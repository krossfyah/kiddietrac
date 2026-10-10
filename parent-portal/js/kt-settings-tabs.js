/* ═══════════════════════════════════════════════════════════════════════
   KT.settingsTabs — one subtab strip for settings screens (2026-10-10)

   Anthony: "under notifications in settings there are multiple settings on one page add
   the sections in its own substabs within notifications to clean this up - looks for other
   setting sections and do the same". Settings screens had grown by stacking one card
   under another until a page was a long scroll of unrelated settings.

   This is the strip Billing already used (.kt-subtabs: underlined, 13.5px, the mobile CSS
   scrolls it sideways), made shared so every settings screen looks and behaves the same:

     KT.settingsTabs(host, 'notification-settings', [
       { key: 'countersign', label: '✍️ Counter-signature', paint: function (pane) { ... } },
       ...
     ], { param: 'tab', eager: false });

   - A pane is painted the first time its tab is opened and kept afterwards, so a
     half-edited form survives a switch to another tab and back. eager: true paints every
     pane up front — for a screen whose ONE Save button reads fields on every tab.
   - Which tab is open is remembered per screen for the session, and #screen?tab=key (or
     the param given) opens a tab directly (bell links, help guides).
   - paint() may return a promise; a failure shows in that pane only.
   - Falsy entries in the tab list are skipped, so role-dependent tabs can be inline.
   ═══════════════════════════════════════════════════════════════════════ */
(function (window) {
  'use strict';
  var KT = window.KT = window.KT || {};

  function wantedFromHash(param) {
    try {
      var q = (location.hash.split('?')[1] || '');
      var m = q.match(new RegExp('(?:^|&)' + param + '=([^&]+)'));
      return m ? decodeURIComponent(m[1]).toLowerCase() : '';
    } catch (e) { return ''; }
  }
  function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"]/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]; });
  }

  KT.settingsTabs = function (host, screenKey, tabs, opts) {
    opts = opts || {};
    tabs = (tabs || []).filter(Boolean);
    var bar = document.createElement('div');
    bar.className = 'kt-subtabs kt-settings-tabs';
    bar.setAttribute('role', 'tablist');
    bar.style.cssText = 'display:flex;gap:6px;flex-wrap:wrap;border-bottom:1px solid #E2E8F0;margin:0 0 14px;padding:0 0 2px;';
    var panes = document.createElement('div');
    panes.className = 'kt-settings-panes';
    host.appendChild(bar);
    host.appendChild(panes);

    var store = 'kt_stab_' + screenKey;
    var painted = {};
    var paneOf = {};
    var active = '';

    function paintBar() {
      bar.innerHTML = '';
      tabs.forEach(function (t) {
        var on = t.key === active;
        var b = document.createElement('button');
        b.type = 'button';
        b.setAttribute('role', 'tab');
        b.setAttribute('aria-selected', on ? 'true' : 'false');
        b.setAttribute('data-stab', t.key);
        b.style.cssText = 'background:none;border:0;border-bottom:2px solid ' + (on ? '#1F6FB2' : 'transparent')
          + ';padding:9px 13px;font-size:13.5px;font-weight:700;color:' + (on ? '#0F172A' : '#64748B')
          + ';cursor:pointer;border-radius:8px 8px 0 0;white-space:nowrap;';
        b.textContent = t.label;
        b.addEventListener('click', function () { open(t.key); });
        bar.appendChild(b);
      });
    }

    function ensure(t) {
      if (!paneOf[t.key]) {
        var p = document.createElement('div');
        p.className = 'kt-settings-pane';
        p.setAttribute('data-stab-pane', t.key);
        p.style.display = 'none';
        panes.appendChild(p);
        paneOf[t.key] = p;
      }
      if (painted[t.key]) { return; }
      painted[t.key] = true;
      var pane = paneOf[t.key];
      pane.innerHTML = '<div class="kt-card" style="color:#64748B;">Loading…</div>';
      var fail = function (e) {
        pane.innerHTML = '<div class="kt-card" style="color:#B91C1C;">Could not load this section: ' + esc((e && e.message) || 'error') + '</div>';
      };
      try {
        var r = t.paint(pane);
        if (r && typeof r.then === 'function') { r.catch(fail); }
      } catch (e) { fail(e); }
    }

    function open(key) {
      var t = tabs.filter(function (x) { return x.key === key; })[0] || tabs[0];
      if (!t) { return; }
      active = t.key;
      try { sessionStorage.setItem(store, active); } catch (e) {}
      paintBar();
      ensure(t);
      Object.keys(paneOf).forEach(function (k) { paneOf[k].style.display = k === active ? '' : 'none'; });
      if (typeof opts.onChange === 'function') { try { opts.onChange(active); } catch (e) {} }
    }

    if (opts.eager) { tabs.forEach(ensure); }
    var first = wantedFromHash(opts.param || 'tab');
    if (!first) { try { first = sessionStorage.getItem(store) || ''; } catch (e) {} }
    if (opts.initial && !first) { first = opts.initial; }
    if (!tabs.some(function (t) { return t.key === first; })) { first = tabs[0] && tabs[0].key; }
    open(first);

    return { open: open, active: function () { return active; }, pane: function (k) { return paneOf[k]; } };
  };
})(window);
