<?php
/**
 * TaraRent - Landlord Payment Status Update Script (update_payment.php)
 * Updates payment status in PostgreSQL, fetches the tenant's email address,
 * and triggers an automated email notification to the tenant's Gmail address via PHPMailer.
 */

declare(strict_types=1);

require_once __DIR__ . '/config/auth.php';
require_once __DIR__ . '/includes/email_service.php';
require_once __DIR__ . '/includes/notification_service.php';

$pdo = Database::getConnection();

// Support JSON input, $_POST, or $_GET
$rawInput = json_decode(file_get_contents('php://input'), true);
$input = is_array($rawInput) ? $rawInput : array_merge($_GET, $_POST);

$paymentId = (int)($input['payment_id'] ?? ($input['id'] ?? 0));
$statusInput = trim((string)($input['status'] ?? ($input['decision'] ?? 'Approved')));
$reason = trim((string)($input['reason'] ?? ($input['notes'] ?? ($input['verification_notes'] ?? ''))));

if ($paymentId <= 0) {
    if (PHP_SAPI !== 'cli') {
        header('Content-Type: application/json; charset=UTF-8');
        http_response_code(422);
    }
    echo json_encode(['status' => 'error', 'message' => 'Invalid payment ID.']);
    exit;
}

// Normalize status
$statusLower = strtolower($statusInput);
if (in_array($statusLower, ['approved', 'verified'], true)) {
    $dbStatus = 'approved';
    $statusDisplay = 'Approved';
    $verifiedFlag = 'TRUE';
} elseif (in_array($statusLower, ['rejected', 'reject'], true)) {
    $dbStatus = 'rejected';
    $statusDisplay = 'Rejected';
    $verifiedFlag = 'FALSE';
} else {
    $dbStatus = $statusInput;
    $statusDisplay = ucfirst($statusInput);
    $verifiedFlag = 'FALSE';
}

// 1. Fetch current payment record
$check = $pdo->prepare("SELECT id, tenant_id, payment_type, billing_cycle, due_date, amount_due, amount_paid, status, verification_notes FROM payments WHERE id = :id LIMIT 1");
$check->execute([':id' => $paymentId]);
$payment = $check->fetch(PDO::FETCH_ASSOC);

if (!$payment) {
    if (PHP_SAPI !== 'cli') {
        header('Content-Type: application/json; charset=UTF-8');
        http_response_code(404);
    }
    echo json_encode(['status' => 'error', 'message' => 'Payment record not found.']);
    exit;
}

$tenantId = (int)($payment['tenant_id'] ?? 0);
$defaultNotes = ($statusDisplay === 'Approved') ? 'Payment receipt approved & verified.' : 'Payment proof rejected.';
$finalNotes = !empty($reason) ? $reason : $defaultNotes;

// 2. Execute PostgreSQL UPDATE query
$updatePaidSql = "";
if ($statusDisplay === 'Approved') {
    $updatePaidSql = ", amount_paid = CASE WHEN amount_paid <= 0 THEN COALESCE(amount_due, 3000.00) ELSE amount_paid END";
}

$stmt = $pdo->prepare("
    UPDATE payments 
    SET status = :status,
        verified_by_landlord = {$verifiedFlag},
        verification_notes = :notes,
        verified_at = NOW(),
        payment_date = COALESCE(payment_date, CURRENT_DATE)
        {$updatePaidSql}
    WHERE id = :id
");
$stmt->execute([
    ':status' => $dbStatus,
    ':notes'  => $finalNotes,
    ':id'     => $paymentId
]);

// 3. Fetch tenant's email address from database using tenant_id
$emailSent = false;
$tenantEmail = '';
$tenantName = 'Tenant';

if ($tenantId > 0) {
    $tenantStmt = $pdo->prepare("SELECT id, name, email FROM users WHERE id = :id LIMIT 1");
    $tenantStmt->execute([':id' => $tenantId]);
    $tenantUser = $tenantStmt->fetch(PDO::FETCH_ASSOC);

    if ($tenantUser && !empty($tenantUser['email'])) {
        $tenantEmail = trim((string)$tenantUser['email']);
        $tenantName = trim((string)($tenantUser['name'] ?? 'Tenant'));

        $amtVal = (float)($payment['amount_paid'] > 0 ? $payment['amount_paid'] : ($payment['amount_due'] ?? 3000.00));
        $formattedAmount = '₱' . number_format($amtVal, 2);

        $rawCycle = (string)($payment['billing_cycle'] ?? '');
        $billingMonth = (!empty($rawCycle) && strtotime($rawCycle) !== false) ? date('F Y', strtotime($rawCycle)) : ($rawCycle ?: date('F Y'));

        // 4. Call mailer function: "Your payment of [Amount] for [Billing Month] has been [Status]."
        // If rejected, include the landlord's reason if provided.
        $messageBody = "Your payment of <strong>{$formattedAmount}</strong> for <strong>{$billingMonth}</strong> has been <strong>{$statusDisplay}</strong>.";
        if ($statusDisplay === 'Rejected' && !empty($finalNotes)) {
            $messageBody .= "<br><br><strong>Reason:</strong> " . htmlspecialchars($finalNotes, ENT_QUOTES, 'UTF-8');
        }

        $subject = "Payment {$statusDisplay} - {$billingMonth}";
        $details = [
            'Amount'        => $formattedAmount,
            'Billing Month' => $billingMonth,
            'Status'        => $statusDisplay,
        ];
        if (!empty($finalNotes)) {
            $details['Notes / Reason'] = $finalNotes;
        }

        $config = MailConfig::get();
        $actionUrl = $config['app_url'] . '/tenant_portal.php?section=tenantPayments&tab=tracker';

        try {
            $emailSent = sendTenantNotification(
                $tenantEmail,
                $subject,
                $messageBody,
                $tenantName,
                $details,
                'View Payment Tracker',
                $actionUrl,
                $tenantId,
                $pdo
            );
        } catch (Throwable $mailEx) {
            error_log('[update_payment] Email dispatch failed: ' . $mailEx->getMessage());
        }

        // In-app notification
        $notifTitle = "Payment {$statusDisplay}";
        $notifMsg = ($statusDisplay === 'Approved')
            ? "Your payment of {$formattedAmount} for {$billingMonth} has been approved."
            : "Your payment of {$formattedAmount} for {$billingMonth} was rejected. Reason: {$finalNotes}";
        NotificationService::notifyTenant(
            $pdo,
            $tenantId,
            'payment',
            $notifTitle,
            $notifMsg,
            'tenantPayments',
            $paymentId,
            'payment_' . strtolower($statusDisplay)
        );
    }
}

if (PHP_SAPI !== 'cli') {
    header('Content-Type: application/json; charset=UTF-8');
}
echo json_encode([
    'status'          => 'success',
    'message'         => "Payment #{$paymentId} status updated to {$statusDisplay}.",
    'email_sent'      => $emailSent,
    'recipient_email' => $tenantEmail,
    'payment'         => [
        'id'                 => $paymentId,
        'status'             => $dbStatus,
        'verification_notes' => $finalNotes
    ]
]);
