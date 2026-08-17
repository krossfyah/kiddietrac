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
     - Insert AFTER the .kt-hero / .auth-greeting banner rather than above
       it. Glass-card design, big icon bubble, larger value typography.
     - Skip silently when the endpoint returns no cards.

   2026-07-20 "cards pop in after everything else" + "no cards after
   Inbox→Home" fix — REWRITTEN to be event-driven instead of timer-raced:
     The old code waited a blind 350ms then fetched then spliced the strip
     in, so the stat cards always landed ~1s after the hero/buttons with a
     layout shift. The first rAF rewrite raced the other way — it fired the
     instant it saw ANY hero, which right after a nav is the OUTGOING
     screen's hero, so it mounted the strip, Home re-rendered and wiped it,
     and the cards only came back if the network fetch happened to land in a
     still-attached container (on some paths it didn't → NO cards at all).
     Now the strip is driven by a MutationObserver on #appMain:
       - Whenever Home is actually rendered (its hero exists) and no strip is
         present, we paint one IMMEDIATELY from a per-role sessionStorage
         CACHE (real cards, instant) or a same-height skeleton (cold cache).
       - This self-heals across re-renders: if Home re-renders and drops the
         strip, the observer re-paints it the same frame.
       - The network refresh is throttled and always targets the LIVE Home
         container (re-found on completion), swapping in place only when the
         value-signature changed — so it can never strand the cards.
   ═══════════════════════════════════════════════════════════════════ */
(function (window) {
  'use strict';
  if (!window.KT) return;
  var KT = window.KT;
  var Api = KT.Api;
  var Dom = KT.Dom;

  var HERO_SEL = '.kt-hero, .auth-greeting, .auth-hero, [data-hero], .hero-banner';

  // Install styles once
  function installStyles() {
    if (document.getElementById('kt-role-widget-style')) return;
    var s = document.createElement('style');
    s.id = 'kt-role-widget-style';
    // v23 (2026-07-20): unified with the portal-wide card theme — tinted card,
    // gradient CIRCULAR icon badge, big accent number, hover lift. (Was a glass
    // card with a top accent bar; now matches the agency/director/parent tiles.)
    s.textContent =
      '.kt-rw-strip{display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:10px;margin:14px 0 18px;}' +
      '.kt-rw-card{position:relative;overflow:hidden;display:flex;align-items:center;gap:11px;background:color-mix(in srgb, var(--kt-rw-accent,#1F6080) 8%, #ffffff);border:1px solid rgba(15,23,42,.06);border-radius:16px;padding:12px 13px;transition:transform .15s ease, box-shadow .15s ease;}' +
      '.kt-rw-body{flex:1 1 auto;min-width:0;}' +
      '.kt-rw-card:hover{transform:translateY(-3px);box-shadow:0 14px 24px -14px rgba(15,23,42,.28);}' +
      '.kt-rw-icon{width:38px;height:38px;flex:0 0 auto;align-self:center;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:18px;margin:0;background:linear-gradient(135deg, color-mix(in srgb, var(--kt-rw-accent,#1F6080) 55%, #ffffff), var(--kt-rw-accent,#1F6080));box-shadow:0 6px 13px -6px var(--kt-rw-accent,#1F6080);}' +
      '.kt-rw-value{font-size:21px;font-weight:900;line-height:1.1;letter-spacing:-0.3px;color:color-mix(in srgb, var(--kt-rw-accent,#1F6080) 80%, #0f172a);font-feature-settings:"tnum";white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}' +
      '.kt-rw-label{font-size:10.5px;font-weight:800;letter-spacing:.6px;text-transform:uppercase;color:color-mix(in srgb, var(--kt-rw-accent,#1F6080) 80%, #0f172a);opacity:.72;margin-top:3px;}' +
      '.kt-rw-hint{font-size:11.5px;color:#64748B;margin-top:2px;line-height:1.35;}' +
      // NB: no display here — the card is ALSO .kt-rw-card{display:flex}. Setting
      // display:block here (it comes later, equal specificity) beat the flex and
      // stacked icon-over-text on desktop for clickable cards (mobile forced flex
      // via !important, hiding the bug). Keep it flex so text stays BESIDE the icon.
      '.kt-rw-link{text-decoration:none;color:inherit;cursor:pointer;-webkit-tap-highlight-color:transparent;}' +
      '.kt-rw-link:active{transform:scale(.985);}' +
      '.kt-rw-go{margin-top:8px;font-size:11.5px;font-weight:800;color:color-mix(in srgb, var(--kt-rw-accent,#1F6080) 80%, #0f172a);opacity:.9;}' +
      // Skeleton placeholder — reserves the strip's space on a cold cache.
      '.kt-rw-card.kt-rw-skel{min-height:104px;background:#FFFFFF;}' +
      '.kt-rw-skel-line{border-radius:7px;background:linear-gradient(90deg,#EEF2F6 25%,#E2E8F0 37%,#EEF2F6 63%);background-size:400% 100%;animation:kt-rw-shimmer 1.3s ease infinite;}' +
      '.kt-rw-skel-label{width:52%;height:11px;margin-bottom:16px;}' +
      '.kt-rw-skel-value{width:40%;height:26px;}' +
      '@keyframes kt-rw-shimmer{0%{background-position:100% 50%}100%{background-position:0 50%}}' +
      '@media (prefers-reduced-motion: reduce){.kt-rw-skel-line{animation:none;}}';
    document.head.appendChild(s);
  }

  // Where each stat card goes when tapped (role-dependent).
  var WIDGET_LINKS = {
    guardian: {
      'today-status': 'today', 'balance': 'billing', 'photos-week': 'photos', 'children-count': 'today', 'open-tasks': 'my-tasks',
    },
    educator: {
      'signed-in': 'today', 'meds-today': 'medications', 'observations': 'observations', 'enrolled': 'children', 'my-hours': 'my-hours', 'open-tasks': 'my-tasks',
    },
    centre_director: {
      'capacity': 'children', 'open-invoices': 'billing-setup', 'missing-checkin': 'today', 'enrolled-count': 'children',
      'signed-in': 'today', 'meds-today': 'medications', 'observations': 'observations', 'enrolled': 'children',
    },
    agency_admin: {
      'capacity': 'admin-children', 'open-invoices': 'bulk-invoices', 'missing-checkin': 'admin-children',
      'enrolled-count': 'admin-children', 'unassigned': 'admin-children',
    },
  };
  function linkFor(role, id) {
    var m = WIDGET_LINKS[role] || {};
    return m[id] || null;
  }
  function roleOf() {
    try {
      var va = sessionStorage.getItem('kt_view_as') || '';
      if (va) return va;
      var u = JSON.parse(sessionStorage.getItem('kt_user') || localStorage.getItem('kt_user') || '{}');
      return u.primary_role || '';
    } catch (e) { return ''; }
  }

  // ── Per-role widget cache (sessionStorage) ────────────────────────────
  function cacheKey(role) { return 'kt_rw_cache_' + (role || 'x'); }
  function readCache(role) {
    try {
      var raw = sessionStorage.getItem(cacheKey(role));
      if (!raw) return null;
      var o = JSON.parse(raw);
      return (o && o.widgets && o.widgets.length) ? o : null;
    } catch (e) { return null; }
  }
  function writeCache(role, data) {
    try { sessionStorage.setItem(cacheKey(role), JSON.stringify({ widgets: data.widgets, role: data.role || role })); } catch (e) {}
  }
  function sigOf(widgets) {
    return JSON.stringify((widgets || []).map(function (w) { return [w.id, w.value, w.hint]; }));
  }

  function renderStrip(widgets, role) {
    installStyles();
    role = role || roleOf();
    var strip = Dom.el('div', { class: 'kt-role-widget-strip kt-rw-strip' });
    strip.setAttribute('data-sig', sigOf(widgets));
    widgets.forEach(function (w) {
      var accent = w.accent || '#1F6080';
      var hash = linkFor(role, w.id);
      var card = hash
        ? Dom.el('a', { class: 'kt-rw-card kt-rw-link', href: '#' + hash, style: '--kt-rw-accent:' + accent + ';' })
        : Dom.el('div', { class: 'kt-rw-card', style: '--kt-rw-accent:' + accent + ';' });
      // Icon and text SIDE BY SIDE: the badge sits to the left, everything else stacks
      // in a body column to its right — tighter, less dead space (was icon-over-text).
      if (w.icon) card.appendChild(Dom.el('div', { class: 'kt-rw-icon' }, w.icon));
      var body = Dom.el('div', { class: 'kt-rw-body' });
      body.appendChild(Dom.el('div', { class: 'kt-rw-value' }, String(w.value == null ? '—' : w.value)));
      body.appendChild(Dom.el('div', { class: 'kt-rw-label' }, w.label || ''));
      if (w.hint) body.appendChild(Dom.el('div', { class: 'kt-rw-hint' }, w.hint));
      if (hash) body.appendChild(Dom.el('div', { class: 'kt-rw-go' }, 'View ›'));
      card.appendChild(body);
      strip.appendChild(card);
    });
    return strip;
  }

  function renderSkeleton(count) {
    installStyles();
    var strip = Dom.el('div', { class: 'kt-role-widget-strip kt-rw-strip kt-rw-skeleton' });
    for (var i = 0; i < count; i++) {
      var card = Dom.el('div', { class: 'kt-rw-card kt-rw-skel' });
      card.appendChild(Dom.el('div', { class: 'kt-rw-skel-line kt-rw-skel-label' }));
      card.appendChild(Dom.el('div', { class: 'kt-rw-skel-line kt-rw-skel-value' }));
      strip.appendChild(card);
    }
    return strip;
  }

  // ── DOM helpers ───────────────────────────────────────────────────────
  function appMain() { return document.getElementById('appMain'); }
  // The educator roster screen (#today / #dashboard) renders its OWN "Today at a
  // glance" brief, so the generic KPI strip is just a repeat of the Home stats.
  // When that shell is on screen, suppress the strip entirely.
  function hasSelfBriefDashboard() {
    var m = appMain();
    return !!(m && m.querySelector('.educator-shell'));
  }
  function heroIn(root) { return root ? root.querySelector(HERO_SEL) : null; }
  // The element the strip lives in: the hero's parent (so it sits right after
  // the banner), else #appMain. Always recomputed against the LIVE DOM.
  function containerFor() {
    var m = appMain(); if (!m) return null;
    var h = heroIn(m);
    return h ? h.parentElement : m;
  }
  function clearAllStrips() {
    var m = appMain(); if (!m) return;
    [].forEach.call(m.querySelectorAll('.kt-role-widget-strip'), function (s) { s.remove(); });
  }
  function currentStrip() {
    var m = appMain(); return m ? m.querySelector('.kt-role-widget-strip') : null;
  }
  function placeStrip(container, strip, opts) {
    opts = opts || {};
    if (opts.prepend === true) { container.insertBefore(strip, container.firstChild); return; }
    if (opts.prepend === false) { container.appendChild(strip); return; }
    var hero = container.querySelector(HERO_SEL);
    if (hero && hero.parentNode === container) { hero.insertAdjacentElement('afterend', strip); }
    else if (hero) { hero.insertAdjacentElement('afterend', strip); }
    else { container.insertBefore(strip, container.firstChild); }
  }

  // Paint from cache (real, instant) or skeleton (cold). De-dupes first.
  function paint(container, opts) {
    if (!container) return;
    clearAllStrips();
    var role = roleOf();
    // These roles have their OWN full dashboards and /widgets/me serves them no
    // cards — so the generic KPI strip would just sit as a stuck skeleton. Skip.
    if (role === 'home_visitor' || role === 'agency_admin' || role === 'platform_admin' || role === 'sales_rep') return;
    var cached = readCache(role);
    if (cached) { placeStrip(container, renderStrip(cached.widgets, cached.role || role), opts); }
    else { placeStrip(container, renderSkeleton(4), opts); }
  }

  // Throttled background refresh. Re-finds the live container on completion so
  // a re-render between request and response can never strand the cards.
  var fetching = false;
  var lastFetchAt = 0;
  var FETCH_THROTTLE_MS = 3000;
  function revalidate(opts, force) {
    if (!Api || fetching) return;
    if (['home_visitor', 'agency_admin', 'platform_admin', 'sales_rep'].indexOf(roleOf()) !== -1) { clearAllStrips(); return; }
    var now = Date.now();
    if (!force && (now - lastFetchAt) < FETCH_THROTTLE_MS) return;
    fetching = true; lastFetchAt = now;
    var role = roleOf();
    var settled = false;
    // Hard timeout: a hung /widgets/me must NEVER leave a permanently stuck
    // skeleton (it also latched `fetching` so no retry could ever fire). After 8s
    // give up, drop a lingering skeleton if we have no cache, and free the lock.
    var killer = setTimeout(function () {
      if (settled) return;
      settled = true; fetching = false;
      var cur0 = currentStrip();
      if (cur0 && cur0.classList.contains('kt-rw-skeleton') && !readCache(roleOf())) clearAllStrips();
    }, 8000);
    Api.get('/widgets/me').then(function (data) {
      if (settled) return; settled = true; clearTimeout(killer);
      fetching = false;
      if (!isDashboardHash()) return;                       // navigated away
      if (hasSelfBriefDashboard()) { clearAllStrips(); return; }  // educator roster owns its brief
      var container = containerFor();
      if (!container || !heroIn(appMain())) return;          // home not up right now
      if (!data || !data.widgets || !data.widgets.length) {  // role has no cards
        clearAllStrips();
        try { sessionStorage.removeItem(cacheKey(role)); } catch (e) {}
        return;
      }
      writeCache(role, data);
      var newSig = sigOf(data.widgets);
      var cur = currentStrip();
      // Real cards already up and unchanged → leave them (no flash).
      if (cur && !cur.classList.contains('kt-rw-skeleton') && cur.getAttribute('data-sig') === newSig) return;
      var strip = renderStrip(data.widgets, data.role);
      clearAllStrips();
      placeStrip(container, strip, opts || {});
    }).catch(function (e) {
      if (settled) return; settled = true; clearTimeout(killer);
      fetching = false;
      // Drop a lingering skeleton if we have nothing cached to fall back on.
      var cur = currentStrip();
      if (cur && cur.classList.contains('kt-rw-skeleton') && !readCache(roleOf())) clearAllStrips();
      if (window.console) console.warn('Role widgets load failed', e);
    });
  }

  // Public API: paint into the given container now, then revalidate.
  function mount(container, opts) {
    if (!container || !Api) return;
    opts = opts || {};
    paint(container, opts);
    revalidate(opts, true);
  }

  KT.RoleWidgets = { mount: mount, renderStrip: renderStrip };

  // ── Auto behaviour (parent/educator/etc. dashboards) ──────────────────
  function isDashboardHash() {
    var h = (window.location.hash || '').replace(/^#/, '').toLowerCase();
    if (!h) return true;
    return /^(dashboard|home|today|overview|platform-overview)$/.test(h);
  }

  // Ensure the strip exists whenever Home is actually on screen. Idempotent:
  // does nothing if a strip is already present (except throttled revalidate).
  function ensure() {
    if (!isDashboardHash()) return;
    var m = appMain();
    if (!m || !heroIn(m)) return;            // Home not rendered yet — wait
    if (hasSelfBriefDashboard()) { clearAllStrips(); return; }  // educator roster owns its brief
    if (!currentStrip()) paint(containerFor(), {});
    revalidate({}, false);
  }

  // Drive `ensure` off DOM changes (self-heals re-renders) + navigation.
  var scheduled = false;
  function scheduleEnsure() {
    if (scheduled) return;
    scheduled = true;
    requestAnimationFrame(function () { scheduled = false; ensure(); });
  }

  var observer = new MutationObserver(scheduleEnsure);
  function startObserver() {
    var m = appMain();
    if (m) { observer.observe(m, { childList: true }); return true; }
    return false;
  }
  // #appMain is in the initial shell HTML, but retry briefly just in case.
  (function waitForMain(tries) {
    if (startObserver()) { scheduleEnsure(); return; }
    if (tries > 60) return;
    requestAnimationFrame(function () { waitForMain(tries + 1); });
  })(0);

  window.addEventListener('hashchange', scheduleEnsure);
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', scheduleEnsure);
  }
})(window);
