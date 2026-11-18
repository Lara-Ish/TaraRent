<?php
/**
 * RentConnect - Payment & Receipt Upload API Endpoint
 * Handles tenant receipt uploads, landlord manual payment review (approval/rejection), and ledger audits
 */

declare(strict_types=1);

header('Content-Type: application/json; charset=UTF-8');

require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../includes/notification_service.php';
require_once __DIR__ . '/../includes/tenant_service.php';
require_once __DIR__ . '/../includes/email_service.php';

$requestMethod = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($requestMethod === 'OPTIONS') {
    http_response_code(200);
    exit;
}

$uploadDir = __DIR__ . '/../uploads/receipts/';
if (!is_dir($uploadDir)) {
    mkdir($uploadDir, 0777, true);
}

$pdo = Database::getConnection();

try {
    if ($requestMethod === 'POST') {
        $input = json_decode(file_get_contents('php://input'), true) ?? $_POST;
        $action = trim((string)($input['action'] ?? 'upload_receipt'));

        // ---------------------------------------------------------------------
        // 1. Landlord Manual Review: Approve or Reject Payment Receipt
        // ---------------------------------------------------------------------
        if ($action === 'verify_payment' || $action === 'review_payment') {
            if (!Auth::isLandlord()) {
                http_response_code(403);
                echo json_encode([
                    'status' => 'error',
                    'message' => 'Unauthorized: Only the landlord can review and verify payment receipts.'
                ]);
                exit;
            }

            $paymentId = (int)($input['payment_id'] ?? 0);
            $decision = strtolower(trim((string)($input['decision'] ?? 'approved'))); // 'approved' or 'rejected'
            $notes = trim((string)($input['notes'] ?? ''));

            if ($paymentId <= 0) {
                http_response_code(422);
                echo json_encode(['status' => 'error', 'message' => 'Invalid payment ID.']);
                exit;
            }

            if (!in_array($decision, ['approved', 'rejected'], true)) {
                http_response_code(422);
                echo json_encode(['status' => 'error', 'message' => 'Decision must be either "approved" or "rejected".']);
                exit;
            }

            // Check if payment exists
            $check = $pdo->prepare("SELECT id, tenant_id, payment_type, billing_cycle, due_date, amount_due, amount_paid, receipt_filename, status, verification_notes, verified_at FROM payments WHERE id = :id LIMIT 1");
            $check->execute([':id' => $paymentId]);
            $payment = $check->fetch();

            if (!$payment) {
                http_response_code(404);
                echo json_encode(['status' => 'error', 'message' => 'Payment record not found.']);
                exit;
            }

            $isApproved = ($decision === 'approved');
            $statusVal = $isApproved ? 'approved' : 'rejected';
            $verifiedFlag = $isApproved ? 1 : 0;
            $verifiedSql = $isApproved ? 'TRUE' : 'FALSE';
            $defaultNotes = $isApproved ? 'Payment receipt approved & verified.' : 'Payment proof rejected.';
            $finalNotes = !empty($notes) ? $notes : $defaultNotes;

            // Anti-duplicate protection: If already processed with same status and notes, return without re-dispatching emails
            if ($payment['status'] === $statusVal && !empty($payment['verified_at']) && $payment['verification_notes'] === $finalNotes) {
                echo json_encode([
                    'status' => 'success',
                    'message' => "Payment is already marked as {$statusVal}.",
                    'payment' => [
                        'id' => $paymentId,
                        'status' => $statusVal,
                        'verified_by_landlord' => $verifiedFlag,
                        'verification_notes' => $finalNotes
                    ]
                ]);
                exit;
            }

            // When approved, ensure paid amount is updated to amount_due so remaining balance becomes 0.00
            $updatePaidSql = "";
            if ($isApproved) {
                $updatePaidSql = ", amount_paid = CASE WHEN amount_paid <= 0 THEN COALESCE(amount_due, 3000.00) ELSE amount_paid END";
            }

            $stmt = $pdo->prepare("
                UPDATE payments 
                SET status = :status,
                    verified_by_landlord = {$verifiedSql},
                    verification_notes = :notes,
                    verified_at = NOW(),
                    payment_date = COALESCE(payment_date, CURRENT_DATE)
                    {$updatePaidSql}
                WHERE id = :id
            ");
            $stmt->execute([
                ':status' => $statusVal,
                ':notes' => $finalNotes,
                ':id' => $paymentId
            ]);

            // Notify Tenant of review outcome
            if (!empty($payment['tenant_id'])) {
                $payType = $payment['payment_type'] ?? 'Rent';
                $monthStr = !empty($payment['billing_cycle']) ? date('F Y', strtotime($payment['billing_cycle'])) : 'account creation';
                $obligationLabel = ($payType === 'Advance Deposit') ? 'Advance Deposit' : "Rent ({$monthStr})";
                $notifTitle = $isApproved ? "{$obligationLabel} Verified" : "{$obligationLabel} Rejected";
                $notifMsg = $isApproved
                    ? "Your payment proof for {$obligationLabel} has been successfully verified and approved."
                    : "Your payment proof for {$obligationLabel} was rejected. Reason: {$finalNotes}. Please review and re-submit.";
                NotificationService::notifyTenant(
                    $pdo,
                    (int)$payment['tenant_id'],
                    'payment',
                    $notifTitle,
                    $notifMsg,
                    'tenantPayments',
                    $paymentId,
                    $isApproved ? 'payment_approved' : 'payment_rejected'
                );

                // Automated Email Notification to Tenant's Gmail
                try {
                    $tenantId = (int)$payment['tenant_id'];
                    $tStmt = $pdo->prepare("SELECT id, name, email FROM users WHERE id = :id LIMIT 1");
                    $tStmt->execute([':id' => $tenantId]);
                    $tUser = $tStmt->fetch(PDO::FETCH_ASSOC);

                    if ($tUser && !empty($tUser['email']) && filter_var($tUser['email'], FILTER_VALIDATE_EMAIL)) {
                        $amtVal = (float)($payment['amount_paid'] > 0 ? $payment['amount_paid'] : ($payment['amount_due'] ?? 3000.00));
                        $formattedAmount = '₱' . number_format($amtVal, 2);
                        $billingMonth = (!empty($payment['billing_cycle']) && strtotime($payment['billing_cycle']) !== false)
                            ? date('F Y', strtotime($payment['billing_cycle']))
                            : ($payment['billing_cycle'] ?: date('F Y'));
                        $statusDisplay = $isApproved ? 'Approved' : 'Rejected';

                        // Exact wording: "Your payment of [Amount] for [Billing Month] has been [Status]."
                        $emailMsg = "Your payment of <strong>{$formattedAmount}</strong> for <strong>{$billingMonth}</strong> has been <strong>{$statusDisplay}</strong>.";
                        if (!$isApproved && !empty($finalNotes)) {
                            $emailMsg .= "<br><br><strong>Reason:</strong> " . htmlspecialchars($finalNotes, ENT_QUOTES, 'UTF-8');
                        }

                        $emailSubj = "Payment {$statusDisplay} - {$billingMonth}";
                        sendTenantNotification(
                            (string)$tUser['email'],
                            $emailSubj,
                            $emailMsg,
                            $tUser['name'] ?? 'Tenant',
                            [
                                'Amount' => $formattedAmount,
                                'Billing Month' => $billingMonth,
                                'Status' => $statusDisplay,
                                'Landlord Notes' => $finalNotes
                            ],
                            'View Payment History',
                            MailConfig::get()['app_url'] . '/tenant_portal.php?section=tenantPayments&tab=tracker',
                            $tenantId,
                            $pdo
                        );
                    }
                } catch (Throwable $mailEx) {
                    error_log('[payment_api] Failed to send payment review email: ' . $mailEx->getMessage());
                }
            }

            // Automatic Archiving Check:
            // When a tenant has officially moved out and paid all outstanding bills,
            // automatically archive the tenant account and payment history safely inside a locked transaction.
            $autoArchived = false;
            $tenantId = (int)($payment['tenant_id'] ?? 0);
            if ($isApproved && $tenantId > 0) {
                $startedTx = false;
                if (!$pdo->inTransaction()) {
                    $pdo->beginTransaction();
                    $startedTx = true;
                }
                try {
                    $tStmt = $pdo->prepare("
                        SELECT * FROM users 
                        WHERE id = :id AND role = 'tenant' AND is_active = TRUE AND (is_archived = FALSE OR is_archived IS NULL)
                        LIMIT 1
                        FOR UPDATE
                    ");
                    $tStmt->execute([':id' => $tenantId]);
                    $tUser = $tStmt->fetch(PDO::FETCH_ASSOC);

                    if ($tUser && ($tUser['rental_status'] ?? 'active') === 'moved_out') {
                        $lockPay = $pdo->prepare("SELECT id FROM payments WHERE tenant_id = :tid FOR UPDATE");
                        $lockPay->execute([':tid' => $tenantId]);
                        $lockPay->fetchAll(PDO::FETCH_COLUMN);

                        $settlement = TenantService::checkTenantSettlement($pdo, $tenantId);
                        if ($settlement['is_settled']) {
                            $autoArchived = TenantService::archiveSettledTenant($pdo, $tenantId, $tUser);
                        }
                    }

                    if ($startedTx) {
                        $pdo->commit();
                    }
                } catch (Throwable $archiveErr) {
                    if ($startedTx && $pdo->inTransaction()) {
                        $pdo->rollBack();
                    }
                    error_log('[payment_api] Auto-archive error: ' . $archiveErr->getMessage());
                    $autoArchived = false;
                }
            }

            $successMsg = $isApproved 
                ? ($autoArchived 
                    ? 'Payment receipt successfully verified and approved! The tenant has moved out and all bills are fully settled. Tenant account and payment history have been automatically transferred to Archived Tenants.' 
                    : 'Payment receipt successfully verified and approved!')
                : 'Payment receipt rejected. Feedback logged for tenant.';

            echo json_encode([
                'status' => 'success',
                'message' => $successMsg,
                'auto_archived' => $autoArchived,
                'payment' => [
                    'id' => $paymentId,
                    'status' => $statusVal,
                    'verified_by_landlord' => $verifiedFlag,
                    'verification_notes' => $finalNotes,
                    'verified_at' => date('Y-m-d H:i:s')
                ]
            ]);
            exit;
        }

        // ---------------------------------------------------------------------
        // 2. Tenant Receipt Upload Workflow
        // ---------------------------------------------------------------------
        if ($action === 'upload_receipt') {
            AccessControl::requireVerifiedTenantJson($pdo);
            $tenantUser = Auth::user();
            $tenantId = (int)$tenantUser['id'];

            $targetPaymentId = (int)($_POST['payment_id'] ?? 0);
            $paymentTypeInput = trim((string)($_POST['payment_type'] ?? ''));
            $amountPaid = (float)($_POST['amount_paid'] ?? 3000.00);
            $billingCycle = trim((string)($_POST['billing_cycle'] ?? date('Y-m-01')));
            $paymentMethod = trim((string)($_POST['payment_method'] ?? 'GCash / Bank Transfer'));
            $refNum = trim((string)($_POST['reference_number'] ?? ''));
            if (!empty($refNum) && stripos($paymentMethod, $refNum) === false) {
                $paymentMethod = $paymentMethod . ' (Ref: ' . $refNum . ')';
            }

            $savedFilename = null;

            if (isset($_FILES['receipt']) && $_FILES['receipt']['error'] === UPLOAD_ERR_OK) {
                $file = $_FILES['receipt'];
                $maxBytes = 8 * 1024 * 1024; // 8MB

                if ($file['size'] > $maxBytes) {
                    http_response_code(413);
                    echo json_encode(['status' => 'error', 'message' => 'Receipt exceeds the 8MB size limit.']);
                    exit;
                }

                $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
                if (!in_array($ext, ['pdf', 'png', 'jpg', 'jpeg', 'webp'], true)) {
                    http_response_code(415);
                    echo json_encode(['status' => 'error', 'message' => 'Only PDF, PNG, JPG, and WEBP receipts are permitted.']);
                    exit;
                }

                $safeName = sprintf(
                    'receipt_%s_%s.%s',
                    str_replace('-', '', $billingCycle),
                    bin2hex(random_bytes(6)),
                    $ext
                );

                if (!move_uploaded_file($file['tmp_name'], $uploadDir . $safeName)) {
                    http_response_code(500);
                    echo json_encode(['status' => 'error', 'message' => 'Failed to save receipt file to storage.']);
                    exit;
                }

                $savedFilename = $safeName;
            }

            // Identify specific payment obligation: by ID, payment type, or billing cycle
            $existing = null;
            if ($targetPaymentId > 0) {
                $checkStmt = $pdo->prepare("SELECT * FROM payments WHERE id = :id AND tenant_id = :tid LIMIT 1");
                $checkStmt->execute([':id' => $targetPaymentId, ':tid' => $tenantId]);
                $existing = $checkStmt->fetch();
            } elseif (!empty($paymentTypeInput)) {
                $checkStmt = $pdo->prepare("SELECT * FROM payments WHERE tenant_id = :tid AND payment_type = :ptype AND status IN ('unpaid', 'rejected', 'overdue', 'pending') ORDER BY id DESC LIMIT 1");
                $checkStmt->execute([':tid' => $tenantId, ':ptype' => $paymentTypeInput]);
                $existing = $checkStmt->fetch();
            } else {
                $checkStmt = $pdo->prepare("
                    SELECT * 
                    FROM payments 
                    WHERE tenant_id = :tid 
                      AND (billing_cycle = :cycle OR status IN ('unpaid', 'rejected', 'overdue')) 
                    ORDER BY id DESC LIMIT 1
                ");
                $checkStmt->execute([':cycle' => $billingCycle, ':tid' => $tenantId]);
                $existing = $checkStmt->fetch();
            }

            if ($existing) {
                if ($existing['status'] === 'pending_setup') {
                    http_response_code(422);
                    echo json_encode([
                        'status' => 'error',
                        'message' => 'Your rent details are being configured by the Owner. Payment submission is disabled until rent configuration is completed.'
                    ]);
                    exit;
                }

                // When uploading or re-uploading receipt, set status to pending review
                $updateStmt = $pdo->prepare("
                    UPDATE payments 
                    SET amount_paid = :paid,
                        payment_date = CURRENT_DATE,
                        status = 'pending',
                        receipt_filename = COALESCE(:receipt, receipt_filename),
                        payment_method = :method,
                        billing_cycle = COALESCE(billing_cycle, :cycle),
                        verified_by_landlord = FALSE,
                        verification_notes = NULL,
                        verified_at = NULL
                    WHERE id = :id
                ");
                $updateStmt->execute([
                    ':paid' => $amountPaid,
                    ':receipt' => $savedFilename,
                    ':method' => $paymentMethod,
                    ':cycle' => $billingCycle,
                    ':id' => $existing['id']
                ]);
                $paymentId = (int)$existing['id'];
                $paymentType = $existing['payment_type'] ?? 'Rent';
            } else {
                $paymentType = !empty($paymentTypeInput) ? $paymentTypeInput : 'Rent';
                $unitId = $tenantUser && isset($tenantUser['unit_id']) ? (int)$tenantUser['unit_id'] : 1;
                $unitRentStmt = $pdo->prepare("SELECT monthly_rent FROM units WHERE id = :uid LIMIT 1");
                $unitRentStmt->execute([':uid' => $unitId]);
                $unitRow = $unitRentStmt->fetch();
                $amountDue = $unitRow ? (float)$unitRow['monthly_rent'] : 3000.00;

                $insertStmt = $pdo->prepare("
                    INSERT INTO payments 
                        (tenant_id, unit_id, payment_type, billing_cycle, due_date, amount_due, amount_paid, payment_date, status, payment_method, receipt_filename, verified_by_landlord, created_at)
                    VALUES 
                        (:tenant_id, :unit_id, :ptype, :cycle, :due_date, :due, :paid, CURRENT_DATE, 'pending', :method, :receipt, FALSE, NOW())
                ");
                $insertStmt->execute([
                    ':tenant_id' => $tenantId,
                    ':unit_id' => $unitId,
                    ':ptype' => $paymentType,
                    ':cycle' => $billingCycle,
                    ':due_date' => date('Y-m-05', strtotime($billingCycle)),
                    ':due' => $amountDue,
                    ':paid' => $amountPaid,
                    ':method' => $paymentMethod,
                    ':receipt' => $savedFilename
                ]);
                $paymentId = (int)$pdo->lastInsertId();
            }

            // Retrieve fresh payment details for exact notification display
            $pInfoStmt = $pdo->prepare("
                SELECT p.id, p.amount_paid, p.payment_date, p.created_at, u.name as tenant_name, un.unit_number
                FROM payments p
                JOIN users u ON p.tenant_id = u.id
                LEFT JOIN units un ON p.unit_id = un.id
                WHERE p.id = :pid
                LIMIT 1
            ");
            $pInfoStmt->execute([':pid' => $paymentId]);
            $pInfo = $pInfoStmt->fetch(PDO::FETCH_ASSOC);

            $pTenantName = $pInfo['tenant_name'] ?? ($tenantUser['name'] ?? 'Tenant');
            $pUnitNum = $pInfo['unit_number'] ?: 'Unit';
            $pAmountStr = '₱' . number_format((float)($pInfo['amount_paid'] ?? $amountPaid), 2);
            $pSubDateRaw = !empty($pInfo['payment_date']) ? $pInfo['payment_date'] : (!empty($pInfo['created_at']) ? $pInfo['created_at'] : 'now');
            $pSubDate = date('M j, Y', strtotime($pSubDateRaw));

            // Notify Landlords of submitted payment proof (strictly Tenant Rent Payment Submission)
            NotificationService::notifyLandlords(
                $pdo,
                'payment',
                'Tenant Rent Payment Submission',
                "Tenant: {$pTenantName} • Unit: {$pUnitNum} • Amount Paid: {$pAmountStr} • Submitted: {$pSubDate}",
                'sectionPayments',
                $paymentId,
                'payment_submitted'
            );

            // Notify Tenant (preserved)
            $cycleMonth = date('F Y', strtotime($billingCycle));
            $notifObligation = ($paymentType === 'Advance Deposit') ? 'Advance Deposit' : "Rent ({$cycleMonth})";
            NotificationService::notifyTenant(
                $pdo,
                $tenantId,
                'payment',
                'Payment Proof Submitted',
                "Your payment proof for {$notifObligation} has been submitted and is pending verification.",
                'tenantPayments',
                $paymentId,
                'payment_pending'
            );

            echo json_encode([
                'status' => 'success',
                'message' => 'Payment receipt uploaded successfully! Submitted for landlord verification.',
                'payment' => [
                    'id' => $paymentId,
                    'amount_paid' => $amountPaid,
                    'billing_cycle' => $billingCycle,
                    'receipt_filename' => $savedFilename,
                    'status' => 'pending'
                ]
            ]);
            exit;
        }

        // ---------------------------------------------------------------------
        // 3. Admin / Landlord: Configure Placeholder Rent Record
        // ---------------------------------------------------------------------
        if ($action === 'configure_rent') {
            if (!Auth::isLandlord()) {
                http_response_code(403);
                echo json_encode([
                    'status' => 'error',
                    'message' => 'Unauthorized: Only the landlord can configure rent records.'
                ]);
                exit;
            }

            $paymentId = (int)($input['payment_id'] ?? 0);
            $rentAmount = isset($input['monthly_rent']) ? (float)$input['monthly_rent'] : (isset($input['amount_due']) ? (float)$input['amount_due'] : 0.0);
            $billingCycleInput = trim((string)($input['billing_cycle'] ?? ''));
            $dueDateInput = trim((string)($input['due_date'] ?? ''));

            if ($paymentId <= 0) {
                http_response_code(422);
                echo json_encode(['status' => 'error', 'message' => 'Invalid payment ID.']);
                exit;
            }

            if ($rentAmount <= 0) {
                http_response_code(422);
                echo json_encode(['status' => 'error', 'message' => 'Please provide a valid monthly rent amount greater than 0.']);
                exit;
            }

            // Format billing cycle (must be valid YYYY-MM or YYYY-MM-DD, normalize to YYYY-MM-01)
            if (empty($billingCycleInput)) {
                http_response_code(422);
                echo json_encode(['status' => 'error', 'message' => 'Billing period / rental month is required.']);
                exit;
            }
            if (preg_match('/^\d{4}-\d{2}$/', $billingCycleInput)) {
                $billingCycle = $billingCycleInput . '-01';
            } else {
                $timestamp = strtotime($billingCycleInput);
                if (!$timestamp) {
                    http_response_code(422);
                    echo json_encode(['status' => 'error', 'message' => 'Invalid billing period format.']);
                    exit;
                }
                $billingCycle = date('Y-m-01', $timestamp);
            }

            // Format due date (defaults to 5th of the billing cycle month if not provided or empty)
            if (empty($dueDateInput)) {
                $dueDate = date('Y-m-05', strtotime($billingCycle));
            } else {
                $dueTimestamp = strtotime($dueDateInput);
                if (!$dueTimestamp) {
                    http_response_code(422);
                    echo json_encode(['status' => 'error', 'message' => 'Invalid due date format.']);
                    exit;
                }
                $dueDate = date('Y-m-d', $dueTimestamp);
            }

            // Check if payment record exists
            $checkStmt = $pdo->prepare("
                SELECT p.*, u.name AS tenant_name, un.unit_number 
                FROM payments p
                JOIN users u ON p.tenant_id = u.id
                JOIN units un ON p.unit_id = un.id
                WHERE p.id = :id LIMIT 1
            ");
            $checkStmt->execute([':id' => $paymentId]);
            $payment = $checkStmt->fetch();

            if (!$payment) {
                http_response_code(404);
                echo json_encode(['status' => 'error', 'message' => 'Rent payment record not found.']);
                exit;
            }

            // Prevent duplicate active rent records for the same tenant and billing cycle
            $dupStmt = $pdo->prepare("
                SELECT id FROM payments 
                WHERE tenant_id = :tid AND billing_cycle = :cycle AND id != :id AND payment_type = 'Rent'
                LIMIT 1
            ");
            $dupStmt->execute([
                ':tid' => $payment['tenant_id'],
                ':cycle' => $billingCycle,
                ':id' => $paymentId
            ]);
            if ($dupStmt->fetch()) {
                http_response_code(409);
                echo json_encode([
                    'status' => 'error',
                    'message' => "A rent record already exists for tenant '{$payment['tenant_name']}' for " . date('F Y', strtotime($billingCycle)) . "."
                ]);
                exit;
            }

            // Update placeholder into an active rental record:
            // - status becomes 'unpaid' (awaiting tenant payment submission)
            // - amount_due is set
            // - billing_cycle and due_date are set
            // - amount_paid remains 0.00 (not treated as actual payment until tenant pays)
            // - verified_by_landlord remains 0
            // - payment_date remains NULL
            // - receipt_filename remains NULL
            // Check if tenant has active additional spaces rented
            $spacesStmt = $pdo->prepare("
                SELECT space_name, monthly_fee 
                FROM additional_spaces 
                WHERE assigned_tenant_id = :tid AND status = 'occupied'
                ORDER BY space_number ASC
            ");
            $spacesStmt->execute([':tid' => $payment['tenant_id']]);
            $activeSpaces = $spacesStmt->fetchAll(PDO::FETCH_ASSOC);

            $extraSpacesFee = 0.0;
            $parts = [];
            foreach ($activeSpaces as $as) {
                $extraSpacesFee += (float)$as['monthly_fee'];
                $parts[] = "{$as['space_name']} (₱" . number_format((float)$as['monthly_fee'], 2) . "/mo)";
            }
            $spacesBreakdown = !empty($parts) ? implode(' + ', $parts) : null;
            $totalAmountDue = $rentAmount + $extraSpacesFee;

            $vNotes = $extraSpacesFee > 0
                ? "Rent: ₱" . number_format($rentAmount, 2) . " + Additional Space: ₱" . number_format($extraSpacesFee, 2) . " (" . $spacesBreakdown . ")"
                : "Rent configured by Landlord. Awaiting tenant payment.";

            $updStmt = $pdo->prepare("
                UPDATE payments 
                SET payment_type = 'Rent',
                    base_rent_amount = :base,
                    additional_space_fee = :extra,
                    additional_spaces_breakdown = :breakdown,
                    amount_due = :due,
                    billing_cycle = :cycle,
                    due_date = :due_date,
                    status = 'unpaid',
                    verification_notes = :vnotes
                WHERE id = :id
            ");
            $updStmt->execute([
                ':base' => $rentAmount,
                ':extra' => $extraSpacesFee,
                ':breakdown' => $spacesBreakdown,
                ':due' => $totalAmountDue,
                ':cycle' => $billingCycle,
                ':due_date' => $dueDate,
                ':vnotes' => $vNotes,
                ':id' => $paymentId
            ]);

            // Sync unit monthly rent if unit exists
            if (!empty($payment['unit_id'])) {
                $pdo->prepare("UPDATE units SET monthly_rent = :rent WHERE id = :uid")
                    ->execute([':rent' => $rentAmount, ':uid' => $payment['unit_id']]);
            }

            // Notify Tenant that rent has been configured
            $monthStr = date('F Y', strtotime($billingCycle));
            $formattedRent = number_format($rentAmount, 2);
            $formattedDueDate = date('M d, Y', strtotime($dueDate));
            NotificationService::notifyTenant(
                $pdo,
                (int)$payment['tenant_id'],
                'payment',
                'Rent Payment Configured',
                "Your monthly rent for {$monthStr} has been set to ₱{$formattedRent}, due on {$formattedDueDate}.",
                'tenantPayments',
                $paymentId,
                'rent_configured'
            );

            echo json_encode([
                'status' => 'success',
                'message' => "Rent for {$payment['tenant_name']} ({$payment['unit_number']}) configured successfully! Billing Month: {$monthStr}, Amount: ₱{$formattedRent}.",
                'payment' => [
                    'id' => $paymentId,
                    'tenant_id' => (int)$payment['tenant_id'],
                    'tenant_name' => $payment['tenant_name'],
                    'unit_number' => $payment['unit_number'],
                    'payment_type' => 'Rent',
                    'amount_due' => $rentAmount,
                    'amount_paid' => 0.00,
                    'billing_cycle' => $billingCycle,
                    'due_date' => $dueDate,
                    'status' => 'unpaid'
                ]
            ]);
            exit;
        }

        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'Invalid action.']);
        exit;
    }

    if ($requestMethod === 'GET') {
        $currentUser = Auth::user();
        $singleId = isset($_GET['id']) ? (int)$_GET['id'] : 0;

        if ($singleId > 0) {
            $stmt = $pdo->prepare("
                SELECT p.*, u.name AS tenant_name, u.username AS tenant_username, u.move_in_date, un.unit_number
                FROM payments p
                JOIN users u ON p.tenant_id = u.id
                JOIN units un ON p.unit_id = un.id
                WHERE p.id = :pid
                LIMIT 1
            ");
            $stmt->execute([':pid' => $singleId]);
            $singlePayment = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($singlePayment) {
                echo json_encode([
                    'status' => 'success',
                    'payment' => $singlePayment
                ]);
                exit;
            } else {
                http_response_code(404);
                echo json_encode(['status' => 'error', 'message' => 'Payment record not found.']);
                exit;
            }
        }

        if (Auth::isTenant() && $currentUser) {
            $stmt = $pdo->prepare("
                SELECT p.*, u.name AS tenant_name, u.username AS tenant_username, u.move_in_date, un.unit_number
                FROM payments p
                JOIN users u ON p.tenant_id = u.id
                JOIN units un ON p.unit_id = un.id
                WHERE p.tenant_id = :tid
                ORDER BY p.id DESC
            ");
            $stmt->execute([':tid' => $currentUser['id']]);
        } else {
            $stmt = $pdo->query("
                SELECT p.*, u.name AS tenant_name, u.username AS tenant_username, u.move_in_date, un.unit_number
                FROM payments p
                JOIN users u ON p.tenant_id = u.id
                JOIN units un ON p.unit_id = un.id
                WHERE (u.is_archived = FALSE OR u.is_archived IS NULL)
                  AND u.is_active = TRUE
                  AND u.deleted_at IS NULL
                ORDER BY p.id DESC
            ");
        }
        $payments = $stmt->fetchAll();

        echo json_encode([
            'status' => 'success',
            'payments' => $payments
        ]);
        exit;
    }

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'status' => 'error',
        'message' => 'Payment service error: ' . $e->getMessage()
    ]);
}
