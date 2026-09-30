/* ═══════════════════════════════════════════════════════════════════
   KIDDIETRAC — Finance → Grants  (agency admin; directors see their centres)

   Operating, wage-enhancement and other funding a centre must spend within a period and
   report on. Each grant shows awarded / received / spent / unspent and whether spending is
   keeping pace with the period, so a shortfall shows up before the report is due.
   Backend: GrantController (/admin/grants*).
   ═══════════════════════════════════════════════════════════════════ */
(function (window) {
  'use strict';
  var KT = window.KT;
  var status = 'active', cache = null;

  function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }
  function money(v) { var n = Number(v || 0); return (n < 0 ? '−$' : '$') + Math.abs(n).toLocaleString('en-CA', { minimumFractionDigits: 2, maximumFractionDigits: 2 }); }
  function day(d) { if (!d) { return '—'; } var p = String(d).slice(0, 10).split('-'); return new Date(Date.UTC(+p[0], +p[1] - 1, +p[2], 12)).toLocaleDateString('en-CA', { month: 'short', day: 'numeric', year: 'numeric', timeZone: 'UTC' }); }
  function toast(m, t) { if (KT.toast) { KT.toast(m, t || 'info'); } }
  var PACE = { on_track: ['On track', '#DCFCE7', '#166534'], underspending: ['Underspending', '#FEF3C7', '#92400E'], ahead: ['Spending ahead', '#DBEAFE', '#1E40AF'], overspent: ['Overspent', '#FEE2E2', '#991B1B'] };

  var CSS = '<style id="gr-css">'
    + '.gr-bar{display:flex;gap:8px;flex-wrap:wrap;align-items:center;margin-bottom:12px}.gr-bar select{height:32px;padding:0 10px;border:1px solid #CBD5E1;border-radius:8px}'
    + '.gr-card{background:#fff;border:1px solid #E2E8F0;border-radius:14px;padding:14px 16px;margin-bottom:10px;cursor:pointer}.gr-card:hover{border-color:#94A3B8}'
    + '.gr-top{display:flex;gap:10px;align-items:center;flex-wrap:wrap}.gr-top strong{font-size:15px;color:#0F172A}'
    + '.gr-pill{display:inline-block;border-radius:10px;padding:1px 8px;font-size:11.5px;font-weight:700}'
    + '.gr-nums{display:grid;grid-template-columns:repeat(5,minmax(0,1fr));gap:8px;margin-top:10px}.gr-nums div{font-size:11.5px;color:#64748B;font-weight:600}.gr-nums b{display:block;font-size:15px;color:#0F172A}'
    + '.gr-track{position:relative;height:8px;background:#E2E8F0;border-radius:6px;margin-top:10px;overflow:visible}.gr-track i{position:absolute;left:0;top:0;bottom:0;border-radius:6px;background:linear-gradient(90deg,#1F6080,#6DBE45)}'
    + '.gr-track s{position:absolute;top:-4px;width:2px;height:16px;background:#0F172A;text-decoration:none}'
    + '.gr-ov{position:fixed;inset:0;background:rgba(15,23,42,.5);display:flex;align-items:center;justify-content:center;z-index:1000000;padding:16px}'
    + '.gr-dlg{background:#fff;border-radius:16px;width:100%;max-width:600px;max-height:90vh;overflow:auto;padding:20px 22px}.gr-dlg h3{margin:0 0 12px;font-size:17px}'
    + '.gr-f{display:grid;grid-template-columns:1fr 1fr;gap:10px}.gr-f .full{grid-column:1/-1}.gr-f label{display:block;font-size:12px;font-weight:700;color:#334155;margin-bottom:3px}'
    + '.gr-f input,.gr-f select,.gr-f textarea{width:100%;box-sizing:border-box;padding:7px 10px;border:1px solid #CBD5E1;border-radius:8px;font-size:13.5px;font-family:inherit}'
    + '.gr-act{display:flex;justify-content:flex-end;gap:8px;margin-top:14px}'
    + '@media (max-width:700px){.gr-nums{grid-template-columns:repeat(2,minmax(0,1fr))}.gr-f{grid-template-columns:minmax(0,1fr)}}'
    + '</style>';

  function dialog(title, html, onSave, label) {
    var ov = document.createElement('div'); ov.className = 'gr-ov';
    ov.innerHTML = '<div class="gr-dlg" role="dialog" aria-modal="true"><h3>' + esc(title) + '</h3>' + html
      + '<div class="gr-act"><button class="kt-btn" data-x>Cancel</button><button class="kt-btn kt-btn-primary" data-ok>' + esc(label || 'Save') + '</button></div></div>';
    document.body.appendChild(ov);
    var down = null;
    ov.addEventListener('mousedown', function (e) { down = e.target; });
    ov.addEventListener('click', function (e) { if (e.target === ov && down === ov) { ov.remove(); } });
    ov.querySelector('[data-x]').onclick = function () { ov.remove(); };
    ov.querySelector('[data-ok]').onclick = async function () {
      this.disabled = true;
      try { if ((await onSave(ov)) !== false) { ov.remove(); } } catch (e) { toast((e && e.message) || 'Could not save', 'error'); }
      this.disabled = false;
    };
  }
  function vals(root) { var o = {}; root.querySelectorAll('[data-k]').forEach(function (el) { o[el.getAttribute('data-k')] = el.value.trim(); }); return o; }
  function fld(k, label, v, o) {
    o = o || {};
    var c = o.full ? ' class="full"' : '';
    if (o.sel) { return '<div' + c + '><label>' + esc(label) + '</label><select data-k="' + k + '">' + o.sel.map(function (x) { return '<option value="' + esc(x[0]) + '"' + (String(x[0]) === String(v == null ? '' : v) ? ' selected' : '') + '>' + esc(x[1]) + '</option>'; }).join('') + '</select></div>'; }
    if (o.area) { return '<div' + c + '><label>' + esc(label) + '</label><textarea data-k="' + k + '" rows="3">' + esc(v || '') + '</textarea></div>'; }
    return '<div' + c + '><label>' + esc(label) + '</label><input data-k="' + k + '" type="' + (o.type || 'text') + '"' + (o.step ? ' step="' + o.step + '"' : '') + ' value="' + esc(v == null ? '' : v) + '"' + (o.ph ? ' placeholder="' + esc(o.ph) + '"' : '') + '></div>';
  }
  function progress(r) {
    var spent = Math.min(100, r.spent_pct || 0), time = Math.min(100, r.time_pct || 0);
    return '<div class="gr-track" title="Bar: share of award spent. Line: share of the period elapsed."><i style="width:' + spent + '%"></i><s style="left:calc(' + time + '% - 1px)"></s></div>'
      + '<div style="display:flex;justify-content:space-between;font-size:11.5px;color:#64748B;margin-top:4px"><span>' + spent + '% of award spent</span><span>' + time + '% of period elapsed</span></div>';
  }

  async function render(main) {
    main.setAttribute('data-kt-pretty', '1');
    main.innerHTML = CSS + '<div data-kt-self-live style="padding:14px 24px;max-width:1200px;"><div class="kt-page-hero"><h2>🏛️ Grants</h2>'
      + '<p>Track operating, wage-enhancement and other funding: what was awarded, received and spent, and whether spending is keeping pace before the report is due.</p></div><div id="gr-body">Loading…</div></div>';
    await list(main);
  }

  async function list(main) {
    var body = main.querySelector('#gr-body');
    try { cache = await KT.Api.get('/admin/grants?status=' + status); }
    catch (e) { body.innerHTML = '<div class="kt-card" style="color:#B91C1C">Could not load: ' + esc(e.message || e) + '</div>'; return; }
    var gs = cache.grants || [];
    body.innerHTML = '<div class="gr-bar"><select data-st><option value="active"' + (status === 'active' ? ' selected' : '') + '>Active grants</option><option value="closed"' + (status === 'closed' ? ' selected' : '') + '>Closed</option><option value="all"' + (status === 'all' ? ' selected' : '') + '>All</option></select>'
      + '<span style="flex:1"></span>' + (cache.can_manage ? '<button class="kt-btn kt-btn-primary" data-new>+ Add a grant</button>' : '') + '</div>'
      + (gs.length ? gs.map(function (g) {
        var r = g.reconciliation, p = PACE[r.pace] || PACE.on_track;
        return '<div class="gr-card" data-open="' + g.id + '"><div class="gr-top"><strong>' + esc(g.name) + '</strong><span class="gr-pill" style="background:' + p[1] + ';color:' + p[2] + '">' + p[0] + '</span>'
          + '<span style="font-size:12.5px;color:#64748B">' + esc((cache.types || {})[g.program_type] || g.program_type) + ' · ' + esc(g.centre_name || 'All centres') + (g.funder ? ' · ' + esc(g.funder) : '') + '</span>'
          + '<span style="flex:1"></span><span style="font-size:12.5px;color:#64748B">' + esc(day(g.period_start)) + ' – ' + esc(day(g.period_end)) + (g.reporting_due ? ' · report due ' + esc(day(g.reporting_due)) : '') + '</span></div>'
          + '<div class="gr-nums"><div>Awarded<b>' + money(r.awarded) + '</b></div><div>Received<b>' + money(r.received) + '</b></div><div>Spent<b>' + money(r.spent) + '</b></div><div>Unspent<b>' + money(r.unspent) + '</b></div><div>Still to receive<b>' + money(r.outstanding) + '</b></div></div>'
          + progress(r) + '</div>';
      }).join('') : '<div class="kt-card" style="text-align:center;color:#64748B;padding:30px">No grants yet.' + (cache.can_manage ? ' Add one to start tracking what you receive and spend.' : '') + '</div>');
    body.querySelector('[data-st]').onchange = function () { status = this.value; list(main); };
    var nb = body.querySelector('[data-new]'); if (nb) { nb.onclick = function () { grantDialog(null, main); }; }
    body.querySelectorAll('[data-open]').forEach(function (c) { c.onclick = function () { detail(main, +c.getAttribute('data-open')); }; });
  }

  function grantDialog(g, main) {
    g = g || { program_type: 'operating', status: 'active' };
    var types = Object.keys(cache.types || {}).map(function (k) { return [k, cache.types[k]]; });
    var centres = [['', 'All centres (agency-wide)']].concat((cache.centres || []).map(function (c) { return [c.id, c.name]; }));
    dialog(g.id ? 'Edit grant' : 'Add a grant', '<div class="gr-f">' + fld('name', 'Grant name *', g.name, { full: true, ph: 'e.g. 2026 Wage Enhancement Grant' })
      + fld('funder', 'Funder', g.funder, { ph: 'e.g. Ministry of Education, CMSM' }) + fld('reference', 'Agreement / reference #', g.reference)
      + fld('program_type', 'Type', g.program_type, { sel: types }) + fld('centre_id', 'Centre', g.centre_id || '', { sel: centres })
      + fld('period_start', 'Period starts *', (g.period_start || '').slice(0, 10), { type: 'date' }) + fld('period_end', 'Period ends *', (g.period_end || '').slice(0, 10), { type: 'date' })
      + fld('amount_awarded', 'Amount awarded *', g.amount_awarded, { type: 'number', step: '0.01' }) + fld('reporting_due', 'Report due', (g.reporting_due || '').slice(0, 10), { type: 'date' })
      + (g.id ? fld('status', 'Status', g.status, { sel: [['active', 'Active'], ['closed', 'Closed']] }) : '')
      + fld('notes', 'Notes (conditions, eligible costs)', g.notes, { area: true, full: true }) + '</div>', async function (ov) {
      var v = vals(ov);
      if (!v.name || !v.period_start || !v.period_end || v.amount_awarded === '') { toast('Name, period and amount are required.', 'error'); return false; }
      if (!v.centre_id) { v.centre_id = null; }
      ['reporting_due', 'reference', 'funder', 'notes'].forEach(function (k) { if (v[k] === '') { v[k] = null; } });
      var r = await KT.Api.post('/admin/grants' + (g.id ? '/' + g.id : ''), v);
      toast('Grant saved', 'success');
      if (g.id) { detail(main, g.id, true); } else { detail(main, r.id); }
    });
  }

  async function detail(main, id, quiet) {
    var body = main.querySelector('#gr-body'), d;
    // "Loading…" only when opening a grant; a refresh after a save swaps in one frame.
    if (!quiet) { body.innerHTML = 'Loading…'; }
    try { d = await KT.Api.get('/admin/grants/' + id); } catch (e) { body.innerHTML = '<div class="kt-card" style="color:#B91C1C">' + esc(e.message || e) + '</div>'; return; }
    var g = d.grant, r = g.reconciliation, p = PACE[r.pace] || PACE.on_track, cats = d.categories || {};
    var paceNote = { underspending: 'Spending is behind the calendar. Unspent funds may have to be returned at the end of the period.', ahead: 'Spending is ahead of the calendar; check the award will cover the rest of the period.', overspent: 'Spending is more than the award.', on_track: 'Spending is keeping pace with the period.' }[r.pace];
    body.innerHTML = '<a href="#grants" data-back style="font-weight:800;font-size:13px;color:#1F6080">‹ All grants</a>'
      + '<div class="kt-card" style="margin-top:10px"><div class="gr-top"><strong style="font-size:17px">' + esc(g.name) + '</strong><span class="gr-pill" style="background:' + p[1] + ';color:' + p[2] + '">' + p[0] + '</span><span style="flex:1"></span>'
      + (cache && cache.can_manage ? '<button class="kt-btn" data-edit>Edit</button>' : '') + '<button class="kt-btn" data-csv>⬇ Export CSV</button></div>'
      + '<div style="font-size:13px;color:#64748B;margin-top:4px">' + esc((d.types || {})[g.program_type] || g.program_type) + ' · ' + esc(g.centre_name || 'All centres') + (g.funder ? ' · ' + esc(g.funder) : '') + (g.reference ? ' · ref ' + esc(g.reference) : '')
      + ' · ' + esc(day(g.period_start)) + ' – ' + esc(day(g.period_end)) + (g.reporting_due ? ' · <strong>report due ' + esc(day(g.reporting_due)) + '</strong>' : '') + '</div>'
      + '<div class="gr-nums"><div>Awarded<b>' + money(r.awarded) + '</b></div><div>Received<b>' + money(r.received) + '</b></div><div>Spent<b>' + money(r.spent) + '</b></div><div>Unspent<b>' + money(r.unspent) + '</b></div><div>Still to receive<b>' + money(r.outstanding) + '</b></div></div>'
      + progress(r) + '<p style="font-size:13px;color:#475569;margin:8px 0 0">' + esc(paceNote) + (r.returned ? ' ' + money(r.returned) + ' returned.' : '') + (r.adjustments ? ' Adjustments: ' + money(r.adjustments) + '.' : '') + '</p>'
      + (g.notes ? '<p style="font-size:13px;color:#475569;margin:8px 0 0;white-space:pre-wrap">' + esc(g.notes) + '</p>' : '') + '</div>'
      + '<div class="kt-card"><div class="gr-top"><strong>Transactions</strong><span style="flex:1"></span>'
      + '<button class="kt-btn" data-add="received">+ Funds received</button><button class="kt-btn kt-btn-primary" data-add="spent">+ Spending</button><button class="kt-btn" data-add="returned">Funds returned</button><button class="kt-btn" data-add="adjustment">Adjustment</button></div>'
      + (d.transactions.length ? '<div style="overflow:auto;margin-top:10px"><table><thead><tr><th>Date</th><th>Type</th><th>Category</th><th>Description</th><th>Reference</th><th style="text-align:right">Amount</th><th></th></tr></thead><tbody>'
        + d.transactions.map(function (t) {
          return '<tr><td>' + esc(day(t.txn_date)) + '</td><td>' + esc(t.kind) + '</td><td>' + esc(t.category ? (cats[t.category] || t.category) : '') + '</td><td>' + esc(t.description || '') + (t.expense_number ? ' <span style="color:#64748B">(bill ' + esc(t.expense_number) + ')</span>' : '') + '</td>'
            + '<td>' + esc(t.reference || '') + '</td><td style="text-align:right;font-weight:700">' + money(t.amount) + '</td><td><button class="kt-btn" style="padding:3px 8px;font-size:12px" data-del="' + t.id + '">Remove</button></td></tr>';
        }).join('') + '</tbody></table></div>' : '<div style="color:#64748B;font-size:13.5px;margin-top:10px">No transactions yet. Record the funds when they arrive and spending as it happens.</div>')
      + '</div>'
      + (d.months.length ? '<div class="kt-card"><strong>By month</strong><div style="overflow:auto;margin-top:8px"><table><thead><tr><th>Month</th><th style="text-align:right">Received</th><th style="text-align:right">Spent</th></tr></thead><tbody>'
        + d.months.map(function (m) { return '<tr><td>' + esc(m.month) + '</td><td style="text-align:right">' + money(m.received) + '</td><td style="text-align:right">' + money(m.spent) + '</td></tr>'; }).join('')
        + '</tbody></table></div>' + (d.by_category.length ? '<div style="margin-top:10px;font-size:13px;color:#475569"><strong>Spent by category:</strong> ' + d.by_category.map(function (c) { return esc(c.label) + ' ' + money(c.spent); }).join(' · ') + '</div>' : '') + '</div>' : '');

    body.querySelector('[data-back]').onclick = function (e) { e.preventDefault(); list(main); };
    var eb = body.querySelector('[data-edit]'); if (eb) { eb.onclick = function () { grantDialog(g, main); }; }
    body.querySelector('[data-csv]').onclick = async function () {
      var tok = sessionStorage.getItem('kt_token') || localStorage.getItem('kt_token'), h = { Authorization: 'Bearer ' + tok }, aid = sessionStorage.getItem('kt_active_agency_id');
      if (aid) { h['X-Active-Agency-Id'] = aid; }
      var res = await fetch((KT.API_BASE || 'https://api.kiddietrac.com/api/v1') + '/admin/grants/' + g.id + '/export.csv', { headers: h });
      if (!res.ok) { toast('Could not export', 'error'); return; }
      var a = document.createElement('a'); a.href = URL.createObjectURL(await res.blob()); a.download = 'grant-' + g.id + '.csv'; document.body.appendChild(a); a.click(); a.remove();
    };
    body.querySelectorAll('[data-add]').forEach(function (b) { b.onclick = function () { txnDialog(main, g, d, b.getAttribute('data-add')); }; });
    body.querySelectorAll('[data-del]').forEach(function (b) {
      b.onclick = async function () {
        if (!(await KT.confirm({ title: 'Remove this transaction?', description: 'The grant totals will be recalculated. This is recorded in the audit log.', okLabel: 'Remove' }))) { return; }
        try { await KT.Api.delete('/admin/grants/' + g.id + '/transactions/' + b.getAttribute('data-del')); } catch (e) { toast(e.message || 'Could not remove', 'error'); }
        detail(main, g.id, true);
      };
    });
  }

  function txnDialog(main, g, d, kind) {
    var titles = { received: 'Funds received', spent: 'Record spending', returned: 'Funds returned to the funder', adjustment: 'Adjustment' };
    var cats = Object.keys(d.categories || {}).map(function (k) { return [k, d.categories[k]]; });
    var bills = [['', '— none —']].concat((d.expenses || []).map(function (e) { return [e.id, (e.invoice_number || e.reference || ('Bill #' + e.id)) + ' · ' + money(e.total) + ' · ' + day(e.issue_date)]; }));
    dialog(titles[kind], '<div class="gr-f">' + fld('txn_date', 'Date *', new Date().toISOString().slice(0, 10), { type: 'date' })
      + fld('amount', kind === 'adjustment' ? 'Amount * (negative to reduce)' : 'Amount *', '', { type: 'number', step: '0.01' })
      + (kind === 'spent' ? fld('category', 'Category', 'wages', { sel: cats }) + fld('expense_invoice_id', 'Linked expense bill (optional)', '', { sel: bills }) : '')
      + fld('description', 'Description', '', { full: true, ph: kind === 'received' ? 'e.g. Q1 instalment' : '' }) + fld('reference', 'Reference (cheque, EFT, payroll run)', '', { full: true }) + '</div>', async function (ov) {
      var v = vals(ov);
      if (!v.txn_date || v.amount === '' || isNaN(+v.amount)) { toast('A date and an amount are required.', 'error'); return false; }
      v.kind = kind;
      if (!v.expense_invoice_id) { delete v.expense_invoice_id; }
      if (!v.category) { delete v.category; }
      await KT.Api.post('/admin/grants/' + g.id + '/transactions', v);
      toast('Saved', 'success');
      detail(main, g.id, true);
    });
  }

  function reg(n) {
    if (!window.KT || !KT.Shell || !KT.Shell.registerScreen) { if ((n || 0) < 100) { setTimeout(function () { reg((n || 0) + 1); }, 100); } return; }
    KT = window.KT;
    ['agency_admin', 'centre_director', 'platform_admin'].forEach(function (r) { KT.Shell.registerScreen(r + ':grants', render); });
  }
  reg(0);
})(window);
