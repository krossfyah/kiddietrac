/* ═══════════════════════════════════════════════════════════════════
   KIDDIETRAC v22p72 — customizable Quick-Add (per user)
   A ➕ floating button (stacked above the ✨ help button) opening the
   user's pinned shortcuts. Each user customises their own set; stored in
   localStorage keyed by user id (per-user, per-device).
   ═══════════════════════════════════════════════════════════════════ */
(function (window) {
  'use strict';

  // Catalog of quick-add destinations (hash routes already in the app)
  var CATALOG = [
    { id: 'observation-new', label: 'Log a moment',     icon: '✨', hash: 'observation-new' },
    { id: 'incident-new',    label: 'New incident',      icon: '⚠️', hash: 'incident-new' },
    { id: 'care-log',        label: 'Daily log',         icon: '📝', hash: 'care-log' },
    { id: 'chat',            label: 'Message a parent',  icon: '💬', hash: 'chat' },
    { id: 'announcements',   label: 'New announcement',  icon: '📢', hash: 'announcements' },
    { id: 'admin-families',  label: 'Add family',        icon: '👪', hash: 'admin-families' },
    { id: 'admin-children',  label: 'Add child',         icon: '🧒', hash: 'admin-children' },
    { id: 'admin-users',     label: 'Add staff',         icon: '👥', hash: 'admin-users' },
    { id: 'bulk-invoices',   label: 'Invoice run',       icon: '💸', hash: 'bulk-invoices' },
    { id: 'tours',           label: 'Book a tour',       icon: '🚪', hash: 'tours' },
    { id: 'field-trips',     label: 'Plan field trip',   icon: '🚐', hash: 'field-trips' },
    { id: 'lesson-plans',    label: 'Lesson plan',       icon: '📚', hash: 'lesson-plans' },
    { id: 'tickets',         label: 'Raise ticket',      icon: '🎫', hash: 'tickets' },
    { id: 'staff-calendar',  label: 'Calendar',          icon: '📅', hash: 'staff-calendar' },
    { id: 'photos',          label: 'Upload photo',      icon: '📷', hash: 'photos' },
    { id: 'reports',         label: 'Reports',           icon: '📋', hash: 'reports' }
  ];

  function userKey() {
    var u = {};
    try { u = JSON.parse(sessionStorage.getItem('kt_user') || '{}'); } catch (e) {}
    return 'kt_quickadd_' + (u.id || 'anon');
  }

  function getPins() {
    try {
      var v = JSON.parse(localStorage.getItem(userKey()) || 'null');
      if (Array.isArray(v)) return v;
    } catch (e) {}
    // Sensible defaults by role
    var roles = [];
    try { roles = (JSON.parse(sessionStorage.getItem('kt_user') || '{}').roles) || []; } catch (e) {}
    if (roles.indexOf('educator') !== -1) return ['observation-new', 'care-log', 'chat', 'incident-new'];
    if (roles.indexOf('guardian') !== -1) return ['chat', 'photos'];
    return ['observation-new', 'admin-families', 'bulk-invoices', 'chat']; // admin/director default
  }
  function setPins(ids) { try { localStorage.setItem(userKey(), JSON.stringify(ids)); } catch (e) {} }

  function go(hash) { window.location.hash = '#' + hash; }

  var menuOpen = false;

  function buildFab() {
    if (document.getElementById('kt-quickadd-fab')) return;
    injectStyles();

    var fab = document.createElement('button');
    fab.id = 'kt-quickadd-fab';
    fab.className = 'kt-qa-fab';
    fab.title = 'Quick add';
    fab.innerHTML = '＋';
    fab.addEventListener('click', toggleMenu);
    document.body.appendChild(fab);

    var menu = document.createElement('div');
    menu.id = 'kt-quickadd-menu';
    menu.className = 'kt-qa-menu';
    menu.style.display = 'none';
    document.body.appendChild(menu);
  }

  function toggleMenu() { menuOpen ? closeMenu() : openMenu(); }

  function openMenu() {
    var menu = document.getElementById('kt-quickadd-menu');
    if (!menu) return;
    menu.innerHTML = '';

    var pins = getPins();
    var byId = {};
    CATALOG.forEach(function (c) { byId[c.id] = c; });

    pins.forEach(function (id) {
      var c = byId[id];
      if (!c) return;
      var item = document.createElement('button');
      item.className = 'kt-qa-item';
      item.innerHTML = '<span class="kt-qa-ic">' + c.icon + '</span><span>' + c.label + '</span>';
      item.addEventListener('click', function () { closeMenu(); go(c.hash); });
      menu.appendChild(item);
    });

    if (!pins.length) {
      var empty = document.createElement('div');
      empty.className = 'kt-qa-empty';
      empty.textContent = 'No shortcuts yet — customise below.';
      menu.appendChild(empty);
    }

    var customize = document.createElement('button');
    customize.className = 'kt-qa-customize';
    customize.innerHTML = '⚙️ Customise shortcuts';
    customize.addEventListener('click', function () { closeMenu(); openCustomizer(); });
    menu.appendChild(customize);

    menu.style.display = 'block';
    // On desktop, drop the menu from the top-bar Quick-add (⚡) button the user
    // actually clicked — not the bottom-right FAB corner (which read as "too far
    // right"). Right-align the panel to the button; fall back to the CSS default
    // (FAB corner) on mobile or when the top-bar button is absent.
    try {
      // The top-bar Quick-add button was switched from a native title to a custom
      // data-kttip tooltip — match either so the popup keeps anchoring to it.
      var tbBtn = document.querySelector('#kt-topbar [data-kttip="Quick add"], #kt-topbar [title="Quick add"]');
      if (tbBtn && window.matchMedia && window.matchMedia('(min-width:769px)').matches) {
        var rct = tbBtn.getBoundingClientRect();
        // setProperty w/ 'important' — kt-consistency-polish.css pins the menu to
        // top:58px/right:16px !important (the viewport corner), which is what made
        // the popup float far from the button. Override it to sit under the button.
        menu.style.setProperty('top', Math.round(rct.bottom + 8) + 'px', 'important');
        menu.style.setProperty('bottom', 'auto', 'important');
        menu.style.setProperty('right', Math.max(8, Math.round(window.innerWidth - rct.right)) + 'px', 'important');
        menu.style.setProperty('left', 'auto', 'important');
      } else {
        menu.style.removeProperty('top'); menu.style.removeProperty('bottom');
        menu.style.removeProperty('right'); menu.style.removeProperty('left');
      }
    } catch (e) {}
    menuOpen = true;
    document.getElementById('kt-quickadd-fab').classList.add('is-open');

    setTimeout(function () {
      document.addEventListener('click', outsideClose);
    }, 30);
  }

  function outsideClose(e) {
    var menu = document.getElementById('kt-quickadd-menu');
    var fab = document.getElementById('kt-quickadd-fab');
    if (menu && !menu.contains(e.target) && e.target !== fab) closeMenu();
  }

  function closeMenu() {
    var menu = document.getElementById('kt-quickadd-menu');
    if (menu) menu.style.display = 'none';
    menuOpen = false;
    var fab = document.getElementById('kt-quickadd-fab');
    if (fab) fab.classList.remove('is-open');
    document.removeEventListener('click', outsideClose);
  }

  function openCustomizer() {
    var pins = getPins();
    var overlay = document.createElement('div');
    overlay.className = 'kt-qa-overlay';
    var card = document.createElement('div');
    card.className = 'kt-qa-modal';
    card.innerHTML = '<h3 style="margin:0 0 6px;">Customise quick-add</h3>'
      + '<p style="margin:0 0 16px;color:#64748B;font-size:13px;">Pick the shortcuts you use most. Saved to your account.</p>';

    var list = document.createElement('div');
    list.className = 'kt-qa-catalog';
    CATALOG.forEach(function (c) {
      var row = document.createElement('label');
      row.className = 'kt-qa-catrow';
      var checked = pins.indexOf(c.id) !== -1 ? 'checked' : '';
      row.innerHTML = '<input type="checkbox" data-id="' + c.id + '" ' + checked + '>'
        + '<span class="kt-qa-ic">' + c.icon + '</span><span>' + c.label + '</span>';
      list.appendChild(row);
    });
    card.appendChild(list);

    var actions = document.createElement('div');
    actions.className = 'kt-qa-actions';
    var cancel = document.createElement('button');
    cancel.className = 'kt-qa-btn-ghost';
    cancel.textContent = 'Cancel';
    cancel.addEventListener('click', function () { overlay.remove(); });
    var save = document.createElement('button');
    save.className = 'kt-qa-btn-primary';
    save.textContent = 'Save';
    save.addEventListener('click', function () {
      var ids = Array.prototype.map.call(card.querySelectorAll('input[data-id]:checked'), function (i) { return i.getAttribute('data-id'); });
      setPins(ids);
      overlay.remove();
      if (window.KT && window.KT.toast) window.KT.toast('Quick-add saved.', 'success');
    });
    actions.appendChild(cancel);
    actions.appendChild(save);
    card.appendChild(actions);

    overlay.appendChild(card);
    overlay.addEventListener('click', function (e) { if (e.target === overlay) overlay.remove(); });
    document.body.appendChild(overlay);
  }

  function injectStyles() {
    if (document.getElementById('kt-quickadd-styles')) return;
    var s = document.createElement('style');
    s.id = 'kt-quickadd-styles';
    s.textContent = [
      '.kt-qa-fab { position:fixed; right:24px; bottom:90px; z-index:8999; width:52px; height:52px; border-radius:26px; border:none; cursor:pointer; font-size:26px; line-height:1; color:#fff; background:linear-gradient(135deg,#F97316 0%,#EA580C 100%); box-shadow:0 8px 20px rgba(234,88,12,.45); transition:transform .15s; }',
      '.kt-qa-fab:hover { transform:scale(1.08); }',
      '.kt-qa-fab.is-open { transform:rotate(45deg); }',
      '.kt-qa-menu { position:fixed; right:24px; bottom:152px; z-index:8999; background:#fff; border-radius:14px; box-shadow:0 12px 32px rgba(15,23,42,.22); padding:8px; min-width:220px; animation:kt-qa-in .16s ease-out; }',
      '@keyframes kt-qa-in { from{opacity:0;transform:translateY(8px);} to{opacity:1;transform:translateY(0);} }',
      '.kt-qa-item { display:flex; align-items:center; gap:12px; width:100%; background:transparent; border:none; padding:11px 12px; border-radius:9px; cursor:pointer; font-size:14px; color:#1F2937; text-align:left; font-family:inherit; transition:background .12s; }',
      '.kt-qa-item:hover { background:#F1F5F9; }',
      '.kt-qa-ic { font-size:18px; width:22px; text-align:center; }',
      '.kt-qa-empty { padding:12px; color:#64748B; font-size:13px; }',
      '.kt-qa-customize { display:flex; align-items:center; gap:8px; width:100%; margin-top:6px; border-top:1px solid #F1F5F9; padding:11px 12px; background:transparent; border-left:none;border-right:none;border-bottom:none; cursor:pointer; font-size:13px; color:#1F6080; font-weight:600; font-family:inherit; }',
      '.kt-qa-customize:hover { background:#F8FAFC; }',
      '.kt-qa-overlay { position:fixed; inset:0; background:rgba(15,23,42,.55); z-index:11000; display:flex; align-items:center; justify-content:center; padding:20px; }',
      '.kt-qa-modal { background:#fff; border-radius:16px; padding:24px; width:min(440px,94vw); max-height:84vh; overflow:auto; }',
      '.kt-qa-catalog { display:flex; flex-direction:column; gap:2px; }',
      '.kt-qa-catrow { display:flex; align-items:center; gap:10px; padding:9px 8px; border-radius:8px; cursor:pointer; font-size:14px; }',
      '.kt-qa-catrow:hover { background:#F8FAFC; }',
      '.kt-qa-catrow input { width:17px; height:17px; cursor:pointer; }',
      '.kt-qa-actions { display:flex; justify-content:flex-end; gap:10px; margin-top:18px; }',
      '.kt-qa-btn-ghost { background:#F1F5F9; border:none; padding:10px 18px; border-radius:9px; cursor:pointer; font-weight:600; font-family:inherit; }',
      '.kt-qa-btn-primary { background:linear-gradient(135deg,#1F6080,#2D7BA8); color:#fff; border:none; padding:10px 20px; border-radius:9px; cursor:pointer; font-weight:700; font-family:inherit; }',
      '@media (max-width:768px){ .kt-qa-fab{ bottom:150px; right:16px; } .kt-qa-menu{ bottom:212px; right:16px; } }'
    ].join('\n');
    document.head.appendChild(s);
  }

  function init() {
    // Only show for logged-in users
    if (!sessionStorage.getItem('kt_token')) return;
    buildFab();
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', function () { setTimeout(init, 600); });
  } else { setTimeout(init, 600); }

  window.KT = window.KT || {};
  window.KT.openQuickAddCustomizer = openCustomizer;
})(window);
