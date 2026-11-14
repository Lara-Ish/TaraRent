<?php
/**
 * TaraRent - Report Route & Gatekeeper
 *
 * Enforces role-based access control and tenancy status security checks.
 * If a tenant has moved out with an outstanding balance > 0, direct access
 * via URL manipulation is blocked, redirecting them immediately back to the Dashboard.
 */

declare(strict_types=1);

require_once __DIR__ . '/config/auth.php';

// 1. Session Authentication Check
if (!class_exists('Auth') || !Auth::check()) {
    header('Location: login.php');
    exit;
}

$sessionRole = strtolower(trim((string)($_SESSION['role'] ?? '')));

// 2. Role Check: Landlords route to landlord reports module
if ($sessionRole === 'landlord') {
    header('Location: admin_dashboard.php?section=sectionReports');
    exit;
}

// 3. Must be Tenant role
if ($sessionRole !== 'tenant') {
    header('Location: login.php');
    exit;
}

// 4. Security Check: Moved-Out Tenants with Outstanding Balances
$currentUser = Auth::user();
$tenantId = (int)($currentUser['id'] ?? 0);

if ($tenantId > 0 && class_exists('Database')) {
    try {
        $pdo = Database::getConnection();
        if ($pdo) {
            $uStmt = $pdo->prepare("SELECT rental_status, is_active FROM users WHERE id = :id LIMIT 1");
            $uStmt->execute([':id' => $tenantId]);
            $uRow = $uStmt->fetch(PDO::FETCH_ASSOC);

            $rentalStatus = strtolower(trim((string)($uRow['rental_status'] ?? '')));
            $isActive = (int)($uRow['is_active'] ?? 0);

            // If tenant has moved out
            if (in_array($rentalStatus, ['moved_out', 'moved out'], true)) {
                $pStmt = $pdo->prepare("
                    SELECT COUNT(*) FROM payments 
                    WHERE tenant_id = :tid 
                      AND (status IN ('unpaid', 'overdue', 'rejected', 'pending_setup') OR (amount_due - amount_paid) > 0.01)
                ");
                $pStmt->execute([':tid' => $tenantId]);
                $unpaidCount = (int)$pStmt->fetchColumn();

                // If balance remains, block route access and redirect to Dashboard
                if ($unpaidCount > 0 || $isActive === 1) {
                    header('Location: tenant_portal.php?section=tenantOverview&restricted=moved_out');
                    exit;
                }
            }
        }
    } catch (\Throwable $e) {
        error_log('report.php security check error: ' . $e->getMessage());
    }
}

// 5. Authorized Access: Route to Tenant Report Section
header('Location: tenant_portal.php?section=tenantReport');
exit;
