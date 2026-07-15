/* ═══════════════════════════════════════════════════════════════════
   KiddieTrac — unified back navigation (2026-07-10).
   One place that decides what "back" means, for BOTH the Android hardware
   back button (Capacitor App plugin) and the in-app ← buttons:

     1. A full-screen overlay open (chat thread, compose, invoice, scanner)?
        → close the top-most one.
     2. The slide-in menu drawer open? → close it.
     3. On a sub-screen? → go to the PREVIOUS screen (shell _hashStack).
     4. Already home? → minimise the app (background it) — never hard-exit.

   Overlays register with KT.pushOverlay(el, dismiss) when they open and call
   KT.popOverlay(el) from their own ← button. goBack() is debounced so a stray
   double-fire (e.g. a capture handler + the element's own handler) can only
   navigate once — this kills the "goes back then jumps to home" bug.
   ═══════════════════════════════════════════════════════════════════ */
(function (window) {
  'use strict';
  var KT = window.KT = window.KT || {};
  if (KT.__backWired) return; KT.__backWired = true;

  var overlays = [];

  KT.pushOverlay = function (el, dismiss) {
    if (!el) return;
    overlays.push({ el: el, dismiss: dismiss || function () { if (el.remove) el.remove(); } });
  };
  KT.popOverlay = function (el) {
    prune();
    var idx = -1;
    if (el) { for (var i = overlays.length - 1; i >= 0; i--) { if (overlays[i].el === el) { idx = i; break; } } }
    else { idx = overlays.length - 1; }
    if (idx < 0) { if (el && el.remove) el.remove(); return; }
    var o = overlays.splice(idx, 1)[0];
    try { o.dismiss(); } catch (e) { if (o.el && o.el.remove) o.el.remove(); }
  };
  KT.hasOverlay = function () { prune(); return overlays.length > 0; };

  // Drop overlays whose element already left the DOM (a screen re-render cleared it).
  function prune() { overlays = overlays.filter(function (o) { return o.el && document.body.contains(o.el); }); }

  function goBackOnce() {
    prune();
    if (overlays.length) { var o = overlays.pop(); try { o.dismiss(); } catch (e) {} return true; }
    if (document.body.classList.contains('kt-mnav-open')) { document.body.classList.remove('kt-mnav-open'); return true; }
    var h = (window.location.hash || '').replace('#', '').split('?')[0];
    var homes = { '': 1, home: 1, dashboard: 1 };  // today is a parent sub-screen: back from it goes home, not a no-op
    if (!homes[h]) {
      if (typeof window.ktBack === 'function') window.ktBack();
      else window.location.hash = '#home';
      return true;
    }
    return false; // already at a home screen
  }

  // Debounced: any double invocation within 450ms is a no-op that still reports
  // "handled", so overlapping handlers can't double-navigate.
  var lastBack = 0;
  KT.goBack = function () {
    var now = Date.now();
    if (now - lastBack < 450) return true;
    lastBack = now;
    return goBackOnce();
  };

  // ── Android hardware / gesture back (Capacitor App plugin) ──────────
  function wireCapacitor() {
    try {
      var C = window.Capacitor, App = C && C.Plugins && C.Plugins.App;
      if (!App || !App.addListener || KT.__capBack) return;
      KT.__capBack = true;
      App.addListener('backButton', function () {
        var handled = KT.goBack();
        if (!handled) { try { if (App.minimizeApp) App.minimizeApp(); else if (App.exitApp) App.exitApp(); } catch (e) {} }
      });
    } catch (e) {}
  }
  wireCapacitor();
  setTimeout(wireCapacitor, 1200);
  setTimeout(wireCapacitor, 3000);
})(window);
