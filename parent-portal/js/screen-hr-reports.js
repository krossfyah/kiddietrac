/* ═══════════════════════════════════════════════════════════════════════
   screen-hr-reports.js - KiddieTrac HR Phase 4, batch 5 (2026-11-27).

   Two self-contained HR tabs (each its own key + render function, so the coming "Human Resources" sidebar can lift
   them out as their own entries without a rewrite):
   - `dashboard` -> drawDashboard(host): the HR setup checklist (leave types, rules, files complete, approvers, staff
     signed in), probation ending soon (passed / extend), the missing-info count and who has not signed in yet,
     plus the agency's probation / reminder / invite settings (admins).
   - `reports` -> drawReports(host): headcount, starters & leavers + turnover, retention, tenure, leave liability
     (pay viewers only), overtime, lateness, schedule, annual leave vs last year, team absence for a range, missing
     info (with the dated minimum-wage table), not signed in yet (Resend for admins), probation, and "everything we
     hold" about one person. Every report: Print (KT.printDoc); CSV / Excel / PDF come from the portal's export bar under each table.
   The server scopes everything (App\Http\Controllers\Api\HrReportsController): admins the agency, directors their own
   centres; nothing is filtered here. Lazy: a "d" dependency of screen-hr.js, never loaded on its own.
   ═══════════════════════════════════════════════════════════════════════ */
(function (window) {
  'use strict';
  var KT = window.KT;
  if (!KT || !KT.Api) return;
  var Api = KT.Api;

  function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }
  function toast(i, t, m, c) { try { KT.toast && KT.toast(i, t, m || '', c); } catch (e) {} }
  function saved(m) { toast('✅', 'Saved', m || '', '#16A34A'); }
  function errMsg(e) {
    var d = e && e.data;
    if (d && d.errors) { var k = Object.keys(d.errors)[0]; if (k && d.errors[k] && d.errors[k][0]) return d.errors[k][0]; }
    return (d && d.message) || (e && e.message && !/^HTTP \d|fetch|network/i.test(e.message) ? e.message : '') || 'Something went wrong. Please try again.';
  }
  function fail(t, e) { toast('⚠️', t, errMsg(e), '#DC2626'); }
  var MON = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
  function day(s) { var m = /^(\d{4})-(\d{2})-(\d{2})/.exec(s || ''); return m ? MON[+m[2] - 1] + ' ' + (+m[3]) + ', ' + m[1] : ''; }
  function today() { try { if (KT.agencyToday) return KT.agencyToday(); } catch (e) {} var d = new Date(); return d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0'); }
  function addDays(ymd, n) { var p = ymd.split('-'); var d = new Date(+p[0], +p[1] - 1, +p[2] + n); return d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0'); }
  function num(v) { return v == null || v === '' ? '' : String(Math.round((+v || 0) * 100) / 100); }
  function money(n) { if (n == null || n === '') return ''; var v = Number(n) || 0; return '$' + v.toFixed(2).replace(/\B(?=(\d{3})+(?!\d))/g, ','); }
  function pill(bg, fg, t) { return '<span data-kt-no-tone style="display:inline-block;padding:2px 9px;border-radius:999px;font-size:12px;font-weight:700;background:' + bg + ';color:' + fg + ';white-space:nowrap;">' + esc(t) + '</span>'; }
  function modal(o) { return KT.Shell.Modal.open(o); }
  function el(html) { var d = document.createElement('div'); d.innerHTML = html; return d; }
  function val(box, k) { var e = box.querySelector('[data-f="' + k + '"]'); if (!e) return null; var v = String(e.value || '').trim(); return v === '' ? null : v; }
  function msg(box, t) { var m = box.querySelector('[data-msg]'); if (m) m.textContent = t || ''; }
  function field(label, inner) { return '<div><label class="hr-l">' + esc(label) + '</label>' + inner + '</div>'; }
  var ERR = '<div data-msg style="color:#B91C1C;font-size:13px;margin-top:8px;"></div>';
  function goPerson(id) { location.hash = '#hr?tab=people&person=' + id; }

  var CSS_ID = 'kt-hrr-css';
  function css() {
    if (document.getElementById(CSS_ID)) return;
    var s = document.createElement('style'); s.id = CSS_ID;
    s.textContent = '.hrr-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(min(320px,100%),1fr));gap:12px;}'
      + '.hrr-bar{height:8px;border-radius:999px;background:#E2E8F0;overflow:hidden;margin:6px 0 10px;}.hrr-bar i{display:block;height:100%;background:#0E7490;border-radius:999px;}'
      + '.hrr-ck{display:flex;gap:10px;align-items:center;padding:7px 0;border-bottom:1px dashed #EEF2F6;font-size:13px;}.hrr-ck:last-child{border-bottom:0;}'
      + '.hrr-ck .t{flex:1;min-width:0;}.hrr-ck .m{width:22px;height:22px;border-radius:50%;display:inline-flex;align-items:center;justify-content:center;font-size:12px;font-weight:800;flex:0 0 auto;}'
      + '.hrr-ck .m.y{background:#DCFCE7;color:#047857;}.hrr-ck .m.n{background:#F1F5F9;color:#64748B;border:1px solid #CBD5E1;}'
      + '.hrr-kinds{display:flex;gap:6px;flex-wrap:wrap;margin:0 0 10px;}'
      + '.hrr-kind{height:28px;padding:0 11px;border-radius:999px;border:1px solid #E2E8F0;background:#fff;font-size:12px;font-weight:700;cursor:pointer;color:#475569;}'
      + '.hrr-kind.on{background:#E0F2FE;border-color:#0E7490;color:#0E7490;}'
      + '.hrr-tt{font-size:12px;letter-spacing:.05em;text-transform:uppercase;color:#0E7490;font-weight:800;margin:14px 0 6px;}'
      + '.hrr-miss{display:inline-block;margin:0 4px 3px 0;}'
      + 'html.kt-dark .hrr-bar{background:#334155;}html.kt-dark .hrr-ck{border-color:#1E293B;}html.kt-dark .hrr-kind{background:#0F172A;border-color:#334155;color:#E2E8F0;}'
      + 'html.kt-dark .hrr-kind.on{background:#0B2530;border-color:#5EEAD4;color:#5EEAD4;}html.kt-dark .hrr-tt{color:#5EEAD4;}';
    document.head.appendChild(s);
  }

  var STATE = { overdue: ['#FEF2F2', '#B91C1C', 'Overdue'], due: ['#FFFBEB', '#B45309', 'Ending soon'], on: ['#E0F2FE', '#075985', 'In probation'],
    no_start: ['#F1F5F9', '#475569', 'No start date'], passed: ['#ECFDF5', '#047857', 'Passed'], old: ['#F1F5F9', '#475569', 'Ended (not recorded)'] };
  function stPill(s) { var c = STATE[s] || STATE.on; return pill(c[0], c[1], c[2]); }

  // ── probation actions (Dashboard + Reports) ─────────────────────────────
  function probationMenu(r, done) {
    return [['Mark probation passed', function () { probationAct(r, 'passed', done); }], ['Extend probation', function () { probationExtend(r, done); }],
      r.override || r.passed_on ? ['Back to the agency length', function () { probationAct(r, 'reset', done); }] : null, ['Open HR file', function () { goPerson(r.user_id); }]];
  }
  function probationAct(r, action, done) {
    Api.post('/hr/people/' + r.user_id + '/probation', { action: action }).then(function () { saved(action === 'passed' ? 'Probation marked passed.' : 'Probation reset.'); if (done) done(); }, function (e) { fail('Could not save', e); });
  }
  function probationExtend(r, done) {
    var box = el('<div class="hr-g">' + field('New end date', '<input class="hr-i" type="date" data-f="end_on" style="width:100%;" value="' + esc(addDays(r.end_on || today(), 30)) + '">')
      + field('Note (optional)', '<input class="hr-i" data-f="note" maxlength="255" style="width:100%;">') + '</div>' + ERR);
    modal({ title: 'Extend probation: ' + r.name, body: box, actions: [{ label: 'Cancel' }, { label: 'Save', primary: true, busyLabel: 'Saving…', handler: function () {
      return Api.post('/hr/people/' + r.user_id + '/probation', { action: 'extend', end_on: val(box, 'end_on'), note: val(box, 'note') })
        .then(function () { saved('Probation extended.'); if (done) done(); }, function (e) { msg(box, errMsg(e)); return false; });
    } }] });
  }
  function menu(anchor, items) {
    var bs = items.filter(Boolean).map(function (it) { var b = document.createElement('button'); b.type = 'button'; b.textContent = it[0]; b.addEventListener('click', function () { it[1](); }); return b; });
    if (KT.openActionMenu) KT.openActionMenu(anchor, bs); else if (bs[0]) bs[0].click();
  }

  // ── Dashboard ───────────────────────────────────────────────────────────
  async function drawDashboard(host) {
    css();
    var r;
    try { r = await Api.get('/hr/dashboard'); } catch (e) { host.innerHTML = '<div class="hr-note">' + esc(errMsg(e)) + '</div>'; return; }
    if (!host.isConnected) return;
    var reload = function () { if (host.isConnected) drawDashboard(host); };
    var c = r.checklist || { items: [] }, can = r.can || {};
    var h = '<div class="hrr-grid">';
    h += '<div class="hr-pc"><h4>HR setup checklist</h4><div class="hr-sub">' + esc(c.done + ' of ' + c.total + ' done') + '</div><div class="hrr-bar"><i style="width:' + (+c.pct || 0) + '%;"></i></div>'
      + c.items.map(function (it) {
        return '<div class="hrr-ck"><span class="m ' + (it.done ? 'y' : 'n') + '">' + (it.done ? '✓' : '') + '</span><div class="t"><b>' + esc(it.label) + '</b><div class="hr-sub">' + esc(it.detail || '') + '</div></div>'
          + (!it.done && (!it.admin || can.admin) ? '<button type="button" class="hr-link" data-kt-iconized="1" data-go="' + esc(it.tab) + '"' + (it.report ? ' data-report="' + esc(it.report) + '"' : '') + '>Set up</button>' : '') + '</div>';
      }).join('') + '</div>';
    var pr = r.probation || [];
    h += '<div class="hr-pc"><h4>Probation ending</h4>' + (pr.length ? pr.map(function (p, i) {
      return '<div class="hrr-ck"><div class="t"><b data-no-i18n>' + esc(p.name) + '</b> ' + stPill(p.state) + '<div class="hr-sub">' + esc('Ends ' + day(p.end_on) + (p.days_left < 0 ? ' (' + (-p.days_left) + ' days ago)' : p.days_left === 0 ? ' (today)' : ' (in ' + p.days_left + ' days)')) + (p.centre ? ' · <span data-no-i18n>' + esc(p.centre) + '</span>' : '') + '</div></div>'
        + '<button type="button" class="kt-ka-kebab hrf-keb" data-pk="' + i + '" aria-label="Actions" data-kt-iconized="1" style="width:30px;height:30px;border-radius:8px;border:1px solid #E2E8F0;background:#fff;cursor:pointer;">⋮</button></div>';
    }).join('') : '<div class="hr-sub">Nobody\'s probation ends in the next ' + '30 days.</div>')
      + '<div class="hr-bar" style="margin:8px 0 0;"><button type="button" class="hr-b" data-go="reports" data-report="probation" data-kt-iconized="1">Everyone in probation</button>'
      + (can.admin ? '<button type="button" class="hr-b" data-act="settings" data-kt-iconized="1">Probation and invite settings</button>' : '') + '</div></div>';
    var m = r.missing || {}, iv = r.invites || {};
    h += '<div class="hr-pc"><h4>To do</h4>'
      + '<div class="hrr-ck"><span class="m ' + (m.people && m.complete === m.people ? 'y' : 'n') + '">' + (m.people && m.complete === m.people ? '✓' : '') + '</span><div class="t"><b>Missing information</b><div class="hr-sub">'
      + esc((m.people - m.complete) + ' of ' + m.people + ' people have something missing (start date, manager, emergency contact' + (can.pay ? ', pay details' : '') + ')') + '</div></div><button type="button" class="hr-link" data-kt-iconized="1" data-go="reports" data-report="missing">See the list</button></div>'
      + '<div class="hrr-ck"><span class="m ' + (iv.not_signed_in ? 'n' : 'y') + '">' + (iv.not_signed_in ? '' : '✓') + '</span><div class="t"><b>Not signed in yet</b><div class="hr-sub">'
      + esc(iv.not_signed_in + ' of ' + iv.people + ' have never signed in' + (iv.expired ? ', ' + iv.expired + ' invite' + (iv.expired === 1 ? '' : 's') + ' expired' : '')) + '</div></div><button type="button" class="hr-link" data-kt-iconized="1" data-go="reports" data-report="invites">See who</button></div>'
      + '</div>';
    h += '</div>';
    host.innerHTML = h;
    host.querySelectorAll('[data-go]').forEach(function (b) {
      b.addEventListener('click', function () {
        var tab = b.getAttribute('data-go'), rep = b.getAttribute('data-report');
        if (rep) R.kind = rep;
        location.hash = '#hr?tab=' + tab + (rep ? '&report=' + rep : '');
      });
    });
    host.querySelectorAll('[data-pk]').forEach(function (b) { b.addEventListener('click', function () { menu(b, probationMenu(pr[+b.getAttribute('data-pk')], reload)); }); });
    var st = host.querySelector('[data-act="settings"]'); if (st) st.addEventListener('click', function () { openSettings(reload); });
  }

  async function openSettings(done) {
    var r;
    try { r = await Api.get('/hr/compliance/settings'); } catch (e) { return fail('Could not open the settings', e); }
    var s = r.settings || {};
    var box = el('<div class="hr-g">' + field('Probation length (days, 0 = none)', '<input class="hr-i" type="number" min="0" max="730" data-f="probation_days" style="width:100%;" value="' + esc(s.probation_days) + '">')
      + field('Remind the manager (days before it ends, 0 = never)', '<input class="hr-i" type="number" min="0" max="90" data-f="remind_days" style="width:100%;" value="' + esc(s.remind_days) + '">')
      + field('An invite counts as expired after (days, 0 = never)', '<input class="hr-i" type="number" min="0" max="365" data-f="invite_days" style="width:100%;" value="' + esc(s.invite_days) + '">') + '</div>'
      + '<div class="hr-sub" style="margin-top:8px;">Probation ends on the start date plus this length unless you set a date for one person (Extend). The manager on their HR file gets a bell, or their centre director. '
      + 'An expired invite is marked on the "Not signed in yet" list so you can resend it; the sign-in itself is not blocked.</div>' + ERR);
    modal({ title: 'Probation and invite settings', body: box, actions: [{ label: 'Cancel' }, { label: 'Save', primary: true, busyLabel: 'Saving…', handler: function () {
      return Api.put('/hr/compliance/settings', { probation_days: +val(box, 'probation_days') || 0, remind_days: +val(box, 'remind_days') || 0, invite_days: +val(box, 'invite_days') || 0 })
        .then(function () { saved(); if (done) done(); }, function (e) { msg(box, errMsg(e)); return false; });
    } }] });
  }

  // ── Reports ─────────────────────────────────────────────────────────────
  var KINDS = [['headcount', 'Headcount'], ['movement', 'Starters and leavers'], ['retention', 'Retention'], ['tenure', 'Length of service'], ['liability', 'Leave liability'],
    ['overtime', 'Overtime'], ['lateness', 'Lateness'], ['schedule', 'Schedule vs worked'], ['leave', 'Leave this year vs last'], ['absence', 'Team absence'],
    ['missing', 'Missing information'], ['invites', 'Not signed in yet'], ['probation', 'Probation'], ['holdings', 'Everything we hold']];
  var RANGED = { movement: 365, overtime: 30, lateness: 30, schedule: 14, absence: 14 };
  var R = { kind: 'headcount', from: {}, to: {}, all: false, person: '' };

  /* A report = stats + tables; each table {title, cols:[[key, header, fmt]], rows, act}. fmt: day|num|money|pct|yn|html. */
  function cell(v, f, row) {
    if (f === 'day') return esc(day(v));
    if (f === 'num') return esc(num(v));
    if (f === 'money') return esc(money(v));
    if (f === 'pct') return v == null ? '' : esc(num(v) + '%');
    if (f === 'yn') return v ? 'Yes' : 'No';
    if (typeof f === 'function') return f(v, row);
    return '<span data-no-i18n>' + esc(v == null ? '' : v) + '</span>';
  }
  function plain(v, f, row) {
    if (f === 'day') return v || '';
    if (f === 'pct') return v == null ? '' : num(v) + '%';
    if (f === 'yn') return v ? 'Yes' : 'No';
    if (f && f.text) return f.text(v, row);
    if (typeof f === 'function') return String(v == null ? '' : v);
    return v == null ? '' : String(v);
  }
  function txt(fn, plainFn) { fn.text = plainFn; return fn; }

  function spec(k, d) {
    var can = d.can || {};
    if (k === 'headcount') {
      var tcols = [['label', 'Centre'], ['total', 'People', 'num']].concat(Object.keys(d.types || {}).map(function (t) { return [t, d.types[t], 'num']; })).concat([['not_set', 'Type not set', 'num']]);
      return { stats: [[d.total, 'People on staff']], tables: [{ title: 'By centre', cols: tcols, rows: d.by_centre }, { title: 'By employment type', cols: [['label', 'Employment type'], ['total', 'People', 'num']], rows: d.by_type }],
        note: 'Current staff (leavers left out). Each person counts once, at their main centre.' };
    }
    if (k === 'movement') return { stats: [[d.headcount_start, 'On staff ' + day(d.from)], [d.headcount_end, 'On staff ' + day(d.to)], [d.starters, 'Starters'], [d.leavers, 'Leavers'],
        [d.turnover_pct == null ? '—' : num(d.turnover_pct) + '%', 'Turnover'], [d.retention_pct == null ? '—' : num(d.retention_pct) + '%', 'Retention']],
      note: 'Turnover = leavers ÷ the average headcount (first and last day). Retention = the people here on the first day who are still here on the last. Leavers come from the leaving pack (last day worked), else the HR file end date, else the day the account was closed.',
      tables: [{ title: 'By month', cols: [['month', 'Month'], ['starters', 'Starters', 'num'], ['leavers', 'Leavers', 'num']], rows: d.months },
        { title: 'Starters', cols: [['name', 'Name'], ['centre', 'Centre'], ['job_title', 'Job title'], ['date', 'Started', 'day'], ['still_here', 'Still here', 'yn']], rows: d.starter_rows },
        { title: 'Leavers', cols: [['name', 'Name'], ['centre', 'Centre'], ['job_title', 'Job title'], ['started', 'Started', 'day'], ['date', 'Last day', 'day'], ['years', 'Years', 'num'], ['reason', 'Reason']], rows: d.leaver_rows }] };
    if (k === 'retention') return { tables: [{ title: 'By the year people started', cols: [['year', 'Started in'], ['started', 'Started', 'num'], ['still_here', 'Still here', 'num'], ['left', 'Left', 'num'],
        ['retained_pct', 'Still here', 'pct'], ['stayed_90_pct', 'Stayed 90 days', 'pct'], ['stayed_year_pct', 'Stayed a year', 'pct'], ['leavers_avg_months', 'Leavers stayed (months, average)', 'num']], rows: d.rows }],
      note: d.no_start_date ? d.no_start_date + ' people have no start date and are not counted.' : '' };
    if (k === 'tenure') return { stats: [[d.average_years == null ? '—' : num(d.average_years), 'Average years'], [d.median_years == null ? '—' : num(d.median_years), 'Median years']],
      tables: [{ title: 'Bands', cols: [['label', 'Length of service'], ['total', 'People', 'num']], rows: d.bands },
        { title: 'People', cols: [['name', 'Name'], ['centre', 'Centre'], ['job_title', 'Job title'], ['start_date', 'Started', 'day'], ['years', 'Years', 'num']], rows: d.rows }] };
    if (k === 'liability') return { stats: [[money(d.total), 'Owed if everyone left today'], [d.no_rate, 'Without a pay rate']],
      note: 'Leave balances that are paid out on leaving (HR > Leave types: by default vacation and the overtime bank) × each person\'s current pay rate (salary = annual ÷ 260 days ÷ hours a day). The same sum as the leaving pack.',
      tables: [{ title: 'By leave type', cols: [['label', 'Leave type'], ['people', 'People', 'num'], ['hours', 'Hours', 'num'], ['value', 'Value', 'money']], rows: d.by_type },
        { title: 'People', cols: [['name', 'Name'], ['centre', 'Centre'], ['balances', 'Balances'], ['hours', 'Hours', 'num'], ['hourly_rate', 'Hourly rate', 'money'], ['value', 'Value', 'money']], rows: d.rows }] };
    if (k === 'overtime') { var t = d.totals || {}; return { stats: [[num(t.approved_hours), 'Approved hours'], [num(t.paid_hours), 'To pay'], [num(t.banked_hours), 'Banked'], [t.pending, 'Waiting for a decision']],
      note: 'Overtime requests by the day worked (HR > Overtime). Banked hours earn time in lieu at the rate fixed when approved.',
      tables: [{ title: 'People', cols: [['name', 'Name'], ['centre', 'Centre'], ['requests', 'Requests', 'num'], ['pending', 'Waiting', 'num'], ['declined', 'Declined', 'num'], ['approved_hours', 'Approved hours', 'num'], ['paid_hours', 'To pay (hours)', 'num'], ['banked_hours', 'Banked (hours)', 'num'], ['bank_credit', 'Time in lieu earned', 'num']], rows: d.rows }] }; }
    if (k === 'lateness') return { note: 'Clock-ins compared with the scheduled shift start; late = more than ' + d.grace + ' minutes after it (the absence report\'s Lateness tab, summed).',
      tables: [{ title: 'By centre', cols: [['label', 'Centre'], ['compared', 'Clock-ins compared', 'num'], ['late', 'Late', 'num'], ['minutes', 'Minutes late', 'num'], ['people_late', 'People late', 'num'], ['on_time_pct', 'On time', 'pct']], rows: d.by_centre },
        { title: 'People', cols: [['name', 'Name'], ['centre', 'Centre'], ['compared', 'Compared', 'num'], ['late', 'Late', 'num'], ['minutes', 'Minutes', 'num'], ['average', 'Average', 'num'], ['worst', 'Most', 'num'], ['on_time_pct', 'On time', 'pct'], ['pattern', 'Pattern']], rows: d.rows }] };
    if (k === 'schedule') return { note: 'Scheduled shift hours against the hours clocked (paid hours follow your timesheet rules). The same figures as HR > Timesheets.',
      tables: [{ title: 'By centre', cols: [['label', 'Centre'], ['people', 'People', 'num'], ['shifts', 'Shifts', 'num'], ['scheduled_hours', 'Scheduled hours', 'num'], ['worked_hours', 'Worked hours', 'num'], ['paid_hours', 'Paid hours', 'num'], ['no_show', 'Missed shifts', 'num'], ['unscheduled', 'Unscheduled clock-ins', 'num'], ['overtime_hours', 'Approved overtime', 'num']], rows: d.by_centre },
        { title: 'People', cols: [['name', 'Name'], ['centre', 'Centre'], ['shifts', 'Shifts', 'num'], ['scheduled_hours', 'Scheduled', 'num'], ['worked_hours', 'Worked', 'num'], ['paid_hours', 'Paid', 'num'], ['variance_hours', 'Paid − scheduled', 'num'], ['no_show', 'Missed', 'num'], ['overtime_hours', 'Overtime', 'num']], rows: d.rows }] };
    if (k === 'leave') return { note: 'Each person\'s current leave year and the one before it (' + (d.leave_year === 'anniversary' ? 'from their start date' : 'your agency\'s leave year') + '). Earned = grants and accruals; Taken = time off booked; Other = carry-over, expiry and adjustments.',
      tables: [{ title: 'People', cols: [['name', 'Name'], ['centre', 'Centre'], ['type', 'Leave type'], ['unit', 'Unit'], ['prev_earned', 'Last year earned', 'num'], ['prev_taken', 'Last year taken', 'num'], ['prev_end', 'Balance at year end', 'num'], ['earned', 'This year earned', 'num'], ['taken', 'This year taken', 'num'], ['other', 'Other', 'num'], ['balance', 'Balance now', 'num']], rows: d.rows }] };
    if (k === 'absence') return { stats: [[(d.rows || []).length, 'Absences'], [d.busiest ? d.busiest.people : 0, d.busiest ? 'Most off on one day (' + day(d.busiest.date) + ')' : 'Most off on one day']],
      note: 'Approved and waiting time off between the two dates. Days count working days (weekends and centre closures skipped).',
      tables: [{ title: 'Who is off', cols: [['name', 'Name'], ['centre', 'Centre'], ['type', 'Leave type'], ['status', 'Status', function (v) { return v === 'pending' ? pill('#FFFBEB', '#B45309', 'Waiting') : pill('#ECFDF5', '#047857', 'Approved'); }], ['first', 'From', 'day'], ['last', 'To', 'day'], ['days', 'Days', 'num']], rows: d.rows },
        { title: 'People off each day', cols: [['date', 'Day', 'day'], ['people', 'People off', 'num']], rows: d.days }] };
    if (k === 'missing') {
      var mw = d.min_wages || { regions: [] };
      return { stats: [[d.complete + ' of ' + d.people, 'Complete']],
        note: 'Checks: start date, manager, emergency contact, job title and employment type' + (d.checks_pay ? '; payroll: pay rate, deposit details' + (d.checks_sin ? ', SIN / SSN' : '') + ' and pay below the minimum wage (hourly pay, or salary with hours a week on the file; contractors not checked)' : '') + '. Payroll items are highlighted.',
        tables: [{ title: 'People', cols: [['name', 'Name'], ['centre', 'Centre'], ['missing', 'Missing', txt(function (v) {
            return (v || []).length ? v.map(function (m) { return '<span class="hrr-miss">' + (m.payroll ? pill('#FEF2F2', '#B91C1C', m.label) : pill('#FFFBEB', '#B45309', m.label)) + '</span>'; }).join('') : pill('#ECFDF5', '#047857', 'Complete');
          }, function (v) { return (v || []).map(function (m) { return m.label; }).join('; ') || 'Complete'; })]].concat(d.checks_pay ? [['wage', 'Hourly pay / minimum', txt(function (v) { return v ? esc(money(v.hourly) + ' / ' + money(v.minimum) + ' (' + v.region + ')') : ''; }, function (v) { return v ? v.hourly + ' / ' + v.minimum + ' (' + v.region + ')' : ''; })]] : []),
          rows: d.rows, act: function (r) { return [['Open HR file', function () { goPerson(r.user_id); }]]; } },
          { title: 'Minimum wages used (checked ' + day(mw.checked) + ')', cols: [['name', 'Province / state'], ['rates', 'Rates', txt(function (v) { return esc((v || []).map(function (x) { return money(x.rate) + ' from ' + day(x.from); }).join(' · ')); }, function (v) { return (v || []).map(function (x) { return x.rate + ' from ' + x.from; }).join('; '); })], ['note', 'Note'], ['source', 'Source']], rows: mw.regions }] };
    }
    if (k === 'invites') return { stats: [[d.signed_in + ' of ' + d.people, 'Have signed in'], [d.expired, 'Invites expired']],
      note: 'Staff who have never signed in. An invite older than ' + d.invite_days + ' days is marked expired (change it in the Dashboard settings). ' + (can.admin ? 'Resend emails a new welcome with a link to set their own password (works once, for 7 days); any password they already have keeps working.' : 'An agency admin can resend the invite.'),
      tables: [{ title: 'Not signed in yet', cols: [['name', 'Name'], ['centre', 'Centre'], ['email', 'Email'], ['invited_on', 'Invited', 'day'], ['days', 'Days ago', 'num'],
          ['expired', 'Invite', txt(function (v, r) { return r.no_email ? pill('#F1F5F9', '#475569', 'No email address') : v ? pill('#FEF2F2', '#B91C1C', 'Expired') : pill('#E0F2FE', '#075985', 'Waiting'); }, function (v, r) { return r.no_email ? 'No email address' : v ? 'Expired' : 'Waiting'; })]], rows: d.rows,
        act: function (r) { return [can.admin && !r.no_email ? ['Resend invite', function () { resend(r); }] : null, KT.openUserRecord ? ['View user', function () { try { KT.openUserRecord(r.user_id); } catch (e) {} }] : null]; } }] };
    if (k === 'probation') return { note: 'Probation = start date + ' + ((d.settings || {}).probation_days) + ' days unless extended for one person. The manager gets a bell ' + ((d.settings || {}).remind_days) + ' days before it ends.',
      tables: [{ title: 'People', cols: [['name', 'Name'], ['centre', 'Centre'], ['start_date', 'Started', 'day'], ['end_on', 'Probation ends', 'day'], ['state', 'Status', txt(function (v) { return stPill(v); }, function (v) { return (STATE[v] || STATE.on)[2]; })], ['days_left', 'Days left', 'num'], ['manager', 'Manager']],
        rows: d.rows, act: function (r) { return probationMenu(r, redrawReports); } }] };
    return { tables: [] };
  }

  var HOST = null;
  function redrawReports() { if (HOST && HOST.isConnected) drawReports(HOST); }

  function query(k) {
    var q = [];
    if (RANGED[k]) {
      var t = today();
      R.to[k] = R.to[k] || t; R.from[k] = R.from[k] || addDays(R.to[k], -(RANGED[k] - 1));
      q.push('from=' + encodeURIComponent(R.from[k]), 'to=' + encodeURIComponent(R.to[k]));
    }
    if (k === 'probation' && R.all) q.push('all=1');
    return q.join('&');
  }

  async function drawReports(host) {
    css();
    HOST = host;
    var k = R.kind;
    var kinds = KINDS;
    var bar = '<div class="hrr-kinds" data-kt-nogrid>' + kinds.map(function (x) { return '<button type="button" class="hrr-kind' + (x[0] === k ? ' on' : '') + '"' + (x[0] === k ? ' aria-current="true"' : '') + ' data-kind="' + x[0] + '">' + esc(x[1]) + '</button>'; }).join('') + '</div>';
    if (k === 'holdings') return drawHoldings(host, bar);
    host.innerHTML = bar + '<div class="hr-sub" style="padding:12px 2px;">Loading…</div>';
    wireKinds(host);
    var d;
    try { d = await Api.get('/hr/reports/' + k + (query(k) ? '?' + query(k) : '')); } catch (e) { if (host.isConnected && R.kind === k) host.innerHTML = bar + '<div class="hr-note">' + esc(errMsg(e)) + '</div>'; wireKinds(host); return; }
    if (!host.isConnected || R.kind !== k) return;
    var sp = spec(k, d);
    var label = kinds.filter(function (x) { return x[0] === k; })[0][1];
    var h = bar + '<div class="hr-bar" data-kt-nogrid>';
    if (RANGED[k]) h += '<label class="hr-sub">From <input class="hr-i" type="date" data-rf value="' + esc(d.from) + '"></label><label class="hr-sub">To <input class="hr-i" type="date" data-rt value="' + esc(d.to) + '"></label>';
    if (k === 'probation') h += '<label class="hr-sub" style="display:inline-flex;gap:6px;align-items:center;cursor:pointer;"><input type="checkbox" data-rall' + (R.all ? ' checked' : '') + ' style="flex:0 0 auto;width:16px;height:16px;"> Include passed and older</label>';
    h += '<button type="button" class="hr-b p" data-print data-kt-iconized="1">Print</button></div>';   // CSV / Excel / PDF: the portal's bar under each table (kt-table-export.js)
    if (sp.stats && sp.stats.length) h += '<div class="hr-stats">' + sp.stats.map(function (s) { return '<div class="hr-stat"><b>' + esc(s[0]) + '</b><span>' + esc(s[1]) + '</span></div>'; }).join('') + '</div>';
    if (sp.note) h += '<div class="hr-sub" style="margin:0 0 8px;">' + esc(sp.note) + '</div>';
    sp.tables.forEach(function (t, ti) {
      h += (sp.tables.length > 1 ? '<div class="hrr-tt">' + esc(t.title) + '</div>' : '') + '<div class="kt-card">' + ((t.rows || []).length ? '<table><thead><tr>' + t.cols.map(function (c) { return '<th>' + esc(c[1]) + '</th>'; }).join('') + (t.act ? '<th></th>' : '') + '</tr></thead><tbody>'
        + t.rows.map(function (r, ri) {
          var acts = t.act ? t.act(r).filter(Boolean) : [];
          return '<tr>' + t.cols.map(function (c) { return '<td>' + cell(r[c[0]], c[2], r) + '</td>'; }).join('')
            + (t.act ? '<td style="text-align:center;white-space:nowrap;">' + acts.map(function (a, ai) { return '<button type="button" data-ra="' + ti + ':' + ri + ':' + ai + '">' + esc(a[0]) + '</button>'; }).join('') + '</td>' : '') + '</tr>';
        }).join('') + '</tbody></table>' : '<div class="hr-sub" style="padding:16px;">Nothing to show for this report.</div>') + '</div>';
    });
    host.innerHTML = h;
    wireKinds(host);
    var rf = host.querySelector('[data-rf]'), rt = host.querySelector('[data-rt]');
    if (rf) rf.addEventListener('change', function () { if (this.value) { R.from[k] = this.value; redrawReports(); } });
    if (rt) rt.addEventListener('change', function () { if (this.value) { R.to[k] = this.value; redrawReports(); } });
    var ra = host.querySelector('[data-rall]'); if (ra) ra.addEventListener('change', function () { R.all = this.checked; redrawReports(); });
    host.querySelectorAll('[data-ra]').forEach(function (b) {
      b.addEventListener('click', function () {
        var p = b.getAttribute('data-ra').split(':'), t = sp.tables[+p[0]], r = t.rows[+p[1]];
        var a = t.act(r).filter(Boolean)[+p[2]]; if (a) a[1]();
      });
    });
    host.querySelector('[data-print]').addEventListener('click', function () { printReport(sp, label, d); });
  }

  function wireKinds(host) {
    host.querySelectorAll('[data-kind]').forEach(function (b) { b.addEventListener('click', function () { R.kind = b.getAttribute('data-kind'); redrawReports(); }); });
  }

  function printReport(sp, label, d) {
    var body = '<h2 style="margin:0 0 4px;">' + esc(label) + '</h2><div style="color:#555;margin:0 0 12px;">' + esc(d.from ? day(d.from) + ' to ' + day(d.to) : day(d.today || today())) + '</div>';
    if (sp.stats && sp.stats.length) body += '<div style="margin:0 0 10px;">' + sp.stats.map(function (s) { return '<span style="display:inline-block;border:1px solid #ccc;border-radius:6px;padding:4px 10px;margin:0 6px 6px 0;"><b>' + esc(s[0]) + '</b> ' + esc(s[1]) + '</span>'; }).join('') + '</div>';
    if (sp.note) body += '<p style="color:#444;font-size:12px;">' + esc(sp.note) + '</p>';
    sp.tables.forEach(function (t) {
      body += (sp.tables.length > 1 ? '<h3 style="margin:14px 0 6px;font-size:14px;">' + esc(t.title) + '</h3>' : '')
        + '<table style="width:100%;border-collapse:collapse;font-size:11.5px;" border="1" cellpadding="4"><thead><tr>' + t.cols.map(function (c) { return '<th style="background:#f1f5f9;text-align:left;">' + esc(c[1]) + '</th>'; }).join('') + '</tr></thead><tbody>'
        + ((t.rows || []).length ? t.rows.map(function (r) { return '<tr>' + t.cols.map(function (c) { return '<td>' + esc(plain(r[c[0]], c[2], r)) + '</td>'; }).join('') + '</tr>'; }).join('') : '<tr><td colspan="' + t.cols.length + '">Nothing to show.</td></tr>') + '</tbody></table>';
    });
    var html = '<!doctype html><html><head><meta charset="utf-8"><title>' + esc(label) + '</title><style>body{font-family:Arial,sans-serif;font-size:13px;color:#111;margin:24px;}</style></head><body>' + body + '</body></html>';
    if (KT.printDoc) KT.printDoc({ html: html }, { title: label });
  }

  async function resend(r) {
    var box = el('<div>Send <b data-no-i18n>' + esc(r.name) + '</b> a new welcome email at <b data-no-i18n>' + esc(r.email) + '</b>? It carries a link to set their own password (works once, for 7 days). Any password they already have keeps working.</div>' + ERR);
    modal({ title: 'Resend invite', body: box, actions: [{ label: 'Cancel' }, { label: 'Resend', primary: true, busyLabel: 'Sending…', handler: function () {
      return Api.post('/admin/users/' + r.user_id + '/resend-welcome', {}).then(function (x) {
        toast(x && x.email_sent ? '✅' : '⚠️', x && x.email_sent ? 'Invite sent' : 'Not sent', (x && x.message) || '', x && x.email_sent ? '#16A34A' : '#B45309');
        redrawReports();
      }, function (e) { msg(box, errMsg(e)); return false; });
    } }] });
  }

  // ── everything we hold about one person ─────────────────────────────────
  async function drawHoldings(host, bar) {
    host.innerHTML = bar + '<div class="hr-sub" style="padding:12px 2px;">Loading…</div>';
    wireKinds(host);
    var p, l;
    try { p = await Api.get('/hr/people'); } catch (e) { host.innerHTML = bar + '<div class="hr-note">' + esc(errMsg(e)) + '</div>'; wireKinds(host); return; }
    try { l = await Api.get('/hr/leavers'); } catch (e) { l = { leavers: [] }; }
    if (!host.isConnected || R.kind !== 'holdings') return;
    var people = (p.people || []).map(function (x) { return { id: x.id, name: x.name }; }).concat((l.leavers || []).map(function (x) { return { id: x.id, name: x.name + ' (left)' }; }));
    host.innerHTML = bar + '<div class="hr-bar" data-kt-nogrid><select class="hr-i" data-hp><option value="">Choose a person…</option>' + people.map(function (x) { return '<option value="' + x.id + '"' + (String(x.id) === String(R.person) ? ' selected' : '') + '>' + esc(x.name) + '</option>'; }).join('') + '</select></div><div data-hb></div>';
    wireKinds(host);
    var sel = host.querySelector('[data-hp]'), out = host.querySelector('[data-hb]');
    var show = async function () {
      R.person = sel.value;
      if (!sel.value) { out.innerHTML = '<div class="hr-sub">Choose someone to see every kind of record KiddieTrac holds about them in your agency (for a privacy request, or before they leave).</div>'; return; }
      out.innerHTML = '<div class="hr-sub">Loading…</div>';
      var h;
      try { h = await Api.get('/hr/people/' + sel.value + '/holdings'); } catch (e) { out.innerHTML = '<div class="hr-note">' + esc(errMsg(e)) + '</div>'; return; }
      var sp = { tables: [{ title: 'What we hold', cols: [['label', 'Record'], ['count', 'How many', 'num']], rows: h.items }], note: h.note };
      out.innerHTML = '<div class="hr-sub" style="margin:0 0 8px;">' + esc(h.note) + '</div><div class="hr-bar" data-kt-nogrid><button type="button" class="hr-b" data-print data-kt-iconized="1">Print</button>'
        + (h.can_export ? '<button type="button" class="hr-b p" data-zip data-kt-iconized="1">Download all their data</button>' : '') + '</div>'
        + '<div class="kt-card"><table><thead><tr><th>Record</th><th>How many</th></tr></thead><tbody>' + h.items.map(function (i) { return '<tr><td>' + esc(i.label) + '</td><td>' + esc(i.count) + '</td></tr>'; }).join('') + '</tbody></table></div>';
      out.querySelector('[data-print]').addEventListener('click', function () { printReport(sp, 'Everything we hold: ' + h.name, { today: today() }); });
      var z = out.querySelector('[data-zip]');
      if (z) z.addEventListener('click', async function () {
        try {
          var b = await KT.fetchFileBlob((KT.API_BASE || '/api/v1') + '/hr/people/' + sel.value + '/export'), u = URL.createObjectURL(b);
          var a = document.createElement('a'); a.href = u; a.download = 'HR-data-' + h.name.replace(/[^A-Za-z0-9]+/g, '-') + '.zip'; a.setAttribute('data-kt-download', '1'); document.body.appendChild(a); a.click(); a.remove();
          setTimeout(function () { URL.revokeObjectURL(u); }, 30000);
        } catch (e) { fail('Could not download', e); }
      });
    };
    sel.addEventListener('change', show);
    show();
  }

  window.KTHrReports = {
    tabs: [['dashboard', 'Dashboard'], ['reports', 'Reports']],
    has: function (tab) { return tab === 'dashboard' || tab === 'reports'; },
    draw: function (tab, host) {
      var m = /[?&]report=([a-z]+)/.exec(location.hash || '');
      if (m && KINDS.some(function (k) { return k[0] === m[1]; })) R.kind = m[1];
      if (tab === 'dashboard') return drawDashboard(host);
      if (tab === 'reports') return drawReports(host);
    },
    drawDashboard: drawDashboard,
    drawReports: drawReports
  };
})(window);
