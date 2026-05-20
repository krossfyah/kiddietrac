/* v22p63 — 8 new feature screens. */
(function (window) {
  'use strict';
  const KT = window.KT || {};
  const Api = KT.Api;
  if (!Api) { console.warn('v22p63: KT.Api unavailable'); return; }

  const apiBase = () => (KT.API_BASE) || 'https://api.kiddietrac.com/api/v1';
  const esc = (s) => String(s == null ? '' : s).replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  const fmtDate = (s) => { if (!s) return ''; const d = new Date(s); return d.toLocaleDateString(undefined, { year: 'numeric', month: 'short', day: 'numeric' }); };
  const fmtMoney = (n) => '$' + (Number(n) || 0).toFixed(2);
  const toast = (msg, kind) => KT.toast ? KT.toast(msg, kind) : alert(msg);

  // ============================ Wellness pre-arrival screening ============================
  async function renderWellness(main) {
    main.setAttribute('data-kt-pretty', '1');
    main.innerHTML = '<div style="padding:24px;">Loading…</div>';
    const childrenRes = await Api.get('/parent/children').catch(() => ({ data: [] }));
    const children = childrenRes.data || childrenRes || [];
    if (!children.length) { main.innerHTML = '<div class="kt-card" style="margin:24px;padding:40px;text-align:center;color:#94A3B8;">No children.</div>'; return; }
    const today = (await Api.get(`/wellness/today/${children[0].id}`).catch(() => ({ data: null }))).data || {};

    main.innerHTML = `<div style="padding:24px;max-width:1800px;margin:0 auto;">
      <div class="kt-page-hero">
        <h2>🩺 Wellness check</h2>
        <p>Quick health screening before drop-off. Tap any symptom your child has today. If you tick none, you're cleared to drop off.</p>
      </div>
      <div class="kt-card">
        <label style="font-size:13px;font-weight:600;">Child</label>
        <select id="ws-child" style="width:100%;padding:11px;border:1px solid #E2E8F0;border-radius:8px;margin-top:6px;">
          ${children.map(c => `<option value="${c.id}">${esc(c.first_name)} ${esc(c.last_name)}</option>`).join('')}
        </select>
        <h4 style="margin-top:24px;color:#0F172A;">Any of these today?</h4>
        <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(160px,1fr));gap:10px;">
          ${[
            ['fever', 'Fever 🌡', '#EF4444'],
            ['cough', 'Cough 😷', '#F59E0B'],
            ['runny_nose', 'Runny nose 🤧', '#F59E0B'],
            ['vomiting', 'Vomiting 🤢', '#EF4444'],
            ['diarrhea', 'Diarrhea 💩', '#EF4444'],
            ['rash', 'Rash 🟥', '#F59E0B'],
            ['exposure', 'Sick contact 🦠', '#EF4444'],
          ].map(([k, lbl, c]) => `<label style="display:flex;align-items:center;gap:10px;padding:14px;background:#FAFCFE;border:2px solid #E2E8F0;border-radius:10px;cursor:pointer;" data-sym-wrap="${k}">
            <input type="checkbox" data-sym="${k}" ${today[k] ? 'checked' : ''} style="width:20px;height:20px;cursor:pointer;accent-color:${c};">
            <span style="font-weight:600;">${lbl}</span></label>`).join('')}
        </div>
        <label style="display:block;font-size:13px;font-weight:600;margin-top:18px;">Notes</label>
        <textarea id="ws-notes" rows="3" style="width:100%;padding:11px;border:1px solid #E2E8F0;border-radius:8px;font-family:inherit;">${esc(today.notes || '')}</textarea>
        <button id="ws-submit" class="kt-btn kt-btn-primary" style="margin-top:16px;width:100%;padding:14px;">Submit screening</button>
        <div id="ws-result" style="margin-top:14px;"></div>
      </div>
    </div>`;

    // Visual feedback on symptom toggle
    main.querySelectorAll('input[data-sym]').forEach(cb => {
      const update = () => {
        const wrap = cb.closest('label');
        wrap.style.background = cb.checked ? '#FEE2E2' : '#FAFCFE';
        wrap.style.borderColor = cb.checked ? '#EF4444' : '#E2E8F0';
      };
      cb.addEventListener('change', update);
      update();
    });
    document.getElementById('ws-submit').onclick = async () => {
      const body = { child_id: +document.getElementById('ws-child').value, notes: document.getElementById('ws-notes').value };
      main.querySelectorAll('input[data-sym]').forEach(cb => body[cb.dataset.sym] = cb.checked);
      try {
        const r = await Api.post('/wellness/screening', body);
        const colour = r.result === 'block' ? '#FEE2E2' : r.result === 'review' ? '#FEF3C7' : '#DCFCE7';
        const fg = r.result === 'block' ? '#991B1B' : r.result === 'review' ? '#92400E' : '#166534';
        const msg = r.result === 'block' ? '🚫 Please keep your child home today and contact the centre.'
          : r.result === 'review' ? '⚠ Submitted. Staff will review symptoms at arrival.'
          : '✓ Cleared for drop-off today.';
        document.getElementById('ws-result').innerHTML = `<div style="background:${colour};color:${fg};padding:16px;border-radius:10px;font-weight:600;">${msg}</div>`;
      } catch (e) { toast(e.message || 'Submission failed', 'error'); }
    };
  }

  // ============================ Wellness digest (staff) ============================
  async function renderWellnessDigest(main) {
    main.setAttribute('data-kt-pretty', '1');
    main.innerHTML = '<div style="padding:24px;">Loading…</div>';
    const centres = (await Api.get('/admin/centres').catch(() => ({ data: [] }))).data || [];
    if (!centres.length) { main.innerHTML = '<div class="kt-card" style="margin:24px;padding:40px;text-align:center;color:#94A3B8;">No centres.</div>'; return; }
    const r = await Api.get(`/wellness/digest?centre_id=${centres[0].id}`);
    main.innerHTML = `<div style="padding:24px;max-width:1800px;margin:0 auto;">
      <div class="kt-page-hero">
        <h2>🩺 Daily wellness</h2>
        <p>${esc(centres[0].name)} · ${fmtDate(r.date)} · ${r.submitted_count}/${r.enrolled_count} screenings in</p>
      </div>
      <div class="kt-kpi-grid">
        <div class="kt-kpi kt-kpi-success"><div class="kt-kpi-label">Cleared</div><div class="kt-kpi-value">${r.counts.cleared || 0}</div></div>
        <div class="kt-kpi kt-kpi-warning"><div class="kt-kpi-label">Review</div><div class="kt-kpi-value">${r.counts.review || 0}</div></div>
        <div class="kt-kpi kt-kpi-danger"><div class="kt-kpi-label">Block</div><div class="kt-kpi-value">${r.counts.block || 0}</div></div>
        <div class="kt-kpi"><div class="kt-kpi-label">Not submitted</div><div class="kt-kpi-value">${r.not_submitted_count}</div></div>
      </div>
      <div class="kt-card">
        <table>
          <thead><tr><th>Child</th><th>Result</th><th>Symptoms reported</th><th>Notes</th></tr></thead>
          <tbody>${(r.data || []).map(row => {
            const syms = ['fever','cough','runny_nose','vomiting','diarrhea','rash','exposure'].filter(k => row[k]).join(', ') || 'none';
            const pill = row.result === 'block' ? 'kt-pill-danger' : row.result === 'review' ? 'kt-pill-warning' : 'kt-pill-success';
            return `<tr>
              <td><strong>${esc(row.child_name)}</strong></td>
              <td><span class="kt-pill ${pill}">${esc(row.result)}</span></td>
              <td style="font-size:12.5px;color:#475569;">${esc(syms)}</td>
              <td style="font-size:12.5px;color:#475569;">${esc(row.notes || '')}</td>
            </tr>`;
          }).join('') || '<tr><td colspan="4" style="text-align:center;padding:30px;color:#94A3B8;">No screenings submitted yet today.</td></tr>'}</tbody>
        </table>
      </div>
    </div>`;
  }

  // ============================ Payment plans ============================
  async function renderPaymentPlans(main) {
    main.setAttribute('data-kt-pretty', '1');
    main.innerHTML = '<div style="padding:24px;">Loading…</div>';
    const u = JSON.parse(sessionStorage.getItem('kt_user') || '{}');
    const isStaff = Array.isArray(u.roles) && u.roles.some(r => ['agency_admin', 'centre_director', 'platform_admin'].includes(r));
    let r;
    if (isStaff) {
      const fid = +(await KT.prompt({ title: 'Family ID', description: 'Enter the family ID to view their payment plans.' }));
      if (!fid) { main.innerHTML = '<div class="kt-card" style="margin:24px;padding:40px;text-align:center;color:#94A3B8;">Enter a family ID via the action button to load plans.</div>'; return; }
      r = await Api.get(`/payment-plans/family/${fid}`);
    } else {
      r = await Api.get('/payment-plans/mine');
    }
    const plans = r.data || [];
    main.innerHTML = `<div style="padding:24px;max-width:1800px;margin:0 auto;">
      <div class="kt-page-hero">
        <h2>📅 Payment plans</h2>
        <p>${plans.length} plan(s). Split a large balance into manageable installments.</p>
        ${isStaff ? '<div class="kt-hero-actions"><button class="kt-btn kt-btn-ghost" id="pp-new">+ New payment plan</button></div>' : ''}
      </div>
      ${plans.map(p => `<div class="kt-card">
        <div style="display:flex;justify-content:space-between;align-items:flex-start;">
          <div>
            <h3 style="margin:0;color:#0F172A;">${fmtMoney(p.total_amount)} over ${p.installment_count} installments</h3>
            <div style="color:#475569;font-size:13px;margin-top:4px;">Status: <span class="kt-pill ${p.status === 'active' ? 'kt-pill-success' : 'kt-pill-warning'}">${esc(p.status)}</span> · created ${fmtDate(p.created_at)}</div>
          </div>
          ${isStaff && p.status === 'active' ? `<button class="kt-btn kt-btn-danger" data-cancel-plan="${p.id}">Cancel plan</button>` : ''}
        </div>
        ${p.notes ? `<p style="color:#475569;margin:10px 0 0;font-size:13px;">${esc(p.notes)}</p>` : ''}
        <table style="margin-top:14px;">
          <thead><tr><th>Due date</th><th style="text-align:right;">Amount</th><th>Status</th></tr></thead>
          <tbody>${(p.installments || []).map(i => `<tr>
            <td>${fmtDate(i.due_date)}</td>
            <td style="text-align:right;font-weight:600;">${fmtMoney(i.amount)}</td>
            <td><span class="kt-pill ${i.status === 'paid' ? 'kt-pill-success' : i.status === 'cancelled' ? 'kt-pill-warning' : 'kt-pill-info'}">${esc(i.status)}</span></td>
          </tr>`).join('')}</tbody>
        </table>
      </div>`).join('') || '<div class="kt-card" style="text-align:center;padding:60px;color:#94A3B8;">No payment plans on file.</div>'}
    </div>`;
    if (isStaff) {
      const newBtn = document.getElementById('pp-new');
      if (newBtn) newBtn.onclick = () => openPaymentPlanModal();
      main.querySelectorAll('button[data-cancel-plan]').forEach(b => b.onclick = async () => {
        if (!await KT.confirm({ title: 'Cancel this payment plan?', description: 'All pending installments will be cancelled. Paid installments remain.', tone: 'danger' })) return;
        await Api.post(`/payment-plans/${b.dataset.cancelPlan}/cancel`, {});
        renderPaymentPlans(main);
      });
    }
  }
  async function openPaymentPlanModal() {
    const r = await KT.prompt({
      title: 'New payment plan',
      fields: [
        { key: 'family_id', label: 'Family ID', type: 'number' },
        { key: 'total_amount', label: 'Total amount ($)', type: 'number', step: '0.01' },
        { key: 'installment_count', label: '# of installments (2-24)', type: 'number', min: 2, max: 24, value: 4 },
        { key: 'first_due_date', label: 'First due date', type: 'date' },
        { key: 'cadence', label: 'Cadence (weekly / biweekly / monthly)', value: 'monthly' },
        { key: 'notes', label: 'Notes (optional)' },
      ],
      okLabel: 'Create plan',
    });
    if (!r) return;
    try {
      await Api.post('/payment-plans', {
        family_id: +r.family_id, total_amount: parseFloat(r.total_amount),
        installment_count: +r.installment_count, first_due_date: r.first_due_date,
        cadence: r.cadence, notes: r.notes,
      });
      toast('Plan created', 'success');
      renderPaymentPlans(document.querySelector('main'));
    } catch (e) { toast(e.message || 'Failed', 'error'); }
  }

  // ============================ Document workflows ============================
  async function renderDocWorkflows(main) {
    main.setAttribute('data-kt-pretty', '1');
    main.innerHTML = '<div style="padding:24px;">Loading…</div>';
    const r = await Api.get('/doc-workflows');
    main.innerHTML = `<div style="padding:24px;max-width:1800px;margin:0 auto;">
      <div class="kt-page-hero">
        <h2>📜 Document workflows</h2>
        <p>Multi-step signoff — parent signs, admin reviews, director countersigns. Full audit trail.</p>
        <div class="kt-hero-actions"><button class="kt-btn kt-btn-ghost" id="dw-new">+ New workflow</button></div>
      </div>
      <div class="kt-card">
        <table>
          <thead><tr><th>Title</th><th>Type</th><th>Current step</th><th>Status</th><th>Created</th><th></th></tr></thead>
          <tbody>${(r.data || []).map(w => `<tr>
            <td><strong>${esc(w.title)}</strong></td>
            <td><span class="kt-pill kt-pill-info">${esc(w.document_type)}</span></td>
            <td>Step ${w.current_step}</td>
            <td><span class="kt-pill ${w.status === 'complete' ? 'kt-pill-success' : 'kt-pill-warning'}">${esc(w.status)}</span></td>
            <td>${fmtDate(w.created_at)}</td>
            <td><button class="kt-btn kt-btn-primary" data-open-wf="${w.id}" style="font-size:12px;padding:6px 12px;">Open</button></td>
          </tr>`).join('') || '<tr><td colspan="6" style="text-align:center;padding:40px;color:#94A3B8;">No workflows yet.</td></tr>'}</tbody>
        </table>
      </div>
    </div>`;
    document.getElementById('dw-new').onclick = () => openDocWorkflowModal();
    main.querySelectorAll('button[data-open-wf]').forEach(b => b.onclick = () => openWorkflowDetail(+b.dataset.openWf));
  }
  async function openDocWorkflowModal() {
    const r = await KT.prompt({
      title: 'New document workflow',
      fields: [
        { key: 'title', label: 'Title', placeholder: 'Field-trip consent' },
        { key: 'document_type', label: 'Document type', value: 'consent' },
        { key: 'document_text', label: 'Document text (what signers agree to)', value: '' },
      ],
      okLabel: 'Continue',
    });
    if (!r) return;
    // Quick second prompt for signers
    const s = await KT.prompt({
      title: 'Signing order',
      description: 'Enter roles separated by commas (e.g. guardian, centre_director). Max 6.',
      fields: [{ key: 'roles', label: 'Signers in order', value: 'guardian, centre_director' }],
    });
    if (!s) return;
    const roles = (s.roles || '').split(',').map(x => x.trim()).filter(Boolean);
    const steps = roles.map(role => ({ signer_role: role, signer_label: role.replace(/_/g, ' ') }));
    try {
      await Api.post('/doc-workflows', {
        title: r.title, document_type: r.document_type, document_text: r.document_text, steps,
      });
      toast('Workflow created', 'success');
      renderDocWorkflows(document.querySelector('main'));
    } catch (e) { toast(e.message || 'Failed', 'error'); }
  }
  async function openWorkflowDetail(id) {
    const r = await Api.get(`/doc-workflows/${id}`);
    const w = r.workflow;
    const steps = r.steps;
    const u = JSON.parse(sessionStorage.getItem('kt_user') || '{}');
    const currentStep = steps.find(s => s.step_order === w.current_step);
    const isMyTurn = currentStep && w.status === 'in_progress' && (!currentStep.signer_user_id || currentStep.signer_user_id === u.id);

    const m = document.createElement('div');
    m.style.cssText = 'position:fixed;inset:0;background:rgba(15,23,42,0.55);z-index:99999;display:flex;align-items:center;justify-content:center;';
    m.innerHTML = `<div style="background:#fff;padding:28px;border-radius:14px;max-width:580px;width:92%;max-height:90vh;overflow:auto;">
      <h3 style="margin:0 0 6px;">${esc(w.title)}</h3>
      <div style="color:#475569;font-size:13px;margin-bottom:14px;">${esc(w.document_type)} · ${esc(w.status)}</div>
      <div style="background:#F8FAFC;padding:14px;border-radius:8px;font-size:13.5px;line-height:1.5;max-height:200px;overflow:auto;white-space:pre-wrap;">${esc(w.document_text)}</div>
      <h4 style="margin-top:18px;color:#0F172A;font-size:14px;">Signers</h4>
      <ol style="padding-left:18px;margin:6px 0;">
        ${steps.map(s => `<li style="padding:6px 0;color:${s.status === 'signed' ? '#15803D' : s.status === 'awaiting' ? '#1F6080' : '#94A3B8'};font-weight:600;">
          ${esc(s.signer_label)} ${s.status === 'signed' ? '✓ signed ' + fmtDate(s.signed_at) : s.status === 'awaiting' ? '… awaiting' : '· pending'}
          ${s.signer_name ? `<span style="color:#64748B;font-weight:400;">— ${esc(s.signer_name)}</span>` : ''}
        </li>`).join('')}
      </ol>
      ${isMyTurn ? `
        <h4 style="margin-top:14px;color:#0F172A;font-size:14px;">Sign now</h4>
        <input id="dw-name" placeholder="Your full name" style="width:100%;padding:9px;border:1px solid #E2E8F0;border-radius:8px;margin-bottom:8px;">
        <canvas id="dw-pad" width="500" height="150" style="border:2px dashed #CBD5E1;border-radius:8px;width:100%;background:#FAFCFE;cursor:crosshair;"></canvas>
        <button id="dw-clear" style="background:#F1F5F9;border:0;padding:6px 14px;border-radius:6px;cursor:pointer;font-size:12px;margin-top:4px;">Clear signature</button>
      ` : ''}
      <div style="margin-top:18px;text-align:right;">
        <button id="dw-close" style="background:#F1F5F9;border:0;padding:10px 18px;border-radius:8px;cursor:pointer;font-weight:600;">Close</button>
        ${isMyTurn ? '<button id="dw-sign" class="kt-btn kt-btn-primary" style="margin-left:8px;">Sign + advance</button>' : ''}
      </div>
    </div>`;
    document.body.appendChild(m);
    m.querySelector('#dw-close').onclick = () => m.remove();
    if (isMyTurn) {
      const c = m.querySelector('#dw-pad');
      const ctx = c.getContext('2d');
      ctx.strokeStyle = '#0F172A'; ctx.lineWidth = 2; ctx.lineCap = 'round';
      let drawing = false;
      const pos = (e) => { const r = c.getBoundingClientRect(); const t = e.touches ? e.touches[0] : e; return [(t.clientX - r.left) * c.width / r.width, (t.clientY - r.top) * c.height / r.height]; };
      c.addEventListener('mousedown', e => { e.preventDefault(); drawing = true; const [x, y] = pos(e); ctx.beginPath(); ctx.moveTo(x, y); });
      c.addEventListener('mousemove', e => { if (!drawing) return; const [x, y] = pos(e); ctx.lineTo(x, y); ctx.stroke(); });
      c.addEventListener('mouseup', () => drawing = false);
      c.addEventListener('mouseleave', () => drawing = false);
      c.addEventListener('touchstart', e => { e.preventDefault(); drawing = true; const [x, y] = pos(e); ctx.beginPath(); ctx.moveTo(x, y); });
      c.addEventListener('touchmove', e => { e.preventDefault(); if (!drawing) return; const [x, y] = pos(e); ctx.lineTo(x, y); ctx.stroke(); });
      c.addEventListener('touchend', () => drawing = false);
      m.querySelector('#dw-clear').onclick = () => ctx.clearRect(0, 0, c.width, c.height);
      m.querySelector('#dw-sign').onclick = async () => {
        if (!m.querySelector('#dw-name').value.trim()) { toast('Name required', 'warning'); return; }
        try {
          await Api.post(`/doc-workflows/${id}/sign`, {
            step_order: w.current_step,
            signature_data: c.toDataURL('image/png'),
          });
          toast('Signed', 'success');
          m.remove();
          renderDocWorkflows(document.querySelector('main'));
        } catch (e) { toast(e.message || 'Failed', 'error'); }
      };
    }
  }

  // ============================ AI Chatbot — floating widget + screen ============================
  let chatbotSession = null;
  function injectChatbotFab() {
    if (document.getElementById('kt-chatbot-fab')) return;
    const fab = document.createElement('button');
    fab.id = 'kt-chatbot-fab';
    fab.title = 'Ask a question';
    fab.style.cssText = 'position:fixed;bottom:24px;right:24px;width:60px;height:60px;border-radius:50%;background:linear-gradient(135deg,#1F6080,#3a86ad);color:#fff;border:0;cursor:pointer;font-size:28px;box-shadow:0 8px 24px rgba(31,96,128,.3);z-index:9999;transition:transform .15s;';
    fab.innerHTML = '💬';
    fab.onmouseover = () => fab.style.transform = 'scale(1.08)';
    fab.onmouseout = () => fab.style.transform = 'scale(1)';
    fab.onclick = openChatbot;
    document.body.appendChild(fab);
  }
  function openChatbot() {
    if (document.getElementById('kt-chatbot-panel')) {
      document.getElementById('kt-chatbot-panel').remove();
      return;
    }
    const panel = document.createElement('div');
    panel.id = 'kt-chatbot-panel';
    panel.style.cssText = 'position:fixed;bottom:96px;right:24px;width:400px;max-width:92vw;height:540px;background:#fff;border-radius:14px;box-shadow:0 20px 60px rgba(15,23,42,.25);z-index:9999;display:flex;flex-direction:column;overflow:hidden;animation:kt-pop-in .2s ease-out;';
    panel.innerHTML = `
      <div style="background:linear-gradient(135deg,#1F6080,#3a86ad);color:#fff;padding:16px 18px;">
        <div style="font-weight:700;font-size:16px;">💬 Ask the centre</div>
        <div style="font-size:12px;opacity:.85;">Answers from your centre's policies + handbook.</div>
      </div>
      <div id="kt-chatbot-feed" style="flex:1;padding:16px;overflow:auto;background:#F9FAFB;">
        <div style="background:#fff;padding:12px 14px;border-radius:14px 14px 14px 4px;max-width:80%;margin-bottom:10px;font-size:13.5px;color:#1F2937;box-shadow:0 1px 3px rgba(15,23,42,.06);">
          Hi! Ask me anything about pickup hours, vacation policy, fees, programs — I'll search the centre handbook and reply in plain English.
        </div>
      </div>
      <div style="padding:12px;border-top:1px solid #F1F5F9;display:flex;gap:8px;">
        <input id="kt-chatbot-input" placeholder="Ask a question…" style="flex:1;padding:10px 14px;border:1px solid #E2E8F0;border-radius:24px;font-size:14px;outline:none;">
        <button id="kt-chatbot-send" style="background:#1F6080;color:#fff;border:0;padding:10px 18px;border-radius:24px;cursor:pointer;font-weight:600;">Send</button>
      </div>
    `;
    document.body.appendChild(panel);
    const input = panel.querySelector('#kt-chatbot-input');
    const send = panel.querySelector('#kt-chatbot-send');
    const feed = panel.querySelector('#kt-chatbot-feed');
    input.focus();
    const post = async () => {
      const q = input.value.trim();
      if (!q) return;
      input.value = '';
      feed.innerHTML += `<div style="background:#1F6080;color:#fff;padding:10px 14px;border-radius:14px 14px 4px 14px;max-width:80%;margin-left:auto;margin-bottom:10px;font-size:13.5px;">${esc(q)}</div>`;
      const pending = document.createElement('div');
      pending.style.cssText = 'background:#fff;padding:12px 14px;border-radius:14px 14px 14px 4px;max-width:80%;margin-bottom:10px;font-size:13.5px;color:#94A3B8;box-shadow:0 1px 3px rgba(15,23,42,.06);';
      pending.textContent = 'Thinking…';
      feed.appendChild(pending);
      feed.scrollTop = feed.scrollHeight;
      try {
        const r = await Api.post('/chatbot/ask', { question: q, session_id: chatbotSession });
        chatbotSession = r.session_id;
        pending.style.color = '#1F2937';
        pending.innerHTML = esc(r.answer).replace(/\n/g, '<br>');
      } catch (e) { pending.style.color = '#B91C1C'; pending.textContent = 'Error: ' + (e.message || 'failed'); }
      feed.scrollTop = feed.scrollHeight;
    };
    send.onclick = post;
    input.addEventListener('keydown', e => { if (e.key === 'Enter') post(); });
  }

  // Inject chatbot FAB for all logged-in users
  setTimeout(() => {
    if (sessionStorage.getItem('kt_token')) injectChatbotFab();
  }, 1500);

  window.KT = KT;
  window.KT.V22p63 = {
    renderWellness, renderWellnessDigest, renderPaymentPlans,
    renderDocWorkflows, openChatbot,
  };
})(window);
