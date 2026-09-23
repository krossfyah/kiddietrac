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
  /* `\/read\b` only ever matched a path ENDING in "/read". The endpoint this app
     actually uses is /notifications/mark-read — the character before "read" is a
     hyphen, not a slash — so every read receipt rang the bus and triggered a full
     re-render. On the mobile parent screens that closed a loop: render billing →
     POST mark-read → bus → renderScreen → render billing → ... every 3s, forever
     (MIN_GAP_MS was the only thing keeping it from being tighter). That is the
     "billing keeps reloading over and over".
     Matched on intent now, not on one spelling: /read, -read, mark-read, read-all
     and /seen are all receipts, and a receipt says nothing about what the user is
     looking at. (Anthony, 2026-09-08) */
  var IGNORE = /([\/-]read\b|\bmark[-_]?read\b|\bread[-_]?all\b|[\/-]seen\b|typing|heartbeat|presence|ping|push\/device|push\/subscribe|diag\/|auth\/refresh|\/view\b|track)/i;

  /* Screens that keep THEMSELVES fresh, and must never be torn down and rebuilt
     underneath the person using them.

     Messenger is the case that made this visible. Sending a message is a POST, so the
     bus fired, and 600ms later the entire screen was cleared and re-rendered — while
     the reader was looking at it. That is the "flashing / it keeps refreshing" in
     Messenger, and on desktop it was worse than cosmetic: the re-mount orphaned the
     conversation the floating dock was still showing, so the thread stopped repainting
     and the message you had just sent never appeared.

     These screens already poll their own data in place, which is both cheaper and
     smoother than a full re-render. A blanket re-render adds nothing but the flash.
     Any screen can join them by putting data-kt-self-live on an element it renders.
     (Anthony, 2026-08-26) */
  var SELF_LIVE = { 'chat': 1, 'messages': 1, 'messenger': 1 };

  function currentHash() {
    return (w.location.hash || '').replace('#', '').split('?')[0] || '';
  }
  function selfLive() {
    try {
      if (SELF_LIVE[currentHash()]) return true;
      if (d.querySelector('[data-kt-self-live]')) return true;
    } catch (e) {}
    return false;
  }

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
    /* KT.uiBusy() is the shared answer — this file's dialogOpen() missed
       .kt-modal-overlay and the class-less inline dialogs entirely. Kept alongside as a
       fallback for before the shell has loaded. */
    if (w.KT && typeof w.KT.uiBusy === 'function' && w.KT.uiBusy()) return true;
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
    /* DROPPED, not deferred. Everything else here waits for a safe moment because the
       refresh is still wanted; this one is not wanted at all — the screen has already
       shown the change itself. Deferring would just fire the same pointless teardown
       a second later. */
    if (selfLive()) { return; }
    /* DROPPED for the same reason, one layer along: somebody reading down a log does
       not want it rebuilt because an unrelated write happened elsewhere in the app.
       Dropped rather than deferred — the periodic refresh picks it up the moment they
       scroll back to the top, so nothing is lost by not queueing it. */
    try {
      if (w.KT && w.KT.autoRefresh && w.KT.autoRefresh.readingHistory
          && w.KT.autoRefresh.readingHistory()) { return; }
    } catch (e) {}
    try {
      // Somebody else's write should not make this reader's screen flicker.
      try { w.__ktSilentRefresh = true; } catch (e2) {}
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
    /* Same-tab listeners: the storage event above only reaches OTHER tabs, so
       without this a component could not react to a write made beside it. */
    try {
      w.dispatchEvent(new CustomEvent('kt:data-changed', { detail: { topic: topic || '' } }));
    } catch (e) {}
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

  /* Successful writes ring the bus.
     The bus was fully built and nothing called it -- every screen waited for the 45s
     poll instead. Chaining fetch once here means no write path can forget, and it
     covers the ones written before this existed.

     Chained, not replaced: kt-progress.js also wraps fetch for its loading bar, and
     whichever loads second must not drop the other. GET/HEAD are ignored (they change
     nothing), as are the polling paths already listed in IGNORE, so this fires on real
     changes rather than on traffic. */
  (function () {
    if (w.__ktLiveFetchWrapped) { return; }
    w.__ktLiveFetchWrapped = true;

    /* Deliberately narrow: only paths that look like a badge count, and only for a
       few seconds. Nothing here can serve a stale write or mask a real change. */
    var COUNTISH = /(unread|unread-count|notif|badge)/i;
    var COUNT_TTL_MS = 8000;
    var countCache = {};

    var inner = w.fetch;
    if (typeof inner !== 'function') { return; }

    w.fetch = function (input, init) {
      /* Count-shaped GETs are de-duplicated for a few seconds.
         Four components poll the same unread/notification figures; without this they
         each hit the network on their own timer and could render different numbers for
         the same thing. Sharing one in-flight response makes them agree, and more than
         pays for the faster cadences above. Writes and everything else are untouched. */
      try {
        var m0 = String((init && init.method) || (input && input.method) || 'GET').toUpperCase();
        var u0 = String((input && input.url) || input || '');
        if ((m0 === 'GET' || m0 === 'HEAD') && COUNTISH.test(u0)) {
          var hit = countCache[u0];
          var t0 = Date.now();
          if (hit && (t0 - hit.at) < COUNT_TTL_MS) {
            return hit.p.then(function (r) { return r.clone(); });
          }
          var p0 = inner.apply(this, arguments);
          countCache[u0] = { at: t0, p: p0 };
          return p0.then(function (r) { return r.clone(); });
        }
      } catch (e) {}

      var res = inner.apply(this, arguments);
      try {
        var method = String((init && init.method) || (input && input.method) || 'GET').toUpperCase();
        if (method === 'GET' || method === 'HEAD') { return res; }

        var url = String((input && input.url) || input || '');
        if (IGNORE.test(url)) { return res; }

        return res.then(function (r) {
          try { if (r && r.ok) { dataChanged(url); } } catch (e) {}
          return r;
        });
      } catch (e) {}
      return res;
    };
  })();
  w.KT.liveRefresh = {
    // Screens doing their own thing can suppress or force a pass.
    now: function () { pending = true; run(); },
    shouldIgnore: function (path) { return IGNORE.test(String(path || '')); },
  };
})(window);
