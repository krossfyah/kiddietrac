/* ═══════════════════════════════════════════════════════════════════
   KIDDIETRAC v17 — Agency Admin Dashboard
   Rewritten to use the new component classes (.stat-tile-v17,
   .centre-card-v17, .page-header-v17, .activity-feed-v17, .tag-v17).
   Same data model as v16, structurally cleaner output.
   ═══════════════════════════════════════════════════════════════════ */
(function (window) {
  'use strict';
  const { Api, Fmt, Dom, Shell } = window.KT;
  const { emptyState } = Shell;

  function esc(s) { return s == null ? '' : String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;'); }

  async function renderAgencyDashboard(main) {
    Dom.clear(main);

    // Skeleton loading state (cleaner than "Loading...")
    const skeleton = document.createElement('div');
    skeleton.innerHTML = `
      <div style="opacity:.5;">
        <div style="height:18px; background:var(--kt-bg); width:140px; border-radius:6px; margin-bottom:10px;"></div>
        <div style="height:32px; background:var(--kt-bg); width:240px; border-radius:8px; margin-bottom:24px;"></div>
        <div style="display:grid; grid-template-columns:repeat(4,1fr); gap:14px; margin-bottom:28px;">
          ${'<div style="height:88px; background:var(--kt-bg); border-radius:14px;"></div>'.repeat(4)}
        </div>
        <div style="height:140px; background:var(--kt-bg); border-radius:14px;"></div>
      </div>`;
    main.appendChild(skeleton);

    let data;
    try {
      data = await Api.get('/agency/dashboard');
    } catch (e) {
      Dom.clear(main);
      main.appendChild(emptyState('⚠️', 'Could not load', e.message || 'Server error'));
      return;
    }

    Dom.clear(main);
    const wrap = document.createElement('div');
    main.appendChild(wrap);

    // ─── Header (breadcrumb · title · sub · actions) ──────────
    const totalEnrolled = data.totals?.enrolled ?? 0;
    const centreCount   = data.agency?.centre_count ?? (data.centres?.length || 0);

    wrap.insertAdjacentHTML('beforeend', `
      <div class="page-header-v17">
        <div>
          <div class="crumbs"><span>Home</span><span class="sep">›</span><span style="color:var(--kt-text-muted);">Agency overview</span></div>
          <h1>${esc(data.agency?.name || 'Agency overview')}</h1>
          <div class="sub">${centreCount} centre${centreCount === 1 ? '' : 's'} · ${totalEnrolled} enrolled · last updated just now</div>
        </div>
        <div class="actions">
          <button class="btn btn-secondary" id="kt-refresh-btn" title="Refresh">↻</button>
          <button class="btn btn-primary" id="kt-add-centre-btn">+ Add centre</button>
        </div>
      </div>
    `);

    wrap.querySelector('#kt-refresh-btn')?.addEventListener('click', () => renderAgencyDashboard(main));
    wrap.querySelector('#kt-add-centre-btn')?.addEventListener('click', () => { window.location.href = '/signup.html'; });

    // ─── KPI strip ────────────────────────────────────────────
    const t = data.totals || {};
    const capacitySum = (data.centres || []).reduce((acc, c) => acc + (c.license_capacity || 0), 0);
    const capacityPct = capacitySum > 0 ? Math.round((t.enrolled / capacitySum) * 100) : 0;
    const presentPct  = t.enrolled > 0 ? Math.round((t.present_now / t.enrolled) * 100) : 0;

    wrap.insertAdjacentHTML('beforeend', `
      <div style="display:grid; grid-template-columns:repeat(auto-fit,minmax(200px,1fr)); gap:14px; margin-bottom:32px;">
        <div class="stat-tile-v17 accent-teal">
          <div class="label">Total enrolled</div>
          <div class="value">${t.enrolled ?? 0}</div>
          <div class="delta muted">${capacityPct}% of capacity</div>
        </div>
        <div class="stat-tile-v17 accent-navy">
          <div class="label">Here right now</div>
          <div class="value">${t.present_now ?? 0}</div>
          <div class="delta muted">${presentPct}% of enrolled</div>
        </div>
        <div class="stat-tile-v17 ${(t.staff_on_floor ?? 0) === 0 ? 'accent-warn' : 'accent-success'}">
          <div class="label">Staff on floor</div>
          <div class="value">${t.staff_on_floor ?? 0}</div>
          <div class="delta muted">${(t.staff_on_floor ?? 0) === 0 ? 'No one clocked in' : 'Active'}</div>
        </div>
        <div class="stat-tile-v17 ${Number(t.receivables) > 0 ? 'accent-danger' : 'accent-success'}">
          <div class="label">Receivables</div>
          <div class="value">$${Number(t.receivables || 0).toFixed(2)}</div>
          <div class="delta ${Number(t.receivables) > 0 ? 'down' : 'up'}">${Number(t.receivables) > 0 ? 'Outstanding' : 'All collected'}</div>
        </div>
      </div>
    `);

    // ─── Centres section ──────────────────────────────────────
    const centresSection = document.createElement('div');
    centresSection.style.marginBottom = '32px';
    centresSection.innerHTML = `
      <div style="display:flex; justify-content:space-between; align-items:baseline; margin-bottom:14px;">
        <h2 style="font-family:var(--kt-font-display); font-weight:700; font-size:20px; margin:0;">Your centres</h2>
        <span style="font-size:12px; color:var(--kt-text-faint);">${(data.centres || []).length} total</span>
      </div>
    `;

    if (!data.centres || data.centres.length === 0) {
      centresSection.insertAdjacentHTML('beforeend', `
        <div style="padding:48px 24px; text-align:center; background:var(--kt-surface); border:1px dashed var(--kt-border); border-radius:var(--kt-radius);">
          <div style="font-size:36px; margin-bottom:8px;">🏢</div>
          <div style="font-weight:600; color:var(--kt-text); margin-bottom:4px;">No centres yet</div>
          <div style="color:var(--kt-text-muted); font-size:14px; margin-bottom:16px;">Get started by onboarding your first childcare centre.</div>
          <button class="btn btn-primary" id="kt-add-centre-empty">+ Add your first centre</button>
        </div>
      `);
      centresSection.querySelector('#kt-add-centre-empty')?.addEventListener('click', () => { window.location.href = '/signup.html'; });
    } else {
      const grid = document.createElement('div');
      grid.style.cssText = 'display:grid; grid-template-columns:repeat(auto-fill,minmax(340px,1fr)); gap:16px;';
      data.centres.forEach(c => {
        const breaches = c.rooms_in_breach || 0;
        const cap = c.capacity_pct || 0;
        const fillClass = cap > 90 ? 'danger' : cap > 70 ? 'warn' : '';
        grid.insertAdjacentHTML('beforeend', `
          <div class="centre-card-v17">
            <div class="head">
              <div>
                <div class="name">${esc(c.name)}</div>
                ${c.city ? `<div class="city">${esc(c.city)}</div>` : ''}
              </div>
              ${breaches > 0
                ? `<span class="tag-v17 danger">${breaches} breach${breaches === 1 ? '' : 'es'}</span>`
                : '<span class="tag-v17 success">Compliant</span>'}
            </div>
            <div class="stats">
              <div class="item">
                <div class="ilabel">Enrolled</div>
                <div class="ivalue">${c.enrolled || 0}${c.license_capacity ? ` <span style="font-size:13px; color:var(--kt-text-faint); font-weight:500;">/ ${c.license_capacity}</span>` : ''}</div>
              </div>
              <div class="item">
                <div class="ilabel">Present</div>
                <div class="ivalue">${c.present_now ?? 0}</div>
              </div>
              <div class="item">
                <div class="ilabel">Staff</div>
                <div class="ivalue">${c.staff_on_floor ?? 0}</div>
              </div>
            </div>
            ${c.license_capacity ? `
              <div class="capacity">
                <div class="row"><span>Capacity</span><span>${cap}%</span></div>
                <div class="bar"><div class="fill ${fillClass}" style="width:${Math.min(100, cap)}%;"></div></div>
              </div>` : ''}
            <div class="footer">
              <button class="manage-btn" data-centre-id="${c.id}">Manage this centre →</button>
            </div>
          </div>
        `);
      });
      centresSection.appendChild(grid);

      // Wire centre buttons
      centresSection.querySelectorAll('.manage-btn').forEach(btn => {
        btn.addEventListener('click', () => {
          const id = btn.getAttribute('data-centre-id');
          window.sessionStorage.setItem('kt_centre_id', id);
          window.location.hash = 'dashboard';
          window.location.reload();
        });
      });
    }
    wrap.appendChild(centresSection);

    // ─── Recent activity feed ─────────────────────────────────
    const activitySection = document.createElement('div');
    activitySection.innerHTML = `
      <div style="display:flex; justify-content:space-between; align-items:baseline; margin-bottom:14px;">
        <h2 style="font-family:var(--kt-font-display); font-weight:700; font-size:20px; margin:0;">Recent activity</h2>
        ${data.recent_activity && data.recent_activity.length > 0
          ? `<span style="font-size:12px; color:var(--kt-text-faint);">${data.recent_activity.length} event${data.recent_activity.length === 1 ? '' : 's'}</span>`
          : ''}
      </div>
    `;

    if (!data.recent_activity || data.recent_activity.length === 0) {
      activitySection.insertAdjacentHTML('beforeend', `
        <div style="padding:32px 24px; text-align:center; background:var(--kt-surface); border:1px dashed var(--kt-border); border-radius:var(--kt-radius); color:var(--kt-text-muted);">
          <div style="font-size:28px; margin-bottom:6px;">📋</div>
          No recent activity yet. As people use the system, key events will show up here.
        </div>
      `);
    } else {
      const feed = document.createElement('div');
      feed.className = 'activity-feed-v17';
      data.recent_activity.forEach(a => {
        feed.insertAdjacentHTML('beforeend', `
          <div class="row">
            <div>
              <div class="who">${esc(a.actor)} <span style="color:var(--kt-text-muted); font-weight:500;">— ${esc(a.action)}</span>${a.centre_name ? ` <span style="color:var(--kt-text-faint);">· ${esc(a.centre_name)}</span>` : ''}</div>
              ${a.details ? `<div class="detail">${esc(a.details)}</div>` : ''}
            </div>
            <div class="when">${esc(a.display_time)}</div>
          </div>
        `);
      });
      activitySection.appendChild(feed);
    }
    wrap.appendChild(activitySection);
  }

  // Expose + register
  window.KT = window.KT || {};
  window.KT.renderAgencyDashboard = renderAgencyDashboard;
  Shell.registerScreen('agency_admin:dashboard', renderAgencyDashboard);
  Shell.registerScreen('agency_admin:centres',   renderAgencyDashboard);
})(window);
