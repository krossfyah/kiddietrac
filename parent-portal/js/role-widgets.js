/* ═══════════════════════════════════════════════════════════════════
   KIDDIETRAC v22p33 — Role widgets

   Single module that fetches GET /widgets/me and renders a KPI strip
   at the top of the caller's container. Role-aware on the server side
   — the frontend just consumes whatever cards the backend returns.

   Usage from a screen's render function:
     KT.RoleWidgets.mount(main);             // mounts into the top
     KT.RoleWidgets.mount(main, { prepend: false });  // appends instead

   Skips silently if the role-widgets endpoint returns an empty array
   (agency_admin + platform_admin already have richer widget systems).
   ═══════════════════════════════════════════════════════════════════ */
(function (window) {
  'use strict';
  if (!window.KT) return;
  var KT = window.KT;
  var Api = KT.Api;
  var Dom = KT.Dom;

  function esc(s) { return s == null ? '' : String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;'); }

  function renderStrip(widgets) {
    var strip = Dom.el('div', {
      class: 'kt-role-widget-strip',
      style: 'display:grid;grid-template-columns:repeat(auto-fit, minmax(220px, 1fr));gap:12px;margin:0 0 16px;',
    });
    widgets.forEach(function (w) {
      var card = Dom.el('div', {
        class: 'kt-lift',
        style: 'background:white;border-radius:14px;padding:14px 16px;box-shadow:0 1px 3px rgba(0,0,0,.06);border-left:4px solid ' + (w.accent || '#1F6080') + ';display:flex;flex-direction:column;gap:2px;',
      });
      var head = Dom.el('div', { style: 'display:flex;align-items:center;justify-content:space-between;' });
      head.appendChild(Dom.el('div', { style: 'font-size:11px;font-weight:700;color:#6B7280;letter-spacing:1px;text-transform:uppercase;' }, w.label || ''));
      if (w.icon) head.appendChild(Dom.el('div', { style: 'font-size:20px;' }, w.icon));
      card.appendChild(head);
      card.appendChild(Dom.el('div', { style: 'font-size:26px;font-weight:800;color:#111827;line-height:1.1;margin-top:4px;' }, String(w.value == null ? '—' : w.value)));
      if (w.hint) card.appendChild(Dom.el('div', { style: 'font-size:12px;color:#6B7280;' }, w.hint));
      strip.appendChild(card);
    });
    return strip;
  }

  function mount(container, opts) {
    if (!container || !Api) return;
    opts = opts || {};
    // De-dupe: if a strip already exists in this container, replace it
    var existing = container.querySelector(':scope > .kt-role-widget-strip');
    if (existing) existing.remove();

    Api.get('/widgets/me').then(function (data) {
      if (!data || !data.widgets || !data.widgets.length) return;
      var strip = renderStrip(data.widgets);
      if (opts.prepend === false) {
        container.appendChild(strip);
      } else {
        container.insertBefore(strip, container.firstChild);
      }
    }).catch(function (e) {
      // Silently swallow — widgets are an enhancement, not a hard dependency
      if (window.console) console.warn('Role widgets load failed', e);
    });
  }

  KT.RoleWidgets = { mount: mount, renderStrip: renderStrip };

  // Auto-mount: on every dashboard-y hash, mount into #appMain after a short
  // delay (lets the screen render first). Hashes considered 'dashboard-y' are
  // anything that's empty, '#dashboard', '#today', or any of the role-specific
  // homes. Non-dashboard hashes (chat, messages, billing, etc.) skip.
  function isDashboardHash() {
    var h = (window.location.hash || '').replace(/^#/, '').toLowerCase();
    if (!h) return true;
    return /^(dashboard|home|today|overview|platform-overview)$/.test(h);
  }

  function autoMount() {
    if (!isDashboardHash()) return;
    var main = document.getElementById('appMain');
    if (!main || !main.children.length) return;
    // Try to find the inner padded wrap (some screens render into a child)
    var target = main.querySelector('.kt-hero') ? main.querySelector('.kt-hero').parentElement : main;
    mount(target);
  }

  // Run a few times after each navigation since the screens render async
  var pending = null;
  function schedule() {
    clearTimeout(pending);
    pending = setTimeout(autoMount, 350);
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', schedule);
  } else {
    schedule();
  }
  window.addEventListener('hashchange', schedule);
})(window);
