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
    o.style.cssText = 'position:fixed;inset:0;background:rgba(15,23,42,0.5);backdrop-filter:blur(2px);z-index:9999999;display:flex;align-items:center;justify-content:center;animation:kt-fade-in .15s ease-out;';
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
    ths.forEach((th, colIdx) => {
      const text = (th.textContent || '').trim();
      if (!text || text === '') return;
      if (th.querySelector('button')) return; // action header
      th.style.cursor = 'pointer';
      th.style.userSelect = 'none';
      // Add a sort indicator span
      const indicator = document.createElement('span');
      indicator.className = 'kt-sort-indicator';
      indicator.style.cssText = 'opacity:0.3;margin-left:6px;font-size:10px;';
      indicator.textContent = '⇅';
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

        const rows = Array.from(tbody.children);
        rows.sort((a, b) => {
          const av = cellSortKey(a.children[colIdx]);
          const bv = cellSortKey(b.children[colIdx]);
          if (av < bv) return dir === 'asc' ? -1 : 1;
          if (av > bv) return dir === 'asc' ? 1 : -1;
          return 0;
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

  function cellSortKey(td) {
    if (!td) return '';
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

  // Expose helpers
  KT.csv = exportTableAsCsv;
  // NOT KT.fmtDate: kt-tz.js owns that name and resolves the agency zone from
  // /auth/me. This file loads AFTER kt-tz.js, so exporting it here overwrote the
  // canonical helper with this cell-formatter and quietly pushed every caller
  // back onto the device timezone. Exposed under its own name instead.
  KT.fmtCellDate = fmtDateMaybe;
})(window);
