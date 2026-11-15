<?php
/**
 * TaraRent - Log Maintenance Route & Gatekeeper
 *
 * Enforces role-based access control and tenancy status security checks.
 * If a tenant has moved out with an outstanding balance > 0, direct access
 * via URL manipulation is blocked, redirecting them immediately back to the Dashboard.
 */

declare(strict_types=1);

require_once __DIR__ . '/config/auth.php';
require_once __DIR__ . '/includes/access_control.php';

// 1. Session Authentication Check
if (!class_exists('Auth') || !Auth::check()) {
    header('Location: login.php');
    exit;
}

$sessionRole = strtolower(trim((string)($_SESSION['role'] ?? '')));

// 2. Role Check: Landlords route to landlord maintenance tickets module
if ($sessionRole === 'landlord') {
    header('Location: admin_dashboard.php?section=sectionTickets');
    exit;
}

// 3. Must be Tenant role
if ($sessionRole !== 'tenant') {
    header('Location: login.php');
    exit;
}

// 4. Role-Based Access Control via AccessControl::getTenantStatus
$currentUser = Auth::user();
$tenantId = (int)($currentUser['id'] ?? 0);

if ($tenantId > 0 && class_exists('Database')) {
    try {
        $pdo = Database::getConnection();
        if ($pdo) {
            $tenantStatus = AccessControl::getTenantStatus($pdo, $tenantId);

            // Rule 2: Silent Lockout (Moved Out & Paid)
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

            // Rule 3: Debt Collection Mode (Moved Out & Unpaid)
            if ($tenantStatus['mode'] === 'debt_collection') {
                header('Location: tenant_portal.php?section=tenantOverview&restricted=moved_out');
                exit;
            }

            // Backend Protection: If a POST request is detected from an unverified user, abort and return access denied error
            if ($_SERVER['REQUEST_METHOD'] === 'POST' && (!$tenantStatus['is_verified'] || $tenantStatus['mode'] === 'unverified')) {
                http_response_code(403);
                header('Content-Type: application/json; charset=UTF-8');
                echo json_encode([
                    'status'  => 'error',
                    'code'    => 'ACCESS_DENIED',
                    'message' => 'Access Denied: Please verify your identity to submit new records.'
                ]);
                exit;
            }
        }
    } catch (\Throwable $e) {
        error_log('log_maintenance.php security check error: ' . $e->getMessage());
    }
}

// 5. Authorized Access: Route to Tenant Maintenance Section
header('Location: tenant_portal.php?section=tenantMaintenance');
exit;
