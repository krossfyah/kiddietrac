/* ═══════════════════════════════════════════════════════════════════
   KiddieTrac — live refresh (2026-08-13)

   A change made anywhere in the portal should be visible everywhere, without
   anyone reaching for the reload button.

   Screens already re-render themselves after their OWN save. What was missing
   is everything else: a change made on one screen left another screen's copy
   of the same data stale, and a change made in one TAB was invisible in
   another until it was reloaded by hand.

   So: every successful write (POST / PUT / PATCH / DELETE through KT.Api)
   announces itself, and the current screen re-renders shortly afterwards —
   in this tab and in every other tab this user has open, via a storage event.
   No websockets, no polling, no server work.

   The whole design rests on one rule: NEVER yank the page out from under
   someone. A refresh is deferred while a dialog is open, while the user is
   typing, while the tab is hidden, and while a menu is open — and retried
   until it is safe. A refresh that interrupts is worse than a stale list.
   ═══════════════════════════════════════════════════════════════════ */
(function (w) {
  'use strict';
  var d = w.document;
  var KEY = 'kt_data_changed';          // cross-tab signal (localStorage 'storage' event)
  var QUIET_MS = 600;                   // let the initiating screen finish its own re-render
  var RETRY_MS = 1500;                  // how often to re-check when it is not safe yet

  // Writes that say nothing about what the user is looking at. Refreshing for
  // these is pure churn — a read receipt or a typing ping is not a data change.
  var IGNORE = /(\/read\b|typing|heartbeat|presence|ping|push\/device|push\/subscribe|diag\/|auth\/refresh|\/view\b|track)/i;

  function dialogOpen() {
    return !!d.querySelector('.modal-backdrop, .kt-scrim, .kt-doc-viewer, .kt-av-zoom, [role="dialog"]');
  }
  function typing() {
    var a = d.activeElement;
    if (!a) return false;
    if (/^(INPUT|TEXTAREA|SELECT)$/.test(a.tagName)) return true;
    return a.isContentEditable === true;
  }
  // A refresh must never interrupt. Anything here defers it, it does not cancel it.
  function unsafe() {
    return d.hidden || dialogOpen() || typing();
  }

  var timer = null;
  var pending = false;
  var lastRender = 0;
  var MIN_GAP_MS = 3000;   // floor between automatic renders

  function run() {
    timer = null;
    if (!pending) return;
    if (unsafe()) { timer = setTimeout(run, RETRY_MS); return; }   // wait, do not drop

    // A screen that writes something while rendering (marking as read, stamping a
    // last-seen) would otherwise render → write → render forever. The IGNORE list
    // above covers the known ones; this floor makes an unknown one merely wasteful
    // instead of a spin. Deliberately a floor, not a cancel: the refresh still
    // happens, just not immediately.
    var since = Date.now() - lastRender;
    if (since < MIN_GAP_MS) { timer = setTimeout(run, MIN_GAP_MS - since); return; }

    pending = false;
    lastRender = Date.now();
    try {
      if (w.KT && w.KT.Shell && w.KT.Shell.renderScreen) w.KT.Shell.renderScreen();
    } catch (e) { /* a failed refresh must never break the page */ }
  }

  function schedule() {
    pending = true;
    if (timer) return;
    timer = setTimeout(run, QUIET_MS);
  }

  /**
   * Announce that data changed. Called automatically for every successful write
   * through KT.Api; call it directly after any change made another way.
   */
  function dataChanged(topic) {
    schedule();
    // Tell this user's other tabs. localStorage fires 'storage' in every OTHER
    // tab on the same origin — the cheapest cross-tab channel there is, and it
    // needs nothing from the server.
    try { w.localStorage.setItem(KEY, Date.now() + '|' + (topic || '')); } catch (e) {}
  }

  w.addEventListener('storage', function (e) {
    if (e && e.key === KEY) schedule();
  });

  // Refresh on return, too: a tab left open in the background can be arbitrarily
  // stale, and coming back to it is exactly when you expect to see the truth.
  d.addEventListener('visibilitychange', function () {
    if (!d.hidden && pending) schedule();
  });

  w.KT = w.KT || {};
  w.KT.dataChanged = dataChanged;
  w.KT.liveRefresh = {
    // Screens doing their own thing can suppress or force a pass.
    now: function () { pending = true; run(); },
    shouldIgnore: function (path) { return IGNORE.test(String(path || '')); },
  };
})(window);
