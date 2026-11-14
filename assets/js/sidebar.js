/**
 * TaraRent - Shared Retractable Sidebar Behavior & Responsive State Manager
 * Used identically by both Owner (admin_dashboard.php) and Tenant (tenant_portal.php) views.
 */
(function (window, document) {
  'use strict';

  function syncSidebarToggleUI() {
    const sidebar = document.getElementById('appSidebar');
    const toggleBtn = document.getElementById('sidebarToggleBtn');
    const mobileTrigger = document.querySelector('.mobile-menu-trigger');
    if (!sidebar) return;

    const isMobile = window.innerWidth <= 960;
    const isExpanded = isMobile
      ? sidebar.classList.contains('mobile-open')
      : !sidebar.classList.contains('retracted');

    if (toggleBtn) {
      const label = isMobile
        ? (isExpanded ? 'Close Sidebar' : 'Open Sidebar')
        : (isExpanded ? 'Collapse Sidebar' : 'Expand Sidebar');
      toggleBtn.setAttribute('aria-expanded', isExpanded ? 'true' : 'false');
      toggleBtn.setAttribute('aria-label', label);
      toggleBtn.setAttribute('title', label);
    }

    if (mobileTrigger) {
      mobileTrigger.setAttribute(
        'aria-expanded',
        isMobile && sidebar.classList.contains('mobile-open') ? 'true' : 'false'
      );
    }
  }

  function closeSidebarMobile() {
    const sidebar = document.getElementById('appSidebar');
    const overlay = document.querySelector('.sidebar-overlay');
    if (sidebar) sidebar.classList.remove('mobile-open');
    if (overlay) {
      overlay.classList.remove('active');
      overlay.style.display = 'none';
      overlay.style.pointerEvents = 'none';
    }
    syncSidebarToggleUI();
  }

  function toggleSidebar() {
    const sidebar = document.getElementById('appSidebar');
    const overlay = document.querySelector('.sidebar-overlay');
    if (!sidebar) return;

    if (window.innerWidth <= 960) {
      const isOpen = sidebar.classList.toggle('mobile-open');
      if (overlay) {
        overlay.classList.toggle('active', isOpen);
        overlay.style.display = isOpen ? 'block' : 'none';
        overlay.style.pointerEvents = isOpen ? 'auto' : 'none';
      }
    } else {
      const isRetracted = sidebar.classList.toggle('retracted');
      const persistKey = sidebar.dataset.persistKey;
      if (persistKey) {
        try {
          localStorage.setItem(persistKey, isRetracted ? '1' : '0');
        } catch (e) {}
      }
    }
    syncSidebarToggleUI();
  }

  function setActiveSidebarNavItem(sectionId, explicitBtn) {
    document.querySelectorAll('#appSidebar .sidebar-nav-item').forEach(function (btn) {
      btn.classList.remove('active');
      btn.removeAttribute('aria-current');
    });

    let activeBtn = explicitBtn || null;
    if (!activeBtn && sectionId) {
      activeBtn =
        document.querySelector(`#appSidebar .sidebar-nav-item[data-section="${sectionId}"]`) ||
        document.querySelector(`#appSidebar .sidebar-nav-item[onclick*="${sectionId}"]`);
    }
    if (activeBtn) {
      activeBtn.classList.add('active');
      activeBtn.setAttribute('aria-current', 'page');
    }
    return activeBtn;
  }

  function initSharedSidebar() {
    syncSidebarToggleUI();

    document.querySelectorAll('#appSidebar .sidebar-nav-item').forEach(function (item) {
      if (item.dataset.sidebarNavBound === '1') return;
      item.dataset.sidebarNavBound = '1';
      item.addEventListener('click', function () {
        if (typeof window.closeSettingsMenu === 'function') {
          window.closeSettingsMenu();
        }
        if (window.innerWidth <= 960) {
          closeSidebarMobile();
        }
      });
    });

    if (!window.__taraSidebarResizeBound) {
      window.__taraSidebarResizeBound = true;
      window.addEventListener('resize', function () {
        if (window.innerWidth > 960) {
          closeSidebarMobile();
        } else {
          syncSidebarToggleUI();
        }
      });
    }
  }

  window.syncSidebarToggleUI = syncSidebarToggleUI;
  window.closeSidebarMobile = closeSidebarMobile;
  window.toggleSidebar = toggleSidebar;
  window.setActiveSidebarNavItem = setActiveSidebarNavItem;
  window.initSharedSidebar = initSharedSidebar;

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initSharedSidebar);
  } else {
    initSharedSidebar();
  }
})(window, document);
