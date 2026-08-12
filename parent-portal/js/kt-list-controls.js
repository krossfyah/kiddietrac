/* ═══════════════════════════════════════════════════════════════════
   KiddieTrac — universal CARD-LIST search + sort (audit Phase 2).
   Companion to kt-table-filter.js/kt-polish.js (which handle <table>s).
   Card/list screens render div rows, which those two can't touch — so
   this adds the same search + sort UX to any container marked opt-in.

   OPT-IN (per screen, on the container whose DIRECT CHILDREN are the cards):
     data-kt-list                         → search box + A–Z / Z–A sort
     data-kt-sort="amount:Amount:num,      → extra sort options in the dropdown;
                   due:Due date:date,        each reads `data-sort-<key>` off a card
                   status:Status"            (types: text | num | date)
   Cards may carry `data-sort-<key>="…"`; without them the A–Z/Z–A sort still
   works off each card's primary heading text.
   ═══════════════════════════════════════════════════════════════════ */
(function (window) {
  'use strict';
  if (window.KT_LIST_CONTROLS_LOADED) return;
  window.KT_LIST_CONTROLS_LOADED = true;

  function elChildren(container) {
    return Array.prototype.filter.call(container.children, function (c) {
      // Treat only real record cards as list items — skip a section heading that
      // sits directly in the container (e.g. a per-child <h3> above its rows) and
      // our own toolbar / empty-state so they're never filtered or reordered.
      return c.nodeType === 1
        && !/^H[1-6]$/.test(c.tagName)
        && !c.classList.contains('kt-list-controls')
        && !c.classList.contains('kt-list-empty');
    });
  }
  function primaryText(el) {
    var h = el.querySelector('[data-sort-primary],h1,h2,h3,h4,strong,b');
    var t = ((h ? h.textContent : el.textContent) || '').trim().toLowerCase();
    return t.split('\n')[0].slice(0, 160);
  }
  function cellVal(el, key, type) {
    var raw = (el.getAttribute('data-sort-' + key) || '').trim();
    if (type === 'num') { var n = parseFloat(raw.replace(/[^0-9.\-]/g, '')); return isNaN(n) ? -Infinity : n; }
    if (type === 'date') { var d = Date.parse(raw); return isNaN(d) ? -Infinity : d; }
    return raw.toLowerCase();
  }

  function attach(container) {
    // Opt-out, mirroring kt-row-actions' data-kt-no-kebab. Some lists want ONLY the
    // shared bottom "N records" bar (kt-table-export keys off the same
    // data-kt-list) without a search box + A–Z sort bolted on top — e.g. the
    // parent's Today timeline and Recent observations, which are short, already
    // chronological, and read as a story rather than a searchable dataset.
    if (container.hasAttribute('data-kt-no-controls')) return;
    // NEVER take over a <table>. This module treats the container's element
    // children as the "items", and a table's children are <thead>/<tbody> — so it
    // would report "Filter 2 items…" and, on any keystroke, hide the entire tbody
    // (the whole dataset) because the two "items" don't match. That is exactly what
    // broke search on the email log, and it also stacked a SECOND filter box on top
    // of the one kt-table-filter already adds to every #appMain table. Tables belong
    // to kt-table-filter (search + pagination) and kt-polish (column sorting).
    if (container.tagName === 'TABLE') return;
    if (container.dataset.ktListified) return;
    var kids = elChildren(container);
    if (kids.length < 2) return; // not worth it (or empty-state) — retried next sweep
    container.dataset.ktListified = '1';

    var sortOpts = (container.getAttribute('data-kt-sort') || '').split(',')
      .map(function (s) { return s.trim(); }).filter(Boolean)
      .map(function (s) { var p = s.split(':'); return { key: p[0], label: p[1] || p[0], type: p[2] || 'text' }; });

    var bar = document.createElement('div');
    bar.className = 'kt-list-controls';
    bar.style.cssText = 'display:flex;justify-content:space-between;align-items:center;gap:12px;margin:14px 0 12px;flex-wrap:wrap;';

    var left = document.createElement('div');
    left.style.cssText = 'position:relative;flex:1;min-width:220px;max-width:360px;';
    var input = document.createElement('input');
    input.type = 'search';
    input.placeholder = '🔍  Filter ' + kids.length + ' items…';
    input.style.cssText = 'width:100%;padding:9px 14px;border:1px solid #E2E8F0;border-radius:8px;font-size:13.5px;background:#fff;';
    input.addEventListener('focus', function () { input.style.borderColor = '#1F6080'; });
    input.addEventListener('blur', function () { input.style.borderColor = '#E2E8F0'; });
    left.appendChild(input);

    var right = document.createElement('div');
    // width:max-content — the group was sizing to the Sort control alone, so the
    // "51 / 51" count spilled past its right edge and got clipped by the page.
    right.style.cssText = 'display:flex;align-items:center;gap:10px;color:#64748B;font-size:13px;font-weight:600;'
      + 'flex:0 0 auto;white-space:nowrap;width:max-content;justify-content:flex-end;';
    var sortSel = document.createElement('select');
    sortSel.style.cssText = 'padding:8px 10px;border:1px solid #E2E8F0;border-radius:8px;font-size:13px;background:#fff;cursor:pointer;color:#334155;'
      + 'min-width:130px;flex:0 0 auto;';   // was shrinking until "Sort…" was clipped
    var opts = [['', 'Sort…'], ['__az', 'A → Z'], ['__za', 'Z → A']];
    sortOpts.forEach(function (o) { opts.push([o.key + ':asc', o.label + ' ↑']); opts.push([o.key + ':desc', o.label + ' ↓']); });
    opts.forEach(function (o) { var op = document.createElement('option'); op.value = o[0]; op.textContent = o[1]; sortSel.appendChild(op); });
    var counter = document.createElement('span');
    counter.className = 'kt-list-count';
    counter.style.cssText = 'white-space:nowrap;flex:0 0 auto;';   // "51 / 51" was wrapping onto two lines
    counter.textContent = kids.length + ' / ' + kids.length;
    right.appendChild(sortSel);
    right.appendChild(counter);

    bar.appendChild(left);
    bar.appendChild(right);
    container.parentElement.insertBefore(bar, container);
    bar._ktFor = container;

    function reindex() { elChildren(container).forEach(function (c, i) { if (c.dataset.ktOrigIdx == null) c.dataset.ktOrigIdx = i; }); }
    reindex();

    function applyFilter() {
      var q = input.value.trim().toLowerCase();
      var all = elChildren(container), vis = 0;
      all.forEach(function (row) {
        var show = !q || (row.textContent || '').toLowerCase().indexOf(q) !== -1;
        row.style.display = show ? '' : 'none';
        if (show) vis++;
      });
      counter.textContent = vis + ' / ' + all.length;
      counter.style.color = vis ? '#64748B' : '#EF4444';
    }
    input.addEventListener('input', applyFilter);

    sortSel.addEventListener('change', function () {
      var v = sortSel.value, rows = elChildren(container);
      if (!v) {
        rows.sort(function (a, b) { return (+a.dataset.ktOrigIdx || 0) - (+b.dataset.ktOrigIdx || 0); });
      } else if (v === '__az' || v === '__za') {
        var d = v === '__az' ? 1 : -1;
        rows.sort(function (a, b) { var av = primaryText(a), bv = primaryText(b); return av < bv ? -d : av > bv ? d : 0; });
      } else {
        var parts = v.split(':'), key = parts[0], dir = parts[1] === 'desc' ? -1 : 1;
        var type = (sortOpts.filter(function (o) { return o.key === key; })[0] || {}).type;
        rows.sort(function (a, b) { var av = cellVal(a, key, type), bv = cellVal(b, key, type); return av < bv ? -dir : av > bv ? dir : 0; });
      }
      rows.forEach(function (r) { container.appendChild(r); });
      applyFilter();
    });

    // Keep working across in-place re-renders (screens rebuild children on action).
    if (window.MutationObserver) {
      var deb = null;
      var obs = new MutationObserver(function () {
        clearTimeout(deb);
        deb = setTimeout(function () { reindex(); if (input.value.trim()) applyFilter(); else counter.textContent = elChildren(container).length + ' / ' + elChildren(container).length; }, 120);
      });
      obs.observe(container, { childList: true });
    }
  }

  function sweep() {
    // drop orphaned toolbars (container was replaced by a full re-render)
    document.querySelectorAll('.kt-list-controls').forEach(function (bar) {
      if (bar._ktFor && !bar._ktFor.isConnected) bar.remove();
    });
    document.querySelectorAll('[data-kt-list]').forEach(attach);
  }
  window.addEventListener('hashchange', function () { setTimeout(sweep, 350); });
  (window.KT && KT.sweepBus) ? KT.sweepBus.on(sweep) : setInterval(sweep, 4000);
  setTimeout(sweep, 700);

  window.KT = window.KT || {};
  window.KT.listControls = { sweep: sweep, attach: attach };
})(window);
