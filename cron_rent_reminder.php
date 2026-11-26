<?php
/**
 * TaraRent - Daily Rent Due Reminder Cron Script (2–3 Days Before Due Date)
 *
 * Queries active tenants with unpaid rent balances whose due date falls within
 * the next 2 to 3 days (and who have not yet submitted payment) and sends a
 * branded TaraRent reminder email via the existing PHPMailer/SMTP service.
 * Avoids sending duplicate reminders on the same day.
 *
 * Usage (CLI / Cron / Windows Task Scheduler):
 *   php cron_rent_reminder.php
 */

declare(strict_types=1);

require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/email_service.php';
require_once __DIR__ . '/includes/notification_service.php';

$isCli = (PHP_SAPI === 'cli');
if (!$isCli && !headers_sent()) {
    header('Content-Type: application/json; charset=UTF-8');
}

$pdo = Database::getConnection();
$driver = (string)$pdo->getAttribute(PDO::ATTR_DRIVER_NAME);

$summary = [
    'checked'            => 0,
    'reminders_sent'     => 0,
    'duplicates_skipped' => 0,
    'invalid_email'      => 0,
    'failed'             => 0,
    'errors'             => [],
];

try {
    if ($driver === 'pgsql') {
        $sql = "
            SELECT p.id AS payment_id,
                   p.tenant_id,
                   p.amount_due,
                   COALESCE(p.amount_paid, 0) AS amount_paid,
                   p.due_date,
                   p.billing_cycle,
                   u.name AS tenant_name,
                   u.email AS tenant_email,
                   un.unit_number,
                   (p.due_date - CURRENT_DATE) AS days_remaining
            FROM payments p
            JOIN users u ON p.tenant_id = u.id
            LEFT JOIN units un ON p.unit_id = un.id
            WHERE p.status NOT IN ('paid', 'verified', 'approved', 'pending', 'pending_setup')
              AND (p.amount_due - COALESCE(p.amount_paid, 0)) > 0
              AND p.due_date IS NOT NULL
              AND (p.due_date - CURRENT_DATE) BETWEEN 2 AND 3
              AND u.role = 'tenant'
              AND u.is_active = TRUE
              AND (u.is_archived = FALSE OR u.is_archived IS NULL)
            ORDER BY p.due_date ASC, p.id ASC
        ";
        $sameDayCheckSql = "
            SELECT id FROM email_logs
            WHERE recipient_email = :email
              AND reference_id = :ref_id
              AND reference_type = 'payment'
              AND template_type LIKE 'payment_reminder%'
              AND DATE(sent_at) = CURRENT_DATE
              AND status IN ('sent', 'mock_sent')
            LIMIT 1
        ";
    } else {
        $sql = "
            SELECT p.id AS payment_id,
                   p.tenant_id,
                   p.amount_due,
                   COALESCE(p.amount_paid, 0) AS amount_paid,
                   p.due_date,
                   p.billing_cycle,
                   u.name AS tenant_name,
                   u.email AS tenant_email,
                   un.unit_number,
                   DATEDIFF(p.due_date, CURDATE()) AS days_remaining
            FROM payments p
            JOIN users u ON p.tenant_id = u.id
            LEFT JOIN units un ON p.unit_id = un.id
            WHERE p.status NOT IN ('paid', 'verified', 'approved', 'pending', 'pending_setup')
              AND (p.amount_due - COALESCE(p.amount_paid, 0)) > 0
              AND p.due_date IS NOT NULL
              AND DATEDIFF(p.due_date, CURDATE()) BETWEEN 2 AND 3
              AND u.role = 'tenant'
              AND u.is_active = 1
              AND (u.is_archived = 0 OR u.is_archived IS NULL)
            ORDER BY p.due_date ASC, p.id ASC
        ";
        $sameDayCheckSql = "
            SELECT id FROM email_logs
            WHERE recipient_email = :email
              AND reference_id = :ref_id
              AND reference_type = 'payment'
              AND template_type LIKE 'payment_reminder%'
              AND DATE(sent_at) = CURDATE()
              AND status IN ('sent', 'mock_sent')
            LIMIT 1
        ";
    }

    $stmt = $pdo->query($sql);
    $dupStmt = $pdo->prepare($sameDayCheckSql);

    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $summary['checked']++;
        $paymentId = (int)$row['payment_id'];
        $tenantId  = (int)$row['tenant_id'];
        $email     = trim((string)($row['tenant_email'] ?? ''));
        $name      = trim((string)($row['tenant_name'] ?? 'Tenant')) ?: 'Tenant';
        $unitLabel = !empty($row['unit_number']) ? (string)$row['unit_number'] : 'Assigned Unit';
        $balanceDue = max(0.0, (float)$row['amount_due'] - (float)$row['amount_paid']);
        $dueDate    = (string)$row['due_date'];
        $daysLeft   = (int)$row['days_remaining'];

        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $summary['invalid_email']++;
            continue;
        }

        // Prevent duplicate reminders on the same day for the same payment
        $dupStmt->execute([
            ':email'  => $email,
            ':ref_id' => $paymentId,
        ]);
        if ($dupStmt->fetchColumn()) {
            $summary['duplicates_skipped']++;
            continue;
        }

        $reminderType = ($daysLeft === 2) ? 'upcoming_2days' : 'upcoming_3days';
        $sent = EmailService::notifyTenantPaymentReminder(
            $pdo,
            $email,
            $name,
            $unitLabel,
            $balanceDue,
            $dueDate,
            $reminderType,
            $paymentId,
            $tenantId,
            $daysLeft,
            false // Same-day deduplication already checked above
        );

        if ($sent) {
            $summary['reminders_sent']++;
            NotificationService::notifyTenant(
                $pdo,
                $tenantId,
                'payment',
                "Rent Due in {$daysLeft} Days",
                "Reminder: Your rent balance of ₱" . number_format($balanceDue, 2) . " for {$unitLabel} is due in {$daysLeft} days (" . date('F j, Y', strtotime($dueDate)) . "). Please upload your proof of payment.",
                'tenantPayments',
                $paymentId,
                "payment_upcoming_{$daysLeft}days",
                false
            );
        } else {
            $summary['failed']++;
            $summary['errors'][] = "Failed to send reminder email to {$email} (payment #{$paymentId}).";
        }
    }
} catch (Throwable $e) {
    error_log('[cron_rent_reminder] Error: ' . $e->getMessage());
    $summary['errors'][] = $e->getMessage();
}

if ($isCli) {
    echo "=== TARARENT DAILY RENT DUE REMINDER (2-3 DAYS) ===\n";
    echo "Records Checked:     {$summary['checked']}\n";
    echo "Reminders Sent:      {$summary['reminders_sent']}\n";
    echo "Duplicates Skipped:  {$summary['duplicates_skipped']}\n";
    echo "Invalid Email:       {$summary['invalid_email']}\n";
    echo "Failed:              {$summary['failed']}\n";
    if (!empty($summary['errors'])) {
        echo "Errors:\n";
        foreach ($summary['errors'] as $err) {
            echo " - {$err}\n";
        }
    }
    echo "Finished at " . date('Y-m-d H:i:s') . "\n";
} else {
    echo json_encode([
        'status'    => empty($summary['errors']) ? 'success' : 'partial',
        'summary'   => $summary,
        'timestamp' => date('Y-m-d H:i:s'),
    ]);
}
