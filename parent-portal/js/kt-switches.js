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
(function (w) {
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
      if (eligible(cb)) cb.classList.add('kt-switchified');
    }
  }

  (w.KT && KT.sweepBus) ? KT.sweepBus.on(sweep) : setInterval(sweep, 2000);
  w.addEventListener('hashchange', function () { setTimeout(sweep, 140); });
  setTimeout(sweep, 500);
})(window);
