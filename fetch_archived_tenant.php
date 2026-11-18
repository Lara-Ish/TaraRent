<?php
/**
 * RentConnect / TaraRent - Fetch Archived Tenant Full Details API
 * Fetches tenant full profile, complete payment history, and maintenance requests.
 */

declare(strict_types=1);

header('Content-Type: application/json; charset=UTF-8');

// Parse CLI arguments if running via command line test
if (php_sapi_name() === 'cli') {
    if (empty($_GET['id']) && empty($_GET['tenant_id']) && isset($argv)) {
        foreach ($argv as $arg) {
            if (str_starts_with($arg, 'id=')) {
                $_GET['id'] = (int)substr($arg, 3);
            } elseif (str_starts_with($arg, 'tenant_id=')) {
                $_GET['tenant_id'] = (int)substr($arg, 10);
            }
        }
    }
} else {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
}

require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/auth.php';

// Security: Landlord only (bypass for CLI testing)
if (php_sapi_name() !== 'cli' && !Auth::isLandlord()) {
    http_response_code(403);
    echo json_encode([
        'status' => 'error',
        'message' => 'Unauthorized: Only the landlord has permission to view archived tenant records.'
    ]);
    exit;
}

$tenantId = (int)($_GET['id'] ?? ($_GET['tenant_id'] ?? 0));
if ($tenantId <= 0) {
    http_response_code(422);
    echo json_encode([
        'status' => 'error',
        'message' => 'Invalid or missing tenant ID.'
    ]);
    exit;
}

try {
    $pdo = Database::getConnection();

    // 1. Fetch Tenant Profile
    $stmt = $pdo->prepare("
        SELECT u.id, u.name, u.username, u.email, u.phone, u.birthday,
               u.emergency_contact, u.emergency_contact_name, u.emergency_contact_relationship, u.emergency_contact_phone,
               u.unit_id, u.rental_status, u.archived_unit_number, u.archived_monthly_rent, u.archived_spaces_text,
               u.move_in_date, u.move_out_date, u.total_occupants, u.adults_count, u.kids_count,
               u.id_type, u.id_photo_filename, u.profile_picture,
               u.verification_status, u.is_verified, u.id_submitted_at, u.id_verified_at,
               u.deleted_at, u.created_at,
               un.unit_number AS current_unit_number,
               (SELECT address FROM apartment_settings LIMIT 1) AS property_address,
               (SELECT security_deposit FROM apartment_settings LIMIT 1) AS standard_deposit
        FROM users u
        LEFT JOIN units un ON u.unit_id = un.id
        WHERE u.id = :id AND u.role = 'tenant'
        LIMIT 1
    ");
    $stmt->execute([':id' => $tenantId]);
    $tenant = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$tenant) {
        http_response_code(404);
        echo json_encode([
            'status' => 'error',
            'message' => 'Archived tenant account not found.'
        ]);
        exit;
    }

    // Format Profile Fields
    $tenant['formatted_move_in'] = !empty($tenant['move_in_date']) ? date('M j, Y', strtotime($tenant['move_in_date'])) : 'Not recorded';
    $tenant['formatted_move_out'] = !empty($tenant['move_out_date']) 
        ? date('M j, Y', strtotime($tenant['move_out_date']))
        : (!empty($tenant['deleted_at']) ? date('M j, Y', strtotime($tenant['deleted_at'])) : 'Not recorded');
    $tenant['formatted_birthday'] = !empty($tenant['birthday']) ? date('M j, Y', strtotime($tenant['birthday'])) : 'Not recorded';
    $tenant['formatted_deleted_at'] = !empty($tenant['deleted_at']) ? date('M j, Y g:i A', strtotime($tenant['deleted_at'])) : 'Archived';
    $tenant['unit_display'] = !empty($tenant['archived_unit_number']) 
        ? $tenant['archived_unit_number'] 
        : (!empty($tenant['current_unit_number']) ? 'Unit ' . $tenant['current_unit_number'] : 'Unit N/A');
    $tenant['monthly_rent_formatted'] = '₱' . number_format((float)($tenant['archived_monthly_rent'] ?: 3000.00), 2);
    $tenant['initial'] = strtoupper(mb_substr(trim(explode(' ', trim((string)$tenant['name']))[0]), 0, 1, 'UTF-8')) ?: 'T';

    $emergencyName = trim((string)($tenant['emergency_contact_name'] ?? ''));
    $emergencyPhone = trim((string)($tenant['emergency_contact_phone'] ?? ''));
    $emergencyRel = trim((string)($tenant['emergency_contact_relationship'] ?? ''));
    $emergencyFallback = trim((string)($tenant['emergency_contact'] ?? ''));

    if ($emergencyName !== '' || $emergencyPhone !== '') {
        $emParts = [];
        if ($emergencyName !== '') $emParts[] = $emergencyName;
        if ($emergencyRel !== '') $emParts[] = "({$emergencyRel})";
        if ($emergencyPhone !== '') $emParts[] = "- {$emergencyPhone}";
        $tenant['emergency_contact_display'] = implode(' ', $emParts);
    } elseif ($emergencyFallback !== '') {
        $tenant['emergency_contact_display'] = $emergencyFallback;
    } else {
        $tenant['emergency_contact_display'] = 'Not provided';
    }

    // 2. Fetch Payment History
    $pStmt = $pdo->prepare("
        SELECT p.id, p.unit_id, pu.unit_number AS payment_unit_number,
               p.payment_type, p.billing_cycle, p.due_date, p.base_rent_amount, 
               p.additional_space_fee, p.additional_spaces_breakdown,
               p.amount_due, p.amount_paid, p.payment_date, p.status, p.payment_method, 
               p.receipt_filename, p.verified_by_landlord, p.verification_notes, p.verified_at, p.created_at
        FROM payments p
        LEFT JOIN units pu ON p.unit_id = pu.id
        WHERE p.tenant_id = :id
        ORDER BY COALESCE(p.payment_date, p.due_date, p.created_at) DESC, p.id DESC
    ");
    $pStmt->execute([':id' => $tenantId]);
    $payments = $pStmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($payments as &$p) {
        $p['billing_cycle_formatted'] = !empty($p['billing_cycle']) ? date('F Y', strtotime($p['billing_cycle'])) : ($p['payment_type'] ?: 'Billing Cycle');
        $p['due_date_formatted'] = !empty($p['due_date']) ? date('M j, Y', strtotime($p['due_date'])) : '—';
        $p['payment_date_formatted'] = !empty($p['payment_date']) ? date('M j, Y', strtotime($p['payment_date'])) : 'Pending';
        $p['amount_due_formatted'] = '₱' . number_format((float)($p['amount_due'] ?? 0), 2);
        $p['amount_paid_formatted'] = '₱' . number_format((float)($p['amount_paid'] ?? 0), 2);
        $p['status_label'] = ucfirst(str_replace('_', ' ', (string)($p['status'] ?? 'unpaid')));
        $p['payment_method_label'] = !empty($p['payment_method']) ? ucfirst((string)$p['payment_method']) : '—';
    }
    unset($p);

    // 3. Fetch Maintenance History (from maintenance_tickets)
    $mStmt = $pdo->prepare("
        SELECT mt.id, mt.unit_id, tu.unit_number AS ticket_unit_number,
               mt.title, mt.description, mt.photo_path, mt.category, mt.priority, mt.status, 
               mt.actual_cost, mt.resolution_notes, mt.created_at, mt.resolved_at
        FROM maintenance_tickets mt
        LEFT JOIN units tu ON mt.unit_id = tu.id
        WHERE mt.tenant_id = :id
        ORDER BY mt.created_at DESC, mt.id DESC
    ");
    $mStmt->execute([':id' => $tenantId]);
    $maintenance = $mStmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($maintenance as &$m) {
        $m['created_at_formatted'] = !empty($m['created_at']) ? date('M j, Y g:i A', strtotime($m['created_at'])) : '—';
        $m['resolved_at_formatted'] = !empty($m['resolved_at']) ? date('M j, Y g:i A', strtotime($m['resolved_at'])) : 'Pending';
        $m['cost_formatted'] = !empty($m['actual_cost']) ? ('₱' . number_format((float)$m['actual_cost'], 2)) : '—';
        $m['status_label'] = ucfirst(str_replace('_', ' ', (string)($m['status'] ?? 'open')));
        $m['priority_label'] = ucfirst((string)($m['priority'] ?? 'medium'));
        $m['category_label'] = ucfirst(str_replace('_', ' ', (string)($m['category'] ?? 'General')));
    }
    unset($m);

    echo json_encode([
        'status' => 'success',
        'tenant' => $tenant,
        'payments' => $payments,
        'maintenance' => $maintenance,
        'tickets' => $maintenance
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode([
        'status' => 'error',
        'message' => 'Database query failed: ' . $e->getMessage()
    ]);
}
