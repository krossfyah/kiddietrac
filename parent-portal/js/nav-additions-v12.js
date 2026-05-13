/* ═══════════════════════════════════════════════════════════════════
   KIDDIETRAC v12-big — Navigation additions
   - Adds "💬 Messages" nav item for parents AND providers
   - Adds "🏢 Agencies" nav item for agency_admins
   - Wires hash-based routing for #chat and #agencies
   - Renders the unread-count badge next to "Messages"
   ═══════════════════════════════════════════════════════════════════ */
(function (window) {
  'use strict';

  function getUser() {
    try { return JSON.parse(sessionStorage.getItem('kt_user') || localStorage.getItem('kt_user') || '{}'); }
    catch (e) { return {}; }
  }
  function getRole() {
    const u = getUser();
    return u.primary_role || (u.roles && u.roles[0]) || 'guest';
  }
  function token() { return sessionStorage.getItem('kt_token') || localStorage.getItem('kt_token'); }

  // ─── Insert nav items ─────────────────────────────────────────
  function injectNav() {
    if (window.KT_V17_NAV_INSTALLED) return; // v17: nav owned by app-v2-shell
    // Find the existing sidebar (try multiple selectors)
    const sidebar = document.querySelector('.app-nav') || document.querySelector('.kt-sidebar')
                || document.querySelector('[class*="sidebar"]')
                || document.querySelector('nav[class*="side"]');
    if (!sidebar) {
      // Retry after a delay — shell may not be rendered yet
      setTimeout(injectNav, 300);
      return;
    }

    const role = getRole();
    if (role === 'guest') return;

    // Build a target container for our extra items
    let extra = document.getElementById('kt-v12-nav-extra');
    if (!extra) {
      extra = document.createElement('div');
      extra.id = 'kt-v12-nav-extra';
      extra.style.cssText = 'display:flex;flex-direction:column;gap:2px;padding:4px 0;';
      sidebar.appendChild(extra);
    }

    if (extra.querySelector('[data-route="chat"]')) {
      // already injected
      return;
    }

    // Always: Chat nav (everyone except auditor)
    if (role !== 'auditor') {
      extra.appendChild(makeNavBtn('chat', '💬 Messages', true));
    }

    // Agency admin only: Agencies nav
    if (role === 'agency_admin') {
      extra.appendChild(makeNavBtn('agencies', '🏢 Agencies'));
    }

    // After injection, fetch unread count
    if (window.KT && window.KT.refreshUnreadBadge) window.KT.refreshUnreadBadge();
  }

  function makeNavBtn(routeId, label, withBadge) {
    const btn = document.createElement('a');
    btn.setAttribute('data-route', routeId);
    btn.href = '#' + routeId;
    btn.style.cssText = 'display:flex;align-items:center;gap:8px;padding:10px 16px;color:#374151;text-decoration:none;font-size:14px;font-weight:600;border-radius:8px;margin:2px 8px;cursor:pointer;transition:background 0.15s;';
    btn.innerHTML = label + (withBadge ? ' <span id="kt-chat-nav-badge" style="display:none;background:#DC2626;color:white;font-size:10px;font-weight:700;padding:1px 7px;border-radius:10px;margin-left:auto;">0</span>' : '');
    btn.addEventListener('mouseenter', () => btn.style.background = '#F3F4F6');
    btn.addEventListener('mouseleave', () => btn.style.background = 'transparent');
    btn.addEventListener('click', (e) => {
      e.preventDefault();
      window.location.hash = '#' + routeId;
      route();
    });
    return btn;
  }

  // ─── Hash router ──────────────────────────────────────────────
  function getMainContainer() {
    return document.querySelector('.kt-main')
        || document.querySelector('main')
        || document.querySelector('#main')
        || document.querySelector('[class*="main-content"]')
        || document.querySelector('[class*="content-area"]')
        || document.body;
  }

  function route() {
    const hash = window.location.hash.replace('#', '').toLowerCase().split('/')[0];
    const container = getMainContainer();
    if (!container) return;

    if (hash === 'chat' || hash === 'messages') {
      if (window.KT && window.KT.Chat) {
        window.KT.Chat.mount(container, { role: getRole() });
      } else {
        container.innerHTML = '<div style="padding:32px;color:#DC2626;">Chat module not loaded.</div>';
      }
    } else if (hash === 'agencies' && getRole() === 'agency_admin') {
      if (window.KT && window.KT.Agencies) {
        window.KT.Agencies.render(container);
      } else {
        container.innerHTML = '<div style="padding:32px;color:#DC2626;">Agencies module not loaded.</div>';
      }
    }
    // Other hashes: leave alone; the existing shell handles them
  }

  // ─── Init ──────────────────────────────────────────────────────
  function init() {
    if (!token()) return;
    injectNav();
    route();
    window.addEventListener('hashchange', route);
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
  // Re-init after login (token gets set)
  window.addEventListener('storage', (e) => { if (e.key === 'kt_token') setTimeout(init, 200); });
})(window);
