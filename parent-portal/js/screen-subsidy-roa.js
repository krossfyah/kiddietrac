/* ═══════════════════════════════════════════════════════════════════════
   OCCMS reporting (#subsidy-roa) — 2026-10-13

   Ontario operators contracted for fee subsidy enter a Record of Attendance (ROA) in OCCMS
   every month (York: by the 8th of the following month): a code per day for each child with
   a fee subsidy, plus licensed / operating capacity, enrolment and vacancies by age group.
   There is no OCCMS API, so this is the working copy: each day is worked out from check-ins
   (P), reported absences (S sick, V holiday, A other), closures (H holiday, N other) and the
   child's schedule; staff correct any day, one person marks it reviewed, a SECOND person signs
   it off, and someone records the date it was entered in OCCMS. Then it is locked (Amend, with
   a reason, reopens it). Tab 2 is the CWELCC monthly summary for the same month.

   Admins (every centre) and directors (their own). Ontario agencies only: the menu item is
   hidden unless GET /occms/status says on.
   ═══════════════════════════════════════════════════════════════════════ */
(function (window) {
  'use strict';
  var KT = window.KT;
  if (!KT || !KT.Shell || !KT.Shell.registerScreen) return;
  var Api = KT.Api;

  function esc(s) { return String(s == null ? '' : s).replace(/[&<>"]/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]; }); }
  var MON = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
  var MONTHS = ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'];
  // A bare YYYY-MM-DD is a calendar day, formatted from its own parts (never new Date()).
  function day(d) { var m = /^(\d{4})-(\d{2})-(\d{2})/.exec(d || ''); return m ? MON[+m[2] - 1] + ' ' + (+m[3]) + ', ' + m[1] : ''; }
  function monthLabel(ym) { var m = /^(\d{4})-(\d{2})$/.exec(ym || ''); return m ? MONTHS[+m[2] - 1] + ' ' + m[1] : ym; }
  function toast(i, t, m, c) { try { KT.toast && KT.toast(i, t, m || '', c); } catch (e) {} }
  function hashParam(k) { var m = (location.hash.split('?')[1] || '').match(new RegExp('(?:^|&)' + k + '=([^&]+)')); return m ? decodeURIComponent(m[1]) : ''; }
  function apiBase() { return (window.KT_CONFIG && window.KT_CONFIG.apiBase) || KT.API_BASE || 'https://api.kiddietrac.com/api/v1'; }

  /* ── menu: hidden unless this is an Ontario agency and the person is a director or admin ── */
  (function () {
    var st = document.createElement('style');
    st.textContent = 'body:not(.kt-roa-on) [data-hash="subsidy-roa"], body:not(.kt-roa-on) [href="#subsidy-roa"] { display:none !important; }';
    (document.head || document.documentElement).appendChild(st);
  })();
  var probedFor = null;
  function probe() {
    if (!Api || !Api.get || !document.body) return;
    var tok = sessionStorage.getItem('kt_token') || localStorage.getItem('kt_token');
    if (!tok) return;
    var bc = document.body.classList;
    if (!/\brole-/.test(document.body.className || '')) return;   // still booting
    var ag = sessionStorage.getItem('kt_active_agency_id') || '';
    if (probedFor === ag) return;
    if (!(bc.contains('role-agency-admin') || bc.contains('role-centre-director') || bc.contains('role-platform-admin'))) { probedFor = ag; bc.remove('kt-roa-on'); return; }
    if (!ag) {
      try { var u = JSON.parse(sessionStorage.getItem('kt_user') || localStorage.getItem('kt_user') || '{}'); if (Array.isArray(u.roles) && u.roles.indexOf('platform_admin') !== -1) return; } catch (e) {}
    }
    probedFor = ag;
    var key = 'kt_roa_on_' + ag;
    try { var c = sessionStorage.getItem(key); if (c === '1' || c === '0') { bc.toggle('kt-roa-on', c === '1'); return; } } catch (e) {}
    Api.get('/occms/status').then(function (d) {
      var on = !!(d && d.on);
      bc.toggle('kt-roa-on', on);
      try { sessionStorage.setItem(key, on ? '1' : '0'); } catch (e) {}
    }).catch(function () { bc.remove('kt-roa-on'); });
  }
  setTimeout(probe, 2500); setTimeout(probe, 8000);
  window.addEventListener('kt:agency-changed', function () { probedFor = null; probe(); });
  window.addEventListener('kt:jurisdiction', function () { probedFor = null; try { sessionStorage.removeItem('kt_roa_on_' + (sessionStorage.getItem('kt_active_agency_id') || '')); } catch (e) {} probe(); });

  // Code colours: [background, text]
  var TONE = { P: ['#DCFCE7', '#166534'], S: ['#FEF3C7', '#92400E'], A: ['#FEE2E2', '#991B1B'], V: ['#DBEAFE', '#1E40AF'], W: ['#E2E8F0', '#0F172A'],
    H: ['#EDE9FE', '#5B21B6'], N: ['#F1F5F9', '#475569'], O: ['#F1F5F9', '#334155'] };
  var STATUS = { draft: ['#F1F5F9', '#475569', 'Draft'], reviewed: ['#FEF3C7', '#92400E', 'Reviewed — waiting for sign-off'], signed_off: ['#DBEAFE', '#1E40AF', 'Signed off'], submitted: ['#DCFCE7', '#166534', 'Submitted to OCCMS'] };

  var CSS_ID = 'kt-roa-css';
  function css() {
    if (document.getElementById(CSS_ID)) return;
    var s = document.createElement('style'); s.id = CSS_ID;
    s.textContent = '.roa-wrap{padding:20px 24px;max-width:1900px;margin:0 auto;}'
      + '.roa-card{background:#fff;border:1px solid #E2E8F0;border-radius:12px;padding:14px 16px;margin-top:14px;}'
      + '.roa-row{display:flex;gap:8px;align-items:center;flex-wrap:wrap;}'
      + '.roa-i{height:30px;padding:0 9px;border:1px solid #CBD5E1;border-radius:8px;font-size:13px;background:#fff;box-sizing:border-box;}'
      + '.roa-b{height:30px;padding:0 12px;border-radius:8px;border:1px solid #CBD5E1;background:#fff;font-weight:700;font-size:13px;cursor:pointer;white-space:nowrap;}'
      + '.roa-b.p{background:#1F6080;border-color:#1F6080;color:#fff;}.roa-b:disabled{opacity:.55;cursor:not-allowed;}'
      + '.roa-chip{display:inline-flex;align-items:center;gap:6px;padding:3px 10px;border-radius:999px;font-size:12px;font-weight:700;}'
      + '.roa-tabs{display:flex;gap:6px;flex-wrap:wrap;margin-top:14px;}'
      + '.roa-tab{height:30px;padding:0 14px;border-radius:999px;border:1px solid #CBD5E1;background:#fff;font-weight:700;font-size:13px;cursor:pointer;}'
      + '.roa-tab.on{background:#0F172A;border-color:#0F172A;color:#fff;}'
      + '.roa-steps{display:grid;grid-template-columns:repeat(auto-fit,minmax(170px,1fr));gap:8px;margin-top:10px;}'
      + '.roa-step{border:1px solid #E2E8F0;border-radius:10px;padding:8px 10px;font-size:12.5px;color:#475569;}'
      + '.roa-step b{display:block;color:#0F172A;font-size:13px;}.roa-step.done{border-color:#86EFAC;background:#F0FDF4;}'
      + '.roa-scroll{overflow-x:auto;border:1px solid #E2E8F0;border-radius:10px;}'
      // overflow:visible — the house table style clips (overflow:hidden), which pins the sticky name column to the table.
      + '.roa-g{border-collapse:separate;border-spacing:0;font-size:12px;overflow:visible !important;}'
      + '.roa-g th,.roa-g td{border-bottom:1px solid #EEF2F6;border-right:1px solid #F1F5F9;padding:0;text-align:center;height:28px;}'
      + '.roa-g tr.hd th{background:#F8FAFC;color:#475569;font-size:10.5px;font-weight:700;line-height:1.15;padding:3px 0;position:sticky;top:0;z-index:1;}'
      + '.roa-g .nm{position:sticky;left:0;background:#fff;z-index:1;text-align:left;padding:3px 10px;min-width:170px;max-width:220px;border-right:1px solid #E2E8F0;}'
      + '.roa-g tr.hd .nm{background:#F8FAFC;z-index:3;}'
      + '.roa-g .dc{width:30px;min-width:30px;}.roa-g .we{background:#F8FAFC;}.roa-g .cl{background:#FAF5FF;}'
      + '.roa-g .tot{min-width:40px;padding:0 6px;font-weight:700;color:#0F172A;}'
      /* The visible code is a span; a transparent native <select> lies over the cell, so the
         platform's select styling can never hide the code and phones still get kt-select-sheet. */
      + '.roa-g td.edt{position:relative;cursor:pointer;}'
      + '.roa-g td.edt:hover .roa-code{box-shadow:0 0 0 1px #94A3B8;}.roa-g td.edt:focus-within .roa-code{box-shadow:0 0 0 2px #1F6080;}'
      + '.roa-g select.roa-sel{position:absolute !important;inset:0 !important;width:100% !important;height:100% !important;min-width:0 !important;opacity:0 !important;margin:0 !important;padding:0 !important;cursor:pointer;border:0 !important;}'
      + '.roa-code.ed{box-shadow:inset 0 -3px 0 #F59E0B !important;}.roa-code.ux{outline:2px solid #DC2626;outline-offset:-2px;}.roa-code.none{color:#CBD5E1;}'
      + '.roa-code{display:inline-block;min-width:22px;height:22px;line-height:22px;border-radius:6px;font-weight:800;}'
      + '.roa-sub{font-size:11px;color:#64748B;font-weight:500;}'
      + '.roa-t{width:100%;border-collapse:collapse;font-size:13px;}'
      + '.roa-t th,.roa-t td{padding:7px 8px;border-top:1px solid #EEF2F6;text-align:left;}'
      + '.roa-t th{background:#F8FAFC;color:#475569;font-size:11px;text-transform:uppercase;letter-spacing:.4px;border-top:0;}'
      + '.roa-t td.n,.roa-t th.n{text-align:right;}'
      + '.roa-l{display:block;font-size:11.5px;font-weight:800;color:#334155;text-transform:uppercase;letter-spacing:.4px;margin-bottom:4px;}'
      + '.roa-f{display:grid;grid-template-columns:repeat(auto-fit,minmax(190px,1fr));gap:10px;}'
      + '.roa-leg{display:flex;gap:6px 12px;flex-wrap:wrap;font-size:12px;color:#475569;margin-top:8px;}'
      + '@media (max-width:640px){.roa-g .nm{min-width:120px;max-width:140px;}}';
    document.head.appendChild(s);
  }

  var state = { tab: 'roa', centre: '', month: '', data: null };

  async function download(path, filename) {
    var tok = sessionStorage.getItem('kt_token') || localStorage.getItem('kt_token');
    var headers = { Authorization: 'Bearer ' + tok };
    var aid = sessionStorage.getItem('kt_active_agency_id');
    if (aid) headers['X-Active-Agency-Id'] = aid;
    try {
      var r = await fetch(apiBase() + path, { headers: headers });
      if (!r.ok) {
        var msg = 'Download failed (' + r.status + ')';
        try { var j = await r.json(); if (j && j.message) msg = j.message; } catch (e) {}
        toast('⚠️', 'Could not download', msg, '#B91C1C'); return;
      }
      var blob = await r.blob();
      var a = document.createElement('a'); a.href = URL.createObjectURL(blob); a.download = filename; document.body.appendChild(a); a.click();
      setTimeout(function () { URL.revokeObjectURL(a.href); a.remove(); }, 4000);
    } catch (e) { toast('⚠️', 'Could not download', (e && e.message) || '', '#B91C1C'); }
  }

  function query() {
    var q = [];
    if (state.centre) q.push('centre_id=' + encodeURIComponent(state.centre));
    if (state.month) q.push('month=' + encodeURIComponent(state.month));
    return q.length ? '?' + q.join('&') : '';
  }

  async function render(main) {
    css();
    if (!state.month) state.month = hashParam('month') || '';
    if (!state.centre) state.centre = hashParam('centre') || '';
    main.innerHTML = '<div class="roa-wrap" style="color:#64748B;">Loading the Record of Attendance…</div>';
    var d;
    try { d = await Api.get('/occms/roa' + query()); }
    catch (e) { main.innerHTML = '<div class="roa-wrap"><div class="roa-card" style="color:#B91C1C;">' + esc((e && e.message) || 'Could not load.') + '</div></div>'; return; }
    if (!main.isConnected) return;
    state.data = d; state.month = d.month; state.centre = String(d.centre.id);

    var h = '<div class="roa-wrap">'
      + '<div class="kt-hero" style="background:linear-gradient(135deg,#0C4A6E 0%,#0369A1 55%,#0EA5E9 100%);">'
      + '<div class="kt-hero-greet">🧾 OCCMS</div><h1>OCCMS reporting</h1>'
      + '<div class="kt-hero-sub">The fee subsidy Record of Attendance for each centre and month, worked out from attendance, absences and closures, ready to enter in OCCMS. '
      + 'Due by the ' + esc(ordinal(d.settings.due_day)) + ' of the following month. ' + esc(d.settings.label) + ' rules.</div></div>'
      + '<div class="roa-card"><div class="roa-row">'
      + '<select class="roa-i" id="roa-c" aria-label="Centre">' + d.centres.map(function (c) { return '<option value="' + c.id + '"' + (String(c.id) === state.centre ? ' selected' : '') + '>' + esc(c.name) + '</option>'; }).join('') + '</select>'
      + '<input class="roa-i" id="roa-m" type="month" value="' + esc(d.month) + '" max="' + esc(d.today.slice(0, 7)) + '" aria-label="Month">'
      + '<span style="flex:1"></span>'
      // Worded labels + data-kt-iconized: the platform strips bare "⬇ CSV" buttons and icon-ifies "print".
      + '<button class="roa-b" id="roa-csv" type="button" data-kt-iconized="1">ROA as CSV</button><button class="roa-b" id="roa-pdf" type="button" data-kt-iconized="1">Printable ROA (PDF)</button>'
      + '</div><div class="roa-tabs">'
      + tabBtn('roa', 'Record of Attendance') + tabBtn('cwelcc', 'CWELCC summary') + (d.can_settings ? tabBtn('settings', 'Settings') : '')
      + '</div></div><div id="roa-pane"></div></div>';
    main.innerHTML = h;
    var $ = function (id) { return main.querySelector('#' + id); };
    $('roa-c').addEventListener('change', function () { state.centre = this.value; render(main); });
    $('roa-m').addEventListener('change', function () { if (this.value) { state.month = this.value; render(main); } });
    $('roa-csv').addEventListener('click', function () {
      if (state.tab === 'cwelcc') download('/occms/cwelcc/csv' + query(), 'CWELCC-summary-' + state.month + '.csv');
      else download('/occms/roa/csv' + query(), 'ROA-' + slug(d.centre.name) + '-' + state.month + '.csv');
    });
    $('roa-pdf').addEventListener('click', function () { download('/occms/roa/pdf' + query(), 'ROA-' + slug(d.centre.name) + '-' + state.month + '.pdf'); });
    main.querySelectorAll('[data-tab]').forEach(function (b) {
      b.addEventListener('click', function () { state.tab = b.getAttribute('data-tab'); main.querySelectorAll('[data-tab]').forEach(function (x) { x.classList.toggle('on', x === b); }); pane(main); });
    });
    pane(main);
  }
  function tabBtn(k, label) { return '<button type="button" class="roa-tab' + (state.tab === k ? ' on' : '') + '" data-tab="' + k + '" data-kt-iconized="1">' + esc(label) + '</button>'; }
  function slug(s) { return String(s || '').replace(/[^A-Za-z0-9]+/g, '-').replace(/^-|-$/g, ''); }
  function ordinal(n) { n = +n; var s = ['th', 'st', 'nd', 'rd'], v = n % 100; return n + (s[(v - 20) % 10] || s[v] || s[0]); }

  function pane(main) {
    var el = main.querySelector('#roa-pane'); if (!el) return;
    var pdf = main.querySelector('#roa-pdf'); if (pdf) pdf.style.display = state.tab === 'roa' ? '' : 'none';
    var csv = main.querySelector('#roa-csv'); if (csv) { csv.style.display = state.tab === 'settings' ? 'none' : ''; csv.textContent = state.tab === 'cwelcc' ? 'CWELCC summary as CSV' : 'ROA as CSV'; }
    if (state.tab === 'cwelcc') return paneCwelcc(main, el);
    if (state.tab === 'settings' && state.data.can_settings) return paneSettings(main, el);
    state.tab = 'roa';
    paneRoa(main, el);
  }

  // ── Record of Attendance ──────────────────────────────────────────────────────
  function paneRoa(main, el) {
    var d = state.data, r = d.roa, st = STATUS[r.status] || STATUS.draft;
    var dueOver = d.today > d.due_on && r.status !== 'submitted';
    var h = '<div class="roa-card"><div class="roa-row" style="justify-content:space-between;">'
      + '<div><div style="font-weight:800;font-size:16px;">' + esc(d.centre.name) + ' · ' + esc(monthLabel(d.month)) + '</div>'
      + '<div style="font-size:12.5px;color:#64748B;">' + (d.centre.license_number ? 'Licence ' + esc(d.centre.license_number) + ' · ' : '') + d.children.length + ' child' + (d.children.length === 1 ? '' : 'ren') + ' with a fee subsidy · '
      + '<span style="color:' + (dueOver ? '#B91C1C;font-weight:700' : '#334155') + '">due in OCCMS by ' + esc(day(d.due_on)) + (dueOver ? ' (overdue)' : '') + '</span></div></div>'
      + '<span class="roa-chip" data-kt-no-tone style="background:' + st[0] + ';color:' + st[1] + ';">' + esc(st[2]) + (r.amendments ? ' · amended ' + r.amendments + '×' : '') + '</span></div>'
      + '<div class="roa-steps">'
      + step('1. Check the days', r.status !== 'draft' || false, 'Correct any day below. Changes are kept in the history.')
      + step('2. Reviewed', !!r.reviewed_by, r.reviewed_by ? esc(r.reviewed_by) + '<br>' + esc(r.reviewed_at) : 'Person 1')
      + step('3. Signed off', !!r.signed_off_by, r.signed_off_by ? esc(r.signed_off_by) + '<br>' + esc(r.signed_off_at) : 'Person 2 — not the reviewer')
      + step('4. Entered in OCCMS', r.status === 'submitted', r.occms_entered_on ? esc(day(r.occms_entered_on)) + (r.occms_reference ? ' · ref ' + esc(r.occms_reference) : '') + '<br>recorded by ' + esc(r.submitted_by || '') : 'Record the date you entered it')
      + '</div><div class="roa-row" style="margin-top:10px;">' + actions(d) + '<span id="roa-msg" style="font-size:12.5px;color:#64748B;"></span></div>'
      + (r.frozen && r.live_diff ? '<div style="margin-top:8px;font-size:12.5px;color:#B45309;">⚠️ ' + r.live_diff + ' day' + (r.live_diff === 1 ? '' : 's') + ' in attendance changed after sign-off. The grid shows what was signed. Reopen or amend to bring them in.</div>' : '')
      + (!d.month_over ? '<div style="margin-top:8px;font-size:12.5px;color:#64748B;">This month is still running. Days fill in as they happen; review and sign-off open on the 1st.</div>' : '')
      + '</div>';

    // flags
    var flags = [];
    d.children.forEach(function (c) {
      (c.runs || []).forEach(function (run) {
        flags.push(run.type === 'unexplained'
          ? '<li><b>' + esc(c.name) + '</b>: absent ' + run.days + ' days in a row without notice (' + esc(day(run.from)) + ' – ' + esc(day(run.to)) + '). Tell the family\'s caseworker (CSR).</li>'
          : '<li><b>' + esc(c.name) + '</b>: ' + run.days + ' absence days in a row (' + esc(day(run.from)) + ' – ' + esc(day(run.to)) + '), over the ' + d.settings.max_consecutive + '-day limit.</li>');
      });
      if (c.warn === 'over') flags.push('<li><b>' + esc(c.name) + '</b>: absence allowance used up (' + c.ytd_used + ' of ' + c.allowance + ' this year). The subsidy does not pay further absence days; the family pays them.</li>');
      else if (c.warn === 'near') flags.push('<li><b>' + esc(c.name) + '</b>: ' + c.ytd_used + ' of ' + c.allowance + ' absence days used this year (' + c.remaining + ' left).</li>');
    });
    if (flags.length) h += '<div class="roa-card" style="border-color:#FCD34D;background:#FFFBEB;"><div style="font-weight:800;font-size:14px;margin-bottom:4px;">Needs attention</div><ul style="margin:0;padding-left:18px;font-size:13px;line-height:1.6;">' + flags.join('') + '</ul></div>';

    h += '<div class="roa-card"><div class="roa-row" style="justify-content:space-between;margin-bottom:8px;"><div style="font-weight:800;font-size:15px;">Days</div>'
      + '<div style="font-size:12px;color:#64748B;">' + (r.locked ? 'Locked — ' + (r.status === 'submitted' ? 'amend' : 'reopen') + ' to change.' : 'Pick a code to change a day. Orange underline = changed by hand; red outline = absent with no notice.') + '</div></div>'
      + '<div id="roa-grid"></div>'
      + '<div class="roa-leg">' + Object.keys(d.codes).map(function (k) { var t = TONE[k] || TONE.O; return '<span><span class="roa-code" style="background:' + t[0] + ';color:' + t[1] + ';">' + k + '</span> ' + esc(d.codes[k]) + '</span>'; }).join('')
      + '<span><span class="roa-code" style="color:#CBD5E1;">·</span> no fee subsidy that day</span></div>'
      + '<div style="font-size:12px;color:#64748B;margin-top:6px;">Worked out from sign-ins (P), absences reported by families or staff (S sick, V holiday, A anything else), closures (H holiday, N other) and each child\'s schedule. '
      + 'Absence allowance: ' + (d.settings.allowance == null ? '<b>not set</b> — an admin can enter it under Settings' : d.settings.allowance + ' sick, absent and vacation days a calendar year' + (d.settings.prorate_step ? ', ' + d.settings.prorate_step + ' fewer for each month after January the subsidy starts' : '')) + '.</div></div>';

    // capacity
    h += '<div class="roa-card" id="roa-cap"></div>';
    // history
    h += '<div class="roa-card"><details><summary style="cursor:pointer;font-weight:800;font-size:14px;">History (' + d.events.length + ')</summary>'
      + (d.events.length ? '<div style="overflow-x:auto;margin-top:8px;"><table class="roa-t" data-kt-no-filter data-kt-no-bulk data-kt-noexport><thead><tr><th>When</th><th>Who</th><th>What</th></tr></thead><tbody>'
        + d.events.map(function (e) { return '<tr><td style="white-space:nowrap;">' + esc(e.at) + '</td><td>' + esc(e.by) + '</td><td>' + esc(eventText(e)) + '</td></tr>'; }).join('') + '</tbody></table></div>'
        : '<div style="font-size:13px;color:#64748B;margin-top:6px;">Nothing changed yet.</div>')
      + '</details></div>';
    el.innerHTML = h;
    grid(main);
    capacity(main);
    wireActions(main);
  }
  function step(t, done, sub) { return '<div class="roa-step' + (done ? ' done' : '') + '"><b>' + (done ? '✓ ' : '') + esc(t) + '</b>' + sub + '</div>'; }
  function eventText(e) {
    switch (e.action) {
      case 'cell': return (e.child || 'Child') + ', ' + day(e.day) + ': ' + (e.old || '—') + ' → ' + (e.new || '—') + (e.note ? ' (' + e.note + ')' : '');
      case 'review': return 'Marked reviewed';
      case 'signoff': return 'Signed off';
      case 'submit': return e.note || 'Recorded as entered in OCCMS';
      case 'reopen': return 'Reopened: ' + (e.note || '');
      case 'amend': return 'Amendment started: ' + (e.note || '');
      case 'review_cleared': return 'Review cleared — a day changed after review';
      case 'capacity': return e.note || 'Operating capacity changed';
      default: return e.action + (e.note ? ': ' + e.note : '');
    }
  }
  function actions(d) {
    var r = d.roa, b = [];
    if (r.status === 'draft') b.push('<button class="roa-b p" type="button" data-act="review"' + (d.month_over ? '' : ' disabled title="Opens on the 1st of next month"') + '>Mark reviewed</button>');
    if (r.status === 'reviewed') {
      var mine = String(r.reviewed_by_id) === String(d.me);
      b.push('<button class="roa-b p" type="button" data-act="signoff"' + (mine ? ' disabled title="A different person must sign off"' : '') + '>Sign off</button>');
      if (mine) b.push('<span style="font-size:12.5px;color:#B45309;">You reviewed it — a second person signs off.</span>');
      b.push('<button class="roa-b" type="button" data-act="reopen">Reopen</button>');
    }
    if (r.status === 'signed_off') { b.push('<button class="roa-b p" type="button" data-act="submit">Record OCCMS submission</button>'); b.push('<button class="roa-b" type="button" data-act="reopen">Reopen</button>'); }
    if (r.status === 'submitted') b.push('<button class="roa-b" type="button" data-act="amend">Amend</button>');
    return b.join('');
  }
  function wireActions(main) {
    main.querySelectorAll('[data-act]').forEach(function (btn) {
      btn.addEventListener('click', async function () {
        var act = btn.getAttribute('data-act'), body = { centre_id: +state.centre, month: state.month, action: act };
        if (act === 'review') {
          if (!(await KT.confirm({ title: 'Mark ' + monthLabel(state.month) + ' reviewed?', description: 'You checked every child\'s days and the capacity. A different person then signs it off.', okLabel: 'Mark reviewed' }))) return;
        } else if (act === 'signoff') {
          if (!(await KT.confirm({ title: 'Sign off ' + monthLabel(state.month) + '?', description: 'You are authorizing this Record of Attendance. It is then frozen as signed, ready to enter in OCCMS.', okLabel: 'Sign off' }))) return;
        } else if (act === 'submit') {
          var v = await KT.prompt({ title: 'Entered in OCCMS', description: 'The date you entered this Record of Attendance in OCCMS. The month is then locked.', okLabel: 'Record',
            fields: [{ key: 'date', label: 'Date entered', type: 'date', value: state.data.today }, { key: 'reference', label: 'Reference (optional)', placeholder: 'Confirmation or batch number' }] });
          if (!v) return;
          body.occms_date = v.date; body.reference = (v.reference || '').trim() || null;
        } else {
          var reason = await KT.prompt({ title: act === 'amend' ? 'Amend a submitted month' : 'Reopen this month', okLabel: act === 'amend' ? 'Amend' : 'Reopen',
            description: act === 'amend' ? 'It goes back to draft. Correct it, review and sign off again, then enter the correction in OCCMS.' : 'It goes back to draft and needs review and sign-off again.',
            placeholder: 'Reason' });
          if (reason == null) return;
          if (!String(reason).trim()) { toast('⚠️', 'A reason is needed', '', '#DC2626'); return; }
          body.reason = String(reason).trim();
        }
        btn.disabled = true;
        try { await Api.post('/occms/roa/status', body); toast('🧾', 'Saved', '', '#16A34A'); render(main); }
        catch (e) { btn.disabled = false; toast('⚠️', 'Could not save', (e && e.message) || '', '#DC2626'); }
      });
    });
  }

  function grid(main) {
    var box = main.querySelector('#roa-grid'); if (!box) return;
    var d = state.data, locked = d.roa.locked, keepLeft = 0;
    var old = box.querySelector('.roa-scroll'); if (old) keepLeft = old.scrollLeft;
    if (!d.children.length) { box.innerHTML = '<div style="font-size:13px;color:#64748B;">No child had a fee subsidy at this centre in ' + esc(monthLabel(d.month)) + '. Fee subsidies are added under Compliance → Subsidies → Provincial subsidy.</div>'; return; }
    var codes = Object.keys(d.codes);
    /* A LAYOUT, not a list of records (CONVENTIONS "Not every table is a list of records"): no
       <thead>, so the table sort / export bar / phone cards leave it alone; the header row is
       the first body row and sticks on its own. */
    var h = '<div class="roa-scroll kt-no-mcards" data-kt-allow-overflow><table class="roa-g" data-kt-no-filter data-kt-no-bulk data-kt-noexport data-kt-no-kebab><tbody><tr class="hd"><th class="nm">Child</th>';
    d.days.forEach(function (x) {
      h += '<th class="dc' + (x.operating ? '' : ' we') + (x.closure ? ' cl' : '') + '"' + (x.closure ? ' title="' + esc(x.closure.reason) + '"' : '') + '>' + x.d + '<br>' + esc(x.dow) + '</th>';
    });
    h += '<th class="tot" title="Present">P</th><th class="tot" title="Sick + absent + vacation this month">Abs</th><th class="tot" title="Absences used this calendar year, of the allowance">Year</th><th class="tot" title="Allowance left">Left</th></tr>';
    d.children.forEach(function (c) {
      var flag = (c.runs || []).length ? ' <span title="Absent without notice" style="color:#DC2626;">●</span>' : '';
      h += '<tr><td class="nm"><div style="font-weight:700;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">' + esc(c.name) + flag + '</div>'
        + '<div class="roa-sub">' + esc([c.case_number ? '#' + c.case_number : 'no case number', d.age_groups[c.age_group] || ''].filter(Boolean).join(' · ')) + '</div></td>';
      c.codes.forEach(function (code, i) {
        var x = d.days[i], src = (c.src || [])[i] || '', sub = !c.sub || c.sub[i];
        var cls = 'dc' + (x.operating ? '' : ' we') + (x.closure ? ' cl' : '');
        if (!sub) { h += '<td class="' + cls + '" style="color:#CBD5E1;">·</td>'; return; }
        var t = code ? (TONE[code] || TONE.O) : null;
        var chip = '<span class="roa-code' + (code ? '' : ' none') + (src === 'edited' ? ' ed' : '') + (src === 'unexplained' ? ' ux' : '') + '" data-kt-no-tone'
          + (t ? ' style="background:' + t[0] + ';color:' + t[1] + ';"' : '') + '>' + (code || (x.future ? '' : '–')) + '</span>';
        if (locked || x.future) { h += '<td class="' + cls + '" title="' + esc(srcText(src, x)) + '">' + (code ? chip : '') + '</td>'; return; }
        h += '<td class="' + cls + ' edt" title="' + esc(srcText(src, x)) + '">' + chip + '<select class="roa-sel" data-c="' + c.child_id + '" data-i="' + i + '" aria-label="' + esc(c.name + ', ' + x.date) + '">'
          + '<option value=""' + (code ? '' : ' selected') + '>– (no care)</option>'
          + codes.map(function (k) { return '<option value="' + k + '"' + (k === code ? ' selected' : '') + '>' + k + ' — ' + esc(d.codes[k]) + '</option>'; }).join('') + '</select></td>';
      });
      var warn = c.warn === 'over' ? 'color:#B91C1C;' : (c.warn === 'near' ? 'color:#B45309;' : '');
      h += '<td class="tot">' + (c.totals.P || 0) + '</td><td class="tot">' + c.absences_month + '</td>'
        + '<td class="tot" style="' + warn + '">' + c.ytd_used + (c.allowance != null ? '<span class="roa-sub">/' + c.allowance + '</span>' : '') + '</td>'
        + '<td class="tot" style="' + warn + '">' + (c.remaining != null ? c.remaining : '—') + '</td></tr>';
    });
    h += '</tbody></table></div>';
    box.innerHTML = h;
    var sc = box.querySelector('.roa-scroll'); if (sc && keepLeft) sc.scrollLeft = keepLeft;
    box.querySelectorAll('.roa-sel').forEach(function (sel) {
      var prev = sel.value;
      sel.addEventListener('change', async function () {
        var cid = +sel.getAttribute('data-c'), i = +sel.getAttribute('data-i');
        var x = state.data.days[i]; if (!x) return;
        sel.disabled = true;
        try {
          var res = await Api.patch('/occms/roa/cell', { centre_id: +state.centre, month: state.month, child_id: cid, day: x.date, code: sel.value || null });
          if (res && res.review_cleared) { toast('🧾', 'Review cleared', 'A day changed after review, so it needs reviewing again.', '#B45309'); render(main); return; }
          if (res && res.child) {
            state.data.children = state.data.children.map(function (c) { return c.child_id === cid ? Object.assign({}, c, res.child) : c; });
            grid(main);
          }
        } catch (e) {
          sel.disabled = false; sel.value = prev;
          toast('⚠️', 'Could not change the day', (e && e.message) || '', '#DC2626');
        }
      });
    });
  }
  function srcText(src, x) {
    return ({ present: 'Signed in', reported: 'Absence reported', unexplained: 'Absent — nothing reported', closure: 'Closed: ' + (x.closure ? x.closure.reason : ''),
      withdrawal: 'Withdrawal', edited: 'Changed by hand', off: 'Not a care day', signed: 'As signed off' })[src] || '';
  }

  function capacity(main) {
    var box = main.querySelector('#roa-cap'); if (!box) return;
    var d = state.data, cap = d.capacity || { rows: [] }, locked = d.roa.locked;
    var h = '<div class="roa-row" style="justify-content:space-between;margin-bottom:6px;"><div style="font-weight:800;font-size:15px;">Capacity and vacancies</div>'
      + '<div style="font-size:12px;color:#64748B;">Enrolment as of ' + esc(day(cap.as_of)) + (d.centre.license_capacity ? ' · licence total ' + d.centre.license_capacity : '') + '</div></div>';
    if (!cap.rows.length) { box.innerHTML = h + '<div style="font-size:13px;color:#64748B;">No rooms with an age group at this centre yet.</div>'; return; }
    h += '<div style="overflow-x:auto;"><table class="roa-t" data-kt-no-filter data-kt-no-bulk data-kt-noexport><thead><tr><th>Age group</th><th class="n">Licensed</th><th class="n">Operating</th><th class="n">Enrolled</th><th class="n">Fee subsidy</th><th class="n">Vacancies</th></tr></thead><tbody>'
      + cap.rows.map(function (r) {
        return '<tr><td>' + esc(r.label) + '</td><td class="n">' + r.licensed + '</td><td class="n">'
          + (locked ? r.operating : '<input class="roa-i" type="number" min="0" max="' + r.licensed + '" data-g="' + r.group + '" value="' + r.operating + '" style="width:72px;text-align:right;">')
          + (r.operating_set ? '' : '<div class="roa-sub">= licensed</div>') + '</td><td class="n">' + r.enrolled + '</td><td class="n">' + r.subsidized + '</td><td class="n" style="font-weight:700;">' + r.vacancies + '</td></tr>';
      }).join('') + '</tbody></table></div>'
      + '<div style="font-size:12px;color:#64748B;margin-top:6px;">Operating capacity is the spaces you can fill with the staff you have (never more than licensed). It carries forward to next month.</div>'
      + (locked ? '' : '<div class="roa-row" style="margin-top:8px;"><button class="roa-b" type="button" id="roa-cap-save">Save operating capacity</button></div>');
    box.innerHTML = h;
    var btn = box.querySelector('#roa-cap-save');
    if (btn) btn.addEventListener('click', async function () {
      var op = {};
      box.querySelectorAll('input[data-g]').forEach(function (i) { if (i.value !== '') op[i.getAttribute('data-g')] = +i.value; });
      btn.disabled = true;
      try { await Api.post('/occms/roa/capacity', { centre_id: +state.centre, month: state.month, operating: op }); toast('🧾', 'Capacity saved', '', '#16A34A'); render(main); }
      catch (e) { btn.disabled = false; toast('⚠️', 'Could not save', (e && e.message) || '', '#DC2626'); }
    });
  }

  // ── CWELCC summary ────────────────────────────────────────────────────────────
  async function paneCwelcc(main, el) {
    el.innerHTML = '<div class="roa-card" style="color:#64748B;">Loading…</div>';
    var c;
    try { c = await Api.get('/occms/cwelcc' + query()); }
    catch (e) { el.innerHTML = '<div class="roa-card" style="color:#B91C1C;">' + esc((e && e.message) || 'Could not load.') + '</div>'; return; }
    if (!el.isConnected) return;
    var h = '<div class="roa-card"><div class="roa-row" style="justify-content:space-between;"><div style="font-weight:800;font-size:15px;">CWELCC enrolment · ' + esc(c.centre.name) + ' · ' + esc(monthLabel(c.month)) + '</div>'
      + '<div style="font-size:12px;color:#64748B;">As of ' + esc(day(c.as_of)) + '</div></div>'
      + (c.centre_cwelcc ? '' : '<div style="margin:6px 0;font-size:13px;color:#B45309;">This centre is not marked as enrolled in CWELCC, so no child counts as CWELCC-eligible. Change it on the centre\'s settings if it is.</div>')
      + '<div style="overflow-x:auto;margin-top:6px;"><table class="roa-t" data-kt-no-filter data-kt-no-bulk data-kt-noexport><thead><tr><th>Age group</th><th class="n">Enrolled</th><th class="n">CWELCC eligible</th><th class="n">Not eligible</th><th class="n">No date of birth</th><th class="n">Family enrolled in CWELCC</th></tr></thead><tbody>'
      + (c.rows.length ? c.rows.map(function (r) { return '<tr><td>' + esc(r.label) + '</td><td class="n">' + r.enrolled + '</td><td class="n" style="font-weight:700;">' + r.eligible + '</td><td class="n">' + r.not_eligible + '</td><td class="n">' + (r.unknown || '') + '</td><td class="n">' + r.family_enrolled + '</td></tr>'; }).join('')
        + '<tr style="font-weight:800;"><td>Total</td><td class="n">' + c.totals.enrolled + '</td><td class="n">' + c.totals.eligible + '</td><td class="n">' + c.totals.not_eligible + '</td><td class="n">' + (c.totals.unknown || '') + '</td><td class="n">' + c.totals.family_enrolled + '</td></tr>'
        : '<tr><td colspan="6" style="color:#64748B;">No children enrolled in this centre\'s rooms.</td></tr>')
      + '</tbody></table></div>'
      + '<div style="font-size:12px;color:#64748B;margin-top:6px;">Eligible: under 6. A child who turns 6 between January 1 and June 30 stays eligible until June 30 (preschool, kindergarten or family age group); otherwise until the end of the month they turn 6. In a school-age room, until the end of their birth month. A child whose enrolment is marked not CWELCC-eligible is counted as not eligible.</div></div>';
    h += '<div class="roa-card"><div style="font-weight:800;font-size:15px;margin-bottom:6px;">Operating and licensed capacity</div><div style="overflow-x:auto;"><table class="roa-t" data-kt-no-filter data-kt-no-bulk data-kt-noexport><thead><tr><th>Age group</th><th class="n">Licensed</th><th class="n">Operating</th><th class="n">Enrolled</th><th class="n">Vacancies</th></tr></thead><tbody>'
      + (c.capacity.rows || []).map(function (r) { return '<tr><td>' + esc(r.label) + '</td><td class="n">' + r.licensed + '</td><td class="n">' + r.operating + '</td><td class="n">' + r.enrolled + '</td><td class="n">' + r.vacancies + '</td></tr>'; }).join('')
      + '</tbody></table></div><div style="font-size:12px;color:#64748B;margin-top:6px;">Operating capacity is set on the Record of Attendance tab.</div></div>';
    if (c.ageing_out.length) h += '<div class="roa-card"><div style="font-weight:800;font-size:15px;">Leaving CWELCC in the next two months</div><div style="font-size:12.5px;color:#64748B;margin-bottom:6px;">Tell these families at least 2 months ahead, with the fee they will pay.</div><ul style="margin:0;padding-left:18px;font-size:13px;line-height:1.6;">'
      + c.ageing_out.map(function (a) { return '<li><b>' + esc(a.name) + '</b> (' + esc(a.group) + ') — eligible until ' + esc(day(a.until)) + '</li>'; }).join('') + '</ul></div>';
    if (c.check.length) h += '<div class="roa-card" style="border-color:#FCD34D;background:#FFFBEB;"><div style="font-weight:800;font-size:15px;margin-bottom:4px;">Check before you report</div><ul style="margin:0;padding-left:18px;font-size:13px;line-height:1.6;">'
      + c.check.map(function (a) { return '<li><b>' + esc(a.name) + '</b> (' + esc(a.group) + '): ' + esc(a.issue) + '</li>'; }).join('') + '</ul></div>';
    el.innerHTML = h;
  }

  // ── Settings (agency admins) ──────────────────────────────────────────────────
  function paneSettings(main, el) {
    var d = state.data, s = d.settings;
    var presetOpts = d.presets.map(function (p) { return '<option value="' + p.key + '"' + (p.key === s.preset ? ' selected' : '') + '>' + esc(p.label) + '</option>'; }).join('');
    var num = function (id, label, v, hint) { return '<div><label class="roa-l" for="' + id + '">' + esc(label) + '</label><input class="roa-i" id="' + id + '" type="number" min="0" value="' + (v == null ? '' : v) + '" style="width:100%;">' + (hint ? '<div class="roa-sub" style="margin-top:3px;">' + esc(hint) + '</div>' : '') + '</div>'; };
    el.innerHTML = '<div class="roa-card"><div style="font-weight:800;font-size:15px;margin-bottom:8px;">Municipality and absence rules</div>'
      + '<div class="roa-f"><div><label class="roa-l" for="roa-s-p">Municipality (CMSM / DSSAB)</label><select class="roa-i" id="roa-s-p" style="width:100%;">' + presetOpts + '</select></div>'
      + num('roa-s-due', 'Due day of the month', s.due_day, 'York: the 8th') + '</div>'
      + '<div id="roa-s-note" style="font-size:12.5px;color:#475569;margin:10px 0;"></div>'
      + '<div class="roa-f">'
      + num('roa-s-a', 'Absence days a year', s.allowance, 'Blank = not set')
      + num('roa-s-st', 'Fewer per month started after January', s.prorate_step, 'York: 3 (Feb 45 … Dec 15)')
      + num('roa-s-mx', 'Most absence days in a row', s.max_consecutive, 'Blank = no limit. Toronto: 20')
      + num('roa-s-un', 'Unexplained absences in a row to report', s.unexplained_run, 'York: 5') + '</div>'
      + '<label style="display:flex;gap:8px;align-items:center;font-size:13.5px;cursor:pointer;margin-top:12px;"><input type="checkbox" id="roa-s-r"' + (s.reminders ? ' checked' : '') + '> '
      + 'Remind directors and admins on the 1st (ready to review) and two days before the due day if not signed off — bell and email</label>'
      + '<div class="roa-row" style="margin-top:12px;"><button class="roa-b p" type="button" id="roa-s-save">Save</button><button class="roa-b" type="button" id="roa-s-def">Use the municipality\'s defaults</button></div></div>';
    var $ = function (id) { return el.querySelector('#' + id); };
    function note() { var p = d.presets.filter(function (x) { return x.key === $('roa-s-p').value; })[0]; $('roa-s-note').textContent = p ? p.note + (p.verified ? '' : ' (Not checked against the municipality\'s current handbook.)') : ''; }
    $('roa-s-p').addEventListener('change', function () {
      var p = d.presets.filter(function (x) { return x.key === $('roa-s-p').value; })[0]; if (!p) return;
      $('roa-s-a').value = p.allowance == null ? '' : p.allowance; $('roa-s-st').value = p.prorate_step || 0; $('roa-s-mx').value = p.max_consecutive == null ? '' : p.max_consecutive; $('roa-s-un').value = p.unexplained_run;
      note();
    });
    note();
    async function save(usePreset) {
      var v = function (id) { var x = $(id).value; return x === '' ? null : +x; };
      var body = { preset: $('roa-s-p').value, due_day: v('roa-s-due') || 8, reminders: $('roa-s-r').checked, use_preset: !!usePreset };
      if (!usePreset) { body.allowance = v('roa-s-a'); body.prorate_step = v('roa-s-st') || 0; body.max_consecutive = v('roa-s-mx'); body.unexplained_run = v('roa-s-un') || 5; }
      try { await Api.post('/occms/settings', body); toast('🧾', 'Settings saved', '', '#16A34A'); render(main); }
      catch (e) { toast('⚠️', 'Could not save', (e && e.message) || '', '#DC2626'); }
    }
    $('roa-s-save').addEventListener('click', function () { save(false); });
    $('roa-s-def').addEventListener('click', function () { save(true); });
  }

  ['agency_admin', 'platform_admin', 'centre_director'].forEach(function (r) { KT.Shell.registerScreen(r + ':subsidy-roa', render); });
})(window);
