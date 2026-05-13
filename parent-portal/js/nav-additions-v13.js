/* ═══════════════════════════════════════════════════════════════════
   KIDDIETRAC v13 — Navigation additions
   ═══════════════════════════════════════════════════════════════════ */
(function (window) {
  'use strict';

  function getUser() { try { return JSON.parse(sessionStorage.getItem('kt_user') || localStorage.getItem('kt_user') || '{}'); } catch (e) { return {}; } }
  function getRole() { const u = getUser(); return u.primary_role || (u.roles && u.roles[0]) || 'guest'; }
  function token() { return sessionStorage.getItem('kt_token') || localStorage.getItem('kt_token'); }

  function findNav() {
    return document.querySelector('.app-nav')
        || document.querySelector('.kt-sidebar')
        || document.querySelector('[class*="sidebar"]')
        || document.querySelector('nav');
  }

  function getMainContainer() {
    return document.querySelector('.kt-main')
        || document.querySelector('main')
        || document.querySelector('#main')
        || document.querySelector('[class*="main-content"]')
        || document.body;
  }

  function injectNav() {
    if (window.KT_V17_NAV_INSTALLED) return; // v17: nav owned by app-v2-shell
    const sidebar = findNav();
    if (!sidebar) { setTimeout(injectNav, 300); return; }

    const role = getRole();
    if (role === 'guest') return;

    let extra = document.getElementById('kt-v13-nav-extra');
    if (!extra) {
      extra = document.createElement('div');
      extra.id = 'kt-v13-nav-extra';
      extra.style.cssText = 'display:flex;flex-direction:column;gap:2px;padding:4px 0;';
      sidebar.appendChild(extra);
    }
    if (extra.querySelector('[data-route="waitlist"]')) return;

    // Director/admin: waitlist + announcements
    if (role === 'centre_director' || role === 'agency_admin') {
      extra.appendChild(makeNavBtn('waitlist', '⏳ Waitlist'));
      extra.appendChild(makeNavBtn('announcements', '📢 Announcements'));
    }
    // Educator: announcements (compose for their room)
    else if (role === 'educator') {
      extra.appendChild(makeNavBtn('announcements', '📢 Announcements'));
    }
    // Parent: announcements (inbox) + autopay
    else if (role === 'guardian') {
      extra.appendChild(makeNavBtn('announcements', '📢 Announcements'));
      extra.appendChild(makeNavBtn('autopay', '💳 Autopay'));
    }
  }

  function makeNavBtn(routeId, label) {
    const btn = document.createElement('a');
    btn.setAttribute('data-route', routeId);
    btn.href = '#' + routeId;
    btn.style.cssText = 'display:flex;align-items:center;gap:8px;padding:10px 16px;color:#374151;text-decoration:none;font-size:14px;font-weight:600;border-radius:8px;margin:2px 8px;cursor:pointer;';
    btn.textContent = label;
    btn.addEventListener('mouseenter', () => btn.style.background = '#F3F4F6');
    btn.addEventListener('mouseleave', () => btn.style.background = 'transparent');
    btn.addEventListener('click', (e) => {
      e.preventDefault();
      window.location.hash = '#' + routeId;
      route();
    });
    return btn;
  }

  function route() {
    const hash = window.location.hash.replace('#', '').toLowerCase().split('/')[0];
    const container = getMainContainer();
    if (!container) return;

    if (hash === 'waitlist' && window.KT && window.KT.Waitlist) {
      window.KT.Waitlist.render(container);
    } else if (hash === 'announcements' && window.KT && window.KT.Announcements) {
      window.KT.Announcements.render(container);
    } else if (hash === 'autopay' && window.KT && window.KT.Autopay) {
      window.KT.Autopay.render(container);
    }
  }

  function init() {
    if (!token()) return;
    injectNav();
    route();
    window.addEventListener('hashchange', route);
  }

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init);
  else init();

  window.addEventListener('storage', (e) => { if (e.key === 'kt_token') setTimeout(init, 200); });
})(window);
