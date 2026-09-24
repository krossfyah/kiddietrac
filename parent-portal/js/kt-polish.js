/* v22p61 — polish layer.
   Extends v22p60's table-filter with: sortable columns, sticky headers,
   per-table CSV export, ISO datetime auto-format. Adds window.KT.prompt
   + window.KT.confirm modals. Mobile sidebar toggle.
*/
(function (window) {
  'use strict';
  if (window.KT_POLISH_LOADED) return;
  window.KT_POLISH_LOADED = true;
  const KT = window.KT = window.KT || {};

  // ============================ Modal: prompt + confirm ============================
  function buildOverlay() {
    const o = document.createElement('div');
    /* ABOVE EVERYTHING, because a confirm is a QUESTION that blocks whatever asked it.

       This sat at 9,999,999 while the portal keeps a whole tier of full-screen overlays
       three orders of magnitude higher — the terms/NDA scrim (2147481000), the two-factor
       gate (2147482000), the avatar cropper (2147483000), the photo lightbox (2147483200),
       even the toast (2147483600). Any confirm raised while one of those was up opened
       UNDERNEATH it: the dialog was there, the buttons were there, and the tap landed on
       the thing covering them.

       Measured on a phone: "Mark all read" opened "Mark all 193 as read?" and
       elementFromPoint over the Confirm button returned the agreement's signature canvas.
       Nothing cleared, and nothing explained why — reported as "clicking all read in the
       APK doesn't clear it".

       Sits just under the int32 ceiling so it outranks every existing tier. If something
       ever genuinely needs to be above a confirm, it is a gate that should be suppressing
       the confirm instead. (Anthony, 2026-09-09) */
    o.style.cssText = 'position:fixed;inset:0;background:rgba(15,23,42,0.5);backdrop-filter:blur(2px);z-index:2147483640;display:flex;align-items:center;justify-content:center;animation:kt-fade-in .15s ease-out;';
    return o;
  }
  if (!document.getElementById('kt-modal-css')) {
    const s = document.createElement('style');
    s.id = 'kt-modal-css';
    s.textContent = '@keyframes kt-fade-in { from { opacity: 0; } to { opacity: 1; } } @keyframes kt-pop-in { from { transform: scale(.94); opacity: 0; } to { transform: scale(1); opacity: 1; } }';
    document.head.appendChild(s);
  }

  KT.prompt = function (opts) {
    if (typeof opts === 'string') opts = { title: opts };
    opts = opts || {};
    return new Promise((resolve) => {
      const o = buildOverlay();
      const m = document.createElement('div');
      m.style.cssText = 'background:#fff;padding:28px;border-radius:14px;max-width:440px;width:92%;box-shadow:0 20px 60px rgba(15,23,42,.25);animation:kt-pop-in .15s ease-out;';
      m.innerHTML = `
        <h3 style="margin:0 0 6px;color:#0F172A;font-size:18px;font-weight:700;">${esc(opts.title || 'Enter value')}</h3>
        ${opts.description ? `<p style="color:#64748B;font-size:13.5px;margin:0 0 14px;">${esc(opts.description)}</p>` : '<div style="height:6px;"></div>'}
        ${(opts.fields || [{ key: 'value', label: opts.title || '', placeholder: opts.placeholder || '', type: opts.type || 'text' }]).map(f => `
          <label style="display:block;font-size:13px;font-weight:600;margin-top:10px;color:#374151;">${esc(f.label || '')}</label>
          <input data-field="${esc(f.key || 'value')}" type="${esc(f.type || 'text')}" placeholder="${esc(f.placeholder || '')}" value="${esc(f.value || '')}" ${f.min !== undefined ? 'min=' + f.min : ''} ${f.max !== undefined ? 'max=' + f.max : ''} ${f.step !== undefined ? 'step=' + f.step : ''}
            style="width:100%;padding:11px 14px;border:1px solid #E2E8F0;border-radius:8px;font-size:14px;margin-top:5px;outline:none;transition:border-color .15s;">
        `).join('')}
        <div style="margin-top:22px;display:flex;justify-content:flex-end;gap:8px;">
          <button data-action="cancel" style="background:#F1F5F9;border:0;padding:10px 18px;border-radius:8px;cursor:pointer;font-weight:600;color:#475569;font-size:14px;">${esc(opts.cancelLabel || 'Cancel')}</button>
          <button data-action="ok" style="background:linear-gradient(180deg,#1F6080,#154057);color:#fff;border:0;padding:10px 18px;border-radius:8px;cursor:pointer;font-weight:600;font-size:14px;">${esc(opts.okLabel || 'OK')}</button>
        </div>
      `;
      o.appendChild(m);
      document.body.appendChild(o);

      const inputs = Array.from(m.querySelectorAll('input[data-field]'));
      inputs.forEach(i => {
        i.addEventListener('focus', () => i.style.borderColor = '#1F6080');
        i.addEventListener('blur', () => i.style.borderColor = '#E2E8F0');
      });
      setTimeout(() => inputs[0] && inputs[0].focus(), 80);

      const close = (result) => { o.remove(); resolve(result); };
      m.querySelector('[data-action="cancel"]').onclick = () => close(null);
      m.querySelector('[data-action="ok"]').onclick = () => {
        if (opts.fields && opts.fields.length > 1) {
          const out = {};
          inputs.forEach(i => out[i.dataset.field] = i.value);
          close(out);
        } else {
          close(inputs[0].value);
        }
      };
      o.addEventListener('keydown', (e) => {
        if (e.key === 'Escape') close(null);
        if (e.key === 'Enter' && e.target.tagName === 'INPUT' && !opts.fields) m.querySelector('[data-action="ok"]').click();
      });
      o.tabIndex = -1;
      o.focus();
    });
  };

  KT.confirm = function (opts) {
    if (typeof opts === 'string') opts = { title: opts };
    opts = opts || {};
    // Auto-flag clearly DESTRUCTIVE actions with the danger (red ⚠) tone + a
    // matching action label, unless the caller set a tone explicitly. This makes
    // the ~47 migrated confirm() sites visually honest with no per-site edits.
    // Kept conservative: only unambiguous destructive verbs (not 'cancel', which
    // collides with the Cancel button, nor 'reset'/'sign out').
    if (!opts.tone) {
      var _dtxt = ((opts.title || '') + ' ' + (opts.description || '')).toLowerCase();
      if (/\b(delete|remove|permanently|deactivate|disconnect|discard|revoke|void|suspend|archive|soft-delete)\b/.test(_dtxt)) {
        opts.tone = 'danger';
        if (!opts.okLabel) {
          opts.okLabel = /\bdelete\b/.test(_dtxt) ? 'Delete'
            : /\bremove\b/.test(_dtxt) ? 'Remove'
            : /\bdisconnect\b/.test(_dtxt) ? 'Disconnect'
            : /\bdiscard\b/.test(_dtxt) ? 'Discard'
            : /\bdeactivate\b/.test(_dtxt) ? 'Deactivate'
            : /\barchive\b/.test(_dtxt) ? 'Archive'
            : /\bvoid\b/.test(_dtxt) ? 'Void'
            : /\bsuspend\b/.test(_dtxt) ? 'Suspend'
            : 'Confirm';
        }
      }
    }
    return new Promise((resolve) => {
      const o = buildOverlay();
      const m = document.createElement('div');
      const tone = opts.tone || 'default';
      const accent = tone === 'danger' ? '#EF4444' : tone === 'warning' ? '#F59E0B' : '#1F6080';
      m.style.cssText = 'background:#fff;padding:28px;border-radius:14px;max-width:440px;width:92%;box-shadow:0 20px 60px rgba(15,23,42,.25);animation:kt-pop-in .15s ease-out;';
      m.innerHTML = `
        <div style="display:flex;gap:14px;align-items:flex-start;">
          <div style="background:${accent}22;color:${accent};width:44px;height:44px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:22px;flex-shrink:0;">
            ${tone === 'danger' ? '⚠' : tone === 'warning' ? '!' : '?'}
          </div>
          <div style="flex:1;">
            <h3 style="margin:0 0 4px;color:#0F172A;font-size:17px;font-weight:700;">${esc(opts.title || 'Are you sure?')}</h3>
            ${opts.description ? `<p style="color:#64748B;font-size:14px;margin:6px 0 0;line-height:1.55;white-space:pre-line;">${esc(opts.description)}</p>` : ''}
            <div class="kt-confirm-extra" style="margin-top:14px;"></div>
          </div>
        </div>
        <div style="margin-top:24px;display:flex;justify-content:flex-end;gap:8px;">
          <button data-action="cancel" style="background:#F1F5F9;border:0;padding:10px 18px;border-radius:8px;cursor:pointer;font-weight:600;color:#475569;">${esc(opts.cancelLabel || 'Cancel')}</button>
          <button data-action="ok" style="background:${accent};color:#fff;border:0;padding:10px 18px;border-radius:8px;cursor:pointer;font-weight:600;">${esc(opts.okLabel || 'Confirm')}</button>
        </div>
      `;

      /* opts.extra: one element the caller built and still holds, so it can read the
         value back after the dialog resolves. Appended rather than interpolated —
         the markup above is a template string, and an element cannot survive that. */
      if (opts.extra && opts.extra.nodeType === 1) {
        var _slot = m.querySelector('.kt-confirm-extra');
        if (_slot) { _slot.appendChild(opts.extra); }
      }

      o.appendChild(m);
      document.body.appendChild(o);
      const close = (b) => { o.remove(); resolve(b); };
      m.querySelector('[data-action="cancel"]').onclick = () => close(false);
      m.querySelector('[data-action="ok"]').onclick = () => close(true);
      o.addEventListener('keydown', (e) => { if (e.key === 'Escape') close(false); });
      o.tabIndex = -1;
      o.focus();
    });
  };

  // Override window.prompt + window.confirm with nicer modals on dashboard
  if (/dashboard|kiddietrac\.com/.test(location.href)) {
    const _p = window.prompt;
    window.prompt = function (msg, defaultValue) {
      // Synchronous behaviour can't be replicated — fall back to native if caller expects sync return.
      // BUT — most existing callsites use await/then patterns elsewhere; we expose the modal but keep native for safety.
      return _p.call(window, msg, defaultValue || '');
    };
  }

  // ============================ Table polish: sort + CSV + sticky header + ISO date format ============================
  /**
   * Reformat a date-looking table cell — in the AGENCY's timezone.
   *
   * This used to call toLocaleDateString/toLocaleTimeString with `undefined` as the
   * locale-and-zone, i.e. the VIEWER'S DEVICE zone, and it parsed the zone-less
   * "2026-05-20 12:34:56" MySQL form with `new Date(...)`, which reads it as local
   * time. So a UTC value stored by the server was mis-parsed AND then re-rendered
   * in whatever zone the laptop happened to be in — wrong twice, and wrong on the
   * one screen that must never drift. Every displayed time in the portal is
   * agency-local; kt-tz.js owns that zone.
   *
   * Non-dates are returned untouched: this runs over EVERY cell in EVERY table, so
   * it has to be a no-op for anything that isn't a timestamp.
   */
  function fmtDateMaybe(s) {
    if (typeof s !== 'string') return s;
    var zone;
    try { zone = (window.KT && KT.tz && KT.tz()) || undefined; } catch (e) { zone = undefined; }

    var dateOpts = { timeZone: zone, year: 'numeric', month: 'short', day: 'numeric' };
    var timeOpts = { timeZone: zone, hour: '2-digit', minute: '2-digit' };

    // ISO with time: 2026-05-20T12:34:56[.mmm][Z|±hh:mm]
    if (/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}/.test(s)) {
      try {
        // No zone marker means the server sent UTC — say so explicitly.
        var iso = /(Z|[+-]\d{2}:?\d{2})$/.test(s) ? s : s + 'Z';
        var d = new Date(iso);
        if (isNaN(d)) return s;
        return d.toLocaleDateString(undefined, dateOpts) + ' ' + d.toLocaleTimeString(undefined, timeOpts);
      } catch (e) { return s; }
    }
    // Date only: 2026-05-20. A bare date has no time to shift, so render it as
    // written rather than converting it into the previous day.
    if (/^\d{4}-\d{2}-\d{2}$/.test(s)) {
      try {
        var parts = s.split('-');
        var dd = new Date(Date.UTC(+parts[0], +parts[1] - 1, +parts[2]));
        if (isNaN(dd)) return s;
        return dd.toLocaleDateString(undefined, { timeZone: 'UTC', year: 'numeric', month: 'short', day: 'numeric' });
      } catch (e) { return s; }
    }
    // MySQL datetime: 2026-05-20 12:34:56 — zone-less, therefore UTC.
    if (/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}/.test(s)) {
      try {
        var d2 = new Date(s.replace(' ', 'T') + 'Z');
        if (isNaN(d2)) return s;
        return d2.toLocaleDateString(undefined, dateOpts) + ' ' + d2.toLocaleTimeString(undefined, timeOpts);
      } catch (e) { return s; }
    }
    return s;
  }

  function polishTable(table) {
    if (table.dataset.ktPolished) return;
    table.dataset.ktPolished = '1';
    const tbody = table.querySelector('tbody');
    const thead = table.querySelector('thead');
    if (!tbody || !thead) return;
    if (tbody.children.length < 1) return;

    // ---- ISO date auto-format ----
    Array.from(tbody.children).forEach(row => {
      Array.from(row.children).forEach(td => {
        // Skip cells with HTML children (pills, bars, badges)
        if (td.children.length) return;
        const text = (td.textContent || '').trim();
        if (text.length > 6 && text.length < 35) {
          const fmt = fmtDateMaybe(text);
          if (fmt !== text) {
            td.dataset.ktIso = text;
            td.textContent = fmt;
          }
        }
      });
    });

    // ---- Sticky header ----
    Array.from(thead.querySelectorAll('th')).forEach(th => {
      th.style.position = 'sticky';
      th.style.top = '0';
      th.style.zIndex = '5';
    });

    // ---- Sortable headers ----
    const ths = Array.from(thead.querySelectorAll('th'));
    ths.forEach((th) => {
      const text = (th.textContent || '').trim();
      if (!text || text === '') return;
      if (th.querySelector('button')) return; // action header
      th.style.cursor = 'pointer';
      th.style.userSelect = 'none';
      // Add a sort indicator span
      /* PINNED TO THE CORNER, not appended after the header's content.
         This used to be a plain inline span added as the th's LAST child. That is fine
         for a header that is just text, but plenty of headers are built from <div>s —
         a day name over a count, a label over a status pill — and a block-level sibling
         pushes the arrows onto a line of their own UNDERNEATH the header, which is the
         stray mark showing up below the column titles.
         Positioning it against the th works whatever the header is made of: the th is
         already position:sticky just above, so it is the containing block. */
      const indicator = document.createElement('span');
      indicator.className = 'kt-sort-indicator';
      indicator.style.cssText = 'position:absolute;right:6px;top:6px;opacity:0.3;font-size:10px;'
        + 'line-height:1;pointer-events:none;';
      indicator.textContent = '⇅';
      // Only widen a header that would otherwise have its text run under the arrows.
      const _pr = parseFloat(getComputedStyle(th).paddingRight) || 0;
      if (_pr < 16) { th.style.paddingRight = '16px'; }
      th.appendChild(indicator);
      th.addEventListener('click', () => {
        const currentDir = th.dataset.ktSort || '';
        // Reset all
        ths.forEach(t => {
          t.dataset.ktSort = '';
          const i = t.querySelector('.kt-sort-indicator');
          if (i) { i.textContent = '⇅'; i.style.opacity = '0.3'; }
        });
        const dir = currentDir === 'asc' ? 'desc' : 'asc';
        th.dataset.ktSort = dir;
        indicator.textContent = dir === 'asc' ? '↑' : '↓';
        indicator.style.opacity = '1';

        /* THE COLUMN IS WHERE THIS HEADER IS *NOW* (2026-09-24).

           Anthony: "when I click on the provider name it should sort the providers
           based on their names and the status doesnt sort based on the status types."

           It was sorting the column to the LEFT of whichever one you clicked. colIdx
           was captured when the handler was bound, and kt-polish-v2.js prepends a
           select-all checkbox cell to the header and to every row afterwards
           (`row.insertBefore(td, row.firstChild)`). Every column shifts one to the
           right; the handlers keep pointing at the old seat. On the immunization
           roster, clicking Provider sorted Family, and clicking Status sorted Uploaded
           - which is mostly empty, so it read as "status does not sort at all".

           It only showed where the checkbox column exists, which is why the same table
           sorts correctly on an agency that has not got one.

           Read live from the DOM instead. Any column inserted later - a checkbox now,
           anything else next time - moves the header and the index together. */
        const liveCol = Array.prototype.indexOf.call(th.parentElement.children, th);
        const rows = Array.from(tbody.children);
        rows.sort((a, b) => {
          const r = compareKeys(cellSortKey(a.children[liveCol]), cellSortKey(b.children[liveCol]));
          return dir === 'asc' ? r : -r;
        });
        rows.forEach(r => tbody.appendChild(r));
      });
    });

    // ---- CSV export button (added to existing kt-table-filter wrap if present) ----
    // The table may be wrapped in a .kt-tbl-scroll container; the toolbar sits
    // above that wrapper, so look there too.
    const _sc = table.closest('.kt-tbl-scroll');
    const wrap = _sc ? _sc.previousElementSibling : table.previousElementSibling;
    // Top CSV button retired — CSV/Excel/PDF now live in the bottom export bar
    // (kt-table-export.js) for consistency with the Reports section.
    if (false && wrap && wrap.classList.contains('kt-table-filter')) {
      if (!wrap.querySelector('.kt-csv-btn')) {
        const csvBtn = document.createElement('button');
        csvBtn.className = 'kt-csv-btn';
        csvBtn.title = 'Download visible rows as CSV';
        csvBtn.style.cssText = 'background:#10B981;color:#fff;border:0;padding:8px 14px;border-radius:8px;font-size:12.5px;font-weight:600;cursor:pointer;';
        csvBtn.textContent = '⤓ CSV';
        csvBtn.onclick = () => exportTableAsCsv(table);
        const right = wrap.children[1] || wrap;
        right.insertBefore(csvBtn, right.firstChild);
      }
    }
  }

  /* COMPARING A NUMBER WITH A DASH (2026-09-24).

     cellSortKey returns a NUMBER for a cell that looks numeric and a STRING for one
     that does not, and a column full of money with a few blanks in it contains both.
     `1500 < '\u2014'` is false, and so is `1500 > '\u2014'` - a dash coerces to NaN and
     every comparison against it answers false. The comparator read that as "equal",
     so the rows around each blank kept whatever order they were already in and the
     column came out unsorted rather than merely oddly sorted. Found on the children
     roster's Fee column, which runs $0.00, a dash, $1500.00.

     So: numbers against numbers numerically, text against text with localeCompare
     (which also gets names with accents right, where < and > do not), and a number
     before text when the two meet. Blanks and dashes are text, so they gather at one
     end instead of scattering through the middle. */
  function compareKeys(av, bv) {
    const an = typeof av === 'number' && !isNaN(av);
    const bn = typeof bv === 'number' && !isNaN(bv);
    if (an && bn) { return av - bv; }
    if (an) { return -1; }
    if (bn) { return 1; }
    return String(av).localeCompare(String(bv), undefined, { numeric: true, sensitivity: 'base' });
  }

  function cellSortKey(td) {
    if (!td) return '';
    /* AN EXPLICIT KEY BEATS WHATEVER THE CELL LOOKS LIKE.

       textContent is the wrong thing to sort on whenever a cell holds more than one
       line: three stacked form titles concatenate into "Consent formPhoto formMedical
       form", and a badge reading "✓ 2 of 3" sorts under the tick. data-kt-sort lets
       the screen say what the column actually means, once, at render time.
       data-kt-iso stays supported — it is the same idea, named for its first use.
       (Anthony, 2026-09-09) */
    if (td.dataset.ktSort) return td.dataset.ktSort;
    // Prefer the original ISO if we stashed one
    if (td.dataset.ktIso) return td.dataset.ktIso;
    const txt = (td.textContent || '').trim();
    // Pure number
    if (/^-?\$?[\d,]+(\.\d+)?%?$/.test(txt)) return parseFloat(txt.replace(/[\$,%]/g, '')) || 0;
    return txt.toLowerCase();
  }

  function exportTableAsCsv(table) {
    const thead = table.querySelector('thead');
    const tbody = table.querySelector('tbody');
    const headers = Array.from(thead.querySelectorAll('th'))
      .map(th => th.cloneNode(true))
      .map(th => { const i = th.querySelector('.kt-sort-indicator'); if (i) i.remove(); return (th.textContent || '').trim().replace(/\s+/g, ' '); });
    const lines = [headers.map(csvCell).join(',')];
    Array.from(tbody.children).forEach(row => {
      if (row.style.display === 'none') return; // skip hidden (filtered) rows
      const cells = Array.from(row.children).map(td => {
        if (td.dataset.ktIso) return td.dataset.ktIso;
        return (td.textContent || '').trim().replace(/\s+/g, ' ');
      });
      lines.push(cells.map(csvCell).join(','));
    });
    // BOM for Excel
    const blob = new Blob(['﻿' + lines.join('\n')], { type: 'text/csv;charset=utf-8' });
    const a = document.createElement('a');
    a.href = URL.createObjectURL(blob);
    a.download = 'table-' + new Date().toISOString().slice(0, 10) + '.csv';
    a.click();
  }
  function csvCell(s) {
    s = String(s == null ? '' : s);
    if (/[",\n]/.test(s)) return '"' + s.replace(/"/g, '""') + '"';
    return s;
  }


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
  KT.tableEnhancers.push(function () { sweep(); });
  if (!KT.enhanceTables) {
    KT.enhanceTables = function () {
      (KT.tableEnhancers || []).forEach(function (f) { try { f(); } catch (e) {} });
    };
  }

  function sweep() {
    // EVERY table under #appMain, matching kt-table-filter's sweep. Gating on
    // [data-kt-pretty] meant sortable columns only appeared on screens that opted
    // in — the Forms Manager tables (and every other unflagged screen) got a search
    // box from kt-table-filter but no sortable headers, which reads as a bug.
    document.querySelectorAll('#appMain table').forEach(polishTable);
  }
  window.addEventListener('hashchange', () => setTimeout(sweep, 400));
  (window.KT && KT.sweepBus) ? KT.sweepBus.on(sweep) : setInterval(sweep, 4000);
  setTimeout(sweep, 900);

  // ============================ "Loading…" → skeleton ============================
  /* How many skeletons this navigation may paint. A stuck placeholder is a cosmetic
     problem; a sweep that repaints forever is a locked browser, and the difference
     between them is only ever a bug somebody has not found yet. Reset on navigation. */
  var skelBudget = 12;
  window.addEventListener('hashchange', function () { skelBudget = 12; });

  function sweepLoading() {
    if (skelBudget <= 0) return;
    document.querySelectorAll('[data-kt-pretty]').forEach(host => {
      const text = (host.innerHTML || '').trim();
      // Crude detector for the "Loading…" placeholder pattern used across v22p51-59
      if (text.length >= 200 || !/Loading|Computing|Scanning/i.test(text)) return;

      /* Replace the PLACEHOLDER, never the host.
         This used to do `KT.skeleton(host, 6)`, and host is #appMain — so it wiped out
         whatever the screen had built. Screens render into a container they captured
         BEFORE awaiting their data:

             const content = Dom.el('div', { id: 'admin-tab-content' });
             wrap.appendChild(content);
             await renderFamiliesTab(content);   // appends "Loading families…", then awaits

         Wiping #appMain mid-await left `content` detached, so when the fetch resolved the
         screen rendered its rows into an orphan node — silently, because appending to a
         detached element is perfectly legal. The skeleton then sat there forever.

         It was intermittent because data-kt-pretty is only set 250ms after navigation
         (screen-v22p55 applyPrettyClass): a fast reply beat the sweep, a slow one lost to
         it — so it hit the biggest agencies most often. Reported on #admin-families,
         2026-09-01.

         Swapping only the placeholder's own contents leaves every container the screen is
         holding attached, and the screen's own Dom.clear() disposes of the skeleton when
         the data arrives. */
      /* Never the banner. Screens like Data retention put "Loading…" in the hero's own
         subtitle, so the first match is inside .kt-page-hero — and filling that with
         shimmer bars stretched the teal banner down the page. The hero is also written
         by normaliseBanners()/tidyOwnBanner(), which remove and rebuild it; a cosmetic
         sweep writing into an element another subsystem rewrites is how a render loop
         starts, and that code already needs a spend budget for the same reason. A
         subtitle reading "Loading…" is perfectly good as it stands. */
      let ph = null;
      const all = host.querySelectorAll('*');
      for (let i = 0; i < all.length; i++) {
        const el = all[i];
        if (el.children.length !== 0) continue;
        if (!/Loading|Computing|Scanning/i.test(el.textContent || '')) continue;
        if (el.closest('.kt-page-hero, .kt-hero, .page-header-v17, header, h1, h2, h3')) continue;
        ph = el; break;
      }
      /* No element of its own to take over — the text is a bare child of the host. Leave
         it alone: a plain "Loading…" is a worse look than a skeleton, and a far better one
         than a screen that never arrives. */
      if (!ph || ph.dataset.ktSkeletonDone) return;
      ph.dataset.ktSkeletonDone = '1';
      skelBudget--;
      // The placeholder centres its text in 40px of padding; the skeleton brings its own.
      ph.style.padding = '0';
      if (KT.skeleton) KT.skeleton(ph, 6);
    });
  }
  (window.KT && KT.sweepBus) ? KT.sweepBus.on(sweepLoading) : setInterval(sweepLoading, 4000);

  // ============================ Mobile sidebar toggle ============================
  function mobileSidebar() {
    // v22p79: REMOVED. This top-left ☰ button (z-index 99999) covered the
    // KiddieTrac logo, used a stale selector (.sidebar/#sidebar — the real
    // element is #appSidebar) so it did nothing, and floated above modals.
    // Mobile navigation is now the bottom-nav "More" drawer (kt-polish-v4.js).
    var ex = document.getElementById('kt-mobile-toggle');
    if (ex) ex.remove();
  }
  setTimeout(mobileSidebar, 1500);
  window.addEventListener('resize', () => setTimeout(mobileSidebar, 200));

  // ============================ Helpers ============================
  function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c])); }

  /* ============================ Search: commit, don't stream ============================

     A SEARCH box replaces what the view is showing — often by asking the server for
     it. Running that on every keystroke rearranges the page under someone who is still
     typing, and fires one request per letter. So a search is committed:

       Enter          the obvious one
       blur/change    they typed and moved on, or clicked the button beside it
       search event   the native clear (x) in a type=search field, so emptying it
                      restores the full list without needing Enter as well

     A FILTER is a different control and is deliberately NOT routed through here:
     kt-list-controls and kt-table-filter hide rows already on the screen, nothing is
     fetched or replaced, and instant is the correct behaviour there.

     One definition rather than a debounce timer per screen — eleven of those drift
     apart, which is how this codebase ended up with eight differently-named tenant
     guards.

     Returns a function that commits programmatically, for a Search button to call. */

  /* ── An invoice that is not due yet is SCHEDULED ──────────────────────────────
     Anthony, 2026-09-04: "for invoices that are not due yet and show as open mark
     these as SCHEDULED - full sweep to find all areas to make this change".

     Only statuses that read as "owed right now" convert. Deliberately excluded:
       partial  — real money has already been received against it; that is the fact
                  worth showing, and it outranks the calendar.
       draft    — not issued at all, which is a different thing from not yet due and
                  must not be hidden behind a word that sounds deliberate.
       overdue  — past its due date by definition; if one ever says otherwise the data
                  is wrong and quietly relabelling it would hide that.

     Date-only, against the AGENCY's today. A due date is a calendar day, not an
     instant: run it through a timezone conversion and it names the day before. */
  var KT_OPEN_NOW = { open: 1, sent: 1, unpaid: 1, issued: 1, outstanding: 1 };

  /* Today, worked out at most once a minute.

     This is called once PER INVOICE ROW, and KT.agencyToday() builds a fresh
     Intl.DateTimeFormat every time — measured on production at 67us a call, of which
     41us is constructing the formatter. Across a few hundred rows that is tens of
     milliseconds of pure repetition to answer a question whose answer changes once a
     day. Cached for a minute, which is far shorter than the thing it describes. */
  var _ktToday = '', _ktTodayAt = 0;
  function ktTodayCached() {
    var now = Date.now();
    if (!_ktToday || now - _ktTodayAt > 60000) {
      try { _ktToday = (KT.agencyToday && KT.agencyToday()) || ''; } catch (e) { _ktToday = ''; }
      _ktTodayAt = now;
    }
    return _ktToday;
  }


  /* ── A signature, drawn ────────────────────────────────────────────────────
     Shared because two screens capture one now (refunds from the ledger, and the
     older payment-refund screen) and a second hand-rolled copy is how the two
     drift apart.

     Pointer events rather than mouse+touch pairs: one code path for mouse, pen and
     finger, and setPointerCapture keeps the stroke attached when the finger leaves
     the canvas mid-signature. The backing store is scaled by devicePixelRatio,
     without which a signature on a phone is a blurry rectangle.

     mount() returns a handle; the caller asks isEmpty() before submitting, because
     an unsigned refund must be refused in the browser as well as on the server. */
  /* KT.signaturePadInline — NOT KT.signaturePad.

     Two different pads were both published as KT.signaturePad: this one, which mounts a
     canvas INSIDE a host element and returns a handle (isEmpty/clear/toDataURL), and
     kt-signature-pad.js, which opens a full-screen overlay and returns a Promise. Both
     files are deferred, so they run in document order and the overlay — loaded later —
     silently replaced this one for the whole page.

     Everything that asked for a handle then got a Promise. Refund approval called
     pad.clear() and pad.isEmpty() on it, threw a TypeError, and could not be completed
     at all; an unrelated signature overlay appeared over the dialog as a bonus. Three
     call sites, broken since the overlay shipped.

     Two pads is fine. Two pads with one name is not, so this one is named for what it
     is. See kt-signature-pad.js for the overlay. */
  KT.signaturePadInline = function (host, opts) {
    opts = opts || {};
    var h = opts.height || 130;
    host.innerHTML = '';
    host.style.cssText = 'position:relative;border:1px dashed #CBD5E1;border-radius:10px;'
      + 'background:#FFFFFF;overflow:hidden;';

    var cv = document.createElement('canvas');
    cv.style.cssText = 'display:block;width:100%;height:' + h + 'px;touch-action:none;cursor:crosshair;';
    host.appendChild(cv);

    var hint = document.createElement('div');
    hint.textContent = opts.hint || 'Sign here';
    hint.style.cssText = 'position:absolute;inset:0;display:flex;align-items:center;'
      + 'justify-content:center;color:#94A3B8;font-size:13px;pointer-events:none;';
    host.appendChild(hint);

    var ctx, drawn = false, drawing = false;

    function size() {
      var r = Math.max(1, window.devicePixelRatio || 1);
      var w = cv.clientWidth || host.clientWidth || 320;
      // Resizing the canvas CLEARS it, so an already-drawn signature is preserved.
      var keep = drawn ? cv.toDataURL() : null;
      cv.width = Math.round(w * r);
      cv.height = Math.round(h * r);
      ctx = cv.getContext('2d');
      ctx.scale(r, r);
      ctx.lineWidth = 2;
      ctx.lineCap = 'round';
      ctx.lineJoin = 'round';
      ctx.strokeStyle = '#0F172A';
      if (keep) {
        var img = new Image();
        img.onload = function () { ctx.drawImage(img, 0, 0, w, h); };
        img.src = keep;
      }
    }
    size();
    var ro = null;
    try { ro = new ResizeObserver(size); ro.observe(host); } catch (e) {}

    function at(e) {
      var b = cv.getBoundingClientRect();
      return { x: e.clientX - b.left, y: e.clientY - b.top };
    }
    cv.addEventListener('pointerdown', function (e) {
      drawing = true;
      try { cv.setPointerCapture(e.pointerId); } catch (x) {}
      var p = at(e);
      ctx.beginPath();
      ctx.moveTo(p.x, p.y);
      e.preventDefault();
    });
    cv.addEventListener('pointermove', function (e) {
      if (!drawing) return;
      var p = at(e);
      ctx.lineTo(p.x, p.y);
      ctx.stroke();
      if (!drawn) { drawn = true; hint.style.display = 'none'; }
      e.preventDefault();
    });
    function stop(e) {
      if (!drawing) return;
      drawing = false;
      try { cv.releasePointerCapture(e.pointerId); } catch (x) {}
    }
    cv.addEventListener('pointerup', stop);
    cv.addEventListener('pointercancel', stop);
    cv.addEventListener('pointerleave', stop);

    return {
      isEmpty: function () { return !drawn; },
      clear: function () {
        ctx.clearRect(0, 0, cv.width, cv.height);
        drawn = false;
        hint.style.display = '';
      },
      /* PNG on white: the server stores it as a data URI and it is rendered back into
         a PDF, where a transparent background prints as a black block in some viewers. */
      toDataURL: function () {
        var out = document.createElement('canvas');
        out.width = cv.width; out.height = cv.height;
        var o = out.getContext('2d');
        o.fillStyle = '#FFFFFF';
        o.fillRect(0, 0, out.width, out.height);
        o.drawImage(cv, 0, 0);
        return out.toDataURL('image/png');
      },
      destroy: function () { try { if (ro) ro.disconnect(); } catch (e) {} },
    };
  };

  KT.invoiceStatus = function (status, dueAt) {
    var s = String(status == null ? '' : status).trim().toLowerCase();
    if (!KT_OPEN_NOW[s] || !dueAt) return s;
    try {
      var due = String(dueAt).trim().slice(0, 10);          // YYYY-MM-DD, no conversion
      if (!/^\d{4}-\d{2}-\d{2}$/.test(due)) return s;
      var today = ktTodayCached();
      if (!today) return s;                                  // unknown today: say nothing new
      return due > today ? 'scheduled' : s;
    } catch (e) { return s; }
  };

  /** The same answer as a yes/no, for callers that only need to style a row. */
  KT.isScheduled = function (status, dueAt) {
    return KT.invoiceStatus(status, dueAt) === 'scheduled';
  };

  /* ONE INVOICE STATUS PILL, FOR EVERY SCREEN THAT SHOWS ONE (2026-09-17).

     Anthony: "use colourized pills for status on accounting and payment schedules to make
     it easier to read."

     Accounting and the payment schedules each had their own map. They had already drifted
     — one said "Partial" where the other said "Partly paid", one rendered a grey inline
     span for a draft and the other a blue `kt-pill-info` — so the same invoice looked like
     two different states depending on which screen you opened. A third copy was about to
     be written for the pills, which is how the first two happened.

     It lives here beside KT.invoiceStatus because that is where the status VOCABULARY
     already lives: this is the same answer in colour.

     THE COLOURS CARRY THE MEANING, not just decoration:
       red    something is late
       amber  money has partly moved, or come back
       blue   issued and owed
       indigo issued, not due yet — calm, because nothing is wrong
       slate  nothing has happened yet
       green  settled
       grey   withdrawn

     Callers pass the raw status and the due date; the scheduled/overdue distinction is
     derived here rather than by each screen ([[kiddietrac-invoice-scheduled-status]]). */
  var KT_PILL = {
    paid:      { bg: '#DCFCE7', fg: '#166534', bd: '#86EFAC', t: 'Paid' },
    partial:   { bg: '#FEF3C7', fg: '#92400E', bd: '#FCD34D', t: 'Partly paid' },
    refunded:  { bg: '#FEF3C7', fg: '#92400E', bd: '#FCD34D', t: 'Refunded' },
    overdue:   { bg: '#FEE2E2', fg: '#991B1B', bd: '#FCA5A5', t: 'Overdue' },
    scheduled: { bg: '#EEF2FF', fg: '#3730A3', bd: '#C7D2FE', t: 'Scheduled' },
    draft:     { bg: '#F1F5F9', fg: '#475569', bd: '#CBD5E1', t: 'Pending' },
    upcoming:  { bg: '#F1F5F9', fg: '#475569', bd: '#CBD5E1', t: 'Upcoming' },
    void:      { bg: '#E5E7EB', fg: '#4B5563', bd: '#D1D5DB', t: 'Void' },
    cancelled: { bg: '#E5E7EB', fg: '#4B5563', bd: '#D1D5DB', t: 'Void' },
    // Four spellings of one state, across the three tables the ledger unions.
    sent:        { bg: '#E0F2FE', fg: '#075985', bd: '#7DD3FC', t: 'Issued' },
    open:        { bg: '#E0F2FE', fg: '#075985', bd: '#7DD3FC', t: 'Issued' },
    unpaid:      { bg: '#E0F2FE', fg: '#075985', bd: '#7DD3FC', t: 'Issued' },
    issued:      { bg: '#E0F2FE', fg: '#075985', bd: '#7DD3FC', t: 'Issued' },
    outstanding: { bg: '#E0F2FE', fg: '#075985', bd: '#7DD3FC', t: 'Issued' },
  };

  /** The resolved {bg,fg,bd,t} for a status, never null. */
  KT.invoicePillSpec = function (status, dueAt) {
    var s = KT.invoiceStatus(status, dueAt);
    var hit = KT_PILL[s];
    if (hit) { return hit; }

    /* A status nobody anticipated still reads as a label. Printing the raw database
       value is what made lowercase `sent` and `draft` sit next to a proper "Paid". */
    var label = String(status == null ? '' : status).replace(/[_-]+/g, ' ').trim();
    label = label ? label.charAt(0).toUpperCase() + label.slice(1) : '—';

    return { bg: '#F1F5F9', fg: '#475569', bd: '#CBD5E1', t: label };
  };

  /** Ready-to-insert HTML. `title` adds a tooltip; `min` sets a minimum width so a
   *  column of pills lines up instead of ragging by word length. */
  KT.invoicePill = function (status, dueAt, opts) {
    var b = KT.invoicePillSpec(status, dueAt);
    var o = opts || {};
    var esc = function (v) {
      return String(v == null ? '' : v).replace(/[&<>"]/g, function (c) {
        return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c];
      });
    };

    return '<span class="kt-inv-pill"' + (o.title ? ' title="' + esc(o.title) + '"' : '')
      + ' style="display:inline-block;background:' + b.bg + ';color:' + b.fg
      + ';border:1px solid ' + b.bd + ';padding:2px 10px;border-radius:999px;font-size:11px;'
      + 'font-weight:800;letter-spacing:.02em;white-space:nowrap;text-align:center;'
      + (o.min === false ? '' : 'min-width:74px;')
      + '">' + esc(b.t) + '</span>';
  };

  KT.onSearchCommit = function (input, run) {
    if (!input || typeof run !== 'function') { return function () {}; }
    var last = null;

    function commit() {
      var v = input.value;
      /* Committing the same text twice is a no-op. Enter, then blur on the way to
         clicking something, would otherwise run the same search twice. */
      if (v === last) { return; }
      last = v;
      run(v);
    }

    input.addEventListener('keydown', function (e) {
      if (e.key === 'Enter') { e.preventDefault(); commit(); }
    });
    input.addEventListener('change', commit);
    // Fires on the native clear (x). Harmless where unsupported.
    input.addEventListener('search', commit);

    return commit;
  };

  /* A magnifier button that commits the search beside it. Screens that want a visible
     control rather than only Enter get the same one, so it looks the same everywhere. */
  KT.searchButton = function (commit, label) {
    var b = document.createElement('button');
    b.type = 'button';
    b.textContent = label || '🔍 Search';
    b.title = 'Search (or press Enter)';
    b.style.cssText = 'padding:8px 12px;border:1px solid #CBD5E1;background:#fff;color:#334155;'
      + 'border-radius:8px;font-size:13px;font-weight:700;cursor:pointer;white-space:nowrap;';
    b.addEventListener('click', function () { commit(); });

    return b;
  };

  /* ═════════════════════════════════════════════════════════════════
     KT.LedgerUI — the account-ledger visual language, shared.

     screen-account-ledgers.js defined these for itself and they are the look
     My Pay was asked to match. One definition, so a change to the tile or the
     palette reaches both screens instead of only the one being edited.
     ═════════════════════════════════════════════════════════════════ */
  var LC = {
    ink: '#0F172A', muted: '#64748B', faint: '#94A3B8', line: '#EEF2F6', rule: '#E2E8F0',
    accent: '#2563EB', good: '#16A34A', warn: '#B45309', bad: '#B91C1C', out: '#4338CA'
  };

  function lesc(s) {
    return String(s == null ? '' : s).replace(/[&<>"]/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c];
    });
  }

  /* en-CA is PINNED. `undefined` follows the browser's locale, so the same CAD
     amount renders '$1,234.00' for one member of staff and 'CA$1,234.00' for the
     next. */
  function lmoney(n) {
    n = Number(n) || 0;
    try { return new Intl.NumberFormat('en-CA', { style: 'currency', currency: 'CAD' }).format(n); }
    catch (e) { return '$' + n.toFixed(2); }
  }
  function lmoney0(n) {
    n = Number(n) || 0;
    try { return new Intl.NumberFormat('en-CA', { style: 'currency', currency: 'CAD', maximumFractionDigits: 0 }).format(n); }
    catch (e) { return '$' + Math.round(n); }
  }

  /* A KPI is a number AND its shape: a figure with no sub-line makes the reader
     work out for themselves whether it is good news. Every tile says what it means. */
  function lkpi(label, value, tint, sub) {
    /* min-width:0 — a grid child defaults to min-width:auto, so anything wide inside
       widens the TRACK instead of scrolling in its own box. */
    return '<div class="kt-card" style="min-width:0;padding:14px 16px;border-left:3px solid ' + tint + ';">'
      + '<div style="font-size:10px;font-weight:800;letter-spacing:.6px;text-transform:uppercase;color:' + LC.muted + ';">' + lesc(label) + '</div>'
      + '<div style="font-size:20px;font-weight:800;margin-top:5px;color:' + tint + ';font-variant-numeric:tabular-nums;">' + lesc(value) + '</div>'
      + (sub ? '<div style="font-size:11.5px;color:' + LC.muted + ';margin-top:3px;">' + sub + '</div>' : '')
      + '</div>';
  }

  function lcard(title, hint, inner) {
    return '<div class="kt-card" style="min-width:0;padding:16px 18px;">'
      + '<div style="display:flex;align-items:baseline;justify-content:space-between;gap:12px;flex-wrap:wrap;margin-bottom:10px;">'
      + '<h3 style="margin:0;font-size:14px;font-weight:800;color:' + LC.ink + ';">' + lesc(title) + '</h3>'
      + (hint ? '<span style="font-size:11.5px;color:' + LC.muted + ';">' + hint + '</span>' : '')
      + '</div>' + inner + '</div>';
  }

  function lempty(text) {
    return '<div style="padding:26px;text-align:center;color:' + LC.faint + ';font-size:13px;">' + lesc(text) + '</div>';
  }

  /* Responsive without a media query: auto-fit collapses the track count as the box
     narrows, so four tiles become two and then one on a phone. */
  function lgrid(cells, min) {
    return '<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(' + (min || 190) + 'px,1fr));gap:12px;">'
      + cells.join('') + '</div>';
  }

  /* One series of bars — "how much, week by week". Hand-rolled, the house pattern:
     this is not worth a charting library on a boot that already parses 162 files.

     Each bar carries a title for hover AND its value is readable from the axis label,
     because hover does not exist on the phone this is mostly read on. */
  function lbars(points, opts) {
    opts = opts || {};
    if (!points || !points.length) { return lempty(opts.emptyText || 'Nothing to chart yet.'); }
    var H = opts.height || 132;
    var tint = opts.tint || LC.accent;
    var fmtV = opts.format || lmoney;
    var max = 0;
    points.forEach(function (p) { max = Math.max(max, Number(p.value) || 0); });
    if (max <= 0) { max = 1; }

    var bars = points.map(function (p) {
      var v = Number(p.value) || 0;
      // A non-zero value always gets at least 2px, or a small week reads as nothing.
      var h = Math.max((v / max) * H, v > 0 ? 2 : 0);
      return '<div style="flex:1 1 0;min-width:0;display:flex;flex-direction:column;align-items:center;gap:5px;">'
        + '<div style="height:' + H + 'px;display:flex;align-items:flex-end;width:100%;justify-content:center;" '
        + 'title="' + lesc(p.label + ' — ' + fmtV(v)) + '">'
        + '<div style="width:72%;max-width:26px;height:' + h.toFixed(1) + 'px;background:' + tint + ';'
        + 'opacity:' + (p.dim ? '.4' : '.85') + ';border-radius:3px 3px 0 0;"></div>'
        + '</div>'
        + '<div style="font-size:9.5px;color:' + LC.muted + ';white-space:nowrap;">' + lesc(p.label) + '</div>'
        + '</div>';
    }).join('');

    return '<div style="overflow-x:auto;"><div style="display:flex;align-items:flex-end;gap:5px;'
      + 'border-bottom:1px solid ' + LC.rule + ';padding-bottom:6px;min-width:' + (points.length * 34) + 'px;">'
      + bars + '</div></div>';
  }

  KT.LedgerUI = {
    C: LC, esc: lesc, money: lmoney, money0: lmoney0,
    kpi: lkpi, card: lcard, empty: lempty, grid: lgrid, bars: lbars
  };

  // Expose helpers
  KT.csv = exportTableAsCsv;
  // NOT KT.fmtDate: kt-tz.js owns that name and resolves the agency zone from
  // /auth/me. This file loads AFTER kt-tz.js, so exporting it here overwrote the
  // canonical helper with this cell-formatter and quietly pushed every caller
  // back onto the device timezone. Exposed under its own name instead.
  KT.fmtCellDate = fmtDateMaybe;
})(window);
