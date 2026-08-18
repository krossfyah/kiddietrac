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

      // v22p32: insight row — top agencies (left) + recent events (right)
      var bottom = Dom.el('div', { style: 'display:grid;grid-template-columns:repeat(auto-fit,minmax(380px,1fr));gap:14px;margin-top:14px;' });
      bottom.appendChild(renderTopAgenciesCard(data.top_agencies || []));
      bottom.appendChild(renderRecentEventsCard(data.recent_events || []));
      wrap.appendChild(bottom);
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
    var emailLogBtn = Dom.el('button', { style: 'background:white;color:#374151;border:1px solid #D1D5DB;padding:10px 16px;border-radius:8px;font-weight:600;cursor:pointer;font-size:14px;' }, '📧 Email log');
    emailLogBtn.addEventListener('click', function () { showEmailLog(); });
    barRight.appendChild(emailLogBtn);
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
      '<div style="background:#F0FDF4;border:1px solid #BBF7D0;border-radius:10px;padding:14px 16px;margin-bottom:16px;">' +
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
            return '<tr style="border-top:1px solid #F3F4F6;">' +
              '<td style="padding:9px 14px;color:#6B7280;white-space:nowrap;">' + esc(fmtDate(l.created_at)) + '</td>' +
              '<td style="padding:9px 14px;">' + esc((l.to_name ? l.to_name + ' ' : '') + '<' + (l.to_email || '') + '>') + '</td>' +
              '<td style="padding:9px 14px;">' + esc(l.subject || '—') + '</td>' +
              '<td style="padding:9px 14px;color:#6B7280;">' + esc(l.mailer || '—') + '</td>' +
              '<td style="padding:9px 14px;white-space:nowrap;">' + opened + '</td>' +
            '</tr>';
          }).join('') + '</tbody>' +
        '</table>';
    }).catch(function (e) {
      m.querySelector('#el-body').innerHTML = '<div style="padding:24px;color:#DC2626;">Could not load: ' + esc(e.message || 'error') + '</div>';
    });
  }

  if (Shell && Shell.registerScreen) {
    // Register for every role that has sidebar access — server gates by middleware.
    ['agency_admin', 'centre_director', 'platform_admin'].forEach(function (r) {
      Shell.registerScreen(r + ':platform-overview', renderOverview);
      Shell.registerScreen(r + ':platform-agencies', renderAgencies);
    });
  }
  KT.PlatformScreens = { overview: renderOverview, agencies: renderAgencies };
})(window);
