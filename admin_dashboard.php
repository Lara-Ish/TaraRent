<?php
/**
 * RentConnect - Landlord Business Intelligence & Management Dashboard
 * Landlord: Leah Dimaculangan (Role: Landlord)
 * Property Architecture: 1 Apartment with Exactly 3 Units (Unlimited Tenants per Unit)
 * Currency: ₱
 */

declare(strict_types=1);

// Prevent browser caching so Back button cannot display protected dashboard after logout
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Cache-Control: post-check=0, pre-check=0", false);
header("Pragma: no-cache");
header("Expires: Sat, 26 Jul 1997 05:00:00 GMT");

require_once __DIR__ . '/config/auth.php';
Auth::requireLandlord();

$pdo = Database::getConnection();

// Fetch current authenticated landlord user
$user = Auth::user();
if ($user && isset($user['id'])) {
    $userStmt = $pdo->prepare("SELECT * FROM users WHERE id = :id LIMIT 1");
    $userStmt->execute([':id' => $user['id']]);
    $freshUser = $userStmt->fetch();
    if ($freshUser) {
        $user = $freshUser;
    }
}

// Resolve user avatar image URL dynamically from database record or uploaded face
$userAvatarUrl = '';
if (!empty($user['profile_picture'])) {
    $pic = (string)$user['profile_picture'];
    if (str_starts_with($pic, 'http://') || str_starts_with($pic, 'https://')) {
        $userAvatarUrl = htmlspecialchars($pic);
    } elseif (file_exists(__DIR__ . '/' . $pic)) {
        $userAvatarUrl = htmlspecialchars($pic);
    } elseif (file_exists(__DIR__ . '/uploads/avatars/' . basename($pic))) {
        $userAvatarUrl = 'uploads/avatars/' . htmlspecialchars(basename($pic));
    }
}
if (empty($userAvatarUrl) && !empty($user['live_photo_filename'])) {
    if (file_exists(__DIR__ . '/uploads/id_verifications/' . $user['live_photo_filename'])) {
        $userAvatarUrl = 'uploads/id_verifications/' . htmlspecialchars($user['live_photo_filename']);
    } elseif (file_exists(__DIR__ . '/uploads/live_captures/' . $user['live_photo_filename'])) {
        $userAvatarUrl = 'uploads/live_captures/' . htmlspecialchars($user['live_photo_filename']);
    } elseif (file_exists(__DIR__ . '/uploads/' . $user['live_photo_filename'])) {
        $userAvatarUrl = 'uploads/' . htmlspecialchars($user['live_photo_filename']);
    }
}

// Extract first letter of user's first name dynamically for profile icon
$userFirstName = trim(explode(' ', trim($user['name'] ?? 'U'))[0]);
$userInitial = strtoupper(mb_substr($userFirstName, 0, 1));
if ($userInitial === '') {
    $userInitial = 'U';
}

// Synchronize and auto-archive any moved-out tenants whose bills are fully settled
require_once __DIR__ . '/includes/tenant_service.php';
TenantService::syncMovedOutSettlements($pdo);

// Fetch 3 Units
$unitsStmt = $pdo->query("SELECT * FROM units ORDER BY unit_number ASC");
$units = $unitsStmt->fetchAll();

// Fetch Additional Rental Spaces (Space 1 & Space 2) with active occupancy check
$registrationSpaces = [];
try {
    $regSpacesStmt = $pdo->query("
        SELECT s.id, s.space_number, s.space_name, s.monthly_fee, s.status, s.assigned_tenant_id,
               u.name AS assigned_tenant_name, u.is_active AS tenant_is_active, u.is_archived AS tenant_is_archived
        FROM additional_spaces s
        LEFT JOIN users u ON s.assigned_tenant_id = u.id
        ORDER BY s.space_number ASC
    ");
    $regSpaceRows = $regSpacesStmt->fetchAll();
    foreach ($regSpaceRows as $sr) {
        $hasActiveTenant = !empty($sr['assigned_tenant_id'])
            && (int)($sr['tenant_is_active'] ?? 0) === 1
            && (int)($sr['tenant_is_archived'] ?? 0) === 0;
        $isSpaceOccupied = ($sr['status'] !== 'available') || $hasActiveTenant;
        $registrationSpaces[] = [
            'id' => (int)$sr['id'],
            'space_number' => (int)$sr['space_number'],
            'space_name' => (string)$sr['space_name'],
            'monthly_fee' => ((float)$sr['monthly_fee'] > 0) ? (float)$sr['monthly_fee'] : 1500.00,
            'status' => $isSpaceOccupied ? 'occupied' : 'available',
            'is_occupied' => $isSpaceOccupied,
            'assigned_tenant_name' => $sr['assigned_tenant_name'] ?? null
        ];
    }
} catch (Throwable $spaceQueryEx) {
    $registrationSpaces = [];
}

if (empty($registrationSpaces)) {
    $registrationSpaces = [
        ['id' => 1, 'space_number' => 1, 'space_name' => 'Space 1', 'monthly_fee' => 1500.00, 'status' => 'available', 'is_occupied' => false, 'assigned_tenant_name' => null],
        ['id' => 2, 'space_number' => 2, 'space_name' => 'Space 2', 'monthly_fee' => 1500.00, 'status' => 'available', 'is_occupied' => false, 'assigned_tenant_name' => null],
    ];
}

// Fetch All Active Tenants (Strictly active, non-archived residents, deduplicated by u.id)
$tenantsStmt = $pdo->query("
    SELECT DISTINCT u.id, u.unit_id, u.username, u.name, u.email, u.phone, u.profile_picture,
           u.verification_status, u.is_verified, u.id_type, u.id_photo_filename, u.verification_notes, u.id_submitted_at,
           u.birthday, u.emergency_contact, u.emergency_contact_name,
           u.emergency_contact_relationship, u.emergency_contact_phone,
           u.move_in_date, u.move_out_date, u.rental_status, u.total_occupants, u.adults_count, u.kids_count, u.created_at,
           COALESCE(un.unit_number, u.archived_unit_number, 'Moved Out') AS unit_number, 
           COALESCE(un.monthly_rent, u.archived_monthly_rent, 0.00) AS monthly_rent
    FROM users u
    LEFT JOIN units un ON u.unit_id = un.id
    WHERE u.role = 'tenant' 
      AND u.is_active = TRUE
      AND (u.is_archived = FALSE OR u.is_archived IS NULL)
      AND u.deleted_at IS NULL
    ORDER BY unit_number ASC, u.name ASC
");
$allTenants = $tenantsStmt->fetchAll();

// Group tenants by unit and evaluate payment settlement status
$unitsWithTenants = [];
foreach ($units as $u) {
    $uId = (int)$u['id'];
    $unitsWithTenants[$uId] = $u;
    $unitsWithTenants[$uId]['unit_id'] = $uId;
    $unitsWithTenants[$uId]['is_occupied'] = false;
    $unitsWithTenants[$uId]['is_paid_up'] = null;
    $unitsWithTenants[$uId]['card_state'] = 'available';
    $unitsWithTenants[$uId]['tenants'] = [];
}
foreach ($allTenants as $t) {
    $uId = (int)($t['unit_id'] ?? 0);
    $tId = (int)$t['id'];
    $settlement = TenantService::checkTenantSettlement($pdo, $tId);
    $t['settlement'] = $settlement;
    $t['is_paid_up'] = (bool)($settlement['is_settled'] ?? false);

    $rawVStatus = strtolower(trim((string)($t['verification_status'] ?? 'unverified')));
    $t['is_verified'] = (filter_var($t['is_verified'] ?? false, FILTER_VALIDATE_BOOLEAN) && $rawVStatus === 'verified') ? 1 : 0;

    $resolvedAvatar = '';
    $rawPic = trim((string)($t['profile_picture'] ?? ''));
    if ($rawPic !== '') {
        if (str_starts_with($rawPic, 'http://') || str_starts_with($rawPic, 'https://')) {
            $resolvedAvatar = $rawPic;
        } else {
            $cleanPic = ltrim(str_replace('\\', '/', $rawPic), '/');
            if ($cleanPic !== '' && is_file(__DIR__ . '/' . $cleanPic)) {
                $resolvedAvatar = $cleanPic;
            } elseif (is_file(__DIR__ . '/uploads/avatars/' . basename($cleanPic))) {
                $resolvedAvatar = 'uploads/avatars/' . basename($cleanPic);
            }
        }
    }
    $t['avatar_url'] = $resolvedAvatar;

    if ($uId > 0 && ($t['rental_status'] ?? 'active') !== 'moved_out' && isset($unitsWithTenants[$uId])) {
        $unitsWithTenants[$uId]['tenants'][] = $t;
    }
}
$activeUnitsCount = 0;
foreach ($unitsWithTenants as &$uItem) {
    if (!empty($uItem['tenants'])) {
        $activeUnitsCount++;
        $uItem['is_occupied'] = true;
        $allSettled = true;
        foreach ($uItem['tenants'] as $occ) {
            if (empty($occ['is_paid_up'])) {
                $allSettled = false;
                break;
            }
        }
        $uItem['is_paid_up'] = $allSettled;
        $uItem['card_state'] = $allSettled ? 'occupied_paid' : 'occupied_unpaid';
    }
}
unset($uItem);

// Fetch Maintenance Tickets (deduplicated)
$tickets = $pdo->query("
    SELECT DISTINCT mt.*, u.name AS tenant_name, u.email AS tenant_email, u.phone AS tenant_phone, un.unit_number
    FROM maintenance_tickets mt
    JOIN users u ON mt.tenant_id = u.id
    JOIN units un ON mt.unit_id = un.id
    ORDER BY mt.created_at DESC
")->fetchAll();

foreach ($tickets as &$tk) {
    $st = strtolower((string)$tk['status']);
    if ($st === 'open' || $st === 'pending') {
        $tk['status'] = 'Pending';
    } elseif ($st === 'in_progress' || $st === 'on-going' || $st === 'ongoing') {
        $tk['status'] = 'On-Going';
    } elseif ($st === 'resolved' || $st === 'closed' || $st === 'done') {
        $tk['status'] = 'Done';
    } else {
        $tk['status'] = 'Pending';
    }
}
unset($tk);

$activeTickets = array_values(array_filter($tickets, fn($t) => in_array($t['status'], ['Pending', 'On-Going'])));
$completedTickets = array_values(array_filter($tickets, fn($t) => $t['status'] === 'Done'));


// Fetch General Tenant Reports (strictly separated from maintenance_tickets, deduplicated)
$tenantReports = $pdo->query("
    SELECT DISTINCT tr.*, u.name AS tenant_name, u.email AS tenant_email, u.phone AS tenant_phone, un.unit_number, rv.name AS reviewer_name
    FROM tenant_reports tr
    JOIN users u ON tr.tenant_id = u.id
    LEFT JOIN units un ON tr.unit_id = un.id
    LEFT JOIN users rv ON tr.reviewed_by = rv.id
    ORDER BY tr.created_at DESC
")->fetchAll();
$pendingReports = array_values(array_filter($tenantReports, fn($r) => ($r['status'] ?? 'Pending') === 'Pending'));
$reviewedReports = array_values(array_filter($tenantReports, fn($r) => ($r['status'] ?? 'Pending') !== 'Pending'));

// Fetch Payments Ledger & Pending Reviews (Strictly active, non-archived tenants, deduplicated)
$payments = $pdo->query("
    SELECT DISTINCT p.*, u.name AS tenant_name, u.username AS tenant_username, u.move_in_date, u.rental_status, un.unit_number
    FROM payments p
    JOIN users u ON p.tenant_id = u.id
    JOIN units un ON p.unit_id = un.id
    WHERE (u.is_archived = FALSE OR u.is_archived IS NULL)
      AND u.is_active = TRUE
      AND u.deleted_at IS NULL
    ORDER BY p.id DESC
")->fetchAll();

// Collect distinct billing years from database payment records for filtering
$availablePaymentYears = [];
foreach ($payments as $p) {
    if (!empty($p['billing_cycle'])) {
        $yr = date('Y', strtotime($p['billing_cycle']));
        if (!in_array($yr, $availablePaymentYears, true)) {
            $availablePaymentYears[] = $yr;
        }
    }
}
$currentYear = date('Y');
if (!in_array($currentYear, $availablePaymentYears, true)) {
    $availablePaymentYears[] = $currentYear;
}
rsort($availablePaymentYears);

// Count pending payment receipts for manual review badge (only those with uploaded receipts)
$pendingReviewPayments = array_values(array_filter($payments, fn($p) => $p['status'] === 'pending' && !empty($p['receipt_filename'])));
$pendingReviewCount = count($pendingReviewPayments);

// Initial Live Financial & Portfolio Metrics (Rental Collections Only — Zero Expense Deductions)
$finInitStmt = $pdo->query("
    SELECT
        COALESCE(SUM(CASE WHEN status != 'pending_setup' THEN amount_due ELSE 0 END), 0) AS total_billed,
        COALESCE(SUM(CASE WHEN status = 'approved' THEN amount_paid ELSE 0 END), 0) AS total_collected,
        COALESCE(SUM(CASE WHEN status NOT IN ('approved', 'pending_setup') AND amount_due IS NOT NULL THEN GREATEST(0, amount_due - COALESCE(amount_paid, 0)) ELSE 0 END), 0) AS total_unpaid
    FROM payments
");
$finInit = $finInitStmt->fetch() ?: [];
$initTotalBilled = (float)($finInit['total_billed'] ?? 0);
$initRentCollected = (float)($finInit['total_collected'] ?? 0);
$initUnpaidRent = (float)($finInit['total_unpaid'] ?? 0);
$initCollectionRate = $initTotalBilled > 0 ? round(($initRentCollected / $initTotalBilled) * 100, 1) : 0.0;

$occupiedUnitsCount = count(array_filter($unitsWithTenants, fn($u) => count($u['tenants']) > 0));
$availableUnitsCount = max(0, count($units) - $occupiedUnitsCount);
$archivedTenantsCount = (int)$pdo->query("
    SELECT COUNT(DISTINCT id) FROM users
    WHERE role = 'tenant' AND (is_archived = TRUE OR deleted_at IS NOT NULL)
")->fetchColumn();

// Fetch archived tenant records for initial table rendering
$archivedTenantsList = [];
try {
    $archivedTenantsStmt = $pdo->query("
        SELECT DISTINCT u.id, u.id AS tenant_id, u.name, u.username, u.email, u.phone, u.birthday, 
               u.archived_unit_number, u.archived_monthly_rent, u.archived_spaces_text,
               u.rental_status, u.move_in_date, u.move_out_date, u.deleted_at, u.created_at, u.profile_picture,
               un.unit_number AS current_unit_number
        FROM users u
        LEFT JOIN units un ON u.unit_id = un.id
        WHERE u.role = 'tenant' AND (u.is_archived = TRUE OR u.deleted_at IS NOT NULL)
        ORDER BY COALESCE(u.deleted_at, u.move_out_date) DESC, u.id DESC
    ");
    if ($archivedTenantsStmt) {
        $archivedTenantsList = $archivedTenantsStmt->fetchAll(PDO::FETCH_ASSOC);
    }
} catch (Exception $e) {
    error_log("Failed to load initial archived tenants list: " . $e->getMessage());
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>TaraRent – Apartment Management System</title>

  <!-- Global Favicon -->
  <link rel="icon" type="image/png" sizes="16x16" href="assets/images/favicon-16.png">
  <link rel="icon" type="image/png" sizes="32x32" href="assets/images/favicon-32.png">
  <link rel="icon" type="image/png" sizes="64x64" href="assets/images/favicon-64.png">
  <link rel="apple-touch-icon" sizes="192x192" href="assets/images/favicon-192.png">
  <link rel="shortcut icon" href="favicon.ico">
  <link rel="manifest" href="site.webmanifest">

  <!-- Typography -->
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@600;700;800&family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">

  <!-- Claymorphism Base & Owner Warm Cream/Gold Design System -->
  <link rel="stylesheet" href="assets/css/claymorphism.css">
  <link rel="stylesheet" href="assets/css/admin_theme.css?v=20261112c">

  <!-- Icons, Charts & Shared Sidebar Controller -->
  <script src="https://unpkg.com/lucide@0.469.0/dist/umd/lucide.min.js"></script>
  <script src="assets/js/lucide_init.js?v=20261112"></script>
  <script src="assets/js/chart.umd.min.js"></script>
  <script>window.Chart || document.write('<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"><\/script>')</script>
  <script src="assets/js/sidebar.js?v=20261112"></script>

  <!-- Tailwind CSS (with Preflight disabled to preserve claymorphic layout) -->
  <script src="https://cdn.tailwindcss.com"></script>
  <script>
    tailwind.config = {
      corePlugins: {
        preflight: false,
      }
    }
  </script>

  <style>
    /* Interactive Settings & Privacy Action Item Styling */
    #settingsToggleBtn.hover\:bg-gray-200:hover,
    #settingsToggleBtn:hover {
      background-color: #e5e7eb !important;
      color: #2563eb !important;
      cursor: pointer !important;
    }
    #settingsToggleBtn:hover .fb-action-label,
    #settingsToggleBtn:hover .fb-action-arrow,
    #settingsToggleBtn:hover .fb-action-icon-circle {
      color: #2563eb !important;
    }
    #settingsToggleBtn:hover .fb-action-icon-circle {
      background-color: rgba(37, 99, 235, 0.12) !important;
    }

    .modal-backdrop {
      display: none !important;
      position: fixed;
      inset: 0;
      background: rgba(15, 23, 42, 0.45);
      backdrop-filter: blur(4px);
      z-index: 10000;
      align-items: center;
      justify-content: center;
      padding: 20px;
      pointer-events: none !important;
    }
    .modal-backdrop.open { 
      display: flex !important; 
      pointer-events: auto !important;
    }
    .clay-modal {
      width: 100%;
      max-width: 540px;
      background: var(--clay-card-bg);
      border-radius: var(--radius-xl);
      box-shadow: var(--clay-card-shadow-lg);
      border: 1px solid var(--clay-border);
      padding: 34px;
      animation: modalSlide 0.25s cubic-bezier(0.34, 1.56, 0.64, 1);
    }
    @keyframes modalSlide {
      from { transform: translateY(20px) scale(0.96); opacity: 0; }
      to { transform: translateY(0) scale(1); opacity: 1; }
    }
    @keyframes spin {
      0% { transform: rotate(0deg); }
      100% { transform: rotate(360deg); }
    }
    .clay-spinner {
      display: inline-block;
      width: 20px;
      height: 20px;
      border: 2.5px solid rgba(181, 143, 83, 0.25);
      border-top-color: #56380E;
      border-radius: 50%;
      animation: spin 0.8s linear infinite;
    }
    .btn-loading-state {
      opacity: 0.75;
      cursor: not-allowed !important;
      pointer-events: none !important;
    }
    .clickable-payment-row,
    .clickable-ticket-row {
      cursor: pointer !important;
      transition: background-color 0.16s ease, transform 0.15s ease, box-shadow 0.16s ease;
    }
    .clickable-payment-row:hover,
    .clickable-ticket-row:hover {
      background-color: rgba(245, 217, 158, 0.34) !important;
    }
    .clickable-payment-row:active,
    .clickable-ticket-row:active {
      background-color: rgba(216, 189, 148, 0.4) !important;
    }

    /* Stacking & Dropdown Visibility */
    .clay-sidebar,
    #appSidebar,
    .sidebar-top-header,
    .sidebar-settings-container,
    .header-settings-container {
      overflow: visible !important;
    }

    #sidebarToggleBtn,
    .sidebar-inner-toggle,
    .clay-toggle-btn {
      position: relative !important;
      z-index: 999 !important;
      pointer-events: auto !important;
      cursor: pointer !important;
    }

    #settingsToggleBtn,
    .sidebar-settings-container > button,
    .sidebar-settings-container .clay-btn,
    .header-settings-container > button,
    .header-settings-container .clay-btn {
      position: relative !important;
      z-index: 999 !important;
      pointer-events: auto !important;
      cursor: pointer !important;
    }

    .clay-settings-dropdown {
      z-index: 1050 !important;
      pointer-events: auto !important;
    }

    .settings-dropdown-item {
      position: relative;
      z-index: 1051 !important;
      pointer-events: auto !important;
      cursor: pointer !important;
    }

    .subview-tab-btn {
      display: inline-flex;
      align-items: center;
      gap: 8px;
      padding: 10px 20px;
      border-radius: 12px;
      font-size: 13.5px;
      font-weight: 700;
      cursor: pointer;
      transition: all 0.2s ease;
    }
    .subview-tab-btn.active .clay-badge {
      background: rgba(255, 255, 255, 0.25) !important;
      color: #ffffff !important;
    }

    /* Animated Maintenance Details Modal (Fade-In & Scale-Up Transition) */
    #ticketDetailsModal {
      display: flex !important;
      position: fixed;
      inset: 0;
      background: rgba(15, 23, 42, 0.6);
      backdrop-filter: blur(6px);
      -webkit-backdrop-filter: blur(6px);
      z-index: 10000;
      align-items: center;
      justify-content: center;
      padding: 20px;
      opacity: 0;
      visibility: hidden;
      pointer-events: none;
      transition: opacity 0.24s cubic-bezier(0.16, 1, 0.3, 1), visibility 0.24s ease;
    }
    #ticketDetailsModal.open,
    #ticketDetailsModal.active {
      opacity: 1;
      visibility: visible;
      pointer-events: auto;
    }
    #ticketDetailsModal .ticket-details-modal-box {
      width: 100%;
      max-width: 680px;
      max-height: 90vh;
      overflow-y: auto;
      background: var(--clay-card-bg, #ffffff);
      border-radius: var(--radius-xl, 20px);
      box-shadow: 0 20px 50px -10px rgba(79, 70, 229, 0.3), 0 10px 25px -5px rgba(15, 23, 42, 0.15);
      border: 1.5px solid rgba(166, 180, 204, 0.35);
      padding: 28px;
      transform: scale(0.92) translateY(14px);
      opacity: 0;
      transition: transform 0.26s cubic-bezier(0.34, 1.56, 0.64, 1), opacity 0.22s ease;
    }
    #ticketDetailsModal.open .ticket-details-modal-box,
    #ticketDetailsModal.active .ticket-details-modal-box {
      transform: scale(1) translateY(0);
      opacity: 1;
    }

    /* High Z-Index for Archived Tenant Modal to overlay sidebar and navbar */
    #archivedTenantModal,
    .modal-backdrop#archivedTenantModal {
      z-index: 99999 !important;
      position: fixed !important;
      inset: 0 !important;
    }

    /* Animated Archived Tenant Details Modal (Fade-In & Scale-Up Transition) */
    #archivedTenantDetailsModal {
      display: flex !important;
      position: fixed;
      inset: 0;
      background: rgba(15, 23, 42, 0.6);
      backdrop-filter: blur(6px);
      -webkit-backdrop-filter: blur(6px);
      z-index: 10000;
      align-items: center;
      justify-content: center;
      padding: 20px;
      opacity: 0;
      visibility: hidden;
      pointer-events: none;
      transition: opacity 0.24s cubic-bezier(0.16, 1, 0.3, 1), visibility 0.24s ease;
    }
    #archivedTenantDetailsModal.open,
    #archivedTenantDetailsModal.active {
      opacity: 1;
      visibility: visible;
      pointer-events: auto;
    }
    #archivedTenantDetailsModal .archived-modal-box {
      width: 100%;
      max-width: 860px;
      max-height: 90vh;
      overflow-y: auto;
      background: var(--clay-card-bg, #ffffff);
      border-radius: var(--radius-xl, 20px);
      box-shadow: 0 20px 50px -10px rgba(79, 70, 229, 0.3), 0 10px 25px -5px rgba(15, 23, 42, 0.15);
      border: 1.5px solid rgba(166, 180, 204, 0.35);
      padding: 28px;
      transform: scale(0.92) translateY(14px);
      opacity: 0;
      transition: transform 0.26s cubic-bezier(0.34, 1.56, 0.64, 1), opacity 0.22s ease;
    }
    #archivedTenantDetailsModal.open .archived-modal-box,
    #archivedTenantDetailsModal.active .archived-modal-box {
      transform: scale(1) translateY(0);
      opacity: 1;
    }

    /* Animated Owner Password Confirmation Modal for Tenant Deletion */
    #ownerDeleteConfirmModal {
      display: flex !important;
      position: fixed;
      inset: 0;
      background: rgba(15, 23, 42, 0.65);
      backdrop-filter: blur(6px);
      -webkit-backdrop-filter: blur(6px);
      z-index: 10050;
      align-items: center;
      justify-content: center;
      padding: 20px;
      opacity: 0;
      visibility: hidden;
      pointer-events: none;
      transition: opacity 0.22s cubic-bezier(0.16, 1, 0.3, 1), visibility 0.22s ease;
    }
    #ownerDeleteConfirmModal.open,
    #ownerDeleteConfirmModal.active {
      opacity: 1;
      visibility: visible;
      pointer-events: auto;
    }
    #ownerDeleteConfirmModal .owner-delete-modal-box {
      width: 100%;
      max-width: 480px;
      background: var(--clay-card-bg, #ffffff);
      border-radius: var(--radius-xl, 20px);
      box-shadow: 0 24px 55px -12px rgba(225, 29, 72, 0.28), 0 10px 25px -5px rgba(15, 23, 42, 0.2);
      border: 1.5px solid rgba(254, 205, 211, 0.9);
      padding: 26px;
      transform: scale(0.92) translateY(14px);
      opacity: 0;
      transition: transform 0.24s cubic-bezier(0.34, 1.56, 0.64, 1), opacity 0.2s ease;
    }
    #ownerDeleteConfirmModal.open .owner-delete-modal-box,
    #ownerDeleteConfirmModal.active .owner-delete-modal-box {
      transform: scale(1) translateY(0);
      opacity: 1;
    }

    /* Animated Move-Out Confirmation & Settlement Audit Modal */
    #moveOutConfirmModal {
      display: flex !important;
      position: fixed;
      inset: 0;
      background: rgba(15, 23, 42, 0.62);
      backdrop-filter: blur(6px);
      -webkit-backdrop-filter: blur(6px);
      z-index: 10045;
      align-items: center;
      justify-content: center;
      padding: 20px;
      opacity: 0;
      visibility: hidden;
      pointer-events: none;
      transition: opacity 0.22s cubic-bezier(0.16, 1, 0.3, 1), visibility 0.22s ease;
    }
    #moveOutConfirmModal.open,
    #moveOutConfirmModal.active {
      opacity: 1;
      visibility: visible;
      pointer-events: auto;
    }
    #moveOutConfirmModal .moveout-modal-box {
      width: 100%;
      max-width: 580px;
      max-height: 90vh;
      overflow-y: auto;
      background: var(--clay-card-bg, #fffdf8);
      border-radius: var(--radius-xl, 20px);
      box-shadow: 0 24px 55px -12px rgba(86, 56, 14, 0.28), 0 10px 25px -5px rgba(15, 23, 42, 0.22);
      border: 1.5px solid rgba(181, 143, 83, 0.45);
      padding: 26px;
      transform: scale(0.92) translateY(14px);
      opacity: 0;
      transition: transform 0.24s cubic-bezier(0.34, 1.56, 0.64, 1), opacity 0.2s ease;
    }
    #moveOutConfirmModal.open .moveout-modal-box,
    #moveOutConfirmModal.active .moveout-modal-box {
      transform: scale(1) translateY(0);
      opacity: 1;
    }
    .moveout-audit-grid {
      display: grid;
      grid-template-columns: repeat(3, minmax(0, 1fr));
      gap: 10px;
      margin-bottom: 12px;
    }
    @media (max-width: 600px) {
      .moveout-audit-grid {
        grid-template-columns: 1fr;
      }
      #archivedTenantDetailsModal .archived-info-grid {
        grid-template-columns: 1fr !important;
      }
    }

    /* =========================================================================
       NEW RESIDENT REGISTRATION FORM MODAL (Matched to Reference Design)
       ========================================================================= */
    #addTenantModal .resident-reg-modal {
      max-width: 560px;
      width: 100%;
      max-height: 92vh;
      overflow-y: auto;
      background: #ffffff;
      border: 2px solid #8c6d46;
      border-radius: 14px;
      padding: 30px 28px 26px;
      box-shadow: 0 24px 55px -12px rgba(43, 30, 12, 0.28);
      position: relative;
      color: #1c140d;
    }
    #addTenantModal .resident-reg-close {
      position: absolute;
      top: 14px;
      right: 14px;
      z-index: 50;
      cursor: pointer;
    }
    #addTenantModal .resident-reg-header {
      text-align: center;
      margin-bottom: 10px;
    }
    #addTenantModal .resident-reg-title {
      font-family: 'Playfair Display', Georgia, 'Times New Roman', serif;
      font-size: 21px;
      font-weight: 800;
      line-height: 1.25;
      letter-spacing: 0.03em;
      color: #1c140d;
      text-transform: uppercase;
      margin: 0;
    }
    #addTenantModal .resident-reg-unit-bar {
      display: flex;
      align-items: center;
      justify-content: center;
      gap: 8px;
      margin-top: 8px;
      flex-wrap: wrap;
    }
    #addTenantModal .resident-reg-unit-label {
      font-family: 'Playfair Display', Georgia, 'Times New Roman', serif;
      font-size: 12.5px;
      font-weight: 600;
      color: #5c4324;
    }
    #addTenantModal .resident-reg-unit-select {
      font-family: 'Plus Jakarta Sans', sans-serif;
      font-size: 12px;
      font-weight: 600;
      color: #2b2116;
      background: #f5efe6;
      border: 1px solid #8c6d46;
      border-radius: 9999px;
      padding: 4px 12px;
      outline: none;
      cursor: pointer;
    }
    #addTenantModal .resident-reg-divider {
      border: none;
      border-top: 1.5px solid #8c704c;
      margin: 14px 0 20px;
    }
    #addTenantModal .resident-reg-divider-mid {
      border: none;
      border-top: 1.5px solid #8c704c;
      margin: 22px 0 20px;
    }
    #addTenantModal .resident-reg-row-3 {
      display: grid;
      grid-template-columns: repeat(3, minmax(0, 1fr));
      gap: 14px;
      margin-bottom: 16px;
    }
    #addTenantModal .resident-reg-row-2 {
      display: grid;
      grid-template-columns: repeat(2, minmax(0, 1fr));
      gap: 18px;
      margin-bottom: 16px;
    }
    #addTenantModal .resident-reg-field {
      min-width: 0;
    }
    #addTenantModal .resident-reg-label {
      display: block;
      font-family: 'Playfair Display', Georgia, 'Times New Roman', serif;
      font-size: 14.5px;
      font-weight: 500;
      color: #1f170f;
      margin-bottom: 6px;
    }
    #addTenantModal .resident-reg-pill-input {
      width: 100%;
      height: 38px;
      padding: 6px 14px;
      border-radius: 9999px;
      background: #e8e0d5;
      border: 1.2px solid #2b2116;
      box-shadow: 0 3px 6px rgba(0, 0, 0, 0.14);
      font-family: 'Plus Jakarta Sans', sans-serif;
      font-size: 13px;
      color: #1c140d;
      outline: none;
      transition: border-color 0.18s ease, box-shadow 0.18s ease, background-color 0.18s ease;
    }
    #addTenantModal .resident-reg-pill-input:focus {
      border-color: #7c521b;
      background: #efe9e0;
      box-shadow: 0 0 0 3px rgba(150, 97, 28, 0.18), 0 3px 6px rgba(0, 0, 0, 0.14);
    }
    #addTenantModal input[type="date"].resident-reg-date-input::-webkit-calendar-picker-indicator {
      cursor: pointer;
      opacity: 0.78;
    }
    #addTenantModal .resident-reg-box {
      background: #e8e0d5;
      border-radius: 18px;
      border: 1.2px solid #2b2116;
      box-shadow: 0 3px 7px rgba(0, 0, 0, 0.14);
      padding: 14px 20px 18px;
      margin-bottom: 18px;
    }
    #addTenantModal .resident-reg-box-title {
      font-family: 'Playfair Display', Georgia, 'Times New Roman', serif;
      font-size: 15.5px;
      font-weight: 500;
      color: #1f170f;
      margin: 0 0 10px 0;
      text-align: left;
    }
    #addTenantModal .resident-reg-occupant-grid {
      display: grid;
      grid-template-columns: repeat(3, minmax(0, 1fr));
      gap: 16px;
      align-items: end;
    }
    #addTenantModal .resident-reg-emergency-row {
      display: grid;
      grid-template-columns: repeat(2, minmax(0, 1fr));
      gap: 16px;
    }
    #addTenantModal .resident-reg-sub-label {
      display: block;
      font-family: 'Playfair Display', Georgia, 'Times New Roman', serif;
      font-size: 11.5px;
      font-weight: 500;
      color: #1f170f;
      margin-bottom: 5px;
    }
    #addTenantModal .resident-reg-rect-input {
      width: 100%;
      height: 34px;
      padding: 5px 10px;
      border-radius: 7px;
      background: #ffffff;
      border: 1.2px solid #2b2116;
      box-shadow: 0 2px 5px rgba(0, 0, 0, 0.12);
      font-family: 'Plus Jakarta Sans', sans-serif;
      font-size: 13px;
      color: #1c140d;
      outline: none;
      transition: border-color 0.18s ease, box-shadow 0.18s ease;
    }
    #addTenantModal .resident-reg-rect-input:focus {
      border-color: #7c521b;
      box-shadow: 0 0 0 3px rgba(150, 97, 28, 0.18), 0 2px 5px rgba(0, 0, 0, 0.12);
    }
    #addTenantModal .resident-reg-num-input {
      text-align: center;
      font-weight: 600;
    }
    #addTenantModal .resident-reg-total-wrap {
      display: flex;
      flex-direction: column;
      align-items: center;
      justify-content: flex-end;
    }
    #addTenantModal .resident-reg-total-input {
      width: 100%;
      height: 32px;
      background: transparent;
      border: none;
      border-bottom: 1.5px solid #1c140d;
      border-radius: 0;
      text-align: center;
      font-family: 'Playfair Display', Georgia, 'Times New Roman', serif;
      font-size: 15px;
      font-weight: 700;
      color: #1c140d;
      outline: none;
      cursor: default;
    }
    #addTenantModal .resident-reg-submit-row {
      display: flex;
      justify-content: center;
      margin-top: 20px;
    }
    #addTenantModal .resident-reg-submit-btn {
      min-width: 225px;
      padding: 10px 38px;
      border-radius: 9999px;
      background: #96611c;
      border: 1.2px solid #2b2116;
      box-shadow: 0 3px 7px rgba(0, 0, 0, 0.18);
      color: #1c1206;
      font-family: 'Playfair Display', Georgia, 'Times New Roman', serif;
      font-size: 14px;
      font-weight: 600;
      letter-spacing: 0.06em;
      text-transform: uppercase;
      cursor: pointer;
      transition: transform 0.16s ease, background-color 0.16s ease, box-shadow 0.16s ease;
    }
    #addTenantModal .resident-reg-submit-btn:hover:not(:disabled) {
      background: #845315;
      transform: translateY(-1px);
      box-shadow: 0 5px 12px rgba(0, 0, 0, 0.22);
    }
    #addTenantModal .resident-reg-submit-btn:disabled {
      opacity: 0.7;
      cursor: not-allowed;
    }
    #addTenantModal .resident-reg-box-desc {
      margin: -4px 0 12px;
      font-family: 'Plus Jakarta Sans', sans-serif;
      font-size: 12px;
      line-height: 1.45;
      color: #54412e;
    }
    #addTenantModal .resident-reg-space-list {
      display: grid;
      grid-template-columns: repeat(2, minmax(0, 1fr));
      gap: 10px;
    }
    #addTenantModal .resident-reg-space-item {
      display: flex;
      align-items: center;
      gap: 7px;
      padding: 9px 10px;
      min-width: 0;
      box-sizing: border-box;
      border-radius: 7px;
      background: #ffffff;
      border: 1.2px solid #2b2116;
      box-shadow: 0 2px 5px rgba(0, 0, 0, 0.12);
      font-family: 'Plus Jakarta Sans', sans-serif;
      font-size: 12.5px;
      font-weight: 600;
      color: #1c140d;
      cursor: pointer;
      user-select: none;
      transition: border-color 0.18s ease, background-color 0.18s ease, box-shadow 0.18s ease;
    }
    #addTenantModal .resident-reg-space-item:hover:not(.is-occupied) {
      border-color: #7c521b;
      background: #fffcf7;
    }
    #addTenantModal .resident-reg-space-item:has(input:checked):not(.is-occupied) {
      border-color: #7c521b;
      background: #fdf6ea;
      box-shadow: 0 0 0 2px rgba(150, 97, 28, 0.16), 0 2px 5px rgba(0, 0, 0, 0.12);
    }
    #addTenantModal .resident-reg-space-item.is-occupied {
      background: #e5dfd6;
      border-color: #9e9384;
      color: #78716c;
      opacity: 0.78;
      cursor: not-allowed;
      box-shadow: none;
    }
    #addTenantModal .resident-reg-space-checkbox {
      width: 16px;
      height: 16px;
      margin: 0;
      flex-shrink: 0;
      accent-color: #96611c;
      cursor: inherit;
    }
    #addTenantModal .resident-reg-space-text {
      flex: 1;
      min-width: 0;
      line-height: 1.25;
      overflow-wrap: break-word;
    }
    #addTenantModal .resident-reg-occupied-tag {
      margin-left: auto;
      flex-shrink: 0;
      padding: 2px 6px;
      border-radius: 9999px;
      background: #d6ccc0;
      color: #4f463c;
      font-size: 9.5px;
      font-weight: 700;
      letter-spacing: 0.04em;
      text-transform: uppercase;
      white-space: nowrap;
    }
    #addTenantModal .resident-reg-space-summary {
      display: flex;
      align-items: baseline;
      justify-content: space-between;
      flex-wrap: wrap;
      gap: 8px 16px;
      margin-top: 12px;
      padding-top: 10px;
      border-top: 1px dashed rgba(43, 33, 22, 0.28);
    }
    #addTenantModal .resident-reg-total-rent-preview {
      font-family: 'Plus Jakarta Sans', sans-serif;
      font-size: 12.5px;
      font-weight: 600;
      color: #4b3926;
    }
    #addTenantModal .resident-reg-additional-rent {
      margin-left: auto;
      text-align: right;
      font-family: 'Playfair Display', Georgia, 'Times New Roman', serif;
      font-size: 14px;
      font-weight: 700;
      color: #1c140d;
    }
    /* Archived Tenants Redesign - Global Theme Container & Main Actions */
    .archived-tenants-beige-container {
      background: #e8e0d5;
      border-radius: 18px;
      border: 1.2px solid #2b2116;
      box-shadow: 0 4px 14px rgba(43, 33, 22, 0.14);
      padding: 22px 24px 26px;
      margin-top: 8px;
    }
    .archived-main-action-btn {
      display: inline-flex;
      align-items: center;
      justify-content: center;
      gap: 6px;
      padding: 8px 18px;
      border-radius: 9999px;
      background: #96611c;
      border: 1.2px solid #2b2116;
      box-shadow: 0 2px 5px rgba(0, 0, 0, 0.14);
      color: #1c1206 !important;
      font-family: 'Plus Jakarta Sans', sans-serif;
      font-size: 13px;
      font-weight: 700;
      cursor: pointer;
      transition: transform 0.15s ease, background-color 0.15s ease, box-shadow 0.15s ease;
      text-decoration: none;
      white-space: nowrap;
    }
    .archived-main-action-btn:hover {
      background: #845315;
      transform: translateY(-1px);
      box-shadow: 0 4px 8px rgba(0, 0, 0, 0.18);
    }
    .archived-main-action-btn.archived-btn-sm {
      padding: 5px 12px;
      font-size: 12px;
    }
    .archived-table-wrapper {
      background: #ffffff;
      border-radius: 12px;
      border: 1.2px solid #2b2116;
      overflow: hidden;
      box-shadow: 0 2px 6px rgba(0, 0, 0, 0.08);
    }
    .archived-tenant-row {
      cursor: pointer;
      transition: background-color 0.15s ease;
    }
    .archived-tenant-row:hover {
      background-color: rgba(232, 216, 176, 0.5) !important;
    }
    @media (max-width: 560px) {
      #addTenantModal .resident-reg-modal {
        padding: 24px 16px 22px;
      }
      #addTenantModal .resident-reg-row-3,
      #addTenantModal .resident-reg-row-2,
      #addTenantModal .resident-reg-occupant-grid,
      #addTenantModal .resident-reg-space-list,
      #addTenantModal .resident-reg-emergency-row {
        grid-template-columns: 1fr;
        gap: 12px;
      }
      #addTenantModal .resident-reg-additional-rent {
        margin-left: 0;
      }
    }
  </style>
</head>
<body class="admin-warm-theme">

  <?php
  // Shared RBAC-Protected Sidebar Component (Landlord/Owner Context Only)
  $sidebarConfig = [
      'portal' => 'landlord',
      'active_tickets_count' => count($activeTickets),
      'pending_reports_count' => count($pendingReports),
      'pending_payments_count' => $pendingReviewCount,
  ];
  require __DIR__ . '/includes/sidebar.php';
  ?>

    <!-- ===================================================================
         MAIN WORKSPACE AREA
         =================================================================== -->
    <main class="main-content">

      <!-- =================================================================
           STICKY TOP NAVIGATION BAR (Centered Search + Bell + Owner Profile)
           ================================================================= -->
      <header class="top-nav-bar">
        <div class="top-nav-left-slot">
          <button type="button" class="mobile-menu-trigger" onclick="toggleSidebar()" aria-label="Open Navigation Menu" title="Open Menu">
            <i data-lucide="menu" class="w-5 h-5 pointer-events-none" style="pointer-events: none;" aria-hidden="true"></i>
          </button>
          <span class="mobile-brand-chip">TaraRent</span>
        </div>

        <!-- Centered Search Bar -->
        <div class="top-nav-search-wrap">
          <div class="top-search-box">
            <span class="top-search-icon" aria-hidden="true">
              <i data-lucide="search" style="width: 16px; height: 16px;"></i>
            </span>
            <input type="search"
                   id="ownerGlobalSearchInput"
                   autocomplete="off"
                   placeholder="Search tenants, units, tickets, payments..."
                   aria-label="Search owner dashboard">
            <button type="button"
                    id="ownerGlobalSearchClear"
                    class="top-search-clear-btn"
                    onclick="clearGlobalOwnerSearch()"
                    aria-label="Clear search"
                    title="Clear search"><i data-lucide="x" class="w-3.5 h-3.5" aria-hidden="true"></i></button>
          </div>
          <div id="globalSearchDropdown" class="global-search-dropdown" role="listbox" aria-label="Search Results"></div>
        </div>

        <!-- Right Controls: Notification Bell + Owner Profile & Name -->
        <div class="top-nav-right">
          <!-- Notification Bell Trigger and Dropdown Panel -->
          <div class="header-notification-container" style="position: relative; z-index: 1250;">
            <style>
              .top-nav-bar:has(#notificationDropdown.show) { z-index: 1250 !important; }
              #headerNotificationBtn {
                position: relative !important;
                z-index: 1255 !important;
                cursor: pointer !important;
                pointer-events: auto !important;
                touch-action: manipulation;
              }
              #headerNotificationBtn i,
              #headerNotificationBtn svg,
              #headerNotificationBtn .notification-badge {
                pointer-events: none !important;
                cursor: pointer !important;
              }
              #notificationDropdown.notification-dropdown-panel {
                z-index: 1260 !important;
                pointer-events: auto !important;
              }
              #notificationDropdown.notification-dropdown-panel.show {
                display: flex !important;
                flex-direction: column;
              }
            </style>
            <button type="button"
                    class="header-notification-btn"
                    id="headerNotificationBtn"
                    onclick="toggleNotificationPanel(event)"
                    title="Notifications"
                    aria-label="Notifications"
                    aria-haspopup="true"
                    aria-expanded="false"
                    aria-controls="notificationDropdown">
              <i data-lucide="bell" aria-hidden="true"></i>
              <span class="notification-badge" id="notificationBadge" style="display: none;">0</span>
            </button>

            <!-- Notification Dropdown Panel -->
            <div class="clay-settings-dropdown notification-dropdown-panel" id="notificationDropdown" role="region" aria-label="Notifications">
              <div class="notification-panel-header">
                <div class="notification-title-group" style="display: flex; align-items: center; gap: 8px;">
                  <h3 class="notification-heading">Notifications</h3>
                  <span class="notification-counter-pill" id="notificationUnreadPill" style="display: none;">0 new</span>
                </div>
                <button type="button" class="notification-mark-all-btn" id="btnMarkAllRead" onclick="markAllNotificationsAsRead(event)">
                  Mark all as read
                </button>
              </div>

              <div class="notification-items-scroll" id="notificationListContainer">
                <div class="notification-empty-state" id="notificationEmptyFallback">
                  <div class="empty-icon"><i data-lucide="bell-off" aria-hidden="true"></i></div>
                  <h4>No Notifications Yet</h4>
                  <p>You're all caught up! No new notifications at this time.</p>
                </div>
              </div>
            </div>
          </div>

          <!-- Owner Profile Trigger (Avatar/Initial + Owner Name Beside Profile) -->
          <div class="sidebar-settings-container header-settings-container" style="position: relative;">
            <div class="header-owner-trigger" id="headerProfileTrigger" onclick="toggleSettingsMenu(event)" role="button" tabindex="0" title="Account &amp; Settings" aria-label="Owner Account Menu">
              <div class="header-avatar-badge" id="headerAvatarBadge">
                <?php if (!empty($userAvatarUrl)): ?>
                  <img src="<?= $userAvatarUrl ?>" alt="<?= htmlspecialchars($user['name'] ?? 'Owner') ?>" class="header-avatar-img">
                <?php else: ?>
                  <span class="header-avatar-initial"><?= htmlspecialchars($userInitial) ?></span>
                <?php endif; ?>
              </div>
              <div class="header-owner-meta">
                <span class="header-owner-name" id="headerUserName"><?= htmlspecialchars($user['name'] ?? 'Leah Dimaculangan') ?></span>
                <span class="header-owner-role" style="display: inline-flex; align-items: center; gap: 4px;"><i data-lucide="circle-user" class="w-3.5 h-3.5" aria-hidden="true"></i>Owner</span>
              </div>
            </div>

            <!-- Preserved Profile Dropdown Menu -->
            <div class="clay-settings-dropdown fb-profile-dropdown" id="settingsDropdown">
              <div class="fb-profile-card">
                <div class="fb-profile-main-row fb-profile-display-only">
                  <div class="fb-profile-avatar-circle">
                    <?php if (!empty($userAvatarUrl)): ?>
                      <img src="<?= $userAvatarUrl ?>" alt="<?= htmlspecialchars($user['name'] ?? 'User') ?>">
                    <?php else: ?>
                      <span><?= htmlspecialchars($userInitial) ?></span>
                    <?php endif; ?>
                  </div>
                  <div class="fb-profile-main-info">
                    <span class="fb-profile-name" id="profileDropdownName"><?= htmlspecialchars($user['name'] ?? 'Leah Dimaculangan') ?></span>
                  </div>
                </div>
              </div>

              <div class="fb-actions-group">
                <a href="settings.php" class="fb-dropdown-action-item cursor-pointer hover:bg-gray-200 hover:text-blue-600 transition-colors duration-150" id="settingsToggleBtn" onclick="openProfileView('accountInfo'); return false;" role="button" title="Settings &amp; privacy">
                  <div class="fb-action-icon-circle">
                    <i data-lucide="settings" style="width: 18px; height: 18px;"></i>
                  </div>
                  <span class="fb-action-label">Settings &amp; privacy</span>
                  <span class="fb-action-arrow">
                    <i data-lucide="chevron-right" style="width: 16px; height: 16px;"></i>
                  </span>
                </a>

                <a href="logout.php" class="fb-dropdown-action-item fb-action-item-danger" title="Sign Out">
                  <div class="fb-action-icon-circle">
                    <i data-lucide="log-out" style="width: 18px; height: 18px;"></i>
                  </div>
                  <span class="fb-action-label">Sign Out</span>
                  <span class="fb-action-arrow">
                    <i data-lucide="chevron-right" style="width: 16px; height: 16px;"></i>
                  </span>
                </a>
              </div>
            </div>
          </div>
        </div>
      </header>

      <!-- Uniform Content Wrapper Shared Across All Owner Modules -->
      <div class="admin-content-container">

      <!-- SECTION 1: OWNER DASHBOARD & BUSINESS ANALYTICS (60-30-10 CLAYMORPHIC THEME) -->
      <section id="sectionAnalytics" class="app-view-section active-section">

        <?php
          $currentMonthPrefix = date('Y-m');
          $thisMonthCollectedInit = 0.0;
          foreach ($payments as $payItem) {
              $payStatus = strtolower((string)($payItem['status'] ?? ''));
              $payCycle = (string)($payItem['billing_cycle'] ?? '');
              $payDate = (string)($payItem['payment_date'] ?? '');
              if ($payStatus === 'approved') {
                  if (str_starts_with($payCycle, $currentMonthPrefix) || str_starts_with($payDate, $currentMonthPrefix)) {
                      $thisMonthCollectedInit += (float)($payItem['amount_paid'] ?? 0);
                  }
              }
          }
          $totalUnitsCountInit = count($units);
          $activeTicketsCountInit = count($activeTickets);
        ?>

        <style>
          /* Scoped 60-30-10 Warm Claymorphic Owner & BA Styles (#sectionAnalytics only) */
          #sectionAnalytics .owner-welcome-bar {
            display: block;
            margin-bottom: 20px;
          }
          #sectionAnalytics .owner-welcome-title {
            margin: 0;
            font-size: clamp(1.45rem, 2.2vw, 1.9rem);
            font-weight: 800;
            color: #4A3728;
            letter-spacing: -0.02em;
          }
          #sectionAnalytics .owner-welcome-sub {
            margin: 4px 0 0 0;
            font-size: 13.5px;
            font-weight: 500;
            color: #8C6D43;
          }

          /* Pillar 4: Recommended Actions for Today Banner */
          #sectionAnalytics .ba-insights-banner {
            background: linear-gradient(145deg, #F5F0E6 0%, #E8D8B0 100%);
            border: 1.5px solid rgba(140, 109, 67, 0.36);
            border-radius: 22px;
            box-shadow: 0 14px 28px -8px rgba(74, 55, 40, 0.22), inset 0 1px 0 rgba(255, 255, 255, 0.78);
            padding: 20px 24px;
            margin-bottom: 24px;
          }
          #sectionAnalytics .ba-insights-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 12px;
            margin-bottom: 14px;
          }
          #sectionAnalytics .ba-insights-title-wrap {
            display: flex;
            align-items: center;
            gap: 12px;
          }
          #sectionAnalytics .ba-insights-icon {
            width: 40px;
            height: 40px;
            border-radius: 12px;
            background: #4A3728;
            color: #F5F0E6;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            box-shadow: 0 6px 14px rgba(74, 55, 40, 0.25);
            flex-shrink: 0;
          }
          #sectionAnalytics .ba-insights-title {
            margin: 0;
            font-size: 17px;
            font-weight: 800;
            color: #4A3728;
          }
          #sectionAnalytics .ba-insights-sub {
            margin: 2px 0 0 0;
            font-size: 12.5px;
            font-weight: 600;
            color: #8C6D43;
          }
          #sectionAnalytics .ba-pillar-pill {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 5px 13px;
            border-radius: 9999px;
            font-size: 11px;
            font-weight: 800;
            letter-spacing: 0.03em;
            background: #4A3728;
            color: #F5F0E6;
          }
          #sectionAnalytics .ba-insights-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(270px, 1fr));
            gap: 14px;
          }
          #sectionAnalytics .ba-insight-item {
            background: rgba(245, 240, 230, 0.94);
            border: 1px solid rgba(140, 109, 67, 0.28);
            border-left: 5px solid #8C6D43;
            border-radius: 14px;
            padding: 14px 16px;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            gap: 10px;
            box-shadow: 0 6px 14px -4px rgba(74, 55, 40, 0.14);
            transition: transform 0.16s ease;
          }
          #sectionAnalytics .ba-insight-item:hover {
            transform: translateY(-2px);
          }
          #sectionAnalytics .ba-insight-item.sev-urgent {
            border-left-color: #4A3728;
          }
          #sectionAnalytics .ba-insight-top {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 8px;
            flex-wrap: wrap;
          }
          #sectionAnalytics .ba-insight-badge {
            font-size: 10.5px;
            font-weight: 800;
            padding: 3px 9px;
            border-radius: 9999px;
            background: #E8D8B0;
            color: #4A3728;
          }
          #sectionAnalytics .ba-insight-item.sev-urgent .ba-insight-badge {
            background: #4A3728;
            color: #F5F0E6;
          }
          #sectionAnalytics .ba-insight-metric {
            font-size: 11.5px;
            font-weight: 800;
            color: #8C6D43;
          }
          #sectionAnalytics .ba-insight-item h4 {
            margin: 6px 0 4px 0;
            font-size: 13.5px;
            font-weight: 800;
            color: #4A3728;
            line-height: 1.3;
          }
          #sectionAnalytics .ba-insight-item p {
            margin: 0;
            font-size: 12.2px;
            font-weight: 500;
            color: #6E5330;
            line-height: 1.42;
          }
          #sectionAnalytics .ba-insight-action-btn {
            align-self: flex-start;
            background: #B89B72;
            color: #F5F0E6;
            border: 1px solid #8C6D43;
            border-radius: 10px;
            padding: 6px 12px;
            font-size: 11.5px;
            font-weight: 700;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            transition: background 0.16s ease;
          }
          #sectionAnalytics .ba-insight-action-btn:hover {
            background: #4A3728;
          }

          /* Clickable Priority KPI Cards */
          #sectionAnalytics .kpi-card.clickable-kpi {
            cursor: pointer;
            position: relative;
            justify-content: space-between;
          }
          #sectionAnalytics .kpi-card-top-row {
            width: 100%;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 8px;
            margin-bottom: 8px;
          }
          #sectionAnalytics .kpi-pill-tag {
            font-size: 10.5px;
            font-weight: 800;
            padding: 3px 10px;
            border-radius: 9999px;
            background: rgba(245, 240, 230, 0.88);
            color: #4A3728;
            border: 1px solid rgba(140, 109, 67, 0.25);
          }
          #sectionAnalytics .kpi-pill-tag.accent {
            background: #4A3728;
            color: #F5F0E6;
          }
          #sectionAnalytics .kpi-action-hint {
            margin-top: 10px;
            font-size: 11.5px;
            font-weight: 800;
            color: #4A3728;
            display: inline-flex;
            align-items: center;
            gap: 5px;
          }

          /* Pillar 1: Descriptive BA Metrics Strip */
          #sectionAnalytics .ba-descriptive-grid {
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 20px;
            margin-bottom: 28px;
          }
          #sectionAnalytics .ba-metric-card {
            background: linear-gradient(160deg, #F5F0E6 0%, #E8D8B0 100%);
            border: 1px solid rgba(140, 109, 67, 0.28);
            border-radius: 20px;
            box-shadow: 0 12px 24px -8px rgba(74, 55, 40, 0.22), inset 0 1px 0 rgba(255, 255, 255, 0.72);
            padding: 20px 22px;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            gap: 8px;
            transition: transform 0.2s ease, box-shadow 0.2s ease;
          }
          #sectionAnalytics .ba-metric-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 16px 28px -8px rgba(74, 55, 40, 0.28);
          }
          #sectionAnalytics .ba-metric-top {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 10px;
            flex-wrap: wrap;
          }
          #sectionAnalytics .ba-metric-title {
            font-size: 12px;
            font-weight: 800;
            letter-spacing: 0.02em;
            color: #4A3728;
          }
          #sectionAnalytics .ba-status-chip {
            font-size: 10.5px;
            font-weight: 800;
            padding: 3px 10px;
            border-radius: 9999px;
            background: rgba(184, 155, 114, 0.28);
            color: #4A3728;
            border: 1px solid rgba(140, 109, 67, 0.25);
          }
          #sectionAnalytics .ba-status-chip.accent {
            background: #4A3728;
            color: #F5F0E6;
          }
          #sectionAnalytics .ba-metric-val {
            font-size: clamp(1.5rem, 2.2vw, 2rem);
            font-weight: 800;
            color: #4A3728;
            letter-spacing: -0.025em;
            line-height: 1.1;
          }
          #sectionAnalytics .ba-metric-sub {
            font-size: 12px;
            font-weight: 600;
            color: #8C6D43;
            line-height: 1.35;
          }

          /* Pillar 2 & 3 Two-Column Grids */
          #sectionAnalytics .ba-two-col-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 24px;
            margin-bottom: 28px;
          }
          #sectionAnalytics .ba-two-col-grid > * {
            min-width: 0;
          }
          #sectionAnalytics .ba-card {
            background: #F5F0E6;
            border: 1.5px solid rgba(140, 109, 67, 0.3);
            border-radius: 24px;
            box-shadow: 0 14px 28px -8px rgba(74, 55, 40, 0.2), inset 0 1px 0 rgba(255, 255, 255, 0.7);
            padding: 24px;
            display: flex;
            flex-direction: column;
            gap: 16px;
          }
          #sectionAnalytics .ba-card-head {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 10px;
          }
          #sectionAnalytics .ba-card-title {
            margin: 0;
            font-size: 17px;
            font-weight: 800;
            color: #4A3728;
          }
          #sectionAnalytics .ba-card-sub {
            margin: 3px 0 0 0;
            font-size: 12.5px;
            font-weight: 500;
            color: #8C6D43;
          }

          /* Forecast Hero Strip */
          #sectionAnalytics .ba-forecast-hero {
            background: linear-gradient(135deg, #E8D8B0 0%, #B89B72 100%);
            border: 1px solid rgba(74, 55, 40, 0.22);
            border-radius: 16px;
            padding: 16px 18px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 12px;
            box-shadow: inset 0 1px 0 rgba(255, 255, 255, 0.5);
          }
          #sectionAnalytics .ba-forecast-amount {
            font-size: 1.75rem;
            font-weight: 900;
            color: #4A3728;
            line-height: 1.1;
          }
          #sectionAnalytics .ba-forecast-breakdown {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 10px;
          }
          #sectionAnalytics .ba-mini-stat {
            background: rgba(245, 240, 230, 0.85);
            border: 1px solid rgba(140, 109, 67, 0.22);
            border-radius: 12px;
            padding: 10px 12px;
          }
          #sectionAnalytics .ba-mini-stat-label {
            font-size: 11px;
            font-weight: 700;
            color: #8C6D43;
            letter-spacing: 0.02em;
          }
          #sectionAnalytics .ba-mini-stat-val {
            font-size: 14px;
            font-weight: 800;
            color: #4A3728;
            margin-top: 2px;
          }
          #sectionAnalytics .ba-chart-box {
            position: relative;
            width: 100%;
            height: 225px;
          }

          /* Readable Tables (Risk & Pareto Breakdown) */
          #sectionAnalytics .ba-risk-pills {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
          }
          #sectionAnalytics .ba-risk-pill {
            font-size: 11px;
            font-weight: 800;
            padding: 4px 11px;
            border-radius: 9999px;
            background: #E8D8B0;
            color: #4A3728;
            border: 1px solid rgba(140, 109, 67, 0.25);
          }
          #sectionAnalytics .ba-risk-pill.high {
            background: #4A3728;
            color: #F5F0E6;
          }
          #sectionAnalytics .ba-table-wrap {
            overflow-x: auto;
            border-radius: 14px;
            border: 1px solid rgba(140, 109, 67, 0.24);
            background: rgba(245, 240, 230, 0.72);
          }
          #sectionAnalytics .ba-data-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 12.2px;
          }
          #sectionAnalytics .ba-data-table th {
            text-align: left;
            font-size: 10.5px;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: 0.04em;
            color: #4A3728;
            background: #E8D8B0;
            padding: 10px 11px;
            border-bottom: 1.5px solid rgba(140, 109, 67, 0.28);
            white-space: nowrap;
          }
          #sectionAnalytics .ba-data-table td {
            padding: 10px 11px;
            color: #4A3728;
            font-weight: 600;
            border-bottom: 1px solid rgba(140, 109, 67, 0.14);
            vertical-align: middle;
          }
          #sectionAnalytics .ba-risk-bar-track {
            width: 72px;
            height: 7px;
            border-radius: 9999px;
            background: rgba(140, 109, 67, 0.2);
            overflow: hidden;
            display: inline-block;
            vertical-align: middle;
            margin-right: 6px;
          }
          #sectionAnalytics .ba-risk-bar-fill {
            height: 100%;
            border-radius: 9999px;
          }

          /* Toggle & Unit Watchlist */
          #sectionAnalytics .ba-toggle-group {
            display: inline-flex;
            background: #E8D8B0;
            padding: 3px;
            border-radius: 10px;
            border: 1px solid rgba(140, 109, 67, 0.25);
            gap: 3px;
          }
          #sectionAnalytics .ba-toggle-btn {
            border: none;
            background: transparent;
            color: #8C6D43;
            font-size: 11.5px;
            font-weight: 700;
            padding: 5px 10px;
            border-radius: 8px;
            cursor: pointer;
            transition: all 0.15s ease;
          }
          #sectionAnalytics .ba-toggle-btn.active {
            background: #4A3728;
            color: #F5F0E6;
          }
          #sectionAnalytics .ba-unit-health-list {
            display: flex;
            flex-direction: column;
            gap: 10px;
          }
          #sectionAnalytics .ba-unit-health-row {
            background: rgba(232, 216, 176, 0.35);
            border: 1px solid rgba(140, 109, 67, 0.22);
            border-radius: 14px;
            padding: 11px 14px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            flex-wrap: wrap;
          }
          #sectionAnalytics .ba-empty-box {
            background: rgba(232, 216, 176, 0.35);
            border: 1px dashed rgba(140, 109, 67, 0.4);
            border-radius: 14px;
            padding: 20px 16px;
            text-align: center;
            color: #8C6D43;
            font-size: 12.5px;
            font-weight: 600;
          }

          /* Unified 6-Card Non-Redundant KPI Grid (3x2 Desktop, 2x3 Tablet, 1-Col Mobile) */
          #sectionAnalytics .ba-unified-kpi-grid {
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 20px;
            margin-bottom: 26px;
          }
          #sectionAnalytics .ba-unified-kpi-grid > * {
            min-width: 0;
          }
          @media (max-width: 1024px) {
            #sectionAnalytics .ba-unified-kpi-grid {
              grid-template-columns: repeat(2, minmax(0, 1fr));
            }
            #sectionAnalytics .ba-two-col-grid {
              grid-template-columns: 1fr;
            }
          }
          @media (max-width: 640px) {
            #sectionAnalytics .ba-unified-kpi-grid,
            #sectionAnalytics .ba-forecast-breakdown {
              grid-template-columns: 1fr;
            }
          }
        </style>

        <!-- Clean Header (No Duplicate Shortcut Buttons) -->
        <div class="owner-welcome-bar">
          <h1 class="owner-welcome-title">Owner Dashboard &amp; Business Analytics</h1>
          <p class="owner-welcome-sub">A single, uncluttered view of rent collections, occupancy, cash flow projections, and tenant reliability.</p>
        </div>

        <!-- PILLAR 4 (PRESCRIPTIVE): RECOMMENDED ACTIONS FOR TODAY -->
        <div class="ba-insights-banner" id="recommendedActionsCard">
          <div class="ba-insights-header">
            <div class="ba-insights-title-wrap">
              <div class="ba-insights-icon"><i data-lucide="sparkles"></i></div>
              <div>
                <h2 class="ba-insights-title">Recommended Actions for Today</h2>
                <p class="ba-insights-sub">Practical suggestions based on tenant payment reliability, approvals, and unit activity</p>
              </div>
            </div>
            <span class="ba-pillar-pill" id="smartInsightsBadge">Decision Support</span>
          </div>
          <div class="ba-insights-grid" id="smartInsightsList">
            <div class="ba-empty-box">Reviewing your latest property activity...</div>
          </div>
        </div>

        <!-- UNIFIED DEDUPLICATED 6-CARD KPI GRID (EVERY METRIC APPEARS ONCE) -->
        <div class="ba-unified-kpi-grid">
          <!-- 1. Total Rent Collected -->
          <div class="kpi-card">
            <div class="kpi-card-top-row">
              <span class="kpi-label" style="margin:0;">Total Rent Collected</span>
              <span class="kpi-pill-tag" id="kpiPaidTag"><?= number_format($initCollectionRate, 1) ?>% Paid</span>
            </div>
            <div>
              <div class="kpi-value" id="kpiRevenue">₱<?= number_format($thisMonthCollectedInit > 0 ? $thisMonthCollectedInit : $initRentCollected, 2) ?></div>
              <div class="kpi-subtitle" id="kpiRevenueSubtitle">
                <?= $thisMonthCollectedInit > 0 ? 'This month (' . date('M Y') . ') · All-time: ₱' . number_format($initRentCollected, 2) : 'Approved rent collected (zero deductions)' ?>
              </div>
            </div>
          </div>

          <!-- 2. Pending & Unpaid Rent -->
          <div class="kpi-card">
            <div class="kpi-card-top-row">
              <span class="kpi-label" style="margin:0;">Pending &amp; Unpaid Rent</span>
              <span class="kpi-pill-tag <?= $pendingReviewCount > 0 ? 'accent' : '' ?>" id="kpiPendingApprovalTag">
                <?= $pendingReviewCount > 0 ? $pendingReviewCount . ' Waiting for Approval' : 'Up to Date' ?>
              </span>
            </div>
            <div>
              <div class="kpi-value" id="kpiOutstanding">₱<?= number_format($initUnpaidRent, 2) ?></div>
              <div class="kpi-subtitle" id="kpiPendingPaymentsSub">
                Unpaid rent balance across active leases
              </div>
            </div>
          </div>

          <!-- 3. Unit Occupancy -->
          <div class="kpi-card">
            <div class="kpi-card-top-row">
              <span class="kpi-label" style="margin:0;">Unit Occupancy</span>
              <span class="kpi-pill-tag" id="kpiUnitsAvailabilityTag">
                <?= $availableUnitsCount > 0 ? $availableUnitsCount . ' Available' : 'Fully Rented' ?>
              </span>
            </div>
            <div>
              <div class="kpi-value" id="kpiUnitsRentedSummary"><?= $occupiedUnitsCount ?> of <?= $totalUnitsCountInit ?> Units Rented</div>
              <div class="kpi-subtitle" id="kpiUnitsRentedSub">
                <?= count($allTenants) ?> Active Tenant<?= count($allTenants) === 1 ? '' : 's' ?> · <?= $archivedTenantsCount ?> Past
              </div>
            </div>
          </div>

          <!-- 4. Open Requests (Maintenance + Tenant Requests combined once) -->
          <div class="kpi-card">
            <div class="kpi-card-top-row">
              <span class="kpi-label" style="margin:0;">Open Requests</span>
              <span class="kpi-pill-tag <?= ($activeTicketsCountInit + count($pendingReports)) > 0 ? 'accent' : '' ?>" id="kpiMaintStatusTag">
                <?= ($activeTicketsCountInit + count($pendingReports)) > 0 ? 'Needs Attention' : 'All Clear' ?>
              </span>
            </div>
            <div>
              <div class="kpi-value" id="kpiOpenRequestsCount"><?= $activeTicketsCountInit + count($pendingReports) ?></div>
              <div class="kpi-subtitle" id="kpiOpenRequestsSub">
                <?= $activeTicketsCountInit ?> Maintenance · <?= count($pendingReports) ?> Tenant Request<?= count($pendingReports) === 1 ? '' : 's' ?>
              </div>
            </div>
          </div>

          <!-- 5. Average Payment Speed (DSO) -->
          <div class="kpi-card">
            <div class="kpi-card-top-row">
              <span class="kpi-label" style="margin:0;">Average Payment Speed (DSO)</span>
              <span class="kpi-pill-tag" id="baKpiOnTimeBadge">0% Paid On Time</span>
            </div>
            <div>
              <div class="kpi-value" id="baKpiDso">0.0 days</div>
              <div class="kpi-subtitle" id="baKpiDsoSub">Average days tenants take to pay after billing</div>
            </div>
          </div>

          <!-- 6. Next-Month Expected Cash Flow -->
          <div class="kpi-card">
            <div class="kpi-card-top-row">
              <span class="kpi-label" style="margin:0;">Next-Month Expected Cash Flow</span>
              <span class="kpi-pill-tag accent" id="baForecastConfidenceBadge">Collection Rate: 0%</span>
            </div>
            <div>
              <div class="kpi-value" id="baForecastExpected">₱0.00</div>
              <div class="kpi-subtitle" id="baForecastSub">Loading upcoming cash flow projection...</div>
            </div>
          </div>
        </div>

        <!-- ROW 1 CHARTS: MONTHLY RENT TIMELINE & NEXT-MONTH PROJECTION -->
        <div class="ba-two-col-grid">
          <!-- Monthly Rent Overview Chart -->
          <div class="ba-card">
            <div class="ba-card-head">
              <div>
                <h2 class="ba-card-title">Monthly Rent Overview</h2>
                <p class="ba-card-sub">Expected rent vs. rent actually paid each month</p>
              </div>
              <span class="widget-badge">Monthly Timeline</span>
            </div>
            <div class="revenue-chart-stage" style="height: 255px; min-height: 230px;">
              <canvas id="chartCollectionEfficiency"></canvas>
              <div id="revenueChartEmptyState" class="chart-empty-overlay">
                <i data-lucide="bar-chart-3" style="width: 34px; height: 34px; color: var(--tr-golden-brown); margin-bottom: 10px;"></i>
                <div style="font-size: 14.5px; font-weight: 800; color: var(--tr-dark-brown);">No Monthly Rent Records Yet</div>
                <p style="font-size: 12.5px; color: var(--tr-text-muted); margin-top: 4px; max-width: 340px;">
                  Once rent bills are created or tenants submit payments, your monthly rent summary will appear here.
                </p>
              </div>
            </div>
          </div>

          <!-- Next-Month Cash Flow Forecast Chart -->
          <div class="ba-card">
            <div class="ba-card-head">
              <div>
                <h2 class="ba-card-title">Next-Month Cash Flow Scenarios</h2>
                <p class="ba-card-sub">Expected inflow vs. full capacity (including vacant units &amp; add-on spaces)</p>
              </div>
              <span class="widget-badge" id="baForecastMonthBadge">Projection</span>
            </div>

            <div class="ba-forecast-breakdown">
              <div class="ba-mini-stat">
                <div class="ba-mini-stat-label">Occupied Unit Rent</div>
                <div class="ba-mini-stat-val" id="baForecastRent">₱0.00</div>
              </div>
              <div class="ba-mini-stat">
                <div class="ba-mini-stat-label">Extra Capacity (Vacant)</div>
                <div class="ba-mini-stat-val" id="baForecastVacancy">+₱0.00</div>
              </div>
            </div>

            <div class="ba-chart-box" style="height: 190px;">
              <canvas id="chartCashFlowForecast"></canvas>
            </div>
          </div>
        </div>

        <!-- ROW 2 TABLES: PAYMENT RELIABILITY RISK & UNIT OVERVIEW (NO DUPLICATE CHARTS/LISTS) -->
        <div class="ba-two-col-grid">
          <!-- Payment Reliability Risk Table -->
          <div class="ba-card">
            <div class="ba-card-head">
              <div>
                <h2 class="ba-card-title">Payment Reliability Risk</h2>
                <p class="ba-card-sub">Risk score (0–100%) per tenant from payment delays, unpaid rent &amp; rejected receipts</p>
              </div>
              <span class="widget-badge" id="baRiskCountBadge">Delinquency Risk</span>
            </div>

            <div class="ba-table-wrap" id="baTenantRiskTableWrap">
              <table class="ba-data-table">
                <thead>
                  <tr>
                    <th>Tenant &amp; Unit</th>
                    <th>Risk Score</th>
                    <th>Unpaid Rent</th>
                    <th>Avg Delay</th>
                    <th>Rejected</th>
                  </tr>
                </thead>
                <tbody id="baTenantRiskTableBody">
                  <tr><td colspan="5" style="text-align:center;color:#8C6D43;padding:24px;">Loading payment reliability scores...</td></tr>
                </tbody>
              </table>
            </div>
          </div>

          <!-- Unit Collection & Watchlist Table -->
          <div class="ba-card">
            <div class="ba-card-head">
              <div>
                <h2 class="ba-card-title">Unit Collection &amp; Watchlist</h2>
                <p class="ba-card-sub">Per-unit rent collected, unpaid balance, and open maintenance requests</p>
              </div>
              <span class="widget-badge">Unit Summary</span>
            </div>

            <div class="ba-table-wrap">
              <table class="ba-data-table">
                <thead>
                  <tr>
                    <th>Unit</th>
                    <th>Collected</th>
                    <th>Unpaid</th>
                    <th>Requests</th>
                    <th>Status</th>
                  </tr>
                </thead>
                <tbody id="baUnitOverviewTableBody">
                  <tr><td colspan="5" style="text-align:center;color:#8C6D43;padding:24px;">Loading unit summary...</td></tr>
                </tbody>
              </table>
            </div>
          </div>
        </div>

        <!-- ROW 3 DIAGNOSTIC CHART: MAINTENANCE REQUESTS BY CATEGORY (CHART ONLY — NO DUPLICATE TABLE) -->
        <div class="ba-card" style="margin-bottom: 12px;">
          <div class="ba-card-head">
            <div>
              <h2 class="ba-card-title">Maintenance Requests by Category</h2>
              <p class="ba-card-sub">Request volume &amp; cumulative share across Plumbing, Electrical, Structural, Carpentry, and General</p>
            </div>
            <span class="widget-badge">Request Volume</span>
          </div>
          <div class="ba-chart-box" style="height: 230px;">
            <canvas id="chartMaintenancePareto"></canvas>
          </div>
        </div>

      </section>

      <!-- SECTION 2: TENANT DIRECTORY (ACTIVE TENANTS + ARCHIVED TENANTS) -->
      <section id="sectionDirectory" class="app-view-section">

        <div class="dashboard-header-bar">
          <div class="dashboard-title-group">
            <h1>Tenant Directory</h1>
            <p>View residential units, real-time occupant payment status, and archived tenant records</p>
          </div>
        </div>

        <!-- PERSISTENT TABBED FOLDER CONTAINER -->
        <div class="maintenance-folder-shell" id="directoryFolderShell">
          <div class="maintenance-folder-tabs" role="tablist" aria-label="Tenant Directory Views">
            <button type="button"
                    class="maintenance-folder-tab active"
                    id="btnTabActiveDirectory"
                    role="tab"
                    aria-selected="true"
                    aria-controls="directoryActiveView"
                    onclick="switchDirectoryTab('active')">
              <span>Active Tenants</span>
              <span id="directoryActiveCountBadge" class="folder-tab-count"><?= $activeUnitsCount ?></span>
            </button>
            <button type="button"
                    class="maintenance-folder-tab"
                    id="btnTabArchivedDirectory"
                    role="tab"
                    aria-selected="false"
                    aria-controls="directoryArchivedView"
                    onclick="switchDirectoryTab('archived')">
              <span>Archived Tenants</span>
              <span id="directoryArchivedCountBadge" class="folder-tab-count"><?= $archivedTenantsCount ?></span>
            </button>
          </div>

          <div class="maintenance-folder-body" id="directoryFolderBody">
            <!-- 1. ACTIVE TENANTS VIEW (UNIT CARDS GRID) -->
            <div id="directoryActiveView" role="tabpanel" aria-labelledby="btnTabActiveDirectory" style="display: block;">
              <div class="directory-toolbar">
                <div class="directory-active-counter" id="directoryActiveUnitsCounter">
                  <?= $activeUnitsCount ?> active unit<?= $activeUnitsCount === 1 ? '' : 's' ?>
                </div>
              </div>

              <div class="directory-units-grid" id="directoryUnitsGrid">
                <?php foreach ($unitsWithTenants as $u):
                  $uId = (int)$u['id'];
                  $isOccupied = !empty($u['tenants']);
                  $primaryTenant = $isOccupied ? $u['tenants'][0] : null;
                  $isPaidUp = $isOccupied ? !empty($u['is_paid_up']) : false;
                  $cardClass = $isOccupied ? 'unit-dir-card--occupied' : 'unit-dir-card--available';
                  $pillClass = !$isOccupied
                    ? 'unit-dir-status-pill--available'
                    : ($isPaidUp ? 'unit-dir-status-pill--paid' : 'unit-dir-status-pill--unpaid');
                  $pillText = $isOccupied ? 'OCCUPIED' : 'AVAILABLE';
                  $brCount = (int)($u['bedrooms'] ?? 1);
                  $crRaw = rtrim(rtrim(number_format((float)($u['bathrooms'] ?? 1), 1), '0'), '.');
                  $crCount = $crRaw !== '' ? $crRaw : '1';
                  $isMovedOutPending = $primaryTenant && (($primaryTenant['rental_status'] ?? 'active') === 'moved_out');
                ?>
                  <article class="unit-dir-card <?= $cardClass ?>"
                           id="unit-directory-card-<?= $uId ?>"
                           data-unit-id="<?= $uId ?>"
                           data-card-state="<?= htmlspecialchars((string)$u['card_state']) ?>"
                           tabindex="0"
                           role="button"
                           aria-label="<?= htmlspecialchars((string)$u['unit_number']) ?> - <?= $pillText ?>"
                           onclick="handleUnitCardClick(<?= $uId ?>)"
                           onkeydown="if(event.key==='Enter'||event.key===' '){event.preventDefault();handleUnitCardClick(<?= $uId ?>);}">
                    <div class="unit-dir-header">
                      <h2 class="unit-dir-title"><?= htmlspecialchars(strtoupper((string)$u['unit_number'])) ?></h2>
                      <div class="unit-dir-specs"><?= $brCount ?> BR | <?= htmlspecialchars($crCount) ?> CR</div>
                      <?php if ($isOccupied && $primaryTenant):
                        $primaryAvatarUrl = trim((string)($primaryTenant['avatar_url'] ?? ''));
                        $primaryInitial = strtoupper(mb_substr(trim((string)($primaryTenant['name'] ?? 'T')), 0, 1, 'UTF-8')) ?: 'T';
                        $primaryUsername = trim((string)($primaryTenant['username'] ?? ''));
                      ?>
                        <div style="display: flex; align-items: center; gap: 10px; margin-top: 2px;">
                          <div style="width: 34px; height: 34px; border-radius: 50%; overflow: hidden; flex-shrink: 0; background: #FAF4EC; border: 2px solid rgba(255, 255, 255, 0.78); display: flex; align-items: center; justify-content: center; box-shadow: 0 2px 6px rgba(34, 23, 9, 0.22);">
                            <?php if ($primaryAvatarUrl !== ''): ?>
                              <img src="<?= htmlspecialchars($primaryAvatarUrl) ?>"
                                   alt="<?= htmlspecialchars((string)$primaryTenant['name']) ?>"
                                   style="width: 100%; height: 100%; border-radius: 50%; object-fit: cover; display: block;"
                                   onerror="this.style.display='none';if(this.nextElementSibling)this.nextElementSibling.style.display='flex';">
                              <span style="display: none; width: 100%; height: 100%; align-items: center; justify-content: center; font-weight: 900; font-size: 13px; color: #1E160E;"><?= htmlspecialchars($primaryInitial) ?></span>
                            <?php else: ?>
                              <span style="font-weight: 900; font-size: 13px; color: #1E160E;"><?= htmlspecialchars($primaryInitial) ?></span>
                            <?php endif; ?>
                          </div>
                          <div style="min-width: 0;">
                            <div class="unit-dir-occupant" style="padding-left: 0;">OCCUPANT: <?= htmlspecialchars(strtoupper((string)$primaryTenant['name'])) ?></div>
                            <?php if ($primaryUsername !== ''): ?>
                              <div style="font-family: ui-monospace, 'SFMono-Regular', Menlo, Consolas, monospace; font-size: 11px; font-weight: 700; color: rgba(246, 241, 229, 0.82); letter-spacing: 0.04em;"><?= htmlspecialchars(strtoupper($primaryUsername)) ?></div>
                            <?php endif; ?>
                          </div>
                        </div>
                        <?php if ($isMovedOutPending): ?>
                          <span class="unit-dir-movedout-tag">Moved Out (Pending Settlement)</span>
                        <?php endif; ?>
                      <?php endif; ?>
                    </div>

                    <?php if ($isOccupied): ?>
                      <div class="unit-dir-divider"></div>
                    <?php endif; ?>

                    <div class="unit-dir-footer">
                      <span class="unit-dir-status-pill <?= $pillClass ?>" id="unit-folder-badge-<?= $uId ?>"><?= $pillText ?></span>
                    </div>
                  </article>
                <?php endforeach; ?>
              </div>
            </div> <!-- /#directoryActiveView -->

            <!-- 2. ARCHIVED TENANTS VIEW (Integrated inside persistent folder container) -->
            <div id="directoryArchivedView" role="tabpanel" aria-labelledby="btnTabArchivedDirectory" style="display: none;">
              <!-- Beige Container Box with Rounded Corners, Thin Dark Brown Border, and Drop Shadow -->
              <div class="archived-tenants-beige-container">
                <div class="dashboard-header-bar" style="margin-bottom: 16px;">
                  <div class="dashboard-title-group">
                    <div style="display: flex; align-items: center; gap: 12px; flex-wrap: wrap;">
                      <h2 style="font-size: 20px; font-weight: 800; color: #1f170f; margin: 0; font-family: 'Playfair Display', Georgia, serif;">Archived Tenant Accounts</h2>
                      <span id="archivedTenantCountBadge" class="clay-badge" style="font-size: 12px; padding: 4px 12px; background: #fdf6ea; color: #56380E; border: 1.2px solid #2b2116; font-weight: 700; border-radius: 9999px;">0 Archived Records</span>
                    </div>
                    <p style="font-size: 13px; color: #54412e; margin-top: 3px;">Preserved repository of deleted and moved-out tenant records with historical billing, receipts, and maintenance logs.</p>
                  </div>
                  <div class="action-btn-group" style="display: flex; align-items: center; gap: 10px; flex-wrap: wrap;">
                    <div style="position: relative; min-width: 260px;">
                      <input type="text" id="archivedTenantSearchInput" class="clay-input" placeholder="Search by name, email, or unit..." style="padding-left: 36px; font-size: 13px; background: #ffffff; border: 1.2px solid #2b2116; border-radius: 8px;" oninput="filterArchivedTenants(this.value)">
                      <i data-lucide="search" aria-hidden="true" style="width: 17px; height: 17px; position: absolute; left: 12px; top: 50%; transform: translateY(-50%); color: #6e5539; pointer-events: none;"></i>
                    </div>
                    <button type="button" class="archived-main-action-btn" onclick="loadArchivedTenants()" title="Refresh Archived Records">
                      <i data-lucide="refresh-cw" class="w-4 h-4" aria-hidden="true"></i>
                      <span>Refresh</span>
                    </button>
                  </div>
                </div>

                <!-- Archived Notice Banner -->
                <div class="alert-clay alert-clay-info" style="margin-bottom: 18px; padding: 12px 18px; background: #fffcf7; border: 1.2px solid #2b2116; border-radius: 12px; box-shadow: 0 2px 4px rgba(0,0,0,0.06);">
                  <div class="alert-icon-clay" style="color: #6e5539;">
                    <i data-lucide="info" class="w-5 h-5" aria-hidden="true"></i>
                  </div>
                  <div class="alert-body-clay" style="font-size: 13px; color: #3b2b1d; line-height: 1.5;">
                    <strong>Data Integrity Guarantee:</strong> Moving out and archiving a tenant preserves all payment receipts, billing history, and maintenance records permanently for accounting and auditing.
                  </div>
                </div>

                <!-- Archived Tenants Table Card (No Horizontal Scroll, Fixed Width) -->
                <div class="archived-table-wrapper overflow-hidden">
                  <div class="w-full overflow-hidden">
                    <table class="w-full table-fixed" style="border-collapse: collapse; text-align: left; font-size: 13.5px;">
                      <thead>
                        <tr style="border-bottom: 1.5px solid #2b2116; color: #1c140d; font-weight: 700; background: #fdf6ea;">
                          <th class="w-[20%]" style="padding: 14px 16px;">Tenant Name</th>
                          <th class="w-[11%]" style="padding: 14px 14px;">Unit Number</th>
                          <th class="w-[22%]" style="padding: 14px 14px;">Email Address</th>
                          <th class="w-[14%]" style="padding: 14px 14px;">Contact Number</th>
                          <th class="w-[16%]" style="padding: 14px 14px;">Date of Moving In</th>
                          <th class="w-[17%]" style="padding: 14px 14px;">Move-Out Date</th>
                        </tr>
                      </thead>
                      <tbody id="archivedTenantsTableBody">
                        <?php if (empty($archivedTenantsList)): ?>
                          <tr>
                            <td colspan="6" style="text-align: center; padding: 42px 14px; color: var(--text-muted); font-size: 13.5px;">
                              <div style="margin-bottom: 8px; display: flex; justify-content: center; color: #94a3b8;"><i data-lucide="folder-open" class="w-7 h-7"></i></div>
                              <strong>No archived tenant accounts found.</strong>
                              <div style="font-size: 12px; color: #94a3b8; margin-top: 4px;">Moved-out and settled tenant records will appear here with full historical logs.</div>
                            </td>
                          </tr>
                        <?php else: ?>
                          <?php foreach ($archivedTenantsList as $row): 
                            $rowName = htmlspecialchars($row['name'] ?? '', ENT_QUOTES, 'UTF-8');
                            $rowUnit = htmlspecialchars($row['archived_unit_number'] ?: ($row['current_unit_number'] ? 'Unit ' . $row['current_unit_number'] : 'Unit N/A'), ENT_QUOTES, 'UTF-8');
                            $rowEmail = htmlspecialchars($row['email'] ?? '—', ENT_QUOTES, 'UTF-8');
                            $rowContact = htmlspecialchars($row['phone'] ?? '—', ENT_QUOTES, 'UTF-8');
                            $rowMoveIn = htmlspecialchars(!empty($row['move_in_date']) ? date('M j, Y', strtotime($row['move_in_date'])) : '—', ENT_QUOTES, 'UTF-8');
                            $rowMoveOut = htmlspecialchars(!empty($row['move_out_date']) ? date('M j, Y', strtotime($row['move_out_date'])) : (!empty($row['deleted_at']) ? date('M j, Y', strtotime($row['deleted_at'])) : 'Not set'), ENT_QUOTES, 'UTF-8');
                            $rowAvatar = htmlspecialchars($row['profile_picture'] ?? '', ENT_QUOTES, 'UTF-8');
                            $initial = strtoupper(mb_substr($row['name'] ?: 'T', 0, 1, 'UTF-8'));
                          ?>
                            <tr id="archived-row-<?= (int)$row['tenant_id'] ?>"
                                class="cursor-pointer hover:bg-[#E8D8B0]/50 transition-colors archived-tenant-row"
                                onclick="openArchivedModal(<?= $row['tenant_id'] ?>)"
                                data-id="<?= (int)$row['tenant_id'] ?>"
                                data-name="<?= $rowName ?>"
                                data-unit="<?= $rowUnit ?>"
                                data-email="<?= $rowEmail ?>"
                                data-contact="<?= $rowContact ?>"
                                data-move-in="<?= $rowMoveIn ?>"
                                data-move-out="<?= $rowMoveOut ?>"
                                data-avatar="<?= $rowAvatar ?>"
                                title="Click to view archived tenant details"
                                style="border-bottom: 1px solid rgba(166, 180, 204, 0.25);">
                              <td style="padding: 14px 16px; font-weight: 700; color: #0f172a;">
                                <div style="display: flex; align-items: center; gap: 10px; min-width: 0;">
                                  <?php if (!empty($row['profile_picture'])): ?>
                                    <img src="<?= $rowAvatar ?>" alt="<?= $rowName ?>" style="width: 34px; height: 34px; border-radius: 50%; object-fit: cover; border: 1.5px solid #cbd5e1; flex-shrink: 0;">
                                  <?php else: ?>
                                    <div style="width: 34px; height: 34px; border-radius: 50%; background: linear-gradient(135deg, #64748b, #475569); color: #fff; font-weight: 700; font-size: 13px; display: flex; align-items: center; justify-content: center; flex-shrink: 0;"><?= $initial ?></div>
                                  <?php endif; ?>
                                  <span class="truncate" title="<?= $rowName ?>"><?= $rowName ?></span>
                                </div>
                              </td>
                              <td style="padding: 14px 14px;">
                                <span class="clay-badge" style="background: #F3E4C6; color: #56380E; border: 1px solid rgba(181, 143, 83, 0.35); font-weight: 800; font-size: 11.5px; padding: 3px 9px;">
                                  <?= $rowUnit ?>
                                </span>
                              </td>
                              <td class="truncate" style="padding: 14px 14px; color: #334155; font-size: 13px;" title="<?= $rowEmail ?>">
                                <?= $rowEmail ?>
                              </td>
                              <td style="padding: 14px 14px; color: #334155; font-size: 13px;"><?= $rowContact ?></td>
                              <td style="padding: 14px 14px; color: var(--text-muted); font-size: 12.5px;"><?= $rowMoveIn ?></td>
                              <td style="padding: 14px 14px; color: #92400e; font-weight: 700; font-size: 12.5px;"><?= $rowMoveOut ?></td>
                            </tr>
                          <?php endforeach; ?>
                        <?php endif; ?>
                      </tbody>
                    </table>
                  </div>
                </div>
              </div> <!-- /.archived-tenants-beige-container -->
            </div> <!-- /#directoryArchivedView -->
          </div>
        </div>

      </section>

      <!-- SECTION 3: MAINTENANCE TICKETS (ACTIVE / ON-GOING VS COMPLETED) -->
      <section id="sectionTickets" class="app-view-section">

        <div class="dashboard-header-bar">
          <div class="dashboard-title-group">
            <h1>Maintenance Requests</h1>
            <p>See and manage fixes needed across your rental units</p>
          </div>
        </div>

        <!-- PERSISTENT TABBED FOLDER CONTAINER -->
        <div class="maintenance-folder-shell" id="maintenanceFolderShell">
          <div class="maintenance-folder-tabs" role="tablist" aria-label="Maintenance Views">
            <button type="button"
                    class="maintenance-folder-tab active"
                    id="btnTabActiveTickets"
                    role="tab"
                    aria-selected="true"
                    aria-controls="paneActiveTickets"
                    onclick="switchTicketTab('active')">
              <span>Fixes Needed</span>
              <span id="activeTicketCountBadge" class="folder-tab-count"><?= count($activeTickets) ?></span>
            </button>
            <button type="button"
                    class="maintenance-folder-tab"
                    id="btnTabCompletedTickets"
                    role="tab"
                    aria-selected="false"
                    aria-controls="paneCompletedTickets"
                    onclick="switchTicketTab('completed')">
              <span>Completed Fixes</span>
              <span id="completedTicketCountBadge" class="folder-tab-count"><?= count($completedTickets) ?></span>
            </button>
          </div>

          <div class="maintenance-folder-body" id="maintenanceFolderBody">
            <!-- PANE 1: ACTIVE / ON-GOING MAINTENANCE (SINGLE UNIFIED VIEW) -->
            <div id="paneActiveTickets" role="tabpanel" aria-labelledby="btnTabActiveTickets" style="display: block;">
              <div class="kanban-toolbar">
                <div class="kanban-toolbar-hint">
                  <span id="kanbanCategoryStateLabel">All Fixes Needed</span>
                </div>
                <div class="kanban-filter-wrap">
                  <select id="activeStatusDropdown"
                          class="kanban-filter-select"
                          aria-label="Filter maintenance status"
                          onchange="handleActiveStatusDropdownChange(this.value)">
                    <option value="All" selected>All</option>
                    <option value="Pending">Pending</option>
                    <option value="On-Going">On-going</option>
                  </select>
                  <span class="kanban-filter-caret" aria-hidden="true">▼</span>
                </div>
              </div>

              <div class="maintenance-unified-box" id="maintenanceUnifiedBox" data-board-status="All">
                <div class="maintenance-unified-grid" id="maintenanceUnifiedGrid"></div>
              </div>
            </div>

            <!-- PANE 2: COMPLETED MAINTENANCE -->
            <div id="paneCompletedTickets" role="tabpanel" aria-labelledby="btnTabCompletedTickets" style="display: none;">
              <div class="maintenance-completed-card">
                <div style="overflow-x: auto;">
                  <table style="width: 100%; border-collapse: collapse; text-align: left; font-size: 13.5px;">
                    <thead>
                      <tr style="border-bottom: 2px solid rgba(166, 180, 204, 0.3); color: #422B0B; font-weight: 800; background: #EFE3CC;">
                        <th style="padding: 14px 16px;">Ticket Number</th>
                        <th style="padding: 14px 16px;">Tenant Name</th>
                        <th style="padding: 14px 16px;">Unit</th>
                        <th style="padding: 14px 16px;">Issue Description</th>
                        <th style="padding: 14px 16px;">Date Submitted</th>
                        <th style="padding: 14px 16px;">Priority</th>
                        <th style="padding: 14px 16px;">Status</th>
                      </tr>
                    </thead>
                    <tbody id="completedTicketsTableBody">
                      <?php if (empty($completedTickets)): ?>
                      <tr id="emptyCompletedTicketsRow">
                        <td colspan="7" style="text-align: center; padding: 42px 14px; color: var(--text-muted); font-size: 14px;">
                          <div style="margin-bottom: 8px; display: flex; justify-content: center; color: #94a3b8;"><i data-lucide="clipboard-list" class="w-8 h-8" aria-hidden="true"></i></div>
                          <strong>No completed maintenance records yet.</strong>
                          <div style="font-size: 12px; color: #94a3b8; margin-top: 4px;">Resolved tickets will be archived here for historical review.</div>
                        </td>
                      </tr>
                      <?php else: ?>
                      <?php foreach ($completedTickets as $tk): ?>
                      <tr class="clickable-ticket-row ticket-record-row"
                          id="ticket-row-<?= $tk['id'] ?>"
                          data-ticket-id="<?= $tk['id'] ?>"
                          style="border-bottom: 1px solid rgba(166, 180, 204, 0.15);"
                          onclick="openTicketDetailsModal(<?= htmlspecialchars(json_encode($tk), ENT_QUOTES, 'UTF-8') ?>)"
                          title="Click to view maintenance ticket details">
                        <td style="padding: 14px 16px; font-weight: 700; color: #56380E; white-space: nowrap;">#MT-<?= str_pad((string)$tk['id'], 4, '0', STR_PAD_LEFT) ?></td>
                        <td style="padding: 14px 16px; font-weight: 600; color: #0f172a; white-space: nowrap;"><?= htmlspecialchars($tk['tenant_name']) ?></td>
                        <td style="padding: 14px 16px; white-space: nowrap;">
                          <span class="clay-badge" style="background: #F3E4C6; color: #56380E; border: 1px solid rgba(181, 143, 83, 0.35); font-weight: 800; font-size: 11px;">
                            <?= htmlspecialchars($tk['unit_number'] ?? ('Unit ' . $tk['unit_id'])) ?>
                          </span>
                        </td>
                        <td style="padding: 14px 16px; max-width: 320px;">
                          <div style="font-weight: 600; color: #1e293b; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;" title="<?= htmlspecialchars($tk['title']) ?>">
                            <?= htmlspecialchars($tk['title']) ?>
                          </div>
                          <?php if (!empty($tk['description'])): ?>
                            <div style="font-size: 12px; color: var(--text-muted); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; margin-top: 2px;" title="<?= htmlspecialchars($tk['description']) ?>">
                              <?= htmlspecialchars(mb_strimwidth($tk['description'], 0, 60, '...')) ?>
                            </div>
                          <?php endif; ?>
                        </td>
                        <td style="padding: 14px 16px; font-size: 12.5px; color: var(--text-muted); white-space: nowrap;">
                          <?= date('M j, Y', strtotime($tk['created_at'])) ?>
                        </td>
                        <td style="padding: 14px 16px; white-space: nowrap;">
                          <span class="clay-badge badge-<?= strtolower($tk['priority']) ?>" style="font-weight: 700; font-size: 11px; padding: 3px 8px;">
                            <?= ucfirst(strtolower($tk['priority'])) ?>
                          </span>
                        </td>
                        <td style="padding: 14px 16px; white-space: nowrap;">
                          <span id="ticket-badge-<?= $tk['id'] ?>" class="clay-badge badge-done" style="font-weight: 700; font-size: 11.5px; padding: 4px 10px;">
                            <?= htmlspecialchars($tk['status']) ?>
                          </span>
                        </td>
                      </tr>
                      <?php endforeach; ?>
                      <?php endif; ?>
                    </tbody>
                  </table>
                </div>
              </div>
            </div>
          </div>
        </div>

      </section>

      <!-- SECTION 3B: GENERAL TENANT REPORTS MANAGEMENT -->
      <section id="sectionReports" class="app-view-section">

        <div class="dashboard-header-bar">
          <div class="dashboard-title-group">
            <h1>Tenant Requests</h1>
            <p>Review tenant requests for extra occupants, pets, unit changes, and general questions</p>
          </div>
        </div>

        <!-- PERSISTENT TABBED FOLDER CONTAINER -->
        <div class="maintenance-folder-shell" id="reportsFolderShell">
          <div class="maintenance-folder-tabs" role="tablist" aria-label="Tenant Report Views">
            <button type="button"
                    class="maintenance-folder-tab active"
                    id="btnTabPendingReports"
                    role="tab"
                    aria-selected="true"
                    aria-controls="panePendingReports"
                    onclick="switchReportTab('pending')">
              <span>Waiting for Review</span>
              <span id="pendingReportsCountBadge" class="folder-tab-count"><?= count($pendingReports) ?></span>
            </button>
            <button type="button"
                    class="maintenance-folder-tab"
                    id="btnTabReviewedReports"
                    role="tab"
                    aria-selected="false"
                    aria-controls="paneReviewedReports"
                    onclick="switchReportTab('reviewed')">
              <span>Reviewed Requests</span>
              <span id="reviewedReportsCountBadge" class="folder-tab-count"><?= count($reviewedReports) ?></span>
            </button>
          </div>

          <div class="maintenance-folder-body" id="reportsFolderBody">
            <div class="kanban-toolbar">
              <div class="kanban-toolbar-hint">
                <span id="reportsFolderStateLabel">Pending Tenant Reports</span>
              </div>
              <div class="kanban-filter-wrap">
                <select id="reportsCategoryFilter"
                        class="kanban-filter-select"
                        aria-label="Filter reports by category"
                        onchange="renderReportsFolderViews()">
                  <option value="ALL">All Categories</option>
                  <option value="Additional Occupant Registration">Additional Occupant Registration</option>
                  <option value="Pet Adoption/Registration">Pet Adoption/Registration</option>
                  <option value="Unit Customization/Modification">Unit Customization/Modification</option>
                  <option value="General Inquiry">General Inquiry</option>
                  <option value="Other...">Other...</option>
                </select>
                <span class="kanban-filter-caret" aria-hidden="true">▼</span>
              </div>
            </div>

            <!-- PANE 1: PENDING REPORTS -->
            <div id="panePendingReports" role="tabpanel" aria-labelledby="btnTabPendingReports" style="display: block;">
              <div class="maintenance-unified-box" id="reportsPendingUnifiedBox">
                <div class="maintenance-unified-grid" id="reportsPendingGrid"></div>
              </div>
            </div>

            <!-- PANE 2: REVIEWED REPORTS (APPROVED / REJECTED / ACKNOWLEDGED) -->
            <div id="paneReviewedReports" role="tabpanel" aria-labelledby="btnTabReviewedReports" style="display: none;">
              <div class="maintenance-unified-box" id="reportsReviewedUnifiedBox">
                <div class="maintenance-unified-grid" id="reportsReviewedGrid"></div>
              </div>
            </div>
          </div>
        </div>

      </section>

      <!-- SECTION 4: PAYMENTS WAITING FOR APPROVAL & PAYMENT HISTORY -->
      <section id="sectionPayments" class="app-view-section">
        <div class="dashboard-header-bar">
          <div class="dashboard-title-group">
            <h1>Rent Payments &amp; Approvals</h1>
            <p>Check tenant payment slips, approve rent payments, and view past payment records</p>
          </div>
        </div>

        <?php
          // Filter payments that have already been processed (i.e. not pending verification)
          $historyPayments = array_values(array_filter($payments, fn($p) => $p['status'] !== 'pending'));
        ?>

        <!-- PERSISTENT TABBED FOLDER CONTAINER (MATCHING MAINTENANCE & TENANT REPORTS) -->
        <div class="maintenance-folder-shell" id="paymentsFolderShell">
          <div class="maintenance-folder-tabs" role="tablist" aria-label="Payment Verification Views">
            <button type="button"
                    class="maintenance-folder-tab active"
                    id="btnTabPendingPayments"
                    role="tab"
                    aria-selected="true"
                    aria-controls="panePendingPayments"
                    onclick="switchPaymentTab('pending')">
              <span>Payments Waiting for Approval</span>
              <span id="pendingVerificationCountBadge" class="folder-tab-count"><?= $pendingReviewCount ?></span>
            </button>
            <button type="button"
                    class="maintenance-folder-tab"
                    id="btnTabPaymentHistory"
                    role="tab"
                    aria-selected="false"
                    aria-controls="panePaymentHistory"
                    onclick="switchPaymentTab('history')">
              <span>Payment History</span>
              <span id="paymentHistoryCountBadge" class="folder-tab-count"><?= count($historyPayments) ?></span>
            </button>
          </div>

          <div class="maintenance-folder-body" id="paymentsFolderBody">
            <!-- PANE 1: PAYMENTS WAITING FOR APPROVAL -->
            <div id="panePendingPayments" role="tabpanel" aria-labelledby="btnTabPendingPayments" style="display: block;">
              <div class="kanban-toolbar">
                <div class="kanban-toolbar-hint">
                  <span id="pendingVerificationToolbarLabel">Payments Waiting for Approval</span>
                </div>
                <div class="kanban-toolbar-actions">
                  <span id="pendingVerificationStatusBadge" class="clay-badge" style="background: #F3E4C6; color: #56380E; border: 1px solid rgba(181, 143, 83, 0.35); font-size: 11.5px; font-weight: 800; padding: 6px 14px; border-radius: 8px;">
                    <?= $pendingReviewCount === 1 ? '1 payment waiting for approval' : $pendingReviewCount . ' payments waiting for approval' ?>
                  </span>
                </div>
              </div>

              <!-- Table Card for Payments Needing Verification -->
              <div class="maintenance-completed-card">
                <div style="overflow-x: auto;">
                  <table style="width: 100%; border-collapse: collapse; text-align: left; font-size: 13.5px;">
                    <thead>
                      <tr style="border-bottom: 2px solid rgba(166, 180, 204, 0.3); color: #422B0B; font-weight: 800; background: #EFE3CC;">
                        <th style="padding: 14px 16px;">Billing Month</th>
                        <th style="padding: 14px 16px;">Unit</th>
                        <th style="padding: 14px 16px;">Tenant Name</th>
                        <th style="padding: 14px 16px;">Amount Due</th>
                        <th style="padding: 14px 16px;">Submitted Amount</th>
                        <th style="padding: 14px 16px;">Method</th>
                        <th style="padding: 14px 16px;">Proof / Receipt</th>
                        <th style="padding: 14px 16px; text-align: right;">Verification Actions</th>
                      </tr>
                    </thead>
                    <tbody id="pendingVerificationTableBody">
                      <?php if (empty($pendingReviewPayments)): ?>
                      <tr id="emptyPendingVerificationRow">
                        <td colspan="8" style="text-align: center; padding: 42px 14px; color: var(--text-muted); font-size: 14px;">
                          <div style="margin-bottom: 8px; display: flex; justify-content: center; color: #9E6E2E;"><i data-lucide="check-circle" class="w-8 h-8" aria-hidden="true"></i></div>
                          <strong style="color: #302313;">All payment submissions are verified!</strong>
                          <div style="font-size: 12px; color: #7A6245; margin-top: 4px;">There are currently no tenant payment submissions awaiting manual verification.</div>
                        </td>
                      </tr>
                      <?php else: ?>
                      <?php foreach ($pendingReviewPayments as $pending): 
                        $receiptUrl = !empty($pending['receipt_filename'])
                          ? ((str_starts_with($pending['receipt_filename'], 'http://') || str_starts_with($pending['receipt_filename'], 'https://'))
                              ? $pending['receipt_filename']
                              : ('uploads/receipts/' . $pending['receipt_filename']))
                          : '';
                      ?>
                      <tr class="clickable-payment-row pending-verification-row"
                          id="pending-row-<?= $pending['id'] ?>"
                          data-id="<?= $pending['id'] ?>"
                          data-tenant-id="<?= (int)$pending['tenant_id'] ?>"
                          data-status="pending"
                          style="border-bottom: 1px solid rgba(166, 180, 204, 0.15);"
                          onclick="openPaymentDetailsModal(<?= htmlspecialchars(json_encode($pending), ENT_QUOTES, 'UTF-8') ?>)"
                          title="Click to view detailed payment verification &amp; audit">
                        <td style="padding: 14px 16px; font-weight: 700; color: #56380E; white-space: nowrap;">
                          <?= !empty($pending['billing_cycle']) ? date('F Y', strtotime($pending['billing_cycle'])) : 'Current Cycle' ?>
                        </td>
                        <td style="padding: 14px 16px; white-space: nowrap;">
                          <span class="clay-badge" style="background: #F3E4C6; color: #56380E; border: 1px solid rgba(181, 143, 83, 0.35); font-weight: 800; font-size: 11.5px;">
                            <?= htmlspecialchars($pending['unit_number']) ?>
                          </span>
                        </td>
                        <td style="padding: 14px 16px; font-weight: 600; color: #0f172a; white-space: nowrap;">
                          <div style="display: flex; align-items: center; gap: 8px;">
                            <div style="width: 28px; height: 28px; border-radius: 8px; background: linear-gradient(135deg, #B58F53, #56380E); color: #fffdf8; font-weight: 800; font-size: 12px; display: flex; align-items: center; justify-content: center; flex-shrink: 0;">
                              <?= strtoupper(substr($pending['tenant_name'], 0, 1)) ?>
                            </div>
                            <span><?= htmlspecialchars($pending['tenant_name']) ?></span>
                          </div>
                        </td>
                        <td style="padding: 14px 16px; font-weight: 700; color: #302313; white-space: nowrap;">
                          ₱<?= number_format((float)$pending['amount_due'], 2) ?>
                        </td>
                        <td style="padding: 14px 16px; font-weight: 800; color: #047857; white-space: nowrap;">
                          ₱<?= number_format((float)$pending['amount_paid'], 2) ?>
                        </td>
                        <td style="padding: 14px 16px; font-size: 12.5px; color: #56380E; font-weight: 600; white-space: nowrap;">
                          <?= htmlspecialchars($pending['payment_method'] ?? 'Bank Transfer') ?>
                        </td>
                        <td style="padding: 14px 16px; white-space: nowrap;">
                          <?php if (!empty($receiptUrl)): ?>
                            <a href="<?= htmlspecialchars($receiptUrl, ENT_QUOTES, 'UTF-8') ?>" target="_blank" onclick="event.stopPropagation();" class="btn-inspect-proof" style="display: inline-flex; align-items: center; gap: 5px; padding: 6px 12px; font-size: 12px; text-decoration: none; border-radius: 8px;" title="Inspect uploaded receipt proof">
                              <i data-lucide="file-text" class="w-3.5 h-3.5" aria-hidden="true"></i> View Proof
                            </a>
                          <?php else: ?>
                            <span style="font-size: 12px; color: var(--text-muted); font-style: italic;">No attachment</span>
                          <?php endif; ?>
                        </td>
                        <td style="padding: 14px 16px; text-align: right; white-space: nowrap;">
                          <div style="display: inline-flex; align-items: center; gap: 6px;" onclick="event.stopPropagation();">
                            <button type="button" class="btn-verify-approve" style="padding: 6px 14px; font-size: 12px; display: inline-flex; align-items: center; gap: 4px; font-weight: 700; border-radius: 8px;" onclick="openPaymentDetailsModal(<?= htmlspecialchars(json_encode($pending), ENT_QUOTES, 'UTF-8') ?>, 'approved')">
                              <i data-lucide="check" class="w-3.5 h-3.5" aria-hidden="true"></i> Approve
                            </button>
                            <button type="button" class="btn-verify-reject" style="padding: 6px 14px; font-size: 12px; display: inline-flex; align-items: center; gap: 4px; font-weight: 700; border-radius: 8px;" onclick="openPaymentDetailsModal(<?= htmlspecialchars(json_encode($pending), ENT_QUOTES, 'UTF-8') ?>, 'rejected')">
                              <i data-lucide="x" class="w-3.5 h-3.5" aria-hidden="true"></i> Reject
                            </button>
                            <button type="button" class="clay-btn clay-btn-secondary" style="padding: 6px 12px; font-size: 12px; border-radius: 8px;" onclick="openPaymentDetailsModal(<?= htmlspecialchars(json_encode($pending), ENT_QUOTES, 'UTF-8') ?>)" title="View full details">
                              Details
                            </button>
                          </div>
                        </td>
                      </tr>
                      <?php endforeach; ?>
                      <?php endif; ?>
                    </tbody>
                  </table>
                </div>
              </div>
            </div>

            <!-- PANE 2: PAYMENT HISTORY & PROCESSED RECORDS -->
            <div id="panePaymentHistory" role="tabpanel" aria-labelledby="btnTabPaymentHistory" style="display: none;">
              <!-- Toolbar with Filter Dropdowns and Search -->
              <div class="kanban-toolbar" style="margin-bottom: 16px; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 14px;">
                <div class="kanban-toolbar-hint">
                  <span id="paymentHistoryToolbarLabel">All Processed Invoices &amp; Historical Ledgers</span>
                </div>

                <!-- Filters: Status, Month, Year, Search, and Reset -->
                <div style="display: flex; gap: 10px; align-items: center; flex-wrap: wrap;">
                  <!-- Status Filter -->
                  <div style="display: flex; align-items: center; gap: 6px;">
                    <label for="paymentFilterStatus" style="font-size: 12px; font-weight: 700; color: #56380E;">Status:</label>
                    <div class="kanban-filter-wrap" style="min-width: 125px;">
                      <select id="paymentFilterStatus" class="kanban-filter-select" onchange="applyPaymentFilters()" style="min-width: 125px; padding: 7px 30px 7px 12px; font-size: 12.5px;">
                        <option value="all">All Statuses</option>
                        <option value="approved">Approved</option>
                        <option value="rejected">Rejected</option>
                        <option value="unpaid">Unpaid</option>
                        <option value="pending_setup">Awaiting Setup</option>
                      </select>
                      <span class="kanban-filter-caret" aria-hidden="true">▼</span>
                    </div>
                  </div>

                  <!-- Month Filter -->
                  <div style="display: flex; align-items: center; gap: 6px;">
                    <label for="paymentFilterMonth" style="font-size: 12px; font-weight: 700; color: #56380E;">Month:</label>
                    <div class="kanban-filter-wrap" style="min-width: 125px;">
                      <select id="paymentFilterMonth" class="kanban-filter-select" onchange="applyPaymentFilters()" style="min-width: 125px; padding: 7px 30px 7px 12px; font-size: 12.5px;">
                        <option value="all">All Months</option>
                        <option value="01">January</option>
                        <option value="02">February</option>
                        <option value="03">March</option>
                        <option value="04">April</option>
                        <option value="05">May</option>
                        <option value="06">June</option>
                        <option value="07">July</option>
                        <option value="08">August</option>
                        <option value="09">September</option>
                        <option value="10">October</option>
                        <option value="11">November</option>
                        <option value="12">December</option>
                      </select>
                      <span class="kanban-filter-caret" aria-hidden="true">▼</span>
                    </div>
                  </div>

                  <!-- Year Filter -->
                  <div style="display: flex; align-items: center; gap: 6px;">
                    <label for="paymentFilterYear" style="font-size: 12px; font-weight: 700; color: #56380E;">Year:</label>
                    <div class="kanban-filter-wrap" style="min-width: 105px;">
                      <select id="paymentFilterYear" class="kanban-filter-select" onchange="applyPaymentFilters()" style="min-width: 105px; padding: 7px 30px 7px 12px; font-size: 12.5px;">
                        <option value="all">All Years</option>
                        <?php foreach ($availablePaymentYears as $yr): ?>
                          <option value="<?= htmlspecialchars($yr) ?>"><?= htmlspecialchars($yr) ?></option>
                        <?php endforeach; ?>
                      </select>
                      <span class="kanban-filter-caret" aria-hidden="true">▼</span>
                    </div>
                  </div>

                  <!-- Search Input -->
                  <div style="position: relative; min-width: 180px;">
                    <input type="text" id="paymentHistorySearchInput" class="clay-input" placeholder="Search tenant or unit..." style="padding: 7px 12px 7px 32px; font-size: 12.5px; border-radius: 8px; font-weight: 600; width: 100%; box-sizing: border-box; border: 1px solid rgba(86, 56, 14, 0.35); background: #FFFDF8;" oninput="applyPaymentFilters()">
                    <i data-lucide="search" aria-hidden="true" style="width: 14px; height: 14px; position: absolute; left: 10px; top: 50%; transform: translateY(-50%); color: #6e5539; pointer-events: none;"></i>
                  </div>

                  <button type="button" class="clay-btn clay-btn-secondary" onclick="resetPaymentFilters()" style="padding: 7px 14px; font-size: 12.5px; border-radius: 8px; font-weight: 700;" title="Reset filters to show all records">
                    ↺ Reset
                  </button>

                  <span id="paymentFilterCountBadge" class="clay-badge" style="background: #F3E4C6; color: #56380E; border: 1px solid rgba(181, 143, 83, 0.35); font-size: 11.5px; font-weight: 800; padding: 7px 14px; border-radius: 8px;">
                    Showing <?= count($historyPayments) ?> Records
                  </span>
                </div>
              </div>

              <!-- Table Card for Payment History -->
              <div class="maintenance-completed-card">
                <div style="overflow-x: auto;">
                  <table style="width: 100%; border-collapse: collapse; text-align: left; font-size: 13.5px;">
                    <thead>
                      <tr style="border-bottom: 2px solid rgba(166, 180, 204, 0.3); color: #422B0B; font-weight: 800; background: #EFE3CC;">
                        <th style="padding: 14px 16px;">Billing Month</th>
                        <th style="padding: 14px 16px;">Type</th>
                        <th style="padding: 14px 16px;">Unit</th>
                        <th style="padding: 14px 16px;">Tenant</th>
                        <th style="padding: 14px 16px;">Amount Due</th>
                        <th style="padding: 14px 16px;">Amount Paid</th>
                        <th style="padding: 14px 16px;">Status</th>
                      </tr>
                    </thead>
                    <tbody id="paymentRecordsTableBody">
                      <?php if (empty($historyPayments)): ?>
                      <tr id="paymentEmptyStateRow">
                        <td colspan="7" style="text-align: center; padding: 42px 14px; color: var(--text-muted); font-size: 14px;">
                          <div style="margin-bottom: 8px; display: flex; justify-content: center; color: #94a3b8;"><i data-lucide="receipt" class="w-8 h-8" aria-hidden="true"></i></div>
                          <strong style="color: #302313;">No processed payment records found.</strong>
                          <div style="font-size: 12px; color: #7A6245; margin-top: 4px;">Verified, approved, and archived payment records will appear here.</div>
                        </td>
                      </tr>
                      <?php else: ?>
                      <?php foreach ($historyPayments as $pm): ?>
                      <tr class="clickable-payment-row payment-record-row"
                          id="payment-row-<?= $pm['id'] ?>"
                          data-id="<?= $pm['id'] ?>"
                          data-tenant-id="<?= (int)$pm['tenant_id'] ?>"
                          data-tenant-name="<?= htmlspecialchars(strtolower($pm['tenant_name'] ?? '')) ?>"
                          data-unit="<?= htmlspecialchars(strtolower($pm['unit_number'] ?? '')) ?>"
                          data-month="<?= !empty($pm['billing_cycle']) ? date('m', strtotime($pm['billing_cycle'])) : '' ?>"
                          data-year="<?= !empty($pm['billing_cycle']) ? date('Y', strtotime($pm['billing_cycle'])) : '' ?>"
                          data-status="<?= htmlspecialchars($pm['status']) ?>"
                          style="border-bottom: 1px solid rgba(166, 180, 204, 0.15);"
                          onclick="openPaymentDetailsModal(<?= htmlspecialchars(json_encode($pm), ENT_QUOTES, 'UTF-8') ?>)"
                          title="Click to view detailed payment verification &amp; audit">
                        <td style="padding: 14px 16px; font-weight: 700; color: #56380E; white-space: nowrap;">
                          <?php if (!empty($pm['billing_cycle'])): ?>
                            <?= date('F Y', strtotime($pm['billing_cycle'])) ?>
                          <?php else: ?>
                            <span style="color: #92400e; font-style: italic; background: #fef3c7; padding: 3px 8px; border-radius: 6px; font-size: 11.5px; border: 1px solid rgba(245, 158, 11, 0.25);">To be assigned</span>
                          <?php endif; ?>
                        </td>
                        <td style="padding: 14px 16px; white-space: nowrap;">
                          <?php if (($pm['payment_type'] ?? 'Rent') === 'Advance Deposit'): ?>
                            <span class="clay-badge" style="background: #F5E6C4; color: #7B4B19; font-weight: 800; font-size: 11px; border: 1px solid rgba(181, 143, 83, 0.45);">
                              Advance Deposit
                            </span>
                          <?php else: ?>
                            <span class="clay-badge" style="background: #e0f2fe; color: #0369a1; font-weight: 800; font-size: 11px; border: 1px solid rgba(3, 105, 161, 0.25);">
                              Monthly Rent
                            </span>
                          <?php endif; ?>
                        </td>
                        <td style="padding: 14px 16px; white-space: nowrap;">
                          <span class="clay-badge" style="background: #F3E4C6; color: #56380E; border: 1px solid rgba(181, 143, 83, 0.35); font-weight: 800; font-size: 11.5px;">
                            <?= htmlspecialchars($pm['unit_number']) ?>
                          </span>
                        </td>
                        <td style="padding: 14px 16px; white-space: nowrap;">
                          <div style="display: flex; align-items: center; gap: 8px;">
                            <div style="width: 28px; height: 28px; border-radius: 8px; background: linear-gradient(135deg, #B58F53, #56380E); color: #fffdf8; font-weight: 800; font-size: 12px; display: flex; align-items: center; justify-content: center; flex-shrink: 0;">
                              <?= strtoupper(substr($pm['tenant_name'], 0, 1)) ?>
                            </div>
                            <span style="font-weight: 600; color: #0f172a;"><?= htmlspecialchars($pm['tenant_name']) ?></span>
                          </div>
                        </td>
                        <td style="padding: 14px 16px; font-weight: 700; white-space: nowrap;">
                          <?php if ($pm['amount_due'] !== null && (float)$pm['amount_due'] > 0): ?>
                            ₱<?= number_format((float)$pm['amount_due'], 2) ?>
                          <?php else: ?>
                            <span style="color: var(--text-muted); font-style: italic; font-size: 12px;">Awaiting configuration</span>
                          <?php endif; ?>
                        </td>
                        <td style="padding: 14px 16px; font-weight: 700; color: <?= (float)$pm['amount_paid'] > 0 ? '#047857' : 'var(--text-muted)' ?>; white-space: nowrap;">
                          ₱<?= number_format((float)$pm['amount_paid'], 2) ?>
                        </td>
                        <td style="padding: 14px 16px; white-space: nowrap;">
                          <?php if ($pm['status'] === 'pending_setup'): ?>
                            <span class="clay-badge" style="background: #fef3c7; color: #92400e; font-weight: 800; border: 1px solid rgba(245, 158, 11, 0.35); font-size: 10.5px; padding: 4px 10px;">
                              AWAITING CONFIGURATION
                            </span>
                          <?php elseif ($pm['status'] === 'unpaid'): ?>
                            <span class="clay-badge" style="background: #fee2e2; color: #991b1b; font-weight: 800; border: 1px solid rgba(239, 68, 68, 0.35); font-size: 10.5px; padding: 4px 10px;">
                              UNPAID
                            </span>
                          <?php elseif ($pm['status'] === 'approved'): ?>
                            <span class="clay-badge badge-approved" style="font-size: 10.5px; padding: 4px 10px;">
                              APPROVED
                            </span>
                          <?php elseif ($pm['status'] === 'rejected'): ?>
                            <span class="clay-badge badge-rejected" style="font-size: 10.5px; padding: 4px 10px;">
                              REJECTED
                            </span>
                          <?php else: ?>
                            <span class="clay-badge badge-<?= strtolower($pm['status']) ?>" style="font-size: 10.5px; padding: 4px 10px;">
                              <?= strtoupper($pm['status']) ?>
                            </span>
                          <?php endif; ?>
                        </td>
                      </tr>
                      <?php endforeach; ?>
                      <tr id="paymentNoFilterResultsRow" style="display: none;">
                        <td colspan="7" style="text-align: center; padding: 32px 14px; color: var(--text-muted); font-size: 13.5px;">
                          No payment records match the selected filters. <a href="javascript:void(0)" onclick="resetPaymentFilters()" style="color: var(--clay-primary); font-weight: 700; text-decoration: underline;">Reset filters</a>
                        </td>
                      </tr>
                      <?php endif; ?>
                    </tbody>
                  </table>
                </div>
              </div>
            </div>
          </div>
        </div>
      </section>

      <!-- SECTION 5: LANDLORD PROFILE (SETTINGS INTERFACE) -->
      <section id="sectionProfile" class="app-view-section">
        <!-- Main Header -->
        <h1 class="tararent-settings-title">ADMIN PROFILE SETTINGS</h1>

        <div class="tararent-settings-shell">
          <!-- Left Nav Container -->
          <div class="tararent-settings-nav">
            <button type="button" class="tararent-settings-nav-btn profile-subnav-item active" id="adminSubnavAccount" onclick="switchProfileSubTab('accountInfo', this)">
              <span class="tab-icon"><i data-lucide="user" class="w-4 h-4" aria-hidden="true"></i></span>
              <span>ACCOUNT INFORMATION</span>
            </button>
            <button type="button" class="tararent-settings-nav-btn profile-subnav-item" id="adminSubnavPassword" onclick="switchProfileSubTab('changePassword', this)">
              <span class="tab-icon"><i data-lucide="lock" class="w-4 h-4" aria-hidden="true"></i></span>
              <span>CHANGE PASSWORD</span>
            </button>
          </div>

          <!-- Right Content Main Card -->
          <div class="tararent-settings-card">

            <!-- Sub-Tab 1: Account Information -->
            <div id="paneAccountInfo" class="profile-tab-pane active-pane">
              <div>
                <!-- Top Row: Avatar + Name / Username + Role -->
                <div class="tararent-settings-header" style="justify-content: space-between; flex-wrap: wrap; gap: 16px;">
                  <div style="display: flex; align-items: center; gap: 18px;">
                    <!-- Hidden profile picture form -->
                    <form id="profilePictureForm" method="POST" action="api/profile_api.php" enctype="multipart/form-data" style="display: none;">
                      <input type="hidden" name="action" value="upload_profile_picture">
                      <input type="hidden" name="user_id" value="<?= (int)($user['id'] ?? 0) ?>">
                      <input type="file" id="profile_picture" name="profile_picture" accept="image/*" onchange="previewProfilePicture(event)">
                    </form>

                    <!-- Circular Avatar Column with Change Profile Label Below (Fits properly, no overlap) -->
                    <div class="tararent-avatar-column">
                      <div class="tararent-settings-avatar-wrap" onclick="triggerProfilePictureInput()" title="Click to change profile picture" role="button" tabindex="0">
                        <?php if (!empty($userAvatarUrl)): ?>
                          <img id="avatarPreviewImg" src="<?= htmlspecialchars($userAvatarUrl) ?>" alt="Profile Photo" class="tararent-avatar-img">
                          <div id="avatarFallbackInitial" class="tararent-avatar-initial" style="display: none !important;"></div>
                        <?php else: ?>
                          <div id="avatarFallbackInitial" class="tararent-avatar-initial"><?= htmlspecialchars($userInitial) ?></div>
                          <img id="avatarPreviewImg" src="" alt="Profile Photo" class="tararent-avatar-img" style="display: none !important;">
                        <?php endif; ?>
                        <div class="tararent-avatar-hover-overlay" title="Change Profile Picture">
                          <i data-lucide="camera" class="w-5 h-5 text-white"></i>
                        </div>
                      </div>

                      <button type="button" class="tararent-change-profile-link" onclick="triggerProfilePictureInput()" title="Change Profile Picture">
                        <i data-lucide="camera" class="w-3 h-3"></i> Change Profile
                      </button>
                    </div>

                    <!-- Name and Handle -->
                    <div class="tararent-settings-title-group">
                      <div style="display: flex; align-items: center; gap: 10px; flex-wrap: wrap;">
                        <h2 class="tararent-settings-user-name" id="profileFullName" style="margin: 0;"><?= htmlspecialchars($user['name'] ?? 'Admin') ?></h2>
                        <span style="font-size: 11px; font-weight: 800; background: #5E3F12; color: #FFFDF8; border-radius: 9999px; padding: 2px 10px; text-transform: uppercase; letter-spacing: 0.04em;">Administrator</span>
                      </div>
                      <div class="tararent-settings-user-handle">@<?= htmlspecialchars($user['username'] ?? 'admin') ?></div>
                    </div>
                  </div>
                </div>

                <!-- Avatar Pending Save Actions -->
                <div id="avatarPendingActions" style="display: none; align-items: center; gap: 8px; margin-bottom: 20px;">
                  <button type="button" class="tararent-settings-save-btn" id="btnSaveAvatar" onclick="submitProfilePictureUpload()" style="padding: 6px 18px; font-size: 12.5px;">Save Photo</button>
                  <button type="button" class="tararent-settings-save-btn" id="btnCancelAvatar" onclick="cancelProfilePictureSelection()" style="padding: 6px 14px; font-size: 12.5px; background: #EFE7DA;">Cancel</button>
                </div>

                <!-- 1. ADMINISTRATOR PERSONAL INFORMATION SUBCARD -->
                <div class="tararent-settings-subcard">
                  <div class="tararent-subcard-header">
                    <h3 class="tararent-subcard-title">
                      <i data-lucide="user-check" class="w-4 h-4"></i> Administrator Personal Information
                    </h3>
                  </div>
                  <div class="tararent-subcard-grid">
                    <div class="tararent-info-field">
                      <span class="tararent-field-label">Full Name</span>
                      <span class="tararent-field-value"><?= htmlspecialchars($user['name'] ?? 'Admin') ?></span>
                    </div>
                    <div class="tararent-info-field">
                      <span class="tararent-field-label">Username</span>
                      <span class="tararent-field-value font-mono">@<?= htmlspecialchars($user['username'] ?? 'admin') ?></span>
                    </div>
                    <div class="tararent-info-field">
                      <span class="tararent-field-label">Residential Address</span>
                      <span class="tararent-field-value"><?= htmlspecialchars($user['address'] ?? 'TaraRent Residences, Batangas') ?></span>
                    </div>
                    <div class="tararent-info-field">
                      <span class="tararent-field-label">Phone Number</span>
                      <span class="tararent-field-value"><?= htmlspecialchars($user['phone'] ?? '+63 912 345 6789') ?></span>
                    </div>
                    <div class="tararent-info-field">
                      <span class="tararent-field-label">Email Address</span>
                      <span class="tararent-field-value"><?= htmlspecialchars($user['email'] ?? 'admin@tararent.ph') ?></span>
                    </div>
                  </div>
                </div>

                <!-- 2. PROPERTY & ADMINISTRATIVE DETAILS SUBCARD -->
                <div class="tararent-settings-subcard">
                  <div class="tararent-subcard-header">
                    <h3 class="tararent-subcard-title">
                      <i data-lucide="building-2" class="w-4 h-4"></i> Property &amp; Management Details
                    </h3>
                  </div>
                  <div class="tararent-subcard-grid">
                    <div class="tararent-info-field">
                      <span class="tararent-field-label">Managed Property</span>
                      <span class="tararent-field-value font-black text-[#56380E]">TaraRent Residences</span>
                    </div>
                    <div class="tararent-info-field">
                      <span class="tararent-field-label">Units Managed</span>
                      <span class="tararent-field-value">3 Apartment Units (Units 1 - 3)</span>
                    </div>
                    <div class="tararent-info-field">
                      <span class="tararent-field-label">System Role</span>
                      <span class="tararent-field-value font-bold text-[#302313]">Property Administrator</span>
                    </div>
                    <div class="tararent-info-field">
                      <span class="tararent-field-label">Administrative Access Level</span>
                      <div>
                        <span style="display: inline-flex; align-items: center; gap: 4px; font-size: 11px; font-weight: 900; background: #D1FAE5; color: #065F46; border: 1px solid #A7F3D0; border-radius: 9999px; padding: 2px 10px; text-transform: uppercase;">
                          <i data-lucide="shield-check" class="w-3 h-3"></i> Full Access
                        </span>
                      </div>
                    </div>
                  </div>
                </div>

                <!-- 3. ADMINISTRATOR IDENTIFICATION SUBCARD -->
                <div class="tararent-settings-subcard">
                  <div class="tararent-subcard-header">
                    <h3 class="tararent-subcard-title">
                      <i data-lucide="badge-check" class="w-4 h-4"></i> Administrator Identification
                    </h3>
                    <button type="button" class="tararent-btn-view-id" onclick="openAdminIdModal()" title="View Administrator Identification">
                      <i data-lucide="eye" class="w-3.5 h-3.5"></i> View Identification
                    </button>
                  </div>
                  <div class="tararent-subcard-grid">
                    <div class="tararent-info-field">
                      <span class="tararent-field-label">Identification Status</span>
                      <div>
                        <span style="display: inline-flex; align-items: center; gap: 4px; font-size: 11px; font-weight: 900; background: #D1FAE5; color: #065F46; border: 1px solid #A7F3D0; border-radius: 9999px; padding: 2px 10px; text-transform: uppercase;">
                          <i data-lucide="check-circle-2" class="w-3 h-3"></i> Verified Property Administrator
                        </span>
                      </div>
                    </div>
                    <div class="tararent-info-field">
                      <span class="tararent-field-label">Clearance Level</span>
                      <span class="tararent-field-value">Authorized System Manager</span>
                    </div>
                  </div>
                </div>

              </div>
            </div>

            <!-- Sub-Tab 2: Change Password -->
            <div id="paneChangePassword" class="profile-tab-pane">
              <div class="tararent-settings-pwd-header">
                <h2 class="tararent-settings-pwd-title">Change Password</h2>
                <p class="tararent-settings-pwd-sub">Protect your account by using a strong, unique password.</p>
              </div>

              <form id="changePasswordForm">
                <div class="tararent-settings-form-group">
                  <label class="tararent-settings-form-label" for="profCurrentPass">Current Password<span class="req">*</span></label>
                  <input type="password" id="profCurrentPass" class="tararent-settings-input" placeholder="" required autocomplete="current-password">
                </div>

                <div class="tararent-settings-form-group">
                  <label class="tararent-settings-form-label" for="profNewPass">New Password<span class="req">*</span></label>
                  <input type="password" id="profNewPass" class="tararent-settings-input" placeholder="" minlength="6" required autocomplete="new-password">
                </div>

                <div class="tararent-settings-form-group">
                  <label class="tararent-settings-form-label" for="profConfirmPass">Confirm Password<span class="req">*</span></label>
                  <input type="password" id="profConfirmPass" class="tararent-settings-input" placeholder="" minlength="6" required autocomplete="new-password">
                </div>

                <div style="text-align: right; max-width: 540px; margin-top: 24px;">
                  <button type="submit" class="tararent-settings-save-btn" id="btnSavePassword">
                    save password
                  </button>
                </div>
              </form>
            </div>

          </div>
        </div>

        <!-- Admin ID Viewing Modal -->
        <div id="modalAdminProfileId" class="clay-modal" style="display: none;" onclick="if(event.target === this) closeModal('modalAdminProfileId')">
          <div class="clay-modal-content" style="max-width: 480px; background: #FAF3E7; border-radius: 20px; padding: 24px; border: 1.5px solid #8C6D43; box-shadow: 0 10px 30px rgba(0,0,0,0.25);">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px;">
              <h3 style="font-weight: 900; color: #1E160E; margin: 0; font-size: 17px; text-transform: uppercase;">Administrator Identification</h3>
              <button type="button" onclick="closeModal('modalAdminProfileId')" style="background: none; border: none; font-size: 20px; cursor: pointer; color: #1E160E; font-weight: bold; line-height: 1;">✕</button>
            </div>
            <div style="text-align: center; padding: 24px 16px; background: #DECBB3; border-radius: 14px;">
              <?php if (!empty($user['id_photo_filename']) && file_exists(__DIR__ . '/uploads/id_verifications/' . $user['id_photo_filename'])): ?>
                <img src="uploads/id_verifications/<?= htmlspecialchars($user['id_photo_filename']) ?>" alt="Admin ID Photo" style="max-width: 100%; max-height: 320px; border-radius: 10px; box-shadow: 0 4px 12px rgba(0,0,0,0.15);">
              <?php else: ?>
                <div style="font-size: 42px; margin-bottom: 10px;">🛡️</div>
                <div style="font-size: 16px; font-weight: 800; color: #1E160E;">Verified Property Administrator</div>
                <p style="font-size: 13px; color: #4A3E31; margin: 6px 0 0 0; font-weight: 600;">Official Property Manager credentials verified by TaraRent System.</p>
              <?php endif; ?>
            </div>
          </div>
        </div>
      </section>

      </div> <!-- /.admin-content-container -->

    </main>

  </div>

  <!-- =====================================================================
       MODAL: COMPREHENSIVE PAYMENT DETAILS & MANUAL VERIFICATION AUDIT
       Sections:
         A. Billing Information (Month, Year, Unit, Tenant, Move-in, Due, Paid, Remaining Balance, Status, Due Date)
         B. Payment Information (Method, Submission Date, Ref Number, Receipt Proof Preview/Inspect, Tenant Remarks)
         C. Verification Actions (Remarks/Reason, Approve, Reject, Double-click protection)
       ===================================================================== -->
  <div class="modal-backdrop" id="paymentDetailsModal">
    <div class="clay-modal" style="max-width: 640px; max-height: 92vh; overflow-y: auto;">
      
      <!-- Modal Header -->
      <div style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 18px; border-bottom: 1px solid var(--clay-border); padding-bottom: 14px;">
        <div style="display: flex; align-items: center; gap: 12px;">
          <div style="width: 44px; height: 44px; border-radius: 12px; background: linear-gradient(135deg, #B58F53, #56380E); color: #fffdf8; display: flex; align-items: center; justify-content: center; box-shadow: 0 4px 12px rgba(86, 56, 14, 0.24);">
            <i data-lucide="credit-card" class="w-5 h-5" aria-hidden="true"></i>
          </div>
          <div>
            <h2 style="font-size: 20px; font-weight: 800; color: #0f172a; margin: 0; line-height: 1.2;">Payment Verification &amp; Details</h2>
            <p style="font-size: 12.5px; color: var(--text-muted); margin-top: 3px;">
              Review billing metrics, inspect uploaded proof slip, and execute verification decisions
            </p>
          </div>
        </div>
        <button type="button" class="modal-close-btn btn-remove-file" onclick="closeModal('paymentDetailsModal')" title="Close details modal" aria-label="Close"><i data-lucide="x" class="w-4 h-4" aria-hidden="true"></i></button>
      </div>

      <input type="hidden" id="pdPaymentId" value="">
      <input type="hidden" id="reviewPaymentId" value="">

      <!-- ===================================================================
           SECTION A: BILLING INFORMATION
           =================================================================== -->
      <div style="background: var(--clay-input-bg); border-radius: var(--radius-md); padding: 16px; box-shadow: var(--clay-sunken-shadow); margin-bottom: 16px;">
        <div style="font-size: 13.5px; font-weight: 800; color: #0f172a; margin-bottom: 14px; display: flex; align-items: center; justify-content: space-between;">
          <span style="display: flex; align-items: center; gap: 6px;">
            <i data-lucide="clipboard-list" class="w-4 h-4" aria-hidden="true"></i>
            <span>Section A: Billing Information</span>
          </span>
          <span id="pdStatusBadge" class="clay-badge badge-pending">PENDING</span>
        </div>

        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px; font-size: 13px;">
          <div>
            <span style="color: var(--text-muted); font-size: 11px; font-weight: 700; text-transform: uppercase;">Payment Category:</span>
            <div id="pdPaymentType" style="font-weight: 800; color: #8C5319; margin-top: 2px; font-size: 14px;">Rent</div>
          </div>
          <div>
            <span style="color: var(--text-muted); font-size: 11px; font-weight: 700; text-transform: uppercase;">Tenant Name:</span>
            <div id="pdTenantName" style="font-weight: 800; color: #0f172a; margin-top: 2px; font-size: 14px;">-</div>
          </div>
          <div>
            <span style="color: var(--text-muted); font-size: 11px; font-weight: 700; text-transform: uppercase;">Apartment Unit:</span>
            <div id="pdUnitNumber" style="font-weight: 800; color: #56380E; margin-top: 2px; font-size: 14px;">-</div>
          </div>
          <div>
            <span style="color: var(--text-muted); font-size: 11px; font-weight: 700; text-transform: uppercase;">Billing Month:</span>
            <div id="pdBillingMonth" style="font-weight: 800; color: #0f172a; margin-top: 2px;">-</div>
          </div>
          <div>
            <span style="color: var(--text-muted); font-size: 11px; font-weight: 700; text-transform: uppercase;">Billing Year:</span>
            <div id="pdBillingYear" style="font-weight: 800; color: #0f172a; margin-top: 2px;">-</div>
          </div>
          <div>
            <span style="color: var(--text-muted); font-size: 11px; font-weight: 700; text-transform: uppercase;">Date of Moving In:</span>
            <div id="pdMoveInDate" style="font-weight: 800; color: #0f172a; margin-top: 2px;">-</div>
          </div>
          <div>
            <span style="color: var(--text-muted); font-size: 11px; font-weight: 700; text-transform: uppercase;">Payment Due Date:</span>
            <div id="pdDueDate" style="font-weight: 800; color: #dc2626; margin-top: 2px;">-</div>
          </div>
        </div>

        <!-- Financial Figures Row -->
        <div style="margin-top: 14px; padding-top: 14px; border-top: 1px dashed rgba(166, 180, 204, 0.4); display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 10px; text-align: center;">
          <div style="background: #ffffff; padding: 10px 8px; border-radius: var(--radius-sm); border: 1px solid var(--clay-border);">
            <div style="font-size: 10.5px; font-weight: 700; color: var(--text-muted); text-transform: uppercase;">Amount Due</div>
            <div id="pdAmountDue" style="font-size: 15px; font-weight: 800; color: #0f172a; margin-top: 3px;">₱0.00</div>
          </div>
          <div style="background: #ffffff; padding: 10px 8px; border-radius: var(--radius-sm); border: 1px solid var(--clay-border);">
            <div style="font-size: 10.5px; font-weight: 700; color: var(--text-muted); text-transform: uppercase;">Amount Paid</div>
            <div id="pdAmountPaid" style="font-size: 15px; font-weight: 800; color: #047857; margin-top: 3px;">₱0.00</div>
          </div>
          <div style="background: #ffffff; padding: 10px 8px; border-radius: var(--radius-sm); border: 1px solid var(--clay-border);">
            <div style="font-size: 10.5px; font-weight: 700; color: var(--text-muted); text-transform: uppercase;">Remaining Balance</div>
            <div id="pdRemainingBalance" style="font-size: 15px; font-weight: 800; color: #dc2626; margin-top: 3px;">₱0.00</div>
          </div>
        </div>
      </div>

      <!-- ===================================================================
           SECTION B: PAYMENT INFORMATION & PROOF
           =================================================================== -->
      <div style="background: var(--clay-input-bg); border-radius: var(--radius-md); padding: 16px; box-shadow: var(--clay-sunken-shadow); margin-bottom: 16px;">
        <div style="font-size: 13.5px; font-weight: 800; color: #0f172a; margin-bottom: 12px; display: flex; align-items: center; gap: 6px;">
          <i data-lucide="receipt" class="w-4 h-4" aria-hidden="true"></i>
          <span>Section B: Payment Information &amp; Proof</span>
        </div>

        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px; font-size: 13px; margin-bottom: 12px;">
          <div>
            <span style="color: var(--text-muted); font-size: 11px; font-weight: 700; text-transform: uppercase;">Payment Method:</span>
            <div id="pdPaymentMethod" style="font-weight: 800; color: #0f172a; margin-top: 2px;">GCash / Bank Transfer</div>
          </div>
          <div>
            <span style="color: var(--text-muted); font-size: 11px; font-weight: 700; text-transform: uppercase;">Payment / Submission Date:</span>
            <div id="pdPaymentDate" style="font-weight: 800; color: #0f172a; margin-top: 2px;">-</div>
          </div>
          <div style="grid-column: 1 / -1;">
            <span style="color: var(--text-muted); font-size: 11px; font-weight: 700; text-transform: uppercase;">Payment Reference Number:</span>
            <div id="pdRefNumber" style="font-weight: 800; color: #56380E; margin-top: 2px; font-family: monospace; font-size: 13.5px;">-</div>
          </div>
          <div style="grid-column: 1 / -1;">
            <span style="color: var(--text-muted); font-size: 11px; font-weight: 700; text-transform: uppercase;">Tenant's Payment Remarks:</span>
            <div id="pdTenantRemarks" style="font-size: 12.5px; color: #334155; margin-top: 3px; font-style: italic; background: #ffffff; padding: 8px 12px; border-radius: var(--radius-sm); border: 1px solid var(--clay-border);">None provided</div>
          </div>
        </div>

        <!-- Uploaded Payment Receipt / Proof Slip with Preview & Inspect button -->
        <div style="margin-top: 10px;">
          <label class="form-label" style="font-size: 11.5px; margin-bottom: 6px;">Uploaded Payment Receipt / Proof</label>
          <div id="pdReceiptWrapper" style="padding: 14px; background: var(--clay-card-bg); border-radius: var(--radius-md); border: 1px solid var(--clay-border); text-align: center;">
            <div id="pdNoReceiptMsg" style="font-size: 13px; color: var(--text-muted); padding: 8px 0;">
              No receipt uploaded yet (Awaiting tenant submission).
            </div>
            <div id="pdReceiptContent" style="display: none;">
              <div style="margin-bottom: 10px;">
                <a id="pdReceiptInspectBtn" href="#" target="_blank" class="btn-inspect-proof" style="display: inline-flex; align-items: center; gap: 6px; padding: 8px 18px; font-size: 13px;">
                  <i data-lucide="file-text" class="w-4 h-4" aria-hidden="true"></i> Inspect Proof in New Tab
                </a>
              </div>
              <!-- Image preview box -->
              <div id="pdReceiptPreviewBox" style="display: none; margin-top: 8px; border-radius: 10px; overflow: hidden; background: rgba(15, 23, 42, 0.04); border: 1px solid var(--clay-border); padding: 8px;">
                <img id="pdReceiptImage" src="" alt="Payment Receipt" style="max-height: 250px; max-width: 100%; object-fit: contain; border-radius: 6px; cursor: pointer; box-shadow: 0 4px 12px rgba(0,0,0,0.08);" onclick="window.open(this.src, '_blank')" title="Click to open full view in new tab">
              </div>
            </div>
          </div>
        </div>
      </div>

      <!-- ===================================================================
           SECTION C: VERIFICATION ACTIONS & AUDIT
           =================================================================== -->
      <div style="background: var(--clay-input-bg); border-radius: var(--radius-md); padding: 16px; box-shadow: var(--clay-sunken-shadow); margin-bottom: 10px;">
        <div style="font-size: 13.5px; font-weight: 800; color: #0f172a; margin-bottom: 12px; display: flex; align-items: center; justify-content: space-between;">
          <span style="display: flex; align-items: center; gap: 6px;">
            <i data-lucide="scale" class="w-4 h-4" aria-hidden="true"></i>
            <span>Section C: Verification Actions</span>
          </span>
          <span id="pdVerifiedMeta" style="font-size: 11px; color: var(--text-muted);"></span>
        </div>

        <div class="form-group" style="margin-bottom: 14px;">
          <label class="form-label" for="pdVerificationNotes">
            Remarks / Reason for Decision <span id="pdNotesRequiredLabel" style="display: none; color: #ef4444; font-size: 11px;">(Required when rejecting payment)</span>
          </label>
          <textarea id="pdVerificationNotes" class="clay-textarea" placeholder="Enter verification feedback or explanation (e.g. Bank transfer reference confirmed, or Payment proof was blurry/partial amount)..." rows="3"></textarea>
        </div>

        <!-- Verification Action Buttons (Pending state) -->
        <div id="pdActionButtonsRow" style="display: flex; gap: 12px;">
          <button type="button" id="btnPdReject" class="btn-verify-reject" style="flex: 1; padding: 12px; font-size: 13.5px; font-weight: 800; display: inline-flex; align-items: center; justify-content: center; gap: 6px;" onclick="handlePaymentVerificationDecision('rejected')">
            <i data-lucide="x" class="w-4 h-4" aria-hidden="true"></i> Reject Payment
          </button>
          <button type="button" id="btnPdApprove" class="btn-verify-approve" style="flex: 1; padding: 12px; font-size: 13.5px; font-weight: 800; display: inline-flex; align-items: center; justify-content: center; gap: 6px;" onclick="handlePaymentVerificationDecision('approved')">
            <i data-lucide="check" class="w-4 h-4" aria-hidden="true"></i> Approve Payment
          </button>
        </div>

        <!-- Read-Only Processed State Action (Approved / Rejected / Verified history) -->
        <div id="pdProcessedActionsRow" style="display: none; justify-content: flex-end; margin-top: 6px;">
          <button type="button" id="btnPdCloseOnly" class="clay-btn clay-btn-secondary" style="padding: 10px 24px; font-size: 13.5px; font-weight: 700; min-width: 100px;" onclick="closePaymentDetailsModal()">Close</button>
        </div>

        <!-- Special Action for Placeholder Rent Setup -->
        <div id="pdSetupActionRow" style="display: none; margin-top: 10px;">
          <button type="button" class="clay-btn clay-btn-primary" style="width: 100%; padding: 12px; font-size: 13.5px; background: linear-gradient(135deg, #B58F53 0%, #56380E 100%); color: #fffdf8; display: inline-flex; align-items: center; justify-content: center; gap: 6px;" onclick="triggerSetupFromModal()">
            <i data-lucide="settings" class="w-4 h-4" aria-hidden="true"></i> Configure Monthly Rent &amp; Billing Cycle
          </button>
        </div>

        <!-- Processing Indicator with Spinner (Double-Click & Concurrency Protection) -->
        <div id="pdProcessingIndicator" style="display: none; align-items: center; justify-content: center; gap: 10px; color: #56380E; font-weight: 700; font-size: 13px; padding: 12px 0;">
          <span class="clay-spinner" style="width: 20px; height: 20px; border-width: 2.5px;"></span>
          <span id="pdProcessingText">Processing decision and dispatching notification email...</span>
        </div>
      </div>

    </div>
  </div>

  <!-- =====================================================================
       MODAL: CONFIGURE PLACEHOLDER RENT RECORD
       Allows Landlord to enter: Monthly Rent Amount, Billing Period / Month, Due Date.
       Once configured, activates record to 'pending' awaiting tenant payment.
       ===================================================================== -->
  <div class="modal-backdrop" id="configureRentModal">
    <div class="clay-modal" style="max-width: 520px;">
      <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
        <div>
          <h2 style="font-size: 20px; font-weight: 800; color: #0f172a;" id="configRentTitle">Configure Rent Record</h2>
          <p style="font-size: 12.5px; color: var(--text-muted); margin-top: 2px;">Set monthly rent amount, billing cycle, and payment due date</p>
        </div>
        <button type="button" class="modal-close-btn btn-remove-file" onclick="closeModal('configureRentModal')" title="Close" aria-label="Close"><i data-lucide="x" class="w-4 h-4" aria-hidden="true"></i></button>
      </div>

      <input type="hidden" id="configPaymentId" value="">

      <!-- Target Tenant & Unit Details -->
      <div style="background: var(--clay-input-bg); border-radius: var(--radius-md); padding: 14px 18px; box-shadow: var(--clay-sunken-shadow); margin-bottom: 20px;">
        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 10px; font-size: 13px;">
          <div><span style="color: var(--text-muted);">Resident:</span> <strong id="configTenantName" style="color: #0f172a;">-</strong></div>
          <div><span style="color: var(--text-muted);">Apartment Unit:</span> <strong id="configUnitNumber" style="color: #56380E;">-</strong></div>
        </div>
      </div>

      <form id="configureRentForm" onsubmit="event.preventDefault(); submitConfigureRent();">
        <div class="form-group" style="margin-bottom: 16px;">
          <label class="form-label" for="configMonthlyRent">Monthly Rent Amount (₱) *</label>
          <div style="position: relative;">
            <span style="position: absolute; left: 14px; top: 50%; transform: translateY(-50%); font-weight: 700; color: var(--text-muted);">₱</span>
            <input type="number" id="configMonthlyRent" class="clay-input" style="padding-left: 32px;" step="0.01" min="1" placeholder="e.g. 8000.00" required>
          </div>
          <span style="font-size: 11px; color: var(--text-muted); margin-top: 4px; display: block;">Standard suggestions: Unit 1: ₱6,000 | Unit 2: ₱8,000 | Unit 3: ₱8,000</span>
        </div>

        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 14px; margin-bottom: 16px;">
          <div class="form-group" style="margin-bottom: 0;">
            <label class="form-label" for="configBillingCycle">Billing Month *</label>
            <input type="month" id="configBillingCycle" class="clay-input" required>
          </div>
          <div class="form-group" style="margin-bottom: 0;">
            <label class="form-label" for="configDueDate">Payment Due Date *</label>
            <input type="date" id="configDueDate" class="clay-input" required>
          </div>
        </div>

        <div style="background: rgba(245, 217, 158, 0.28); border: 1px solid rgba(181, 143, 83, 0.35); border-radius: var(--radius-md); padding: 12px 14px; margin-bottom: 22px; font-size: 12px; color: #56380E; line-height: 1.45; display: flex; align-items: flex-start; gap: 8px;">
          <i data-lucide="info" class="w-4 h-4" style="margin-top: 2px;" aria-hidden="true"></i>
          <span><strong>Note:</strong> Configuring this rent converts it to an active pending invoice. It will <strong>NOT</strong> be treated as paid and no receipt is generated until the resident submits payment proof.</span>
        </div>

        <div style="display: flex; gap: 12px; justify-content: flex-end;">
          <button type="button" class="clay-btn clay-btn-secondary" style="padding: 10px 18px;" onclick="closeConfigureRentModal()">
            Cancel
          </button>
          <button type="submit" class="clay-btn clay-btn-primary" style="padding: 10px 22px; background: linear-gradient(135deg, #B58F53 0%, #56380E 100%); color: #fffdf8; display: inline-flex; align-items: center; gap: 6px;">
            <i data-lucide="check" class="w-4 h-4" aria-hidden="true"></i> Save &amp; Activate Rent
          </button>
        </div>
      </form>
    </div>
  </div>

  <!-- =====================================================================
       MODAL: ADD NEW TENANT TO A UNIT
       Collects: Full Name, Birthday, Email, Phone, and Emergency Contact
       (Name, Relationship, Contact Number).
       Auto-generates sequential username (TRT001, TRT002, ...) and 6-digit passcode.
       ===================================================================== -->
  <div class="modal-backdrop" id="addTenantModal">
    <div class="clay-modal resident-reg-modal">
      <button type="button" class="modal-close-btn btn-remove-file resident-reg-close" onclick="closeModal('addTenantModal')" title="Close" aria-label="Close"><i data-lucide="x" class="w-4 h-4" aria-hidden="true"></i></button>

      <div class="resident-reg-header">
        <h2 class="resident-reg-title">NEW RESIDENT<br>REGISTRATION FORM</h2>
      </div>

      <form id="createTenantForm">
        <!-- Unit Assignment Selector (Kept compact in header band to preserve clean 3-row personal layout) -->
        <div class="resident-reg-unit-bar">
          <label class="resident-reg-unit-label" for="newUnitId">Assigned Unit:</label>
          <select id="newUnitId" name="unit_id" class="resident-reg-unit-select" required>
            <?php foreach ($units as $u): ?>
              <?php $isOcc = !empty($unitsWithTenants[$u['id']]['tenants']); ?>
              <option value="<?= $u['id'] ?>" data-monthly-rent="<?= (float)$u['monthly_rent'] ?>" <?= $isOcc ? 'disabled style="color: #94a3b8;"' : '' ?>>
                <?= htmlspecialchars($u['unit_number']) ?> <?= $isOcc ? '(Occupied)' : '(Available • ₱' . number_format((float)$u['monthly_rent'], 2) . '/mo)' ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>

        <hr class="resident-reg-divider">

        <!-- Top Section: Personal & Contact Details (Pill-shaped light beige inputs) -->
        <div class="resident-reg-row-3">
          <div class="resident-reg-field">
            <label class="resident-reg-label" for="newLastName">Last Name:</label>
            <input type="text" id="newLastName" name="last_name" class="resident-reg-pill-input" required>
          </div>
          <div class="resident-reg-field">
            <label class="resident-reg-label" for="newFirstName">First Name:</label>
            <input type="text" id="newFirstName" name="first_name" class="resident-reg-pill-input" required>
          </div>
          <div class="resident-reg-field">
            <label class="resident-reg-label" for="newMiddleName">Middle Name:</label>
            <input type="text" id="newMiddleName" name="middle_name" class="resident-reg-pill-input">
          </div>
        </div>

        <div class="resident-reg-row-2">
          <div class="resident-reg-field">
            <label class="resident-reg-label" for="newBirthday">Birthday</label>
            <input type="date"
                   id="newBirthday"
                   name="birthday"
                   class="resident-reg-pill-input resident-reg-date-input"
                   required
                   max="<?= (new DateTime('now', new DateTimeZone('Asia/Manila')))->format('Y-m-d') ?>">
          </div>
          <div class="resident-reg-field">
            <label class="resident-reg-label" for="newMoveInDate">Date of Moving In</label>
            <input type="date"
                   id="newMoveInDate"
                   name="move_in_date"
                   class="resident-reg-pill-input resident-reg-date-input"
                   required
                   min="<?= (new DateTime('now', new DateTimeZone('Asia/Manila')))->format('Y-m-d') ?>"
                   value="<?= (new DateTime('now', new DateTimeZone('Asia/Manila')))->format('Y-m-d') ?>">
          </div>
        </div>

        <div class="resident-reg-row-2" style="margin-bottom: 0;">
          <div class="resident-reg-field">
            <label class="resident-reg-label" for="newEmail">Email Address</label>
            <input type="email" id="newEmail" name="email" class="resident-reg-pill-input" required>
          </div>
          <div class="resident-reg-field">
            <label class="resident-reg-label" for="newPhone">Phone Number</label>
            <input type="tel" id="newPhone" name="phone" class="resident-reg-pill-input" required>
          </div>
        </div>

        <hr class="resident-reg-divider-mid">

        <!-- Grouped Box 1: Occupant Breakdown -->
        <div class="resident-reg-box">
          <h3 class="resident-reg-box-title">Occupant Breakdown</h3>
          <div class="resident-reg-occupant-grid">
            <div>
              <label class="resident-reg-sub-label" for="newAdultsCount">Number of Adults</label>
              <input type="number" id="newAdultsCount" name="adults_count" class="resident-reg-rect-input resident-reg-num-input" min="1" value="1" required>
            </div>
            <div>
              <label class="resident-reg-sub-label" for="newKidsCount">Number of Kids</label>
              <input type="number" id="newKidsCount" name="kids_count" class="resident-reg-rect-input resident-reg-num-input" min="0" value="0" required>
            </div>
            <div class="resident-reg-total-wrap">
              <label class="resident-reg-sub-label" for="newTotalOccupants" style="font-size: 13px; text-align: center;">Total Number:</label>
              <input type="text" id="newTotalOccupants" name="total_occupants" class="resident-reg-total-input" value="1" readonly aria-readonly="true">
            </div>
          </div>
        </div>

        <!-- Grouped Box 2: Additional Space Allocation -->
        <div class="resident-reg-box" id="additionalSpaceAllocationBox">
          <h3 class="resident-reg-box-title">Additional Space Allocation</h3>
          <p class="resident-reg-box-desc">Versatile open spaces available for tenant customization (e.g., recreation area, billiards, or personal use).</p>
          <div class="resident-reg-space-list">
            <?php foreach ($registrationSpaces as $sp): ?>
              <?php
                $spNum = (int)$sp['space_number'];
                $spId = (int)$sp['id'];
                $spFee = (float)$sp['monthly_fee'];
                $spOcc = !empty($sp['is_occupied']);
              ?>
              <label class="resident-reg-space-item<?= $spOcc ? ' is-occupied' : '' ?>" for="newSpace<?= $spNum ?>" data-space-id="<?= $spId ?>" data-space-number="<?= $spNum ?>">
                <input type="checkbox"
                       id="newSpace<?= $spNum ?>"
                       name="space_<?= $spNum ?>"
                       value="<?= $spId ?>"
                       class="resident-reg-space-checkbox"
                       data-space-id="<?= $spId ?>"
                       data-space-number="<?= $spNum ?>"
                       data-fee="<?= htmlspecialchars(number_format($spFee, 2, '.', ''), ENT_QUOTES, 'UTF-8') ?>"
                       <?= $spOcc ? 'disabled aria-disabled="true"' : '' ?>>
                <span class="resident-reg-space-text"><?= htmlspecialchars($sp['space_name']) ?> (₱<?= number_format($spFee, 0) ?>/month)</span>
                <?php if ($spOcc): ?>
                  <span class="resident-reg-occupied-tag">Occupied</span>
                <?php endif; ?>
              </label>
            <?php endforeach; ?>
          </div>
          <div class="resident-reg-space-summary">
            <div class="resident-reg-total-rent-preview">
              Total Monthly Rent: <strong id="newTotalMonthlyRentDisplay">₱0.00</strong>
            </div>
            <div class="resident-reg-additional-rent">
              Additional Rent: <span id="newAdditionalRentDisplay">₱0.00</span>
            </div>
          </div>
        </div>

        <!-- Grouped Box 3: Emergency Contact Information -->
        <div class="resident-reg-box" style="margin-bottom: 18px;">
          <h3 class="resident-reg-box-title">Emergency Contact Information</h3>
          <div style="margin-bottom: 12px;">
            <label class="resident-reg-sub-label" for="newEmergencyName">Contact Person's Full Name</label>
            <input type="text" id="newEmergencyName" name="emergency_contact_name" class="resident-reg-rect-input" required>
          </div>
          <div class="resident-reg-emergency-row">
            <div>
              <label class="resident-reg-sub-label" for="newEmergencyRel">Relationship</label>
              <input type="text" id="newEmergencyRel" name="emergency_contact_relationship" class="resident-reg-rect-input" required>
            </div>
            <div>
              <label class="resident-reg-sub-label" for="newEmergencyPhone">Contact Information</label>
              <input type="tel" id="newEmergencyPhone" name="emergency_contact_phone" class="resident-reg-rect-input" required>
            </div>
          </div>
        </div>

        <!-- Loading & Status State Container -->
        <div id="tenantCreationStatus" style="display: none; margin-bottom: 14px; padding: 12px 14px; border-radius: 10px; text-align: center; transition: all 0.25s ease;">
          <div id="tenantCreationLoading" style="display: flex; align-items: center; justify-content: center; gap: 10px; color: #56380E; font-weight: 700; font-size: 13px;">
            <span class="clay-spinner" style="width: 18px; height: 18px; border-width: 2.5px;"></span>
            <span>Creating tenant account, please wait...</span>
          </div>
          <div id="tenantCreationSuccess" style="display: none; align-items: center; justify-content: center; gap: 8px; color: #047857; font-weight: 700; font-size: 13px;">
            <i data-lucide="check-circle-2" class="w-4 h-4" style="color: #10b981;" aria-hidden="true"></i>
            <span id="tenantCreationSuccessText">Tenant account created successfully! Account details have been sent to the tenant's email address.</span>
          </div>
          <div id="tenantCreationError" style="display: none; align-items: center; justify-content: center; gap: 8px; color: #b91c1c; font-weight: 700; font-size: 13px;">
            <i data-lucide="alert-circle" class="w-4 h-4" style="color: #ef4444;" aria-hidden="true"></i>
            <span id="tenantCreationErrorText"></span>
          </div>
        </div>

        <div class="resident-reg-submit-row">
          <button type="submit" id="btnSubmitCreateTenant" class="resident-reg-submit-btn">REGISTER</button>
        </div>
      </form>
    </div>
  </div>

  <!-- =====================================================================
       MODAL: AUTO-GENERATED CREDENTIALS DISPLAY (WITH COPY BUTTONS)
       Prominently displays generated username (TRT00X) & 6-digit numeric password
       ===================================================================== -->
  <div class="modal-backdrop" id="tenantCredentialsModal">
    <div class="clay-modal" style="max-width: 480px; position: relative;">
      <button type="button" class="modal-close-btn btn-remove-file" onclick="closeModal('tenantCredentialsModal')" title="Close" aria-label="Close" style="position: absolute; top: 18px; right: 18px;"><i data-lucide="x" class="w-4 h-4" aria-hidden="true"></i></button>
      <div style="text-align: center; margin-bottom: 18px;">
        <div style="margin-bottom: 8px; display: flex; justify-content: center; color: #059669;"><i data-lucide="badge-check" class="w-10 h-10" style="width:40px;height:40px;" aria-hidden="true"></i></div>
        <h2 style="font-size: 22px; font-weight: 800; color: #0f172a;">Tenant Account Provisioned!</h2>
        <p style="font-size: 13px; color: var(--text-muted); margin-top: 4px;">
          Credentials have been auto-generated. Please securely copy and share these with the resident.
        </p>
      </div>

      <div class="credentials-box">
        <div style="font-size: 12.5px; color: var(--text-muted); margin-bottom: 12px; font-weight: 700; text-align: center;">
          Resident: <strong id="credTenantName" style="color: #0f172a;">-</strong> • Unit: <strong id="credUnitNumber" style="color: #56380E;">-</strong>
        </div>
        
        <div class="credential-row">
          <div>
            <div class="credential-label">Username</div>
            <div class="credential-value" id="credUsername">TRT001</div>
          </div>
          <button type="button" class="btn-copy-credential" onclick="copyCredField('credUsername', this)" style="display:inline-flex;align-items:center;gap:4px;"><i data-lucide="copy" class="w-3.5 h-3.5" aria-hidden="true"></i> Copy</button>
        </div>

        <div class="credential-row">
          <div>
            <div class="credential-label">6-Digit Passcode</div>
            <div class="credential-value" id="credPassword">123456</div>
          </div>
          <button type="button" class="btn-copy-credential" onclick="copyCredField('credPassword', this)" style="display:inline-flex;align-items:center;gap:4px;"><i data-lucide="copy" class="w-3.5 h-3.5" aria-hidden="true"></i> Copy</button>
        </div>
      </div>

      <div style="display: flex; gap: 12px; margin-top: 20px;">
        <button type="button" class="clay-btn clay-btn-secondary" style="flex: 1; padding: 12px; display: inline-flex; align-items: center; justify-content: center; gap: 6px;" onclick="copyAllCredentials(this)">
          <i data-lucide="copy" class="w-4 h-4" aria-hidden="true"></i> Copy All Details
        </button>
        <button type="button" class="clay-btn clay-btn-primary" style="flex: 1;" onclick="closeCredentialsModalAndReload()">
          Done &amp; View Resident
        </button>
      </div>
    </div>
  </div>

  <!-- =====================================================================
       MODAL: ANIMATED MAINTENANCE TICKET DETAILS & STATUS MANAGEMENT
       ===================================================================== -->
  <div class="modal-backdrop" id="ticketDetailsModal" onclick="handleTicketModalBackdropClick(event)">
    <div class="ticket-details-modal-box" onclick="event.stopPropagation()">
      
      <!-- Modal Header -->
      <div style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 18px; border-bottom: 1px solid rgba(166, 180, 204, 0.25); padding-bottom: 14px;">
        <div style="display: flex; align-items: center; gap: 12px; flex-wrap: wrap;">
          <span class="clay-badge" id="mdlTicketNumberBadge" style="background: #F3E4C6; color: #56380E; border: 1px solid rgba(181, 143, 83, 0.35); font-weight: 800; font-size: 13.5px; padding: 4px 11px;">#MT-0000</span>
          <div>
            <h2 id="mdlTicketTitle" style="font-size: 18px; font-weight: 800; color: #0f172a; margin: 0; line-height: 1.3;">Maintenance Request</h2>
            <p style="font-size: 12px; color: var(--text-muted); margin: 3px 0 0 0;">
              Submitted on <span id="mdlDateSubmitted" style="font-weight: 600; color: #334155;">-</span>
            </p>
          </div>
        </div>
        <button type="button" class="modal-close-btn btn-remove-file" onclick="closeModal('ticketDetailsModal')" title="Close details (Esc)" aria-label="Close"><i data-lucide="x" class="w-4 h-4" aria-hidden="true"></i></button>
      </div>

      <!-- Badges row: Category, Priority, Current Status -->
      <div style="display: flex; gap: 8px; margin-bottom: 18px; flex-wrap: wrap; align-items: center;">
        <span class="clay-badge" id="mdlCategoryBadge" style="font-size: 11.5px; font-weight: 700; padding: 4px 10px;">Category: General</span>
        <span class="clay-badge" id="mdlPriorityBadge" style="font-size: 11.5px; font-weight: 700; padding: 4px 10px;">Priority: Medium</span>
        <span class="clay-badge" id="mdlStatusBadge" style="font-size: 11.5px; font-weight: 700; padding: 4px 10px;">Status: Pending</span>
      </div>

      <!-- Scrollable content area -->
      <div style="display: flex; flex-direction: column; gap: 16px;">
        
        <!-- 1. Tenant & Unit Info Grid -->
        <div style="background: var(--clay-input-bg, #f8fafc); border-radius: var(--radius-md, 12px); padding: 14px 16px; box-shadow: var(--clay-sunken-shadow);">
          <div style="font-size: 12.5px; font-weight: 800; color: #0f172a; margin-bottom: 10px; display: flex; align-items: center; gap: 6px;">
            <i data-lucide="user" class="w-4 h-4" aria-hidden="true"></i> Tenant &amp; Unit Information
          </div>
          <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 10px; font-size: 13px;">
            <div><strong style="color: var(--text-muted);">Tenant Name:</strong> <span id="mdlTenantName" style="font-weight: 700; color: #0f172a;">-</span></div>
            <div><strong style="color: var(--text-muted);">Assigned Unit:</strong> <span class="clay-badge" id="mdlUnitBadge" style="background: #F3E4C6; color: #56380E; border: 1px solid rgba(181, 143, 83, 0.35); font-weight: 800; font-size: 11.5px; padding: 2px 8px;">-</span></div>
            <div style="grid-column: 1 / -1;"><strong style="color: var(--text-muted);">Email Address:</strong> <span id="mdlTenantEmail" style="font-weight: 600; color: #334155;">-</span></div>
          </div>
        </div>

        <!-- 2. Complete Issue Description -->
        <div>
          <div style="font-size: 12.5px; font-weight: 800; color: #0f172a; margin-bottom: 6px; display: flex; align-items: center; gap: 6px;">
            <i data-lucide="file-text" class="w-4 h-4" aria-hidden="true"></i> Complete Issue Description
          </div>
          <div style="background: var(--clay-input-bg, #f8fafc); border-radius: var(--radius-md, 12px); padding: 14px 16px; box-shadow: var(--clay-sunken-shadow); min-height: 80px;">
            <p id="mdlDescription" style="font-size: 13.5px; color: #1e293b; line-height: 1.6; white-space: pre-wrap; margin: 0;"></p>
          </div>
        </div>

        <!-- 3. Attached Images or Supporting Photos -->
        <div>
          <div style="font-size: 12.5px; font-weight: 800; color: #0f172a; margin-bottom: 6px; display: flex; align-items: center; gap: 6px;">
            <i data-lucide="image" class="w-4 h-4" aria-hidden="true"></i> Attached Images &amp; Supporting Photos
          </div>
          <div id="mdlPhotosContainer" style="display: flex; gap: 12px; flex-wrap: wrap;"></div>
          <div id="mdlNoPhotosNotice" style="display: none; background: #f8fafc; border: 1.5px dashed #cbd5e1; border-radius: var(--radius-md, 12px); padding: 18px; text-align: center; color: var(--text-muted); font-size: 12.5px;">
            No photos attached to this maintenance request.
          </div>
        </div>

        <!-- 4. Dedicated Status Update Section Inside the Modal -->
        <div style="background: #ffffff; border: 1.5px solid rgba(181, 143, 83, 0.45); border-radius: var(--radius-md, 12px); padding: 16px; box-shadow: 0 4px 14px rgba(86, 56, 14, 0.08);">
          <div style="font-size: 13px; font-weight: 800; color: #0f172a; margin-bottom: 12px; display: flex; align-items: center; gap: 6px;">
            <i id="mdlStatusHeadingIcon" data-lucide="settings" class="w-4 h-4" aria-hidden="true"></i>
            <span id="mdlStatusHeadingText">Update Maintenance Status</span>
          </div>
          <form id="formModalStatusUpdate" onsubmit="event.preventDefault(); submitModalStatusUpdate();">
            <input type="hidden" id="mdlTicketId" value="">

            <div class="form-group" style="margin-bottom: 12px;">
              <label class="form-label" for="mdlSelectStatus" style="font-weight: 700; color: #0f172a; font-size: 12.5px;">Status *</label>
              <select id="mdlSelectStatus" class="clay-select" style="width: 100%; font-weight: 700; font-size: 13.5px; padding: 9px 12px;">
                <option value="Pending">Pending</option>
                <option value="On-Going">On-Going</option>
                <option value="Done">Done</option>
              </select>
              <p id="mdlStatusHelpText" style="font-size: 11.5px; color: var(--text-muted); margin: 4px 0 0 2px;">
                Setting status to <strong>Done</strong> automatically moves this ticket to Completed Maintenance.
              </p>
              <div id="mdlStatusLockedNotice" style="display: none; align-items: center; gap: 6px; margin-top: 6px; font-size: 12px; color: #166534; background: #DCFCE7; border: 1px solid #BBF7D0; padding: 7px 10px; border-radius: 8px; font-weight: 700;">
                <i data-lucide="lock" class="w-4 h-4" aria-hidden="true"></i> Status is permanently locked as Done.
              </div>
            </div>

            <div class="form-group" style="margin-bottom: 14px;">
              <label class="form-label" for="mdlResolutionNotes" style="font-weight: 700; color: #0f172a; font-size: 12.5px;">Owner Remarks / Instructions (Optional)</label>
              <textarea id="mdlResolutionNotes" class="clay-textarea" rows="3" placeholder="Add instructions, contractor notes, or an update for the tenant..." style="font-size: 13px;"></textarea>
            </div>

            <!-- Active / Editable Buttons: Cancel + Save Changes -->
            <div id="mdlActiveActions" style="display: flex; gap: 10px; justify-content: flex-end;">
              <button type="button" id="btnModalCancel" class="clay-btn clay-btn-secondary" onclick="closeTicketDetailsModal()" style="padding: 9px 16px; font-size: 13px;">Cancel</button>
              <button type="submit" id="btnModalSubmitStatus" class="clay-btn clay-btn-primary" style="padding: 9px 20px; font-size: 13px; font-weight: 800;">
                <span style="display:inline-flex;align-items:center;gap:6px;"><i data-lucide="save" class="w-4 h-4" aria-hidden="true"></i> Save Changes</span>
              </button>
            </div>

            <!-- Read-Only Completed Button: Single Neutral Close Button -->
            <div id="mdlCompletedActions" style="display: none; justify-content: flex-end;">
              <button type="button" id="btnModalCloseOnly" class="clay-btn clay-btn-secondary" onclick="closeTicketDetailsModal()" style="padding: 9px 24px; font-size: 13px; font-weight: 700; min-width: 90px;">Close</button>
            </div>
          </form>
        </div>

      </div>
    </div>
  </div>

  <!-- =====================================================================
       MODAL: GENERAL TENANT REPORT DETAILS & OWNER DECISION
       ===================================================================== -->
  <div class="modal-backdrop" id="reportDetailsModal" onclick="handleReportModalBackdropClick(event)">
    <div class="clay-modal" style="max-width: 600px; max-height: 90vh; overflow-y: auto;">
      <div style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 16px; border-bottom: 1px solid var(--clay-border); padding-bottom: 14px;">
        <div style="padding-right: 12px;">
          <div style="display: flex; align-items: center; gap: 8px; flex-wrap: wrap; margin-bottom: 6px;">
            <span id="mdlReportIdBadge" style="font-size: 12px; font-weight: 800; color: #56380E; background: #F3E4C6; padding: 3px 9px; border-radius: 6px;">#GR-0001</span>
            <span id="mdlReportCategory" class="report-category-pill">General Inquiry</span>
            <span id="mdlReportStatusPill" class="report-status-pill report-status-pill--pending">PENDING</span>
          </div>
          <h2 id="mdlReportSubject" style="font-size: 19px; font-weight: 800; color: #0f172a; margin: 0;">Report Subject</h2>
          <p id="mdlReportDate" style="font-size: 12px; color: var(--text-muted); margin: 4px 0 0 0;">Submitted on -</p>
        </div>
        <button type="button" class="modal-close-btn btn-icon-close" onclick="closeModal('reportDetailsModal')" title="Close" aria-label="Close"><i data-lucide="x" class="w-4 h-4" aria-hidden="true"></i></button>
      </div>

      <!-- Tenant & Unit Info -->
      <div style="display: flex; align-items: center; gap: 14px; background: rgba(248, 250, 252, 0.9); border: 1px solid rgba(166, 180, 204, 0.35); border-radius: 12px; padding: 12px 16px; margin-bottom: 16px;">
        <div style="width: 40px; height: 40px; border-radius: 50%; background: linear-gradient(135deg, #B58F53, #56380E); color: #fff; font-weight: 800; font-size: 16px; display: flex; align-items: center; justify-content: center; flex-shrink: 0;" id="mdlReportTenantAvatar">
          T
        </div>
        <div style="flex: 1; min-width: 0;">
          <div style="font-size: 14.5px; font-weight: 700; color: #0f172a;" id="mdlReportTenantName">Tenant Name</div>
          <div style="font-size: 12px; color: var(--text-muted);" id="mdlReportTenantMeta">Unit - • -</div>
        </div>
        <div style="text-align: right; flex-shrink: 0;">
          <span class="clay-badge" style="background: #F3E4C6; color: #56380E; font-weight: 800; font-size: 12px;" id="mdlReportUnitBadge">Unit</span>
        </div>
      </div>

      <!-- Request Details -->
      <div style="margin-bottom: 16px;">
        <label style="display: block; font-size: 11px; font-weight: 700; color: var(--text-muted); text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 6px;">Request Details</label>
        <div style="background: #FFF9EE; border: 1px solid rgba(181, 143, 83, 0.35); border-radius: 12px; padding: 14px; font-size: 13.5px; color: #1e293b; line-height: 1.55; white-space: pre-wrap;" id="mdlReportDetails"></div>
      </div>

      <!-- Attachments -->
      <div id="mdlReportAttachmentsSection" style="margin-bottom: 16px; display: none;">
        <label style="display: block; font-size: 11px; font-weight: 700; color: var(--text-muted); text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 6px;">Supporting Documents / Photos</label>
        <div id="mdlReportAttachmentsContainer" style="display: flex; gap: 10px; flex-wrap: wrap;"></div>
      </div>

      <!-- Owner Remarks & Decision Form -->
      <div style="border-top: 1px solid rgba(166, 180, 204, 0.25); padding-top: 16px;">
        <input type="hidden" id="mdlReportId" value="">
        <div style="display: flex; justify-content: space-between; align-items: center; gap: 8px; flex-wrap: wrap; margin-bottom: 6px;">
          <label for="mdlReportRemarks" style="font-size: 12px; font-weight: 800; color: #302313;">Owner Remarks / Decision Notes</label>
          <span id="mdlReportReviewedMeta" style="font-size: 11.5px; color: var(--text-muted); font-weight: 600;">Not yet reviewed</span>
        </div>
        <textarea id="mdlReportRemarks" class="clay-input" rows="3" placeholder="Write approval conditions, lease addendum notes, or reason for rejection..." style="width: 100%; resize: vertical; font-size: 13.5px; padding: 10px 12px;"></textarea>

        <!-- Action Buttons Row (Active / Pending Review State) -->
        <div id="mdlReportActionButtons" class="report-decision-actions">
          <button type="button" id="btnReportApprove" class="report-action-btn report-action-btn--approve" onclick="handleReportDecision('Approved')" style="display:inline-flex;align-items:center;justify-content:center;gap:5px;"><i data-lucide="check" class="w-4 h-4" aria-hidden="true"></i> Approve</button>
          <button type="button" id="btnReportAcknowledge" class="report-action-btn report-action-btn--acknowledge" onclick="handleReportDecision('Acknowledged')" style="display:inline-flex;align-items:center;justify-content:center;gap:5px;"><i data-lucide="eye" class="w-4 h-4" aria-hidden="true"></i> Acknowledge</button>
          <button type="button" id="btnReportReject" class="report-action-btn report-action-btn--reject" onclick="handleReportDecision('Rejected')" style="display:inline-flex;align-items:center;justify-content:center;gap:5px;"><i data-lucide="x" class="w-4 h-4" aria-hidden="true"></i> Reject</button>
        </div>

        <!-- Read-Only Reviewed Actions: Single Neutral Close Button -->
        <div id="mdlReportReviewedActions" style="display: none; justify-content: flex-end; margin-top: 14px;">
          <button type="button" id="btnReportCloseOnly" class="clay-btn clay-btn-secondary" onclick="closeReportDetailsModal()" style="padding: 9px 24px; font-size: 13px; font-weight: 700; min-width: 90px;">Close</button>
        </div>
      </div>
    </div>
  </div>

  <!-- =====================================================================
       MODAL: EXPANDED TENANT DETAILS & PRIVACY (LANDLORD UI)
       Displays: Complete Info (Emergency contacts, 1 family occupant breakdown).
       ID Display: Tenant's uploaded Valid ID image is immediately visible.
       STRICT PRIVACY: Live Face Scan photo is strictly excluded from UI.
       ===================================================================== -->
  <!-- =====================================================================
       MODAL: TARARENT REBUILT TENANT INFORMATION VIEW (MATCHING IMAGE_56DA2C)
       ===================================================================== -->
  <div class="modal-backdrop" id="tenantDetailsModal" onclick="if(event.target === this) closeModal('tenantDetailsModal')">
    <div class="td-modal-dialog" onclick="event.stopPropagation()">
      
      <!-- Close "X" Button -->
      <button type="button" 
              class="td-modal-close-btn"
              onclick="closeModal('tenantDetailsModal')" 
              title="Close" 
              aria-label="Close">
        <i data-lucide="x" class="w-5 h-5" style="pointer-events: none;" aria-hidden="true"></i>
      </button>

      <!-- 1. HEADER & OCCUPANCY STATUS LAYOUT -->
      <div class="td-modal-header">
        <div>
          <!-- Large, bold Unit Number -->
          <h1 id="tdModalUnitNumber" class="td-modal-unit-title">
            UNIT 1
          </h1>
          <!-- Subunit details directly below it -->
          <p id="tdModalUnitDetails" class="td-modal-unit-subtitle">
            1 BR | 1 CR
          </p>
        </div>
        <!-- Occupancy Badge -->
        <div>
          <span id="tdModalOccupancyBadge" class="td-badge-pill td-badge-success">
            OCCUPIED
          </span>
        </div>
      </div>

      <!-- 2. TOP CARD: TENANT INFORMATION (FULL WIDTH) -->
      <div style="margin-bottom: 24px;">
        <!-- Section Title above Card -->
        <div class="td-section-label">
          TENANT INFORMATION
        </div>

        <!-- Full-Width Warm Clay Container -->
        <div class="td-clay-card" style="padding: 24px 26px;">
          <!-- Top Row: Avatar, Identity & Move-in Date -->
          <div class="td-personal-top">
            <div class="td-personal-identity">
              <!-- Circular Avatar -->
              <div id="tdModalAvatarBox" class="td-avatar-box">
                <img id="tdModalAvatarImg" src="" alt="Tenant photo" style="width: 100%; height: 100%; border-radius: 50%; object-fit: cover; display: none;" />
                <span id="tdModalAvatarInitial" class="td-avatar-initial">T</span>
              </div>
              <div>
                <h2 id="tdModalTenantName" class="td-tenant-name">
                  JANE DOE
                </h2>
                <div class="td-tenant-role" style="display: flex; align-items: center; gap: 8px; flex-wrap: wrap;">
                  <span>TENANT</span>
                  <span id="tdModalUsername" style="font-family: ui-monospace, 'SFMono-Regular', Menlo, Consolas, monospace; font-size: 11px; font-weight: 800; padding: 2px 8px; border-radius: 999px; background: rgba(74, 55, 40, 0.12); color: #4A3728; display: none;"></span>
                </div>
              </div>
            </div>

            <!-- Top Right Move-in Date Badge -->
            <div class="td-move-in-badge">
              <div id="tdModalMoveInDate" class="td-move-in-date">
                08/11/2026
              </div>
              <div class="td-move-in-label">
                date of move in
              </div>
            </div>
          </div>

          <!-- Personal & Emergency Columns -->
          <div class="td-personal-columns">
            <!-- Left Column: Personal Contact Info -->
            <div class="td-info-list">
              <div class="td-info-item">
                <span class="td-info-label">Birthday:</span>
                <span id="tdModalBirthday" class="td-info-val">-</span>
              </div>
              <div class="td-info-item">
                <span class="td-info-label">Phone No.:</span>
                <span id="tdModalPhone" class="td-info-val">-</span>
              </div>
              <div class="td-info-item">
                <span class="td-info-label">Email Address:</span>
                <span id="tdModalEmail" class="td-info-val">-</span>
              </div>
            </div>

            <!-- Center Divider -->
            <div class="td-vertical-divider"></div>

            <!-- Right Column: Emergency Contact -->
            <div>
              <div class="td-subcard-title">
                <i data-lucide="phone-call" style="width: 14px; height: 14px; color: #5C462D;" aria-hidden="true"></i>
                <span>Emergency Contact</span>
              </div>
              <div class="td-info-list">
                <div class="td-info-item">
                  <span class="td-info-label">NAME:</span>
                  <span id="tdModalEmergencyName" class="td-info-val">-</span>
                </div>
                <div class="td-info-item">
                  <span class="td-info-label">RELATIONSHIP:</span>
                  <span id="tdModalEmergencyRel" class="td-info-val">-</span>
                </div>
                <div class="td-info-item">
                  <span class="td-info-label">CONTACT NO.:</span>
                  <span id="tdModalEmergencyPhone" class="td-info-val">-</span>
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>

      <!-- 3. BOTTOM CARDS: SPLIT GRID LAYOUT (IDENTIFICATION & BILLING) -->
      <div class="td-bottom-grid">
        
        <!-- LEFT CARD: TENANT IDENTIFICATION INFORMATION -->
        <div>
          <div class="td-section-label">
            TENANT IDENTIFICATION INFORMATION
          </div>
          <div class="td-clay-card td-id-card-content">
            <!-- Status Row -->
            <div class="td-id-status-row">
              <span style="font-size: 13.5px; font-weight: 800; color: #3A2C1D; text-transform: uppercase;">STATUS:</span>
              <span id="tdModalIdStatusBadge" class="td-badge-pill td-badge-success">
                VERIFIED
              </span>
            </div>

            <!-- Dynamic ID Details Container (Conditional Rendering with Unverified Privacy Masking) -->
            <div id="tdModalIdDetailsContainer">
              <?php if (!empty($tenant) && (int)($tenant['is_verified'] ?? 0) === 1): ?>
                <!-- ID Icon, Type, and [view id] Button -->
                <div id="tdModalVerifiedIdSection" class="td-verified-id-box">
                  <!-- Illustrated ID Card Icon -->
                  <svg class="w-16 h-12 flex-shrink-0 drop-shadow-sm" viewBox="0 0 72 52" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true" style="width: 60px; height: 44px; flex-shrink: 0;">
                    <rect x="2" y="2" width="68" height="48" rx="8" fill="#F8E5E5" stroke="#1A120B" stroke-width="2.5"/>
                    <rect x="2" y="2" width="68" height="14" rx="8" fill="#F47272" stroke="#1A120B" stroke-width="2.5"/>
                    <rect x="8" y="20" width="20" height="24" rx="4" fill="#FFFFFF" stroke="#1A120B" stroke-width="2"/>
                    <circle cx="18" cy="27" r="3.5" fill="#1A120B"/>
                    <path d="M11 40c0-3.5 3-5 7-5s7 1.5 7 5" stroke="#1A120B" stroke-width="2" stroke-linecap="round" fill="#1A120B"/>
                    <text x="33" y="28" font-family="sans-serif" font-weight="900" font-size="9" fill="#1A120B">ID</text>
                    <line x1="45" y1="26" x2="62" y2="26" stroke="#1A120B" stroke-width="2" stroke-linecap="round"/>
                    <line x1="33" y1="34" x2="62" y2="34" stroke="#1A120B" stroke-width="2" stroke-linecap="round"/>
                    <line x1="33" y1="40" x2="54" y2="40" stroke="#1A120B" stroke-width="2" stroke-linecap="round"/>
                  </svg>

                  <!-- ID Type and [view id] clickable button -->
                  <div>
                    <div id="tdModalIdType" class="td-id-type-title">
                      <?= htmlspecialchars($tenant['id_type'] ?? 'PhilSys ID') ?>
                    </div>
                    <button type="button" 
                            id="tdModalBtnViewId" 
                            onclick="viewTenantIdImage()" 
                            class="td-btn-view-id">
                      <i data-lucide="eye" style="width: 13px; height: 13px;" aria-hidden="true"></i>
                      <span>[view id]</span>
                    </button>
                  </div>
                </div>
              <?php else: ?>
                <!-- Unverified Fallback Placeholder (No <a> tags, no modal triggers) -->
                <div id="tdModalUnverifiedIdSection" class="td-id-locked-box">
                  <div style="display: flex; align-items: center; justify-content: center; font-size: 14px; font-weight: 800; color: #3A2C1D;">
                    <span id="tdModalHiddenIdPlaceholder">ID Hidden (Pending Verification)</span>
                  </div>
                  <p style="font-size: 11.5px; color: #5C462D; margin: 4px 0 0 0; font-weight: 600;">
                    Identification documents are locked until account verification is complete.
                  </p>
                </div>
              <?php endif; ?>
            </div>
          </div>
        </div>

        <!-- RIGHT CARD: BILLING INFORMATION -->
        <div>
          <div class="td-section-label">
            BILLING INFORMATION
          </div>
          <div class="td-clay-card td-billing-content">
            <!-- Top Row: Payment Status & Deposit Status -->
            <div class="td-billing-status-group">
              <div class="td-billing-status-row">
                <span style="font-size: 12.5px; font-weight: 800; color: #3A2C1D; text-transform: uppercase;">PAYMENT STATUS:</span>
                <span id="tdModalPaymentStatusBadge" class="td-badge-pill td-badge-success">
                  PAID
                </span>
              </div>
              <div class="td-billing-status-row">
                <span id="tdModalDepositLabel" style="font-size: 12.5px; font-weight: 800; color: #3A2C1D; text-transform: uppercase;">
                  DEPOSIT: PHP 3000
                </span>
                <span id="tdModalDepositStatusBadge" class="td-badge-pill td-badge-success">
                  DONE
                </span>
              </div>
            </div>

            <!-- Divider -->
            <div class="td-billing-divider"></div>

            <!-- Bottom Rows Grid -->
            <div class="td-billing-metrics-grid">
              <!-- DUE DATE -->
              <div>
                <div class="td-metric-label td-label-due">
                  DUE DATE:
                </div>
                <div id="tdModalDueDate" class="td-metric-val">
                  08/11/26
                </div>
              </div>

              <!-- PAID ON -->
              <div>
                <div class="td-metric-label">
                  PAID ON:
                </div>
                <div id="tdModalPaidOnDate" class="td-metric-val">
                  08/11/26
                </div>
              </div>

              <!-- TOTAL AMOUNT -->
              <div>
                <div class="td-metric-label">
                  TOTAL AMOUNT:
                </div>
                <div id="tdModalTotalAmount" class="td-metric-val td-metric-val-total">
                  PHP 3,000.00
                </div>
              </div>

              <!-- PROOF OF PAYMENT THUMBNAIL BOX -->
              <div>
                <div class="td-metric-label">
                  PROOF OF PAYMENT:
                </div>
                <div id="tdModalReceiptThumbnail" 
                     onclick="viewTenantReceiptPhoto()" 
                     class="td-btn-view-receipt"
                     title="View Proof of Payment Photo">
                  <i data-lucide="eye" style="width: 14px; height: 14px; color: #1E160E;" aria-hidden="true"></i>
                  <span>VIEW PHOTO</span>
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>

      <!-- Footer Actions: Retain Move-Out / Settlement and Close Buttons -->
      <div class="td-modal-footer">
        <button type="button" class="td-btn-close" onclick="closeModal('tenantDetailsModal')">
          Close
        </button>
        <button type="button" id="tdBtnDelete" class="td-btn-moveout">
          <i data-lucide="log-out" class="w-4 h-4" aria-hidden="true"></i>
          <span>Mark as Moved Out</span>
        </button>
      </div>
    </div>
  </div>

  <!-- LIGHTBOX MODAL: FULL VALID ID IMAGE PREVIEW -->
  <div class="modal-backdrop" id="viewIdImageModal" onclick="if(event.target === this) closeModal('viewIdImageModal')" style="display: none; align-items: center; justify-content: center; z-index: 99999;">
    <div class="relative max-w-2xl w-[95%] bg-white rounded-2xl p-5 sm:p-6 shadow-2xl" onclick="event.stopPropagation()" style="background: #ffffff; border-radius: 20px; max-width: 620px; width: 95%; padding: 24px; box-shadow: 0 25px 60px rgba(0,0,0,0.3);">
      <div class="flex justify-between items-center mb-4 pb-3 border-b border-gray-200" style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px; padding-bottom: 12px; border-bottom: 1px solid #e2e8f0;">
        <h3 id="viewIdModalTitle" class="text-base sm:text-lg font-bold text-gray-900 flex items-center gap-2" style="font-size: 16px; font-weight: 800; color: #0f172a; margin: 0; display: flex; align-items: center; gap: 8px;">
          <i data-lucide="id-card" class="w-5 h-5 text-gray-700"></i>
          <span>Uploaded Valid ID Image</span>
        </h3>
        <button type="button" class="modal-close-btn text-gray-400 hover:text-gray-700 w-9 h-9 rounded-full flex items-center justify-center cursor-pointer border-0" onclick="closeModal('viewIdImageModal')" title="Close" aria-label="Close">
          <i data-lucide="x" class="w-5 h-5" aria-hidden="true"></i>
        </button>
      </div>
      <div id="viewIdModalBody" class="text-center p-2">
        <img id="viewIdModalImg" src="" alt="Valid ID" class="max-w-full max-h-[70vh] rounded-lg mx-auto object-contain shadow-sm" style="max-width: 100%; max-height: 70vh; border-radius: 12px; margin: 0 auto; display: block;">
        <div id="viewIdModalEmpty" class="hidden py-10 text-gray-400 text-sm font-medium" style="display: none; padding: 40px 0; color: #94a3b8; font-size: 14px;">
          No valid ID photo uploaded yet.
        </div>
      </div>
    </div>
  </div>

  <!-- LIGHTBOX MODAL: FULL PROOF OF PAYMENT RECEIPT PREVIEW -->
  <div class="modal-backdrop" id="viewReceiptImageModal" onclick="if(event.target === this) closeModal('viewReceiptImageModal')" style="display: none; align-items: center; justify-content: center; z-index: 99999;">
    <div class="relative max-w-2xl w-[95%] bg-white rounded-2xl p-5 sm:p-6 shadow-2xl" onclick="event.stopPropagation()" style="background: #ffffff; border-radius: 20px; max-width: 620px; width: 95%; padding: 24px; box-shadow: 0 25px 60px rgba(0,0,0,0.3);">
      <div class="flex justify-between items-center mb-4 pb-3 border-b border-gray-200" style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px; padding-bottom: 12px; border-bottom: 1px solid #e2e8f0;">
        <h3 id="viewReceiptModalTitle" class="text-base sm:text-lg font-bold text-gray-900 flex items-center gap-2" style="font-size: 16px; font-weight: 800; color: #0f172a; margin: 0; display: flex; align-items: center; gap: 8px;">
          <i data-lucide="receipt" class="w-5 h-5 text-gray-700"></i>
          <span>Proof of Payment Receipt</span>
        </h3>
        <button type="button" class="modal-close-btn text-gray-400 hover:text-gray-700 w-9 h-9 rounded-full flex items-center justify-center cursor-pointer border-0" onclick="closeModal('viewReceiptImageModal')" title="Close" aria-label="Close">
          <i data-lucide="x" class="w-5 h-5" aria-hidden="true"></i>
        </button>
      </div>
      <div id="viewReceiptModalBody" class="text-center p-2">
        <img id="viewReceiptModalImg" src="" alt="Proof of Payment" class="max-w-full max-h-[70vh] rounded-lg mx-auto object-contain shadow-sm" style="max-width: 100%; max-height: 70vh; border-radius: 12px; margin: 0 auto; display: block;">
        <div id="viewReceiptModalEmpty" class="hidden py-10 text-gray-400 text-sm font-medium" style="display: none; padding: 40px 0; color: #94a3b8; font-size: 14px;">
          No receipt photo on file for this billing record.
        </div>
      </div>
    </div>
  </div>

  <!-- =====================================================================
       ARCHIVED TENANT POPUP MODAL (EXPANDED 60-30-10 WITH FULL HISTORY)
       ===================================================================== -->
  <div id="archivedTenantModal"
       class="modal-backdrop fixed inset-0 z-[99999] hidden flex items-center justify-center bg-black/60 backdrop-blur-sm p-4 sm:p-6"
       style="z-index: 99999 !important; position: fixed !important; inset: 0 !important;"
       onclick="if (event.target === this) closeModal('archivedTenantModal')"
       role="dialog"
       aria-modal="true"
       aria-labelledby="archivedModalTitle">
    
    <div class="relative w-full max-w-4xl rounded-2xl shadow-2xl overflow-hidden border-2 border-[#B89B72] bg-[#F5F0E6] transition-all transform my-auto"
         onclick="event.stopPropagation()">
      
      <!-- 30% Secondary Color: Medium Brown Header Area -->
      <div class="bg-[#B89B72] px-6 py-4 flex items-center justify-between border-b-2 border-[#9A7B4F]">
        <div class="flex items-center gap-3">
          <div class="w-10 h-10 rounded-xl bg-[#4A3728]/15 border border-[#4A3728]/30 flex items-center justify-center text-[#4A3728]">
            <i data-lucide="archive" class="w-5 h-5 pointer-events-none"></i>
          </div>
          <div>
            <h3 id="archivedModalTitle" class="text-lg font-extrabold text-[#4A3728] leading-tight">Archived Tenant Details</h3>
            <p class="text-xs font-semibold text-[#4A3728]/80">Full resident profile, payment ledger &amp; maintenance history</p>
          </div>
        </div>
        
        <!-- 10% Dark Brown Close 'X' Button -->
        <button type="button"
                onclick="closeModal('archivedTenantModal')"
                class="modal-close-btn w-9 h-9 rounded-full bg-[#4A3728] hover:bg-[#382618] text-[#F5F0E6] flex items-center justify-center transition shadow-md focus:outline-none focus:ring-2 focus:ring-[#4A3728]/50 cursor-pointer"
                title="Close modal (Esc)"
                aria-label="Close modal">
          <i data-lucide="x" class="w-5 h-5 pointer-events-none"></i>
        </button>
      </div>

      <!-- Scrollable Body Container (max-h-[80vh] overflow-y-auto) -->
      <div class="p-6 bg-[#F5F0E6] overflow-y-auto max-h-[80vh] space-y-6">

        <!-- Loading Spinner State -->
        <div id="archivedModalLoading" class="py-16 text-center">
          <div class="w-12 h-12 border-4 border-[#B89B72]/30 border-t-[#4A3728] rounded-full animate-spin mx-auto mb-4"></div>
          <p class="text-sm font-extrabold text-[#4A3728]">Loading tenant archive &amp; historical records...</p>
          <p class="text-xs text-[#4A3728]/70 mt-1">Retrieving profile, payment ledger, and maintenance tickets</p>
        </div>

        <!-- Error State -->
        <div id="archivedModalError" class="hidden py-12 px-6 text-center bg-[#FAF5EC] rounded-xl border border-red-300">
          <div class="w-12 h-12 rounded-full bg-red-100 text-red-700 flex items-center justify-center mx-auto mb-3">
            <i data-lucide="alert-circle" class="w-6 h-6"></i>
          </div>
          <p id="archivedModalErrorMessage" class="text-sm font-bold text-red-700">Failed to load tenant record.</p>
          <button type="button" onclick="retryFetchArchivedTenant()" class="mt-4 px-4 py-2 text-xs font-bold rounded-lg bg-[#B89B72] text-[#4A3728] hover:bg-[#a88b62] transition shadow-xs">
            Retry
          </button>
        </div>

        <!-- Dynamic Content Body (Hidden while loading) -->
        <div id="archivedModalBodyContent" class="space-y-6" style="display: none;">

          <!-- SECTION 1: FULL PROFILE INFORMATION -->
          <div class="rounded-xl border border-[#B89B72]/40 bg-[#FAF5EC] p-5 shadow-sm">
            <div class="flex items-center gap-2.5 pb-3 mb-4 border-b border-[#B89B72]/30">
              <div class="w-8 h-8 rounded-lg bg-[#B89B72]/20 text-[#4A3728] flex items-center justify-center">
                <i data-lucide="user" class="w-4 h-4"></i>
              </div>
              <div>
                <h4 class="text-sm font-extrabold text-[#4A3728] uppercase tracking-wider">Full Profile Information</h4>
                <p class="text-[11px] text-[#4A3728]/70">Personal identity and lease arrangement details</p>
              </div>
            </div>

            <!-- Primary Profile Header Card -->
            <div class="flex items-center gap-4 mb-4 pb-4 border-b border-[#B89B72]/20">
              <div id="archivedModalAvatarContainer" class="flex-shrink-0">
                <div class="w-14 h-14 rounded-full bg-[#B89B72] text-[#4A3728] font-black text-xl flex items-center justify-center border-2 border-[#4A3728]/20 shadow-sm">
                  T
                </div>
              </div>
              <div class="min-w-0 flex-1">
                <div class="flex items-center gap-2 flex-wrap mb-1">
                  <h4 id="archivedModalTenantName" class="text-lg font-black text-[#4A3728] truncate">Tenant Name</h4>
                  <span id="archivedModalUsernameBadge" class="px-2 py-0.5 text-xs font-bold rounded-md bg-[#E8D8B0] text-[#4A3728] border border-[#B89B72]">@RC001</span>
                  <span class="px-2 py-0.5 text-[11px] font-bold rounded-full bg-[#DE7C5A]/20 text-[#8A3816] border border-[#DE7C5A]/40 uppercase tracking-wide">Archived</span>
                </div>
                <div class="flex items-center gap-2 text-xs text-[#4A3728]/80 flex-wrap">
                  <span>Assigned Unit:</span>
                  <span id="archivedModalUnitBadge" class="font-extrabold text-[#4A3728]">Unit N/A</span>
                  <span class="text-[#B89B72]">•</span>
                  <span id="archivedModalDeletedDate">Archived record</span>
                </div>
              </div>
            </div>

            <!-- Profile Fields Grid -->
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-3">
              <div class="p-3 rounded-lg bg-[#FFFDF9] border border-[#B89B72]/30 shadow-xs">
                <span class="block text-[10.5px] font-bold uppercase tracking-wider text-[#4A3728]/70 mb-0.5">Tenant Name</span>
                <p id="archivedModalName" class="text-xs font-bold text-[#4A3728] truncate">—</p>
              </div>
              <div class="p-3 rounded-lg bg-[#FFFDF9] border border-[#B89B72]/30 shadow-xs">
                <span class="block text-[10.5px] font-bold uppercase tracking-wider text-[#4A3728]/70 mb-0.5">Unit Number</span>
                <p id="archivedModalUnit" class="text-xs font-bold text-[#4A3728]">—</p>
              </div>
              <div class="p-3 rounded-lg bg-[#FFFDF9] border border-[#B89B72]/30 shadow-xs">
                <span class="block text-[10.5px] font-bold uppercase tracking-wider text-[#4A3728]/70 mb-0.5">Email Address</span>
                <p id="archivedModalEmail" class="text-xs font-bold text-[#4A3728] truncate">—</p>
              </div>
              <div class="p-3 rounded-lg bg-[#FFFDF9] border border-[#B89B72]/30 shadow-xs">
                <span class="block text-[10.5px] font-bold uppercase tracking-wider text-[#4A3728]/70 mb-0.5">Contact Number</span>
                <p id="archivedModalContact" class="text-xs font-bold text-[#4A3728]">—</p>
              </div>
              <div class="p-3 rounded-lg bg-[#FFFDF9] border border-[#B89B72]/30 shadow-xs">
                <span class="block text-[10.5px] font-bold uppercase tracking-wider text-[#4A3728]/70 mb-0.5">Date of Moving In</span>
                <p id="archivedModalMoveIn" class="text-xs font-bold text-[#4A3728]">—</p>
              </div>
              <div class="p-3 rounded-lg bg-[#FFFDF9] border border-[#B89B72]/30 shadow-xs">
                <span class="block text-[10.5px] font-bold uppercase tracking-wider text-[#4A3728]/70 mb-0.5">Move-Out Date</span>
                <p id="archivedModalMoveOut" class="text-xs font-bold text-[#92400E]">—</p>
              </div>
              <div class="p-3 rounded-lg bg-[#FFFDF9] border border-[#B89B72]/30 shadow-xs">
                <span class="block text-[10.5px] font-bold uppercase tracking-wider text-[#4A3728]/70 mb-0.5">Monthly Rent</span>
                <p id="archivedModalRent" class="text-xs font-bold text-[#4A3728]">—</p>
              </div>
              <div class="p-3 rounded-lg bg-[#FFFDF9] border border-[#B89B72]/30 shadow-xs">
                <span class="block text-[10.5px] font-bold uppercase tracking-wider text-[#4A3728]/70 mb-0.5">Date of Birth</span>
                <p id="archivedModalBirthday" class="text-xs font-bold text-[#4A3728]">—</p>
              </div>
              <div class="p-3 rounded-lg bg-[#FFFDF9] border border-[#B89B72]/30 shadow-xs">
                <span class="block text-[10.5px] font-bold uppercase tracking-wider text-[#4A3728]/70 mb-0.5">Emergency Contact</span>
                <p id="archivedModalEmergency" class="text-xs font-bold text-[#4A3728] truncate">—</p>
              </div>
            </div>
          </div>

          <!-- SECTION 2: PAYMENT HISTORY -->
          <div class="rounded-xl border border-[#B89B72]/40 bg-[#FAF5EC] p-5 shadow-sm">
            <div class="flex items-center justify-between pb-3 mb-4 border-b border-[#B89B72]/30">
              <div class="flex items-center gap-2.5">
                <div class="w-8 h-8 rounded-lg bg-[#B89B72]/20 text-[#4A3728] flex items-center justify-center">
                  <i data-lucide="receipt" class="w-4 h-4"></i>
                </div>
                <div>
                  <h4 class="text-sm font-extrabold text-[#4A3728] uppercase tracking-wider">Payment History</h4>
                  <p class="text-[11px] text-[#4A3728]/70">Complete financial ledger &amp; payment receipts</p>
                </div>
              </div>
              <span id="archivedModalPaymentCount" class="px-2.5 py-1 text-xs font-bold rounded-full bg-[#E8D8B0] text-[#4A3728] border border-[#B89B72]">
                0 Records
              </span>
            </div>

            <div class="overflow-x-auto rounded-lg border border-[#B89B72]/30 bg-[#FFFDF9]">
              <table class="w-full text-left text-xs" style="border-collapse: collapse;">
                <thead>
                  <tr class="bg-[#F5EEDD] text-[#4A3728] font-bold border-b border-[#B89B72]/30">
                    <th class="py-2.5 px-3">Billing Cycle / Pay For</th>
                    <th class="py-2.5 px-3">Amount Due</th>
                    <th class="py-2.5 px-3">Amount Paid</th>
                    <th class="py-2.5 px-3">Due Date</th>
                    <th class="py-2.5 px-3">Payment Date</th>
                    <th class="py-2.5 px-3">Method</th>
                    <th class="py-2.5 px-3">Status</th>
                  </tr>
                </thead>
                <tbody id="archivedModalPaymentsList" class="divide-y divide-[#B89B72]/20 text-[#4A3728]">
                  <!-- Dynamically generated via JavaScript -->
                </tbody>
              </table>
            </div>
          </div>

          <!-- SECTION 3: MAINTENANCE HISTORY -->
          <div class="rounded-xl border border-[#B89B72]/40 bg-[#FAF5EC] p-5 shadow-sm">
            <div class="flex items-center justify-between pb-3 mb-4 border-b border-[#B89B72]/30">
              <div class="flex items-center gap-2.5">
                <div class="w-8 h-8 rounded-lg bg-[#B89B72]/20 text-[#4A3728] flex items-center justify-center">
                  <i data-lucide="wrench" class="w-4 h-4"></i>
                </div>
                <div>
                  <h4 class="text-sm font-extrabold text-[#4A3728] uppercase tracking-wider">Maintenance History</h4>
                  <p class="text-[11px] text-[#4A3728]/70">Service tickets, repair issues, and resolution notes</p>
                </div>
              </div>
              <span id="archivedModalTicketCount" class="px-2.5 py-1 text-xs font-bold rounded-full bg-[#E8D8B0] text-[#4A3728] border border-[#B89B72]">
                0 Requests
              </span>
            </div>

            <div class="overflow-x-auto rounded-lg border border-[#B89B72]/30 bg-[#FFFDF9]">
              <table class="w-full text-left text-xs" style="border-collapse: collapse;">
                <thead>
                  <tr class="bg-[#F5EEDD] text-[#4A3728] font-bold border-b border-[#B89B72]/30">
                    <th class="py-2.5 px-3">Ticket / Issue</th>
                    <th class="py-2.5 px-3">Category</th>
                    <th class="py-2.5 px-3">Priority</th>
                    <th class="py-2.5 px-3">Date Reported</th>
                    <th class="py-2.5 px-3">Status</th>
                    <th class="py-2.5 px-3">Cost</th>
                  </tr>
                </thead>
                <tbody id="archivedModalMaintenanceList" class="divide-y divide-[#B89B72]/20 text-[#4A3728]">
                  <!-- Dynamically generated via JavaScript -->
                </tbody>
              </table>
            </div>
          </div>

        </div> <!-- /#archivedModalBodyContent -->

        <!-- Modal Footer -->
        <div class="pt-4 flex items-center justify-end gap-3 border-t border-[#B89B72]/30">
          <button type="button"
                  onclick="closeModal('archivedTenantModal')"
                  class="px-6 py-2 text-xs font-extrabold rounded-xl bg-[#4A3728] hover:bg-[#382618] text-[#F5F0E6] shadow-sm transition cursor-pointer">
            Close
          </button>
        </div>

      </div> <!-- /.overflow-y-auto -->
    </div>
  </div>

  <!-- =====================================================================
       MODAL: ANIMATED ARCHIVED TENANT DETAILS & RESTORATION
       ===================================================================== -->
  <div class="modal-backdrop" id="archivedTenantDetailsModal" onclick="handleArchivedModalBackdropClick(event)">
    <div class="archived-modal-box" onclick="event.stopPropagation()">
      <input type="hidden" id="archivedModalTenantId" value="">

      <!-- Header: Avatar, Name, Username, Archived Badge, Close Button -->
      <div style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 20px; border-bottom: 1px solid rgba(166, 180, 204, 0.25); padding-bottom: 16px;">
        <div style="display: flex; align-items: center; gap: 14px;">
          <div id="archivedModalAvatar" style="width: 50px; height: 50px; border-radius: 14px; background: linear-gradient(135deg, #64748b, #475569); color: #fff; font-weight: 800; font-size: 20px; display: flex; align-items: center; justify-content: center; flex-shrink: 0; box-shadow: 0 4px 10px rgba(0,0,0,0.1);">
            T
          </div>
          <div>
            <div style="display: flex; align-items: center; gap: 10px; flex-wrap: wrap;">
              <h2 id="archivedDetailsModalName" style="font-size: 20px; font-weight: 800; color: #0f172a; margin: 0; line-height: 1.2;">Archived Tenant Name</h2>
              <span id="archivedModalUsernameBadge" style="font-size: 12px; font-weight: 700; color: #56380E; background: #F3E4C6; border: 1px solid rgba(181, 143, 83, 0.35); padding: 2px 8px; border-radius: 6px;">@RC001</span>
              <span class="clay-badge" style="background: #fee2e2; color: #991b1b; font-size: 11px; font-weight: 800; padding: 3px 10px;">ARCHIVED</span>
            </div>
            <p id="archivedModalDeletedDate" style="font-size: 12.5px; color: var(--text-muted); margin: 4px 0 0 0;">Account archived on -</p>
          </div>
        </div>
        <button type="button" class="modal-close-btn btn-remove-file" onclick="closeModal('archivedTenantDetailsModal')" title="Close (Esc)" aria-label="Close"><i data-lucide="x" class="w-4 h-4" aria-hidden="true"></i></button>
      </div>

      <!-- Scrollable content sections -->
      <div style="display: flex; flex-direction: column; gap: 18px;">

        <!-- 1. PERSONAL INFORMATION & RENTAL INFORMATION (2 Columns) -->
        <div class="archived-info-grid" style="display: grid; grid-template-columns: 1fr 1fr; gap: 16px;">
          
          <!-- Card A: Personal Information -->
          <div style="background: var(--clay-input-bg, #f8fafc); border-radius: var(--radius-md, 12px); padding: 16px; box-shadow: var(--clay-sunken-shadow);">
            <div style="font-size: 12.5px; font-weight: 800; color: #0f172a; margin-bottom: 12px; display: flex; align-items: center; gap: 6px;">
              <i data-lucide="user" class="w-4 h-4" aria-hidden="true"></i> Personal &amp; Contact Information
            </div>
            <div style="display: flex; flex-direction: column; gap: 8px; font-size: 13px;">
              <div><span style="color: var(--text-muted);">Full Name:</span> <strong id="archivedPersonalName" style="color: #0f172a;">-</strong></div>
              <div><span style="color: var(--text-muted);">Email Address:</span> <span id="archivedPersonalEmail" style="color: #334155; font-weight: 600;">-</span></div>
              <div><span style="color: var(--text-muted);">Contact Number:</span> <span id="archivedPersonalPhone" style="color: #334155; font-weight: 600;">-</span></div>
              <div><span style="color: var(--text-muted);">Complete Address:</span> <span id="archivedPersonalAddress" style="color: #334155; font-weight: 600;">-</span></div>
              <div><span style="color: var(--text-muted);">Date of Birth:</span> <strong id="archivedPersonalBirthday" style="color: #0f172a;">-</strong></div>
              <div><span style="color: var(--text-muted);">Emergency Contact:</span> <span id="archivedPersonalEmergency" style="color: #334155; font-weight: 600;">-</span></div>
              <div><span style="color: var(--text-muted);">Household Occupants:</span> <span id="archivedPersonalOccupants" style="color: #334155; font-weight: 600;">-</span></div>
            </div>
          </div>

          <!-- Card B: Rental Information -->
          <div style="background: var(--clay-input-bg, #f8fafc); border-radius: var(--radius-md, 12px); padding: 16px; box-shadow: var(--clay-sunken-shadow);">
            <div style="font-size: 12.5px; font-weight: 800; color: #0f172a; margin-bottom: 12px; display: flex; align-items: center; gap: 6px;">
              <i data-lucide="home" class="w-4 h-4" aria-hidden="true"></i> Rental &amp; Move-Out Information
            </div>
            <div style="display: flex; flex-direction: column; gap: 8px; font-size: 13px;">
              <div><span style="color: var(--text-muted);">Assigned Unit:</span> <span class="clay-badge" id="archivedRentalUnit" style="background: #F3E4C6; color: #56380E; border: 1px solid rgba(181, 143, 83, 0.35); font-weight: 800; font-size: 11.5px; padding: 2px 8px;">-</span></div>
              <div><span style="color: var(--text-muted);">Unit Assignment History:</span> <strong id="archivedRentalUnitHistory" style="color: #334155;">-</strong></div>
              <div><span style="color: var(--text-muted);">Rented Spaces:</span> <span id="archivedRentalSpaces" style="color: #334155; font-weight: 600;">None</span></div>
              <div><span style="color: var(--text-muted);">Monthly Rent:</span> <strong id="archivedRentalMonthlyRent" style="color: #0f172a;">₱3,000.00 / mo</strong></div>
              <div><span style="color: var(--text-muted);">Advance Deposit:</span> <strong id="archivedRentalDeposit" style="color: #047857;">₱3,000.00</strong></div>
              <div><span style="color: var(--text-muted);">Account Created:</span> <span id="archivedRentalCreatedAt" style="color: #334155; font-weight: 600;">-</span></div>
              <div><span style="color: var(--text-muted);">Date of Moving In:</span> <strong id="archivedRentalMoveIn" style="color: #0f172a;">-</strong></div>
              <div><span style="color: var(--text-muted);">Move-Out Date:</span> <strong id="archivedRentalMoveOut" style="color: #92400e;">-</strong></div>
              <div><span style="color: var(--text-muted);">Date Archived:</span> <span id="archivedRentalDeletedAt" style="color: #be123c; font-weight: 700;">-</span></div>
            </div>
          </div>

        </div>

        <!-- 2. ACCOUNT INFORMATION -->
        <div style="background: var(--clay-input-bg, #f8fafc); border-radius: var(--radius-md, 12px); padding: 14px 16px; box-shadow: var(--clay-sunken-shadow); display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 10px;">
          <div>
            <div style="font-size: 11px; font-weight: 700; color: var(--text-muted); text-transform: uppercase;">Username</div>
            <strong id="archivedAccountUsername" style="font-size: 14px; color: #0f172a;">@RC001</strong>
          </div>
          <div>
            <div style="font-size: 11px; font-weight: 700; color: var(--text-muted); text-transform: uppercase;">Account Status</div>
            <span class="clay-badge" id="archivedAccountRentalStatus" style="background: #fee2e2; color: #991b1b; font-weight: 800; font-size: 11.5px; padding: 3px 10px;">Archived (Moved Out)</span>
          </div>
          <div>
            <div style="font-size: 11px; font-weight: 700; color: var(--text-muted); text-transform: uppercase;">ID Verification</div>
            <span id="archivedAccountIdStatus" style="font-size: 12.5px; font-weight: 600; color: #059669;">Verified Government ID</span>
          </div>
        </div>

        <!-- 3. PAYMENT RECORDS (Historical Payments & Advance Deposit) -->
        <div>
          <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 10px;">
            <div style="font-size: 13px; font-weight: 800; color: #0f172a; display: flex; align-items: center; gap: 6px;">
              <i data-lucide="credit-card" class="w-4 h-4" aria-hidden="true"></i> Previous Payment History &amp; Billing Records
            </div>
            <span id="archivedPaymentCountBadge" class="clay-badge" style="font-size: 11px; background: #f1f5f9; color: #475569; font-weight: 700;">
              0 Records
            </span>
          </div>

          <div style="background: var(--clay-input-bg, #f8fafc); border-radius: var(--radius-md, 12px); overflow: hidden; box-shadow: var(--clay-sunken-shadow); border: 1px solid rgba(166, 180, 204, 0.2);">
            <div style="overflow-x: auto; max-height: 240px; overflow-y: auto;">
              <table style="width: 100%; border-collapse: collapse; font-size: 12.5px; text-align: left;">
                <thead style="position: sticky; top: 0; z-index: 10;">
                  <tr style="border-bottom: 1.5px solid rgba(166, 180, 204, 0.3); background: #f1f5f9; color: var(--text-muted); font-weight: 700;">
                    <th style="padding: 9px 10px;">Billing Month</th>
                    <th style="padding: 9px 10px;">Type / Unit</th>
                    <th style="padding: 9px 10px;">Amount Due</th>
                    <th style="padding: 9px 10px;">Amount Paid</th>
                    <th style="padding: 9px 10px;">Payment Date</th>
                    <th style="padding: 9px 10px;">Payment Method</th>
                    <th style="padding: 9px 10px;">Status</th>
                    <th style="padding: 9px 10px; text-align: center; white-space: nowrap;">Receipt / Proof</th>
                  </tr>
                </thead>
                <tbody id="archivedPaymentsList">
                  <tr>
                    <td colspan="8" style="text-align: center; padding: 24px 14px; color: var(--text-muted);">
                      Loading payment records...
                    </td>
                  </tr>
                </tbody>
              </table>
            </div>
          </div>
        </div>

        <!-- 4. MAINTENANCE TICKETS HISTORY -->
        <div>
          <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 10px;">
            <div style="font-size: 13px; font-weight: 800; color: #0f172a; display: flex; align-items: center; gap: 6px;">
              <i data-lucide="wrench" class="w-4 h-4" aria-hidden="true"></i> Previous Maintenance Tickets
            </div>
            <span id="archivedTicketCountBadge" class="clay-badge" style="font-size: 11px; background: #f1f5f9; color: #475569; font-weight: 700;">
              0 Tickets
            </span>
          </div>

          <div id="archivedTicketsList" style="background: var(--clay-input-bg, #f8fafc); border-radius: var(--radius-md, 12px); padding: 12px; box-shadow: var(--clay-sunken-shadow); border: 1px solid rgba(166, 180, 204, 0.2); max-height: 240px; overflow-y: auto; display: flex; flex-direction: column; gap: 10px;">
            <div style="text-align: center; padding: 18px 12px; color: var(--text-muted); font-size: 12.5px;">
              Loading maintenance tickets...
            </div>
          </div>
        </div>

        <!-- 5. MODAL FOOTER & ARCHIVED TENANT FUNCTIONS -->
        <div style="display: flex; justify-content: space-between; align-items: center; border-top: 1px solid rgba(166, 180, 204, 0.25); padding-top: 16px; margin-top: 6px; flex-wrap: wrap; gap: 12px;">
          <div>
            <button type="button" id="btnPermanentDeleteArchived" class="btn-moveout-delete" style="padding: 9px 16px; font-size: 12.5px; margin: 0; display: inline-flex; align-items: center; gap: 6px;" onclick="submitPermanentDeleteArchived()">
              <i data-lucide="trash-2" class="w-4 h-4" aria-hidden="true"></i> Permanently Delete Account
            </button>
          </div>
          <div style="display: flex; gap: 10px; align-items: center;">
            <button type="button" class="clay-btn clay-btn-secondary" onclick="closeArchivedTenantModal()" style="padding: 10px 18px; font-size: 13px;">
              Close
            </button>
            <button type="button" id="btnRestoreArchivedAccount" class="clay-btn clay-btn-primary" style="padding: 10px 22px; font-size: 13px; font-weight: 800; background: linear-gradient(135deg, #059669, #047857); border-color: #047857;" onclick="submitRestoreArchivedAccount()">
              <span style="display:inline-flex;align-items:center;gap:6px;"><i data-lucide="rotate-ccw" class="w-4 h-4" aria-hidden="true"></i> Restore Account</span>
            </button>
          </div>
        </div>

      </div>
    </div>
  </div>

  <!-- =====================================================================
       MODAL: OWNER PASSWORD CONFIRMATION FOR TENANT DELETION
       ===================================================================== -->
  <div class="modal-backdrop" id="ownerDeleteConfirmModal" onclick="handleOwnerDeleteBackdropClick(event)">
    <div class="owner-delete-modal-box" onclick="event.stopPropagation()">
      <div style="display: flex; justify-content: space-between; align-items: flex-start; gap: 12px; margin-bottom: 16px;">
        <div style="display: flex; align-items: center; gap: 12px;">
          <div style="width: 44px; height: 44px; border-radius: 14px; background: linear-gradient(135deg, #ffe4e6, #fecdd3); color: #be123c; display: flex; align-items: center; justify-content: center; flex-shrink: 0; box-shadow: var(--clay-button-shadow);">
            <i data-lucide="shield-alert" class="w-5 h-5" aria-hidden="true"></i>
          </div>
          <div>
            <h2 id="ownerDeleteModalTitle" style="font-size: 18px; font-weight: 800; color: #0f172a; margin: 0; line-height: 1.25;">Confirm Tenant Account Deletion</h2>
            <p style="font-size: 12.5px; color: var(--text-muted); margin: 2px 0 0 0;">Owner password verification is required before proceeding</p>
          </div>
        </div>
        <button type="button" class="modal-close-btn btn-remove-file" onclick="closeModal('ownerDeleteConfirmModal')" title="Close (Esc)" aria-label="Close"><i data-lucide="x" class="w-4 h-4" aria-hidden="true"></i></button>
      </div>

      <!-- Target Tenant Context Box -->
      <div id="ownerDeleteTenantCard" style="background: var(--clay-input-bg); border: 1px solid rgba(166, 180, 204, 0.35); border-radius: 12px; padding: 12px 14px; margin-bottom: 14px;">
        <div style="font-size: 11px; font-weight: 800; text-transform: uppercase; letter-spacing: 0.4px; color: var(--text-muted); margin-bottom: 4px;">Target Tenant Account</div>
        <div style="display: flex; align-items: center; justify-content: space-between; gap: 8px; flex-wrap: wrap;">
          <span id="ownerDeleteTargetName" style="font-size: 15px; font-weight: 800; color: #0f172a;">Tenant Name</span>
          <span id="ownerDeleteTargetMeta" class="clay-badge" style="background: #e2e8f0; color: #334155; font-size: 11px; font-weight: 700;">ID #0</span>
        </div>
      </div>

      <!-- Permanent Action Warning -->
      <div style="background: #fff1f2; border: 1.5px solid #fecdd3; border-radius: 12px; padding: 12px 14px; margin-bottom: 16px; display: flex; align-items: flex-start; gap: 10px;">
        <span style="line-height: 1.2; flex-shrink: 0; color: #be123c;"><i data-lucide="alert-triangle" class="w-4 h-4" aria-hidden="true"></i></span>
        <p id="ownerDeleteWarningText" style="margin: 0; font-size: 12.5px; color: #881337; line-height: 1.45; font-weight: 600;">
          Deleting this tenant account is a permanent action and cannot be undone.
        </p>
      </div>

      <form id="ownerDeleteConfirmForm" onsubmit="event.preventDefault(); executeVerifiedTenantDeletion();">
        <div class="form-group" style="margin-bottom: 12px;">
          <label class="form-label" for="ownerDeletePasswordInput">Owner Account Password <span class="req">*</span></label>
          <div style="position: relative; display: flex; align-items: center;">
            <input type="password"
                   id="ownerDeletePasswordInput"
                   name="owner_password"
                   class="clay-input"
                   placeholder="Enter your current owner password"
                   autocomplete="current-password"
                   required
                   style="padding-right: 46px;"
                   oninput="clearOwnerDeletePasswordError()">
            <button type="button"
                    id="btnToggleOwnerDeletePass"
                    onclick="toggleOwnerDeletePasswordVisibility()"
                    aria-label="Show password"
                    title="Show / Hide Password"
                    style="position: absolute; right: 10px; background: transparent; border: none; color: #64748b; cursor: pointer; padding: 4px 6px; display: inline-flex; align-items: center;">
              <i data-lucide="eye" class="w-4 h-4" aria-hidden="true"></i>
            </button>
          </div>
        </div>

        <div id="ownerDeleteErrorAlert" style="display: none; background: #fef2f2; border: 1px solid #fecaca; color: #b91c1c; border-radius: 10px; padding: 10px 12px; font-size: 12.5px; font-weight: 700; margin-bottom: 14px; line-height: 1.4;"></div>

        <div style="display: flex; gap: 10px; margin-top: 16px;">
          <button type="button" id="btnCancelOwnerDelete" class="clay-btn clay-btn-secondary" style="flex: 1; padding: 11px;" onclick="closeOwnerDeleteConfirmModal()">
            Cancel
          </button>
          <button type="submit" id="btnConfirmOwnerDelete" class="clay-btn clay-btn-primary" style="flex: 1.4; padding: 11px; background: linear-gradient(135deg, #e11d48, #be123c); border-color: #be123c; color: #ffffff; font-weight: 800; box-shadow: 0 6px 16px rgba(225, 29, 72, 0.3);">
            <span style="display:inline-flex;align-items:center;gap:6px;"><i data-lucide="trash-2" class="w-4 h-4" aria-hidden="true"></i> Confirm Delete</span>
          </button>
        </div>
      </form>
    </div>
  </div>

  <!-- =====================================================================
       MODAL: CONFIRM TENANT MOVE-OUT & AUTOMATIC ARCHIVING AUDIT
       ===================================================================== -->
  <div class="modal-backdrop" id="moveOutConfirmModal" onclick="handleMoveOutBackdropClick(event)">
    <div class="moveout-modal-box" onclick="event.stopPropagation()">
      <div style="display: flex; justify-content: space-between; align-items: flex-start; gap: 12px; margin-bottom: 16px;">
        <div style="display: flex; align-items: center; gap: 12px;">
          <div style="width: 44px; height: 44px; border-radius: 14px; background: linear-gradient(135deg, #F5D99E, #D8BD94); color: #56380E; display: flex; align-items: center; justify-content: center; flex-shrink: 0; box-shadow: var(--clay-button-shadow); border: 1px solid rgba(181, 143, 83, 0.4);">
            <i data-lucide="log-out" class="w-5 h-5" aria-hidden="true"></i>
          </div>
          <div>
            <h2 id="moModalTitle" style="font-size: 18px; font-weight: 800; color: #302313; margin: 0; line-height: 1.25;">Confirm Tenant Move-Out</h2>
            <p style="font-size: 12.5px; color: var(--text-muted); margin: 2px 0 0 0;">Verify payment settlement before automatic transfer to Archived Tenants</p>
          </div>
        </div>
        <button type="button" class="modal-close-btn btn-remove-file" onclick="closeModal('moveOutConfirmModal')" title="Close (Esc)" aria-label="Close"><i data-lucide="x" class="w-4 h-4" aria-hidden="true"></i></button>
      </div>

      <!-- Target Tenant & Assigned Unit Context -->
      <div style="background: var(--clay-input-bg, #f8fafc); border: 1px solid rgba(181, 143, 83, 0.3); border-radius: 14px; padding: 14px 16px; margin-bottom: 14px;">
        <div style="display: flex; align-items: center; justify-content: space-between; gap: 10px; flex-wrap: wrap;">
          <div>
            <div style="font-size: 11px; font-weight: 800; text-transform: uppercase; letter-spacing: 0.4px; color: var(--text-muted); margin-bottom: 3px;">Tenant &amp; Assigned Unit</div>
            <div style="display: flex; align-items: center; gap: 8px; flex-wrap: wrap;">
              <span id="moTenantName" style="font-size: 16px; font-weight: 800; color: #0f172a;">Tenant Name</span>
              <span id="moTenantUsername" style="font-size: 11.5px; font-weight: 700; color: #56380E; background: #F3E4C6; border: 1px solid rgba(181, 143, 83, 0.35); padding: 2px 8px; border-radius: 6px;">@RC000</span>
            </div>
          </div>
          <span id="moUnitBadge" class="clay-badge" style="background: #F3E4C6; color: #56380E; border: 1px solid rgba(181, 143, 83, 0.4); font-size: 12px; font-weight: 800; padding: 5px 12px;">Unit N/A</span>
        </div>
        <div style="display: flex; align-items: center; justify-content: space-between; gap: 12px; margin-top: 12px; padding-top: 10px; border-top: 1px dashed rgba(181, 143, 83, 0.28); flex-wrap: wrap;">
          <div style="font-size: 12px; color: var(--text-muted);">
            Move-In Date: <strong id="moMoveInDate" style="color: #0f172a;">—</strong>
          </div>
          <div style="display: flex; align-items: center; gap: 8px;">
            <label for="moMoveOutDate" style="font-size: 12px; font-weight: 700; color: #56380E; margin: 0;">Official Move-Out Date:</label>
            <input type="date" id="moMoveOutDate" class="clay-input" style="padding: 5px 10px; font-size: 12.5px; width: auto; height: auto;" required>
          </div>
        </div>
      </div>

      <!-- Loading Audit Indicator -->
      <div id="moLoadingState" style="display: flex; align-items: center; justify-content: center; gap: 10px; padding: 22px 12px; color: var(--text-muted); font-size: 13px;">
        <span class="clay-spinner" style="width: 18px; height: 18px; border-width: 2px;"></span>
        <span>Auditing outstanding bills and pending payment verifications...</span>
      </div>

      <!-- Settlement Audit Content -->
      <div id="moSettlementContainer" style="display: none;">
        <div style="font-size: 12px; font-weight: 800; text-transform: uppercase; letter-spacing: 0.4px; color: #56380E; margin-bottom: 8px;">
          Financial &amp; Verification Status
        </div>

        <div class="moveout-audit-grid">
          <div style="background: #FFF9EE; border: 1px solid rgba(181, 143, 83, 0.35); border-radius: 12px; padding: 10px 12px; text-align: center;">
            <div style="font-size: 11px; color: var(--text-muted); font-weight: 700;">Unpaid / Overdue Bills</div>
            <div id="moUnpaidCount" style="font-size: 18px; font-weight: 800; color: #302313; margin-top: 2px;">0</div>
          </div>
          <div style="background: #FFF9EE; border: 1px solid rgba(181, 143, 83, 0.35); border-radius: 12px; padding: 10px 12px; text-align: center;">
            <div style="font-size: 11px; color: var(--text-muted); font-weight: 700;">Pending Verifications</div>
            <div id="moPendingCount" style="font-size: 18px; font-weight: 800; color: #302313; margin-top: 2px;">0</div>
          </div>
          <div style="background: #FFF9EE; border: 1px solid rgba(181, 143, 83, 0.35); border-radius: 12px; padding: 10px 12px; text-align: center;">
            <div style="font-size: 11px; color: var(--text-muted); font-weight: 700;">Outstanding Balance</div>
            <div id="moOutstandingBalance" style="font-size: 17px; font-weight: 800; color: #302313; margin-top: 2px;">₱0.00</div>
          </div>
        </div>

        <!-- Itemized Unsettled Obligations List -->
        <div id="moUnsettledListWrap" style="display: none; background: #fff; border: 1px solid #fecdd3; border-radius: 12px; overflow: hidden; margin-bottom: 12px;">
          <div style="background: #fff1f2; padding: 8px 12px; font-size: 11.5px; font-weight: 800; color: #9f1239; border-bottom: 1px solid #fecdd3;">
            Unsettled Bills &amp; Pending Receipts
          </div>
          <div id="moUnsettledList" style="max-height: 150px; overflow-y: auto; font-size: 12.5px;"></div>
        </div>

        <!-- Settlement Status / Warning Banner -->
        <div id="moWarningBanner" style="border-radius: 12px; padding: 12px 14px; margin-bottom: 14px; display: flex; align-items: flex-start; gap: 10px;">
          <span id="moWarningIcon" style="line-height: 1.2; flex-shrink: 0;"><i data-lucide="check-circle-2" class="w-4 h-4" aria-hidden="true"></i></span>
          <p id="moWarningText" style="margin: 0; font-size: 12.5px; line-height: 1.45; font-weight: 600;">
            Checking settlement...
          </p>
        </div>
      </div>

      <form id="moveOutConfirmForm" onsubmit="event.preventDefault(); submitMoveOutConfirmation();">
        <label style="display: flex; align-items: flex-start; gap: 10px; background: #FFFDF8; border: 1.5px solid rgba(181, 143, 83, 0.4); border-radius: 12px; padding: 12px 14px; cursor: pointer; margin-bottom: 12px;">
          <input type="checkbox" id="moSettlementConfirmCheckbox" style="margin-top: 2px; width: 16px; height: 16px; accent-color: #B58F53; flex-shrink: 0;">
          <span style="font-size: 12.5px; color: #302313; font-weight: 600; line-height: 1.45;">
            I confirm that the tenant is moving out on the selected date and I have checked whether all outstanding bills and pending payments have been fully settled.
          </span>
        </label>

        <!-- Landlord Password Verification Field -->
        <div class="form-group" style="margin-bottom: 14px;">
          <label class="form-label" for="moOwnerPasswordInput" style="font-size: 12.5px; font-weight: 800; color: #56380E; display: flex; align-items: center; justify-content: space-between; margin-bottom: 6px;">
            <span>Authorize Move-Out (Landlord Password) <span style="color: #b91c1c;">*</span></span>
            <span style="font-size: 11px; font-weight: 600; color: var(--text-muted);">Required for authorization</span>
          </label>
          <div style="position: relative; display: flex; align-items: center;">
            <input type="password" id="moOwnerPasswordInput" name="owner_password" class="clay-input" placeholder="Enter your Landlord account password" autocomplete="current-password" required style="padding-right: 46px; width: 100%;">
            <button type="button" id="btnToggleMoOwnerPass" onclick="toggleMoPasswordVisibility()" aria-label="Show password" title="Show / Hide Password" style="position: absolute; right: 10px; background: transparent; border: none; color: #64748b; cursor: pointer; padding: 4px 6px; display: inline-flex; align-items: center;">
              <i data-lucide="eye" class="w-4 h-4" aria-hidden="true"></i>
            </button>
          </div>
        </div>

        <div id="moErrorAlert" style="display: none; background: #fef2f2; border: 1px solid #fecaca; color: #b91c1c; border-radius: 10px; padding: 10px 12px; font-size: 12.5px; font-weight: 700; margin-bottom: 12px; line-height: 1.45;"></div>

        <div style="display: flex; gap: 10px; margin-top: 14px;">
          <button type="button" id="btnCancelMoveOut" class="clay-btn clay-btn-secondary" style="flex: 1; padding: 11px;" onclick="closeModal('moveOutConfirmModal')">
            Cancel
          </button>
          <button type="submit" id="btnConfirmMoveOut" class="clay-btn clay-btn-primary" style="flex: 1.5; padding: 11px; font-weight: 800;">
            <span style="display:inline-flex;align-items:center;gap:6px;"><i data-lucide="check-circle-2" class="w-4 h-4" aria-hidden="true"></i> Confirm Move-Out</span>
          </button>
        </div>
      </form>
    </div>
  </div>

  <div class="modal-backdrop" id="announcementModal">
    <div class="clay-modal" style="max-width: 560px;">
      <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
        <div>
          <h2 style="font-size: 20px; font-weight: 800; color: #0f172a; display: flex; align-items: center; gap: 8px;"><i data-lucide="megaphone" class="w-5 h-5" aria-hidden="true"></i> Send Rent Due Announcement</h2>
          <p style="font-size: 12.5px; color: var(--text-muted); margin-top: 2px;">Broadcast rental reminders and instructions to tenants via email</p>
        </div>
        <button type="button" class="modal-close-btn btn-remove-file" onclick="closeModal('announcementModal')" title="Close" aria-label="Close"><i data-lucide="x" class="w-4 h-4" aria-hidden="true"></i></button>
      </div>

      <form id="announcementForm" onsubmit="event.preventDefault(); submitAnnouncement();">
        <div class="form-group" style="margin-bottom: 16px;">
          <label class="form-label" for="announcementTitle">Announcement Title *</label>
          <input type="text" id="announcementTitle" class="clay-input" placeholder="e.g. Monthly Rent Collection Notice - November" required>
        </div>

        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 14px; margin-bottom: 16px;">
          <div class="form-group" style="margin-bottom: 0;">
            <label class="form-label" for="announcementTargetType">Recipients *</label>
            <select id="announcementTargetType" class="clay-select" style="width: 100%;" onchange="toggleAnnouncementUnitSelector(this.value)">
              <option value="all">All Active Tenants</option>
              <option value="selected">Specific Units Only</option>
            </select>
          </div>
          <div class="form-group" style="margin-bottom: 0;">
            <label class="form-label" for="announcementDueDate">Due Date</label>
            <input type="date" id="announcementDueDate" class="clay-input">
          </div>
        </div>

        <div class="form-group" id="announcementUnitsContainer" style="display: none; margin-bottom: 16px;">
          <label class="form-label">Select Units</label>
          <div style="display: flex; gap: 14px; background: var(--clay-input-bg); padding: 10px 14px; border-radius: var(--radius-md);">
            <label style="display: inline-flex; align-items: center; gap: 6px; font-size: 13px; font-weight: 600; cursor: pointer;">
              <input type="checkbox" name="announcement_units[]" value="1"> Unit 101
            </label>
            <label style="display: inline-flex; align-items: center; gap: 6px; font-size: 13px; font-weight: 600; cursor: pointer;">
              <input type="checkbox" name="announcement_units[]" value="2"> Unit 102
            </label>
            <label style="display: inline-flex; align-items: center; gap: 6px; font-size: 13px; font-weight: 600; cursor: pointer;">
              <input type="checkbox" name="announcement_units[]" value="3"> Unit 103
            </label>
          </div>
        </div>

        <div class="form-group" style="margin-bottom: 20px;">
          <label class="form-label" for="announcementMessage">Custom Message from Owner *</label>
          <textarea id="announcementMessage" class="clay-textarea" rows="4" placeholder="Enter custom message to residents regarding payment schedules, accepted payment methods (GCash / Bank Transfer), and guidelines..." required></textarea>
        </div>

        <div style="display: flex; gap: 12px;">
          <button type="button" class="clay-btn btn-secondary" style="flex: 1; padding: 12px;" onclick="closeAnnouncementModal()">
            Cancel
          </button>
          <button type="submit" id="btnSubmitAnnouncement" class="clay-btn clay-btn-primary" style="flex: 1; padding: 12px;">
            <span>Send Announcement Emails</span>
          </button>
        </div>
      </form>
    </div>
  </div>

  <!-- Clay Toast Notification -->
  <div class="clay-toast" id="clayToast">
    <span class="toast-icon" id="toastIcon"><i data-lucide="check-circle-2" class="w-4 h-4" aria-hidden="true"></i></span>
    <span class="toast-message" id="toastMessage">Done</span>
  </div>

  <script src="assets/js/charts.js"></script>
  <script>
    function showToast(msg, type = 'success') {
      const t = document.getElementById('clayToast');
      const iconEl = document.getElementById('toastIcon');
      if (iconEl) {
        if (type === 'error') {
          iconEl.innerHTML = window.rcIcon('alert-triangle', 'w-4 h-4');
          iconEl.style.color = '#dc2626';
        } else if (type === 'info') {
          iconEl.innerHTML = window.rcIcon('info', 'w-4 h-4');
          iconEl.style.color = '#0284c7';
        } else {
          iconEl.innerHTML = window.rcIcon('check-circle-2', 'w-4 h-4');
          iconEl.style.color = '#059669';
        }
      }
      document.getElementById('toastMessage').textContent = msg;
      t.classList.add('show');
      setTimeout(() => t.classList.remove('show'), 3500);
    }

    // Global Modal Controller: Open & Close Modal with Hidden State
    function openModal(modalId) {
      const modal = typeof modalId === 'string' ? document.getElementById(modalId) : modalId;
      if (!modal) return;
      modal.classList.remove('hidden');
      modal.classList.add('open', 'active');
      modal.style.display = 'flex';
      modal.style.pointerEvents = 'auto';
      document.body.style.overflow = 'hidden';
      if (window.lucide && typeof window.lucide.createIcons === 'function') {
        window.lucide.createIcons();
      }
    }
    window.openModal = openModal;

    function closeModal(modalId) {
      const modal = typeof modalId === 'string' ? document.getElementById(modalId) : modalId;
      if (!modal) return;
      modal.classList.add('hidden');
      modal.classList.remove('open', 'active');
      modal.style.display = 'none';
      modal.style.pointerEvents = 'none';
      document.body.style.overflow = '';

      const id = modal.id;
      if (id === 'paymentDetailsModal' && typeof closePaymentDetailsModal === 'function') closePaymentDetailsModal(true);
      else if (id === 'configureRentModal' && typeof closeConfigureRentModal === 'function') closeConfigureRentModal(true);
      else if (id === 'addTenantModal' && typeof closeAddTenantModal === 'function') closeAddTenantModal(true);
      else if (id === 'tenantCredentialsModal' && typeof closeCredentialsModalAndReload === 'function') closeCredentialsModalAndReload();
      else if (id === 'ticketDetailsModal' && typeof closeTicketDetailsModal === 'function') closeTicketDetailsModal(true);
      else if (id === 'reportDetailsModal' && typeof closeReportDetailsModal === 'function') closeReportDetailsModal(true);
      else if (id === 'tenantDetailsModal' && typeof closeTenantDetailsModal === 'function') closeTenantDetailsModal(true);
      else if (id === 'archivedTenantModal' && typeof closeArchivedModal === 'function') closeArchivedModal();
      else if (id === 'archivedTenantDetailsModal' && typeof closeArchivedTenantModal === 'function') closeArchivedTenantModal(true);
      else if (id === 'ownerDeleteConfirmModal' && typeof closeOwnerDeleteConfirmModal === 'function') closeOwnerDeleteConfirmModal(true);
      else if (id === 'moveOutConfirmModal' && typeof closeMoveOutConfirmModal === 'function') closeMoveOutConfirmModal(true);
      else if (id === 'announcementModal' && typeof closeAnnouncementModal === 'function') closeAnnouncementModal(true);
      else if (id === 'modalAdminProfileId' && typeof closeAdminIdModal === 'function') closeAdminIdModal(true);
    }
    window.closeModal = closeModal;

    // Universal Close Button Click Listener (Capturing phase guarantees all "X" buttons work)
    document.addEventListener('click', function(e) {
      const closeBtn = e.target.closest('.modal-close-btn, .btn-remove-file, .btn-icon-close, .resident-reg-close, .tararent-modal-close-btn, [data-modal-close], button[aria-label="Close"], button[title*="Close"]');
      if (closeBtn) {
        if (closeBtn.id === 'btnRemoveFile' || closeBtn.id === 'btnRemoveAttachedPhoto' || closeBtn.id === 'btnRemoveIdFile') return;
        const modal = closeBtn.closest('.modal-backdrop, [role="dialog"], [id$="Modal"]');
        if (modal && modal.id && typeof window.closeModal === 'function') {
          e.preventDefault();
          e.stopPropagation();
          window.closeModal(modal.id);
        }
      }
    }, true);

    // Sidebar Toggle & Active-State Controller provided by assets/js/sidebar.js

    // Sidebar Section Switching:
    // 1. Fully Clickable: Navigates smoothly to the target section and activates the clicked sidebar tab.
    // 2. Persistent Sidebar State: Desktop collapse state strictly changes only when the toggle button is clicked.
    function switchSection(sectionId, btn, updateUrl = true) {
      if (!sectionId) return;

      if (sectionId === 'sectionArchivedTenants') {
        const dirBtn = document.getElementById('navTenantDirectory');
        switchSection('sectionDirectory', dirBtn, updateUrl);
        switchDirectoryTab('archived');
        return;
      }

      if (sectionId === 'sectionProfile') {
        openProfileView('accountInfo', updateUrl);
        return;
      }

      document.querySelectorAll('.app-view-section').forEach(s => s.classList.remove('active-section'));

      const target = document.getElementById(sectionId);
      if (target) {
        target.classList.add('active-section');
      }

      if (typeof window.setActiveSidebarNavItem === 'function') {
        window.setActiveSidebarNavItem(sectionId, btn);
      }

      if (sectionId === 'sectionAnalytics') {
        loadDashboardAnalytics();
      } else if (sectionId === 'sectionDirectory' && typeof fetchActiveDirectoryUnits === 'function') {
        fetchActiveDirectoryUnits();
      } else if (sectionId === 'sectionTickets') {
        if (typeof switchTicketTab === 'function') {
          switchTicketTab('active');
        }
        if (typeof fetchMaintenanceTickets === 'function') {
          fetchMaintenanceTickets(true);
        }
      } else if (sectionId === 'sectionReports' && typeof fetchTenantReports === 'function') {
        fetchTenantReports(true);
      } else if (sectionId === 'sectionPayments') {
        if (typeof switchPaymentTab === 'function') {
          switchPaymentTab('pending');
        }
      }

      // Synchronize profile name elements
      updateProfileSettingsVisibility(false);

      // Universal Contextual Routing: update URL to reflect current sidebar category
      if (updateUrl && window.history && window.history.replaceState) {
        try {
          history.replaceState(null, '', `admin_dashboard.php?section=${sectionId}`);
        } catch (err) {}
      }

      closeSettingsMenu();
      closeGlobalSearchDropdown();

      if (window.innerWidth <= 960) {
        closeSidebarMobile();
      }
    }

    // Synchronize user profile information and settings state
    function updateProfileSettingsVisibility(isProfileOpen) {
      const nameEl = document.getElementById('headerUserName');
      if (nameEl) {
        nameEl.textContent = <?= json_encode($user['name'] ?? 'Leah Dimaculangan') ?>;
      }
      const profNameEl = document.getElementById('profileFullName');
      if (profNameEl) {
        profNameEl.textContent = <?= json_encode($user['name'] ?? 'Leah Dimaculangan') ?>;
      }
      const dropNameEl = document.getElementById('profileDropdownName');
      if (dropNameEl) {
        dropNameEl.textContent = <?= json_encode($user['name'] ?? 'Leah Dimaculangan') ?>;
      }
    }

    // Settings Dropdown Popover Control (Upper-Right Corner)
    function toggleSettingsMenu(e) {
      if (e) {
        e.preventDefault();
        e.stopPropagation();
      }
      if (typeof closeNotificationPanel === 'function') {
        closeNotificationPanel();
      }
      closeGlobalSearchDropdown();
      const d = document.getElementById('settingsDropdown');
      if (d) d.classList.toggle('show');
    }

    function closeSettingsMenu() {
      const d = document.getElementById('settingsDropdown');
      if (d) d.classList.remove('show');
    }

    function closeGlobalSearchDropdown() {
      const dd = document.getElementById('globalSearchDropdown');
      if (dd) dd.classList.remove('open');
    }

    document.addEventListener('click', (e) => {
      if (!e.target.closest('#settingsToggleBtn') && 
          !e.target.closest('#settingsDropdown') && 
          !e.target.closest('#headerProfileTrigger') && 
          !e.target.closest('#headerAvatarBadge') &&
          !e.target.closest('#headerNotificationBtn') &&
          !e.target.closest('#notificationDropdown')) {
        closeSettingsMenu();
      }
      if (!e.target.closest('.top-nav-search-wrap')) {
        closeGlobalSearchDropdown();
      }
    });

    // Profile View & Internal Sub-Sidebar Navigation
    // Profile View & Internal Sub-Sidebar Navigation (Sidebar strictly remains open and persistent)
    function openProfileView(tab = 'accountInfo', updateUrl = true) {
      document.querySelectorAll('.app-view-section').forEach(s => s.classList.remove('active-section'));
      document.querySelectorAll('.sidebar-nav-item').forEach(b => b.classList.remove('active'));

      const profSec = document.getElementById('sectionProfile');
      if (profSec) profSec.classList.add('active-section');

      // Show Settings button along with user profile information
      updateProfileSettingsVisibility(true);

      switchProfileSubTab(tab, null, updateUrl);
      closeSettingsMenu();

      // Sidebar strictly remains open and visible
    }

    function switchProfileSubTab(tabName, clickedBtn = null, updateUrl = true) {
      document.querySelectorAll('.profile-tab-pane').forEach(p => p.classList.remove('active-pane'));
      document.querySelectorAll('.profile-subnav-item').forEach(b => b.classList.remove('active'));

      if (tabName === 'accountInfo') {
        const pane = document.getElementById('paneAccountInfo');
        if (pane) pane.classList.add('active-pane');
        const btn = clickedBtn || document.getElementById('adminSubnavAccount');
        if (btn) btn.classList.add('active');
      } else if (tabName === 'changePassword') {
        const pane = document.getElementById('paneChangePassword');
        if (pane) pane.classList.add('active-pane');
        const btn = clickedBtn || document.getElementById('adminSubnavPassword');
        if (btn) btn.classList.add('active');
      }

      if (updateUrl && window.history && window.history.replaceState) {
        history.replaceState(null, '', `admin_dashboard.php?section=sectionProfile&tab=${tabName}`);
      }
      if (window.lucide && typeof window.lucide.createIcons === 'function') {
        window.lucide.createIcons();
      }
    }

    // =========================================================================
    // PROFILE PICTURE CUSTOMIZATION (LANDLORD)
    // =========================================================================
    let currentSavedAvatarUrl = <?= json_encode(!empty($userAvatarUrl) ? $userAvatarUrl : '') ?>;
    let userDefaultInitial = <?= json_encode($userInitial) ?>;
    let pendingAvatarFile = null;

    function openAdminIdModal() {
      openModal('modalAdminProfileId');
    }

    function closeAdminIdModal(fromCloseModal = false) {
      const m = document.getElementById('modalAdminProfileId');
      if (m) {
        m.classList.add('hidden');
        m.classList.remove('open', 'active');
        m.style.display = 'none';
        m.style.pointerEvents = 'none';
        document.body.style.overflow = '';
      }
      if (!fromCloseModal && typeof closeModal === 'function') {
        closeModal('modalAdminProfileId');
      }
    }

    function previewProfilePicture(e) {
      const file = e.target.files && e.target.files[0];
      if (!file) return;

      const validTypes = ['image/jpeg', 'image/png', 'image/webp', 'image/jpg'];
      if (!validTypes.includes(file.type.toLowerCase()) && !file.type.startsWith('image/')) {
        showToast('Invalid format. Please choose a JPG, PNG, or WEBP image.', 'error');
        e.target.value = '';
        return;
      }

      if (file.size > 5 * 1024 * 1024) {
        showToast('Image file exceeds the 5MB size limit.', 'error');
        e.target.value = '';
        return;
      }

      pendingAvatarFile = file;

      const reader = new FileReader();
      reader.onload = function(evt) {
        const previewImg = document.getElementById('avatarPreviewImg');
        const fallbackInit = document.getElementById('avatarFallbackInitial');
        if (previewImg) {
          previewImg.src = evt.target.result;
          previewImg.style.display = 'block';
        }
        if (fallbackInit) {
          fallbackInit.style.display = 'none';
        }
        const pendingActions = document.getElementById('avatarPendingActions');
        if (pendingActions) pendingActions.style.display = 'inline-flex';
        const removeBtn = document.getElementById('btnRemoveAvatar');
        if (removeBtn) removeBtn.style.display = 'none';
        if (window.lucide && typeof window.lucide.createIcons === 'function') window.lucide.createIcons();
      };
      reader.readAsDataURL(file);
    }

    function triggerProfilePictureInput() {
      const input = document.getElementById('profile_picture') || document.getElementById('profilePictureInput');
      if (input) input.click();
    }

    function cancelProfilePictureSelection() {
      pendingAvatarFile = null;
      const input = document.getElementById('profile_picture') || document.getElementById('profilePictureInput');
      if (input) input.value = '';

      const previewImg = document.getElementById('avatarPreviewImg');
      const fallbackInit = document.getElementById('avatarFallbackInitial');
      const pendingActions = document.getElementById('avatarPendingActions');
      const removeBtn = document.getElementById('btnRemoveAvatar');

      if (pendingActions) pendingActions.style.display = 'none';

      if (currentSavedAvatarUrl) {
        if (previewImg) {
          previewImg.src = currentSavedAvatarUrl;
          previewImg.style.display = 'block';
        }
        if (fallbackInit) fallbackInit.style.display = 'none';
        if (removeBtn) removeBtn.style.display = 'inline-flex';
      } else {
        if (previewImg) {
          previewImg.src = '';
          previewImg.style.display = 'none';
        }
        if (fallbackInit) {
          fallbackInit.textContent = userDefaultInitial;
          fallbackInit.style.display = 'flex';
        }
        if (removeBtn) removeBtn.style.display = 'none';
      }
      if (window.lucide && typeof window.lucide.createIcons === 'function') window.lucide.createIcons();
    }

    function submitProfilePictureUpload() {
      const input = document.getElementById('profile_picture') || document.getElementById('profilePictureInput');
      const file = pendingAvatarFile || (input && input.files && input.files[0]);
      if (!file) {
        showToast('Please select a photo first.', 'warning');
        return;
      }

      const saveBtn = document.getElementById('btnSaveAvatar');
      if (saveBtn) {
        saveBtn.disabled = true;
        saveBtn.innerHTML = 'Saving...';
      }

      const form = document.getElementById('profilePictureForm');
      const formData = new FormData(form || undefined);
      formData.set('action', 'upload_profile_picture');
      formData.set('profile_picture', file);
      formData.set('is_ajax', '1');

      fetch('api/profile_api.php', {
        method: 'POST',
        body: formData
      })
      .then(res => res.json())
      .then(data => {
        if (saveBtn) {
          saveBtn.disabled = false;
          saveBtn.innerHTML = '<i data-lucide="check" style="width: 15px; height: 15px;"></i> Save Changes';
          if (window.lucide && typeof window.lucide.createIcons === 'function') window.lucide.createIcons();
        }

        if (data.status === 'success') {
          currentSavedAvatarUrl = data.profile_picture;
          pendingAvatarFile = null;
          if (input) input.value = '';

          const pendingActions = document.getElementById('avatarPendingActions');
          if (pendingActions) pendingActions.style.display = 'none';

          const removeBtn = document.getElementById('btnRemoveAvatar');
          if (removeBtn) removeBtn.style.display = 'inline-flex';

          const stamp = Date.now();
          const avatarUrlWithCache = data.profile_picture + '?t=' + stamp;

          // Update header avatar badge
          const headerBadge = document.getElementById('headerAvatarBadge');
          if (headerBadge) {
            headerBadge.innerHTML = `<img src="${avatarUrlWithCache}" alt="Profile" class="header-avatar-img">`;
          }

          // Update profile dropdown avatar
          const dropCircle = document.querySelector('.fb-profile-avatar-circle');
          if (dropCircle) {
            dropCircle.innerHTML = `<img src="${avatarUrlWithCache}" alt="Profile">`;
          }

          // Update settings preview
          const previewImg = document.getElementById('avatarPreviewImg');
          if (previewImg) {
            previewImg.src = avatarUrlWithCache;
            previewImg.style.display = 'block';
          }
          const fallbackInit = document.getElementById('avatarFallbackInitial');
          if (fallbackInit) {
            fallbackInit.style.setProperty('display', 'none', 'important');
            fallbackInit.textContent = '';
          }

          if (window.lucide && typeof window.lucide.createIcons === 'function') window.lucide.createIcons();
          showToast('Profile picture updated successfully!', 'success');
        } else {
          showToast(data.message || 'Failed to update profile picture.', 'error');
        }
      })
      .catch(err => {
        if (saveBtn) {
          saveBtn.disabled = false;
          saveBtn.innerHTML = '<i data-lucide="check" style="width: 15px; height: 15px;"></i> Save Changes';
          if (window.lucide && typeof window.lucide.createIcons === 'function') window.lucide.createIcons();
        }
        showToast('Network error uploading profile picture.', 'error');
      });
    }

    function confirmRemoveProfilePicture() {
      if (!confirm('Are you sure you want to remove your profile picture and revert to the default circular initial icon?')) {
        return;
      }

      const removeBtn = document.getElementById('btnRemoveAvatar');
      if (removeBtn) removeBtn.disabled = true;

      fetch('api/profile_api.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ action: 'remove_profile_picture' })
      })
      .then(res => res.json())
      .then(data => {
        if (removeBtn) removeBtn.disabled = false;

        if (data.status === 'success') {
          currentSavedAvatarUrl = '';
          pendingAvatarFile = null;
          userDefaultInitial = data.initial || userDefaultInitial;

          const input = document.getElementById('profile_picture');
          if (input) input.value = '';

          const pendingActions = document.getElementById('avatarPendingActions');
          if (pendingActions) pendingActions.style.display = 'none';

          if (removeBtn) removeBtn.style.display = 'none';

          // Update settings preview
          const previewImg = document.getElementById('avatarPreviewImg');
          if (previewImg) {
            previewImg.src = '';
            previewImg.style.display = 'none';
          }
          const fallbackInit = document.getElementById('avatarFallbackInitial');
          if (fallbackInit) {
            fallbackInit.textContent = userDefaultInitial;
            fallbackInit.style.display = 'flex';
          }

          // Update header avatar badge
          const headerBadge = document.getElementById('headerAvatarBadge');
          if (headerBadge) {
            headerBadge.innerHTML = `<span class="header-avatar-initial">${userDefaultInitial}</span>`;
          }

          // Update profile dropdown avatar
          const dropCircle = document.querySelector('.fb-profile-avatar-circle');
          if (dropCircle) {
            dropCircle.innerHTML = `<div style="width: 44px; height: 44px; border-radius: 50%; background: #7c3aed; color: #fff; display: flex; align-items: center; justify-content: center; font-weight: bold; font-size: 18px;">${userDefaultInitial}</div>`;
          }

          if (window.lucide && typeof window.lucide.createIcons === 'function') window.lucide.createIcons();
          showToast('Profile picture removed.', 'success');
        } else {
          showToast(data.message || 'Failed to remove profile picture.', 'error');
        }
      })
      .catch(err => {
        if (removeBtn) removeBtn.disabled = false;
        showToast('Network error removing profile picture.', 'error');
      });
    }

    // Landlord Approves or Rejects Tenant ID Document
    async function reviewTenantId(tenantId, decision) {
      const confirmMsg = decision === 'verified' 
        ? 'Approve and mark this tenant ID as verified?' 
        : 'Reject this tenant ID submission?';
      if (!confirm(confirmMsg)) return;

      try {
        const res = await fetch('api/tenant_directory_api.php', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({
            action: 'verify_tenant_id',
            tenant_id: tenantId,
            decision: decision
          })
        });
        const result = await res.json();
        if (res.ok && result.status === 'success') {
          showToast(result.message);
          setTimeout(() => {
            window.location.href = window.location.href;
          }, 1000);
        } else {
          showToast(result.message || 'Error updating ID verification', 'error');
        }
      } catch (err) {
        showToast('Network error reviewing ID', 'error');
      }
    }

    document.getElementById('changePasswordForm').addEventListener('submit', async (e) => {
      e.preventDefault();
      const currentPassword = document.getElementById('profCurrentPass').value;
      const newPassword = document.getElementById('profNewPass').value;
      const confirmPassword = document.getElementById('profConfirmPass').value;

      if (newPassword !== confirmPassword) {
        showToast('New passwords do not match.', 'error');
        return;
      }

      if (newPassword.length < 6) {
        showToast('Password must be at least 6 characters.', 'error');
        return;
      }

      const btn = document.getElementById('btnSavePassword');
      btn.disabled = true;
      btn.textContent = 'Updating...';

      try {
        const res = await fetch('api/profile_api.php', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({
            action: 'change_password',
            current_password: currentPassword,
            new_password: newPassword,
            confirm_password: confirmPassword
          })
        });

        const data = await res.json();
        if (res.ok && data.status === 'success') {
          showToast(data.message, 'success');
          document.getElementById('changePasswordForm').reset();
          // Universal Contextual Routing: Stay on Profile -> Change Password tab
          setTimeout(() => {
            window.location.href = 'admin_dashboard.php?section=sectionProfile&tab=changePassword';
          }, 1000);
        } else {
          showToast(data.message || 'Error updating password', 'error');
        }
      } catch (err) {
        showToast('Network error updating password', 'error');
      } finally {
        btn.disabled = false;
        btn.textContent = 'Update Password';
      }
    });

    // =========================================================================
    // TENANT DIRECTORY: UNIT CARDS GRID & REAL-TIME PAYMENT SETTLEMENT STATUS
    // =========================================================================
    window.directoryUnitsData = <?= json_encode(array_values($unitsWithTenants), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    let isDirectoryUnitsFetching = false;

    function formatBathCount(val) {
      const n = parseFloat(val);
      if (Number.isNaN(n) || n <= 0) return '1';
      return Number.isInteger(n) ? String(n) : String(n).replace(/\.0+$/, '');
    }

    function resolveTenantAvatarSrc(t) {
      if (!t) return '';
      if (Object.prototype.hasOwnProperty.call(t, 'avatar_url')) {
        return String(t.avatar_url || '').trim();
      }
      const raw = String(t.profile_picture || '').trim();
      if (!raw) return '';
      if (raw.startsWith('http://') || raw.startsWith('https://') || raw.startsWith('uploads/')) {
        return raw;
      }
      return '';
    }

    function renderDirectoryUnitsGrid(units = window.directoryUnitsData) {
      const gridEl = document.getElementById('directoryUnitsGrid');
      const counterEl = document.getElementById('directoryActiveUnitsCounter');
      const tabBadgeEl = document.getElementById('directoryActiveCountBadge');
      const list = Array.isArray(units) ? units : [];

      const activeUnitsCount = list.filter(u => Array.isArray(u.tenants) && u.tenants.length > 0).length;
      if (counterEl) {
        counterEl.textContent = `${activeUnitsCount} active unit${activeUnitsCount === 1 ? '' : 's'}`;
      }
      if (tabBadgeEl) {
        tabBadgeEl.textContent = String(activeUnitsCount);
      }

      if (!gridEl) return;

      gridEl.innerHTML = list.map(u => {
        const uId = parseInt(u.unit_id || u.id, 10);
        const tenants = Array.isArray(u.tenants) ? u.tenants : [];
        const isOccupied = tenants.length > 0;
        const primary = isOccupied ? tenants[0] : null;
        const isPaidUp = isOccupied ? !!(typeof u.is_paid_up === 'boolean' ? u.is_paid_up : (primary && primary.is_paid_up)) : false;
        const cardClass = isOccupied ? 'unit-dir-card--occupied' : 'unit-dir-card--available';
        const pillClass = !isOccupied
          ? 'unit-dir-status-pill--available'
          : (isPaidUp ? 'unit-dir-status-pill--paid' : 'unit-dir-status-pill--unpaid');
        const pillText = isOccupied ? 'OCCUPIED' : 'AVAILABLE';
        const cardState = !isOccupied ? 'available' : (isPaidUp ? 'occupied_paid' : 'occupied_unpaid');
        const brCount = parseInt(u.bedrooms, 10) || 1;
        const crCount = formatBathCount(u.bathrooms);
        const unitLabel = String(u.unit_number || ('Unit ' + uId)).toUpperCase();
        const isMovedOutPending = !!(primary && (primary.rental_status || 'active') === 'moved_out');
        const primaryAvatar = primary ? resolveTenantAvatarSrc(primary) : '';
        const primaryInitial = primary ? ((String(primary.name || 'T').trim().charAt(0) || 'T').toUpperCase()) : 'T';
        const primaryUsername = primary && primary.username ? String(primary.username).trim().toUpperCase() : '';

        const avatarInnerHtml = primaryAvatar
          ? `<img src="${escapeHtml(primaryAvatar)}" alt="${escapeHtml(String(primary.name || 'Tenant'))}" style="width: 100%; height: 100%; border-radius: 50%; object-fit: cover; display: block;" onerror="this.style.display='none';if(this.nextElementSibling)this.nextElementSibling.style.display='flex';"><span style="display: none; width: 100%; height: 100%; align-items: center; justify-content: center; font-weight: 900; font-size: 13px; color: #1E160E;">${escapeHtml(primaryInitial)}</span>`
          : `<span style="font-weight: 900; font-size: 13px; color: #1E160E;">${escapeHtml(primaryInitial)}</span>`;

        return `
          <article class="unit-dir-card ${cardClass}"
                   id="unit-directory-card-${uId}"
                   data-unit-id="${uId}"
                   data-card-state="${cardState}"
                   tabindex="0"
                   role="button"
                   aria-label="${escapeHtml(String(u.unit_number || 'Unit'))} - ${pillText}"
                   onclick="handleUnitCardClick(${uId})"
                   onkeydown="if(event.key==='Enter'||event.key===' '){event.preventDefault();handleUnitCardClick(${uId});}">
            <div class="unit-dir-header">
              <h2 class="unit-dir-title">${escapeHtml(unitLabel)}</h2>
              <div class="unit-dir-specs">${brCount} BR | ${escapeHtml(crCount)} CR</div>
              ${isOccupied && primary ? `
                <div style="display: flex; align-items: center; gap: 10px; margin-top: 2px;">
                  <div style="width: 34px; height: 34px; border-radius: 50%; overflow: hidden; flex-shrink: 0; background: #FAF4EC; border: 2px solid rgba(255, 255, 255, 0.78); display: flex; align-items: center; justify-content: center; box-shadow: 0 2px 6px rgba(34, 23, 9, 0.22);">
                    ${avatarInnerHtml}
                  </div>
                  <div style="min-width: 0;">
                    <div class="unit-dir-occupant" style="padding-left: 0;">OCCUPANT: ${escapeHtml(String(primary.name || 'Tenant').toUpperCase())}</div>
                    ${primaryUsername ? `<div style="font-family: ui-monospace, 'SFMono-Regular', Menlo, Consolas, monospace; font-size: 11px; font-weight: 700; color: rgba(246, 241, 229, 0.82); letter-spacing: 0.04em;">${escapeHtml(primaryUsername)}</div>` : ''}
                  </div>
                </div>
                ${isMovedOutPending ? '<span class="unit-dir-movedout-tag">Moved Out (Pending Settlement)</span>' : ''}
              ` : ''}
            </div>
            ${isOccupied ? '<div class="unit-dir-divider"></div>' : ''}
            <div class="unit-dir-footer">
              <span class="unit-dir-status-pill ${pillClass}" id="unit-folder-badge-${uId}">${pillText}</span>
            </div>
          </article>
        `;
      }).join('');
    }

    async function fetchActiveDirectoryUnits() {
      if (isDirectoryUnitsFetching) return;
      isDirectoryUnitsFetching = true;
      try {
        const res = await fetch('api/tenant_directory_api.php?action=list_active', { cache: 'no-store' });
        if (!res.ok) return;
        const data = await res.json();
        if (data && data.status === 'success' && Array.isArray(data.units)) {
          window.directoryUnitsData = data.units;
          if (Array.isArray(data.occupied_unit_ids)) {
            occupiedUnitIds = data.occupied_unit_ids.map(id => parseInt(id, 10));
          }
          const flatTenants = [];
          data.units.forEach(u => {
            (u.tenants || []).forEach(t => {
              flatTenants.push({ ...t, unit_id: u.unit_id || u.id, unit_number: u.unit_number });
            });
          });
          if (typeof OWNER_SEARCH_TENANTS !== 'undefined' && Array.isArray(OWNER_SEARCH_TENANTS)) {
            OWNER_SEARCH_TENANTS.splice(0, OWNER_SEARCH_TENANTS.length, ...flatTenants);
          }
          renderDirectoryUnitsGrid(window.directoryUnitsData);
        }
      } catch (err) {
      } finally {
        isDirectoryUnitsFetching = false;
      }
    }

    function handleUnitCardClick(unitId) {
      const uId = parseInt(unitId, 10);
      const unitObj = (window.directoryUnitsData || []).find(u => parseInt(u.unit_id || u.id, 10) === uId);
      if (unitObj && Array.isArray(unitObj.tenants) && unitObj.tenants.length > 0) {
        const primaryTenant = unitObj.tenants[0];
        openTenantDetailsModal(primaryTenant, unitObj.unit_number || UNIT_LABELS[String(uId)] || '', uId);
      } else {
        openAddTenantModal(uId);
      }
    }

    function openUnitFolder(unitId) {
      const uId = parseInt(unitId, 10);
      if (typeof switchDirectoryTab === 'function') {
        switchDirectoryTab('active');
      }
      document.querySelectorAll('.unit-dir-card--highlighted').forEach(el => el.classList.remove('unit-dir-card--highlighted'));
      const cardEl = document.getElementById(`unit-directory-card-${uId}`);
      if (cardEl) {
        cardEl.classList.add('unit-dir-card--highlighted');
        cardEl.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
      }
      try {
        history.replaceState(null, '', `admin_dashboard.php?section=sectionDirectory&unit_id=${uId}`);
      } catch (err) {}
    }

    function closeUnitFolder() {
      document.querySelectorAll('.unit-dir-card--highlighted').forEach(el => el.classList.remove('unit-dir-card--highlighted'));
      try {
        history.replaceState(null, '', `admin_dashboard.php?section=sectionDirectory`);
      } catch (err) {}
    }

    // List of occupied unit IDs for strict 1 account per unit limit
    let occupiedUnitIds = <?= json_encode(array_values(array_map(fn($u) => (int)$u['id'], array_filter($unitsWithTenants, fn($u) => !empty($u['tenants']))))) ?>;
    const UNIT_LABELS = <?= json_encode(array_column($units, 'unit_number', 'id'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    let isSubmittingTenant = false;

    function resetAddTenantModalState() {
      isSubmittingTenant = false;
      const submitBtn = document.getElementById('btnSubmitCreateTenant');
      const cancelBtn = document.getElementById('btnCancelCreateTenant');
      const statusContainer = document.getElementById('tenantCreationStatus');
      const loadingEl = document.getElementById('tenantCreationLoading');
      const successEl = document.getElementById('tenantCreationSuccess');
      const errorEl = document.getElementById('tenantCreationError');

      if (submitBtn) {
        submitBtn.disabled = false;
        submitBtn.classList.remove('btn-loading-state');
        submitBtn.innerHTML = 'REGISTER';
      }
      if (cancelBtn) {
        cancelBtn.disabled = false;
      }
      if (statusContainer) statusContainer.style.display = 'none';
      if (loadingEl) loadingEl.style.display = 'flex';
      if (successEl) successEl.style.display = 'none';
      if (errorEl) errorEl.style.display = 'none';

      const moveInInput = document.getElementById('newMoveInDate');
      if (moveInInput) {
        const todayStr = getManilaTodayStr();
        moveInInput.min = todayStr;
        moveInInput.value = todayStr;
      }

      document.querySelectorAll('#addTenantModal .resident-reg-space-checkbox').forEach((cb) => {
        if (!cb.disabled) {
          cb.checked = false;
        }
      });
      updateRegistrationRentPreview();
    }

    // =========================================================================
    // CUSTOM CLAYMORPHIC CALENDAR (ASIA/MANILA TIMEZONE & RESTRICTIONS)
    // =========================================================================
    function getManilaDateDetails() {
      const formatter = new Intl.DateTimeFormat('en-US', {
        timeZone: 'Asia/Manila',
        year: 'numeric',
        month: 'numeric',
        day: 'numeric'
      });
      const parts = formatter.formatToParts(new Date());
      let year = 2026, month = 10, day = 31;
      for (const p of parts) {
        if (p.type === 'year') year = parseInt(p.value, 10);
        if (p.type === 'month') month = parseInt(p.value, 10);
        if (p.type === 'day') day = parseInt(p.value, 10);
      }
      const yyyy = year;
      const mm = String(month).padStart(2, '0');
      const dd = String(day).padStart(2, '0');
      return {
        year,
        month, // 1..12
        day,
        isoString: `${yyyy}-${mm}-${dd}`
      };
    }

    function getManilaTodayStr() {
      return getManilaDateDetails().isoString;
    }

    function initMoveInDatePicker() {
      const moveInInput = document.getElementById('newMoveInDate');
      if (moveInInput) {
        const todayStr = getManilaTodayStr();
        moveInInput.min = todayStr;
        if (!moveInInput.value || moveInInput.value < todayStr) {
          moveInInput.value = todayStr;
        }
      }
    }

    // Modal Control
    function openAddTenantModal(preselectedUnitId = null) {
      const sel = document.getElementById('newUnitId');
      if (preselectedUnitId) {
        const uId = parseInt(preselectedUnitId, 10);
        if (occupiedUnitIds.includes(uId)) {
          showToast("This unit is already occupied and has an existing tenant account. Only one tenant account is allowed per unit.", "error");
          return;
        }
        if (sel) sel.value = preselectedUnitId;
      } else if (sel && sel.options.length > 0) {
        const currentOpt = sel.options[sel.selectedIndex];
        if (!currentOpt || currentOpt.disabled) {
          const firstAvail = Array.from(sel.options).find(opt => !opt.disabled);
          if (firstAvail) sel.value = firstAvail.value;
        }
      }
      resetAddTenantModalState();
      initMoveInDatePicker();
      calculateTotalOccupants();
      updateRegistrationRentPreview();
      openModal('addTenantModal');
    }

    function closeAddTenantModal(fromCloseModal = false) {
      if (isSubmittingTenant && !fromCloseModal) return; // Prevent closing during active processing
      const modal = document.getElementById('addTenantModal');
      if (modal) {
        modal.classList.add('hidden');
        modal.classList.remove('open', 'active');
        modal.style.display = 'none';
        modal.style.pointerEvents = 'none';
        document.body.style.overflow = '';
      }
      resetAddTenantModalState();
      if (!fromCloseModal && typeof closeModal === 'function') {
        closeModal('addTenantModal');
      }
    }

    // Auto-Calculate Occupants (Vanilla JS)
    function calculateTotalOccupants() {
      const adultsInput = document.getElementById('newAdultsCount');
      const kidsInput = document.getElementById('newKidsCount');
      const totalInput = document.getElementById('newTotalOccupants');
      if (!totalInput) return;

      const adultsVal = adultsInput ? parseInt(adultsInput.value, 10) : 0;
      const kidsVal = kidsInput ? parseInt(kidsInput.value, 10) : 0;
      const adults = isNaN(adultsVal) || adultsVal < 0 ? 0 : adultsVal;
      const kids = isNaN(kidsVal) || kidsVal < 0 ? 0 : kidsVal;
      const total = adults + kids;
      totalInput.value = total > 0 ? total : 1;
    }

    // Real-time Calculation for Additional Space Allocation & Total Monthly Rent Preview
    function formatPesoAmount(amount) {
      const safeNum = Number.isFinite(amount) ? amount : 0;
      return safeNum.toLocaleString('en-PH', {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2
      });
    }

    function updateRegistrationRentPreview() {
      const unitSelect = document.getElementById('newUnitId');
      const extraDisplay = document.getElementById('newAdditionalRentDisplay');
      const totalDisplay = document.getElementById('newTotalMonthlyRentDisplay');

      let baseRent = 0;
      if (unitSelect && unitSelect.selectedIndex >= 0) {
        const selectedOpt = unitSelect.options[unitSelect.selectedIndex];
        if (selectedOpt) {
          const parsedBase = parseFloat(selectedOpt.dataset.monthlyRent || '0');
          if (!isNaN(parsedBase) && parsedBase > 0) {
            baseRent = parsedBase;
          }
        }
      }

      let additionalRent = 0;
      document.querySelectorAll('#addTenantModal .resident-reg-space-checkbox').forEach((cb) => {
        if (cb.checked && !cb.disabled) {
          const spaceFee = parseFloat(cb.dataset.fee || '1500');
          additionalRent += (!isNaN(spaceFee) && spaceFee > 0) ? spaceFee : 1500;
        }
      });

      const totalMonthlyRent = baseRent + additionalRent;
      if (extraDisplay) {
        extraDisplay.textContent = '₱' + formatPesoAmount(additionalRent);
      }
      if (totalDisplay) {
        totalDisplay.textContent = '₱' + formatPesoAmount(totalMonthlyRent);
      }
    }

    function markRegisteredSpacesOccupied(spaceNumbers = [], spaceIds = []) {
      const numSet = new Set((spaceNumbers || []).map(n => parseInt(n, 10)));
      const idSet = new Set((spaceIds || []).map(id => parseInt(id, 10)));
      document.querySelectorAll('#addTenantModal .resident-reg-space-checkbox').forEach((cb) => {
        const cbNum = parseInt(cb.dataset.spaceNumber || '0', 10);
        const cbId = parseInt(cb.dataset.spaceId || cb.value || '0', 10);
        if (numSet.has(cbNum) || idSet.has(cbId)) {
          cb.checked = false;
          cb.disabled = true;
          cb.setAttribute('aria-disabled', 'true');
          const label = cb.closest('.resident-reg-space-item');
          if (label) {
            label.classList.add('is-occupied');
            if (!label.querySelector('.resident-reg-occupied-tag')) {
              const badge = document.createElement('span');
              badge.className = 'resident-reg-occupied-tag';
              badge.textContent = 'Occupied';
              label.appendChild(badge);
            }
          }
        }
      });
      updateRegistrationRentPreview();
    }

    // Attach Vanilla JS event listeners for real-time recalculation
    const adultsEl = document.getElementById('newAdultsCount');
    const kidsEl = document.getElementById('newKidsCount');
    if (adultsEl) {
      adultsEl.addEventListener('input', calculateTotalOccupants);
      adultsEl.addEventListener('change', calculateTotalOccupants);
      adultsEl.addEventListener('keyup', calculateTotalOccupants);
    }
    if (kidsEl) {
      kidsEl.addEventListener('input', calculateTotalOccupants);
      kidsEl.addEventListener('change', calculateTotalOccupants);
      kidsEl.addEventListener('keyup', calculateTotalOccupants);
    }

    const regUnitSelectEl = document.getElementById('newUnitId');
    if (regUnitSelectEl) {
      regUnitSelectEl.addEventListener('change', updateRegistrationRentPreview);
    }
    document.querySelectorAll('#addTenantModal .resident-reg-space-checkbox').forEach((cb) => {
      cb.addEventListener('change', updateRegistrationRentPreview);
    });

    // Handle Tenant Creation with Auto-Generated Credentials & Animated Loading State
    let currentGeneratedCreds = null;
    document.getElementById('createTenantForm').addEventListener('submit', async (e) => {
      e.preventDefault();
      if (isSubmittingTenant) return;

      const unitId = parseInt(document.getElementById('newUnitId').value, 10);
      if (occupiedUnitIds.includes(unitId)) {
        showToast("This unit is already occupied and has an existing tenant account. Only one tenant account is allowed per unit.", "error");
        return;
      }

      const moveInVal = document.getElementById('newMoveInDate') ? document.getElementById('newMoveInDate').value.trim() : '';
      if (!moveInVal) {
        showToast("Date of Moving In is required.", "error");
        return;
      }

      const manilaToday = getManilaTodayStr();
      if (moveInVal < manilaToday) {
        showToast("Invalid moving-in date. Please select today or a future date.", "error");
        resetAddTenantModalState();
        return;
      }

      // 1. Immediately disable buttons and display loading animation
      isSubmittingTenant = true;
      const submitBtn = document.getElementById('btnSubmitCreateTenant');
      const cancelBtn = document.getElementById('btnCancelCreateTenant');
      const statusContainer = document.getElementById('tenantCreationStatus');
      const loadingEl = document.getElementById('tenantCreationLoading');
      const successEl = document.getElementById('tenantCreationSuccess');
      const errorEl = document.getElementById('tenantCreationError');

      if (submitBtn) {
        submitBtn.disabled = true;
        submitBtn.classList.add('btn-loading-state');
        submitBtn.innerHTML = '<span class="clay-spinner" style="width: 15px; height: 15px; border-width: 2px; margin-right: 8px; vertical-align: middle;"></span>Creating Account...';
      }
      if (cancelBtn) {
        cancelBtn.disabled = true;
      }

      if (statusContainer) {
        statusContainer.style.display = 'block';
        statusContainer.style.background = '#eef2ff';
        statusContainer.style.border = '1px solid #c7d2fe';
      }
      if (loadingEl) loadingEl.style.display = 'flex';
      if (successEl) successEl.style.display = 'none';
      if (errorEl) errorEl.style.display = 'none';

      const firstNameVal = document.getElementById('newFirstName') ? document.getElementById('newFirstName').value.trim() : '';
      const middleNameVal = document.getElementById('newMiddleName') ? document.getElementById('newMiddleName').value.trim() : '';
      const lastNameVal = document.getElementById('newLastName') ? document.getElementById('newLastName').value.trim() : '';
      const fullNameVal = [firstNameVal, middleNameVal, lastNameVal].filter(Boolean).join(' ');

      if (!firstNameVal || !lastNameVal) {
        isSubmittingTenant = false;
        if (submitBtn) {
          submitBtn.disabled = false;
          submitBtn.classList.remove('btn-loading-state');
          submitBtn.innerHTML = 'REGISTER';
        }
        if (cancelBtn) cancelBtn.disabled = false;
        if (statusContainer) statusContainer.style.display = 'none';
        showToast("First Name and Last Name are required.", "error");
        return;
      }

      const space1Cb = document.getElementById('newSpace1');
      const space2Cb = document.getElementById('newSpace2');
      const selectedSpaceIds = [];
      document.querySelectorAll('#addTenantModal .resident-reg-space-checkbox').forEach((cb) => {
        if (cb.checked && !cb.disabled) {
          const parsedId = parseInt(cb.dataset.spaceId || cb.value || '0', 10);
          if (!isNaN(parsedId) && parsedId > 0) {
            selectedSpaceIds.push(parsedId);
          }
        }
      });

      const payload = {
        action: 'create',
        unit_id: unitId,
        name: fullNameVal,
        first_name: firstNameVal,
        middle_name: middleNameVal,
        last_name: lastNameVal,
        move_in_date: moveInVal,
        birthday: document.getElementById('newBirthday').value,
        email: document.getElementById('newEmail').value.trim(),
        phone: document.getElementById('newPhone').value.trim(),
        emergency_contact_name: document.getElementById('newEmergencyName').value.trim(),
        emergency_contact_relationship: document.getElementById('newEmergencyRel').value.trim(),
        emergency_contact_phone: document.getElementById('newEmergencyPhone').value.trim(),
        total_occupants: parseInt(document.getElementById('newTotalOccupants').value, 10) || 1,
        adults_count: parseInt(document.getElementById('newAdultsCount').value, 10) || 1,
        kids_count: parseInt(document.getElementById('newKidsCount').value, 10) || 0,
        space_1: Boolean(space1Cb && space1Cb.checked && !space1Cb.disabled),
        space_2: Boolean(space2Cb && space2Cb.checked && !space2Cb.disabled),
        additional_space_ids: selectedSpaceIds
      };

      try {
        const res = await fetch('api/tenant_directory_api.php', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify(payload)
        });

        if (res.status === 403) {
          throw new Error('Your session has expired. Please refresh the page and sign in as Admin again.');
        }
        if (res.status === 504 || res.status === 408) {
          throw new Error('The request timed out. Please check your network and Gmail SMTP connection.');
        }

        let result = null;
        const resText = await res.text();
        try {
          result = JSON.parse(resText);
        } catch (jsonErr) {
          const cleanErr = resText.replace(/<[^>]+>/g, ' ').replace(/\s+/g, ' ').trim();
          throw new Error(cleanErr.substring(0, 150) || 'Server returned invalid response format.');
        }

        if (res.ok && result.status === 'success') {
          markRegisteredSpacesOccupied(
            result.assigned_space_numbers || [],
            result.assigned_space_ids || selectedSpaceIds
          );

          // Smooth transition: hide spinner, display success message
          if (loadingEl) loadingEl.style.display = 'none';
          if (successEl) {
            successEl.style.display = 'flex';
            const sText = document.getElementById('tenantCreationSuccessText');
            if (sText) {
              sText.textContent = result.message || "Tenant account created successfully! Account details have been sent to the tenant's email address.";
            }
          }
          if (statusContainer) {
            statusContainer.style.background = '#ecfdf5';
            statusContainer.style.border = '1px solid #a7f3d0';
          }
          showToast(result.message || "Tenant account created successfully! Account details have been sent to the tenant's email address.", "success");

          // Transition delay so user experiences the success feedback
          setTimeout(() => {
            isSubmittingTenant = false;
            closeAddTenantModal();
            document.getElementById('createTenantForm').reset();
            calculateTotalOccupants();
            updateRegistrationRentPreview();

            if (result.credentials) {
              result.credentials.unit_id = unitId;
              showCredentialsModal(result.credentials);
            } else {
              window.location.href = `admin_dashboard.php?section=sectionDirectory&unit_id=${unitId}`;
            }
          }, 900);

        } else {
          // Re-enable and show error feedback
          isSubmittingTenant = false;
          if (submitBtn) {
            submitBtn.disabled = false;
            submitBtn.classList.remove('btn-loading-state');
            submitBtn.innerHTML = 'REGISTER';
          }
          if (cancelBtn) {
            cancelBtn.disabled = false;
          }
          if (loadingEl) loadingEl.style.display = 'none';
          if (errorEl) {
            errorEl.style.display = 'flex';
            const eText = document.getElementById('tenantCreationErrorText');
            if (eText) {
              eText.textContent = result.message || 'Error creating tenant account.';
            }
          }
          if (statusContainer) {
            statusContainer.style.background = '#fef2f2';
            statusContainer.style.border = '1px solid #fecaca';
          }
          showToast(result.message || 'Error creating tenant', 'error');
        }
      } catch (err) {
        console.error('[Tenant Creation Network/Server Error]:', err);
        isSubmittingTenant = false;
        if (submitBtn) {
          submitBtn.disabled = false;
          submitBtn.classList.remove('btn-loading-state');
          submitBtn.innerHTML = 'REGISTER';
        }
        if (cancelBtn) {
          cancelBtn.disabled = false;
        }
        if (loadingEl) loadingEl.style.display = 'none';
        if (errorEl) {
          errorEl.style.display = 'flex';
          const eText = document.getElementById('tenantCreationErrorText');
          if (eText) {
            let userMsg = err.message || 'Network or server error creating tenant account.';
            if (typeof navigator !== 'undefined' && navigator.onLine === false) {
              userMsg = 'Network disconnected. Please check your internet connection and try again.';
            } else if (userMsg.toLowerCase().includes('failed to fetch') || userMsg.toLowerCase().includes('networkerror') || userMsg.toLowerCase().includes('load failed')) {
              userMsg = 'Cannot reach server. Please ensure XAMPP (Apache and MySQL) is active and your network connection is stable.';
            }
            eText.textContent = userMsg;
          }
        }
        if (statusContainer) {
          statusContainer.style.background = '#fef2f2';
          statusContainer.style.border = '1px solid #fecaca';
        }
        showToast(err.message || 'Network error creating tenant', 'error');
      }
    });

    function showCredentialsModal(creds) {
      currentGeneratedCreds = creds;
      document.getElementById('credTenantName').textContent = creds.name;
      document.getElementById('credUnitNumber').textContent = creds.unit_number;
      document.getElementById('credUsername').textContent = creds.username;
      document.getElementById('credPassword').textContent = creds.password;
      openModal('tenantCredentialsModal');
    }

    function closeCredentialsModalAndReload() {
      const modal = document.getElementById('tenantCredentialsModal');
      if (modal) {
        modal.classList.add('hidden');
        modal.classList.remove('open', 'active');
        modal.style.display = 'none';
        modal.style.pointerEvents = 'none';
        document.body.style.overflow = '';
      }
      const uId = currentGeneratedCreds && currentGeneratedCreds.unit_id ? currentGeneratedCreds.unit_id : null;
      if (uId) {
        window.location.href = `admin_dashboard.php?section=sectionDirectory&unit_id=${uId}`;
      } else {
        window.location.href = `admin_dashboard.php?section=sectionDirectory`;
      }
    }

    function copyCredField(elemId, btn) {
      const val = document.getElementById(elemId).textContent;
      navigator.clipboard.writeText(val).then(() => {
        const orig = btn.innerHTML;
        btn.innerHTML = `<span style="display:inline-flex;align-items:center;gap:4px;">${window.rcIcon('check', 'w-3.5 h-3.5')} Copied!</span>`;
        setTimeout(() => btn.innerHTML = orig, 1800);
        showToast('Copied: ' + val, 'success');
      });
    }

    function copyAllCredentials(btn) {
      if (!currentGeneratedCreds) return;
      const text = `TaraRent Resident Account\nResident: ${currentGeneratedCreds.name}\nUnit: ${currentGeneratedCreds.unit_number}\nUsername: ${currentGeneratedCreds.username}\nPassword: ${currentGeneratedCreds.password}`;
      navigator.clipboard.writeText(text).then(() => {
        const orig = btn.innerHTML;
        btn.innerHTML = `<span style="display:inline-flex;align-items:center;gap:6px;">${window.rcIcon('check', 'w-4 h-4')} All Copied!</span>`;
        setTimeout(() => btn.innerHTML = orig, 1800);
        showToast('All login details copied to clipboard!', 'success');
      });
    }

    // =========================================================================
    // EXPANDED TENANT DETAILS & DIRECT ID PREVIEW (LANDLORD UI)
    // - Immediately displays uploaded Valid ID image without extra clicks.
    // =========================================================================
    // MODAL CONTROLLERS & DATA BINDING: TENANT DETAILS & DIRECTORY
    // Rebuilt to strictly match image_56da2c.png layout and styling.
    // - Header: UNIT 1 with 1 BR | 1 CR and OCCUPIED badge.
    // - Top Card: Tenant Information (Full width, brown bg, divider, emergency contact, move-in date).
    // - Bottom Cards: Tenant Identification Information & Billing Information.
    // =========================================================================
    window.currentActiveTenantIdPhoto = null;
    window.currentActiveTenantReceiptPhoto = null;

    function viewTenantIdImage() {
      if (!window.currentActiveTenantIdPhoto || window.currentActiveTenantIdPhoto.trim() === '') {
        showToast('Identification document is locked until account verification is complete.', 'warning');
        return;
      }
      const m = document.getElementById('viewIdImageModal');
      const img = document.getElementById('viewIdModalImg');
      const empty = document.getElementById('viewIdModalEmpty');
      if (!m || !img) return;
      img.src = 'uploads/id_verifications/' + encodeURIComponent(window.currentActiveTenantIdPhoto);
      img.style.display = 'block';
      if (empty) empty.style.display = 'none';
      openModal('viewIdImageModal');
      if (window.lucide && typeof window.lucide.createIcons === 'function') window.lucide.createIcons();
    }

    function viewTenantReceiptPhoto() {
      const m = document.getElementById('viewReceiptImageModal');
      const img = document.getElementById('viewReceiptModalImg');
      const empty = document.getElementById('viewReceiptModalEmpty');
      if (!m || !img) return;
      if (window.currentActiveTenantReceiptPhoto && window.currentActiveTenantReceiptPhoto.trim() !== '') {
        img.src = 'uploads/receipts/' + encodeURIComponent(window.currentActiveTenantReceiptPhoto);
        img.style.display = 'block';
        if (empty) empty.style.display = 'none';
      } else {
        img.src = '';
        img.style.display = 'none';
        if (empty) empty.style.display = 'block';
      }
      openModal('viewReceiptImageModal');
      if (window.lucide && typeof window.lucide.createIcons === 'function') window.lucide.createIcons();
    }

    function openTenantDetailsModal(t, unitNumber, unitId) {
      if (!t) return;

      // Cache files for lightbox viewers
      window.currentActiveTenantIdPhoto = t.id_photo_filename || null;
      window.currentActiveTenantReceiptPhoto = null;

      // 1. Resolve Unit information (Unit Number, Subunit 1 BR | 1 CR, Occupancy)
      const uId = parseInt(unitId || (t ? t.unit_id : 0), 10);
      const unitObj = (window.directoryUnitsData || []).find(u => parseInt(u.unit_id || u.id, 10) === uId);

      const uNumRaw = (unitObj && unitObj.unit_number) ? unitObj.unit_number : (unitNumber || 'UNIT ' + (uId || '1'));
      const unitHeading = uNumRaw.toLowerCase().startsWith('unit') ? uNumRaw.toUpperCase() : ('UNIT ' + uNumRaw.toUpperCase());
      const uNumEl = document.getElementById('tdModalUnitNumber');
      if (uNumEl) uNumEl.textContent = unitHeading;

      const br = (unitObj && unitObj.bedrooms) ? unitObj.bedrooms : 1;
      const cr = (unitObj && unitObj.bathrooms) ? Math.round(unitObj.bathrooms) : 1;
      const uDetEl = document.getElementById('tdModalUnitDetails');
      if (uDetEl) uDetEl.textContent = `${br} BR | ${cr} CR`;

      // Occupancy Badge
      const isMovedOut = (t.rental_status === 'moved_out');
      const occBadge = document.getElementById('tdModalOccupancyBadge');
      if (occBadge) {
        occBadge.style.backgroundColor = '';
        occBadge.style.color = '';
        if (!isMovedOut) {
          occBadge.textContent = 'OCCUPIED';
          occBadge.className = 'td-badge-pill td-badge-success';
        } else {
          occBadge.textContent = 'MOVED OUT';
          occBadge.className = 'td-badge-pill td-badge-warning';
        }
      }

      // 2. Tenant Information (Profile, Contact, Emergency, Move-in Date)
      // Format Date Helper (MM/DD/YYYY)
      const fmtDate = (dStr) => {
        if (!dStr) return '-';
        const d = new Date(dStr.includes('T') ? dStr : dStr + 'T00:00:00');
        if (isNaN(d.getTime())) return dStr;
        const mm = String(d.getMonth() + 1).padStart(2, '0');
        const dd = String(d.getDate()).padStart(2, '0');
        const yyyy = d.getFullYear();
        return `${mm}/${dd}/${yyyy}`;
      };

      // Format Short Date (MM/DD/YY)
      const fmtShortDate = (dStr) => {
        if (!dStr) return '--/--/--';
        const d = new Date(dStr.includes('T') ? dStr : dStr + 'T00:00:00');
        if (isNaN(d.getTime())) return dStr;
        const mm = String(d.getMonth() + 1).padStart(2, '0');
        const dd = String(d.getDate()).padStart(2, '0');
        const yy = String(d.getFullYear()).slice(-2);
        return `${mm}/${dd}/${yy}`;
      };

      const syncModalTenantProfile = (profile) => {
        if (!profile) return;

        // Assigned unit & occupancy binding
        const pUnitId = parseInt(profile.unit_id || uId || 0, 10);
        const pUnitNum = profile.unit_number || (unitObj && unitObj.unit_number) || unitNumber || ('UNIT ' + (pUnitId || '1'));
        const pUnitHeading = pUnitNum.toLowerCase().startsWith('unit') ? pUnitNum.toUpperCase() : ('UNIT ' + pUnitNum.toUpperCase());
        if (uNumEl) uNumEl.textContent = pUnitHeading;
        const pBr = parseInt(profile.bedrooms || (unitObj && unitObj.bedrooms) || 1, 10) || 1;
        const pCr = formatBathCount(profile.bathrooms ?? (unitObj && unitObj.bathrooms) ?? 1);
        if (uDetEl) uDetEl.textContent = `${pBr} BR | ${pCr} CR`;

        const pMovedOut = (profile.rental_status === 'moved_out');
        if (occBadge) {
          occBadge.style.backgroundColor = '';
          occBadge.style.color = '';
          if (!pMovedOut) {
            occBadge.textContent = 'OCCUPIED';
            occBadge.className = 'td-badge-pill td-badge-success';
          } else {
            occBadge.textContent = 'MOVED OUT';
            occBadge.className = 'td-badge-pill td-badge-warning';
          }
        }

        // Name & Username/Code
        const tNameEl = document.getElementById('tdModalTenantName');
        if (tNameEl) tNameEl.textContent = (profile.name || 'Resident').toUpperCase();

        const usernameEl = document.getElementById('tdModalUsername');
        const uname = String(profile.username || '').trim();
        if (usernameEl) {
          if (uname) {
            usernameEl.textContent = uname.toUpperCase();
            usernameEl.style.display = 'inline-block';
          } else {
            usernameEl.textContent = '';
            usernameEl.style.display = 'none';
          }
        }

        // Circular Avatar Photo or Fallback Initial
        const avatarImg = document.getElementById('tdModalAvatarImg');
        const avatarInit = document.getElementById('tdModalAvatarInitial');
        const initialChar = (String(profile.name || 'T').trim().charAt(0) || 'T').toUpperCase();
        if (avatarInit) avatarInit.textContent = initialChar;

        const avatarSrc = resolveTenantAvatarSrc(profile);
        if (avatarImg && avatarInit) {
          if (avatarSrc) {
            avatarImg.onerror = () => {
              avatarImg.style.display = 'none';
              avatarInit.style.display = '';
            };
            avatarImg.alt = profile.name ? `${profile.name} profile photo` : 'Tenant profile photo';
            avatarImg.src = avatarSrc;
            avatarImg.style.display = 'block';
            avatarInit.style.display = 'none';
          } else {
            avatarImg.onerror = null;
            avatarImg.removeAttribute('src');
            avatarImg.style.display = 'none';
            avatarInit.style.display = '';
          }
        } else if (avatarInit) {
          avatarInit.textContent = initialChar;
        }

        // Contact, Birthday, Move-in & Emergency Info
        const moveInDateEl = document.getElementById('tdModalMoveInDate');
        if (moveInDateEl) moveInDateEl.textContent = fmtDate(profile.move_in_date || profile.created_at);

        const bdayEl = document.getElementById('tdModalBirthday');
        if (bdayEl) bdayEl.textContent = profile.birthday ? fmtDate(profile.birthday) : 'N/A';

        const phoneEl = document.getElementById('tdModalPhone');
        if (phoneEl) phoneEl.textContent = profile.phone || 'N/A';

        const emailEl = document.getElementById('tdModalEmail');
        if (emailEl) emailEl.textContent = profile.email || 'N/A';

        const emNameEl = document.getElementById('tdModalEmergencyName');
        if (emNameEl) emNameEl.textContent = profile.emergency_contact_name || profile.emergency_contact || 'None listed';

        const emRelEl = document.getElementById('tdModalEmergencyRel');
        if (emRelEl) emRelEl.textContent = profile.emergency_contact_relationship || 'N/A';

        const emPhoneEl = document.getElementById('tdModalEmergencyPhone');
        if (emPhoneEl) emPhoneEl.textContent = profile.emergency_contact_phone || 'N/A';
      };

      syncModalTenantProfile(t);

      // 3. Tenant Identification Information & Privacy Masking
      const isVerified = (parseInt(t.is_verified, 10) === 1) && ((t.verification_status || '').toLowerCase() === 'verified');
      const vStatus = (t.verification_status || 'unverified').toLowerCase();
      
      const idStatusBadge = document.getElementById('tdModalIdStatusBadge');
      const idDetailsContainer = document.getElementById('tdModalIdDetailsContainer');

      if (idStatusBadge) {
        idStatusBadge.style.backgroundColor = '';
        idStatusBadge.style.color = '';
        if (isVerified) {
          idStatusBadge.textContent = 'VERIFIED';
          idStatusBadge.className = 'td-badge-pill td-badge-success';
        } else if (vStatus === 'pending' || vStatus === 'pending_liveness') {
          idStatusBadge.textContent = 'PENDING';
          idStatusBadge.className = 'td-badge-pill td-badge-warning';
        } else {
          idStatusBadge.textContent = 'UNVERIFIED';
          idStatusBadge.className = 'td-badge-pill td-badge-danger';
        }
      }

      if (idDetailsContainer) {
        if (isVerified && t.id_type && t.id_photo_filename) {
          // Verified: Display ID Icon, ID Type text, and clickable [view id] link
          idDetailsContainer.innerHTML = `
            <div id="tdModalVerifiedIdSection" class="td-verified-id-box">
              <svg class="w-16 h-12 flex-shrink-0 drop-shadow-sm" viewBox="0 0 72 52" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true" style="width: 60px; height: 44px; flex-shrink: 0;">
                <rect x="2" y="2" width="68" height="48" rx="8" fill="#F8E5E5" stroke="#1A120B" stroke-width="2.5"/>
                <rect x="2" y="2" width="68" height="14" rx="8" fill="#F47272" stroke="#1A120B" stroke-width="2.5"/>
                <rect x="8" y="20" width="20" height="24" rx="4" fill="#FFFFFF" stroke="#1A120B" stroke-width="2"/>
                <circle cx="18" cy="27" r="3.5" fill="#1A120B"/>
                <path d="M11 40c0-3.5 3-5 7-5s7 1.5 7 5" stroke="#1A120B" stroke-width="2" stroke-linecap="round" fill="#1A120B"/>
                <text x="33" y="28" font-family="sans-serif" font-weight="900" font-size="9" fill="#1A120B">ID</text>
                <line x1="45" y1="26" x2="62" y2="26" stroke="#1A120B" stroke-width="2" stroke-linecap="round"/>
                <line x1="33" y1="34" x2="62" y2="34" stroke="#1A120B" stroke-width="2" stroke-linecap="round"/>
                <line x1="33" y1="40" x2="54" y2="40" stroke="#1A120B" stroke-width="2" stroke-linecap="round"/>
              </svg>
              <div>
                <div id="tdModalIdType" class="td-id-type-title">
                  ${t.id_type.toUpperCase()}
                </div>
                <button type="button" 
                        id="tdModalBtnViewId" 
                        onclick="viewTenantIdImage()" 
                        class="td-btn-view-id">
                  <i data-lucide="eye" style="width: 13px; height: 13px;" aria-hidden="true"></i>
                  <span>[view id]</span>
                </button>
              </div>
            </div>
          `;
          window.currentActiveTenantIdPhoto = t.id_photo_filename;
          if (window.lucide && typeof window.lucide.createIcons === 'function') window.lucide.createIcons();
        } else {
          // Unverified / Pending Fallback: Grayed-out placeholder, NO <a> tags, NO triggers
          const placeholderText = (vStatus === 'pending' || vStatus === 'pending_liveness')
            ? 'ID Hidden (Pending Verification)'
            : 'ID Hidden (Unverified Account)';
          idDetailsContainer.innerHTML = `
            <div id="tdModalUnverifiedIdSection" class="td-id-locked-box">
              <div style="display: flex; align-items: center; justify-content: center; font-size: 14px; font-weight: 800; color: #3A2C1D;">
                <span id="tdModalHiddenIdPlaceholder">${placeholderText}</span>
              </div>
              <p style="font-size: 11.5px; color: #5C462D; margin: 4px 0 0 0; font-weight: 600;">
                Identification documents are locked until account verification is complete.
              </p>
            </div>
          `;
          window.currentActiveTenantIdPhoto = null;
        }
      }

      // 4. Billing Information (Initial Fallbacks)
      const baseRent = parseFloat(t.monthly_rent || (unitObj ? unitObj.monthly_rent : 3000)) || 3000;
      const depLabel = document.getElementById('tdModalDepositLabel');
      if (depLabel) depLabel.textContent = `DEPOSIT: PHP ${Math.round(baseRent)}`;

      const depBadge = document.getElementById('tdModalDepositStatusBadge');
      if (depBadge) {
        depBadge.textContent = 'DONE';
        depBadge.className = 'td-badge-pill td-badge-success';
        depBadge.style.backgroundColor = '';
        depBadge.style.color = '';
      }

      const payBadge = document.getElementById('tdModalPaymentStatusBadge');
      if (payBadge) {
        payBadge.textContent = (t.is_paid_up ? 'PAID' : 'PENDING');
        payBadge.className = t.is_paid_up ? 'td-badge-pill td-badge-success' : 'td-badge-pill td-badge-warning';
        payBadge.style.backgroundColor = '';
        payBadge.style.color = '';
      }

      const dueEl = document.getElementById('tdModalDueDate');
      if (dueEl) dueEl.textContent = fmtShortDate(new Date().toISOString());

      const paidEl = document.getElementById('tdModalPaidOnDate');
      if (paidEl) paidEl.textContent = t.is_paid_up ? fmtShortDate(new Date().toISOString()) : '--/--/--';

      const totEl = document.getElementById('tdModalTotalAmount');
      if (totEl) totEl.textContent = `PHP ${baseRent.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;

      // 5. Asynchronously Fetch Real-Time Billing & Receipt Data
      if (t.id) {
        fetch(`api/tenant_directory_api.php?action=get_tenant_billing&tenant_id=${encodeURIComponent(t.id)}`)
          .then(res => res.json())
          .then(data => {
            if (data && data.status === 'success') {
              if (data.tenant) {
                // Re-bind live tenant account profile (avatar, name, username, contact, birthday, unit)
                syncModalTenantProfile(data.tenant);
                if (Array.isArray(window.directoryUnitsData)) {
                  let directoryNeedsRedraw = false;
                  window.directoryUnitsData.forEach(u => {
                    if (Array.isArray(u.tenants)) {
                      u.tenants = u.tenants.map(cached => {
                        if (parseInt(cached.id, 10) === parseInt(data.tenant.id, 10)) {
                          if (cached.name !== data.tenant.name || cached.avatar_url !== data.tenant.avatar_url || cached.username !== data.tenant.username) {
                            directoryNeedsRedraw = true;
                          }
                          return { ...cached, ...data.tenant };
                        }
                        return cached;
                      });
                    }
                  });
                  if (directoryNeedsRedraw) {
                    renderDirectoryUnitsGrid(window.directoryUnitsData);
                  }
                }
                const liveVerified = (parseInt(data.tenant.is_verified, 10) === 1) && ((data.tenant.verification_status || '').toLowerCase() === 'verified');
                if (liveVerified && data.tenant.id_photo_filename && data.tenant.id_type) {
                  window.currentActiveTenantIdPhoto = data.tenant.id_photo_filename;
                  if (idDetailsContainer) {
                    idDetailsContainer.innerHTML = `
                      <div id="tdModalVerifiedIdSection" class="td-verified-id-box">
                        <svg class="w-16 h-12 flex-shrink-0 drop-shadow-sm" viewBox="0 0 72 52" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true" style="width: 60px; height: 44px; flex-shrink: 0;">
                          <rect x="2" y="2" width="68" height="48" rx="8" fill="#F8E5E5" stroke="#1A120B" stroke-width="2.5"/>
                          <rect x="2" y="2" width="68" height="14" rx="8" fill="#F47272" stroke="#1A120B" stroke-width="2.5"/>
                          <rect x="8" y="20" width="20" height="24" rx="4" fill="#FFFFFF" stroke="#1A120B" stroke-width="2"/>
                          <circle cx="18" cy="27" r="3.5" fill="#1A120B"/>
                          <path d="M11 40c0-3.5 3-5 7-5s7 1.5 7 5" stroke="#1A120B" stroke-width="2" stroke-linecap="round" fill="#1A120B"/>
                          <text x="33" y="28" font-family="sans-serif" font-weight="900" font-size="9" fill="#1A120B">ID</text>
                          <line x1="45" y1="26" x2="62" y2="26" stroke="#1A120B" stroke-width="2" stroke-linecap="round"/>
                          <line x1="33" y1="34" x2="62" y2="34" stroke="#1A120B" stroke-width="2" stroke-linecap="round"/>
                          <line x1="33" y1="40" x2="54" y2="40" stroke="#1A120B" stroke-width="2" stroke-linecap="round"/>
                        </svg>
                        <div>
                          <div id="tdModalIdType" class="td-id-type-title">
                            ${data.tenant.id_type.toUpperCase()}
                          </div>
                          <button type="button" 
                                  id="tdModalBtnViewId" 
                                  onclick="viewTenantIdImage()" 
                                  class="td-btn-view-id">
                            <i data-lucide="eye" style="width: 13px; height: 13px;" aria-hidden="true"></i>
                            <span>[view id]</span>
                          </button>
                        </div>
                      </div>
                    `;
                    if (window.lucide && typeof window.lucide.createIcons === 'function') window.lucide.createIcons();
                  }
                  if (idStatusBadge) {
                    idStatusBadge.textContent = 'VERIFIED';
                    idStatusBadge.className = 'td-badge-pill td-badge-success';
                    idStatusBadge.style.backgroundColor = '';
                    idStatusBadge.style.color = '';
                  }
                } else {
                  window.currentActiveTenantIdPhoto = null;
                  const liveVStatus = (data.tenant.verification_status || 'unverified').toLowerCase();
                  if (idDetailsContainer) {
                    const placeholderText = (liveVStatus === 'pending' || liveVStatus === 'pending_liveness')
                      ? 'ID Hidden (Pending Verification)'
                      : 'ID Hidden (Unverified Account)';
                    idDetailsContainer.innerHTML = `
                      <div id="tdModalUnverifiedIdSection" class="td-id-locked-box">
                        <div style="display: flex; align-items: center; justify-content: center; font-size: 14px; font-weight: 800; color: #3A2C1D;">
                          <span id="tdModalHiddenIdPlaceholder">${placeholderText}</span>
                        </div>
                        <p style="font-size: 11.5px; color: #5C462D; margin: 4px 0 0 0; font-weight: 600;">
                          Identification documents are locked until account verification is complete.
                        </p>
                      </div>
                    `;
                  }
                  if (idStatusBadge) {
                    idStatusBadge.style.backgroundColor = '';
                    idStatusBadge.style.color = '';
                    if (liveVStatus === 'pending' || liveVStatus === 'pending_liveness') {
                      idStatusBadge.textContent = 'PENDING';
                      idStatusBadge.className = 'td-badge-pill td-badge-warning';
                    } else {
                      idStatusBadge.textContent = 'UNVERIFIED';
                      idStatusBadge.className = 'td-badge-pill td-badge-danger';
                    }
                  }
                }
              }

              // Latest Rent Billing
              if (data.latest_rent) {
                const lr = data.latest_rent;
                const rDue = parseFloat(lr.amount_due) || baseRent;
                const rPaid = parseFloat(lr.amount_paid) || 0;
                const isPaid = (lr.status === 'approved' || (rDue > 0 && rPaid >= rDue));
                const isPending = (lr.status === 'pending');

                if (payBadge) {
                  payBadge.style.backgroundColor = '';
                  payBadge.style.color = '';
                  if (isPaid) {
                    payBadge.textContent = 'PAID';
                    payBadge.className = 'td-badge-pill td-badge-success';
                  } else if (isPending) {
                    payBadge.textContent = 'PENDING';
                    payBadge.className = 'td-badge-pill td-badge-warning';
                  } else {
                    payBadge.textContent = 'UNPAID';
                    payBadge.className = 'td-badge-pill td-badge-danger';
                  }
                }

                if (dueEl && lr.due_date) dueEl.textContent = fmtShortDate(lr.due_date);
                if (paidEl) paidEl.textContent = lr.payment_date ? fmtShortDate(lr.payment_date) : (isPaid ? fmtShortDate(lr.due_date) : '--/--/--');
                if (totEl) totEl.textContent = `PHP ${rDue.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
                if (lr.receipt_filename) {
                  window.currentActiveTenantReceiptPhoto = lr.receipt_filename;
                }
              }

              // Deposit Status
              if (data.latest_deposit) {
                const ld = data.latest_deposit;
                const depAmt = parseFloat(ld.amount_due || ld.amount_paid) || baseRent;
                if (depLabel) depLabel.textContent = `DEPOSIT: PHP ${Math.round(depAmt)}`;
                if (depBadge) {
                  const isDepDone = (ld.status === 'approved' || parseFloat(ld.amount_paid) >= depAmt);
                  depBadge.textContent = isDepDone ? 'DONE' : (ld.status === 'pending' ? 'PENDING' : 'UNPAID');
                  depBadge.className = isDepDone ? 'td-badge-pill td-badge-success' : (ld.status === 'pending' ? 'td-badge-pill td-badge-warning' : 'td-badge-pill td-badge-danger');
                  depBadge.style.backgroundColor = '';
                  depBadge.style.color = '';
                }
                if (!window.currentActiveTenantReceiptPhoto && ld.receipt_filename) {
                  window.currentActiveTenantReceiptPhoto = ld.receipt_filename;
                }
              }
            }
          })
          .catch(err => console.warn('Could not load tenant billing details:', err));
      }

      // Bind Move-Out / Settlement confirmation button
      const delBtn = document.getElementById('tdBtnDelete');
      if (delBtn) {
        delBtn.innerHTML = isMovedOut
          ? `<span style="display:inline-flex;align-items:center;gap:6px;">${window.rcIcon('shield-check', 'w-4 h-4')} Verify Settlement &amp; Archive</span>`
          : `<span style="display:inline-flex;align-items:center;gap:6px;">${window.rcIcon('log-out', 'w-4 h-4')} Mark as Moved Out</span>`;
        delBtn.onclick = () => openMoveOutConfirmModal({
          tenantId: parseInt(t.id, 10),
          tenantName: t.name || 'Tenant',
          username: t.username || '',
          unitNumber: uNumRaw,
          unitId: uId,
          moveInDate: t.move_in_date || '',
          alreadyMovedOut: isMovedOut
        });
      }

      openModal('tenantDetailsModal');
      if (window.lucide && typeof window.lucide.createIcons === 'function') {
        window.lucide.createIcons();
      }
    }

    function closeTenantDetailsModal(fromCloseModal = false) {
      const modal = document.getElementById('tenantDetailsModal');
      if (modal) {
        modal.classList.add('hidden');
        modal.classList.remove('open', 'active');
        modal.style.display = 'none';
        modal.style.pointerEvents = 'none';
        document.body.style.overflow = '';
      }
      if (!fromCloseModal && typeof closeModal === 'function') {
        closeModal('tenantDetailsModal');
      }
    }

    // Dynamic UI counter & unit card sync for tenant directory
    function updateUnitTenantCount(unitId) {
      const uIdNum = parseInt(unitId, 10);
      const unitLabel = UNIT_LABELS[String(unitId)] || `Unit ${unitId}`;
      let remaining = 0;

      if (Array.isArray(window.directoryUnitsData)) {
        const unitObj = window.directoryUnitsData.find(u => parseInt(u.id, 10) === uIdNum);
        if (unitObj && Array.isArray(unitObj.tenants)) {
          remaining = unitObj.tenants.filter(t => (t.rental_status || 'active') !== 'archived').length;
          unitObj.is_occupied = remaining > 0;
          if (!unitObj.is_occupied) {
            unitObj.is_paid_up = false;
            unitObj.card_state = 'available';
          }
        }
        renderDirectoryUnitsGrid(window.directoryUnitsData);
      }

      if (remaining === 0) {
        // Release occupancy status for this unit
        occupiedUnitIds = occupiedUnitIds.filter(id => id !== uIdNum);

        // Enable unit in addTenantModal select dropdown
        const selectEl = document.getElementById('newUnitId');
        if (selectEl) {
          const opt = selectEl.querySelector(`option[value="${unitId}"]`);
          if (opt) {
            opt.disabled = false;
            opt.style.color = '';
            opt.textContent = `${unitLabel} (Available)`;
          }
        }
      }
    }

    // =========================================================================
    // CONTEXTUAL ROUTING: TENANT DELETION
    // When the landlord deletes a tenant account, the system refreshes or stays
    // exactly where they are (inside the specific unit folder).
    // It strictly DOES NOT redirect them back to the main Landlord Dashboard.
    // =========================================================================
    let isDeletingTenant = false;
    let isSubmittingOwnerDelete = false;
    let pendingOwnerDeleteContext = null;

    function clearOwnerDeletePasswordError() {
      const errEl = document.getElementById('ownerDeleteErrorAlert');
      if (errEl) {
        errEl.style.display = 'none';
        errEl.textContent = '';
      }
    }

    function toggleOwnerDeletePasswordVisibility() {
      const pwInput = document.getElementById('ownerDeletePasswordInput');
      const toggleBtn = document.getElementById('btnToggleOwnerDeletePass');
      if (!pwInput || !toggleBtn) return;

      if (pwInput.type === 'password') {
        pwInput.type = 'text';
        toggleBtn.innerHTML = window.rcIcon('eye-off', 'w-4 h-4');
        toggleBtn.setAttribute('aria-label', 'Hide password');
      } else {
        pwInput.type = 'password';
        toggleBtn.innerHTML = window.rcIcon('eye', 'w-4 h-4');
        toggleBtn.setAttribute('aria-label', 'Show password');
      }
    }

    function openOwnerDeleteConfirmModal(context) {
      if (isSubmittingOwnerDelete) return;
      pendingOwnerDeleteContext = context;

      const modal = document.getElementById('ownerDeleteConfirmModal');
      const titleEl = document.getElementById('ownerDeleteModalTitle');
      const targetNameEl = document.getElementById('ownerDeleteTargetName');
      const targetMetaEl = document.getElementById('ownerDeleteTargetMeta');
      const warningEl = document.getElementById('ownerDeleteWarningText');
      const pwInput = document.getElementById('ownerDeletePasswordInput');
      const toggleBtn = document.getElementById('btnToggleOwnerDeletePass');
      const confirmBtn = document.getElementById('btnConfirmOwnerDelete');

      if (!modal || !pwInput) return;

      pwInput.value = '';
      pwInput.type = 'password';
      if (toggleBtn) {
        toggleBtn.innerHTML = window.rcIcon('eye', 'w-4 h-4');
        toggleBtn.setAttribute('aria-label', 'Show password');
      }
      clearOwnerDeletePasswordError();

      const safeName = context.tenantName || 'Selected Tenant';
      if (targetNameEl) targetNameEl.textContent = safeName;

      if (context.mode === 'permanent_delete') {
        if (titleEl) titleEl.textContent = 'Permanently Delete Tenant Account';
        if (targetMetaEl) targetMetaEl.textContent = `Archived • Tenant ID #${context.tenantId}`;
        if (warningEl) {
          warningEl.textContent = `WARNING: Permanently deleting "${safeName}" will erase their account, payment records, maintenance tickets, and notifications forever. This action is permanent and cannot be undone.`;
        }
        if (confirmBtn) confirmBtn.innerHTML = `<span style="display:inline-flex;align-items:center;gap:6px;">${window.rcIcon('trash-2', 'w-4 h-4')} Confirm Delete</span>`;
      } else {
        const rawUnit = context.unitNumber ? String(context.unitNumber).trim() : '';
        const unitLabel = rawUnit
          ? (/^unit\b/i.test(rawUnit) ? rawUnit : `Unit ${rawUnit}`)
          : 'Assigned Unit';
        if (titleEl) titleEl.textContent = context.alreadyMovedOut ? 'Verify Settlement & Archive Tenant' : 'Delete / Move Out Tenant Account';
        if (targetMetaEl) targetMetaEl.textContent = `${unitLabel} • Tenant ID #${context.tenantId}`;
        if (warningEl) {
          warningEl.textContent = context.alreadyMovedOut
            ? `Permanent Action Warning: Confirming will verify bill settlement and archive "${safeName}" out of active tenancy. Please enter your owner password to continue.`
            : `Permanent Action Warning: Removing or marking "${safeName}" as Moved Out permanently closes their active tenancy (and archives settled records). Enter your owner password to continue.`;
        }
        if (confirmBtn) confirmBtn.innerHTML = `<span style="display:inline-flex;align-items:center;gap:6px;">${window.rcIcon('trash-2', 'w-4 h-4')} Confirm Delete</span>`;
      }

      openModal('ownerDeleteConfirmModal');
      setTimeout(() => {
        if (pwInput) pwInput.focus();
      }, 120);
    }

    function closeOwnerDeleteConfirmModal(force = false) {
      if (isSubmittingOwnerDelete && !force) return;
      const modal = document.getElementById('ownerDeleteConfirmModal');
      const pwInput = document.getElementById('ownerDeletePasswordInput');
      const toggleBtn = document.getElementById('btnToggleOwnerDeletePass');

      if (pwInput) {
        pwInput.value = '';
        pwInput.type = 'password';
      }
      if (toggleBtn) {
        toggleBtn.innerHTML = window.rcIcon('eye', 'w-4 h-4');
        toggleBtn.setAttribute('aria-label', 'Show password');
      }
      clearOwnerDeletePasswordError();
      pendingOwnerDeleteContext = null;

      if (modal) {
        modal.classList.add('hidden');
        modal.classList.remove('open', 'active');
        modal.style.display = 'none';
        modal.style.pointerEvents = 'none';
        document.body.style.overflow = '';
      }
      if (!force && typeof closeModal === 'function') {
        closeModal('ownerDeleteConfirmModal');
      }
    }

    function handleOwnerDeleteBackdropClick(event) {
      if (event.target === document.getElementById('ownerDeleteConfirmModal')) {
        closeOwnerDeleteConfirmModal();
      }
    }

    async function executeVerifiedTenantDeletion() {
      if (isSubmittingOwnerDelete || !pendingOwnerDeleteContext) return;

      const pwInput = document.getElementById('ownerDeletePasswordInput');
      const errEl = document.getElementById('ownerDeleteErrorAlert');
      const confirmBtn = document.getElementById('btnConfirmOwnerDelete');
      const cancelBtn = document.getElementById('btnCancelOwnerDelete');

      const ownerPassword = pwInput ? pwInput.value : '';
      if (!ownerPassword) {
        if (errEl) {
          errEl.textContent = 'Please enter your current owner account password to confirm deletion.';
          errEl.style.display = 'block';
        }
        if (pwInput) pwInput.focus();
        return;
      }

      // Immediately clear plaintext input after reading into transient local variable
      if (pwInput) pwInput.value = '';
      clearOwnerDeletePasswordError();

      isSubmittingOwnerDelete = true;
      const origConfirmHtml = confirmBtn ? confirmBtn.innerHTML : `<span style="display:inline-flex;align-items:center;gap:6px;">${window.rcIcon('trash-2', 'w-4 h-4')} Confirm Delete</span>`;
      if (confirmBtn) {
        confirmBtn.disabled = true;
        confirmBtn.style.opacity = '0.75';
        confirmBtn.style.cursor = 'not-allowed';
        confirmBtn.innerHTML = '<span class="clay-spinner" style="width: 14px; height: 14px; border-width: 2px; vertical-align: middle; margin-right: 6px;"></span><span>Verifying...</span>';
      }
      if (cancelBtn) cancelBtn.disabled = true;

      let resultOutcome = { ok: false, message: 'Unable to complete deletion.' };
      const ctx = pendingOwnerDeleteContext;

      try {
        if (ctx.mode === 'permanent_delete') {
          resultOutcome = await performPermanentDeleteArchived(ctx.tenantId, ctx.tenantName, ownerPassword);
        } else {
          resultOutcome = await performTenantMoveOutDelete(ctx.tenantId, ctx.tenantName, ctx.unitNumber, ctx.unitId, ctx.alreadyMovedOut, ownerPassword);
        }
      } finally {
        isSubmittingOwnerDelete = false;
        if (confirmBtn) {
          confirmBtn.disabled = false;
          confirmBtn.style.opacity = '1';
          confirmBtn.style.cursor = 'pointer';
          confirmBtn.innerHTML = origConfirmHtml;
        }
        if (cancelBtn) cancelBtn.disabled = false;
      }

      if (resultOutcome && resultOutcome.ok) {
        closeOwnerDeleteConfirmModal(true);
      } else {
        if (errEl) {
          errEl.textContent = (resultOutcome && resultOutcome.message) ? resultOutcome.message : 'Incorrect password or deletion failed.';
          errEl.style.display = 'block';
        }
        if (pwInput) pwInput.focus();
      }
    }

    let isSubmittingMoveOut = false;
    let activeMoveOutContext = null;

    function deleteTenantAccount(tenantId, tenantName, unitNumber, unitId = null, alreadyMovedOut = false) {
      openMoveOutConfirmModal({
        tenantId: parseInt(tenantId, 10),
        tenantName: tenantName || 'Tenant',
        username: '',
        unitNumber: unitNumber || UNIT_LABELS[String(unitId)] || 'Assigned Unit',
        unitId: unitId,
        moveInDate: '',
        alreadyMovedOut: !!alreadyMovedOut
      });
    }

    function formatDisplayDate(dateStr) {
      if (!dateStr) return 'Not recorded';
      const parsed = new Date(dateStr + 'T00:00:00');
      if (isNaN(parsed.getTime())) return dateStr;
      return parsed.toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' });
    }

    function renderMoveOutSettlementAudit(settlement) {
      const unpaidEl = document.getElementById('moUnpaidCount');
      const pendingEl = document.getElementById('moPendingCount');
      const balanceEl = document.getElementById('moOutstandingBalance');
      const listWrap = document.getElementById('moUnsettledListWrap');
      const listEl = document.getElementById('moUnsettledList');
      const bannerEl = document.getElementById('moWarningBanner');
      const iconEl = document.getElementById('moWarningIcon');
      const textEl = document.getElementById('moWarningText');
      const confirmBtn = document.getElementById('btnConfirmMoveOut');

      const pendingCount = parseInt(settlement?.pending_count ?? 0, 10) || 0;
      const unpaidCount = parseInt(settlement?.unpaid_count ?? 0, 10) || 0;
      const balanceFormatted = settlement?.unpaid_balance_formatted || '₱0.00';
      const isSettled = !!(settlement && settlement.is_settled);
      const items = Array.isArray(settlement?.unsettled_items) ? settlement.unsettled_items : [];

      if (unpaidEl) {
        unpaidEl.textContent = String(unpaidCount);
        unpaidEl.style.color = unpaidCount > 0 ? '#be123c' : '#047857';
      }
      if (pendingEl) {
        pendingEl.textContent = String(pendingCount);
        pendingEl.style.color = pendingCount > 0 ? '#b45309' : '#047857';
      }
      if (balanceEl) {
        balanceEl.textContent = balanceFormatted;
        balanceEl.style.color = parseFloat(settlement?.unpaid_balance ?? 0) > 0.01 ? '#be123c' : '#047857';
      }

      if (listWrap && listEl) {
        if (items.length > 0) {
          listWrap.style.display = 'block';
          listEl.innerHTML = items.map(item => {
            const st = String(item.status || 'unpaid');
            const badgeStyle = st === 'pending'
              ? 'background: #fef3c7; color: #92400e;'
              : 'background: #fee2e2; color: #991b1b;';
            const cycleLabel = item.billing_cycle_formatted && item.billing_cycle_formatted !== '—'
              ? `${item.payment_type || 'Rent'} · ${item.billing_cycle_formatted}`
              : (item.payment_type || 'Billing Item');
            return `
              <div style="display: flex; align-items: center; justify-content: space-between; gap: 10px; padding: 9px 12px; border-bottom: 1px solid #f1f5f9;">
                <div>
                  <div style="font-weight: 700; color: #0f172a;">${escapeHtml(cycleLabel)}</div>
                  <div style="font-size: 11px; color: var(--text-muted);">Due: ${escapeHtml(item.due_date_formatted || '—')}</div>
                </div>
                <div style="text-align: right;">
                  <div style="font-weight: 800; color: #9f1239;">${escapeHtml(item.balance_formatted || item.amount_due_formatted || '₱0.00')}</div>
                  <span class="clay-badge" style="font-size: 10px; padding: 2px 7px; ${badgeStyle}">${escapeHtml(item.status_label || st)}</span>
                </div>
              </div>
            `;
          }).join('');
        } else {
          listWrap.style.display = 'none';
          listEl.innerHTML = '';
        }
      }

      if (bannerEl && iconEl && textEl) {
        if (isSettled) {
          bannerEl.style.background = '#ecfdf5';
          bannerEl.style.border = '1.5px solid #a7f3d0';
          bannerEl.style.color = '#065f46';
          iconEl.innerHTML = window.rcIcon('check-circle-2', 'w-4 h-4');
          textEl.textContent = 'All rental bills and payment receipts are settled and verified. Confirming will record the official move-out date and automatically transfer all tenant records to Archived Tenants.';
        } else {
          bannerEl.style.background = '#fff1f2';
          bannerEl.style.border = '1.5px solid #fecdd3';
          bannerEl.style.color = '#881337';
          iconEl.innerHTML = window.rcIcon('alert-triangle', 'w-4 h-4');
          textEl.textContent = `Cannot archive yet: ${settlement?.reason_text || 'Outstanding or pending payments remain'}. Owner confirmation cannot override unpaid balances or unverified receipts. Confirming will mark Moved Out only while keeping records active until settled.`;
        }
      }

      if (confirmBtn && !isSubmittingMoveOut) {
        confirmBtn.innerHTML = isSettled
          ? `<span style="display:inline-flex;align-items:center;gap:6px;">${window.rcIcon('check-circle-2', 'w-4 h-4')} Confirm Move-Out &amp; Archive</span>`
          : `<span style="display:inline-flex;align-items:center;gap:6px;">${window.rcIcon('log-out', 'w-4 h-4')} Confirm Move-Out Check</span>`;
      }
    }

    async function openMoveOutConfirmModal(context) {
      if (isDeletingTenant || isSubmittingMoveOut || isSubmittingOwnerDelete) return;

      const modal = document.getElementById('moveOutConfirmModal');
      if (!modal) return;

      const tenantId = parseInt(context.tenantId, 10);
      const rawUnit = context.unitNumber ? String(context.unitNumber).trim() : '';
      const unitLabel = rawUnit || UNIT_LABELS[String(context.unitId)] || 'Assigned Unit';

      activeMoveOutContext = {
        tenantId,
        tenantName: context.tenantName || 'Tenant',
        username: context.username || '',
        unitNumber: unitLabel,
        unitId: context.unitId ? parseInt(context.unitId, 10) : null,
        moveInDate: context.moveInDate || '',
        alreadyMovedOut: !!context.alreadyMovedOut
      };

      const titleEl = document.getElementById('moModalTitle');
      const nameEl = document.getElementById('moTenantName');
      const userEl = document.getElementById('moTenantUsername');
      const unitBadge = document.getElementById('moUnitBadge');
      const moveInEl = document.getElementById('moMoveInDate');
      const moveOutInput = document.getElementById('moMoveOutDate');
      const checkboxEl = document.getElementById('moSettlementConfirmCheckbox');
      const errEl = document.getElementById('moErrorAlert');
      const loadingEl = document.getElementById('moLoadingState');
      const auditContainer = document.getElementById('moSettlementContainer');
      const confirmBtn = document.getElementById('btnConfirmMoveOut');

      if (titleEl) {
        titleEl.textContent = activeMoveOutContext.alreadyMovedOut
          ? 'Verify Settlement & Archive Tenant'
          : 'Confirm Tenant Move-Out';
      }
      if (nameEl) nameEl.textContent = activeMoveOutContext.tenantName;
      if (userEl) {
        userEl.textContent = activeMoveOutContext.username ? `@${activeMoveOutContext.username}` : `ID #${tenantId}`;
      }
      if (unitBadge) unitBadge.textContent = unitLabel;
      if (moveInEl) moveInEl.textContent = formatDisplayDate(activeMoveOutContext.moveInDate);

      const todayStr = typeof getManilaTodayStr === 'function'
        ? getManilaTodayStr()
        : new Date().toISOString().slice(0, 10);
      if (moveOutInput) moveOutInput.value = todayStr;
      if (checkboxEl) checkboxEl.checked = false;
      if (errEl) {
        errEl.style.display = 'none';
        errEl.textContent = '';
      }
      if (loadingEl) loadingEl.style.display = 'flex';
      if (auditContainer) auditContainer.style.display = 'none';
      if (confirmBtn) {
        confirmBtn.disabled = true;
        confirmBtn.style.opacity = '0.7';
      }

      openModal('moveOutConfirmModal');

      try {
        const res = await fetch(`api/tenant_directory_api.php?action=check_settlement&tenant_id=${encodeURIComponent(tenantId)}`);
        const data = await res.json();

        if (!res.ok || data.status !== 'success') {
          throw new Error(data.message || 'Unable to verify payment settlement.');
        }

        if (activeMoveOutContext && activeMoveOutContext.tenantId === tenantId) {
          if (data.unit_id) activeMoveOutContext.unitId = parseInt(data.unit_id, 10);
          if (data.unit_number) {
            activeMoveOutContext.unitNumber = data.unit_number;
            if (unitBadge) unitBadge.textContent = data.unit_number;
          }
          if (data.username && userEl) userEl.textContent = `@${data.username}`;
          if (data.move_in_date_formatted && moveInEl) moveInEl.textContent = data.move_in_date_formatted;
          if (data.move_out_date && moveOutInput) moveOutInput.value = data.move_out_date;

          renderMoveOutSettlementAudit(data.settlement || {});
        }
      } catch (err) {
        if (errEl) {
          errEl.textContent = err.message || 'Network error checking payment settlement.';
          errEl.style.display = 'block';
        }
      } finally {
        if (loadingEl) loadingEl.style.display = 'none';
        if (auditContainer) auditContainer.style.display = 'block';
        if (confirmBtn) {
          confirmBtn.disabled = false;
          confirmBtn.style.opacity = '1';
        }
      }
    }

    function closeMoveOutConfirmModal(force = false) {
      if (isSubmittingMoveOut && !force) return;
      const modal = document.getElementById('moveOutConfirmModal');
      const errEl = document.getElementById('moErrorAlert');
      const checkboxEl = document.getElementById('moSettlementConfirmCheckbox');
      const pwInput = document.getElementById('moOwnerPasswordInput');
      const toggleBtn = document.getElementById('btnToggleMoOwnerPass');
      if (errEl) {
        errEl.style.display = 'none';
        errEl.textContent = '';
      }
      if (checkboxEl) checkboxEl.checked = false;
      if (pwInput) {
        pwInput.value = '';
        pwInput.type = 'password';
      }
      if (toggleBtn && window.rcIcon) {
        toggleBtn.innerHTML = window.rcIcon('eye', 'w-4 h-4');
      }
      activeMoveOutContext = null;
      if (modal) {
        modal.classList.add('hidden');
        modal.classList.remove('open', 'active');
        modal.style.display = 'none';
        modal.style.pointerEvents = 'none';
        document.body.style.overflow = '';
      }
      if (!force && typeof closeModal === 'function') {
        closeModal('moveOutConfirmModal');
      }
    }

    function toggleMoPasswordVisibility() {
      const pwInput = document.getElementById('moOwnerPasswordInput');
      const toggleBtn = document.getElementById('btnToggleMoOwnerPass');
      if (!pwInput || !toggleBtn) return;
      if (pwInput.type === 'password') {
        pwInput.type = 'text';
        toggleBtn.innerHTML = window.rcIcon ? window.rcIcon('eye-off', 'w-4 h-4') : '👁';
        toggleBtn.setAttribute('aria-label', 'Hide password');
      } else {
        pwInput.type = 'password';
        toggleBtn.innerHTML = window.rcIcon ? window.rcIcon('eye', 'w-4 h-4') : '👁';
        toggleBtn.setAttribute('aria-label', 'Show password');
      }
    }

    function handleMoveOutBackdropClick(event) {
      if (event.target === document.getElementById('moveOutConfirmModal')) {
        closeMoveOutConfirmModal();
      }
    }

    function markRowAsMovedOutPendingSettlement(tenantId) {
      const idNum = parseInt(tenantId, 10);
      if (Array.isArray(window.directoryUnitsData)) {
        window.directoryUnitsData.forEach(u => {
          (u.tenants || []).forEach(t => {
            if (parseInt(t.id, 10) === idNum) {
              t.rental_status = 'moved_out';
            }
          });
        });
        if (typeof renderDirectoryUnitsGrid === 'function') {
          renderDirectoryUnitsGrid(window.directoryUnitsData);
        }
      }

      const statusBadge = document.getElementById('tdRentalStatusBadge');
      const noticeBox = document.getElementById('tdMovedOutNotice');
      const delBtn = document.getElementById('tdBtnDelete');
      if (statusBadge) {
        statusBadge.className = 'clay-badge';
        statusBadge.style.background = '#fef3c7';
        statusBadge.style.color = '#92400e';
        statusBadge.textContent = 'Moved Out (Pending Settlement)';
      }
      if (noticeBox) noticeBox.style.display = 'block';
      if (delBtn) delBtn.innerHTML = `${window.rcIcon('shield-check', 'w-4 h-4')} Verify Settlement &amp; Archive`;
    }

    function syncDashboardAfterTenantArchived(result) {
      const tenantId = parseInt(result.tenant_id || 0, 10);
      const resolvedUnitId = parseInt(result.unit_id || activeMoveOutContext?.unitId || 0, 10);

      // 1. Remove archived tenant from unit card state & release unit occupancy
      if (Array.isArray(window.directoryUnitsData)) {
        window.directoryUnitsData.forEach(u => {
          const prevLen = (u.tenants || []).length;
          u.tenants = (u.tenants || []).filter(t => parseInt(t.id, 10) !== tenantId);
          if (prevLen > 0 && u.tenants.length === 0) {
            u.is_occupied = false;
            u.is_paid_up = null;
            u.card_state = 'available';
          }
        });
        if (typeof renderDirectoryUnitsGrid === 'function') {
          renderDirectoryUnitsGrid(window.directoryUnitsData);
        }
      }
      if (resolvedUnitId) {
        updateUnitTenantCount(resolvedUnitId);
      }

      // 2. Remove archived tenant rows from active Payment Verification table
      document.querySelectorAll(`#paymentRecordsTableBody .payment-record-row[data-tenant-id="${tenantId}"]`).forEach(pRow => {
        pRow.remove();
      });

      // 3. Synchronize client-side search & payment caches
      if (Array.isArray(OWNER_SEARCH_TENANTS)) {
        OWNER_SEARCH_TENANTS = OWNER_SEARCH_TENANTS.filter(t => parseInt(t.id, 10) !== tenantId);
      }
      if (Array.isArray(OWNER_SEARCH_PAYMENTS)) {
        OWNER_SEARCH_PAYMENTS = OWNER_SEARCH_PAYMENTS.filter(p => parseInt(p.tenant_id, 10) !== tenantId);
      }
      if (Array.isArray(allPaymentsData)) {
        allPaymentsData = allPaymentsData.filter(p => parseInt(p.tenant_id, 10) !== tenantId);
      }

      // 4. Immediately update Overview cards & directory badges
      const activeResEl = document.getElementById('tenantSummaryText');
      if (activeResEl && Array.isArray(OWNER_SEARCH_TENANTS)) {
        activeResEl.textContent = String(OWNER_SEARCH_TENANTS.length);
      }

      const unitsValEl = document.getElementById('overviewUnitsValue');
      if (unitsValEl) {
        const totalUnits = Object.keys(UNIT_LABELS).length || 3;
        const remainingOccupied = resolvedUnitId
          ? occupiedUnitIds.filter(id => id !== resolvedUnitId).length
          : occupiedUnitIds.length;
        const availableUnits = Math.max(0, totalUnits - remainingOccupied);
        unitsValEl.textContent = `${availableUnits} of ${totalUnits}`;
      }

      const archOvEl = document.getElementById('overviewArchivedTenants');
      if (archOvEl) {
        const curArch = parseInt(archOvEl.textContent || '0', 10) || 0;
        archOvEl.textContent = String(curArch + 1);
      }
      const archDirEl = document.getElementById('directoryArchivedCountBadge');
      if (archDirEl) {
        const curDir = parseInt(archDirEl.textContent || '0', 10) || 0;
        archDirEl.textContent = String(curDir + 1);
      }

      // 5. Refresh Archived Tenants folder from database & analytics
      cachedArchivedTenants = [];
      ownerSearchArchivedLoaded = false;
      loadArchivedTenants();
      if (typeof loadDashboardAnalytics === 'function') {
        loadDashboardAnalytics();
      }
    }

    async function submitMoveOutConfirmation() {
      if (isSubmittingMoveOut || !activeMoveOutContext) return;

      const checkboxEl = document.getElementById('moSettlementConfirmCheckbox');
      const moveOutInput = document.getElementById('moMoveOutDate');
      const errEl = document.getElementById('moErrorAlert');
      const confirmBtn = document.getElementById('btnConfirmMoveOut');
      const cancelBtn = document.getElementById('btnCancelMoveOut');

      if (errEl) {
        errEl.style.display = 'none';
        errEl.textContent = '';
      }

      if (!checkboxEl || !checkboxEl.checked) {
        if (errEl) {
          errEl.textContent = 'Please tick the confirmation box confirming you checked outstanding bills and pending payments before proceeding.';
          errEl.style.display = 'block';
        }
        return;
      }

      const moveOutDate = moveOutInput ? moveOutInput.value : '';
      if (!moveOutDate) {
        if (errEl) {
          errEl.textContent = 'Please select the official move-out date.';
          errEl.style.display = 'block';
        }
        if (moveOutInput) moveOutInput.focus();
        return;
      }

      const pwInput = document.getElementById('moOwnerPasswordInput');
      const ownerPassword = pwInput ? pwInput.value.trim() : '';
      if (!ownerPassword) {
        if (errEl) {
          errEl.textContent = 'Please enter your Landlord password to authorize this move-out.';
          errEl.style.display = 'block';
        }
        if (pwInput) pwInput.focus();
        return;
      }

      isSubmittingMoveOut = true;
      const origConfirmHtml = confirmBtn ? confirmBtn.innerHTML : '<span>Confirm</span>';
      if (confirmBtn) {
        confirmBtn.disabled = true;
        confirmBtn.style.opacity = '0.75';
        confirmBtn.style.cursor = 'not-allowed';
        confirmBtn.innerHTML = '<span class="clay-spinner" style="width: 14px; height: 14px; border-width: 2px; vertical-align: middle; margin-right: 6px;"></span><span>Processing...</span>';
      }
      if (cancelBtn) cancelBtn.disabled = true;

      try {
        const res = await fetch('api/tenant_directory_api.php', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({
            action: 'mark_moved_out',
            tenant_id: activeMoveOutContext.tenantId,
            confirm_settlement: true,
            move_out_date: moveOutDate,
            owner_password: ownerPassword
          })
        });
        const result = await res.json();

        if (res.ok && result.status === 'success' && result.archived) {
          showToast(result.message || 'Tenant moved out and automatically archived.');
          closeMoveOutConfirmModal(true);
          closeTenantDetailsModal();
          syncDashboardAfterTenantArchived(result);
        } else if (res.ok && result.status === 'success' && !result.archived) {
          // Decoupled Move-Out with unpaid balance: unit is immediately available, account stays active until settled
          showToast(result.message || 'Tenant marked as moved out. Unit is now available; account will remain active until balance is settled.', 'info');
          closeMoveOutConfirmModal(true);
          closeTenantDetailsModal();
          markRowAsMovedOutPendingSettlement(activeMoveOutContext.tenantId);
          if (typeof loadDashboardAnalytics === 'function') loadDashboardAnalytics();
          if (typeof loadTenantDirectory === 'function') loadTenantDirectory();
        } else if (res.ok && result.status === 'pending_settlement' && !result.archived) {
          if (result.settlement) {
            renderMoveOutSettlementAudit(result.settlement);
          }
          if (errEl) {
            errEl.textContent = result.message || 'Cannot archive tenant while unpaid bills or pending verifications remain.';
            errEl.style.display = 'block';
          }
          markRowAsMovedOutPendingSettlement(activeMoveOutContext.tenantId);
          showToast(result.message || 'Outstanding or pending payments must be settled before archiving.', 'warning');
        } else if (res.status === 409) {
          showToast(result.message || 'This tenant account has already been archived.', 'warning');
          closeMoveOutConfirmModal(true);
          closeTenantDetailsModal();
          syncDashboardAfterTenantArchived({ tenant_id: activeMoveOutContext.tenantId, unit_id: activeMoveOutContext.unitId });
        } else {
          const errMsg = result.message || 'Failed to process move-out.';
          if (errEl) {
            errEl.textContent = errMsg;
            errEl.style.display = 'block';
          }
          if (result && result.settlement) {
            renderMoveOutSettlementAudit(result.settlement);
          }
          showToast(errMsg, 'error');
        }
      } catch (e) {
        const netMsg = 'Network error while confirming move-out. Please try again.';
        if (errEl) {
          errEl.textContent = netMsg;
          errEl.style.display = 'block';
        }
        showToast(netMsg, 'error');
      } finally {
        if (pwInput) pwInput.value = '';
        isSubmittingMoveOut = false;
        if (confirmBtn) {
          confirmBtn.disabled = false;
          confirmBtn.style.opacity = '1';
          confirmBtn.style.cursor = 'pointer';
          confirmBtn.innerHTML = origConfirmHtml;
        }
        if (cancelBtn) cancelBtn.disabled = false;
      }
    }

    async function performTenantMoveOutDelete(tenantId, tenantName, unitNumber, unitId = null, alreadyMovedOut = false, ownerPassword = '') {
      if (isDeletingTenant) return { ok: false, message: 'Deletion is already in progress.' };
      isDeletingTenant = true;
      let outcome = { ok: true, message: '' };

      try {
        const res = await fetch('api/tenant_directory_api.php', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({
            action: 'mark_moved_out',
            tenant_id: parseInt(tenantId, 10),
            owner_password: ownerPassword
          })
        });
        const result = await res.json();

        if (res.ok && result.status === 'success' && result.archived) {
          showToast(result.message || 'Tenant account officially archived successfully.');
          closeTenantDetailsModal();
          syncDashboardAfterTenantArchived({ ...result, unit_id: result.unit_id || unitId });
        } else if (result.status === 'pending_settlement') {
          showToast(result.message, 'warning');
          markRowAsMovedOutPendingSettlement(tenantId);
          outcome = { ok: false, message: result.message };
        } else {
          outcome = { ok: false, message: result.message || 'Error processing move-out.' };
          showToast(outcome.message, 'error');
        }
      } catch (e) {
        outcome = { ok: false, message: 'Network error while processing move-out. Please try again.' };
        showToast(outcome.message, 'error');
      } finally {
        isDeletingTenant = false;
      }

      return outcome;
    }

    // Restore active section, unit folder, or profile tab on page load
    document.addEventListener('DOMContentLoaded', () => {
      if (window.lucide && typeof window.lucide.createIcons === 'function') {
        window.lucide.createIcons();
      }
      if (typeof window.initSharedSidebar === 'function') {
        window.initSharedSidebar();
      }

      // Universal Modal Backdrop Click-to-Close for Landlord Modals
      try {
        ['paymentDetailsModal', 'configureRentModal', 'addTenantModal', 'tenantCredentialsModal', 'ticketDetailsModal', 'reportDetailsModal', 'tenantDetailsModal', 'archivedTenantModal', 'archivedTenantDetailsModal', 'ownerDeleteConfirmModal', 'moveOutConfirmModal', 'announcementModal', 'viewIdImageModal', 'viewReceiptImageModal'].forEach(mId => {
          const m = document.getElementById(mId);
          if (m) {
            m.addEventListener('click', (e) => {
              if (e.target === m) closeModal(mId);
            });
          }
        });
      } catch (e) {
        console.warn('Landlord modal backdrop listener notice:', e);
      }

      const profileTrigger = document.getElementById('headerProfileTrigger');
      if (profileTrigger) {
        profileTrigger.addEventListener('keydown', (e) => {
          if (e.key === 'Enter' || e.key === ' ') {
            e.preventDefault();
            toggleSettingsMenu(e);
          }
        });
      }

      if (typeof initGlobalOwnerSearch === 'function') {
        initGlobalOwnerSearch();
      }

      initMoveInDatePicker();

      const moveInInput = document.getElementById('newMoveInDate');
      if (moveInInput) {
        moveInInput.addEventListener('change', function() {
          const todayStr = getManilaTodayStr();
          if (this.value && this.value < todayStr) {
            showToast("Invalid moving-in date. Please select today or a future date.", "error");
            this.value = todayStr;
          }
        });
      }
      const urlParams = new URLSearchParams(window.location.search);
      const targetSection = urlParams.get('section');
      const targetUnitId = urlParams.get('unit_id');
      const targetTab = urlParams.get('tab') || 'accountInfo';

      // Initialize Settings button visibility based on whether Profile section is active
      updateProfileSettingsVisibility(targetSection === 'sectionProfile');

      if (typeof renderReportsFolderViews === 'function') {
        renderReportsFolderViews();
      }

      if (targetSection === 'sectionArchivedTenants') {
        const navBtn = document.getElementById('navTenantDirectory');
        if (navBtn) switchSection('sectionDirectory', navBtn, false);
        switchDirectoryTab('archived');
      } else if (targetSection === 'sectionDirectory') {
        const navBtn = document.getElementById('navTenantDirectory');
        if (navBtn) switchSection('sectionDirectory', navBtn, false);
        if (targetUnitId) {
          openUnitFolder(targetUnitId);
        }
      } else if (targetSection === 'sectionTickets') {
        const navBtn = document.getElementById('navMaintenanceTickets');
        if (navBtn) switchSection('sectionTickets', navBtn, false);
        const targetTicketId = urlParams.get('ticket_id');
        if (targetTicketId) {
          window.openTicketDetailsById(targetTicketId);
        }
      } else if (targetSection === 'sectionReports') {
        const navBtn = document.getElementById('navTenantReports');
        if (navBtn) switchSection('sectionReports', navBtn, false);
        const targetReportId = urlParams.get('report_id');
        if (targetReportId && typeof window.openReportDetailsById === 'function') {
          window.openReportDetailsById(targetReportId);
        }
      } else if (targetSection === 'sectionPayments') {
        const navBtn = document.getElementById('navPaymentVerification');
        if (navBtn) switchSection('sectionPayments', navBtn, false);
      } else if (targetSection === 'sectionProfile') {
        openProfileView(targetTab, false);
      } else if (targetSection === 'sectionAnalytics') {
        const navBtn = document.getElementById('navLandlordDashboard');
        if (navBtn) switchSection('sectionAnalytics', navBtn, false);
      }
    });

    // =========================================================================
    // UTILITY: SAFE HTML ESCAPER
    // =========================================================================
    function escapeHtml(str) {
      if (str === null || str === undefined) return '';
      return String(str)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');
    }

    // =========================================================================
    // TENANT DIRECTORY TABS: ACTIVE TENANTS VS ARCHIVED TENANTS
    // =========================================================================
    function switchDirectoryTab(tab) {
      const activeBtn = document.getElementById('btnTabActiveDirectory');
      const archivedBtn = document.getElementById('btnTabArchivedDirectory');
      const activeView = document.getElementById('directoryActiveView');
      const archivedView = document.getElementById('directoryArchivedView');

      if (tab === 'archived') {
        if (activeBtn) {
          activeBtn.classList.remove('active');
          activeBtn.setAttribute('aria-selected', 'false');
        }
        if (archivedBtn) {
          archivedBtn.classList.add('active');
          archivedBtn.setAttribute('aria-selected', 'true');
        }
        if (activeView) activeView.style.display = 'none';
        if (archivedView) archivedView.style.display = 'block';
        loadArchivedTenants();
      } else {
        if (archivedBtn) {
          archivedBtn.classList.remove('active');
          archivedBtn.setAttribute('aria-selected', 'false');
        }
        if (activeBtn) {
          activeBtn.classList.add('active');
          activeBtn.setAttribute('aria-selected', 'true');
        }
        if (archivedView) archivedView.style.display = 'none';
        if (activeView) activeView.style.display = 'block';
        if (typeof fetchActiveDirectoryUnits === 'function') {
          fetchActiveDirectoryUnits();
        }
      }
    }

    let cachedArchivedTenants = [];
    let isArchivedTenantsLoading = false;

    async function loadArchivedTenants(searchQuery = '') {
      if (isArchivedTenantsLoading) return;
      isArchivedTenantsLoading = true;

      const tbody = document.getElementById('archivedTenantsTableBody');
      const countBadge = document.getElementById('archivedTenantCountBadge');
      const dirBadge = document.getElementById('directoryArchivedCountBadge');

      if (tbody && !searchQuery) {
        tbody.innerHTML = `
          <tr>
            <td colspan="6" style="text-align: center; padding: 36px 14px; color: var(--text-muted); font-size: 13.5px;">
              <span class="clay-spinner" style="width: 18px; height: 18px; border-width: 2px; vertical-align: middle; margin-right: 8px;"></span>
              Loading archived tenant accounts...
            </td>
          </tr>
        `;
      }

      try {
        const url = 'api/tenant_directory_api.php?action=list_archived&search=' + encodeURIComponent(searchQuery);
        const res = await fetch(url);
        const data = await res.json();

        if (res.ok && data.status === 'success') {
          cachedArchivedTenants = data.archived_tenants || [];
          const count = cachedArchivedTenants.length;

          if (countBadge) countBadge.textContent = `${count} Archived Record${count === 1 ? '' : 's'}`;
          if (dirBadge) dirBadge.textContent = count;
          if (!searchQuery) {
            const ovArch = document.getElementById('overviewArchivedTenants');
            if (ovArch) ovArch.textContent = String(count);
          }

          renderArchivedTenantsTable(cachedArchivedTenants);
        } else {
          if (tbody) {
            tbody.innerHTML = `
              <tr>
                <td colspan="6" style="text-align: center; padding: 32px 14px; color: #ef4444; font-size: 13.5px;">
                  Failed to load archived records: ${escapeHtml(data.message || 'Server error')}
                </td>
              </tr>
            `;
          }
        }
      } catch (err) {
        console.error('Error loading archived tenants:', err);
        if (tbody) {
          tbody.innerHTML = `
            <tr>
              <td colspan="6" style="text-align: center; padding: 32px 14px; color: #ef4444; font-size: 13.5px;">
                Network error while loading archived records. Please refresh.
              </td>
            </tr>
          `;
        }
      } finally {
        isArchivedTenantsLoading = false;
      }
    }

    let searchArchivedTimeout = null;
    function filterArchivedTenants(val) {
      if (searchArchivedTimeout) clearTimeout(searchArchivedTimeout);
      searchArchivedTimeout = setTimeout(() => {
        loadArchivedTenants(val.trim());
      }, 300);
    }

    function exportArchivedTenantsToCSV() {
      const records = cachedArchivedTenants || [];
      if (records.length === 0) {
        showToast('No archived tenant records available to export.', 'warning');
        return;
      }
      const headers = ['Tenant Name', 'Unit Number', 'Email Address', 'Contact Number', 'Date of Moving In', 'Move-Out Date'];
      const rows = records.map(t => [
        t.name || '',
        t.archived_unit_number || '',
        t.email || '',
        t.phone || '',
        t.formatted_move_in || '',
        t.formatted_move_out || (t.formatted_deleted_at || '')
      ]);

      const csvContent = 'data:text/csv;charset=utf-8,\uFEFF'
        + headers.map(h => `"${h.replace(/"/g, '""')}"`).join(',') + '\n'
        + rows.map(r => r.map(c => `"${String(c).replace(/"/g, '""')}"`).join(',')).join('\n');

      const encodedUri = encodeURI(csvContent);
      const link = document.createElement('a');
      link.setAttribute('href', encodedUri);
      link.setAttribute('download', `tararent_archived_tenants_${new Date().toISOString().slice(0, 10)}.csv`);
      document.body.appendChild(link);
      link.click();
      document.body.removeChild(link);
      showToast('Archived tenants successfully exported to CSV.', 'success');
    }

    function renderArchivedTenantsTable(tenants) {
      const tbody = document.getElementById('archivedTenantsTableBody');
      if (!tbody) return;

      if (!tenants || tenants.length === 0) {
        tbody.innerHTML = `
          <tr>
            <td colspan="6" style="text-align: center; padding: 42px 14px; color: var(--text-muted); font-size: 13.5px;">
              <div style="margin-bottom: 8px; display: flex; justify-content: center; color: #94a3b8;">${window.rcIcon('folder-open', 'w-7 h-7')}</div>
              <strong>No archived tenant accounts found.</strong>
              <div style="font-size: 12px; color: #94a3b8; margin-top: 4px;">Moved-out and settled tenant records will appear here with full historical logs.</div>
            </td>
          </tr>
        `;
        return;
      }

      let html = '';
      tenants.forEach(t => {
        const initial = (t.name ? t.name.charAt(0) : 'T').toUpperCase();
        const avatarHtml = t.profile_picture 
          ? `<img src="${escapeHtml(t.profile_picture)}" alt="${escapeHtml(t.name)}" style="width: 34px; height: 34px; border-radius: 50%; object-fit: cover; border: 1.5px solid #cbd5e1; flex-shrink: 0;">`
          : `<div style="width: 34px; height: 34px; border-radius: 50%; background: linear-gradient(135deg, #64748b, #475569); color: #fff; font-weight: 700; font-size: 13px; display: flex; align-items: center; justify-content: center; flex-shrink: 0;">${initial}</div>`;

        const tenantUnit = t.archived_unit_number || 'Unit N/A';
        const moveInFormatted = t.formatted_move_in || '—';
        const moveOutFormatted = t.formatted_move_out || (t.formatted_deleted_at || 'Not set');

        html += `
          <tr id="archived-row-${t.id}"
              class="cursor-pointer hover:bg-[#E8D8B0]/50 transition-colors archived-tenant-row"
              onclick="openArchivedModal(${t.id})"
              data-id="${t.id}"
              data-name="${escapeHtml(t.name || '')}"
              data-unit="${escapeHtml(tenantUnit)}"
              data-email="${escapeHtml(t.email || '—')}"
              data-contact="${escapeHtml(t.phone || '—')}"
              data-move-in="${escapeHtml(moveInFormatted)}"
              data-move-out="${escapeHtml(moveOutFormatted)}"
              data-avatar="${escapeHtml(t.profile_picture || '')}"
              title="Click to view full archived tenant details"
              style="border-bottom: 1px solid rgba(166, 180, 204, 0.25);">
            <td style="padding: 14px 16px; font-weight: 700; color: #0f172a;">
              <div style="display: flex; align-items: center; gap: 10px; min-width: 0;">
                ${avatarHtml}
                <span class="truncate" title="${escapeHtml(t.name)}">${escapeHtml(t.name)}</span>
              </div>
            </td>
            <td style="padding: 14px 14px;">
              <span class="clay-badge" style="background: #F3E4C6; color: #56380E; border: 1px solid rgba(181, 143, 83, 0.35); font-weight: 800; font-size: 11.5px; padding: 3px 9px;">
                ${escapeHtml(tenantUnit)}
              </span>
            </td>
            <td class="truncate" style="padding: 14px 14px; color: #334155; font-size: 13px;" title="${escapeHtml(t.email || '')}">
              ${escapeHtml(t.email || '—')}
            </td>
            <td style="padding: 14px 14px; color: #334155; font-size: 13px;">${escapeHtml(t.phone || '—')}</td>
            <td style="padding: 14px 14px; color: var(--text-muted); font-size: 12.5px;">${escapeHtml(moveInFormatted)}</td>
            <td style="padding: 14px 14px; color: #92400e; font-weight: 700; font-size: 12.5px;">${escapeHtml(moveOutFormatted)}</td>
          </tr>
        `;
      });

      tbody.innerHTML = html;
      if (window.lucide && typeof window.lucide.createIcons === 'function') {
        window.lucide.createIcons();
      }
    }

    let currentArchivedTenantId = null;

    /**
     * Open Archived Tenant Modal via AJAX (fetch_archived_tenant.php)
     * Fetches tenant full profile, payment history, and maintenance history
     */
    async function openArchivedModal(tenantId) {
      // Support both direct ID and element passing
      let id = tenantId;
      if (typeof tenantId === 'object' && tenantId !== null) {
        id = tenantId.getAttribute('data-id') || tenantId.dataset.id || '';
      }
      if (!id) return;
      currentArchivedTenantId = id;

      const modal = document.getElementById('archivedTenantModal');
      if (!modal) return;

      const loadingEl = document.getElementById('archivedModalLoading');
      const errorEl = document.getElementById('archivedModalError');
      const contentEl = document.getElementById('archivedModalBodyContent');

      // Unhide modal immediately and show spinner
      openModal('archivedTenantModal');

      if (loadingEl) loadingEl.style.display = 'block';
      if (errorEl) errorEl.style.display = 'none';
      if (contentEl) contentEl.style.display = 'none';

      try {
        const res = await fetch(`fetch_archived_tenant.php?id=${encodeURIComponent(id)}`);
        const data = await res.json();

        if (!res.ok || data.status !== 'success' || !data.tenant) {
          throw new Error(data.message || 'Unable to load archived tenant records.');
        }

        const t = data.tenant;
        const payments = data.payments || [];
        const maintenance = data.maintenance || data.tickets || [];

        // 1. Inject Profile Data
        const nameEl = document.getElementById('archivedModalName');
        if (nameEl) nameEl.textContent = t.name || '—';
        const headerNameEl = document.getElementById('archivedModalTenantName');
        if (headerNameEl) headerNameEl.textContent = t.name || 'Tenant Name';

        const unitEl = document.getElementById('archivedModalUnit');
        if (unitEl) unitEl.textContent = t.unit_display || t.archived_unit_number || 'Unit N/A';
        const unitBadgeEl = document.getElementById('archivedModalUnitBadge');
        if (unitBadgeEl) unitBadgeEl.textContent = t.unit_display || t.archived_unit_number || 'Unit N/A';

        const userBadgeEl = document.getElementById('archivedModalUsernameBadge');
        if (userBadgeEl) userBadgeEl.textContent = t.username ? `@${t.username}` : '@RC000';

        const emailEl = document.getElementById('archivedModalEmail');
        if (emailEl) {
          emailEl.textContent = t.email || '—';
          emailEl.setAttribute('title', t.email || '');
        }

        const contactEl = document.getElementById('archivedModalContact');
        if (contactEl) contactEl.textContent = t.phone || '—';

        const moveInEl = document.getElementById('archivedModalMoveIn');
        if (moveInEl) moveInEl.textContent = t.formatted_move_in || '—';

        const moveOutEl = document.getElementById('archivedModalMoveOut');
        if (moveOutEl) moveOutEl.textContent = t.formatted_move_out || '—';

        const rentEl = document.getElementById('archivedModalRent');
        if (rentEl) rentEl.textContent = t.monthly_rent_formatted || '—';

        const bdayEl = document.getElementById('archivedModalBirthday');
        if (bdayEl) bdayEl.textContent = t.formatted_birthday || '—';

        const emEl = document.getElementById('archivedModalEmergency');
        if (emEl) emEl.textContent = t.emergency_contact_display || '—';

        const delDateEl = document.getElementById('archivedModalDeletedDate');
        if (delDateEl) delDateEl.textContent = `Archived on ${t.formatted_deleted_at || 'Archived'}`;

        // Avatar
        const avatarContainer = document.getElementById('archivedModalAvatarContainer');
        if (avatarContainer) {
          if (t.profile_picture && t.profile_picture.trim() !== '') {
            avatarContainer.innerHTML = `<img src="${escapeHtml(t.profile_picture)}" alt="${escapeHtml(t.name)}" class="w-14 h-14 rounded-full object-cover border-2 border-[#B89B72] shadow-sm">`;
          } else {
            const initial = (t.name ? t.name.charAt(0) : 'T').toUpperCase();
            avatarContainer.innerHTML = `<div class="w-14 h-14 rounded-full bg-[#B89B72] text-[#4A3728] font-black text-xl flex items-center justify-center border-2 border-[#4A3728]/20 shadow-sm">${escapeHtml(initial)}</div>`;
          }
        }

        // 2. Inject Payment History
        const payList = document.getElementById('archivedModalPaymentsList');
        const payCount = document.getElementById('archivedModalPaymentCount');
        if (payCount) payCount.textContent = `${payments.length} Record${payments.length === 1 ? '' : 's'}`;

        if (payList) {
          if (payments.length === 0) {
            payList.innerHTML = `
              <tr>
                <td colspan="7" class="py-6 text-center text-[#4A3728]/70 italic">
                  No historical payment records found for this tenant.
                </td>
              </tr>
            `;
          } else {
            let pRows = '';
            payments.forEach(p => {
              let statusBadgeClass = 'bg-[#E8D8B0] text-[#4A3728] border-[#B89B72]';
              const st = (p.status || '').toLowerCase();
              if (st === 'approved' || st === 'paid') {
                statusBadgeClass = 'bg-[#dcfce7] text-[#166534] border-[#86efac]';
              } else if (st === 'overdue' || st === 'rejected') {
                statusBadgeClass = 'bg-[#fee2e2] text-[#991b1b] border-[#fca5a5]';
              } else if (st === 'pending') {
                statusBadgeClass = 'bg-[#fef3c7] text-[#92400e] border-[#fcd34d]';
              }

              pRows += `
                <tr class="hover:bg-[#FAF5EC] transition-colors">
                  <td class="py-2.5 px-3 font-semibold text-[#4A3728]">${escapeHtml(p.billing_cycle_formatted || p.payment_type || 'Rent')}</td>
                  <td class="py-2.5 px-3 text-[#4A3728]/80">${escapeHtml(p.amount_due_formatted || '₱0.00')}</td>
                  <td class="py-2.5 px-3 font-bold text-[#4A3728]">${escapeHtml(p.amount_paid_formatted || '₱0.00')}</td>
                  <td class="py-2.5 px-3 text-[#4A3728]/80">${escapeHtml(p.due_date_formatted || '—')}</td>
                  <td class="py-2.5 px-3 text-[#4A3728]/80">${escapeHtml(p.payment_date_formatted || 'Pending')}</td>
                  <td class="py-2.5 px-3 text-[#4A3728]/80">${escapeHtml(p.payment_method_label || '—')}</td>
                  <td class="py-2.5 px-3">
                    <span class="inline-block px-2 py-0.5 text-[10.5px] font-extrabold rounded-md border ${statusBadgeClass}">
                      ${escapeHtml(p.status_label || 'Unpaid')}
                    </span>
                  </td>
                </tr>
              `;
            });
            payList.innerHTML = pRows;
          }
        }

        // 3. Inject Maintenance History
        const maintList = document.getElementById('archivedModalMaintenanceList');
        const maintCount = document.getElementById('archivedModalTicketCount');
        if (maintCount) maintCount.textContent = `${maintenance.length} Request${maintenance.length === 1 ? '' : 's'}`;

        if (maintList) {
          if (maintenance.length === 0) {
            maintList.innerHTML = `
              <tr>
                <td colspan="6" class="py-6 text-center text-[#4A3728]/70 italic">
                  No maintenance requests on record for this tenant.
                </td>
              </tr>
            `;
          } else {
            let mRows = '';
            maintenance.forEach(m => {
              let statusBadgeClass = 'bg-[#E8D8B0] text-[#4A3728] border-[#B89B72]';
              const st = (m.status || '').toLowerCase();
              if (st === 'resolved' || st === 'completed') {
                statusBadgeClass = 'bg-[#dcfce7] text-[#166534] border-[#86efac]';
              } else if (st === 'in_progress') {
                statusBadgeClass = 'bg-[#e0e7ff] text-[#3730a3] border-[#a5b4fc]';
              } else if (st === 'open' || st === 'pending') {
                statusBadgeClass = 'bg-[#fef3c7] text-[#92400e] border-[#fcd34d]';
              }

              let priorityBadgeClass = 'bg-[#FAF5EC] text-[#4A3728] border-[#B89B72]/40';
              const pr = (m.priority || '').toLowerCase();
              if (pr === 'high' || pr === 'emergency') {
                priorityBadgeClass = 'bg-[#fee2e2] text-[#991b1b] border-[#fca5a5]';
              } else if (pr === 'medium') {
                priorityBadgeClass = 'bg-[#fef3c7] text-[#92400e] border-[#fcd34d]';
              }

              mRows += `
                <tr class="hover:bg-[#FAF5EC] transition-colors">
                  <td class="py-2.5 px-3">
                    <div class="font-bold text-[#4A3728]">${escapeHtml(m.title || 'Untitled')}</div>
                    <div class="text-[11px] text-[#4A3728]/70 line-clamp-1 truncate max-w-xs">${escapeHtml(m.description || '')}</div>
                  </td>
                  <td class="py-2.5 px-3 text-[#4A3728]/80">${escapeHtml(m.category_label || 'General')}</td>
                  <td class="py-2.5 px-3">
                    <span class="inline-block px-1.5 py-0.5 text-[10px] font-bold rounded border ${priorityBadgeClass}">
                      ${escapeHtml(m.priority_label || 'Medium')}
                    </span>
                  </td>
                  <td class="py-2.5 px-3 text-[#4A3728]/80">${escapeHtml(m.created_at_formatted || '—')}</td>
                  <td class="py-2.5 px-3">
                    <span class="inline-block px-2 py-0.5 text-[10.5px] font-extrabold rounded-md border ${statusBadgeClass}">
                      ${escapeHtml(m.status_label || 'Open')}
                    </span>
                  </td>
                  <td class="py-2.5 px-3 font-semibold text-[#4A3728]">${escapeHtml(m.cost_formatted || '—')}</td>
                </tr>
              `;
            });
            maintList.innerHTML = mRows;
          }
        }

        // Hide loader, show content
        if (loadingEl) loadingEl.style.display = 'none';
        if (contentEl) contentEl.style.display = 'block';

        if (window.lucide && typeof window.lucide.createIcons === 'function') {
          window.lucide.createIcons();
        }

      } catch (err) {
        console.error('Failed to fetch archived tenant:', err);
        if (loadingEl) loadingEl.style.display = 'none';
        if (contentEl) contentEl.style.display = 'none';
        if (errorEl) {
          errorEl.style.display = 'block';
          const msgEl = document.getElementById('archivedModalErrorMessage');
          if (msgEl) msgEl.textContent = err.message || 'Failed to load archived tenant records.';
        }
      }
    }

    function retryFetchArchivedTenant() {
      if (currentArchivedTenantId) {
        openArchivedModal(currentArchivedTenantId);
      }
    }

    /**
     * Close Archived Tenant Modal
     */
    function closeArchivedModal() {
      const modal = document.getElementById('archivedTenantModal');
      if (modal) {
        modal.classList.add('hidden');
        modal.classList.remove('open', 'active');
        modal.style.display = 'none';
        modal.style.pointerEvents = 'none';
        document.body.style.overflow = '';
      }
      currentArchivedTenantId = null;
    }

    // Keyboard listener for Escape key
    document.addEventListener('keydown', function(e) {
      if (e.key === 'Escape') {
        const modal = document.getElementById('archivedTenantModal');
        if (modal && !modal.classList.contains('hidden')) {
          closeArchivedModal();
        }
      }
    });

    window.openArchivedModal = openArchivedModal;
    window.closeArchivedModal = closeArchivedModal;

    async function openArchivedTenantDetails(tenantId) {
      const modal = document.getElementById('archivedTenantDetailsModal');
      if (!modal) return;

      const tenantIdInput = document.getElementById('archivedModalTenantId');
      if (tenantIdInput) tenantIdInput.value = tenantId;

      // Animate modal open & lock body scroll
      openModal('archivedTenantDetailsModal');

      // Reset loading state for payments and maintenance tickets
      const payList = document.getElementById('archivedPaymentsList');
      if (payList) {
        payList.innerHTML = '<tr><td colspan="8" style="text-align: center; padding: 24px 14px; color: var(--text-muted);"><span class="clay-spinner" style="width: 16px; height: 16px; border-width: 2px; vertical-align: middle; margin-right: 6px;"></span>Loading payment records...</td></tr>';
      }
      const ticketList = document.getElementById('archivedTicketsList');
      if (ticketList) {
        ticketList.innerHTML = '<div style="text-align: center; padding: 18px 12px; color: var(--text-muted); font-size: 12.5px;"><span class="clay-spinner" style="width: 15px; height: 15px; border-width: 2px; vertical-align: middle; margin-right: 6px;"></span>Loading maintenance tickets...</div>';
      }

      try {
        const res = await fetch(`api/tenant_directory_api.php?action=get_archived_tenant_details&tenant_id=${tenantId}`);
        const data = await res.json();

        if (res.ok && data.status === 'success') {
          const t = data.tenant;
          const payments = data.payments || [];
          const tickets = data.tickets || [];

          // Header
          const nameEl = document.getElementById('archivedDetailsModalName');
          const dateEl = document.getElementById('archivedModalDeletedDate');
          const avatarEl = document.getElementById('archivedModalAvatar');
          const usernameBadge = document.getElementById('archivedModalUsernameBadge');

          if (nameEl) nameEl.textContent = t.name || 'Archived Tenant';
          if (dateEl) {
            dateEl.textContent = `Moved out on ${t.formatted_move_out || 'N/A'} • Archived on ${t.formatted_deleted_at || 'N/A'}`;
          }
          if (usernameBadge) usernameBadge.textContent = t.username ? `@${t.username}` : '@RC000';
          if (avatarEl) {
            if (t.profile_picture) {
              avatarEl.innerHTML = `<img src="${escapeHtml(t.profile_picture)}" alt="${escapeHtml(t.name)}" style="width: 100%; height: 100%; object-fit: cover; border-radius: 14px;">`;
            } else {
              avatarEl.textContent = (t.name ? t.name.charAt(0) : 'T').toUpperCase();
            }
          }

          // Personal Information
          const pName = document.getElementById('archivedPersonalName');
          const pEmail = document.getElementById('archivedPersonalEmail');
          const pPhone = document.getElementById('archivedPersonalPhone');
          const pAddress = document.getElementById('archivedPersonalAddress');
          const pBirthday = document.getElementById('archivedPersonalBirthday');
          const pEmergency = document.getElementById('archivedPersonalEmergency');
          const pOccupants = document.getElementById('archivedPersonalOccupants');

          if (pName) pName.textContent = t.name || '-';
          if (pEmail) pEmail.textContent = t.email || '—';
          if (pPhone) pPhone.textContent = t.phone || '—';
          if (pAddress) pAddress.textContent = t.complete_address || '—';
          if (pBirthday) pBirthday.textContent = t.formatted_birthday || 'Not provided';
          if (pEmergency) {
            const emParts = [t.emergency_contact_name, t.emergency_contact_relationship ? `(${t.emergency_contact_relationship})` : '', t.emergency_contact_phone]
              .filter(Boolean);
            pEmergency.textContent = emParts.length > 0 ? emParts.join(' · ') : (t.emergency_contact || 'Not provided');
          }
          if (pOccupants) {
            const totOcc = parseInt(t.total_occupants || 1, 10) || 1;
            const adults = parseInt(t.adults_count || 1, 10) || 0;
            const kids = parseInt(t.kids_count || 0, 10) || 0;
            pOccupants.textContent = `${totOcc} total (${adults} adult${adults === 1 ? '' : 's'}, ${kids} kid${kids === 1 ? '' : 's'})`;
          }

          // Rental & Move-Out Information
          const rUnit = document.getElementById('archivedRentalUnit');
          const rUnitHist = document.getElementById('archivedRentalUnitHistory');
          const rSpaces = document.getElementById('archivedRentalSpaces');
          const rMoveIn = document.getElementById('archivedRentalMoveIn');
          const rMoveOut = document.getElementById('archivedRentalMoveOut');
          const rRent = document.getElementById('archivedRentalMonthlyRent');
          const rDeposit = document.getElementById('archivedRentalDeposit');
          const rCreatedAt = document.getElementById('archivedRentalCreatedAt');
          const rDeletedAt = document.getElementById('archivedRentalDeletedAt');

          if (rUnit) rUnit.textContent = t.archived_unit_number || 'Unit N/A';
          if (rUnitHist) rUnitHist.textContent = t.unit_history || t.archived_unit_number || 'Not recorded';
          if (rSpaces) rSpaces.textContent = t.archived_spaces_text || 'None';
          if (rMoveIn) rMoveIn.textContent = t.formatted_move_in || '—';
          if (rMoveOut) rMoveOut.textContent = t.formatted_move_out || 'Not recorded';
          if (rRent) rRent.textContent = t.archived_monthly_rent_formatted || '₱3,000.00 / mo';
          if (rDeposit) rDeposit.textContent = t.advance_deposit_formatted || '₱3,000.00';
          if (rCreatedAt) rCreatedAt.textContent = t.formatted_created_at || '—';
          if (rDeletedAt) rDeletedAt.textContent = t.formatted_deleted_at || 'Archived';

          // Account Information
          const aUser = document.getElementById('archivedAccountUsername');
          const aStatus = document.getElementById('archivedAccountRentalStatus');
          const aIdStatus = document.getElementById('archivedAccountIdStatus');

          if (aUser) aUser.textContent = t.username ? `@${t.username}` : '@RC000';
          if (aStatus) aStatus.textContent = t.rental_status_label || 'Archived / Moved Out';
          if (aIdStatus) {
            aIdStatus.textContent = t.id_type ? `${t.id_type} (${t.verification_status || 'verified'})` : 'Verified Account';
          }

          // Payment Records
          const pBadge = document.getElementById('archivedPaymentCountBadge');
          if (pBadge) pBadge.textContent = `${payments.length} Record${payments.length === 1 ? '' : 's'}`;

          if (payList) {
            if (payments.length === 0) {
              payList.innerHTML = '<tr><td colspan="8" style="text-align: center; padding: 24px 14px; color: var(--text-muted);">No payment records found for this archived account.</td></tr>';
            } else {
              payList.innerHTML = payments.map(p => {
                const receiptHref = p.receipt_filename && (p.receipt_filename.startsWith('http://') || p.receipt_filename.startsWith('https://'))
                  ? p.receipt_filename
                  : ('uploads/receipts/' + encodeURIComponent(p.receipt_filename || ''));
                const receiptLink = p.receipt_filename 
                  ? `<a href="${escapeHtml(receiptHref)}" target="_blank" class="btn-inspect-proof" style="font-size: 11px; padding: 3px 8px; text-decoration: none; white-space: nowrap; display: inline-flex; align-items: center; gap: 4px;">${window.rcIcon('file-text', 'w-3.5 h-3.5')} Receipt</a>`
                  : '<span style="color: #94a3b8; font-size: 11px;">None</span>';
                const methodStr = p.payment_method || 'GCash / Bank Transfer';
                const typeLabel = p.payment_type || 'Rent';
                const unitLabel = p.unit_label || t.archived_unit_number || 'Unit';
                return `
                  <tr style="border-bottom: 1px solid rgba(166, 180, 204, 0.15);">
                    <td style="padding: 9px 10px; font-weight: 700; color: #0f172a;">${escapeHtml(p.billing_cycle_formatted || '-')}</td>
                    <td style="padding: 9px 10px;">
                      <div style="font-weight: 700; color: #56380E; font-size: 12px;">${escapeHtml(typeLabel)}</div>
                      <div style="font-size: 11px; color: var(--text-muted);">${escapeHtml(unitLabel)}</div>
                    </td>
                    <td style="padding: 9px 10px; font-weight: 600; color: #475569;">${escapeHtml(p.amount_due_formatted || '₱0.00')}</td>
                    <td style="padding: 9px 10px; font-weight: 700; color: #047857;">${escapeHtml(p.amount_paid_formatted || '₱0.00')}</td>
                    <td style="padding: 9px 10px; font-size: 12px; color: var(--text-muted);">${escapeHtml(p.payment_date_formatted || 'Pending')}</td>
                    <td style="padding: 9px 10px; font-size: 12px; color: #334155; font-weight: 600;">${escapeHtml(methodStr)}</td>
                    <td style="padding: 9px 10px;"><span class="clay-badge badge-${escapeHtml(p.status)}">${escapeHtml(String(p.status).toUpperCase())}</span></td>
                    <td style="padding: 9px 10px; text-align: center; white-space: nowrap;">${receiptLink}</td>
                  </tr>
                `;
              }).join('');
            }
          }

          // Previous Maintenance Tickets
          const tkBadge = document.getElementById('archivedTicketCountBadge');
          if (tkBadge) tkBadge.textContent = `${tickets.length} Ticket${tickets.length === 1 ? '' : 's'}`;

          if (ticketList) {
            if (tickets.length === 0) {
              ticketList.innerHTML = '<div style="text-align: center; padding: 20px 12px; color: var(--text-muted); font-size: 12.5px;">No maintenance tickets were filed by this tenant.</div>';
            } else {
              ticketList.innerHTML = tickets.map(tk => {
                const st = String(tk.status || 'open');
                const statusBadgeStyle = (st === 'resolved' || st === 'closed')
                  ? 'background: #dcfce7; color: #166534;'
                  : (st === 'in_progress' ? 'background: #fef3c7; color: #92400e;' : 'background: #fee2e2; color: #991b1b;');
                const photos = (tk.photo_path || '')
                  .split(',')
                  .map(s => s.trim())
                  .filter(Boolean);
                const photosHtml = photos.length > 0
                  ? `<div style="display: flex; gap: 6px; flex-wrap: wrap; margin-top: 6px;">` +
                    photos.map((src, idx) => `<a href="${escapeHtml(src)}" target="_blank" style="font-size: 11px; font-weight: 700; color: #56380E; background: #F3E4C6; padding: 2px 8px; border-radius: 6px; text-decoration: none; display: inline-flex; align-items: center; gap: 4px;">${window.rcIcon('image', 'w-3.5 h-3.5')} Photo ${photos.length > 1 ? idx + 1 : ''}</a>`).join('') +
                    `</div>`
                  : '';
                const resHtml = tk.resolution_notes
                  ? `<div style="margin-top: 6px; padding: 6px 8px; background: #FFF9EE; border-radius: 6px; font-size: 11.5px; color: #56380E;"><strong>Resolution:</strong> ${escapeHtml(tk.resolution_notes)}${tk.resolved_at_formatted ? ` <span style="color: var(--text-muted);">(${escapeHtml(tk.resolved_at_formatted)})</span>` : ''}</div>`
                  : '';

                return `
                  <div style="background: #ffffff; border: 1px solid rgba(166, 180, 204, 0.28); border-radius: 10px; padding: 10px 12px;">
                    <div style="display: flex; justify-content: space-between; align-items: flex-start; gap: 8px; flex-wrap: wrap;">
                      <div>
                        <div style="font-size: 13px; font-weight: 800; color: #0f172a;">#${tk.id} · ${escapeHtml(tk.title || 'Maintenance Request')}</div>
                        <div style="font-size: 11.5px; color: var(--text-muted); margin-top: 2px;">
                          ${escapeHtml(tk.unit_label || 'Unit')} • ${escapeHtml(tk.category || 'General')} • Priority: ${escapeHtml(tk.priority_label || 'Medium')} • Filed ${escapeHtml(tk.formatted_date || 'N/A')}
                        </div>
                      </div>
                      <div style="display: flex; align-items: center; gap: 8px;">
                        <span style="font-size: 12px; font-weight: 800; color: #302313;">${escapeHtml(tk.actual_cost_formatted || '₱0.00')}</span>
                        <span class="clay-badge" style="font-size: 10.5px; padding: 2px 8px; font-weight: 800; ${statusBadgeStyle}">${escapeHtml((tk.status_label || st).toUpperCase())}</span>
                      </div>
                    </div>
                    ${tk.description ? `<div style="font-size: 12px; color: #334155; margin-top: 6px; line-height: 1.4;">${escapeHtml(tk.description)}</div>` : ''}
                    ${resHtml}
                    ${photosHtml}
                  </div>
                `;
              }).join('');
            }
          }

        } else {
          showToast(data.message || 'Error fetching archived details', 'error');
        }
      } catch (err) {
        showToast('Error communicating with server', 'error');
      }
    }

    function closeArchivedTenantModal(fromCloseModal = false) {
      const modal = document.getElementById('archivedTenantDetailsModal');
      if (modal) {
        modal.classList.add('hidden');
        modal.classList.remove('open', 'active');
        modal.style.display = 'none';
        modal.style.pointerEvents = 'none';
        document.body.style.overflow = '';
      }
      if (!fromCloseModal && typeof closeModal === 'function') {
        closeModal('archivedTenantDetailsModal');
      }
    }

    function handleArchivedModalBackdropClick(event) {
      if (event.target === document.getElementById('archivedTenantDetailsModal')) {
        closeArchivedTenantModal();
      }
    }

    async function submitRestoreArchivedAccount() {
      const tenantId = document.getElementById('archivedModalTenantId').value;
      if (!tenantId) return;

      const tenantName = document.getElementById('archivedPersonalName').textContent || 'this tenant';
      if (!confirm(`Are you sure you want to restore '${tenantName}' to the Active Tenants list?`)) {
        return;
      }

      const btn = document.getElementById('btnRestoreArchivedAccount');
      const origHtml = btn.innerHTML;
      btn.innerHTML = '<span>Restoring...</span>';
      btn.disabled = true;

      try {
        const res = await fetch('api/tenant_directory_api.php', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ action: 'restore', tenant_id: parseInt(tenantId, 10) })
        });
        const data = await res.json();
        if (res.ok && data.status === 'success') {
          showToast(data.message || `Tenant '${tenantName}' restored successfully.`);
          closeArchivedTenantModal();
          loadArchivedTenants();
          if (typeof loadTenantsDirectory === 'function') {
            loadTenantsDirectory();
          }
        } else {
          showToast(data.message || 'Failed to restore tenant account.', 'error');
        }
      } catch (err) {
        showToast('Error communicating with server while restoring account.', 'error');
      } finally {
        btn.innerHTML = origHtml;
        btn.disabled = false;
      }
    }

    function submitPermanentDeleteArchived() {
      const tenantId = document.getElementById('archivedModalTenantId').value;
      if (!tenantId || isSubmittingOwnerDelete) return;

      const tenantName = document.getElementById('archivedPersonalName').textContent || 'this tenant';
      openOwnerDeleteConfirmModal({
        mode: 'permanent_delete',
        tenantId: parseInt(tenantId, 10),
        tenantName: tenantName
      });
    }

    async function performPermanentDeleteArchived(tenantId, tenantName, ownerPassword) {
      const btn = document.getElementById('btnPermanentDeleteArchived');
      const origHtml = btn ? btn.innerHTML : '';
      if (btn) {
        btn.innerHTML = 'Deleting...';
        btn.disabled = true;
      }

      try {
        const res = await fetch('api/tenant_directory_api.php', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({
            action: 'permanent_delete',
            tenant_id: parseInt(tenantId, 10),
            owner_password: ownerPassword
          })
        });
        const data = await res.json();
        if (res.ok && data.status === 'success') {
          showToast(data.message || `Tenant account '${tenantName}' permanently deleted.`);
          closeArchivedTenantModal();
          loadArchivedTenants();
          return { ok: true, message: data.message };
        } else {
          const errMsg = data.message || 'Failed to permanently delete tenant account.';
          showToast(errMsg, 'error');
          return { ok: false, message: errMsg };
        }
      } catch (err) {
        const errMsg = 'Error communicating with server while deleting account.';
        showToast(errMsg, 'error');
        return { ok: false, message: errMsg };
      } finally {
        if (btn) {
          btn.innerHTML = origHtml;
          btn.disabled = false;
        }
      }
    }

    // =========================================================================
    // MAINTENANCE TICKETS: FOLDER TABS, UNIFIED VIEW & LIVE BACKEND SYNC
    // =========================================================================
    window.allTicketsData = <?= json_encode($tickets, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    let activeTicketTab = 'active';
    let activeBoardStatus = 'All';
    let lastTicketsSnapshot = JSON.stringify(window.allTicketsData || []);
    const maintenanceScrollMemory = { windowY: null, boxTop: 0 };

    function normalizeTicketStatus(statusVal) {
      const st = String(statusVal || '').toLowerCase().trim();
      if (st === 'done' || st === 'resolved' || st === 'closed' || st === 'completed') return 'Done';
      if (st === 'on-going' || st === 'ongoing' || st === 'in_progress') return 'On-Going';
      return 'Pending';
    }

    function getKanbanCategoryBadgeStyle(category) {
      const key = String(category || 'General').trim().toLowerCase();
      const styles = {
        plumbing:   'background: #9E6E2E; color: #FFF8EB; border-color: #302313;',
        carpentry:  'background: #A77532; color: #FFF8EB; border-color: #302313;',
        electrical: 'background: #B98636; color: #261807; border-color: #302313;',
        hvac:       'background: #845921; color: #FFF8EB; border-color: #302313;',
        appliance:  'background: #C5954A; color: #261807; border-color: #302313;',
        structural: 'background: #734A19; color: #FFF8EB; border-color: #302313;',
        general:    'background: #B58F53; color: #261807; border-color: #302313;'
      };
      return styles[key] || 'background: #9C7136; color: #FFF8EB; border-color: #302313;';
    }

    function getKanbanPriorityBadgeStyle(priority) {
      const key = String(priority || 'medium').trim().toLowerCase();
      const styles = {
        urgent: 'background: #DC5454; color: #FFFFFF; border-color: #4A1515;',
        high:   'background: #D97724; color: #FFFFFF; border-color: #4D2507;',
        medium: 'background: #DDA844; color: #2B1B07; border-color: #4A310E;',
        low:    'background: #E7C369; color: #2B1B07; border-color: #4A310E;'
      };
      return styles[key] || styles.medium;
    }

    function captureMaintenanceScrollPositions() {
      const paneActive = document.getElementById('paneActiveTickets');
      if (!paneActive || paneActive.style.display === 'none') return;
      maintenanceScrollMemory.windowY = window.scrollY;
      const boxEl = document.getElementById('maintenanceUnifiedBox');
      if (boxEl) maintenanceScrollMemory.boxTop = boxEl.scrollTop;
    }

    function restoreMaintenanceScrollPositions() {
      requestAnimationFrame(() => {
        const boxEl = document.getElementById('maintenanceUnifiedBox');
        if (boxEl) boxEl.scrollTop = maintenanceScrollMemory.boxTop || 0;
        if (maintenanceScrollMemory.windowY !== null) {
          window.scrollTo({ top: maintenanceScrollMemory.windowY, behavior: 'auto' });
        }
      });
    }

    function handleActiveStatusDropdownChange(selectedVal) {
      const valStr = String(selectedVal || '').trim();
      if (valStr.toLowerCase() === 'all') {
        activeBoardStatus = 'All';
      } else {
        activeBoardStatus = normalizeTicketStatus(valStr) === 'On-Going' ? 'On-Going' : 'Pending';
      }
      const dropdownEl = document.getElementById('activeStatusDropdown');
      if (dropdownEl && dropdownEl.value !== activeBoardStatus) {
        dropdownEl.value = activeBoardStatus;
      }
      if (activeTicketTab !== 'active') {
        switchTicketTab('active');
      }
      renderMaintenanceUnifiedView();
      syncTicketCounters();
    }

    function switchTicketTab(tab) {
      const activeBtn = document.getElementById('btnTabActiveTickets');
      const completedBtn = document.getElementById('btnTabCompletedTickets');
      const activePane = document.getElementById('paneActiveTickets');
      const completedPane = document.getElementById('paneCompletedTickets');

      if (tab === 'completed') {
        if (activeTicketTab === 'active') {
          captureMaintenanceScrollPositions();
        }
        activeTicketTab = 'completed';
        if (activeBtn) {
          activeBtn.classList.remove('active');
          activeBtn.setAttribute('aria-selected', 'false');
        }
        if (completedBtn) {
          completedBtn.classList.add('active');
          completedBtn.setAttribute('aria-selected', 'true');
        }
        if (activePane) activePane.style.display = 'none';
        if (completedPane) completedPane.style.display = 'block';
      } else {
        activeTicketTab = 'active';
        if (completedBtn) {
          completedBtn.classList.remove('active');
          completedBtn.setAttribute('aria-selected', 'false');
        }
        if (activeBtn) {
          activeBtn.classList.add('active');
          activeBtn.setAttribute('aria-selected', 'true');
        }
        if (completedPane) completedPane.style.display = 'none';
        if (activePane) activePane.style.display = 'block';

        // Requirement: Default Active/On-going Maintenance filter to All when opened
        const dropdownEl = document.getElementById('activeStatusDropdown');
        if (dropdownEl && dropdownEl.value !== 'All') {
          dropdownEl.value = 'All';
        }
        activeBoardStatus = 'All';
        renderMaintenanceUnifiedView();
        restoreMaintenanceScrollPositions();
      }
    }

    function createMaintenanceCardHtml(tk) {
      const unitRaw = tk.unit_number ? String(tk.unit_number) : ('Unit ' + (tk.unit_id || ''));
      const unitText = unitRaw.toUpperCase();
      const catText = (tk.category ? String(tk.category) : 'General').trim();
      const prioText = (tk.priority ? String(tk.priority) : 'medium').trim();
      const dateStr = tk.created_at
        ? new Date(String(tk.created_at).replace(' ', 'T')).toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' })
        : '-';
      const hasExtra = !!(tk.photo_path || tk.resolution_notes || (tk.description && String(tk.description).trim() !== ''));

      return `
        <article class="kanban-ticket-card"
                 id="kanban-ticket-${tk.id}"
                 data-ticket-id="${tk.id}"
                 tabindex="0"
                 role="button"
                 aria-label="${escapeHtml(unitText)} - ${escapeHtml(catText)} - ${escapeHtml(prioText)} priority"
                 onclick="openTicketByIdFromCache(${tk.id})"
                 onkeydown="if(event.key==='Enter'||event.key===' '){event.preventDefault();openTicketByIdFromCache(${tk.id});}">
          <div class="kanban-card-top">
            <span class="kanban-card-unit">${escapeHtml(unitText)}</span>
            <span class="kanban-card-id">#MT-${String(tk.id).padStart(4, '0')}</span>
          </div>
          <div class="kanban-card-badges">
            <span class="kanban-pill-badge" style="${getKanbanCategoryBadgeStyle(catText)}">${escapeHtml(catText.toUpperCase())}</span>
            <span class="kanban-pill-badge" style="${getKanbanPriorityBadgeStyle(prioText)}">${escapeHtml(prioText.toUpperCase())}</span>
            ${hasExtra ? '<span class="kanban-card-more" title="Click card for full details &amp; photos">+...</span>' : ''}
          </div>
          <div class="kanban-card-title" title="${escapeHtml(tk.title || 'Maintenance Request')}">${escapeHtml(tk.title || 'Maintenance Request')}</div>
          <div class="kanban-card-meta">
            <span style="display:inline-flex;align-items:center;gap:4px;">${window.rcIcon ? window.rcIcon('user', 'w-3.5 h-3.5') : ''} ${escapeHtml(tk.tenant_name || 'Tenant')}</span>
            <span>${escapeHtml(dateStr)}</span>
          </div>
        </article>
      `;
    }

    function openTicketByIdFromCache(ticketId) {
      const idNum = parseInt(ticketId, 10);
      const found = (window.allTicketsData || []).find(t => parseInt(t.id, 10) === idNum);
      if (found) {
        openTicketDetailsModal(found);
      } else if (typeof window.openTicketDetailsById === 'function') {
        window.openTicketDetailsById(idNum);
      }
    }

    function renderMaintenanceUnifiedView() {
      const boxEl = document.getElementById('maintenanceUnifiedBox');
      const gridEl = document.getElementById('maintenanceUnifiedGrid');
      if (!boxEl || !gridEl) return;

      const prevScrollTop = boxEl.scrollTop;
      const stateLabelText = activeBoardStatus === 'All'
        ? 'All Active Maintenance'
        : (activeBoardStatus === 'On-Going' ? 'On-going Maintenance' : 'Pending Maintenance');

      boxEl.setAttribute('data-board-status', activeBoardStatus);
      const catLabel = document.getElementById('kanbanCategoryStateLabel');
      if (catLabel) catLabel.textContent = stateLabelText;

      const statusList = (window.allTicketsData || []).filter(tk => {
        const norm = normalizeTicketStatus(tk.status);
        if (activeBoardStatus === 'All') {
          return norm !== 'Done';
        }
        return norm === activeBoardStatus;
      });

      // Leave zero-data unified box completely empty (no placeholder cards or messages)
      gridEl.innerHTML = statusList.length > 0
        ? statusList.map(createMaintenanceCardHtml).join('')
        : '';

      boxEl.scrollTop = prevScrollTop;
    }

    function renderCompletedTicketsView() {
      const completedTbody = document.getElementById('completedTicketsTableBody');
      if (!completedTbody) return;

      const completed = (window.allTicketsData || []).filter(tk => normalizeTicketStatus(tk.status) === 'Done');
      if (completed.length === 0) {
        completedTbody.innerHTML = `
          <tr id="emptyCompletedTicketsRow">
            <td colspan="7" style="text-align: center; padding: 42px 14px; color: var(--text-muted); font-size: 14px;">
              <div style="margin-bottom: 8px; display: flex; justify-content: center; color: #94a3b8;">${window.rcIcon('clipboard-list', 'w-8 h-8')}</div>
              <strong>No completed maintenance records yet.</strong>
              <div style="font-size: 12px; color: #94a3b8; margin-top: 4px;">Resolved tickets will be archived here for historical review.</div>
            </td>
          </tr>
        `;
        return;
      }

      completedTbody.innerHTML = completed.map(tk => {
        const unitStr = tk.unit_number || ('Unit ' + (tk.unit_id || ''));
        const prioLower = String(tk.priority || 'medium').toLowerCase();
        const prioLabel = prioLower.charAt(0).toUpperCase() + prioLower.slice(1);
        const dateStr = tk.created_at
          ? new Date(String(tk.created_at).replace(' ', 'T')).toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' })
          : '-';
        const descRaw = tk.description ? String(tk.description) : '';
        const descPreview = descRaw.length > 60 ? descRaw.slice(0, 57) + '...' : descRaw;

        return `
          <tr class="clickable-ticket-row ticket-record-row"
              id="ticket-row-${tk.id}"
              data-ticket-id="${tk.id}"
              style="border-bottom: 1px solid rgba(166, 180, 204, 0.15);"
              onclick="openTicketByIdFromCache(${tk.id})"
              title="Click to view maintenance ticket details">
            <td style="padding: 14px 16px; font-weight: 700; color: #56380E; white-space: nowrap;">#MT-${String(tk.id).padStart(4, '0')}</td>
            <td style="padding: 14px 16px; font-weight: 600; color: #0f172a; white-space: nowrap;">${escapeHtml(tk.tenant_name || '-')}</td>
            <td style="padding: 14px 16px; white-space: nowrap;">
              <span class="clay-badge" style="background: #F3E4C6; color: #56380E; border: 1px solid rgba(181, 143, 83, 0.35); font-weight: 800; font-size: 11px;">
                ${escapeHtml(unitStr)}
              </span>
            </td>
            <td style="padding: 14px 16px; max-width: 320px;">
              <div style="font-weight: 600; color: #1e293b; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;" title="${escapeHtml(tk.title || '')}">
                ${escapeHtml(tk.title || 'Maintenance Request')}
              </div>
              ${descPreview ? `<div style="font-size: 12px; color: var(--text-muted); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; margin-top: 2px;" title="${escapeHtml(descRaw)}">${escapeHtml(descPreview)}</div>` : ''}
            </td>
            <td style="padding: 14px 16px; font-size: 12.5px; color: var(--text-muted); white-space: nowrap;">${escapeHtml(dateStr)}</td>
            <td style="padding: 14px 16px; white-space: nowrap;">
              <span class="clay-badge badge-${escapeHtml(prioLower)}" style="font-weight: 700; font-size: 11px; padding: 3px 8px;">
                ${escapeHtml(prioLabel)}
              </span>
            </td>
            <td style="padding: 14px 16px; white-space: nowrap;">
              <span id="ticket-badge-${tk.id}" class="clay-badge badge-done" style="font-weight: 700; font-size: 11.5px; padding: 4px 10px;">
                ${escapeHtml(tk.status || 'Done')}
              </span>
            </td>
          </tr>
        `;
      }).join('');
    }

    function syncTicketCounters() {
      const all = window.allTicketsData || [];
      const activeTotal = all.filter(tk => normalizeTicketStatus(tk.status) !== 'Done').length;
      const completedCount = all.filter(tk => normalizeTicketStatus(tk.status) === 'Done').length;

      const activeBadge = document.getElementById('activeTicketCountBadge');
      const completedBadge = document.getElementById('completedTicketCountBadge');
      const overviewTickets = document.getElementById('overviewOpenTickets');
      if (activeBadge) activeBadge.textContent = activeTotal;
      if (completedBadge) completedBadge.textContent = completedCount;
      if (overviewTickets) overviewTickets.textContent = activeTotal;

      const navBtn = document.getElementById('navMaintenanceTickets');
      let sidebarBadge = document.getElementById('sidebarTicketCountBadge');
      if (activeTotal > 0) {
        if (!sidebarBadge && navBtn) {
          sidebarBadge = document.createElement('span');
          sidebarBadge.className = 'sidebar-rail-badge';
          sidebarBadge.id = 'sidebarTicketCountBadge';
          navBtn.appendChild(sidebarBadge);
        }
        if (sidebarBadge) {
          sidebarBadge.textContent = activeTotal;
          sidebarBadge.style.display = '';
        }
      } else if (sidebarBadge) {
        sidebarBadge.style.display = 'none';
      }

      if (typeof OWNER_SEARCH_TICKETS !== 'undefined' && Array.isArray(OWNER_SEARCH_TICKETS)) {
        OWNER_SEARCH_TICKETS.splice(0, OWNER_SEARCH_TICKETS.length, ...all);
      }
    }

    async function fetchMaintenanceTickets(preserveState = true) {
      try {
        const res = await fetch('api/ticket_api.php', { cache: 'no-store' });
        if (!res.ok) return;
        const data = await res.json();
        if (data && data.status === 'success' && Array.isArray(data.tickets)) {
          const normalized = data.tickets.map(t => ({
            ...t,
            status: normalizeTicketStatus(t.status)
          }));
          const nextSnap = JSON.stringify(normalized);
          if (preserveState && nextSnap === lastTicketsSnapshot) {
            return;
          }
          lastTicketsSnapshot = nextSnap;
          window.allTicketsData = normalized;
          renderMaintenanceUnifiedView();
          renderCompletedTicketsView();
          syncTicketCounters();
        }
      } catch (err) {}
    }

    // Direct Status Update
    async function updateTicketStatus(id, newStatus) {
      const idNum = parseInt(id, 10);
      const cachedTk = (window.allTicketsData || []).find(t => parseInt(t.id, 10) === idNum);
      if (cachedTk && normalizeTicketStatus(cachedTk.status) === 'Done') {
        showToast(`Ticket #MT-${String(id).padStart(4, '0')} is marked as Done and its status is permanently locked.`, 'warning');
        return;
      }
      const normStatus = normalizeTicketStatus(newStatus);

      try {
        const res = await fetch('api/ticket_api.php', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ action: 'update_status', ticket_id: parseInt(id, 10), status: normStatus })
        });
        const result = await res.json();
        if (result.status === 'success') {
          showToast(`Ticket #MT-${String(id).padStart(4, '0')} updated to ${normStatus}.`);
          moveTicketRowInDOM(id, normStatus);
        } else {
          showToast(result.message || 'Failed to update ticket status', 'error');
        }
      } catch (e) {
        showToast('Error updating ticket status', 'error');
      }
    }

    // Sync cached ticket state, unified view, Completed view, and badges
    function moveTicketRowInDOM(ticketId, status, replyNotes = null) {
      const idNum = parseInt(ticketId, 10);
      const normStatus = normalizeTicketStatus(status);
      const found = (window.allTicketsData || []).find(t => parseInt(t.id, 10) === idNum);
      if (found) {
        found.status = normStatus;
        if (replyNotes !== null) {
          found.resolution_notes = replyNotes;
        }
        found.resolved_at = normStatus === 'Done' ? new Date().toISOString() : null;
      }

      lastTicketsSnapshot = JSON.stringify(window.allTicketsData || []);
      renderMaintenanceUnifiedView();
      renderCompletedTicketsView();
      syncTicketCounters();
    }

    function updateTicket(id, status, cost) {
      updateTicketStatus(id, status);
    }

    // Render initial unified empty/wired view immediately from SSR payload
    renderMaintenanceUnifiedView();

    // =========================================================================
    // ANIMATED MAINTENANCE TICKET DETAILS MODAL
    // =========================================================================
    function openTicketDetailsModal(tk) {
      if (!tk) return;

      // 1. Identifiers and Header
      const idVal = tk.id;
      const ticketIdInput = document.getElementById('mdlTicketId');
      if (ticketIdInput) ticketIdInput.value = idVal;
      
      const badgeNumber = document.getElementById('mdlTicketNumberBadge');
      if (badgeNumber) badgeNumber.textContent = '#MT-' + String(idVal).padStart(4, '0');

      const titleEl = document.getElementById('mdlTicketTitle');
      if (titleEl) titleEl.textContent = tk.title || 'Maintenance Request';

      // 2. Submission Date
      let dateSub = '-';
      if (tk.created_at) {
        dateSub = new Date(tk.created_at.replace(' ', 'T')).toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' });
      }
      const dateSubEl = document.getElementById('mdlDateSubmitted');
      if (dateSubEl) dateSubEl.textContent = dateSub;

      // 3. Category & Priority Badges
      const catBadge = document.getElementById('mdlCategoryBadge');
      if (catBadge) {
        catBadge.textContent = 'Category: ' + (tk.category || 'General');
        catBadge.className = 'clay-badge badge-' + String(tk.category || 'general').toLowerCase();
      }

      const prioBadge = document.getElementById('mdlPriorityBadge');
      if (prioBadge) {
        const p = String(tk.priority || 'medium').toLowerCase();
        prioBadge.textContent = 'Priority: ' + p.charAt(0).toUpperCase() + p.slice(1);
        prioBadge.className = 'clay-badge badge-' + p;
      }

      // 4. Status Badge & Select
      const rawStatus = String(tk.status || '').toLowerCase().trim();
      const isCompleted = (rawStatus === 'done' || rawStatus === 'completed' || rawStatus === 'resolved' || rawStatus === 'closed');
      const normStatus = isCompleted
        ? 'Done'
        : ((rawStatus === 'on-going' || rawStatus === 'ongoing' || rawStatus === 'in_progress') ? 'On-Going' : 'Pending');

      const statusBadge = document.getElementById('mdlStatusBadge');
      if (statusBadge) {
        statusBadge.textContent = 'Status: ' + (isCompleted ? 'Completed' : normStatus);
        statusBadge.className = 'clay-badge ' + (isCompleted ? 'badge-done' : (normStatus === 'On-Going' ? 'badge-ongoing' : 'badge-pending'));
      }

      const headingIcon = document.getElementById('mdlStatusHeadingIcon');
      const headingText = document.getElementById('mdlStatusHeadingText');
      if (headingText) {
        headingText.textContent = isCompleted ? 'Maintenance Status & Remarks' : 'Update Maintenance Status';
      }
      if (headingIcon) {
        headingIcon.setAttribute('data-lucide', isCompleted ? 'check-circle-2' : 'settings');
      }

      const selStatus = document.getElementById('mdlSelectStatus');
      const statusHelpText = document.getElementById('mdlStatusHelpText');
      const statusLockedNotice = document.getElementById('mdlStatusLockedNotice');
      if (selStatus) {
        selStatus.value = normStatus;
        selStatus.disabled = isCompleted;
        if (isCompleted) {
          selStatus.classList.add('opacity-60', 'cursor-not-allowed');
          selStatus.setAttribute('aria-readonly', 'true');
        } else {
          selStatus.classList.remove('opacity-60', 'cursor-not-allowed');
          selStatus.removeAttribute('aria-readonly');
        }
      }
      if (statusHelpText) {
        statusHelpText.style.display = isCompleted ? 'none' : 'block';
      }
      if (statusLockedNotice) {
        statusLockedNotice.style.display = isCompleted ? 'flex' : 'none';
      }

      // 5. Tenant Info
      const tenantNameEl = document.getElementById('mdlTenantName');
      if (tenantNameEl) tenantNameEl.textContent = tk.tenant_name || '-';

      const unitBadge = document.getElementById('mdlUnitBadge');
      if (unitBadge) unitBadge.textContent = tk.unit_number || '-';

      const tenantEmailEl = document.getElementById('mdlTenantEmail');
      if (tenantEmailEl) tenantEmailEl.textContent = tk.tenant_email || 'Not on file';

      // 6. Complete Issue Description
      const descEl = document.getElementById('mdlDescription');
      if (descEl) descEl.textContent = tk.description || tk.title || 'No additional description provided.';

      // 7. Resolution Notes (Owner Remarks / Instructions)
      const notesEl = document.getElementById('mdlResolutionNotes');
      if (notesEl) {
        const rawNotes = (tk.resolution_notes || '').trim();
        if (isCompleted) {
          notesEl.value = rawNotes;
          notesEl.readOnly = true;
          notesEl.disabled = true;
          notesEl.placeholder = rawNotes ? '' : 'No owner remarks provided.';
          notesEl.classList.add('opacity-75', 'cursor-not-allowed');
          notesEl.style.backgroundColor = '#f8fafc';
          notesEl.style.color = rawNotes ? '#1e293b' : '#64748b';
          notesEl.style.borderColor = 'rgba(166, 180, 204, 0.4)';
        } else {
          notesEl.value = rawNotes;
          notesEl.readOnly = false;
          notesEl.disabled = false;
          notesEl.placeholder = 'Add instructions, contractor notes, or an update for the tenant...';
          notesEl.classList.remove('opacity-75', 'cursor-not-allowed');
          notesEl.style.backgroundColor = '';
          notesEl.style.color = '';
          notesEl.style.borderColor = '';
        }
      }

      // 8. Action Buttons (Read-Only Close for Completed, Cancel + Save for Active)
      const activeActions = document.getElementById('mdlActiveActions');
      const completedActions = document.getElementById('mdlCompletedActions');
      const btnSave = document.getElementById('btnModalSubmitStatus');
      const btnCancel = document.getElementById('btnModalCancel');
      const btnCloseOnly = document.getElementById('btnModalCloseOnly');

      if (isCompleted) {
        if (activeActions) activeActions.style.display = 'none';
        if (btnSave) btnSave.style.display = 'none';
        if (btnCancel) btnCancel.style.display = 'none';
        if (completedActions) completedActions.style.display = 'flex';
        if (btnCloseOnly) btnCloseOnly.style.display = 'inline-flex';
      } else {
        if (activeActions) activeActions.style.display = 'flex';
        if (btnSave) btnSave.style.display = 'inline-flex';
        if (btnCancel) btnCancel.style.display = 'inline-flex';
        if (completedActions) completedActions.style.display = 'none';
        if (btnCloseOnly) btnCloseOnly.style.display = 'none';
      }

      // 8. Attached Photos Gallery
      const photosContainer = document.getElementById('mdlPhotosContainer');
      const noPhotosNotice = document.getElementById('mdlNoPhotosNotice');
      if (photosContainer) {
        photosContainer.innerHTML = '';
        if (tk.photo_path && tk.photo_path.trim() !== '') {
          if (noPhotosNotice) noPhotosNotice.style.display = 'none';
          photosContainer.style.display = 'flex';
          const pList = tk.photo_path.split(',');
          pList.forEach((pItem, idx) => {
            pItem = pItem.trim();
            if (!pItem) return;
            const card = document.createElement('div');
            card.style.cssText = 'border: 1.5px solid rgba(166,180,204,0.35); border-radius: 10px; overflow: hidden; background: #fff; box-shadow: 0 4px 10px rgba(0,0,0,0.06); text-align: center; max-width: 160px;';
            card.innerHTML = `
              <a href="${pItem}" target="_blank" title="Click to view full image in a new tab" style="display: block; text-decoration: none;">
                <img src="${pItem}" alt="Ticket Attachment" style="width: 150px; height: 110px; object-fit: cover; display: block;" onerror="this.onerror=null;this.parentElement.innerHTML='<div style=\\'padding:14px;font-size:12px;color:#56380E;display:inline-flex;align-items:center;gap:4px;\\'>' + window.rcIcon('image', 'w-4 h-4') + ' Photo ' + (${pList.length > 1 ? (idx + 1) : ''}) + '</div>';">
                <div style="font-size: 11.5px; font-weight: 700; color: #56380E; padding: 7px 6px; background: #FDF7EC; border-top: 1px solid rgba(166,180,204,0.2); display: flex; align-items: center; justify-content: center; gap: 4px;">
                  ${window.rcIcon('zoom-in', 'w-3.5 h-3.5')} View Photo ${pList.length > 1 ? (idx + 1) : ''}
                </div>
              </a>
            `;
            photosContainer.appendChild(card);
          });
        } else {
          photosContainer.style.display = 'none';
          if (noPhotosNotice) noPhotosNotice.style.display = 'block';
        }
      }

      // 9. Open Modal with Animation & Lock Body Scroll
      openModal('ticketDetailsModal');
    }

    function closeTicketDetailsModal(fromCloseModal = false) {
      const modal = document.getElementById('ticketDetailsModal');
      if (modal) {
        modal.classList.add('hidden');
        modal.classList.remove('open', 'active');
        modal.style.display = 'none';
        modal.style.pointerEvents = 'none';
        document.body.style.overflow = '';
      }
      if (!fromCloseModal && typeof closeModal === 'function') {
        closeModal('ticketDetailsModal');
      }
    }

    function handleTicketModalBackdropClick(event) {
      if (event.target === document.getElementById('ticketDetailsModal')) {
        closeTicketDetailsModal();
      }
    }

    // Universal Escape key listener for closing modals
    document.addEventListener('keydown', function(e) {
      if (e.key === 'Escape' || e.keyCode === 27) {
        if (document.getElementById('ownerDeleteConfirmModal')?.classList.contains('open') || document.getElementById('ownerDeleteConfirmModal')?.classList.contains('active')) {
          closeOwnerDeleteConfirmModal();
          return;
        }
        if (document.getElementById('moveOutConfirmModal')?.classList.contains('open') || document.getElementById('moveOutConfirmModal')?.classList.contains('active')) {
          closeMoveOutConfirmModal();
          return;
        }
        const openBackdrops = document.querySelectorAll('.modal-backdrop.open, .modal-backdrop:not(.hidden):not([style*="display: none"])');
        if (openBackdrops.length > 0) {
          const topModal = openBackdrops[openBackdrops.length - 1];
          if (topModal && topModal.id && typeof window.closeModal === 'function') {
            window.closeModal(topModal.id);
          }
        }
      }
    });

    // Dedicated Status Update inside Modal
    async function submitModalStatusUpdate() {
      const btn = document.getElementById('btnModalSubmitStatus');
      const id = document.getElementById('mdlTicketId').value;
      const status = document.getElementById('mdlSelectStatus').value;
      const reply = document.getElementById('mdlResolutionNotes').value.trim();

      if (!id) return;

      const cachedTk = (window.allTicketsData || []).find(t => parseInt(t.id, 10) === parseInt(id, 10));
      const wasDone = cachedTk && (
        normalizeTicketStatus(cachedTk.status) === 'Done' ||
        ['done', 'completed', 'resolved', 'closed'].includes(String(cachedTk.status || '').toLowerCase().trim())
      );
      if (wasDone) {
        showToast('Completed maintenance requests are read-only and cannot be modified.', 'warning');
        return;
      }

      const origText = btn.innerHTML;
      btn.innerHTML = '<span>Saving...</span>';
      btn.disabled = true;

      try {
        const res = await fetch('api/ticket_api.php', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({
            action: 'reply_ticket',
            ticket_id: parseInt(id, 10),
            status: status,
            reply: reply
          })
        });
        const result = await res.json();
        if (result.status === 'success') {
          showToast(result.message || `Ticket #MT-${String(id).padStart(4, '0')} updated to ${status}.`);

          // Update modal status badge if still open
          const statusBadge = document.getElementById('mdlStatusBadge');
          if (statusBadge) {
            statusBadge.textContent = 'Status: ' + status;
            statusBadge.className = 'clay-badge ' + (status === 'Done' ? 'badge-done' : (status === 'On-Going' ? 'badge-ongoing' : 'badge-pending'));
          }

          // Immediately reflect in the Kanban board, Completed view, and ticket counters
          const savedNotes = result.ticket && typeof result.ticket.resolution_notes !== 'undefined'
            ? result.ticket.resolution_notes
            : (reply !== '' ? reply : null);
          moveTicketRowInDOM(id, status, savedNotes);

          // Close modal
          closeTicketDetailsModal();
        } else {
          showToast(result.message || 'Failed to update ticket status', 'error');
        }
      } catch (err) {
        showToast('Error communicating with ticket service', 'error');
      } finally {
        btn.innerHTML = origText;
        btn.disabled = false;
      }
    }

    // Direct lookup helper (used by notifications and URL parameters)
    window.openTicketDetailsById = async function(ticketId) {
      const idNum = parseInt(ticketId, 10);
      if (typeof switchSection === 'function') {
        const navBtn = document.getElementById('navMaintenanceTickets');
        switchSection('sectionTickets', navBtn, false);
      }
      const cachedTk = (window.allTicketsData || []).find(t => parseInt(t.id, 10) === idNum);
      if (cachedTk) {
        const normStatus = normalizeTicketStatus(cachedTk.status);
        if (normStatus === 'Done') {
          switchTicketTab('completed');
        } else {
          handleActiveStatusDropdownChange(normStatus);
        }
        openTicketDetailsModal(cachedTk);
        return;
      }
      try {
        const res = await fetch(`api/ticket_api.php?ticket_id=${idNum}`);
        const data = await res.json();
        if (data.status === 'success' && data.ticket) {
          const normStatus = normalizeTicketStatus(data.ticket.status);
          if (normStatus === 'Done') {
            switchTicketTab('completed');
          } else {
            handleActiveStatusDropdownChange(normStatus);
          }
          openTicketDetailsModal(data.ticket);
        } else {
          showToast('Ticket not found', 'error');
        }
      } catch(err) {
        showToast('Error retrieving ticket details', 'error');
      }
    };

    // =========================================================================
    // GENERAL TENANT REPORTS MANAGEMENT (OWNER PORTAL)
    // =========================================================================
    window.allReportsData = <?= json_encode($tenantReports, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    let currentReportFolderTab = 'pending';

    function switchReportTab(tab) {
      currentReportFolderTab = (tab === 'reviewed') ? 'reviewed' : 'pending';
      const pendingBtn = document.getElementById('btnTabPendingReports');
      const reviewedBtn = document.getElementById('btnTabReviewedReports');
      const pendingPane = document.getElementById('panePendingReports');
      const reviewedPane = document.getElementById('paneReviewedReports');
      const stateLabel = document.getElementById('reportsFolderStateLabel');

      const isReviewed = currentReportFolderTab === 'reviewed';
      if (pendingBtn) {
        pendingBtn.classList.toggle('active', !isReviewed);
        pendingBtn.setAttribute('aria-selected', !isReviewed ? 'true' : 'false');
      }
      if (reviewedBtn) {
        reviewedBtn.classList.toggle('active', isReviewed);
        reviewedBtn.setAttribute('aria-selected', isReviewed ? 'true' : 'false');
      }
      if (pendingPane) pendingPane.style.display = isReviewed ? 'none' : 'block';
      if (reviewedPane) reviewedPane.style.display = isReviewed ? 'block' : 'none';
      if (stateLabel) {
        stateLabel.textContent = isReviewed ? 'Reviewed Tenant Reports' : 'Pending Tenant Reports';
      }
    }

    function updateReportCounters() {
      const all = Array.isArray(window.allReportsData) ? window.allReportsData : [];
      const pending = all.filter(r => (r.status || 'Pending') === 'Pending');
      const reviewed = all.filter(r => (r.status || 'Pending') !== 'Pending');

      const pendBadge = document.getElementById('pendingReportsCountBadge');
      const revBadge = document.getElementById('reviewedReportsCountBadge');
      const sideBadge = document.getElementById('sidebarReportCountBadge');

      if (pendBadge) pendBadge.textContent = pending.length;
      if (revBadge) revBadge.textContent = reviewed.length;
      if (sideBadge) {
        sideBadge.textContent = pending.length;
        sideBadge.style.display = pending.length > 0 ? 'inline-flex' : 'none';
      }

      if (typeof OWNER_SEARCH_REPORTS !== 'undefined' && Array.isArray(OWNER_SEARCH_REPORTS)) {
        OWNER_SEARCH_REPORTS.splice(0, OWNER_SEARCH_REPORTS.length, ...all);
      }
    }

    function renderReportsCardGrid(gridEl, list, emptyTitle, emptySub) {
      if (!gridEl) return;
      gridEl.innerHTML = '';
      if (!list || list.length === 0) {
        gridEl.innerHTML = `
          <div class="kanban-empty-placeholder" style="grid-column: 1 / -1;">
            <span>${window.rcIcon('clipboard-list', 'w-7 h-7')}</span>
            <strong style="font-size: 13.5px; color: #302313;">${escapeHtml(emptyTitle)}</strong>
            <span>${escapeHtml(emptySub)}</span>
          </div>
        `;
        return;
      }

      list.forEach(rep => {
        const card = document.createElement('div');
        card.className = 'kanban-ticket-card';
        card.tabIndex = 0;
        card.setAttribute('role', 'button');

        const st = String(rep.status || 'Pending');
        const stSlug = st.toLowerCase();
        const unitText = rep.unit_number ? `UNIT ${String(rep.unit_number).toUpperCase()}` : 'UNIT -';
        const catText = rep.category || 'General Inquiry';
        const dateStr = rep.created_at
          ? new Date(String(rep.created_at).replace(' ', 'T')).toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' })
          : '-';

        card.innerHTML = `
          <div class="kanban-card-header">
            <span class="kanban-card-unit">${escapeHtml(unitText)}</span>
            <span class="report-status-pill report-status-pill--${escapeHtml(stSlug)}">${escapeHtml(st.toUpperCase())}</span>
          </div>
          <div style="margin-bottom: 6px;">
            <span class="report-category-pill">${escapeHtml(catText)}</span>
          </div>
          <div class="kanban-card-title" title="${escapeHtml(rep.subject || 'General Report')}">${escapeHtml(rep.subject || 'General Report')}</div>
          <div class="kanban-card-meta">
            <span style="display:inline-flex;align-items:center;gap:4px;">${window.rcIcon('user', 'w-3.5 h-3.5')} ${escapeHtml(rep.tenant_name || 'Tenant')}</span>
            <span>${escapeHtml(dateStr)}</span>
          </div>
        `;
        card.onclick = () => openReportDetailsModal(rep);
        card.onkeydown = (ev) => {
          if (ev.key === 'Enter' || ev.key === ' ') {
            ev.preventDefault();
            openReportDetailsModal(rep);
          }
        };
        gridEl.appendChild(card);
      });
    }

    function renderReportsFolderViews() {
      const all = Array.isArray(window.allReportsData) ? window.allReportsData : [];
      const filterEl = document.getElementById('reportsCategoryFilter');
      const catFilter = filterEl ? filterEl.value : 'ALL';

      const matchesCat = (r) => catFilter === 'ALL' || String(r.category || '') === catFilter;
      const pendingList = all.filter(r => (r.status || 'Pending') === 'Pending' && matchesCat(r));
      const reviewedList = all.filter(r => (r.status || 'Pending') !== 'Pending' && matchesCat(r));

      renderReportsCardGrid(
        document.getElementById('reportsPendingGrid'),
        pendingList,
        'No pending general reports',
        catFilter === 'ALL' ? 'New tenant requests will appear here for review.' : `No pending reports under "${catFilter}".`
      );
      renderReportsCardGrid(
        document.getElementById('reportsReviewedGrid'),
        reviewedList,
        'No reviewed general reports yet',
        catFilter === 'ALL' ? 'Approved, acknowledged, and rejected reports appear here.' : `No reviewed reports under "${catFilter}".`
      );
      updateReportCounters();
    }

    async function fetchTenantReports(silent = true) {
      try {
        const res = await fetch('api/report_api.php');
        const data = await res.json();
        if (data.status === 'success' && Array.isArray(data.reports)) {
          window.allReportsData = data.reports;
          renderReportsFolderViews();
        }
      } catch (err) {
        if (!silent) showToast('Failed to fetch tenant reports', 'error');
      }
    }

    function openReportDetailsModal(rep) {
      const modal = document.getElementById('reportDetailsModal');
      if (!modal || !rep) return;
      window.currentViewingReport = rep;

      const st = String(rep.status || 'Pending');
      const stSlug = st.toLowerCase().trim();
      const isReviewed = (stSlug !== 'pending' && stSlug !== 'open') || Boolean(rep.reviewed_at && String(rep.reviewed_at).trim() !== '');

      document.getElementById('mdlReportId').value = rep.id || '';
      document.getElementById('mdlReportIdBadge').textContent = `#GR-${String(rep.id || 0).padStart(4, '0')}`;
      document.getElementById('mdlReportCategory').textContent = rep.category || 'General Inquiry';

      const pillEl = document.getElementById('mdlReportStatusPill');
      pillEl.textContent = st.toUpperCase();
      pillEl.className = `report-status-pill report-status-pill--${stSlug}`;

      document.getElementById('mdlReportSubject').textContent = rep.subject || 'General Report';
      const createdFmt = rep.created_at
        ? new Date(String(rep.created_at).replace(' ', 'T')).toLocaleString('en-US', { month: 'short', day: 'numeric', year: 'numeric', hour: 'numeric', minute: '2-digit' })
        : '-';
      document.getElementById('mdlReportDate').textContent = `Submitted on ${createdFmt}`;

      const tName = rep.tenant_name || 'Resident';
      document.getElementById('mdlReportTenantAvatar').textContent = tName.trim().charAt(0).toUpperCase() || 'T';
      document.getElementById('mdlReportTenantName').textContent = tName;
      document.getElementById('mdlReportUnitBadge').textContent = rep.unit_number ? `Unit ${rep.unit_number}` : 'Unassigned';
      document.getElementById('mdlReportTenantMeta').textContent = [rep.tenant_phone || '', rep.tenant_email || ''].filter(Boolean).join(' • ') || 'Registered Tenant';
      document.getElementById('mdlReportDetails').textContent = rep.details || 'No details provided.';

      const attSec = document.getElementById('mdlReportAttachmentsSection');
      const attCont = document.getElementById('mdlReportAttachmentsContainer');
      attCont.innerHTML = '';
      if (rep.attachment_path && String(rep.attachment_path).trim() !== '') {
        const files = String(rep.attachment_path).split(',').map(s => s.trim()).filter(Boolean);
        if (files.length > 0) {
          attSec.style.display = 'block';
          files.forEach(src => {
            const link = document.createElement('a');
            link.href = src;
            link.target = '_blank';
            link.rel = 'noopener';
            if (src.toLowerCase().endsWith('.pdf')) {
              link.style.cssText = 'display:inline-flex;align-items:center;gap:6px;padding:8px 12px;border-radius:8px;background:#FFF9EE;border:1.5px solid rgba(181,143,83,0.4);color:#56380E;font-size:12px;font-weight:700;text-decoration:none;';
              link.innerHTML = `${window.rcIcon('file-text', 'w-4 h-4')} Open PDF Attachment`;
            } else {
              const img = document.createElement('img');
              img.src = src;
              img.alt = 'Report attachment';
              img.style.cssText = 'width:84px;height:84px;object-fit:cover;border-radius:10px;border:1.5px solid rgba(86,56,14,0.22);';
              link.appendChild(img);
            }
            attCont.appendChild(link);
          });
        } else {
          attSec.style.display = 'none';
        }
      } else {
        attSec.style.display = 'none';
      }

      // Owner Remarks & Conditional Action Buttons
      const remarksEl = document.getElementById('mdlReportRemarks');
      const actionBtns = document.getElementById('mdlReportActionButtons');
      const reviewedActions = document.getElementById('mdlReportReviewedActions');
      const btnCloseOnly = document.getElementById('btnReportCloseOnly');
      const rawRemarks = String(rep.owner_remarks || '').trim();

      if (isReviewed) {
        if (actionBtns) actionBtns.style.display = 'none';
        if (reviewedActions) reviewedActions.style.display = 'flex';
        if (btnCloseOnly) btnCloseOnly.style.display = 'inline-flex';
        if (remarksEl) {
          remarksEl.value = rawRemarks;
          remarksEl.readOnly = true;
          remarksEl.disabled = true;
          remarksEl.placeholder = rawRemarks ? '' : 'No owner remarks provided.';
          remarksEl.style.backgroundColor = '#f8fafc';
          remarksEl.style.cursor = 'default';
          remarksEl.style.color = rawRemarks ? '#1e293b' : '#64748b';
          remarksEl.style.borderColor = 'rgba(166, 180, 204, 0.4)';
        }
      } else {
        if (actionBtns) actionBtns.style.display = '';
        if (reviewedActions) reviewedActions.style.display = 'none';
        if (btnCloseOnly) btnCloseOnly.style.display = 'none';
        if (remarksEl) {
          remarksEl.value = rawRemarks;
          remarksEl.readOnly = false;
          remarksEl.disabled = false;
          remarksEl.placeholder = 'Write approval conditions, lease addendum notes, or reason for rejection...';
          remarksEl.style.backgroundColor = '';
          remarksEl.style.cursor = '';
          remarksEl.style.color = '';
          remarksEl.style.borderColor = '';
        }
      }

      const revMeta = document.getElementById('mdlReportReviewedMeta');
      if (rep.reviewed_at) {
        const revFmt = new Date(String(rep.reviewed_at).replace(' ', 'T')).toLocaleString('en-US', { month: 'short', day: 'numeric', year: 'numeric', hour: 'numeric', minute: '2-digit' });
        revMeta.textContent = `Reviewed on ${revFmt}${rep.reviewer_name ? ' by ' + rep.reviewer_name : ''}`;
      } else {
        revMeta.textContent = 'Not yet reviewed';
      }

      openModal('reportDetailsModal');
    }

    function closeReportDetailsModal(fromCloseModal = false) {
      const modal = document.getElementById('reportDetailsModal');
      if (modal) {
        modal.classList.add('hidden');
        modal.classList.remove('open', 'active');
        modal.style.display = 'none';
        modal.style.pointerEvents = 'none';
        document.body.style.overflow = '';
      }
      if (!fromCloseModal && typeof closeModal === 'function') {
        closeModal('reportDetailsModal');
      }
    }

    function handleReportModalBackdropClick(event) {
      if (event.target === document.getElementById('reportDetailsModal')) {
        closeReportDetailsModal();
      }
    }

    async function handleReportDecision(decision) {
      if (window.currentViewingReport) {
        const curSt = String(window.currentViewingReport.status || 'Pending').toLowerCase().trim();
        const curIsReviewed = (curSt !== 'pending' && curSt !== 'open') || Boolean(window.currentViewingReport.reviewed_at && String(window.currentViewingReport.reviewed_at).trim() !== '');
        if (curIsReviewed) {
          showToast('This report has already been reviewed and cannot be modified.', 'warning');
          return;
        }
      }
      const id = document.getElementById('mdlReportId').value;
      const remarks = document.getElementById('mdlReportRemarks').value.trim();
      if (!id) return;

      const btns = [
        document.getElementById('btnReportApprove'),
        document.getElementById('btnReportAcknowledge'),
        document.getElementById('btnReportReject')
      ].filter(Boolean);
      btns.forEach(b => { b.disabled = true; });

      try {
        const res = await fetch('api/report_api.php', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({
            action: 'review_report',
            report_id: parseInt(id, 10),
            status: decision,
            owner_remarks: remarks
          })
        });
        const result = await res.json();
        if (res.ok && result.status === 'success' && result.report) {
          showToast(result.message || `Report #GR-${String(id).padStart(4, '0')} marked ${decision}.`);
          const updated = result.report;
          const idx = (window.allReportsData || []).findIndex(r => parseInt(r.id, 10) === parseInt(id, 10));
          if (idx >= 0) {
            window.allReportsData[idx] = updated;
          } else if (Array.isArray(window.allReportsData)) {
            window.allReportsData.unshift(updated);
          }
          renderReportsFolderViews();
          closeReportDetailsModal();
        } else {
          showToast(result.message || 'Failed to update report status', 'error');
        }
      } catch (err) {
        showToast('Error communicating with report service', 'error');
      } finally {
        btns.forEach(b => { b.disabled = false; });
      }
    }

    window.openReportDetailsById = async function(reportId) {
      const idNum = parseInt(reportId, 10);
      if (typeof switchSection === 'function') {
        const navBtn = document.getElementById('navTenantReports');
        switchSection('sectionReports', navBtn, false);
      }
      const cached = (window.allReportsData || []).find(r => parseInt(r.id, 10) === idNum);
      if (cached) {
        switchReportTab((cached.status || 'Pending') === 'Pending' ? 'pending' : 'reviewed');
        openReportDetailsModal(cached);
        return;
      }
      try {
        const res = await fetch(`api/report_api.php?report_id=${idNum}`);
        const data = await res.json();
        if (data.status === 'success' && data.report) {
          switchReportTab((data.report.status || 'Pending') === 'Pending' ? 'pending' : 'reviewed');
          openReportDetailsModal(data.report);
        } else {
          showToast('Report not found', 'error');
        }
      } catch (err) {
        showToast('Error retrieving report details', 'error');
      }
    };

    // Forwarding functions for backwards compatibility
    window.openTicketDetailPage = openTicketDetailsModal;
    window.closeTicketDetailPage = closeTicketDetailsModal;
    window.submitTicketStatusUpdateFromPage = submitModalStatusUpdate;
    function openTicketReplyModal(id, title, tenant, unit, status, cost, notes) {
      openTicketDetailsModal({
        id: id,
        title: title,
        tenant_name: tenant,
        unit_number: unit,
        status: status,
        resolution_notes: notes,
        category: 'General',
        priority: 'medium',
        description: notes || title
      });
    }
    function closeTicketReplyModal() {
      closeTicketDetailsModal();
    }
    function submitTicketReply() {
      return submitModalStatusUpdate();
    }

    // Rent Due Announcement Modal Controls
    function openAnnouncementModal() {
      document.getElementById('announcementForm').reset();
      document.getElementById('announcementUnitsContainer').style.display = 'none';
      openModal('announcementModal');
    }

    function closeAnnouncementModal(fromCloseModal = false) {
      const modal = document.getElementById('announcementModal');
      if (modal) {
        modal.classList.add('hidden');
        modal.classList.remove('open', 'active');
        modal.style.display = 'none';
        modal.style.pointerEvents = 'none';
        document.body.style.overflow = '';
      }
      if (!fromCloseModal && typeof closeModal === 'function') {
        closeModal('announcementModal');
      }
    }

    function toggleAnnouncementUnitSelector(val) {
      const container = document.getElementById('announcementUnitsContainer');
      if (container) {
        container.style.display = (val === 'selected') ? 'block' : 'none';
      }
    }

    async function submitAnnouncement() {
      const btn = document.getElementById('btnSubmitAnnouncement');
      const title = document.getElementById('announcementTitle').value.trim();
      const dueDate = document.getElementById('announcementDueDate').value;
      const targetType = document.getElementById('announcementTargetType').value;
      const message = document.getElementById('announcementMessage').value.trim();

      if (!title || !message) {
        showToast('Please fill in title and message.', 'error');
        return;
      }

      let selectedUnits = [];
      if (targetType === 'selected') {
        const checked = document.querySelectorAll('input[name="announcement_units[]"]:checked');
        checked.forEach(cb => selectedUnits.push(parseInt(cb.value)));
        if (selectedUnits.length === 0) {
          showToast('Please select at least one unit.', 'error');
          return;
        }
      }

      const origText = btn.innerHTML;
      btn.innerHTML = 'Sending Emails...';
      btn.disabled = true;

      try {
        const res = await fetch('api/announcement_api.php', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({
            title: title,
            due_date: dueDate,
            target_type: targetType,
            target_units: selectedUnits,
            message: message
          })
        });
        const result = await res.json();
        if (result.status === 'success') {
          showToast(result.message);
          closeAnnouncementModal();
        } else {
          showToast(result.message || 'Failed to send announcement', 'error');
        }
      } catch (e) {
        showToast('Network error while sending announcement', 'error');
      } finally {
        btn.innerHTML = origText;
        btn.disabled = false;
      }
    }

    // =========================================================================
    // PAYMENT VERIFICATION & AUDIT MODAL CONTROLS & FILTERING
    // =========================================================================
    let allPaymentsData = <?= json_encode($payments) ?>;
    let isPaymentVerificationInProgress = false;
    let currentViewingPayment = null;
    let currentPaymentFolderTab = 'pending';

    function switchPaymentTab(tab) {
      currentPaymentFolderTab = (tab === 'history') ? 'history' : 'pending';
      const pendingBtn = document.getElementById('btnTabPendingPayments');
      const historyBtn = document.getElementById('btnTabPaymentHistory');
      const pendingPane = document.getElementById('panePendingPayments');
      const historyPane = document.getElementById('panePaymentHistory');

      const isHistory = currentPaymentFolderTab === 'history';
      if (pendingBtn) {
        pendingBtn.classList.toggle('active', !isHistory);
        pendingBtn.setAttribute('aria-selected', !isHistory ? 'true' : 'false');
      }
      if (historyBtn) {
        historyBtn.classList.toggle('active', isHistory);
        historyBtn.setAttribute('aria-selected', isHistory ? 'true' : 'false');
      }
      if (pendingPane) pendingPane.style.display = isHistory ? 'none' : 'block';
      if (historyPane) historyPane.style.display = isHistory ? 'block' : 'none';
    }

    // Filter payments ledger table by Status, Month, Year, and Search
    function applyPaymentFilters() {
      const selectedStatus = document.getElementById('paymentFilterStatus')?.value || 'all';
      const selectedMonth = document.getElementById('paymentFilterMonth')?.value || 'all';
      const selectedYear = document.getElementById('paymentFilterYear')?.value || 'all';
      const searchQuery = (document.getElementById('paymentHistorySearchInput')?.value || '').trim().toLowerCase();
      const rows = document.querySelectorAll('.payment-record-row');
      let visibleCount = 0;

      rows.forEach(row => {
        const rowStatus = (row.getAttribute('data-status') || '').toLowerCase();
        const rowMonth = row.getAttribute('data-month') || '';
        const rowYear = row.getAttribute('data-year') || '';
        const rowTenant = (row.getAttribute('data-tenant-name') || '').toLowerCase();
        const rowUnit = (row.getAttribute('data-unit') || '').toLowerCase();

        const statusMatch = (selectedStatus === 'all') || (rowStatus === selectedStatus.toLowerCase());
        const monthMatch = (selectedMonth === 'all') || (rowMonth === selectedMonth);
        const yearMatch = (selectedYear === 'all') || (rowYear === selectedYear);
        const searchMatch = !searchQuery || rowTenant.includes(searchQuery) || rowUnit.includes(searchQuery);

        if (statusMatch && monthMatch && yearMatch && searchMatch) {
          row.style.display = '';
          visibleCount++;
        } else {
          row.style.display = 'none';
        }
      });

      const emptyRow = document.getElementById('paymentNoFilterResultsRow');
      if (emptyRow) {
        emptyRow.style.display = (visibleCount === 0 && rows.length > 0) ? '' : 'none';
      }

      const badge = document.getElementById('paymentFilterCountBadge');
      if (badge) {
        badge.textContent = `Showing ${visibleCount} of ${rows.length} Records`;
      }
    }

    // Reset status, month, year & search filters to default
    function resetPaymentFilters() {
      const statusSelect = document.getElementById('paymentFilterStatus');
      const monthSelect = document.getElementById('paymentFilterMonth');
      const yearSelect = document.getElementById('paymentFilterYear');
      const searchInput = document.getElementById('paymentHistorySearchInput');
      if (statusSelect) statusSelect.value = 'all';
      if (monthSelect) monthSelect.value = 'all';
      if (yearSelect) yearSelect.value = 'all';
      if (searchInput) searchInput.value = '';
      applyPaymentFilters();
    }

    // Open Comprehensive Payment Details & Verification Modal (Sections A, B, C)
    function openPaymentDetailsModal(p, defaultDecision = '') {
      if (!p) return;
      currentViewingPayment = p;
      isPaymentVerificationInProgress = false;

      document.getElementById('pdPaymentId').value = p.id;
      document.getElementById('reviewPaymentId').value = p.id;

      // Section A: Billing Information
      const payTypeElem = document.getElementById('pdPaymentType');
      if (payTypeElem) {
        payTypeElem.textContent = p.payment_type || 'Rent';
        payTypeElem.style.color = (p.payment_type === 'Advance Deposit') ? '#8C5319' : '#2E6F4E';
      }

      document.getElementById('pdTenantName').textContent = p.tenant_name || 'Resident';
      document.getElementById('pdUnitNumber').textContent = p.unit_number || ('Unit ' + (p.unit_id || ''));

      if (p.billing_cycle) {
        const bDate = new Date(p.billing_cycle.includes('T') ? p.billing_cycle : p.billing_cycle + 'T00:00:00');
        document.getElementById('pdBillingMonth').textContent = bDate.toLocaleDateString('en-US', { month: 'long' });
        document.getElementById('pdBillingYear').textContent = bDate.getFullYear();
      } else {
        document.getElementById('pdBillingMonth').innerHTML = '<span style="color: #92400e; font-style: italic;">To be assigned</span>';
        document.getElementById('pdBillingYear').innerHTML = '<span style="color: #92400e; font-style: italic;">To be assigned</span>';
      }

      if (p.move_in_date) {
        const mDate = new Date(p.move_in_date.includes('T') ? p.move_in_date : p.move_in_date + 'T00:00:00');
        document.getElementById('pdMoveInDate').textContent = mDate.toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' });
      } else {
        document.getElementById('pdMoveInDate').innerHTML = '<span style="color: var(--text-light);">N/A</span>';
      }

      if (p.due_date) {
        const dDate = new Date(p.due_date.includes('T') ? p.due_date : p.due_date + 'T00:00:00');
        document.getElementById('pdDueDate').textContent = dDate.toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' });
      } else {
        document.getElementById('pdDueDate').innerHTML = '<span style="color: #92400e; font-style: italic;">To be assigned</span>';
      }

      // Financials
      const amtDue = p.amount_due !== null ? parseFloat(p.amount_due) : null;
      const amtPaid = parseFloat(p.amount_paid || 0);

      document.getElementById('pdAmountDue').textContent = (amtDue !== null && amtDue > 0)
        ? '₱' + amtDue.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 })
        : 'Not yet configured';

      document.getElementById('pdAmountPaid').textContent = '₱' + amtPaid.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });

      const remBalElem = document.getElementById('pdRemainingBalance');
      if (p.status === 'approved') {
        remBalElem.textContent = '₱0.00';
        remBalElem.style.color = '#047857';
      } else if (amtDue === null || amtDue === 0) {
        remBalElem.textContent = '—';
        remBalElem.style.color = 'var(--text-muted)';
      } else {
        const remaining = Math.max(0, amtDue - amtPaid);
        remBalElem.textContent = '₱' + remaining.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
        remBalElem.style.color = remaining > 0 ? '#dc2626' : '#047857';
      }

      // Status Badge
      const statusBadge = document.getElementById('pdStatusBadge');
      if (statusBadge) {
        if (p.status === 'pending_setup') {
          statusBadge.className = 'clay-badge';
          statusBadge.style.cssText = 'background: #fef3c7; color: #92400e; font-weight: 800; border: 1px solid rgba(245, 158, 11, 0.35); font-size: 11px; padding: 4px 10px;';
          statusBadge.textContent = 'AWAITING CONFIGURATION';
        } else if (p.status === 'unpaid') {
          statusBadge.className = 'clay-badge';
          statusBadge.style.cssText = 'background: #fee2e2; color: #991b1b; font-weight: 800; border: 1px solid rgba(239, 68, 68, 0.35); font-size: 11px; padding: 4px 10px;';
          statusBadge.textContent = 'UNPAID';
        } else if (p.status === 'pending') {
          statusBadge.className = 'clay-badge badge-pending';
          statusBadge.style.cssText = '';
          statusBadge.textContent = 'PENDING REVIEW';
        } else if (p.status === 'approved') {
          statusBadge.className = 'clay-badge badge-approved';
          statusBadge.style.cssText = '';
          statusBadge.textContent = 'APPROVED';
        } else if (p.status === 'rejected') {
          statusBadge.className = 'clay-badge badge-rejected';
          statusBadge.style.cssText = '';
          statusBadge.textContent = 'REJECTED';
        } else {
          statusBadge.className = 'clay-badge badge-' + String(p.status || '').toLowerCase();
          statusBadge.style.cssText = '';
          statusBadge.textContent = String(p.status || '').toUpperCase();
        }
      }

      // Section B: Payment Information
      document.getElementById('pdPaymentMethod').textContent = p.payment_method || 'GCash / Bank Transfer';

      let payDateStr = 'Pending submission';
      if (p.payment_date) {
        const pdDate = new Date(p.payment_date.includes('T') ? p.payment_date : p.payment_date + 'T00:00:00');
        payDateStr = pdDate.toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' });
      } else if (p.created_at && p.status !== 'pending_setup') {
        payDateStr = new Date(p.created_at.replace(' ', 'T')).toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' });
      }
      document.getElementById('pdPaymentDate').textContent = payDateStr;

      // Reference Number
      document.getElementById('pdRefNumber').textContent = p.reference_number || ('PAY-' + String(p.id).padStart(5, '0'));

      // Tenant Remarks
      const tenantRemarksElem = document.getElementById('pdTenantRemarks');
      if (tenantRemarksElem) {
        tenantRemarksElem.textContent = p.tenant_remarks || (p.verification_notes && p.status === 'pending' ? p.verification_notes : 'None provided');
      }

      // Receipt Proof
      const receiptInspectBtn = document.getElementById('pdReceiptInspectBtn');
      const receiptContent = document.getElementById('pdReceiptContent');
      const noReceiptMsg = document.getElementById('pdNoReceiptMsg');
      const previewBox = document.getElementById('pdReceiptPreviewBox');
      const previewImage = document.getElementById('pdReceiptImage');

      if (p.receipt_filename && p.receipt_filename.trim() !== '') {
        const fileUrl = (p.receipt_filename.startsWith('http://') || p.receipt_filename.startsWith('https://'))
          ? p.receipt_filename
          : ('uploads/receipts/' + encodeURIComponent(p.receipt_filename));
        receiptInspectBtn.href = fileUrl;
        receiptInspectBtn.innerHTML = `${window.rcIcon('file-text', 'w-4 h-4')} Inspect Uploaded Proof`;
        receiptContent.style.display = 'block';
        noReceiptMsg.style.display = 'none';

        const ext = p.receipt_filename.split('?')[0].split('.').pop().toLowerCase();
        if (['png', 'jpg', 'jpeg', 'webp'].includes(ext)) {
          previewImage.src = fileUrl;
          previewBox.style.display = 'block';
        } else {
          previewBox.style.display = 'none';
        }
      } else {
        receiptContent.style.display = 'none';
        noReceiptMsg.style.display = 'block';
        previewBox.style.display = 'none';
      }

      // Section C: Actions & Remarks
      const notesField = document.getElementById('pdVerificationNotes');
      const notesReqLabel = document.getElementById('pdNotesRequiredLabel');
      const setupRow = document.getElementById('pdSetupActionRow');
      const actionRow = document.getElementById('pdActionButtonsRow');
      const processedRow = document.getElementById('pdProcessedActionsRow');
      const closeOnlyBtn = document.getElementById('btnPdCloseOnly');
      const processingIndicator = document.getElementById('pdProcessingIndicator');
      const verifiedMeta = document.getElementById('pdVerifiedMeta');

      if (notesReqLabel) notesReqLabel.style.display = 'none';
      if (processingIndicator) processingIndicator.style.display = 'none';

      if (verifiedMeta) {
        verifiedMeta.textContent = p.verified_at ? ((p.verified_by_landlord ? 'Verified on ' : 'Audited on ') + p.verified_at) : '';
      }

      const pStatusNorm = String(p.status || '').toLowerCase().trim();
      const isAlreadyProcessed = (pStatusNorm !== 'pending' && pStatusNorm !== 'pending_setup') || Boolean(p.verified_at && String(p.verified_at).trim() !== '' && pStatusNorm !== 'pending');

      if (isAlreadyProcessed) {
        // Read-only state for processed / verified payment history
        if (setupRow) setupRow.style.display = 'none';
        if (actionRow) actionRow.style.display = 'none';
        if (processedRow) processedRow.style.display = 'flex';
        if (closeOnlyBtn) closeOnlyBtn.style.display = 'inline-flex';
        notesField.value = p.verification_notes || '';
        notesField.readOnly = true;
        notesField.disabled = true;
        notesField.style.backgroundColor = '#f8fafc';
        notesField.style.cursor = 'default';
        notesField.style.color = (p.verification_notes && p.verification_notes.trim()) ? '#1e293b' : '#64748b';
        notesField.style.borderColor = 'rgba(166, 180, 204, 0.4)';
        notesField.placeholder = (p.verification_notes && p.verification_notes.trim()) ? '' : 'No decision remarks provided.';
      } else if (p.status === 'pending_setup') {
        if (setupRow) setupRow.style.display = 'block';
        if (actionRow) actionRow.style.display = 'none';
        if (processedRow) processedRow.style.display = 'none';
        notesField.value = p.verification_notes || '';
        notesField.readOnly = false;
        notesField.disabled = true;
        notesField.style.backgroundColor = '';
        notesField.style.cursor = '';
        notesField.style.color = '';
        notesField.style.borderColor = '';
        notesField.placeholder = 'Enter verification feedback or explanation (e.g. Bank transfer reference confirmed, or Payment proof was blurry/partial amount)...';
      } else {
        // Active pending payment review
        if (setupRow) setupRow.style.display = 'none';
        if (actionRow) actionRow.style.display = 'flex';
        if (processedRow) processedRow.style.display = 'none';
        notesField.readOnly = false;
        notesField.disabled = false;
        notesField.style.backgroundColor = '';
        notesField.style.cursor = '';
        notesField.style.color = '';
        notesField.style.borderColor = '';
        if (defaultDecision === 'approved') {
          notesField.value = 'Payment receipt verified and approved.';
        } else if (defaultDecision === 'rejected') {
          notesField.value = 'Payment proof rejected: ';
          if (notesReqLabel) notesReqLabel.style.display = 'inline';
        } else if (p.verification_notes) {
          notesField.value = p.verification_notes;
        } else {
          notesField.value = '';
        }
        notesField.placeholder = 'Enter verification feedback or explanation (e.g. Bank transfer reference confirmed, or Payment proof was blurry/partial amount)...';
        document.getElementById('btnPdApprove').disabled = false;
        document.getElementById('btnPdReject').disabled = false;
      }

      // Open Modal
      openModal('paymentDetailsModal');
    }

    function closePaymentDetailsModal(fromCloseModal = false) {
      const m = document.getElementById('paymentDetailsModal');
      if (m) {
        m.classList.add('hidden');
        m.classList.remove('open', 'active');
        m.style.display = 'none';
        m.style.pointerEvents = 'none';
        document.body.style.overflow = '';
      }
      if (!fromCloseModal && typeof closeModal === 'function') {
        closeModal('paymentDetailsModal');
      }
    }

    // Trigger Configure Rent modal directly from details view
    function triggerSetupFromModal() {
      if (!currentViewingPayment) return;
      closePaymentDetailsModal();
      openConfigureRentModal(
        currentViewingPayment.id,
        currentViewingPayment.tenant_name,
        currentViewingPayment.unit_number,
        currentViewingPayment.amount_due,
        currentViewingPayment.billing_cycle,
        currentViewingPayment.due_date
      );
    }

    // Handle Approve / Reject Decision with double-click protection & feedback
    async function handlePaymentVerificationDecision(decision) {
      if (isPaymentVerificationInProgress) return;

      if (currentViewingPayment) {
        const pStatusNorm = String(currentViewingPayment.status || '').toLowerCase().trim();
        const isAlreadyProcessed = (pStatusNorm !== 'pending' && pStatusNorm !== 'pending_setup') || Boolean(currentViewingPayment.verified_at && String(currentViewingPayment.verified_at).trim() !== '' && pStatusNorm !== 'pending');
        if (isAlreadyProcessed) {
          showToast('This payment has already been verified and cannot be modified.', 'warning');
          return;
        }
      }

      const paymentId = document.getElementById('pdPaymentId').value;
      const notes = document.getElementById('pdVerificationNotes').value.trim();

      if (!paymentId) return;

      if (decision === 'rejected') {
        if (!notes || notes === 'Payment proof rejected:' || notes === 'Payment proof rejected: ') {
          showToast('Please enter an explanation or reason for rejecting this payment proof.', 'error');
          const notesField = document.getElementById('pdVerificationNotes');
          if (notesField) {
            notesField.focus();
            const reqLabel = document.getElementById('pdNotesRequiredLabel');
            if (reqLabel) reqLabel.style.display = 'inline';
          }
          return;
        }
      }

      // Activate Concurrency & Double-Click Protection
      isPaymentVerificationInProgress = true;
      const actionRow = document.getElementById('pdActionButtonsRow');
      const processingIndicator = document.getElementById('pdProcessingIndicator');
      const btnApprove = document.getElementById('btnPdApprove');
      const btnReject = document.getElementById('btnPdReject');

      if (btnApprove) btnApprove.disabled = true;
      if (btnReject) btnReject.disabled = true;
      if (actionRow) actionRow.style.display = 'none';
      if (processingIndicator) {
        document.getElementById('pdProcessingText').textContent = (decision === 'approved')
          ? 'Approving payment receipt & notifying tenant via email...'
          : 'Rejecting payment receipt & notifying tenant via email...';
        processingIndicator.style.display = 'flex';
      }

      try {
        const res = await fetch('api/payment_api.php', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({
            action: 'verify_payment',
            payment_id: parseInt(paymentId, 10),
            decision: decision,
            notes: notes
          })
        });

        const result = await res.json();
        if (res.ok && result.status === 'success') {
          showToast(result.message);
          closePaymentDetailsModal();
          setTimeout(() => {
            window.location.href = 'admin_dashboard.php?section=sectionPayments';
          }, 900);
        } else {
          showToast(result.message || 'Error updating payment status', 'error');
          isPaymentVerificationInProgress = false;
          if (processingIndicator) processingIndicator.style.display = 'none';
          if (actionRow) actionRow.style.display = 'flex';
          if (btnApprove) btnApprove.disabled = false;
          if (btnReject) btnReject.disabled = false;
        }
      } catch (err) {
        showToast('Network error verifying payment receipt', 'error');
        isPaymentVerificationInProgress = false;
        if (processingIndicator) processingIndicator.style.display = 'none';
        if (actionRow) actionRow.style.display = 'flex';
        if (btnApprove) btnApprove.disabled = false;
        if (btnReject) btnReject.disabled = false;
      }
    }

    // Backward Compatibility Forwarders
    function openReviewModal(paymentId, tenantName, unitNumber, billingCycle, amountPaid, receiptFilename, defaultDecision = '') {
      const found = allPaymentsData.find(p => p.id == paymentId);
      if (found) {
        openPaymentDetailsModal(found, defaultDecision);
      } else {
        openPaymentDetailsModal({
          id: paymentId,
          tenant_name: tenantName,
          unit_number: unitNumber,
          billing_cycle: billingCycle,
          amount_paid: amountPaid,
          amount_due: amountPaid,
          receipt_filename: receiptFilename,
          status: defaultDecision ? (defaultDecision === 'approved' ? 'approved' : 'rejected') : 'pending'
        }, defaultDecision);
      }
    }

    function closeReviewModal() {
      closePaymentDetailsModal();
    }

    async function submitPaymentReview(decision) {
      await handlePaymentVerificationDecision(decision);
    }

    // Configure Placeholder Rent Modal Controls
    function openConfigureRentModal(paymentId, tenantName, unitNumber, currentRent, currentCycle, currentDueDate) {
      document.getElementById('configPaymentId').value = paymentId;
      document.getElementById('configTenantName').textContent = tenantName || '-';
      document.getElementById('configUnitNumber').textContent = unitNumber || '-';
      
      const rentInput = document.getElementById('configMonthlyRent');
      if (currentRent && parseFloat(currentRent) > 0) {
        rentInput.value = parseFloat(currentRent).toFixed(2);
      } else {
        rentInput.value = '3000.00';
      }

      const cycleInput = document.getElementById('configBillingCycle');
      if (currentCycle && currentCycle.length >= 7) {
        cycleInput.value = currentCycle.substring(0, 7);
      } else {
        const now = new Date();
        const yyyy = now.getFullYear();
        const mm = String(now.getMonth() + 1).padStart(2, '0');
        cycleInput.value = `${yyyy}-${mm}`;
      }

      const dueDateInput = document.getElementById('configDueDate');
      if (currentDueDate && currentDueDate.length >= 10) {
        dueDateInput.value = currentDueDate;
      } else {
        const valMonth = cycleInput.value;
        dueDateInput.value = `${valMonth}-05`;
      }

      // Auto-update due date when billing month changes if left default
      cycleInput.onchange = function() {
        if (cycleInput.value) {
          dueDateInput.value = `${cycleInput.value}-05`;
        }
      };

      openModal('configureRentModal');
    }

    function closeConfigureRentModal(fromCloseModal = false) {
      const m = document.getElementById('configureRentModal');
      if (m) {
        m.classList.add('hidden');
        m.classList.remove('open', 'active');
        m.style.display = 'none';
        m.style.pointerEvents = 'none';
        document.body.style.overflow = '';
      }
      if (!fromCloseModal && typeof closeModal === 'function') {
        closeModal('configureRentModal');
      }
    }

    async function submitConfigureRent() {
      const paymentId = document.getElementById('configPaymentId').value;
      const rentAmount = document.getElementById('configMonthlyRent').value;
      const cycle = document.getElementById('configBillingCycle').value;
      const dueDate = document.getElementById('configDueDate').value;

      if (!paymentId) return;
      if (!rentAmount || parseFloat(rentAmount) <= 0) {
        showToast('Please enter a valid monthly rent amount greater than 0', 'error');
        return;
      }
      if (!cycle) {
        showToast('Please select a billing month', 'error');
        return;
      }
      if (!dueDate) {
        showToast('Please select a payment due date', 'error');
        return;
      }

      try {
        const res = await fetch('api/payment_api.php', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({
            action: 'configure_rent',
            payment_id: parseInt(paymentId, 10),
            monthly_rent: parseFloat(rentAmount),
            billing_cycle: cycle + '-01',
            due_date: dueDate
          })
        });

        const result = await res.json();
        if (res.ok && result.status === 'success') {
          showToast(result.message);
          closeConfigureRentModal();
          setTimeout(() => {
            window.location.href = 'admin_dashboard.php?section=sectionPayments';
          }, 1000);
        } else {
          showToast(result.message || 'Error configuring rent', 'error');
        }
      } catch (err) {
        showToast('Network error configuring rent', 'error');
      }
    }

    // =========================================================================
    // GLOBAL OWNER SEARCH (UNITS, TENANTS, ARCHIVED, TICKETS, PAYMENTS, SECTIONS)
    // =========================================================================
    const OWNER_SEARCH_UNITS = <?= json_encode($units, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    let OWNER_SEARCH_TENANTS = <?= json_encode($allTenants, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    const OWNER_SEARCH_TICKETS = <?= json_encode($tickets, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    let OWNER_SEARCH_REPORTS = <?= json_encode($tenantReports, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    let OWNER_SEARCH_PAYMENTS = <?= json_encode($payments, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    let ownerSearchArchivedLoaded = false;
    let activeGlobalSearchResults = [];

    function preloadArchivedForGlobalSearch() {
      if (ownerSearchArchivedLoaded || (Array.isArray(cachedArchivedTenants) && cachedArchivedTenants.length > 0)) {
        ownerSearchArchivedLoaded = true;
        return;
      }
      ownerSearchArchivedLoaded = true;
      fetch('api/tenant_directory_api.php?action=list_archived')
        .then(r => r.ok ? r.json() : null)
        .then(data => {
          if (data && data.status === 'success' && Array.isArray(data.archived_tenants)) {
            cachedArchivedTenants = data.archived_tenants;
            const input = document.getElementById('ownerGlobalSearchInput');
            if (input && input.value.trim().length > 0) {
              renderGlobalOwnerSearchResults(input.value);
            }
          }
        })
        .catch(() => {});
    }

    function clearGlobalOwnerSearch() {
      const input = document.getElementById('ownerGlobalSearchInput');
      const clearBtn = document.getElementById('ownerGlobalSearchClear');
      if (input) input.value = '';
      if (clearBtn) clearBtn.classList.remove('visible');
      closeGlobalSearchDropdown();
    }

    function selectGlobalSearchResult(idx) {
      const item = activeGlobalSearchResults[idx];
      if (!item) return;
      closeGlobalSearchDropdown();

      if (item.kind === 'section') {
        switchSection(item.sectionId, document.getElementById(item.navId));
      } else if (item.kind === 'archived_section') {
        switchSection('sectionArchivedTenants');
      } else if (item.kind === 'unit') {
        switchSection('sectionDirectory', document.getElementById('navTenantDirectory'));
        switchDirectoryTab('active');
        openUnitFolder(item.payload.id);
      } else if (item.kind === 'tenant') {
        switchSection('sectionDirectory', document.getElementById('navTenantDirectory'));
        switchDirectoryTab('active');
        openTenantDetailsModal(item.payload, item.payload.unit_number || '', item.payload.unit_id || '');
      } else if (item.kind === 'archived_tenant') {
        switchSection('sectionDirectory', document.getElementById('navTenantDirectory'));
        switchDirectoryTab('archived');
        openArchivedTenantDetails(item.payload.id);
      } else if (item.kind === 'ticket') {
        switchSection('sectionTickets', document.getElementById('navMaintenanceTickets'));
        openTicketDetailsModal(item.payload);
      } else if (item.kind === 'report') {
        switchSection('sectionReports', document.getElementById('navTenantReports'));
        switchReportTab((item.payload.status || 'Pending') === 'Pending' ? 'pending' : 'reviewed');
        openReportDetailsModal(item.payload);
      } else if (item.kind === 'payment') {
        switchSection('sectionPayments', document.getElementById('navPaymentVerification'));
        const pStatus = ((item.payload && item.payload.status) || '').toLowerCase();
        if (pStatus !== 'pending' && typeof switchPaymentTab === 'function') {
          switchPaymentTab('history');
        } else if (typeof switchPaymentTab === 'function') {
          switchPaymentTab('pending');
        }
        openPaymentDetailsModal(item.payload);
      }
    }

    function renderGlobalOwnerSearchResults(rawQuery) {
      const dd = document.getElementById('globalSearchDropdown');
      const clearBtn = document.getElementById('ownerGlobalSearchClear');
      if (!dd) return;

      const q = (rawQuery || '').trim().toLowerCase();
      if (clearBtn) {
        clearBtn.classList.toggle('visible', q.length > 0);
      }
      if (!q) {
        activeGlobalSearchResults = [];
        dd.innerHTML = '';
        dd.classList.remove('open');
        return;
      }

      const tokens = q.split(/\s+/).filter(Boolean);
      function matchesTokens(haystack) {
        const h = String(haystack || '').toLowerCase();
        return tokens.every(tok => h.includes(tok));
      }

      const matches = [];

      const quickSections = [
        { title: 'Owner Dashboard & Revenue Analytics', sub: 'KPI cards, overview panel, revenue & repair charts', tag: 'Dashboard', kind: 'section', sectionId: 'sectionAnalytics', navId: 'navLandlordDashboard', keys: 'dashboard analytics overview revenue rent collected rate' },
        { title: 'Tenant Directory & Units', sub: 'Manage units and active residents', tag: 'Directory', kind: 'section', sectionId: 'sectionDirectory', navId: 'navTenantDirectory', keys: 'tenants directory units apartments residents' },
        { title: 'Archived Tenant Accounts', sub: 'Moved-out and archived resident records', tag: 'Archive', kind: 'archived_section', keys: 'archived tenants moved out history deleted' },
        { title: 'Maintenance Tickets', sub: 'Active and completed repair requests', tag: 'Repairs', kind: 'section', sectionId: 'sectionTickets', navId: 'navMaintenanceTickets', keys: 'maintenance tickets repairs issues' },
        { title: 'Tenant Reports', sub: 'Occupants, pets, modifications & general requests', tag: 'Reports', kind: 'section', sectionId: 'sectionReports', navId: 'navTenantReports', keys: 'reports general occupant pet customization modification inquiry' },
        { title: 'Payment Verification & Ledger', sub: 'Verify payment proofs, receipts, and rent schedules', tag: 'Payments', kind: 'section', sectionId: 'sectionPayments', navId: 'navPaymentVerification', keys: 'payments receipts verification ledger billing rent' },
        { title: 'Account Settings & Password', sub: 'Manage owner profile and login security', tag: 'Settings', kind: 'section', sectionId: 'sectionProfile', navId: null, keys: 'settings profile password account security' }
      ];

      quickSections.forEach(s => {
        const hay = `${s.title} ${s.sub} ${s.keys}`.toLowerCase();
        if (matchesTokens(hay)) {
          matches.push(s);
        }
      });

      (OWNER_SEARCH_UNITS || []).forEach(u => {
        const unitName = String(u.unit_number || '');
        const rentStr = u.monthly_rent ? `₱${Number(u.monthly_rent).toLocaleString()}/mo` : '';
        const hay = `${unitName} unit ${unitName} floor ${u.floor_level || ''} ${rentStr}`.toLowerCase();
        if (matchesTokens(hay)) {
          matches.push({
            title: unitName.toLowerCase().startsWith('unit') ? unitName : `Unit ${unitName}`,
            sub: `${rentStr} • Floor ${u.floor_level || '-'}`,
            tag: 'Unit',
            kind: 'unit',
            payload: u
          });
        }
      });

      (OWNER_SEARCH_TENANTS || []).forEach(t => {
        const hay = `${t.name || ''} ${t.username || ''} ${t.email || ''} ${t.phone || ''} ${t.unit_number || ''}`.toLowerCase();
        if (matchesTokens(hay)) {
          matches.push({
            title: t.name || 'Tenant',
            sub: `${t.unit_number || 'Unit'} • @${t.username || ''} • ${t.email || ''}`,
            tag: 'Tenant',
            kind: 'tenant',
            payload: t
          });
        }
      });

      (cachedArchivedTenants || []).forEach(a => {
        const hay = `${a.name || ''} ${a.username || ''} ${a.email || ''} ${a.phone || ''} ${a.archived_unit_number || ''}`.toLowerCase();
        if (matchesTokens(hay)) {
          matches.push({
            title: a.name || 'Archived Tenant',
            sub: `${a.archived_unit_number || 'Archived'} • ${a.email || ''}`,
            tag: 'Archived',
            kind: 'archived_tenant',
            payload: a
          });
        }
      });

      (OWNER_SEARCH_TICKETS || []).forEach(tk => {
        const tkCode = `#MT-${String(tk.id || '').padStart(4, '0')}`;
        const rawCode = `MT-${tk.id} ${tk.id}`;
        const hay = `${tkCode} ${rawCode} ${tk.title || ''} ${tk.category || ''} ${tk.description || ''} ${tk.tenant_name || ''} ${tk.unit_number || ''} ${tk.status || ''}`.toLowerCase();
        if (matchesTokens(hay)) {
          matches.push({
            title: `${tkCode} — ${tk.title || 'Maintenance Request'}`,
            sub: `${tk.unit_number || ''} • ${tk.tenant_name || ''} • ${tk.status || ''}`,
            tag: 'Ticket',
            kind: 'ticket',
            payload: tk
          });
        }
      });

      (OWNER_SEARCH_REPORTS || []).forEach(rep => {
        const repCode = `#GR-${String(rep.id || '').padStart(4, '0')}`;
        const rawCode = `GR-${rep.id} ${rep.id}`;
        const hay = `${repCode} ${rawCode} ${rep.subject || ''} ${rep.category || ''} ${rep.details || ''} ${rep.tenant_name || ''} ${rep.unit_number || ''} ${rep.status || ''}`.toLowerCase();
        if (matchesTokens(hay)) {
          matches.push({
            title: `${repCode} — ${rep.subject || 'General Report'}`,
            sub: `${rep.unit_number || ''} • ${rep.category || ''} • ${rep.status || 'Pending'}`,
            tag: 'Report',
            kind: 'report',
            payload: rep
          });
        }
      });

      (OWNER_SEARCH_PAYMENTS || []).forEach(pm => {
        const amt = Number(pm.amount_due || pm.amount_paid || 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
        const hay = `${pm.tenant_name || ''} ${pm.unit_number || ''} ${pm.reference_number || ''} ${pm.payment_type || ''} ${pm.status || ''} ${pm.billing_cycle || ''} ₱${amt}`.toLowerCase();
        if (matchesTokens(hay)) {
          matches.push({
            title: `${pm.tenant_name || 'Resident'} — ₱${amt}`,
            sub: `${pm.unit_number || ''} • ${pm.billing_cycle || 'Cycle'} • ${String(pm.status || '').toUpperCase()}${pm.reference_number ? ' • Ref: ' + pm.reference_number : ''}`,
            tag: 'Payment',
            kind: 'payment',
            payload: pm
          });
        }
      });

      activeGlobalSearchResults = matches.slice(0, 12);
      if (activeGlobalSearchResults.length === 0) {
        dd.innerHTML = `<div style="padding: 16px 14px; text-align: center; font-size: 13px; color: var(--tr-text-muted);">No matching tenants, units, tickets, or payments for "<strong>${escapeHtml(rawQuery)}</strong>".</div>`;
      } else {
        dd.innerHTML = activeGlobalSearchResults.map((m, idx) => `
          <button type="button" class="global-search-item" role="option" onclick="selectGlobalSearchResult(${idx})">
            <div class="global-search-meta">
              <span class="global-search-title">${escapeHtml(m.title)}</span>
              <span class="global-search-sub">${escapeHtml(m.sub)}</span>
            </div>
            <span class="global-search-tag">${escapeHtml(m.tag)}</span>
          </button>
        `).join('');
      }
      dd.classList.add('open');
    }

    function initGlobalOwnerSearch() {
      const input = document.getElementById('ownerGlobalSearchInput');
      if (!input) return;

      input.addEventListener('focus', () => {
        preloadArchivedForGlobalSearch();
        if (input.value.trim().length > 0) {
          renderGlobalOwnerSearchResults(input.value);
        }
      });

      input.addEventListener('input', () => {
        renderGlobalOwnerSearchResults(input.value);
      });

      input.addEventListener('keydown', (e) => {
        if (e.key === 'Escape') {
          clearGlobalOwnerSearch();
          input.blur();
        } else if (e.key === 'Enter' && activeGlobalSearchResults.length > 0) {
          e.preventDefault();
          selectGlobalSearchResult(0);
        }
      });
    }

    // Initialize immediately if DOM is ready
    if (document.readyState === 'complete' || document.readyState === 'interactive') {
      initGlobalOwnerSearch();
    }

    let baForecastChartInstance = null;
    let baParetoChartInstance = null;

    function escapeHtmlOwnerDash(str) {
      return String(str ?? '').replace(/[&<>"']/g, s => ({
        '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'
      }[s]));
    }

    function formatPhpOwnerDash(val) {
      return '₱' + Number(val || 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    }

    function renderBusinessAnalyticsPillars(ba, monthlyCollections, kpis) {
      if (!ba) return;

      // -----------------------------------------------------------------------
      // PILLAR 4: PRESCRIPTIVE ("RECOMMENDED ACTIONS FOR TODAY" — NO DUPLICATE NAV LINKS)
      // -----------------------------------------------------------------------
      const insightsContainer = document.getElementById('smartInsightsList');
      const insightsBadge = document.getElementById('smartInsightsBadge');
      const insights = ba.prescriptive?.smart_insights || [];
      if (insightsBadge) {
        insightsBadge.textContent = `${insights.length} Suggestion${insights.length === 1 ? '' : 's'} Today`;
      }
      if (insightsContainer) {
        if (insights.length === 0) {
          insightsContainer.innerHTML = '<div class="ba-empty-box">Everything looks caught up! No urgent actions recommended today.</div>';
        } else {
          insightsContainer.innerHTML = insights.map(item => {
            const sevClass = item.severity === 'urgent' ? 'sev-urgent' : '';
            return `
              <div class="ba-insight-item ${sevClass}">
                <div>
                  <div class="ba-insight-top">
                    <span class="ba-insight-badge">${escapeHtmlOwnerDash(item.badge || 'Suggestion')}</span>
                    <span class="ba-insight-metric">${escapeHtmlOwnerDash(item.metric_highlight || '')}</span>
                  </div>
                  <h4>${escapeHtmlOwnerDash(item.title)}</h4>
                  <p>${escapeHtmlOwnerDash(item.recommendation)}</p>
                </div>
              </div>
            `;
          }).join('');
        }
      }

      // -----------------------------------------------------------------------
      // PILLAR 1: AVERAGE PAYMENT SPEED (DSO) KPI CARD
      // -----------------------------------------------------------------------
      const desc = ba.descriptive || {};
      const elDso = document.getElementById('baKpiDso');
      const elOnTime = document.getElementById('baKpiOnTimeBadge');
      const elDsoSub = document.getElementById('baKpiDsoSub');
      if (elDso) {
        elDso.textContent = desc.has_dso_data ? `${Number(desc.dso_days || 0).toFixed(1)} days` : '—';
      }
      if (elOnTime) {
        elOnTime.textContent = desc.has_dso_data
          ? `${Number(desc.on_time_collection_rate_pct || 0).toFixed(0)}% Paid On Time`
          : 'No Paid Bills Yet';
      }
      if (elDsoSub) {
        elDsoSub.textContent = desc.has_dso_data
          ? `Across ${desc.settled_payments_count || 0} paid rent bill(s)`
          : 'Average days tenants take to pay after billing';
      }

      // -----------------------------------------------------------------------
      // PILLAR 3A: NEXT-MONTH EXPECTED CASH FLOW KPI + PROJECTION CHART
      // -----------------------------------------------------------------------
      const cf = ba.predictive?.cash_flow_forecast || {};
      const elFcMonth = document.getElementById('baForecastMonthBadge');
      const elFcExp = document.getElementById('baForecastExpected');
      const elFcConf = document.getElementById('baForecastConfidenceBadge');
      const elFcSub = document.getElementById('baForecastSub');
      const elFcRent = document.getElementById('baForecastRent');
      const elFcVac = document.getElementById('baForecastVacancy');

      if (elFcMonth) elFcMonth.textContent = cf.forecast_month_label ? `Forecast · ${cf.forecast_month_label}` : 'Projection';
      if (elFcExp) elFcExp.textContent = formatPhpOwnerDash(cf.expected_cash_inflow || 0);
      if (elFcConf) {
        const fac = Number(cf.historical_collection_factor_pct || 0).toFixed(1);
        elFcConf.textContent = cf.has_historical_baseline ? `Collection Rate: ${fac}%` : `Baseline: ${fac}%`;
      }
      if (elFcSub) {
        elFcSub.textContent = `For ${cf.forecast_month_label || 'next month'} · Parking/Add-ons: ${formatPhpOwnerDash(cf.parking_addons_potential || 0)}`;
      }
      if (elFcRent) elFcRent.textContent = formatPhpOwnerDash(cf.occupied_unit_rent_potential || 0);
      if (elFcVac) {
        const totVac = Number(cf.vacant_unit_opportunity || 0) + Number(cf.vacant_parking_opportunity || 0);
        elFcVac.textContent = '+' + formatPhpOwnerDash(totVac);
      }

      const forecastCanvas = document.getElementById('chartCashFlowForecast');
      if (forecastCanvas && typeof window.Chart !== 'undefined') {
        if (baForecastChartInstance) {
          baForecastChartInstance.destroy();
          baForecastChartInstance = null;
        }
        const fullCapacityTotal = Number(cf.optimistic_cash_inflow || 0) + Number(cf.vacant_unit_opportunity || 0) + Number(cf.vacant_parking_opportunity || 0);
        baForecastChartInstance = new Chart(forecastCanvas, {
          type: 'bar',
          data: {
            labels: ['Avg Monthly Collected', `Expected (${cf.forecast_month_label || 'Next Mo'})`, 'Full Capacity Ceiling'],
            datasets: [{
              label: 'Cash Flow (₱)',
              data: [
                Number(cf.historical_avg_collected || 0),
                Number(cf.expected_cash_inflow || 0),
                fullCapacityTotal
              ],
              backgroundColor: ['#B89B72', '#4A3728', '#E8D8B0'],
              borderColor: ['#8C6D43', '#4A3728', '#8C6D43'],
              borderWidth: 1.5,
              borderRadius: 10,
              maxBarThickness: 56
            }]
          },
          options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
              legend: { display: false },
              tooltip: {
                backgroundColor: '#F5F0E6',
                titleColor: '#4A3728',
                bodyColor: '#4A3728',
                borderColor: '#B89B72',
                borderWidth: 1,
                callbacks: {
                  label: ctx => ` ${formatPhpOwnerDash(ctx.parsed.y || 0)}`
                }
              }
            },
            scales: {
              x: { grid: { display: false }, ticks: { color: '#4A3728', font: { size: 11, weight: '700' } } },
              y: {
                beginAtZero: true,
                grid: { color: 'rgba(140, 109, 67, 0.14)' },
                ticks: { color: '#8C6D43', font: { size: 11, weight: '600' }, callback: v => `₱${Number(v).toLocaleString()}` }
              }
            }
          }
        });
      }

      // -----------------------------------------------------------------------
      // PILLAR 3B: PAYMENT RELIABILITY RISK TABLE (SINGLE SOURCE — NO REDUNDANT PILLS)
      // -----------------------------------------------------------------------
      const riskSummary = ba.predictive?.risk_summary || {};
      const riskCountBadge = document.getElementById('baRiskCountBadge');
      if (riskCountBadge) {
        const hi = Number(riskSummary.high_count || 0);
        const tot = Number(riskSummary.total_scored || 0);
        riskCountBadge.textContent = hi > 0 ? `${hi} High Risk` : `${tot} Scored`;
      }

      const riskTbody = document.getElementById('baTenantRiskTableBody');
      const riskRows = ba.predictive?.tenant_risk_scores || [];
      if (riskTbody) {
        if (riskRows.length === 0) {
          riskTbody.innerHTML = `
            <tr>
              <td colspan="5" style="text-align:center;padding:24px;color:#8C6D43;">
                No active tenants to score yet.
              </td>
            </tr>
          `;
        } else {
          riskTbody.innerHTML = riskRows.map(t => {
            const score = Number(t.risk_score || 0);
            const tier = t.risk_tier || 'Low';
            const barColor = tier === 'High' ? '#4A3728' : (tier === 'Medium' ? '#8C6D43' : '#B89B72');
            const badgeStyle = tier === 'High'
              ? 'background:#4A3728;color:#F5F0E6;'
              : (tier === 'Medium' ? 'background:#8C6D43;color:#F5F0E6;' : 'background:#E8D8B0;color:#4A3728;');
            return `
              <tr>
                <td>
                  <div style="font-weight:800;color:#4A3728;">${escapeHtmlOwnerDash(t.tenant_name)}</div>
                  <div style="font-size:11px;color:#8C6D43;font-weight:700;">${escapeHtmlOwnerDash(t.unit_number)}</div>
                </td>
                <td style="white-space:nowrap;">
                  <div class="ba-risk-bar-track"><div class="ba-risk-bar-fill" style="width:${Math.max(5, score)}%;background:${barColor};"></div></div>
                  <span style="font-size:10.5px;font-weight:800;padding:3px 8px;border-radius:9999px;${badgeStyle}">
                    ${score}% · ${escapeHtmlOwnerDash(tier)}
                  </span>
                </td>
                <td style="white-space:nowrap;">${formatPhpOwnerDash(t.unpaid_balance)}</td>
                <td style="white-space:nowrap;">${Number(t.avg_days_late || 0).toFixed(1)}d <span style="color:#8C6D43;font-size:11px;">(${t.late_payments_count}/${t.total_bills})</span></td>
                <td style="white-space:nowrap;">${Number(t.rejected_ratio_pct || 0).toFixed(0)}% <span style="color:#8C6D43;font-size:11px;">(${t.rejected_receipts_count})</span></td>
              </tr>
            `;
          }).join('');
        }
      }

      // -----------------------------------------------------------------------
      // PILLAR 2B: UNIT COLLECTION & WATCHLIST TABLE (SINGLE TABLE — NO DUPLICATE CHART)
      // -----------------------------------------------------------------------
      const unitHealthRows = ba.diagnostic?.unit_health_index || [];
      const unitTbody = document.getElementById('baUnitOverviewTableBody');
      if (unitTbody) {
        if (unitHealthRows.length === 0) {
          unitTbody.innerHTML = '<tr><td colspan="5" style="text-align:center;color:#8C6D43;padding:24px;">No units configured yet.</td></tr>';
        } else {
          unitTbody.innerHTML = unitHealthRows.map(u => {
            const tier = u.health_tier || 'healthy';
            const tierLabel = tier === 'problematic' ? 'Needs Inspection' : (tier === 'monitor' ? 'Watchlist' : 'Healthy');
            const badgeStyle = tier === 'problematic'
              ? 'background:#4A3728;color:#F5F0E6;'
              : (tier === 'monitor' ? 'background:#8C6D43;color:#F5F0E6;' : 'background:#E8D8B0;color:#4A3728;');
            return `
              <tr>
                <td><strong>${escapeHtmlOwnerDash(u.unit_number)}</strong></td>
                <td style="white-space:nowrap;">${formatPhpOwnerDash(u.unit_collected_rent)}</td>
                <td style="white-space:nowrap;">${formatPhpOwnerDash(u.unit_unpaid_rent)}</td>
                <td style="white-space:nowrap;">${u.open_tickets} open <span style="color:#8C6D43;font-size:11px;">(${u.ticket_count} total)</span></td>
                <td style="white-space:nowrap;">
                  <span style="font-size:10.5px;font-weight:800;padding:3px 9px;border-radius:9999px;${badgeStyle}">${tierLabel}</span>
                </td>
              </tr>
            `;
          }).join('');
        }
      }

      // -----------------------------------------------------------------------
      // PILLAR 2A: MAINTENANCE REQUESTS BY CATEGORY (CHART ONLY — NO DUPLICATE TABLE)
      // -----------------------------------------------------------------------
      const paretoRows = ba.diagnostic?.pareto_maintenance || [];
      const paretoCanvas = document.getElementById('chartMaintenancePareto');
      if (paretoCanvas && typeof window.Chart !== 'undefined') {
        if (baParetoChartInstance) {
          baParetoChartInstance.destroy();
          baParetoChartInstance = null;
        }
        baParetoChartInstance = new Chart(paretoCanvas, {
          data: {
            labels: paretoRows.map(r => r.category),
            datasets: [
              {
                type: 'line',
                label: 'Cumulative %',
                data: paretoRows.map(r => Number(r.cumulative_pct || 0)),
                borderColor: '#4A3728',
                backgroundColor: '#F5F0E6',
                borderWidth: 2.5,
                tension: 0.25,
                pointRadius: 4,
                pointBackgroundColor: '#4A3728',
                yAxisID: 'yCum',
                order: 1
              },
              {
                type: 'bar',
                label: 'Requests',
                data: paretoRows.map(r => Number(r.ticket_count || 0)),
                backgroundColor: ['#8C6D43', '#B89B72', '#D4BD94', '#E8D8B0', '#C9B18B'],
                borderRadius: 8,
                maxBarThickness: 44,
                yAxisID: 'yCount',
                order: 2
              }
            ]
          },
          options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
              legend: {
                position: 'top',
                labels: { usePointStyle: true, pointStyle: 'circle', color: '#4A3728', font: { size: 11, weight: '700' } }
              },
              tooltip: {
                backgroundColor: '#F5F0E6',
                titleColor: '#4A3728',
                bodyColor: '#4A3728',
                borderColor: '#B89B72',
                borderWidth: 1,
                callbacks: {
                  label: ctx => {
                    const row = paretoRows[ctx.dataIndex];
                    if (ctx.dataset.yAxisID === 'yCum') {
                      return ` Cumulative Share: ${Number(ctx.parsed.y || 0).toFixed(1)}%`;
                    }
                    const share = row ? Number(row.request_share_percentage || 0).toFixed(1) : '0.0';
                    return ` Requests: ${Number(ctx.parsed.y || 0)} (${share}%)`;
                  }
                }
              }
            },
            scales: {
              x: { grid: { display: false }, ticks: { color: '#4A3728', font: { size: 11.5, weight: '700' } } },
              yCount: {
                type: 'linear',
                position: 'left',
                beginAtZero: true,
                grid: { color: 'rgba(140, 109, 67, 0.14)' },
                ticks: { color: '#8C6D43', font: { size: 11 }, stepSize: 1, precision: 0 }
              },
              yCum: {
                type: 'linear',
                position: 'right',
                min: 0,
                max: 100,
                grid: { display: false },
                ticks: { color: '#4A3728', font: { size: 11, weight: '700' }, callback: v => `${v}%` }
              }
            }
          }
        });
      }
    }

    // Load Deduplicated Owner Dashboard Overview & 4 BA Pillars
    async function loadDashboardAnalytics() {
      try {
        const res = await fetch('api/analytics_api.php');
        const data = await res.json();
        if (data.status === 'success' && data.kpis) {
          const fin = data.kpis.financials || {};
          const units = data.kpis.units || {};
          const maint = data.kpis.maintenance || {};
          const reports = data.kpis.reports || {};
          const pendingApprovals = Number(data.kpis.manual_verification?.pending_receipts_count || 0);

          // 1. Total Rent Collected (Single Source)
          const now = new Date();
          const curMonthKey = `${now.getFullYear()}-${String(now.getMonth() + 1).padStart(2, '0')}`;
          const monthlyArr = Array.isArray(data.charts?.monthly_collection_efficiency)
            ? data.charts.monthly_collection_efficiency
            : [];
          const curMonthRow = monthlyArr.find(m => m.month_key === curMonthKey);
          const allTimeCollected = Number(fin.total_revenue_collected || 0);
          const thisMonthCollected = curMonthRow ? Number(curMonthRow.collected_rent || 0) : 0;

          const elRev = document.getElementById('kpiRevenue');
          const elPaidTag = document.getElementById('kpiPaidTag');
          const elRevSub = document.getElementById('kpiRevenueSubtitle');
          if (elRev) {
            elRev.textContent = formatPhpOwnerDash(thisMonthCollected > 0 ? thisMonthCollected : allTimeCollected);
          }
          if (elPaidTag) {
            elPaidTag.textContent = `${Number(fin.overall_collection_efficiency_pct || 0).toFixed(1)}% Paid`;
          }
          if (elRevSub) {
            elRevSub.textContent = thisMonthCollected > 0
              ? `This month (${curMonthRow.month_label || curMonthKey}) · All-time: ${formatPhpOwnerDash(allTimeCollected)}`
              : 'Approved rent collected (zero deductions)';
          }

          // 2. Pending & Unpaid Rent (Single Source)
          const elOut = document.getElementById('kpiOutstanding');
          const elPendTag = document.getElementById('kpiPendingApprovalTag');
          if (elOut) {
            elOut.textContent = formatPhpOwnerDash(fin.total_outstanding_balance || 0);
          }
          if (elPendTag) {
            elPendTag.textContent = pendingApprovals > 0 ? `${pendingApprovals} Waiting for Approval` : 'Up to Date';
            elPendTag.classList.toggle('accent', pendingApprovals > 0);
          }

          // 3. Unit Occupancy (Single Source)
          const occUnits = Number(units.occupied_units || 0);
          const availUnits = Number(units.available_units || 0);
          const totUnits = Number(units.total_units || 0);
          const activeTenants = Number(units.total_active_tenants || 0);
          const archivedTenants = Number(units.total_archived_tenants || 0);
          const elUnitsSum = document.getElementById('kpiUnitsRentedSummary');
          const elUnitsTag = document.getElementById('kpiUnitsAvailabilityTag');
          const elUnitsSub = document.getElementById('kpiUnitsRentedSub');
          if (elUnitsSum) {
            elUnitsSum.textContent = `${occUnits} of ${totUnits} Units Rented`;
          }
          if (elUnitsTag) {
            elUnitsTag.textContent = availUnits > 0 ? `${availUnits} Available` : 'Fully Rented';
          }
          if (elUnitsSub) {
            elUnitsSub.textContent = `${activeTenants} Active Tenant${activeTenants === 1 ? '' : 's'} · ${archivedTenants} Past`;
          }

          // 4. Open Requests (Maintenance + Tenant Requests combined once)
          const openMaint = Number(maint.active_tickets || 0);
          const pendReports = Number(reports.pending_reports || 0);
          const totalOpen = openMaint + pendReports;
          const elOpenReq = document.getElementById('kpiOpenRequestsCount');
          const elMaintTag = document.getElementById('kpiMaintStatusTag');
          const elOpenReqSub = document.getElementById('kpiOpenRequestsSub');
          if (elOpenReq) elOpenReq.textContent = String(totalOpen);
          if (elMaintTag) {
            elMaintTag.textContent = totalOpen > 0 ? 'Needs Attention' : 'All Clear';
            elMaintTag.classList.toggle('accent', totalOpen > 0);
          }
          if (elOpenReqSub) {
            elOpenReqSub.textContent = `${openMaint} Maintenance · ${pendReports} Tenant Request${pendReports === 1 ? '' : 's'}`;
          }

          const pendBadge = document.getElementById('pendingReportsCountBadge');
          if (pendBadge) pendBadge.textContent = String(pendReports);

          // 5. Hydrate all 4 Business Analytics Pillars
          if (data.business_analytics) {
            renderBusinessAnalyticsPillars(data.business_analytics, monthlyArr, data.kpis);
          }

          if (window.lucide && typeof window.lucide.createIcons === 'function') {
            window.lucide.createIcons();
          }

          // 6. Render Monthly Rent Overview Chart
          if (typeof RentConnectCharts !== 'undefined' && typeof RentConnectCharts.initCharts === 'function') {
            RentConnectCharts.initCharts(data);
          }
        }
      } catch (e) {
        console.warn('Analytics loading error:', e);
      }
    }

    document.addEventListener('DOMContentLoaded', () => {
      initGlobalOwnerSearch();
      loadDashboardAnalytics();
      const urlParams = new URLSearchParams(window.location.search);
      if (urlParams.get('tab') === 'archived' || urlParams.get('section') === 'archived' || window.location.hash === '#directoryArchivedView') {
        if (typeof switchSection === 'function' && typeof switchDirectoryTab === 'function') {
          switchSection('sectionDirectory', document.getElementById('navTenantDirectory'));
          switchDirectoryTab('archived');
        }
      }
    });
  </script>
  <!-- Real-time Centralized Notification Client Handler -->
  <script src="assets/js/notifications.js"></script>
  <script>
    (function() {
      let notifFetchInFlight = false;

      function escNotifHtml(str) {
        if (!str) return '';
        return String(str)
          .replace(/&/g, '&amp;')
          .replace(/</g, '&lt;')
          .replace(/>/g, '&gt;')
          .replace(/"/g, '&quot;')
          .replace(/'/g, '&#039;');
      }

      function getNotifIconMarkup(type) {
        switch (type) {
          case 'payment':
            return '<div class="notif-icon-circle notif-icon-payment"><i data-lucide="receipt" aria-hidden="true"></i></div>';
          case 'maintenance':
            return '<div class="notif-icon-circle notif-icon-maintenance"><i data-lucide="wrench" aria-hidden="true"></i></div>';
          case 'announcement':
            return '<div class="notif-icon-circle notif-icon-announcement"><i data-lucide="bell" aria-hidden="true"></i></div>';
          case 'account':
          default:
            return '<div class="notif-icon-circle notif-icon-account"><i data-lucide="user-round" aria-hidden="true"></i></div>';
        }
      }

      function renderFriendlyEmptyState() {
        const listContainer = document.getElementById('notificationListContainer');
        if (!listContainer) return;
        listContainer.innerHTML = `
          <div class="notification-empty-state">
            <div class="empty-icon"><i data-lucide="bell-off" aria-hidden="true"></i></div>
            <h4>No Notifications Yet</h4>
            <p>You're all caught up! No new notifications at this time.</p>
          </div>
        `;
        if (window.lucide && typeof window.lucide.createIcons === 'function') {
          window.lucide.createIcons();
        }
      }

      function renderNotificationListSafe(data) {
        const unreadCount = parseInt((data && data.unread_count) || 0, 10) || 0;
        const badge = document.getElementById('notificationBadge');
        const unreadPill = document.getElementById('notificationUnreadPill');
        const listContainer = document.getElementById('notificationListContainer');

        if (badge) {
          if (unreadCount > 0) {
            badge.textContent = unreadCount > 99 ? '99+' : String(unreadCount);
            badge.style.display = 'flex';
          } else {
            badge.style.display = 'none';
          }
        }

        if (unreadPill) {
          if (unreadCount > 0) {
            unreadPill.textContent = `${unreadCount} new`;
            unreadPill.style.display = 'inline-block';
          } else {
            unreadPill.style.display = 'none';
          }
        }

        if (!listContainer) return;
        const items = (data && Array.isArray(data.notifications)) ? data.notifications : [];
        if (items.length === 0) {
          renderFriendlyEmptyState();
          return;
        }

        listContainer.innerHTML = items.map(n => {
          const isUnread = parseInt(n.is_read, 10) === 0;
          return `
            <div class="notification-item ${isUnread ? 'unread' : 'read'}"
                 data-id="${n.id}"
                 data-link="${escNotifHtml(n.link || '')}"
                 data-ref-id="${escNotifHtml(n.reference_id || '')}"
                 data-ref-type="${escNotifHtml(n.reference_type || '')}"
                 data-type="${escNotifHtml(n.type || '')}"
                 onclick="handleNotificationClick(event, ${n.id}, '${escNotifHtml(n.link || '')}', '${escNotifHtml(n.reference_id || '')}', '${escNotifHtml(n.reference_type || '')}', '${escNotifHtml(n.type || '')}')"
                 role="button"
                 tabindex="0">
              ${getNotifIconMarkup(n.type)}
              <div class="notif-content">
                <div class="notif-title">${escNotifHtml(n.title)}</div>
                <div class="notif-message">${escNotifHtml(n.message)}</div>
                <div class="notif-time">${escNotifHtml(n.time_ago || '')}${n.formatted_date ? ' • ' + escNotifHtml(n.formatted_date) : ''}</div>
              </div>
              ${isUnread ? '<span class="notification-unread-dot" title="Unread"></span>' : ''}
            </div>
          `;
        }).join('');

        if (window.lucide && typeof window.lucide.createIcons === 'function') {
          window.lucide.createIcons();
        }
      }

      function ensureBellAlwaysInteractive() {
        const bellBtn = document.getElementById('headerNotificationBtn');
        if (!bellBtn) return;
        bellBtn.disabled = false;
        bellBtn.removeAttribute('disabled');
        bellBtn.removeAttribute('aria-disabled');
        bellBtn.style.pointerEvents = 'auto';
        bellBtn.style.cursor = 'pointer';
      }

      window.loadNotifications = function(silent = false) {
        if (notifFetchInFlight) return;
        notifFetchInFlight = true;
        fetch('api/notification_api.php?action=list', { cache: 'no-store' })
          .then(res => {
            if (!res.ok) throw new Error('HTTP ' + res.status);
            return res.json();
          })
          .then(data => {
            notifFetchInFlight = false;
            if (data && data.status === 'success') {
              renderNotificationListSafe(data);
            } else {
              renderFriendlyEmptyState();
            }
          })
          .catch(err => {
            notifFetchInFlight = false;
            const listContainer = document.getElementById('notificationListContainer');
            if (listContainer && !listContainer.querySelector('.notification-item')) {
              renderFriendlyEmptyState();
            }
            if (!silent) console.warn('Notification load notice:', err);
          });
      };

      window.closeNotificationPanel = function() {
        const notifPanel = document.getElementById('notificationDropdown');
        const bellBtn = document.getElementById('headerNotificationBtn');
        if (notifPanel) notifPanel.classList.remove('show');
        if (bellBtn) bellBtn.setAttribute('aria-expanded', 'false');
      };

      window.toggleNotificationPanel = function(e) {
        if (e) {
          e.preventDefault();
          e.stopPropagation();
        }
        ensureBellAlwaysInteractive();

        const settingsPanel = document.getElementById('settingsDropdown');
        if (settingsPanel) settingsPanel.classList.remove('show');
        if (typeof closeGlobalSearchDropdown === 'function') {
          closeGlobalSearchDropdown();
        }

        const notifPanel = document.getElementById('notificationDropdown');
        const bellBtn = document.getElementById('headerNotificationBtn');
        if (!notifPanel) return;

        const isShowing = notifPanel.classList.toggle('show');
        if (bellBtn) bellBtn.setAttribute('aria-expanded', isShowing ? 'true' : 'false');

        if (isShowing) {
          const listContainer = document.getElementById('notificationListContainer');
          if (listContainer && !listContainer.querySelector('.notification-item') && !listContainer.querySelector('.notification-empty-state')) {
            renderFriendlyEmptyState();
          }
          window.loadNotifications(true);
        }
      };

      document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') {
          const notifPanel = document.getElementById('notificationDropdown');
          if (notifPanel && notifPanel.classList.contains('show')) {
            window.closeNotificationPanel();
          }
        }
      });

      document.addEventListener('click', function(e) {
        const notifPanel = document.getElementById('notificationDropdown');
        if (notifPanel && notifPanel.classList.contains('show')) {
          if (!e.target.closest('#headerNotificationBtn') && !e.target.closest('#notificationDropdown')) {
            window.closeNotificationPanel();
          }
        }
      });

      ensureBellAlwaysInteractive();
      if (document.readyState !== 'loading') {
        window.loadNotifications(true);
      } else {
        document.addEventListener('DOMContentLoaded', () => {
          ensureBellAlwaysInteractive();
          window.loadNotifications(true);
        });
      }
    })();
  </script>
</body>
</html>
