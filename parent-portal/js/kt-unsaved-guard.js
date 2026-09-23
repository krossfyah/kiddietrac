/* A HALF-FILLED FORM IS NOT "IDLE" (2026-09-18).

   Lloydene, filling an inspection form or a home-visit report: "the form keeps clearing
   for her and she needs to start over and over again."

   Nothing was wrong with the form. It was being torn down underneath her.

   Both screens render inline into #appMain and keep every answer IN THE DOM - there is no
   model behind them, and "Save as draft" is a button you have to press. kt-live.js calls
   KT.Shell.renderScreen() after any write this tab makes, and renderScreen() clears
   #appMain and re-runs the screen's render function, which rebuilds an EMPTY form. Every
   answer typed since she opened it is gone.

   The refreshers do try to be careful. They defer while KT.uiBusy(), and uiBusy() asks
   two questions: is a field focused, and is a dialog open. Neither is the right question.
   A form being filled is not "busy" by those tests the instant she taps a radio button,
   scrolls to read the next section, closes the phone keyboard, or comes back from the
   camera - and that is exactly the moment the deferred refresh fires. The refresh was not
   cancelled while she typed; it was WAITING for her to stop.

   So this adds the missing question: has the person entered something here that is not
   saved yet? Opt-in, via data-kt-guard-unsaved on the container a screen renders, because
   "never refresh a screen with a dirty field" would freeze the live dashboards that are
   supposed to keep moving.

   The flag survives until the work does: cleared when they navigate away deliberately, or
   when the screen says it has saved (KT.clearUnsaved()). It is deliberately NOT cleared
   on a timer - a form can be open for twenty minutes and it is still unsaved at minute
   nineteen. */
(function (w, d) {
  'use strict';
  var KT = w.KT = w.KT || {};

  var dirty = false;
  var dirtyHash = null;

  function currentHash() {
    return (w.location.hash || '').replace('#', '').split('?')[0] || '';
  }

  /* Anything the person actually entered. A click on a button is not input, and a
     programmatic value change does not raise a trusted event, so a screen painting its
     own defaults does not mark itself dirty. */
  function onEdit(e) {
    try {
      if (!e || !e.isTrusted) { return; }
      var t = e.target;
      if (!t || !t.closest) { return; }
      if (!t.closest('[data-kt-guard-unsaved]')) { return; }
      var tag = (t.tagName || '').toUpperCase();
      if (tag !== 'INPUT' && tag !== 'TEXTAREA' && tag !== 'SELECT' && !t.isContentEditable) { return; }
      dirty = true;
      dirtyHash = currentHash();
    } catch (err) { /* a guard must never break the screen it guards */ }
  }

  d.addEventListener('input', onEdit, true);
  d.addEventListener('change', onEdit, true);

  /* Navigating away is a decision. Whatever was typed is already gone by then, so holding
     the flag would only block the NEXT screen's refreshes. */
  w.addEventListener('hashchange', function () {
    if (dirtyHash && currentHash() !== dirtyHash) { dirty = false; dirtyHash = null; }
  });

  /** True while this screen holds input the person has not saved. */
  KT.hasUnsavedInput = function () {
    if (!dirty) { return false; }
    /* The guarded container has to still be on screen. If the screen was replaced by
       something else without a hash change, the answer is no longer about this form. */
    try {
      if (!d.querySelector('[data-kt-guard-unsaved]')) { dirty = false; dirtyHash = null; return false; }
    } catch (e) {}

    return dirtyHash === currentHash();
  };

  /** Screens call this once their save or submit has actually succeeded. */
  KT.clearUnsaved = function () { dirty = false; dirtyHash = null; };

  /* Fold it into the one answer every refresher already asks, rather than adding a
     fourth thing each of them has to remember to check. uiBusy() is defined in
     app-v2-shell.js, which may load after this file, so the wrap is deferred until it
     exists. */
  function wrap() {
    if (typeof KT.uiBusy !== 'function' || KT.uiBusy.__ktUnsavedWrapped) { return false; }
    var inner = KT.uiBusy;
    KT.uiBusy = function () {
      try { if (KT.hasUnsavedInput()) { return true; } } catch (e) {}

      return inner.apply(this, arguments);
    };
    KT.uiBusy.__ktUnsavedWrapped = true;

    return true;
  }
  if (!wrap()) {
    var tries = 0;
    var t = setInterval(function () {
      if (wrap() || ++tries > 100) { clearInterval(t); }
    }, 100);
  }
})(window, document);
