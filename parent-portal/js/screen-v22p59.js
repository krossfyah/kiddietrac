/* v22p59 — 9 features, all hash-based screens. */
(function (window) {
  'use strict';
  const KT = window.KT || {};
  const Api = KT.Api;
  if (!Api) { console.warn('v22p59: KT.Api unavailable'); return; }

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
        <label style="display:block;margin:8px 0;"><input id="opt-em" type="checkbox" ${o.share_email ? 'checked' : ''}> Share my email</label>
        <label style="display:block;margin:8px 0;"><input id="opt-ph" type="checkbox" ${o.share_phone ? 'checked' : ''}> Share my phone</label>
        <label style="display:block;margin:8px 0;"><input id="opt-ad" type="checkbox" ${o.share_address ? 'checked' : ''}> Share my address (city only)</label>
        <label style="display:block;margin:8px 0;"><input id="opt-kn" type="checkbox" ${o.share_children_names !== 0 ? 'checked' : ''}> Show my children's first names</label>
        <button id="opt-save" class="kt-btn kt-btn-primary" style="margin-top:10px;">Save preferences</button>
      </div>
      <div class="kt-card">
        <div class="kt-card-header"><h3 class="kt-card-title">Other families at your centre</h3></div>
        ${(dir.data || []).length ? `<div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(280px,1fr));gap:14px;">
          ${(dir.data || []).map(f => `<div style="background:#FAFCFE;padding:16px;border-radius:10px;border:1px solid #F1F5F9;">
            <strong style="color:#0F172A;font-size:15px;">${esc(f.family_name)}</strong>
            ${f.child_names && f.child_names.length ? `<div style="color:#475569;font-size:13px;margin-top:4px;">Children: ${f.child_names.map(esc).join(', ')}</div>` : ''}
            ${f.primary_email ? `<div style="color:#475569;font-size:13px;margin-top:6px;">📧 <a href="mailto:${esc(f.primary_email)}" style="color:#1F6080;">${esc(f.primary_email)}</a></div>` : ''}
            ${f.primary_phone ? `<div style="color:#475569;font-size:13px;margin-top:4px;">📞 ${esc(f.primary_phone)}</div>` : ''}
            ${f.city ? `<div style="color:#475569;font-size:13px;margin-top:4px;">📍 ${esc(f.city)}</div>` : ''}
          </div>`).join('')}
        </div>` : '<div style="color:#94A3B8;padding:20px;text-align:center;">No other families have opted in yet.</div>'}
      </div>
    </div>`;
    document.getElementById('opt-save').onclick = async () => {
      await Api.post('/directory/me', {
        share_email: document.getElementById('opt-em').checked,
        share_phone: document.getElementById('opt-ph').checked,
        share_address: document.getElementById('opt-ad').checked,
        share_children_names: document.getElementById('opt-kn').checked,
      });
      renderDirectory(main);
    };
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
            <td><strong>${fmtDate(s.slot_at)}</strong><div style="color:#94A3B8;font-size:12px;">${fmtTime(s.slot_at)} · ${s.duration_minutes} min</div></td>
            <td>${esc(s.teacher_name || 'Any teacher')}</td>
            <td><span class="kt-pill ${s.status === 'open' ? 'kt-pill-success' : 'kt-pill-warning'}">${esc(s.status)}</span></td>
            ${isStaff ? `<td>${esc(s.booked_by_name || '')} ${s.booked_child_name ? '(' + esc(s.booked_child_name) + ')' : ''}</td>` : ''}
            <td>${s.status === 'open' && !isStaff ? `<button class="kt-btn kt-btn-primary" data-book="${s.id}">Book</button>` : s.status === 'booked' ? `<button class="kt-btn kt-btn-danger" data-cancel="${s.id}" style="font-size:12px;">Cancel</button>` : ''}</td>
          </tr>`).join('') || '<tr><td colspan="' + (isStaff ? 5 : 4) + '" style="text-align:center;padding:40px;color:#94A3B8;">No slots scheduled.</td></tr>'}</tbody>
        </table>
      </div>
    </div>`;
    if (isStaff) document.getElementById('cf-new').onclick = () => openCreateSlotsModal();
    main.querySelectorAll('button[data-book]').forEach(b => b.onclick = async () => {
      const kids = (await Api.get('/parent/children')).data || [];
      const cid = kids.length === 1 ? kids[0].id : +prompt('Child ID for this booking? ' + kids.map(c => c.id + ': ' + c.first_name).join(', '));
      if (!cid) return;
      await Api.post(`/conferences/slots/${b.dataset.book}/book`, { child_id: cid });
      renderConferences(main);
    });
    main.querySelectorAll('button[data-cancel]').forEach(b => b.onclick = async () => {
      if (!confirm('Cancel this conference?')) return;
      await Api.post(`/conferences/slots/${b.dataset.cancel}/cancel`, {});
      renderConferences(main);
    });
  }
  async function openCreateSlotsModal() {
    const centres = (await Api.get('/admin/centres').catch(() => ({ data: [] }))).data || [];
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
      if (!r.latest) { det.innerHTML = '<div style="color:#94A3B8;padding:14px;">No location pings yet.</div>'; return; }
      det.innerHTML = `<div class="kt-kpi-grid">
        <div class="kt-kpi kt-kpi-info"><div class="kt-kpi-label">Latitude</div><div class="kt-kpi-value">${parseFloat(r.latest.lat).toFixed(5)}</div></div>
        <div class="kt-kpi kt-kpi-info"><div class="kt-kpi-label">Longitude</div><div class="kt-kpi-value">${parseFloat(r.latest.lon).toFixed(5)}</div></div>
        <div class="kt-kpi kt-kpi-success"><div class="kt-kpi-label">Last ping</div><div class="kt-kpi-value" style="font-size:18px;">${fmtTime(r.latest.recorded_at)}</div></div>
        <div class="kt-kpi"><div class="kt-kpi-label">Trail length</div><div class="kt-kpi-value">${r.trail.length}</div></div>
      </div>
      <div style="margin-top:18px;">
        <iframe width="100%" height="500" frameborder="0" style="border-radius:12px;border:1px solid #E2E8F0;"
          src="https://www.openstreetmap.org/export/embed.html?bbox=${parseFloat(r.latest.lon)-0.01},${parseFloat(r.latest.lat)-0.01},${parseFloat(r.latest.lon)+0.01},${parseFloat(r.latest.lat)+0.01}&layer=mapnik&marker=${r.latest.lat},${r.latest.lon}"></iframe>
        <div style="margin-top:8px;font-size:12px;color:#94A3B8;">
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
    const children = childrenRes.data || childrenRes || [];
    if (!children.length) { main.innerHTML = '<div class="kt-card" style="margin:24px;text-align:center;color:#94A3B8;padding:40px;">No children.</div>'; return; }
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
        <div style="display:grid;grid-template-columns:repeat(7,1fr);gap:8px;margin-top:14px;">
          ${['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'].map(d => {
            const label = d.charAt(0).toUpperCase() + d.slice(1, 3);
            return `<label style="text-align:center;cursor:pointer;background:${p[d] ? '#DCFCE7' : '#F1F5F9'};padding:14px 8px;border-radius:10px;border:2px solid ${p[d] ? '#10B981' : '#E2E8F0'};">
              <div style="font-weight:700;font-size:12px;color:#64748B;text-transform:uppercase;">${label}</div>
              <input type="checkbox" data-day="${d}" ${p[d] ? 'checked' : ''} style="margin-top:8px;width:22px;height:22px;cursor:pointer;">
            </label>`;
          }).join('')}
        </div>
        <label style="display:block;font-size:13px;font-weight:600;margin:18px 0 4px;">Effective from</label>
        <input id="ap-from" type="date" value="${new Date().toISOString().slice(0,10)}" style="width:100%;padding:9px;border:1px solid #E2E8F0;border-radius:8px;">
        <button id="ap-save" class="kt-btn kt-btn-primary" style="margin-top:14px;">Save pattern</button>
      `;
      document.getElementById('ap-save').onclick = async () => {
        const days = {};
        ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'].forEach(d => {
          days[d] = document.querySelector(`input[data-day="${d}"]`).checked;
        });
        await Api.post(`/attendance/pattern/${cid}`, { ...days, effective_from: document.getElementById('ap-from').value });
        alert('Saved.');
      };
    };
    document.getElementById('ap-child').onchange = (e) => load(+e.target.value);
    load(children[0].id);
  }

  // ============================ Report cards (staff) ============================
  async function renderReportCards(main) {
    main.setAttribute('data-kt-pretty', '1');
    main.innerHTML = `<div style="padding:24px;max-width:1800px;margin:0 auto;">
      <div class="kt-page-hero">
        <h2>📑 Report cards</h2>
        <p>Generate AI-drafted narratives per HDLH domain. Review + edit + send to family.</p>
      </div>
      <div class="kt-card">
        <label style="font-size:13px;font-weight:600;">Child ID</label>
        <input id="rc-cid" type="number" style="width:100%;padding:11px;border:1px solid #E2E8F0;border-radius:8px;margin-top:6px;">
        <label style="display:block;font-size:13px;font-weight:600;margin-top:10px;">Term</label>
        <input id="rc-term" placeholder="e.g. 2026 Year-End" style="width:100%;padding:11px;border:1px solid #E2E8F0;border-radius:8px;margin-top:6px;">
        <button id="rc-gen" class="kt-btn kt-btn-primary" style="margin-top:14px;">Generate via AI</button>
        <div id="rc-out" style="margin-top:18px;"></div>
      </div>
    </div>`;
    document.getElementById('rc-gen').onclick = async () => {
      const cid = +document.getElementById('rc-cid').value;
      const term = document.getElementById('rc-term').value;
      if (!cid || !term) { alert('Child ID + term required'); return; }
      const btn = document.getElementById('rc-gen');
      btn.disabled = true; btn.textContent = 'Generating… (15-30 sec)';
      try {
        const r = await Api.post('/report-cards/generate', { child_id: cid, term });
        document.getElementById('rc-out').innerHTML = ['belonging', 'wellbeing', 'engagement', 'expression'].map(d => `
          <h4 style="margin-top:14px;color:#1F6080;text-transform:capitalize;">${d.replace('wellbeing', 'Well-being')}</h4>
          <textarea id="rc-${d}" rows="5" style="width:100%;padding:9px;border:1px solid #E2E8F0;border-radius:8px;font-family:inherit;">${esc((r.narratives || {})[d] || '')}</textarea>
        `).join('') + `
          <h4 style="margin-top:14px;color:#F59E0B;">Next steps</h4>
          <textarea id="rc-next" rows="5" style="width:100%;padding:9px;border:1px solid #E2E8F0;border-radius:8px;font-family:inherit;">${esc(r.next_steps || '')}</textarea>
          <button id="rc-save" class="kt-btn kt-btn-primary" style="margin-top:14px;">Save edits</button>
          <button id="rc-send" class="kt-btn kt-btn-success" style="margin-top:14px;margin-left:8px;">Mark as sent + notify family</button>
        `;
        document.getElementById('rc-save').onclick = async () => {
          await Api.patch(`/report-cards/${r.id}`, {
            narrative_belonging: document.getElementById('rc-belonging').value,
            narrative_wellbeing: document.getElementById('rc-wellbeing').value,
            narrative_engagement: document.getElementById('rc-engagement').value,
            narrative_expression: document.getElementById('rc-expression').value,
            next_steps: document.getElementById('rc-next').value,
            status: 'reviewed',
          });
          alert('Saved.');
        };
        document.getElementById('rc-send').onclick = async () => {
          await Api.post(`/report-cards/${r.id}/send`, {});
          alert('Sent. Family was notified.');
        };
      } catch (e) { alert('AI failed: ' + (e.message || e)); }
      finally { btn.disabled = false; btn.textContent = 'Generate via AI'; }
    };
  }

  // ============================ Activity zones ============================
  async function renderZones(main) {
    main.setAttribute('data-kt-pretty', '1');
    main.innerHTML = '<div style="padding:24px;">Loading…</div>';
    const centres = (await Api.get('/admin/centres').catch(() => ({ data: [] }))).data || [];
    if (!centres.length) { main.innerHTML = '<div class="kt-card" style="margin:24px;text-align:center;color:#94A3B8;padding:40px;">No centres.</div>'; return; }
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
          </div>`).join('') || '<div style="color:#94A3B8;padding:40px;text-align:center;grid-column:1/-1;">No zones set up.</div>'}
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
        </table>` : '<div style="color:#94A3B8;padding:20px;text-align:center;">No visits logged today.</div>'}
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
            <td>${fmtDate(t.created_at)}</td></tr>`).join('') || '<tr><td colspan="6" style="text-align:center;padding:40px;color:#94A3B8;">No tickets yet.</td></tr>'}</tbody>
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
        <div style="font-size:12px;color:#94A3B8;font-weight:600;">${esc(msg.author_name)} · ${fmtDate(msg.created_at)}</div>
        <div style="margin-top:4px;font-size:14px;">${esc(msg.body)}</div></div>`).join('') || '<div style="color:#94A3B8;font-size:13px;">No replies yet.</div>'}
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
