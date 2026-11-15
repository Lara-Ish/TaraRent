<?php
/**
 * TaraRent - Shared RBAC-Protected Retractable Sidebar Component
 *
 * Renders an identical structural sidebar DOM for both Owner/Landlord and Tenant accounts
 * while strictly enforcing session role-based access control (RBAC) and applying role-specific
 * visual theme utility classes (.owner-sidebar-theme vs .tenant-sidebar-theme).
 */

declare(strict_types=1);

if (!class_exists('Auth') || !Auth::check()) {
    return;
}

$sessionRole = strtolower(trim((string)($_SESSION['role'] ?? '')));
$requestedPortal = isset($sidebarConfig['portal']) ? strtolower(trim((string)$sidebarConfig['portal'])) : $sessionRole;

// Strict fail-closed RBAC verification: session role must authorize the portal sidebar being rendered
$isAuthorizedLandlord = ($sessionRole === 'landlord' && $requestedPortal === 'landlord');
$isAuthorizedTenant   = ($sessionRole === 'tenant'   && $requestedPortal === 'tenant');

if (!$isAuthorizedLandlord && !$isAuthorizedTenant) {
    return;
}

$themeClass = $isAuthorizedLandlord ? 'owner-sidebar-theme' : 'tenant-sidebar-theme';
$portalSubtitle = $isAuthorizedLandlord ? 'Owner Portal' : 'Resident Portal';
$menuCategoryLabel = $isAuthorizedLandlord ? 'Owner Modules' : 'Menu Categories';
$navAriaLabel = $isAuthorizedLandlord ? 'Owner Navigation Sidebar' : 'Tenant Navigation Sidebar';
$persistKey = $isAuthorizedLandlord ? 'tararent_owner_sidebar_collapsed' : 'tararent_tenant_sidebar_collapsed';
$footerHtml = (string)($sidebarConfig['footer_html'] ?? '');

$authorizedNavItems = [];

if ($isAuthorizedLandlord) {
    $activeTicketsCount = (int)($sidebarConfig['active_tickets_count'] ?? 0);
    $pendingReportsCount = (int)($sidebarConfig['pending_reports_count'] ?? 0);
    $pendingPaymentsCount = (int)($sidebarConfig['pending_payments_count'] ?? 0);

    $authorizedNavItems = [
        [
            'id'      => 'navLandlordDashboard',
            'section' => 'sectionAnalytics',
            'onclick' => "switchSection('sectionAnalytics', this)",
            'label'   => 'Dashboard',
            'active'  => true,
            'icon'    => '<i data-lucide="layout-dashboard" class="w-6 h-6" aria-hidden="true"></i>',
        ],
        [
            'id'      => 'navTenantDirectory',
            'section' => 'sectionDirectory',
            'onclick' => "switchSection('sectionDirectory', this)",
            'label'   => 'Tenant Directory',
            'active'  => false,
            'icon'    => '<i data-lucide="users" class="w-6 h-6" aria-hidden="true"></i>',
        ],
        [
            'id'          => 'navMaintenanceTickets',
            'section'     => 'sectionTickets',
            'onclick'     => "switchSection('sectionTickets', this)",
            'label'       => 'Maintenance',
            'active'      => false,
            'badge_id'    => 'sidebarTicketCountBadge',
            'badge_count' => $activeTicketsCount,
            'icon'        => '<i data-lucide="wrench" class="w-6 h-6" aria-hidden="true"></i>',
        ],
        [
            'id'          => 'navTenantReports',
            'section'     => 'sectionReports',
            'onclick'     => "switchSection('sectionReports', this)",
            'label'       => 'Tenant Reports',
            'active'      => false,
            'badge_id'    => 'sidebarReportCountBadge',
            'badge_count' => $pendingReportsCount,
            'icon'        => '<i data-lucide="file-text" class="w-6 h-6" aria-hidden="true"></i>',
        ],
        [
            'id'          => 'navPaymentVerification',
            'section'     => 'sectionPayments',
            'onclick'     => "switchSection('sectionPayments', this)",
            'label'       => 'Payment Verification',
            'active'      => false,
            'badge_id'    => 'sidebarPaymentCountBadge',
            'badge_count' => $pendingPaymentsCount,
            'icon'        => '<i data-lucide="credit-card" class="w-6 h-6" aria-hidden="true"></i>',
        ],
    ];
} elseif ($isAuthorizedTenant) {
    $currentTenantSection = $_GET['section'] ?? 'tenantOverview';

    // Determine tenant verification and tenancy status mode
    $tenantSessionId = (int)($_SESSION['user_id'] ?? 0);
    $tenantStatusMode = $sidebarConfig['tenant_status_mode'] ?? null;
    $isTenantVerified = isset($sidebarConfig['is_verified']) ? (bool)$sidebarConfig['is_verified'] : null;
    $isMovedOutUnpaid = isset($sidebarConfig['is_moved_out_unpaid']) ? (bool)$sidebarConfig['is_moved_out_unpaid'] : null;

    if (($tenantStatusMode === null || $isTenantVerified === null || $isMovedOutUnpaid === null) && $tenantSessionId > 0 && class_exists('Database')) {
        try {
            $dbPdo = Database::getConnection();
            if ($dbPdo && class_exists('AccessControl')) {
                $statusData = AccessControl::getTenantStatus($dbPdo, $tenantSessionId);
                $tenantStatusMode = $statusData['mode'];
                $isTenantVerified = $statusData['is_verified'];
                $isMovedOutUnpaid = ($tenantStatusMode === 'debt_collection');
            }
        } catch (\Throwable $e) {}
    }

    $isTenantVerified = (bool)($isTenantVerified ?? false);
    $isMovedOutUnpaid = (bool)($isMovedOutUnpaid ?? false);

    // Rule 1 & Rule 3 Nav Restrictions:
    // 1. Unverified: Re-enable Log Maintenance, Upload Payment, and Report so tenants can browse freely.
    // 3. Debt Collection: restrict to Dashboard & Upload Payment; disable Log Maintenance & Report.
    $isMaintenanceDisabled = $isMovedOutUnpaid;
    $isPaymentsDisabled = false;
    $isReportDisabled = $isMovedOutUnpaid;

    $maintenanceDisabledReason = 'Access restricted: Settle unpaid balance first';
    $paymentsDisabledReason = '';
    $reportDisabledReason = 'Access restricted: Settle unpaid balance first';

    $tenantNavDefinitions = [
        [
            'id'              => 'navTenantOverview',
            'section'         => 'tenantOverview',
            'onclick'         => "switchTenantSection('tenantOverview', this)",
            'label'           => 'Dashboard',
            'icon'            => '<i data-lucide="layout-dashboard" class="w-6 h-6 text-gray-700" aria-hidden="true"></i>',
            'disabled'        => false,
            'disabled_reason' => '',
        ],
        [
            'id'              => 'navTenantMaintenance',
            'section'         => 'tenantMaintenance',
            'onclick'         => $isMaintenanceDisabled ? '' : "switchTenantSection('tenantMaintenance', this)",
            'label'           => 'Log Maintenance',
            'icon'            => '<i data-lucide="wrench" class="w-6 h-6 text-gray-700" aria-hidden="true"></i>',
            'disabled'        => $isMaintenanceDisabled,
            'disabled_reason' => $maintenanceDisabledReason,
        ],
        [
            'id'              => 'navTenantPayments',
            'section'         => 'tenantPayments',
            'onclick'         => $isPaymentsDisabled ? '' : "switchTenantSection('tenantPayments', this)",
            'label'           => 'Upload Payment',
            'icon'            => '<i data-lucide="credit-card" class="w-6 h-6 text-gray-700" aria-hidden="true"></i>',
            'disabled'        => $isPaymentsDisabled,
            'disabled_reason' => $paymentsDisabledReason,
        ],
        [
            'id'              => 'navTenantReport',
            'section'         => 'tenantReport',
            'onclick'         => $isReportDisabled ? '' : "switchTenantSection('tenantReport', this)",
            'label'           => 'Report',
            'icon'            => '<i data-lucide="file-text" class="w-6 h-6 text-gray-700" aria-hidden="true"></i>',
            'disabled'        => $isReportDisabled,
            'disabled_reason' => $reportDisabledReason,
        ],
    ];

    $hasMatchedActive = false;
    foreach ($tenantNavDefinitions as $item) {
        if (!$item['disabled'] && $item['section'] === $currentTenantSection) {
            $hasMatchedActive = true;
            break;
        }
    }

    $authorizedNavItems = array_map(function ($item) use ($currentTenantSection, $hasMatchedActive) {
        $isActive = !$item['disabled'] && ($hasMatchedActive ? ($item['section'] === $currentTenantSection) : ($item['section'] === 'tenantOverview'));
        return [
            'id'              => $item['id'],
            'section'         => $item['section'],
            'onclick'         => $item['onclick'],
            'label'           => $item['label'],
            'active'          => $isActive,
            'disabled'        => $item['disabled'],
            'disabled_reason' => $item['disabled_reason'] ?? '',
            'icon'            => $item['icon'],
        ];
    }, $tenantNavDefinitions);
}
?>
<!-- Overlay for Mobile Retractable Sidebar -->
<div class="sidebar-overlay" onclick="closeSidebarMobile()"></div>

<!-- Full App Shell with Shared Retractable Sidebar -->
<div class="app-shell">
  <aside class="clay-sidebar <?= htmlspecialchars($themeClass) ?>"
         id="appSidebar"
         data-role="<?= htmlspecialchars($sessionRole) ?>"
         data-persist-key="<?= htmlspecialchars($persistKey) ?>"
         aria-label="<?= htmlspecialchars($navAriaLabel) ?>">
    <div>
      <!-- Brand Badge & Inner Sidebar Toggle (Strictly inside sidebar) -->
      <div class="sidebar-top-header">
        <div class="brand-wrapper" style="padding: 0; margin-bottom: 0;">
          <div class="brand-icon-clay sidebar-brand-logo-wrap">
            <img src="assets/images/tararent-logo.png" alt="TaraRent Logo" class="sidebar-brand-logo-img">
          </div>
          <div class="hide-on-retract">
            <div class="sidebar-brand-title">TaraRent</div>
            <div class="sidebar-brand-subtitle"><?= htmlspecialchars($portalSubtitle) ?></div>
          </div>
        </div>
        <button type="button"
                id="sidebarToggleBtn"
                class="clay-toggle-btn sidebar-inner-toggle"
                onclick="toggleSidebar()"
                aria-label="Toggle Sidebar"
                aria-expanded="true"
                title="Toggle Sidebar">
          <i data-lucide="menu" class="w-5 h-5 pointer-events-none" style="pointer-events: none;" aria-hidden="true"></i>
        </button>
      </div>

      <!-- Sidebar Navigation Menu -->
      <nav class="sidebar-menu" aria-label="<?= htmlspecialchars($navAriaLabel) ?>">
        <div class="sidebar-category-label hide-on-retract"><?= htmlspecialchars($menuCategoryLabel) ?></div>

        <?php foreach ($authorizedNavItems as $item): ?>
          <?php
            $isDisabled = !empty($item['disabled']);
            $btnClasses = 'sidebar-nav-item flex justify-between items-center w-full'
              . (!empty($item['active']) ? ' active' : '')
              . ($isDisabled ? ' opacity-50 pointer-events-none cursor-not-allowed nav-item-disabled' : '');
            $itemTitle = $isDisabled ? (!empty($item['disabled_reason']) ? $item['disabled_reason'] : 'Access restricted') : $item['label'];
          ?>
          <button type="button"
                  class="<?= htmlspecialchars($btnClasses) ?>"
                  id="<?= htmlspecialchars($item['id']) ?>"
                  data-section="<?= htmlspecialchars($item['section']) ?>"
                  <?= $isDisabled ? 'disabled aria-disabled="true" tabindex="-1"' : (!empty($item['onclick']) ? 'onclick="' . htmlspecialchars($item['onclick']) . '"' : '') ?>
                  title="<?= htmlspecialchars($itemTitle) ?>"
                  aria-label="<?= htmlspecialchars($item['label']) ?>"<?= !empty($item['active']) ? ' aria-current="page"' : '' ?>
                  style="display: flex; justify-content: space-between; align-items: center; width: 100%; <?= $isDisabled ? 'opacity: 0.5; pointer-events: none; cursor: not-allowed;' : '' ?>">
            <span class="flex items-center gap-3" style="display: flex; align-items: center; gap: 12px;">
              <span class="nav-icon"><?= $item['icon'] ?></span>
              <span class="hide-on-retract nav-label font-medium"><?= htmlspecialchars($item['label']) ?></span>
            </span>
            <?php if ($isDisabled): ?>
              <span class="hide-on-retract text-gray-500 ml-auto flex items-center" style="margin-left: auto; display: flex; align-items: center;" title="<?= htmlspecialchars($itemTitle) ?>">
                <i data-lucide="lock" class="w-4 h-4 text-gray-500" style="width: 16px; height: 16px;" aria-hidden="true"></i>
              </span>
            <?php elseif (!empty($item['badge_id']) && !empty($item['badge_count']) && (int)$item['badge_count'] > 0): ?>
              <span class="sidebar-rail-badge" id="<?= htmlspecialchars($item['badge_id']) ?>"><?= (int)$item['badge_count'] ?></span>
            <?php endif; ?>
          </button>
        <?php endforeach; ?>
      </nav>
    </div>

    <?php if ($footerHtml !== ''): ?>
    <!-- Sidebar Footer -->
    <div class="sidebar-footer">
      <div class="hide-on-retract sidebar-footer-meta"><?= $footerHtml ?></div>
    </div>
    <?php endif; ?>
  </aside>
  <script>
    (function () {
      try {
        if (window.innerWidth > 960 && localStorage.getItem(<?= json_encode($persistKey) ?>) === '1') {
          var sb = document.getElementById('appSidebar');
          if (sb) sb.classList.add('retracted');
        }
        if (window.lucide && typeof window.lucide.createIcons === 'function') {
          window.lucide.createIcons();
        }
      } catch (e) {}
    })();
  </script>
