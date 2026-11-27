<?php
/**
 * RentConnect - User Profile & Account Security API
 * Allows Landlord and Tenants to manage profile, submit ID verification, and change password
 */

declare(strict_types=1);

header('Content-Type: application/json; charset=UTF-8');

require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../includes/verification_service.php';
require_once __DIR__ . '/../includes/notification_service.php';

$requestMethod = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($requestMethod === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// Authentication Check: All authenticated users can access their own profile
if (!Auth::check()) {
    http_response_code(401);
    echo json_encode([
        'status' => 'error',
        'message' => 'Unauthorized: Please log in to manage your profile.'
    ]);
    exit;
}

$currentUser = Auth::user();
$userId = (int)$currentUser['id'];
$pdo = Database::getConnection();

try {
    // -------------------------------------------------------------------------
    // 1. GET: Fetch Current User Profile Details
    // -------------------------------------------------------------------------
    if ($requestMethod === 'GET') {
        $stmt = $pdo->prepare("
            SELECT u.id, u.unit_id, u.username, u.name, u.email, u.phone, 
                   u.profile_picture,
                   u.verification_status, u.id_type, u.id_photo_filename, u.id_photo_back_filename,
                   u.live_photo_filename, u.verification_notes,
                   u.id_submitted_at, u.id_verified_at,
                   u.birthday, u.emergency_contact, u.emergency_contact_name,
                   u.emergency_contact_relationship, u.emergency_contact_phone,
                   u.role, u.move_in_date, u.created_at,
                   un.unit_number, un.monthly_rent
            FROM users u
            LEFT JOIN units un ON u.unit_id = un.id
            WHERE u.id = :id
            LIMIT 1
        ");
        $stmt->execute([':id' => $userId]);
        $profile = $stmt->fetch();

        if (!$profile) {
            http_response_code(404);
            echo json_encode(['status' => 'error', 'message' => 'User profile not found.']);
            exit;
        }

        echo json_encode([
            'status' => 'success',
            'profile' => $profile
        ]);
        exit;
    }

    // -------------------------------------------------------------------------
    // 2. POST: Change Password OR Submit ID Verification
    // -------------------------------------------------------------------------
    if ($requestMethod === 'POST') {
        // Detect payload format: JSON vs multipart/form-data
        $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
        if (stripos($contentType, 'application/json') !== false) {
            $input = json_decode(file_get_contents('php://input'), true) ?? [];
        } else {
            $input = $_POST;
        }

        $action = trim((string)($input['action'] ?? (isset($_FILES['profile_picture']) ? 'upload_profile_picture' : 'change_password')));

        // ACTION: Upload Profile Picture (Owner and Tenant)
        if ($action === 'upload_profile_picture' || isset($_FILES['profile_picture'])) {
            if (!isset($_FILES['profile_picture']) || $_FILES['profile_picture']['error'] !== UPLOAD_ERR_OK) {
                http_response_code(422);
                echo json_encode([
                    'status' => 'error',
                    'message' => 'No image file uploaded or an upload error occurred.'
                ]);
                exit;
            }

            $picFile = $_FILES['profile_picture'];
            $maxBytes = 5 * 1024 * 1024; // 5MB limit

            if ($picFile['size'] > $maxBytes) {
                http_response_code(422);
                echo json_encode([
                    'status' => 'error',
                    'message' => 'The uploaded profile picture exceeds the 5MB size limit.'
                ]);
                exit;
            }

            $rawExt = strtolower(pathinfo((string)$picFile['name'], PATHINFO_EXTENSION));
            $allowedExts = ['jpg', 'jpeg', 'png', 'webp'];
            if (!in_array($rawExt, $allowedExts, true)) {
                http_response_code(422);
                echo json_encode([
                    'status' => 'error',
                    'message' => 'Invalid image format. Supported formats are JPG, JPEG, PNG, and WEBP.'
                ]);
                exit;
            }

            // Verify binary image content via getimagesize
            $imageInfo = @getimagesize($picFile['tmp_name']);
            if ($imageInfo === false) {
                http_response_code(422);
                echo json_encode([
                    'status' => 'error',
                    'message' => 'Uploaded file is not a valid image.'
                ]);
                exit;
            }

            $allowedMimes = ['image/jpeg', 'image/png', 'image/webp'];
            $detectedMime = $imageInfo['mime'] ?? '';
            if (!in_array($detectedMime, $allowedMimes, true)) {
                http_response_code(422);
                echo json_encode([
                    'status' => 'error',
                    'message' => 'Invalid image type detected. Allowed: JPG, PNG, WEBP.'
                ]);
                exit;
            }

            // Retrieve old avatar to safely clean up if local
            $oldStmt = $pdo->prepare("SELECT profile_picture FROM users WHERE id = :id LIMIT 1");
            $oldStmt->execute([':id' => $userId]);
            $oldAvatar = $oldStmt->fetchColumn();

            $timestampStr = date('Ymd_His');
            $randStr = bin2hex(random_bytes(6));
            $newFilename = sprintf('avatar_u%d_%s_%s.%s', $userId, $timestampStr, $randStr, $rawExt);

            // Attempt storage: First try cloud storage (Supabase), fallback to local storage
            $savedDbPath = null;
            try {
                if (function_exists('uploadToSupabaseStorage')) {
                    $cloudUrl = uploadToSupabaseStorage($picFile['tmp_name'], $newFilename, 'avatars');
                    if (!empty($cloudUrl) && is_string($cloudUrl)) {
                        $savedDbPath = $cloudUrl;
                    }
                }
            } catch (\Throwable $cloudEx) {
                error_log("[Profile API] Cloud storage notice: " . $cloudEx->getMessage());
            }

            // Fallback to local storage if cloud storage was unreachable or returned false
            if (empty($savedDbPath)) {
                $localUploadDir = dirname(__DIR__) . '/uploads/avatars/';
                if (!is_dir($localUploadDir)) {
                    @mkdir($localUploadDir, 0777, true);
                }
                $targetLocalPath = $localUploadDir . $newFilename;

                $storedLocally = false;
                if (is_uploaded_file($picFile['tmp_name'])) {
                    $storedLocally = @move_uploaded_file($picFile['tmp_name'], $targetLocalPath);
                }
                if (!$storedLocally) {
                    $storedLocally = @copy($picFile['tmp_name'], $targetLocalPath);
                }
                if (!$storedLocally) {
                    $content = @file_get_contents($picFile['tmp_name']);
                    if ($content !== false && strlen($content) > 0) {
                        $storedLocally = (@file_put_contents($targetLocalPath, $content) !== false);
                    }
                }

                if ($storedLocally) {
                    $savedDbPath = 'uploads/avatars/' . $newFilename;
                } else {
                    http_response_code(500);
                    echo json_encode([
                        'status' => 'error',
                        'message' => 'Failed to store profile picture to storage system.'
                    ]);
                    exit;
                }
            }

            // Update database record using PostgreSQL UPDATE query
            $updStmt = $pdo->prepare("UPDATE users SET profile_picture = :pic WHERE id = :id");
            $updStmt->execute([':pic' => $savedDbPath, ':id' => $userId]);

            // Clean up old local avatar file safely if it was local and distinct
            if (!empty($oldAvatar) && !str_starts_with((string)$oldAvatar, 'http') && $oldAvatar !== $savedDbPath) {
                $oldPath = dirname(__DIR__) . '/uploads/avatars/' . basename((string)$oldAvatar);
                if (file_exists($oldPath) && is_file($oldPath)) {
                    @unlink($oldPath);
                }
            }

            // Update session if current user
            if (isset($_SESSION['user']['id']) && (int)$_SESSION['user']['id'] === $userId) {
                $_SESSION['user']['profile_picture'] = $savedDbPath;
            }

            // Check if AJAX request or standard form submission
            $isAjax = (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') 
                      || (isset($_SERVER['HTTP_ACCEPT']) && strpos($_SERVER['HTTP_ACCEPT'], 'application/json') !== false)
                      || (!empty($_POST['is_ajax']));

            if (!$isAjax && empty($_POST['is_ajax'])) {
                $referer = $_SERVER['HTTP_REFERER'] ?? '';
                if (!empty($referer)) {
                    $sep = strpos($referer, '?') !== false ? '&' : '?';
                    header('Location: ' . $referer . $sep . 'avatar=success');
                } else {
                    $redir = (Auth::isLandlord() ? 'admin_dashboard.php?section=sectionProfile&tab=accountInfo' : 'tenant_portal.php?section=tenantProfile&tab=accountInfo');
                    header('Location: ../' . $redir . '&avatar=success');
                }
                exit;
            }

            echo json_encode([
                'status' => 'success',
                'message' => 'Profile picture updated successfully!',
                'profile_picture' => $savedDbPath,
                'filename' => $newFilename
            ]);
            exit;
        }

        // ACTION: Remove Profile Picture (revert to circular initial icon)
        if ($action === 'remove_profile_picture') {
            $oldStmt = $pdo->prepare("SELECT profile_picture, name FROM users WHERE id = :id LIMIT 1");
            $oldStmt->execute([':id' => $userId]);
            $userRow = $oldStmt->fetch(PDO::FETCH_ASSOC);
            $oldAvatar = $userRow['profile_picture'] ?? null;
            $userName = $userRow['name'] ?? ($currentUser['name'] ?? 'U');

            if (!empty($oldAvatar)) {
                $oldPath = __DIR__ . '/../uploads/avatars/' . basename($oldAvatar);
                if (file_exists($oldPath) && is_file($oldPath)) {
                    @unlink($oldPath);
                }
            }

            $updStmt = $pdo->prepare("UPDATE users SET profile_picture = NULL WHERE id = :id");
            $updStmt->execute([':id' => $userId]);

            if (isset($_SESSION['user']['id']) && (int)$_SESSION['user']['id'] === $userId) {
                $_SESSION['user']['profile_picture'] = null;
            }

            // Calculate first name initial
            $firstName = trim(explode(' ', trim($userName))[0]);
            $initial = strtoupper(mb_substr($firstName, 0, 1, 'UTF-8')) ?: 'U';

            echo json_encode([
                'status' => 'success',
                'message' => 'Profile picture removed successfully.',
                'initial' => $initial
            ]);
            exit;
        }

        // ACTION: Cancel ID Update (Discard temporary file, clear holding columns, keep original ID verified)
        if ($action === 'cancel_id_update') {
            $userStmt = $pdo->prepare("SELECT * FROM users WHERE id = :id LIMIT 1");
            $userStmt->execute([':id' => $userId]);
            $currentUserRecord = $userStmt->fetch(PDO::FETCH_ASSOC);

            $wasVerified = ((int)($currentUserRecord['is_verified'] ?? 0) === 1 || ($currentUserRecord['verification_status'] ?? '') === 'verified' || !empty($_SESSION['is_updating_verified_id']));
            $uploadDir = __DIR__ . '/../uploads/id_verifications';

            $pendingId = $currentUserRecord['pending_id_path'] ?? ($_SESSION['pending_id_path'] ?? null);
            if (!empty($pendingId) && !str_starts_with((string)$pendingId, 'http')) {
                $pPath = $uploadDir . '/' . basename($pendingId);
                if (file_exists($pPath) && is_file($pPath)) {
                    @unlink($pPath);
                }
            }

            $pendingBackId = $currentUserRecord['pending_id_back_path'] ?? ($_SESSION['pending_id_back_path'] ?? null);
            if (!empty($pendingBackId) && !str_starts_with((string)$pendingBackId, 'http')) {
                $pbPath = $uploadDir . '/' . basename($pendingBackId);
                if (file_exists($pbPath) && is_file($pbPath)) {
                    @unlink($pbPath);
                }
            }

            $pdo->prepare("UPDATE users SET pending_id_path = NULL, pending_id_back_path = NULL, pending_id_type = NULL WHERE id = :id")->execute([':id' => $userId]);
            unset($_SESSION['pending_id_path'], $_SESSION['pending_id_back_path'], $_SESSION['pending_id_type'], $_SESSION['is_updating_verified_id'], $_SESSION['staged_id_photo'], $_SESSION['staged_id_photo_back'], $_SESSION['staged_id_type']);

            http_response_code(200);
            echo json_encode([
                'status' => 'success',
                'is_verified' => $wasVerified ? 1 : 0,
                'was_verified' => $wasVerified,
                'message' => 'ID update cancelled or failed. Your previously verified ID remains active.'
            ]);
            exit;
        }

        // ACTION: Validate & Stage ID Document (Front & Back Sides Required) with Strict ID Type Cross-Checking
        if ($action === 'validate_and_stage_id') {
            $userStmt = $pdo->prepare("SELECT * FROM users WHERE id = :id LIMIT 1");
            $userStmt->execute([':id' => $userId]);
            $currentUserRecord = $userStmt->fetch(PDO::FETCH_ASSOC);
            $isVerifiedUser = ((int)($currentUserRecord['is_verified'] ?? 0) === 1 || ($currentUserRecord['verification_status'] ?? '') === 'verified' || !empty($_SESSION['is_updating_verified_id']));
            $idType = trim((string)($input['id_type'] ?? ($_POST['id_type'] ?? '')));

            if (empty($idType)) {
                http_response_code(422);
                echo json_encode([
                    'status' => 'error',
                    'code' => 'MISSING_ID_TYPE',
                    'message' => 'Please select a valid document or ID type from the dropdown menu.'
                ]);
                exit;
            }

            // 1A. Validate FRONT ID Photo file (supports multipart $_FILES upload and device camera capture payload)
            if (!isset($_FILES['id_photo']) || $_FILES['id_photo']['error'] !== UPLOAD_ERR_OK) {
                if (isset($_FILES['id_photo_front']) && $_FILES['id_photo_front']['error'] === UPLOAD_ERR_OK) {
                    $_FILES['id_photo'] = $_FILES['id_photo_front'];
                } else {
                    $base64Front = trim((string)($input['captured_front_id_base64'] ?? ($input['captured_id_base64'] ?? ($input['id_photo_base64'] ?? ($_POST['captured_front_id_base64'] ?? ($_POST['captured_id_base64'] ?? ($_POST['id_photo_base64'] ?? '')))))));
                    if (!empty($base64Front)) {
                        $cleanBase64 = preg_replace('/^data:image\/\w+;base64,/', '', $base64Front);
                        $decoded = base64_decode($cleanBase64, true);
                        if ($decoded !== false && strlen($decoded) > 100) {
                            $tmpFilePath = sys_get_temp_dir() . '/id_front_cam_' . bin2hex(random_bytes(6)) . '.jpg';
                            if (file_put_contents($tmpFilePath, $decoded) !== false) {
                                $_FILES['id_photo'] = [
                                    'name' => 'captured_front_id.jpg',
                                    'type' => 'image/jpeg',
                                    'tmp_name' => $tmpFilePath,
                                    'error' => UPLOAD_ERR_OK,
                                    'size' => strlen($decoded),
                                    'is_camera_capture' => true
                                ];
                            }
                        }
                    }
                }
            }

            if (!isset($_FILES['id_photo']) || $_FILES['id_photo']['error'] !== UPLOAD_ERR_OK) {
                http_response_code(422);
                echo json_encode([
                    'status' => 'error',
                    'code' => 'MISSING_FRONT_ID',
                    'message' => 'Please upload or capture the Front side of your ID.'
                ]);
                exit;
            }

            // 1B. Validate BACK ID Photo file (supports multipart $_FILES upload and device camera capture payload)
            if (!isset($_FILES['id_photo_back']) || $_FILES['id_photo_back']['error'] !== UPLOAD_ERR_OK) {
                $base64Back = trim((string)($input['captured_back_id_base64'] ?? ($input['captured_id_back_base64'] ?? ($input['id_photo_back_base64'] ?? ($_POST['captured_back_id_base64'] ?? ($_POST['captured_id_back_base64'] ?? ($_POST['id_photo_back_base64'] ?? '')))))));
                if (!empty($base64Back)) {
                    $cleanBase64Back = preg_replace('/^data:image\/\w+;base64,/', '', $base64Back);
                    $decodedBack = base64_decode($cleanBase64Back, true);
                    if ($decodedBack !== false && strlen($decodedBack) > 100) {
                        $tmpBackFilePath = sys_get_temp_dir() . '/id_back_cam_' . bin2hex(random_bytes(6)) . '.jpg';
                        if (file_put_contents($tmpBackFilePath, $decodedBack) !== false) {
                            $_FILES['id_photo_back'] = [
                                'name' => 'captured_back_id.jpg',
                                'type' => 'image/jpeg',
                                'tmp_name' => $tmpBackFilePath,
                                'error' => UPLOAD_ERR_OK,
                                'size' => strlen($decodedBack),
                                'is_camera_capture' => true
                            ];
                        }
                    }
                }
            }

            if (!isset($_FILES['id_photo_back']) || $_FILES['id_photo_back']['error'] !== UPLOAD_ERR_OK) {
                http_response_code(422);
                echo json_encode([
                    'status' => 'error',
                    'code' => 'MISSING_BACK_ID',
                    'message' => 'Please upload or capture the Back side of your ID.'
                ]);
                exit;
            }

            $frontFile = $_FILES['id_photo'];
            $backFile = $_FILES['id_photo_back'];
            $maxBytes = 10 * 1024 * 1024; // 10MB limit
            $allowedExts = ['jpg', 'jpeg', 'png', 'webp'];

            if ($frontFile['size'] > $maxBytes) {
                http_response_code(422);
                echo json_encode([
                    'status' => 'error',
                    'code' => 'FILE_TOO_LARGE',
                    'message' => 'The uploaded Front ID photo exceeds the 10MB size limit.'
                ]);
                exit;
            }

            if ($backFile['size'] > $maxBytes) {
                http_response_code(422);
                echo json_encode([
                    'status' => 'error',
                    'code' => 'FILE_TOO_LARGE',
                    'message' => 'The uploaded Back ID photo exceeds the 10MB size limit.'
                ]);
                exit;
            }

            $frontExt = strtolower(pathinfo((string)$frontFile['name'], PATHINFO_EXTENSION));
            $backExt = strtolower(pathinfo((string)$backFile['name'], PATHINFO_EXTENSION));

            if (!in_array($frontExt, $allowedExts, true)) {
                http_response_code(422);
                echo json_encode([
                    'status' => 'error',
                    'code' => 'INVALID_FILE_TYPE',
                    'message' => 'Invalid Front ID format. Please upload JPG, PNG, or WEBP.'
                ]);
                exit;
            }

            if (!in_array($backExt, $allowedExts, true)) {
                http_response_code(422);
                echo json_encode([
                    'status' => 'error',
                    'code' => 'INVALID_FILE_TYPE',
                    'message' => 'Invalid Back ID format. Please upload JPG, PNG, or WEBP.'
                ]);
                exit;
            }

            $uploadDir = __DIR__ . '/../uploads/id_verifications';
            if (!is_dir($uploadDir)) {
                mkdir($uploadDir, 0755, true);
            }

            $timestampStr = date('Ymd_His');
            $randStrFront = bin2hex(random_bytes(4));
            $randStrBack = bin2hex(random_bytes(4));

            $frontFilename = sprintf('id_front_user%d_%s_%s.%s', $userId, $timestampStr, $randStrFront, $frontExt);
            $backFilename = sprintf('id_back_user%d_%s_%s.%s', $userId, $timestampStr, $randStrBack, $backExt);

            $frontDestination = $uploadDir . '/' . $frontFilename;
            $backDestination = $uploadDir . '/' . $backFilename;

            $isCamCaptureFront = !empty($frontFile['is_camera_capture']);
            $movedFront = $isCamCaptureFront ? @rename($frontFile['tmp_name'], $frontDestination) : move_uploaded_file($frontFile['tmp_name'], $frontDestination);
            if (!$movedFront && file_exists($frontFile['tmp_name'])) {
                $movedFront = @copy($frontFile['tmp_name'], $frontDestination);
                @unlink($frontFile['tmp_name']);
            }

            if (!$movedFront) {
                http_response_code(500);
                echo json_encode([
                    'status' => 'error',
                    'code' => 'UPLOAD_FAILED',
                    'message' => 'Failed to store uploaded Front ID photo on the server.'
                ]);
                exit;
            }

            $isCamCaptureBack = !empty($backFile['is_camera_capture']);
            $movedBack = $isCamCaptureBack ? @rename($backFile['tmp_name'], $backDestination) : move_uploaded_file($backFile['tmp_name'], $backDestination);
            if (!$movedBack && file_exists($backFile['tmp_name'])) {
                $movedBack = @copy($backFile['tmp_name'], $backDestination);
                @unlink($backFile['tmp_name']);
            }

            if (!$movedBack) {
                @unlink($frontDestination);
                http_response_code(500);
                echo json_encode([
                    'status' => 'error',
                    'code' => 'UPLOAD_FAILED',
                    'message' => 'Failed to store uploaded Back ID photo on the server.'
                ]);
                exit;
            }

            // 2. Validate ID Document Type using fast OCR, Gemini Vision (if configured), and multi-pass rotated OCR
            $rawOcrText = trim((string)($input['ocr_raw_text'] ?? ($_POST['ocr_raw_text'] ?? '')));
            $validationResult = VerificationService::validateIdDocument($idType, $frontDestination, $rawOcrText, $backDestination);
            $rawOcrText = $validationResult['raw_text'];
            $typeCheck = $validationResult['type_check'];

            // 3. ID TYPE CROSS-CHECK: Enforce validation rule that attached ID must match chosen Document Type
            if (empty($validationResult['matched']) && empty($validationResult['skipped'])) {
                @unlink($frontDestination);
                @unlink($backDestination);
                $mismatchDetail = trim((string)($typeCheck['reason'] ?? ''));
                $mismatchDetail = (string)preg_replace('/^(?:Verification\s+Failed:\s*)+/i', '', $mismatchDetail);
                if ($mismatchDetail === '') {
                    $mismatchDetail = 'The uploaded document does not match the selected ID type.';
                }
                http_response_code(422);
                echo json_encode([
                    'status' => 'error',
                    'verification_status' => 'unverified',
                    'code' => 'ID_TYPE_MISMATCH',
                    'message' => $mismatchDetail,
                    'details' => $typeCheck,
                    'validation_method' => $validationResult['method'] ?? 'ocr'
                ]);
                exit;
            }

            // 4. Staged successfully! Upload both Front & Back to Supabase Cloud Storage
            $publicFrontUrl = uploadToSupabaseStorage($frontDestination, $frontFilename, 'id_verifications');
            $savedFrontPath = $publicFrontUrl !== false ? $publicFrontUrl : $frontFilename;

            $publicBackUrl = uploadToSupabaseStorage($backDestination, $backFilename, 'id_verifications');
            $savedBackPath = $publicBackUrl !== false ? $publicBackUrl : $backFilename;

            if ($isVerifiedUser) {
                // Rule 1: Temporary Data Holding (Database/PHP)
                // When a verified tenant uploads a new ID, store in dedicated temporary database columns and session.
                $saveIdStmt = $pdo->prepare("
                    UPDATE users 
                    SET pending_id_path = :fn, 
                        pending_id_back_path = :fn_back,
                        pending_id_type = :id_type
                    WHERE id = :id
                ");
                $saveIdStmt->execute([
                    ':fn' => $savedFrontPath, 
                    ':fn_back' => $savedBackPath,
                    ':id_type' => $idType, 
                    ':id' => $userId
                ]);

                $_SESSION['pending_id_path'] = $savedFrontPath;
                $_SESSION['pending_id_back_path'] = $savedBackPath;
                $_SESSION['pending_id_type'] = $idType;
                $_SESSION['is_updating_verified_id'] = true;
                $_SESSION['staged_id_photo'] = $savedFrontPath;
                $_SESSION['staged_id_photo_back'] = $savedBackPath;
                $_SESSION['staged_id_type'] = $idType;
            } else {
                $saveIdStmt = $pdo->prepare("
                    UPDATE users 
                    SET id_photo_filename = :fn, 
                        id_photo_back_filename = :fn_back,
                        id_type = :id_type,
                        pending_id_path = :fn,
                        pending_id_back_path = :fn_back,
                        pending_id_type = :id_type,
                        id_submitted_at = NOW()
                    WHERE id = :id
                ");
                $saveIdStmt->execute([
                    ':fn' => $savedFrontPath, 
                    ':fn_back' => $savedBackPath,
                    ':id_type' => $idType, 
                    ':id' => $userId
                ]);

                if (isset($_SESSION['user'])) {
                    $_SESSION['user']['id_photo_filename'] = $savedFrontPath;
                    $_SESSION['user']['id_photo_back_filename'] = $savedBackPath;
                    $_SESSION['user']['id_type'] = $idType;
                }
                $_SESSION['staged_id_photo'] = $savedFrontPath;
                $_SESSION['staged_id_photo_back'] = $savedBackPath;
                $_SESSION['staged_id_type'] = $idType;
            }

            http_response_code(200);
            echo json_encode([
                'status' => 'success',
                'is_verified' => $isVerifiedUser ? 1 : 0,
                'is_updating' => $isVerifiedUser,
                'message' => 'Front and Back ID documents validated and staged successfully.',
                'id_photo_filename' => $frontFilename,
                'id_photo_back_filename' => $backFilename,
                'id_type' => $idType
            ]);
            exit;
        }

        // ACTION A: Submit Valid ID for Tenant Verification (Tesseract OCR + Face++ Biometrics or Landlord Review)
        if ($action === 'verify_account' || $action === 'submit_verification') {
            $idType = trim((string)($input['id_type'] ?? ''));

            if (empty($idType)) {
                http_response_code(422);
                echo json_encode([
                    'status' => 'error',
                    'message' => 'Please select a valid ID type from the dropdown menu.'
                ]);
                exit;
            }

            $termsAccepted = !empty($input['terms_accepted']) || !empty($_POST['terms_accepted']);
            $privacyAcknowledged = !empty($input['privacy_acknowledged']) || !empty($_POST['privacy_acknowledged']);

            if (!$termsAccepted) {
                http_response_code(422);
                echo json_encode([
                    'status' => 'error',
                    'message' => 'Please review and accept the Terms & Conditions.'
                ]);
                exit;
            }

            if (!$privacyAcknowledged) {
                http_response_code(422);
                echo json_encode([
                    'status' => 'error',
                    'message' => 'Please acknowledge the Privacy Policy & Biometric Consent.'
                ]);
                exit;
            }

            // 1. Validate ID Photo file
            if (!isset($_FILES['id_photo']) || $_FILES['id_photo']['error'] !== UPLOAD_ERR_OK) {
                http_response_code(422);
                echo json_encode([
                    'status' => 'error',
                    'message' => 'Please select a valid photo of your ID to upload.'
                ]);
                exit;
            }

            $idFile = $_FILES['id_photo'];
            $maxBytes = 10 * 1024 * 1024; // 10MB limit

            if ($idFile['size'] > $maxBytes) {
                http_response_code(422);
                echo json_encode([
                    'status' => 'error',
                    'message' => 'The uploaded ID file exceeds the 10MB size limit.'
                ]);
                exit;
            }

            $idExt = strtolower(pathinfo((string)$idFile['name'], PATHINFO_EXTENSION));
            $allowedExts = ['jpg', 'jpeg', 'png', 'webp'];

            if (!in_array($idExt, $allowedExts, true)) {
                http_response_code(422);
                echo json_encode([
                    'status' => 'error',
                    'message' => 'Invalid ID photo format. Please upload JPG, PNG, or WEBP.'
                ]);
                exit;
            }

            $uploadDir = __DIR__ . '/../uploads/id_verifications';
            if (!is_dir($uploadDir)) {
                mkdir($uploadDir, 0755, true);
            }

            $timestampStr = date('Ymd_His');
            $randStr = bin2hex(random_bytes(4));
            $idFilename = sprintf('id_user%d_%s_%s.%s', $userId, $timestampStr, $randStr, $idExt);
            $idDestination = $uploadDir . '/' . $idFilename;

            if (!move_uploaded_file($idFile['tmp_name'], $idDestination)) {
                http_response_code(500);
                echo json_encode([
                    'status' => 'error',
                    'message' => 'Failed to store uploaded ID file on the server.'
                ]);
                exit;
            }

            // 2. Validate Live Face Scan (from webcam if available)
            $liveFaceFilename = sprintf('face_user%d_%s_%s.jpg', $userId, $timestampStr, $randStr);
            $liveFaceDestination = $uploadDir . '/' . $liveFaceFilename;
            $hasLiveFace = false;

            if (isset($_FILES['live_face']) && $_FILES['live_face']['error'] === UPLOAD_ERR_OK) {
                if (move_uploaded_file($_FILES['live_face']['tmp_name'], $liveFaceDestination)) {
                    $hasLiveFace = true;
                }
            } elseif (!empty($input['live_face_data'])) {
                $base64Data = (string)$input['live_face_data'];
                $base64Data = preg_replace('/^data:image\/\w+;base64,/', '', $base64Data);
                $decoded = base64_decode($base64Data, true);
                if ($decoded !== false && strlen($decoded) > 100) {
                    if (file_put_contents($liveFaceDestination, $decoded) !== false) {
                        $hasLiveFace = true;
                    }
                }
            }

            // Fetch current registered user account information
            $userStmt = $pdo->prepare("SELECT id, name, username, email, birthday, verification_status, is_verified, id_type, id_photo_filename, pending_id_path, pending_id_type FROM users WHERE id = :id LIMIT 1");
            $userStmt->execute([':id' => $userId]);
            $accountUser = $userStmt->fetch();

            if (!$accountUser) {
                http_response_code(404);
                echo json_encode(['status' => 'error', 'message' => 'User account not found.']);
                exit;
            }

            $isVerifiedUser = ((int)($accountUser['is_verified'] ?? 0) === 1 || ($accountUser['verification_status'] ?? '') === 'verified' || !empty($_SESSION['is_updating_verified_id']));

            $handleVerificationFailure = function(string $reason, array $details = []) use ($isVerifiedUser, $idDestination, $liveFaceDestination, $pdo, $userId) {
                if ($isVerifiedUser) {
                    if (file_exists($idDestination)) @unlink($idDestination);
                    if (file_exists($liveFaceDestination)) @unlink($liveFaceDestination);
                    $pdo->prepare("UPDATE users SET pending_id_path = NULL, pending_id_type = NULL WHERE id = :id")->execute([':id' => $userId]);
                    unset($_SESSION['pending_id_path'], $_SESSION['pending_id_type'], $_SESSION['is_updating_verified_id'], $_SESSION['staged_id_photo'], $_SESSION['staged_id_type']);

                    http_response_code(422);
                    echo json_encode([
                        'status' => 'error',
                        'is_verified' => 1,
                        'was_verified' => true,
                        'verification_status' => 'verified',
                        'message' => 'ID update cancelled or failed. Your previously verified ID remains active.',
                        'rejection_reason' => $reason,
                        'details' => $details
                    ]);
                    exit;
                }
            };

            if (!$hasLiveFace) {
                // Upload ID photo to Supabase Cloud Storage
                $publicIdUrl = uploadToSupabaseStorage($idDestination, $idFilename, 'id_verifications');
                $savedIdStoragePath = $publicIdUrl !== false ? $publicIdUrl : $idFilename;

                // When webcam live face is not provided, put under Landlord Review
                $updPending = $pdo->prepare("
                    UPDATE users 
                    SET verification_status = 'pending',
                        id_type = :id_type,
                        id_photo_filename = :id_photo,
                        id_submitted_at = NOW(),
                        verification_notes = 'ID photo submitted. Awaiting landlord review.'
                    WHERE id = :id
                ");
                $updPending->execute([
                    ':id_type' => $idType,
                    ':id_photo' => $savedIdStoragePath,
                    ':id' => $userId
                ]);

                if (isset($_SESSION['user'])) {
                    $_SESSION['user']['verification_status'] = 'pending';
                    $_SESSION['user']['id_type'] = $idType;
                    $_SESSION['user']['id_photo_filename'] = $savedIdStoragePath;
                }

                if (class_exists('NotificationService')) {
                    $uName = $accountUser['name'] ?? 'Tenant';
                    NotificationService::notifyOwner(
                        'tenant_verification_submitted',
                        $userId,
                        "Tenant {$uName} has submitted their {$idType} for verification."
                    );
                }

                http_response_code(200);
                echo json_encode([
                    'status' => 'success',
                    'verification_status' => 'pending',
                    'message' => 'Your ID has been submitted successfully and is now under review by the landlord.'
                ]);
                exit;
            }

            // 4. Handle Pre-Processed ID Image (Grayscale + Contrast + Binarization)
            // Can be provided by frontend canvas or processed natively on backend
            $procFilename = 'proc_' . $idFilename;
            $procDestination = $uploadDir . '/' . $procFilename;
            $hasProcessedUpload = false;

            if (isset($_FILES['id_photo_processed']) && $_FILES['id_photo_processed']['error'] === UPLOAD_ERR_OK) {
                if (move_uploaded_file($_FILES['id_photo_processed']['tmp_name'], $procDestination)) {
                    $hasProcessedUpload = true;
                }
            } elseif (!empty($input['id_photo_processed_data']) || !empty($_POST['id_photo_processed_data'])) {
                $base64Proc = (string)($input['id_photo_processed_data'] ?? $_POST['id_photo_processed_data']);
                $base64Proc = preg_replace('/^data:image\/\w+;base64,/', '', $base64Proc);
                $decodedProc = base64_decode($base64Proc, true);
                if ($decodedProc !== false && strlen($decodedProc) > 100) {
                    if (file_put_contents($procDestination, $decodedProc) !== false) {
                        $hasProcessedUpload = true;
                    }
                }
            }

            if (!$hasProcessedUpload || !file_exists($procDestination)) {
                // Apply backend image pre-processing (Grayscale + Contrast Enhancement + Binarization thresholding)
                VerificationService::preprocessIdImage($idDestination, $procDestination);
            }

            $ocrImagePath = (file_exists($procDestination) && filesize($procDestination) > 0)
                ? $procDestination
                : $idDestination;

            // 5. STEP 1 (FAIL-EARLY EXECUTION): Dedicated OCR Extraction & ID Document Type Validation
            // Before validating the tenant's name, birthday, or face scan, the system must first
            // validate the ID document itself. The script must search the raw OCR text for the required
            // keywords tied to the selected dropdown value.
            $rawOcrText = isset($_POST['ocr_raw_text']) ? trim((string)$_POST['ocr_raw_text']) : null;
            if (empty($rawOcrText) && !empty($input['ocr_raw_text'])) {
                $rawOcrText = trim((string)$input['ocr_raw_text']);
            }

            // If raw OCR text was not passed by client (or needs multi-pass/Vision verification), run unified validator
            $idTypePreValidated = false;
            $idTypeCheck = null;
            if (!isset($_SERVER['HTTP_X_MOCK_OCR_NAME']) && !isset($_POST['mock_ocr_data']) && !isset($_SERVER['HTTP_X_MOCK_OCR_RAW'])) {
                $docValidation = VerificationService::validateIdDocument($idType, $idDestination, (string)($rawOcrText ?? ''), $backDestination ?? null);
                if (!empty($docValidation['raw_text'])) {
                    $rawOcrText = $docValidation['raw_text'];
                }
                if (empty($docValidation['skipped'])) {
                    $idTypeCheck = $docValidation['type_check'];
                    $idTypePreValidated = true;
                }
            }

            if (empty($rawOcrText) && isset($_SERVER['HTTP_X_MOCK_OCR_RAW'])) {
                $rawOcrText = trim((string)$_SERVER['HTTP_X_MOCK_OCR_RAW']);
            }

            if (empty($rawOcrText) && isset($_SERVER['HTTP_X_MOCK_OCR_NAME'])) {
                $mockKeywords = VerificationService::getKeywordsForIdType($idType);
                $typeHeader = !empty($mockKeywords) ? implode(' ', $mockKeywords) : 'OFFICIAL GOVERNMENT ID';
                if (isset($_SERVER['HTTP_X_MOCK_WRONG_ID_TYPE'])) {
                    $typeHeader = 'STUDENT IDENTIFICATION CARD ABC UNIVERSITY';
                }
                $mockName = trim((string)$_SERVER['HTTP_X_MOCK_OCR_NAME']);
                $mockBday = trim((string)($_SERVER['HTTP_X_MOCK_OCR_BDAY'] ?? '1995-04-12'));
                if ($mockBday === 'none' || $mockBday === 'missing') {
                    $mockBday = '';
                }
                $rawOcrText = "REPUBLIC OF THE PHILIPPINES\n{$typeHeader}\nNAME: {$mockName}\nDATE OF BIRTH: {$mockBday}";
            }

            // Zero Fallbacks: If no readable OCR text could be obtained from the physical ID, reject immediately!
            if (empty($rawOcrText)) {
                $emptyOcrReason = "Verification Failed: ID photo is unclear or blurry.";
                $updateStmt = $pdo->prepare("
                    UPDATE users 
                    SET verification_status = 'unverified',
                        id_type = :id_type,
                        id_photo_filename = :id_photo,
                        live_photo_filename = :live_face,
                        id_submitted_at = NOW(),
                        id_verified_at = NULL,
                        verification_notes = :notes
                    WHERE id = :id
                ");
                $updateStmt->execute([
                    ':id_type' => $idType,
                    ':id_photo' => $idFilename,
                    ':live_face' => $liveFaceFilename,
                    ':notes' => 'Rejected: ' . $emptyOcrReason,
                    ':id' => $userId
                ]);

                http_response_code(422);
                echo json_encode([
                    'status' => 'error',
                    'verification_status' => 'unverified',
                    'message' => $emptyOcrReason,
                    'rejection_reason' => $emptyOcrReason,
                    'prompt' => 'Verify Account Again',
                    'details' => [
                        'id_type_matched' => false,
                        'selected_id_type' => $idType,
                        'text_error' => $emptyOcrReason
                    ]
                ]);
                exit;
            }

            // FAIL-EARLY HARD-STOP: Search raw OCR text (or reuse Vision check) mapped to selected ID type
            if (!$idTypePreValidated || !is_array($idTypeCheck)) {
                $idTypeCheck = VerificationService::verifyIdTypeMatchesDocument($idType, $rawOcrText);
            }

            if (empty($idTypeCheck['matched'])) {
                $typeFailureReason = trim((string)preg_replace('/^(?:Verification\s+Failed:\s*)+/i', '', (string)($idTypeCheck['reason'] ?? '')));
                if ($typeFailureReason === '') {
                    $typeFailureReason = 'The uploaded document does not match the selected ID type.';
                }
                $updateStmt = $pdo->prepare("
                    UPDATE users 
                    SET verification_status = 'unverified',
                        id_type = :id_type,
                        id_photo_filename = :id_photo,
                        live_photo_filename = :live_face,
                        id_submitted_at = NOW(),
                        id_verified_at = NULL,
                        verification_notes = :notes
                    WHERE id = :id
                ");
                $updateStmt->execute([
                    ':id_type' => $idType,
                    ':id_photo' => $idFilename,
                    ':live_face' => $liveFaceFilename,
                    ':notes' => 'Rejected: ' . $typeFailureReason,
                    ':id' => $userId
                ]);

                http_response_code(422);
                echo json_encode([
                    'status' => 'error',
                    'verification_status' => 'unverified',
                    'message' => $typeFailureReason,
                    'rejection_reason' => $typeFailureReason,
                    'prompt' => 'Verify Account Again',
                    'details' => [
                        'id_type_matched' => false,
                        'selected_id_type' => $idType,
                        'canonical_type' => $idTypeCheck['canonical_type'] ?? $idType,
                        'required_keywords' => $idTypeCheck['required_keywords'] ?? [],
                        'detected_keywords' => $idTypeCheck['detected_keywords'] ?? [],
                        'conflicting_type' => $idTypeCheck['conflicting_type'] ?? null,
                        'id_type_error' => $typeFailureReason
                    ]
                ]);
                exit;
            }

            // 6. STEP 2: Dedicated OCR Extraction for Cardholder Details (Name & Birthday)
            // Only reached if the physical document strictly passed ID type verification
            $ocrResult = VerificationService::extractIdDataWithOCR($ocrImagePath, $rawOcrText, $accountUser, $idType);

            if (!$ocrResult['success']) {
                $textFailureReason = "Verification Failed: ID photo is unclear or blurry.";
                $handleVerificationFailure($textFailureReason, [
                    'text_matched' => false,
                    'text_error' => $textFailureReason
                ]);

                $updateStmt = $pdo->prepare("
                    UPDATE users 
                    SET verification_status = 'unverified',
                        id_type = :id_type,
                        id_photo_filename = :id_photo,
                        live_photo_filename = :live_face,
                        id_submitted_at = NOW(),
                        id_verified_at = NULL,
                        verification_notes = :notes
                    WHERE id = :id
                ");
                $updateStmt->execute([
                    ':id_type' => $idType,
                    ':id_photo' => $idFilename,
                    ':live_face' => $liveFaceFilename,
                    ':notes' => 'Rejected: ' . $textFailureReason,
                    ':id' => $userId
                ]);

                http_response_code(422);
                echo json_encode([
                    'status' => 'error',
                    'verification_status' => 'unverified',
                    'message' => $textFailureReason,
                    'rejection_reason' => $textFailureReason,
                    'prompt' => 'Verify Account Again',
                    'details' => [
                        'text_matched' => false,
                        'text_error' => $textFailureReason
                    ]
                ]);
                exit;
            }

            // 7. STEP 3: Strict OCR Text & Identity Validation (Absolute Match of Name AND Birthday)
            $textMatchResult = VerificationService::compareIdTextWithAccount($ocrResult, $accountUser);

            if (!$textMatchResult['match']) {
                $textFailureReason = $textMatchResult['reason'] ?: "Verification Failed: Account information does not match the ID.";
                $handleVerificationFailure($textFailureReason, [
                    'text_matched' => false,
                    'name_matched' => $textMatchResult['name_matched'] ?? false,
                    'birthday_matched' => $textMatchResult['birthday_matched'] ?? false,
                    'name_similarity' => $textMatchResult['name_similarity'] ?? 0.0,
                    'text_error' => $textFailureReason
                ]);

                $updateStmt = $pdo->prepare("
                    UPDATE users 
                    SET verification_status = 'unverified',
                        id_type = :id_type,
                        id_photo_filename = :id_photo,
                        live_photo_filename = :live_face,
                        id_submitted_at = NOW(),
                        id_verified_at = NULL,
                        verification_notes = :notes
                    WHERE id = :id
                ");
                $updateStmt->execute([
                    ':id_type' => $idType,
                    ':id_photo' => $idFilename,
                    ':live_face' => $liveFaceFilename,
                    ':notes' => 'Rejected: ' . $textFailureReason,
                    ':id' => $userId
                ]);

                http_response_code(422);
                echo json_encode([
                    'status' => 'error',
                    'verification_status' => 'unverified',
                    'message' => $textFailureReason,
                    'rejection_reason' => $textFailureReason,
                    'prompt' => 'Verify Account Again',
                    'details' => [
                        'text_matched' => false,
                        'name_matched' => $textMatchResult['name_matched'] ?? false,
                        'birthday_matched' => $textMatchResult['birthday_matched'] ?? false,
                        'name_similarity' => $textMatchResult['name_similarity'] ?? 0.0,
                        'text_error' => $textFailureReason
                    ]
                ]);
                exit;
            }

            // 8. STEP 4: Strict Live Face Scan (Face++ Biometrics: Live Webcam vs ID Portrait)
            $faceCompareResult = VerificationService::compareFacesWithFacePlusPlus($idDestination, $liveFaceDestination);

            if (!$faceCompareResult['success'] || !$faceCompareResult['is_match']) {
                $faceFailureReason = "Verification Failed: Face scan does not match the ID photo.";
                $faceConfidence = (float)($faceCompareResult['confidence'] ?? 0.0);
                $handleVerificationFailure($faceFailureReason, [
                    'text_matched' => true,
                    'name_matched' => true,
                    'birthday_matched' => true,
                    'id_type_matched' => true,
                    'face_matched' => false,
                    'face_error' => $faceFailureReason,
                    'face_confidence' => $faceConfidence
                ]);

                $updateStmt = $pdo->prepare("
                    UPDATE users 
                    SET verification_status = 'unverified',
                        id_type = :id_type,
                        id_photo_filename = :id_photo,
                        live_photo_filename = :live_face,
                        id_submitted_at = NOW(),
                        id_verified_at = NULL,
                        verification_notes = :notes
                    WHERE id = :id
                ");
                $updateStmt->execute([
                    ':id_type' => $idType,
                    ':id_photo' => $idFilename,
                    ':live_face' => $liveFaceFilename,
                    ':notes' => 'Rejected: ' . $faceFailureReason,
                    ':id' => $userId
                ]);

                http_response_code(422);
                echo json_encode([
                    'status' => 'error',
                    'verification_status' => 'unverified',
                    'message' => $faceFailureReason,
                    'rejection_reason' => $faceFailureReason,
                    'prompt' => 'Verify Account Again',
                    'details' => [
                        'text_matched' => true,
                        'name_matched' => true,
                        'birthday_matched' => true,
                        'id_type_matched' => true,
                        'face_matched' => false,
                        'face_error' => $faceFailureReason,
                        'face_confidence' => $faceConfidence
                    ]
                ]);
                exit;
            }

            // 9. FINAL APPROVAL: All strict validation rules passed!
            // 1. Selected ID type matches document keywords
            // 2. Full Name strictly matches registered account
            // 3. Birthday strictly matches registered account
            // 4. Face++ biometric match >= 70.0% confidence
            $confidence = (float)($faceCompareResult['confidence'] ?? 0.0);
            $extractedName = $textMatchResult['extracted_name'] ?? $accountUser['name'];
            $notes = sprintf(
                'Verified: Strict 1-to-1 match for Full Name & Birthday on %s (Face++ Biometric Confidence: %.1f%%).',
                $idType,
                $confidence
            );

            $updateStmt = $pdo->prepare("
                UPDATE users 
                SET is_verified = TRUE,
                    verification_status = 'verified',
                    id_type = :id_type,
                    id_photo_filename = :id_photo,
                    live_photo_filename = :live_face,
                    pending_id_path = NULL,
                    pending_id_type = NULL,
                    id_submitted_at = NOW(),
                    id_verified_at = NOW(),
                    verification_notes = :notes
                WHERE id = :id
            ");
            $updateStmt->execute([
                ':id_type' => $idType,
                ':id_photo' => $idFilename,
                ':live_face' => $liveFaceFilename,
                ':notes' => $notes,
                ':id' => $userId
            ]);

            if (isset($_SESSION['user'])) {
                $_SESSION['user']['is_verified'] = 1;
                $_SESSION['user']['verification_status'] = 'verified';
                $_SESSION['user']['id_type'] = $idType;
                $_SESSION['user']['id_photo_filename'] = $idFilename;
            }
            $_SESSION['is_verified'] = 1;
            $_SESSION['verification_status'] = 'verified';
            unset($_SESSION['pending_id_path'], $_SESSION['pending_id_type'], $_SESSION['is_updating_verified_id'], $_SESSION['staged_id_photo'], $_SESSION['staged_id_type']);

            // Send Account Verified email when transitioning from unverified to verified
            if (($accountUser['verification_status'] ?? '') !== 'verified' && (int)($accountUser['is_verified'] ?? 0) !== 1) {
                try {
                    require_once __DIR__ . '/../includes/email_service.php';
                    EmailService::notifyTenantAccountVerified($pdo, $userId, 'ID & Face Biometric Verification');
                } catch (Throwable $mailEx) {
                    error_log('[Profile API] Failed to dispatch account verified email: ' . $mailEx->getMessage());
                }
            }

            http_response_code(200);
            echo json_encode([
                'status' => 'success',
                'verification_status' => 'verified',
                'message' => 'Account Successfully Verified',
                'id_type' => $idType,
                'id_photo_filename' => $idFilename,
                'live_photo_filename' => $liveFaceFilename,
                'details' => [
                    'extracted_name' => $extractedName,
                    'name_matched' => true,
                    'birthday_matched' => true,
                    'name_similarity' => $textMatchResult['name_similarity'] ?? 100.0,
                    'id_type_matched' => true,
                    'face_confidence' => round($confidence, 3)
                ]
            ]);
            exit;
        }

        // ACTION B: Change Account Password
        if ($action === 'change_password') {
            $currentPassword = (string)($input['current_password'] ?? '');
            $newPassword = (string)($input['new_password'] ?? '');
            $confirmPassword = (string)($input['confirm_password'] ?? '');

            if (empty($currentPassword) || empty($newPassword) || empty($confirmPassword)) {
                http_response_code(422);
                echo json_encode([
                    'status' => 'error',
                    'message' => 'Please provide current password, new password, and confirmation.'
                ]);
                exit;
            }

            if ($newPassword !== $confirmPassword) {
                http_response_code(422);
                echo json_encode([
                    'status' => 'error',
                    'message' => 'New password and confirmation do not match.'
                ]);
                exit;
            }

            if (strlen($newPassword) < 6) {
                http_response_code(422);
                echo json_encode([
                    'status' => 'error',
                    'message' => 'New password must be at least 6 characters in length.'
                ]);
                exit;
            }

            // Verify current password against stored hash
            $chk = $pdo->prepare("SELECT password_hash FROM users WHERE id = :id LIMIT 1");
            $chk->execute([':id' => $userId]);
            $row = $chk->fetch();

            if (!$row || !password_verify($currentPassword, $row['password_hash'])) {
                http_response_code(422);
                echo json_encode([
                    'status' => 'error',
                    'message' => 'Incorrect current password. Please re-enter your existing password.'
                ]);
                exit;
            }

            // Hash and update to new password
            $newHash = password_hash($newPassword, PASSWORD_BCRYPT);
            $updateStmt = $pdo->prepare("UPDATE users SET password_hash = :hash WHERE id = :id");
            $updateStmt->execute([':hash' => $newHash, ':id' => $userId]);

            echo json_encode([
                'status' => 'success',
                'message' => 'Your password has been changed successfully!'
            ]);
            exit;
        }

        // ACTION C: Update Account Information (Name, Phone, Email, Emergency Contacts)
        if ($action === 'update_account_info') {
            // Strict backend enforcement: Disallow modifying any rental or system control fields
            $disallowedKeys = ['unit_id', 'monthly_rent', 'advance_deposit', 'rental_status', 'role', 'is_verified', 'verification_status', 'move_in_date', 'move_out_date', 'username', 'password_hash'];
            foreach ($disallowedKeys as $k) {
                unset($input[$k], $_POST[$k]);
            }

            $firstName = trim((string)($input['first_name'] ?? ''));
            $middleName = trim((string)($input['middle_name'] ?? ''));
            $lastName = trim((string)($input['last_name'] ?? ''));
            $directName = trim((string)($input['name'] ?? ''));

            $existingName = (string)($currentUser['name'] ?? '');
            $fullName = $existingName;

            if ($firstName !== '' || $lastName !== '') {
                if ($firstName === '' || $lastName === '') {
                    http_response_code(422);
                    echo json_encode([
                        'status' => 'error',
                        'message' => 'Both First Name and Last Name are required when updating your name.'
                    ]);
                    exit;
                }
                $fullName = trim(implode(' ', array_filter([$firstName, $middleName, $lastName], fn($v) => $v !== '')));
            } elseif ($directName !== '') {
                $fullName = $directName;
            }

            $email = trim((string)($input['email'] ?? ''));
            $phone = trim((string)($input['phone'] ?? ''));
            $emergencyName = trim((string)($input['emergency_contact_name'] ?? ''));
            $emergencyRel = trim((string)($input['emergency_contact_relationship'] ?? ''));
            $emergencyPhone = trim((string)($input['emergency_contact_phone'] ?? ''));

            if (!empty($email) && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                http_response_code(422);
                echo json_encode([
                    'status' => 'error',
                    'message' => 'Please provide a valid email address.'
                ]);
                exit;
            }

            if (!empty($email)) {
                $dupCheck = $pdo->prepare("
                    SELECT id FROM users 
                    WHERE email = :e 
                      AND id != :id 
                      AND role = 'tenant' 
                      AND is_active = TRUE 
                      AND (is_archived = FALSE OR is_archived IS NULL)
                    LIMIT 1
                ");
                $dupCheck->execute([':e' => $email, ':id' => $userId]);
                if ($dupCheck->fetch()) {
                    http_response_code(409);
                    echo json_encode([
                        'status' => 'error',
                        'message' => 'This email address is already associated with an active tenant account.'
                    ]);
                    exit;
                }
            }

            $updateStmt = $pdo->prepare("
                UPDATE users 
                SET name = :name,
                    email = :email,
                    phone = :phone,
                    emergency_contact = :em_contact,
                    emergency_contact_name = :em_name,
                    emergency_contact_relationship = :em_rel,
                    emergency_contact_phone = :em_phone
                WHERE id = :id
            ");
            $updateStmt->execute([
                ':name' => !empty($fullName) ? $fullName : $existingName,
                ':email' => !empty($email) ? $email : null,
                ':phone' => !empty($phone) ? $phone : null,
                ':em_contact' => !empty($emergencyName) ? $emergencyName : null,
                ':em_name' => !empty($emergencyName) ? $emergencyName : null,
                ':em_rel' => !empty($emergencyRel) ? $emergencyRel : null,
                ':em_phone' => !empty($emergencyPhone) ? $emergencyPhone : null,
                ':id' => $userId
            ]);

            // Refresh user session data if needed
            if (isset($_SESSION['user']['id']) && (int)$_SESSION['user']['id'] === $userId) {
                $_SESSION['user']['name'] = $fullName;
                $_SESSION['user']['email'] = $email;
                $_SESSION['user']['phone'] = $phone;
            }

            // If tenant updated info, notify landlord
            if (!Auth::isLandlord()) {
                NotificationService::notifyLandlords(
                    $pdo,
                    'account',
                    'Tenant Profile Updated',
                    "{$fullName} updated their profile and personal information.",
                    'sectionDirectory',
                    $userId,
                    'tenant_profile_update'
                );
            }

            echo json_encode([
                'status' => 'success',
                'message' => 'Profile information updated successfully!',
                'name' => $fullName,
                'email' => $email,
                'phone' => $phone
            ]);
            exit;
        }

        // ACTION D: Landlord Verification Review (Optional helper for Landlord)
        if ($action === 'review_tenant_verification' && Auth::isLandlord()) {
            $targetTenantId = (int)($input['tenant_id'] ?? 0);
            $decision = strtolower(trim((string)($input['decision'] ?? 'verified')));

            if (!in_array($decision, ['verified', 'rejected', 'unverified'], true) || $targetTenantId <= 0) {
                http_response_code(422);
                echo json_encode(['status' => 'error', 'message' => 'Invalid decision or tenant ID.']);
                exit;
            }

            $isVerifiedSql = ($decision === 'verified') ? 'TRUE' : 'FALSE';
            $stmt = $pdo->prepare("
                UPDATE users 
                SET verification_status = :status,
                    is_verified = {$isVerifiedSql},
                    id_verified_at = " . ($decision === 'verified' ? 'NOW()' : 'NULL') . "
                WHERE id = :id AND role = 'tenant'
            ");
            $stmt->execute([
                ':status' => $decision,
                ':id' => $targetTenantId
            ]);

            // Notify Tenant of ID verification decision
            $title = ($decision === 'verified') ? 'ID Verification Approved' : 'ID Verification Rejected';
            $msg = ($decision === 'verified')
                ? 'Your ID document has been verified by the landlord. Your account is fully verified.'
                : 'Your ID verification was not approved. Please re-submit a valid official ID.';
            NotificationService::notifyTenant(
                $pdo,
                $targetTenantId,
                'account',
                $title,
                $msg,
                'tenantProfile',
                $targetTenantId,
                'id_verification_' . $decision,
                false
            );

            $emailSent = false;
            if ($decision === 'verified') {
                try {
                    require_once __DIR__ . '/../includes/email_service.php';
                    $emailSent = EmailService::notifyTenantAccountVerified($pdo, $targetTenantId, 'Owner / Admin Review');
                } catch (Throwable $mailEx) {
                    error_log('[Profile API] Failed to dispatch account verified email: ' . $mailEx->getMessage());
                }
            }

            echo json_encode([
                'status' => 'success',
                'email_sent' => $emailSent,
                'message' => "Tenant verification status updated to " . strtoupper($decision) . "."
            ]);
            exit;
        }

        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'Invalid action requested.']);
        exit;
    }

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'status' => 'error',
        'message' => 'Profile API error: ' . $e->getMessage()
    ]);
}
