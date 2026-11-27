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

  window.KTHrTax = { mine: mine, profile: profile };
})(window);
