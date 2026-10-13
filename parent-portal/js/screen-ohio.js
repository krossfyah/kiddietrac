/* ═══════════════════════════════════════════════════════════════════════
   Ohio: publicly funded child care (#pfcc) and Step Up To Quality (#sutq) — 2026-10-10

   #pfcc  authorizations (case number, dates, care unit, rate per unit, weekly copay, the
          signed copay agreement), the weekly copay ledger, absent days per half-year, the
          weekly TAP reconciliation (Sunday–Saturday, CSV) and the state / family split of a
          DRAFT invoice. Ohio has no TAP API, so this lines KiddieTrac attendance up beside
          what each authorization allows for the director to match in TAP by hand.
   #sutq  screening / family needs / assessment timers per child, referrals, the
          continuous improvement plan and the rating evidence binder.

   Directors (their centres) and admins. The menu entries only show for a US-OH agency or one
   that switched the feature on (body.kt-jur-oh / kt-jur-sutq); the API 404s otherwise.
   ═══════════════════════════════════════════════════════════════════════ */
(function (window) {
  'use strict';
  var KT = window.KT;
  if (!KT || !KT.Shell || !KT.Shell.registerScreen) return;
  var Api = KT.Api;

  function esc(s) { return String(s == null ? '' : s).replace(/[&<>"]/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]; }); }
  function toast(i, t, m, c) { try { KT.toast && KT.toast(i, t, m || '', c); } catch (e) {} }
  function money(n) { return '$' + (Number(n) || 0).toFixed(2); }
  function apiBase() { return (window.KT_CONFIG && window.KT_CONFIG.apiBase) || KT.API_BASE || 'https://api.kiddietrac.com/api/v1'; }
  function errMsg(e) { return (e && e.message) || 'Something went wrong.'; }
  function fmtDate(d) { if (!d) return '—'; var p = String(d).slice(0, 10).split('-'); var m = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'][+p[1] - 1]; return m + ' ' + (+p[2]) + ', ' + p[0]; }

  /* ── menu visibility ── */
  (function () {
    var st = document.createElement('style');
    st.textContent = 'body:not(.kt-jur-oh) [data-hash="pfcc"], body:not(.kt-jur-oh) [href="#pfcc"],'
      + 'body:not(.kt-jur-sutq) [data-hash="sutq"], body:not(.kt-jur-sutq) [href="#sutq"] { display:none !important; }';
    (document.head || document.documentElement).appendChild(st);
  })();
  var probedFor = null;
  function probe() {
    if (!Api || !Api.get) return;
    var tok = sessionStorage.getItem('kt_token') || localStorage.getItem('kt_token');
    if (!tok) return;
    var ag = sessionStorage.getItem('kt_active_agency_id') || '';
    if (probedFor === ag) return;
    /* ONLY WHO CAN USE IT (2026-10-13, Anthony: "why am i seeing this today with most of
       the users - Get:api/v1/pfcc/settings (failed)"). This ran for every signed-in person,
       but the endpoint admits directors and admins only, so every parent, educator and home
       visitor made two refused requests per page load, and anyone with no agency chosen got
       "Select an agency first". The Ohio menu is for those roles alone; nobody else asks.
       No role class yet (still booting): wait for the next timer rather than decide now. */
    var bc = document.body ? document.body.classList : null;
    var anyRole = bc && /\brole-/.test(document.body.className || '');
    if (!anyRole) return;
    if (!(bc.contains('role-agency-admin') || bc.contains('role-centre-director') || bc.contains('role-platform-admin'))) {
      probedFor = ag;
      document.body.classList.remove('kt-jur-oh', 'kt-jur-sutq');
      return;
    }
    // A platform admin's agency is whichever one they have chosen; with none chosen there
    // is nothing to ask about. (Agency admins and directors are resolved by the server.)
    if (!ag) {
      try {
        var u = JSON.parse(sessionStorage.getItem('kt_user') || localStorage.getItem('kt_user') || '{}');
        if (Array.isArray(u.roles) && u.roles.indexOf('platform_admin') !== -1) return;
      } catch (e) {}
    }
    probedFor = ag;
    Api.get('/pfcc/settings').then(function (d) {
      document.body.classList.toggle('kt-jur-oh', !!(d && d.pfcc_on));
      document.body.classList.toggle('kt-jur-sutq', !!(d && d.sutq_on));
    }).catch(function () { document.body.classList.remove('kt-jur-oh', 'kt-jur-sutq'); });
  }
  setTimeout(probe, 2500); setTimeout(probe, 8000);
  window.addEventListener('kt:jurisdiction', function () { probedFor = null; probe(); });
  window.addEventListener('kt:agency-changed', function () { probedFor = null; probe(); });

  var CSS_ID = 'kt-ohio-css';
  function css() {
    if (document.getElementById(CSS_ID)) return;
    var s = document.createElement('style'); s.id = CSS_ID;
    s.textContent = '.oh-wrap{padding:20px 24px;max-width:1800px;margin:0 auto;}'
      + '.oh-card{background:#fff;border:1px solid #E2E8F0;border-radius:12px;padding:14px 16px;margin-top:14px;}'
      + '.oh-row{display:flex;gap:8px;align-items:center;flex-wrap:wrap;}'
      + '.oh-i{height:30px;padding:0 10px;border:1px solid #CBD5E1;border-radius:8px;font-size:13px;background:#fff;box-sizing:border-box;}'
      + '.oh-b{height:30px;padding:0 12px;border-radius:8px;border:1px solid #CBD5E1;background:#fff;font-weight:700;font-size:13px;cursor:pointer;white-space:nowrap;}'
      + '.oh-b.s{height:26px;padding:0 9px;font-size:12px;}'
      + '.oh-b.p{background:#B91C1C;border-color:#B91C1C;color:#fff;}'
      + '.oh-tabs{display:flex;gap:6px;flex-wrap:wrap;margin-top:14px;}'
      + '.oh-tab{height:30px;padding:0 12px;border-radius:999px;border:1px solid #CBD5E1;background:#fff;font-weight:700;font-size:13px;cursor:pointer;}'
      + '.oh-tab.on{background:#0F172A;border-color:#0F172A;color:#fff;}'
      + '.oh-t{width:100%;border-collapse:collapse;font-size:13px;}'
      + '.oh-t th,.oh-t td{padding:7px 8px;border-top:1px solid #EEF2F6;text-align:left;vertical-align:top;}'
      + '.oh-t th{background:#F8FAFC;color:#475569;font-size:11px;text-transform:uppercase;letter-spacing:.4px;border-top:0;}'
      + '.oh-g{display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:10px;}'
      + '.oh-l{display:block;font-size:11.5px;font-weight:800;color:#334155;text-transform:uppercase;letter-spacing:.4px;margin-bottom:4px;}'
      + '.oh-note{font-size:12.5px;color:#64748B;margin-top:6px;}'
      + '.oh-warn{background:#FFFBEB;border:1px solid #FDE68A;color:#92400E;border-radius:10px;padding:9px 12px;font-size:13px;margin-top:10px;}';
    document.head.appendChild(s);
  }
  var TONE = { ok: ['#ECFDF5', '#047857'], done: ['#ECFDF5', '#047857'], paid: ['#ECFDF5', '#047857'], waived: ['#F1F5F9', '#475569'],
    due: ['#FFFBEB', '#B45309'], due_soon: ['#FFFBEB', '#B45309'], partial: ['#FFFBEB', '#B45309'], partly: ['#FFFBEB', '#B45309'],
    overdue: ['#FEF2F2', '#B91C1C'], missing: ['#FEF2F2', '#B91C1C'], unknown: ['#F1F5F9', '#64748B'], info: ['#EFF6FF', '#1D4ED8'], 'n/a': ['#F1F5F9', '#64748B'],
    planned: ['#F1F5F9', '#475569'], in_progress: ['#EFF6FF', '#1D4ED8'] };
  var WORD = { due_soon: 'Due soon', in_progress: 'In progress', 'n/a': 'N/A' };
  function pill(st, txt) { var t = TONE[st] || TONE.unknown; return '<span data-kt-no-tone style="display:inline-block;padding:2px 9px;border-radius:999px;font-size:12px;font-weight:700;background:' + t[0] + ';color:' + t[1] + ';white-space:nowrap;">' + esc(txt || WORD[st] || (st.charAt(0).toUpperCase() + st.slice(1))) + '</span>'; }
  function tabs(list, cur) { return '<div class="oh-tabs">' + list.map(function (t) { return '<button class="oh-tab' + (t[0] === cur ? ' on' : '') + '" data-tab="' + t[0] + '">' + esc(t[1]) + '</button>'; }).join('') + '</div>'; }
  function centreSelect(id, centres, cur, all) {
    return '<select class="oh-i" id="' + id + '">' + (all ? '<option value="">All my centres</option>' : '') + Object.keys(centres || {}).map(function (k) { return '<option value="' + k + '"' + (String(k) === String(cur) ? ' selected' : '') + '>' + esc(centres[k]) + '</option>'; }).join('') + '</select>';
  }
  function form(html) { var w = document.createElement('div'); w.innerHTML = html; return w; }
  function val(w, id) { var el = w.querySelector('#' + id); return el ? (el.type === 'checkbox' ? el.checked : el.value.trim()) : ''; }
  function modal(title, body, label, save) {
    KT.Shell.Modal.open({ title: title, body: body, actions: [{ label: 'Cancel' }, { label: label, style: 'btn-primary', busyLabel: 'Saving…', handler: function () {
      var msg = body.querySelector('.oh-msg');
      return Promise.resolve().then(save).then(function (r) { return r; }, function (e) { if (msg) { msg.style.color = '#B91C1C'; msg.textContent = errMsg(e); } return false; });
    } }] });
  }

  /* ═════════════════════ #pfcc ═════════════════════ */
  var P = { tab: 'auth', week: '', centre: '', auth: null };

  async function renderPfcc(main) {
    css();
    main.innerHTML = '<div class="oh-wrap" style="color:#64748B;">Loading…</div>';
    var d;
    try { d = await Api.get('/pfcc'); } catch (e) { main.innerHTML = '<div class="oh-wrap" style="color:#B91C1C;">' + esc(errMsg(e)) + '</div>'; return; }
    var h = '<div class="oh-wrap"><div class="kt-hero" style="background:linear-gradient(135deg,#0B1F3A 0%,#B91C1C 100%);">'
      + '<div class="kt-hero-greet">🏛️ OHIO</div><h1>Publicly funded child care</h1>'
      + '<div class="kt-hero-sub">Authorizations, weekly copays and the TAP reconciliation. Weeks run Sunday to Saturday.</div></div>';
    if (!d.on) {
      h += '<div class="oh-card">Ohio publicly funded child care is not switched on for this agency. It switches on by itself for agencies in Ohio.'
        + (d.is_admin ? '<div class="oh-row" style="margin-top:10px;"><button class="oh-b p" id="oh-enable">Switch it on anyway</button></div>' : '') + '</div></div>';
      main.innerHTML = h;
      var en = main.querySelector('#oh-enable');
      if (en) en.addEventListener('click', function () { Api.patch('/pfcc/settings', { pfcc_enabled: true }).then(function () { probedFor = null; probe(); renderPfcc(main); }).catch(function (e) { toast('⚠️', 'Could not save', errMsg(e), '#DC2626'); }); });
      return;
    }
    var T = [['auth', 'Authorizations'], ['copays', 'Copays'], ['tap', 'TAP reconciliation'], ['split', 'Invoice split'], ['settings', 'Limits & alerts']];
    h += tabs(T, P.tab) + '<div id="oh-body"></div></div>';
    main.innerHTML = h;
    main.querySelectorAll('[data-tab]').forEach(function (b) { b.addEventListener('click', function () { P.tab = b.getAttribute('data-tab'); renderPfcc(main); }); });
    var body = main.querySelector('#oh-body');
    var fn = { auth: authTab, copays: copayTab, tap: tapTab, split: splitTab, settings: settingsTab }[P.tab] || authTab;
    fn(body, d, main);
  }

  function authForm(d, a) {
    a = a || {};
    return form('<div class="oh-g">'
      + (a.id ? '' : '<div style="grid-column:1/-1;"><label class="oh-l">Child</label><select class="oh-i" id="f-child" style="width:100%;"><option value="">Loading…</option></select></div>')
      + '<div><label class="oh-l">Case number</label><input class="oh-i" id="f-case" maxlength="64" value="' + esc(a.case_number || '') + '" style="width:100%;"></div>'
      + '<div><label class="oh-l">Care unit</label><select class="oh-i" id="f-unit" style="width:100%;">' + Object.keys(d.units).map(function (k) { return '<option value="' + k + '"' + (k === a.care_unit ? ' selected' : '') + '>' + esc(d.units[k]) + '</option>'; }).join('') + '</select></div>'
      + '<div><label class="oh-l">Units per week</label><input class="oh-i" id="f-units" type="number" step="0.25" min="0.25" value="' + esc(a.units_per_week || 1) + '" style="width:100%;"></div>'
      + '<div><label class="oh-l">Provider rate per unit ($)</label><input class="oh-i" id="f-rate" type="number" step="0.01" min="0" value="' + esc(a.rate_per_unit != null ? a.rate_per_unit : '') + '" style="width:100%;"></div>'
      + '<div><label class="oh-l">Weekly family copay ($)</label><input class="oh-i" id="f-copay" type="number" step="0.01" min="0" value="' + esc(a.weekly_copay != null ? a.weekly_copay : '') + '" style="width:100%;"></div>'
      + '<div><label class="oh-l">Starts</label><input class="oh-i" id="f-start" type="date" value="' + esc(a.starts_on || d.today) + '" style="width:100%;"></div>'
      + '<div><label class="oh-l">Ends (optional)</label><input class="oh-i" id="f-end" type="date" value="' + esc(a.ends_on || '') + '" style="width:100%;"></div>'
      + '<div><label class="oh-l">Copay agreement signed</label><input class="oh-i" id="f-signed" type="date" value="' + esc(a.agreement_signed_on || '') + '" style="width:100%;"></div>'
      + '<div style="grid-column:1/-1;"><label class="oh-l">Notes</label><input class="oh-i" id="f-notes" maxlength="2000" value="' + esc(a.notes || '') + '" style="width:100%;"></div>'
      + '</div><div class="oh-note">Enter the rate from the child\'s authorization in Ohio\'s system. KiddieTrac does not hold the state rate table.</div><div class="oh-msg" style="font-size:13px;margin-top:8px;"></div>');
  }
  function authPayload(w) {
    return { case_number: val(w, 'f-case') || null, care_unit: val(w, 'f-unit'), units_per_week: +val(w, 'f-units'), rate_per_unit: +val(w, 'f-rate') || 0,
      weekly_copay: +val(w, 'f-copay') || 0, starts_on: val(w, 'f-start'), ends_on: val(w, 'f-end') || null, agreement_signed_on: val(w, 'f-signed') || null, notes: val(w, 'f-notes') || null };
  }

  function authTab(body, d, main) {
    var cap = d.settings.absent_cap;
    var rows = d.authorizations || [];
    var h = '<div class="oh-card"><div class="oh-row" style="margin-bottom:8px;"><div style="font-weight:800;font-size:15px;flex:1;">Authorizations</div><button class="oh-b p" id="oh-add">Add authorization</button></div>';
    h += rows.length ? '<div style="overflow-x:auto;"><table class="oh-t"><thead><tr><th>Child</th><th>Case</th><th>Care unit</th><th>Weekly</th><th>Dates</th><th>Copay</th><th>Absent (' + esc(d.half_year.label) + ')</th><th>Agreement</th><th></th></tr></thead><tbody>'
      + rows.map(function (a) {
          var abs = a.absent_days >= cap ? pill('overdue', a.absent_days + ' of ' + cap) : a.absent_days >= cap - 2 ? pill('due', a.absent_days + ' of ' + cap) : esc(a.absent_days + ' of ' + cap);
          var cp = !a.active ? '—' : a.unpaid_weeks >= d.settings.alert_unpaid_weeks ? pill('overdue', a.unpaid_weeks + ' wk unpaid') : a.unpaid_weeks ? pill('due', a.unpaid_weeks + ' wk unpaid') : pill('ok', 'Up to date');
          return '<tr' + (a.active ? '' : ' style="opacity:.6;"') + '><td><b>' + esc(a.child) + '</b><div style="color:#64748B;font-size:12px;">' + esc(a.centre) + '</div></td><td>' + esc(a.case_number || '—') + '</td>'
            + '<td>' + esc(d.units[a.care_unit]) + '<div style="color:#64748B;font-size:12px;">' + esc(a.units_per_week) + ' × ' + money(a.rate_per_unit) + '</div></td>'
            + '<td>' + money(a.weekly.gross) + '<div style="color:#64748B;font-size:12px;">state ' + money(a.weekly.state) + ' · family ' + money(a.weekly.copay) + '</div></td>'
            + '<td style="white-space:nowrap;">' + fmtDate(a.starts_on) + ' – ' + (a.ends_on ? fmtDate(a.ends_on) : 'open') + (a.ended ? '<div>' + pill('waived', 'Ended') + '</div>' : '') + '</td>'
            + '<td>' + cp + '</td><td>' + abs + '</td>'
            + '<td>' + (a.has_agreement ? '<button class="oh-b s" data-view="' + a.id + '">View</button> ' : '') + '<button class="oh-b s" data-up="' + a.id + '">' + (a.has_agreement ? 'Replace' : 'Upload') + '</button></td>'
            + '<td style="white-space:nowrap;"><button class="oh-b s" data-cp="' + a.id + '">Copays</button> <button class="oh-b s" data-edit="' + a.id + '">Edit</button>' + (a.ended ? '' : ' <button class="oh-b s" data-end="' + a.id + '">End</button>') + '</td></tr>';
        }).join('') + '</tbody></table></div>'
      : '<div style="color:#64748B;font-size:13px;">No authorizations yet. Add one for each child with publicly funded care.</div>';
    h += '<div class="oh-note">Absent days count per child for ' + esc(fmtDate(d.half_year.from)) + ' – ' + esc(fmtDate(d.half_year.to)) + '. The limit is set to ' + esc(cap) + ' under Limits & alerts: check it against your authorizations.</div></div>';
    body.innerHTML = h;
    var byId = {}; rows.forEach(function (a) { byId[a.id] = a; });
    body.querySelector('#oh-add').addEventListener('click', function () {
      var w = authForm(d);
      Api.get('/pfcc/children').then(function (r) {
        w.querySelector('#f-child').innerHTML = '<option value="">Choose a child</option>' + r.children.map(function (c) { return '<option value="' + c.id + '">' + esc(c.name) + ' (' + esc(c.family || '') + ')</option>'; }).join('');
      });
      modal('Add a PFCC authorization', w, 'Add', function () {
        var p = authPayload(w); p.child_id = +val(w, 'f-child');
        if (!p.child_id) throw new Error('Choose a child.');
        return Api.post('/pfcc/authorizations', p).then(function () { toast('🏛️', 'Authorization added', '', '#16A34A'); renderPfcc(main); });
      });
    });
    body.querySelectorAll('[data-edit]').forEach(function (b) { b.addEventListener('click', function () {
      var a = byId[b.getAttribute('data-edit')], w = authForm(d, a);
      modal('Edit authorization: ' + a.child, w, 'Save', function () { return Api.patch('/pfcc/authorizations/' + a.id, authPayload(w)).then(function () { toast('🏛️', 'Saved', '', '#16A34A'); renderPfcc(main); }); });
    }); });
    body.querySelectorAll('[data-end]').forEach(function (b) { b.addEventListener('click', function () {
      var a = byId[b.getAttribute('data-end')];
      var w = form('<label class="oh-l">Last day of care under this authorization</label><input class="oh-i" id="f-end" type="date" value="' + esc(d.today) + '"><div class="oh-msg" style="font-size:13px;margin-top:8px;"></div>');
      modal('End authorization: ' + a.child, w, 'End it', function () { return Api.post('/pfcc/authorizations/' + a.id + '/end', { ends_on: val(w, 'f-end') }).then(function () { renderPfcc(main); }); });
    }); });
    body.querySelectorAll('[data-cp]').forEach(function (b) { b.addEventListener('click', function () { P.tab = 'copays'; P.auth = b.getAttribute('data-cp'); renderPfcc(main); }); });
    body.querySelectorAll('[data-view]').forEach(function (b) { b.addEventListener('click', function () {
      KT.openFile(function () { return KT.fetchFileBlob(apiBase() + '/pfcc/authorizations/' + b.getAttribute('data-view') + '/agreement'); }, { title: 'Signed copay agreement' });
    }); });
    body.querySelectorAll('[data-up]').forEach(function (b) { b.addEventListener('click', function () {
      var a = byId[b.getAttribute('data-up')];
      var w = form('<label class="oh-l">Signed copay agreement (PDF or photo)</label><input type="file" id="f-file" accept=".pdf,.jpg,.jpeg,.png,.heic,.doc,.docx" style="font-size:13px;">'
        + '<label class="oh-l" style="margin-top:10px;">Date signed</label><input class="oh-i" id="f-signed" type="date" value="' + esc(a.agreement_signed_on || d.today) + '"><div class="oh-msg" style="font-size:13px;margin-top:8px;"></div>');
      modal('Copay agreement: ' + a.child, w, 'Upload', function () {
        var f = w.querySelector('#f-file').files[0]; if (!f) throw new Error('Choose the file.');
        var fd = new FormData(); fd.append('file', f); fd.append('signed_on', val(w, 'f-signed'));
        return Api.postForm('/pfcc/authorizations/' + a.id + '/agreement', fd).then(function () { toast('📄', 'Agreement saved', '', '#16A34A'); renderPfcc(main); });
      });
    }); });
  }

  async function copayTab(body, d, main) {
    var rows = (d.authorizations || []).filter(function (a) { return a.weekly_copay > 0; });
    if (!rows.length) { body.innerHTML = '<div class="oh-card" style="color:#64748B;font-size:13px;">No authorization has a weekly copay.</div>'; return; }
    if (!P.auth || !rows.some(function (a) { return String(a.id) === String(P.auth); })) P.auth = String(rows[0].id);
    var h = '<div class="oh-card"><div class="oh-row" style="margin-bottom:8px;"><div style="font-weight:800;font-size:15px;flex:1;">Weekly copay ledger</div>'
      + '<select class="oh-i" id="oh-auth">' + rows.map(function (a) { return '<option value="' + a.id + '"' + (String(a.id) === String(P.auth) ? ' selected' : '') + '>' + esc(a.child) + ' · ' + money(a.weekly_copay) + '/wk</option>'; }).join('') + '</select></div><div id="oh-cp">Loading…</div></div>';
    body.innerHTML = h;
    body.querySelector('#oh-auth').addEventListener('change', function () { P.auth = this.value; renderPfcc(main); });
    var c;
    try { c = await Api.get('/pfcc/authorizations/' + P.auth + '/copays'); } catch (e) { body.querySelector('#oh-cp').textContent = errMsg(e); return; }
    var a = rows.filter(function (x) { return String(x.id) === String(P.auth); })[0];
    var x = (c.unpaid_weeks >= c.alert_after ? '<div class="oh-warn">' + esc(c.unpaid_weeks) + ' finished week(s) unpaid in a row. Ohio expects non-payment past 2 weeks to be reported to the county within 3 weeks.</div>' : '')
      + '<div style="overflow-x:auto;margin-top:8px;"><table class="oh-t"><thead><tr><th>Week (Sun–Sat)</th><th>Due</th><th>Paid</th><th>Status</th><th>Paid on</th><th>Note</th><th></th></tr></thead><tbody>'
      + c.copays.map(function (r) {
          return '<tr><td style="white-space:nowrap;">' + fmtDate(r.week_start) + '</td><td>' + money(r.amount_due) + '</td><td>' + money(r.amount_paid) + '</td><td>' + pill(r.status) + '</td><td>' + (r.paid_on ? fmtDate(r.paid_on) : '—') + '</td><td>' + esc(r.note || '') + '</td>'
            + '<td><button class="oh-b s" data-pay="' + r.id + '" data-due="' + r.amount_due + '" data-paid="' + r.amount_paid + '" data-week="' + esc(r.week_start) + '">Record</button></td></tr>';
        }).join('') + '</tbody></table></div><div class="oh-note">Copays are recorded here, separately from invoices. Families are not messaged about them.</div>';
    body.querySelector('#oh-cp').innerHTML = x;
    body.querySelectorAll('[data-pay]').forEach(function (b) { b.addEventListener('click', function () {
      var w = form('<div class="oh-g"><div><label class="oh-l">Amount paid ($)</label><input class="oh-i" id="f-amt" type="number" step="0.01" min="0" value="' + esc(+b.getAttribute('data-paid') || b.getAttribute('data-due')) + '" style="width:100%;"></div>'
        + '<div><label class="oh-l">Paid on</label><input class="oh-i" id="f-on" type="date" value="' + esc(d.today) + '" style="width:100%;"></div>'
        + '<div><label class="oh-l">Status</label><select class="oh-i" id="f-st" style="width:100%;"><option value="">Work it out</option><option value="waived">Waived</option><option value="due">Not paid</option></select></div></div>'
        + '<label class="oh-l" style="margin-top:10px;">Note</label><input class="oh-i" id="f-note" maxlength="255" style="width:100%;"><div class="oh-msg" style="font-size:13px;margin-top:8px;"></div>');
      modal('Copay for the week of ' + fmtDate(b.getAttribute('data-week')) + ' (' + a.child + ')', w, 'Save', function () {
        var p = { amount_paid: +val(w, 'f-amt') || 0, paid_on: val(w, 'f-on') || null, note: val(w, 'f-note') || null };
        if (val(w, 'f-st')) p.status = val(w, 'f-st');
        return Api.patch('/pfcc/copays/' + b.getAttribute('data-pay'), p).then(function () { renderPfcc(main); });
      });
    }); });
  }

  async function tapTab(body, d, main) {
    var q = []; if (P.week) q.push('week=' + P.week); if (P.centre) q.push('centre_id=' + P.centre);
    body.innerHTML = '<div class="oh-card">Loading…</div>';
    var r;
    try { r = await Api.get('/pfcc/reconciliation' + (q.length ? '?' + q.join('&') : '')); } catch (e) { body.innerHTML = '<div class="oh-card" style="color:#B91C1C;">' + esc(errMsg(e)) + '</div>'; return; }
    P.week = r.week_start;
    var h = '<div class="oh-card"><div class="oh-row" style="margin-bottom:8px;"><div style="font-weight:800;font-size:15px;flex:1;">TAP week ' + fmtDate(r.week_start) + ' – ' + fmtDate(r.week_end) + '</div>'
      + '<button class="oh-b s" id="oh-prev">‹ Week</button><input class="oh-i" type="date" id="oh-week" value="' + esc(r.week_start) + '"><button class="oh-b s" id="oh-next">Week ›</button>'
      + centreSelect('oh-cen', d.centres, P.centre, true) + '<button class="oh-b" id="oh-csv">Download CSV</button></div>'
      + (r.sweep_warning ? '<div class="oh-warn">' + esc(r.sweep_warning) + '</div>' : '')
      + '<div class="oh-note" style="margin-bottom:6px;">' + (r.issues ? esc(r.issues) + ' child(ren) need a look before you submit in TAP.' : 'Nothing flagged this week.') + ' Ohio has no TAP connection, so match these in TAP by hand.</div>'
      + (r.rows.length ? '<div style="overflow-x:auto;"><table class="oh-t"><thead><tr><th>Child</th><th>Case</th><th>Care unit</th><th>Days</th><th>Hours</th><th>Absent</th><th>Check</th></tr></thead><tbody>'
        + r.rows.map(function (x) {
            return '<tr><td><b>' + esc(x.child) + '</b><div style="color:#64748B;font-size:12px;">' + esc(x.centre) + '</div></td><td>' + esc(x.case_number || '—') + '</td><td>' + esc(x.care_unit || '—') + '</td>'
              + '<td>' + esc(x.days_attended) + '</td><td>' + esc(x.hours) + '</td><td>' + esc(x.absent_days) + '</td>'
              + '<td>' + (x.flags.length ? x.flags.map(function (f) { return '<div style="color:#B91C1C;font-size:12.5px;">• ' + esc(f) + '</div>'; }).join('') : pill('ok', 'OK')) + '</td></tr>';
          }).join('') + '</tbody></table></div>'
        : '<div style="color:#64748B;font-size:13px;">No authorized children and no attendance this week.</div>')
      + '</div>';
    body.innerHTML = h;
    function shift(n) { var dt = new Date(r.week_start + 'T12:00:00'); dt.setDate(dt.getDate() + n); P.week = dt.toISOString().slice(0, 10); renderPfcc(main); }
    body.querySelector('#oh-prev').addEventListener('click', function () { shift(-7); });
    body.querySelector('#oh-next').addEventListener('click', function () { shift(7); });
    body.querySelector('#oh-week').addEventListener('change', function () { P.week = this.value; renderPfcc(main); });
    body.querySelector('#oh-cen').addEventListener('change', function () { P.centre = this.value; renderPfcc(main); });
    body.querySelector('#oh-csv').addEventListener('click', function () {
      var url = apiBase() + '/pfcc/reconciliation?format=csv&week=' + encodeURIComponent(r.week_start) + (P.centre ? '&centre_id=' + P.centre : '');
      KT.openFile(function () { return KT.fetchFileBlob(url); }, { title: 'TAP reconciliation', filename: 'tap-reconciliation-' + r.week_start + '.csv', mime: 'text/csv' });
    });
  }

  async function splitTab(body, d, main) {
    body.innerHTML = '<div class="oh-card">Loading…</div>';
    var r;
    try { r = await Api.get('/pfcc/invoices'); } catch (e) { body.innerHTML = '<div class="oh-card" style="color:#B91C1C;">' + esc(errMsg(e)) + '</div>'; return; }
    var h = '<div class="oh-card"><div style="font-weight:800;font-size:15px;margin-bottom:4px;">Split an invoice between the state and the family</div>'
      + '<div class="oh-note" style="margin-bottom:8px;">For a DRAFT invoice only, one at a time, and only when you choose to. The state\'s share (authorized units × rate − copay, pro-rated by week) goes on the invoice as a subsidy, and the family\'s copay is shown. Undo it any time before the invoice is sent. Other invoices are never changed.</div>'
      + (r.invoices.length ? '<div style="overflow-x:auto;"><table class="oh-t"><thead><tr><th>Invoice</th><th>Family</th><th>Period</th><th>Status</th><th>Subtotal</th><th>State share</th><th>Total</th><th></th></tr></thead><tbody>'
        + r.invoices.map(function (i) {
            return '<tr><td>' + esc(i.invoice_number) + '</td><td>' + esc(i.family_name) + '</td><td style="white-space:nowrap;">' + fmtDate(i.period_start) + ' – ' + fmtDate(i.period_end) + '</td><td>' + pill(i.status === 'draft' ? 'due' : 'waived', i.status) + '</td>'
              + '<td>' + money(i.subtotal) + '</td><td>' + (i.split_applied ? money(i.state_amount) : '—') + '</td><td>' + money(i.total) + '</td>'
              + '<td><button class="oh-b s" data-inv="' + i.id + '">' + (i.split_applied ? 'View' : 'Preview') + '</button></td></tr>';
          }).join('') + '</tbody></table></div>'
        : '<div style="color:#64748B;font-size:13px;">No invoices for children with a PFCC authorization yet.</div>') + '</div>';
    body.innerHTML = h;
    body.querySelectorAll('[data-inv]').forEach(function (b) { b.addEventListener('click', async function () {
      var id = b.getAttribute('data-inv'), p;
      try { p = await Api.get('/pfcc/invoices/' + id + '/split'); } catch (e) { toast('⚠️', 'Could not load', errMsg(e), '#DC2626'); return; }
      var s = p.split, inv = p.invoice;
      var w = form('<div style="font-size:13px;">Invoice ' + esc(inv.number) + ' · subtotal ' + money(inv.subtotal) + ' · status ' + esc(inv.status) + '</div>'
        + (s.children.length ? '<table class="oh-t" style="margin-top:8px;"><thead><tr><th>Child</th><th>Case</th><th>Weeks</th><th>State</th><th>Copay</th></tr></thead><tbody>'
          + s.children.map(function (c) { return '<tr><td>' + esc(c.child) + '</td><td>' + esc(c.case_number || '—') + '</td><td>' + esc(c.weeks) + '</td><td>' + money(c.state) + '</td><td>' + money(c.copay) + '</td></tr>'; }).join('')
          + '</tbody></table>' : '<div class="oh-warn">No child on this invoice has an authorization for its period.</div>')
        + '<div style="margin-top:8px;font-size:13.5px;"><b>State share: ' + money(s.state) + '</b> · family copay ' + money(s.copay) + (s.capped ? ' <span style="color:#B45309;">(capped at the invoice subtotal)</span>' : '') + '</div>'
        + '<div style="margin-top:4px;font-size:13.5px;">Family owes after the split: <b>' + money(s.family_owes) + '</b>' + (s.write_off ? ' · written off ' + money(s.write_off) : '') + '</div>'
        + '<div class="oh-note">' + (s.family_billing === 'copay_plus_difference'
            ? 'Your agency bills the family the copay plus any tuition above what the state pays (Limits &amp; alerts).'
            : 'Your agency bills the family only the copay; tuition above what the state pays is written off on this invoice (Limits &amp; alerts).') + '</div>'
        + '<div class="oh-msg" style="font-size:13px;margin-top:8px;"></div>');
      var actions = [{ label: 'Close' }];
      if (inv.status === 'draft' && p.applied) actions.push({ label: 'Undo the split', busyLabel: 'Undoing…', handler: function () { return Api.delete('/pfcc/invoices/' + id + '/split').then(function () { toast('↩️', 'Split removed', '', '#16A34A'); renderPfcc(main); }, function (e) { w.querySelector('.oh-msg').textContent = errMsg(e); return false; }); } });
      if (inv.status === 'draft' && !p.applied && s.children.length) actions.push({ label: 'Apply to this draft', style: 'btn-primary', busyLabel: 'Applying…', handler: function () { return Api.post('/pfcc/invoices/' + id + '/split', {}).then(function () { toast('🏛️', 'Split applied', '', '#16A34A'); renderPfcc(main); }, function (e) { w.querySelector('.oh-msg').textContent = errMsg(e); return false; }); } });
      KT.Shell.Modal.open({ title: 'State / family split', body: w, actions: actions });
    }); });
  }

  function settingsTab(body, d, main) {
    var s = d.settings;
    var dis = d.is_admin ? '' : ' disabled';
    var h = '<div class="oh-card"><div style="font-weight:800;font-size:15px;margin-bottom:8px;">Limits and alerts</div><div class="oh-g">'
      + '<div><label class="oh-l">Absent days allowed per half-year</label><input class="oh-i" id="s-cap" type="number" min="1" max="60" value="' + esc(s.absent_cap) + '"' + dis + ' style="width:100%;"></div>'
      + '<div><label class="oh-l">Alert after unpaid copay weeks</label><input class="oh-i" id="s-wk" type="number" min="1" max="8" value="' + esc(s.alert_unpaid_weeks) + '"' + dis + ' style="width:100%;"></div>'
      + '<div><label class="oh-l">After a split, the family pays</label><select class="oh-i" id="s-bill"' + dis + ' style="width:100%;">'
      +   '<option value="copay_only"' + (s.family_billing !== 'copay_plus_difference' ? ' selected' : '') + '>Only the copay (the gap is written off)</option>'
      +   '<option value="copay_plus_difference"' + (s.family_billing === 'copay_plus_difference' ? ' selected' : '') + '>The copay plus tuition above what the state pays</option></select></div>'
      + '<div><label class="oh-l">Step Up To Quality rating</label><select class="oh-i" id="s-lvl"' + dis + ' style="width:100%;">' + ['bronze', 'silver', 'gold'].map(function (l) { return '<option value="' + l + '"' + (l === s.sutq_level ? ' selected' : '') + '>' + l.charAt(0).toUpperCase() + l.slice(1) + '</option>'; }).join('') + '</select></div>'
      + '</div><div class="oh-note">Absent days: Ohio sources give 10 and 20 days per half-year for different cases, so this starts at 10. Check it against your authorizations. Half-years run January–June and July–December. Directors and admins get a bell (never an email or text, and nothing to families) when a child is 2 days from the limit, and when a copay has been unpaid for the weeks set here.</div>'
      + (d.ohio ? '' : '<label style="display:flex;gap:8px;align-items:center;font-size:13.5px;margin-top:10px;"><input type="checkbox" id="s-pf"' + (s.pfcc_enabled ? ' checked' : '') + dis + '> Show publicly funded child care for this agency (not in Ohio)</label>'
        + '<label style="display:flex;gap:8px;align-items:center;font-size:13.5px;margin-top:6px;"><input type="checkbox" id="s-sq"' + (s.sutq_enabled ? ' checked' : '') + dis + '> Show Step Up To Quality for this agency</label>')
      + (d.is_admin ? '<div class="oh-row" style="margin-top:10px;"><button class="oh-b p" id="s-save">Save settings</button><span id="s-msg" style="font-size:13px;"></span></div>' : '<div class="oh-note">Only agency admins can change these.</div>')
      + '</div>';
    body.innerHTML = h;
    var sv = body.querySelector('#s-save');
    if (sv) sv.addEventListener('click', function () {
      var p = { absent_cap: +val(body, 's-cap'), alert_unpaid_weeks: +val(body, 's-wk'), sutq_level: val(body, 's-lvl'), family_billing: val(body, 's-bill') };
      if (body.querySelector('#s-pf')) { p.pfcc_enabled = val(body, 's-pf'); p.sutq_enabled = val(body, 's-sq'); }
      Api.patch('/pfcc/settings', p).then(function () { toast('⚙️', 'Settings saved', '', '#16A34A'); probedFor = null; probe(); renderPfcc(main); })
        .catch(function (e) { body.querySelector('#s-msg').textContent = errMsg(e); });
    });
  }

  /* ═════════════════════ #sutq ═════════════════════ */
  var S = { tab: 'timers', centre: '' };

  async function renderSutq(main) {
    css();
    main.innerHTML = '<div class="oh-wrap" style="color:#64748B;">Loading…</div>';
    var d;
    try { d = await Api.get('/sutq' + (S.centre ? '?centre_id=' + S.centre : '')); }
    catch (e) {
      main.innerHTML = '<div class="oh-wrap"><div class="oh-card">' + esc(e && /not switched on/i.test(e.message || '') ? 'Step Up To Quality is shown for agencies in Ohio. An agency admin can switch it on in Ohio PFCC → Limits & alerts.' : errMsg(e)) + '</div></div>';
      return;
    }
    var h = '<div class="oh-wrap"><div class="kt-hero" style="background:linear-gradient(135deg,#1E3A8A 0%,#0E7490 100%);">'
      + '<div class="kt-hero-greet">⭐ STEP UP TO QUALITY</div><h1>Quality rating</h1>'
      + '<div class="kt-hero-sub">Rating: ' + esc(d.level.charAt(0).toUpperCase() + d.level.slice(1)) + ' · curriculum framework: ' + esc(d.framework.name) + '</div></div>'
      + '<div class="oh-row" style="margin-top:14px;">' + centreSelect('sq-cen', d.centres, S.centre, true) + '</div>'
      + tabs([['timers', 'Child timers'], ['refs', 'Referrals'], ['cip', 'Improvement plan'], ['binder', 'Evidence binder']], S.tab) + '<div id="oh-body"></div></div>';
    main.innerHTML = h;
    main.querySelector('#sq-cen').addEventListener('change', function () { S.centre = this.value; renderSutq(main); });
    main.querySelectorAll('[data-tab]').forEach(function (b) { b.addEventListener('click', function () { S.tab = b.getAttribute('data-tab'); renderSutq(main); }); });
    var body = main.querySelector('#oh-body');
    ({ timers: timersTab, refs: refsTab, cip: cipTab, binder: binderTab }[S.tab] || timersTab)(body, d, main);
  }

  function timerCell(t, kind, child) {
    if (!t) return '<td>—</td>';
    var x = pill(t.status) + '<div style="color:#64748B;font-size:12px;margin-top:2px;">' + (t.last ? 'Done ' + fmtDate(t.last) + ' · ' : '') + 'due ' + fmtDate(t.due) + '</div>';
    if (kind === 'screening' && t.last) x += '<div style="font-size:12px;margin-top:2px;">Shared with family: ' + (t.shared_on ? fmtDate(t.shared_on) : pill(t.share_status === 'overdue' ? 'overdue' : 'due', 'by ' + fmtDate(t.share_due))) + (t.shared_on ? '' : ' <button class="oh-b s" data-share="' + t.id + '">Mark shared</button>') + '</div>';
    return '<td>' + x + '<div style="margin-top:4px;"><button class="oh-b s" data-rec="' + kind + '" data-child="' + child + '">Record</button></div></td>';
  }

  function timersTab(body, d, main) {
    var silver = d.level !== 'bronze';
    var h = '<div class="oh-card"><div style="font-weight:800;font-size:15px;margin-bottom:4px;">Per-child timers</div>'
      + '<div class="oh-note" style="margin-bottom:8px;">Screening within 60 days of enrolment then yearly, results shared with the family within 30 days. Family needs assessment within 30 days then yearly.' + (silver ? ' Child assessment at least twice a year.' : ' Child assessments twice a year start at Silver.') + '</div>'
      + (d.children.length ? '<div style="overflow-x:auto;"><table class="oh-t"><thead><tr><th>Child</th><th>Enrolled</th><th>Screening</th><th>Family needs</th>' + (silver ? '<th>Assessment</th>' : '') + '</tr></thead><tbody>'
        + d.children.map(function (c) {
            return '<tr><td><b>' + esc(c.name) + '</b></td><td style="white-space:nowrap;">' + fmtDate(c.start) + '</td>' + timerCell(c.screening, 'screening', c.id) + timerCell(c.family_needs, 'family_needs', c.id) + (silver ? timerCell(c.assessment, 'assessment', c.id) : '') + '</tr>';
          }).join('') + '</tbody></table></div>'
        : '<div style="color:#64748B;font-size:13px;">No enrolled children.</div>') + '</div>';
    body.innerHTML = h;
    var NAMES = { screening: 'Developmental screening', family_needs: 'Family needs assessment', assessment: 'Child assessment' };
    body.querySelectorAll('[data-rec]').forEach(function (b) { b.addEventListener('click', function () {
      var kind = b.getAttribute('data-rec');
      var w = form('<div class="oh-g"><div><label class="oh-l">Completed on</label><input class="oh-i" id="f-on" type="date" value="' + esc(d.today) + '" max="' + esc(d.today) + '" style="width:100%;"></div>'
        + '<div><label class="oh-l">Tool</label><input class="oh-i" id="f-tool" maxlength="120" placeholder="' + (kind === 'screening' ? 'e.g. ASQ-3' : kind === 'assessment' ? 'e.g. COR Advantage' : '') + '" style="width:100%;"></div>'
        + (kind === 'screening' ? '<div><label class="oh-l">Shared with family on</label><input class="oh-i" id="f-sh" type="date" style="width:100%;"></div>' : '') + '</div>'
        + '<label class="oh-l" style="margin-top:10px;">Notes</label><input class="oh-i" id="f-n" maxlength="2000" style="width:100%;"><div class="oh-msg" style="font-size:13px;margin-top:8px;"></div>');
      modal(NAMES[kind], w, 'Save', function () {
        return Api.post('/sutq/events', { child_id: +b.getAttribute('data-child'), kind: kind, completed_on: val(w, 'f-on'), tool: val(w, 'f-tool') || null, shared_with_family_on: val(w, 'f-sh') || null, notes: val(w, 'f-n') || null })
          .then(function () { renderSutq(main); });
      });
    }); });
    body.querySelectorAll('[data-share]').forEach(function (b) { b.addEventListener('click', function () {
      Api.patch('/sutq/events/' + b.getAttribute('data-share'), { shared_with_family_on: d.today }).then(function () { renderSutq(main); }).catch(function (e) { toast('⚠️', 'Could not save', errMsg(e), '#DC2626'); });
    }); });
  }

  function refsTab(body, d, main) {
    var h = '<div class="oh-card"><div class="oh-row" style="margin-bottom:8px;"><div style="font-weight:800;font-size:15px;flex:1;">Referrals</div><button class="oh-b p" id="r-add">Add referral</button></div>'
      + (d.referrals.length ? '<div style="overflow-x:auto;"><table class="oh-t"><thead><tr><th>Child</th><th>Referred</th><th>To</th><th>Reason</th><th>Follow up</th><th>Outcome</th><th></th></tr></thead><tbody>'
        + d.referrals.map(function (r) {
            var fu = r.followed_up_on ? pill('done', 'Done ' + fmtDate(r.followed_up_on)) : r.follow_up_due && r.follow_up_due < d.today ? pill('overdue', 'Due ' + fmtDate(r.follow_up_due)) : pill('due', 'Due ' + fmtDate(r.follow_up_due));
            return '<tr><td>' + esc(r.child) + '</td><td style="white-space:nowrap;">' + fmtDate(r.referred_on) + '</td><td>' + esc(r.referred_to) + '</td><td>' + esc(r.reason || '') + '</td><td>' + fu + '</td><td>' + esc(r.outcome || '') + '</td>'
              + '<td><button class="oh-b s" data-fu="' + r.id + '">Follow-up</button></td></tr>';
          }).join('') + '</tbody></table></div>' : '<div style="color:#64748B;font-size:13px;">No referrals recorded.</div>') + '</div>';
    body.innerHTML = h;
    body.querySelector('#r-add').addEventListener('click', function () {
      var w = form('<div class="oh-g"><div style="grid-column:1/-1;"><label class="oh-l">Child</label><select class="oh-i" id="f-c" style="width:100%;"><option value="">Choose a child</option>' + d.children.map(function (c) { return '<option value="' + c.id + '">' + esc(c.name) + '</option>'; }).join('') + '</select></div>'
        + '<div><label class="oh-l">Referred on</label><input class="oh-i" id="f-on" type="date" value="' + esc(d.today) + '" style="width:100%;"></div>'
        + '<div><label class="oh-l">Referred to</label><input class="oh-i" id="f-to" maxlength="190" placeholder="e.g. Help Me Grow" style="width:100%;"></div>'
        + '<div><label class="oh-l">Follow up by</label><input class="oh-i" id="f-fu" type="date" style="width:100%;"></div></div>'
        + '<label class="oh-l" style="margin-top:10px;">Reason</label><input class="oh-i" id="f-r" maxlength="255" style="width:100%;"><div class="oh-note">Follow-up defaults to 30 days after the referral.</div><div class="oh-msg" style="font-size:13px;margin-top:8px;"></div>');
      modal('Add a referral', w, 'Add', function () {
        if (!val(w, 'f-c') || !val(w, 'f-to')) throw new Error('Choose the child and who they were referred to.');
        return Api.post('/sutq/referrals', { child_id: +val(w, 'f-c'), referred_on: val(w, 'f-on'), referred_to: val(w, 'f-to'), follow_up_due: val(w, 'f-fu') || null, reason: val(w, 'f-r') || null }).then(function () { renderSutq(main); });
      });
    });
    body.querySelectorAll('[data-fu]').forEach(function (b) { b.addEventListener('click', function () {
      var w = form('<label class="oh-l">Followed up on</label><input class="oh-i" id="f-on" type="date" value="' + esc(d.today) + '"><label class="oh-l" style="margin-top:10px;">Outcome</label><input class="oh-i" id="f-o" maxlength="2000" style="width:100%;"><div class="oh-msg" style="font-size:13px;margin-top:8px;"></div>');
      modal('Record the follow-up', w, 'Save', function () { return Api.patch('/sutq/referrals/' + b.getAttribute('data-fu'), { followed_up_on: val(w, 'f-on') || null, outcome: val(w, 'f-o') || null }).then(function () { renderSutq(main); }); });
    }); });
  }

  function cipTab(body, d, main) {
    var byId = {}; d.cip.forEach(function (c) { byId[c.id] = c; });
    var h = '<div class="oh-card"><div class="oh-row" style="margin-bottom:8px;"><div style="font-weight:800;font-size:15px;flex:1;">Continuous improvement plan</div><button class="oh-b p" id="c-add">Add goal</button></div>'
      + (d.cip.length ? '<div style="overflow-x:auto;"><table class="oh-t"><thead><tr><th>Centre</th><th>Goal</th><th>Action</th><th>Owner</th><th>Due</th><th>Status</th><th></th></tr></thead><tbody>'
        + d.cip.map(function (c) {
            var late = c.status !== 'done' && c.due_on && c.due_on < d.today;
            return '<tr><td>' + esc(d.centres[c.centre_id] || '') + '</td><td><b>' + esc(c.goal) + '</b></td><td>' + esc(c.action || '') + '</td><td>' + esc(c.owner || '') + '</td><td style="white-space:nowrap;">' + (late ? pill('overdue', fmtDate(c.due_on)) : fmtDate(c.due_on)) + '</td><td>' + pill(c.status) + '</td>'
              + '<td style="white-space:nowrap;"><button class="oh-b s" data-ce="' + c.id + '">Edit</button> <button class="oh-b s" data-cd="' + c.id + '">Remove</button></td></tr>';
          }).join('') + '</tbody></table></div>' : '<div style="color:#64748B;font-size:13px;">No plan yet. Add the goals from your self-assessment, with an owner and a date for each.</div>') + '</div>';
    body.innerHTML = h;
    function cipForm(c) {
      c = c || {};
      return form('<div class="oh-g">' + (c.id ? '' : '<div><label class="oh-l">Centre</label>' + centreSelect('f-cen', d.centres, S.centre) + '</div>')
        + '<div style="grid-column:1/-1;"><label class="oh-l">Goal</label><input class="oh-i" id="f-g" maxlength="255" value="' + esc(c.goal || '') + '" style="width:100%;"></div>'
        + '<div style="grid-column:1/-1;"><label class="oh-l">Action</label><input class="oh-i" id="f-a" maxlength="2000" value="' + esc(c.action || '') + '" style="width:100%;"></div>'
        + '<div><label class="oh-l">Owner</label><input class="oh-i" id="f-o" maxlength="120" value="' + esc(c.owner || '') + '" style="width:100%;"></div>'
        + '<div><label class="oh-l">Due</label><input class="oh-i" id="f-d" type="date" value="' + esc(c.due_on || '') + '" style="width:100%;"></div>'
        + '<div><label class="oh-l">Status</label><select class="oh-i" id="f-s" style="width:100%;">' + [['planned', 'Planned'], ['in_progress', 'In progress'], ['done', 'Done']].map(function (s) { return '<option value="' + s[0] + '"' + (s[0] === (c.status || 'planned') ? ' selected' : '') + '>' + s[1] + '</option>'; }).join('') + '</select></div>'
        + '</div><div class="oh-msg" style="font-size:13px;margin-top:8px;"></div>');
    }
    function payload(w) { return { goal: val(w, 'f-g'), action: val(w, 'f-a') || null, owner: val(w, 'f-o') || null, due_on: val(w, 'f-d') || null, status: val(w, 'f-s') }; }
    body.querySelector('#c-add').addEventListener('click', function () {
      var w = cipForm();
      modal('Add an improvement goal', w, 'Add', function () { var p = payload(w); if (!p.goal) throw new Error('Enter the goal.'); p.centre_id = +val(w, 'f-cen'); return Api.post('/sutq/cip', p).then(function () { renderSutq(main); }); });
    });
    body.querySelectorAll('[data-ce]').forEach(function (b) { b.addEventListener('click', function () {
      var c = byId[b.getAttribute('data-ce')], w = cipForm(c);
      modal('Edit goal', w, 'Save', function () { return Api.patch('/sutq/cip/' + c.id, payload(w)).then(function () { renderSutq(main); }); });
    }); });
    body.querySelectorAll('[data-cd]').forEach(function (b) { b.addEventListener('click', async function () {
      var ok = KT.confirm ? await KT.confirm({ title: 'Remove this goal?', description: 'It is taken off the plan. The removal is recorded in the audit log.', okLabel: 'Remove' }) : true;
      if (!ok) return;
      Api.delete('/sutq/cip/' + b.getAttribute('data-cd')).then(function () { renderSutq(main); }).catch(function (e) { toast('⚠️', 'Could not remove', errMsg(e), '#DC2626'); });
    }); });
  }

  async function binderTab(body, d) {
    body.innerHTML = '<div class="oh-card">Loading…</div>';
    var b;
    try { b = await Api.get('/sutq/binder' + (S.centre ? '?centre_id=' + S.centre : '')); } catch (e) { body.innerHTML = '<div class="oh-card" style="color:#B91C1C;">' + esc(errMsg(e)) + '</div>'; return; }
    var done = b.items.filter(function (i) { return i.status === 'done'; }).length, of = b.items.filter(function (i) { return i.status !== 'info' && i.status !== 'n/a'; }).length;
    body.innerHTML = '<div class="oh-card"><div class="oh-row" style="margin-bottom:8px;"><div style="font-weight:800;font-size:15px;flex:1;">Rating evidence</div><span style="font-size:13px;color:#475569;">' + done + ' of ' + of + ' in place · ' + esc(b.children) + ' children</span></div>'
      + '<div style="overflow-x:auto;"><table class="oh-t"><thead><tr><th>Area</th><th>Standard</th><th>Status</th><th>Evidence</th><th>How to fix</th></tr></thead><tbody>'
      + b.items.map(function (i) { return '<tr><td>' + esc(i.standard) + '</td><td><b>' + esc(i.label) + '</b></td><td>' + pill(i.status) + '</td><td>' + esc(i.detail) + '</td><td style="color:#64748B;">' + esc(i.hint) + '</td></tr>'; }).join('')
      + '</tbody></table></div><div class="oh-note">This covers the program standards KiddieTrac holds records for. Your rating also looks at things kept outside KiddieTrac (staff qualifications, ratios, the self-assessment), so use this as a checklist rather than your whole binder.</div></div>';
  }

  ['agency_admin', 'platform_admin', 'centre_director'].forEach(function (r) {
    KT.Shell.registerScreen(r + ':pfcc', renderPfcc);
    KT.Shell.registerScreen(r + ':sutq', renderSutq);
  });
})(window);
