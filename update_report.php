<?php
/**
 * TaraRent - Landlord Report Status Update Script (update_report.php)
 * Updates general report status in PostgreSQL, fetches the tenant's email address,
 * and triggers an automated email notification to the tenant's Gmail address via PHPMailer.
 */

declare(strict_types=1);

require_once __DIR__ . '/config/auth.php';
require_once __DIR__ . '/includes/email_service.php';
require_once __DIR__ . '/includes/notification_service.php';
require_once __DIR__ . '/includes/sms_service.php';

$pdo = Database::getConnection();

$rawInput = json_decode(file_get_contents('php://input'), true);
$input = is_array($rawInput) ? $rawInput : array_merge($_GET, $_POST);

$reportId = (int)($input['report_id'] ?? ($input['id'] ?? 0));
$rawStatus = trim((string)($input['status'] ?? ''));
$remarks = trim((string)($input['owner_remarks'] ?? ($input['remarks'] ?? ($input['notes'] ?? ''))));

if ($reportId <= 0) {
    if (PHP_SAPI !== 'cli') {
        header('Content-Type: application/json; charset=UTF-8');
        http_response_code(422);
    }
    echo json_encode(['status' => 'error', 'message' => 'Invalid report ID.']);
    exit;
}

// Fetch existing report and tenant info
$reportStmt = $pdo->prepare("
    SELECT tr.*, u.name AS tenant_name, u.email AS tenant_email, u.phone AS tenant_phone
    FROM tenant_reports tr
    JOIN users u ON tr.tenant_id = u.id
    WHERE tr.id = :id
    LIMIT 1
");
$reportStmt->execute([':id' => $reportId]);
$reportRow = $reportStmt->fetch(PDO::FETCH_ASSOC);

if (!$reportRow) {
    if (PHP_SAPI !== 'cli') {
        header('Content-Type: application/json; charset=UTF-8');
        http_response_code(404);
    }
    echo json_encode(['status' => 'error', 'message' => 'Tenant report not found.']);
    exit;
}

// Normalize status
$statusMap = [
    'pending'      => 'Pending',
    'open'         => 'Pending',
    'in_progress'  => 'In Progress',
    'ongoing'      => 'In Progress',
    'on-going'     => 'In Progress',
    'completed'    => 'Completed',
    'done'         => 'Completed',
    'approved'     => 'Approved',
    'approve'      => 'Approved',
    'rejected'     => 'Rejected',
    'reject'       => 'Rejected',
    'acknowledged' => 'Acknowledged',
    'acknowledge'  => 'Acknowledged',
];
$statusKey = strtolower($rawStatus);
$statusToSave = $statusMap[$statusKey] ?? (!empty($rawStatus) ? ucfirst($rawStatus) : ($reportRow['status'] ?: 'Pending'));
$remarksToSave = $remarks !== '' ? $remarks : ($reportRow['owner_remarks'] ?? null);
$reviewedAt = ($statusToSave === 'Pending') ? null : date('Y-m-d H:i:s');

// Update PostgreSQL
$updStmt = $pdo->prepare("
    UPDATE tenant_reports
    SET status = :status,
        owner_remarks = :owner_remarks,
        reviewed_at = :reviewed_at
    WHERE id = :id
");
$updStmt->execute([
    ':status'        => $statusToSave,
    ':owner_remarks' => $remarksToSave,
    ':reviewed_at'   => $reviewedAt,
    ':id'            => $reportId
]);

if (!empty($reportRow['tenant_phone']) && in_array(strtolower($statusToSave), ['approved', 'rejected'], true)) {
    $tenantName = (string)($reportRow['tenant_name'] ?? 'Tenant');
    $smsReportMsg = "TaraRent Notice: Hello {$tenantName}, your report regarding '{$reportRow['subject']}' has been {$statusToSave} by the property owner." . (!empty($remarksToSave) ? " Remarks: {$remarksToSave}" : "");
    
    SmsService::send((string)$reportRow['tenant_phone'], $smsReportMsg);
}
// Dispatch email notification to Tenant
$emailSent = false;
$tenantId = (int)$reportRow['tenant_id'];
$tenantEmail = trim((string)($reportRow['tenant_email'] ?? ''));
$reportSubject = (string)$reportRow['subject'];

if (!empty($tenantEmail) && filter_var($tenantEmail, FILTER_VALIDATE_EMAIL)) {
    // Exact requirement: "The status of your [Maintenance/Report] request regarding '[Issue/Title]' has been updated to: [New Status]."
    $emailTitle = "Report Request Update: {$reportSubject}";
    $emailMessage = "The status of your Report request regarding '<strong>" . htmlspecialchars($reportSubject, ENT_QUOTES, 'UTF-8') . "</strong>' has been updated to: <strong>{$statusToSave}</strong>.";
    if (!empty($remarksToSave)) {
        $emailMessage .= "<br><br><strong>Landlord Remarks:</strong> " . htmlspecialchars($remarksToSave, ENT_QUOTES, 'UTF-8');
    }

    try {
        $emailSent = sendTenantNotification(
            $tenantEmail,
            $emailTitle,
            $emailMessage,
            (string)($reportRow['tenant_name'] ?? 'Tenant'),
            [
                'Request Type'     => 'General Report',
                'Report Subject'   => $reportSubject,
                'Updated Status'   => $statusToSave,
                'Landlord Remarks' => $remarksToSave ?: 'Reviewed by property management.'
            ],
            'View Report History',
            MailConfig::get()['app_url'] . '/tenant_portal.php?section=tenantReport',
            $tenantId,
            $pdo
        );
    } catch (Throwable $mailEx) {
        error_log('[update_report] Email error: ' . $mailEx->getMessage());
    }
}

// In-app notification
$notifTitle = "General Report {$statusToSave}";
$notifMsg = "Your report #GR-" . str_pad((string)$reportId, 4, '0', STR_PAD_LEFT)
    . " ('{$reportSubject}') has been marked as {$statusToSave}.";
if (!empty($remarksToSave)) {
    $notifMsg .= " Owner remarks: {$remarksToSave}";
}

NotificationService::notifyTenant(
    $pdo,
    $tenantId,
    'report',
    $notifTitle,
    $notifMsg,
    'tenantReport',
    $reportId,
    'report_' . strtolower(str_replace(['-', ' '], '_', $statusToSave)),
    false
);

if (PHP_SAPI !== 'cli') {
    header('Content-Type: application/json; charset=UTF-8');
}
echo json_encode([
    'status'          => 'success',
    'message'         => "Report #GR-" . str_pad((string)$reportId, 4, '0', STR_PAD_LEFT) . " marked as {$statusToSave}.",
    'email_sent'      => $emailSent,
    'recipient_email' => $tenantEmail,
    'report'          => [
        'id'            => $reportId,
        'status'        => $statusToSave,
        'owner_remarks' => $remarksToSave
    ]
]);
