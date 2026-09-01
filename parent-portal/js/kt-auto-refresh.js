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
    'incidents': 1, 'notifications': 1,
    /* chat / messages are NOT here. Messenger polls its own list and its open thread
       in place; a full re-render on top of that only produced a visible flash, and on
       desktop it orphaned the conversation held in the floating dock. See kt-live.js's
       SELF_LIVE for the same rule applied to write-triggered refreshes. */
    'attendance': 1, 'time-clock': 1,
    /* Screens whose whole purpose is watching what people are doing right now.
       Without these the Users table showed the last-login time as at whenever the
       page happened to load, which read as "last login is not updating". */
    'admin-users': 1, 'users': 1, 'user-management': 1,
    'staff': 1, 'audit-logs': 1, 'email-logs': 1
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
      // A screen that updates itself in place needs no teardown from us.
      if (document.querySelector('[data-kt-self-live]')) return true;
    } catch (e) {}
    return false;
  }

  /* A screen cannot otherwise tell a periodic re-render from somebody navigating
     to it — both call renderScreen() and both look like a fresh mount. Screens that
     restore state across a refresh (the audit log's subtab) need that difference:
     keeping your place through a refresh is right, and keeping it when you clicked
     the menu is how "Audit log" started opening the Email log. */
  var refreshing = false;
  function refresh() {
    if (!shellReady() || booting() || busy()) return;
    refreshing = true;
    try { KT.Shell.renderScreen(); } catch (e) {}
    // Cleared on a timer, not immediately: a screen's async part renders after
    // renderScreen() returns, and that is where the flag is read.
    setTimeout(function () { refreshing = false; }, 4000);
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
    /* The old rule was "scrolled past 80px? do not refresh". Combined with the 45s
       cadence and the LIVE_SCREENS whitelist it meant a scrolled screen never refreshed
       at all until you navigated away, which is exactly what "it takes minutes to catch
       up" was. Scroll position is restored instead, so a refresh no longer costs you
       your place. */
    /* No scroll bookkeeping here any more. There were TWO restores fighting each
       other — this fixed 60ms one and the shell's own — and both fired before the
       async part of a screen had rendered, so each clamped to the height of a
       half-built page and the reader ended up somewhere neither intended. The shell
       owns this now (__ktSettleScroll): it re-applies the position while the page is
       still growing and yields the moment the reader scrolls. */
    refresh();
  }, PERIODIC_MS);

  KT.autoRefresh = {
    refresh: refresh,
    LIVE_SCREENS: LIVE_SCREENS,
    isRefreshing: function () { return refreshing; },
  };
})();
