/* ═══════════════════════════════════════════════════════════════════
   KiddieTrac — FILTERS FOLD AWAY ON A PHONE (2026-10-27).
   Anthony: "with the audit log section in mobile view you added the filters as a drop down -
   pls make this the case throughout the mobile view - full sweep".
   The Audit log (2026-10-09) put its five filters behind a "Filters" button on a phone,
   because stacked full-width they took a whole screen before the first row. Every other
   list screen still shows its filter fields stacked. Rather than rebuild each screen, this
   recognises a FILTER BAR anywhere in #appMain on a phone and folds it behind the same kind
   of button (with a count of the filters that are set). Desktop is untouched.

   A FILTER BAR (all must hold):
     - 2+ filter controls: a search/text box, a dropdown or a date, in one block;
     - a list follows it: a table, or a block of repeated rows/cards, after it on the page;
     - it is taller than one row of fields on a phone (over 60px);
     - it is not a form that saves: no textarea, no Save / Submit / Send / Add / Create /
       Update / Upload / Import button inside it;
     - no required choice is still unmade ("Select a provider..." with nothing picked) - that
       pick is the screen's main control, not a filter;
     - not inside a popup, not the Audit log's own toolbar, not opted out with data-kt-nofold.
   A watcher (KT.observeMain) re-checks after each render; KT.phoneFilters.report() lists the
   bars folded on the current screen so a wrong one can be found and opted out.
   ═══════════════════════════════════════════════════════════════════ */
(function () {
  'use strict';
  if (window.__ktPhoneFilters) { return; }
  window.__ktPhoneFilters = true;

  var MQ = '(max-width:768px)';
  var FILTER_CTL = 'select, input[type="search"], input[type="text"], input[type="date"], input[type="month"], input[type="week"], input:not([type])';
  var SAVE_RX = /^\s*(\W\s*)?(save|submit|send|update|upload|import|post|publish|confirm|generate|run|charge|pay|refund|apply changes)\b/i;
  /* FILTER-LIKE (2026-10-27, after the first sweep folded the Phone dial pad, Usage & spend's
     rate settings, the Inspection checklist's pass/fail picks and Safe Arrival's pager): a bar
     folds only when EVERY field in it is a filter - a dropdown offering "All… / Any… /
     Everyone" or a "Sort… / This year… / Last…" pick, a search box, or a date. */
  var OPT_FILTER = /^\s*(\W\s*)?(all|any|every|everyone|sort|this |last |today|anytime|show all)\b/i;
  function filterLike(c) {
    if (c.tagName === 'SELECT') {
      var o = c.options;
      for (var i = 0; i < o.length && i < 40; i++) { if (OPT_FILTER.test(o[i].text || '')) { return true; } }
      // Rows-per-page (10 / 25 / 50): a list control, not a reason to leave the bar open.
      return o.length > 0 && [].every.call(o, function (x) { return /^\s*\d+\s*$/.test(x.text || ''); });
    }
    var type = (c.getAttribute('type') || 'text').toLowerCase();
    if (/^(date|month|week)$/.test(type)) { return true; }
    if (type === 'search') { return true; }
    return /search|filter|find|🔍/i.test((c.getAttribute('placeholder') || '') + ' ' + (c.getAttribute('aria-label') || ''));
  }
  var LIST_RX = /(^|[\s_-])(list|table|rows|cards|feed|grid|results|items)([\s_-]|$)/i;
  var openState = {};
  var skipped = [];   // per screen + bar index: stays open across a re-render

  function phone() { return !!(window.matchMedia && window.matchMedia(MQ).matches); }
  function css() {
    if (document.getElementById('kt-pf-css')) { return; }
    var st = document.createElement('style');
    st.id = 'kt-pf-css';
    st.textContent = '.kt-pf-toggle{display:none;}'
      + '@media ' + MQ + '{'
      +   '.kt-pf-toggle{display:flex;align-items:center;gap:8px;width:100%;box-sizing:border-box;margin:8px 0;padding:9px 12px;background:#fff;border:1px solid #E2E8F0;border-radius:10px;font-family:inherit;font-size:14px;font-weight:700;line-height:1.2;color:#0F172A;cursor:pointer;text-align:left;}'
      +   '.kt-pf-toggle .kt-pf-count{background:#1F6080;color:#fff;border-radius:999px;font-size:11.5px;padding:1px 8px;}'
      +   '.kt-pf-toggle .kt-pf-count:empty{display:none;}'
      +   '.kt-pf-toggle .kt-pf-chev{margin-left:auto;color:#64748B;transition:transform .15s;}'
      +   '.kt-pf-toggle[aria-expanded="true"] .kt-pf-chev{transform:rotate(180deg);}'
      +   '[data-kt-pf].kt-pf-shut .kt-pf-field{display:none !important;}'
      +   '[data-kt-pf].kt-pf-shut.kt-pf-all{display:none !important;}'
      +   '.kt-table-filter.kt-pf-emptied, .kt-list-controls.kt-pf-emptied{display:none !important;}'
      +   '[data-kt-pf] .kt-pf-dup{display:none !important;}'
      +   '[data-kt-pf] .kt-lc-right.kt-pf-field{display:flex !important;align-items:center;gap:8px;}'
      +   'html body #appMain [data-kt-pf] .kt-lc-right.kt-pf-field select.kt-lc-sort{flex:1 1 auto !important;width:auto !important;max-width:none !important;min-width:0 !important;}'
      +   '[data-kt-pf] .kt-pf-sortrow.kt-pf-field{display:flex !important;align-items:center;gap:8px;}'
      // Clear / Reset inside the open panel: its own size, at the end of the stack - not stretched full width.
      +   '[data-kt-pf] .kt-pf-clear.kt-pf-field.kt-pf-field{flex:0 0 auto !important;width:auto !important;align-self:flex-start;}'
      +   '[data-kt-pf] .kt-pf-chips.kt-pf-field.kt-pf-field{display:flex !important;flex-wrap:wrap !important;gap:6px;}'
      +   'html body #appMain [data-kt-pf] .kt-pf-sortrow select{flex:1 1 auto !important;width:auto !important;max-width:none !important;min-width:0 !important;}'
      /* OPEN, IT IS A TIDY STACK (2026-10-28): every field the full width of the panel, the sort
         dropdown beside its direction button, and the bar's own "Filter:" label hidden - the
         button above already says Filters. */
      +   '[data-kt-pf]:not(.kt-pf-shut){display:flex !important;flex-wrap:wrap !important;align-items:center !important;gap:8px !important;}'
      +   '[data-kt-pf] .kt-pf-field{flex:1 1 100% !important;width:100% !important;max-width:none !important;min-width:0 !important;margin:0 !important;box-sizing:border-box;}'
      +   '[data-kt-pf] .kt-pf-field:not(.kt-tf-sortwrap) select, [data-kt-pf] .kt-pf-field:not(.kt-tf-sortwrap) input:not([type=checkbox]):not([type=radio]), [data-kt-pf] select.kt-pf-field, [data-kt-pf] input.kt-pf-field{width:100% !important;max-width:none !important;box-sizing:border-box;}'
      +   '[data-kt-pf] .kt-pf-label{display:none !important;}'
      +   '[data-kt-pf] .kt-tf-sortwrap.kt-pf-field{display:flex !important;align-items:center;gap:8px;}'
      +   '[data-kt-pf] .kt-tf-sortwrap.kt-pf-field .kt-tf-sort{flex:1 1 auto !important;width:auto !important;max-width:none !important;min-width:0 !important;}'
      +   '[data-kt-pf] .kt-tf-sortwrap.kt-pf-field .kt-tf-dir{flex:0 0 auto !important;}'
      // A global field rule (html body #appMain select) out-ranks the lines above for dropdowns.
      +   'html body #appMain [data-kt-pf] select.kt-pf-field.kt-pf-field, html body #appMain [data-kt-pf] input.kt-pf-field.kt-pf-field, html body #appMain [data-kt-pf] .kt-pf-field.kt-pf-field:not(.kt-tf-sortwrap) select{flex:1 1 100% !important;width:100% !important;max-width:none !important;}'
      +   'html body #appMain [data-kt-pf] .kt-tf-sortwrap.kt-pf-field.kt-pf-field{flex:1 1 100% !important;width:100% !important;}'
      +   'html body #appMain [data-kt-pf] .kt-tf-sortwrap.kt-pf-field select.kt-tf-sort.kt-tf-sort{flex:1 1 auto !important;width:auto !important;max-width:none !important;}'
      + '}';
    (document.head || document.documentElement).appendChild(st);
  }
  function visible(el) { return !!(el.getClientRects().length && getComputedStyle(el).visibility !== 'hidden'); }
  function controlsIn(el) {
    return [].filter.call(el.querySelectorAll(FILTER_CTL), function (c) {
      return c.type !== 'hidden' && !c.closest('[data-kt-pf-skip]') && visible(c);
    });
  }
  function excluded(el, main) {
    if (el.closest('[data-kt-nofold], .kt-al-toolbar, .modal, [role=dialog], .kt-scrim, .kt-modal-overlay, .kt-tabband, [role=tablist]')) { return 'inside a popup, tab strip, Audit toolbar or data-kt-nofold'; }
    if (el === main || el.querySelector('textarea, .kt-tabband, [role=tablist], table, [role=row]')) { return 'holds a textarea, tab strip or table'; }
    if (/\bPage \d+ of \d+|Rows per page/i.test(el.textContent || '') || el.querySelector('[class*=pager], [class*=pagination]')) { return 'a pager'; }   // a pager
    if (el.querySelectorAll('button, [role=button]').length > 10) { return 'too many buttons (keypad / toolbar)'; }   // a keypad (the dial pad has 12+), not a filter bar
    var ctls = controlsIn(el);
    for (var f = 0; f < ctls.length; f++) { if (!filterLike(ctls[f])) { return 'a field that is not a filter'; } }
    var btns = el.querySelectorAll('button, [role=button], input[type=submit]');
    for (var i = 0; i < btns.length; i++) { if (SAVE_RX.test(btns[i].textContent || btns[i].value || '')) { return 'has a save / action button'; } }
    var sels = el.querySelectorAll('select');
    for (var j = 0; j < sels.length; j++) {
      var s = sels[j], first = s.options[0];
      if (s.value === '' && first && /^\s*(select|choose|pick)\b/i.test(first.text)) { return 'a required pick not made yet'; }   // a required pick, unmade
    }
    return false;
  }
  function listFollows(bar) {
    // Something list-like after the bar, within the next few siblings of the bar or its parents.
    for (var n = bar, depth = 0; n && depth < 3; n = n.parentElement, depth++) {
      for (var sib = n.nextElementSibling, k = 0; sib && k < 4; sib = sib.nextElementSibling, k++) {
        if (sib.matches('table, [data-kt-list], ul, ol') || sib.querySelector('table, [data-kt-list], tbody')) { return true; }
        if (LIST_RX.test(String(sib.className || '')) || LIST_RX.test(sib.id || '')) { return true; }
        // repeated cards: 3+ children of the same tag and class
        var kids = sib.children;
        if (kids.length >= 3 && kids[0].className && kids[0].className === kids[1].className && kids[1].className === kids[2].className) { return true; }
        if (/^\s*(loading|no\s)/i.test(sib.textContent || '')) { return true; }   // an empty / loading list
        // A card list of any length (2026-10-28: Reseller overview had one agency card, so "3 alike" missed it).
        if (kids.length >= 1 && /(^|[\s_-])[a-z]*-?(card|row|item)s?($|[\s_-])/i.test(String(kids[0].className || '')) && !/tile|stat/i.test(String(kids[0].className || ''))) { return true; }
      }
    }
    return false;
  }
  function activeCount(bar) {
    var n = 0;
    controlsIn(bar).forEach(function (c) {
      if (c.tagName === 'SELECT' && c.classList.contains('kt-tf-sort')) { return; }
      if (c.tagName === 'SELECT') { if (c.selectedIndex > 0 && c.value !== '' && !/^\s*all\b/i.test((c.options[c.selectedIndex] || {}).text || '')) { n++; } }
      else if ((c.value || '').trim()) { n++; }
    });
    return n;
  }
  function screenKey() { return String(location.hash || '#').split('?')[0]; }

  function fold(bar, idx) {
    bar.setAttribute('data-kt-pf', '1');
    // Each field block: the bar's child (or grandchild, for a bar wrapped in one row) that
    // holds a filter control. Anything else in the bar - buttons, a title - stays visible.
    controlsIn(bar).forEach(function (c) {
      // The largest block around the control that holds no button.
      var n = c;
      while (n.parentElement && n.parentElement !== bar && !n.parentElement.querySelector('button, [role=button]')) { n = n.parentElement; }
      n.classList.add('kt-pf-field');
    });
    markExtras(bar);
    var key = screenKey() + ':' + idx;
    var t = document.createElement('button');
    t.type = 'button';
    t.className = 'kt-pf-toggle';
    t.innerHTML = '<span aria-hidden="true">⚙️</span><span>Filters</span><span class="kt-pf-count"></span><span class="kt-pf-chev" aria-hidden="true">▾</span>';
    var open = !!openState[key];
    function paint() {
      t.setAttribute('aria-expanded', open ? 'true' : 'false');
      bar.classList.toggle('kt-pf-shut', !open);
      markExtras(bar);
      var c = activeCount(bar);
      t.querySelector('.kt-pf-count').textContent = c ? String(c) : '';
    }
    t.addEventListener('click', function () { open = !open; openState[key] = open; paint(); });
    bar.addEventListener('input', paint);
    bar.addEventListener('change', paint);
    bar.parentNode.insertBefore(t, bar);
    bar.__ktPfToggle = t;
    t.__ktPfBar = bar;
    bar.__ktPfPaint = paint;
    paint();
  }

  /* A label on its own ("Filter:", "Sort by", "Show") folds with the fields it names, the table
     sort control folds as one piece (select + direction button), and a bar left with nothing
     visible but folded parts is hidden outright while shut - no empty tinted strip. */
  var LABEL_RX = /^\s*(filters?|sort( by)?|show|search|view|refine)\s*:?\s*$/i;
  /* The bar's own Clear / Reset acts only on these filters, so it lives in the dropdown with them (2026-10-28:
     Email log / Email errors showed "Clear" beside a shut Filters button). Other buttons stay out, as before. */
  var CLEAR_RX = /^[^A-Za-z0-9]{0,3}\s*(clear|reset)(\s+(all|filters?|search|dates?))?\s*$/i;
  var SEARCHBTN_RX = /^\s*(🔍|🔎|search|go|apply)\s*$/i;   // submits the folded fields - it folds with them
  function markExtras(bar) {
    [].forEach.call(bar.querySelectorAll('button, a, [role=button]'), function (b) {
      if (b.classList.contains('kt-pf-toggle') || b.closest('.kt-pf-field') || b.closest('table, .kt-bulk-bar')) { return; }
      if (CLEAR_RX.test(b.textContent || '') || SEARCHBTN_RX.test(b.textContent || '')) { b.classList.add('kt-pf-field', 'kt-pf-clear'); }
    });
    // A row of choice chips with an "All" among them is a filter (period / status presets): it folds as one piece.
    [].forEach.call(bar.querySelectorAll('button'), function (b) {
      if (b.closest('.kt-pf-field') || !/^\s*all(\b|\d)/i.test(b.textContent || '')) { return; }   /* "All", or "All0": a count straight after */
      var g = b.parentElement;
      if (!g || g === bar || g.closest('table')) { return; }
      var onlyButtons = ![].some.call(g.children, function (k) { return k.tagName !== 'BUTTON'; });
      if (onlyButtons && g.children.length >= 1) {   /* a lone "All" (no categories yet) is still the filter */ g.classList.add('kt-pf-field', 'kt-pf-chips'); }
    });
    [].forEach.call(bar.querySelectorAll('.kt-tf-sortwrap, .kt-tf-left, .kt-lc-filter, .kt-lc-right'), function (w) {
      if (w.querySelector('select, input')) { w.classList.add('kt-pf-field'); }
    });
    [].forEach.call(bar.children, function (k) {
      if (k.classList.contains('kt-pf-field') || k.querySelector('button, select, input, a, [role=button]')) { return; }
      if (LABEL_RX.test(k.textContent || '')) { k.classList.add('kt-pf-field', 'kt-pf-label'); }
    });
    var rest = [].some.call(bar.children, function (k) {
      if (k.classList.contains('kt-pf-field') || k.classList.contains('kt-pf-toggle')) { return false; }
      if (/^(STYLE|SCRIPT|TEMPLATE)$/.test(k.tagName) || k.hidden) { return false; }
      return hasLooseContent(k);
    });
    // Loose text directly in the bar (a text node) also counts as content.
    var loose = [].some.call(bar.childNodes, function (n) { return n.nodeType === 3 && n.nodeValue.trim() && !LABEL_RX.test(n.nodeValue); });
    bar.classList.toggle('kt-pf-all', !rest && !loose);
  }
  function hasLooseContent(k) {
    if (!k.getClientRects().length) { return false; }   // hidden (a counter phones do not show)
    var c = k.cloneNode(true);
    [].forEach.call(c.querySelectorAll('.kt-pf-field'), function (f) { f.remove(); });
    return !!(c.textContent || '').trim() || !!c.querySelector('button, a, img, svg');
  }

  /* THE TABLE'S OWN SORT (AND SEARCH) GO IN THE DROPDOWN (2026-10-28, Anthony: "the sort by should
     be included in the filters drop down"). kt-table-filter puts Sort by + direction (and a search
     box) in a row directly above its table. When this screen folded a Filters bar for that table -
     the bar whose first following table it is - they move into that bar; otherwise the row folds
     behind a Filters button of its own. A moved control belongs to one table: when that table is
     replaced (a filter change re-renders the list) the stale control is removed and the new
     table's is moved in. */
  function tableOf(wrap, main) {
    var ts = main.getElementsByTagName('table');
    for (var i = 0; i < ts.length; i++) { if (ts[i].__ktFilterBar === wrap) { return ts[i]; } }
    return null;
  }
  function tableFilters(main) {
    [].forEach.call(main.querySelectorAll('[data-kt-pf-moved]'), function (el) {
      if (!el.__ktTable || !el.__ktTable.isConnected) { el.remove(); }
    });
    var order = [].slice.call(main.querySelectorAll('table, [data-kt-pf], [data-kt-listified]'));
    [].forEach.call(main.querySelectorAll('.kt-table-filter, .kt-list-controls'), function (wrap) {
      if (wrap.closest('[data-kt-pf]') || wrap.hasAttribute('data-kt-pf') || wrap.closest('[data-kt-nofold], .modal, [role=dialog], #modalRoot')) { return; }
      var isList = wrap.classList.contains('kt-list-controls');
      var parts = [].filter.call(wrap.querySelectorAll(isList ? '.kt-lc-filter, .kt-lc-right' : '.kt-tf-sortwrap, .kt-tf-left'), function (w) {
        var c = w.querySelector('select, input');
        return c && c.getClientRects().length;
      });
      if (!parts.length) { return; }
      var table = isList ? (wrap._ktFor && wrap._ktFor.isConnected ? wrap._ktFor : null) : tableOf(wrap, main);
      if (!table) { return; }
      var bar = null;
      for (var i = 0; i < order.length; i++) {
        if (order[i] === table) { break; }
        if (!order[i].hasAttribute('data-kt-pf')) { bar = null; } else { bar = order[i]; }
      }
      if (bar) {
        parts.forEach(function (w) {
          w.setAttribute('data-kt-pf-moved', '1');
          w.__ktTable = table;
          w.classList.add('kt-pf-field');
          bar.appendChild(w);
        });
        wrap.classList.add('kt-pf-emptied');
        if (bar.__ktPfPaint) { bar.__ktPfPaint(); }
        return;
      }
      fold(wrap, 'tf' + [].indexOf.call(main.querySelectorAll('.kt-table-filter, .kt-list-controls'), wrap));
    });
    sortRows(main);
  }

  /* A SCREEN'S OWN "Sort by" ROW (2026-10-28): a lone sort dropdown (its label or first choice says
     Sort, or it offers Newest / Oldest) above a list. It moves into the next Filters bar on the
     screen, and that bar's generic Sort... (A to Z) is hidden - the screen's own is the better one. */
  var SORT_RX = /^\s*(sort|order)\b/i, SORT_OPT_RX = /^\s*(newest|oldest|most recent|latest|a\s*(→|-|to)\s*z)/i;
  function sortRows(main) {
    [].forEach.call(main.querySelectorAll('select'), function (sel) {
      if (sel.closest('[data-kt-pf], .kt-table-filter, .kt-list-controls, [data-kt-nofold], .modal, [role=dialog]') || !sel.getClientRects().length) { return; }
      var first = (sel.options[0] || {}).text || '';
      var lab = sel.getAttribute('aria-label') || '';
      var prev = sel.previousElementSibling ? (sel.previousElementSibling.textContent || '') : '';
      var parentText = sel.parentElement ? (sel.parentElement.textContent || '').replace(first, '') : '';
      if (!(SORT_RX.test(first) || SORT_RX.test(lab) || SORT_RX.test(prev) || (/sort/i.test(parentText.slice(0, 20)) && SORT_OPT_RX.test(first)))) { return; }
      // The row: the largest block around it holding no other control and no button.
      var row = sel;
      while (row.parentElement && row.parentElement !== main && row.parentElement.querySelectorAll('select, input, textarea, button, [role=button]').length === 1
             && row.parentElement.getBoundingClientRect().height < 90) { row = row.parentElement; }
      // The next folded bar after it, with no list in between.
      var all = [].slice.call(main.querySelectorAll('table, [data-kt-pf], [data-kt-listified]'));
      var bar = null;
      for (var i = 0; i < all.length; i++) {
        if (!(row.compareDocumentPosition(all[i]) & Node.DOCUMENT_POSITION_FOLLOWING)) { continue; }
        if (all[i].hasAttribute('data-kt-pf')) { bar = all[i]; }
        break;
      }
      if (!bar) {
        /* No Filters bar on the screen (Reseller overview, 2026-10-28): the sort row folds behind a
           Filters button of its own, so a phone never shows a bare sort dropdown above a list. */
        if (row === main || row.hasAttribute('data-kt-pf') || !listFollows(row)) { return; }
        row.classList.add('kt-pf-sortrow');
        fold(row, 'sort' + [].indexOf.call(main.querySelectorAll('select'), sel));
        return;
      }
      row.classList.add('kt-pf-field', 'kt-pf-sortrow');
      bar.insertBefore(row, bar.firstChild);
      [].forEach.call(bar.querySelectorAll('.kt-lc-sort, .kt-tf-sort'), function (g) {
        var w = g.closest('.kt-tf-sortwrap') || g;
        w.classList.add('kt-pf-dup');
      });
      if (bar.__ktPfPaint) { bar.__ktPfPaint(); }
    });
  }

  function sweep() {
    if (!phone()) { return; }
    var main = document.getElementById('appMain');
    if (!main) { return; }
    css();
    // A toggle whose bar is gone goes with it.
    [].forEach.call(main.querySelectorAll('.kt-pf-toggle'), function (t) {
      var b = t.nextElementSibling;
      if (!b || !b.hasAttribute('data-kt-pf') || (t.__ktPfBar && b !== t.__ktPfBar)) {
        if (t.__ktPfBar && t.__ktPfBar !== b) { t.__ktPfBar.removeAttribute('data-kt-pf'); t.__ktPfBar.classList.remove('kt-pf-shut', 'kt-pf-all'); }
        t.remove();
      }
    });
    var seen = [];
    skipped = [];
    function skip(bar, why) { skipped.push({ why: why, text: (bar.textContent || '').replace(/\s+/g, ' ').trim().slice(0, 60) }); }
    controlsIn(main).forEach(function (c) {
      if (c.closest('[data-kt-pf]')) { return; }
      // The smallest block holding 2+ filter controls...
      var bar = null;
      for (var n = c.parentElement, d = 0; n && n !== main && d < 5; n = n.parentElement, d++) {
        if (controlsIn(n).length >= 2) { bar = n; break; }
      }
      if (!bar || seen.indexOf(bar) >= 0) { return; }
      seen.push(bar);
      // ...widened to its wrapper when the wrapper holds nothing more than it (labels, a title).
      var p = bar.parentElement;
      while (p && p !== main && controlsIn(p).length === controlsIn(bar).length && p.getBoundingClientRect().height < bar.getBoundingClientRect().height + 50 && !p.querySelector('table')) { bar = p; p = bar.parentElement; }
      if (bar.hasAttribute('data-kt-pf')) { return; }
      var ex = excluded(bar, main);
      if (ex) { skip(bar, ex); return; }
      if (bar.getBoundingClientRect().height <= 60) { skip(bar, 'fits on one line'); return; }
      if (controlsIn(bar).length > 10 || (bar.textContent || '').replace(/\s+/g, ' ').length > 1200) { skip(bar, 'too big to be a filter bar'); return; }
      if (!listFollows(bar)) { skip(bar, 'no list after it'); return; }
      fold(bar, seen.length);
    });
    try { tableFilters(main); } catch (e) {}
  }
  var pending = null;
  function schedule() { if (pending) { return; } pending = setTimeout(function () { pending = null; try { sweep(); } catch (e) {} }, 180); }
  function boot() {
    schedule();
    if (window.KT && KT.observeMain) { KT.observeMain(schedule, { childList: true, subtree: true }); }
    window.addEventListener('hashchange', schedule);
    try { window.matchMedia(MQ).addEventListener('change', schedule); } catch (e) {}
  }
  if (document.readyState === 'loading') { document.addEventListener('DOMContentLoaded', boot); } else { boot(); }

  window.KT = window.KT || {};
  window.KT.phoneFilters = {
    sweep: sweep,
    // Why each multi-field block on this screen was NOT folded (the watcher's own record).
    skipped: function () { return skipped; },
    report: function () {
      return [].map.call(document.querySelectorAll('#appMain [data-kt-pf]'), function (b) {
        return { screen: screenKey(), controls: controlsIn(b).length, text: (b.textContent || '').replace(/\s+/g, ' ').trim().slice(0, 80) };
      });
    },
  };
})();
