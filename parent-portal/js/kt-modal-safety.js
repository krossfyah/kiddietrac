/* ═══════════════════════════════════════════════════════════════════
   KiddieTrac — a dialog is left on purpose, not by tapping past it.

   Two rules, in this order:
     1. Clicking the backdrop does NOT close a dialog that offers a way out (×, Cancel,
        Close…). Leaving is a deliberate act.
     2. Where a dialog offers no way out, the backdrop still closes it — but only on a
        real click, never on a drag that merely ENDED there.

   Around thirty dialogs in this portal dismiss with the same one-liner:

       overlay.addEventListener('click', e => { if (e.target === overlay) close(); });

   which is wrong in a way that only shows up once somebody is actually typing. A `click`
   is dispatched to the nearest common ancestor of where the pointer went DOWN and where it
   came UP. Select the text in a field and release a few pixels outside the dialog — as
   people constantly do when correcting an entry, dragging across a value, or flicking a
   scrollbar — and the common ancestor is the backdrop. `e.target === overlay` is true, the
   dialog closes, and everything typed is gone. Reported as "the popup loses focus and
   closes while entering data".

   app-v2-shell.js already solved this for its own modal by remembering whether the
   mousedown STARTED on the backdrop. Copying that line into thirty files would fix them
   until the thirty-first is written, so the rule lives here once instead:

       a real backdrop click goes down AND up on the backdrop.

   Anything else is a drag, and a drag is not a dismissal. Such a click is stopped in the
   CAPTURE phase, so the per-dialog handlers never see it and need no changes.

   Deliberately narrow, because suppressing clicks is not a thing to do casually:
     · only when the pointer went down INSIDE the element the click landed on — a genuine
       press-and-release on the backdrop is untouched, and so is every ordinary click
     · only when that element actually looks like an overlay: fixed or absolute, and
       covering most of the viewport. A drag inside an ordinary panel is left alone.

   Nothing here closes anything; it only declines to treat a drag as a click.
   (Anthony, 2026-09-09)
   ═══════════════════════════════════════════════════════════════════ */
(function (window) {
  'use strict';
  if (window.__ktModalSafety) return; window.__ktModalSafety = true;

  var downTarget = null;

  function looksLikeOverlay(el) {
    if (!el || el.nodeType !== 1 || el === document.body || el === document.documentElement) return false;
    var cs;
    try { cs = window.getComputedStyle(el); } catch (e) { return false; }
    if (!cs || (cs.position !== 'fixed' && cs.position !== 'absolute')) return false;
    var r;
    try { r = el.getBoundingClientRect(); } catch (e) { return false; }
    var vw = window.innerWidth || 0, vh = window.innerHeight || 0;
    if (!vw || !vh) return false;
    // Covers most of the screen in BOTH axes — a backdrop, not a popover or a toast.
    return r.width >= vw * 0.6 && r.height >= vh * 0.6;
  }

  document.addEventListener('pointerdown', function (e) { downTarget = e.target; }, true);
  // Safari/older WebViews without pointer events still give us mousedown/touchstart.
  document.addEventListener('mousedown', function (e) { if (!downTarget) downTarget = e.target; }, true);
  document.addEventListener('touchstart', function (e) {
    if (!downTarget && e.touches && e.touches[0]) downTarget = e.touches[0].target;
  }, true);

  /* DOES THIS DIALOG OFFER A WAY OUT? — the same selector kt-modal-guard.js uses to
     decide whether a popup already ships its own close, so the two cannot disagree about
     what counts. That guard grafts a × into any popup lacking one, which is what makes it
     safe to take the backdrop away. */
  var CLOSE_SEL = '.modal-close, .kt-modal-x, [data-close], [aria-label="Close"]';

  function hasWayOut(overlay) {
    try {
      if (overlay.querySelector(CLOSE_SEL)) { return true; }
      /* Plenty of dialogs close with a worded button instead of a glyph. Matched on the
         whole label so "Close" counts and "Closed on Monday" does not. */
      var btns = overlay.querySelectorAll('button, a[role="button"]');
      for (var i = 0; i < btns.length; i++) {
        var t = (btns[i].textContent || '').replace(/\s+/g, ' ').trim().toLowerCase();
        if (/^(×|✕|✖|x|close|cancel|done|not now|dismiss|back|no thanks)$/.test(t)) { return true; }
      }
    } catch (e) {}
    return false;
  }

  document.addEventListener('click', function (e) {
    var t = e.target, d = downTarget;
    downTarget = null;                       // one click per press, whatever happens next
    if (!t || !looksLikeOverlay(t)) { return; }   // not a backdrop; none of our business

    /* CLICKING OUTSIDE NO LONGER DISMISSES A DIALOG.

       Around thirty popups closed on any backdrop click, which loses whatever was typed —
       and a misplaced tap on a phone is not a decision to discard a half-filled form.
       Leaving is now a deliberate act: the ×, Cancel, or whatever the dialog offers.

       Conditional on the dialog HAVING one of those, deliberately. Taking the backdrop
       away from a popup with no close control would trap somebody in it with no way back,
       which is worse than the problem being fixed. kt-modal-guard.js grafts a × into any
       popup missing one, so in practice the way out is always there — but this checks
       rather than assumes, because a popup that opts out of that guard
       (data-no-modal-guard) is exactly the kind that might not have one.

       When there is no way out, the older rule still applies: a press that began INSIDE
       the dialog and merely finished on the backdrop is a drag, not a dismissal.
       (Anthony, 2026-09-09) */
    if (hasWayOut(t)) {
      e.stopPropagation();
      if (e.stopImmediatePropagation) e.stopImmediatePropagation();
      return;
    }

    if (!d || d === t) { return; }                     // a genuine backdrop click: let it close
    if (!t.contains || !t.contains(d)) { return; }     // not a drag that ended on an ancestor
    e.stopPropagation();
    if (e.stopImmediatePropagation) e.stopImmediatePropagation();
  }, true);

  /* A drag that ends outside the window never produces a click at all, but it does leave
     downTarget set; clear it so the NEXT genuine backdrop click is not mistaken for the
     tail of this one. */
  window.addEventListener('blur', function () { downTarget = null; });
  document.addEventListener('pointercancel', function () { downTarget = null; }, true);
})(window);
