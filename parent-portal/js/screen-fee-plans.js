/* ═══════════════════════════════════════════════════════════════════
   KIDDIETRAC v22p86 — Tuition & fee plans (billing build-out, part 1)
   Reusable fee schedule: named amount + frequency, optionally scoped to a
   centre. The basis for invoice automation.
   Hash: #tuition-plans  ·  agency_admin / centre_director
   ═══════════════════════════════════════════════════════════════════ */
(function (window) {
  'use strict';
  if (!window.KT) return;
  var KT = window.KT;
  var Api = KT.Api, Dom = KT.Dom, Shell = KT.Shell;

  var FREqS = [
    ['weekly', 'Weekly'], ['biweekly', 'Bi-weekly'], ['monthly', 'Monthly'], ['one_time', 'One-time'],
  ];
  function freqLabel(v) { var f = FREqS.filter(function (x) { return x[0] === v; })[0]; return f ? f[1] : v; }
  function money(n) { n = Number(n) || 0; try { return new Intl.NumberFormat(undefined, { style: 'currency', currency: 'CAD' }).format(n); } catch (e) { return '$' + n.toFixed(2); } }
  function inputStyle() { return 'width:100%;padding:9px 12px;border:1px solid #D1D5DB;border-radius:8px;font-size:14px;box-sizing:border-box;'; }
  function label(t) { return Dom.el('label', { style: 'display:block;font-size:13px;font-weight:600;color:#374151;margin-bottom:4px;' }, t); }

  var _centres = [];

  function render(container) {
    Dom.clear(container);
    var wrap = Dom.el('div', { style: 'padding:24px;max-width:1100px;margin:0 auto;' });
    container.appendChild(wrap);

    var hero = Dom.el('div', { class: 'kt-hero', style: 'background:linear-gradient(135deg,#1F6080 0%,#16637A 70%,#0EA5A0 100%);' });
    hero.innerHTML = '<div class="kt-hero-greet">💵 BILLING</div><h1>Tuition &amp; fee plans</h1><div class="kt-hero-sub">Define reusable fee plans — a named amount and how often it bills. Apply them to families to drive invoices.</div>';
    wrap.appendChild(hero);

    var bar = Dom.el('div', { style: 'display:flex;justify-content:space-between;align-items:center;gap:12px;margin:18px 0;flex-wrap:wrap;' });
    bar.appendChild(Dom.el('div', { style: 'color:#6B7280;font-size:13px;' }, 'Reusable plans for this agency'));
    var addBtn = Dom.el('button', { style: btnPrimary() }, '+ New plan');
    addBtn.addEventListener('click', function () { openEditor(null, container); });
    bar.appendChild(addBtn);
    wrap.appendChild(bar);

    var listWrap = Dom.el('div', { 'data-kt-list': '1', 'data-kt-sort': 'amount:Amount:num', style: 'background:white;border-radius:12px;box-shadow:0 1px 3px rgba(0,0,0,.04);overflow:hidden;' });
    wrap.appendChild(listWrap);
    listWrap.appendChild(Dom.el('div', { style: 'padding:40px;text-align:center;color:#9CA3AF;' }, 'Loading…'));

    Promise.all([
      Api.get('/admin/fee-plans'),
      Api.get('/admin/centres').catch(function () { return { centres: [] }; }),
    ]).then(function (res) {
      _centres = (res[1].centres || []).map(function (c) { return { id: c.id, name: c.name }; });
      var plans = res[0].plans || [];
      Dom.clear(listWrap);
      if (!plans.length) {
        listWrap.appendChild(Dom.el('div', { style: 'padding:48px;text-align:center;color:#6B7280;' }, 'No plans yet. Click + New plan to create your first fee plan.'));
        return;
      }
      plans.forEach(function (p) { listWrap.appendChild(planRow(p, container)); });
    }).catch(function (e) {
      Dom.clear(listWrap);
      listWrap.appendChild(Dom.el('div', { style: 'padding:24px;color:#DC2626;' }, 'Could not load: ' + (e.message || 'error')));
    });
  }

  function planRow(p, container) {
    var row = Dom.el('div', { style: 'display:flex;align-items:center;gap:14px;padding:14px 18px;border-bottom:1px solid #F3F4F6;cursor:pointer;' });
    row.setAttribute('data-sort-amount', p.amount);
    row.addEventListener('mouseenter', function () { row.style.background = '#FAFBFC'; });
    row.addEventListener('mouseleave', function () { row.style.background = 'white'; });
    row.addEventListener('click', function () { openEditor(p, container); });

    row.appendChild(Dom.el('div', { style: 'width:46px;height:46px;border-radius:10px;background:#EAF3F6;color:#1F6080;display:flex;align-items:center;justify-content:center;font-size:15px;font-weight:800;flex-shrink:0;' }, money(p.amount).replace(/\.00$/, '')));

    var body = Dom.el('div', { style: 'flex:1;min-width:0;' });
    var titleRow = Dom.el('div', { style: 'display:flex;align-items:center;gap:8px;' });
    titleRow.appendChild(Dom.el('span', { style: 'font-weight:700;font-size:15px;color:#111827;' }, p.name));
    if (!p.active) titleRow.appendChild(Dom.el('span', { style: 'background:#F3F4F6;color:#6B7280;font-size:10px;font-weight:700;padding:2px 8px;border-radius:999px;' }, 'INACTIVE'));
    body.appendChild(titleRow);
    var sub = money(p.amount) + ' · ' + freqLabel(p.frequency);
    if (p.centre_name) sub += ' · ' + p.centre_name;
    if (p.age_group) sub += ' · ' + p.age_group;
    body.appendChild(Dom.el('div', { style: 'font-size:12px;color:#6B7280;margin-top:2px;' }, sub));
    row.appendChild(body);

    if (p.active) {
      var genBtn = Dom.el('button', { title: 'Generate invoices from this plan', style: 'background:#16A34A;color:white;border:none;padding:6px 12px;border-radius:8px;font-size:12px;font-weight:600;cursor:pointer;flex-shrink:0;' }, '💸 Generate invoices');
      genBtn.addEventListener('click', function (e) { e.stopPropagation(); openGenerate(p); });
      genBtn.addEventListener('mouseenter', function () { genBtn.style.background = '#15803D'; });
      genBtn.addEventListener('mouseleave', function () { genBtn.style.background = '#16A34A'; });
      row.appendChild(genBtn);
    }

    var freqTag = Dom.el('span', { style: 'background:#DBEAFE;color:#1E40AF;font-size:11px;font-weight:700;padding:4px 10px;border-radius:999px;flex-shrink:0;' }, freqLabel(p.frequency));
    row.appendChild(freqTag);
    return row;
  }

  // Invoice automation: pick families, generate an invoice per family from a plan.
  function openGenerate(p) {
    var body = Dom.el('div', {});
    body.appendChild(Dom.el('div', { style: 'background:#F0FBF4;border:1px solid #BFE3CF;border-radius:10px;padding:12px 14px;margin-bottom:14px;font-size:13px;color:#166534;' },
      'Each selected family gets an invoice for ' + money(p.amount) + ' (' + freqLabel(p.frequency) + ') from “' + p.name + '”.'));

    var optRow = Dom.el('div', { style: 'display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:14px;' });
    var statusWrap = Dom.el('div', {});
    statusWrap.appendChild(label('Create as'));
    var statusSel = Dom.el('select', { style: inputStyle() });
    statusSel.appendChild(Dom.el('option', { value: 'draft' }, 'Draft (review first)'));
    statusSel.appendChild(Dom.el('option', { value: 'sent' }, 'Sent (issue now)'));
    statusWrap.appendChild(statusSel); optRow.appendChild(statusWrap);
    var dueWrap = Dom.el('div', {});
    dueWrap.appendChild(label('Due in (days)'));
    var dueIn = Dom.el('input', { type: 'number', min: '0', max: '120', value: '14', style: inputStyle() });
    dueWrap.appendChild(dueIn); optRow.appendChild(dueWrap);
    body.appendChild(optRow);

    var famHead = Dom.el('div', { style: 'display:flex;justify-content:space-between;align-items:center;margin-bottom:6px;' });
    famHead.appendChild(label('Families'));
    var selAllWrap = Dom.el('label', { style: 'display:flex;align-items:center;gap:6px;font-size:12px;color:#374151;cursor:pointer;' });
    var selAll = Dom.el('input', { type: 'checkbox' });
    selAllWrap.appendChild(selAll); selAllWrap.appendChild(Dom.el('span', {}, 'Select all'));
    famHead.appendChild(selAllWrap);
    body.appendChild(famHead);

    var famBox = Dom.el('div', { style: 'max-height:260px;overflow:auto;border:1px solid #E5E7EB;border-radius:8px;padding:6px;' });
    famBox.appendChild(Dom.el('div', { style: 'padding:16px;text-align:center;color:#9CA3AF;font-size:13px;' }, 'Loading families…'));
    body.appendChild(famBox);

    var checks = [];
    Api.get('/admin/families').then(function (d) {
      var fams = (d.families || d.data || []);
      if (p.centre_id) fams = fams.filter(function (f) { return String(f.centre_id) === String(p.centre_id); });
      Dom.clear(famBox);
      if (!fams.length) { famBox.appendChild(Dom.el('div', { style: 'padding:16px;text-align:center;color:#6B7280;font-size:13px;' }, 'No families found.')); return; }
      fams.forEach(function (f) {
        var rowL = Dom.el('label', { style: 'display:flex;align-items:center;gap:8px;padding:7px 8px;border-radius:6px;cursor:pointer;font-size:13px;color:#111827;' });
        rowL.addEventListener('mouseenter', function () { rowL.style.background = '#F8FAFC'; });
        rowL.addEventListener('mouseleave', function () { rowL.style.background = 'transparent'; });
        var cb = Dom.el('input', { type: 'checkbox', value: String(f.id) });
        rowL.appendChild(cb);
        rowL.appendChild(Dom.el('span', {}, f.family_name || f.name || ('Family #' + f.id)));
        famBox.appendChild(rowL);
        checks.push(cb);
      });
      selAll.addEventListener('change', function () { checks.forEach(function (c) { c.checked = selAll.checked; }); });
    }).catch(function () { Dom.clear(famBox); famBox.appendChild(Dom.el('div', { style: 'padding:16px;color:#DC2626;' }, 'Could not load families.')); });

    Shell.Modal.open({
      title: 'Generate invoices · ' + p.name,
      body: body,
      actions: [{
        label: 'Generate invoices', primary: true,
        handler: function () {
          var ids = checks.filter(function (c) { return c.checked; }).map(function (c) { return parseInt(c.value, 10); });
          if (!ids.length) { if (Dom.toast) Dom.toast('Select at least one family', 'error'); return false; }
          return Api.post('/admin/fee-plans/' + p.id + '/generate-invoices', {
            family_ids: ids, status: statusSel.value, due_days: parseInt(dueIn.value, 10) || 0,
          }).then(function (r) {
            if (Dom.toast) Dom.toast('Generated ' + (r.created || 0) + ' invoice' + (r.created === 1 ? '' : 's') + ' (' + r.status + ').', 'success');
          }).catch(function (e) { if (Dom.toast) Dom.toast(e.message || 'Generation failed', 'error'); return false; });
        },
      }],
    });
  }

  function openEditor(existing, container) {
    var p = existing || { name: '', amount: '', frequency: 'monthly', centre_id: null, age_group: '', description: '', active: true };
    var body = Dom.el('div', {});

    var nameWrap = Dom.el('div', { style: 'margin-bottom:12px;' });
    nameWrap.appendChild(label('Plan name *'));
    var nameIn = Dom.el('input', { type: 'text', value: p.name || '', placeholder: 'e.g. Toddler full-time', style: inputStyle() });
    nameWrap.appendChild(nameIn); body.appendChild(nameWrap);

    var grid = Dom.el('div', { style: 'display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:12px;' });
    var amtWrap = Dom.el('div', {});
    amtWrap.appendChild(label('Amount ($) *'));
    var amtIn = Dom.el('input', { type: 'number', step: '0.01', min: '0', value: (p.amount === '' ? '' : p.amount), placeholder: '0.00', style: inputStyle() });
    amtWrap.appendChild(amtIn); grid.appendChild(amtWrap);
    var freqWrap = Dom.el('div', {});
    freqWrap.appendChild(label('Billing frequency *'));
    var freqSel = Dom.el('select', { style: inputStyle() });
    FREqS.forEach(function (f) { var o = Dom.el('option', { value: f[0] }, f[1]); if (p.frequency === f[0]) o.selected = true; freqSel.appendChild(o); });
    freqWrap.appendChild(freqSel); grid.appendChild(freqWrap);
    body.appendChild(grid);

    var grid2 = Dom.el('div', { style: 'display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:12px;' });
    var centreWrap = Dom.el('div', {});
    centreWrap.appendChild(label('Centre (optional)'));
    var centreSel = Dom.el('select', { style: inputStyle() });
    centreSel.appendChild(Dom.el('option', { value: '' }, 'All centres'));
    _centres.forEach(function (c) { var o = Dom.el('option', { value: String(c.id) }, c.name); if (String(p.centre_id) === String(c.id)) o.selected = true; centreSel.appendChild(o); });
    centreWrap.appendChild(centreSel); grid2.appendChild(centreWrap);
    var ageWrap = Dom.el('div', {});
    ageWrap.appendChild(label('Age group (optional)'));
    var ageIn = Dom.el('input', { type: 'text', value: p.age_group || '', placeholder: 'Infant, Toddler…', style: inputStyle() });
    ageWrap.appendChild(ageIn); grid2.appendChild(ageWrap);
    body.appendChild(grid2);

    var descWrap = Dom.el('div', { style: 'margin-bottom:12px;' });
    descWrap.appendChild(label('Description (optional)'));
    var descIn = Dom.el('textarea', { placeholder: 'What this plan covers…', style: inputStyle() + 'min-height:56px;font-family:inherit;resize:vertical;' });
    descIn.value = p.description || '';
    descWrap.appendChild(descIn); body.appendChild(descWrap);

    var actWrap = Dom.el('label', { style: 'display:flex;align-items:center;gap:8px;font-size:14px;color:#374151;cursor:pointer;' });
    var actIn = Dom.el('input', { type: 'checkbox' }); if (p.active !== false) actIn.checked = true;
    actWrap.appendChild(actIn); actWrap.appendChild(Dom.el('span', {}, 'Active (available to apply to families)'));
    body.appendChild(actWrap);

    var actions = [{
      label: existing ? 'Save changes' : 'Create plan', primary: true,
      handler: function () {
        var payload = {
          name: nameIn.value.trim(),
          amount: parseFloat(amtIn.value) || 0,
          frequency: freqSel.value,
          centre_id: centreSel.value ? parseInt(centreSel.value, 10) : null,
          age_group: ageIn.value.trim() || null,
          description: descIn.value.trim() || null,
          active: actIn.checked,
        };
        if (!payload.name) { if (Dom.toast) Dom.toast('Plan name is required', 'error'); return false; }
        var req = existing ? Api.patch('/admin/fee-plans/' + existing.id, payload) : Api.post('/admin/fee-plans', payload);
        return req.then(function () { if (Dom.toast) Dom.toast('Plan saved', 'success'); render(container); }).catch(function (e) { if (Dom.toast) Dom.toast(e.message || 'Save failed', 'error'); return false; });
      },
    }];
    if (existing) {
      actions.unshift({
        label: 'Delete', style: 'btn-secondary',
        handler: function () {
          if (!window.confirm('Delete the plan “' + existing.name + '”?')) return false;
          return Api.delete('/admin/fee-plans/' + existing.id).then(function () { if (Dom.toast) Dom.toast('Plan deleted', 'success'); render(container); }).catch(function (e) { if (Dom.toast) Dom.toast(e.message || 'Delete failed', 'error'); return false; });
        },
      });
    }
    Shell.Modal.open({ title: existing ? 'Edit plan' : 'New fee plan', body: body, actions: actions });
  }

  function btnPrimary() { return 'background:#1F6080;color:white;border:none;padding:9px 18px;border-radius:8px;font-weight:700;cursor:pointer;font-size:14px;'; }

  if (Shell && Shell.registerScreen) {
    ['agency_admin', 'centre_director', 'platform_admin'].forEach(function (role) {
      Shell.registerScreen(role + ':tuition-plans', render);
    });
  }
  KT.FeePlans = { render: render };
})(window);
