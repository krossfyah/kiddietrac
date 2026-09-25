/* v22p60 — universal table filter / search + toast notifications.
   Auto-attaches a search input above every <table> on data-kt-pretty
   screens that don't already have one. No per-screen wiring needed.
*/
(function (window) {
  'use strict';
  if (window.KT_TABLE_FILTER_LOADED) return;
  window.KT_TABLE_FILTER_LOADED = true;

  // ============================ Toast notifications ============================
  function ensureToastHost() {
    let host = document.getElementById('kt-toast-host');
    if (host) return host;
    host = document.createElement('div');
    host.id = 'kt-toast-host';
    host.style.cssText = 'position:fixed;top:18px;right:18px;z-index:999999;display:flex;flex-direction:column;gap:10px;pointer-events:none;';
    document.body.appendChild(host);
    return host;
  }
  function showToast(msg, kind = 'info', ttl = 4000) {
    // Unified toast: hand off to the canonical KT.toast (kt-toasts.js) so these
    // match every other toast. Guard against delegating to ourselves — we assign
    // window.KT.toast = showToast below, before kt-toasts.js overrides it, so a
    // bare delegation could recurse if kt-toasts.js hadn't loaded.
    if (window.KT && typeof window.KT.toast === 'function' && window.KT.toast !== showToast) {
      var _m = ({ info: ['ℹ️', '#1F6080'], success: ['✅', '#16A34A'], warning: ['⚠️', '#D97706'], error: ['⚠️', '#DC2626'] })[kind] || ['ℹ️', '#1F6080'];
      return window.KT.toast(_m[0], msg, '', _m[1]);
    }
    const host = ensureToastHost();
    const t = document.createElement('div');
    const colors = {
      info:    { bg: '#1F6080', icon: 'ℹ' },
      success: { bg: '#10B981', icon: '✓' },
      warning: { bg: '#F59E0B', icon: '⚠' },
      error:   { bg: '#EF4444', icon: '✗' },
    };
    const c = colors[kind] || colors.info;
    t.style.cssText = `pointer-events:auto;background:${c.bg};color:#fff;padding:12px 18px 12px 14px;border-radius:10px;box-shadow:0 8px 24px rgba(15,23,42,.18);font-size:14px;font-weight:600;max-width:380px;display:flex;align-items:flex-start;gap:10px;cursor:pointer;animation:kt-toast-in .2s ease-out;`;
    t.innerHTML = `<span style="font-size:18px;line-height:1;">${c.icon}</span><span>${String(msg).replace(/[&<>]/g, x => ({'&':'&amp;','<':'&lt;','>':'&gt;'}[x]))}</span>`;
    host.appendChild(t);
    setTimeout(() => { t.style.opacity = '0'; t.style.transform = 'translateX(20px)'; t.style.transition = 'all .2s'; setTimeout(() => t.remove(), 220); }, ttl);
    t.onclick = () => t.remove();
  }
  // Inject animation
  if (!document.getElementById('kt-toast-css')) {
    const s = document.createElement('style');
    s.id = 'kt-toast-css';
    s.textContent = '@keyframes kt-toast-in { from { opacity: 0; transform: translateX(20px); } to { opacity: 1; transform: translateX(0); } }';
    document.head.appendChild(s);
  }

  window.KT = window.KT || {};
  window.KT.toast = showToast;

  // ============================ Table filter + pagination ============================
  // Rows-per-page for the client-side pager. Tables at or below this show no pager
  // (everything fits on one page); larger tables get numbered page buttons + a jump box.
  const PAGE_SIZE = 25;

  function attachFilter(table) {
    // Opt-out for screens that own their own search. The email log, for one, searches
    // SERVER-side across every row — bolting this client-side box (which can only see
    // the rows currently loaded) beside it gives the user two search fields that
    // disagree with each other.
    if (table.hasAttribute('data-kt-no-filter')) return;
    // A screen that owns its own SEARCH but still wants sorting uses
    // data-kt-no-search instead — handled below, once the controls are built.
    if (table.dataset.ktFiltered) return;
    const tbody = table.querySelector('tbody');
    if (!tbody) return;
    // Skip 1-row tables: a search box over a single row is noise. A table can opt in
    // with data-kt-filter-always when the control should be present even while empty,
    // so a screen still being populated does not look like it is missing the feature.
    if (tbody.children.length < 2 && !table.hasAttribute('data-kt-filter-always')) return;

    /* THE FLAG IS STAMPED HERE, NOT ABOVE.

       It used to be set before the two early returns, which meant any table the sweep
       happened to reach while it was still empty or one row long was marked "done"
       forever — rows arriving a moment later found the door already shut, and the
       screen looked like it was simply missing search and sorting. Everything that
       renders its table after a fetch was exposed to that race. (Anthony, 2026-09-09) */
    table.dataset.ktFiltered = '1';

    const wrap = document.createElement('div');
    wrap.className = 'kt-table-filter';
    // Spacing lives in the stylesheet so a phone can tighten it; inline it could not be.
    wrap.style.cssText = 'display:flex;justify-content:flex-start;align-items:center;flex-wrap:wrap;';

    const left = document.createElement('div');
    left.className = 'kt-tf-left';
    // min/max width in the stylesheet: a 240px floor forced the row counter onto its
    // own line on a phone.
    left.style.cssText = 'position:relative;flex:1;';
    const input = document.createElement('input');
    input.type = 'search';
    input.placeholder = '🔍  Filter ' + tbody.children.length + ' rows…';
    input.className = 'kt-table-filter-input';
    input.style.cssText = 'width:100%;padding:9px 14px;border:1px solid #E2E8F0;border-radius:8px;font-size:13.5px;background:#fff;transition:border-color .15s;';
    input.addEventListener('focus', () => input.style.borderColor = '#1F6080');
    input.addEventListener('blur', () => input.style.borderColor = '#E2E8F0');

    /* data-kt-no-search — SORTING WITHOUT A SECOND SEARCH BOX.

       data-kt-no-filter already exists for screens that own their search, and its own
       comment says why: a client-side box beside a server-side one "gives the user two
       search fields that disagree with each other". But it returns early, so it takes
       the column sorting and the row counter with it — all or nothing.

       The Children screen is the case that needed the middle option. It searches
       SERVER-side (GET /director/enrollments?search=…, every child in the agency) and
       has centre and status filters of its own, but no sorting — so opting out entirely
       would have traded one duplicate box for a lost feature. Reported as "double the
       filter and sort fields" on the phone, where the two boxes stack one above the
       other and the duplication is unmissable.

       The input is still CREATED, because the filter/sort pipeline below reads
       input.value and listens to it. It is simply never shown, so it stays empty and
       filters nothing, and sorting, pagination and the counter all work untouched. */
    if (table.hasAttribute('data-kt-no-search')) {
      left.style.flex = '0 0 auto';
    } else {
      left.appendChild(input);
    }

    const right = document.createElement('div');
    right.style.cssText = 'display:flex;align-items:center;gap:14px;color:#64748B;font-size:13px;font-weight:600;flex-shrink:0;white-space:nowrap;margin-left:auto;';
    const counter = document.createElement('span');
    counter.className = 'kt-table-filter-count';
    counter.textContent = tbody.children.length + ' / ' + tbody.children.length;
    right.appendChild(counter);

    /* Sorting, on a phone.
       kt-mobile-tables.js restacks tables into cards below 600px and hides <thead>, so
       the header click that sorts is unreachable there — the feature silently vanishes
       on exactly the screens where a long list is hardest to read.

       This does not sort anything itself. It finds the <th> for the chosen column and
       CLICKS it, so kt-polish.js's handler stays the single implementation: same
       comparator, same indicators, same ISO-date handling. The arrow button clicks
       again, which is how that handler already toggles direction.

       Hidden above 600px by CSS — with the headers on screen, they are the better
       control and a second one would just disagree with them. */
    var sortWrap = null;
    try {
      var _thead = table.querySelector('thead');
      var headCells = Array.prototype.filter.call(_thead ? _thead.querySelectorAll('th') : [], function (th) {
        return (th.textContent || '').trim() && !th.querySelector('button');
      });
      if (headCells.length > 1) {
        sortWrap = document.createElement('div');
        sortWrap.className = 'kt-tf-sortwrap';
        /* The documented opt-out from the 16px/11px form-field sizing: this is table
           chrome, not a form field. See kt-mobile-app.css. */
        sortWrap.setAttribute('data-kt-compact', '1');

        var sel = document.createElement('select');
        sel.className = 'kt-tf-sort';
        sel.setAttribute('aria-label', 'Sort by');
        sel.innerHTML = '<option value="">Sort by…</option>' + headCells.map(function (th, i) {
          var t = (th.textContent || '').replace(/[⇅↑↓]/g, '').trim();
          return '<option value="' + i + '">' + t.replace(/[&<>"]/g, '') + '</option>';
        }).join('');

        var dirBtn = document.createElement('button');
        dirBtn.type = 'button';
        dirBtn.className = 'kt-tf-dir';
        dirBtn.textContent = '↑↓';
        dirBtn.setAttribute('aria-label', 'Reverse sort order');
        dirBtn.disabled = true;

        sel.addEventListener('change', function () {
          var i = parseInt(sel.value, 10);
          if (isNaN(i) || !headCells[i]) { dirBtn.disabled = true; return; }
          dirBtn.disabled = false;
          headCells[i].click();
          dirBtn.textContent = headCells[i].dataset.ktSort === 'desc' ? '↓' : '↑';
        });
        dirBtn.addEventListener('click', function () {
          var i = parseInt(sel.value, 10);
          if (isNaN(i) || !headCells[i]) return;
          headCells[i].click();
          dirBtn.textContent = headCells[i].dataset.ktSort === 'desc' ? '↓' : '↑';
        });

        sortWrap.appendChild(sel);
        sortWrap.appendChild(dirBtn);
      }
    } catch (e) { sortWrap = null; }

    wrap.appendChild(left);
    if (sortWrap) wrap.appendChild(sortWrap);
    wrap.appendChild(right);

    // Bounded height + internal scroll (Gmail-style): wrap the table in a
    // scroll container so long tables scroll WITHIN a reasonable box instead of
    // stretching the page. The toolbar (search + count) is inserted ABOVE the
    // scroll container — never inside an overflow box — so the count is never
    // clipped. Skipped for modal tables (they scroll themselves).
    let sc = table.closest('.kt-tbl-scroll');
    if (!sc && !table.closest('#modalRoot')) {
      sc = document.createElement('div');
      sc.className = 'kt-tbl-scroll';
      table.parentElement.insertBefore(sc, table);
      sc.appendChild(table);
    }
    (sc ? sc.parentElement : table.parentElement).insertBefore(wrap, sc || table);

    /* ONE PAGER PER TABLE (2026-09-24).

       Anthony: "the immunization table has two pagination - this should never be
       like this."

       It had. There are two global table pagers in the portal - this one, and
       attachPagination() in kt-polish-v2.js - and nothing stopped both landing under
       the same tbody. kt-polish-v2 fires when a table carries data-kt-paginate="N"
       (or runs past 200 rows); this one fires whenever the search toolbar attaches.
       Seven tables carry data-kt-paginate, and the immunization roster carries
       data-kt-filter-always as well, so it asked for both by name. Two sets of
       controls under one table is two ways to disagree about which page you are on.

       This one wins where both apply, for the reason kt-polish-v2's own comment
       gives: it is the pager that cooperates with the search and sort sweep through
       data-ktFilterHidden, and a pager that does not know what is filtered will
       happily show an empty page 3.

       `ktPaginated` is the flag kt-polish-v2 already checks before attaching, so
       setting it here is enough to stop it in the usual order. When it has already
       run - it is free to fire before the toolbar attaches - its bar is removed
       rather than left sitting under this one. Either order, one pager. */
    table.dataset.ktPaginated = '1';
    try {
      const host = (sc || table).parentElement;
      host.querySelectorAll(':scope > .kt-pager').forEach((b) => b.remove());
    } catch (e) {}

    // Pagination footer — inserted BELOW the scroll container so it's always visible.
    const pager = document.createElement('div');
    pager.className = 'kt-table-pager';
    pager.style.cssText = 'display:none;justify-content:center;align-items:center;gap:6px;margin:12px 0 2px;flex-wrap:wrap;font-size:13px;';
    const anchor = sc || table;
    anchor.parentElement.insertBefore(pager, anchor.nextSibling);

    let page = 1;

    /** The size this table asked for, else the house default. */
    function tablePageSize() {
      const want = parseInt(table.getAttribute('data-kt-paginate') || '', 10);
      return want > 0 ? want : PAGE_SIZE;
    }

    /* The bar itself is KT.pagerBar (kt-card-pager.js) — the one every pager in the
       portal draws with, server-paged screens included. */
    function buildPager(totalPages) {
      if (KT.pagerBar) { KT.pagerBar(pager, page, totalPages, (p) => { page = p; render(); }); return; }
      pager.style.display = 'none';
    }

  /* HOW WELL a row matches, not merely WHETHER it does.

     Both list filters tested `row.textContent.includes(q)` and hid the rest. Everything
     that survived kept its original position, so searching a name showed it wherever it
     already happened to sit — often below rows that merely mention the word in an
     address, a note or a status. On a phone, where only a few rows are on screen, the
     thing you searched for is then simply not visible, which reads as "the search does
     not work".

     Ranked lowest-first: the identifying text IS the term, then begins with it, then
     begins a WORD in it, then contains it, and last a row that matched only in some
     other column. Ties keep their original order, so the list never shuffles for rows
     that are equally good. (Anthony, 2026-09-08) */
  function ktRankOf(primary, whole, q) {
    if (whole.indexOf(q) === -1) { return -1; }
    if (primary === q) { return 0; }
    if (primary.indexOf(q) === 0) { return 1; }
    var esc = q.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
    try { if (new RegExp('(^|[^a-z0-9])' + esc).test(primary)) { return 2; } } catch (e) {}
    if (primary.indexOf(q) !== -1) { return 3; }
    return 4;
  }

  /* The row's identifying text: the first cell that carries real words. Tables lead
     with a checkbox or a spacer column, and scoring against that would rank nothing. */
  function ktRowPrimary(r) {
    var cells = r.children;
    for (var i = 0; i < cells.length; i++) {
      var t = (cells[i].textContent || '').replace(/\s+/g, ' ').trim().toLowerCase();
      if (t.length > 1) { return t; }
    }
    return (r.textContent || '').replace(/\s+/g, ' ').trim().toLowerCase();
  }

    // Single source of truth for row visibility: a row shows iff it matches the
    // filter AND falls on the current page (of the matched subset). Called on input,
    // on page change, and whenever the tbody changes (sort / screen re-render).
    let renderScheduled = false;
    /* The order the list was in when the search began — restored when the box is
       cleared. Captured then rather than once at startup so it preserves whatever the
       reader had already sorted by, instead of yanking them back to the default. */
    let baseOrder = null;

    /* Held so render() can switch it off while it rearranges the rows itself.
       See the note at the bottom of this function. */
    let mo = null;

    function render() {
      renderScheduled = false;

      /* ── THE SEARCH USED TO FIGHT ITSELF ────────────────────────────────
         Ranking re-appends the matching rows so the best match is first in the
         DOM. appendChild on a row already in the tbody MOVES it, and a move is a
         childList mutation — which is exactly what the observer at the end of
         this function listens for. So render() woke itself: rank, mutate,
         observe, schedule, 40ms later rank again, for as long as the search box
         had anything in it. Around twenty-five renders a second, forever.

         Nothing looked wrong, because every render produced the same list. What
         it broke was CLICKING. A button press is mousedown, mouseup, and only
         then a click — and the browser fires no click at all if the element
         moved in between. With the rows being re-appended every 40ms, a press
         landed in the gap more often than not, so action buttons did nothing
         while a search was active and worked perfectly without one. Reported
         three times as "the kebab won't open"; it was never the kebab.

         The comment on the observer claimed render() "only flips style.display,
         so it can't retrigger this". That was true of the paging half and false
         of the ranking half added later.

         Deaf while it speaks: disconnect() also discards the records already
         queued, so the moves this function makes can never come back to it.
         Re-observed in a finally, so a throw cannot leave the table permanently
         unwatched. */
      if (mo) { try { mo.disconnect(); } catch (e) {} }
      try {
        renderInner();
      } finally {
        if (mo) { try { mo.observe(tbody, { childList: true }); } catch (e) {} }
      }
    }

    function renderInner() {
      const q = (input.value || '').trim().toLowerCase();

      if (!q && baseOrder) {
        baseOrder.forEach(r => { if (r.parentNode === tbody) tbody.appendChild(r); });
        baseOrder = null;
      }
      if (q && !baseOrder) { baseOrder = Array.from(tbody.children); }

      const rows = Array.from(tbody.children);
      let matched = rows;
      if (q) {
        const scored = [];
        rows.forEach((r, i) => {
          const sc = ktRankOf(ktRowPrimary(r), (r.textContent || '').toLowerCase(), q);
          if (sc >= 0) { scored.push({ r, sc, i }); }
        });
        scored.sort((a, b) => (a.sc - b.sc) || (a.i - b.i));
        // Best first IN THE DOM, so paging puts them on page 1 rather than page 3.
        scored.forEach(m => tbody.appendChild(m.r));
        matched = scored.map(m => m.r);
      }
      const total = matched.length;
      /* A screen that asked for a page size gets it. data-kt-paginate used to reach
         only the other pager, so silencing that one would have quietly changed 25 to
         this module's default on every table that set it. */
      const pageSize = tablePageSize();
      const totalPages = Math.max(1, Math.ceil(total / pageSize));
      if (page > totalPages) page = totalPages;
      if (page < 1) page = 1;
      const start = (page - 1) * pageSize, end = start + pageSize;
      const inMatch = new Set(matched);
      let mi = 0;
      for (const r of Array.from(tbody.children)) {
        if (q && !inMatch.has(r)) { r.style.display = 'none'; continue; }
        r.style.display = (mi >= start && mi < end) ? '' : 'none';
        mi++;
      }
      const from = total ? start + 1 : 0;
      const to = Math.min(end, total);
      counter.textContent = (total === rows.length)
        ? (from + '–' + to + ' of ' + total)
        : (from + '–' + to + ' of ' + total + ' (filtered from ' + rows.length + ')');
      counter.style.color = total ? '#64748B' : '#EF4444';
      buildPager(totalPages);
    }
    function scheduleRender() {
      if (renderScheduled) return;
      renderScheduled = true;
      setTimeout(render, 40);
    }

    input.addEventListener('input', () => { page = 1; render(); });

    /* Re-paginate when the tbody's rows change from OUTSIDE — a column-header sort
       reorders them, a screen re-render replaces them. render() switches this off
       around its own rearranging, so only somebody else's changes reach it. */
    try {
      mo = new MutationObserver(scheduleRender);
      mo.observe(tbody, { childList: true });
    } catch (e) {}

    render();
  }

  function sweepTables() {
    // EVERY table in the app, not only screens flagged data-kt-pretty. Certifications
    // (and others) were never flagged, so they got no search, no sort and no live
    // record count. attachFilter is guarded by a dataset flag, so this attaches once
    // per table however often the sweep runs.
    document.querySelectorAll('#appMain table').forEach(t => {
      // skip tables with only a "no data" empty-state row
      const tbody = t.querySelector('tbody');
      if (!tbody) return;
      if (tbody.children.length === 1) {
        const td = tbody.children[0].querySelector('td');
        if (td && td.colSpan && td.colSpan > 1) return; // it's an empty-state row
      }
      attachFilter(t);
    });
  }

  // Run on hashchange + at intervals
  let sweepTimer = null;
  function scheduleSweep() {
    if (sweepTimer) clearTimeout(sweepTimer);
    sweepTimer = setTimeout(sweepTables, 350);
  }
  window.addEventListener('hashchange', scheduleSweep);
  (window.KT && KT.sweepBus) ? KT.sweepBus.on(sweepTables) : setInterval(sweepTables, 4000);    // was 2000ms — the toolbar landed long after the table
  setTimeout(sweepTables, 700);

  /* ASK FOR IT, DON'T WAIT TO BE SWEPT.

     Both table enhancers (this one and kt-polish.js) find their work by sweeping, driven
     by hashchange and by KT.sweepBus. That covers a screen you navigate TO. It does not
     cover a table that appears when you click a tab INSIDE a screen — no hash changes, so
     the only remaining chance is a bus tick, and the bus stops for the whole session once
     a tab has been backgrounded. The result is a table with no search box and no sortable
     headers, on a screen where the identical table two tabs over has both.

     So a screen that has just rendered a table can say so. Each enhancer pushes its sweep
     onto the same list and KT.enhanceTables() runs them all; the sweeps are already
     guarded against running twice on the same table, so calling this is free.
     (Anthony, 2026-09-09) */
  window.KT = window.KT || {};
  KT.tableEnhancers = KT.tableEnhancers || [];
  KT.tableEnhancers.push(sweepTables);
  if (!KT.enhanceTables) {
    KT.enhanceTables = function () {
      (KT.tableEnhancers || []).forEach(function (f) { try { f(); } catch (e) {} });
    };
  }


  // ============================ Better empty states ============================
  function sweepEmptyStates() {
    document.querySelectorAll('[data-kt-pretty] tbody').forEach(tb => {
      if (tb.dataset.ktEmpty) return;
      if (tb.children.length === 1) {
        const td = tb.children[0].querySelector('td');
        if (td && td.colSpan && td.colSpan > 1 && /no\s+|none|empty|haven/i.test(td.textContent || '')) {
          // already an empty-state row — style it nicer
          td.style.padding = '40px 20px';
          if (!td.querySelector('.kt-empty-icon')) {
            td.innerHTML = '<div class="kt-empty-icon" style="font-size:42px;margin-bottom:8px;opacity:.4;">📭</div>' + td.innerHTML;
          }
          tb.dataset.ktEmpty = '1';
        }
      }
    });
  }
  (window.KT && KT.sweepBus) ? KT.sweepBus.on(sweepEmptyStates) : setInterval(sweepEmptyStates, 4000);
  setTimeout(sweepEmptyStates, 800);

  // ============================ Loading skeleton ============================
  // expose a helper screens can call: KT.skeleton(main, rowCount)
  window.KT.skeleton = function (host, rows = 5) {
    host.innerHTML = '<div style="padding:24px;max-width:1800px;margin:0 auto;">'
      + '<div style="background:linear-gradient(90deg,#F1F5F9 0%,#E2E8F0 50%,#F1F5F9 100%);background-size:200% 100%;animation:kt-shimmer 1.4s linear infinite;height:120px;border-radius:14px;margin-bottom:18px;"></div>'
      + Array.from({length: rows}).map(() =>
        '<div style="background:linear-gradient(90deg,#F1F5F9 0%,#E2E8F0 50%,#F1F5F9 100%);background-size:200% 100%;animation:kt-shimmer 1.4s linear infinite;height:60px;border-radius:10px;margin-bottom:8px;"></div>'
      ).join('')
      + '</div>';
    if (!document.getElementById('kt-shimmer-css')) {
      const s = document.createElement('style');
      s.id = 'kt-shimmer-css';
      s.textContent = '@keyframes kt-shimmer { 0% { background-position: 200% 0; } 100% { background-position: -200% 0; } }';
      document.head.appendChild(s);
    }
  };

  // ============================ Override window.alert to use toasts ============================
  // Only on /dashboard-y pages, not on auth pages.
  if (location.pathname.indexOf('dashboard') !== -1 || location.pathname === '/' || location.pathname.endsWith('.html')) {
    const origAlert = window.alert;
    window.alert = function (msg) {
      try {
        if (/error|fail|wrong|denied|expired|invalid/i.test(String(msg))) showToast(msg, 'error', 6000);
        else if (/saved|sent|approved|done|success|complete|removed|added|charged|paid/i.test(String(msg))) showToast(msg, 'success');
        else showToast(msg, 'info');
      } catch (e) { origAlert(msg); }
    };
  }
})(window);
