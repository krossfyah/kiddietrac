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
  function money(cents) {
    const n = (cents || 0) / 100;
    return '$' + n.toLocaleString('en-CA', { maximumFractionDigits: 0 });
  }
  // v22p3.4: prefix relative /storage/ paths with the API host (PWA is served
  // from app.kiddietrac.com; assets live under api.kiddietrac.com/storage/...).
  function absUrl(p) {
    if (!p) return '';
    if (/^https?:\/\//i.test(p)) return p;
    const base = (window.KT && window.KT.API_BASE) || 'https://api.kiddietrac.com/api/v1';
    return base.replace(/\/api\/v1\/?$/, '') + p;
  }

  // ─────────────────────────────────────────────────────────
  // v22p4.1: widget add/remove framework
  // ─────────────────────────────────────────────────────────
  const WIDGET_DEFS = {
    'mrr-sparkline': {
      title: 'Recurring revenue',
      build: (ctx) => widgetMrrSparkline(ctx.mrr),
    },
    'arr-agencies': {
      title: 'Annualised revenue + plan mix',
      build: (ctx) => widgetArrAgencies(ctx.mrr),
    },
    'users-by-role': {
      title: 'Users by role',
      build: (ctx) => widgetUsersByRole(ctx.analytics),
    },
    'enrollment-delta': {
      title: 'Enrollment & revenue (30d)',
      build: (ctx) => widgetEnrollmentDelta(ctx.analytics, ctx.mrr),
    },
    // ── New in v22p4.1 ──
    'centre-occupancy': {
      title: 'Centre occupancy',
      build: (ctx) => widgetCentreOccupancy(ctx.data),
    },
    'compliance-snapshot': {
      title: 'Compliance snapshot',
      build: (ctx) => widgetComplianceSnapshot(ctx.data),
    },
    'pending-tasks': {
      title: 'Pending approvals',
      build: (ctx) => widgetPendingTasks(),
    },
    'recent-activity-mini': {
      title: 'Activity feed',
      build: (ctx) => widgetRecentActivityMini(ctx.data),
    },
    // ── New widget options ──
    'attendance-today': {
      title: 'Attendance today',
      build: (ctx) => widgetAttendanceToday(ctx.data),
    },
    'staff-on-floor': {
      title: 'Staff on floor',
      build: (ctx) => widgetStaffOnFloor(ctx.data),
    },
    'capacity-utilization': {
      title: 'Capacity utilisation',
      build: (ctx) => widgetCapacityUtilization(ctx.data),
    },
    'receivables': {
      title: 'Outstanding receivables',
      build: (ctx) => widgetReceivables(ctx.data),
    },
  };
  const DEFAULT_WIDGETS = ['mrr-sparkline', 'arr-agencies', 'users-by-role', 'enrollment-delta'];

  function getEnabledWidgets() {
    try {
      const raw = localStorage.getItem('kt_agency_widgets');
      if (!raw) return DEFAULT_WIDGETS.slice();
      const arr = JSON.parse(raw);
      // Drop ids that no longer exist
      return arr.filter(id => WIDGET_DEFS[id]);
    } catch (e) { return DEFAULT_WIDGETS.slice(); }
  }
  function saveEnabledWidgets(arr) {
    try { localStorage.setItem('kt_agency_widgets', JSON.stringify(arr)); } catch (e) {}
  }
  function renderWidgetsGrid(section, ctx) {
    const grid = section.querySelector('#kt-widgets-grid');
    if (!grid) return;
    grid.innerHTML = '';
    const enabled = getEnabledWidgets();
    if (!enabled.length) {
      grid.innerHTML = '<div style="grid-column:1/-1;padding:32px;text-align:center;background:var(--kt-surface);border:1px dashed var(--kt-border);border-radius:14px;color:var(--kt-text-muted);">No widgets enabled. Click <b>+ Add widget</b> to pick from 12 insight cards.</div>';
      return;
    }
    // Clean cards — no inline arrows/numbers. Reordering + add/remove is done in
    // the "Customize widgets" grid modal (+ Add widget). Silent drag-to-reorder
    // on the dashboard is still supported for mouse users.
    enabled.forEach((id) => {
      const def = WIDGET_DEFS[id];
      if (!def) return;
      const card = def.build(ctx) || document.createElement('div');
      const card_wrap = document.createElement('div');
      card_wrap.style.cssText = 'position:relative;transition:opacity 120ms ease,outline 120ms ease;outline-offset:2px;';
      card_wrap.setAttribute('draggable', 'true');
      card.style.height = '100%';
      card_wrap.addEventListener('dragstart', (e) => { e.dataTransfer.setData('text/plain', id); e.dataTransfer.effectAllowed = 'move'; card_wrap.style.opacity = '0.4'; });
      card_wrap.addEventListener('dragend', () => { card_wrap.style.opacity = ''; });
      card_wrap.addEventListener('dragover', (e) => { e.preventDefault(); e.dataTransfer.dropEffect = 'move'; card_wrap.style.outline = '2px dashed #1F6080'; });
      card_wrap.addEventListener('dragleave', () => { card_wrap.style.outline = ''; });
      card_wrap.addEventListener('drop', (e) => {
        e.preventDefault(); card_wrap.style.outline = '';
        const dragged = e.dataTransfer.getData('text/plain');
        if (!dragged || dragged === id) return;
        const order = getEnabledWidgets();
        const from = order.indexOf(dragged), to = order.indexOf(id);
        if (from < 0 || to < 0) return;
        order.splice(to, 0, order.splice(from, 1)[0]);
        saveEnabledWidgets(order);
        renderWidgetsGrid(section, ctx);
      });
      card_wrap.appendChild(card);
      grid.appendChild(card_wrap);
    });
  }
  // Grid-based widget manager: drag the tiles in "On your dashboard" to set the
  // position they appear on the dashboard; click an available widget to add it,
  // or × to remove. Changes apply to the dashboard live.
  function openWidgetPicker(section, ctx) {
    const body = document.createElement('div');
    body.style.cssText = 'max-width:560px;';
    const hdr = (t) => { const h = document.createElement('div'); h.textContent = t; h.style.cssText = 'font-size:11.5px;font-weight:800;letter-spacing:.6px;text-transform:uppercase;color:#64748B;margin:4px 0 9px;'; return h; };

    function paint() {
      const enabled = getEnabledWidgets();
      const available = Object.keys(WIDGET_DEFS).filter(id => !enabled.includes(id));
      body.innerHTML = '';

      body.appendChild(hdr('On your dashboard — drag to arrange'));
      const grid = document.createElement('div');
      grid.style.cssText = 'display:grid;grid-template-columns:1fr 1fr;gap:8px;margin-bottom:18px;';
      if (!enabled.length) {
        const e = document.createElement('div');
        e.style.cssText = 'grid-column:1/-1;color:#64748B;font-size:13px;padding:14px;text-align:center;border:1px dashed #E2E8F0;border-radius:10px;';
        e.textContent = 'No widgets yet — add some below.';
        grid.appendChild(e);
      }
      enabled.forEach((id) => {
        const def = WIDGET_DEFS[id]; if (!def) return;
        const tile = document.createElement('div');
        tile.setAttribute('draggable', 'true');
        tile.style.cssText = 'display:flex;align-items:center;justify-content:space-between;gap:8px;padding:11px 12px;background:#EFF7FA;border:1.5px solid #BEE0EA;border-radius:10px;cursor:grab;font-size:13px;font-weight:600;color:#0F172A;transition:outline .1s,opacity .1s;outline-offset:2px;';
        tile.innerHTML = '<span style="display:flex;align-items:center;gap:8px;min-width:0;"><span style="color:#64748B;flex-shrink:0;">⠿</span><span style="overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">' + esc(def.title) + '</span></span>' +
          '<button data-rm="' + id + '" title="Remove" style="border:none;background:transparent;color:#64748B;font-size:17px;cursor:pointer;line-height:1;flex-shrink:0;padding:0 2px;">×</button>';
        tile.addEventListener('dragstart', (e) => { e.dataTransfer.setData('text/plain', id); tile.style.opacity = '.4'; });
        tile.addEventListener('dragend', () => { tile.style.opacity = ''; });
        tile.addEventListener('dragover', (e) => { e.preventDefault(); tile.style.outline = '2px dashed #1F6080'; });
        tile.addEventListener('dragleave', () => { tile.style.outline = ''; });
        tile.addEventListener('drop', (e) => {
          e.preventDefault(); tile.style.outline = '';
          const dragged = e.dataTransfer.getData('text/plain');
          if (!dragged || dragged === id) return;
          const order = getEnabledWidgets();
          const from = order.indexOf(dragged), to = order.indexOf(id);
          if (from < 0 || to < 0) return;
          order.splice(to, 0, order.splice(from, 1)[0]);
          saveEnabledWidgets(order); renderWidgetsGrid(section, ctx); paint();
        });
        grid.appendChild(tile);
      });
      body.appendChild(grid);

      body.appendChild(hdr('Available widgets'));
      const av = document.createElement('div');
      av.style.cssText = 'display:grid;grid-template-columns:1fr 1fr;gap:8px;';
      if (!available.length) {
        const e = document.createElement('div');
        e.style.cssText = 'grid-column:1/-1;color:#64748B;font-size:13px;padding:8px;';
        e.textContent = 'All widgets are on your dashboard.';
        av.appendChild(e);
      }
      available.forEach((id) => {
        const def = WIDGET_DEFS[id]; if (!def) return;
        const b = document.createElement('button'); b.type = 'button';
        b.style.cssText = 'display:flex;align-items:center;justify-content:space-between;padding:11px 12px;background:#fff;border:1.5px solid #E2E8F0;border-radius:10px;cursor:pointer;font-size:13px;font-weight:600;color:#0F172A;text-align:left;font-family:inherit;';
        b.innerHTML = '<span style="overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">' + esc(def.title) + '</span><span style="color:#1F6080;font-size:18px;line-height:1;flex-shrink:0;">＋</span>';
        b.addEventListener('click', () => {
          const order = getEnabledWidgets(); if (!order.includes(id)) order.push(id);
          saveEnabledWidgets(order); renderWidgetsGrid(section, ctx); paint();
        });
        av.appendChild(b);
      });
      body.appendChild(av);

      body.querySelectorAll('[data-rm]').forEach((btn) => btn.addEventListener('click', (e) => {
        e.stopPropagation();
        const id = btn.getAttribute('data-rm');
        saveEnabledWidgets(getEnabledWidgets().filter((x) => x !== id));
        renderWidgetsGrid(section, ctx); paint();
      }));
    }

    paint();
    window.KT.Shell.Modal.open({ title: '🧩 Customize widgets', body: body, actions: [{ label: 'Done', style: 'btn-primary' }] });
  }

  // ── New widget builders ──
  function widgetCentreOccupancy(data) {
    const centres = (data && data.centres) || [];
    if (!centres.length) return widgetCard('Centre occupancy', '', '<div style="color:#64748B;flex:1;display:flex;align-items:center;">No centres yet.</div>');
    const rows = centres.slice(0, 6).map(c => {
      const cap = c.license_capacity || 0;
      const enrolled = c.enrolled || 0;
      const pct = cap ? Math.round((enrolled / cap) * 100) : 0;
      const color = pct >= 90 ? '#DC2626' : pct >= 70 ? '#F59E0B' : '#16A34A';
      return '<div style="margin-bottom:8px;">' +
        '<div style="display:flex;justify-content:space-between;font-size:12px;color:#475569;margin-bottom:3px;">' +
          '<span style="font-weight:600;">' + esc(c.name) + '</span><span style="color:#64748B;">' + enrolled + ' / ' + cap + '</span>' +
        '</div>' +
        '<div style="height:6px;background:#F1F5F9;border-radius:3px;overflow:hidden;">' +
          '<div style="height:100%;width:' + Math.min(100, pct) + '%;background:' + color + ';"></div>' +
        '</div></div>';
    }).join('');
    return widgetCard('Centre occupancy', 'Enrolled vs. licensed capacity', rows);
  }
  function widgetComplianceSnapshot(data) {
    const centres = (data && data.centres) || [];
    const breaches = centres.reduce((acc, c) => acc + (c.rooms_in_breach || 0), 0);
    const lowStaff = centres.filter(c => (c.present_now || 0) > 0 && (c.staff_on_floor || 0) === 0).length;
    const compliant = centres.length - lowStaff;
    const status = breaches === 0 && lowStaff === 0
      ? { color: '#16A34A', label: 'ALL CLEAR' }
      : { color: '#DC2626', label: 'ATTENTION NEEDED' };
    const html = '<div style="display:flex;align-items:center;gap:12px;flex:1;">' +
      '<div style="width:64px;height:64px;border-radius:50%;background:' + status.color + ';color:white;display:flex;align-items:center;justify-content:center;font-size:28px;font-weight:800;flex-shrink:0;">' +
        (breaches + lowStaff) +
      '</div>' +
      '<div>' +
        '<div style="font-size:14px;font-weight:800;color:' + status.color + ';letter-spacing:0.5px;">' + status.label + '</div>' +
        '<div style="font-size:12px;color:#64748B;margin-top:6px;">' + breaches + ' ratio breach' + (breaches === 1 ? '' : 'es') + '<br>' + lowStaff + ' centre' + (lowStaff === 1 ? '' : 's') + ' with children + no staff<br>' + compliant + ' centre' + (compliant === 1 ? '' : 's') + ' compliant</div>' +
      '</div></div>';
    return widgetCard('Compliance snapshot', 'CCEYA ratios in real time', html);
  }
  function widgetPendingTasks() {
    // Fire-and-forget: render a card with placeholders, then asynchronously
    // fill from /director/medications?status=pending_auth + /director/edocuments.
    const card = widgetCard('Pending approvals', 'Items waiting on your attention',
      '<div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;flex:1;align-content:start;">' +
        '<div><div style="font-size:24px;font-weight:800;color:#0F172A;line-height:1;" id="kt-pa-meds">--</div><div style="font-size:11px;color:#6B7280;margin-top:4px;font-weight:600;">MED AUTHORIZATIONS</div></div>' +
        '<div><div style="font-size:24px;font-weight:800;color:#0F172A;line-height:1;" id="kt-pa-edocs">--</div><div style="font-size:11px;color:#6B7280;margin-top:4px;font-weight:600;">UNSIGNED eDOCS</div></div>' +
        '<div><div style="font-size:24px;font-weight:800;color:#0F172A;line-height:1;" id="kt-pa-onb">--</div><div style="font-size:11px;color:#6B7280;margin-top:4px;font-weight:600;">USERS UN-ONBOARDED</div></div>' +
        '<div><div style="font-size:24px;font-weight:800;color:#0F172A;line-height:1;" id="kt-pa-invs">--</div><div style="font-size:11px;color:#6B7280;margin-top:4px;font-weight:600;">UNUSED INVITES</div></div>' +
      '</div>');
    Api.get('/director/medications?status=pending_auth').then(r => {
      const el = card.querySelector('#kt-pa-meds'); if (el) el.textContent = (r.medications || []).length;
    }).catch(() => {});
    Api.get('/director/edocuments').then(r => {
      const docs = r.templates || [];
      const unsigned = docs.reduce((acc, d) => acc + ((d.families_total || 0) - (d.families_signed || 0)), 0);
      const el = card.querySelector('#kt-pa-edocs'); if (el) el.textContent = unsigned;
    }).catch(() => {});
    Api.get('/admin/users').then(r => {
      const users = r.users || [];
      const noOnb  = users.filter(u => !u.onboarded_at).length;
      const el = card.querySelector('#kt-pa-onb'); if (el) el.textContent = noOnb;
    }).catch(() => {});
    Api.get('/director/invitation-codes').then(r => {
      const codes = r.invitation_codes || [];
      const active = codes.filter(c => c.is_usable).length;
      const el = card.querySelector('#kt-pa-invs'); if (el) el.textContent = active;
    }).catch(() => {});
    return card;
  }
  function widgetRecentActivityMini(data) {
    const events = (data && data.recent_activity) || [];
    if (!events.length) return widgetCard('Activity feed', '', '<div style="color:#64748B;flex:1;display:flex;align-items:center;">No recent activity.</div>');
    const rows = events.slice(0, 5).map(a => {
      return '<div style="padding:8px 0;border-bottom:1px solid #F1F5F9;font-size:12px;">' +
        '<div style="color:#0F172A;"><b>' + esc(a.actor) + '</b> <span style="color:#64748B;">' + esc(a.action) + '</span></div>' +
        '<div style="color:#64748B;font-size:11px;margin-top:2px;">' + esc(a.display_time) + (a.centre_name ? ' · ' + esc(a.centre_name) : '') + '</div>' +
      '</div>';
    }).join('');
    return widgetCard('Activity feed', 'Latest 5 events', rows + '<div style="margin-top:auto;text-align:center;padding-top:10px;"><a href="#admin-billing" style="color:#1F6080;font-size:12px;font-weight:600;">See all activity →</a></div>');
  }

  function widgetAttendanceToday(data) {
    const centres = (data && data.centres) || [];
    const present = centres.reduce((a, c) => a + (c.present_now || 0), 0);
    const enrolled = centres.reduce((a, c) => a + (c.enrolled || 0), 0);
    const pct = enrolled ? Math.round((present / enrolled) * 100) : 0;
    const html = '<div style="display:flex;align-items:baseline;gap:8px;"><div style="font-size:40px;font-weight:800;color:#1F6080;line-height:1;">' + present + '</div><div style="font-size:15px;color:#64748B;">/ ' + enrolled + ' enrolled</div></div>' +
      '<div style="height:8px;background:#F1F5F9;border-radius:4px;overflow:hidden;margin-top:12px;"><div style="height:100%;width:' + Math.min(100, pct) + '%;background:#16A34A;"></div></div>' +
      '<div style="font-size:12px;color:#64748B;margin-top:6px;">' + pct + '% of enrolled children checked in right now</div>';
    return widgetCard('Attendance today', 'Children currently checked in', html);
  }
  function widgetStaffOnFloor(data) {
    const centres = (data && data.centres) || [];
    const staff = centres.reduce((a, c) => a + (c.staff_on_floor || 0), 0);
    const rows = centres.slice(0, 6).map(c => '<div style="display:flex;justify-content:space-between;font-size:12px;color:#475569;padding:5px 0;border-bottom:1px solid #F1F5F9;"><span style="font-weight:600;">' + esc(c.name) + '</span><span>' + (c.staff_on_floor || 0) + '</span></div>').join('');
    const html = '<div style="font-size:40px;font-weight:800;color:#1F6080;line-height:1;margin-bottom:10px;">' + staff + '</div>' + rows;
    return widgetCard('Staff on floor', 'Educators clocked in now', html);
  }
  function widgetCapacityUtilization(data) {
    const centres = (data && data.centres) || [];
    const cap = centres.reduce((a, c) => a + (c.license_capacity || 0), 0);
    const enrolled = centres.reduce((a, c) => a + (c.enrolled || 0), 0);
    const pct = cap ? Math.round((enrolled / cap) * 100) : 0;
    const color = pct >= 90 ? '#DC2626' : pct >= 70 ? '#F59E0B' : '#16A34A';
    const html = '<div style="display:flex;align-items:center;gap:16px;flex:1;">' +
      '<div style="width:74px;height:74px;border-radius:50%;background:conic-gradient(' + color + ' ' + (pct * 3.6) + 'deg,#F1F5F9 0);display:flex;align-items:center;justify-content:center;flex-shrink:0;"><div style="width:54px;height:54px;border-radius:50%;background:#fff;display:flex;align-items:center;justify-content:center;font-size:16px;font-weight:800;color:' + color + ';">' + pct + '%</div></div>' +
      '<div style="font-size:13px;color:#64748B;line-height:1.5;">' + enrolled + ' enrolled<br>of ' + cap + ' licensed spaces<br><b style="color:' + color + ';">' + Math.max(0, cap - enrolled) + ' spaces open</b></div></div>';
    return widgetCard('Capacity utilisation', 'Enrolled vs. licensed spaces', html);
  }
  function widgetReceivables(data) {
    // Defensive: use whichever outstanding-balance field the dashboard provides.
    const cents = (data && (data.receivables_cents != null ? data.receivables_cents
      : (data.outstanding_cents != null ? data.outstanding_cents
      : (data.summary && data.summary.receivables_cents))));
    const dollars = (data && data.receivables != null) ? data.receivables : (cents != null ? cents / 100 : null);
    const amt = dollars != null ? '$' + Number(dollars).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 }) : '—';
    const html = '<div style="font-size:clamp(20px,6.2vw,34px);font-weight:800;color:#B45309;line-height:1.05;overflow-wrap:anywhere;word-break:break-word;max-width:100%;">' + amt + '</div>' +
      '<div style="font-size:12px;color:#64748B;margin-top:8px;">Total unpaid balance across all families.</div>' +
      '<div style="margin-top:auto;padding-top:12px;"><a href="#admin-billing" style="color:#1F6080;font-size:12px;font-weight:600;">Open billing →</a></div>';
    return widgetCard('Outstanding receivables', 'Money owed to your agency', html);
  }

  // v22p3.6: insight-widget builders
  function widgetCard(title, subtitle, contentHtml) {
    const card = document.createElement('div');
    card.style.cssText = 'background:white;border-radius:14px;padding:18px;box-shadow:0 1px 4px rgba(0,0,0,0.05);min-height:160px;display:flex;flex-direction:column;';
    card.innerHTML =
      '<div style="font-size:11px;font-weight:800;color:#6B7280;letter-spacing:1px;text-transform:uppercase;margin-bottom:4px;">' + esc(title) + '</div>' +
      (subtitle ? '<div style="font-size:13px;color:#64748B;margin-bottom:10px;">' + esc(subtitle) + '</div>' : '') +
      contentHtml;
    return card;
  }

  function widgetMrrSparkline(mrr) {
    if (!mrr || !mrr.mrr_history_12mo) {
      return widgetCard('Recurring revenue', 'No data yet', '<div style="color:#64748B;font-size:14px;flex:1;display:flex;align-items:center;">No MRR data available.</div>');
    }
    const series = mrr.mrr_history_12mo;
    const vals = series.map(s => s.mrr_cents || 0);
    const max = Math.max.apply(null, vals.concat([1]));
    const min = Math.min.apply(null, vals);
    const W = 240, H = 60, pad = 4;
    const stepX = (W - pad * 2) / Math.max(1, vals.length - 1);
    const points = vals.map((v, i) => {
      const x = pad + i * stepX;
      const y = pad + (H - pad * 2) * (1 - (max === min ? 0.5 : (v - min) / (max - min)));
      return x.toFixed(1) + ',' + y.toFixed(1);
    });
    const last = vals[vals.length - 1] || 0;
    const prev = vals[vals.length - 2] || 0;
    const delta = last - prev;
    const deltaPct = prev > 0 ? Math.round((delta / prev) * 100) : (last > 0 ? 100 : 0);
    const trendColor = delta >= 0 ? '#16A34A' : '#DC2626';
    const sparkSvg =
      '<svg viewBox="0 0 ' + W + ' ' + H + '" style="width:100%;height:64px;margin-top:auto;">' +
        '<polyline fill="none" stroke="#1F6080" stroke-width="2" stroke-linejoin="round" points="' + points.join(' ') + '" />' +
        '<polyline fill="rgba(31,96,128,0.10)" stroke="none" points="' + pad + ',' + (H-pad) + ' ' + points.join(' ') + ' ' + (W-pad) + ',' + (H-pad) + '" />' +
      '</svg>';
    const big = '<div style="font-size:28px;font-weight:800;color:#0F172A;line-height:1;">' + money(last) +
      '<span style="font-size:11px;color:#64748B;font-weight:600;margin-left:4px;">' + (mrr.currency || 'CAD') + '/mo</span></div>' +
      '<div style="font-size:12px;color:' + trendColor + ';font-weight:700;margin-top:4px;">' + (delta >= 0 ? '▲' : '▼') + ' ' + Math.abs(deltaPct) + '% vs last month</div>';
    return widgetCard('Recurring revenue', 'Trailing 12 months', big + sparkSvg);
  }

  function widgetArrAgencies(mrr) {
    if (!mrr) return widgetCard('ARR & agencies', '', '<div style="color:#64748B;flex:1;display:flex;align-items:center;">No data.</div>');
    const status = (mrr.agencies && mrr.agencies.by_status) || {};
    const total  = (mrr.agencies && mrr.agencies.total) || 0;
    const bar = (label, count, color) => {
      const pct = total ? Math.round(count / total * 100) : 0;
      return '<div style="margin-bottom:6px;">' +
        '<div style="display:flex;justify-content:space-between;font-size:11px;color:#475569;font-weight:600;margin-bottom:2px;">' +
          '<span>' + label + '</span><span>' + count + '</span></div>' +
        '<div style="height:6px;background:#F1F5F9;border-radius:3px;overflow:hidden;">' +
          '<div style="height:100%;width:' + pct + '%;background:' + color + ';"></div></div></div>';
    };
    const html =
      '<div style="font-size:28px;font-weight:800;color:#0F172A;line-height:1;">' + money(mrr.arr_cents) +
      '<span style="font-size:11px;color:#64748B;font-weight:600;margin-left:4px;">ARR</span></div>' +
      '<div style="font-size:12px;color:#475569;margin:6px 0 12px;">' + total + ' agency' + (total === 1 ? '' : 'ies') + ' · ARPU ' + money(mrr.arpu_cents || 0) + '</div>' +
      bar('Active',    status.active || 0,    '#16A34A') +
      bar('Trial',     status.trial || 0,     '#F59E0B') +
      bar('Past due',  status.past_due || 0,  '#DC2626') +
      bar('Cancelled', status.cancelled || 0, '#9CA3AF');
    return widgetCard('Annualised revenue', 'By status', html);
  }

  function widgetUsersByRole(an) {
    if (!an || !an.users_by_role) {
      return widgetCard('Users by role', '', '<div style="color:#64748B;flex:1;display:flex;align-items:center;">No data.</div>');
    }
    const roles = an.users_by_role;
    const labels = { agency_admin: 'Admins', centre_director: 'Directors', educator: 'Educators', guardian: 'Parents', auditor: 'Auditors' };
    const colors = { agency_admin: '#1F6080', centre_director: '#0891B2', educator: '#8EC73C', guardian: '#F59E0B', auditor: '#7C3AED' };
    const total = Object.values(roles).reduce((a, b) => a + b, 0);
    if (!total) return widgetCard('Users by role', '', '<div style="color:#64748B;flex:1;display:flex;align-items:center;">No users yet.</div>');
    // Donut chart
    const R = 36, INNER = 22, CX = 50, CY = 50;
    let acc = 0;
    const arcs = [];
    Object.keys(labels).forEach(k => {
      const v = roles[k] || 0; if (!v) return;
      const start = (acc / total) * 2 * Math.PI - Math.PI / 2;
      acc += v;
      const end   = (acc / total) * 2 * Math.PI - Math.PI / 2;
      const large = (end - start) > Math.PI ? 1 : 0;
      const x1 = CX + R * Math.cos(start), y1 = CY + R * Math.sin(start);
      const x2 = CX + R * Math.cos(end),   y2 = CY + R * Math.sin(end);
      const ix2 = CX + INNER * Math.cos(end), iy2 = CY + INNER * Math.sin(end);
      const ix1 = CX + INNER * Math.cos(start), iy1 = CY + INNER * Math.sin(start);
      arcs.push(
        '<path fill="' + (colors[k] || '#94A3B8') + '" d="' +
          'M ' + x1 + ' ' + y1 +
          ' A ' + R + ' ' + R + ' 0 ' + large + ' 1 ' + x2 + ' ' + y2 +
          ' L ' + ix2 + ' ' + iy2 +
          ' A ' + INNER + ' ' + INNER + ' 0 ' + large + ' 0 ' + ix1 + ' ' + iy1 +
          ' Z"/>'
      );
    });
    const svg = '<svg viewBox="0 0 100 100" style="width:96px;height:96px;flex-shrink:0;">' + arcs.join('') +
      '<text x="50" y="48" text-anchor="middle" font-size="22" font-weight="800" fill="#0F172A">' + total + '</text>' +
      '<text x="50" y="62" text-anchor="middle" font-size="9" fill="#94A3B8" letter-spacing="1">USERS</text>' +
      '</svg>';
    const legend = Object.keys(labels).map(k => {
      const v = roles[k] || 0;
      return '<div style="display:flex;align-items:center;gap:6px;font-size:12px;margin-bottom:3px;">' +
        '<span style="width:10px;height:10px;border-radius:2px;background:' + (colors[k] || '#94A3B8') + ';"></span>' +
        '<span style="color:#475569;flex:1;">' + labels[k] + '</span>' +
        '<span style="font-weight:700;color:#0F172A;">' + v + '</span></div>';
    }).join('');
    return widgetCard('Users by role', null,
      '<div style="display:flex;gap:14px;align-items:center;flex:1;">' + svg + '<div style="flex:1;">' + legend + '</div></div>');
  }

  function widgetEnrollmentDelta(an, mrr) {
    const e = (an && an.last_30_days) || {};
    const tm = (an && an.this_month) || {};
    const churn = (mrr && mrr.this_month && mrr.this_month.churn_last_30_days) || 0;
    const net = e.net || 0;
    const netColor = net >= 0 ? '#16A34A' : '#DC2626';
    const html =
      '<div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;flex:1;align-content:start;">' +
        '<div><div style="font-size:24px;font-weight:800;color:' + netColor + ';line-height:1;">' + (net >= 0 ? '+' : '') + net + '</div>' +
          '<div style="font-size:11px;color:#6B7280;margin-top:4px;font-weight:600;">NET ENROLLMENT · 30 DAYS</div></div>' +
        '<div><div style="font-size:24px;font-weight:800;color:#0F172A;line-height:1;">$' + ((tm.revenue || 0).toLocaleString()) + '</div>' +
          '<div style="font-size:11px;color:#6B7280;margin-top:4px;font-weight:600;">REVENUE THIS MONTH</div></div>' +
        '<div><div style="font-size:18px;font-weight:700;color:#16A34A;line-height:1;">+' + (e.new_enrollments || 0) + '</div>' +
          '<div style="font-size:11px;color:#6B7280;margin-top:4px;font-weight:600;">NEW ENROLLMENTS</div></div>' +
        '<div><div style="font-size:18px;font-weight:700;color:' + (e.withdrawals ? '#DC2626' : '#9CA3AF') + ';line-height:1;">-' + (e.withdrawals || 0) + '</div>' +
          '<div style="font-size:11px;color:#6B7280;margin-top:4px;font-weight:600;">WITHDRAWALS</div></div>' +
      '</div>' +
      '<div style="font-size:11px;color:#64748B;margin-top:auto;padding-top:10px;border-top:1px solid #F1F5F9;">Outstanding receivables: $' + ((tm.outstanding || 0).toLocaleString()) + '</div>';
    return widgetCard('Enrollment & revenue', 'Trailing 30 days', html);
  }

  // The overview KPI tiles, computed from a /agency/dashboard payload. Each carries a
  // stable `key` so the live poll (below) can update its number in place without a
  // full, scroll-jumping re-render.
  function overviewKpis(data) {
    const t = data.totals || {};
    const capacitySum = (data.centres || []).reduce((acc, c) => acc + (c.license_capacity || 0), 0);
    const capacityPct = capacitySum > 0 ? Math.round((t.enrolled / capacitySum) * 100) : 0;
    const presentPct  = t.enrolled > 0 ? Math.round((t.present_now / t.enrolled) * 100) : 0;
    const _owed = Number(t.receivables || 0) > 0;
    const _noStaff = (t.staff_on_floor ?? 0) === 0;
    const _centresCount = (data.centres || []).length;
    const _roomsSum = (data.centres || []).reduce((acc, c) => acc + (c.room_count || 0), 0);
    const _breachSum = (data.centres || []).reduce((acc, c) => acc + (c.rooms_in_breach || 0), 0);
    const _overdue = t.overdue_invoices ?? 0;
    return [
      { key: 'enrolled', icon: '👶', value: t.enrolled ?? 0, label: 'Total enrolled', sub: capacityPct + '% of capacity', c1: '#5EEAD4', c2: '#0D9488', tint: '#F0FDFA', ink: '#0F766E' },
      { key: 'present', icon: '📍', value: t.present_now ?? 0, label: 'Here right now', sub: presentPct + '% of enrolled', c1: '#93C5FD', c2: '#2563EB', tint: '#EFF6FF', ink: '#1D4ED8' },
      { key: 'staff_floor', icon: '🧑‍🏫', value: t.staff_on_floor ?? 0, label: 'Staff on floor', sub: _noStaff ? 'No one clocked in' : 'Active', c1: _noStaff ? '#FCA5A5' : '#FCD34D', c2: _noStaff ? '#DC2626' : '#D97706', tint: _noStaff ? '#FEF2F2' : '#FFFBEB', ink: _noStaff ? '#B91C1C' : '#B45309' },
      { key: 'receivables', icon: '💳', value: '$' + Number(t.receivables || 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 }), label: 'Receivables', sub: _owed ? 'Outstanding' : 'All collected', c1: _owed ? '#FCA5A5' : '#86EFAC', c2: _owed ? '#DC2626' : '#16A34A', tint: _owed ? '#FEF2F2' : '#F0FDF4', ink: _owed ? '#B91C1C' : '#15803D' },
      { key: 'centres', icon: '🏫', value: _centresCount, label: 'Centres', sub: _roomsSum + ' room' + (_roomsSum === 1 ? '' : 's'), c1: '#C4B5FD', c2: '#7C3AED', tint: '#F5F3FF', ink: '#6D28D9' },
      { key: 'capacity', icon: '📊', value: capacitySum, label: 'Licensed capacity', sub: capacityPct + '% filled', c1: '#7DD3FC', c2: '#0284C7', tint: '#F0F9FF', ink: '#0369A1' },
      { key: 'families', icon: '👪', value: t.families ?? 0, label: 'Families', sub: 'enrolled', c1: '#FDBA74', c2: '#EA580C', tint: '#FFF7ED', ink: '#C2410C' },
      { key: 'team', icon: '👥', value: t.staff_total ?? 0, label: 'Team members', sub: 'educators + directors', c1: '#F9A8D4', c2: '#DB2777', tint: '#FDF2F8', ink: '#BE185D' },
      { key: 'overdue', icon: '🧾', value: _overdue, label: 'Overdue invoices', sub: _overdue > 0 ? 'Need follow-up' : 'All current', c1: _overdue > 0 ? '#FCA5A5' : '#86EFAC', c2: _overdue > 0 ? '#DC2626' : '#16A34A', tint: _overdue > 0 ? '#FEF2F2' : '#F0FDF4', ink: _overdue > 0 ? '#B91C1C' : '#15803D' },
      { key: 'ratio', icon: '⚖️', value: _breachSum, label: 'Ratio alerts', sub: _breachSum > 0 ? 'Rooms over ratio' : 'All within ratio', c1: _breachSum > 0 ? '#FCA5A5' : '#86EFAC', c2: _breachSum > 0 ? '#DC2626' : '#16A34A', tint: _breachSum > 0 ? '#FEF2F2' : '#F0FDF4', ink: _breachSum > 0 ? '#B91C1C' : '#15803D' },
    ];
  }

  // One live poll per screen visit — cleared when the overview re-renders or the user
  // navigates away (the tiles leave the DOM).
  let _overviewPollTimer = null;

  async function renderAgencyDashboard(main) {
    if (_overviewPollTimer) { clearInterval(_overviewPollTimer); _overviewPollTimer = null; }
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

    // Email delivery state (master + per-centre) for the on/off badges. Non-fatal.
    const emailInfo = { master_enabled: true, byCentre: {} };
    try {
      const ed = await Api.get('/admin/email-delivery');
      emailInfo.master_enabled = ed.master_enabled !== false;
      (ed.centres || []).forEach(c => { emailInfo.byCentre[c.id] = c.email_enabled !== false; });
    } catch (e) { /* badges just default to "on" */ }
    // Small on/off pill (HTML string) reused by the hero + centre cards.
    const emailPillHtml = (on, text) => '<span style="display:inline-flex;align-items:center;gap:4px;font-size:11px;font-weight:700;white-space:nowrap;padding:2px 9px;border-radius:20px;'
      + (on ? 'color:#15803D;background:#DCFCE7;' : 'color:#B45309;background:#FEF3C7;') + '">'
      + (on ? '✉️ ' : '🔕 ') + esc(text || (on ? 'Email on' : 'Email off')) + '</span>';

    Dom.clear(main);
    const wrap = document.createElement('div');
    main.appendChild(wrap);

    // ─── Header (breadcrumb · title · sub · actions) ──────────
    const totalEnrolled = data.totals?.enrolled ?? 0;
    const centreCount   = data.agency?.centre_count ?? (data.centres?.length || 0);

    // v22p12: hero card with gradient + cloud illustration + time-of-day greeting.
    let firstName = '';
    try {
      var stored = JSON.parse(sessionStorage.getItem('kt_user') || '{}');
      firstName = (stored.first_name || stored.name || '').split(' ')[0];
    } catch (e) { /* noop */ }
    const greet = (window.KT && window.KT.greetingForNow)
      ? window.KT.greetingForNow(firstName)
      : 'Welcome' + (firstName ? ', ' + firstName : '');
    const cloudsSvg = (window.KT && window.KT.Illustrations && window.KT.Illustrations.cloudsAndStars)
      ? window.KT.Illustrations.cloudsAndStars() : '';

    wrap.insertAdjacentHTML('beforeend', `
      <div class="kt-hero">
        <div style="display:flex;align-items:center;gap:14px;">
          ${data.agency?.logo_url
            ? `<img src="${esc(absUrl(data.agency.logo_url))}" alt="${esc(data.agency.name || '')}" style="width:54px;height:54px;border-radius:12px;object-fit:contain;background:rgba(255,255,255,.92);padding:5px;box-shadow:0 2px 8px rgba(0,0,0,.16);flex-shrink:0;">`
            : ''}
          <h1 style="margin:0;">${esc(data.agency?.name || 'Agency overview')}</h1>
        </div>
        <div class="kt-hero-sub">
          <strong>${centreCount}</strong> centre${centreCount === 1 ? '' : 's'} ·
          <strong>${totalEnrolled}</strong> children enrolled ·
          last updated just now
          <div style="margin-top:9px;">
            <a href="#email-settings" title="Manage email delivery" style="text-decoration:none;">
              ${emailPillHtml(emailInfo.master_enabled, emailInfo.master_enabled ? 'Agency email ON' : 'Agency email OFF')}
            </a>
          </div>
        </div>
        <div class="kt-hero-actions">
          <button class="kt-hero-btn primary" id="kt-add-centre-btn">+ Add centre</button>
          <button class="kt-hero-btn" id="kt-edit-agency-btn">✏️ Edit agency</button>
          <button class="kt-hero-btn" id="kt-refresh-btn" title="Refresh">↻ Refresh</button>
        </div>
        <div class="kt-hero-svg">${cloudsSvg}</div>
      </div>
    `);

    wrap.querySelector('#kt-refresh-btn')?.addEventListener('click', () => renderAgencyDashboard(main));
    wrap.querySelector('#kt-add-centre-btn')?.addEventListener('click', () => openAddCentre(main));
    wrap.querySelector('#kt-edit-agency-btn')?.addEventListener('click', () => openEditAgency(main, data.agency || {}));

    // This screen re-renders itself (the ↻ Refresh button rebuilds the hero with the
    // action buttons back INSIDE it). The shell only lifts banner buttons into the
    // toolbar during the initial visit — its budget/observer are long spent by the time
    // someone clicks Refresh — so lift them ourselves after every render. Buttons keep
    // their bound listeners (the lift moves the same nodes); idempotent on first mount.
    try { window.KT && KT.Shell && KT.Shell.liftHeroButtons && KT.Shell.liftHeroButtons(main); } catch (e) {}

    // ─── KPI strip ────────────────────────────────────────────
    // v23 (2026-07-20): KPI tiles restyled to match the colourful card theme
    // used across the portal — gradient icon badge, big accent number, tinted
    // card, hover lift. Receivables stays red when money is owed (a real signal).
    // Values carry data-kpi/data-kpi-sub so the live poll updates them in place.
    const _kpis = overviewKpis(data);
    const _kpiHtml = _kpis.map((k) => {
      // Adaptive value size: long money totals (e.g. "$34,339.63") overflowed the
      // ~132px tile at a fixed 27px. Shrink by length + allow a break so it fits.
      const _vs = String(k.value == null ? '' : k.value);
      const _vfs = _vs.length >= 10 ? '16px' : (_vs.length >= 8 ? '19px' : (_vs.length >= 6 ? '22px' : '27px'));
      return `
        <div class="kt-kpi-tile" style="position:relative;overflow:hidden;background:${k.tint};border:1px solid rgba(15,23,42,.06);border-radius:16px;padding:13px 16px;display:flex;align-items:center;gap:13px;transition:transform .15s ease, box-shadow .15s ease;">
          <div style="width:44px;height:44px;flex:0 0 auto;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:21px;background:linear-gradient(135deg,${k.c1},${k.c2});box-shadow:0 6px 13px -6px ${k.c2};">${k.icon}</div>
          <div style="min-width:0;flex:1;">
            <div data-kpi="${k.key}" style="font-size:${_vfs};font-weight:900;line-height:1.05;color:${k.ink};overflow-wrap:anywhere;transition:color .3s ease;">${k.value}</div>
            <div style="font-size:10.5px;font-weight:800;letter-spacing:.6px;color:${k.ink};opacity:.72;margin-top:3px;text-transform:uppercase;">${k.label}</div>
            <div data-kpi-sub="${k.key}" style="font-size:11.5px;color:#64748b;margin-top:1px;">${k.sub}</div>
          </div>
        </div>`;
    }).join('');
    wrap.insertAdjacentHTML('beforeend', `
      <style>.kt-kpi-tile:hover{transform:translateY(-3px);box-shadow:0 14px 24px -14px rgba(15,23,42,.28);}</style>
      <div style="display:grid; grid-template-columns:repeat(auto-fit,minmax(210px,1fr)); gap:12px; margin-bottom:20px;">${_kpiHtml}</div>
    `);

    // ── Live KPIs ─────────────────────────────────────────────
    // Refresh the tile numbers in place every 30s so "Here right now", "Staff on
    // floor", receivables, etc. stay current without a full, scroll-jumping re-render.
    // Self-stops once the tiles leave the DOM (navigation away or a manual refresh,
    // which clears the timer up front). A changed number briefly fades in as a cue.
    _overviewPollTimer = setInterval(async () => {
      if (!document.body.contains(wrap)) { clearInterval(_overviewPollTimer); _overviewPollTimer = null; return; }
      let fresh;
      try { fresh = await Api.get('/agency/dashboard'); } catch (e) { return; }
      if (!document.body.contains(wrap)) return;
      overviewKpis(fresh).forEach((k) => {
        const el = wrap.querySelector('[data-kpi="' + k.key + '"]');
        if (el) {
          const changed = String(el.textContent) !== String(k.value);
          el.textContent = k.value;
          el.style.color = k.ink;                       // state can flip (e.g. staff → red at 0)
          if (changed && el.animate) el.animate([{ opacity: 0.3 }, { opacity: 1 }], { duration: 650 });
        }
        const sub = wrap.querySelector('[data-kpi-sub="' + k.key + '"]');
        if (sub) sub.textContent = k.sub;
      });
    }, 30000);

    // ─── v22p4.1: Customizable business widgets ──────────────
    // 8 widget options, user chooses which to display via localStorage.
    // Defaults to the 4 original widgets. Each card has a × button to hide;
    // a "+ Add widget" button shows a picker of disabled widgets.
    const widgetsSection = document.createElement('div');
    widgetsSection.style.marginBottom = '32px';
    widgetsSection.innerHTML = `
      <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:14px;flex-wrap:wrap;gap:8px;">
        <div>
          <h2 style="font-family:var(--kt-font-display);font-weight:700;font-size:20px;margin:0;">Business insights</h2>
          <div style="font-size:12px;color:var(--kt-text-faint);margin-top:2px;">Live · pick what you want, drag to reorder</div>
        </div>
        <button id="kt-add-widget" style="padding:8px 14px;background:white;color:#1F6080;border:1.5px solid #1F6080;border-radius:8px;font-weight:600;cursor:pointer;font-size:13px;">+ Add widget</button>
      </div>
      <div id="kt-widgets-grid" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(280px,1fr));gap:14px;">
        ${'<div style="height:160px;background:var(--kt-bg);border-radius:14px;opacity:.5;"></div>'.repeat(4)}
      </div>
    `;
    wrap.appendChild(widgetsSection);

    // Render widgets asynchronously. Pull both endpoints once and pass results
    // to every widget builder — each ignores what it doesn't need.
    Promise.all([
      Api.get('/admin/mrr/overview').catch(() => null),
      Api.get('/admin/analytics').catch(() => null),
    ]).then(([mrr, an]) => {
      renderWidgetsGrid(widgetsSection, { mrr: mrr, analytics: an, data: data });
    });

    widgetsSection.querySelector('#kt-add-widget').addEventListener('click', () => openWidgetPicker(widgetsSection, { data: data }));

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
      centresSection.querySelector('#kt-add-centre-empty')?.addEventListener('click', () => openAddCentre(main));
    } else {
      const grid = document.createElement('div');
      grid.style.cssText = 'display:grid; grid-template-columns:repeat(auto-fill,minmax(340px,1fr)); gap:16px;';
      // A distinct, deterministic colour per name — so every centre/room card
      // (and its avatar) is visually differentiated even without a brand colour.
      const _CARD_PAL = ['#1F6FB2', '#0FA3B1', '#E0699A', '#7C3AED', '#F59E0B', '#10B981', '#EF6C4D', '#0891B2', '#DB2777', '#4F8A3D'];
      const cardColour = (s) => { s = String(s || ''); let h = 0; for (let i = 0; i < s.length; i++) h = (h * 31 + s.charCodeAt(i)) >>> 0; return _CARD_PAL[h % _CARD_PAL.length]; };

      data.centres.forEach(c => {
        const breaches = c.rooms_in_breach || 0;
        const cap = c.capacity_pct || 0;
        const fillClass = cap > 90 ? 'danger' : cap > 70 ? 'warn' : '';
        // v22p3.4: render the centre logo + brand_color on the card. Falls back
        // to the initial-in-a-tile when no logo is uploaded. Each centre gets a
        // distinct colour band (its brand colour, or a deterministic one by name).
        const brand = c.brand_color || cardColour(c.name);
        // Provider (home childcare person) avatar: their logo when set, else an
        // adult emoji face — sex assumed from the provider name (a person), never
        // an initial. A rounded tile, not a circle, so the global emoji sweeper
        // (circles only) doesn't reach it — hence the explicit emoji here.
        const provEmoji = (window.KT && KT.emojiFor) ? KT.emojiFor(KT.guessSex(c.provider_name || c.name || ''), false) : '🧑';
        // Prefer the provider's PHOTO (a face → cover) matched by email, then the
        // centre LOGO (branding → contain), then an emoji face. Consistent with the
        // Providers & rooms list so a provider's uploaded photo shows everywhere.
        const logoBlock = c.provider_photo_url
          ? `<img src="${esc(absUrl(c.provider_photo_url))}" alt="${esc(c.name)}" loading="lazy" style="width:44px;height:44px;border-radius:10px;object-fit:cover;box-shadow:0 1px 3px rgba(0,0,0,.08);">`
          : (c.logo_url
            ? `<img src="${esc(absUrl(c.logo_url))}" alt="${esc(c.name)}" style="width:44px;height:44px;border-radius:10px;object-fit:contain;background:white;box-shadow:0 1px 3px rgba(0,0,0,.08);">`
            : `<div style="width:44px;height:44px;border-radius:10px;background:${brand};display:flex;align-items:center;justify-content:center;font-size:26px;line-height:1;">${provEmoji}</div>`);
        // Capacity donut (circle graph). Shows capacity % when a licence cap is
        // set, else the enrolled count with a full ring.
        const _ringPct = c.license_capacity ? cap : (c.enrolled ? 100 : 0);
        const _centerTxt = c.license_capacity ? (cap + '%') : (c.enrolled || 0);
        const _circ = 2 * Math.PI * 20;
        const donutSvg =
          `<svg width="56" height="56" viewBox="0 0 56 56" style="flex-shrink:0;" aria-hidden="true">` +
          `<circle cx="28" cy="28" r="20" fill="none" stroke="#EEF0F4" stroke-width="6"/>` +
          `<circle cx="28" cy="28" r="20" fill="none" stroke="${brand}" stroke-width="6" stroke-linecap="round" stroke-dasharray="${_circ.toFixed(1)}" stroke-dashoffset="${(_circ * (1 - Math.max(0, Math.min(100, _ringPct)) / 100)).toFixed(1)}" transform="rotate(-90 28 28)"/>` +
          `<text x="28" y="32" text-anchor="middle" font-size="13" font-weight="800" fill="#0D1B2A">${_centerTxt}</text>` +
          `</svg>`;
        // Hover lists for the Present + Staff counters.
        const _presentTip = (c.present_children || []).length ? 'Present now:\n• ' + c.present_children.join('\n• ') : 'No children checked in right now';
        const _staffTip = (c.staff_present || []).length ? 'On the floor:\n• ' + c.staff_present.join('\n• ') : 'No staff clocked in';
        // Today's attendance roster (per centre): every enrolled child with
        // avatar + status + check-in/out times, present-first.
        const _roster = c.roster || [];
        const _av = (r) => r.photo_url
          ? `<img src="${esc(absUrl(r.photo_url))}" alt="" loading="lazy" style="width:26px;height:26px;border-radius:50%;object-fit:cover;flex-shrink:0;background:#EEF2F7;">`
          : `<div style="width:26px;height:26px;border-radius:50%;background:${cardColour(r.name)};color:#fff;font-size:11px;font-weight:700;display:flex;align-items:center;justify-content:center;flex-shrink:0;">${esc((r.name || '?').charAt(0).toUpperCase())}</div>`;
        const _statusHtml = (r) => {
          if (r.status === 'in') return `<span style="color:#16A34A;font-weight:700;white-space:nowrap;">✓ In ${esc(r.check_in_at || '')}</span>`;
          if (r.status === 'out') return `<span style="color:#64748B;white-space:nowrap;">${esc(r.check_in_at || '')} → ${esc(r.check_out_at || '')}</span>`;
          return `<span style="color:#B45309;background:#FEF3C7;font-weight:600;font-size:11px;padding:1px 7px;border-radius:20px;white-space:nowrap;">Not in</span>`;
        };
        const _rosterRows = _roster.map((r, i) => `
              <div style="display:flex;align-items:center;gap:8px;padding:6px 8px;font-size:12px;border-radius:6px;background:${i % 2 ? '#F4F8FC' : '#FFFFFF'};${r.status === 'absent' ? 'opacity:.72;' : ''}">
                ${_av(r)}
                <span style="flex:1;font-weight:600;color:var(--kt-text,#0D1B2A);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">${esc(r.name)}</span>
                ${_statusHtml(r)}
              </div>`).join('');
        const checkinHtml = _roster.length === 0 ? '' : `
            <div style="border-top:1px dashed var(--kt-border,#E5E9F0); padding:8px 16px 6px;">
              <div style="display:flex; gap:16px; font-size:12px; font-weight:700; margin-bottom:4px;">
                <span style="color:#16A34A;">● ${c.checked_in_count || 0} checked in</span>
                <span style="color:#B45309;">● ${c.not_checked_in_count || 0} not in</span>
              </div>
              <div style="max-height:196px; overflow-y:auto; margin:0 -2px;">${_rosterRows}</div>
            </div>`;
        grid.insertAdjacentHTML('beforeend', `
          <div class="centre-card-v17" style="border-left:6px solid ${brand};box-shadow:inset 0 3px 0 ${brand}22;">
            <div class="head">
              <div style="display:flex;align-items:center;gap:12px;">
                ${logoBlock}
                <div>
                  <div class="name">${esc(c.provider_name || c.name)}</div>
                  ${c.provider_name && c.provider_name !== c.name ? `<div style="font-size:11.5px;color:var(--kt-text-muted);font-weight:500;">${esc(c.name)}</div>` : ''}
                  ${c.tagline
                    ? `<div style="font-size:12px;color:var(--kt-text-muted);font-weight:500;margin-top:1px;">${esc(c.tagline)}</div>`
                    : (c.city ? `<div class="city">${esc(c.city)}</div>` : '')}
                </div>
              </div>
              <div style="display:flex;align-items:center;gap:10px;flex-shrink:0;">
                ${donutSvg}
                ${breaches > 0
                  ? `<span class="tag-v17 danger">${breaches} breach${breaches === 1 ? '' : 'es'}</span>`
                  : '<span class="tag-v17 success">Compliant</span>'}
              </div>
            </div>
            <div class="stats">
              <div class="item" style="background:#EFF6FF;border-radius:10px;padding:8px 10px;">
                <div class="ilabel" style="color:#1D4ED8;">Enrolled</div>
                <div class="ivalue" style="color:#1D4ED8;">${c.enrolled || 0}${c.license_capacity ? ` <span style="font-size:13px; color:#60A5FA; font-weight:500;">/ ${c.license_capacity}</span>` : ''}</div>
              </div>
              <div class="item" title="${esc(_presentTip)}" style="cursor:help;background:#F0FDF4;border-radius:10px;padding:8px 10px;">
                <div class="ilabel" style="color:#15803D;">Present ⓘ</div>
                <div class="ivalue" style="color:#16A34A;">${c.present_now ?? 0}</div>
              </div>
              <div class="item" title="${esc(_staffTip)}" style="cursor:help;background:${(c.staff_on_floor ?? 0) === 0 ? '#FEF2F2' : '#FFFBEB'};border-radius:10px;padding:8px 10px;">
                <div class="ilabel" style="color:${(c.staff_on_floor ?? 0) === 0 ? '#B91C1C' : '#B45309'};">Staff ⓘ</div>
                <div class="ivalue" style="color:${(c.staff_on_floor ?? 0) === 0 ? '#B91C1C' : '#B45309'};">${c.staff_on_floor ?? 0}</div>
              </div>
            </div>
            ${checkinHtml}
            <div class="footer" style="display:flex;align-items:center;justify-content:space-between;gap:10px;flex-wrap:wrap;">
              ${emailPillHtml(emailInfo.byCentre[c.id] !== false, emailInfo.byCentre[c.id] !== false ? 'Email on' : 'Email off')}
              <div style="display:flex;align-items:center;gap:10px;margin-left:auto;">
                <button class="review-day-btn" data-centre-id="${c.id}" title="Open this provider's daily overview" style="display:inline-flex;align-items:center;gap:6px;background:linear-gradient(135deg,#13B7CC,#1F6080);color:#fff;border:0;border-radius:10px;padding:8px 15px;font-size:12.5px;font-weight:700;cursor:pointer;box-shadow:0 4px 10px -4px rgba(31,96,128,.6);white-space:nowrap;">📅 Review day</button>
                <button class="manage-btn" data-centre-id="${c.id}">Manage this centre →</button>
              </div>
            </div>
          </div>
        `);
      });
      centresSection.appendChild(grid);

      // Wire centre buttons. v22p4.1: previously this set kt_centre_id and
      // reloaded — but agency_admin:dashboard is the SAME screen so the page
      // visibly didn't change. Now we route to the admin Centres tab and
      // stash an auto-open hint so the centre's edit modal opens on arrival.
      // Shortcut → this provider's Daily Overview, pre-selected to their centre.
      centresSection.querySelectorAll('.review-day-btn').forEach(btn => {
        btn.addEventListener('click', () => {
          try { sessionStorage.setItem('kt_pd_centre', btn.getAttribute('data-centre-id')); } catch (e) {}
          window.location.hash = 'provider-day';
        });
      });
      centresSection.querySelectorAll('.manage-btn').forEach(btn => {
        btn.addEventListener('click', async () => {
          const id = btn.getAttribute('data-centre-id');
          // Open the centre edit modal IN PLACE — no teleport to the
          // Administration › Centres tab (which reads as a stray background
          // screen). The dashboard payload lacks the editable fields, so pull
          // the full record first, then hand it to the shared modal with an
          // overview refresh callback.
          if (window.KT && window.KT.showCentreModal) {
            let full = null;
            try {
              const r = await Api.get('/admin/centres');
              full = (r.centres || []).find(x => String(x.id) === String(id));
            } catch (e) { /* fall through to the old navigation */ }
            if (full) { window.KT.showCentreModal(full, main, () => renderAgencyDashboard(main)); return; }
          }
          // Fallback: route to the Administration Centres tab (a real sidebar
          // screen) and auto-open the modal there.
          sessionStorage.setItem('kt_centre_id', id);
          sessionStorage.setItem('kt_admin_open_centre', id);
          window.location.hash = 'admin-centres';
        });
      });
    }
    wrap.appendChild(centresSection);

    // ─── Archived centres (restore / permanently delete) ──────
    const archived = data.archived_centres || [];
    if (archived.length) {
      const archSection = document.createElement('div');
      archSection.style.cssText = 'margin:4px 0 26px;';
      archSection.innerHTML = `
        <h2 style="font-family:var(--kt-font-display);font-weight:700;font-size:16px;margin:0 0 10px;color:var(--kt-text-muted);">🗄️ Archived centres (${archived.length})</h2>
        <div style="display:flex;flex-direction:column;gap:8px;">
          ${archived.map(c => `
            <div style="display:flex;align-items:center;gap:12px;padding:10px 14px;background:#F9FAFB;border:1px solid #E5E7EB;border-radius:10px;">
              <div style="flex:1;font-weight:600;color:#374151;">${esc(c.name)}${c.city ? ` <span style="font-weight:400;color:#64748B;font-size:13px;">· ${esc(c.city)}</span>` : ''}</div>
              <button class="kt-restore-centre" data-centre-id="${c.id}" style="background:#ECFDF5;border:1px solid #A7F3D0;color:#065F46;border-radius:8px;padding:6px 12px;font-size:12px;font-weight:700;cursor:pointer;">Restore</button>
              <button class="kt-delete-centre" data-centre-id="${c.id}" data-centre-name="${esc(c.name)}" style="background:#FEF2F2;border:1px solid #FECACA;color:#B91C1C;border-radius:8px;padding:6px 12px;font-size:12px;font-weight:700;cursor:pointer;">Delete</button>
            </div>`).join('')}
        </div>`;
      archSection.addEventListener('click', async (e) => {
        const rBtn = e.target.closest && e.target.closest('.kt-restore-centre');
        const dBtn = e.target.closest && e.target.closest('.kt-delete-centre');
        if (rBtn) {
          const id = rBtn.getAttribute('data-centre-id');
          try { await Api.post('/admin/centres/' + id + '/restore'); if (window.KT && KT.Dom && KT.Dom.toast) KT.Dom.toast('Centre restored', 'success'); renderAgencyDashboard(main); }
          catch (err) { alert('Could not restore: ' + (err.message || err)); }
        } else if (dBtn) {
          const id = dBtn.getAttribute('data-centre-id'), nm = dBtn.getAttribute('data-centre-name') || 'this centre';
          if (!await KT.confirm({ title: 'Permanently delete “' + nm + '”?', description: 'This cannot be undone.', tone: 'danger' })) return;
          try { await Api.delete('/admin/centres/' + id + '/permanent'); if (window.KT && KT.Dom && KT.Dom.toast) KT.Dom.toast('Centre permanently deleted', 'success'); renderAgencyDashboard(main); }
          catch (err) { alert('Could not delete: ' + (err.message || err)); }
        }
      });
      wrap.appendChild(archSection);
    }

    // ─── Team & family demographics (onboarding analytics) ────
    // Self-reported race/ethnicity + auto-detected device type, captured at
    // onboarding into profile_extras. Loaded async so it never delays the board.
    const demoSection = document.createElement('div');
    demoSection.style.cssText = 'margin-bottom:26px;';
    demoSection.innerHTML = `
      <div style="display:flex; justify-content:space-between; align-items:baseline; margin-bottom:14px;">
        <h2 style="font-family:var(--kt-font-display); font-weight:700; font-size:20px; margin:0;">Team &amp; family demographics</h2>
        <span style="font-size:12px; color:var(--kt-text-faint);">self-reported at onboarding · optional</span>
      </div>
      <div id="kt-demo-body" style="background:var(--kt-surface); border:1px solid var(--kt-border); border-radius:var(--kt-radius); padding:18px 20px; color:var(--kt-text-muted); font-size:13px;">Loading…</div>
    `;
    wrap.appendChild(demoSection);
    (async () => {
      const body = demoSection.querySelector('#kt-demo-body');
      let d;
      try { d = await Api.get('/admin/analytics/demographics'); }
      catch (e) { demoSection.style.display = 'none'; return; }   // endpoint absent → hide quietly
      if (!d || ((d.ethnicity || []).length === 0 && (d.devices || []).length === 0)) {
        body.innerHTML = '<div style="text-align:center; padding:14px 8px;"><span style="font-size:24px;">📊</span><div style="margin-top:6px;">No demographic data yet — it fills in as your people complete onboarding'
          + (d && d.total ? ` (0 of ${d.total} reported so far)` : '') + '.</div></div>';
        return;
      }
      const PAL = ['#1F6080', '#13B7CC', '#8B5CF6', '#F59E0B', '#10B981', '#EF4444', '#EC4899', '#6366F1', '#0EA5E9', '#84CC16', '#F97316', '#14B8A6', '#A855F7', '#64748B'];
      // Compact horizontal bars (tighter than before — the old ones were needlessly tall).
      const bars = (rows) => {
        if (!rows || !rows.length) return '<div style="color:var(--kt-text-faint); font-size:12px; padding:4px 0;">No responses yet.</div>';
        const max = Math.max.apply(null, rows.map(r => r.count)) || 1;
        return rows.map((r, i) => {
          const pct = Math.round((r.count / max) * 100);
          const c = PAL[i % PAL.length];
          return `<div style="display:flex; align-items:center; gap:9px; margin-bottom:6px;">
            <span style="flex:0 0 108px; font-size:12px; color:var(--kt-text); white-space:nowrap; overflow:hidden; text-overflow:ellipsis;">${esc(r.label)}</span>
            <span style="flex:1; height:6px; background:var(--kt-border); border-radius:5px; overflow:hidden;"><span style="display:block; height:100%; width:${pct}%; background:${c}; border-radius:5px;"></span></span>
            <span style="flex:0 0 auto; font-size:12px; font-weight:700; color:var(--kt-text-muted); font-variant-numeric:tabular-nums;">${r.count}</span>
          </div>`;
        }).join('');
      };
      // Device type → compact tiles with the real Apple / Android brand marks.
      const APPLE = '<svg viewBox="0 0 24 24" width="20" height="20" fill="currentColor" aria-hidden="true"><path d="M17.05 12.04c-.03-2.65 2.16-3.92 2.26-3.98-1.23-1.8-3.15-2.05-3.83-2.08-1.63-.16-3.18.96-4.01.96-.82 0-2.1-.94-3.46-.91-1.78.03-3.42 1.03-4.34 2.62-1.85 3.21-.47 7.96 1.33 10.56.88 1.27 1.93 2.7 3.31 2.65 1.33-.05 1.83-.86 3.44-.86 1.61 0 2.06.86 3.46.83 1.43-.03 2.34-1.31 3.22-2.58.7-1.02.98-1.55 1.53-2.71-4.02-1.53-3.09-6.31.36-7.97zM14.53 4.5c.73-.89 1.22-2.12 1.09-3.35-1.05.04-2.32.7-3.07 1.58-.67.78-1.26 2.03-1.1 3.23 1.17.09 2.36-.6 3.08-1.46z"/></svg>';
      const ANDROID = '<svg viewBox="0 0 24 24" width="20" height="20" fill="currentColor" aria-hidden="true"><path d="M17.6 9.48l1.84-3.18c.16-.31.04-.69-.26-.85a.637.637 0 0 0-.83.22l-1.88 3.24a11.43 11.43 0 0 0-8.94 0L5.65 5.67a.643.643 0 0 0-.87-.2c-.28.18-.37.54-.22.83L6.4 9.48A10.78 10.78 0 0 0 1 18h22a10.78 10.78 0 0 0-5.4-8.52zM7 15.25a1.25 1.25 0 1 1 0-2.5 1.25 1.25 0 0 1 0 2.5zm10 0a1.25 1.25 0 1 1 0-2.5 1.25 1.25 0 0 1 0 2.5z"/></svg>';
      const devMeta = (label) => {
        const l = String(label || '').toLowerCase();
        if (/apple|ios|iphone|ipad|mac/.test(l)) return { icon: APPLE, color: '#111827', name: 'Apple / iOS' };
        if (/android/.test(l)) return { icon: ANDROID, color: '#3DDC84', name: 'Android' };
        if (/web|desktop|browser|windows/.test(l)) return { icon: '🌐', color: '#0EA5E9', name: label || 'Web' };
        return { icon: '💻', color: '#64748B', name: label || 'Other' };
      };
      const deviceTiles = (rows) => {
        if (!rows || !rows.length) return '<div style="color:var(--kt-text-faint); font-size:12px; padding:4px 0;">No responses yet.</div>';
        const total = rows.reduce((a, r) => a + (r.count || 0), 0) || 1;
        return '<div style="display:flex; flex-wrap:wrap; gap:10px;">' + rows.map(r => {
          const m = devMeta(r.label);
          const pct = Math.round((r.count / total) * 100);
          const glyph = m.icon.charAt(0) === '<'
            ? `<span style="color:${m.color}; display:flex; align-items:center;">${m.icon}</span>`
            : `<span style="font-size:19px; line-height:1;">${m.icon}</span>`;
          return `<div style="flex:1 1 130px; min-width:120px; display:flex; align-items:center; gap:11px; padding:10px 13px; border:1px solid var(--kt-border); border-radius:12px; background:var(--kt-surface);">
            ${glyph}
            <div style="min-width:0;">
              <div style="font-size:19px; font-weight:800; line-height:1; color:var(--kt-text); font-variant-numeric:tabular-nums;">${r.count}</div>
              <div style="font-size:11px; color:var(--kt-text-muted); margin-top:3px; white-space:nowrap;">${esc(m.name)} · ${pct}%</div>
            </div>
          </div>`;
        }).join('') + '</div>';
      };
      const cover = d.total ? ` · ${d.reported}/${d.total} shared` : '';
      body.style.color = 'inherit';
      body.innerHTML = `
        <div style="display:grid; grid-template-columns:1.35fr 1fr; gap:22px; align-items:start;">
          <div>
            <div style="font-weight:700; font-size:12.5px; color:var(--kt-text); margin-bottom:10px;">🌍 Race / ethnicity <span style="font-weight:500; color:var(--kt-text-faint);">${cover}</span></div>
            ${bars(d.ethnicity)}
          </div>
          <div>
            <div style="font-weight:700; font-size:12.5px; color:var(--kt-text); margin-bottom:10px;">📱 Device type</div>
            ${deviceTiles(d.devices)}
          </div>
        </div>`;
    })();

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
      const relTime = (iso) => {
        if (!iso) return '';
        const t = new Date(String(iso).replace(' ', 'T') + 'Z');
        if (isNaN(t)) return '';
        let s = (Date.now() - t.getTime()) / 1000; if (s < 0) s = 0;
        if (s < 60) return 'just now';
        if (s < 3600) return Math.floor(s / 60) + 'm ago';
        if (s < 86400) return Math.floor(s / 3600) + 'h ago';
        if (s < 604800) return Math.floor(s / 86400) + 'd ago';
        return t.toLocaleDateString();
      };
      // Gmail-style: a bounded, scrollable window + pagination.
      const events = data.recent_activity;
      const PAGE = 8;
      let page = 0;
      const box = document.createElement('div');
      box.style.cssText = 'border:1px solid var(--kt-border);border-radius:var(--kt-radius);overflow:hidden;background:#fff;';
      const feed = document.createElement('div');
      feed.className = 'activity-feed-v17';
      feed.style.cssText = 'max-height:360px;overflow-y:auto;overscroll-behavior:contain;';
      const pager = document.createElement('div');
      pager.style.cssText = 'display:flex;align-items:center;justify-content:space-between;gap:10px;padding:8px 14px;border-top:1px solid var(--kt-border);background:var(--kt-surface);';
      const btnCss = 'font-size:12.5px;font-weight:600;padding:6px 12px;border-radius:8px;border:1px solid var(--kt-border);background:#fff;color:#1F6080;cursor:pointer;';
      // Person avatar for a feed row: uploaded photo, else a coloured initial
      // circle. A small emoji badge (check-in/out, clock, etc.) sits on the corner.
      // NOTE: the centres-block `cardColour` is const-scoped to that block, so it
      // is NOT in scope here — a photo-less feed row (e.g. a sign-in event) threw
      // "cardColour is not defined" and broke the whole overview. Local copy:
      const _FEED_PAL = ['#1F6FB2', '#0FA3B1', '#E0699A', '#7C3AED', '#F59E0B', '#10B981', '#EF6C4D', '#0891B2', '#DB2777', '#4F8A3D'];
      const cardColour = (s) => { s = String(s || ''); let h = 0; for (let i = 0; i < s.length; i++) h = (h * 31 + s.charCodeAt(i)) >>> 0; return _FEED_PAL[h % _FEED_PAL.length]; };
      const feedAvatar = (a) => {
        const nm = a.name || a.text || '?';
        const badge = `<span style="position:absolute;right:-3px;bottom:-3px;font-size:13px;line-height:1;background:#fff;border-radius:50%;box-shadow:0 0 0 1.5px #fff;">${esc(a.icon || '•')}</span>`;
        const inner = a.photo_url
          ? `<img src="${esc(absUrl(a.photo_url))}" alt="" loading="lazy" style="width:32px;height:32px;border-radius:50%;object-fit:cover;background:#EEF2F7;">`
          : `<div style="width:32px;height:32px;border-radius:50%;background:${cardColour(nm)};color:#fff;font-size:13px;font-weight:700;display:flex;align-items:center;justify-content:center;">${esc(String(nm).trim().charAt(0).toUpperCase() || '•')}</div>`;
        return `<div style="position:relative;flex-shrink:0;">${inner}${badge}</div>`;
      };
      function renderPage() {
        feed.innerHTML = '';
        events.slice(page * PAGE, page * PAGE + PAGE).forEach(a => {
          feed.insertAdjacentHTML('beforeend', `
            <div class="row">
              <div style="display:flex; align-items:center; gap:10px;">
                ${feedAvatar(a)}
                <div class="who">${esc(a.text || a.action || '')}</div>
              </div>
              <div class="when">${esc(relTime(a.created_at))}</div>
            </div>`);
        });
        feed.scrollTop = 0;
        const total = Math.max(1, Math.ceil(events.length / PAGE));
        pager.innerHTML = '';
        const prev = document.createElement('button'); prev.textContent = '‹ Newer'; prev.style.cssText = btnCss; prev.disabled = page === 0; if (prev.disabled) prev.style.opacity = '.45';
        const info = document.createElement('span'); info.style.cssText = 'font-size:12.5px;color:var(--kt-text-muted);'; info.textContent = 'Page ' + (page + 1) + ' of ' + total + ' · ' + events.length + ' events';
        const next = document.createElement('button'); next.textContent = 'Older ›'; next.style.cssText = btnCss; next.disabled = page >= total - 1; if (next.disabled) next.style.opacity = '.45';
        prev.onclick = () => { if (page > 0) { page--; renderPage(); } };
        next.onclick = () => { if (page < total - 1) { page++; renderPage(); } };
        pager.appendChild(prev); pager.appendChild(info); pager.appendChild(next);
      }
      renderPage();
      box.appendChild(feed); box.appendChild(pager);
      activitySection.appendChild(box);
    }
    wrap.appendChild(activitySection);
  }

  // ── v22p90: Edit agency — view/edit core agency settings ───────────
  function eaActiveAgencyId(fallback) {
    try { const a = parseInt(sessionStorage.getItem('kt_active_agency_id'), 10); if (a) return a; } catch (e) {}
    return fallback || null;
  }

  function openAddCentre(main) {
    const overlay = document.createElement('div');
    overlay.style.cssText = 'position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:1000;display:flex;align-items:center;justify-content:center;padding:24px;overflow:auto;';
    const modal = document.createElement('div');
    modal.style.cssText = 'background:white;border-radius:16px;max-width:580px;width:100%;max-height:calc(100vh - 48px);overflow-y:auto;box-shadow:0 12px 36px rgba(0,0,0,.25);';
    overlay.appendChild(modal);
    const fld = (label, id, ph, type) =>
      `<div style="margin-bottom:14px;">
        <label style="display:block;font-size:13px;font-weight:700;color:#374151;margin-bottom:6px;">${esc(label)}</label>
        <input id="${id}" type="${type || 'text'}" placeholder="${esc(ph || '')}" style="width:100%;padding:9px 12px;border:1px solid #D1D5DB;border-radius:8px;font-size:14px;box-sizing:border-box;">
      </div>`;
    modal.innerHTML = `
      <div style="padding:18px 24px;border-bottom:1px solid #E5E7EB;display:flex;align-items:center;justify-content:space-between;">
        <h2 style="margin:0;font-size:18px;">🏫 Add centre / room</h2>
        <button id="kt-ac-close" style="background:transparent;border:none;font-size:22px;color:#6B7280;cursor:pointer;line-height:1;padding:4px 10px;">×</button>
      </div>
      <div style="padding:20px 24px;">
        ${fld('Name', 'kt-ac-name', 'e.g. Maple Grove — Toddler Room')}
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">
          ${fld('Supervisor / owner first name', 'kt-ac-sfn', '')}
          ${fld('Supervisor / owner last name', 'kt-ac-sln', '')}
        </div>
        <div style="display:grid;grid-template-columns:2fr 1fr;gap:12px;">
          ${fld('Licence number', 'kt-ac-license', 'optional')}
          ${fld('Licensed capacity', 'kt-ac-cap', 'e.g. 24', 'number')}
        </div>
        ${fld('Address', 'kt-ac-addr', 'Street address')}
        <div style="display:grid;grid-template-columns:2fr 1fr 1fr;gap:12px;">
          ${fld('City', 'kt-ac-city', '')}
          ${fld('Province', 'kt-ac-prov', 'ON')}
          ${fld('Postal code', 'kt-ac-postal', '')}
        </div>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">
          ${fld('Phone', 'kt-ac-phone', '', 'tel')}
          ${fld('Email', 'kt-ac-email', '', 'email')}
        </div>
        <label style="display:flex;align-items:center;gap:9px;font-size:14px;color:#111827;cursor:pointer;margin:2px 0 4px;">
          <input id="kt-ac-cwelcc" type="checkbox"> <span>Enrolled in CWELCC</span>
        </label>
        <div id="kt-ac-msg" style="margin-top:12px;font-size:13px;"></div>
        <div style="display:flex;justify-content:flex-end;gap:8px;margin-top:16px;">
          <button id="kt-ac-cancel" style="background:white;color:#374151;border:1px solid #D1D5DB;border-radius:8px;padding:9px 18px;font-weight:700;cursor:pointer;">Cancel</button>
          <button id="kt-ac-save" style="background:#1F6080;color:white;border:none;border-radius:8px;padding:9px 18px;font-weight:700;cursor:pointer;">Create centre</button>
        </div>
      </div>`;
    document.body.appendChild(overlay);
    const close = () => overlay.remove();
    modal.querySelector('#kt-ac-close').addEventListener('click', close);
    modal.querySelector('#kt-ac-cancel').addEventListener('click', close);
    overlay.addEventListener('click', (e) => { if (e.target === overlay) close(); });
    setTimeout(() => modal.querySelector('#kt-ac-name')?.focus(), 60);

    modal.querySelector('#kt-ac-save').addEventListener('click', async () => {
      const msg = modal.querySelector('#kt-ac-msg');
      const v = (id) => (modal.querySelector('#' + id).value || '').trim();
      const name = v('kt-ac-name');
      if (!name) { msg.style.color = '#DC2626'; msg.textContent = 'Name is required.'; return; }
      const payload = {
        name: name,
        supervisor_first_name: v('kt-ac-sfn') || null,
        supervisor_last_name: v('kt-ac-sln') || null,
        license_number: v('kt-ac-license') || null,
        license_capacity: v('kt-ac-cap') ? parseInt(v('kt-ac-cap'), 10) : null,
        address_line1: v('kt-ac-addr') || null,
        city: v('kt-ac-city') || null,
        province: v('kt-ac-prov') || null,
        postal_code: v('kt-ac-postal') || null,
        phone: v('kt-ac-phone') || null,
        email: v('kt-ac-email') || null,
        cwelcc_enrolled: modal.querySelector('#kt-ac-cwelcc').checked,
      };
      const saveBtn = modal.querySelector('#kt-ac-save');
      saveBtn.disabled = true; msg.style.color = '#1F6080'; msg.textContent = 'Creating…';
      try {
        await Api.post('/admin/centres', payload);
        close();
        if (Dom.toast) Dom.toast('Centre created', 'success');
        renderAgencyDashboard(main);
      } catch (e) {
        saveBtn.disabled = false; msg.style.color = '#DC2626'; msg.textContent = 'Could not create: ' + (e.message || 'error');
      }
    });
  }

  async function openEditAgency(main, agency) {
    agency = agency || {};
    const agencyId = eaActiveAgencyId(agency.id);
    if (!agencyId) { if (Dom && Dom.toast) Dom.toast('No active agency selected', 'error'); return; }

    // Local avatar-colour helper. The owner card below uses it when the owner has
    // no photo; the module's other cardColour is scoped to a different function,
    // so referencing it here threw "cardColour is not defined" and broke the whole
    // Edit-agency modal (only surfaced once an owner without an avatar existed).
    const _EA_PAL = ['#1F6FB2', '#0FA3B1', '#E0699A', '#7C3AED', '#F59E0B', '#10B981', '#EF6C4D', '#0891B2', '#DB2777', '#4F8A3D'];
    const cardColour = (s) => { s = String(s || ''); let h = 0; for (let i = 0; i < s.length; i++) h = (h * 31 + s.charCodeAt(i)) >>> 0; return _EA_PAL[h % _EA_PAL.length]; };

    // Prefill from the agencies list (name/contact_email/subdomain). contact_phone
    // is not in that payload, so it starts blank and is only saved if edited.
    const rec = { name: agency.name || '', contact_email: '', contact_phone: '', subdomain: '' };
    try {
      const list = await Api.get('/agencies');
      const arr = (list && list.agencies) || [];
      const found = arr.find(a => Number(a.id) === Number(agencyId));
      if (found) {
        rec.name = found.name || rec.name;
        rec.contact_email = found.contact_email || '';
        rec.subdomain = found.subdomain || '';
      }
    } catch (e) { /* prefill is best-effort */ }

    const overlay = document.createElement('div');
    overlay.style.cssText = 'position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:1000;display:flex;align-items:center;justify-content:center;padding:24px;overflow:auto;';
    const modal = document.createElement('div');
    modal.style.cssText = 'background:white;border-radius:16px;max-width:560px;width:100%;max-height:calc(100vh - 48px);overflow-y:auto;box-shadow:0 12px 36px rgba(0,0,0,.25);';
    overlay.appendChild(modal);

    function fld(label, id, val, ph, hint) {
      return `<div style="margin-bottom:14px;">
        <label style="display:block;font-size:13px;font-weight:700;color:#374151;margin-bottom:6px;">${esc(label)}</label>
        <input id="${id}" type="text" value="${esc(val || '')}" placeholder="${esc(ph || '')}" style="width:100%;padding:9px 12px;border:1px solid #D1D5DB;border-radius:8px;font-size:14px;box-sizing:border-box;">
        ${hint ? `<div style="font-size:11px;color:#64748B;margin-top:4px;">${hint}</div>` : ''}
      </div>`;
    }
    modal.innerHTML = `
      <div style="padding:18px 24px;border-bottom:1px solid #E5E7EB;display:flex;align-items:center;justify-content:space-between;">
        <h2 style="margin:0;font-size:18px;">✏️ Edit agency</h2>
        <button id="kt-ea-close" style="background:transparent;border:none;font-size:22px;color:#6B7280;cursor:pointer;line-height:1;padding:4px 10px;">×</button>
      </div>
      <div style="padding:20px 24px;">
        ${fld('Agency name', 'kt-ea-name', rec.name, 'Your agency name')}
        ${fld('Contact email', 'kt-ea-email', rec.contact_email, 'info@youragency.com')}
        ${fld('Contact phone', 'kt-ea-phone', rec.contact_phone, '(555) 123-4567')}
        ${fld('Subdomain', 'kt-ea-subdomain', rec.subdomain, 'youragency', 'Your portal address: <strong>&lt;subdomain&gt;.kiddietrac.com</strong> — lowercase letters, numbers, hyphens.')}
        ${agency.owner ? (function (o) {
          var roleLabel = o.role === 'agency_admin' ? 'Owner / admin' : (o.role === 'centre_director' ? 'Primary director' : '');
          var hasPerson = !!o.name;
          var line2 = [o.email, o.phone].filter(Boolean).join(' · ');
          var contactLine = (o.contact_email || o.contact_phone) ? [o.contact_email, o.contact_phone].filter(Boolean).join(' · ') : '';
          var avatar = hasPerson
            ? (o.photo_url
                ? '<img src="' + esc(absUrl(o.photo_url)) + '" alt="" style="width:38px;height:38px;border-radius:50%;object-fit:cover;background:#EEF2F7;flex-shrink:0;">'
                : '<div style="width:38px;height:38px;border-radius:50%;flex-shrink:0;background:' + cardColour(o.name || '?') + ';color:#fff;font-weight:700;display:flex;align-items:center;justify-content:center;">' + esc((o.name || '?').charAt(0).toUpperCase()) + '</div>')
            : '<div style="width:38px;height:38px;border-radius:50%;flex-shrink:0;background:#E2E8F0;display:flex;align-items:center;justify-content:center;font-size:18px;">🏢</div>';
          return '<div style="margin:2px 0 16px;padding:12px 14px;background:#F8FAFC;border:1px solid #E5E7EB;border-radius:10px;">'
            + '<div style="font-size:11px;font-weight:700;color:#64748B;text-transform:uppercase;letter-spacing:.5px;margin-bottom:8px;">👤 Agency owner</div>'
            + '<div style="display:flex;align-items:center;gap:10px;">' + avatar
            + '<div style="min-width:0;">'
            + '<div style="font-weight:700;color:#0F172A;">' + esc(o.name || 'No owner assigned') + (roleLabel ? ' <span style="font-weight:500;color:#64748B;font-size:12px;">(' + roleLabel + ')</span>' : '') + '</div>'
            + (line2 ? '<div style="font-size:12.5px;color:#64748B;word-break:break-word;">' + esc(line2) + '</div>' : '')
            + (contactLine ? '<div style="font-size:11.5px;color:#94A3B8;word-break:break-word;margin-top:2px;">Agency contact: ' + esc(contactLine) + '</div>' : '')
            + '</div></div></div>';
        })(agency.owner) : ''}
        <div style="margin-bottom:14px;">
          <label style="display:block;font-size:13px;font-weight:700;color:#374151;margin-bottom:6px;">Facilities are called</label>
          <select id="kt-ea-term" style="width:100%;padding:9px 12px;border:1px solid #D1D5DB;border-radius:8px;font-size:14px;box-sizing:border-box;">
            <option value="centre">Centres</option>
            <option value="room">Rooms</option>
            <option value="provider">Providers</option>
          </select>
          <div style="font-size:11px;color:#64748B;margin-top:4px;">Switches the word used throughout the portal — Centres, Rooms, or Providers. Applies instantly.</div>
        </div>
        <div style="margin-bottom:14px;">
          <label style="display:block;font-size:13px;font-weight:700;color:#374151;margin-bottom:6px;">Country</label>
          <select id="kt-ea-country" style="width:100%;padding:9px 12px;border:1px solid #D1D5DB;border-radius:8px;font-size:14px;box-sizing:border-box;">
            <option value="CA">Canada</option>
            <option value="US">United States</option>
            <option value="GB">United Kingdom</option>
            <option value="AU">Australia</option>
            <option value="NZ">New Zealand</option>
            <option value="IE">Ireland</option>
          </select>
          <div style="font-size:11px;color:#64748B;margin-top:4px;">Sets currency, locale, compliance pack, and address fields — e.g. <strong>State / ZIP code</strong> (US) vs <strong>Province / Postal code</strong> (Canada).</div>
        </div>
        <div style="background:#F9FAFB;border:1px solid #EEF0F2;border-radius:8px;padding:12px;font-size:12px;color:#6B7280;">
          Logo, colours, business address, bank details, and privacy / terms links live under <strong>Branding</strong>.
          <button id="kt-ea-branding" style="margin-top:8px;display:block;background:white;color:#1F6080;border:1px solid #1F6080;border-radius:7px;padding:6px 12px;font-weight:600;cursor:pointer;font-size:12px;">Open Branding →</button>
        </div>
        <div id="kt-ea-msg" style="margin-top:12px;font-size:13px;"></div>
        <div style="display:flex;justify-content:flex-end;gap:8px;margin-top:16px;">
          <button id="kt-ea-cancel" style="background:white;color:#374151;border:1px solid #D1D5DB;border-radius:8px;padding:9px 18px;font-weight:700;cursor:pointer;">Cancel</button>
          <button id="kt-ea-save" style="background:#1F6080;color:white;border:none;border-radius:8px;padding:9px 18px;font-weight:700;cursor:pointer;">Save changes</button>
        </div>
      </div>`;
    document.body.appendChild(overlay);

    const close = () => overlay.remove();
    modal.querySelector('#kt-ea-close').addEventListener('click', close);
    modal.querySelector('#kt-ea-cancel').addEventListener('click', close);
    overlay.addEventListener('click', (e) => { if (e.target === overlay) close(); });
    modal.querySelector('#kt-ea-branding').addEventListener('click', () => { close(); window.location.hash = '#admin-branding'; });

    const termSel = modal.querySelector('#kt-ea-term');
    if (termSel) {
      try { termSel.value = sessionStorage.getItem('kt_centre_term') || 'centre'; } catch (e) {}
      termSel.addEventListener('change', () => {
        const val = termSel.value;
        Api.post('/admin/centre-term', { term: val }).then(() => {
          if (window.KT && KT.setCentreTerm) KT.setCentreTerm(val);
          if (Dom.toast) Dom.toast('Terminology updated — "' + ({ room: 'Rooms', provider: 'Providers' }[val] || 'Centres') + '"', 'success');
        }).catch((e) => { if (Dom.toast) Dom.toast('Could not update: ' + (e.message || 'error'), 'error'); });
      });
    }

    const countrySel = modal.querySelector('#kt-ea-country');
    if (countrySel) {
      Api.get('/admin/country').then((r) => { const c = r && (r.country || (r.data && r.data.country)); if (c) countrySel.value = c; }).catch(() => {});
      countrySel.addEventListener('change', () => {
        const val = countrySel.value;
        Api.patch('/admin/country', { country: val }).then(() => {
          if (window.KT && KT.setCountry) KT.setCountry(val);
          if (Dom.toast) Dom.toast('Country updated — address fields adjusted', 'success');
        }).catch((e) => { if (Dom.toast) Dom.toast('Could not update: ' + (e.message || 'error'), 'error'); });
      });
    }

    modal.querySelector('#kt-ea-save').addEventListener('click', async () => {
      const msg = modal.querySelector('#kt-ea-msg');
      const next = {
        name: modal.querySelector('#kt-ea-name').value.trim(),
        contact_email: modal.querySelector('#kt-ea-email').value.trim(),
        contact_phone: modal.querySelector('#kt-ea-phone').value.trim(),
        subdomain: modal.querySelector('#kt-ea-subdomain').value.trim().toLowerCase(),
      };
      if (!next.name) { msg.style.color = '#DC2626'; msg.textContent = 'Agency name is required.'; return; }
      if (next.subdomain && !/^[a-z0-9-]+$/.test(next.subdomain)) {
        msg.style.color = '#DC2626'; msg.textContent = 'Subdomain may only contain lowercase letters, numbers, and hyphens.'; return;
      }
      // Only send fields the user actually changed — avoids clobbering values we
      // could not prefill (e.g. contact_phone).
      const payload = {};
      if (next.name !== (rec.name || '')) payload.name = next.name;
      if (next.contact_email !== (rec.contact_email || '')) payload.contact_email = next.contact_email || null;
      if (next.contact_phone !== (rec.contact_phone || '')) payload.contact_phone = next.contact_phone || null;
      if (next.subdomain !== (rec.subdomain || '')) payload.subdomain = next.subdomain || null;
      if (Object.keys(payload).length === 0) { close(); return; }

      const saveBtn = modal.querySelector('#kt-ea-save');
      saveBtn.disabled = true; saveBtn.textContent = 'Saving…';
      msg.style.color = '#6B7280'; msg.textContent = 'Saving…';
      try {
        await Api.patch('/agencies/' + agencyId, payload);
        if (Dom && Dom.toast) Dom.toast('Agency updated', 'success');
        close();
        renderAgencyDashboard(main);
      } catch (e) {
        msg.style.color = '#DC2626'; msg.textContent = (e && e.message) || 'Save failed.';
        saveBtn.disabled = false; saveBtn.textContent = 'Save changes';
      }
    });
  }

  // Expose + register
  window.KT = window.KT || {};
  window.KT.renderAgencyDashboard = renderAgencyDashboard;
  Shell.registerScreen('agency_admin:dashboard', renderAgencyDashboard);
  Shell.registerScreen('agency_admin:centres',   renderAgencyDashboard);
})(window);
