/* v22p62 — polish round 2.
   Bulk-select + pagination + keyboard shortcuts + button spinners +
   print CSS + inline form validation. All auto-attached. */
(function (window) {
  'use strict';
  if (window.KT_POLISH_V2_LOADED) return;
  window.KT_POLISH_V2_LOADED = true;
  const KT = window.KT = window.KT || {};

  // ============================ Print stylesheet ============================
  if (!document.getElementById('kt-print-css')) {
    const s = document.createElement('style');
    s.id = 'kt-print-css';
    s.textContent = `@media print {
      body { background: #fff !important; }
      .sidebar, .kt-sidebar, #sidebar, header, .kt-page-hero .kt-hero-actions,
      .kt-table-filter input, .kt-csv-btn, button, #kt-mobile-toggle,
      #kt-toast-host { display: none !important; }
      .kt-page-hero { background: #fff !important; color: #000 !important; padding: 0 0 14px !important; box-shadow: none !important; }
      .kt-page-hero h2 { color: #000 !important; }
      .kt-card, [data-kt-pretty] table { box-shadow: none !important; border: 1px solid #ddd !important; page-break-inside: avoid; }
      [data-kt-pretty] table thead th { background: #f5f5f5 !important; color: #000 !important; }
      [data-kt-pretty] { background: #fff !important; }
      main { margin: 0 !important; padding: 14px !important; }
      a { color: #000 !important; text-decoration: none !important; }
    }`;
    document.head.appendChild(s);
  }

  // ============================ Bulk select on tables ============================
  function attachBulkSelect(table) {
    // Opt-out, matching the data-kt-no-* family the other primitives use. Some tables
    // are a layout rather than a list of records — the weekly menu grid is five meals
    // by five days of inputs, and there is no bulk action for "breakfast and lunch".
    if (table.hasAttribute('data-kt-no-bulk')) return;
    if (table.dataset.ktBulk) return;
    table.dataset.ktBulk = '1';
    const tbody = table.querySelector('tbody');
    const thead = table.querySelector('thead');
    if (!tbody || !thead || tbody.children.length < 2) return;

    // Heuristic: skip tables that are clearly "view-only" — no per-row actions,
    // no row IDs we could act on. We attach to any table with >= 3 data rows.
    if (tbody.children.length < 3) return;
    // Skip empty-state rows
    const first = tbody.children[0];
    if (first.children.length === 1 && first.querySelector('td').colSpan > 1) return;

    // Insert a checkbox column header
    const headerRow = thead.querySelector('tr');
    if (!headerRow) return;
    // v22p83: Don't double-decorate. Some screens (e.g. User management's
    // renderUsersTab) already render their own select-all column. Adding ours
    // on top produced two side-by-side checkbox columns. Bail if the header
    // already has a checkbox of its own.
    if (headerRow.querySelector('input[type="checkbox"]')) return;
    const checkboxTh = document.createElement('th');
    checkboxTh.style.cssText = 'width:32px;padding:8px 0 8px 14px;';
    const headerCheckbox = document.createElement('input');
    headerCheckbox.type = 'checkbox';
    headerCheckbox.style.cssText = 'width:16px;height:16px;cursor:pointer;';
    headerCheckbox.title = 'Select all visible';
    checkboxTh.appendChild(headerCheckbox);
    headerRow.insertBefore(checkboxTh, headerRow.firstChild);

    // Insert checkbox cells per row
    Array.from(tbody.children).forEach((row, idx) => {
      if (row.children.length === 1 && row.querySelector('td').colSpan > 1) return; // empty-state
      const td = document.createElement('td');
      td.style.cssText = 'width:32px;padding:8px 0 8px 14px;';
      const cb = document.createElement('input');
      cb.type = 'checkbox';
      cb.className = 'kt-row-check';
      cb.style.cssText = 'width:16px;height:16px;cursor:pointer;';
      cb.addEventListener('change', updateActionBar);
      td.appendChild(cb);
      row.insertBefore(td, row.firstChild);
    });

    // Update colspan on empty-state rows (if any appear later)
    function fixEmptyState() {
      Array.from(tbody.children).forEach(row => {
        if (row.children.length === 1) {
          const td = row.children[0];
          if (td.colSpan > 1) td.colSpan++;
        }
      });
    }
    fixEmptyState();

    headerCheckbox.addEventListener('change', () => {
      Array.from(tbody.querySelectorAll('.kt-row-check')).forEach(cb => {
        if (cb.closest('tr').style.display !== 'none') cb.checked = headerCheckbox.checked;
      });
      updateActionBar();
    });

    // Action bar (initially hidden)
    const bar = document.createElement('div');
    bar.className = 'kt-bulk-bar';
    bar.style.cssText = 'display:none;background:linear-gradient(180deg,#7C3AED,#5B21B6);color:#fff;padding:12px 18px;border-radius:10px;margin-bottom:14px;align-items:center;justify-content:space-between;box-shadow:0 6px 20px rgba(124,58,237,.3);animation:kt-bar-in .2s ease-out;';
    bar.innerHTML = `
      <div style="display:flex;align-items:center;gap:12px;">
        <span style="font-size:18px;">☑</span>
        <span class="kt-bulk-count" style="font-weight:700;">0 selected</span>
      </div>
      <div style="display:flex;gap:8px;align-items:center;" class="kt-bulk-btns">
        <span class="kt-bulk-custom" style="display:flex;gap:8px;"></span>
        <button class="kt-bulk-csv" style="background:rgba(255,255,255,.18);color:#fff;border:0;padding:7px 14px;border-radius:6px;cursor:pointer;font-weight:600;font-size:13px;">⤓ Export selected</button>
        <button class="kt-bulk-clear" style="background:rgba(255,255,255,.16);color:#fff;border:0;padding:7px 14px;border-radius:6px;cursor:pointer;font-size:13px;">Clear</button>
      </div>`;
    if (!document.getElementById('kt-bar-css')) {
      const s = document.createElement('style');
      s.id = 'kt-bar-css';
      s.textContent = '@keyframes kt-bar-in { from { opacity: 0; transform: translateY(-6px); } to { opacity: 1; transform: translateY(0); } }';
      document.head.appendChild(s);
    }
    table.parentElement.insertBefore(bar, table);

    /* A SCREEN'S OWN BULK ACTIONS (2026-09-17).

       Anthony: "when selecting multiple rows under payment schedule have an option to
       issue all now, delete all, download all, same goes for the accounting section."

       The bar only ever offered Export and Clear, which are generic. A screen now sets
       `table.ktBulkActions = [{label, danger, run(rows)}]` and its buttons appear here.

       Read on every selection change rather than once when the bar is built, because the
       sweep that builds the bar and the render that knows the actions do not have a fixed
       order — attaching them at build time worked or silently did nothing depending on
       which ran first. */
    function syncCustomActions() {
      const host = bar.querySelector('.kt-bulk-custom');
      const acts = table.ktBulkActions || [];
      const sig = acts.map(a => a.label).join('|');
      if (host.dataset.sig === sig) { return; }
      host.dataset.sig = sig;
      host.innerHTML = '';
      acts.forEach(function (a) {
        const b = document.createElement('button');
        b.textContent = a.label;
        b.style.cssText = 'background:' + (a.danger ? 'rgba(220,38,38,.9)' : 'rgba(255,255,255,.18)')
          + ';color:#fff;border:0;padding:7px 14px;border-radius:6px;cursor:pointer;font-weight:600;font-size:13px;';
        b.onclick = async function () {
          const rows = Array.from(tbody.querySelectorAll('.kt-row-check:checked'))
            .map(cb => cb.closest('tr'));
          if (!rows.length) { return; }
          const was = b.textContent;
          b.disabled = true; b.textContent = 'Working…';
          try { await a.run(rows); } catch (e) { /* the action reports its own failure */ }
          b.disabled = false; b.textContent = was;
        };
        host.appendChild(b);
      });
    }

    function updateActionBar() {
      const checked = Array.from(tbody.querySelectorAll('.kt-row-check:checked'));
      const n = checked.length;
      syncCustomActions();
      bar.querySelector('.kt-bulk-count').textContent = n + ' selected';
      bar.style.display = n > 0 ? 'flex' : 'none';
      // Header checkbox indeterminate
      const total = Array.from(tbody.querySelectorAll('.kt-row-check')).filter(cb => cb.closest('tr').style.display !== 'none').length;
      headerCheckbox.checked = n === total && n > 0;
      headerCheckbox.indeterminate = n > 0 && n < total;
    }
    bar.querySelector('.kt-bulk-clear').onclick = () => {
      Array.from(tbody.querySelectorAll('.kt-row-check')).forEach(cb => cb.checked = false);
      updateActionBar();
    };
    bar.querySelector('.kt-bulk-csv').onclick = () => {
      // Reuse the kt-polish CSV export but with only checked rows
      const csv = [];
      const headers = Array.from(thead.querySelectorAll('th'))
        .slice(1) // skip the checkbox column
        .map(th => { const i = th.querySelector('.kt-sort-indicator'); if (i) i.remove(); return (th.textContent || '').trim().replace(/\s+/g, ' '); });
      csv.push(headers.map(csvCell).join(','));
      Array.from(tbody.querySelectorAll('.kt-row-check:checked')).forEach(cb => {
        const row = cb.closest('tr');
        const cells = Array.from(row.children).slice(1).map(td => {
          if (td.dataset.ktIso) return td.dataset.ktIso;
          return (td.textContent || '').trim().replace(/\s+/g, ' ');
        });
        csv.push(cells.map(csvCell).join(','));
      });
      const blob = new Blob(['﻿' + csv.join('\n')], { type: 'text/csv;charset=utf-8' });
      const a = document.createElement('a');
      a.href = URL.createObjectURL(blob);
      a.download = 'selected-rows-' + new Date().toISOString().slice(0, 10) + '.csv';
      a.click();
      if (KT.toast) KT.toast('Exported ' + (csv.length - 1) + ' row(s)', 'success');
    };
  }
  function csvCell(s) {
    s = String(s == null ? '' : s);
    if (/[",\n]/.test(s)) return '"' + s.replace(/"/g, '""') + '"';
    return s;
  }

  // ============================ Pagination for >200-row tables ============================
  function attachPagination(table) {
    if (table.dataset.ktPaginated) return;
    const tbody = table.querySelector('tbody');
    if (!tbody) return;
    const rows = Array.from(tbody.children).filter(r => !(r.children.length === 1 && r.querySelector('td').colSpan > 1));

    /* OPT-IN PAGE SIZE: data-kt-paginate="25" on the table.

       The 200-row / 100-per-page rule below is a safety net for tables nobody sized on
       purpose — it stops a runaway list, it does not make anything readable. A screen
       that KNOWS its rows are long (a signed document per line, with a signer and a
       timestamp) wants far fewer than a hundred at a time, and wants them paged from the
       first screenful rather than only once there are two hundred.

       Reusing this rather than hand-rolling a pager per screen: there is one pager in the
       portal, one set of controls, one behaviour — and it already cooperates with the
       filter sweep through data-ktFilterHidden, which a bespoke one would quietly break.
       (Anthony, 2026-09-09) */
    const optIn = parseInt(table.getAttribute('data-kt-paginate') || '', 10);
    const wanted = (optIn > 0) ? optIn : 0;
    if (!wanted && rows.length < 200) return;
    if (wanted && rows.length <= wanted) return;   // one page is not a pager
    table.dataset.ktPaginated = '1';

    const PAGE_SIZE = wanted || 100;
    let currentPage = 1;
    const totalPages = Math.ceil(rows.length / PAGE_SIZE);

    const pager = document.createElement('div');
    pager.className = 'kt-pager';
    pager.style.cssText = 'display:flex;justify-content:space-between;align-items:center;gap:10px;flex-wrap:wrap;margin-top:14px;font-size:13px;color:#475569;';
    /* Same bar as every other pager in the portal (KT.pagerBar, kt-card-pager.js),
       not the « ‹ [n] of N › » strip this used to draw for itself. */
    pager.innerHTML = '<div class="kt-pager-info"></div><div class="kt-pager-nav"></div>';
    table.parentElement.insertBefore(pager, table.nextSibling);

    function render() {
      const start = (currentPage - 1) * PAGE_SIZE;
      const end = start + PAGE_SIZE;
      rows.forEach((r, i) => {
        if (i >= start && i < end) r.dataset.ktPageHidden = ''; else r.dataset.ktPageHidden = '1';
        if (r.dataset.ktPageHidden) r.style.display = 'none';
        else if (r.dataset.ktFilterHidden !== '1') r.style.display = '';
      });
      pager.querySelector('.kt-pager-info').textContent = `Showing ${start + 1}–${Math.min(end, rows.length)} of ${rows.length}`;
      if (window.KT && KT.pagerBar) KT.pagerBar(pager.querySelector('.kt-pager-nav'), currentPage, totalPages, (p) => { currentPage = p; render(); });
    }
    render();
  }

  // ============================ Button spinner during async clicks ============================
  function attachButtonSpinners() {
    document.addEventListener('click', (e) => {
      const btn = e.target.closest('button');
      if (!btn) return;
      if (btn.dataset.ktSpinning) return;
      // Only attach to buttons that are likely to trigger async work
      const text = (btn.textContent || '').toLowerCase();
      /* \b MATTERS, and its absence cost a week. Without it this alternation matches a
         verb ANYWHERE inside the label, so "Bruni Meeser" — b·r·u·n·i — matched "run",
         and every tap on that provider in the mobile select sheet disabled her row
         mid-click, leaving a dropdown that silently did nothing.

         Leading boundary only, never trailing: "Uploading" and "Sending" are real async
         labels and would not survive \b on the end. (Anthony, 2026-09-07) */
      if (!/\b(?:save|submit|send|generate|upload|create|charge|refund|run|approve|deny|publish|sign|book|claim|add|delete|remove|sync|push|extract|tag)/.test(text)) return;
      // Skip cancel-style buttons
      if (/cancel|close|back|×/i.test(text)) return;
      /* ATTENDANCE ACTIONS. "Sign in"/"Sign out" match sign in the list above, so this
         pass disabled them and rewrote their innerHTML for up to 2.5s — and a button that
         is already disabled when clicked receives NOTHING (measured). A second tap inside
         that window was silently swallowed, which on a phone is exactly what a tap that
         "did nothing" invites. They do no async work themselves either: they open a
         confirmation, and the request happens only after the user confirms. Safe to
         exclude — this file is not loaded on the login page, so its own Sign in button is
         untouched. (Anthony, 2026-09-08) */
      if (/(sign|check)\s*(in|out)/i.test(text)) return;
      // v22p87: do NOT spin buttons that merely OPEN a modal or toggle a panel.
      // They stay in the DOM with no async work to finish, so the spinner used
      // to run for the full 8s — the "circle keeps spinning" bug (e.g. Waitlist
      // "+ Add", the sidebar "⚡ Quick add" header/items). Skip "+"-prefixed
      // creation buttons and anything inside the sidebar / nav / quick-add menu.
      if (/^[+＋➕]/.test((btn.textContent || '').trim())) return;
      /* And never a dropdown row. The mobile select/date sheets render each <option>
         as a <button>: it does no async work, it is destroyed when the sheet closes, and
         disabling it can only break the control — which is precisely what happened. The
         \b fix above stops today's collision; this stops the next one, because a
         provider, room or child called "Sandy", "Brunt" or "Tagg" would trip the same
         wire. */
      if (btn.closest('.kt-select-sheet, .kt-date-sheet')) return;
      if (btn.closest('.app-sidebar, .kt-sidebar, nav, .kt-v5a-actions, .kt-quickadd-menu, .kt-qa-menu, #kt-quickadd-menu')) return;

      const original = btn.innerHTML;
      const wasDisabled = btn.disabled;
      btn.dataset.ktSpinning = '1';
      btn.dataset.ktOriginal = original;
      btn.disabled = true;
      btn.style.opacity = '0.7';
      btn.style.cursor = 'wait';
      btn.innerHTML = '<span style="display:inline-block;width:14px;height:14px;border:2px solid currentColor;border-right-color:transparent;border-radius:50%;animation:kt-spin .7s linear infinite;vertical-align:-2px;margin-right:8px;"></span>' + original;

      let _restored = false;
      const restore = () => {
        if (_restored) return;
        _restored = true;
        btn.disabled = wasDisabled;
        btn.style.opacity = '';
        btn.style.cursor = '';
        if (btn.dataset.ktOriginal) btn.innerHTML = btn.dataset.ktOriginal;
        delete btn.dataset.ktSpinning;
        delete btn.dataset.ktOriginal;
      };
      // Restore when: the button is removed (re-render), a modal/overlay opens
      // (the click's job was to open it), or a 2.5s safety timeout.
      const observer = new MutationObserver((muts) => {
        if (!document.body.contains(btn)) { restore(); observer.disconnect(); return; }
        for (let i = 0; i < muts.length; i++) {
          const added = muts[i].addedNodes;
          for (let j = 0; j < added.length; j++) {
            const n = added[j];
            if (n.nodeType !== 1) continue;
            if ((n.className && /overlay|backdrop|modal/i.test(String(n.className))) ||
                (n.querySelector && n.querySelector('.modal, .modal-backdrop, [role="dialog"], .kt-qa-overlay'))) {
              restore(); observer.disconnect(); return;
            }
          }
        }
      });
      observer.observe(document.body, { childList: true, subtree: true });
      setTimeout(() => { restore(); observer.disconnect(); }, 2500);
    }, true);
    if (!document.getElementById('kt-spin-css')) {
      const s = document.createElement('style');
      s.id = 'kt-spin-css';
      s.textContent = '@keyframes kt-spin { to { transform: rotate(360deg); } }';
      document.head.appendChild(s);
    }
  }

  // ============================ Keyboard shortcuts ============================
  document.addEventListener('keydown', (e) => {
    // Don't capture if user is typing in an input
    const t = e.target;
    if (t.tagName === 'INPUT' || t.tagName === 'TEXTAREA' || t.contentEditable === 'true') {
      // Allow Esc to clear filter inputs
      if (e.key === 'Escape' && t.classList && t.classList.contains('kt-table-filter-input')) {
        t.value = '';
        t.dispatchEvent(new Event('input'));
      }
      return;
    }
    // `/` to focus first table filter
    if (e.key === '/' && !e.metaKey && !e.ctrlKey) {
      const filter = document.querySelector('[data-kt-pretty] .kt-table-filter-input');
      if (filter) {
        e.preventDefault();
        filter.focus();
      }
    }
    // `?` to show help
    if (e.key === '?' && e.shiftKey) {
      e.preventDefault();
      showShortcutsHelp();
    }
  });
  function showShortcutsHelp() {
    if (document.getElementById('kt-shortcut-help')) return;
    const o = document.createElement('div');
    o.id = 'kt-shortcut-help';
    o.style.cssText = 'position:fixed;inset:0;background:rgba(15,23,42,0.6);z-index:9999999;display:flex;align-items:center;justify-content:center;';
    o.innerHTML = `<div style="background:#fff;padding:28px;border-radius:14px;max-width:440px;width:92%;">
      <h3 style="margin:0 0 18px;color:#0F172A;">⌨ Keyboard shortcuts</h3>
      <div style="display:grid;grid-template-columns:auto 1fr;gap:10px 18px;font-size:14px;">
        <kbd style="background:#F1F5F9;padding:3px 9px;border-radius:5px;font-family:ui-monospace,monospace;font-size:12px;">⌘K</kbd><span>Open command palette</span>
        <kbd style="background:#F1F5F9;padding:3px 9px;border-radius:5px;font-family:ui-monospace,monospace;font-size:12px;">/</kbd><span>Focus table filter</span>
        <kbd style="background:#F1F5F9;padding:3px 9px;border-radius:5px;font-family:ui-monospace,monospace;font-size:12px;">Esc</kbd><span>Clear filter / close modal</span>
        <kbd style="background:#F1F5F9;padding:3px 9px;border-radius:5px;font-family:ui-monospace,monospace;font-size:12px;">?</kbd><span>This help</span>
      </div>
      <button id="ks-close" style="background:#1F6080;color:#fff;border:0;padding:10px 18px;border-radius:8px;margin-top:18px;cursor:pointer;font-weight:600;">Close</button>
    </div>`;
    document.body.appendChild(o);
    o.querySelector('#ks-close').onclick = () => o.remove();
    o.addEventListener('keydown', (e) => { if (e.key === 'Escape') o.remove(); });
  }

  // ============================ Sweep + auto-attach ============================
  function sweep() {
    document.querySelectorAll('[data-kt-pretty] table').forEach(t => {
      attachBulkSelect(t);
      attachPagination(t);
    });
  }
  /* EXPOSED, because a screen that repaints its table without changing the hash gets no
     sweep otherwise (2026-09-17).

     The bulk-select checkboxes never appeared on the payment schedules table: picking a
     family re-renders it in place, which fires no hashchange and does not necessarily
     land on a bus tick, so the freshly-built table was never swept. Same shape as the
     in-screen TAB problem ([[kiddietrac-enhance-tables]]) and solved the same way —
     kt-row-actions already exposes KT.sweepRowActions for exactly this, and a screen
     calls it after an async render. This is that lever for the table primitives. */
  window.KT = window.KT || {};
  KT.sweepTables = sweep;

  window.addEventListener('hashchange', () => setTimeout(sweep, 450));
  (window.KT && KT.sweepBus) ? KT.sweepBus.on(sweep) : setInterval(sweep, 4000);
  setTimeout(sweep, 1000);
  attachButtonSpinners();

  // Expose helpers
  KT.shortcuts = showShortcutsHelp;
})(window);
