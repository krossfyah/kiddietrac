/* ONE SCROLLBAR PER POPUP (2026-10-13, Anthony: "manage user has two scroll bars when
   clicking on view details/manage - full sweep").

   Several dialogs give their tab content a steady, fixed-height scroll pane
   (height:min(62vh,680px); overflow-y:auto) so switching tabs never resizes the window —
   Manage user, Edit family, the audit-log detail, the child record. But the pane's fixed
   height plus the tabs and padding above it can come out taller than the window, so the
   window's own body scrolls a few pixels as well: two scrollbars, one inside the other.

   This sizes such a pane to exactly the room the window has left, so only the pane
   scrolls. It touches only a pane that has BOTH an inline height and its own overflow
   scrolling, inside .modal-body, and only when that body actually overflows. */
(function () {
  'use strict';
  if (window.__ktFitPane) return;
  window.__ktFitPane = true;

  function isSteadyPane(el, body) {
    if (el === body || !el.style) return false;
    var h = el.style.height || '';
    var ov = el.style.overflowY || el.style.overflow || '';
    return /min\(|vh|px/.test(h) && /auto|scroll/.test(ov);
  }
  function fit(body) {
    var panes = [].filter.call(body.querySelectorAll('div'), function (d) { return isSteadyPane(d, body); });
    if (!panes.length) return;
    var pane = panes[0];
    if (!pane.dataset.ktFitOrig) pane.dataset.ktFitOrig = pane.style.height;
    // Start from the pane's intended height, then take away whatever makes the body overflow.
    pane.style.height = pane.dataset.ktFitOrig;
    var over = body.scrollHeight - body.clientHeight;
    if (over > 0) {
      var h = Math.max(220, pane.getBoundingClientRect().height - over - 1);
      pane.style.height = h + 'px';
    }
  }
  function sweep() {
    var root = document.getElementById('modalRoot');
    if (!root) return;
    [].forEach.call(root.querySelectorAll('.modal-body'), function (b) { try { fit(b); } catch (e) {} });
  }

  var pending = false;
  function soon() {
    if (pending) return;
    pending = true;
    (window.requestAnimationFrame || setTimeout)(function () { pending = false; sweep(); });
  }
  (function watch() {
    var root = document.getElementById('modalRoot');
    if (!root) { setTimeout(watch, 300); return; }
    if (window.MutationObserver) new MutationObserver(soon).observe(root, { childList: true, subtree: true });
  })();
  window.addEventListener('resize', soon);
  window.KT = window.KT || {};
  window.KT.fitModalPanes = sweep;
})();
