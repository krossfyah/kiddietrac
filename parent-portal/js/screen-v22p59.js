/* v22p59 — 9 features, all hash-based screens. */
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
  const fmtTime = (s) => { if (!s) return ''; const d = new Date(s); return d.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' }); };

  // ============================ Family directory ============================
  async function renderDirectory(main) {
    main.setAttribute('data-kt-pretty', '1');
    main.innerHTML = '<div style="padding:24px;">Loading…</div>';
    const [dir, mine] = await Promise.all([
      Api.get('/directory').catch(() => ({ data: [] })),
      Api.get('/directory/me').catch(() => ({ data: null })),
    ]);
    const o = mine.data || {};
    main.innerHTML = `<div style="padding:24px;max-width:1800px;margin:0 auto;">
      <div class="kt-page-hero">
        <h2>👪 Family directory</h2>
        <p>Connect with other families at your centre. Sharing your contact info is opt-in — uncheck anything you don't want to share.</p>
      </div>
      <div class="kt-card">
        <div class="kt-card-header"><h3 class="kt-card-title">My privacy</h3></div>
        <p style="color:#475569;font-size:14px;margin:4px 0;">Choose what you share with other families in <a href="#settings" style="color:#1F6080;font-weight:700;text-decoration:none;">Settings &rsaquo; Family directory</a>.</p>
      </div>
      <div class="kt-card">
        <div class="kt-card-header"><h3 class="kt-card-title">Other families at your centre</h3></div>
        ${(dir.data || []).length ? `<div data-kt-list="1" style="display:grid;grid-template-columns:repeat(auto-fill,minmax(280px,1fr));gap:14px;">
          ${(dir.data || []).map(f => `<div style="background:#FAFCFE;padding:16px;border-radius:10px;border:1px solid #F1F5F9;">
            <strong style="color:#0F172A;font-size:15px;">${esc(f.family_name)}</strong>
            ${f.child_names && f.child_names.length ? `<div style="color:#475569;font-size:13px;margin-top:4px;">Children: ${f.child_names.map(esc).join(', ')}</div>` : ''}
            ${f.primary_email ? `<div style="color:#475569;font-size:13px;margin-top:6px;">📧 <a href="mailto:${esc(f.primary_email)}" style="color:#1F6080;">${esc(f.primary_email)}</a></div>` : ''}
            ${f.primary_phone ? `<div style="color:#475569;font-size:13px;margin-top:4px;">📞 ${esc(f.primary_phone)}</div>` : ''}
            ${f.city ? `<div style="color:#475569;font-size:13px;margin-top:4px;">📍 ${esc(f.city)}</div>` : ''}
          </div>`).join('')}
        </div>` : '<div style="color:#64748B;padding:20px;text-align:center;">No other families have opted in yet.</div>'}
      </div>
    </div>`;
    // Privacy preferences now live on the Settings screen (Family directory section).
  }

  // ============================ Conferences ============================
  async function renderConferences(main) {
    main.setAttribute('data-kt-pretty', '1');
    main.innerHTML = '<div style="padding:24px;">Loading…</div>';
    const r = await Api.get('/conferences/slots').catch(() => ({ data: [] }));
    const slots = r.data || [];
    const u = JSON.parse(sessionStorage.getItem('kt_user') || '{}');
    const isStaff = Array.isArray(u.roles) && u.roles.some(r => ['educator', 'centre_director', 'agency_admin', 'platform_admin'].includes(r));
    main.innerHTML = `<div style="padding:24px;max-width:1800px;margin:0 auto;">
      <div class="kt-page-hero">
        <h2>🗣 Parent-teacher conferences</h2>
        <p>${isStaff ? slots.length + ' slot(s) in your agency.' : (slots.length + ' open slot(s) at your centre. Pick one for each child.')}</p>
        ${isStaff ? '<div class="kt-hero-actions"><button class="kt-btn kt-btn-ghost" id="cf-new">+ Create slots</button></div>' : ''}
      </div>
      <div class="kt-card">
        <table>
          <thead><tr><th>When</th><th>Teacher</th><th>Status</th>${isStaff ? '<th>Booked by</th>' : ''}<th></th></tr></thead>
          <tbody>${slots.map(s => `<tr>
            <td><strong>${fmtDate(s.slot_at)}</strong><div style="color:#64748B;font-size:12px;">${fmtTime(s.slot_at)} · ${s.duration_minutes} min</div></td>
            <td>${esc(s.teacher_name || 'Any teacher')}</td>
            <td><span class="kt-pill ${s.status === 'open' ? 'kt-pill-success' : 'kt-pill-warning'}">${esc(s.status)}</span></td>
            ${isStaff ? `<td>${esc(s.booked_by_name || '')} ${s.booked_child_name ? '(' + esc(s.booked_child_name) + ')' : ''}</td>` : ''}
            <td>${s.status === 'open' && !isStaff ? `<button class="kt-btn kt-btn-primary" data-book="${s.id}">Book</button>` : s.status === 'booked' ? `<button class="kt-btn kt-btn-danger" data-cancel="${s.id}" style="font-size:12px;">Cancel</button>` : ''}</td>
          </tr>`).join('') || '<tr><td colspan="' + (isStaff ? 5 : 4) + '" style="text-align:center;padding:40px;color:#64748B;">No slots scheduled.</td></tr>'}</tbody>
        </table>
      </div>
    </div>`;
    if (isStaff) document.getElementById('cf-new').onclick = () => openCreateSlotsModal();
    main.querySelectorAll('button[data-book]').forEach(b => b.onclick = async () => {
      const _kc = await Api.get('/parent/children'); const kids = _kc.children || _kc.data || [];
      const cid = kids.length === 1 ? kids[0].id : +prompt('Child ID for this booking? ' + kids.map(c => c.id + ': ' + c.first_name).join(', '));
      if (!cid) return;
      await Api.post(`/conferences/slots/${b.dataset.book}/book`, { child_id: cid });
      renderConferences(main);
    });
    main.querySelectorAll('button[data-cancel]').forEach(b => b.onclick = async () => {
      if (!await KT.confirm('Cancel this conference?')) return;
      await Api.post(`/conferences/slots/${b.dataset.cancel}/cancel`, {});
      renderConferences(main);
    });
  }
  async function openCreateSlotsModal() {
    const _cr = await Api.get('/admin/centres').catch(() => ({})); const centres = _cr.centres || _cr.data || [];
    const m = document.createElement('div');
    m.style.cssText = 'position:fixed;inset:0;background:rgba(15,23,42,0.55);z-index:99999;display:flex;align-items:center;justify-content:center;';
    m.innerHTML = `<div style="background:#fff;padding:28px;border-radius:14px;max-width:460px;width:92%;">
      <h3 style="margin:0 0 16px;">Create conference slots</h3>
      <label style="display:block;font-size:13px;font-weight:600;margin:10px 0 4px;">Centre</label>
      <select id="cf-centre" style="width:100%;padding:9px;border:1px solid #E2E8F0;border-radius:8px;">${centres.map(c => `<option value="${c.id}">${esc(c.name)}</option>`).join('')}</select>
      <label style="display:block;font-size:13px;font-weight:600;margin:14px 0 4px;">Date</label>
      <input id="cf-date" type="date" style="width:100%;padding:9px;border:1px solid #E2E8F0;border-radius:8px;">
      <div style="display:flex;gap:8px;margin-top:14px;">
        <div style="flex:1;"><label style="display:block;font-size:13px;font-weight:600;">Start</label>
          <input id="cf-start" type="time" value="15:00" style="width:100%;padding:9px;border:1px solid #E2E8F0;border-radius:8px;"></div>
        <div style="flex:1;"><label style="display:block;font-size:13px;font-weight:600;">End</label>
          <input id="cf-end" type="time" value="19:00" style="width:100%;padding:9px;border:1px solid #E2E8F0;border-radius:8px;"></div>
        <div style="flex:1;"><label style="display:block;font-size:13px;font-weight:600;">Length (min)</label>
          <input id="cf-dur" type="number" value="20" min="10" max="120" style="width:100%;padding:9px;border:1px solid #E2E8F0;border-radius:8px;"></div>
      </div>
      <div style="margin-top:20px;text-align:right;">
        <button id="cf-cancel" style="background:#F1F5F9;border:0;padding:10px 18px;border-radius:8px;cursor:pointer;font-weight:600;">Cancel</button>
        <button id="cf-save" class="kt-btn kt-btn-primary" style="margin-left:8px;">Generate slots</button>
      </div></div>`;
    document.body.appendChild(m);
    m.querySelector('#cf-cancel').onclick = () => m.remove();
    m.querySelector('#cf-save').onclick = async () => {
      await Api.post('/conferences/slots', {
        centre_id: +m.querySelector('#cf-centre').value,
        date: m.querySelector('#cf-date').value,
        start_time: m.querySelector('#cf-start').value,
        end_time: m.querySelector('#cf-end').value,
        duration_minutes: +m.querySelector('#cf-dur').value,
      });
      m.remove();
      renderConferences(document.querySelector('main'));
    };
  }

  // ============================ Field-trip GPS map ============================
  async function renderTripGps(main) {
    main.setAttribute('data-kt-pretty', '1');
    main.innerHTML = `<div style="padding:24px;max-width:1800px;margin:0 auto;">
      <div class="kt-page-hero">
        <h2>📍 Field-trip live tracker</h2>
        <p>Live location of staff lead on each active trip.</p>
      </div>
      <div class="kt-card">
        <label style="font-size:13px;font-weight:600;">Trip ID</label>
        <div style="display:flex;gap:8px;">
          <input id="ft-id" type="number" placeholder="e.g. 1" style="flex:1;padding:11px;border:1px solid #E2E8F0;border-radius:8px;margin-top:6px;">
          <button id="ft-load" class="kt-btn kt-btn-primary" style="margin-top:6px;">Load</button>
        </div>
        <div id="ft-detail" style="margin-top:20px;"></div>
      </div>
    </div>`;
    document.getElementById('ft-load').onclick = async () => {
      const tid = +document.getElementById('ft-id').value;
      if (!tid) return;
      const r = await Api.get(`/field-trips/${tid}/location`);
      const det = document.getElementById('ft-detail');
      if (!r.latest) { det.innerHTML = '<div style="color:#64748B;padding:14px;">No location pings yet.</div>'; return; }
      det.innerHTML = `<div class="kt-kpi-grid">
        <div class="kt-kpi kt-kpi-info"><div class="kt-kpi-label">Latitude</div><div class="kt-kpi-value">${parseFloat(r.latest.lat).toFixed(5)}</div></div>
        <div class="kt-kpi kt-kpi-info"><div class="kt-kpi-label">Longitude</div><div class="kt-kpi-value">${parseFloat(r.latest.lon).toFixed(5)}</div></div>
        <div class="kt-kpi kt-kpi-success"><div class="kt-kpi-label">Last ping</div><div class="kt-kpi-value" style="font-size:18px;">${fmtTime(r.latest.recorded_at)}</div></div>
        <div class="kt-kpi"><div class="kt-kpi-label">Trail length</div><div class="kt-kpi-value">${r.trail.length}</div></div>
      </div>
      <div style="margin-top:18px;">
        <iframe width="100%" height="500" frameborder="0" style="border-radius:12px;border:1px solid #E2E8F0;"
          src="https://www.openstreetmap.org/export/embed.html?bbox=${parseFloat(r.latest.lon)-0.01},${parseFloat(r.latest.lat)-0.01},${parseFloat(r.latest.lon)+0.01},${parseFloat(r.latest.lat)+0.01}&layer=mapnik&marker=${r.latest.lat},${r.latest.lon}"></iframe>
        <div style="margin-top:8px;font-size:12px;color:#64748B;">
          <a href="https://www.openstreetmap.org/?mlat=${r.latest.lat}&mlon=${r.latest.lon}#map=15/${r.latest.lat}/${r.latest.lon}" target="_blank" style="color:#1F6080;">View larger map</a>
        </div>
      </div>`;
    };
  }

  // ============================ Attendance pattern (parent + admin) ============================
  async function renderAttendancePattern(main) {
    main.setAttribute('data-kt-pretty', '1');
    main.innerHTML = '<div style="padding:24px;">Loading…</div>';
    const childrenRes = await Api.get('/parent/children').catch(() => Api.get('/admin/children').catch(() => ({ data: [] })));
    const children = childrenRes.children || childrenRes.data || (Array.isArray(childrenRes) ? childrenRes : []);
    if (!children.length) { main.innerHTML = '<div class="kt-card" style="margin:24px;text-align:center;color:#64748B;padding:40px;">No children.</div>'; return; }
    main.innerHTML = `<div style="padding:24px;max-width:1800px;margin:0 auto;">
      <div class="kt-page-hero">
        <h2>📅 Multi-day attendance pattern</h2>
        <p>Declare which days each child normally attends. Used for ratios + tuition projections.</p>
      </div>
      <div class="kt-card">
        <label style="font-size:13px;font-weight:600;">Child</label>
        <select id="ap-child" style="width:100%;padding:11px;border:1px solid #E2E8F0;border-radius:8px;margin-top:6px;">
          ${children.map(c => `<option value="${c.id}">${esc(c.first_name)} ${esc(c.last_name)}</option>`).join('')}
        </select>
        <div id="ap-detail" style="margin-top:18px;"></div>
      </div>
    </div>`;
    const load = async (cid) => {
      const r = await Api.get(`/attendance/pattern/${cid}`);
      const p = r.data || { monday: 1, tuesday: 1, wednesday: 1, thursday: 1, friday: 1, saturday: 0, sunday: 0 };
      document.getElementById('ap-detail').innerHTML = `
        <div style="display:flex;flex-wrap:wrap;gap:8px;margin-top:14px;">
          ${['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'].map(d => {
            const label = d.charAt(0).toUpperCase() + d.slice(1, 3);
            const on = !!p[d];
            return `<button type="button" class="ap-day" data-day="${d}" data-on="${on ? '1' : '0'}" aria-pressed="${on ? 'true' : 'false'}"
              style="flex:1 1 0;min-width:60px;display:flex;flex-direction:column;align-items:center;gap:3px;cursor:pointer;box-sizing:border-box;
              padding:12px 6px;border-radius:12px;font-family:inherit;transition:background .14s,border-color .14s,color .14s,box-shadow .14s;
              border:1.5px solid ${on ? '#159FB4' : '#E2E8F0'};background:${on ? 'linear-gradient(135deg,#0FA3B1,#1F6FB2)' : '#F8FAFC'};
              color:${on ? '#fff' : '#64748B'};box-shadow:${on ? '0 6px 14px -8px rgba(31,111,178,.7)' : 'none'};">
              <span style="font-weight:800;font-size:13.5px;letter-spacing:.3px;">${label}</span>
              <span class="ap-day-state" style="font-size:10px;font-weight:800;text-transform:uppercase;letter-spacing:.4px;opacity:.9;">${on ? 'In' : 'Off'}</span>
            </button>`;
          }).join('')}
        </div>
        <label style="display:block;font-size:13px;font-weight:600;margin:18px 0 4px;">Effective from</label>
        <input id="ap-from" type="date" value="${new Date().toISOString().slice(0,10)}" style="width:100%;padding:9px;border:1px solid #E2E8F0;border-radius:8px;max-width:220px;">
        <button id="ap-save" class="kt-btn kt-btn-primary" style="margin-top:14px;">Save pattern</button>
      `;
      // Each day is a single toggle button (was a raw checkbox stacked under the
      // label — visually inconsistent with the platform's pill toggles). Clicking
      // flips data-on and restyles to the selected/unselected states.
      const styleDay = (btn, on) => {
        btn.setAttribute('data-on', on ? '1' : '0');
        btn.setAttribute('aria-pressed', on ? 'true' : 'false');
        btn.style.border = '1.5px solid ' + (on ? '#159FB4' : '#E2E8F0');
        btn.style.background = on ? 'linear-gradient(135deg,#0FA3B1,#1F6FB2)' : '#F8FAFC';
        btn.style.color = on ? '#fff' : '#64748B';
        btn.style.boxShadow = on ? '0 6px 14px -8px rgba(31,111,178,.7)' : 'none';
        const st = btn.querySelector('.ap-day-state');
        if (st) st.textContent = on ? 'In' : 'Off';
      };
      document.querySelectorAll('#ap-detail .ap-day').forEach(btn => {
        btn.addEventListener('click', () => styleDay(btn, btn.getAttribute('data-on') !== '1'));
      });
      document.getElementById('ap-save').onclick = async () => {
        const days = {};
        ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'].forEach(d => {
          days[d] = document.querySelector(`.ap-day[data-day="${d}"]`).getAttribute('data-on') === '1';
        });
        await Api.post(`/attendance/pattern/${cid}`, { ...days, effective_from: document.getElementById('ap-from').value });
        (window.KT && window.KT.toast) ? KT.toast('Saved.', /save|sent|added|created|approved|deleted|removed|done|charged/i.test('Saved.') ? 'success' : 'info') : alert('Saved.');
      };
    };
    document.getElementById('ap-child').onchange = (e) => load(+e.target.value);
    load(children[0].id);
  }

  // ============================ Report cards (staff) ============================
  async function renderReportCards(main) {
    main.setAttribute('data-kt-pretty', '1');
    main.innerHTML = '<div style="padding:24px;">Loading…</div>';
    // Children DROPDOWN (was a raw "Child ID" number box — with nowhere in the app to
    // look that number up, it was unusable). Pull the agency's children so staff pick a
    // name; /admin/children is agency-scoped and returns {children:[...]} (empty if no
    // active agency is selected; 403 for a platform_admin with none).
    let kids = [];
    let kidsErr = 0;
    try {
      const kr = await Api.get('/admin/children');
      kids = (kr && Array.isArray(kr.children)) ? kr.children
           : Array.isArray(kr) ? kr
           : (kr && Array.isArray(kr.data)) ? kr.data : [];
    } catch (e) { kidsErr = (e && e.status) || 0; }
    // Educators / home-visitors can't hit /admin/children (agency-admin only) → it
    // 403s and the picker was empty. Fall back to their own centre roster.
    if (!kids.length) {
      try {
        const pr = await Api.get('/provider/children');
        if (pr && Array.isArray(pr.children) && pr.children.length) { kids = pr.children; kidsErr = 0; }
      } catch (e2) {}
    }
    const kidLabel = (c) => {
      const nm = ((c.first_name || '') + ' ' + (c.last_name || '')).trim() || c.preferred_name || ('Child #' + c.id);
      return esc(nm) + (c.centre_name ? ' · ' + esc(c.centre_name) : '');
    };
    const kidOptions = kids.map(c => `<option value="${c.id}">${kidLabel(c)}</option>`).join('');
    const noKidsMsg = kids.length ? '' :
      `<div style="margin-top:6px;color:#64748B;font-size:12.5px;">${kidsErr === 403 ? 'Select an agency (top-bar switcher) to load its children.' : 'No children found for this agency.'}</div>`;
    main.innerHTML = `<div style="padding:24px;max-width:1800px;margin:0 auto;">
      <div class="kt-page-hero">
        <h2>📑 Report cards</h2>
        <p>Generate AI-drafted narratives per HDLH domain. Educators sign &amp; submit; a director/admin signs &amp; approves before it reaches the family.</p>
      </div>
      <div id="rc-pending"></div>
      <div class="kt-card">
        <div style="max-width:920px;">
          <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:16px;max-width:620px;">
            <div>
              <label style="display:block;font-size:13px;font-weight:600;margin-bottom:6px;">Child</label>
              <style>#rc-cid,#rc-cid option{background:#fff !important;color:#0F172A !important;}</style>
              <select id="rc-cid" style="width:100%;padding:11px;border:1px solid #E2E8F0;border-radius:8px;background:#fff;color:#0F172A;color-scheme:light;font-size:14px;">
                <option value="">Select a child…</option>
                ${kidOptions}
              </select>
              ${noKidsMsg}
            </div>
            <div>
              <label style="display:block;font-size:13px;font-weight:600;margin-bottom:6px;">Term</label>
              <input id="rc-term" placeholder="e.g. 2026 Year-End" style="width:100%;padding:11px;border:1px solid #E2E8F0;border-radius:8px;font-size:14px;">
            </div>
          </div>
          <button id="rc-gen" class="kt-btn kt-btn-primary" style="margin-top:18px;">Generate via AI</button>
          <div id="rc-out" style="margin-top:20px;"></div>
        </div>
      </div>
    </div>`;
    document.getElementById('rc-gen').onclick = async () => {
      const cid = +document.getElementById('rc-cid').value;
      const term = document.getElementById('rc-term').value;
      if (!cid || !term) { (window.KT && window.KT.toast) ? KT.toast('Select a child and enter a term', /save|sent|added|created|approved|deleted|removed|done|charged/i.test('Select a child and enter a term') ? 'success' : 'info') : alert('Select a child and enter a term'); return; }
      const btn = document.getElementById('rc-gen');
      btn.disabled = true; btn.textContent = 'Generating… (15-30 sec)';
      try {
        const r = await Api.post('/report-cards/generate', { child_id: cid, term });
        document.getElementById('rc-out').innerHTML = ['belonging', 'wellbeing', 'engagement', 'expression'].map(d => `
          <h4 style="margin:16px 0 6px;color:#1F6080;text-transform:capitalize;">${d.replace('wellbeing', 'Well-being')}</h4>
          <textarea id="rc-${d}" rows="5" style="width:100%;padding:11px;border:1px solid #E2E8F0;border-radius:8px;font-family:inherit;font-size:14px;">${esc((r.narratives || {})[d] || '')}</textarea>
        `).join('') + `
          <h4 style="margin:16px 0 6px;color:#F59E0B;">Next steps</h4>
          <textarea id="rc-next" rows="5" style="width:100%;padding:11px;border:1px solid #E2E8F0;border-radius:8px;font-family:inherit;font-size:14px;">${esc(r.next_steps || '')}</textarea>
          <div id="rc-actions" style="display:flex;gap:8px;flex-wrap:wrap;margin-top:16px;"></div>
          <p style="font-size:12px;color:#94A3B8;margin-top:8px;line-height:1.5;">Educators sign &amp; submit for their director/admin to review, sign and send to the family.</p>
        `;
        var _rcRoles = []; try { _rcRoles = (JSON.parse(sessionStorage.getItem('kt_user') || '{}').roles) || []; } catch (e) {}
        var _rcApprover = ['centre_director', 'agency_admin', 'platform_admin'].some(function (x) { return _rcRoles.indexOf(x) !== -1; });
        var saveEdits = async function (status) {
          await Api.patch(`/report-cards/${r.id}`, {
            narrative_belonging: document.getElementById('rc-belonging').value,
            narrative_wellbeing: document.getElementById('rc-wellbeing').value,
            narrative_engagement: document.getElementById('rc-engagement').value,
            narrative_expression: document.getElementById('rc-expression').value,
            next_steps: document.getElementById('rc-next').value,
            status: status || 'reviewed',
          });
        };
        var _acts = document.getElementById('rc-actions');
        _acts.innerHTML = '<button id="rc-save" class="kt-btn kt-btn-primary">💾 Save edits</button>'
          + (_rcApprover
            ? '<button id="rc-approve" class="kt-btn kt-btn-success">✍️ Sign, approve &amp; send</button>'
            : '<button id="rc-submit" class="kt-btn kt-btn-success">✍️ Sign &amp; submit for approval</button>');
        document.getElementById('rc-save').onclick = async () => { await saveEdits('reviewed'); (window.KT && KT.toast) ? KT.toast('Saved.', 'success') : alert('Saved.'); };
        if (_rcApprover) {
          document.getElementById('rc-approve').onclick = async () => {
            await saveEdits('reviewed');
            var sig = await KT.signaturePad({ title: 'Director / admin sign-off', subtitle: 'Your signature approves this report card and sends it to the family.', okLabel: 'Approve & send' });
            if (!sig) return;
            try { await Api.post(`/report-cards/${r.id}/approve`, { admin_signature: sig }); (window.KT && KT.toast) ? KT.toast('Approved & sent to the family. ✓', 'success') : alert('Approved & sent.'); }
            catch (e) { (window.KT && KT.toast) ? KT.toast(e.message || 'Could not approve', 'error') : alert(e.message); }
          };
        } else {
          document.getElementById('rc-submit').onclick = async () => {
            await saveEdits('reviewed');
            var sig = await KT.signaturePad({ title: 'Educator signature', subtitle: 'Sign to submit this report card to your director/admin for review & approval.', okLabel: 'Sign & submit' });
            if (!sig) return;
            try { await Api.post(`/report-cards/${r.id}/submit`, { educator_signature: sig }); (window.KT && KT.toast) ? KT.toast('Submitted for approval — your director/admin was notified. ✓', 'success') : alert('Submitted for approval.'); }
            catch (e) { (window.KT && KT.toast) ? KT.toast(e.message || 'Could not submit', 'error') : alert(e.message); }
          };
        }
      } catch (e) { const _m = 'AI failed: ' + (e.message || e); (window.KT && window.KT.toast) ? KT.toast(_m, 'error') : alert(_m); }
      finally { btn.disabled = false; btn.textContent = 'Generate via AI'; }
    };

    // ── Director/admin: report cards awaiting approval ──────────────────
    async function reviewCard(childId, cardId) {
      let list; try { list = await Api.get('/report-cards/child/' + childId); } catch (e) { alert('Could not load the card.'); return; }
      const card = ((list && list.data) || []).find(c => +c.id === cardId);
      if (!card) { alert('Card not found.'); return; }
      const out = document.getElementById('rc-out'); if (!out) return;
      out.innerHTML = ['belonging', 'wellbeing', 'engagement', 'expression'].map(d =>
        `<h4 style="margin:16px 0 6px;color:#1F6080;text-transform:capitalize;">${d.replace('wellbeing', 'Well-being')}</h4>` +
        `<textarea id="rc-${d}" rows="5" style="width:100%;padding:11px;border:1px solid #E2E8F0;border-radius:8px;font-family:inherit;font-size:14px;">${esc(card['narrative_' + d] || '')}</textarea>`).join('') +
        `<h4 style="margin:16px 0 6px;color:#F59E0B;">Next steps</h4><textarea id="rc-next" rows="5" style="width:100%;padding:11px;border:1px solid #E2E8F0;border-radius:8px;font-family:inherit;font-size:14px;">${esc(card.next_steps || '')}</textarea>` +
        (card.educator_signature ? `<div style="margin-top:14px;font-size:12px;color:#64748B;">Educator signature</div><img src="${esc(card.educator_signature)}" style="height:66px;border:1px solid #E2E8F0;border-radius:8px;background:#fff;padding:2px;">` : '') +
        `<div style="display:flex;gap:8px;flex-wrap:wrap;margin-top:16px;">
          <button id="rc-r-save" class="kt-btn kt-btn-primary">💾 Save edits</button>
          <button id="rc-r-approve" class="kt-btn kt-btn-success">✍️ Sign, approve &amp; send</button>
          <button id="rc-r-reject" class="kt-btn" style="background:#FEE2E2;color:#B91C1C;">↩ Send back for changes</button>
        </div>`;
      out.scrollIntoView({ behavior: 'smooth', block: 'start' });
      const save = () => Api.patch('/report-cards/' + cardId, {
        narrative_belonging: document.getElementById('rc-belonging').value,
        narrative_wellbeing: document.getElementById('rc-wellbeing').value,
        narrative_engagement: document.getElementById('rc-engagement').value,
        narrative_expression: document.getElementById('rc-expression').value,
        next_steps: document.getElementById('rc-next').value,
      });
      document.getElementById('rc-r-save').onclick = async () => { await save(); (window.KT && KT.toast) ? KT.toast('Saved.', 'success') : alert('Saved.'); };
      document.getElementById('rc-r-approve').onclick = async () => {
        await save();
        const sig = await KT.signaturePad({ title: 'Director / admin sign-off', subtitle: 'Your signature approves this report card and sends it to the family.', okLabel: 'Approve & send' });
        if (!sig) return;
        try { await Api.post('/report-cards/' + cardId + '/approve', { admin_signature: sig }); (window.KT && KT.toast) ? KT.toast('Approved & sent to the family. ✓', 'success') : alert('Approved & sent.'); renderReportCards(main); }
        catch (e) { alert(e.message || 'Could not approve.'); }
      };
      document.getElementById('rc-r-reject').onclick = async () => {
        const note = prompt('What needs changing? The educator will see this note.'); if (note === null) return;
        try { await Api.post('/report-cards/' + cardId + '/reject', { note: note }); (window.KT && KT.toast) ? KT.toast('Sent back to the educator.', 'success') : alert('Sent back.'); renderReportCards(main); }
        catch (e) { alert(e.message || 'Could not send back.'); }
      };
    }

    (async () => {
      let roles = []; try { roles = (JSON.parse(sessionStorage.getItem('kt_user') || '{}').roles) || []; } catch (e) {}
      if (!['centre_director', 'agency_admin', 'platform_admin'].some(x => roles.indexOf(x) !== -1)) return;
      const host = document.getElementById('rc-pending'); if (!host) return;
      let r; try { r = await Api.get('/report-cards/pending'); } catch (e) { return; }
      const pend = (r && r.data) || [];
      if (!pend.length) return;
      host.innerHTML = '<div class="kt-card" style="border-left:4px solid #F59E0B;margin-bottom:16px;">' +
        `<h3 style="margin:0 0 4px;">✍️ Awaiting your approval <span style="background:#FEF3C7;color:#92400E;border-radius:999px;padding:2px 10px;font-size:12px;font-weight:800;">${pend.length}</span></h3>` +
        '<p style="font-size:12.5px;color:#64748B;margin:0 0 12px;">Educators submitted these report cards for your review, signature and approval.</p>' +
        pend.map(p => `<div style="display:flex;gap:12px;align-items:center;justify-content:space-between;flex-wrap:wrap;border:1px solid #FDE68A;background:#FFFBEB;border-radius:12px;padding:12px 14px;margin-bottom:8px;">
          <div><b>${esc(p.child)}</b> — ${esc(p.term)}<div style="font-size:12px;color:#92400E;">Submitted by ${esc(p.submitted_by || 'an educator')}</div></div>
          <button class="kt-btn kt-btn-primary rc-review" data-id="${p.id}" data-cid="${p.child_id}">Review &amp; sign</button></div>`).join('') + '</div>';
      host.querySelectorAll('.rc-review').forEach(b => { b.onclick = () => reviewCard(+b.getAttribute('data-cid'), +b.getAttribute('data-id')); });
    })();
  }

  // ============================ Activity zones ============================
  async function renderZones(main) {
    main.setAttribute('data-kt-pretty', '1');
    main.innerHTML = '<div style="padding:24px;">Loading…</div>';
    const _cr = await Api.get('/admin/centres').catch(() => ({})); const centres = _cr.centres || _cr.data || [];
    if (!centres.length) { main.innerHTML = '<div class="kt-card" style="margin:24px;text-align:center;color:#64748B;padding:40px;">No centres.</div>'; return; }
    const cid = centres[0].id;
    const today = new Date().toISOString().slice(0, 10);
    const [zones, report] = await Promise.all([
      Api.get(`/zones?centre_id=${cid}`),
      Api.get(`/zones/daily-report?centre_id=${cid}&date=${today}`),
    ]);
    const byZone = report.by_zone || {};
    const byChild = report.by_child || {};
    main.innerHTML = `<div style="padding:24px;max-width:1800px;margin:0 auto;">
      <div class="kt-page-hero">
        <h2>🎨 Activity zones</h2>
        <p>${esc(centres[0].name)} · ${fmtDate(today)} · ${report.total_visits} visits today.</p>
        <div class="kt-hero-actions"><button class="kt-btn kt-btn-ghost" id="z-new">+ New zone</button></div>
      </div>
      <div class="kt-card">
        <div class="kt-card-header"><h3 class="kt-card-title">Today's activity</h3></div>
        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:12px;">
          ${(zones.data || []).map(z => `<div style="background:${esc(z.color)}22;border:2px solid ${esc(z.color)};padding:18px;border-radius:12px;text-align:center;">
            <div style="font-size:32px;">${esc(z.icon)}</div>
            <div style="font-weight:700;color:#0F172A;margin-top:6px;">${esc(z.name)}</div>
            <div style="color:${esc(z.color)};font-weight:700;font-size:22px;margin-top:4px;">${byZone[z.name] || 0}</div>
            <button class="kt-btn kt-btn-primary" data-log="${z.id}" data-name="${esc(z.name)}" style="margin-top:10px;font-size:12px;padding:6px 14px;">+ Log visit</button>
          </div>`).join('') || '<div style="color:#64748B;padding:40px;text-align:center;grid-column:1/-1;">No zones set up.</div>'}
        </div>
      </div>
      <div class="kt-card">
        <div class="kt-card-header"><h3 class="kt-card-title">Per-child visits today</h3></div>
        ${Object.keys(byChild).length ? `<table>
          <thead><tr><th>Child</th><th>Visited</th></tr></thead>
          <tbody>${Object.entries(byChild).map(([name, visits]) => `<tr>
            <td><strong>${esc(name)}</strong></td>
            <td>${visits.map(v => `<span class="kt-pill kt-pill-info">${v.icon} ${esc(v.zone)} <span style="opacity:.7">@ ${v.time}</span></span>`).join(' ')}</td>
          </tr>`).join('')}</tbody>
        </table>` : '<div style="color:#64748B;padding:20px;text-align:center;">No visits logged today.</div>'}
      </div>
    </div>`;
    document.getElementById('z-new').onclick = () => openZoneModal(cid);
    main.querySelectorAll('button[data-log]').forEach(b => b.onclick = async () => {
      const child = prompt(`Child ID visiting ${b.dataset.name}?`);
      if (!child) return;
      await Api.post('/zones/visit', { child_id: +child, zone_id: +b.dataset.log });
      renderZones(main);
    });
  }
  function openZoneModal(centreId) {
    const m = document.createElement('div');
    m.style.cssText = 'position:fixed;inset:0;background:rgba(15,23,42,0.55);z-index:99999;display:flex;align-items:center;justify-content:center;';
    m.innerHTML = `<div style="background:#fff;padding:28px;border-radius:14px;max-width:440px;width:92%;">
      <h3 style="margin:0 0 16px;">New activity zone</h3>
      <label style="display:block;font-size:13px;font-weight:600;margin:10px 0 4px;">Name</label>
      <input id="z-name" placeholder="Art corner, Reading nook, Sensory…" style="width:100%;padding:9px;border:1px solid #E2E8F0;border-radius:8px;">
      <label style="display:block;font-size:13px;font-weight:600;margin:14px 0 4px;">Icon (emoji)</label>
      <input id="z-icon" value="🎨" style="width:100%;padding:9px;border:1px solid #E2E8F0;border-radius:8px;">
      <label style="display:block;font-size:13px;font-weight:600;margin:14px 0 4px;">Colour</label>
      <input id="z-color" type="color" value="#A855F7" style="width:100%;height:44px;padding:4px;border:1px solid #E2E8F0;border-radius:8px;">
      <div style="margin-top:20px;text-align:right;">
        <button id="z-cancel" style="background:#F1F5F9;border:0;padding:10px 18px;border-radius:8px;cursor:pointer;font-weight:600;">Cancel</button>
        <button id="z-save" class="kt-btn kt-btn-primary" style="margin-left:8px;">Save</button>
      </div></div>`;
    document.body.appendChild(m);
    m.querySelector('#z-cancel').onclick = () => m.remove();
    m.querySelector('#z-save').onclick = async () => {
      await Api.post('/zones', {
        centre_id: centreId,
        name: m.querySelector('#z-name').value,
        icon: m.querySelector('#z-icon').value,
        color: m.querySelector('#z-color').value,
      });
      m.remove();
      renderZones(document.querySelector('main'));
    };
  }

  // ============================ Support tickets ============================
  async function renderTickets(main) {
    main.setAttribute('data-kt-pretty', '1');
    main.innerHTML = '<div style="padding:24px;">Loading…</div>';
    const r = await Api.get('/tickets').catch(() => ({ data: [] }));
    main.innerHTML = `<div style="padding:24px;max-width:1800px;margin:0 auto;">
      <div class="kt-page-hero">
        <h2>🎫 Support tickets</h2>
        <p>Operational issues that aren't chat-style conversations. Track to resolution with audit trail.</p>
        <div class="kt-hero-actions"><button class="kt-btn kt-btn-ghost" id="tk-new">+ Raise ticket</button></div>
      </div>
      <div class="kt-card">
        <table>
          <thead><tr><th>Subject</th><th>Category</th><th>Priority</th><th>Status</th><th>Raised by</th><th>Created</th></tr></thead>
          <tbody>${(r.data || []).map(t => `<tr style="cursor:pointer;" data-open="${t.id}">
            <td><strong>${esc(t.subject)}</strong></td>
            <td><span class="kt-pill kt-pill-info">${esc(t.category)}</span></td>
            <td><span class="kt-pill ${t.priority === 'urgent' ? 'kt-pill-danger' : t.priority === 'high' ? 'kt-pill-warning' : 'kt-pill-info'}">${esc(t.priority)}</span></td>
            <td><span class="kt-pill ${t.status === 'resolved' || t.status === 'closed' ? 'kt-pill-success' : t.status === 'awaiting_user' ? 'kt-pill-warning' : 'kt-pill-info'}">${esc(t.status)}</span></td>
            <td>${esc(t.raised_by_name)}</td>
            <td>${fmtDate(t.created_at)}</td></tr>`).join('') || '<tr><td colspan="6" style="text-align:center;padding:40px;color:#64748B;">No tickets yet.</td></tr>'}</tbody>
        </table>
      </div>
    </div>`;
    document.getElementById('tk-new').onclick = () => openTicketModal();
    main.querySelectorAll('tr[data-open]').forEach(tr => tr.onclick = () => openTicket(+tr.dataset.open));
  }
  function openTicketModal() {
    const m = document.createElement('div');
    m.style.cssText = 'position:fixed;inset:0;background:rgba(15,23,42,0.55);z-index:99999;display:flex;align-items:center;justify-content:center;';
    m.innerHTML = `<div style="background:#fff;padding:28px;border-radius:14px;max-width:520px;width:92%;">
      <h3 style="margin:0 0 16px;">Raise a ticket</h3>
      <label style="display:block;font-size:13px;font-weight:600;margin:10px 0 4px;">Category</label>
      <select id="tk-cat" style="width:100%;padding:9px;border:1px solid #E2E8F0;border-radius:8px;">
        <option value="billing">Billing question</option><option value="enrollment">Enrollment</option>
        <option value="maintenance">Maintenance / repair</option><option value="technical">Technical / app issue</option>
        <option value="policy">Policy question</option><option value="other">Other</option>
      </select>
      <label style="display:block;font-size:13px;font-weight:600;margin:14px 0 4px;">Priority</label>
      <select id="tk-pri" style="width:100%;padding:9px;border:1px solid #E2E8F0;border-radius:8px;">
        <option value="low">Low</option><option value="normal" selected>Normal</option>
        <option value="high">High</option><option value="urgent">Urgent</option>
      </select>
      <label style="display:block;font-size:13px;font-weight:600;margin:14px 0 4px;">Subject</label>
      <input id="tk-sub" style="width:100%;padding:9px;border:1px solid #E2E8F0;border-radius:8px;">
      <label style="display:block;font-size:13px;font-weight:600;margin:14px 0 4px;">Description</label>
      <textarea id="tk-body" rows="5" style="width:100%;padding:9px;border:1px solid #E2E8F0;border-radius:8px;font-family:inherit;"></textarea>
      <div style="margin-top:20px;text-align:right;">
        <button id="tk-cancel" style="background:#F1F5F9;border:0;padding:10px 18px;border-radius:8px;cursor:pointer;font-weight:600;">Cancel</button>
        <button id="tk-save" class="kt-btn kt-btn-primary" style="margin-left:8px;">Raise ticket</button>
      </div></div>`;
    document.body.appendChild(m);
    m.querySelector('#tk-cancel').onclick = () => m.remove();
    m.querySelector('#tk-save').onclick = async () => {
      await Api.post('/tickets', {
        category: m.querySelector('#tk-cat').value,
        priority: m.querySelector('#tk-pri').value,
        subject: m.querySelector('#tk-sub').value,
        body: m.querySelector('#tk-body').value,
      });
      m.remove();
      renderTickets(document.querySelector('main'));
    };
  }
  async function openTicket(id) {
    const r = await Api.get(`/tickets/${id}`);
    const t = r.ticket;
    const msgs = r.messages || [];
    const m = document.createElement('div');
    m.style.cssText = 'position:fixed;inset:0;background:rgba(15,23,42,0.55);z-index:99999;display:flex;align-items:center;justify-content:center;';
    m.innerHTML = `<div style="background:#fff;padding:28px;border-radius:14px;max-width:600px;width:92%;max-height:88vh;overflow:auto;">
      <h3 style="margin:0 0 8px;color:#0F172A;">${esc(t.subject)}</h3>
      <div style="color:#475569;font-size:13px;margin-bottom:14px;">
        <span class="kt-pill kt-pill-info">${esc(t.category)}</span>
        <span class="kt-pill ${t.priority === 'urgent' ? 'kt-pill-danger' : t.priority === 'high' ? 'kt-pill-warning' : 'kt-pill-info'}">${esc(t.priority)}</span>
        <span class="kt-pill ${t.status === 'resolved' ? 'kt-pill-success' : 'kt-pill-warning'}">${esc(t.status)}</span>
      </div>
      <div style="background:#F8FAFC;padding:14px;border-radius:8px;font-size:14px;">${esc(t.body || '')}</div>
      <h4 style="margin:18px 0 10px;color:#0F172A;font-size:14px;">Replies</h4>
      <div style="max-height:240px;overflow:auto;">${msgs.map(msg => `<div style="padding:10px;background:#FAFCFE;border-radius:8px;margin-bottom:8px;">
        <div style="font-size:12px;color:#64748B;font-weight:600;">${esc(msg.author_name)} · ${fmtDate(msg.created_at)}</div>
        <div style="margin-top:4px;font-size:14px;">${esc(msg.body)}</div></div>`).join('') || '<div style="color:#64748B;font-size:13px;">No replies yet.</div>'}
      </div>
      <label style="display:block;font-size:13px;font-weight:600;margin:14px 0 4px;">Reply</label>
      <textarea id="tk-reply" rows="3" style="width:100%;padding:9px;border:1px solid #E2E8F0;border-radius:8px;font-family:inherit;"></textarea>
      <div style="margin-top:14px;display:flex;justify-content:space-between;align-items:center;">
        <button id="tk-resolve" class="kt-btn kt-btn-success" style="font-size:12px;padding:6px 12px;">Mark resolved</button>
        <div>
          <button id="tk-close" style="background:#F1F5F9;border:0;padding:10px 18px;border-radius:8px;cursor:pointer;font-weight:600;">Close</button>
          <button id="tk-send" class="kt-btn kt-btn-primary" style="margin-left:8px;">Reply</button>
        </div>
      </div></div>`;
    document.body.appendChild(m);
    m.querySelector('#tk-close').onclick = () => m.remove();
    m.querySelector('#tk-send').onclick = async () => {
      const body = m.querySelector('#tk-reply').value;
      if (!body) return;
      await Api.post(`/tickets/${id}/reply`, { body });
      m.remove();
      openTicket(id);
    };
    m.querySelector('#tk-resolve').onclick = async () => {
      await Api.patch(`/tickets/${id}/status`, { status: 'resolved' });
      m.remove();
      renderTickets(document.querySelector('main'));
    };
  }

  // ============================ Photo tagging (extends feed) ============================
  async function renderPhotoTagging(main) {
    main.setAttribute('data-kt-pretty', '1');
    main.innerHTML = `<div style="padding:24px;max-width:1800px;margin:0 auto;">
      <div class="kt-page-hero">
        <h2>🪄 Auto-tag photos</h2>
        <p>Run AI classification on a photo to identify which children appear in it. Staff confirm or remove tags.</p>
      </div>
      <div class="kt-card">
        <label style="font-size:13px;font-weight:600;">Photo ID to classify</label>
        <div style="display:flex;gap:8px;margin-top:6px;">
          <input id="pt-id" type="number" placeholder="e.g. 12" style="flex:1;padding:11px;border:1px solid #E2E8F0;border-radius:8px;">
          <button id="pt-go" class="kt-btn kt-btn-primary">Classify</button>
        </div>
        <div id="pt-out" style="margin-top:18px;"></div>
      </div>
    </div>`;
    document.getElementById('pt-go').onclick = async () => {
      const pid = +document.getElementById('pt-id').value;
      const btn = document.getElementById('pt-go');
      btn.disabled = true; btn.textContent = 'AI running…';
      try {
        const r = await Api.post(`/photos/${pid}/classify`, {});
        const tags = await Api.get(`/photos/${pid}/tags`);
        document.getElementById('pt-out').innerHTML = `
          <div style="color:#15803D;font-weight:600;">✓ Tagged ${r.tagged} child(ren)</div>
          <table style="margin-top:14px;">
            <thead><tr><th>Child</th><th>Confidence</th><th>Source</th><th></th></tr></thead>
            <tbody>${(tags.data || []).map(t => `<tr>
              <td><strong>${esc(t.child_name)}</strong></td>
              <td>${t.confidence ? (parseFloat(t.confidence) * 100).toFixed(0) + '%' : '—'}</td>
              <td>${t.ai_tagged ? '🤖 AI' : '✋ Manual'}</td>
              <td>${t.confirmed ? '<span class="kt-pill kt-pill-success">Confirmed</span>' : `<button class="kt-btn kt-btn-primary" data-confirm="${t.photo_id}-${t.child_id}" style="font-size:12px;padding:6px 12px;">Confirm</button>`}</td>
            </tr>`).join('')}</tbody>
          </table>`;
        main.querySelectorAll('button[data-confirm]').forEach(b => b.onclick = async () => {
          const [pid, cid] = b.dataset.confirm.split('-').map(Number);
          await Api.post('/photos/tag-confirm', { photo_id: pid, child_id: cid, confirmed: true });
          b.outerHTML = '<span class="kt-pill kt-pill-success">Confirmed</span>';
        });
      } catch (e) { document.getElementById('pt-out').innerHTML = `<div style="color:#B91C1C;">${esc(e.message || 'Failed')}</div>`; }
      finally { btn.disabled = false; btn.textContent = 'Classify'; }
    };
  }

  window.KT = KT;
  window.KT.V22p59 = {
    renderDirectory, renderConferences, renderTripGps, renderAttendancePattern,
    renderReportCards, renderZones, renderTickets, renderPhotoTagging,
  };
})(window);
