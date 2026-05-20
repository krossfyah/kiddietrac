/* v22p66 — mobile bottom nav + table-to-card transform helpers */
(function (window) {
  'use strict';
  if (window.KT_POLISH_V4_LOADED) return;
  window.KT_POLISH_V4_LOADED = true;
  const KT = window.KT = window.KT || {};

  // ============================ Mobile bottom nav ============================
  function injectMobileBottomNav() {
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

    // "More" → open sidebar (toggle existing hamburger)
    nav.querySelector('a[data-mob-hash="more"]').onclick = (e) => {
      e.preventDefault();
      const ss = document.querySelector('.sidebar, .kt-sidebar, #sidebar');
      if (ss) ss.classList.toggle('kt-mobile-open');
    };
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
