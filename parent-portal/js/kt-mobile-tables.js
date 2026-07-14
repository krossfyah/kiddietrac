/* ═══════════════════════════════════════════════════════════════════
   KiddieTrac — tables → cards on phones (2026-07-13).

   Alerts (#announcements) and Messages (#chat) — and most staff screens —
   render a desktop <table>. On a 412px phone that table is 675–1400px wide, so
   the user side-scrolls through "Sent to / Channels / Date ▾" columns to read a
   single row. That's the "alerts and messages don't show properly on mobile".

   Rather than rewrite each screen, this restacks any table inside #appMain into
   one card per row on ≤600px: each cell keeps its column header as a label, the
   first cell becomes the card's title, and header-less/empty cells are dropped.
   CSS alone can't do it (the labels live in <thead>), so we copy the headers
   onto each cell as data-label and let the stylesheet do the rest.

   Rows keep their click handlers — we never move or clone nodes, only add
   attributes — so "tap a row to read it" still works.
   ═══════════════════════════════════════════════════════════════════ */
(function () {
  'use strict';
  if (window.__ktMobileTables) return; window.__ktMobileTables = true;

  var MQ = '(max-width:600px)';
  function isPhone() { return window.matchMedia && window.matchMedia(MQ).matches; }

  function injectStyle() {
    if (document.getElementById('kt-mobile-tables-style')) return;
    var s = document.createElement('style');
    s.id = 'kt-mobile-tables-style';
    s.textContent = [
      '@media (max-width:600px){',
      // The wrapper some screens add for horizontal scrolling is no longer needed.
      '  #appMain .kt-table-scroll, #appMain .table-wrap, #appMain .table-responsive{overflow-x:visible !important;}',
      '  #appMain table.kt-mcards{display:block;width:100% !important;min-width:0 !important;border-collapse:separate;border-spacing:0;}',
      '  #appMain table.kt-mcards thead{display:none;}',
      '  #appMain table.kt-mcards tbody{display:block;width:100%;}',
      '  #appMain table.kt-mcards tr{display:block;width:100%;box-sizing:border-box;background:#fff;border:1px solid #E7EDF3;border-radius:14px;padding:12px 14px;margin:0 0 10px;box-shadow:0 1px 4px rgba(15,23,42,.05);}',
      '  #appMain table.kt-mcards tr:active{background:#F8FAFC;}',
      '  #appMain table.kt-mcards td{display:flex;align-items:flex-start;justify-content:space-between;gap:12px;width:auto !important;',
      '    border:none !important;padding:4px 0 !important;text-align:left !important;white-space:normal !important;font-size:13.5px;}',
      // Column header, shown as a dim label beside the value.
      '  #appMain table.kt-mcards td::before{content:attr(data-label);flex:0 0 auto;color:#94A3B8;font-size:11px;font-weight:800;letter-spacing:.4px;text-transform:uppercase;padding-top:2px;}',
      '  #appMain table.kt-mcards td[data-label=""]::before{display:none;}',
      // First cell reads as the card title: no label, bigger, full width.
      '  #appMain table.kt-mcards td.kt-mcard-title{display:block;font-size:15px;font-weight:800;color:#0F172A;padding:0 0 6px !important;}',
      '  #appMain table.kt-mcards td.kt-mcard-title::before{display:none;}',
      '  #appMain table.kt-mcards td.kt-mcard-empty{display:none;}',
      // Value side of each row.
      '  #appMain table.kt-mcards td > *{min-width:0;}',
      '  #appMain table.kt-mcards td{color:#334155;}',
      '}',
    ].join('\n');
    document.head.appendChild(s);
  }

  function labelsOf(table) {
    var head = table.querySelector('thead tr');
    if (!head) return null;
    return [].map.call(head.children, function (th) {
      // Strip the sort arrows headers carry ("Date ▾", "Sent to ⇅") — they're
      // controls for a table that no longer exists at this width.
      return (th.textContent || '').replace(/[▾▴▲▼↑↓⇅⇵↕]/g, '').trim();
    });
  }

  // Does this cell carry readable text (rather than being empty, a placeholder
  // dash, or a bare checkbox / icon button)?
  function hasText(td) {
    var t = (td.textContent || '').trim();
    return t !== '' && t !== '—' && t !== '-' && t !== '–';
  }
  // A cell with no text AND nothing worth showing is a blank line in a card.
  function isBlank(td) {
    if (hasText(td)) return false;
    return !td.querySelector('img,button,a,svg,input');
  }

  function restack(table) {
    var labels = labelsOf(table);
    if (!labels || !labels.length) return;
    table.classList.add('kt-mcards');
    [].forEach.call(table.querySelectorAll('tbody tr'), function (tr) {
      if (tr.__ktCards) return;                        // already done
      tr.__ktCards = true;
      var cells = [].slice.call(tr.children);
      cells.forEach(function (td, i) {
        td.setAttribute('data-label', labels[i] == null ? '' : labels[i]);
        if (isBlank(td)) td.classList.add('kt-mcard-empty');
      });
      // The card's title is the first cell with actual TEXT in it. Tables lead
      // with a checkbox/spacer column, and titling that gave every card an empty
      // heading followed by a list of "LABEL: value" rows — exactly the table we
      // were trying to get away from.
      for (var i = 0; i < cells.length; i++) {
        if (hasText(cells[i])) { cells[i].classList.add('kt-mcard-title'); break; }
      }
    });
  }

  function apply() {
    if (!isPhone()) return;
    var main = document.getElementById('appMain');
    if (!main) return;
    injectStyle();
    [].forEach.call(main.querySelectorAll('table'), function (t) {
      // Leave a table alone if a screen has already built its own mobile view,
      // or if it's a layout table with no header row.
      if (t.closest('.kt-no-mcards')) return;
      restack(t);
    });
  }

  var pending = null;
  function schedule() { clearTimeout(pending); pending = setTimeout(apply, 120); }

  // Screens render (and re-render) asynchronously, so watch #appMain rather than
  // trying to hook every screen's render.
  function boot() {
    apply();
    var main = document.getElementById('appMain');
    if (main && window.MutationObserver) {
      new MutationObserver(schedule).observe(main, { childList: true, subtree: true });
    }
    window.addEventListener('hashchange', schedule);
    if (window.matchMedia) {
      try { window.matchMedia(MQ).addEventListener('change', schedule); } catch (e) {}
    }
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot);
  else boot();
})();
