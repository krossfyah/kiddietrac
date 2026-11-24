/* ═══════════════════════════════════════════════════════════════════
   KiddieTrac — A POPUP KEEPS ITS SIZE WHEN YOU SWITCH TABS (2026-10-23).

   Anthony: "when I edit agency the popup window resizes when I move from one subtab to
   the other - fix this behaviour throughout the portal - full sweep".

   THE MECHANISM. A tabbed popup shows one pane and hides the rest, and the popup is sized
   by whatever is showing. Edit agency's Details tab is tall, Address is short, so the box
   jumps on every click; the same in every tabbed dialog built the same way (Shell.Modal
   dialogs that re-render their body per tab, and the ~150 hand-rolled overlays). A few
   dialogs were fixed one by one with a fixed-height scroll pane (kt-modal-fitpane.js);
   fixing them one by one is how the next one ships broken. So this is one rule for all:

     1. When a popup opens, panes that sit side by side ([data-…pane], [data-…panel],
        [role=tabpanel] siblings) are measured, hidden ones included, and their container
        gets the tallest one's height as its minimum. The box is the right size from the
        first frame, and switching never moves it.
     2. For popups that build a tab's content only when it is clicked (nothing to measure
        in advance): when a tab is clicked, the box's current size becomes its floor, so
        it can grow to fit a bigger tab but never shrinks back while it stays open.

   A floor never exceeds the popup's own max-height (the box still scrolls inside), and
   it is dropped on window resize so a rotated phone is measured afresh.
   ═══════════════════════════════════════════════════════════════════ */
(function () {
  'use strict';
  if (window.__ktSteadyDialog) return;
  window.__ktSteadyDialog = true;

  var TAB_ATTR = /^data-(?:[a-z0-9]+-)*[a-z0-9]*tabs?$/;       // data-tab, data-eatab, data-sv-tab, data-sub-tab…
  var PANE_ATTR = /^data-(?:[a-z0-9]+-)*[a-z0-9]*(?:pane|panel)$/;
  var TAB_CLASS = /(^|\s)(kt-)?(sub)?tabs?(-btn|-button|-item|-link)?(\s|$)|(^|\s)[a-z-]*subtab[a-z-]*(\s|$)/;

  function isOverlay(el) {
    if (!el || el.nodeType !== 1 || el === document.body) return false;
    var cs = getComputedStyle(el);
    if (cs.position !== 'fixed') return false;
    var r = el.getBoundingClientRect();
    // The page scrollbar makes a full-screen overlay ~15px narrower than innerWidth.
    var de = document.documentElement;
    return r.width >= Math.min(window.innerWidth, de.clientWidth) - 24 && r.height >= Math.min(window.innerHeight, de.clientHeight) - 24;
  }

  /* The popup box: Shell.Modal's .modal, a [role=dialog], or the first sizable child of a
     full-screen fixed overlay. */
  function boxOf(node) {
    for (var el = node; el && el !== document.body; el = el.parentElement) {
      if (el.classList && el.classList.contains('modal') && !el.classList.contains('modal-body')) return el;
      if (el.getAttribute && el.getAttribute('role') === 'dialog') return el;
      if (el.parentElement && isOverlay(el.parentElement) && el.getBoundingClientRect().width < el.parentElement.getBoundingClientRect().width - 4) return el;
    }
    return null;
  }

  function hasAttr(el, re) {
    if (!el.attributes) return null;
    for (var i = 0; i < el.attributes.length; i++) { var n = el.attributes[i].name; if (re.test(n) && n.indexOf('table') < 0) return n; }
    return null;
  }

  function isTab(el, box) {
    for (var e = el; e && e !== box; e = e.parentElement) {
      if (e.getAttribute('role') === 'tab') return true;
      if (hasAttr(e, TAB_ATTR)) return true;
      if (typeof e.className === 'string' && TAB_CLASS.test(e.className) && e.className.indexOf('table') < 0) return true;
      var p = e.parentElement;
      if (p && (p.getAttribute('role') === 'tablist' || p.hasAttribute('data-kt-tabbar') || p.hasAttribute('data-kt-tabbed'))) return true;
    }
    return false;
  }

  function maxH(box) {
    var mh = getComputedStyle(box).maxHeight;
    var v = mh && mh !== 'none' ? parseFloat(mh) : NaN;
    return isNaN(v) ? window.innerHeight - 16 : Math.min(v, window.innerHeight - 8);
  }

  /* 1. Measure side-by-side panes up front. */
  function steadyPanes(box) {
    var groups = new Map();
    box.querySelectorAll('*').forEach(function (el) {
      if (el.getAttribute('role') === 'tabpanel' || hasAttr(el, PANE_ATTR)) {
        var p = el.parentElement;
        if (!p) return;
        if (!groups.has(p)) groups.set(p, []);
        groups.get(p).push(el);
      }
    });
    groups.forEach(function (panes, parent) {
      if (panes.length < 2 || parent.dataset.ktSteady) return;
      var tallest = 0;
      panes.forEach(function (pn) {
        var wasHidden = pn.hidden, disp = pn.style.display, vis = pn.style.visibility;
        if (wasHidden || getComputedStyle(pn).display === 'none') {
          pn.hidden = false; pn.style.display = 'block'; pn.style.visibility = 'hidden';
        }
        tallest = Math.max(tallest, pn.getBoundingClientRect().height);
        pn.hidden = wasHidden; pn.style.display = disp; pn.style.visibility = vis;
      });
      if (tallest > 0) {
        parent.dataset.ktSteady = '1';
        parent.style.minHeight = Math.ceil(tallest) + 'px';
      }
    });
  }

  /* 2. Never shrink after a tab click. */
  function floor(box) {
    var r = box.getBoundingClientRect();
    if (!r.height) return;
    var h = Math.min(Math.ceil(r.height), maxH(box));
    var cur = parseFloat(box.style.minHeight) || 0;
    if (h > cur) { box.style.minHeight = h + 'px'; box.dataset.ktFloor = '1'; }
    var w = Math.min(Math.ceil(r.width), window.innerWidth - 16);
    var curW = parseFloat(box.style.minWidth) || 0;
    if (w > curW) { box.style.minWidth = w + 'px'; box.dataset.ktFloor = '1'; }
  }

  document.addEventListener('click', function (e) {
    var box = boxOf(e.target);
    if (!box || !isTab(e.target, box)) return;
    /* A panel the person sizes and minimises themselves (the dial pad) is not floored: its
       min-width/min-height outranked the minimised pill and left a 350x564 white circle
       (2026-10-23). data-kt-nosteady opts out. */
    if (box.closest('[data-kt-nosteady]')) return;
    floor(box);
    // Panes painted only now (lazy tabs) get measured on the next frame too.
    (window.requestAnimationFrame || setTimeout)(function () { try { steadyPanes(box); } catch (er) {} });
  }, true);

  // New popups: measure their panes once their content is in.
  var seen = new WeakSet();
  function scan() {
    var cands = document.querySelectorAll('.modal, [role=dialog]');
    [].forEach.call(document.body.children, function (c) { if (isOverlay(c) && c.firstElementChild) cands = [].concat([].slice.call(cands), [c.firstElementChild]); });
    [].forEach.call(cands, function (box) {
      if (seen.has(box)) return;
      if (!box.querySelector('[role=tab],[role=tablist],[data-kt-tabbar]') && !hasTabAttr(box)) return;
      seen.add(box);
      setTimeout(function () { try { steadyPanes(box); } catch (er) {} }, 60);
    });
  }
  function hasTabAttr(box) {
    var all = box.querySelectorAll('button,a,[tabindex],li,span,div');
    for (var i = 0; i < all.length && i < 400; i++) if (hasAttr(all[i], TAB_ATTR)) return true;
    return false;
  }
  var pending = false;
  function soon() { if (pending) return; pending = true; setTimeout(function () { pending = false; try { scan(); } catch (er) {} }, 30); }
  if (window.MutationObserver) new MutationObserver(soon).observe(document.body, { childList: true, subtree: false });
  (function watchRoot() {
    var root = document.getElementById('modalRoot');
    if (!root) { setTimeout(watchRoot, 400); return; }
    if (window.MutationObserver) new MutationObserver(soon).observe(root, { childList: true });
  })();

  // A rotated phone or a resized window: measure again from scratch.
  window.addEventListener('resize', function () {
    document.querySelectorAll('[data-kt-floor]').forEach(function (b) { b.style.minHeight = ''; b.style.minWidth = ''; delete b.dataset.ktFloor; });
    document.querySelectorAll('[data-kt-steady]').forEach(function (p) { p.style.minHeight = ''; delete p.dataset.ktSteady; });
    seen = new WeakSet(); soon();
  });

  window.KT = window.KT || {};
  window.KT.steadyDialogs = scan;
})();
