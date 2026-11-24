/* ═══════════════════════════════════════════════════════════════════
   KiddieTrac — MRR dashboard (platform_admin only), rebuilt 2026-10-25.
   Anthony: "update MRR dashboard to look better with better information and visuals etc - full sweep".

   Reads GET /admin/mrr/insights, which counts what actually bills: live PAID licence lines normalised to a
   month (agencies on a plan price fall back to it). Complimentary lines are shown as complimentary value and
   trials as trial pipeline - never as MRR. Collections and receivables come from KiddieTrac's own invoices
   and payments. Charts are inline SVG (no chart library).
   ═══════════════════════════════════════════════════════════════════ */
(function (window) {
  'use strict';

  function token() { return sessionStorage.getItem('kt_token') || localStorage.getItem('kt_token'); }
  function apiBase() { return (window.KT && window.KT.API_BASE) || 'https://api.kiddietrac.com/api/v1'; }
  async function api(path) {
    const res = await fetch(apiBase() + path, { headers: { Authorization: 'Bearer ' + token(), Accept: 'application/json' } });
    const json = await res.json().catch(() => ({}));
    if (!res.ok) throw new Error(json.message || ('API ' + res.status));
    return json;
  }
  function esc(s) { return s == null ? '' : String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;'); }
  function money(c, dp) { return ((c || 0) / 100).toLocaleString('en-CA', { style: 'currency', currency: 'CAD', minimumFractionDigits: dp ? 2 : 0, maximumFractionDigits: dp ? 2 : 0 }); }
  function short(c) { var n = (c || 0) / 100; return n >= 1000000 ? '$' + (n / 1000000).toFixed(1) + 'M' : n >= 1000 ? '$' + (n / 1000).toFixed(1) + 'k' : '$' + Math.round(n); }
  function day(d) { if (!d) return '—'; var q = String(d).slice(0, 10).split('-'); return new Date(Date.UTC(+q[0], +q[1] - 1, +q[2], 12)).toLocaleDateString('en-CA', { month: 'short', day: 'numeric', year: 'numeric', timeZone: 'UTC' }); }

  var CSS = ''
    + '.mrr{--ink:#0F172A;--mut:#64748B;--line:#E5E9F0;}'
    + '.mrr .row{display:grid;gap:14px;margin-bottom:14px;}'
    + '.mrr .k4{grid-template-columns:repeat(auto-fit,minmax(200px,1fr));}'
    + '.mrr .c2{grid-template-columns:repeat(auto-fit,minmax(340px,1fr));}'
    + '.mrr .c3{grid-template-columns:2fr 1fr;}@media(max-width:900px){.mrr .c3{grid-template-columns:1fr;}}'
    + '.mrr .hero-k{border-radius:16px;padding:16px 18px;color:#fff;position:relative;overflow:hidden;box-shadow:0 12px 26px -18px rgba(37,99,235,.9);}'
    + '.mrr .hero-k::after{content:"";position:absolute;width:120px;height:120px;border-radius:50%;right:-30px;top:-40px;background:rgba(255,255,255,.14);}'
    + '.mrr .hero-k b{display:block;font-size:28px;font-weight:800;letter-spacing:-.02em;margin-top:4px;}'
    + '.mrr .hero-k span{font-size:11.5px;font-weight:800;letter-spacing:1.2px;text-transform:uppercase;opacity:.9;}'
    + '.mrr .hero-k em{display:block;font-style:normal;font-size:12px;opacity:.88;margin-top:2px;}'
    + '.mrr .k{background:#fff;border:1px solid var(--line);border-radius:14px;padding:14px 16px;}'
    + '.mrr .k span{font-size:11px;font-weight:800;letter-spacing:1px;text-transform:uppercase;color:var(--mut);}'
    + '.mrr .k b{display:block;font-size:22px;font-weight:800;color:var(--ink);margin-top:4px;}'
    + '.mrr .k em{display:block;font-style:normal;font-size:12px;color:var(--mut);margin-top:2px;}'
    + '.mrr .card{background:#fff;border:1px solid var(--line);border-radius:16px;padding:16px 18px;min-width:0;}'
    + '.mrr .card h3{margin:0 0 2px;font-size:14.5px;font-weight:800;color:var(--ink);}'
    + '.mrr .card .sub{font-size:12px;color:var(--mut);margin-bottom:10px;}'
    + '.mrr table{width:100%;border-collapse:collapse;font-size:13px;}'
    + '.mrr th{text-align:left;font-size:10.5px;font-weight:800;letter-spacing:.8px;text-transform:uppercase;color:var(--mut);padding:7px 8px;border-bottom:1px solid var(--line);}'
    + '.mrr td{padding:8px;border-bottom:1px solid #F1F4F8;vertical-align:middle;}'
    + '.mrr .chip{display:inline-block;font-size:10.5px;font-weight:800;padding:2px 8px;border-radius:999px;}'
    + '.mrr .leg{display:flex;gap:14px;flex-wrap:wrap;font-size:12px;color:var(--mut);margin-top:6px;}'
    + '.mrr .leg i{display:inline-block;width:10px;height:10px;border-radius:3px;margin-right:5px;vertical-align:-1px;}'
    // Phones: two compact tiles per row instead of one tall card each (2026-10-30).
    + '@media(max-width:640px){.mrr .row{gap:8px;margin-bottom:10px;}.mrr .k4{grid-template-columns:repeat(2,minmax(0,1fr));}.mrr .hero-k{border-radius:12px;padding:10px 12px;box-shadow:0 8px 18px -14px rgba(37,99,235,.9);}.mrr .hero-k::after{width:64px;height:64px;right:-18px;top:-24px;}.mrr .hero-k b{font-size:19px;margin-top:2px;}.mrr .hero-k b small{font-size:11px!important;}.mrr .hero-k span{font-size:9.5px;letter-spacing:.8px;}.mrr .hero-k em{font-size:10.5px;line-height:1.3;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden;}.mrr .k{border-radius:12px;padding:9px 11px;}.mrr .k b{font-size:17px;margin-top:2px;}.mrr .k span{font-size:9.5px;letter-spacing:.6px;}.mrr .k em{font-size:10.5px;line-height:1.3;}.mrr .card{border-radius:12px;padding:12px;}}'
    + '.mrr .note{background:linear-gradient(135deg,#F5F3FF,#EFF6FF);border:1px solid #DDD6FE;border-radius:14px;padding:12px 16px;font-size:13px;color:#4C1D95;margin-bottom:14px;}';
  var G = ['linear-gradient(135deg,#1DB9C3,#2563EB)', 'linear-gradient(135deg,#2563EB,#7C3AED)', 'linear-gradient(135deg,#0EA5E9,#1DB9C3)', 'linear-gradient(135deg,#7C3AED,#C026D3)'];
  var PAL = ['#2563EB', '#1DB9C3', '#7C3AED', '#F59E0B', '#10B981', '#EF4444', '#64748B'];
  var TYPE = { paid: ['Paid', '#166534', '#DCFCE7'], complimentary: ['Complimentary', '#6D28D9', '#EDE9FE'], trial: ['Trial', '#92400E', '#FEF3C7'] };

  /* ── charts ── */
  function trend(hist) {
    var W = 640, H = 220, P = { l: 46, r: 12, t: 12, b: 26 };
    var top0 = Math.max.apply(null, hist.map(function (h) { return Math.max(h.mrr, h.comp); }).concat([0]));
    if (top0 <= 0) return '<div style="height:180px;display:flex;align-items:center;justify-content:center;border:1px dashed #E2E8F0;border-radius:12px;color:#94A3B8;font-size:13px;">No licence revenue recorded yet.</div>';
    var max = Math.max(1, top0);
    var x = function (i) { return P.l + i * (W - P.l - P.r) / Math.max(1, hist.length - 1); };
    var y = function (v) { return H - P.b - v / max * (H - P.t - P.b); };
    var pts = hist.map(function (h, i) { return x(i) + ',' + y(h.mrr); }).join(' ');
    var cpts = hist.map(function (h, i) { return x(i) + ',' + y(h.comp); }).join(' ');
    var g = '';
    for (var t = 0; t <= 4; t++) { var v = max * t / 4, yy = y(v); g += '<line x1="' + P.l + '" x2="' + (W - P.r) + '" y1="' + yy + '" y2="' + yy + '" stroke="#EEF2F7"/><text x="' + (P.l - 6) + '" y="' + (yy + 4) + '" font-size="10" fill="#94A3B8" text-anchor="end">' + short(v) + '</text>'; }
    var lab = hist.map(function (h, i) { return '<text x="' + x(i) + '" y="' + (H - 8) + '" font-size="10" fill="#94A3B8" text-anchor="middle">' + esc(h.label) + '</text>'; }).join('');
    var last = hist[hist.length - 1] || { mrr: 0 };
    return '<svg viewBox="0 0 ' + W + ' ' + H + '" style="width:100%;height:auto;display:block;"><defs><linearGradient id="mrrA" x1="0" y1="0" x2="0" y2="1"><stop offset="0" stop-color="#2563EB" stop-opacity=".35"/><stop offset="1" stop-color="#2563EB" stop-opacity="0"/></linearGradient>'
      + '<linearGradient id="mrrL" x1="0" x2="1"><stop offset="0" stop-color="#1DB9C3"/><stop offset=".55" stop-color="#2563EB"/><stop offset="1" stop-color="#7C3AED"/></linearGradient></defs>' + g
      + '<polygon points="' + x(0) + ',' + y(0) + ' ' + pts + ' ' + x(hist.length - 1) + ',' + y(0) + '" fill="url(#mrrA)"/>'
      + '<polyline points="' + cpts + '" fill="none" stroke="#A78BFA" stroke-width="2" stroke-dasharray="5 4"/>'
      + '<polyline points="' + pts + '" fill="none" stroke="url(#mrrL)" stroke-width="3" stroke-linejoin="round" stroke-linecap="round"/>'
      + '<circle cx="' + x(hist.length - 1) + '" cy="' + y(last.mrr) + '" r="4.5" fill="#fff" stroke="#2563EB" stroke-width="2.5"/>' + lab + '</svg>';
  }
  function donut(items, valueKey, centre, centreSub) {
    var tot = items.reduce(function (n, x) { return n + (x[valueKey] || 0); }, 0);
    var R = 70, C = 2 * Math.PI * R, off = 0, segs = '';
    if (!tot) segs = '<circle r="' + R + '" cx="90" cy="90" fill="none" stroke="#EEF2F7" stroke-width="22"/>';
    items.forEach(function (x, i) {
      var v = x[valueKey] || 0; if (!v) return;
      var len = v / tot * C;
      segs += '<circle r="' + R + '" cx="90" cy="90" fill="none" stroke="' + PAL[i % PAL.length] + '" stroke-width="22" stroke-dasharray="' + len + ' ' + (C - len) + '" stroke-dashoffset="' + (-off) + '" transform="rotate(-90 90 90)"/>';
      off += len;
    });
    return '<svg viewBox="0 0 180 180" style="width:170px;height:170px;flex:0 0 auto;">' + segs
      + '<text x="90" y="88" text-anchor="middle" font-size="18" font-weight="800" fill="#0F172A">' + esc(centre) + '</text>'
      + '<text x="90" y="106" text-anchor="middle" font-size="10.5" fill="#64748B">' + esc(centreSub) + '</text></svg>';
  }
  function bars(rows) {
    var W = 640, H = 200, P = { l: 46, r: 8, t: 10, b: 26 };
    var top0 = Math.max.apply(null, rows.map(function (r) { return Math.max(r.invoiced, r.collected); }).concat([0]));
    // Nothing invoiced or collected yet: say so, rather than an axis of $0 labels.
    if (top0 <= 0) return '<div style="height:150px;display:flex;align-items:center;justify-content:center;border:1px dashed #E2E8F0;border-radius:12px;color:#94A3B8;font-size:13px;">No paid invoices or payments yet in the last 12 months.</div>';
    var max = Math.max(1, top0);
    var bw = (W - P.l - P.r) / rows.length;
    var y = function (v) { return H - P.b - Math.max(0, v) / max * (H - P.t - P.b); };
    var s = '';
    for (var t = 0; t <= 4; t++) { var v = max * t / 4; s += '<line x1="' + P.l + '" x2="' + (W - P.r) + '" y1="' + y(v) + '" y2="' + y(v) + '" stroke="#EEF2F7"/><text x="' + (P.l - 6) + '" y="' + (y(v) + 4) + '" font-size="10" fill="#94A3B8" text-anchor="end">' + short(v) + '</text>'; }
    rows.forEach(function (r, i) {
      var x0 = P.l + i * bw + bw * .18, w = bw * .3;
      s += '<rect x="' + x0 + '" y="' + y(r.invoiced) + '" width="' + w + '" height="' + (H - P.b - y(r.invoiced)) + '" rx="3" fill="#BFDBFE"/>'
        + '<rect x="' + (x0 + w + 2) + '" y="' + y(r.collected) + '" width="' + w + '" height="' + (H - P.b - y(r.collected)) + '" rx="3" fill="#2563EB"/>'
        + '<text x="' + (P.l + i * bw + bw / 2) + '" y="' + (H - 8) + '" font-size="10" fill="#94A3B8" text-anchor="middle">' + esc(r.label) + '</text>';
    });
    return '<svg viewBox="0 0 ' + W + ' ' + H + '" style="width:100%;height:auto;display:block;">' + s + '</svg>';
  }
  function stack(parts) {
    var tot = parts.reduce(function (n, p) { return n + p[1]; }, 0);
    if (!tot) return '<div style="height:14px;border-radius:999px;background:#EEF2F7;"></div><div class="sub" style="margin-top:8px;">Nothing owing.</div>';
    return '<div style="display:flex;height:14px;border-radius:999px;overflow:hidden;">' + parts.map(function (p) { return p[1] ? '<div title="' + esc(p[0] + ' ' + money(p[1])) + '" style="width:' + (p[1] / tot * 100) + '%;background:' + p[2] + ';"></div>' : ''; }).join('') + '</div>'
      + '<div class="leg">' + parts.map(function (p) { return '<span><i style="background:' + p[2] + '"></i>' + esc(p[0]) + ' ' + money(p[1]) + '</span>'; }).join('') + '</div>';
  }
  function meter(v, max, col) { return '<div style="height:6px;border-radius:999px;background:#EEF2F7;min-width:60px;"><div style="height:6px;border-radius:999px;width:' + (max ? Math.max(2, v / max * 100) : 0) + '%;background:' + col + ';"></div></div>'; }

  async function render(container) {
    if (!document.getElementById('kt-mrr-css')) { var st = document.createElement('style'); st.id = 'kt-mrr-css'; st.textContent = CSS; document.head.appendChild(st); }
    container.innerHTML = '<div class="kt-hero"><h2>💰 MRR dashboard</h2><p>Recurring revenue, complimentary value, collections and renewals across KiddieTrac.</p></div><div style="padding:24px;color:#64748B;">Loading…</div>';
    var d;
    try { d = await api('/admin/mrr/insights'); } catch (e) { container.innerHTML = '<div class="kt-hero"><h2>💰 MRR dashboard</h2></div><div style="padding:20px;color:#B91C1C;">' + esc(e.message) + '</div>'; return; }
    var k = d.kpi || {};
    var h = '<div class="kt-hero"><h2>💰 MRR dashboard</h2><p>Recurring revenue, complimentary value, collections and renewals across KiddieTrac · as of ' + esc(new Date(d.as_of).toLocaleString('en-CA', { dateStyle: 'medium', timeStyle: 'short' })) + '</p></div><div class="mrr">';

    h += '<div class="row k4">'
      + '<div class="hero-k" style="background:' + G[0] + '"><span>MRR</span><b>' + money(k.mrr) + '</b><em>' + (k.net_new ? (k.net_new > 0 ? '▲ ' : '▼ ') + money(Math.abs(k.net_new)) + ' net new this month' : 'No change this month') + '</em></div>'
      + '<div class="hero-k" style="background:' + G[1] + '"><span>ARR</span><b>' + money(k.arr) + '</b><em>MRR × 12</em></div>'
      + '<div class="hero-k" style="background:' + G[2] + '"><span>Paying agencies</span><b>' + (k.paying || 0) + ' <small style="font-size:14px;opacity:.85;">of ' + (k.customers || 0) + '</small></b><em>' + (k.complimentary || 0) + ' complimentary · ' + (k.trials || 0) + ' on trial</em></div>'
      + '<div class="hero-k" style="background:' + G[3] + '"><span>ARPA</span><b>' + money(k.arpa) + '</b><em>Average revenue per paying agency / month</em></div>'
      + '</div>';
    h += '<div class="row k4">'
      + '<div class="k"><span>Complimentary value</span><b>' + money(k.comp_value) + '<small style="font-size:12px;color:#64748B;"> /mo</small></b><em>' + money(k.comp_value * 12) + ' a year at list price, not charged</em></div>'
      + '<div class="k"><span>Trial pipeline</span><b>' + money(k.trial_value) + '<small style="font-size:12px;color:#64748B;"> /mo</small></b><em>' + (k.trials || 0) + ' agenc' + (k.trials === 1 ? 'y' : 'ies') + ' on trial at list price</em></div>'
      + '<div class="k"><span>Collected this month</span><b>' + money(k.collected_month) + '</b><em>' + money(k.invoiced_month) + ' invoiced</em></div>'
      + '<div class="k" style="' + (k.overdue ? 'border-color:#FCA5A5;background:#FFF7F7;' : '') + '"><span>Outstanding</span><b>' + money(k.outstanding) + '</b><em>' + (k.overdue ? money(k.overdue) + ' overdue on ' + k.overdue_count + ' invoice' + (k.overdue_count === 1 ? '' : 's') : 'Nothing overdue') + '</em></div>'
      + '</div>';
    if (!k.mrr && k.comp_value) {
      h += '<div class="note">✨ No paying customers yet. ' + (k.complimentary || 0) + ' agenc' + (k.complimentary === 1 ? 'y is' : 'ies are') + ' on complimentary licences worth <b>' + money(k.comp_value) + ' a month</b> (' + money(k.comp_value * 12) + ' a year) at list price, recorded on their invoices as KT-CMP-0001.</div>';
    }

    var plans = (d.plans || []).slice().sort(function (a, b) { return (b.mrr + b.comp) - (a.mrr + a.comp); });
    var planVal = plans.map(function (p) { return Object.assign({ v: p.mrr || p.comp }, p); });
    h += '<div class="row c3">'
      + '<div class="card"><h3>MRR, last 12 months</h3><div class="sub">Paid licences normalised to a month · the dashed line is complimentary value at list price.</div>' + trend(d.history || [])
      + '<div class="leg"><span><i style="background:linear-gradient(90deg,#1DB9C3,#7C3AED)"></i>MRR</span><span><i style="background:#A78BFA"></i>Complimentary value</span></div></div>'
      + '<div class="card"><h3>Plan mix</h3><div class="sub">' + (k.mrr ? 'Share of MRR by plan.' : 'Share of complimentary value by plan (no paid MRR yet).') + '</div>'
      + '<div style="display:flex;gap:14px;align-items:center;flex-wrap:wrap;">' + donut(planVal, 'v', short(k.mrr || k.comp_value), k.mrr ? 'MRR' : 'comp / mo')
      + '<div style="flex:1;min-width:140px;">' + planVal.map(function (p, i) { return '<div style="display:flex;gap:8px;align-items:center;font-size:12.5px;margin:6px 0;"><i style="width:10px;height:10px;border-radius:3px;background:' + PAL[i % PAL.length] + ';"></i><span style="flex:1;">' + esc(p.plan) + ' <span style="color:#94A3B8;">· ' + p.agencies + '</span></span><b>' + short(p.v) + '</b></div>'; }).join('') + '</div></div></div>'
      + '</div>';

    var ag = d.aging || {};
    h += '<div class="row c2">'
      + '<div class="card"><h3>Invoiced vs collected</h3><div class="sub">KiddieTrac invoices issued and payments received, by month (refunds come off).</div>' + bars(d.collections || [])
      + '<div class="leg"><span><i style="background:#BFDBFE"></i>Invoiced</span><span><i style="background:#2563EB"></i>Collected</span></div></div>'
      + '<div class="card"><h3>Receivables</h3><div class="sub">What agencies owe, by how late it is.</div>'
      + stack([['Current', ag.current || 0, '#10B981'], ['1–30 days', ag.d1_30 || 0, '#F59E0B'], ['31–60', ag.d31_60 || 0, '#F97316'], ['61–90', ag.d61_90 || 0, '#EF4444'], ['90+', ag.d90 || 0, '#991B1B']])
      + '<h3 style="margin-top:18px;">This month</h3><div class="sub">MRR movement.</div>'
      + '<div style="display:grid;grid-template-columns:repeat(3,1fr);gap:10px;">'
      + '<div class="k" style="padding:10px 12px;"><span>New</span><b style="font-size:18px;color:#047857;">+' + money(k.new_mrr) + '</b></div>'
      + '<div class="k" style="padding:10px 12px;"><span>Churned</span><b style="font-size:18px;color:#B91C1C;">−' + money(k.churned_mrr) + '</b></div>'
      + '<div class="k" style="padding:10px 12px;"><span>Net</span><b style="font-size:18px;">' + (k.net_new < 0 ? '−' : '+') + money(Math.abs(k.net_new)) + '</b></div></div></div>'
      + '</div>';

    var top = d.top || [], maxTop = Math.max(1, Math.max.apply(null, top.map(function (a) { return Math.max(a.mrr, a.comp); })));
    h += '<div class="row c2">'
      + '<div class="card"><h3>Agencies by value</h3><div class="sub">Paid MRR first, then complimentary value at list price.</div><div style="overflow-x:auto;"><div class="kt-report-doc"><table data-kt-no-filter data-kt-no-kebab><thead><tr><th>Agency</th><th>Type</th><th>Boarded</th><th style="text-align:right;">Per month</th><th style="width:22%;"></th></tr></thead><tbody>'
      + (top.length ? top.map(function (a) { var t = TYPE[a.type] || TYPE.paid, v = a.mrr || a.comp || a.trial; return '<tr><td><b>' + esc(a.name) + '</b><div style="font-size:11.5px;color:#94A3B8;">' + esc(a.plan || '') + (a.lines ? ' · ' + a.lines + ' line' + (a.lines === 1 ? '' : 's') : '') + '</div></td>'
          + '<td><span class="chip" style="color:' + t[1] + ';background:' + t[2] + ';">' + t[0] + '</span></td><td style="white-space:nowrap;color:#475569;">' + esc(day(a.boarded)) + '</td>'
          + '<td style="text-align:right;white-space:nowrap;font-weight:800;' + (a.mrr ? '' : 'color:#7C3AED;') + '">' + money(v, true) + '</td><td>' + meter(v, maxTop, a.mrr ? 'linear-gradient(90deg,#1DB9C3,#2563EB)' : '#C4B5FD') + '</td></tr>'; }).join('')
        : '<tr><td colspan="5" style="color:#64748B;">No agencies on a licence yet.</td></tr>')
      + '</tbody></table></div></div></div>'
      + '<div class="card"><h3>Renewals, next 90 days</h3><div class="sub">When each licence term ends.</div><div class="kt-report-doc"><table data-kt-no-filter data-kt-no-kebab><thead><tr><th>Agency</th><th>Renews</th><th style="text-align:right;">Per month</th><th>Auto</th></tr></thead><tbody>'
      + ((d.renewals || []).length ? d.renewals.map(function (a) { return '<tr><td>' + esc(a.name) + '</td><td style="white-space:nowrap;">' + esc(day(a.renews)) + '</td><td style="text-align:right;">' + money(a.mrr || a.comp, true) + '</td><td>' + (a.auto ? '✓' : '<span style="color:#B45309;">Ends</span>') + '</td></tr>'; }).join('')
        : '<tr><td colspan="4" style="color:#64748B;">No licence renews in the next 90 days.</td></tr>')
      + '</tbody></table></div>'
      + '<h3 style="margin-top:18px;">By part number</h3><div class="sub">Live lines, paying lines and value per month.</div><div class="kt-report-doc"><table data-kt-no-filter data-kt-no-kebab><thead><tr><th>Part #</th><th>Item</th><th style="text-align:right;">Live</th><th style="text-align:right;">MRR</th><th style="text-align:right;">Comp / mo</th></tr></thead><tbody>'
      + (d.parts || []).map(function (p) { return '<tr><td style="font-family:ui-monospace,monospace;font-size:11.5px;font-weight:800;color:#1F6080;">' + esc(p.part_number) + '</td><td>' + esc(p.name) + '</td><td style="text-align:right;">' + p.live + (p.paying ? ' <span style="color:#94A3B8;">(' + p.paying + ' paid)</span>' : '') + '</td><td style="text-align:right;">' + money(p.mrr, true) + '</td><td style="text-align:right;color:#7C3AED;">' + money(p.comp, true) + '</td></tr>'; }).join('')
      + '</tbody></table></div></div>'
      + '</div>';

    container.innerHTML = h + '</div>';
  }

  window.KT = window.KT || {};
  window.KT.AdminMrr = { render: render };
})(window);
