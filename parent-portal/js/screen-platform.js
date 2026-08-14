/* ============================================================
   KIDDIETRAC v22p22 — Platform-admin screens
   Hash: #platform-overview, #platform-agencies
   Registered for agency_admin (which platform_admin shares as
   primary_role via existing assignments) — server gates via
   role:platform_admin middleware on the actual API.
   ============================================================ */
(function (window) {
  'use strict';
  if (!window.KT) return;
  var KT = window.KT;
  var Api = KT.Api;
  var Dom = KT.Dom;
  var Shell = KT.Shell;

  function esc(s) {
    return s == null ? '' : String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;')
      .replace(/>/g, '&gt;').replace(/"/g, '&quot;');
  }
  function fmtMoney(cents) {
    return '$' + (cents / 100).toLocaleString('en-CA', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
  }
  function fmtDate(d) {
    if (!d) return '—';
    try { return new Date(d).toLocaleDateString('en-CA', { year: 'numeric', month: 'short', day: 'numeric' }); }
    catch (e) { return d; }
  }
  function statusPill(s) {
    var map = {
      active:    { bg: '#16A34A', fg: 'white',   t: 'ACTIVE' },
      trial:     { bg: '#FEF3C7', fg: '#92400E', t: 'TRIAL' },
      past_due:  { bg: '#FED7AA', fg: '#9A3412', t: 'PAST DUE' },
      suspended: { bg: '#FEE2E2', fg: '#991B1B', t: 'SUSPENDED' },
    };
    var m = map[s] || { bg: '#F3F4F6', fg: '#6B7280', t: (s || '').toUpperCase() };
    return '<span style="padding:3px 10px;border-radius:999px;background:' + m.bg + ';color:' + m.fg + ';font-size:10px;font-weight:700;letter-spacing:0.5px;">' + m.t + '</span>';
  }

  // ── v22p32: widgets for the platform overview ────────────────────
  function cardShell(title, subtitle) {
    var box = Dom.el('div', { style: 'background:white;border-radius:14px;padding:20px 24px;box-shadow:0 1px 3px rgba(0,0,0,.06);min-height:240px;' });
    box.appendChild(Dom.el('h3', { style: 'margin:0 0 4px;font-size:14px;color:#6B7280;letter-spacing:0.5px;text-transform:uppercase;' }, title));
    if (subtitle) box.appendChild(Dom.el('div', { style: 'font-size:12px;color:#64748B;margin-bottom:14px;' }, subtitle));
    return box;
  }

  // Compact bar chart using divs — no chart library needed
  function renderBarChart(rows, colorFn, formatFn) {
    var max = 0;
    rows.forEach(function (r) { if (r.value > max) max = r.value; });
    if (max === 0) max = 1;
    var wrap = Dom.el('div', { style: 'display:flex;align-items:flex-end;gap:10px;height:140px;padding:8px 0;' });
    rows.forEach(function (r) {
      var col = Dom.el('div', { style: 'flex:1;display:flex;flex-direction:column;align-items:stretch;height:100%;' });
      var spacer = Dom.el('div', { style: 'flex:1;display:flex;align-items:flex-end;' });
      var heightPct = Math.max((r.value / max) * 100, 2);
      var bar = Dom.el('div', {
        style: 'width:100%;height:0%;background:' + colorFn(r) + ';border-radius:6px 6px 0 0;position:relative;transition:height .9s cubic-bezier(.22,1,.36,1), opacity .15s;',
        title: r.label + ': ' + (formatFn ? formatFn(r.value) : r.value),
      });
      // Grow from the baseline once painted (a gentle rise-up animation).
      requestAnimationFrame(function () { requestAnimationFrame(function () { bar.style.height = heightPct + '%'; }); });
      // Value label above bar (only if there's room)
      var lbl = Dom.el('div', { style: 'font-size:10px;font-weight:700;color:#374151;text-align:center;padding:2px;margin-top:-18px;' }, formatFn ? formatFn(r.value) : String(r.value));
      bar.appendChild(lbl);
      spacer.appendChild(bar);
      col.appendChild(spacer);
      col.appendChild(Dom.el('div', { style: 'font-size:11px;color:#6B7280;text-align:center;margin-top:6px;' }, r.label));
      wrap.appendChild(col);
    });
    return wrap;
  }

  function renderMrrTrendCard(mrrTrend) {
    var box = cardShell('MRR trend', 'Last 6 months · Canadian dollars');
    var bars = mrrTrend.map(function (m) { return { label: m.label, value: m.mrr_cents / 100 }; });
    box.appendChild(renderBarChart(
      bars,
      function () { return 'linear-gradient(180deg,#16A34A 0%,#15803D 100%)'; },
      function (v) { return '$' + Math.round(v).toLocaleString(); }
    ));
    return box;
  }

  function renderAgencyGrowthCard(growth) {
    var box = cardShell('Agency growth', 'Signups vs cancellations · last 6 months');
    var bars = growth.map(function (g) { return { label: g.label, value: g.signups, cancelled: g.cancelled }; });
    box.appendChild(renderBarChart(
      bars,
      function (r) {
        if (r.cancelled > r.value) return 'linear-gradient(180deg,#DC2626 0%,#991B1B 100%)';
        if (r.cancelled > 0) return 'linear-gradient(180deg,#F59E0B 0%,#B45309 100%)';
        return 'linear-gradient(180deg,#7C3AED 0%,#5B21B6 100%)';
      },
      null
    ));
    // Legend
    var legend = Dom.el('div', { style: 'display:flex;gap:14px;font-size:11px;color:#6B7280;margin-top:8px;flex-wrap:wrap;' });
    legend.innerHTML =
      '<span><span style="display:inline-block;width:10px;height:10px;background:#7C3AED;border-radius:2px;vertical-align:middle;margin-right:5px;"></span>Net positive month</span>' +
      '<span><span style="display:inline-block;width:10px;height:10px;background:#F59E0B;border-radius:2px;vertical-align:middle;margin-right:5px;"></span>Had cancellations</span>' +
      '<span><span style="display:inline-block;width:10px;height:10px;background:#DC2626;border-radius:2px;vertical-align:middle;margin-right:5px;"></span>Net negative</span>';
    box.appendChild(legend);
    return box;
  }

  function renderTopAgenciesCard(rows) {
    var box = cardShell('Top agencies by enrolment', 'Five largest by children on roll');
    if (!rows.length) { box.appendChild(Dom.el('div', { style: 'color:#64748B;font-style:italic;padding:20px 0;' }, 'No agencies yet')); return box; }
    var list = Dom.el('div', {});
    var maxChildren = Math.max.apply(null, rows.map(function (r) { return r.children; })) || 1;
    rows.forEach(function (a) {
      var pct = Math.max((a.children / maxChildren) * 100, 4);
      var row = Dom.el('div', { style: 'display:flex;align-items:center;gap:12px;padding:8px 0;border-bottom:1px solid #F3F4F6;' });
      var swatch = Dom.el('div', { style: 'width:8px;height:36px;border-radius:4px;background:' + (a.accent || '#1F6080') + ';flex-shrink:0;' });
      var body = Dom.el('div', { style: 'flex:1;min-width:0;' });
      var line1 = Dom.el('div', { style: 'display:flex;align-items:center;justify-content:space-between;gap:8px;margin-bottom:4px;' });
      line1.appendChild(Dom.el('div', { style: 'font-weight:600;font-size:14px;color:#111827;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;' }, a.name));
      line1.appendChild(Dom.el('div', { style: 'font-weight:700;font-size:14px;color:#1F6080;flex-shrink:0;' }, a.children + ' kids'));
      body.appendChild(line1);
      var track = Dom.el('div', { style: 'height:6px;border-radius:3px;background:#F3F4F6;overflow:hidden;' });
      var fill = Dom.el('div', { style: 'height:100%;width:0%;background:' + (a.accent || '#1F6080') + ';transition:width .9s cubic-bezier(.22,1,.36,1);' });
      track.appendChild(fill);
      requestAnimationFrame(function () { requestAnimationFrame(function () { fill.style.width = pct + '%'; }); });
      body.appendChild(track);
      row.appendChild(swatch);
      row.appendChild(body);
      list.appendChild(row);
    });
    box.appendChild(list);
    return box;
  }

  // v22p34: SaaS business metrics row — ARR, ARPA, churn, LTV, growth %,
  // capacity utilisation, net revenue retention. Numbers come pre-computed
  // from PlatformController::businessMetrics so the JS just paints tiles.
  function renderBusinessMetricsSection(bm) {
    var section = Dom.el('div', { style: 'margin-top:18px;background:linear-gradient(135deg,#0F172A 0%,#1F2937 60%,#16637A 100%);border-radius:16px;padding:24px 28px;color:white;box-shadow:0 6px 18px rgba(15,23,42,.18);' });
    // v22p35: previous version passed an HTML string as Dom.el's third argument,
    // which text-escapes the content — so users saw the literal <h3> tag.
    // Build the heading via innerHTML on a real element instead.
    var head = Dom.el('div', { style: 'display:flex;align-items:center;justify-content:space-between;margin-bottom:18px;flex-wrap:wrap;gap:8px;' });
    var heading = Dom.el('div');
    heading.innerHTML =
      '<h3 style="margin:0;font-size:18px;letter-spacing:0.3px;">📊 Business metrics</h3>' +
      '<div style="font-size:12px;color:rgba(255,255,255,.65);margin-top:2px;">SaaS KPIs · CAD · derived from current MRR + trend</div>';
    head.appendChild(heading);
    section.appendChild(head);

    function tile(label, value, hint, accent) {
      var t = Dom.el('div', { style: 'background:rgba(255,255,255,.06);border:1px solid rgba(255,255,255,.08);border-radius:12px;padding:14px 16px;border-left:4px solid ' + accent + ';' });
      t.appendChild(Dom.el('div', { style: 'font-size:11px;font-weight:700;letter-spacing:1px;color:rgba(255,255,255,.6);text-transform:uppercase;' }, label));
      t.appendChild(Dom.el('div', { style: 'font-size:26px;font-weight:800;color:white;line-height:1.1;margin:4px 0 2px;' }, String(value)));
      if (hint) t.appendChild(Dom.el('div', { style: 'font-size:12px;color:rgba(255,255,255,.65);' }, hint));
      return t;
    }
    function money(n) { return '$' + Number(n || 0).toLocaleString('en-CA', { maximumFractionDigits: 0 }); }
    function moneyDecimals(n) { return '$' + Number(n || 0).toLocaleString('en-CA', { minimumFractionDigits: 2, maximumFractionDigits: 2 }); }
    function pct(n) { return (Number(n) >= 0 ? '+' : '') + Number(n || 0).toFixed(1) + '%'; }

    var grid = Dom.el('div', { style: 'display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:12px;' });
    grid.appendChild(tile('ARR', money(bm.arr_dollars), 'Annualised recurring revenue', '#16A34A'));
    grid.appendChild(tile('ARPA', moneyDecimals(bm.arpa_dollars), 'Per active agency / month', '#8EC73C'));
    grid.appendChild(tile('ARPU', moneyDecimals(bm.arpu_dollars), 'Per enrolled child / month', '#FF8A65'));
    grid.appendChild(tile('MRR growth', pct(bm.mrr_growth_pct), 'Month over month · ' + moneyDecimals(bm.mrr_growth_abs_dollars), bm.mrr_growth_pct >= 0 ? '#16A34A' : '#DC2626'));
    grid.appendChild(tile('Churn (30d)', (bm.churn_pct_30d || 0).toFixed(1) + '%', 'Agencies lost vs starting count', bm.churn_pct_30d >= 5 ? '#DC2626' : '#F59E0B'));
    grid.appendChild(tile('LTV', money(bm.ltv_dollars), 'Est. ' + bm.ltv_months + ' month lifetime', '#7C3AED'));
    grid.appendChild(tile('NRR (6m)', (bm.nrr_pct_6m || 0).toFixed(1) + '%', '>100% = net expansion', bm.nrr_pct_6m >= 100 ? '#16A34A' : '#F59E0B'));
    grid.appendChild(tile('Capacity', (bm.capacity_pct || 0).toFixed(1) + '%', bm.capacity_filled + ' / ' + bm.capacity_licensed + ' licensed seats', bm.capacity_pct >= 80 ? '#16A34A' : '#F59E0B'));
    section.appendChild(grid);

    return section;
  }

  // Platform performance — operational health + usage/engagement (light card).
  function renderPlatformPerformanceSection(pp) {
    var e = pp.email || {}, u = pp.usage || {}, s = pp.system || {};
    var capPct = (u.total_agencies || 0) > 0 ? Math.round(((u.active_agencies_30d || 0) / u.total_agencies) * 100) : 0;

    // Crisp line icons (feather-style), stroked white on the gradient faces.
    var ICONS = {
      mail: '<rect x="3" y="5" width="18" height="14" rx="2"/><path d="m3 7 9 6 9-6"/>',
      eye: '<path d="M2 12s3.6-7 10-7 10 7 10 7-3.6 7-10 7-10-7-10-7Z"/><circle cx="12" cy="12" r="3"/>',
      alert: '<path d="M10.3 3.9 1.8 18a2 2 0 0 0 1.7 3h16.9a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0Z"/><line x1="12" y1="9" x2="12" y2="13.5"/><line x1="12" y1="17" x2="12.01" y2="17"/>',
      check: '<path d="M22 11.1V12a10 10 0 1 1-5.9-9.1"/><path d="m8.5 11 3 3L22 3.5"/>',
      belloff: '<path d="M6.3 6.3A6 6 0 0 0 6 8c0 7-3 9-3 9h14"/><path d="M18 8a6 6 0 0 0-9.3-5"/><path d="M10.3 21a1.9 1.9 0 0 0 3.4 0"/><line x1="2" y1="2" x2="22" y2="22"/>',
      database: '<ellipse cx="12" cy="5" rx="9" ry="3"/><path d="M21 12c0 1.7-4 3-9 3s-9-1.3-9-3"/><path d="M3 5v14c0 1.7 4 3 9 3s9-1.3 9-3V5"/>',
      zap: '<path d="M13 2 3 14h9l-1 8 10-12h-9l1-8Z"/>',
      key: '<circle cx="7.5" cy="15.5" r="4.5"/><path d="m11 12 9.5-9.5"/><path d="m15.5 7.5 3 3 2.5-2.5-3-3"/>',
      trending: '<polyline points="22 7 13.5 15.5 8.5 10.5 2 17"/><polyline points="16 7 22 7 22 13"/>',
      users: '<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.9"/><path d="M16 3.1a4 4 0 0 1 0 7.8"/>',
      building: '<rect x="4" y="2" width="16" height="20" rx="2"/><path d="M9 22v-4h6v4"/><path d="M8 6h.01M16 6h.01M8 10h.01M16 10h.01M8 14h.01M16 14h.01"/>',
      moon: '<path d="M12 3a6.4 6.4 0 0 0 9 9 9 9 0 1 1-9-9Z"/>',
      star: '<path d="m12 2 3 6.9 7.5.6-5.7 5 1.7 7.4L12 18l-6.5 3.9 1.7-7.4L1.5 9.5 9 8.9Z"/>',
      pulse: '<path d="M22 12h-4l-3 8-6-16-3 8H2"/>'
    };
    function svg(name, size, color) {
      return '<svg width="' + (size || 22) + '" height="' + (size || 22) + '" viewBox="0 0 24 24" fill="none" stroke="' + (color || '#fff') + '" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">' + (ICONS[name] || '') + '</svg>';
    }

    var section = Dom.el('div', { style: 'position:relative;margin-top:14px;border-radius:16px;padding:18px 20px 20px;'
      + 'background:#FBFCFE;border:1px solid #E8EEF6;box-shadow:0 1px 4px rgba(15,23,42,.03);' });

    var head = Dom.el('div', { style: 'display:flex;align-items:center;gap:11px;margin-bottom:2px;' });
    head.innerHTML = '<span style="width:34px;height:34px;border-radius:10px;background:linear-gradient(135deg,#22D3EE,#6366F1);display:inline-flex;align-items:center;justify-content:center;box-shadow:0 9px 18px -7px #6366F1;">' + svg('pulse', 19) + '</span>'
      + '<span><span style="display:block;font-size:19px;font-weight:800;color:#0F172A;letter-spacing:-.3px;">Platform performance</span>'
      + '<span style="display:block;font-size:12.5px;color:#64748B;margin-top:2px;">Operational health &amp; usage · updated live</span></span>';
    section.appendChild(head);

    function groupLabel(title, sub) {
      var g = Dom.el('div', { style: 'display:flex;align-items:center;gap:12px;margin:20px 0 13px;' });
      g.innerHTML = '<span style="font-size:11px;font-weight:800;letter-spacing:1.2px;color:#475569;text-transform:uppercase;">' + title + '</span>'
        + '<span style="flex:1;height:1px;background:linear-gradient(90deg,#D8E2EE,transparent);"></span>'
        + (sub ? '<span style="font-size:11px;color:#94A3B8;">' + sub + '</span>' : '');
      return g;
    }
    function grid() { return Dom.el('div', { style: 'display:grid;grid-template-columns:repeat(auto-fill,minmax(158px,1fr));gap:10px;' }); }

    // o = {icon,label,value,hint,c1,c2, bar?}  — compact, calm KPI cards: white
    // face, a small tinted icon, dark-ink value, and just a thin accent. Tight
    // spacing so more fits without the loud full-colour gradients.
    function tile(o) {
      var accent = o.c1;
      var t = Dom.el('div', { style: 'position:relative;overflow:hidden;background:#fff;border:1px solid #E8EEF6;border-radius:12px;padding:12px 13px 12px;'
        + 'box-shadow:0 1px 2px rgba(15,23,42,.04);transition:box-shadow .15s ease,border-color .15s ease;' });
      // A thin accent rail on the left edge — the only colour on the card.
      t.appendChild(Dom.el('div', { style: 'position:absolute;left:0;top:0;bottom:0;width:3px;background:' + accent + ';' }));
      t.addEventListener('mouseenter', function () { t.style.boxShadow = '0 6px 16px -8px rgba(15,23,42,.22)'; t.style.borderColor = '#D8E2EE'; });
      t.addEventListener('mouseleave', function () { t.style.boxShadow = '0 1px 2px rgba(15,23,42,.04)'; t.style.borderColor = '#E8EEF6'; });

      var top = Dom.el('div', { style: 'display:flex;align-items:center;gap:8px;margin-bottom:9px;' });
      var chip = Dom.el('span', { style: 'width:26px;height:26px;border-radius:8px;background:' + accent + '18;display:inline-flex;align-items:center;justify-content:center;flex:0 0 auto;' });
      chip.innerHTML = svg(o.icon, 15, accent);
      top.appendChild(chip);
      top.appendChild(Dom.el('span', { style: 'font-size:10.5px;font-weight:800;letter-spacing:.5px;text-transform:uppercase;color:#94A3B8;' }, o.label));
      t.appendChild(top);

      t.appendChild(Dom.el('div', { style: 'font-size:22px;font-weight:800;line-height:1;letter-spacing:-.4px;color:#0F172A;font-variant-numeric:tabular-nums;' }, String(o.value)));
      if (o.hint) t.appendChild(Dom.el('div', { style: 'font-size:11px;color:#94A3B8;margin-top:4px;' }, o.hint));
      if (typeof o.bar === 'number') {
        var track = Dom.el('div', { style: 'margin-top:9px;height:4px;border-radius:99px;background:#EEF2F7;overflow:hidden;' });
        track.appendChild(Dom.el('div', { style: 'height:100%;border-radius:99px;width:' + Math.max(3, Math.min(100, o.bar)) + '%;background:' + accent + ';' }));
        t.appendChild(track);
      }
      return t;
    }

    section.appendChild(groupLabel('Operational health', 'email · database · API'));
    var g1 = grid();
    g1.appendChild(tile({ icon: 'mail', label: 'Email delivered', value: (e.delivered || 0), hint: 'Last ' + (e.window_days || 7) + ' days', c1: '#2DD4BF', c2: '#059669' }));
    g1.appendChild(tile({ icon: 'eye', label: 'Open rate', value: (e.open_rate_pct || 0) + '%', hint: (e.opened || 0) + ' opened', c1: '#3B82F6', c2: '#4F46E5', bar: (e.open_rate_pct || 0) }));
    var failBad = (e.fail_rate_pct || 0) > 2;
    g1.appendChild(tile({ icon: (failBad ? 'alert' : 'check'), label: 'Failed sends', value: (e.failed || 0), hint: (e.fail_rate_pct || 0) + '% of sends', c1: (failBad ? '#FB7185' : '#34D399'), c2: (failBad ? '#E11D48' : '#059669'), bar: Math.min(100, (e.fail_rate_pct || 0) * 10) }));
    g1.appendChild(tile({ icon: 'belloff', label: 'Suppressed', value: (e.suppressed || 0), hint: 'Held by kill-switch', c1: '#FBBF24', c2: '#EA580C' }));
    g1.appendChild(tile({ icon: 'database', label: 'Database', value: (s.db_size_mb || 0) + ' MB', hint: 'Total size', c1: '#A78BFA', c2: '#7C3AED' }));
    g1.appendChild(tile({ icon: 'zap', label: 'API status', value: (s.api_ok ? 'Online' : 'Down'), hint: 'Responding now', c1: (s.api_ok ? '#22D3EE' : '#FB7185'), c2: (s.api_ok ? '#0891B2' : '#E11D48') }));
    section.appendChild(g1);

    section.appendChild(groupLabel('Usage & engagement', 'logins · active users · agencies'));
    var g2 = grid();
    g2.appendChild(tile({ icon: 'key', label: 'Logins today', value: (u.logins_today || 0), hint: 'All agencies', c1: '#38BDF8', c2: '#2563EB' }));
    g2.appendChild(tile({ icon: 'trending', label: 'Logins (7d)', value: (u.logins_7d || 0), hint: 'Last 7 days', c1: '#818CF8', c2: '#7C3AED' }));
    g2.appendChild(tile({ icon: 'users', label: 'Active users', value: (u.active_users_24h || 0), hint: 'Last 24 hours', c1: '#34D399', c2: '#059669' }));
    g2.appendChild(tile({ icon: 'building', label: 'Active agencies', value: (u.active_agencies_30d || 0) + ' / ' + (u.total_agencies || 0), hint: capPct + '% active (30d)', c1: '#E879F9', c2: '#9333EA', bar: capPct }));
    var dormant = (u.dormant_agencies || 0) > 0;
    g2.appendChild(tile({ icon: (dormant ? 'moon' : 'star'), label: 'Dormant', value: (u.dormant_agencies || 0), hint: 'No activity in 30d', c1: (dormant ? '#FBBF24' : '#34D399'), c2: (dormant ? '#D97706' : '#059669') }));
    section.appendChild(g2);

    return section;
  }

  function renderRecentEventsCard(events) {
    var box = cardShell('Recent platform activity', 'Last 10 events across all agencies');
    if (!events.length) { box.appendChild(Dom.el('div', { style: 'color:#64748B;font-style:italic;padding:20px 0;' }, 'No activity recorded')); return box; }
    var list = Dom.el('div', {});
    var actionIcons = {
      'user.created': '👤', 'user.revived': '♻️', 'user.deleted': '✖',
      'user.updated': '✏', 'centre.created': '🏫', 'centre.updated': '🔧',
      'agency.created': '🏛', 'agency.updated': '🔧', 'agency.suspended': '⏸',
      'agency.resumed': '▶', 'family.created': '👪', 'invoice.created': '💸',
      'invoice.paid': '✅', 'branding.updated': '🎨',
    };
    var verbMap = { post: 'Created', patch: 'Updated', put: 'Updated', 'delete': 'Deleted' };
    var iconFor = function (a) {
      if (actionIcons[a]) return actionIcons[a];
      if (/email\.failed/.test(a)) return '⚠️';
      if (/email/.test(a)) return '📧';
      if (/delete|deleted|destroy/.test(a)) return '🗑️';
      if (/login/.test(a)) return '🔑';
      if (/^post:/.test(a)) return '➕';
      if (/^(patch|put):/.test(a)) return '✏';
      return '•';
    };
    var titleFor = function (a) {
      var m = a.match(/^(post|patch|put|delete):(.+)$/);
      if (m) { return (verbMap[m[1]] || m[1]) + ' · ' + m[2].replace(/^api\/v1\//, '').replace(/\//g, ' › '); }
      return a.replace(/[._]/g, ' ');
    };
    // Precise timestamp — date AND time (was date-only, too coarse).
    var fmtDT = function (t) { try { return new Date(t).toLocaleString(undefined, { month: 'short', day: 'numeric', hour: 'numeric', minute: '2-digit' }); } catch (e) { return fmtDate(t); } };
    events.forEach(function (ev) {
      var row = Dom.el('div', { style: 'display:flex;align-items:flex-start;gap:10px;padding:8px 0;border-bottom:1px solid #F3F4F6;font-size:13px;' });
      row.appendChild(Dom.el('div', { style: 'font-size:18px;width:28px;text-align:center;' }, iconFor(ev.action)));
      var body = Dom.el('div', { style: 'flex:1;min-width:0;' });
      var title = titleFor(ev.action);
      if (ev.detail) title += ' — ' + ev.detail;   // e.g. "Deleted · users — Jane Doe"
      body.appendChild(Dom.el('div', { style: 'color:#111827;font-weight:600;' }, title));
      var meta = 'by ' + ev.actor + (ev.entity_type ? (' · ' + ev.entity_type + (ev.entity_id ? (' #' + ev.entity_id) : '')) : '');
      body.appendChild(Dom.el('div', { style: 'color:#6B7280;font-size:11px;margin-top:2px;' }, meta + ' · ' + fmtDT(ev.created_at)));
      row.appendChild(body);
      list.appendChild(row);
    });
    box.appendChild(list);
    return box;
  }

  // ── Platform Overview ───────────────────────────────────────────
  function renderOverview(container) {
    Dom.clear(container);
    var wrap = Dom.el('div', { style: 'padding:24px;max-width:1800px;margin:0 auto;' });
    container.appendChild(wrap);

    var hero = Dom.el('div', { class: 'kt-hero', style: 'background:linear-gradient(135deg,#7C3AED 0%,#2C8AAC 60%,#16637A 100%);' });
    hero.innerHTML =
      '<div class="kt-hero-greet">🌐 PLATFORM ADMIN</div>' +
      '<h1>Cross-agency overview</h1>' +
      '<div class="kt-hero-sub">Every centre, every family, every dollar — across the entire platform.</div>';
    wrap.appendChild(hero);

    var loading = Dom.el('div', { style: 'padding:40px;text-align:center;color:#6B7280;' }, 'Loading…');
    wrap.appendChild(loading);

    Api.get('/platform/overview').then(function (data) {
      Dom.clear(wrap);
      wrap.appendChild(hero);

      var t = data.totals || {};
      var r = data.recent_30d || {};

      // KPI grid — v23: unified gradient-badge theme (matches every other role's cards)
      var kpis = [
        { label: 'Agencies', value: t.agencies, hint: 'active', accent: '#7C3AED', icon: '🏢' },
        { label: 'Centres',  value: t.centres,  hint: 'across all', accent: '#1F6080', icon: '🏫' },
        { label: 'Children', value: t.children, hint: 'enrolled', accent: '#0D9488', icon: '👶' },
        { label: 'Families', value: t.families, hint: 'on platform', accent: '#EA580C', icon: '👪' },
        { label: 'Staff',    value: t.staff,    hint: 'active users', accent: '#2563EB', icon: '🧑‍🏫' },
        { label: 'MRR',      value: fmtMoney(t.mrr_cents || 0), hint: 'monthly recurring', accent: '#16A34A', icon: '💰' },
        { label: 'Sessions', value: (t.active_sessions_24h || 0), hint: 'active last 24h', accent: '#D97706', icon: '⚡' },
      ];
      var grid = Dom.el('div', { style: 'display:grid;grid-template-columns:repeat(auto-fill,minmax(200px,1fr));gap:14px;margin:18px 0;' });
      kpis.forEach(function (k) {
        var c = Dom.el('div', { class: 'kt-kpi-tile', style: 'position:relative;overflow:hidden;background:color-mix(in srgb, ' + k.accent + ' 8%, #ffffff);border:1px solid rgba(15,23,42,.06);border-radius:16px;padding:16px 16px 14px;' });
        c.innerHTML =
          '<div style="width:40px;height:40px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:20px;margin-bottom:11px;background:linear-gradient(135deg, color-mix(in srgb, ' + k.accent + ' 55%, #ffffff), ' + k.accent + ');box-shadow:0 6px 13px -6px ' + k.accent + ';">' + k.icon + '</div>' +
          '<div style="font-size:30px;font-weight:900;line-height:1;color:color-mix(in srgb, ' + k.accent + ' 80%, #0f172a);">' + esc(String(k.value)) + '</div>' +
          '<div style="font-size:10.5px;font-weight:800;letter-spacing:.6px;text-transform:uppercase;color:color-mix(in srgb, ' + k.accent + ' 80%, #0f172a);opacity:.72;margin-top:9px;">' + esc(k.label) + '</div>' +
          '<div style="font-size:11.5px;color:#64748b;margin-top:2px;">' + esc(k.hint) + '</div>';
        grid.appendChild(c);
      });
      wrap.appendChild(grid);

      // Recent 30d card
      var recent = Dom.el('div', { style: 'background:white;border-radius:14px;padding:20px 24px;box-shadow:0 1px 3px rgba(0,0,0,.06);' });
      recent.innerHTML =
        '<h3 style="margin:0 0 12px;font-size:14px;color:#6B7280;letter-spacing:0.5px;text-transform:uppercase;">Recent 30 days</h3>' +
        '<div style="display:grid;grid-template-columns:repeat(3,1fr);gap:24px;">' +
          '<div><div style="font-size:13px;color:#6B7280;">New agencies</div><div style="font-size:24px;font-weight:800;color:#16A34A;">+' + (r.new_agencies || 0) + '</div></div>' +
          '<div><div style="font-size:13px;color:#6B7280;">Cancellations</div><div style="font-size:24px;font-weight:800;color:#DC2626;">−' + (r.cancelled_agencies || 0) + '</div></div>' +
          '<div><div style="font-size:13px;color:#6B7280;">Net change</div><div style="font-size:24px;font-weight:800;color:#1F6080;">' + (r.net >= 0 ? '+' : '') + (r.net || 0) + '</div></div>' +
        '</div>';
      wrap.appendChild(recent);

      // v22p32: trend widget row — MRR + agency growth side by side
      var trends = Dom.el('div', { style: 'display:grid;grid-template-columns:repeat(auto-fit,minmax(380px,1fr));gap:14px;margin-top:14px;' });
      trends.appendChild(renderMrrTrendCard(data.mrr_trend || []));
      trends.appendChild(renderAgencyGrowthCard(data.agency_growth || []));
      wrap.appendChild(trends);

      // v22p34: SaaS business metrics section
      if (data.business_metrics) {
        wrap.appendChild(renderBusinessMetricsSection(data.business_metrics));
      }

      // Platform performance — operational health + usage/engagement
      if (data.platform_performance) {
        wrap.appendChild(renderPlatformPerformanceSection(data.platform_performance));
      }

      // v22p32: insight row — top agencies (left) + recent events (right)
      var bottom = Dom.el('div', { style: 'display:grid;grid-template-columns:repeat(auto-fit,minmax(380px,1fr));gap:14px;margin-top:14px;' });
      bottom.appendChild(renderTopAgenciesCard(data.top_agencies || []));
      bottom.appendChild(renderRecentEventsCard(data.recent_events || []));
      wrap.appendChild(bottom);

      // Crash reports — recent server errors (5xx) across every agency: who + what.
      var crashSec = Dom.el('div', { style: 'margin-top:14px;' });
      wrap.appendChild(crashSec);
      Api.get('/platform/crash-reports').then(function (cr) { renderCrashReports(crashSec, cr); }).catch(function () { crashSec.remove(); });
    }).catch(function (e) {
      Dom.clear(wrap);
      wrap.appendChild(hero);
      wrap.appendChild(Dom.el('div', { style: 'padding:24px;color:#DC2626;' }, 'Could not load: ' + e.message));
    });
  }

  // ── Platform Agencies ──────────────────────────────────────────
  function renderAgencies(container) {
    Dom.clear(container);
    var wrap = Dom.el('div', { style: 'padding:24px;max-width:1800px;margin:0 auto;' });
    container.appendChild(wrap);

    var hero = Dom.el('div', { class: 'kt-hero', style: 'background:linear-gradient(135deg,#7C3AED 0%,#2C8AAC 60%,#16637A 100%);' });
    hero.innerHTML =
      '<div class="kt-hero-greet">🌐 PLATFORM ADMIN</div>' +
      '<h1>All agencies</h1>' +
      '<div class="kt-hero-sub">Every customer agency on Kiddietrac. Click to set as the active context, or use the platform actions to suspend / resume.</div>';
    wrap.appendChild(hero);

    var bar = Dom.el('div', { style: 'display:flex;justify-content:space-between;align-items:center;margin:16px 0;' });
    bar.appendChild(Dom.el('div', { style: 'color:#6B7280;font-size:13px;' }, 'All tenants'));
    var barRight = Dom.el('div', { style: 'display:flex;gap:8px;' });
    var addBtn = Dom.el('button', { style: 'background:#7C3AED;color:white;border:none;padding:10px 16px;border-radius:8px;font-weight:700;cursor:pointer;font-size:14px;' }, '+ Create agency');
    addBtn.addEventListener('click', function () { showCreateAgencyModal(container); });
    barRight.appendChild(addBtn);
    bar.appendChild(barRight);
    wrap.appendChild(bar);

    var loading = Dom.el('div', { style: 'padding:40px;text-align:center;color:#6B7280;' }, 'Loading…');
    wrap.appendChild(loading);

    Api.get('/platform/agencies').then(function (data) {
      loading.remove();
      var table = Dom.el('table', { style: 'width:100%;background:white;border-radius:12px;overflow:hidden;border-collapse:collapse;box-shadow:0 1px 3px rgba(0,0,0,.04);' });
      table.innerHTML =
        '<thead style="background:#F9FAFB;"><tr>' +
          ['Name', 'Status', 'Plan', 'Centres', 'Families', 'Children', 'Created', 'Actions'].map(function (h) {
            return '<th style="text-align:left;padding:12px 16px;font-size:11px;font-weight:700;color:#6B7280;text-transform:uppercase;letter-spacing:0.5px;">' + h + '</th>';
          }).join('') +
        '</tr></thead>';
      var tbody = Dom.el('tbody');
      (data.agencies || []).forEach(function (a) {
        var tr = Dom.el('tr', { style: 'border-top:1px solid #E5E7EB;' });
        var planLabel = a.plan_code ? (esc(a.plan_code) + ' (' + fmtMoney(a.plan_amount_cents || 0) + ')') : '—';
        tr.innerHTML =
          '<td style="padding:14px 16px;font-weight:600;">' + esc(a.name) + '</td>' +
          '<td style="padding:14px 16px;">' + statusPill(a.billing_status) + '</td>' +
          '<td style="padding:14px 16px;font-size:13px;color:#6B7280;">' + planLabel + '</td>' +
          '<td style="padding:14px 16px;">' + a.centre_count + '</td>' +
          '<td style="padding:14px 16px;">' + a.family_count + '</td>' +
          '<td style="padding:14px 16px;">' + a.child_count + '</td>' +
          '<td style="padding:14px 16px;font-size:13px;color:#6B7280;">' + fmtDate(a.created_at) + '</td>';
        var actionsTd = Dom.el('td', { style: 'padding:14px 16px;text-align:right;white-space:nowrap;' });
        var switchBtn = Dom.el('button', {
          style: 'background:transparent;color:#1F6080;border:1px solid #1F6080;padding:5px 10px;border-radius:6px;font-size:12px;font-weight:600;cursor:pointer;margin-right:4px;',
        }, 'Open');
        switchBtn.addEventListener('click', async function () {
          await Api.post('/auth/active-agency', { agency_id: a.id });
          sessionStorage.setItem('kt_active_agency_id', String(a.id));
          sessionStorage.setItem('kt_active_agency_name', a.name);
          window.location.hash = '#dashboard';
          window.location.reload();
        });
        actionsTd.appendChild(switchBtn);
        // v22p24: edit branding / plan / white-label
        var editBtn = Dom.el('button', {
          class: 'kt-act-icon kt-act-edit kt-icon-tip', title: 'Edit agency', 'data-kttip': 'Edit agency', 'aria-label': 'Edit agency',
        }, '✏️');
        editBtn.addEventListener('click', function () {
          showAgencyModal(a, container);
        });
        actionsTd.appendChild(editBtn);
        // v22p92: re-send the agency admin's set-password invite (delivery can be flaky).
        var resendBtn = Dom.el('button', {
          class: 'kt-act-icon kt-act-teal kt-icon-tip', title: 'Resend invite', 'data-kttip': 'Resend invite', 'aria-label': 'Resend invite',
        }, '✉️');
        resendBtn.addEventListener('click', async function () {
          resendBtn.disabled = true; resendBtn.textContent = '…';
          try {
            var r = await Api.post('/platform/agencies/' + a.id + '/resend-invite', {});
            showInviteLink(a.name, r.email, r.invite_link);
          } catch (e) {
            window.alert('Could not resend: ' + (e.message || 'error'));
          } finally { resendBtn.disabled = false; resendBtn.textContent = '✉️'; }
        });
        actionsTd.appendChild(resendBtn);
        var toggleBtn = Dom.el('button', {
          class: 'kt-act-icon kt-icon-tip ' + (a.billing_status === 'suspended' ? 'kt-act-ok' : 'kt-act-warn'),
          title: a.billing_status === 'suspended' ? 'Resume' : 'Suspend',
          'data-kttip': a.billing_status === 'suspended' ? 'Resume' : 'Suspend',
          'aria-label': a.billing_status === 'suspended' ? 'Resume' : 'Suspend',
        }, a.billing_status === 'suspended' ? '▶️' : '⏸️');
        toggleBtn.addEventListener('click', async function () {
          var op = a.billing_status === 'suspended' ? 'resume' : 'suspend';
          if (op === 'suspend' && !await KT.confirm('Suspend ' + a.name + '? Users will lose dashboard access immediately.')) return;
          await Api.post('/platform/agencies/' + a.id + '/' + op, {});
          renderAgencies(container);
        });
        actionsTd.appendChild(toggleBtn);
        tr.appendChild(actionsTd);
        tbody.appendChild(tr);
      });
      table.appendChild(tbody);
      wrap.appendChild(table);
    }).catch(function (e) {
      loading.remove();
      wrap.appendChild(Dom.el('div', { style: 'padding:24px;color:#DC2626;' }, 'Could not load: ' + e.message));
    });
  }

  // v22p24: shared modal for both Create and Edit agency.
  // existing = null for create, or the agency row for edit (pre-fills inputs).
  function showAgencyModal(existing, container) {
    var isEdit = !!existing;
    // v22p25: centered overlay with internal scroll on the modal so tall
    // content (with white-label section) doesnt get clipped on shorter
    // viewports.
    var overlay = Dom.el('div', { style: 'position:fixed;inset:0;background:rgba(15,23,42,.55);z-index:9999;display:flex;align-items:center;justify-content:center;padding:24px;' });
    var modal = Dom.el('div', { style: 'background:white;border-radius:14px;padding:24px;max-width:580px;width:100%;max-height:calc(100vh - 48px);overflow-y:auto;box-shadow:0 20px 50px rgba(0,0,0,.3);' });
    function v(key, fallback) {
      if (! existing) return fallback || '';
      return (existing[key] === null || existing[key] === undefined) ? (fallback || '') : existing[key];
    }
    function agInput() { return 'width:100%;padding:8px 12px;border:1px solid #D1D5DB;border-radius:6px;font-size:14px;box-sizing:border-box;'; }
    var whiteLabelChecked = isEdit && existing.powered_by_visible === 0;
    var primaryColor = v('brand_primary_color', '#1F6080');
    modal.innerHTML =
      '<h3 style="margin:0 0 14px;font-size:18px;">' + (isEdit ? '✏️ Edit agency' : '🌐 Create new customer agency') + '</h3>' +
      (isEdit ? '' : '<p style="font-size:13px;color:#6B7280;margin:0 0 16px;">Provisions a brand-new tenant on the platform. Starts on a 30-day trial. You can invite the first agency_admin afterwards via the User management tab once you switch into the new agency.</p>') +
      '<div style="margin-bottom:12px;"><label style="display:block;font-size:13px;font-weight:600;margin-bottom:4px;">Agency name *</label><input id="ag-name" type="text" placeholder="e.g. Tiny Steps Daycare" value="' + esc(v('name')) + '" style="width:100%;padding:8px 12px;border:1px solid #D1D5DB;border-radius:6px;font-size:14px;box-sizing:border-box;"></div>' +
      '<div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:12px;">' +
        '<div><label style="display:block;font-size:13px;font-weight:600;margin-bottom:4px;">Contact email</label><input id="ag-email" type="email" value="' + esc(v('contact_email')) + '" style="' + agInput() + '"></div>' +
        '<div><label style="display:block;font-size:13px;font-weight:600;margin-bottom:4px;">Contact phone</label><input id="ag-phone" type="tel" value="' + esc(v('contact_phone')) + '" style="' + agInput() + '"></div>' +
      '</div>' +
      (isEdit ?
        '<div data-kt-noautofill="1" style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:4px;">' +
          '<div><label style="display:block;font-size:13px;font-weight:600;margin-bottom:4px;">Agency owner name</label><input id="ag-owner-name" type="text" placeholder="Owner full name" value="' + esc(v('owner_name')) + '" style="' + agInput() + '"></div>' +
          '<div><label style="display:block;font-size:13px;font-weight:600;margin-bottom:4px;">Agency owner email</label><input id="ag-owner-email" type="email" placeholder="owner@agency.com" value="' + esc(v('owner_email')) + '" style="' + agInput() + '"></div>' +
        '</div>' +
        '<div style="font-size:11.5px;color:#94A3B8;margin-bottom:12px;">Leave blank to auto-detect from the agency admins (super admins are excluded).</div>'
        : '') +
      '<div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:14px;">' +
        '<div><label style="display:block;font-size:13px;font-weight:600;margin-bottom:4px;">Plan</label><select id="ag-plan" style="' + agInput() + 'background:white;">' +
          [['starter','Starter — $49/mo'],['growth','Growth — $149/mo'],['enterprise','Enterprise — $349/mo']].map(function (p) { return '<option value="' + p[0] + '"' + (v('plan_code') === p[0] ? ' selected' : '') + '>' + p[1] + '</option>'; }).join('') +
        '</select></div>' +
        '<div><label style="display:block;font-size:13px;font-weight:600;margin-bottom:4px;">Monthly</label><input id="ag-amount" type="number" min="0" step="1" placeholder="149" value="' + (existing && existing.plan_amount_cents ? Math.round(existing.plan_amount_cents / 100) : '') + '" style="' + agInput() + '"></div>' +
      '</div>' +
      // v22p92: agency details (address + residence country + default language)
      '<div style="border-top:1px solid #E5E7EB;padding-top:12px;margin-bottom:12px;">' +
        '<div style="font-size:14px;font-weight:700;margin-bottom:8px;">🏢 Agency details</div>' +
        '<div style="margin-bottom:10px;"><label style="display:block;font-size:12px;font-weight:600;margin-bottom:3px;">Business address</label><textarea id="ag-address" rows="3" style="' + agInput() + 'font-family:inherit;resize:vertical;">' + esc(v('brand_address')) + '</textarea></div>' +
        '<div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;">' +
          '<div><label style="display:block;font-size:12px;font-weight:600;margin-bottom:3px;">Country</label><select id="ag-country" style="' + agInput() + 'background:white;">' +
            [['CA','🇨🇦 Canada'],['US','🇺🇸 United States'],['GB','🇬🇧 United Kingdom'],['AU','🇦🇺 Australia'],['NZ','🇳🇿 New Zealand'],['IE','🇮🇪 Ireland']].map(function (c) { return '<option value="' + c[0] + '"' + (v('country') === c[0] ? ' selected' : '') + '>' + c[1] + '</option>'; }).join('') +
          '</select></div>' +
          '<div><label style="display:block;font-size:12px;font-weight:600;margin-bottom:3px;">Default language</label><select id="ag-locale" style="' + agInput() + 'background:white;">' +
            [['en','English'],['fr','Français'],['es','Español'],['hi','हिन्दी (Hindi)']].map(function (l) { return '<option value="' + l[0] + '"' + ((v('default_locale') || '').slice(0,2) === l[0] ? ' selected' : '') + '>' + l[1] + '</option>'; }).join('') +
          '</select></div>' +
        '</div>' +
      '</div>' +
      // ── White-label section ────────────────────────────────────────
      '<div style="border-top:1px solid #E5E7EB;padding-top:14px;margin-bottom:12px;">' +
        '<div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:6px;">' +
          '<div><div style="font-size:14px;font-weight:700;">🎨 White-label branding</div>' +
            '<div style="font-size:11px;color:#6B7280;">Chargeable add-on. When enabled, the agency shows its own logo + colours and the "Powered by Kiddietrac" footer is hidden. Price baked into the monthly amount above (suggest +$50/mo).</div>' +
          '</div>' +
          '<label style="display:inline-flex;align-items:center;gap:6px;font-size:13px;font-weight:600;cursor:pointer;white-space:nowrap;">' +
            '<input id="ag-wl" type="checkbox" ' + (whiteLabelChecked ? 'checked' : '') + '> Enable' +
          '</label>' +
        '</div>' +
        // v22p25: stay fully visible on Edit so the fields are obvious; checkbox alone signals state.
        '<div id="ag-wl-fields" style="margin-top:10px;">' +
          '<div style="margin-bottom:10px;"><label style="display:block;font-size:12px;font-weight:600;margin-bottom:3px;">Logo URL (PNG/SVG, max 200×60)</label>' +
            '<input id="ag-logo" type="text" placeholder="https://customer.com/logo.png" value="' + esc(v('brand_logo_url')) + '" style="width:100%;padding:7px 10px;border:1px solid #D1D5DB;border-radius:6px;font-size:13px;box-sizing:border-box;font-family:ui-monospace,monospace;"></div>' +
          '<div style="display:grid;grid-template-columns:120px 1fr;gap:10px;margin-bottom:10px;">' +
            '<div><label style="display:block;font-size:12px;font-weight:600;margin-bottom:3px;">Primary colour</label><input id="ag-color" type="color" value="' + esc(primaryColor) + '" style="width:100%;height:36px;border:1px solid #D1D5DB;border-radius:6px;padding:2px;cursor:pointer;"></div>' +
            '<div><label style="display:block;font-size:12px;font-weight:600;margin-bottom:3px;">Support email</label><input id="ag-support" type="email" placeholder="support@customer.com" value="' + esc(v('brand_support_email')) + '" style="width:100%;padding:7px 10px;border:1px solid #D1D5DB;border-radius:6px;font-size:13px;box-sizing:border-box;"></div>' +
          '</div>' +
        '</div>' +
      '</div>' +

      // v22p36 ── Email settings ─────────────────────────────────────
      // data-kt-noautofill: stop the browser filling the SMTP username/from with
      // the signed-in user's saved email (looked like a mailbox was configured
      // when nothing was entered).
      '<div data-kt-noautofill="1" style="background:#F0FDF4;border:1px solid #BBF7D0;border-radius:10px;padding:14px 16px;margin-bottom:16px;">' +
        '<div style="display:flex;align-items:center;gap:8px;margin-bottom:10px;">' +
          '<div><div style="font-size:14px;font-weight:700;">✉️ Email settings (per-agency SMTP)</div>' +
          '<div style="font-size:11px;color:#6B7280;">When set, outbound mail for this tenant — digests, marketing, invoices, password resets — goes via these credentials. Leave blank to use the platform default.</div></div>' +
        '</div>' +
        '<div style="display:grid;grid-template-columns:2fr 1fr;gap:10px;margin-bottom:10px;">' +
          '<div><label style="display:block;font-size:12px;font-weight:600;margin-bottom:3px;">SMTP host</label>' +
            '<input id="ag-smtp-host" type="text" placeholder="smtp.gmail.com / smtp.office365.com / mail.yourcentre.com" value="' + esc(v('email_smtp_host')) + '" style="width:100%;padding:7px 10px;border:1px solid #D1D5DB;border-radius:6px;font-size:13px;box-sizing:border-box;font-family:ui-monospace,monospace;"></div>' +
          '<div><label style="display:block;font-size:12px;font-weight:600;margin-bottom:3px;">Port</label>' +
            '<input id="ag-smtp-port" type="number" min="1" max="65535" placeholder="587" value="' + esc(v('email_smtp_port')) + '" style="width:100%;padding:7px 10px;border:1px solid #D1D5DB;border-radius:6px;font-size:13px;box-sizing:border-box;"></div>' +
        '</div>' +
        '<div style="display:grid;grid-template-columns:2fr 1fr;gap:10px;margin-bottom:10px;">' +
          '<div><label style="display:block;font-size:12px;font-weight:600;margin-bottom:3px;">SMTP username</label>' +
            '<input id="ag-smtp-user" type="text" placeholder="noreply@yourcentre.com" autocomplete="off" value="' + esc(v('email_smtp_user')) + '" style="width:100%;padding:7px 10px;border:1px solid #D1D5DB;border-radius:6px;font-size:13px;box-sizing:border-box;"></div>' +
          '<div><label style="display:block;font-size:12px;font-weight:600;margin-bottom:3px;">Encryption</label>' +
            '<select id="ag-smtp-enc" style="width:100%;padding:7px 10px;border:1px solid #D1D5DB;border-radius:6px;font-size:13px;box-sizing:border-box;background:white;">' +
              '<option value="tls"' + (v('email_smtp_encryption', 'tls') === 'tls' ? ' selected' : '') + '>TLS (port 587)</option>' +
              '<option value="ssl"' + (v('email_smtp_encryption') === 'ssl' ? ' selected' : '') + '>SSL (port 465)</option>' +
              '<option value="none"' + (v('email_smtp_encryption') === 'none' ? ' selected' : '') + '>None</option>' +
            '</select></div>' +
        '</div>' +
        '<div style="margin-bottom:10px;"><label style="display:block;font-size:12px;font-weight:600;margin-bottom:3px;">SMTP password ' + (v('email_smtp_pass_set') ? '<span style="color:#16A34A;font-weight:normal;font-size:11px;">(saved · leave blank to keep)</span>' : '') + '</label>' +
          '<input id="ag-smtp-pass" type="password" placeholder="' + (v('email_smtp_pass_set') ? '••••••••••' : 'Mailbox password or app token') + '" autocomplete="new-password" style="width:100%;padding:7px 10px;border:1px solid #D1D5DB;border-radius:6px;font-size:13px;box-sizing:border-box;"></div>' +
        '<div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;">' +
          '<div><label style="display:block;font-size:12px;font-weight:600;margin-bottom:3px;">From address</label>' +
            '<input id="ag-from-addr" type="email" placeholder="noreply@yourcentre.com" value="' + esc(v('email_from_address')) + '" style="width:100%;padding:7px 10px;border:1px solid #D1D5DB;border-radius:6px;font-size:13px;box-sizing:border-box;"></div>' +
          '<div><label style="display:block;font-size:12px;font-weight:600;margin-bottom:3px;">From name</label>' +
            '<input id="ag-from-name" type="text" placeholder="' + esc(v('name', 'Agency')) + '" value="' + esc(v('email_from_name')) + '" style="width:100%;padding:7px 10px;border:1px solid #D1D5DB;border-radius:6px;font-size:13px;box-sizing:border-box;"></div>' +
        '</div>' +
      '</div>' +
      '<div id="ag-err" style="color:#DC2626;font-size:13px;min-height:18px;margin-bottom:8px;"></div>' +
      '<div style="display:flex;justify-content:flex-end;gap:8px;">' +
        '<button id="ag-cancel" style="background:white;color:#374151;border:1px solid #D1D5DB;padding:9px 16px;border-radius:8px;font-size:13px;font-weight:600;cursor:pointer;">Cancel</button>' +
        '<button id="ag-save" style="background:#7C3AED;color:white;border:none;padding:9px 16px;border-radius:8px;font-size:13px;font-weight:600;cursor:pointer;">' + (isEdit ? 'Save changes' : 'Create agency') + '</button>' +
      '</div>';
    overlay.appendChild(modal);
    document.body.appendChild(overlay);
    overlay.addEventListener('click', function (e) { if (e.target === overlay) overlay.remove(); });
    modal.querySelector('#ag-cancel').addEventListener('click', function () { overlay.remove(); });

    // v22p25: section stays fully visible — checkbox alone signals on/off state.
    var wlBox = modal.querySelector('#ag-wl');

    modal.querySelector('#ag-save').addEventListener('click', async function () {
      // v22p36: collect per-agency email settings too. Password field empty =
      // 'keep existing' (server unsets the key in that case).
      var portRaw = modal.querySelector('#ag-smtp-port').value;
      var port = portRaw ? parseInt(portRaw, 10) : null;
      var payload = {
        name: modal.querySelector('#ag-name').value.trim(),
        contact_email: modal.querySelector('#ag-email').value.trim() || null,
        contact_phone: modal.querySelector('#ag-phone').value.trim() || null,
        owner_name: (modal.querySelector('#ag-owner-name') ? modal.querySelector('#ag-owner-name').value.trim() : '') || null,
        owner_email: (modal.querySelector('#ag-owner-email') ? modal.querySelector('#ag-owner-email').value.trim() : '') || null,
        plan_code: modal.querySelector('#ag-plan').value.trim() || null,
        plan_amount_cents: (function () { var n = parseInt(modal.querySelector('#ag-amount').value, 10); return isNaN(n) ? 0 : n * 100; })(),
        brand_address: modal.querySelector('#ag-address').value.trim() || null,
        country: modal.querySelector('#ag-country').value || null,
        default_locale: modal.querySelector('#ag-locale').value || null,
        white_label_enabled: wlBox.checked,
        brand_logo_url: modal.querySelector('#ag-logo').value.trim() || null,
        brand_primary_color: modal.querySelector('#ag-color').value || null,
        brand_support_email: modal.querySelector('#ag-support').value.trim() || null,
        email_smtp_host: modal.querySelector('#ag-smtp-host').value.trim() || null,
        email_smtp_port: (port && !isNaN(port)) ? port : null,
        email_smtp_user: modal.querySelector('#ag-smtp-user').value.trim() || null,
        email_smtp_pass: modal.querySelector('#ag-smtp-pass').value || null,
        email_smtp_encryption: modal.querySelector('#ag-smtp-enc').value,
        email_from_address: modal.querySelector('#ag-from-addr').value.trim() || null,
        email_from_name: modal.querySelector('#ag-from-name').value.trim() || null,
      };
      var errBox = modal.querySelector('#ag-err');
      errBox.textContent = '';
      if (! payload.name) { errBox.textContent = 'Agency name is required.'; return; }
      var saveBtn = modal.querySelector('#ag-save');
      saveBtn.disabled = true;
      saveBtn.textContent = isEdit ? 'Saving…' : 'Creating…';
      try {
        if (isEdit) {
          await Api.patch('/platform/agencies/' + existing.id, payload);
        } else {
          await Api.post('/platform/agencies', payload);
        }
        overlay.remove();
        renderAgencies(container);
      } catch (e) {
        saveBtn.disabled = false;
        saveBtn.textContent = isEdit ? 'Save changes' : 'Create agency';
        errBox.textContent = e.message || 'Could not save.';
      }
    });
  }

  // Backwards-compatible name still used by existing render path.
  // v22p91: creation now uses the multi-step wizard; Edit still uses showAgencyModal.
  function showCreateAgencyModal(container) {
    return showCreateAgencyWizard(container);
  }

  // ── v22p91: wizard-style "Create new agency" ───────────────────────
  function showCreateAgencyWizard(container) {
    var PLANS = [
      { code: 'starter',    label: 'Starter — $49/mo',    wl: false },
      { code: 'growth',     label: 'Growth — $149/mo (white-label included)', wl: true },
      { code: 'enterprise', label: 'Enterprise — $349/mo (white-label included)', wl: true },
    ];
    var PROVINCES = ['AB','BC','MB','NB','NL','NS','NT','NU','ON','PE','QC','SK','YT'];
    var COUNTRIES = [['CA','🇨🇦 Canada'],['US','🇺🇸 United States'],['GB','🇬🇧 United Kingdom'],['AU','🇦🇺 Australia'],['NZ','🇳🇿 New Zealand'],['IE','🇮🇪 Ireland']];
    var LANGS = [['en','English'],['fr','Français'],['es','Español'],['hi','हिन्दी (Hindi)']];
    var st = { step: 1, logoUrl: '', plan: 'growth', color: '#1F6080', country: 'CA', locale: 'en' };
    var token = sessionStorage.getItem('kt_token') || localStorage.getItem('kt_token');
    var apiBase = (window.KT && window.KT.API_BASE) || 'https://api.kiddietrac.com/api/v1';

    var overlay = Dom.el('div', { style: 'position:fixed;inset:0;background:rgba(15,23,42,.55);z-index:9999;display:flex;align-items:center;justify-content:center;padding:24px;' });
    var modal = Dom.el('div', { style: 'background:white;border-radius:16px;max-width:620px;width:100%;max-height:calc(100vh - 48px);overflow-y:auto;box-shadow:0 20px 50px rgba(0,0,0,.3);' });
    overlay.appendChild(modal);
    document.body.appendChild(overlay);
    overlay.addEventListener('click', function (e) { if (e.target === overlay) overlay.remove(); });

    function inp(id, ph, type) { return '<input id="' + id + '" type="' + (type || 'text') + '" placeholder="' + esc(ph || '') + '" style="width:100%;padding:9px 12px;border:1px solid #D1D5DB;border-radius:8px;font-size:14px;box-sizing:border-box;">'; }
    function row(label, html, req) { return '<div style="margin-bottom:12px;"><label style="display:block;font-size:13px;font-weight:600;margin-bottom:4px;">' + esc(label) + (req ? ' <span style="color:#DC2626;">*</span>' : '') + '</label>' + html + '</div>'; }
    function stepDots(n) {
      var names = ['Plan & logo', 'Agency details', 'Admin', 'Review'];
      return '<div style="display:flex;gap:6px;margin:0 0 18px;">' + names.map(function (nm, idx) {
        var on = (idx + 1) <= n;
        return '<div style="flex:1;text-align:center;"><div style="height:5px;border-radius:3px;background:' + (on ? '#7C3AED' : '#E5E7EB') + ';"></div>' +
          '<div style="font-size:10px;margin-top:5px;color:' + ((idx + 1) === n ? '#7C3AED' : '#9CA3AF') + ';font-weight:' + ((idx + 1) === n ? '700' : '500') + ';">' + nm + '</div></div>';
      }).join('') + '</div>';
    }

    function render() {
      var s = st.step;
      var planOpts = PLANS.map(function (p) { return '<option value="' + p.code + '"' + (st.plan === p.code ? ' selected' : '') + '>' + esc(p.label) + '</option>'; }).join('');
      var provOpts = '<option value="">Province *</option>' + PROVINCES.map(function (p) { return '<option value="' + p + '"' + (st.province === p ? ' selected' : '') + '>' + p + '</option>'; }).join('');
      var planMeta = PLANS.find(function (p) { return p.code === st.plan; }) || PLANS[0];

      var bodyHtml = '';
      if (s === 1) {
        bodyHtml =
          row('Plan', '<select id="w-plan" style="width:100%;padding:9px 12px;border:1px solid #D1D5DB;border-radius:8px;font-size:14px;box-sizing:border-box;background:white;">' + planOpts + '</select>', true) +
          '<div id="w-wl-note" style="font-size:12px;color:' + (planMeta.wl ? '#065F46' : '#92400E') + ';background:' + (planMeta.wl ? '#ECFDF5' : '#FEF3C7') + ';border-radius:8px;padding:9px 11px;margin:-4px 0 14px;">' +
            (planMeta.wl ? '✓ White-label is included — the admin sets up their logo &amp; colours on first login.' : 'Standard branding (“Powered by Kiddietrac”). White-label is on Growth and above.') + '</div>' +
          row('Agency logo', '<div style="display:flex;align-items:center;gap:12px;">' +
              '<div id="w-logo-prev" style="width:96px;height:60px;border:1px solid #E5E7EB;border-radius:8px;background:#F9FAFB ' + (st.logoUrl ? "url('" + esc(absUrlP(st.logoUrl)) + "')" : '') + ' center/contain no-repeat;display:flex;align-items:center;justify-content:center;color:#64748B;font-size:22px;flex-shrink:0;">' + (st.logoUrl ? '' : '🖼') + '</div>' +
              '<button type="button" id="w-logo-btn" style="background:white;color:#7C3AED;border:1.5px solid #7C3AED;padding:8px 14px;border-radius:8px;font-weight:600;cursor:pointer;font-size:13px;">' + (st.logoUrl ? 'Change logo' : 'Upload logo') + '</button>' +
              '<input id="w-logo-file" type="file" accept="image/png,image/jpeg,image/svg+xml,image/webp" style="display:none;">' +
              '<span id="w-logo-msg" style="font-size:12px;color:#64748B;">PNG/SVG, max 2 MB</span>' +
            '</div>', true) +
          row('Primary colour', '<input id="w-color" type="color" value="' + esc(st.color) + '" style="width:80px;height:40px;border:1px solid #D1D5DB;border-radius:8px;padding:2px;cursor:pointer;">');
      } else if (s === 2) {
        bodyHtml =
          row('Agency name', inp('w-name', 'e.g. Tiny Steps Childcare'), true) +
          row('Address line 1', inp('w-addr1', '123 Main St'), true) +
          row('Address line 2', inp('w-addr2', 'Unit 4 (optional)')) +
          '<div style="display:grid;grid-template-columns:2fr 1fr 1fr;gap:10px;">' +
            '<div>' + row('City', inp('w-city', 'Toronto'), true) + '</div>' +
            '<div>' + row('Province', '<select id="w-prov" style="width:100%;padding:9px 12px;border:1px solid #D1D5DB;border-radius:8px;font-size:14px;box-sizing:border-box;background:white;">' + provOpts + '</select>', true) + '</div>' +
            '<div>' + row('Postal code', inp('w-postal', 'M5V 1A1'), true) + '</div>' +
          '</div>' +
          '<div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;">' +
            '<div>' + row('Country (residence — sets currency &amp; compliance)', '<select id="w-country" style="width:100%;padding:9px 12px;border:1px solid #D1D5DB;border-radius:8px;font-size:14px;box-sizing:border-box;background:white;">' + COUNTRIES.map(function (c) { return '<option value="' + c[0] + '"' + (st.country === c[0] ? ' selected' : '') + '>' + esc(c[1]) + '</option>'; }).join('') + '</select>', true) + '</div>' +
            '<div>' + row('Default language', '<select id="w-locale" style="width:100%;padding:9px 12px;border:1px solid #D1D5DB;border-radius:8px;font-size:14px;box-sizing:border-box;background:white;">' + LANGS.map(function (l) { return '<option value="' + l[0] + '"' + (st.locale === l[0] ? ' selected' : '') + '>' + esc(l[1]) + '</option>'; }).join('') + '</select>', true) + '</div>' +
          '</div>' +
          '<div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;">' +
            '<div>' + row('Contact email', inp('w-email', 'info@agency.com', 'email'), true) + '</div>' +
            '<div>' + row('Contact phone', inp('w-phone', '(555) 123-4567', 'tel'), true) + '</div>' +
          '</div>';
      } else if (s === 3) {
        bodyHtml =
          '<p style="font-size:13px;color:#6B7280;margin:0 0 14px;">The first <strong>agency admin</strong>. They’ll be emailed an invite to set their password and finish setup on first login.</p>' +
          '<div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;">' +
            '<div>' + row('First name', inp('w-afirst', 'Jordan'), true) + '</div>' +
            '<div>' + row('Last name', inp('w-alast', 'Lee'), true) + '</div>' +
          '</div>' +
          row('Email', inp('w-aemail', 'jordan@agency.com', 'email'), true) +
          row('Phone', inp('w-aphone', '(555) 987-6543', 'tel'), true);
      } else {
        bodyHtml =
          '<p style="font-size:13px;color:#6B7280;margin:0 0 12px;">Review, then provision the agency.</p>' +
          reviewRow('Plan', (PLANS.find(function (p) { return p.code === st.plan; }) || {}).label) +
          reviewRow('White-label', planMeta.wl ? 'Included' : 'Not on this plan') +
          reviewRow('Logo', st.logoUrl ? 'Uploaded ✓' : '—') +
          reviewRow('Agency', st.name) +
          reviewRow('Address', [st.addr1, st.addr2, (st.city || '') + ' ' + (st.province || '') + ' ' + (st.postal || '')].filter(Boolean).join(', ')) +
          reviewRow('Country', (function () { var c = COUNTRIES.find(function (x) { return x[0] === st.country; }); return c ? c[1] : st.country; })()) +
          reviewRow('Language', (function () { var l = LANGS.find(function (x) { return x[0] === st.locale; }); return l ? l[1] : st.locale; })()) +
          reviewRow('Contact', (st.email || '') + ' · ' + (st.phone || '')) +
          reviewRow('Admin', (st.afirst || '') + ' ' + (st.alast || '') + ' · ' + (st.aemail || '') + ' · ' + (st.aphone || ''));
      }

      modal.innerHTML =
        '<div style="padding:22px 24px 0;"><h3 style="margin:0 0 4px;font-size:19px;">🌐 Create new agency</h3>' +
          '<div style="font-size:12px;color:#64748B;margin-bottom:16px;">Step ' + s + ' of 4</div>' + stepDots(s) + '</div>' +
        '<div style="padding:0 24px;">' + bodyHtml +
          '<div id="w-err" style="color:#DC2626;font-size:13px;min-height:18px;margin-top:4px;"></div></div>' +
        '<div style="display:flex;justify-content:space-between;gap:8px;padding:14px 24px 22px;">' +
          '<button id="w-back" style="background:white;color:#374151;border:1px solid #D1D5DB;padding:10px 18px;border-radius:9px;font-size:14px;font-weight:600;cursor:pointer;' + (s === 1 ? 'visibility:hidden;' : '') + '">← Back</button>' +
          '<button id="w-next" style="background:#7C3AED;color:white;border:none;padding:10px 22px;border-radius:9px;font-size:14px;font-weight:700;cursor:pointer;">' + (s === 4 ? '✓ Create agency' : 'Next →') + '</button>' +
        '</div>';

      wire();
    }

    function reviewRow(k, v) {
      return '<div style="display:flex;gap:10px;padding:8px 0;border-bottom:1px solid #F3F4F6;font-size:13px;"><div style="width:110px;color:#6B7280;font-weight:600;flex-shrink:0;">' + esc(k) + '</div><div style="color:#111827;">' + esc(v || '—') + '</div></div>';
    }

    function persist() {
      var s = st.step, q = function (id) { var el = modal.querySelector(id); return el ? el.value : undefined; };
      if (s === 1) { st.plan = q('#w-plan') || st.plan; st.color = q('#w-color') || st.color; }
      else if (s === 2) { st.name = (q('#w-name') || '').trim(); st.addr1 = (q('#w-addr1') || '').trim(); st.addr2 = (q('#w-addr2') || '').trim(); st.city = (q('#w-city') || '').trim(); st.province = q('#w-prov') || ''; st.postal = (q('#w-postal') || '').trim(); st.country = q('#w-country') || st.country; st.locale = q('#w-locale') || st.locale; st.email = (q('#w-email') || '').trim(); st.phone = (q('#w-phone') || '').trim(); }
      else if (s === 3) { st.afirst = (q('#w-afirst') || '').trim(); st.alast = (q('#w-alast') || '').trim(); st.aemail = (q('#w-aemail') || '').trim(); st.aphone = (q('#w-aphone') || '').trim(); }
    }

    function validate() {
      var s = st.step, err = modal.querySelector('#w-err');
      var miss = [];
      if (s === 1) { if (!st.logoUrl) miss.push('Upload a logo'); }
      else if (s === 2) { [['name', 'Agency name'], ['addr1', 'Address line 1'], ['city', 'City'], ['province', 'Province'], ['postal', 'Postal code'], ['email', 'Contact email'], ['phone', 'Contact phone']].forEach(function (f) { if (!st[f[0]]) miss.push(f[1]); }); if (st.email && !/^[^@]+@[^@]+\.[^@]+$/.test(st.email)) miss.push('Valid contact email'); }
      else if (s === 3) { [['afirst', 'First name'], ['alast', 'Last name'], ['aemail', 'Email'], ['aphone', 'Phone']].forEach(function (f) { if (!st[f[0]]) miss.push(f[1]); }); if (st.aemail && !/^[^@]+@[^@]+\.[^@]+$/.test(st.aemail)) miss.push('Valid admin email'); }
      if (miss.length) { err.textContent = 'Required: ' + miss.join(', ') + '.'; return false; }
      err.textContent = ''; return true;
    }

    function wire() {
      modal.querySelector('#w-back').addEventListener('click', function () { persist(); if (st.step > 1) { st.step--; render(); } });
      modal.querySelector('#w-next').addEventListener('click', function () {
        persist();
        if (!validate()) return;
        if (st.step < 4) { st.step++; render(); } else { submit(); }
      });
      if (st.step === 1) {
        var planSel = modal.querySelector('#w-plan');
        planSel.addEventListener('change', function () { persist(); render(); });
        var fileEl = modal.querySelector('#w-logo-file');
        var msg = modal.querySelector('#w-logo-msg');
        modal.querySelector('#w-logo-btn').addEventListener('click', function () { fileEl.click(); });
        fileEl.addEventListener('change', function () {
          var f = fileEl.files[0]; if (!f) return;
          if (f.size > 2 * 1024 * 1024) { msg.textContent = 'Max 2 MB'; msg.style.color = '#DC2626'; return; }
          msg.textContent = 'Uploading…'; msg.style.color = '#6B7280';
          var fd = new FormData(); fd.append('image', f);
          fetch(apiBase + '/marketing/images', { method: 'POST', headers: { 'Authorization': 'Bearer ' + token }, body: fd })
            .then(function (r) { return r.json().then(function (j) { if (!r.ok) throw new Error(j.message || ('HTTP ' + r.status)); return j; }); })
            .then(function (j) { st.logoUrl = j.url || ''; persist(); render(); })
            .catch(function (e) { msg.textContent = 'Upload failed: ' + (e.message || 'error'); msg.style.color = '#DC2626'; });
        });
      }
    }

    function submit() {
      // Progress overlay so the user sees provisioning is underway.
      modal.innerHTML =
        '<div style="padding:48px 32px;text-align:center;">' +
          '<div class="kt-wiz-spin" style="width:46px;height:46px;border:4px solid #EDE9FE;border-top-color:#7C3AED;border-radius:50%;margin:0 auto 18px;animation:kt-wiz-spin 0.8s linear infinite;"></div>' +
          '<h3 style="margin:0 0 6px;font-size:18px;">Creating ' + esc(st.name) + '…</h3>' +
          '<div style="font-size:13px;color:#6B7280;">Provisioning the agency, its first centre, and the admin invite. This takes a few seconds.</div>' +
        '</div>';
      if (!document.getElementById('kt-wiz-spin-css')) {
        var sc = document.createElement('style'); sc.id = 'kt-wiz-spin-css';
        sc.textContent = '@keyframes kt-wiz-spin{to{transform:rotate(360deg)}}';
        document.head.appendChild(sc);
      }
      var payload = {
        name: st.name, contact_email: st.email, contact_phone: st.phone,
        plan_code: st.plan,
        address_line1: st.addr1, address_line2: st.addr2 || null, city: st.city,
        province: st.province, postal_code: st.postal, country: st.country,
        default_locale: st.locale,
        brand_logo_url: st.logoUrl || null, brand_primary_color: st.color || null,
        admin_first_name: st.afirst, admin_last_name: st.alast,
        admin_email: st.aemail, admin_phone: st.aphone,
      };
      Api.post('/platform/agencies', payload).then(function (res) {
        modal.innerHTML =
          '<div style="padding:34px 28px;text-align:center;">' +
            '<div style="font-size:42px;">🎉</div>' +
            '<h3 style="margin:10px 0 6px;font-size:20px;">' + esc(st.name) + ' is ready</h3>' +
            '<p style="font-size:13px;color:#6B7280;margin:0 0 16px;">Provisioned on a 30-day trial with a default centre. ' + esc(st.afirst) + ' was emailed an invite to set their password.</p>' +
            (res && res.invite_link ? '<div style="font-size:12px;color:#6B7280;margin-bottom:6px;">Invite link (in case the email is delayed):</div><div style="font-size:11px;background:#F3F4F6;border-radius:8px;padding:10px;word-break:break-all;font-family:ui-monospace,monospace;">' + esc(res.invite_link) + '</div>' : '') +
            '<button id="w-done" style="margin-top:18px;background:#7C3AED;color:white;border:none;padding:10px 22px;border-radius:9px;font-weight:700;cursor:pointer;">Done</button>' +
          '</div>';
        modal.querySelector('#w-done').addEventListener('click', function () { overlay.remove(); renderAgencies(container); });
      }).catch(function (e) {
        // The spinner replaced the form — show the error with a way back.
        modal.innerHTML =
          '<div style="padding:34px 28px;text-align:center;">' +
            '<div style="font-size:38px;">⚠️</div>' +
            '<h3 style="margin:10px 0 6px;font-size:18px;">Could not create the agency</h3>' +
            '<p style="font-size:13px;color:#DC2626;margin:0 0 16px;">' + esc(e.message || 'Unknown error.') + '</p>' +
            '<button id="w-retry" style="background:#7C3AED;color:white;border:none;padding:10px 22px;border-radius:9px;font-weight:700;cursor:pointer;">← Back to review</button>' +
          '</div>';
        modal.querySelector('#w-retry').addEventListener('click', function () { st.step = 4; render(); });
      });
    }

    render();
  }

  // Prefix relative /storage paths with the API host for logo previews.
  function absUrlP(p) {
    if (!p) return '';
    if (/^https?:\/\//i.test(p)) return p;
    var base = (window.KT && window.KT.API_BASE) || 'https://api.kiddietrac.com/api/v1';
    return base.replace(/\/api\/v1\/?$/, '') + p;
  }

  // v22p92: confirm a (re)sent invite and surface the link so it never depends
  // on email delivery.
  function showInviteLink(agencyName, email, link) {
    var ov = Dom.el('div', { style: 'position:fixed;inset:0;background:rgba(15,23,42,.55);z-index:10000;display:flex;align-items:center;justify-content:center;padding:24px;' });
    var m = Dom.el('div', { style: 'background:white;border-radius:14px;max-width:520px;width:100%;padding:24px;box-shadow:0 20px 50px rgba(0,0,0,.3);' });
    m.innerHTML =
      '<h3 style="margin:0 0 6px;font-size:18px;">✉ Invite sent</h3>' +
      '<p style="font-size:13px;color:#6B7280;margin:0 0 14px;">A set-password invite was emailed to <strong>' + esc(email || '') + '</strong> for <strong>' + esc(agencyName) + '</strong>. If it doesn\'t arrive (spam/SPF), copy this link and send it directly:</p>' +
      '<div style="font-size:12px;background:#F3F4F6;border-radius:8px;padding:11px;word-break:break-all;font-family:ui-monospace,monospace;">' + esc(link || '') + '</div>' +
      '<div style="display:flex;justify-content:flex-end;gap:8px;margin-top:16px;">' +
        '<button id="il-copy" style="background:white;color:#374151;border:1px solid #D1D5DB;padding:9px 16px;border-radius:8px;font-weight:600;cursor:pointer;">Copy link</button>' +
        '<button id="il-close" style="background:#7C3AED;color:white;border:none;padding:9px 16px;border-radius:8px;font-weight:700;cursor:pointer;">Done</button>' +
      '</div>';
    ov.appendChild(m); document.body.appendChild(ov);
    ov.addEventListener('click', function (e) { if (e.target === ov) ov.remove(); });
    m.querySelector('#il-close').addEventListener('click', function () { ov.remove(); });
    m.querySelector('#il-copy').addEventListener('click', function () {
      try { navigator.clipboard.writeText(link); m.querySelector('#il-copy').textContent = '✓ Copied'; } catch (e) {}
    });
  }

  // v22p92: outbound email audit log (platform admin).
  function showEmailLog() {
    var ov = Dom.el('div', { style: 'position:fixed;inset:0;background:rgba(15,23,42,.55);z-index:10000;display:flex;align-items:flex-start;justify-content:center;padding:32px 24px;overflow:auto;' });
    var m = Dom.el('div', { style: 'background:white;border-radius:14px;max-width:900px;width:100%;max-height:calc(100vh - 64px);overflow:auto;box-shadow:0 20px 50px rgba(0,0,0,.3);' });
    m.innerHTML =
      '<div style="padding:18px 22px;border-bottom:1px solid #E5E7EB;display:flex;justify-content:space-between;align-items:center;position:sticky;top:0;background:white;">' +
        '<div><h3 style="margin:0;font-size:18px;">📧 Email log</h3><div style="font-size:12px;color:#6B7280;">Every message the system sent. Delivery still depends on DNS/SPF — this confirms the send.</div></div>' +
        '<button id="el-close" style="background:transparent;border:none;font-size:22px;color:#6B7280;cursor:pointer;">×</button>' +
      '</div>' +
      '<div id="el-body" style="padding:8px 0;"><div style="padding:30px;text-align:center;color:#6B7280;">Loading…</div></div>';
    ov.appendChild(m); document.body.appendChild(ov);
    ov.addEventListener('click', function (e) { if (e.target === ov) ov.remove(); });
    m.querySelector('#el-close').addEventListener('click', function () { ov.remove(); });
    Api.get('/platform/email-logs').then(function (data) {
      var logs = (data && data.logs) || [];
      var body = m.querySelector('#el-body');
      if (!logs.length) { body.innerHTML = '<div style="padding:30px;text-align:center;color:#6B7280;">No emails logged yet.</div>'; return; }
      body.innerHTML =
        '<table style="width:100%;border-collapse:collapse;font-size:13px;">' +
          '<thead><tr style="background:#F9FAFB;">' + ['When', 'To', 'Subject', 'Via', 'Opened'].map(function (h) { return '<th style="text-align:left;padding:9px 14px;font-size:11px;color:#6B7280;text-transform:uppercase;">' + h + '</th>'; }).join('') + '</tr></thead>' +
          '<tbody>' + logs.map(function (l) {
            var opened = l.opened_at
              ? '<span style="color:#16A34A;font-weight:700;">✓ ' + esc(fmtDate(l.opened_at)) + (l.opens > 1 ? ' (' + l.opens + '×)' : '') + '</span>'
              : (l.tracking_token ? '<span style="color:#64748B;">not yet</span>' : '<span style="color:#CBD5E1;">—</span>');
            var canView = !!l.has_body;
            var subjCell = canView
              ? '<span style="color:#2563EB;font-weight:600;">' + esc(l.subject || '—') + '</span> <span style="font-size:11px;color:#93C5FD;">👁 preview</span>'
              : esc(l.subject || '—');
            return '<tr data-el-id="' + (canView ? l.id : '') + '" style="border-top:1px solid #F3F4F6;' + (canView ? 'cursor:pointer;' : '') + '"' + (canView ? ' class="el-row"' : '') + '>' +
              '<td style="padding:9px 14px;color:#6B7280;white-space:nowrap;">' + esc(fmtDate(l.created_at)) + '</td>' +
              '<td style="padding:9px 14px;">' + esc((l.to_name ? l.to_name + ' ' : '') + '<' + (l.to_email || '') + '>') + '</td>' +
              '<td style="padding:9px 14px;">' + subjCell + '</td>' +
              '<td style="padding:9px 14px;color:#6B7280;">' + esc(l.mailer || '—') + '</td>' +
              '<td style="padding:9px 14px;white-space:nowrap;">' + opened + '</td>' +
            '</tr>';
          }).join('') + '</tbody>' +
        '</table>';
      body.querySelectorAll('.el-row').forEach(function (row) {
        row.addEventListener('mouseenter', function () { row.style.background = '#F8FAFC'; });
        row.addEventListener('mouseleave', function () { row.style.background = ''; });
        row.addEventListener('click', function () {
          var id = row.getAttribute('data-el-id');
          if (id) showEmailPreview(id);
        });
      });
    }).catch(function (e) {
      m.querySelector('#el-body').innerHTML = '<div style="padding:24px;color:#DC2626;">Could not load: ' + esc(e.message || 'error') + '</div>';
    });
  }

  // v22p93: render the actual HTML that was emailed, in a sandboxed iframe.
  function showEmailPreview(id) {
    var ov = Dom.el('div', { style: 'position:fixed;inset:0;background:rgba(15,23,42,.62);z-index:10001;display:flex;align-items:flex-start;justify-content:center;padding:28px 20px;overflow:auto;' });
    var m = Dom.el('div', { style: 'background:white;border-radius:14px;max-width:720px;width:100%;max-height:calc(100vh - 56px);overflow:hidden;box-shadow:0 24px 60px rgba(0,0,0,.35);display:flex;flex-direction:column;' });
    m.innerHTML =
      '<div style="padding:16px 20px;border-bottom:1px solid #E5E7EB;display:flex;justify-content:space-between;align-items:flex-start;gap:12px;">' +
        '<div id="ep-head" style="min-width:0;"><h3 style="margin:0;font-size:16px;">📨 Email preview</h3><div style="font-size:12px;color:#6B7280;">Loading…</div></div>' +
        '<button id="ep-close" style="background:transparent;border:none;font-size:22px;color:#6B7280;cursor:pointer;line-height:1;">×</button>' +
      '</div>' +
      '<div id="ep-body" style="flex:1;overflow:auto;background:#F1F5F9;padding:0;"><div style="padding:40px;text-align:center;color:#6B7280;">Loading…</div></div>';
    ov.appendChild(m); document.body.appendChild(ov);
    ov.addEventListener('click', function (e) { if (e.target === ov) ov.remove(); });
    m.querySelector('#ep-close').addEventListener('click', function () { ov.remove(); });
    var onEsc = function (e) { if (e.key === 'Escape') { ov.remove(); document.removeEventListener('keydown', onEsc); } };
    document.addEventListener('keydown', onEsc);
    Api.get('/platform/email-logs/' + id + '/preview').then(function (d) {
      m.querySelector('#ep-head').innerHTML =
        '<h3 style="margin:0;font-size:16px;">' + esc(d.subject || '(no subject)') + '</h3>' +
        '<div style="font-size:12px;color:#6B7280;margin-top:2px;">To ' + esc((d.to_name ? d.to_name + ' ' : '') + '<' + (d.to_email || '') + '>') +
          ' · ' + esc(fmtDate(d.created_at)) + (d.status ? ' · ' + esc(d.status) : '') + '</div>' +
        // Shown only when there were copied recipients. An empty "Bcc:" on every
        // routine email is noise, and noise is how a line stops being read — which
        // matters here because this is the line that answers "was the director copied?"
        // for an access-removal, suspension or de-enrolment notice.
        ((d.cc || d.bcc)
          ? '<div style="font-size:12px;color:#475569;margin-top:4px;padding:6px 9px;background:#F1F5F9;border-radius:7px;">' +
              (d.cc ? '<div><b>Cc:</b> ' + esc(d.cc) + '</div>' : '') +
              (d.bcc ? '<div><b>Bcc:</b> ' + esc(d.bcc) + '</div>' : '') +
            '</div>'
          : '');
      var epBody = m.querySelector('#ep-body');
      if (!d.html) {
        epBody.innerHTML = '<div style="padding:40px;text-align:center;color:#6B7280;">No stored content for this message (it predates preview capture, or was plain-text only).</div>';
        return;
      }
      var frame = document.createElement('iframe');
      frame.setAttribute('sandbox', '');
      // color-scheme:light stops the viewer's dark-mode from auto-inverting the
      // iframe (emails are authored for a white background, not a dark one).
      frame.style.cssText = 'width:100%;height:70vh;border:none;background:#ffffff;color-scheme:light;display:block;';
      epBody.innerHTML = '';
      epBody.appendChild(frame);
      // Inject a light color-scheme + white ground so the OS/browser dark theme
      // can't darken the email content inside the iframe.
      var lightHead = '<meta name="color-scheme" content="light only"><style>:root{color-scheme:light !important;}html,body{background:#ffffff !important;}</style>';
      var html = String(d.html);
      if (/<head[^>]*>/i.test(html)) {
        html = html.replace(/<head([^>]*)>/i, '<head$1>' + lightHead);
      } else if (/<html[^>]*>/i.test(html)) {
        html = html.replace(/<html([^>]*)>/i, '<html$1><head>' + lightHead + '</head>');
      } else {
        html = '<head>' + lightHead + '</head>' + html;
      }
      frame.srcdoc = html;
    }).catch(function (e) {
      m.querySelector('#ep-body').innerHTML = '<div style="padding:24px;color:#DC2626;">Could not load preview: ' + esc(e.message || 'error') + '</div>';
    });
  }

  // ── Scheduled maintenance / downtime (super-admin) ──
  // A proper, reachable SCREEN for the email log. The showEmailLog() modal above
  // was orphaned (defined but never wired to any button), so no one could find it —
  // this renders the same list inline and is registered + given a nav item below.
  function elWhen(d) {
    if (!d) return '—';
    try {
      // Server timestamps are zone-less UTC ("YYYY-MM-DD HH:MM:SS"); a bare
      // new Date() would parse them as LOCAL time (the recurring UTC landmine).
      // Parse via the shared KT.Fmt when present, else coerce to UTC, then
      // render in the agency's Eastern zone like the rest of the portal.
      var dt;
      if (window.KT && KT.Fmt && typeof KT.Fmt.parse === 'function') {
        dt = KT.Fmt.parse(d);
      } else {
        var s = String(d).trim();
        if (/^\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}/.test(s) && !/[zZ]|[+\-]\d{2}:?\d{2}$/.test(s)) {
          s = s.replace(' ', 'T') + 'Z';
        }
        dt = new Date(s);
      }
      // Agency timezone — NOT hardcoded Toronto (that was wrong for every agency
      // outside Eastern) and not the viewer's device zone.
      var zone; try { zone = (window.KT && KT.tz && KT.tz()) || undefined; } catch (e2) { zone = undefined; }
      return dt.toLocaleString('en-CA', { timeZone: zone, year: 'numeric', month: 'short', day: 'numeric', hour: 'numeric', minute: '2-digit' });
    } catch (e) { return String(d); }
  }
  function elToast(icon, title, msg, colour) { try { if (window.KT && KT.toast) KT.toast(icon, title, msg, colour || '#0E7C90'); } catch (e) {} }

  function renderEmailLogScreen(container) {
    // Search, status and date filters all run SERVER-side (see PlatformController::
    // emailLogs). The screen used to pull a flat window of the newest 300 rows and
    // rely on a client-side filter box, which meant anything older than a day or two
    // of sending simply could not be found — it looked like the email had never been
    // logged. Rows are paged in on demand instead, and the count says how many
    // matches exist in total so nothing is silently cut off.
    var state = { q: '', status: '', from: '', to: '', offset: 0, limit: 100, total: 0, rows: [] };
    var timer = null;

    var inp = 'padding:8px 11px;border:1px solid #E2E8F0;border-radius:8px;font-size:13px;background:#fff;box-sizing:border-box;font-family:inherit;';
    container.innerHTML = '<div style="margin:0 auto;">'
      + '<div style="margin:2px 2px 14px;"><h2 style="margin:0;font-size:20px;color:#0F172A;">📧 Email log</h2>'
      + '<div style="font-size:12.5px;color:#64748B;margin-top:3px;line-height:1.5;">Every message the system sent, with a date &amp; time stamp in your agency’s timezone. Search covers every email ever logged — not just the ones on screen. Use the row actions to open, download or resend. (Delivery still depends on DNS/SPF — this confirms the send + open tracking.)</div></div>'
      + '<div style="display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end;margin:0 2px 12px;">'
        + '<label style="font-size:11px;font-weight:700;color:#64748B;text-transform:uppercase;flex:1;min-width:240px;max-width:380px;">Search'
          + '<input id="elf-q" type="search" placeholder="Name, email address or subject…" style="' + inp + 'width:100%;margin-top:4px;font-weight:400;text-transform:none;"></label>'
        + '<label style="font-size:11px;font-weight:700;color:#64748B;text-transform:uppercase;">Status'
          + '<select id="elf-status" style="' + inp + 'display:block;margin-top:4px;font-weight:400;text-transform:none;">'
            + '<option value="">All</option><option value="sent">Sent</option><option value="failed">Failed</option><option value="suppressed">Suppressed</option></select></label>'
        + '<label style="font-size:11px;font-weight:700;color:#64748B;text-transform:uppercase;">From'
          + '<input id="elf-from" type="date" style="' + inp + 'display:block;margin-top:4px;font-weight:400;"></label>'
        + '<label style="font-size:11px;font-weight:700;color:#64748B;text-transform:uppercase;">To'
          + '<input id="elf-to" type="date" style="' + inp + 'display:block;margin-top:4px;font-weight:400;"></label>'
        + '<button id="elf-clear" style="background:#F1F5F9;color:#334155;border:none;border-radius:8px;padding:9px 14px;font-weight:700;font-size:13px;cursor:pointer;">Clear</button>'
      + '</div>'
      + '<div id="els-meta" style="font-size:12.5px;color:#64748B;margin:0 2px 8px;min-height:17px;"></div>'
      + '<div id="els-body"><div style="padding:30px;text-align:center;color:#6B7280;">Loading…</div></div>'
      + '<div id="els-more" style="text-align:center;margin:14px 0 4px;"></div></div>';

    var body = container.querySelector('#els-body');
    var meta = container.querySelector('#els-meta');
    var more = container.querySelector('#els-more');

    function qs(o) {
      return Object.keys(o).filter(function (k) { return o[k] !== '' && o[k] != null; })
        .map(function (k) { return encodeURIComponent(k) + '=' + encodeURIComponent(o[k]); }).join('&');
    }

    function statusPill(st) {
      if (!st) return '';
      var map = { sent: ['#16A34A', '#DCFCE7'], failed: ['#B91C1C', '#FEE2E2'], suppressed: ['#B45309', '#FEF3C7'] };
      var c = map[st] || ['#475569', '#F1F5F9'];
      return '<span style="font-size:10.5px;font-weight:800;color:' + c[0] + ';background:' + c[1] + ';border-radius:5px;padding:1px 6px;text-transform:uppercase;">' + esc(st) + '</span>';
    }

    function rowHtml(l) {
      var opened = l.opened_at
        ? '<span style="color:#16A34A;font-weight:700;">✓ ' + esc(elWhen(l.opened_at)) + (l.opens > 1 ? ' (' + l.opens + '×)' : '') + '</span>'
        : (l.tracking_token ? '<span style="color:#64748B;">not yet</span>' : '<span style="color:#CBD5E1;">—</span>');
      var canView = !!l.has_body;
      var subjCell = canView
        ? '<span style="color:#2563EB;font-weight:600;">' + esc(l.subject || '—') + '</span> <span style="font-size:11px;color:#93C5FD;">👁</span>'
        : esc(l.subject || '—');
      // Plain action buttons in the last cell — the global kt-row-actions sweep
      // collapses them into a single ⋮ kebab (no bespoke menu of our own).
      var actions = (canView
          ? '<button class="kt-act-icon kt-act-info els-open" data-id="' + l.id + '" title="Open email" data-kttip="Open" aria-label="Open email">👁</button>'
            + '<button class="kt-act-icon els-dl" data-id="' + l.id + '" title="Download (.html)" data-kttip="Download" aria-label="Download email">⬇️</button>'
          : '')
        + '<button class="kt-act-icon els-resend" data-id="' + l.id + '" data-email="' + esc(l.to_email || '') + '" title="Resend email" data-kttip="Resend" aria-label="Resend email">📤</button>';
      return '<tr style="border-top:1px solid #F3F4F6;">' +
        '<td style="padding:9px 14px;color:#374151;white-space:nowrap;">' + esc(elWhen(l.created_at)) + '</td>' +
        '<td style="padding:9px 14px;">' + esc((l.to_name ? l.to_name + ' ' : '') + '<' + (l.to_email || '') + '>') + '</td>' +
        '<td class="els-subj" data-id="' + (canView ? l.id : '') + '" style="padding:9px 14px;' + (canView ? 'cursor:pointer;' : '') + '">' + subjCell + '</td>' +
        '<td style="padding:9px 14px;white-space:nowrap;">' + statusPill(l.status) + (l.error ? '<div style="font-size:11px;color:#B91C1C;max-width:280px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="' + esc(l.error) + '">' + esc(l.error) + '</div>' : '') + '</td>' +
        '<td style="padding:9px 14px;color:#6B7280;">' + esc(l.mailer || '—') + '</td>' +
        '<td style="padding:9px 14px;white-space:nowrap;">' + opened + '</td>' +
        '<td style="padding:9px 8px;text-align:right;white-space:nowrap;">' + actions + '</td></tr>';
    }

    function wireRows(scope) {
      scope.querySelectorAll('.els-subj[data-id]').forEach(function (c) { if (c.getAttribute('data-id')) c.addEventListener('click', function () { showEmailPreview(c.getAttribute('data-id')); }); });
      scope.querySelectorAll('.els-open').forEach(function (b) { b.addEventListener('click', function () { showEmailPreview(b.getAttribute('data-id')); }); });
      scope.querySelectorAll('.els-dl').forEach(function (b) { b.addEventListener('click', function () { downloadEmail(b.getAttribute('data-id')); }); });
      scope.querySelectorAll('.els-resend').forEach(function (b) { b.addEventListener('click', function () { resendEmail(b.getAttribute('data-id'), b.getAttribute('data-email')); }); });
      if (window.KT && typeof KT.sweepRowActions === 'function') setTimeout(KT.sweepRowActions, 0);
    }

    function paintMore() {
      var shown = state.rows.length;
      if (shown >= state.total) { more.innerHTML = ''; return; }
      more.innerHTML = '<button id="els-more-btn" style="background:#fff;color:#1F6080;border:1.5px solid #CBD5E1;border-radius:9px;padding:9px 20px;font-weight:700;font-size:13px;cursor:pointer;">Load ' + Math.min(state.limit, state.total - shown) + ' more</button>';
      more.querySelector('#els-more-btn').onclick = function () {
        this.disabled = true; this.textContent = 'Loading…';
        state.offset = shown; load(true);
      };
    }

    function load(append) {
      if (!append) { state.offset = 0; body.innerHTML = '<div style="padding:30px;text-align:center;color:#6B7280;">Loading…</div>'; more.innerHTML = ''; }
      var url = '/platform/email-logs?' + qs({ q: state.q, status: state.status, from: state.from, to: state.to, limit: state.limit, offset: state.offset });
      Api.get(url).then(function (data) {
        var logs = (data && data.logs) || [];
        state.total = (data && typeof data.total === 'number') ? data.total : logs.length;
        state.rows = append ? state.rows.concat(logs) : logs;

        var filtered = !!(state.q || state.status || state.from || state.to);
        meta.textContent = state.total
          ? ('Showing ' + state.rows.length.toLocaleString() + ' of ' + state.total.toLocaleString() + (filtered ? ' matching' : '') + ' email' + (state.total === 1 ? '' : 's'))
          : '';

        if (!state.rows.length) {
          body.innerHTML = '<div style="padding:40px;text-align:center;color:#6B7280;background:#F8FAFC;border-radius:12px;">'
            + (filtered ? 'No emails match those filters.' : 'No emails logged yet.') + '</div>';
          more.innerHTML = '';
          return;
        }

        if (append) {
          var tb = body.querySelector('tbody');
          if (tb) {
            var frag = document.createElement('tbody');
            frag.innerHTML = logs.map(rowHtml).join('');
            while (frag.firstChild) tb.appendChild(frag.firstChild);
            wireRows(tb);
            paintMore();
            return;
          }
        }

        // data-kt-no-filter: this screen owns its own SERVER-side search box, so the
        // global client-side one must not stack a second field beside it.
        body.innerHTML =
          '<table data-kt-no-filter style="width:100%;border-collapse:collapse;font-size:13px;background:#fff;border:1px solid #E5E7EB;border-radius:12px;overflow:hidden;">' +
            '<thead><tr style="background:#F9FAFB;">' + ['Date &amp; time', 'To', 'Subject', 'Status', 'Via', 'Opened', ''].map(function (h) { return '<th style="text-align:left;padding:9px 14px;font-size:11px;color:#6B7280;text-transform:uppercase;">' + h + '</th>'; }).join('') + '</tr></thead>' +
            '<tbody>' + state.rows.map(rowHtml).join('') + '</tbody></table>';
        wireRows(body);
        paintMore();
      }).catch(function (e) { body.innerHTML = '<div style="padding:24px;color:#DC2626;">Could not load: ' + esc(e.message || 'error') + '</div>'; });
    }

    function schedule() { clearTimeout(timer); timer = setTimeout(function () { load(false); }, 350); }

    var qEl = container.querySelector('#elf-q');
    qEl.addEventListener('input', function () { state.q = qEl.value.trim(); schedule(); });
    qEl.addEventListener('keydown', function (ev) { if (ev.key === 'Enter') { clearTimeout(timer); state.q = qEl.value.trim(); load(false); } });
    container.querySelector('#elf-status').addEventListener('change', function () { state.status = this.value; load(false); });
    container.querySelector('#elf-from').addEventListener('change', function () { state.from = this.value; load(false); });
    container.querySelector('#elf-to').addEventListener('change', function () { state.to = this.value; load(false); });
    container.querySelector('#elf-clear').addEventListener('click', function () {
      qEl.value = ''; container.querySelector('#elf-status').value = '';
      container.querySelector('#elf-from').value = ''; container.querySelector('#elf-to').value = '';
      state.q = state.status = state.from = state.to = '';
      load(false);
    });

    load(false);
  }

  function downloadEmail(id) {
    Api.get('/platform/email-logs/' + id + '/preview').then(function (d) {
      if (!d.html) { elToast('⚠️', 'Nothing to download', 'No stored content for this email.', '#B45309'); return; }
      var blob = new Blob([d.html], { type: 'text/html' });
      var url = URL.createObjectURL(blob);
      var a = document.createElement('a');
      a.href = url; a.download = 'email-' + id + '-' + String(d.subject || 'message').replace(/[^a-z0-9]+/gi, '-').slice(0, 40) + '.html';
      document.body.appendChild(a); a.click(); a.remove();
      setTimeout(function () { URL.revokeObjectURL(url); }, 2000);
    }).catch(function (e) { elToast('⚠️', 'Download failed', e.message || 'error', '#B91C1C'); });
  }

  function resendEmail(id, email) {
    var to = email || 'the original recipient';
    Promise.resolve(window.KT && KT.confirm ? KT.confirm('Resend this email to ' + to + '?') : window.confirm('Resend this email to ' + to + '?')).then(function (ok) {
      if (!ok) return;
      Api.post('/platform/email-logs/' + id + '/resend', {}).then(function (res) {
        elToast('✅', 'Resent', (res && res.message) || 'Email resent to ' + to + '.', '#16A34A');
      }).catch(function (e) { elToast('⚠️', 'Could not resend', e.message || 'error', '#B91C1C'); });
    });
  }

  function renderMaintenance(container) {
    container.setAttribute('data-kt-pretty', '1');
    container.innerHTML = '<div style="padding:24px;max-width:760px;margin:0 auto;color:#64748B;">Loading maintenance settings…</div>';
    var base = (window.KT && window.KT.API_BASE) || 'https://api.kiddietrac.com/api/v1';
    var token = sessionStorage.getItem('kt_token');
    function mapi(path, method, body) {
      return fetch(base + path, { method: method || 'GET', headers: { 'Authorization': 'Bearer ' + token, 'Content-Type': 'application/json', 'Accept': 'application/json' }, body: body ? JSON.stringify(body) : undefined })
        .then(function (r) { return r.text().then(function (t) { var j; try { j = t ? JSON.parse(t) : {}; } catch (e) { j = {}; } if (!r.ok) throw new Error(j.message || ('HTTP ' + r.status)); return j; }); });
    }
    function toInput(v) { if (!v) return ''; try { var dt = new Date(v); var p = function (n) { return (n < 10 ? '0' : '') + n; }; return dt.getFullYear() + '-' + p(dt.getMonth() + 1) + '-' + p(dt.getDate()) + 'T' + p(dt.getHours()) + ':' + p(dt.getMinutes()); } catch (e) { return ''; } }
    mapi('/platform/maintenance').then(function (d) {
      var w = d.window || {};
      var inp = 'width:100%;padding:9px 11px;border:1.5px solid #E2E8F0;border-radius:9px;font-size:14px;box-sizing:border-box;font-family:inherit;';
      container.innerHTML =
        '<div style="padding:24px;max-width:760px;margin:0 auto;">' +
          '<div class="kt-page-hero"><h2>🛠️ Scheduled maintenance</h2><p>Take the portal offline for a planned window. While active, nobody can sign in except platform admins.</p></div>' +
          (d.is_down_now ? '<div style="background:#FEE2E2;color:#B91C1C;border-radius:10px;padding:12px 14px;margin-bottom:14px;font-weight:700;">● Maintenance is ACTIVE right now — sign-in is blocked for everyone except platform admins.</div>' : '') +
          '<div class="kt-card" style="max-width:none;">' +
            '<label style="display:flex;align-items:center;justify-content:space-between;gap:16px;cursor:pointer;margin:0 0 14px;">' +
              '<span><span style="display:block;font-size:14px;font-weight:700;color:#0F172A;">Maintenance mode</span>' +
              '<span style="display:block;font-size:12.5px;color:#64748B;margin-top:3px;">When ON <strong>and</strong> the current time is inside the window, sign-in is blocked. Leave Start/End blank to block from now until you turn it off.</span></span>' +
              '<input type="checkbox" id="mw-active" data-kt-switch="1"' + (w.active ? ' checked' : '') + '></label>' +
            '<div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">' +
              '<label style="font-size:13px;font-weight:700;color:#334155;">Starts<br><input type="datetime-local" id="mw-start" value="' + toInput(w.starts_at) + '" style="' + inp + 'margin-top:4px;"></label>' +
              '<label style="font-size:13px;font-weight:700;color:#334155;">Ends<br><input type="datetime-local" id="mw-end" value="' + toInput(w.ends_at) + '" style="' + inp + 'margin-top:4px;"></label>' +
            '</div>' +
            '<label style="font-size:13px;font-weight:700;color:#334155;display:block;margin-top:12px;">Message shown to users<br><textarea id="mw-msg" rows="3" style="' + inp + 'margin-top:4px;resize:vertical;" placeholder="We are performing scheduled maintenance…">' + esc(w.message || '') + '</textarea></label>' +
            '<div id="mw-out" style="font-size:13px;margin-top:10px;min-height:16px;"></div>' +
            '<div style="margin-top:12px;"><button id="mw-save" style="background:#1F6080;color:#fff;border:none;border-radius:9px;padding:9px 18px;font-weight:700;cursor:pointer;">Save</button></div>' +
          '</div>' +
          '<div class="kt-card" style="max-width:none;margin-top:16px;">' +
            '<div style="font-weight:700;color:#0F172A;margin-bottom:8px;">📧 Heads-up email</div>' +
            '<p style="color:#64748B;font-size:13px;margin:0 0 10px;">Email the maintenance notice. Send yourself a test first, then notify everyone when ready.</p>' +
            '<div style="display:flex;gap:10px;flex-wrap:wrap;align-items:center;">' +
              '<input type="email" id="mw-test-email" value="mr.anthonyhosein@gmail.com" style="' + inp + 'max-width:300px;">' +
              '<button id="mw-test" style="background:#F1F5F9;color:#1F2937;border:none;border-radius:9px;padding:9px 16px;font-weight:700;cursor:pointer;">Send test</button>' +
              '<button id="mw-notify" style="background:#FEF2F2;color:#B91C1C;border:1px solid #FECACA;border-radius:9px;padding:9px 16px;font-weight:700;cursor:pointer;">Email all users</button>' +
            '</div>' +
            '<div id="mw-mail-out" style="font-size:13px;margin-top:10px;min-height:16px;"></div>' +
            (w.notified_at ? '<div style="font-size:12px;color:#94A3B8;margin-top:6px;">All-user notice last sent ' + esc(fmtDate(w.notified_at)) + '</div>' : '') +
          '</div>' +
        '</div>';

      var out = container.querySelector('#mw-out');
      container.querySelector('#mw-save').onclick = function () {
        var body = { active: container.querySelector('#mw-active').checked, starts_at: container.querySelector('#mw-start').value || null, ends_at: container.querySelector('#mw-end').value || null, message: container.querySelector('#mw-msg').value || null };
        out.style.color = '#64748B'; out.textContent = 'Saving…';
        mapi('/platform/maintenance', 'POST', body).then(function (r) { out.style.color = '#047857'; out.textContent = r.is_down_now ? '✓ Saved — maintenance is ACTIVE now.' : '✓ Saved.'; setTimeout(function () { renderMaintenance(container); }, 800); }).catch(function (e) { out.style.color = '#B91C1C'; out.textContent = '✗ ' + e.message; });
      };
      var mailOut = container.querySelector('#mw-mail-out');
      container.querySelector('#mw-test').onclick = function () {
        var em = (container.querySelector('#mw-test-email').value || '').trim(); if (!em) { mailOut.style.color = '#B91C1C'; mailOut.textContent = 'Enter an email.'; return; }
        mailOut.style.color = '#64748B'; mailOut.textContent = 'Sending test…';
        mapi('/platform/maintenance/test', 'POST', { email: em }).then(function (r) { mailOut.style.color = '#047857'; mailOut.textContent = '✓ ' + r.message; }).catch(function (e) { mailOut.style.color = '#B91C1C'; mailOut.textContent = '✗ ' + e.message; });
      };
      container.querySelector('#mw-notify').onclick = async function () {
        if (window.KT && KT.confirm && !(await KT.confirm('Email the maintenance notice to ALL users right now?'))) return;
        mailOut.style.color = '#64748B'; mailOut.textContent = 'Emailing all users…';
        mapi('/platform/maintenance/notify-all', 'POST', {}).then(function (r) { mailOut.style.color = '#047857'; mailOut.textContent = '✓ ' + r.message; }).catch(function (e) { mailOut.style.color = '#B91C1C'; mailOut.textContent = '✗ ' + e.message; });
      };
    }).catch(function (e) {
      container.innerHTML = '<div style="padding:24px;color:#B91C1C;">Could not load maintenance settings: ' + esc(e.message || 'error') + '</div>';
    });
  }

  // Crash reports — recent 5xx errors, who hit them and on what endpoint.
  function renderCrashReports(sec, cr) {
    var reports = (cr && cr.reports) || [], c24 = (cr && cr.count_24h) || 0;
    var when = function (v) { try { return (window.KT && KT.Fmt && KT.Fmt.relative) ? KT.Fmt.relative(v) : String(v || ''); } catch (e) { return ''; } };
    var rows = reports.map(function (r) {
      return '<tr style="border-top:1px solid #F1F5F9;">'
        + '<td style="padding:8px 10px;white-space:nowrap;color:#64748B;font-size:12px;">' + esc(when(r.at)) + '</td>'
        + '<td style="padding:8px 10px;font-weight:600;">' + esc(r.who || 'Unknown') + (r.agency ? '<div style="font-size:11px;color:#94A3B8;font-weight:400;">' + esc(r.agency) + '</div>' : '') + '</td>'
        + '<td style="padding:8px 10px;"><span style="font-size:11px;font-weight:800;color:#B91C1C;background:#FEE2E2;border-radius:5px;padding:1px 6px;">' + esc(String(r.status || '500')) + '</span> '
        + '<span style="font-family:ui-monospace,Menlo,monospace;font-size:12px;color:#334155;">' + esc((r.method || '') + ' ' + (r.path || '')) + '</span>'
        + (r.input ? '<div style="font-family:ui-monospace,Menlo,monospace;font-size:11px;color:#94A3B8;margin-top:2px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;max-width:460px;">' + esc(r.input) + '</div>' : '')
        + '</td></tr>';
    }).join('');
    sec.innerHTML = '<div style="background:#fff;border:1px solid #E7EDF3;border-radius:14px;overflow:hidden;">'
      + '<div style="display:flex;justify-content:space-between;align-items:center;padding:14px 16px;border-bottom:1px solid #F1F5F9;">'
      + '<h3 style="margin:0;font-size:16px;font-weight:800;">💥 Crash reports</h3>'
      + '<span style="font-size:12px;font-weight:700;color:' + (c24 ? '#B91C1C' : '#16A34A') + ';">' + c24 + ' in last 24h</span></div>'
      + (reports.length
        ? '<div style="overflow-x:auto;"><table style="width:100%;border-collapse:collapse;font-size:13px;"><thead><tr style="background:#F8FAFC;color:#64748B;font-size:11px;text-transform:uppercase;"><th style="text-align:left;padding:8px 10px;">When</th><th style="text-align:left;padding:8px 10px;">Who</th><th style="text-align:left;padding:8px 10px;">Error</th></tr></thead><tbody>' + rows + '</tbody></table></div>'
        : '<div style="padding:24px;text-align:center;color:#16A34A;">✓ No server errors recorded. All clear.</div>')
      + '</div>';
  }

  if (Shell && Shell.registerScreen) {
    // Register for every role that has sidebar access — server gates by middleware.
    ['agency_admin', 'centre_director', 'platform_admin'].forEach(function (r) {
      Shell.registerScreen(r + ':platform-overview', renderOverview);
      Shell.registerScreen(r + ':platform-agencies', renderAgencies);
    });
    Shell.registerScreen('platform_admin:maintenance', renderMaintenance);
    // Expose the email-log renderer so the Audit log screen can host it as a subtab.
    try { if (window.KT) window.KT.EmailLog = { render: renderEmailLogScreen }; } catch (e) {}
  }
  KT.PlatformScreens = { overview: renderOverview, agencies: renderAgencies, maintenance: renderMaintenance };
})(window);
