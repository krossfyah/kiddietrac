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
      grid.innerHTML = '<div style="grid-column:1/-1;padding:32px;text-align:center;background:var(--kt-surface);border:1px dashed var(--kt-border);border-radius:14px;color:var(--kt-text-muted);">No widgets enabled. Click <b>+ Add widget</b> to pick from 8 insight cards.</div>';
      return;
    }
    enabled.forEach(id => {
      const def = WIDGET_DEFS[id];
      if (!def) return;
      const card = def.build(ctx) || document.createElement('div');
      // Hover-revealed remove button
      const card_wrap = document.createElement('div');
      card_wrap.style.cssText = 'position:relative;';
      card.style.height = '100%';
      const removeBtn = document.createElement('button');
      removeBtn.textContent = '×';
      removeBtn.title = 'Remove widget';
      removeBtn.style.cssText = 'position:absolute;top:8px;right:8px;width:24px;height:24px;border-radius:6px;background:rgba(15,23,42,0.06);color:#475569;border:none;cursor:pointer;font-size:16px;line-height:1;display:flex;align-items:center;justify-content:center;opacity:0;transition:opacity 120ms ease;';
      card_wrap.addEventListener('mouseenter', () => removeBtn.style.opacity = '1');
      card_wrap.addEventListener('mouseleave', () => removeBtn.style.opacity = '0');
      removeBtn.addEventListener('click', (e) => {
        e.stopPropagation();
        const updated = getEnabledWidgets().filter(x => x !== id);
        saveEnabledWidgets(updated);
        renderWidgetsGrid(section, ctx);
      });
      card_wrap.appendChild(card);
      card_wrap.appendChild(removeBtn);
      grid.appendChild(card_wrap);
    });
  }
  function openWidgetPicker(section, ctx) {
    const enabled = getEnabledWidgets();
    const available = Object.keys(WIDGET_DEFS).filter(id => !enabled.includes(id));
    if (!available.length) {
      window.KT.Shell.Modal.open({
        title: 'All widgets are already on your dashboard',
        body: Dom.el('p', {}, 'Hover any widget and click × to remove it first.'),
        actions: [{ label: 'Got it', style: 'btn-primary' }],
      });
      return;
    }
    const body = document.createElement('div');
    body.style.cssText = 'display:grid;gap:10px;';
    available.forEach(id => {
      const row = document.createElement('button');
      row.type = 'button';
      row.style.cssText = 'display:flex;justify-content:space-between;align-items:center;padding:14px 16px;background:white;border:1.5px solid #E2E8F0;border-radius:10px;cursor:pointer;text-align:left;font-family:inherit;';
      row.innerHTML = '<div><div style="font-weight:700;font-size:14px;color:#0F172A;">' + esc(WIDGET_DEFS[id].title) + '</div><div style="font-size:12px;color:#64748B;margin-top:2px;">id: ' + id + '</div></div>' +
        '<span style="padding:6px 12px;background:#1F6080;color:white;border-radius:6px;font-size:12px;font-weight:600;">Add</span>';
      row.addEventListener('click', () => {
        const updated = getEnabledWidgets();
        if (!updated.includes(id)) updated.push(id);
        saveEnabledWidgets(updated);
        window.KT.Shell.Modal.close();
        // Re-fetch context-free widgets that don't need data don't need re-fetch; but
        // the simplest is to re-render the whole grid using the latest ctx.
        // Re-render: we still have the most recent ctx from the closing closure.
        // Best path: trigger a small re-render via the section's data.
        renderWidgetsGrid(section, ctx);
      });
      body.appendChild(row);
    });
    window.KT.Shell.Modal.open({
      title: 'Add widget',
      body: body,
      actions: [{ label: 'Done', style: 'btn-secondary' }],
    });
  }

  // ── New widget builders ──
  function widgetCentreOccupancy(data) {
    const centres = (data && data.centres) || [];
    if (!centres.length) return widgetCard('Centre occupancy', '', '<div style="color:#94A3B8;flex:1;display:flex;align-items:center;">No centres yet.</div>');
    const rows = centres.slice(0, 6).map(c => {
      const cap = c.license_capacity || 0;
      const enrolled = c.enrolled || 0;
      const pct = cap ? Math.round((enrolled / cap) * 100) : 0;
      const color = pct >= 90 ? '#DC2626' : pct >= 70 ? '#F59E0B' : '#16A34A';
      return '<div style="margin-bottom:8px;">' +
        '<div style="display:flex;justify-content:space-between;font-size:12px;color:#475569;margin-bottom:3px;">' +
          '<span style="font-weight:600;">' + esc(c.name) + '</span><span style="color:#94A3B8;">' + enrolled + ' / ' + cap + '</span>' +
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
    if (!events.length) return widgetCard('Activity feed', '', '<div style="color:#94A3B8;flex:1;display:flex;align-items:center;">No recent activity.</div>');
    const rows = events.slice(0, 5).map(a => {
      return '<div style="padding:8px 0;border-bottom:1px solid #F1F5F9;font-size:12px;">' +
        '<div style="color:#0F172A;"><b>' + esc(a.actor) + '</b> <span style="color:#64748B;">' + esc(a.action) + '</span></div>' +
        '<div style="color:#94A3B8;font-size:11px;margin-top:2px;">' + esc(a.display_time) + (a.centre_name ? ' · ' + esc(a.centre_name) : '') + '</div>' +
      '</div>';
    }).join('');
    return widgetCard('Activity feed', 'Latest 5 events', rows + '<div style="margin-top:auto;text-align:center;padding-top:10px;"><a href="#admin-billing" style="color:#1F6080;font-size:12px;font-weight:600;">See all activity →</a></div>');
  }

  // v22p3.6: insight-widget builders
  function widgetCard(title, subtitle, contentHtml) {
    const card = document.createElement('div');
    card.style.cssText = 'background:white;border-radius:14px;padding:18px;box-shadow:0 1px 4px rgba(0,0,0,0.05);min-height:160px;display:flex;flex-direction:column;';
    card.innerHTML =
      '<div style="font-size:11px;font-weight:800;color:#6B7280;letter-spacing:1px;text-transform:uppercase;margin-bottom:4px;">' + esc(title) + '</div>' +
      (subtitle ? '<div style="font-size:13px;color:#94A3B8;margin-bottom:10px;">' + esc(subtitle) + '</div>' : '') +
      contentHtml;
    return card;
  }

  function widgetMrrSparkline(mrr) {
    if (!mrr || !mrr.mrr_history_12mo) {
      return widgetCard('Recurring revenue', 'No data yet', '<div style="color:#94A3B8;font-size:14px;flex:1;display:flex;align-items:center;">No MRR data available.</div>');
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
      '<span style="font-size:11px;color:#94A3B8;font-weight:600;margin-left:4px;">' + (mrr.currency || 'CAD') + '/mo</span></div>' +
      '<div style="font-size:12px;color:' + trendColor + ';font-weight:700;margin-top:4px;">' + (delta >= 0 ? '▲' : '▼') + ' ' + Math.abs(deltaPct) + '% vs last month</div>';
    return widgetCard('Recurring revenue', 'Trailing 12 months', big + sparkSvg);
  }

  function widgetArrAgencies(mrr) {
    if (!mrr) return widgetCard('ARR & agencies', '', '<div style="color:#94A3B8;flex:1;display:flex;align-items:center;">No data.</div>');
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
      '<span style="font-size:11px;color:#94A3B8;font-weight:600;margin-left:4px;">ARR</span></div>' +
      '<div style="font-size:12px;color:#475569;margin:6px 0 12px;">' + total + ' agency' + (total === 1 ? '' : 'ies') + ' · ARPU ' + money(mrr.arpu_cents || 0) + '</div>' +
      bar('Active',    status.active || 0,    '#16A34A') +
      bar('Trial',     status.trial || 0,     '#F59E0B') +
      bar('Past due',  status.past_due || 0,  '#DC2626') +
      bar('Cancelled', status.cancelled || 0, '#9CA3AF');
    return widgetCard('Annualised revenue', 'By status', html);
  }

  function widgetUsersByRole(an) {
    if (!an || !an.users_by_role) {
      return widgetCard('Users by role', '', '<div style="color:#94A3B8;flex:1;display:flex;align-items:center;">No data.</div>');
    }
    const roles = an.users_by_role;
    const labels = { agency_admin: 'Admins', centre_director: 'Directors', educator: 'Educators', guardian: 'Parents', auditor: 'Auditors' };
    const colors = { agency_admin: '#1F6080', centre_director: '#0891B2', educator: '#8EC73C', guardian: '#F59E0B', auditor: '#7C3AED' };
    const total = Object.values(roles).reduce((a, b) => a + b, 0);
    if (!total) return widgetCard('Users by role', '', '<div style="color:#94A3B8;flex:1;display:flex;align-items:center;">No users yet.</div>');
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
      '<div style="font-size:11px;color:#94A3B8;margin-top:auto;padding-top:10px;border-top:1px solid #F1F5F9;">Outstanding receivables: $' + ((tm.outstanding || 0).toLocaleString()) + '</div>';
    return widgetCard('Enrollment & revenue', 'Trailing 30 days', html);
  }

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
          <div style="font-size:12px;color:var(--kt-text-faint);margin-top:2px;">Live · pick what you want on this dashboard</div>
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
      centresSection.querySelector('#kt-add-centre-empty')?.addEventListener('click', () => { window.location.href = '/signup.html'; });
    } else {
      const grid = document.createElement('div');
      grid.style.cssText = 'display:grid; grid-template-columns:repeat(auto-fill,minmax(340px,1fr)); gap:16px;';
      data.centres.forEach(c => {
        const breaches = c.rooms_in_breach || 0;
        const cap = c.capacity_pct || 0;
        const fillClass = cap > 90 ? 'danger' : cap > 70 ? 'warn' : '';
        // v22p3.4: render the centre logo + brand_color on the card. Falls back
        // to the initial-in-a-tile when no logo is uploaded.
        const brand = c.brand_color || '#1F6080';
        const logoBlock = c.logo_url
          ? `<img src="${esc(absUrl(c.logo_url))}" alt="${esc(c.name)}" style="width:44px;height:44px;border-radius:10px;object-fit:contain;background:white;box-shadow:0 1px 3px rgba(0,0,0,.08);">`
          : `<div style="width:44px;height:44px;border-radius:10px;background:${brand};color:white;display:flex;align-items:center;justify-content:center;font-weight:800;font-size:18px;">${esc((c.name || '?').charAt(0).toUpperCase())}</div>`;
        grid.insertAdjacentHTML('beforeend', `
          <div class="centre-card-v17" style="border-left:4px solid ${brand};">
            <div class="head">
              <div style="display:flex;align-items:center;gap:12px;">
                ${logoBlock}
                <div>
                  <div class="name">${esc(c.name)}</div>
                  ${c.tagline
                    ? `<div style="font-size:12px;color:var(--kt-text-muted);font-weight:500;margin-top:1px;">${esc(c.tagline)}</div>`
                    : (c.city ? `<div class="city">${esc(c.city)}</div>` : '')}
                </div>
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

      // Wire centre buttons. v22p4.1: previously this set kt_centre_id and
      // reloaded — but agency_admin:dashboard is the SAME screen so the page
      // visibly didn't change. Now we route to the admin Centres tab and
      // stash an auto-open hint so the centre's edit modal opens on arrival.
      centresSection.querySelectorAll('.manage-btn').forEach(btn => {
        btn.addEventListener('click', () => {
          const id = btn.getAttribute('data-centre-id');
          sessionStorage.setItem('kt_centre_id', id);
          sessionStorage.setItem('kt_admin_open_centre', id);
          window.location.hash = 'admin-centres';
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
