/* Kiddietrac v22p1 - Immunizations
   Per-child immunization records aligned with Ontario CCEYA daycare requirements.
*/
(function (window) {
  'use strict';
  if (!window.KT || !window.KT.Shell) { return; }
  var Shell = window.KT.Shell;
  var Modal = Shell.Modal;

  function token() { return sessionStorage.getItem('kt_token') || localStorage.getItem('kt_token'); }
  function apiBase() { return (window.KT && window.KT.API_BASE) || 'https://api.kiddietrac.com/api/v1'; }
  function getUser() { try { return JSON.parse(sessionStorage.getItem('kt_user') || '{}'); } catch (e) { return {}; } }
  function role() { var u = getUser(); return (u && (u.primary_role || (u.roles && u.roles[0]))) || ''; }

  async function api(method, path, body) {
    var opts = { method: method, headers: { 'Authorization': 'Bearer ' + token(), 'Accept': 'application/json' } };
    if (body !== undefined) { opts.headers['Content-Type'] = 'application/json'; opts.body = JSON.stringify(body); }
    var res = await fetch(apiBase() + path, opts);
    var json = await res.json().catch(function () { return {}; });
    if (!res.ok) throw new Error(json.message || ('API ' + res.status));
    return json;
  }
  function esc(s) { return s == null ? '' : String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;'); }
  function fmtDate(s) { if (!s) return '-'; try { return new Date(s).toLocaleDateString('en-CA'); } catch (e) { return s; } }

  var COMMON_VACCINES = [
    'DTaP', 'Tdap', 'Hib', 'IPV (Polio)', 'MMR', 'Varicella', 'Rotavirus',
    'Pneumococcal', 'Meningococcal', 'Hepatitis A', 'Hepatitis B', 'Influenza', 'COVID-19'
  ];

  // ---------------------------------------------------------------
  // Director / agency_admin
  // ---------------------------------------------------------------
  async function renderDirector(container, opts) {
    opts = opts || {};
    container.innerHTML = '<div style="padding:32px;text-align:center;color:#6B7280;">Loading immunization records...</div>';
    var url = '/director/immunizations' + (opts.overdue ? '?overdue=1' : '');
    var resp;
    try { resp = await api('GET', url); }
    catch (e) {
      container.innerHTML = '<div style="padding:24px;color:#DC2626;">Could not load: ' + esc(e.message) + '</div>';
      return;
    }
    var rows = resp.immunizations || [];

    container.innerHTML =
      '<div style="padding:24px;max-width:1100px;">' +
        '<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:14px;flex-wrap:wrap;gap:12px;">' +
          '<div>' +
            '<h2 style="font-size:24px;margin:0;">Immunizations</h2>' +
            '<p style="color:#6B7280;margin:4px 0 0;font-size:13px;">Per-child immunization records (CCEYA-compliant). Track upcoming and overdue doses.</p>' +
          '</div>' +
          '<button id="kt-new-imm" style="background:#1F6080;color:white;border:none;padding:11px 20px;border-radius:10px;font-weight:700;cursor:pointer;">+ Add record</button>' +
        '</div>' +
        '<div style="margin-bottom:18px;">' +
          '<button id="kt-tab-all" class="kt-tab ' + (opts.overdue ? '' : 'active') + '" style="padding:8px 16px;margin-right:8px;border:1px solid #D1D5DB;border-radius:8px;background:' + (opts.overdue ? 'white' : '#1F6080') + ';color:' + (opts.overdue ? '#374151' : 'white') + ';font-weight:600;cursor:pointer;">All</button>' +
          '<button id="kt-tab-overdue" class="kt-tab ' + (opts.overdue ? 'active' : '') + '" style="padding:8px 16px;border:1px solid #D1D5DB;border-radius:8px;background:' + (opts.overdue ? '#DC2626' : 'white') + ';color:' + (opts.overdue ? 'white' : '#374151') + ';font-weight:600;cursor:pointer;">Overdue only</button>' +
        '</div>' +
        (rows.length === 0
          ? '<div style="padding:32px;text-align:center;color:#6B7280;background:white;border-radius:14px;">' + (opts.overdue ? 'No overdue immunizations.' : 'No records yet.') + '</div>'
          : '<div style="background:white;border-radius:14px;overflow:hidden;">' +
              '<table style="width:100%;border-collapse:collapse;font-size:14px;">' +
                '<thead><tr style="background:#F9FAFB;text-align:left;">' +
                  '<th style="padding:12px;">Child</th><th style="padding:12px;">Vaccine</th><th style="padding:12px;">Dose</th><th style="padding:12px;">Given</th><th style="padding:12px;">Next due</th><th style="padding:12px;">Status</th><th style="padding:12px;"></th>' +
                '</tr></thead><tbody>' +
                rows.map(immRow).join('') +
                '</tbody></table>' +
            '</div>') +
      '</div>';

    container.querySelector('#kt-new-imm').addEventListener('click', function () { openNewImmModal(container); });
    container.querySelector('#kt-tab-all').addEventListener('click', function () { renderDirector(container, { overdue: false }); });
    container.querySelector('#kt-tab-overdue').addEventListener('click', function () { renderDirector(container, { overdue: true }); });
    container.querySelectorAll('[data-edit]').forEach(function (btn) {
      btn.addEventListener('click', function () { openEditImmModal(parseInt(btn.dataset.edit, 10), container); });
    });
    container.querySelectorAll('[data-delete]').forEach(function (btn) {
      btn.addEventListener('click', function () { confirmDelete(parseInt(btn.dataset.delete, 10), container); });
    });
  }

  function immRow(r) {
    var status = '<span style="color:#16A34A;font-weight:600;">Current</span>';
    if (r.exempt) status = '<span style="color:#9CA3AF;font-weight:600;">Exempt</span>';
    else if (r.next_due_on && new Date(r.next_due_on) < new Date()) status = '<span style="color:#DC2626;font-weight:700;">OVERDUE</span>';
    else if (r.next_due_on) status = '<span style="color:#F59E0B;font-weight:600;">Due ' + fmtDate(r.next_due_on) + '</span>';
    return '<tr style="border-top:1px solid #E5E7EB;">' +
      '<td style="padding:12px;font-weight:600;">' + esc(r.child_name) + '</td>' +
      '<td style="padding:12px;">' + esc(r.vaccine) + '</td>' +
      '<td style="padding:12px;color:#6B7280;">' + esc(r.dose_label || '-') + '</td>' +
      '<td style="padding:12px;">' + fmtDate(r.administered_on) + '</td>' +
      '<td style="padding:12px;">' + fmtDate(r.next_due_on) + '</td>' +
      '<td style="padding:12px;">' + status + '</td>' +
      '<td style="padding:12px;text-align:right;">' +
        '<button data-edit="' + r.id + '" style="padding:5px 10px;background:#E5E7EB;color:#374151;border:none;border-radius:6px;font-size:12px;cursor:pointer;margin-right:4px;">Edit</button>' +
        '<button data-delete="' + r.id + '" style="padding:5px 10px;background:#FEE2E2;color:#991B1B;border:none;border-radius:6px;font-size:12px;cursor:pointer;">Delete</button>' +
      '</td>' +
    '</tr>';
  }

  async function openNewImmModal(container, prefill) {
    prefill = prefill || {};
    var children = [];
    try {
      var en = await api('GET', '/director/enrollments');
      children = (en.enrollments || []).filter(function (e) { return e.status === 'enrolled' || e.status === 'active'; });
    } catch (e) {}

    var body = document.createElement('div');
    body.innerHTML =
      '<div style="display:grid;gap:10px;">' +
        '<label style="font-size:12px;font-weight:700;color:#6B7280;">Child</label>' +
        '<select id="kt-child" style="padding:10px 12px;border:1px solid #D1D5DB;border-radius:8px;font-size:14px;">' +
          children.map(function (c) { return '<option value="' + c.child_id + '"' + (prefill.child_id == c.child_id ? ' selected' : '') + '>' + esc((c.first_name || '') + ' ' + (c.last_name || '')) + '</option>'; }).join('') +
        '</select>' +
        '<label style="font-size:12px;font-weight:700;color:#6B7280;">Vaccine</label>' +
        '<select id="kt-vaccine" style="padding:10px 12px;border:1px solid #D1D5DB;border-radius:8px;font-size:14px;">' +
          COMMON_VACCINES.map(function (v) { return '<option' + (prefill.vaccine === v ? ' selected' : '') + '>' + esc(v) + '</option>'; }).join('') +
          '<option value="__other__">Other (specify)</option>' +
        '</select>' +
        '<input id="kt-vaccine-other" placeholder="Specify vaccine name" style="padding:10px 12px;border:1px solid #D1D5DB;border-radius:8px;font-size:14px;display:none;">' +
        '<div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;">' +
          '<div><label style="font-size:12px;font-weight:700;color:#6B7280;">Dose label</label><input id="kt-dose" placeholder="1st, 2nd, booster" value="' + esc(prefill.dose_label || '') + '" style="width:100%;padding:10px 12px;border:1px solid #D1D5DB;border-radius:8px;font-size:14px;"></div>' +
          '<div><label style="font-size:12px;font-weight:700;color:#6B7280;">Date administered</label><input type="date" id="kt-admin" value="' + esc(prefill.administered_on || '') + '" style="width:100%;padding:10px 12px;border:1px solid #D1D5DB;border-radius:8px;font-size:14px;"></div>' +
        '</div>' +
        '<div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;">' +
          '<div><label style="font-size:12px;font-weight:700;color:#6B7280;">Next due (optional)</label><input type="date" id="kt-next" value="' + esc(prefill.next_due_on || '') + '" style="width:100%;padding:10px 12px;border:1px solid #D1D5DB;border-radius:8px;font-size:14px;"></div>' +
          '<div><label style="font-size:12px;font-weight:700;color:#6B7280;">Lot number</label><input id="kt-lot" value="' + esc(prefill.lot_number || '') + '" style="width:100%;padding:10px 12px;border:1px solid #D1D5DB;border-radius:8px;font-size:14px;"></div>' +
        '</div>' +
        '<label style="font-size:12px;font-weight:700;color:#6B7280;">Clinic / administered by</label>' +
        '<input id="kt-clinic" placeholder="Family doctor or clinic name" value="' + esc(prefill.clinic_name || '') + '" style="padding:10px 12px;border:1px solid #D1D5DB;border-radius:8px;font-size:14px;">' +
        '<label style="display:flex;align-items:center;gap:6px;font-size:13px;margin-top:6px;"><input type="checkbox" id="kt-exempt"' + (prefill.exempt ? ' checked' : '') + '> Medical or religious exemption</label>' +
        '<input id="kt-exempt-reason" placeholder="Exemption reason (if applicable)" value="' + esc(prefill.exemption_reason || '') + '" style="padding:10px 12px;border:1px solid #D1D5DB;border-radius:8px;font-size:14px;">' +
        '<div id="kt-msg" style="font-size:13px;min-height:20px;"></div>' +
      '</div>';

    body.querySelector('#kt-vaccine').addEventListener('change', function (e) {
      body.querySelector('#kt-vaccine-other').style.display = e.target.value === '__other__' ? '' : 'none';
    });

    Modal.open({
      title: prefill.id ? 'Edit immunization record' : 'Add immunization record',
      body: body,
      actions: [
        { label: 'Cancel', style: 'btn-secondary' },
        {
          label: 'Save',
          style: 'btn-primary',
          handler: async function () {
            var vac = body.querySelector('#kt-vaccine').value;
            if (vac === '__other__') vac = body.querySelector('#kt-vaccine-other').value.trim();
            if (!vac) { body.querySelector('#kt-msg').textContent = 'Vaccine name required'; body.querySelector('#kt-msg').style.color = '#DC2626'; return false; }
            var payload = {
              child_id: parseInt(body.querySelector('#kt-child').value, 10),
              vaccine: vac,
              dose_label: body.querySelector('#kt-dose').value.trim() || null,
              administered_on: body.querySelector('#kt-admin').value || null,
              next_due_on: body.querySelector('#kt-next').value || null,
              lot_number: body.querySelector('#kt-lot').value.trim() || null,
              clinic_name: body.querySelector('#kt-clinic').value.trim() || null,
              exempt: body.querySelector('#kt-exempt').checked,
              exemption_reason: body.querySelector('#kt-exempt-reason').value.trim() || null,
            };
            try {
              if (prefill.id) await api('PATCH', '/director/immunizations/' + prefill.id, payload);
              else await api('POST', '/director/immunizations', payload);
              renderDirector(container);
            } catch (e) {
              body.querySelector('#kt-msg').textContent = 'Error: ' + e.message;
              body.querySelector('#kt-msg').style.color = '#DC2626';
              return false;
            }
          },
        },
      ],
    });
  }

  async function openEditImmModal(id, container) {
    var resp;
    try { resp = await api('GET', '/director/immunizations?child_id=0'); } catch (e) {}
    // We don't have a single-record endpoint; fetch the list and find it.
    var all;
    try { all = await api('GET', '/director/immunizations'); } catch (e) { return; }
    var rec = (all.immunizations || []).find(function (r) { return r.id === id; });
    if (!rec) return;
    openNewImmModal(container, rec);
  }

  function confirmDelete(id, container) {
    Modal.confirm({
      title: 'Delete immunization record',
      message: 'Permanently delete this immunization record? This cannot be undone.',
      confirmLabel: 'Delete',
      destructive: true,
      onConfirm: async function () {
        await api('DELETE', '/director/immunizations/' + id);
        renderDirector(container);
      },
    });
  }

  // ---------------------------------------------------------------
  // Parent view
  // ---------------------------------------------------------------
  async function renderParent(container) {
    container.innerHTML = '<div style="padding:32px;text-align:center;color:#6B7280;">Loading...</div>';
    var children;
    try { children = await api('GET', '/parent/children'); }
    catch (e) { container.innerHTML = '<div style="padding:24px;color:#DC2626;">Could not load: ' + esc(e.message) + '</div>'; return; }
    var kids = (children && children.children) || [];
    var html = '<div style="padding:24px;max-width:900px;">' +
      '<h2 style="font-size:24px;margin:0 0 4px;">Immunization records</h2>' +
      '<p style="color:#6B7280;margin:0 0 18px;font-size:13px;">Records on file at the centre for your children.</p>';
    if (kids.length === 0) html += '<p style="color:#6B7280;">No children on record.</p>';
    for (var i = 0; i < kids.length; i++) {
      var k = kids[i];
      var imms = [];
      try { var r = await api('GET', '/parent/children/' + k.id + '/immunizations'); imms = r.immunizations || []; } catch (e) {}
      html += '<div style="background:white;border-radius:14px;padding:18px;margin-bottom:14px;">' +
        '<h3 style="margin:0 0 12px;font-size:17px;">' + esc(k.first_name + ' ' + k.last_name) + '</h3>' +
        (imms.length === 0 ? '<p style="color:#6B7280;">No records on file.</p>' :
          '<table style="width:100%;border-collapse:collapse;font-size:13px;">' +
          '<thead><tr style="background:#F9FAFB;text-align:left;"><th style="padding:8px;">Vaccine</th><th style="padding:8px;">Dose</th><th style="padding:8px;">Given</th><th style="padding:8px;">Next due</th></tr></thead><tbody>' +
          imms.map(function (m) {
            return '<tr style="border-top:1px solid #E5E7EB;"><td style="padding:8px;">' + esc(m.vaccine) + '</td><td style="padding:8px;">' + esc(m.dose_label || '-') + '</td><td style="padding:8px;">' + fmtDate(m.administered_on) + '</td><td style="padding:8px;">' + fmtDate(m.next_due_on) + (m.exempt ? ' (exempt)' : '') + '</td></tr>';
          }).join('') +
          '</tbody></table>') +
        '</div>';
    }
    html += '</div>';
    container.innerHTML = html;
  }

  function render(container) {
    var r = role();
    if (r === 'agency_admin' || r === 'centre_director') return renderDirector(container, {});
    if (r === 'guardian') return renderParent(container);
    container.innerHTML = '<div style="padding:24px;color:#6B7280;">Immunizations are managed by the centre director. Ask your director for the latest list.</div>';
  }

  if (Shell && Shell.registerScreen) {
    ['agency_admin', 'centre_director', 'educator', 'guardian'].forEach(function (r) {
      Shell.registerScreen(r + ':immunizations', render);
    });
  }
  window.KT = window.KT || {};
  window.KT.Immunizations = { render: render };
})(window);
