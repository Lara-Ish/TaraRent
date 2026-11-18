<?php
/**
 * RentConnect - Tenant Resident Portal
 * Multi-Tenant Architecture with Retractable Claymorphic Sidebar Navigation
 * Currency: ₱
 */

declare(strict_types=1);

// Prevent browser caching so Back button cannot display protected portal after logout
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Cache-Control: post-check=0, pre-check=0", false);
header("Pragma: no-cache");
header("Expires: Sat, 26 Jul 1997 05:00:00 GMT");

require_once __DIR__ . '/config/auth.php';
Auth::requireTenant();

$user = Auth::user();
$pdo = Database::getConnection();

// Fetch comprehensive tenant account status via centralized AccessControl
$tenantStatus = AccessControl::getTenantStatus($pdo, (int)$user['id']);

if (!$tenantStatus['found']) {
    Auth::logout();
    header('Location: login.php');
    exit;
}

// Rule 2: Silent Lockout (Moved Out & Paid)
// If the tenant's account is marked as moved out AND their outstanding balance is 0 or less,
// completely deny access. Destroy session and silently redirect back to the login page without any specific error messages or notifications.
if ($tenantStatus['mode'] === 'silent_lockout') {
    Auth::logout();
    header('Location: login.php');
    exit;
}

if ($tenantStatus['mode'] === 'deactivated') {
    Auth::logout();
    header('Location: login.php?msg=deactivated');
    exit;
}

$freshUser = $tenantStatus['user'];
$user = $freshUser;
$isTenantVerified = $tenantStatus['is_verified'];
$isMovedOutTenant = ($tenantStatus['mode'] === 'debt_collection');

// Rule 3: Debt Collection Mode (Moved Out & Unpaid)
// Restrict access exclusively to the Dashboard (for viewing history) and the Upload Payment page.
// Hard-redirect any attempts to access Log Maintenance or Reports back to the Dashboard.
if ($isMovedOutTenant) {
    $sec = trim((string)($_GET['section'] ?? 'tenantOverview'));
    if (!in_array($sec, ['tenantOverview', 'tenantPayments'], true)) {
        header('Location: tenant_portal.php?section=tenantOverview');
        exit;
    }
}

// Backend Protection: If a POST request is detected from an unverified user, abort and return access denied error.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$isTenantVerified) {
    http_response_code(403);
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode([
        'status'  => 'error',
        'code'    => 'ACCESS_DENIED',
        'message' => 'Access Denied: Please verify your identity to submit new records.'
    ]);
    exit;
}

// Resolve user avatar image URL dynamically from database record or uploaded avatar
$userAvatarUrl = '';
if (!empty($user['profile_picture'])) {
    $pic = (string)$user['profile_picture'];
    if (str_starts_with($pic, 'http://') || str_starts_with($pic, 'https://')) {
        $userAvatarUrl = htmlspecialchars($pic);
    } elseif (file_exists(__DIR__ . '/' . $pic)) {
        $userAvatarUrl = htmlspecialchars($pic);
    } elseif (file_exists(__DIR__ . '/uploads/avatars/' . basename($pic))) {
        $userAvatarUrl = 'uploads/avatars/' . htmlspecialchars(basename($pic));
    }
}

// Extract first letter of user's first name dynamically for profile icon (circular initial)
$userFullName = trim((string)($user['name'] ?? 'Tenant'));
$userFirstName = trim(explode(' ', $userFullName)[0]);
$userInitial = strtoupper(mb_substr($userFirstName, 0, 1, 'UTF-8'));
if ($userInitial === '') {
    $userInitial = 'T';
}

// Fetch assigned unit details
$unitStmt = $pdo->prepare("SELECT * FROM units WHERE id = :uid LIMIT 1");
$unitStmt->execute([':uid' => $user['unit_id'] ?? 1]);
$unit = $unitStmt->fetch() ?: [
    'unit_number' => 'Unit 1',
    'monthly_rent' => 3000.00
];

// ---------------------------------------------------------------------
// TENANT PAYMENT PROOF SUBMISSION (POST Handler)
// ---------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && (isset($_POST['action']) && $_POST['action'] === 'upload_payment_proof' || isset($_POST['amount']) && isset($_POST['billing_month']))) {
    // 1. Verify tenant authorization & verification status
    if (!$isTenantVerified) {
        header('Location: tenant_portal.php?section=tenantPayments&restricted=unverified');
        exit;
    }

    $tenantId = (int)$user['id'];
    $unitId = !empty($user['unit_id']) ? (int)$user['unit_id'] : (!empty($unit['id']) ? (int)$unit['id'] : 1);

    // 2. Capture all $_POST variables
    $amount = (float)($_POST['amount'] ?? 0.0);
    $billingMonth = trim((string)($_POST['billing_month'] ?? ''));
    $paymentMethod = trim((string)($_POST['payment_method'] ?? 'GCash'));
    $payFor = trim((string)($_POST['pay_for'] ?? 'Rent'));
    $referenceNumber = trim((string)($_POST['reference_number'] ?? ''));
    $targetPaymentId = (int)($_POST['payment_id'] ?? 0);

    // Normalize billing_cycle date format (YYYY-MM-01)
    if (!empty($billingMonth)) {
        $parsedTime = strtotime($billingMonth);
        $billingCycle = $parsedTime ? date('Y-m-01', $parsedTime) : date('Y-m-01');
    } else {
        $billingCycle = date('Y-m-01');
    }
    $dueDate = date('Y-m-05', strtotime($billingCycle));

    // Normalize payment type
    $paymentType = 'Rent';
    if ($payFor === 'Advance Deposit') {
        $paymentType = 'Advance Deposit';
    } elseif ($payFor === 'Additional Space') {
        $paymentType = 'Additional Space';
    } elseif ($payFor === 'Monthly Rent' || $payFor === 'Rent') {
        $paymentType = 'Rent';
    }

    // Embed reference number into payment_method string if supplied and not already present
    $fullPaymentMethod = $paymentMethod;
    if ($referenceNumber !== '' && stripos($paymentMethod, $referenceNumber) === false) {
        $fullPaymentMethod = $paymentMethod . ' (Ref: ' . $referenceNumber . ')';
    }

    // Determine base rent / amount due
    $baseRent = (float)($unit['monthly_rent'] ?? 3000.00);
    $amountDue = $amount > 0 ? $amount : $baseRent;

    // 3. File Upload Handling (Supabase Cloud Storage)

    $receiptFilename = null;
    if (isset($_FILES['receipt']) && $_FILES['receipt']['error'] === UPLOAD_ERR_OK) {
        $file = $_FILES['receipt'];
        $maxBytes = 10 * 1024 * 1024; // 10MB limit

        if ($file['size'] > $maxBytes) {
            header('Location: tenant_portal.php?section=tenantPayments&error=file_too_large');
            exit;
        }

        $origExt = strtolower(pathinfo((string)$file['name'], PATHINFO_EXTENSION));
        if (!in_array($origExt, ['jpg', 'jpeg', 'png', 'webp', 'pdf'], true)) {
            header('Location: tenant_portal.php?section=tenantPayments&error=invalid_file_type');
            exit;
        }

        // Validate MIME type
        if (function_exists('finfo_open')) {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            $mime = finfo_file($finfo, $file['tmp_name']);
            finfo_close($finfo);
            $allowedMimes = ['image/jpeg', 'image/png', 'image/webp', 'application/pdf'];
            if (!in_array($mime, $allowedMimes, true)) {
                header('Location: tenant_portal.php?section=tenantPayments&error=invalid_file_type');
                exit;
            }
        }

        // Generate unique filename: receipt_tenantID_timestamp.ext
        $timestamp = time();
        $safeName = "receipt_{$tenantId}_{$timestamp}_" . bin2hex(random_bytes(4)) . ".{$origExt}";

        // Upload directly to Supabase Cloud Storage bucket 'receipts'
        $publicReceiptUrl = uploadToSupabaseStorage($file['tmp_name'], $safeName, 'receipts');
        if ($publicReceiptUrl !== false) {
            $receiptFilename = $publicReceiptUrl;
        } else {
            header('Location: tenant_portal.php?section=tenantPayments&error=upload_failed');
            exit;
        }
    } else {
        header('Location: tenant_portal.php?section=tenantPayments&error=no_file');
        exit;
    }

    // 4. Database Insertion (PHP/PDO):
    // Insert new payment record with hardcoded initial status 'pending'
    $insertStmt = $pdo->prepare("
        INSERT INTO payments (
            tenant_id,
            unit_id,
            payment_type,
            billing_cycle,
            due_date,
            base_rent_amount,
            additional_space_fee,
            amount_due,
            amount_paid,
            payment_date,
            status,
            payment_method,
            receipt_filename,
            verified_by_landlord,
            created_at
        ) VALUES (
            :tenant_id,
            :unit_id,
            :payment_type,
            :billing_cycle,
            :due_date,
            :base_rent_amount,
            0.00,
            :amount_due,
            :amount_paid,
            CURRENT_DATE,
            'pending',
            :payment_method,
            :receipt_filename,
            FALSE,
            NOW()
        )
    ");

    $insertStmt->execute([
        ':tenant_id'        => $tenantId,
        ':unit_id'          => $unitId,
        ':payment_type'     => $paymentType,
        ':billing_cycle'    => $billingCycle,
        ':due_date'         => $dueDate,
        ':base_rent_amount' => $baseRent,
        ':amount_due'       => $amountDue,
        ':amount_paid'      => $amount,
        ':payment_method'   => $fullPaymentMethod,
        ':receipt_filename' => $receiptFilename
    ]);

    $newPaymentId = (int)$pdo->lastInsertId();

    // Clean up unpaid placeholder if the tenant paid an outstanding unpaid bill
    if ($targetPaymentId > 0) {
        $delStmt = $pdo->prepare("DELETE FROM payments WHERE id = :id AND tenant_id = :tid AND status = 'unpaid' AND amount_paid = 0");
        $delStmt->execute([':id' => $targetPaymentId, ':tid' => $tenantId]);
    }

    // 5. Notify Landlords
    try {
        require_once __DIR__ . '/includes/notification_service.php';
        $pTenantName = $user['name'] ?? 'Tenant';
        $pUnitNum = $unit['unit_number'] ?? 'Unit';
        $pAmountStr = '₱' . number_format($amount, 2);
        $pSubDate = date('M j, Y');
        NotificationService::notifyLandlords(
            $pdo,
            'payment',
            'Tenant Rent Payment Submission',
            "Tenant: {$pTenantName} • Unit: {$pUnitNum} • Amount Paid: {$pAmountStr} • Submitted: {$pSubDate}",
            'sectionPayments',
            $newPaymentId,
            'payment_submitted'
        );
    } catch (Throwable $e) {
        error_log('Notification error: ' . $e->getMessage());
    }

    // 6. Success Redirection: redirect tenant back with success flag and tracker tab active
    header('Location: tenant_portal.php?section=tenantPayments&tab=tracker&success=1');
    exit;
}

// ---------------------------------------------------------------------
// TENANT GENERAL REPORT & APPROVAL REQUEST (POST Handler)
// ---------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && (
    (isset($_POST['action']) && $_POST['action'] === 'submit_report') ||
    isset($_POST['request_category']) ||
    (isset($_POST['category']) && (isset($_POST['specification']) || isset($_POST['request_specification'])))
)) {
    // 1. Verify tenant authorization & verification status
    if (!$isTenantVerified) {
        if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) || (isset($_SERVER['HTTP_ACCEPT']) && str_contains($_SERVER['HTTP_ACCEPT'], 'application/json'))) {
            http_response_code(403);
            header('Content-Type: application/json; charset=UTF-8');
            echo json_encode(['status' => 'error', 'message' => 'Identity verification required before submitting requests.']);
            exit;
        }
        header('Location: tenant_portal.php?section=tenantReport&restricted=unverified');
        exit;
    }

    $tenantId = (int)$user['id'];
    $unitId = !empty($user['unit_id']) ? (int)$user['unit_id'] : (!empty($unit['id']) ? (int)$unit['id'] : 1);

    // 2. Process $_POST data (request_category, request_specification, request_description)
    $category = trim((string)($_POST['request_category'] ?? ($_POST['category'] ?? 'General Request')));
    $specification = trim((string)($_POST['request_specification'] ?? ($_POST['specification'] ?? ($_POST['subject'] ?? 'Untitled Request'))));
    $description = trim((string)($_POST['request_description'] ?? ($_POST['description'] ?? ($_POST['details'] ?? ''))));

    if (empty($specification) || empty($description)) {
        if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) || (isset($_SERVER['HTTP_ACCEPT']) && str_contains($_SERVER['HTTP_ACCEPT'], 'application/json'))) {
            http_response_code(422);
            header('Content-Type: application/json; charset=UTF-8');
            echo json_encode(['status' => 'error', 'message' => 'Specification and description are required.']);
            exit;
        }
        header('Location: tenant_portal.php?section=tenantReport&error=missing_fields');
        exit;
    }

    if ($category === '') {
        $category = 'General Request';
    }

    // 3. Securely handle $_FILES upload for supporting document (Supabase Cloud Storage)

    $savedFiles = [];
    $allowedExts = ['jpg', 'jpeg', 'png', 'webp', 'gif', 'pdf', 'doc', 'docx'];
    $allowedMimes = [
        'image/jpeg', 'image/png', 'image/webp', 'image/gif',
        'application/pdf',
        'application/msword',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document'
    ];

    $fileInputs = ['request_attachment', 'request_attachments', 'report_attachments', 'document', 'attachment', 'attachments', 'file'];
    foreach ($fileInputs as $fKey) {
        if (!isset($_FILES[$fKey])) continue;
        $f = $_FILES[$fKey];

        $filesToProcess = [];
        if (is_array($f['name'])) {
            $count = count($f['name']);
            for ($i = 0; $i < $count; $i++) {
                if ($f['error'][$i] === UPLOAD_ERR_OK && !empty($f['tmp_name'][$i])) {
                    $filesToProcess[] = [
                        'name'     => $f['name'][$i],
                        'tmp_name' => $f['tmp_name'][$i],
                        'size'     => $f['size'][$i],
                    ];
                }
            }
        } elseif ($f['error'] === UPLOAD_ERR_OK && !empty($f['tmp_name'])) {
            $filesToProcess[] = $f;
        }

        foreach ($filesToProcess as $fileItem) {
            if ($fileItem['size'] > 15 * 1024 * 1024) continue; // 15MB limit
            $ext = strtolower(pathinfo((string)$fileItem['name'], PATHINFO_EXTENSION));
            if (!in_array($ext, $allowedExts, true)) continue;

            if (function_exists('finfo_open')) {
                $finfo = finfo_open(FILEINFO_MIME_TYPE);
                $mime = finfo_file($finfo, $fileItem['tmp_name']);
                finfo_close($finfo);
                if (!in_array($mime, $allowedMimes, true)) continue;
            }

            $safeFilename = 'report_' . $tenantId . '_' . time() . '_' . bin2hex(random_bytes(6)) . '.' . $ext;

            // Upload directly to Supabase Storage bucket 'reports' via REST API
            $publicDocUrl = uploadToSupabaseStorage($fileItem['tmp_name'], $safeFilename, 'reports');
            if ($publicDocUrl !== false) {
                $savedFiles[] = $publicDocUrl;
            }
        }
    }

    $attachmentPathStr = !empty($savedFiles) ? implode(',', $savedFiles) : null;

    // 4. Execute PDO INSERT query into tenant_reports table
    // Ensure default status matches database constraint: 'Pending'
    $insStmt = $pdo->prepare("
        INSERT INTO tenant_reports 
            (tenant_id, unit_id, category, subject, details, attachment_path, status, created_at)
        VALUES 
            (:tenant_id, :unit_id, :category, :subject, :details, :attachment_path, 'Pending', NOW())
    ");
    $insStmt->execute([
        ':tenant_id'       => $tenantId,
        ':unit_id'         => $unitId,
        ':category'        => $category,
        ':subject'         => htmlspecialchars($specification, ENT_QUOTES, 'UTF-8'),
        ':details'         => htmlspecialchars($description, ENT_QUOTES, 'UTF-8'),
        ':attachment_path' => $attachmentPathStr,
    ]);

    $newReportId = (int)$pdo->lastInsertId();

    // 5. Notify Landlords
    try {
        require_once __DIR__ . '/includes/notification_service.php';
        $tUnitStmt = $pdo->prepare("SELECT unit_number FROM units WHERE id = :uid LIMIT 1");
        $tUnitStmt->execute([':uid' => $unitId]);
        $tUnitNum = $tUnitStmt->fetchColumn() ?: ('Unit ' . $unitId);
        $tenantName = $user['name'] ?? 'Tenant';
        $subDate = date('M j, Y');

        NotificationService::notifyLandlords(
            $pdo,
            'report',
            'New Tenant General Report',
            "Tenant: {$tenantName} • Unit: {$tUnitNum} • Category: {$category} • Subject: {$specification} • Submitted: {$subDate}",
            'sectionReports',
            $newReportId,
            'report_submitted',
            false
        );
    } catch (Throwable $e) {
        error_log('Report notification error: ' . $e->getMessage());
    }

    // 6. Return response (JSON if AJAX, or Redirect with success flag for tracker tab)
    if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) || (isset($_SERVER['HTTP_ACCEPT']) && str_contains($_SERVER['HTTP_ACCEPT'], 'application/json')) || isset($_POST['ajax'])) {
        header('Content-Type: application/json; charset=UTF-8');
        echo json_encode([
            'status'     => 'success',
            'success'    => true,
            'message'    => 'Report and Approval Request submitted successfully.',
            'report_id'  => $newReportId,
            'redirect'   => 'tenant_portal.php?section=tenantReport&tab=tracker&success=report_submitted'
        ]);
        exit;
    }

    header('Location: tenant_portal.php?section=tenantReport&tab=tracker&success=report_submitted');
    exit;
}

// Fetch tickets submitted by this tenant
$ticketsStmt = $pdo->prepare("SELECT * FROM maintenance_tickets WHERE tenant_id = :tid ORDER BY created_at DESC");
$ticketsStmt->execute([':tid' => $user['id']]);
$myTickets = $ticketsStmt->fetchAll();

foreach ($myTickets as &$t) {
    $st = strtolower((string)$t['status']);
    if ($st === 'open' || $st === 'pending') {
        $t['status'] = 'Pending';
    } elseif ($st === 'in_progress' || $st === 'on-going' || $st === 'ongoing') {
        $t['status'] = 'On-Going';
    } elseif ($st === 'resolved' || $st === 'closed' || $st === 'done') {
        $t['status'] = 'Done';
    } else {
        $t['status'] = 'Pending';
    }
}
unset($t);

// Fetch general reports submitted by this tenant (strictly separate from maintenance_tickets)
$reportsStmt = $pdo->prepare("
    SELECT tr.*, un.unit_number, rv.name AS reviewer_name
    FROM tenant_reports tr
    LEFT JOIN units un ON tr.unit_id = un.id
    LEFT JOIN users rv ON tr.reviewed_by = rv.id
    WHERE tr.tenant_id = ?
    ORDER BY tr.created_at DESC
");
$reportsStmt->execute([(int)$user['id']]);
$myReports = $reportsStmt->fetchAll();

// Fetch payments for this tenant
$paymentsStmt = $pdo->prepare("SELECT * FROM payments WHERE tenant_id = :tid ORDER BY id DESC");
$paymentsStmt->execute([':tid' => $user['id']]);
$myPayments = $paymentsStmt->fetchAll();
$currentPayment = !empty($myPayments) ? $myPayments[0] : null;

// Categorize payments into obligations and check rent status for unpaid balances
$depositBill = null;
$rentBill = null;
$hasUnpaidBalance = false;

if (empty($myPayments)) {
    $hasUnpaidBalance = true;
} else {
    foreach ($myPayments as $p) {
        if ($p['payment_type'] === 'Advance Deposit' && !$depositBill) {
            $depositBill = $p;
        } elseif ($p['payment_type'] === 'Rent' && !$rentBill) {
            $rentBill = $p;
        }
        $pStatus = strtolower((string)($p['status'] ?? ''));
        $amtDue = (float)($p['amount_due'] ?? 0.0);
        $amtPaid = (float)($p['amount_paid'] ?? 0.0);
        if (in_array($pStatus, ['unpaid', 'overdue', 'rejected', 'pending_setup'], true) || ($amtDue - $amtPaid) > 0.01) {
            $hasUnpaidBalance = true;
        }
    }
    if (!$depositBill || strtolower((string)($depositBill['status'] ?? '')) === 'unpaid') {
        $hasUnpaidBalance = true;
    }
    if (!$rentBill || strtolower((string)($rentBill['status'] ?? '')) === 'unpaid') {
        $hasUnpaidBalance = true;
    }
}

// Global Routing Security & Middleware:
// All tenant accounts are allowed full unrestricted GET navigation across all sidebar sections to view UI and records.
// Submission restrictions are strictly enforced on forms and POST API endpoints.

// Fetch Additional Rental Spaces (Space 1 & Space 2)
$spacesStmt = $pdo->prepare("
    SELECT s.*, u.name AS tenant_name, u.username AS tenant_username, un.unit_number
    FROM additional_spaces s
    LEFT JOIN users u ON s.assigned_tenant_id = u.id
    LEFT JOIN units un ON s.assigned_unit_id = un.id
    ORDER BY s.space_number ASC
");
$spacesStmt->execute();
$allSpaces = $spacesStmt->fetchAll();

// Determine spaces rented by current tenant vs rented by someone else
$myRentedSpaces = array_values(array_filter($allSpaces, fn($s) => (int)($s['assigned_tenant_id'] ?? 0) === (int)$user['id']));
$myRentedSpaceIds = array_map(fn($s) => (int)$s['id'], $myRentedSpaces);
$myRentedSpaceCount = count($myRentedSpaces);
$myAdditionalFee = array_reduce($myRentedSpaces, fn($carry, $s) => $carry + (float)$s['monthly_fee'], 0.00);
$myTotalMonthlyRent = (float)$unit['monthly_rent'] + $myAdditionalFee;
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>TaraRent – Apartment Management System</title>

  <!-- Global Favicon -->
  <link rel="icon" type="image/png" sizes="16x16" href="assets/images/favicon-16.png">
  <link rel="icon" type="image/png" sizes="32x32" href="assets/images/favicon-32.png">
  <link rel="icon" type="image/png" sizes="64x64" href="assets/images/favicon-64.png">
  <link rel="apple-touch-icon" sizes="192x192" href="assets/images/favicon-192.png">
  <link rel="shortcut icon" href="favicon.ico">
  <link rel="manifest" href="site.webmanifest">

  <!-- Typography -->
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">

  <!-- Claymorphism Design System, Aligned Layout Shell & Shared Sidebar Controller -->
  <link rel="stylesheet" href="assets/css/claymorphism.css?v=20261112">
  <link rel="stylesheet" href="assets/css/tenant_theme.css?v=20261112f">
  <script src="assets/js/sidebar.js?v=20261112b"></script>

  <!-- Dedicated OCR Library (Tesseract.js) -->
  <script src="https://cdn.jsdelivr.net/npm/tesseract.js@5/dist/tesseract.min.js"></script>

  <!-- face-api.js for 68-Point Facial Landmark Tracking (Local with CDN fallback) -->
  <script src="assets/js/face-api.min.js"></script>
  <script>
    if (typeof faceapi === 'undefined') {
      document.write('<script src="https://cdn.jsdelivr.net/npm/@vladmandic/face-api/dist/face-api.min.js"><\/script>');
    }
  </script>

  <!-- Tailwind CSS (with Preflight disabled to preserve portal claymorphic styling) -->
  <script src="https://cdn.tailwindcss.com"></script>
  <script>
    tailwind.config = {
      corePlugins: {
        preflight: false,
      }
    }
  </script>

  <style>
    /* =========================================================================
       TARARENT IDENTITY VERIFICATION MODAL & COMPONENTS
       ========================================================================= */
    .tararent-verification-modal-box {
      position: relative !important;
      width: 100% !important;
      max-width: 500px !important;
      background: #FCFBF9 !important;
      border: 1.5px solid rgba(86, 56, 14, 0.22) !important;
      border-radius: 20px !important;
      box-shadow: 0 16px 40px rgba(43, 33, 22, 0.22), 0 2px 8px rgba(86, 56, 14, 0.08) !important;
      padding: 26px 28px 24px !important;
      box-sizing: border-box !important;
      max-height: 92vh !important;
      overflow-y: auto !important;
      pointer-events: auto !important;
    }

    .tararent-modal-close-btn {
      position: absolute !important;
      top: 18px !important;
      right: 18px !important;
      width: 36px !important;
      height: 36px !important;
      display: inline-flex !important;
      align-items: center !important;
      justify-content: center !important;
      background: rgba(86, 56, 14, 0.07) !important;
      border: none !important;
      border-radius: 9999px !important;
      color: #56380E !important;
      cursor: pointer !important;
      transition: all 0.15s ease !important;
      z-index: 25 !important;
      outline: none !important;
    }
    .tararent-modal-close-btn:hover {
      background: rgba(86, 56, 14, 0.16) !important;
      color: #2B2116 !important;
      transform: scale(1.06) !important;
    }

    .tararent-id-upload-zone {
      border: 2px dashed #a89f91 !important;
      border-radius: 14px !important;
      background: rgba(255, 255, 255, 0.75) !important;
      padding: 24px 16px !important;
      display: flex;
      flex-direction: column !important;
      align-items: center !important;
      justify-content: center !important;
      text-align: center !important;
      cursor: pointer !important;
      transition: all 0.2s ease !important;
      box-shadow: inset 0 2px 4px rgba(0, 0, 0, 0.02) !important;
    }
    .tararent-id-upload-zone.hidden,
    .tararent-id-upload-zone[style*="display: none"],
    #idDropZone.hidden,
    #idDropZone[style*="display: none"] {
      display: none !important;
    }
    #idImagePreviewCard.hidden,
    #idImagePreviewCard[style*="display: none"] {
      display: none !important;
    }
    .tararent-id-upload-zone:hover,
    .tararent-id-upload-zone.drag-over {
      border-color: #56380E !important;
      background: #F5EFE6 !important;
      transform: translateY(-1px) !important;
    }
    .tararent-upload-icon-circle {
      width: 48px !important;
      height: 48px !important;
      border-radius: 9999px !important;
      background: rgba(86, 56, 14, 0.09) !important;
      display: flex !important;
      align-items: center !important;
      justify-content: center !important;
      color: #56380E !important;
      margin-bottom: 10px !important;
    }
    .tararent-upload-prompt {
      font-size: 13.5px !important;
      font-weight: 700 !important;
      color: #2B2116 !important;
      margin-bottom: 3px !important;
    }
    .tararent-upload-hint {
      font-size: 11.5px !important;
      color: #8C857B !important;
      font-weight: 600 !important;
    }

    .tararent-pill-submit-btn {
      width: 100% !important;
      background: #56380E !important;
      color: #ffffff !important;
      font-weight: 800 !important;
      font-size: 14px !important;
      padding: 13px 20px !important;
      border-radius: 12px !important;
      border: none !important;
      display: flex !important;
      align-items: center !important;
      justify-content: center !important;
      gap: 10px !important;
      cursor: pointer !important;
      box-shadow: 0 4px 14px rgba(86, 56, 14, 0.28) !important;
      transition: all 0.2s ease !important;
      outline: none !important;
    }
    .tararent-pill-submit-btn:hover:not(:disabled) {
      background: #432b0b !important;
      box-shadow: 0 6px 18px rgba(86, 56, 14, 0.35) !important;
      transform: translateY(-1px) !important;
    }
    .tararent-pill-submit-btn:active:not(:disabled) {
      transform: translateY(0) !important;
    }
    .tararent-pill-submit-btn:disabled {
      opacity: 0.75 !important;
      cursor: not-allowed !important;
    }

    /* Multi-step Verification Indicator */
    .verification-step-indicator {
      display: flex;
      align-items: center;
      gap: 10px;
      margin-bottom: 20px;
      padding: 6px;
      background: rgba(226, 232, 240, 0.6);
      border-radius: 14px;
    }
    .step-pill {
      flex: 1;
      padding: 8px 12px;
      border-radius: 10px;
      display: flex;
      align-items: center;
      justify-content: center;
      gap: 6px;
      font-size: 12px;
      font-weight: 800;
      background: transparent;
      color: var(--text-muted);
      transition: all 0.2s ease;
    }
    .step-pill.active {
      background: var(--clay-surface);
      color: var(--clay-primary-dark);
      box-shadow: var(--clay-button-shadow);
    }

    /* Live Face Scan Camera Mask, Oval Ring, and Status Pill */
    .face-oval-guide {
      position: absolute;
      inset: 0;
      display: flex;
      align-items: center;
      justify-content: center;
      pointer-events: none;
      z-index: 10;
    }
    .face-oval-ring {
      width: 175px;
      height: 235px;
      border: 3px solid rgba(255, 255, 255, 0.85);
      border-radius: 50%;
      box-shadow: 0 0 0 9999px rgba(9, 13, 22, 0.65);
      transition: border-color 0.25s ease, box-shadow 0.25s ease, transform 0.25s ease;
      background: transparent;
    }
    .face-oval-ring.detecting,
    .face-oval-ring.challenge {
      border-color: #38bdf8 !important;
      border-style: solid !important;
      box-shadow: 0 0 0 9999px rgba(9, 13, 22, 0.65), 0 0 20px rgba(56, 189, 248, 0.7) !important;
    }
    .face-oval-ring.perfect-capturing,
    .face-oval-ring.captured {
      border-color: #10b981 !important;
      border-style: solid !important;
      box-shadow: 0 0 0 9999px rgba(9, 13, 22, 0.6), 0 0 30px rgba(16, 185, 129, 0.9) !important;
      transform: scale(1.02);
    }
    .face-oval-ring.warning,
    .face-oval-ring.spoof {
      border-color: #f43f5e !important;
      border-style: dashed !important;
      box-shadow: 0 0 0 9999px rgba(9, 13, 22, 0.65), 0 0 18px rgba(244, 63, 94, 0.5) !important;
    }
    .face-status-pill {
      position: absolute;
      bottom: 12px;
      left: 16px;
      right: 16px;
      background: rgba(15, 23, 42, 0.9);
      backdrop-filter: blur(8px);
      -webkit-backdrop-filter: blur(8px);
      color: #ffffff;
      padding: 7px 14px;
      border-radius: 12px;
      font-size: 12px;
      font-weight: 700;
      text-align: center;
      display: flex;
      align-items: center;
      justify-content: center;
      gap: 8px;
      box-shadow: 0 4px 12px rgba(0, 0, 0, 0.3);
      z-index: 15;
      pointer-events: none;
    }
    .face-status-pill.status-holding {
      background: rgba(6, 95, 70, 0.92) !important;
      color: #ecfdf5 !important;
    }
    .face-status-pill.status-spoof {
      background: rgba(159, 18, 57, 0.92) !important;
      color: #fff1f2 !important;
    }
    @keyframes spin {
      to { transform: rotate(360deg); }
    }
    .modal-backdrop {
      display: none;
      position: fixed;
      inset: 0;
      background: rgba(15, 23, 42, 0.45);
      backdrop-filter: blur(4px);
      -webkit-backdrop-filter: blur(4px);
      z-index: 10000;
      align-items: center;
      justify-content: center;
      padding: 20px;
      pointer-events: none;
    }
    .modal-backdrop.open {
      display: flex !important;
      pointer-events: auto;
    }
    .clay-modal {
      width: 100%;
      max-width: 540px;
      background: var(--clay-card-bg);
      border-radius: var(--radius-xl);
      box-shadow: var(--clay-card-shadow-lg);
      border: 1px solid var(--clay-border);
      padding: 34px;
      animation: modalSlide 0.25s cubic-bezier(0.34, 1.56, 0.64, 1);
      max-height: 90vh;
      overflow-y: auto;
      pointer-events: auto;
    }
    @keyframes modalSlide {
      from { transform: translateY(20px) scale(0.96); opacity: 0; }
      to { transform: translateY(0) scale(1); opacity: 1; }
    }

    /* Inactive overlays must never block sidebar, settings, or dashboard buttons */
    .sidebar-overlay,
    .sidebar-overlay:not(.active) {
      display: none !important;
      position: fixed;
      inset: 0;
      background: rgba(15, 23, 42, 0.4);
      backdrop-filter: blur(3px);
      z-index: 998;
      pointer-events: none !important;
    }
    .sidebar-overlay.active {
      display: block !important;
      pointer-events: auto !important;
    }

    .modal-backdrop,
    .modal-backdrop:not(.open) {
      display: none !important;
      pointer-events: none !important;
    }
    .modal-backdrop.open {
      display: flex !important;
      pointer-events: auto !important;
    }

    /* Prevent clipping and ensure parent containers never cut off button hit areas or popovers */
    .clay-sidebar,
    #appSidebar,
    .sidebar-top-header,
    .sidebar-footer,
    .sidebar-settings-container,
    .header-settings-container {
      overflow: visible !important;
    }

    /* Interactive Settings & Privacy Action Item Styling */
    #settingsToggleBtn.hover\:bg-gray-200:hover,
    #settingsToggleBtn:hover {
      background-color: #e5e7eb !important;
      color: #2563eb !important;
      cursor: pointer !important;
    }
    #settingsToggleBtn:hover .fb-action-label,
    #settingsToggleBtn:hover .fb-action-arrow,
    #settingsToggleBtn:hover .fb-action-icon-circle {
      color: #2563eb !important;
    }
    #settingsToggleBtn:hover .fb-action-icon-circle {
      background-color: rgba(37, 99, 235, 0.12) !important;
    }

    /* Force Sidebar Toggle Button & Settings Button to Top Layer with High Z-Index */
    #sidebarToggleBtn,
    .sidebar-inner-toggle,
    .clay-toggle-btn {
      position: relative !important;
      z-index: 999 !important;
      pointer-events: auto !important;
      cursor: pointer !important;
    }

    #settingsToggleBtn,
    .sidebar-settings-container > button,
    .sidebar-settings-container .clay-btn,
    .header-settings-container > button,
    .header-settings-container .clay-btn {
      position: relative !important;
      z-index: 999 !important;
      pointer-events: auto !important;
      cursor: pointer !important;
    }

    /* Dropdown sits above buttons and remains interactive */
    .clay-settings-dropdown {
      z-index: 1050 !important;
      pointer-events: auto !important;
    }

    .settings-dropdown-item {
      position: relative;
      z-index: 1051 !important;
      pointer-events: auto !important;
      cursor: pointer !important;
    }

    /* Correct Z-Index Stacking: Main content buttons & clickable dashboard elements forced to top layer */
    .clay-btn,
    .priority-pill,
    .sidebar-nav-item,
    .profile-subnav-item,
    .btn-remove-file,
    .btn-inspect-proof,
    .main-content button,
    .main-content .clay-btn,
    .dashboard-header-bar button,
    .verification-dashboard-warning button,
    .clay-dropzone,
    .drop-zone {
      position: relative;
      z-index: 50;
    }

    /* Unverified Tenant Read-Only Banner & Locked Controls (Purple / Beige Theme) */
    .unverified-readonly-banner {
      margin-bottom: 24px;
      padding: 18px 22px;
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 16px;
      flex-wrap: wrap;
      border-radius: var(--radius-xl);
      border: 1.5px solid #d8b4fe;
      background: linear-gradient(135deg, #fbf7f0 0%, #f5f0ff 55%, #ede9fe 100%);
      box-shadow: 0 10px 24px -8px rgba(109, 40, 217, 0.14), inset 0 1px 0 rgba(255, 255, 255, 0.9);
    }
    .unverified-readonly-icon {
      width: 44px;
      height: 44px;
      border-radius: 14px;
      background: linear-gradient(135deg, #ede9fe, #f3e8d8);
      color: #6d28d9;
      display: flex;
      align-items: center;
      justify-content: center;
      font-size: 20px;
      flex-shrink: 0;
      border: 1px solid #d8b4fe;
      box-shadow: var(--clay-button-shadow);
    }
    .unverified-readonly-cta {
      padding: 10px 20px;
      font-size: 13px;
      font-weight: 800;
      background: linear-gradient(135deg, #7c3aed, #6d28d9);
      color: #ffffff;
      border: none;
      border-radius: 12px;
      box-shadow: 0 6px 14px rgba(109, 40, 217, 0.28);
      cursor: pointer;
      transition: transform 0.16s ease, box-shadow 0.16s ease;
    }
    .unverified-readonly-cta:hover {
      transform: translateY(-1px);
      box-shadow: 0 8px 18px rgba(109, 40, 217, 0.36);
    }
    .unverified-inline-strip {
      margin-top: 14px;
      margin-bottom: 14px;
      padding: 12px 16px;
      border-radius: 14px;
      background: #FFFDF9;
      border: 1.5px solid #D9C3A5;
      box-shadow: 2px 3px 8px rgba(74, 48, 16, 0.08);
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 12px;
      flex-wrap: wrap;
    }
    .clay-btn-locked {
      background: #e2e8f0 !important;
      color: #475569 !important;
      border: 1.5px dashed #94a3b8 !important;
      box-shadow: none !important;
      opacity: 0.78 !important;
      cursor: not-allowed !important;
    }
    .clay-btn-locked:hover {
      transform: none !important;
      box-shadow: none !important;
    }
    .readonly-dropzone-locked {
      opacity: 0.65;
      cursor: not-allowed !important;
      background: linear-gradient(135deg, #f8fafc, #f5f0ff) !important;
      border-color: #cbd5e1 !important;
    }
    @keyframes verifyCardPulse {
      0%, 100% { box-shadow: 0 0 0 0 rgba(124, 58, 237, 0); }
      50% { box-shadow: 0 0 0 6px rgba(124, 58, 237, 0.25); }
    }
    .verify-highlight-pulse {
      animation: verifyCardPulse 1.1s ease 2;
      border-radius: var(--radius-lg);
    }
  </style>
  <script src="https://unpkg.com/lucide@0.469.0/dist/umd/lucide.min.js"></script>
  <script src="assets/js/lucide_init.js?v=20261112"></script>
</head>
<body class="tenant-portal-shell">

  <?php
  // Shared RBAC-Protected Sidebar Component (Tenant Context Only)
  $sidebarConfig = [
      'portal'              => 'tenant',
      'has_unpaid_balance'  => $hasUnpaidBalance,
      'is_verified'         => $isTenantVerified,
      'verification_status' => (string)($user['verification_status'] ?? 'unverified'),
      'is_moved_out_unpaid' => $isMovedOutTenant,
      'tenant_status_mode'  => $tenantStatus['mode'] ?? 'active_verified',
  ];
  require __DIR__ . '/includes/sidebar.php';
  ?>

    <!-- ===================================================================
         MAIN CONTENT AREA
         =================================================================== -->
    <main class="main-content">

      <!-- =================================================================
           STICKY TOP NAVIGATION BAR (Mobile Trigger + Quick Search + Bell + Resident Profile)
           ================================================================= -->
      <header class="top-nav-bar">
        <div class="top-nav-left-slot">
          <button type="button" class="mobile-menu-trigger" onclick="toggleSidebar()" aria-label="Open Navigation Menu" title="Open Menu">
            <i data-lucide="menu" class="w-5 h-5 pointer-events-none" style="width: 20px; height: 20px; pointer-events: none;" aria-hidden="true"></i>
          </button>
          <span class="mobile-brand-chip">TaraRent</span>
        </div>

        <!-- Centered Tenant Quick Search Bar -->
        <div class="top-nav-search-wrap">
          <div class="top-search-box">
            <span class="top-search-icon" aria-hidden="true">
              <i data-lucide="search" class="w-4 h-4" style="width: 16px; height: 16px;" aria-hidden="true"></i>
            </span>
            <input type="search"
                   id="tenantGlobalSearchInput"
                   autocomplete="off"
                   placeholder="Search maintenance logs, receipts, or sections..."
                   aria-label="Search tenant portal">
            <button type="button"
                    id="tenantGlobalSearchClear"
                    class="top-search-clear-btn"
                    onclick="clearGlobalTenantSearch()"
                    aria-label="Clear search"
                    title="Clear search"><i data-lucide="x" class="w-3.5 h-3.5" aria-hidden="true"></i></button>
          </div>
          <div id="tenantSearchDropdown" class="global-search-dropdown" role="listbox" aria-label="Search Results"></div>
        </div>

        <!-- Upper Right: Notifications + Resident Profile Trigger with Dropdown Menu -->
        <div class="top-nav-right">
          <!-- Notification Bell Trigger and Dropdown Panel -->
          <div class="header-notification-container" style="position: relative;">
            <button type="button" class="header-notification-btn" id="headerNotificationBtn" onclick="toggleNotificationPanel(event)" title="Notifications" aria-label="Notifications">
              <i data-lucide="bell" class="w-5 h-5" aria-hidden="true"></i>
              <span class="notification-badge" id="notificationBadge" style="display: none;">0</span>
            </button>

            <!-- Notification Dropdown Panel: Glassmorphism Design -->
            <div class="clay-settings-dropdown notification-dropdown-panel" id="notificationDropdown">
              <div class="notification-panel-header">
                <div class="notification-title-group" style="display: flex; align-items: center; gap: 8px;">
                  <h3 class="notification-heading">Notifications</h3>
                  <span class="notification-counter-pill" id="notificationUnreadPill" style="display: none;">0 new</span>
                </div>
                <button type="button" class="notification-mark-all-btn" id="btnMarkAllRead" onclick="markAllNotificationsAsRead(event)">
                  Mark all as read
                </button>
              </div>

              <!-- Notification items container -->
              <div class="notification-items-scroll" id="notificationListContainer">
                <div class="notification-loading-state" id="notificationLoading" style="padding: 24px; text-align: center; color: #64748b; font-size: 13px;">
                  <span>Loading notifications...</span>
                </div>
              </div>
            </div>
          </div>

          <!-- Resident Profile Trigger (Avatar/Initial + Resident Name Beside Profile) -->
          <div class="sidebar-settings-container header-settings-container" style="position: relative;">
            <div class="header-resident-trigger" id="headerProfileTrigger" onclick="toggleSettingsMenu(event)" onkeydown="if(event.key==='Enter'||event.key===' '){event.preventDefault();toggleSettingsMenu(event);}" role="button" tabindex="0" title="Account &amp; Settings" aria-label="Resident Account Menu">
              <div class="header-avatar-badge" id="headerAvatarBadge">
                <?php if (!empty($userAvatarUrl)): ?>
                  <img src="<?= $userAvatarUrl ?>" alt="<?= htmlspecialchars($user['name'] ?? 'Tenant') ?>" class="header-avatar-img">
                <?php else: ?>
                  <span class="header-avatar-initial"><?= htmlspecialchars($userInitial) ?></span>
                <?php endif; ?>
              </div>
              <div class="header-resident-meta">
                <span class="header-resident-name" id="headerUserName"><?= htmlspecialchars($user['name'] ?? 'Resident') ?></span>
                <span class="header-resident-role" style="display: inline-flex; align-items: center; gap: 4px;"><i data-lucide="circle-user" class="w-3.5 h-3.5" aria-hidden="true"></i> Resident</span>
              </div>
            </div>

            <!-- Profile Dropdown: Clean Glassmorphism Panel -->
            <div class="clay-settings-dropdown fb-profile-dropdown" id="settingsDropdown">
              <!-- Top Profile Information Card (Display-Only) -->
              <div class="fb-profile-card">
                <!-- Main Profile Display: Avatar/Initial & Full Name (Clickable to view Profile) -->
                <div class="fb-profile-main-row" onclick="openProfileView('accountInfo')" style="cursor: pointer;" title="View Account &amp; Profile">
                  <div class="fb-profile-avatar-circle" id="profileDropdownAvatarCircle">
                    <?php if (!empty($userAvatarUrl)): ?>
                      <img src="<?= $userAvatarUrl ?>" alt="<?= htmlspecialchars($user['name'] ?? 'Tenant') ?>" id="profileDropdownAvatarImg">
                    <?php else: ?>
                      <span id="profileDropdownAvatarInitial"><?= htmlspecialchars($userInitial) ?></span>
                    <?php endif; ?>
                  </div>
                  <div class="fb-profile-main-info">
                    <span class="fb-profile-name" id="profileDropdownName"><?= htmlspecialchars($user['name'] ?? 'Tenant') ?></span>
                  </div>
                </div>
              </div>

              <!-- Options Directly Below Profile Information: Visually Separated -->
              <div class="fb-actions-group">
                <?php if (!$isMovedOutTenant): ?>
                <!-- Settings & Privacy Option (Positioned Directly Below Profile Information) -->
                <a href="settings.php" class="fb-dropdown-action-item cursor-pointer hover:bg-gray-200 hover:text-blue-600 transition-colors duration-150" id="settingsToggleBtn" onclick="openProfileView('accountInfo'); return false;" role="button" title="Settings &amp; privacy">
                  <div class="fb-action-icon-circle">
                    <i data-lucide="settings" class="w-5 h-5 text-gray-700" aria-hidden="true"></i>
                  </div>
                  <span class="fb-action-label">Settings &amp; privacy</span>
                  <span class="fb-action-arrow">
                    <i data-lucide="chevron-right" class="w-4 h-4" aria-hidden="true"></i>
                  </span>
                </a>
                <?php endif; ?>

                <!-- Sign Out Option -->
                <a href="logout.php" class="fb-dropdown-action-item fb-action-item-danger" title="Sign Out">
                  <div class="fb-action-icon-circle">
                    <i data-lucide="log-out" class="w-5 h-5" aria-hidden="true"></i>
                  </div>
                  <span class="fb-action-label">Sign Out</span>
                  <span class="fb-action-arrow">
                    <i data-lucide="chevron-right" class="w-4 h-4" aria-hidden="true"></i>
                  </span>
                </a>
              </div>
            </div>
          </div>
        </div>
      </header>

      <!-- Uniform Content Wrapper Shared Across All Tenant Portal Modules -->
      <div class="tenant-content-container">

      <!-- CATEGORY 1: DASHBOARD OVERVIEW -->
      <section id="tenantOverview" class="app-view-section active-section">
        <!-- Dashboard Verification Notification Banner -->
        <div id="dashboardVerificationAlert" class="dashboard-verification-alert" style="display: none; margin: 0 0 24px 0; opacity: 1; transition: opacity 0.5s ease, transform 0.5s ease;"></div>

        <div class="dashboard-header-bar flex flex-col sm:flex-row sm:items-center justify-between gap-4 mb-6">
          <div class="dashboard-title-group">
            <h1 class="text-2xl md:text-3xl font-black text-[#3E2723] tracking-wide mb-1">Welcome Home, <?= htmlspecialchars(explode(' ', $user['name'])[0]) ?>!</h1>
            <p class="text-sm md:text-base font-semibold text-[#7A6245] m-0">Here is your residency overview for <?= htmlspecialchars($unit['unit_number']) ?>.</p>
          </div>
          <div class="px-4 py-2 rounded-xl text-xs md:text-sm font-black <?= ($currentPayment && $currentPayment['status'] === 'pending_setup') ? 'bg-amber-100 text-amber-900 border border-amber-300' : 'bg-[#4A3728] text-white border border-[#382618]' ?> shadow-sm">
            <?php if ($currentPayment && $currentPayment['status'] === 'pending_setup'): ?>
              <span class="inline-flex items-center gap-1.5"><i data-lucide="clock" class="w-4 h-4" aria-hidden="true"></i> Rent Setup in Progress</span>
            <?php else: ?>
              Monthly Rent: ₱<?= number_format((float)$unit['monthly_rent'], 2) ?>
            <?php endif; ?>
          </div>
        </div>

        <!-- Read-Only Access Notice Banner for Unverified / Pending Tenant Accounts -->
        <?php if (!$isTenantVerified): ?>
        <?php
          $rawVStatus = strtolower(trim((string)($user['verification_status'] ?? 'unverified')));
          $isPendingVerification = in_array($rawVStatus, ['pending', 'pending_verification', 'pending_liveness'], true);
          $isRejectedVerification = ($rawVStatus === 'rejected');
        ?>
        <div class="unverified-readonly-banner <?= $isPendingVerification ? 'is-pending verification-dashboard-pending' : 'verification-dashboard-warning' ?>" 
             id="readonlyVerificationBanner"
             style="<?= $isPendingVerification ? 'background: linear-gradient(135deg, #fffbeb, #fef3c7); border: 1.5px solid #f59e0b; color: #92400e; box-shadow: 0 4px 12px rgba(180, 83, 9, 0.15);' : 'background: #FCFBF9; border: 1.5px solid #b91c1c; color: #b91c1c; box-shadow: 3px 4px 10px rgba(42, 24, 6, 0.22);' ?>">
          <div style="display: flex; align-items: center; gap: 14px; flex: 1; min-width: 240px;">
            <div class="unverified-readonly-icon" 
                 id="readonlyVerificationIconBox"
                 style="<?= $isPendingVerification ? 'color: #b45309; background: #fef3c7; border: 1px solid rgba(180, 83, 9, 0.35);' : 'color: #b91c1c; background: linear-gradient(135deg, #fee2e2, #fecaca); border: 1px solid #fca5a5;' ?>">
              <i data-lucide="<?= $isPendingVerification ? 'clock' : 'alert-triangle' ?>" class="w-5 h-5" aria-hidden="true"></i>
            </div>
            <div>
              <div style="display: flex; align-items: center; gap: 8px; flex-wrap: wrap; margin-bottom: 4px;">
                <h4 class="unverified-banner-title" 
                    id="readonlyVerificationTitle"
                    style="<?= $isPendingVerification ? 'color: #92400e;' : 'color: #b91c1c;' ?>">
                  <?= $isPendingVerification ? 'Verification Pending Review' : 'Account Verification Required — Read-Only Access' ?>
                </h4>
                <?php if ($isPendingVerification): ?>
                  <span class="clay-badge unverified-banner-badge is-pending" id="readonlyVerificationBadge" style="display: inline-flex; align-items: center; gap: 4px; background: #fef3c7; color: #92400e; border: 1px solid rgba(180, 83, 9, 0.3);">
                    <i data-lucide="clock" class="w-3.5 h-3.5" aria-hidden="true"></i> Pending Review
                  </span>
                <?php elseif ($isRejectedVerification): ?>
                  <span class="clay-badge unverified-banner-badge" id="readonlyVerificationBadge" style="display: inline-flex; align-items: center; gap: 4px; background: #fee2e2; color: #991b1b; border: 1px solid #f87171;">
                    <i data-lucide="x" class="w-3.5 h-3.5" aria-hidden="true"></i> Needs Re-Verification
                  </span>
                <?php else: ?>
                  <span class="clay-badge unverified-banner-badge" id="readonlyVerificationBadge">Read-Only Mode</span>
                <?php endif; ?>
              </div>
              <p class="unverified-banner-desc" 
                 id="readonlyVerificationDesc"
                 style="<?= $isPendingVerification ? 'color: #78350f;' : 'color: #b91c1c;' ?>">
                <?= $isPendingVerification 
                  ? 'Your identity document has been submitted and is currently pending review by the property owner. You will receive full access once approved.' 
                  : 'Your account is not yet verified. You can view your account information and history, but maintenance requests, payments, and reports are locked until identity verification is complete.' ?>
              </p>
              <?php if ($isPendingVerification && !empty($user['id_type'])): ?>
                <p class="unverified-banner-note" id="readonlyVerificationNote" style="color: #92400e;">
                  Uploaded ID: <strong><?= htmlspecialchars($user['id_type']) ?></strong> is awaiting owner approval.
                </p>
              <?php endif; ?>
            </div>
          </div>
          <?php if (!$isPendingVerification): ?>
          <button type="button" class="unverified-readonly-cta" id="btnVerifyAccountCta" onclick="openVerifyAccountModal()">
            <span>Verify Account →</span>
          </button>
          <?php endif; ?>
        </div>
        <?php endif; ?>

        <!-- KPI Grid (30% Secondary Medium Clay Border, 60% Dominant White/Tan Body, 10% Accent Dark Roast Values & Icons) -->
        <div class="grid grid-cols-1 md:grid-cols-3 gap-5 mb-8">
          <!-- Active Rent Status -->
          <div class="bg-white/95 border-2 border-[#B89B72] rounded-2xl p-5 sm:p-6 shadow-md hover:shadow-lg transition-all flex flex-col justify-between min-h-[160px]">
            <div class="flex items-center justify-between gap-3 mb-2">
              <span class="text-xs font-black text-[#7A6245] uppercase tracking-wider">Current Rent Status</span>
              <div class="w-10 h-10 rounded-xl bg-[#4A3728]/10 text-[#4A3728] border border-[#8C6D43]/30 flex items-center justify-center flex-shrink-0">
                <i data-lucide="banknote" class="w-5 h-5" aria-hidden="true"></i>
              </div>
            </div>
            <?php
              $rentStatusRaw = $currentPayment['status'] ?? 'paid';
              $rentStatusSlug = preg_replace('/[^a-z0-9_-]/', '', strtolower((string)$rentStatusRaw));
            ?>
            <div class="text-2xl sm:text-3xl font-black text-[#3E2723] my-2">
              <?php if ($currentPayment && $currentPayment['status'] === 'pending_setup'): ?>
                <span class="text-amber-800 text-xl sm:text-2xl">Pending Setup</span>
              <?php else: ?>
                <?= $currentPayment ? strtoupper(htmlspecialchars((string)$currentPayment['status'])) : 'PAID' ?>
              <?php endif; ?>
            </div>
            <div class="text-xs sm:text-sm font-semibold text-[#7A6245]">
              <?php if ($currentPayment && $currentPayment['status'] === 'pending_setup'): ?>
                <span class="text-amber-800 font-semibold">Your rent details are being configured by the Owner.</span>
              <?php else: ?>
                <span>₱<?= number_format((float)($currentPayment['amount_due'] ?? $unit['monthly_rent']), 2) ?> <?= !empty($currentPayment['due_date']) ? 'Due on ' . date('M j', strtotime($currentPayment['due_date'])) : 'Due on 5th' ?></span>
              <?php endif; ?>
            </div>
          </div>

          <!-- Active Maintenance Tickets -->
          <div class="bg-white/95 border-2 border-[#B89B72] rounded-2xl p-5 sm:p-6 shadow-md hover:shadow-lg transition-all flex flex-col justify-between min-h-[160px]">
            <div class="flex items-center justify-between gap-3 mb-2">
              <span class="text-xs font-black text-[#7A6245] uppercase tracking-wider">Active Maintenance</span>
              <div class="w-10 h-10 rounded-xl bg-[#4A3728]/10 text-[#4A3728] border border-[#8C6D43]/30 flex items-center justify-center flex-shrink-0">
                <i data-lucide="wrench" class="w-5 h-5" aria-hidden="true"></i>
              </div>
            </div>
            <div class="text-2xl sm:text-3xl font-black text-[#3E2723] my-2">
              <?= count(array_filter($myTickets, fn($t) => in_array($t['status'], ['Pending', 'On-Going', 'open', 'in_progress']))) ?>
            </div>
            <div class="text-xs sm:text-sm font-semibold text-[#7A6245]">
              <span>Dispatched to Landlord</span>
            </div>
          </div>

          <!-- Total Resolved -->
          <div class="bg-white/95 border-2 border-[#B89B72] rounded-2xl p-5 sm:p-6 shadow-md hover:shadow-lg transition-all flex flex-col justify-between min-h-[160px]">
            <div class="flex items-center justify-between gap-3 mb-2">
              <span class="text-xs font-black text-[#7A6245] uppercase tracking-wider">Resolved Repairs</span>
              <div class="w-10 h-10 rounded-xl bg-[#4A3728]/10 text-[#4A3728] border border-[#8C6D43]/30 flex items-center justify-center flex-shrink-0">
                <i data-lucide="check-circle-2" class="w-5 h-5" aria-hidden="true"></i>
              </div>
            </div>
            <div class="text-2xl sm:text-3xl font-black text-[#3E2723] my-2">
              <?= count(array_filter($myTickets, fn($t) => in_array($t['status'], ['Done', 'resolved', 'closed']))) ?>
            </div>
            <div class="text-xs sm:text-sm font-semibold text-[#7A6245]">
              <button type="button" class="font-black text-[#4A3728] hover:text-[#382618] underline transition-colors cursor-pointer" onclick="switchTenantSection('tenantMaintenance', document.getElementById('navTenantMaintenance'))">
                View history →
              </button>
            </div>
          </div>
        </div>

        <!-- Recent Activity Cards (30% Secondary Medium Clay Borders, 60% Dominant Tan Rows, 10% Accent Badges & CTAs) -->
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
          <div class="bg-white/95 border-2 border-[#B89B72] rounded-2xl p-6 shadow-md flex flex-col justify-start">
            <div class="flex items-center justify-between gap-4 pb-4 border-b border-dashed border-[#8C6D43]/30 mb-4">
              <div>
                <h3 class="text-lg font-black text-[#3E2723] uppercase tracking-wide m-0">Recent Maintenance Logs</h3>
                <p class="text-xs font-semibold text-[#7A6245] mt-1 mb-0">Recent repair requests for <?= htmlspecialchars($unit['unit_number']) ?></p>
              </div>
              <button type="button" class="px-3.5 py-1.5 rounded-lg text-xs font-bold bg-[#F5F0E6] text-[#3E2723] border border-[#8C6D43] hover:bg-[#E8D8B0] shadow-sm transition-colors cursor-pointer" onclick="switchTenantSection('tenantMaintenance', document.getElementById('navTenantMaintenance'))">
                View All
              </button>
            </div>

            <div class="flex-1 flex flex-col gap-2.5">
              <?php if (empty($myTickets)): ?>
                <div class="flex items-center justify-center p-8 rounded-xl bg-[#F5F0E6] border border-dashed border-[#8C6D43] text-[#7A6245] text-xs font-bold text-center">No maintenance requests logged yet.</div>
              <?php else: ?>
                <?php foreach (array_slice($myTickets, 0, 3) as $tk): 
                  $tkStatus = $tk['status'] ?? 'Pending';
                  $stLower = strtolower((string)$tkStatus);
                  $statusBadgeClass = 'bg-amber-100 text-amber-900 border-amber-300';
                  if (in_array($stLower, ['on-going', 'ongoing', 'assigned', 'in_progress'], true)) {
                    $statusBadgeClass = 'bg-blue-100 text-blue-900 border-blue-300';
                  } elseif (in_array($stLower, ['done', 'resolved', 'closed'], true)) {
                    $statusBadgeClass = 'bg-green-100 text-green-900 border-green-300';
                  }
                ?>
                <div class="flex items-center justify-between p-3.5 rounded-xl bg-[#F5F0E6] border border-[#8C6D43]/40 shadow-sm text-[#3E2723] hover:shadow-md transition-all cursor-pointer"
                     onclick="openTenantTicketModal(<?= htmlspecialchars(json_encode($tk), ENT_QUOTES, 'UTF-8') ?>)"
                     title="Click to view ticket details and owner response">
                  <div>
                    <div class="flex items-center gap-2 mb-1">
                      <span class="bg-[#E8D8B0] text-[#3E2723] font-black text-[11px] px-2 py-0.5 rounded-md border border-[#8C6D43]/30">#MT-<?= str_pad((string)$tk['id'], 4, '0', STR_PAD_LEFT) ?></span>
                      <h4 class="text-sm font-bold text-[#3E2723] m-0"><?= htmlspecialchars($tk['title']) ?></h4>
                    </div>
                    <div class="flex items-center gap-2 text-xs text-[#7A6245] font-semibold">
                      <span><?= htmlspecialchars($tk['category'] ?? 'General') ?></span>
                      <span>•</span>
                      <span><?= date('M j, Y', strtotime($tk['created_at'])) ?></span>
                    </div>
                  </div>
                  <span class="<?= $statusBadgeClass ?> border font-extrabold text-xs px-2.5 py-1 rounded-full shadow-sm"><?= htmlspecialchars($tkStatus) ?></span>
                </div>
                <?php endforeach; ?>
              <?php endif; ?>
            </div>
          </div>

          <div class="bg-white/95 border-2 border-[#B89B72] rounded-2xl p-6 shadow-md flex flex-col justify-start">
            <div class="flex items-center justify-between gap-4 pb-4 border-b border-dashed border-[#8C6D43]/30 mb-4">
              <div>
                <h3 class="text-lg font-black text-[#3E2723] uppercase tracking-wide m-0">Payment Receipts</h3>
                <p class="text-xs font-semibold text-[#7A6245] mt-1 mb-0">Verified receipts on file</p>
              </div>
              <button type="button" class="px-3.5 py-1.5 rounded-lg text-xs font-bold bg-[#4A3728] hover:bg-[#382618] text-white border border-[#382618] shadow-sm transition-colors cursor-pointer" onclick="switchTenantSection('tenantPayments', document.getElementById('navTenantPayments'))">
                Upload New
              </button>
            </div>

            <div class="flex-1 flex flex-col gap-2.5">
              <?php if (empty($myPayments)): ?>
                <div class="flex items-center justify-center p-8 rounded-xl bg-[#F5F0E6] border border-dashed border-[#8C6D43] text-[#7A6245] text-xs font-bold text-center">No payments recorded yet.</div>
              <?php else: ?>
                <?php foreach (array_slice($myPayments, 0, 3) as $pay): 
                  $payStatus = strtolower((string)($pay['status'] ?? 'pending'));
                  $payBadgeClass = 'bg-amber-100 text-amber-900 border-amber-300';
                  if (in_array($payStatus, ['approved', 'paid', 'verified'], true)) {
                    $payBadgeClass = 'bg-green-100 text-green-900 border-green-300';
                  } elseif (in_array($payStatus, ['rejected', 'declined'], true)) {
                    $payBadgeClass = 'bg-red-100 text-red-900 border-red-300';
                  }
                ?>
                <div class="flex items-center justify-between p-3.5 rounded-xl bg-[#F5F0E6] border border-[#8C6D43]/40 shadow-sm text-[#3E2723]">
                  <div>
                    <div class="text-sm font-bold text-[#3E2723]"><?= !empty($pay['billing_cycle']) ? date('F Y', strtotime($pay['billing_cycle'])) : 'Pending Setup' ?></div>
                    <div class="text-xs font-semibold text-[#7A6245] mt-0.5">
                      <?php if ($pay['status'] === 'pending_setup'): ?>
                        Rent setup pending with landlord
                      <?php else: ?>
                        ₱<?= number_format((float)$pay['amount_paid'], 2) ?> on <?= $pay['payment_date'] ?? 'Pending' ?>
                      <?php endif; ?>
                    </div>
                  </div>
                  <?php if ($pay['status'] === 'pending_setup'): ?>
                    <span class="bg-amber-100 text-amber-900 border border-amber-300 font-bold text-[10px] px-2 py-0.5 rounded-full">PENDING SETUP</span>
                  <?php else: ?>
                    <span class="<?= $payBadgeClass ?> border font-extrabold text-xs px-2.5 py-1 rounded-full shadow-sm"><?= strtoupper($pay['status']) ?></span>
                  <?php endif; ?>
                </div>
                <?php endforeach; ?>
              <?php endif; ?>
            </div>
          </div>
        </div>

      </section>

      <!-- CATEGORY 2: LOG MAINTENANCE -->
      <section id="tenantMaintenance" class="app-view-section">
        <div class="max-w-4xl mx-auto border-2 border-[#8C6D43] rounded-2xl shadow-xl overflow-hidden bg-[#B89B72]" id="maintenanceFolderShell">
          <div class="flex items-stretch bg-[#F5F0E6] relative z-0" role="tablist" aria-label="Maintenance Views">
            <button type="button"
                    class="flex-1 py-3.5 px-6 font-black text-sm md:text-base tracking-wide flex items-center justify-center gap-2 transition-all bg-[#B89B72] text-white rounded-tl-xl border-b-2 border-b-[#B89B72] z-10 cursor-pointer active"
                    id="btn-submit"
                    role="tab"
                    aria-selected="true"
                    aria-controls="submit-section"
                    onclick="toggleMaintenanceTabs('submit')">
              <i data-lucide="wrench" class="w-4 h-4"></i>
              <span>Submit Request</span>
            </button>
            <button type="button"
                    class="flex-1 py-3.5 px-6 font-bold text-sm md:text-base tracking-wide flex items-center justify-center gap-2 transition-all bg-[#F5F0E6] text-[#3E2723] rounded-tr-xl border-l border-b-2 border-[#8C6D43] hover:bg-[#E8D8B0] cursor-pointer"
                    id="btn-track"
                    role="tab"
                    aria-selected="false"
                    aria-controls="track-section"
                    onclick="toggleMaintenanceTabs('track')">
              <i data-lucide="list-checks" class="w-4 h-4"></i>
              <span>Track Requests</span>
              <span id="tenantMaintenanceCountBadge" class="ml-1.5 px-2 py-0.5 rounded-full text-xs font-black bg-[#E8D8B0] text-[#3E2723] border border-[#B89B72]"><?= count($myTickets) ?></span>
            </button>
          </div>

          <div class="p-6 md:p-8 bg-[#B89B72]" id="maintenanceFolderBody">
            <!-- 1. SUBMIT SECTION -->
            <div id="submit-section" class="w-full">
              <h2 class="text-2xl md:text-3xl font-black text-white mb-6 tracking-wide text-center sm:text-left">Submit Maintenance Request</h2>

              <?php if (!$isTenantVerified): ?>
              <div class="mb-5 p-4 rounded-xl border border-amber-400 bg-amber-100 text-amber-950 flex items-center justify-between gap-3 shadow-md" role="alert">
                <div class="flex items-center gap-3">
                  <div class="p-2 rounded-lg bg-amber-200 text-amber-800 flex-shrink-0">
                    <i data-lucide="eye" class="w-5 h-5" aria-hidden="true"></i>
                  </div>
                  <div>
                    <p class="font-bold text-sm sm:text-base m-0">Viewing Mode: Please verify your identity to submit new records.</p>
                    <p class="text-xs text-amber-900 mt-0.5 mb-0">You can explore existing repair requests freely. Complete verification to unlock submission.</p>
                  </div>
                </div>
                <button type="button" onclick="openModal('verifyAccountModal')" class="flex-shrink-0 px-3.5 py-1.5 rounded-lg text-xs font-bold bg-[#4A3728] hover:bg-[#382618] text-white shadow-sm transition-colors cursor-pointer">
                  Verify Now
                </button>
              </div>
              <?php endif; ?>

              <form id="maintenanceForm" class="maintenance-request-form">
                <div class="maintenance-form-grid">
                  <!-- Left Column: Issue, Repair Category, Urgency Level -->
                  <div class="maintenance-form-left">
                    <div class="maintenance-field-group">
                      <label for="ticketTitle" class="maintenance-field-label">Issue:</label>
                      <input type="text" id="ticketTitle" class="w-full h-11 px-3.5 py-2.5 rounded-lg bg-[#F5F0E6] text-[#3E2723] font-semibold text-sm border border-[#8C6D43]/60 focus:outline-none focus:ring-2 focus:ring-[#4A3728] shadow-sm <?= !$isTenantVerified ? 'opacity-60 cursor-not-allowed bg-gray-200' : '' ?>" placeholder="e.g. Bathroom sink pipe dripping" required <?= !$isTenantVerified ? 'disabled' : '' ?>>
                    </div>

                    <div class="maintenance-meta-row">
                      <div class="maintenance-field-group">
                        <label for="ticketCategoryTrigger" class="maintenance-field-label">Repair Category:</label>
                        <div class="maintenance-select-wrap" id="ticketCategoryWrap">
                          <select id="ticketCategory" class="maintenance-native-select-hidden" tabindex="-1" aria-hidden="true" <?= !$isTenantVerified ? 'disabled' : '' ?>>
                            <option value="" disabled selected hidden></option>
                            <option value="Structural">Structural &amp; Masonry</option>
                            <option value="Plumbing">Plumbing &amp; Water System</option>
                            <option value="Electrical">Electrical &amp; Lighting</option>
                            <option value="Carpentry">Carpentry &amp; Fixtures</option>
                            <option value="Sanitation">Pest Control &amp; Sanitation</option>
                            <option value="General">Others...</option>
                          </select>
                          <button type="button"
                                  id="ticketCategoryTrigger"
                                  class="w-full h-11 px-3.5 py-2.5 rounded-lg bg-[#F5F0E6] text-[#3E2723] font-semibold text-sm border border-[#8C6D43]/60 focus:outline-none focus:ring-2 focus:ring-[#4A3728] shadow-sm flex items-center justify-between <?= !$isTenantVerified ? 'opacity-60 cursor-not-allowed pointer-events-none' : '' ?>"
                                  aria-haspopup="listbox"
                                  aria-expanded="false"
                                  aria-controls="ticketCategoryMenu"
                                  <?= !$isTenantVerified ? 'disabled' : '' ?>>
                            <span class="maintenance-select-value" id="ticketCategoryDisplay"></span>
                          </button>
                          <div class="maintenance-select-menu" id="ticketCategoryMenu" role="listbox" aria-label="Repair Category">
                            <button type="button" class="maintenance-select-option" role="option" data-value="Structural">Structural &amp; Masonry</button>
                            <button type="button" class="maintenance-select-option" role="option" data-value="Plumbing">Plumbing &amp; Water System</button>
                            <button type="button" class="maintenance-select-option" role="option" data-value="Electrical">Electrical &amp; Lighting</button>
                            <button type="button" class="maintenance-select-option" role="option" data-value="Carpentry">Carpentry &amp; Fixtures</button>
                            <button type="button" class="maintenance-select-option" role="option" data-value="Sanitation">Pest Control &amp; Sanitation</button>
                            <button type="button" class="maintenance-select-option" role="option" data-value="General">Others...</button>
                          </div>
                        </div>
                      </div>

                      <div class="maintenance-field-group">
                        <span class="maintenance-field-label" id="urgencyLevelLabel">Urgency Level:</span>
                        <div class="maintenance-urgency-list" role="radiogroup" aria-labelledby="urgencyLevelLabel">
                          <label class="maintenance-radio-option">
                            <input type="radio" name="ticketPriority" value="urgent" checked <?= !$isTenantVerified ? 'disabled' : '' ?>>
                            <span class="maintenance-radio-circle" aria-hidden="true"></span>
                            <span class="maintenance-radio-text">Very High</span>
                          </label>
                          <label class="maintenance-radio-option">
                            <input type="radio" name="ticketPriority" value="high" <?= !$isTenantVerified ? 'disabled' : '' ?>>
                            <span class="maintenance-radio-circle" aria-hidden="true"></span>
                            <span class="maintenance-radio-text">High</span>
                          </label>
                          <label class="maintenance-radio-option">
                            <input type="radio" name="ticketPriority" value="medium" <?= !$isTenantVerified ? 'disabled' : '' ?>>
                            <span class="maintenance-radio-circle" aria-hidden="true"></span>
                            <span class="maintenance-radio-text">Medium</span>
                          </label>
                          <label class="maintenance-radio-option">
                            <input type="radio" name="ticketPriority" value="low" <?= !$isTenantVerified ? 'disabled' : '' ?>>
                            <span class="maintenance-radio-circle" aria-hidden="true"></span>
                            <span class="maintenance-radio-text">Low</span>
                          </label>
                        </div>
                      </div>
                    </div>
                  </div>

                  <!-- Right Column: Detailed Description -->
                  <div class="maintenance-form-right">
                    <div class="maintenance-field-group maintenance-desc-group">
                      <label for="ticketDesc" class="maintenance-field-label">Detailed Description:</label>
                      <textarea id="ticketDesc" class="w-full min-h-[220px] p-3.5 rounded-lg bg-[#F5F0E6] text-[#3E2723] font-semibold text-sm border border-[#8C6D43]/60 focus:outline-none focus:ring-2 focus:ring-[#4A3728] shadow-sm resize-none <?= !$isTenantVerified ? 'opacity-60 cursor-not-allowed bg-gray-200' : '' ?>" placeholder="Describe and put the details of your issue." required <?= !$isTenantVerified ? 'disabled' : '' ?>></textarea>
                    </div>
                  </div>
                </div>

                <!-- File Upload Section -->
                <div class="maintenance-upload-section">
                  <label for="ticketPhotos" class="maintenance-field-label">Attach photos of issue to be repaired:</label>
                  <div class="maintenance-upload-center">
                    <div class="maintenance-upload-btn-wrap">
                      <input type="file" id="ticketPhotos" name="maintenance_photos[]" accept="image/*" multiple class="maintenance-file-input" <?= !$isTenantVerified ? 'disabled' : '' ?>>
                      <label for="ticketPhotos" class="inline-flex items-center justify-center gap-2.5 px-6 py-2.5 rounded-xl bg-[#F5F0E6] text-[#3E2723] font-bold text-sm border-2 border-[#8C6D43] shadow-md hover:bg-[#E8D8B0] transition cursor-pointer <?= !$isTenantVerified ? 'opacity-60 cursor-not-allowed pointer-events-none' : '' ?>">
                        <i data-lucide="image" class="w-5 h-5 text-[#4A3728]" aria-hidden="true"></i>
                        <span id="ticketPhotosUploadLabel">Upload Photo</span>
                      </label>
                    </div>
                  </div>
                  <div id="ticketPhotosPreview" class="maintenance-photos-preview" style="display: none;"></div>
                </div>

                <!-- Centered Submit Request Button -->
                <div class="maintenance-submit-row">
                  <button type="submit" class="min-w-[260px] py-3.5 px-8 rounded-xl bg-[#4A3728] hover:bg-[#382618] text-white font-black text-base shadow-lg border border-[#382618] transition-all cursor-pointer <?= !$isTenantVerified ? 'opacity-60 cursor-not-allowed bg-gray-400 pointer-events-none' : '' ?>" id="btnLogTicket" <?= !$isTenantVerified ? 'disabled' : '' ?>>Submit Request</button>
                </div>
              </form>
            </div>

            <!-- 2. TRACK SECTION -->
            <div id="track-section" class="w-full hidden">
              <h2 class="text-2xl md:text-3xl font-black text-white mb-6 tracking-wide text-center sm:text-left">Track Maintenance Requests</h2>

              <div id="ticketHistoryContainer" class="flex flex-col gap-6">
                <?php if (empty($myTickets)): ?>
                  <div class="text-center py-12 px-6 rounded-2xl bg-[#F5F0E6] border-2 border-[#8C6D43]/40 text-[#7A6245]">
                    <i data-lucide="inbox" class="w-8 h-8 mx-auto mb-2 opacity-60"></i>
                    <p class="font-bold text-base m-0">No maintenance requests submitted yet.</p>
                    <p class="text-xs text-[#7A6245] mt-1 mb-0">Submit your first repair request using the "Submit Request" tab.</p>
                  </div>
                <?php else: ?>
                  <?php foreach ($myTickets as $index => $tk): 
                    $ticketNum = $index + 1;
                    $tkStatus = $tk['status'] ?? 'Pending';
                    $tkCategory = !empty($tk['category']) ? $tk['category'] : 'General';
                    $progressPct = 25;
                    $stLower = strtolower((string)$tkStatus);
                    $statusBadgeClass = 'bg-amber-100 text-amber-900 border-amber-300';
                    if (in_array($stLower, ['on-going', 'ongoing', 'assigned', 'in_progress'], true)) {
                      $progressPct = 65;
                      $statusBadgeClass = 'bg-blue-100 text-blue-900 border-blue-300';
                    } elseif (in_array($stLower, ['done', 'resolved', 'closed'], true)) {
                      $progressPct = 100;
                      $statusBadgeClass = 'bg-green-100 text-green-900 border-green-300';
                    }
                    $createdDateStr = !empty($tk['created_at']) ? date('M j, Y', strtotime($tk['created_at'])) : 'Recent';
                  ?>
                  <div class="maintenance-track-card p-5 sm:p-6 rounded-2xl bg-[#F5F0E6] border-2 border-[#8C6D43]/40 shadow-md cursor-pointer hover:shadow-lg transition-shadow" onclick="openTenantTicketModal(<?= htmlspecialchars(json_encode($tk), ENT_QUOTES, 'UTF-8') ?>)">
                    <!-- Top Row: Title, Date, and Badges -->
                    <div class="flex flex-col sm:flex-row sm:items-start justify-between gap-4">
                      <div>
                        <h3 class="text-lg sm:text-xl font-extrabold text-[#3E2723] m-0">
                          <?= $ticketNum ?>. <?= htmlspecialchars($tk['title']) ?>
                        </h3>
                        <p class="text-xs sm:text-sm font-semibold text-[#7A6245] mt-1 mb-0">
                          Date Submitted: <?= htmlspecialchars($createdDateStr) ?>
                        </p>
                      </div>
                      <div class="flex items-center gap-2.5 flex-wrap sm:flex-nowrap">
                        <span class="<?= $statusBadgeClass ?> border font-extrabold text-xs px-3.5 py-1.5 rounded-full shadow-sm min-w-[90px] text-center">
                          <?= htmlspecialchars($tkStatus) ?>
                        </span>
                        <span class="bg-white/90 text-[#3E2723] border border-[#8C6D43]/30 font-bold text-xs px-3.5 py-1.5 rounded-full shadow-sm min-w-[90px] text-center">
                          <?= htmlspecialchars($tkCategory) ?>
                        </span>
                      </div>
                    </div>

                    <!-- Progress Bar -->
                    <div class="w-full bg-white/80 h-3.5 rounded-full overflow-hidden my-3.5 border border-[#8C6D43]/30 shadow-inner">
                      <div class="bg-[#4A3728] h-full rounded-full transition-all duration-300" style="width: <?= $progressPct ?>%;"></div>
                    </div>

                    <!-- Issue Details Section -->
                    <div>
                      <span class="block text-sm font-extrabold text-[#3E2723] mb-1.5">Issue Details:</span>
                      <div class="bg-white/90 rounded-xl p-4 text-sm text-[#3E2723] leading-relaxed shadow-sm min-h-[60px] whitespace-pre-line border border-[#8C6D43]/20">
                        <?= nl2br(htmlspecialchars($tk['description'] ?? 'No additional details provided.')) ?>
                      </div>
                    </div>
                  </div>
                  <?php endforeach; ?>
                <?php endif; ?>
              </div>
            </div>
          </div>
        </div>
      </section>

      <!-- CATEGORY 3: UPLOAD PAYMENT -->
      <section id="tenantPayments" class="app-view-section">
        <?php
        // ---------------------------------------------------------------------
        // Real-Time Supabase PostgreSQL Payments & Ledger Data
        // ---------------------------------------------------------------------
        $currentTenantId = (int)$user['id'];

        // 1. Fetch all payment records for this tenant (ordered newest first)
        $payQueryStmt = $pdo->prepare("
            SELECT * FROM payments 
            WHERE tenant_id = :tid 
            ORDER BY billing_cycle DESC NULLS LAST, id DESC
        ");
        $payQueryStmt->execute([':tid' => $currentTenantId]);
        $allTenantPayments = $payQueryStmt->fetchAll();

        // 2. Compute outstanding rent balance, earliest due date, and unpaid ledger items
        $tenantRentBalance = 0.00;
        $unpaidBills = [];
        $earliestDueDate = null;
        $depositBill = null;
        $rentBill = null;

        foreach ($allTenantPayments as $p) {
            $pType = $p['payment_type'] ?? 'Rent';
            if ($pType === 'Advance Deposit' && !$depositBill) {
                $depositBill = $p;
            } elseif ($pType === 'Rent' && !$rentBill) {
                $rentBill = $p;
            }

            $pStatus = strtolower((string)($p['status'] ?? ''));
            $amtDue = (float)($p['amount_due'] ?? 0.0);
            $amtPaid = (float)($p['amount_paid'] ?? 0.0);
            $remaining = max(0.0, $amtDue - $amtPaid);

            if (in_array($pStatus, ['unpaid', 'overdue', 'rejected', 'pending_setup', 'pending'], true) || $remaining > 0.01) {
                if ($remaining > 0.01) {
                    $tenantRentBalance += $remaining;
                }
                $unpaidBills[] = $p;
                if (!empty($p['due_date'])) {
                    if ($earliestDueDate === null || strtotime($p['due_date']) < strtotime($earliestDueDate)) {
                        $earliestDueDate = $p['due_date'];
                    }
                }
            }
        }

        // If no unpaid bills exist in the ledger, create a standard upcoming cycle entry so the form is always usable
        if (empty($unpaidBills)) {
            $currentMonthCycle = date('Y-m-01');
            $unpaidBills[] = [
                'id' => 0,
                'payment_type' => 'Rent',
                'billing_cycle' => $currentMonthCycle,
                'due_date' => date('Y-m-05'),
                'amount_due' => (float)($unit['monthly_rent'] ?? 3000.00),
                'amount_paid' => 0.00,
                'status' => 'unpaid'
            ];
        }

        $rentBalanceDisplay = number_format($tenantRentBalance, 2);

        if (!empty($earliestDueDate)) {
            $nextDueDateDisplay = date('M d, Y', strtotime($earliestDueDate));
        } elseif (!empty($rentBill['due_date'])) {
            $nextDueDateDisplay = date('M d, Y', strtotime($rentBill['due_date']));
        } elseif (!empty($allTenantPayments[0]['due_date'])) {
            $nextDueDateDisplay = date('M d, Y', strtotime($allTenantPayments[0]['due_date']));
        } else {
            $nextDueDateDisplay = date('M 05, Y', strtotime('+1 month'));
        }

        $unitHeaderTitle = strtoupper(!empty($unit['unit_number']) ? $unit['unit_number'] : 'Unit 1') . ' PAYMENT';
        ?>

        <div class="max-w-4xl mx-auto space-y-6">

          <!-- Success Alert Banner (Displayed after successful payment submission) -->
          <?php if (isset($_GET['success']) && $_GET['success'] === '1'): ?>
          <div class="bg-emerald-50 border-2 border-emerald-400 text-emerald-950 rounded-2xl shadow-md p-4 flex items-center justify-between gap-3" role="alert">
            <div class="flex items-center gap-3">
              <div class="w-10 h-10 rounded-xl bg-emerald-100 border border-emerald-300 text-emerald-700 flex items-center justify-center flex-shrink-0">
                <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 13l4 4L19 7"/></svg>
              </div>
              <div>
                <h3 class="font-extrabold text-sm sm:text-base text-emerald-900 m-0">Payment Proof Submitted Successfully!</h3>
                <p class="text-xs text-emerald-800 m-0 mt-0.5">Your payment receipt has been received and is currently <strong class="uppercase text-amber-700 font-black">Pending</strong> review by the property owner. You can track its status below.</p>
              </div>
            </div>
            <button type="button" onclick="this.parentElement.remove()" class="text-emerald-700 hover:text-emerald-950 font-black p-1 text-base cursor-pointer" title="Dismiss">&times;</button>
          </div>
          <?php elseif (isset($_GET['error'])): ?>
          <div class="bg-red-50 border-2 border-red-400 text-red-950 rounded-2xl shadow-md p-4 flex items-center justify-between gap-3" role="alert">
            <div class="flex items-center gap-3">
              <div class="w-10 h-10 rounded-xl bg-red-100 border border-red-300 text-red-700 flex items-center justify-center flex-shrink-0">
                <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/></svg>
              </div>
              <div>
                <h3 class="font-extrabold text-sm sm:text-base text-red-900 m-0">Payment Submission Notice</h3>
                <p class="text-xs text-red-800 m-0 mt-0.5">
                  <?php
                  if ($_GET['error'] === 'file_too_large') echo 'The attached receipt file exceeds the 10MB limit.';
                  elseif ($_GET['error'] === 'upload_failed') echo 'Failed to save the receipt image. Please try again.';
                  elseif ($_GET['error'] === 'no_file') echo 'Please attach a payment receipt photo before submitting.';
                  elseif ($_GET['error'] === 'invalid_file_type') echo 'Only JPG, PNG, WEBP, or PDF receipt files are allowed.';
                  else echo 'An error occurred while saving your payment. Please try again.';
                  ?>
                </p>
              </div>
            </div>
            <button type="button" onclick="this.parentElement.remove()" class="text-red-700 hover:text-red-950 font-black p-1 text-base cursor-pointer" title="Dismiss">&times;</button>
          </div>
          <?php endif; ?>

          <?php
          $isTrackerTabActive = (isset($_GET['tab']) && $_GET['tab'] === 'tracker') || (isset($_GET['success']) && $_GET['success'] === '1');
          ?>

          <!-- 1. STATIC TOP HEADER CARD (60% Dominant Light Beige with 30% Border & 10% Text) -->
          <div class="bg-[#F5F0E6] border-2 border-[#8C6D43] rounded-2xl shadow-md p-6 text-center">
            <h1 class="text-2xl md:text-3xl font-black text-[#3E2723] tracking-wider uppercase mb-2">
              <?= htmlspecialchars($unitHeaderTitle) ?>
            </h1>
            <div class="flex flex-wrap items-center justify-center gap-x-12 gap-y-2 text-sm md:text-base font-bold text-[#7A6245]">
              <div>Rent Balance: <span class="font-black text-[#3E2723]">Php <?= $rentBalanceDisplay ?></span></div>
              <div>Next Due Date: <span class="font-black text-[#3E2723]"><?= htmlspecialchars($nextDueDateDisplay) ?></span></div>
            </div>
          </div>

          <!-- 2. UNIFIED PARENT FOLDER CONTAINER (30% Secondary Medium Clay bg-[#B89B72]) -->
          <div class="border-2 border-[#8C6D43] rounded-2xl shadow-xl overflow-hidden bg-[#B89B72]">

            <!-- Top Tab Bar -->
            <div class="flex items-stretch bg-[#F5F0E6] relative z-0">
              <!-- Tab: Upload Proof -->
              <button type="button" id="tabBtnUpload" onclick="switchPaymentTab('upload')"
                class="flex-1 py-3.5 px-6 font-black text-sm md:text-base tracking-wide flex items-center justify-center gap-2 transition-all <?= !$isTrackerTabActive ? 'bg-[#B89B72] text-white rounded-tl-xl border-b-2 border-b-[#B89B72] z-10' : 'bg-[#F5F0E6] text-[#3E2723] rounded-tl-xl border-r border-b-2 border-[#8C6D43] hover:bg-[#E8D8B0]' ?> cursor-pointer">
                <svg class="w-5 h-5 <?= !$isTrackerTabActive ? 'text-white' : 'text-[#4A3728]' ?>" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-8l-4-4m0 0L8 8m4-4v12" />
                </svg>
                <span>Upload Proof</span>
              </button>

              <!-- Tab: Payment Tracker -->
              <button type="button" id="tabBtnTracker" onclick="switchPaymentTab('tracker')"
                class="flex-1 py-3.5 px-6 font-bold text-sm md:text-base tracking-wide flex items-center justify-center gap-2 transition-all <?= $isTrackerTabActive ? 'bg-[#B89B72] text-white rounded-tr-xl border-b-2 border-b-[#B89B72] z-10' : 'bg-[#F5F0E6] text-[#3E2723] rounded-tr-xl border-l border-b-2 border-[#8C6D43] hover:bg-[#E8D8B0]' ?> cursor-pointer">
                <svg class="w-5 h-5 <?= $isTrackerTabActive ? 'text-white' : 'text-[#4A3728]' ?>" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-6 9l2 2 4-4" />
                </svg>
                <span>Payment Tracker</span>
                <span id="paymentCountBadge" class="ml-1.5 px-2 py-0.5 rounded-full text-xs font-black bg-[#E8D8B0] text-[#3E2723] border border-[#B89B72]">
                  <?= count($allTenantPayments) ?>
                </span>
              </button>
            </div>

            <!-- Main Content Area (30% Secondary Medium Clay bg-[#B89B72] Open Folder) -->
            <div class="p-6 md:p-8 bg-[#B89B72]">

              <!-- TAB 1: UPLOAD PROOF -->
              <div id="tabContentUpload" class="<?= $isTrackerTabActive ? 'hidden' : '' ?>">
                <h2 class="text-2xl md:text-3xl font-black text-white mb-6 tracking-wide">Upload Payment Proof</h2>

                <?php if (!$isTenantVerified): ?>
                <div class="mb-6 p-4 rounded-xl border border-amber-400 bg-amber-100 text-amber-950 flex items-center justify-between gap-3 shadow-md" role="alert">
                  <div class="flex items-center gap-3">
                    <div class="p-2 rounded-lg bg-amber-200 text-amber-800 flex-shrink-0">
                      <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/></svg>
                    </div>
                    <div>
                      <p class="font-bold text-sm sm:text-base m-0">Viewing Mode: Please verify your identity to submit new records.</p>
                      <p class="text-xs text-amber-900 mt-0.5 mb-0">You can explore billing cycles and historical receipts freely. Complete verification to unlock payment submission.</p>
                    </div>
                  </div>
                  <button type="button" onclick="openModal('verifyAccountModal')" class="flex-shrink-0 px-3.5 py-1.5 rounded-lg text-xs font-bold bg-[#4A3728] hover:bg-[#382618] text-white shadow-sm transition-colors cursor-pointer">
                    Verify Now
                  </button>
                </div>
                <?php endif; ?>

                <!-- Two Summary Boxes -->
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 max-w-md mx-auto mb-6">
                  <!-- Advance Deposit -->
                  <div class="bg-white rounded-xl p-4 text-center shadow-md border border-[#8C6D43]/30">
                    <div class="text-xs font-semibold text-gray-700">Advance Deposit:</div>
                    <div class="text-base md:text-lg font-black text-gray-900 mt-0.5">Php <?= number_format((float)($depositBill['amount_due'] ?? 3000.00), 2) ?></div>
                    <?php if ($depositBill && $depositBill['status'] === 'approved'): ?>
                      <div class="text-xs font-black text-green-600 mt-0.5">Paid</div>
                    <?php elseif ($depositBill && $depositBill['status'] === 'pending'): ?>
                      <div class="text-xs font-black text-amber-600 mt-0.5">Pending</div>
                    <?php else: ?>
                      <div class="text-xs font-black text-red-600 mt-0.5">Unpaid</div>
                    <?php endif; ?>
                  </div>

                  <!-- Monthly Rent -->
                  <div class="bg-white rounded-xl p-4 text-center shadow-md border border-[#8C6D43]/30">
                    <div class="text-xs font-semibold text-gray-700">Monthly Rent:</div>
                    <div class="text-base md:text-lg font-black text-gray-900 mt-0.5">Php <?= number_format((float)($rentBill['amount_due'] ?? $myTotalMonthlyRent), 2) ?></div>
                    <?php if ($rentBill && $rentBill['status'] === 'approved'): ?>
                      <div class="text-xs font-black text-green-600 mt-0.5">Paid</div>
                    <?php elseif ($rentBill && $rentBill['status'] === 'pending'): ?>
                      <div class="text-xs font-black text-amber-600 mt-0.5">Pending</div>
                    <?php else: ?>
                      <div class="text-xs font-black text-red-600 mt-0.5">Unpaid</div>
                    <?php endif; ?>
                  </div>
                </div>

                <!-- Form: Multipart POST Form for Payment Proof Upload -->
                <form id="paymentUploadForm" method="POST" action="tenant_portal.php?section=tenantPayments" enctype="multipart/form-data">
                  <input type="hidden" name="action" value="upload_payment_proof">
                  <input type="hidden" id="payTargetPayment" name="payment_id" value="<?= !empty($unpaidBills[0]['id']) ? (int)$unpaidBills[0]['id'] : '0' ?>">

                  <!-- Grid for Form Fields (60% Dominant Light Beige Form Inputs bg-[#F5F0E6]) -->
                  <div class="grid grid-cols-1 md:grid-cols-2 gap-x-6 gap-y-4 mb-6">
                    <!-- 1. Billing Month -->
                    <div>
                      <label for="payBillingMonth" class="block font-bold text-sm text-[#F5F0E6] mb-1.5">Billing Month:</label>
                      <div class="relative">
                        <select id="payBillingMonth" name="billing_month" onchange="handleBillingMonthChange()" class="w-full bg-[#F5F0E6] text-[#3E2723] font-bold text-sm px-4 py-2.5 rounded-xl border border-[#8C6D43]/60 shadow-md focus:outline-none focus:ring-2 focus:ring-[#4A3728] appearance-none pr-10 cursor-pointer <?= !$isTenantVerified ? 'opacity-60 cursor-not-allowed' : '' ?>" <?= !$isTenantVerified ? 'disabled' : '' ?>>
                          <?php foreach ($unpaidBills as $idx => $ub): ?>
                            <?php
                            $cycleDate = !empty($ub['billing_cycle']) ? $ub['billing_cycle'] : date('Y-m-01');
                            $cycleLabel = date('F Y', strtotime($cycleDate));
                            $dueAmt = (float)($ub['amount_due'] ?? 3000.00) - (float)($ub['amount_paid'] ?? 0.00);
                            $pType = !empty($ub['payment_type']) ? $ub['payment_type'] : 'Rent';
                            ?>
                            <option value="<?= htmlspecialchars($cycleDate) ?>"
                                    data-id="<?= (int)($ub['id'] ?? 0) ?>"
                                    data-type="<?= htmlspecialchars($pType) ?>"
                                    data-amount="<?= number_format(max(0.0, $dueAmt), 2, '.', '') ?>"
                                    <?= $idx === 0 ? 'selected' : '' ?>>
                              <?= htmlspecialchars($cycleLabel) ?> – <?= htmlspecialchars($pType) ?> (Php <?= number_format(max(0.0, $dueAmt), 2) ?>)
                            </option>
                          <?php endforeach; ?>
                        </select>
                        <div class="pointer-events-none absolute inset-y-0 right-0 flex items-center px-3.5 text-[#7A6245]">
                          <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M19 9l-7 7-7-7"></path></svg>
                        </div>
                      </div>
                    </div>

                    <!-- 2. Payment Method -->
                    <div>
                      <label for="payMethod" class="block font-bold text-sm text-[#F5F0E6] mb-1.5">Payment Method:</label>
                      <div class="relative">
                        <select id="payMethod" name="payment_method" class="w-full bg-[#F5F0E6] text-[#3E2723] font-bold text-sm px-4 py-2.5 rounded-xl border border-[#8C6D43]/60 shadow-md focus:outline-none focus:ring-2 focus:ring-[#4A3728] appearance-none pr-10 cursor-pointer <?= !$isTenantVerified ? 'opacity-60 cursor-not-allowed' : '' ?>" <?= !$isTenantVerified ? 'disabled' : '' ?>>
                          <option value="GCash" selected>GCash</option>
                          <option value="Bank Transfer">Bank Transfer</option>
                          <option value="Maya">Maya</option>
                          <option value="Cash">Cash</option>
                        </select>
                        <div class="pointer-events-none absolute inset-y-0 right-0 flex items-center px-3.5 text-[#7A6245]">
                          <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M19 9l-7 7-7-7"></path></svg>
                        </div>
                      </div>
                    </div>

                    <!-- 3. Pay for -->
                    <div>
                      <label for="payFor" class="block font-bold text-sm text-[#F5F0E6] mb-1.5">Pay for:</label>
                      <div class="relative">
                        <select id="payFor" name="pay_for" class="w-full bg-[#F5F0E6] text-[#3E2723] font-bold text-sm px-4 py-2.5 rounded-xl border border-[#8C6D43]/60 shadow-md focus:outline-none focus:ring-2 focus:ring-[#4A3728] appearance-none pr-10 cursor-pointer <?= !$isTenantVerified ? 'opacity-60 cursor-not-allowed' : '' ?>" <?= !$isTenantVerified ? 'disabled' : '' ?>>
                          <option value="Rent" <?= (!empty($unpaidBills[0]['payment_type']) && $unpaidBills[0]['payment_type'] === 'Rent') ? 'selected' : '' ?>>Monthly Rent</option>
                          <option value="Advance Deposit" <?= (!empty($unpaidBills[0]['payment_type']) && $unpaidBills[0]['payment_type'] === 'Advance Deposit') ? 'selected' : '' ?>>Advance Deposit</option>
                          <option value="Additional Space">Additional Space</option>
                        </select>
                        <div class="pointer-events-none absolute inset-y-0 right-0 flex items-center px-3.5 text-[#7A6245]">
                          <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M19 9l-7 7-7-7"></path></svg>
                        </div>
                      </div>
                    </div>

                    <!-- 4. Payment Amount (Required field, 60% Dominant Light Beige fill bg-[#F5F0E6]) -->
                    <div>
                      <label for="payAmount" class="block font-bold text-sm text-[#F5F0E6] mb-1.5">Payment Amount (Php):</label>
                      <?php
                      $initialPayAmount = !empty($unpaidBills[0]) ? max(0.0, (float)$unpaidBills[0]['amount_due'] - (float)$unpaidBills[0]['amount_paid']) : 3000.00;
                      if ($initialPayAmount <= 0.01) $initialPayAmount = 3000.00;
                      ?>
                      <input type="number" step="0.01" min="1" name="amount" id="payAmount" required value="<?= number_format($initialPayAmount, 2, '.', '') ?>" placeholder="0.00" class="w-full bg-[#F5F0E6] text-[#3E2723] font-bold text-sm px-4 py-2.5 rounded-xl border border-[#8C6D43]/60 shadow-md placeholder-[#9A7B4F] focus:outline-none focus:ring-2 focus:ring-[#4A3728] <?= !$isTenantVerified ? 'opacity-60 cursor-not-allowed' : '' ?>" <?= !$isTenantVerified ? 'disabled' : '' ?>>
                    </div>

                    <!-- 5. Reference Number -->
                    <div class="md:col-span-2">
                      <label for="payReferenceNumber" class="block font-bold text-sm text-[#F5F0E6] mb-1.5">Reference Number:</label>
                      <input type="text" id="payReferenceNumber" name="reference_number" placeholder="Enter reference number (e.g. 083948857486783)" class="w-full bg-[#F5F0E6] text-[#3E2723] font-bold text-sm px-4 py-2.5 rounded-xl border border-[#8C6D43]/60 shadow-md placeholder-[#9A7B4F] focus:outline-none focus:ring-2 focus:ring-[#4A3728] <?= !$isTenantVerified ? 'opacity-60 cursor-not-allowed' : '' ?>" <?= !$isTenantVerified ? 'disabled' : '' ?>>
                    </div>
                  </div>

                  <!-- Dashed-Border Upload Zone (30% Secondary Overlay) -->
                  <div id="receiptDropZone" class="border-2 border-dashed border-[#8C6D43] rounded-xl p-6 text-center max-w-lg mx-auto mb-6 bg-[#9A7B4F]/20 transition-all hover:bg-[#9A7B4F]/30 cursor-pointer <?= !$isTenantVerified ? 'opacity-60 cursor-not-allowed pointer-events-none' : '' ?>">
                    <div class="font-bold text-sm text-[#F5F0E6] mb-3">Upload Photos of Payment Receipt:</div>
                    <label for="receiptFileInput" class="inline-flex items-center gap-2 px-6 py-2 bg-[#F5F0E6] hover:bg-white text-[#3E2723] text-sm font-bold rounded shadow-sm border border-[#8C6D43] cursor-pointer transition-all">
                      <svg class="w-4 h-4 text-[#4A3728]" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2 2v12a2 2 0 002 2z"></path></svg>
                      + Add Photo
                    </label>
                    <input type="file" id="receiptFileInput" name="receipt" accept="image/*,.pdf" class="hidden" onchange="handleReceiptFileSelect(event)" <?= !$isTenantVerified ? 'disabled' : '' ?>>

                    <!-- File preview card once attached -->
                    <div id="filePreviewCard" class="hidden mt-4 inline-flex items-center gap-3 px-4 py-2 bg-white rounded-lg shadow-sm border border-[#8C6D43]/40 text-left">
                      <span class="text-xl">📄</span>
                      <div>
                        <div id="previewFileName" class="text-xs font-bold text-[#3E2723] truncate max-w-[200px]">receipt.png</div>
                        <div id="previewFileSize" class="text-[11px] text-[#7A6245]">1.2 MB</div>
                      </div>
                      <button type="button" id="btnRemoveFile" onclick="removeAttachedReceipt(event)" class="text-[#7A6245] hover:text-red-500 font-bold px-1.5" title="Remove photo">✕</button>
                    </div>
                  </div>

                  <!-- Submit Proof Button (10% Accent Dark Roast Brown bg-[#4A3728]) -->
                  <div class="text-center">
                    <button type="submit" id="btnUploadReceipt" class="px-14 py-3.5 bg-[#4A3728] hover:bg-[#382618] text-white font-black text-base uppercase rounded-xl shadow-lg transition-all cursor-pointer border border-[#382618] <?= !$isTenantVerified ? 'opacity-60 cursor-not-allowed bg-gray-400 text-gray-700 pointer-events-none border-transparent' : '' ?>" <?= !$isTenantVerified ? 'disabled' : '' ?>>
                      Submit Proof
                    </button>
                  </div>
                </form>
              </div>

              <!-- TAB 2: PAYMENT TRACKER -->
              <div id="tabContentTracker" class="<?= !$isTrackerTabActive ? 'hidden' : '' ?>">
                <h2 class="text-2xl md:text-3xl font-black text-white mb-6 tracking-wide">Payment Tracker and History</h2>

                <div class="space-y-4">
                  <?php if (!empty($allTenantPayments)): ?>
                    <?php $itemNum = 1; foreach ($allTenantPayments as $py): ?>
                      <?php
                      $statusLower = strtolower((string)($py['status'] ?? ''));
                      $displayAmount = (float)($py['amount_paid'] > 0 ? $py['amount_paid'] : ($py['amount_due'] ?? 3000.00));
                      $cycleText = !empty($py['billing_cycle']) ? date('F Y', strtotime($py['billing_cycle'])) : 'Current';
                      $payType = !empty($py['payment_type']) ? $py['payment_type'] : 'Rent';
                      $submitDate = !empty($py['payment_date']) ? date('M j, Y', strtotime($py['payment_date'])) : (!empty($py['created_at']) ? date('M j, Y', strtotime($py['created_at'])) : date('M j, Y'));
                      
                      $refDisplay = 'N/A';
                      if (!empty($py['payment_method']) && preg_match('/Ref:\s*([A-Za-z0-9_-]+)/i', $py['payment_method'], $m)) {
                          $refDisplay = $m[1];
                      } elseif (!empty($py['id'])) {
                          $refDisplay = sprintf('0839488574%05d', $py['id']);
                      }
                      ?>
                      <!-- Tracker Row Card (60% Dominant Light Beige Fill bg-[#F5F0E6]) -->
                      <div class="flex items-center justify-between p-4 md:p-5 bg-[#F5F0E6] rounded-xl border border-[#8C6D43]/40 shadow-sm hover:bg-white transition-colors">
                        <div>
                          <div class="font-bold text-base md:text-lg text-[#3E2723]">
                            <?= $itemNum++ ?>. <?= htmlspecialchars($cycleText) ?> <?= htmlspecialchars($payType) ?>
                          </div>
                          <div class="text-xs text-[#7A6245] mt-1 font-medium">
                            Date Submitted: <?= htmlspecialchars($submitDate) ?>
                          </div>
                          <div class="text-xs text-[#7A6245] mt-0.5 font-medium">
                            Ref No.: <?= htmlspecialchars($refDisplay) ?>
                          </div>
                        </div>
                        <div class="text-right">
                          <div class="font-black text-base md:text-lg text-[#3E2723]">
                            PHP <?= number_format($displayAmount, 2) ?>
                          </div>
                          <div>
                            <!-- Status Badges (10% Accent Layer) -->
                            <?php if ($statusLower === 'approved'): ?>
                              <span class="inline-block mt-2 px-4 py-1 bg-[#22c55e] text-white font-black text-xs uppercase rounded shadow-sm">Verified</span>
                            <?php elseif ($statusLower === 'pending'): ?>
                              <span class="inline-block mt-2 px-4 py-1 bg-[#f59e0b] text-white font-black text-xs uppercase rounded shadow-sm">Pending</span>
                            <?php elseif ($statusLower === 'overdue'): ?>
                              <span class="inline-block mt-2 px-4 py-1 bg-[#ef4444] text-white font-black text-xs uppercase rounded shadow-sm">Overdue</span>
                            <?php elseif ($statusLower === 'rejected'): ?>
                              <span class="inline-block mt-2 px-4 py-1 bg-[#ef4444] text-white font-black text-xs uppercase rounded shadow-sm">Rejected</span>
                            <?php else: ?>
                              <span class="inline-block mt-2 px-4 py-1 bg-[#ef4444] text-white font-black text-xs uppercase rounded shadow-sm">Unpaid</span>
                            <?php endif; ?>
                          </div>
                        </div>
                      </div>
                    <?php endforeach; ?>
                  <?php else: ?>
                    <div class="p-8 bg-[#F5F0E6] rounded-xl border border-[#8C6D43]/40 text-center text-[#7A6245] font-bold">
                      No payment records found yet. Submit your payment proof above to start tracking.
                    </div>
                  <?php endif; ?>
                </div>
              </div>

            </div>
          </div>

        </div>
      </section>

      <!-- CATEGORY 4: SUBMIT GENERAL REPORT (NON-MAINTENANCE REQUESTS) -->
      <section id="tenantReport" class="app-view-section">

        <!-- ═══════════════════════════════════════════════════════════════════
             REPORT AND APPROVAL REQUEST — TABBED NAVIGATION COMPONENT
             Color Hierarchy (60-30-10 Rule):
             • 60% Dominant (Light Beige): #E8D8B0 / #FAF5EC (Inputs, Tracker Container, Inactive Tab)
             • 30% Secondary (Medium Brown): #B89B72 (Active Submit Tab & Submit Container)
             • 10% Accent (Dark Brown): #4A3728 (Buttons, Headers, Progress Fill)
             ═══════════════════════════════════════════════════════════════════ -->
        <div class="w-full max-w-4xl mx-auto" id="approvalTabsContainer">

          <?php
          $isReportTrackerActive = (isset($_GET['tab']) && $_GET['tab'] === 'tracker') || (isset($_GET['success']) && $_GET['success'] === 'report_submitted');
          ?>
          <!-- ── Top Tab Bar ─────────────────────────────────────────────── -->
          <div class="flex items-end gap-1.5" role="tablist" aria-label="Approval Request Navigation">
            <!-- Tab 1: Submit Request -->
            <button
              type="button"
              id="tabBtnSubmit"
              role="tab"
              aria-selected="<?= $isReportTrackerActive ? 'false' : 'true' ?>"
              aria-controls="tabPaneSubmit"
              onclick="switchApprovalTab('submit')"
              class="<?= $isReportTrackerActive 
                ? 'relative z-0 px-6 py-2.5 rounded-t-2xl font-bold text-sm sm:text-base tracking-wide bg-[#B89B72]/70 text-[#261909]/80 border-2 border-[#7C5A36] hover:bg-[#B89B72] transition-all cursor-pointer focus:outline-none focus:ring-2 focus:ring-[#4A3728]' 
                : 'relative z-10 px-6 py-3 rounded-t-2xl font-black text-sm sm:text-base tracking-wide bg-[#B89B72] text-[#261909] border-2 border-b-0 border-[#7C5A36] shadow-[0_-2px_6px_rgba(74,55,40,0.1)] transition-all cursor-pointer focus:outline-none focus:ring-2 focus:ring-[#4A3728]' ?>"
              style="margin-bottom: -2px;">
              Submit Request
            </button>

            <!-- Tab 2: Status Tracker -->
            <button
              type="button"
              id="tabBtnTracker"
              role="tab"
              aria-selected="<?= $isReportTrackerActive ? 'true' : 'false' ?>"
              aria-controls="tabPaneTracker"
              onclick="switchApprovalTab('tracker')"
              class="<?= $isReportTrackerActive 
                ? 'relative z-10 px-6 py-3 rounded-t-2xl font-black text-sm sm:text-base tracking-wide bg-[#E8D8B0] text-[#261909] border-2 border-b-0 border-[#8C6D43] shadow-[0_-2px_6px_rgba(74,55,40,0.1)] transition-all cursor-pointer focus:outline-none focus:ring-2 focus:ring-[#4A3728]' 
                : 'relative z-0 px-6 py-2.5 rounded-t-2xl font-bold text-sm sm:text-base tracking-wide bg-[#E8D8B0] text-[#5C3D1E] border-2 border-[#8C6D43] hover:bg-[#DFCE9F] transition-all cursor-pointer focus:outline-none focus:ring-2 focus:ring-[#4A3728]' ?>"
              style="margin-bottom: -2px;">
              Status Tracker
            </button>
          </div>

          <!-- ── Tab 1: "Submit Request" Pane ─────────────── -->
          <div
            id="tabPaneSubmit"
            role="tabpanel"
            aria-labelledby="tabBtnSubmit"
            class="<?= $isReportTrackerActive ? 'hidden ' : '' ?>relative z-0 rounded-b-3xl rounded-tr-3xl border-2 border-[#7C5A36]
                   bg-[#B89B72] p-6 sm:p-9 shadow-xl">

            <!-- Card Header: Large Dark Text -->
            <h2 class="text-2xl sm:text-4xl font-black text-[#3E2723] text-center mb-7 tracking-wide drop-shadow-sm">
              Report and Approval Request
            </h2>

            <?php if (!$isTenantVerified): ?>
            <div class="mb-6 p-4 rounded-xl border border-amber-300 bg-amber-50 text-amber-900 flex items-center justify-between gap-3 shadow-sm" role="alert">
              <div class="flex items-center gap-3">
                <i data-lucide="eye" class="w-5 h-5 text-amber-700 flex-shrink-0" aria-hidden="true"></i>
                <p class="font-bold text-xs sm:text-sm m-0">Viewing Mode: Verify your identity to submit new approval requests.</p>
              </div>
              <button type="button" onclick="openModal('verifyAccountModal')" class="flex-shrink-0 px-3.5 py-1.5 rounded-lg text-xs font-bold bg-[#4A3728] text-white hover:bg-[#382618] transition-colors cursor-pointer">
                Verify Now
              </button>
            </div>
            <?php endif; ?>

            <form id="approvalForm" method="POST" action="tenant_portal.php?section=tenantReport" enctype="multipart/form-data" class="flex flex-col gap-5" autocomplete="off">
              <input type="hidden" name="action" value="submit_report">
              <!-- Form Layout: Two-Column Label & Input Grid -->
              <div class="grid grid-cols-1 md:grid-cols-[180px_1fr] items-center gap-2 sm:gap-4">
                <label for="requestCategory" class="font-black text-sm sm:text-base text-[#2E1A0C]">
                  Request Category:
                </label>
                <div class="relative">
                  <select
                    id="requestCategory"
                    name="request_category"
                    required
                    <?= !$isTenantVerified ? 'disabled' : '' ?>
                    class="w-full appearance-none rounded-xl border-2 border-[#7C5A36]
                           bg-[#FAF5EC] text-[#2E1A0C] font-semibold text-sm sm:text-base
                           px-4 py-3 pr-10 shadow-inner
                           focus:outline-none focus:ring-2 focus:ring-[#4A3728] focus:border-[#4A3728]
                           transition-colors cursor-pointer <?= !$isTenantVerified ? 'opacity-60 cursor-not-allowed' : '' ?>">
                    <option value="" disabled selected hidden>Select a request category...</option>
                    <option value="Unit Customization">Unit Customization</option>
                    <option value="Pet Addition">Pet Addition</option>
                    <option value="Appliance or Wi-Fi Installation">Appliance or Wi-Fi Installation</option>
                    <option value="Additional Occupant">Additional Occupant</option>
                    <option value="Vehicle Parking Request">Vehicle Parking Request</option>
                    <option value="Move-Out or Lease Renewal Notice">Move-Out or Lease Renewal Notice</option>
                    <option value="Key Duplication or Replacement">Key Duplication or Replacement</option>
                  </select>
                  <div class="pointer-events-none absolute inset-y-0 right-3.5 flex items-center text-[#5C3D1E]">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24">
                      <path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7"/>
                    </svg>
                  </div>
                </div>
              </div>

              <!-- Request Specification -->
              <div class="grid grid-cols-1 md:grid-cols-[180px_1fr] items-center gap-2 sm:gap-4">
                <label for="requestSpecification" class="font-black text-sm sm:text-base text-[#2E1A0C]">
                  Request Specification:
                </label>
                <input
                  type="text"
                  id="requestSpecification"
                  name="request_specification"
                  placeholder="e.g. Add Dog (Golden Retriever)"
                  maxlength="160"
                  required
                  <?= !$isTenantVerified ? 'disabled' : '' ?>
                  class="w-full rounded-xl border-2 border-[#7C5A36]
                         bg-[#FAF5EC] text-[#2E1A0C] font-semibold text-sm sm:text-base
                         px-4 py-3 placeholder-[#9E835F] shadow-inner
                         focus:outline-none focus:ring-2 focus:ring-[#4A3728] focus:border-[#4A3728]
                         transition-colors <?= !$isTenantVerified ? 'opacity-60 cursor-not-allowed' : '' ?>">
              </div>

              <!-- Describe your Request -->
              <div class="grid grid-cols-1 md:grid-cols-[180px_1fr] items-start gap-2 sm:gap-4">
                <label for="requestDescription" class="font-black text-sm sm:text-base text-[#2E1A0C] pt-2">
                  Describe your Request:
                </label>
                <textarea
                  id="requestDescription"
                  name="request_description"
                  rows="4"
                  placeholder="Describe and put the details of your request."
                  required
                  <?= !$isTenantVerified ? 'disabled' : '' ?>
                  class="w-full rounded-xl border-2 border-[#7C5A36]
                         bg-[#FAF5EC] text-[#2E1A0C] font-semibold text-sm sm:text-base
                         px-4 py-3 placeholder-[#9E835F] shadow-inner resize-none
                         focus:outline-none focus:ring-2 focus:ring-[#4A3728] focus:border-[#4A3728]
                         transition-colors <?= !$isTenantVerified ? 'opacity-60 cursor-not-allowed' : '' ?>"></textarea>
              </div>

              <!-- Upload Supporting Document/Photo Zone with Dashed Border -->
              <div class="w-full mt-1">
                <input
                  type="file"
                  id="requestAttachments"
                  name="request_attachments[]"
                  accept="image/*,.pdf,.doc,.docx"
                  multiple
                  class="hidden"
                  <?= !$isTenantVerified ? 'disabled' : '' ?>
                  onchange="handleRequestFileSelect(this)">
                <label
                  for="requestAttachments"
                  id="uploadDashedZone"
                  class="flex items-center justify-center gap-3 w-full rounded-xl
                         border-2 border-dashed border-[#7C5A36]
                         bg-[#FAF5EC]/70 text-[#3E2723] hover:bg-[#FAF5EC]
                         py-5 px-4 text-center transition-all cursor-pointer shadow-sm
                         <?= !$isTenantVerified ? 'opacity-60 cursor-not-allowed pointer-events-none' : '' ?>">
                  <span class="font-bold text-sm sm:text-base text-[#3E2723]">
                    Upload Supporting Document/Photo
                  </span>
                  <!-- Upload Arrow Icon -->
                  <svg class="w-6 h-6 text-[#3E2723]" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M4 16v2a2 2 0 002 2h12a2 2 0 002-2v-2M12 12V4m0 0L8 8m4-4l4 4"/>
                  </svg>
                </label>
                <div id="requestFilePreview" class="flex flex-wrap gap-2 mt-2"></div>
              </div>

              <!-- Submit Request Button (10% Dark Brown Accent Color) -->
              <div class="flex justify-center mt-3">
                <button
                  type="submit"
                  id="btnSubmitApproval"
                  <?= !$isTenantVerified ? 'disabled' : '' ?>
                  class="min-w-[240px] sm:min-w-[280px] py-3.5 px-8 rounded-xl font-black text-lg tracking-wide
                         text-white bg-[#4A3728] hover:bg-[#382618] active:bg-[#2A1A0E]
                         border-2 border-[#2D1B0E] shadow-lg hover:shadow-xl hover:-translate-y-0.5
                         transition-all cursor-pointer <?= !$isTenantVerified ? 'opacity-60 cursor-not-allowed pointer-events-none' : '' ?>">
                  Submit Request
                </button>
              </div>

          <!-- ── Tab 2: "Status Tracker" Pane ─────────────── -->
          <div
            id="tabPaneTracker"
            role="tabpanel"
            aria-labelledby="tabBtnTracker"
            class="<?= !$isReportTrackerActive ? 'hidden ' : '' ?>relative z-0 rounded-b-3xl rounded-tr-3xl border-2 border-[#8C6D43]
                   bg-[#E8D8B0] p-6 sm:p-9 shadow-xl">

            <!-- Card Header: Large Dark Text -->
            <h2 class="text-2xl sm:text-3xl font-black text-[#3E2723] mb-6 tracking-wide">
              Owner Approval Status Tracker and History:
            </h2>

            <!-- Tracker List Flex Container -->
            <div id="trackerList" class="flex flex-col gap-5">

              <?php if (empty($myReports)): ?>
              <!-- Default Demo/Sample Tracker Item matching the exact prompt specification -->
              <div class="flex flex-col gap-3 rounded-2xl border-2 border-[#8C6D43]/40 bg-white/60 p-5 sm:p-6 shadow-sm">
                <!-- Top row: Request Title on left, White category badge on right -->
                <div class="flex items-start justify-between gap-3 flex-wrap">
                  <h3 class="text-base sm:text-lg font-black text-[#3E2723] leading-snug m-0">
                    1. Pet Addition: Add Dog (Golden Retriever)
                  </h3>
                  <span class="flex-shrink-0 px-4 py-1.5 rounded-lg bg-white border border-[#8C6D43]/30
                               text-[#3E2723] font-black text-xs sm:text-sm shadow-sm text-center">
                    Pet Addition
                  </span>
                </div>

                <!-- Date -->
                <p class="text-xs font-bold text-[#664D36] m-0">
                  Date Submitted: Nov 14, 2026
                </p>

                <!-- Progress Bar: Filled portion Dark Brown, Empty track White -->
                <div class="mt-1">
                  <div class="w-full h-3.5 rounded-full bg-white border border-[#8C6D43]/30 overflow-hidden shadow-inner">
                    <div class="h-full rounded-full bg-[#4A3728] w-1/2 transition-all duration-500"></div>
                  </div>
                  <p class="text-xs font-black text-[#4A3728] mt-1.5 text-right m-0">
                    Pending Owner Approval
                  </p>
                </div>

                <!-- Request Details and Description: Label above White read-only box -->
                <div class="mt-2">
                  <label class="block text-xs font-black text-[#3E2723] mb-1.5">
                    Request Details and Description:
                  </label>
                  <div class="w-full rounded-xl border border-[#8C6D43]/40 bg-white p-4 text-sm font-medium text-[#3E2723] min-h-[80px] shadow-inner leading-relaxed">
                    Requesting approval to keep a trained Golden Retriever in Unit 1. All necessary vaccination documents and care arrangements are complete and ready for review.
                  </div>
                </div>
              </div>
              <?php else: ?>
              <?php foreach ($myReports as $idx => $rep):
                $repStatus    = trim((string)($rep['status'] ?? 'Pending'));
                $repCategory  = htmlspecialchars($rep['category'] ?? 'General Request');
                $repSubject   = htmlspecialchars($rep['subject'] ?? 'Untitled Request');
                $repDetails   = htmlspecialchars($rep['details'] ?? ($rep['description'] ?? ''));
                $repDate      = !empty($rep['created_at']) ? date('M d, Y', strtotime($rep['created_at'])) : date('M d, Y');
                $repOwnerNote = htmlspecialchars(trim((string)($rep['owner_remarks'] ?? '')));
                $repNum       = $idx + 1;

                $stLower = strtolower($repStatus);
                $barPct  = match($stLower) {
                  'approved', 'done', 'resolved' => 100,
                  'in_progress', 'under review', 'ongoing' => 60,
                  'rejected', 'cancelled' => 100,
                  default => 50,
                };
                $barColor = match($stLower) {
                  'approved', 'done', 'resolved' => '#2D6A4F',
                  'rejected', 'cancelled' => '#7A1C1C',
                  default => '#4A3728',
                };
                $statusLabel = match($stLower) {
                  'pending' => 'Pending Owner Approval',
                  'approved' => 'Approved by Owner',
                  'rejected' => 'Request Declined',
                  default => ucfirst($repStatus),
                };
              ?>
              <div class="flex flex-col gap-3 rounded-2xl border-2 border-[#8C6D43]/40 bg-white/60 p-5 sm:p-6 shadow-sm">
                <!-- Top row: Request Title on left, White category badge on right -->
                <div class="flex items-start justify-between gap-3 flex-wrap">
                  <h3 class="text-base sm:text-lg font-black text-[#3E2723] leading-snug m-0">
                    <?= $repNum ?>. <?= $repCategory ?>: <?= $repSubject ?>
                  </h3>
                  <span class="flex-shrink-0 px-4 py-1.5 rounded-lg bg-white border border-[#8C6D43]/30
                               text-[#3E2723] font-black text-xs sm:text-sm shadow-sm text-center">
                    <?= $repCategory ?>
                  </span>
                </div>

                <!-- Date -->
                <p class="text-xs font-bold text-[#664D36] m-0">
                  Date Submitted: <?= $repDate ?>
                </p>

                <!-- Progress Bar: Filled portion Dark Brown, Empty track White -->
                <div class="mt-1">
                  <div class="w-full h-3.5 rounded-full bg-white border border-[#8C6D43]/30 overflow-hidden shadow-inner">
                    <div class="h-full rounded-full transition-all duration-500" style="width: <?= $barPct ?>%; background-color: <?= $barColor ?>;"></div>
                  </div>
                  <p class="text-xs font-black mt-1.5 text-right m-0" style="color: <?= $barColor ?>;">
                    <?= $statusLabel ?>
                  </p>
                </div>

                <?php if (!empty($repOwnerNote)): ?>
                <div class="p-3 rounded-xl bg-white/90 border border-[#8C6D43]/30 text-xs font-semibold text-[#4A3728]">
                  <span class="font-black">Owner Remark:</span> <?= $repOwnerNote ?>
                </div>
                <?php endif; ?>

                <!-- Request Details and Description: Label above White read-only box -->
                <div class="mt-2">
                  <label class="block text-xs font-black text-[#3E2723] mb-1.5">
                    Request Details and Description:
                  </label>
                  <div class="w-full rounded-xl border border-[#8C6D43]/40 bg-white p-4 text-sm font-medium text-[#3E2723] min-h-[80px] shadow-inner leading-relaxed whitespace-pre-wrap">
                    <?= !empty($repDetails) ? $repDetails : '<span class="text-[#9E835F] italic">No description provided.</span>' ?>
                  </div>
                </div>
              </div>
              <?php endforeach; ?>
              <?php endif; ?>

            </div>
          </div>
        </div>

        <!-- ── Vanilla JavaScript for Tab Switching & Upload Handling ── -->
        <script>
        /**
         * switchApprovalTab:
         * Toggles the 'hidden' class between the two content sections.
         * Dynamically swaps styles so active tab seamlessly connects to container below.
         */
        function switchApprovalTab(tabName) {
          const tabBtnSubmit = document.getElementById('tabBtnSubmit');
          const tabBtnTracker = document.getElementById('tabBtnTracker');
          const tabPaneSubmit = document.getElementById('tabPaneSubmit');
          const tabPaneTracker = document.getElementById('tabPaneTracker');

          if (!tabBtnSubmit || !tabBtnTracker || !tabPaneSubmit || !tabPaneTracker) return;

          if (tabName === 'submit') {
            // Show Submit pane, hide Tracker pane
            tabPaneSubmit.classList.remove('hidden');
            tabPaneTracker.classList.add('hidden');

            // Style Submit Tab as Active (Medium Brown seamless connect)
            tabBtnSubmit.setAttribute('aria-selected', 'true');
            tabBtnSubmit.className = "relative z-10 px-6 py-3 rounded-t-2xl font-black text-sm sm:text-base tracking-wide bg-[#B89B72] text-[#261909] border-2 border-b-0 border-[#7C5A36] shadow-[0_-2px_6px_rgba(74,55,40,0.1)] transition-all cursor-pointer focus:outline-none focus:ring-2 focus:ring-[#4A3728]";
            tabBtnSubmit.style.marginBottom = "-2px";

            // Style Tracker Tab as Inactive (Light Beige)
            tabBtnTracker.setAttribute('aria-selected', 'false');
            tabBtnTracker.className = "relative z-0 px-6 py-2.5 rounded-t-2xl font-bold text-sm sm:text-base tracking-wide bg-[#E8D8B0] text-[#5C3D1E] border-2 border-[#8C6D43] hover:bg-[#DFCE9F] transition-all cursor-pointer focus:outline-none focus:ring-2 focus:ring-[#4A3728]";
            tabBtnTracker.style.marginBottom = "-2px";

          } else if (tabName === 'tracker') {
            // Show Tracker pane, hide Submit pane
            tabPaneTracker.classList.remove('hidden');
            tabPaneSubmit.classList.add('hidden');

            // Style Tracker Tab as Active (Light Beige seamless connect)
            tabBtnTracker.setAttribute('aria-selected', 'true');
            tabBtnTracker.className = "relative z-10 px-6 py-3 rounded-t-2xl font-black text-sm sm:text-base tracking-wide bg-[#E8D8B0] text-[#261909] border-2 border-b-0 border-[#8C6D43] shadow-[0_-2px_6px_rgba(74,55,40,0.1)] transition-all cursor-pointer focus:outline-none focus:ring-2 focus:ring-[#4A3728]";
            tabBtnTracker.style.marginBottom = "-2px";

            // Style Submit Tab as Inactive (Medium Brown recessed)
            tabBtnSubmit.setAttribute('aria-selected', 'false');
            tabBtnSubmit.className = "relative z-0 px-6 py-2.5 rounded-t-2xl font-bold text-sm sm:text-base tracking-wide bg-[#B89B72]/70 text-[#261909]/80 border-2 border-[#7C5A36] hover:bg-[#B89B72] transition-all cursor-pointer focus:outline-none focus:ring-2 focus:ring-[#4A3728]";
            tabBtnSubmit.style.marginBottom = "-2px";
          }
        }

        /**
         * Handle file selection preview chips
         */
        function handleRequestFileSelect(input) {
          const preview = document.getElementById('requestFilePreview');
          if (!preview) return;
          preview.innerHTML = '';
          if (input.files && input.files.length > 0) {
            Array.from(input.files).forEach(f => {
              const chip = document.createElement('span');
              chip.className = 'inline-flex items-center gap-1.5 px-3 py-1 rounded-full bg-[#4A3728] text-white text-xs font-bold shadow-sm';
              chip.textContent = '📎 ' + (f.name.length > 25 ? f.name.substring(0, 22) + '...' : f.name);
              preview.appendChild(chip);
            });
          }
        }

        // Form Submission with Feedback & Redirection to Tracker
        document.addEventListener('DOMContentLoaded', () => {
          const form = document.getElementById('approvalForm');
          if (!form) return;

          form.addEventListener('submit', async (e) => {
            e.preventDefault();
            const btn = document.getElementById('btnSubmitApproval');
            if (btn) {
              btn.disabled = true;
              btn.textContent = 'Submitting...';
            }

            try {
              const formData = new FormData(form);
              const catVal = document.getElementById('requestCategory') ? document.getElementById('requestCategory').value : '';
              const specVal = document.getElementById('requestSpecification') ? document.getElementById('requestSpecification').value : '';
              const descVal = document.getElementById('requestDescription') ? document.getElementById('requestDescription').value : '';

              formData.set('action', 'submit_report');
              formData.set('request_category', catVal);
              formData.set('request_specification', specVal);
              formData.set('request_description', descVal);
              formData.set('category', catVal);
              formData.set('subject', specVal);
              formData.set('specification', specVal);
              formData.set('details', descVal);
              formData.set('description', descVal);

              const fileInput = document.getElementById('requestAttachments');
              if (fileInput && fileInput.files.length > 0) {
                for (let i = 0; i < fileInput.files.length; i++) {
                  formData.append('request_attachments[]', fileInput.files[i]);
                  formData.append('report_attachments[]', fileInput.files[i]);
                }
              }

              const res = await fetch('tenant_portal.php?section=tenantReport', {
                method: 'POST',
                headers: {
                  'X-Requested-With': 'XMLHttpRequest',
                  'Accept': 'application/json'
                },
                body: formData
              });
              const data = await res.json();

              if (data.status === 'success' || data.success) {
                if (typeof showToast === 'function') {
                  showToast('Request submitted successfully! Awaiting owner approval.', 'success');
                } else {
                  alert('Request submitted successfully!');
                }
                form.reset();
                const previewEl = document.getElementById('requestFilePreview');
                if (previewEl) previewEl.innerHTML = '';
                switchApprovalTab('tracker');
                setTimeout(() => {
                  window.location.href = 'tenant_portal.php?section=tenantReport&tab=tracker&success=report_submitted';
                }, 800);
              } else {
                if (typeof showToast === 'function') {
                  showToast(data.message || 'Failed to submit request.', 'error');
                } else {
                  alert(data.message || 'Failed to submit request.');
                }
              }
            } catch (err) {
              // Fallback to standard form submission if AJAX is interrupted
              form.submit();
            } finally {
              if (btn) {
                btn.disabled = false;
                btn.textContent = 'Submit Request';
              }
            }
          });
        });
        </script>

      </section>

      <!-- CATEGORY 5: RESIDENT PROFILE (VIEW WITH INTERNAL SUB-SIDEBAR) -->
      <section id="tenantProfile" class="app-view-section">
        <div class="dashboard-header-bar flex flex-col sm:flex-row sm:items-center justify-between gap-4 mb-6">
          <div class="dashboard-title-group">
            <h1 class="text-2xl md:text-3xl font-black text-[#3E2723] tracking-wide mb-1">Resident Profile &amp; Settings</h1>
            <p class="text-sm md:text-base font-semibold text-[#7A6245] m-0">Manage your account information, residency verification, and login security.</p>
          </div>
        </div>

        <div class="profile-view-container">
          <!-- Internal Sub-Sidebar with exactly two options (30% Secondary Medium Clay bg-[#B89B72]) -->
          <div class="profile-sub-sidebar bg-[#B89B72]/15 border-2 border-[#B89B72] rounded-2xl p-3 flex flex-col gap-2">
            <button type="button" class="profile-subnav-item active flex items-center gap-3 px-4 py-3 rounded-xl font-bold text-sm bg-[#B89B72] text-white border-2 border-[#8C6D43] transition-all cursor-pointer" id="tenantSubnavAccount" onclick="switchProfileSubTab('accountInfo', this)">
              <span class="subnav-icon">👤</span>
              <span>Account Information</span>
            </button>
            <button type="button" class="profile-subnav-item flex items-center gap-3 px-4 py-3 rounded-xl font-bold text-sm text-[#3E2723] hover:bg-[#B89B72]/20 border-2 border-transparent transition-all cursor-pointer" id="tenantSubnavPassword" onclick="switchProfileSubTab('changePassword', this)">
              <span class="subnav-icon">🔒</span>
              <span>Change Password</span>
            </button>
          </div>

          <!-- Sub-Tab Content Area -->
          <div class="profile-content-area">
            
            <!-- Sub-Tab 1: Account Information -->
            <div id="paneAccountInfo" class="profile-tab-pane active-pane">
              <!-- Verification Notification Bar inside Account Information (6s duration, Soft Red Warning / Soft Green Success) -->
              <div id="accountInfoVerificationAlert" class="account-info-verification-alert" style="display: none; margin-bottom: 22px; opacity: 1; transition: opacity 0.5s ease, transform 0.5s ease;"></div>

              <div class="bg-white/95 border-2 border-[#B89B72] rounded-2xl p-6 sm:p-8 shadow-md">
                <div class="flex justify-between items-center mb-6 flex-wrap gap-3 pb-4 border-b border-dashed border-[#8C6D43]/30">
                  <div>
                    <h2 class="text-xl sm:text-2xl font-black text-[#3E2723] tracking-wide m-0">Account Information</h2>
                    <p class="text-xs sm:text-sm font-semibold text-[#7A6245] mt-1 mb-0">Personal records and unit assignment details</p>
                  </div>
                  <div class="flex items-center gap-2.5 flex-wrap">
                    <button type="button" class="px-4 py-2 rounded-xl text-xs sm:text-sm font-bold bg-[#F5F0E6] text-[#3E2723] border border-[#8C6D43] hover:bg-[#E8D8B0] shadow-sm transition-colors cursor-pointer" onclick="openEditAccountInfoModal()">
                      ✏️ Edit Information
                    </button>
                    <?php if (($user['verification_status'] ?? 'unverified') !== 'verified'): ?>
                      <?php if (($user['verification_status'] ?? '') === 'pending'): ?>
                        <span class="bg-amber-100 text-amber-900 border border-amber-300 font-bold text-xs px-3.5 py-1.5 rounded-full shadow-sm flex items-center gap-1.5">
                          <span>⏳</span> Pending Review
                        </span>
                      <?php else: ?>
                        <span class="bg-red-100 text-red-900 border border-red-300 font-bold text-xs px-3.5 py-1.5 rounded-full shadow-sm flex items-center gap-1.5">
                          <span>⚠️</span> Unverified Account
                        </span>
                      <?php endif; ?>
                    <?php endif; ?>
                  </div>
                </div>

                <!-- Profile Picture Customization Card (60% Dominant Light Tan bg-[#F5F0E6], 30% Secondary border-[#B89B72]) -->
                <form id="profilePictureForm" method="POST" action="api/profile_api.php" enctype="multipart/form-data" class="flex items-center gap-6 p-5 rounded-2xl bg-[#F5F0E6] border border-[#B89B72] mb-6 flex-wrap shadow-sm">
                  <input type="hidden" name="action" value="upload_profile_picture">
                  <input type="hidden" name="user_id" value="<?= (int)($user['id'] ?? 0) ?>">

                  <label for="profile_picture" class="avatar-preview-wrapper relative w-24 h-24 flex-shrink-0 cursor-pointer block" title="Click photo to choose new picture">
                    <img id="avatarPreviewImg" src="<?= !empty($userAvatarUrl) ? $userAvatarUrl : '' ?>" alt="Profile Preview" style="width: 96px; height: 96px; border-radius: 50%; object-fit: cover; border: 3px solid #8C6D43; box-shadow: 0 4px 14px rgba(74, 55, 40, 0.2); display: <?= !empty($userAvatarUrl) ? 'block' : 'none' ?>;">
                    <div id="avatarFallbackInitial" style="width: 96px; height: 96px; border-radius: 50%; background: #B89B72; color: #ffffff; display: <?= !empty($userAvatarUrl) ? 'none' : 'flex' ?>; align-items: center; justify-content: center; font-size: 38px; font-weight: 800; border: 3px solid #8C6D43; box-shadow: 0 4px 14px rgba(74, 55, 40, 0.2);">
                      <?= htmlspecialchars($userInitial) ?>
                    </div>
                    <span class="absolute bottom-0 right-0 w-7 h-7 rounded-full bg-[#4A3728] text-white flex items-center justify-center border-2 border-white shadow-sm text-xs">
                      📷
                    </span>
                  </label>

                  <div class="avatar-info-controls flex-1 min-w-[220px]">
                    <h3 class="text-base font-black text-[#3E2723] m-0 mb-1">Profile Picture</h3>
                    <p class="text-xs font-semibold text-[#7A6245] m-0 mb-3.5">
                      Click the avatar or choose a photo (JPEG, PNG, WEBP, maximum 5MB). This avatar will be visible on your tenant dashboard and messages.
                    </p>

                    <input type="file" id="profile_picture" name="profile_picture" accept="image/*" class="hidden" style="display: none;" onchange="previewProfilePicture(event)">

                    <div class="flex items-center gap-2.5 flex-wrap">
                      <button type="button" class="px-4 py-2 rounded-xl text-xs sm:text-sm font-bold bg-[#4A3728] hover:bg-[#382618] text-white border border-[#382618] shadow-sm transition-colors cursor-pointer" id="btnChooseAvatar" onclick="triggerProfilePictureInput()">
                        📷 Choose Photo
                      </button>

                      <div id="avatarPendingActions" style="display: none; align-items: center; gap: 8px;">
                        <button type="button" class="px-4 py-2 rounded-xl text-xs sm:text-sm font-bold bg-green-600 hover:bg-green-700 text-white shadow-sm transition-colors cursor-pointer inline-flex items-center gap-1.5" id="btnSaveAvatar" onclick="submitProfilePictureUpload()">
                          <i data-lucide="check" style="width: 15px; height: 15px;"></i> Save Changes
                        </button>
                        <button type="button" class="px-3.5 py-2 rounded-xl text-xs sm:text-sm font-bold bg-[#F5F0E6] text-[#3E2723] border border-[#8C6D43] hover:bg-[#E8D8B0] shadow-sm transition-colors cursor-pointer inline-flex items-center gap-1.5" id="btnCancelAvatar" onclick="cancelProfilePictureSelection()">
                          Cancel
                        </button>
                      </div>

                      <button type="button" class="px-3.5 py-2 rounded-xl text-xs sm:text-sm font-bold text-red-600 bg-red-50 hover:bg-red-100 border border-red-200 transition-colors cursor-pointer inline-flex items-center gap-1.5" id="btnRemoveAvatar" style="display: <?= !empty($userAvatarUrl) ? 'inline-flex' : 'none' ?>;" onclick="confirmRemoveProfilePicture()">
                        <i data-lucide="trash-2" style="width: 15px; height: 15px;"></i> Remove Photo
                      </button>
                    </div>
                  </div>
                </form>

                <div class="detail-grid grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4 mb-6">
                  <div class="p-3.5 rounded-xl bg-[#F5F0E6] border border-[#8C6D43]/30">
                    <span class="block text-xs font-bold text-[#7A6245]">Full Name:</span>
                    <span class="block text-sm sm:text-base font-extrabold text-[#3E2723] mt-0.5" id="profileFullName"><?= htmlspecialchars($user['name']) ?></span>
                  </div>
                  <div class="p-3.5 rounded-xl bg-[#F5F0E6] border border-[#8C6D43]/30">
                    <span class="block text-xs font-bold text-[#7A6245]">Username:</span>
                    <span class="block text-sm sm:text-base font-extrabold text-[#3E2723] mt-0.5"><?= htmlspecialchars($user['username']) ?></span>
                  </div>
                  <div class="p-3.5 rounded-xl bg-[#F5F0E6] border border-[#8C6D43]/30">
                    <span class="block text-xs font-bold text-[#7A6245]">Assigned Unit:</span>
                    <span class="block text-sm sm:text-base font-extrabold text-[#3E2723] mt-0.5"><?= htmlspecialchars($unit['unit_number']) ?></span>
                  </div>
                  <div class="p-3.5 rounded-xl bg-[#F5F0E6] border border-[#8C6D43]/30">
                    <span class="block text-xs font-bold text-[#7A6245]">Monthly Rent:</span>
                    <span class="block text-sm sm:text-base font-extrabold text-[#3E2723] mt-0.5">₱<?= number_format((float)$unit['monthly_rent'], 2) ?></span>
                  </div>
                  <div class="p-3.5 rounded-xl bg-[#F5F0E6] border border-[#8C6D43]/30">
                    <span class="block text-xs font-bold text-[#7A6245]">Email Address:</span>
                    <span class="block text-sm sm:text-base font-extrabold text-[#3E2723] mt-0.5"><?= htmlspecialchars($user['email'] ?? 'Not set') ?></span>
                  </div>
                  <div class="p-3.5 rounded-xl bg-[#F5F0E6] border border-[#8C6D43]/30">
                    <span class="block text-xs font-bold text-[#7A6245]">Phone Number:</span>
                    <span class="block text-sm sm:text-base font-extrabold text-[#3E2723] mt-0.5"><?= htmlspecialchars($user['phone'] ?? 'Not set') ?></span>
                  </div>
                  <div class="p-3.5 rounded-xl bg-[#F5F0E6] border border-[#8C6D43]/30">
                    <span class="block text-xs font-bold text-[#7A6245]">Emergency Contact:</span>
                    <span class="block text-sm sm:text-base font-extrabold text-[#3E2723] mt-0.5"><?= htmlspecialchars($user['emergency_contact_name'] ?? $user['emergency_contact'] ?? 'Not set') ?></span>
                  </div>
                  <div class="p-3.5 rounded-xl bg-[#F5F0E6] border border-[#8C6D43]/30">
                    <span class="block text-xs font-bold text-[#7A6245]">Emergency Phone:</span>
                    <span class="block text-sm sm:text-base font-extrabold text-[#3E2723] mt-0.5"><?= htmlspecialchars($user['emergency_contact_phone'] ?? 'Not set') ?></span>
                  </div>
                  <div class="p-3.5 rounded-xl bg-[#F5F0E6] border border-[#8C6D43]/30">
                    <span class="block text-xs font-bold text-[#7A6245]">Date of Birth:</span>
                    <span class="block text-sm sm:text-base font-extrabold text-[#3E2723] mt-0.5">
                      <?= !empty($user['birthday']) ? date('F j, Y', strtotime($user['birthday'])) : 'Not set' ?>
                    </span>
                  </div>
                  <div class="p-3.5 rounded-xl bg-[#F5F0E6] border border-[#8C6D43]/30">
                    <span class="block text-xs font-bold text-[#7A6245]">Role:</span>
                    <span class="block text-sm sm:text-base font-extrabold text-[#3E2723] mt-0.5 capitalize"><?= htmlspecialchars($user['role']) ?></span>
                  </div>
                </div>

                <?php if (($user['verification_status'] ?? 'unverified') !== 'verified'): ?>
                <!-- Verification Action / Status Container -->
                <div id="accountVerificationSection" class="mt-6 pt-5 border-t border-dashed border-[#8C6D43]/30">
                  <?php if (($user['verification_status'] ?? 'unverified') === 'unverified'): ?>
                    <div class="p-5 rounded-2xl bg-rose-50 border border-rose-200">
                      <div class="flex items-center justify-between flex-wrap gap-4">
                        <div>
                          <?php if (!empty($user['verification_notes'])): ?>
                            <div class="flex items-center gap-2 mb-1">
                              <span class="text-xl text-rose-600 font-bold">✕</span>
                              <div class="text-sm font-extrabold text-rose-900">Identity Verification Failed — Account Remains Unverified</div>
                            </div>
                            <div class="text-xs text-rose-800 mb-1.5">
                              <?= htmlspecialchars(str_replace('Rejected: ', '', $user['verification_notes'])) ?>
                            </div>
                            <div class="text-xs text-rose-900 font-semibold">
                              Please re-verify your account with a clear government-issued ID to unlock resident features.
                            </div>
                          <?php else: ?>
                            <div class="text-sm font-extrabold text-rose-900 mb-1">Identity Verification Required</div>
                            <div class="text-xs text-rose-800">Verify your account with a government ID to unlock your rental dashboard capabilities.</div>
                          <?php endif; ?>
                        </div>
                        <button type="button" class="px-5 py-2.5 rounded-xl font-black text-xs sm:text-sm bg-[#4A3728] hover:bg-[#382618] text-white shadow-md transition-colors cursor-pointer" onclick="goToAccountVerification()">
                          <?= !empty($user['verification_notes']) ? 'Re-Verify Account' : 'Verify Account' ?>
                        </button>
                      </div>
                    </div>
                  <?php elseif (($user['verification_status'] ?? '') === 'pending'): ?>
                    <div class="p-5 rounded-2xl bg-amber-50 border border-amber-200">
                      <div class="flex items-center justify-between flex-wrap gap-4">
                        <div>
                          <div class="text-sm font-extrabold text-amber-900 mb-1">Verification Under Review</div>
                          <div class="text-xs text-amber-800">Your submitted documents are currently being audited by management. Full functionality will be enabled upon approval.</div>
                        </div>
                        <span class="bg-amber-100 text-amber-900 border border-amber-300 font-bold text-xs px-3.5 py-1.5 rounded-full shadow-sm flex items-center gap-1.5">
                          <span>⏳</span> In Review
                        </span>
                      </div>
                    </div>
                  <?php endif; ?>
                </div>
                <?php endif; ?>
                <?php if (($user['verification_status'] ?? 'unverified') === 'verified'): ?>
                <!-- Verified Tenant ID Card & Update Trigger -->
                <div id="verifiedAccountSection" class="mt-6 pt-5 border-t border-dashed border-[#8C6D43]/30">
                  <div class="p-5 rounded-2xl bg-green-50 border border-green-200">
                    <div class="flex items-center justify-between flex-wrap gap-4">
                      <div>
                        <div class="flex items-center gap-2 mb-1">
                          <span class="w-5 h-5 rounded-full bg-green-600 text-white flex items-center justify-center text-xs font-bold">✓</span>
                          <div class="text-sm font-extrabold text-green-900">Identity Verified Resident</div>
                        </div>
                        <div class="text-xs text-green-800">
                          Verified with <?= htmlspecialchars($user['id_type'] ?? 'Government ID') ?>
                          <?= !empty($user['id_verified_at']) ? ' on ' . date('F j, Y', strtotime($user['id_verified_at'])) : '' ?>.
                        </div>
                      </div>
                      <button type="button" class="px-4 py-2 rounded-xl text-xs sm:text-sm font-bold bg-white text-green-800 border border-green-600 hover:bg-green-50 shadow-sm transition-colors cursor-pointer inline-flex items-center gap-1.5" id="btnUpdateId" onclick="openVerifyAccountModal(true)">
                        <i data-lucide="refresh-cw" class="w-4 h-4"></i>
                        <span>Update ID Document</span>
                      </button>
                    </div>
                  </div>
                </div>
                <?php endif; ?>
              </div>
            </div>

            <!-- Sub-Tab 2: Change Password -->
            <div id="paneChangePassword" class="profile-tab-pane">
              <div class="bg-white/95 border-2 border-[#B89B72] rounded-2xl p-6 sm:p-8 shadow-md max-w-[600px]">
                <div class="mb-6 pb-4 border-b border-dashed border-[#8C6D43]/30">
                  <h2 class="text-xl sm:text-2xl font-black text-[#3E2723] tracking-wide m-0">Change Password</h2>
                  <p class="text-xs sm:text-sm font-semibold text-[#7A6245] mt-1 mb-0">Keep your account secure by updating your password regularly</p>
                </div>

                <form id="changePasswordForm" class="flex flex-col gap-4">
                  <div class="flex flex-col gap-1.5">
                    <label class="font-bold text-xs sm:text-sm text-[#3E2723]" for="profCurrentPass">Current Password <span class="text-red-600">*</span></label>
                    <input type="password" id="profCurrentPass" class="w-full h-11 px-3.5 py-2.5 rounded-lg bg-[#F5F0E6] text-[#3E2723] font-semibold text-sm border border-[#B89B72] focus:outline-none focus:ring-2 focus:ring-[#4A3728] shadow-sm" placeholder="Enter existing password" required autocomplete="current-password">
                  </div>

                  <div class="flex flex-col gap-1.5">
                    <label class="font-bold text-xs sm:text-sm text-[#3E2723]" for="profNewPass">New Password <span class="text-red-600">*</span></label>
                    <input type="password" id="profNewPass" class="w-full h-11 px-3.5 py-2.5 rounded-lg bg-[#F5F0E6] text-[#3E2723] font-semibold text-sm border border-[#B89B72] focus:outline-none focus:ring-2 focus:ring-[#4A3728] shadow-sm" placeholder="At least 6 characters" minlength="6" required autocomplete="new-password">
                  </div>

                  <div class="flex flex-col gap-1.5">
                    <label class="font-bold text-xs sm:text-sm text-[#3E2723]" for="profConfirmPass">Confirm New Password <span class="text-red-600">*</span></label>
                    <input type="password" id="profConfirmPass" class="w-full h-11 px-3.5 py-2.5 rounded-lg bg-[#F5F0E6] text-[#3E2723] font-semibold text-sm border border-[#B89B72] focus:outline-none focus:ring-2 focus:ring-[#4A3728] shadow-sm" placeholder="Confirm new password" minlength="6" required autocomplete="new-password">
                  </div>

                  <div class="mt-4">
                    <button type="submit" class="min-w-[180px] py-3 px-6 rounded-xl bg-[#4A3728] hover:bg-[#382618] text-white font-black text-sm sm:text-base shadow-md border border-[#382618] transition-all cursor-pointer" id="btnSavePassword">
                      Update Password
                    </button>
                  </div>
                </form>
              </div>
            </div>

          </div>
        </div>
      </section>

      </div> <!-- /.tenant-content-container -->

    </main>

  </div>

  <!-- =====================================================================
       MODAL: EDIT TENANT ACCOUNT INFORMATION
       Allows tenant to update phone, email, and emergency contact details
       ===================================================================== -->
  <div class="modal-backdrop" id="editAccountInfoModal" style="display: none; pointer-events: none;">
    <div class="clay-modal bg-[#FCFBF9] border-2 border-[#B89B72] rounded-2xl p-6 sm:p-8 shadow-2xl" style="max-width: 500px;">
      <div class="flex justify-between items-center mb-5 pb-3 border-b border-dashed border-[#8C6D43]/30">
        <div>
          <h2 class="text-xl font-black text-[#3E2723] m-0 mb-0.5">Edit Account Information</h2>
          <p class="text-xs font-semibold text-[#7A6245] m-0">Update your contact details and emergency contact records</p>
        </div>
        <button type="button" class="modal-close-btn text-[#4A3728] hover:text-[#382618] p-1.5 rounded-lg hover:bg-[#4A3728]/10 transition-colors cursor-pointer" onclick="closeModal('editAccountInfoModal')" title="Close" aria-label="Close"><i data-lucide="x" class="w-5 h-5" aria-hidden="true"></i></button>
      </div>

      <form id="editAccountInfoForm">
        <div class="form-group mb-3.5">
          <label class="font-bold text-xs text-[#3E2723] block mb-1.5" for="editEmail">Email Address</label>
          <input type="email" id="editEmail" name="email" class="w-full h-10 px-3.5 py-2 rounded-lg bg-[#F5F0E6] text-[#3E2723] font-semibold text-sm border border-[#B89B72] focus:outline-none focus:ring-2 focus:ring-[#4A3728] shadow-sm" value="<?= htmlspecialchars($user['email'] ?? '') ?>" placeholder="your.email@example.com">
        </div>

        <div class="form-group mb-4">
          <label class="font-bold text-xs text-[#3E2723] block mb-1.5" for="editPhone">Phone Number</label>
          <input type="tel" id="editPhone" name="phone" class="w-full h-10 px-3.5 py-2 rounded-lg bg-[#F5F0E6] text-[#3E2723] font-semibold text-sm border border-[#B89B72] focus:outline-none focus:ring-2 focus:ring-[#4A3728] shadow-sm" value="<?= htmlspecialchars($user['phone'] ?? '') ?>" placeholder="+63 900 000 0000">
        </div>

        <div class="bg-[#F5F0E6] border border-[#B89B72] rounded-xl p-4 shadow-sm mb-4">
          <div class="text-xs font-black text-[#3E2723] mb-2.5 flex items-center gap-1.5">
            <i data-lucide="phone-call" class="w-4 h-4 text-rose-600" aria-hidden="true"></i> Emergency Contact Details
          </div>
          <div class="form-group mb-2.5">
            <label class="font-semibold text-[11px] text-[#7A6245] block mb-1" for="editEmergencyName">Contact Person's Full Name</label>
            <input type="text" id="editEmergencyName" name="emergency_contact_name" class="w-full h-9 px-3 py-1.5 rounded-lg bg-white text-[#3E2723] font-semibold text-xs border border-[#B89B72] focus:outline-none focus:ring-1 focus:ring-[#4A3728] shadow-sm" value="<?= htmlspecialchars($user['emergency_contact_name'] ?? $user['emergency_contact'] ?? '') ?>" placeholder="e.g. Maria Santos">
          </div>
          <div class="grid grid-cols-2 gap-2.5">
            <div class="form-group mb-0">
              <label class="font-semibold text-[11px] text-[#7A6245] block mb-1" for="editEmergencyRel">Relationship</label>
              <input type="text" id="editEmergencyRel" name="emergency_contact_relationship" class="w-full h-9 px-3 py-1.5 rounded-lg bg-white text-[#3E2723] font-semibold text-xs border border-[#B89B72] focus:outline-none focus:ring-1 focus:ring-[#4A3728] shadow-sm" value="<?= htmlspecialchars($user['emergency_contact_relationship'] ?? '') ?>" placeholder="e.g. Mother, Spouse">
            </div>
            <div class="form-group mb-0">
              <label class="font-semibold text-[11px] text-[#7A6245] block mb-1" for="editEmergencyPhone">Contact Number</label>
              <input type="tel" id="editEmergencyPhone" name="emergency_contact_phone" class="w-full h-9 px-3 py-1.5 rounded-lg bg-white text-[#3E2723] font-semibold text-xs border border-[#B89B72] focus:outline-none focus:ring-1 focus:ring-[#4A3728] shadow-sm" value="<?= htmlspecialchars($user['emergency_contact_phone'] ?? '') ?>" placeholder="+63 900 000 0000">
            </div>
          </div>
        </div>

        <div class="flex gap-3 mt-5">
          <button type="button" class="flex-1 py-2.5 px-4 rounded-xl font-bold text-sm bg-[#F5F0E6] text-[#3E2723] border border-[#8C6D43] hover:bg-[#E8D8B0] shadow-sm transition-colors cursor-pointer" onclick="closeEditAccountInfoModal()">Cancel</button>
          <button type="submit" class="flex-[2] py-2.5 px-5 rounded-xl font-black text-sm bg-[#4A3728] hover:bg-[#382618] text-white border border-[#382618] shadow-md transition-colors cursor-pointer" id="btnSaveAccountInfo">Save Information</button>
        </div>
      </form>
    </div>
  </div>

  <!-- =====================================================================
       MODAL: TARARENT ACTION REQUIRED - ACCOUNT VERIFICATION PROMPT
       Appears when an unverified tenant attempts to submit Maintenance, Report, or Payment
       ===================================================================== -->
  <div class="modal-backdrop" id="verificationActionPromptModal" style="display: none; pointer-events: none; align-items: center; justify-content: center; z-index: 10020;" onclick="if (event.target === this) closeVerificationActionPrompt();">
    <div class="clay-modal" style="max-width: 500px; width: 92%; border: 1.5px solid #56380E; border-radius: 20px; background: #FCFBF9; box-shadow: 4px 6px 16px rgba(42, 24, 6, 0.28); padding: 24px;" onclick="event.stopPropagation()">
      <div style="display: flex; justify-content: space-between; align-items: flex-start; gap: 12px; margin-bottom: 16px;">
        <div style="display: flex; align-items: center; gap: 12px;">
          <div style="width: 44px; height: 44px; border-radius: 12px; background: linear-gradient(135deg, #fee2e2, #fecaca); border: 1.5px solid #b91c1c; display: flex; align-items: center; justify-content: center; color: #b91c1c; flex-shrink: 0;">
            <i data-lucide="alert-triangle" class="w-6 h-6" aria-hidden="true"></i>
          </div>
          <div>
            <h2 style="font-size: 19px; font-weight: 800; color: #56380E; margin: 0; line-height: 1.2;">Action Required</h2>
            <p style="font-size: 12.5px; color: #8C857B; margin: 3px 0 0 0; font-weight: 600;">Account Verification Needed</p>
          </div>
        </div>
        <button type="button" class="modal-close-btn btn-remove-file" onclick="closeModal('verificationActionPromptModal')" title="Close" aria-label="Close" style="background: rgba(86, 56, 14, 0.08); border: 1px solid rgba(86, 56, 14, 0.2); border-radius: 50%; width: 36px; height: 36px; display: inline-flex; align-items: center; justify-content: center; color: #56380E; cursor: pointer;">
          <i data-lucide="x" class="w-4 h-4" aria-hidden="true"></i>
        </button>
      </div>

      <div style="background: #FFFDF9; border: 1.5px solid #D9C3A5; border-radius: 14px; padding: 16px; margin-bottom: 20px; box-shadow: inset 0 1px 3px rgba(0,0,0,0.03);">
        <p style="margin: 0; font-size: 14px; font-weight: 700; color: #2B2116; line-height: 1.5;">
          Action Required: Please verify your account to proceed with this submission.
        </p>
        <p style="margin: 8px 0 0 0; font-size: 12.5px; color: #6E5A44; line-height: 1.45;">
          Your filled-out form details have been safely preserved so you won't need to re-enter anything once your verification is completed.
        </p>
      </div>

      <div style="display: flex; gap: 12px; justify-content: flex-end; flex-wrap: wrap;">
        <button type="button" class="clay-btn clay-btn-secondary" style="padding: 10px 18px; font-size: 13px; font-weight: 700;" onclick="closeVerificationActionPrompt()">
          Keep Editing
        </button>
        <button type="button" class="clay-btn" id="btnVerifyAccountNow" style="background: linear-gradient(135deg, #b91c1c, #991b1b); color: #ffffff; border: none; border-radius: 12px; padding: 10px 20px; font-size: 13.5px; font-weight: 800; box-shadow: 0 4px 12px rgba(185, 28, 28, 0.32); cursor: pointer; display: inline-flex; align-items: center; gap: 8px;" onclick="closeVerificationActionPrompt(); openVerifyAccountModal();">
          <i data-lucide="shield-check" class="w-4 h-4" aria-hidden="true"></i>
          <span>Verify Account Now</span>
        </button>
      </div>
    </div>
  </div>

  <!-- =====================================================================
       MODAL: TENANT VIEW MAINTENANCE TICKET DETAILS & OWNER RESPONSE
       ===================================================================== -->
  <div class="modal-backdrop" id="tenantTicketModal" style="display: none; pointer-events: none;">
    <div class="clay-modal bg-[#FCFBF9] border-2 border-[#B89B72] rounded-2xl p-6 sm:p-8 shadow-2xl" style="max-width: 580px; width: 95%; max-height: 90vh; overflow-y: auto;">
      <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 18px; border-bottom: 1px dashed rgba(140, 109, 67, 0.3); padding-bottom: 12px;">
        <div style="display: flex; align-items: center; gap: 10px;">
          <span class="clay-badge" id="ttModalIdBadge" style="background: #F5F0E6; color: #4A3728; border: 1px solid #B89B72; font-weight: 800; font-size: 13px;">#MT-0001</span>
          <div>
            <h2 style="font-size: 19px; font-weight: 800; color: #3E2723; margin: 0;" id="ttModalTitle">Issue Title</h2>
            <p style="font-size: 12px; color: #7A6245; margin: 2px 0 0 0;">Maintenance request details and status tracking</p>
          </div>
        </div>
        <button type="button" class="modal-close-btn text-[#4A3728] hover:text-[#382618] p-1.5 rounded-lg hover:bg-[#4A3728]/10 transition-colors cursor-pointer" onclick="closeModal('tenantTicketModal')" title="Close" aria-label="Close"><i data-lucide="x" class="w-5 h-5" aria-hidden="true"></i></button>
      </div>

      <!-- Metadata Badges -->
      <div style="display: flex; gap: 8px; flex-wrap: wrap; margin-bottom: 16px;">
        <span class="clay-badge" id="ttModalUnit" style="background: #F5F0E6; color: #4A3728; border: 1px solid #B89B72; font-weight: 800;">Unit</span>
        <span class="clay-badge" id="ttModalCategory">Category</span>
        <span class="clay-badge" id="ttModalPriority">Priority</span>
        <span class="clay-badge" id="ttModalStatus">Status</span>
      </div>

      <!-- Full Problem Description -->
      <div style="background: #F5F0E6; border: 1px solid #B89B72; border-radius: var(--radius-md); padding: 14px 16px; margin-bottom: 16px;">
        <div style="font-size: 11px; font-weight: 700; color: #7A6245; text-transform: uppercase; margin-bottom: 6px;">Full Description of the Problem</div>
        <p id="ttModalDesc" style="font-size: 13.5px; color: #3E2723; line-height: 1.5; white-space: pre-wrap; margin: 0;"></p>
      </div>

      <!-- Attached Photos / Attachments -->
      <div id="ttModalPhotosSection" style="margin-bottom: 16px; display: none;">
        <div style="font-size: 11px; font-weight: 700; color: #7A6245; text-transform: uppercase; margin-bottom: 6px;">Attached Photos</div>
        <div id="ttModalPhotosContainer" style="display: flex; gap: 8px; flex-wrap: wrap;"></div>
      </div>

      <!-- Owner's Response / Remarks -->
      <div id="ttModalOwnerResponseSection" style="margin-bottom: 16px;">
        <div style="font-size: 11px; font-weight: 700; color: #7A6245; text-transform: uppercase; margin-bottom: 6px;">Owner's Response &amp; Remarks</div>
        <div id="ttModalOwnerResponseBox" style="background: #FFF9EE; border: 1.5px solid rgba(181, 143, 83, 0.35); border-radius: var(--radius-md); padding: 14px 16px; color: #4A3214; font-size: 13.5px; line-height: 1.5;">
          <span id="ttModalOwnerResponseText">Awaiting response from property owner.</span>
        </div>
      </div>

      <!-- Timestamps & Status Info -->
      <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 10px; font-size: 12px; color: #7A6245; border-top: 1px dashed rgba(140, 109, 67, 0.3); padding-top: 12px; margin-bottom: 18px;">
        <div>Date Submitted: <strong id="ttModalDateSubmitted" style="color: #3E2723;">-</strong></div>
        <div>Date Resolved: <span id="ttModalDateResolved" style="color: #047857; font-weight: 600;">-</span></div>
      </div>

      <div style="display: flex; justify-content: flex-end;">
        <button type="button" class="py-2.5 px-6 rounded-xl font-black text-sm bg-[#4A3728] hover:bg-[#382618] text-white border border-[#382618] shadow-md transition-colors cursor-pointer" onclick="closeTenantTicketModal()">Close</button>
      </div>
    </div>
  </div>

  <!-- =====================================================================
       MODAL: GENERAL REPORT DETAILS & OWNER DECISION (TENANT READ-ONLY)
       ===================================================================== -->
  <div class="modal-backdrop" id="tenantReportModal" style="display: none; pointer-events: none;">
    <div class="clay-modal bg-[#FCFBF9] border-2 border-[#B89B72] rounded-2xl p-6 sm:p-8 shadow-2xl" style="max-width: 560px; width: 100%; max-height: 90vh; overflow-y: auto;">
      <div style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 16px; border-bottom: 1px dashed rgba(140, 109, 67, 0.3); padding-bottom: 12px;">
        <div style="padding-right: 12px;">
          <div style="display: flex; align-items: center; gap: 8px; flex-wrap: wrap; margin-bottom: 6px;">
            <span class="clay-badge" id="trModalIdBadge" style="background: #F5F0E6; color: #4A3728; border: 1px solid #B89B72; font-weight: 800; font-size: 12px;">#GR-0001</span>
            <span class="report-status-pill report-status-pill--pending" id="trModalStatus">PENDING</span>
          </div>
          <h2 style="font-size: 19px; font-weight: 800; color: #3E2723; margin: 0;" id="trModalSubject">Report Subject</h2>
        </div>
        <button type="button" class="modal-close-btn text-[#4A3728] hover:text-[#382618] p-1.5 rounded-lg hover:bg-[#4A3728]/10 transition-colors cursor-pointer" onclick="closeModal('tenantReportModal')" title="Close" aria-label="Close"><i data-lucide="x" class="w-5 h-5" aria-hidden="true"></i></button>
      </div>

      <div style="display: flex; gap: 8px; flex-wrap: wrap; margin-bottom: 16px;">
        <span class="clay-badge" id="trModalUnit" style="background: #F5F0E6; color: #4A3728; border: 1px solid #B89B72; font-weight: 800;">Unit</span>
        <span class="clay-badge" id="trModalCategory" style="background: #F5F0E6; color: #4A3728; border: 1px solid #B89B72; font-weight: 700;">Category</span>
      </div>

      <div style="margin-bottom: 16px;">
        <div style="font-size: 11px; font-weight: 700; color: #7A6245; text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 6px;">Request Details</div>
        <div style="background: #F5F0E6; border: 1px solid #B89B72; border-radius: var(--radius-md); padding: 14px;">
          <p id="trModalDetails" style="font-size: 13.5px; color: #3E2723; line-height: 1.55; white-space: pre-wrap; margin: 0;"></p>
        </div>
      </div>

      <div id="trModalAttachmentsSection" style="margin-bottom: 16px; display: none;">
        <div style="font-size: 11px; font-weight: 700; color: #7A6245; text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 6px;">Supporting Files &amp; Photos</div>
        <div id="trModalAttachmentsContainer" style="display: flex; gap: 8px; flex-wrap: wrap;"></div>
      </div>

      <div style="margin-bottom: 16px;">
        <div style="font-size: 11px; font-weight: 700; color: #7A6245; text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 6px;">Owner Response / Remarks</div>
        <div id="trModalOwnerRemarksBox" style="background: #FFF9EE; border: 1.5px solid rgba(181, 143, 83, 0.35); border-radius: var(--radius-md); padding: 14px 16px; color: #4A3214; font-size: 13.5px; line-height: 1.5;">
          <span id="trModalOwnerRemarksText">Awaiting owner review.</span>
        </div>
      </div>

      <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 10px; font-size: 12px; color: #7A6245; border-top: 1px dashed rgba(140, 109, 67, 0.3); padding-top: 12px; margin-bottom: 18px;">
        <div>Date Submitted: <strong id="trModalDateSubmitted" style="color: #3E2723;">-</strong></div>
        <div>Reviewed At: <span id="trModalReviewedAt" style="color: #4A3728; font-weight: 700;">Pending review</span></div>
      </div>

      <div style="display: flex; justify-content: flex-end;">
        <button type="button" class="py-2.5 px-6 rounded-xl font-black text-sm bg-[#4A3728] hover:bg-[#382618] text-white border border-[#382618] shadow-md transition-colors cursor-pointer" onclick="closeTenantReportModal()">Close</button>
      </div>
    </div>
  </div>

  <!-- =====================================================================
       MODAL: TARARENT 3D IDENTITY VERIFICATION INTERFACE
       ===================================================================== -->
  <div class="modal-backdrop" id="verifyAccountModal" style="display: none; pointer-events: none; align-items: center; justify-content: center; z-index: 10000;">
    <div class="tararent-verification-modal-box relative w-full max-w-[500px] bg-[#FCFBF9] border-2 border-[#B89B72] rounded-3xl p-6 sm:p-8 shadow-2xl overflow-y-auto max-h-[92vh]" onclick="event.stopPropagation()" style="position: relative; width: 100%; max-width: 500px; background: #FCFBF9; border: 2px solid #B89B72; border-radius: 24px; padding: 28px 28px 24px; box-shadow: 0 20px 48px rgba(43, 33, 22, 0.22); box-sizing: border-box; max-height: 92vh; overflow-y: auto; pointer-events: auto;">
      <!-- Absolute Top-Right Close Button with Clean Lucide Icon -->
      <button type="button" 
              class="tararent-modal-close-btn modal-close-btn absolute top-4 right-4 sm:top-5 sm:right-5 w-9 h-9 rounded-full bg-[#4A3728]/10 hover:bg-[#4A3728]/20 text-[#4A3728] hover:text-[#382618] flex items-center justify-center transition-all cursor-pointer border-0 z-50 focus:outline-none" 
              onclick="closeModal('verifyAccountModal')" 
              title="Close" 
              aria-label="Close"
              style="position: absolute; top: 18px; right: 18px; width: 36px; height: 36px; display: flex; align-items: center; justify-content: center; border-radius: 9999px; background: rgba(74, 55, 40, 0.1); color: #4A3728; cursor: pointer; border: 1px solid rgba(184, 155, 114, 0.3); z-index: 100;">
        <i data-lucide="x" class="w-5 h-5 text-[#4A3728]" style="pointer-events: none;" aria-hidden="true"></i>
      </button>

      <!-- ================= STEP 1: DOCUMENT / ID SUBMISSION ================= -->
      <div id="verifyStep1View">
        <!-- Modal Header -->
        <div class="mb-5 pr-10" style="margin-bottom: 20px; padding-right: 40px;">
          <h2 class="text-xl font-black text-[#3E2723] m-0 mb-1.5 flex items-center gap-2.5" style="font-size: 20px; font-weight: 800; color: #3E2723; margin: 0 0 6px; display: flex; align-items: center; gap: 10px;">
            <i data-lucide="shield-check" class="w-6 h-6 text-[#4A3728]" style="color: #4A3728; width: 24px; height: 24px;" aria-hidden="true"></i>
            <span id="verifyModalTitleText">Identity Verification Required</span>
          </h2>
          <p id="verifyModalSubText" class="text-xs sm:text-sm text-[#7A6245] m-0 font-semibold leading-relaxed" style="font-size: 13px; color: #7A6245; margin: 0; line-height: 1.45; font-weight: 600;">
            To unlock all tenant features, please upload a valid Government ID to verify your identity.
          </p>
        </div>

        <!-- Alert Box inside modal -->
        <div id="verifyModalAlert" style="display: none; margin-bottom: 18px; padding: 12px 16px; border-radius: 12px; font-size: 13px; font-weight: 700; line-height: 1.4; border: 1.5px solid #fecdd3; background: #fff1f2; color: #9f1239;"></div>

        <form id="verifyAccountForm" enctype="multipart/form-data" class="flex flex-col gap-4 sm:gap-5" style="display: flex; flex-direction: column; gap: 18px;">
          <!-- 1. Select Document / ID Type -->
          <div class="form-group flex flex-col gap-1.5" style="margin: 0;">
            <label class="form-label text-xs sm:text-sm font-bold text-[#3E2723] block" for="verifyDocType" style="font-weight: 800; color: #3E2723; font-size: 13px; margin-bottom: 6px; display: block;">
              Document Type <span class="text-red-600 font-bold" style="color: #b91c1c;">*</span>
            </label>
            <select id="verifyDocType" name="id_type" class="w-full bg-[#F5F0E6] border border-[#B89B72] focus:border-[#4A3728] focus:ring-1 focus:ring-[#4A3728] rounded-xl px-3.5 py-2.5 text-sm font-semibold text-[#3E2723] shadow-sm outline-none transition-colors" style="background: #F5F0E6; border: 1.5px solid #B89B72; border-radius: 12px; padding: 11px 14px; font-size: 13.5px; font-weight: 700; color: #3E2723; width: 100%; outline: none;" required>
              <option value="" disabled selected>Select Valid Id</option>
              <optgroup label="Government Issued IDs">
                <option value="National ID">Philippine National ID (PhilSys)</option>
                <option value="Driver's License">Driver's License</option>
                <option value="Passport">Passport</option>
                <option value="UMID">UMID (Unified Multi-Purpose ID)</option>
                <option value="SSS / GSIS ID">SSS / GSIS ID</option>
                <option value="Postal ID">Postal ID</option>
                <option value="Voter's ID">Voter's ID</option>
                <option value="PRC ID">PRC ID (Professional Regulation)</option>
                <option value="PhilHealth ID">PhilHealth ID</option>
                <option value="Student ID">Student ID</option>
                <option value="Other Valid Government ID">Other Valid Government ID</option>
              </optgroup>
            </select>
          </div>

          <!-- 2. ID Upload Zone -->
          <div class="form-group flex flex-col gap-1.5" style="margin: 0;">
            <label class="form-label text-xs sm:text-sm font-bold text-[#3E2723] block" style="font-weight: 800; color: #3E2723; font-size: 13px; margin-bottom: 6px; display: block;">
              Upload Document Photo <span class="text-red-600 font-bold" style="color: #b91c1c;">*</span>
            </label>
            <div class="tararent-id-upload-zone w-full flex flex-col items-center justify-center border-2 border-dashed border-[#B89B72] hover:border-[#4A3728] rounded-2xl p-6 bg-[#F5F0E6]/60 hover:bg-[#F5F0E6] transition-all duration-200 cursor-pointer text-center group" 
                 id="idDropZone" 
                 onclick="document.getElementById('idFileInput').click()"
                 style="border: 2px dashed #B89B72; border-radius: 16px; background: rgba(245, 240, 230, 0.6); padding: 24px 16px; display: flex; flex-direction: column; align-items: center; justify-content: center; text-align: center; cursor: pointer; transition: all 0.2s ease; width: 100%; box-sizing: border-box;">
              <div class="tararent-upload-icon-circle w-12 h-12 rounded-full bg-[#4A3728]/10 flex items-center justify-center text-[#4A3728] mb-2.5 transition-transform group-hover:scale-110" style="width: 48px; height: 48px; border-radius: 9999px; background: rgba(74, 55, 40, 0.1); display: flex; align-items: center; justify-content: center; color: #4A3728; margin-bottom: 10px;">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor" class="w-6 h-6" style="width: 26px; height: 26px;">
                  <path stroke-linecap="round" stroke-linejoin="round" d="M12 16.5V9.75m0 0 3 3m-3-3-3 3M6.75 19.5a4.5 4.5 0 0 1-1.41-8.775 5.25 5.25 0 0 1 10.233-2.33 3 3 0 0 1 3.758 3.848A3.752 3.752 0 0 1 18 19.5H6.75Z" />
                </svg>
              </div>
              <div class="tararent-upload-prompt text-sm font-bold text-[#3E2723] mb-1" style="font-size: 13.5px; font-weight: 700; color: #3E2723; margin-bottom: 3px;">Click to upload or drag and drop your ID here</div>
              <div class="tararent-upload-hint text-xs text-[#7A6245] font-semibold" style="font-size: 11.5px; color: #7A6245; font-weight: 600;">Supports JPG, PNG, or WEBP (Max 10MB)</div>
            </div>
            <input type="file" id="idFileInput" name="id_photo" accept="image/jpeg,image/png,image/webp" style="display: none;" onchange="handleVerificationFileSelect(event)">

            <!-- Image Preview Card -->
            <div id="idImagePreviewCard" class="hidden rounded-xl overflow-hidden bg-white border-2 border-[#B89B72] shadow-md" style="display: none; border-radius: 14px; overflow: hidden; background: #ffffff; border: 2px solid #B89B72; box-shadow: 0 4px 12px rgba(43,26,6,0.12);">
              <div class="relative w-full h-48 bg-[#1c1206] flex items-center justify-center overflow-hidden" style="position: relative; width: 100%; height: 190px; background: #1c1206; display: flex; align-items: center; justify-content: center; overflow: hidden;">
                <img id="idVisualPreviewImg" src="" alt="Uploaded ID Preview" class="w-full h-full object-contain block" style="width: 100%; height: 100%; object-fit: contain; display: block;">
                <button type="button" 
                        class="btn-remove-attached-photo absolute top-2.5 right-2.5 bg-[#1c1206]/85 hover:bg-[#1c1206] text-white w-8 h-8 rounded-full flex items-center justify-center cursor-pointer border border-white/35 transition-colors" 
                        id="btnRemoveAttachedPhoto" 
                        onclick="removeAttachedPhoto(event)" 
                        title="Remove Attached Photo" 
                        aria-label="Remove Attached Photo" 
                        style="position: absolute; top: 10px; right: 10px; background: rgba(28, 18, 6, 0.85); color: #ffffff; width: 32px; height: 32px; border-radius: 50%; display: flex; align-items: center; justify-content: center; cursor: pointer; border: 1.5px solid rgba(255, 255, 255, 0.35); z-index: 20;">
                  <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" class="w-4 h-4 pointer-events-none" style="width: 16px; height: 16px; pointer-events: none;">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12" />
                  </svg>
                </button>
              </div>
              <div class="p-2.5 sm:p-3 bg-[#F5F0E6] flex items-center justify-between gap-2.5 border-t border-[#B89B72]/30" style="padding: 10px 14px; background: #F5F0E6; display: flex; align-items: center; justify-content: space-between; gap: 10px; border-top: 1px solid rgba(184, 155, 114, 0.3);">
                <div class="flex items-center gap-2 overflow-hidden" style="display: flex; align-items: center; gap: 8px; overflow: hidden;">
                  <span class="inline-flex text-[#4A3728]" style="display: inline-flex; color: #4A3728;"><i data-lucide="id-card" class="w-4 h-4" aria-hidden="true"></i></span>
                  <div class="overflow-hidden truncate" style="overflow: hidden; text-overflow: ellipsis; white-space: nowrap;">
                    <div id="idPreviewFileName" class="text-xs sm:text-sm font-bold text-[#3E2723] truncate" style="font-size: 13px; font-weight: 700; color: #3E2723; overflow: hidden; text-overflow: ellipsis;"></div>
                    <div id="idPreviewFileSize" class="text-[11px] text-[#7A6245] font-semibold" style="font-size: 11px; color: #7A6245; font-weight: 600;"></div>
                  </div>
                </div>
                <span class="bg-emerald-50 text-emerald-800 border border-emerald-200 text-xs px-2.5 py-1 rounded-full font-bold inline-flex items-center gap-1 shrink-0" style="background: #ecfdf5; color: #065f46; border: 1px solid #a7f3d0; font-size: 11.5px; padding: 4px 10px; font-weight: 800; display: inline-flex; align-items: center; gap: 4px;">
                  <i data-lucide="check" class="w-3.5 h-3.5" aria-hidden="true"></i> Document Attached
                </span>
              </div>
            </div>
          </div>

          <!-- 3. Consent & Privacy Acknowledgement -->
          <div class="p-3 sm:p-3.5 bg-[#F5F0E6]/70 border border-[#B89B72] rounded-xl" style="padding: 12px 14px; background: rgba(245, 240, 230, 0.7); border: 1.5px solid #B89B72; border-radius: 12px; margin: 0;">
            <label class="flex items-start gap-2.5 cursor-pointer select-none" style="display: flex; align-items: flex-start; gap: 10px; cursor: pointer; user-select: none;">
              <input type="checkbox" id="checkTermsAgreement" name="terms_accepted" value="1" class="w-4.5 h-4.5 mt-0.5 accent-[#4A3728] cursor-pointer" style="width: 18px; height: 18px; margin-top: 2px; accent-color: #4A3728; cursor: pointer;" required checked>
              <span class="text-xs sm:text-sm text-[#3E2723] leading-relaxed font-semibold" style="font-size: 12.5px; color: #3E2723; line-height: 1.45; font-weight: 600;">
                I certify that this document is valid, clear, and accurately represents my identity for apartment tenancy verification. <span class="text-red-600 font-bold" style="color: #b91c1c;">*</span>
              </span>
            </label>
          </div>

          <!-- 4. Prominent Action Button -->
          <button type="submit" id="btnSubmitVerification" class="tararent-pill-submit-btn w-full bg-[#4A3728] hover:bg-[#382618] text-white font-black text-sm py-3.5 px-6 rounded-xl border border-[#382618] flex items-center justify-center gap-2.5 cursor-pointer shadow-md hover:shadow-lg transition-all duration-200 active:scale-[0.99] focus:outline-none" style="width: 100%; background: #4A3728; color: #ffffff; font-weight: 800; font-size: 14px; padding: 14px 20px; border-radius: 12px; border: 1px solid #382618; display: flex; align-items: center; justify-content: center; gap: 10px; cursor: pointer; box-shadow: 0 4px 14px rgba(74, 55, 40, 0.28); transition: all 0.2s ease; outline: none; box-sizing: border-box;">
            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" class="w-5 h-5 text-white" style="width: 20px; height: 20px;">
              <path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75 11.25 15 15 9.75m-3-7.036A11.959 11.959 0 0 1 3.598 6 11.99 11.99 0 0 0 3 9.749c0 5.592 3.824 10.29 9 11.623 5.176-1.332 9-6.03 9-11.622 0-1.31-.21-2.571-.598-3.751h-.152c-3.196 0-6.1-1.248-8.25-3.285Z" />
            </svg>
            <span>Submit for Verification</span>
          </button>

          <div class="text-center -mt-1" style="text-align: center; margin-top: -4px;">
            <button type="button" onclick="closeVerifyAccountModal()" class="text-xs sm:text-sm font-bold text-[#4A3728] hover:text-[#382618] underline cursor-pointer bg-transparent border-0 transition-colors" style="background: none; border: none; color: #4A3728; font-size: 13px; font-weight: 700; cursor: pointer; text-decoration: underline;">
              Cancel
            </button>
          </div>
        </form>
      </div>

      <!-- ================= STEP 2: LIVE FACE SCAN (LIVENESS TESTING) ================= -->
      <div id="verifyStep2View" style="display: none;">
        <!-- Header -->
        <div style="text-align: center; margin-bottom: 16px; padding-right: 28px; padding-left: 28px;">
          <h3 style="font-size: 18px; font-weight: 800; color: #56380E; margin: 0 0 4px 0; display: flex; align-items: center; justify-content: center; gap: 8px;">
            <i data-lucide="scan-face" class="w-5 h-5 text-[#56380E]"></i>
            <span>Live Face Scan</span>
          </h3>
          <p style="font-size: 12.5px; color: #786c5e; margin: 0; line-height: 1.45; font-weight: 500;">
            Look directly into your camera to verify your identity against your uploaded ID
          </p>
        </div>

        <!-- Camera / Preview Viewport -->
        <div style="position: relative; width: 100%; max-width: 440px; height: 290px; margin: 0 auto; border-radius: 18px; overflow: hidden; background: #0f172a; box-shadow: 0 6px 20px rgba(0,0,0,0.28); display: flex; align-items: center; justify-content: center;">
          
          <!-- Live Webcam Video Stream (Flipped horizontally for natural mirror feel) -->
          <video id="livenessVideo" autoplay playsinline muted style="width: 100%; height: 100%; object-fit: cover; transform: scaleX(-1); -webkit-transform: scaleX(-1);"></video>

          <!-- Hidden Canvas for auto-capturing frame -->
          <canvas id="livenessCaptureCanvas" style="display: none;"></canvas>

          <!-- Oval Cutout Guide -->
          <div id="livenessGuideOverlay" class="face-oval-guide">
            <div id="livenessOvalRing" class="face-oval-ring"></div>
          </div>

          <!-- Dynamic Status Badge Pill -->
          <div id="livenessStatusPill" class="face-status-pill">
            <span id="livenessStatusIcon" style="display: inline-flex;"><i data-lucide="scan-face" class="w-4 h-4"></i></span>
            <span id="livenessStatusText">Position face in oval</span>
          </div>

          <!-- Finalizing & Comparison Overlay -->
          <div id="livenessFinalizingOverlay" style="display: none; position: absolute; inset: 0; background: rgba(15, 23, 42, 0.88); backdrop-filter: blur(6px); -webkit-backdrop-filter: blur(6px); z-index: 30; flex-direction: column; align-items: center; justify-content: center; padding: 20px; text-align: center; color: #ffffff;">
            <div id="livenessLoadingSpinner" class="clay-spinner" style="width: 38px; height: 38px; border-width: 3px; border-color: rgba(255,255,255,0.25); border-top-color: #38bdf8; margin-bottom: 12px;"></div>
            <div id="livenessFinalizingTitle" style="font-size: 14px; font-weight: 800; margin-bottom: 6px;">Comparing with ID photo...</div>
            <div id="livenessFinalizingSub" style="font-size: 12px; color: #94a3b8; max-width: 260px; line-height: 1.4;">Please hold still while we verify your biometrics.</div>
          </div>
        </div>

        <!-- Dynamic Challenge / Instruction Box -->
        <div id="livenessInstructionBanner" style="margin-top: 14px; padding: 11px 16px; background: rgba(86, 56, 14, 0.06); border: 1.5px solid rgba(86, 56, 14, 0.15); border-radius: 12px; text-align: center; font-size: 12.5px; font-weight: 700; color: #56380E; display: flex; align-items: center; justify-content: center; gap: 8px;">
          <span id="livenessInstructionIconBox" style="display: inline-flex; color: #56380E;"><i data-lucide="sparkles" class="w-4 h-4"></i></span>
          <span id="livenessChallengeText">Please blink your eyes or turn your head slightly to confirm liveness.</span>
        </div>

        <!-- Action Buttons -->
        <div style="display: flex; align-items: center; justify-content: space-between; margin-top: 16px; gap: 12px;">
          <button type="button" id="btnBackToStep1" onclick="goToVerificationStep(1)" style="flex: 1; background: #FAF8F5; border: 1.5px solid rgba(86, 56, 14, 0.2); border-radius: 12px; padding: 11px 16px; font-size: 13px; font-weight: 700; color: #56380E; cursor: pointer; display: flex; align-items: center; justify-content: center; gap: 8px; transition: all 0.2s ease;">
            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" style="width: 16px; height: 16px;">
              <path stroke-linecap="round" stroke-linejoin="round" d="M10.5 19.5 3 12m0 0 7.5-7.5M3 12h18" />
            </svg>
            <span>Back to Document</span>
          </button>
          <button type="button" id="btnLivenessRetry" onclick="restartLivenessScan()" style="display: none; flex: 1; background: #56380E; border: none; border-radius: 12px; padding: 11px 16px; font-size: 13px; font-weight: 800; color: #ffffff; cursor: pointer; align-items: center; justify-content: center; gap: 8px; box-shadow: 0 4px 12px rgba(86, 56, 14, 0.28);">
            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" style="width: 16px; height: 16px;">
              <path stroke-linecap="round" stroke-linejoin="round" d="M16.023 9.348h4.992v-.001M2.985 19.644v-4.992m0 0h4.992m-4.993 0 3.181 3.183a8.25 8.25 0 0 0 13.803-3.7M4.031 9.865a8.25 8.25 0 0 1 13.803-3.7l3.181 3.182m0-4.991v4.99" />
            </svg>
            <span>Retry Scan</span>
          </button>
        </div>
      </div>
    </div>
  </div>

  <!-- =====================================================================
       MODAL: APARTMENT TERMS & CONDITIONS
       ===================================================================== -->
  <div class="modal-backdrop" id="termsContentModal" style="display: none; pointer-events: none; z-index: 10050;">
    <div class="clay-modal bg-[#FCFBF9] border-2 border-[#B89B72] rounded-2xl p-6 sm:p-7 shadow-2xl" style="max-width: 620px; width: 100%; max-height: 85vh; display: flex; flex-direction: column;">
      <div style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 14px; padding-bottom: 12px; border-bottom: 1px dashed rgba(140, 109, 67, 0.3);">
        <div>
          <div style="display: flex; align-items: center; gap: 8px;">
            <h2 style="font-size: 18px; font-weight: 800; color: #3E2723; margin: 0;">Apartment Terms &amp; Conditions</h2>
            <span class="clay-badge" style="font-size: 11px; padding: 2px 8px; background: #F5F0E6; color: #4A3728; border: 1px solid #B89B72; font-weight: 700;">TaraRent</span>
          </div>
          <p style="font-size: 12px; color: #7A6245; margin: 3px 0 0 0;">Apartment Rental Agreement Guidelines</p>
        </div>
        <button type="button" class="modal-close-btn text-[#4A3728] hover:text-[#382618] p-1.5 rounded-lg hover:bg-[#4A3728]/10 transition-colors cursor-pointer" onclick="closeModal('termsContentModal')" title="Close" aria-label="Close"><i data-lucide="x" class="w-5 h-5" aria-hidden="true"></i></button>
      </div>

      <div style="flex: 1; overflow-y: auto; padding-right: 10px; font-size: 13px; line-height: 1.6; color: #3E2723;">
        <h4 style="font-weight: 800; color: #3E2723; margin-top: 0;">1. Monthly Rent &amp; Advance Deposit</h4>
        <p>The standard apartment monthly rent is ₱3,000.00 per unit, due on the designated monthly due date. An initial advance deposit of ₱3,000.00 is required upon tenancy commencement.</p>

        <h4 style="font-weight: 800; color: #3E2723;">2. Maintenance &amp; Repair Policy</h4>
        <p>All structural, electrical, plumbing, and general wear-and-tear repairs are the sole responsibility of the property owner/admin. Tenants will <strong>not</strong> be billed or charged for submitting maintenance work orders.</p>

        <h4 style="font-weight: 800; color: #3E2723;">3. Resident Verification</h4>
        <p>Tenants agree to provide valid identification and complete real-time face verification to prevent identity fraud and ensure community safety within TaraRent residences.</p>

        <h4 style="font-weight: 800; color: #3E2723;">4. House Rules &amp; Noise Curfew</h4>
        <p>Tenants must respect quiet hours between 10:00 PM and 7:00 AM. Cleanliness of common areas must be maintained at all times.</p>
      </div>

      <div style="display: flex; justify-content: space-between; align-items: center; gap: 12px; margin-top: 16px; padding-top: 12px; border-top: 1px dashed rgba(140, 109, 67, 0.3);">
        <span style="font-size: 12px; color: #7A6245;">TaraRent Management</span>
        <button type="button" class="py-2 px-5 rounded-xl font-black text-sm bg-[#4A3728] hover:bg-[#382618] text-white border border-[#382618] shadow-md transition-colors cursor-pointer" onclick="agreeTermsFromModal()">
          <span>I Agree to Terms</span>
        </button>
      </div>
    </div>
  </div>

  <!-- =====================================================================
       MODAL: PRIVACY POLICY & BIOMETRIC DATA CONSENT (RA 10173)
       ===================================================================== -->
  <div class="modal-backdrop" id="privacyContentModal" style="display: none; pointer-events: none; z-index: 10050;">
    <div class="clay-modal bg-[#FCFBF9] border-2 border-[#B89B72] rounded-2xl p-6 sm:p-7 shadow-2xl" style="max-width: 620px; width: 100%; max-height: 85vh; display: flex; flex-direction: column;">
      <div style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 14px; padding-bottom: 12px; border-bottom: 1px dashed rgba(140, 109, 67, 0.3);">
        <div>
          <div style="display: flex; align-items: center; gap: 8px;">
            <h2 style="font-size: 18px; font-weight: 800; color: #3E2723; margin: 0;">Privacy Policy &amp; Biometric Consent</h2>
            <span class="clay-badge" style="font-size: 11px; padding: 2px 8px; background: #dcfce7; color: #166534; font-weight: 700;">RA 10173</span>
          </div>
          <p style="font-size: 12px; color: #7A6245; margin: 3px 0 0 0;">In compliance with Republic Act No. 10173 (Data Privacy Act of 2012)</p>
        </div>
        <button type="button" class="modal-close-btn text-[#4A3728] hover:text-[#382618] p-1.5 rounded-lg hover:bg-[#4A3728]/10 transition-colors cursor-pointer" onclick="closeModal('privacyContentModal')" title="Close" aria-label="Close"><i data-lucide="x" class="w-4 h-4" aria-hidden="true"></i></button>
      </div>

      <div style="flex: 1; overflow-y: auto; padding-right: 10px; font-size: 13px; line-height: 1.6; color: #3E2723;">
        <h4 style="font-weight: 800; color: #3E2723; margin-top: 0;">1. Data Collection &amp; Purpose</h4>
        <p>TaraRent collects tenant names, contact details, uploaded government/student ID photos, and real-time live face scan imagery solely for identity verification, residency records, and tenancy contract management.</p>

        <h4 style="font-weight: 800; color: #3E2723;">2. Biometric Data Processing</h4>
        <p>Your live face scan is used exclusively to compare against your uploaded ID to confirm your identity. Biometric templates are not retained indefinitely, sold, or shared with third parties.</p>

        <h4 style="font-weight: 800; color: #3E2723;">3. Data Security &amp; Confidentiality</h4>
        <p>All sensitive information, including uploaded IDs and payment proofs, is stored in restricted, secure server directories with role-based access controls and encrypted channels.</p>

        <h4 style="font-weight: 800; color: #3E2723;">4. Tenant Rights</h4>
        <p>Under RA 10173, you retain the right to access, rectify, or request information regarding your personal data retained within the system by contacting the property administrator.</p>
      </div>

      <div style="display: flex; justify-content: space-between; align-items: center; gap: 12px; margin-top: 16px; padding-top: 12px; border-top: 1px dashed rgba(140, 109, 67, 0.3);">
        <span style="font-size: 12px; color: #7A6245;">TaraRent Data Privacy Protection</span>
        <button type="button" class="py-2 px-5 rounded-xl font-black text-sm bg-[#4A3728] hover:bg-[#382618] text-white border border-[#382618] shadow-md transition-colors cursor-pointer" onclick="agreePrivacyFromModal()">
          <span>I Acknowledge &amp; Consent</span>
        </button>
      </div>
    </div>
  </div>

  <!-- Clay Toast Notification -->
  <div class="clay-toast" id="clayToast">
    <span class="toast-icon" id="toastIcon" style="display: inline-flex; align-items: center;"><i data-lucide="check-circle-2" class="w-4 h-4" aria-hidden="true"></i></span>
    <span class="toast-message" id="toastMessage">Done</span>
  </div>

  <script>
    let currentPrio = 'urgent';
    let attachedFile = null;

    let toastTimeout = null;
    function showToast(msg, type = 'success', duration = 3000) {
      const t = document.getElementById('clayToast');
      const icon = document.getElementById('toastIcon');
      const msgEl = document.getElementById('toastMessage');
      if (msgEl) msgEl.textContent = msg;
      if (icon) {
        const iconName = (type === 'error') ? 'alert-triangle' : ((type === 'info') ? 'info' : 'check-circle-2');
        icon.innerHTML = `<i data-lucide="${iconName}" class="w-4 h-4" aria-hidden="true"></i>`;
        if (typeof window.refreshLucideIcons === 'function') {
          window.refreshLucideIcons();
        }
      }
      if (t) {
        t.style.transition = 'opacity 0.4s ease, transform 0.4s ease';
        t.classList.add('show');
        if (toastTimeout) clearTimeout(toastTimeout);
        toastTimeout = setTimeout(() => {
          t.classList.remove('show');
        }, duration);
      }
    }

    function handleTargetBillChange() {
      const sel = document.getElementById('payTargetPayment');
      if (!sel) return;
      const opt = sel.options[sel.selectedIndex];
      if (!opt) return;
      const amt = opt.dataset.amount || '3000.00';
      const cycle = opt.dataset.cycle;
      const payAmountEl = document.getElementById('payAmount');
      const payCycleEl = document.getElementById('payCycle');
      if (payAmountEl) payAmountEl.value = amt;
      if (payCycleEl && cycle) payCycleEl.value = cycle;
    }

    function selectBillToPay(id) {
      switchTenantSection('tenantPayments', document.getElementById('navTenantPayments'));
      const sel = document.getElementById('payTargetPayment');
      if (sel) {
        sel.value = id;
        handleTargetBillChange();
      }
      const dropZone = document.getElementById('receiptDropZone');
      if (dropZone) {
        dropZone.scrollIntoView({ behavior: 'smooth', block: 'center' });
      }
    }

    // Sidebar Toggle & Active-State Controller provided by assets/js/sidebar.js
    window.HAS_UNPAID_BALANCE = <?= $hasUnpaidBalance ? 'true' : 'false' ?>;
    window.IS_TENANT_VERIFIED = <?= $isTenantVerified ? 'true' : 'false' ?>;
    window.TENANT_STATUS_MODE = <?= json_encode($tenantStatus['mode'] ?? 'active_verified') ?>;
    window.TENANT_VERIFICATION_STATUS = <?= json_encode(strtolower((string)($user['verification_status'] ?? 'unverified'))) ?>;
    window.IS_MOVED_OUT_UNPAID = <?= $isMovedOutTenant ? 'true' : 'false' ?>;

    // Global Modal Controller: Open & Close Modal with Hidden State
    function openModal(modalId) {
      const modal = typeof modalId === 'string' ? document.getElementById(modalId) : modalId;
      if (!modal) return;
      modal.classList.remove('hidden');
      modal.classList.add('open');
      modal.style.display = 'flex';
      modal.style.pointerEvents = 'auto';
      if (modal.id === 'verifyAccountModal' && typeof goToVerificationStep === 'function') {
        goToVerificationStep(1);
      }
      if (window.lucide && typeof window.lucide.createIcons === 'function') {
        window.lucide.createIcons();
      }
    }
    window.openModal = openModal;

    function closeModal(modalId) {
      const modal = typeof modalId === 'string' ? document.getElementById(modalId) : modalId;
      if (!modal) return;
      modal.classList.add('hidden');
      modal.classList.remove('open', 'active');
      modal.style.display = 'none';
      modal.style.pointerEvents = 'none';
      document.body.style.overflow = '';

      const id = modal.id;
      if (id === 'verifyAccountModal' && typeof closeVerifyAccountModal === 'function') {
        closeVerifyAccountModal();
      } else if (id === 'editAccountInfoModal' && typeof closeEditAccountInfoModal === 'function') {
        closeEditAccountInfoModal();
      } else if (id === 'verificationActionPromptModal' && typeof closeVerificationActionPrompt === 'function') {
        closeVerificationActionPrompt();
      } else if (id === 'tenantTicketModal' && typeof closeTenantTicketModal === 'function') {
        closeTenantTicketModal();
      } else if (id === 'tenantReportModal' && typeof closeTenantReportModal === 'function') {
        closeTenantReportModal();
      } else if (id === 'termsContentModal' && typeof closeTermsModal === 'function') {
        closeTermsModal();
      } else if (id === 'privacyContentModal' && typeof closePrivacyModal === 'function') {
        closePrivacyModal();
      }
    }
    window.closeModal = closeModal;

    // Universal Close Button Click Listener (Capturing phase guarantees all "X" buttons work)
    document.addEventListener('click', function(e) {
      const closeBtn = e.target.closest('.modal-close-btn, .btn-remove-file, .btn-icon-close, .resident-reg-close, .tararent-modal-close-btn, [data-modal-close], button[aria-label="Close"], button[title*="Close"]');
      if (closeBtn) {
        if (closeBtn.id === 'btnRemoveFile' || closeBtn.id === 'btnRemoveAttachedPhoto' || closeBtn.id === 'btnRemoveIdFile' || closeBtn.classList.contains('btn-remove-attached-photo')) return; // File preview remove buttons, handled by dedicated upload handlers
        const modal = closeBtn.closest('.modal-backdrop, [role="dialog"], [id$="Modal"]');
        if (modal && modal.id && typeof window.closeModal === 'function') {
          e.preventDefault();
          e.stopPropagation();
          window.closeModal(modal.id);
        }
      }
    }, true);

    function handleUnverifiedNavClick(featureName) {
      handleRestrictedNavClick(featureName);
    }

    function handleRestrictedNavClick(featureName) {
      // Unrestricted GET navigation: directly switch to the requested feature section
      const secMap = {
        'Maintenance': 'tenantMaintenance',
        'Log Maintenance': 'tenantMaintenance',
        'Reports': 'tenantReport',
        'Report': 'tenantReport',
        'Payments': 'tenantPayments',
        'Upload Payment': 'tenantPayments',
        'Dashboard': 'tenantOverview',
        'Settings': 'tenantProfile'
      };
      const secId = secMap[featureName] || 'tenantOverview';
      const navBtn = document.querySelector(`.sidebar-nav-item[data-section="${secId}"]`) ||
                     document.querySelector(`.sidebar-nav-item[onclick*="${secId}"]`);
      switchTenantSection(secId, navBtn, true);
    }

    // Sidebar Category Switcher: Sidebar strictly remains open and visible when clicking category tabs/links
    function switchTenantSection(sectionId, btn, updateUrl = true) {
      if (!sectionId) return;

      if (window.IS_MOVED_OUT_UNPAID && !['tenantOverview', 'tenantPayments'].includes(sectionId)) {
        if (typeof showToast === 'function') {
          showToast('Navigation restricted: Only Dashboard and Upload Payment are accessible while your balance remains unsettled.', 'warning');
        }
        sectionId = 'tenantOverview';
        btn = document.getElementById('navTenantDashboard') || document.querySelector('.sidebar-nav-item');
      }

      if (sectionId === 'tenantProfile') {
        if (window.IS_MOVED_OUT_UNPAID) {
          showToast('Profile and Settings are unavailable while your account has an unsettled move-out balance.', 'warning');
          return;
        }
        const settingsBtn = btn || document.getElementById('navTenantSettings');
        if (typeof window.setActiveSidebarNavItem === 'function') {
          window.setActiveSidebarNavItem('tenantProfile', settingsBtn);
        }
        openProfileView('accountInfo', updateUrl);
        return;
      }

      document.querySelectorAll('.app-view-section').forEach(s => s.classList.remove('active-section'));

      const target = document.getElementById(sectionId);
      if (target) target.classList.add('active-section');

      if (typeof window.setActiveSidebarNavItem === 'function') {
        window.setActiveSidebarNavItem(sectionId, btn);
      }

      if (updateUrl && window.history && window.history.replaceState) {
        history.replaceState(null, '', `tenant_portal.php?section=${sectionId}`);
      }

      if (sectionId === 'tenantReport' && typeof loadTenantReports === 'function') {
        loadTenantReports();
      }

      // Hide Settings button when leaving profile section
      updateProfileSettingsVisibility(false);

      closeSettingsMenu();
    }

    window.switchTenantSection = switchTenantSection;
    window.switchSection = switchTenantSection;

    // Synchronize user profile information and settings state
    function updateProfileSettingsVisibility(isProfileOpen) {
      const nameEl = document.getElementById('headerUserName');
      if (nameEl) {
        nameEl.textContent = <?= json_encode($user['name'] ?? 'Resident') ?>;
      }
      const profNameEl = document.getElementById('profileFullName');
      if (profNameEl) {
        profNameEl.textContent = <?= json_encode($user['name'] ?? 'Resident') ?>;
      }
      const dropNameEl = document.getElementById('profileDropdownName');
      if (dropNameEl) {
        dropNameEl.textContent = <?= json_encode($user['name'] ?? 'Resident') ?>;
      }
    }

    // Settings Dropdown Popover Control
    function toggleSettingsMenu(e) {
      if (e && typeof e.stopPropagation === 'function') {
        e.stopPropagation();
      }
      if (typeof closeNotificationPanel === 'function') {
        closeNotificationPanel();
      }
      const d = document.getElementById('settingsDropdown');
      if (d) d.classList.toggle('show');
    }

    function closeSettingsMenu() {
      const d = document.getElementById('settingsDropdown');
      if (d) d.classList.remove('show');
    }

    // Profile View & Internal Sub-Sidebar Navigation (Sidebar strictly remains open and visible)
    function openProfileView(tab = 'accountInfo', updateUrl = true) {
      if (window.IS_MOVED_OUT_UNPAID) {
        showToast('Profile and Settings are restricted while your account has an unsettled move-out balance.', 'warning');
        return;
      }
      document.querySelectorAll('.app-view-section').forEach(s => s.classList.remove('active-section'));
      document.querySelectorAll('.sidebar-nav-item').forEach(b => b.classList.remove('active'));

      const profSec = document.getElementById('tenantProfile');
      if (profSec) profSec.classList.add('active-section');

      const settingsBtn = document.getElementById('navTenantSettings');
      if (settingsBtn) {
        settingsBtn.classList.add('active');
        settingsBtn.setAttribute('aria-current', 'page');
      }

      // Show Settings button along with user profile information
      updateProfileSettingsVisibility(true);

      switchProfileSubTab(tab, null, updateUrl);
      closeSettingsMenu();
    }

    function switchProfileSubTab(tabName, clickedBtn = null, updateUrl = true) {
      document.querySelectorAll('.profile-tab-pane').forEach(p => p.classList.remove('active-pane'));
      document.querySelectorAll('.profile-subnav-item').forEach(b => b.classList.remove('active'));

      if (tabName === 'accountInfo') {
        const pane = document.getElementById('paneAccountInfo');
        if (pane) pane.classList.add('active-pane');
        const btn = clickedBtn || document.getElementById('tenantSubnavAccount');
        if (btn) btn.classList.add('active');
      } else if (tabName === 'changePassword') {
        const pane = document.getElementById('paneChangePassword');
        if (pane) pane.classList.add('active-pane');
        const btn = clickedBtn || document.getElementById('tenantSubnavPassword');
        if (btn) btn.classList.add('active');
      }

      if (updateUrl && window.history && window.history.replaceState) {
        history.replaceState(null, '', `tenant_portal.php?section=tenantProfile&tab=${tabName}`);
      }
    }

    // =========================================================================
    // PROFILE PICTURE CUSTOMIZATION (TENANT)
    // =========================================================================
    let currentSavedAvatarUrl = <?= json_encode(!empty($userAvatarUrl) ? $userAvatarUrl : '') ?>;
    let userDefaultInitial = <?= json_encode($userInitial) ?>;
    let pendingAvatarFile = null;

    const IS_TENANT_VERIFIED = <?= $isTenantVerified ? 'true' : 'false' ?>;
    const VERIFICATION_ACTION_REQUIRED_MSG = 'Action Required: Please verify your account to proceed with this submission.';

    function openVerificationActionPrompt(moduleName = '') {
      const modal = document.getElementById('verificationActionPromptModal');
      if (modal) {
        modal.style.display = 'flex';
        modal.classList.add('open');
        modal.style.pointerEvents = 'auto';
        if (window.lucide && typeof window.lucide.createIcons === 'function') {
          window.lucide.createIcons();
        }
      } else {
        showToast(VERIFICATION_ACTION_REQUIRED_MSG, 'warning', 6000);
        openVerifyAccountModal();
      }
    }

    function closeVerificationActionPrompt() {
      const modal = document.getElementById('verificationActionPromptModal');
      if (modal) {
        modal.classList.remove('open');
        modal.style.display = 'none';
        modal.style.pointerEvents = 'none';
      }
    }

    // Alias for backwards compatibility
    function showFeatureLockedAlert(featureLabel = '', customMessage = '') {
      openVerificationActionPrompt(featureLabel);
    }

    function closeFeatureLockedModal() {
      closeVerificationActionPrompt();
    }

    function goToAccountVerification() {
      closeVerificationActionPrompt();
      openVerifyAccountModal();
    }

    document.addEventListener('keydown', (e) => {
      if (e.key === 'Escape' || e.keyCode === 27) {
        const openBackdrops = document.querySelectorAll('.modal-backdrop.open, .modal-backdrop:not(.hidden):not([style*="display: none"])');
        if (openBackdrops.length > 0) {
          const topModal = openBackdrops[openBackdrops.length - 1];
          if (topModal && topModal.id && typeof window.closeModal === 'function') {
            window.closeModal(topModal.id);
          }
        }
      }
    });

    function previewProfilePicture(e) {
      const file = e.target.files && e.target.files[0];
      if (!file) return;

      const validTypes = ['image/jpeg', 'image/png', 'image/webp', 'image/jpg'];
      if (!validTypes.includes(file.type.toLowerCase()) && !file.type.startsWith('image/')) {
        showToast('Invalid format. Please choose a JPG, PNG, or WEBP image.', 'error');
        e.target.value = '';
        return;
      }

      if (file.size > 5 * 1024 * 1024) {
        showToast('Image file exceeds the 5MB size limit.', 'error');
        e.target.value = '';
        return;
      }

      pendingAvatarFile = file;

      const reader = new FileReader();
      reader.onload = function(evt) {
        const previewImg = document.getElementById('avatarPreviewImg');
        const fallbackInit = document.getElementById('avatarFallbackInitial');
        if (previewImg) {
          previewImg.src = evt.target.result;
          previewImg.style.display = 'block';
        }
        if (fallbackInit) {
          fallbackInit.style.display = 'none';
        }
        const pendingActions = document.getElementById('avatarPendingActions');
        if (pendingActions) pendingActions.style.display = 'inline-flex';
        const removeBtn = document.getElementById('btnRemoveAvatar');
        if (removeBtn) removeBtn.style.display = 'none';
        if (window.lucide && typeof window.lucide.createIcons === 'function') window.lucide.createIcons();
      };
      reader.readAsDataURL(file);
    }

    // Aliases for compatibility
    function handleProfilePictureSelect(e) { previewProfilePicture(e); }
    function handleProfilePictureSelected(e) { previewProfilePicture(e); }

    function triggerProfilePictureInput() {
      const input = document.getElementById('profile_picture') || document.getElementById('profilePictureInput') || document.getElementById('profileAvatarInput');
      if (input) input.click();
    }

    function cancelProfilePictureSelection() {
      pendingAvatarFile = null;
      const input = document.getElementById('profile_picture') || document.getElementById('profilePictureInput') || document.getElementById('profileAvatarInput');
      if (input) input.value = '';

      const previewImg = document.getElementById('avatarPreviewImg');
      const fallbackInit = document.getElementById('avatarFallbackInitial');
      const pendingActions = document.getElementById('avatarPendingActions');
      const removeBtn = document.getElementById('btnRemoveAvatar');

      if (pendingActions) pendingActions.style.display = 'none';

      if (currentSavedAvatarUrl) {
        if (previewImg) {
          previewImg.src = currentSavedAvatarUrl;
          previewImg.style.display = 'block';
        }
        if (fallbackInit) fallbackInit.style.display = 'none';
        if (removeBtn) removeBtn.style.display = 'inline-flex';
      } else {
        if (previewImg) {
          previewImg.src = '';
          previewImg.style.display = 'none';
        }
        if (fallbackInit) {
          fallbackInit.textContent = userDefaultInitial;
          fallbackInit.style.display = 'flex';
        }
        if (removeBtn) removeBtn.style.display = 'none';
      }
      if (window.lucide && typeof window.lucide.createIcons === 'function') window.lucide.createIcons();
    }

    function submitProfilePictureUpload() {
      const input = document.getElementById('profile_picture') || document.getElementById('profilePictureInput') || document.getElementById('profileAvatarInput');
      const file = pendingAvatarFile || (input && input.files && input.files[0]);
      if (!file) {
        showToast('Please select a photo first.', 'warning');
        return;
      }

      const saveBtn = document.getElementById('btnSaveAvatar');
      if (saveBtn) {
        saveBtn.disabled = true;
        saveBtn.innerHTML = 'Saving...';
      }

      const form = document.getElementById('profilePictureForm');
      const formData = new FormData(form || undefined);
      formData.set('action', 'upload_profile_picture');
      formData.set('profile_picture', file);
      formData.set('is_ajax', '1');

      fetch('api/profile_api.php', {
        method: 'POST',
        body: formData
      })
      .then(res => res.json())
      .then(data => {
        if (saveBtn) {
          saveBtn.disabled = false;
          saveBtn.innerHTML = '<i data-lucide="check" style="width: 15px; height: 15px;"></i> Save Changes';
          if (window.lucide && typeof window.lucide.createIcons === 'function') window.lucide.createIcons();
        }

        if (data.status === 'success') {
          currentSavedAvatarUrl = data.profile_picture;
          pendingAvatarFile = null;
          if (input) input.value = '';

          const pendingActions = document.getElementById('avatarPendingActions');
          if (pendingActions) pendingActions.style.display = 'none';

          const removeBtn = document.getElementById('btnRemoveAvatar');
          if (removeBtn) removeBtn.style.display = 'inline-flex';

          const stamp = Date.now();
          const avatarUrlWithCache = data.profile_picture + '?t=' + stamp;

          // Update header avatar badge
          const headerBadge = document.getElementById('headerAvatarBadge');
          if (headerBadge) {
            headerBadge.innerHTML = `<img src="${avatarUrlWithCache}" alt="Profile" class="header-avatar-img">`;
          }

          // Update profile dropdown avatar
          const dropCircle = document.getElementById('profileDropdownAvatarCircle') || document.querySelector('.fb-profile-avatar-circle');
          if (dropCircle) {
            dropCircle.innerHTML = `<img src="${avatarUrlWithCache}" alt="Profile" id="profileDropdownAvatarImg">`;
          }

          // Update settings preview
          const previewImg = document.getElementById('avatarPreviewImg');
          if (previewImg) {
            previewImg.src = avatarUrlWithCache;
            previewImg.style.display = 'block';
          }
          const fallbackInit = document.getElementById('avatarFallbackInitial');
          if (fallbackInit) fallbackInit.style.display = 'none';

          if (window.lucide && typeof window.lucide.createIcons === 'function') window.lucide.createIcons();
          showToast('Profile picture updated successfully!', 'success');
        } else {
          showToast(data.message || 'Failed to update profile picture.', 'error');
        }
      })
      .catch(err => {
        if (saveBtn) {
          saveBtn.disabled = false;
          saveBtn.innerHTML = '<i data-lucide="check" style="width: 15px; height: 15px;"></i> Save Changes';
          if (window.lucide && typeof window.lucide.createIcons === 'function') window.lucide.createIcons();
        }
        showToast('Network error uploading profile picture.', 'error');
      });
    }

    function confirmRemoveProfilePicture() {
      if (!confirm('Are you sure you want to remove your profile picture and revert to the default circular initial icon?')) {
        return;
      }

      const removeBtn = document.getElementById('btnRemoveAvatar');
      if (removeBtn) removeBtn.disabled = true;

      fetch('api/profile_api.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ action: 'remove_profile_picture' })
      })
      .then(res => res.json())
      .then(data => {
        if (removeBtn) removeBtn.disabled = false;

        if (data.status === 'success') {
          currentSavedAvatarUrl = '';
          pendingAvatarFile = null;
          userDefaultInitial = data.initial || userDefaultInitial;

          const input = document.getElementById('profilePictureInput');
          if (input) input.value = '';

          const pendingActions = document.getElementById('avatarPendingActions');
          if (pendingActions) pendingActions.style.display = 'none';

          if (removeBtn) removeBtn.style.display = 'none';

          // Update settings preview
          const previewImg = document.getElementById('avatarPreviewImg');
          if (previewImg) {
            previewImg.src = '';
            previewImg.style.display = 'none';
          }
          const fallbackInit = document.getElementById('avatarFallbackInitial');
          if (fallbackInit) {
            fallbackInit.textContent = userDefaultInitial;
            fallbackInit.style.display = 'flex';
          }

          // Update header avatar badge
          const headerBadge = document.getElementById('headerAvatarBadge');
          if (headerBadge) {
            headerBadge.innerHTML = `<span class="header-avatar-initial">${escapeHtml(userDefaultInitial)}</span>`;
          }

          // Update profile dropdown avatar
          const dropCircle = document.getElementById('profileDropdownAvatarCircle') || document.querySelector('.fb-profile-avatar-circle');
          if (dropCircle) {
            dropCircle.innerHTML = `<span id="profileDropdownAvatarInitial">${escapeHtml(userDefaultInitial)}</span>`;
          }

          if (window.lucide && typeof window.lucide.createIcons === 'function') window.lucide.createIcons();
          showToast('Profile picture removed. Reverted to default initial icon.', 'success');
        } else {
          showToast(data.message || 'Failed to remove profile picture.', 'error');
        }
      })
      .catch(() => {
        if (removeBtn) removeBtn.disabled = false;
        showToast('Network error removing profile picture.', 'error');
      });
    }

    // Edit Account Information Modal Controls
    function openEditAccountInfoModal() {
      const modal = document.getElementById('editAccountInfoModal');
      if (modal) {
        modal.style.display = 'flex';
        modal.classList.add('open');
        modal.style.pointerEvents = 'auto';
      }
    }

    function closeEditAccountInfoModal() {
      const modal = document.getElementById('editAccountInfoModal');
      if (modal) {
        modal.classList.remove('open');
        modal.style.display = 'none';
        modal.style.pointerEvents = 'none';
      }
    }

    // =========================================================================
    // CORE SIDEBAR & SETTINGS EVENT LISTENERS
    // Attached in DOMContentLoaded immediately at the top of script execution
    // to guarantee the hamburger toggle and settings buttons are interactive
    // without being blocked by deferred libraries, OCR, or face biometrics.
    // =========================================================================
    const __tenantSearchTickets = <?= json_encode(array_map(fn($t) => [
      'id' => (int)($t['id'] ?? 0),
      'title' => (string)($t['title'] ?? ''),
      'category' => (string)($t['category'] ?? ''),
      'status' => (string)($t['status'] ?? ''),
      'created_at' => (string)($t['created_at'] ?? ''),
      'raw' => $t,
    ], $myTickets), JSON_UNESCAPED_UNICODE) ?>;

    let __tenantSearchReports = <?= json_encode(array_map(fn($r) => [
      'id' => (int)($r['id'] ?? 0),
      'subject' => (string)($r['subject'] ?? ''),
      'category' => (string)($r['category'] ?? ''),
      'status' => (string)($r['status'] ?? ''),
      'created_at' => (string)($r['created_at'] ?? ''),
      'raw' => $r,
    ], $myReports), JSON_UNESCAPED_UNICODE) ?>;

    const __tenantSearchPayments = <?= json_encode(array_map(fn($p) => [
      'billing_cycle' => (string)($p['billing_cycle'] ?? ''),
      'status' => (string)($p['status'] ?? ''),
      'amount_paid' => (float)($p['amount_paid'] ?? 0),
      'payment_date' => (string)($p['payment_date'] ?? ''),
    ], $myPayments), JSON_UNESCAPED_UNICODE) ?>;

    function escapeTenantSearchHtml(str) {
      return String(str ?? '').replace(/[&<>"']/g, (ch) => ({
        '&': '&amp;',
        '<': '&lt;',
        '>': '&gt;',
        '"': '&quot;',
        "'": '&#39;',
      }[ch]));
    }

    function clearGlobalTenantSearch() {
      const input = document.getElementById('tenantGlobalSearchInput');
      const clearBtn = document.getElementById('tenantGlobalSearchClear');
      const dropdown = document.getElementById('tenantSearchDropdown');
      if (input) input.value = '';
      if (clearBtn) clearBtn.classList.remove('visible');
      if (dropdown) {
        dropdown.innerHTML = '';
        dropdown.classList.remove('open');
      }
    }

    function initTenantGlobalSearch() {
      const input = document.getElementById('tenantGlobalSearchInput');
      const clearBtn = document.getElementById('tenantGlobalSearchClear');
      const dropdown = document.getElementById('tenantSearchDropdown');
      if (!input || !dropdown) return;

      const staticSections = [
        { title: 'Dashboard Overview', sub: 'Current rent, active maintenance & receipts', tag: 'Section', section: 'tenantOverview', navId: 'navTenantOverview' },
        { title: 'Log Maintenance', sub: 'Report repair requests & track work orders', tag: 'Section', section: 'tenantMaintenance', navId: 'navTenantMaintenance' },
        { title: 'Upload Payment', sub: 'Submit bank or GCash rent receipts', tag: 'Section', section: 'tenantPayments', navId: 'navTenantPayments' },
        { title: 'Submit General Report', sub: 'Occupants, pets, unit modifications & inquiries', tag: 'Section', section: 'tenantReport', navId: 'navTenantReport' },
        { title: 'Account Information', sub: 'Profile records & identity verification', tag: 'Settings', profileTab: 'accountInfo' },
        { title: 'Change Password', sub: 'Update your resident login credentials', tag: 'Settings', profileTab: 'changePassword' },
      ];

      function renderResults(rawQuery) {
        const q = (rawQuery || '').trim().toLowerCase();
        if (clearBtn) clearBtn.classList.toggle('visible', q.length > 0);
        if (!q) {
          dropdown.innerHTML = '';
          dropdown.classList.remove('open');
          return;
        }

        const results = [];

        staticSections.forEach((sec) => {
          if (sec.title.toLowerCase().includes(q) || sec.sub.toLowerCase().includes(q)) {
            results.push({
              title: sec.title,
              sub: sec.sub,
              tag: sec.tag,
              onSelect: () => {
                if (sec.profileTab) {
                  openProfileView(sec.profileTab);
                } else {
                  switchTenantSection(sec.section, document.getElementById(sec.navId));
                }
              },
            });
          }
        });

        __tenantSearchTickets.forEach((tk) => {
          const ticketCode = '#MT-' + String(tk.id).padStart(4, '0');
          if (
            tk.title.toLowerCase().includes(q) ||
            tk.category.toLowerCase().includes(q) ||
            tk.status.toLowerCase().includes(q) ||
            ticketCode.toLowerCase().includes(q)
          ) {
            results.push({
              title: `${ticketCode} · ${tk.title}`,
              sub: `${tk.category || 'Maintenance'} • ${tk.status}`,
              tag: 'Ticket',
              onSelect: () => {
                if (typeof openTenantTicketModal === 'function') {
                  openTenantTicketModal(tk.raw);
                } else {
                  switchTenantSection('tenantMaintenance', document.getElementById('navTenantMaintenance'));
                }
              },
            });
          }
        });

        (__tenantSearchReports || []).forEach((rep) => {
          const reportCode = '#GR-' + String(rep.id).padStart(4, '0');
          if (
            rep.subject.toLowerCase().includes(q) ||
            rep.category.toLowerCase().includes(q) ||
            rep.status.toLowerCase().includes(q) ||
            reportCode.toLowerCase().includes(q)
          ) {
            results.push({
              title: `${reportCode} · ${rep.subject}`,
              sub: `${rep.category || 'General Inquiry'} • ${rep.status}`,
              tag: 'Report',
              onSelect: () => {
                if (typeof openTenantReportModal === 'function') {
                  openTenantReportModal(rep.raw);
                } else {
                  switchTenantSection('tenantReport', document.getElementById('navTenantReport'));
                }
              },
            });
          }
        });

        __tenantSearchPayments.forEach((pay) => {
          const cycleLabel = pay.billing_cycle ? pay.billing_cycle : 'Pending Setup';
          const statusLabel = String(pay.status || '').toUpperCase();
          if (cycleLabel.toLowerCase().includes(q) || statusLabel.toLowerCase().includes(q)) {
            results.push({
              title: `Rent · ${cycleLabel}`,
              sub: pay.status === 'pending_setup' ? 'Rent setup pending' : `₱${Number(pay.amount_paid || 0).toFixed(2)} • ${statusLabel}`,
              tag: 'Payment',
              onSelect: () => switchTenantSection('tenantPayments', document.getElementById('navTenantPayments')),
            });
          }
        });

        dropdown.innerHTML = '';
        if (results.length === 0) {
          const emptyEl = document.createElement('div');
          emptyEl.style.padding = '12px 14px';
          emptyEl.style.fontSize = '12.5px';
          emptyEl.style.color = 'var(--text-muted)';
          emptyEl.textContent = 'No matching tickets, payments, or sections found.';
          dropdown.appendChild(emptyEl);
        } else {
          results.slice(0, 8).forEach((item) => {
            const btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'global-search-item';
            btn.innerHTML = `
              <div class="global-search-meta">
                <span class="global-search-title">${escapeTenantSearchHtml(item.title)}</span>
                <span class="global-search-sub">${escapeTenantSearchHtml(item.sub)}</span>
              </div>
              <span class="global-search-tag">${escapeTenantSearchHtml(item.tag)}</span>
            `;
            btn.addEventListener('click', () => {
              item.onSelect();
              clearGlobalTenantSearch();
            });
            dropdown.appendChild(btn);
          });
        }
        dropdown.classList.add('open');
      }

      input.addEventListener('input', (e) => renderResults(e.target.value));
      input.addEventListener('focus', () => {
        if (input.value.trim() !== '') renderResults(input.value);
      });
    }

    function setupSidebarAndSettingsListeners() {
      try {
        if (typeof window.initSharedSidebar === 'function') {
          window.initSharedSidebar();
        }

        initTenantGlobalSearch();

        // Global outside click handler to dismiss settings dropdown & search results
        document.addEventListener('click', (e) => {
          if (!e.target.closest('#settingsToggleBtn') &&
              !e.target.closest('#settingsDropdown') &&
              !e.target.closest('#headerProfileTrigger') &&
              !e.target.closest('#headerAvatarBadge') &&
              !e.target.closest('#headerNotificationBtn') &&
              !e.target.closest('#notificationDropdown')) {
            closeSettingsMenu();
          }
          if (!e.target.closest('.top-nav-search-wrap')) {
            const sd = document.getElementById('tenantSearchDropdown');
            if (sd) sd.classList.remove('open');
          }
        });
      } catch (err) {
        console.warn('Sidebar and Settings listener registration notice:', err);
      }
    }

    if (document.readyState === 'loading') {
      document.addEventListener('DOMContentLoaded', setupSidebarAndSettingsListeners);
    } else {
      setupSidebarAndSettingsListeners();
    }

    // =========================================================================
    // MULTI-STEP TENANT ID & LIVE FACE VERIFICATION JAVASCRIPT
    // Strictly ONLY appears and opens when the tenant clicks the "Verify Account" button in Account Information
    // =========================================================================
    let selectedIdFile = null;
    let preprocessedIdBlob = null;
    let preprocessedIdDataUrl = '';
    let heldOcrRawText = '';
    let webcamStream = null;
    let capturedFaceBlob = null;
    let capturedFaceDataUrl = '';
    let isFaceCaptureValid = false;
    let currentVerificationStep = 1;

    // Strict Anti-Spoofing State Machines for Phase 3 Live Testing
    let livenessBlinkState = 'INITIAL'; // 'INITIAL' -> 'OPEN_OBSERVED' -> 'DROPPED_CLOSED' -> 'COMPLETED'
    let livenessHeadState = 'INITIAL';  // 'INITIAL' -> 'CENTER_OBSERVED' -> 'TURNED_AWAY' -> 'COMPLETED'
    let livenessOpenFrames = 0;
    let livenessCenterFrames = 0;
    let livenessTurnDirection = '';

    function resetLivenessAntiSpoofingState() {
      livenessBlinkState = 'INITIAL';
      livenessHeadState = 'INITIAL';
      livenessOpenFrames = 0;
      livenessCenterFrames = 0;
      livenessTurnDirection = '';
    }

    // Image Pre-processing for OCR:
    // Converts attached ID to grayscale, enhances contrast, and applies binarization/thresholding.
    // This maximizes character legibility for the OCR engine and eliminates errors from blurry or low-contrast uploads.
    function preprocessIdImageForOCR(file, onComplete) {
      if (!file || !file.type || !file.type.startsWith('image/')) {
        if (onComplete) onComplete(null, null);
        return;
      }

      const reader = new FileReader();
      reader.onload = function(e) {
        const img = new Image();
        img.onload = function() {
          const canvas = document.createElement('canvas');
          let width = img.width;
          let height = img.height;

          // Limit maximum dimension to 1600px for optimal OCR recognition
          const maxDim = 1600;
          if (width > maxDim || height > maxDim) {
            if (width > height) {
              height = Math.round((height * maxDim) / width);
              width = maxDim;
            } else {
              width = Math.round((width * maxDim) / height);
              height = maxDim;
            }
          }

          canvas.width = width;
          canvas.height = height;
          const ctx = canvas.getContext('2d');
          ctx.drawImage(img, 0, 0, width, height);

          try {
            const imgData = ctx.getImageData(0, 0, width, height);
            const d = imgData.data;

            // Pre-processing parameters:
            // Contrast enhancement factor (-100 to 100, set to 40)
            const contrast = 40;
            const factor = (259 * (contrast + 255)) / (255 * (259 - contrast));
            // Luminance threshold for binarization (0-255)
            const threshold = 135;

            for (let i = 0; i < d.length; i += 4) {
              // 1. Grayscale luminance conversion: 0.299R + 0.587G + 0.114B
              const gray = 0.299 * d[i] + 0.587 * d[i + 1] + 0.114 * d[i + 2];
              // 2. Contrast enhancement
              const cVal = factor * (gray - 128) + 128;
              // 3. Binarization thresholding
              const bin = cVal >= threshold ? 255 : 0;

              d[i] = bin;     // R
              d[i + 1] = bin; // G
              d[i + 2] = bin; // B
            }

            ctx.putImageData(imgData, 0, 0);

            preprocessedIdDataUrl = canvas.toDataURL('image/jpeg', 0.92);
            canvas.toBlob(function(blob) {
              preprocessedIdBlob = blob;
              if (onComplete) onComplete(blob, preprocessedIdDataUrl);
            }, 'image/jpeg', 0.92);
          } catch (err) {
            console.warn('Canvas pre-processing warning:', err);
            if (onComplete) onComplete(null, null);
          }
        };
        img.src = e.target.result;
      };
      reader.readAsDataURL(file);
    }

    // Portal ID Camera Streaming and Capture State
    let portalCameraStream = null;

    function togglePortalIdMethod(method) {
      const tabUpload = document.getElementById('portalTabUploadId');
      const tabCamera = document.getElementById('portalTabUseCamera');
      const secUpload = document.getElementById('portalSectionUploadId');
      const secCamera = document.getElementById('portalSectionUseCamera');

      if (method === 'upload') {
        if (tabUpload) {
          tabUpload.className = 'py-2 px-3 rounded-lg text-xs sm:text-sm font-black bg-[#B89B72] text-white shadow-xs flex items-center justify-center gap-1.5 transition cursor-pointer';
        }
        if (tabCamera) {
          tabCamera.className = 'py-2 px-3 rounded-lg text-xs sm:text-sm font-bold bg-[#F5F0E6] text-[#4A3728] hover:bg-[#E8D8B0] flex items-center justify-center gap-1.5 transition cursor-pointer';
        }
        if (secUpload) secUpload.classList.remove('hidden');
        if (secCamera) secCamera.classList.add('hidden');
        stopPortalCamera();
      } else {
        if (tabCamera) {
          tabCamera.className = 'py-2 px-3 rounded-lg text-xs sm:text-sm font-black bg-[#B89B72] text-white shadow-xs flex items-center justify-center gap-1.5 transition cursor-pointer';
        }
        if (tabUpload) {
          tabUpload.className = 'py-2 px-3 rounded-lg text-xs sm:text-sm font-bold bg-[#F5F0E6] text-[#4A3728] hover:bg-[#E8D8B0] flex items-center justify-center gap-1.5 transition cursor-pointer';
        }
        if (secCamera) secCamera.classList.remove('hidden');
        if (secUpload) secUpload.classList.add('hidden');

        const capturedBase64 = document.getElementById('captured_id_base64') ? document.getElementById('captured_id_base64').value : '';
        if (!capturedBase64) {
          startPortalCamera();
        }
      }
      if (window.lucide) lucide.createIcons();
    }

    async function startPortalCamera() {
      const videoEl = document.getElementById('camera_feed');
      const viewport = document.getElementById('portalCameraViewport');
      const card = document.getElementById('portalCapturedCard');
      const btnCap = document.getElementById('btnCaptureId');
      const btnRet = document.getElementById('btnRetakeId');

      if (viewport) viewport.classList.remove('hidden');
      if (card) card.classList.add('hidden');
      if (btnCap) btnCap.classList.remove('hidden');
      if (btnRet) btnRet.classList.add('hidden');

      stopPortalCamera();

      try {
        const stream = await navigator.mediaDevices.getUserMedia({
          video: { facingMode: 'environment', width: { ideal: 1920 }, height: { ideal: 1080 } },
          audio: false
        });
        portalCameraStream = stream;
        if (videoEl) {
          videoEl.srcObject = stream;
          videoEl.play();
        }
      } catch (err) {
        console.warn('Rear camera unavailable, trying any camera:', err);
        try {
          const fallback = await navigator.mediaDevices.getUserMedia({ video: true, audio: false });
          portalCameraStream = fallback;
          if (videoEl) {
            videoEl.srcObject = fallback;
            videoEl.play();
          }
        } catch (fErr) {
          console.error('Camera access failed:', fErr);
          alert('Unable to access device camera. Please grant camera permission or use the "Upload ID" tab.');
          togglePortalIdMethod('upload');
        }
      }
    }

    function stopPortalCamera() {
      if (portalCameraStream) {
        portalCameraStream.getTracks().forEach(t => {
          try { t.stop(); } catch(e) {}
        });
        portalCameraStream = null;
      }
      const videoEl = document.getElementById('camera_feed');
      if (videoEl) videoEl.srcObject = null;
    }

    function capturePortalCamera() {
      const videoEl = document.getElementById('camera_feed');
      const canvasEl = document.getElementById('snapshot');
      const hiddenInput = document.getElementById('captured_id_base64');
      const viewport = document.getElementById('portalCameraViewport');
      const card = document.getElementById('portalCapturedCard');
      const img = document.getElementById('portalCapturedImg');
      const btnCap = document.getElementById('btnCaptureId');
      const btnRet = document.getElementById('btnRetakeId');

      if (!videoEl || !canvasEl) return;
      const w = videoEl.videoWidth || 1280;
      const h = videoEl.videoHeight || 720;
      canvasEl.width = w;
      canvasEl.height = h;
      const ctx = canvasEl.getContext('2d');
      ctx.drawImage(videoEl, 0, 0, w, h);

      const dataUrl = canvasEl.toDataURL('image/jpeg', 0.92);
      if (hiddenInput) hiddenInput.value = dataUrl;
      if (img) img.src = dataUrl;

      if (viewport) viewport.classList.add('hidden');
      if (card) card.classList.remove('hidden');
      if (btnCap) btnCap.classList.add('hidden');
      if (btnRet) btnRet.classList.remove('hidden');

      stopPortalCamera();
      selectedIdFile = null;
      const fInput = document.getElementById('idFileInput');
      if (fInput) fInput.value = '';
      const pCard = document.getElementById('idImagePreviewCard');
      if (pCard) pCard.style.display = 'none';

      if (window.lucide) lucide.createIcons();
    }

    function retakePortalCamera() {
      const hiddenInput = document.getElementById('captured_id_base64');
      if (hiddenInput) hiddenInput.value = '';
      startPortalCamera();
    }

    function openTermsModal(e) {
      if (e) {
        e.preventDefault();
        e.stopPropagation();
      }
      const m = document.getElementById('termsContentModal');
      if (m) {
        m.style.display = 'flex';
        m.style.pointerEvents = 'auto';
      }
    }

    function closeTermsModal() {
      const m = document.getElementById('termsContentModal');
      if (m) {
        m.style.display = 'none';
        m.style.pointerEvents = 'none';
      }
    }

    function agreeTermsFromModal() {
      const chk = document.getElementById('checkTermsAgreement');
      if (chk) chk.checked = true;
      closeTermsModal();
    }

    function openPrivacyModal(e) {
      if (e) {
        e.preventDefault();
        e.stopPropagation();
      }
      const m = document.getElementById('privacyContentModal');
      if (m) {
        m.style.display = 'flex';
        m.style.pointerEvents = 'auto';
      }
    }

    function closePrivacyModal() {
      const m = document.getElementById('privacyContentModal');
      if (m) {
        m.style.display = 'none';
        m.style.pointerEvents = 'none';
      }
    }

    function agreePrivacyFromModal() {
      const chk = document.getElementById('checkPrivacyAgreement');
      if (chk) chk.checked = true;
      closePrivacyModal();
    }

    const hasRegisteredFaceId = <?= !empty($user['id_photo_filename']) ? 'true' : 'false' ?>;
    const registeredIdType = <?= json_encode($user['id_type'] ?? '') ?>;
    const registeredIdPhoto = <?= json_encode($user['id_photo_filename'] ?? '') ?>;

    window.isUpdatingVerifiedId = false;
    window.hasStagedPendingId = false;

    function cancelIdUpdateIfPending() {
      if (window.isUpdatingVerifiedId && (window.hasStagedPendingId || selectedIdFile)) {
        window.hasStagedPendingId = false;
        selectedIdFile = null;
        fetch('verify_face.php', {
          method: 'POST',
          headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
          body: 'action=cancel_id_update'
        }).catch(() => {});
        showToast("ID update cancelled or failed. Your previously verified ID remains active.", "info", 5000);
      }
    }

    function openVerifyAccountModal(isUpdate = false) {
      window.isUpdatingVerifiedId = (window.IS_TENANT_VERIFIED || isUpdate === true);
      window.hasStagedPendingId = false;

      const modal = document.getElementById('verifyAccountModal');
      if (modal) {
        modal.classList.remove('hidden');
        modal.classList.add('open');
        modal.style.display = 'flex';
        modal.style.pointerEvents = 'auto';
      }

      const titleEl = document.getElementById('verifyModalTitleText');
      const subEl = document.getElementById('verifyModalSubText');
      if (titleEl) {
        titleEl.textContent = window.isUpdatingVerifiedId ? 'Update Identity Verification ID' : 'Identity Verification Required';
      }
      if (subEl) {
        subEl.textContent = window.isUpdatingVerifiedId 
          ? 'Upload a new valid Government ID and complete the Live Face Scan to update your verified credentials. Your previously verified ID remains active until this update passes.' 
          : 'To unlock all tenant features, please upload a valid Government ID to verify your identity.';
      }

      goToVerificationStep(1);

      const alertBox = document.getElementById('verifyModalAlert');
      if (alertBox) {
        alertBox.style.display = 'none';
        alertBox.innerHTML = '';
      }

      // Pre-select ID type if on file
      const docSelect = document.getElementById('verifyDocType');
      if (docSelect && !docSelect.value && registeredIdType) {
        docSelect.value = registeredIdType;
      }

      if (window.lucide && typeof window.lucide.createIcons === 'function') {
        window.lucide.createIcons();
      }
    }

    function handleVerificationFileSelect(e) {
      const file = e.target.files && e.target.files[0];
      if (file) {
        handleIdFile(file);
      }
    }

    function removeAttachedPhoto(event) {
      if (event) {
        if (typeof event.stopPropagation === 'function') event.stopPropagation();
        if (typeof event.preventDefault === 'function') event.preventDefault();
        if (typeof event.stopImmediatePropagation === 'function') event.stopImmediatePropagation();
      }

      selectedIdFile = null;
      preprocessedIdBlob = null;
      preprocessedIdDataUrl = '';
      heldOcrRawText = '';

      // 1. Clear the <input type="file"> value
      const fileInput = document.getElementById('idFileInput');
      if (fileInput) fileInput.value = '';

      const previewImg = document.getElementById('idVisualPreviewImg');
      if (previewImg) previewImg.src = '';

      const nameEl = document.getElementById('idPreviewFileName');
      const sizeEl = document.getElementById('idPreviewFileSize');
      if (nameEl) nameEl.textContent = '';
      if (sizeEl) sizeEl.textContent = '';

      // 2. Add the 'hidden' class to the image preview card
      const previewCard = document.getElementById('idImagePreviewCard');
      if (previewCard) {
        previewCard.classList.add('hidden');
        previewCard.style.setProperty('display', 'none', 'important');
      }

      // 3. Remove the 'hidden' class from the dotted drag-and-drop zone
      const dropZone = document.getElementById('idDropZone');
      if (dropZone) {
        dropZone.classList.remove('hidden');
        dropZone.style.setProperty('display', 'flex', 'important');
      }
    }
    window.removeAttachedPhoto = removeAttachedPhoto;

    function removeVerificationIdFile(event) {
      removeAttachedPhoto(event);
    }
    window.removeVerificationIdFile = removeVerificationIdFile;

    function removeIdFile(event) {
      removeAttachedPhoto(event);
    }
    window.removeIdFile = removeIdFile;

    function closeVerifyAccountModal() {
      cancelIdUpdateIfPending();

      const modal = document.getElementById('verifyAccountModal');
      if (modal) {
        modal.classList.add('hidden');
        modal.classList.remove('open');
        modal.style.display = 'none';
        modal.style.pointerEvents = 'none';
      }
      stopLivenessCamera();
      const retryBtn = document.getElementById('btnLivenessRetry');
      if (retryBtn) retryBtn.style.display = 'none';
      const overlay = document.getElementById('livenessFinalizingOverlay');
      if (overlay) overlay.style.display = 'none';
      const alertBox = document.getElementById('verifyModalAlert');
      if (alertBox) {
        alertBox.style.display = 'none';
        alertBox.innerHTML = '';
      }
      isLivenessConfirmed = false;
      lastFaceDetected = false;
      resetLivenessAntiSpoofingState();
    }

    function goToVerificationStep(stepNum) {
      const alertBox = document.getElementById('verifyModalAlert');
      if (alertBox) {
        alertBox.style.display = 'none';
        alertBox.innerHTML = '';
      }
      const retryBtn = document.getElementById('btnLivenessRetry');
      if (retryBtn) retryBtn.style.display = 'none';

      const v1 = document.getElementById('verifyStep1View');
      const v2 = document.getElementById('verifyStep2View');

      if (stepNum === 2) {
        // Transition directly to Step 2 (Live Face Scan)
        currentVerificationStep = 2;
        if (v1) v1.style.display = 'none';
        if (v2) v2.style.display = 'block';

        if (window.lucide && typeof window.lucide.createIcons === 'function') {
          window.lucide.createIcons();
        }

        // Start dedicated liveness webcam and real-time scanning
        startLivenessTestingTab();

      } else {
        // Step 1: Upload ID
        currentVerificationStep = 1;
        stopLivenessCamera();

        if (v1) v1.style.display = 'block';
        if (v2) v2.style.display = 'none';

        if (window.lucide && typeof window.lucide.createIcons === 'function') {
          window.lucide.createIcons();
        }

        // Seamless "Back" navigation: Preserve selectedIdFile, preview card, and idType
        const previewCard = document.getElementById('idImagePreviewCard');
        const dropZone = document.getElementById('idDropZone');
        if (selectedIdFile) {
          if (previewCard) {
            previewCard.classList.remove('hidden');
            previewCard.style.setProperty('display', 'block', 'important');
          }
          if (dropZone) {
            dropZone.classList.add('hidden');
            dropZone.style.setProperty('display', 'none', 'important');
          }
        } else {
          if (previewCard) {
            previewCard.classList.add('hidden');
            previewCard.style.setProperty('display', 'none', 'important');
          }
          if (dropZone) {
            dropZone.classList.remove('hidden');
            dropZone.style.setProperty('display', 'flex', 'important');
          }
        }
      }
    }

    // =========================================================================
    // DEDICATED LIVENESS TESTING & AUTO-CAPTURE (VANILLA JS & face-api.js)
    // 1. Monitors camera feed purely for real-time physical movement.
    // 2. Mathematical state-change anti-spoofing condition.
    // 3. Auto-captures the frame at the moment liveness is verified.
    // 4. Applies ctx.scale(-1, 1) mirror effect to the canvas.
    // 5. Automatically submits Base64 frame + ID image to verify_face.php.
    // =========================================================================

    let livenessStream = null;
    let livenessTrackingTimerId = null;
    let isLivenessTrackingActive = false;
    let isLivenessConfirmed = false;
    let lastFaceDetected = false;
    let isFaceApiModelsLoaded = false;
    let isModelLoadingInProgress = false;

    /**
     * Euclidean distance between two 2D points: dist = sqrt((x1 - x2)^2 + (y1 - y2)^2)
     */
    function euclideanDistance(p1, p2) {
      if (!p1 || !p2) return 0;
      const x1 = (p1.x !== undefined ? p1.x : (p1._x !== undefined ? p1._x : p1[0])) || 0;
      const y1 = (p1.y !== undefined ? p1.y : (p1._y !== undefined ? p1._y : p1[1])) || 0;
      const x2 = (p2.x !== undefined ? p2.x : (p2._x !== undefined ? p2._x : p2[0])) || 0;
      const y2 = (p2.y !== undefined ? p2.y : (p2._y !== undefined ? p2._y : p2[1])) || 0;
      return Math.sqrt((x1 - x2) * (x1 - x2) + (y1 - y2) * (y1 - y2));
    }

    /**
     * Preload face-api.js models (tinyFaceDetector and faceLandmark68Net)
     */
    async function ensureFaceApiModelsLoaded() {
      if (isFaceApiModelsLoaded) return true;
      if (isModelLoadingInProgress) {
        while (isModelLoadingInProgress) {
          await new Promise(r => setTimeout(r, 100));
        }
        return isFaceApiModelsLoaded;
      }

      isModelLoadingInProgress = true;

      if (typeof faceapi === 'undefined') {
        for (let i = 0; i < 25; i++) {
          if (typeof faceapi !== 'undefined') break;
          await new Promise(r => setTimeout(r, 100));
        }
      }

      if (typeof faceapi === 'undefined') {
        console.error('faceapi library is unavailable.');
        isModelLoadingInProgress = false;
        return false;
      }

      const candidateUris = [
        'assets/models',
        '/TaraRent/assets/models',
        '/RentConnect/assets/models',
        'https://cdn.jsdelivr.net/npm/@vladmandic/face-api/model'
      ];

      for (const uri of candidateUris) {
        try {
          await Promise.all([
            faceapi.nets.tinyFaceDetector.loadFromUri(uri),
            faceapi.nets.faceLandmark68Net.loadFromUri(uri)
          ]);
          isFaceApiModelsLoaded = true;
          break;
        } catch (err) {
          console.warn('Could not load models from uri:', uri, err);
        }
      }

      isModelLoadingInProgress = false;
      return isFaceApiModelsLoaded;
    }

    /**
     * Start camera & tracking in Dedicated Liveness Testing Tab
     */
    async function startLivenessTestingTab() {
      pauseLivenessTracking();
      resetLivenessAntiSpoofingState();
      isLivenessConfirmed = false;
      lastFaceDetected = false;

      const overlay = document.getElementById('livenessFinalizingOverlay');
      if (overlay) overlay.style.display = 'none';

      const alertBox = document.getElementById('verifyModalAlert');
      if (alertBox) {
        alertBox.style.display = 'none';
        alertBox.innerHTML = '';
      }

      const retryBtn = document.getElementById('btnLivenessRetry');
      if (retryBtn) retryBtn.style.display = 'none';

      updateLivenessStatusBadge('camera', 'Position your face in the camera.', 'normal');

      const video = document.getElementById('livenessVideo');
      if (!video) return;

      // Start webcam stream for liveness feed if not already running
      try {
        if (!livenessStream || !livenessStream.active) {
          livenessStream = await navigator.mediaDevices.getUserMedia({
            video: { facingMode: 'user', width: { ideal: 640 }, height: { ideal: 480 } },
            audio: false
          });
        }
        if (video.srcObject !== livenessStream) {
          video.srcObject = livenessStream;
        }
        await video.play().catch(() => {});
        updateLivenessStatusBadge('scan-face', 'Position your face in the camera.', 'challenge');
      } catch (err) {
        console.warn('Liveness camera access:', err);
        let userMsg = 'Unable to access camera. Please ensure camera permissions are granted.';
        if (err.name === 'NotAllowedError' || err.name === 'PermissionDeniedError') {
          userMsg = 'Camera permission denied. Please allow camera access in your browser settings to perform live face verification.';
        } else if (err.name === 'NotFoundError' || err.name === 'DevicesNotFoundError') {
          userMsg = 'No camera device detected. Please connect a camera or use a mobile device.';
        } else if (err.name === 'NotReadableError' || err.name === 'TrackStartError') {
          userMsg = 'Camera is in use by another application. Please close other camera apps and retry.';
        }
        updateLivenessStatusBadge('alert-triangle', userMsg, 'spoof');
        if (alertBox) {
          alertBox.style.display = 'block';
          alertBox.innerHTML = `<span style="display:inline-flex;align-items:center;gap:6px;">${window.rcIcon('alert-triangle', 'w-4 h-4')} <span><strong>Camera Error:</strong> ${escapeHtml(userMsg)}</span></span>`;
        }
        showToast(userMsg, 'error');
        if (retryBtn) retryBtn.style.display = 'inline-flex';
        return;
      }

      // Preload models
      await ensureFaceApiModelsLoaded();

      // Start non-blocking tracking loop (150ms interval)
      isLivenessTrackingActive = true;
      scheduleNextLivenessStep();
    }

    // Restart scanning after a failed attempt or reset
    function restartLivenessScan() {
      const retryBtn = document.getElementById('btnLivenessRetry');
      if (retryBtn) retryBtn.style.display = 'none';

      const alertBox = document.getElementById('verifyModalAlert');
      if (alertBox) {
        alertBox.style.display = 'none';
        alertBox.innerHTML = '';
      }

      isLivenessConfirmed = false;
      resetLivenessAntiSpoofingState();
      startLivenessTestingTab();
    }

    // Pause tracking evaluation loop without cutting the active camera stream
    function pauseLivenessTracking() {
      isLivenessTrackingActive = false;
      if (livenessTrackingTimerId) {
        clearTimeout(livenessTrackingTimerId);
        livenessTrackingTimerId = null;
      }
    }

    // Completely stops camera stream and releases webcam hardware on modal exit, failure, or completion
    function stopLivenessCamera() {
      pauseLivenessTracking();
      lastFaceDetected = false;
      if (livenessStream) {
        try {
          livenessStream.getTracks().forEach(t => t.stop());
        } catch (e) {}
        livenessStream = null;
      }
      const v = document.getElementById('livenessVideo');
      if (v) v.srcObject = null;
    }

    // Backwards-compatible alias for camera cleanup
    function stopLivenessTracking() {
      pauseLivenessTracking();
    }

    function scheduleNextLivenessStep() {
      if (!isLivenessTrackingActive || isLivenessConfirmed) return;

      livenessTrackingTimerId = setTimeout(async () => {
        if (!isLivenessTrackingActive || isLivenessConfirmed) return;
        try {
          await executeLivenessDetectionStep();
        } catch (err) {
          console.error('Liveness step error:', err);
        }
        if (isLivenessTrackingActive && !isLivenessConfirmed) {
          scheduleNextLivenessStep();
        }
      }, 150); // 150ms non-blocking interval
    }

    /**
     * Pure physical motion detection (Blink or Head Turn) using relaxed thresholds & real-time landmark tracking.
     * Strictly does NOT gatekeep with custom pixel variance, brightness, or blurriness heuristics.
     * Mathematical state machine prevents static photo / phone screen spoofing.
     * Multi-face check prevents proceeding if more than 1 face is detected.
     */
    async function executeLivenessDetectionStep() {
      if (!isLivenessTrackingActive || isLivenessConfirmed) return;

      const video = document.getElementById('livenessVideo');
      if (!video || video.readyState < 2 || video.videoWidth === 0 || video.paused || video.ended) {
        return;
      }

      // Preload models if needed
      if (!isFaceApiModelsLoaded) {
        await ensureFaceApiModelsLoaded();
      }

      // Multi-face detection check: use detectAllFaces to verify only one person is in camera view
      let detections = [];
      if (typeof faceapi !== 'undefined' && faceapi.nets.tinyFaceDetector && faceapi.nets.tinyFaceDetector.params) {
        try {
          const detectorOptions = new faceapi.TinyFaceDetectorOptions({ inputSize: 320, scoreThreshold: 0.3 });
          detections = await faceapi.detectAllFaces(video, detectorOptions).withFaceLandmarks();
        } catch (err) {
          console.warn('faceapi tracking warning:', err);
        }
      }

      // Condition A: If no face is detected
      if (!detections || detections.length === 0) {
        lastFaceDetected = false;
        updateLivenessStatusBadge('scan-face', 'Position your face in the camera.', 'detecting');
        resetLivenessAntiSpoofingState();
        return;
      }

      // Condition B: If multiple faces are detected, do not proceed with verification
      if (detections.length > 1) {
        lastFaceDetected = false;
        updateLivenessStatusBadge('alert-triangle', 'Multiple faces detected. Please ensure only one person is in view.', 'spoof');
        resetLivenessAntiSpoofingState();
        return;
      }

      // Condition C: Exactly 1 face detected
      const detection = detections[0];
      const landmarks = detection.landmarks ? (detection.landmarks.positions ||
        (typeof detection.landmarks.getPositions === 'function' ? detection.landmarks.getPositions() : null)) : null;

      if (!landmarks || landmarks.length < 68) {
        lastFaceDetected = false;
        updateLivenessStatusBadge('scan-face', 'Position your face in the camera.', 'detecting');
        resetLivenessAntiSpoofingState();
        return;
      }

      // Face confirmed present and tracked!
      lastFaceDetected = true;

      // 1. Calculate Eye Aspect Ratio (EAR) using 68 landmarks
      // Left eye: 36, 37, 38, 39, 40, 41
      const p36 = landmarks[36], p37 = landmarks[37], p38 = landmarks[38];
      const p39 = landmarks[39], p40 = landmarks[40], p41 = landmarks[41];
      const leftVertical1 = euclideanDistance(p37, p41);
      const leftVertical2 = euclideanDistance(p38, p40);
      const leftHorizontal = Math.max(0.001, euclideanDistance(p36, p39));
      const leftEAR = (leftVertical1 + leftVertical2) / (2.0 * leftHorizontal);

      // Right eye: 42, 43, 44, 45, 46, 47
      const p42 = landmarks[42], p43 = landmarks[43], p44 = landmarks[44];
      const p45 = landmarks[45], p46 = landmarks[46], p47 = landmarks[47];
      const rightVertical1 = euclideanDistance(p43, p47);
      const rightVertical2 = euclideanDistance(p44, p46);
      const rightHorizontal = Math.max(0.001, euclideanDistance(p42, p45));
      const rightEAR = (rightVertical1 + rightVertical2) / (2.0 * rightHorizontal);

      const avgEAR = (leftEAR + rightEAR) / 2.0;

      // 2. Calculate Yaw (Head Turn) via Nose-to-Jaw distance ratio
      // Landmark 30 is nose tip, 0 is left jaw, 16 is right jaw
      const noseTip = landmarks[30];
      const leftJaw = landmarks[0];
      const rightJaw = landmarks[16];

      const dLeft = Math.abs(noseTip.x - leftJaw.x);
      const dRight = Math.abs(rightJaw.x - noseTip.x);
      const jawRatio = dLeft / Math.max(0.001, dRight);

      // =========================================================================
      // RELAXED ANTI-SPOOFING STATE MACHINES (STANDARD INDOOR LIGHTING & CAMERAS)
      // =========================================================================

      // --- BLINK STATE MACHINE ---
      // Relaxed thresholds prevent false rejections while verifying genuine motion:
      // 1) Establish open-eye baseline (avgEAR >= 0.24 for >= 2 frames)
      // 2) Actively drop below threshold (avgEAR < 0.20, eyes closed)
      // 3) Rise back above threshold (avgEAR >= 0.23, eyes re-opened)
      if (livenessBlinkState === 'INITIAL') {
        if (avgEAR >= 0.24) {
          livenessOpenFrames++;
          if (livenessOpenFrames >= 2) {
            livenessBlinkState = 'OPEN_OBSERVED';
          }
        } else {
          livenessOpenFrames = 0;
        }
      } else if (livenessBlinkState === 'OPEN_OBSERVED') {
        if (avgEAR < 0.20) {
          livenessBlinkState = 'DROPPED_CLOSED';
        }
      } else if (livenessBlinkState === 'DROPPED_CLOSED') {
        if (avgEAR >= 0.23) {
          livenessBlinkState = 'COMPLETED';
        }
      }

      // --- HEAD TURN STATE MACHINE ---
      // Relaxed yaw thresholds allow standard gentle head turns without losing tracking:
      // 1) Establish centered face baseline (0.70 <= jawRatio <= 1.40 for >= 2 frames)
      // 2) Actively shift away from center (jawRatio < 0.60 or jawRatio > 1.65)
      // 3) Return to center (0.68 <= jawRatio <= 1.45)
      if (livenessHeadState === 'INITIAL') {
        if (jawRatio >= 0.70 && jawRatio <= 1.40) {
          livenessCenterFrames++;
          if (livenessCenterFrames >= 2) {
            livenessHeadState = 'CENTER_OBSERVED';
          }
        } else {
          livenessCenterFrames = 0;
        }
      } else if (livenessHeadState === 'CENTER_OBSERVED') {
        if (jawRatio < 0.60) {
          livenessHeadState = 'TURNED_AWAY';
          livenessTurnDirection = 'left';
        } else if (jawRatio > 1.65) {
          livenessHeadState = 'TURNED_AWAY';
          livenessTurnDirection = 'right';
        }
      } else if (livenessHeadState === 'TURNED_AWAY') {
        if (jawRatio >= 0.68 && jawRatio <= 1.45) {
          livenessHeadState = 'COMPLETED';
        }
      }

      // --- EVALUATION ---
      if (livenessBlinkState === 'COMPLETED' || livenessHeadState === 'COMPLETED') {
        const triggerReason = (livenessBlinkState === 'COMPLETED')
          ? 'Blink cycle confirmed'
          : 'Head turn cycle confirmed';
        console.log('Liveness motion confirmed:', triggerReason);

        // Pause evaluation tracking loop
        pauseLivenessTracking();

        // Update badge to "Liveness detected."
        updateLivenessStatusBadge('sparkles', 'Liveness detected.', 'perfect-capturing');

        // Short stabilization pause to ensure eyes are open and face is forward before auto-capture
        setTimeout(async () => {
          await captureAndSubmitLiveVerification(triggerReason);
        }, 400);
      } else {
        // Dynamic real-time guidance
        if (livenessBlinkState === 'DROPPED_CLOSED') {
          updateLivenessStatusBadge('eye', 'Please blink your eyes.', 'challenge');
        } else if (livenessHeadState === 'TURNED_AWAY') {
          updateLivenessStatusBadge('refresh-cw', 'Turn your head slightly.', 'challenge');
        } else if (livenessBlinkState === 'OPEN_OBSERVED' || livenessHeadState === 'CENTER_OBSERVED') {
          updateLivenessStatusBadge('eye', 'Please blink your eyes.', 'challenge');
        } else {
          updateLivenessStatusBadge('user', 'Face detected.', 'challenge');
        }
      }
    }

    /**
     * Auto-Capture on Liveness Detection & Submit Unified Verification
     * Pauses the video feed, draws current frame to canvas with ctx.scale(-1, 1),
     * and submits Base64 frame + ID image to Face++ via verify_face.php.
     */
    async function captureAndSubmitLiveVerification(reason) {
      if (isLivenessConfirmed) return;
      isLivenessConfirmed = true;

      const video = document.getElementById('livenessVideo');
      const canvas = document.getElementById('livenessCaptureCanvas');
      if (!video || !canvas) {
        isLivenessConfirmed = false;
        return;
      }

      // 1. Draw current active frame to hidden canvas maintaining visual mirror effect
      const width = (video.videoWidth && video.videoWidth > 0) ? video.videoWidth : 640;
      const height = (video.videoHeight && video.videoHeight > 0) ? video.videoHeight : 480;
      canvas.width = width;
      canvas.height = height;

      const ctx = canvas.getContext('2d');
      ctx.save();
      ctx.scale(-1, 1);
      ctx.drawImage(video, -width, 0, width, height);
      ctx.restore();

      // Extract high quality Base64 JPEG frame
      const liveFaceBase64 = canvas.toDataURL('image/jpeg', 0.95);

      // 2. Pause video feed only after frame has been cleanly drawn to canvas
      try {
        video.pause();
      } catch (e) {}

      // 3. Show finalizing overlay UI
      const overlay = document.getElementById('livenessFinalizingOverlay');
      const titleEl = document.getElementById('livenessFinalizingTitle');
      const subEl = document.getElementById('livenessFinalizingSub');
      const spinnerEl = document.getElementById('livenessLoadingSpinner');
      if (overlay) overlay.style.display = 'flex';
      if (spinnerEl) spinnerEl.style.display = 'block';
      if (titleEl) titleEl.textContent = 'Comparing with registered Face ID...';
      if (subEl) subEl.textContent = 'Please hold still while we verify your identity.';

      const ring = document.getElementById('livenessOvalRing');
      if (ring) ring.className = 'face-oval-ring perfect-capturing';

      updateLivenessStatusBadge('clock', 'Comparing with registered Face ID...', 'perfect-capturing');

      // 4. Prepare payload with auto-captured Base64 string + registered Face ID
      const idTypeEl = document.getElementById('idTypeSelect');
      const idType = idTypeEl ? idTypeEl.value : (registeredIdType || '');

      const formData = new FormData();
      formData.append('action', 'complete_verification');
      formData.append('live_face_data', liveFaceBase64);
      if (selectedIdFile) {
        formData.append('id_photo', selectedIdFile);
      }
      if (idType) {
        formData.append('id_type', idType);
      }
      if (heldOcrRawText) {
        formData.append('ocr_raw_text', heldOcrRawText);
      }
      if (preprocessedIdBlob) {
        formData.append('id_photo_processed', preprocessedIdBlob, 'proc_id.jpg');
      }
      formData.append('terms_accepted', '1');
      formData.append('privacy_acknowledged', '1');
      formData.append('liveness_passed', '1');
      formData.append('liveness_reason', reason || 'Liveness detected');

      try {
        const response = await fetch('verify_face.php', {
          method: 'POST',
          body: formData
        });
        const result = await response.json();

        if (response.ok && result.status === 'success') {
          // Release camera immediately upon success
          stopLivenessCamera();

          if (spinnerEl) spinnerEl.style.display = 'none';
          if (titleEl) titleEl.textContent = 'Face verification successful!';
          if (subEl) subEl.textContent = 'Account is now officially verified.';

          updateLivenessStatusBadge('check-circle-2', 'Face verification successful!', 'perfect-capturing');
          showToast('Face verification successful!', 'success');
          showVerificationAlert(true, 'Face verification successful!');

          // Update badges in UI immediately
          const statusBadge = document.getElementById('accountVerificationStatusBadge');
          if (statusBadge) {
            statusBadge.className = 'clay-badge badge-low';
            statusBadge.innerHTML = `<span style="display:inline-flex;align-items:center;gap:4px;">Verified Resident ${window.rcIcon('check', 'w-3.5 h-3.5')}</span>`;
          }
          const promptCard = document.getElementById('verificationPromptCard');
          if (promptCard) promptCard.style.display = 'none';

          setTimeout(() => {
            closeVerifyAccountModal();
            window.location.href = 'tenant_portal.php?section=tenantProfile&tab=accountInfo';
          }, 1500);
        } else {
          // Stop webcam on failure as requested
          stopLivenessCamera();

          const isUpdateFallback = window.isUpdatingVerifiedId || (result && result.was_verified);
          const errMsg = isUpdateFallback 
            ? "ID update cancelled or failed. Your previously verified ID remains active." 
            : (result.message || 'Face verification failed. Please try again.');

          showToast(errMsg, isUpdateFallback ? 'info' : 'error', 6000);
          if (overlay) overlay.style.display = 'none';

          const alertBox = document.getElementById('verifyModalAlert');
          if (alertBox) {
            alertBox.style.display = 'block';
            alertBox.innerHTML = `<span style="display:inline-flex;align-items:center;gap:6px;">${window.rcIcon('alert-triangle', 'w-4 h-4')} <span><strong>${isUpdateFallback ? 'Notice:' : 'Verification Failed:'}</strong> ${escapeHtml(errMsg)}</span></span>`;
          }

          updateLivenessStatusBadge('x-circle', isUpdateFallback ? errMsg : 'Face verification failed. Please try again.', 'spoof');

          // Show retry button
          const retryBtn = document.getElementById('btnLivenessRetry');
          if (retryBtn) retryBtn.style.display = 'inline-flex';
        }
      } catch (err) {
        console.error('Finalize verification error:', err);
        stopLivenessCamera();

        const isUpdateFallback = window.isUpdatingVerifiedId;
        const errMsg = isUpdateFallback 
          ? "ID update cancelled or failed. Your previously verified ID remains active." 
          : 'Face verification failed. Please try again.';

        showToast(errMsg, isUpdateFallback ? 'info' : 'error', 6000);
        if (overlay) overlay.style.display = 'none';

        const alertBox = document.getElementById('verifyModalAlert');
        if (alertBox) {
          alertBox.style.display = 'block';
          alertBox.innerHTML = `<span style="display:inline-flex;align-items:center;gap:6px;">${window.rcIcon('alert-triangle', 'w-4 h-4')} <span><strong>${isUpdateFallback ? 'Notice:' : 'Verification Failed:'}</strong> ${escapeHtml(errMsg)}</span></span>`;
        }

        updateLivenessStatusBadge('x-circle', 'Face verification failed. Please try again.', 'spoof');

        const retryBtn = document.getElementById('btnLivenessRetry');
        if (retryBtn) retryBtn.style.display = 'inline-flex';
      }
    }

    /**
     * Programmatic / Testing helper for automated verification trigger
     */
    function triggerManualLivenessAction() {
      captureAndSubmitLiveVerification('Automated physical movement detection');
    }

    function updateLivenessStatusBadge(icon, text, mode) {
      const iconEl = document.getElementById('livenessStatusIcon');
      const textEl = document.getElementById('livenessStatusText');
      const pill = document.getElementById('livenessStatusPill');
      const ring = document.getElementById('livenessOvalRing');
      const challengeEl = document.getElementById('livenessChallengeText');
      const challengeIconEl = document.getElementById('livenessInstructionIconBox');

      const iconMap = {
        '\uD83D\uDCF7': 'camera',
        '\uD83D\uDC40': 'eye',
        '\u26A0\uFE0F': 'alert-triangle',
        '\u2728': 'sparkles',
        '\uD83D\uDD04': 'refresh-cw',
        '\uD83D\uDC64': 'user',
        '\u23F3': 'clock',
        '\u2705': 'check-circle-2',
        '\u274C': 'x-circle'
      };
      const lucideName = iconMap[icon] || icon || 'eye';

      if (iconEl) iconEl.innerHTML = window.rcIcon(lucideName, 'w-4 h-4');
      if (textEl) textEl.textContent = text;
      if (challengeEl) challengeEl.textContent = text;
      if (challengeIconEl && icon) challengeIconEl.innerHTML = window.rcIcon(lucideName, 'w-5 h-5');

      if (pill) {
        pill.className = 'face-status-pill';
        if (mode === 'liveness') pill.classList.add('status-liveness');
        else if (mode === 'holding' || mode === 'perfect-capturing') pill.classList.add('status-holding');
        else if (mode === 'spoof') pill.classList.add('status-spoof');
      }
      if (ring) {
        ring.className = 'face-oval-ring ' + (mode || 'normal');
      }
    }

    // Stop webcam hardware if the user navigates away or unloads the page
    window.addEventListener('beforeunload', () => {
      stopLivenessCamera();
    });

    // Testing and simulation helpers
    window.euclideanDistance = euclideanDistance;
    window.ensureFaceApiModelsLoaded = ensureFaceApiModelsLoaded;
    window.goToVerificationStep = goToVerificationStep;
    window.startLivenessTestingTab = startLivenessTestingTab;
    window.stopLivenessTracking = stopLivenessTracking;
    window.completeLivenessVerification = captureAndSubmitLiveVerification;
    window.captureAndSubmitLiveVerification = captureAndSubmitLiveVerification;
    window.triggerManualLivenessAction = triggerManualLivenessAction;
    window.restartLivenessScan = restartLivenessScan;
    window.stopLivenessCamera = stopLivenessCamera;

    window.__simulateTenantFaceMatch = function(confidence) {
      console.log('Simulating tenant face match with confidence:', confidence || 88.5);
      captureAndSubmitLiveVerification('Simulated match ' + (confidence || 88.5));
    };

    window.__simulateTenantLivenessAction = function(action) {
      console.log('Simulating tenant liveness action:', action);
      captureAndSubmitLiveVerification('Simulated ' + (action || 'blink'));
    };

    window.__simulateLivenessAction = window.__simulateTenantLivenessAction;

    /**
     * Display a verification notification banner inside the Account Information section.
     * Strictly ensures ONLY one single notification bar is rendered in this interface,
     * completely eliminating redundant duplications.
     *
     * - Error/Warning state: styled with visually distinct soft red claymorphism styling.
     * - Success state: displays clean message "Account Successfully Verified".
     * - Display Duration: specifically kept visible inside Account Information for at least 6 seconds (6000ms).
     *
     * @param {boolean} isSuccess
     * @param {string} [title] Optional title or message
     * @param {string} [message] Status message
     */
    function showVerificationAlert(isSuccess, title, message) {
      // 1. Immediately close the verification modal
      closeVerifyAccountModal();

      // 2. Hide any active toast so there is only one unified notification on the screen
      const toastEl = document.getElementById('clayToast');
      if (toastEl) toastEl.classList.remove('show');

      // 3. Keep user on the "Profile -> Account Information" tab
      if (typeof openProfileView === 'function') {
        openProfileView('accountInfo');
      }
      window.scrollTo({ top: 0, behavior: 'smooth' });

      // 4. Resolve clean message
      const displayMsg = isSuccess
        ? 'Verification Successful'
        : (message || title || 'Verification Failed: Please try again');

      // 5. Hide dashboard alert to strictly prevent any duplication
      const dashAlert = document.getElementById('dashboardVerificationAlert');
      if (dashAlert) {
        dashAlert.style.display = 'none';
      }

      // 6. Render ONLY into the single notification bar inside Account Information
      const alertEl = document.getElementById('accountInfoVerificationAlert');
      if (!alertEl) return;

      alertEl.style.opacity = '1';
      alertEl.style.transform = 'translateY(0)';
      alertEl.style.display = 'block';

      if (isSuccess) {
        alertEl.innerHTML = `
          <div class="alert-clay alert-clay-success" style="padding: 16px 22px; border: 2px solid #a7f3d0; background: #ecfdf5; border-radius: var(--radius-xl); box-shadow: var(--clay-card-shadow); display: flex; align-items: center; justify-content: space-between; gap: 16px; flex-wrap: wrap;">
            <div style="display: flex; align-items: center; gap: 14px;">
              <div style="width: 40px; height: 40px; border-radius: 12px; background: #d1fae5; color: #059669; display: flex; align-items: center; justify-content: center; box-shadow: var(--clay-button-shadow); flex-shrink: 0;">
                ${window.rcIcon('check', 'w-5 h-5')}
              </div>
              <div>
                <div style="font-size: 15px; font-weight: 800; color: #065f46;">
                  Verification Successful
                </div>
              </div>
            </div>
            <div class="clay-badge" style="background: #10b981; color: #ffffff; font-size: 12px; font-weight: 800; padding: 6px 16px; border-radius: 20px;">
              Verified Resident
            </div>
          </div>
        `;
      } else {
        alertEl.innerHTML = `
          <div class="alert-clay alert-clay-danger" style="padding: 16px 22px; border: 2px solid #fecdd3; background: #fff1f2; border-radius: var(--radius-xl); box-shadow: 6px 6px 16px rgba(225, 29, 72, 0.15), inset 2px 2px 4px rgba(255, 255, 255, 0.8); display: flex; align-items: center; justify-content: space-between; gap: 16px; flex-wrap: wrap;">
            <div style="display: flex; align-items: center; gap: 14px; flex: 1;">
              <div style="width: 40px; height: 40px; border-radius: 12px; background: #ffe4e6; color: #e11d48; display: flex; align-items: center; justify-content: center; box-shadow: var(--clay-button-shadow); flex-shrink: 0;">
                ${window.rcIcon('x', 'w-5 h-5')}
              </div>
              <div>
                <div style="font-size: 15px; font-weight: 800; color: #9f1239; margin-bottom: 2px;">
                  Verification Warning
                </div>
                <div style="font-size: 13.5px; font-weight: 600; color: #be123c; line-height: 1.4;">
                  ${displayMsg}
                </div>
              </div>
            </div>
            <button type="button" class="clay-btn clay-btn-primary" style="padding: 9px 20px; font-size: 12.5px; background: linear-gradient(135deg, #f43f5e, #e11d48); box-shadow: 4px 4px 12px rgba(225, 29, 72, 0.35); flex-shrink: 0; display: inline-flex; align-items: center; gap: 6px;" onclick="openVerifyAccountModal()">
              ${window.rcIcon('refresh-cw', 'w-4 h-4')} <span>Verify Account Again</span>
            </button>
          </div>
        `;
      }

      // Clear existing notification timers
      if (window.verificationAlertTimeout) clearTimeout(window.verificationAlertTimeout);
      if (window.verificationFadeTimeout) clearTimeout(window.verificationFadeTimeout);

      // 7. Display Duration: Exactly 6 seconds (6000ms) for notification bar inside Account Information
      const displayDuration = 6000;
      window.verificationAlertTimeout = setTimeout(() => {
        alertEl.style.opacity = '0';
        alertEl.style.transform = 'translateY(-8px)';

        window.verificationFadeTimeout = setTimeout(() => {
          alertEl.style.display = 'none';
          alertEl.style.opacity = '1';
          alertEl.style.transform = 'none';
          if (isSuccess) {
            location.reload();
          }
        }, 500); // 500ms smooth fadeout
      }, displayDuration);
    }

    function showDashboardVerificationAlert(isSuccess, title, message) {
      // 1. Immediately close the verification modal
      closeVerifyAccountModal();

      // 2. Hide active toast
      const toastEl = document.getElementById('clayToast');
      if (toastEl) toastEl.classList.remove('show');

      // 3. Direct user to dashboard overview
      if (typeof switchTenantSection === 'function') {
        const dashboardBtn = document.querySelectorAll('.sidebar-nav-item')[0];
        switchTenantSection('tenantOverview', dashboardBtn);
      }
      window.scrollTo({ top: 0, behavior: 'smooth' });

      // 4. Hide account info alert to strictly prevent any duplication
      const accAlert = document.getElementById('accountInfoVerificationAlert');
      if (accAlert) accAlert.style.display = 'none';

      // 5. Render ONLY into dashboard container
      const alertEl = document.getElementById('dashboardVerificationAlert');
      if (!alertEl) return;

      const displayMsg = isSuccess
        ? 'Verification Successful'
        : (message || title || 'Verification Failed: Please try again');

      alertEl.style.opacity = '1';
      alertEl.style.transform = 'translateY(0)';
      alertEl.style.display = 'block';

      if (isSuccess) {
        alertEl.innerHTML = `
          <div class="alert-clay alert-clay-success" style="padding: 16px 22px; border: 2px solid #a7f3d0; background: #ecfdf5; border-radius: var(--radius-xl); box-shadow: var(--clay-card-shadow); display: flex; align-items: center; justify-content: space-between; gap: 16px; flex-wrap: wrap;">
            <div style="display: flex; align-items: center; gap: 14px;">
              <div style="width: 40px; height: 40px; border-radius: 12px; background: #d1fae5; color: #059669; display: flex; align-items: center; justify-content: center; box-shadow: var(--clay-button-shadow); flex-shrink: 0;">
                ${window.rcIcon('check', 'w-5 h-5')}
              </div>
              <div>
                <div style="font-size: 15px; font-weight: 800; color: #065f46;">
                  Verification Successful
                </div>
              </div>
            </div>
            <div class="clay-badge" style="background: #10b981; color: #ffffff; font-size: 12px; font-weight: 800; padding: 6px 16px; border-radius: 20px;">
              Verified Resident
            </div>
          </div>
        `;
      } else {
        alertEl.innerHTML = `
          <div class="alert-clay alert-clay-danger" style="padding: 16px 22px; border: 2px solid #fecdd3; background: #fff1f2; border-radius: var(--radius-xl); box-shadow: 6px 6px 16px rgba(225, 29, 72, 0.15), inset 2px 2px 4px rgba(255, 255, 255, 0.8); display: flex; align-items: center; justify-content: space-between; gap: 16px; flex-wrap: wrap;">
            <div style="display: flex; align-items: center; gap: 14px; flex: 1;">
              <div style="width: 40px; height: 40px; border-radius: 12px; background: #ffe4e6; color: #e11d48; display: flex; align-items: center; justify-content: center; box-shadow: var(--clay-button-shadow); flex-shrink: 0;">
                ${window.rcIcon('x', 'w-5 h-5')}
              </div>
              <div>
                <div style="font-size: 15px; font-weight: 800; color: #9f1239; margin-bottom: 2px;">
                  Verification Warning
                </div>
                <div style="font-size: 13.5px; font-weight: 600; color: #be123c; line-height: 1.4;">
                  ${displayMsg}
                </div>
              </div>
            </div>
            <button type="button" class="clay-btn clay-btn-primary" style="padding: 9px 20px; font-size: 12.5px; background: linear-gradient(135deg, #f43f5e, #e11d48); box-shadow: 4px 4px 12px rgba(225, 29, 72, 0.35); flex-shrink: 0; display: inline-flex; align-items: center; gap: 6px;" onclick="openVerifyAccountModal()">
              ${window.rcIcon('refresh-cw', 'w-4 h-4')} <span>Verify Account Again</span>
            </button>
          </div>
        `;
      }

      if (window.dashboardVerificationAlertTimeout) clearTimeout(window.dashboardVerificationAlertTimeout);
      if (window.dashboardVerificationFadeTimeout) clearTimeout(window.dashboardVerificationFadeTimeout);

      window.dashboardVerificationAlertTimeout = setTimeout(() => {
        alertEl.style.opacity = '0';
        alertEl.style.transform = 'translateY(-8px)';

        window.dashboardVerificationFadeTimeout = setTimeout(() => {
          alertEl.style.display = 'none';
          alertEl.style.opacity = '1';
          alertEl.style.transform = 'none';
          if (isSuccess) {
            location.reload();
          }
        }, 500);
      }, 6000);
    }

    async function submitFullVerification() {
      const idTypeEl = document.getElementById('idTypeSelect');
      const idType = idTypeEl ? idTypeEl.value : '';
      const alertBox = document.getElementById('verifyModalAlert');
      if (alertBox) alertBox.style.display = 'none';

      if (!idType) {
        showToast('Please select your ID type in Step 1.', 'error');
        goToVerificationStep(1);
        return;
      }

      if (!selectedIdFile) {
        showToast('Please upload an ID image in Step 1.', 'error');
        goToVerificationStep(1);
        return;
      }

      if (!capturedFaceDataUrl && !capturedFaceBlob) {
        showToast('Please capture your live face scan before submitting.', 'error');
        if (alertBox) {
          alertBox.style.display = 'block';
          alertBox.innerHTML = `<span style="display: inline-flex; align-items: center; gap: 6px;">${window.rcIcon('alert-triangle', 'w-4 h-4')} <span>Please click <strong>Capture Face</strong> to take your live selfie photo first.</span></span>`;
        }
        return;
      }

      const btn = document.getElementById('btnSubmitVerification');
      const btnBack = document.getElementById('btnBackToStep1');
      const origText = btn ? btn.innerHTML : 'Submit &amp; Verify Account';

      if (btn) {
        btn.disabled = true;
        btn.innerHTML = '<span>Scanning ID Document (OCR)...</span>';
      }
      if (btnBack) btnBack.disabled = true;

      // Extract raw text from the uploaded ID using client-side Tesseract.js OCR
      let ocrRawText = '';
      if (window.Tesseract && typeof Tesseract.recognize === 'function') {
        try {
          const targetImage = preprocessedIdBlob || selectedIdFile;
          const ocrPromise = Tesseract.recognize(targetImage, 'eng');
          const timeoutPromise = new Promise((_, reject) => setTimeout(() => reject(new Error('OCR Timeout')), 10000));
          const ocrResult = await Promise.race([ocrPromise, timeoutPromise]);
          if (ocrResult && ocrResult.data && ocrResult.data.text) {
            ocrRawText = ocrResult.data.text;
          }
        } catch (ocrErr) {
          console.warn('Client OCR extraction notice:', ocrErr);
        }
      }

      if (btn) {
        btn.innerHTML = '<span>Verifying ID &amp; Face Biometrics...</span>';
      }

      const formData = new FormData();
      formData.append('action', 'verify_account');
      formData.append('id_type', idType);
      formData.append('id_photo', selectedIdFile);
      if (ocrRawText) {
        formData.append('ocr_raw_text', ocrRawText);
      }

      // Pass client-side pre-processed image (Grayscale + Contrast + Binarization) for high-accuracy OCR
      if (preprocessedIdBlob) {
        formData.append('id_photo_processed', preprocessedIdBlob, 'proc_id.jpg');
      }
      if (preprocessedIdDataUrl) {
        formData.append('id_photo_processed_data', preprocessedIdDataUrl);
      }

      if (capturedFaceBlob) {
        formData.append('live_face', capturedFaceBlob, 'live_face.jpg');
      }
      if (capturedFaceDataUrl) {
        formData.append('live_face_data', capturedFaceDataUrl);
      }

      try {
        const res = await fetch('verify_face.php', { method: 'POST', body: formData });
        const result = await res.json();

        // 1. Immediately close the verification modal/tab upon completion
        closeVerifyAccountModal();

        if (res.ok && result.status === 'success') {
          // Simple, clean success message visible for 6 seconds
          showVerificationAlert(true, 'Verification Successful');
          showToast('Verification Successful', 'success');
          setTimeout(() => {
            window.location.href = 'tenant_portal.php?section=tenantProfile&tab=accountInfo';
          }, 1500);
        } else {
          const isUpdateFallback = window.isUpdatingVerifiedId || (result && result.was_verified);
          const errMsg = isUpdateFallback 
            ? "ID update cancelled or failed. Your previously verified ID remains active." 
            : (result.message || 'Face scan does not match the ID photo.');
          showVerificationAlert(false, errMsg);
          showToast(errMsg, isUpdateFallback ? 'info' : 'error', 6000);
        }
      } catch (err) {
        console.error('Verification error:', err);
        // Immediately close the verification modal/tab upon completion
        closeVerifyAccountModal();
        const isUpdateFallback = window.isUpdatingVerifiedId;
        const errMsg = isUpdateFallback 
          ? "ID update cancelled or failed. Your previously verified ID remains active." 
          : 'Face scan does not match the ID photo.';
        showVerificationAlert(false, errMsg);
        showToast(errMsg, isUpdateFallback ? 'info' : 'error', 6000);
      } finally {
        if (btn) {
          btn.disabled = false;
          btn.innerHTML = origText;
        }
        if (btnBack) btnBack.disabled = false;
      }
    }

    function handleIdFile(file) {
      if (file.size > 10 * 1024 * 1024) {
        showToast('ID file exceeds 10MB limit', 'error');
        return;
      }
      selectedIdFile = file;
      preprocessedIdBlob = null;
      preprocessedIdDataUrl = '';
      heldOcrRawText = '';

      // Immediately pre-process image in the background (Grayscale + Contrast + Binarization) for high-accuracy OCR
      preprocessIdImageForOCR(file, function(blob, dataUrl) {
        if (window.Tesseract && typeof Tesseract.recognize === 'function') {
          Tesseract.recognize(blob || file, 'eng').then(res => {
            if (res && res.data && res.data.text) {
              heldOcrRawText = res.data.text;
            }
          }).catch(err => {
            console.warn('Background OCR notice:', err);
          });
        }
      });

      const nameEl = document.getElementById('idPreviewFileName');
      const sizeEl = document.getElementById('idPreviewFileSize');
      if (nameEl) nameEl.textContent = file.name;
      if (sizeEl) sizeEl.textContent = (file.size / 1024 / 1024).toFixed(2) + ' MB';

      // Visual Image Preview: Single Document Upload UI (hide dropzone entirely, only show preview card)
      const previewImg = document.getElementById('idVisualPreviewImg');
      const previewCard = document.getElementById('idImagePreviewCard');
      const dropZone = document.getElementById('idDropZone');

      const showSinglePreviewCard = (src) => {
        if (previewImg && src) previewImg.src = src;
        if (dropZone) {
          dropZone.classList.add('hidden');
          dropZone.style.setProperty('display', 'none', 'important');
        }
        if (previewCard) {
          previewCard.classList.remove('hidden');
          previewCard.style.setProperty('display', 'block', 'important');
        }
      };

      if (file.type && file.type.startsWith('image/')) {
        const reader = new FileReader();
        reader.onload = function(e) {
          showSinglePreviewCard(e.target.result);
        };
        reader.readAsDataURL(file);
      } else {
        showSinglePreviewCard('');
      }

      showToast('ID attached: ' + file.name, 'info');
    }

    function switchPaymentTab(tab) {
      const uploadContent = document.getElementById('tabContentUpload');
      const trackerContent = document.getElementById('tabContentTracker');
      const btnUpload = document.getElementById('tabBtnUpload');
      const btnTracker = document.getElementById('tabBtnTracker');

      if (!uploadContent || !trackerContent || !btnUpload || !btnTracker) return;

      if (tab === 'upload') {
        uploadContent.classList.remove('hidden');
        trackerContent.classList.add('hidden');

        btnUpload.className = 'flex-1 py-3.5 px-6 font-black text-sm md:text-base tracking-wide flex items-center justify-center gap-2 transition-all bg-[#B89B72] text-white rounded-tl-xl border-b-2 border-b-[#B89B72] z-10 cursor-pointer';
        btnTracker.className = 'flex-1 py-3.5 px-6 font-bold text-sm md:text-base tracking-wide flex items-center justify-center gap-2 transition-all bg-[#F5F0E6] text-[#3E2723] rounded-tr-xl border-l border-b-2 border-[#8C6D43] hover:bg-[#E8D8B0] cursor-pointer';
      } else {
        uploadContent.classList.add('hidden');
        trackerContent.classList.remove('hidden');

        btnTracker.className = 'flex-1 py-3.5 px-6 font-black text-sm md:text-base tracking-wide flex items-center justify-center gap-2 transition-all bg-[#B89B72] text-white rounded-tr-xl border-b-2 border-b-[#B89B72] z-10 cursor-pointer';
        btnUpload.className = 'flex-1 py-3.5 px-6 font-bold text-sm md:text-base tracking-wide flex items-center justify-center gap-2 transition-all bg-[#F5F0E6] text-[#3E2723] rounded-tl-xl border-r border-b-2 border-[#8C6D43] hover:bg-[#E8D8B0] cursor-pointer';
      }
    }

    function handleBillingMonthChange() {
      const monthSelect = document.getElementById('payBillingMonth');
      if (!monthSelect) return;
      const opt = monthSelect.options[monthSelect.selectedIndex];
      if (!opt) return;

      const targetIdInput = document.getElementById('payTargetPayment');
      if (targetIdInput && opt.dataset.id) {
        targetIdInput.value = opt.dataset.id;
      }

      const amountInput = document.getElementById('payAmount');
      if (amountInput && opt.dataset.amount) {
        amountInput.value = opt.dataset.amount;
      }

      const payForSelect = document.getElementById('payFor');
      if (payForSelect && opt.dataset.type) {
        const pType = opt.dataset.type.toLowerCase();
        for (let i = 0; i < payForSelect.options.length; i++) {
          if (payForSelect.options[i].value.toLowerCase().includes(pType)) {
            payForSelect.selectedIndex = i;
            break;
          }
        }
      }
    }

    function handleReceiptFileSelect(event) {
      const file = event.target.files && event.target.files[0];
      if (file) handleFile(file);
    }

    function removeAttachedReceipt(event) {
      if (event) {
        event.stopPropagation();
        event.preventDefault();
      }
      attachedFile = null;
      const fileInput = document.getElementById('receiptFileInput');
      if (fileInput) fileInput.value = '';
      const previewCard = document.getElementById('filePreviewCard');
      if (previewCard) {
        previewCard.style.display = 'none';
        previewCard.classList.add('hidden');
      }
    }

    function handleFile(file) {
      if (file.size > 8 * 1024 * 1024) {
        showToast('Receipt exceeds 8MB limit', 'error');
        return;
      }
      attachedFile = file;
      const previewName = document.getElementById('previewFileName');
      const previewSize = document.getElementById('previewFileSize');
      const previewCard = document.getElementById('filePreviewCard');
      if (previewName) previewName.textContent = file.name;
      if (previewSize) previewSize.textContent = (file.size / 1024 / 1024).toFixed(2) + ' MB';
      if (previewCard) {
        previewCard.style.display = 'inline-flex';
        previewCard.classList.remove('hidden');
      }
      showToast('Receipt attached: ' + file.name, 'info');
    }

    function escapeHtml(str) {
      if (str === null || str === undefined) return '';
      return String(str)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');
    }

    function openTenantTicketModal(tk) {
      if (!tk) return;
      const modal = document.getElementById('tenantTicketModal');
      if (!modal) return;
      document.getElementById('ttModalIdBadge').textContent = '#MT-' + String(tk.id).padStart(4, '0');
      document.getElementById('ttModalTitle').textContent = tk.title || 'Maintenance Request';
      
      const catEl = document.getElementById('ttModalCategory');
      if (catEl) {
        catEl.textContent = tk.category || 'General';
        catEl.className = 'clay-badge badge-' + String(tk.category || 'general').toLowerCase();
      }

      const prioEl = document.getElementById('ttModalPriority');
      if (prioEl) {
        prioEl.textContent = String(tk.priority || 'medium').toUpperCase();
        prioEl.className = 'clay-badge badge-' + String(tk.priority || 'medium').toLowerCase();
      }

      const statusEl = document.getElementById('ttModalStatus');
      if (statusEl) {
        const s = tk.status || 'Pending';
        statusEl.textContent = s;
        statusEl.className = 'clay-badge ' + (s === 'Done' ? 'badge-done' : (s === 'On-Going' ? 'badge-ongoing' : 'badge-pending'));
      }

      const unitEl = document.getElementById('ttModalUnit');
      if (unitEl) unitEl.textContent = '<?= htmlspecialchars($unit['unit_number']) ?>';

      document.getElementById('ttModalDesc').textContent = tk.description || 'No description provided.';

      // Photos container
      const photosSec = document.getElementById('ttModalPhotosSection');
      const photosCont = document.getElementById('ttModalPhotosContainer');
      if (photosSec && photosCont) {
        photosCont.innerHTML = '';
        if (tk.photo_path && tk.photo_path.trim() !== '') {
          const pList = tk.photo_path.split(',');
          pList.forEach((pItem, idx) => {
            pItem = pItem.trim();
            if (!pItem) return;
            const a = document.createElement('a');
            a.href = pItem;
            a.target = '_blank';
            a.className = 'btn-inspect-proof';
            a.style.cssText = 'display: inline-flex; align-items: center; gap: 6px; padding: 6px 12px; font-size: 12px; border-radius: 8px; text-decoration: none;';
            a.innerHTML = `${window.rcIcon('image', 'w-3.5 h-3.5')} View Attached Photo ` + (pList.length > 1 ? (idx + 1) : '');
            photosCont.appendChild(a);
          });
          photosSec.style.display = 'block';
        } else {
          photosSec.style.display = 'none';
        }
      }

      // Owner Response / Remarks
      const respBox = document.getElementById('ttModalOwnerResponseBox');
      const respText = document.getElementById('ttModalOwnerResponseText');
      if (tk.resolution_notes && tk.resolution_notes.trim() !== '') {
        respText.textContent = tk.resolution_notes;
        respBox.style.background = '#f0fdf4';
        respBox.style.borderColor = '#bbf7d0';
        respBox.style.color = '#166534';
      } else {
        respText.textContent = 'Awaiting response from property owner. Any updates or instructions will appear here.';
        respBox.style.background = '#f8fafc';
        respBox.style.borderColor = '#e2e8f0';
        respBox.style.color = '#64748b';
      }

      // Dates
      let dateSub = '-';
      if (tk.created_at) {
        dateSub = new Date(tk.created_at.replace(' ', 'T')).toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' });
      }
      document.getElementById('ttModalDateSubmitted').textContent = dateSub;

      let dateRes = 'In progress / Pending';
      if (tk.resolved_at) {
        dateRes = new Date(tk.resolved_at.replace(' ', 'T')).toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' });
      }
      document.getElementById('ttModalDateResolved').textContent = dateRes;

      modal.style.display = 'flex';
      modal.style.pointerEvents = 'auto';
      modal.classList.add('open');
    }

    function closeTenantTicketModal() {
      const modal = document.getElementById('tenantTicketModal');
      if (modal) {
        modal.classList.remove('open');
        modal.style.display = 'none';
        modal.style.pointerEvents = 'none';
      }
    }

    function toggleMaintenanceTabs(tabName) {
      const submitSection = document.getElementById('submit-section');
      const trackSection = document.getElementById('track-section');
      const submitBtn = document.getElementById('btn-submit');
      const trackBtn = document.getElementById('btn-track');

      if (!submitSection || !trackSection || !submitBtn || !trackBtn) return;

      if (tabName === 'submit') {
        submitSection.classList.remove('hidden');
        trackSection.classList.add('hidden');
        submitBtn.className = 'flex-1 py-3.5 px-6 font-black text-sm md:text-base tracking-wide flex items-center justify-center gap-2 transition-all bg-[#B89B72] text-white rounded-tl-xl border-b-2 border-b-[#B89B72] z-10 cursor-pointer active';
        trackBtn.className = 'flex-1 py-3.5 px-6 font-bold text-sm md:text-base tracking-wide flex items-center justify-center gap-2 transition-all bg-[#F5F0E6] text-[#3E2723] rounded-tr-xl border-l border-b-2 border-[#8C6D43] hover:bg-[#E8D8B0] cursor-pointer';
        submitBtn.setAttribute('aria-selected', 'true');
        trackBtn.setAttribute('aria-selected', 'false');
      } else {
        trackSection.classList.remove('hidden');
        submitSection.classList.add('hidden');
        trackBtn.className = 'flex-1 py-3.5 px-6 font-black text-sm md:text-base tracking-wide flex items-center justify-center gap-2 transition-all bg-[#B89B72] text-white rounded-tr-xl border-b-2 border-b-[#B89B72] z-10 cursor-pointer active';
        submitBtn.className = 'flex-1 py-3.5 px-6 font-bold text-sm md:text-base tracking-wide flex items-center justify-center gap-2 transition-all bg-[#F5F0E6] text-[#3E2723] rounded-tl-xl border-r border-b-2 border-[#8C6D43] hover:bg-[#E8D8B0] cursor-pointer';
        trackBtn.setAttribute('aria-selected', 'true');
        submitBtn.setAttribute('aria-selected', 'false');
        if (typeof loadTenantTickets === 'function') {
          loadTenantTickets();
        }
      }
      if (window.lucide && typeof window.lucide.createIcons === 'function') {
        window.lucide.createIcons();
      }
    }
    window.toggleMaintenanceTabs = toggleMaintenanceTabs;

    async function loadTenantTickets() {
      try {
        const res = await fetch('api/ticket_api.php');
        const data = await res.json();
        if (data.status === 'success' && Array.isArray(data.tickets)) {
          const cont = document.getElementById('ticketHistoryContainer');
          const countBadge = document.getElementById('tenantMaintenanceCountBadge');
          if (countBadge) {
            countBadge.textContent = data.tickets.length;
          }
          if (!cont) return;
          if (data.tickets.length === 0) {
            cont.innerHTML = `
              <div class="text-center py-12 px-6 rounded-2xl bg-[#F5F0E6] border-2 border-[#8C6D43]/40 text-[#7A6245]">
                <i data-lucide="inbox" class="w-8 h-8 mx-auto mb-2 opacity-60"></i>
                <p class="font-bold text-base m-0">No maintenance requests submitted yet.</p>
                <p class="text-xs text-[#7A6245] mt-1 mb-0">Submit your first repair request using the "Submit Request" tab.</p>
              </div>`;
            if (window.lucide && typeof window.lucide.createIcons === 'function') {
              window.lucide.createIcons();
            }
            return;
          }
          cont.innerHTML = '';
          data.tickets.forEach((tk, index) => {
            const ticketNum = index + 1;
            const tkStatus = tk.status || 'Pending';
            const tkCategory = tk.category || 'General';
            let progressPct = 25;
            const stLower = String(tkStatus).toLowerCase();
            let statusBadgeClass = 'bg-amber-100 text-amber-900 border-amber-300';
            if (['on-going', 'ongoing', 'assigned', 'in_progress'].includes(stLower)) {
              progressPct = 65;
              statusBadgeClass = 'bg-blue-100 text-blue-900 border-blue-300';
            } else if (['done', 'resolved', 'closed'].includes(stLower)) {
              progressPct = 100;
              statusBadgeClass = 'bg-green-100 text-green-900 border-green-300';
            }
            const dateStr = tk.created_at ? new Date(tk.created_at.replace(' ', 'T')).toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' }) : 'Recent';
            
            const card = document.createElement('div');
            card.className = 'maintenance-track-card p-5 sm:p-6 rounded-2xl bg-[#F5F0E6] border-2 border-[#8C6D43]/40 shadow-md cursor-pointer hover:shadow-lg transition-shadow';
            card.onclick = () => openTenantTicketModal(tk);
            
            card.innerHTML = `
              <div class="flex flex-col sm:flex-row sm:items-start justify-between gap-4">
                <div>
                  <h3 class="text-lg sm:text-xl font-extrabold text-[#3E2723] m-0">
                    ${ticketNum}. ${escapeHtml(tk.title || 'Untitled Request')}
                  </h3>
                  <p class="text-xs sm:text-sm font-semibold text-[#7A6245] mt-1 mb-0">
                    Date Submitted: ${escapeHtml(dateStr)}
                  </p>
                </div>
                <div class="flex items-center gap-2.5 flex-wrap sm:flex-nowrap">
                  <span class="${statusBadgeClass} border font-extrabold text-xs px-3.5 py-1.5 rounded-full shadow-sm min-w-[90px] text-center">
                    ${escapeHtml(tkStatus)}
                  </span>
                  <span class="bg-white/90 text-[#3E2723] border border-[#8C6D43]/30 font-bold text-xs px-3.5 py-1.5 rounded-full shadow-sm min-w-[90px] text-center">
                    ${escapeHtml(tkCategory)}
                  </span>
                </div>
              </div>

              <div class="w-full bg-white/80 h-3.5 rounded-full overflow-hidden my-3.5 border border-[#8C6D43]/30 shadow-inner">
                <div class="bg-[#4A3728] h-full rounded-full transition-all duration-300" style="width: ${progressPct}%;"></div>
              </div>

              <div>
                <span class="block text-sm font-extrabold text-[#3E2723] mb-1.5">Issue Details:</span>
                <div class="bg-white/90 rounded-xl p-4 text-sm text-[#3E2723] leading-relaxed shadow-sm min-h-[60px] whitespace-pre-line border border-[#8C6D43]/20">
                  ${escapeHtml(tk.description || 'No additional details provided.')}
                </div>
              </div>
            `;
            cont.appendChild(card);
          });
          if (window.lucide && typeof window.lucide.createIcons === 'function') {
            window.lucide.createIcons();
          }
        }
      } catch (e) {
        console.warn('Failed to refresh tenant tickets:', e);
      }
    }

    function openTenantReportModal(rep) {
      const modal = document.getElementById('tenantReportModal');
      if (!modal || !rep) return;

      document.getElementById('trModalIdBadge').textContent = '#GR-' + String(rep.id).padStart(4, '0');
      document.getElementById('trModalSubject').textContent = rep.subject || 'General Report';
      document.getElementById('trModalUnit').textContent = rep.unit_number ? ('Unit ' + rep.unit_number) : 'Tenant Unit';
      document.getElementById('trModalCategory').textContent = rep.category || 'General Inquiry';
      document.getElementById('trModalDetails').textContent = rep.details || 'No details provided.';

      const statusText = rep.status || 'Pending';
      const statusSlug = String(statusText).toLowerCase();
      const statusEl = document.getElementById('trModalStatus');
      statusEl.textContent = statusText.toUpperCase();
      statusEl.className = 'report-status-pill report-status-pill--' + statusSlug;

      const attSec = document.getElementById('trModalAttachmentsSection');
      const attCont = document.getElementById('trModalAttachmentsContainer');
      attCont.innerHTML = '';
      if (rep.attachment_path && String(rep.attachment_path).trim() !== '') {
        const files = String(rep.attachment_path).split(',').map(s => s.trim()).filter(Boolean);
        if (files.length > 0) {
          attSec.style.display = 'block';
          files.forEach((src) => {
            const link = document.createElement('a');
            link.href = src;
            link.target = '_blank';
            link.rel = 'noopener';
            if (src.toLowerCase().endsWith('.pdf')) {
              link.style.cssText = 'display:inline-flex;align-items:center;gap:6px;padding:8px 12px;border-radius:8px;background:#FFF9EE;border:1.5px solid rgba(181,143,83,0.4);color:#56380E;font-size:12px;font-weight:700;text-decoration:none;';
              link.innerHTML = `${window.rcIcon('file-text', 'w-4 h-4')} <span>View PDF Document</span>`;
            } else {
              const img = document.createElement('img');
              img.src = src;
              img.alt = 'Report attachment';
              img.style.cssText = 'width:82px;height:82px;object-fit:cover;border-radius:8px;border:1.5px solid var(--clay-border);';
              link.appendChild(img);
            }
            attCont.appendChild(link);
          });
        } else {
          attSec.style.display = 'none';
        }
      } else {
        attSec.style.display = 'none';
      }

      const remarksBox = document.getElementById('trModalOwnerRemarksBox');
      const remarksText = document.getElementById('trModalOwnerRemarksText');
      if (rep.owner_remarks && String(rep.owner_remarks).trim() !== '') {
        remarksText.textContent = rep.owner_remarks;
        if (statusSlug === 'rejected') {
          remarksBox.style.background = '#FEF2F2';
          remarksBox.style.borderColor = '#FECACA';
          remarksBox.style.color = '#991B1B';
        } else if (statusSlug === 'approved') {
          remarksBox.style.background = '#F0FDF4';
          remarksBox.style.borderColor = '#BBF7D0';
          remarksBox.style.color = '#166534';
        } else {
          remarksBox.style.background = '#EFF6FF';
          remarksBox.style.borderColor = '#BFDBFE';
          remarksBox.style.color = '#1E40AF';
        }
      } else {
        remarksText.textContent = statusSlug === 'pending'
          ? 'Awaiting owner review. Any decision or remarks from the owner will appear here.'
          : 'Processed by owner without additional remarks.';
        remarksBox.style.background = '#FFF9EE';
        remarksBox.style.borderColor = 'rgba(181, 143, 83, 0.35)';
        remarksBox.style.color = '#4A3214';
      }

      document.getElementById('trModalDateSubmitted').textContent = rep.created_at
        ? new Date(String(rep.created_at).replace(' ', 'T')).toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' })
        : '-';
      document.getElementById('trModalReviewedAt').textContent = rep.reviewed_at
        ? new Date(String(rep.reviewed_at).replace(' ', 'T')).toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' })
        : 'Pending review';

      modal.style.display = 'flex';
      modal.style.pointerEvents = 'auto';
      modal.classList.add('open');
    }

    function closeTenantReportModal() {
      const modal = document.getElementById('tenantReportModal');
      if (modal) {
        modal.classList.remove('open');
        modal.style.display = 'none';
        modal.style.pointerEvents = 'none';
      }
    }

    async function loadTenantReports() {
      try {
        const res = await fetch('api/report_api.php');
        const data = await res.json();
        if (data.status === 'success' && Array.isArray(data.reports)) {
          __tenantSearchReports = data.reports.map(r => ({
            id: Number(r.id || 0),
            subject: String(r.subject || ''),
            category: String(r.category || ''),
            status: String(r.status || ''),
            created_at: String(r.created_at || ''),
            raw: r
          }));

          const countBadge = document.getElementById('tenantReportsCountBadge');
          if (countBadge) {
            countBadge.textContent = `${data.reports.length} ${data.reports.length === 1 ? 'Report' : 'Reports'}`;
          }

          const cont = document.getElementById('reportHistoryContainer');
          if (!cont) return;
          if (data.reports.length === 0) {
            cont.innerHTML = '<p id="noReportsMsg" style="font-size: 13px; color: var(--text-muted); text-align: center; padding: 18px 8px; margin: 0;">No general reports submitted yet.</p>';
            return;
          }

          cont.innerHTML = '';
          data.reports.forEach(rep => {
            const item = document.createElement('div');
            item.className = 'report-history-item';
            item.setAttribute('role', 'button');
            item.setAttribute('tabindex', '0');
            item.onclick = () => openTenantReportModal(rep);
            item.onkeydown = (ev) => {
              if (ev.key === 'Enter' || ev.key === ' ') {
                ev.preventDefault();
                openTenantReportModal(rep);
              }
            };

            const st = String(rep.status || 'Pending');
            const stSlug = st.toLowerCase();
            const files = String(rep.attachment_path || '').split(',').map(s => s.trim()).filter(Boolean);
            const dateStr = rep.created_at ? new Date(String(rep.created_at).replace(' ', 'T')).toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' }) : '';

            let remarksPreview = '';
            if (rep.owner_remarks && String(rep.owner_remarks).trim() !== '') {
              const trimmed = String(rep.owner_remarks).trim();
              const shortRemarks = trimmed.length > 80 ? trimmed.substring(0, 80) + '...' : trimmed;
              remarksPreview = `<div style="font-size: 12px; color: #4A3214; margin-top: 6px; font-weight: 600; display: flex; align-items: center; gap: 5px;">${window.rcIcon('message-square', 'w-3.5 h-3.5')} <span>Owner remarks: ${escapeHtml(shortRemarks)}</span></div>`;
            }

            item.innerHTML = `
              <div style="min-width: 0; flex: 1;">
                <div style="display: flex; align-items: center; gap: 8px; flex-wrap: wrap; margin-bottom: 5px;">
                  <span class="clay-badge" style="background: #F3E4C6; color: #56380E; font-weight: 800; font-size: 11px;">#GR-${String(rep.id).padStart(4, '0')}</span>
                  <span style="font-size: 11.5px; font-weight: 700; color: #6E3A14; background: #EFE3CC; padding: 2px 8px; border-radius: 999px;">${escapeHtml(rep.category || 'General Inquiry')}</span>
                  <span style="font-size: 11.5px; color: var(--text-muted); font-weight: 600;">${dateStr}</span>
                  ${files.length > 0 ? `<span style="font-size: 11px; font-weight: 700; color: #56380E; display: inline-flex; align-items: center; gap: 4px;">${window.rcIcon('paperclip', 'w-3 h-3')} ${files.length} ${files.length === 1 ? 'file' : 'files'}</span>` : ''}
                </div>
                <h4 style="margin: 0; font-size: 14.5px; font-weight: 800; color: #261909;">${escapeHtml(rep.subject || 'General Report')}</h4>
                ${remarksPreview}
              </div>
              <span class="report-status-pill report-status-pill--${escapeHtml(stSlug)}">${escapeHtml(st.toUpperCase())}</span>
            `;
            cont.appendChild(item);
          });
        }
      } catch (e) {
        console.warn('Failed to refresh tenant reports:', e);
      }
    }

    // Comprehensive Event Listener Initialization (Guarded against blocking errors)
    function initTenantEventListeners() {
      // 0. Universal Modal Backdrop Click-to-Close for Tenant Modals
      try {
        ['tenantTicketModal', 'tenantReportModal', 'verifyAccountModal', 'editAccountInfoModal', 'verificationActionPromptModal', 'termsContentModal', 'privacyContentModal'].forEach(mId => {
          const m = document.getElementById(mId);
          if (m) {
            m.addEventListener('click', (e) => {
              if (e.target === m) closeModal(mId);
            });
          }
        });
      } catch (e) {
        console.warn('Modal backdrop listener notice:', e);
      }

      // Sidebar toggle & shared nav controls are handled by assets/js/sidebar.js and includes/sidebar.php

      // 3. Drop Zone & File Selection for ID Verification
      try {
        const idDropZone = document.getElementById('idDropZone');
        const idFileInput = document.getElementById('idFileInput');
        const btnRemoveAttachedPhoto = document.getElementById('btnRemoveAttachedPhoto') || document.getElementById('btnRemoveIdFile');

        if (btnRemoveAttachedPhoto) {
          btnRemoveAttachedPhoto.addEventListener('click', (e) => {
            e.preventDefault();
            e.stopPropagation();
            removeAttachedPhoto(e);
          });
        }

        if (idDropZone && idFileInput) {
          idDropZone.addEventListener('click', (e) => {
            idFileInput.click();
          });

          ['dragenter', 'dragover'].forEach(n => idDropZone.addEventListener(n, (e) => {
            e.preventDefault();
            idDropZone.classList.add('drag-over');
          }));

          ['dragleave', 'drop'].forEach(n => idDropZone.addEventListener(n, (e) => {
            e.preventDefault();
            idDropZone.classList.remove('drag-over');
          }));

          idDropZone.addEventListener('drop', (e) => {
            e.preventDefault();
            idDropZone.classList.remove('drag-over');
            if (e.dataTransfer && e.dataTransfer.files.length > 0) {
              const file = e.dataTransfer.files[0];
              try {
                const dt = new DataTransfer();
                dt.items.add(file);
                idFileInput.files = dt.files;
              } catch (err) {}
              handleIdFile(file);
            }
          });
        }
      } catch (e) {
        console.warn('ID dropzone listener notice:', e);
      }

      // 3.5 TaraRent Verify Account Form Submission (Step 1 -> Validate & Stage -> Step 2 Live Face Scan)
      try {
        const verifyForm = document.getElementById('verifyAccountForm');
        if (verifyForm) {
          verifyForm.addEventListener('submit', async (e) => {
            e.preventDefault();
            const alertBox = document.getElementById('verifyModalAlert');
            if (alertBox) {
              alertBox.style.display = 'none';
              alertBox.innerHTML = '';
            }

            const docTypeSelect = document.getElementById('verifyDocType');
            const docType = docTypeSelect ? docTypeSelect.value : '';
            const fileInput = document.getElementById('idFileInput');
            const file = (selectedIdFile || (fileInput && fileInput.files && fileInput.files[0]));
            const termsCheck = document.getElementById('checkTermsAgreement');

            if (!docType) {
              const msg = 'Please select a valid Document Type from the dropdown.';
              showToast(msg, 'error');
              if (alertBox) {
                alertBox.style.display = 'block';
                alertBox.innerHTML = `<span style="display:flex;align-items:center;gap:8px;">${window.rcIcon('alert-triangle', 'w-4 h-4 text-red-600')}<span>${escapeHtml(msg)}</span></span>`;
              }
              return;
            }

            if (!file) {
              const msg = 'Please upload or attach a photo of your ID document.';
              showToast(msg, 'error');
              if (alertBox) {
                alertBox.style.display = 'block';
                alertBox.innerHTML = `<span style="display:flex;align-items:center;gap:8px;">${window.rcIcon('alert-triangle', 'w-4 h-4 text-red-600')}<span>${escapeHtml(msg)}</span></span>`;
              }
              return;
            }

            if (termsCheck && !termsCheck.checked) {
              const msg = 'Please certify that this document is valid and accurately represents your identity.';
              showToast(msg, 'error');
              if (alertBox) {
                alertBox.style.display = 'block';
                alertBox.innerHTML = `<span style="display:flex;align-items:center;gap:8px;">${window.rcIcon('alert-triangle', 'w-4 h-4 text-red-600')}<span>${escapeHtml(msg)}</span></span>`;
              }
              return;
            }

            selectedIdFile = file;

            const submitBtn = document.getElementById('btnSubmitVerification');
            const origHtml = submitBtn ? submitBtn.innerHTML : 'Submit for Verification';
            if (submitBtn) {
              submitBtn.disabled = true;
              submitBtn.innerHTML = '<span class="clay-spinner" style="width: 16px; height: 16px; border-width: 2px; vertical-align: middle; margin-right: 8px;"></span> Validating Document...';
            }

            // Extract OCR text with Tesseract.js if not already held
            let ocrText = heldOcrRawText || '';
            if (!ocrText && window.Tesseract && typeof Tesseract.recognize === 'function') {
              try {
                const targetImage = preprocessedIdBlob || file;
                const ocrPromise = Tesseract.recognize(targetImage, 'eng');
                const timeoutPromise = new Promise((_, reject) => setTimeout(() => reject(new Error('OCR Timeout')), 5000));
                const ocrResult = await Promise.race([ocrPromise, timeoutPromise]);
                if (ocrResult && ocrResult.data && ocrResult.data.text) {
                  ocrText = ocrResult.data.text;
                  heldOcrRawText = ocrText;
                }
              } catch (ocrErr) {
                console.warn('Quick client OCR check notice:', ocrErr);
              }
            }

            const formData = new FormData();
            formData.append('action', 'validate_and_stage_id');
            formData.append('id_type', docType);
            formData.append('id_photo', file);
            if (ocrText) {
              formData.append('ocr_raw_text', ocrText);
            }
            if (preprocessedIdBlob) {
              formData.append('id_photo_processed', preprocessedIdBlob, 'proc_id.jpg');
            }
            formData.append('terms_accepted', '1');
            formData.append('privacy_acknowledged', '1');

            try {
              const res = await fetch('api/profile_api.php', {
                method: 'POST',
                body: formData
              });
              const data = await res.json();

              if (res.ok && data.status === 'success') {
                window.hasStagedPendingId = true;
                // Document type cross-check PASSED!
                // Seamlessly transition directly to Step 2 (Live Face Scan)
                if (submitBtn) {
                  submitBtn.disabled = false;
                  submitBtn.innerHTML = origHtml;
                }
                goToVerificationStep(2);
              } else {
                // Document mismatch or validation error! Halt process!
                const errMsg = data.message || 'Verification Failed: The uploaded document does not match the selected ID type.';
                showToast(errMsg, 'error');
                if (alertBox) {
                  alertBox.style.display = 'block';
                  alertBox.innerHTML = `<span style="display:flex;align-items:center;gap:8px;">${window.rcIcon('alert-triangle', 'w-4 h-4 text-red-600')}<span><strong>Verification Failed:</strong> ${escapeHtml(errMsg)}</span></span>`;
                }
                if (submitBtn) {
                  submitBtn.disabled = false;
                  submitBtn.innerHTML = origHtml;
                }
              }
            } catch (err) {
              console.error('ID Document validation error:', err);
              const errMsg = 'Verification Failed: The uploaded document does not match the selected ID type.';
              showToast(errMsg, 'error');
              if (alertBox) {
                alertBox.style.display = 'block';
                alertBox.innerHTML = `<span style="display:flex;align-items:center;gap:8px;">${window.rcIcon('alert-triangle', 'w-4 h-4 text-red-600')}<span><strong>Verification Error:</strong> Unable to validate document type. Please try again.</span></span>`;
              }
              if (submitBtn) {
                submitBtn.disabled = false;
                submitBtn.innerHTML = origHtml;
              }
            }
          });
        }
      } catch (e) {
        console.warn('Verify form listener notice:', e);
      }

      // 4. Change Password Form Submission
      try {
        const passForm = document.getElementById('changePasswordForm');
        if (passForm) {
          passForm.addEventListener('submit', async (e) => {
            e.preventDefault();
            const currentPassEl = document.getElementById('profCurrentPass');
            const newPassEl = document.getElementById('profNewPass');
            const confirmPassEl = document.getElementById('profConfirmPass');
            const currentPassword = currentPassEl ? currentPassEl.value : '';
            const newPassword = newPassEl ? newPassEl.value : '';
            const confirmPassword = confirmPassEl ? confirmPassEl.value : '';

            if (newPassword !== confirmPassword) {
              showToast('New passwords do not match.', 'error');
              return;
            }

            if (newPassword.length < 6) {
              showToast('Password must be at least 6 characters.', 'error');
              return;
            }

            const btn = document.getElementById('btnSavePassword');
            if (btn) {
              btn.disabled = true;
              btn.textContent = 'Updating...';
            }

            try {
              const res = await fetch('api/profile_api.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                  action: 'change_password',
                  current_password: currentPassword,
                  new_password: newPassword,
                  confirm_password: confirmPassword
                })
              });

              const data = await res.json();
              if (res.ok && data.status === 'success') {
                showToast(data.message, 'success');
                passForm.reset();
                // Contextual Routing: Refresh and keep tenant on the Profile -> Change Password page
                setTimeout(() => {
                  window.location.href = 'tenant_portal.php?section=tenantProfile&tab=changePassword';
                }, 1000);
              } else {
                showToast(data.message || 'Error updating password', 'error');
              }
            } catch (err) {
              showToast('Network error updating password', 'error');
            } finally {
              if (btn) {
                btn.disabled = false;
                btn.textContent = 'Update Password';
              }
            }
          });
        }
      } catch (e) {
        console.warn('Password form listener notice:', e);
      }

      // 5. Edit Account Information Form Submission
      try {
        const editInfoForm = document.getElementById('editAccountInfoForm');
        if (editInfoForm) {
          editInfoForm.addEventListener('submit', async (e) => {
            e.preventDefault();
            const btn = document.getElementById('btnSaveAccountInfo');
            if (btn) {
              btn.disabled = true;
              btn.textContent = 'Saving...';
            }

            const payload = {
              action: 'update_account_info',
              email: document.getElementById('editEmail').value.trim(),
              phone: document.getElementById('editPhone').value.trim(),
              emergency_contact_name: document.getElementById('editEmergencyName').value.trim(),
              emergency_contact_relationship: document.getElementById('editEmergencyRel').value.trim(),
              emergency_contact_phone: document.getElementById('editEmergencyPhone').value.trim()
            };

            try {
              const res = await fetch('api/profile_api.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(payload)
              });
              const result = await res.json();
              if (res.ok && result.status === 'success') {
                showToast(result.message, 'success');
                closeEditAccountInfoModal();
                // Contextual Routing: Refresh and keep tenant on the Profile -> Account Information page
                setTimeout(() => {
                  window.location.href = 'tenant_portal.php?section=tenantProfile&tab=accountInfo';
                }, 1000);
              } else {
                showToast(result.message || 'Error updating account information', 'error');
              }
            } catch (err) {
              showToast('Network error updating account information', 'error');
            } finally {
              if (btn) {
                btn.disabled = false;
                btn.textContent = 'Save Information';
              }
            }
          });
        }
      } catch (e) {
        console.warn('Edit account info form listener notice:', e);
      }

      // 6. Urgency Level Radio Group
      try {
        document.querySelectorAll('input[name="ticketPriority"]').forEach(radio => {
          radio.addEventListener('change', () => {
            if (radio.checked) {
              currentPrio = radio.value || 'urgent';
            }
          });
        });
      } catch (e) {
        console.warn('Urgency level radio listener notice:', e);
      }

      // 7. Maintenance Ticket Form Submission, Custom Dropdown & Photo Attachment
      try {
        const maintForm = document.getElementById('maintenanceForm');
        const photoInput = document.getElementById('ticketPhotos');
        const photoPreview = document.getElementById('ticketPhotosPreview');
        const catWrap = document.getElementById('ticketCategoryWrap');
        const catSelect = document.getElementById('ticketCategory');
        const catTrigger = document.getElementById('ticketCategoryTrigger');
        const catDisplay = document.getElementById('ticketCategoryDisplay');
        const catOptions = catWrap ? Array.from(catWrap.querySelectorAll('.maintenance-select-option')) : [];
        const categoryEnumMap = {
          Structural: 'Structural',
          Plumbing: 'Plumbing',
          Electrical: 'Electrical',
          Carpentry: 'Carpentry',
          Sanitation: 'General',
          General: 'General'
        };

        const syncCategoryUI = (val) => {
          if (!catSelect || !catDisplay) return;
          catSelect.value = val || '';
          const matched = catOptions.find(opt => opt.dataset.value === catSelect.value);
          catDisplay.textContent = matched ? matched.textContent.trim() : '';
          catOptions.forEach(opt => {
            opt.classList.toggle('is-selected', opt.dataset.value === catSelect.value);
          });
        };

        const closeCategoryMenu = () => {
          if (!catWrap || !catTrigger) return;
          catWrap.classList.remove('is-open');
          catTrigger.setAttribute('aria-expanded', 'false');
        };

        if (catWrap && catTrigger && catSelect) {
          catTrigger.addEventListener('click', (e) => {
            e.stopPropagation();
            const willOpen = !catWrap.classList.contains('is-open');
            catWrap.classList.toggle('is-open', willOpen);
            catTrigger.setAttribute('aria-expanded', willOpen ? 'true' : 'false');
          });

          catOptions.forEach(opt => {
            opt.addEventListener('click', (e) => {
              e.stopPropagation();
              syncCategoryUI(opt.dataset.value || '');
              closeCategoryMenu();
              catTrigger.focus();
            });
          });

          catSelect.addEventListener('change', () => {
            syncCategoryUI(catSelect.value);
          });

          document.addEventListener('click', (e) => {
            if (!catWrap.contains(e.target)) {
              closeCategoryMenu();
            }
          });

          document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape' && catWrap.classList.contains('is-open')) {
              closeCategoryMenu();
              catTrigger.focus();
            }
          });
        }

        if (photoInput && photoPreview) {
          const uploadLabel = document.getElementById('ticketPhotosUploadLabel');
          photoInput.addEventListener('change', function() {
            photoPreview.innerHTML = '';
            if (this.files && this.files.length > 0) {
              if (uploadLabel) {
                uploadLabel.textContent = this.files.length === 1 ? this.files[0].name : (this.files.length + ' Photos Attached');
              }
              photoPreview.style.display = 'flex';
              Array.from(this.files).forEach((file) => {
                if (file.type.startsWith('image/')) {
                  const imgWrapper = document.createElement('div');
                  imgWrapper.style.position = 'relative';
                  imgWrapper.style.display = 'inline-block';

                  const img = document.createElement('img');
                  img.src = URL.createObjectURL(file);
                  img.style.width = '64px';
                  img.style.height = '64px';
                  img.style.objectFit = 'cover';
                  img.style.borderRadius = 'var(--radius-sm)';
                  img.style.border = '1.5px solid var(--clay-border)';
                  img.title = file.name;

                  imgWrapper.appendChild(img);
                  photoPreview.appendChild(imgWrapper);
                }
              });
            } else {
              if (uploadLabel) {
                uploadLabel.textContent = 'Upload Photo';
              }
              photoPreview.style.display = 'none';
            }
          });
        }

        if (maintForm) {
          maintForm.addEventListener('submit', async (e) => {
            e.preventDefault();
            if (!IS_TENANT_VERIFIED) {
              openVerificationActionPrompt('maintenance');
              return;
            }
            const titleEl = document.getElementById('ticketTitle');
            const descEl = document.getElementById('ticketDesc');
            const checkedPrioEl = document.querySelector('input[name="ticketPriority"]:checked');
            const title = titleEl ? titleEl.value.trim() : '';
            const desc = descEl ? descEl.value.trim() : '';
            const rawCat = catSelect ? catSelect.value : '';
            if (!rawCat) {
              showToast('Please select a repair category.', 'error');
              if (catWrap && catTrigger) {
                catWrap.classList.add('is-open');
                catTrigger.setAttribute('aria-expanded', 'true');
                catTrigger.focus();
              }
              return;
            }
            const cat = categoryEnumMap[rawCat] || 'General';
            const priorityToSend = checkedPrioEl ? checkedPrioEl.value : (currentPrio || 'urgent');

            const btn = document.getElementById('btnLogTicket');
            if (btn) {
              btn.disabled = true;
              btn.textContent = 'Logging...';
            }

            try {
              const formData = new FormData();
              formData.append('title', title);
              formData.append('description', desc);
              formData.append('category', cat);
              formData.append('priority', priorityToSend);

              if (photoInput && photoInput.files && photoInput.files.length > 0) {
                for (let i = 0; i < photoInput.files.length; i++) {
                  formData.append('maintenance_photos[]', photoInput.files[i]);
                }
              }

              const res = await fetch('api/ticket_api.php', {
                method: 'POST',
                body: formData
              });
              const result = await res.json();
              if (res.ok && result.status === 'success') {
                showToast(result.message);
                maintForm.reset();
                syncCategoryUI('');
                closeCategoryMenu();
                currentPrio = 'urgent';
                if (photoPreview) {
                  photoPreview.innerHTML = '';
                  photoPreview.style.display = 'none';
                }
                const uploadLabel = document.getElementById('ticketPhotosUploadLabel');
                if (uploadLabel) {
                  uploadLabel.textContent = 'Upload Photo';
                }
                // Dynamically refresh ticket state if listener/container is present
                loadTenantTickets();
                if (typeof toggleMaintenanceTabs === 'function') {
                  toggleMaintenanceTabs('track');
                }
              } else if (res.status === 403 && result.code === 'ACCOUNT_NOT_VERIFIED') {
                showFeatureLockedAlert('submit maintenance tickets', result.message);
              } else {
                showToast(result.message || 'Error logging ticket', 'error');
              }
            } catch (err) {
              showToast('Network error submitting ticket', 'error');
            } finally {
              if (btn) {
                btn.disabled = false;
                btn.textContent = 'Submit Request';
              }
            }
          });
        }
      } catch (e) {
        console.warn('Maintenance form listener notice:', e);
      }

      // 7B. General Report Form Submission, Custom Dropdown & File/Photo Attachments
      try {
        const repForm = document.getElementById('reportForm');
        const repFileInput = document.getElementById('reportAttachments');
        const repFilePreview = document.getElementById('reportAttachmentsPreview');
        const repFileLabel = document.getElementById('reportAttachmentsLabel');
        const repCatWrap = document.getElementById('reportCategoryWrap');
        const repCatSelect = document.getElementById('reportCategory');
        const repCatTrigger = document.getElementById('reportCategoryTrigger');
        const repCatDisplay = document.getElementById('reportCategoryDisplay');
        const repCatOptions = repCatWrap ? Array.from(repCatWrap.querySelectorAll('.maintenance-select-option')) : [];
        const repPlaceholder = 'Select a request category...';

        const syncReportCatUI = (val) => {
          if (!repCatSelect || !repCatDisplay) return;
          repCatSelect.value = val || '';
          const matched = repCatOptions.find(opt => opt.dataset.value === repCatSelect.value);
          repCatDisplay.textContent = matched ? matched.textContent.trim() : repPlaceholder;
          repCatDisplay.classList.toggle('is-placeholder', !matched);
          repCatOptions.forEach(opt => {
            opt.classList.toggle('is-selected', opt.dataset.value === repCatSelect.value);
          });
        };

        const closeReportCatMenu = () => {
          if (!repCatWrap || !repCatTrigger) return;
          repCatWrap.classList.remove('is-open');
          repCatTrigger.setAttribute('aria-expanded', 'false');
        };

        if (repCatWrap && repCatTrigger && repCatSelect) {
          repCatTrigger.addEventListener('click', (e) => {
            e.stopPropagation();
            const willOpen = !repCatWrap.classList.contains('is-open');
            repCatWrap.classList.toggle('is-open', willOpen);
            repCatTrigger.setAttribute('aria-expanded', willOpen ? 'true' : 'false');
          });

          repCatOptions.forEach(opt => {
            opt.addEventListener('click', (e) => {
              e.stopPropagation();
              syncReportCatUI(opt.dataset.value || '');
              closeReportCatMenu();
              repCatTrigger.focus();
            });
          });

          repCatSelect.addEventListener('change', () => {
            syncReportCatUI(repCatSelect.value);
          });

          document.addEventListener('click', (e) => {
            if (!repCatWrap.contains(e.target)) {
              closeReportCatMenu();
            }
          });

          document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape') {
              if (repCatWrap.classList.contains('is-open')) {
                closeReportCatMenu();
                repCatTrigger.focus();
              }
              if (document.getElementById('tenantReportModal')?.classList.contains('open')) {
                closeTenantReportModal();
              }
            }
          });
        }

        if (repFileInput && repFilePreview) {
          repFileInput.addEventListener('change', function() {
            repFilePreview.innerHTML = '';
            if (this.files && this.files.length > 0) {
              if (repFileLabel) {
                repFileLabel.textContent = this.files.length === 1 ? this.files[0].name : (this.files.length + ' Files Attached');
              }
              repFilePreview.style.display = 'flex';
              Array.from(this.files).forEach((file) => {
                if (file.type.startsWith('image/')) {
                  const img = document.createElement('img');
                  img.src = URL.createObjectURL(file);
                  img.alt = file.name;
                  img.title = file.name;
                  img.style.cssText = 'width:64px;height:64px;object-fit:cover;border-radius:var(--radius-sm);border:1.5px solid var(--clay-border);';
                  repFilePreview.appendChild(img);
                } else {
                  const chip = document.createElement('span');
                  chip.innerHTML = `${window.rcIcon('file-text', 'w-3.5 h-3.5')} <span>${escapeHtml(file.name)}</span>`;
                  chip.style.cssText = 'display:inline-flex;align-items:center;gap:6px;padding:6px 10px;border-radius:6px;background:#FFFDF8;border:1px solid rgba(74,48,16,0.3);color:#4A3214;font-size:12px;font-weight:700;';
                  repFilePreview.appendChild(chip);
                }
              });
            } else {
              if (repFileLabel) {
                repFileLabel.textContent = 'Upload File/Photo';
              }
              repFilePreview.style.display = 'none';
            }
          });
        }

        if (repForm) {
          repForm.addEventListener('submit', async (e) => {
            e.preventDefault();
            if (!IS_TENANT_VERIFIED) {
              openVerificationActionPrompt('report');
              return;
            }
            const category = repCatSelect ? repCatSelect.value : '';
            const subjectEl = document.getElementById('reportSubject');
            const detailsEl = document.getElementById('reportDetails');
            const subject = subjectEl ? subjectEl.value.trim() : '';
            const details = detailsEl ? detailsEl.value.trim() : '';

            if (!category) {
              showToast('Please select a Type of Request.', 'error');
              if (repCatTrigger) repCatTrigger.focus();
              return;
            }
            if (!subject || !details) {
              showToast('Subject and Request Details are required.', 'error');
              return;
            }

            const btn = document.getElementById('btnSubmitReport');
            if (btn) {
              btn.disabled = true;
              btn.textContent = 'Submitting Report...';
            }

            try {
              const formData = new FormData();
              formData.append('category', category);
              formData.append('subject', subject);
              formData.append('details', details);

              if (repFileInput && repFileInput.files && repFileInput.files.length > 0) {
                for (let i = 0; i < repFileInput.files.length; i++) {
                  formData.append('report_attachments[]', repFileInput.files[i]);
                }
              }

              const res = await fetch('api/report_api.php', {
                method: 'POST',
                body: formData
              });
              const result = await res.json();
              if (res.ok && result.status === 'success') {
                showToast(result.message || 'General report submitted!');
                repForm.reset();
                syncReportCatUI('');
                closeReportCatMenu();
                if (repFilePreview) {
                  repFilePreview.innerHTML = '';
                  repFilePreview.style.display = 'none';
                }
                if (repFileLabel) {
                  repFileLabel.textContent = 'Upload File/Photo';
                }
                loadTenantReports();
              } else if (res.status === 403 && result.code === 'ACCOUNT_NOT_VERIFIED') {
                showFeatureLockedAlert('submit general reports', result.message);
              } else {
                showToast(result.message || 'Error submitting report', 'error');
              }
            } catch (err) {
              showToast('Network error submitting report', 'error');
            } finally {
              if (btn) {
                btn.disabled = false;
                btn.textContent = 'Submit Report';
              }
            }
          });
        }
      } catch (e) {
        console.warn('General report form listener notice:', e);
      }

      // 8. Payment Drop-Zone & Receipt Upload Submission
      try {
        const dropZone = document.getElementById('receiptDropZone');
        const fileInput = document.getElementById('receiptFileInput');
        const previewCard = document.getElementById('filePreviewCard');
        const btnRemoveFile = document.getElementById('btnRemoveFile');
        const btnUploadReceipt = document.getElementById('btnUploadReceipt');
        const paymentForm = document.getElementById('paymentUploadForm');

        if (dropZone && fileInput) {
          dropZone.addEventListener('click', (e) => {
            if (e.target !== btnRemoveFile && !e.target.closest('#btnRemoveFile')) fileInput.click();
          });

          ['dragenter', 'dragover'].forEach(n => dropZone.addEventListener(n, (e) => {
            e.preventDefault();
            dropZone.classList.add('drag-over');
          }));

          ['dragleave', 'drop'].forEach(n => dropZone.addEventListener(n, (e) => {
            e.preventDefault();
            dropZone.classList.remove('drag-over');
          }));

          dropZone.addEventListener('drop', (e) => {
            e.preventDefault();
            dropZone.classList.remove('drag-over');
            if (e.dataTransfer && e.dataTransfer.files.length > 0) {
              fileInput.files = e.dataTransfer.files;
              handleFile(e.dataTransfer.files[0]);
            }
          });

          fileInput.addEventListener('change', () => {
            if (fileInput.files && fileInput.files.length > 0) handleFile(fileInput.files[0]);
          });
        }

        // Initialize dynamic amount and obligation from currently selected billing option
        if (typeof handleBillingMonthChange === 'function') {
          handleBillingMonthChange();
        }

        if (btnRemoveFile) {
          btnRemoveFile.addEventListener('click', (e) => {
            e.preventDefault();
            e.stopPropagation();
            attachedFile = null;
            if (fileInput) fileInput.value = '';
            if (previewCard) previewCard.style.display = 'none';
          });
        }

        if (paymentForm) {
          paymentForm.addEventListener('submit', function(e) {
            if (!IS_TENANT_VERIFIED) {
              e.preventDefault();
              openVerificationActionPrompt('payment');
              return false;
            }
            if (!fileInput || !fileInput.files || fileInput.files.length === 0) {
              e.preventDefault();
              showToast('Please attach or drop a payment receipt first.', 'error');
              return false;
            }
            const checkAmountEl = document.getElementById('payAmount');
            if (!checkAmountEl || !checkAmountEl.value || parseFloat(checkAmountEl.value) <= 0) {
              e.preventDefault();
              showToast('Please enter a valid payment amount.', 'error');
              return false;
            }
            if (btnUploadReceipt) {
              btnUploadReceipt.disabled = true;
              btnUploadReceipt.innerHTML = '<span>Submitting Proof...</span>';
            }
            return true;
          });
        }
      } catch (e) {
        console.warn('Payment receipt dropzone listener notice:', e);
      }

      // Universal Contextual Routing: Restore active section and tab on page load
      const urlParams = new URLSearchParams(window.location.search);
      const targetSec = urlParams.get('section');
      const targetTab = urlParams.get('tab') || 'accountInfo';

      // Auto-switch to Payment Tracker tab & surface toast if redirected with success
      if (urlParams.get('tab') === 'tracker' || urlParams.get('success') === '1') {
        if (typeof switchPaymentTab === 'function') {
          switchPaymentTab('tracker');
        }
        if (urlParams.get('success') === '1' && typeof showToast === 'function') {
          showToast('Payment proof submitted successfully! Your receipt is now pending review.', 'success');
        }
      }

      // Auto-switch to Report Status Tracker tab & surface toast if redirected with report success
      if (targetSec === 'tenantReport' || urlParams.get('success') === 'report_submitted') {
        if (urlParams.get('tab') === 'tracker' || urlParams.get('success') === 'report_submitted') {
          if (typeof switchApprovalTab === 'function') {
            switchApprovalTab('tracker');
          }
          if (urlParams.get('success') === 'report_submitted' && typeof showToast === 'function') {
            showToast('Report and Approval Request submitted successfully! Awaiting owner review.', 'success');
          }
        }
      }

      if (urlParams.get('restricted') === 'unverified') {
        if (typeof showToast === 'function') {
          showToast('Access restricted: Please complete account verification to access that feature.', 'error');
        }
      } else if (urlParams.get('restricted') === 'unpaid') {
        if (typeof showToast === 'function') {
          showToast('Access to Maintenance and Reports is restricted until your unpaid balance is settled.', 'warning');
        }
      } else if (urlParams.get('restricted') === 'moved_out') {
        if (typeof showToast === 'function') {
          showToast('Navigation restricted: Only Dashboard and Upload Payment are accessible while your move-out balance remains unsettled.', 'warning');
        }
      }

      // Initialize Settings button visibility based on whether Profile section is active
      updateProfileSettingsVisibility(targetSec === 'tenantProfile');

      if (targetSec === 'tenantProfile') {
        openProfileView(targetTab, false);
      } else if (targetSec) {
        const targetBtn = document.querySelector(`.sidebar-nav-item[onclick*="${targetSec}"]`);
        switchTenantSection(targetSec, targetBtn, false);
      }
    }

    // Stop live camera immediately when user navigates away or switches tabs/apps
    window.addEventListener('beforeunload', stopLivenessCamera);
    window.addEventListener('pagehide', stopLivenessCamera);
    document.addEventListener('visibilitychange', () => {
      if (document.hidden) {
        stopLivenessCamera();
      }
    });

    if (document.readyState === 'loading') {
      document.addEventListener('DOMContentLoaded', initTenantEventListeners);
    } else {
      initTenantEventListeners();
    }
  </script>
  <!-- Real-time Centralized Notification Client Handler -->
  <script src="assets/js/notifications.js"></script>
</body>
</html>
