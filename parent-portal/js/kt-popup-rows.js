/* ═══════════════════════════════════════════════════════════════════
   KiddieTrac — FIELD ROWS IN POP-UPS, ON A PHONE (2026-10-28).
   Anthony (screenshot of "Add a new family"): "look at the fields not aligned properly in
   mobile view".

   Pop-ups lay address-type fields three or four across (City 2fr | Province / State 1fr |
   Postal / ZIP 1fr). On a 390px phone each slot is ~100px, so the labels wrap onto two
   lines ("Province / / State *", "Postal / ZIP / *") while "City *" stays on one - the
   three boxes then sit at three different heights, and each is too narrow to read.

   Any row inside a pop-up whose children are 3+ side-by-side fields (one input / select
   each, no buttons) becomes two columns at <=600px: the first field takes the whole row
   when the count is odd (City), the rest pair up (Province | Postal), and boxes in a row
   line up at the bottom whatever their labels do. Desktop is untouched. Opt out with
   data-kt-noprow on the row or the pop-up.
   ═══════════════════════════════════════════════════════════════════ */
(function () {
  'use strict';
  if (window.__ktPopupRows) { return; }
  window.__ktPopupRows = true;

  var MQ = '(max-width:600px)';
  var POPUPS = '#modalRoot, [role="dialog"], [aria-modal="true"], .modal, .kt-modal, .kt-scrim, .kt-modal-overlay';
  var FIELD = 'input:not([type=hidden]):not([type=checkbox]):not([type=radio]):not([type=button]):not([type=submit]), select, textarea';

  function css() {
    if (document.getElementById('kt-prow-css')) { return; }
    var st = document.createElement('style');
    st.id = 'kt-prow-css';
    st.textContent = '@media ' + MQ + '{'
      + '[data-kt-prow]{display:grid !important;grid-template-columns:minmax(0,1fr) minmax(0,1fr) !important;column-gap:12px !important;row-gap:0 !important;align-items:end !important;flex-wrap:nowrap !important;}'
      + '[data-kt-prow] > *{min-width:0 !important;width:auto !important;max-width:none !important;flex:none !important;grid-column:auto !important;}'
      + '[data-kt-prow="odd"] > :first-child{grid-column:1 / -1 !important;}'
      + '[data-kt-prow] > * input, [data-kt-prow] > * select, [data-kt-prow] > * textarea{width:100% !important;max-width:none !important;box-sizing:border-box;}'
      + '}';
    (document.head || document.documentElement).appendChild(st);
  }
  function phone() { return !!(window.matchMedia && window.matchMedia(MQ).matches); }

  function isFieldCell(k) {
    if (!k.getClientRects().length) { return false; }
    var f = k.matches(FIELD) ? [k] : k.querySelectorAll(FIELD);
    if (f.length !== 1) { return false; }
    return !k.querySelector('button, [role=button]');
  }
  function check(row) {
    if (row.hasAttribute('data-kt-prow') || row.closest('[data-kt-noprow]')) { return; }
    var kids = [].filter.call(row.children, function (k) { return !/^(STYLE|SCRIPT|TEMPLATE)$/.test(k.tagName) && k.getClientRects().length; });
    if (kids.length < 3 || kids.length > 5) { return; }
    for (var i = 0; i < kids.length; i++) { if (!isFieldCell(kids[i])) { return; } }
    var cs = getComputedStyle(row);
    if (!/grid|flex/.test(cs.display) || /column/.test(cs.flexDirection || '')) { return; }
    // Side by side now: the first three start on one line.
    var t0 = kids[0].getBoundingClientRect().top;
    if (Math.abs(kids[1].getBoundingClientRect().top - t0) > 4 || Math.abs(kids[2].getBoundingClientRect().top - t0) > 4) { return; }
    row.setAttribute('data-kt-prow', kids.length % 2 ? 'odd' : 'even');
  }
  function sweep() {
    if (!phone()) { return; }
    var pops = document.querySelectorAll(POPUPS);
    if (!pops.length) { return; }
    css();
    [].forEach.call(pops, function (p) {
      if (p.closest('[data-kt-noprow]') || !p.getClientRects().length) { return; }
      // Rows = parents of field cells; check each parent once.
      var seen = [];
      [].forEach.call(p.querySelectorAll(FIELD), function (f) {
        for (var n = f.parentElement, d = 0; n && n !== p && d < 4; n = n.parentElement, d++) {
          var row = n.parentElement;
          if (!row || seen.indexOf(row) !== -1) { continue; }
          seen.push(row);
          try { check(row); } catch (e) {}
        }
      });
    });
  }
  var raf = 0;
  function schedule() {
    if (raf) { return; }
    raf = (window.requestAnimationFrame || setTimeout)(function () { raf = 0; try { sweep(); } catch (e) {} });
  }
  function boot() {
    css();
    schedule();
    try { new MutationObserver(function (list) {
      // Only when something was added (pop-ups opening or filling in), not on every attribute flip.
      for (var i = 0; i < list.length; i++) { if (list[i].addedNodes.length) { schedule(); return; } }
    }).observe(document.body, { childList: true, subtree: true }); } catch (e) {}
    try { window.matchMedia(MQ).addEventListener('change', schedule); } catch (e) {}
  }
  if (document.readyState === 'loading') { document.addEventListener('DOMContentLoaded', boot); } else { boot(); }
  window.KT = window.KT || {};
  window.KT.popupRows = { sweep: sweep };
})();
