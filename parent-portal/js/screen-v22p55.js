/* v22p55 — design overhaul + rewritten screens.
   Replaces:
     - V22p51 renderAiChurn (prettier KPI tiles)
     - V22p53 renderAllergyAlerts (proper kt-alert card style)
     - V22p53 renderForecast (rich SVG chart)
     - V22p53 renderRetention (animated gradient bars)
   Plus widens notifications + adds class hooks across the rest.
*/
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

  // ============================ Allergy alerts (rewrite) ============================
  async function renderAllergyAlerts(main) {
    main.setAttribute('data-kt-pretty', '1');
    main.innerHTML = '<div style="padding:24px;">Loading alerts…</div>';
    const r = await Api.get('/operations/allergy-alerts').catch(() => ({ data: [] }));
    const list = r.data || [];
    const anaphylactic = list.filter(c => (c.tags || []).some(t => t.severity === 'anaphylactic')).length;
    const dietary = list.filter(c => (c.tags || []).some(t => t.kind === 'dietary')).length;
    main.innerHTML = `<div style="padding:24px;max-width:1800px;margin:0 auto;">
      <div class="kt-page-hero">
        <h2>⚠ Allergy & dietary alerts</h2>
        <p>${list.length} child(ren) with active alerts. ${anaphylactic} anaphylactic. ${dietary} dietary restriction(s). Display on the Today screen and post in the kitchen.</p>
      </div>
      <div class="kt-kpi-grid">
        <div class="kt-kpi kt-kpi-danger"><div class="kt-kpi-label">Anaphylactic</div><div class="kt-kpi-value">${anaphylactic}</div><div class="kt-kpi-trend">🚨 EpiPen required</div></div>
        <div class="kt-kpi kt-kpi-warning"><div class="kt-kpi-label">Total allergies</div><div class="kt-kpi-value">${list.filter(c => (c.tags || []).some(t => t.kind === 'allergy')).length}</div></div>
        <div class="kt-kpi kt-kpi-info"><div class="kt-kpi-label">Dietary</div><div class="kt-kpi-value">${dietary}</div></div>
        <div class="kt-kpi"><div class="kt-kpi-label">Children affected</div><div class="kt-kpi-value">${list.length}</div></div>
      </div>
      <div>${list.map(c => `<div class="kt-alert">
        <div class="kt-alert-title">${esc(c.first_name)} ${esc(c.last_name)}</div>
        <div class="kt-alert-body">${(c.tags || []).map(t => {
          const sevDot = t.severity === 'anaphylactic' ? '🚨 ' : t.severity === 'severe' ? '⚠ ' : '';
          return `<span class="kt-tag severity-${esc(t.severity || 'note')} kind-${esc(t.kind || '')}">${sevDot}<strong>${esc((t.kind || '').toUpperCase())}</strong>&nbsp;${esc(t.label || '')}</span>`;
        }).join('')}</div></div>`).join('') || '<div class="kt-card" style="text-align:center;color:#9CA3AF;padding:40px;">No active alerts.</div>'}
      </div>
    </div>`;
  }

  // ============================ Enrollment forecast (SVG rewrite) ============================
  async function renderForecast(main) {
    main.setAttribute('data-kt-pretty', '1');
    main.innerHTML = '<div style="padding:24px;">Computing forecast…</div>';
    const r = await Api.get('/ai/enrollment-forecast');
    const proj = r.projection || [];
    const all = [r.current_enrolment, ...proj.map(p => p.projected_enrolment)];
    const maxY = Math.max(...all, 10);
    const points = [
      { label: 'Now', y: r.current_enrolment, isNow: true },
      ...proj.map(p => ({ label: p.month.slice(5), y: p.projected_enrolment, adds: p.monthly_adds, churn: p.monthly_churn, monthFull: p.month })),
    ];
    const W = 1100, H = 380;
    const padL = 60, padR = 30, padT = 30, padB = 50;
    const innerW = W - padL - padR;
    const innerH = H - padT - padB;
    const stepX = innerW / (points.length - 1);
    const yFor = (v) => padT + innerH - (v / maxY) * innerH;
    // Build paths
    const linePath = points.map((p, i) => `${i === 0 ? 'M' : 'L'} ${padL + i * stepX} ${yFor(p.y)}`).join(' ');
    const areaPath = `M ${padL} ${padT + innerH} L ${points.map((p, i) => `${padL + i * stepX} ${yFor(p.y)}`).join(' L ')} L ${padL + (points.length - 1) * stepX} ${padT + innerH} Z`;
    // Grid lines
    const gridLines = [0, 0.25, 0.5, 0.75, 1].map(g => {
      const y = padT + innerH - g * innerH;
      const v = Math.round(g * maxY);
      return `<line x1="${padL}" y1="${y}" x2="${W - padR}" y2="${y}" stroke="#E5E7EB" stroke-dasharray="3 4" stroke-width="1"></line>
              <text x="${padL - 8}" y="${y + 4}" text-anchor="end" fill="#94A3B8" font-size="11" font-weight="600">${v}</text>`;
    }).join('');

    main.innerHTML = `<div style="padding:24px;max-width:1800px;margin:0 auto;">
      <div class="kt-page-hero">
        <h2>📈 Enrolment forecast</h2>
        <p>6-month projection. Combines current enrolment, ${r.tour_pipeline_60d} tour pipeline (last 60 days), assumed 30% conversion + 2.5% monthly churn.</p>
      </div>
      <div class="kt-kpi-grid">
        <div class="kt-kpi kt-kpi-info"><div class="kt-kpi-label">Current enrolment</div><div class="kt-kpi-value">${r.current_enrolment}</div></div>
        <div class="kt-kpi kt-kpi-purple"><div class="kt-kpi-label">Tour pipeline · 60d</div><div class="kt-kpi-value">${r.tour_pipeline_60d}</div></div>
        <div class="kt-kpi kt-kpi-success"><div class="kt-kpi-label">Monthly adds</div><div class="kt-kpi-value">+${r.monthly_adds_assumption}</div></div>
        <div class="kt-kpi kt-kpi-warning"><div class="kt-kpi-label">Monthly churn %</div><div class="kt-kpi-value">${(r.monthly_churn_pct * 100).toFixed(1)}%</div></div>
      </div>
      <div class="kt-forecast">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:18px;">
          <h3 style="margin:0;color:#0F172A;font-size:17px;font-weight:700;">6-month enrolment trajectory</h3>
          <div style="display:flex;gap:18px;font-size:12px;color:#475569;">
            <span style="display:inline-flex;align-items:center;gap:6px;"><span style="width:12px;height:12px;border-radius:3px;background:linear-gradient(135deg,#1F6080,#3a86ad);"></span>Projected</span>
            <span style="display:inline-flex;align-items:center;gap:6px;"><span style="width:12px;height:12px;border-radius:3px;background:#10B981;"></span>Current</span>
          </div>
        </div>
        <svg viewBox="0 0 ${W} ${H}" style="width:100%;height:auto;">
          <defs>
            <linearGradient id="kt-fg" x1="0" x2="0" y1="0" y2="1">
              <stop offset="0%" stop-color="#3a86ad" stop-opacity="0.40"></stop>
              <stop offset="100%" stop-color="#3a86ad" stop-opacity="0.05"></stop>
            </linearGradient>
            <linearGradient id="kt-bg" x1="0" x2="0" y1="0" y2="1">
              <stop offset="0%" stop-color="#1F6080"></stop>
              <stop offset="100%" stop-color="#3a86ad"></stop>
            </linearGradient>
            <filter id="kt-shadow"><feDropShadow dx="0" dy="2" stdDeviation="3" flood-color="#1F6080" flood-opacity="0.25"></feDropShadow></filter>
          </defs>
          ${gridLines}
          <path d="${areaPath}" fill="url(#kt-fg)"></path>
          <path d="${linePath}" stroke="url(#kt-bg)" stroke-width="3.5" fill="none" stroke-linecap="round" stroke-linejoin="round" filter="url(#kt-shadow)"></path>
          ${points.map((p, i) => {
            const x = padL + i * stepX;
            const y = yFor(p.y);
            const isNow = p.isNow;
            return `<g>
              <circle cx="${x}" cy="${y}" r="${isNow ? 8 : 6}" fill="${isNow ? '#10B981' : '#fff'}" stroke="${isNow ? '#fff' : '#1F6080'}" stroke-width="3"></circle>
              <text x="${x}" y="${y - 14}" text-anchor="middle" font-size="13" font-weight="700" fill="${isNow ? '#10B981' : '#1F6080'}">${p.y}</text>
              <text x="${x}" y="${H - 16}" text-anchor="middle" font-size="12" fill="#475569" font-weight="600">${p.label}</text>
            </g>`;
          }).join('')}
        </svg>
      </div>
      <div class="kt-card" style="margin-top:20px;">
        <h3 style="margin:0 0 14px;color:#0F172A;font-size:16px;font-weight:700;">Month-by-month breakdown</h3>
        <table>
          <thead><tr><th>Month</th><th style="text-align:right">Projected</th><th style="text-align:right">+ Adds</th><th style="text-align:right">− Churn</th></tr></thead>
          <tbody>${proj.map(p => `<tr>
            <td><strong>${p.month}</strong></td>
            <td style="text-align:right;font-weight:700;color:#0F172A;">${p.projected_enrolment}</td>
            <td style="text-align:right;color:#15803D;">+${p.monthly_adds}</td>
            <td style="text-align:right;color:#B91C1C;">−${p.monthly_churn}</td>
          </tr>`).join('')}</tbody>
        </table>
      </div>
    </div>`;
  }

  // ============================ AI churn risk (rewrite to match) ============================
  async function renderAiChurn(main) {
    main.setAttribute('data-kt-pretty', '1');
    main.innerHTML = '<div style="padding:24px;">Computing…</div>';
    const r = await Api.get('/ai/churn-risk').catch(() => ({ data: [] }));
    const rows = r.data || [];
    const hi = rows.filter(x => x.bucket === 'high').length;
    const md = rows.filter(x => x.bucket === 'medium').length;
    const lo = rows.length - hi - md;
    main.innerHTML = `<div style="padding:24px;max-width:1800px;margin:0 auto;">
      <div class="kt-page-hero">
        <h2>📉 Churn risk</h2>
        <p>Per-family risk score using observation activity, sign-in attendance, and payment status. Re-computed each load.</p>
      </div>
      <div class="kt-kpi-grid">
        <div class="kt-kpi kt-kpi-danger"><div class="kt-kpi-label">High risk</div><div class="kt-kpi-value">${hi}</div><div class="kt-kpi-trend" style="color:#B91C1C;">Outreach this week</div></div>
        <div class="kt-kpi kt-kpi-warning"><div class="kt-kpi-label">Medium</div><div class="kt-kpi-value">${md}</div></div>
        <div class="kt-kpi kt-kpi-success"><div class="kt-kpi-label">Low</div><div class="kt-kpi-value">${lo}</div></div>
        <div class="kt-kpi"><div class="kt-kpi-label">Total families</div><div class="kt-kpi-value">${rows.length}</div></div>
      </div>
      <div class="kt-card">
        <div class="kt-card-header"><h3 class="kt-card-title">All families by risk</h3></div>
        <table>
          <thead><tr><th>Family</th><th>Score</th><th>Bucket</th><th>Signals</th></tr></thead>
          <tbody>${rows.slice(0, 80).map(r => {
            const pill = r.bucket === 'high' ? 'kt-pill-danger' : r.bucket === 'medium' ? 'kt-pill-warning' : 'kt-pill-success';
            return `<tr>
              <td><strong>${esc(r.family_name)}</strong></td>
              <td><div style="display:flex;align-items:center;gap:10px;">
                <div style="background:#E5E7EB;height:8px;width:100px;border-radius:4px;overflow:hidden;"><div style="background:${r.bucket === 'high' ? '#EF4444' : r.bucket === 'medium' ? '#F59E0B' : '#10B981'};height:100%;width:${Math.max(2, r.risk_score)}%;border-radius:4px;"></div></div>
                <span style="font-weight:700;color:#0F172A;">${r.risk_score}</span></div></td>
              <td><span class="kt-pill ${pill}">${esc(r.bucket)}</span></td>
              <td style="color:#475569;font-size:13px;">${(r.signals || []).join(' · ') || '—'}</td>
            </tr>`;
          }).join('')}</tbody>
        </table>
      </div>
    </div>`;
  }

  // ============================ Cohort retention (rewrite) ============================
  async function renderRetention(main) {
    main.setAttribute('data-kt-pretty', '1');
    main.innerHTML = '<div style="padding:24px;">Computing…</div>';
    const r = await Api.get('/compliance/retention');
    const rows = r.data || [];
    const avg = rows.filter(c => c.retention_pct != null).reduce((a, c) => a + c.retention_pct, 0) / Math.max(1, rows.filter(c => c.retention_pct != null).length);
    main.innerHTML = `<div style="padding:24px;max-width:1800px;margin:0 auto;">
      <div class="kt-page-hero">
        <h2>📊 Cohort retention</h2>
        <p>% of children still enrolled by enrolment-month cohort. 12-month rolling window. Average retention: ${isNaN(avg) ? '—' : avg.toFixed(1) + '%'}.</p>
      </div>
      <div class="kt-card">
        <div class="kt-card-header"><h3 class="kt-card-title">Cohort breakdown</h3></div>
        <table>
          <thead><tr><th>Cohort</th><th style="text-align:right">Enrolled</th><th style="text-align:right">Still</th><th>Retention</th></tr></thead>
          <tbody>${rows.map(c => {
            const pct = c.retention_pct == null ? null : c.retention_pct;
            const colour = pct == null ? '#E5E7EB' : pct >= 80 ? '#10B981' : pct >= 50 ? '#F59E0B' : '#EF4444';
            const grad = pct == null ? '#E5E7EB' : pct >= 80 ? 'linear-gradient(90deg,#10B981,#34D399)' : pct >= 50 ? 'linear-gradient(90deg,#F59E0B,#FBBF24)' : 'linear-gradient(90deg,#EF4444,#F87171)';
            return `<tr>
              <td><strong>${c.cohort}</strong></td>
              <td style="text-align:right">${c.enrolled}</td>
              <td style="text-align:right">${c.still_enrolled}</td>
              <td><div style="display:flex;align-items:center;gap:12px;">
                <div style="flex:1;background:#F1F5F9;height:14px;border-radius:7px;overflow:hidden;max-width:340px;"><div style="background:${grad};height:100%;width:${pct == null ? 0 : Math.max(2, pct)}%;border-radius:7px;transition:width 0.6s;"></div></div>
                <span style="font-weight:700;color:${colour};min-width:48px;">${pct == null ? '—' : pct + '%'}</span>
              </div></td>
            </tr>`;
          }).join('')}</tbody>
        </table>
      </div>
    </div>`;
  }

  // ============================ Photo feed (NEW v22p55) ============================
  async function renderPhotoFeed(main) {
    main.setAttribute('data-kt-pretty', '1');
    main.innerHTML = '<div style="padding:24px;">Loading photos…</div>';
    const r = await Api.get('/photos/feed').catch(() => ({ data: [] }));
    const photos = r.data || [];
    main.innerHTML = `<div style="padding:24px;max-width:1800px;margin:0 auto;">
      <div class="kt-page-hero">
        <h2>📸 Photo feed</h2>
        <p>${photos.length} photo(s) shared. Upload a moment — visible to the family within seconds.</p>
        <div class="kt-hero-actions"><button class="kt-btn kt-btn-ghost" id="pf-upload">+ Upload photo</button></div>
      </div>
      <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(280px,1fr));gap:18px;">
        ${photos.map(p => `<div class="kt-card" style="padding:0;overflow:hidden;">
          <img src="${esc(p.url)}" loading="lazy" style="width:100%;height:220px;object-fit:cover;display:block;background:#F1F5F9;">
          <div style="padding:14px 18px;">
            <div style="font-size:12.5px;color:#475569;">${fmtDate(p.taken_at)} · ${esc(p.uploader_name || '')}</div>
            <div style="font-size:13.5px;color:#0F172A;margin-top:6px;">${esc(p.caption || '')}</div>
            ${(p.child_names || []).length ? `<div style="margin-top:8px;display:flex;flex-wrap:wrap;gap:4px;">${p.child_names.map(n => `<span class="kt-pill kt-pill-info">${esc(n)}</span>`).join('')}</div>` : ''}
          </div></div>`).join('') || '<div class="kt-card" style="text-align:center;color:#9CA3AF;padding:60px;grid-column:1/-1;">No photos shared yet.</div>'}
      </div>
    </div>`;
    document.getElementById('pf-upload').onclick = () => openPhotoUpload();
  }
  function openPhotoUpload() {
    const m = document.createElement('div');
    m.style.cssText = 'position:fixed;inset:0;background:rgba(15,23,42,0.6);z-index:99999;display:flex;align-items:center;justify-content:center;';
    m.innerHTML = `<div style="background:#fff;padding:28px;border-radius:14px;max-width:480px;width:92%;">
      <h3 style="margin:0 0 16px;color:#0F172A;">Share a photo</h3>
      <label style="display:block;font-size:13px;font-weight:600;margin-bottom:8px;">Photo file</label>
      <input id="pf-file" type="file" accept="image/*" style="width:100%;padding:9px;border:2px dashed #CBD5E1;border-radius:8px;background:#F8FAFC;">
      <label style="display:block;font-size:13px;font-weight:600;margin-top:14px;">Caption</label>
      <textarea id="pf-caption" rows="3" placeholder="What were they up to?" style="width:100%;padding:10px;border:1px solid #E2E8F0;border-radius:8px;font-family:inherit;"></textarea>
      <div style="margin-top:20px;display:flex;justify-content:flex-end;gap:8px;">
        <button id="pf-cancel" style="background:#F1F5F9;border:0;padding:10px 18px;border-radius:8px;cursor:pointer;font-weight:600;">Cancel</button>
        <button id="pf-submit" style="background:linear-gradient(180deg,#1F6080,#154057);color:#fff;border:0;padding:10px 18px;border-radius:8px;cursor:pointer;font-weight:600;">Share</button>
      </div></div>`;
    document.body.appendChild(m);
    m.querySelector('#pf-cancel').onclick = () => m.remove();
    m.querySelector('#pf-submit').onclick = async () => {
      const f = m.querySelector('#pf-file').files[0];
      if (!f) { alert('Pick a file'); return; }
      const fd = new FormData();
      fd.append('photo', f);
      fd.append('caption', m.querySelector('#pf-caption').value);
      try {
        const apiBase = (KT.API_BASE) || 'https://api.kiddietrac.com/api/v1';
        const r = await fetch(apiBase + '/photos', {
          method: 'POST',
          headers: { Authorization: 'Bearer ' + sessionStorage.getItem('kt_token') },
          body: fd,
        });
        if (!r.ok) {
          let detail = 'Upload failed (' + r.status + ')';
          try {
            const j = await r.json();
            if (j.message) detail = j.message;
            if (j.errors) detail = Object.values(j.errors).flat().join('. ');
            if (r.status === 413) detail = 'File too large. Max photo size is 8MB.';
          } catch (e) {}
          throw new Error(detail);
        }
        m.remove();
        if (window.KT && window.KT.toast) window.KT.toast('Photo shared', 'success');
        renderPhotoFeed(document.querySelector('main'));
      } catch (e) { if (window.KT && window.KT.toast) window.KT.toast(e.message || 'Upload failed', 'error', 7000); else alert(e.message); }
    };
  }

  // ============================ Parent feedback ratings (NEW) ============================
  async function renderFeedback(main) {
    main.setAttribute('data-kt-pretty', '1');
    main.innerHTML = '<div style="padding:24px;">Loading…</div>';
    const r = await Api.get('/feedback').catch(() => ({ data: [], summary: {} }));
    const ratings = r.data || [];
    const s = r.summary || { avg: 0, count: 0, breakdown: { 5: 0, 4: 0, 3: 0, 2: 0, 1: 0 } };
    main.innerHTML = `<div style="padding:24px;max-width:1800px;margin:0 auto;">
      <div class="kt-page-hero">
        <h2>⭐ Parent feedback</h2>
        <p>${s.count || 0} ratings · average ${(s.avg || 0).toFixed(2)} / 5</p>
      </div>
      <div class="kt-kpi-grid">
        ${[5,4,3,2,1].map(stars => {
          const n = s.breakdown[stars] || 0;
          const pct = s.count ? Math.round((n / s.count) * 100) : 0;
          return `<div class="kt-kpi"><div class="kt-kpi-label">${stars} star${stars > 1 ? 's' : ''}</div><div class="kt-kpi-value">${n}</div><div class="kt-kpi-trend" style="color:#475569;">${pct}%</div></div>`;
        }).join('')}
      </div>
      <div class="kt-card">
        <div class="kt-card-header"><h3 class="kt-card-title">Recent feedback</h3></div>
        <table>
          <thead><tr><th>When</th><th>Family</th><th>Rating</th><th>Comment</th></tr></thead>
          <tbody>${ratings.slice(0, 50).map(f => `<tr>
            <td>${fmtDate(f.created_at)}</td>
            <td>${esc(f.family_name || 'Anonymous')}</td>
            <td>${'⭐'.repeat(f.rating || 0)}</td>
            <td style="color:#475569;">${esc(f.comment || '')}</td></tr>`).join('') || '<tr><td colspan="4" style="text-align:center;padding:40px;color:#94A3B8;">No feedback yet.</td></tr>'}</tbody>
        </table>
      </div>
    </div>`;
  }

  // ============================ AI doc auto-link UI (NEW) ============================
  async function renderDocAutolink(main) {
    main.setAttribute('data-kt-pretty', '1');
    const r = await Api.get('/ai/doc-extractions').catch(() => ({ data: [] }));
    const exts = r.data || [];
    main.innerHTML = `<div style="padding:24px;max-width:1800px;margin:0 auto;">
      <div class="kt-page-hero">
        <h2>🪄 AI doc extractions</h2>
        <p>Documents you extracted via AI doc-extract. Click "Auto-link" to find the matching staff/child record and create the cert/check row.</p>
      </div>
      <div class="kt-card">
        <table>
          <thead><tr><th>When</th><th>Type</th><th>Status</th><th>Fields</th><th></th></tr></thead>
          <tbody>${exts.map(e => {
            const fields = e.extracted_fields ? (typeof e.extracted_fields === 'string' ? JSON.parse(e.extracted_fields) : e.extracted_fields) : {};
            const pill = e.status === 'linked' ? 'kt-pill-success' : e.status === 'extracted' ? 'kt-pill-info' : e.status === 'failed' ? 'kt-pill-danger' : 'kt-pill-warning';
            return `<tr>
              <td>${fmtDate(e.created_at)}</td>
              <td><span class="kt-pill kt-pill-purple">${esc(e.doc_type)}</span></td>
              <td><span class="kt-pill ${pill}">${esc(e.status)}</span></td>
              <td style="font-size:12px;font-family:ui-monospace,monospace;color:#475569;">${esc(Object.entries(fields).slice(0, 3).map(([k, v]) => k + '=' + v).join(', '))}</td>
              <td>${e.status === 'extracted' ? `<button class="kt-btn kt-btn-primary" data-link-id="${e.id}">Auto-link</button>` : ''}</td>
            </tr>`;
          }).join('') || '<tr><td colspan="5" style="text-align:center;padding:40px;color:#94A3B8;">No extractions yet. Use the AI Doc Extract screen.</td></tr>'}</tbody>
        </table>
      </div>
    </div>`;
    main.querySelectorAll('button[data-link-id]').forEach(b => b.onclick = async () => {
      b.disabled = true; b.textContent = '…';
      const r = await Api.post(`/ai/doc-extract/${b.dataset.linkId}/auto-link`, {});
      alert(`Action: ${r.action}. Matches: ${(r.matches || []).length}`);
      renderDocAutolink(main);
    });
  }

  // ============================ Apply pretty-class wrapper to existing screens ============================
  function applyPrettyClass() {
    const main = document.querySelector('main') || document.getElementById('main');
    if (!main) return;
    const hash = location.hash.replace('#', '').split('?')[0];
    const prettyHashes = ['notifications', 'compliance', 'audit-logs', 'admin-users', 'admin-families', 'admin-children',
      'payroll', 'background-checks', 'menu', 'field-trips', 'substitutes', 'inspection', 'cwelcc', 'time-off', 'sms',
      'ai-docs', 'agency-billing', 'autopay-card', 'language', 'forecast', 'anomalies', 'renewals', 'retention',
      'allergy-alerts', 'ai-churn', 'photos', 'feedback', 'doc-autolink'];
    if (prettyHashes.includes(hash)) {
      main.setAttribute('data-kt-pretty', '1');
      main.setAttribute('data-kt-screen', hash);
      // v22p64: do not force max-width — let each screen decide its own width
      // Narrow form-style screens get a marker so CSS can keep them compact
      const narrowScreens = ['language', 'autopay-card', 'agency-billing', 'ach-pay'];
      if (narrowScreens.includes(hash)) main.setAttribute('data-kt-narrow', '1');
      else main.removeAttribute('data-kt-narrow');
    }
  }
  window.addEventListener('hashchange', () => setTimeout(applyPrettyClass, 250));
  setTimeout(applyPrettyClass, 600);
  setInterval(applyPrettyClass, 2500);

  // Expose
  window.KT = KT;
  window.KT.V22p55 = {
    renderAllergyAlerts, renderForecast, renderAiChurn, renderRetention,
    renderPhotoFeed, renderFeedback, renderDocAutolink,
  };
})(window);
