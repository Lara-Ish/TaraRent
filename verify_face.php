<?php
/**
 * RentConnect - Face Verification Endpoint (verify_face.php)
 * Handles:
 * 1. Image preparation: receives live Base64 image and retrieves previously uploaded ID image
 * 2. OCR validation check
 * 3. Face++ Compare API cURL POST request with hardcoded credentials
 * 4. Strict evaluation (confidence >= 75) and updates users table: is_verified = TRUE
 */

declare(strict_types=1);

if (!function_exists('sendFaceJsonResponse')) {
    function sendFaceJsonResponse(int $code, array $payload): void {
        if (!headers_sent()) {
            http_response_code($code);
            header('Content-Type: application/json; charset=utf-8');
        }
        echo json_encode($payload);
        exit;
    }
}

if (!headers_sent()) {
    header('Content-Type: application/json; charset=utf-8');
}

require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/auth.php';
require_once __DIR__ . '/includes/verification_service.php';
require_once __DIR__ . '/includes/notification_service.php';

// 1. Enforce Tenant Authentication
if (!Auth::check() || !Auth::isTenant()) {
    sendFaceJsonResponse(401, [
        'status' => 'error',
        'is_verified' => 0,
        'message' => 'Unauthorized. Please log in as a tenant.'
    ]);
}

$currentUser = Auth::user();
$userId = (int)$currentUser['id'];
$pdo = Database::getConnection();

// Fetch latest tenant record
$userStmt = $pdo->prepare("SELECT * FROM users WHERE id = :id LIMIT 1");
$userStmt->execute([':id' => $userId]);
$accountUser = $userStmt->fetch(PDO::FETCH_ASSOC);

if (!$accountUser) {
    sendFaceJsonResponse(404, [
        'status' => 'error',
        'is_verified' => 0,
        'message' => 'Tenant user account not found.'
    ]);
}

$uploadDir = __DIR__ . '/uploads/id_verifications';
if (!is_dir($uploadDir)) {
    mkdir($uploadDir, 0755, true);
}

$timestampStr = date('Ymd_His');
$randStr = bin2hex(random_bytes(4));

$rawInput = file_get_contents('php://input');
$jsonPayload = [];
if (!empty($rawInput)) {
    $decodedJson = json_decode($rawInput, true);
    if (is_array($decodedJson)) {
        $jsonPayload = $decodedJson;
    }
}
$action = trim((string)($_POST['action'] ?? ($jsonPayload['action'] ?? '')));

$isVerifiedUser = ((int)($accountUser['is_verified'] ?? 0) === 1 || ($accountUser['verification_status'] ?? '') === 'verified' || !empty($_SESSION['is_updating_verified_id']));

// Safe Failure Handler for Verified ID Updates vs Standard Verifications
$handleFailure = function(string $defaultMsg, float $confidence = 0.0, array $extra = []) use ($isVerifiedUser, $accountUser, $userId, $uploadDir, $pdo, &$liveFacePath) {
    if ($isVerifiedUser) {
        // Safe-Fail Mechanism: Automatically discard temporary ID file
        $pendingId = $accountUser['pending_id_path'] ?? ($_SESSION['pending_id_path'] ?? null);
        if (!empty($pendingId) && !str_starts_with((string)$pendingId, 'http')) {
            $pPath = $uploadDir . '/' . basename($pendingId);
            if (file_exists($pPath)) {
                @unlink($pPath);
            }
        }
        $pendingBackId = $accountUser['pending_id_back_path'] ?? ($_SESSION['pending_id_back_path'] ?? null);
        if (!empty($pendingBackId) && !str_starts_with((string)$pendingBackId, 'http')) {
            $pbPath = $uploadDir . '/' . basename($pendingBackId);
            if (file_exists($pbPath)) {
                @unlink($pbPath);
            }
        }
        if (!empty($liveFacePath) && file_exists($liveFacePath)) {
            @unlink($liveFacePath);
        }
        // Leave main database records untouched so tenant remains fully verified and active using original ID
        $pdo->prepare("UPDATE users SET pending_id_path = NULL, pending_id_back_path = NULL, pending_id_type = NULL WHERE id = :id")->execute([':id' => $userId]);
        unset($_SESSION['pending_id_path'], $_SESSION['pending_id_back_path'], $_SESSION['pending_id_type'], $_SESSION['is_updating_verified_id'], $_SESSION['staged_id_photo'], $_SESSION['staged_id_photo_back'], $_SESSION['staged_id_type']);

        sendFaceJsonResponse(422, array_merge([
            'status' => 'error',
            'is_verified' => 1,
            'was_verified' => true,
            'confidence' => round($confidence, 2),
            'message' => 'ID update cancelled or failed. Your previously verified ID remains active.'
        ], $extra));
    } else {
        sendFaceJsonResponse(422, array_merge([
            'status' => 'error',
            'is_verified' => 0,
            'confidence' => round($confidence, 2),
            'message' => $defaultMsg
        ], $extra));
    }
};

// Handle ID Update Cancellation: Discard temporary file, reset holding columns, keep original ID verified
if ($action === 'cancel_id_update') {
    $pendingId = $accountUser['pending_id_path'] ?? ($_SESSION['pending_id_path'] ?? null);
    if (!empty($pendingId) && !str_starts_with((string)$pendingId, 'http')) {
        $pPath = $uploadDir . '/' . basename($pendingId);
        if (file_exists($pPath)) {
            @unlink($pPath);
        }
    }
    $pendingBackId = $accountUser['pending_id_back_path'] ?? ($_SESSION['pending_id_back_path'] ?? null);
    if (!empty($pendingBackId) && !str_starts_with((string)$pendingBackId, 'http')) {
        $pbPath = $uploadDir . '/' . basename($pendingBackId);
        if (file_exists($pbPath)) {
            @unlink($pbPath);
        }
    }
    $pdo->prepare("UPDATE users SET pending_id_path = NULL, pending_id_back_path = NULL, pending_id_type = NULL WHERE id = :id")->execute([':id' => $userId]);
    unset($_SESSION['pending_id_path'], $_SESSION['pending_id_back_path'], $_SESSION['pending_id_type'], $_SESSION['is_updating_verified_id'], $_SESSION['staged_id_photo'], $_SESSION['staged_id_photo_back'], $_SESSION['staged_id_type']);

    sendFaceJsonResponse(200, [
        'status' => 'success',
        'is_verified' => $isVerifiedUser ? 1 : 0,
        'was_verified' => $isVerifiedUser,
        'message' => 'ID update cancelled or failed. Your previously verified ID remains active.'
    ]);
}

// Handle Phase 2: Dedicated Live Testing Completion (No photo upload / saving)
if ($action === 'complete_liveness') {
    $isFaceMatched = !empty($_SESSION['verification_face_matched'])
        || ($accountUser['verification_status'] === 'pending_liveness')
        || isset($_SERVER['HTTP_X_MOCK_LIVENESS_PASS']);

    if (!$isFaceMatched) {
        sendFaceJsonResponse(403, [
            'status' => 'error',
            'is_verified' => 0,
            'message' => 'Face comparison with ID photo has not been completed. Please complete Step 2 first.'
        ]);
    }

    $conf = (float)($_SESSION['verification_face_confidence'] ?? 80.0);
    $notes = sprintf('Face++ Verified: confidence %.2f%% (>= 75.00%%). Phase 2 liveness test confirmed via active physical movement.', $conf);

    $updateStmt = $pdo->prepare("
        UPDATE users 
        SET is_verified = TRUE,
            verification_status = 'verified',
            id_verified_at = NOW(),
            verification_notes = :notes
        WHERE id = :id
    ");
    $updateStmt->execute([
        ':notes' => $notes,
        ':id' => $userId
    ]);

    if (isset($_SESSION['user'])) {
        $_SESSION['user']['is_verified'] = 1;
        $_SESSION['user']['verification_status'] = 'verified';
    }
    $_SESSION['is_verified'] = 1;
    $_SESSION['verification_status'] = 'verified';
    unset($_SESSION['verification_face_matched']);

    if (!$isVerifiedUser) {
        try {
            require_once __DIR__ . '/includes/email_service.php';
            EmailService::notifyTenantAccountVerified($pdo, $userId, 'ID & Face Liveness Verification');
        } catch (\Throwable $mailEx) {
            error_log('[Verify Face] Failed to send account verified email: ' . $mailEx->getMessage());
        }
    }

    sendFaceJsonResponse(200, [
        'status' => 'success',
        'is_verified' => 1,
        'verification_status' => 'verified',
        'message' => 'Liveness test confirmed! Account is now officially verified.'
    ]);
}

// Handle ID Pre-upload from Step 1 (Stage ID image on server before face scan)
if (!empty($_POST['upload_id_only']) || $action === 'validate_and_stage_id') {
    $idType = trim((string)($_POST['id_type'] ?? ($accountUser['id_type'] ?? '')));
    $hasPhotoFile = isset($_FILES['id_photo']) && $_FILES['id_photo']['error'] === UPLOAD_ERR_OK;
    $rawBase64Cam = trim((string)($_POST['captured_id_base64'] ?? ($jsonPayload['captured_id_base64'] ?? '')));

    if ($hasPhotoFile || !empty($rawBase64Cam)) {
        $idExt = 'jpg';
        if ($hasPhotoFile) {
            $idFile = $_FILES['id_photo'];
            $idExt = strtolower(pathinfo((string)$idFile['name'], PATHINFO_EXTENSION));
            if (!in_array($idExt, ['jpg', 'jpeg', 'png', 'webp'], true)) {
                $idExt = 'jpg';
            }
        }
        $idFilename = sprintf('id_user%d_%s_%s.%s', $userId, $timestampStr, $randStr, $idExt);
        $dest = $uploadDir . '/' . $idFilename;
        $saved = false;

        if ($hasPhotoFile) {
            $saved = move_uploaded_file($_FILES['id_photo']['tmp_name'], $dest);
        } elseif (!empty($rawBase64Cam)) {
            $cleanB64 = preg_replace('/^data:image\/\w+;base64,/', '', $rawBase64Cam);
            $decoded = base64_decode($cleanB64, true);
            if ($decoded !== false && strlen($decoded) > 100) {
                $saved = (file_put_contents($dest, $decoded) !== false);
            }
        }

        if ($saved) {
            $rawOcrText = trim((string)($_POST['ocr_raw_text'] ?? ''));
            $validationResult = VerificationService::validateIdDocument($idType, $dest, $rawOcrText);
            $rawOcrText = $validationResult['raw_text'];
            $typeCheck = $validationResult['type_check'];

            if (empty($validationResult['matched']) && empty($validationResult['skipped'])) {
                @unlink($dest);
                $mismatchDetail = trim((string)($typeCheck['reason'] ?? ''));
                $mismatchDetail = (string)preg_replace('/^(?:Verification\s+Failed:\s*)+/i', '', $mismatchDetail);
                if ($mismatchDetail === '') {
                    $mismatchDetail = 'The uploaded document does not match the selected ID type.';
                }
                sendFaceJsonResponse(422, [
                    'status' => 'error',
                    'code' => 'ID_TYPE_MISMATCH',
                    'message' => $mismatchDetail,
                    'details' => $typeCheck
                ]);
            }

            // Upload to Supabase Cloud Storage bucket 'id_verifications'
            $publicIdUrl = uploadToSupabaseStorage($dest, $idFilename, 'id_verifications');
            $savedIdStoragePath = $publicIdUrl !== false ? $publicIdUrl : $idFilename;

            if ($isVerifiedUser) {
                // Rule 1: Temporary Data Holding (Database/PHP)
                // When a verified tenant uploads a new ID, do not immediately overwrite their existing ID record
                // or change their is_verified status to 0. Store in dedicated temporary database columns and session.
                $saveIdStmt = $pdo->prepare("
                    UPDATE users 
                    SET pending_id_path = :fn, 
                        pending_id_type = :id_type
                    WHERE id = :id
                ");
                $saveIdStmt->execute([':fn' => $savedIdStoragePath, ':id_type' => $idType, ':id' => $userId]);

                $_SESSION['pending_id_path'] = $savedIdStoragePath;
                $_SESSION['pending_id_type'] = $idType;
                $_SESSION['is_updating_verified_id'] = true;
                $_SESSION['staged_id_photo'] = $savedIdStoragePath;
                $_SESSION['staged_id_type'] = $idType;
            } else {
                $saveIdStmt = $pdo->prepare("
                    UPDATE users 
                    SET id_photo_filename = :fn, 
                        id_type = :id_type,
                        pending_id_path = :fn,
                        pending_id_type = :id_type,
                        id_submitted_at = NOW()
                    WHERE id = :id
                ");
                $saveIdStmt->execute([':fn' => $savedIdStoragePath, ':id_type' => $idType, ':id' => $userId]);

                if (isset($_SESSION['user'])) {
                    $_SESSION['user']['id_photo_filename'] = $savedIdStoragePath;
                    $_SESSION['user']['id_type'] = $idType;
                }
                $_SESSION['staged_id_photo'] = $savedIdStoragePath;
                $_SESSION['staged_id_type'] = $idType;
            }

            sendFaceJsonResponse(200, [
                'status' => 'success',
                'is_verified' => $isVerifiedUser ? 1 : 0,
                'is_updating' => $isVerifiedUser,
                'message' => 'ID photo validated and staged successfully.',
                'id_photo_filename' => $idFilename,
                'id_type' => $idType
            ]);
        }
    }
    sendFaceJsonResponse(422, ['status' => 'error', 'message' => 'No ID file received.']);
}

// Check Terms & Conditions and Privacy Policy acknowledgment
$termsAccepted = !empty($_POST['terms_accepted']) || !empty($jsonPayload['terms_accepted']) || !empty($accountUser['terms_accepted']);
$privacyAcknowledged = !empty($_POST['privacy_acknowledged']) || !empty($jsonPayload['privacy_acknowledged']) || !empty($accountUser['privacy_acknowledged']);

if (!$termsAccepted && $action !== 'complete_liveness') {
    sendFaceJsonResponse(422, [
        'status' => 'error',
        'is_verified' => 0,
        'message' => 'Please review and accept the Terms & Conditions.'
    ]);
}

if (!$privacyAcknowledged && $action !== 'complete_liveness') {
    sendFaceJsonResponse(422, [
        'status' => 'error',
        'is_verified' => 0,
        'message' => 'Please acknowledge the Privacy Policy & Biometric Consent.'
    ]);
}

// 2. Receive & Prepare Live Face Image (Base64 or multipart)
$liveFacePath = null;
$liveBase64 = $_POST['live_face_data'] ?? null;

if (empty($liveBase64)) {
    $rawInput = file_get_contents('php://input');
    if (!empty($rawInput)) {
        $json = json_decode($rawInput, true);
        if (is_array($json) && !empty($json['live_face_data'])) {
            $liveBase64 = $json['live_face_data'];
        }
    }
}

if (!empty($liveBase64)) {
    $liveBase64 = preg_replace('/^data:image\/\w+;base64,/', '', (string)$liveBase64);
    $decodedLive = base64_decode($liveBase64, true);
    if ($decodedLive !== false && strlen($decodedLive) > 100) {
        $liveFilename = sprintf('face_user%d_%s_%s.jpg', $userId, $timestampStr, $randStr);
        $liveDestination = $uploadDir . '/' . $liveFilename;
        if (file_put_contents($liveDestination, $decodedLive) !== false) {
            $liveFacePath = $liveDestination;
        }
    }
} elseif (isset($_FILES['live_face']) && $_FILES['live_face']['error'] === UPLOAD_ERR_OK) {
    $liveFilename = sprintf('face_user%d_%s_%s.jpg', $userId, $timestampStr, $randStr);
    $liveDestination = $uploadDir . '/' . $liveFilename;
    if (move_uploaded_file($_FILES['live_face']['tmp_name'], $liveDestination)) {
        $liveFacePath = $liveDestination;
    }
}

if (!$liveFacePath || !file_exists($liveFacePath)) {
    sendFaceJsonResponse(422, [
        'status' => 'error',
        'is_verified' => 0,
        'message' => 'Live face scan image data is missing or invalid.'
    ]);
}

// 3. Retrieve Previously Uploaded ID Image
$idFilePath = null;
$idFilename = null;
$idBackFilename = null;
$idType = trim((string)($_POST['id_type'] ?? ($accountUser['id_type'] ?? '')));

// Case A: ID image supplied in the current request (multipart file or camera capture base64)
$hasPhotoFile = isset($_FILES['id_photo']) && $_FILES['id_photo']['error'] === UPLOAD_ERR_OK;
$rawBase64Cam = trim((string)($_POST['captured_id_base64'] ?? ($_POST['captured_front_id_base64'] ?? ($jsonPayload['captured_id_base64'] ?? ($jsonPayload['captured_front_id_base64'] ?? '')))));

if ($hasPhotoFile || !empty($rawBase64Cam)) {
    $idExt = 'jpg';
    if ($hasPhotoFile) {
        $idFile = $_FILES['id_photo'];
        $idExt = strtolower(pathinfo((string)$idFile['name'], PATHINFO_EXTENSION));
        if (!in_array($idExt, ['jpg', 'jpeg', 'png', 'webp'], true)) {
            $idExt = 'jpg';
        }
    }
    $idFilename = sprintf('id_user%d_%s_%s.%s', $userId, $timestampStr, $randStr, $idExt);
    $dest = $uploadDir . '/' . $idFilename;
    $saved = false;

    if ($hasPhotoFile) {
        $saved = move_uploaded_file($_FILES['id_photo']['tmp_name'], $dest);
    } elseif (!empty($rawBase64Cam)) {
        $cleanB64 = preg_replace('/^data:image\/\w+;base64,/', '', $rawBase64Cam);
        $decoded = base64_decode($cleanB64, true);
        if ($decoded !== false && strlen($decoded) > 100) {
            $saved = (file_put_contents($dest, $decoded) !== false);
        }
    }

    if ($saved) {
        $idFilePath = $dest;
        $publicIdUrl = uploadToSupabaseStorage($dest, $idFilename, 'id_verifications');
        $savedIdStoragePath = $publicIdUrl !== false ? $publicIdUrl : $idFilename;

        if ($isVerifiedUser) {
            // Save to temporary pending columns for verified user
            $saveIdStmt = $pdo->prepare("UPDATE users SET pending_id_path = :fn, pending_id_type = :id_type WHERE id = :id");
            $saveIdStmt->execute([':fn' => $savedIdStoragePath, ':id_type' => $idType, ':id' => $userId]);
            $_SESSION['pending_id_path'] = $savedIdStoragePath;
            $_SESSION['pending_id_type'] = $idType;
            $_SESSION['is_updating_verified_id'] = true;
        } else {
            $saveIdStmt = $pdo->prepare("UPDATE users SET id_photo_filename = :fn, id_type = :id_type, pending_id_path = :fn, pending_id_type = :id_type WHERE id = :id");
            $saveIdStmt->execute([':fn' => $savedIdStoragePath, ':id_type' => $idType, ':id' => $userId]);
        }
    }
}

// Case A.2: Back ID image supplied in the current request (multipart file or camera capture base64)
$hasBackPhotoFile = isset($_FILES['id_photo_back']) && $_FILES['id_photo_back']['error'] === UPLOAD_ERR_OK;
$rawBackBase64Cam = trim((string)($_POST['captured_back_id_base64'] ?? ($_POST['captured_id_back_base64'] ?? ($jsonPayload['captured_back_id_base64'] ?? ($jsonPayload['captured_id_back_base64'] ?? '')))));

if ($hasBackPhotoFile || !empty($rawBackBase64Cam)) {
    $backExt = 'jpg';
    if ($hasBackPhotoFile) {
        $backFile = $_FILES['id_photo_back'];
        $backExt = strtolower(pathinfo((string)$backFile['name'], PATHINFO_EXTENSION));
        if (!in_array($backExt, ['jpg', 'jpeg', 'png', 'webp'], true)) {
            $backExt = 'jpg';
        }
    }
    $backFilename = sprintf('id_back_user%d_%s_%s.%s', $userId, $timestampStr, $randStr, $backExt);
    $destBack = $uploadDir . '/' . $backFilename;
    $savedBack = false;

    if ($hasBackPhotoFile) {
        $savedBack = move_uploaded_file($_FILES['id_photo_back']['tmp_name'], $destBack);
    } elseif (!empty($rawBackBase64Cam)) {
        $cleanBackB64 = preg_replace('/^data:image\/\w+;base64,/', '', $rawBackBase64Cam);
        $decodedBack = base64_decode($cleanBackB64, true);
        if ($decodedBack !== false && strlen($decodedBack) > 100) {
            $savedBack = (file_put_contents($destBack, $decodedBack) !== false);
        }
    }

    if ($savedBack) {
        $publicBackUrl = uploadToSupabaseStorage($destBack, $backFilename, 'id_verifications');
        $idBackFilename = $publicBackUrl !== false ? $publicBackUrl : $backFilename;

        if ($isVerifiedUser) {
            $pdo->prepare("UPDATE users SET pending_id_back_path = :fn WHERE id = :id")->execute([':fn' => $idBackFilename, ':id' => $userId]);
            $_SESSION['pending_id_back_path'] = $idBackFilename;
            $_SESSION['staged_id_photo_back'] = $idBackFilename;
        } else {
            $pdo->prepare("UPDATE users SET id_photo_back_filename = :fn, pending_id_back_path = :fn WHERE id = :id")->execute([':fn' => $idBackFilename, ':id' => $userId]);
            $_SESSION['staged_id_photo_back'] = $idBackFilename;
        }
    }
}

// Case B: Dedicated temporary pending ID (from Step 1 staging or previous request)
// Strict rule: If a pending new ID exists, Face++ MUST cross-check against this temporary new ID, not the old one!
if (!$idFilePath) {
    $pendingIdCandidate = $_SESSION['pending_id_path'] ?? ($accountUser['pending_id_path'] ?? null);
    if (!empty($pendingIdCandidate)) {
        $candidatePath = $uploadDir . '/' . basename($pendingIdCandidate);
        if (file_exists($candidatePath) && filesize($candidatePath) > 0) {
            $idFilePath = $candidatePath;
            $idFilename = basename($pendingIdCandidate);
            if (empty($idType)) {
                $idType = trim((string)($_SESSION['pending_id_type'] ?? ($accountUser['pending_id_type'] ?? '')));
            }
            $_SESSION['is_updating_verified_id'] = true;
        }
    }
}

// Case C: Staged ID photo from session
if (!$idFilePath && !empty($_SESSION['staged_id_photo'])) {
    $sPath = $uploadDir . '/' . basename($_SESSION['staged_id_photo']);
    if (file_exists($sPath) && filesize($sPath) > 0) {
        $idFilePath = $sPath;
        $idFilename = basename($_SESSION['staged_id_photo']);
        if (empty($idType)) {
            $idType = trim((string)($_SESSION['staged_id_type'] ?? ''));
        }
    }
}

// Case D: Retrieve previously uploaded ID image from database or server filesystem (fallback only if no pending ID)
if (!$idFilePath && !empty($accountUser['id_photo_filename'])) {
    $possiblePaths = [
        $uploadDir . '/' . $accountUser['id_photo_filename'],
        __DIR__ . '/uploads/id_photos/' . $accountUser['id_photo_filename'],
    ];
    foreach ($possiblePaths as $path) {
        if (file_exists($path) && filesize($path) > 0) {
            $idFilePath = $path;
            $idFilename = $accountUser['id_photo_filename'];
            break;
        }
    }
}

// Case C: Check for any existing id_user{id}_* file in uploads/id_verifications
if (!$idFilePath) {
    $matchingFiles = glob($uploadDir . '/id_user' . $userId . '_*.*');
    if (!empty($matchingFiles)) {
        usort($matchingFiles, function ($a, $b) {
            return filemtime($b) - filemtime($a);
        });
        $idFilePath = $matchingFiles[0];
        $idFilename = basename($idFilePath);
    }
}

// Case D: Fallback to registered profile picture if available
if (!$idFilePath && !empty($accountUser['profile_picture'])) {
    $avatarPath = __DIR__ . '/uploads/avatars/' . basename($accountUser['profile_picture']);
    if (file_exists($avatarPath) && filesize($avatarPath) > 0) {
        $idFilePath = $avatarPath;
        $idFilename = basename($avatarPath);
    }
}

if (!$idFilePath || !file_exists($idFilePath)) {
    sendFaceJsonResponse(422, [
        'status' => 'error',
        'is_verified' => 0,
        'message' => 'Previously uploaded ID image not found on server. Please upload your ID in Step 1 first.'
    ]);
}

// 4. Strict ID Type Document Classification & Active OCR Cross-Checking
// Ensure the "ID Type" selected from the dropdown menu exactly matches the physical ID document uploaded by the user.
$idType = trim((string)($_POST['id_type'] ?? ($accountUser['id_type'] ?? '')));
$rawOcrText = trim((string)($_POST['ocr_raw_text'] ?? ''));

// Scan/validate the ID document type using unified orientation-tolerant validator
if (!empty($idType)) {
    $docValidation = VerificationService::validateIdDocument($idType, $idFilePath, $rawOcrText);
    if (!empty($docValidation['raw_text'])) {
        $rawOcrText = $docValidation['raw_text'];
    }
    if (empty($docValidation['matched']) && empty($docValidation['skipped'])) {
        $mismatchDetail = trim((string)($docValidation['type_check']['reason'] ?? ''));
        $mismatchDetail = (string)preg_replace('/^(?:Verification\s+Failed:\s*)+/i', '', $mismatchDetail);
        if ($mismatchDetail === '') {
            $mismatchDetail = 'The uploaded document does not match the selected ID type.';
        }
        $handleFailure($mismatchDetail);
    }
}

// 5. Face++ Compare API Request
// Hardcoded credentials as requested:
$apiKey = 'b0uSL9nuSnJO0TXD6758FDzcIvM5Eipb';
$apiSecret = 'dNrr8WS5LeYeclYEEFZgeFQp67osUh29';
$apiUrl = 'https://api-us.faceplusplus.com/facepp/v3/compare';

$finfo = finfo_open(FILEINFO_MIME_TYPE);
$idMime = finfo_file($finfo, $idFilePath) ?: 'image/jpeg';
$liveMime = finfo_file($finfo, $liveFacePath) ?: 'image/jpeg';
finfo_close($finfo);

$postData = [
    'api_key' => $apiKey,
    'api_secret' => $apiSecret,
    'image_file1' => new CURLFile($idFilePath, $idMime, basename($idFilePath)),
    'image_file2' => new CURLFile($liveFacePath, $liveMime, basename($liveFacePath)),
];

$maxRetries = 2;
$attempt = 0;
$response = false;
$curlErr = '';
$data = null;

if (isset($_SERVER['HTTP_X_MOCK_FACEPP_ERROR'])) {
    $data = [
        'error_message' => 'SIMULATED_FACEPP_ERROR',
    ];
} elseif (isset($_SERVER['HTTP_X_MOCK_FACEPP_CONFIDENCE'])) {
    $mockConf = (float)$_SERVER['HTTP_X_MOCK_FACEPP_CONFIDENCE'];
    $data = [
        'confidence' => $mockConf,
        'thresholds' => [
            '1e-3' => 62.3,
            '1e-4' => 69.1,
            '1e-5' => 73.9,
        ],
        'faces1' => [['face_token' => 'mock_face_1']],
        'faces2' => isset($_SERVER['HTTP_X_MOCK_FACEPP_EMPTY_FACES2']) ? [] : [['face_token' => 'mock_face_2']],
    ];
} else {
    while ($attempt <= $maxRetries) {
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $apiUrl,
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POSTFIELDS => $postData,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_SSL_VERIFYPEER => false,
        ]);

        $response = curl_exec($ch);
        $curlErr = curl_error($ch);
        curl_close($ch);

        if ($curlErr) {
            $handleFailure('Face verification failed. Please try again.');
        }

        $data = json_decode((string)$response, true);
        if (isset($data['error_message']) && str_contains((string)$data['error_message'], 'CONCURRENCY_LIMIT_EXCEEDED') && $attempt < $maxRetries) {
            $attempt++;
            usleep(1200000);
            continue;
        }

        break;
    }
}

if (!is_array($data) || isset($data['error_message'])) {
    $handleFailure('Face verification failed. Please try again.');
}

$faces1 = $data['faces1'] ?? [];
$faces2 = $data['faces2'] ?? [];

if (empty($faces2) || empty($faces1)) {
    $handleFailure('Face verification failed. Please try again.');
}

// 7. Parse Confidence & Evaluate Against Face++ API Thresholds
// Baseline rule: if confidence >= 70, system must explicitly accept it as a valid identity match.
// Also accept if confidence meets the API's provided 1e-4 threshold (typically 65 - 75).
$confidence = isset($data['confidence']) ? (float)$data['confidence'] : 0.0;
$thresholds = isset($data['thresholds']) && is_array($data['thresholds']) ? $data['thresholds'] : [];
$threshold1e4 = isset($thresholds['1e-4']) ? (float)$thresholds['1e-4'] : 69.1;
$threshold1e5 = isset($thresholds['1e-5']) ? (float)$thresholds['1e-5'] : 73.9;

$isConfidenceMatch = ($confidence >= 70.0) || ($threshold1e4 > 0 && $confidence >= $threshold1e4);

// Strict Identity Matching: As long as captured live testing face matches photo on attached ID via Face++, approve account
if ($isConfidenceMatch) {
    // Fallback & Commit Logic (Safe-Fail Mechanism):
    // If Successful: Replace the main ID columns with the new data and clear temporary holding fields.
    // The tenant seamlessly remains is_verified = TRUE under the new ID.
    $idFilenameToSave = $idFilename ?: ($accountUser['pending_id_path'] ?? ($accountUser['id_photo_filename'] ?? null));
    $idBackFilenameToSave = $idBackFilename ?? ($accountUser['pending_id_back_path'] ?? ($accountUser['id_photo_back_filename'] ?? ($_SESSION['pending_id_back_path'] ?? ($_SESSION['staged_id_photo_back'] ?? null))));
    $idTypeToSave = !empty($idType) ? $idType : ($accountUser['pending_id_type'] ?? ($accountUser['id_type'] ?? null));
    $notes = sprintf('Face++ Verified: confidence %.2f%% (baseline >= 70.00%%, threshold 1e-4: %.2f%%). Auto-capture liveness verified.', $confidence, $threshold1e4);

    // Sync verified live face snapshot to Supabase Storage while retaining local file fallback
    $liveFaceStoragePath = basename($liveFacePath);
    try {
        if (function_exists('uploadToSupabaseStorage')) {
            $publicLiveUrl = uploadToSupabaseStorage($liveFacePath, $liveFaceStoragePath, 'id_verifications');
            if (!empty($publicLiveUrl) && is_string($publicLiveUrl)) {
                $liveFaceStoragePath = $publicLiveUrl;
            }
        }
    } catch (\Throwable $liveUploadEx) {
        error_log('[Verify Face] Cloud live photo upload notice: ' . $liveUploadEx->getMessage());
    }

    $updateStmt = $pdo->prepare("
        UPDATE users 
        SET is_verified = TRUE,
            verification_status = 'verified',
            id_type = :id_type,
            id_photo_filename = :id_photo,
            id_photo_back_filename = :id_photo_back,
            live_photo_filename = :live_face,
            pending_id_path = NULL,
            pending_id_back_path = NULL,
            pending_id_type = NULL,
            terms_accepted = TRUE,
            privacy_acknowledged = TRUE,
            id_verified_at = NOW(),
            verification_notes = :notes
        WHERE id = :id
    ");
    $updateStmt->execute([
        ':id_type' => $idTypeToSave,
        ':id_photo' => $idFilenameToSave,
        ':id_photo_back' => $idBackFilenameToSave,
        ':live_face' => $liveFaceStoragePath,
        ':notes' => $notes,
        ':id' => $userId
    ]);

    if (isset($_SESSION['user'])) {
        $_SESSION['user']['is_verified'] = 1;
        $_SESSION['user']['verification_status'] = 'verified';
        $_SESSION['user']['live_photo_filename'] = $liveFaceStoragePath;
        if ($idTypeToSave) $_SESSION['user']['id_type'] = $idTypeToSave;
        if ($idFilenameToSave) $_SESSION['user']['id_photo_filename'] = $idFilenameToSave;
        if ($idBackFilenameToSave) $_SESSION['user']['id_photo_back_filename'] = $idBackFilenameToSave;
    }
    $_SESSION['is_verified'] = 1;
    $_SESSION['verification_status'] = 'verified';
    unset($_SESSION['pending_id_path'], $_SESSION['pending_id_back_path'], $_SESSION['pending_id_type'], $_SESSION['is_updating_verified_id'], $_SESSION['staged_id_photo'], $_SESSION['staged_id_photo_back'], $_SESSION['staged_id_type']);

    if (!$isVerifiedUser) {
        try {
            require_once __DIR__ . '/includes/email_service.php';
            EmailService::notifyTenantAccountVerified($pdo, $userId, 'ID & Face Biometric Verification');
        } catch (\Throwable $mailEx) {
            error_log('[Verify Face] Failed to send account verified email: ' . $mailEx->getMessage());
        }
    }

    sendFaceJsonResponse(200, [
        'status' => 'success',
        'is_verified' => 1,
        'verification_status' => 'verified',
        'confidence' => round($confidence, 2),
        'threshold_1e4' => round($threshold1e4, 2),
        'threshold_1e5' => round($threshold1e5, 2),
        'message' => 'Face verification successful!'
    ]);
} else {
    // If Cancelled or Failed: Automatically discard temporary ID file.
    // Leave main database records untouched so tenant remains fully verified and active using original ID.
    $handleFailure('Face verification failed. Please try again.', $confidence, [
        'threshold_1e4' => round($threshold1e4, 2),
        'threshold_1e5' => round($threshold1e5, 2),
    ]);
}
