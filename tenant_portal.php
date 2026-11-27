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

// Name breakdown for personal information editing & profile display
$tenantNamePieces = preg_split('/\s+/', $userFullName);
$tenantEditFirstName = !empty($tenantNamePieces) ? array_shift($tenantNamePieces) : '';
$tenantEditLastName = !empty($tenantNamePieces) ? array_pop($tenantNamePieces) : '';
$tenantEditMiddleName = !empty($tenantNamePieces) ? implode(' ', $tenantNamePieces) : '';

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

    // 3. Securely handle $_FILES upload for supporting document(s) (Supabase Cloud Storage + local fallback)

    $savedFiles = [];
    $seenUploadKeys = [];
    $allowedExts = ['jpg', 'jpeg', 'png', 'webp', 'gif', 'pdf', 'doc', 'docx'];
    $allowedMimes = [
        'image/jpeg', 'image/png', 'image/webp', 'image/gif',
        'application/pdf',
        'application/msword',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document'
    ];

    $fileInputs = ['request_attachments', 'supporting_docs', 'request_attachment', 'report_attachments', 'document', 'attachment', 'attachments', 'file'];
    foreach ($fileInputs as $fKey) {
        if (!isset($_FILES[$fKey])) continue;
        $f = $_FILES[$fKey];

        $filesToProcess = [];
        if (is_array($f['name'])) {
            $count = count($f['name']);
            for ($i = 0; $i < $count; $i++) {
                if (($f['error'][$i] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK && !empty($f['tmp_name'][$i])) {
                    $filesToProcess[] = [
                        'name'     => $f['name'][$i],
                        'tmp_name' => $f['tmp_name'][$i],
                        'size'     => $f['size'][$i],
                    ];
                }
            }
        } elseif (($f['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK && !empty($f['tmp_name'])) {
            $filesToProcess[] = $f;
        }

        foreach ($filesToProcess as $fileItem) {
            if ((int)$fileItem['size'] > 15 * 1024 * 1024) continue; // 15MB limit
            $dedupKey = (string)$fileItem['name'] . '|' . (string)$fileItem['size'];
            if (isset($seenUploadKeys[$dedupKey])) continue;

            $ext = strtolower(pathinfo((string)$fileItem['name'], PATHINFO_EXTENSION));
            if (!in_array($ext, $allowedExts, true)) continue;

            if (function_exists('finfo_open')) {
                $finfo = finfo_open(FILEINFO_MIME_TYPE);
                $mime = finfo_file($finfo, $fileItem['tmp_name']);
                finfo_close($finfo);
                if (!in_array($mime, $allowedMimes, true)) continue;
            }

            $seenUploadKeys[$dedupKey] = true;
            $safeFilename = 'report_' . $tenantId . '_' . time() . '_' . bin2hex(random_bytes(6)) . '.' . $ext;

            // Upload directly to Supabase Storage bucket 'reports' via REST API, with local uploads/reports fallback
            $publicDocUrl = function_exists('uploadToSupabaseStorage')
                ? uploadToSupabaseStorage($fileItem['tmp_name'], $safeFilename, 'reports')
                : false;
            if ($publicDocUrl !== false) {
                $savedFiles[] = $publicDocUrl;
            } else {
                $localDir = __DIR__ . '/uploads/reports';
                if (!is_dir($localDir)) {
                    @mkdir($localDir, 0755, true);
                }
                $localDest = $localDir . '/' . $safeFilename;
                if (@move_uploaded_file($fileItem['tmp_name'], $localDest) || @copy($fileItem['tmp_name'], $localDest)) {
                    $savedFiles[] = 'uploads/reports/' . $safeFilename;
                }
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

// Fetch all payments for this tenant (ordered newest billing cycle first)
$currentTenantId = (int)$user['id'];
$payQueryStmt = $pdo->prepare("
    SELECT * FROM payments 
    WHERE tenant_id = :tid 
    ORDER BY billing_cycle DESC NULLS LAST, id DESC
");
$payQueryStmt->execute([':tid' => $currentTenantId]);
$allTenantPayments = $payQueryStmt->fetchAll();
$myPayments = $allTenantPayments;
$currentPayment = !empty($allTenantPayments) ? $allTenantPayments[0] : null;

// Categorize payments, calculate outstanding rent balance, earliest due date, and unpaid ledger items
$depositBill = null;
$rentBill = null;
$tenantRentBalance = 0.00;
$unpaidBills = [];
$earliestDueDate = null;
$hasPendingPayment = false;
$hasUnpaidBalance = false;

if (empty($allTenantPayments)) {
    $hasUnpaidBalance = true;
    $tenantRentBalance = (float)($unit['monthly_rent'] ?? 3000.00);
} else {
    foreach ($allTenantPayments as $p) {
        $pType = $p['payment_type'] ?? 'Rent';
        if ($pType === 'Advance Deposit' && !$depositBill) {
            $depositBill = $p;
        } elseif ($pType === 'Rent' && !$rentBill) {
            $rentBill = $p;
        }

        $pStatus = strtolower((string)($p['status'] ?? ''));
        if ($pStatus === 'pending') {
            $hasPendingPayment = true;
        }
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

// Determine active rent status display
if ($hasPendingPayment) {
    $rentStatusDisplay = 'PENDING';
} elseif ($tenantRentBalance > 0.01) {
    if (!empty($earliestDueDate) && strtotime($earliestDueDate) < time()) {
        $rentStatusDisplay = 'OVERDUE';
    } else {
        $rentStatusDisplay = 'UNPAID';
    }
} elseif (!empty($allTenantPayments) && $tenantRentBalance <= 0.01) {
    $rentStatusDisplay = 'PAID';
} else {
    $rentStatusDisplay = 'UNPAID';
}

$rentBalanceDisplay = number_format($tenantRentBalance, 2);

if (!empty($earliestDueDate)) {
    $nextDueDateDisplay = date('M d, Y', strtotime($earliestDueDate));
    $shortDueDateDisplay = date('F j', strtotime($earliestDueDate));
} elseif (!empty($rentBill['due_date'])) {
    $nextDueDateDisplay = date('M d, Y', strtotime($rentBill['due_date']));
    $shortDueDateDisplay = date('F j', strtotime($rentBill['due_date']));
} elseif (!empty($allTenantPayments[0]['due_date'])) {
    $nextDueDateDisplay = date('M d, Y', strtotime($allTenantPayments[0]['due_date']));
    $shortDueDateDisplay = date('F j', strtotime($allTenantPayments[0]['due_date']));
} else {
    $nextDueDateDisplay = date('M 05, Y', strtotime('+1 month'));
    $shortDueDateDisplay = date('F 5', strtotime('+1 month'));
}

// Dynamic maintenance counts for tickets submitted by this tenant
$activeTicketsCount = count(array_filter($myTickets, fn($t) => in_array($t['status'], ['Pending', 'On-Going', 'open', 'in_progress'])));
$resolvedTicketsCount = count(array_filter($myTickets, fn($t) => in_array($t['status'], ['Done', 'resolved', 'closed'])));

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
  <link rel="stylesheet" href="assets/css/tenant_theme.css?v=20261121f">
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

    /* =========================================================================
       PAYMENT TRACKER INTERACTIVE CARDS
       ========================================================================= */
    .payment-tracker-card {
      cursor: pointer !important;
      transition: transform 0.16s cubic-bezier(0.4, 0, 0.2, 1), box-shadow 0.16s cubic-bezier(0.4, 0, 0.2, 1), border-color 0.16s ease, background-color 0.16s ease !important;
      outline: none;
    }
    .payment-tracker-card:hover {
      transform: translateY(-2px);
      box-shadow: 0 8px 20px rgba(62, 39, 35, 0.12), 0 2px 6px rgba(86, 56, 14, 0.08) !important;
      border-color: #8C6D43 !important;
      background-color: #ffffff !important;
    }
    .payment-tracker-card:focus-visible {
      outline: 2px solid #56380E;
      outline-offset: 2px;
      box-shadow: 0 0 0 4px rgba(184, 155, 114, 0.3) !important;
    }
    .payment-tracker-card:active {
      transform: translateY(0);
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
          <div class="header-notification-container" style="position: relative; z-index: 1250;">
            <style>
              .top-nav-bar:has(#notificationDropdown.show) { z-index: 1250 !important; }
              #headerNotificationBtn {
                position: relative !important;
                z-index: 1255 !important;
                cursor: pointer !important;
                pointer-events: auto !important;
                touch-action: manipulation;
              }
              #headerNotificationBtn i,
              #headerNotificationBtn svg,
              #headerNotificationBtn .notification-badge {
                pointer-events: none !important;
                cursor: pointer !important;
              }
              #notificationDropdown.notification-dropdown-panel {
                z-index: 1260 !important;
                pointer-events: auto !important;
              }
              #notificationDropdown.notification-dropdown-panel.show {
                display: flex !important;
                flex-direction: column;
              }
            </style>
            <button type="button"
                    class="header-notification-btn"
                    id="headerNotificationBtn"
                    onclick="toggleNotificationPanel(event)"
                    title="Notifications"
                    aria-label="Notifications"
                    aria-haspopup="true"
                    aria-expanded="false"
                    aria-controls="notificationDropdown">
              <i data-lucide="bell" class="w-5 h-5" aria-hidden="true"></i>
              <span class="notification-badge" id="notificationBadge" style="display: none;">0</span>
            </button>

            <!-- Notification Dropdown Panel: Glassmorphism Design -->
            <div class="clay-settings-dropdown notification-dropdown-panel" id="notificationDropdown" role="region" aria-label="Notifications">
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
                <div class="notification-empty-state" id="notificationEmptyFallback">
                  <div class="empty-icon"><i data-lucide="bell-off" aria-hidden="true"></i></div>
                  <h4>No Notifications Yet</h4>
                  <p>You're all caught up! No new notifications at this time.</p>
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
        <!-- Dashboard Verification Notification Banner for dynamic JS alerts -->
        <div id="dashboardVerificationAlert" class="dashboard-verification-alert" style="display: none; margin: 0 0 16px 0; opacity: 1; transition: opacity 0.5s ease, transform 0.5s ease;"></div>

        <!-- Prominent Welcome Section -->
        <?php
          $tenantFullName = trim((string)($user['name'] ?? 'Resident'));
          $tenantNameParts = preg_split('/\s+/', $tenantFullName);
          $tenantFirstName = !empty($tenantNameParts[0]) ? $tenantNameParts[0] : 'Resident';
        ?>
        <h1 class="tenant-dash-welcome-title">
          Welcome home, <span class="tenant-dash-welcome-name"><?= htmlspecialchars($tenantFirstName) ?></span>!
        </h1>

        <!-- Account Verification Notice (Only visible when unverified or pending review) -->
        <?php if (!$isTenantVerified): ?>
        <?php
          $rawVStatus = strtolower(trim((string)($user['verification_status'] ?? 'unverified')));
          $isPendingVerification = in_array($rawVStatus, ['pending', 'pending_verification', 'pending_liveness'], true);
        ?>
        <div class="tenant-dash-verify-box" id="dashboardVerifyNoticeBox">
          <div class="verify-msg">
            <span class="verify-icon">⚠</span>
            <span><?= $isPendingVerification ? 'Your identity document is pending owner review.' : 'Please verify your account first.' ?></span>
          </div>
          <?php if (!$isPendingVerification): ?>
          <button type="button" class="verify-btn" id="btnVerifyAccountCta" onclick="openVerifyAccountModal()">Verify Account →</button>
          <?php else: ?>
          <span class="clay-badge" id="readonlyVerificationBadge" style="background: #fef3c7; color: #92400e; border: 1px solid rgba(180, 83, 9, 0.3); font-size: 11px; padding: 2px 8px; border-radius: 4px;">Pending Review</span>
          <?php endif; ?>
        </div>
        <?php endif; ?>

        <!-- Dashboard Summary Cards (Horizontal Row) -->
        <?php
          $rentStatusCssClass = match (strtoupper((string)$rentStatusDisplay)) {
            'OVERDUE' => 'status-overdue',
            'PAID'    => 'status-paid',
            'PENDING' => 'status-pending',
            default   => 'status-unpaid',
          };
        ?>
        <div class="grid grid-cols-1 md:grid-cols-3 gap-6 mb-8">
          <!-- 1. Current Rent Status -->
          <div class="tenant-dash-summary-card">
            <div class="tenant-dash-card-title">CURRENT RENT STATUS</div>
            <div class="tenant-dash-outlined-val <?= $rentStatusCssClass ?>"><?= htmlspecialchars($rentStatusDisplay) ?></div>
            <div class="tenant-dash-card-bottom">
              <div>Due: <?= htmlspecialchars($shortDueDateDisplay) ?></div>
              <div>
                <button type="button" class="tenant-dash-card-link" onclick="switchTenantSection('tenantPayments', document.getElementById('navTenantPayments'))">Upload Now</button>
              </div>
            </div>
          </div>

          <!-- 2. Active Maintenance -->
          <div class="tenant-dash-summary-card">
            <div class="tenant-dash-card-title">ACTIVE MAINTENANCE</div>
            <div class="tenant-dash-outlined-val">(<?= (int)$activeTicketsCount ?>)</div>
            <div class="tenant-dash-card-bottom">
              <button type="button" class="tenant-dash-card-link" onclick="switchTenantSection('tenantMaintenance', document.getElementById('navTenantMaintenance'))">Submit Report or Maintenance</button>
            </div>
          </div>

          <!-- 3. Resolved Repairs -->
          <div class="tenant-dash-summary-card">
            <div class="tenant-dash-card-title">RESOLVED REPAIRS</div>
            <div class="tenant-dash-outlined-val">(<?= (int)$resolvedTicketsCount ?>)</div>
            <div class="tenant-dash-card-bottom">
              <div>Due: <?= htmlspecialchars($shortDueDateDisplay) ?></div>
              <div>
                <button type="button" class="tenant-dash-card-link" onclick="switchTenantSection('tenantMaintenance', document.getElementById('navTenantMaintenance'))">View History</button>
              </div>
            </div>
          </div>
        </div>

        <!-- Two Main Content Sections Side-by-Side -->
        <div class="grid grid-cols-1 lg:grid-cols-3 gap-6 mb-8">
          <!-- Left: RECENT MAINTENANCE LOGS (~67% width) -->
          <div class="lg:col-span-2 tenant-dash-main-container">
            <div>
              <h2 class="tenant-dash-section-title">RECENT MAINTENANCE LOGS</h2>
              <div class="space-y-1">
                <?php if (empty($myTickets)): ?>
                  <div style="padding: 24px 16px; text-align: center; font-size: 13.5px; font-weight: 700; color: #5C4324; background: rgba(66, 43, 11, 0.05); border-radius: 10px; border: 1px dashed rgba(66, 43, 11, 0.25);">
                    No maintenance requests logged yet.
                  </div>
                <?php else: ?>
                  <?php foreach (array_slice($myTickets, 0, 5) as $tk): 
                    $tkStatus = $tk['status'] ?? 'Pending';
                    $stLower = strtolower((string)$tkStatus);
                    $badgeBg = 'rgba(217, 119, 6, 0.15)';
                    $badgeColor = '#92400e';
                    $badgeBorder = '#d97706';
                    if (in_array($stLower, ['on-going', 'ongoing', 'assigned', 'in_progress'], true)) {
                      $badgeBg = 'rgba(37, 99, 235, 0.15)';
                      $badgeColor = '#1d4ed8';
                      $badgeBorder = '#3b82f6';
                    } elseif (in_array($stLower, ['done', 'resolved', 'closed'], true)) {
                      $badgeBg = 'rgba(22, 163, 74, 0.15)';
                      $badgeColor = '#15803d';
                      $badgeBorder = '#22c55e';
                    }
                  ?>
                  <div class="tenant-dash-log-row" 
                       onclick="openTenantTicketModal(<?= htmlspecialchars(json_encode($tk), ENT_QUOTES, 'UTF-8') ?>)"
                       title="Click to view details for Ticket #MT-<?= str_pad((string)$tk['id'], 4, '0', STR_PAD_LEFT) ?>">
                    <div class="tenant-dash-log-date"><?= date('d F, Y', strtotime($tk['created_at'])) ?></div>
                    <div class="tenant-dash-log-desc" title="<?= htmlspecialchars($tk['title']) ?>"><?= htmlspecialchars($tk['title']) ?></div>
                    <span class="tenant-dash-log-badge" style="background: <?= $badgeBg ?>; color: <?= $badgeColor ?>; border-color: <?= $badgeBorder ?>;"><?= htmlspecialchars($tkStatus) ?></span>
                  </div>
                  <?php endforeach; ?>
                <?php endif; ?>
              </div>
            </div>
            <div style="padding-top: 16px;">
              <button type="button" class="tenant-dash-viewall-btn" onclick="switchTenantSection('tenantMaintenance', document.getElementById('navTenantMaintenance'))">View all...</button>
            </div>
          </div>

          <!-- Right: PAYMENT SUMMARY (~33% width) -->
          <div class="lg:col-span-1 tenant-dash-main-container">
            <div>
              <h2 class="tenant-dash-section-title">PAYMENT SUMMARY</h2>
              <div class="space-y-2 mt-4">
                <div class="tenant-dash-pay-item">
                  <span class="tenant-dash-pay-label">Assigned Unit</span>
                  <span class="tenant-dash-pay-val"><?= htmlspecialchars($unit['unit_number'] ?? 'Unit 1') ?></span>
                </div>
                <div class="tenant-dash-pay-item">
                  <span class="tenant-dash-pay-label">Monthly Rent</span>
                  <span class="tenant-dash-pay-val">₱<?= number_format((float)($unit['monthly_rent'] ?? 0), 2) ?></span>
                </div>
                <div class="tenant-dash-pay-item">
                  <span class="tenant-dash-pay-label">Outstanding Balance</span>
                  <span class="tenant-dash-pay-val">₱<?= $rentBalanceDisplay ?></span>
                </div>
                <div class="tenant-dash-pay-item">
                  <span class="tenant-dash-pay-label">Next Due Date</span>
                  <span class="tenant-dash-pay-val"><?= htmlspecialchars($nextDueDateDisplay) ?></span>
                </div>
                <div class="tenant-dash-pay-item">
                  <span class="tenant-dash-pay-label">Current Status</span>
                  <span class="tenant-dash-pay-val" style="font-size: 11px; padding: 2px 8px; border-radius: 9999px; font-weight: 800; <?= $rentStatusDisplay === 'PAID' ? 'background: #dcfce7; color: #15803d; border: 1px solid #86efac;' : ($rentStatusDisplay === 'PENDING' ? 'background: #fef3c7; color: #92400e; border: 1px solid #fde68a;' : 'background: #fee2e2; color: #991b1b; border: 1px solid #fca5a5;') ?>">
                    <?= htmlspecialchars($rentStatusDisplay) ?>
                  </span>
                </div>
              </div>
            </div>
            <div style="padding-top: 16px;">
              <button type="button" class="tenant-dash-pay-btn" onclick="switchTenantSection('tenantPayments', document.getElementById('navTenantPayments'))">
                <i data-lucide="upload-cloud" class="w-4 h-4" aria-hidden="true"></i>
                <span>Upload Payment</span>
              </button>
            </div>
          </div>
        </div>

      </section>

      <!-- CATEGORY 2: LOG MAINTENANCE -->
      <section id="tenantMaintenance" class="app-view-section">
        <div class="max-w-4xl mx-auto maintenance-folder-shell" id="maintenanceFolderShell">
          <div class="maintenance-folder-tabs" role="tablist" aria-label="Maintenance Views">
            <button type="button"
                    class="maintenance-folder-tab active"
                    id="btn-submit"
                    role="tab"
                    aria-selected="true"
                    aria-controls="submit-section"
                    onclick="toggleMaintenanceTabs('submit')">
              <i data-lucide="wrench" class="w-4 h-4"></i>
              <span>Submit Request</span>
            </button>
            <button type="button"
                    class="maintenance-folder-tab"
                    id="btn-track"
                    role="tab"
                    aria-selected="false"
                    aria-controls="track-section"
                    onclick="toggleMaintenanceTabs('track')">
              <i data-lucide="list-checks" class="w-4 h-4"></i>
              <span>Track Requests</span>
              <span id="tenantMaintenanceCountBadge" class="folder-tab-count"><?= count($myTickets) ?></span>
            </button>
          </div>

          <div class="maintenance-folder-body" id="maintenanceFolderBody">
            <!-- 1. SUBMIT SECTION -->
            <div id="submit-section" class="w-full">
              <h2 class="text-2xl md:text-3xl font-black text-[#422B0B] mb-6 tracking-wide text-center sm:text-left">Submit Maintenance Request</h2>

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
                      <label for="ticketTitle" class="maintenance-field-label" style="color: #422B0B !important;">Issue:</label>
                      <input type="text" id="ticketTitle" class="w-full h-11 px-3.5 py-2.5 rounded-xl bg-[#FFFDF8] text-[#302313] font-semibold text-sm border border-[#8C6D43]/60 focus:outline-none focus:ring-2 focus:ring-[#5E3F12] shadow-sm <?= !$isTenantVerified ? 'opacity-60 cursor-not-allowed bg-gray-200' : '' ?>" placeholder="e.g. Bathroom sink pipe dripping" required <?= !$isTenantVerified ? 'disabled' : '' ?>>
                    </div>

                    <div class="maintenance-meta-row">
                      <div class="maintenance-field-group">
                        <label for="ticketCategoryTrigger" class="maintenance-field-label" style="color: #422B0B !important;">Repair Category:</label>
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
                                  class="w-full h-11 px-3.5 py-2.5 rounded-xl bg-[#FFFDF8] text-[#302313] font-semibold text-sm border border-[#8C6D43]/60 focus:outline-none focus:ring-2 focus:ring-[#5E3F12] shadow-sm flex items-center justify-between <?= !$isTenantVerified ? 'opacity-60 cursor-not-allowed pointer-events-none' : '' ?>"
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
                        <span class="maintenance-field-label" id="urgencyLevelLabel" style="color: #422B0B !important;">Urgency Level:</span>
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
                      <label for="ticketDesc" class="maintenance-field-label" style="color: #422B0B !important;">Detailed Description:</label>
                      <textarea id="ticketDesc" class="w-full min-h-[220px] p-3.5 rounded-xl bg-[#FFFDF8] text-[#302313] font-semibold text-sm border border-[#8C6D43]/60 focus:outline-none focus:ring-2 focus:ring-[#5E3F12] shadow-sm resize-none <?= !$isTenantVerified ? 'opacity-60 cursor-not-allowed bg-gray-200' : '' ?>" placeholder="Describe and put the details of your issue." required <?= !$isTenantVerified ? 'disabled' : '' ?>></textarea>
                    </div>
                  </div>
                </div>

                <!-- File Upload Section -->
                <div class="maintenance-upload-section">
                  <label for="ticketPhotos" class="maintenance-field-label" style="color: #422B0B !important;">Attach photos of issue to be repaired:</label>
                  <div class="maintenance-upload-center">
                    <div class="maintenance-upload-btn-wrap">
                      <input type="file" id="ticketPhotos" name="maintenance_photos[]" accept="image/*" multiple class="maintenance-file-input" <?= !$isTenantVerified ? 'disabled' : '' ?>>
                      <label for="ticketPhotos" class="inline-flex items-center justify-center gap-2.5 px-6 py-2.5 rounded-xl bg-[#F3E4C6] text-[#56380E] font-bold text-sm border border-[#8C6D43] shadow-md hover:bg-[#EAD5B0] transition cursor-pointer <?= !$isTenantVerified ? 'opacity-60 cursor-not-allowed pointer-events-none' : '' ?>">
                        <i data-lucide="image" class="w-5 h-5 text-[#5E3F12]" aria-hidden="true"></i>
                        <span id="ticketPhotosUploadLabel">Upload Photo</span>
                      </label>
                    </div>
                  </div>
                  <div id="ticketPhotosPreview" class="maintenance-photos-preview" style="display: none;"></div>
                </div>

                <!-- Centered Submit Request Button -->
                <div class="maintenance-submit-row">
                  <button type="submit" class="min-w-[260px] py-3.5 px-8 rounded-xl bg-[#5E3F12] hover:bg-[#4A320E] text-white font-black text-base shadow-lg border border-[#422B0B] transition-all cursor-pointer <?= !$isTenantVerified ? 'opacity-60 cursor-not-allowed bg-gray-400 pointer-events-none' : '' ?>" id="btnLogTicket" <?= !$isTenantVerified ? 'disabled' : '' ?>>Submit Request</button>
                </div>
              </form>
            </div>

            <!-- 2. TRACK SECTION -->
            <div id="track-section" class="w-full hidden">
              <h2 class="text-2xl md:text-3xl font-black text-[#422B0B] mb-6 tracking-wide text-center sm:text-left" style="color: #422B0B !important;">Track Maintenance Requests</h2>

              <div id="ticketHistoryContainer" class="flex flex-col gap-6">
                <?php if (empty($myTickets)): ?>
                  <div class="text-center py-12 px-6 rounded-2xl bg-[#FCF9F4] border border-[#8C6D43]/40 text-[#7A6245]">
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
        if (!isset($allTenantPayments)) {
            $payQueryStmt = $pdo->prepare("
                SELECT * FROM payments 
                WHERE tenant_id = :tid 
                ORDER BY billing_cycle DESC NULLS LAST, id DESC
            ");
            $payQueryStmt->execute([':tid' => $currentTenantId]);
            $allTenantPayments = $payQueryStmt->fetchAll();
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

          <!-- 1. STATIC TOP HEADER CARD (Dominant Warm Card) -->
          <div class="bg-[#FCF9F4] border-2 border-[#422B0B]/30 rounded-2xl shadow-md p-6 text-center">
            <h1 class="text-2xl md:text-3xl font-black text-[#422B0B] tracking-wider uppercase mb-2">
              <?= htmlspecialchars($unitHeaderTitle) ?>
            </h1>
            <div class="flex flex-wrap items-center justify-center gap-x-12 gap-y-2 text-sm md:text-base font-bold text-[#7A6245]">
              <div>Rent Balance: <span class="font-black text-[#422B0B]">Php <?= $rentBalanceDisplay ?></span></div>
              <div>Next Due Date: <span class="font-black text-[#422B0B]"><?= htmlspecialchars($nextDueDateDisplay) ?></span></div>
            </div>
          </div>

          <!-- 2. UNIFIED PARENT FOLDER CONTAINER -->
          <div class="maintenance-folder-shell" id="tenantPaymentsFolderShell">

            <!-- Top Tab Bar -->
            <div class="maintenance-folder-tabs" role="tablist" aria-label="Payment Views">
              <!-- Tab: Upload Proof -->
              <button type="button" id="tabBtnUpload" onclick="switchPaymentTab('upload')"
                class="maintenance-folder-tab <?= !$isTrackerTabActive ? 'active' : '' ?>" role="tab" aria-selected="<?= !$isTrackerTabActive ? 'true' : 'false' ?>">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-8l-4-4m0 0L8 8m4-4v12" />
                </svg>
                <span>Upload Proof</span>
              </button>

              <!-- Tab: Payment Tracker -->
              <button type="button" id="tabBtnTracker" onclick="switchPaymentTab('tracker')"
                class="maintenance-folder-tab <?= $isTrackerTabActive ? 'active' : '' ?>" role="tab" aria-selected="<?= $isTrackerTabActive ? 'true' : 'false' ?>">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-6 9l2 2 4-4" />
                </svg>
                <span>Payment Tracker</span>
                <span id="paymentCountBadge" class="folder-tab-count">
                  <?= count($allTenantPayments) ?>
                </span>
              </button>
            </div>

            <!-- Main Content Area -->
            <div class="maintenance-folder-body" id="tenantPaymentsFolderBody">

              <!-- TAB 1: UPLOAD PROOF -->
              <div id="tabContentUpload" class="<?= $isTrackerTabActive ? 'hidden' : '' ?>">
                <h2 class="text-2xl md:text-3xl font-black text-[#422B0B] mb-6 tracking-wide" style="color: #422B0B !important;">Upload Payment Proof</h2>

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

                  <!-- Grid for Form Fields -->
                  <div class="grid grid-cols-1 md:grid-cols-2 gap-x-6 gap-y-4 mb-6">
                    <!-- 1. Billing Month -->
                    <div>
                      <label for="payBillingMonth" class="block font-bold text-sm text-[#422B0B] mb-1.5" style="color: #422B0B !important;">Billing Month:</label>
                      <div class="relative">
                        <select id="payBillingMonth" name="billing_month" onchange="handleBillingMonthChange()" class="w-full bg-[#FFFDF8] text-[#3E2723] font-bold text-sm px-4 py-2.5 rounded-xl border border-[#8C6D43]/60 shadow-md focus:outline-none focus:ring-2 focus:ring-[#5E3F12] appearance-none pr-10 cursor-pointer <?= !$isTenantVerified ? 'opacity-60 cursor-not-allowed' : '' ?>" <?= !$isTenantVerified ? 'disabled' : '' ?>>
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
                      <label for="payMethod" class="block font-bold text-sm text-[#422B0B] mb-1.5" style="color: #422B0B !important;">Payment Method:</label>
                      <div class="relative">
                        <select id="payMethod" name="payment_method" class="w-full bg-[#FFFDF8] text-[#3E2723] font-bold text-sm px-4 py-2.5 rounded-xl border border-[#8C6D43]/60 shadow-md focus:outline-none focus:ring-2 focus:ring-[#5E3F12] appearance-none pr-10 cursor-pointer <?= !$isTenantVerified ? 'opacity-60 cursor-not-allowed' : '' ?>" <?= !$isTenantVerified ? 'disabled' : '' ?>>
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
                      <label for="payFor" class="block font-bold text-sm text-[#422B0B] mb-1.5" style="color: #422B0B !important;">Pay for:</label>
                      <div class="relative">
                        <select id="payFor" name="pay_for" class="w-full bg-[#FFFDF8] text-[#3E2723] font-bold text-sm px-4 py-2.5 rounded-xl border border-[#8C6D43]/60 shadow-md focus:outline-none focus:ring-2 focus:ring-[#5E3F12] appearance-none pr-10 cursor-pointer <?= !$isTenantVerified ? 'opacity-60 cursor-not-allowed' : '' ?>" <?= !$isTenantVerified ? 'disabled' : '' ?>>
                          <option value="Rent" <?= (!empty($unpaidBills[0]['payment_type']) && $unpaidBills[0]['payment_type'] === 'Rent') ? 'selected' : '' ?>>Monthly Rent</option>
                          <option value="Advance Deposit" <?= (!empty($unpaidBills[0]['payment_type']) && $unpaidBills[0]['payment_type'] === 'Advance Deposit') ? 'selected' : '' ?>>Advance Deposit</option>
                          <option value="Additional Space">Additional Space</option>
                        </select>
                        <div class="pointer-events-none absolute inset-y-0 right-0 flex items-center px-3.5 text-[#7A6245]">
                          <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M19 9l-7 7-7-7"></path></svg>
                        </div>
                      </div>
                    </div>

                    <!-- 4. Payment Amount -->
                    <div>
                      <label for="payAmount" class="block font-bold text-sm text-[#422B0B] mb-1.5" style="color: #422B0B !important;">Payment Amount (Php):</label>
                      <?php
                      $initialPayAmount = !empty($unpaidBills[0]) ? max(0.0, (float)$unpaidBills[0]['amount_due'] - (float)$unpaidBills[0]['amount_paid']) : 3000.00;
                      if ($initialPayAmount <= 0.01) $initialPayAmount = 3000.00;
                      ?>
                      <input type="number" step="0.01" min="1" name="amount" id="payAmount" required value="<?= number_format($initialPayAmount, 2, '.', '') ?>" placeholder="0.00" class="w-full bg-[#FFFDF8] text-[#3E2723] font-bold text-sm px-4 py-2.5 rounded-xl border border-[#8C6D43]/60 shadow-md placeholder-[#9A7B4F] focus:outline-none focus:ring-2 focus:ring-[#5E3F12] <?= !$isTenantVerified ? 'opacity-60 cursor-not-allowed' : '' ?>" <?= !$isTenantVerified ? 'disabled' : '' ?>>
                    </div>

                    <!-- 5. Reference Number -->
                    <div class="md:col-span-2">
                      <label for="payReferenceNumber" class="block font-bold text-sm text-[#422B0B] mb-1.5" style="color: #422B0B !important;">Reference Number:</label>
                      <input type="text" id="payReferenceNumber" name="reference_number" placeholder="Enter reference number (e.g. 083948857486783)" class="w-full bg-[#FFFDF8] text-[#3E2723] font-bold text-sm px-4 py-2.5 rounded-xl border border-[#8C6D43]/60 shadow-md placeholder-[#9A7B4F] focus:outline-none focus:ring-2 focus:ring-[#5E3F12] <?= !$isTenantVerified ? 'opacity-60 cursor-not-allowed' : '' ?>" <?= !$isTenantVerified ? 'disabled' : '' ?>>
                    </div>
                  </div>

                  <!-- Dashed-Border Upload Zone -->
                  <div id="receiptDropZone" class="border-2 border-dashed border-[#8C6D43] rounded-xl p-6 text-center max-w-lg mx-auto mb-6 bg-[#FBF8F1]/80 transition-all hover:bg-white cursor-pointer <?= !$isTenantVerified ? 'opacity-60 cursor-not-allowed pointer-events-none' : '' ?>">
                    <div class="font-bold text-sm text-[#422B0B] mb-3" style="color: #422B0B !important;">Upload Photos of Payment Receipt:</div>
                    <label for="receiptFileInput" class="inline-flex items-center gap-2 px-6 py-2 bg-[#F3E4C6] hover:bg-[#EAD5B0] text-[#56380E] text-sm font-bold rounded shadow-sm border border-[#8C6D43] cursor-pointer transition-all">
                      <svg class="w-4 h-4 text-[#5E3F12]" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2 2v12a2 2 0 002 2z"></path></svg>
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

                  <!-- Submit Proof Button -->
                  <div class="text-center">
                    <button type="submit" id="btnUploadReceipt" class="px-14 py-3.5 bg-[#5E3F12] hover:bg-[#4A320E] text-white font-black text-base uppercase rounded-xl shadow-lg transition-all cursor-pointer border border-[#422B0B] <?= !$isTenantVerified ? 'opacity-60 cursor-not-allowed bg-gray-400 text-gray-700 pointer-events-none border-transparent' : '' ?>" <?= !$isTenantVerified ? 'disabled' : '' ?>>
                      Submit Proof
                    </button>
                  </div>
                </form>
              </div>

              <!-- TAB 2: PAYMENT TRACKER -->
              <div id="tabContentTracker" class="<?= !$isTrackerTabActive ? 'hidden' : '' ?>">
                <h2 class="text-2xl md:text-3xl font-black text-[#422B0B] mb-6 tracking-wide">Payment Tracker and History</h2>

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
                      $dueDateFmt = !empty($py['due_date']) ? date('M j, Y', strtotime($py['due_date'])) : 'N/A';
                      $pyPayload = [
                          'id' => (int)($py['id'] ?? 0),
                          'purpose' => $cycleText . ' ' . $payType,
                          'payment_type' => $payType,
                          'billing_cycle' => $py['billing_cycle'] ?? '',
                          'billing_cycle_fmt' => $cycleText,
                          'amount_paid' => (float)($py['amount_paid'] ?? 0),
                          'amount_due' => (float)($py['amount_due'] ?? 0),
                          'display_amount' => $displayAmount,
                          'due_date' => $dueDateFmt,
                          'payment_date' => $submitDate,
                          'ref_number' => $refDisplay,
                          'status' => $py['status'] ?? 'pending',
                          'receipt_filename' => $py['receipt_filename'] ?? '',
                          'verification_notes' => $py['verification_notes'] ?? '',
                          'verified_at' => !empty($py['verified_at']) ? date('M j, Y g:i A', strtotime($py['verified_at'])) : '',
                          'payment_method' => $py['payment_method'] ?? 'GCash / Bank Transfer',
                      ];
                      ?>
                      <!-- Tracker Row Card (Clickable, Interactive Hover State) -->
                      <div class="payment-tracker-card flex items-center justify-between p-4 md:p-5 bg-[#F5F0E6] rounded-xl border border-[#8C6D43]/40 shadow-sm hover:bg-white transition-all cursor-pointer group"
                           role="button"
                           tabindex="0"
                           onclick="openTenantPaymentDetailsModal(<?= htmlspecialchars(json_encode($pyPayload), ENT_QUOTES, 'UTF-8') ?>)"
                           onkeydown="if(event.key === 'Enter' || event.key === ' ') { event.preventDefault(); openTenantPaymentDetailsModal(<?= htmlspecialchars(json_encode($pyPayload), ENT_QUOTES, 'UTF-8') ?>); }"
                           title="Click to view payment details and receipt">
                        <div>
                          <div class="font-bold text-base md:text-lg text-[#3E2723] group-hover:text-[#2A1810] transition-colors">
                            <?= $itemNum++ ?>. <?= htmlspecialchars($cycleText) ?> <?= htmlspecialchars($payType) ?>
                          </div>
                          <div class="text-xs text-[#7A6245] mt-1 font-medium">
                            Date Submitted: <?= htmlspecialchars($submitDate) ?>
                          </div>
                          <div class="text-xs text-[#7A6245] mt-0.5 font-medium">
                            Ref No.: <?= htmlspecialchars($refDisplay) ?>
                          </div>
                        </div>
                        <div class="flex items-center gap-3 sm:gap-4">
                          <div class="text-right">
                            <div class="font-black text-base md:text-lg text-[#3E2723]">
                              PHP <?= number_format($displayAmount, 2) ?>
                            </div>
                            <div>
                              <!-- Status Badges (10% Accent Layer) -->
                              <?php if ($statusLower === 'approved'): ?>
                                <span class="inline-block mt-2 px-3.5 py-1 bg-[#22c55e] text-white font-black text-xs uppercase rounded shadow-sm">Verified</span>
                              <?php elseif ($statusLower === 'pending'): ?>
                                <span class="inline-block mt-2 px-3.5 py-1 bg-[#f59e0b] text-white font-black text-xs uppercase rounded shadow-sm">Pending</span>
                              <?php elseif ($statusLower === 'overdue'): ?>
                                <span class="inline-block mt-2 px-3.5 py-1 bg-[#ef4444] text-white font-black text-xs uppercase rounded shadow-sm">Overdue</span>
                              <?php elseif ($statusLower === 'rejected'): ?>
                                <span class="inline-block mt-2 px-3.5 py-1 bg-[#ef4444] text-white font-black text-xs uppercase rounded shadow-sm">Rejected</span>
                              <?php else: ?>
                                <span class="inline-block mt-2 px-3.5 py-1 bg-[#ef4444] text-white font-black text-xs uppercase rounded shadow-sm">Unpaid</span>
                              <?php endif; ?>
                            </div>
                          </div>
                          <!-- Subtle Chevron / View Details Arrow -->
                          <div class="text-[#8C6D43] group-hover:text-[#4A3728] group-hover:translate-x-1 transition-all flex items-center justify-center pl-1" aria-hidden="true" title="View Details">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M9 5l7 7-7 7" />
                            </svg>
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
        <div class="w-full max-w-4xl mx-auto maintenance-folder-shell" id="approvalTabsContainer">

          <?php
          $isReportTrackerActive = (isset($_GET['tab']) && $_GET['tab'] === 'tracker') || (isset($_GET['success']) && $_GET['success'] === 'report_submitted');
          ?>
          <!-- Top Tab Bar -->
          <div class="maintenance-folder-tabs" role="tablist" aria-label="Approval Request Navigation">
            <!-- Tab 1: Submit Request -->
            <button
              type="button"
              id="tabBtnSubmit"
              role="tab"
              aria-selected="<?= $isReportTrackerActive ? 'false' : 'true' ?>"
              aria-controls="tabPaneSubmit"
              onclick="switchApprovalTab('submit')"
              class="maintenance-folder-tab <?= !$isReportTrackerActive ? 'active' : '' ?>">
              <i data-lucide="file-text" class="w-4 h-4"></i>
              <span>Submit Request</span>
            </button>

            <!-- Tab 2: Status Tracker -->
            <button
              type="button"
              id="tabBtnTracker"
              role="tab"
              aria-selected="<?= $isReportTrackerActive ? 'true' : 'false' ?>"
              aria-controls="tabPaneTracker"
              onclick="switchApprovalTab('tracker')"
              class="maintenance-folder-tab <?= $isReportTrackerActive ? 'active' : '' ?>">
              <i data-lucide="list-checks" class="w-4 h-4"></i>
              <span>Status Tracker</span>
              <span id="tenantReportCountBadge" class="folder-tab-count"><?= count($myReports) ?></span>
            </button>
          </div>

          <!-- Main Content Area -->
          <div class="maintenance-folder-body" id="tenantReportFolderBody">

            <!-- Tab 1: "Submit Request" Pane -->
            <div
              id="tabPaneSubmit"
              role="tabpanel"
              aria-labelledby="tabBtnSubmit"
              class="<?= $isReportTrackerActive ? 'hidden' : '' ?>">

              <!-- Card Header -->
              <h2 class="text-2xl md:text-3xl font-black text-[#422B0B] mb-6 tracking-wide text-center sm:text-left" style="color: #422B0B !important;">
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
                  <label for="requestCategory" class="font-bold text-sm text-[#422B0B]" style="color: #422B0B !important;">
                    Request Category:
                  </label>
                  <div class="relative">
                    <select
                      id="requestCategory"
                      name="request_category"
                      required
                      <?= !$isTenantVerified ? 'disabled' : '' ?>
                      class="w-full appearance-none rounded-xl border border-[#8C6D43]/60
                             bg-[#FFFDF8] text-[#302313] font-semibold text-sm
                             px-4 py-2.5 pr-10 shadow-sm
                             focus:outline-none focus:ring-2 focus:ring-[#5E3F12]
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
                    <div class="pointer-events-none absolute inset-y-0 right-3.5 flex items-center text-[#7A6245]">
                      <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7"/>
                      </svg>
                    </div>
                  </div>
                </div>

                <!-- Request Specification -->
                <div class="grid grid-cols-1 md:grid-cols-[180px_1fr] items-center gap-2 sm:gap-4">
                  <label for="requestSpecification" class="font-bold text-sm text-[#422B0B]" style="color: #422B0B !important;">
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
                    class="w-full rounded-xl border border-[#8C6D43]/60
                           bg-[#FFFDF8] text-[#302313] font-semibold text-sm
                           px-4 py-2.5 placeholder-[#9C8262] shadow-sm
                           focus:outline-none focus:ring-2 focus:ring-[#5E3F12]
                           transition-colors <?= !$isTenantVerified ? 'opacity-60 cursor-not-allowed' : '' ?>">
                </div>

                <!-- Describe your Request -->
                <div class="grid grid-cols-1 md:grid-cols-[180px_1fr] items-start gap-2 sm:gap-4">
                  <label for="requestDescription" class="font-bold text-sm text-[#422B0B] pt-2" style="color: #422B0B !important;">
                    Describe your Request:
                  </label>
                  <textarea
                    id="requestDescription"
                    name="request_description"
                    rows="4"
                    placeholder="Describe and put the details of your request."
                    required
                    <?= !$isTenantVerified ? 'disabled' : '' ?>
                    class="w-full rounded-xl border border-[#8C6D43]/60
                           bg-[#FFFDF8] text-[#302313] font-semibold text-sm
                           px-4 py-2.5 placeholder-[#9C8262] shadow-sm resize-none
                           focus:outline-none focus:ring-2 focus:ring-[#5E3F12]
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
                    class="flex flex-col sm:flex-row items-center justify-center gap-2 sm:gap-3 w-full rounded-xl
                           border-2 border-dashed border-[#8C6D43]
                           bg-[#FBF8F1]/80 text-[#422B0B] hover:bg-white
                           py-5 px-4 text-center transition-all cursor-pointer shadow-sm
                           <?= !$isTenantVerified ? 'opacity-60 cursor-not-allowed pointer-events-none' : '' ?>">
                    <span id="uploadDashedZoneLabel" class="font-bold text-sm sm:text-base text-[#422B0B]" style="color: #422B0B !important;">
                      Upload Supporting Document/Photo
                    </span>
                    <svg class="w-6 h-6 text-[#5E3F12] flex-shrink-0" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24">
                      <path stroke-linecap="round" stroke-linejoin="round" d="M4 16v2a2 2 0 002 2h12a2 2 0 002-2v-2M12 12V4m0 0L8 8m4-4l4 4"/>
                    </svg>
                  </label>
                  <div id="requestFilePreview" class="flex flex-wrap gap-3 mt-3" style="display: none;"></div>
                </div>

                <!-- Submit Request Button -->
                <div class="flex justify-center mt-3">
                  <button
                    type="submit"
                    id="btnSubmitApproval"
                    <?= !$isTenantVerified ? 'disabled' : '' ?>
                    class="min-w-[260px] py-3.5 px-8 rounded-xl font-black text-base tracking-wide
                           text-white bg-[#5E3F12] hover:bg-[#4A320E]
                           border border-[#422B0B] shadow-lg
                           transition-all cursor-pointer <?= !$isTenantVerified ? 'opacity-60 cursor-not-allowed pointer-events-none' : '' ?>">
                    Submit Request
                  </button>
                </div>
              </form>
            </div>

            <!-- Tab 2: Status Tracker Pane -->
            <div
              id="tabPaneTracker"
              role="tabpanel"
              aria-labelledby="tabBtnTracker"
              class="<?= !$isReportTrackerActive ? 'hidden' : '' ?>">

              <!-- Card Header -->
              <h2 class="text-2xl md:text-3xl font-black text-[#422B0B] mb-6 tracking-wide text-center sm:text-left" style="color: #422B0B !important;">
                Owner Approval Status Tracker and History
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
      </div>

        <!-- ── Vanilla JavaScript for Tab Switching & Upload Handling ── -->
        <script>
        /**
         * switchApprovalTab:
         * Toggles the 'hidden' class between the two content sections.
         * Sets active/inactive classes on the standardized folder tabs.
         */
        function switchApprovalTab(tabName) {
          const tabBtnSubmit = document.getElementById('tabBtnSubmit');
          const tabBtnTracker = document.getElementById('tabBtnTracker');
          const tabPaneSubmit = document.getElementById('tabPaneSubmit');
          const tabPaneTracker = document.getElementById('tabPaneTracker');

          if (!tabBtnSubmit || !tabBtnTracker || !tabPaneSubmit || !tabPaneTracker) return;

          if (tabName === 'submit') {
            tabPaneSubmit.classList.remove('hidden');
            tabPaneTracker.classList.add('hidden');

            tabBtnSubmit.setAttribute('aria-selected', 'true');
            tabBtnSubmit.className = "maintenance-folder-tab active";

            tabBtnTracker.setAttribute('aria-selected', 'false');
            tabBtnTracker.className = "maintenance-folder-tab";
          } else if (tabName === 'tracker') {
            tabPaneTracker.classList.remove('hidden');
            tabPaneSubmit.classList.add('hidden');

            tabBtnTracker.setAttribute('aria-selected', 'true');
            tabBtnTracker.className = "maintenance-folder-tab active";

            tabBtnSubmit.setAttribute('aria-selected', 'false');
            tabBtnSubmit.className = "maintenance-folder-tab";
          }
          if (window.lucide && typeof window.lucide.createIcons === 'function') {
            window.lucide.createIcons();
          }
        }

        /**
         * Cumulative file list & visual thumbnail preview grid for Report and Approval Request
         */
        let requestAttachmentFiles = [];

        function isPreviewableImageFile(file) {
          if (!file) return false;
          if (file.type && file.type.startsWith('image/')) return true;
          return /\.(jpe?g|png|webp|gif|bmp)$/i.test(String(file.name || ''));
        }

        function syncRequestAttachmentsInput(input) {
          if (!input) input = document.getElementById('requestAttachments');
          if (!input || typeof DataTransfer === 'undefined') return;
          const dt = new DataTransfer();
          requestAttachmentFiles.forEach(item => {
            if (item && item.file) {
              dt.items.add(item.file);
            }
          });
          input.files = dt.files;
        }

        function updateUploadZoneLabel() {
          const labelEl = document.getElementById('uploadDashedZoneLabel');
          if (!labelEl) return;
          if (requestAttachmentFiles.length > 0) {
            labelEl.textContent = `Add More Supporting Photos/Documents (${requestAttachmentFiles.length} attached)`;
          } else {
            labelEl.textContent = 'Upload Supporting Document/Photo';
          }
        }

        function openRequestAttachmentLightbox(imageUrl, fileName) {
          const modal = document.getElementById('requestAttachmentLightboxModal');
          const img = document.getElementById('requestAttachmentLightboxImg');
          const titleEl = document.getElementById('requestAttachmentLightboxTitle');
          if (!modal || !img || !imageUrl) return;

          if (titleEl) {
            const span = titleEl.querySelector('span');
            if (span) span.textContent = fileName || 'Photo Preview';
          }
          img.src = imageUrl;
          img.alt = fileName || 'Attached photo preview';
          openModal('requestAttachmentLightboxModal');
        }

        function removeRequestAttachment(index) {
          if (index < 0 || index >= requestAttachmentFiles.length) return;
          const [removed] = requestAttachmentFiles.splice(index, 1);
          if (removed && removed.objectUrl) {
            try { URL.revokeObjectURL(removed.objectUrl); } catch (e) {}
          }
          syncRequestAttachmentsInput(document.getElementById('requestAttachments'));
          renderRequestFilePreview();
        }

        function clearRequestAttachments() {
          requestAttachmentFiles.forEach(item => {
            if (item && item.objectUrl) {
              try { URL.revokeObjectURL(item.objectUrl); } catch (e) {}
            }
          });
          requestAttachmentFiles = [];
          syncRequestAttachmentsInput(document.getElementById('requestAttachments'));
          renderRequestFilePreview();
        }

        function renderRequestFilePreview() {
          const preview = document.getElementById('requestFilePreview');
          if (!preview) return;
          preview.innerHTML = '';
          updateUploadZoneLabel();

          if (requestAttachmentFiles.length === 0) {
            preview.style.display = 'none';
            return;
          }

          preview.style.display = 'flex';
          requestAttachmentFiles.forEach((item, idx) => {
            const file = item.file;
            const card = document.createElement('div');
            card.style.cssText = 'position: relative; width: 112px; height: 112px; border-radius: 12px; overflow: hidden; border: 1.5px solid #B89B72; background: #FAF5EC; box-shadow: 0 4px 12px rgba(74,55,40,0.12); flex-shrink: 0; transition: transform 0.16s ease, box-shadow 0.16s ease;';

            if (item.isImage && item.objectUrl) {
              card.style.cursor = 'pointer';
              card.title = 'Click to view full photo: ' + file.name;

              const img = document.createElement('img');
              img.src = item.objectUrl;
              img.alt = file.name;
              img.style.cssText = 'width: 100%; height: 100%; object-fit: cover; display: block; transition: transform 0.2s ease;';

              const caption = document.createElement('div');
              caption.style.cssText = 'position: absolute; left: 0; right: 0; bottom: 0; padding: 4px 6px; background: linear-gradient(transparent, rgba(43,26,6,0.78)); color: #fff; font-size: 10px; font-weight: 700; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; pointer-events: none;';
              caption.textContent = file.name;

              card.addEventListener('mouseenter', () => {
                img.style.transform = 'scale(1.05)';
                card.style.boxShadow = '0 6px 16px rgba(74,55,40,0.22)';
              });
              card.addEventListener('mouseleave', () => {
                img.style.transform = 'scale(1)';
                card.style.boxShadow = '0 4px 12px rgba(74,55,40,0.12)';
              });
              card.addEventListener('click', () => {
                openRequestAttachmentLightbox(item.objectUrl, file.name);
              });

              card.appendChild(img);
              card.appendChild(caption);
            } else {
              card.style.cssText += ' display: flex; flex-direction: column; align-items: center; justify-content: center; padding: 10px 8px; gap: 6px; text-align: center;';
              card.title = file.name;

              const ext = (file.name.split('.').pop() || 'FILE').toUpperCase().slice(0, 5);
              const iconBox = document.createElement('div');
              iconBox.style.cssText = 'width: 38px; height: 38px; border-radius: 10px; background: #EFE3CC; color: #5E3F12; display: flex; align-items: center; justify-content: center;';
              iconBox.innerHTML = '<svg style="width:20px;height:20px;" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z"/><path stroke-linecap="round" stroke-linejoin="round" d="M14 2v6h6M16 13H8M16 17H8M10 9H8"/></svg>';

              const extBadge = document.createElement('span');
              extBadge.style.cssText = 'font-size: 9.5px; font-weight: 800; color: #fff; background: #8C6D43; padding: 1px 6px; border-radius: 999px; letter-spacing: 0.4px;';
              extBadge.textContent = ext;

              const nameEl = document.createElement('span');
              nameEl.style.cssText = 'font-size: 11px; font-weight: 700; color: #3E2723; width: 100%; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;';
              nameEl.textContent = file.name;

              card.appendChild(iconBox);
              card.appendChild(extBadge);
              card.appendChild(nameEl);
            }

            const removeBtn = document.createElement('button');
            removeBtn.type = 'button';
            removeBtn.className = 'btn-remove-attached-photo';
            removeBtn.title = 'Remove file';
            removeBtn.setAttribute('aria-label', 'Remove ' + file.name);
            removeBtn.innerHTML = '&times;';
            removeBtn.style.cssText = 'position: absolute; top: 5px; right: 5px; width: 22px; height: 22px; border-radius: 9999px; background: rgba(62, 39, 35, 0.88); color: #ffffff; border: 1.5px solid #ffffff; font-size: 14px; font-weight: 800; line-height: 1; display: flex; align-items: center; justify-content: center; cursor: pointer; z-index: 3; box-shadow: 0 2px 6px rgba(0,0,0,0.28); padding: 0;';
            removeBtn.addEventListener('click', (e) => {
              e.preventDefault();
              e.stopPropagation();
              removeRequestAttachment(idx);
            });

            card.appendChild(removeBtn);
            preview.appendChild(card);
          });
        }

        function handleRequestFileSelect(input) {
          if (!input || !input.files) return;
          const incoming = Array.from(input.files);
          if (incoming.length === 0) return;

          incoming.forEach(file => {
            const key = `${file.name}::${file.size}`;
            const alreadyAdded = requestAttachmentFiles.some(item => item.key === key);
            if (alreadyAdded) return;

            const isImg = isPreviewableImageFile(file);
            requestAttachmentFiles.push({
              file: file,
              key: key,
              isImage: isImg,
              objectUrl: isImg ? URL.createObjectURL(file) : null
            });
          });

          syncRequestAttachmentsInput(input);
          renderRequestFilePreview();
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
              const fileInput = document.getElementById('requestAttachments');
              syncRequestAttachmentsInput(fileInput);

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

              formData.delete('request_attachments[]');
              formData.delete('report_attachments[]');
              formData.delete('supporting_docs[]');
              requestAttachmentFiles.forEach(item => {
                if (item && item.file) {
                  formData.append('request_attachments[]', item.file);
                }
              });

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
                clearRequestAttachments();
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
              syncRequestAttachmentsInput(document.getElementById('requestAttachments'));
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

      <!-- CATEGORY 5: RESIDENT PROFILE (SETTINGS INTERFACE) -->
      <section id="tenantProfile" class="app-view-section">
        <!-- Verification Notification Bar inside Account Information -->
        <div id="accountInfoVerificationAlert" class="account-info-verification-alert" style="display: none; margin-bottom: 18px; opacity: 1; transition: opacity 0.5s ease, transform 0.5s ease;"></div>

        <!-- Main Header -->
        <h1 class="tararent-settings-title">TENANT PROFILE SETTINGS</h1>

        <div class="tararent-settings-shell">
          <!-- Left Nav Container -->
          <div class="tararent-settings-nav">
            <button type="button" class="tararent-settings-nav-btn profile-subnav-item active" id="tenantSubnavAccount" onclick="switchProfileSubTab('accountInfo', this)">
              <span class="tab-icon"><i data-lucide="user" class="w-4 h-4" aria-hidden="true"></i></span>
              <span>ACCOUNT INFORMATION</span>
            </button>
            <button type="button" class="tararent-settings-nav-btn profile-subnav-item" id="tenantSubnavPassword" onclick="switchProfileSubTab('changePassword', this)">
              <span class="tab-icon"><i data-lucide="lock" class="w-4 h-4" aria-hidden="true"></i></span>
              <span>CHANGE PASSWORD</span>
            </button>
          </div>

          <!-- Right Content Main Card -->
          <div class="tararent-settings-card">

            <!-- Sub-Tab 1: Account Information -->
            <div id="paneAccountInfo" class="profile-tab-pane active-pane">
              <div>
                <!-- Top Row: Avatar + Name / Username + Role & Edit Profile -->
                <div class="tararent-settings-header" style="justify-content: space-between; flex-wrap: wrap; gap: 16px;">
                  <div style="display: flex; align-items: center; gap: 18px;">
                    <!-- Hidden profile picture form -->
                    <form id="profilePictureForm" method="POST" action="api/profile_api.php" enctype="multipart/form-data" style="display: none;">
                      <input type="hidden" name="action" value="upload_profile_picture">
                      <input type="hidden" name="user_id" value="<?= (int)($user['id'] ?? 0) ?>">
                      <input type="file" id="profile_picture" name="profile_picture" accept="image/jpeg,image/png,image/webp,image/jpg" onchange="previewProfilePicture(event)">
                    </form>

                    <!-- Circular Avatar Column with Change Profile Label Below (Fits properly, no overlap) -->
                    <div class="tararent-avatar-column">
                      <div class="tararent-settings-avatar-wrap" onclick="triggerProfilePictureInput()" title="Click to change profile picture" role="button" tabindex="0">
                        <?php if (!empty($userAvatarUrl)): ?>
                          <img id="avatarPreviewImg" src="<?= htmlspecialchars($userAvatarUrl) ?>" alt="Profile Photo" class="tararent-avatar-img">
                          <div id="avatarFallbackInitial" class="tararent-avatar-initial" style="display: none !important;"></div>
                        <?php else: ?>
                          <div id="avatarFallbackInitial" class="tararent-avatar-initial"><?= htmlspecialchars($userInitial) ?></div>
                          <img id="avatarPreviewImg" src="" alt="Profile Photo" class="tararent-avatar-img" style="display: none !important;">
                        <?php endif; ?>
                        <div class="tararent-avatar-hover-overlay" title="Change Profile Picture">
                          <i data-lucide="camera" class="w-5 h-5 text-white"></i>
                        </div>
                      </div>

                      <button type="button" class="tararent-change-profile-link" onclick="triggerProfilePictureInput()" title="Change Profile Picture">
                        <i data-lucide="camera" class="w-3 h-3"></i> Change Profile
                      </button>
                    </div>

                    <!-- Name and Handle -->
                    <div class="tararent-settings-title-group">
                      <div style="display: flex; align-items: center; gap: 10px; flex-wrap: wrap;">
                        <h2 class="tararent-settings-user-name" id="profileFullName" style="margin: 0;"><?= htmlspecialchars($user['name'] ?? 'Tenant') ?></h2>
                        <span style="font-size: 11px; font-weight: 800; background: #EFE7DA; color: #56380E; border: 1px solid #D1C2A5; border-radius: 9999px; padding: 2px 10px; text-transform: uppercase;">Resident</span>
                      </div>
                      <div class="tararent-settings-user-handle">@<?= htmlspecialchars($user['username'] ?? 'tenant') ?></div>
                    </div>
                  </div>
                </div>

                <!-- 1. PERSONAL INFORMATION SUBCARD -->
                <div class="tararent-settings-subcard">
                  <div class="tararent-subcard-header">
                    <h3 class="tararent-subcard-title">
                      <i data-lucide="user" class="w-4 h-4"></i> Personal Information
                    </h3>
                    <button type="button" class="tararent-btn-edit-profile" onclick="openEditAccountInfoModal()" style="font-size: 11.5px; padding: 5px 14px;">
                      <i data-lucide="edit-2" class="w-3 h-3"></i> Edit
                    </button>
                  </div>
                  <div class="tararent-subcard-grid">
                    <div class="tararent-info-field">
                      <span class="tararent-field-label">Full Name</span>
                      <span class="tararent-field-value"><?= htmlspecialchars($user['name'] ?? 'Resident') ?></span>
                    </div>
                    <div class="tararent-info-field">
                      <span class="tararent-field-label">Registered Email</span>
                      <span class="tararent-field-value"><?= htmlspecialchars($user['email'] ?? 'Not set') ?></span>
                    </div>
                    <div class="tararent-info-field">
                      <span class="tararent-field-label">Contact Number</span>
                      <span class="tararent-field-value"><?= htmlspecialchars($user['phone'] ?? 'Not set') ?></span>
                    </div>
                    <div class="tararent-info-field">
                      <span class="tararent-field-label">Emergency Contact Name</span>
                      <span class="tararent-field-value"><?= htmlspecialchars($user['emergency_contact_name'] ?? $user['emergency_contact'] ?? 'Not set') ?></span>
                    </div>
                    <div class="tararent-info-field">
                      <span class="tararent-field-label">Emergency Relationship</span>
                      <span class="tararent-field-value"><?= htmlspecialchars($user['emergency_contact_relationship'] ?? 'Not set') ?></span>
                    </div>
                    <div class="tararent-info-field">
                      <span class="tararent-field-label">Emergency Contact Phone</span>
                      <span class="tararent-field-value"><?= htmlspecialchars($user['emergency_contact_phone'] ?? 'Not set') ?></span>
                    </div>
                  </div>
                </div>

                <!-- 2. RENTAL INFORMATION SUBCARD (STRICTLY READ-ONLY) -->
                <div class="tararent-settings-subcard" style="border-left: 3.5px solid #AA7B3E;">
                  <div class="tararent-subcard-header">
                    <div style="display: flex; align-items: center; gap: 8px;">
                      <h3 class="tararent-subcard-title">
                        <i data-lucide="home" class="w-4 h-4"></i> Rental Information
                      </h3>
                      <span class="tararent-readonly-badge"><i data-lucide="lock" class="w-3 h-3"></i> READ-ONLY</span>
                    </div>
                    <span style="font-size: 11px; font-weight: 700; color: #8C6D43;">Managed by Landlord</span>
                  </div>
                  <div class="tararent-subcard-grid">
                    <div class="tararent-info-field">
                      <span class="tararent-field-label">Assigned Unit</span>
                      <span class="tararent-field-value font-black text-[#56380E]"><?= htmlspecialchars(!empty($unit['unit_number']) ? (str_starts_with(strtolower($unit['unit_number']), 'unit') ? $unit['unit_number'] : 'Unit ' . $unit['unit_number']) : 'Unit 1') ?></span>
                    </div>
                    <div class="tararent-info-field">
                      <span class="tararent-field-label">Date of Moving In</span>
                      <span class="tararent-field-value"><?= !empty($user['move_in_date']) ? date('F d, Y', strtotime($user['move_in_date'])) : 'November 18, 2026' ?></span>
                    </div>
                    <div class="tararent-info-field">
                      <span class="tararent-field-label">Monthly Rent Amount</span>
                      <span class="tararent-field-value font-black text-[#56380E]">₱<?= number_format((float)($unit['monthly_rent'] ?? 3000.00), 2) ?></span>
                    </div>
                    <div class="tararent-info-field">
                      <span class="tararent-field-label">Advance Deposit</span>
                      <div style="display: flex; align-items: center; gap: 8px; flex-wrap: wrap;">
                        <span class="tararent-field-value">₱<?= number_format((float)($depositBill['amount_due'] ?? ($unit['monthly_rent'] ?? 3000.00)), 2) ?></span>
                        <?php if ($depositBill && strtolower((string)($depositBill['status'] ?? '')) === 'approved'): ?>
                          <span style="font-size: 10.5px; font-weight: 900; background: #D1FAE5; color: #065F46; border: 1px solid #A7F3D0; border-radius: 9999px; padding: 2px 8px; text-transform: uppercase;">Paid</span>
                        <?php else: ?>
                          <span style="font-size: 10.5px; font-weight: 900; background: #FEE2E2; color: #991B1B; border: 1px solid #FECACA; border-radius: 9999px; padding: 2px 8px; text-transform: uppercase;">Unpaid</span>
                        <?php endif; ?>
                      </div>
                    </div>
                    <div class="tararent-info-field">
                      <span class="tararent-field-label">Rent Payment Status</span>
                      <div>
                        <?php if ($rentStatusDisplay === 'PAID'): ?>
                          <span style="display: inline-flex; align-items: center; gap: 5px; font-size: 11px; font-weight: 900; background: #D1FAE5; color: #065F46; border: 1px solid #A7F3D0; border-radius: 9999px; padding: 3px 10px; text-transform: uppercase;">
                            <span style="width: 6px; height: 6px; border-radius: 50%; background: #059669;"></span> PAID
                          </span>
                        <?php elseif ($rentStatusDisplay === 'PENDING'): ?>
                          <span style="display: inline-flex; align-items: center; gap: 5px; font-size: 11px; font-weight: 900; background: #FEF3C7; color: #92400E; border: 1px solid #FDE68A; border-radius: 9999px; padding: 3px 10px; text-transform: uppercase;">
                            <span style="width: 6px; height: 6px; border-radius: 50%; background: #D97706;"></span> PENDING
                          </span>
                        <?php elseif ($rentStatusDisplay === 'OVERDUE'): ?>
                          <span style="display: inline-flex; align-items: center; gap: 5px; font-size: 11px; font-weight: 900; background: #FEE2E2; color: #991B1B; border: 1px solid #FECACA; border-radius: 9999px; padding: 3px 10px; text-transform: uppercase;">
                            <span style="width: 6px; height: 6px; border-radius: 50%; background: #DC2626;"></span> OVERDUE
                          </span>
                        <?php else: ?>
                          <span style="display: inline-flex; align-items: center; gap: 5px; font-size: 11px; font-weight: 900; background: #FEE2E2; color: #991B1B; border: 1px solid #FECACA; border-radius: 9999px; padding: 3px 10px; text-transform: uppercase;">
                            <span style="width: 6px; height: 6px; border-radius: 50%; background: #DC2626;"></span> UNPAID
                          </span>
                        <?php endif; ?>
                      </div>
                    </div>
                  </div>
                  <div class="tararent-readonly-notice">
                    <i data-lucide="shield-alert" class="w-4 h-4 flex-shrink-0" style="color: #92400E;"></i>
                    <span>Rental terms, assignment, deposit, and payment ledgers are strictly read-only and managed exclusively by the property administrator.</span>
                  </div>
                </div>

                <!-- 3. ACCOUNT INFORMATION SUBCARD -->
                <div class="tararent-settings-subcard">
                  <div class="tararent-subcard-header">
                    <h3 class="tararent-subcard-title">
                      <i data-lucide="shield-check" class="w-4 h-4"></i> Account Information
                    </h3>
                  </div>
                  <div class="tararent-subcard-grid">
                    <div class="tararent-info-field">
                      <span class="tararent-field-label">Username</span>
                      <span class="tararent-field-value font-mono">@<?= htmlspecialchars($user['username'] ?? 'tenant') ?></span>
                    </div>
                    <div class="tararent-info-field">
                      <span class="tararent-field-label">Account Role</span>
                      <span class="tararent-field-value"><?= htmlspecialchars(ucfirst($user['role'] ?? 'Tenant')) ?></span>
                    </div>
                    <div class="tararent-info-field">
                      <span class="tararent-field-label">Account Creation Date</span>
                      <span class="tararent-field-value"><?= !empty($user['created_at']) ? date('F d, Y', strtotime($user['created_at'])) : 'Recently' ?></span>
                    </div>
                    <div class="tararent-info-field">
                      <span class="tararent-field-label">Account Verification Status</span>
                      <div style="display: flex; align-items: center; gap: 10px; flex-wrap: wrap; margin-top: 3px;">
                        <?php if ($isTenantVerified): ?>
                          <span id="tenantAccountVerificationBadge" style="display: inline-flex; align-items: center; gap: 4px; font-size: 11px; font-weight: 900; background: #D1FAE5; color: #065F46; border: 1px solid #A7F3D0; border-radius: 9999px; padding: 3px 12px; text-transform: uppercase;">
                            <i data-lucide="check-circle-2" class="w-3.5 h-3.5"></i> Verified
                          </span>
                        <?php else: ?>
                          <span id="tenantAccountVerificationBadge" style="display: inline-flex; align-items: center; gap: 4px; font-size: 11px; font-weight: 900; background: #FEE2E2; color: #991B1B; border: 1px solid #FECACA; border-radius: 9999px; padding: 3px 12px; text-transform: uppercase;">
                            <i data-lucide="alert-circle" class="w-3.5 h-3.5"></i> Unverified
                          </span>
                          <button type="button" class="tararent-btn-verify-account" id="tenantBtnVerifyAccount" onclick="openVerifyAccountModal()" title="Verify your account now">
                            <i data-lucide="shield-check" class="w-3.5 h-3.5"></i>
                            <span>Verify Account</span>
                          </button>
                        <?php endif; ?>
                      </div>
                    </div>
                  </div>
                </div>

                <!-- 4. ID INFORMATION & VERIFICATION SUBCARD -->
                <div class="tararent-settings-subcard">
                  <div class="tararent-subcard-header">
                    <h3 class="tararent-subcard-title">
                      <i data-lucide="credit-card" class="w-4 h-4"></i> ID Information &amp; Verification
                    </h3>
                    <div style="display: flex; align-items: center; gap: 8px; flex-wrap: wrap;">
                      <button type="button" class="tararent-btn-view-id" onclick="openTenantIdModal()" title="View submitted ID photo">
                        <i data-lucide="eye" class="w-3.5 h-3.5"></i> View ID Photo
                      </button>
                      <button type="button" class="tararent-btn-update-id" onclick="openVerifyAccountModal(true)" title="Update your valid identification">
                        <i data-lucide="upload" class="w-3.5 h-3.5"></i> Update Valid ID
                      </button>
                    </div>
                  </div>
                  <div class="tararent-subcard-grid">
                    <div class="tararent-info-field">
                      <span class="tararent-field-label">Submitted ID Type</span>
                      <span class="tararent-field-value"><?= htmlspecialchars($user['id_type'] ?? ($isTenantVerified ? 'Government ID' : 'None Uploaded')) ?></span>
                    </div>
                    <div class="tararent-info-field">
                      <span class="tararent-field-label">Date of Last Verification</span>
                      <span class="tararent-field-value"><?= !empty($user['verified_at']) ? date('F d, Y', strtotime($user['verified_at'])) : (!empty($user['created_at']) && $isTenantVerified ? date('F d, Y', strtotime($user['created_at'])) : 'Pending Verification') ?></span>
                    </div>
                  </div>
                </div>

              </div>
            </div>

            <!-- Sub-Tab 2: Change Password -->
            <div id="paneChangePassword" class="profile-tab-pane">
              <div class="tararent-settings-pwd-header">
                <h2 class="tararent-settings-pwd-title">Change Password</h2>
                <p class="tararent-settings-pwd-sub">Protect your account by using a strong, unique password.</p>
              </div>

              <form id="changePasswordForm">
                <div class="tararent-settings-form-group">
                  <label class="tararent-settings-form-label" for="profCurrentPass">Current Password<span class="req">*</span></label>
                  <input type="password" id="profCurrentPass" class="tararent-settings-input" placeholder="" required autocomplete="current-password">
                </div>

                <div class="tararent-settings-form-group">
                  <label class="tararent-settings-form-label" for="profNewPass">New Password<span class="req">*</span></label>
                  <input type="password" id="profNewPass" class="tararent-settings-input" placeholder="" minlength="6" required autocomplete="new-password">
                </div>

                <div class="tararent-settings-form-group">
                  <label class="tararent-settings-form-label" for="profConfirmPass">Confirm Password<span class="req">*</span></label>
                  <input type="password" id="profConfirmPass" class="tararent-settings-input" placeholder="" minlength="6" required autocomplete="new-password">
                </div>

                <div style="text-align: right; max-width: 540px; margin-top: 24px;">
                  <button type="submit" class="tararent-settings-save-btn" id="btnSavePassword">
                    save password
                  </button>
                </div>
              </form>
            </div>

          </div>
        </div>
      </section>

      </div> <!-- /.tenant-content-container -->

    </main>

  </div>

  <!-- =====================================================================
       MODAL: VIEW TENANT IDENTIFICATION DOCUMENT (PHOTO & DETAILS)
       Centered fixed dialog with dark blur backdrop and escape/backdrop controls
       ===================================================================== -->
  <div class="modal-backdrop" id="modalTenantProfileId" style="display: none; pointer-events: none; position: fixed; inset: 0; width: 100vw; height: 100vh; background: rgba(20, 14, 8, 0.68); z-index: 10030; align-items: center; justify-content: center; backdrop-filter: blur(4px); -webkit-backdrop-filter: blur(4px); padding: 16px; box-sizing: border-box;" onclick="if(event.target === this) closeTenantIdModal()">
    <div class="clay-modal" style="max-width: 520px; width: 94%; max-height: 90vh; overflow-y: auto; background: #FCFBF9; border: 2px solid #B89B72; border-radius: 24px; padding: 24px 26px; box-shadow: 0 20px 48px rgba(43, 33, 22, 0.28); margin: auto; position: relative;" onclick="event.stopPropagation()">
      
      <!-- Modal Header -->
      <div style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 16px; padding-bottom: 12px; border-bottom: 1px dashed rgba(140, 109, 67, 0.35);">
        <div>
          <h2 style="font-size: 18px; font-weight: 900; color: #3E2723; margin: 0 0 3px 0; display: flex; align-items: center; gap: 8px;">
            <i data-lucide="shield-check" class="w-5 h-5 text-[#8C6D43]"></i> Valid Identification Document
          </h2>
          <p style="font-size: 11.5px; font-weight: 600; color: #7A6245; margin: 0;">
            Government-issued identification on file for this account
          </p>
        </div>
        <button type="button" class="modal-close-btn" onclick="closeTenantIdModal()" title="Close" aria-label="Close" style="background: rgba(74, 55, 40, 0.1); border: none; border-radius: 50%; width: 34px; height: 34px; min-width: 34px; min-height: 34px; display: inline-flex; align-items: center; justify-content: center; color: #4A3728; cursor: pointer; transition: background 0.15s;">
          <i data-lucide="x" class="w-4 h-4"></i>
        </button>
      </div>

      <!-- Modal Body -->
      <?php 
        $tenantIdPhotoPath = '';
        if (!empty($user['id_photo_filename'])) {
            $rawIdFile = (string)$user['id_photo_filename'];
            if (str_starts_with($rawIdFile, 'http://') || str_starts_with($rawIdFile, 'https://')) {
                $tenantIdPhotoPath = $rawIdFile;
            } elseif (file_exists(__DIR__ . '/uploads/id_verifications/' . $rawIdFile)) {
                $tenantIdPhotoPath = 'uploads/id_verifications/' . htmlspecialchars($rawIdFile);
            } elseif (file_exists(__DIR__ . '/' . $rawIdFile)) {
                $tenantIdPhotoPath = htmlspecialchars($rawIdFile);
            }
        }
      ?>

      <?php if (!empty($tenantIdPhotoPath)): ?>
        <div style="background: #F5EFE6; border: 1.5px solid #D1C2A5; border-radius: 18px; padding: 16px; text-align: center; margin-bottom: 16px; box-shadow: inset 0 2px 6px rgba(43, 33, 22, 0.05);">
          <div style="max-height: 380px; display: flex; align-items: center; justify-content: center; overflow: hidden; border-radius: 12px; background: rgba(0,0,0,0.03);">
            <img id="tenantIdModalPreviewImg" src="<?= $tenantIdPhotoPath ?>" alt="Tenant ID Photo" style="max-width: 100%; max-height: 380px; width: auto; height: auto; object-fit: contain; border-radius: 12px; box-shadow: 0 4px 14px rgba(43, 33, 22, 0.15); display: block; margin: 0 auto; border: 1px solid rgba(140, 109, 67, 0.25);">
          </div>
          <div style="margin-top: 14px; display: flex; align-items: center; justify-content: center; gap: 8px; flex-wrap: wrap;">
            <span style="display: inline-flex; align-items: center; gap: 5px; font-size: 11.5px; font-weight: 800; color: #56380E; background: rgba(140, 109, 67, 0.14); padding: 4px 12px; border-radius: 9999px; border: 1px solid rgba(140, 109, 67, 0.25);">
              <i data-lucide="id-card" class="w-3.5 h-3.5 text-[#8C6D43]"></i>
              ID Type: <?= htmlspecialchars($user['id_type'] ?? 'Government ID') ?>
            </span>
            <span style="display: inline-flex; align-items: center; gap: 5px; font-size: 11.5px; font-weight: 800; <?= $isTenantVerified ? 'color: #166534; background: #DCFCE7; border: 1px solid #86EFAC;' : 'color: #991B1B; background: #FEE2E2; border: 1px solid #FCA5A5;' ?> padding: 4px 12px; border-radius: 9999px;">
              <i data-lucide="<?= $isTenantVerified ? 'check-circle' : 'alert-circle' ?>" class="w-3.5 h-3.5"></i>
              <?= strtoupper(htmlspecialchars($user['verification_status'] ?? 'Unverified')) ?>
            </span>
          </div>
        </div>
      <?php else: ?>
        <div style="text-align: center; padding: 32px 20px; background: #F7EFE1; border: 1.5px dashed #C4A47C; border-radius: 18px; margin-bottom: 16px;">
          <div style="width: 54px; height: 54px; margin: 0 auto 12px; border-radius: 50%; background: rgba(140, 109, 67, 0.12); display: flex; align-items: center; justify-content: center; color: #8C6D43;">
            <i data-lucide="file-question" class="w-7 h-7"></i>
          </div>
          <div style="font-size: 16px; font-weight: 900; color: #3E2723; margin-bottom: 6px;">No ID Document Uploaded Yet</div>
          <p style="font-size: 12.5px; color: #6D4D22; margin: 0 auto 18px; max-width: 320px; font-weight: 600; line-height: 1.5;">
            You have not submitted a valid government identification document. Complete verification to unlock all account features.
          </p>
          <button type="button" onclick="closeTenantIdModal(); openVerifyAccountModal(true);" class="tararent-btn-verify-account" style="display: inline-flex; align-items: center; gap: 6px; padding: 9px 22px; font-size: 12px; font-weight: 800; cursor: pointer;">
            <i data-lucide="shield-check" class="w-4 h-4"></i> Complete Verification Now
          </button>
        </div>
      <?php endif; ?>

      <!-- Modal Footer -->
      <div style="display: flex; justify-content: flex-end; align-items: center; gap: 10px; margin-top: 14px; padding-top: 12px; border-top: 1px dashed rgba(140, 109, 67, 0.3);">
        <?php if (!empty($tenantIdPhotoPath)): ?>
          <button type="button" onclick="closeTenantIdModal(); openVerifyAccountModal(true);" style="display: inline-flex; align-items: center; gap: 6px; padding: 9px 16px; border-radius: 12px; font-weight: 800; font-size: 12px; background: #F5F0E6; color: #56380E; border: 1.5px solid #8C6D43; cursor: pointer; transition: all 0.15s ease;">
            <i data-lucide="upload" class="w-3.5 h-3.5 text-[#8C6D43]"></i> Update ID
          </button>
        <?php endif; ?>
        <button type="button" onclick="closeTenantIdModal()" style="display: inline-flex; align-items: center; gap: 6px; padding: 9px 22px; border-radius: 12px; font-weight: 900; font-size: 12px; background: #4A3728; color: #FFFFFF; border: 1.5px solid #382618; cursor: pointer; transition: all 0.15s ease; box-shadow: 0 3px 10px rgba(56, 38, 24, 0.22);">
          Close
        </button>
      </div>

    </div>
  </div>

  <!-- =====================================================================
       MODAL: PROFILE PICTURE ADJUSTMENT & CIRCULAR CROP
       Allows tenant to zoom, pan/drag, reset, preview, and save profile picture
       ===================================================================== -->
  <div class="modal-backdrop" id="profileAdjustmentModal" style="display: none; pointer-events: none; align-items: center; justify-content: center; z-index: 10030;" onclick="if(event.target === this) closeProfileAdjustmentModal()">
    <div class="clay-modal" style="max-width: 460px; width: 94%; border: 2px solid #B89B72; border-radius: 24px; background: #FCFBF9; box-shadow: 0 20px 48px rgba(43, 33, 22, 0.25); padding: 22px 24px 24px; max-height: 94vh; overflow-y: auto;" onclick="event.stopPropagation()">
      
      <!-- Modal Header -->
      <div style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 14px; padding-bottom: 12px; border-bottom: 1px dashed rgba(140, 109, 67, 0.35);">
        <div>
          <h2 style="font-size: 18px; font-weight: 900; color: #3E2723; margin: 0 0 2px 0; display: flex; align-items: center; gap: 8px;">
            <i data-lucide="crop" class="w-5 h-5 text-[#8C6D43]"></i> Adjust Profile Picture
          </h2>
          <p style="font-size: 11.5px; font-weight: 600; color: #7A6245; margin: 0;">
            Drag to reposition your photo and use the slider to zoom in or out.
          </p>
        </div>
        <button type="button" class="modal-close-btn" onclick="closeProfileAdjustmentModal()" title="Close" aria-label="Close" style="background: rgba(74, 55, 40, 0.1); border: none; border-radius: 50%; width: 32px; height: 32px; display: inline-flex; align-items: center; justify-content: center; color: #4A3728; cursor: pointer; transition: background 0.15s;">
          <i data-lucide="x" class="w-4 h-4"></i>
        </button>
      </div>

      <!-- Interactive Cropping Area -->
      <div style="position: relative; width: 100%; max-width: 320px; height: 320px; margin: 0 auto 14px; border-radius: 18px; overflow: hidden; background: #1E160E; box-shadow: inset 0 2px 10px rgba(0,0,0,0.5); display: flex; align-items: center; justify-content: center;">
        <canvas id="cropCanvas" width="320" height="320" style="width: 320px; height: 320px; display: block; cursor: grab; touch-action: none; user-select: none;"></canvas>
        <div style="position: absolute; bottom: 8px; left: 50%; transform: translateX(-50%); background: rgba(0,0,0,0.65); color: #FAF3E7; font-size: 10.5px; font-weight: 700; padding: 3px 12px; border-radius: 9999px; pointer-events: none; display: flex; align-items: center; gap: 5px; white-space: nowrap; backdrop-filter: blur(4px);">
          <i data-lucide="move" class="w-3 h-3"></i> Drag to move • Scroll to zoom
        </div>
      </div>

      <!-- Zoom & Adjustment Controls -->
      <div style="background: #F5F0E6; border: 1.5px solid #D1C2A5; border-radius: 16px; padding: 12px 14px; margin-bottom: 14px;">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 8px;">
          <span style="font-size: 12px; font-weight: 800; color: #3E2723; display: flex; align-items: center; gap: 6px;">
            <i data-lucide="zoom-in" class="w-3.5 h-3.5 text-[#8C6D43]"></i> Zoom
          </span>
          <span id="cropZoomLabel" style="font-size: 12px; font-weight: 900; color: #56380E; font-family: monospace;">100%</span>
        </div>
        <div style="display: flex; align-items: center; gap: 8px;">
          <button type="button" onclick="stepCropZoom(-0.1)" title="Zoom Out" style="background: #FAF3E7; border: 1px solid #B89B72; border-radius: 8px; width: 32px; height: 32px; display: inline-flex; align-items: center; justify-content: center; cursor: pointer; color: #3E2723; font-weight: 900; font-size: 16px; transition: background 0.15s;">
            −
          </button>
          <input type="range" id="cropZoomSlider" min="1.0" max="3.0" step="0.01" value="1.0" oninput="handleCropZoomSlider(this.value)" style="flex: 1; accent-color: #56380E; cursor: pointer; height: 6px;">
          <button type="button" onclick="stepCropZoom(0.1)" title="Zoom In" style="background: #FAF3E7; border: 1px solid #B89B72; border-radius: 8px; width: 32px; height: 32px; display: inline-flex; align-items: center; justify-content: center; cursor: pointer; color: #3E2723; font-weight: 900; font-size: 16px; transition: background 0.15s;">
            +
          </button>
          <button type="button" onclick="resetCropAdjustment()" title="Reset Adjustment" style="background: #FAF3E7; border: 1px solid #B89B72; border-radius: 8px; padding: 6px 10px; font-size: 11px; font-weight: 800; color: #56380E; cursor: pointer; display: inline-flex; align-items: center; gap: 4px; white-space: nowrap; transition: background 0.15s;">
            <i data-lucide="rotate-ccw" class="w-3 h-3"></i> Reset
          </button>
        </div>
      </div>

      <!-- Live Circular Preview -->
      <div style="display: flex; align-items: center; gap: 14px; background: #FAF3E7; border: 1.5px solid #D1C2A5; border-radius: 16px; padding: 10px 14px; margin-bottom: 18px;">
        <canvas id="cropPreviewCanvas" width="56" height="56" style="width: 52px; height: 52px; border-radius: 50%; border: 2px solid #8C6D43; background: #EFE7DA; box-shadow: 0 2px 6px rgba(0,0,0,0.12); flex-shrink: 0; display: block;"></canvas>
        <div>
          <div style="font-weight: 900; font-size: 12.5px; color: #3E2723; margin-bottom: 2px;">Live Circular Preview</div>
          <div style="font-size: 11px; color: #7A6245; font-weight: 600;">This preview reflects your exact circular profile avatar.</div>
        </div>
      </div>

      <!-- Action Buttons (Cancel & Save) -->
      <div style="display: flex; gap: 12px;">
        <button type="button" onclick="closeProfileAdjustmentModal()" style="flex: 1; padding: 11px 16px; border-radius: 12px; font-weight: 800; font-size: 13px; background: #F5F0E6; color: #3E2723; border: 1.5px solid #8C6D43; cursor: pointer; transition: background 0.15s;">
          Cancel
        </button>
        <button type="button" id="btnSaveCroppedAvatar" onclick="saveCroppedProfilePicture()" style="flex: 2; padding: 11px 20px; border-radius: 12px; font-weight: 900; font-size: 13px; background: #4A3728; color: #FFFFFF; border: 1.5px solid #382618; box-shadow: 0 4px 12px rgba(56, 38, 24, 0.25); cursor: pointer; transition: background 0.15s; display: inline-flex; align-items: center; justify-content: center; gap: 8px;">
          <i data-lucide="check" class="w-4 h-4"></i> Save Profile Picture
        </button>
      </div>

    </div>
  </div>

  <!-- =====================================================================
       MODAL: EDIT TENANT ACCOUNT INFORMATION
       Allows tenant to update phone, email, and emergency contact details
       ===================================================================== -->
  <div class="modal-backdrop" id="editAccountInfoModal" style="display: none; pointer-events: none;">
    <div class="clay-modal bg-[#FCFBF9] border-2 border-[#B89B72] rounded-2xl p-6 sm:p-8 shadow-2xl" style="max-width: 540px; width: 94%;">
      <div class="flex justify-between items-center mb-4 pb-3 border-b border-dashed border-[#8C6D43]/30">
        <div>
          <h2 class="text-xl font-black text-[#3E2723] m-0 mb-0.5">Edit Personal Information</h2>
          <p class="text-xs font-semibold text-[#7A6245] m-0">Update your name, contact details, and emergency contacts</p>
        </div>
        <button type="button" class="modal-close-btn text-[#4A3728] hover:text-[#382618] p-1.5 rounded-lg hover:bg-[#4A3728]/10 transition-colors cursor-pointer" onclick="closeEditAccountInfoModal()" title="Close" aria-label="Close"><i data-lucide="x" class="w-5 h-5" aria-hidden="true"></i></button>
      </div>

      <!-- Read-Only Notice -->
      <div class="bg-amber-50 border border-amber-200 rounded-xl p-3 mb-4 text-xs font-semibold text-amber-900 flex items-center gap-2">
        <i data-lucide="lock" class="w-4 h-4 text-amber-700 flex-shrink-0"></i>
        <span>Rental details (Unit, Monthly Rent, Deposit, Move-in Date) are read-only and managed exclusively by your landlord.</span>
      </div>

      <form id="editAccountInfoForm">
        <!-- Name Breakdown (First, Middle, Last) -->
        <div class="mb-3.5">
          <label class="font-bold text-xs text-[#3E2723] block mb-1.5">Tenant Full Name</label>
          <div class="grid grid-cols-1 sm:grid-cols-3 gap-2">
            <div>
              <input type="text" id="editFirstName" name="first_name" class="w-full h-10 px-3 py-2 rounded-lg bg-[#F5F0E6] text-[#3E2723] font-semibold text-xs border border-[#B89B72] focus:outline-none focus:ring-2 focus:ring-[#4A3728] shadow-sm" value="<?= htmlspecialchars($tenantEditFirstName) ?>" placeholder="First Name *" required>
            </div>
            <div>
              <input type="text" id="editMiddleName" name="middle_name" class="w-full h-10 px-3 py-2 rounded-lg bg-[#F5F0E6] text-[#3E2723] font-semibold text-xs border border-[#B89B72] focus:outline-none focus:ring-2 focus:ring-[#4A3728] shadow-sm" value="<?= htmlspecialchars($tenantEditMiddleName) ?>" placeholder="Middle Name">
            </div>
            <div>
              <input type="text" id="editLastName" name="last_name" class="w-full h-10 px-3 py-2 rounded-lg bg-[#F5F0E6] text-[#3E2723] font-semibold text-xs border border-[#B89B72] focus:outline-none focus:ring-2 focus:ring-[#4A3728] shadow-sm" value="<?= htmlspecialchars($tenantEditLastName) ?>" placeholder="Last Name *" required>
            </div>
          </div>
        </div>

        <div class="grid grid-cols-1 sm:grid-cols-2 gap-3 mb-3.5">
          <div class="form-group mb-0">
            <label class="font-bold text-xs text-[#3E2723] block mb-1.5" for="editEmail">Email Address *</label>
            <input type="email" id="editEmail" name="email" class="w-full h-10 px-3.5 py-2 rounded-lg bg-[#F5F0E6] text-[#3E2723] font-semibold text-xs border border-[#B89B72] focus:outline-none focus:ring-2 focus:ring-[#4A3728] shadow-sm" value="<?= htmlspecialchars($user['email'] ?? '') ?>" placeholder="your.email@example.com" required>
          </div>

          <div class="form-group mb-0">
            <label class="font-bold text-xs text-[#3E2723] block mb-1.5" for="editPhone">Contact Number *</label>
            <input type="tel" id="editPhone" name="phone" class="w-full h-10 px-3.5 py-2 rounded-lg bg-[#F5F0E6] text-[#3E2723] font-semibold text-xs border border-[#B89B72] focus:outline-none focus:ring-2 focus:ring-[#4A3728] shadow-sm" value="<?= htmlspecialchars($user['phone'] ?? '') ?>" placeholder="+63 900 000 0000" required>
          </div>
        </div>

        <div class="bg-[#F5F0E6] border border-[#B89B72] rounded-xl p-3.5 shadow-sm mb-4">
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

        <div class="flex gap-3 mt-4">
          <button type="button" class="flex-1 py-2.5 px-4 rounded-xl font-bold text-xs bg-[#F5F0E6] text-[#3E2723] border border-[#8C6D43] hover:bg-[#E8D8B0] shadow-sm transition-colors cursor-pointer" onclick="closeEditAccountInfoModal()">Cancel</button>
          <button type="submit" class="flex-[2] py-2.5 px-5 rounded-xl font-black text-xs bg-[#4A3728] hover:bg-[#382618] text-white border border-[#382618] shadow-md transition-colors cursor-pointer" id="btnSaveAccountInfo">Save Information</button>
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
       MODAL: TENANT VIEW PAYMENT DETAILS & RECEIPT (STRICTLY VIEW-ONLY)
       ===================================================================== -->
  <div class="modal-backdrop" id="tenantPaymentDetailsModal" style="display: none; pointer-events: none; align-items: center; justify-content: center; z-index: 10025;" onclick="if(event.target === this) closeTenantPaymentDetailsModal()">
    <div class="clay-modal bg-[#FCFBF9] border-2 border-[#B89B72] rounded-2xl p-6 sm:p-8 shadow-2xl" style="max-width: 580px; width: 95%; max-height: 90vh; overflow-y: auto;" onclick="event.stopPropagation()">
      
      <!-- Modal Header -->
      <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 18px; border-bottom: 1px dashed rgba(140, 109, 67, 0.3); padding-bottom: 14px;">
        <div style="display: flex; align-items: center; gap: 10px;">
          <span class="clay-badge" id="tpdModalBadge" style="background: #F5F0E6; color: #4A3728; border: 1px solid #B89B72; font-weight: 800; font-size: 13px;">#PAY-0000</span>
          <div>
            <h2 style="font-size: 19px; font-weight: 800; color: #3E2723; margin: 0;" id="tpdModalTitle">Payment Details</h2>
            <p style="font-size: 12px; color: #7A6245; margin: 2px 0 0 0;">Transaction details, verification status, and proof receipt</p>
          </div>
        </div>
        <button type="button" class="modal-close-btn text-[#4A3728] hover:text-[#382618] p-1.5 rounded-lg hover:bg-[#4A3728]/10 transition-colors cursor-pointer" onclick="closeTenantPaymentDetailsModal()" title="Close" aria-label="Close">
          <i data-lucide="x" class="w-5 h-5" aria-hidden="true"></i>
        </button>
      </div>

      <!-- Financial & Status Highlight Banner -->
      <div style="background: #F5F0E6; border: 1px solid #B89B72; border-radius: var(--radius-md, 12px); padding: 14px 16px; margin-bottom: 16px; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 10px;">
        <div>
          <div style="font-size: 11px; font-weight: 700; color: #7A6245; text-transform: uppercase; letter-spacing: 0.5px;">Amount Paid / Due</div>
          <div id="tpdModalAmount" style="font-size: 22px; font-weight: 900; color: #3E2723; margin-top: 2px;">PHP 0.00</div>
        </div>
        <div style="text-align: right;">
          <div style="font-size: 11px; font-weight: 700; color: #7A6245; text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 4px;">Verification Status</div>
          <span id="tpdModalStatusBadge" class="inline-block px-3.5 py-1 font-black text-xs uppercase rounded shadow-sm" style="background: #22c55e; color: #ffffff;">VERIFIED</span>
        </div>
      </div>

      <!-- Key Details Grid -->
      <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px; font-size: 13px; margin-bottom: 16px;">
        <div style="background: #FAF7F2; padding: 10px 12px; border-radius: 8px; border: 1px solid rgba(184, 155, 114, 0.35);">
          <span style="display: block; font-size: 11px; font-weight: 700; color: #7A6245; text-transform: uppercase;">Payment Purpose</span>
          <strong id="tpdModalPurpose" style="color: #3E2723; font-size: 13.5px; display: block; margin-top: 2px;">-</strong>
        </div>
        <div style="background: #FAF7F2; padding: 10px 12px; border-radius: 8px; border: 1px solid rgba(184, 155, 114, 0.35);">
          <span style="display: block; font-size: 11px; font-weight: 700; color: #7A6245; text-transform: uppercase;">Payment Due Date</span>
          <strong id="tpdModalDueDate" style="color: #3E2723; font-size: 13.5px; display: block; margin-top: 2px;">-</strong>
        </div>
        <div style="background: #FAF7F2; padding: 10px 12px; border-radius: 8px; border: 1px solid rgba(184, 155, 114, 0.35);">
          <span style="display: block; font-size: 11px; font-weight: 700; color: #7A6245; text-transform: uppercase;">Reference Number</span>
          <strong id="tpdModalRefNo" style="color: #56380E; font-size: 13px; font-family: monospace; display: block; margin-top: 2px;">-</strong>
        </div>
        <div style="background: #FAF7F2; padding: 10px 12px; border-radius: 8px; border: 1px solid rgba(184, 155, 114, 0.35);">
          <span style="display: block; font-size: 11px; font-weight: 700; color: #7A6245; text-transform: uppercase;">Date Submitted</span>
          <strong id="tpdModalDateSubmitted" style="color: #3E2723; font-size: 13.5px; display: block; margin-top: 2px;">-</strong>
        </div>
      </div>

      <!-- Uploaded Proof of Payment Section -->
      <div style="margin-bottom: 16px;">
        <div style="font-size: 11px; font-weight: 700; color: #7A6245; text-transform: uppercase; margin-bottom: 6px;">Proof of Payment</div>
        <div id="tpdModalReceiptContainer" style="background: #F5F0E6; border: 1px solid #B89B72; border-radius: var(--radius-md, 12px); padding: 14px; text-align: center;">
          <!-- Placeholder if no receipt -->
          <div id="tpdModalNoReceipt" style="font-size: 13px; color: #7A6245; padding: 10px 0;">
            No proof receipt uploaded for this record.
          </div>
          <!-- Receipt Image Preview & Action -->
          <div id="tpdModalReceiptWrapper" style="display: none;">
            <div style="margin-bottom: 10px; display: flex; justify-content: center; gap: 10px;">
              <a id="tpdModalInspectBtn" href="#" target="_blank" rel="noopener" class="inline-flex items-center gap-1.5 px-4 py-2 rounded-lg text-xs font-bold bg-[#4A3728] hover:bg-[#382618] text-white shadow transition-colors cursor-pointer" style="text-decoration: none;">
                <i data-lucide="external-link" class="w-4 h-4" aria-hidden="true"></i> View Full Image in New Tab
              </a>
            </div>
            <div id="tpdModalImageWrapper" style="display: flex; justify-content: center; background: rgba(62, 39, 35, 0.04); border-radius: 8px; padding: 8px; border: 1px solid rgba(184, 155, 114, 0.4);">
              <img id="tpdModalReceiptImg" src="" alt="Payment Proof Receipt" style="max-height: 240px; max-width: 100%; object-fit: contain; border-radius: 6px; cursor: pointer; box-shadow: 0 4px 12px rgba(0,0,0,0.08);" onclick="window.open(this.src, '_blank')" title="Click to view full size in new tab">
            </div>
          </div>
        </div>
      </div>

      <!-- Admin / Owner Remarks -->
      <div style="margin-bottom: 18px;">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 6px;">
          <div style="font-size: 11px; font-weight: 700; color: #7A6245; text-transform: uppercase;">Admin / Owner Remarks</div>
          <span id="tpdModalVerifiedMeta" style="font-size: 11px; color: #7A6245; font-weight: 600;"></span>
        </div>
        <div id="tpdModalRemarksBox" style="background: #FFF9EE; border: 1.5px solid rgba(181, 143, 83, 0.35); border-radius: var(--radius-md, 12px); padding: 12px 14px; color: #4A3214; font-size: 13.5px; line-height: 1.5;">
          <span id="tpdModalRemarksText">No remarks provided yet.</span>
        </div>
      </div>

      <!-- Modal Footer (Close Button Only) -->
      <div style="display: flex; justify-content: flex-end; border-top: 1px dashed rgba(140, 109, 67, 0.3); padding-top: 14px;">
        <button type="button" class="py-2.5 px-6 rounded-xl font-black text-sm bg-[#4A3728] hover:bg-[#382618] text-white border border-[#382618] shadow-md transition-colors cursor-pointer" onclick="closeTenantPaymentDetailsModal()">Close</button>
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

          <!-- 2A. Front Side of ID Upload & Camera Zone -->
          <div class="form-group flex flex-col gap-1.5" style="margin: 0;">
            <div class="flex items-center justify-between gap-2" style="margin-bottom: 6px;">
              <label class="form-label text-xs sm:text-sm font-bold text-[#3E2723] block m-0" style="font-weight: 800; color: #3E2723; font-size: 13px; margin: 0;">
                Front Side of ID <span class="text-red-600 font-bold" style="color: #b91c1c;">*</span>
              </label>
              <button type="button" 
                      id="btnOpenFrontIdCamera" 
                      onclick="openIdCaptureCamera('front')" 
                      class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-xl text-xs font-bold bg-[#F5F0E6] hover:bg-[#E8D8B0] text-[#4A3728] border border-[#B89B72] transition-colors cursor-pointer shadow-xs active:scale-95"
                      style="background: #F5F0E6; color: #4A3728; border: 1.5px solid #B89B72; font-size: 12px; font-weight: 700; padding: 5px 12px; border-radius: 10px; display: inline-flex; align-items: center; gap: 6px; cursor: pointer; transition: all 0.15s ease;">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" style="width: 14px; height: 14px;">
                  <path stroke-linecap="round" stroke-linejoin="round" d="M6.827 6.175A2.31 2.31 0 0 1 5.186 7.23c-.38.054-.757.112-1.134.175C2.999 7.58 2.25 8.507 2.25 9.574V18a2.25 2.25 0 0 0 2.25 2.25h15A2.25 2.25 0 0 0 21.75 18V9.574c0-1.067-.75-1.994-1.802-2.169a47.865 47.865 0 0 0-1.134-.175 2.31 2.31 0 0 1-1.64-1.055l-.822-1.316a2.192 2.192 0 0 0-1.736-1.039 48.774 48.774 0 0 0-5.232 0 2.192 2.192 0 0 0-1.736 1.039l-.821 1.316Z" />
                  <path stroke-linecap="round" stroke-linejoin="round" d="M16.5 12.75a4.5 4.5 0 1 1-9 0 4.5 4.5 0 0 1 9 0ZM18.75 10.5h.008v.008h-.008V10.5Z" />
                </svg>
                <span>Open Camera</span>
              </button>
            </div>

            <!-- Upload Drop Zone (Front) -->
            <div class="tararent-id-upload-zone w-full flex flex-col items-center justify-center border-2 border-dashed border-[#B89B72] hover:border-[#4A3728] rounded-2xl p-5 bg-[#F5F0E6]/60 hover:bg-[#F5F0E6] transition-all duration-200 cursor-pointer text-center group" 
                 id="idFrontDropZone" 
                 onclick="document.getElementById('idFrontFileInput').click()"
                 style="border: 2px dashed #B89B72; border-radius: 16px; background: rgba(245, 240, 230, 0.6); padding: 20px 16px; display: flex; flex-direction: column; align-items: center; justify-content: center; text-align: center; cursor: pointer; transition: all 0.2s ease; width: 100%; box-sizing: border-box;">
              <div class="tararent-upload-icon-circle w-11 h-11 rounded-full bg-[#4A3728]/10 flex items-center justify-center text-[#4A3728] mb-2 transition-transform group-hover:scale-110" style="width: 44px; height: 44px; border-radius: 9999px; background: rgba(74, 55, 40, 0.1); display: flex; align-items: center; justify-content: center; color: #4A3728; margin-bottom: 8px;">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor" class="w-6 h-6" style="width: 24px; height: 24px;">
                  <path stroke-linecap="round" stroke-linejoin="round" d="M12 16.5V9.75m0 0 3 3m-3-3-3 3M6.75 19.5a4.5 4.5 0 0 1-1.41-8.775 5.25 5.25 0 0 1 10.233-2.33 3 3 0 0 1 3.758 3.848A3.752 3.752 0 0 1 18 19.5H6.75Z" />
                </svg>
              </div>
              <div class="tararent-upload-prompt text-sm font-bold text-[#3E2723] mb-1" style="font-size: 13px; font-weight: 700; color: #3E2723; margin-bottom: 2px;">Click to upload or drag &amp; drop Front ID</div>
              <div class="tararent-upload-hint text-xs text-[#7A6245] font-semibold" style="font-size: 11px; color: #7A6245; font-weight: 600;">Supports JPG, PNG, or WEBP (Max 10MB)</div>
            </div>

            <!-- Live Camera Capture Interface (Front) -->
            <div id="idFrontCameraCaptureContainer" class="hidden rounded-2xl overflow-hidden bg-[#1c1206] border-2 border-[#B89B72] shadow-lg flex-col" style="display: none; border-radius: 16px; overflow: hidden; background: #1c1206; border: 2px solid #B89B72; box-shadow: 0 6px 20px rgba(43,26,6,0.18); flex-direction: column;">
              <div class="relative w-full h-52 sm:h-60 bg-[#0f0b06] flex items-center justify-center overflow-hidden" style="position: relative; width: 100%; height: 220px; background: #0f0b06; display: flex; align-items: center; justify-content: center; overflow: hidden;">
                <video id="idFrontCameraVideo" autoplay playsinline muted class="w-full h-full object-cover block" style="width: 100%; height: 100%; object-fit: cover; display: block;"></video>
                <canvas id="idFrontCameraCanvas" style="display: none;"></canvas>
                <img id="idFrontCameraCapturedPreview" alt="Captured Front ID Preview" class="hidden w-full h-full object-contain" style="display: none; width: 100%; height: 100%; object-fit: contain;">
                <div id="idFrontCameraCardGuide" class="pointer-events-none absolute inset-3 sm:inset-5 border-2 border-dashed border-[#B89B72]/80 rounded-xl flex items-end justify-center pb-2.5 transition-opacity" style="position: absolute; top: 12px; bottom: 12px; left: 16px; right: 16px; border: 2px dashed rgba(184, 155, 114, 0.85); border-radius: 12px; display: flex; align-items: flex-end; justify-content: center; padding-bottom: 8px; pointer-events: none;">
                  <span class="bg-[#1c1206]/80 text-[#F5F0E6] text-[11px] font-bold px-2.5 py-0.5 rounded-full border border-white/20" style="background: rgba(28, 18, 6, 0.85); color: #F5F0E6; font-size: 11px; font-weight: 700; padding: 3px 10px; border-radius: 9999px; border: 1px solid rgba(255, 255, 255, 0.2);">
                    Align FRONT side of ID card within frame
                  </span>
                </div>
              </div>
              <div class="p-3 bg-[#F5F0E6] border-t border-[#B89B72]/40" style="padding: 10px 14px; background: #F5F0E6; border-top: 1px solid rgba(184, 155, 114, 0.4);">
                <div id="idFrontCameraLiveControls" class="flex items-center justify-between gap-2.5" style="display: flex; align-items: center; justify-content: space-between; gap: 10px;">
                  <button type="button" 
                          onclick="closeIdCaptureCamera('front')" 
                          class="px-3.5 py-1.5 rounded-xl text-xs font-bold text-[#4A3728] hover:text-[#382618] hover:bg-[#E8D8B0] transition-colors cursor-pointer border border-[#B89B72]"
                          style="background: transparent; color: #4A3728; border: 1.5px solid #B89B72; font-size: 12px; font-weight: 700; padding: 6px 12px; border-radius: 10px; cursor: pointer;">
                    Cancel
                  </button>
                  <button type="button" 
                          onclick="captureIdPhotoFromCamera('front')" 
                          class="px-4 py-1.5 rounded-xl text-xs font-black bg-[#4A3728] hover:bg-[#382618] text-white transition-all cursor-pointer shadow-md inline-flex items-center gap-1.5 active:scale-95"
                          style="background: #4A3728; color: #ffffff; border: 1px solid #382618; font-size: 12px; font-weight: 800; padding: 7px 16px; border-radius: 10px; cursor: pointer; display: inline-flex; align-items: center; gap: 6px;">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" style="width: 14px; height: 14px;">
                      <path stroke-linecap="round" stroke-linejoin="round" d="M6.827 6.175A2.31 2.31 0 0 1 5.186 7.23c-.38.054-.757.112-1.134.175C2.999 7.58 2.25 8.507 2.25 9.574V18a2.25 2.25 0 0 0 2.25 2.25h15A2.25 2.25 0 0 0 21.75 18V9.574c0-1.067-.75-1.994-1.802-2.169a47.865 47.865 0 0 0-1.134-.175 2.31 2.31 0 0 1-1.64-1.055l-.822-1.316a2.192 2.192 0 0 0-1.736-1.039 48.774 48.774 0 0 0-5.232 0 2.192 2.192 0 0 0-1.736 1.039l-.821 1.316Z" />
                      <path stroke-linecap="round" stroke-linejoin="round" d="M16.5 12.75a4.5 4.5 0 1 1-9 0 4.5 4.5 0 0 1 9 0ZM18.75 10.5h.008v.008h-.008V10.5Z" />
                    </svg>
                    <span>Capture Front</span>
                  </button>
                </div>
                <div id="idFrontCameraReviewControls" class="hidden flex items-center justify-between gap-2" style="display: none; align-items: center; justify-content: space-between; gap: 8px;">
                  <button type="button" 
                          onclick="closeIdCaptureCamera('front')" 
                          class="px-3 py-1.5 rounded-xl text-xs font-bold text-[#4A3728] hover:text-[#382618] hover:bg-[#E8D8B0] transition-colors cursor-pointer border border-[#B89B72]"
                          style="background: transparent; color: #4A3728; border: 1.5px solid #B89B72; font-size: 12px; font-weight: 700; padding: 6px 12px; border-radius: 10px; cursor: pointer;">
                    Cancel
                  </button>
                  <div class="flex items-center gap-2" style="display: flex; align-items: center; gap: 8px;">
                    <button type="button" 
                            onclick="retakeIdPhotoCamera('front')" 
                            class="px-3 py-1.5 rounded-xl text-xs font-bold text-[#4A3728] bg-white hover:bg-[#FAF8F5] transition-colors cursor-pointer border border-[#B89B72] inline-flex items-center gap-1 shadow-xs"
                            style="background: #ffffff; color: #4A3728; border: 1.5px solid #B89B72; font-size: 11.5px; font-weight: 700; padding: 6px 10px; border-radius: 10px; cursor: pointer; display: inline-flex; align-items: center; gap: 4px;">
                      <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" style="width: 13px; height: 13px;">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M16.023 9.348h4.992v-.001M2.985 19.644v-4.992m0 0h4.992m-4.993 0 3.181 3.183a8.25 8.25 0 0 0 13.803-3.7M4.031 9.865a8.25 8.25 0 0 1 13.803-3.7l3.181 3.182m0-4.991v4.99" />
                      </svg>
                      <span>Retake</span>
                    </button>
                    <button type="button" 
                            onclick="confirmUseCapturedIdPhoto('front')" 
                            class="px-3.5 py-1.5 rounded-xl text-xs font-black bg-[#4A3728] hover:bg-[#382618] text-white transition-all cursor-pointer shadow-md inline-flex items-center gap-1 active:scale-95"
                            style="background: #4A3728; color: #ffffff; border: 1px solid #382618; font-size: 12px; font-weight: 800; padding: 6px 12px; border-radius: 10px; cursor: pointer; display: inline-flex; align-items: center; gap: 5px;">
                      <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2.5" stroke="currentColor" style="width: 13px; height: 13px;">
                        <path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5" />
                      </svg>
                      <span>Use Photo</span>
                    </button>
                  </div>
                </div>
              </div>
            </div>

            <input type="file" id="idFrontFileInput" name="id_photo" accept="image/jpeg,image/png,image/webp" style="display: none;" onchange="handleVerificationFileSelect(event, 'front')">
            <!-- Legacy alias reference -->
            <input type="file" id="idFileInput" style="display: none;" onchange="handleVerificationFileSelect(event, 'front')">
            <input type="hidden" name="captured_id_base64" id="captured_front_id_base64" value="">
            <input type="hidden" id="captured_id_base64" value="">

            <!-- Image Preview Card (Front) -->
            <div id="idFrontImagePreviewCard" class="hidden rounded-xl overflow-hidden bg-white border-2 border-[#B89B72] shadow-md" style="display: none; border-radius: 14px; overflow: hidden; background: #ffffff; border: 2px solid #B89B72; box-shadow: 0 4px 12px rgba(43,26,6,0.12);">
              <div class="relative w-full h-44 bg-[#1c1206] flex items-center justify-center overflow-hidden" style="position: relative; width: 100%; height: 175px; background: #1c1206; display: flex; align-items: center; justify-content: center; overflow: hidden;">
                <img id="idFrontVisualPreviewImg" src="" alt="Uploaded Front ID Preview" class="w-full h-full object-contain block" style="width: 100%; height: 100%; object-fit: contain; display: block;">
                <button type="button" 
                        class="btn-remove-attached-photo absolute top-2.5 right-2.5 bg-[#1c1206]/85 hover:bg-[#1c1206] text-white w-8 h-8 rounded-full flex items-center justify-center cursor-pointer border border-white/35 transition-colors" 
                        id="btnRemoveFrontAttachedPhoto" 
                        onclick="removeAttachedPhoto(event, 'front')" 
                        title="Remove Front ID" 
                        aria-label="Remove Front ID" 
                        style="position: absolute; top: 10px; right: 10px; background: rgba(28, 18, 6, 0.85); color: #ffffff; width: 32px; height: 32px; border-radius: 50%; display: flex; align-items: center; justify-content: center; cursor: pointer; border: 1.5px solid rgba(255, 255, 255, 0.35); z-index: 20;">
                  <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" class="w-4 h-4 pointer-events-none" style="width: 16px; height: 16px; pointer-events: none;">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12" />
                  </svg>
                </button>
              </div>
              <div class="p-2.5 bg-[#F5F0E6] flex items-center justify-between gap-2.5 border-t border-[#B89B72]/30" style="padding: 9px 12px; background: #F5F0E6; display: flex; align-items: center; justify-content: space-between; gap: 8px; border-top: 1px solid rgba(184, 155, 114, 0.3);">
                <div class="flex items-center gap-2 overflow-hidden" style="display: flex; align-items: center; gap: 8px; overflow: hidden;">
                  <span class="inline-flex text-[#4A3728]" style="display: inline-flex; color: #4A3728;"><i data-lucide="id-card" class="w-4 h-4" aria-hidden="true"></i></span>
                  <div class="overflow-hidden truncate" style="overflow: hidden; text-overflow: ellipsis; white-space: nowrap;">
                    <div id="idFrontPreviewFileName" class="text-xs font-bold text-[#3E2723] truncate" style="font-size: 12.5px; font-weight: 700; color: #3E2723; overflow: hidden; text-overflow: ellipsis;"></div>
                    <div id="idFrontPreviewFileSize" class="text-[11px] text-[#7A6245] font-semibold" style="font-size: 10.5px; color: #7A6245; font-weight: 600;"></div>
                  </div>
                </div>
                <span class="bg-emerald-50 text-emerald-800 border border-emerald-200 text-xs px-2.5 py-0.5 rounded-full font-bold inline-flex items-center gap-1 shrink-0" style="background: #ecfdf5; color: #065f46; border: 1px solid #a7f3d0; font-size: 11px; padding: 3px 8px; font-weight: 800; display: inline-flex; align-items: center; gap: 4px;">
                  <i data-lucide="check" class="w-3 h-3" aria-hidden="true"></i> Front Attached
                </span>
              </div>
            </div>
            <!-- Legacy alias preview container -->
            <div id="idImagePreviewCard" style="display: none;"></div>
          </div>

          <!-- 2B. Back Side of ID Upload & Camera Zone -->
          <div class="form-group flex flex-col gap-1.5" style="margin: 0;">
            <div class="flex items-center justify-between gap-2" style="margin-bottom: 6px;">
              <label class="form-label text-xs sm:text-sm font-bold text-[#3E2723] block m-0" style="font-weight: 800; color: #3E2723; font-size: 13px; margin: 0;">
                Back Side of ID <span class="text-red-600 font-bold" style="color: #b91c1c;">*</span>
              </label>
              <button type="button" 
                      id="btnOpenBackIdCamera" 
                      onclick="openIdCaptureCamera('back')" 
                      class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-xl text-xs font-bold bg-[#F5F0E6] hover:bg-[#E8D8B0] text-[#4A3728] border border-[#B89B72] transition-colors cursor-pointer shadow-xs active:scale-95"
                      style="background: #F5F0E6; color: #4A3728; border: 1.5px solid #B89B72; font-size: 12px; font-weight: 700; padding: 5px 12px; border-radius: 10px; display: inline-flex; align-items: center; gap: 6px; cursor: pointer; transition: all 0.15s ease;">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" style="width: 14px; height: 14px;">
                  <path stroke-linecap="round" stroke-linejoin="round" d="M6.827 6.175A2.31 2.31 0 0 1 5.186 7.23c-.38.054-.757.112-1.134.175C2.999 7.58 2.25 8.507 2.25 9.574V18a2.25 2.25 0 0 0 2.25 2.25h15A2.25 2.25 0 0 0 21.75 18V9.574c0-1.067-.75-1.994-1.802-2.169a47.865 47.865 0 0 0-1.134-.175 2.31 2.31 0 0 1-1.64-1.055l-.822-1.316a2.192 2.192 0 0 0-1.736-1.039 48.774 48.774 0 0 0-5.232 0 2.192 2.192 0 0 0-1.736 1.039l-.821 1.316Z" />
                  <path stroke-linecap="round" stroke-linejoin="round" d="M16.5 12.75a4.5 4.5 0 1 1-9 0 4.5 4.5 0 0 1 9 0ZM18.75 10.5h.008v.008h-.008V10.5Z" />
                </svg>
                <span>Open Camera</span>
              </button>
            </div>

            <!-- Upload Drop Zone (Back) -->
            <div class="tararent-id-upload-zone w-full flex flex-col items-center justify-center border-2 border-dashed border-[#B89B72] hover:border-[#4A3728] rounded-2xl p-5 bg-[#F5F0E6]/60 hover:bg-[#F5F0E6] transition-all duration-200 cursor-pointer text-center group" 
                 id="idBackDropZone" 
                 onclick="document.getElementById('idBackFileInput').click()"
                 style="border: 2px dashed #B89B72; border-radius: 16px; background: rgba(245, 240, 230, 0.6); padding: 20px 16px; display: flex; flex-direction: column; align-items: center; justify-content: center; text-align: center; cursor: pointer; transition: all 0.2s ease; width: 100%; box-sizing: border-box;">
              <div class="tararent-upload-icon-circle w-11 h-11 rounded-full bg-[#4A3728]/10 flex items-center justify-center text-[#4A3728] mb-2 transition-transform group-hover:scale-110" style="width: 44px; height: 44px; border-radius: 9999px; background: rgba(74, 55, 40, 0.1); display: flex; align-items: center; justify-content: center; color: #4A3728; margin-bottom: 8px;">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor" class="w-6 h-6" style="width: 24px; height: 24px;">
                  <path stroke-linecap="round" stroke-linejoin="round" d="M12 16.5V9.75m0 0 3 3m-3-3-3 3M6.75 19.5a4.5 4.5 0 0 1-1.41-8.775 5.25 5.25 0 0 1 10.233-2.33 3 3 0 0 1 3.758 3.848A3.752 3.752 0 0 1 18 19.5H6.75Z" />
                </svg>
              </div>
              <div class="tararent-upload-prompt text-sm font-bold text-[#3E2723] mb-1" style="font-size: 13px; font-weight: 700; color: #3E2723; margin-bottom: 2px;">Click to upload or drag &amp; drop Back ID</div>
              <div class="tararent-upload-hint text-xs text-[#7A6245] font-semibold" style="font-size: 11px; color: #7A6245; font-weight: 600;">Supports JPG, PNG, or WEBP (Max 10MB)</div>
            </div>

            <!-- Live Camera Capture Interface (Back) -->
            <div id="idBackCameraCaptureContainer" class="hidden rounded-2xl overflow-hidden bg-[#1c1206] border-2 border-[#B89B72] shadow-lg flex-col" style="display: none; border-radius: 16px; overflow: hidden; background: #1c1206; border: 2px solid #B89B72; box-shadow: 0 6px 20px rgba(43,26,6,0.18); flex-direction: column;">
              <div class="relative w-full h-52 sm:h-60 bg-[#0f0b06] flex items-center justify-center overflow-hidden" style="position: relative; width: 100%; height: 220px; background: #0f0b06; display: flex; align-items: center; justify-content: center; overflow: hidden;">
                <video id="idBackCameraVideo" autoplay playsinline muted class="w-full h-full object-cover block" style="width: 100%; height: 100%; object-fit: cover; display: block;"></video>
                <canvas id="idBackCameraCanvas" style="display: none;"></canvas>
                <img id="idBackCameraCapturedPreview" alt="Captured Back ID Preview" class="hidden w-full h-full object-contain" style="display: none; width: 100%; height: 100%; object-fit: contain;">
                <div id="idBackCameraCardGuide" class="pointer-events-none absolute inset-3 sm:inset-5 border-2 border-dashed border-[#B89B72]/80 rounded-xl flex items-end justify-center pb-2.5 transition-opacity" style="position: absolute; top: 12px; bottom: 12px; left: 16px; right: 16px; border: 2px dashed rgba(184, 155, 114, 0.85); border-radius: 12px; display: flex; align-items: flex-end; justify-content: center; padding-bottom: 8px; pointer-events: none;">
                  <span class="bg-[#1c1206]/80 text-[#F5F0E6] text-[11px] font-bold px-2.5 py-0.5 rounded-full border border-white/20" style="background: rgba(28, 18, 6, 0.85); color: #F5F0E6; font-size: 11px; font-weight: 700; padding: 3px 10px; border-radius: 9999px; border: 1px solid rgba(255, 255, 255, 0.2);">
                    Align BACK side of ID card within frame
                  </span>
                </div>
              </div>
              <div class="p-3 bg-[#F5F0E6] border-t border-[#B89B72]/40" style="padding: 10px 14px; background: #F5F0E6; border-top: 1px solid rgba(184, 155, 114, 0.4);">
                <div id="idBackCameraLiveControls" class="flex items-center justify-between gap-2.5" style="display: flex; align-items: center; justify-content: space-between; gap: 10px;">
                  <button type="button" 
                          onclick="closeIdCaptureCamera('back')" 
                          class="px-3.5 py-1.5 rounded-xl text-xs font-bold text-[#4A3728] hover:text-[#382618] hover:bg-[#E8D8B0] transition-colors cursor-pointer border border-[#B89B72]"
                          style="background: transparent; color: #4A3728; border: 1.5px solid #B89B72; font-size: 12px; font-weight: 700; padding: 6px 12px; border-radius: 10px; cursor: pointer;">
                    Cancel
                  </button>
                  <button type="button" 
                          onclick="captureIdPhotoFromCamera('back')" 
                          class="px-4 py-1.5 rounded-xl text-xs font-black bg-[#4A3728] hover:bg-[#382618] text-white transition-all cursor-pointer shadow-md inline-flex items-center gap-1.5 active:scale-95"
                          style="background: #4A3728; color: #ffffff; border: 1px solid #382618; font-size: 12px; font-weight: 800; padding: 7px 16px; border-radius: 10px; cursor: pointer; display: inline-flex; align-items: center; gap: 6px;">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" style="width: 14px; height: 14px;">
                      <path stroke-linecap="round" stroke-linejoin="round" d="M6.827 6.175A2.31 2.31 0 0 1 5.186 7.23c-.38.054-.757.112-1.134.175C2.999 7.58 2.25 8.507 2.25 9.574V18a2.25 2.25 0 0 0 2.25 2.25h15A2.25 2.25 0 0 0 21.75 18V9.574c0-1.067-.75-1.994-1.802-2.169a47.865 47.865 0 0 0-1.134-.175 2.31 2.31 0 0 1-1.64-1.055l-.822-1.316a2.192 2.192 0 0 0-1.736-1.039 48.774 48.774 0 0 0-5.232 0 2.192 2.192 0 0 0-1.736 1.039l-.821 1.316Z" />
                      <path stroke-linecap="round" stroke-linejoin="round" d="M16.5 12.75a4.5 4.5 0 1 1-9 0 4.5 4.5 0 0 1 9 0ZM18.75 10.5h.008v.008h-.008V10.5Z" />
                    </svg>
                    <span>Capture Back</span>
                  </button>
                </div>
                <div id="idBackCameraReviewControls" class="hidden flex items-center justify-between gap-2" style="display: none; align-items: center; justify-content: space-between; gap: 8px;">
                  <button type="button" 
                          onclick="closeIdCaptureCamera('back')" 
                          class="px-3 py-1.5 rounded-xl text-xs font-bold text-[#4A3728] hover:text-[#382618] hover:bg-[#E8D8B0] transition-colors cursor-pointer border border-[#B89B72]"
                          style="background: transparent; color: #4A3728; border: 1.5px solid #B89B72; font-size: 12px; font-weight: 700; padding: 6px 12px; border-radius: 10px; cursor: pointer;">
                    Cancel
                  </button>
                  <div class="flex items-center gap-2" style="display: flex; align-items: center; gap: 8px;">
                    <button type="button" 
                            onclick="retakeIdPhotoCamera('back')" 
                            class="px-3 py-1.5 rounded-xl text-xs font-bold text-[#4A3728] bg-white hover:bg-[#FAF8F5] transition-colors cursor-pointer border border-[#B89B72] inline-flex items-center gap-1 shadow-xs"
                            style="background: #ffffff; color: #4A3728; border: 1.5px solid #B89B72; font-size: 11.5px; font-weight: 700; padding: 6px 10px; border-radius: 10px; cursor: pointer; display: inline-flex; align-items: center; gap: 4px;">
                      <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" style="width: 13px; height: 13px;">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M16.023 9.348h4.992v-.001M2.985 19.644v-4.992m0 0h4.992m-4.993 0 3.181 3.183a8.25 8.25 0 0 0 13.803-3.7M4.031 9.865a8.25 8.25 0 0 1 13.803-3.7l3.181 3.182m0-4.991v4.99" />
                      </svg>
                      <span>Retake</span>
                    </button>
                    <button type="button" 
                            onclick="confirmUseCapturedIdPhoto('back')" 
                            class="px-3.5 py-1.5 rounded-xl text-xs font-black bg-[#4A3728] hover:bg-[#382618] text-white transition-all cursor-pointer shadow-md inline-flex items-center gap-1 active:scale-95"
                            style="background: #4A3728; color: #ffffff; border: 1px solid #382618; font-size: 12px; font-weight: 800; padding: 6px 12px; border-radius: 10px; cursor: pointer; display: inline-flex; align-items: center; gap: 5px;">
                      <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2.5" stroke="currentColor" style="width: 13px; height: 13px;">
                        <path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5" />
                      </svg>
                      <span>Use Photo</span>
                    </button>
                  </div>
                </div>
              </div>
            </div>

            <input type="file" id="idBackFileInput" name="id_photo_back" accept="image/jpeg,image/png,image/webp" style="display: none;" onchange="handleVerificationFileSelect(event, 'back')">
            <input type="hidden" name="captured_id_back_base64" id="captured_back_id_base64" value="">

            <!-- Image Preview Card (Back) -->
            <div id="idBackImagePreviewCard" class="hidden rounded-xl overflow-hidden bg-white border-2 border-[#B89B72] shadow-md" style="display: none; border-radius: 14px; overflow: hidden; background: #ffffff; border: 2px solid #B89B72; box-shadow: 0 4px 12px rgba(43,26,6,0.12);">
              <div class="relative w-full h-44 bg-[#1c1206] flex items-center justify-center overflow-hidden" style="position: relative; width: 100%; height: 175px; background: #1c1206; display: flex; align-items: center; justify-content: center; overflow: hidden;">
                <img id="idBackVisualPreviewImg" src="" alt="Uploaded Back ID Preview" class="w-full h-full object-contain block" style="width: 100%; height: 100%; object-fit: contain; display: block;">
                <button type="button" 
                        class="btn-remove-attached-photo absolute top-2.5 right-2.5 bg-[#1c1206]/85 hover:bg-[#1c1206] text-white w-8 h-8 rounded-full flex items-center justify-center cursor-pointer border border-white/35 transition-colors" 
                        id="btnRemoveBackAttachedPhoto" 
                        onclick="removeAttachedPhoto(event, 'back')" 
                        title="Remove Back ID" 
                        aria-label="Remove Back ID" 
                        style="position: absolute; top: 10px; right: 10px; background: rgba(28, 18, 6, 0.85); color: #ffffff; width: 32px; height: 32px; border-radius: 50%; display: flex; align-items: center; justify-content: center; cursor: pointer; border: 1.5px solid rgba(255, 255, 255, 0.35); z-index: 20;">
                  <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" class="w-4 h-4 pointer-events-none" style="width: 16px; height: 16px; pointer-events: none;">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12" />
                  </svg>
                </button>
              </div>
              <div class="p-2.5 bg-[#F5F0E6] flex items-center justify-between gap-2.5 border-t border-[#B89B72]/30" style="padding: 9px 12px; background: #F5F0E6; display: flex; align-items: center; justify-content: space-between; gap: 8px; border-top: 1px solid rgba(184, 155, 114, 0.3);">
                <div class="flex items-center gap-2 overflow-hidden" style="display: flex; align-items: center; gap: 8px; overflow: hidden;">
                  <span class="inline-flex text-[#4A3728]" style="display: inline-flex; color: #4A3728;"><i data-lucide="id-card" class="w-4 h-4" aria-hidden="true"></i></span>
                  <div class="overflow-hidden truncate" style="overflow: hidden; text-overflow: ellipsis; white-space: nowrap;">
                    <div id="idBackPreviewFileName" class="text-xs font-bold text-[#3E2723] truncate" style="font-size: 12.5px; font-weight: 700; color: #3E2723; overflow: hidden; text-overflow: ellipsis;"></div>
                    <div id="idBackPreviewFileSize" class="text-[11px] text-[#7A6245] font-semibold" style="font-size: 10.5px; color: #7A6245; font-weight: 600;"></div>
                  </div>
                </div>
                <span class="bg-emerald-50 text-emerald-800 border border-emerald-200 text-xs px-2.5 py-0.5 rounded-full font-bold inline-flex items-center gap-1 shrink-0" style="background: #ecfdf5; color: #065f46; border: 1px solid #a7f3d0; font-size: 11px; padding: 3px 8px; font-weight: 800; display: inline-flex; align-items: center; gap: 4px;">
                  <i data-lucide="check" class="w-3 h-3" aria-hidden="true"></i> Back Attached
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

  <!-- LIGHTBOX MODAL: REPORT ATTACHMENT FULL PHOTO PREVIEW -->
  <div class="modal-backdrop hidden" id="requestAttachmentLightboxModal" onclick="if (event.target === this) { event.stopPropagation(); closeModal('requestAttachmentLightboxModal'); }" style="display: none; pointer-events: none; align-items: center; justify-content: center; background: rgba(15, 23, 42, 0.85); backdrop-filter: blur(4px); z-index: 100050; position: fixed; inset: 0; padding: 20px;" role="dialog" aria-modal="true" aria-labelledby="requestAttachmentLightboxTitle">
    <div onclick="event.stopPropagation()" style="position: relative; max-width: 94vw; max-height: 92vh; background: #ffffff; border-radius: 16px; padding: 14px 16px 16px; box-shadow: 0 25px 60px rgba(0,0,0,0.45); display: flex; flex-direction: column; align-items: center; border: 2px solid #B89B72;">
      <div style="width: 100%; display: flex; justify-content: space-between; align-items: center; gap: 16px; margin-bottom: 12px; padding-bottom: 10px; border-bottom: 1px solid #E8D8B0;">
        <h3 id="requestAttachmentLightboxTitle" style="font-size: 15px; font-weight: 800; color: #3E2723; margin: 0; display: flex; align-items: center; gap: 8px; min-width: 0;">
          <i data-lucide="image" class="w-4 h-4 flex-shrink-0" style="color: #9A7B4F;" aria-hidden="true"></i>
          <span style="overflow: hidden; text-overflow: ellipsis; white-space: nowrap;">Photo Preview</span>
        </h3>
        <button type="button" class="modal-close-btn" onclick="closeModal('requestAttachmentLightboxModal')" title="Close photo preview (Esc)" aria-label="Close" style="width: 34px; height: 34px; border-radius: 999px; border: 1px solid #E8D8B0; background: #FAF5EC; color: #3E2723; display: inline-flex; align-items: center; justify-content: center; cursor: pointer; font-weight: 800; flex-shrink: 0;">
          <i data-lucide="x" class="w-4 h-4" style="pointer-events: none;" aria-hidden="true"></i>
        </button>
      </div>
      <div style="display: flex; align-items: center; justify-content: center; min-width: 240px; min-height: 160px;">
        <img id="requestAttachmentLightboxImg" src="" alt="Attached photo preview" style="max-width: 90vw; max-height: 82vh; width: auto; height: auto; object-fit: contain; border-radius: 10px; display: block;">
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
      modal.classList.add('open', 'active');
      modal.style.display = 'flex';
      modal.style.pointerEvents = 'auto';
      document.body.style.overflow = 'hidden';
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
        closeVerifyAccountModal(true);
      } else if (id === 'editAccountInfoModal' && typeof closeEditAccountInfoModal === 'function') {
        closeEditAccountInfoModal(true);
      } else if (id === 'verificationActionPromptModal' && typeof closeVerificationActionPrompt === 'function') {
        closeVerificationActionPrompt(true);
      } else if (id === 'tenantTicketModal' && typeof closeTenantTicketModal === 'function') {
        closeTenantTicketModal(true);
      } else if (id === 'tenantReportModal' && typeof closeTenantReportModal === 'function') {
        closeTenantReportModal(true);
      } else if (id === 'termsContentModal' && typeof closeTermsModal === 'function') {
        closeTermsModal(true);
      } else if (id === 'privacyContentModal' && typeof closePrivacyModal === 'function') {
        closePrivacyModal(true);
      } else if (id === 'modalTenantProfileId' && typeof closeTenantIdModal === 'function') {
        closeTenantIdModal(true);
      } else if (id === 'profileAdjustmentModal' && typeof closeProfileAdjustmentModal === 'function') {
        closeProfileAdjustmentModal(true);
      } else if (id === 'tenantPaymentDetailsModal' && typeof closeTenantPaymentDetailsModal === 'function') {
        closeTenantPaymentDetailsModal(true);
      }
    }
    window.closeModal = closeModal;

    // =========================================================================
    // TENANT VIEW PAYMENT DETAILS & RECEIPT MODAL
    // =========================================================================
    function openTenantPaymentDetailsModal(payment) {
      if (!payment) return;

      const modal = document.getElementById('tenantPaymentDetailsModal');
      if (!modal) return;

      // 1. Badge & Header
      const badgeEl = document.getElementById('tpdModalBadge');
      if (badgeEl) {
        badgeEl.textContent = payment.id ? `#PAY-${String(payment.id).padStart(4, '0')}` : '#PAY-HIST';
      }

      const titleEl = document.getElementById('tpdModalTitle');
      if (titleEl) {
        titleEl.textContent = payment.purpose || 'Payment Details';
      }

      // 2. Amount
      const amtEl = document.getElementById('tpdModalAmount');
      if (amtEl) {
        const val = parseFloat(payment.display_amount || payment.amount_paid || payment.amount_due || 0);
        amtEl.textContent = 'PHP ' + val.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
      }

      // 3. Status Badge
      const stBadge = document.getElementById('tpdModalStatusBadge');
      if (stBadge) {
        const stLower = String(payment.status || 'unpaid').toLowerCase().trim();
        if (stLower === 'approved') {
          stBadge.textContent = 'VERIFIED';
          stBadge.style.background = '#22c55e';
          stBadge.style.color = '#ffffff';
        } else if (stLower === 'pending') {
          stBadge.textContent = 'PENDING';
          stBadge.style.background = '#f59e0b';
          stBadge.style.color = '#ffffff';
        } else if (stLower === 'rejected') {
          stBadge.textContent = 'REJECTED';
          stBadge.style.background = '#ef4444';
          stBadge.style.color = '#ffffff';
        } else if (stLower === 'overdue') {
          stBadge.textContent = 'OVERDUE';
          stBadge.style.background = '#ef4444';
          stBadge.style.color = '#ffffff';
        } else {
          stBadge.textContent = 'UNPAID';
          stBadge.style.background = '#ef4444';
          stBadge.style.color = '#ffffff';
        }
      }

      // 4. Details Grid: Purpose, Due Date, Reference Number, Date Submitted
      const purpEl = document.getElementById('tpdModalPurpose');
      if (purpEl) purpEl.textContent = payment.purpose || 'Rent';

      const dueEl = document.getElementById('tpdModalDueDate');
      if (dueEl) dueEl.textContent = payment.due_date || 'N/A';

      const refEl = document.getElementById('tpdModalRefNo');
      if (refEl) refEl.textContent = payment.ref_number || 'N/A';

      const dateSubEl = document.getElementById('tpdModalDateSubmitted');
      if (dateSubEl) dateSubEl.textContent = payment.payment_date || '-';

      // 5. Uploaded Proof of Payment Handling
      const noReceiptEl = document.getElementById('tpdModalNoReceipt');
      const receiptWrapEl = document.getElementById('tpdModalReceiptWrapper');
      const receiptImgEl = document.getElementById('tpdModalReceiptImg');
      const inspectBtnEl = document.getElementById('tpdModalInspectBtn');
      const imgWrapper = document.getElementById('tpdModalImageWrapper');

      const rf = payment.receipt_filename ? String(payment.receipt_filename).trim() : '';
      if (rf && rf !== '') {
        const fileUrl = (rf.startsWith('http://') || rf.startsWith('https://'))
          ? rf
          : ('uploads/receipts/' + encodeURIComponent(rf));

        if (noReceiptEl) noReceiptEl.style.display = 'none';
        if (receiptWrapEl) receiptWrapEl.style.display = 'block';

        const isPdf = rf.toLowerCase().split('?')[0].endsWith('.pdf');
        if (inspectBtnEl) {
          inspectBtnEl.href = fileUrl;
          inspectBtnEl.innerHTML = isPdf
            ? `${window.rcIcon ? window.rcIcon('file-text', 'w-4 h-4') : ''} Open PDF Receipt in New Tab`
            : `${window.rcIcon ? window.rcIcon('external-link', 'w-4 h-4') : ''} View Full Image in New Tab`;
        }

        if (isPdf) {
          if (imgWrapper) imgWrapper.style.display = 'none';
          if (receiptImgEl) receiptImgEl.src = '';
        } else {
          if (imgWrapper) imgWrapper.style.display = 'flex';
          if (receiptImgEl) receiptImgEl.src = fileUrl;
        }
      } else {
        if (noReceiptEl) noReceiptEl.style.display = 'block';
        if (receiptWrapEl) receiptWrapEl.style.display = 'none';
        if (receiptImgEl) receiptImgEl.src = '';
      }

      // 6. Admin / Owner Remarks & Verification Timestamp
      const remarksTextEl = document.getElementById('tpdModalRemarksText');
      const verifiedMetaEl = document.getElementById('tpdModalVerifiedMeta');

      const rawRemarks = payment.verification_notes ? String(payment.verification_notes).trim() : '';
      if (remarksTextEl) {
        if (rawRemarks) {
          remarksTextEl.textContent = rawRemarks;
          remarksTextEl.style.fontStyle = 'normal';
          remarksTextEl.style.color = '#3E2723';
        } else {
          remarksTextEl.textContent = 'No remarks provided yet.';
          remarksTextEl.style.fontStyle = 'italic';
          remarksTextEl.style.color = '#7A6245';
        }
      }

      if (verifiedMetaEl) {
        verifiedMetaEl.textContent = payment.verified_at ? ('Verified on ' + payment.verified_at) : '';
      }

      // 7. Open modal via controller
      openModal('tenantPaymentDetailsModal');
    }

    function closeTenantPaymentDetailsModal(fromCloseModal = false) {
      const modal = document.getElementById('tenantPaymentDetailsModal');
      if (modal) {
        modal.classList.add('hidden');
        modal.classList.remove('open', 'active');
        modal.style.display = 'none';
        modal.style.pointerEvents = 'none';
        document.body.style.overflow = '';
      }
      if (!fromCloseModal && typeof closeModal === 'function') {
        closeModal('tenantPaymentDetailsModal');
      }
    }
    window.openTenantPaymentDetailsModal = openTenantPaymentDetailsModal;
    window.closeTenantPaymentDetailsModal = closeTenantPaymentDetailsModal;

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
      if (window.lucide && typeof window.lucide.createIcons === 'function') {
        window.lucide.createIcons();
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
        openModal('verificationActionPromptModal');
      } else {
        showToast(VERIFICATION_ACTION_REQUIRED_MSG, 'warning', 6000);
        openVerifyAccountModal();
      }
    }

    function closeVerificationActionPrompt(fromCloseModal = false) {
      const modal = document.getElementById('verificationActionPromptModal');
      if (modal) {
        modal.classList.add('hidden');
        modal.classList.remove('open', 'active');
        modal.style.display = 'none';
        modal.style.pointerEvents = 'none';
        document.body.style.overflow = '';
      }
      if (!fromCloseModal && typeof closeModal === 'function') {
        closeModal('verificationActionPromptModal');
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
        const openBackdrops = document.querySelectorAll('.modal-backdrop.open, .modal-backdrop:not(.hidden):not([style*="display: none"]), .clay-modal.open, .clay-modal:not(.hidden):not([style*="display: none"])');
        if (openBackdrops.length > 0) {
          const topModal = openBackdrops[openBackdrops.length - 1];
          if (topModal && topModal.id && typeof window.closeModal === 'function') {
            window.closeModal(topModal.id);
          }
        }
      }
    });

    function openTenantIdModal() {
      const m = document.getElementById('modalTenantProfileId');
      if (m) {
        m.classList.remove('hidden');
        m.classList.add('open', 'active');
        m.style.display = 'flex';
        m.style.pointerEvents = 'auto';
      }
      document.body.style.overflow = 'hidden';
      if (typeof openModal === 'function') {
        openModal('modalTenantProfileId');
      }
      if (window.lucide && typeof window.lucide.createIcons === 'function') {
        window.lucide.createIcons();
      }
    }

    function closeTenantIdModal(fromCloseModal = false) {
      const m = document.getElementById('modalTenantProfileId');
      if (m) {
        m.classList.add('hidden');
        m.classList.remove('open', 'active');
        m.style.display = 'none';
        m.style.pointerEvents = 'none';
      }
      document.body.style.overflow = '';
      if (!fromCloseModal && typeof closeModal === 'function') {
        closeModal('modalTenantProfileId');
      }
    }

    // =========================================================================
    // PROFILE PICTURE ADJUSTMENT & CIRCULAR CROPPING ENGINE (TENANT)
    // =========================================================================
    let cropOriginalImg = null;
    let cropScale = 1.0;
    let cropBaseScale = 1.0;
    let cropOffsetX = 0;
    let cropOffsetY = 0;
    const CROP_CANVAS_SIZE = 320;
    const CROP_RADIUS = 115; // 230px diameter crop circle inside 320x320 canvas
    let isCropDragging = false;
    let cropDragStartX = 0;
    let cropDragStartY = 0;
    let cropListenersInitialized = false;

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

      openProfileAdjustmentModal(file);
    }

    // Aliases for compatibility
    function handleProfilePictureSelect(e) { previewProfilePicture(e); }
    function handleProfilePictureSelected(e) { previewProfilePicture(e); }

    function triggerProfilePictureInput() {
      const input = document.getElementById('profile_picture') || document.getElementById('profilePictureInput') || document.getElementById('profileAvatarInput');
      if (input) {
        input.value = ''; // Reset input to allow selecting the same file again
        input.click();
      }
    }

    function openProfileAdjustmentModal(file) {
      const reader = new FileReader();
      reader.onload = function(evt) {
        const img = new Image();
        img.onload = function() {
          cropOriginalImg = img;
          cropScale = 1.0;
          cropOffsetX = 0;
          cropOffsetY = 0;

          // Calculate base scale so image at 1.0x covers the circular cutout
          const minDim = Math.min(img.naturalWidth, img.naturalHeight);
          cropBaseScale = (2 * CROP_RADIUS) / Math.max(1, minDim);

          const slider = document.getElementById('cropZoomSlider');
          if (slider) slider.value = '1.0';
          const label = document.getElementById('cropZoomLabel');
          if (label) label.textContent = '100%';

          openModal('profileAdjustmentModal');

          initCropCanvasInteractions();
          renderCropCanvas();
        };
        img.onerror = function() {
          showToast('Failed to load image for adjustment.', 'error');
        };
        img.src = evt.target.result;
      };
      reader.readAsDataURL(file);
    }

    function closeProfileAdjustmentModal(fromCloseModal = false) {
      const input = document.getElementById('profile_picture') || document.getElementById('profilePictureInput') || document.getElementById('profileAvatarInput');
      if (input) input.value = '';
      cropOriginalImg = null;

      const modal = document.getElementById('profileAdjustmentModal');
      if (modal) {
        modal.classList.add('hidden');
        modal.classList.remove('open', 'active');
        modal.style.display = 'none';
        modal.style.pointerEvents = 'none';
        document.body.style.overflow = '';
      }
      if (!fromCloseModal && typeof closeModal === 'function') {
        closeModal('profileAdjustmentModal');
      }
    }

    function initCropCanvasInteractions() {
      const canvas = document.getElementById('cropCanvas');
      if (!canvas || cropListenersInitialized) return;
      cropListenersInitialized = true;

      // Pointer events for dragging / moving (supports Mouse and Touch seamlessly)
      canvas.addEventListener('pointerdown', (e) => {
        if (!cropOriginalImg) return;
        isCropDragging = true;
        canvas.style.cursor = 'grabbing';
        try { canvas.setPointerCapture(e.pointerId); } catch (_) {}

        const rect = canvas.getBoundingClientRect();
        const factor = CROP_CANVAS_SIZE / rect.width;
        cropDragStartX = (e.clientX - rect.left) * factor - cropOffsetX;
        cropDragStartY = (e.clientY - rect.top) * factor - cropOffsetY;
      });

      canvas.addEventListener('pointermove', (e) => {
        if (!isCropDragging || !cropOriginalImg) return;
        const rect = canvas.getBoundingClientRect();
        const factor = CROP_CANVAS_SIZE / rect.width;
        const curX = (e.clientX - rect.left) * factor;
        const curY = (e.clientY - rect.top) * factor;

        cropOffsetX = curX - cropDragStartX;
        cropOffsetY = curY - cropDragStartY;

        renderCropCanvas();
      });

      const stopDrag = (e) => {
        if (!isCropDragging) return;
        isCropDragging = false;
        canvas.style.cursor = 'grab';
        try { canvas.releasePointerCapture(e.pointerId); } catch (_) {}
      };

      canvas.addEventListener('pointerup', stopDrag);
      canvas.addEventListener('pointercancel', stopDrag);

      // Mouse wheel zoom
      canvas.addEventListener('wheel', (e) => {
        if (!cropOriginalImg) return;
        e.preventDefault();
        const delta = e.deltaY < 0 ? 0.08 : -0.08;
        stepCropZoom(delta);
      }, { passive: false });
    }

    function renderCropCanvas() {
      const canvas = document.getElementById('cropCanvas');
      if (!canvas || !cropOriginalImg) return;
      const ctx = canvas.getContext('2d');
      if (!ctx) return;

      const W = CROP_CANVAS_SIZE;
      const H = CROP_CANVAS_SIZE;
      const cx = W / 2;
      const cy = H / 2;

      ctx.clearRect(0, 0, W, H);

      // Draw dark background
      ctx.fillStyle = '#140E08';
      ctx.fillRect(0, 0, W, H);

      // Scaled dimensions
      const curScale = cropBaseScale * cropScale;
      const dispW = cropOriginalImg.naturalWidth * curScale;
      const dispH = cropOriginalImg.naturalHeight * curScale;

      // Bound clamping: ensure image always fills the circular crop window
      const maxOffX = Math.max(0, (dispW / 2) - CROP_RADIUS);
      const maxOffY = Math.max(0, (dispH / 2) - CROP_RADIUS);
      cropOffsetX = Math.max(-maxOffX, Math.min(maxOffX, cropOffsetX));
      cropOffsetY = Math.max(-maxOffY, Math.min(maxOffY, cropOffsetY));

      const drawX = cx + cropOffsetX - (dispW / 2);
      const drawY = cy + cropOffsetY - (dispH / 2);

      // Draw original image
      ctx.drawImage(cropOriginalImg, drawX, drawY, dispW, dispH);

      // Draw dark semi-transparent circular mask overlay
      ctx.save();
      ctx.fillStyle = 'rgba(20, 14, 8, 0.65)';
      ctx.beginPath();
      // Outer box
      ctx.rect(0, 0, W, H);
      // Cutout circle counter-clockwise
      ctx.arc(cx, cy, CROP_RADIUS, 0, Math.PI * 2, true);
      ctx.fill();

      // Golden circular guide border
      ctx.strokeStyle = '#D4AF37';
      ctx.lineWidth = 2.5;
      ctx.beginPath();
      ctx.arc(cx, cy, CROP_RADIUS, 0, Math.PI * 2);
      ctx.stroke();

      // Subtle dashed inner ring for alignment
      ctx.strokeStyle = 'rgba(255, 255, 255, 0.3)';
      ctx.lineWidth = 1;
      ctx.setLineDash([4, 4]);
      ctx.beginPath();
      ctx.arc(cx, cy, CROP_RADIUS - 6, 0, Math.PI * 2);
      ctx.stroke();
      ctx.restore();

      // Update Live Circular Preview
      updateCropPreview(drawX, drawY, curScale);
    }

    function updateCropPreview(drawX, drawY, curScale) {
      const prevCanvas = document.getElementById('cropPreviewCanvas');
      if (!prevCanvas || !cropOriginalImg) return;
      const prevCtx = prevCanvas.getContext('2d');
      if (!prevCtx) return;

      const pSize = prevCanvas.width;
      prevCtx.clearRect(0, 0, pSize, pSize);

      const cx = CROP_CANVAS_SIZE / 2;
      const cy = CROP_CANVAS_SIZE / 2;
      const cropLeft = cx - CROP_RADIUS;
      const cropTop = cy - CROP_RADIUS;
      const cropSize = 2 * CROP_RADIUS;

      const sx = (cropLeft - drawX) / curScale;
      const sy = (cropTop - drawY) / curScale;
      const sw = cropSize / curScale;
      const sh = cropSize / curScale;

      prevCtx.drawImage(cropOriginalImg, sx, sy, sw, sh, 0, 0, pSize, pSize);
    }

    function handleCropZoomSlider(val) {
      cropScale = Math.max(1.0, Math.min(3.0, parseFloat(val) || 1.0));
      const label = document.getElementById('cropZoomLabel');
      if (label) label.textContent = Math.round(cropScale * 100) + '%';
      renderCropCanvas();
    }

    function stepCropZoom(delta) {
      cropScale = Math.max(1.0, Math.min(3.0, cropScale + delta));
      const slider = document.getElementById('cropZoomSlider');
      if (slider) slider.value = cropScale.toFixed(2);
      const label = document.getElementById('cropZoomLabel');
      if (label) label.textContent = Math.round(cropScale * 100) + '%';
      renderCropCanvas();
    }

    function resetCropAdjustment() {
      cropScale = 1.0;
      cropOffsetX = 0;
      cropOffsetY = 0;
      const slider = document.getElementById('cropZoomSlider');
      if (slider) slider.value = '1.0';
      const label = document.getElementById('cropZoomLabel');
      if (label) label.textContent = '100%';
      renderCropCanvas();
    }

    async function saveCroppedProfilePicture() {
      // Prevent saving if no new profile picture has been selected or loaded
      if (!cropOriginalImg) {
        showToast('Please select a profile picture first.', 'warning');
        return;
      }

      const saveBtn = document.getElementById('btnSaveCroppedAvatar');
      const origHtml = saveBtn ? saveBtn.innerHTML : '';
      if (saveBtn) {
        saveBtn.disabled = true;
        saveBtn.innerHTML = '<span class="clay-spinner" style="width: 15px; height: 15px; border-width: 2px; margin-right: 6px; vertical-align: middle;"></span>Saving...';
      }

      try {
        const OUT_SIZE = 512;
        const outCanvas = document.createElement('canvas');
        outCanvas.width = OUT_SIZE;
        outCanvas.height = OUT_SIZE;
        const outCtx = outCanvas.getContext('2d');

        const cx = CROP_CANVAS_SIZE / 2;
        const cy = CROP_CANVAS_SIZE / 2;
        const curScale = cropBaseScale * cropScale;
        const dispW = cropOriginalImg.naturalWidth * curScale;
        const dispH = cropOriginalImg.naturalHeight * curScale;
        const drawX = cx + cropOffsetX - (dispW / 2);
        const drawY = cy + cropOffsetY - (dispH / 2);

        const cropLeft = cx - CROP_RADIUS;
        const cropTop = cy - CROP_RADIUS;
        const cropSize = 2 * CROP_RADIUS;

        const sx = (cropLeft - drawX) / curScale;
        const sy = (cropTop - drawY) / curScale;
        const sw = cropSize / curScale;
        const sh = cropSize / curScale;

        outCtx.drawImage(cropOriginalImg, sx, sy, sw, sh, 0, 0, OUT_SIZE, OUT_SIZE);

        let blob = await new Promise((resolve) => outCanvas.toBlob(resolve, 'image/jpeg', 0.92));
        if (!blob) {
          // Fallback via dataURL if toBlob fails
          const dataUrl = outCanvas.toDataURL('image/jpeg', 0.92);
          const parts = dataUrl.split(',');
          const byteString = atob(parts[1]);
          const mimeString = parts[0].split(':')[1].split(';')[0];
          const ab = new ArrayBuffer(byteString.length);
          const ia = new Uint8Array(ab);
          for (let i = 0; i < byteString.length; i++) {
            ia[i] = byteString.charCodeAt(i);
          }
          blob = new Blob([ab], { type: mimeString });
        }

        if (!blob) throw new Error('Could not process profile image.');

        const formData = new FormData();
        formData.append('action', 'upload_profile_picture');
        formData.append('profile_picture', blob, 'avatar.jpg');
        formData.append('is_ajax', '1');

        const res = await fetch('api/profile_api.php', {
          method: 'POST',
          headers: {
            'X-Requested-With': 'XMLHttpRequest',
            'Accept': 'application/json'
          },
          body: formData
        });

        let data = null;
        try {
          data = await res.json();
        } catch (jsonErr) {
          console.error('Non-JSON response from profile API:', jsonErr);
        }

        if (res.ok && data && data.status === 'success' && data.profile_picture) {
          currentSavedAvatarUrl = data.profile_picture;
          const stamp = Date.now();
          const avatarUrlWithCache = data.profile_picture + '?t=' + stamp;

          // 1. Display updated profile picture in settings immediately without page refresh
          const previewImg = document.getElementById('avatarPreviewImg');
          if (previewImg) {
            previewImg.src = avatarUrlWithCache;
            previewImg.style.display = 'block';
          }
          const fallbackInit = document.getElementById('avatarFallbackInitial');
          if (fallbackInit) {
            fallbackInit.style.setProperty('display', 'none', 'important');
            fallbackInit.textContent = '';
          }

          // 2. Display updated profile picture in header avatar badge immediately
          const headerBadge = document.getElementById('headerAvatarBadge');
          if (headerBadge) {
            headerBadge.innerHTML = `<img src="${avatarUrlWithCache}" alt="Profile" class="header-avatar-img">`;
          }

          // 3. Display updated profile picture in profile dropdown menu immediately
          const dropCircles = document.querySelectorAll('#profileDropdownAvatarCircle, .fb-profile-avatar-circle');
          dropCircles.forEach(circle => {
            circle.innerHTML = `<img src="${avatarUrlWithCache}" alt="Profile" id="profileDropdownAvatarImg">`;
          });

          // Close modal and surface success notification
          closeProfileAdjustmentModal();
          showToast('Profile picture saved successfully!', 'success');
        } else {
          // Preserve existing profile picture unchanged on save failure
          const errorMsg = (data && data.message) ? data.message : 'Failed to save profile picture. Please try again.';
          showToast(errorMsg, 'error');
        }
      } catch (err) {
        // Preserve existing profile picture unchanged on network/runtime error
        showToast(err.message || 'Network error saving profile picture. Please try again.', 'error');
      } finally {
        if (saveBtn) {
          saveBtn.disabled = false;
          saveBtn.innerHTML = origHtml;
          if (window.lucide && typeof window.lucide.createIcons === 'function') window.lucide.createIcons();
        }
      }
    }
    window.saveCroppedProfilePicture = saveCroppedProfilePicture;

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
      openModal('editAccountInfoModal');
    }

    function closeEditAccountInfoModal(fromCloseModal = false) {
      const modal = document.getElementById('editAccountInfoModal');
      if (modal) {
        modal.classList.add('hidden');
        modal.classList.remove('open', 'active');
        modal.style.display = 'none';
        modal.style.pointerEvents = 'none';
        document.body.style.overflow = '';
      }
      if (!fromCloseModal && typeof closeModal === 'function') {
        closeModal('editAccountInfoModal');
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
      'description' => (string)($t['description'] ?? ''),
      'status' => (string)($t['status'] ?? ''),
      'created_at' => (string)($t['created_at'] ?? ''),
      'raw' => $t,
    ], $myTickets), JSON_UNESCAPED_UNICODE) ?>;

    let __tenantSearchReports = <?= json_encode(array_map(fn($r) => [
      'id' => (int)($r['id'] ?? 0),
      'subject' => (string)($r['subject'] ?? ''),
      'category' => (string)($r['category'] ?? ''),
      'details' => (string)($r['details'] ?? ''),
      'status' => (string)($r['status'] ?? ''),
      'created_at' => (string)($r['created_at'] ?? ''),
      'raw' => $r,
    ], $myReports), JSON_UNESCAPED_UNICODE) ?>;

    const __tenantSearchPayments = <?= json_encode(array_map(fn($p) => [
      'id' => (int)($p['id'] ?? 0),
      'billing_cycle' => (string)($p['billing_cycle'] ?? ''),
      'status' => (string)($p['status'] ?? ''),
      'amount_due' => (float)($p['amount_due'] ?? 0),
      'amount_paid' => (float)($p['amount_paid'] ?? 0),
      'reference_number' => (string)($p['reference_number'] ?? ''),
      'payment_type' => (string)($p['payment_type'] ?? 'Rent'),
      'payment_date' => (string)($p['payment_date'] ?? ''),
      'raw' => $p,
    ], $allTenantPayments), JSON_UNESCAPED_UNICODE) ?>;

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

    let activeTenantSearchResults = [];

    function initTenantGlobalSearch() {
      const input = document.getElementById('tenantGlobalSearchInput');
      const clearBtn = document.getElementById('tenantGlobalSearchClear');
      const dropdown = document.getElementById('tenantSearchDropdown');
      if (!input || !dropdown) return;

      const staticSections = [
        { title: 'Dashboard Overview', sub: 'Current rent status, active maintenance & receipts', tag: 'Section', section: 'tenantOverview', navId: 'navTenantOverview', keys: 'home dashboard overview rent residency summary' },
        { title: 'Log Maintenance', sub: 'Report repair requests & track work orders', tag: 'Section', section: 'tenantMaintenance', navId: 'navTenantMaintenance', keys: 'maintenance tickets repairs issues plumber electrician' },
        { title: 'Upload Payment', sub: 'Submit bank or GCash rent receipts', tag: 'Section', section: 'tenantPayments', navId: 'navTenantPayments', keys: 'payment upload receipts gcash bank transfer ledger due' },
        { title: 'Submit General Report', sub: 'Occupants, pets, unit modifications & inquiries', tag: 'Section', section: 'tenantReport', navId: 'navTenantReport', keys: 'report inquiry occupants pets customization concern' },
        { title: 'Account Information', sub: 'Profile records & identity verification', tag: 'Settings', profileTab: 'accountInfo', keys: 'profile account info verification id liveness' },
        { title: 'Change Password', sub: 'Update your resident login credentials', tag: 'Settings', profileTab: 'changePassword', keys: 'password security login credentials' },
      ];

      function renderResults(rawQuery) {
        const q = (rawQuery || '').trim().toLowerCase();
        if (clearBtn) clearBtn.classList.toggle('visible', q.length > 0);
        if (!q) {
          activeTenantSearchResults = [];
          dropdown.innerHTML = '';
          dropdown.classList.remove('open');
          return;
        }

        const tokens = q.split(/\s+/).filter(Boolean);
        function matchesTokens(haystack) {
          const h = String(haystack || '').toLowerCase();
          return tokens.every(t => h.includes(t));
        }

        const results = [];

        staticSections.forEach((sec) => {
          const hay = `${sec.title} ${sec.sub} ${sec.keys}`.toLowerCase();
          if (matchesTokens(hay)) {
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
          const hay = `${ticketCode} MT-${tk.id} ${tk.id} ${tk.title} ${tk.category} ${tk.description} ${tk.status}`.toLowerCase();
          if (matchesTokens(hay)) {
            results.push({
              title: `${ticketCode} · ${tk.title}`,
              sub: `${tk.category || 'Maintenance'} • ${tk.status} • ${tk.created_at ? tk.created_at.split(' ')[0] : ''}`,
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
          const hay = `${reportCode} GR-${rep.id} ${rep.id} ${rep.subject} ${rep.category} ${rep.details} ${rep.status}`.toLowerCase();
          if (matchesTokens(hay)) {
            results.push({
              title: `${reportCode} · ${rep.subject}`,
              sub: `${rep.category || 'General Report'} • ${rep.status} • ${rep.created_at ? rep.created_at.split(' ')[0] : ''}`,
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
          const pType = pay.payment_type || 'Rent';
          const statusLabel = String(pay.status || '').toUpperCase();
          const ref = pay.reference_number || '';
          const amt = Number(pay.amount_paid || pay.amount_due || 0).toFixed(2);
          const hay = `${pType} ${cycleLabel} ${ref} ${statusLabel} ${amt} payment receipt`.toLowerCase();
          if (matchesTokens(hay)) {
            results.push({
              title: `${pType} · ${cycleLabel}`,
              sub: pay.status === 'pending_setup' ? 'Rent setup pending' : `₱${amt} • ${statusLabel}${ref ? ' • Ref: ' + ref : ''}`,
              tag: 'Payment',
              onSelect: () => {
                switchTenantSection('tenantPayments', document.getElementById('navTenantPayments'));
                if (typeof switchPaymentTab === 'function') switchPaymentTab('tracker');
                if (typeof openTenantPaymentDetailsModal === 'function' && pay.raw) {
                  const raw = pay.raw;
                  const cycleText = raw.billing_cycle ? new Date(raw.billing_cycle.includes('T') ? raw.billing_cycle : raw.billing_cycle + 'T00:00:00').toLocaleDateString('en-US', { month: 'long', year: 'numeric' }) : 'Current';
                  const dispAmt = Number(raw.amount_paid > 0 ? raw.amount_paid : (raw.amount_due || 3000));
                  openTenantPaymentDetailsModal({
                    id: raw.id,
                    purpose: cycleText + ' ' + (raw.payment_type || 'Rent'),
                    payment_type: raw.payment_type || 'Rent',
                    billing_cycle: raw.billing_cycle || '',
                    billing_cycle_fmt: cycleText,
                    amount_paid: Number(raw.amount_paid || 0),
                    amount_due: Number(raw.amount_due || 0),
                    display_amount: dispAmt,
                    due_date: raw.due_date ? new Date(raw.due_date.includes('T') ? raw.due_date : raw.due_date + 'T00:00:00').toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' }) : 'N/A',
                    payment_date: raw.payment_date || raw.created_at || '-',
                    ref_number: ref || ('PAY-' + String(raw.id || 0).padStart(5, '0')),
                    status: raw.status || 'pending',
                    receipt_filename: raw.receipt_filename || '',
                    verification_notes: raw.verification_notes || '',
                    verified_at: raw.verified_at || '',
                    payment_method: raw.payment_method || 'GCash / Bank Transfer'
                  });
                }
              },
            });
          }
        });

        activeTenantSearchResults = results;
        dropdown.innerHTML = '';
        if (results.length === 0) {
          const emptyEl = document.createElement('div');
          emptyEl.style.padding = '14px 16px';
          emptyEl.style.fontSize = '12.5px';
          emptyEl.style.color = '#5C4324';
          emptyEl.style.textAlign = 'center';
          emptyEl.innerHTML = `No matching tickets, reports, or payments found for "<strong>${escapeTenantSearchHtml(rawQuery)}</strong>".`;
          dropdown.appendChild(emptyEl);
        } else {
          results.slice(0, 10).forEach((item) => {
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
      input.addEventListener('keydown', (e) => {
        if (e.key === 'Escape') {
          clearGlobalTenantSearch();
          input.blur();
        } else if (e.key === 'Enter' && activeTenantSearchResults.length > 0) {
          e.preventDefault();
          activeTenantSearchResults[0].onSelect();
          clearGlobalTenantSearch();
          input.blur();
        }
      });
    }

    function setupSidebarAndSettingsListeners() {
      try {
        if (typeof window.initSharedSidebar === 'function') {
          window.initSharedSidebar();
        }

        initTenantGlobalSearch();

        if (window.lucide && typeof window.lucide.createIcons === 'function') {
          window.lucide.createIcons();
        }

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
            // Convert to grayscale and apply gentle, continuous contrast enhancement.
            // Avoid destructive static 1-bit thresholding (cVal >= 135) which destroys camera photos under varied ambient lighting.
            // Preserving tonal gradients allows Tesseract's adaptive block binarizer (Leptonica) to properly segment characters.
            const contrast = 30;
            const factor = (259 * (contrast + 255)) / (255 * (259 - contrast));

            for (let i = 0; i < d.length; i += 4) {
              // 1. Grayscale luminance conversion: 0.299R + 0.587G + 0.114B
              const gray = 0.299 * d[i] + 0.587 * d[i + 1] + 0.114 * d[i + 2];
              // 2. Contrast enhancement preserving gradient detail
              const cVal = Math.min(255, Math.max(0, factor * (gray - 128) + 128));

              d[i] = cVal;     // R
              d[i + 1] = cVal; // G
              d[i + 2] = cVal; // B
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

    // =========================================================================
    // PHYSICAL ID CAMERA STREAMING & PHOTO CAPTURE CONTROLLER (FRONT & BACK)
    // Allows tenants to take a clear photo of their physical ID (Front & Back)
    // using device camera, preview, retake, and automatically attach.
    // =========================================================================
    let idCameraStream = null;
    let currentCameraSide = 'front';
    let selectedFrontIdFile = null;
    let selectedBackIdFile = null;

    async function openIdCaptureCamera(side = 'front') {
      if (typeof side !== 'string' || (side !== 'front' && side !== 'back')) {
        side = 'front';
      }
      currentCameraSide = side;
      const cap = side === 'back' ? 'Back' : 'Front';

      const alertBox = document.getElementById('verifyModalAlert');
      if (alertBox) {
        alertBox.style.display = 'none';
        alertBox.innerHTML = '';
      }

      const dropZone = document.getElementById('id' + cap + 'DropZone') || document.getElementById('idDropZone');
      const previewCard = document.getElementById('id' + cap + 'ImagePreviewCard') || document.getElementById('idImagePreviewCard');
      const cameraContainer = document.getElementById('id' + cap + 'CameraCaptureContainer') || document.getElementById('idCameraCaptureContainer');
      const videoEl = document.getElementById('id' + cap + 'CameraVideo') || document.getElementById('idCameraVideo') || document.getElementById('camera_feed');
      const previewImg = document.getElementById('id' + cap + 'CameraCapturedPreview') || document.getElementById('idCameraCapturedPreview');
      const cardGuide = document.getElementById('id' + cap + 'CameraCardGuide') || document.getElementById('idCameraCardGuide');
      const liveControls = document.getElementById('id' + cap + 'CameraLiveControls') || document.getElementById('idCameraLiveControls');
      const reviewControls = document.getElementById('id' + cap + 'CameraReviewControls') || document.getElementById('idCameraReviewControls');

      if (previewCard) previewCard.style.setProperty('display', 'none', 'important');
      if (dropZone) dropZone.style.setProperty('display', 'none', 'important');

      if (cameraContainer) {
        cameraContainer.classList.remove('hidden');
        cameraContainer.style.setProperty('display', 'flex', 'important');
      }
      if (videoEl) {
        videoEl.style.display = 'block';
      }
      if (previewImg) {
        previewImg.src = '';
        previewImg.style.display = 'none';
      }
      if (cardGuide) cardGuide.style.display = 'flex';
      if (liveControls) liveControls.style.display = 'flex';
      if (reviewControls) reviewControls.style.display = 'none';

      stopIdCamera();

      try {
        // Prioritize rear/environment camera for mobile ID framing
        idCameraStream = await navigator.mediaDevices.getUserMedia({
          video: {
            facingMode: { ideal: 'environment' },
            width: { ideal: 1920, min: 640 },
            height: { ideal: 1080, min: 480 }
          },
          audio: false
        });
      } catch (err1) {
        console.warn('Rear camera not available, attempting fallback to default camera:', err1);
        try {
          // Desktop webcam fallback
          idCameraStream = await navigator.mediaDevices.getUserMedia({
            video: true,
            audio: false
          });
        } catch (err2) {
          console.error('Camera access failed:', err2);
          let userMsg = 'Unable to access device camera. Please check camera permissions or upload an image file instead.';
          if (err2.name === 'NotAllowedError' || err2.name === 'PermissionDeniedError') {
            userMsg = 'Camera permission denied. Please allow camera access in your browser settings to capture your physical ID.';
          } else if (err2.name === 'NotFoundError' || err2.name === 'DevicesNotFoundError') {
            userMsg = 'No camera found on your device. Please use the upload option instead.';
          } else if (err2.name === 'NotReadableError' || err2.name === 'TrackStartError') {
            userMsg = 'Camera is currently in use by another application. Please close other camera apps and retry.';
          }

          showToast(userMsg, 'error', 5000);
          if (alertBox) {
            alertBox.style.display = 'block';
            alertBox.innerHTML = `<span style="display:flex;align-items:center;gap:8px;">${window.rcIcon ? window.rcIcon('alert-triangle', 'w-4 h-4 text-red-600') : ''}<span><strong>Camera Error:</strong> ${escapeHtml(userMsg)}</span></span>`;
          }
          closeIdCaptureCamera(side);
          return;
        }
      }

      if (videoEl && idCameraStream) {
        videoEl.srcObject = idCameraStream;
        try {
          await videoEl.play();
        } catch (playErr) {
          console.warn('Camera video play error:', playErr);
        }
      }
      if (window.lucide && typeof window.lucide.createIcons === 'function') {
        window.lucide.createIcons();
      }
    }

    function captureIdPhotoFromCamera(side) {
      if (!side || typeof side !== 'string') side = currentCameraSide || 'front';
      const cap = side === 'back' ? 'Back' : 'Front';

      const videoEl = document.getElementById('id' + cap + 'CameraVideo') || document.getElementById('idCameraVideo') || document.getElementById('camera_feed');
      const canvasEl = document.getElementById('id' + cap + 'CameraCanvas') || document.getElementById('idCameraCanvas') || document.getElementById('snapshot');
      const previewImg = document.getElementById('id' + cap + 'CameraCapturedPreview') || document.getElementById('idCameraCapturedPreview');
      const cardGuide = document.getElementById('id' + cap + 'CameraCardGuide') || document.getElementById('idCameraCardGuide');
      const liveControls = document.getElementById('id' + cap + 'CameraLiveControls') || document.getElementById('idCameraLiveControls');
      const reviewControls = document.getElementById('id' + cap + 'CameraReviewControls') || document.getElementById('idCameraReviewControls');

      if (!videoEl || !canvasEl) return;

      const w = videoEl.videoWidth || 1280;
      const h = videoEl.videoHeight || 720;
      canvasEl.width = w;
      canvasEl.height = h;

      const ctx = canvasEl.getContext('2d');
      ctx.drawImage(videoEl, 0, 0, w, h);

      const dataUrl = canvasEl.toDataURL('image/jpeg', 0.95);
      if (previewImg) {
        previewImg.src = dataUrl;
        previewImg.style.display = 'block';
      }
      if (videoEl) {
        videoEl.style.display = 'none';
        try { videoEl.pause(); } catch (e) {}
      }
      if (cardGuide) cardGuide.style.display = 'none';
      if (liveControls) liveControls.style.display = 'none';
      if (reviewControls) reviewControls.style.display = 'flex';
      if (window.lucide && typeof window.lucide.createIcons === 'function') {
        window.lucide.createIcons();
      }
    }

    function retakeIdPhotoCamera(side) {
      if (!side || typeof side !== 'string') side = currentCameraSide || 'front';
      const cap = side === 'back' ? 'Back' : 'Front';

      const videoEl = document.getElementById('id' + cap + 'CameraVideo') || document.getElementById('idCameraVideo') || document.getElementById('camera_feed');
      const previewImg = document.getElementById('id' + cap + 'CameraCapturedPreview') || document.getElementById('idCameraCapturedPreview');
      const cardGuide = document.getElementById('id' + cap + 'CameraCardGuide') || document.getElementById('idCameraCardGuide');
      const liveControls = document.getElementById('id' + cap + 'CameraLiveControls') || document.getElementById('idCameraLiveControls');
      const reviewControls = document.getElementById('id' + cap + 'CameraReviewControls') || document.getElementById('idCameraReviewControls');

      if (previewImg) {
        previewImg.src = '';
        previewImg.style.display = 'none';
      }
      if (videoEl) {
        videoEl.style.display = 'block';
        try { videoEl.play(); } catch (e) {}
      }
      if (cardGuide) cardGuide.style.display = 'flex';
      if (liveControls) liveControls.style.display = 'flex';
      if (reviewControls) reviewControls.style.display = 'none';
      if (window.lucide && typeof window.lucide.createIcons === 'function') {
        window.lucide.createIcons();
      }
    }

    function confirmUseCapturedIdPhoto(side) {
      if (!side || typeof side !== 'string') side = currentCameraSide || 'front';
      const cap = side === 'back' ? 'Back' : 'Front';

      const canvasEl = document.getElementById('id' + cap + 'CameraCanvas') || document.getElementById('idCameraCanvas') || document.getElementById('snapshot');
      if (!canvasEl) return;

      canvasEl.toBlob((blob) => {
        if (!blob) {
          showToast('Failed to generate image from camera capture.', 'error');
          return;
        }

        const timestamp = Date.now();
        const capturedFile = new File([blob], `id_${side}_capture_${timestamp}.jpg`, { type: 'image/jpeg' });
        const dataUrl = canvasEl.toDataURL('image/jpeg', 0.95);

        // Populate hidden base64 input
        const hiddenBase64 = document.getElementById('captured_' + side + '_id_base64');
        if (hiddenBase64) {
          try { hiddenBase64.value = dataUrl; } catch (e) {}
        }
        if (side === 'front') {
          const legB64 = document.getElementById('captured_id_base64');
          if (legB64) {
            try { legB64.value = dataUrl; } catch (e) {}
          }
        }

        // Place captured file into corresponding file input via DataTransfer
        const fileInput = document.getElementById('id' + cap + 'FileInput') || document.getElementById('idFileInput');
        if (fileInput) {
          try {
            const dt = new DataTransfer();
            dt.items.add(capturedFile);
            fileInput.files = dt.files;
          } catch (e) {
            console.warn('DataTransfer notice:', e);
          }
        }
        if (side === 'front') {
          const legInput = document.getElementById('idFileInput');
          if (legInput && legInput !== fileInput) {
            try {
              const dt2 = new DataTransfer();
              dt2.items.add(capturedFile);
              legInput.files = dt2.files;
            } catch (e) {}
          }
        }

        // Stop camera stream & hide camera interface
        stopIdCamera();
        const cameraContainer = document.getElementById('id' + cap + 'CameraCaptureContainer') || document.getElementById('idCameraCaptureContainer');
        if (cameraContainer) {
          cameraContainer.classList.add('hidden');
          cameraContainer.style.setProperty('display', 'none', 'important');
        }

        // Automatically pass into ID attachment handler
        handleIdFile(capturedFile, side);

        showToast(`Photo of ${side === 'front' ? 'Front' : 'Back'} ID captured and attached!`, 'success');
      }, 'image/jpeg', 0.95);
    }

    function closeIdCaptureCamera(side) {
      if (!side || typeof side !== 'string') side = currentCameraSide || 'front';
      const cap = side === 'back' ? 'Back' : 'Front';

      stopIdCamera();

      const cameraContainer = document.getElementById('id' + cap + 'CameraCaptureContainer') || document.getElementById('idCameraCaptureContainer');
      if (cameraContainer) {
        cameraContainer.classList.add('hidden');
        cameraContainer.style.setProperty('display', 'none', 'important');
      }

      const previewCard = document.getElementById('id' + cap + 'ImagePreviewCard') || document.getElementById('idImagePreviewCard');
      const dropZone = document.getElementById('id' + cap + 'DropZone') || document.getElementById('idDropZone');

      const isAttached = side === 'front' ? selectedFrontIdFile : selectedBackIdFile;
      if (isAttached) {
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

    function stopIdCamera() {
      if (idCameraStream) {
        try {
          idCameraStream.getTracks().forEach(track => {
            try { track.stop(); } catch (e) {}
          });
        } catch (e) {}
        idCameraStream = null;
      }
      ['idFrontCameraVideo', 'idBackCameraVideo', 'idCameraVideo', 'camera_feed'].forEach(id => {
        const el = document.getElementById(id);
        if (el) el.srcObject = null;
      });
    }

    // Compatibility aliases
    window.openIdCaptureCamera = openIdCaptureCamera;
    window.closeIdCaptureCamera = closeIdCaptureCamera;
    window.captureIdPhotoFromCamera = captureIdPhotoFromCamera;
    window.retakeIdPhotoCamera = retakeIdPhotoCamera;
    window.confirmUseCapturedIdPhoto = confirmUseCapturedIdPhoto;
    window.stopIdCamera = stopIdCamera;
    window.startPortalCamera = openIdCaptureCamera;
    window.stopPortalCamera = stopIdCamera;
    window.capturePortalCamera = captureIdPhotoFromCamera;
    window.retakePortalCamera = retakeIdPhotoCamera;
    window.togglePortalIdMethod = function(method) {
      if (method === 'camera') openIdCaptureCamera('front');
      else closeIdCaptureCamera('front');
    };

    function openTermsModal(e) {
      if (e) {
        e.preventDefault();
        e.stopPropagation();
      }
      openModal('termsContentModal');
    }

    function closeTermsModal(fromCloseModal = false) {
      const m = document.getElementById('termsContentModal');
      if (m) {
        m.classList.add('hidden');
        m.classList.remove('open', 'active');
        m.style.display = 'none';
        m.style.pointerEvents = 'none';
        document.body.style.overflow = '';
      }
      if (!fromCloseModal && typeof closeModal === 'function') {
        closeModal('termsContentModal');
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
      openModal('privacyContentModal');
    }

    function closePrivacyModal(fromCloseModal = false) {
      const m = document.getElementById('privacyContentModal');
      if (m) {
        m.classList.add('hidden');
        m.classList.remove('open', 'active');
        m.style.display = 'none';
        m.style.pointerEvents = 'none';
        document.body.style.overflow = '';
      }
      if (!fromCloseModal && typeof closeModal === 'function') {
        closeModal('privacyContentModal');
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

      openModal('verifyAccountModal');

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

    function handleVerificationFileSelect(e, side = 'front') {
      const file = e.target.files && e.target.files[0];
      if (file) {
        handleIdFile(file, side);
      }
    }

    function removeAttachedPhoto(event, side = 'front') {
      if (event) {
        if (typeof event.stopPropagation === 'function') event.stopPropagation();
        if (typeof event.preventDefault === 'function') event.preventDefault();
        if (typeof event.stopImmediatePropagation === 'function') event.stopImmediatePropagation();
      }

      stopIdCamera();
      const cap = side === 'back' ? 'Back' : 'Front';

      if (side === 'front') {
        selectedFrontIdFile = null;
        selectedIdFile = null;
        preprocessedIdBlob = null;
        preprocessedIdDataUrl = '';
        heldOcrRawText = '';

        const hiddenBase64 = document.getElementById('captured_front_id_base64');
        if (hiddenBase64) hiddenBase64.value = '';
        const legB64 = document.getElementById('captured_id_base64');
        if (legB64) legB64.value = '';

        const fileInput = document.getElementById('idFrontFileInput');
        if (fileInput) fileInput.value = '';
        const legInput = document.getElementById('idFileInput');
        if (legInput) legInput.value = '';

        const previewImg = document.getElementById('idFrontVisualPreviewImg');
        if (previewImg) previewImg.src = '';

        const nameEl = document.getElementById('idFrontPreviewFileName');
        const sizeEl = document.getElementById('idFrontPreviewFileSize');
        if (nameEl) nameEl.textContent = '';
        if (sizeEl) sizeEl.textContent = '';

        const previewCard = document.getElementById('idFrontImagePreviewCard');
        if (previewCard) {
          previewCard.classList.add('hidden');
          previewCard.style.setProperty('display', 'none', 'important');
        }

        const cameraContainer = document.getElementById('idFrontCameraCaptureContainer');
        if (cameraContainer) {
          cameraContainer.classList.add('hidden');
          cameraContainer.style.setProperty('display', 'none', 'important');
        }

        const dropZone = document.getElementById('idFrontDropZone');
        if (dropZone) {
          dropZone.classList.remove('hidden');
          dropZone.style.setProperty('display', 'flex', 'important');
        }
      } else {
        selectedBackIdFile = null;

        const hiddenBase64 = document.getElementById('captured_back_id_base64');
        if (hiddenBase64) hiddenBase64.value = '';

        const fileInput = document.getElementById('idBackFileInput');
        if (fileInput) fileInput.value = '';

        const previewImg = document.getElementById('idBackVisualPreviewImg');
        if (previewImg) previewImg.src = '';

        const nameEl = document.getElementById('idBackPreviewFileName');
        const sizeEl = document.getElementById('idBackPreviewFileSize');
        if (nameEl) nameEl.textContent = '';
        if (sizeEl) sizeEl.textContent = '';

        const previewCard = document.getElementById('idBackImagePreviewCard');
        if (previewCard) {
          previewCard.classList.add('hidden');
          previewCard.style.setProperty('display', 'none', 'important');
        }

        const cameraContainer = document.getElementById('idBackCameraCaptureContainer');
        if (cameraContainer) {
          cameraContainer.classList.add('hidden');
          cameraContainer.style.setProperty('display', 'none', 'important');
        }

        const dropZone = document.getElementById('idBackDropZone');
        if (dropZone) {
          dropZone.classList.remove('hidden');
          dropZone.style.setProperty('display', 'flex', 'important');
        }
      }
    }
    window.removeAttachedPhoto = removeAttachedPhoto;

    function removeVerificationIdFile(event, side = 'front') {
      removeAttachedPhoto(event, side);
    }
    window.removeVerificationIdFile = removeVerificationIdFile;

    function removeIdFile(event, side = 'front') {
      removeAttachedPhoto(event, side);
    }
    window.removeIdFile = removeIdFile;

    function closeVerifyAccountModal(fromCloseModal = false) {
      cancelIdUpdateIfPending();

      stopIdCamera();
      ['idFrontCameraCaptureContainer', 'idBackCameraCaptureContainer', 'idCameraCaptureContainer'].forEach(id => {
        const el = document.getElementById(id);
        if (el) {
          el.classList.add('hidden');
          el.style.setProperty('display', 'none', 'important');
        }
      });

      const modal = document.getElementById('verifyAccountModal');
      if (modal) {
        modal.classList.add('hidden');
        modal.classList.remove('open', 'active');
        modal.style.display = 'none';
        modal.style.pointerEvents = 'none';
        document.body.style.overflow = '';
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
      if (!fromCloseModal && typeof closeModal === 'function') {
        closeModal('verifyAccountModal');
      }
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

      stopIdCamera();
      const cameraContainer = document.getElementById('idCameraCaptureContainer');
      if (cameraContainer) {
        cameraContainer.classList.add('hidden');
        cameraContainer.style.setProperty('display', 'none', 'important');
      }

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

        // Seamless "Back" navigation: Preserve Front & Back files, preview cards, and idType
        const frontCard = document.getElementById('idFrontImagePreviewCard');
        const frontDrop = document.getElementById('idFrontDropZone');
        if (selectedFrontIdFile) {
          if (frontCard) {
            frontCard.classList.remove('hidden');
            frontCard.style.setProperty('display', 'block', 'important');
          }
          if (frontDrop) {
            frontDrop.classList.add('hidden');
            frontDrop.style.setProperty('display', 'none', 'important');
          }
        } else {
          if (frontCard) {
            frontCard.classList.add('hidden');
            frontCard.style.setProperty('display', 'none', 'important');
          }
          if (frontDrop) {
            frontDrop.classList.remove('hidden');
            frontDrop.style.setProperty('display', 'flex', 'important');
          }
        }

        const backCard = document.getElementById('idBackImagePreviewCard');
        const backDrop = document.getElementById('idBackDropZone');
        if (selectedBackIdFile) {
          if (backCard) {
            backCard.classList.remove('hidden');
            backCard.style.setProperty('display', 'block', 'important');
          }
          if (backDrop) {
            backDrop.classList.add('hidden');
            backDrop.style.setProperty('display', 'none', 'important');
          }
        } else {
          if (backCard) {
            backCard.classList.add('hidden');
            backCard.style.setProperty('display', 'none', 'important');
          }
          if (backDrop) {
            backDrop.classList.remove('hidden');
            backDrop.style.setProperty('display', 'flex', 'important');
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
      const idTypeEl = document.getElementById('verifyDocType') || document.getElementById('idTypeSelect');
      const idType = idTypeEl ? idTypeEl.value : (registeredIdType || '');

      const formData = new FormData();
      formData.append('action', 'complete_verification');
      formData.append('live_face_data', liveFaceBase64);
      if (selectedIdFile) {
        formData.append('id_photo', selectedIdFile);
      }
      if (selectedBackIdFile) {
        formData.append('id_photo_back', selectedBackIdFile);
      }
      const hiddenFrontBase64 = document.getElementById('captured_front_id_base64') || document.getElementById('captured_id_base64');
      if (hiddenFrontBase64 && hiddenFrontBase64.value) {
        formData.append('captured_id_base64', hiddenFrontBase64.value);
        formData.append('captured_front_id_base64', hiddenFrontBase64.value);
      }
      const hiddenBackBase64 = document.getElementById('captured_back_id_base64');
      if (hiddenBackBase64 && hiddenBackBase64.value) {
        formData.append('captured_back_id_base64', hiddenBackBase64.value);
        formData.append('captured_id_back_base64', hiddenBackBase64.value);
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
      stopIdCamera();
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
      const idTypeEl = document.getElementById('verifyDocType') || document.getElementById('idTypeSelect');
      const idType = idTypeEl ? idTypeEl.value : '';
      const alertBox = document.getElementById('verifyModalAlert');
      if (alertBox) alertBox.style.display = 'none';

      if (!idType) {
        showToast('Please select your ID type in Step 1.', 'error');
        goToVerificationStep(1);
        return;
      }

      const frontFileInput = document.getElementById('idFrontFileInput') || document.getElementById('idFileInput');
      const frontFile = selectedFrontIdFile || selectedIdFile || (frontFileInput && frontFileInput.files && frontFileInput.files[0]);
      const hiddenFrontBase64 = document.getElementById('captured_front_id_base64') || document.getElementById('captured_id_base64');
      const frontBase64 = hiddenFrontBase64 ? hiddenFrontBase64.value.trim() : '';

      const backFileInput = document.getElementById('idBackFileInput');
      const backFile = selectedBackIdFile || (backFileInput && backFileInput.files && backFileInput.files[0]);
      const hiddenBackBase64 = document.getElementById('captured_back_id_base64');
      const backBase64 = hiddenBackBase64 ? hiddenBackBase64.value.trim() : '';

      if (!frontFile && !frontBase64 && !window.hasStagedPendingId) {
        showToast('Please upload or capture the Front side of your ID in Step 1.', 'error');
        goToVerificationStep(1);
        return;
      }

      if (!backFile && !backBase64 && !window.hasStagedPendingId) {
        showToast('Please upload or capture the Back side of your ID in Step 1.', 'error');
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
      let ocrRawText = heldOcrRawText || '';
      if (!ocrRawText && window.Tesseract && typeof Tesseract.recognize === 'function') {
        try {
          const targetImage = preprocessedIdBlob || frontFile || selectedIdFile;
          if (targetImage) {
            const ocrPromise = Tesseract.recognize(targetImage, 'eng');
            const timeoutPromise = new Promise((_, reject) => setTimeout(() => reject(new Error('OCR Timeout')), 10000));
            const ocrResult = await Promise.race([ocrPromise, timeoutPromise]);
            if (ocrResult && ocrResult.data && ocrResult.data.text) {
              ocrRawText = ocrResult.data.text;
              heldOcrRawText = ocrRawText;
            }
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
      if (frontFile) {
        formData.append('id_photo', frontFile);
      }
      if (frontBase64) {
        formData.append('captured_id_base64', frontBase64);
        formData.append('captured_front_id_base64', frontBase64);
      }
      if (backFile) {
        formData.append('id_photo_back', backFile);
      }
      if (backBase64) {
        formData.append('captured_back_id_base64', backBase64);
        formData.append('captured_id_back_base64', backBase64);
      }
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

    function handleIdFile(file, side = 'front') {
      if (!side || typeof side !== 'string') side = 'front';
      const cap = side === 'back' ? 'Back' : 'Front';

      if (file.size > 10 * 1024 * 1024) {
        showToast((side === 'front' ? 'Front' : 'Back') + ' ID file exceeds 10MB limit', 'error');
        return;
      }

      if (side === 'front') {
        selectedFrontIdFile = file;
        selectedIdFile = file; // compatibility
        preprocessedIdBlob = null;
        preprocessedIdDataUrl = '';
        heldOcrRawText = '';

        // Immediately pre-process front image in the background (Grayscale + Contrast + Binarization) for high-accuracy OCR
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
      } else {
        selectedBackIdFile = file;
      }

      const nameEl = document.getElementById('id' + cap + 'PreviewFileName') || (side === 'front' ? document.getElementById('idPreviewFileName') : null);
      const sizeEl = document.getElementById('id' + cap + 'PreviewFileSize') || (side === 'front' ? document.getElementById('idPreviewFileSize') : null);
      if (nameEl) nameEl.textContent = file.name;
      if (sizeEl) sizeEl.textContent = (file.size / 1024 / 1024).toFixed(2) + ' MB';

      // Visual Image Preview: update preview card and hide dropzone for corresponding side
      const previewImg = document.getElementById('id' + cap + 'VisualPreviewImg') || (side === 'front' ? document.getElementById('idVisualPreviewImg') : null);
      const previewCard = document.getElementById('id' + cap + 'ImagePreviewCard') || (side === 'front' ? document.getElementById('idImagePreviewCard') : null);
      const dropZone = document.getElementById('id' + cap + 'DropZone') || (side === 'front' ? document.getElementById('idDropZone') : null);

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

      showToast(`${side === 'front' ? 'Front' : 'Back'} ID attached: ${file.name}`, 'info');
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

        btnUpload.className = 'maintenance-folder-tab active';
        btnTracker.className = 'maintenance-folder-tab';
        btnUpload.setAttribute('aria-selected', 'true');
        btnTracker.setAttribute('aria-selected', 'false');
      } else {
        uploadContent.classList.add('hidden');
        trackerContent.classList.remove('hidden');

        btnTracker.className = 'maintenance-folder-tab active';
        btnUpload.className = 'maintenance-folder-tab';
        btnTracker.setAttribute('aria-selected', 'true');
        btnUpload.setAttribute('aria-selected', 'false');
      }
      if (window.lucide && typeof window.lucide.createIcons === 'function') {
        window.lucide.createIcons();
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

      openModal('tenantTicketModal');
    }

    function closeTenantTicketModal(fromCloseModal = false) {
      const modal = document.getElementById('tenantTicketModal');
      if (modal) {
        modal.classList.add('hidden');
        modal.classList.remove('open', 'active');
        modal.style.display = 'none';
        modal.style.pointerEvents = 'none';
        document.body.style.overflow = '';
      }
      if (!fromCloseModal && typeof closeModal === 'function') {
        closeModal('tenantTicketModal');
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
        submitBtn.className = 'maintenance-folder-tab active';
        trackBtn.className = 'maintenance-folder-tab';
        submitBtn.setAttribute('aria-selected', 'true');
        trackBtn.setAttribute('aria-selected', 'false');
      } else {
        trackSection.classList.remove('hidden');
        submitSection.classList.add('hidden');
        trackBtn.className = 'maintenance-folder-tab active';
        submitBtn.className = 'maintenance-folder-tab';
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

      openModal('tenantReportModal');
    }

    function closeTenantReportModal(fromCloseModal = false) {
      const modal = document.getElementById('tenantReportModal');
      if (modal) {
        modal.classList.add('hidden');
        modal.classList.remove('open', 'active');
        modal.style.display = 'none';
        modal.style.pointerEvents = 'none';
        document.body.style.overflow = '';
      }
      if (!fromCloseModal && typeof closeModal === 'function') {
        closeModal('tenantReportModal');
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
        ['tenantTicketModal', 'tenantReportModal', 'verifyAccountModal', 'editAccountInfoModal', 'verificationActionPromptModal', 'termsContentModal', 'privacyContentModal', 'modalTenantProfileId', 'profileAdjustmentModal'].forEach(mId => {
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

      // 3. Drop Zone & File Selection for ID Verification (Front & Back)
      try {
        const setupDropZone = (dropZoneId, fileInputId, removeBtnId, side) => {
          const dropZone = document.getElementById(dropZoneId);
          const fileInput = document.getElementById(fileInputId);
          const removeBtn = document.getElementById(removeBtnId);

          if (removeBtn) {
            removeBtn.addEventListener('click', (e) => {
              e.preventDefault();
              e.stopPropagation();
              removeAttachedPhoto(e, side);
            });
          }

          if (dropZone && fileInput) {
            dropZone.addEventListener('click', () => {
              fileInput.click();
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
                const file = e.dataTransfer.files[0];
                try {
                  const dt = new DataTransfer();
                  dt.items.add(file);
                  fileInput.files = dt.files;
                } catch (err) {}
                handleIdFile(file, side);
              }
            });
          }
        };

        setupDropZone('idFrontDropZone', 'idFrontFileInput', 'btnRemoveFrontAttachedPhoto', 'front');
        setupDropZone('idBackDropZone', 'idBackFileInput', 'btnRemoveBackAttachedPhoto', 'back');
        // Legacy fallback
        setupDropZone('idDropZone', 'idFileInput', 'btnRemoveAttachedPhoto', 'front');
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
            const termsCheck = document.getElementById('checkTermsAgreement');

            const frontFileInput = document.getElementById('idFrontFileInput') || document.getElementById('idFileInput');
            const frontFile = (selectedFrontIdFile || selectedIdFile || (frontFileInput && frontFileInput.files && frontFileInput.files[0]));
            const hiddenFrontBase64 = document.getElementById('captured_front_id_base64') || document.getElementById('captured_id_base64');
            const frontBase64 = hiddenFrontBase64 ? hiddenFrontBase64.value.trim() : '';

            const backFileInput = document.getElementById('idBackFileInput');
            const backFile = (selectedBackIdFile || (backFileInput && backFileInput.files && backFileInput.files[0]));
            const hiddenBackBase64 = document.getElementById('captured_back_id_base64');
            const backBase64 = hiddenBackBase64 ? hiddenBackBase64.value.trim() : '';

            if (!docType) {
              const msg = 'Please select a valid Document Type from the dropdown.';
              showToast(msg, 'error');
              if (alertBox) {
                alertBox.style.display = 'block';
                alertBox.innerHTML = `<span style="display:flex;align-items:center;gap:8px;">${window.rcIcon('alert-triangle', 'w-4 h-4 text-red-600')}<span>${escapeHtml(msg)}</span></span>`;
              }
              return;
            }

            if (!frontFile && !frontBase64) {
              const msg = 'Please attach or capture the Front side of your ID.';
              showToast(msg, 'error');
              if (alertBox) {
                alertBox.style.display = 'block';
                alertBox.innerHTML = `<span style="display:flex;align-items:center;gap:8px;">${window.rcIcon('alert-triangle', 'w-4 h-4 text-red-600')}<span>${escapeHtml(msg)}</span></span>`;
              }
              return;
            }

            if (!backFile && !backBase64) {
              const msg = 'Please attach or capture the Back side of your ID.';
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

            selectedFrontIdFile = frontFile || null;
            selectedIdFile = frontFile || null;
            selectedBackIdFile = backFile || null;

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
                const targetImage = preprocessedIdBlob || frontFile;
                if (targetImage) {
                  const ocrPromise = Tesseract.recognize(targetImage, 'eng');
                  const timeoutPromise = new Promise((_, reject) => setTimeout(() => reject(new Error('OCR Timeout')), 5000));
                  const ocrResult = await Promise.race([ocrPromise, timeoutPromise]);
                  if (ocrResult && ocrResult.data && ocrResult.data.text) {
                    ocrText = ocrResult.data.text;
                    heldOcrRawText = ocrText;
                  }
                }
              } catch (ocrErr) {
                console.warn('Quick client OCR check notice:', ocrErr);
              }
            }

            const formData = new FormData();
            formData.append('action', 'validate_and_stage_id');
            formData.append('id_type', docType);
            if (frontFile) {
              formData.append('id_photo', frontFile);
            }
            if (frontBase64) {
              formData.append('captured_id_base64', frontBase64);
              formData.append('captured_front_id_base64', frontBase64);
            }
            if (backFile) {
              formData.append('id_photo_back', backFile);
            }
            if (backBase64) {
              formData.append('captured_back_id_base64', backBase64);
              formData.append('captured_id_back_base64', backBase64);
            }
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
              first_name: (document.getElementById('editFirstName') ? document.getElementById('editFirstName').value.trim() : ''),
              middle_name: (document.getElementById('editMiddleName') ? document.getElementById('editMiddleName').value.trim() : ''),
              last_name: (document.getElementById('editLastName') ? document.getElementById('editLastName').value.trim() : ''),
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
  <script>
    (function() {
      let notifFetchInFlight = false;

      function escNotifHtml(str) {
        if (!str) return '';
        return String(str)
          .replace(/&/g, '&amp;')
          .replace(/</g, '&lt;')
          .replace(/>/g, '&gt;')
          .replace(/"/g, '&quot;')
          .replace(/'/g, '&#039;');
      }

      function getNotifIconMarkup(type) {
        switch (type) {
          case 'payment':
            return '<div class="notif-icon-circle notif-icon-payment"><i data-lucide="receipt" aria-hidden="true"></i></div>';
          case 'maintenance':
            return '<div class="notif-icon-circle notif-icon-maintenance"><i data-lucide="wrench" aria-hidden="true"></i></div>';
          case 'announcement':
            return '<div class="notif-icon-circle notif-icon-announcement"><i data-lucide="bell" aria-hidden="true"></i></div>';
          case 'account':
          default:
            return '<div class="notif-icon-circle notif-icon-account"><i data-lucide="user-round" aria-hidden="true"></i></div>';
        }
      }

      function renderFriendlyEmptyState() {
        const listContainer = document.getElementById('notificationListContainer');
        if (!listContainer) return;
        listContainer.innerHTML = `
          <div class="notification-empty-state">
            <div class="empty-icon"><i data-lucide="bell-off" aria-hidden="true"></i></div>
            <h4>No Notifications Yet</h4>
            <p>You're all caught up! No new notifications at this time.</p>
          </div>
        `;
        if (window.lucide && typeof window.lucide.createIcons === 'function') {
          window.lucide.createIcons();
        }
      }

      function renderNotificationListSafe(data) {
        const unreadCount = parseInt((data && data.unread_count) || 0, 10) || 0;
        const badge = document.getElementById('notificationBadge');
        const unreadPill = document.getElementById('notificationUnreadPill');
        const listContainer = document.getElementById('notificationListContainer');

        if (badge) {
          if (unreadCount > 0) {
            badge.textContent = unreadCount > 99 ? '99+' : String(unreadCount);
            badge.style.display = 'flex';
          } else {
            badge.style.display = 'none';
          }
        }

        if (unreadPill) {
          if (unreadCount > 0) {
            unreadPill.textContent = `${unreadCount} new`;
            unreadPill.style.display = 'inline-block';
          } else {
            unreadPill.style.display = 'none';
          }
        }

        if (!listContainer) return;
        const items = (data && Array.isArray(data.notifications)) ? data.notifications : [];
        if (items.length === 0) {
          renderFriendlyEmptyState();
          return;
        }

        listContainer.innerHTML = items.map(n => {
          const isUnread = parseInt(n.is_read, 10) === 0;
          return `
            <div class="notification-item ${isUnread ? 'unread' : 'read'}"
                 data-id="${n.id}"
                 data-link="${escNotifHtml(n.link || '')}"
                 data-ref-id="${escNotifHtml(n.reference_id || '')}"
                 data-ref-type="${escNotifHtml(n.reference_type || '')}"
                 data-type="${escNotifHtml(n.type || '')}"
                 onclick="handleNotificationClick(event, ${n.id}, '${escNotifHtml(n.link || '')}', '${escNotifHtml(n.reference_id || '')}', '${escNotifHtml(n.reference_type || '')}', '${escNotifHtml(n.type || '')}')"
                 role="button"
                 tabindex="0">
              ${getNotifIconMarkup(n.type)}
              <div class="notif-content">
                <div class="notif-title">${escNotifHtml(n.title)}</div>
                <div class="notif-message">${escNotifHtml(n.message)}</div>
                <div class="notif-time">${escNotifHtml(n.time_ago || '')}${n.formatted_date ? ' • ' + escNotifHtml(n.formatted_date) : ''}</div>
              </div>
              ${isUnread ? '<span class="notification-unread-dot" title="Unread"></span>' : ''}
            </div>
          `;
        }).join('');

        if (window.lucide && typeof window.lucide.createIcons === 'function') {
          window.lucide.createIcons();
        }
      }

      function ensureBellAlwaysInteractive() {
        const bellBtn = document.getElementById('headerNotificationBtn');
        if (!bellBtn) return;
        bellBtn.disabled = false;
        bellBtn.removeAttribute('disabled');
        bellBtn.removeAttribute('aria-disabled');
        bellBtn.style.pointerEvents = 'auto';
        bellBtn.style.cursor = 'pointer';
      }

      window.loadNotifications = function(silent = false) {
        if (notifFetchInFlight) return;
        notifFetchInFlight = true;
        fetch('api/notification_api.php?action=list', { cache: 'no-store' })
          .then(res => {
            if (!res.ok) throw new Error('HTTP ' + res.status);
            return res.json();
          })
          .then(data => {
            notifFetchInFlight = false;
            if (data && data.status === 'success') {
              renderNotificationListSafe(data);
            } else {
              renderFriendlyEmptyState();
            }
          })
          .catch(err => {
            notifFetchInFlight = false;
            const listContainer = document.getElementById('notificationListContainer');
            if (listContainer && !listContainer.querySelector('.notification-item')) {
              renderFriendlyEmptyState();
            }
            if (!silent) console.warn('Notification load notice:', err);
          });
      };

      window.closeNotificationPanel = function() {
        const notifPanel = document.getElementById('notificationDropdown');
        const bellBtn = document.getElementById('headerNotificationBtn');
        if (notifPanel) notifPanel.classList.remove('show');
        if (bellBtn) bellBtn.setAttribute('aria-expanded', 'false');
      };

      window.toggleNotificationPanel = function(e) {
        if (e) {
          e.preventDefault();
          e.stopPropagation();
        }
        ensureBellAlwaysInteractive();

        const settingsPanel = document.getElementById('settingsDropdown');
        if (settingsPanel) settingsPanel.classList.remove('show');
        const tenantSearchDropdown = document.getElementById('tenantSearchDropdown');
        if (tenantSearchDropdown) tenantSearchDropdown.classList.remove('open');

        const notifPanel = document.getElementById('notificationDropdown');
        const bellBtn = document.getElementById('headerNotificationBtn');
        if (!notifPanel) return;

        const isShowing = notifPanel.classList.toggle('show');
        if (bellBtn) bellBtn.setAttribute('aria-expanded', isShowing ? 'true' : 'false');

        if (isShowing) {
          const listContainer = document.getElementById('notificationListContainer');
          if (listContainer && !listContainer.querySelector('.notification-item') && !listContainer.querySelector('.notification-empty-state')) {
            renderFriendlyEmptyState();
          }
          window.loadNotifications(true);
        }
      };

      document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') {
          const notifPanel = document.getElementById('notificationDropdown');
          if (notifPanel && notifPanel.classList.contains('show')) {
            window.closeNotificationPanel();
          }
        }
      });

      document.addEventListener('click', function(e) {
        const notifPanel = document.getElementById('notificationDropdown');
        if (notifPanel && notifPanel.classList.contains('show')) {
          if (!e.target.closest('#headerNotificationBtn') && !e.target.closest('#notificationDropdown')) {
            window.closeNotificationPanel();
          }
        }
      });

      ensureBellAlwaysInteractive();
      if (document.readyState !== 'loading') {
        window.loadNotifications(true);
      } else {
        document.addEventListener('DOMContentLoaded', () => {
          ensureBellAlwaysInteractive();
          window.loadNotifications(true);
        });
      }
    })();
  </script>
</body>
</html>
