/* ═══════════════════════════════════════════════════════════════════
   KIDDIETRAC v22p41 — Global Cmd/Ctrl-K command palette

   Window-level keyboard handlers:
     Cmd/Ctrl-K  — open the palette (works from anywhere)
     /           — open the palette (when not already in an input)
     Esc         — close
     ↑/↓         — navigate result list
     Enter       — open the selected result

   The palette shows:
     - Static jump-to-screen shortcuts (Dashboard, Marketing, Calendar,
       Audit log, Help) so users can navigate without hunting the
       sidebar.
     - Live search results from /admin/search (centres, rooms, families,
       children, staff). Same backend as the existing sidebar widget,
       different presentation.

   No backend changes needed — this is a pure frontend overlay that
   piggybacks on the v22p4.6 SearchController.
   ═══════════════════════════════════════════════════════════════════ */
(function (window) {
  'use strict';
  if (!window.KT) return;
  var KT = window.KT;
  var Api = KT.Api;

  // Static shortcuts — show even when the search box is empty
  var SHORTCUTS = [
    { label: 'Dashboard',     icon: '🏠', hash: '#dashboard',          kbd: 'D' },
    { label: 'Marketing',     icon: '📣', hash: '#marketing-campaigns', kbd: 'M' },
    { label: 'Staff calendar', icon: '📅', hash: '#staff-calendar',     kbd: 'C' },
    { label: 'Children',      icon: '🧒', hash: '#admin-children',     kbd: null },
    { label: 'Families',      icon: '👪', hash: '#admin-families',     kbd: null },
    { label: 'Centres',       icon: '🏫', hash: '#admin-centres',      kbd: null },
    { label: 'Users',         icon: '👥', hash: '#admin-users',        kbd: null },
    { label: 'Audit log',     icon: '📜', hash: '#audit-logs',         kbd: null },
    { label: 'Billing',       icon: '💳', hash: '#admin-billing',      kbd: null },
    { label: 'Help & guides', icon: '📖', hash: '#help',               kbd: '?' },
    { label: 'Two-factor (MFA)', icon: '🔐', hash: '#mfa',             kbd: null },
  ];

  var state = {
    overlay: null,
    input: null,
    list: null,
    items: [],        // flattened [{ label, sublabel, icon, hash, group }]
    cursor: 0,
    debounceTimer: null,
    isOpen: false,
  };

  // ── Style installed once ──────────────────────────────────────────
  function installStyles() {
    if (document.getElementById('kt-cmdk-style')) return;
    var s = document.createElement('style');
    s.id = 'kt-cmdk-style';
    s.textContent =
      '.kt-cmdk-overlay{position:fixed;inset:0;background:rgba(15,23,42,.58);backdrop-filter:blur(4px);z-index:10000;display:flex;align-items:flex-start;justify-content:center;padding:80px 16px 16px;animation:ktCmdKIn .14s ease-out;}' +
      '@keyframes ktCmdKIn{from{opacity:0;transform:translateY(-6px);}to{opacity:1;transform:translateY(0);}}' +
      '.kt-cmdk-modal{background:#FFFFFF;border-radius:14px;width:100%;max-width:600px;box-shadow:0 20px 50px rgba(15,23,42,.32);overflow:hidden;display:flex;flex-direction:column;max-height:min(72vh,560px);}' +
      '.kt-cmdk-input-wrap{display:flex;align-items:center;padding:12px 16px;border-bottom:1px solid #E5E7EB;gap:10px;}' +
      '.kt-cmdk-input{flex:1;border:none;outline:none;font-size:16px;color:#0F172A;background:transparent;font-family:inherit;}' +
      '.kt-cmdk-input::placeholder{color:#64748B;}' +
      '.kt-cmdk-kbd{font-size:11px;font-weight:700;color:#64748B;background:#F1F5F9;border:1px solid #E2E8F0;border-radius:6px;padding:3px 7px;font-family:ui-monospace,monospace;}' +
      '.kt-cmdk-list{flex:1;overflow-y:auto;padding:6px;}' +
      '.kt-cmdk-group{font-size:10px;font-weight:700;color:#64748B;letter-spacing:1.4px;text-transform:uppercase;padding:10px 12px 4px;}' +
      '.kt-cmdk-item{display:flex;align-items:center;gap:10px;padding:9px 12px;border-radius:8px;cursor:pointer;font-size:14px;color:#0F172A;}' +
      '.kt-cmdk-item:hover,.kt-cmdk-item.is-active{background:linear-gradient(90deg,#1F6080 0%,#16637A 100%);color:#FFFFFF;}' +
      '.kt-cmdk-item:hover .kt-cmdk-sub,.kt-cmdk-item.is-active .kt-cmdk-sub{color:rgba(255,255,255,.78);}' +
      '.kt-cmdk-item:hover .kt-cmdk-icon,.kt-cmdk-item.is-active .kt-cmdk-icon{filter:grayscale(0);}' +
      '.kt-cmdk-icon{width:28px;text-align:center;font-size:18px;flex-shrink:0;}' +
      '.kt-cmdk-label{flex:1;min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;font-weight:600;}' +
      '.kt-cmdk-sub{font-size:12px;color:#64748B;margin-left:auto;flex-shrink:0;}' +
      '.kt-cmdk-empty{padding:30px 16px;text-align:center;color:#64748B;font-size:13px;}' +
      '.kt-cmdk-footer{display:flex;align-items:center;justify-content:space-between;padding:8px 14px;border-top:1px solid #E5E7EB;background:#FAFBFC;font-size:11px;color:#64748B;}' +
      '.kt-cmdk-footer kbd{font-family:ui-monospace,monospace;background:white;border:1px solid #E5E7EB;border-radius:4px;padding:1px 5px;margin:0 3px;color:#64748B;}';
    document.head.appendChild(s);
  }

  // ── Open / close ──────────────────────────────────────────────────
  function open() {
    if (state.isOpen) return;
    installStyles();

    var overlay = document.createElement('div');
    overlay.className = 'kt-cmdk-overlay';
    overlay.addEventListener('click', function (e) { if (e.target === overlay) close(); });

    var modal = document.createElement('div');
    modal.className = 'kt-cmdk-modal';

    var inputWrap = document.createElement('div');
    inputWrap.className = 'kt-cmdk-input-wrap';
    inputWrap.innerHTML = '<span style="font-size:18px;color:#64748B;">🔎</span>';

    var input = document.createElement('input');
    input.className = 'kt-cmdk-input';
    input.type = 'search';
    input.placeholder = 'Jump to a screen or search children, families, centres, rooms, staff…';
    input.autocomplete = 'off';
    input.spellcheck = false;
    inputWrap.appendChild(input);

    var kbd = document.createElement('span');
    kbd.className = 'kt-cmdk-kbd';
    kbd.textContent = 'Esc';
    inputWrap.appendChild(kbd);

    modal.appendChild(inputWrap);

    var list = document.createElement('div');
    list.className = 'kt-cmdk-list';
    modal.appendChild(list);

    var footer = document.createElement('div');
    footer.className = 'kt-cmdk-footer';
    footer.innerHTML = '<span><kbd>↑</kbd><kbd>↓</kbd> navigate · <kbd>↵</kbd> open · <kbd>Esc</kbd> close</span>' +
      '<span><kbd>' + (isMac() ? '⌘' : 'Ctrl') + '</kbd><kbd>K</kbd> anywhere to reopen</span>';
    modal.appendChild(footer);

    overlay.appendChild(modal);
    document.body.appendChild(overlay);

    state.overlay = overlay;
    state.input = input;
    state.list = list;
    state.isOpen = true;
    state.cursor = 0;

    input.addEventListener('input', function () {
      clearTimeout(state.debounceTimer);
      state.debounceTimer = setTimeout(function () { runQuery(input.value); }, 180);
    });
    input.addEventListener('keydown', onKeydown);

    renderEmpty();
    setTimeout(function () { input.focus(); }, 20);
  }

  function close() {
    if (!state.isOpen) return;
    if (state.overlay && state.overlay.parentNode) state.overlay.parentNode.removeChild(state.overlay);
    state.overlay = null; state.input = null; state.list = null;
    state.items = []; state.cursor = 0; state.isOpen = false;
    clearTimeout(state.debounceTimer);
  }

  // ── Query + render ────────────────────────────────────────────────
  function renderEmpty() {
    // Show static shortcuts when there's no query
    state.items = SHORTCUTS.map(function (s) {
      return { label: s.label, sublabel: s.kbd ? s.kbd : 'Go to', icon: s.icon, hash: s.hash, group: 'Jump to' };
    });
    paint();
  }

  function runQuery(q) {
    q = (q || '').trim();
    if (q.length === 0) { renderEmpty(); return; }
    if (q.length < 2) {
      state.items = [];
      state.cursor = 0;
      state.list.innerHTML = '<div class="kt-cmdk-empty">Type at least 2 characters…</div>';
      return;
    }

    // While loading, still show filtered shortcuts at the top
    var ql = q.toLowerCase();
    var staticMatches = SHORTCUTS.filter(function (s) { return s.label.toLowerCase().indexOf(ql) !== -1; });

    Api.get('/admin/search?q=' + encodeURIComponent(q)).then(function (data) {
      var r = (data && data.results) || {};
      var items = staticMatches.map(function (s) {
        return { label: s.label, sublabel: 'Go to', icon: s.icon, hash: s.hash, group: 'Jump to' };
      });
      (r.centres || []).forEach(function (x) { items.push({ label: x.label, sublabel: x.sublabel || 'Centre', icon: '🏫', hash: x.hash, group: 'Centres' }); });
      (r.rooms || []).forEach(function (x) { items.push({ label: x.label, sublabel: x.sublabel || 'Room', icon: '🚪', hash: x.hash, group: 'Rooms' }); });
      (r.families || []).forEach(function (x) { items.push({ label: x.label, sublabel: x.sublabel || 'Family', icon: '👪', hash: x.hash, group: 'Families' }); });
      (r.children || []).forEach(function (x) { items.push({ label: x.label, sublabel: x.sublabel || 'Child', icon: '🧒', hash: x.hash, group: 'Children' }); });
      (r.staff || []).forEach(function (x) { items.push({ label: x.label, sublabel: x.sublabel || 'Staff', icon: '👤', hash: x.hash, group: 'Staff' }); });

      state.items = items;
      state.cursor = 0;
      paint();
    }).catch(function () {
      state.items = staticMatches.map(function (s) {
        return { label: s.label, sublabel: 'Go to', icon: s.icon, hash: s.hash, group: 'Jump to' };
      });
      state.cursor = 0;
      paint();
    });
  }

  function paint() {
    if (!state.list) return;
    if (!state.items.length) {
      state.list.innerHTML = '<div class="kt-cmdk-empty">No matches.</div>';
      return;
    }
    state.list.innerHTML = '';
    var lastGroup = null;
    state.items.forEach(function (it, idx) {
      if (it.group !== lastGroup) {
        var h = document.createElement('div');
        h.className = 'kt-cmdk-group';
        h.textContent = it.group;
        state.list.appendChild(h);
        lastGroup = it.group;
      }
      var row = document.createElement('div');
      row.className = 'kt-cmdk-item' + (idx === state.cursor ? ' is-active' : '');
      row.dataset.index = String(idx);
      row.innerHTML =
        '<div class="kt-cmdk-icon">' + (it.icon || '•') + '</div>' +
        '<div class="kt-cmdk-label">' + escHtml(it.label) + '</div>' +
        '<div class="kt-cmdk-sub">' + escHtml(it.sublabel || '') + '</div>';
      row.addEventListener('mouseenter', function () {
        state.cursor = idx; updateActive();
      });
      row.addEventListener('click', function () { select(idx); });
      state.list.appendChild(row);
    });
    scrollActiveIntoView();
  }

  function updateActive() {
    if (!state.list) return;
    var rows = state.list.querySelectorAll('.kt-cmdk-item');
    rows.forEach(function (r) {
      r.classList.toggle('is-active', parseInt(r.dataset.index, 10) === state.cursor);
    });
  }

  function scrollActiveIntoView() {
    if (!state.list) return;
    var active = state.list.querySelector('.kt-cmdk-item.is-active');
    if (active && active.scrollIntoView) active.scrollIntoView({ block: 'nearest' });
  }

  function select(idx) {
    var it = state.items[idx];
    if (!it) return;
    var href = it.hash || '#';
    close();
    // Allow same-hash re-navigation by clearing first
    if (window.location.hash === href) {
      window.location.hash = '';
      setTimeout(function () { window.location.hash = href; }, 0);
    } else {
      window.location.hash = href;
    }
  }

  // ── Keyboard ──────────────────────────────────────────────────────
  function onKeydown(e) {
    if (e.key === 'Escape') { e.preventDefault(); close(); return; }
    if (e.key === 'ArrowDown') { e.preventDefault(); state.cursor = Math.min(state.items.length - 1, state.cursor + 1); updateActive(); scrollActiveIntoView(); return; }
    if (e.key === 'ArrowUp')   { e.preventDefault(); state.cursor = Math.max(0, state.cursor - 1); updateActive(); scrollActiveIntoView(); return; }
    if (e.key === 'Enter')     { e.preventDefault(); select(state.cursor); return; }
  }

  function escHtml(s) { return s == null ? '' : String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;'); }
  function isMac() { return /Mac/i.test(navigator.platform || ''); }

  // ── Global keybinding ─────────────────────────────────────────────
  function isTypingInField(target) {
    if (!target) return false;
    var t = target.tagName;
    if (t === 'INPUT' || t === 'TEXTAREA' || t === 'SELECT') return true;
    if (target.isContentEditable) return true;
    return false;
  }

  window.addEventListener('keydown', function (e) {
    // Cmd/Ctrl-K — open from anywhere (even when typing in a field)
    if ((e.metaKey || e.ctrlKey) && (e.key === 'k' || e.key === 'K')) {
      e.preventDefault();
      if (state.isOpen) { close(); } else { open(); }
      return;
    }
    // '/' — only when not already typing somewhere
    if (e.key === '/' && !state.isOpen && !isTypingInField(e.target)) {
      e.preventDefault();
      open();
      return;
    }
    // Esc — close palette if open and event hasn't been handled by the input
    if (e.key === 'Escape' && state.isOpen && e.target !== state.input) {
      close();
    }
  });

  // Expose for menu / button triggers
  KT.SearchPalette = { open: open, close: close };
})(window);
