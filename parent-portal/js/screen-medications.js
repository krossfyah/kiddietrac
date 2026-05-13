/* Kiddietrac v22p1 — Medications
   CCEYA-aligned standing-order medications + per-dose administration log.
   Roles:
     - director / agency_admin: add new med (status pending_auth), record parent
       authorization, log doses, view dose history, discontinue
     - educator: see active meds for the centre, log a dose given
     - guardian: read-only list of their child's active meds
*/
(function (window) {
  'use strict';

  if (!window.KT || !window.KT.Shell) { return; }
  var Shell = window.KT.Shell;
  var Modal = Shell.Modal;

  function token() { return sessionStorage.getItem('kt_token') || localStorage.getItem('kt_token'); }
  function apiBase() { return (window.KT && window.KT.API_BASE) || 'https://api.kiddietrac.com/api/v1'; }
  function getUser() { try { return JSON.parse(sessionStorage.getItem('kt_user') || '{}'); } catch (e) { return {}; } }
  function role() {
    var u = getUser();
    return (u && (u.primary_role || (u.roles && u.roles[0]))) || '';
  }

  async function api(method, path, body) {
    var opts = { method: method, headers: { 'Authorization': 'Bearer ' + token(), 'Accept': 'application/json' } };
    if (body !== undefined) {
      opts.headers['Content-Type'] = 'application/json';
      opts.body = JSON.stringify(body);
    }
    var res = await fetch(apiBase() + path, opts);
    var json = await res.json().catch(function () { return {}; });
    if (!res.ok) throw new Error(json.message || ('API ' + res.status));
    return json;
  }

  function esc(s) {
    return s == null ? '' : String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
  }
  function fmtDate(s) {
    if (!s) return '-';
    try { return new Date(s).toLocaleDateString('en-CA'); } catch (e) { return s; }
  }
  function fmtDateTime(s) {
    if (!s) return '-';
    try { return new Date(s).toLocaleString('en-CA', { dateStyle: 'medium', timeStyle: 'short' }); } catch (e) { return s; }
  }
  function statusBadge(status) {
    var colors = {
      pending_auth: '#F59E0B',
      active: '#16A34A',
      discontinued: '#9CA3AF',
      expired: '#DC2626',
    };
    var labels = {
      pending_auth: 'PENDING PARENT AUTH',
      active: 'ACTIVE',
      discontinued: 'DISCONTINUED',
      expired: 'EXPIRED',
    };
    return '<span style="display:inline-block;padding:3px 9px;border-radius:6px;background:' + (colors[status] || '#6B7280') + ';color:white;font-size:10px;font-weight:700;letter-spacing:0.5px;">' + (labels[status] || status) + '</span>';
  }

  // ---------------------------------------------------------------
  // Director / agency_admin view
  // ---------------------------------------------------------------
  async function renderDirector(container) {
    container.innerHTML = '<div style="padding:32px;text-align:center;color:#6B7280;">Loading medications...</div>';
    var resp;
    try { resp = await api('GET', '/director/medications'); }
    catch (e) {
      container.innerHTML = '<div style="padding:24px;color:#DC2626;">Could not load: ' + esc(e.message) + '</div>';
      return;
    }
    var meds = resp.medications || [];

    container.innerHTML =
      '<div style="padding:24px;max-width:1100px;">' +
        '<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:18px;flex-wrap:wrap;gap:12px;">' +
          '<div>' +
            '<h2 style="font-size:24px;margin:0;">Medications</h2>' +
            '<p style="color:#6B7280;margin:4px 0 0;font-size:13px;">Standing orders for children in your centre. Parent authorization required before any dose can be logged.</p>' +
          '</div>' +
          '<button id="kt-new-med" style="background:#1F6080;color:white;border:none;padding:11px 20px;border-radius:10px;font-weight:700;cursor:pointer;">+ New medication</button>' +
        '</div>' +
        (meds.length === 0
          ? '<div style="padding:32px;text-align:center;color:#6B7280;background:white;border-radius:14px;">No medications recorded yet.</div>'
          : '<div style="display:grid;gap:14px;">' + meds.map(medCard).join('') + '</div>') +
      '</div>';

    container.querySelector('#kt-new-med').addEventListener('click', function () { openNewMedModal(container); });
    container.querySelectorAll('[data-action]').forEach(function (btn) {
      btn.addEventListener('click', function () { handleMedAction(btn.dataset.action, parseInt(btn.dataset.medId, 10), container); });
    });
  }

  function medCard(m) {
    return '<div style="background:white;border-radius:14px;padding:18px;box-shadow:0 1px 4px rgba(0,0,0,.05);">' +
      '<div style="display:flex;justify-content:space-between;align-items:flex-start;gap:12px;margin-bottom:10px;flex-wrap:wrap;">' +
        '<div>' +
          '<div style="font-size:17px;font-weight:700;color:#111827;">' + esc(m.name) + (m.strength ? ' <span style="color:#6B7280;font-weight:500;">' + esc(m.strength) + '</span>' : '') + '</div>' +
          '<div style="color:#6B7280;font-size:13px;margin-top:2px;">for ' + esc(m.child_name) + ' &middot; ' + esc(m.dosage) + ' &middot; ' + esc(m.frequency) + '</div>' +
        '</div>' +
        '<div style="text-align:right;">' + statusBadge(m.status) + '</div>' +
      '</div>' +
      (m.reason ? '<div style="font-size:13px;color:#6B7280;margin-bottom:6px;"><b>Reason:</b> ' + esc(m.reason) + '</div>' : '') +
      (m.special_instructions ? '<div style="font-size:13px;color:#6B7280;margin-bottom:6px;"><b>Instructions:</b> ' + esc(m.special_instructions) + '</div>' : '') +
      '<div style="font-size:12px;color:#9CA3AF;margin-top:6px;">' +
        fmtDate(m.starts_on) + ' &rarr; ' + (m.expires_on ? fmtDate(m.expires_on) : 'no expiry') +
        ' &middot; ' + (m.dose_count || 0) + ' doses logged' +
        (m.last_dose_at ? ' &middot; last dose ' + fmtDateTime(m.last_dose_at) : '') +
      '</div>' +
      '<div style="margin-top:12px;display:flex;gap:8px;flex-wrap:wrap;">' +
        (m.status === 'pending_auth'
          ? '<button data-action="authorize" data-med-id="' + m.id + '" style="padding:7px 14px;background:#16A34A;color:white;border:none;border-radius:8px;font-size:13px;font-weight:600;cursor:pointer;">Record parent authorization</button>'
          : '') +
        (m.status === 'active'
          ? '<button data-action="logDose" data-med-id="' + m.id + '" style="padding:7px 14px;background:#1F6080;color:white;border:none;border-radius:8px;font-size:13px;font-weight:600;cursor:pointer;">+ Log dose</button>' +
            '<button data-action="discontinue" data-med-id="' + m.id + '" style="padding:7px 14px;background:#FEE2E2;color:#991B1B;border:1px solid #FCA5A5;border-radius:8px;font-size:13px;font-weight:600;cursor:pointer;">Discontinue</button>'
          : '') +
        (m.dose_count > 0
          ? '<button data-action="viewLogs" data-med-id="' + m.id + '" style="padding:7px 14px;background:#E5E7EB;color:#374151;border:none;border-radius:8px;font-size:13px;font-weight:600;cursor:pointer;">View dose log (' + m.dose_count + ')</button>'
          : '') +
      '</div>' +
    '</div>';
  }

  async function openNewMedModal(container) {
    var ags;
    try { ags = await api('GET', '/director/centres'); } catch (e) { ags = { centres: [] }; }
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
          children.map(function (c) { return '<option value="' + c.child_id + '">' + esc((c.first_name || '') + ' ' + (c.last_name || '')) + '</option>'; }).join('') +
        '</select>' +
        '<label style="font-size:12px;font-weight:700;color:#6B7280;">Medication name</label>' +
        '<input id="kt-name" placeholder="e.g. Children\'s Tylenol" style="padding:10px 12px;border:1px solid #D1D5DB;border-radius:8px;font-size:14px;">' +
        '<div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;">' +
          '<div><label style="font-size:12px;font-weight:700;color:#6B7280;">Strength</label><input id="kt-strength" placeholder="160mg/5mL" style="width:100%;padding:10px 12px;border:1px solid #D1D5DB;border-radius:8px;font-size:14px;"></div>' +
          '<div><label style="font-size:12px;font-weight:700;color:#6B7280;">Route</label>' +
            '<select id="kt-route" style="width:100%;padding:10px 12px;border:1px solid #D1D5DB;border-radius:8px;font-size:14px;">' +
              '<option value="oral">Oral</option><option value="topical">Topical</option><option value="inhaled">Inhaled</option><option value="injection">Injection</option><option value="other">Other</option>' +
            '</select>' +
          '</div>' +
        '</div>' +
        '<div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;">' +
          '<div><label style="font-size:12px;font-weight:700;color:#6B7280;">Dosage</label><input id="kt-dosage" placeholder="5 mL" style="width:100%;padding:10px 12px;border:1px solid #D1D5DB;border-radius:8px;font-size:14px;"></div>' +
          '<div><label style="font-size:12px;font-weight:700;color:#6B7280;">Frequency</label><input id="kt-frequency" placeholder="every 4 hours as needed" style="width:100%;padding:10px 12px;border:1px solid #D1D5DB;border-radius:8px;font-size:14px;"></div>' +
        '</div>' +
        '<label style="font-size:12px;font-weight:700;color:#6B7280;">Reason</label>' +
        '<input id="kt-reason" placeholder="fever / pain" style="padding:10px 12px;border:1px solid #D1D5DB;border-radius:8px;font-size:14px;">' +
        '<div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;">' +
          '<div><label style="font-size:12px;font-weight:700;color:#6B7280;">Starts on</label><input type="date" id="kt-starts" value="' + new Date().toISOString().split('T')[0] + '" style="width:100%;padding:10px 12px;border:1px solid #D1D5DB;border-radius:8px;font-size:14px;"></div>' +
          '<div><label style="font-size:12px;font-weight:700;color:#6B7280;">Expires on (optional)</label><input type="date" id="kt-expires" style="width:100%;padding:10px 12px;border:1px solid #D1D5DB;border-radius:8px;font-size:14px;"></div>' +
        '</div>' +
        '<label style="font-size:12px;font-weight:700;color:#6B7280;">Special instructions (optional)</label>' +
        '<textarea id="kt-special" rows="2" placeholder="Refrigerate after opening. Give with food." style="padding:10px 12px;border:1px solid #D1D5DB;border-radius:8px;font-size:14px;font-family:inherit;resize:vertical;"></textarea>' +
        '<label style="display:flex;align-items:center;gap:6px;font-size:13px;color:#374151;"><input type="checkbox" id="kt-rx"> Is prescription medication</label>' +
        '<input id="kt-physician" placeholder="Prescribing physician (if Rx)" style="padding:10px 12px;border:1px solid #D1D5DB;border-radius:8px;font-size:14px;">' +
        '<div id="kt-msg" style="font-size:13px;min-height:20px;"></div>' +
      '</div>';

    Modal.open({
      title: 'New medication',
      body: body,
      large: true,
      actions: [
        { label: 'Cancel', style: 'btn-secondary' },
        {
          label: 'Save (status: pending parent auth)',
          style: 'btn-primary',
          handler: async function () {
            var payload = {
              child_id: parseInt(body.querySelector('#kt-child').value, 10),
              name: body.querySelector('#kt-name').value.trim(),
              strength: body.querySelector('#kt-strength').value.trim() || null,
              route: body.querySelector('#kt-route').value,
              dosage: body.querySelector('#kt-dosage').value.trim(),
              frequency: body.querySelector('#kt-frequency').value.trim(),
              reason: body.querySelector('#kt-reason').value.trim() || null,
              starts_on: body.querySelector('#kt-starts').value,
              expires_on: body.querySelector('#kt-expires').value || null,
              special_instructions: body.querySelector('#kt-special').value.trim() || null,
              is_prescription: body.querySelector('#kt-rx').checked,
              prescribing_physician: body.querySelector('#kt-physician').value.trim() || null,
            };
            try {
              await api('POST', '/director/medications', payload);
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

  async function handleMedAction(action, medId, container) {
    if (action === 'authorize') return openAuthorizeModal(medId, container);
    if (action === 'discontinue') return openDiscontinueConfirm(medId, container);
    if (action === 'logDose') return openLogDoseModal(medId, container);
    if (action === 'viewLogs') return openLogsModal(medId);
  }

  function openAuthorizeModal(medId, container) {
    var me = getUser();
    var body = document.createElement('div');
    body.innerHTML =
      '<p style="font-size:14px;color:#374151;line-height:1.6;">' +
        'Record that the parent/guardian has signed authorization for this standing medication. ' +
        'CCEYA requires written parental consent before any dose can be administered.' +
      '</p>' +
      '<div style="margin-top:12px;background:#FEF3C7;border-left:3px solid #F59E0B;padding:10px 14px;border-radius:6px;font-size:13px;color:#92400E;">' +
        'For v22p1, the director records this on behalf of the parent. v22p2 will add the parent-side e-signature flow.' +
      '</div>' +
      '<label style="display:block;margin-top:14px;font-size:12px;font-weight:700;color:#6B7280;">Authorizing parent user ID</label>' +
      '<input id="kt-auth-uid" type="number" placeholder="user_id from family roster" style="width:100%;padding:10px 12px;border:1px solid #D1D5DB;border-radius:8px;font-size:14px;">' +
      '<div id="kt-msg" style="font-size:13px;margin-top:8px;min-height:20px;"></div>';
    Modal.open({
      title: 'Record parent authorization',
      body: body,
      actions: [
        { label: 'Cancel', style: 'btn-secondary' },
        {
          label: 'Mark authorized',
          style: 'btn-primary',
          handler: async function () {
            var uid = parseInt(body.querySelector('#kt-auth-uid').value, 10);
            if (!uid) { body.querySelector('#kt-msg').textContent = 'Enter a user ID'; body.querySelector('#kt-msg').style.color = '#DC2626'; return false; }
            try {
              await api('POST', '/director/medications/' + medId + '/authorize', { authorized_by_id: uid });
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

  function openDiscontinueConfirm(medId, container) {
    Modal.confirm({
      title: 'Discontinue medication',
      message: 'Mark this medication as discontinued? No new doses can be logged after this. Previous dose history is preserved.',
      confirmLabel: 'Discontinue',
      destructive: true,
      onConfirm: async function () {
        await api('POST', '/director/medications/' + medId + '/discontinue');
        renderDirector(container);
      },
    });
  }

  function openLogDoseModal(medId, container) {
    var body = document.createElement('div');
    body.innerHTML =
      '<label style="font-size:12px;font-weight:700;color:#6B7280;">Dose given</label>' +
      '<input id="kt-dose" placeholder="5 mL" style="width:100%;padding:10px 12px;border:1px solid #D1D5DB;border-radius:8px;font-size:14px;margin-bottom:12px;">' +
      '<label style="font-size:12px;font-weight:700;color:#6B7280;">Outcome</label>' +
      '<select id="kt-outcome" style="width:100%;padding:10px 12px;border:1px solid #D1D5DB;border-radius:8px;font-size:14px;margin-bottom:12px;">' +
        '<option value="given">Given as prescribed</option>' +
        '<option value="partial">Partial dose</option>' +
        '<option value="refused">Refused by child</option>' +
        '<option value="missed">Missed (not given)</option>' +
      '</select>' +
      '<label style="font-size:12px;font-weight:700;color:#6B7280;">Notes</label>' +
      '<textarea id="kt-notes" rows="3" placeholder="Optional context" style="width:100%;padding:10px 12px;border:1px solid #D1D5DB;border-radius:8px;font-size:14px;font-family:inherit;resize:vertical;margin-bottom:12px;"></textarea>' +
      '<label style="font-size:12px;font-weight:700;color:#6B7280;">Witness user ID (optional, recommended for narcotics)</label>' +
      '<input id="kt-witness" type="number" style="width:100%;padding:10px 12px;border:1px solid #D1D5DB;border-radius:8px;font-size:14px;">' +
      '<div id="kt-msg" style="font-size:13px;margin-top:8px;min-height:20px;"></div>';

    Modal.open({
      title: 'Log dose',
      body: body,
      actions: [
        { label: 'Cancel', style: 'btn-secondary' },
        {
          label: 'Record dose',
          style: 'btn-primary',
          handler: async function () {
            var payload = {
              medication_id: medId,
              dose_given: body.querySelector('#kt-dose').value.trim(),
              outcome: body.querySelector('#kt-outcome').value,
              notes: body.querySelector('#kt-notes').value.trim() || null,
              witness_id: parseInt(body.querySelector('#kt-witness').value, 10) || null,
            };
            try {
              await api('POST', '/provider/medications/give', payload);
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

  async function openLogsModal(medId) {
    var resp;
    try { resp = await api('GET', '/director/medications/' + medId + '/logs'); }
    catch (e) { return; }
    var logs = resp.logs || [];
    var body = document.createElement('div');
    body.style.maxHeight = '60vh';
    body.style.overflowY = 'auto';
    body.innerHTML = logs.length === 0
      ? '<p style="color:#6B7280;">No doses recorded yet.</p>'
      : '<table style="width:100%;border-collapse:collapse;font-size:13px;">' +
          '<thead><tr style="background:#F9FAFB;text-align:left;">' +
            '<th style="padding:8px;">When</th><th style="padding:8px;">Dose</th><th style="padding:8px;">Outcome</th><th style="padding:8px;">By</th><th style="padding:8px;">Notes</th>' +
          '</tr></thead><tbody>' +
          logs.map(function (l) {
            return '<tr style="border-top:1px solid #E5E7EB;">' +
              '<td style="padding:8px;">' + fmtDateTime(l.administered_at) + '</td>' +
              '<td style="padding:8px;">' + esc(l.dose_given) + '</td>' +
              '<td style="padding:8px;">' + esc(l.outcome) + '</td>' +
              '<td style="padding:8px;">' + esc(l.administered_by || '-') + (l.witness ? ' / wit: ' + esc(l.witness) : '') + '</td>' +
              '<td style="padding:8px;color:#6B7280;">' + esc(l.notes || '') + '</td>' +
            '</tr>';
          }).join('') +
          '</tbody></table>';
    Modal.open({ title: 'Dose history', body: body, large: true, actions: [{ label: 'Close', style: 'btn-secondary' }] });
  }

  // ---------------------------------------------------------------
  // Educator view (subset: see active meds, log a dose)
  // ---------------------------------------------------------------
  async function renderEducator(container) {
    container.innerHTML = '<div style="padding:32px;text-align:center;color:#6B7280;">Loading active medications...</div>';
    var resp;
    try { resp = await api('GET', '/provider/medications'); }
    catch (e) {
      container.innerHTML = '<div style="padding:24px;color:#DC2626;">Could not load: ' + esc(e.message) + '</div>';
      return;
    }
    var meds = resp.medications || [];
    container.innerHTML =
      '<div style="padding:24px;max-width:900px;">' +
        '<h2 style="font-size:24px;margin:0 0 4px;">Medications to administer</h2>' +
        '<p style="color:#6B7280;margin:0 0 18px;font-size:13px;">Active standing orders. Tap "+ Log dose" after administering.</p>' +
        (meds.length === 0
          ? '<div style="padding:32px;text-align:center;color:#6B7280;background:white;border-radius:14px;">No active medications.</div>'
          : '<div style="display:grid;gap:14px;">' + meds.map(medCard).join('') + '</div>') +
      '</div>';
    container.querySelectorAll('[data-action="logDose"]').forEach(function (btn) {
      btn.addEventListener('click', function () { openLogDoseModal(parseInt(btn.dataset.medId, 10), container); });
    });
    container.querySelectorAll('[data-action="viewLogs"]').forEach(function (btn) {
      btn.addEventListener('click', function () { openLogsModal(parseInt(btn.dataset.medId, 10)); });
    });
  }

  // ---------------------------------------------------------------
  // Parent view (read-only)
  // ---------------------------------------------------------------
  async function renderParent(container) {
    container.innerHTML = '<div style="padding:32px;text-align:center;color:#6B7280;">Loading...</div>';
    var children;
    try { children = await api('GET', '/parent/children'); }
    catch (e) {
      container.innerHTML = '<div style="padding:24px;color:#DC2626;">Could not load: ' + esc(e.message) + '</div>';
      return;
    }
    var kids = (children && children.children) || [];
    var html = '<div style="padding:24px;max-width:900px;">' +
      '<h2 style="font-size:24px;margin:0 0 4px;">Medications</h2>' +
      '<p style="color:#6B7280;margin:0 0 18px;font-size:13px;">Active medications recorded at the centre for your children.</p>';
    if (kids.length === 0) {
      html += '<p style="color:#6B7280;">No children on record.</p>';
    }
    for (var i = 0; i < kids.length; i++) {
      var k = kids[i];
      var meds = [];
      try { var r = await api('GET', '/parent/children/' + k.id + '/medications'); meds = r.medications || []; } catch (e) {}
      html += '<div style="background:white;border-radius:14px;padding:18px;margin-bottom:14px;">' +
        '<h3 style="margin:0 0 10px;font-size:17px;">' + esc(k.first_name + ' ' + k.last_name) + '</h3>' +
        (meds.length === 0 ? '<p style="color:#6B7280;">No medications on file.</p>' : meds.map(parentMedRow).join('')) +
        '</div>';
    }
    html += '</div>';
    container.innerHTML = html;
  }
  function parentMedRow(m) {
    return '<div style="padding:12px;border-top:1px solid #F3F4F6;">' +
      '<div style="font-weight:700;color:#111827;">' + esc(m.name) + (m.strength ? ' ' + esc(m.strength) : '') + ' ' + statusBadge(m.status) + '</div>' +
      '<div style="color:#6B7280;font-size:13px;margin-top:3px;">' + esc(m.dosage) + ' &middot; ' + esc(m.frequency) + '</div>' +
      (m.reason ? '<div style="color:#6B7280;font-size:13px;margin-top:2px;"><b>For:</b> ' + esc(m.reason) + '</div>' : '') +
      '<div style="color:#9CA3AF;font-size:12px;margin-top:4px;">' + fmtDate(m.starts_on) + ' &rarr; ' + (m.expires_on ? fmtDate(m.expires_on) : 'no expiry') + ' &middot; ' + (m.dose_count || 0) + ' doses logged</div>' +
      '</div>';
  }

  // ---------------------------------------------------------------
  // Shell registration
  // ---------------------------------------------------------------
  function render(container) {
    var r = role();
    if (r === 'agency_admin' || r === 'centre_director') return renderDirector(container);
    if (r === 'educator') return renderEducator(container);
    if (r === 'guardian') return renderParent(container);
    container.innerHTML = '<div style="padding:24px;color:#6B7280;">No medications view for this role.</div>';
  }

  if (Shell && Shell.registerScreen) {
    ['agency_admin', 'centre_director', 'educator', 'guardian'].forEach(function (r) {
      Shell.registerScreen(r + ':medications', render);
    });
  }
  window.KT = window.KT || {};
  window.KT.Medications = { render: render };
})(window);
