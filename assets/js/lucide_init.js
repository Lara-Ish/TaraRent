/**
 * Shared Lucide icon initializer & helper for TaraRent.
 * Ensures all static and dynamically-injected [data-lucide] nodes
 * render with uniform stroke-width="2" and inherit CSS currentColor.
 */
(function () {
  'use strict';

  function sanitizeAttr(val) {
    return String(val == null ? '' : val).replace(/"/g, '&quot;');
  }

  /**
   * Returns an HTML string for a Lucide icon placeholder.
   * @param {string} name - Lucide icon kebab-case name (e.g. 'alert-triangle')
   * @param {string} [extraClass=''] - Additional CSS classes
   * @param {string} [extraAttrs=''] - Additional HTML attributes (e.g. style="width:16px;height:16px")
   */
  window.rcIcon = function rcIcon(name, extraClass = '', extraAttrs = '') {
    const cls = extraClass ? ` class="${sanitizeAttr(extraClass)}"` : '';
    const attrs = extraAttrs ? ` ${extraAttrs}` : '';
    return `<i data-lucide="${sanitizeAttr(name)}"${cls}${attrs} aria-hidden="true"></i>`;
  };

  let rafScheduled = false;

  function renderAllIcons() {
    rafScheduled = false;
    if (!window.lucide || typeof window.lucide.createIcons !== 'function') return;
    if (!document.querySelector('[data-lucide]')) return;
    try {
      window.lucide.createIcons({
        attrs: {
          'stroke-width': '2',
        },
      });
    } catch (e) {
      // Ignore transient DOM mutation errors during icon swap
    }
  }

  window.refreshLucideIcons = function refreshLucideIcons() {
    renderAllIcons();
  };

  function scheduleRender() {
    if (rafScheduled) return;
    rafScheduled = true;
    if (typeof window.requestAnimationFrame === 'function') {
      window.requestAnimationFrame(renderAllIcons);
    } else {
      setTimeout(renderAllIcons, 0);
    }
  }

  function startObserver() {
    renderAllIcons();
    if (typeof MutationObserver === 'undefined' || !document.body) return;

    const observer = new MutationObserver((mutations) => {
      for (const m of mutations) {
        if (m.type === 'childList' && m.addedNodes && m.addedNodes.length > 0) {
          for (const node of m.addedNodes) {
            if (node.nodeType !== 1) continue;
            if (
              (node.hasAttribute && node.hasAttribute('data-lucide')) ||
              (node.querySelector && node.querySelector('[data-lucide]'))
            ) {
              scheduleRender();
              return;
            }
          }
        } else if (m.type === 'attributes' && m.attributeName === 'data-lucide') {
          scheduleRender();
          return;
        }
      }
    });

    observer.observe(document.body, {
      childList: true,
      subtree: true,
      attributes: true,
      attributeFilter: ['data-lucide'],
    });
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', startObserver);
  } else {
    startObserver();
  }
})();
