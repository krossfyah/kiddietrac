/* ═══════════════════════════════════════════════════════════════════
   KiddieTrac — Payroll report, Campaign report and KiddieTrac reports (2026-10-31).

   Anthony: "in reports, add a payroll report, campaign report" and "add a report that only superadmin can see
   for sales reports, leads reports, cold call reports".
   - #payroll-report   agency admin / centre director (own centres) / platform admin. GET /admin/payroll/report
   - #campaign-report  same roles.                                                   GET /marketing/campaigns-report
   - #kt-reports       superadmin only (server: role:platform_admin + a re-check).   GET /platform/reports/{tab}
   Tables are plain <table>s in #appMain, so the portal's filter box, sortable headers, phone cards and the
   Excel/CSV/PDF export bar attach to them; every row is rendered, so exports cover the whole filtered set.
   Real figures only - anything the platform does not record is listed under "Not tracked".
   ═══════════════════════════════════════════════════════════════════ */
(function (window) {
  'use strict';
  var KT = window.KT || {};
  var Shell = KT.Shell, Api = KT.Api;
  if (!Shell || !Api) return;

  function esc(s) { return String(s == null ? '' : s).replace(/[&<>"]/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]; }); }
  function money(v, cur) {
    if (v == null || v === '') return '—';
    var n = Number(v); if (!isFinite(n)) return '—';
    try { return n.toLocaleString(undefined, { style: 'currency', currency: cur || 'CAD', maximumFractionDigits: 2 }); } catch (e) { return '$' + n.toFixed(2); }
  }
  function num(v) { return v == null ? '—' : Number(v).toLocaleString(); }
  function pct(v) { return v == null ? '—' : Number(v).toFixed(1) + '%'; }
  function dt(v) {
    if (!v) return '—';
    var s = String(v); var d = new Date(s.length <= 10 ? s + 'T12:00:00' : s.replace(' ', 'T') + (/[zZ+]/.test(s) ? '' : 'Z'));
    if (isNaN(d)) return s.slice(0, 10);
    return d.toLocaleDateString(undefined, { year: 'numeric', month: 'short', day: 'numeric' });
  }
  function period(a, b) { return a && b && a !== b ? dt(a) + ' – ' + dt(b) : dt(a || b); }
  function today(off) { var d = new Date(((KT.agencyToday && KT.agencyToday()) || new Date().toISOString().slice(0, 10)) + 'T00:00:00Z'); if (off) d.setUTCDate(d.getUTCDate() + off); return d.toISOString().slice(0, 10); }
  function startOfYear() { return today(0).slice(0, 4) + '-01-01'; }
  function user() { try { return JSON.parse(sessionStorage.getItem('kt_user') || '{}'); } catch (e) { return {}; } }
  function viewAs() { try { return sessionStorage.getItem('kt_view_as') || ''; } catch (e) { return ''; } }
  function isSuper() { var u = user(); return !viewAs() && !!(u.is_platform_admin || (u.roles || []).indexOf('platform_admin') > -1); }
  function ssGet(k, d) { try { return sessionStorage.getItem(k) || d; } catch (e) { return d; } }
  function ssSet(k, v) { try { sessionStorage.setItem(k, v); } catch (e) {} }

  var CSS_ID = 'kt-rp-css';
  function css() {
    if (document.getElementById(CSS_ID)) return;
    var s = document.createElement('style'); s.id = CSS_ID;
    s.textContent =
      '.kt-rp{padding:14px 24px;}' +
      '.kt-rp-bar{display:flex;flex-wrap:wrap;gap:10px;align-items:flex-end;background:#fff;border:1px solid #E7EBF0;border-radius:14px;padding:12px 14px;margin:0 0 14px;}' +
      '.kt-rp-bar label{font-size:12px;font-weight:700;color:#475569;display:flex;flex-direction:column;gap:4px;}' +
      '.kt-rp-bar select,.kt-rp-bar input{height:32px;}' +
      '.kt-rp-cards{display:grid;grid-template-columns:repeat(auto-fill,minmax(190px,1fr));gap:12px;margin:0 0 14px;}' +
      '.kt-rp-card{background:#fff;border:1px solid #E7EBF0;border-radius:14px;overflow:hidden;}' +
      '.kt-rp-card h4{margin:0;padding:9px 12px;font-size:11.5px;letter-spacing:.5px;text-transform:uppercase;font-weight:800;}' +
      '.kt-rp-card .v{padding:8px 12px 2px;font-size:22px;font-weight:800;color:#0D1B2A;}' +
      '.kt-rp-card .s{padding:0 12px 10px;font-size:12px;color:#64748B;line-height:1.35;}' +
      '.kt-rp-panel{background:#fff;border:1px solid #E7EBF0;border-radius:14px;padding:14px;margin:0 0 14px;}' +
      '.kt-rp-panel h3{margin:0 0 4px;font-size:15px;color:#0D1B2A;}' +
      '.kt-rp-panel .cap{font-size:12px;color:#64748B;margin:0 0 10px;}' +
      '.kt-rp-meter{display:grid;grid-template-columns:minmax(90px,170px) 1fr auto;gap:8px;align-items:center;font-size:12.5px;margin:5px 0;}' +
      '.kt-rp-meter .t{height:10px;border-radius:6px;background:#EEF2F7;overflow:hidden;}' +
      '.kt-rp-meter .t i{display:block;height:100%;border-radius:6px;background:#2F7FC1;}' +
      '.kt-rp-grid2{display:grid;grid-template-columns:repeat(auto-fit,minmax(300px,1fr));gap:14px;}' +
      '.kt-rp-note{font-size:12px;color:#64748B;margin:4px 0 14px;}' +
      '.kt-rp-flag{color:#B45309;font-weight:800;}' +
      '.kt-rp-table{width:100%;border-collapse:collapse;font-size:13px;}' +
      '.kt-rp-table th{text-align:left;font-size:11px;letter-spacing:.4px;text-transform:uppercase;color:#64748B;padding:8px 10px;border-bottom:1px solid #E7EBF0;white-space:nowrap;}' +
      '.kt-rp-table td{padding:8px 10px;border-bottom:1px solid #F1F5F9;vertical-align:top;}' +
      '.kt-rp-table td.n,.kt-rp-table th.n{text-align:right;white-space:nowrap;}' +
      '.kt-rp-table tfoot td{font-weight:800;border-top:2px solid #E2E8F0;}' +
      '.kt-rp-av{width:24px;height:24px;border-radius:50%;object-fit:cover;vertical-align:middle;margin-right:6px;background:#E2E8F0;}' +
      '@media (max-width:600px){.kt-rp{padding:10px 10px;}.kt-rp-cards{grid-template-columns:repeat(2,minmax(0,1fr));}.kt-rp-card .v{font-size:18px;}.kt-rp-meter{grid-template-columns:minmax(70px,110px) 1fr auto;}}';
    document.head.appendChild(s);
  }
  var TONES = { blue: ['#EAF2FB', '#1E4E7C'], green: ['#E9F7EF', '#166534'], amber: ['#FEF5E6', '#92400E'], violet: ['#F1EDFB', '#5B21B6'], teal: ['#E6F6F5', '#0F766E'], rose: ['#FDECEF', '#9F1239'], slate: ['#EEF2F7', '#334155'] };
  function card(tone, icon, title, value, sub) {
    var t = TONES[tone] || TONES.slate;
    return '<div class="kt-rp-card"><h4 style="background:' + t[0] + ';color:' + t[1] + ';">' + icon + ' ' + esc(title) + '</h4>' +
      '<div class="v">' + value + '</div><div class="s">' + (sub || '') + '</div></div>';
  }
  function meters(rows, label, val, fmt) {
    var max = 0; rows.forEach(function (r) { max = Math.max(max, Number(r[val]) || 0); });
    if (!rows.length) return '<div class="kt-rp-note">Nothing recorded yet.</div>';
    return rows.map(function (r) {
      var v = Number(r[val]) || 0, w = max ? Math.max(2, Math.round(v / max * 100)) : 0;
      return '<div class="kt-rp-meter"><span>' + esc(r[label]) + '</span><span class="t"><i style="width:' + w + '%"></i></span><b>' + (fmt ? fmt(v) : num(v)) + '</b></div>';
    }).join('');
  }
  /* Month bars (SVG), two series side by side; hover a bar for the figure. */
  function monthBars(months, a, b, la, lb, fmt) {
    if (!months || !months.length) return '<div class="kt-rp-note">No months with data in this range.</div>';
    var W = Math.max(320, months.length * 56), H = 170, pad = 26, max = 0;
    months.forEach(function (m) { max = Math.max(max, Number(m[a]) || 0, b ? Number(m[b]) || 0 : 0); });
    max = max || 1;
    var bw = (W - pad * 2) / months.length, out = '';
    months.forEach(function (m, i) {
      var x = pad + i * bw, h1 = (Number(m[a]) || 0) / max * (H - 46), h2 = b ? (Number(m[b]) || 0) / max * (H - 46) : 0;
      out += '<rect x="' + (x + bw * 0.14) + '" y="' + (H - 24 - h1) + '" width="' + (b ? bw * 0.34 : bw * 0.6) + '" height="' + h1 + '" rx="3" fill="#9CC3E8"><title>' + esc(m.month) + ' · ' + esc(la) + ': ' + (fmt ? fmt(m[a]) : num(m[a])) + '</title></rect>';
      if (b) out += '<rect x="' + (x + bw * 0.52) + '" y="' + (H - 24 - h2) + '" width="' + bw * 0.34 + '" height="' + h2 + '" rx="3" fill="#2F7FC1"><title>' + esc(m.month) + ' · ' + esc(lb) + ': ' + (fmt ? fmt(m[b]) : num(m[b])) + '</title></rect>';
      out += '<text x="' + (x + bw / 2) + '" y="' + (H - 8) + '" text-anchor="middle" font-size="11" fill="#64748B">' + esc(String(m.month).slice(2)) + '</text>';
    });
    return '<div style="display:flex;gap:14px;font-size:12px;color:#475569;margin-bottom:4px;"><span><i style="display:inline-block;width:10px;height:10px;border-radius:2px;background:#9CC3E8;margin-right:5px;"></i>' + esc(la) + '</span>' +
      (b ? '<span><i style="display:inline-block;width:10px;height:10px;border-radius:2px;background:#2F7FC1;margin-right:5px;"></i>' + esc(lb) + '</span>' : '') + '</div>' +
      '<div style="overflow-x:auto;"><svg viewBox="0 0 ' + W + ' ' + H + '" width="100%" style="min-width:' + Math.min(W, 520) + 'px;max-height:220px;" role="img" aria-label="' + esc(la) + ' by month">' + out + '</svg></div>';
  }
  function table(cols, rows, foot) {
    if (!rows.length) return '<div class="kt-rp-note" style="padding:14px;background:#fff;border:1px dashed #CBD5E1;border-radius:12px;text-align:center;">No rows match these filters.</div>';
    return '<div class="kt-rp-panel" style="padding:0;overflow:hidden;"><div style="overflow-x:auto;"><table class="kt-rp-table"><thead><tr>' +
      cols.map(function (c) { return '<th' + (c.n ? ' class="n"' : '') + '>' + esc(c.t) + '</th>'; }).join('') + '</tr></thead><tbody>' +
      rows.map(function (r) { return '<tr>' + cols.map(function (c) { var v = c.f ? c.f(r) : esc(r[c.k]); return '<td' + (c.n ? ' class="n"' : '') + (c.sort != null ? ' data-kt-sort="' + esc(c.sort(r)) + '"' : '') + '>' + v + '</td>'; }).join('') + '</tr>'; }).join('') +
      '</tbody>' + (foot ? '<tfoot><tr>' + cols.map(function (c, i) { return '<td' + (c.n ? ' class="n"' : '') + '>' + (foot[c.k] != null ? foot[c.k] : (i === 0 ? 'Total' : '')) + '</td>'; }).join('') + '</tr></tfoot>' : '') +
      '</table></div></div>';
  }
  function tabs(id, list, cur) {
    return '<div role="tablist" aria-label="Report views" id="' + id + '" style="display:flex;gap:4px;margin:0 0 12px;">' +
      list.map(function (t) { return '<button type="button" role="tab" data-v="' + t[0] + '" aria-selected="' + (t[0] === cur ? 'true' : 'false') + '">' + t[1] + '</button>'; }).join('') + '</div>';
  }
  function notTracked(list) { return list && list.length ? '<div class="kt-rp-note">ℹ️ Not tracked: ' + list.map(esc).join(' · ') + '.</div>' : ''; }
  function hero(main, title, sub) { return '<div class="kt-page-hero"><h2>' + title + '</h2><p>' + esc(sub) + '</p></div>'; }
  function loading(main, title) { main.innerHTML = '<div class="kt-rp">' + hero(main, title, 'Loading…') + '</div>'; }
  function fail(main, title, e) {
    main.innerHTML = '<div class="kt-rp">' + hero(main, title, '') + '<div class="kt-rp-panel" style="color:#B91C1C;">' +
      esc((e && e.status === 403) ? 'This report is not available for your role.' : 'The report could not be loaded. Please try again.') + '</div></div>';
  }
  function back() { return '<a href="#reports" style="display:inline-block;font-size:13px;font-weight:700;color:#1F6080;text-decoration:none;margin:0 0 10px;">‹ All reports</a>'; }
  function wireTabs(main, id, key, redraw) {
    var bar = main.querySelector('#' + id); if (!bar) return;
    bar.addEventListener('click', function (e) {
      var b = e.target.closest && e.target.closest('[data-v]'); if (!b) return;
      ssSet(key, b.getAttribute('data-v'));
      [].forEach.call(bar.querySelectorAll('[data-v]'), function (x) { x.setAttribute('aria-selected', x === b ? 'true' : 'false'); });
      redraw(b.getAttribute('data-v'));
    });
  }
  function enhance() {
    if (KT.enhanceTables) setTimeout(KT.enhanceTables, 0);
    if (KT.sweepRowActions) setTimeout(KT.sweepRowActions, 0);
  }
  function person(r) {
    return (r.photo_url ? '<img class="kt-rp-av" src="' + esc(r.photo_url) + '" alt="">' : '') + '<b>' + esc(r.name) + '</b>' +
      (r.role || r.centre ? '<div style="font-size:11.5px;color:#64748B;">' + esc([r.role, r.centre].filter(Boolean).join(' · ')) + '</div>' : '');
  }

  /* ── PAYROLL REPORT ───────────────────────────────────────────── */
  var PF = null;
  async function renderPayroll(main) {
    css(); main.setAttribute('data-kt-pretty', '1');
    var T = '💼 Payroll report';
    PF = PF || { from: startOfYear(), to: today(0), group: '', centre_id: '', user_id: '', status: '', source: '' };
    loading(main, T);
    var d;
    try {
      var qs = Object.keys(PF).filter(function (k) { return PF[k]; }).map(function (k) { return k + '=' + encodeURIComponent(PF[k]); }).join('&');
      d = await Api.get('/admin/payroll/report' + (qs ? '?' + qs : ''));
    } catch (e) { fail(main, T, e); return; }
    var f = d.filters || {}, t = d.totals || {};
    var sel = function (id, label, opts, val) {
      return '<label>' + label + '<select data-f="' + id + '">' + opts.map(function (o) { return '<option value="' + esc(o[0]) + '"' + (String(o[0]) === String(val) ? ' selected' : '') + '>' + esc(o[1]) + '</option>'; }).join('') + '</select></label>';
    };
    var groupName = { educators: 'Educators', other: 'Other staff', contractors: 'Contractors' };
    var view = ssGet('kt_rp_pay_view', 'summary');
    main.innerHTML = '<div class="kt-rp">' + back() + hero(main, T, 'What payroll cost and who was paid, for any date range. Exports cover every row.') +
      '<div class="kt-rp-bar">' +
        '<label>From<input type="date" data-f="from" value="' + esc(PF.from) + '"></label>' +
        '<label>To<input type="date" data-f="to" value="' + esc(PF.to) + '"></label>' +
        sel('group', 'Group', [['', 'All groups']].concat((f.groups || []).map(function (g) { return [g, groupName[g] || g]; })), PF.group) +
        sel('centre_id', 'Centre', [['', 'All centres']].concat((f.centres || []).map(function (c) { return [c.id, c.name]; })), PF.centre_id) +
        sel('user_id', 'Staff member', [['', 'Everyone']].concat((f.people || []).map(function (p) { return [p.id, p.name]; }), (f.departed_people || []).map(function (p) { return [p.id, p.name + ' (former staff)']; })), PF.user_id) +
        sel('status', 'Status', [['', 'Any'], ['issued', 'Issued (not paid)'], ['paid', 'Paid']], PF.status) +
        sel('source', 'Source', [['', 'Any'], ['ilearn', 'iLearn'], ['kiddietrac', 'KiddieTrac']], PF.source) +
      '</div>' +
      '<div class="kt-rp-cards">' +
        card('green', '💵', 'Net paid', money(t.net), num(t.payslips) + ' payslips · ' + num(t.people) + ' people') +
        card('blue', '🧾', 'Gross', money(t.gross), 'Before deductions') +
        card('violet', '➖', 'Deductions', money(t.deductions), 'CPP ' + money(t.cpp) + ' · EI ' + money(t.ei) + ' · Tax ' + money(t.income_tax)) +
        card('teal', '🏖️', 'Vacation pay', money(t.vacation_pay), 'Included in pay') +
        card('amber', '⏳', 'Outstanding', money(t.outstanding_net), num(t.outstanding_count) + ' payslips issued, not marked paid') +
        card('rose', '⏱', 'Hours flags', num(t.flagged), 'Payslip hours differ from clock hours by more than ½ h') +
      '</div>' +
      '<div class="kt-rp-panel"><h3>Payroll by month</h3><p class="cap">Gross and net by the month each pay period ends. Hover a bar for the figure.</p>' +
        monthBars(d.months, 'gross', 'net', 'Gross', 'Net', money) + '</div>' +
      tabs('kt-rp-pay-tabs', [['summary', '📅 By period'], ['staff', '👥 By staff'], ['detail', '🧾 Every payslip'], ['hours', '⏱ Hours check'], ['outstanding', '⏳ Outstanding']], view) +
      '<div id="kt-rp-pay-out"></div></div>';
    [].forEach.call(main.querySelectorAll('[data-f]'), function (el) {
      el.addEventListener('change', function () { PF[el.getAttribute('data-f')] = el.value; renderPayroll(main); });
    });
    var out = main.querySelector('#kt-rp-pay-out');
    var draw = function (v) {
      var M = function (k) { return { k: k, n: true, f: function (r) { return money(r[k]); }, sort: function (r) { return String(Math.round((Number(r[k]) || 0) * 100)).padStart(12, '0'); } }; };
      var tm = function (rows, keys) { var o = {}; keys.forEach(function (k) { var s = 0; rows.forEach(function (r) { s += Number(r[k]) || 0; }); o[k] = money(s); }); return o; };
      var keys = ['gross', 'cpp', 'ei', 'income_tax', 'other_deductions', 'deductions', 'vacation_pay', 'net'];
      var moneyCols = [ Object.assign(M('gross'), { t: 'Gross' }), Object.assign(M('deductions'), { t: 'Deductions' }), Object.assign(M('vacation_pay'), { t: 'Vacation' }), Object.assign(M('net'), { t: 'Net' }) ];
      if (v === 'summary') {
        out.innerHTML = table([{ k: 'period', t: 'Period', f: function (r) { return '<b>' + period(r.period_start, r.period_end) + '</b><div style="font-size:11.5px;color:#64748B;">' + esc([r.frequency, groupName[r.group] || r.group].filter(Boolean).join(' · ')) + '</div>'; }, sort: function (r) { return r.period_end || ''; } },
          { k: 'staff', t: 'Staff paid', f: function (r) { var s = r.staff || []; return esc(s.slice(0, 3).join(', ')) + (s.length > 3 ? ' <span title="' + esc(s.slice(3).join(', ')) + '" style="color:#1F6080;font-weight:700;">+' + (s.length - 3) + ' more</span>' : ''); } },
          { k: 'payslips', t: 'Payslips', n: true }, { k: 'paid', t: 'Paid', n: true }].concat([Object.assign(M('gross'), { t: 'Gross' }), Object.assign(M('cpp'), { t: 'CPP' }), Object.assign(M('ei'), { t: 'EI' }), Object.assign(M('income_tax'), { t: 'Tax' }), Object.assign(M('other_deductions'), { t: 'Other' }), Object.assign(M('vacation_pay'), { t: 'Vacation' }), Object.assign(M('net'), { t: 'Net' })]),
          d.summary || [], Object.assign(tm(d.summary || [], keys), { payslips: num(t.payslips), paid: num(t.paid) }));
      } else if (v === 'staff') {
        out.innerHTML = table([{ k: 'name', t: 'Staff member', f: person, sort: function (r) { return r.name; } }, { k: 'payslips', t: 'Payslips', n: true }].concat(moneyCols).concat([
          { k: 'outstanding', t: 'Outstanding', n: true, f: function (r) { return r.outstanding ? '<span class="kt-rp-flag">' + money(r.outstanding) + '</span>' : '—'; } },
          { k: 'last_paid', t: 'Last paid', f: function (r) { return dt(r.last_paid); }, sort: function (r) { return r.last_paid || ''; } }]),
          d.staff || [], Object.assign(tm(d.staff || [], keys), { payslips: num(t.payslips), outstanding: money(t.outstanding_net) }));
      } else if (v === 'detail' || v === 'outstanding') {
        var rows = v === 'detail' ? (d.detail || []) : (d.outstanding || []);
        out.innerHTML = table([{ k: 'name', t: 'Staff member', f: person, sort: function (r) { return r.name; } },
          { k: 'period', t: 'Period', f: function (r) { return period(r.period_start, r.period_end); }, sort: function (r) { return r.period_end || ''; } },
          Object.assign(M('gross'), { t: 'Gross' }), Object.assign(M('cpp'), { t: 'CPP' }), Object.assign(M('ei'), { t: 'EI' }), Object.assign(M('income_tax'), { t: 'Tax' }), Object.assign(M('other_deductions'), { t: 'Other' }), Object.assign(M('vacation_pay'), { t: 'Vacation' }), Object.assign(M('net'), { t: 'Net' }),
          { k: 'status', t: 'Status', f: function (r) { return r.status === 'paid' ? '✓ Paid ' + dt(r.paid_at) : 'Issued ' + dt(r.issued_at); } },
          { k: 'source', t: 'Source' }], rows, tm(rows, keys));
      } else if (v === 'hours') {
        out.innerHTML = '<div class="kt-rp-note">Payslip hours against KiddieTrac clock hours for the same person and dates. Flat-amount payslips (no hours) show clock hours only.</div>' +
          table([{ k: 'name', t: 'Staff member', f: person, sort: function (r) { return r.name; } },
            { k: 'period', t: 'Period', f: function (r) { return period(r.period_start, r.period_end); }, sort: function (r) { return r.period_end || ''; } },
            { k: 'paid_hours', t: 'Payslip hours', n: true, f: function (r) { return r.paid_hours == null ? 'flat' : num(r.paid_hours); } },
            { k: 'clock_hours', t: 'Clock hours', n: true, f: function (r) { return r.clock_hours == null ? '—' : num(r.clock_hours); } },
            { k: 'hours_diff', t: 'Difference', n: true, f: function (r) { return r.hours_diff == null ? '—' : (r.hours_flag ? '<span class="kt-rp-flag">⚠ ' + (r.hours_diff > 0 ? '+' : '') + num(r.hours_diff) + ' h</span>' : num(r.hours_diff) + ' h'); } }],
            d.hours || []);
      }
      enhance();
    };
    wireTabs(main, 'kt-rp-pay-tabs', 'kt_rp_pay_view', draw);
    draw(view);
  }

  /* ── CAMPAIGN REPORT (agency) ─────────────────────────────────── */
  var CF = null;
  async function renderCampaigns(main) {
    css(); main.setAttribute('data-kt-pretty', '1');
    var T = '📣 Campaign report';
    CF = CF || { from: today(-365), to: today(0) };
    loading(main, T);
    var d;
    try { d = await Api.get('/marketing/campaigns-report?from=' + CF.from + '&to=' + CF.to); } catch (e) { fail(main, T, e); return; }
    var t = d.totals || {};
    var view = ssGet('kt_rp_camp_view', 'campaigns');
    main.innerHTML = '<div class="kt-rp">' + back() + hero(main, T, 'How your campaigns and announcements to families performed. Only figures the platform recorded.') +
      '<div class="kt-rp-bar"><label>From<input type="date" data-c="from" value="' + esc(CF.from) + '"></label><label>To<input type="date" data-c="to" value="' + esc(CF.to) + '"></label></div>' +
      '<div class="kt-rp-cards">' +
        card('blue', '📣', 'Campaigns sent', num(t.campaigns_sent), 'From Marketing → Campaigns') +
        card('teal', '📢', 'Announcements', num(t.announcements), 'Sent to families in this range') +
        card('green', '📬', 'Emails delivered', num(t.delivered), 'Campaigns + announcement emails') +
        card('violet', '👀', 'Open rate', pct(t.open_rate), num(t.opens) + ' opens recorded') +
        card('amber', '🔗', 'Clicks', num(t.clicks), 'Campaign link clicks') +
      '</div>' +
      '<div class="kt-rp-panel"><h3>By month</h3><p class="cap">Emails delivered and opened each month.</p>' + monthBars(d.months, 'delivered', 'opens', 'Delivered', 'Opened') + '</div>' +
      tabs('kt-rp-camp-tabs', [['campaigns', '📣 Campaigns'], ['announcements', '📢 Announcements']], view) +
      '<div id="kt-rp-camp-out"></div>' + notTracked(d.not_tracked) + '</div>';
    [].forEach.call(main.querySelectorAll('[data-c]'), function (el) { el.addEventListener('change', function () { CF[el.getAttribute('data-c')] = el.value; renderCampaigns(main); }); });
    var out = main.querySelector('#kt-rp-camp-out');
    var draw = function (v) {
      if (v === 'campaigns') {
        out.innerHTML = table([{ k: 'title', t: 'Campaign', f: function (r) { return '<b>' + esc(r.title) + '</b>' + (r.automated ? ' <span style="font-size:11px;color:#0F766E;">automated</span>' : ''); } },
          { k: 'channel', t: 'Channel' }, { k: 'status', t: 'Status' }, { k: 'sent_at', t: 'Sent', f: function (r) { return dt(r.sent_at); }, sort: function (r) { return r.sent_at || ''; } },
          { k: 'recipients', t: 'Audience', n: true, f: function (r) { return num(r.recipients); } }, { k: 'delivered', t: 'Delivered', n: true, f: function (r) { return num(r.delivered); } },
          { k: 'failed', t: 'Failed', n: true, f: function (r) { return num(r.failed); } }, { k: 'opens', t: 'Opens', n: true, f: function (r) { return num(r.opens); } },
          { k: 'clicks', t: 'Clicks', n: true, f: function (r) { return num(r.clicks); } }, { k: 'open_rate', t: 'Open rate', n: true, f: function (r) { return pct(r.open_rate); } },
          { k: 'click_rate', t: 'Click rate', n: true, f: function (r) { return pct(r.click_rate); } }], d.campaigns || []);
      } else {
        out.innerHTML = '<div class="kt-rp-note">Email figures are the announcement emails logged for your agency within two days of sending. SMS, push and voice deliveries are not counted here.</div>' +
          table([{ k: 'title', t: 'Announcement', f: function (r) { return '<b>' + esc(r.title) + '</b>'; } }, { k: 'channels', t: 'Channels', f: function (r) { return esc((r.channels || []).join(', ') || 'in-app'); } },
            { k: 'sent_at', t: 'Sent', f: function (r) { return dt(r.sent_at); }, sort: function (r) { return r.sent_at || ''; } },
            { k: 'emails_sent', t: 'Emails sent', n: true, f: function (r) { return r.emails_sent == null ? '—' : num(r.emails_sent); } },
            { k: 'emails_opened', t: 'Opened', n: true, f: function (r) { return r.emails_opened == null ? '—' : num(r.emails_opened); } },
            { k: 'emails_failed', t: 'Failed', n: true, f: function (r) { return r.emails_failed == null ? '—' : num(r.emails_failed); } },
            { k: 'open_rate', t: 'Open rate', n: true, f: function (r) { return pct(r.open_rate); } }], d.announcements || []);
      }
      enhance();
    };
    wireTabs(main, 'kt-rp-camp-tabs', 'kt_rp_camp_view', draw);
    draw(view);
  }

  /* ── KIDDIETRAC REPORTS (superadmin only) ─────────────────────── */
  var KF = null;
  async function renderKt(main) {
    css(); main.setAttribute('data-kt-pretty', '1');
    var T = '🔒 KiddieTrac reports';
    if (!isSuper()) { main.innerHTML = '<div class="kt-rp">' + hero(main, T, '') + '<div class="kt-rp-panel">These reports are for KiddieTrac superadmins only.</div></div>'; return; }
    KF = KF || { from: startOfYear(), to: today(0) };
    var tab = ssGet('kt_rp_kt_tab', 'sales');
    main.innerHTML = '<div class="kt-rp" data-kt-keep-brand="1" data-kt-export-brand="platform">' + back() + hero(main, T, 'KiddieTrac\'s own sales, leads, cold reach and campaigns. Superadmins only — never shown to agencies.') +
      '<div class="kt-rp-bar"><label>From<input type="date" data-k="from" value="' + esc(KF.from) + '"></label><label>To<input type="date" data-k="to" value="' + esc(KF.to) + '"></label>' +
      '<a href="#platform-sales" style="margin-left:auto;align-self:center;font-size:13px;font-weight:700;color:#1F6080;text-decoration:none;">💹 Revenue by part number ›</a></div>' +
      tabs('kt-rp-kt-tabs', [['sales', '💹 Sales'], ['leads', '🎯 Leads'], ['coldreach', '📞 Cold reach'], ['campaigns', '📣 Campaigns']], tab) +
      '<div id="kt-rp-kt-out"><div class="kt-rp-note">Loading…</div></div></div>';
    [].forEach.call(main.querySelectorAll('[data-k]'), function (el) { el.addEventListener('change', function () { KF[el.getAttribute('data-k')] = el.value; renderKt(main); }); });
    var out = main.querySelector('#kt-rp-kt-out');
    var draw = async function (v) {
      out.innerHTML = '<div class="kt-rp-note">Loading…</div>';
      var d;
      try { d = await Api.get('/platform/reports/' + v + '?from=' + KF.from + '&to=' + KF.to); }
      catch (e) { out.innerHTML = '<div class="kt-rp-panel" style="color:#B91C1C;">' + esc(e && e.status === 403 ? 'Superadmins only.' : 'The report could not be loaded.') + '</div>'; return; }
      var t = d.totals || {}, h = '';
      if (v === 'sales') {
        var rv = d.revenue || {};
        h = '<div class="kt-rp-cards">' +
          card('blue', '📈', 'Open pipeline', money(t.pipeline_value), num(t.open_leads) + ' open leads') +
          card('green', '🏆', 'Won / lost', num(t.won) + ' / ' + num(t.lost), 'Win rate ' + pct(t.win_rate)) +
          card('violet', '📝', 'Proposals', num((d.quotes || {}).sent) + ' sent', num((d.quotes || {}).signed) + ' signed · ' + pct(t.quote_accept_rate) + ' accepted') +
          card('teal', '🔁', 'MRR', rv.mrr_cents != null ? money(rv.mrr_cents / 100) : '—', 'ARR ' + (rv.arr_cents != null ? money(rv.arr_cents / 100) : '—')) +
          card('amber', '💵', 'Invoiced', rv.invoiced_cents != null ? money(rv.invoiced_cents / 100) : '—', 'Collected ' + (rv.collected_cents != null ? money(rv.collected_cents / 100) : '—')) +
          '</div><div class="kt-rp-grid2"><div class="kt-rp-panel"><h3>Pipeline by stage</h3><p class="cap">Open leads and their estimated value.</p>' + meters(d.stages || [], 'stage', 'value', money) + '</div>' +
          '<div class="kt-rp-panel"><h3>Lost reasons</h3><p class="cap">Why deals were lost.</p>' + meters(d.lost_reasons || [], 'reason', 'leads') + '</div></div>' +
          '<div class="kt-rp-panel"><h3>By month</h3><p class="cap">New leads and proposals sent each month.</p>' + monthBars(d.months, 'leads', 'quotes_sent', 'New leads', 'Proposals sent') + '</div>' +
          '<h3 style="font-size:15px;margin:6px 0 8px;">By owner</h3>' + table([{ k: 'owner', t: 'Owner' }, { k: 'open', t: 'Open', n: true }, { k: 'won', t: 'Won', n: true }, { k: 'lost', t: 'Lost', n: true }, { k: 'pipeline', t: 'Pipeline', n: true, f: function (r) { return money(r.pipeline); } }], d.by_owner || []) +
          '<h3 style="font-size:15px;margin:6px 0 8px;">Proposals</h3>' + table([{ k: 'number', t: 'Proposal' }, { k: 'status', t: 'Status' }, { k: 'total', t: 'Total', n: true, f: function (r) { return money(r.total, r.currency); } },
            { k: 'sent_at', t: 'Sent', f: function (r) { return dt(r.sent_at); } }, { k: 'signed_at', t: 'Signed', f: function (r) { return dt(r.signed_at); } }, { k: 'declined_at', t: 'Declined', f: function (r) { return dt(r.declined_at); } }], d.quote_list || []);
      } else if (v === 'leads') {
        h = '<div class="kt-rp-cards">' +
          card('blue', '🎯', 'New leads', num(t.leads), num(t.open) + ' still open') +
          card('green', '✅', 'Became customers', num(t.converted), 'Conversion ' + pct(t.conversion_rate)) +
          card('violet', '⚡', 'First response', t.median_first_response_hours == null ? '—' : t.median_first_response_hours + ' h', 'Median time to first call, email, text or meeting (' + num(t.responded) + ' leads)') +
          card('amber', '📅', 'Follow-ups', num(t.followups_overdue) + ' overdue', num(t.followups_due_today) + ' due today') +
          card('teal', '🤝', 'Meetings booked', num(t.meetings_booked), num(t.meetings_total) + ' requests in range') +
          '</div><div class="kt-rp-grid2"><div class="kt-rp-panel"><h3>By source</h3><p class="cap">Where leads came from.</p>' + meters(d.by_source || [], 'source', 'leads') + '</div>' +
          '<div class="kt-rp-panel"><h3>Stage funnel</h3><p class="cap">Leads that reached at least each stage.</p>' + meters(d.funnel || [], 'stage', 'leads') + '</div>' +
          '<div class="kt-rp-panel"><h3>Aging (open leads)</h3><p class="cap">Days since the last activity.</p>' + meters(d.aging || [], 'bucket', 'leads') + '</div></div>' +
          table([{ k: 'name', t: 'Lead', f: function (r) { return '<b>' + esc(r.name) + '</b>' + (r.contact && r.contact !== r.name ? '<div style="font-size:11.5px;color:#64748B;">' + esc(r.contact) + '</div>' : ''); } },
            { k: 'source', t: 'Source' }, { k: 'stage', t: 'Stage' }, { k: 'status', t: 'Status' }, { k: 'owner', t: 'Owner' },
            { k: 'created_at', t: 'Created', f: function (r) { return dt(r.created_at); }, sort: function (r) { return r.created_at || ''; } },
            { k: 'first_response_hours', t: 'First response', n: true, f: function (r) { return r.first_response_hours == null ? '—' : r.first_response_hours + ' h'; } },
            { k: 'follow_up_date', t: 'Follow-up', f: function (r) { return dt(r.follow_up_date); } }, { k: 'converted', t: 'Customer', f: function (r) { return r.converted ? '✓' : ''; } }], d.list || []);
      } else if (v === 'coldreach') {
        h = '<div class="kt-rp-cards">' +
          card('blue', '🗂', 'Prospects', num(t.prospects), num(t.emailed) + ' emailed · ' + num(t.queued) + ' queued') +
          card('green', '📧', 'Emails sent', num(t.emails_sent), num(t.emails_failed) + ' failed') +
          card('violet', '👀', 'Opened / clicked', num(t.opened) + ' / ' + num(t.clicked), 'Replies ' + num(t.replies)) +
          card('teal', '📞', 'Calls logged', num(t.calls), num(t.called) + ' prospects called') +
          card('amber', '🤝', 'Meetings booked', num(t.meetings_booked), num(t.to_leads) + ' became leads') +
          card('rose', '🚫', 'Opted out', num(t.do_not_contact + t.unsubscribed), num(t.unsubscribed) + ' unsubscribed · ' + num(t.do_not_contact) + ' do-not-contact') +
          '</div><div class="kt-rp-grid2"><div class="kt-rp-panel"><h3>Sequence steps</h3><p class="cap">Emails sent at each step of the sequence.</p>' + meters((d.steps || []), 'label', 'sent') + '</div>' +
          '<div class="kt-rp-panel"><h3>Call outcomes</h3><p class="cap">Calls logged on prospects.</p>' + meters(d.calls || [], 'outcome', 'count') + '</div></div>' +
          '<div class="kt-rp-panel"><h3>Daily send volume</h3><p class="cap">Cold emails sent per day' + (t.daily_cap ? ' (daily cap ' + t.daily_cap + ')' : '') + '.</p>' +
            monthBars((d.daily || []).map(function (x) { return { month: x.day, sent: x.sent, cap: t.daily_cap || 0 }; }), 'sent', t.daily_cap ? 'cap' : null, 'Sent', 'Daily cap') + '</div>' +
          '<h3 style="font-size:15px;margin:6px 0 8px;">Batches</h3>' + table([{ k: 'name', t: 'Batch', f: function (r) { return '<b>' + esc(r.name) + '</b><div style="font-size:11.5px;color:#64748B;">' + dt(r.created_at) + '</div>'; } },
            { k: 'prospects', t: 'Prospects', n: true }, { k: 'with_email', t: 'With email', n: true }, { k: 'emailed', t: 'Emailed', n: true }, { k: 'called', t: 'Called', n: true },
            { k: 'opened', t: 'Opened', n: true }, { k: 'replied', t: 'Replied', n: true }, { k: 'to_lead', t: 'Leads', n: true }, { k: 'dnc', t: 'Do not contact', n: true }, { k: 'queued', t: 'Queued', n: true }], d.batches || []);
      } else {
        h = '<div class="kt-rp-cards">' +
          card('blue', '📣', 'Campaigns', num(t.campaigns), num(t.sent) + ' emails sent') +
          card('violet', '👀', 'Open rate', pct(t.open_rate), num(t.opened) + ' opened · ' + num(t.clicked) + ' clicked') +
          card('green', '💬', 'Responses', num(t.responded), num(t.leads) + ' leads from campaigns') +
          card('teal', '📝', 'Proposals', num(t.proposals), num(t.won) + ' won') +
          card('rose', '🚫', 'Unsubscribed', num(t.unsubscribed), 'Across all campaigns') +
          '</div>' + table([{ k: 'name', t: 'Campaign', f: function (r) { return '<b>' + esc(r.name) + '</b><div style="font-size:11.5px;color:#64748B;">' + esc(r.subject || '') + '</div>'; } },
            { k: 'status', t: 'Status' }, { k: 'sent_at', t: 'Sent', f: function (r) { return dt(r.sent_at); }, sort: function (r) { return r.sent_at || ''; } },
            { k: 'sent', t: 'Sent', n: true }, { k: 'opened', t: 'Opened', n: true }, { k: 'clicked', t: 'Clicked', n: true }, { k: 'open_rate', t: 'Open rate', n: true, f: function (r) { return pct(r.open_rate); } },
            { k: 'responded', t: 'Responded', n: true }, { k: 'leads', t: 'Leads', n: true }, { k: 'proposals', t: 'Proposals', n: true }, { k: 'won', t: 'Won', n: true }, { k: 'unsubscribed', t: 'Unsubs', n: true },
            { k: 'top_links', t: 'Top links', f: function (r) { return (r.top_links || []).map(function (l) { return esc(String(l.link).replace(/^https?:\/\/(www\.)?/, '').slice(0, 40)) + ' (' + l.clicks + ')'; }).join('<br>') || '—'; } }], d.campaigns || []);
      }
      out.innerHTML = h + notTracked(d.not_tracked);
      enhance();
    };
    wireTabs(main, 'kt-rp-kt-tabs', 'kt_rp_kt_tab', draw);
    draw(tab);
  }

  ['agency_admin', 'centre_director', 'platform_admin'].forEach(function (role) {
    Shell.registerScreen(role + ':payroll-report', renderPayroll);
    Shell.registerScreen(role + ':campaign-report', renderCampaigns);
  });
  ['platform_admin', 'agency_admin'].forEach(function (role) { Shell.registerScreen(role + ':kt-reports', renderKt); });

  /* Cards for the Reports screen (screen-reports.js asks for them). */
  KT.reportsPlusCards = function () {
    var u = user(), roles = u.roles || [], va = viewAs();
    var staffMgr = !va && (roles.indexOf('agency_admin') > -1 || roles.indexOf('centre_director') > -1 || roles.indexOf('platform_admin') > -1 || u.is_platform_admin);
    if (va && /agency_admin|centre_director/.test(va)) staffMgr = true;
    var link = function (href, icon, title, desc, key) {
      return '<a href="' + href + '" ' + (key ? 'data-kt-rp-tab="' + key + '" ' : '') + 'class="kt-rep-card kt-report-noprint" style="text-align:left;cursor:pointer;transition:box-shadow .15s,border-color .15s;background:#fff;border:1px solid #E7EBF0;border-radius:14px;padding:16px;display:flex;gap:12px;align-items:flex-start;text-decoration:none;">' +
        '<div style="font-size:30px;line-height:1;flex-shrink:0;">' + icon + '</div><div><div style="font-weight:700;font-size:15px;color:#0D1B2A;margin-bottom:2px;">' + esc(title) + '</div>' +
        '<div style="font-size:12.5px;color:#64748B;line-height:1.35;">' + esc(desc) + '</div></div></a>';
    };
    var html = '';
    if (staffMgr) {
      html += '<div class="kt-report-noprint" style="margin:0 0 18px;"><div class="kt-rep-sec">Payroll &amp; communications</div>' +
        '<div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(280px,1fr));gap:14px;">' +
        link('#payroll-report', '💼', 'Payroll report', 'Gross, deductions and net by period or by person, every payslip, hours check and what is still unpaid.') +
        link('#campaign-report', '📣', 'Campaign report', 'Your campaigns and announcements to families: delivered, opened, clicked.') +
        '</div></div>';
    }
    if (isSuper()) {
      // data-kt-keep-brand: these are KiddieTrac's own figures, so a white-label agency's name never replaces it here.
      html += '<div class="kt-report-noprint" data-kt-keep-brand="1" style="margin:0 0 18px;"><div class="kt-rep-sec">🔒 KiddieTrac · superadmins only</div>' +
        '<div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(280px,1fr));gap:14px;">' +
        link('#kt-reports', '💹', 'Sales report', 'Pipeline by stage, proposals, won and lost, MRR and revenue booked.', 'sales') +
        link('#kt-reports', '🎯', 'Leads report', 'Leads by source, funnel, response times, follow-ups, meetings and conversion.', 'leads') +
        link('#kt-reports', '📞', 'Cold reach report', 'Batches, sequence steps, opens, replies, calls, opt-outs and daily volume.', 'coldreach') +
        link('#kt-reports', '📣', 'Sales campaigns report', 'KiddieTrac campaigns: sent, opened, clicked, responses, leads and proposals.', 'campaigns') +
        '</div></div>';
    }
    return html;
  };
  document.addEventListener('click', function (e) {
    var a = e.target && e.target.closest ? e.target.closest('[data-kt-rp-tab]') : null;
    if (a) ssSet('kt_rp_kt_tab', a.getAttribute('data-kt-rp-tab'));
  }, true);
})(window);
