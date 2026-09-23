/* ═══════════════════════════════════════════════════════════════════
   KiddieTrac — turn on/off setting checkboxes into physical toggle switches.
   Applies `.kt-switchified` (styled in kt-consistency-polish.css) to eligible
   checkboxes in #appMain. Restyles the native input IN PLACE — checked state,
   name, value and all event handlers are preserved (appearance:none only).

   Eligible = an explicit opt-in (`data-kt-switch`), OR a standalone on/off
   checkbox. SKIPPED: table row-selects, multi-checkbox groups (same name),
   listboxes, and anything under [data-kt-noswitch] — those are not on/off
   settings and must stay as checkboxes.
   ═══════════════════════════════════════════════════════════════════ */
(function (w, d) {
  'use strict';
  var KT = w.KT || (w.KT = {});
  if (KT.switchesLoaded) return;
  KT.switchesLoaded = true;

  function eligible(cb) {
    if (cb.dataset.ktSwitch) return true; // explicit opt-in always wins
    // Never touch selection checkboxes in tables/grids or opted-out regions.
    if (cb.closest('table, thead, tbody, [role="grid"], [role="listbox"], [data-kt-noswitch], .kt-checkbox-group')) return false;
    // Part of a multi-select group (same name, >1) → it's a choice list, not a toggle.
    if (cb.name) {
      try {
        var scope = cb.form || document;
        var g = scope.querySelectorAll('input[type="checkbox"][name="' + (w.CSS && CSS.escape ? CSS.escape(cb.name) : cb.name) + '"]');
        if (g && g.length > 1) return false;
      } catch (e) { /* odd name → treat as standalone */ }
    }
    return true;
  }

  function sweep() {
    // #appMain alone misses dialogs. A modal has to be appended to <body> to escape
    // the sidebar/top-bar stacking context (otherwise they paint over its scrim), so
    // its controls sit outside #appMain and were left as bare checkboxes while the
    // same control inside the page rendered as a switch. Every KiddieTrac dialog
    // carries data-no-modal-guard, so cover those too.
    var cbs = document.querySelectorAll(
      '#appMain input[type="checkbox"]:not([data-kt-sw]),'
      + ' [data-no-modal-guard] input[type="checkbox"]:not([data-kt-sw])');
    if (!cbs.length) return;
    for (var i = 0; i < cbs.length; i++) {
      var cb = cbs[i];
      cb.setAttribute('data-kt-sw', '1');
      // The switch is now the CSS default, so this marks the EXCEPTIONS instead of
      // the rule. It leaves nothing to do at first paint, which is what stopped the
      // control flicking from square to pill a second after every screen change.
      // .kt-switchified is still set on eligible controls for anything that looks
      // for it.
      if (eligible(cb)) cb.classList.add('kt-switchified');
      else cb.classList.add('kt-nosw');
    }
  }

  /* An observer, not a timer. The remaining job — spotting several checkboxes that
     share a name — has to happen before the user sees them, and a two-second poll
     was never going to manage that. The interval stays as a backstop only.

     Coalesced to one pass per frame: this SPA rebuilds whole screens at a time, and
     running a querySelectorAll for every individual node insertion would cost more
     than the flicker it is fixing. */
  if (w.MutationObserver) {
    try {
      var queued = false;
      new MutationObserver(function () {
        if (queued) return;
        queued = true;
        (w.requestAnimationFrame || setTimeout)(function () { queued = false; sweep(); });
      }).observe(d.documentElement, { childList: true, subtree: true });
    } catch (e) { /* fall through to the sweeps below */ }
  }
  (w.KT && KT.sweepBus) ? KT.sweepBus.on(sweep) : setInterval(sweep, 2000);
  w.addEventListener('hashchange', function () { setTimeout(sweep, 140); });
  setTimeout(sweep, 0);
  setTimeout(sweep, 500);
})(window, document);
