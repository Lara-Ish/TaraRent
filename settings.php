<?php
/**
 * TaraRent - Role-Based Settings & Privacy Router
 *
 * Redirects authenticated users to their respective portal settings:
 * - Landlord/Owner: admin_dashboard.php?section=sectionProfile&tab=accountInfo
 * - Tenant/Resident: tenant_portal.php?section=tenantProfile&tab=accountInfo
 */

declare(strict_types=1);

require_once __DIR__ . '/config/auth.php';
require_once __DIR__ . '/includes/access_control.php';

if (!class_exists('Auth') || !Auth::check()) {
    header('Location: login.php');
    exit;
}

$role = strtolower(trim((string)($_SESSION['role'] ?? '')));

if ($role === 'landlord') {
    header('Location: admin_dashboard.php?section=sectionProfile&tab=accountInfo');
    exit;
}

if ($role === 'tenant') {
    $tenantId = (int)($_SESSION['user_id'] ?? 0);
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
            }
        } catch (\Throwable $e) {
            error_log('settings.php security check error: ' . $e->getMessage());
        }
    }
    header('Location: tenant_portal.php?section=tenantProfile&tab=accountInfo');
    exit;
}

// Fallback for any other authenticated role
header('Location: login.php');
exit;
