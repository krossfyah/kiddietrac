/* ═══════════════════════════════════════════════════════════════════════
   screen-hr-reports.js - KiddieTrac HR Phase 4, batch 5 (2026-11-27).

   Two self-contained HR tabs (each its own key + render function, so the coming "Human Resources" sidebar can lift
   them out as their own entries without a rewrite):
   - `dashboard` -> drawDashboard(host): animated insight tiles + charts (drawInsights, GET hr/dashboard/stats), then the HR setup checklist (leave types, rules, files complete, approvers, staff
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

  // ── Insights: animated tiles + charts (2026-11-27, Anthony: "HR Dashboard should have animated graphs, stats, charts") ──
  /* One call, GET /hr/dashboard/stats?period=&centre_id= (App\Support\HrDashboard): every figure is computed and scoped on
     the server. Plain SVG/HTML (no chart library in the portal). Colours are tokens on .hrd: the single-series accent is
     the white-label primary when there is one (--kt-brand-primary, set only for white-label) else KiddieTrac's #1F6080;
     multi-series charts take the validated categorical order (dataviz reference palette; light and dark steps). Marks
     draw in once when their card scrolls into view; prefers-reduced-motion shows them finished. Hover = the portal's one
     tooltip engine (data-kttip); every chart also has a Table view. Clicking a tile or a mark opens the matching HR
     screen with its dates or report chosen. */
  var D = { period: 'month', centre: '' };
  var PERIOD_LABEL = { month: 'This month', quarter: 'This quarter', year: 'This year' };
  var RM = false;
  var CW = 560;   // chart viewBox width: narrower on a phone so the axis text stays readable
  try { RM = !!(window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches); } catch (e) {}
  var SER = ['var(--hrd-s1)', 'var(--hrd-s2)', 'var(--hrd-s3)', 'var(--hrd-s4)', 'var(--hrd-s5)', 'var(--hrd-s6)', 'var(--hrd-s7)', 'var(--hrd-s8)'];
  function hrdCss() {
    if (document.getElementById('kt-hrd-css')) return;
    var s = document.createElement('style'); s.id = 'kt-hrd-css';
    s.textContent = '.hrd{--hrd-ink:#0D1B2A;--hrd-ink2:#475569;--hrd-mut:#64748B;--hrd-grid:#EEF2F6;--hrd-axis:#CBD5E1;--hrd-card:#fff;--hrd-edge:#E6EBF1;--hrd-chip:#F1F5F9;'
      + '--hrd-acc:var(--kt-brand-primary,#1F6080);--hrd-good:#047857;--hrd-bad:#B91C1C;--hrd-warn:#B45309;--hrd-okfill:#0CA30C;--hrd-warnfill:#FAB219;--hrd-badfill:#D03B3B;'
      + '--hrd-s1:#2A78D6;--hrd-s2:#EB6834;--hrd-s3:#1BAF7A;--hrd-s4:#EDA100;--hrd-s5:#E87BA4;--hrd-s6:#008300;--hrd-s7:#6250D6;--hrd-s8:#E34948;margin:0 0 14px;}'
      + 'html.kt-dark .hrd{--hrd-ink:#E2E8F0;--hrd-ink2:#CBD5E1;--hrd-mut:#94A3B8;--hrd-grid:#1E293B;--hrd-axis:#334155;--hrd-card:#0F172A;--hrd-edge:#334155;--hrd-chip:#1E293B;'
      + '--hrd-acc:var(--kt-brand-accent,#5EEAD4);--hrd-good:#34D399;--hrd-bad:#F87171;--hrd-warn:#FBBF24;'
      + '--hrd-s1:#3987E5;--hrd-s2:#D95926;--hrd-s3:#199E70;--hrd-s4:#C98500;--hrd-s5:#D55181;--hrd-s6:#0CA30C;--hrd-s7:#9085E9;--hrd-s8:#E66767;}'
      + '.hrd-bar{display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin:0 0 10px;}.hrd-bar .sp{flex:1;}.hrd-bar .upd{font-size:11.5px;color:var(--hrd-mut);}'
      + '.hrd-bar select{height:30px;border:1px solid #E2E8F0;border-radius:9px;padding:0 8px;font-size:12.5px;background:var(--hrd-card);color:var(--hrd-ink);max-width:220px;}'
      + 'html.kt-dark .hrd-bar select{border-color:#334155;}'
      + '.hrd-tiles{display:grid;grid-template-columns:repeat(auto-fill,minmax(min(100%,205px),1fr));gap:8px;margin:0 0 12px;}'
      + '.hrd-t{background:var(--hrd-card);border:1px solid var(--hrd-edge);border-radius:14px;padding:9px 12px 8px;min-width:0;display:flex;flex-direction:column;gap:3px;cursor:pointer;'
      + 'box-shadow:0 1px 2px rgba(15,23,42,.04);transition:box-shadow .15s,transform .15s;outline-offset:2px;}'
      + '.hrd-t:hover,.hrd-t:focus-visible{box-shadow:0 6px 18px rgba(15,23,42,.09);transform:translateY(-1px);}'
      + '.hrd-t .tp{display:flex;align-items:center;gap:6px;min-width:0;}.hrd-t .ic{flex:0 0 22px;height:22px;border-radius:50%;background:var(--hrd-chip);display:flex;align-items:center;justify-content:center;font-size:12px;}'
      + '.hrd-t .lb{font-size:11.5px;font-weight:700;color:var(--hrd-ink2);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}'
      + '.hrd-t .md{display:flex;align-items:flex-end;justify-content:space-between;gap:8px;min-width:0;}'
      + '.hrd-t .v{font-size:22px;font-weight:800;color:var(--hrd-ink);line-height:1.05;letter-spacing:-.5px;white-space:nowrap;}.hrd-t .v small{font-size:11.5px;font-weight:700;color:var(--hrd-mut);margin-left:2px;letter-spacing:0;}'
      + '.hrd-t .s{font-size:11px;color:var(--hrd-mut);line-height:1.3;min-height:14px;}'
      + '.hrd-d{display:inline-flex;align-items:center;gap:3px;font-size:11px;font-weight:800;white-space:nowrap;}.hrd-d.g{color:var(--hrd-good);}.hrd-d.b{color:var(--hrd-bad);}.hrd-d.n{color:var(--hrd-mut);}'
      + '.hrd-viz{flex:0 0 auto;line-height:0;}.hrd-viz svg{display:block;}'
      + '.hrd-charts{display:grid;grid-template-columns:repeat(auto-fit,minmax(min(100%,430px),1fr));gap:10px;margin:0 0 12px;}'
      + '.hrd-c{background:var(--hrd-card);border:1px solid var(--hrd-edge);border-radius:14px;padding:10px 12px;min-width:0;}'
      + '.hrd-c .hd{display:flex;align-items:center;gap:8px;margin:0 0 4px;}.hrd-c h4{margin:0;font-size:12px;letter-spacing:.05em;text-transform:uppercase;color:#0E7490;flex:1;min-width:0;}'
      + 'html.kt-dark .hrd-c h4{color:#5EEAD4;}'
      + '.hrd-c .sub{font-size:11.5px;color:var(--hrd-mut);margin:0 0 6px;}'
      + '.hrd-tbtn{height:24px;padding:0 9px;border-radius:999px;border:1px solid var(--hrd-edge);background:var(--hrd-card);color:var(--hrd-ink2);font-size:11px;font-weight:700;cursor:pointer;}'
      + '.hrd-leg{display:flex;flex-wrap:wrap;gap:4px 12px;font-size:11.5px;color:var(--hrd-ink2);margin:4px 0 0;}.hrd-leg i{display:inline-block;width:9px;height:9px;border-radius:2px;margin-right:5px;vertical-align:-1px;}'
      + '.hrd-svg{width:100%;height:auto;display:block;overflow:visible;}.hrd-svg text{font-family:inherit;}'
      + '.hrd-svg .gl{stroke:var(--hrd-grid);}.hrd-svg .ax{fill:var(--hrd-mut);font-size:10px;}.hrd-svg .base{stroke:var(--hrd-axis);}'
      + '.hrd-svg .hit{fill:transparent;cursor:pointer;}.hrd-svg .hit:hover,.hrd-svg .hit:focus{fill:var(--hrd-grid);fill-opacity:.55;outline:none;}'
      + '.hrd-svg .b{transform-box:fill-box;transform-origin:50% 100%;}'
      + '.hrd-svg .ln{fill:none;stroke:var(--hrd-acc);stroke-width:2;stroke-linejoin:round;stroke-linecap:round;}'
      + '.hrd-svg .ar{fill:var(--hrd-acc);fill-opacity:.12;}'
      + '.hrd-hb{display:grid;grid-template-columns:minmax(90px,38%) 1fr auto;gap:5px 8px;align-items:center;font-size:12px;}'
      + '.hrd-hb .n{color:var(--hrd-ink2);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}.hrd-hb .k{font-weight:800;color:var(--hrd-ink);font-variant-numeric:tabular-nums;}'
      + '.hrd-hb .tr{height:12px;border-radius:0 4px 4px 0;background:transparent;}.hrd-hb .tr i{display:block;height:100%;border-radius:0 4px 4px 0;background:var(--hrd-acc);transform-origin:0 50%;}'
      + '.hrd-hb .row{display:contents;cursor:pointer;}.hrd-hb .row:hover .n{color:var(--hrd-ink);text-decoration:underline;}'
      + '.hrd-meter{margin:0 0 9px;cursor:pointer;}.hrd-meter .t{display:flex;justify-content:space-between;gap:8px;font-size:12px;color:var(--hrd-ink2);margin:0 0 3px;}.hrd-meter .t b{color:var(--hrd-ink);}'
      + '.hrd-meter .tr{height:8px;border-radius:999px;background:var(--hrd-chip);overflow:hidden;}.hrd-meter .tr i{display:block;height:100%;border-radius:999px;background:var(--hrd-acc);transform-origin:0 50%;}'
      + '.hrd-seg{display:flex;gap:2px;height:28px;margin:4px 0 8px;}.hrd-seg span{display:flex;align-items:center;padding:0 7px;border-radius:4px;font-size:11.5px;font-weight:800;color:#fff;min-width:0;overflow:hidden;white-space:nowrap;cursor:pointer;transform-origin:0 50%;}'
      + '.hrd-seg span.dk{color:#0B0B0B;}'
      + '.hrd-donuts{display:flex;gap:14px;flex-wrap:wrap;}.hrd-dn{flex:1;min-width:170px;display:flex;gap:10px;align-items:center;}'
      + '.hrd-dn .dl{flex:1;min-width:0;font-size:12px;}.hrd-dn .dl div{display:flex;align-items:center;gap:6px;margin:0 0 4px;cursor:pointer;}.hrd-dn .dl span{flex:1;color:var(--hrd-ink2);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}.hrd-dn .dl b{color:var(--hrd-ink);}'
      + '.hrd-dn circle.sg{transition:stroke-dasharray .9s cubic-bezier(.2,.7,.2,1);cursor:pointer;}'
      + '.hrd-strip{display:grid;grid-template-columns:repeat(14,minmax(0,1fr));gap:3px;align-items:end;}'
      + '.hrd-day{display:flex;flex-direction:column;align-items:center;gap:2px;cursor:pointer;border-radius:8px;padding:3px 0;}.hrd-day:hover{background:var(--hrd-chip);}'
      + '.hrd-day .col{width:62%;max-width:22px;height:64px;display:flex;flex-direction:column;justify-content:flex-end;gap:2px;}'
      + '.hrd-day .col i{display:block;border-radius:4px 4px 0 0;background:var(--hrd-acc);transform-origin:50% 100%;}.hrd-day .col i.p,.hrd-leg i.p{background:repeating-linear-gradient(135deg,var(--hrd-acc) 0 2px,transparent 2px 5px);box-shadow:inset 0 0 0 1.5px var(--hrd-acc);border-radius:4px 4px 0 0;}.hrd-day .col i.p+i{border-radius:0;}'
      + '.hrd-day .n{font-size:11px;font-weight:800;color:var(--hrd-ink);min-height:13px;}.hrd-day .w{font-size:10px;color:var(--hrd-mut);}.hrd-day.we .w{opacity:.6;}.hrd-day.td .w{color:var(--hrd-ink);font-weight:800;}'
      + '.hrd-list{font-size:12px;margin:4px 0 0;}.hrd-list div{display:flex;gap:8px;padding:4px 0;border-top:1px dashed var(--hrd-grid);}.hrd-list span{flex:1;min-width:0;color:var(--hrd-ink2);}'
      + '.hrd-tbl{width:100%;border-collapse:collapse;font-size:12px;}.hrd-tbl th,.hrd-tbl td{padding:4px 6px;border-bottom:1px solid var(--hrd-grid);text-align:right;color:var(--hrd-ink2);}.hrd-tbl th:first-child,.hrd-tbl td:first-child{text-align:left;}'
      + '.hrd-empty{height:120px;display:flex;align-items:center;justify-content:center;color:var(--hrd-mut);font-size:12.5px;}'
      + '.hrd-skel{height:86px;border-radius:14px;background:linear-gradient(90deg,var(--hrd-chip),var(--hrd-card),var(--hrd-chip));background-size:200% 100%;animation:hrdsk 1.2s linear infinite;}'
      + '@keyframes hrdsk{to{background-position:-200% 0;}}'
      // Draw-in: marks start collapsed and ease to their place when their card gets .hrd-in.
      + '.hrd-anim .b,.hrd-anim .hrd-day .col i{transform:scaleY(0);transition:transform .75s cubic-bezier(.2,.7,.2,1);}'
      + '.hrd-anim .hrd-hb .tr i,.hrd-anim .hrd-meter .tr i,.hrd-anim .hrd-seg span{transform:scaleX(0);transition:transform .8s cubic-bezier(.2,.7,.2,1);}'
      + '.hrd-anim .ln{stroke-dasharray:1;stroke-dashoffset:1;transition:stroke-dashoffset 1.1s cubic-bezier(.3,.6,.2,1);}'
      + '.hrd-anim .ar,.hrd-anim .dot{opacity:0;transition:opacity .6s ease .5s;}'
      + '.hrd-in .b,.hrd-in .hrd-day .col i,.hrd-in .hrd-hb .tr i,.hrd-in .hrd-meter .tr i,.hrd-in .hrd-seg span{transform:none;}'
      + '.hrd-in .ln{stroke-dashoffset:0;}.hrd-in .ar,.hrd-in .dot{opacity:1;}'
      + '@media (prefers-reduced-motion: reduce){.hrd *{transition:none !important;animation:none !important;}}'
      + '@media (max-width:640px){.hrd-tiles{grid-template-columns:repeat(2,minmax(0,1fr));gap:6px;}.hrd-t{padding:8px 9px;}.hrd-t .v{font-size:19px;}.hrd-viz svg{width:64px;}.hrd-hb{grid-template-columns:minmax(70px,34%) 1fr auto;}}';
    document.head.appendChild(s);
  }

  function fmtN(v, dp) { if (v == null || !isFinite(v)) return '—'; var n = Number(v); var s = n.toFixed(dp || 0); return s.replace(/\B(?=(\d{3})+(?!\d))/g, ','); }
  function fmtMoney0(v) { return v == null ? '—' : '$' + fmtN(Math.round(Number(v) || 0)); }
  function niceMax(m) { if (!(m > 0)) return 1; var st = Math.pow(10, Math.floor(Math.log10(m))), n = Math.ceil(m / st) * st; if (n / st <= 2) n = Math.ceil(m / (st / 4)) * (st / 4); else if (n / st <= 5) n = Math.ceil(m / (st / 2)) * (st / 2); return n; }
  function axisN(v) { return v >= 1000 ? (Math.round(v / 100) / 10) + 'k' : String(Math.round(v * 10) / 10); }
  function plur(n, one, many) { return fmtN(n) + ' ' + (Number(n) === 1 ? one : (many || one + 's')); }
  function dDay(s) { var m = /^(\d{4})-(\d{2})-(\d{2})/.exec(s || ''); return m ? MON[+m[2] - 1] + ' ' + (+m[3]) : ''; }
  function lastDay(ym) { var p = ym.split('-'); var d = new Date(+p[0], +p[1], 0); return ym + '-' + String(d.getDate()).padStart(2, '0'); }

  /* Change against the same point of the previous period. kind: 'count' (± n) | 'pct' (± points) | 'hours'. good: 'up' | 'down' | null. */
  function delta(cur, prev, kind, good, label) {
    if (cur == null || prev == null) return '';
    var diff = Number(cur) - Number(prev);
    var r = Math.round(diff * 10) / 10;
    if (!r) return '<span class="hrd-d n" data-kttip="' + esc('Same as ' + label) + '">= ' + esc(label) + '</span>';
    var up = r > 0, cls = good ? ((good === 'up') === up ? 'g' : 'b') : 'n';
    var txtv = (up ? '+' : '−') + (kind === 'pct' ? fmtN(Math.abs(r), 1) + ' pts' : kind === 'hours' ? fmtN(Math.abs(r), 1) + ' h' : fmtN(Math.abs(r)));
    return '<span class="hrd-d ' + cls + '" data-kttip="' + esc((up ? 'Up ' : 'Down ') + txtv.slice(1) + ' on ' + label + ' (' + fmtN(prev, kind === 'count' ? 0 : 1) + ')') + '">' + (up ? '▲ ' : '▼ ') + esc(txtv) + '</span>';
  }

  function spark(vals, tip) {
    var W = 96, H = 30, n = (vals || []).length, max = Math.max.apply(null, (vals || []).concat([0]));
    if (n < 2 || !max) return '<svg class="hrd-svg" width="' + W + '" height="' + H + '" viewBox="0 0 ' + W + ' ' + H + '" aria-hidden="true"><line x1="2" x2="' + (W - 2) + '" y1="' + (H - 3) + '" y2="' + (H - 3) + '" stroke="var(--hrd-axis)" stroke-width="2" stroke-linecap="round" stroke-dasharray="3 4"/></svg>';
    var pts = vals.map(function (v, i) { return [2 + (W - 8) * i / (n - 1), (H - 4) - (H - 9) * (Number(v) || 0) / max]; });
    var line = pts.map(function (p, i) { return (i ? 'L' : 'M') + p[0].toFixed(1) + ' ' + p[1].toFixed(1); }).join(' '), l = pts[n - 1];
    return '<svg class="hrd-svg" width="' + W + '" height="' + H + '" viewBox="0 0 ' + W + ' ' + H + '" role="img" aria-label="' + esc(tip) + '" data-kttip="' + esc(tip) + '">'
      + '<path class="ar" d="' + line + ' L' + l[0].toFixed(1) + ' ' + (H - 1) + ' L2 ' + (H - 1) + ' Z"/>'
      + '<path class="ln" pathLength="1" d="' + line + '" style="stroke-opacity:.75"/>'
      + '<circle class="dot" cx="' + l[0].toFixed(1) + '" cy="' + l[1].toFixed(1) + '" r="3.5" fill="var(--hrd-acc)" stroke="var(--hrd-card)" stroke-width="2"/></svg>';
  }
  function ring(share, tip, text) {
    var has = share != null && isFinite(share), p = has ? Math.max(0, Math.min(1, share)) : 0;
    return '<svg class="hrd-svg" width="44" height="44" viewBox="0 0 44 44" role="img" aria-label="' + esc(tip) + '" data-kttip="' + esc(tip) + '">'
      + '<circle cx="22" cy="22" r="17" fill="none" stroke="var(--hrd-chip)" stroke-width="5"/>'
      + (has && p > 0 ? '<circle class="sg" cx="22" cy="22" r="17" fill="none" stroke="var(--hrd-acc)" stroke-width="5" stroke-linecap="round" pathLength="100" stroke-dasharray="' + (RM ? (p * 100).toFixed(1) : '0') + ' 100" data-to="' + (p * 100).toFixed(1) + '" transform="rotate(-90 22 22)" style="transition:stroke-dasharray .9s cubic-bezier(.2,.7,.2,1)"/>' : '')
      + '<text x="22" y="26" text-anchor="middle" font-size="10.5" font-weight="800" fill="var(--hrd-ink)">' + (text || (has ? Math.round(p * 100) + '%' : '—')) + '</text></svg>';
  }
  /* A tile: {id, icon, label, value, dp, unit, prefix, sub, delta, viz, go, tip}. The value counts up when it comes into view. */
  function tileHtml(t, i) {
    return '<div class="hrd-t" role="button" tabindex="0" data-tile="' + i + '" aria-label="' + esc(t.label + ': ' + (t.prefix || '') + fmtN(t.value, t.dp) + (t.unit ? ' ' + t.unit : '') + (t.sub && t.subText ? '. ' + t.subText : '')) + '">'
      + '<div class="tp"><span class="ic" aria-hidden="true">' + t.icon + '</span><span class="lb">' + esc(t.label) + '</span></div>'
      + '<div class="md"><div class="v"><span data-count="' + (t.value == null ? '' : t.value) + '" data-dp="' + (t.dp || 0) + '" data-prefix="' + esc(t.prefix || '') + '">' + (t.value == null ? '—' : esc((t.prefix || '') + fmtN(t.value, t.dp))) + '</span>' + (t.unit ? '<small>' + esc(t.unit) + '</small>' : '') + '</div>'
      + (t.viz ? '<div class="hrd-viz">' + t.viz + '</div>' : '') + '</div>'
      + '<div class="s">' + (t.delta || '') + (t.delta && t.sub ? ' · ' : '') + (t.sub || '') + '</div></div>';
  }
  function countUp(elm) {
    var to = elm.getAttribute('data-count'); if (to === '' || to == null) return;
    var v = Number(to), dp = +elm.getAttribute('data-dp') || 0, pre = elm.getAttribute('data-prefix') || '';
    if (RM || !v || !window.requestAnimationFrame) { elm.textContent = pre + fmtN(v, dp); return; }
    var t0 = null, dur = 750;
    function step(ts) { if (t0 == null) t0 = ts; var k = Math.min(1, (ts - t0) / dur), e = 1 - Math.pow(1 - k, 3); elm.textContent = pre + fmtN(v * e, k < 1 ? (dp ? dp : 0) : dp); if (k < 1) requestAnimationFrame(step); }
    elm.textContent = pre + fmtN(0, dp); requestAnimationFrame(step);
  }
  function reveal(node) {
    node.classList.add('hrd-in');
    node.querySelectorAll('[data-count]').forEach(countUp);
    node.querySelectorAll('circle.sg[data-to]').forEach(function (c) { c.setAttribute('stroke-dasharray', c.getAttribute('data-to') + ' 100'); });
  }
  function watch(root) {
    var nodes = [].slice.call(root.querySelectorAll('.hrd-tiles,.hrd-c'));
    if (RM || !('IntersectionObserver' in window)) { nodes.forEach(reveal); return; }
    var io = new IntersectionObserver(function (es) { es.forEach(function (e) { if (e.isIntersecting) { io.unobserve(e.target); reveal(e.target); } }); }, { threshold: 0.18 });
    nodes.forEach(function (n) { io.observe(n); });
  }

  /* Vertical bars by month. series [{key,label,color}], stacked or grouped. Each month = one hit column (tooltip + click). */
  function monthBars(rows, series, opt) {
    opt = opt || {};
    var W = CW, H = CW < 500 ? 200 : 170, L = 30, T = 8, B = 20, n = Math.max(1, rows.length), max = 0;
    rows.forEach(function (r) { var s = 0; series.forEach(function (x) { var v = Number(r[x.key] || 0); s += v; if (!opt.stack) max = Math.max(max, v); }); if (opt.stack) max = Math.max(max, s); });
    if (!max) return '<div class="hrd-empty">' + esc(opt.empty || 'Nothing in the last 12 months.') + '</div>';
    var top = niceMax(max);
    if (opt.dp === 0) top = Math.max(3, Math.ceil(top / 3) * 3);   // whole people: whole-number gridlines
    var ph = H - T - B, cw = (W - L) / n, bw = opt.stack ? Math.min(26, cw * 0.58) : Math.min(14, (cw * 0.7) / series.length);
    var y = function (v) { return T + ph - ph * v / top; };
    var s = '<svg class="hrd-svg" viewBox="0 0 ' + W + ' ' + H + '" role="img" aria-label="' + esc(opt.label || '') + '">';
    for (var g = 0; g <= 3; g++) { var gv = top * g / 3, gy = y(gv); s += '<line class="' + (g ? 'gl' : 'base') + '" x1="' + L + '" x2="' + W + '" y1="' + gy.toFixed(1) + '" y2="' + gy.toFixed(1) + '"/><text class="ax" x="' + (L - 5) + '" y="' + (gy + 3.5).toFixed(1) + '" text-anchor="end">' + esc(axisN(gv)) + '</text>'; }
    rows.forEach(function (r, i) {
      var cx = L + cw * i + cw / 2, tipParts = [], acc = 0, k = 0;
      series.forEach(function (x, j) {
        var v = Number(r[x.key] || 0); tipParts.push(x.label + ' ' + fmtN(v, opt.dp == null ? 1 : opt.dp).replace(/\.0$/, ''));
        if (!v) return;
        var x0, y0, h;
        if (opt.stack) { y0 = y(acc + v); h = y(acc) - y0 - (acc ? 2 : 0); x0 = cx - bw / 2; acc += v; }
        else { x0 = cx - (bw * series.length + 2 * (series.length - 1)) / 2 + j * (bw + 2); y0 = y(v); h = y(0) - y0; }
        if (h <= 0) return;
        var rr = Math.min(4, h, bw / 2);
        s += '<path class="b" style="transition-delay:' + (RM ? 0 : (i * 30 + j * 40)) + 'ms" fill="' + x.color + '" d="M' + x0.toFixed(1) + ' ' + (y0 + h).toFixed(1) + 'V' + (y0 + rr).toFixed(1) + 'Q' + x0.toFixed(1) + ' ' + y0.toFixed(1) + ' ' + (x0 + rr).toFixed(1) + ' ' + y0.toFixed(1)
          + 'H' + (x0 + bw - rr).toFixed(1) + 'Q' + (x0 + bw).toFixed(1) + ' ' + y0.toFixed(1) + ' ' + (x0 + bw).toFixed(1) + ' ' + (y0 + rr).toFixed(1) + 'V' + (y0 + h).toFixed(1) + 'Z"/>';
        k++;
      });
      var tip = (r.label || '') + (r.year ? ' ' + r.year : '') + ' · ' + tipParts.join(' · ') + (opt.extra ? opt.extra(r) : '');
      s += '<rect class="hit" x="' + (L + cw * i).toFixed(1) + '" y="' + T + '" width="' + cw.toFixed(1) + '" height="' + ph + '" rx="6" data-i="' + i + '" tabindex="0" role="button" aria-label="' + esc(tip) + '" data-kttip="' + esc(tip) + '"/>';
      s += '<text class="ax" x="' + cx.toFixed(1) + '" y="' + (H - 5) + '" text-anchor="middle">' + esc(r.label || '') + '</text>';
    });
    return s + '</svg>';
  }
  function areaChart(rows, opt) {
    var W = CW, H = CW < 500 ? 200 : 170, L = 30, T = 10, B = 20, n = rows.length, max = Math.max.apply(null, rows.map(function (r) { return Number(r.value) || 0; }).concat([0]));
    if (!max) return '<div class="hrd-empty">Nobody on staff yet.</div>';
    var top = niceMax(max * 1.08), ph = H - T - B, cw = (W - L) / n, y = function (v) { return T + ph - ph * v / top; };
    var pts = rows.map(function (r, i) { return [L + cw * i + cw / 2, y(Number(r.value) || 0)]; });
    var line = pts.map(function (p, i) { return (i ? 'L' : 'M') + p[0].toFixed(1) + ' ' + p[1].toFixed(1); }).join(' ');
    var s = '<svg class="hrd-svg" viewBox="0 0 ' + W + ' ' + H + '" role="img" aria-label="' + esc(opt.label) + '">';
    for (var g = 0; g <= 3; g++) { var gv = top * g / 3, gy = y(gv); s += '<line class="' + (g ? 'gl' : 'base') + '" x1="' + L + '" x2="' + W + '" y1="' + gy.toFixed(1) + '" y2="' + gy.toFixed(1) + '"/><text class="ax" x="' + (L - 5) + '" y="' + (gy + 3.5).toFixed(1) + '" text-anchor="end">' + esc(axisN(gv)) + '</text>'; }
    s += '<path class="ar" d="' + line + ' L' + pts[n - 1][0].toFixed(1) + ' ' + y(0).toFixed(1) + ' L' + pts[0][0].toFixed(1) + ' ' + y(0).toFixed(1) + ' Z"/>';
    s += '<path class="ln" pathLength="1" d="' + line + '"/>';
    var l = pts[n - 1];
    s += '<circle class="dot" cx="' + l[0].toFixed(1) + '" cy="' + l[1].toFixed(1) + '" r="4.5" fill="var(--hrd-acc)" stroke="var(--hrd-card)" stroke-width="2"/>';
    s += '<text class="dot" x="' + (l[0] - 8).toFixed(1) + '" y="' + (l[1] - 9).toFixed(1) + '" text-anchor="end" font-size="11" font-weight="800" fill="var(--hrd-ink)">' + esc(fmtN(rows[n - 1].value)) + '</text>';
    rows.forEach(function (r, i) {
      var tip = r.label + ' ' + (r.year || '') + ' · ' + plur(r.value, 'person', 'people') + (i === n - 1 ? ' (today)' : ' (end of month)');
      s += '<rect class="hit" x="' + (L + cw * i).toFixed(1) + '" y="' + T + '" width="' + cw.toFixed(1) + '" height="' + ph + '" rx="6" data-i="' + i + '" tabindex="0" role="button" aria-label="' + esc(tip) + '" data-kttip="' + esc(tip) + '"/>';
      s += '<text class="ax" x="' + (L + cw * i + cw / 2).toFixed(1) + '" y="' + (H - 5) + '" text-anchor="middle">' + esc(r.label) + '</text>';
    });
    return s + '</svg>';
  }
  function legendHtml(series) { return '<div class="hrd-leg">' + series.map(function (x) { return '<span><i' + (x.cls ? ' class="' + x.cls + '"' : ' style="background:' + x.color + '"') + '></i>' + esc(x.label) + '</span>'; }).join('') + '</div>'; }
  function hbarsHtml(rows, unit) {
    var max = Math.max.apply(null, rows.map(function (r) { return r.value; }).concat([1]));
    return '<div class="hrd-hb">' + rows.map(function (r, i) {
      return '<div class="row" data-i="' + i + '" role="button" tabindex="0" data-kttip="' + esc(r.tip || (r.label + ': ' + plur(r.value, unit || 'person', unit ? unit + 's' : 'people'))) + '"><span class="n" data-no-i18n>' + esc(r.label) + '</span>'
        + '<span class="tr"><i style="width:' + Math.max(r.value ? 2 : 0, 100 * r.value / max).toFixed(1) + '%;transition-delay:' + (RM ? 0 : i * 45) + 'ms"></i></span><span class="k">' + esc(fmtN(r.value)) + '</span></div>';
    }).join('') + '</div>';
  }
  function donutHtml(parts, centre, sub, key) {
    var tot = parts.reduce(function (a, p) { return a + p.value; }, 0), off = 0;
    var s = '<svg class="hrd-svg" width="112" height="112" viewBox="0 0 112 112" style="width:112px;flex:0 0 auto" role="img" aria-label="' + esc(sub + ': ' + parts.map(function (p) { return p.label + ' ' + p.value; }).join(', ')) + '">'
      + '<circle cx="56" cy="56" r="42" fill="none" stroke="var(--hrd-chip)" stroke-width="14"/>';
    parts.forEach(function (p, i) {
      var len = tot ? 100 * p.value / tot : 0; if (!len) return;
      var gap = parts.filter(function (x) { return x.value; }).length > 1 ? 0.8 : 0;
      s += '<circle class="sg" cx="56" cy="56" r="42" fill="none" stroke="' + p.color + '" stroke-width="14" pathLength="100" data-to="' + Math.max(0, len - gap).toFixed(2) + '" stroke-dasharray="' + (RM ? Math.max(0, len - gap).toFixed(2) : '0') + ' 100" stroke-dashoffset="' + (-off).toFixed(2) + '" transform="rotate(-90 56 56)" data-dk="' + key + '" data-i="' + i + '" data-kttip="' + esc(p.label + ': ' + p.value + ' (' + Math.round(len) + '%)') + '"/>';
      off += len;
    });
    s += '<text x="56" y="58" text-anchor="middle" font-size="20" font-weight="800" fill="var(--hrd-ink)">' + esc(centre) + '</text><text x="56" y="73" text-anchor="middle" font-size="10" fill="var(--hrd-mut)">' + esc(sub) + '</text></svg>';
    var leg = '<div class="dl">' + parts.map(function (p, i) { return p.value ? '<div data-dk="' + key + '" data-i="' + i + '" role="button" tabindex="0"><i style="width:9px;height:9px;border-radius:2px;flex:0 0 auto;background:' + p.color + '"></i><span>' + esc(p.label) + '</span><b>' + esc(fmtN(p.value)) + '</b></div>' : ''; }).join('') + '</div>';
    return '<div class="hrd-dn">' + s + leg + '</div>';
  }
  function tableHtml(head, rows) {
    return '<table class="hrd-tbl"><thead><tr>' + head.map(function (h) { return '<th>' + esc(h) + '</th>'; }).join('') + '</tr></thead><tbody>'
      + rows.map(function (r) { return '<tr>' + r.map(function (c, i) { return '<td' + (i ? '' : ' data-no-i18n') + '>' + esc(c == null ? '' : c) + '</td>'; }).join('') + '</tr>'; }).join('') + '</tbody></table>';
  }

  // Where a tile or mark goes. Reports get their dates set first (R is this file's Reports state).
  function goReport(kind, from, to) { if (from && to) { R.from[kind] = from; R.to[kind] = to; } R.kind = kind; location.hash = '#hr-reports?report=' + kind; }
  function goHash(h) { location.hash = h; }

  async function drawInsights(box) {
    hrdCss();
    box.className = 'hrd' + (RM ? '' : ' hrd-anim');
    if (!box.firstChild) box.innerHTML = '<div class="hrd-tiles">' + new Array(9).join('<div class="hrd-skel"></div>') + '</div>';
    var q = 'period=' + D.period + (D.centre ? '&centre_id=' + encodeURIComponent(D.centre) : ''), d;
    try { d = await Api.get('/hr/dashboard/stats?' + q); } catch (e) {
      if (e && e.status === 404 && D.centre) { D.centre = ''; return drawInsights(box); }
      box.innerHTML = '<div class="hr-note">' + esc(errMsg(e)) + '</div>'; return;
    }
    if (!box.isConnected) return;
    CW = (box.clientWidth || 900) < 640 ? 380 : 560;
    var P = d.period || {}, pl = P.prev_label || 'last period', T = d.tiles || {}, C = d.charts || {}, can = d.can || {};
    var mo = d.months || [];
    var mName = function (i) { var m = mo[i]; return m ? m.label + ' ' + m.year : ''; };
    var per = PERIOD_LABEL[D.period].toLowerCase();

    // ── tiles ──
    var tiles = [];
    var hc = T.headcount || {};
    tiles.push({ icon: '👥', label: 'Headcount', value: hc.value, delta: delta(hc.value, hc.prev, 'count', null, pl),
      sub: esc('FTE ' + fmtN(hc.fte, 1)) + (hc.fte_missing ? ' <span data-kttip="' + esc(plur(hc.fte_missing, 'person has', 'people have') + ' no weekly hours on their HR file, so they are not in the FTE') + '">(' + hc.fte_missing + ' no hours)</span>' : ''),
      subText: 'FTE ' + fmtN(hc.fte, 1), viz: spark(hc.series, 'Headcount at the end of each month, last 12 months'), go: function () { goReport('headcount'); } });
    var st = T.starters || {}, lv = T.leavers || {}, to = T.turnover || {};
    tiles.push({ icon: '🌱', label: 'Starters ' + per, value: st.value, delta: delta(st.value, st.prev, 'count', 'up', pl), viz: spark(st.series, 'Starters each month, last 12 months'), go: function () { goReport('movement', P.from, P.to); } });
    tiles.push({ icon: '👋', label: 'Leavers ' + per, value: lv.value, delta: delta(lv.value, lv.prev, 'count', 'down', pl), viz: spark(lv.series, 'Leavers each month, last 12 months'), go: function () { goReport('movement', P.from, P.to); } });
    tiles.push({ icon: '🔄', label: 'Turnover ' + per, value: to.value, dp: 1, unit: '%', delta: delta(to.value, to.prev, 'pct', 'down', pl), viz: spark(to.series, 'Turnover each month (leavers ÷ average headcount), last 12 months'), go: function () { goReport('movement', P.from, P.to); } });
    var off = T.off || {};
    tiles.push({ icon: '🌴', label: 'Off today', value: off.today, sub: esc(fmtN(off.week) + ' off this week'), subText: fmtN(off.week) + ' off this week', viz: spark(off.series, 'People off each day, next 14 days (approved and waiting)'),
      go: function () { goReport('absence', d.today, addDays(d.today, 13)); } });
    var ab = T.absence || {};
    tiles.push({ icon: '🤒', label: 'Absence rate', value: ab.value, dp: 1, unit: '%', delta: delta(ab.value, ab.prev, 'pct', 'down', pl), sub: esc(fmtN(ab.days, 1) + ' days ' + per), subText: fmtN(ab.days, 1) + ' days',
      viz: spark(ab.series, 'Absence rate each month (days of approved leave ÷ people × weekdays), last 12 months'), go: function () { goReport('absence', P.from, P.to); } });
    var la = T.lateness || {};
    tiles.push({ icon: '⏰', label: 'Late clock-ins ' + per, value: la.period == null ? 0 : la.period, delta: delta(la.period, la.prev, 'count', 'down', pl),
      sub: (la.on_time == null ? (la.unchecked ? '' : 'no clock-ins compared yet') : esc(la.on_time + '% on time')) + (la.unchecked ? (la.on_time == null ? '' : ' · ') + '<span data-kttip="' + esc('Older or corrected clock-ins are being compared with their shifts now; open the dashboard again in a minute for the full count.') + '">' + esc(plur(la.unchecked, 'clock-in') + ' still being checked') + '</span>' : ''),
      subText: la.on_time == null ? '' : la.on_time + '% on time',
      viz: spark(la.series, 'Late clock-ins each month (more than ' + (la.grace || 0) + ' minutes after the shift start), last 12 months'), go: function () { goReport('lateness', P.from, P.to); } });
    var pe = T.pending || {};
    var PEND = [['time_off', 'time off', '#time-off'], ['punch_fixes', 'clock-in fix', '#timesheets?tab=punchfix'], ['timesheets', 'timesheet', '#timesheets?tab=lock'],
      ['overtime', 'overtime', '#timesheets?tab=overtime'], ['claims', 'claim', '#payroll?tab=claims'], ['shift_claims', 'shift pick-up', '#schedule?tab=openshifts']];
    var pendOn = PEND.filter(function (p) { return pe[p[0]]; });
    tiles.push({ icon: '📥', label: 'Waiting for a decision', value: pe.total || 0,
      sub: pendOn.length ? esc(pendOn.map(function (p) { return pe[p[0]] + ' ' + p[1]; }).join(' · ')) : 'all caught up', subText: pendOn.map(function (p) { return pe[p[0]] + ' ' + p[1]; }).join(', '),
      go: function (anchor) {
        if (pendOn.length === 1) return goHash(pendOn[0][2]);
        var items = (pendOn.length ? pendOn : PEND.slice(0, 1)).map(function (p) { return [(p[0] === 'timesheets' ? 'Timesheets to approve' : p[0] === 'time_off' ? 'Time off requests' : p[0] === 'punch_fixes' ? 'Clock-in fixes' : p[0] === 'overtime' ? 'Overtime requests' : p[0] === 'claims' ? 'Expense claims' : 'Shift pick-ups') + ' (' + (pe[p[0]] || 0) + ')', function () { goHash(p[2]); }]; });
        menu(anchor, items);
      } });
    var ot = T.overtime || {};
    tiles.push({ icon: '🕘', label: 'Overtime ' + per, value: ot.value, dp: 1, unit: 'h', delta: delta(ot.value, ot.prev, 'hours', null, pl),
      sub: ot.banked ? esc(fmtN(ot.banked, 1) + ' h banked as time in lieu') : '', subText: ot.banked ? fmtN(ot.banked, 1) + ' h banked' : '', viz: spark(ot.series, 'Approved overtime hours each month, last 12 months'), go: function () { goReport('overtime', P.from, P.to); } });
    var sh = T.shifts || {};
    tiles.push({ icon: '🗒️', label: 'Open shifts', value: sh.open, sub: sh.unaccepted ? esc(plur(sh.unaccepted, 'shift') + ' not accepted yet') : 'every shift accepted', subText: plur(sh.unaccepted || 0, 'shift') + ' not accepted', go: function () { goHash('#schedule?tab=openshifts'); } });
    var co = T.compliance || {}, cTot = (co.valid || 0) + (co.expiring || 0) + (co.expired || 0);
    tiles.push({ icon: '📜', label: 'Certificates to renew', value: (co.expiring || 0) + (co.expired || 0),
      sub: esc((co.expired || 0) + ' expired · ' + (co.expiring || 0) + ' within 30 days') + (co.duties_attention ? ' · <span data-kttip="' + esc('H&S duties (first aider, fire warden…) whose certificate is missing, expired or expiring') + '">' + esc(co.duties_attention + ' H&S duty') + '</span>' : ''),
      subText: (co.expired || 0) + ' expired, ' + (co.expiring || 0) + ' within 30 days', viz: ring(cTot ? (co.valid || 0) / cTot : null, (co.valid || 0) + ' of ' + cTot + ' certificates valid'), go: function () { goHash('#certifications'); } });
    var pr = T.probation || {};
    tiles.push({ icon: '🧪', label: 'Probation reviews due', value: (pr.due || 0) + (pr.overdue || 0), sub: pr.overdue ? '<span class="hrd-d b">⚠ ' + esc(pr.overdue + ' overdue') + '</span>' : esc((pr.on || 0) + ' in probation'), subText: (pr.overdue || 0) + ' overdue',
      go: function () { goReport('probation'); } });
    var on = T.onboarding || {};
    tiles.push({ icon: '🧲', label: 'Onboarding in progress', value: on.active, delta: delta(on.started_period, on.started_prev, 'count', null, pl), sub: esc(plur(on.started_period || 0, 'start') + ' ' + per), subText: plur(on.started_period || 0, 'start'),
      go: function () { goHash('#hr-hiring?tab=onboarding'); } });
    var cs = T.courses || {};
    tiles.push({ icon: '🎓', label: 'Training completed', value: cs.pct, unit: cs.pct == null ? '' : '%', sub: esc((cs.done || 0) + ' of ' + (cs.assigned || 0) + ' done') + (cs.overdue ? ' · <span class="hrd-d b">' + esc(cs.overdue + ' overdue') + '</span>' : ''), subText: (cs.done || 0) + ' of ' + (cs.assigned || 0) + ' done',
      viz: ring(cs.assigned ? cs.done / cs.assigned : null, (cs.done || 0) + ' of ' + (cs.assigned || 0) + ' course assignments completed'), go: function () { goHash('#hr-performance?tab=courses'); } });
    if (can.pay && T.labour) {
      var lb = T.labour;
      tiles.push({ icon: '💵', label: 'Labour cost this week', value: lb.cost, prefix: '$', sub: lb.budget ? esc('of ' + fmtMoney0(lb.budget) + ' budget') + (lb.pct > 100 ? ' <span class="hrd-d b">▲ ' + esc((lb.pct - 100) + '% over') + '</span>' : '') : 'no budget set',
        subText: lb.budget ? 'budget ' + fmtMoney0(lb.budget) : 'no budget', viz: lb.budget ? ring(Math.min(1, lb.cost / lb.budget), fmtMoney0(lb.cost) + ' planned against a ' + fmtMoney0(lb.budget) + ' weekly budget (' + lb.pct + '%)' + (lb.no_rate ? '; ' + lb.no_rate + ' shifts have no pay rate' : ''), lb.pct + '%') : '',
        go: function () { goHash('#schedule?tab=rotatools'); } });
    }
    // Payroll Engine P3 (2026-11-27): T4 / T4A / RL-1 deadline (Nov 1 - Mar 31, payroll engine on).
    if (can.pay && T.year_end) {
      var ye = T.year_end, yd = ye.days_left, ySt = ye.status === 'final' ? (ye.pending ? 'finalized · ' + ye.pending + ' change(s) waiting' : 'finalized') : (ye.status === 'draft' ? 'draft slips' : 'not started');
      tiles.push({ icon: '🧾', label: ye.year + ' tax slips due', value: yd < 0 ? 0 : yd, unit: yd === 1 ? ' day' : ' days',
        sub: esc('Feb ' + String(ye.deadline).slice(8) + ', ' + (ye.year + 1) + ' · ' + ySt) + (yd < 0 && ye.status !== 'final' ? ' <span class="hrd-d b">' + esc((-yd) + ' days late') + '</span>' : ''),
        subText: 'T4 / T4A / RL-1 ' + ySt, go: function () { goHash('#hr-payroll?tab=yearend'); } });
    }

    // ── charts ──
    var cards = [];
    var tenureTot = (C.tenure || []).reduce(function (a, b) { return a + b.value; }, 0);
    cards.push({ id: 'hc', title: 'Headcount, last 12 months', sub: 'People on staff at the end of each month', body: areaChart(C.headcount || [], { label: 'Headcount over the last 12 months' }),
      table: [['Month', 'People'], (C.headcount || []).map(function (r, i) { return [mName(i), r.value]; })], click: function () { goReport('headcount'); } });
    var MV = [{ key: 'starters', label: 'Starters', color: SER[0] }, { key: 'leavers', label: 'Leavers', color: SER[1] }];
    cards.push({ id: 'mv', title: 'Starters, leavers and turnover', sub: 'By month; hover a month for its turnover', body: monthBars((C.movement || []).map(function (r, i) { return Object.assign({ year: (mo[i] || {}).year }, r); }), MV, { dp: 0, label: 'Starters and leavers by month', empty: 'Nobody started or left in the last 12 months.', extra: function (r) { return r.turnover != null ? ' · turnover ' + fmtN(r.turnover, 1) + '%' : ''; } }) + legendHtml(MV),
      table: [['Month', 'Starters', 'Leavers', 'Turnover %'], (C.movement || []).map(function (r, i) { return [mName(i), r.starters, r.leavers, r.turnover == null ? '' : fmtN(r.turnover, 1)]; })],
      click: function (i) { var m = mo[i]; if (m) goReport('movement', m.key + '-01', lastDay(m.key) > d.today ? d.today : lastDay(m.key)); } });
    var AB = (C.absence || {}).reasons || [], ABs = AB.map(function (r, i) { return { key: r, label: r, color: SER[i % 8] }; });
    cards.push({ id: 'ab', title: 'Absence by reason', sub: 'Days of approved leave each month (working days)', body: monthBars(((C.absence || {}).rows || []).map(function (r, i) { return Object.assign({ year: (mo[i] || {}).year }, r); }), ABs, { stack: true, dp: 1, label: 'Absence days by reason and month', empty: 'No approved leave in the last 12 months.' }) + (ABs.length ? legendHtml(ABs) : ''),
      table: [['Month'].concat(AB), ((C.absence || {}).rows || []).map(function (r, i) { return [mName(i)].concat(AB.map(function (k) { return fmtN(r[k], 1); })); })],
      click: function (i) { var m = mo[i]; if (m) goReport('absence', m.key + '-01', lastDay(m.key) > d.today ? d.today : lastDay(m.key)); } });
    var OTs = [{ key: 'paid', label: 'To pay', color: SER[0] }, { key: 'banked', label: 'Banked (time in lieu)', color: SER[2] }];
    cards.push({ id: 'ot', title: 'Overtime trend', sub: 'Approved overtime hours by the day worked', body: monthBars((C.overtime || []).map(function (r, i) { return Object.assign({ year: (mo[i] || {}).year }, r); }), OTs, { stack: true, dp: 1, label: 'Approved overtime hours by month', empty: 'No approved overtime in the last 12 months.' }) + legendHtml(OTs),
      table: [['Month', 'To pay (h)', 'Banked (h)'], (C.overtime || []).map(function (r, i) { return [mName(i), fmtN(r.paid, 1), fmtN(r.banked, 1)]; })],
      click: function (i) { var m = mo[i]; if (m) goReport('overtime', m.key + '-01', lastDay(m.key) > d.today ? d.today : lastDay(m.key)); } });
    var LV = C.leave || [];
    cards.push({ id: 'lv', title: 'Leave balances and use', sub: 'Taken in the last 12 months against what is still left, all staff together', body: LV.length ? LV.map(function (r, i) {
      var used = Math.max(0, r.used), left = Math.max(0, r.balance), tot = used + left;
      return '<div class="hrd-meter" data-i="' + i + '" role="button" tabindex="0" data-kttip="' + esc(r.label + ': ' + fmtN(used, 1) + ' ' + r.unit + ' taken in 12 months, ' + fmtN(r.balance, 1) + ' ' + r.unit + ' left across ' + plur(r.people, 'person', 'people')) + '"><div class="t"><span>' + esc(r.label) + '</span><span><b>' + esc(fmtN(used, 1)) + '</b> taken · <b>' + esc(fmtN(r.balance, 1)) + '</b> ' + esc(r.unit) + ' left</span></div>'
        + '<div class="tr"><i style="width:' + (tot ? Math.max(used ? 2 : 0, 100 * used / tot) : 0).toFixed(1) + '%;background:' + SER[i % 8] + ';transition-delay:' + (RM ? 0 : i * 60) + 'ms"></i></div></div>';
    }).join('') : '<div class="hrd-empty">No leave balances yet (Time off › Leave types).</div>',
      table: [['Leave type', 'Taken (12 months)', 'Left', 'Unit', 'People'], LV.map(function (r) { return [r.label, fmtN(r.used, 1), fmtN(r.balance, 1), r.unit, r.people]; })], click: function () { goHash('#time-off?tab=balances'); } });
    var CE = C.centres || [];
    cards.push({ id: 'ce', title: 'Staff by centre', sub: CE.length > 1 ? 'Each person once, at their main centre · click a centre to filter this dashboard' : 'Each person once, at their main centre', body: CE.length ? hbarsHtml(CE.map(function (r) { return { label: r.label, value: r.total }; })) : '<div class="hrd-empty">Nobody on staff yet.</div>',
      table: [['Centre', 'People'], CE.map(function (r) { return [r.label, r.total]; })], click: function (i) { var c = CE[i]; if (c && c.centre_id && CE.length > 1 && !D.centre) { D.centre = String(c.centre_id); drawInsights(box); } else goReport('headcount'); } });
    var RO = (C.roles || []).map(function (p, i) { return { label: p.label, value: p.value, color: SER[i % 8] }; }), TY = (C.types || []).map(function (p, i) { return { label: p.label, value: p.value, color: SER[(i + 3) % 8] }; });
    var rTot = RO.reduce(function (a, b) { return a + b.value; }, 0);
    cards.push({ id: 'ro', title: 'Roles and employment types', sub: 'Current staff', body: '<div class="hrd-donuts">' + donutHtml(RO, fmtN(rTot), 'by role', 'r') + donutHtml(TY, fmtN(rTot), 'by type', 't') + '</div>',
      table: [['Group', 'People'], RO.map(function (p) { return ['Role: ' + p.label, p.value]; }).concat(TY.map(function (p) { return ['Type: ' + p.label, p.value]; }))], click: function () { goReport('headcount'); } });
    var TN = C.tenure || [];
    cards.push({ id: 'tn', title: 'Length of service', sub: 'Current staff by years since their start date', body: tenureTot ? hbarsHtml(TN.map(function (r) { return { label: r.label, value: r.value }; })) : '<div class="hrd-empty">Nobody on staff yet.</div>',
      table: [['Length of service', 'People'], TN.map(function (r) { return [r.label, r.value]; })], click: function () { goReport('tenure'); } });
    var CC = C.certs || {}, ccTot = (CC.valid || 0) + (CC.expiring || 0) + (CC.expired || 0);
    var segs = [['valid', 'Valid', 'var(--hrd-okfill)', ''], ['expiring', 'Expiring in 30 days', 'var(--hrd-warnfill)', 'dk'], ['expired', 'Expired', 'var(--hrd-badfill)', '']];
    cards.push({ id: 'cc', title: 'Certificates', sub: 'Each person\'s latest certificate of each kind', body: ccTot ? '<div class="hrd-seg" role="img" aria-label="' + esc(segs.map(function (s) { return s[1] + ' ' + (CC[s[0]] || 0); }).join(', ')) + '">' + segs.map(function (s, i) {
      var v = CC[s[0]] || 0; if (!v) return ''; var pc = Math.round(100 * v / ccTot);
      return '<span class="' + s[3] + '" style="flex:' + v + ' 1 0;background:' + s[2] + ';transition-delay:' + (RM ? 0 : i * 120) + 'ms" data-kttip="' + esc(s[1] + ': ' + v + ' (' + pc + '%)') + '">' + (pc >= 12 ? pc + '%' : '') + '</span>';
    }).join('') + '</div><div class="hrd-leg">' + segs.map(function (s) { return '<span><i style="background:' + s[2] + '"></i>' + esc((s[0] === 'valid' ? '✓ ' : s[0] === 'expired' ? '✕ ' : '⚠ ') + s[1] + ' ' + (CC[s[0]] || 0)) + '</span>'; }).join('') + '</div>'
      + ((CC.next || []).length ? '<div class="hrd-list">' + CC.next.map(function (x) { return '<div><span><b data-no-i18n>' + esc(x.name) + '</b> · <span data-no-i18n>' + esc(x.title) + '</span></span>' + (x.state === 'expired' ? '<span class="hrd-d b">✕ expired ' + esc(dDay(x.expires)) + '</span>' : '<span class="hrd-d" style="color:var(--hrd-warn)">⚠ ' + esc(dDay(x.expires)) + '</span>') + '</div>'; }).join('') + '</div>' : '')
      : '<div class="hrd-empty">No certificates recorded yet.</div>',
      table: [['State', 'Certificates'], segs.map(function (s) { return [s[1], CC[s[0]] || 0]; })], click: function () { goHash('#certifications'); } });
    var UP = C.upcoming || [], upMax = Math.max.apply(null, UP.map(function (x) { return x.approved + x.pending; }).concat([1]));
    var WD = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
    cards.push({ id: 'up', title: 'Who is off, next 14 days', sub: 'Approved and waiting time off · click a day for the list', wide: true, body: '<div class="hrd-strip">' + UP.map(function (x, i) {
      var p = x.date.split('-'), dt = new Date(+p[0], +p[1] - 1, +p[2]), we = dt.getDay() === 0 || dt.getDay() === 6, n = x.approved + x.pending;
      var nm = x.names || [], tip = dDay(x.date) + ': ' + (n ? nm.slice(0, 8).join(', ') + (nm.length > 8 ? ' and ' + (nm.length - 8) + ' more' : '') : 'nobody off');
      return '<div class="hrd-day' + (we ? ' we' : '') + (i === 0 ? ' td' : '') + '" data-i="' + i + '" role="button" tabindex="0" data-kttip="' + esc(tip) + '"><span class="n">' + (n || '') + '</span><span class="col">'
        + (x.pending ? '<i class="p" style="height:' + (52 * x.pending / upMax).toFixed(1) + 'px;transition-delay:' + (RM ? 0 : i * 35) + 'ms"></i>' : '')
        + (x.approved ? '<i style="height:' + (52 * x.approved / upMax).toFixed(1) + 'px;transition-delay:' + (RM ? 0 : i * 35) + 'ms"></i>' : '') + '</span>'
        + '<span class="w">' + esc(i === 0 ? 'Today' : WD[dt.getDay()]) + '</span><span class="w">' + esc(String(dt.getDate())) + '</span></div>';
    }).join('') + '</div>' + legendHtml([{ label: 'Approved', color: 'var(--hrd-acc)' }, { label: 'Waiting for approval', cls: 'p' }]),
      table: [['Day', 'Approved', 'Waiting'], UP.map(function (x) { return [dDay(x.date), x.approved, x.pending]; })], click: function (i) { var x = UP[i]; if (x) goReport('absence', x.date, x.date); } });

    var cents = d.centres || [];
    var h = '<div class="hrd-bar" data-kt-nogrid><div class="hrr-kinds" style="margin:0" role="group" aria-label="Period">' + ['month', 'quarter', 'year'].map(function (k) {
      return '<button type="button" class="hrr-kind' + (k === D.period ? ' on' : '') + '"' + (k === D.period ? ' aria-current="true"' : '') + ' data-per="' + k + '" data-kt-iconized="1">' + esc(PERIOD_LABEL[k]) + '</button>'; }).join('') + '</div>'
      + (cents.length > 1 ? '<select data-hrd-centre aria-label="Centre" data-kt-no-sheet><option value="">All centres</option>' + cents.map(function (c) { return '<option value="' + c.id + '"' + (String(c.id) === String(D.centre) ? ' selected' : '') + ' data-no-i18n>' + esc(c.name) + '</option>'; }).join('') + '</select>' : '')
      + '<span class="sp"></span><span class="upd">' + esc(dDay(P.from) + ' – ' + dDay(P.to) + ', compared with ' + pl + ' to the same day') + '</span></div>';
    h += '<div class="hrd-tiles">' + tiles.map(tileHtml).join('') + '</div>';
    h += '<div class="hrd-charts">' + cards.map(function (c, i) {
      return '<div class="hrd-c" data-card="' + i + '"' + (c.wide ? ' style="grid-column:1/-1"' : '') + '><div class="hd"><h4>' + esc(c.title) + '</h4><button type="button" class="hrd-tbtn" data-tbl="' + i + '" data-kt-iconized="1">Table</button></div>'
        + '<div class="sub">' + esc(c.sub) + '</div><div data-body>' + c.body + '</div></div>';
    }).join('') + '</div>';
    box.innerHTML = h;
    box.querySelectorAll('[data-per]').forEach(function (b) { b.addEventListener('click', function () { D.period = b.getAttribute('data-per'); drawInsights(box); }); });
    var cs2 = box.querySelector('[data-hrd-centre]'); if (cs2) cs2.addEventListener('change', function () { D.centre = this.value; drawInsights(box); });
    var act = function (elm, fn) {
      elm.addEventListener('click', function (e) { fn(e); });
      elm.addEventListener('keydown', function (e) { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); fn(e); } });
    };
    box.querySelectorAll('[data-tile]').forEach(function (t) { var def = tiles[+t.getAttribute('data-tile')]; act(t, function () { if (def.go) def.go(t); }); });
    box.querySelectorAll('[data-card]').forEach(function (cd) {
      var c = cards[+cd.getAttribute('data-card')], body = cd.querySelector('[data-body]');
      var wire = function () { body.querySelectorAll('[data-i]').forEach(function (m) { act(m, function (e) { e.stopPropagation(); c.click(+m.getAttribute('data-i')); }); }); };
      wire();
      cd.querySelector('[data-tbl]').addEventListener('click', function () {
        var on = this.getAttribute('data-on') !== '1';
        this.setAttribute('data-on', on ? '1' : '0'); this.textContent = on ? 'Chart' : 'Table';
        body.innerHTML = on ? tableHtml(c.table[0], c.table[1]) : c.body;
        if (!on) { wire(); reveal(cd); }
      });
    });
    watch(box);
  }

  // ── Dashboard ───────────────────────────────────────────────────────────
  async function drawDashboard(host) {
    css();
    host.innerHTML = '<div data-hrd-ins></div><div class="hrr-tt">Setup and to-dos</div><div data-hrd-setup></div>';
    drawInsights(host.querySelector('[data-hrd-ins]'));
    return drawSetup(host.querySelector('[data-hrd-setup]'));
  }
  async function drawSetup(host) {
    var r;
    try { r = await Api.get('/hr/dashboard'); } catch (e) { host.innerHTML = '<div class="hr-note">' + esc(errMsg(e)) + '</div>'; return; }
    if (!host.isConnected) return;
    var reload = function () { if (host.isConnected) drawSetup(host); };
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
