/* ═══════════════════════════════════════════════════════════════════
   kt-auto-refresh.js — keep the current screen fresh without a manual reload.

   Two triggers, both heavily guarded so a refresh NEVER interrupts the user:
     1. Return-to-app: when the tab / APK regains visibility after being hidden
        for ≥ 12s, re-render the current screen so stale data is refreshed. A
        scroll-to-top on return is expected, so this fires on any screen.
     2. Gentle live poll: for a small allowlist of read-only "live" screens
        (dashboard, agency overview, ratios, waitlist, notifications, chat…),
        re-render every 45s — but ONLY while the user is at the top of the page
        (scrollY < 80), so a refresh can never yank someone reading further down.

   Guards — skip the refresh entirely if ANY is true:
     • the boot / reload gate is still up (html.kt-gating or #kt-splash visible)
     • a modal / lightbox / menu is open
     • an input / textarea / select / contenteditable is focused (user typing)
     • the screen opted out with [data-kt-no-autorefresh]
     • the shell isn't ready yet

   The shell re-render (KT.Shell.renderScreen) is the same code a hashchange runs,
   so this reuses the exact, tested render path — no bespoke fetching here.
   ═══════════════════════════════════════════════════════════════════ */
(function () {
  'use strict';
  var KT = window.KT || (window.KT = {});

  var PERIODIC_MS  = 45000;   // live-screen poll cadence
  var MIN_HIDDEN_MS = 12000;  // how long away before a return-to-app refresh
  var START_DELAY_MS = 9000;  // don't poll until well past first boot

  // Read-only screens that are safe + worth refreshing on a timer. Anything not
  // listed still gets the return-to-app refresh, just not the periodic one.
  var LIVE_SCREENS = {
    'dashboard': 1, 'agency-overview': 1, 'overview': 1, 'provider-overview': 1,
    'provider-day': 1, 'room-ratios': 1, 'waitlist': 1, 'tours': 1,
    'incidents': 1, 'notifications': 1, 'chat': 1, 'messages': 1,
    'attendance': 1, 'time-clock': 1
  };

  function now() { return Date.now(); }
  function currentHash() {
    return (window.location.hash || '').replace('#', '').split('?')[0] || 'dashboard';
  }
  function shellReady() {
    return !!(KT.Shell && typeof KT.Shell.renderScreen === 'function');
  }

  function booting() {
    try {
      if (document.documentElement.classList.contains('kt-gating')) return true;
      var sp = document.getElementById('kt-splash');
      if (sp && sp.offsetParent !== null) return true; // splash still visible
    } catch (e) {}
    return false;
  }

  function busy() {
    try {
      // Modal / overlay / menu open.
      var mr = document.getElementById('modalRoot');
      if (mr && mr.firstElementChild) return true;
      if (document.querySelector('.kt-modal, .kt-lightbox, [data-kt-open-menu], .kt-sheet-open')) return true;
      // User is interacting with a control.
      var a = document.activeElement;
      if (a) {
        var t = (a.tagName || '').toUpperCase();
        if (t === 'INPUT' || t === 'TEXTAREA' || t === 'SELECT') return true;
        if (a.isContentEditable) return true;
      }
      // Explicit opt-out on the current screen.
      if (document.querySelector('[data-kt-no-autorefresh]')) return true;
    } catch (e) {}
    return false;
  }

  function refresh() {
    if (!shellReady() || booting() || busy()) return;
    try { KT.Shell.renderScreen(); } catch (e) {}
  }

  // ── 1. Return-to-app visibility refresh ──────────────────────────
  var hiddenAt = 0;
  document.addEventListener('visibilitychange', function () {
    if (document.hidden) { hiddenAt = now(); return; }
    var away = now() - (hiddenAt || 0);
    if (hiddenAt && away >= MIN_HIDDEN_MS) refresh();
    hiddenAt = 0;
  });

  // ── 2. Gentle top-of-page periodic refresh for live screens ──────
  var started = now();
  setInterval(function () {
    if (now() - started < START_DELAY_MS) return;
    if (document.hidden) return;
    if (!LIVE_SCREENS[currentHash()]) return;
    var y = window.scrollY || window.pageYOffset || 0;
    if (y > 80) return;   // scrolled into content — leave the user be
    refresh();
  }, PERIODIC_MS);

  KT.autoRefresh = { refresh: refresh, LIVE_SCREENS: LIVE_SCREENS };
})();
