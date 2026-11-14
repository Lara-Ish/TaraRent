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
    // Check if tenant has moved out with an unpaid balance
    $currentUser = Auth::user();
    if (!empty($currentUser['id'])) {
        $pdo = Database::getConnection();
        $st = $pdo->prepare("SELECT rental_status, is_active FROM users WHERE id = ? LIMIT 1");
        $st->execute([(int)$currentUser['id']]);
        $row = $st->fetch();
        if ($row && ($row['rental_status'] ?? '') === 'moved_out' && (int)($row['is_active'] ?? 0) === 1) {
            header('Location: tenant_portal.php?section=tenantOverview&restricted=moved_out');
            exit;
        }
    }
    header('Location: tenant_portal.php?section=tenantProfile&tab=accountInfo');
    exit;
}

// Fallback for any other authenticated role
header('Location: login.php');
exit;
