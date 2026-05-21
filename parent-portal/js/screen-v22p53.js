/* v22p53 — all new screens in one module.
   Lessons learned from v22p51:
     - never use html(`<newline>...`).firstChild (returns text node);
       set main.innerHTML directly
     - use window.KT.API_BASE not KT_API_BASE
     - use Array.isArray(u.roles) not u.primary_role
*/
(function (window) {
  'use strict';
  const KT = (window.KT = window.KT || {});
  const Api = new Proxy({}, {
    get(_, prop) {
      const a = window.KT && window.KT.Api;
      if (!a) throw new Error('KT.Api not loaded yet — call after app.js initialises');
      const v = a[prop];
      return typeof v === 'function' ? v.bind(a) : v;
    }
  });

  const apiBase = () => (KT.API_BASE) || 'https://api.kiddietrac.com/api/v1';
  const esc = (s) => String(s == null ? '' : s).replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  const fmtDate = (s) => { if (!s) return ''; const d = new Date(s); return d.toLocaleDateString(undefined, { year: 'numeric', month: 'short', day: 'numeric' }); };
  const userRoles = () => { try { return JSON.parse(sessionStorage.getItem('kt_user') || '{}').roles || []; } catch (e) { return []; } };
  const isStaff = () => userRoles().some(r => ['agency_admin', 'centre_director', 'educator', 'platform_admin'].includes(r));

  async function downloadAuthed(path, filename) {
    const tok = sessionStorage.getItem('kt_token');
    const r = await fetch(apiBase() + path, { headers: { Authorization: 'Bearer ' + tok } });
    const blob = await r.blob();
    const a = document.createElement('a');
    a.href = URL.createObjectURL(blob); a.download = filename;
    a.click();
  }

  // ============================ Meal planning ============================
  async function renderMenu(main) {
    main.innerHTML = '<div style="padding:24px;">Loading menu…</div>';
    // resolve a centre for the user; try director endpoint first, then admin endpoint
    let centreList = [];
    try {
      const r1 = await Api.get('/director/centres');
      centreList = r1.data || r1 || [];
    } catch (e) { /* fall through */ }
    if (!centreList.length) {
      try {
        const r2 = await Api.get('/admin/centres');
        centreList = r2.data || r2 || [];
      } catch (e) {}
    }
    if (!centreList.length) { main.innerHTML = '<div style="padding:24px;color:#9CA3AF;">No centres found for your role.</div>'; return; }
    const cid = centreList[0].id;
    const weekStart = new Date(); const dow = (weekStart.getDay() + 6) % 7; weekStart.setDate(weekStart.getDate() - dow);
    const weekStartStr = weekStart.toISOString().slice(0, 10);
    const r = await Api.get(`/operations/menu?centre_id=${cid}&week_start=${weekStartStr}`).catch(() => ({}));
    const week = r.data || { status: 'draft', notes: '' };
    const itemsByDayMeal = {};
    (r.items || []).forEach(it => { itemsByDayMeal[`${it.day_of_week}-${it.meal_type}`] = it; });
    const days = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];
    const meals = ['breakfast', 'morning_snack', 'lunch', 'afternoon_snack', 'dinner'];
    let table = `<table style="width:100%;border-collapse:collapse;margin-top:18px;">
      <thead><tr><th style="text-align:left;padding:8px;border-bottom:1px solid #E5E7EB;font-size:12px;color:#6B7280;">Meal</th>`;
    days.forEach((d, i) => table += `<th style="text-align:left;padding:8px;border-bottom:1px solid #E5E7EB;font-size:12px;color:#6B7280;">${d}</th>`);
    table += `</tr></thead><tbody>`;
    meals.forEach(m => {
      table += `<tr><td style="padding:10px 8px;border-bottom:1px solid #F3F4F6;font-weight:600;text-transform:capitalize;">${m.replace('_', ' ')}</td>`;
      days.forEach((d, di) => {
        const it = itemsByDayMeal[`${di + 1}-${m}`] || {};
        table += `<td style="padding:6px 4px;border-bottom:1px solid #F3F4F6;">
          <input data-d="${di + 1}" data-m="${m}" data-f="name" placeholder="—" value="${esc(it.name || '')}" style="width:100%;padding:6px;border:1px solid #E5E7EB;border-radius:4px;font-size:12px;">
          <input data-d="${di + 1}" data-m="${m}" data-f="allergens" placeholder="allergens" value="${esc(it.allergens || '')}" style="width:100%;padding:4px;border:1px solid #FEF3C7;border-radius:4px;font-size:11px;margin-top:3px;color:#92400E;">
        </td>`;
      });
      table += '</tr>';
    });
    table += '</tbody></table>';

    main.innerHTML = `<div style="padding:24px;max-width:1800px;margin:0 auto;">
      <h2 style="margin:0 0 6px;color:#1F6080;">Weekly menu</h2>
      <div style="color:#6B7280;font-size:14px;">${esc(centreList[0].name || '')} · Week of ${fmtDate(weekStartStr)}</div>
      <div style="margin-top:14px;display:flex;gap:10px;align-items:center;">
        <label style="font-size:13px;">Status
          <select id="mp-status" style="padding:7px;border:1px solid #E5E7EB;border-radius:4px;margin-left:6px;">
            <option value="draft" ${week.status === 'draft' ? 'selected' : ''}>Draft</option>
            <option value="published" ${week.status === 'published' ? 'selected' : ''}>Published</option>
            <option value="archived" ${week.status === 'archived' ? 'selected' : ''}>Archived</option>
          </select></label>
        <button id="mp-save" style="background:#1F6080;color:#fff;border:0;padding:9px 18px;border-radius:6px;cursor:pointer;">Save menu</button>
        <span id="mp-msg" style="font-size:13px;color:#047857;"></span>
      </div>
      ${table}
      <label style="display:block;margin-top:18px;font-size:13px;color:#374151;font-weight:600;">Notes (optional)
        <textarea id="mp-notes" rows="3" style="width:100%;padding:9px;border:1px solid #E5E7EB;border-radius:4px;margin-top:4px;">${esc(week.notes || '')}</textarea>
      </label>
    </div>`;

    document.getElementById('mp-save').onclick = async () => {
      const items = [];
      main.querySelectorAll('input[data-d][data-m][data-f="name"]').forEach(inp => {
        const name = inp.value.trim(); if (!name) return;
        const allergens = main.querySelector(`input[data-d="${inp.dataset.d}"][data-m="${inp.dataset.m}"][data-f="allergens"]`).value.trim();
        items.push({ day_of_week: +inp.dataset.d, meal_type: inp.dataset.m, name, allergens: allergens || null });
      });
      const status = document.getElementById('mp-status').value;
      try {
        await Api.post('/operations/menu', {
          centre_id: cid, week_start: weekStartStr, status, notes: document.getElementById('mp-notes').value, items,
        });
        document.getElementById('mp-msg').textContent = 'Saved.';
      } catch (e) { document.getElementById('mp-msg').textContent = 'Save failed.'; document.getElementById('mp-msg').style.color = '#B91C1C'; }
    };
  }

  // ============================ Allergy banner ============================
  async function renderAllergyAlerts(main) {
    main.innerHTML = '<div style="padding:24px;">Loading alerts…</div>';
    const r = await Api.get('/operations/allergy-alerts').catch(() => ({ data: [] }));
    main.innerHTML = `<div style="padding:24px;max-width:1800px;margin:0 auto;">
      <h2 style="margin:0 0 6px;color:#B91C1C;">⚠ Allergy & dietary alerts</h2>
      <p style="color:#6B7280;font-size:13px;">${r.count || 0} child(ren) with active alerts. Display on the Today screen and in the kitchen.</p>
      <div style="margin-top:18px;">
        ${(r.data || []).map(c => `<div style="border-left:4px solid #B91C1C;background:#FEF2F2;padding:14px 18px;margin-bottom:10px;border-radius:0 8px 8px 0;">
          <strong style="color:#991B1B;font-size:16px;">${esc(c.first_name)} ${esc(c.last_name)}</strong>
          <div style="margin-top:6px;font-size:13px;color:#374151;">
            ${(c.tags || []).map(t => {
              const colors = { allergy: { bg: '#FEE2E2', fg: '#991B1B' }, health: { bg: '#FEF3C7', fg: '#92400E' }, dietary: { bg: '#DBEAFE', fg: '#1E40AF' } };
              const sev = t.severity || 'note';
              const sevDot = sev === 'anaphylactic' ? '🚨' : sev === 'severe' ? '⚠' : '';
              const c = colors[t.kind] || { bg: '#F3F4F6', fg: '#374151' };
              return `<span style="background:${c.bg};color:${c.fg};padding:4px 10px;border-radius:6px;margin-right:6px;margin-bottom:4px;font-size:13px;display:inline-block;font-weight:600;">${sevDot} ${esc((t.kind || '').toUpperCase())}: ${esc(t.label || '')}</span>`;
            }).join('')}
          </div></div>`).join('') || '<div style="color:#9CA3AF;padding:20px;">No active allergy or dietary alerts.</div>'}
      </div>
    </div>`;
  }

  // ============================ Field trips ============================
  async function renderFieldTrips(main) {
    main.innerHTML = '<div style="padding:24px;">Loading…</div>';
    const r = await Api.get('/operations/field-trips').catch(() => ({ data: [] }));
    main.innerHTML = `<div style="padding:24px;max-width:1800px;margin:0 auto;">
      <div style="display:flex;justify-content:space-between;align-items:center;">
        <h2 style="margin:0;color:#1F6080;">Field trips</h2>
        <button id="ft-new" style="background:#1F6080;color:#fff;border:0;padding:10px 18px;border-radius:6px;cursor:pointer;">+ Plan trip</button>
      </div>
      <table style="width:100%;border-collapse:collapse;margin-top:18px;">
        <thead><tr>
          <th style="text-align:left;padding:8px;border-bottom:1px solid #E5E7EB;font-size:12px;color:#6B7280;">Trip</th>
          <th style="text-align:left;padding:8px;border-bottom:1px solid #E5E7EB;font-size:12px;color:#6B7280;">Date</th>
          <th style="text-align:left;padding:8px;border-bottom:1px solid #E5E7EB;font-size:12px;color:#6B7280;">Centre</th>
          <th style="text-align:left;padding:8px;border-bottom:1px solid #E5E7EB;font-size:12px;color:#6B7280;">Status</th>
          <th></th></tr></thead>
        <tbody>${(r.data || []).map(t => `<tr>
          <td style="padding:10px 8px;border-bottom:1px solid #F3F4F6;"><strong>${esc(t.title)}</strong><div style="color:#6B7280;font-size:12px;">${esc(t.destination)}</div></td>
          <td style="padding:10px 8px;border-bottom:1px solid #F3F4F6;">${fmtDate(t.trip_date)}</td>
          <td style="padding:10px 8px;border-bottom:1px solid #F3F4F6;">${esc(t.centre_name)}</td>
          <td style="padding:10px 8px;border-bottom:1px solid #F3F4F6;"><span style="background:#EFF6FF;color:#1E40AF;padding:2px 8px;border-radius:4px;font-size:12px;">${esc(t.status)}</span></td>
          <td style="padding:10px 8px;border-bottom:1px solid #F3F4F6;text-align:right;"><button data-ft-id="${t.id}" style="background:#F3F4F6;border:0;padding:6px 12px;border-radius:4px;cursor:pointer;">Permissions</button></td>
        </tr>`).join('') || '<tr><td colspan="5" style="padding:24px;color:#9CA3AF;text-align:center;">No field trips planned.</td></tr>'}
        </tbody></table>
    </div>`;
    document.getElementById('ft-new').onclick = () => openFieldTripModal();
    main.querySelectorAll('button[data-ft-id]').forEach(b => b.onclick = () => openPermissionsModal(+b.dataset['ft-id']));
  }
  async function openFieldTripModal() {
    /* v22p70: fix field-trip modal response keys */
    const childrenRes = await Api.get('/admin/children').catch(() => ({ children: [] }));
    const centresRes = await Api.get('/director/centres').catch(() => ({ centres: [] }));
    const children = childrenRes.children || childrenRes.data || [];
    const centres = centresRes.centres || centresRes.data || (Array.isArray(centresRes) ? centresRes : []);
    const m = document.createElement('div');
    m.style.cssText = 'position:fixed;inset:0;background:rgba(0,0,0,0.4);z-index:9999;display:flex;align-items:center;justify-content:center;';
    m.innerHTML = `<div style="background:#fff;padding:24px;border-radius:8px;max-width:560px;width:92%;max-height:88vh;overflow:auto;">
      <h3 style="margin:0 0 12px;">New field trip</h3>
      <label style="display:block;margin-top:10px;font-size:13px;font-weight:600;">Centre <select id="ft-centre" style="width:100%;padding:8px;border:1px solid #E5E7EB;border-radius:4px;">${centres.map(c => `<option value="${c.id}">${esc(c.name)}</option>`).join('')}</select></label>
      <label style="display:block;margin-top:10px;font-size:13px;font-weight:600;">Title <input id="ft-title" style="width:100%;padding:8px;border:1px solid #E5E7EB;border-radius:4px;"></label>
      <label style="display:block;margin-top:10px;font-size:13px;font-weight:600;">Destination <input id="ft-dest" style="width:100%;padding:8px;border:1px solid #E5E7EB;border-radius:4px;"></label>
      <label style="display:block;margin-top:10px;font-size:13px;font-weight:600;">Trip date <input id="ft-date" type="date" style="width:100%;padding:8px;border:1px solid #E5E7EB;border-radius:4px;"></label>
      <label style="display:block;margin-top:10px;font-size:13px;font-weight:600;">Cost per child ($) <input id="ft-cost" type="number" step="0.01" value="0" style="width:100%;padding:8px;border:1px solid #E5E7EB;border-radius:4px;"></label>
      <label style="display:block;margin-top:10px;font-size:13px;font-weight:600;">Children invited</label>
      <div id="ft-children" style="max-height:200px;overflow:auto;border:1px solid #E5E7EB;border-radius:4px;padding:8px;">
        ${children.map(c => `<label style="display:block;padding:4px 0;font-size:13px;"><input type="checkbox" data-cid="${c.id}"> ${esc(c.first_name)} ${esc(c.last_name)}</label>`).join('') || '<em>No children loaded.</em>'}
      </div>
      <div style="margin-top:18px;text-align:right;">
        <button id="ft-cancel" style="background:#F3F4F6;border:0;padding:9px 16px;border-radius:4px;margin-right:8px;cursor:pointer;">Cancel</button>
        <button id="ft-save" style="background:#1F6080;color:#fff;border:0;padding:9px 16px;border-radius:4px;cursor:pointer;">Create + send permission slips</button>
      </div></div>`;
    document.body.appendChild(m);
    m.querySelector('#ft-cancel').onclick = () => m.remove();
    m.querySelector('#ft-save').onclick = async () => {
      const childIds = Array.from(m.querySelectorAll('input[data-cid]:checked')).map(c => +c.dataset.cid);
      if (!childIds.length) { alert('Pick at least one child'); return; }
      await Api.post('/operations/field-trips', {
        centre_id: +m.querySelector('#ft-centre').value,
        title: m.querySelector('#ft-title').value,
        destination: m.querySelector('#ft-dest').value,
        trip_date: m.querySelector('#ft-date').value,
        cost_per_child: parseFloat(m.querySelector('#ft-cost').value) || 0,
        child_ids: childIds,
      });
      m.remove();
      renderFieldTrips(document.querySelector('main') || document.getElementById('main'));
    };
  }
  async function openPermissionsModal(tripId) {
    const r = await Api.get(`/operations/field-trips/${tripId}/permissions`);
    const m = document.createElement('div');
    m.style.cssText = 'position:fixed;inset:0;background:rgba(0,0,0,0.4);z-index:9999;display:flex;align-items:center;justify-content:center;';
    m.innerHTML = `<div style="background:#fff;padding:24px;border-radius:8px;max-width:520px;width:92%;">
      <h3>Permissions</h3>
      <div style="display:flex;gap:10px;margin-bottom:12px;">
        <div style="flex:1;background:#DCFCE7;padding:12px;border-radius:6px;text-align:center;"><div style="font-size:11px;color:#166534;font-weight:600;">APPROVED</div><div style="font-size:22px;font-weight:700;color:#166534;">${r.counts.approved}</div></div>
        <div style="flex:1;background:#FEE2E2;padding:12px;border-radius:6px;text-align:center;"><div style="font-size:11px;color:#B91C1C;font-weight:600;">DENIED</div><div style="font-size:22px;font-weight:700;color:#B91C1C;">${r.counts.denied}</div></div>
        <div style="flex:1;background:#FEF3C7;padding:12px;border-radius:6px;text-align:center;"><div style="font-size:11px;color:#92400E;font-weight:600;">PENDING</div><div style="font-size:22px;font-weight:700;color:#92400E;">${r.counts.pending}</div></div>
      </div>
      <div style="max-height:300px;overflow:auto;">${(r.data || []).map(p => `<div style="padding:8px 0;border-bottom:1px solid #F3F4F6;font-size:13px;display:flex;justify-content:space-between;">
        <span>${esc(p.first_name)} ${esc(p.last_name)}</span>
        <span style="color:${p.status === 'approved' ? '#047857' : p.status === 'denied' ? '#B91C1C' : '#D97706'};font-weight:600;">${p.status}</span></div>`).join('')}</div>
      <div style="margin-top:14px;text-align:right;"><button id="pm-close" style="background:#1F6080;color:#fff;border:0;padding:9px 16px;border-radius:4px;cursor:pointer;">Close</button></div>
    </div>`;
    document.body.appendChild(m);
    m.querySelector('#pm-close').onclick = () => m.remove();
  }

  // ============================ Substitutes ============================
  async function renderSubstitutes(main) {
    main.innerHTML = '<div style="padding:24px;">Loading…</div>';
    const [pool, requests] = await Promise.all([
      Api.get('/operations/substitutes').catch(() => ({ data: [] })),
      Api.get('/operations/sub-requests').catch(() => ({ data: [] })),
    ]);
    main.innerHTML = `<div style="padding:24px;max-width:1800px;margin:0 auto;">
      <h2 style="margin:0 0 6px;color:#1F6080;">Substitute teachers</h2>
      <p style="color:#6B7280;font-size:14px;">Manage your on-call pool and post open shifts.</p>
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:24px;margin-top:24px;">
        <div>
          <h3 style="font-size:14px;color:#374151;">Pool (${(pool.data || []).length})</h3>
          <table style="width:100%;border-collapse:collapse;">
            <thead><tr><th style="text-align:left;padding:6px;border-bottom:1px solid #E5E7EB;font-size:11px;color:#6B7280;">Name</th><th style="text-align:left;padding:6px;border-bottom:1px solid #E5E7EB;font-size:11px;color:#6B7280;">Priority</th></tr></thead>
            <tbody>${(pool.data || []).map(p => `<tr><td style="padding:8px 6px;border-bottom:1px solid #F3F4F6;font-size:13px;">${esc(p.user_name)}</td><td style="padding:8px 6px;border-bottom:1px solid #F3F4F6;font-size:13px;">${p.contact_priority}</td></tr>`).join('') || '<tr><td colspan="2" style="padding:14px;color:#9CA3AF;font-size:13px;">No substitutes added yet.</td></tr>'}</tbody>
          </table>
        </div>
        <div>
          <h3 style="font-size:14px;color:#374151;">Recent requests (${(requests.data || []).length})</h3>
          <table style="width:100%;border-collapse:collapse;">
            <thead><tr><th style="text-align:left;padding:6px;border-bottom:1px solid #E5E7EB;font-size:11px;color:#6B7280;">Date</th><th style="text-align:left;padding:6px;border-bottom:1px solid #E5E7EB;font-size:11px;color:#6B7280;">Status</th><th></th></tr></thead>
            <tbody>${(requests.data || []).map(r => `<tr><td style="padding:8px 6px;border-bottom:1px solid #F3F4F6;font-size:13px;">${fmtDate(r.shift_date)}</td>
              <td style="padding:8px 6px;border-bottom:1px solid #F3F4F6;font-size:13px;"><span style="color:${r.status === 'filled' ? '#047857' : '#D97706'};font-weight:600;">${r.status}</span></td>
              <td style="padding:8px 6px;border-bottom:1px solid #F3F4F6;font-size:13px;">${esc(r.assigned_name || '')}</td></tr>`).join('') || '<tr><td colspan="3" style="padding:14px;color:#9CA3AF;font-size:13px;">No requests yet.</td></tr>'}</tbody>
          </table>
          <button id="sr-new" style="margin-top:12px;background:#1F6080;color:#fff;border:0;padding:10px 16px;border-radius:6px;cursor:pointer;">+ Post open shift</button>
        </div>
      </div>
    </div>`;
    document.getElementById('sr-new').onclick = () => openSubRequestModal();
  }
  function openSubRequestModal() {
    const m = document.createElement('div');
    m.style.cssText = 'position:fixed;inset:0;background:rgba(0,0,0,0.4);z-index:9999;display:flex;align-items:center;justify-content:center;';
    m.innerHTML = `<div style="background:#fff;padding:24px;border-radius:8px;max-width:440px;width:92%;">
      <h3>New substitute request</h3>
      <label style="display:block;margin-top:10px;font-size:13px;font-weight:600;">Centre ID <input id="sr-cid" type="number" style="width:100%;padding:8px;border:1px solid #E5E7EB;border-radius:4px;"></label>
      <label style="display:block;margin-top:10px;font-size:13px;font-weight:600;">Shift date <input id="sr-date" type="date" style="width:100%;padding:8px;border:1px solid #E5E7EB;border-radius:4px;"></label>
      <label style="display:block;margin-top:10px;font-size:13px;font-weight:600;">Start <input id="sr-start" type="time" style="width:100%;padding:8px;border:1px solid #E5E7EB;border-radius:4px;"></label>
      <label style="display:block;margin-top:10px;font-size:13px;font-weight:600;">End <input id="sr-end" type="time" style="width:100%;padding:8px;border:1px solid #E5E7EB;border-radius:4px;"></label>
      <label style="display:block;margin-top:10px;font-size:13px;font-weight:600;">Reason <input id="sr-reason" placeholder="sick / personal / vacation" style="width:100%;padding:8px;border:1px solid #E5E7EB;border-radius:4px;"></label>
      <div style="margin-top:18px;text-align:right;">
        <button id="sr-cancel" style="background:#F3F4F6;border:0;padding:9px 16px;border-radius:4px;margin-right:8px;cursor:pointer;">Cancel</button>
        <button id="sr-save" style="background:#1F6080;color:#fff;border:0;padding:9px 16px;border-radius:4px;cursor:pointer;">Post + notify</button>
      </div></div>`;
    document.body.appendChild(m);
    m.querySelector('#sr-cancel').onclick = () => m.remove();
    m.querySelector('#sr-save').onclick = async () => {
      await Api.post('/operations/sub-requests', {
        centre_id: +m.querySelector('#sr-cid').value,
        shift_date: m.querySelector('#sr-date').value,
        shift_start: m.querySelector('#sr-start').value,
        shift_end: m.querySelector('#sr-end').value,
        reason: m.querySelector('#sr-reason').value,
      });
      m.remove();
      renderSubstitutes(document.querySelector('main') || document.getElementById('main'));
    };
  }

  // ============================ Inspection checklist ============================
  async function renderInspection(main) {
    main.innerHTML = '<div style="padding:24px;">Loading checklist…</div>';
    const r = await Api.get('/operations/inspection');
    const totalDone = r.done; const totalAll = r.total;
    const pct = totalAll ? Math.round((totalDone / totalAll) * 100) : 0;
    let html = `<div style="padding:24px;max-width:1800px;margin:0 auto;">
      <h2 style="margin:0 0 4px;color:#1F6080;">Inspection prep checklist</h2>
      <p style="color:#6B7280;font-size:14px;">Provincial / municipal inspectors typically cover these items. ${totalDone}/${totalAll} marked complete (${pct}%).</p>
      <div style="background:#E5E7EB;height:10px;border-radius:5px;margin:14px 0;"><div style="background:#10B981;height:100%;width:${pct}%;border-radius:5px;"></div></div>`;
    Object.entries(r.data || {}).forEach(([cat, items]) => {
      html += `<h3 style="margin-top:24px;color:#1F6080;font-size:16px;">${esc(cat)}</h3><table style="width:100%;border-collapse:collapse;">`;
      items.forEach(it => {
        const bg = it.status === 'pass' ? '#DCFCE7' : it.status === 'fail' ? '#FEE2E2' : it.status === 'na' ? '#F3F4F6' : '#FFFFFF';
        html += `<tr style="background:${bg}"><td style="padding:10px 12px;border-bottom:1px solid #E5E7EB;font-size:13px;">${esc(it.item_text)}</td>
          <td style="padding:10px 12px;border-bottom:1px solid #E5E7EB;width:300px;">
            <select data-it-id="${it.id}" style="padding:6px;border:1px solid #E5E7EB;border-radius:4px;font-size:12px;">
              <option value="pending" ${it.status === 'pending' ? 'selected' : ''}>Pending</option>
              <option value="pass" ${it.status === 'pass' ? 'selected' : ''}>✓ Pass</option>
              <option value="fail" ${it.status === 'fail' ? 'selected' : ''}>✗ Fail</option>
              <option value="na" ${it.status === 'na' ? 'selected' : ''}>N/A</option></select></td></tr>`;
      });
      html += '</table>';
    });
    html += '</div>';
    main.innerHTML = html;
    main.querySelectorAll('select[data-it-id]').forEach(s => s.onchange = async () => {
      await Api.patch(`/operations/inspection/${s.dataset['it-id']}`, { status: s.value });
      s.closest('tr').style.background = s.value === 'pass' ? '#DCFCE7' : s.value === 'fail' ? '#FEE2E2' : s.value === 'na' ? '#F3F4F6' : '#FFFFFF';
    });
  }

  // ============================ CWELCC report ============================
  async function renderCwelcc(main) {
    main.innerHTML = '<div style="padding:24px;">Loading CWELCC report…</div>';
    const month = new Date().toISOString().slice(0, 7);
    const r = await Api.get(`/compliance/cwelcc/monthly?month=${month}`);
    main.innerHTML = `<div style="padding:24px;max-width:1800px;margin:0 auto;">
      <div style="display:flex;justify-content:space-between;align-items:center;">
        <div>
          <h2 style="margin:0 0 4px;color:#1F6080;">CWELCC subsidy report</h2>
          <div style="color:#6B7280;font-size:14px;">Month: <input id="cw-month" type="month" value="${month}" style="padding:6px;border:1px solid #E5E7EB;border-radius:4px;font-size:14px;"></div>
        </div>
        <div>
          <button id="cw-csv" style="background:#059669;color:#fff;border:0;padding:9px 16px;border-radius:6px;margin-right:8px;cursor:pointer;">⤓ CSV</button>
          <button id="cw-pdf" style="background:#1F6080;color:#fff;border:0;padding:9px 16px;border-radius:6px;cursor:pointer;">⤓ PDF</button>
        </div>
      </div>
      <div style="display:flex;gap:14px;margin-top:18px;">
        <div style="flex:1;background:#EFF6FF;padding:16px;border-radius:8px;"><div style="font-size:11px;color:#1E40AF;font-weight:600;">CHILDREN</div><div style="font-size:24px;font-weight:700;color:#1E40AF;">${r.totals.child_count}</div></div>
        <div style="flex:1;background:#F0FDF4;padding:16px;border-radius:8px;"><div style="font-size:11px;color:#166534;font-weight:600;">GROSS</div><div style="font-size:24px;font-weight:700;color:#166534;">$${(r.totals.gross || 0).toFixed(2)}</div></div>
        <div style="flex:1;background:#FEF3C7;padding:16px;border-radius:8px;"><div style="font-size:11px;color:#92400E;font-weight:600;">SUBSIDY</div><div style="font-size:24px;font-weight:700;color:#92400E;">$${(r.totals.subsidy || 0).toFixed(2)}</div></div>
        <div style="flex:1;background:#FAE8FF;padding:16px;border-radius:8px;"><div style="font-size:11px;color:#86198F;font-weight:600;">PARENT</div><div style="font-size:24px;font-weight:700;color:#86198F;">$${(r.totals.parent || 0).toFixed(2)}</div></div>
      </div>
      <table style="width:100%;border-collapse:collapse;margin-top:24px;">
        <thead><tr>
          <th style="text-align:left;padding:8px;border-bottom:1px solid #E5E7EB;font-size:12px;color:#6B7280;">Child</th>
          <th style="text-align:left;padding:8px;border-bottom:1px solid #E5E7EB;font-size:12px;color:#6B7280;">Family</th>
          <th style="text-align:left;padding:8px;border-bottom:1px solid #E5E7EB;font-size:12px;color:#6B7280;">Centre</th>
          <th style="text-align:right;padding:8px;border-bottom:1px solid #E5E7EB;font-size:12px;color:#6B7280;">Gross</th>
          <th style="text-align:right;padding:8px;border-bottom:1px solid #E5E7EB;font-size:12px;color:#6B7280;">Subsidy</th>
          <th style="text-align:right;padding:8px;border-bottom:1px solid #E5E7EB;font-size:12px;color:#6B7280;">Parent</th>
        </tr></thead>
        <tbody>${(r.data || []).map(row => `<tr>
          <td style="padding:9px 8px;border-bottom:1px solid #F3F4F6;font-size:13px;">${esc(row.child_name)}</td>
          <td style="padding:9px 8px;border-bottom:1px solid #F3F4F6;font-size:13px;">${esc(row.family_name)}</td>
          <td style="padding:9px 8px;border-bottom:1px solid #F3F4F6;font-size:13px;">${esc(row.centre_name)}</td>
          <td style="padding:9px 8px;border-bottom:1px solid #F3F4F6;font-size:13px;text-align:right;">$${row.gross_fee.toFixed(2)}</td>
          <td style="padding:9px 8px;border-bottom:1px solid #F3F4F6;font-size:13px;text-align:right;">$${row.subsidy_amount.toFixed(2)}</td>
          <td style="padding:9px 8px;border-bottom:1px solid #F3F4F6;font-size:13px;text-align:right;">$${row.parent_portion.toFixed(2)}</td>
        </tr>`).join('') || '<tr><td colspan="6" style="padding:24px;color:#9CA3AF;text-align:center;">No CWELCC-enrolled families for this month.</td></tr>'}</tbody>
      </table>
    </div>`;
    document.getElementById('cw-month').onchange = (e) => { renderCwelcc(main); };
    document.getElementById('cw-csv').onclick = () => downloadAuthed(`/compliance/cwelcc/monthly/csv?month=${document.getElementById('cw-month').value}`, `CWELCC-${month}.csv`);
    document.getElementById('cw-pdf').onclick = () => downloadAuthed(`/compliance/cwelcc/monthly/pdf?month=${document.getElementById('cw-month').value}`, `CWELCC-${month}.pdf`);
  }

  // ============================ Cohort retention ============================
  async function renderRetention(main) {
    main.innerHTML = '<div style="padding:24px;">Computing…</div>';
    const r = await Api.get('/compliance/retention');
    main.innerHTML = `<div style="padding:24px;max-width:1800px;margin:0 auto;">
      <h2 style="margin:0 0 6px;color:#1F6080;">Cohort retention</h2>
      <p style="color:#6B7280;font-size:14px;">% of children still enrolled, broken down by enrolment-month cohort. 12-month rolling window.</p>
      <table style="width:100%;border-collapse:collapse;margin-top:18px;">
        <thead><tr>
          <th style="text-align:left;padding:8px;border-bottom:1px solid #E5E7EB;font-size:12px;color:#6B7280;">Cohort</th>
          <th style="text-align:right;padding:8px;border-bottom:1px solid #E5E7EB;font-size:12px;color:#6B7280;">Enrolled</th>
          <th style="text-align:right;padding:8px;border-bottom:1px solid #E5E7EB;font-size:12px;color:#6B7280;">Still</th>
          <th style="text-align:left;padding:8px;border-bottom:1px solid #E5E7EB;font-size:12px;color:#6B7280;">Retention</th>
        </tr></thead>
        <tbody>${(r.data || []).map(c => {
          const pct = c.retention_pct == null ? '—' : c.retention_pct + '%';
          const w = c.retention_pct == null ? 0 : Math.max(2, c.retention_pct);
          const color = c.retention_pct == null ? '#E5E7EB' : c.retention_pct >= 80 ? '#10B981' : c.retention_pct >= 50 ? '#F59E0B' : '#EF4444';
          return `<tr><td style="padding:9px 8px;border-bottom:1px solid #F3F4F6;font-weight:600;">${c.cohort}</td>
            <td style="padding:9px 8px;border-bottom:1px solid #F3F4F6;text-align:right;">${c.enrolled}</td>
            <td style="padding:9px 8px;border-bottom:1px solid #F3F4F6;text-align:right;">${c.still_enrolled}</td>
            <td style="padding:9px 8px;border-bottom:1px solid #F3F4F6;"><div style="display:flex;align-items:center;gap:8px;"><div style="flex:1;background:#E5E7EB;height:12px;border-radius:6px;max-width:240px;"><div style="background:${color};height:100%;width:${w}%;border-radius:6px;"></div></div><span style="color:${color};font-weight:600;min-width:50px;">${pct}</span></div></td></tr>`;
        }).join('')}</tbody></table>
    </div>`;
  }

  // ============================ Enrollment forecast ============================
  async function renderForecast(main) {
    main.innerHTML = '<div style="padding:24px;">Computing forecast…</div>';
    const r = await Api.get('/ai/enrollment-forecast');
    const maxP = Math.max(...r.projection.map(p => p.projected_enrolment), r.current_enrolment);
    main.innerHTML = `<div style="padding:24px;max-width:1800px;margin:0 auto;">
      <h2 style="margin:0 0 6px;color:#1F6080;">Enrolment forecast</h2>
      <p style="color:#6B7280;font-size:14px;">6-month projection using tour pipeline + assumed 30% conversion + 2.5% monthly churn.</p>
      <div style="display:flex;gap:14px;margin-top:18px;">
        <div style="flex:1;background:#EFF6FF;padding:16px;border-radius:8px;"><div style="font-size:11px;color:#1E40AF;font-weight:600;">CURRENT</div><div style="font-size:24px;font-weight:700;color:#1E40AF;">${r.current_enrolment}</div></div>
        <div style="flex:1;background:#FAE8FF;padding:16px;border-radius:8px;"><div style="font-size:11px;color:#86198F;font-weight:600;">TOUR PIPELINE (60d)</div><div style="font-size:24px;font-weight:700;color:#86198F;">${r.tour_pipeline_60d}</div></div>
        <div style="flex:1;background:#DCFCE7;padding:16px;border-radius:8px;"><div style="font-size:11px;color:#166534;font-weight:600;">MONTHLY ADDS</div><div style="font-size:24px;font-weight:700;color:#166534;">+${r.monthly_adds_assumption}</div></div>
      </div>
      <h3 style="margin-top:24px;font-size:14px;color:#374151;">Projection</h3>
      <div style="display:flex;align-items:flex-end;gap:14px;height:200px;padding:18px;background:#F9FAFB;border-radius:8px;">
        ${r.projection.map(p => `<div style="flex:1;text-align:center;">
          <div style="background:#1F6080;height:${(p.projected_enrolment / maxP) * 160}px;border-radius:6px 6px 0 0;display:flex;align-items:flex-end;justify-content:center;color:#fff;font-weight:700;padding-bottom:6px;font-size:13px;">${p.projected_enrolment}</div>
          <div style="margin-top:6px;font-size:11px;color:#6B7280;">${p.month}</div></div>`).join('')}
      </div>
    </div>`;
  }

  // ============================ Anomaly detection ============================
  async function renderAnomalies(main) {
    main.innerHTML = '<div style="padding:24px;">Scanning…</div>';
    const r = await Api.get('/ai/attendance-anomalies?days=30');
    main.innerHTML = `<div style="padding:24px;max-width:1800px;margin:0 auto;">
      <h2 style="margin:0 0 6px;color:#1F6080;">Attendance anomalies</h2>
      <p style="color:#6B7280;font-size:14px;">${(r.data || []).length} child(ren) with unusual activity patterns in the last ${r.window_days} days.</p>
      <table style="width:100%;border-collapse:collapse;margin-top:18px;">
        <thead><tr>
          <th style="text-align:left;padding:8px;border-bottom:1px solid #E5E7EB;font-size:12px;color:#6B7280;">Child</th>
          <th style="text-align:left;padding:8px;border-bottom:1px solid #E5E7EB;font-size:12px;color:#6B7280;">Signals</th>
          <th style="text-align:right;padding:8px;border-bottom:1px solid #E5E7EB;font-size:12px;color:#6B7280;">Obs 30d</th>
          <th style="text-align:right;padding:8px;border-bottom:1px solid #E5E7EB;font-size:12px;color:#6B7280;">Obs 90d</th>
        </tr></thead>
        <tbody>${(r.data || []).map(a => `<tr>
          <td style="padding:10px 8px;border-bottom:1px solid #F3F4F6;font-weight:600;">${esc(a.child_name)}</td>
          <td style="padding:10px 8px;border-bottom:1px solid #F3F4F6;font-size:13px;color:#B91C1C;">${a.signals.join(' · ')}</td>
          <td style="padding:10px 8px;border-bottom:1px solid #F3F4F6;text-align:right;">${a.obs_30}</td>
          <td style="padding:10px 8px;border-bottom:1px solid #F3F4F6;text-align:right;">${a.obs_90}</td>
        </tr>`).join('') || '<tr><td colspan="4" style="padding:24px;color:#9CA3AF;text-align:center;">No anomalies — all children showing normal activity.</td></tr>'}</tbody></table>
    </div>`;
  }

  // ============================ Cert expiry calendar ============================
  async function renderExpiryCalendar(main) {
    main.innerHTML = '<div style="padding:24px;">Loading…</div>';
    const r = await Api.get('/compliance/expiry-calendar?within_days=180');
    const groups = { expired: [], soon: [], upcoming: [], future: [] };
    (r.data || []).forEach(row => groups[row.bucket].push(row));
    main.innerHTML = `<div style="padding:24px;max-width:1800px;margin:0 auto;">
      <h2 style="margin:0 0 6px;color:#1F6080;">Renewal calendar</h2>
      <p style="color:#6B7280;font-size:14px;">Certifications + background checks. Combines all sources into a single timeline.</p>
      ${['expired', 'soon', 'upcoming', 'future'].map(b => {
        const titles = { expired: '⛔ Expired', soon: '⚠ Next 30 days', upcoming: 'Next 90 days', future: 'Later' };
        const colors = { expired: '#FEE2E2', soon: '#FEF3C7', upcoming: '#EFF6FF', future: '#F9FAFB' };
        return `<h3 style="margin-top:22px;font-size:15px;">${titles[b]} (${groups[b].length})</h3>
          ${groups[b].length ? `<table style="width:100%;border-collapse:collapse;"><tbody>${groups[b].map(r => `<tr style="background:${colors[b]};">
            <td style="padding:9px 12px;border-bottom:1px solid #F3F4F6;font-size:13px;width:60%;"><strong>${esc(r.user_name)}</strong> · ${esc(r.type)} <span style="color:#6B7280;font-size:11px;">(${esc(r.source)})</span></td>
            <td style="padding:9px 12px;border-bottom:1px solid #F3F4F6;font-size:13px;">${fmtDate(r.expires_at)} <span style="color:#6B7280;font-size:11px;">(${Math.abs(r.days_until)}d ${r.days_until < 0 ? 'ago' : 'left'})</span></td>
          </tr>`).join('')}</tbody></table>` : '<div style="color:#9CA3AF;padding:8px;font-size:13px;">None.</div>'}`;
      }).join('')}
    </div>`;
  }

  // Expose
  window.KT = window.KT || {};
  window.KT.V22p53 = {
    renderMenu, renderAllergyAlerts, renderFieldTrips, renderSubstitutes,
    renderInspection, renderCwelcc, renderRetention, renderForecast,
    renderAnomalies, renderExpiryCalendar,
  };
})(window);
