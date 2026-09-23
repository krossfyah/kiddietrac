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
  /* A DATE-ONLY STRING IS A DAY, NOT AN INSTANT (2026-09-17).

     `new Date('2026-09-01')` is parsed as UTC MIDNIGHT, and toLocaleDateString then
     reads it back in the browser's zone — so west of Greenwich every plain date came
     out a day early. The confirm dialog offered to issue an invoice "scheduled for
     Aug 31" that the server had dated 1 September, and the table's issue dates were
     wrong by a day in the same direction.

     Splitting the string and building a LOCAL date keeps a day a day. Anything that
     carries a time is a real instant and is left alone. */
  const fmtDate = (s) => {
    if (!s) return '';
    const m = /^(\d{4})-(\d{2})-(\d{2})$/.exec(String(s).trim());
    const d = m ? new Date(+m[1], +m[2] - 1, +m[3]) : new Date(s);
    if (isNaN(d)) return '';
    return d.toLocaleDateString(undefined, { year: 'numeric', month: 'short', day: 'numeric' });
  };
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
        <p>${isStaff && !fid ? 'Choose a family to see their payment schedules.' : plans.length + ' schedule(s). Dated instalments, each raising its own invoice shortly before it falls due.'}</p>
        ${isStaff ? '<div class="kt-hero-actions"><button class="kt-btn kt-btn-ghost" id="pp-new">+ New payment schedule</button></div>' : ''}
      </div>
      ${picker}
      ${plans.map(p => `<div class="kt-card">
        <div style="display:flex;justify-content:space-between;align-items:flex-start;">
          <div>
            <h3 style="margin:0;color:#0F172A;">${fmtMoney(p.total_amount)} over ${p.installment_count} instalments</h3>
            <div style="color:#475569;font-size:13px;margin-top:4px;">Status: <span class="kt-pill ${p.status === 'active' ? 'kt-pill-success' : 'kt-pill-warning'}">${esc(p.status)}</span> · created ${fmtDate(p.created_at)}${p.created_by_name ? ' by ' + esc(p.created_by_name) : ''}</div>
          </div>
          ${isStaff && p.status === 'active' ? `<div style="display:flex;gap:8px;flex-wrap:wrap;">
            <button class="kt-btn" data-edit-plan="${p.id}">Edit schedule</button>
            <button class="kt-btn kt-btn-danger" data-cancel-plan="${p.id}">Cancel plan</button>
          </div>` : ''}
          ${'' /* Edit schedule is also in every row's kebab; it stays here because
                  editing is a whole-schedule act and a reader looking for it looks at
                  the schedule, not at one of its rows. */}
        </div>
        ${scheduleNote(p.notes) ? `<p style="color:#475569;margin:10px 0 0;font-size:13px;">${esc(scheduleNote(p.notes))}</p>` : ''}
        <table class="pp-table" style="margin-top:12px;width:100%;border-collapse:collapse;">
          <thead><tr>
            <th style="${PP_TH}">Due date</th>
            <th style="${PP_TH}text-align:right;">Amount</th>
            <th style="${PP_TH}">Invoice</th>
            <th style="${PP_TH}">Issued</th>
            <th style="${PP_TH}">Issued by</th>
            <th style="${PP_TH}">Status</th>
            <th style="${PP_TH}text-align:right;">Actions</th>
          </tr></thead>
          <tbody>${(p.installments || []).map(i => `<tr data-inst="${i.id}" data-plan="${p.id}"
            data-inv="${i.invoice_id || ''}" data-num="${esc(String(i.invoice_number || ''))}"
            data-st="${esc(String(i.invoice_status || ''))}">
            <td style="${PP_TD}white-space:nowrap;">${fmtDate(i.due_date)}</td>
            <td style="${PP_TD}text-align:right;font-weight:600;white-space:nowrap;">${fmtMoney(i.amount)}</td>
            <td style="${PP_TD}">${invoiceCell(i)}</td>
            <td style="${PP_TD}white-space:nowrap;">${issuedCell(i)}</td>
            <td style="${PP_TD}white-space:nowrap;">${issuedByCell(i, p.created_by_name)}</td>
            <td style="${PP_TD}white-space:nowrap;">${statusCell(i)}</td>
            <td style="${PP_TD}white-space:nowrap;text-align:right;">${actionsCell(i, p.id)}</td>
          </tr>`).join('')}</tbody>
        </table>
      </div>`).join('') || `<div class="kt-card" style="text-align:center;padding:60px;color:#64748B;">${isStaff && !fid ? 'Select a family above to see their payment schedules.' : 'No payment schedules on file.'}</div>`}
    </div>`;
    plansRef.list = plans || [];
    ppDenseStyle();
    /* kt-row-actions.js sweeps on its own schedule and this screen paints after an
       await, so it has already run by the time these rows exist. Nudging it is the
       documented step for an async render. */
    if (window.KT && KT.sweepRowActions) { setTimeout(KT.sweepRowActions, 0); }
    /* And the table primitives (bulk select, pagination). Picking a family repaints this
       table without a hashchange, so the global sweep would not otherwise see it. */
    if (window.KT && KT.sweepTables) { setTimeout(KT.sweepTables, 0); }

    /* BULK ACTIONS ON THE SELECTED ROWS (2026-09-17).

       Anthony: "when selecting multiple rows under payment schedule have an option to
       issue all now, delete all, download all."

       Registered on the table; kt-polish-v2's selection bar renders them. Each one runs
       the SAME endpoint the row's own menu item runs, one row at a time, rather than a
       bulk endpoint that would need its own guards and could drift from the single-row
       rules — an instalment that cannot be issued alone must not become issuable because
       it was selected with six others.

       Every action reports what it actually did, including what it refused and why:
       "4 issued, 2 skipped" is the truth; a bare "Done" would hide a refusal. */
    (function () {
      const table = main.querySelector('table.pp-table');
      if (!table || !staffNow()) { return; }

      const ids = (rows, want) => rows.map(function (r) {
        const st = String(r.getAttribute('data-st') || '').toLowerCase();
        if (want === 'draft' && st !== 'draft') { return null; }
        if (want === 'invoice' && !r.getAttribute('data-inv')) { return null; }
        return {
          inst: r.getAttribute('data-inst'),
          plan: r.getAttribute('data-plan'),
          inv: r.getAttribute('data-inv'),
          num: r.getAttribute('data-num') || 'invoice',
        };
      }).filter(Boolean);

      table.ktBulkActions = [
        {
          label: 'Issue now',
          run: async function (rows) {
            const targets = ids(rows, 'draft');
            const skipped = rows.length - targets.length;
            if (!targets.length) {
              toast('None of those are still pending \u2014 nothing to issue.', 'error');
              return;
            }
            const ok = await KT.confirm({
              title: 'Issue ' + targets.length + ' invoice(s) now?',
              description: 'They become owed by the family straight away and start counting towards '
                + 'due and overdue reminders. Due dates do not change, and no email is sent.'
                + (skipped ? ' ' + skipped + ' selected row(s) are already issued and will be left alone.' : ''),
              okLabel: 'Issue ' + targets.length,
            });
            if (!ok) { return; }
            let done = 0, failed = 0;
            for (const t of targets) {
              try { await Api.post('/director/invoices/' + t.inv + '/issue', {}); done++; }
              catch (e) { failed++; }
            }
            toast(done + ' issued' + (failed ? ', ' + failed + ' failed' : '')
              + (skipped ? ', ' + skipped + ' already issued' : ''), failed ? 'error' : 'success');
            renderPaymentPlans(main);
          },
        },
        {
          label: 'Download',
          run: async function (rows) {
            const targets = ids(rows, 'invoice');
            if (!targets.length) { toast('None of those have an invoice yet.', 'error'); return; }
            let tok = '';
            try { tok = sessionStorage.getItem('kt_token') || localStorage.getItem('kt_token') || ''; } catch (e) {}
            const headers = { Authorization: 'Bearer ' + tok };
            try { const ag = sessionStorage.getItem('kt_active_agency_id'); if (ag) { headers['X-Active-Agency-Id'] = ag; } } catch (e) {}
            let done = 0, failed = 0;
            for (const t of targets) {
              try {
                const res = await fetch(KT.API_BASE + '/invoices/' + t.inv + '/pdf', { headers });
                if (!res.ok) { throw new Error('no pdf'); }
                const url = URL.createObjectURL(await res.blob());
                const a = document.createElement('a');
                a.href = url; a.download = t.num + '.pdf';
                document.body.appendChild(a); a.click(); a.remove();
                setTimeout(function () { URL.revokeObjectURL(url); }, 4000);
                done++;
                /* One at a time, with a breath between: a browser that is handed ten
                   downloads in the same tick blocks all but the first. */
                await new Promise(function (r) { setTimeout(r, 350); });
              } catch (e) { failed++; }
            }
            toast(done + ' downloaded' + (failed ? ', ' + failed + ' could not be rendered' : ''),
              failed ? 'error' : 'success');
          },
        },
        {
          label: 'Delete',
          danger: true,
          run: async function (rows) {
            const targets = ids(rows, 'draft');
            const skipped = rows.length - targets.length;
            if (!targets.length) {
              toast('Only pending instalments can be deleted. Void an issued one from Accounting.', 'error');
              return;
            }
            const ok = await KT.confirm({
              title: 'Delete ' + targets.length + ' instalment(s)?',
              description: 'They are removed from this schedule and their draft invoices withdrawn. '
                + 'Nothing was ever sent to the family, so nobody is told. The schedule total is '
                + 'recalculated. This cannot be undone.'
                + (skipped ? ' ' + skipped + ' selected row(s) have already been issued and will be '
                  + 'left alone \u2014 void those from Accounting.' : ''),
              okLabel: 'Delete ' + targets.length,
              tone: 'danger',
            });
            if (!ok) { return; }
            let done = 0, failed = 0;
            for (const t of targets) {
              try { await Api.del('/payment-plans/' + t.plan + '/installments/' + t.inst); done++; }
              catch (e) { failed++; }
            }
            toast(done + ' deleted' + (failed ? ', ' + failed + ' refused' : '')
              + (skipped ? ', ' + skipped + ' already issued' : ''), failed ? 'error' : 'success');
            renderPaymentPlans(main);
          },
        },
      ];
    })();
    wireInvoiceActions(main);
    /* Edit opens the review view straight away, filled with the instalments that can
       still change: pending, and either not yet invoiced or invoiced only as a draft.
       An issued instalment has been seen by the family, so it is counted and kept rather
       than offered for editing — the same line the server draws. */
    main.querySelectorAll('[data-edit-plan]').forEach(function (b) {
      b.addEventListener('click', function () {
        const plan = (plans || []).filter(x => String(x.id) === b.getAttribute('data-edit-plan'))[0];
        if (!plan) { return; }
        const all = plan.installments || [];
        const editable = all.filter(i => i.status === 'pending'
          && (!i.invoice_number || String(i.invoice_status || '').toLowerCase() === 'draft'));
        const kept = all.filter(i => i.status !== 'cancelled').length - editable.length;
        if (!editable.length) {
          toast('Every instalment on this schedule has already been issued — nothing left to edit.', 'error');
          return;
        }
        const rows = editable.map(i => ({ due_date: String(i.due_date).slice(0, 10), amount: Number(i.amount) }));
        const label = (plan.family_name || plan.family || 'This family');
        openScheduleReview(plan.family_id, label,
          rows.reduce((a, r) => a + r.amount, 0), scheduleNote(plan.notes) || '', rows, plan.id, kept);
      });
    });
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
  /* THE WORDS A SCHEDULE USES (2026-09-17).

     Anthony: "when payment schedules are issued the invoices sent to the parent should
     show as issued/sent in the status and the ones that are not due just yet should show
     as pending."

     "DRAFT" was the wrong word on this screen. Everywhere else in the portal a draft is
     an unfinished document somebody is composing; here it is a future instalment that
     the schedule will raise on its own, entirely deliberate, and calling it a draft made
     seven perfectly correct rows look like seven unfinished ones. PENDING says what it
     is: waiting its turn.

     Deliberately NOT the portal-wide "Scheduled" ([[kiddietrac-invoice-scheduled-status]]).
     That word answers "is this owed yet?" on the ledgers, and it is reserved for an
     invoice that HAS been issued and is not yet due. This column answers a different
     question - has it gone out? - so Accounting keeps Scheduled and the schedule says
     Issued. Overdue, Paid and Void are the same word everywhere, because they mean the
     same thing everywhere.

     OVERDUE IS DERIVED, NEVER STORED. Nothing about the invoice changes when its due
     date passes, only its relation to today, and a stored status would need yet another
     nightly job to flip it. Compared as date-only text against the AGENCY's today, since
     a due date is a calendar day and not an instant. */
  function statusCell(i) {
    /* THE SAME PILL AS ACCOUNTING (2026-09-17). Anthony: "use colourized pills for status
       on accounting and payment schedules to make it easier to read."

       This screen drew its own: kt-pill classes for most states and a hand-rolled slate
       span for Pending, so the column mixed two shapes and Accounting showed a third.
       KT.invoicePill (kt-polish.js) is the one engine now, sitting beside the
       KT.invoiceStatus that already owned the vocabulary.

       An instalment with no invoice is a different thing from an invoice with a status —
       nothing has been raised at all — so it keeps its own quiet pill and its caption. */
    if (!i.invoice_number) {
      return (window.KT && KT.invoicePill ? KT.invoicePill(i.status || 'pending', null) : '')
        + '<div style="font-size:11px;color:#94A3B8;margin-top:2px;">scheduled only</div>';
    }

    /* The DUE date is passed so "issued but not yet due" resolves to Scheduled rather
       than Issued — derived in one place, never stored. */
    return (window.KT && KT.invoicePill)
      ? KT.invoicePill(i.invoice_status, i.invoice_due_at || i.due_date)
      : '<span class="kt-pill kt-pill-info">' + esc(String(i.invoice_status || '')) + '</span>';
  }

  /* ONE MENU PER ROW, NOT A BAR OF BUTTONS (2026-09-17).

     Anthony: "use kebab to access view invoice, issue now, resend, edit schedule,
     download invoice." Five controls inline would be wider than the figures they sit
     beside, and the portal already has one answer for this: kt-row-actions.js collapses
     the buttons in a row's LAST cell behind the standard kebab. So this draws plain
     labelled buttons and lets that sweep collapse them. Hand-rolling a kebab here is the
     mistake CONVENTIONS.md names, and kt-row-actions would then wrap its own menu around
     ours - a menu whose only item is another menu.

     The BUTTON TEXT becomes the menu item, so every one is labelled; a bare glyph would
     render as a blank row in the menu.

     Only what applies to THIS row appears. Issue now exists solely on a draft - there is
     nothing to issue on one already sent - and Resend only once there is something a
     family could have received. A parent sees View and Download and nothing that
     changes anything. */
  function actionsCell(i, planId) {
    const st = String(i.invoice_status || '');
    const staff = staffNow();
    const id = i.invoice_id ? esc(String(i.invoice_id)) : '';
    const num = esc(String(i.invoice_number || ''));
    const out = [];

    if (i.invoice_id) {
      out.push('<button type="button" class="kt-act-icon kt-act-info" data-inv-view="' + id + '">View invoice</button>');
    } else if (i.external_invoice_id) {
      out.push('<button type="button" class="kt-act-icon kt-act-info" data-inv-ext="'
        + esc(String(i.external_invoice_id)) + '">View invoice</button>');
    }
    if (staff && i.invoice_id && st === 'draft') {
      out.push('<button type="button" class="kt-act-icon kt-act-teal" data-inv-issue="' + id + '"'
        + ' data-inv-num="' + num + '" data-inv-on="' + esc(String(i.invoice_issues_on || '')) + '">Issue now</button>');
    }
    if (staff && i.invoice_id && st !== 'draft') {
      out.push('<button type="button" class="kt-act-icon kt-act-edit" data-inv-resend="' + id + '"'
        + ' data-inv-num="' + num + '" data-plan="' + esc(String(planId)) + '">Resend</button>');
    }
    if (i.invoice_id) {
      out.push('<button type="button" class="kt-act-icon kt-act-info" data-inv-pdf="' + id + '"'
        + ' data-inv-num="' + num + '">Download invoice</button>');
    }
    /* EDIT THIS ROW, NOT THE WHOLE PLAN (2026-09-17).

       Anthony: "the actions where you can edit the schedule should only allow you to
       edit the individual schedule and not all." This used to open the whole-plan
       editor, which withdraws and re-raises every unissued instalment — so correcting
       one amount renumbered all the invoices after it. The plan-wide editor is still
       one click away on the card above, where it belongs.

       Only on an unissued row: once an invoice has gone out, the way to change it is to
       void it and add a replacement, which is what the next item is for. */
    if (staff && st === 'draft') {
      out.push('<button type="button" class="kt-act-icon kt-act-edit" data-edit-inst="' + esc(String(i.id))
        + '" data-plan="' + esc(String(planId)) + '" data-due="' + esc(String(i.due_date || '').slice(0, 10))
        + '" data-amt="' + esc(String(i.amount || 0)) + '" data-inv-num="' + num + '">Edit instalment</button>');
    }
    /* VOID IS NOT HERE ANY MORE (2026-09-17). Anthony: "under accounting you need to
       have download invoice, void and remove the void function under payment schedules."
       Voiding withdraws a document and takes money off a family's balance — an accounting
       act, belonging where the ledger is read rather than on the screen for planning
       instalments. It lives in Accounting's row menu now. */

    return out.join('') || '<span style="color:#94A3B8;">—</span>';
  }

  /* ISSUED, AND ISSUED BY (2026-09-17).

     Anthony: "add a issued column to the payment schedules table and issued by as well."

     These are two different facts and the table now separates them. A DRAFT has not been
     issued at all, so its date is shown as what it is — a future promise, greyed and
     labelled — rather than as an event that happened. Only once it is out does the
     column state a date plainly.

     "Issued by" reads the person who pressed Issue now. Null is not missing data: it
     means no person did it, so it says "Automatic" for anything the 06:00 run or an
     import issued, and a blank dash only while there is nothing to attribute. */
  function issuedCell(i) {
    const st = String(i.invoice_status || '');
    if (!i.invoice_number || !i.invoice_issues_on) { return '<span style="color:#94A3B8;">—</span>'; }
    if (st === 'draft') {
      return '<span style="color:#94A3B8;">' + esc(fmtDate(i.invoice_issues_on)) + '</span>'
        + '<span style="font-size:10.5px;color:#B45309;margin-left:6px;">scheduled</span>';
    }
    return '<span style="color:#334155;">' + esc(fmtDate(i.invoice_issues_on)) + '</span>';
  }

  function issuedByCell(i, planAuthor) {
    const st = String(i.invoice_status || '');
    const author = String(planAuthor || '').trim();

    if (!i.invoice_number) { return '<span style="color:#94A3B8;">—</span>'; }

    /* A PENDING ROW STILL HAS SOMEBODY BEHIND IT (2026-09-17).

       Anthony: "the payment schedule created for sanford family was done by Safia but it
       doesnt show up under the issued by column." It showed a dash, because nothing on
       that schedule has been issued yet and this column reports who put an invoice OUT.
       Correct, and unhelpful: the question being asked is who is responsible for this
       money, and the answer exists - whoever built the schedule.

       Greyed and qualified, so it still reads as "not yet" rather than as a claim that
       Safia has already issued it. */
    if (st === 'draft') {
      return author
        ? '<span style="color:#94A3B8;" title="Not issued yet — scheduled by ' + esc(author) + '">'
          + esc(author) + '</span>'
        : '<span style="color:#94A3B8;">—</span>';
    }

    const who = String(i.invoice_issued_by || '').trim();
    if (who) { return '<span style="color:#334155;">' + esc(who) + '</span>'; }
    /* "Automatic" means OUR 06:00 run issued it. An instalment matched to an imported
       iLearn invoice was never issued by anything here, and claiming our cron did it
       would be a small lie in a column whose whole job is attribution. */
    if (i.external_invoice_id || i.invoice_matched_by) {
      return '<span style="color:#64748B;">Imported</span>';
    }
    /* Issued before issued_by_user_id existed, or by a run that predates the stamp.
       The schedule's author is still the right answer. */
    if (author) { return '<span style="color:#64748B;">' + esc(author) + '</span>'; }
    return '<span style="color:#64748B;">Automatic</span>';
  }

  /* An instalment's own invoice, named. Everything that DOES something has moved to the
     row's kebab, and the issue date to its own column, so this cell is now the number
     and - where the two disagree - what was actually invoiced.

     An imported instalment is matched to its invoice by due date, and iLearn sometimes
     adjusted the invoice afterwards. Where they differ the invoiced figure is shown:
     that gap is the useful part of the match. */
  function invoiceCell(i) {
    if (!i.invoice_number) return '<span style="color:#94A3B8;">—</span>';
    const differs = i.invoice_differs
      ? `<span style="font-size:11px;color:#B45309;margin-left:8px;white-space:nowrap;">invoiced ${fmtMoney(i.invoice_total)}</span>` : '';
    return `<span style="font-weight:600;white-space:nowrap;">${esc(i.invoice_number)}</span>` + differs;
  }

  /* A DENSE TABLE, BECAUSE A SCHEDULE IS A LIST OF DATES AND AMOUNTS (2026-09-17).

     Anthony: "the payment schedules table has alot of white space and should be showing
     things more easily." It inherited the portal's roomy default padding and then made
     it worse: the invoice cell stacked a number, an issue date, a variance line and a
     button on four separate rows, so seven instalments filled a screen and a half.

     Rows are compact, dates and amounts never wrap, and the button sits on the same line
     as the invoice number rather than beneath it. Nothing is hidden — the same facts, in
     a third of the height. */
  /* AN INLINE STYLE CANNOT WIN THIS ONE.

     The compact padding above was written inline and computed at 14px 16px anyway: the
     portal's table stylesheet sets cell padding with !important, and an !important
     declaration beats a non-important inline style. Same trap as the gear that would not
     move — the value was right and the cascade threw it away. So the rule is injected
     once, with !important of its own, scoped to this table so no other list changes.
     (2026-09-17) */
  function ppDenseStyle() {
    if (document.getElementById('kt-pp-dense')) { return; }
    const st = document.createElement('style');
    st.id = 'kt-pp-dense';
    /* The rule to beat is `[data-kt-pretty] table tbody td` with !important — found by
       asking the cascade rather than guessing, because a plain `.pp-table td` lost to it
       on specificity even with !important of its own. Matching its shape and adding the
       class outranks it; the bare selectors stay for a screen that has not been stamped
       data-kt-pretty yet. */
    st.textContent =
      '[data-kt-pretty] table.pp-table thead th,.pp-table th'
      + '{padding:7px 10px !important;line-height:1.3 !important;}'
      + '[data-kt-pretty] table.pp-table tbody td,.pp-table td'
      + '{padding:6px 10px !important;line-height:1.4 !important;font-size:13px !important;}'
      + '[data-kt-pretty] table.pp-table tbody tr,.pp-table tr{height:auto !important;}'
      + '[data-kt-pretty] table.pp-table tbody td button,.pp-table td button'
      + '{min-height:0 !important;height:auto !important;padding:3px 10px !important;}';
    document.head.appendChild(st);
  }

  const PP_TH = 'padding:7px 10px;text-align:left;font-size:10.5px;font-weight:800;'
    + 'text-transform:uppercase;letter-spacing:.5px;color:#64748B;border-bottom:1.5px solid #E2E8F0;';
  const PP_TD = 'padding:6px 10px;font-size:13px;color:#334155;border-bottom:1px solid #F1F5F9;vertical-align:middle;';

  const INV_BTN = 'background:#fff;border:1px solid #CBD5E1;border-radius:8px;padding:3px 10px;font-size:11.5px;font-weight:700;color:#1F6080;cursor:pointer;';

  /* One delegated listener for the screen rather than one per row, so it survives the
     redraws this table does whenever a schedule is created, cancelled or the family
     picker changes. Guarded so repeated renders do not stack handlers. */
  /* Asked at CLICK time, not captured at render. The delegated listener below outlives
     any one render, and a view-as switch changes the answer without re-running it.
     Same three roles renderPaymentPlans() tests. */
  function staffNow() {
    try {
      const u = JSON.parse(sessionStorage.getItem('kt_user') || '{}');
      return Array.isArray(u.roles)
        && u.roles.some(r => ['agency_admin', 'centre_director', 'platform_admin'].includes(r));
    } catch (e) { return false; }
  }

  /* wireInvoiceActions() binds ONCE per #appMain and outlives any single render, so it
     cannot close over that render's `plans`. This box is what it reads instead, rewritten
     by each render — a stale capture is how Resend would offer the previous family's
     addresses. */
  const plansRef = { list: [] };

  function wireInvoiceActions(main) {
    if (main.getAttribute('data-inv-wired') === '1') { return; }
    main.setAttribute('data-inv-wired', '1');
    main.addEventListener('click', async (e) => {
      /* Issuing is the moment a draft becomes money the family owes — it appears in
         their balance and starts attracting reminders — so it is confirmed, and the
         confirmation says plainly when it WOULD have gone out on its own. */
      const iss = e.target.closest('[data-inv-issue]');
      if (iss) {
        e.preventDefault();
        const num = iss.getAttribute('data-inv-num') || 'this invoice';
        const on = iss.getAttribute('data-inv-on') || '';
        /* `description` and `okLabel` are what KT.confirm reads — a `body`/`confirmText`
           pair would render a bare title and an "OK". */
        const ok = await KT.confirm({
          title: 'Issue ' + num + ' now?',
          description: (on ? 'It is scheduled to issue on its own on ' + fmtDate(on) + '. ' : '')
            + 'Issuing now makes it owed by the family straight away and it starts counting '
            + 'towards due and overdue reminders. The due date does not change, and no email '
            + 'is sent — send a copy separately if they should get one.',
          okLabel: 'Issue now',
        });
        if (!ok) { return; }
        try {
          const r = await Api.post('/director/invoices/' + iss.getAttribute('data-inv-issue') + '/issue', {});
          toast((r && r.message) || (num + ' issued'), 'success');
          renderPaymentPlans(main);
        } catch (err) {
          toast((err && err.message) || 'Could not issue that invoice.', 'error');
        }
        return;
      }
      /* DOWNLOAD - fetched with the caller's token, because the PDF route is
         authenticated and a plain <a href> carries no Authorization header. The object
         URL is revoked straight after; leaving it alive pins the whole file in memory. */
      const dl = e.target.closest('[data-inv-pdf]');
      if (dl) {
        e.preventDefault();
        e.stopPropagation();
        const num = dl.getAttribute('data-inv-num') || 'invoice';
        try {
          const tok = sessionStorage.getItem('kt_token') || localStorage.getItem('kt_token') || '';
          const headers = { Authorization: 'Bearer ' + tok };
          const ag = sessionStorage.getItem('kt_active_agency_id');
          if (ag) { headers['X-Active-Agency-Id'] = ag; }
          const res = await fetch(KT.API_BASE + '/invoices/' + dl.getAttribute('data-inv-pdf') + '/pdf', { headers });
          if (!res.ok) { throw new Error('That invoice could not be rendered as a PDF.'); }
          const blob = await res.blob();
          const url = URL.createObjectURL(blob);
          const a = document.createElement('a');
          a.href = url; a.download = num + '.pdf';
          document.body.appendChild(a); a.click(); a.remove();
          setTimeout(() => URL.revokeObjectURL(url), 4000);
        } catch (err) {
          toast((err && err.message) || 'Could not download that invoice.', 'error');
        }
        return;
      }

      /* RESEND - the address is SHOWN and editable, never assumed. The family's
         guardian addresses are pre-filled because typing them again invites a typo, but
         a resend that silently picks its own recipient is how an invoice lands in the
         wrong inbox, so the person sending it sees exactly who it goes to. */
      const rs = e.target.closest('[data-inv-resend]');
      if (rs) {
        e.preventDefault();
        e.stopPropagation();
        const num = rs.getAttribute('data-inv-num') || 'this invoice';
        const plan = (plansRef.list || []).filter(x => String(x.id) === rs.getAttribute('data-plan'))[0];
        const to = ((plan && plan.guardian_emails) || []).join(', ');
        /* KT.prompt resolves the raw STRING when there is a single field (an object only
           for two or more), and the field's id is `key`, not `name` — reading `.to` off
           the result would be undefined on every send. */
        const got = await KT.prompt({
          title: 'Resend ' + num,
          description: to
            ? 'It will be emailed with the invoice attached. Check the address before sending.'
            : 'No guardian on this family has an email address on file, so there is nobody to '
              + 'send to unless you type one.',
          fields: [{ key: 'to', label: 'Send to', value: to, placeholder: 'name@example.com' }],
          okLabel: 'Send',
        });
        const addr = String(got == null ? '' : got).trim();
        if (!addr) { return; }
        try {
          const r = await Api.post('/admin/invoices/native/' + rs.getAttribute('data-inv-resend') + '/email',
            { to: addr });
          toast((r && r.sent === false) ? (r.reason || 'Not sent.') : (num + ' sent to ' + addr),
            (r && r.sent === false) ? 'error' : 'success');
        } catch (err) {
          toast((err && err.message) || 'Could not send that invoice.', 'error');
        }
        return;
      }

      /* EDIT ONE INSTALMENT. Two fields, because an instalment IS two facts: when it
         is due and how much. The invoice's issue date follows the due date on the
         server, so it is not asked for here — one date to keep right, not two. */
      const ei = e.target.closest('[data-edit-inst]');
      if (ei) {
        e.preventDefault();
        e.stopPropagation();
        const num = ei.getAttribute('data-inv-num') || 'this instalment';
        const got = await KT.prompt({
          title: 'Edit ' + num,
          description: 'Only this instalment changes. The rest of the schedule, and every '
            + 'invoice already issued, is left exactly as it is.',
          fields: [
            { key: 'due_date', label: 'Due date', type: 'date', value: ei.getAttribute('data-due') || '' },
            { key: 'amount', label: 'Amount', type: 'number', value: ei.getAttribute('data-amt') || '' },
          ],
          okLabel: 'Save instalment',
        });
        if (!got || !got.due_date || !(Number(got.amount) > 0)) { return; }
        try {
          await Api.patch('/payment-plans/' + ei.getAttribute('data-plan')
            + '/installments/' + ei.getAttribute('data-edit-inst'),
            { due_date: String(got.due_date).slice(0, 10), amount: Number(got.amount) });
          toast(num + ' updated', 'success');
          renderPaymentPlans(main);
        } catch (err) {
          toast((err && err.message) || 'Could not update that instalment.', 'error');
        }
        return;
      }

      const v = e.target.closest('[data-inv-view]');
      if (v) {
        e.preventDefault();
        if (KT.openInvoiceById) { KT.openInvoiceById(v.getAttribute('data-inv-view')); }
        return;
      }
      const x = e.target.closest('[data-inv-ext]');
      if (!x) { return; }
      e.preventDefault();
      /* Fetched through our API so the provider's token never sits in the page and the
         family check happens on our side. The tab is opened BEFORE the await — a popup
         blocker refuses a window opened after one. */
      const w = window.open('', '_blank');
      try {
        /* THE ROUTE THAT MATCHES WHO IS ASKING.

           This always called the /parent/ one, which resolves the caller's families
           through `guardians` — correct for a parent, and a guaranteed 403 for an
           admin or director, who is nobody's guardian. Staff could see the invoice
           listed and were told "Forbidden. Required role: guardian" the moment they
           clicked it. The staff twin is scoped by the active agency instead. */
        const base = staffNow()
          ? '/agency/external-invoices/'
          : '/parent/external-invoices/';
        const r = await Api.get(base + x.getAttribute('data-inv-ext') + '/link');
        if (r && r.url) {
          if (w) { w.location = r.url; } else { window.location = r.url; }
        } else if (w) {
          w.close();
        }
      } catch (err) {
        if (w) { w.close(); }
        toast('Could not open that invoice: ' + ((err && err.message) || 'please try again.'), 'error');
      }
    });
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

    /* The agency's own notice period is what this dialog should offer, so it agrees with
       Settings instead of hard-coding 5. Fetched AFTER the form is drawn, not awaited
       before it: this function is not async and its callers do not await it, so awaiting
       here would have meant either making the whole chain async or a syntax error. The
       field is drawn with 5 and corrected the moment the setting arrives - and left alone
       if somebody has already typed in it. */
    const defaultLead = 5;

    wrap.innerHTML = `
      <label for="ps-famq" style="${lbl}">Family <span style="color:#DC2626;">*</span></label>
      <div id="ps-combo" style="position:relative;">
        <input id="ps-famq" type="text" autocomplete="off" placeholder="Start typing a family name…" style="${fld}">
        <input id="ps-fam" type="hidden" value="">
        <div id="ps-famlist" hidden style="position:absolute;z-index:40;left:0;right:0;top:100%;margin-top:4px;
          background:#fff;border:1px solid #E2E8F0;border-radius:10px;box-shadow:0 12px 30px rgba(15,23,42,.16);
          max-height:210px;overflow:auto;"></div>
      </div>

      <div style="display:flex;gap:10px;margin-top:12px;">
        <div style="flex:1;"><label for="ps-total" style="${lbl}">Total amount ($) <span style="color:#DC2626;">*</span></label>
          <input id="ps-total" type="number" step="0.01" min="0.01" style="${fld}"></div>
        <div style="flex:1;"><label for="ps-cadence" style="${lbl}">Every <span style="color:#DC2626;">*</span></label>
          <select id="ps-cadence" style="${fld}">
            <option value="">Choose a schedule…</option>
            <option value="monthly">Month</option><option value="biweekly">2 weeks</option><option value="weekly">Week</option>
          </select></div>
      </div>
      <div style="display:flex;gap:10px;margin-top:12px;">
        <div style="flex:1;"><label for="ps-first" style="${lbl}">First due <span style="color:#DC2626;">*</span></label>
          <input id="ps-first" type="date" value="${ymd(firstDefault)}" style="${fld}"></div>
        <div style="flex:1;"><label for="ps-last" style="${lbl}">Last due <span style="color:#DC2626;">*</span></label>
          <input id="ps-last" type="date" value="${ymd(lastDefault)}" style="${fld}"></div>
      </div>

      <label for="ps-desc" style="${lbl}margin-top:12px;">What is this for? <span style="color:#DC2626;">*</span></label>
      <input id="ps-desc" maxlength="200" placeholder="e.g. Full-time childcare, Sep 2026 – Feb 2027" style="${fld}">
      <div style="font-size:11.5px;color:#64748B;margin-top:4px;line-height:1.5;">
        Printed on every invoice as the line the total is for. Left blank, each one reads
        “Payment schedule instalment 3 of 7”, which tells a parent nothing.</div>

      <!-- WHEN THE MONEY IS ACTUALLY DUE. Anthony: "indicate due immediately or can
           choose date when are actually due (grace period)". The dates above are the DUE
           dates; this is only about how much warning the family gets before each one. -->
      <div style="margin-top:14px;border-top:1px solid #E2E8F0;padding-top:12px;">
        <span style="${lbl}margin:0;">When is each instalment due?</span>
        <label style="display:flex;gap:9px;align-items:flex-start;cursor:pointer;font-size:13px;color:#334155;line-height:1.5;margin-top:7px;">
          <input type="radio" name="ps-grace" value="notice" checked style="margin-top:2px;">
          <span><strong>Give notice</strong> — the invoice goes out
          <input id="ps-grace-days" type="number" min="0" max="60" value="${defaultLead}"
            style="width:74px;padding:3px 8px;border:1px solid #CBD5E1;border-radius:6px;font-size:13px;
              text-align:center;vertical-align:middle;box-sizing:content-box;flex:none;">
          days before each due date, so the family can pay before it is owed.</span></label>
        <label style="display:flex;gap:9px;align-items:flex-start;cursor:pointer;font-size:13px;color:#334155;line-height:1.5;margin-top:8px;">
          <input type="radio" name="ps-grace" value="immediate" style="margin-top:2px;">
          <span><strong>Due immediately</strong> — each invoice is issued on its due date and
          owed the same day, with no notice period.</span></label>
      </div>

      <div style="margin-top:14px;border-top:1px solid #E2E8F0;padding-top:12px;">
        <div style="display:flex;align-items:center;gap:8px;">
          <span style="${lbl}margin:0;">Extra charges (optional)</span>
          <button type="button" id="ps-addline" style="margin-left:auto;border:1px solid #CBD5E1;background:#fff;color:#334155;border-radius:8px;padding:4px 10px;font-size:12px;font-weight:700;cursor:pointer;">+ Add line</button>
        </div>
        <div id="ps-lines" style="margin-top:8px;"></div>
        <div style="font-size:11.5px;color:#64748B;margin-top:6px;line-height:1.5;">
          Added to the instalments, not billed separately — so what the family owes and
          what the schedule says stay the same number. Use a negative amount to take money off.</div>
      </div>

      <label for="ps-notes" style="${lbl}margin-top:14px;">Notes (optional)</label>
      <input id="ps-notes" style="${fld}">
      <div style="font-size:11.5px;color:#64748B;margin-top:4px;">Internal. Never printed on an invoice.</div>
      <div id="ps-msg" style="margin-top:10px;font-size:12.5px;color:#B91C1C;"></div>`;

    /* Narrowing the list as you type is fine here — it only shortens a <select>,
       nothing is fetched and nothing on the page is replaced. */
    /* EXTRA CHARGES ON A SCHEDULE (2026-09-17).

       Anthony: "also ability to add a line item(s) as well."

       EVERY INSTALMENT vs FIRST ONLY is asked, not guessed. A supply fee belongs on all
       seven invoices and a registration fee on exactly one, and picking either default
       silently would mean billing a one-off seven times or dropping six of a recurring
       charge \u2014 both wrong in a way nobody would notice until a parent complained. */
    const extraLines = [];

    function paintLines() {
      const host = wrap.querySelector('#ps-lines');
      if (!host) { return; }
      if (!extraLines.length) {
        host.innerHTML = '<div style="font-size:12.5px;color:#94A3B8;padding:2px 0;">None. The total above is the whole bill.</div>';
        return;
      }
      host.innerHTML = extraLines.map((l, n) => `
        <div style="display:grid;grid-template-columns:1fr 92px 72px 128px 28px;gap:6px;align-items:center;margin-bottom:6px;">
          <input data-xl-d="${n}" value="${esc(l.description)}" maxlength="200" placeholder="Description"
            style="padding:7px 9px;border:1px solid #E2E8F0;border-radius:8px;font-size:13px;box-sizing:border-box;width:100%;">
          <input data-xl-a="${n}" type="number" step="0.01" value="${l.amount}" placeholder="Amount"
            style="padding:7px 9px;border:1px solid #E2E8F0;border-radius:8px;font-size:13px;box-sizing:border-box;width:100%;">
          <input data-xl-t="${n}" type="number" step="0.01" min="0" max="100" value="${l.tax_rate == null ? '' : l.tax_rate}" placeholder="Tax %"
            style="padding:7px 9px;border:1px solid #E2E8F0;border-radius:8px;font-size:13px;box-sizing:border-box;width:100%;">
          <select data-xl-p="${n}" style="padding:7px 9px;border:1px solid #E2E8F0;border-radius:8px;font-size:12.5px;box-sizing:border-box;width:100%;">
            <option value="every"${l.applies === 'every' ? ' selected' : ''}>Every instalment</option>
            <option value="first"${l.applies === 'first' ? ' selected' : ''}>First only</option>
          </select>
          <button type="button" data-xl-x="${n}" title="Remove" style="border:none;background:none;color:#B91C1C;cursor:pointer;font-size:15px;line-height:1;">\u00d7</button>
        </div>`).join('');

      host.querySelectorAll('[data-xl-d]').forEach(el => el.addEventListener('input', function () {
        extraLines[+this.dataset.xlD].description = this.value;
      }));
      host.querySelectorAll('[data-xl-a]').forEach(el => el.addEventListener('input', function () {
        extraLines[+this.dataset.xlA].amount = this.value;
      }));
      host.querySelectorAll('[data-xl-t]').forEach(el => el.addEventListener('input', function () {
        extraLines[+this.dataset.xlT].tax_rate = this.value === '' ? null : this.value;
      }));
      host.querySelectorAll('[data-xl-p]').forEach(el => el.addEventListener('change', function () {
        extraLines[+this.dataset.xlP].applies = this.value;
      }));
      host.querySelectorAll('[data-xl-x]').forEach(el => el.addEventListener('click', function () {
        extraLines.splice(+this.dataset.xlX, 1); paintLines();
      }));
    }

    wrap.querySelector('#ps-addline').addEventListener('click', function () {
      extraLines.push({ description: '', amount: '', tax_rate: null, applies: 'every' });
      paintLines();
      const last = wrap.querySelectorAll('#ps-lines [data-xl-d]');
      if (last.length) { last[last.length - 1].focus(); }
    });
    paintLines();

    /* ONE CONTROL, NOT A SEARCH BOX ABOVE A SEPARATE DROPDOWN (2026-09-17).

       Anthony: "the search families and select a family drop down should be together so
       if entering a family name it can search through the drop download and allow you to
       select."

       It was two: an <input> that filtered a <select> underneath it. Typing narrowed the
       list but selected nothing, so every choice took a type AND a click in a second
       control, and on a phone the filtered <select> still opened the OS picker showing
       its own copy of the options.

       This is one combobox: type to filter, click or press Enter to choose. The chosen
       family id lives in a hidden input so the submit path below is unchanged, and the
       visible field always shows the name that was actually selected - typing something
       that matches nothing leaves the previous selection cleared rather than pretending. */
    const famInput = wrap.querySelector('#ps-famq');
    const famId = wrap.querySelector('#ps-fam');
    const famList = wrap.querySelector('#ps-famlist');
    const all = (families || []).slice();
    let cursor = -1;

    function closeList() { famList.hidden = true; cursor = -1; }

    function paintList(q) {
      const hits = q
        ? all.filter(f => famName(f).toLowerCase().indexOf(q.toLowerCase()) !== -1).slice(0, 40)
        : all.slice(0, 40);
      if (!hits.length) {
        famList.innerHTML = '<div style="padding:10px 12px;font-size:13px;color:#94A3B8;">No family matches that.</div>';
        famList.hidden = false;
        return;
      }
      famList.innerHTML = hits.map((f, n) => `<div data-fid="${f.id}" data-n="${n}"
        style="padding:9px 12px;font-size:13.5px;color:#334155;cursor:pointer;border-bottom:1px solid #F1F5F9;">${esc(famName(f))}</div>`).join('');
      famList.hidden = false;
      famList.querySelectorAll('[data-fid]').forEach(function (el) {
        el.addEventListener('mousedown', function (ev) {
          // mousedown, not click: blur would close the list before a click landed.
          ev.preventDefault();
          choose(el.getAttribute('data-fid'), el.textContent);
        });
        el.addEventListener('mouseenter', function () { highlight(+el.getAttribute('data-n')); });
      });
    }

    function highlight(n) {
      const rows = famList.querySelectorAll('[data-fid]');
      cursor = Math.max(-1, Math.min(n, rows.length - 1));
      rows.forEach(function (r, i) { r.style.background = i === cursor ? '#EFF6FF' : '#fff'; });
      if (cursor >= 0 && rows[cursor]) { rows[cursor].scrollIntoView({ block: 'nearest' }); }
    }

    function choose(id, label) {
      famId.value = id;
      famInput.value = label;
      closeList();
    }

    famInput.addEventListener('input', function () {
      // Typing after a selection invalidates it: the box and the id must never disagree.
      famId.value = '';
      paintList(this.value.trim());
    });
    /* NOT ON FOCUS (2026-09-17). KT.Shell.Modal focuses the first field when it opens,
       so opening the list on focus meant the dialog appeared with the whole family list
       already hanging open over the form - before anybody had asked for it.

       It opens when somebody actually asks: by typing, by clicking into the field a
       second time, or with the down arrow. Focus alone is the modal doing its job, not a
       request to see 40 families. */
    famInput.addEventListener('mousedown', function () {
      // Deferred: on the focusing click the value is read before focus settles.
      setTimeout(() => paintList(famInput.value.trim()), 0);
    });
    famInput.addEventListener('blur', function () { setTimeout(closeList, 120); });
    famInput.addEventListener('keydown', function (e) {
      if (famList.hidden) {
        // The one key that means "show me the options".
        if (e.key === 'ArrowDown') { e.preventDefault(); paintList(famInput.value.trim()); }
        return;
      }
      const rows = famList.querySelectorAll('[data-fid]');
      if (e.key === 'ArrowDown') { e.preventDefault(); highlight(cursor + 1); }
      else if (e.key === 'ArrowUp') { e.preventDefault(); highlight(cursor - 1); }
      else if (e.key === 'Enter') {
        if (cursor >= 0 && rows[cursor]) {
          e.preventDefault();
          choose(rows[cursor].getAttribute('data-fid'), rows[cursor].textContent);
        }
      } else if (e.key === 'Escape') { closeList(); }
    });

    (async function () {
      try {
        const bs = await Api.get('/admin/billing-setup');
        const v = bs && bs.data && bs.data.invoice_issue_lead_days;
        const el = wrap.querySelector('#ps-grace-days');
        if (el && !el.dataset.touched && v != null && !isNaN(parseInt(v, 10))) {
          el.value = parseInt(v, 10);
        }
      } catch (e) { /* the default stands */ }
    })();
    const graceEl = wrap.querySelector('#ps-grace-days');
    if (graceEl) { graceEl.addEventListener('input', function () { this.dataset.touched = '1'; }); }

    KT.Shell.Modal.open({
      title: 'New payment schedule',
      body: wrap,
      actions: [
        { label: 'Cancel' },
        {
          label: 'Build the schedule', style: 'btn-primary',
          handler: function () {
            const msg = wrap.querySelector('#ps-msg');
            const familyId = +famId.value;
            const total = parseFloat(wrap.querySelector('#ps-total').value);
            const first = wrap.querySelector('#ps-first').value;
            const last = wrap.querySelector('#ps-last').value;
            const cadence = wrap.querySelector('#ps-cadence').value;
            const notes = wrap.querySelector('#ps-notes').value.trim();
            const description = wrap.querySelector('#ps-desc').value.trim();

            /* Blank rows are dropped rather than rejected — pressing "+ Add line" and
               changing your mind should not be an error to clear. A half-filled one IS
               an error, because it is somebody mid-thought. */
            const items = [];
            for (const l of extraLines) {
              const d = String(l.description || '').trim();
              const a = parseFloat(l.amount);
              if (!d && (isNaN(a) || a === 0)) { continue; }
              if (!d) { msg.textContent = 'Give every extra charge a description.'; return false; }
              if (isNaN(a) || a === 0) { msg.textContent = 'Give \u201c' + d + '\u201d an amount.'; return false; }
              const t = parseFloat(l.tax_rate);
              items.push({
                description: d,
                amount: Math.round(a * 100) / 100,
                tax_rate: isNaN(t) || t <= 0 ? null : t,
                applies: l.applies === 'first' ? 'first' : 'every',
              });
            }

            /* EVERY FIELD THAT SHAPES THE MONEY IS REQUIRED (2026-09-17).

               Anthony: "make the fields mandatory when entering a new payment schedule
               dates, amount, descriptions and $ and schedule."

               Each message names the ONE thing to fix and focuses it, rather than a
               single "fill in the form" that leaves somebody hunting. The family check
               distinguishes "nothing typed" from "typed something that matched nothing",
               because the second is the one people get wrong. */
            const focusFail = function (el, text) {
              msg.textContent = text;
              if (el && el.focus) { try { el.focus(); } catch (e) {} }
              return false;
            };

            if (!familyId) {
              return focusFail(famInput, famInput.value.trim()
                ? 'No family matches “' + famInput.value.trim() + '” — pick one from the list.'
                : 'Choose a family.');
            }
            if (!(total > 0)) {
              return focusFail(wrap.querySelector('#ps-total'), 'Enter the total amount.');
            }
            if (!cadence) {
              return focusFail(wrap.querySelector('#ps-cadence'), 'Choose how often it is paid.');
            }
            if (!first || !last) {
              return focusFail(wrap.querySelector(first ? '#ps-last' : '#ps-first'),
                'Both a first and a last due date are needed.');
            }
            if (last < first) {
              return focusFail(wrap.querySelector('#ps-last'), 'The last due date is before the first.');
            }
            if (!description) {
              return focusFail(wrap.querySelector('#ps-desc'),
                'Say what this is for — it is printed on every invoice.');
            }

            /* 0 is a real answer here ("due on issue"), so it is sent as 0 and not
               collapsed into "unset" by a falsy check. */
            const graceMode = (wrap.querySelector('input[name="ps-grace"]:checked') || {}).value;
            let lead = 0;
            if (graceMode !== 'immediate') {
              lead = parseInt((wrap.querySelector('#ps-grace-days') || {}).value, 10);
              if (isNaN(lead) || lead < 0) {
                return focusFail(wrap.querySelector('#ps-grace-days'),
                  'Enter how many days of notice to give, or choose Due immediately.');
              }
              lead = Math.min(60, lead);
            }

            const rows = buildSchedule(first, last, cadence, total);
            if (!rows.length) { msg.textContent = 'That range does not contain a single due date.'; return false; }

            const fam = all.filter(f => +f.id === familyId)[0];
            setTimeout(() => openScheduleReview(familyId, famName(fam || { id: familyId }), total, notes, rows,
              null, 0, description, items, lead), 60);

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
  /* THE SAME REVIEW VIEW, FOR A NEW SCHEDULE AND FOR AN EDIT (2026-09-17).

     "I need an option to edit plans just the same way we created them with the same view
      for a family/parent." So editing does not get a second form: it opens this one,
     already filled with the instalments that can still be changed, and saves with PATCH
     instead of POST.

     `editPlanId` carries the schedule being edited; `keptCount` is how many settled
     instalments are NOT in this list and will be left alone, said plainly at the top so
     nobody wonders where they went. */
  function openScheduleReview(familyId, familyLabel, intendedTotal, notes, rows, editPlanId, keptCount, description, lineItems, leadDays) {
    const wrap = document.createElement('div');
    const money = (n) => '$' + (Number(n) || 0).toLocaleString('en-CA', { minimumFractionDigits: 2, maximumFractionDigits: 2 });

    /* WHAT THE EXTRAS ADD, said out loud (2026-09-17).

       The amount boxes below hold the TOTAL split into instalments — the figure typed on
       the first step. The server adds the extra charges on top of each one when it raises
       the invoices, so the boxes and the invoices deliberately differ, and a review screen
       that showed only the boxes would promise $220 and bill $245. Computed here purely to
       say so; the rows sent to the server are untouched, because billing arithmetic
       belongs on the server and not in two places. */
    const chargeOf = (rows_) => Math.round(rows_.reduce((sum, l) => {
      const a = Number(l.amount) || 0;
      const t = Number(l.tax_rate) || 0;
      return sum + a + (t > 0 ? a * (t / 100) : 0);
    }, 0) * 100) / 100;
    const extraEvery = chargeOf((lineItems || []).filter(l => l.applies !== 'first'));
    const extraFirst = chargeOf((lineItems || []).filter(l => l.applies === 'first'));

    /* ISSUE NOW BY DEFAULT (2026-09-17).

       Anthony: "add a step to issue now or keep in draft - default should be issued now
       with a prompt stating that the invoices will be issued."

       Drafts were the only behaviour and they are right for a plan running into next
       year, but the common case is a schedule whose early instalments are for months
       already underway, where the drafts simply sat owed by nobody. So the choice is
       asked for, issuing wins the default, and the consequence is stated in full before
       the button is pressed rather than discovered afterwards.

       EDITING DOES NOT ASK. An edit rewrites only the unissued tail of an existing
       schedule; whether those go out today is a separate decision, made per invoice with
       Issue now, and folding it into the edit form would let one Save do two things. */
    let issueNow = !editPlanId;

    function paint() {
      const sum = rows.reduce((a, r) => a + (Number(r.amount) || 0), 0);
      const diff = Math.round((sum - intendedTotal) * 100) / 100;
      wrap.innerHTML = `
        <p style="margin:0 0 12px;font-size:13.5px;color:#334155;line-height:1.6;">
          <strong>${esc(familyLabel)}</strong> — ${rows.length} instalment(s). Correct any date or
          amount before saving. Each line raises its own invoice${editPlanId || issueNow ? '' : ', issued a few days before it falls due'}.</p>
        ${(description || (lineItems || []).length) ? `<p style="margin:-4px 0 10px;font-size:12.5px;color:#334155;background:#F8FAFC;border-left:3px solid #CBD5E1;padding:8px 10px;border-radius:0 6px 6px 0;line-height:1.55;">
          ${description ? `Each invoice's main line will read <strong>${esc(description)}</strong>.` : ''}
          ${extraEvery ? `Every instalment also carries <strong>${money(extraEvery)}</strong> of extra charges, so
            a ${money(rows[0] ? rows[0].amount : 0)} instalment is invoiced at
            <strong>${money((Number(rows[0] && rows[0].amount) || 0) + extraEvery)}</strong>.` : ''}
          ${extraFirst ? `The first instalment also carries <strong>${money(extraFirst)}</strong> charged once.` : ''}
          ${leadDays === 0 ? ' Each invoice is issued on its due date and owed the same day.'
            : (leadDays > 0 ? ' Each invoice goes out ' + leadDays + ' day(s) before it is due.' : '')}
          </p>` : ''}
        ${editPlanId && keptCount ? `<p style="margin:-4px 0 12px;font-size:12.5px;color:#7C4A11;background:#FFF8EC;border-left:3px solid #B45309;padding:8px 10px;border-radius:0 6px 6px 0;line-height:1.55;">
          ${keptCount} instalment(s) have already been issued to this family and are left untouched — only the
          instalments below are rewritten.</p>` : ''}
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
        ${editPlanId ? '' : `<div id="ps-issue" style="margin-top:12px;border:1px solid #E2E8F0;border-radius:10px;padding:12px 14px;background:#F8FAFC;">
          <div style="font-size:12.5px;font-weight:800;color:#334155;text-transform:uppercase;letter-spacing:.4px;margin-bottom:8px;">When do these go out?</div>
          <label style="display:flex;gap:9px;align-items:flex-start;cursor:pointer;font-size:13px;color:#334155;line-height:1.5;">
            <input type="radio" name="ps-issue-mode" value="now" ${issueNow ? 'checked' : ''} style="margin-top:2px;">
            <span><strong>Issue now</strong> — all ${rows.length} invoice(s) are issued today and owed by the
            family straight away. They appear on the family's balance and start counting towards due and
            overdue reminders. Due dates are unchanged.</span></label>
          <label style="display:flex;gap:9px;align-items:flex-start;cursor:pointer;font-size:13px;color:#334155;line-height:1.5;margin-top:9px;">
            <input type="radio" name="ps-issue-mode" value="draft" ${issueNow ? '' : 'checked'} style="margin-top:2px;">
            <span><strong>Keep as drafts</strong> — each invoice stays pending until shortly before
            its own due date, then issues automatically. Nothing is owed before then. (How many days'
            notice is set in Settings → Billing → Defaults &amp; fees.)</span></label>
          ${issueNow ? `<p style="margin:10px 0 0;font-size:12.5px;color:#7C4A11;background:#FFF8EC;border-left:3px solid #B45309;padding:8px 10px;border-radius:0 6px 6px 0;line-height:1.55;">
            Saving will issue ${rows.length} invoice(s) totalling <strong>${money(sum)}</strong> to
            ${esc(familyLabel)} immediately.</p>` : ''}
        </div>`}
        <div id="ps-rmsg" style="margin-top:10px;font-size:13px;color:#B91C1C;"></div>`;

      wrap.querySelectorAll('input[name="ps-issue-mode"]').forEach(el => el.addEventListener('change', function () {
        issueNow = this.value === 'now'; paint();
      }));
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
      title: editPlanId ? 'Edit payment schedule' : 'Check the schedule before saving',
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

            const payload = {
              notes: notes || null,
              installments: rows.map(r => ({ due_date: r.due_date, amount: Number(r.amount) })),
            };
            if (!editPlanId) {
              payload.family_id = familyId;
              payload.issue_now = issueNow;
              payload.description = description || null;
              /* Only on create. An edit rewrites dates and amounts; the description and
                 extra charges live on the plan and the server reapplies them itself, so
                 re-posting them from a form that never showed them could only overwrite
                 good data with blanks. */
              if (lineItems && lineItems.length) { payload.line_items = lineItems; }
              // 0 means "due on issue" and must survive; only an absent value is omitted.
              if (leadDays != null) { payload.issue_lead_days = leadDays; }
            }

            return (editPlanId
              ? Api.patch('/payment-plans/' + editPlanId, payload)
              : Api.post('/payment-plans', payload)
            ).then(function (res) {
              /* Say what actually happened, not what was asked for — the server decides
                 how many were issued and reports it back. */
              const issued = res && Number(res.issued || 0);
              toast(editPlanId
                ? 'Schedule updated — ' + rows.length + ' instalment(s)'
                : (issued
                    ? rows.length + ' instalment(s) scheduled — ' + issued + ' invoice(s) issued'
                    : rows.length + ' instalment(s) scheduled, held as drafts'), 'success');
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
