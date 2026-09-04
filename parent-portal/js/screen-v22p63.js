/* v22p63 — 8 new feature screens. */
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
  const toast = (msg, kind) => KT.toast ? KT.toast(msg, kind) : alert(msg);

  // ============================ Wellness pre-arrival screening ============================
  async function renderWellness(main) {
    main.setAttribute('data-kt-pretty', '1');
    main.innerHTML = '<div style="padding:24px;">Loading…</div>';
    const childrenRes = await Api.get('/parent/children').catch(() => ({ children: [] }));
    // /parent/children returns { children: [...] } — not { data: [...] } (that shape
    // mismatch is why Wellness showed "No children" even for families with kids).
    const children = childrenRes.children || childrenRes.data || (Array.isArray(childrenRes) ? childrenRes : []);
    if (!children.length) { main.innerHTML = '<div class="kt-card" style="margin:24px;padding:40px;text-align:center;color:#64748B;">No children.</div>'; return; }
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
        <p style="margin:-6px 0 10px;color:#64748B;font-size:12.5px;">Select any that apply — you can pick more than one.</p>
        <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(160px,1fr));gap:10px;">
          ${[
            ['fever', 'Fever 🌡', '#EF4444'],
            ['cough', 'Cough 😷', '#F59E0B'],
            ['runny_nose', 'Runny nose 🤧', '#F59E0B'],
            ['vomiting', 'Vomiting 🤢', '#EF4444'],
            ['diarrhea', 'Diarrhea 💩', '#EF4444'],
            ['rash', 'Rash 🟥', '#F59E0B'],
            ['exposure', 'Sick contact 🦠', '#EF4444'],
          ].map(([k, lbl, c]) => `<label style="position:relative;display:flex;align-items:center;gap:11px;padding:13px 14px;background:#FAFCFE;border:2px solid #E2E8F0;border-radius:12px;cursor:pointer;transition:background .12s,border-color .12s;" data-sym-wrap="${k}">
            <input type="checkbox" class="kt-sw-none" data-sym="${k}" data-color="${c}" ${today[k] ? 'checked' : ''} style="position:absolute;opacity:0;width:0;height:0;pointer-events:none;">
            <span class="ws-dot" style="flex:0 0 auto;width:22px;height:22px;border-radius:50%;border:2px solid #CBD5E1;background:#fff;display:flex;align-items:center;justify-content:center;color:#fff;font-size:13px;font-weight:900;line-height:1;transition:background .12s,border-color .12s;"></span>
            <span style="font-weight:600;">${lbl}</span></label>`).join('')}
        </div>
        <label style="display:block;font-size:13px;font-weight:600;margin-top:18px;">Notes</label>
        <textarea id="ws-notes" rows="3" style="width:100%;box-sizing:border-box;padding:11px;border:1px solid #E2E8F0;border-radius:8px;font-family:inherit;">${esc(today.notes || '')}</textarea>
        <button id="ws-submit" style="display:block;width:fit-content;margin:18px 0 0 0;min-height:0;height:38px;padding:0 24px;font-size:14px;font-weight:700;line-height:38px;border:0;border-radius:9px;cursor:pointer;background:linear-gradient(135deg,#0FA3B1,#1F6FB2 60%,#2456A6);color:#fff;">Submit screening</button>
        <div id="ws-result" style="margin-top:14px;"></div>
      </div>
    </div>`;

    // Dot-style multi-select: clicking a symptom fills its circular dot (in the
    // symptom's colour) and tints the row. Any number can be selected.
    main.querySelectorAll('input[data-sym]').forEach(cb => {
      const colour = cb.dataset.color || '#EF4444';
      const wrap = cb.closest('label');
      const dot = wrap.querySelector('.ws-dot');
      const update = () => {
        wrap.style.background = cb.checked ? (colour + '14') : '#FAFCFE';
        wrap.style.borderColor = cb.checked ? colour : '#E2E8F0';
        if (dot) {
          dot.style.background = cb.checked ? colour : '#fff';
          dot.style.borderColor = cb.checked ? colour : '#CBD5E1';
          dot.textContent = cb.checked ? '✓' : '';
        }
      };
      // The hidden checkbox is not clickable (opacity:0;pointer-events:none), so
      // toggle it ourselves when the row is clicked, then reflect the new state.
      wrap.addEventListener('click', (e) => {
        e.preventDefault();
        cb.checked = !cb.checked;
        update();
      });
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
    const _cr = await Api.get('/admin/centres').catch(() => ({})); const centres = _cr.centres || _cr.data || [];
    if (!centres.length) { main.innerHTML = '<div class="kt-card" style="margin:24px;padding:40px;text-align:center;color:#64748B;">No centres.</div>'; return; }
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
          }).join('') || '<tr><td colspan="4" style="text-align:center;padding:30px;color:#64748B;">No screenings submitted yet today.</td></tr>'}</tbody>
        </table>
      </div>
    </div>`;
  }

  // ============================ Payment schedules ============================
  async function renderPaymentPlans(main) {
    main.setAttribute('data-kt-pretty', '1');
    main.innerHTML = '<div style="padding:24px;">Loading…</div>';
    const u = JSON.parse(sessionStorage.getItem('kt_user') || '{}');
    const isStaff = Array.isArray(u.roles) && u.roles.some(r => ['agency_admin', 'centre_director', 'platform_admin'].includes(r));
    // Staff used to be met with an "Enter the family ID" prompt on arrival — and there
    // is nowhere in the app to look that number up, so the screen was unusable. Pick the
    // family by NAME instead, and remember the choice so returning here doesn't ask again.
    let r;
    let families = [];
    let fid = 0;
    let famErr = 0;
    if (isStaff) {
      try {
        const fr = await Api.get('/admin/families');
        // /admin/families answers with {families:[...]}; other endpoints use {data:[...]},
        // a bare array, or a paginated {data:{data:[...]}}. Accept all of them — assuming
        // one shape is what threw "families.map is not a function".
        families = (fr && Array.isArray(fr.families)) ? fr.families
                 : Array.isArray(fr) ? fr
                 : (fr && Array.isArray(fr.data)) ? fr.data
                 : (fr && fr.data && Array.isArray(fr.data.data)) ? fr.data.data
                 : [];
      } catch (e) { families = []; famErr = (e && e.status) || 0; }
      const remembered = +(sessionStorage.getItem('kt_pp_family') || 0);
      if (remembered && families.some(f => +f.id === remembered)) fid = remembered;
      else if (families.length === 1) fid = +families[0].id;
      r = fid ? await Api.get(`/payment-plans/family/${fid}`) : { data: [] };
    } else {
      r = await Api.get('/payment-plans/mine');
    }
    const familyName = (f) => esc(f.name || f.family_name || [f.primary_guardian_name, f.last_name].filter(Boolean).join(' ').trim() || ('Family #' + f.id));
    const picker = isStaff ? `<div class="kt-card" style="margin:0 0 16px;padding:14px 16px;display:flex;align-items:center;gap:10px;flex-wrap:wrap;">
        <label for="pp-family" style="font-weight:600;color:#334155;">Family</label>
        <select id="pp-family" style="min-width:260px;padding:6px 10px;border:1px solid #CBD5E1;border-radius:8px;background:#fff;">
          <option value="">Select a family…</option>
          ${families.map(f => `<option value="${f.id}"${+f.id === fid ? ' selected' : ''}>${familyName(f)}</option>`).join('')}
        </select>
        ${families.length ? '' : '<span style="color:#64748B;font-size:13px;">' + (famErr === 403 ? 'Select an agency using the top-bar switcher to load its families.' : 'No families found for this agency.') + '</span>'}
      </div>` : '';
    const plans = r.data || [];
    main.innerHTML = `<div style="padding:24px;max-width:1800px;margin:0 auto;">
      <div class="kt-page-hero">
        <h2>📅 Payment schedules</h2>
        <p>${isStaff && !fid ? 'Choose a family to see their payment schedules.' : plans.length + ' schedule(s). Dated instalments, each raising its own invoice on the first of its month.'}</p>
        ${isStaff ? '<div class="kt-hero-actions"><button class="kt-btn kt-btn-ghost" id="pp-new">+ New payment schedule</button></div>' : ''}
      </div>
      ${picker}
      ${plans.map(p => `<div class="kt-card">
        <div style="display:flex;justify-content:space-between;align-items:flex-start;">
          <div>
            <h3 style="margin:0;color:#0F172A;">${fmtMoney(p.total_amount)} over ${p.installment_count} instalments</h3>
            <div style="color:#475569;font-size:13px;margin-top:4px;">Status: <span class="kt-pill ${p.status === 'active' ? 'kt-pill-success' : 'kt-pill-warning'}">${esc(p.status)}</span> · created ${fmtDate(p.created_at)}</div>
          </div>
          ${isStaff && p.status === 'active' ? `<button class="kt-btn kt-btn-danger" data-cancel-plan="${p.id}">Cancel plan</button>` : ''}
        </div>
        ${scheduleNote(p.notes) ? `<p style="color:#475569;margin:10px 0 0;font-size:13px;">${esc(scheduleNote(p.notes))}</p>` : ''}
        <table style="margin-top:14px;">
          <thead><tr><th>Due date</th><th style="text-align:right;">Amount</th><th>Invoice</th><th>Status</th></tr></thead>
          <tbody>${(p.installments || []).map(i => `<tr>
            <td>${fmtDate(i.due_date)}</td>
            <td style="text-align:right;font-weight:600;">${fmtMoney(i.amount)}</td>
            <td>${invoiceCell(i)}</td>
            <td>${statusCell(i)}</td>
          </tr>`).join('')}</tbody>
        </table>
      </div>`).join('') || `<div class="kt-card" style="text-align:center;padding:60px;color:#64748B;">${isStaff && !fid ? 'Select a family above to see their payment schedules.' : 'No payment schedules on file.'}</div>`}
    </div>`;
    if (isStaff) {
      const sel = document.getElementById('pp-family');
      if (sel) sel.onchange = () => {
        if (sel.value) sessionStorage.setItem('kt_pp_family', sel.value);
        else sessionStorage.removeItem('kt_pp_family');
        renderPaymentPlans(main);
      };
      const newBtn = document.getElementById('pp-new');
      if (newBtn) newBtn.onclick = () => openPaymentPlanModal(families);
      main.querySelectorAll('button[data-cancel-plan]').forEach(b => b.onclick = () => {
        const plan = plans.filter(p => String(p.id) === String(b.dataset.cancelPlan))[0];
        if (plan) openCancelSchedule(main, plan);
      });
    }
  }
  /* ── STEP 1: what the schedule is ──────────────────────────────────
     The family is picked by NAME. It used to be a raw "Family ID" number field, and
     there is nowhere in the app to look that number up, so the form was unusable by
     the people it was for.

     The instalment COUNT is not asked for: it falls out of the range and the cadence,
     and asking for both invites the two to disagree. */
  /* The note on an imported schedule is machine plumbing, not something a person
     wrote. The [ilearn:...] token is the key the sync matches on, so it stays in the
     DATA and is only removed from what a human reads — along with the boilerplate
     around it. Anything actually typed by somebody survives. */
  function scheduleNote(notes) {
    if (!notes) return '';

    return String(notes)
      .replace(/\[ilearn:[^\]]*\]/gi, '')
      .replace(/Imported from iLearn[^.]*\.?/gi, '')
      .replace(/Authoritative paid\/unpaid status remains the invoice record\.?/gi, '')
      .replace(/\s+/g, ' ')
      .trim();
  }

  /* THE INVOICE IS THE STATUS.

     payment_plan_installments.status is what the SCHEDULE thinks, and for everything
     imported from iLearn it reads 'pending' and always will — nothing on this side ever
     moves it. The invoice is what actually happened to the money, and the imported
     schedules say so themselves: "Authoritative paid/unpaid status remains the invoice
     record."

     So the invoice wins, and the instalment's own status is used only where there is no
     invoice to ask. Shown once, in one column. */
  function statusCell(i) {
    const st = String(i.invoice_number ? (i.invoice_status || '') : (i.status || ''));
    const cls = /paid/.test(st) ? 'kt-pill-success'
      : /cancel|void/.test(st) ? 'kt-pill-warning'
      : /overdue/.test(st) ? 'kt-pill-danger'
      : 'kt-pill-info';

    return `<span class="kt-pill ${cls}">${esc(st || 'pending')}</span>`
      + (i.invoice_number ? '' : '<div style="font-size:11px;color:#94A3B8;">scheduled only</div>');
  }

  /* An imported schedule raised no invoice, and a blank cell says so more honestly
     than a placeholder would. One raised here shows its number and what it is doing. */
  function invoiceCell(i) {
    if (!i.invoice_number) return '<span style="color:#94A3B8;">\u2014</span>';
    const st = String(i.invoice_status || '');
    /* No status here. It lives in the Status column, which reads the same field — the
       two used to be printed separately and could be read as disagreeing. */
    const when = st === 'draft' && i.invoice_issues_on ? ' \u00b7 issues ' + fmtDate(i.invoice_issues_on) : '';

    /* An imported instalment is matched to its invoice by due date, and iLearn
       sometimes adjusted the invoice afterwards. Where the two disagree the
       invoiced figure is shown — that gap is the useful part of the match. */
    const differs = i.invoice_differs
      ? `<div style="font-size:11.5px;color:#B45309;">invoiced ${fmtMoney(i.invoice_total)}</div>` : '';

    return `<span style="font-weight:600;">${esc(i.invoice_number)}</span>`
      + (when ? `<div style="font-size:11.5px;color:#64748B;">${esc(when.replace(' \u00b7 ', ''))}</div>` : '')
      + differs;
  }

  function openPaymentPlanModal(families) {
    const fld = 'width:100%;padding:8px 12px;border:1px solid #E2E8F0;border-radius:8px;font-size:13px;box-sizing:border-box;font-family:inherit;';
    const lbl = 'display:block;font-size:11px;font-weight:800;text-transform:uppercase;letter-spacing:.5px;color:#64748B;margin-bottom:5px;';
    const wrap = document.createElement('div');
    const famName = (f) => esc(f.name || f.family_name || ('Family #' + f.id));
    const today = new Date();
    const firstDefault = new Date(today.getFullYear(), today.getMonth() + 1, 1);
    const lastDefault = new Date(today.getFullYear(), today.getMonth() + 6, 1);
    const ymd = (d) => d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0');

    wrap.innerHTML = `
      <label for="ps-fam" style="${lbl}">Family</label>
      <input id="ps-famq" type="search" placeholder="Search families…" style="${fld}margin-bottom:6px;">
      <select id="ps-fam" size="1" style="${fld}">
        <option value="">Select a family…</option>
        ${(families || []).map(f => `<option value="${f.id}">${famName(f)}</option>`).join('')}
      </select>
      <div style="display:flex;gap:10px;margin-top:12px;">
        <div style="flex:1;"><label for="ps-total" style="${lbl}">Total amount ($)</label>
          <input id="ps-total" type="number" step="0.01" min="0.01" style="${fld}"></div>
        <div style="flex:1;"><label for="ps-cadence" style="${lbl}">Every</label>
          <select id="ps-cadence" style="${fld}">
            <option value="monthly">Month</option><option value="biweekly">2 weeks</option><option value="weekly">Week</option>
          </select></div>
      </div>
      <div style="display:flex;gap:10px;margin-top:12px;">
        <div style="flex:1;"><label for="ps-first" style="${lbl}">First due</label>
          <input id="ps-first" type="date" value="${ymd(firstDefault)}" style="${fld}"></div>
        <div style="flex:1;"><label for="ps-last" style="${lbl}">Last due</label>
          <input id="ps-last" type="date" value="${ymd(lastDefault)}" style="${fld}"></div>
      </div>
      <label for="ps-notes" style="${lbl}margin-top:12px;">Notes (optional)</label>
      <input id="ps-notes" style="${fld}">
      <div id="ps-msg" style="margin-top:10px;font-size:12.5px;color:#B91C1C;"></div>`;

    /* Narrowing the list as you type is fine here — it only shortens a <select>,
       nothing is fetched and nothing on the page is replaced. */
    const sel = wrap.querySelector('#ps-fam');
    const all = (families || []).slice();
    wrap.querySelector('#ps-famq').addEventListener('input', function () {
      const q = this.value.trim().toLowerCase();
      const hits = q ? all.filter(f => famName(f).toLowerCase().indexOf(q) !== -1) : all;
      sel.innerHTML = '<option value="">Select a family…</option>'
        + hits.map(f => `<option value="${f.id}">${famName(f)}</option>`).join('');
    });

    KT.Shell.Modal.open({
      title: 'New payment schedule',
      body: wrap,
      actions: [
        { label: 'Cancel' },
        {
          label: 'Build the schedule', style: 'btn-primary',
          handler: function () {
            const msg = wrap.querySelector('#ps-msg');
            const familyId = +sel.value;
            const total = parseFloat(wrap.querySelector('#ps-total').value);
            const first = wrap.querySelector('#ps-first').value;
            const last = wrap.querySelector('#ps-last').value;
            const cadence = wrap.querySelector('#ps-cadence').value;
            const notes = wrap.querySelector('#ps-notes').value.trim();

            if (!familyId) { msg.textContent = 'Choose a family.'; return false; }
            if (!(total > 0)) { msg.textContent = 'Enter the total amount.'; return false; }
            if (!first || !last) { msg.textContent = 'Both a first and a last due date are needed.'; return false; }
            if (last < first) { msg.textContent = 'The last due date is before the first.'; return false; }

            const rows = buildSchedule(first, last, cadence, total);
            if (!rows.length) { msg.textContent = 'That range does not contain a single due date.'; return false; }

            const fam = all.filter(f => +f.id === familyId)[0];
            setTimeout(() => openScheduleReview(familyId, famName(fam || { id: familyId }), total, notes, rows), 60);

            return true;
          },
        },
      ],
    });
    setTimeout(() => { const q = wrap.querySelector('#ps-famq'); if (q) q.focus(); }, 60);
  }

  /* Dates are stepped on their own numbers, never through a timezone: these are
     wall-clock DAYS, and a Date parsed from a string comes back the day before in any
     zone behind UTC. */
  function buildSchedule(firstYmd, lastYmd, cadence, total) {
    const p = firstYmd.split('-').map(Number);
    const end = lastYmd;
    const out = [];
    let d = new Date(p[0], p[1] - 1, p[2]);
    const ymd = (x) => x.getFullYear() + '-' + String(x.getMonth() + 1).padStart(2, '0') + '-' + String(x.getDate()).padStart(2, '0');

    for (let guard = 0; guard < 120 && ymd(d) <= end; guard++) {
      out.push({ due_date: ymd(d), amount: 0 });
      if (cadence === 'weekly') d = new Date(d.getFullYear(), d.getMonth(), d.getDate() + 7);
      else if (cadence === 'biweekly') d = new Date(d.getFullYear(), d.getMonth(), d.getDate() + 14);
      else d = new Date(d.getFullYear(), d.getMonth() + 1, d.getDate());
    }
    // Even split, with the remainder on the last line so the rows sum to the total.
    const per = Math.round((total / out.length) * 100) / 100;
    out.forEach((r, i) => {
      r.amount = i === out.length - 1
        ? Math.round((total - per * (out.length - 1)) * 100) / 100
        : per;
    });

    return out;
  }

  /* ── STEP 2: check it before it exists ─────────────────────────────
     Every date and amount is editable, rows can be added or removed, and the running
     total is compared with what was asked for. Nothing has been persisted — the draft
     lives here until Save, so abandoning it leaves nothing behind. */
  function openScheduleReview(familyId, familyLabel, intendedTotal, notes, rows) {
    const wrap = document.createElement('div');
    const money = (n) => '$' + (Number(n) || 0).toLocaleString('en-CA', { minimumFractionDigits: 2, maximumFractionDigits: 2 });

    function paint() {
      const sum = rows.reduce((a, r) => a + (Number(r.amount) || 0), 0);
      const diff = Math.round((sum - intendedTotal) * 100) / 100;
      wrap.innerHTML = `
        <p style="margin:0 0 12px;font-size:13.5px;color:#334155;line-height:1.6;">
          <strong>${esc(familyLabel)}</strong> — ${rows.length} instalment(s). Correct any date or
          amount before saving. Each line raises its own invoice, issued on the first of the
          month it falls due in.</p>
        <div style="max-height:46vh;overflow:auto;border:1px solid #E2E8F0;border-radius:10px;">
        <table style="width:100%;border-collapse:collapse;font-size:13px;">
          <thead><tr style="background:#F8FAFC;">
            <th style="text-align:left;padding:8px 10px;font-size:10px;font-weight:800;color:#64748B;text-transform:uppercase;">#</th>
            <th style="text-align:left;padding:8px 10px;font-size:10px;font-weight:800;color:#64748B;text-transform:uppercase;">Due date</th>
            <th style="text-align:right;padding:8px 10px;font-size:10px;font-weight:800;color:#64748B;text-transform:uppercase;">Amount</th>
            <th style="text-align:left;padding:8px 10px;font-size:10px;font-weight:800;color:#64748B;text-transform:uppercase;">Invoice issues</th>
            <th></th></tr></thead>
          <tbody>${rows.map((r, i) => `<tr>
            <td style="padding:6px 10px;border-top:1px solid #F1F5F9;color:#64748B;">${i + 1}</td>
            <td style="padding:6px 10px;border-top:1px solid #F1F5F9;">
              <input data-d="${i}" type="date" value="${r.due_date}" style="padding:5px 8px;border:1px solid #E2E8F0;border-radius:6px;font-size:13px;font-family:inherit;"></td>
            <td style="padding:6px 10px;border-top:1px solid #F1F5F9;text-align:right;">
              <input data-a="${i}" type="number" step="0.01" min="0" value="${r.amount}" style="width:110px;padding:5px 8px;border:1px solid #E2E8F0;border-radius:6px;font-size:13px;text-align:right;font-family:inherit;"></td>
            <td style="padding:6px 10px;border-top:1px solid #F1F5F9;color:#64748B;font-size:12px;">${issueLabel(r.due_date)}</td>
            <td style="padding:6px 10px;border-top:1px solid #F1F5F9;text-align:right;">
              <button type="button" data-x="${i}" title="Remove this instalment" style="border:1px solid #FECACA;background:#fff;color:#B91C1C;border-radius:6px;padding:3px 8px;font-size:12px;cursor:pointer;">Remove</button></td>
          </tr>`).join('')}</tbody>
        </table></div>
        <div style="display:flex;align-items:center;gap:12px;margin-top:10px;flex-wrap:wrap;">
          <button type="button" id="ps-add" style="border:1px solid #CBD5E1;background:#fff;color:#334155;border-radius:8px;padding:6px 12px;font-size:12.5px;font-weight:700;cursor:pointer;">+ Add instalment</button>
          <span style="margin-left:auto;font-size:13px;color:#334155;">Schedule total <strong>${money(sum)}</strong>
          ${Math.abs(diff) > 0.005
            ? `<span style="color:#B45309;"> — ${diff > 0 ? money(diff) + ' over' : money(-diff) + ' under'} the ${money(intendedTotal)} entered</span>`
            : '<span style="color:#16A34A;"> — matches</span>'}</span>
        </div>
        <div id="ps-rmsg" style="margin-top:10px;font-size:13px;color:#B91C1C;"></div>`;

      wrap.querySelectorAll('input[data-d]').forEach(el => el.addEventListener('change', function () {
        rows[+this.dataset.d].due_date = this.value; paint();
      }));
      wrap.querySelectorAll('input[data-a]').forEach(el => el.addEventListener('change', function () {
        rows[+this.dataset.a].amount = Math.round((parseFloat(this.value) || 0) * 100) / 100; paint();
      }));
      wrap.querySelectorAll('button[data-x]').forEach(el => el.addEventListener('click', function () {
        rows.splice(+this.dataset.x, 1); paint();
      }));
      const add = wrap.querySelector('#ps-add');
      if (add) add.addEventListener('click', function () {
        const last = rows[rows.length - 1];
        const base = last ? last.due_date.split('-').map(Number) : [new Date().getFullYear(), new Date().getMonth() + 1, 1];
        const nd = new Date(base[0], base[1], base[2]);
        rows.push({ due_date: nd.getFullYear() + '-' + String(nd.getMonth() + 1).padStart(2, '0') + '-' + String(nd.getDate()).padStart(2, '0'), amount: 0 });
        paint();
      });
    }

    function issueLabel(ymdStr) {
      const p = String(ymdStr).split('-');
      if (p.length !== 3) return '—';
      const MON = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
      return '1 ' + (MON[Number(p[1]) - 1] || '?') + ' ' + p[0];
    }

    paint();

    KT.Shell.Modal.open({
      title: 'Check the schedule before saving',
      body: wrap,
      large: true,
      actions: [
        { label: 'Cancel' },
        {
          label: 'Save schedule', style: 'btn-primary', busyLabel: 'Saving…',
          handler: function () {
            const msg = wrap.querySelector('#ps-rmsg');
            if (!rows.length) { msg.textContent = 'A schedule needs at least one instalment.'; return false; }
            if (rows.some(r => !r.due_date)) { msg.textContent = 'Every instalment needs a due date.'; return false; }
            if (rows.some(r => !(Number(r.amount) > 0))) { msg.textContent = 'Every instalment needs an amount above zero.'; return false; }

            return Api.post('/payment-plans', {
              family_id: familyId,
              notes: notes || null,
              installments: rows.map(r => ({ due_date: r.due_date, amount: Number(r.amount) })),
            }).then(function () {
              toast(rows.length + ' instalment(s) scheduled', 'success');
              renderPaymentPlans(document.querySelector('#appMain') || document.querySelector('main'));

              return true;
            }).catch(function (e) {
              const why = (e && e.data && e.data.message) || (e && e.message) || 'It could not be saved.';
              msg.textContent = why;
              throw new Error(why);
            });
          },
        },
      ],
    });
  }

  /* ── CANCELLING: what goes, and what stays ─────────────────────────
     It used to withdraw every unissued invoice on the schedule. A family may have
     already promised October's payment, and that invoice should not vanish because the
     schedule after it was cancelled — so each one is listed and chosen.

     Drafts are ticked by default, because withdrawing them is the usual intent. An
     ISSUED invoice is listed but cannot be ticked: it has been seen by the family and
     may have been paid against, and undoing that is a deliberate void on the invoice
     itself, never a side effect of cancelling a schedule. */
  function openCancelSchedule(main, plan) {
    const inst = ((plan && plan.installments) || []).filter(i => i.invoice_number && i.status !== 'cancelled');
    const drafts = inst.filter(i => i.invoice_status === 'draft');
    const issued = inst.filter(i => i.invoice_status && i.invoice_status !== 'draft' && i.invoice_status !== 'void');
    const th = 'padding:8px 10px;text-align:left;font-size:10px;font-weight:800;color:#64748B;text-transform:uppercase;';
    const td = 'padding:7px 10px;border-top:1px solid #F1F5F9;';

    const wrap = document.createElement('div');
    wrap.innerHTML =
      '<p style="margin:0 0 12px;font-size:13.5px;color:#334155;line-height:1.6;">'
      + 'Cancelling ends the schedule and stops every pending instalment. Choose what should '
      + 'happen to the invoices it raised.</p>'
      + (inst.length
        ? '<div style="max-height:40vh;overflow:auto;border:1px solid #E2E8F0;border-radius:10px;">'
          + '<table style="width:100%;border-collapse:collapse;font-size:13px;"><thead><tr style="background:#F8FAFC;">'
          + `<th style="${th}">Withdraw</th><th style="${th}">Invoice</th><th style="${th}">Due</th>`
          + `<th style="${th}text-align:right;">Amount</th><th style="${th}">State</th></tr></thead><tbody>`
          + inst.map(i => {
            const isDraft = i.invoice_status === 'draft';
            return '<tr>'
              + `<td style="${td}"><input type="checkbox" data-inv="${i.invoice_id}" `
              + (isDraft ? 'checked' : 'disabled') + ' style="width:16px;height:16px;"></td>'
              + `<td style="${td}">${esc(i.invoice_number)}</td>`
              + `<td style="${td}">${fmtDate(i.due_date)}</td>`
              + `<td style="${td}text-align:right;font-weight:600;">${fmtMoney(i.amount)}</td>`
              + `<td style="${td}color:${isDraft ? '#64748B' : '#166534'};">${esc(i.invoice_status || '')}`
              + (isDraft ? '' : ' \u2014 already with the family') + '</td></tr>';
          }).join('')
          + '</tbody></table></div>'
          + (issued.length
            ? '<p style="margin:10px 0 0;font-size:12.5px;color:#B45309;">' + issued.length
              + ' invoice(s) have already been issued and cannot be withdrawn here \u2014 a payment may '
              + 'already be on its way. Void one deliberately from Accounting if that is really intended.</p>'
            : '')
          + (drafts.length ? ''
            : '<p style="margin:10px 0 0;font-size:12.5px;color:#64748B;">Nothing here is still unissued, '
              + 'so nothing will be withdrawn.</p>')
        : '<p style="margin:0;font-size:13px;color:#64748B;">This schedule raised no invoices, so there is '
          + 'nothing to withdraw.</p>')
      + '<div id="ps-cmsg" style="margin-top:10px;font-size:13px;color:#B91C1C;"></div>';

    KT.Shell.Modal.open({
      title: 'Cancel this payment schedule?',
      body: wrap,
      large: true,
      actions: [
        { label: 'Keep the schedule' },
        {
          label: 'Cancel the schedule', style: 'btn-danger', busyLabel: 'Cancelling\u2026',
          handler: function () {
            const ids = Array.prototype.slice
              .call(wrap.querySelectorAll('input[data-inv]:checked'))
              .map(el => +el.getAttribute('data-inv'));

            return Api.post('/payment-plans/' + plan.id + '/cancel', { void_invoice_ids: ids })
              .then(function (r) {
                toast('Schedule cancelled \u2014 ' + ((r && r.invoices_withdrawn) || 0) + ' invoice(s) withdrawn', 'success');
                renderPaymentPlans(main);

                return true;
              })
              .catch(function (e) {
                const why = (e && e.data && e.data.message) || (e && e.message) || 'It could not be cancelled.';
                wrap.querySelector('#ps-cmsg').textContent = why;
                throw new Error(why);
              });
          },
        },
      ],
    });
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
          </tr>`).join('') || '<tr><td colspan="6" style="text-align:center;padding:40px;color:#64748B;">No workflows yet.</td></tr>'}</tbody>
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
      <div style="background:linear-gradient(135deg,#1F6080,#3a86ad);color:#fff;padding:16px 18px;display:flex;align-items:flex-start;justify-content:space-between;gap:8px;">
        <div>
          <div style="font-weight:700;font-size:16px;">💬 Ask the centre</div>
          <div style="font-size:12px;opacity:.85;">Answers from your centre's policies + handbook.</div>
        </div>
        <button id="kt-chatbot-close" title="Close" aria-label="Close chat"
          style="background:rgba(255,255,255,.18);color:#fff;border:0;width:30px;height:30px;min-width:30px;border-radius:50%;cursor:pointer;font-size:16px;line-height:1;display:flex;align-items:center;justify-content:center;transition:background .15s;"
          onmouseover="this.style.background='rgba(255,255,255,.32)'" onmouseout="this.style.background='rgba(255,255,255,.18)'">✕</button>
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
    panel.querySelector('#kt-chatbot-close').onclick = () => panel.remove();
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
      pending.style.cssText = 'background:#fff;padding:12px 14px;border-radius:14px 14px 14px 4px;max-width:80%;margin-bottom:10px;font-size:13.5px;color:#64748B;box-shadow:0 1px 3px rgba(15,23,42,.06);';
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
