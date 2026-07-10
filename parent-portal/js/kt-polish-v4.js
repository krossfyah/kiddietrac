/* v22p66 — mobile bottom nav + table-to-card transform helpers */
(function (window) {
  'use strict';
  if (window.KT_POLISH_V4_LOADED) return;
  window.KT_POLISH_V4_LOADED = true;
  const KT = window.KT = window.KT || {};

  // ============================ Mobile bottom nav ============================
  function injectMobileBottomNav() {
    // Superseded by kt-mobilenav.js (#kt-mobilenav). This legacy bar caused a
    // second, overlapping bottom nav — disabled. Remove any stale instance too.
    var _stale = document.getElementById('kt-mobile-bottom-nav');
    if (_stale) _stale.remove();
    return;
    /* eslint-disable no-unreachable */
    if (document.getElementById('kt-mobile-bottom-nav')) return;
    if (!window.matchMedia('(max-width: 768px)').matches) return;
    if (!sessionStorage.getItem('kt_token')) return; // only when logged in

    // Pick role-appropriate items
    const user = (function () { try { return JSON.parse(sessionStorage.getItem('kt_user') || '{}'); } catch (e) { return {}; } })();
    const roles = user.roles || [];
    const isParent = roles.includes('guardian') && !roles.some(r => ['agency_admin', 'centre_director', 'educator'].includes(r));

    const items = isParent ? [
      { hash: 'today', icon: '✨', label: 'Today' },
      { hash: 'photos', icon: '📷', label: 'Photos' },
      { hash: 'messages', icon: '💬', label: 'Messages' },
      { hash: 'notifications', icon: '🔔', label: 'Inbox' },
      { hash: 'more', icon: '☰', label: 'More' },
    ] : [
      { hash: 'dashboard', icon: '🏠', label: 'Home' },
      { hash: 'children', icon: '🧒', label: 'Children' },
      { hash: 'chat', icon: '💬', label: 'Messages' },
      { hash: 'notifications', icon: '🔔', label: 'Inbox' },
      { hash: 'more', icon: '☰', label: 'More' },
    ];

    const nav = document.createElement('nav');
    nav.id = 'kt-mobile-bottom-nav';
    nav.className = 'kt-mobile-bottom-nav';
    nav.innerHTML = items.map(it => `<a href="${it.hash === 'more' ? '#' : '#' + it.hash}" data-mob-hash="${it.hash}">
      <span class="kt-mob-icon">${it.icon}</span>
      <span>${it.label}</span>
    </a>`).join('');
    document.body.appendChild(nav);

    // Active state from current hash
    function syncActive() {
      const hash = location.hash.replace('#', '').split('?')[0];
      nav.querySelectorAll('a').forEach(a => {
        a.classList.toggle('kt-mobile-active', a.dataset.mobHash === hash);
      });
    }
    syncActive();
    window.addEventListener('hashchange', syncActive);

    // v22p79: "More" → open a real slide-in drawer cloning the actual nav.
    nav.querySelector('a[data-mob-hash="more"]').onclick = (e) => {
      e.preventDefault();
      openMoreDrawer();
    };
  }

  // v22p79: full-height drawer that mirrors the real sidebar nav (#navLinks).
  function openMoreDrawer() {
    var existing = document.getElementById('kt-more-drawer');
    if (existing) { existing.remove(); return; }

    var navLinks = document.getElementById('navLinks');
    var overlay = document.createElement('div');
    overlay.id = 'kt-more-drawer';
    overlay.setAttribute('style', 'position:fixed;inset:0;z-index:10500;background:rgba(15,23,42,.5);display:flex;justify-content:flex-end;');

    var panel = document.createElement('div');
    panel.setAttribute('style', 'width:min(320px,86vw);height:100%;background:#fff;overflow-y:auto;box-shadow:-8px 0 32px rgba(0,0,0,.25);animation:kt-more-in .2s ease-out;');

    var header = document.createElement('div');
    header.setAttribute('style', 'display:flex;align-items:center;justify-content:space-between;padding:16px 18px;border-bottom:1px solid #EEF2F6;position:sticky;top:0;background:#fff;');
    header.innerHTML = '<span style="font-weight:800;font-size:16px;color:#1F6080;">Menu</span>';
    var close = document.createElement('button');
    close.textContent = '×';
    close.setAttribute('style', 'background:#F1F5F9;border:none;width:34px;height:34px;border-radius:17px;font-size:22px;line-height:1;cursor:pointer;color:#475569;');
    close.onclick = function () { overlay.remove(); };
    header.appendChild(close);
    panel.appendChild(header);

    var body = document.createElement('div');
    body.setAttribute('style', 'padding:10px 8px 30px;');
    if (navLinks) {
      var clone = navLinks.cloneNode(true);
      clone.removeAttribute('id');
      clone.querySelectorAll('a').forEach(function (a) {
        a.addEventListener('click', function () { overlay.remove(); });
      });
      var ctl = clone.querySelector('#kt-nav-allctl, .kt-nav-allctl');
      if (ctl) ctl.remove();
      body.appendChild(clone);
    } else {
      body.innerHTML = '<div style="padding:24px;color:#94A3B8;">Menu unavailable.</div>';
    }
    panel.appendChild(body);
    overlay.appendChild(panel);
    overlay.addEventListener('click', function (e) { if (e.target === overlay) overlay.remove(); });
    document.body.appendChild(overlay);

    if (!document.getElementById('kt-more-drawer-css')) {
      var st = document.createElement('style');
      st.id = 'kt-more-drawer-css';
      st.textContent = '@keyframes kt-more-in{from{transform:translateX(100%);}to{transform:translateX(0);}}'
        + '#kt-more-drawer .sidebar-section{display:block !important;margin:0 0 6px;}'
        + '#kt-more-drawer .sidebar-section-label{display:block !important;font-size:11px;font-weight:800;letter-spacing:.5px;text-transform:uppercase;color:#64748B;padding:10px 12px 4px;}'
        + '#kt-more-drawer .nav-link{display:flex !important;align-items:center;gap:10px;padding:11px 14px;border-radius:10px;text-decoration:none;color:#1F2937;font-size:14px;}'
        + '#kt-more-drawer .nav-link:hover{background:#F1F5F9;}'
        + '#kt-more-drawer .nav-link.active{background:rgba(31,96,128,.10);color:#1F6080;font-weight:700;}'
        + '#kt-more-drawer .kt-nav-chev{display:none;}';
      document.head.appendChild(st);
    }
  }
  setTimeout(injectMobileBottomNav, 1000);
  window.addEventListener('resize', () => {
    setTimeout(injectMobileBottomNav, 200);
    // Remove if growing past mobile
    if (!window.matchMedia('(max-width: 768px)').matches) {
      const ex = document.getElementById('kt-mobile-bottom-nav');
      if (ex) ex.remove();
    }
  });

  // ============================ Table → mobile card data labels ============================
  function decorateTableCells() {
    document.querySelectorAll('[data-kt-pretty] table').forEach(t => {
      if (t.dataset.ktMobLabeled) return;
      const thead = t.querySelector('thead');
      const tbody = t.querySelector('tbody');
      if (!thead || !tbody) return;
      const headers = Array.from(thead.querySelectorAll('th')).map(th => {
        const clone = th.cloneNode(true);
        clone.querySelectorAll('.kt-sort-indicator').forEach(i => i.remove());
        return (clone.textContent || '').trim();
      });
      Array.from(tbody.children).forEach(row => {
        Array.from(row.children).forEach((td, idx) => {
          if (headers[idx]) td.setAttribute('data-kt-label', headers[idx]);
        });
      });
      t.dataset.ktMobLabeled = '1';
    });
  }
  setInterval(decorateTableCells, 1800);
  setTimeout(decorateTableCells, 1300);

  // ============================ Card class auto-application ============================
  // Add .kt-card class to any [data-kt-pretty] direct-child div that looks like a card
  // (background:#fff inline-style + border-radius). Some legacy screens have inline-styled cards.
  function applyCardClassToInlineStyled() {
    document.querySelectorAll('[data-kt-pretty] > div > div').forEach(d => {
      if (d.classList.contains('kt-card')) return;
      if (d.classList.contains('kt-kpi')) return;
      if (d.classList.contains('kt-page-hero')) return;
      const style = d.getAttribute('style') || '';
      // Heuristic: an inline-styled "card-like" element
      if (/background:\s*#fff/i.test(style) && /border-radius/i.test(style) && /padding/i.test(style)) {
        d.classList.add('kt-card');
      }
    });
  }
  setInterval(applyCardClassToInlineStyled, 2200);
  setTimeout(applyCardClassToInlineStyled, 1500);
})(window);
