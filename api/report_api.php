<?php
/**
 * TaraRent - General Tenant Reports API Endpoint
 * Strictly separated from maintenance_tickets repair workflows.
 * Handles general tenant requests (occupants, pets, unit customizations, inquiries)
 * and Landlord/Owner approval, rejection, and acknowledgement actions.
 */

declare(strict_types=1);

header('Content-Type: application/json; charset=UTF-8');

require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../includes/notification_service.php';
require_once __DIR__ . '/../includes/email_service.php';
require_once __DIR__ . '/../includes/sms_service.php';

$requestMethod = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($requestMethod === 'OPTIONS') {
    http_response_code(200);
    exit;
}

if (!Auth::check()) {
    http_response_code(401);
    echo json_encode(['status' => 'error', 'message' => 'Authentication required.']);
    exit;
}

$pdo = Database::getConnection();

/**
 * Ensure tenant_reports table exists in case endpoint is invoked before manual migration.
 */
function ensureTenantReportsTable(PDO $pdo): void
{
    try {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS tenant_reports (
                id SERIAL PRIMARY KEY,
                tenant_id INT NOT NULL,
                unit_id INT NOT NULL,
                category VARCHAR(80) NOT NULL,
                subject VARCHAR(180) NOT NULL,
                details TEXT NOT NULL,
                attachment_path VARCHAR(1000) NULL DEFAULT NULL,
                status VARCHAR(30) NOT NULL DEFAULT 'Pending',
                owner_remarks TEXT NULL DEFAULT NULL,
                reviewed_by INT NULL DEFAULT NULL,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                reviewed_at TIMESTAMP NULL DEFAULT NULL,
                updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                CONSTRAINT fk_tr_tenant FOREIGN KEY (tenant_id) REFERENCES users (id) ON DELETE CASCADE ON UPDATE CASCADE,
                CONSTRAINT fk_tr_unit FOREIGN KEY (unit_id) REFERENCES units (id) ON DELETE CASCADE ON UPDATE CASCADE
            )
        ");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_tr_tenant ON tenant_reports (tenant_id)");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_tr_unit ON tenant_reports (unit_id)");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_tr_status ON tenant_reports (status)");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_tr_created ON tenant_reports (created_at)");
    } catch (Throwable $e) {}
}

try {
    ensureTenantReportsTable($pdo);

    if ($requestMethod === 'POST') {
        $rawInput = json_decode(file_get_contents('php://input'), true);
        $input = is_array($rawInput) ? $rawInput : $_POST;
        $action = trim((string)($input['action'] ?? 'create'));

        // Action 1: Owner Reviews Report (Approve / Reject / Acknowledge)
        if (in_array($action, ['review_report', 'update_status', 'approve', 'reject', 'acknowledge'], true)) {
            if (!Auth::isLandlord()) {
                http_response_code(403);
                echo json_encode(['status' => 'error', 'message' => 'Unauthorized action.']);
                exit;
            }

            $reportId = (int)($input['report_id'] ?? 0);
            $rawStatus = trim((string)($input['status'] ?? $action));
            $remarks = trim((string)($input['owner_remarks'] ?? ($input['remarks'] ?? '')));

            $statusMap = [
                'pending'      => 'Pending',
                'approve'      => 'Approved',
                'approved'     => 'Approved',
                'reject'       => 'Rejected',
                'rejected'     => 'Rejected',
                'acknowledge'  => 'Acknowledged',
                'acknowledged' => 'Acknowledged',
            ];
            $statusKey = strtolower($rawStatus);
            if (!isset($statusMap[$statusKey])) {
                http_response_code(422);
                echo json_encode(['status' => 'error', 'message' => 'Invalid report status decision.']);
                exit;
            }
            $statusToSave = $statusMap[$statusKey];

            $fetchStmt = $pdo->prepare("
                SELECT tr.*, u.name AS tenant_name, u.email AS tenant_email, u.phone AS tenant_phone, u.profile_picture AS tenant_profile_picture, un.unit_number
                FROM tenant_reports tr
                JOIN users u ON tr.tenant_id = u.id
                LEFT JOIN units un ON tr.unit_id = un.id
                WHERE tr.id = :id
                LIMIT 1
            ");
            $fetchStmt->execute([':id' => $reportId]);
            $reportRow = $fetchStmt->fetch();

            if (!$reportRow) {
                http_response_code(404);
                echo json_encode(['status' => 'error', 'message' => 'General report not found.']);
                exit;
            }

            $reviewer = Auth::user();
            $reviewerId = (int)($reviewer['id'] ?? 0) ?: null;
            $reviewedAt = $statusToSave === 'Pending' ? null : date('Y-m-d H:i:s');
            $remarksToSave = $remarks !== '' ? $remarks : ($reportRow['owner_remarks'] ?? null);

            $updStmt = $pdo->prepare("
                UPDATE tenant_reports
                SET status = :status,
                    owner_remarks = :owner_remarks,
                    reviewed_by = :reviewed_by,
                    reviewed_at = :reviewed_at
                WHERE id = :id
            ");
            $updStmt->execute([
                ':status'        => $statusToSave,
                ':owner_remarks' => $remarksToSave,
                ':reviewed_by'   => $reviewerId,
                ':reviewed_at'   => $reviewedAt,
                ':id'            => $reportId,
            ]);

            $notifTitle = "General Report {$statusToSave}";
            $notifMsg = "Your report #GR-" . str_pad((string)$reportId, 4, '0', STR_PAD_LEFT)
                . " ('{$reportRow['subject']}') has been marked as {$statusToSave}.";
            if (!empty($remarksToSave)) {
                $notifMsg .= " Owner remarks: {$remarksToSave}";
            }
            NotificationService::notifyTenant(
                $pdo,
                (int)$reportRow['tenant_id'],
                'report',
                $notifTitle,
                $notifMsg,
                'tenantReport',
                $reportId,
                'report_' . strtolower($statusToSave),
                false
            );

            // Dispatch SMS Notification to Tenant if Approved or Rejected
            if (!empty($reportRow['tenant_phone']) && in_array(strtolower($statusToSave), ['approved', 'rejected'], true)) {
                $tenantName = (string)($reportRow['tenant_name'] ?? 'Tenant');
                $smsReportMsg = "TaraRent Notice: Hello {$tenantName}, your report regarding '{$reportRow['subject']}' has been {$statusToSave} by the property owner." . (!empty($remarksToSave) ? " Remarks: {$remarksToSave}" : "");
                
                SmsService::send((string)$reportRow['tenant_phone'], $smsReportMsg);
            }
            // Automated Email Notification to Tenant's Gmail
            $tenantEmail = trim((string)($reportRow['tenant_email'] ?? ''));
            $tenantId = (int)$reportRow['tenant_id'];
            $reportSubject = (string)$reportRow['subject'];
            $emailSent = false;

            try {
                if (!empty($tenantEmail) && filter_var($tenantEmail, FILTER_VALIDATE_EMAIL)) {
                    $emailSent = EmailService::notifyTenantReportStatusUpdate(
                        $pdo,
                        $tenantId,
                        $reportSubject,
                        $statusToSave,
                        $remarksToSave,
                        $reportId
                    );
                } else {
                    EmailService::logEmail(
                        $pdo,
                        $tenantEmail,
                        (string)($reportRow['tenant_name'] ?? 'Tenant'),
                        "Report Request Update: {$reportSubject}",
                        'report_update',
                        $reportId,
                        'report',
                        'failed',
                        'Tenant record in database is missing a valid email address.',
                        $tenantId
                    );
                }
            } catch (Throwable $mailEx) {
                error_log('[report_api] Failed to send report email: ' . $mailEx->getMessage());
            }

            $fetchStmt->execute([':id' => $reportId]);
            $updatedRow = $fetchStmt->fetch();

            echo json_encode([
                'status'     => 'success',
                'message'    => "Report #GR-" . str_pad((string)$reportId, 4, '0', STR_PAD_LEFT) . " marked as {$statusToSave}.",
                'email_sent' => $emailSent,
                'report'     => $updatedRow,
            ]);
            exit;
        }

        // Action 2: Verified Tenant Submits General Report
        if ($action !== '' && $action !== 'create') {
            http_response_code(400);
            echo json_encode(['status' => 'error', 'message' => 'Invalid report action.']);
            exit;
        }

        AccessControl::requireVerifiedTenantJson($pdo);

        $tenantUser = Auth::user();
        $tenantId = (int)$tenantUser['id'];

        // Enforce Moved-Out / Unpaid Ex-Tenant restriction
        $freshCheckStmt = $pdo->prepare("SELECT rental_status FROM users WHERE id = :id LIMIT 1");
        $freshCheckStmt->execute([':id' => $tenantId]);
        $currRentalStatus = $freshCheckStmt->fetchColumn();
        if ($currRentalStatus === 'moved_out') {
            http_response_code(403);
            echo json_encode([
                'status'  => 'error',
                'message' => 'Your account is marked as moved out. General reports are closed for ex-tenants.'
            ]);
            exit;
        }

        // Enforce unpaid balance restriction: tenants with unpaid balances cannot submit reports
        require_once __DIR__ . '/../includes/tenant_service.php';
        $settlementCheck = TenantService::checkTenantSettlement($pdo, $tenantId);
        if (!$settlementCheck['is_settled'] && ($settlementCheck['unpaid_count'] > 0 || $settlementCheck['unpaid_balance'] > 0.01)) {
            http_response_code(403);
            echo json_encode([
                'status'  => 'error',
                'message' => 'Cannot submit reports: Outstanding balances must be settled first.'
            ]);
            exit;
        }

        $category = trim((string)($input['request_category'] ?? ($input['category'] ?? 'General Request')));
        $subject = trim((string)($input['request_specification'] ?? ($input['specification'] ?? ($input['subject'] ?? ''))));
        $details = trim((string)($input['request_description'] ?? ($input['description'] ?? ($input['details'] ?? ''))));

        if ($category === '') {
            $category = 'General Request';
        }

        if ($subject === '' || $details === '') {
            http_response_code(422);
            echo json_encode(['status' => 'error', 'message' => 'Both Subject and Request Details are required.']);
            exit;
        }

        if (mb_strlen($subject) > 180) {
            $subject = mb_substr($subject, 0, 180);
        }

        $unitId = isset($tenantUser['unit_id']) ? (int)$tenantUser['unit_id'] : 0;
        if ($unitId <= 0) {
            $uStmt = $pdo->prepare("SELECT unit_id FROM users WHERE id = :id LIMIT 1");
            $uStmt->execute([':id' => $tenantId]);
            $unitId = (int)$uStmt->fetchColumn() ?: 1;
        }

        // Optional supporting attachment upload (Supabase Cloud Storage: images + PDF + Docs)
        $savedAttachments = [];

        $fileList = [];
        foreach (['request_attachments', 'supporting_docs', 'request_attachment', 'report_attachments', 'attachments', 'attachment', 'document', 'file'] as $key) {
            if (isset($_FILES[$key])) {
                $f = $_FILES[$key];
                if (is_array($f['name'])) {
                    $count = count($f['name']);
                    for ($i = 0; $i < $count; $i++) {
                        if (isset($f['error'][$i]) && $f['error'][$i] === UPLOAD_ERR_OK && !empty($f['tmp_name'][$i])) {
                            $fileList[] = [
                                'name'     => $f['name'][$i],
                                'tmp_name' => $f['tmp_name'][$i],
                                'size'     => $f['size'][$i],
                            ];
                        }
                    }
                } elseif (isset($f['error']) && $f['error'] === UPLOAD_ERR_OK && !empty($f['tmp_name'])) {
                    $fileList[] = $f;
                }
            }
        }

        $allowedExts = ['jpg', 'jpeg', 'png', 'webp', 'gif', 'pdf', 'doc', 'docx'];
        $allowedMimes = [
            'image/jpeg', 'image/png', 'image/webp', 'image/gif',
            'application/pdf',
            'application/msword',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document'
        ];
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $seenUploadKeys = [];

        foreach ($fileList as $fileItem) {
            if ((int)$fileItem['size'] > 15 * 1024 * 1024) {
                continue;
            }
            $dedupKey = (string)$fileItem['name'] . '|' . (string)$fileItem['size'];
            if (isset($seenUploadKeys[$dedupKey])) {
                continue;
            }
            $ext = strtolower(pathinfo($fileItem['name'], PATHINFO_EXTENSION));
            if (!in_array($ext, $allowedExts, true)) {
                continue;
            }
            $mime = finfo_file($finfo, $fileItem['tmp_name']);
            if (!in_array($mime, $allowedMimes, true)) {
                continue;
            }

            $seenUploadKeys[$dedupKey] = true;
            $safeFilename = 'report_' . $tenantId . '_' . time() . '_' . bin2hex(random_bytes(6)) . '.' . $ext;

            // Upload directly to Supabase Storage bucket 'reports' via REST API, with local uploads/reports fallback
            $publicDocUrl = function_exists('uploadToSupabaseStorage')
                ? uploadToSupabaseStorage($fileItem['tmp_name'], $safeFilename, 'reports')
                : false;
            if ($publicDocUrl !== false) {
                $savedAttachments[] = $publicDocUrl;
            } else {
                $localDir = dirname(__DIR__) . '/uploads/reports';
                if (!is_dir($localDir)) {
                    @mkdir($localDir, 0755, true);
                }
                $localDest = $localDir . '/' . $safeFilename;
                if (@move_uploaded_file($fileItem['tmp_name'], $localDest) || @copy($fileItem['tmp_name'], $localDest)) {
                    $savedAttachments[] = 'uploads/reports/' . $safeFilename;
                }
            }
        }
        if ($finfo) {
            finfo_close($finfo);
        }

        $attachmentPathStr = !empty($savedAttachments) ? implode(',', $savedAttachments) : null;

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
            ':subject'         => $subject,
            ':details'         => $details,
            ':attachment_path' => $attachmentPathStr,
        ]);

        $newId = (int)$pdo->lastInsertId();

        $tUnitStmt = $pdo->prepare("SELECT unit_number FROM units WHERE id = :uid LIMIT 1");
        $tUnitStmt->execute([':uid' => $unitId]);
        $tUnitNum = $tUnitStmt->fetchColumn() ?: ('Unit ' . $unitId);
        $tenantName = $tenantUser['name'] ?? 'Tenant';
        $subDate = date('M j, Y');

        NotificationService::notifyLandlords(
            $pdo,
            'report',
            'New Tenant General Report',
            "Tenant: {$tenantName} • Unit: {$tUnitNum} • Category: {$category} • Subject: {$subject} • Submitted: {$subDate}",
            'sectionReports',
            $newId,
            'report_submitted',
            false
        );

        $rowStmt = $pdo->prepare("
            SELECT tr.*, u.name AS tenant_name, u.email AS tenant_email, u.phone AS tenant_phone, u.profile_picture AS tenant_profile_picture, un.unit_number
            FROM tenant_reports tr
            JOIN users u ON tr.tenant_id = u.id
            LEFT JOIN units un ON tr.unit_id = un.id
            WHERE tr.id = :id
            LIMIT 1
        ");
        $rowStmt->execute([':id' => $newId]);
        $createdRow = $rowStmt->fetch();

        echo json_encode([
            'status'  => 'success',
            'success' => true,
            'message' => 'General report #GR-' . str_pad((string)$newId, 4, '0', STR_PAD_LEFT) . ' submitted successfully.',
            'report'  => $createdRow,
        ]);
        exit;
    }

    if ($requestMethod === 'GET') {
        $currentUser = Auth::user();
        $reportId = isset($_GET['id']) ? (int)$_GET['id'] : (isset($_GET['report_id']) ? (int)$_GET['report_id'] : 0);

        if ($reportId > 0) {
            $query = "
                SELECT tr.*, u.name AS tenant_name, u.email AS tenant_email, u.phone AS tenant_phone, u.profile_picture AS tenant_profile_picture, un.unit_number
                FROM tenant_reports tr
                JOIN users u ON tr.tenant_id = u.id
                LEFT JOIN units un ON tr.unit_id = un.id
                WHERE tr.id = :id
            ";
            $params = [':id' => $reportId];

            if (Auth::isTenant() && $currentUser) {
                $query .= " AND tr.tenant_id = :tid";
                $params[':tid'] = (int)$currentUser['id'];
            }
            $query .= " LIMIT 1";

            $stmt = $pdo->prepare($query);
            $stmt->execute($params);
            $report = $stmt->fetch();

            if (!$report) {
                http_response_code(404);
                echo json_encode(['status' => 'error', 'message' => 'General report not found or access restricted.']);
                exit;
            }

            echo json_encode([
                'status' => 'success',
                'report' => $report,
            ]);
            exit;
        }

        $query = "
            SELECT tr.*, u.name AS tenant_name, u.email AS tenant_email, u.phone AS tenant_phone, u.profile_picture AS tenant_profile_picture, un.unit_number
            FROM tenant_reports tr
            JOIN users u ON tr.tenant_id = u.id
            LEFT JOIN units un ON tr.unit_id = un.id
        ";
        $params = [];

        if (Auth::isTenant() && $currentUser) {
            $query .= " WHERE tr.tenant_id = :tid";
            $params[':tid'] = (int)$currentUser['id'];
        } else {
            $query .= " WHERE (u.is_archived = FALSE OR u.is_archived IS NULL) AND u.deleted_at IS NULL";
        }

        $query .= " ORDER BY tr.created_at DESC, tr.id DESC";

        $stmt = $pdo->prepare($query);
        $stmt->execute($params);
        $reports = $stmt->fetchAll();

        $pendingCount = count(array_filter($reports, fn($r) => $r['status'] === 'Pending'));
        $reviewedCount = count($reports) - $pendingCount;

        echo json_encode([
            'status'         => 'success',
            'count'          => count($reports),
            'pending_count'  => $pendingCount,
            'reviewed_count' => $reviewedCount,
            'reports'        => $reports,
        ]);
        exit;
    }
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'status'  => 'error',
        'message' => 'Report service error: ' . $e->getMessage(),
    ]);
}
