/* ═══════════════════════════════════════════════════════════════════
   KIDDIETRAC v15 — MRR Dashboard (platform_admin only)
   ─────────────────────────────────────────────────────────────────
   Hits GET /admin/mrr/overview and renders:
   - Headline cards (MRR, ARR, active agencies, churn)
   - 12-month MRR sparkline (inline SVG, no chart lib)
   - Breakdown by plan
   - Top agencies by revenue
   - Agency list with status filter
   ═══════════════════════════════════════════════════════════════════ */
(function (window) {
  'use strict';

  function token() { return sessionStorage.getItem('kt_token') || localStorage.getItem('kt_token'); }
  function apiBase() { return (window.KT && window.KT.API_BASE) || 'https://api.kiddietrac.com/api/v1'; }
  async function api(method, path) {
    const res = await fetch(apiBase() + path, { method, headers: { 'Authorization': 'Bearer ' + token(), 'Accept': 'application/json' } });
    const json = await res.json().catch(() => ({}));
    if (!res.ok) throw new Error(json.message || ('API ' + res.status));
    return json;
  }
  function esc(s) { return s == null ? '' : String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;'); }
  function $(s, r) { return (r || document).querySelector(s); }
  function money(cents, ccy) {
    ccy = ccy || 'CAD';
    const n = (cents || 0) / 100;
    return n.toLocaleString('en-CA', { style: 'currency', currency: ccy, maximumFractionDigits: 0 });
  }

  async function render(container) {
    container.innerHTML = '<div style="padding:32px;text-align:center;color:#6B7280;">Loading MRR dashboard…</div>';
    let data;
    try { data = await api('GET', '/admin/mrr/overview'); }
    catch (e) {
      container.innerHTML = '<div style="padding:24px;color:#DC2626;">' + esc(e.message) + '</div>';
      return;
    }

    if (data.configured === false) {
      container.innerHTML = `
        <div style="padding:48px;max-width:720px;margin:0 auto;">
          <div style="background:#FEF3C7;border-left:4px solid #F59E0B;padding:20px;border-radius:8px;">
            <h2 style="margin:0 0 8px;color:#92400E;">⚙️ MRR tracking not yet configured</h2>
            <p style="margin:0;color:#78350F;font-size:14px;">
              The reseller migration hasn't been run yet, or no agencies have plan_amount_cents set.
              Run <code>php artisan migrate</code> on the server and assign plans via Feature Flags screen.
            </p>
          </div>
        </div>`;
      return;
    }

    const ccy = data.currency || 'CAD';
    container.innerHTML = `
      <div style="padding:24px;max-width:1800px;">
        <h2 style="font-size:24px;margin:0 0 4px;">💰 MRR Dashboard</h2>
        <p style="color:#6B7280;font-size:13px;margin:0 0 20px;">Monthly recurring revenue across all paying agencies</p>

        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:14px;margin-bottom:20px;">
          ${card('MRR',          money(data.mrr_cents, ccy),                '#3BBBBE')}
          ${card('ARR',          money(data.arr_cents, ccy),                '#16A34A')}
          ${card('Active',       (data.agencies_active || 0) + ' agencies', '#7C3AED')}
          ${card('Trial',        (data.agencies_trial  || 0) + ' agencies', '#F59E0B')}
          ${card('Churn 30d',    (data.churn_rate_pct || 0).toFixed(1) + '%', '#DC2626')}
          ${card('ARPU',         money(data.arpu_cents || 0, ccy),          '#0891B2')}
        </div>

        ${sparklineBlock(data.mrr_history_12mo || [], ccy)}

        <div style="display:grid;grid-template-columns:1fr 1fr;gap:18px;margin-top:20px;" id="kt-mrr-grid">
          <div style="background:white;border-radius:14px;padding:18px;box-shadow:0 1px 4px rgba(0,0,0,.05);">
            <h3 style="margin:0 0 12px;font-size:15px;">By plan</h3>
            ${planBreakdownTable(data.by_plan || [], ccy)}
          </div>
          <div style="background:white;border-radius:14px;padding:18px;box-shadow:0 1px 4px rgba(0,0,0,.05);">
            <h3 style="margin:0 0 12px;font-size:15px;">Top agencies by revenue</h3>
            ${topAgenciesTable(data.top_agencies || [], ccy)}
          </div>
        </div>

        <div style="background:white;border-radius:14px;padding:18px;box-shadow:0 1px 4px rgba(0,0,0,.05);margin-top:20px;">
          <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px;flex-wrap:wrap;gap:8px;">
            <h3 style="margin:0;font-size:15px;">All agencies</h3>
            <select id="kt-status-filter" style="padding:8px 10px;border:1px solid #D1D5DB;border-radius:6px;font-size:13px;">
              <option value="">All statuses</option>
              <option value="active">Active</option>
              <option value="trial">Trial</option>
              <option value="cancelled">Cancelled</option>
            </select>
          </div>
          <div id="kt-agency-list"><div style="color:#6B7280;font-size:13px;">Loading…</div></div>
        </div>
      </div>
    `;

    loadAgencyList(container, '');
    $('#kt-status-filter', container).addEventListener('change', (e) => loadAgencyList(container, e.target.value));
  }

  async function loadAgencyList(container, status) {
    const target = $('#kt-agency-list', container);
    target.innerHTML = '<div style="color:#6B7280;font-size:13px;">Loading…</div>';
    try {
      const q = status ? '?status=' + encodeURIComponent(status) + '&limit=100' : '?limit=100';
      const data = await api('GET', '/admin/mrr/agencies' + q);
      const rows = data.agencies || [];
      if (rows.length === 0) { target.innerHTML = '<div style="color:#6B7280;font-size:13px;padding:12px;">No agencies match.</div>'; return; }
      target.innerHTML = `
        <table style="width:100%;border-collapse:collapse;font-size:13px;">
          <thead><tr style="background:#F9FAFB;">
            <th style="${th()}">Agency</th>
            <th style="${th()}">Plan</th>
            <th style="${th()}">MRR</th>
            <th style="${th()}">Status</th>
            <th style="${th()}">Since</th>
            <th style="${th()}"></th>
          </tr></thead>
          <tbody>
            ${rows.map(a => `<tr style="border-top:1px solid #F3F4F6;">
              <td style="${td()}"><strong>${esc(a.name || ('#' + a.id))}</strong></td>
              <td style="${td()}">${esc(a.plan_code || '—')}</td>
              <td style="${td()}">${money(a.plan_amount_cents || 0, a.plan_currency || 'CAD')}</td>
              <td style="${td()}">${statusPill(a.billing_status)}</td>
              <td style="${td()}">${esc(a.billing_starts_at || '—')}</td>
              <td style="${td()}"><a href="#admin-features/${a.id}" style="color:#3BBBBE;text-decoration:none;font-weight:600;">⚙ Manage</a></td>
            </tr>`).join('')}
          </tbody>
        </table>`;
    } catch (e) {
      target.innerHTML = '<div style="color:#DC2626;font-size:13px;">' + esc(e.message) + '</div>';
    }
  }

  function card(label, value, color) {
    return `<div style="background:white;border-radius:14px;padding:18px;box-shadow:0 1px 4px rgba(0,0,0,.05);border-left:4px solid ${color};">
      <div style="font-size:11px;font-weight:700;color:#6B7280;text-transform:uppercase;letter-spacing:1px;">${label}</div>
      <div style="font-size:22px;font-weight:700;color:#111827;margin-top:6px;">${value}</div>
    </div>`;
  }

  function sparklineBlock(history, ccy) {
    if (!history || history.length === 0) return '';
    const W = 1100, H = 140, PAD = 30;
    const values = history.map(h => h.mrr_cents || 0);
    const max = Math.max(...values, 1);
    const step = (W - PAD * 2) / Math.max(history.length - 1, 1);
    const pts = history.map((h, i) => {
      const x = PAD + i * step;
      const y = H - PAD - ((h.mrr_cents || 0) / max) * (H - PAD * 2);
      return [x, y];
    });
    const linePath = pts.map((p, i) => (i === 0 ? 'M' : 'L') + p[0].toFixed(1) + ',' + p[1].toFixed(1)).join(' ');
    const areaPath = linePath + ' L' + pts[pts.length-1][0].toFixed(1) + ',' + (H - PAD) + ' L' + pts[0][0].toFixed(1) + ',' + (H - PAD) + ' Z';

    return `<div style="background:white;border-radius:14px;padding:18px;box-shadow:0 1px 4px rgba(0,0,0,.05);">
      <div style="display:flex;justify-content:space-between;align-items:baseline;margin-bottom:8px;">
        <h3 style="margin:0;font-size:15px;">MRR — last 12 months</h3>
        <span style="font-size:12px;color:#6B7280;">peak ${money(max, ccy)}</span>
      </div>
      <svg viewBox="0 0 ${W} ${H}" preserveAspectRatio="none" style="width:100%;height:140px;display:block;">
        <path d="${areaPath}" fill="#3BBBBE" opacity="0.12"/>
        <path d="${linePath}" stroke="#3BBBBE" stroke-width="2" fill="none" stroke-linecap="round" stroke-linejoin="round"/>
        ${pts.map(p => `<circle cx="${p[0].toFixed(1)}" cy="${p[1].toFixed(1)}" r="3" fill="#3BBBBE"/>`).join('')}
      </svg>
      <div style="display:flex;justify-content:space-between;font-size:11px;color:#9CA3AF;margin-top:4px;">
        ${history.map(h => `<span>${esc(h.month_label || h.month || '')}</span>`).join('')}
      </div>
    </div>`;
  }

  function planBreakdownTable(rows, ccy) {
    if (rows.length === 0) return '<div style="color:#6B7280;font-size:13px;">No plan data.</div>';
    return `<table style="width:100%;border-collapse:collapse;font-size:13px;">
      <thead><tr style="background:#F9FAFB;">
        <th style="${th()}">Plan</th>
        <th style="${th()}">Count</th>
        <th style="${th()}">MRR</th>
      </tr></thead>
      <tbody>${rows.map(r => `<tr style="border-top:1px solid #F3F4F6;">
        <td style="${td()}"><strong>${esc(r.plan_code || '—')}</strong></td>
        <td style="${td()}">${r.agency_count || 0}</td>
        <td style="${td()}">${money(r.mrr_cents || 0, ccy)}</td>
      </tr>`).join('')}</tbody>
    </table>`;
  }

  function topAgenciesTable(rows, ccy) {
    if (rows.length === 0) return '<div style="color:#6B7280;font-size:13px;">No data yet.</div>';
    return `<table style="width:100%;border-collapse:collapse;font-size:13px;">
      <thead><tr style="background:#F9FAFB;">
        <th style="${th()}">Agency</th>
        <th style="${th()}">MRR</th>
        <th style="${th()}">Months</th>
      </tr></thead>
      <tbody>${rows.map(r => `<tr style="border-top:1px solid #F3F4F6;">
        <td style="${td()}"><strong>${esc(r.name || ('#' + r.id))}</strong></td>
        <td style="${td()}">${money(r.plan_amount_cents || 0, r.plan_currency || ccy)}</td>
        <td style="${td()}">${r.months_active || 0}</td>
      </tr>`).join('')}</tbody>
    </table>`;
  }

  function statusPill(status) {
    const m = {
      active:    { bg: '#DCFCE7', fg: '#166534', txt: 'Active' },
      trial:     { bg: '#FEF3C7', fg: '#92400E', txt: 'Trial' },
      cancelled: { bg: '#FEE2E2', fg: '#991B1B', txt: 'Cancelled' },
      past_due:  { bg: '#FED7AA', fg: '#9A3412', txt: 'Past due' },
    };
    const b = m[status] || { bg: '#E5E7EB', fg: '#374151', txt: status || '—' };
    return `<span style="background:${b.bg};color:${b.fg};padding:3px 9px;border-radius:10px;font-size:11px;font-weight:700;">${b.txt}</span>`;
  }

  function th() { return 'text-align:left;padding:10px 12px;font-size:11px;font-weight:700;color:#6B7280;text-transform:uppercase;letter-spacing:1px;'; }
  function td() { return 'padding:10px 12px;color:#374151;'; }

  window.KT = window.KT || {};
  window.KT.AdminMrr = { render: render };
})(window);
