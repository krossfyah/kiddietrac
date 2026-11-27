/* ═══════════════════════════════════════════════════════════════════════
   screen-hr-tax.js - KiddieTrac Payroll Engine, Phase 1 (2026-11-27).

   - My HR > "Tax forms": the person fills in their TD1 (federal + their province; Quebec = TD1 + TP-1015.3). It
     becomes a PDF in My forms that they e-sign (signature stamp). Signed = in force for that tax year.
   - HR profile > "Payroll tax setup" card (agency admins): province of employment, pay frequency, CPP / EI / QPIP
     exemptions, vacation pay %, the person's forms, "Preview a pay" (an example deduction, nothing saved) and
     "Record a paper TD1". Never your own setup.
   Draws NOTHING until the agency's payroll engine is switched on (the server answers {enabled:false}).
   Server: App\Http\Controllers\Api\PayrollTaxController. Lazy: a "d" dependency of screen-hr.js and
   screen-hr-talent.js, never loaded on its own.
   Phase 2 (2026-11-27): pay runs, remittances, opening balances, the HR settings card and My payslips (see below).
   ═══════════════════════════════════════════════════════════════════════ */
(function (window) {
  'use strict';
  var KT = window.KT;
  if (!KT || !KT.Api) return;
  var Api = KT.Api;

  function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }
  function toast(i, t, m, c) { try { KT.toast && KT.toast(i, t, m || '', c); } catch (e) {} }
  function errMsg(e) {
    var d = e && e.data;
    if (d && d.errors) { var k = Object.keys(d.errors)[0]; if (k && d.errors[k] && d.errors[k][0]) return d.errors[k][0]; }
    return (d && d.message) || 'Something went wrong. Please try again.';
  }
  var MON = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
  function day(s) { var m = /^(\d{4})-(\d{2})-(\d{2})/.exec(s || ''); return m ? MON[+m[2] - 1] + ' ' + (+m[3]) + ', ' + m[1] : ''; }
  function today() { try { if (KT.agencyToday) return KT.agencyToday(); } catch (e) {} return new Date().toISOString().slice(0, 10); }
  function money(n) { if (n == null || n === '') return '—'; var v = Number(n) || 0; return '$' + v.toFixed(2).replace(/\B(?=(\d{3})+(?!\d))/g, ','); }
  function pill(bg, fg, t) { return '<span data-kt-no-tone style="display:inline-block;padding:2px 9px;border-radius:999px;font-size:12px;font-weight:700;background:' + bg + ';color:' + fg + ';white-space:nowrap;">' + esc(t) + '</span>'; }
  function state(f) { return f.state === 'signed' ? pill('#ECFDF5', '#047857', 'Signed') : f.state === 'waiting' ? pill('#FEF3C7', '#92400E', 'Waiting for signature') : pill('#F1F5F9', '#475569', 'Withdrawn'); }
  function kv(k, v) { return '<div class="hr-kv"><span>' + esc(k) + '</span><b data-no-i18n>' + (v == null || v === '' ? '<span class="hr-sub">—</span>' : v) + '</b></div>'; }
  function field(label, inner, wide) { return '<div' + (wide ? ' style="grid-column:1/-1;"' : '') + '><label class="hr-l">' + esc(label) + '</label>' + inner + '</div>'; }
  function num(k, v, ph) { return '<input class="hr-i" data-f="' + k + '" type="number" min="0" step="0.01" inputmode="decimal" value="' + esc(v == null ? '' : v) + '" placeholder="' + esc(ph == null ? '' : ph) + '" style="width:100%;">'; }
  function check(k, v, label) { return '<label style="display:flex;gap:8px;align-items:center;font-size:13px;"><input type="checkbox" class="kt-nosw" data-f="' + k + '"' + (v ? ' checked' : '') + '> ' + esc(label) + '</label>'; }
  function val(box, k) { var e = box.querySelector('[data-f="' + k + '"]'); if (!e) return null; if (e.type === 'checkbox') return e.checked; var v = String(e.value || '').trim(); return v === '' ? null : v; }
  function numv(box, k) { var v = val(box, k); return v == null ? null : Number(v); }
  function msg(box, t) { var m = box.querySelector('[data-msg]'); if (m) m.textContent = t || ''; }
  function el(html) { var d = document.createElement('div'); d.innerHTML = html; return d; }
  function modal(o) { return KT.Shell.Modal.open(o); }
  function later(fn) { setTimeout(fn, 0); }
  var ERR = '<div data-msg style="color:#B91C1C;font-size:13px;margin-top:8px;"></div>';
  var GRID = 'display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:10px 12px;';
  function kebabBtn(key) { return '<button type="button" class="kt-ka-kebab hrx-keb" data-keb="' + esc(key) + '" aria-label="Actions" data-kt-iconized="1">⋮</button>'; }
  function menu(anchor, items) {
    var bs = items.filter(Boolean).map(function (it) { var b = document.createElement('button'); b.type = 'button'; b.textContent = it[0]; b.addEventListener('click', function () { it[1](); }); return b; });
    if (KT.openActionMenu) KT.openActionMenu(anchor, bs); else if (bs[0]) bs[0].click();
  }
  function css() {
    if (document.getElementById('kt-hrx-css')) return;
    var s = document.createElement('style'); s.id = 'kt-hrx-css';
    s.textContent = '.hrx-row{display:flex;gap:10px;align-items:center;padding:8px 2px;border-bottom:1px solid #F1F5F9;}.hrx-row:last-child{border-bottom:0;}.hrx-row .t{flex:1;min-width:0;}'
      + '.hrx-keb{width:30px;height:30px;border:1px solid #E2E8F0;border-radius:8px;background:#fff;cursor:pointer;font-size:16px;line-height:1;}'
      + '.hrx-res td{padding:4px 6px;border-bottom:1px solid #F1F5F9;font-size:13px;}.hrx-res td:last-child{text-align:right;font-weight:700;}'
      + 'html.kt-dark .hrx-row{border-color:#1E293B;}html.kt-dark .hrx-keb{background:#0F172A;border-color:#334155;color:#E2E8F0;}';
    document.head.appendChild(s);
  }
  function formLabel(f) { return (f.form === 'TP-1015.3' ? 'TD1 + TP-1015.3' : 'TD1' + (f.jurisdiction ? ' + TD1' + f.jurisdiction : '')) + ' ' + f.tax_year; }
  function formLine(f, keb) {
    var how = f.source === 'paper' ? 'Signed on paper' : 'Signed online';
    return '<div class="hrx-row"><div class="t"><b>' + esc(formLabel(f)) + '</b><div class="hr-sub">'
      + (f.signed_at ? esc(how + ' · ' + day(f.signed_at)) : 'Filled in ' + esc(day(f.created_at))) + '</div></div>' + state(f) + (keb ? kebabBtn(f.id) : '') + '</div>';
  }
  function withdraw(f, reload) {
    Api.post('/hr/payroll/tax/forms/' + f.id + '/withdraw', {}).then(function () { toast('✅', 'Withdrawn', '', '#16A34A'); reload(); }, function (e) { toast('⚠️', 'Could not withdraw', errMsg(e), '#DC2626'); });
  }

  /* The claim fields, shared by "Fill in my TD1" and "Record a paper TD1". */
  function claimFields(j, basics) {
    var qc = j === 'QC', b = basics || {};
    var h = '<div class="hr-sub" style="grid-column:1/-1;">Work out your amounts on the CRA ' + (qc ? 'TD1 and Revenu Québec TP-1015.3' : 'TD1 and TD1' + esc(j)) + ' worksheets (canada.ca' + (qc ? ', revenuquebec.ca' : '') + '). Leave an amount blank to use the basic personal amount.</div>'
      + field('Federal total claim amount (TD1 line 13)', num('fed_claim', null, b.federal_basic != null ? b.federal_basic : ''))
      + (qc ? field('Quebec: basic amount and other credits (line 7)', num('qc7', null, b.quebec_basic != null ? b.quebec_basic : ''))
        + field('Quebec: amounts transferred (line 9)', num('qc9', null, '0'))
        + field('Quebec: deductions (line 19)', num('qc19', null, '0'))
        + field('Extra Quebec tax from each pay', num('qc_extra', null, '0'))
        : field('Provincial total claim amount (TD1' + esc(j) + ')', num('prov_claim', null, b.provincial_basic != null ? b.provincial_basic : '')))
      + field('Extra federal tax from each pay', num('fed_extra', null, '0'))
      + field('Prescribed-zone deduction (annual)', num('zone', null, '0'))
      + '<div style="grid-column:1/-1;display:grid;gap:6px;">' + check('more', false, 'I have more than one employer at the same time and already claimed my amounts with another (claim = $0)')
      + check('fed_exempt', false, 'My total income this year will be less than my federal claim amount') + '</div>';
    return h;
  }
  function claimBody(box, j) {
    var o = { federal: { total_claim: numv(box, 'fed_claim'), additional_tax: numv(box, 'fed_extra'), zone_deduction: numv(box, 'zone'), more_than_one_employer: !!val(box, 'more'), exempt: !!val(box, 'fed_exempt') } };
    if (j === 'QC') o.quebec = { line7: numv(box, 'qc7'), line9: numv(box, 'qc9'), line19: numv(box, 'qc19'), additional_tax: numv(box, 'qc_extra') };
    else o.provincial = { total_claim: numv(box, 'prov_claim') };
    return o;
  }

  /* ── My HR > Tax forms ── */
  async function mine(host) {
    var r;
    try { r = await Api.get('/hr/me/tax-forms'); } catch (e) { return; }
    if (!r || !r.enabled || !host.isConnected) return;
    css();
    var wrap = document.createElement('div'); wrap.setAttribute('data-hrx-mine', '1'); host.appendChild(wrap);
    var reload = function () { if (wrap.isConnected) { wrap.remove(); mine(host); } };
    var forms = (r.forms || []).filter(function (f) { return f.state !== 'withdrawn'; });
    var inForce = r.in_force && r.in_force.form;
    var h = '<div class="hrt-sec">Tax forms</div><div class="kt-card" style="padding:10px 14px;" data-kt-nogrid>';
    if (!r.jurisdiction) h += '<div class="hr-sub">Your employer has not set the province you work in yet, so your TD1 cannot be filled in here. Ask your HR admin.</div>';
    else {
      h += kv('Works in', esc(r.jurisdiction_name || r.jurisdiction)) + kv('In force for ' + r.year, inForce ? esc(formLabel(inForce)) + ' · signed ' + esc(day(inForce.signed_at)) : '<span class="hr-sub">No signed TD1: the basic personal amounts are used</span>');
      h += forms.length ? '<div style="margin-top:6px;">' + forms.map(function (f) { return formLine(f, f.state === 'waiting'); }).join('') + '</div>' : '';
      h += '<div class="hr-bar" style="margin:10px 0 0;">' + (r.years || []).map(function (y) { return '<button type="button" class="hr-b" data-td1="' + y + '" data-kt-iconized="1">Fill in my ' + y + ' TD1</button>'; }).join(' ')
        + (forms.some(function (f) { return f.state === 'waiting'; }) ? ' <button type="button" class="hr-b" data-myforms data-kt-iconized="1">Sign it in My forms</button>' : '') + '</div>';
    }
    wrap.innerHTML = h + '</div>';
    wrap.querySelectorAll('[data-td1]').forEach(function (b) { b.addEventListener('click', function () { openTd1(r, +b.getAttribute('data-td1'), reload); }); });
    var mf = wrap.querySelector('[data-myforms]'); if (mf) mf.addEventListener('click', function () { location.hash = '#my-forms'; });
    wrap.querySelectorAll('[data-keb]').forEach(function (b) {
      b.addEventListener('click', function () {
        var f = forms.filter(function (x) { return String(x.id) === b.getAttribute('data-keb'); })[0]; if (!f) return;
        menu(b, [['Sign it in My forms', function () { location.hash = '#my-forms'; }], ['Withdraw', function () { withdraw(f, reload); }]]);
      });
    });
  }
  function openTd1(r, year, reload) {
    var j = r.jurisdiction, basics = year === r.year ? r.basics : r.next_basics;
    var box = el('<div style="' + GRID + '">' + claimFields(j, basics) + '<div style="grid-column:1/-1;">' + check('certify', false, 'I certify that the information given is correct and complete') + '</div></div>' + ERR);
    modal({ title: 'My ' + year + ' TD1' + (j === 'QC' ? ' and TP-1015.3' : ''), body: box, actions: [
      { label: 'Cancel' },
      { label: 'Continue to sign', primary: true, handler: function () {
        if (!val(box, 'certify')) { msg(box, 'Tick the box to certify the information.'); return false; }
        var o = claimBody(box, j); o.tax_year = year; o.certify = true;
        Api.post('/hr/me/tax-forms', o).then(function () {
          toast('✅', 'Ready to sign', 'Your TD1 is in My forms. Sign it there to put it in force.', '#16A34A');
          later(function () { location.hash = '#my-forms'; });
        }, function (e) { msg(box, errMsg(e)); });
        return false;
      } }
    ] });
  }

  /* ── HR profile > Payroll tax setup (agency admins) ── */
  async function profile(host, id) {
    var r;
    try { r = await Api.get('/hr/payroll/tax/people/' + id); } catch (e) { return; }
    if (!r || !r.enabled || !host.isConnected) return;
    css();
    var p = r.profile || {}, reload = function () { if (host.isConnected) { host.innerHTML = ''; profile(host, id); } };
    var freq = p.pay_frequency ? r.frequencies[p.pay_frequency] : (p.agency_pay_frequency ? (r.frequencies[p.agency_pay_frequency] || p.agency_pay_frequency) + ' (agency schedule)' : null);
    var j = p.jurisdiction ? (r.jurisdictions[p.jurisdiction] || p.jurisdiction) + (p.jurisdiction_from_centre && p.default_centre ? ' (from ' + p.default_centre + ')' : '') : null;
    var cpp = p.cpp_exempt_reason ? 'Exempt: ' + (r.cpp_reasons[p.cpp_exempt_reason] || p.cpp_exempt_reason) : (p.pensionable_months < 12 ? p.pensionable_months + ' of 12 months this year (age)' : 'Deducted');
    var forms = (r.forms || []).filter(function (f) { return f.state !== 'withdrawn'; });
    var h = '<div class="hr-pc"><h4>Payroll tax setup</h4>'
      + kv('Province of employment', j ? esc(j) : null) + kv('Pay frequency', freq ? esc(freq) : null)
      + kv(p.jurisdiction === 'QC' ? 'QPP' : 'CPP', esc(cpp)) + kv('EI', p.ei_exempt ? 'Exempt' : 'Deducted') + (p.jurisdiction === 'QC' ? kv('QPIP', p.qpip_exempt ? 'Exempt' : 'Deducted') : '')
      + kv('Vacation pay', p.vacation_pay_pct != null ? esc(p.vacation_pay_pct + '% each pay') : 'Not paid each pay')
      + kv('SIN', r.sin_on_file ? 'On file (ending ' + esc(r.sin_on_file) + ')' : null)
      + (p.warnings || []).map(function (w) { return '<div class="hr-sub" style="color:#92400E;margin-top:4px;">' + esc(w) + '</div>'; }).join('')
      + '<div style="margin-top:6px;">' + (forms.length ? forms.map(function (f) { return formLine(f, f.state === 'waiting'); }).join('') : '<span class="hr-sub">No TD1 yet: the basic personal amounts are used.</span>') + '</div>'
      + (r.own ? '<div class="hr-sub" style="margin-top:8px;">Another admin sets up your own payroll tax details.</div>'
        : '<div class="hr-bar" style="margin:8px 0 0;"><button type="button" class="hr-b" data-x="edit" data-kt-iconized="1">Edit tax setup</button> <button type="button" class="hr-b" data-x="preview" data-kt-iconized="1">Preview a pay</button> <button type="button" class="hr-b" data-x="paper" data-kt-iconized="1">Record a paper TD1</button></div>')
      + '</div>';
    host.innerHTML = h;
    host.querySelectorAll('[data-x]').forEach(function (b) {
      b.addEventListener('click', function () { var x = b.getAttribute('data-x'); if (x === 'edit') openSetup(r, id, reload); else if (x === 'preview') openPreview(r, id); else openPaper(r, id, reload); });
    });
    host.querySelectorAll('[data-keb]').forEach(function (b) {
      b.addEventListener('click', function () {
        var f = forms.filter(function (x) { return String(x.id) === b.getAttribute('data-keb'); })[0]; if (!f) return;
        menu(b, [['Withdraw', function () { withdraw(f, reload); }]]);
      });
    });
  }
  function opts(map, v, blank) {
    return (blank != null ? '<option value="">' + esc(blank) + '</option>' : '') + Object.keys(map || {}).map(function (k) { return '<option value="' + esc(k) + '"' + (String(v) === String(k) ? ' selected' : '') + '>' + esc(map[k]) + '</option>'; }).join('');
  }
  function openSetup(r, id, reload) {
    var p = r.profile || {};
    var box = el('<div style="' + GRID + '">'
      + field('Province of employment', '<select class="hr-i" data-f="jurisdiction" style="width:100%;">' + opts(r.jurisdictions, p.jurisdiction_from_centre ? '' : p.jurisdiction, 'From their main centre' + (p.default_jurisdiction ? ' (' + p.default_jurisdiction + ')' : '')) + '</select>')
      + field('Pay frequency', '<select class="hr-i" data-f="pay_frequency" style="width:100%;">' + opts(r.frequencies, p.pay_frequency, 'Agency pay schedule') + '</select>')
      + field('CPP / QPP', '<select class="hr-i" data-f="cpp_exempt_reason" style="width:100%;">' + opts(r.cpp_reasons, p.cpp_exempt_reason, 'Deducted (age limits apply automatically)') + '</select>')
      + field('Vacation pay paid each pay (%)', num('vacation_pay_pct', p.vacation_pay_pct, 'Not paid each pay'))
      + field('Prescribed-zone deduction (annual)', num('annual_zone_deduction', p.annual_zone_deduction, '0'))
      + '<div style="display:grid;gap:6px;align-content:end;">' + check('ei_exempt', p.ei_exempt, 'EI exempt') + check('qpip_exempt', p.qpip_exempt, 'QPIP exempt (Quebec)') + '</div>'
      + field('Notes', '<input class="hr-i" data-f="notes" maxlength="500" value="' + esc(p.notes || '') + '" style="width:100%;">', true)
      + '</div>' + ERR);
    modal({ title: 'Payroll tax setup', body: box, actions: [
      { label: 'Cancel' },
      { label: 'Save', primary: true, handler: function () {
        var o = { jurisdiction: val(box, 'jurisdiction'), pay_frequency: val(box, 'pay_frequency') ? +val(box, 'pay_frequency') : null, cpp_exempt_reason: val(box, 'cpp_exempt_reason'),
          vacation_pay_pct: numv(box, 'vacation_pay_pct'), annual_zone_deduction: numv(box, 'annual_zone_deduction'), ei_exempt: !!val(box, 'ei_exempt'), qpip_exempt: !!val(box, 'qpip_exempt'), notes: val(box, 'notes') };
        Api.put('/hr/payroll/tax/people/' + id, o).then(function () { toast('✅', 'Saved', '', '#16A34A'); later(function () { document.querySelector('#modalRoot .modal-close') && document.querySelector('#modalRoot .modal-close').click(); reload(); }); }, function (e) { msg(box, errMsg(e)); });
        return false;
      } }
    ] });
  }
  function openPreview(r, id) {
    var box = el('<div style="' + GRID + '">' + field('Gross pay for one pay', num('gross', null, 'e.g. 1500')) + field('Pay date', '<input class="hr-i" data-f="pay_date" type="date" value="' + esc(today()) + '" style="width:100%;">') + '</div>' + ERR + '<div data-res style="margin-top:10px;"></div>');
    modal({ title: 'Preview a pay', body: box, actions: [
      { label: 'Close' },
      { label: 'Work it out', primary: true, handler: function () {
        var g = numv(box, 'gross'); if (g == null) { msg(box, 'Enter the gross pay.'); return false; }
        msg(box, '');
        Api.post('/hr/payroll/tax/people/' + id + '/preview', { gross: g, pay_date: val(box, 'pay_date') }).then(function (x) {
          var e = x.result.employee, er = x.result.employer, qc = x.result.plan === 'QPP';
          var rows = [['Gross (with vacation pay)', e.gross], [qc ? 'QPP' : 'CPP', e.cpp], [qc ? 'QPP2' : 'CPP2', e.cpp2], ['EI', e.ei]].concat(qc ? [['QPIP', e.qpip]] : [])
            .concat([['Federal tax', e.federal_tax], [qc ? 'Quebec tax' : 'Provincial tax', e.provincial_tax], ['Net pay', e.net], ['Employer ' + (qc ? 'QPP' : 'CPP'), er.cpp + er.cpp2], ['Employer EI', er.ei]]).concat(qc ? [['Employer QPIP', er.qpip]] : []);
          box.querySelector('[data-res]').innerHTML = '<table class="hrx-res" style="width:100%;border-collapse:collapse;">' + rows.map(function (rw) { return '<tr><td>' + esc(rw[0]) + '</td><td data-no-i18n>' + money(rw[1]) + '</td></tr>'; }).join('') + '</table>'
            + '<div class="hr-sub" style="margin-top:6px;">' + esc(x.note) + ' Tables: ' + esc(x.result.table_version) + '.</div>'
            + (x.warnings || []).map(function (w) { return '<div class="hr-sub" style="color:#92400E;">' + esc(w) + '</div>'; }).join('');
        }, function (err) { msg(box, errMsg(err)); });
        return false;
      } }
    ] });
  }
  function openPaper(r, id, reload) {
    var p = r.profile || {};
    if (!p.jurisdiction) { toast('⚠️', 'Set the province first', 'Edit the tax setup, then record the TD1.', '#DC2626'); return; }
    var box = el('<div style="' + GRID + '">' + field('Tax year', '<input class="hr-i" data-f="tax_year" type="number" min="2026" value="' + esc(r.year) + '" style="width:100%;">')
      + field('Signed on', '<input class="hr-i" data-f="signed_on" type="date" value="' + esc(today()) + '" style="width:100%;">') + claimFields(p.jurisdiction, r.basics) + '</div>' + ERR);
    modal({ title: 'Record a paper TD1', body: box, actions: [
      { label: 'Cancel' },
      { label: 'Save', primary: true, handler: function () {
        var o = claimBody(box, p.jurisdiction); o.tax_year = +val(box, 'tax_year'); o.signed_on = val(box, 'signed_on');
        Api.post('/hr/payroll/tax/people/' + id + '/paper', o).then(function () { toast('✅', 'Saved', 'Keep the signed paper form on file.', '#16A34A'); later(function () { document.querySelector('#modalRoot .modal-close') && document.querySelector('#modalRoot .modal-close').click(); reload(); }); }, function (e) { msg(box, errMsg(e)); });
        return false;
      } }
    ] });
  }


  /* ═══ PAYROLL ENGINE, PHASE 2 (2026-11-27) ═══════════════════════════════════════════════════════════════════════
     HR settings > "Payroll engine" card (agency admins): the switch, the one-time "check with your accountant" notice,
     the CRA / Revenu Québec numbers, the remitter type and the workers' compensation rate (report only).
     Payroll > "Pay runs": draft -> review -> approve -> finalize, one line per person (hours from the clock, every
     deduction, the table version); a finalized run is never changed - an adjustment run corrects it.
     Payroll > "Remittances": what is owed to the CRA (and Revenu Québec) per remitting period, with the due date.
     Payroll > "Opening balances" (agency admins): year-to-date figures from a previous payroll, for mid-year starts.
     My HR > "My payslips". Server: App\Http\Controllers\Api\PayrollRunController (+ PayrollDocumentController::officeView). */
  var STATUS = { draft: ['#F1F5F9', '#475569', 'Draft'], review: ['#FEF3C7', '#92400E', 'In review'], approved: ['#E0F2FE', '#075985', 'Approved'],
    finalized: ['#ECFDF5', '#047857', 'Finalized'], discarded: ['#FEE2E2', '#991B1B', 'Discarded'] };
  function runPill(s) { var p = STATUS[s] || STATUS.draft; return pill(p[0], p[1], p[2]); }
  function period(a, b) { return day(a) + ' – ' + day(b); }
  function table(head, rows, attr) {
    return '<div style="overflow-x:auto;"><table class="hrx-t"' + (attr || '') + '><thead><tr>' + head.map(function (h) { return '<th' + (h.r ? ' style="text-align:right;"' : '') + '>' + esc(h.t || h) + '</th>'; }).join('') + '</tr></thead><tbody>' + rows.join('') + '</tbody></table></div>';
  }
  function td(v, right, raw) { return '<td' + (right ? ' style="text-align:right;white-space:nowrap;"' : '') + '>' + (raw ? v : esc(v)) + '</td>'; }
  function css2() {
    css();
    if (document.getElementById('kt-hrx2-css')) return;
    var s = document.createElement('style'); s.id = 'kt-hrx2-css';
    s.textContent = '.hrx-t{width:100%;border-collapse:collapse;font-size:13px;}.hrx-t th{text-align:left;font-size:11.5px;color:#64748B;font-weight:800;padding:6px 8px;border-bottom:1px solid #E2E8F0;white-space:nowrap;}'
      + '.hrx-t td{padding:7px 8px;border-bottom:1px solid #F1F5F9;vertical-align:top;}.hrx-t tr[data-open]{cursor:pointer;}.hrx-t tr[data-open]:hover td{background:#F8FAFC;}'
      + '.hrx-tot{display:grid;grid-template-columns:repeat(auto-fit,minmax(130px,1fr));gap:8px;margin:10px 0;}.hrx-tot div{border:1px solid #E2E8F0;border-radius:10px;padding:8px 10px;background:#fff;}'
      + '.hrx-tot span{display:block;font-size:11.5px;color:#64748B;font-weight:700;}.hrx-tot b{font-size:15px;}.hrx-w{color:#92400E;font-size:12px;margin-top:2px;}.hrx-e{color:#B91C1C;font-size:12px;margin-top:2px;}'
      + 'html.kt-dark .hrx-t th{border-color:#334155;color:#94A3B8;}html.kt-dark .hrx-t td{border-color:#1E293B;}html.kt-dark .hrx-t tr[data-open]:hover td{background:#1E293B;}'
      + 'html.kt-dark .hrx-tot div{background:#0F172A;border-color:#334155;}html.kt-dark .hrx-w{color:#FCD34D;}';
    document.head.appendChild(s);
  }
  function offNote(host, admin) {
    host.innerHTML = '<div class="kt-card" style="padding:14px 16px;" data-kt-nogrid><div class="hr-sub">Payroll is not switched on for this agency. '
      + (admin ? 'Switch it on in HR settings &rarr; Payroll engine.' : 'An agency admin can switch it on in HR settings.') + '</div></div>';
  }
  function centreOpts(r, v) {
    var h = r.agency_wide ? '<option value="">Every centre</option>' : '';
    Object.keys(r.centres || {}).forEach(function (k) { h += '<option value="' + esc(k) + '"' + (String(v) === k ? ' selected' : '') + '>' + esc(r.centres[k]) + '</option>'; });
    return h;
  }
  function date(k, v) { return '<input class="hr-i" data-f="' + k + '" type="date" value="' + esc(v || '') + '" style="width:100%;">'; }

  /* The payslip itself: the office view (no password, for reading here) with Download = the payslip file. */
  function openSlip(docId, title) {
    var base = KT.API_BASE || 'https://api.kiddietrac.com/api/v1';
    var tok = sessionStorage.getItem('kt_token') || localStorage.getItem('kt_token') || '';
    var get = function (path) { return fetch(base + (path === '/view' ? '/provider' : '') + '/payroll-documents/' + docId + path, { headers: { Authorization: 'Bearer ' + tok, Accept: 'application/pdf' }, cache: 'no-store' }).then(function (r) { if (!r.ok) throw new Error('HTTP ' + r.status); return r.blob(); }); };
    var save = function () {
      get('/pdf').then(function (b) { var u = URL.createObjectURL(b), a = document.createElement('a'); a.href = u; a.download = 'payslip-' + docId + '.pdf'; document.body.appendChild(a); a.click(); a.remove(); setTimeout(function () { URL.revokeObjectURL(u); }, 30000); },
        function () { toast('⚠️', 'Could not download the payslip', 'Please try again.', '#DC2626'); });
    };
    get('/view').then(function (b) { KT.openFile(b, { title: title || 'Payslip', filename: 'payslip-' + docId + '.pdf', label: 'Payslip', protectedDownload: save }); },
      function () { toast('⚠️', 'Could not open the payslip', 'Please try again.', '#DC2626'); });
  }

  // ── Pay runs ────────────────────────────────────────────────────────────────────────────────────────────────────
  async function drawRuns(host, openId) {
    css2();
    host.innerHTML = '<div class="hr-sub" style="padding:12px 2px;">Loading…</div>';
    var r;
    try { r = await Api.get('/hr/payroll/runs'); } catch (e) { host.innerHTML = '<div class="hr-note">' + esc(errMsg(e)) + '</div>'; return; }
    if (!host.isConnected) return;
    if (!r.enabled) return offNote(host, true);
    if (openId) return drawRun(host, openId, r);
    var rows = (r.runs || []).map(function (x) {
      var t = x.totals || {};
      return '<tr data-open="' + x.id + '">' + td('<b data-no-i18n>' + esc(x.reference) + '</b>' + (x.kind === 'adjustment' ? '<div class="hr-sub">Adjustment</div>' : '') + (x.centre ? '<div class="hr-sub" data-no-i18n>' + esc(x.centre) + '</div>' : ''), false, true)
        + td(period(x.period_start, x.period_end)) + td(day(x.pay_date)) + td(runPill(x.status), false, true) + td(String(t.people || 0), true)
        + td(money(t.gross), true) + td(money(t.net), true) + td(kebabBtn(x.id), true, true) + '</tr>';
    });
    host.innerHTML = '<div class="kt-card" style="padding:12px 14px;" data-kt-nogrid>'
      + '<div class="hr-bar" style="margin:0 0 8px;"><b style="font-size:14px;">Pay runs ' + esc(r.year) + '</b><span style="flex:1"></span>'
      + (r.acknowledged ? '<button type="button" class="hr-b p" data-new data-kt-iconized="1">＋ New pay run</button>' : '<span class="hr-sub">An agency admin accepts the notice in HR settings before the first run.</span>') + '</div>'
      + (rows.length ? table(['Run', 'Period', 'Pay date', 'Status', { t: 'People', r: 1 }, { t: 'Gross', r: 1 }, { t: 'Net', r: 1 }, ''], rows) : '<div class="hr-sub" style="padding:10px 0;">No pay runs yet.</div>')
      + '</div>';
    var nb = host.querySelector('[data-new]'); if (nb) nb.addEventListener('click', function () { newRun(host, r); });
    host.querySelectorAll('tr[data-open]').forEach(function (tr) {
      tr.addEventListener('click', function (e) { if (e.target.closest('[data-keb]')) return; drawRun(host, +tr.getAttribute('data-open'), r); });
    });
    host.querySelectorAll('[data-keb]').forEach(function (b) {
      b.addEventListener('click', function (e) {
        e.stopPropagation();
        var x = (r.runs || []).filter(function (y) { return String(y.id) === b.getAttribute('data-keb'); })[0]; if (!x) return;
        menu(b, [['Open', function () { drawRun(host, x.id, r); }],
          x.status === 'finalized' && x.kind === 'regular' ? ['Make an adjustment', function () { adjustRun(host, x); }] : null,
          x.status !== 'finalized' ? ['Discard', function () { discardRun(host, x); }] : null]);
      });
    });
  }
  function newRun(host, r) {
    var s = r.suggest || {};
    var fr = r.frequencies || {};
    var box = el('<div style="' + GRID + '">'
      + field('Centre', '<select class="hr-i" data-f="centre_id" style="width:100%;">' + centreOpts(r, '') + '</select>')
      + field('Pay periods a year', '<select class="hr-i" data-f="periods" style="width:100%;">' + Object.keys(fr).map(function (k) { return '<option value="' + k + '"' + (String(r.periods) === k ? ' selected' : '') + '>' + esc(fr[k]) + '</option>'; }).join('') + '</select>')
      + field('Period starts', date('period_start', s.period_start)) + field('Period ends', date('period_end', s.period_end)) + field('Pay date', date('pay_date', s.pay_date))
      + field('Note (optional)', '<input class="hr-i" data-f="notes" maxlength="1000" style="width:100%;">', true)
      + '</div><div class="hr-sub" style="margin-top:8px;">Hours come from the clock (timesheets). Nothing is paid or sent until the run is approved and finalized.</div>' + ERR);
    modal({ title: '💼 New pay run', body: box, actions: [{ label: 'Cancel' }, { label: 'Start the run', primary: true, busyLabel: 'Working…', handler: function () {
      var o = { kind: 'regular', centre_id: val(box, 'centre_id') ? +val(box, 'centre_id') : null, periods: +val(box, 'periods') || null,
        period_start: val(box, 'period_start'), period_end: val(box, 'period_end'), pay_date: val(box, 'pay_date'), notes: val(box, 'notes') };
      return Api.post('/hr/payroll/runs', o).then(function (x) { drawRun(host, x.id, r, x); }, function (e) { msg(box, errMsg(e)); return false; });
    } }] });
  }
  function adjustRun(host, x) {
    var box = el('<div style="' + GRID + '">' + field('Pay date', date('pay_date', today())) + field('Why (optional)', '<input class="hr-i" data-f="notes" maxlength="1000" style="width:100%;">', true)
      + '</div><div class="hr-sub" style="margin-top:8px;">An adjustment pays what changed since ' + esc(x.reference) + ' was finalized (late clock-in fixes, a missed person). ' + esc(x.reference) + ' itself stays as it is.</div>' + ERR);
    modal({ title: '💼 Adjust ' + x.reference, body: box, actions: [{ label: 'Cancel' }, { label: 'Start the adjustment', primary: true, busyLabel: 'Working…', handler: function () {
      return Api.post('/hr/payroll/runs', { kind: 'adjustment', adjusts_run_id: x.id, pay_date: val(box, 'pay_date'), notes: val(box, 'notes') })
        .then(function (n) { drawRuns(host, n.id); }, function (e) { msg(box, errMsg(e)); return false; });
    } }] });
  }
  function discardRun(host, x) {
    var go = function () { Api.delete('/hr/payroll/runs/' + x.id).then(function () { toast('🗑️', 'Pay run discarded', x.reference); drawRuns(host); }, function (e) { toast('⚠️', 'Could not discard', errMsg(e), '#DC2626'); }); };
    if (KT.confirm) KT.confirm({ title: 'Discard ' + x.reference + '?', description: 'Nobody is paid by it. This cannot be undone.', okLabel: 'Discard', tone: 'danger' }).then(function (ok) { if (ok) go(); });
    else go();
  }
  async function drawRun(host, id, list, pre) {
    css2();
    var r = pre;
    if (!r) { try { r = await Api.get('/hr/payroll/runs/' + id); } catch (e) { toast('⚠️', 'Could not open the pay run', errMsg(e), '#DC2626'); return; } }
    if (!host.isConnected) return;
    var t = r.totals || {}, can = r.can || {};
    var redraw = function (x) { drawRun(host, id, list, x); };
    var act = function (path, okT, body) { return Api.post('/hr/payroll/runs/' + id + path, body || {}).then(function (x) { if (okT) toast('✅', okT, r.reference, '#16A34A'); redraw(x); }, function (e) { toast('⚠️', 'Not done', errMsg(e), '#DC2626'); }); };
    var lines = (r.lines || []).map(function (l) {
      var w = (l.warnings || []).map(function (m) { return '<div class="hrx-w">' + esc(m) + '</div>'; }).join('') + (l.error ? '<div class="hrx-e">' + esc(l.error) + '</div>' : '');
      var off = !l.included;
      return '<tr' + (off ? ' style="opacity:.55;"' : '') + '>' + td('<b data-no-i18n>' + esc(l.name) + '</b>' + (off ? ' <span class="hr-sub">left out</span>' : '') + (l.note ? '<div class="hr-sub" data-no-i18n>' + esc(l.note) + '</div>' : '') + w, false, true)
        + td(l.pay_type === 'salary' ? 'Salary' : (Number(l.hours) || 0).toFixed(2) + ' h', true) + td(money(l.gross), true) + td(money((+l.cpp || 0) + (+l.cpp2 || 0)), true)
        + td(money(l.ei), true) + td(money(l.income_tax), true) + td('<b>' + money(l.net) + '</b>', true, true) + td(kebabBtn(l.id), true, true) + '</tr>';
    });
    var btn = function (k, label, p) { return '<button type="button" class="hr-b' + (p ? ' p' : '') + '" data-ra="' + k + '" data-kt-iconized="1">' + esc(label) + '</button> '; };
    host.innerHTML = '<div class="kt-card" style="padding:12px 14px;" data-kt-nogrid>'
      + '<div class="hr-bar" style="margin:0 0 6px;"><button type="button" class="hr-b" data-ra="list" data-kt-iconized="1">‹ All pay runs</button><span style="flex:1"></span>'
      + (can.edit ? btn('refresh', 'Recalculate') : '') + (can.submit ? btn('submit', 'Send for review', true) : '') + (can.send_back ? btn('return', 'Send to draft') : '')
      + (can.approve ? btn('approve', 'Approve', true) : '') + (can.finalize ? btn('finalize', 'Finalize and issue payslips', true) : '')
      + (can.adjust ? btn('adjust', 'Make an adjustment') : '') + btn('print', 'Print') + '</div>'
      + '<div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;"><b style="font-size:16px;" data-no-i18n>' + esc(r.reference) + '</b>' + runPill(r.status)
      + (r.kind === 'adjustment' ? pill('#EDE9FE', '#5B21B6', 'Adjustment to ' + (r.adjusts || '')) : '') + '<span class="hr-sub">' + esc(period(r.period_start, r.period_end)) + ' · pay date ' + esc(day(r.pay_date)) + (r.centre ? ' · ' + esc(r.centre) : '') + '</span></div>'
      + (r.return_reason && r.status === 'draft' ? '<div class="hrx-w" style="margin-top:6px;">Sent back: ' + esc(r.return_reason) + '</div>' : '')
      + (r.paid_here && r.status === 'review' ? '<div class="hrx-w" style="margin-top:6px;">You are paid in this run, so another manager approves it.</div>' : '')
      + '<div class="hrx-tot"><div><span>People</span><b>' + esc(t.people || 0) + '</b></div><div><span>Gross</span><b>' + money(t.gross) + '</b></div><div><span>Net pay</span><b>' + money(t.net) + '</b></div>'
      + '<div><span>Employer contributions</span><b>' + money(t.employer) + '</b></div><div><span>Owed to the CRA</span><b>' + money(t.remit_cra) + '</b></div>' + (+t.remit_rq ? '<div><span>Owed to Revenu Québec</span><b>' + money(t.remit_rq) + '</b></div>' : '') + '</div>'
      + ((+t.errors) ? '<div class="hrx-e" style="margin-bottom:6px;">' + esc(t.errors) + ' line(s) need fixing (or leaving out) before the run can be finalized.</div>' : '')
      + table(['Person', { t: 'Hours', r: 1 }, { t: 'Gross', r: 1 }, { t: 'CPP', r: 1 }, { t: 'EI', r: 1 }, { t: 'Income tax', r: 1 }, { t: 'Net', r: 1 }, ''], lines, ' data-hrx-lines')
      + '<div class="hr-sub" style="margin-top:8px;">' + (r.lines && r.lines[0] && r.lines[0].table_version ? 'Worked out with the CRA tables ' + esc(r.lines[0].table_version) + '. ' : '')
      + 'Hours are paid time from the clock. Every figure is kept with the line, so a finalized run always shows what it paid.</div></div>';
    host.querySelectorAll('[data-ra]').forEach(function (b) {
      b.addEventListener('click', function () {
        var k = b.getAttribute('data-ra');
        if (k === 'list') return drawRuns(host);
        if (k === 'refresh') return act('/refresh', 'Recalculated');
        if (k === 'submit') return act('/submit', 'Sent for review');
        if (k === 'approve') return act('/approve', 'Approved');
        if (k === 'adjust') return adjustRun(host, r);
        if (k === 'print') return printRun(r);
        if (k === 'return') {
          KT.prompt({ title: 'Send ' + r.reference + ' to draft', fields: [{ key: 'reason', label: 'What needs changing (optional)', type: 'textarea', rows: 3 }], okLabel: 'Send to draft' }).then(function (v) {
            if (v === null || v === undefined) return; act('/return', 'Sent to draft', { reason: typeof v === 'string' ? v : (v.reason || '') });
          });
          return;
        }
        if (k === 'finalize') {
          var go = function () { act('/finalize', 'Finalized: payslips issued'); };
          if (KT.confirm) KT.confirm({ title: 'Finalize ' + r.reference + '?', description: 'Each person gets their payslip in the app and an email saying it is ready (no amounts in the email). A finalized run cannot be changed: corrections go in an adjustment run.', okLabel: 'Finalize' }).then(function (ok) { if (ok) go(); });
          else go();
        }
      });
    });
    host.querySelectorAll('[data-keb]').forEach(function (b) {
      b.addEventListener('click', function () {
        var l = (r.lines || []).filter(function (x) { return String(x.id) === b.getAttribute('data-keb'); })[0]; if (!l) return;
        menu(b, [l.payroll_document_id ? ['View payslip', function () { openSlip(l.payroll_document_id, 'Payslip · ' + l.name); }] : null,
          can.edit ? ['Edit this line', function () { editLine(r, l, redraw); }] : null,
          can.edit ? [l.included ? 'Leave out of this run' : 'Put back in this run', function () {
            Api.put('/hr/payroll/runs/' + id + '/lines/' + l.id, { included: !l.included }).then(redraw, function (e) { toast('⚠️', 'Not saved', errMsg(e), '#DC2626'); });
          }] : null,
          ['How it was worked out', function () { lineDetail(l); }]]);
      });
    });
  }
  function editLine(r, l, redraw) {
    var box = el('<div style="' + GRID + '">' + field('Bonus (taxed by the bonus method)', num('bonus', l.bonus || '')) + field('Taxable benefit paid in cash', num('benefits_cash', l.benefits_cash || ''))
      + field('Taxable benefit not paid in cash', num('benefits_noncash', l.benefits_noncash || '')) + field('Deduction after tax', num('other_deduction', l.other_deduction || ''))
      + field('What the deduction is for', '<input class="hr-i" data-f="other_label" maxlength="120" value="' + esc(l.other_label || '') + '" style="width:100%;">')
      + field('Note on the line (optional)', '<input class="hr-i" data-f="note" maxlength="500" value="' + esc(l.note || '') + '" style="width:100%;">', true) + '</div>' + ERR);
    modal({ title: '✏️ ' + l.name + ' · ' + r.reference, body: box, actions: [{ label: 'Cancel' }, { label: 'Save and recalculate', primary: true, busyLabel: 'Working…', handler: function () {
      var o = { bonus: numv(box, 'bonus') || 0, benefits_cash: numv(box, 'benefits_cash') || 0, benefits_noncash: numv(box, 'benefits_noncash') || 0,
        other_deduction: numv(box, 'other_deduction') || 0, other_label: val(box, 'other_label'), note: val(box, 'note') };
      return Api.put('/hr/payroll/runs/' + r.id + '/lines/' + l.id, o).then(function (x) { redraw(x); }, function (e) { msg(box, errMsg(e)); return false; });
    } }] });
  }
  function lineDetail(l) {
    var y = l.ytd_before || {};
    var rows = [['Regular pay', money(l.regular)], ['Overtime', money(l.overtime_pay)], ['Vacation pay', money(l.vacation_pay)], ['Bonus', money(l.bonus)], ['Taxable benefits', money((+l.benefits_cash || 0) + (+l.benefits_noncash || 0))],
      ['Gross', money(l.gross)], ['CPP' + (+l.cpp2 ? ' + CPP2' : ''), money((+l.cpp || 0) + (+l.cpp2 || 0))], ['EI', money(l.ei)], +l.qpip ? ['QPIP', money(l.qpip)] : null,
      ['Federal tax', money(l.federal_tax)], ['Provincial tax', money(l.provincial_tax)], +l.other_deduction ? [(l.other_label || 'Deduction after tax'), money(l.other_deduction)] : null,
      +l.reimbursements ? ['Claims repaid', money(l.reimbursements)] : null, ['Net pay', money(l.net)],
      ['Year to date before this pay', money(y.gross) + ' gross · ' + money(y.cpp) + ' CPP · ' + money(y.ei) + ' EI · ' + money(y.income_tax) + ' tax'],
      ['Rate', l.pay_type === 'salary' ? money(l.rate) + ' a year' : money(l.rate) + ' an hour'], ['Province', l.jurisdiction || '—'], ['CRA tables', l.table_version || '—']].filter(Boolean);
    var box = el('<table class="hrx-res" style="width:100%;">' + rows.map(function (x) { return '<tr><td>' + esc(x[0]) + '</td><td data-no-i18n>' + esc(x[1]) + '</td></tr>'; }).join('') + '</table>'
      + (l.warnings || []).map(function (m) { return '<div class="hrx-w">' + esc(m) + '</div>'; }).join(''));
    css2();
    modal({ title: '🧮 ' + l.name, body: box, actions: [{ label: 'Close', primary: true }] });
  }
  function printRun(r) {
    var t = r.totals || {};
    var h = '<h2 style="margin:0 0 4px;">' + esc(r.reference) + ' · ' + esc((STATUS[r.status] || STATUS.draft)[2]) + '</h2><div>' + esc(period(r.period_start, r.period_end)) + ' · pay date ' + esc(day(r.pay_date)) + '</div>'
      + '<table style="width:100%;border-collapse:collapse;margin-top:12px;font-size:12px;"><tr><th align="left">Person</th><th align="right">Hours</th><th align="right">Gross</th><th align="right">CPP</th><th align="right">EI</th><th align="right">Tax</th><th align="right">Net</th></tr>'
      + (r.lines || []).filter(function (l) { return l.included; }).map(function (l) { return '<tr><td>' + esc(l.name) + '</td><td align="right">' + (l.pay_type === 'salary' ? 'Salary' : (+l.hours || 0).toFixed(2)) + '</td><td align="right">' + money(l.gross) + '</td><td align="right">' + money((+l.cpp || 0) + (+l.cpp2 || 0)) + '</td><td align="right">' + money(l.ei) + '</td><td align="right">' + money(l.income_tax) + '</td><td align="right">' + money(l.net) + '</td></tr>'; }).join('')
      + '<tr><td><b>Total</b></td><td></td><td align="right"><b>' + money(t.gross) + '</b></td><td align="right"><b>' + money(t.cpp) + '</b></td><td align="right"><b>' + money(t.ei) + '</b></td><td align="right"><b>' + money(t.tax) + '</b></td><td align="right"><b>' + money(t.net) + '</b></td></tr></table>'
      + '<p>Employer contributions ' + money(t.employer) + ' · owed to the CRA ' + money(t.remit_cra) + (+t.remit_rq ? ' · owed to Revenu Québec ' + money(t.remit_rq) : '') + '</p>';
    if (KT.printDoc) KT.printDoc({ html: h }, { title: 'Pay run ' + r.reference });
  }

  // ── Remittances ─────────────────────────────────────────────────────────────────────────────────────────────────
  async function drawRemit(host, year) {
    css2();
    host.innerHTML = '<div class="hr-sub" style="padding:12px 2px;">Loading…</div>';
    var r;
    try { r = await Api.get('/hr/payroll/remittance' + (year ? '?year=' + year : '')); } catch (e) { host.innerHTML = '<div class="hr-note">' + esc(errMsg(e)) + '</div>'; return; }
    if (!host.isConnected) return;
    if (!r.enabled) return offNote(host, true);
    var qc = r.has_quebec, wsib = r.wsib_rate != null;
    var rows = (r.periods || []).map(function (p) {
      return '<tr>' + td('<b>' + esc(period(p.start, p.end)) + '</b><div class="hr-sub" data-no-i18n>' + esc((p.runs || []).join(', ')) + '</div>', false, true)
        + td('<b>' + esc(day(p.due)) + '</b>' + (p.due_note ? '<div class="hr-sub">' + esc(p.due_note) + '</div>' : ''), false, true)
        + td(money(p.gross), true) + td(money(p.cpp_employee + p.cpp_employer), true) + td(money(p.ei_employee + p.ei_employer), true) + td(money(p.tax), true)
        + td('<b>' + money(p.cra_total) + '</b>', true, true) + (qc ? td('<b>' + money(p.rq_total) + '</b>', true, true) : '') + td(String(p.employees_last_pay || 0), true) + (wsib ? td(money(p.wsib), true) : '') + '</tr>';
    });
    var head = ['Remitting period', 'Due', { t: 'Gross pay', r: 1 }, { t: 'CPP (both)', r: 1 }, { t: 'EI (both)', r: 1 }, { t: 'Income tax', r: 1 }, { t: 'To the CRA', r: 1 }];
    if (qc) head.push({ t: 'To Revenu Québec', r: 1 });
    head.push({ t: 'Employees', r: 1 });
    if (wsib) head.push({ t: 'WSIB estimate', r: 1 });
    var tt = r.totals || {};
    host.innerHTML = '<div class="kt-card" style="padding:12px 14px;" data-kt-nogrid>'
      + '<div class="hr-bar" style="margin:0 0 6px;"><b style="font-size:14px;">Remittances ' + esc(r.year) + '</b><span style="flex:1"></span>'
      + '<button type="button" class="hr-b" data-y="' + (r.year - 1) + '" data-kt-iconized="1">‹ ' + (r.year - 1) + '</button> <button type="button" class="hr-b" data-y="' + (r.year + 1) + '" data-kt-iconized="1">' + (r.year + 1) + ' ›</button> '
      + '<button type="button" class="hr-b" data-print data-kt-iconized="1">Print</button></div>'
      + kv('Remitter type', esc(r.remitter_label)) + kv('CRA payroll account', r.rp_number ? esc(r.rp_number) : null) + (qc ? kv('Revenu Québec number', r.rq_number ? esc(r.rq_number) : null) : '')
      + '<div class="hrx-tot"><div><span>Gross pay</span><b>' + money(tt.gross) + '</b></div><div><span>Owed to the CRA</span><b>' + money(tt.cra_total) + '</b></div>' + (qc ? '<div><span>Owed to Revenu Québec</span><b>' + money(tt.rq_total) + '</b></div>' : '') + (wsib ? '<div><span>WSIB estimate</span><b>' + money(tt.wsib) + '</b></div>' : '') + '</div>'
      + (rows.length ? table(head, rows, ' data-hrx-remit') : '<div class="hr-sub">No finalized pay runs in ' + esc(r.year) + '.</div>')
      + '<div class="hr-sub" style="margin-top:8px;">From finalized pay runs only (what the PD7A remittance voucher asks for). CPP and EI include the employer\'s share. ' + (wsib ? 'The WSIB figure is an estimate from your rate; it is not remitted from here. ' : '') + 'Check the amounts with your accountant before paying.</div></div>';
    host.querySelectorAll('[data-y]').forEach(function (b) { b.addEventListener('click', function () { drawRemit(host, +b.getAttribute('data-y')); }); });
    host.querySelector('[data-print]').addEventListener('click', function () {
      var tb = host.querySelector('[data-hrx-remit]');
      if (KT.printDoc) KT.printDoc({ html: '<h2>Remittances ' + esc(r.year) + '</h2><p>' + esc(r.remitter_label) + (r.rp_number ? ' · CRA ' + esc(r.rp_number) : '') + '</p>' + (tb ? tb.outerHTML : '') }, { title: 'Remittances ' + r.year });
    });
  }

  // ── Opening balances ────────────────────────────────────────────────────────────────────────────────────────────
  async function drawYtd(host) {
    css2();
    host.innerHTML = '<div class="hr-sub" style="padding:12px 2px;">Loading…</div>';
    var r;
    try { r = await Api.get('/hr/payroll/ytd-openings'); } catch (e) { host.innerHTML = '<div class="hr-note">' + esc(errMsg(e)) + '</div>'; return; }
    if (!host.isConnected) return;
    if (!r.enabled) return offNote(host, true);
    var reload = function () { drawYtd(host); };
    var rows = (r.people || []).map(function (p) {
      var o = p.opening;
      return '<tr>' + td('<b data-no-i18n>' + esc(p.name) + '</b>' + (o && o.note ? '<div class="hr-sub" data-no-i18n>' + esc(o.note) + '</div>' : ''), false, true)
        + td(o ? day(o.as_of) : '—') + td(o ? money(o.gross) : '—', true) + td(o ? money(o.cpp + o.cpp2) : '—', true) + td(o ? money(o.ei) : '—', true) + td(o ? money(o.income_tax) : '—', true)
        + td(p.own ? '<span class="hr-sub">Another admin</span>' : kebabBtn(p.user_id), true, true) + '</tr>';
    });
    host.innerHTML = '<div class="kt-card" style="padding:12px 14px;" data-kt-nogrid>'
      + '<div class="hr-bar" style="margin:0 0 6px;"><b style="font-size:14px;">Opening balances ' + esc(r.year) + '</b><span style="flex:1"></span><button type="button" class="hr-b" data-imp data-kt-iconized="1">Import from a spreadsheet</button></div>'
      + '<div class="hr-sub" style="margin-bottom:8px;">Only for people paid by another payroll earlier this year. Enter what that payroll paid and deducted up to the as-of date, so CPP and EI stop at the yearly maximum and the year-to-date on payslips is right.</div>'
      + table(['Person', 'As of', { t: 'Gross', r: 1 }, { t: 'CPP', r: 1 }, { t: 'EI', r: 1 }, { t: 'Income tax', r: 1 }, ''], rows) + '</div>';
    host.querySelectorAll('[data-keb]').forEach(function (b) {
      b.addEventListener('click', function () {
        var p = (r.people || []).filter(function (x) { return String(x.user_id) === b.getAttribute('data-keb'); })[0]; if (!p) return;
        menu(b, [[p.opening ? 'Change' : 'Enter opening balance', function () { editOpening(r, p, reload); }],
          p.opening ? ['Remove', function () { Api.delete('/hr/payroll/ytd-openings/' + p.opening.id).then(reload, function (e) { toast('⚠️', 'Not removed', errMsg(e), '#DC2626'); }); }] : null]);
      });
    });
    host.querySelector('[data-imp]').addEventListener('click', function () { importOpenings(r, reload); });
  }
  var OPEN_F = [['gross', 'Gross pay'], ['pensionable', 'Pensionable earnings (blank = gross)'], ['insurable', 'Insurable earnings (blank = gross)'], ['cpp', 'CPP / QPP'], ['cpp2', 'CPP2 / QPP2'],
    ['ei', 'EI'], ['qpip', 'QPIP'], ['income_tax', 'Income tax'], ['bonus', 'Bonuses in that pay']];
  function editOpening(r, p, reload) {
    var o = p.opening || {};
    var box = el('<div style="' + GRID + '">' + field('As of', date('as_of', o.as_of || (r.year + '-03-31')))
      + OPEN_F.map(function (f) { return field(f[1], num(f[0], o[f[0]] != null && (f[0] === 'gross' || +o[f[0]]) ? o[f[0]] : '')); }).join('')
      + field('Note (optional)', '<input class="hr-i" data-f="note" maxlength="300" value="' + esc(o.note || '') + '" style="width:100%;">', true) + '</div>' + ERR);
    modal({ title: '📒 Opening balance · ' + p.name, body: box, actions: [{ label: 'Cancel' }, { label: 'Save', primary: true, busyLabel: 'Working…', handler: function () {
      var d = { as_of: val(box, 'as_of'), note: val(box, 'note') };
      OPEN_F.forEach(function (f) { d[f[0]] = numv(box, f[0]); });
      if (d.gross == null) { msg(box, 'Enter the gross pay.'); return false; }
      return Api.put('/hr/payroll/ytd-openings/' + p.user_id, d).then(function () { toast('✅', 'Saved', p.name, '#16A34A'); reload(); }, function (e) { msg(box, errMsg(e)); return false; });
    } }] });
  }
  function importOpenings(r, reload) {
    var box = el('<div style="' + GRID + '">' + field('As of', date('as_of', r.year + '-03-31'))
      + field('Paste from a spreadsheet', '<textarea class="hr-i" data-f="csv" rows="8" style="width:100%;min-height:150px;" placeholder="email,gross,cpp,cpp2,ei,income_tax\nsam@example.com,12000,650,0,196,1450"></textarea>', true)
      + '</div><div class="hr-sub" style="margin-top:6px;">First row = column names: email (or name), gross, and any of pensionable, insurable, cpp, cpp2, ei, qpip, income_tax, bonus. Existing balances are replaced.</div>' + ERR);
    modal({ title: '📒 Import opening balances ' + r.year, body: box, actions: [{ label: 'Cancel' }, { label: 'Import', primary: true, busyLabel: 'Working…', handler: function () {
      var lines = String(val(box, 'csv') || '').split(/\r?\n/).filter(function (x) { return x.trim(); });
      if (lines.length < 2) { msg(box, 'Paste the column names and at least one row.'); return false; }
      var sep = lines[0].indexOf('\t') >= 0 ? '\t' : ',';
      var hd = lines[0].split(sep).map(function (x) { return x.trim().toLowerCase().replace(/[^a-z0-9]+/g, '_'); });
      var rows = lines.slice(1).map(function (ln) {
        var c = ln.split(sep), o = {};
        hd.forEach(function (h, i) { var v = (c[i] || '').trim(); if (h === 'email' || h === 'name' || h === 'who') o.who = v; else if (v !== '') o[h] = Number(v.replace(/[$,]/g, '')); });
        return o;
      });
      return Api.post('/hr/payroll/ytd-openings/import', { tax_year: r.year, as_of: val(box, 'as_of'), rows: rows }).then(function (x) {
        toast('✅', 'Imported ' + (x.imported || 0), (x.missed && x.missed.length) ? 'Not matched: ' + x.missed.slice(0, 5).join(', ') : '', '#16A34A');
        reload();
      }, function (e) { msg(box, errMsg(e)); return false; });
    } }] });
  }

  // ── HR settings > Payroll engine (agency admins) ────────────────────────────────────────────────────────────────
  async function settings(host) {
    if (!host) return;
    var r;
    try { r = await Api.get('/hr/payroll/engine'); } catch (e) { return; }
    if (!host.isConnected || !r.can_edit) return;
    css2();
    var s = r.settings || {};
    var tb = r.tables || {};
    host.innerHTML = '<div class="kt-card" style="padding:14px 16px;margin-top:12px;" data-kt-nogrid>'
      + '<div style="font-weight:800;font-size:14px;">Payroll engine</div>'
      + '<div class="hr-sub" style="margin:2px 0 10px;">Works out CPP, EI, QPIP and income tax for each pay, runs pay runs, issues payslips and shows what to remit. Off until you switch it on; your current payroll export is not changed by it.</div>'
      + '<div class="hr-note" style="margin-bottom:10px;">' + esc(r.ack_text) + (r.acknowledged ? '<div class="hr-sub" style="margin-top:6px;">Accepted' + (s.acknowledged_by_name ? ' by ' + esc(s.acknowledged_by_name) : '') + (s.acknowledged_at ? ' on ' + esc(day(s.acknowledged_at)) : '') + '.</div>' : '') + '</div>'
      + (r.acknowledged ? '' : '<div style="margin-bottom:10px;">' + check('acknowledge', false, 'I have read this and will check the first runs with our accountant') + '</div>')
      + '<div style="margin-bottom:10px;">' + check('enabled', !!r.enabled, 'Payroll engine on') + '</div>'
      + '<div style="' + GRID + '">'
      + field('CRA payroll account (RP)', '<input class="hr-i" data-f="rp_number" maxlength="24" placeholder="123456789RP0001" value="' + esc(s.rp_number || '') + '" style="width:100%;">')
      + field('Revenu Québec number (Quebec staff only)', '<input class="hr-i" data-f="rq_number" maxlength="24" placeholder="1234567890RS0001" value="' + esc(s.rq_number || '') + '" style="width:100%;">')
      + field('Remitter type', '<select class="hr-i" data-f="remitter_type" style="width:100%;">' + opts(r.remitters || {}, s.remitter_type) + '</select>')
      + field('Workers\' compensation rate per $100 (report only)', num('wsib_rate', s.wsib_rate))
      + '</div><div class="hr-sub" style="margin-top:8px;">CRA tables: ' + esc(tb.this_year ? tb.this_year.year + (tb.this_year.loaded ? ' loaded' : ' not loaded') : '') + (tb.next_year ? ' · ' + esc(tb.next_year.year + (tb.next_year.loaded ? ' loaded' : ' not loaded yet')) : '')
      + (r.payroll_feature ? '' : ' · The Payroll add-on is off, so pay runs cannot be opened.') + '</div>'
      + '<div class="hr-bar" style="margin:12px 0 0;"><button type="button" class="hr-b p" data-pe-save data-kt-iconized="1">Save payroll engine</button></div>' + ERR + '</div>';
    host.querySelector('[data-pe-save]').addEventListener('click', function () {
      var o = { enabled: !!val(host, 'enabled'), rp_number: val(host, 'rp_number') || '', rq_number: val(host, 'rq_number') || '', remitter_type: val(host, 'remitter_type'), wsib_rate: numv(host, 'wsib_rate') };
      if (!r.acknowledged) o.acknowledge = !!val(host, 'acknowledge');
      if (o.enabled && !r.acknowledged && !o.acknowledge) { msg(host, 'Tick the notice first: check with your accountant before your first live run.'); return; }
      Api.put('/hr/payroll/engine', o).then(function (x) {
        toast('✅', 'Saved', x.enabled ? 'The payroll engine is on.' : 'The payroll engine is off.', '#16A34A');
        PAY_ON = !!x.enabled; if (window.KTHrSections && window.KTHrSections.setPay) window.KTHrSections.setPay(PAY_ON);
        settings(host);
      }, function (e) { msg(host, errMsg(e)); });
    });
  }

  // ── My HR > My payslips ─────────────────────────────────────────────────────────────────────────────────────────
  async function myPayslips(host) {
    var r;
    try { r = await Api.get('/hr/me/payslips'); } catch (e) { return; }
    if (!r || !r.enabled || !host.isConnected) return;
    css2();
    var wrap = document.createElement('div'); wrap.setAttribute('data-hrx-slips', '1'); host.appendChild(wrap);
    var ps = r.payslips || [];
    var rows = ps.map(function (p) {
      return '<tr data-open="' + p.id + '">' + td(day(p.pay_date || p.period_end)) + td(period(p.period_start, p.period_end) + (p.adjustment ? ' · adjustment' : '')) + td(money(p.gross), true) + td('<b>' + money(p.net) + '</b>', true, true) + '</tr>';
    });
    var y = ps[0] || null;
    wrap.innerHTML = '<div class="hrt-sec">My payslips</div><div class="kt-card" style="padding:10px 14px;" data-kt-nogrid>'
      + (ps.length ? (y ? '<div class="hrx-tot"><div><span>Gross this year</span><b>' + money(y.ytd_gross) + '</b></div><div><span>Net this year</span><b>' + money(y.ytd_net) + '</b></div></div>' : '')
        + table(['Pay date', 'Period', { t: 'Gross', r: 1 }, { t: 'Net', r: 1 }], rows) : '<span class="hr-sub">No payslips from KiddieTrac payroll yet.</span>') + '</div>';
    wrap.querySelectorAll('tr[data-open]').forEach(function (tr) {
      tr.addEventListener('click', function () { slipMine(+tr.getAttribute('data-open')); });
    });
  }
  /* My own payslip: the same file the email links to (my own sign-in), as My pay opens it - never the office view. */
  function slipMine(id) {
    var base = KT.API_BASE || 'https://api.kiddietrac.com/api/v1';
    var tok = sessionStorage.getItem('kt_token') || localStorage.getItem('kt_token') || '';
    fetch(base + '/payroll-documents/' + id + '/pdf', { headers: { Authorization: 'Bearer ' + tok, Accept: 'application/pdf' } }).then(function (r) { if (!r.ok) throw new Error('HTTP ' + r.status); return r.blob(); })
      .then(function (b) { KT.openFile(b, { title: 'My payslip', filename: 'payslip-' + id + '.pdf', label: 'Payslip' }); }, function () { toast('⚠️', 'Could not open the payslip', 'Please try again.', '#DC2626'); });
  }
  /* My HR tiles for the payroll sections, added to the tile grid only when they have something to show. */
  function tile(b, icon, label, rx) {
    var g = b.querySelector('.mh-tiles'); if (!g || g.querySelector('[data-hrx-tile="' + label + '"]')) return;
    var t = document.createElement('button'); t.type = 'button'; t.className = 'mh-tile'; t.setAttribute('data-kt-iconized', '1'); t.setAttribute('data-hrx-tile', label);
    t.innerHTML = '<span class="mh-ti" aria-hidden="true">' + icon + '</span><span>' + esc(label) + '</span>';
    t.addEventListener('click', function () {
      var hit = [].filter.call(b.querySelectorAll('.hrt-sec'), function (s) { return rx.test((s.textContent || '').trim()); })[0];
      if (hit) { try { hit.scrollIntoView({ behavior: 'smooth', block: 'start' }); } catch (e) { hit.scrollIntoView(); } }
    });
    g.appendChild(t);
  }

  var PAY_ON = null;
  var P2_TABS = { payruns: drawRuns, remit: drawRemit, ytd: drawYtd };
  function has(k) { return !!P2_TABS[k]; }
  function draw(k, host) { var m = /[?&]run=(\d+)/.exec(location.hash || ''); return k === 'payruns' ? drawRuns(host, m ? +m[1] : null) : P2_TABS[k](host); }

  /* My HR: tax forms (P1) and my payslips (P2), each with its tile once it has something to show. */
  function mineAll(host) {
    mine(host).then(function () { if (host.querySelector('[data-hrx-mine]')) tile(host, '🏛️', 'Tax forms', /^tax forms/i); });
    myPayslips(host).then(function () { if (host.querySelector('[data-hrx-slips]')) tile(host, '💵', 'My payslips', /^my payslips/i); });
  }
  window.KTHrTax = { mine: mineAll, profile: profile, has: has, draw: draw, settings: settings, openSlip: openSlip };
})(window);
