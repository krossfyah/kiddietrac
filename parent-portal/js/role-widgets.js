/* ═══════════════════════════════════════════════════════════════════
   KIDDIETRAC v22p35 — Role widgets (polished)

   Single module that fetches GET /widgets/me and renders a KPI strip
   for the caller's dashboard. Role-aware on the server side — the
   frontend just consumes whatever cards the backend returns.

   Usage from a screen's render function:
     KT.RoleWidgets.mount(main);              // auto-place after hero
     KT.RoleWidgets.mount(main, { prepend: true });   // top of container
     KT.RoleWidgets.mount(main, { prepend: false });  // bottom

   v22p35 changes:
     - Insert AFTER the .kt-hero / .auth-greeting banner rather than
       above it. Previously widgets pushed the goodnight greeting
       below the fold.
     - Glass-card design with subtle gradient, big icon bubble,
       larger value typography, animated hover lift.
     - Skip silently when the endpoint returns no cards (agency_admin
       + platform_admin already have richer widget systems).
   ═══════════════════════════════════════════════════════════════════ */
(function (window) {
  'use strict';
  if (!window.KT) return;
  var KT = window.KT;
  var Api = KT.Api;
  var Dom = KT.Dom;

  // Install styles once
  function installStyles() {
    if (document.getElementById('kt-role-widget-style')) return;
    var s = document.createElement('style');
    s.id = 'kt-role-widget-style';
    s.textContent =
      '.kt-rw-strip{display:grid;grid-template-columns:repeat(auto-fit,minmax(230px,1fr));gap:14px;margin:14px 0 18px;}' +
      '.kt-rw-card{position:relative;background:linear-gradient(135deg,#FFFFFF 0%,#F8FAFC 100%);border-radius:16px;padding:18px 18px 16px;box-shadow:0 1px 3px rgba(15,23,42,.06),0 4px 12px rgba(15,23,42,.04);overflow:hidden;transition:transform .18s ease,box-shadow .18s ease;border:1px solid rgba(15,23,42,.04);}' +
      '.kt-rw-card::before{content:"";position:absolute;top:0;left:0;right:0;height:4px;background:var(--kt-rw-accent,#1F6080);}' +
      '.kt-rw-card::after{content:"";position:absolute;top:-30px;right:-30px;width:140px;height:140px;border-radius:50%;background:radial-gradient(circle, color-mix(in srgb, var(--kt-rw-accent,#1F6080) 18%, transparent) 0%, transparent 70%);pointer-events:none;}' +
      '.kt-rw-card:hover{transform:translateY(-3px);box-shadow:0 4px 8px rgba(15,23,42,.07),0 12px 28px rgba(15,23,42,.08);}' +
      '.kt-rw-head{display:flex;align-items:center;justify-content:space-between;margin-bottom:10px;position:relative;z-index:1;}' +
      '.kt-rw-label{font-size:11px;font-weight:700;color:#6B7280;letter-spacing:1.2px;text-transform:uppercase;}' +
      '.kt-rw-icon{width:38px;height:38px;border-radius:11px;display:flex;align-items:center;justify-content:center;font-size:20px;background:color-mix(in srgb, var(--kt-rw-accent,#1F6080) 14%, white);color:var(--kt-rw-accent,#1F6080);box-shadow:inset 0 0 0 1px color-mix(in srgb, var(--kt-rw-accent,#1F6080) 22%, transparent);}' +
      '.kt-rw-value{font-size:30px;font-weight:800;color:#0F172A;line-height:1.05;letter-spacing:-0.3px;position:relative;z-index:1;font-feature-settings:"tnum";}' +
      '.kt-rw-hint{font-size:12px;color:#64748B;margin-top:4px;position:relative;z-index:1;line-height:1.35;}';
    document.head.appendChild(s);
  }

  function renderStrip(widgets) {
    installStyles();
    var strip = Dom.el('div', { class: 'kt-role-widget-strip kt-rw-strip' });
    widgets.forEach(function (w) {
      var accent = w.accent || '#1F6080';
      var card = Dom.el('div', { class: 'kt-rw-card', style: '--kt-rw-accent:' + accent + ';' });
      var head = Dom.el('div', { class: 'kt-rw-head' });
      head.appendChild(Dom.el('div', { class: 'kt-rw-label' }, w.label || ''));
      if (w.icon) head.appendChild(Dom.el('div', { class: 'kt-rw-icon' }, w.icon));
      card.appendChild(head);
      card.appendChild(Dom.el('div', { class: 'kt-rw-value' }, String(w.value == null ? '—' : w.value)));
      if (w.hint) card.appendChild(Dom.el('div', { class: 'kt-rw-hint' }, w.hint));
      strip.appendChild(card);
    });
    return strip;
  }

  // Find the right place to drop the strip in the role's dashboard.
  // Prefer the FIRST hero-like banner (kt-hero, auth-greeting, .hero, [data-hero]);
  // insert immediately AFTER it so the greeting/photo banner stays on top.
  function findInsertionPoint(container) {
    var hero = container.querySelector('.kt-hero, .auth-greeting, .auth-hero, [data-hero], .hero-banner');
    return hero || null;
  }

  function mount(container, opts) {
    if (!container || !Api) return;
    opts = opts || {};
    // De-dupe: drop any prior strip first (handles re-renders)
    var existing = container.querySelector('.kt-role-widget-strip');
    if (existing) existing.remove();

    Api.get('/widgets/me').then(function (data) {
      if (!data || !data.widgets || !data.widgets.length) return;
      var strip = renderStrip(data.widgets);

      if (opts.prepend === true) {
        container.insertBefore(strip, container.firstChild);
        return;
      }
      if (opts.prepend === false) {
        container.appendChild(strip);
        return;
      }
      // Default: place AFTER the hero/banner so the welcome greeting reads first
      var hero = findInsertionPoint(container);
      if (hero && hero.parentNode === container) {
        hero.insertAdjacentElement('afterend', strip);
      } else if (hero) {
        // Hero is nested inside a child wrap — insert right after it within its parent
        hero.insertAdjacentElement('afterend', strip);
      } else {
        // No hero found — put strip at the top
        container.insertBefore(strip, container.firstChild);
      }
    }).catch(function (e) {
      if (window.console) console.warn('Role widgets load failed', e);
    });
  }

  KT.RoleWidgets = { mount: mount, renderStrip: renderStrip };

  // Auto-mount on dashboard-y hashes; non-dashboard hashes skip.
  function isDashboardHash() {
    var h = (window.location.hash || '').replace(/^#/, '').toLowerCase();
    if (!h) return true;
    return /^(dashboard|home|today|overview|platform-overview)$/.test(h);
  }

  function autoMount() {
    if (!isDashboardHash()) return;
    var main = document.getElementById('appMain');
    if (!main || !main.children.length) return;
    // Use the deepest sensible container that wraps the hero
    var hero = main.querySelector('.kt-hero, .auth-greeting, .auth-hero, [data-hero], .hero-banner');
    var target = hero ? hero.parentElement : main;
    mount(target);
  }

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
