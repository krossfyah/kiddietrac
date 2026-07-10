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
  const fmtDate = (s) => { if (!s) return ''; const d = new Date(s); return d.toLocaleDateString(undefined, { year: 'numeric', month: 'short', day: 'numeric' }); };
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
          <thead><tr><th>Date</th><th>Centre</th><th>Type</th><th>Reason</th><th>Billing</th><th></th></tr></thead>
          <tbody>${(r.data || []).map(c => `<tr>
            <td><strong>${fmtDate(c.closure_date)}${c.end_date ? ' – ' + fmtDate(c.end_date) : ''}</strong></td>
            <td>${esc(c.centre_name)}</td>
            <td><span class="kt-pill kt-pill-info">${esc(c.closure_type)}</span></td>
            <td>${esc(c.reason || '—')}</td>
            <td>${c.affects_billing ? 'Paused' : 'No change'}</td>
            <td><button class="kt-btn kt-btn-danger" data-rm="${c.id}">Remove</button></td>
          </tr>`).join('') || '<tr><td colspan="6" style="text-align:center;padding:40px;color:#94A3B8;">No closures scheduled.</td></tr>'}</tbody>
        </table>
      </div>
    </div>`;
    document.getElementById('cl-new').onclick = () => openClosureModal();
    main.querySelectorAll('button[data-rm]').forEach(b => b.onclick = async () => {
      if (!confirm('Remove this closure?')) return;
      await Api.delete(`/operations/closures/${b.dataset.rm}`);
      renderClosures(main);
    });
  }
  async function openClosureModal() {
    const _cr = await Api.get('/admin/centres').catch(() => ({})); const centres = _cr.centres || _cr.data || [];
    const m = document.createElement('div');
    m.style.cssText = 'position:fixed;inset:0;background:rgba(15,23,42,0.5);z-index:99999;display:flex;align-items:center;justify-content:center;';
    m.innerHTML = `<div style="background:#fff;padding:28px;border-radius:14px;max-width:480px;width:92%;">
      <h3 style="margin:0 0 16px;color:#0F172A;">New closure</h3>
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
    m.querySelector('#cl-save').onclick = async () => {
      await Api.post('/operations/closures', {
        centre_id: +m.querySelector('#cl-centre').value,
        closure_date: m.querySelector('#cl-date').value,
        end_date: m.querySelector('#cl-end').value || null,
        closure_type: m.querySelector('#cl-type').value,
        reason: m.querySelector('#cl-reason').value,
        affects_billing: m.querySelector('#cl-bill').checked,
      });
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
          </tr>`).join('') || '<tr><td colspan="5" style="text-align:center;padding:40px;color:#94A3B8;">No late pickups logged.</td></tr>'}</tbody>
        </table>
      </div>
    </div>`;
    document.getElementById('lp-new').onclick = () => openLatePickupModal();
  }
  async function openLatePickupModal() {
    const _cr = await Api.get('/admin/centres').catch(() => ({})); const centres = _cr.centres || _cr.data || [];
    const children = (await Api.get('/admin/children').catch(() => ({ data: [] }))).data || [];
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
          </tr>`).join('') || '<tr><td colspan="6" style="text-align:center;padding:40px;color:#94A3B8;">No rooms tracked.</td></tr>'}</tbody>
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
          </tr>`).join('') || '<tr><td colspan="6" style="text-align:center;padding:40px;color:#94A3B8;">No vacation holds.</td></tr>'}</tbody>
        </table>
      </div>
    </div>`;
    main.querySelectorAll('button[data-decide]').forEach(b => b.onclick = async () => {
      const credit = b.dataset.action === 'approved' ? prompt('Credit amount (or leave blank for auto):', '') : null;
      await Api.patch(`/billing/vacation-holds/${b.dataset.decide}`, { status: b.dataset.action, credit_amount: credit ? parseFloat(credit) : null });
      renderVacationHolds(main);
    });
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
          </tr>`).join('') || '<tr><td colspan="5" style="text-align:center;padding:40px;color:#94A3B8;">No tuition increases scheduled.</td></tr>'}</tbody>
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
          </tr>`).join('') || '<tr><td colspan="7" style="text-align:center;padding:40px;color:#94A3B8;">No campaigns yet.</td></tr>'}</tbody>
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
          }).join('') || '<tr><td colspan="4" style="text-align:center;padding:40px;color:#94A3B8;">No engagement data yet.</td></tr>'}</tbody>
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
          <div><div style="color:#94A3B8;font-weight:700;font-size:22px;">${s.passive || 0}</div><div style="font-size:12px;color:#64748B;font-weight:600;">PASSIVE (7-8)</div></div>
          <div><div style="color:#EF4444;font-weight:700;font-size:22px;">${s.detractors || 0}</div><div style="font-size:12px;color:#64748B;font-weight:600;">DETRACTORS (0-6)</div></div>
        </div>
      </div>
      <div class="kt-card">
        <h3 style="margin:0 0 14px;color:#0F172A;font-size:16px;font-weight:700;">Recent comments</h3>
        <div data-kt-list="1">${(r.data || []).slice(0, 30).map(n => `<div style="padding:12px 0;border-bottom:1px solid #F1F5F9;">
          <div style="font-size:13px;color:#64748B;"><strong style="color:${n.score >= 9 ? '#10B981' : n.score >= 7 ? '#F59E0B' : '#EF4444'};">${n.score}/10</strong> · ${fmtDate(n.created_at)}</div>
          <div style="font-size:14px;color:#0F172A;margin-top:4px;">${esc(n.comment || '')}</div>
        </div>`).join('') || '<div style="text-align:center;padding:30px;color:#94A3B8;">No responses yet.</div>'}</div>
      </div>
    </div>`;
  }

  // ============================ E-sign documents =================================
  async function renderSignedDocs(main) {
    main.setAttribute('data-kt-pretty', '1');
    main.innerHTML = '<div style="padding:24px;">Loading…</div>';
    const r = await Api.get('/engagement/signed-documents').catch(() => ({ data: [] }));
    main.innerHTML = `<div style="padding:24px;max-width:1800px;margin:0 auto;">
      <div class="kt-page-hero">
        <h2>✍ Signed documents</h2>
        <p>${(r.data || []).length} document(s) with audit-trail signatures. Each row includes IP, user-agent, and SHA-256 hash for legal traceability.</p>
        <div class="kt-hero-actions"><button class="kt-btn kt-btn-ghost" id="esign-new">+ Sign a new document</button></div>
      </div>
      <div class="kt-card">
        <table>
          <thead><tr><th>Signed</th><th>Document</th><th>Signer</th><th>Hash</th><th>IP</th></tr></thead>
          <tbody>${(r.data || []).map(s => `<tr>
            <td>${fmtDate(s.signed_at)}</td>
            <td><strong>${esc(s.document_type)}</strong></td>
            <td>${esc(s.signer_name)}</td>
            <td style="font-family:ui-monospace,monospace;font-size:11px;color:#64748B;">${esc((s.document_hash || '').substring(0, 16))}…</td>
            <td style="font-size:12px;color:#64748B;">${esc(s.ip_address || '')}</td>
          </tr>`).join('') || '<tr><td colspan="5" style="text-align:center;padding:40px;color:#94A3B8;">No signatures yet.</td></tr>'}</tbody>
        </table>
      </div>
    </div>`;
    document.getElementById('esign-new').onclick = () => openESignModal();
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
        <div style="font-size:11px;color:#94A3B8;">Sign above with mouse or finger</div>
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
          </tr>`).join('') || '<tr><td colspan="4" style="text-align:center;padding:40px;color:#94A3B8;">No routes set up.</td></tr>'}</tbody>
        </table>
      </div>
    </div>`;
  }

  // ============================ Room rotation candidates =================================
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
            <td>${c.current_age_months} months</td>
            <td>${c.threshold} months on ${fmtDate(c.reach_date)}</td>
            <td><span class="kt-pill ${c.days_until <= 14 ? 'kt-pill-danger' : c.days_until <= 30 ? 'kt-pill-warning' : 'kt-pill-info'}">${c.days_until} days</span></td>
            <td>${esc(c.centre_name)}</td>
          </tr>`).join('') || '<tr><td colspan="5" style="text-align:center;padding:40px;color:#94A3B8;">No upcoming rotations.</td></tr>'}</tbody>
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
