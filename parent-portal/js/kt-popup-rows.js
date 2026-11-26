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
   line up at the bottom whatever their labels do. Desktop keeps its columns (see alignLines for the one rule on every width). Opt out with
   data-kt-noprow on the row or the pop-up.
   ═══════════════════════════════════════════════════════════════════ */
(function () {
  'use strict';
  if (window.__ktPopupRows) { return; }
  window.__ktPopupRows = true;

  var MQ = '(max-width:600px)';
  /* [data-kt-dlg] = any popup kt-modal-guard dresses (2026-11-26): Edit agency, a plain <div> overlay, matched none
     of the others, so its City | State | ZIP row stayed three across on a phone with ZIP pushed down a line. */
  var POPUPS = '#modalRoot, [role="dialog"], [aria-modal="true"], [data-kt-dlg], .modal, .kt-modal, .kt-scrim, .kt-modal-overlay';
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
  /* FIELDS SIDE BY SIDE SHARE A LINE, ON EVERY WIDTH (2026-11-26, Anthony: "the pop up prompts are not all fully
     aligned correctly - full sweep"). In a two-across row where one label wraps to two lines ("Username (optional -
     lets one email have several accounts)" beside "Email *" in Invite user), the wrapped side's box sat 20px lower than
     its neighbour. Per visual line of a grid or flex row whose cells are each "label, then its one field": every label
     on the line gets the tallest label's height as its min-height, so the boxes start on one line and the words stay
     at the top. Whatever sits under a field (a hint, a status line) is untouched. Re-measured on every pass and on
     resize: our own min-height is cleared first, so a label that stops wrapping lets its neighbours shrink back. */
  function labelOf(cell, f) {
    if (f.parentElement !== cell) { return null; }
    var lab = f.previousElementSibling;
    if (!lab || lab !== cell.firstElementChild || !/^(LABEL|DIV|SPAN|P)$/.test(lab.tagName) || lab.querySelector(FIELD)) { return null; }
    return lab;
  }
  function alignLines(row) {
    if (row.closest('[data-kt-noprow]')) { return; }
    var cs = getComputedStyle(row);
    if (!/grid|flex/.test(cs.display) || /column/.test(cs.flexDirection || '')) { return; }
    var kids = [].filter.call(row.children, function (k) { return !/^(STYLE|SCRIPT|TEMPLATE)$/.test(k.tagName) && k.getClientRects().length; });
    if (kids.length < 2) { return; }
    var labs = [];
    kids.forEach(function (k) {
      if (!isFieldCell(k)) { return; }
      var f = k.matches(FIELD) ? k : k.querySelector(FIELD);
      var lab = f && f !== k ? labelOf(k, f) : null;
      if (lab) { labs.push({ cell: k, lab: lab }); }
    });
    if (labs.length < 2) { return; }
    labs.forEach(function (x) { if (x.lab.hasAttribute('data-kt-lblh')) { x.lab.style.minHeight = ''; x.lab.removeAttribute('data-kt-lblh'); } });
    var lines = [];
    labs.forEach(function (x) {
      var t = x.cell.getBoundingClientRect().top;
      var line = null;
      for (var i = 0; i < lines.length; i++) { if (Math.abs(lines[i].top - t) <= 4) { line = lines[i]; break; } }
      if (!line) { line = { top: t, items: [] }; lines.push(line); }
      line.items.push(x);
    });
    lines.forEach(function (line) {
      if (line.items.length < 2) { return; }
      var hs = line.items.map(function (x) { return x.lab.getBoundingClientRect().height; });
      var max = Math.max.apply(null, hs);
      if (max - Math.min.apply(null, hs) <= 2) { return; }
      line.items.forEach(function (x) { x.lab.style.minHeight = Math.ceil(max) + 'px'; x.lab.setAttribute('data-kt-lblh', '1'); });
    });
  }
  function sweep() {
    var pops = document.querySelectorAll(POPUPS);
    if (!pops.length) { return; }
    var isPhone = phone();
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
          if (isPhone) { try { check(row); } catch (e) {} }
          try { alignLines(row); } catch (e) {}
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
    // A tab in a popup shows a pane that was hidden (nothing is added): look again once it has drawn.
    document.addEventListener('click', function () { setTimeout(schedule, 80); }, true);
    window.addEventListener('resize', schedule);
  }
  if (document.readyState === 'loading') { document.addEventListener('DOMContentLoaded', boot); } else { boot(); }
  window.KT = window.KT || {};
  window.KT.popupRows = { sweep: sweep };
})();
