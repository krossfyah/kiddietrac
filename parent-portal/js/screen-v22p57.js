/* v22p57 — 8 new screens. Uses kt-design-v22p55.css conventions. */
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

  // ============================ Pickup authorizations ============================
  async function renderPickupAuth(main) {
    main.setAttribute('data-kt-pretty', '1');
    main.innerHTML = '<div style="padding:24px;">Loading children…</div>';
    // grab the parent's children
    const childrenRes = await Api.get('/parent/children').catch(() => ({ data: [] }));
    const children = childrenRes.data || childrenRes || [];
    if (!children.length) { main.innerHTML = '<div class="kt-card" style="margin:24px;text-align:center;color:#94A3B8;padding:40px;">No children on file.</div>'; return; }

    const auths = {};
    for (const c of children) {
      const r = await Api.get(`/family/pickup-auth/${c.id}`).catch(() => ({ data: [] }));
      auths[c.id] = r.data || [];
    }

    main.innerHTML = `<div style="padding:24px;max-width:1800px;margin:0 auto;">
      <div class="kt-page-hero">
        <h2>🪪 Pickup authorizations</h2>
        <p>People other than guardians who are allowed to collect your child. Staff verify ID at pickup.</p>
      </div>
      ${children.map(c => `<div class="kt-card">
        <div class="kt-card-header">
          <h3 class="kt-card-title">${esc(c.first_name)} ${esc(c.last_name)}</h3>
          <button class="kt-btn kt-btn-primary" data-add-child="${c.id}">+ Add authorised person</button>
        </div>
        ${(auths[c.id] || []).length ? `<table>
          <thead><tr><th>Name</th><th>Relationship</th><th>Phone</th><th>Expires</th><th></th></tr></thead>
          <tbody>${auths[c.id].map(a => `<tr>
            <td><strong>${esc(a.full_name)}</strong></td>
            <td>${esc(a.relationship || '')}</td>
            <td>${esc(a.phone || '')}</td>
            <td>${a.expires_at ? fmtDate(a.expires_at) : '—'}</td>
            <td><button class="kt-btn kt-btn-danger" data-rm="${a.id}">Remove</button></td>
          </tr>`).join('')}</tbody>
        </table>` : '<div style="color:#94A3B8;padding:18px 0;">No additional authorisations.</div>'}
      </div>`).join('')}
    </div>`;
    main.querySelectorAll('button[data-add-child]').forEach(b => b.onclick = () => openPickupModal(+b.dataset.addChild));
    main.querySelectorAll('button[data-rm]').forEach(b => b.onclick = async () => {
      if (!confirm('Remove this authorisation?')) return;
      await Api.delete(`/family/pickup-auth/${b.dataset.rm}`);
      renderPickupAuth(main);
    });
  }
  function openPickupModal(childId) {
    const m = document.createElement('div');
    m.style.cssText = 'position:fixed;inset:0;background:rgba(15,23,42,0.55);z-index:99999;display:flex;align-items:center;justify-content:center;';
    m.innerHTML = `<div style="background:#fff;padding:28px;border-radius:14px;max-width:460px;width:92%;">
      <h3 style="margin:0 0 16px;color:#0F172A;">Authorise a pickup person</h3>
      <label style="display:block;font-size:13px;font-weight:600;margin:10px 0 4px;">Full name *</label>
      <input id="pa-name" style="width:100%;padding:9px;border:1px solid #E2E8F0;border-radius:8px;">
      <label style="display:block;font-size:13px;font-weight:600;margin:14px 0 4px;">Relationship</label>
      <input id="pa-rel" placeholder="grandparent, aunt, nanny…" style="width:100%;padding:9px;border:1px solid #E2E8F0;border-radius:8px;">
      <label style="display:block;font-size:13px;font-weight:600;margin:14px 0 4px;">Phone</label>
      <input id="pa-phone" placeholder="+14165550101" style="width:100%;padding:9px;border:1px solid #E2E8F0;border-radius:8px;">
      <label style="display:block;font-size:13px;font-weight:600;margin:14px 0 4px;">Expires on (optional)</label>
      <input id="pa-exp" type="date" style="width:100%;padding:9px;border:1px solid #E2E8F0;border-radius:8px;">
      <label style="display:block;font-size:13px;font-weight:600;margin:14px 0 4px;">Notes</label>
      <textarea id="pa-notes" rows="2" style="width:100%;padding:9px;border:1px solid #E2E8F0;border-radius:8px;"></textarea>
      <div style="margin-top:20px;text-align:right;">
        <button id="pa-cancel" style="background:#F1F5F9;border:0;padding:10px 18px;border-radius:8px;cursor:pointer;font-weight:600;">Cancel</button>
        <button id="pa-save" class="kt-btn kt-btn-primary" style="margin-left:8px;">Save</button>
      </div></div>`;
    document.body.appendChild(m);
    m.querySelector('#pa-cancel').onclick = () => m.remove();
    m.querySelector('#pa-save').onclick = async () => {
      if (!m.querySelector('#pa-name').value.trim()) { (window.KT && window.KT.toast) ? KT.toast('Name is required', /save|sent|added|created|approved|deleted|removed|done|charged/i.test('Name is required') ? 'success' : 'info') : alert('Name is required'); return; }
      await Api.post('/family/pickup-auth', {
        child_id: childId,
        full_name: m.querySelector('#pa-name').value,
        relationship: m.querySelector('#pa-rel').value,
        phone: m.querySelector('#pa-phone').value,
        expires_at: m.querySelector('#pa-exp').value || null,
        notes: m.querySelector('#pa-notes').value,
      });
      m.remove();
      renderPickupAuth(document.querySelector('main'));
    };
  }

  // ============================ Daily check-in ============================
  async function renderCheckin(main) {
    main.setAttribute('data-kt-pretty', '1');
    main.innerHTML = '<div style="padding:24px;">Loading…</div>';
    const childrenRes = await Api.get('/parent/children').catch(() => ({ data: [] }));
    const children = childrenRes.data || childrenRes || [];
    if (!children.length) { main.innerHTML = '<div class="kt-card" style="margin:24px;text-align:center;color:#94A3B8;padding:40px;">No children on file.</div>'; return; }
    const today = (await Api.get(`/family/checkin/today/${children[0].id}`)).data || {};
    main.innerHTML = `<div style="padding:24px;max-width:1800px;margin:0 auto;">
      <div class="kt-page-hero">
        <h2>☀ Daily check-in</h2>
        <p>Quick drop-off survey. Staff see this on the Today screen so they know what to expect.</p>
      </div>
      <div class="kt-card">
        <label style="display:block;font-size:13px;font-weight:600;margin-bottom:8px;">Child</label>
        <select id="ck-child" style="width:100%;padding:11px;border:1px solid #E2E8F0;border-radius:8px;font-size:15px;">${children.map(c => `<option value="${c.id}">${esc(c.first_name)} ${esc(c.last_name)}</option>`).join('')}</select>

        <h4 style="margin-top:24px;color:#0F172A;font-size:14px;">Mood this morning</h4>
        <div id="ck-mood" style="display:flex;gap:10px;flex-wrap:wrap;">
          ${['happy 😊','sleepy 😴','fussy 😩','sick 🤒','quiet 😶'].map(m => {
            const k = m.split(' ')[0];
            const sel = today.mood === k;
            return `<button data-mood="${k}" class="kt-tag" style="background:${sel ? '#1F6080' : '#F1F5F9'};color:${sel ? '#fff' : '#0F172A'};border:0;padding:10px 18px;border-radius:10px;cursor:pointer;font-size:15px;font-weight:600;">${m}</button>`;
          }).join('')}
        </div>

        <h4 style="margin-top:24px;color:#0F172A;font-size:14px;">Sleep last night</h4>
        <div id="ck-sleep" style="display:flex;gap:10px;flex-wrap:wrap;">
          ${['great 🌙','okay 🌒','restless 🌖','poor 🌘'].map(s => {
            const k = s.split(' ')[0];
            const sel = today.sleep_quality === k;
            return `<button data-sleep="${k}" class="kt-tag" style="background:${sel ? '#1F6080' : '#F1F5F9'};color:${sel ? '#fff' : '#0F172A'};border:0;padding:10px 18px;border-radius:10px;cursor:pointer;font-size:15px;font-weight:600;">${s}</button>`;
          }).join('')}
        </div>

        <h4 style="margin-top:24px;color:#0F172A;font-size:14px;">Ate breakfast?</h4>
        <div style="display:flex;gap:10px;">
          <button data-ate="1" class="kt-tag" style="background:${today.ate_breakfast == 1 ? '#10B981' : '#F1F5F9'};color:${today.ate_breakfast == 1 ? '#fff' : '#0F172A'};border:0;padding:10px 18px;border-radius:10px;cursor:pointer;font-size:15px;font-weight:600;">Yes</button>
          <button data-ate="0" class="kt-tag" style="background:${today.ate_breakfast === 0 ? '#EF4444' : '#F1F5F9'};color:${today.ate_breakfast === 0 ? '#fff' : '#0F172A'};border:0;padding:10px 18px;border-radius:10px;cursor:pointer;font-size:15px;font-weight:600;">No</button>
        </div>

        <label style="display:block;font-size:13px;font-weight:600;margin:24px 0 4px;">Medication today</label>
        <input id="ck-med" placeholder="e.g. Tylenol at 7am for fever" value="${esc(today.medication_today || '')}" style="width:100%;padding:11px;border:1px solid #E2E8F0;border-radius:8px;">

        <label style="display:block;font-size:13px;font-weight:600;margin:14px 0 4px;">Notes for staff</label>
        <textarea id="ck-notes" rows="3" placeholder="Anything else they should know today?" style="width:100%;padding:11px;border:1px solid #E2E8F0;border-radius:8px;font-family:inherit;">${esc(today.parent_notes || '')}</textarea>

        <button id="ck-save" class="kt-btn kt-btn-primary" style="margin-top:20px;width:100%;padding:14px;font-size:15px;">Submit check-in</button>
        <div id="ck-msg" style="margin-top:10px;font-size:13px;text-align:center;"></div>
      </div>
    </div>`;
    let state = { mood: today.mood, sleep_quality: today.sleep_quality, ate_breakfast: today.ate_breakfast };
    const wireToggles = () => {
      main.querySelectorAll('button[data-mood]').forEach(b => b.onclick = () => {
        state.mood = b.dataset.mood;
        main.querySelectorAll('button[data-mood]').forEach(x => { x.style.background = x.dataset.mood === state.mood ? '#1F6080' : '#F1F5F9'; x.style.color = x.dataset.mood === state.mood ? '#fff' : '#0F172A'; });
      });
      main.querySelectorAll('button[data-sleep]').forEach(b => b.onclick = () => {
        state.sleep_quality = b.dataset.sleep;
        main.querySelectorAll('button[data-sleep]').forEach(x => { x.style.background = x.dataset.sleep === state.sleep_quality ? '#1F6080' : '#F1F5F9'; x.style.color = x.dataset.sleep === state.sleep_quality ? '#fff' : '#0F172A'; });
      });
      main.querySelectorAll('button[data-ate]').forEach(b => b.onclick = () => {
        state.ate_breakfast = +b.dataset.ate;
        main.querySelectorAll('button[data-ate]').forEach(x => {
          const v = +x.dataset.ate;
          x.style.background = v === state.ate_breakfast ? (v ? '#10B981' : '#EF4444') : '#F1F5F9';
          x.style.color = v === state.ate_breakfast ? '#fff' : '#0F172A';
        });
      });
    };
    wireToggles();
    main.querySelector('#ck-save').onclick = async () => {
      await Api.post('/family/checkin', {
        child_id: +main.querySelector('#ck-child').value,
        mood: state.mood,
        sleep_quality: state.sleep_quality,
        ate_breakfast: state.ate_breakfast,
        medication_today: main.querySelector('#ck-med').value,
        parent_notes: main.querySelector('#ck-notes').value,
      });
      main.querySelector('#ck-msg').innerHTML = '<span style="color:#15803D;font-weight:600;">✓ Saved. Staff will see your note today.</span>';
    };
  }

  // ============================ Child trends ============================
  async function renderTrends(main) {
    main.setAttribute('data-kt-pretty', '1');
    main.innerHTML = '<div style="padding:24px;">Loading children…</div>';
    const childrenRes = await Api.get('/parent/children').catch(() => Api.get('/admin/children').catch(() => ({ data: [] })));
    const children = childrenRes.data || childrenRes || [];
    if (!children.length) { main.innerHTML = '<div class="kt-card" style="margin:24px;text-align:center;color:#94A3B8;padding:40px;">No children.</div>'; return; }
    const id = children[0].id;
    const t = await Api.get(`/analytics/trends/${id}?days=30`);

    const byDay = t.log_counts_by_day || {};
    const days = Object.keys(byDay).sort();
    const max = Math.max(...Object.values(byDay), 1);
    const moods = t.mood_distribution || {};
    const moodTotal = Object.values(moods).reduce((a, b) => a + b, 0);

    main.innerHTML = `<div style="padding:24px;max-width:1800px;margin:0 auto;">
      <div class="kt-page-hero">
        <h2>📊 ${esc(children[0].first_name)}'s 30-day trends</h2>
        <p>Sleep, diaper, and mood patterns. Pulled from care logs + daily check-ins.</p>
      </div>
      <div class="kt-kpi-grid">
        <div class="kt-kpi kt-kpi-info"><div class="kt-kpi-label">Avg nap minutes</div><div class="kt-kpi-value">${t.sleep_avg_minutes ?? '—'}</div></div>
        <div class="kt-kpi kt-kpi-purple"><div class="kt-kpi-label">Diapers/day</div><div class="kt-kpi-value">${t.diaper_avg_per_day}</div></div>
        <div class="kt-kpi kt-kpi-success"><div class="kt-kpi-label">Total logs (30d)</div><div class="kt-kpi-value">${t.logs_total}</div></div>
        <div class="kt-kpi kt-kpi-warning"><div class="kt-kpi-label">Most common mood</div><div class="kt-kpi-value">${moodTotal ? Object.entries(moods).sort((a, b) => b[1] - a[1])[0][0] : '—'}</div></div>
      </div>
      <div class="kt-card">
        <div class="kt-card-header"><h3 class="kt-card-title">Activity heatmap by day</h3></div>
        <div style="display:flex;gap:3px;align-items:flex-end;height:160px;padding:14px 0;">
          ${days.map(d => `<div style="flex:1;min-width:8px;background:linear-gradient(180deg,#1F6080,#3a86ad);height:${(byDay[d]/max)*140}px;border-radius:3px 3px 0 0;" title="${d}: ${byDay[d]}"></div>`).join('')}
        </div>
        <div style="display:flex;justify-content:space-between;color:#94A3B8;font-size:11px;">
          <span>${days[0] || ''}</span><span>${days[days.length - 1] || ''}</span>
        </div>
      </div>
      <div class="kt-card">
        <div class="kt-card-header"><h3 class="kt-card-title">Mood distribution</h3></div>
        ${Object.entries(moods).map(([k, v]) => `<div style="display:flex;align-items:center;gap:12px;margin:8px 0;">
          <div style="width:80px;font-size:13px;text-transform:capitalize;">${esc(k)}</div>
          <div style="flex:1;background:#F1F5F9;height:14px;border-radius:7px;overflow:hidden;"><div style="background:linear-gradient(90deg,#1F6080,#3a86ad);height:100%;width:${(v / moodTotal) * 100}%;"></div></div>
          <div style="font-weight:700;color:#0F172A;min-width:30px;text-align:right;">${v}</div>
        </div>`).join('') || '<div style="color:#94A3B8;">No mood data yet — start daily check-ins.</div>'}
      </div>
    </div>`;
  }

  // ============================ HDLH gaps ============================
  async function renderHdlhGaps(main) {
    main.setAttribute('data-kt-pretty', '1');
    main.innerHTML = '<div style="padding:24px;">Loading children…</div>';
    const childrenRes = await Api.get('/admin/children').catch(() => ({ data: [] }));
    const children = (childrenRes.data || []).slice(0, 30);
    if (!children.length) { main.innerHTML = '<div class="kt-card" style="margin:24px;text-align:center;color:#94A3B8;padding:40px;">No children on file.</div>'; return; }
    const results = [];
    for (const c of children) {
      const r = await Api.get(`/analytics/hdlh-gaps/${c.id}`).catch(() => null);
      if (r) results.push({ ...c, gaps: r.gaps_count, domains: r.data });
    }
    results.sort((a, b) => b.gaps - a.gaps);
    main.innerHTML = `<div style="padding:24px;max-width:1800px;margin:0 auto;">
      <div class="kt-page-hero">
        <h2>🎯 HDLH gap detection</h2>
        <p>Children with fewer observations in any of the four HDLH domains. Lower-rated ones first — those need attention.</p>
      </div>
      <div class="kt-card">
        <table>
          <thead><tr><th>Child</th><th>Gaps</th><th>Belonging</th><th>Well-being</th><th>Engagement</th><th>Expression</th></tr></thead>
          <tbody>${results.map(r => {
            const domain = (name) => {
              const d = (r.domains || []).find(x => x.domain === name);
              if (!d) return '';
              const colour = d.status === 'gap' ? '#EF4444' : d.status === 'moderate' ? '#F59E0B' : '#10B981';
              return `<span class="kt-pill" style="background:${colour}22;color:${colour};">${d.count}</span>`;
            };
            return `<tr>
              <td><strong>${esc(r.first_name)} ${esc(r.last_name)}</strong></td>
              <td><span class="kt-pill ${r.gaps >= 2 ? 'kt-pill-danger' : r.gaps === 1 ? 'kt-pill-warning' : 'kt-pill-success'}">${r.gaps}</span></td>
              <td>${domain('Belonging')}</td><td>${domain('Well-being')}</td>
              <td>${domain('Engagement')}</td><td>${domain('Expression')}</td>
            </tr>`;
          }).join('')}</tbody>
        </table>
      </div>
    </div>`;
  }

  // ============================ Drip campaigns ============================
  async function renderDrip(main) {
    main.setAttribute('data-kt-pretty', '1');
    main.innerHTML = '<div style="padding:24px;">Loading…</div>';
    const [list, stats] = await Promise.all([
      Api.get('/marketing/drip').catch(() => ({ data: [] })),
      Api.get('/marketing/drip/stats').catch(() => ({})),
    ]);
    main.innerHTML = `<div style="padding:24px;max-width:1800px;margin:0 auto;">
      <div class="kt-page-hero">
        <h2>💧 Drip campaigns</h2>
        <p>Auto-send follow-up emails N days after a trigger event (tour booked, enrollment complete, birthday, etc).</p>
        <div class="kt-hero-actions"><button class="kt-btn kt-btn-ghost" id="dp-new">+ New drip campaign</button></div>
      </div>
      <div class="kt-kpi-grid">
        <div class="kt-kpi"><div class="kt-kpi-label">Active drip campaigns</div><div class="kt-kpi-value">${stats.campaigns || 0}</div></div>
        <div class="kt-kpi kt-kpi-success"><div class="kt-kpi-label">Sent (all time)</div><div class="kt-kpi-value">${stats.sent || 0}</div></div>
        <div class="kt-kpi kt-kpi-warning"><div class="kt-kpi-label">Queued</div><div class="kt-kpi-value">${stats.queued || 0}</div></div>
        <div class="kt-kpi kt-kpi-danger"><div class="kt-kpi-label">Failed</div><div class="kt-kpi-value">${stats.failed || 0}</div></div>
      </div>
      <div class="kt-card">
        <table>
          <thead><tr><th>Title</th><th>Trigger</th><th>Delay</th><th>Status</th></tr></thead>
          <tbody>${(list.data || []).map(d => `<tr>
            <td><strong>${esc(d.title)}</strong></td>
            <td><span class="kt-pill kt-pill-info">${esc(d.trigger_event)}</span></td>
            <td>${d.trigger_delay_days} day(s)</td>
            <td><span class="kt-pill ${d.status === 'active' ? 'kt-pill-success' : 'kt-pill-warning'}">${esc(d.status)}</span></td>
          </tr>`).join('') || '<tr><td colspan="4" style="text-align:center;padding:40px;color:#94A3B8;">No drip campaigns set up.</td></tr>'}</tbody>
        </table>
      </div>
    </div>`;
    document.getElementById('dp-new').onclick = () => openDripModal();
  }
  function openDripModal() {
    const m = document.createElement('div');
    m.style.cssText = 'position:fixed;inset:0;background:rgba(15,23,42,0.55);z-index:99999;display:flex;align-items:center;justify-content:center;';
    m.innerHTML = `<div style="background:#fff;padding:28px;border-radius:14px;max-width:560px;width:92%;max-height:88vh;overflow:auto;">
      <h3 style="margin:0 0 16px;color:#0F172A;">New drip campaign</h3>
      <label style="display:block;font-size:13px;font-weight:600;margin:10px 0 4px;">Title</label>
      <input id="dp-title" style="width:100%;padding:9px;border:1px solid #E2E8F0;border-radius:8px;">
      <label style="display:block;font-size:13px;font-weight:600;margin:14px 0 4px;">Trigger event</label>
      <select id="dp-trig" style="width:100%;padding:9px;border:1px solid #E2E8F0;border-radius:8px;">
        <option value="tour_booked">After tour booked</option>
        <option value="enrollment_complete">After enrollment complete</option>
        <option value="birthday">On child's birthday</option>
        <option value="inactivity_30d">After 30 days inactive</option>
      </select>
      <label style="display:block;font-size:13px;font-weight:600;margin:14px 0 4px;">Delay (days)</label>
      <input id="dp-delay" type="number" value="3" min="0" max="365" style="width:100%;padding:9px;border:1px solid #E2E8F0;border-radius:8px;">
      <label style="display:block;font-size:13px;font-weight:600;margin:14px 0 4px;">Email subject</label>
      <input id="dp-subj" style="width:100%;padding:9px;border:1px solid #E2E8F0;border-radius:8px;">
      <label style="display:block;font-size:13px;font-weight:600;margin:14px 0 4px;">Email body (HTML allowed)</label>
      <textarea id="dp-body" rows="6" style="width:100%;padding:9px;border:1px solid #E2E8F0;border-radius:8px;font-family:inherit;"></textarea>
      <div style="margin-top:20px;text-align:right;">
        <button id="dp-cancel" style="background:#F1F5F9;border:0;padding:10px 18px;border-radius:8px;cursor:pointer;font-weight:600;">Cancel</button>
        <button id="dp-save" class="kt-btn kt-btn-primary" style="margin-left:8px;">Create</button>
      </div></div>`;
    document.body.appendChild(m);
    m.querySelector('#dp-cancel').onclick = () => m.remove();
    m.querySelector('#dp-save').onclick = async () => {
      await Api.post('/marketing/drip', {
        title: m.querySelector('#dp-title').value,
        subject: m.querySelector('#dp-subj').value,
        body_html: m.querySelector('#dp-body').value,
        trigger_event: m.querySelector('#dp-trig').value,
        trigger_delay_days: +m.querySelector('#dp-delay').value,
      });
      m.remove();
      renderDrip(document.querySelector('main'));
    };
  }

  // ============================ Curriculum library ============================
  async function renderCurriculum(main) {
    main.setAttribute('data-kt-pretty', '1');
    main.innerHTML = '<div style="padding:24px;">Loading library…</div>';
    const r = await Api.get('/curriculum').catch(() => ({ data: [] }));
    const rows = r.data || [];
    main.innerHTML = `<div style="padding:24px;max-width:1800px;margin:0 auto;">
      <div class="kt-page-hero">
        <h2>📚 Curriculum library</h2>
        <p>Shared lesson plans across your agency. Click "Use" to copy a template into your centre.</p>
        <div class="kt-hero-actions"><button class="kt-btn kt-btn-ghost" id="cl-new">+ New template</button></div>
      </div>
      <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(320px,1fr));gap:18px;">
        ${rows.map(t => `<div class="kt-card">
          <div style="display:flex;justify-content:space-between;align-items:start;gap:10px;">
            <div>
              <h3 style="margin:0;color:#0F172A;font-size:16px;font-weight:700;">${esc(t.title)}</h3>
              <div style="color:#475569;font-size:12px;margin-top:4px;">${esc(t.author_name || '')}</div>
            </div>
            ${t.is_featured ? '<span class="kt-pill kt-pill-warning">⭐ Featured</span>' : ''}
          </div>
          <div style="margin:14px 0;font-size:13px;color:#475569;line-height:1.5;">${esc((t.description || '').substring(0, 180))}</div>
          <div style="display:flex;gap:6px;flex-wrap:wrap;margin-bottom:14px;">
            ${t.age_band ? `<span class="kt-pill kt-pill-info">${esc(t.age_band)}</span>` : ''}
            ${t.duration_minutes ? `<span class="kt-pill kt-pill-purple">${t.duration_minutes} min</span>` : ''}
            ${(t.hdlh_domains || []).map(d => `<span class="kt-pill kt-pill-success">${esc(d)}</span>`).join('')}
          </div>
          <div style="display:flex;justify-content:space-between;align-items:center;border-top:1px solid #F1F5F9;padding-top:12px;">
            <span style="color:#94A3B8;font-size:12px;">Used ${t.use_count}× • ${esc(t.visibility)}</span>
            <button class="kt-btn kt-btn-primary" data-use="${t.id}">Use template</button>
          </div>
        </div>`).join('') || '<div class="kt-card" style="grid-column:1/-1;text-align:center;color:#94A3B8;padding:60px;">No curriculum templates yet.</div>'}
      </div>
    </div>`;
    document.getElementById('cl-new').onclick = () => openCurriculumModal();
    main.querySelectorAll('button[data-use]').forEach(b => b.onclick = async () => {
      const r = await Api.post(`/curriculum/${b.dataset.use}/use`, {});
      (window.KT && window.KT.toast) ? KT.toast(`Loaded "${r.data.title}". Use it as a starting point for a new lesson plan.`, /save|sent|added|created|approved|deleted|removed|done|charged/i.test(`Loaded "${r.data.title}". Use it as a starting point for a new lesson plan.`) ? 'success' : 'info') : alert(`Loaded "${r.data.title}". Use it as a starting point for a new lesson plan.`);
    });
  }
  function openCurriculumModal() {
    const m = document.createElement('div');
    m.style.cssText = 'position:fixed;inset:0;background:rgba(15,23,42,0.55);z-index:99999;display:flex;align-items:center;justify-content:center;';
    m.innerHTML = `<div style="background:#fff;padding:28px;border-radius:14px;max-width:560px;width:92%;max-height:88vh;overflow:auto;">
      <h3 style="margin:0 0 16px;color:#0F172A;">New curriculum template</h3>
      <label style="display:block;font-size:13px;font-weight:600;margin:10px 0 4px;">Title *</label>
      <input id="cu-title" style="width:100%;padding:9px;border:1px solid #E2E8F0;border-radius:8px;">
      <label style="display:block;font-size:13px;font-weight:600;margin:14px 0 4px;">Description</label>
      <textarea id="cu-desc" rows="4" style="width:100%;padding:9px;border:1px solid #E2E8F0;border-radius:8px;font-family:inherit;"></textarea>
      <label style="display:block;font-size:13px;font-weight:600;margin:14px 0 4px;">Age band</label>
      <select id="cu-age" style="width:100%;padding:9px;border:1px solid #E2E8F0;border-radius:8px;">
        <option value="infant">Infant (0-18mo)</option><option value="toddler">Toddler (18-30mo)</option>
        <option value="preschool">Preschool (30-72mo)</option><option value="school_age">School-age (72mo+)</option>
      </select>
      <label style="display:block;font-size:13px;font-weight:600;margin:14px 0 4px;">Duration (minutes)</label>
      <input id="cu-dur" type="number" min="5" max="240" value="30" style="width:100%;padding:9px;border:1px solid #E2E8F0;border-radius:8px;">
      <label style="display:block;font-size:13px;font-weight:600;margin:14px 0 4px;">Materials (one per line)</label>
      <textarea id="cu-mat" rows="3" placeholder="Construction paper&#10;Glue sticks&#10;Crayons" style="width:100%;padding:9px;border:1px solid #E2E8F0;border-radius:8px;font-family:inherit;"></textarea>
      <label style="display:block;font-size:13px;font-weight:600;margin:14px 0 4px;">HDLH domains (Ctrl+click to multi-select)</label>
      <select id="cu-hdlh" multiple size="4" style="width:100%;padding:9px;border:1px solid #E2E8F0;border-radius:8px;">
        <option value="Belonging">Belonging</option><option value="Well-being">Well-being</option>
        <option value="Engagement">Engagement</option><option value="Expression">Expression</option>
      </select>
      <label style="display:block;font-size:13px;font-weight:600;margin:14px 0 4px;">Visibility</label>
      <select id="cu-vis" style="width:100%;padding:9px;border:1px solid #E2E8F0;border-radius:8px;">
        <option value="agency">Agency-wide</option><option value="centre">Centre only</option><option value="private">Private</option>
      </select>
      <div style="margin-top:20px;text-align:right;">
        <button id="cu-cancel" style="background:#F1F5F9;border:0;padding:10px 18px;border-radius:8px;cursor:pointer;font-weight:600;">Cancel</button>
        <button id="cu-save" class="kt-btn kt-btn-primary" style="margin-left:8px;">Save template</button>
      </div></div>`;
    document.body.appendChild(m);
    m.querySelector('#cu-cancel').onclick = () => m.remove();
    m.querySelector('#cu-save').onclick = async () => {
      const hdlh = Array.from(m.querySelector('#cu-hdlh').selectedOptions).map(o => o.value);
      const materials = m.querySelector('#cu-mat').value.split('\n').filter(Boolean);
      await Api.post('/curriculum', {
        title: m.querySelector('#cu-title').value,
        description: m.querySelector('#cu-desc').value,
        age_band: m.querySelector('#cu-age').value,
        duration_minutes: +m.querySelector('#cu-dur').value,
        materials,
        hdlh_domains: hdlh,
        visibility: m.querySelector('#cu-vis').value,
      });
      m.remove();
      renderCurriculum(document.querySelector('main'));
    };
  }

  // ============================ ACH setup ============================
  async function renderAch(main) {
    main.setAttribute('data-kt-pretty', '1');
    main.innerHTML = '<div style="padding:24px;">Loading…</div>';
    const r = await Api.get('/parent/billing/ach-status').catch(() => ({}));
    main.innerHTML = `<div style="padding:24px;max-width:600px;margin:0 auto;">
      <div class="kt-page-hero">
        <h2>🏦 Bank account auto-pay</h2>
        <p>Skip credit card fees — connect your Canadian bank account for monthly tuition. Same security as your bank.</p>
      </div>
      <div class="kt-card">
        ${r.has_ach ? `
          <div style="display:flex;align-items:center;gap:14px;padding:12px;background:#F0FDF4;border-radius:10px;margin-bottom:14px;">
            <div style="font-size:32px;">✅</div>
            <div><div style="font-weight:700;color:#0F172A;">Connected</div>
              <div style="font-size:13px;color:#475569;">${esc(r.bank_name || 'Bank')} ending in ${esc(r.last4 || '')}</div></div>
          </div>
          <button id="ach-replace" class="kt-btn kt-btn-primary">Replace bank account</button>
        ` : `
          <p style="color:#475569;margin:0 0 16px;">Pre-Authorized Debit (PAD) authorizes us to pull tuition directly. Stripe verifies your bank with micro-deposits or instant verification.</p>
          <button id="ach-add" class="kt-btn kt-btn-primary" style="width:100%;padding:14px;">+ Connect bank account</button>
        `}
      </div>
    </div>`;
    const btn = document.getElementById('ach-add') || document.getElementById('ach-replace');
    if (btn) btn.onclick = () => startAchFlow();
  }
  async function startAchFlow() {
    if (!window.Stripe) {
      const s = document.createElement('script'); s.src = 'https://js.stripe.com/v3/';
      document.head.appendChild(s);
      await new Promise(r => s.onload = r);
    }
    const intent = await Api.post('/parent/billing/ach-setup-intent', {});
    if (!intent.publishable_key) { (window.KT && window.KT.toast) ? KT.toast('Stripe not configured.', /save|sent|added|created|approved|deleted|removed|done|charged/i.test('Stripe not configured.') ? 'success' : 'info') : alert('Stripe not configured.'); return; }
    const stripe = window.Stripe(intent.publishable_key);
    const { setupIntent, error } = await stripe.collectBankAccountForSetup({
      clientSecret: intent.client_secret,
      params: {
        payment_method_type: 'acss_debit',
        payment_method_data: {
          billing_details: {
            name: (JSON.parse(sessionStorage.getItem('kt_user') || '{}').first_name || 'Parent') + ' ' + (JSON.parse(sessionStorage.getItem('kt_user') || '{}').last_name || ''),
            email: JSON.parse(sessionStorage.getItem('kt_user') || '{}').email,
          },
        },
      },
    });
    if (error) { (window.KT && window.KT.toast) ? KT.toast(error.message, /save|sent|added|created|approved|deleted|removed|done|charged/i.test(error.message) ? 'success' : 'info') : alert(error.message); return; }
    await Api.post('/parent/billing/ach-save', { payment_method: setupIntent.payment_method });
    renderAch(document.querySelector('main'));
  }

  // ============================ Referrals ============================
  async function renderReferrals(main) {
    main.setAttribute('data-kt-pretty', '1');
    main.innerHTML = '<div style="padding:24px;">Loading…</div>';
    const r = await Api.get('/family/referrals').catch(() => ({ data: [] }));
    main.innerHTML = `<div style="padding:24px;max-width:1800px;margin:0 auto;">
      <div class="kt-page-hero">
        <h2>🎁 Refer a friend</h2>
        <p>When a family you refer enrolls, you get a tuition credit.</p>
        <div class="kt-hero-actions"><button class="kt-btn kt-btn-ghost" id="rf-new">+ Refer a family</button></div>
      </div>
      <div class="kt-card">
        <table>
          <thead><tr><th>Referred</th><th>Status</th><th>Credit</th><th>When</th></tr></thead>
          <tbody>${(r.data || []).map(rf => `<tr>
            <td>${esc(rf.referred_email || '')}</td>
            <td><span class="kt-pill ${rf.status === 'enrolled' ? 'kt-pill-success' : 'kt-pill-warning'}">${esc(rf.status)}</span></td>
            <td>${rf.credit_amount ? fmtMoney(rf.credit_amount) : '—'}</td>
            <td>${fmtDate(rf.created_at)}</td>
          </tr>`).join('') || '<tr><td colspan="4" style="text-align:center;padding:40px;color:#94A3B8;">No referrals yet.</td></tr>'}</tbody>
        </table>
      </div>
    </div>`;
    document.getElementById('rf-new').onclick = async () => {
      const email = prompt('Email of the family you want to refer?');
      if (!email) return;
      await Api.post('/family/referrals', { referred_email: email });
      renderReferrals(main);
    };
  }

  window.KT = KT;
  window.KT.V22p57 = {
    renderPickupAuth, renderCheckin, renderTrends, renderHdlhGaps,
    renderDrip, renderCurriculum, renderAch, renderReferrals,
  };
})(window);
