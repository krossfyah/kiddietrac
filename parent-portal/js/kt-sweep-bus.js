/* ═══════════════════════════════════════════════════════════════════
   KiddieTrac — shared DOM-settle sweep bus (2026-07-27, perf audit).
   The portal had ~14 independent setInterval "sweeps" (kt-polish v1–v4,
   kt-table-filter, kt-list-controls, kt-term, kt-banner-normalize) each
   re-scanning #appMain every 1.5–2.5s FOREVER — the CPU never idled, which
   was the top source of scroll jank + battery drain on the APK.

   This routes them all through ONE MutationObserver on #appMain: a sweep now
   runs when the DOM actually CHANGES (screen render / dynamic content), not on a
   timer. When nothing changes, nothing runs. The sweeps are idempotent (they
   guard against re-decorating — proven by the fact they ran repeatedly before),
   and the observer is DISCONNECTED while sweeps run so their own mutations can't
   re-trigger it. A slow 6s safety pass (visible tab only) self-heals anything the
   observer might miss, so worst-case behaviour is strictly better than before.

   Usage (replaces `setInterval(mySweep, 2000)`):
       (window.KT && KT.sweepBus) ? KT.sweepBus.on(mySweep) : setInterval(mySweep, 4000);
   ═══════════════════════════════════════════════════════════════════ */
(function (w) {
  'use strict';
  var KT = w.KT || (w.KT = {});
  if (KT.sweepBus) return;

  var fns = [];
  var main = null;
  var observer = null;
  var debTimer = null;
  var pending = false;

  function mainEl() { return document.getElementById('appMain'); }

  function runAll() {
    pending = false;
    // Disconnect so the sweeps' own DOM writes don't re-trigger us (infinite loop).
    if (observer) { try { observer.disconnect(); } catch (e) {} }
    for (var i = 0; i < fns.length; i++) {
      try { fns[i](); } catch (e) {}
    }
    // Re-observe (re-find #appMain in case the shell replaced it).
    var m = mainEl();
    if (m) {
      main = m;
      if (!observer) observer = new MutationObserver(onMutate);
      try { observer.observe(main, { childList: true, subtree: true }); } catch (e) {}
    } else {
      setTimeout(attach, 300);
    }
  }

  function schedule() {
    if (pending) return;
    pending = true;
    setTimeout(runAll, 0);
  }

  // DOM-change path: run the sweeps in the pre-paint animation frame so freshly
  // rendered buttons/toggles/cards are decorated BEFORE the browser paints them.
  // This removes the brief "text button flashes then turns into an icon" flicker
  // (the old 180ms debounce painted the raw text first). The `pending` flag
  // coalesces a burst of mutations from one render into a single pass; rAF is also
  // paused on hidden tabs, so backgrounded WebViews stay idle.
  function scheduleFrame() {
    if (pending) return;
    pending = true;
    (window.requestAnimationFrame || function (f) { setTimeout(f, 16); })(runAll);
  }

  function onMutate() {
    scheduleFrame();
  }

  function attach() {
    main = mainEl();
    if (!main) { setTimeout(attach, 300); return; }
    if (!observer) observer = new MutationObserver(onMutate);
    try { observer.disconnect(); } catch (e) {}
    try { observer.observe(main, { childList: true, subtree: true }); } catch (e) {}
    schedule(); // initial pass
  }

  KT.sweepBus = {
    // Register a sweep: runs it soon, then on every DOM-settle / navigation.
    on: function (fn) { if (typeof fn === 'function') { fns.push(fn); schedule(); } },
    // Force a pass (e.g. after a manual render).
    run: schedule,
  };

  // Navigation always warrants a pass (the shell may swap #appMain).
  w.addEventListener('hashchange', function () { setTimeout(schedule, 60); setTimeout(schedule, 500); });

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', attach);
  else attach();

  // Slow safety net — only while the tab is visible (backgrounded WebViews are
  // paused anyway). One coalesced pass every 6s is negligible vs the old 14 timers.
  setInterval(function () { if (!document.hidden) schedule(); }, 6000);
})(window);
