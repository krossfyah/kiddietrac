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

  function hash() { return (location.hash || '').replace('#', '').split('?')[0]; }
  function mapType() {
    try { return (KT.V22p54 && KT.V22p54.mapHashToExportType) ? KT.V22p54.mapHashToExportType(hash()) : null; } catch (e) { return null; }
  }
  function pageTitle() {
    var h = document.querySelector('#appMain .kt-hero h1, #appMain .kt-page-hero h2, #appMain .kt-page-hero h1, #appMain h1, #appMain h2');
    var t = h ? (h.textContent || '').replace(/\s+/g, ' ').trim() : '';
    return t || 'Report';
  }
  function sane(s) { return (s || 'report').replace(/[^a-z0-9]+/gi, '-').toLowerCase().replace(/^-+|-+$/g, '') || 'report'; }

  function extract(table) {
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
      rows.push(keep.map(function (i) { var c = tds[i]; return c ? (c.textContent || '').replace(/\s+/g, ' ').trim() : ''; }));
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
    save(new Blob(['﻿' + s], { type: 'text/csv' }), sane(pageTitle()) + '.csv');
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
        body: JSON.stringify({ title: pageTitle(), format: fmt, columns: d.headers, rows: d.rows }),
      });
      if (!res.ok) throw new Error('HTTP ' + res.status);
      save(await res.blob(), sane(pageTitle()) + '.' + fmt);
    } catch (e) { alert(fmt.toUpperCase() + ' export failed: ' + (e.message || e)); }
    busy(btn, false);
  }

  // Move the live record counter (kt-table-filter's "N / N") out of the top
  // toolbar and into the bottom bar's right slot — so it reads as the record
  // total at the bottom-right, not floating at the top.
  function relocateCount(table, barEl) {
    var slot = barEl.querySelector('.kt-export-count');
    if (!slot) return;

    // Already holding the filter's live counter? Then we are done. This check has to come
    // FIRST: once the counter has been moved into the slot it is no longer in the
    // toolbar, so the lookup below finds nothing, concludes there is no filter, and adds
    // a second count — which is why the bar read "10 / 1010 records".
    if (slot.querySelector('.kt-table-filter-count')) {
      var dupe = slot.querySelector('.kt-export-own-count');
      if (dupe) dupe.parentNode.removeChild(dupe);
      return;
    }

    var sc = table.closest('.kt-tbl-scroll');
    var toolbar = sc ? sc.previousElementSibling : table.previousElementSibling;
    var cnt = (toolbar && toolbar.classList && toolbar.classList.contains('kt-table-filter'))
      ? toolbar.querySelector('.kt-table-filter-count') : null;

    if (cnt) {                                   // the filter owns a live "N / N" counter
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
      if (rows[i].offsetParent !== null || rows[i].style.display !== 'none') shown++;
    }
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
    // Report docs have their own branded export footer — no auto-bar, but do
    // move the record count to the bottom for consistency.
    if (table.closest('.kt-report-doc')) { relocateReportCount(table); return; }
    var sc = table.closest('.kt-tbl-scroll'), anchor = sc || table;
    var nxt = anchor.nextElementSibling;
    if (nxt && nxt.classList && nxt.classList.contains('kt-export-bar')) { relocateCount(table, nxt); return; }
    var tbody = table.querySelector('tbody');
    if (!tbody || tbody.children.length < 1) return;
    if (!table.querySelector('thead')) return;
    if (table.querySelectorAll('thead th').length < 2) return;            // skip non-data / layout tables

    var barEl = document.createElement('div');
    barEl.className = 'kt-export-bar';
    var btns = document.createElement('div');
    btns.className = 'kt-export-btns';
    var mk = function (label, fn, cls) {
      var b = document.createElement('button'); b.className = 'kt-export-btn' + (cls ? ' ' + cls : '');
      b.type = 'button'; b.textContent = label;
      b.addEventListener('click', function () { fn(b); });
      return b;
    };
    btns.appendChild(mk('⬇ Excel', function (b) { serverExport(table, 'xlsx', b); }));
    btns.appendChild(mk('⬇ CSV', function () { csv(table); }));
    btns.appendChild(mk('⬇ PDF', function (b) { serverExport(table, 'pdf', b); }));
    var mt = mapType();
    if (mt && mt.importType && KT.V22p54 && KT.V22p54.buildImportModal) {
      btns.appendChild(mk('⬆ Import', function () {
        KT.V22p54.buildImportModal(mt.importType, mt.label, function () { location.reload(); });
      }, 'kt-export-import'));
    }
    var count = document.createElement('div');
    count.className = 'kt-export-count';   // record-count slot (bottom-right)
    barEl.appendChild(btns);
    barEl.appendChild(count);
    anchor.parentNode.insertBefore(barEl, anchor.nextSibling);
    relocateCount(table, barEl);
  }

  // Remove legacy per-screen top CSV buttons (⤓ CSV / ⤓ Download CSV / ⤓ Export
  // … CSV) now that the bottom bar covers CSV — but only once a bar exists on the
  // screen, so CSV is never lost. Leaves Import buttons alone.
  function removeLegacyCsv() {
    if (!document.querySelector('#appMain .kt-export-bar')) return;
    document.querySelectorAll('#appMain button').forEach(function (b) {
      if (b.closest('.kt-export-bar')) return;
      var t = (b.textContent || '').trim();
      if (/csv/i.test(t) && !/import/i.test(t) && t.length < 30) b.remove();
    });
  }

  function sweep() {
    document.querySelectorAll('#appMain table').forEach(addBar);   // addBar refreshes the count on tables that already have a bar
    removeLegacyCsv();
  }
  // Let the shell run the sweep as part of a screen render. The 1.8s poll below meant
  // a table appeared first and its Excel/CSV/PDF bar dropped in a beat later — visible
  // on Support tickets and every other table.
  window.KT = window.KT || {};
  window.KT.tableExport = { sweep: sweep };

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', sweep);
  else sweep();
  setInterval(sweep, 250);   // was 1800ms — the table appeared, then its export bar dropped in a beat later
})(window);
