<?php
/**
 * TaraRent - Landlord Maintenance Status Update Script (update_maintenance.php)
 * Updates maintenance ticket status in PostgreSQL, fetches the tenant's email address,
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

$ticketId = (int)($input['ticket_id'] ?? ($input['id'] ?? 0));
$rawStatus = trim((string)($input['status'] ?? ''));
$notes = trim((string)($input['notes'] ?? ($input['reply'] ?? ($input['resolution_notes'] ?? ''))));

if ($ticketId <= 0) {
    if (PHP_SAPI !== 'cli') {
        header('Content-Type: application/json; charset=UTF-8');
        http_response_code(422);
    }
    echo json_encode(['status' => 'error', 'message' => 'Invalid ticket ID.']);
    exit;
}

// Fetch existing ticket details and tenant info
$ticketStmt = $pdo->prepare("
    SELECT mt.*, u.name AS tenant_name, u.email AS tenant_email, u.phone AS tenant_phone
    FROM maintenance_tickets mt
    JOIN users u ON mt.tenant_id = u.id
    WHERE mt.id = :id
    LIMIT 1
");
$ticketStmt->execute([':id' => $ticketId]);
$ticketRow = $ticketStmt->fetch(PDO::FETCH_ASSOC);

if (!$ticketRow) {
    if (PHP_SAPI !== 'cli') {
        header('Content-Type: application/json; charset=UTF-8');
        http_response_code(404);
    }
    echo json_encode(['status' => 'error', 'message' => 'Maintenance ticket not found.']);
    exit;
}

// Completed maintenance requests are read-only and immutable
$existingStatusNorm = strtolower(trim((string)($ticketRow['status'] ?? '')));
if (in_array($existingStatusNorm, ['completed', 'done', 'resolved', 'closed'], true)) {
    if (PHP_SAPI !== 'cli') {
        header('Content-Type: application/json; charset=UTF-8');
        http_response_code(403);
    }
    echo json_encode(['status' => 'error', 'message' => 'Completed maintenance requests are read-only and cannot be modified.']);
    exit;
}

// Normalize status
$statusMap = [
    'pending'     => 'Pending',
    'open'        => 'Pending',
    'in_progress' => 'In Progress',
    'ongoing'     => 'In Progress',
    'on-going'    => 'In Progress',
    'completed'   => 'Completed',
    'done'        => 'Completed',
    'resolved'    => 'Completed',
    'rejected'    => 'Rejected',
];
$statusKey = strtolower($rawStatus);
$statusToSave = $statusMap[$statusKey] ?? (!empty($rawStatus) ? ucfirst($rawStatus) : ($ticketRow['status'] ?: 'Pending'));
$replyToSave = !empty($notes) ? $notes : ($ticketRow['resolution_notes'] ?? '');
$isResolved = in_array(strtolower($statusToSave), ['completed', 'done', 'resolved'], true);
$resolvedAt = $isResolved ? ($ticketRow['resolved_at'] ?: date('Y-m-d H:i:s')) : null;

// Update PostgreSQL
$updStmt = $pdo->prepare("
    UPDATE maintenance_tickets 
    SET status = :status,
        resolution_notes = :resolution_notes,
        resolved_at = :resolved_at
    WHERE id = :id
");
$updStmt->execute([
    ':status'           => $statusToSave,
    ':resolution_notes' => $replyToSave,
    ':resolved_at'      => $resolvedAt,
    ':id'               => $ticketId
]);
// Dispatch SMS Notification if maintenance ticket is marked as Done
if (in_array(strtolower($statusToSave), ['done', 'completed', 'resolved'], true) && !empty($ticketRow['tenant_phone'])) {
    $tenantName = (string)($ticketRow['tenant_name'] ?? 'Tenant');
    $ticketTitle = (string)$ticketRow['title'];
    $smsTicketMsg = "TaraRent Notice: Hello {$tenantName}, your maintenance request regarding '{$ticketTitle}' has been completed and marked as DONE." . (!empty($replyToSave) ? " Landlord Notes: {$replyToSave}" : "");
    
    SmsService::send((string)$ticketRow['tenant_phone'], $smsTicketMsg);
}
// Dispatch email notification to Tenant
$emailSent = false;
$tenantId = (int)$ticketRow['tenant_id'];
$tenantEmail = trim((string)($ticketRow['tenant_email'] ?? ''));
$issueTitle = (string)$ticketRow['title'];

if (!empty($tenantEmail) && filter_var($tenantEmail, FILTER_VALIDATE_EMAIL)) {
    try {
        $emailSent = EmailService::notifyTenantMaintenanceStatusUpdate(
            $pdo,
            $tenantId,
            $issueTitle,
            $statusToSave,
            $replyToSave,
            $ticketId
        );
    } catch (Throwable $mailEx) {
        error_log('[update_maintenance] Email error: ' . $mailEx->getMessage());
    }
}

// In-app notification
$statusLabel = $statusToSave;
$titleText = $isResolved ? 'Maintenance Request Completed' : 'Maintenance Ticket Updated (' . $statusLabel . ')';
$msgText = !empty($replyToSave)
    ? "Owner updated ticket #{$ticketId} ('{$issueTitle}') to {$statusLabel}: {$replyToSave}"
    : "The owner updated maintenance ticket #{$ticketId} ('{$issueTitle}') to status: {$statusLabel}.";

NotificationService::notifyTenant(
    $pdo,
    $tenantId,
    'maintenance',
    $titleText,
    $msgText,
    'tenantMaintenance',
    $ticketId,
    'ticket_status_' . strtolower(str_replace(['-', ' '], '_', $statusToSave)),
    false
);

if (PHP_SAPI !== 'cli') {
    header('Content-Type: application/json; charset=UTF-8');
}
echo json_encode([
    'status'          => 'success',
    'message'         => "Ticket #{$ticketId} status updated to '{$statusToSave}'.",
    'email_sent'      => $emailSent,
    'recipient_email' => $tenantEmail,
    'ticket'          => [
        'id'               => $ticketId,
        'status'           => $statusToSave,
        'resolution_notes' => $replyToSave
    ]
]);
