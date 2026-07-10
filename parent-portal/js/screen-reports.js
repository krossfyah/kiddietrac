/* ═══════════════════════════════════════════════════════════════════
   KiddieTrac — Canned Reports (2026-07-08)
   A list of ready-to-run reports. Pick a date range + centre, hit Run, and
   get a branded (agency + centre logo), zebra-striped, printable document.
   Registered for :reports AFTER the legacy builder so this becomes the
   Reports screen. Data: GET /reports/canned + /reports/canned/run.
   ═══════════════════════════════════════════════════════════════════ */
(function (window) {
  'use strict';
  var KT = window.KT || {};
  var Shell = KT.Shell, Api = KT.Api;
  if (!Shell || !Api) return;

  function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"]/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c];
    });
  }
  function todayISO(offsetDays) {
    var d = new Date();
    if (offsetDays) d.setDate(d.getDate() + offsetDays);
    return d.toISOString().slice(0, 10);
  }
  function absUrl(u) {
    if (!u) return '';
    if (/^https?:|^data:/.test(u)) return u;
    return (u.charAt(0) === '/' ? '' : '/') + u;
  }
  function logoBox(logo, name, color) {
    if (logo) return '<img src="' + esc(absUrl(logo)) + '" alt="" style="height:46px;max-width:150px;object-fit:contain;">';
    var initial = esc((name || '?').charAt(0).toUpperCase());
    return '<div style="width:46px;height:46px;border-radius:10px;background:' + esc(color || '#1F6080') +
      ';color:#fff;display:flex;align-items:center;justify-content:center;font-weight:800;font-size:20px;">' + initial + '</div>';
  }

  var META = null;

  async function render(main) {
    main.setAttribute('data-kt-pretty', '1');
    main.innerHTML = '<div style="padding:14px 24px;"><div class="kt-page-hero"><h2>📋 Reports</h2><p>Loading…</p></div></div>';
    META = await Api.get('/reports/canned').catch(function () { return { reports: [], centres: [], agency: null }; });

    var centreOpts = '<option value="">All centres</option>' +
      (META.centres || []).map(function (c) { return '<option value="' + c.id + '">' + esc(c.name) + '</option>'; }).join('');

    var cards = (META.reports || []).map(function (r) {
      return '<button class="kt-rep-card" data-type="' + esc(r.type) + '" data-dated="' + (r.dated ? '1' : '0') +
        '" style="text-align:left;background:#fff;border:1px solid #E7EBF0;border-radius:14px;padding:16px;cursor:pointer;display:flex;gap:12px;align-items:flex-start;transition:box-shadow .15s,border-color .15s;">' +
        '<div style="font-size:30px;line-height:1;flex-shrink:0;">' + esc(r.icon || '📄') + '</div>' +
        '<div><div style="font-weight:700;font-size:15px;color:#0D1B2A;margin-bottom:2px;">' + esc(r.title) + '</div>' +
        '<div style="font-size:12.5px;color:#64748B;line-height:1.35;">' + esc(r.desc || '') + '</div></div></button>';
    }).join('');

    main.innerHTML =
      '<div style="padding:14px 24px;">' +
        '<div class="kt-page-hero"><h2>📋 Reports</h2><p>Pick a report, set a date range and centre, then run it. Every report is branded and print-ready.</p></div>' +
        '<div class="kt-report-noprint" style="display:flex;flex-wrap:wrap;gap:14px;align-items:flex-end;background:#fff;border:1px solid #E7EBF0;border-radius:14px;padding:14px 16px;margin-bottom:16px;">' +
          '<label style="font-size:12px;font-weight:700;color:#475569;">Centre<br><select id="rep-centre" style="margin-top:4px;">' + centreOpts + '</select></label>' +
          '<label id="rep-from-wrap" style="font-size:12px;font-weight:700;color:#475569;">From<br><input type="date" id="rep-from" value="' + todayISO(-30) + '" style="margin-top:4px;"></label>' +
          '<label id="rep-to-wrap" style="font-size:12px;font-weight:700;color:#475569;">To<br><input type="date" id="rep-to" value="' + todayISO(0) + '" style="margin-top:4px;"></label>' +
          '<span style="font-size:12px;color:#94A3B8;align-self:center;">Date range applies to dated reports (attendance, payments, invoices, staff hours).</span>' +
        '</div>' +
        '<div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(280px,1fr));gap:14px;margin-bottom:18px;">' + cards + '</div>' +
        '<div id="rep-out"></div>' +
      '</div>';

    main.querySelectorAll('.kt-rep-card').forEach(function (b) {
      b.addEventListener('mouseenter', function () { b.style.boxShadow = '0 6px 18px rgba(15,23,42,.10)'; b.style.borderColor = '#C9D3DE'; });
      b.addEventListener('mouseleave', function () { b.style.boxShadow = 'none'; b.style.borderColor = '#E7EBF0'; });
      b.addEventListener('click', function () { runReport(b.getAttribute('data-type')); });
    });
  }

  async function runReport(type) {
    var out = document.getElementById('rep-out');
    if (!out) return;
    var centreId = (document.getElementById('rep-centre') || {}).value || '';
    var from = (document.getElementById('rep-from') || {}).value || '';
    var to = (document.getElementById('rep-to') || {}).value || '';
    out.innerHTML = '<div style="padding:24px;color:#64748B;">Generating report…</div>';
    var qs = 'type=' + encodeURIComponent(type);
    if (centreId) qs += '&centre_id=' + encodeURIComponent(centreId);
    if (from) qs += '&from=' + encodeURIComponent(from);
    if (to) qs += '&to=' + encodeURIComponent(to);
    var r;
    try { r = await Api.get('/reports/canned/run?' + qs); }
    catch (e) { out.innerHTML = '<div style="padding:24px;color:#DC2626;">Could not run report: ' + esc(e.message || 'error') + '</div>'; return; }

    var cols = r.columns || [];
    var rows = r.rows || [];
    var ag = r.agency || {}, ce = r.centre || null;
    var range = (r.date_from || r.date_to)
      ? ((r.date_from || '…') + ' → ' + (r.date_to || '…'))
      : 'All dates';

    var thead = '<tr>' + cols.map(function (c, i) {
      var numeric = /amount|total|paid|balance|hours/i.test(c);
      return '<th style="text-align:' + (numeric && i > 0 ? 'right' : 'left') + ';padding:9px 12px;font-size:10.5px;font-weight:800;letter-spacing:.05em;text-transform:uppercase;color:#fff;background:' +
        esc(ag.color || '#1F6080') + ';">' + esc(c) + '</th>';
    }).join('') + '</tr>';

    var tbody = rows.length ? rows.map(function (row, ri) {
      var bg = ri % 2 ? '#F5F8FB' : '#FFFFFF';   // zebra
      return '<tr style="background:' + bg + ';">' + cols.map(function (c, i) {
        var v = row[c]; var numeric = /amount|total|paid|balance|hours/i.test(c);
        var isStatus = /status/i.test(c);
        var cell = esc(v);
        if (isStatus && v) cell = '<span style="display:inline-block;padding:1px 8px;border-radius:20px;font-size:11px;font-weight:700;background:#EEF2F7;color:#334155;">' + esc(v) + '</span>';
        return '<td style="padding:8px 12px;font-size:12.5px;color:#1E293B;border-bottom:1px solid #E9EEF3;text-align:' + (numeric && i > 0 ? 'right' : 'left') + ';white-space:nowrap;">' + cell + '</td>';
      }).join('') + '</tr>';
    }).join('') : '<tr><td colspan="' + cols.length + '" style="padding:30px;text-align:center;color:#94A3B8;">No records for this selection.</td></tr>';

    // Explicit, visible button styles — kt-btn-ghost is white-on-transparent
    // (built for dark hero backgrounds) and vanished on this white footer.
    var _dlBtn = 'font-size:13px;font-weight:600;padding:8px 15px;border-radius:8px;cursor:pointer;border:1px solid #D6DEE7;background:#F3F6F9;color:#1E293B;';
    var _primBtn = 'font-size:13px;font-weight:600;padding:8px 16px;border-radius:8px;cursor:pointer;border:0;background:' + (ag.color || '#1F6080') + ';color:#fff;';
    var _u = {}; try { _u = JSON.parse(sessionStorage.getItem('kt_user') || '{}'); } catch (e) {}
    var producedBy = _u.name || (((_u.first_name || '') + ' ' + (_u.last_name || '')).trim()) || _u.email || 'a signed-in user';
    var _nowStr = new Date().toLocaleString();

    out.innerHTML =
      '<div class="kt-report-doc" style="background:#fff;border:1px solid #E7EBF0;border-radius:14px;overflow:hidden;">' +
        '<div style="display:flex;justify-content:space-between;align-items:center;gap:16px;padding:18px 22px;border-bottom:3px solid ' + esc(ag.color || '#1F6080') + ';">' +
          '<div style="display:flex;align-items:center;gap:12px;">' + logoBox(ag.logo, ag.name, ag.color) +
            '<div><div style="font-weight:800;font-size:16px;color:#0D1B2A;">' + esc(ag.name || 'Agency') + '</div>' +
            '<div style="font-size:12px;color:#64748B;">' + esc(r.icon || '') + ' ' + esc(r.title) + '</div></div>' +
          '</div>' +
          '<div style="text-align:right;display:flex;align-items:center;gap:12px;">' +
            '<div><div style="font-size:12px;color:#64748B;">' + esc(ce ? ce.name : 'All centres') + '</div>' +
            '<div style="font-size:12px;color:#94A3B8;">' + esc(range) + '</div>' +
            '<div style="font-size:11px;color:#B6C0CC;">Generated ' + esc(todayISO(0)) + ' · ' + rows.length + ' rows</div></div>' +
            (ce ? logoBox(ce.logo, ce.name, ce.color) : '') +
          '</div>' +
        '</div>' +
        '<table style="width:100%;border-collapse:collapse;">' +
          '<thead>' + thead + '</thead><tbody>' + tbody + '</tbody></table>' +
        '<div style="padding:9px 18px;font-size:11px;color:#64748B;border-top:1px solid #EEF2F7;line-height:1.5;">' +
          'Generated ' + esc(_nowStr) + ' by ' + esc(producedBy) + ' · ' + esc(ag.name || '') +
          ' &nbsp;·&nbsp; <b style="color:#B91C1C;">PRIVATE &amp; CONFIDENTIAL</b> — Contains sensitive information; do not distribute without authorisation.' +
        '</div>' +
        '<div class="kt-report-noprint" style="padding:12px 16px;display:flex;gap:8px;justify-content:flex-end;flex-wrap:wrap;border-top:1px solid #EEF2F7;">' +
          '<button id="rep-xlsx" style="' + _dlBtn + '">⬇ Excel</button>' +
          '<button id="rep-csv" style="' + _dlBtn + '">⬇ CSV</button>' +
          '<button id="rep-pdf" style="' + _dlBtn + '">⬇ PDF</button>' +
          '<button id="rep-print" style="' + _primBtn + '">🖨 Print</button>' +
        '</div>' +
      '</div>';

    var printCss = document.getElementById('kt-report-print-css');
    if (!printCss) {
      printCss = document.createElement('style');
      printCss.id = 'kt-report-print-css';
      printCss.textContent = '@media print{body *{visibility:hidden!important;}#appMain .kt-report-doc,#appMain .kt-report-doc *{visibility:visible!important;}#appMain .kt-report-doc{position:absolute;left:0;top:0;width:100%;border:0!important;}.kt-report-noprint{display:none!important;}}';
      document.head.appendChild(printCss);
    }
    out.scrollIntoView({ behavior: 'smooth', block: 'start' });
    document.getElementById('rep-print').onclick = function () { window.print(); };
    document.getElementById('rep-csv').onclick = function () { downloadCsv(r); };
    document.getElementById('rep-xlsx').onclick = function (ev) { downloadServerFile(qs, type, 'xlsx', ev.currentTarget); };
    document.getElementById('rep-pdf').onclick = function (ev) { downloadServerFile(qs, type, 'pdf', ev.currentTarget); };
  }

  // PDF (dompdf) + Excel (PhpSpreadsheet) are rendered server-side so they're
  // real, branded files (a proper .xlsx opens with no "format" error, with the
  // agency logo + colours + Private & Confidential footer). Both need the auth
  // token, so we fetch as a blob rather than a plain link.
  async function downloadServerFile(qs, type, kind, btn) {
    var base = (window.KT && window.KT.API_BASE) || 'https://api.kiddietrac.com/api/v1';
    var token = sessionStorage.getItem('kt_token') || localStorage.getItem('kt_token');
    var agency = '';
    try { agency = sessionStorage.getItem('kt_active_agency_id') || ''; } catch (e) {}
    var ext = kind === 'xlsx' ? '.xlsx' : '.pdf';
    var label = btn ? btn.textContent : '';
    if (btn) { btn.textContent = '…'; btn.disabled = true; }
    try {
      var res = await fetch(base + '/reports/canned/' + kind + '?' + qs, { headers: { 'Authorization': 'Bearer ' + token, 'X-Active-Agency-Id': agency } });
      if (!res.ok) throw new Error('HTTP ' + res.status);
      saveBlob(await res.blob(), (type || 'report') + '_' + todayISO(0) + ext);
    } catch (e) {
      alert(kind.toUpperCase() + ' export failed: ' + (e.message || e));
    } finally {
      if (btn) { btn.textContent = label; btn.disabled = false; }
    }
  }

  function saveBlob(blob, filename) {
    var url = URL.createObjectURL(blob);
    var a = document.createElement('a');
    a.href = url; a.download = filename;
    document.body.appendChild(a); a.click(); a.remove();
    setTimeout(function () { URL.revokeObjectURL(url); }, 4000);
  }

  function downloadCsv(r) {
    var cols = r.columns || [], rows = r.rows || [];
    var q = function (v) { v = String(v == null ? '' : v); return /[",\n]/.test(v) ? '"' + v.replace(/"/g, '""') + '"' : v; };
    var csv = cols.map(q).join(',') + '\n' + rows.map(function (row) { return cols.map(function (c) { return q(row[c]); }).join(','); }).join('\n');
    var a = document.createElement('a');
    a.href = 'data:text/csv;charset=utf-8,' + encodeURIComponent(csv);
    a.download = (r.type || 'report') + '_' + todayISO(0) + '.csv';
    document.body.appendChild(a); a.click(); a.remove();
  }

  ['agency_admin', 'platform_admin', 'centre_director', 'auditor'].forEach(function (role) {
    Shell.registerScreen(role + ':reports', render);
  });
  if (KT) KT.CannedReports = { render: render };
})(window);
