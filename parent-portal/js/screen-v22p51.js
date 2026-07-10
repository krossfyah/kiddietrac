/* v22p51 — all new screens in one module, registered via shim.
   Pattern: each render function takes the main container element.
   Uses window.KT.Api and window.KT.Dom helpers.
*/
(function (window) {
  'use strict';
  const Api = window.KT && window.KT.Api;
  const Dom = window.KT && window.KT.Dom;
  if (!Api || !Dom) { console.warn('v22p51: KT.Api / KT.Dom unavailable'); return; }

  const el = (tag, attrs, children) => Dom.el(tag, attrs || {}, children);
  const html = (s) => { const d = document.createElement('div'); d.innerHTML = s; return d; };

  // ============================ Time-off ============================
  async function renderTimeOff(main) {
    main.innerHTML = '<div style="padding:20px;">Loading…</div>';
    const [mine, pending] = await Promise.all([
      Api.get('/time-off/mine').catch(() => ({ data: [] })),
      isStaffOrAdmin() ? Api.get('/admin/time-off?status=pending').catch(() => ({ data: [] })) : Promise.resolve({ data: [] }),
    ]);
    main.innerHTML = '';
    main.appendChild(html(`
      <div style="padding:24px;max-width:1800px;margin:0 auto;">
        <h2 style="margin:0 0 16px;color:#1F6080;">Time off</h2>
        <button id="tor-new" class="btn btn-primary" style="padding:10px 18px;border-radius:6px;background:#1F6080;color:#fff;border:0;font-weight:600;cursor:pointer;">+ Request time off</button>
        <h3 style="margin-top:32px;font-size:16px;color:#374151;">Your requests</h3>
        <div id="tor-mine"></div>
        ${isStaffOrAdmin() ? '<h3 style="margin-top:32px;font-size:16px;color:#374151;">Pending team requests</h3><div id="tor-pending"></div>' : ''}
      </div>`).firstElementChild);

    renderTorList(document.getElementById('tor-mine'), mine.data || [], false);
    if (isStaffOrAdmin()) renderTorList(document.getElementById('tor-pending'), pending.data || [], true);
    document.getElementById('tor-new').onclick = () => openTorModal();
  }
  function renderTorList(host, rows, isApprover) {
    if (!rows.length) { host.innerHTML = '<div style="color:#9CA3AF;padding:12px;">No requests.</div>'; return; }
    host.innerHTML = '';
    const tbl = document.createElement('table');
    tbl.style.cssText = 'width:100%;border-collapse:collapse;margin-top:8px;';
    tbl.innerHTML = '<thead><tr><th style="text-align:left;padding:8px;border-bottom:1px solid #E5E7EB;font-size:12px;color:#6B7280;">Who</th><th style="text-align:left;padding:8px;border-bottom:1px solid #E5E7EB;font-size:12px;color:#6B7280;">Type</th><th style="text-align:left;padding:8px;border-bottom:1px solid #E5E7EB;font-size:12px;color:#6B7280;">Dates</th><th style="text-align:left;padding:8px;border-bottom:1px solid #E5E7EB;font-size:12px;color:#6B7280;">Status</th><th></th></tr></thead><tbody></tbody>';
    const tb = tbl.querySelector('tbody');
    rows.forEach(r => {
      const tr = document.createElement('tr');
      const status = (r.status || 'pending').toLowerCase();
      const color = status === 'approved' ? '#047857' : status === 'denied' ? '#B91C1C' : '#D97706';
      tr.innerHTML = `<td style="padding:10px 8px;border-bottom:1px solid #F3F4F6;">${escapeHtml(r.user_name || 'You')}</td>
        <td style="padding:10px 8px;border-bottom:1px solid #F3F4F6;text-transform:capitalize;">${escapeHtml(r.request_type)}</td>
        <td style="padding:10px 8px;border-bottom:1px solid #F3F4F6;">${fmtDate(r.start_at)} – ${fmtDate(r.end_at)}</td>
        <td style="padding:10px 8px;border-bottom:1px solid #F3F4F6;"><span style="color:${color};font-weight:600;text-transform:capitalize;">${status}</span></td>
        <td style="padding:10px 8px;border-bottom:1px solid #F3F4F6;text-align:right;">${isApprover && status === 'pending' ? `<button data-act="approved" data-id="${r.id}" style="background:#10B981;color:#fff;border:0;padding:6px 12px;border-radius:4px;cursor:pointer;margin-right:6px;">Approve</button><button data-act="denied" data-id="${r.id}" style="background:#EF4444;color:#fff;border:0;padding:6px 12px;border-radius:4px;cursor:pointer;">Deny</button>` : ''}</td>`;
      tb.appendChild(tr);
    });
    host.appendChild(tbl);
    host.querySelectorAll('button[data-act]').forEach(b => {
      b.onclick = async () => {
        await Api.patch(`/admin/time-off/${b.dataset.id}`, { status: b.dataset.act });
        renderTimeOff(document.getElementById('main') || document.querySelector('main'));
      };
    });
  }
  function openTorModal() {
    const m = document.createElement('div');
    m.style.cssText = 'position:fixed;inset:0;background:rgba(0,0,0,0.4);z-index:9999;display:flex;align-items:center;justify-content:center;';
    m.innerHTML = `<div style="background:#fff;padding:24px;border-radius:8px;max-width:480px;width:90%;">
      <h3 style="margin:0 0 12px;">New time-off request</h3>
      <label style="display:block;margin-top:10px;font-size:13px;color:#374151;font-weight:600;">Type
        <select id="tor-type" style="width:100%;padding:8px;border:1px solid #E5E7EB;border-radius:4px;margin-top:4px;">
          <option value="vacation">Vacation</option><option value="sick">Sick</option>
          <option value="personal">Personal</option><option value="bereavement">Bereavement</option>
          <option value="jury">Jury duty</option><option value="other">Other</option></select></label>
      <label style="display:block;margin-top:10px;font-size:13px;color:#374151;font-weight:600;">Start date
        <input id="tor-start" type="date" style="width:100%;padding:8px;border:1px solid #E5E7EB;border-radius:4px;margin-top:4px;"></label>
      <label style="display:block;margin-top:10px;font-size:13px;color:#374151;font-weight:600;">End date
        <input id="tor-end" type="date" style="width:100%;padding:8px;border:1px solid #E5E7EB;border-radius:4px;margin-top:4px;"></label>
      <label style="display:block;margin-top:10px;font-size:13px;color:#374151;font-weight:600;">Reason
        <textarea id="tor-reason" rows="3" style="width:100%;padding:8px;border:1px solid #E5E7EB;border-radius:4px;margin-top:4px;"></textarea></label>
      <div style="margin-top:20px;text-align:right;">
        <button id="tor-cancel" style="background:#F3F4F6;border:0;padding:9px 16px;border-radius:4px;margin-right:8px;cursor:pointer;">Cancel</button>
        <button id="tor-submit" style="background:#1F6080;color:#fff;border:0;padding:9px 16px;border-radius:4px;cursor:pointer;">Submit</button>
      </div></div>`;
    document.body.appendChild(m);
    m.querySelector('#tor-cancel').onclick = () => m.remove();
    m.querySelector('#tor-submit').onclick = async () => {
      const payload = {
        request_type: m.querySelector('#tor-type').value,
        start_at: m.querySelector('#tor-start').value,
        end_at: m.querySelector('#tor-end').value,
        reason: m.querySelector('#tor-reason').value,
      };
      if (!payload.start_at || !payload.end_at) { alert('Pick start and end dates'); return; }
      await Api.post('/time-off', payload);
      m.remove();
      renderTimeOff(document.getElementById('main') || document.querySelector('main'));
    };
  }

  // ============================ Background checks ============================
  async function renderBackgroundChecks(main) {
    main.innerHTML = '<div style="padding:20px;">Loading…</div>';
    const res = await Api.get('/admin/background-checks').catch(() => ({ data: [] }));
    main.innerHTML = '';
    const root = html(`
      <div style="padding:24px;max-width:1800px;margin:0 auto;">
        <div style="display:flex;justify-content:space-between;align-items:center;">
          <h2 style="margin:0;color:#1F6080;">Background checks</h2>
          <div>
            <button id="bgc-csv" style="background:#059669;color:#fff;border:0;padding:9px 16px;border-radius:6px;margin-right:8px;cursor:pointer;">⤓ CSV</button>
            <button id="bgc-add" style="background:#1F6080;color:#fff;border:0;padding:9px 16px;border-radius:6px;cursor:pointer;">+ Record</button>
          </div>
        </div>
        <div id="bgc-list" style="margin-top:18px;"></div>
      </div>`).firstElementChild;
    main.appendChild(root);
    renderBgcList(root.querySelector('#bgc-list'), res.data || []);
    root.querySelector('#bgc-csv').onclick = () => downloadAuthed('/admin/background-checks?format=csv', 'background-checks.csv');
    root.querySelector('#bgc-add').onclick = () => openBgcModal(null);
  }
  function renderBgcList(host, rows) {
    if (!rows.length) { host.innerHTML = '<div style="color:#9CA3AF;padding:12px;">No records.</div>'; return; }
    host.innerHTML = '';
    const tbl = document.createElement('table');
    tbl.style.cssText = 'width:100%;border-collapse:collapse;';
    tbl.innerHTML = '<thead><tr><th style="text-align:left;padding:8px;border-bottom:1px solid #E5E7EB;font-size:12px;color:#6B7280;">Staff</th><th style="text-align:left;padding:8px;border-bottom:1px solid #E5E7EB;font-size:12px;color:#6B7280;">Type</th><th style="text-align:left;padding:8px;border-bottom:1px solid #E5E7EB;font-size:12px;color:#6B7280;">Expires</th><th style="text-align:left;padding:8px;border-bottom:1px solid #E5E7EB;font-size:12px;color:#6B7280;">Status</th><th></th></tr></thead><tbody></tbody>';
    const tb = tbl.querySelector('tbody');
    rows.forEach(r => {
      const color = r.status_bucket === 'expired' ? '#B91C1C' : r.status_bucket === 'expiring' ? '#D97706' : '#047857';
      const tr = document.createElement('tr');
      tr.innerHTML = `<td style="padding:10px 8px;border-bottom:1px solid #F3F4F6;">${escapeHtml(r.user_name)}</td>
        <td style="padding:10px 8px;border-bottom:1px solid #F3F4F6;text-transform:uppercase;">${escapeHtml(r.check_type)}</td>
        <td style="padding:10px 8px;border-bottom:1px solid #F3F4F6;">${fmtDate(r.expires_at)}</td>
        <td style="padding:10px 8px;border-bottom:1px solid #F3F4F6;"><span style="color:${color};font-weight:600;">${r.status_bucket}</span></td>
        <td style="padding:10px 8px;border-bottom:1px solid #F3F4F6;text-align:right;"><button data-id="${r.id}" data-edit="1" style="background:#F3F4F6;border:0;padding:6px 12px;border-radius:4px;cursor:pointer;margin-right:6px;">Edit</button><button data-id="${r.id}" data-del="1" style="background:#FEE2E2;color:#B91C1C;border:0;padding:6px 12px;border-radius:4px;cursor:pointer;">Delete</button></td>`;
      tb.appendChild(tr);
    });
    host.appendChild(tbl);
    host.querySelectorAll('button[data-edit]').forEach(b => b.onclick = () => openBgcModal(rows.find(r => r.id == b.dataset.id)));
    host.querySelectorAll('button[data-del]').forEach(b => b.onclick = async () => {
      if (!confirm('Delete this background check?')) return;
      await Api.delete(`/admin/background-checks/${b.dataset.id}`);
      renderBackgroundChecks(document.getElementById('main') || document.querySelector('main'));
    });
  }
  async function openBgcModal(row) {
    const staffRes = await Api.get('/admin/users?role=educator,centre_director,agency_admin').catch(() => ({ data: [] }));
    const staff = staffRes.data || [];
    const m = document.createElement('div');
    m.style.cssText = 'position:fixed;inset:0;background:rgba(0,0,0,0.4);z-index:9999;display:flex;align-items:center;justify-content:center;';
    m.innerHTML = `<div style="background:#fff;padding:24px;border-radius:8px;max-width:480px;width:90%;">
      <h3 style="margin:0 0 12px;">${row ? 'Edit' : 'Add'} background check</h3>
      <label style="display:block;margin-top:10px;font-size:13px;font-weight:600;">Staff
        <select id="bgc-user" style="width:100%;padding:8px;border:1px solid #E5E7EB;border-radius:4px;">
          ${staff.map(s => `<option value="${s.id}" ${row && row.user_id == s.id ? 'selected' : ''}>${escapeHtml(s.name)}</option>`).join('')}
        </select></label>
      <label style="display:block;margin-top:10px;font-size:13px;font-weight:600;">Type
        <select id="bgc-type" style="width:100%;padding:8px;border:1px solid #E5E7EB;border-radius:4px;">
          ${['vss', 'criminal', 'driver', 'reference', 'other'].map(t => `<option value="${t}" ${row && row.check_type === t ? 'selected' : ''}>${t.toUpperCase()}</option>`).join('')}
        </select></label>
      <label style="display:block;margin-top:10px;font-size:13px;font-weight:600;">Reference / cert #
        <input id="bgc-ref" value="${row ? escapeHtml(row.reference || '') : ''}" style="width:100%;padding:8px;border:1px solid #E5E7EB;border-radius:4px;"></label>
      <label style="display:block;margin-top:10px;font-size:13px;font-weight:600;">Issued
        <input id="bgc-issued" type="date" value="${row && row.issued_at ? row.issued_at.substring(0, 10) : ''}" style="width:100%;padding:8px;border:1px solid #E5E7EB;border-radius:4px;"></label>
      <label style="display:block;margin-top:10px;font-size:13px;font-weight:600;">Expires
        <input id="bgc-expires" type="date" value="${row ? row.expires_at.substring(0, 10) : ''}" style="width:100%;padding:8px;border:1px solid #E5E7EB;border-radius:4px;"></label>
      <div style="margin-top:18px;text-align:right;">
        <button id="bgc-cancel" style="background:#F3F4F6;border:0;padding:9px 16px;border-radius:4px;margin-right:8px;cursor:pointer;">Cancel</button>
        <button id="bgc-save" style="background:#1F6080;color:#fff;border:0;padding:9px 16px;border-radius:4px;cursor:pointer;">Save</button>
      </div></div>`;
    document.body.appendChild(m);
    m.querySelector('#bgc-cancel').onclick = () => m.remove();
    m.querySelector('#bgc-save').onclick = async () => {
      const payload = {
        id: row ? row.id : null,
        user_id: parseInt(m.querySelector('#bgc-user').value, 10),
        check_type: m.querySelector('#bgc-type').value,
        reference: m.querySelector('#bgc-ref').value,
        issued_at: m.querySelector('#bgc-issued').value || null,
        expires_at: m.querySelector('#bgc-expires').value,
      };
      await Api.post('/admin/background-checks', payload);
      m.remove();
      renderBackgroundChecks(document.getElementById('main') || document.querySelector('main'));
    };
  }

  // ============================ Payroll ============================
  async function renderPayroll(main) {
    main.innerHTML = `
      <div style="padding:24px;max-width:1800px;margin:0 auto;">
        <h2 style="margin:0 0 16px;color:#1F6080;">Payroll report</h2>
        <div style="display:flex;gap:12px;margin-bottom:18px;align-items:end;">
          <label style="font-size:13px;color:#374151;">From <input id="pr-from" type="date" style="display:block;margin-top:4px;padding:8px;border:1px solid #E5E7EB;border-radius:4px;"></label>
          <label style="font-size:13px;color:#374151;">To <input id="pr-to" type="date" style="display:block;margin-top:4px;padding:8px;border:1px solid #E5E7EB;border-radius:4px;"></label>
          <button id="pr-run" style="background:#1F6080;color:#fff;border:0;padding:10px 16px;border-radius:6px;cursor:pointer;">Run</button>
          <button id="pr-csv" style="background:#059669;color:#fff;border:0;padding:10px 16px;border-radius:6px;cursor:pointer;">⤓ CSV</button>
        </div>
        <div id="pr-result"></div></div>`;
    // v22p98: default to a trailing 30-day window (was first-of-month → today,
    // a single empty day on the 1st, hiding the whole prior pay period).
    const today = new Date();
    const startWin = new Date(); startWin.setDate(today.getDate() - 30);
    document.getElementById('pr-from').valueAsDate = startWin;
    document.getElementById('pr-to').valueAsDate = today;
    document.getElementById('pr-run').onclick = () => runPayroll();
    document.getElementById('pr-csv').onclick = () => {
      const f = document.getElementById('pr-from').value, t = document.getElementById('pr-to').value;
      downloadAuthed(`/admin/payroll?from=${f}&to=${t}&format=csv`, `payroll-${f}-to-${t}.csv`);
    };
    runPayroll();
  }
  async function runPayroll() {
    const f = document.getElementById('pr-from').value, t = document.getElementById('pr-to').value;
    const res = await Api.get(`/admin/payroll?from=${f}&to=${t}`);
    const host = document.getElementById('pr-result');
    if (!res.data || !res.data.length) { host.innerHTML = '<div style="color:#9CA3AF;padding:12px;">No punches in range.</div>'; return; }
    let tot = 0;
    host.innerHTML = `<table style="width:100%;border-collapse:collapse;"><thead><tr>
      <th style="text-align:left;padding:8px;border-bottom:1px solid #E5E7EB;font-size:12px;color:#6B7280;">Staff</th>
      <th style="text-align:left;padding:8px;border-bottom:1px solid #E5E7EB;font-size:12px;color:#6B7280;">Centre</th>
      <th style="text-align:right;padding:8px;border-bottom:1px solid #E5E7EB;font-size:12px;color:#6B7280;">Punches</th>
      <th style="text-align:right;padding:8px;border-bottom:1px solid #E5E7EB;font-size:12px;color:#6B7280;">Hours</th></tr></thead><tbody>${res.data.map(r => {
        tot += parseFloat(r.total_hours || 0);
        return `<tr><td style="padding:10px 8px;border-bottom:1px solid #F3F4F6;">${escapeHtml(r.user_name)}</td><td style="padding:10px 8px;border-bottom:1px solid #F3F4F6;">${escapeHtml(r.centre_name || '')}</td><td style="padding:10px 8px;border-bottom:1px solid #F3F4F6;text-align:right;">${r.punch_count}</td><td style="padding:10px 8px;border-bottom:1px solid #F3F4F6;text-align:right;font-weight:600;">${r.total_hours}</td></tr>`;
      }).join('')}<tr><td colspan="3" style="padding:10px 8px;text-align:right;font-weight:700;border-top:2px solid #1F6080;">Total</td><td style="padding:10px 8px;text-align:right;font-weight:700;border-top:2px solid #1F6080;">${tot.toFixed(2)}</td></tr></tbody></table>`;
  }

  // ============================ Agency billing config ============================
  async function renderAgencyBilling(main) {
    main.innerHTML = '<div style="padding:20px;">Loading…</div>';
    const res = await Api.get('/admin/billing-config');
    const a = res.data || {};
    main.innerHTML = `<div style="padding:24px;max-width:600px;margin:0 auto;">
      <h2 style="margin:0 0 16px;color:#1F6080;">Billing settings</h2>
      <p style="color:#6B7280;font-size:14px;">Per-agency late-fee + SMS + locale defaults. Applies to all centres in this agency.</p>
      <label style="display:block;margin-top:14px;font-size:13px;font-weight:600;">Late-fee percent (of overdue balance)
        <input id="lfp" type="number" step="0.01" min="0" max="25" value="${a.late_fee_percent || 1.5}" style="width:100%;padding:9px;border:1px solid #E5E7EB;border-radius:4px;margin-top:4px;"></label>
      <label style="display:block;margin-top:14px;font-size:13px;font-weight:600;">Late-fee cap ($)
        <input id="lfc" type="number" step="0.01" min="0" value="${a.late_fee_cap || 25}" style="width:100%;padding:9px;border:1px solid #E5E7EB;border-radius:4px;margin-top:4px;"></label>
      <label style="display:block;margin-top:14px;font-size:13px;font-weight:600;">Grace days
        <input id="lfg" type="number" min="0" max="60" value="${a.late_fee_grace_days || 0}" style="width:100%;padding:9px;border:1px solid #E5E7EB;border-radius:4px;margin-top:4px;"></label>
      <label style="display:block;margin-top:14px;font-size:13px;font-weight:600;">
        <input id="sms-en" type="checkbox" ${a.sms_enabled ? 'checked' : ''}> Enable SMS for this agency</label>
      <label style="display:block;margin-top:14px;font-size:13px;font-weight:600;">Default locale
        <select id="loc" style="width:100%;padding:9px;border:1px solid #E5E7EB;border-radius:4px;margin-top:4px;">
          <option value="en" ${a.default_locale === 'en' ? 'selected' : ''}>English</option>
          <option value="fr" ${a.default_locale === 'fr' ? 'selected' : ''}>Français</option>
          <option value="es" ${a.default_locale === 'es' ? 'selected' : ''}>Español</option></select></label>
      <button id="cfg-save" style="background:#1F6080;color:#fff;border:0;padding:10px 18px;border-radius:6px;margin-top:20px;cursor:pointer;">Save</button>
      <div id="cfg-msg" style="margin-top:14px;font-size:13px;"></div></div>`;
    document.getElementById('cfg-save').onclick = async () => {
      await Api.patch('/admin/billing-config', {
        late_fee_percent: parseFloat(document.getElementById('lfp').value),
        late_fee_cap: parseFloat(document.getElementById('lfc').value),
        late_fee_grace_days: parseInt(document.getElementById('lfg').value, 10),
        sms_enabled: document.getElementById('sms-en').checked,
        default_locale: document.getElementById('loc').value,
      });
      document.getElementById('cfg-msg').innerHTML = '<span style="color:#047857;">Saved.</span>';
    };
  }

  // ============================ Stripe parent autopay ============================
  async function renderAutopay(main) {
    main.innerHTML = '<div style="padding:20px;">Loading…</div>';
    const res = await Api.get('/parent/billing/status').catch(() => ({}));
    main.innerHTML = `<div style="padding:24px;max-width:520px;margin:0 auto;">
      <h2 style="margin:0 0 16px;color:#1F6080;">Auto-pay</h2>
      <p style="color:#6B7280;font-size:14px;">Save a card and we'll automatically charge each invoice when it's due. Cancel any time.</p>
      <div id="ap-state" style="background:#F9FAFB;padding:18px;border-radius:8px;margin-top:16px;">
        ${res.has_card ? `<div><strong>Saved card:</strong> •••• ${res.card_last4 || '****'}</div>
          <div style="margin-top:8px;"><strong>Auto-pay:</strong> ${res.autopay_enabled ? '<span style="color:#047857;font-weight:600;">on</span>' : '<span style="color:#6B7280;">off</span>'}</div>
          <button id="ap-toggle" style="background:${res.autopay_enabled ? '#EF4444' : '#10B981'};color:#fff;border:0;padding:9px 16px;border-radius:6px;margin-top:14px;cursor:pointer;">${res.autopay_enabled ? 'Turn off auto-pay' : 'Turn on auto-pay'}</button>
          <button id="ap-replace" style="background:#F3F4F6;border:0;padding:9px 16px;border-radius:6px;margin-top:14px;margin-left:8px;cursor:pointer;">Replace card</button>` :
        `<div>No card saved yet.</div><button id="ap-add" style="background:#1F6080;color:#fff;border:0;padding:11px 20px;border-radius:6px;margin-top:14px;cursor:pointer;">+ Add card</button>`}
      </div></div>`;
    if (res.has_card) {
      document.getElementById('ap-toggle').onclick = async () => { await Api.post('/parent/billing/autopay', { enabled: !res.autopay_enabled }); renderAutopay(main); };
      document.getElementById('ap-replace').onclick = () => startStripeFlow();
    } else {
      document.getElementById('ap-add').onclick = () => startStripeFlow();
    }
  }
  async function startStripeFlow() {
    if (!window.Stripe) {
      const s = document.createElement('script');
      s.src = 'https://js.stripe.com/v3/';
      document.head.appendChild(s);
      await new Promise(r => s.onload = r);
    }
    const intent = await Api.post('/parent/billing/setup-intent', {});
    const pubKey = intent.publishable_key || '';
    if (!pubKey) { alert('Stripe not configured for this agency.'); return; }
    const stripe = window.Stripe(pubKey);
    const m = document.createElement('div');
    m.style.cssText = 'position:fixed;inset:0;background:rgba(0,0,0,0.4);z-index:9999;display:flex;align-items:center;justify-content:center;';
    m.innerHTML = `<div style="background:#fff;padding:24px;border-radius:8px;max-width:440px;width:90%;">
      <h3 style="margin:0 0 12px;">Save a card</h3>
      <div id="ap-element" style="padding:12px;border:1px solid #E5E7EB;border-radius:4px;"></div>
      <div id="ap-err" style="color:#B91C1C;font-size:13px;margin-top:8px;"></div>
      <div style="margin-top:16px;text-align:right;">
        <button id="ap-cancel" style="background:#F3F4F6;border:0;padding:9px 16px;border-radius:4px;margin-right:8px;cursor:pointer;">Cancel</button>
        <button id="ap-save" style="background:#1F6080;color:#fff;border:0;padding:9px 16px;border-radius:4px;cursor:pointer;">Save card</button>
      </div></div>`;
    document.body.appendChild(m);
    const elements = stripe.elements();
    const card = elements.create('card');
    card.mount('#ap-element');
    m.querySelector('#ap-cancel').onclick = () => m.remove();
    m.querySelector('#ap-save').onclick = async () => {
      const { setupIntent, error } = await stripe.confirmCardSetup(intent.client_secret, { payment_method: { card } });
      if (error) { m.querySelector('#ap-err').textContent = error.message; return; }
      await Api.post('/parent/billing/save-card', { payment_method: setupIntent.payment_method });
      m.remove();
      renderAutopay(document.getElementById('main') || document.querySelector('main'));
    };
  }

  // ============================ SMS ============================
  async function renderSms(main) {
    main.innerHTML = `<div style="padding:24px;max-width:760px;margin:0 auto;">
      <h2 style="margin:0 0 16px;color:#1F6080;">SMS broadcast</h2>
      <p style="color:#6B7280;font-size:14px;">Send a one-off SMS to staff or families. Only recipients with sms_opt_in and a phone number on file will receive it.</p>
      <label style="display:block;margin-top:14px;font-size:13px;font-weight:600;">Audience
        <select id="sms-aud" style="width:100%;padding:9px;border:1px solid #E5E7EB;border-radius:4px;margin-top:4px;">
          <option value="role">By role</option><option value="centre">By centre</option><option value="agency">Whole agency</option>
        </select></label>
      <label id="sms-role-l" style="display:block;margin-top:14px;font-size:13px;font-weight:600;">Role
        <select id="sms-role" style="width:100%;padding:9px;border:1px solid #E5E7EB;border-radius:4px;margin-top:4px;">
          <option value="guardian">Parents</option><option value="educator">Educators</option><option value="centre_director">Directors</option></select></label>
      <label style="display:block;margin-top:14px;font-size:13px;font-weight:600;">Message (max 300 chars)
        <textarea id="sms-body" maxlength="300" rows="4" style="width:100%;padding:9px;border:1px solid #E5E7EB;border-radius:4px;margin-top:4px;"></textarea></label>
      <button id="sms-send" style="background:#1F6080;color:#fff;border:0;padding:10px 18px;border-radius:6px;margin-top:18px;cursor:pointer;">Send broadcast</button>
      <div id="sms-msg" style="margin-top:14px;font-size:13px;"></div>
      <h3 style="margin-top:32px;font-size:14px;color:#6B7280;text-transform:uppercase;">Recent</h3>
      <div id="sms-recent"></div></div>`;
    document.getElementById('sms-send').onclick = async () => {
      const aud = document.getElementById('sms-aud').value;
      const payload = { audience: aud, body: document.getElementById('sms-body').value, category: 'broadcast' };
      if (aud === 'role') payload.role = document.getElementById('sms-role').value;
      try {
        const r = await Api.post('/admin/sms/broadcast', payload);
        document.getElementById('sms-msg').innerHTML = `<span style="color:#047857;">Sent: ${r.sent} · Skipped: ${r.skipped} · Total: ${r.total}</span>`;
      } catch (e) { document.getElementById('sms-msg').innerHTML = '<span style="color:#B91C1C;">Send failed</span>'; }
      loadSmsRecent();
    };
    loadSmsRecent();
  }
  async function loadSmsRecent() {
    const r = await Api.get('/admin/sms/messages').catch(() => ({ data: [] }));
    const host = document.getElementById('sms-recent');
    if (!r.data || !r.data.length) { host.innerHTML = '<div style="color:#9CA3AF;padding:12px;font-size:13px;">No messages yet.</div>'; return; }
    host.innerHTML = r.data.slice(0, 20).map(m => `<div style="padding:10px;border-bottom:1px solid #F3F4F6;font-size:13px;">
      <div style="color:#6B7280;font-size:11px;">${fmtDate(m.created_at)} · ${m.to_phone} · <span style="color:${m.status === 'sent' ? '#047857' : m.status === 'failed' ? '#B91C1C' : '#D97706'};font-weight:600;">${m.status}</span></div>
      <div style="margin-top:4px;">${escapeHtml((m.body || '').substring(0, 200))}</div></div>`).join('');
  }

  // ============================ AI churn risk ============================
  async function renderAiChurn(main) {
    main.innerHTML = '<div style="padding:20px;">Computing…</div>';
    const res = await Api.get('/ai/churn-risk').catch(() => ({ data: [] }));
    const rows = res.data || [];
    const hi = rows.filter(r => r.bucket === 'high').length;
    const md = rows.filter(r => r.bucket === 'medium').length;
    main.innerHTML = `<div style="padding:24px;max-width:1800px;margin:0 auto;">
      <h2 style="margin:0 0 8px;color:#1F6080;">Churn risk</h2>
      <p style="color:#6B7280;font-size:14px;">Heuristic score per family based on observation activity, sign-in attendance, and payment status. Updated each time you load this page.</p>
      <div style="display:flex;gap:12px;margin:20px 0;">
        <div style="flex:1;background:#FEE2E2;padding:18px;border-radius:8px;"><div style="color:#B91C1C;font-size:12px;font-weight:600;">HIGH RISK</div><div style="font-size:28px;font-weight:700;color:#B91C1C;">${hi}</div></div>
        <div style="flex:1;background:#FEF3C7;padding:18px;border-radius:8px;"><div style="color:#92400E;font-size:12px;font-weight:600;">MEDIUM</div><div style="font-size:28px;font-weight:700;color:#92400E;">${md}</div></div>
        <div style="flex:1;background:#DCFCE7;padding:18px;border-radius:8px;"><div style="color:#166534;font-size:12px;font-weight:600;">LOW</div><div style="font-size:28px;font-weight:700;color:#166534;">${rows.length - hi - md}</div></div>
      </div>
      <table style="width:100%;border-collapse:collapse;">
        <thead><tr><th style="text-align:left;padding:8px;border-bottom:1px solid #E5E7EB;font-size:12px;color:#6B7280;">Family</th><th style="text-align:left;padding:8px;border-bottom:1px solid #E5E7EB;font-size:12px;color:#6B7280;">Score</th><th style="text-align:left;padding:8px;border-bottom:1px solid #E5E7EB;font-size:12px;color:#6B7280;">Signals</th></tr></thead>
        <tbody>${rows.slice(0, 50).map(r => `<tr>
          <td style="padding:10px 8px;border-bottom:1px solid #F3F4F6;">${escapeHtml(r.family_name)}</td>
          <td style="padding:10px 8px;border-bottom:1px solid #F3F4F6;"><span style="background:${r.bucket === 'high' ? '#FEE2E2' : r.bucket === 'medium' ? '#FEF3C7' : '#DCFCE7'};color:${r.bucket === 'high' ? '#B91C1C' : r.bucket === 'medium' ? '#92400E' : '#166534'};font-weight:700;padding:2px 8px;border-radius:4px;">${r.risk_score}</span></td>
          <td style="padding:10px 8px;border-bottom:1px solid #F3F4F6;font-size:13px;color:#6B7280;">${(r.signals || []).join(' · ') || '—'}</td></tr>`).join('')}</tbody></table></div>`;
  }

  // ============================ AI doc extraction ============================
  async function renderAiDocs(main) {
    main.innerHTML = `<div style="padding:24px;max-width:760px;margin:0 auto;">
      <h2 style="margin:0 0 16px;color:#1F6080;">AI document extraction</h2>
      <p style="color:#6B7280;font-size:14px;">Upload a photo or scan of a document and we'll extract structured fields into JSON. Useful for new staff certifications, immunization records, etc.</p>
      <label style="display:block;margin-top:14px;font-size:13px;font-weight:600;">Document URL (must be publicly reachable)
        <input id="aid-url" placeholder="https://…/document.png" style="width:100%;padding:9px;border:1px solid #E5E7EB;border-radius:4px;margin-top:4px;"></label>
      <label style="display:block;margin-top:14px;font-size:13px;font-weight:600;">Document type
        <select id="aid-type" style="width:100%;padding:9px;border:1px solid #E5E7EB;border-radius:4px;margin-top:4px;">
          <option value="immunization">Immunization record</option>
          <option value="certification">Staff certification</option>
          <option value="background_check">Background / VSS check</option>
          <option value="id">ID document</option>
          <option value="enrollment">Enrollment form</option>
        </select></label>
      <button id="aid-go" style="background:#1F6080;color:#fff;border:0;padding:10px 18px;border-radius:6px;margin-top:18px;cursor:pointer;">Extract fields</button>
      <pre id="aid-out" style="background:#1F2937;color:#10B981;padding:14px;border-radius:6px;margin-top:18px;font-size:12px;overflow:auto;max-height:400px;"></pre></div>`;
    document.getElementById('aid-go').onclick = async () => {
      const out = document.getElementById('aid-out');
      out.textContent = 'Extracting…';
      try {
        const r = await Api.post('/ai/doc-extract', {
          document_url: document.getElementById('aid-url').value,
          doc_type: document.getElementById('aid-type').value,
        });
        out.textContent = JSON.stringify(r.fields, null, 2);
      } catch (e) { out.textContent = 'Error: ' + (e.message || e); }
    };
  }

  // ============================ Locale picker ============================
  async function renderLocale(main) {
    const res = await Api.get('/locale');
    main.innerHTML = `<div style="padding:24px;max-width:1800px;margin:0 auto;">
      <h2 style="margin:0 0 16px;color:#1F6080;">Language / Langue / Idioma</h2>
      <p style="color:#6B7280;font-size:14px;">Current: <strong>${res.locale}</strong></p>
      <div style="margin-top:18px;max-width:520px;">
        ${['en', 'fr', 'es'].map(l => `<button data-loc="${l}" style="background:${res.locale === l ? '#1F6080' : '#F3F4F6'};color:${res.locale === l ? '#fff' : '#374151'};border:0;padding:14px 22px;border-radius:6px;margin-right:8px;cursor:pointer;font-weight:600;">${{ en: 'English', fr: 'Français', es: 'Español' }[l]}</button>`).join('')}
      </div></div>`;
    main.querySelectorAll('button[data-loc]').forEach(b => b.onclick = async () => {
      await Api.post('/locale', { locale: b.dataset.loc });
      window.location.reload();
    });
  }

  // ============================ Helpers ============================
  function escapeHtml(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c])); }
  function fmtDate(s) { if (!s) return ''; const d = new Date(s); return d.toLocaleDateString(undefined, { year: 'numeric', month: 'short', day: 'numeric' }); }
  function isStaffOrAdmin() {
    const u = JSON.parse(sessionStorage.getItem('kt_user') || '{}');
    return Array.isArray(u.roles) && u.roles.some(r => ['agency_admin', 'centre_director', 'platform_admin'].includes(r));
  }
  async function downloadAuthed(path, filename) {
    const tok = sessionStorage.getItem('kt_token');
    const r = await fetch(((window.KT && window.KT.API_BASE) || 'https://api.kiddietrac.com/api/v1') + path, { headers: { Authorization: 'Bearer ' + tok } });
    const blob = await r.blob();
    const a = document.createElement('a');
    a.href = URL.createObjectURL(blob); a.download = filename;
    a.click();
  }

  // Expose for shim
  window.KT = window.KT || {};
  window.KT.V22p51 = {
    renderTimeOff,
    renderBackgroundChecks,
    renderPayroll,
    renderAgencyBilling,
    renderAutopay,
    renderSms,
    renderAiChurn,
    renderAiDocs,
    renderLocale,
  };
})(window);
