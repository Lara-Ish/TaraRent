<?php
/**
 * RentConnect - Scheduled Email Reminders Script (Cron Job)
 * Executes periodic checks for upcoming due dates, due-today notices, overdue alerts, and lease expirations.
 *
 * Schedule:
 *   1. 3 days before due date
 *   2. 1 day before due date
 *   3. On the due date
 *   4. When the rent becomes overdue
 *
 * Excludes:
 *   - Tenants who have already paid or whose payments are already verified (amount_paid > 0 or status in 'paid', 'verified')
 *   - Placeholder rent records (pending_setup)
 *
 * Usage via CLI:
 *   php cron/send_reminders.php
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/config/db.php';
require_once dirname(__DIR__) . '/includes/email_service.php';
require_once dirname(__DIR__) . '/includes/notification_service.php';

require_once dirname(__DIR__) . '/includes/sms_service.php';

$isCli = (php_sapi_name() === 'cli');
if (!$isCli) {
    header('Content-Type: application/json; charset=UTF-8');
}

$pdo = Database::getConnection();

$summary = [
    'sms_reminders_sent'   => 0,
    'sms_reminders_failed' => 0,
    'upcoming_3days_sent'  => 0,
    'upcoming_1day_sent'   => 0,
    'due_today_sent'       => 0,
    'overdue_sent'         => 0,
    'lease_sent'           => 0,
    'duplicates_skipped'   => 0,
    'errors'               => []
];

// -----------------------------------------------------------------------------
// SMS Due Date Reminders (2-3 Days Before Due Date)
// -----------------------------------------------------------------------------
try {
    $smsDueStmt = $pdo->prepare("
        SELECT p.id, p.tenant_id, p.amount_due, p.due_date, p.billing_cycle,
               u.name AS tenant_name, u.phone AS tenant_phone, un.unit_number,
               (p.due_date - CURRENT_DATE) AS days_remaining
        FROM payments p
        JOIN users u ON p.tenant_id = u.id
        LEFT JOIN units un ON p.unit_id = un.id
        WHERE p.status NOT IN ('paid', 'verified', 'approved', 'pending_setup')
          AND p.amount_paid = 0
          AND p.due_date IS NOT NULL
          AND (p.due_date - CURRENT_DATE) BETWEEN 2 AND 3
          AND u.is_active = TRUE
          AND u.phone IS NOT NULL
          AND TRIM(u.phone) != ''
    ");
    $smsDueStmt->execute();
    while ($row = $smsDueStmt->fetch(PDO::FETCH_ASSOC)) {
        $daysLeft = (int)$row['days_remaining'];
        $amountFormatted = number_format((float)($row['amount_due'] ?? 3000.00), 2);
        $dueDateFormatted = date('M j, Y', strtotime((string)$row['due_date']));
        $unitLabel = !empty($row['unit_number']) ? "Unit " . $row['unit_number'] : "your unit";
        $smsMessage = "TaraRent Notice: Hello {$row['tenant_name']}, this is a reminder that your rent payment of PHP {$amountFormatted} for {$unitLabel} is due in {$daysLeft} days ({$dueDateFormatted}). Please settle on or before your due date. Thank you!";
        
        // Dispatch via sanitized iProg SMS API
        $smsResponse = sendIprogSMS((string)$row['tenant_phone'], $smsMessage);

        if (!($smsResponse['success'] ?? false)) {
            $failureMsg = $smsResponse['message'] ?? 'Unknown SMS API error';
            error_log("[cron/send_reminders] SMS 2-3 day reminder failed for tenant '{$row['tenant_name']}' ({$row['tenant_phone']}): {$failureMsg}");
            $summary['sms_reminders_failed']++;
            $summary['errors'][] = "SMS to {$row['tenant_name']} failed: {$failureMsg}";
        } else {
            $summary['sms_reminders_sent']++;
        }

        // Loop delay (500ms / 0.5s) to avoid rate-limiting during bulk sending
        usleep(500000);
    }
} catch (Throwable $e) {
    error_log('[cron/send_reminders] SMS 2-3 day reminder scan error: ' . $e->getMessage());
    $summary['errors'][] = 'SMS 2-3 day reminder scan error: ' . $e->getMessage();
}

// -----------------------------------------------------------------------------
// 1. Upcoming Payment Reminders (2–3 Days Before Due Date)
// -----------------------------------------------------------------------------
try {
    $upcoming3Stmt = $pdo->prepare("
        SELECT p.id, p.tenant_id, p.amount_due, COALESCE(p.amount_paid, 0) AS amount_paid, p.due_date, p.billing_cycle,
               u.name as tenant_name, u.email as tenant_email, un.unit_number,
               (p.due_date - CURRENT_DATE) AS days_remaining
        FROM payments p
        JOIN users u ON p.tenant_id = u.id
        LEFT JOIN units un ON p.unit_id = un.id
        WHERE p.status NOT IN ('paid', 'verified', 'approved', 'pending', 'pending_setup')
          AND (p.amount_due - COALESCE(p.amount_paid, 0)) > 0
          AND p.due_date IS NOT NULL
          AND (p.due_date - CURRENT_DATE) BETWEEN 2 AND 3
          AND u.is_active = TRUE
          AND (u.is_archived = FALSE OR u.is_archived IS NULL)
    ");
    $upcoming3Stmt->execute();
    while ($row = $upcoming3Stmt->fetch(PDO::FETCH_ASSOC)) {
        $pid = (int)$row['id'];
        $tid = (int)$row['tenant_id'];
        $daysLeft = (int)$row['days_remaining'];
        $balanceDue = max(0.0, (float)$row['amount_due'] - (float)$row['amount_paid']);

        $chk = $pdo->prepare("
            SELECT id FROM email_logs
            WHERE recipient_email = :e
              AND template_type LIKE 'payment_reminder%'
              AND reference_id = :ref
              AND reference_type = 'payment'
              AND DATE(sent_at) = CURRENT_DATE
              AND status IN ('sent', 'mock_sent')
            LIMIT 1
        ");
        $chk->execute([':e' => $row['tenant_email'], ':ref' => $pid]);
        if ($chk->fetch()) {
            $summary['duplicates_skipped']++;
            continue;
        }

        $reminderType = ($daysLeft === 2) ? 'upcoming_2days' : 'upcoming_3days';
        $sent = EmailService::notifyTenantPaymentReminder(
            $pdo,
            (string)$row['tenant_email'],
            (string)$row['tenant_name'],
            $row['unit_number'] ?: 'Unit',
            $balanceDue,
            (string)$row['due_date'],
            $reminderType,
            $pid,
            $tid,
            $daysLeft,
            false
        );

        if ($sent) {
            $summary['upcoming_3days_sent']++;
            NotificationService::notifyTenant(
                $pdo,
                $tid,
                'payment',
                'Upcoming Rental Payment Reminder',
                "Reminder: Your rent payment of ₱" . number_format($balanceDue, 2) . " for " . ($row['unit_number'] ?: 'your unit') . " is due in {$daysLeft} days (" . date('F j, Y', strtotime((string)$row['due_date'])) . ").",
                'tenantPayments',
                $pid,
                "payment_upcoming_{$daysLeft}days",
                false
            );
        }
    }
} catch (Throwable $e) {
    $summary['errors'][] = 'Upcoming 2-3 day scan error: ' . $e->getMessage();
}

// -----------------------------------------------------------------------------
// 2. Upcoming Payment Reminders (1 Day Before Due Date)
// -----------------------------------------------------------------------------
try {
    $upcoming1Stmt = $pdo->prepare("
        SELECT p.id, p.tenant_id, p.amount_due, p.due_date, p.billing_cycle,
               u.name as tenant_name, u.email as tenant_email, un.unit_number
        FROM payments p
        JOIN users u ON p.tenant_id = u.id
        LEFT JOIN units un ON p.unit_id = un.id
        WHERE p.status NOT IN ('paid', 'verified', 'pending_setup')
          AND p.amount_paid = 0
          AND p.due_date IS NOT NULL
          AND (p.due_date - CURRENT_DATE) = 1
          AND u.is_active = TRUE
    ");
    $upcoming1Stmt->execute();
    while ($row = $upcoming1Stmt->fetch(PDO::FETCH_ASSOC)) {
        $pid = (int)$row['id'];
        $tid = (int)$row['tenant_id'];

        $chk = $pdo->prepare("SELECT id FROM email_logs WHERE recipient_email = :e AND template_type = 'payment_reminder_upcoming_1day' AND reference_id = :ref LIMIT 1");
        $chk->execute([':e' => $row['tenant_email'], ':ref' => $pid]);
        if ($chk->fetch()) {
            $summary['duplicates_skipped']++;
            continue;
        }

        $sent = EmailService::notifyTenantPaymentReminder(
            $pdo,
            $row['tenant_email'],
            $row['tenant_name'],
            $row['unit_number'] ?: 'Unit',
            (float)$row['amount_due'],
            $row['due_date'],
            'upcoming_1day',
            $pid,
            $tid
        );

        if ($sent) {
            $summary['upcoming_1day_sent']++;
            NotificationService::notifyTenant(
                $pdo,
                $tid,
                'payment',
                'Rental Payment Due Tomorrow',
                "Reminder: Your rent payment of ₱" . number_format((float)$row['amount_due'], 2) . " for " . ($row['unit_number'] ?: 'your unit') . " is due tomorrow (" . date('F j, Y', strtotime($row['due_date'])) . ").",
                'tenantPayments',
                $pid,
                'payment_upcoming_1day',
                false
            );
        }
    }
} catch (Throwable $e) {
    $summary['errors'][] = 'Upcoming 1-day scan error: ' . $e->getMessage();
}

// -----------------------------------------------------------------------------
// 3. Payment Due Today Reminders
// -----------------------------------------------------------------------------
try {
    $dueTodayStmt = $pdo->prepare("
        SELECT p.id, p.tenant_id, p.amount_due, p.due_date, p.billing_cycle,
               u.name as tenant_name, u.email as tenant_email, un.unit_number
        FROM payments p
        JOIN users u ON p.tenant_id = u.id
        LEFT JOIN units un ON p.unit_id = un.id
        WHERE p.status NOT IN ('paid', 'verified', 'pending_setup')
          AND p.amount_paid = 0
          AND p.due_date IS NOT NULL
          AND p.due_date = CURRENT_DATE
          AND u.is_active = TRUE
    ");
    $dueTodayStmt->execute();
    while ($row = $dueTodayStmt->fetch(PDO::FETCH_ASSOC)) {
        $pid = (int)$row['id'];
        $tid = (int)$row['tenant_id'];

        $chk = $pdo->prepare("SELECT id FROM email_logs WHERE recipient_email = :e AND template_type = 'payment_reminder_due_today' AND reference_id = :ref LIMIT 1");
        $chk->execute([':e' => $row['tenant_email'], ':ref' => $pid]);
        if ($chk->fetch()) {
            $summary['duplicates_skipped']++;
            continue;
        }

        $sent = EmailService::notifyTenantPaymentReminder(
            $pdo,
            $row['tenant_email'],
            $row['tenant_name'],
            $row['unit_number'] ?: 'Unit',
            (float)$row['amount_due'],
            $row['due_date'],
            'due_today',
            $pid,
            $tid
        );

        if ($sent) {
            $summary['due_today_sent']++;
            NotificationService::notifyTenant(
                $pdo,
                $tid,
                'payment',
                'Rental Payment Due Today',
                "Your rent payment of ₱" . number_format((float)$row['amount_due'], 2) . " for " . ($row['unit_number'] ?: 'your unit') . " is due today (" . date('F j, Y', strtotime($row['due_date'])) . "). Please submit your payment.",
                'tenantPayments',
                $pid,
                'payment_due_today',
                false
            );
        }
    }
} catch (Throwable $e) {
    $summary['errors'][] = 'Due today scan error: ' . $e->getMessage();
}

// -----------------------------------------------------------------------------
// 4. Overdue Payment Reminders
// -----------------------------------------------------------------------------
try {
    $overdueStmt = $pdo->prepare("
        SELECT p.id, p.tenant_id, p.amount_due, p.due_date, p.billing_cycle,
               u.name as tenant_name, u.email as tenant_email, un.unit_number
        FROM payments p
        JOIN users u ON p.tenant_id = u.id
        LEFT JOIN units un ON p.unit_id = un.id
        WHERE (p.status = 'overdue' OR (p.status = 'pending' AND p.due_date < CURRENT_DATE))
          AND p.status NOT IN ('paid', 'verified', 'pending_setup')
          AND p.amount_paid = 0
          AND p.due_date IS NOT NULL
          AND u.is_active = TRUE
    ");
    $overdueStmt->execute();
    while ($row = $overdueStmt->fetch(PDO::FETCH_ASSOC)) {
        $pid = (int)$row['id'];
        $tid = (int)$row['tenant_id'];

        // Only send overdue reminder once per 3 days per payment
        $chk = $pdo->prepare("
            SELECT id FROM email_logs 
            WHERE recipient_email = :e 
              AND template_type = 'payment_reminder_overdue' 
              AND reference_id = :ref 
              AND sent_at >= (NOW() - INTERVAL '3 DAY')
            LIMIT 1
        ");
        $chk->execute([':e' => $row['tenant_email'], ':ref' => $pid]);
        if ($chk->fetch()) {
            $summary['duplicates_skipped']++;
            continue;
        }

        $sent = EmailService::notifyTenantPaymentReminder(
            $pdo,
            $row['tenant_email'],
            $row['tenant_name'],
            $row['unit_number'] ?: 'Unit',
            (float)$row['amount_due'],
            $row['due_date'],
            'overdue',
            $pid,
            $tid
        );

        if ($sent) {
            $summary['overdue_sent']++;
            NotificationService::notifyTenant(
                $pdo,
                $tid,
                'payment',
                'Overdue Payment Alert',
                "Your rent payment of ₱" . number_format((float)$row['amount_due'], 2) . " for " . ($row['unit_number'] ?: 'your unit') . " is overdue. Please submit your payment promptly.",
                'tenantPayments',
                $pid,
                'payment_overdue',
                false
            );
        }
    }
} catch (Throwable $e) {
    $summary['errors'][] = 'Overdue scan error: ' . $e->getMessage();
}

// -----------------------------------------------------------------------------
// 5. Upcoming Lease Expiration Reminders (30 Days Remaining)
// -----------------------------------------------------------------------------
try {
    $leaseStmt = $pdo->query("
        SELECT s.active_tenant_id, s.lease_end, u.name as tenant_name, u.email as tenant_email, un.unit_number
        FROM apartment_settings s
        JOIN users u ON s.active_tenant_id = u.id
        LEFT JOIN units un ON u.unit_id = un.id
        WHERE s.lease_end IS NOT NULL
          AND (s.lease_end - CURRENT_DATE) = 30
          AND u.is_active = TRUE
    ");
    while ($row = $leaseStmt->fetch(PDO::FETCH_ASSOC)) {
        $tid = (int)$row['active_tenant_id'];

        $chk = $pdo->prepare("SELECT id FROM email_logs WHERE recipient_email = :e AND template_type = 'lease_expiring_30' AND reference_id = :ref LIMIT 1");
        $chk->execute([':e' => $row['tenant_email'], ':ref' => $tid]);
        if ($chk->fetch()) {
            $summary['duplicates_skipped']++;
            continue;
        }

        $sent = EmailService::notifyTenantLeaseExpiring(
            $pdo,
            $row['tenant_email'],
            $row['tenant_name'],
            $row['unit_number'] ?: 'Unit',
            $row['lease_end'],
            30,
            $tid,
            $tid
        );

        if ($sent) {
            $summary['lease_sent']++;
            NotificationService::notifyTenant(
                $pdo,
                $tid,
                'account',
                'Upcoming Lease Expiration Notice',
                "Your apartment lease is scheduled to end in 30 days (" . date('F j, Y', strtotime($row['lease_end'])) . ").",
                'tenantProfile',
                $tid,
                'lease_expiring',
                false
            );
        }
    }
} catch (Throwable $e) {
    $summary['errors'][] = 'Lease expiration scan error: ' . $e->getMessage();
}

if ($isCli) {
    echo "=== TARARENT SCHEDULED REMINDERS SUMMARY ===\n";
    echo "SMS Reminders Sent:    {$summary['sms_reminders_sent']}\n";
    echo "SMS Reminders Failed:  {$summary['sms_reminders_failed']}\n";
    echo "Upcoming (3 Days):     {$summary['upcoming_3days_sent']}\n";
    echo "Upcoming (1 Day):      {$summary['upcoming_1day_sent']}\n";
    echo "Due Today Sent:        {$summary['due_today_sent']}\n";
    echo "Overdue Notices Sent:  {$summary['overdue_sent']}\n";
    echo "Lease Notices Sent:    {$summary['lease_sent']}\n";
    echo "Duplicates Skipped:    {$summary['duplicates_skipped']}\n";
    if (!empty($summary['errors'])) {
        echo "Errors encountered:\n";
        foreach ($summary['errors'] as $err) {
            echo " - $err\n";
        }
    }
    echo "Completed successfully at " . date('Y-m-d H:i:s') . "\n";
} else {
    echo json_encode(['status' => 'success', 'summary' => $summary, 'timestamp' => date('Y-m-d H:i:s')]);
}
