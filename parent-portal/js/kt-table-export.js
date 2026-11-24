/* ═══════════════════════════════════════════════════════════════════
   KiddieTrac — bottom export bar for every data table (2026-07-08)
   Adds a consistent CSV / Excel / PDF bar under each #appMain table (like the
   Reports section). CSV is built client-side from the visible rows; Excel + PDF
   go to the branded /exports/table endpoint (agency logo + colours + Private &
   Confidential footer). On admin screens with a per-type export/import, Excel
   uses the richer branded export and an Import button is added.
   ═══════════════════════════════════════════════════════════════════ */
(function (w) {
  'use strict';
  var KT = w.KT || {};

  // Icon export buttons: square, icon-sized, with a clean hover tooltip (in
  // addition to the native title). Injected once.
  function ensureExportIconStyle() {
    if (document.getElementById('kt-export-icon-style')) return;
    var st = document.createElement('style'); st.id = 'kt-export-icon-style';
    st.textContent =
      '.kt-export-btn.kt-export-icon{width:36px;height:36px;padding:0;display:inline-flex;align-items:center;justify-content:center;font-size:17px;line-height:1;border-radius:9px;position:relative;}' +
      '.kt-export-btn.kt-export-icon.kt-export-import{background:#EDE9FE;border-color:#DDD6FE;}' +
      '';   // hover label: kt-tooltip-global.js (a local ::after bubble here doubled it, 2026-11-04)
    document.head.appendChild(st);
  }

  function hash() { return (location.hash || '').replace('#', '').split('?')[0]; }
  function mapType() {
    try { return (KT.V22p54 && KT.V22p54.mapHashToExportType) ? KT.V22p54.mapHashToExportType(hash()) : null; } catch (e) { return null; }
  }
  function pageTitle() {
    var h = document.querySelector('#appMain .kt-hero h1, #appMain .kt-page-hero h2, #appMain .kt-page-hero h1, #appMain h1, #appMain h2');
    var t = h ? (h.textContent || '').replace(/\s+/g, ' ').trim() : '';
    return t || 'Report';
  }
  /* A table can name its own export (data-kt-export-title): a report screen holding
     several reports otherwise gives every file the screen's name. */
  function titleFor(table) {
    var t = table && table.getAttribute && table.getAttribute('data-kt-export-title');
    return (t && t.trim()) || pageTitle();
  }
  function sane(s) { return (s || 'report').replace(/[^a-z0-9]+/gi, '-').toLowerCase().replace(/^-+|-+$/g, '') || 'report'; }

  function extract(table) {
    if (typeof table.__ktExportAll === 'function') {
      try { var all = table.__ktExportAll(); if (all && all.headers && all.rows) return all; } catch (e) {}
    }
    var thead = table.querySelector('thead'), tbody = table.querySelector('tbody');
    if (!thead || !tbody) return null;
    var ths = Array.prototype.slice.call(thead.querySelectorAll('th'));
    var headers = [], keep = [];
    ths.forEach(function (th, i) {
      if (th.querySelector('input[type=checkbox]')) return;               // row-select column
      var t = (th.textContent || '').replace(/[⇅↑↓]/g, '').replace(/\s+/g, ' ').trim();
      if (!t) return;                                                     // empty / actions column
      headers.push(t); keep.push(i);
    });
    var rows = [];
    Array.prototype.slice.call(tbody.children).forEach(function (tr) {
      if (tr.querySelector('td[colspan]')) return;                        // empty-state row
      if (tr.style.display === 'none') return;                           // filtered out
      var tds = tr.children;
      rows.push(keep.map(function (i) {
        var c = tds[i]; if (!c) return '';
        /* A cell can show a short form and export a full one: anything inside it with
           data-export contributes that value instead of its text ("Oct 5, 5:51 PM" on
           screen, "2026-10-05 5:51 PM" in the file). */
        if (c.querySelector('[data-export]')) {
          c = c.cloneNode(true);
          c.querySelectorAll('[data-export]').forEach(function (x) { x.textContent = x.getAttribute('data-export'); });
        }
        return (c.textContent || '').replace(/\s+/g, ' ').trim();
      }));
    });
    return { headers: headers, rows: rows };
  }

  function save(blob, name) {
    var u = URL.createObjectURL(blob);
    var a = document.createElement('a'); a.href = u; a.download = name;
    document.body.appendChild(a); a.click(); a.remove();
    setTimeout(function () { URL.revokeObjectURL(u); }, 4000);
  }
  function busy(btn, on) {
    if (!btn) return;
    if (on) { btn.dataset._t = btn.textContent; btn.textContent = '…'; btn.disabled = true; }
    else { btn.textContent = btn.dataset._t || btn.textContent; btn.disabled = false; }
  }

  function csv(table) {
    var d = extract(table); if (!d) return;
    var q = function (v) { v = String(v == null ? '' : v); return /[",\n]/.test(v) ? '"' + v.replace(/"/g, '""') + '"' : v; };
    var s = d.headers.map(q).join(',') + '\n' + d.rows.map(function (r) { return r.map(q).join(','); }).join('\n');
    save(new Blob(['﻿' + s], { type: 'text/csv' }), sane(titleFor(table)) + '.csv');
  }

  /* KiddieTrac's own screens (Platform, Sales, the superadmin reports) export with KiddieTrac's brand, not the
     active agency's - a superadmin working inside iLearn got iLearn's logo on KiddieTrac's sales figures
     (2026-11-01). The server honours it for platform admins only. A table can also opt in with
     data-kt-export-brand="platform". */
  var PLATFORM_SCREENS = /^(platform-|sales|kt-reports$|admin-mrr$|ai-usage$|usage-spend$|server-health$|backups$|compliance-evidence$|digest-status$|security-alerts$|licences$|marketing-site$|mail-settings$|maintenance$|admin-features$|social-settings$)/;
  function platformBrand(table) {
    return !!((table && table.closest && table.closest('[data-kt-export-brand="platform"]')) || PLATFORM_SCREENS.test(hash()));
  }
  async function serverExport(table, fmt, btn) {
    var mt = mapType();
    if (fmt === 'xlsx' && mt && mt.path && KT.V22p54 && KT.V22p54.downloadAuthed) {
      busy(btn, true);
      try { await KT.V22p54.downloadAuthed(mt.path, mt.filename || (sane(pageTitle()) + '.xlsx')); }
      catch (e) { alert('Excel export failed.'); }
      busy(btn, false); return;
    }
    var d = extract(table); if (!d) return;
    var base = (KT && KT.API_BASE) || 'https://api.kiddietrac.com/api/v1';
    var token = sessionStorage.getItem('kt_token') || localStorage.getItem('kt_token');
    var agency = ''; try { agency = sessionStorage.getItem('kt_active_agency_id') || ''; } catch (e) {}
    busy(btn, true);
    try {
      var res = await fetch(base + '/exports/table', {
        method: 'POST',
        headers: { 'Authorization': 'Bearer ' + token, 'X-Active-Agency-Id': agency, 'Content-Type': 'application/json' },
        body: JSON.stringify({ title: titleFor(table), format: fmt, columns: d.headers, rows: d.rows, brand: platformBrand(table) ? 'platform' : undefined }),
      });
      if (!res.ok) throw new Error('HTTP ' + res.status);
      save(await res.blob(), sane(titleFor(table)) + '.' + fmt);
    } catch (e) { alert(fmt.toUpperCase() + ' export failed: ' + (e.message || e)); }
    busy(btn, false);
  }

  // Move the live record counter (kt-table-filter's "N / N") out of the top
  // toolbar and into the bottom bar's right slot — so it reads as the record
  // total at the bottom-right, not floating at the top.
  function relocateCount(table, barEl) {
    var slot = barEl.querySelector('.kt-export-count');
    if (!slot) return;

    // A page of a longer list in memory (table.__ktRecordTotal): the filter's "25 / 25" counts the page, not the list.
    var paged = table.__ktRecordTotal != null;
    if (paged) { Array.prototype.forEach.call(slot.querySelectorAll('.kt-table-filter-count'), function (c) { c.style.display = 'none'; }); }

    // Already holding the filter's live counter? Then we are done. This check has to come
    // FIRST: once the counter has been moved into the slot it is no longer in the
    // toolbar, so the lookup below finds nothing, concludes there is no filter, and adds
    // a second count — which is why the bar read "10 / 1010 records".
    if (!paged && slot.querySelector('.kt-table-filter-count')) {
      var dupe = slot.querySelector('.kt-export-own-count');
      if (dupe) dupe.parentNode.removeChild(dupe);
      return;
    }

    var sc = table.closest('.kt-tbl-scroll');
    var toolbar = sc ? sc.previousElementSibling : table.previousElementSibling;
    // A docked search bar (kt-table-filter, 2026-10-20) lives in the screen's filter row.
    if (!(toolbar && toolbar.classList && toolbar.classList.contains('kt-table-filter')) && table.__ktFilterBar) toolbar = table.__ktFilterBar;
    var cnt = (toolbar && toolbar.classList && toolbar.classList.contains('kt-table-filter'))
      ? toolbar.querySelector('.kt-table-filter-count') : null;

    if (cnt && paged) cnt.style.display = 'none';
    if (cnt && !paged) {                         // the filter owns a live "N / N" counter
      // The filter attaches AFTER the first sweep, so our fallback count may already be
      // sitting there — drop it, or the bar reads "20 records20 / 20".
      var stale = slot.querySelector('.kt-export-own-count');
      if (stale) stale.parentNode.removeChild(stale);
      if (cnt.parentNode !== slot) slot.appendChild(cnt);
      return;
    }

    // No filter on this table — count the rows ourselves so the bar still reports how
    // many records are on screen (hidden rows, e.g. filtered out, are not counted).
    var own = slot.querySelector('.kt-export-own-count');
    if (!own) {
      own = document.createElement('span');
      own.className = 'kt-export-own-count';
      slot.appendChild(own);
    }
    var rows = table.querySelectorAll('tbody tr');
    var shown = 0;
    for (var i = 0; i < rows.length; i++) {
      // The inline display first: it answers almost every row without a layout (phone speed sweep 2026-10-30).
      if (rows[i].style.display !== 'none' || rows[i].offsetParent !== null) shown++;
    }
    if (table.__ktRecordTotal != null) shown = +table.__ktRecordTotal || 0;   // a page of a longer list in memory
    var label = shown === 1 ? '1 record' : (shown + ' records');
    if (own.textContent !== label) own.textContent = label;
  }

  // Report tables keep their own export footer (no auto-bar), but still move the
  // top count down to a bottom-right slot so the count is consistent everywhere.
  function relocateReportCount(table) {
    var sc = table.closest('.kt-tbl-scroll'); if (!sc) return;
    var toolbar = sc.previousElementSibling;
    if (!toolbar || !toolbar.classList || !toolbar.classList.contains('kt-table-filter')) return;
    var cnt = toolbar.querySelector('.kt-table-filter-count'); if (!cnt) return;
    var slot = (sc.nextElementSibling && sc.nextElementSibling.classList && sc.nextElementSibling.classList.contains('kt-report-count')) ? sc.nextElementSibling : null;
    if (!slot) {
      slot = document.createElement('div');
      slot.className = 'kt-report-count';
      slot.style.cssText = 'text-align:right;font-size:12.5px;color:#64748B;font-weight:600;padding:8px 16px 0;';
      sc.parentNode.insertBefore(slot, sc.nextSibling);
    }
    if (cnt.parentNode !== slot) slot.appendChild(cnt);
  }

  function addBar(table) {
    if (table.closest('#modalRoot')) return;
    // data-kt-noexport (e.g. Messages): keep the record-count bar, just no export buttons.
    var countOnly = table.hasAttribute('data-kt-noexport');
    // Report docs have their own branded export footer — no auto-bar, but do
    // move the record count to the bottom for consistency.
    if (table.closest('.kt-report-doc')) { relocateReportCount(table); return; }
    var sc = table.closest('.kt-tbl-scroll'), anchor = sc || table;
    /* ONE BAR PER TABLE, WHEREVER IT HAS BEEN PUSHED (2026-10-20). Anthony: "leads section
       has double up on the pdf, excel etc buttons". This only recognised a bar sitting
       DIRECTLY after the table. The rows-per-page pagers (kt-table-filter, kt-polish-v2,
       kt-card-pager) also insert themselves directly after the table, so whenever one
       arrived after the bar it pushed the bar one sibling down, the next sweep saw no bar
       and added a second (and a third, after the next pager redraw). The bar is now
       tied to its table and found by that link, or by looking a few siblings ahead; any
       extra bars for the same table are removed. */
    var own = table.__ktExportBar && table.__ktExportBar.isConnected ? table.__ktExportBar : null;
    var s = anchor.nextElementSibling, hop = 0, found = [];
    while (s && hop++ < 5) {
      if (s.classList && s.classList.contains('kt-export-bar') && !s.classList.contains('kt-cardcount')) {
        /* A bar left by a table the screen has since redrawn goes: its buttons would
           export the old table. A fresh one is built for this table below. */
        if (s.__ktExportFor && s.__ktExportFor !== table && !s.__ktExportFor.isConnected) {
          var dead = s; s = s.nextElementSibling; if (dead.parentNode) { dead.parentNode.removeChild(dead); } continue;
        }
        if (!s.__ktExportFor || s.__ktExportFor === table) { found.push(s); }
      } else if (s.tagName === 'TABLE' || (s.classList && s.classList.contains('kt-tbl-scroll'))) { break; }   // the next table's territory
      s = s.nextElementSibling;
    }
    if (own && found.indexOf(own) === -1) { found.unshift(own); }
    if (found.length) {
      var keep = own || found[0];
      found.forEach(function (b) { if (b !== keep && b.parentNode) { b.parentNode.removeChild(b); } });
      keep.__ktExportFor = table; table.__ktExportBar = keep;
      relocateCount(table, keep);
      return;
    }
    var tbody = table.querySelector('tbody');
    if (!tbody || tbody.children.length < 1) return;
    if (!table.querySelector('thead')) return;
    if (table.querySelectorAll('thead th').length < 2) return;            // skip non-data / layout tables

    var barEl = document.createElement('div');
    barEl.className = 'kt-export-bar';
    if (!countOnly) {
      var btns = document.createElement('div');
      btns.className = 'kt-export-btns';
      ensureExportIconStyle();
      // Icon-only buttons (cleaner than "⬇ Excel" text) with a tooltip naming the
      // format — the icon carries the meaning, the tooltip removes any doubt.
      var mk = function (icon, tip, fn, cls) {
        var b = document.createElement('button'); b.className = 'kt-export-btn kt-export-icon' + (cls ? ' ' + cls : '');
        b.type = 'button'; b.textContent = icon;
        b.title = tip; b.setAttribute('aria-label', tip); b.setAttribute('data-kttip', tip);
        b.addEventListener('click', function () { fn(b); });
        return b;
      };
      btns.appendChild(mk('📊', 'Export to Excel', function (b) { serverExport(table, 'xlsx', b); }, 'kt-exp-xlsx'));
      btns.appendChild(mk('📄', 'Export to CSV', function () { csv(table); }, 'kt-exp-csv'));
      btns.appendChild(mk('📕', 'Export to PDF', function (b) { serverExport(table, 'pdf', b); }, 'kt-exp-pdf'));
      var mt = mapType();
      if (mt && mt.importType && KT.V22p54 && KT.V22p54.buildImportModal) {
        btns.appendChild(mk('⬆️', 'Import from a spreadsheet', function () {
          KT.V22p54.buildImportModal(mt.importType, mt.label, function () { location.reload(); });
        }, 'kt-export-import'));
      }
      barEl.appendChild(btns);
    }
    var count = document.createElement('div');
    count.className = 'kt-export-count';   // record-count slot (bottom-right)
    barEl.appendChild(count);
    anchor.parentNode.insertBefore(barEl, anchor.nextSibling);
    barEl.__ktExportFor = table; table.__ktExportBar = barEl;
    relocateCount(table, barEl);
  }

  // Card lists ([data-kt-list], e.g. Learning observations, Documents) get the SAME
  // bottom "N records" status bar as tables — read the accurate total from their
  // top .kt-list-controls .kt-list-count ("N / N"); fall back to counting rows.
  function cardTotal(list) {
    var tb = list.previousElementSibling, hop = 0;
    while (tb && hop++ < 4) { if (tb.classList && tb.classList.contains('kt-list-controls')) break; tb = tb.previousElementSibling; }
    if (tb && tb.classList && tb.classList.contains('kt-list-controls')) {
      var c = tb.querySelector('.kt-list-count');
      var m = c && (c.textContent || '').match(/(\d+)\s*(?:\/\s*(\d+))?/);
      if (m) return parseInt(m[2] || m[1], 10);
    }
    var n = 0;
    /* An empty-state or loading message is not a record (2026-10-30: an empty Suppliers list read
       '1 record'). Skipped when marked .kt-list-empty / [data-kt-empty], and a list whose only child
       is a plain 'No ...' / 'Nothing ...' / 'Loading...' line with no controls counts as none. */
    var kids = [];
    for (var i = 0; i < list.children.length; i++) { var ch = list.children[i]; if (ch.nodeType === 1 && !ch.classList.contains('kt-export-bar') && !ch.classList.contains('kt-list-empty') && !ch.hasAttribute('data-kt-empty')) kids.push(ch); }
    if (kids.length === 1 && !kids[0].querySelector('button,a,input,select') && /^(no |nothing|loading)/i.test((kids[0].textContent || '').trim())) return 0;
    n = kids.length;
    return n;
  }

  function addCardBar(list) {
    if (list.closest('#modalRoot')) return;
    if (list.hasAttribute('data-kt-nocount')) return;
    var total = cardTotal(list);
    var label = total === 1 ? '1 record' : (total + ' records');
    // Look a few siblings ahead for an existing card-count bar — cardPager may
    // insert its pager between the list and our bar, so we can't rely on nextSibling.
    var s = list.nextElementSibling, hop = 0, existing = null;
    while (s && hop++ < 3) { if (s.classList && s.classList.contains('kt-cardcount')) { existing = s; break; } s = s.nextElementSibling; }
    if (existing) { var ec = existing.querySelector('.kt-export-count'); if (ec) ec.textContent = label; return; }
    var barEl = document.createElement('div');
    barEl.className = 'kt-export-bar kt-cardcount';
    var count = document.createElement('div');
    count.className = 'kt-export-count';
    count.textContent = label;
    barEl.appendChild(count);
    // Place after the cardPager bar if present, else right after the list.
    var after = list, n = list.nextElementSibling;
    if (n && n.classList && n.classList.contains('kt-card-pager')) after = n;
    after.parentNode.insertBefore(barEl, after.nextSibling);
  }

  // Remove legacy per-screen top export buttons (⤓ CSV / ⤓ PDF / Export PDF /
  // Download CSV …) now that the bottom bar covers CSV/Excel/PDF — but only once a
  // bar exists on the screen, so export is never lost. Buttons flagged
  // `.kt-legacy-export` are always stripped when a bar is present; otherwise only a
  // button whose WHOLE label is a download glyph + a format word is removed (never
  // a button that merely mentions a format inside a longer, meaningful label).
  // Import buttons are always left alone.
  function removeLegacyExport() {
    if (!document.querySelector('#appMain .kt-export-bar')) return;
    document.querySelectorAll('#appMain button').forEach(function (b) {
      if (b.closest('.kt-export-bar')) return;
      if (b.classList && b.classList.contains('kt-legacy-export')) { b.remove(); return; }
      var t = (b.textContent || '').trim();
      if (/import/i.test(t)) return;
      if (/^(?:[\s⤓⬇↓]|download|export|to)*\b(csv|pdf|excel|xlsx)\b$/i.test(t)) b.remove();
    });
  }

  function sweep() {
    document.querySelectorAll('#appMain table').forEach(addBar);   // addBar refreshes the count on tables that already have a bar
    document.querySelectorAll('#appMain [data-kt-list]').forEach(addCardBar); // card lists get the same bottom "N records" bar
    removeLegacyExport();
  }
  // Let the shell run the sweep as part of a screen render. The 1.8s poll below meant
  // a table appeared first and its Excel/CSV/PDF bar dropped in a beat later — visible
  // on Support tickets and every other table.
  window.KT = window.KT || {};
  window.KT.tableExport = { sweep: sweep };

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', sweep);
  else sweep();
  // Drive the bar off the shared DOM-settle bus so it renders WITH the table (~60–180ms
  // after a screen paints) instead of waiting for a 2s poll tick — that lag was the
  // "Excel/CSV/PDF icons pop in after the page loads" the user reported. Falls back to a
  // poll if the bus isn't loaded.
  (window.KT && KT.sweepBus) ? KT.sweepBus.on(sweep) : setInterval(sweep, 2000);
})(window);
