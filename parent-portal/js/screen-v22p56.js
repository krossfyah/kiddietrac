/* v22p56 — Procare-parity screens. All use kt-design-v22p55.css conventions. */
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

  const esc = (s) => String(s == null ? '' : s).replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  const MONTHS_ABBR = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun',
                       'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
  /* A date-only value is a calendar day, not an instant. `new Date('2026-07-27')` is
     parsed as UTC midnight, and rendering that in a western timezone lands on the 26th —
     every closure here was drawn a day early. Date-only is formatted from its own parts;
     anything carrying a time still goes through the agency timezone. */
  const fmtDate = (s) => {
    if (!s) return '';
    const str = String(s);
    const dOnly = str.match(/^(\d{4})-(\d{2})-(\d{2})/);
    if (dOnly && !/[T ]\d{2}:/.test(str)) {
      return (MONTHS_ABBR[+dOnly[2] - 1] || dOnly[2]) + ' ' + (+dOnly[3]) + ', ' + dOnly[1];
    }
    try {
      const tz = (window.KT && KT.agencyTz && KT.agencyTz()) || undefined;
      return new Date(str.replace(' ', 'T')).toLocaleDateString(undefined,
        { year: 'numeric', month: 'short', day: 'numeric', timeZone: tz });
    } catch (e) { return str.slice(0, 10); }
  };
  const fmtMoney = (n) => '$' + (Number(n) || 0).toFixed(2);

  // ============================ Closures =================================
  async function renderClosures(main) {
    main.setAttribute('data-kt-pretty', '1');
    main.innerHTML = '<div style="padding:24px;">Loading…</div>';
    const r = await Api.get('/operations/closures').catch(() => ({ data: [] }));
    main.innerHTML = `<div style="padding:24px;max-width:1800px;margin:0 auto;">
      <div class="kt-page-hero">
        <h2>🗓 Closures & holidays</h2>
        <p>Days you'll be closed. Families are notified automatically. Billing pauses on closures with affects_billing = yes.</p>
        <div class="kt-hero-actions"><button class="kt-btn kt-btn-ghost" id="cl-new">+ New closure</button></div>
      </div>
      <div class="kt-card">
        <table>
          <thead><tr><th>Date</th><th>Centre</th><th>Type</th><th>Reason</th><th>Billing</th><th>Added</th><th></th></tr></thead>
          <tbody>${(r.data || []).map(c => `<tr>
            <td><strong>${fmtDate(c.closure_date)}${c.end_date ? ' – ' + fmtDate(c.end_date) : ''}</strong></td>
            <td>${esc(c.centre_name)}</td>
            <td><span class="kt-pill kt-pill-info">${esc(c.closure_type)}</span></td>
            <td>${esc(c.reason || '—')}</td>
            <td>${c.affects_billing ? 'Paused' : 'No change'}</td>
            <td style="font-size:12.5px;color:#64748B;">${esc(c.added_by || '—')}${c.created_at ? '<div style="font-size:11.5px;color:#94A3B8;">' + esc(fmtStamp(c.created_at)) + '</div>' : ''}</td>
            <td><button class="kt-act-icon kt-icon-tip" data-ed="${c.id}" title="Edit" aria-label="Edit" data-kttip="Edit">✏️</button><button class="kt-act-icon kt-act-danger kt-icon-tip" data-rm="${c.id}" title="Remove" aria-label="Remove" data-kttip="Remove">🗑️</button></td>
          </tr>`).join('') || '<tr><td colspan="7" style="text-align:center;padding:40px;color:#64748B;">No closures scheduled.</td></tr>'}</tbody>
        </table>
      </div>
    </div>`;
    document.getElementById('cl-new').onclick = () => openClosureModal();
    main.querySelectorAll('button[data-ed]').forEach(b => b.onclick = () => {
      const row = (r.data || []).filter(c => String(c.id) === String(b.dataset.ed))[0];
      if (row) { openClosureModal(row); }
    });
    main.querySelectorAll('button[data-rm]').forEach(b => b.onclick = async () => {
      if (!await KT.confirm('Remove this closure?')) return;
      await Api.delete(`/operations/closures/${b.dataset.rm}`);
      renderClosures(main);
    });
  }
  /* A closure's created_at is stored UTC. Rendered in the agency's timezone, like
     every other timestamp — never the device's. */
  function fmtStamp(ts) {
    if (!ts) { return ''; }
    try {
      var tz = (window.KT && KT.agencyTz && KT.agencyTz()) || undefined;
      var s = String(ts).replace(' ', 'T');
      if (!/[Zz]|[+-]\d\d:?\d\d$/.test(s)) { s += 'Z'; }
      return new Date(s).toLocaleString('en-CA',
        { month: 'short', day: 'numeric', hour: 'numeric', minute: '2-digit', timeZone: tz });
    } catch (e) { return String(ts).slice(0, 16); }
  }

  /* One dialog for both jobs. `existing` turns it into an edit, which PATCHes rather than
     creating a second closure — the previous way to fix a wrong date was to delete and
     re-add, and that announces the same closure to every family twice. */
  async function openClosureModal(existing) {
    const _cr = await Api.get('/admin/centres').catch(() => ({})); const centres = _cr.centres || _cr.data || [];
    const m = document.createElement('div');
    m.style.cssText = 'position:fixed;inset:0;background:rgba(15,23,42,0.5);z-index:99999;display:flex;align-items:center;justify-content:center;';
    m.innerHTML = `<div style="background:#fff;padding:28px;border-radius:14px;max-width:480px;width:92%;">
      <h3 style="margin:0 0 16px;color:#0F172A;">${existing ? 'Edit closure' : 'New closure'}</h3>
      <label style="display:block;font-size:13px;font-weight:600;margin:10px 0 4px;">Centre</label>
      <select id="cl-centre" style="width:100%;padding:9px;border:1px solid #E2E8F0;border-radius:8px;">${centres.map(c => `<option value="${c.id}">${esc(c.name)}</option>`).join('')}</select>
      <label style="display:block;font-size:13px;font-weight:600;margin:14px 0 4px;">Type</label>
      <select id="cl-type" style="width:100%;padding:9px;border:1px solid #E2E8F0;border-radius:8px;">
        <option value="holiday">Holiday</option><option value="pd_day">PD day</option>
        <option value="emergency">Emergency</option><option value="renovation">Renovation</option>
        <option value="other">Other</option></select>
      <label style="display:block;font-size:13px;font-weight:600;margin:14px 0 4px;">Date (start)</label>
      <input id="cl-date" type="date" style="width:100%;padding:9px;border:1px solid #E2E8F0;border-radius:8px;">
      <label style="display:block;font-size:13px;font-weight:600;margin:14px 0 4px;">End date (optional, for multi-day)</label>
      <input id="cl-end" type="date" style="width:100%;padding:9px;border:1px solid #E2E8F0;border-radius:8px;">
      <label style="display:block;font-size:13px;font-weight:600;margin:14px 0 4px;">Reason</label>
      <input id="cl-reason" placeholder="e.g. Family Day" style="width:100%;padding:9px;border:1px solid #E2E8F0;border-radius:8px;">
      <label style="display:flex;align-items:center;gap:8px;margin-top:14px;font-size:13.5px;font-weight:600;"><input id="cl-bill" type="checkbox" checked> Pause billing on these days</label>
      <div style="margin-top:20px;text-align:right;">
        <button id="cl-cancel" style="background:#F1F5F9;border:0;padding:10px 18px;border-radius:8px;cursor:pointer;font-weight:600;">Cancel</button>
        <button id="cl-save" class="kt-btn kt-btn-primary" style="margin-left:8px;">Save</button>
      </div></div>`;
    document.body.appendChild(m);
    m.querySelector('#cl-cancel').onclick = () => m.remove();

    // Prefill when editing. Dates are sliced, not parsed: running a date-only value
    // through Date() shifts it a day in either direction depending on the engine.
    if (existing) {
      m.querySelector('#cl-centre').value = String(existing.centre_id);
      m.querySelector('#cl-centre').disabled = true;   // moving a closure between centres is a new closure
      m.querySelector('#cl-type').value = existing.closure_type || 'holiday';
      m.querySelector('#cl-date').value = String(existing.closure_date || '').slice(0, 10);
      m.querySelector('#cl-end').value = existing.end_date ? String(existing.end_date).slice(0, 10) : '';
      m.querySelector('#cl-reason').value = existing.reason || '';
      m.querySelector('#cl-bill').checked = !!existing.affects_billing;
    }

    m.querySelector('#cl-save').onclick = async () => {
      const btn = m.querySelector('#cl-save');
      const body = {
        closure_date: m.querySelector('#cl-date').value,
        end_date: m.querySelector('#cl-end').value || null,
        closure_type: m.querySelector('#cl-type').value,
        reason: m.querySelector('#cl-reason').value,
        affects_billing: m.querySelector('#cl-bill').checked,
      };
      if (!body.closure_date) {
        if (KT.Dom && KT.Dom.toast) { KT.Dom.toast('Pick a start date', 'error'); }
        return;
      }
      btn.disabled = true; btn.textContent = 'Saving…';
      try {
        if (existing) {
          await Api.patch(`/operations/closures/${existing.id}`, body);
        } else {
          body.centre_id = +m.querySelector('#cl-centre').value;
          await Api.post('/operations/closures', body);
        }
      } catch (e) {
        btn.disabled = false; btn.textContent = 'Save';
        if (KT.Dom && KT.Dom.toast) { KT.Dom.toast((e && e.message) || 'Could not save', 'error'); }
        return;
      }
      m.remove();
      renderClosures(document.querySelector('main'));
    };
  }

  // ============================ Late pickup =================================
  async function renderLatePickup(main) {
    main.setAttribute('data-kt-pretty', '1');
    main.innerHTML = '<div style="padding:24px;">Loading…</div>';
    const r = await Api.get('/operations/late-pickups').catch(() => ({ data: [] }));
    main.innerHTML = `<div style="padding:24px;max-width:1800px;margin:0 auto;">
      <div class="kt-page-hero">
        <h2>⏰ Late pickup fees</h2>
        <p>Charge a per-minute fee for pickups after centre close. Auto-appends to the family's next invoice.</p>
        <div class="kt-hero-actions"><button class="kt-btn kt-btn-ghost" id="lp-new">+ Log late pickup</button></div>
      </div>
      <div class="kt-card">
        <table>
          <thead><tr><th>When</th><th>Child</th><th>Minutes late</th><th>Fee</th><th>Notes</th></tr></thead>
          <tbody>${(r.data || []).map(c => `<tr>
            <td>${fmtDate(c.pickup_at)}</td>
            <td><strong>${esc(c.child_name)}</strong></td>
            <td><span class="kt-pill kt-pill-warning">${c.minutes_late} min</span></td>
            <td><strong>${fmtMoney(c.fee_amount)}</strong></td>
            <td>${esc(c.notes || '')}</td>
          </tr>`).join('') || '<tr><td colspan="5" style="text-align:center;padding:40px;color:#64748B;">No late pickups logged.</td></tr>'}</tbody>
        </table>
      </div>
    </div>`;
    document.getElementById('lp-new').onclick = () => openLatePickupModal();
  }
  async function openLatePickupModal() {
    // Agency-scoped picker options — works for educators too (not just admins).
    const _opts = await Api.get('/operations/late-pickup-options').catch(() => ({ centres: [], children: [] }));
    const centres = _opts.centres || [];
    const children = _opts.children || [];
    const m = document.createElement('div');
    m.style.cssText = 'position:fixed;inset:0;background:rgba(15,23,42,0.5);z-index:99999;display:flex;align-items:center;justify-content:center;';
    m.innerHTML = `<div style="background:#fff;padding:28px;border-radius:14px;max-width:480px;width:92%;">
      <h3 style="margin:0 0 16px;color:#0F172A;">Log late pickup</h3>
      <label style="display:block;font-size:13px;font-weight:600;margin:10px 0 4px;">Centre</label>
      <select id="lp-centre" style="width:100%;padding:9px;border:1px solid #E2E8F0;border-radius:8px;">${centres.map(c => `<option value="${c.id}">${esc(c.name)}</option>`).join('')}</select>
      <label style="display:block;font-size:13px;font-weight:600;margin:14px 0 4px;">Child</label>
      <select id="lp-child" style="width:100%;padding:9px;border:1px solid #E2E8F0;border-radius:8px;">${children.map(c => `<option value="${c.id}">${esc(c.first_name)} ${esc(c.last_name)}</option>`).join('')}</select>
      <label style="display:block;font-size:13px;font-weight:600;margin:14px 0 4px;">Pickup time</label>
      <input id="lp-time" type="datetime-local" style="width:100%;padding:9px;border:1px solid #E2E8F0;border-radius:8px;">
      <label style="display:block;font-size:13px;font-weight:600;margin:14px 0 4px;">Centre close time</label>
      <input id="lp-close" type="time" value="18:00" style="width:100%;padding:9px;border:1px solid #E2E8F0;border-radius:8px;">
      <label style="display:block;font-size:13px;font-weight:600;margin:14px 0 4px;">Notes</label>
      <textarea id="lp-notes" rows="2" style="width:100%;padding:9px;border:1px solid #E2E8F0;border-radius:8px;"></textarea>
      <div style="margin-top:20px;text-align:right;">
        <button id="lp-cancel" style="background:#F1F5F9;border:0;padding:10px 18px;border-radius:8px;cursor:pointer;font-weight:600;">Cancel</button>
        <button id="lp-save" class="kt-btn kt-btn-primary" style="margin-left:8px;">Log + charge</button>
      </div></div>`;
    document.body.appendChild(m);
    m.querySelector('#lp-cancel').onclick = () => m.remove();
    m.querySelector('#lp-save').onclick = async () => {
      const r = await Api.post('/operations/late-pickup', {
        centre_id: +m.querySelector('#lp-centre').value,
        child_id: +m.querySelector('#lp-child').value,
        pickup_at: m.querySelector('#lp-time').value,
        close_time: m.querySelector('#lp-close').value,
        notes: m.querySelector('#lp-notes').value,
      });
      alert(r.status === 'within_grace' ? 'Within grace period — no charge.' : `Charged ${fmtMoney(r.fee)} for ${r.minutes_late} min`);
      m.remove();
      renderLatePickup(document.querySelector('main'));
    };
  }

  // ============================ Room ratios =================================
  async function renderRoomRatios(main) {
    main.setAttribute('data-kt-pretty', '1');
    main.innerHTML = '<div style="padding:24px;">Computing…</div>';
    const r = await Api.get('/operations/ratio-status').catch(() => ({ data: [] }));
    const rooms = r.data || [];
    const breached = rooms.filter(x => !x.compliant).length;
    main.innerHTML = `<div style="padding:24px;max-width:1800px;margin:0 auto;">
      <div class="kt-page-hero">
        <h2>👥 Real-time room ratios</h2>
        <p>Ontario CCEYA standards. ${breached} of ${rooms.length} rooms out of ratio right now.</p>
      </div>
      <div class="kt-kpi-grid">
        <div class="kt-kpi kt-kpi-${breached > 0 ? 'danger' : 'success'}"><div class="kt-kpi-label">Out of ratio</div><div class="kt-kpi-value">${breached}</div></div>
        <div class="kt-kpi kt-kpi-info"><div class="kt-kpi-label">Rooms tracked</div><div class="kt-kpi-value">${rooms.length}</div></div>
        <div class="kt-kpi kt-kpi-success"><div class="kt-kpi-label">Compliant</div><div class="kt-kpi-value">${rooms.length - breached}</div></div>
      </div>
      <div class="kt-card">
        <table>
          <thead><tr><th>Room</th><th>Age band</th><th>Children</th><th>Staff</th><th>Required</th><th>Status</th></tr></thead>
          <tbody>${rooms.map(r => `<tr>
            <td><strong>${esc(r.room_name)}</strong><div style="color:#64748B;font-size:12px;">${esc(r.centre_name)}</div></td>
            <td><span class="kt-pill kt-pill-purple">${esc(r.standard.label)}</span></td>
            <td>${r.children_present} / ${r.standard.max_group}</td>
            <td>${r.staff_present}</td>
            <td>${r.required_staff}</td>
            <td><span class="kt-pill ${r.compliant ? 'kt-pill-success' : 'kt-pill-danger'}">${r.compliant ? '✓ Compliant' : '⚠ Out of ratio'}</span></td>
          </tr>`).join('') || '<tr><td colspan="6" style="text-align:center;padding:40px;color:#64748B;">No rooms tracked.</td></tr>'}</tbody>
        </table>
      </div>
    </div>`;
  }

  // ============================ Vacation holds =================================
  async function renderVacationHolds(main) {
    main.setAttribute('data-kt-pretty', '1');
    main.innerHTML = '<div style="padding:24px;">Loading…</div>';
    const r = await Api.get('/billing/vacation-holds').catch(() => ({ data: [] }));
    main.innerHTML = `<div style="padding:24px;max-width:1800px;margin:0 auto;">
      <div class="kt-page-hero">
        <h2>🏖 Vacation holds</h2>
        <p>Pause tuition while a family is away. Approve or deny requests with an optional credit amount.</p>
        <div class="kt-hero-actions"><button class="kt-btn kt-btn-primary" id="vh-add">+ Add hold</button></div>
      </div>
      <div class="kt-card">
        <table>
          <thead><tr><th>Family</th><th>Dates</th><th>Reason</th><th>Status</th><th>Credit</th><th></th></tr></thead>
          <tbody>${(r.data || []).map(h => `<tr>
            <td><strong>${esc(h.family_name)}</strong></td>
            <td>${fmtDate(h.start_date)} – ${fmtDate(h.end_date)}</td>
            <td>${esc(h.reason || '')}</td>
            <td><span class="kt-pill ${h.status === 'approved' ? 'kt-pill-success' : h.status === 'denied' ? 'kt-pill-danger' : 'kt-pill-warning'}">${esc(h.status)}</span></td>
            <td>${h.credit_amount ? fmtMoney(h.credit_amount) : '—'}</td>
            <td>${h.status === 'requested' ? `<button class="kt-btn kt-btn-success" data-decide="${h.id}" data-action="approved">Approve</button> <button class="kt-btn kt-btn-danger" data-decide="${h.id}" data-action="denied" style="margin-left:6px;">Deny</button>` : ''}</td>
          </tr>`).join('') || '<tr><td colspan="6" style="text-align:center;padding:40px;color:#64748B;">No vacation holds.</td></tr>'}</tbody>
        </table>
      </div>
    </div>`;
    main.querySelectorAll('button[data-decide]').forEach(b => b.onclick = async () => {
      const credit = b.dataset.action === 'approved' ? prompt('Credit amount (or leave blank for auto):', '') : null;
      await Api.patch(`/billing/vacation-holds/${b.dataset.decide}`, { status: b.dataset.action, credit_amount: credit ? parseFloat(credit) : null });
      renderVacationHolds(main);
    });
    const vhAdd = document.getElementById('vh-add');
    if (vhAdd) vhAdd.onclick = () => openAddHoldModal(main);
  }

  // Staff adds a vacation hold for a family (family picker + dates + reason, dimmed modal).
  async function openAddHoldModal(main) {
    let families = [];
    try { const fr = await Api.get('/admin/families'); families = (fr && fr.families) || (Array.isArray(fr) ? fr : (fr && fr.data) || []); } catch (e) {}
    const m = document.createElement('div');
    m.style.cssText = 'position:fixed;inset:0;background:rgba(15,23,42,.55);z-index:99999;display:flex;align-items:center;justify-content:center;padding:20px;';
    m.innerHTML = `<div style="background:#fff;border-radius:14px;max-width:460px;width:100%;padding:24px;box-shadow:0 20px 50px -12px rgba(15,23,42,.4);">
      <h3 style="margin:0 0 14px;color:#0F172A;">🏖 Add vacation hold</h3>
      <label style="display:block;font-size:13px;font-weight:600;margin-bottom:5px;">Family</label>
      <select id="vh-family" style="width:100%;padding:9px;border:1px solid #E2E8F0;border-radius:8px;background:#fff;margin-bottom:12px;box-sizing:border-box;">
        <option value="">Select a family…</option>
        ${families.map(f => `<option value="${f.id}">${esc(f.family_name || f.name || ('Family #' + f.id))}</option>`).join('')}
      </select>
      ${families.length ? '' : '<div style="color:#B91C1C;font-size:12.5px;margin:-6px 0 12px;">No families loaded — select an agency first.</div>'}
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-bottom:12px;">
        <div><label style="display:block;font-size:13px;font-weight:600;margin-bottom:5px;">From</label><input id="vh-start" type="date" style="width:100%;padding:9px;border:1px solid #E2E8F0;border-radius:8px;box-sizing:border-box;"></div>
        <div><label style="display:block;font-size:13px;font-weight:600;margin-bottom:5px;">To</label><input id="vh-end" type="date" style="width:100%;padding:9px;border:1px solid #E2E8F0;border-radius:8px;box-sizing:border-box;"></div>
      </div>
      <label style="display:block;font-size:13px;font-weight:600;margin-bottom:5px;">Reason (optional)</label>
      <input id="vh-reason" placeholder="e.g. Family holiday" maxlength="120" style="width:100%;padding:9px;border:1px solid #E2E8F0;border-radius:8px;box-sizing:border-box;">
      <div id="vh-status" style="font-size:12.5px;min-height:16px;margin-top:8px;"></div>
      <div style="display:flex;gap:8px;justify-content:flex-end;margin-top:14px;">
        <button id="vh-cancel" style="background:#F1F5F9;color:#475569;border:none;border-radius:9px;padding:9px 16px;font-weight:700;cursor:pointer;">Cancel</button>
        <button id="vh-save" class="kt-btn kt-btn-primary">Add hold</button>
      </div>
    </div>`;
    const close = () => m.remove();
    m.addEventListener('click', e => { if (e.target === m) close(); });
    document.body.appendChild(m);
    m.querySelector('#vh-cancel').onclick = close;
    m.querySelector('#vh-save').onclick = async () => {
      const fid = m.querySelector('#vh-family').value;
      const start = m.querySelector('#vh-start').value;
      const end = m.querySelector('#vh-end').value;
      const st = m.querySelector('#vh-status');
      if (!fid || !start || !end) { st.style.color = '#B91C1C'; st.textContent = 'Family and both dates are required.'; return; }
      const btn = m.querySelector('#vh-save'); btn.disabled = true;
      const done = (window.KT && KT.busy) ? KT.busy(btn) : function () {};
      try {
        await Api.post('/billing/vacation-holds/for-family', { family_id: parseInt(fid, 10), start_date: start, end_date: end, reason: m.querySelector('#vh-reason').value || null });
        close();
        renderVacationHolds(main);
      } catch (e) { st.style.color = '#B91C1C'; st.textContent = '✗ ' + (e.message || 'Could not add hold.'); btn.disabled = false; }
      finally { done(); }
    };
  }

  // ============================ Tuition increases =================================
  async function renderTuitionIncreases(main) {
    main.setAttribute('data-kt-pretty', '1');
    main.innerHTML = '<div style="padding:24px;">Loading…</div>';
    const r = await Api.get('/billing/tuition-increases').catch(() => ({ data: [] }));
    main.innerHTML = `<div style="padding:24px;max-width:1800px;margin:0 auto;">
      <div class="kt-page-hero">
        <h2>📈 Tuition increases</h2>
        <p>Schedule a percent or flat increase. Families auto-notified ahead of effective date.</p>
        <div class="kt-hero-actions"><button class="kt-btn kt-btn-ghost" id="ti-new">+ Schedule increase</button></div>
      </div>
      <div class="kt-card">
        <table>
          <thead><tr><th>Effective</th><th>Type</th><th>Amount</th><th>Notify</th><th>Status</th></tr></thead>
          <tbody>${(r.data || []).map(t => `<tr>
            <td><strong>${fmtDate(t.effective_date)}</strong></td>
            <td>${esc(t.increase_type)}</td>
            <td><strong>${t.increase_type === 'percent' ? t.amount + '%' : fmtMoney(t.amount)}</strong></td>
            <td>${t.notify_families_days_before} days before</td>
            <td>${t.applied_at ? '<span class="kt-pill kt-pill-success">Applied</span>' : t.notification_sent_at ? '<span class="kt-pill kt-pill-info">Notified</span>' : '<span class="kt-pill kt-pill-warning">Scheduled</span>'}</td>
          </tr>`).join('') || '<tr><td colspan="5" style="text-align:center;padding:40px;color:#64748B;">No tuition increases scheduled.</td></tr>'}</tbody>
        </table>
      </div>
    </div>`;
    document.getElementById('ti-new').onclick = () => {
      const eff = prompt('Effective date (YYYY-MM-DD)?');
      const amount = prompt('Amount (number)?');
      const type = prompt('Type? "percent" or "flat"', 'percent');
      if (!eff || !amount) return;
      Api.post('/billing/tuition-increases', {
        effective_date: eff, amount: parseFloat(amount), increase_type: type || 'percent',
      }).then(() => renderTuitionIncreases(main));
    };
  }

  // ============================ Re-enrollment campaigns =================================
  async function renderReenrollment(main) {
    main.setAttribute('data-kt-pretty', '1');
    main.innerHTML = '<div style="padding:24px;">Loading…</div>';
    const r = await Api.get('/engagement/reenrollment').catch(() => ({ data: [] }));
    main.innerHTML = `<div style="padding:24px;max-width:1800px;margin:0 auto;">
      <div class="kt-page-hero">
        <h2>🔁 Re-enrollment campaigns</h2>
        <p>Confirm next term's enrolment with a notification + portal action for every guardian.</p>
        <div class="kt-hero-actions"><button class="kt-btn kt-btn-ghost" id="re-new">+ Launch campaign</button></div>
      </div>
      <div class="kt-card">
        <table>
          <thead><tr><th>Campaign</th><th>Term</th><th>Deadline</th><th>Status</th><th>Invited</th><th>Responded</th><th>Renewed</th></tr></thead>
          <tbody>${(r.data || []).map(c => `<tr>
            <td><strong>${esc(c.campaign_name)}</strong></td>
            <td>${esc(c.target_term)}</td>
            <td>${fmtDate(c.deadline)}</td>
            <td><span class="kt-pill kt-pill-info">${esc(c.status)}</span></td>
            <td>${c.total_invited}</td>
            <td>${c.total_responded}</td>
            <td><strong style="color:#15803D;">${c.total_renewed}</strong></td>
          </tr>`).join('') || '<tr><td colspan="7" style="text-align:center;padding:40px;color:#64748B;">No campaigns yet.</td></tr>'}</tbody>
        </table>
      </div>
    </div>`;
    document.getElementById('re-new').onclick = () => {
      const name = prompt('Campaign name?', 'Fall 2026 re-enrolment');
      const term = prompt('Target term?', 'Fall 2026');
      const deadline = prompt('Deadline (YYYY-MM-DD)?');
      if (!name || !term || !deadline) return;
      Api.post('/engagement/reenrollment', { campaign_name: name, target_term: term, deadline })
        .then(r => { alert(`Invited ${r.invited} children`); renderReenrollment(main); });
    };
  }

  // ============================ Engagement scores =================================
  async function renderEngagement(main) {
    main.setAttribute('data-kt-pretty', '1');
    main.innerHTML = '<div style="padding:24px;">Computing…</div>';
    const r = await Api.get('/engagement/scores').catch(() => ({ data: [] }));
    const rows = r.data || [];
    const eng = rows.filter(x => x.bucket === 'engaged').length;
    const mod = rows.filter(x => x.bucket === 'moderate').length;
    const low = rows.length - eng - mod;
    main.innerHTML = `<div style="padding:24px;max-width:1800px;margin:0 auto;">
      <div class="kt-page-hero">
        <h2>💚 Family engagement</h2>
        <p>How actively families interact with the portal — messages, feedback, forms, observation views, custom form submissions over 30 days.</p>
      </div>
      <div class="kt-kpi-grid">
        <div class="kt-kpi kt-kpi-success"><div class="kt-kpi-label">Engaged</div><div class="kt-kpi-value">${eng}</div></div>
        <div class="kt-kpi kt-kpi-warning"><div class="kt-kpi-label">Moderate</div><div class="kt-kpi-value">${mod}</div></div>
        <div class="kt-kpi kt-kpi-danger"><div class="kt-kpi-label">Low</div><div class="kt-kpi-value">${low}</div></div>
        <div class="kt-kpi"><div class="kt-kpi-label">Total</div><div class="kt-kpi-value">${rows.length}</div></div>
      </div>
      <div class="kt-card">
        <table>
          <thead><tr><th>Family</th><th>Score</th><th>Bucket</th><th>Signals</th></tr></thead>
          <tbody>${rows.map(r => {
            const pill = r.bucket === 'engaged' ? 'kt-pill-success' : r.bucket === 'moderate' ? 'kt-pill-warning' : 'kt-pill-danger';
            const colour = r.bucket === 'engaged' ? '#10B981' : r.bucket === 'moderate' ? '#F59E0B' : '#EF4444';
            return `<tr>
              <td><strong>${esc(r.family_name)}</strong></td>
              <td><div style="display:flex;align-items:center;gap:10px;"><div style="background:#F1F5F9;height:8px;width:100px;border-radius:4px;overflow:hidden;"><div style="background:${colour};height:100%;width:${Math.max(2, r.score)}%;"></div></div><strong>${r.score}</strong></div></td>
              <td><span class="kt-pill ${pill}">${esc(r.bucket)}</span></td>
              <td style="color:#475569;font-size:13px;">${(r.signals || []).join(' · ') || '—'}</td>
            </tr>`;
          }).join('') || '<tr><td colspan="4" style="text-align:center;padding:40px;color:#64748B;">No engagement data yet.</td></tr>'}</tbody>
        </table>
      </div>
    </div>`;
  }

  // ============================ NPS summary =================================
  async function renderNps(main) {
    main.setAttribute('data-kt-pretty', '1');
    main.innerHTML = '<div style="padding:24px;">Loading…</div>';
    const r = await Api.get('/engagement/nps').catch(() => ({ data: [], summary: {} }));
    const s = r.summary || {};
    const npsScore = s.nps || 0;
    const npsColour = npsScore >= 50 ? '#10B981' : npsScore >= 0 ? '#F59E0B' : '#EF4444';
    main.innerHTML = `<div style="padding:24px;max-width:1800px;margin:0 auto;">
      <div class="kt-page-hero">
        <h2>📊 NPS — Net Promoter Score</h2>
        <p>${s.count || 0} responses in the last 12 months.</p>
      </div>
      <div class="kt-card" style="text-align:center;padding:36px;">
        <div style="font-size:14px;color:#64748B;font-weight:700;letter-spacing:2px;text-transform:uppercase;">NPS</div>
        <div style="font-size:72px;font-weight:800;color:${npsColour};line-height:1;margin:12px 0;">${npsScore}</div>
        <div style="display:flex;justify-content:center;gap:20px;margin-top:14px;">
          <div><div style="color:#10B981;font-weight:700;font-size:22px;">${s.promoters || 0}</div><div style="font-size:12px;color:#64748B;font-weight:600;">PROMOTERS (9-10)</div></div>
          <div><div style="color:#64748B;font-weight:700;font-size:22px;">${s.passive || 0}</div><div style="font-size:12px;color:#64748B;font-weight:600;">PASSIVE (7-8)</div></div>
          <div><div style="color:#EF4444;font-weight:700;font-size:22px;">${s.detractors || 0}</div><div style="font-size:12px;color:#64748B;font-weight:600;">DETRACTORS (0-6)</div></div>
        </div>
      </div>
      <div class="kt-card">
        <h3 style="margin:0 0 14px;color:#0F172A;font-size:16px;font-weight:700;">Recent comments</h3>
        <div data-kt-list="1">${(r.data || []).slice(0, 30).map(n => `<div style="padding:12px 0;border-bottom:1px solid #F1F5F9;">
          <div style="font-size:13px;color:#64748B;"><strong style="color:${n.score >= 9 ? '#10B981' : n.score >= 7 ? '#F59E0B' : '#EF4444'};">${n.score}/10</strong> · ${fmtDate(n.created_at)}</div>
          <div style="font-size:14px;color:#0F172A;margin-top:4px;">${esc(n.comment || '')}</div>
        </div>`).join('') || '<div style="text-align:center;padding:30px;color:#64748B;">No responses yet.</div>'}</div>
      </div>
    </div>`;
  }

  // ============================ E-sign documents =================================
  async function renderSignedDocs(main) {
    main.setAttribute('data-kt-pretty', '1');
    main.innerHTML = '<div style="padding:24px;">Loading…</div>';
    const isGuardian = /\brole-guardian\b/.test(document.body.className || '');
    const [sr, pr] = await Promise.all([
      Api.get('/engagement/signed-documents').catch(() => ({ data: [] })),
      isGuardian ? Api.get('/parent/edocuments').catch(() => ({ data: [] })) : Promise.resolve({ data: [] }),
    ]);
    const rows = sr.data || [];
    const pending = ((pr.data || pr.documents || [])).filter(d => !d.signed);

    // Pending signatures FIRST (parents only) — each card taps through to the
    // e-signature flow (#doc-workflows) with a clear Sign action.
    const pendingHtml = pending.length ? `
      <div style="font-size:12px;font-weight:800;letter-spacing:.5px;text-transform:uppercase;color:#B91C1C;margin:0 2px 10px;">⚠ Awaiting your signature (${pending.length})</div>
      ${pending.map(d => `<a href="#edocuments" style="display:flex;align-items:center;gap:12px;text-decoration:none;background:#FEF2F2;border:1px solid #FCA5A5;border-radius:14px;padding:13px 15px;margin-bottom:10px;">
        <span style="width:40px;height:40px;flex-shrink:0;border-radius:11px;background:#FEE2E2;display:flex;align-items:center;justify-content:center;font-size:20px;">📝</span>
        <span style="flex:1;min-width:0;"><span style="display:block;font-weight:800;color:#0F172A;font-size:14.5px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">${esc(d.title || d.name || d.document_name || 'Document')}</span><span style="display:block;font-size:12.5px;color:#B91C1C;margin-top:2px;">Tap to review &amp; sign</span></span>
        <span style="flex-shrink:0;background:#DC2626;color:#fff;font-size:13px;font-weight:800;padding:9px 18px;border-radius:11px;">Sign</span>
      </a>`).join('')}
      <div style="height:8px;"></div>` : '';

    const signedHtml = rows.length ? rows.map((s, i) => `
      <div style="display:flex;align-items:center;gap:12px;background:#fff;border:1px solid #E7EDF3;border-radius:14px;padding:12px 14px;margin-bottom:10px;">
        <span style="width:40px;height:40px;flex-shrink:0;border-radius:11px;background:#ECFDF5;display:flex;align-items:center;justify-content:center;font-size:20px;">✅</span>
        <span style="flex:1;min-width:0;">
          <span style="display:block;font-weight:800;color:#0F172A;font-size:14.5px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">${esc(s.document_type)}</span>
          <span style="display:block;font-size:12px;color:#64748B;margin-top:2px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">Signed ${fmtDate(s.signed_at)}${s.signer_name ? ' · ' + esc(s.signer_name) : ''}</span>
        </span>
        <span style="flex-shrink:0;display:inline-flex;gap:6px;">${_sdActions(s, i)}</span>
      </div>`).join('') : '<div style="text-align:center;padding:34px 16px;color:#64748B;background:#fff;border:1px dashed #CBD5E1;border-radius:14px;">No signed documents yet.</div>';

    main.innerHTML = `<div style="padding:18px 14px;max-width:900px;margin:0 auto;">
      <div class="kt-page-hero"><h2>📄 Documents</h2><p>Documents awaiting your signature, and your completed, audit-trailed signatures.</p></div>
      ${pendingHtml}
      <div style="font-size:12px;font-weight:800;letter-spacing:.5px;text-transform:uppercase;color:#64748B;margin:2px 2px 10px;">Signed &amp; on file</div>
      <div data-kt-list="1">${signedHtml}</div>
    </div>`;
    main.querySelectorAll('.kt-sd-view').forEach(b => { b.onclick = (e) => { e.preventDefault(); viewSignedDoc(rows[+b.dataset.i]); }; });
    main.querySelectorAll('.kt-sd-dl').forEach(b => { b.onclick = (e) => { e.preventDefault(); downloadSignedDoc(rows[+b.dataset.i]); }; });
    // Collapse the per-row View/Download icons into the standard ⋮ kebab.
    if (window.KT && typeof KT.sweepRowActions === 'function') setTimeout(KT.sweepRowActions, 0);
  }

  // Row action buttons — View (always) + Download (only when a server file is stored).
  const _SD_BTN_STYLE = 'width:36px;height:36px;min-height:0;box-sizing:border-box;padding:0;display:inline-flex;align-items:center;justify-content:center;border:1px solid #CFE3FF;border-radius:9px;background:#EFF6FF;cursor:pointer;color:#1D4ED8;';
  const _SD_EYE = '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>';
  const _SD_DL = '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>';
  function _sdActions(s, i) {
    const hasFile = !!(s.doc_file_url || (s.signature_data && String(s.signature_data).indexOf('/storage') === 0));
    let h = '<button class="kt-sd-view kt-icon-tip" data-i="' + i + '" title="View document" data-kttip="View document" aria-label="View document" style="' + _SD_BTN_STYLE + '">' + _SD_EYE + '</button>';
    if (hasFile) h += '<button class="kt-sd-dl kt-icon-tip" data-i="' + i + '" title="Download" data-kttip="Download" aria-label="Download" style="margin-left:6px;' + _SD_BTN_STYLE + '">' + _SD_DL + '</button>';
    return h;
  }

  // Download the signed file with a datestamped name (Type-Signer-YYYY-MM-DD.ext). Uses
  // the authed API endpoint (Bearer + active-agency) → blob → save, so the browser keeps
  // the server-provided filename regardless of the cross-origin /storage host.
  function downloadSignedDoc(s) {
    if (!s || !s.id) return;
    const base = (window.KT && KT.API_BASE) || 'https://api.kiddietrac.com/api/v1';
    const tok = (window.KT && KT.Auth && KT.Auth.token && KT.Auth.token()) || localStorage.getItem('kt_token') || '';
    const hdrs = { 'Authorization': 'Bearer ' + tok };
    try { const a = sessionStorage.getItem('kt_active_agency_id'); if (a) hdrs['X-Active-Agency-Id'] = a; } catch (e) {}
    fetch(base + '/engagement/signed-documents/' + s.id + '/download', { headers: hdrs })
      .then(r => {
        if (!r.ok) throw new Error(r.status === 404 ? 'No downloadable file for this signature.' : 'Download failed');
        const disp = r.headers.get('Content-Disposition') || '';
        const m = disp.match(/filename="?([^"]+)"?/);
        const fname = m ? m[1] : ('signed-document-' + s.id);
        return r.blob().then(b => ({ b: b, fname: fname }));
      })
      .then(o => {
        const url = URL.createObjectURL(o.b);
        const a = document.createElement('a');
        a.href = url; a.download = o.fname; document.body.appendChild(a); a.click(); a.remove();
        setTimeout(() => URL.revokeObjectURL(url), 4000);
      })
      .catch(e => { if (window.KT && KT.toast) KT.toast('⚠️', 'Could not download', e.message || '', '#B91C1C'); });
  }

  // The API HOST (not the /api/v1 base) — stored documents & signature images are
  // served from https://api.kiddietrac.com/storage/… , not under /api/v1.
  function esignApiHost() {
    const b = (window.KT && KT.API_BASE) || 'https://api.kiddietrac.com/api/v1';
    return b.replace(/\/api\/v1\/?$/, '');
  }
  // Open the signed artifact. NDA/agreement rows carry doc_file_url (the full signed
  // PDF/HTML) → open it directly. Handbook / ad-hoc rows have only a signature image
  // + audit metadata → show that in a modal (there is no stored document body).
  function viewSignedDoc(s) {
    if (!s) return;
    if (s.doc_file_url) {
      var _u = esignApiHost() + s.doc_file_url;
      if (window.KT && KT.viewDocument) { KT.viewDocument(_u, { title: s.doc_type || 'Signed document', label: 'Signed document' }); return; }
      window.open(_u, '_blank', 'noopener'); return;
    }
    const sig = s.signature_data || '';
    const src = sig.indexOf('data:') === 0 ? sig : (sig.charAt(0) === '/' ? esignApiHost() + sig : '');
    const m = document.createElement('div');
    m.style.cssText = 'position:fixed;inset:0;background:rgba(15,23,42,0.55);z-index:99999;display:flex;align-items:center;justify-content:center;padding:20px;';
    m.innerHTML = '<div style="background:#fff;padding:24px;border-radius:14px;max-width:520px;width:100%;">'
      + '<h3 style="margin:0 0 4px;color:#0F172A;">✍ ' + esc(s.document_type || 'Signed document') + '</h3>'
      + '<p style="margin:0 0 14px;color:#64748B;font-size:13px;">Signed by ' + esc(s.signer_name || '') + ' · ' + fmtDate(s.signed_at) + '</p>'
      + (src ? '<div style="border:1px solid #E2E8F0;border-radius:8px;padding:10px;text-align:center;background:#fff;"><img src="' + src + '" alt="Signature" style="max-width:100%;max-height:200px;"></div>'
             : '<p style="color:#64748B;">No downloadable document is stored for this signature — only the audit record below.</p>')
      + '<div style="margin-top:12px;font-size:12px;color:#64748B;font-family:ui-monospace,monospace;word-break:break-all;">SHA-256: ' + esc(s.document_hash || '—') + '<br>IP: ' + esc(s.ip_address || '—') + '</div>'
      + '<div style="text-align:right;margin-top:16px;"><button class="kt-btn kt-btn-primary" style="padding:7px 16px;">Close</button></div>'
      + '</div>';
    m.querySelector('button').onclick = () => m.remove();
    m.addEventListener('click', e => { if (e.target === m) m.remove(); });
    document.body.appendChild(m);
  }
  function openESignModal() {
    const m = document.createElement('div');
    m.style.cssText = 'position:fixed;inset:0;background:rgba(15,23,42,0.55);z-index:99999;display:flex;align-items:center;justify-content:center;';
    m.innerHTML = `<div style="background:#fff;padding:28px;border-radius:14px;max-width:560px;width:92%;">
      <h3 style="margin:0 0 16px;color:#0F172A;">Sign a document</h3>
      <label style="display:block;font-size:13px;font-weight:600;margin:10px 0 4px;">Document type</label>
      <select id="es-type" style="width:100%;padding:9px;border:1px solid #E2E8F0;border-radius:8px;">
        <option value="enrollment_contract">Enrollment contract</option>
        <option value="photo_consent">Photo consent</option>
        <option value="medical_authorization">Medical authorization</option>
        <option value="field_trip">Field trip permission</option>
        <option value="parent_handbook">Parent handbook acknowledgement</option>
        <option value="other">Other</option>
      </select>
      <label style="display:block;font-size:13px;font-weight:600;margin:14px 0 4px;">Document text (what you're agreeing to)</label>
      <textarea id="es-text" rows="5" placeholder="Paste the policy / contract text here…" style="width:100%;padding:9px;border:1px solid #E2E8F0;border-radius:8px;font-family:inherit;"></textarea>
      <label style="display:block;font-size:13px;font-weight:600;margin:14px 0 4px;">Your full name</label>
      <input id="es-name" placeholder="Jane Smith" style="width:100%;padding:9px;border:1px solid #E2E8F0;border-radius:8px;">
      <label style="display:block;font-size:13px;font-weight:600;margin:14px 0 4px;">Signature</label>
      <canvas id="es-pad" width="500" height="160" style="border:2px dashed #CBD5E1;border-radius:8px;width:100%;background:#FAFCFE;cursor:crosshair;"></canvas>
      <div style="display:flex;justify-content:space-between;align-items:center;margin-top:6px;">
        <button id="es-clear" style="background:#F1F5F9;border:0;padding:6px 14px;border-radius:6px;cursor:pointer;font-size:12px;">Clear</button>
        <div style="font-size:11px;color:#64748B;">Sign above with mouse or finger</div>
      </div>
      <div style="margin-top:20px;text-align:right;">
        <button id="es-cancel" style="background:#F1F5F9;border:0;padding:10px 18px;border-radius:8px;cursor:pointer;font-weight:600;">Cancel</button>
        <button id="es-save" class="kt-btn kt-btn-primary" style="margin-left:8px;">Submit signature</button>
      </div></div>`;
    document.body.appendChild(m);
    const c = m.querySelector('#es-pad');
    const ctx = c.getContext('2d');
    ctx.strokeStyle = '#0F172A'; ctx.lineWidth = 2; ctx.lineCap = 'round';
    let drawing = false;
    const pos = (e) => { const r = c.getBoundingClientRect(); const t = e.touches ? e.touches[0] : e; return [(t.clientX - r.left) * c.width / r.width, (t.clientY - r.top) * c.height / r.height]; };
    const start = (e) => { e.preventDefault(); drawing = true; const [x, y] = pos(e); ctx.beginPath(); ctx.moveTo(x, y); };
    const draw = (e) => { if (!drawing) return; e.preventDefault(); const [x, y] = pos(e); ctx.lineTo(x, y); ctx.stroke(); };
    const stop = () => { drawing = false; };
    c.addEventListener('mousedown', start); c.addEventListener('mousemove', draw); c.addEventListener('mouseup', stop); c.addEventListener('mouseleave', stop);
    c.addEventListener('touchstart', start); c.addEventListener('touchmove', draw); c.addEventListener('touchend', stop);
    m.querySelector('#es-clear').onclick = () => ctx.clearRect(0, 0, c.width, c.height);
    m.querySelector('#es-cancel').onclick = () => m.remove();
    m.querySelector('#es-save').onclick = async () => {
      if (!m.querySelector('#es-name').value.trim() || !m.querySelector('#es-text').value.trim()) { alert('Name + document text required'); return; }
      await Api.post('/engagement/sign-document', {
        document_type: m.querySelector('#es-type').value,
        signer_name: m.querySelector('#es-name').value,
        signature_data: c.toDataURL('image/png'),
        document_text: m.querySelector('#es-text').value,
      });
      m.remove();
      renderSignedDocs(document.querySelector('main'));
    };
  }

  // ============================ Bus routes (transport) =================================
  async function renderBusRoutes(main) {
    main.setAttribute('data-kt-pretty', '1');
    main.innerHTML = '<div style="padding:24px;">Loading…</div>';
    const r = await Api.get('/operations/bus-routes').catch(() => ({ data: [] }));
    main.innerHTML = `<div style="padding:24px;max-width:1800px;margin:0 auto;">
      <div class="kt-page-hero">
        <h2>🚐 Bus routes</h2>
        <p>Pickup + dropoff rosters per route. Track every child on each route with stop order, time, address.</p>
      </div>
      <div class="kt-card">
        <table>
          <thead><tr><th>Route</th><th>Driver</th><th>Vehicle</th><th>Active</th></tr></thead>
          <tbody>${(r.data || []).map(b => `<tr>
            <td><strong>${esc(b.route_name)}</strong></td>
            <td>${esc(b.driver_name || '—')}</td>
            <td>${esc(b.vehicle_label || '—')}</td>
            <td><span class="kt-pill ${b.active ? 'kt-pill-success' : 'kt-pill-warning'}">${b.active ? 'Active' : 'Paused'}</span></td>
          </tr>`).join('') || '<tr><td colspan="4" style="text-align:center;padding:40px;color:#64748B;">No routes set up.</td></tr>'}</tbody>
        </table>
      </div>
    </div>`;
  }

  // ============================ Room rotation candidates =================================
  // Age as whole years + months (no decimals) — "2 yr 7 mo" / "9 mo".
  function fmtAgeYM(months) {
    var m = Math.round(Number(months) || 0);
    if (m < 0) m = 0;
    var y = Math.floor(m / 12), r = m % 12;
    if (y <= 0) return r + ' mo';
    if (r === 0) return y + ' yr';
    return y + ' yr ' + r + ' mo';
  }
  async function renderRoomRotations(main) {
    main.setAttribute('data-kt-pretty', '1');
    main.innerHTML = '<div style="padding:24px;">Loading…</div>';
    const r = await Api.get('/operations/rotation-candidates').catch(() => ({ data: [] }));
    main.innerHTML = `<div style="padding:24px;max-width:1800px;margin:0 auto;">
      <div class="kt-page-hero">
        <h2>🔄 Room rotation candidates</h2>
        <p>Children aging into the next age band within 60 days. Plan their move so the receiving room can prepare.</p>
      </div>
      <div class="kt-card">
        <table>
          <thead><tr><th>Child</th><th>Current age</th><th>Reaches threshold</th><th>Days until</th><th>Centre</th></tr></thead>
          <tbody>${(r.data || []).map(c => `<tr>
            <td><strong>${esc(c.child_name)}</strong></td>
            <td>${fmtAgeYM(c.current_age_months)}</td>
            <td>${fmtAgeYM(c.threshold)} on ${fmtDate(c.reach_date)}</td>
            <td><span class="kt-pill ${c.days_until <= 14 ? 'kt-pill-danger' : c.days_until <= 30 ? 'kt-pill-warning' : 'kt-pill-info'}">${c.days_until} days</span></td>
            <td>${esc(c.centre_name)}</td>
          </tr>`).join('') || '<tr><td colspan="5" style="text-align:center;padding:40px;color:#64748B;">No upcoming rotations.</td></tr>'}</tbody>
        </table>
      </div>
    </div>`;
  }

  window.KT = KT;
  window.KT.V22p56 = {
    renderClosures, renderLatePickup, renderRoomRatios,
    renderVacationHolds, renderTuitionIncreases,
    renderReenrollment, renderEngagement, renderNps,
    renderSignedDocs, renderBusRoutes, renderRoomRotations,
  };
})(window);
