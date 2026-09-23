/* v22p58 — 9 features. Uses kt-design-v22p55.css conventions. */
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
  const fmtMoney = (n) => '$' + (Number(n) || 0).toFixed(2);

  async function downloadAuthed(path, filename) {
    const r = await fetch(apiBase() + path, { headers: { Authorization: 'Bearer ' + sessionStorage.getItem('kt_token') } });
    if (!r.ok) { (window.KT && window.KT.toast) ? KT.toast('Download failed: ' + r.status, /save|sent|added|created|approved|deleted|removed|done|charged/i.test('Download failed: ' + r.status) ? 'success' : 'info') : alert('Download failed: ' + r.status); return; }
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
      <div data-kt-list="1" style="display:grid;grid-template-columns:repeat(auto-fill,minmax(320px,1fr));gap:16px;">
        ${methods.map(m => `<div class="kt-card" style="position:relative;">
          ${m.is_default ? '<span class="kt-pill kt-pill-success" style="position:absolute;top:12px;right:12px;">DEFAULT</span>' : ''}
          <div style="font-size:36px;">${m.type === 'card' ? '💳' : '🏦'}</div>
          <div style="font-weight:700;color:#0F172A;font-size:16px;margin-top:8px;">${esc(m.brand ? m.brand.toUpperCase() : (m.bank_name || 'Bank account'))}</div>
          <div style="color:#475569;font-size:14px;">•••• ${esc(m.last4 || '****')}</div>
          ${m.exp_month ? `<div style="color:#64748B;font-size:12px;margin-top:4px;">Expires ${String(m.exp_month).padStart(2, '0')}/${m.exp_year}</div>` : ''}
          ${m.nickname ? `<div style="color:#475569;font-size:13px;margin-top:6px;font-style:italic;">"${esc(m.nickname)}"</div>` : ''}
          <div style="margin-top:14px;display:flex;gap:8px;flex-wrap:wrap;">
            ${!m.is_default ? `<button class="kt-btn kt-btn-primary" data-default="${m.id}" style="font-size:13px;">Set default</button>` : ''}
            <button class="kt-btn kt-btn-danger" data-rm="${m.id}" style="font-size:13px;">Remove</button>
          </div></div>`).join('') || '<div class="kt-card" style="grid-column:1/-1;text-align:center;padding:60px;color:#64748B;">No payment methods yet.</div>'}
      </div>
    </div>`;
    // Scope to `main`, don't reach into the document. renderWallet awaits
    // /parent/wallet first, so a parent who navigates away mid-fetch leaves `main`
    // detached — document.getElementById then returns null and the old code threw
    // "Cannot set properties of null (setting 'onclick')", which surfaced to the
    // parent as the "KiddieTrac had a problem" crash prompt.
    const addCard = main.querySelector('#w-add-card');
    const addAch = main.querySelector('#w-add-ach');
    if (addCard) addCard.onclick = () => startWalletAddFlow('card');
    if (addAch) addAch.onclick = () => startWalletAddFlow('acss_debit');
    main.querySelectorAll('button[data-default]').forEach(b => b.onclick = async () => {
      await Api.post(`/parent/wallet/${b.dataset.default}/default`, {});
      renderWallet(main);
    });
    main.querySelectorAll('button[data-rm]').forEach(b => b.onclick = async () => {
      if (!await KT.confirm('Remove this payment method?')) return;
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
      if (error) { (window.KT && window.KT.toast) ? KT.toast(error.message, /save|sent|added|created|approved|deleted|removed|done|charged/i.test(error.message) ? 'success' : 'info') : alert(error.message); return; }
      await Api.post('/parent/wallet', { payment_method: setupIntent.payment_method });
      renderWallet(document.querySelector('main'));
    }
  }

  // ============================ Ledger ============================
  function ldIsMobile() { return window.innerWidth <= 700 || document.documentElement.classList.contains('kt-native'); }
  function ldTypeMeta(t) {
    return t === 'payment' ? { icon: '💳', bg: '#ECFDF5', fg: '#047857' }
      : t === 'refund' ? { icon: '↩️', bg: '#FEF3C7', fg: '#B45309' }
      : { icon: '🧾', bg: '#EFF6FF', fg: '#1D4ED8' };
  }
  function ldTxnCard(row) {
    var m = ldTypeMeta(row.type);
    var isInv = row.type === 'invoice' && row.invoice_id;
    var amt = row.debit > 0 ? '−' + fmtMoney(row.debit) : (row.credit > 0 ? '+' + fmtMoney(row.credit) : fmtMoney(0));
    var amtColor = row.debit > 0 ? '#B91C1C' : (row.credit > 0 ? '#047857' : '#64748B');
    var late = row.days_late > 0 ? ' <span style="display:inline-block;margin-left:6px;padding:1px 8px;border-radius:10px;background:#FEE2E2;color:#B91C1C;font-size:10.5px;font-weight:800;white-space:nowrap;">' + row.days_late + ' day' + (row.days_late === 1 ? '' : 's') + ' late</span>' : '';
    // #34 — invoice rows carry the three per-invoice actions as a PURE last-child
    // bar so kt-row-actions collapses them into the standard ⋮ kebab.
    var actionBar = isInv ? '<div style="flex:0 0 auto;display:flex;align-items:center;">' + ldActionButtons(row.invoice_id) + '</div>' : '';
    return '<div class="kt-ldx" style="display:flex;align-items:center;gap:10px;background:#fff;border:1px solid #EDF1F6;border-radius:14px;padding:12px 10px 12px 13px;margin-bottom:9px;box-shadow:0 1px 4px rgba(15,23,42,.05);">'
      + '<span style="flex:0 0 auto;width:40px;height:40px;border-radius:50%;background:' + m.bg + ';color:' + m.fg + ';display:flex;align-items:center;justify-content:center;font-size:19px;">' + m.icon + '</span>'
      + '<div style="flex:1;min-width:0;">'
      +   '<div style="font-weight:700;font-size:14px;color:#0F172A;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">' + esc(row.description) + '</div>'
      +   '<div style="font-size:11.5px;color:#94A3B8;margin-top:2px;">' + esc(fmtDate(row.date)) + late + '</div>'
      + '</div>'
      + '<div style="flex:0 0 auto;text-align:right;min-width:92px;">'
      +   '<div style="font-weight:800;font-size:14.5px;color:' + amtColor + ';white-space:nowrap;font-variant-numeric:tabular-nums;">' + amt + '</div>'
      +   '<div style="font-size:11px;color:#94A3B8;margin-top:2px;white-space:nowrap;font-variant-numeric:tabular-nums;">bal ' + fmtMoney(row.running_balance) + '</div>'
      + '</div>'
      + actionBar
      + '</div>';
  }

  // #34 — the three per-invoice actions, rendered as a PURE bar of action
  // controls in the row's last cell. The global kt-row-actions.js engine then
  // collapses them into the app's standard ⋮ kebab (View / Download / Email) on
  // desktop — identical to every other list — and shows them inline on phones.
  function ldActionButtons(invId) {
    var b = 'class="kt-act-icon" style="display:inline-flex;align-items:center;justify-content:center;width:30px;height:30px;margin-left:4px;border:1px solid #E2E8F0;border-radius:8px;background:#fff;color:#475569;cursor:pointer;font-size:15px;line-height:1;padding:0;vertical-align:middle;"';
    return '<button type="button" ' + b + ' data-inv-view="' + invId + '" title="View invoice" aria-label="View invoice">👁️</button>'
      + '<button type="button" ' + b + ' data-inv-dl="' + invId + '" title="Download PDF" aria-label="Download PDF">⬇️</button>'
      + '<button type="button" ' + b + ' data-inv-email="' + invId + '" title="Email to me" aria-label="Email invoice to me">✉️</button>';
  }

  async function ldEmailInvoice(invId) {
    try {
      var r = await Api.post('/parent/invoices/' + invId + '/email', {});
      if (window.KT && KT.toast) KT.toast('✉️', 'Invoice emailed', 'Sent to ' + (r.email || 'your email') + '.', '#16A34A');
    } catch (e) {
      if (window.KT && KT.toast) KT.toast('⚠️', 'Could not email invoice', (e && e.message) || 'Please try again.', '#DC2626');
    }
  }
  async function renderLedger(main, famId) {
    /* The shell invokes every registered screen as fn(main, ctx), so on a normal render
       this second argument is the shell's context object, not a family id. Only a plain
       string or number here came from our own tab handler; anything else is the shell
       and means "no family chosen". */
    if (typeof famId !== 'string' && typeof famId !== 'number') { famId = null; }
    main.setAttribute('data-kt-pretty', '1');
    main.innerHTML = '<div style="padding:24px;">Loading ledger…</div>';
    /* A guardian can belong to more than one family — two on this platform do — and the
       API used to answer with whichever row came back first, so the other family's
       invoices were unreachable and nothing said a second account existed. */
    const r = await Api.get('/parent/ledger' + (famId ? ('?family_id=' + encodeURIComponent(famId)) : ''))
      .catch(() => null);
    if (!r) { main.innerHTML = '<div class="kt-card" style="margin:24px;text-align:center;color:#64748B;padding:40px;">No ledger available.</div>'; return; }
    const rows = r.data || [];
    const bal = Number(r.current_balance || 0);
    const owed = bal > 0.005, credit = bal < -0.005;
    const balGrad = owed ? '#E11D48,#B91C1C' : (credit ? '#7C3AED,#5B21B6' : '#0FA3B1,#0E7C90');
    const balLabel = owed ? 'Balance due' : (credit ? 'Account credit' : 'Account balance');
    const balSub = owed
      ? (r.days_overdue > 0 ? (r.days_overdue + ' day' + (r.days_overdue === 1 ? '' : 's') + ' overdue') : 'Please settle when you can')
      : (credit ? 'A credit is on your account' : 'You\'re all settled — thank you! 🎉');

    /* Only drawn when there is something to switch between; one family sees no tabs at
       all. Each family keeps its OWN balance, statement and PDF — they are separate
       billing accounts and merging them would produce a statement matching neither. */
    const fams = Array.isArray(r.families) ? r.families : [];
    const activeFam = r.family_id || (r.family && r.family.id);
    const famTabs = fams.length > 1
      ? '<div style="display:flex;gap:8px;flex-wrap:wrap;margin:0 2px 12px;">'
        + fams.map((f) => {
            const on = String(f.id) === String(activeFam);
            return `<button type="button" data-fam="${esc(String(f.id))}" style="height:34px;padding:0 16px;`
              + `border-radius:999px;font-weight:800;font-size:13px;cursor:pointer;`
              + `border:1px solid ${on ? '#1F6080' : '#CBD5E1'};background:${on ? '#1F6080' : '#fff'};`
              + `color:${on ? '#fff' : '#334155'};">${esc(f.family_name || ('Family #' + f.id))}</button>`;
          }).join('')
        + '</div>'
      : '';

    const txnHtml = rows.length
      ? (ldIsMobile()
          ? '<div data-kt-list="1">' + rows.map(ldTxnCard).join('') + '</div>'
          : `<div class="kt-card" style="padding:0;"><table style="width:100%;border-collapse:collapse;table-layout:auto;">
              <thead><tr>
                <th style="text-align:left;white-space:nowrap;">Date</th>
                <th style="text-align:left;">Type</th>
                <th style="text-align:left;">Description</th>
                <th style="text-align:right;white-space:nowrap;">Debit</th>
                <th style="text-align:right;white-space:nowrap;">Credit</th>
                <th style="text-align:right;white-space:nowrap;">Balance</th>
                <th style="width:1%;"></th>
              </tr></thead>
              <tbody>${rows.map(row => { const _inv = row.type === 'invoice' && row.invoice_id; return `<tr style="vertical-align:middle;">
                <td style="white-space:nowrap;vertical-align:middle;">${fmtDate(row.date)}</td>
                <td style="text-transform:capitalize;vertical-align:middle;">${esc(row.type)}</td>
                <td style="vertical-align:middle;">${esc(row.description)}${row.days_late > 0 ? ' <span style="display:inline-block;margin-left:6px;padding:1px 7px;border-radius:10px;background:#FEE2E2;color:#B91C1C;font-size:11px;font-weight:700;white-space:nowrap;">' + row.days_late + ' day' + (row.days_late === 1 ? '' : 's') + ' late</span>' : ''}</td>
                <td style="text-align:right;white-space:nowrap;font-variant-numeric:tabular-nums;vertical-align:middle;color:${row.debit > 0 ? '#B91C1C' : '#94A3B8'};">${row.debit ? fmtMoney(row.debit) : ''}</td>
                <td style="text-align:right;white-space:nowrap;font-variant-numeric:tabular-nums;vertical-align:middle;color:${row.credit > 0 ? '#047857' : '#94A3B8'};">${row.credit ? fmtMoney(row.credit) : ''}</td>
                <td style="text-align:right;white-space:nowrap;font-variant-numeric:tabular-nums;vertical-align:middle;font-weight:700;">${fmtMoney(row.running_balance)}</td>
                <td style="white-space:nowrap;vertical-align:middle;text-align:right;padding-right:10px;">${_inv ? ldActionButtons(row.invoice_id) : ''}</td>
              </tr>`; }).join('')}</tbody>
            </table></div>`)
      : '<div style="text-align:center;padding:34px 16px;color:#64748B;background:#fff;border:1px dashed #CBD5E1;border-radius:14px;">No transactions yet.</div>';

    main.innerHTML = `<div style="padding:18px 14px;max-width:1000px;margin:0 auto;">
      <div style="display:flex;align-items:flex-start;justify-content:space-between;gap:12px;flex-wrap:wrap;margin:2px 2px 12px;">
        <div><h2 style="margin:0;font-size:20px;color:#0F172A;">📒 Account ledger</h2>
          <div style="font-size:12.5px;color:#64748B;margin-top:2px;">${esc(r.family.family_name)}</div></div>
        <button id="ld-pdf" style="flex:0 0 auto;background:#fff;border:1px solid #E2E8F0;color:#1F6080;font-weight:700;font-size:13px;border-radius:10px;padding:9px 15px;cursor:pointer;">⤓ Statement PDF</button>
      </div>

      ${famTabs}

      <div style="background:linear-gradient(135deg,${balGrad});color:#fff;border-radius:18px;padding:20px;margin-bottom:14px;box-shadow:0 12px 28px -14px rgba(0,0,0,.45);">
        <div style="font-size:11.5px;font-weight:800;letter-spacing:.9px;opacity:.9;text-transform:uppercase;">${balLabel}</div>
        <div style="font-size:36px;font-weight:900;line-height:1;margin-top:7px;">${fmtMoney(Math.abs(bal))}</div>
        <div style="font-size:12.5px;opacity:.92;margin-top:7px;">${esc(balSub)}</div>
      </div>

      <div class="kt-kpi-grid" style="margin-bottom:14px;">
        <div class="kt-kpi kt-kpi-info"><div class="kt-kpi-label">Total invoiced</div><div class="kt-kpi-value">${fmtMoney(r.total_invoiced)}</div></div>
        <div class="kt-kpi kt-kpi-success"><div class="kt-kpi-label">Total paid</div><div class="kt-kpi-value">${fmtMoney(r.total_paid)}</div></div>
        <div class="kt-kpi kt-kpi-danger"><div class="kt-kpi-label">Total refunded</div><div class="kt-kpi-value">${fmtMoney(r.total_refunded)}</div></div>
        <div class="kt-kpi ${r.days_overdue > 0 ? 'kt-kpi-danger' : 'kt-kpi-success'}"><div class="kt-kpi-label">Payment status</div><div class="kt-kpi-value">${r.days_overdue > 0 ? r.days_overdue + (r.days_overdue === 1 ? ' day late' : ' days late') : 'On time'}</div></div>
      </div>

      <div style="font-size:12px;font-weight:800;letter-spacing:.5px;text-transform:uppercase;color:#64748B;margin:2px 2px 10px;">Transactions</div>
      ${txnHtml}
    </div>`;

    // #34 — per-invoice actions (View / Download PDF / Email to me). One delegated
    // handler so it fires whether the button is clicked inline (mobile) OR
    // forwarded by the kt-row-actions kebab menu (desktop) via el.click().
    main.addEventListener('click', (e) => {
      const v = e.target.closest('[data-inv-view]');
      if (v) { e.preventDefault(); const id = v.getAttribute('data-inv-view'); if (window.KT && KT.openInvoiceById) KT.openInvoiceById(id); return; }
      const dl = e.target.closest('[data-inv-dl]');
      if (dl) { e.preventDefault(); const id = dl.getAttribute('data-inv-dl'); downloadAuthed('/parent/invoices/' + id + '/pdf', 'Invoice-' + id + '.pdf'); return; }
      const em = e.target.closest('[data-inv-email]');
      if (em) { e.preventDefault(); ldEmailInvoice(em.getAttribute('data-inv-email')); return; }
    });
    // Collapse the per-row action buttons into the app's standard ⋮ kebab now
    // (don't wait for the 4s sweep bus) so it's there on first paint.
    try { if (window.KT && KT.sweepRowActions) KT.sweepRowActions(); } catch (e) {}

    /* Attached ONCE. renderLedger runs again on every tab click, and re-adding here
       would stack a handler per switch until one click fired a render for each family
       the parent had ever looked at. */
    if (main.getAttribute('data-fam-wired') !== '1') {
      main.setAttribute('data-fam-wired', '1');
      main.addEventListener('click', (e) => {
        const f = e.target.closest('[data-fam]');
        if (!f) return;
        e.preventDefault();
        renderLedger(main, f.getAttribute('data-fam'));
      });
    }

    const _ldPdf = main.querySelector('#ld-pdf');
    // The statement must be for the family being LOOKED at, not the first one.
    if (_ldPdf) _ldPdf.onclick = () => downloadAuthed(
      '/parent/ledger/pdf' + (activeFam ? ('?family_id=' + encodeURIComponent(activeFam)) : ''),
      'statement-' + new Date().toISOString().slice(0, 10) + '.pdf');
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
        <label style="font-size:13px;font-weight:600;">Choose the payment to refund</label>
        <select id="rf-picker" style="width:100%;padding:11px;border:1px solid #E2E8F0;border-radius:8px;margin-top:6px;">
          <option value="">Finding payments with money on them…</option>
        </select>
        <div style="display:flex;gap:8px;margin-top:10px;align-items:center;flex-wrap:wrap;">
          <span style="font-size:12px;color:#64748B;">Or enter the invoice number to refund:</span>
          <!-- TEXT, not number. Invoice numbers are 'iL-INV-1779383993378' and
               'INV-202606-0016-079'; a numeric input could not hold either. -->
          <input id="rf-inv" type="text" placeholder="e.g. iL-INV-1779383993378" style="width:250px;padding:9px;border:1px solid #E2E8F0;border-radius:8px;">
          <button id="rf-load" class="kt-btn kt-btn-primary">Find invoice</button>
        </div>
        <div id="rf-lookup" style="margin-top:10px;"></div>
        <div id="rf-detail" style="margin-top:20px;"></div>
      </div>
    </div>`;
    // v22p98: populate a picker of recent payments so no raw Payment ID is needed.
    (async () => {
      const sel = document.getElementById('rf-picker');
      try {
        const res = await Api.get('/refunds/recent-payments');
        const pays = res.data || [];
        window.__rfEntries = pays;
        /* Both kinds in one list. A 'payment' is a receipt already on file; an
           'external' is an invoice holding money that never got one, which is all of
           iLearn's $40,261.54 — the receipt is written if it is actually refunded. */
        sel.innerHTML = pays.length
          ? '<option value="">— Choose a payment —</option>' + pays.map((p, i) =>
              `<option value="${i}">${esc(p.family_name)} · ${fmtMoney(p.refundable)} refundable · ${p.reference ? esc(p.reference) : '#' + p.id}${p.date ? ' · ' + fmtDate(p.date) : ''}${p.refunded > 0 ? ' · ' + fmtMoney(p.refunded) + ' already refunded' : ''}</option>`).join('')
          : '<option value="">Nothing on this agency has money that can be refunded</option>';
        sel.onchange = () => {
          /* "" is the placeholder, and +"" is 0 — a real index. Checked as a string
             first, or picking "— Choose a payment —" loads the first family on the
             list and offers to refund them. */
          if (sel.value === '') { document.getElementById('rf-detail').innerHTML = ''; return; }
          const e = (window.__rfEntries || [])[Number(sel.value)];
          if (!e) return;
          // #rf-pid is gone — the manual field takes an invoice number now.
          const lk = document.getElementById('rf-lookup');
          if (lk) lk.innerHTML = '';
          if (e.kind === 'external') { loadInvoice(e); } else { loadPayment(e.id); }
        };
      } catch (e) { sel.innerHTML = '<option value="">Could not load payments — enter an ID below</option>'; }
    })();
    const _rfLoad = main.querySelector('#rf-load');
    if (_rfLoad) _rfLoad.onclick = () => lookupInvoice();
    const _rfInv = main.querySelector('#rf-inv');
    // Enter should work — nobody types a number then reaches for the mouse.
    if (_rfInv) _rfInv.addEventListener('keydown', (e) => { if (e.key === 'Enter') { e.preventDefault(); lookupInvoice(); } });

    /* One number can belong to several invoices — 'PINVO-05082026' belongs to eleven
       families — so every match is listed and a person chooses. Picking one for them
       would eventually offer to refund a stranger. */
    async function lookupInvoice() {
      const box = document.getElementById('rf-lookup');
      const num = (document.getElementById('rf-inv').value || '').trim();
      document.getElementById('rf-detail').innerHTML = '';
      if (!num) { box.innerHTML = '<div style="color:#64748B;font-size:12.5px;">Enter an invoice number.</div>'; return; }
      box.innerHTML = '<div style="color:#64748B;font-size:12.5px;">Looking for ' + esc(num) + '…</div>';
      try {
        const r = await Api.get('/refunds/lookup?number=' + encodeURIComponent(num));
        const ms = r.matches || [];
        if (!ms.length) {
          box.innerHTML = `<div style="background:#FEF3C7;color:#92400E;padding:11px 13px;border-radius:8px;font-size:12.5px;">
            No invoice found with the number ${esc(num)}. Check the number, or choose the family from the list above.</div>`;
          return;
        }
        if (ms.length === 1 && ms[0].refundable > 0.005) { box.innerHTML = ''; openMatch(ms[0]); return; }
        box.innerHTML = `<div style="font-size:12.5px;color:#334155;margin-bottom:6px;">${ms.length} invoice${ms.length > 1 ? 's' : ''} with that number${ms.length > 1 ? ' — choose the right family' : ''}:</div>`
          + ms.map((m, i) => `<div style="display:flex;gap:10px;align-items:center;padding:9px 11px;border:1px solid #E2E8F0;border-radius:9px;margin-bottom:6px;">
              <div style="flex:1;min-width:0;">
                <strong>${esc(m.family_name || '—')}</strong>
                <div style="font-size:11.5px;color:#64748B;">${esc(m.number)} · ${esc(m.method)} · billed ${fmtMoney(m.total)} · received ${fmtMoney(m.amount)}</div>
                ${m.reason ? `<div style="font-size:11.5px;color:#B45309;margin-top:2px;">${esc(m.reason)}</div>` : ''}
              </div>
              <div style="text-align:right;white-space:nowrap;">
                <div style="font-size:10.5px;color:#64748B;text-transform:uppercase;font-weight:800;">Refundable</div>
                <div style="font-weight:800;">${fmtMoney(m.refundable)}</div>
              </div>
              ${m.refundable > 0.005 ? `<button class="kt-btn kt-btn-primary" data-match="${i}" style="font-size:12px;padding:6px 12px;">Refund</button>` : ''}
            </div>`).join('');
        box.querySelectorAll('[data-match]').forEach(b => {
          b.onclick = () => { box.innerHTML = ''; openMatch(ms[+b.dataset.match]); };
        });
      } catch (e) {
        box.innerHTML = `<div style="background:#FEE2E2;color:#991B1B;padding:11px 13px;border-radius:8px;font-size:12.5px;">${esc(e.message || 'Could not look that up.')}</div>`;
      }
    }

    /* A native invoice already has a receipt, so its existing panel (with the refund
       history on it) is the better one. An external invoice has none until it is
       refunded, which is what loadInvoice handles. Both support partial and full. */
    function openMatch(m) {
      if (m.kind === 'native' && m.payment_id) { loadPayment(m.payment_id); return; }
      loadInvoice({
        kind: 'external', id: m.id, reference: m.number, amount: m.amount,
        refunded: m.refunded, refundable: m.refundable, method: m.method,
      });
    }
    async function loadPayment(pid) {
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
          <td><span class="kt-pill ${rf.status === 'succeeded' ? 'kt-pill-success' : rf.status === 'failed' ? 'kt-pill-danger' : 'kt-pill-warning'}">${esc(rf.status)}</span></td></tr>`).join('') || '<tr><td colspan="4" style="text-align:center;padding:24px;color:#64748B;">No prior refunds.</td></tr>'}</tbody></table>
        <div style="max-width:440px;">
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
        <div style="margin-top:14px;padding-top:12px;border-top:1px solid #E2E8F0;">
          <label style="font-size:13px;font-weight:600;display:block;">Your name</label>
          <input id="rf-name" type="text" placeholder="Your full name" style="width:100%;padding:9px;border:1px solid #E2E8F0;border-radius:8px;">
          <label style="font-size:13px;font-weight:600;margin-top:10px;display:block;">Signature</label>
          <div style="font-size:12px;color:#64748B;margin-bottom:6px;">Stored with the refund, along with the date and time.</div>
          <div id="rf-pad"></div>
          <button id="rf-clear" type="button" class="kt-btn" style="margin-top:6px;font-size:12px;padding:4px 10px;">Clear signature</button>
        </div>
        <button id="rf-go" class="kt-btn kt-btn-danger" style="margin-top:14px;">Issue refund</button>
        <div id="rf-out" style="margin-top:10px;"></div>
        </div>
      `;
      /* The server requires a signature on every route that can refund, so this
         screen captures one too — otherwise the change that made refunds accountable
         would simply have broken this page. */
      const _rfPadHost = main.querySelector('#rf-pad');
      const _rfPad = (_rfPadHost && window.KT && KT.signaturePadInline)
        ? KT.signaturePadInline(_rfPadHost, { hint: 'Sign here to approve' })
        : null;
      const _rfClear = main.querySelector('#rf-clear');
      if (_rfClear && _rfPad) _rfClear.onclick = () => _rfPad.clear();

      const _rfGo = main.querySelector('#rf-go');
      if (_rfGo) _rfGo.onclick = async () => {
        const amt = parseFloat(document.getElementById('rf-amt').value);
        const _say = (m) => (window.KT && window.KT.toast) ? KT.toast(m, 'info') : alert(m);
        if (!amt) return _say('Amount required');
        const _name = (document.getElementById('rf-name').value || '').trim();
        if (_name.length < 2) return _say('Type your full name to approve this refund.');
        if (!_rfPad || _rfPad.isEmpty()) return _say('Please sign in the box to approve this refund.');
        try {
          const out = await Api.post('/refunds', {
            payment_id: pid, amount: amt,
            reason: document.getElementById('rf-reason').value,
            notes: document.getElementById('rf-notes').value,
            signature: _rfPad.toDataURL(),
            signed_name: _name,
          });
          var _done = `<div style="background:#DCFCE7;color:#166534;padding:14px;border-radius:8px;">✓ Refund ${out.status} (${out.stripe_refund_id || 'manual'})</div>`;
          /* A manual refund has moved no money — the invoice is reversed and the family
             has been told their centre will arrange it. Offering the transfer here is
             the difference between that promise being kept and being remembered. */
          if (out.payout) {
            _done += `<div style="margin-top:10px;background:#FFF7ED;border:1px solid #FED7AA;color:#9A3412;padding:14px;border-radius:8px;">
              <div style="font-weight:800;margin-bottom:4px;">No money has been sent yet</div>
              <div style="font-size:13px;margin-bottom:10px;">This refund is recorded against the invoice, and
                ${esc(out.payout.name)} has been told you will arrange payment.</div>
              <button id="rf-payout" class="kt-btn kt-btn-primary" type="button">Send $${Number(out.payout.amount).toFixed(2)} by Interac now</button>
              <button id="rf-nopayout" class="kt-btn" type="button" style="margin-left:6px;">I'll arrange it myself</button>
            </div>`;
          }
          document.getElementById('rf-out').innerHTML = _done;
          if (out.payout) {
            var _po = document.getElementById('rf-payout');
            var _no = document.getElementById('rf-nopayout');
            if (_no) _no.onclick = function () { document.getElementById('rf-out').innerHTML = _done.split('<div style="margin-top:10px;')[0]; };
            if (_po) _po.onclick = async function () {
              _po.disabled = true; _po.textContent = 'Sending…';
              try {
                var r2 = await Api.post('/director/zum/send', {
                  user_id: out.payout.user_id,
                  amount: out.payout.amount,
                  comment: 'Refund of payment #' + pid,
                });
                document.getElementById('rf-out').innerHTML =
                  `<div style="background:#DCFCE7;color:#166534;padding:14px;border-radius:8px;">✓ ${esc(r2.message || 'Sent.')}</div>`;
              } catch (e2) {
                // The server's own sentence — "not enough balance in wallet" is actionable.
                _po.disabled = false; _po.textContent = 'Try again';
                document.getElementById('rf-out').insertAdjacentHTML('beforeend',
                  `<div style="margin-top:8px;background:#FEE2E2;color:#991B1B;padding:12px;border-radius:8px;">${esc(e2.message || 'That could not be sent.')}</div>`);
              }
            };
          }
          // The panel already knows which payment it is showing.
          setTimeout(() => loadPayment(pid), 2500);
        } catch (e) { document.getElementById('rf-out').innerHTML = `<div style="background:#FEE2E2;color:#991B1B;padding:14px;border-radius:8px;">${esc(e.message || 'Failed')}</div>`; }
      };
    }
  }

  /* An invoice holding money that never got a receipt row.

     All of iLearn's $40,261.54 is this shape: the integration writes external_invoices
     and no payment, so there is nothing for loadPayment to fetch and no refund history
     to list. The receipt is written by the server at the moment a refund is approved.

     Separate from loadPayment for that reason, but it posts to /refunds/invoice, so the
     guards, the signature, the audit row and the ledger effect are identical. */
  function loadInvoice(entry) {
    if (!entry) return;
    document.getElementById('rf-detail').innerHTML = `
      <div class="kt-kpi-grid">
        <div class="kt-kpi"><div class="kt-kpi-label">Received</div><div class="kt-kpi-value">${fmtMoney(entry.amount)}</div></div>
        <div class="kt-kpi kt-kpi-success"><div class="kt-kpi-label">Refundable</div><div class="kt-kpi-value">${fmtMoney(entry.refundable)}</div></div>
        <div class="kt-kpi"><div class="kt-kpi-label">Invoice</div><div class="kt-kpi-value" style="font-size:16px;">${esc(entry.reference || ('#' + entry.id))}</div></div>
      </div>
      <div style="margin-top:14px;padding:11px 13px;background:#EEF2FF;border:1px solid #C7D2FE;border-radius:9px;color:#3730A3;font-size:12.5px;">
        This money was received in ${esc(entry.method || 'the source system')} and has no payment record here yet.
        Approving a refund creates that record first, so the refund has something to reverse.
      </div>
      <div style="max-width:440px;">
      <h3 style="margin-top:18px;font-size:15px;color:#0F172A;">Issue new refund</h3>
      <label style="font-size:13px;font-weight:600;">Amount</label>
      <input id="rfi-amt" type="number" step="0.01" max="${entry.refundable}" min="0.01" value="${Number(entry.refundable).toFixed(2)}" style="width:100%;padding:9px;border:1px solid #E2E8F0;border-radius:8px;">
      <div style="font-size:11.5px;color:#64748B;margin-top:4px;">At most ${fmtMoney(entry.refundable)} can be refunded against this invoice.</div>
      <label style="font-size:13px;font-weight:600;margin-top:10px;display:block;">Reason</label>
      <select id="rfi-reason" style="width:100%;padding:9px;border:1px solid #E2E8F0;border-radius:8px;">
        <option value="requested_by_customer">Family requested it</option>
        <option value="overpayment">Overpayment</option>
        <option value="duplicate">Duplicate charge</option>
        <option value="vacation_credit">Vacation credit</option>
        <option value="goodwill">Goodwill</option>
      </select>
      <label style="font-size:13px;font-weight:600;margin-top:10px;display:block;">Notes</label>
      <textarea id="rfi-notes" rows="3" style="width:100%;padding:9px;border:1px solid #E2E8F0;border-radius:8px;font-family:inherit;"></textarea>
      <div style="margin-top:14px;padding-top:12px;border-top:1px solid #E2E8F0;">
        <label style="font-size:13px;font-weight:600;display:block;">Your name</label>
        <input id="rfi-name" type="text" placeholder="Your full name" style="width:100%;padding:9px;border:1px solid #E2E8F0;border-radius:8px;">
        <label style="font-size:13px;font-weight:600;margin-top:10px;display:block;">Signature</label>
        <div style="font-size:12px;color:#64748B;margin-bottom:6px;">Stored with the refund, along with the date and time.</div>
        <div id="rfi-pad"></div>
        <button id="rfi-clear" type="button" class="kt-btn" style="margin-top:6px;font-size:12px;padding:4px 10px;">Clear signature</button>
      </div>
      <button id="rfi-go" class="kt-btn kt-btn-danger" style="margin-top:14px;">Issue refund</button>
      <div id="rfi-out" style="margin-top:10px;"></div>
      </div>`;

    const padHost = document.getElementById('rfi-pad');
    const pad = (padHost && window.KT && KT.signaturePadInline)
      ? KT.signaturePadInline(padHost, { hint: 'Sign here to approve' }) : null;
    const clr = document.getElementById('rfi-clear');
    if (clr && pad) clr.onclick = () => pad.clear();

    const go = document.getElementById('rfi-go');
    go.onclick = async () => {
      const out = document.getElementById('rfi-out');
      const fail = (m) => { out.innerHTML = `<div style="background:#FEE2E2;color:#991B1B;padding:12px;border-radius:8px;">${esc(m)}</div>`; };
      const amt = parseFloat(document.getElementById('rfi-amt').value);
      if (!(amt > 0)) return fail('Enter the amount to refund.');
      if (amt > Number(entry.refundable) + 0.005) return fail('That is more than the ' + fmtMoney(entry.refundable) + ' available on this invoice.');
      const name = (document.getElementById('rfi-name').value || '').trim();
      if (name.length < 2) return fail('Type your full name to approve this refund.');
      if (!pad || pad.isEmpty()) return fail('Please sign in the box to approve this refund.');

      go.disabled = true; go.textContent = 'Approving…';
      try {
        const res = await Api.post('/refunds/invoice', {
          kind: 'external', invoice_id: entry.id, amount: Number(amt.toFixed(2)),
          reason: document.getElementById('rfi-reason').value,
          notes: document.getElementById('rfi-notes').value,
          signature: pad.toDataURL(), signed_name: name,
        });
        out.innerHTML = `<div style="background:#DCFCE7;color:#166534;padding:14px;border-radius:8px;">✓ Refund ${esc(res.status)} — ${fmtMoney(amt)} recorded against ${esc(entry.reference || ('#' + entry.id))}.${res.status === 'manual' ? ' No money has been sent yet; arrange the payment as usual.' : ''}</div>`;
        go.textContent = 'Refund recorded';
      } catch (e) {
        go.disabled = false; go.textContent = 'Issue refund';
        fail(e.message || 'The refund could not be recorded.');
      }
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
            <div><strong>${esc(f.name)}</strong><div style="color:#64748B;font-size:12px;">${esc(r.report_types[f.report_type] || f.report_type)}</div></div>
            <button class="kt-btn kt-btn-primary" data-run="${f.id}" style="font-size:12px;padding:6px 12px;">Run</button>
          </div>`).join('') || '<div style="color:#64748B;">No favourites yet.</div>'}
        </div>
        <div class="kt-card">
          <div class="kt-card-header"><h3 class="kt-card-title">🕘 Recently run</h3></div>
          ${(r.recent || []).map(f => `<div style="padding:10px 0;border-bottom:1px solid #F1F5F9;display:flex;justify-content:space-between;align-items:center;">
            <div><strong>${esc(f.name)}</strong><div style="color:#64748B;font-size:12px;">last run ${fmtDate(f.last_run_at)}</div></div>
            <button class="kt-btn kt-btn-primary" data-run="${f.id}" style="font-size:12px;padding:6px 12px;">Run</button>
          </div>`).join('') || '<div style="color:#64748B;">No reports run yet.</div>'}
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
            </td></tr>`).join('') || '<tr><td colspan="5" style="text-align:center;padding:30px;color:#64748B;">No reports yet.</td></tr>'}</tbody>
        </table>
      </div>
    </div>`;
    const _repNew = main.querySelector('#rep-new');
    if (_repNew) _repNew.onclick = () => openReportBuilder(r.report_types);
    main.querySelectorAll('button[data-run]').forEach(b => b.onclick = () => runReport(+b.dataset.run, r.report_types));
    main.querySelectorAll('button[data-del]').forEach(b => b.onclick = async () => {
      if (!await KT.confirm('Delete this saved report?')) return;
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
      <div data-kt-list="1" style="display:grid;grid-template-columns:repeat(auto-fill,minmax(360px,1fr));gap:18px;">
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
        }).join('') || '<div class="kt-card" style="grid-column:1/-1;text-align:center;padding:60px;color:#64748B;">No videos yet.</div>'}
      </div>
    </div>`;
    const _vUp = main.querySelector('#v-upload');
    if (_vUp) _vUp.onclick = () => openVideoUpload();
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
      if (!f) { (window.KT && window.KT.toast) ? KT.toast('Pick a video', /save|sent|added|created|approved|deleted|removed|done|charged/i.test('Pick a video') ? 'success' : 'info') : alert('Pick a video'); return; }
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
      } catch (e) { if (window.KT && window.KT.toast) window.KT.toast(e.message || 'Upload failed', 'error', 7000); else (window.KT && window.KT.toast) ? KT.toast(e.message, /save|sent|added|created|approved|deleted|removed|done|charged/i.test(e.message) ? 'success' : 'info') : alert(e.message); }
    };
  }

  // ============================ Immunization Due At Age ============================

  /* TWO VIEWS, NOT TWO CARDS STACKED.

     "Children needing attention" and "Schedule defaults" answer different questions and
     are used by different people on different days — one is the morning chase list, the
     other is a policy table somebody edits once a year. Stacked, the policy table pushed
     the chase list off the screen on a laptop and the schedule was read-only besides.
     Subtabs, so each is a whole screen when you are in it. (Anthony, 2026-09-10) */
  var IMS_TAB = 'due';

  function imsTabBar(active) {
    return [['due', '⚠️ Children due'], ['defaults', '💉 Schedule defaults']].map(function (t) {
      var on = t[0] === active;
      return '<button class="ims-tab" data-t="' + t[0] + '" type="button" style="padding:8px 16px;margin-right:8px;'
        + 'border:1px solid ' + (on ? '#1F6080' : '#D1D5DB') + ';border-radius:9px;background:'
        + (on ? '#1F6080' : '#fff') + ';color:' + (on ? '#fff' : '#374151')
        + ';font-weight:700;font-size:13.5px;cursor:pointer;">' + t[1] + '</button>';
    }).join('');
  }

  /* Months read as an age, not as a number. "18 months" is fine; "48 months" is a
     four-year-old and nobody thinks in 48s. */
  function imsAge(m) {
    m = Number(m) || 0;
    if (m < 24) { return m + ' month' + (m === 1 ? '' : 's'); }
    var y = Math.floor(m / 12), r = m % 12;
    return y + ' year' + (y === 1 ? '' : 's') + (r ? ' ' + r + ' mo' : '');
  }

  /* Add or edit one schedule row. The same dialog for both — a separate "add" form is
     how two things that should agree start disagreeing about what a dose looks like. */
  function imsEdit(row, after) {
    var isNew = !row || !row.id;
    var r = row || { vaccine: '', dose_label: '', due_at_age_months: 2, is_required: 1, notes: '' };

    var ov = document.createElement('div');
    ov.className = 'kt-scrim';
    ov.setAttribute('data-no-modal-guard', '1');
    ov.style.cssText = 'position:fixed;inset:0;z-index:2147479000;display:flex;align-items:flex-start;'
      + 'justify-content:center;padding:20px;overflow-y:auto;background:rgba(8,20,40,.55);';
    var lab = 'display:block;font-size:12px;font-weight:700;color:#475569;margin-bottom:4px;';
    var inp = 'width:100%;padding:9px 12px;border:1px solid #D1D5DB;border-radius:9px;font-size:14px;box-sizing:border-box;';
    ov.innerHTML =
      '<div style="background:#fff;border-radius:16px;max-width:520px;width:100%;margin:auto;overflow:hidden;box-shadow:0 30px 80px -20px rgba(8,20,40,.6);">'
      + '<div style="padding:18px 22px;border-bottom:1px solid #EEF2F7;display:flex;align-items:center;gap:12px;">'
      +   '<div style="font-size:17px;font-weight:800;color:#0F172A;">💉 ' + (isNew ? 'Add a schedule item' : 'Edit schedule item') + '</div>'
      +   '<button class="modal-close" type="button" aria-label="Close" data-kt-iconized="1" style="margin-left:auto;background:#F1F5F9;border:0;border-radius:9px;width:34px;height:34px;font-size:17px;cursor:pointer;color:#475569;">✕</button>'
      + '</div>'
      + '<div style="padding:18px 22px;display:grid;gap:12px;">'
      +   '<div><label style="' + lab + '">Vaccine *</label><input id="ims-v" style="' + inp + '" value="' + esc(r.vaccine) + '" placeholder="e.g. MMR"></div>'
      +   '<div><label style="' + lab + '">Dose *</label><input id="ims-d" style="' + inp + '" value="' + esc(r.dose_label) + '" placeholder="e.g. 1st dose"></div>'
      +   '<div><label style="' + lab + '">Due at age (months) *</label><input id="ims-m" type="number" min="0" max="300" style="' + inp + '" value="' + esc(r.due_at_age_months) + '">'
      +     '<div id="ims-age" style="font-size:12px;color:#64748B;margin-top:4px;"></div></div>'
      +   '<label style="display:flex;gap:9px;align-items:center;cursor:pointer;font-size:13.5px;font-weight:600;color:#334155;">'
      +     '<input id="ims-r" type="checkbox" style="width:18px;height:18px;accent-color:#1F6080;"' + (r.is_required ? ' checked' : '') + '> Required'
      +   '</label>'
      +   '<div><label style="' + lab + '">Notes</label><textarea id="ims-n" rows="2" style="' + inp + 'resize:vertical;">' + esc(r.notes || '') + '</textarea></div>'
      +   '<div id="ims-msg" style="font-size:13px;color:#B91C1C;min-height:18px;"></div>'
      + '</div>'
      + '<div style="padding:14px 22px;border-top:1px solid #EEF2F7;display:flex;gap:10px;justify-content:flex-end;">'
      +   '<button class="modal-close" type="button" style="padding:10px 18px;border:1px solid #D1D5DB;background:#fff;border-radius:10px;font-size:13.5px;font-weight:700;cursor:pointer;color:#475569;">Cancel</button>'
      +   '<button id="ims-save" type="button" style="padding:10px 22px;border:0;background:#1F6080;color:#fff;border-radius:10px;font-size:13.5px;font-weight:800;cursor:pointer;">' + (isNew ? 'Add' : 'Save') + '</button>'
      + '</div></div>';
    document.body.appendChild(ov);

    var months = ov.querySelector('#ims-m');
    var ageEl = ov.querySelector('#ims-age');
    var paintAge = function () { ageEl.textContent = '= ' + imsAge(months.value); };
    months.addEventListener('input', paintAge);
    paintAge();

    ov.querySelectorAll('.modal-close').forEach(function (b) {
      b.addEventListener('click', function () { ov.remove(); });
    });

    ov.querySelector('#ims-save').addEventListener('click', function () {
      var msg = ov.querySelector('#ims-msg');
      var payload = {
        vaccine: (ov.querySelector('#ims-v').value || '').trim(),
        dose_label: (ov.querySelector('#ims-d').value || '').trim(),
        due_at_age_months: parseInt(months.value, 10),
        is_required: ov.querySelector('#ims-r').checked,
        notes: (ov.querySelector('#ims-n').value || '').trim() || null,
      };
      if (!payload.vaccine || !payload.dose_label) { msg.textContent = 'Vaccine and dose are both required.'; return; }
      if (!(payload.due_at_age_months >= 0)) { msg.textContent = 'Due at age must be a number of months.'; return; }
      if (!isNew) { payload.id = r.id; }

      var btn = ov.querySelector('#ims-save');
      btn.disabled = true; btn.textContent = 'Saving…';
      Api.post('/immunization/schedule', payload).then(function () {
        ov.remove();
        if (KT.toast) { KT.toast('💉', isNew ? 'Added' : 'Saved', payload.vaccine + ' · ' + payload.dose_label, '#16A34A'); }
        if (after) { after(); }
      }).catch(function (e) {
        btn.disabled = false; btn.textContent = isNew ? 'Add' : 'Save';
        msg.textContent = (e && e.message) || 'Could not save.';
      });
    });
  }

  /* Read-only detail. Asked for alongside edit because most visits to this table are to
     CHECK something, and opening an edit form to read a value is how a value gets changed
     by accident. */
  function imsView(r) {
    var ov = document.createElement('div');
    ov.className = 'kt-scrim';
    ov.setAttribute('data-no-modal-guard', '1');
    ov.style.cssText = 'position:fixed;inset:0;z-index:2147479000;display:flex;align-items:flex-start;'
      + 'justify-content:center;padding:20px;overflow-y:auto;background:rgba(8,20,40,.55);';
    var line = function (k, v) {
      return '<div style="display:flex;justify-content:space-between;gap:14px;padding:8px 0;border-bottom:1px solid #F1F5F9;font-size:13.5px;">'
        + '<span style="color:#64748B;">' + k + '</span><span style="font-weight:700;color:#0F172A;text-align:right;">' + v + '</span></div>';
    };
    ov.innerHTML =
      '<div style="background:#fff;border-radius:16px;max-width:460px;width:100%;margin:auto;overflow:hidden;box-shadow:0 30px 80px -20px rgba(8,20,40,.6);">'
      + '<div style="padding:18px 22px;border-bottom:1px solid #EEF2F7;display:flex;align-items:center;gap:12px;">'
      +   '<div style="font-size:17px;font-weight:800;color:#0F172A;">💉 ' + esc(r.vaccine) + '</div>'
      +   '<button class="modal-close" type="button" aria-label="Close" data-kt-iconized="1" style="margin-left:auto;background:#F1F5F9;border:0;border-radius:9px;width:34px;height:34px;font-size:17px;cursor:pointer;color:#475569;">✕</button>'
      + '</div>'
      + '<div style="padding:8px 22px 18px;">'
      +   line('Dose', esc(r.dose_label))
      /* The raw figure only when it says something the label does not. Under two years the
         two are the same string, and "2 months (2 months)" reads like a bug. */
      +   line('Due at age', esc(imsAge(r.due_at_age_months))
            + (Number(r.due_at_age_months) >= 24
                ? ' <span style="color:#94A3B8;font-weight:500;">(' + esc(r.due_at_age_months) + ' months)</span>'
                : ''))
      +   line('Required', r.is_required ? 'Yes' : 'No')
      +   (r.notes ? '<div style="padding:10px 0;font-size:13px;color:#475569;line-height:1.55;"><strong>Notes:</strong> ' + esc(r.notes) + '</div>' : '')
      + '</div></div>';
    document.body.appendChild(ov);
    ov.querySelectorAll('.modal-close').forEach(function (b) {
      b.addEventListener('click', function () { ov.remove(); });
    });
  }

  /* EMBEDDABLE.

     These two views used to live on their own screen (#immun-schedule) while the roster,
     the records table and the received-documents list lived on another (#immunizations).
     Same subject, two places, and neither one complete — so somebody chasing an overdue
     dose had to know which of the two to open.

     `opts.embed` renders the panes WITHOUT their own hero and tab bar, so
     screen-immunizations.js can host them under its own tabs; `opts.pane` picks which.
     Called standalone (no opts) it behaves exactly as before, which keeps the old hash
     working for anything that still links to it. (Anthony, 2026-09-10) */
  async function renderImmunSchedule(main, opts) {
    opts = (opts && typeof opts === 'object' && (opts.embed || opts.pane)) ? opts : {};
    main.setAttribute('data-kt-pretty', '1');
    main.innerHTML = '<div style="padding:24px;">Loading schedule…</div>';
    const [sched, due] = await Promise.all([
      Api.get('/immunization/schedule'),
      Api.get('/immunization/due-report').catch(() => ({ data: [] })),
    ]);
    const rows = sched.data || [];
    const kids = due.data || [];
    const reload = () => renderImmunSchedule(main, opts);
    const pane = opts.pane || IMS_TAB;

    /* EVERY CHILD, NOT ONLY THE ONES IN TROUBLE.

       This pane and the old "Children" tab read the SAME endpoint and differed only in
       that this one hid anybody up to date — two tabs, one query, and a reader who had to
       know which of them answered their question. Merged: the whole roster, ordered so the
       ones needing attention are already at the top, with the status pill and the running
       totals the Children tab carried.

       Ordered overdue → due soon → up to date, then by name. A list you open every morning
       should put the work first without anybody sorting it. */
    const rank = (c) => (c.overdue > 0 ? 0 : (c.due_soon > 0 ? 1 : 2));
    const ordered = kids.slice().sort((a, b) =>
      (rank(a) - rank(b)) || String(a.child_name || '').localeCompare(String(b.child_name || '')));

    const nOver = kids.filter(c => c.overdue > 0).length;
    const nSoon = kids.filter(c => c.overdue === 0 && c.due_soon > 0).length;
    const nOk = kids.length - nOver - nSoon;

    const duePane = `
      <div style="display:flex;gap:10px;flex-wrap:wrap;margin-bottom:14px;font-size:13px;">
        <span style="padding:6px 12px;border-radius:20px;background:#FEE2E2;color:#991B1B;font-weight:700;">${nOver} overdue</span>
        <span style="padding:6px 12px;border-radius:20px;background:#FEF3C7;color:#92400E;font-weight:700;">${nSoon} due soon</span>
        <span style="padding:6px 12px;border-radius:20px;background:#DCFCE7;color:#166534;font-weight:700;">${nOk} up to date</span>
      </div>
      <div class="kt-card">
        <div class="kt-card-header" style="display:flex;align-items:center;gap:12px;">
          <h3 class="kt-card-title" style="margin:0;">Every enrolled child</h3>
          <span style="font-size:12.5px;color:#64748B;">Worked out from each date of birth against the schedule — complete whether or not anybody has typed a dose in.</span>
        </div>
        <table data-kt-filter-always="1" data-kt-paginate="25">
          <thead><tr><th>Child</th><th>Family</th><th>Provider</th><th>Overdue</th><th>Due soon</th><th>Uploaded</th><th>Status</th><th></th></tr></thead>
          <tbody>${ordered.map(c => {
            const st = c.overdue > 0
              ? ['Overdue', 'kt-pill-danger', '0']
              : (c.due_soon > 0 ? ['Due soon', 'kt-pill-warning', '1'] : ['Up to date', 'kt-pill-success', '2']);
            return `<tr>
          <td data-kt-sort="${esc(String(c.child_name || '').toLowerCase())}"><button type="button" class="imm-child" data-id="${esc(c.child_id || '')}" data-n="${esc(c.child_name || '')}" style="background:none;border:0;padding:0;font:inherit;font-weight:700;color:#1F6080;cursor:pointer;text-align:left;text-decoration:underline;">${esc(c.child_name)}</button></td>
          <td data-kt-sort="${esc(String(c.family_name || '').toLowerCase())}">${esc(c.family_name)}</td>
          <td data-kt-sort="${esc(String(c.centre_name || '').toLowerCase())}">${esc(c.centre_name)}</td>
          <td data-kt-sort="${String(c.overdue || 0).padStart(3, '0')}">${c.overdue > 0 ? `<span class="kt-pill kt-pill-danger">${c.overdue}</span>` : '—'}</td>
          <td data-kt-sort="${String(c.due_soon || 0).padStart(3, '0')}">${c.due_soon > 0 ? `<span class="kt-pill kt-pill-warning">${c.due_soon}</span>` : '—'}</td>
          <td data-kt-sort="${esc(String(c.record_filed_at || ''))}">${(() => {
            /* Sorted on the RAW timestamp, shown as a date — sorting on "3 days ago"
               orders alphabetically, which is how a column like this ends up putting
               April before yesterday. */
            if (!c.record_filed_at) {
              return '<span style="color:#CBD5E1;">—</span>';
            }
            const when = String(c.record_filed_at).slice(0, 10);
            const who = c.record_filed_by ? `<div style="color:#94A3B8;font-size:11.5px;margin-top:2px;">${esc(c.record_filed_by)}</div>` : '';
            const flag = c.record_pending
              ? '<div style="margin-top:3px;"><span style="background:#FEF3C7;border:1px solid #FDE68A;border-radius:999px;padding:1px 7px;font-size:10.5px;font-weight:700;color:#92400E;white-space:nowrap;">Needs details</span></div>'
              : '';
            return `<span style="color:#334155;white-space:nowrap;">${esc(when)}</span>${who}${flag}`;
          })()}</td>
          <td data-kt-sort="${st[2]}"><span class="kt-pill ${st[1]}">${st[0]}</span></td>
          <td style="text-align:right;white-space:nowrap;">
            <button type="button" data-kt-iconized="1" class="imm-child" data-id="${esc(c.child_id || '')}" data-n="${esc(c.child_name || '')}" style="padding:6px 12px;border-radius:8px;border:1px solid #CBD5E1;background:#fff;font-size:12.5px;font-weight:700;cursor:pointer;color:#0F172A;">View immunisations</button>
            <button type="button" data-kt-iconized="1" class="imm-recs" data-id="${esc(c.child_id || '')}" data-n="${esc(c.child_name || '')}" style="margin-left:6px;padding:6px 12px;border-radius:8px;border:1px solid #CBD5E1;background:#fff;font-size:12.5px;font-weight:700;cursor:pointer;color:#0F172A;">📄 Records on file</button>
            <button type="button" data-kt-iconized="1" class="imm-childrec" data-id="${esc(c.child_id || '')}" style="margin-left:6px;padding:6px 12px;border-radius:8px;border:1px solid #CBD5E1;background:#fff;font-size:12.5px;font-weight:700;cursor:pointer;color:#0F172A;">Open child record</button>
          </td>
        </tr>`;
          }).join('') || '<tr><td colspan="8" style="text-align:center;padding:30px;color:#64748B;">No enrolled children.</td></tr>'}</tbody>
        </table>
      </div>`;

    /* data-kt-no-kebab: this table draws its OWN ⋮, and kt-row-actions would otherwise
       wrap it in a second one. (The global guard now catches that too — this says it out
       loud where a reader of this file will see it.) */
    const defaultsPane = `
      <div class="kt-card">
        <div class="kt-card-header" style="display:flex;align-items:center;gap:12px;">
          <h3 class="kt-card-title" style="margin:0;">Schedule defaults</h3>
          <span style="font-size:12.5px;color:#64748B;">What each child is measured against. Changing a row re-computes every child's status.</span>
          <button id="ims-add" type="button" data-kt-iconized="1" style="margin-left:auto;background:#1F6080;color:#fff;border:0;border-radius:10px;padding:9px 16px;font-size:13px;font-weight:800;cursor:pointer;white-space:nowrap;">+ Add item</button>
        </div>
        <table data-kt-no-kebab="1" data-kt-filter-always="1" data-kt-paginate="25">
          <thead><tr><th>Vaccine</th><th>Dose</th><th>Due at age</th><th>Required</th><th>Notes</th><th style="text-align:right;"></th></tr></thead>
          <tbody>${rows.map(r => `<tr>
            <td data-kt-sort="${esc(String(r.vaccine).toLowerCase())}"><strong>${esc(r.vaccine)}</strong></td>
            <td>${esc(r.dose_label)}</td>
            <td data-kt-sort="${String(r.due_at_age_months).padStart(4, '0')}">${esc(imsAge(r.due_at_age_months))}</td>
            <td>${r.is_required ? '<span class="kt-pill kt-pill-info">Required</span>' : '<span style="color:#94A3B8;">Optional</span>'}</td>
            <td style="max-width:280px;color:#475569;">${r.notes ? esc(r.notes) : '<span style="color:#CBD5E1;">—</span>'}</td>
            <td style="text-align:right;"><button class="ims-kebab" data-id="${r.id}" type="button" title="Actions" data-kt-iconized="1"
              style="width:32px;height:32px;border:1px solid #E5E7EB;background:#fff;border-radius:8px;cursor:pointer;font-size:17px;color:#475569;">⋮</button></td>
          </tr>`).join('') || '<tr><td colspan="6" style="text-align:center;padding:30px;color:#64748B;">No schedule items yet. Add the first one.</td></tr>'}</tbody>
        </table>
      </div>`;

    const kpis = `<div class="kt-kpi-grid">
        <div class="kt-kpi kt-kpi-danger"><div class="kt-kpi-label">Children with overdue doses</div><div class="kt-kpi-value">${kids.filter(c => c.overdue > 0).length}</div></div>
        <div class="kt-kpi kt-kpi-warning"><div class="kt-kpi-label">Due in next 2 months</div><div class="kt-kpi-value">${kids.filter(c => c.due_soon > 0).length}</div></div>
        <div class="kt-kpi kt-kpi-info"><div class="kt-kpi-label">Schedule items</div><div class="kt-kpi-value">${rows.length}</div></div>
      </div>`;

    main.innerHTML = opts.embed
      ? `${kpis}${pane === 'defaults' ? defaultsPane : duePane}`
      : `<div style="padding:24px;max-width:1800px;margin:0 auto;">
      <div class="kt-page-hero">
        <h2>💉 Immunization "Due At Age"</h2>
        <p>Age-based schedule. Status auto-computed per child from their DOB + administered records.</p>
      </div>
      ${kpis}
      <div style="margin:18px 0 16px;">${imsTabBar(pane)}</div>
      ${pane === 'defaults' ? defaultsPane : duePane}
    </div>`;

    main.querySelectorAll('.ims-tab').forEach(b => b.addEventListener('click', () => {
      IMS_TAB = b.getAttribute('data-t');
      renderImmunSchedule(main, opts);
    }));

    const addBtn = main.querySelector('#ims-add');
    if (addBtn) { addBtn.addEventListener('click', () => imsEdit(null, reload)); }

    const byId = {};
    rows.forEach(r => { byId[String(r.id)] = r; });
    main.querySelectorAll('.ims-kebab').forEach(btn => {
      btn.addEventListener('click', (ev) => {
        ev.stopPropagation();
        const r = byId[btn.getAttribute('data-id')];
        if (!r) { return; }
        imsMenu(btn, r, reload);
      });
    });

    /* A name opens that child: what is due, the records on file, and the upload —
       the same panel the family sees. */
    /* .imm-child is now BOTH the underlined name and the row action — one selector,
       one handler, so the two cannot drift apart. stopPropagation because the action
       sits inside a row that may gain a click of its own later, and a kebab item that
       also triggers the row is the classic double-fire. */
    main.querySelectorAll('.imm-child').forEach(b => b.addEventListener('click', (e) => {
      e.stopPropagation();
      const id = parseInt(b.getAttribute('data-id'), 10);
      if (!id) { return; }
      if (KT.openChildImmun) { KT.openChildImmun(id, b.getAttribute('data-n')); }
    }));
    /* Straight to the card the family sent in. Same panel as View immunisations, one
       tab along - which is where somebody chasing a missing record is actually going,
       and it saves them the click every time. The leading glyph is deliberate: the
       fallback icon picker tests view/open/details before anything else, so an unlabelled
       "Records on file" would come out as a third eye in the menu. */
    main.querySelectorAll('.imm-recs').forEach(b => b.addEventListener('click', (e) => {
      e.stopPropagation();
      const id = parseInt(b.getAttribute('data-id'), 10);
      if (!id) { return; }
      if (KT.openChildImmun) { KT.openChildImmun(id, b.getAttribute('data-n'), 'recs'); }
    }));
    main.querySelectorAll('.imm-childrec').forEach(b => b.addEventListener('click', (e) => {
      e.stopPropagation();
      const id = parseInt(b.getAttribute('data-id'), 10);
      if (id) { window.location.hash = '#child-detail?id=' + id; }
    }));

    /* Both panes render on a tab switch, which changes no hash — so the shared sweep
       would never see either table. See KT.enhanceTables. */
    if (KT.enhanceTables) { KT.enhanceTables(); }
  }

  /* One ⋮ per row: view, edit, delete. Deliberately the same three a reader expects from
     every other row menu in the portal, in the same order. */
  function imsMenu(btn, r, after) {
    const menu = document.createElement('div');
    menu.style.cssText = 'position:fixed;z-index:2147483000;background:#fff;border:1px solid #E5E7EB;'
      + 'border-radius:12px;box-shadow:0 12px 34px rgba(15,23,42,.18);padding:6px 0;min-width:170px;';
    const close = () => {
      if (menu.parentNode) { menu.remove(); }
      document.removeEventListener('click', onDoc, true);
      window.removeEventListener('scroll', close, true);
    };
    const onDoc = (e) => { if (!menu.contains(e.target) && e.target !== btn) { close(); } };
    const item = (icon, label, danger, fn) => {
      const mi = document.createElement('button');
      mi.type = 'button';
      mi.style.cssText = 'display:flex;align-items:center;gap:10px;width:100%;text-align:left;background:none;'
        + 'border:none;padding:10px 15px;font-size:13.5px;cursor:pointer;font-family:inherit;white-space:nowrap;color:'
        + (danger ? '#B91C1C' : '#111827') + ';';
      mi.innerHTML = '<span style="width:18px;text-align:center;">' + icon + '</span><span>' + label + '</span>';
      mi.onmouseenter = () => { mi.style.background = '#F1F5F9'; };
      mi.onmouseleave = () => { mi.style.background = 'none'; };
      mi.onclick = (e) => { e.stopPropagation(); close(); fn(); };
      menu.appendChild(mi);
    };

    item('👁', 'View', false, () => imsView(r));
    item('✏️', 'Edit', false, () => imsEdit(r, after));
    item('🗑', 'Delete', true, () => {
      /* Says what it costs. This row is what every child's status is measured against, so
         removing it silently changes numbers on a screen somebody else is reading. */
      const msg = 'Remove ' + r.vaccine + ' · ' + r.dose_label + ' from the schedule?\n\n'
        + 'Every child’s due/overdue status is recalculated against the remaining items.';
      Promise.resolve(KT.confirm ? KT.confirm(msg) : confirm(msg)).then(ok => {
        if (!ok) { return; }
        Api.delete('/immunization/schedule/' + r.id).then(() => {
          if (KT.toast) { KT.toast('🗑️', 'Removed', r.vaccine + ' · ' + r.dose_label, '#B91C1C'); }
          if (after) { after(); }
        }).catch(e => {
          if (KT.toast) { KT.toast('⚠️', 'Could not remove', (e && e.message) || '', '#B91C1C'); }
        });
      });
    });

    document.body.appendChild(menu);
    const rect = btn.getBoundingClientRect();
    const mw = menu.offsetWidth || 170, mh = menu.offsetHeight || 130;
    menu.style.left = Math.max(8, Math.min(rect.right - mw, innerWidth - mw - 8)) + 'px';
    menu.style.top = (rect.bottom + 6 + mh > innerHeight - 8 ? Math.max(8, rect.top - mh - 6) : rect.bottom + 6) + 'px';
    setTimeout(() => {
      document.addEventListener('click', onDoc, true);
      window.addEventListener('scroll', close, true);
    }, 0);
  }

  // ============================ CACFP =================================
  async function renderCacfp(main) {
    main.setAttribute('data-kt-pretty', '1');
    main.innerHTML = '<div style="padding:24px;">Loading CACFP…</div>';
    const _cr = await Api.get('/admin/centres').catch(() => ({})); const centres = _cr.centres || _cr.data || [];
    if (!centres.length) { main.innerHTML = '<div class="kt-card" style="margin:24px;text-align:center;color:#64748B;padding:40px;">No centres.</div>'; return; }
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
              <td>${r.cacfp_tier ? `<span class="kt-pill kt-pill-info">${esc(r.cacfp_tier)}</span>` : '<span style="color:#64748B;">unset</span>'}</td>
              ${cell('breakfast')}${cell('morning_snack')}${cell('lunch')}${cell('afternoon_snack')}${cell('dinner')}
            </tr>`;
          }).join('') || '<tr><td colspan="7" style="text-align:center;padding:30px;color:#64748B;">No children enrolled.</td></tr>'}</tbody>
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
        <label style="font-size:13px;font-weight:600;">Family</label>
        <div style="display:flex;gap:8px;margin-top:6px;flex-wrap:wrap;align-items:center;">
          <select id="bs-fid" style="flex:1;min-width:240px;padding:11px;border:1px solid #E2E8F0;border-radius:8px;"><option value="">Loading families…</option></select>
          <button id="bs-load" class="kt-btn kt-btn-primary">Load</button>
        </div>
        <div id="bs-detail" style="margin-top:20px;"></div>
      </div>
    </div>`;
    // Pick a family by NAME (no more typing a raw ID).
    try {
      const fr = await Api.get('/admin/families');
      const fams = (fr && (fr.families || fr.data)) || [];
      const sel = document.getElementById('bs-fid');
      sel.innerHTML = '<option value="">— Select a family —</option>' +
        fams.map(f => `<option value="${f.id}">${esc(f.family_name || ('Family #' + f.id))}</option>`).join('');
      sel.onchange = () => { if (+sel.value) document.getElementById('bs-load').click(); };
    } catch (e) {
      document.getElementById('bs-fid').innerHTML = '<option value="">Could not load families</option>';
    }
    const _bsLoad = main.querySelector('#bs-load');
    if (_bsLoad) _bsLoad.onclick = async () => {
      const fid = +document.getElementById('bs-fid').value;
      if (!fid) { document.getElementById('bs-detail').innerHTML = '<div style="color:#B91C1C;font-size:13px;">Choose a family first.</div>'; return; }
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
      const _bsSave = main.querySelector('#bs-save');
      if (_bsSave) _bsSave.onclick = async () => {
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
