/* ═══════════════════════════════════════════════════════════════════
   KIDDIETRAC v15 — Navigation additions (Reseller / Admin)
   ─────────────────────────────────────────────────────────────────
   Adds nav entries for:
   - platform_admin: 💰 MRR, ⚙️ Feature Flags
   - agency_admin:   🎨 Branding
   Wires hash-based routing to render screens.
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
  function getMain() {
    return document.querySelector('.kt-main') || document.querySelector('main') || document.querySelector('#main') || document.body;
  }

  function injectNav() {
    if (window.KT_V17_NAV_INSTALLED) return; // v17: nav owned by app-v2-shell
    const sidebar = findNav();
    if (!sidebar) { setTimeout(injectNav, 300); return; }
    const role = getRole();
    if (role === 'guest') return;

    let extra = document.getElementById('kt-v15-nav-extra');
    if (!extra) {
      extra = document.createElement('div');
      extra.id = 'kt-v15-nav-extra';
      extra.style.cssText = 'display:flex;flex-direction:column;gap:2px;padding:4px 0;border-top:1px solid #E5E7EB;margin-top:8px;';
      sidebar.appendChild(extra);
    }
    if (extra.querySelector('[data-route="admin-mrr"]') || extra.querySelector('[data-route="admin-branding"]')) return;

    if (role === 'platform_admin') {
      extra.appendChild(sectionLabel('Reseller'));
      extra.appendChild(makeNavBtn('admin-mrr',      '💰 MRR Dashboard'));
      extra.appendChild(makeNavBtn('admin-features', '⚙️ Feature Flags'));
      extra.appendChild(makeNavBtn('marketing-site', '🌐 Website'));
    }
    if (role === 'agency_admin' || role === 'platform_admin') {
      extra.appendChild(makeNavBtn('admin-branding', '🎨 White-Label Branding'));
    }
  }

  function sectionLabel(text) {
    const d = document.createElement('div');
    d.style.cssText = 'padding:8px 16px 4px;font-size:11px;font-weight:700;color:#9CA3AF;text-transform:uppercase;letter-spacing:1px;';
    d.textContent = text;
    return d;
  }

  function makeNavBtn(routeId, label) {
    const btn = document.createElement('a');
    btn.setAttribute('data-route', routeId);
    btn.href = '#' + routeId;
    btn.style.cssText = 'display:flex;align-items:center;justify-content:space-between;padding:10px 16px;color:#374151;text-decoration:none;font-size:14px;font-weight:600;border-radius:8px;margin:2px 8px;cursor:pointer;';
    btn.innerHTML = '<span>' + label + '</span>';
    btn.addEventListener('mouseenter', () => btn.style.background = '#F3F4F6');
    btn.addEventListener('mouseleave', () => btn.style.background = 'transparent');
    btn.addEventListener('click', (e) => { e.preventDefault(); window.location.hash = '#' + routeId; route(); });
    return btn;
  }

  function route() {
    if (window.KT_V17_NAV_INSTALLED) return; // v21.1: v17 shell owns routing now
    const hash = (window.location.hash.replace('#', '').toLowerCase().split('/')[0]) || '';
    const c = getMain();
    if (!c) return;
    if (hash === 'admin-mrr'      && window.KT && window.KT.AdminMrr)      return window.KT.AdminMrr.render(c);
    if (hash === 'admin-features' && window.KT && window.KT.AdminFeatures) return window.KT.AdminFeatures.render(c);
    if (hash === 'admin-branding' && window.KT && window.KT.AdminBranding) return window.KT.AdminBranding.render(c);
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
