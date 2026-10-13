/* ═══════════════════════════════════════════════════════════════════════
   Ontario workforce funding (#workforce-funding) — 2026-10-13

   WEG (Wage Enhancement Grant) and CWELCC Workforce Compensation for Ontario agencies:
     · Staff wages   — per employee: base wage, then WEG, the WCF annual increase and the
                       wage-floor top-up, worked out in the guideline's order of operations
                       (App\Support\WorkforceFunding), each overridable, effective-dated.
     · Pay period    — hours from the clock x each component, as separate lines, and the
                       lines already on that period's payslips. CSV.
     · Funding received — what the CMSM/DSSAB paid and when; every receipt must reach staff
                       pay within 32 days (flagged MET / PENDING / MISSED).
     · Year-end      — per employee and per position: hours, FTE, base, WEG, WCF; non-RECE
                       director-approved staff separately; received vs paid. CSV + PDF.
   Settings → Payroll carries the switch, the year's floor/ceilings and the distribution
   policy (KT.WorkforceFunding.paintSettings, called by screen-payroll-settings.js).

   Admins only; Ontario agencies only (the menu entry is hidden everywhere else). Records
   and reporting only — nothing here pays anybody.
   ═══════════════════════════════════════════════════════════════════════ */
(function (window) {
  'use strict';
  var KT = window.KT = window.KT || {};

  function Api() { return KT.Api; }
  function esc(s) { return String(s == null ? '' : s).replace(/[&<>"]/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]; }); }
  function errMsg(e) { return (e && e.data && e.data.message) || (e && e.message) || 'Something went wrong.'; }
  function toast(msg, kind) { try { if (KT.Dom && KT.Dom.toast) KT.Dom.toast(msg, kind); } catch (e) {} }
  function apiBase() { return (window.KT_CONFIG && window.KT_CONFIG.apiBase) || KT.API_BASE || 'https://api.kiddietrac.com/api/v1'; }
  function money(n) { n = Number(n) || 0; return (n < 0 ? '-$' : '$') + Math.abs(n).toFixed(2).replace(/\B(?=(\d{3})+(?!\d))/g, ','); }
  function rate(n) { return n == null ? '—' : '$' + Number(n).toFixed(2); }
  function roles() { try { return (JSON.parse(sessionStorage.getItem('kt_user') || localStorage.getItem('kt_user') || '{}').roles || []); } catch (e) { return []; } }
  function hashParam(k) { var m = (location.hash.split('?')[1] || '').match(new RegExp('(?:^|&)' + k + '=([^&]+)')); return m ? decodeURIComponent(m[1]) : ''; }
  function ymd(d) { return d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0'); }

  /* ── menu: hidden unless Ontario + switched on ── */
  (function () {
    var st = document.createElement('style');
    st.textContent = 'body:not(.kt-wf-on) [data-hash="workforce-funding"], body:not(.kt-wf-on) [href="#workforce-funding"] { display:none !important; }';
    (document.head || document.documentElement).appendChild(st);
  })();
  var probedFor = null;
  function probe(force) {
    var A = Api();
    if (!A || !A.get || !document.body) return;
    var tok = sessionStorage.getItem('kt_token') || localStorage.getItem('kt_token');
    if (!tok) return;
    var bc = document.body.classList;
    if (!/\brole-/.test(document.body.className || '')) return;
    var ag = sessionStorage.getItem('kt_active_agency_id') || '';
    if (probedFor === ag && !force) return;
    if (!(bc.contains('role-agency-admin') || bc.contains('role-platform-admin'))) { probedFor = ag; bc.remove('kt-wf-on'); return; }
    if (!ag && roles().indexOf('platform_admin') !== -1) return;
    probedFor = ag;
    var key = 'kt_wf_on_' + ag;
    if (!force) { try { var c = sessionStorage.getItem(key); if (c === '1' || c === '0') { bc.toggle('kt-wf-on', c === '1'); return; } } catch (e) {} }
    A.get('/workforce-funding/status').then(function (d) {
      var on = !!(d && d.on);
      bc.toggle('kt-wf-on', on);
      try { sessionStorage.setItem(key, on ? '1' : '0'); } catch (e) {}
    }).catch(function () { bc.remove('kt-wf-on'); });
  }
  setTimeout(probe, 2600); setTimeout(probe, 8200);
  window.addEventListener('kt:agency-changed', function () { probedFor = null; probe(); });

  var CSS_ID = 'kt-wf-css';
  function css() {
    if (document.getElementById(CSS_ID)) return;
    var s = document.createElement('style'); s.id = CSS_ID;
    s.textContent = '.wf-wrap{padding:16px 20px;max-width:1150px;margin:0 auto;}'
      + '.wf-card{background:#fff;border:1px solid #E2E8F0;border-radius:12px;padding:12px 14px;margin-top:12px;}'
      + '.wf-row{display:flex;gap:8px;align-items:center;flex-wrap:wrap;}'
      + '.wf-i{height:30px;padding:0 9px;border:1px solid #CBD5E1;border-radius:8px;font-size:13px;background:#fff;box-sizing:border-box;font-family:inherit;}'
      + 'textarea.wf-i{height:auto;padding:7px 9px;}'
      + '.wf-b{height:30px;padding:0 12px;border-radius:8px;border:1px solid #CBD5E1;background:#fff;font-weight:700;font-size:12.5px;cursor:pointer;white-space:nowrap;color:#0F172A;}'
      + '.wf-b.p{background:#1F6080;border-color:#1F6080;color:#fff;}.wf-b.s{height:26px;padding:0 9px;font-size:12px;}'
      + '.wf-tabs{display:flex;gap:2px;border-bottom:1px solid #E2E8F0;margin-top:10px;overflow-x:auto;}'
      + '.wf-tab{border:0;background:none;padding:8px 12px;font-size:13px;font-weight:700;color:#64748B;border-bottom:2px solid transparent;cursor:pointer;white-space:nowrap;}'
      + '.wf-tab.on{color:#1F6080;border-bottom-color:#1F6080;}'
      + '.wf-t{width:100%;border-collapse:collapse;font-size:12.5px;}'
      + '.wf-t th{text-align:left;font-size:10.5px;text-transform:uppercase;letter-spacing:.4px;color:#64748B;background:#F8FAFC;padding:6px 8px;border-bottom:1px solid #E2E8F0;white-space:nowrap;}'
      + '.wf-t td{padding:6px 8px;border-bottom:1px solid #F1F5F9;vertical-align:top;}.wf-t td.n,.wf-t th.n{text-align:right;white-space:nowrap;}'
      + '.wf-t tr.tot td{font-weight:800;background:#F8FAFC;}.wf-t tr.sub td{color:#475569;font-size:12px;border-bottom:0;padding-top:2px;padding-bottom:2px;}'
      + '.wf-scroll{overflow-x:auto;-webkit-overflow-scrolling:touch;}'
      + '.wf-chip{display:inline-block;padding:1px 7px;border-radius:999px;font-size:11px;font-weight:700;white-space:nowrap;}'
      + '.wf-ok{background:#DCFCE7;color:#166534;}.wf-warn{background:#FEF3C7;color:#92400E;}.wf-bad{background:#FEE2E2;color:#991B1B;}.wf-mute{background:#F1F5F9;color:#475569;}'
      + '.wf-w{font-size:11.5px;color:#92400E;margin-top:2px;}.wf-w.info{color:#64748B;}'
      + '.wf-m{font-size:12.5px;color:#64748B;line-height:1.5;}'
      + '.wf-tiles{display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:8px;margin-top:10px;}'
      + '.wf-tile{border:1px solid #E2E8F0;border-radius:10px;padding:8px 10px;}.wf-tile b{display:block;font-size:17px;color:#0F172A;}.wf-tile span{font-size:11px;color:#64748B;text-transform:uppercase;letter-spacing:.4px;}'
      + '.wf-f label{display:block;font-size:11px;font-weight:800;text-transform:uppercase;letter-spacing:.4px;color:#64748B;margin:8px 0 3px;}'
      + '.wf-f .wf-i{width:100%;}.wf-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:0 10px;}'
      + '@media (max-width:600px){.wf-wrap{padding:12px 10px;}.wf-hide-sm{display:none;}}';
    document.head.appendChild(s);
  }

  var CAT_CHIP = { rece_program: 'wf-ok', rece_supervisor: 'wf-ok', hcc_visitor: 'wf-ok', non_rece: 'wf-warn', not_eligible: 'wf-mute' };
  function warnHtml(list) {
    return (list || []).map(function (w) { return '<div class="wf-w' + (w.level === 'info' ? ' info' : '') + '">' + (w.level === 'info' ? '' : '⚠ ') + esc(w.text) + '</div>'; }).join('');
  }

  var state = { tab: 'staff' };

  async function render(main) {
    css();
    main.setAttribute('data-kt-pretty', '1');
    var tabFromHash = hashParam('tab');
    if (tabFromHash) state.tab = tabFromHash;
    main.innerHTML = '<div class="wf-wrap"><div class="wf-card"><div class="wf-m">Loading…</div></div></div>';
    var st = await Api().get('/workforce-funding/settings').catch(function (e) { return { __err: e }; });
    if (st.__err) {
      main.innerHTML = '<div class="wf-wrap"><div class="wf-card"><b>Ontario workforce funding</b><div class="wf-m" style="margin-top:6px;">'
        + esc(errMsg(st.__err)) + '</div></div></div>';
      return;
    }
    var s = st.settings || {};
    var p = st.params || {};
    var head = '<div class="wf-card"><div class="wf-row" style="justify-content:space-between;">'
      + '<div><div style="font-weight:800;font-size:16px;color:#0F172A;">Ontario workforce funding</div>'
      + '<div class="wf-m">Wage Enhancement Grant (WEG) and CWELCC workforce compensation. ' + esc(p.year) + ': floor '
      + rate(p.floor_program) + ' program staff / ' + rate(p.floor_supervisor) + ' supervisors and home visitors · WCF increase up to '
      + rate(p.wcf_max_increase) + '/h to ' + rate(p.wcf_ceiling_program) + ' (' + rate(p.wcf_ceiling_supervisor) + ' supervisors) · WEG up to '
      + rate(p.weg_max) + '/h to ' + rate(p.weg_ceiling) + '. Paid on every pay cheque within 32 days of receiving funding — never a lump sum.</div></div>'
      + '<a class="wf-b s" href="#payroll-settings" style="display:inline-flex;align-items:center;text-decoration:none;">Settings</a></div>'
      + (s.enabled ? '' : '<div style="margin-top:8px;background:#FEF3C7;border:1px solid #FDE68A;color:#92400E;border-radius:8px;padding:8px 10px;font-size:12.5px;">'
        + 'Funding lines are <b>off</b> for payroll runs. Switch them on in <a href="#payroll-settings">Settings → Payroll</a>.</div>')
      + '<div class="wf-tabs">'
      + [['staff', 'Staff wages'], ['period', 'Pay period'], ['receipts', 'Funding received'], ['year', 'Year-end report']].map(function (t) {
        return '<button type="button" class="wf-tab' + (state.tab === t[0] ? ' on' : '') + '" data-tab="' + t[0] + '">' + t[1] + '</button>';
      }).join('') + '</div><div id="wf-pane" style="margin-top:10px;"></div></div>';
    main.innerHTML = '<div class="wf-wrap">' + head + '</div>';
    main.querySelectorAll('[data-tab]').forEach(function (b) {
      b.addEventListener('click', function () { state.tab = b.getAttribute('data-tab'); render(main); });
    });
    var pane = main.querySelector('#wf-pane');
    if (state.tab === 'period') return paintPeriod(pane);
    if (state.tab === 'receipts') return paintReceipts(pane);
    if (state.tab === 'year') return paintYear(pane);
    return paintStaff(pane);
  }

  /* ───────────── Staff wages ───────────── */
  async function paintStaff(pane) {
    var date = state.staffDate || '';
    pane.innerHTML = '<div class="wf-m">Loading staff…</div>';
    var d = await Api().get('/workforce-funding/staff' + (date ? '?date=' + date : '')).catch(function (e) { return { __err: e }; });
    if (d.__err) { pane.innerHTML = '<div class="wf-w">' + esc(errMsg(d.__err)) + '</div>'; return; }
    state.staffDate = d.date;
    var rows = (d.staff || []).map(function (p) {
      var c = p.c || {};
      return '<tr><td><b>' + esc(p.name) + '</b><div class="wf-m">' + esc((p.role || '').replace('_', ' ')) + (p.centre ? ' · ' + esc(p.centre) : '') + '</div>'
        + warnHtml((c.warnings || []).filter(function (w) { return w.level !== 'info'; })) + '</td>'
        + '<td><span class="wf-chip ' + (CAT_CHIP[c.category] || 'wf-mute') + '">' + esc(c.category_label) + '</span>'
        + (c.rece ? '' : '<div class="wf-m">no RECE on file</div>') + '</td>'
        + '<td class="n">' + rate(c.base) + '</td>'
        + '<td class="n wf-hide-sm">' + (c.gof ? rate(c.gof) : '—') + '</td>'
        + '<td class="n">' + (c.base == null ? '—' : rate(c.weg)) + '</td>'
        + '<td class="n">' + (c.base == null ? '—' : rate(c.wcf_increase)) + '</td>'
        + '<td class="n">' + (c.base == null ? '—' : rate(c.wcf_floor)) + '</td>'
        + '<td class="n"><b>' + rate(c.total) + '</b></td>'
        + '<td class="n"><button type="button" class="wf-b s" data-person="' + p.id + '">Wages</button></td></tr>';
    }).join('');
    pane.innerHTML = '<div class="wf-row" style="justify-content:space-between;margin-bottom:8px;">'
      + '<div class="wf-m">Hourly rates in force on <input type="date" class="wf-i" id="wf-sdate" value="' + esc(d.date) + '" style="width:150px;">. '
      + 'Eligibility comes from the role and an active RECE registration; amounts follow the guideline’s order of operations and can be overridden per person.</div></div>'
      + '<div class="wf-scroll"><table class="wf-t"><thead><tr><th>Employee</th><th>Funding position</th><th class="n">Base</th><th class="n wf-hide-sm">GOF</th>'
      + '<th class="n">WEG</th><th class="n">WCF increase</th><th class="n">Floor top-up</th><th class="n">Total /h</th><th></th></tr></thead><tbody>'
      + (rows || '<tr><td colspan="9" class="wf-m" style="padding:20px;text-align:center;">No staff in this agency.</td></tr>') + '</tbody></table></div>';
    pane.querySelector('#wf-sdate').addEventListener('change', function (e) { state.staffDate = e.target.value; paintStaff(pane); });
    pane.querySelectorAll('[data-person]').forEach(function (b) {
      b.addEventListener('click', function () { openPerson(+b.getAttribute('data-person'), function () { paintStaff(pane); }); });
    });
  }

  function catOptions(cats, sel) {
    return '<option value="">Automatic (role + RECE registration)</option>' + Object.keys(cats).map(function (k) {
      return '<option value="' + k + '"' + (sel === k ? ' selected' : '') + '>' + esc(cats[k]) + '</option>';
    }).join('');
  }
  function num(v) { return v == null ? '' : String(v); }

  async function openPerson(userId, onChange) {
    var wrap = document.createElement('div');
    wrap.className = 'wf-f';
    wrap.style.width = '100%';
    wrap.innerHTML = '<div class="wf-m">Loading…</div>';
    var editing = null;   // a history row being corrected, or null for a new record
    var data = null;

    function form() {
      var r = editing || {};
      var cur = data.history[0] || {};
      var today = ymd(new Date());
      return '<div style="margin-top:12px;padding-top:10px;border-top:1px solid #E2E8F0;">'
        + '<div style="font-weight:800;font-size:13.5px;">' + (editing ? 'Correct the record from ' + esc(r.effective_from) : 'New wage record') + '</div>'
        + '<div class="wf-m">' + (editing ? 'Corrections are logged with the old and new values.' : 'A pay change is a new record from its effective date; the previous record ends the day before. Leave the funding amounts blank to use the guideline calculation.') + '</div>'
        + '<div class="wf-grid">'
        + '<div><label for="wf-ef">Effective from</label><input id="wf-ef" type="date" class="wf-i" value="' + esc(r.effective_from || today) + '"></div>'
        + (editing ? '<div><label for="wf-et">Effective to</label><input id="wf-et" type="date" class="wf-i" value="' + esc(r.effective_to || '') + '"></div>' : '')
        + '<div><label for="wf-base">Base wage ($/h)</label><input id="wf-base" type="number" step="0.01" min="0" class="wf-i" value="' + esc(num(editing ? r.base_wage : cur.base_wage)) + '"></div>'
        + '<div><label for="wf-gof">2024 GOF kept ($/h)</label><input id="wf-gof" type="number" step="0.01" min="0" class="wf-i" value="' + esc(num(editing ? r.gof : (cur.gof || ''))) + '" placeholder="0"></div>'
        + '</div>'
        + '<label for="wf-cat">Funding position</label><select id="wf-cat" class="wf-i">' + catOptions(data.categories, editing ? r.eligibility_override : cur.eligibility_override) + '</select>'
        + '<div class="wf-grid">'
        + '<div><label for="wf-weg">WEG override ($/h)</label><input id="wf-weg" type="number" step="0.01" min="0" class="wf-i" placeholder="auto" value="' + esc(num(editing ? r.weg_override : cur.weg_override)) + '"></div>'
        + '<div><label for="wf-inc">WCF increase override</label><input id="wf-inc" type="number" step="0.01" min="0" class="wf-i" placeholder="auto" value="' + esc(num(editing ? r.wcf_increase_override : cur.wcf_increase_override)) + '"></div>'
        + '<div><label for="wf-fl">Floor top-up override</label><input id="wf-fl" type="number" step="0.01" min="0" class="wf-i" placeholder="auto" value="' + esc(num(editing ? r.floor_override : cur.floor_override)) + '"></div>'
        + '<div><label for="wf-stab">Wage stability ($/h)</label><input id="wf-stab" type="number" step="0.01" min="0" class="wf-i" placeholder="0" value="' + esc(num(editing ? r.stability : (cur.stability || ''))) + '"></div>'
        + '</div>'
        + '<label for="wf-notes">Notes</label><input id="wf-notes" class="wf-i" maxlength="1000" value="' + esc(editing ? (r.notes || '') : '') + '" placeholder="e.g. 2026 wage letter sent Jan 9">'
        + (editing ? '<div style="margin-top:8px;"><button type="button" class="wf-b s" id="wf-cancel-edit">Cancel correction</button></div>' : '')
        + '<div id="wf-pmsg" class="wf-w" style="margin-top:6px;"></div></div>';
    }

    function paint() {
      var c = data.current || {};
      var rece = data.rece;
      var breakdown = [['Base wage', c.base], ['GOF (2024, kept)', c.gof], ['WEG', c.weg, 'weg'], ['WCF annual increase', c.wcf_increase, 'wcf_increase'],
        ['WCF wage floor top-up', c.wcf_floor, 'wcf_floor'], ['Wage stability', c.stability]].map(function (x) {
        var auto = x[2] && c.auto ? c.auto[x[2]] : null;
        var over = x[2] && (c.overridden || []).indexOf(x[2]) !== -1;
        return '<tr><td>' + esc(x[0]) + (over ? ' <span class="wf-chip wf-warn">override</span>' : '') + '</td><td class="n">' + rate(x[1])
          + (over && auto != null ? '<div class="wf-m">guideline ' + rate(auto) + '</div>' : '') + '</td></tr>';
      }).join('');
      var hist = (data.history || []).map(function (h) {
        var hc = h.c || {};
        return '<tr><td>' + esc(h.effective_from) + (h.effective_to ? ' – ' + esc(h.effective_to) : ' – now') + '<div class="wf-m">' + esc(hc.category_label || '') + '</div></td>'
          + '<td class="n">' + rate(h.base_wage) + '</td><td class="n">' + rate(hc.weg) + '</td><td class="n">' + rate(hc.wcf_increase) + '</td><td class="n">' + rate(hc.wcf_floor)
          + '</td><td class="n"><b>' + rate(hc.total) + '</b></td><td class="wf-hide-sm wf-m">' + esc(h.updated_by_name || h.created_by_name || '') + (h.notes ? '<div>' + esc(h.notes) + '</div>' : '') + '</td>'
          + '<td class="n"><button type="button" class="wf-b s" data-edit="' + h.id + '">Correct</button></td></tr>';
      }).join('');
      wrap.innerHTML = '<div class="wf-row" style="justify-content:space-between;"><div><b style="font-size:15px;">' + esc(data.user.name) + '</b>'
        + '<div class="wf-m">' + esc((data.role || 'no active staff role').replace('_', ' ')) + ' · '
        + (rece ? 'RECE registered' + (rece.issued_at ? ' ' + esc(String(rece.issued_at).slice(0, 10)) : '') + (rece.expires_at ? ', expires ' + esc(String(rece.expires_at).slice(0, 10)) : '') : 'No RECE registration on file')
        + (data.user.registry_number ? ' · #' + esc(data.user.registry_number) : '') + '</div></div>'
        + '<span class="wf-chip ' + (CAT_CHIP[c.category] || 'wf-mute') + '">' + esc(c.category_label || '') + '</span></div>'
        + '<div style="display:flex;gap:14px;flex-wrap:wrap;margin-top:8px;">'
        + '<div style="flex:1;min-width:230px;"><div style="font-weight:800;font-size:12px;color:#64748B;text-transform:uppercase;">Today</div>'
        + '<table class="wf-t">' + breakdown + '<tr class="tot"><td>Total hourly wage</td><td class="n">' + rate(c.total) + '</td></tr></table>'
        + (c.floor ? '<div class="wf-m">Floor ' + rate(c.floor) + ' · WCF ceiling ' + rate(c.ceiling) + '</div>' : '') + warnHtml(c.warnings) + '</div>'
        + '<div style="flex:1.4;min-width:260px;"><div style="font-weight:800;font-size:12px;color:#64748B;text-transform:uppercase;">History</div><div class="wf-scroll">'
        + '<table class="wf-t"><thead><tr><th>Effective</th><th class="n">Base</th><th class="n">WEG</th><th class="n">WCF</th><th class="n">Floor</th><th class="n">Total</th><th class="wf-hide-sm">By</th><th></th></tr></thead><tbody>'
        + (hist || '<tr><td colspan="8" class="wf-m">No wage records yet — add the base wage below.</td></tr>') + '</tbody></table></div></div></div>'
        + form();
      wrap.querySelectorAll('[data-edit]').forEach(function (b) {
        b.addEventListener('click', function () {
          var id = +b.getAttribute('data-edit');
          editing = (data.history || []).filter(function (h) { return h.id === id; })[0] || null;
          paint();
          var f = wrap.querySelector('#wf-ef'); if (f) f.focus();
        });
      });
      var ce = wrap.querySelector('#wf-cancel-edit');
      if (ce) ce.addEventListener('click', function () { editing = null; paint(); });
    }

    async function load() {
      data = await Api().get('/workforce-funding/staff/' + userId);
      paint();
    }

    function payload() {
      function v(id) { var el = wrap.querySelector(id); return el ? el.value.trim() : ''; }
      var o = { effective_from: v('#wf-ef'), base_wage: v('#wf-base'), gof: v('#wf-gof') || 0, eligibility_override: v('#wf-cat') || null,
        weg_override: v('#wf-weg') === '' ? null : v('#wf-weg'), wcf_increase_override: v('#wf-inc') === '' ? null : v('#wf-inc'),
        floor_override: v('#wf-fl') === '' ? null : v('#wf-fl'), stability: v('#wf-stab') || 0, notes: v('#wf-notes') || null };
      if (editing) o.effective_to = v('#wf-et') || null;
      return o;
    }

    KT.Shell.Modal.open({
      title: 'Wage components',
      body: wrap,
      large: true,
      actions: [
        { label: 'Close' },
        { label: 'Save', style: 'btn-primary', busyLabel: 'Saving…', handler: function () {
          var o = payload();
          var msg = wrap.querySelector('#wf-pmsg');
          if (!o.effective_from || o.base_wage === '') { msg.textContent = 'An effective date and a base wage are needed.'; return false; }
          var req = editing ? Api().post('/workforce-funding/components/' + editing.id, o) : Api().post('/workforce-funding/staff/' + userId + '/components', o);
          return req.then(function () {
            toast(editing ? 'Wage record corrected' : 'Wage record saved', 'success');
            editing = null;
            if (onChange) onChange();
            return load().then(function () { return false; });
          }).catch(function (e) { msg.textContent = errMsg(e); return false; });
        } },
      ],
    });
    load().catch(function (e) { wrap.innerHTML = '<div class="wf-w">' + esc(errMsg(e)) + '</div>'; });
  }

  /* ───────────── Pay period ───────────── */
  async function paintPeriod(pane) {
    var now = new Date();
    var from = state.pFrom || ymd(new Date(now.getFullYear(), now.getMonth(), 1));
    var to = state.pTo || ymd(new Date(now.getFullYear(), now.getMonth() + 1, 0));
    pane.innerHTML = '<div class="wf-row" style="margin-bottom:8px;"><label class="wf-m" for="wf-pf">Pay period</label>'
      + '<input id="wf-pf" type="date" class="wf-i" value="' + from + '"><span class="wf-m">to</span><input id="wf-pt" type="date" class="wf-i" value="' + to + '">'
      + '<button type="button" class="wf-b p" id="wf-pgo">Show</button><button type="button" class="wf-b" id="wf-pcsv">⬇ Lines (CSV)</button></div><div id="wf-pbody" class="wf-m">Loading…</div>';
    function go() {
      state.pFrom = pane.querySelector('#wf-pf').value; state.pTo = pane.querySelector('#wf-pt').value;
      paintPeriodBody(pane.querySelector('#wf-pbody'), state.pFrom, state.pTo);
    }
    pane.querySelector('#wf-pgo').addEventListener('click', go);
    pane.querySelector('#wf-pcsv').addEventListener('click', function () {
      var f = pane.querySelector('#wf-pf').value, t = pane.querySelector('#wf-pt').value;
      KT.openFile(function () { return KT.fetchFileBlob(apiBase() + '/workforce-funding/period?format=csv&from=' + f + '&to=' + t); },
        { title: 'Workforce funding lines', filename: 'workforce-funding-lines-' + f + '-to-' + t + '.csv', mime: 'text/csv' });
    });
    go();
  }

  async function paintPeriodBody(box, from, to) {
    box.innerHTML = '<div class="wf-m">Reading the clock…</div>';
    var d = await Api().get('/workforce-funding/period?from=' + from + '&to=' + to).catch(function (e) { return { __err: e }; });
    if (d.__err) { box.innerHTML = '<div class="wf-w">' + esc(errMsg(d.__err)) + '</div>'; return; }
    var tg = 0, tf = 0;
    var body = (d.rows || []).map(function (r) {
      tg += r.gross; tf += r.funded;
      var lines = (r.lines || []).map(function (l) {
        return '<tr class="sub"><td style="padding-left:18px;">' + esc(l.label) + '</td><td class="n">' + Number(l.hours).toFixed(2) + ' h</td><td class="n">× ' + rate(l.rate) + '</td><td class="n">' + money(l.amount) + '</td><td></td></tr>';
      }).join('');
      var slips = (r.payslips || []).map(function (s) {
        var funded = (s.lines || []).filter(function (l) { return l.component !== 'base'; });
        var amt = funded.reduce(function (a, l) { return a + l.amount; }, 0);
        return '<div class="wf-m">' + esc(s.reference || ('#' + s.id)) + ' · ' + money(s.gross) + ' · '
          + (funded.length ? '<span class="wf-chip wf-ok">' + money(amt) + ' funding lines</span>' : (r.funded > 0 ? '<span class="wf-chip wf-bad">no funding lines</span>' : '<span class="wf-chip wf-mute">no funding</span>')) + '</div>';
      }).join('');
      return '<tr><td><b>' + esc(r.name) + '</b><div class="wf-m">' + esc(r.category_label) + '</div>' + warnHtml((r.warnings || []).filter(function (w) { return w.level !== 'info'; })) + '</td>'
        + '<td class="n">' + Number(r.hours).toFixed(2) + ' h</td><td class="n">' + rate(r.total_rate) + '/h</td><td class="n"><b>' + money(r.gross) + '</b><div class="wf-m">' + money(r.funded) + ' funded</div></td>'
        + '<td>' + (slips || '<span class="wf-m">no payslip yet</span>') + '</td></tr>' + lines;
    }).join('');
    box.innerHTML = '<div class="wf-m" style="margin-bottom:6px;">Hours are closed clock punches in the period. Funding is paid on every hour worked, overtime included, at the same hourly amount. '
      + 'The right-hand column shows what is already on that period’s payslips.</div><div class="wf-scroll"><table class="wf-t"><thead><tr><th>Employee / line</th><th class="n">Hours</th><th class="n">Rate</th><th class="n">Amount</th><th>Payslips</th></tr></thead><tbody>'
      + (body || '<tr><td colspan="5" class="wf-m" style="padding:20px;text-align:center;">Nobody worked or was paid in this period.</td></tr>')
      + (body ? '<tr class="tot"><td>Total</td><td></td><td></td><td class="n">' + money(tg) + '<div class="wf-m">' + money(tf) + ' funded</div></td><td></td></tr>' : '')
      + '</tbody></table></div>';
  }

  /* ───────────── Funding received ───────────── */
  var STREAM = { weg: 'WEG', wcf: 'Workforce compensation', both: 'WEG + workforce comp.' };
  async function paintReceipts(pane) {
    pane.innerHTML = '<div class="wf-m">Loading…</div>';
    var d = await Api().get('/workforce-funding/receipts').catch(function (e) { return { __err: e }; });
    if (d.__err) { pane.innerHTML = '<div class="wf-w">' + esc(errMsg(d.__err)) + '</div>'; return; }
    var rows = (d.receipts || []).map(function (r) {
      var chip = r.state === 'met' ? '<span class="wf-chip wf-ok">Met · paid ' + esc(r.first_paid_on) + '</span>'
        : r.state === 'missed' ? '<span class="wf-chip wf-bad">MISSED — no funded pay by ' + esc(r.deadline) + '</span>'
        : r.state === 'void' ? '<span class="wf-chip wf-mute">Void</span>'
        : '<span class="wf-chip wf-warn">Pending · ' + r.days_left + ' day' + (r.days_left === 1 ? '' : 's') + ' left</span>';
      return '<tr' + (r.state === 'void' ? ' style="opacity:.6;"' : '') + '><td>' + esc(r.received_on) + '</td><td class="n">' + money(r.amount) + '</td><td>' + esc(STREAM[r.stream] || r.stream) + '</td>'
        + '<td class="wf-hide-sm">' + esc((r.period_from || '') + (r.period_to ? ' – ' + r.period_to : '')) + (r.reference ? '<div class="wf-m">' + esc(r.reference) + '</div>' : '') + '</td>'
        + '<td>' + esc(r.deadline) + '</td><td>' + chip + (r.void_reason ? '<div class="wf-m">' + esc(r.void_reason) + '</div>' : '') + '</td>'
        + '<td class="n">' + (r.state === 'void' ? '' : '<button type="button" class="wf-b s" data-void="' + r.id + '">Void</button>') + '</td></tr>';
    }).join('');
    var s = d.summary || {};
    pane.innerHTML = '<div class="wf-row" style="justify-content:space-between;margin-bottom:8px;"><div class="wf-m">Each payment from your service manager must reach staff pay — as WEG / workforce compensation lines on a payslip — within 32 days of receiving it.'
      + (s.missed ? ' <b style="color:#991B1B;">' + s.missed + ' missed.</b>' : '') + '</div><button type="button" class="wf-b p" id="wf-radd">+ Record funding received</button></div>'
      + '<div class="wf-scroll"><table class="wf-t"><thead><tr><th>Received</th><th class="n">Amount</th><th>Stream</th><th class="wf-hide-sm">Covers</th><th>Pay by</th><th>32-day check</th><th></th></tr></thead><tbody>'
      + (rows || '<tr><td colspan="7" class="wf-m" style="padding:20px;text-align:center;">No funding recorded yet.</td></tr>') + '</tbody></table></div>';
    pane.querySelector('#wf-radd').addEventListener('click', function () { addReceipt(d.centres || [], function () { paintReceipts(pane); }); });
    pane.querySelectorAll('[data-void]').forEach(function (b) {
      b.addEventListener('click', async function () {
        var reason = await KT.prompt({ title: 'Void this receipt?', description: 'It stays on record (kept seven years), marked void. Say why.', placeholder: 'e.g. entered twice', okLabel: 'Void it' });
        if (!reason || String(reason).trim().length < 3) return;
        Api().post('/workforce-funding/receipts/' + b.getAttribute('data-void') + '/void', { reason: String(reason).trim() })
          .then(function () { toast('Receipt voided', 'success'); paintReceipts(pane); })
          .catch(function (e) { toast(errMsg(e), 'error'); });
      });
    });
  }

  function addReceipt(centres, done) {
    var wrap = document.createElement('div');
    wrap.className = 'wf-f';
    wrap.style.width = '100%';
    var today = ymd(new Date());
    wrap.innerHTML = '<div class="wf-grid">'
      + '<div><label for="wr-on">Received on</label><input id="wr-on" type="date" class="wf-i" value="' + today + '" max="' + today + '"></div>'
      + '<div><label for="wr-amt">Amount ($)</label><input id="wr-amt" type="number" step="0.01" min="0" class="wf-i"></div>'
      + '<div><label for="wr-str">Funding</label><select id="wr-str" class="wf-i"><option value="both">WEG + workforce compensation</option><option value="weg">WEG only</option><option value="wcf">Workforce compensation only</option></select></div>'
      + '</div><div class="wf-grid">'
      + '<div><label for="wr-pf">Period covered from</label><input id="wr-pf" type="date" class="wf-i"></div>'
      + '<div><label for="wr-pt">to</label><input id="wr-pt" type="date" class="wf-i"></div>'
      + '<div><label for="wr-c">Centre (optional)</label><select id="wr-c" class="wf-i"><option value="">Whole agency</option>'
      + centres.map(function (c) { return '<option value="' + c.id + '">' + esc(c.name) + '</option>'; }).join('') + '</select></div>'
      + '</div><label for="wr-ref">Reference</label><input id="wr-ref" class="wf-i" maxlength="120" placeholder="e.g. Q3 instalment, EFT 4471">'
      + '<label for="wr-n">Notes</label><input id="wr-n" class="wf-i" maxlength="1000">'
      + '<div class="wf-m" style="margin-top:8px;">From this date you have 32 days to pay it out to staff on their regular pay.</div><div id="wr-msg" class="wf-w"></div>';
    KT.Shell.Modal.open({
      title: 'Record funding received', body: wrap,
      actions: [{ label: 'Cancel' }, { label: 'Save', style: 'btn-primary', busyLabel: 'Saving…', handler: function () {
        function v(id) { return wrap.querySelector(id).value.trim(); }
        var o = { received_on: v('#wr-on'), amount: v('#wr-amt'), stream: v('#wr-str'), period_from: v('#wr-pf') || null, period_to: v('#wr-pt') || null,
          centre_id: v('#wr-c') || null, reference: v('#wr-ref') || null, notes: v('#wr-n') || null };
        var msg = wrap.querySelector('#wr-msg');
        if (!o.received_on || !(Number(o.amount) > 0)) { msg.textContent = 'The date and the amount are needed.'; return false; }
        return Api().post('/workforce-funding/receipts', o).then(function () { toast('Funding receipt recorded', 'success'); done(); return true; })
          .catch(function (e) { msg.textContent = errMsg(e); return false; });
      } }],
    });
  }

  /* ───────────── Year-end ───────────── */
  async function paintYear(pane) {
    var cur = new Date().getFullYear();
    var year = state.year || cur;
    var opts = '';
    for (var y = cur; y >= 2023; y--) opts += '<option value="' + y + '"' + (y === +year ? ' selected' : '') + '>' + y + '</option>';
    pane.innerHTML = '<div class="wf-row" style="margin-bottom:8px;"><label class="wf-m" for="wf-y">Calendar year</label><select id="wf-y" class="wf-i">' + opts + '</select>'
      + '<button type="button" class="wf-b" id="wf-ycsv">⬇ CSV</button><button type="button" class="wf-b" id="wf-ypdf">⬇ PDF</button></div><div id="wf-ybody" class="wf-m">Loading…</div>';
    pane.querySelector('#wf-y').addEventListener('change', function (e) { state.year = +e.target.value; paintYear(pane); });
    pane.querySelector('#wf-ycsv').addEventListener('click', function () {
      KT.openFile(function () { return KT.fetchFileBlob(apiBase() + '/workforce-funding/year-end?format=csv&year=' + year); },
        { title: 'Year-end reconciliation', filename: 'workforce-funding-year-end-' + year + '.csv', mime: 'text/csv' });
    });
    pane.querySelector('#wf-ypdf').addEventListener('click', function () {
      KT.openFile(function () { return KT.fetchFileBlob(apiBase() + '/workforce-funding/year-end?format=pdf&year=' + year); },
        { title: 'Year-end reconciliation', filename: 'workforce-funding-year-end-' + year + '.pdf', mime: 'application/pdf' });
    });
    var d = await Api().get('/workforce-funding/year-end?year=' + year).catch(function (e) { return { __err: e }; });
    var box = pane.querySelector('#wf-ybody');
    if (d.__err) { box.innerHTML = '<div class="wf-w">' + esc(errMsg(d.__err)) + '</div>'; return; }
    function table(list, tot) {
      var b = (list || []).map(function (p) {
        return '<tr><td><b>' + esc(p.name) + '</b><div class="wf-m">' + esc(p.category_label) + ' · ' + p.payslips + ' payslip' + (p.payslips === 1 ? '' : 's') + '</div></td>'
          + '<td class="n">' + Number(p.hours).toFixed(2) + '</td><td class="n">' + Number(p.fte).toFixed(3) + '</td><td class="n">' + money(p.base) + '</td><td class="n">' + money(p.weg)
          + '</td><td class="n">' + money(p.wcf_increase) + '</td><td class="n">' + money(p.wcf_floor) + '</td><td class="n wf-hide-sm">' + money(p.benefit_allocation) + '</td></tr>';
      }).join('');
      if (!b) return '<div class="wf-m">None.</div>';
      if (tot && tot.staff) b += '<tr class="tot"><td>Total (' + tot.staff + ')</td><td class="n">' + Number(tot.hours).toFixed(2) + '</td><td class="n">' + Number(tot.fte).toFixed(3) + '</td><td class="n">' + money(tot.base)
        + '</td><td class="n">' + money(tot.weg) + '</td><td class="n">' + money(tot.wcf_increase) + '</td><td class="n">' + money(tot.wcf_floor) + '</td><td class="n wf-hide-sm">' + money(tot.benefit_allocation) + '</td></tr>';
      return '<div class="wf-scroll"><table class="wf-t"><thead><tr><th>Employee</th><th class="n">Hours</th><th class="n">FTE</th><th class="n">Base wages</th><th class="n">WEG</th><th class="n">WCF increase</th><th class="n">WCF floor</th><th class="n wf-hide-sm">Benefits 17.5%</th></tr></thead><tbody>' + b + '</tbody></table></div>';
    }
    var f = d.funding || { received: {}, paid: {} };
    var pos = (d.positions || []).map(function (x) {
      return '<tr><td>' + esc(x.label) + '</td><td class="n">' + x.staff + '</td><td class="n">' + Number(x.fte).toFixed(3) + '</td><td class="n">' + money(x.base) + '</td><td class="n">' + money(x.weg)
        + '</td><td class="n">' + money(x.wcf_increase + x.wcf_floor + x.stability) + '</td><td class="n wf-hide-sm">' + x.staff_weg + ' / ' + x.staff_wcf_increase + ' / ' + x.staff_wcf_floor + '</td></tr>';
    }).join('');
    var missed = (d.receipts || []).filter(function (r) { return r.state === 'missed'; }).length;
    box.innerHTML = '<div class="wf-tiles">'
      + '<div class="wf-tile"><span>Funding received</span><b>' + money(f.received.total) + '</b></div>'
      + '<div class="wf-tile"><span>WEG paid</span><b>' + money(f.paid.weg) + '</b></div>'
      + '<div class="wf-tile"><span>WCF paid</span><b>' + money(f.paid.wcf) + '</b></div>'
      + '<div class="wf-tile"><span>Benefit allocation 17.5%</span><b>' + money(f.benefit_allocation) + '</b></div>'
      + '<div class="wf-tile"><span>' + (f.difference >= 0 ? 'Surplus to return' : 'Shortfall') + '</span><b style="color:' + (f.difference >= 0 ? '#0F172A' : '#991B1B') + ';">' + money(Math.abs(f.difference)) + '</b></div>'
      + '<div class="wf-tile"><span>32-day misses</span><b style="color:' + (missed ? '#991B1B' : '#166534') + ';">' + missed + '</b></div></div>'
      + (d.unsplit_payslips ? '<div class="wf-w" style="margin-top:8px;">⚠ ' + d.unsplit_payslips + ' payslip(s) in ' + d.year + ' have no WEG/WCF breakdown (e.g. synced from another payroll system) and are not counted.</div>' : '')
      + '<div style="font-weight:800;margin:14px 0 4px;">RECE staff</div>' + table(d.rece, d.totals && d.totals.rece)
      + '<div style="font-weight:800;margin:14px 0 4px;">Non-RECE director-approved staff <span class="wf-m" style="font-weight:400;">— reported separately (WEG only)</span></div>' + table(d.non_rece, d.totals && d.totals.non_rece)
      + '<div style="font-weight:800;margin:14px 0 4px;">By position</div>'
      + (pos ? '<div class="wf-scroll"><table class="wf-t"><thead><tr><th>Position</th><th class="n">Staff</th><th class="n">FTE</th><th class="n">Base wages</th><th class="n">WEG</th><th class="n">WCF</th><th class="n wf-hide-sm">Staff with WEG / increase / floor</th></tr></thead><tbody>' + pos + '</tbody></table></div>' : '<div class="wf-m">No payslips with funding lines in ' + d.year + '.</div>')
      + '<div class="wf-m" style="margin-top:10px;">From the WEG / WCF lines on each payslip, pay periods ending in ' + d.year + '. FTE = hours ÷ ' + d.fte_hours + '. '
      + 'Benefit allocation is the guideline’s 17.5% on the enhancement amounts, not your actual benefit cost. Keep these records seven years.</div>';
  }

  /* ───────────── Settings → Payroll card ───────────── */
  async function paintSettings(main) {
    css();
    var host = document.createElement('div');
    host.className = 'kt-card';
    host.style.maxWidth = '680px';
    host.style.marginTop = '14px';
    var d = await Api().get('/workforce-funding/settings').catch(function () { return null; });
    if (!d || !d.settings) return;   // not Ontario, or not an admin: the card simply is not there
    var s = d.settings, year = d.current_year, p = (s.years || {})[year] || d.params || {};
    var F = [['floor_program', 'Wage floor — RECE program staff'], ['floor_supervisor', 'Wage floor — RECE supervisors / home visitors'],
      ['wcf_ceiling_program', 'WCF ceiling — program staff'], ['wcf_ceiling_supervisor', 'WCF ceiling — supervisors / home visitors'],
      ['wcf_max_increase', 'WCF annual increase — maximum /h'], ['weg_max', 'WEG — maximum /h'], ['weg_ceiling', 'WEG eligibility ceiling']];
    var pol = d.policy;
    host.innerHTML = '<div class="kt-card-header"><h3 class="kt-card-title">🍁 Ontario workforce funding (WEG + CWELCC)</h3></div><div class="wf-f">'
      + '<p class="wf-m" style="margin:0 0 6px;">Adds Wage Enhancement Grant and workforce compensation to payroll runs as separate pay-stub lines, tracks the 32-day rule and produces the year-end reconciliation. '
      + 'Rates per person are in <a href="#workforce-funding">Finance → Workforce funding</a>.</p>'
      + '<label style="display:flex;gap:10px;align-items:flex-start;text-transform:none;letter-spacing:0;font-size:14px;color:#334155;font-weight:600;cursor:pointer;">'
      + '<input type="checkbox" id="wfs-on" data-kt-switch="1"' + (s.enabled ? ' checked' : '') + '> <span>Use Ontario workforce funding in payroll</span></label>'
      + '<div style="display:flex;align-items:center;gap:8px;margin-top:10px;"><b style="font-size:13px;">Values for</b><select id="wfs-year" class="wf-i" style="width:100px;">'
      + [year - 1, year, year + 1].map(function (y) { return '<option value="' + y + '"' + (y === year ? ' selected' : '') + '>' + y + '</option>'; }).join('') + '</select>'
      + '<span class="wf-m">2026 values come from the 2026 guidelines; enter the new year’s when your service manager publishes them.</span></div>'
      + '<div class="wf-grid" id="wfs-params">' + F.map(function (f) {
        return '<div><label for="wfs-' + f[0] + '">' + esc(f[1]) + '</label><input id="wfs-' + f[0] + '" data-p="' + f[0] + '" type="number" step="0.01" min="0" class="wf-i" value="' + esc(p[f[0]] != null ? p[f[0]] : '') + '"></div>';
      }).join('') + '</div>'
      + '<label for="wfs-fte">Hours in one FTE (year-end report)</label><input id="wfs-fte" type="number" step="1" min="500" class="wf-i" style="max-width:160px;" value="' + esc(s.fte_hours) + '">'
      + '<label for="wfs-pol">Distribution policy</label><textarea id="wfs-pol" rows="5" class="wf-i" placeholder="Who is eligible, how funding is paid (every pay cheque, within 32 days of receipt), what staff are told, how to raise a concern…">' + esc(s.policy_text || '') + '</textarea>'
      + '<div class="wf-grid"><div><label for="wfs-pby">Approved by (name / title)</label><input id="wfs-pby" class="wf-i" value="' + esc(s.policy_approved_by || '') + '"></div>'
      + '<div><label for="wfs-pon">Approved on</label><input id="wfs-pon" type="date" class="wf-i" value="' + esc(s.policy_approved_on || '') + '"></div></div>'
      + '<div class="wf-m" style="margin-top:6px;">' + (d.policy_library
        ? (pol ? 'In the <a href="#policies">Policy library</a>: “' + esc(pol.title) + '”' + (pol.current_version_id ? '' : ' — no version published yet (upload the signed policy there).')
          : '<button type="button" class="wf-b s" id="wfs-lib">Add to the Policy library</button> to upload the signed policy, track yearly review and staff acknowledgement.')
        : '') + '</div>'
      + '<div class="wf-m" style="margin-top:8px;background:#F8FAFC;border:1px solid #E2E8F0;border-radius:8px;padding:7px 10px;">Records are kept seven years: wage records, funding receipts and the pay lines are never deleted by KiddieTrac (corrections are logged; receipts are voided, not removed), and no retention purge touches payroll.</div>'
      + '<div style="margin-top:12px;display:flex;align-items:center;gap:10px;"><button type="button" class="kt-btn kt-btn-primary" id="wfs-save">Save funding settings</button><span id="wfs-st" style="font-size:13px;"></span></div></div>';
    main.appendChild(host);
    var ysel = host.querySelector('#wfs-year');
    ysel.addEventListener('change', function () {
      var yy = +ysel.value, pp = (s.years || {})[yy] || {};
      host.querySelectorAll('[data-p]').forEach(function (i) { var k = i.getAttribute('data-p'); i.value = pp[k] != null ? pp[k] : ''; });
    });
    var lib = host.querySelector('#wfs-lib');
    if (lib) lib.addEventListener('click', function () {
      Api().post('/workforce-funding/policy', {}).then(function () { toast('Added to the Policy library', 'success'); host.remove(); paintSettings(main); })
        .catch(function (e) { toast(errMsg(e), 'error'); });
    });
    host.querySelector('#wfs-save').addEventListener('click', function () {
      var btn = this, st = host.querySelector('#wfs-st');
      var params = {};
      var any = false;
      host.querySelectorAll('[data-p]').forEach(function (i) { if (i.value !== '') { params[i.getAttribute('data-p')] = i.value; any = true; } });
      var o = { enabled: host.querySelector('#wfs-on').checked, fte_hours: host.querySelector('#wfs-fte').value || 1950,
        policy_text: host.querySelector('#wfs-pol').value, policy_approved_by: host.querySelector('#wfs-pby').value || null,
        policy_approved_on: host.querySelector('#wfs-pon').value || null };
      if (any) { o.year = +ysel.value; o.params = params; }
      btn.disabled = true; st.textContent = '';
      Api().post('/workforce-funding/settings', o).then(function (r) {
        btn.disabled = false; st.style.color = '#1E8E60'; st.textContent = '✓ Saved';
        s = r.settings || s;
        try { Object.keys(sessionStorage).forEach(function (k) { if (k.indexOf('kt_wf_on_') === 0) sessionStorage.removeItem(k); }); } catch (e) {}
        probe(true);
        setTimeout(function () { st.textContent = ''; }, 2600);
      }).catch(function (e) { btn.disabled = false; st.style.color = '#BE4038'; st.textContent = errMsg(e); });
    });
  }

  KT.WorkforceFunding = { render: render, paintSettings: paintSettings, openPerson: openPerson };
  ['agency_admin', 'platform_admin'].forEach(function (r) { KT.Shell && KT.Shell.registerScreen && KT.Shell.registerScreen(r + ':workforce-funding', render); });
})(window);
