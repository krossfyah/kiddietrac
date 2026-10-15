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

  /* WHY THE SELECTOR IS A :not() AND NOT THE CLASS THIS FILE ADDS.

     The card layout used to be gated on .kt-mcards, which JavaScript adds — and this
     file runs on a 120ms debounce that every DOM mutation during a render resets. So on
     a phone the sequence was: paint a 675–1400px desktop table on a 412px screen, wait
     for the screen to stop changing, then restack it into cards. The reader watches the
     whole page reflow on every load. That is the "screens resize on initial load" on
     mobile, and it cannot happen on a desktop because the whole block is inside
     @media (max-width:600px).

     Inverted: the cards are the default at this width and JS marks the exceptions
     (.kt-nomc) instead of marking the takers. The browser now lays them out on the first
     paint, and the later pass only adds the column labels — text appearing inside a box
     that is already the right shape, rather than the shape changing underneath it.

     The exceptions are rare (a layout table with no header row, or a screen that built
     its own mobile view) and they now flicker instead of everything else. That is the
     right way round. */
  function injectStyle() {
    if (document.getElementById('kt-mobile-tables-style')) return;
    var s = document.createElement('style');
    s.id = 'kt-mobile-tables-style';
    s.textContent = [
      '@media (max-width:600px){',
      // The wrapper some screens add for horizontal scrolling is no longer needed.
      '  #appMain .kt-table-scroll, #appMain .table-wrap, #appMain .table-responsive{overflow-x:visible !important;}',
      '  #appMain table:not(.kt-nomc){display:block;width:100% !important;min-width:0 !important;border-collapse:separate;border-spacing:0;}',
      '  #appMain table:not(.kt-nomc) thead{display:none;}',
      '  #appMain table:not(.kt-nomc) tbody{display:block;width:100%;}',
      '  #appMain table:not(.kt-nomc) tr{display:block;width:100%;box-sizing:border-box;background:#fff;border:1px solid #E7EDF3;border-radius:14px;padding:12px 14px;margin:0 0 10px;box-shadow:0 1px 4px rgba(15,23,42,.05);}',
      '  #appMain table:not(.kt-nomc) tr:active{background:#F8FAFC;}',
      '  #appMain table:not(.kt-nomc) td{display:flex;align-items:flex-start;justify-content:space-between;gap:12px;width:auto !important;',
      '    border:none !important;padding:4px 0 !important;text-align:left !important;white-space:normal !important;font-size:13.5px;}',
      // Column header, shown as a dim label beside the value.
      '  #appMain table:not(.kt-nomc) td::before{content:attr(data-label);flex:0 0 auto;max-width:42%;color:#64748B;font-size:11px;font-weight:800;letter-spacing:.4px;text-transform:uppercase;padding-top:2px;}',
      /* ONE VALUE PER ROW (2026-10-09, Anthony: audit log "words spilling over to the next
         line"). The cell is a flex row, so every child of a cell became its own column
         beside the label: a name and its email, a label and its detail line, a place and
         its IP were squeezed side by side and broke mid-word ("Sign / ed a / form").
         restack() now gathers a multi-part cell into ONE .kt-mcv block, so the parts stack
         under each other on the value side the way they do on desktop. */
      /* !important throughout: css/kt-mobile-app.css restates the card rules with !important
         (#appMain table.kt-mcards td{display:flex !important;text-align:right !important}), so
         these win by being more specific AND important. */
      '  #appMain table:not(.kt-nomc) td > .kt-mcv{display:block !important;flex:0 1 auto !important;min-width:0 !important;text-align:right !important;overflow-wrap:break-word;}',
      '  #appMain table:not(.kt-nomc) td > .kt-mcv > div{max-width:none !important;}',
      /* A LONG value (a sentence, a summary, an address) gets the whole card width: the
         label sits above it instead of beside it, so it is not read through a slot. */
      '  #appMain table:not(.kt-nomc) td.kt-mcard-block:not(.kt-mcard-title){display:block !important;text-align:left !important;}',
      '  #appMain table:not(.kt-nomc) td.kt-mcard-block:not(.kt-mcard-title)::before{display:block !important;max-width:none !important;margin-bottom:3px !important;padding-top:0 !important;white-space:normal !important;}',
      '  #appMain table:not(.kt-nomc) td.kt-mcard-block > .kt-mcv, #appMain table:not(.kt-nomc) td.kt-mcard-title > .kt-mcv{text-align:left !important;}',
      '  #appMain table:not(.kt-nomc) td[data-label=""]::before{display:none;}',
      // First cell reads as the card title: no label, bigger, full width.
      '  #appMain table:not(.kt-nomc) td.kt-mcard-title{display:block;font-size:15px;font-weight:800;color:#0F172A;padding:0 0 6px !important;}',
      '  #appMain table:not(.kt-nomc) td.kt-mcard-title::before{display:none;}',
      '  #appMain table:not(.kt-nomc) td.kt-mcard-empty{display:none;}',
      // Value side of each row.
      '  #appMain table:not(.kt-nomc) td > *{min-width:0;}',
      '  #appMain table:not(.kt-nomc) td{color:#334155;}',
      /* CONDENSED CARDS (2026-10-15, Anthony: "the boxes are so big can you condense with
         repositioning the data"). One field per full-width line made a family 327px tall,
         two to a screen. Now: the title across the top, the select box and the ⋮ menu in
         the top-right corner, and short fields TWO PER ROW with a small label above each
         value; a long value or an email keeps the whole width (marked in restack()).
         table.kt-mcards:not(.kt-nomc) out-specifies kt-mobile-app.css's
         `#appMain table.kt-mcards td{display:flex !important}`. */
      '  #appMain table.kt-mcards:not(.kt-nomc) tr{display:grid !important;grid-template-columns:minmax(0,1fr) minmax(0,1fr);column-gap:14px;row-gap:7px;position:relative;padding:11px 13px 12px !important;margin-bottom:8px !important;}',
      '  #appMain table.kt-mcards:not(.kt-nomc) td{display:block !important;text-align:left !important;padding:0 !important;min-width:0;line-height:1.3 !important;font-size:13px !important;}',
      '  #appMain table.kt-mcards:not(.kt-nomc) td::before{display:block !important;max-width:none !important;font-size:9.5px !important;letter-spacing:.35px;margin:0 0 1px !important;padding:0 !important;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}',
      '  #appMain table.kt-mcards:not(.kt-nomc) td[data-label=""]::before{display:none !important;}',
      '  #appMain table.kt-mcards:not(.kt-nomc) td > .kt-mcv{text-align:left !important;}',
      '  #appMain table.kt-mcards:not(.kt-nomc) td.kt-mcard-title,#appMain table.kt-mcards:not(.kt-nomc) td.kt-mcard-block{grid-column:1 / -1;}',
      '  #appMain table.kt-mcards:not(.kt-nomc) td.kt-mcard-title{font-size:15px !important;padding:0 0 2px !important;}',
      '  #appMain table.kt-mcards:not(.kt-nomc) td.kt-mcard-title::before{display:none !important;}',
      '  #appMain table.kt-mcards:not(.kt-nomc) td.kt-mcard-empty{display:none !important;}',
      '  #appMain table.kt-mcards:not(.kt-nomc) td.kt-mcard-select,#appMain table.kt-mcards:not(.kt-nomc) td.kt-mcard-actions{position:absolute !important;top:9px;right:10px;width:auto !important;z-index:1;}',
      '  #appMain table.kt-mcards:not(.kt-nomc) td.kt-mcard-select::before,#appMain table.kt-mcards:not(.kt-nomc) td.kt-mcard-actions::before{display:none !important;}',
      '  #appMain table.kt-mcards:not(.kt-nomc) tr:has(> td.kt-mcard-select) > td.kt-mcard-actions{right:42px;}',
      '  #appMain table.kt-mcards:not(.kt-nomc) tr:has(> td.kt-mcard-select, > td.kt-mcard-actions) > td.kt-mcard-title{padding-right:40px !important;}',
      '  #appMain table.kt-mcards:not(.kt-nomc) tr:has(> td.kt-mcard-select):has(> td.kt-mcard-actions) > td.kt-mcard-title{padding-right:84px !important;}',
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
    // A cell holding nothing but a chevron is a desktop "click me" affordance —
    // on a phone the whole card is tappable, so it's just a stray line.
    var t = (td.textContent || '').trim();
    if (/^[→›»⟩>]$/.test(t)) return true;
    if (hasText(td)) return false;
    return !td.querySelector('img,button,a,svg,input');
  }

  /* See the .kt-mcv rule: a cell with more than one part (two divs, text plus a span…)
     gets them wrapped in a single block so they stack instead of sitting side by side.
     Cells holding controls are left exactly as built — the row-actions kebab and the
     screens' own handlers look for their buttons where they put them. A long value is
     marked to take the full card width. */
  var CONTROLS = 'button,input,select,textarea,a[href],[role="button"],[contenteditable="true"]';
  function gatherValue(td) {
    if (td.hasAttribute('data-kt-mc-keep') || td.querySelector(CONTROLS)) return;
    var parts = [].filter.call(td.childNodes, function (n) {
      return n.nodeType === 1 || (n.nodeType === 3 && n.nodeValue.trim() !== '');
    });
    if (parts.length > 1) {
      var box = document.createElement('div');
      box.className = 'kt-mcv';
      while (td.firstChild) box.appendChild(td.firstChild);
      td.appendChild(box);
    }
    if ((td.textContent || '').replace(/\s+/g, ' ').trim().length > 44) td.classList.add('kt-mcard-block');
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
        else gatherValue(td);
      });
      // The card's title is the first cell with actual TEXT in it. Tables lead
      // with a checkbox/spacer column, and titling that gave every card an empty
      // heading followed by a list of "LABEL: value" rows — exactly the table we
      // were trying to get away from.
      var TITLE_LABELS = /child|name|title|announcement|subject|from|staff|user|family/i;
      var titled = false;
      for (var i = 0; i < cells.length; i++) {
        var lbl = cells[i].getAttribute('data-label') || '';
        if (TITLE_LABELS.test(lbl) && hasText(cells[i])) {
          cells[i].classList.add('kt-mcard-title');
          titled = true;
          break;
        }
      }
      if (!titled) {
        for (var j = 0; j < cells.length; j++) {
          if (hasText(cells[j])) { cells[j].classList.add('kt-mcard-title'); break; }
        }
      }
      // Condensed layout (see the stylesheet): controls to the corner, wide values full width.
      /* Only BUTTONS count as controls. A cell whose value is a link (an email, a file
         name) is data and stays in the grid; and only the LAST button-only cell goes to
         the corner, so two never land on the same spot. */
      var lastActions = null, selectDone = false;
      cells.forEach(function (td) {
        if (/\bkt-mcard-(title|empty)\b/.test(td.className)) return;
        var bare = td.cloneNode(true);
        [].forEach.call(bare.querySelectorAll('button,[role="button"],input,select,label'), function (n) { n.remove(); });
        if ((bare.textContent || '').trim() === '') {
          var box = td.querySelector('input[type="checkbox"]');
          if (box && !selectDone && !td.querySelector('button,[role="button"],select')) { td.classList.add('kt-mcard-select'); selectDone = true; }
          else if (td.querySelector('button,[role="button"]')) { lastActions = td; }
          return;
        }
        var t = (td.textContent || '').replace(/\s+/g, ' ').trim();
        if (t.length > 26 || /\S+@\S+\.\S+/.test(t)) td.classList.add('kt-mcard-block');
      });
      if (lastActions) lastActions.classList.add('kt-mcard-actions');
    });
  }

  // ── Lift the action button out of the page banner ────────────────────
  // .page-header-v17 puts its primary action ("+ New observation") INSIDE the
  // banner. On a phone that makes the banner ~90-160px tall, against the ~36px
  // title bar every other section shows — the same screen, two different
  // headers. Move the actions row out and drop it directly below the banner:
  // the banner collapses to a title, and the button becomes a full-width tap
  // target where a thumb actually is.
  function liftBannerActions() {
    var main = document.getElementById('appMain');
    if (!main) return;

    [].forEach.call(main.querySelectorAll('.page-header-v17'), function (banner) {
      var actions = banner.querySelector(':scope > .actions');
      if (!actions || actions.__ktLifted) return;

      // Desktop keeps the button where it was — this is a phone-only rearrangement.
      if (!isPhone()) return;

      actions.__ktLifted = true;
      actions.classList.add('kt-lifted-actions');
      banner.insertAdjacentElement('afterend', actions);
    });
  }

  function apply() {
    if (!isPhone()) return;
    var main = document.getElementById('appMain');
    if (!main) return;
    liftBannerActions();
    [].forEach.call(main.querySelectorAll('table'), function (t) {
      /* Leave a table alone if a screen has already built its own mobile view, or if it
         is a layout table with no header row — and SAY SO on the element, because the
         stylesheet now applies the card layout unless told otherwise. */
      if (t.closest('.kt-no-mcards') || !labelsOf(t) || !labelsOf(t).length) {
        t.classList.add('kt-nomc');
        return;
      }
      t.classList.remove('kt-nomc');
      restack(t);
    });
  }

  var pending = null;
  function schedule() { clearTimeout(pending); pending = setTimeout(apply, 120); }

  // Screens render (and re-render) asynchronously, so watch #appMain rather than
  // trying to hook every screen's render.
  function boot() {
    /* BEFORE anything renders. This used to be called from apply(), which is behind the
       debounce — so the rules themselves arrived after the table they were meant to
       shape. Injected once, up front, and the media query decides whether it does
       anything. */
    injectStyle();
    apply();
    var main = document.getElementById('appMain');
    if (main && window.MutationObserver) {
      /* Re-binds when the shell swaps #appMain (kt:main-swapped); a MutationObserver follows a NODE, and this one used to die silently at the first render. */
      if (window.KT && KT.observeMain) { KT.observeMain(schedule, { childList: true, subtree: true }); }
      else { new MutationObserver(schedule).observe(main, { childList: true, subtree: true }); }
    }
    window.addEventListener('hashchange', schedule);
    if (window.matchMedia) {
      try { window.matchMedia(MQ).addEventListener('change', schedule); } catch (e) {}
    }
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot);
  else boot();
})();
