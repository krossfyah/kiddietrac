/* v22p58 — 9 features. Uses kt-design-v22p55.css conventions. */
(function (window) {
  'use strict';
  const KT = window.KT || {};
  const Api = KT.Api;
  if (!Api) { console.warn('v22p58: KT.Api unavailable'); return; }

  const apiBase = () => (KT.API_BASE) || 'https://api.kiddietrac.com/api/v1';
  const esc = (s) => String(s == null ? '' : s).replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  const fmtDate = (s) => { if (!s) return ''; const d = new Date(s); return d.toLocaleDateString(undefined, { year: 'numeric', month: 'short', day: 'numeric' }); };
  const fmtMoney = (n) => '$' + (Number(n) || 0).toFixed(2);

  async function downloadAuthed(path, filename) {
    const r = await fetch(apiBase() + path, { headers: { Authorization: 'Bearer ' + sessionStorage.getItem('kt_token') } });
    if (!r.ok) { alert('Download failed: ' + r.status); return; }
    const blob = await r.blob();
    const a = document.createElement('a'); a.href = URL.createObjectURL(blob); a.download = filename; a.click();
  }

  // ============================ Parent Wallet ============================
  async function renderWallet(main) {
    main.setAttribute('data-kt-pretty', '1');
    main.innerHTML = '<div style="padding:24px;">Loading wallet…</div>';
    const r = await Api.get('/parent/wallet').catch(() => ({ data: [], limit: 6 }));
    const methods = r.data || [];
    main.innerHTML = `<div style="padding:24px;max-width:1800px;margin:0 auto;">
      <div class="kt-page-hero">
        <h2>💳 Wallet</h2>
        <p>${methods.length} of ${r.limit || 6} saved payment methods. Set any one as the default for autopay.</p>
        <div class="kt-hero-actions">
          <button class="kt-btn kt-btn-ghost" id="w-add-card">+ Add credit/debit card</button>
          <button class="kt-btn kt-btn-ghost" id="w-add-ach">+ Add bank account (ACH)</button>
        </div>
      </div>
      <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(320px,1fr));gap:16px;">
        ${methods.map(m => `<div class="kt-card" style="position:relative;">
          ${m.is_default ? '<span class="kt-pill kt-pill-success" style="position:absolute;top:12px;right:12px;">DEFAULT</span>' : ''}
          <div style="font-size:36px;">${m.type === 'card' ? '💳' : '🏦'}</div>
          <div style="font-weight:700;color:#0F172A;font-size:16px;margin-top:8px;">${esc(m.brand ? m.brand.toUpperCase() : (m.bank_name || 'Bank account'))}</div>
          <div style="color:#475569;font-size:14px;">•••• ${esc(m.last4 || '****')}</div>
          ${m.exp_month ? `<div style="color:#94A3B8;font-size:12px;margin-top:4px;">Expires ${String(m.exp_month).padStart(2, '0')}/${m.exp_year}</div>` : ''}
          ${m.nickname ? `<div style="color:#475569;font-size:13px;margin-top:6px;font-style:italic;">"${esc(m.nickname)}"</div>` : ''}
          <div style="margin-top:14px;display:flex;gap:8px;flex-wrap:wrap;">
            ${!m.is_default ? `<button class="kt-btn kt-btn-primary" data-default="${m.id}" style="font-size:13px;">Set default</button>` : ''}
            <button class="kt-btn kt-btn-danger" data-rm="${m.id}" style="font-size:13px;">Remove</button>
          </div></div>`).join('') || '<div class="kt-card" style="grid-column:1/-1;text-align:center;padding:60px;color:#94A3B8;">No payment methods yet.</div>'}
      </div>
    </div>`;
    document.getElementById('w-add-card').onclick = () => startWalletAddFlow('card');
    document.getElementById('w-add-ach').onclick = () => startWalletAddFlow('acss_debit');
    main.querySelectorAll('button[data-default]').forEach(b => b.onclick = async () => {
      await Api.post(`/parent/wallet/${b.dataset.default}/default`, {});
      renderWallet(main);
    });
    main.querySelectorAll('button[data-rm]').forEach(b => b.onclick = async () => {
      if (!confirm('Remove this payment method?')) return;
      await Api.delete(`/parent/wallet/${b.dataset.rm}`);
      renderWallet(main);
    });
  }
  async function startWalletAddFlow(type) {
    if (!window.Stripe) {
      const s = document.createElement('script'); s.src = 'https://js.stripe.com/v3/';
      document.head.appendChild(s);
      await new Promise(r => s.onload = r);
    }
    const intent = await Api.post('/parent/wallet/setup-intent', { type });
    const stripe = window.Stripe(intent.publishable_key);
    if (type === 'card') {
      const m = document.createElement('div');
      m.style.cssText = 'position:fixed;inset:0;background:rgba(15,23,42,0.55);z-index:99999;display:flex;align-items:center;justify-content:center;';
      m.innerHTML = `<div style="background:#fff;padding:28px;border-radius:14px;max-width:440px;width:92%;">
        <h3 style="margin:0 0 16px;color:#0F172A;">Add card</h3>
        <div id="w-card-el" style="padding:14px;border:1px solid #E2E8F0;border-radius:8px;"></div>
        <label style="display:block;font-size:13px;font-weight:600;margin:14px 0 4px;">Nickname (optional)</label>
        <input id="w-nick" placeholder="My everyday card" style="width:100%;padding:9px;border:1px solid #E2E8F0;border-radius:8px;">
        <div id="w-err" style="color:#B91C1C;font-size:13px;margin-top:8px;"></div>
        <div style="margin-top:20px;text-align:right;">
          <button id="w-cancel" style="background:#F1F5F9;border:0;padding:10px 18px;border-radius:8px;cursor:pointer;font-weight:600;">Cancel</button>
          <button id="w-save" class="kt-btn kt-btn-primary" style="margin-left:8px;">Save card</button>
        </div></div>`;
      document.body.appendChild(m);
      const elements = stripe.elements();
      const card = elements.create('card');
      card.mount('#w-card-el');
      m.querySelector('#w-cancel').onclick = () => m.remove();
      m.querySelector('#w-save').onclick = async () => {
        const { setupIntent, error } = await stripe.confirmCardSetup(intent.client_secret, { payment_method: { card } });
        if (error) { m.querySelector('#w-err').textContent = error.message; return; }
        await Api.post('/parent/wallet', {
          payment_method: setupIntent.payment_method,
          nickname: m.querySelector('#w-nick').value,
        });
        m.remove();
        renderWallet(document.querySelector('main'));
      };
    } else {
      const { setupIntent, error } = await stripe.collectBankAccountForSetup({
        clientSecret: intent.client_secret,
        params: {
          payment_method_type: 'acss_debit',
          payment_method_data: {
            billing_details: {
              name: (JSON.parse(sessionStorage.getItem('kt_user') || '{}').first_name || '') + ' ' + (JSON.parse(sessionStorage.getItem('kt_user') || '{}').last_name || ''),
              email: JSON.parse(sessionStorage.getItem('kt_user') || '{}').email,
            },
          },
        },
      });
      if (error) { alert(error.message); return; }
      await Api.post('/parent/wallet', { payment_method: setupIntent.payment_method });
      renderWallet(document.querySelector('main'));
    }
  }

  // ============================ Ledger ============================
  async function renderLedger(main) {
    main.setAttribute('data-kt-pretty', '1');
    main.innerHTML = '<div style="padding:24px;">Loading ledger…</div>';
    const r = await Api.get('/parent/ledger').catch(() => null);
    if (!r) { main.innerHTML = '<div class="kt-card" style="margin:24px;text-align:center;color:#94A3B8;padding:40px;">No ledger available.</div>'; return; }
    main.innerHTML = `<div style="padding:24px;max-width:1800px;margin:0 auto;">
      <div class="kt-page-hero">
        <h2>📒 Account ledger</h2>
        <p>${esc(r.family.family_name)} · all invoices, payments, and refunds with a running balance.</p>
        <div class="kt-hero-actions"><button class="kt-btn kt-btn-ghost" id="ld-pdf">⤓ Download statement PDF</button></div>
      </div>
      <div class="kt-kpi-grid">
        <div class="kt-kpi ${r.current_balance > 0 ? 'kt-kpi-warning' : 'kt-kpi-success'}"><div class="kt-kpi-label">Current balance</div><div class="kt-kpi-value">${fmtMoney(r.current_balance)}</div></div>
        <div class="kt-kpi kt-kpi-info"><div class="kt-kpi-label">Total invoiced</div><div class="kt-kpi-value">${fmtMoney(r.total_invoiced)}</div></div>
        <div class="kt-kpi kt-kpi-success"><div class="kt-kpi-label">Total paid</div><div class="kt-kpi-value">${fmtMoney(r.total_paid)}</div></div>
        <div class="kt-kpi kt-kpi-danger"><div class="kt-kpi-label">Total refunded</div><div class="kt-kpi-value">${fmtMoney(r.total_refunded)}</div></div>
      </div>
      <div class="kt-card">
        <table>
          <thead><tr><th>Date</th><th>Type</th><th>Description</th><th style="text-align:right;">Debit</th><th style="text-align:right;">Credit</th><th style="text-align:right;">Balance</th></tr></thead>
          <tbody>${(r.data || []).map(row => `<tr>
            <td>${fmtDate(row.date)}</td>
            <td style="text-transform:capitalize;">${esc(row.type)}</td>
            <td>${esc(row.description)}</td>
            <td style="text-align:right;color:${row.debit > 0 ? '#B91C1C' : '#94A3B8'};">${row.debit ? fmtMoney(row.debit) : ''}</td>
            <td style="text-align:right;color:${row.credit > 0 ? '#047857' : '#94A3B8'};">${row.credit ? fmtMoney(row.credit) : ''}</td>
            <td style="text-align:right;font-weight:700;">${fmtMoney(row.running_balance)}</td>
          </tr>`).join('') || '<tr><td colspan="6" style="text-align:center;padding:40px;color:#94A3B8;">No transactions yet.</td></tr>'}</tbody>
        </table>
      </div>
    </div>`;
    document.getElementById('ld-pdf').onclick = () => downloadAuthed('/parent/ledger/pdf', 'statement-' + new Date().toISOString().slice(0, 10) + '.pdf');
  }

  // ============================ Refunds (director) ============================
  async function renderRefunds(main) {
    main.setAttribute('data-kt-pretty', '1');
    main.innerHTML = `<div style="padding:24px;max-width:1800px;margin:0 auto;">
      <div class="kt-page-hero">
        <h2>↩ Refunds</h2>
        <p>Process partial or full refunds against any payment. Stripe payments are refunded automatically; manual ones are recorded for audit.</p>
      </div>
      <div class="kt-card">
        <label style="font-size:13px;font-weight:600;">Payment ID to refund</label>
        <div style="display:flex;gap:8px;margin-top:6px;">
          <input id="rf-pid" type="number" placeholder="e.g. 1234" style="flex:1;padding:11px;border:1px solid #E2E8F0;border-radius:8px;">
          <button id="rf-load" class="kt-btn kt-btn-primary">Load</button>
        </div>
        <div id="rf-detail" style="margin-top:20px;"></div>
      </div>
    </div>`;
    document.getElementById('rf-load').onclick = async () => {
      const pid = +document.getElementById('rf-pid').value;
      if (!pid) return;
      const r = await Api.get(`/refunds/payment/${pid}`);
      document.getElementById('rf-detail').innerHTML = `
        <div class="kt-kpi-grid">
          <div class="kt-kpi"><div class="kt-kpi-label">Payment amount</div><div class="kt-kpi-value">${fmtMoney(r.payment.amount)}</div></div>
          <div class="kt-kpi kt-kpi-warning"><div class="kt-kpi-label">Already refunded</div><div class="kt-kpi-value">${fmtMoney(r.total_refunded)}</div></div>
          <div class="kt-kpi kt-kpi-success"><div class="kt-kpi-label">Refundable</div><div class="kt-kpi-value">${fmtMoney(r.remaining_refundable)}</div></div>
        </div>
        <h3 style="margin-top:18px;font-size:15px;color:#0F172A;">Refund history</h3>
        <table><thead><tr><th>Date</th><th>Amount</th><th>Reason</th><th>Status</th></tr></thead>
        <tbody>${(r.data || []).map(rf => `<tr>
          <td>${fmtDate(rf.refunded_at)}</td>
          <td>${fmtMoney(rf.amount)}</td>
          <td>${esc(rf.reason || '')}</td>
          <td><span class="kt-pill ${rf.status === 'succeeded' ? 'kt-pill-success' : rf.status === 'failed' ? 'kt-pill-danger' : 'kt-pill-warning'}">${esc(rf.status)}</span></td></tr>`).join('') || '<tr><td colspan="4" style="text-align:center;padding:24px;color:#94A3B8;">No prior refunds.</td></tr>'}</tbody></table>
        <h3 style="margin-top:18px;font-size:15px;color:#0F172A;">Issue new refund</h3>
        <label style="font-size:13px;font-weight:600;">Amount</label>
        <input id="rf-amt" type="number" step="0.01" max="${r.remaining_refundable}" min="0.01" style="width:100%;padding:9px;border:1px solid #E2E8F0;border-radius:8px;">
        <label style="font-size:13px;font-weight:600;margin-top:10px;display:block;">Reason</label>
        <select id="rf-reason" style="width:100%;padding:9px;border:1px solid #E2E8F0;border-radius:8px;">
          <option value="requested_by_customer">Customer requested</option>
          <option value="duplicate">Duplicate charge</option>
          <option value="overpayment">Overpayment</option>
          <option value="goodwill">Goodwill</option>
          <option value="vacation_credit">Vacation credit</option>
        </select>
        <label style="font-size:13px;font-weight:600;margin-top:10px;display:block;">Notes</label>
        <textarea id="rf-notes" rows="3" style="width:100%;padding:9px;border:1px solid #E2E8F0;border-radius:8px;font-family:inherit;"></textarea>
        <button id="rf-go" class="kt-btn kt-btn-danger" style="margin-top:14px;">Issue refund</button>
        <div id="rf-out" style="margin-top:10px;"></div>
      `;
      document.getElementById('rf-go').onclick = async () => {
        const amt = parseFloat(document.getElementById('rf-amt').value);
        if (!amt) return alert('Amount required');
        try {
          const out = await Api.post('/refunds', {
            payment_id: pid, amount: amt,
            reason: document.getElementById('rf-reason').value,
            notes: document.getElementById('rf-notes').value,
          });
          document.getElementById('rf-out').innerHTML = `<div style="background:#DCFCE7;color:#166534;padding:14px;border-radius:8px;">✓ Refund ${out.status} (${out.stripe_refund_id || 'manual'})</div>`;
          setTimeout(() => document.getElementById('rf-load').click(), 1500);
        } catch (e) { document.getElementById('rf-out').innerHTML = `<div style="background:#FEE2E2;color:#991B1B;padding:14px;border-radius:8px;">${esc(e.message || 'Failed')}</div>`; }
      };
    };
  }

  // ============================ Custom report builder ============================
  async function renderReports(main) {
    main.setAttribute('data-kt-pretty', '1');
    main.innerHTML = '<div style="padding:24px;">Loading reports…</div>';
    const r = await Api.get('/reports').catch(() => ({ data: [], favorites: [], recent: [], report_types: {} }));
    main.innerHTML = `<div style="padding:24px;max-width:1800px;margin:0 auto;">
      <div class="kt-page-hero">
        <h2>📋 Reports</h2>
        <p>Build a report with any combination of columns + filters. Save it. Re-run any time.</p>
        <div class="kt-hero-actions"><button class="kt-btn kt-btn-ghost" id="rep-new">+ New report</button></div>
      </div>
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:20px;margin-bottom:20px;">
        <div class="kt-card">
          <div class="kt-card-header"><h3 class="kt-card-title">⭐ Favourites</h3></div>
          ${(r.favorites || []).map(f => `<div style="padding:10px 0;border-bottom:1px solid #F1F5F9;display:flex;justify-content:space-between;align-items:center;">
            <div><strong>${esc(f.name)}</strong><div style="color:#94A3B8;font-size:12px;">${esc(r.report_types[f.report_type] || f.report_type)}</div></div>
            <button class="kt-btn kt-btn-primary" data-run="${f.id}" style="font-size:12px;padding:6px 12px;">Run</button>
          </div>`).join('') || '<div style="color:#94A3B8;">No favourites yet.</div>'}
        </div>
        <div class="kt-card">
          <div class="kt-card-header"><h3 class="kt-card-title">🕘 Recently run</h3></div>
          ${(r.recent || []).map(f => `<div style="padding:10px 0;border-bottom:1px solid #F1F5F9;display:flex;justify-content:space-between;align-items:center;">
            <div><strong>${esc(f.name)}</strong><div style="color:#94A3B8;font-size:12px;">last run ${fmtDate(f.last_run_at)}</div></div>
            <button class="kt-btn kt-btn-primary" data-run="${f.id}" style="font-size:12px;padding:6px 12px;">Run</button>
          </div>`).join('') || '<div style="color:#94A3B8;">No reports run yet.</div>'}
        </div>
      </div>
      <div class="kt-card">
        <div class="kt-card-header"><h3 class="kt-card-title">All saved reports</h3></div>
        <table>
          <thead><tr><th>Name</th><th>Type</th><th>Runs</th><th>Last run</th><th></th></tr></thead>
          <tbody>${(r.data || []).map(f => `<tr>
            <td><strong>${esc(f.name)}</strong>${f.is_favorite ? ' ⭐' : ''}</td>
            <td><span class="kt-pill kt-pill-info">${esc(r.report_types[f.report_type] || f.report_type)}</span></td>
            <td>${f.run_count}</td>
            <td>${f.last_run_at ? fmtDate(f.last_run_at) : '—'}</td>
            <td>
              <button class="kt-btn kt-btn-primary" data-run="${f.id}" style="font-size:12px;padding:6px 12px;">Run</button>
              <button class="kt-btn kt-btn-danger" data-del="${f.id}" style="font-size:12px;padding:6px 12px;margin-left:4px;">Delete</button>
            </td></tr>`).join('') || '<tr><td colspan="5" style="text-align:center;padding:30px;color:#94A3B8;">No reports yet.</td></tr>'}</tbody>
        </table>
      </div>
    </div>`;
    document.getElementById('rep-new').onclick = () => openReportBuilder(r.report_types);
    main.querySelectorAll('button[data-run]').forEach(b => b.onclick = () => runReport(+b.dataset.run, r.report_types));
    main.querySelectorAll('button[data-del]').forEach(b => b.onclick = async () => {
      if (!confirm('Delete this saved report?')) return;
      await Api.delete(`/reports/${b.dataset.del}`);
      renderReports(main);
    });
  }
  function openReportBuilder(types) {
    const m = document.createElement('div');
    m.style.cssText = 'position:fixed;inset:0;background:rgba(15,23,42,0.55);z-index:99999;display:flex;align-items:center;justify-content:center;';
    m.innerHTML = `<div style="background:#fff;padding:28px;border-radius:14px;max-width:560px;width:92%;">
      <h3 style="margin:0 0 16px;color:#0F172A;">New report</h3>
      <label style="display:block;font-size:13px;font-weight:600;margin-bottom:4px;">Name</label>
      <input id="rb-name" style="width:100%;padding:9px;border:1px solid #E2E8F0;border-radius:8px;">
      <label style="display:block;font-size:13px;font-weight:600;margin:14px 0 4px;">Data source</label>
      <select id="rb-type" style="width:100%;padding:9px;border:1px solid #E2E8F0;border-radius:8px;">
        ${Object.entries(types || {}).map(([k, v]) => `<option value="${k}">${esc(v)}</option>`).join('')}
      </select>
      <label style="display:block;font-size:13px;font-weight:600;margin:14px 0 4px;">Sort by (column name)</label>
      <input id="rb-sort" placeholder="e.g. created_at, last_name" style="width:100%;padding:9px;border:1px solid #E2E8F0;border-radius:8px;">
      <label style="display:flex;align-items:center;gap:8px;margin-top:14px;font-size:13.5px;font-weight:600;">
        <input id="rb-share" type="checkbox"> Share with my agency
      </label>
      <label style="display:flex;align-items:center;gap:8px;font-size:13.5px;font-weight:600;">
        <input id="rb-fav" type="checkbox"> Add to favourites
      </label>
      <div style="margin-top:20px;text-align:right;">
        <button id="rb-cancel" style="background:#F1F5F9;border:0;padding:10px 18px;border-radius:8px;cursor:pointer;font-weight:600;">Cancel</button>
        <button id="rb-save" class="kt-btn kt-btn-primary" style="margin-left:8px;">Save report</button>
      </div></div>`;
    document.body.appendChild(m);
    m.querySelector('#rb-cancel').onclick = () => m.remove();
    m.querySelector('#rb-save').onclick = async () => {
      const r = await Api.post('/reports', {
        report_type: m.querySelector('#rb-type').value,
        name: m.querySelector('#rb-name').value,
        sort_column: m.querySelector('#rb-sort').value || null,
        is_shared: m.querySelector('#rb-share').checked,
      });
      if (m.querySelector('#rb-fav').checked) {
        await Api.patch(`/reports/${r.id}`, { is_favorite: true });
      }
      m.remove();
      renderReports(document.querySelector('main'));
    };
  }
  async function runReport(id, types) {
    const r = await Api.get(`/reports/${id}/run`);
    const m = document.createElement('div');
    m.style.cssText = 'position:fixed;inset:0;background:rgba(15,23,42,0.55);z-index:99999;display:flex;align-items:center;justify-content:center;padding:24px;';
    const rows = r.rows || [];
    const cols = rows.length ? Object.keys(rows[0]) : [];
    m.innerHTML = `<div style="background:#fff;padding:28px;border-radius:14px;max-width:1400px;width:96%;max-height:90vh;overflow:auto;">
      <h3 style="margin:0 0 8px;color:#0F172A;">${esc(r.name)}</h3>
      <div style="color:#475569;font-size:13px;margin-bottom:14px;">${esc(types[r.report_type] || r.report_type)} · ${r.count} rows</div>
      <table style="width:100%;border-collapse:separate;border-spacing:0;">
        <thead><tr>${cols.map(c => `<th style="background:#F1F5F9;text-align:left;padding:10px 12px;font-size:11px;color:#475569;font-weight:700;letter-spacing:0.05em;text-transform:uppercase;border-bottom:2px solid #E2E8F0;">${esc(c)}</th>`).join('')}</tr></thead>
        <tbody>${rows.slice(0, 200).map(row => `<tr>${cols.map(c => `<td style="padding:8px 12px;border-bottom:1px solid #F1F5F9;font-size:13px;">${esc(row[c])}</td>`).join('')}</tr>`).join('')}</tbody>
      </table>
      <div style="text-align:right;margin-top:16px;"><button id="rep-close" class="kt-btn kt-btn-primary">Close</button></div>
    </div>`;
    document.body.appendChild(m);
    m.querySelector('#rep-close').onclick = () => m.remove();
  }

  // ============================ Video feed ============================
  async function renderVideoFeed(main) {
    main.setAttribute('data-kt-pretty', '1');
    main.innerHTML = '<div style="padding:24px;">Loading videos…</div>';
    const r = await Api.get('/videos/feed').catch(() => ({ data: [] }));
    main.innerHTML = `<div style="padding:24px;max-width:1800px;margin:0 auto;">
      <div class="kt-page-hero">
        <h2>🎬 Video feed</h2>
        <p>${(r.data || []).length} video(s). React with a heart / smile / clap.</p>
        <div class="kt-hero-actions"><button class="kt-btn kt-btn-ghost" id="v-upload">+ Upload video</button></div>
      </div>
      <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(360px,1fr));gap:18px;">
        ${(r.data || []).map(v => {
          const reacts = v.reactions || {};
          return `<div class="kt-card" style="padding:0;overflow:hidden;">
            <video src="${esc(v.url)}" controls style="width:100%;display:block;background:#000;max-height:300px;"></video>
            <div style="padding:14px 18px;">
              <div style="font-size:12.5px;color:#475569;">${fmtDate(v.taken_at)} · ${esc(v.uploader_name || '')}</div>
              <div style="font-size:13.5px;color:#0F172A;margin-top:6px;">${esc(v.caption || '')}</div>
              <div style="margin-top:10px;display:flex;gap:6px;align-items:center;">
                ${['heart', 'smile', 'wow', 'celebrate', 'clap'].map(rx => {
                  const emoji = { heart: '❤️', smile: '😊', wow: '😮', celebrate: '🎉', clap: '👏' }[rx];
                  return `<button data-vid="${v.id}" data-rx="${rx}" style="background:#F1F5F9;border:0;padding:6px 10px;border-radius:14px;cursor:pointer;font-size:15px;">${emoji} ${reacts[rx] || 0}</button>`;
                }).join('')}
              </div></div></div>`;
        }).join('') || '<div class="kt-card" style="grid-column:1/-1;text-align:center;padding:60px;color:#94A3B8;">No videos yet.</div>'}
      </div>
    </div>`;
    document.getElementById('v-upload').onclick = () => openVideoUpload();
    main.querySelectorAll('button[data-vid][data-rx]').forEach(b => b.onclick = async () => {
      await Api.post('/reactions', { target_type: 'video', target_id: +b.dataset.vid, reaction: b.dataset.rx });
      renderVideoFeed(main);
    });
  }
  function openVideoUpload() {
    const m = document.createElement('div');
    m.style.cssText = 'position:fixed;inset:0;background:rgba(15,23,42,0.55);z-index:99999;display:flex;align-items:center;justify-content:center;';
    m.innerHTML = `<div style="background:#fff;padding:28px;border-radius:14px;max-width:480px;width:92%;">
      <h3 style="margin:0 0 16px;color:#0F172A;">Share a video</h3>
      <input id="v-file" type="file" accept="video/mp4,video/quicktime,video/webm" style="width:100%;padding:9px;border:2px dashed #CBD5E1;border-radius:8px;background:#F8FAFC;">
      <label style="display:block;font-size:13px;font-weight:600;margin:14px 0 4px;">Caption</label>
      <textarea id="v-cap" rows="3" style="width:100%;padding:9px;border:1px solid #E2E8F0;border-radius:8px;font-family:inherit;"></textarea>
      <div style="margin-top:20px;text-align:right;">
        <button id="v-cancel" style="background:#F1F5F9;border:0;padding:10px 18px;border-radius:8px;cursor:pointer;font-weight:600;">Cancel</button>
        <button id="v-go" class="kt-btn kt-btn-primary" style="margin-left:8px;">Upload</button>
      </div></div>`;
    document.body.appendChild(m);
    m.querySelector('#v-cancel').onclick = () => m.remove();
    m.querySelector('#v-go').onclick = async () => {
      const f = m.querySelector('#v-file').files[0];
      if (!f) { alert('Pick a video'); return; }
      const fd = new FormData(); fd.append('video', f); fd.append('caption', m.querySelector('#v-cap').value);
      try {
        const r = await fetch(apiBase() + '/videos', {
          method: 'POST', headers: { Authorization: 'Bearer ' + sessionStorage.getItem('kt_token') }, body: fd,
        });
        if (!r.ok) {
          let detail = 'Upload failed (' + r.status + ')';
          try {
            const j = await r.json();
            if (j.message) detail = j.message;
            if (j.errors) detail = Object.values(j.errors).flat().join('. ');
            if (r.status === 413) detail = 'File too large. Max video size is 50MB.';
          } catch (e) {}
          throw new Error(detail);
        }
        m.remove();
        if (window.KT && window.KT.toast) window.KT.toast('Video uploaded', 'success');
        renderVideoFeed(document.querySelector('main'));
      } catch (e) { if (window.KT && window.KT.toast) window.KT.toast(e.message || 'Upload failed', 'error', 7000); else alert(e.message); }
    };
  }

  // ============================ Immunization Due At Age ============================
  async function renderImmunSchedule(main) {
    main.setAttribute('data-kt-pretty', '1');
    main.innerHTML = '<div style="padding:24px;">Loading schedule…</div>';
    const [sched, due] = await Promise.all([
      Api.get('/immunization/schedule'),
      Api.get('/immunization/due-report').catch(() => ({ data: [] })),
    ]);
    main.innerHTML = `<div style="padding:24px;max-width:1800px;margin:0 auto;">
      <div class="kt-page-hero">
        <h2>💉 Immunization "Due At Age"</h2>
        <p>Age-based schedule. Status auto-computed per child from their DOB + administered records.</p>
      </div>
      <div class="kt-kpi-grid">
        <div class="kt-kpi kt-kpi-danger"><div class="kt-kpi-label">Children with overdue doses</div><div class="kt-kpi-value">${(due.data || []).filter(c => c.overdue > 0).length}</div></div>
        <div class="kt-kpi kt-kpi-warning"><div class="kt-kpi-label">Due in next 2 months</div><div class="kt-kpi-value">${(due.data || []).filter(c => c.due_soon > 0).length}</div></div>
        <div class="kt-kpi kt-kpi-info"><div class="kt-kpi-label">Schedule items</div><div class="kt-kpi-value">${(sched.data || []).length}</div></div>
      </div>
      <div class="kt-card">
        <div class="kt-card-header"><h3 class="kt-card-title">Children needing attention</h3></div>
        <table><thead><tr><th>Child</th><th>Family</th><th>Centre</th><th>Overdue</th><th>Due soon</th></tr></thead>
        <tbody>${(due.data || []).filter(c => c.overdue + c.due_soon > 0).map(c => `<tr>
          <td><strong>${esc(c.child_name)}</strong></td>
          <td>${esc(c.family_name)}</td>
          <td>${esc(c.centre_name)}</td>
          <td>${c.overdue > 0 ? `<span class="kt-pill kt-pill-danger">${c.overdue}</span>` : '—'}</td>
          <td>${c.due_soon > 0 ? `<span class="kt-pill kt-pill-warning">${c.due_soon}</span>` : '—'}</td>
        </tr>`).join('') || '<tr><td colspan="5" style="text-align:center;padding:30px;color:#94A3B8;">All children up to date.</td></tr>'}</tbody></table>
      </div>
      <div class="kt-card">
        <div class="kt-card-header"><h3 class="kt-card-title">Schedule defaults</h3></div>
        <table><thead><tr><th>Vaccine</th><th>Dose</th><th>Due at age</th><th>Required</th></tr></thead>
        <tbody>${(sched.data || []).map(s => `<tr>
          <td><strong>${esc(s.vaccine)}</strong></td>
          <td>${esc(s.dose_label)}</td>
          <td>${s.due_at_age_months} months</td>
          <td>${s.is_required ? '✓' : '—'}</td>
        </tr>`).join('')}</tbody></table>
      </div>
    </div>`;
  }

  // ============================ CACFP =================================
  async function renderCacfp(main) {
    main.setAttribute('data-kt-pretty', '1');
    main.innerHTML = '<div style="padding:24px;">Loading CACFP…</div>';
    const centres = (await Api.get('/admin/centres').catch(() => ({ data: [] }))).data || [];
    if (!centres.length) { main.innerHTML = '<div class="kt-card" style="margin:24px;text-align:center;color:#94A3B8;padding:40px;">No centres.</div>'; return; }
    const centreId = centres[0].id;
    const date = new Date().toISOString().slice(0, 10);
    const month = date.slice(0, 7);
    const [roster, report] = await Promise.all([
      Api.get(`/cacfp/roster?centre_id=${centreId}&date=${date}`),
      Api.get(`/cacfp/monthly?month=${month}`),
    ]);
    main.innerHTML = `<div style="padding:24px;max-width:1800px;margin:0 auto;">
      <div class="kt-page-hero">
        <h2>🍽 CACFP meal tracking</h2>
        <p>${esc(centres[0].name)} · ${fmtDate(date)} · ${roster.data.length} enrolled children. Tap to record served meals — totals roll into the monthly reimbursement claim.</p>
      </div>
      <div class="kt-kpi-grid">
        <div class="kt-kpi kt-kpi-success"><div class="kt-kpi-label">Free</div><div class="kt-kpi-value">${report.totals.free || 0}</div></div>
        <div class="kt-kpi kt-kpi-warning"><div class="kt-kpi-label">Reduced</div><div class="kt-kpi-value">${report.totals.reduced || 0}</div></div>
        <div class="kt-kpi kt-kpi-info"><div class="kt-kpi-label">Paid</div><div class="kt-kpi-value">${report.totals.paid || 0}</div></div>
        <div class="kt-kpi"><div class="kt-kpi-label">Total meals this month</div><div class="kt-kpi-value">${report.totals.grand_total}</div></div>
      </div>
      <div class="kt-card">
        <div class="kt-card-header"><h3 class="kt-card-title">Today's roster — ${fmtDate(date)}</h3></div>
        <table>
          <thead><tr><th>Child</th><th>Tier</th><th>Breakfast</th><th>AM snack</th><th>Lunch</th><th>PM snack</th><th>Dinner</th></tr></thead>
          <tbody>${(roster.data || []).map(r => {
            const cell = (mt) => `<td><input type="checkbox" data-meal="${mt}" data-child="${r.child_id}" ${r[mt] ? 'checked' : ''} style="width:22px;height:22px;cursor:pointer;"></td>`;
            return `<tr>
              <td><strong>${esc(r.child_name)}</strong></td>
              <td>${r.cacfp_tier ? `<span class="kt-pill kt-pill-info">${esc(r.cacfp_tier)}</span>` : '<span style="color:#94A3B8;">unset</span>'}</td>
              ${cell('breakfast')}${cell('morning_snack')}${cell('lunch')}${cell('afternoon_snack')}${cell('dinner')}
            </tr>`;
          }).join('') || '<tr><td colspan="7" style="text-align:center;padding:30px;color:#94A3B8;">No children enrolled.</td></tr>'}</tbody>
        </table>
      </div>
    </div>`;
    main.querySelectorAll('input[type="checkbox"][data-meal]').forEach(cb => cb.onchange = async () => {
      await Api.post('/cacfp/meal', {
        centre_id: centreId,
        child_id: +cb.dataset.child,
        meal_date: date,
        meal_type: cb.dataset.meal,
        served: cb.checked,
      });
    });
  }

  // ============================ Billing schedule =================================
  async function renderBillingSchedule(main) {
    main.setAttribute('data-kt-pretty', '1');
    main.innerHTML = `<div style="padding:24px;max-width:1800px;margin:0 auto;">
      <div class="kt-page-hero">
        <h2>📅 Billing schedule</h2>
        <p>Pick weekly, biweekly, or monthly billing per family.</p>
      </div>
      <div class="kt-card">
        <label style="font-size:13px;font-weight:600;">Family ID</label>
        <input id="bs-fid" type="number" style="width:100%;padding:11px;border:1px solid #E2E8F0;border-radius:8px;margin-top:6px;">
        <button id="bs-load" class="kt-btn kt-btn-primary" style="margin-top:10px;">Load</button>
        <div id="bs-detail" style="margin-top:20px;"></div>
      </div>
    </div>`;
    document.getElementById('bs-load').onclick = async () => {
      const fid = +document.getElementById('bs-fid').value;
      const r = await Api.get(`/billing/schedule/${fid}`);
      const s = r.data || { frequency: 'monthly' };
      document.getElementById('bs-detail').innerHTML = `
        <label style="font-size:13px;font-weight:600;display:block;">Frequency</label>
        <select id="bs-freq" style="width:100%;padding:9px;border:1px solid #E2E8F0;border-radius:8px;margin-top:6px;">
          <option value="weekly" ${s.frequency === 'weekly' ? 'selected' : ''}>Weekly</option>
          <option value="biweekly" ${s.frequency === 'biweekly' ? 'selected' : ''}>Biweekly</option>
          <option value="monthly" ${s.frequency === 'monthly' ? 'selected' : ''}>Monthly</option>
        </select>
        <label style="font-size:13px;font-weight:600;display:block;margin-top:14px;">Day of week (0=Sun … 6=Sat)</label>
        <input id="bs-dow" type="number" min="0" max="6" value="${s.day_of_week || 1}" style="width:100%;padding:9px;border:1px solid #E2E8F0;border-radius:8px;margin-top:6px;">
        <label style="font-size:13px;font-weight:600;display:block;margin-top:14px;">Day of month (1-31)</label>
        <input id="bs-dom" type="number" min="1" max="31" value="${s.day_of_month || 1}" style="width:100%;padding:9px;border:1px solid #E2E8F0;border-radius:8px;margin-top:6px;">
        ${s.next_charge_at ? `<div style="margin-top:14px;color:#475569;font-size:13px;">Next charge: <strong>${fmtDate(s.next_charge_at)}</strong></div>` : ''}
        <button id="bs-save" class="kt-btn kt-btn-primary" style="margin-top:14px;">Save schedule</button>
        ${s.frequency ? `<button id="bs-off" class="kt-btn kt-btn-danger" style="margin-top:14px;margin-left:8px;">Disable</button>` : ''}
      `;
      document.getElementById('bs-save').onclick = async () => {
        await Api.post('/billing/schedule', {
          family_id: fid,
          frequency: document.getElementById('bs-freq').value,
          day_of_week: +document.getElementById('bs-dow').value,
          day_of_month: +document.getElementById('bs-dom').value,
        });
        document.getElementById('bs-load').click();
      };
      const off = document.getElementById('bs-off');
      if (off) off.onclick = async () => {
        await Api.delete(`/billing/schedule/${fid}`);
        document.getElementById('bs-load').click();
      };
    };
  }

  window.KT = KT;
  window.KT.V22p58 = {
    renderWallet, renderLedger, renderRefunds, renderReports,
    renderVideoFeed, renderImmunSchedule, renderCacfp, renderBillingSchedule,
  };
})(window);
