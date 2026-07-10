/* ═══════════════════════════════════════════════════════════════════
   KIDDIETRAC — Global modal guard (v22p85)
   Ensures EVERY popup in the portal:
     1. has a visible "×" close control (top-right of the dialog), and
     2. stays contained within the viewport (centred, scrolls internally
        instead of pushing the page or spilling off-screen).
   Works without each screen opting in: it watches the two places popups are
   mounted (document.body and #modalRoot) and enhances any full-screen overlay.
   Popups that already ship their own close (Shell.Modal's .modal-close) are
   left alone. Heuristics are size-based so toasts / the chat widget / the FAB
   (all small, fixed elements) are never treated as modals.
   ═══════════════════════════════════════════════════════════════════ */
(function (window) {
  'use strict';
  var doc = window.document;

  function vw() { return window.innerWidth || doc.documentElement.clientWidth; }
  function vh() { return window.innerHeight || doc.documentElement.clientHeight; }

  // Is `el` a full-screen modal overlay/backdrop?
  function isOverlay(el) {
    if (!el || el.nodeType !== 1 || el.__ktModal) return false;
    var cs;
    try { cs = getComputedStyle(el); } catch (e) { return false; }
    if (cs.position !== 'fixed' && cs.position !== 'absolute') return false;
    if (cs.display === 'none' || cs.visibility === 'hidden') return false;
    var r = el.getBoundingClientRect();
    // Must blanket most of the viewport (a backdrop), and contain a dialog.
    if (r.width < vw() * 0.8 || r.height < vh() * 0.8) return false;
    if (!el.children || el.children.length === 0) return false;
    return true;
  }

  // The dialog card = the largest child that isn't itself full-screen.
  function findCard(overlay) {
    var best = null, bestArea = 0;
    for (var i = 0; i < overlay.children.length; i++) {
      var c = overlay.children[i];
      if (c.nodeType !== 1) continue;
      var r = c.getBoundingClientRect();
      if (r.width >= vw() * 0.94 && r.height >= vh() * 0.94) continue; // nested backdrop
      var area = r.width * r.height;
      if (area > bestArea) { bestArea = area; best = c; }
    }
    return best;
  }

  function hasClose(card) {
    if (card.querySelector('.modal-close, .kt-modal-x, [data-close], [aria-label="Close"]')) return true;
    var els = card.querySelectorAll('button, a, span, i');
    for (var i = 0; i < els.length; i++) {
      var t = (els[i].textContent || '').trim();
      if (t === '×' || t === '✕' || t === '✖' || t === '⨯' || t === '✗') return true;
    }
    return false;
  }

  function closeOverlay(overlay, card) {
    // Prefer the dialog's own Cancel/Close/Done button so app state stays sane.
    var btns = card.querySelectorAll('button, a');
    for (var i = 0; i < btns.length; i++) {
      var t = (btns[i].textContent || '').trim().toLowerCase();
      if (t === 'cancel' || t === 'close' || t === 'done' || t === 'not now') { btns[i].click(); return; }
    }
    // Otherwise just remove the overlay node.
    if (overlay && overlay.parentNode) overlay.parentNode.removeChild(overlay);
  }

  function enhance(overlay) {
    if (overlay.__ktModal) return;
    overlay.__ktModal = 1;
    var card = findCard(overlay);
    if (!card) return;

    // (2) Containment — keep the dialog on-screen and scroll inside it.
    var ccs;
    try { ccs = getComputedStyle(card); } catch (e) { ccs = {}; }
    if (!ccs.maxHeight || ccs.maxHeight === 'none') card.style.maxHeight = '90vh';
    if (ccs.overflowY === 'visible' || !ccs.overflowY) card.style.overflowY = 'auto';
    if (ccs.position === 'static' || !ccs.position) card.style.position = 'relative';

    // (1) Close "×" — only if the dialog doesn't already have one.
    if (!hasClose(card)) {
      var x = doc.createElement('button');
      x.className = 'kt-modal-x';
      x.type = 'button';
      x.setAttribute('aria-label', 'Close');
      x.innerHTML = '×';
      x.style.cssText = 'position:absolute;top:10px;right:12px;z-index:10;width:30px;height:30px;padding:0;border:none;background:transparent;font-size:24px;line-height:1;color:#64748B;cursor:pointer;border-radius:6px;transition:background .12s,color .12s;';
      x.addEventListener('mouseenter', function () { x.style.background = '#F1F5F9'; x.style.color = '#111827'; });
      x.addEventListener('mouseleave', function () { x.style.background = 'transparent'; x.style.color = '#64748B'; });
      x.addEventListener('click', function (e) { e.stopPropagation(); closeOverlay(overlay, card); });
      card.appendChild(x);
    }
  }

  function check(node) {
    // Defer one tick so layout is measurable.
    setTimeout(function () { try { if (isOverlay(node)) enhance(node); } catch (e) {} }, 0);
  }

  function watch(container) {
    if (!container) return;
    try {
      new MutationObserver(function (muts) {
        for (var i = 0; i < muts.length; i++) {
          var added = muts[i].addedNodes;
          for (var j = 0; j < added.length; j++) {
            if (added[j].nodeType === 1) check(added[j]);
          }
        }
      }).observe(container, { childList: true });
    } catch (e) {}
  }

  function start() {
    watch(doc.body);
    watch(doc.getElementById('modalRoot'));
    // Catch any popups already on screen at load.
    var kids = doc.body ? doc.body.children : [];
    for (var i = 0; i < kids.length; i++) check(kids[i]);
  }

  if (doc.readyState === 'loading') doc.addEventListener('DOMContentLoaded', start);
  else start();
})(window);
