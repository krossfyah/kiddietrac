/* ═══════════════════════════════════════════════════════════════════
   KiddieTrac — Sales insights (2026-10-24). KiddieTrac's OWN sales, never an agency's.
   #sales-quotes     Every proposal: stat tiles, charts, a sortable table with the ⋮ menu
                     (Anthony: "something under sales to look at all of the quotes/proposals -
                     full table with kebab and stats cards/graphs etc (make this look cool)").
                     Sales team + superadmins. Backend: GET /sales/quotes-overview.
   #platform-sales   Revenue by part number, plans/add-ons/services, month and customer
                     (Anthony: "under reports add sales reports based on part#, services, total
                     revenue etc (this is for kiddietrac platform only and not for agencies)").
                     Superadmins only. Backend: GET /platform/sales-report.
   Charts are plain SVG (no chart library is loaded in the portal).
   ═══════════════════════════════════════════════════════════════════ */
(function (window) {
  'use strict';
  var KT = window.KT || {};
  var Api = KT.Api;
  var ACCENT = '#1F6080';

  function el(tag, attrs, kids) {
    var e = document.createElement(tag);
    if (attrs) Object.keys(attrs).forEach(function (k) {
      if (k === 'style') e.style.cssText = attrs[k];
      else if (k === 'html') e.innerHTML = attrs[k];
      else if (k.slice(0, 2) === 'on' && typeof attrs[k] === 'function') e.addEventListener(k.slice(2), attrs[k]);
      else if (k === 'value') e.value = attrs[k];
      else e.setAttribute(k, attrs[k]);
    });
    (kids || []).forEach(function (c) { if (c != null) e.appendChild(typeof c === 'string' ? document.createTextNode(c) : c); });
    return e;
  }
  function esc(s) { return String(s == null ? '' : s).replace(/[&<>"]/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]; }); }
  function clear(n) { while (n.firstChild) n.removeChild(n.firstChild); }
  function toast(i, t, m, c) { try { KT.toast && KT.toast(i, t, m || '', c || ACCENT); } catch (e) {} }
  function me() {
    try { var a = KT.Auth && KT.Auth.user && KT.Auth.user(); if (a && a.roles) return a; } catch (e) {}
    try { return JSON.parse(sessionStorage.getItem('kt_user') || '{}'); } catch (e) { return {}; }
  }
  /* On a direct load the screen can be drawn before the signed-in user is stored, which read as
     "no roles" and showed the lock (2026-10-24). Wait up to ~5 s for the roles to arrive. */
  function whoReady() {
    return new Promise(function (res) { var n = 0; (function tick() { if ((me().roles || []).length || n++ > 25) return res(); setTimeout(tick, 200); })(); });
  }
  function roles() { return me().roles || []; }
  function isSuper() { var u = me(); return !!u.is_platform_admin || roles().indexOf('platform_admin') > -1; }
  function salesOk() { return isSuper() || roles().indexOf('sales_rep') > -1; }
  function tok() { return sessionStorage.getItem('kt_token') || localStorage.getItem('kt_token'); }
  function apiBase() { return KT.API_BASE || 'https://api.kiddietrac.com/api/v1'; }
  function money(v, dp) { var n = Number(v || 0); return (n < 0 ? '−$' : '$') + Math.abs(n).toLocaleString(undefined, { minimumFractionDigits: dp == null ? 0 : dp, maximumFractionDigits: dp == null ? 0 : dp }); }
  function cents(c, dp) { return money(Number(c || 0) / 100, dp); }
  function short(v) { var n = Number(v || 0); if (Math.abs(n) >= 1e6) return '$' + (n / 1e6).toFixed(1) + 'M'; if (Math.abs(n) >= 1e4) return '$' + Math.round(n / 1e3) + 'k'; if (Math.abs(n) >= 1e3) return '$' + (n / 1e3).toFixed(1) + 'k'; return '$' + Math.round(n); }
  function dayLabel(s) {
    if (!s) return '—';
    var d = String(s).slice(0, 10);
    if (KT.dayLabel) { try { return KT.dayLabel(d); } catch (e) {} }
    var t = new Date(d + 'T12:00:00'); return isNaN(t) ? d : t.toLocaleDateString(undefined, { month: 'short', day: 'numeric', year: 'numeric' });
  }
  function monthLabel(ym) { var t = new Date(ym + '-15T12:00:00'); return t.toLocaleDateString(undefined, { month: 'short' }); }
  function today() { return KT.agencyToday ? KT.agencyToday() : new Date().toISOString().slice(0, 10); }
  function locked(c, msg) { clear(c); c.appendChild(el('div', { style: 'padding:48px 20px;text-align:center;color:#64748B' }, ['🔒 ' + msg])); }
  function hero(title, sub, icon) {
    return el('div', { class: 'kt-hero kt-hero-auto kt-banner-fx' }, [el('h1', {}, [title]), el('div', { class: 'kt-hero-sub' }, [sub]), el('div', { class: 'kt-hero-emoji', 'aria-hidden': 'true' }, [icon])]);
  }
  function btn(label, primary, on) {
    return el('button', { type: 'button', class: 'btn', onclick: on, style: (primary ? 'background:' + ACCENT + ';color:#fff;border:1px solid ' + ACCENT : 'background:#fff;color:' + ACCENT + ';border:1px solid #CBD5E1')
      + ';border-radius:9px;padding:0 14px;height:32px;min-height:32px;font-weight:700;font-size:13px;cursor:pointer;display:inline-flex;align-items:center;gap:6px;white-space:nowrap' }, [label]);
  }

  function css() {
    if (document.getElementById('kt-insights-css')) return;
    var s = document.createElement('style'); s.id = 'kt-insights-css';
    s.textContent =
      '.ki-tiles{display:grid;grid-template-columns:repeat(auto-fill,minmax(min(100%,190px),1fr));gap:12px;margin:0 0 14px}' +
      '.ki-tile{position:relative;overflow:hidden;background:#fff;border:1px solid #E6EBF1;border-radius:16px;padding:14px 16px 13px;box-shadow:0 1px 3px rgba(15,23,42,.05);min-width:0}' +
      '.ki-tile:before{content:"";position:absolute;inset:0 auto 0 0;width:4px;background:var(--ki,#1F6080)}' +
      '.ki-tile .l{font-size:12px;font-weight:700;color:#64748B;display:flex;gap:6px;align-items:center}' +
      '.ki-tile .v{font-size:26px;font-weight:800;color:#0D1B2A;margin-top:5px;line-height:1.05;letter-spacing:-.5px}' +
      '.ki-tile .s{font-size:11.5px;color:#94A3B8;margin-top:4px}' +
      '.ki-tile .g{position:absolute;right:12px;top:12px;font-size:22px;opacity:.16}' +
      '.ki-charts{display:grid;grid-template-columns:repeat(auto-fit,minmax(min(100%,360px),1fr));gap:12px;margin:0 0 14px}' +
      '.ki-box{background:#fff;border:1px solid #E6EBF1;border-radius:16px;padding:14px 16px;min-width:0;box-shadow:0 1px 3px rgba(15,23,42,.05)}' +
      '.ki-h{margin:0 0 10px;font-size:14.5px;font-weight:800;color:#0D1B2A;display:flex;justify-content:space-between;gap:8px;flex-wrap:wrap;align-items:center}' +
      '.ki-h small{font-size:11.5px;font-weight:600;color:#64748B}' +
      '.ki-seg{display:flex;gap:6px;flex-wrap:wrap;margin:0}' +
      '.ki-seg button{border:1.5px solid #E2E8F0;background:#fff;color:#334155;border-radius:999px;padding:0 12px;height:30px;min-height:30px;font-size:12.5px;font-weight:700;cursor:pointer;display:inline-flex;align-items:center;gap:6px;white-space:nowrap}' +
      '.ki-seg button.on{background:#0D1B2A;border-color:#0D1B2A;color:#fff}' +
      '.ki-seg button i{font-style:normal;font-size:11px;font-weight:800;opacity:.75}' +
      '.ki-bar{display:flex;gap:10px;flex-wrap:wrap;align-items:center;justify-content:space-between;margin:0 0 12px}' +
      '.ki-tbl{width:100%;border-collapse:collapse;background:#fff}' +
      '.ki-tbl th{padding:10px 12px;font-size:11.5px;letter-spacing:.3px;text-transform:uppercase;color:#5a7080;text-align:left;background:#F4F6F9;white-space:nowrap;cursor:pointer;user-select:none}' +
      '.ki-tbl th.nos{cursor:default}' +
      '.ki-tbl td{padding:10px 12px;border-top:1px solid #EEF1F5;font-size:13.5px;vertical-align:middle}' +
      '.ki-tbl tr.go{cursor:pointer}.ki-tbl tr.go:hover td{background:#F8FAFC}' +
      '.ki-wrap{background:#fff;border:1px solid #E6EBF1;border-radius:16px;overflow:hidden;box-shadow:0 1px 3px rgba(15,23,42,.05)}' +
      '.ki-pill{display:inline-flex;align-items:center;gap:5px;padding:2px 9px;border-radius:999px;font-size:11.5px;font-weight:800;white-space:nowrap}' +
      '.ki-pill:before{content:"";width:6px;height:6px;border-radius:50%;background:currentColor}' +
      '.ki-pn{display:inline-block;font-size:11px;font-weight:800;color:#1F6080;background:#EAF3F6;border:1px solid #CFE3EB;border-radius:6px;padding:2px 7px;font-family:ui-monospace,Menlo,Consolas,monospace;white-space:nowrap}' +
      '.ki-track{display:flex;gap:3px;align-items:center}.ki-track span{width:9px;height:9px;border-radius:50%;background:#E2E8F0}.ki-track span.on{background:#10B981}' +
      '.ki-share{height:7px;border-radius:99px;background:#EEF2F6;overflow:hidden;min-width:60px}.ki-share b{display:block;height:100%;border-radius:99px}' +
      '.ki-range{display:flex;gap:8px;flex-wrap:wrap;align-items:center}' +
      '.ki-range input{height:32px;padding:0 9px;border:1px solid #D9E1EA;border-radius:9px;font-size:13px;width:auto!important;max-width:150px}' +
      '@media (max-width:640px){.ki-tile .v{font-size:21px}.ki-tbl td,.ki-tbl th{padding:9px 9px}.ki-hide-sm{display:none}}';
    document.head.appendChild(s);
  }

  function tile(icon, label, value, sub, color) {
    var t = el('div', { class: 'ki-tile', style: '--ki:' + (color || ACCENT) }, [
      el('div', { class: 'g', 'aria-hidden': 'true' }, [icon]),
      el('div', { class: 'l' }, [icon + ' ' + label]),
      el('div', { class: 'v' }, [String(value)]),
      sub ? el('div', { class: 's' }, [sub]) : null,
    ]);
    return t;
  }

  /* ── charts ── */
  function barChart(rows, series, labelOf, fmt) {
    var W = 560, H = 180, top = 18, left = 40, n = Math.max(1, rows.length), max = 0;
    rows.forEach(function (r) { series.forEach(function (sx) { max = Math.max(max, Number(r[sx.key] || 0)); }); });
    if (!max) return '<div style="height:150px;display:flex;align-items:center;justify-content:center;color:#94A3B8;font-size:13px">Nothing yet</div>';
    var step = Math.pow(10, Math.floor(Math.log10(max))); var nice = Math.ceil(max / step) * step; if (nice / step < 3) nice = Math.ceil(max / (step / 2)) * (step / 2);
    var bw = (W - left - 8) / n, inner = Math.max(3, (bw - 8) / series.length);
    var s = '<svg viewBox="0 0 ' + W + ' ' + (H + 26) + '" style="width:100%;height:auto;display:block" role="img">';
    s += '<defs>' + series.map(function (sx, j) { return '<linearGradient id="kig' + j + sx.key + '" x1="0" x2="0" y1="0" y2="1"><stop offset="0" stop-color="' + sx.color + '"/><stop offset="1" stop-color="' + sx.color + '" stop-opacity=".55"/></linearGradient>'; }).join('') + '</defs>';
    for (var g = 0; g <= 3; g++) { var gy = top + (H - top) * g / 3; s += '<line x1="' + left + '" x2="' + W + '" y1="' + gy + '" y2="' + gy + '" stroke="#EEF2F6"/><text x="' + (left - 6) + '" y="' + (gy + 4) + '" font-size="10" text-anchor="end" fill="#94A3B8">' + esc(fmt(nice * (3 - g) / 3)) + '</text>'; }
    rows.forEach(function (r, i) {
      series.forEach(function (sx, j) {
        var v = Number(r[sx.key] || 0), h = (H - top) * v / nice, x = left + i * bw + 4 + j * inner, y = H - h;
        s += '<rect x="' + x.toFixed(1) + '" y="' + y.toFixed(1) + '" width="' + Math.max(1, inner - 2).toFixed(1) + '" height="' + Math.max(0, h).toFixed(1) + '" rx="3" fill="url(#kig' + j + sx.key + ')"><title>' + esc(labelOf(r) + ' · ' + sx.label + ': ' + fmt(v)) + '</title></rect>';
      });
      if (n <= 12 || i % 2 === 0) s += '<text x="' + (left + i * bw + bw / 2).toFixed(1) + '" y="' + (H + 16) + '" font-size="10" text-anchor="middle" fill="#64748B">' + esc(labelOf(r)) + '</text>';
    });
    return s + '</svg>';
  }
  function legend(series) {
    return el('small', { html: series.map(function (sx) { return '<span style="display:inline-block;width:9px;height:9px;border-radius:2px;background:' + sx.color + ';margin:0 4px 0 10px"></span>' + esc(sx.label); }).join('') });
  }
  function donut(parts, centreTop, centreSub) {
    var total = parts.reduce(function (a, p) { return a + Number(p.value || 0); }, 0);
    var r = 56, C = 2 * Math.PI * r, off = 0;
    var s = '<svg width="150" height="150" viewBox="0 0 150 150" aria-hidden="true" style="flex:0 0 auto"><circle cx="75" cy="75" r="' + r + '" fill="none" stroke="#F1F5F9" stroke-width="16"/>';
    if (total) parts.forEach(function (p) {
      var len = C * Number(p.value || 0) / total; if (!len) return;
      s += '<circle cx="75" cy="75" r="' + r + '" fill="none" stroke="' + p.color + '" stroke-width="16" stroke-dasharray="' + Math.max(0, len - 1.5).toFixed(2) + ' ' + C.toFixed(2) + '" stroke-dashoffset="' + (-off).toFixed(2) + '" transform="rotate(-90 75 75)"><title>' + esc(p.label + ': ' + p.text) + '</title></circle>';
      off += len;
    });
    s += '<text x="75" y="73" text-anchor="middle" font-size="22" font-weight="800" fill="#0D1B2A">' + esc(centreTop) + '</text><text x="75" y="92" text-anchor="middle" font-size="11" fill="#64748B">' + esc(centreSub) + '</text></svg>';
    var leg = el('div', { style: 'flex:1;min-width:150px' }, parts.filter(function (p) { return p.value; }).map(function (p) {
      return el('div', { style: 'display:flex;align-items:center;gap:8px;margin-bottom:7px;font-size:13px' }, [
        el('span', { style: 'width:10px;height:10px;border-radius:3px;flex:0 0 auto;background:' + p.color }),
        el('span', { style: 'flex:1;color:#475569' }, [p.label]),
        el('b', { style: 'color:#0D1B2A' }, [p.text]),
      ]);
    }));
    if (!total) leg.appendChild(el('div', { style: 'color:#94A3B8;font-size:13px' }, ['Nothing yet.']));
    return el('div', { style: 'display:flex;gap:16px;align-items:center;flex-wrap:wrap' }, [el('div', { html: s }), leg]);
  }
  function funnel(steps) {
    var max = Math.max.apply(null, steps.map(function (s) { return s.n; }).concat([1]));
    return el('div', {}, steps.map(function (s, i) {
      var pct = Math.round(s.n / max * 100), prev = i ? steps[i - 1].n : 0;
      return el('div', { style: 'margin-bottom:10px' }, [
        el('div', { style: 'display:flex;justify-content:space-between;font-size:12.5px;margin-bottom:4px' }, [
          el('span', { style: 'font-weight:700;color:#334155' }, [s.icon + ' ' + s.label]),
          el('span', { style: 'color:#64748B' }, [String(s.n) + (i && prev ? '  ·  ' + Math.round(s.n / prev * 100) + '% of previous' : '')]),
        ]),
        el('div', { class: 'ki-share', style: 'height:12px' }, [el('b', { style: 'width:' + Math.max(s.n ? 3 : 0, pct) + '%;background:linear-gradient(90deg,' + s.color + ',' + s.color + 'AA)' })]),
      ]);
    }));
  }
  function box(title, kids, extra) { return el('div', { class: 'ki-box' }, [el('div', { class: 'ki-h', role: 'heading', 'aria-level': '3' }, [title, extra || null])].concat(kids)); }

  /* ════════════════════════ PROPOSALS ════════════════════════ */
  var STATE = {
    draft: ['Draft', '#64748B', '#F1F5F9'], sent: ['Sent', '#0369A1', '#E0F2FE'], opened: ['Opened', '#6D28D9', '#EDE9FE'],
    awaiting_countersign: ['Client signed', '#B45309', '#FEF3C7'], signed: ['Signed', '#047857', '#D1FAE5'], won: ['Customer', '#065F46', '#A7F3D0'],
    declined: ['Declined', '#B91C1C', '#FEE2E2'], expired: ['Expired', '#475569', '#E2E8F0'],
  };
  var DONUT_COLOR = { draft: '#CBD5E1', sent: '#38BDF8', opened: '#8B5CF6', awaiting_countersign: '#F59E0B', signed: '#10B981', won: '#047857', declined: '#EF4444', expired: '#94A3B8' };
  var FILTERS = [['all', 'All'], ['open', 'Open', ['sent', 'opened', 'awaiting_countersign']], ['won', 'Signed & won', ['signed', 'won']], ['awaiting_countersign', 'To countersign', ['awaiting_countersign']], ['draft', 'Drafts', ['draft']], ['declined', 'Declined', ['declined']], ['expired', 'Expired', ['expired']]];
  function pill(state) { var s = STATE[state] || [state, '#475569', '#F1F5F9']; return el('span', { class: 'ki-pill', style: 'color:' + s[1] + ';background:' + s[2] }, [s[0]]); }
  function track(q) {
    // Sent · Opened · Signed · Countersigned · Customer
    var on = [!!q.sent_at, !!(q.opens || q.viewed_at), !!q.signed_at, !!q.countersigned_at, q.state === 'won'];
    var names = ['Sent', 'Opened', 'Client signed', 'Countersigned', 'Customer'];
    return el('span', { class: 'ki-track', title: names.map(function (n, i) { return (on[i] ? '✓ ' : '○ ') + n; }).join('\n') }, on.map(function (x) { return el('span', { class: x ? 'on' : '' }); }));
  }
  function pdf(q) {
    fetch(apiBase() + '/sales/quotes/' + q.id + '/pdf', { headers: { Authorization: 'Bearer ' + tok() } })
      .then(function (r) { if (!r.ok) throw new Error('HTTP ' + r.status); return r.blob(); })
      .then(function (b) { var u = URL.createObjectURL(b); var a = document.createElement('a'); a.href = u; a.download = (q.number || ('proposal-' + q.id)) + '.pdf'; document.body.appendChild(a); a.click(); a.remove(); setTimeout(function () { URL.revokeObjectURL(u); }, 1500); })
      .catch(function (e) { toast('⚠️', 'PDF failed', e.message || '', '#DC2626'); });
  }

  async function renderQuotes(c) {
    await whoReady();
    if (!salesOk()) return locked(c, 'Proposals are for the sales team and superadmins.');
    css(); clear(c);
    c.appendChild(hero('Proposals', 'Every proposal you have sent, where each one stands, and what it is worth.', '📄'));
    var host = el('div', {}, [el('div', { style: 'padding:24px;color:#64748B' }, ['Loading…'])]); c.appendChild(host);
    var d;
    try { d = await Api.get('/sales/quotes-overview'); } catch (e) { clear(host); host.appendChild(el('div', { class: 'ki-box', style: 'color:#B91C1C' }, ['Could not load proposals: ' + (e.message || e)])); return; }
    clear(host);
    var st = d.stats || {}, all = d.quotes || [];

    host.appendChild(el('div', { class: 'ki-tiles' }, [
      tile('📄', 'Proposals', st.total || 0, (st.drafts || 0) + ' draft' + (st.drafts === 1 ? '' : 's'), '#1F6080'),
      tile('⏳', 'Open pipeline', short(st.open_value), (st.open || 0) + ' waiting on the client', '#0EA5E9'),
      tile('🏆', 'Won', short(st.won_value), (st.won || 0) + ' signed or set up', '#10B981'),
      tile('🎯', 'Win rate', st.win_rate == null ? '—' : st.win_rate + '%', 'of proposals decided', '#8B5CF6'),
      tile('👀', 'Open rate', st.open_rate == null ? '—' : st.open_rate + '%', 'of sent proposals were opened', '#6D28D9'),
      tile('💵', 'Average proposal', short(st.avg_value), 'before tax', '#F59E0B'),
      tile('✍️', 'To countersign', st.awaiting_countersign || 0, st.awaiting_countersign ? 'client signed — your turn' : 'nothing waiting', st.awaiting_countersign ? '#B45309' : '#94A3B8'),
    ]));

    var bm = d.by_month || [], byState = d.by_state || [];
    var sent = all.filter(function (q) { return q.sent_at; }).length;
    var opened = all.filter(function (q) { return q.sent_at && (q.opens || q.viewed_at); }).length;
    var signed = all.filter(function (q) { return q.signed_at || q.state === 'signed' || q.state === 'won'; }).length;
    var won = all.filter(function (q) { return q.state === 'won'; }).length;
    var s1 = [{ key: 'created', label: 'Created', color: '#7DD3FC' }, { key: 'won', label: 'Won', color: '#10B981' }];
    var s2 = [{ key: 'value_won', label: 'Won value', color: '#1F6080' }];
    host.appendChild(el('div', { class: 'ki-charts' }, [
      box('📈 Proposals by month', [el('div', { html: barChart(bm, s1, function (r) { return monthLabel(r.month); }, function (v) { return String(Math.round(v)); }) })], legend(s1)),
      box('🍩 Where they stand', [donut(byState.map(function (b) { return { label: (STATE[b.state] || [b.state])[0], value: b.count, color: DONUT_COLOR[b.state] || '#94A3B8', text: b.count + ' · ' + short(b.value) }; }), String(st.total || 0), 'proposals')]),
      box('🪜 From sent to customer', [funnel([
        { icon: '📤', label: 'Sent', n: sent, color: '#38BDF8' }, { icon: '👀', label: 'Opened', n: opened, color: '#8B5CF6' },
        { icon: '✍️', label: 'Signed', n: signed, color: '#F59E0B' }, { icon: '🏢', label: 'Customer', n: won, color: '#10B981' }])]),
      box('💰 Value won by month', [el('div', { html: barChart(bm, s2, function (r) { return monthLabel(r.month); }, short) })], legend(s2)),
    ]));

    // ── filters + table ──
    var view = { f: 'all', q: '', sort: 'created_at', dir: -1 };
    var seg = el('div', { class: 'ki-seg', 'data-kt-noband': '1' });
    var search = el('input', { type: 'search', placeholder: '🔍 Search client, number, title…', style: 'height:32px;padding:0 11px;border:1px solid #D9E1EA;border-radius:9px;font-size:13px;width:260px;max-width:100%;box-sizing:border-box' });
    host.appendChild(el('div', { class: 'ki-bar' }, [seg, search]));
    var tblHost = el('div', { class: 'ki-wrap' }); host.appendChild(tblHost);
    function inFilter(q, f) { var def = FILTERS.filter(function (x) { return x[0] === f; })[0]; return !def || !def[2] || def[2].indexOf(q.state) > -1; }
    function paintSeg() {
      clear(seg);
      FILTERS.forEach(function (f) {
        var n = all.filter(function (q) { return inFilter(q, f[0]); }).length;
        if (f[0] !== 'all' && !n) return;
        seg.appendChild(el('button', { type: 'button', 'data-kt-iconized': '1', class: view.f === f[0] ? 'on' : '', onclick: function () { view.f = f[0]; paintSeg(); paint(); } }, [f[1], el('i', {}, [String(n)])]));
      });
    }
    var COLS = [['number', 'Proposal'], ['lead', 'Client'], ['state', 'Status'], ['total', 'Value'], ['track', 'Progress', 1], ['sent_at', 'Sent', 0, 'ki-hide-sm'], ['valid_until', 'Valid until', 0, 'ki-hide-sm'], ['by', 'By', 0, 'ki-hide-sm'], ['', '', 1]];
    function paint() {
      var q = view.q.toLowerCase();
      var rows = all.filter(function (x) { return inFilter(x, view.f) && (!q || [x.number, x.title, x.lead, x.contact, x.agency, x.by].join(' ').toLowerCase().indexOf(q) > -1); });
      rows.sort(function (a, b) { var A = a[view.sort], B = b[view.sort]; if (A == null) A = ''; if (B == null) B = ''; return (typeof A === 'number' && typeof B === 'number' ? A - B : String(A).localeCompare(String(B))) * view.dir; });
      clear(tblHost);
      if (!rows.length) { tblHost.appendChild(el('div', { style: 'padding:28px;text-align:center;color:#64748B' }, ['No proposals match.'])); return; }
      var tbl = el('table', { class: 'ki-tbl', 'data-kt-filtered': '1' });
      tbl.appendChild(el('thead', {}, [el('tr', {}, COLS.map(function (col) {
        var th = el('th', { class: (col[2] ? 'nos ' : '') + (col[3] || '') }, [col[1] + (view.sort === col[0] && !col[2] ? (view.dir > 0 ? ' ▲' : ' ▼') : '')]);
        if (!col[2]) th.addEventListener('click', function () { if (view.sort === col[0]) view.dir = -view.dir; else { view.sort = col[0]; view.dir = col[0] === 'lead' || col[0] === 'number' ? 1 : -1; } paint(); });
        return th;
      }))]));
      var tb = el('tbody');
      rows.forEach(function (x) {
        var openLead = function () { window.location.hash = '#sales-lead?id=' + x.lead_id; };
        var cur = x.currency && x.currency !== 'CAD' ? ' ' + x.currency : '';
        tb.appendChild(el('tr', { class: 'go', onclick: openLead }, [
          el('td', {}, [el('div', { style: 'font-weight:800;color:#0D1B2A;white-space:nowrap' }, [x.number || ('#' + x.id)]), x.title ? el('div', { style: 'font-size:12px;color:#64748B;max-width:220px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap' }, [x.title]) : null]),
          el('td', {}, [el('div', { style: 'font-weight:700' }, [x.lead || '—']), x.agency ? el('div', { style: 'font-size:12px;color:#047857' }, ['🏢 ' + x.agency]) : (x.contact ? el('div', { style: 'font-size:12px;color:#64748B' }, [x.contact]) : null)]),
          el('td', {}, [pill(x.state), x.contract ? el('span', { title: 'Signed contract attached', style: 'margin-left:6px' }, ['📎']) : null]),
          el('td', { style: 'font-weight:800;white-space:nowrap;color:#0D1B2A' }, [money(x.total, 2) + cur, x.billing_period ? el('span', { style: 'font-weight:600;color:#94A3B8;font-size:11.5px' }, [x.billing_period === 'year' ? ' /yr' : x.billing_period === 'month' ? ' /mo' : '']) : null]),
          el('td', {}, [track(x), x.opens ? el('div', { style: 'font-size:11px;color:#64748B;margin-top:3px' }, ['opened ' + x.opens + '×']) : null]),
          el('td', { class: 'ki-hide-sm', style: 'white-space:nowrap;color:#475569' }, [x.sent_at ? dayLabel(x.sent_at) : '—']),
          el('td', { class: 'ki-hide-sm', style: 'white-space:nowrap;color:' + (x.valid_until && x.valid_until < today() && ['sent', 'opened', 'expired'].indexOf(x.state) > -1 ? '#B91C1C' : '#475569') }, [x.valid_until ? dayLabel(x.valid_until) : '—']),
          el('td', { class: 'ki-hide-sm', style: 'color:#475569;white-space:nowrap' }, [x.by || '—']),
          /* Plain labelled buttons; kt-row-actions folds them into the portal's ⋮ menu. */
          el('td', { style: 'padding:6px 12px;text-align:center;white-space:nowrap' }, [
            el('button', { type: 'button', title: 'Open the lead and this proposal', onclick: function (ev) { ev.stopPropagation(); openLead(); } }, ['👁 Open']),
            el('button', { type: 'button', title: 'Download the PDF', onclick: function (ev) { ev.stopPropagation(); pdf(x); } }, ['📄 Download PDF']),
            x.agency ? el('button', { type: 'button', title: 'The customer this proposal set up', onclick: function (ev) { ev.stopPropagation(); window.location.hash = '#licences'; } }, ['🔑 Licences']) : null,
            /* DELETE (2026-11-11, Anthony: "there is not option to delete proposals that were added as a test in the
               kebab"). Same soft delete as the lead's Proposals tab; the lead's history notes it. Not offered once a
               proposal has set up a customer (the server refuses that too). */
            x.agency ? null : el('button', { type: 'button', title: 'Delete this proposal', onclick: async function (ev) {
              ev.stopPropagation();
              var ok = KT.confirm ? await KT.confirm({ title: 'Delete ' + (x.number || 'this proposal') + '?',
                description: (x.lead ? 'It is removed from ' + x.lead + '. ' : '') + 'The lead\u2019s history keeps a note that it was deleted.' + (x.state === 'signed' ? ' It was signed: its signed copy goes with it.' : ''),
                okLabel: 'Delete', tone: 'danger' }) : false;
              if (!ok) return;
              try { await Api.delete('/sales/quotes/' + x.id); toast('🗑', 'Proposal deleted', x.number || ''); renderQuotes(c); }
              catch (e2) { toast('⚠️', 'Could not delete', e2.message || '', '#DC2626'); }
            } }, ['🗑 Delete']),
          ]),
        ]));
      });
      tbl.appendChild(tb);
      tblHost.appendChild(el('div', { style: 'overflow-x:auto' }, [tbl]));
      var tot = rows.reduce(function (a, r) { return a + Number(r.total || 0); }, 0);
      tblHost.appendChild(el('div', { style: 'padding:10px 14px;border-top:1px solid #EEF1F5;font-size:12.5px;color:#64748B;display:flex;justify-content:space-between;gap:8px;flex-wrap:wrap' }, [
        el('span', {}, [rows.length + ' proposal' + (rows.length === 1 ? '' : 's')]), el('b', { style: 'color:#0D1B2A' }, ['Total ' + money(tot, 2)]),
      ]));
    }
    var t; search.addEventListener('input', function () { clearTimeout(t); t = setTimeout(function () { view.q = search.value.trim(); paint(); }, 200); });
    paintSeg(); paint();
  }

  /* ════════════════════════ CUSTOMERS (2026-11-10) ════════════════════════
     Anthony: "when I change lead to won it should create a customer account with customer id based on the details of
     the services etc (this should be tracked in a new section called Customers under Sales - this section should be
     table with all of the information, stats, graphs, metrics and tie this to the account AR/AP as well".
     Every won lead is a customer (KT-CUS-0001, App\Support\SalesCustomers) with the services of the winning proposal;
     its KiddieTrac agency, once Approve sets it up, ties it to AR. The AR columns come only to superadmins (the server
     leaves them out for sales reps). Backend: GET/PATCH /sales/customers. */
  var CSTATUS = { onboarding: ['Onboarding', '#B45309', '#FEF3C7'], active: ['Active', '#047857', '#D1FAE5'], paused: ['Paused', '#475569', '#E2E8F0'], cancelled: ['Cancelled', '#B91C1C', '#FEE2E2'] };
  var CCOLOR = { onboarding: '#F59E0B', active: '#10B981', paused: '#94A3B8', cancelled: '#EF4444' };
  var MIXCOL = ['#1F6080', '#7C3AED', '#0EA5E9', '#10B981', '#F59E0B', '#94A3B8'];
  function cpill(s) { var x = CSTATUS[s] || [s, '#475569', '#F1F5F9']; return el('span', { class: 'ki-pill', style: 'color:' + x[1] + ';background:' + x[2] }, [x[0]]); }
  function perLabel(p) { return p === 'year' ? 'billed yearly' : p === 'month' ? 'billed monthly' : ''; }
  /* ── Customer stat cards (2026-11-11). Anthony: "i dont like the stat cards design - make these better looking with
     cricle graphs and other designs and no colour bands". No side stripe: a white card, a neutral icon chip, the number
     in ink, and ONE small picture that says something true: a ring (a share of something) or a sparkline (12 months).
     The colour sits only on the ring or line; red only where something is overdue or missing. ── */
  function kcCss() {
    if (document.getElementById('kt-kc-css')) return;
    var s = document.createElement('style'); s.id = 'kt-kc-css';
    s.textContent =
      '.kc-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(min(100%,180px),1fr));gap:8px;margin:0 0 10px}' +
      '.kc{background:#fff;border:1px solid #E6EBF1;border-radius:14px;padding:9px 12px;min-width:0;display:flex;flex-direction:column;gap:4px;box-shadow:0 1px 2px rgba(15,23,42,.04);transition:box-shadow .15s,transform .15s}' +
      '.kc:hover{box-shadow:0 6px 18px rgba(15,23,42,.08);transform:translateY(-1px)}' +
      '.kc-top{display:flex;align-items:center;gap:6px;min-width:0}' +
      '.kc-ic{flex:0 0 22px;width:22px;height:22px;border-radius:50%;background:#F1F5F9;display:flex;align-items:center;justify-content:center;font-size:12px}' +
      '.kc-l{font-size:11.5px;font-weight:700;color:#475569;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}' +
      '.kc-mid{display:flex;align-items:center;justify-content:space-between;gap:10px;min-width:0}' +
      '.kc-v{font-size:19px;font-weight:800;color:#0D1B2A;line-height:1.05;letter-spacing:-.5px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;min-width:0}' +
      '.kc-v small{font-size:11px;font-weight:700;color:#94A3B8;letter-spacing:0;margin-left:3px}' +
      '.kc-s{font-size:11px;color:#64748B;line-height:1.3}' +
      '.kc-viz{flex:0 0 auto;display:block;height:28px;width:auto}.kc-viz[width="56"]{height:38px}' +
      '.kc-flag{display:inline-flex;align-items:center;gap:4px;font-size:11px;font-weight:800;color:#B91C1C}' +
      '.kc-mini .kc{padding:8px 11px}.kc-mini .kc-v{font-size:17px}' +
      '@media (max-width:640px){.kc-v{font-size:17px}}';
    document.head.appendChild(s);
  }
  /* A ring: share = 0..1 (null = nothing to show yet). The centre reads the percent. */
  function kcRing(share, color, tip) {
    var r = 22, C = 2 * Math.PI * r, has = share != null && isFinite(share), p = has ? Math.max(0, Math.min(1, share)) : 0;
    var arc = has && p > 0 ? '<circle cx="28" cy="28" r="' + r + '" fill="none" stroke="' + color + '" stroke-width="6" stroke-linecap="round" stroke-dasharray="' + (C * p).toFixed(2) + ' ' + C.toFixed(2) + '" transform="rotate(-90 28 28)"/>' : '';
    return '<svg class="kc-viz" width="56" height="56" viewBox="0 0 56 56" role="img" aria-label="' + esc(tip) + '"' + (tip ? ' data-kttip="' + esc(tip) + '"' : '') + '>'
      + '<circle cx="28" cy="28" r="' + r + '" fill="none" stroke="#EEF2F6" stroke-width="6"/>' + arc
      + '<text x="28" y="32" text-anchor="middle" font-size="12" font-weight="800" fill="#0D1B2A">' + (has ? Math.round(p * 100) + '%' : '—') + '</text></svg>';
  }
  /* A 12-month sparkline: thin line, faint area, a dot on this month. */
  function kcSpark(vals, color, tip) {
    var W = 92, H = 38, n = vals.length, max = Math.max.apply(null, vals.concat([0]));
    if (n < 2 || !max) return '<svg class="kc-viz" width="' + W + '" height="' + H + '" viewBox="0 0 ' + W + ' ' + H + '" aria-hidden="true"><line x1="2" x2="' + (W - 2) + '" y1="' + (H - 4) + '" y2="' + (H - 4) + '" stroke="#E2E8F0" stroke-width="2" stroke-linecap="round" stroke-dasharray="3 4"/></svg>';
    var pts = vals.map(function (v, i) { return [2 + (W - 8) * i / (n - 1), (H - 5) - (H - 10) * v / max]; });
    var line = pts.map(function (p, i) { return (i ? 'L' : 'M') + p[0].toFixed(1) + ' ' + p[1].toFixed(1); }).join(' ');
    var last = pts[n - 1];
    return '<svg class="kc-viz" width="' + W + '" height="' + H + '" viewBox="0 0 ' + W + ' ' + H + '" role="img" aria-label="' + esc(tip) + '" data-kttip="' + esc(tip) + '">'
      + '<path d="' + line + ' L' + last[0].toFixed(1) + ' ' + (H - 2) + ' L2 ' + (H - 2) + ' Z" fill="' + color + '" opacity=".1"/>'
      + '<path d="' + line + '" fill="none" stroke="' + color + '" stroke-width="2" stroke-linejoin="round" stroke-linecap="round"/>'
      + '<circle cx="' + last[0].toFixed(1) + '" cy="' + last[1].toFixed(1) + '" r="3.5" fill="' + color + '" stroke="#fff" stroke-width="2"/></svg>';
  }
  /* Small month bars (counts), for "won by month". */
  function kcBars(vals, color, tip) {
    var W = 92, H = 38, n = vals.length, max = Math.max.apply(null, vals.concat([0])), bw = n ? (W - 4) / n : 0, s = '';
    vals.forEach(function (v, i) {
      var h = max ? Math.max(v ? 3 : 2, (H - 6) * v / max) : 2;
      s += '<rect x="' + (2 + i * bw + 1).toFixed(1) + '" y="' + (H - 2 - h).toFixed(1) + '" width="' + Math.max(2, bw - 3).toFixed(1) + '" height="' + h.toFixed(1) + '" rx="2" fill="' + (v ? color : '#E2E8F0') + '"/>';
    });
    return '<svg class="kc-viz" width="' + W + '" height="' + H + '" viewBox="0 0 ' + W + ' ' + H + '" role="img" aria-label="' + esc(tip) + '" data-kttip="' + esc(tip) + '">' + s + '</svg>';
  }
  function kcard(icon, label, value, sub, viz, unit) {
    var v = el('div', { class: 'kc-v', title: String(value) }, [String(value)]);
    if (unit) v.appendChild(el('small', {}, [unit]));
    return el('div', { class: 'kc' }, [
      el('div', { class: 'kc-top' }, [el('span', { class: 'kc-ic', 'aria-hidden': 'true' }, [icon]), el('span', { class: 'kc-l' }, [label])]),
      el('div', { class: 'kc-mid' }, [v, viz ? el('div', { html: viz, style: 'flex:0 0 auto;line-height:0' }) : null]),
      sub ? (sub.nodeType ? el('div', { class: 'kc-s' }, [sub]) : el('div', { class: 'kc-s' }, [sub])) : null,
    ]);
  }
  function kcShare(a, b) { a = Number(a || 0); b = Number(b || 0); return b > 0 ? a / b : null; }
  var KC_TEAL = '#1F6080', KC_GREEN = '#10B981', KC_SKY = '#0EA5E9', KC_AMBER = '#F59E0B', KC_RED = '#DC2626', KC_VIOLET = '#7C3AED';

  async function renderCustomers(c) {
    await whoReady();
    if (!salesOk()) return locked(c, 'Customers are for the sales team and superadmins.');
    css(); kcCss(); clear(c);
    c.appendChild(hero('Customers', 'Everyone who said yes: what they bought, what it is worth, and where their account stands.', '🏆'));
    var host = el('div', {}, [el('div', { style: 'padding:24px;color:#64748B' }, ['Loading…'])]); c.appendChild(host);
    var d;
    try { d = await Api.get('/sales/customers'); } catch (e) { clear(host); host.appendChild(el('div', { class: 'ki-box', style: 'color:#B91C1C' }, ['Could not load customers: ' + (e.message || e)])); return; }
    clear(host);
    var st = d.stats || {}, all = d.customers || [], ar = !!d.can_see_ar;
    var mixed = (st.currencies || []).length > 1 ? ' (mixed currencies)' : '';

    var bm = d.by_month || [];
    var col = function (k) { return bm.map(function (m) { return Number(m[k] || 0); }); };
    var live = all.filter(function (x) { return ['active', 'onboarding'].indexOf(x.status) > -1; });
    var paying = live.filter(function (x) { return Number(x.mrr || 0) > 0; }).length;
    var firstYear = Number(st.arr || 0) + Number(st.one_time || 0);
    var billed = Number(st.ar_paid || 0) + Number(st.ar_outstanding || 0);
    var flag = function (t) { return el('span', { class: 'kc-flag' }, ['⚠️ ' + t]); };
    var tiles = [
      kcard('🏆', 'Customers', st.live || 0, (st.total || 0) + ' in all · ' + (st.new_this_month || 0) + ' new this month',
        kcBars(col('won'), KC_TEAL, 'Customers won each month, last 12 months')),
      kcard('🔁', 'Monthly revenue', money(st.mrr, 2), 'recurring, after discounts, before tax' + mixed,
        kcSpark(col('mrr'), KC_GREEN, 'Monthly revenue at the end of each month, last 12 months')),
      kcard('📅', 'Yearly revenue', short(st.arr), 'monthly revenue × 12',
        kcSpark(col('mrr').map(function (v) { return v * 12; }), KC_SKY, 'Yearly revenue at the end of each month, last 12 months')),
      kcard('👤', 'Average customer', money(st.avg_mrr, 2), paying + ' of ' + live.length + ' current customer' + (live.length === 1 ? ' pays' : 's pay') + ' monthly',
        kcRing(kcShare(paying, live.length), KC_VIOLET, paying + ' of ' + live.length + ' current customers pay a monthly amount'), '/mo'),
      kcard('🧰', 'One-time fees', money(st.one_time, 2), 'setup, training, imports · share of first-year value',
        kcRing(kcShare(st.one_time, firstYear), KC_AMBER, 'One-time fees as a share of first-year value (yearly revenue + one-time)')),
      kcard('🚧', 'Onboarding', st.onboarding || 0, st.no_account ? st.no_account + ' without a KiddieTrac account yet' : 'all have their account',
        kcRing(kcShare(st.onboarding, st.live), KC_AMBER, (st.onboarding || 0) + ' of ' + (st.live || 0) + ' current customers still onboarding')),
      kcard('🧾', 'No services yet', st.no_services || 0, st.no_services ? flag('won without an accepted or signed proposal') : 'every customer has their services',
        kcRing(kcShare(st.no_services, st.total), st.no_services ? KC_RED : KC_GREEN, (st.no_services || 0) + ' of ' + (st.total || 0) + ' customers have no services yet')),
    ];
    if (ar) tiles.push(
      kcard('🏦', 'Owed to us', money(st.ar_outstanding, 2), 'unpaid invoices (AR) · share of billed',
        kcRing(kcShare(st.ar_outstanding, billed), KC_SKY, 'Owed as a share of everything billed')),
      kcard('⏰', 'Overdue', money(st.ar_overdue, 2), st.ar_overdue_count ? flag(st.ar_overdue_count + ' customer' + (st.ar_overdue_count === 1 ? '' : 's') + ' past due') : 'nothing past due',
        kcRing(kcShare(st.ar_overdue, st.ar_outstanding), KC_RED, 'Overdue as a share of what is owed')),
      kcard('✅', 'Collected', money(st.ar_paid, 2), 'paid on their invoices · share of billed',
        kcRing(kcShare(st.ar_paid, billed), KC_GREEN, 'Collected as a share of everything billed')));
    host.appendChild(el('div', { class: 'kc-grid' }, tiles));

    var s1 = [{ key: 'won', label: 'Customers won', color: '#10B981' }];
    var s2 = [{ key: 'mrr', label: 'Monthly revenue', color: '#1F6080' }, { key: 'new_mrr', label: 'Added that month', color: '#7DD3FC' }];
    var byStatus = ['active', 'onboarding', 'paused', 'cancelled'].map(function (k) { var n = all.filter(function (x) { return x.status === k; }).length; return { label: CSTATUS[k][0], value: n, color: CCOLOR[k], text: String(n) }; });
    var charts = [
      box('💰 Monthly revenue by month', [el('div', { html: barChart(bm, s2, function (r) { return monthLabel(r.month); }, short) })], legend(s2)),
      box('📈 Customers won by month', [el('div', { html: barChart(bm, s1, function (r) { return monthLabel(r.month); }, function (v) { return String(Math.round(v)); }) })], legend(s1)),
      box('🧩 What they pay for (a month)', [donut((d.mix || []).map(function (m, i) { return { label: m.label, value: m.value, color: MIXCOL[i % MIXCOL.length], text: money(m.value, 2) }; }), short(st.mrr), 'a month')]),
      box('🍩 Where customers stand', [donut(byStatus, String(st.total || 0), 'customers')]),
    ];
    if (ar) charts.push(box('🏦 Accounts receivable', [donut([
      { label: 'Collected', value: st.ar_paid || 0, color: '#10B981', text: money(st.ar_paid, 2) },
      { label: 'Owed, not due yet', value: Math.max(0, (st.ar_outstanding || 0) - (st.ar_overdue || 0)), color: '#38BDF8', text: money(Math.max(0, (st.ar_outstanding || 0) - (st.ar_overdue || 0)), 2) },
      { label: 'Overdue', value: st.ar_overdue || 0, color: '#EF4444', text: money(st.ar_overdue, 2) }], short((st.ar_paid || 0) + (st.ar_outstanding || 0)), 'invoiced')]));
    host.appendChild(el('div', { class: 'ki-charts' }, charts));

    // ── filters + table ──
    var FIL = [['all', 'All'], ['live', 'Current', ['active', 'onboarding']], ['active', 'Active', ['active']], ['onboarding', 'Onboarding', ['onboarding']], ['flag', 'No services yet'], ['paused', 'Paused', ['paused']], ['cancelled', 'Cancelled', ['cancelled']]];
    if (ar) FIL.splice(5, 0, ['overdue', 'Overdue']);
    var view = { f: 'all', q: '', sort: 'won_at', dir: -1 };
    var seg = el('div', { class: 'ki-seg', 'data-kt-noband': '1' });
    var search = el('input', { type: 'search', placeholder: '🔍 Search customer, ID, contact, service…', style: 'height:32px;padding:0 11px;border:1px solid #D9E1EA;border-radius:9px;font-size:13px;width:280px;max-width:100%;box-sizing:border-box' });
    host.appendChild(el('div', { class: 'ki-bar' }, [seg, search]));
    var tblHost = el('div', { class: 'ki-wrap' }); host.appendChild(tblHost);
    function inF(x, f) {
      if (f === 'flag') return x.no_services;
      if (f === 'overdue') return x.ar && x.ar.overdue > 0;
      var def = FIL.filter(function (y) { return y[0] === f; })[0];
      return !def || !def[2] || def[2].indexOf(x.status) > -1;
    }
    function paintSeg() {
      clear(seg);
      FIL.forEach(function (f) {
        var n = all.filter(function (x) { return inF(x, f[0]); }).length;
        if (f[0] !== 'all' && !n) return;
        seg.appendChild(el('button', { type: 'button', 'data-kt-iconized': '1', class: view.f === f[0] ? 'on' : '', onclick: function () { view.f = f[0]; paintSeg(); paint(); } }, [f[1], el('i', {}, [String(n)])]));
      });
    }
    var COLS = [['number', 'Customer ID'], ['company', 'Customer'], ['status', 'Status'], ['services_summary', 'Services', 0, 'ki-hide-sm'], ['mrr', 'Monthly'], ['arr', 'Yearly', 0, 'ki-hide-sm'], ['one_time', 'One-time', 0, 'ki-hide-sm']];
    if (ar) COLS.push(['ar_out', 'Owed (AR)'], ['ar_last', 'Last paid', 0, 'ki-hide-sm']);
    COLS.push(['won_at', 'Won', 0, 'ki-hide-sm'], ['', '', 1]);
    function sortVal(x, k) { if (k === 'ar_out') return x.ar ? x.ar.outstanding : -1; if (k === 'ar_last') return x.ar && x.ar.last_paid ? x.ar.last_paid : ''; return x[k]; }
    function paint() {
      var q = view.q.toLowerCase();
      var rows = all.filter(function (x) { return inF(x, view.f) && (!q || [x.number, x.company, x.contact, x.email, x.agency, x.services_summary, x.quote_number, x.place].join(' ').toLowerCase().indexOf(q) > -1); });
      rows.sort(function (a, b) { var A = sortVal(a, view.sort), B = sortVal(b, view.sort); if (A == null) A = ''; if (B == null) B = ''; return (typeof A === 'number' && typeof B === 'number' ? A - B : String(A).localeCompare(String(B))) * view.dir; });
      clear(tblHost);
      if (!rows.length) {
        tblHost.appendChild(el('div', { style: 'padding:28px;text-align:center;color:#64748B' }, [all.length ? 'No customers match.' : 'No customers yet. Set a lead to Won and it shows up here with its customer ID.']));
        return;
      }
      var tbl = el('table', { class: 'ki-tbl', 'data-kt-filtered': '1' });
      tbl.appendChild(el('thead', {}, [el('tr', {}, COLS.map(function (col) {
        var th = el('th', { class: (col[2] ? 'nos ' : '') + (col[3] || '') }, [col[1] + (view.sort === col[0] && !col[2] ? (view.dir > 0 ? ' ▲' : ' ▼') : '')]);
        if (!col[2]) th.addEventListener('click', function () { if (view.sort === col[0]) view.dir = -view.dir; else { view.sort = col[0]; view.dir = ['company', 'number', 'services_summary', 'status'].indexOf(col[0]) > -1 ? 1 : -1; } paint(); });
        return th;
      }))]));
      var tb = el('tbody');
      rows.forEach(function (x) {
        var open = function () { openCustomer(x.id, c); };
        var cur = x.currency && x.currency !== 'CAD' ? ' ' + x.currency : '';
        var cells = [
          el('td', { style: 'white-space:nowrap' }, [el('div', { style: 'font-weight:800;color:#0D1B2A' }, [x.number || '—']), x.quote_number ? el('div', { style: 'font-size:11.5px;color:#64748B' }, ['from ' + x.quote_number]) : null]),
          el('td', {}, [el('div', { style: 'font-weight:700' }, [x.company || '—']),
            x.agency ? el('div', { style: 'font-size:12px;color:#047857' }, ['🏢 ' + x.agency]) : el('div', { style: 'font-size:12px;color:#64748B' }, [[x.contact, x.place].filter(Boolean).join(' · ') || '—'])]),
          el('td', {}, [cpill(x.status), x.no_services ? el('div', { style: 'font-size:11px;color:#B91C1C;font-weight:700;margin-top:3px' }, ['⚠️ No services yet']) : (!x.agency_id && x.status !== 'cancelled' ? el('div', { style: 'font-size:11px;color:#B45309;margin-top:3px' }, ['No KiddieTrac account yet']) : null)]),
          el('td', { class: 'ki-hide-sm', style: 'color:#475569;max-width:260px' }, [el('div', { style: 'overflow:hidden;text-overflow:ellipsis;white-space:nowrap', title: x.services_summary || '' }, [x.services_summary || '—'])]),
          el('td', { style: 'font-weight:800;white-space:nowrap;color:#0D1B2A' }, [money(x.mrr, 2) + cur, el('div', { style: 'font-size:11px;font-weight:600;color:#94A3B8' }, [perLabel(x.billing_period)])]),
          el('td', { class: 'ki-hide-sm', style: 'white-space:nowrap;color:#475569' }, [money(x.arr, 2)]),
          el('td', { class: 'ki-hide-sm', style: 'white-space:nowrap;color:#475569' }, [x.one_time ? money(x.one_time, 2) : '—']),
        ];
        if (ar) {
          cells.push(el('td', { style: 'white-space:nowrap' }, x.ar ? [el('div', { style: 'font-weight:800;color:' + (x.ar.overdue > 0 ? '#B91C1C' : (x.ar.outstanding > 0 ? '#0369A1' : '#047857')) }, [x.ar.outstanding > 0 ? money(x.ar.outstanding, 2) : 'Paid up']),
            x.ar.overdue > 0 ? el('div', { style: 'font-size:11px;color:#B91C1C;font-weight:700' }, [money(x.ar.overdue, 2) + ' overdue']) : el('div', { style: 'font-size:11px;color:#94A3B8' }, [x.ar.invoices + ' invoice' + (x.ar.invoices === 1 ? '' : 's')])]
            : [el('span', { style: 'color:#94A3B8;font-size:12px' }, ['No account yet'])]));
          cells.push(el('td', { class: 'ki-hide-sm', style: 'white-space:nowrap;color:#475569' }, [x.ar && x.ar.last_paid ? dayLabel(x.ar.last_paid) : '—']));
        }
        cells.push(el('td', { class: 'ki-hide-sm', style: 'white-space:nowrap;color:#475569' }, [x.won_at ? dayLabel(x.won_at) : '—', x.owner ? el('div', { style: 'font-size:11px;color:#94A3B8' }, ['by ' + x.owner]) : null]));
        /* Plain labelled buttons; kt-row-actions folds them into the portal's ⋮ menu. */
        cells.push(el('td', { style: 'padding:6px 12px;text-align:center;white-space:nowrap' }, [
          el('button', { type: 'button', title: 'Customer details, services and account', onclick: function (ev) { ev.stopPropagation(); open(); } }, ['👁 Details']),
          !x.lead_id ? null : el('button', { type: 'button', title: 'The lead this customer came from', onclick: function (ev) { ev.stopPropagation(); window.location.hash = '#sales-lead?id=' + x.lead_id; } }, ['🎯 Open lead']),
          x.quote_id ? el('button', { type: 'button', title: 'The proposal they bought', onclick: function (ev) { ev.stopPropagation(); pdf({ id: x.quote_id, number: x.quote_number }); } }, ['📄 Proposal PDF']) : null,
          ar && x.agency_id ? el('button', { type: 'button', title: 'Invoices and payments in Accounting AR/AP', onclick: function (ev) { ev.stopPropagation(); window.location.hash = '#sales-invoices'; } }, ['🏦 Accounting AR/AP']) : null,
        ]));
        tb.appendChild(el('tr', { class: 'go', onclick: open }, cells));
      });
      tbl.appendChild(tb);
      tblHost.appendChild(el('div', { style: 'overflow-x:auto' }, [tbl]));
      var mrr = rows.reduce(function (a, r) { return a + (['active', 'onboarding'].indexOf(r.status) > -1 ? Number(r.mrr || 0) : 0); }, 0);
      var owed = rows.reduce(function (a, r) { return a + (r.ar ? Number(r.ar.outstanding || 0) : 0); }, 0);
      tblHost.appendChild(el('div', { style: 'padding:10px 14px;border-top:1px solid #EEF1F5;font-size:12.5px;color:#64748B;display:flex;justify-content:space-between;gap:8px;flex-wrap:wrap' }, [
        el('span', {}, [rows.length + ' customer' + (rows.length === 1 ? '' : 's')]),
        el('b', { style: 'color:#0D1B2A' }, ['Monthly ' + money(mrr, 2) + ' · yearly ' + money(mrr * 12, 2) + (ar ? ' · owed ' + money(owed, 2) : '')]),
      ]));
    }
    var t; search.addEventListener('input', function () { clearTimeout(t); t = setTimeout(function () { view.q = search.value.trim(); paint(); }, 200); });
    paintSeg(); paint();
    // #sales-customers?id=N opens that customer.
    var m = /[?&]id=(\d+)/.exec(location.hash); if (m) openCustomer(Number(m[1]), c);
  }

  /* One customer: who, what they bought, their account (AR, superadmins), status and notes. */
  /* A platform invoice's PDF, opened in the portal's file viewer (same path as Accounting AR/AP). */
  function invoicePdf(i, b) {
    if (b) b.disabled = true;
    fetch(apiBase() + '/platform/invoices/' + i.id + '/pdf', { headers: { Authorization: 'Bearer ' + tok(), Accept: 'application/pdf' } })
      .then(function (res) { if (!res.ok) throw new Error('HTTP ' + res.status); return res.blob(); })
      .then(function (blob) {
        var name = (i.number || ('invoice-' + i.id)) + '.pdf';
        if (KT.openFile) KT.openFile(blob, { title: 'Invoice ' + (i.number || i.id), filename: name, label: 'Platform invoice' });
        else { var u = URL.createObjectURL(blob); window.open(u, '_blank'); setTimeout(function () { URL.revokeObjectURL(u); }, 60000); }
      })
      .catch(function (e) { toast('⚠️', 'Could not open the invoice', e.message || '', '#DC2626'); })
      .then(function () { if (b) b.disabled = false; });
  }

  /* One customer (2026-11-11 rework). Anthony: "cannot view the invoices etc and the table should show the discounts as
     well given to a customer and the intial total amount before discounts and full agency info at the top of that view
     (agency owner name and address etc)". The agency card leads; services show every discount with its rate and the
     totals before and after; each invoice opens its PDF. */
  async function openCustomer(id, screen) {
    var body = el('div', { style: 'font-size:13.5px;color:#334155' }, [el('div', { style: 'color:#94A3B8' }, ['Loading…'])]);
    var statusSel = null, notesIn = null, ownerSel = null, cust = null;
    var M = KT.Shell && KT.Shell.Modal;
    if (!M) return;
    M.open({ title: '🏆 Customer', body: body, large: true, actions: [
      { label: 'Close' },
      { label: 'Save', primary: true, busyLabel: 'Saving…', handler: async function () {
        if (!cust) return;
        var send = { status: statusSel.value, notes: notesIn.value };
        if (ownerSel) send.owner_user_id = ownerSel.value ? Number(ownerSel.value) : null;
        try {
          await Api.patch('/sales/customers/' + id, send);
          toast('✅', 'Customer saved', cust.number + ' · ' + (CSTATUS[statusSel.value] || [statusSel.value])[0]);
          if (screen) renderCustomers(screen);
        } catch (e) { toast('⚠️', 'Could not save', e.message || '', '#DC2626'); return false; }
      } },
    ] });
    var r;
    try { r = await Api.get('/sales/customers/' + id); } catch (e) { clear(body); body.appendChild(el('div', { style: 'color:#B91C1C' }, [e.message || 'Could not load this customer.'])); return; }
    cust = r.customer; var x = cust, ag = x.agency_info, pr = x.pricing || {};
    clear(body); kcCss();
    var cur = x.currency && x.currency !== 'CAD' ? ' ' + x.currency : '';
    var fact = function (k, v) { return v ? el('div', { style: 'display:flex;gap:10px;padding:3px 0' }, [el('div', { style: 'flex:0 0 130px;color:#64748B' }, [k]), el('div', { style: 'flex:1 1 auto;font-weight:600;min-width:0;overflow-wrap:anywhere' }, [v])]) : null; };
    /* FOLDING SECTIONS (2026-11-11, Anthony: "allow to collapse some of the views in the popup such as services and
       pricing and invoices etc"). Each heading is a button; kcFold() below gathers what follows it into its body.
       What you folded stays folded the next time you open a customer (this browser only). */
    var sec = function (title) {
      return el('button', { type: 'button', 'data-kc-sec': title, 'aria-expanded': 'true', 'data-kt-iconized': '1',
        style: 'display:flex;align-items:center;gap:8px;width:100%;background:none;border:none;border-bottom:1px solid #EEF1F5;padding:6px 0;margin:14px 0 8px;font:inherit;font-weight:800;font-size:14px;color:#0D1B2A;cursor:pointer;text-align:left' },
        [el('span', { class: 'kc-chev', 'aria-hidden': 'true', style: 'display:inline-block;width:14px;color:#64748B;transition:transform .15s' }, ['▾']), el('span', {}, [title])]);
    };
    var card = function (kids) { return el('div', { style: 'border:1px solid #E2E8F0;border-radius:14px;padding:12px 14px;background:#fff;min-width:0' }, kids); };
    var link = function (href, text) { return el('a', { href: href, style: 'color:#1F6080;font-weight:700;text-decoration:none', target: href.indexOf('http') === 0 ? '_blank' : null, rel: 'noopener' }, [text]); };
    var phoneFmt = function (p) { var d = String(p || '').replace(/\D/g, ''); if (d.length === 11 && d[0] === '1') d = d.slice(1); return d.length === 10 ? '(' + d.slice(0, 3) + ') ' + d.slice(3, 6) + '-' + d.slice(6) : (p || ''); };

    /* ── header ── */
    body.appendChild(el('div', { style: 'display:flex;gap:12px;align-items:center;flex-wrap:wrap;margin-bottom:10px' }, [
      ag && ag.logo_url ? el('img', { src: ag.logo_url, alt: '', style: 'width:44px;height:44px;object-fit:contain;border-radius:10px;border:1px solid #E2E8F0;background:#fff;padding:3px' }) : null,
      el('div', { style: 'min-width:0' }, [
        el('div', { style: 'font-size:18px;font-weight:800;color:#0D1B2A' }, [x.company || '—']),
        ag && ag.legal_name && ag.legal_name !== x.company ? el('div', { style: 'font-size:12px;color:#64748B' }, [ag.legal_name]) : null]),
      el('span', { style: 'font-weight:800;color:#1F6080' }, [x.number]), cpill(x.status)]));
    if (x.no_services) body.appendChild(el('div', { style: 'background:#FEF2F2;border:1px solid #FECACA;color:#991B1B;border-radius:10px;padding:8px 12px;margin-bottom:8px;font-weight:600' }, ['⚠️ No services yet: this lead was won without an accepted or signed proposal. Its services fill in when one is accepted or signed.']));

    /* ── agency (or, before Approve, the lead's contact) ── */
    body.appendChild(sec(ag ? 'Agency' : 'Contact'));
    if (ag) {
      var addr = [ag.address_line1, ag.address_line2, [ag.city, ag.province].filter(Boolean).join(', ') + (ag.postal_code ? '  ' + ag.postal_code : ''), ag.country].filter(function (v) { return v && String(v).trim(); });
      var o = ag.owner;
      var ownerBox = el('div', {}, [
        el('div', { style: 'font-size:11px;font-weight:800;color:#64748B;letter-spacing:.3px;text-transform:uppercase;margin-bottom:4px' }, ['Owner']),
        o ? el('div', { style: 'font-weight:800;color:#0D1B2A;font-size:15px' }, [o.name]) : el('div', { style: 'color:#94A3B8;font-weight:600' }, ['No agency admin yet']),
        o && o.derived ? el('div', { style: 'font-size:11.5px;color:#94A3B8' }, ['the agency\u2019s first admin']) : null,
        o && o.email ? el('div', {}, [link('mailto:' + o.email, o.email)]) : null,
        o && o.phone ? el('div', {}, [link('tel:' + String(o.phone).replace(/[^\d+]/g, ''), phoneFmt(o.phone))]) : null,
      ]);
      var addrBox = el('div', {}, [
        el('div', { style: 'font-size:11px;font-weight:800;color:#64748B;letter-spacing:.3px;text-transform:uppercase;margin-bottom:4px' }, ['Address']),
        addr.length ? el('div', { style: 'font-weight:600;color:#0D1B2A;line-height:1.45' }, addr.map(function (l, i) { return el('div', {}, [l]); }))
          : el('div', { style: 'color:#94A3B8' }, ['No address on the agency']),
        addr.length ? el('div', { style: 'margin-top:3px' }, [link('https://www.google.com/maps/search/?api=1&query=' + encodeURIComponent(addr.join(', ')), '📍 Map')]) : null,
      ]);
      var reach = el('div', {}, [
        el('div', { style: 'font-size:11px;font-weight:800;color:#64748B;letter-spacing:.3px;text-transform:uppercase;margin-bottom:4px' }, ['Agency contact']),
        ag.phone ? el('div', {}, [link('tel:' + String(ag.phone).replace(/[^\d+]/g, ''), '📞 ' + phoneFmt(ag.phone))]) : null,
        ag.email ? el('div', {}, [link('mailto:' + ag.email, '✉️ ' + ag.email)]) : null,
        ag.website ? el('div', {}, [link(/^https?:/i.test(ag.website) ? ag.website : 'https://' + ag.website, '🌐 ' + ag.website.replace(/^https?:\/\//i, ''))]) : null,
        ag.invoice_emails ? el('div', { style: 'font-size:12px;color:#64748B;margin-top:3px' }, ['Invoices go to ' + ag.invoice_emails.split(',').join(', ')]) : null,
      ]);
      body.appendChild(card([
        el('div', { style: 'display:grid;grid-template-columns:repeat(auto-fit,minmax(min(100%,200px),1fr));gap:14px' }, [ownerBox, addrBox, reach]),
        el('div', { style: 'border-top:1px solid #EEF1F5;margin-top:12px;padding-top:10px;display:grid;grid-template-columns:repeat(auto-fit,minmax(min(100%,260px),1fr));gap:2px 18px' }, [
          fact('KiddieTrac account', ag.name + (ag.ref_prefix ? ' · ' + ag.ref_prefix : '')),
          fact('Plan', [ag.plan_label, ag.plan_amount != null ? money(ag.plan_amount, 2) + (ag.billing_interval === 'yearly' ? ' a year' : ' a month') : '', ag.billing_status ? '(' + ag.billing_status + ')' : ''].filter(Boolean).join(' ')),
          fact('Customer since', ag.billing_starts_at ? dayLabel(ag.billing_starts_at) : (ag.created_at ? dayLabel(ag.created_at) : '')),
          fact('Next invoice', ag.next_invoice_at ? dayLabel(ag.next_invoice_at) : ''),
          fact('Centres', ag.centres != null ? String(ag.centres) : ''),
          fact('Tax', ag.tax_label ? ag.tax_label + (ag.tax_rate != null ? ' ' + ag.tax_rate + '%' : '') + (ag.tax_registration ? ' · ' + ag.tax_registration : '') : ''),
          fact('Time zone', ag.timezone || ''),
          fact('Won', x.won_at ? dayLabel(x.won_at) + (x.owner ? ' · by ' + x.owner : '') : ''),
          fact('Bought', x.quote_number || ''),
        ]),
      ]));
    } else {
      body.appendChild(card([
        fact('Contact', [x.contact, x.email, x.phone].filter(Boolean).join(' · ')),
        fact('Location', x.place),
        fact('KiddieTrac account', 'Not set up yet: use ✅ Approve & set up customer on the proposal. The agency, its owner and address show here once it is.'),
        fact('Bought', x.quote_number || ''),
        fact('Won', x.won_at ? dayLabel(x.won_at) + (x.owner ? ' · by ' + x.owner : '') : ''),
      ]));
    }

    /* ── services, every discount, and the totals before and after ── */
    var per = pr.period === 'year' ? ' a year' : ' a month';
    body.appendChild(sec('Services and pricing'));
    var lines = pr.lines || x.services || [];
    if (lines.length) {
      var st = el('table', { class: 'ki-tbl', style: 'font-size:13px' });
      st.appendChild(el('thead', {}, [el('tr', {}, ['Part #', 'Service', 'Qty', 'Price', 'Discount', 'Total'].map(function (h) { return el('th', { class: 'nos' }, [h]); }))]));
      var sb = el('tbody');
      lines.forEach(function (s) {
        var isD = s.kind === 'discount';
        sb.appendChild(el('tr', { style: isD ? 'background:#F0FDF4' : '' }, [
          el('td', { style: 'white-space:nowrap;font-family:ui-monospace,Menlo,Consolas,monospace;font-size:11.5px;color:#1F6080' }, [s.part_number || '—']),
          el('td', { style: isD ? 'color:#047857;font-weight:700' : '' }, [(isD ? '🏷️ ' : '') + s.description, s.unit === 'one-time' && !isD ? el('span', { style: 'color:#94A3B8;font-weight:600' }, [' · one-time']) : null,
            isD && s.applies ? el('div', { style: 'font-size:11.5px;font-weight:600;color:#64748B' }, ['on ' + s.applies]) : null]),
          el('td', {}, [isD ? '' : String(Number(s.qty))]),
          el('td', { style: 'white-space:nowrap' }, [isD ? '' : money(s.unit_price, 2)]),
          el('td', { style: 'white-space:nowrap;color:#047857;font-weight:800' }, [isD ? (s.rate || '—') : '']),
          el('td', { style: 'white-space:nowrap;font-weight:700;color:' + (isD ? '#047857' : '#0D1B2A') }, [money(s.line_total, 2)]),
        ]));
      });
      st.appendChild(sb); body.appendChild(el('div', { class: 'ki-wrap', style: 'overflow-x:auto' }, [st]));

      /* Totals: before discounts, each discount, after, tax. Monthly (or yearly) and one-time side by side. */
      var G = pr.gross || {}, N = pr.net || {}, T = pr.tax || {}, D = pr.discounts || [];
      var hasOnce = Number(G.one_time || 0) !== 0 || Number(N.one_time || 0) !== 0;
      var row = function (label, a, b, strong, color, note) {
        return el('tr', {}, [
          el('td', { style: 'padding:6px 10px;color:' + (color || '#334155') + ';font-weight:' + (strong ? 800 : 600) }, [label, note ? el('div', { style: 'font-size:11.5px;color:#64748B;font-weight:600' }, [note]) : null]),
          el('td', { style: 'padding:6px 10px;text-align:right;white-space:nowrap;font-weight:' + (strong ? 800 : 700) + ';color:' + (color || '#0D1B2A') }, [a]),
          hasOnce ? el('td', { style: 'padding:6px 10px;text-align:right;white-space:nowrap;font-weight:' + (strong ? 800 : 700) + ';color:' + (color || '#0D1B2A') }, [b]) : null,
        ]);
      };
      var tt = el('table', { style: 'width:100%;border-collapse:collapse;font-size:13.5px' });
      tt.appendChild(el('tr', {}, [el('th', { style: 'text-align:left;padding:6px 10px;font-size:11px;color:#64748B;text-transform:uppercase;letter-spacing:.3px' }, ['']),
        el('th', { style: 'text-align:right;padding:6px 10px;font-size:11px;color:#64748B;text-transform:uppercase;letter-spacing:.3px' }, [pr.period === 'year' ? 'Yearly' : 'Monthly']),
        hasOnce ? el('th', { style: 'text-align:right;padding:6px 10px;font-size:11px;color:#64748B;text-transform:uppercase;letter-spacing:.3px' }, ['One-time']) : null]));
      tt.appendChild(row('Before discounts', money(G.recurring, 2) + cur, money(G.one_time, 2) + cur, true));
      D.forEach(function (d) {
        tt.appendChild(row('🏷️ ' + d.label + (d.rate ? ' (' + d.rate + ')' : ''), d.on === 'one_time' ? '' : money(d.recurring != null ? d.recurring : d.amount, 2), d.on === 'recurring' ? '' : (d.one_time != null ? money(d.one_time, 2) : ''), false, '#047857', d.applies ? 'on ' + d.applies : ''));
      });
      if (!D.length) tt.appendChild(row('No discounts', '—', '—', false, '#94A3B8'));
      tt.appendChild(el('tr', {}, [el('td', { colspan: hasOnce ? 3 : 2, style: 'padding:0' }, [el('div', { style: 'border-top:1px solid #E2E8F0;margin:2px 10px' })])]));
      tt.appendChild(row('After discounts', money(N.recurring, 2) + cur, money(N.one_time, 2) + cur, true));
      if (Number(T.recurring || 0) || Number(T.one_time || 0)) {
        tt.appendChild(row('Tax' + (pr.tax_label ? ' (' + pr.tax_label + ')' : ''), money(T.recurring, 2), money(T.one_time, 2)));
        tt.appendChild(row('Total with tax', money(Number(N.recurring || 0) + Number(T.recurring || 0), 2) + cur, money(Number(N.one_time || 0) + Number(T.one_time || 0), 2) + cur, true, '#1F6080'));
      }
      var saved = Number(pr.discount_total || 0);
      body.appendChild(el('div', { style: 'display:grid;grid-template-columns:minmax(0,1fr) auto;gap:12px;align-items:start;margin-top:10px' }, [
        card([tt]),
        el('div', { class: 'kc', style: 'min-width:170px' }, [
          el('div', { class: 'kc-top' }, [el('span', { class: 'kc-ic', 'aria-hidden': 'true' }, ['🏷️']), el('span', { class: 'kc-l' }, ['Discounts given'])]),
          el('div', { class: 'kc-mid' }, [el('div', { class: 'kc-v', style: 'font-size:17px;color:' + (saved ? '#047857' : '#0D1B2A') }, [money(Math.abs(saved), 2)]),
            el('div', { html: kcRing(kcShare(Math.abs(saved), Number(G.recurring || 0) + Number(G.one_time || 0)), KC_GREEN, 'Discount as a share of the price before discounts'), style: 'line-height:0' })]),
          el('div', { class: 'kc-s' }, [D.length ? D.length + ' discount' + (D.length === 1 ? '' : 's') + ' · ' + (pr.period === 'year' ? 'yearly' : 'monthly') + (hasOnce ? ' + one-time' : '') : 'full price']),
        ]),
      ]));
      if (!pr.from_quote) body.appendChild(el('div', { style: 'font-size:12px;color:#64748B;margin-top:6px' }, [pr.source_invoice ? 'No proposal on file: these are the services and discounts on their latest invoice, ' + pr.source_invoice + '.' : 'From the customer\'s plan (no proposal on file).']));
    } else body.appendChild(el('div', { style: 'color:#94A3B8' }, ['None yet.']));

    /* ── account (AR), superadmins ── */
    if (r.can_see_ar) {
      body.appendChild(sec('Account (AR)'));
      if (!x.agency_id) body.appendChild(el('div', { style: 'color:#94A3B8' }, ['Invoices and payments show here once their KiddieTrac account is set up.']));
      else {
        var a = x.ar || {};
        body.appendChild(el('div', { class: 'kc-grid kc-mini', style: 'grid-template-columns:repeat(auto-fill,minmax(min(100%,190px),1fr));margin-bottom:8px' }, [
          kcard('🧾', 'Invoiced', money(a.invoiced, 2), (a.invoices || 0) + ' invoice' + (a.invoices === 1 ? '' : 's'), kcBars(r.customer.invoices ? r.customer.invoices.slice(0, 12).reverse().map(function (i) { return Number(i.amount || 0); }) : [], KC_TEAL, 'Each invoice, oldest to newest')),
          kcard('✅', 'Paid', money(a.paid, 2), a.last_paid ? 'last ' + dayLabel(a.last_paid) : 'nothing yet', kcRing(kcShare(a.paid, a.invoiced), KC_GREEN, 'Paid as a share of invoiced')),
          kcard('🏦', 'Owed', money(a.outstanding, 2), 'not paid yet', kcRing(kcShare(a.outstanding, a.invoiced), KC_SKY, 'Owed as a share of invoiced')),
          kcard('⏰', 'Overdue', money(a.overdue, 2), a.overdue ? el('span', { class: 'kc-flag' }, ['⚠️ past due']) : 'nothing past due', kcRing(kcShare(a.overdue, a.outstanding), KC_RED, 'Overdue as a share of what is owed')),
        ]));
        var inv = r.customer.invoices || [];
        if (inv.length) {
          var it = el('table', { class: 'ki-tbl', style: 'font-size:13px' });
          it.appendChild(el('thead', {}, [el('tr', {}, ['Invoice', 'Issued', 'Due', 'Amount', 'Paid', 'Status', ''].map(function (h) { return el('th', { class: 'nos' }, [h]); }))]));
          var ib = el('tbody');
          inv.forEach(function (i) {
            var viewBtn = el('button', { type: 'button', title: 'Open this invoice', 'data-kt-iconized': '1', style: 'height:28px;min-height:28px;padding:0 10px;border:1px solid #CBD5E1;border-radius:8px;background:#fff;color:#1F6080;font-weight:700;font-size:12.5px;cursor:pointer;white-space:nowrap' }, ['👁 View']);
            viewBtn.addEventListener('click', function (ev) { ev.stopPropagation(); invoicePdf(i, viewBtn); });
            var stTxt = i.overdue ? ['Overdue', '#B91C1C', '#FEE2E2'] : i.status === 'paid' ? ['Paid', '#047857', '#D1FAE5'] : i.status === 'void' ? ['Void', '#475569', '#E2E8F0'] : ['Open', '#0369A1', '#E0F2FE'];
            var tr = el('tr', { class: 'go', title: 'Open invoice ' + (i.number || ''), style: i.status === 'void' ? 'opacity:.6' : '' }, [
              el('td', { style: 'white-space:nowrap' }, [el('div', { style: 'font-weight:800;color:#1F6080' }, [i.number || '—']),
                i.period_start ? el('div', { style: 'font-size:11.5px;color:#64748B' }, [dayLabel(i.period_start) + (i.period_end ? ' – ' + dayLabel(i.period_end) : '')]) : null]),
              el('td', { style: 'white-space:nowrap' }, [i.issued_at ? dayLabel(i.issued_at) : '—']),
              el('td', { style: 'white-space:nowrap;' + (i.overdue ? 'color:#B91C1C;font-weight:700' : '') }, [i.due_at ? dayLabel(i.due_at) : '—']),
              el('td', { style: 'white-space:nowrap;font-weight:700' }, [money(i.amount, 2)]),
              el('td', { style: 'white-space:nowrap' }, [money(i.paid, 2)]),
              el('td', {}, [el('span', { class: 'ki-pill', style: 'color:' + stTxt[1] + ';background:' + stTxt[2] }, [stTxt[0]])]),
              el('td', { style: 'text-align:right' }, [viewBtn]),
            ]);
            tr.addEventListener('click', function () { invoicePdf(i, viewBtn); });
            ib.appendChild(tr);
          });
          it.appendChild(ib); body.appendChild(el('div', { class: 'ki-wrap', style: 'overflow-x:auto' }, [it]));
        } else body.appendChild(el('div', { style: 'color:#94A3B8' }, ['No invoices yet.']));
        var pays = r.customer.payments || [];
        if (pays.length) body.appendChild(el('div', { style: 'margin-top:8px;color:#475569' }, ['Payments: ' + pays.slice(0, 6).map(function (p) { return (p.paid_on ? dayLabel(p.paid_on) : '') + ' ' + money(p.amount, 2) + (p.method ? ' (' + p.method + ')' : '') + (p.status && p.status !== 'confirmed' ? ' · ' + p.status : ''); }).join(' · ')]));
        body.appendChild(el('div', { style: 'margin-top:8px' }, [btn('🏦 Open Accounting AR/AP', false, function () { M.close && M.close(); window.location.hash = '#sales-invoices'; })]));
      }
    }

    /* ── referrals (2026-11-26): who referred them, and the referral credits they were given ── */
    if (KT.ReferralCredits && x.referrals && (r.can_see_ar || x.referrals.referred_by || (x.referrals.given || []).length)) {
      body.appendChild(sec('Referrals'));
      body.appendChild(card([KT.ReferralCredits.customerBlock(x.referrals, { canEdit: !!r.can_see_ar && !!x.agency_id, agency_id: x.agency_id,
        onChange: function () { M.close && M.close(); openCustomer(id, screen); } })]));
    }

    body.appendChild(sec('Status, owner and notes'));
    var selStyle = 'height:34px;padding:0 10px;border:1px solid #D9E1EA;border-radius:9px;font-size:13.5px;max-width:100%';
    statusSel = el('select', { 'aria-label': 'Customer status', style: selStyle },
      Object.keys(r.statuses || CSTATUS).map(function (k) { var o = el('option', { value: k }, [(r.statuses || {})[k] || CSTATUS[k][0]]); if (k === x.status) o.selected = true; return o; }));
    var ctrls = [statusSel];
    if (ag && (ag.admins || []).length) {
      ownerSel = el('select', { 'aria-label': 'Agency owner', style: selStyle }, [el('option', { value: '' }, [ag.owner && ag.owner.derived ? 'Owner: ' + ag.owner.name + ' (first admin)' : 'Owner: not set'])].concat(ag.admins.map(function (u) {
        var o = el('option', { value: String(u.id) }, ['Owner: ' + u.name + (u.email ? ' · ' + u.email : '')]); if (ag.owner && !ag.owner.derived && ag.owner.id === u.id) o.selected = true; return o;
      })));
      ctrls.push(ownerSel);
    }
    body.appendChild(el('div', { style: 'display:flex;gap:8px;flex-wrap:wrap' }, ctrls));
    notesIn = el('textarea', { 'aria-label': 'Notes', placeholder: 'Anything to remember about this customer', style: 'width:100%;box-sizing:border-box;padding:9px 11px;border:1px solid #D9E1EA;border-radius:9px;font:inherit;font-size:13.5px;margin-top:8px;resize:vertical' });
    notesIn.value = x.notes || '';
    notesIn.style.setProperty('min-height', '90px', 'important');   // a global rule zeroes textarea min-height
    body.appendChild(notesIn);
    body.appendChild(el('div', { style: 'margin-top:10px;display:flex;gap:8px;flex-wrap:wrap' }, [
      !x.lead_id ? null : btn('🎯 Open lead', false, function () { M.close && M.close(); window.location.hash = '#sales-lead?id=' + x.lead_id; }),
      x.quote_id ? btn('📄 Proposal PDF', false, function () { pdf({ id: x.quote_id, number: x.quote_number }); }) : null,
    ]));
    kcFold(body);
  }

  /* Everything after a [data-kc-sec] heading, up to the next one, goes into that heading's body. */
  function kcFold(root) {
    var KEY = 'kt_cust_fold', shut = {};
    try { shut = JSON.parse(localStorage.getItem(KEY) || '{}') || {}; } catch (e) { shut = {}; }
    var kids = Array.prototype.slice.call(root.children), cur = null;
    kids.forEach(function (n) {
      if (n.hasAttribute && n.hasAttribute('data-kc-sec')) {
        var head = n, name = head.getAttribute('data-kc-sec');
        var box = el('div', { 'data-kc-body': name });
        head.parentNode.insertBefore(box, head.nextSibling);
        var set = function (open) {
          box.style.display = open ? '' : 'none';
          head.setAttribute('aria-expanded', open ? 'true' : 'false');
          var ch = head.querySelector('.kc-chev'); if (ch) ch.style.transform = open ? '' : 'rotate(-90deg)';
        };
        set(!shut[name]);
        head.addEventListener('click', function () {
          var open = head.getAttribute('aria-expanded') !== 'true';
          set(open);
          if (open) delete shut[name]; else shut[name] = 1;
          try { localStorage.setItem(KEY, JSON.stringify(shut)); } catch (e) {}
        });
        cur = box;
        return;
      }
      if (cur) cur.appendChild(n);
    });
  }

  /* ════════════════════════ PLATFORM SALES REPORT ════════════════════════ */
  var KIND = { plan: ['Plans', '#1F6080'], addon: ['Add-ons', '#7C3AED'], service: ['Services', '#F59E0B'], discount: ['Discounts', '#EF4444'], other: ['Other', '#94A3B8'] };
  function iso(dt) { return dt.getFullYear() + '-' + String(dt.getMonth() + 1).padStart(2, '0') + '-' + String(dt.getDate()).padStart(2, '0'); }
  function presets() {
    var t = new Date(today() + 'T12:00:00'), y = t.getFullYear(), q = Math.floor(t.getMonth() / 3) * 3;
    return [['ytd', 'This year', y + '-01-01'], ['q', 'This quarter', iso(new Date(y, q, 1))], ['m', 'This month', iso(new Date(y, t.getMonth(), 1))],
      ['12', 'Last 12 months', iso(new Date(y - 1, t.getMonth(), t.getDate() + 1))], ['all', 'All time', '2020-01-01']];
  }

  async function renderPlatformSales(c) {
    await whoReady();
    if (!isSuper()) return locked(c, 'KiddieTrac sales reports are for superadmins.');
    css(); clear(c);
    c.appendChild(hero('Sales reports', 'What KiddieTrac has sold and billed — by part number, plan, add-on, service and customer. Platform figures only; no agency sees this.', '💹'));
    var P = presets(), range = { from: P[0][2], to: today(), p: 'ytd' };
    var seg = el('div', { class: 'ki-seg', 'data-kt-noband': '1' });
    var fromI = el('input', { type: 'date', value: range.from }), toI = el('input', { type: 'date', value: range.to });
    c.appendChild(el('div', { class: 'ki-bar' }, [seg, el('div', { class: 'ki-range' }, [el('span', { style: 'font-size:12px;font-weight:700;color:#475569' }, ['From']), fromI, el('span', { style: 'font-size:12px;font-weight:700;color:#475569' }, ['to']), toI])]));
    var host = el('div'); c.appendChild(host);
    function paintSeg() {
      clear(seg);
      P.forEach(function (p) { seg.appendChild(el('button', { type: 'button', 'data-kt-iconized': '1', class: range.p === p[0] ? 'on' : '', onclick: function () { range.p = p[0]; range.from = p[2]; range.to = today(); fromI.value = range.from; toI.value = range.to; paintSeg(); load(); } }, [p[1]])); });
    }
    [fromI, toI].forEach(function (i) { i.addEventListener('change', function () { if (fromI.value && toI.value) { range.from = fromI.value; range.to = toI.value; range.p = ''; paintSeg(); load(); } }); });

    async function load() {
      clear(host); host.appendChild(el('div', { style: 'padding:24px;color:#64748B' }, ['Loading…']));
      var d;
      try { d = await Api.get('/platform/sales-report', { from: range.from, to: range.to }); } catch (e) { clear(host); host.appendChild(el('div', { class: 'ki-box', style: 'color:#B91C1C' }, ['Could not load the report: ' + (e.message || e)])); return; }
      clear(host);
      var T = d.totals || {};
      host.appendChild(el('div', { class: 'ki-tiles' }, [
        tile('💵', 'Revenue invoiced', cents(T.invoiced), (T.count || 0) + ' invoice' + (T.count === 1 ? '' : 's') + ' · before tax', '#1F6080'),
        tile('✅', 'Collected', cents(T.collected), T.invoiced ? Math.round(T.collected / Math.max(1, T.invoiced + T.tax) * 100) + '% of billed (incl. tax)' : 'nothing billed yet', '#10B981'),
        tile('⏳', 'Outstanding', cents(T.outstanding), 'billed, not yet paid', T.outstanding ? '#B45309' : '#94A3B8'),
        tile('🔁', 'Recurring (MRR)', cents(T.mrr_cents), cents(T.arr_cents) + ' a year (ARR), as of today', '#8B5CF6'),
        tile('📝', 'Booked on proposals', cents(T.booked_cents), 'signed proposals in range, invoiced or not', '#0EA5E9'),
        tile('🏢', 'Paying customers', T.customers || 0, 'invoiced in range', '#0D1B2A'),
        tile('🧾', 'Tax collected', cents(T.tax), 'on invoices in range', '#64748B'),
      ]));

      var bm = (d.by_month || []).map(function (m) { return { month: m.month, invoiced: m.invoiced / 100, collected: m.collected / 100 }; });
      var s1 = [{ key: 'invoiced', label: 'Invoiced', color: '#1F6080' }, { key: 'collected', label: 'Collected', color: '#10B981' }];
      var bk = d.by_kind || {}, kt = Object.keys(bk).reduce(function (a, k) { return a + Number(bk[k] || 0); }, 0);
      var cust = d.by_customer || [], cmax = Math.max.apply(null, cust.map(function (x) { return x.cents; }).concat([1]));
      host.appendChild(el('div', { class: 'ki-charts' }, [
        box('📈 Invoiced vs collected — last 12 months', [el('div', { html: barChart(bm, s1, function (r) { return monthLabel(r.month); }, short) })], legend(s1)),
        box('🧩 Revenue by kind', [donut(Object.keys(KIND).map(function (k) { return { label: KIND[k][0], value: Number(bk[k] || 0), color: KIND[k][1], text: cents(bk[k] || 0) + (kt ? ' · ' + Math.round((bk[k] || 0) / kt * 100) + '%' : '') }; }), short(kt / 100), 'invoiced')]),
        box('🏆 Top customers', cust.length ? cust.slice(0, 8).map(function (x) {
          return el('div', { style: 'margin-bottom:9px' }, [
            el('div', { style: 'display:flex;justify-content:space-between;gap:8px;font-size:13px;margin-bottom:3px' }, [el('span', { style: 'font-weight:700;color:#334155;overflow:hidden;text-overflow:ellipsis;white-space:nowrap' }, [x.customer]), el('b', {}, [cents(x.cents)])]),
            el('div', { class: 'ki-share' }, [el('b', { style: 'width:' + Math.max(3, x.cents / cmax * 100) + '%;background:linear-gradient(90deg,#1F6080,#38BDF8)' })]),
          ]);
        }) : [el('div', { style: 'color:#94A3B8;font-size:13px' }, ['No invoices in this range.'])]),
      ]));

      host.appendChild(partTable('🔢 Invoiced by part number', d.by_part || [], function (r) { return Number(r.cents || 0) / 100; }, 'Invoiced', 'Platform invoices in range (void excluded). Older invoices made before itemised lines count under their plan.'));
      host.appendChild(el('div', { style: 'height:14px' }));
      host.appendChild(partTable('📝 Sold on proposals by part number', d.booked || [], function (r) { return Number(r.amount || 0); }, 'Sold', 'Lines on signed or approved proposals in range — what was sold, including one-time services, whether or not it has been invoiced yet. Proposal currencies are added as typed.'));
    }
    function partTable(title, rows, val, valLabel, note) {
      var total = rows.reduce(function (a, r) { return a + val(r); }, 0);
      var wrapEl = el('div', { class: 'ki-box', style: 'padding:0;overflow:hidden' }, [
        el('div', { style: 'padding:14px 16px 4px' }, [el('div', { class: 'ki-h', role: 'heading', 'aria-level': '3', style: 'margin:0 0 4px' }, [title]), el('div', { style: 'font-size:12px;color:#64748B;margin-bottom:8px' }, [note])]),
      ]);
      if (!rows.length) { wrapEl.appendChild(el('div', { style: 'padding:6px 16px 18px;color:#94A3B8;font-size:13px' }, ['Nothing in this range.'])); return wrapEl; }
      var tbl = el('table', { class: 'ki-tbl', 'data-kt-filtered': '1' });
      tbl.appendChild(el('thead', {}, [el('tr', {}, ['Part #', 'Item', 'Kind', 'Qty', valLabel, 'Share'].map(function (h, i) { return el('th', { class: 'nos' + (i === 5 ? ' ki-hide-sm' : ''), style: i === 3 || i === 4 ? 'text-align:right' : '' }, [h]); }))]));
      var tb = el('tbody');
      rows.forEach(function (r) {
        var k = KIND[r.kind] || KIND.other, v = val(r), pct = total ? v / total * 100 : 0;
        tb.appendChild(el('tr', {}, [
          el('td', {}, [r.part_number ? el('span', { class: 'ki-pn' }, [r.part_number]) : el('span', { style: 'color:#94A3B8' }, ['—'])]),
          el('td', { style: 'font-weight:700' }, [r.name || '—']),
          el('td', {}, [el('span', { class: 'ki-pill', style: 'color:' + k[1] + ';background:' + k[1] + '1A' }, [k[0]])]),
          el('td', { style: 'text-align:right' }, [String(Math.round(Number(r.qty || 0) * 100) / 100)]),
          el('td', { style: 'text-align:right;font-weight:800;white-space:nowrap' }, [money(v, 2)]),
          el('td', { class: 'ki-hide-sm', style: 'min-width:120px' }, [el('div', { style: 'display:flex;align-items:center;gap:8px' }, [el('div', { class: 'ki-share', style: 'flex:1' }, [el('b', { style: 'width:' + Math.max(2, pct) + '%;background:' + k[1] })]), el('span', { style: 'font-size:11.5px;color:#64748B;width:34px;text-align:right' }, [Math.round(pct) + '%'])])]),
        ]));
      });
      tb.appendChild(el('tr', {}, [el('td', { colspan: '4', style: 'font-weight:800;background:#F8FAFC' }, ['Total']), el('td', { style: 'text-align:right;font-weight:800;background:#F8FAFC;white-space:nowrap' }, [money(total, 2)]), el('td', { class: 'ki-hide-sm', style: 'background:#F8FAFC' })]));
      tbl.appendChild(tb);
      wrapEl.appendChild(el('div', { style: 'overflow-x:auto' }, [tbl]));
      return wrapEl;
    }
    paintSeg(); load();
  }

  /* ════════════════════════ EXPENSES (KiddieTrac's own) ════════════════════════
     Sales → Expenses (2026-10-24, Anthony: "manage all expenses with kiddietrac for travel, 3rd
     party services, monthly generic expenses, food etc - full system with graphs, stats").
     Reps log their own (submitted for approval); superadmins see all, approve / reject / mark
     reimbursed, and keep the recurring costs. Backend: PlatformExpenseController. */
  var EXP_STATUS = { submitted: ['Waiting approval', '#B45309', '#FFFBEB'], approved: ['Approved', '#047857', '#ECFDF5'],
    rejected: ['Not approved', '#B91C1C', '#FEF2F2'], reimbursed: ['Reimbursed', '#1E40AF', '#EFF6FF'] };
  var EXP_COLORS = ['#1F6080', '#1DB9C3', '#7C3AED', '#F59E0B', '#EF4444', '#10B981', '#2F5BD3', '#EC4899', '#64748B', '#0EA5E9', '#84CC16', '#A855F7'];
  function expSend(method, url, form) {
    var tk = tok();
    return fetch(apiBase() + url, { method: method, headers: { Authorization: 'Bearer ' + tk, Accept: 'application/json' }, body: form })
      .then(function (r) { return r.json().catch(function () { return {}; }).then(function (j) { if (!r.ok) throw new Error(j.message || ('HTTP ' + r.status)); return j; }); });
  }
  function expPill(s) { var x = EXP_STATUS[s] || [s, '#475569', '#F1F5F9']; return el('span', { class: 'ki-pill', style: 'color:' + x[1] + ';background:' + x[2] }, [x[0]]); }
  function curMoney(v, cur) { return (cur === 'USD' ? 'US$' : '$') + Number(v || 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 }); }

  /* The open sub-tab outlives a re-render: on phones the shell redraws the screen just after a popup closes, which
     used to drop you back on Expenses while you were editing suppliers. */
  var EXP_TAB = 'expenses';
  async function renderExpenses(c) {
    await whoReady();
    if (!salesOk()) return locked(c, 'Expenses are for the sales team and superadmins.');
    css(); clear(c);
    c.appendChild(hero('Expenses', 'Travel, meals, third-party services and running costs — logged, approved and reported in one place.', '🧾'));
    /* Expenses | Suppliers (2026-11-02). A real tablist, so kt-tab-band draws the band and the phone ‹ › arrows. */
    var tab = EXP_TAB;
    var tabs = el('div', { role: 'tablist', 'aria-label': 'Expenses sections', style: 'display:flex;gap:4px;margin:0 0 12px' });
    function paintTabs() {
      clear(tabs);
      [['expenses', '🧾 Expenses'], ['suppliers', '🏪 Suppliers']].forEach(function (t) {
        tabs.appendChild(el('button', { type: 'button', role: 'tab', 'aria-selected': tab === t[0] ? 'true' : 'false', class: tab === t[0] ? 'active' : '',
          onclick: function () { if (tab === t[0]) return; tab = EXP_TAB = t[0]; paintTabs(); showTab(); } }, [t[1]]));
      });
    }
    paintTabs();
    c.appendChild(tabs);
    var P = presets(), range = { from: P[0][2], to: today(), p: 'ytd' }, D = null;
    var view = { status: 'all', cat: '', q: '' };
    var top = el('div', { class: 'ki-bar' });
    var seg = el('div', { class: 'ki-seg', 'data-kt-noband': '1' });
    var fromI = el('input', { type: 'date', value: range.from }), toI = el('input', { type: 'date', value: range.to });
    var actions = el('div', { 'data-kt-noband': '1', style: 'display:flex;gap:8px;flex-wrap:wrap' });
    top.appendChild(actions);
    top.appendChild(el('div', { class: 'ki-range' }, [seg, el('span', { style: 'font-size:12px;font-weight:700;color:#475569' }, ['From']), fromI, el('span', { style: 'font-size:12px;font-weight:700;color:#475569' }, ['to']), toI]));
    c.appendChild(top);
    var host = el('div'); c.appendChild(host);
    function showTab() {
      if (tab === 'suppliers') { top.style.display = 'none'; paintSuppliers(); }
      else { top.style.display = ''; load(); }
    }
    function paintSeg() {
      clear(seg);
      P.forEach(function (p) { seg.appendChild(el('button', { type: 'button', 'data-kt-iconized': '1', class: range.p === p[0] ? 'on' : '', onclick: function () { range.p = p[0]; range.from = p[2]; range.to = today(); fromI.value = range.from; toI.value = range.to; paintSeg(); load(); } }, [p[1]])); });
    }
    [fromI, toI].forEach(function (i) { i.addEventListener('change', function () { if (fromI.value && toI.value) { range.from = fromI.value; range.to = toI.value; range.p = ''; paintSeg(); load(); } }); });
    function catOf(k) { var x = (D && D.categories || []).filter(function (q) { return q.key === k; })[0]; return x || { label: k, icon: '🧾' }; }

    async function load() {
      clear(host); host.appendChild(el('div', { style: 'padding:24px;color:#64748B' }, ['Loading…']));
      try { D = await Api.get('/sales/expenses', { from: range.from, to: range.to }); }
      catch (e) { clear(host); host.appendChild(el('div', { class: 'ki-box', style: 'color:#B91C1C' }, ['Could not load expenses: ' + (e.message || e)])); return; }
      clear(actions);
      actions.appendChild(btn('➕ Add expense', true, function () { openForm(null); }));
      if (D.is_super) actions.appendChild(btn('🔁 Recurring costs', false, openRecurring));
      paint();
    }
    function paint() {
      clear(host);
      var S = D.stats || {}, chg = S.last_month_cents ? Math.round((S.this_month_cents - S.last_month_cents) / S.last_month_cents * 100) : null;
      var tiles = [
        tile('📅', 'This month', cents(S.this_month_cents), chg == null ? 'nothing last month' : (chg >= 0 ? '▲ ' : '▼ ') + Math.abs(chg) + '% vs last month', '#1F6080'),
        tile('🧾', 'In range', cents(S.total_cents), (S.count || 0) + ' expense' + (S.count === 1 ? '' : 's') + (S.total_usd_cents ? ' · + US$' + (S.total_usd_cents / 100).toFixed(2) : ''), '#1DB9C3'),
        tile('📊', 'Average a month', cents(S.avg_month_cents), 'over the chosen range', '#7C3AED'),
        tile('⏳', 'Waiting approval', S.pending_count || 0, S.pending_count ? cents(S.pending_cents) + ' to review' : 'nothing waiting', S.pending_count ? '#B45309' : '#94A3B8'),
        tile('💸', 'Owed to staff', cents(S.owed_cents), S.owed_count ? S.owed_count + ' approved, paid personally' : 'all reimbursed', S.owed_count ? '#EF4444' : '#94A3B8'),
      ];
      if (D.is_super) tiles.push(tile('🔁', 'Recurring a month', cents(S.monthly_recurring_cents), (D.recurring || []).filter(function (r) { return r.active; }).length + ' active', '#10B981'));
      tiles.push(tile('🏛️', 'Tax paid', cents(S.tax_cents), 'on expenses in range', '#64748B'));
      host.appendChild(el('div', { class: 'ki-tiles' }, tiles));

      var bm = (D.by_month || []).map(function (m) { return { month: m.month, v: m.cents / 100 }; });
      var cats = D.by_category || [], ctot = cats.reduce(function (a, x) { return a + x.cents; }, 0);
      var ven = D.top_vendors || [], vmax = Math.max.apply(null, ven.map(function (x) { return x.cents; }).concat([1]));
      var s1 = [{ key: 'v', label: 'Spent (CAD)', color: '#1F6080' }];
      host.appendChild(el('div', { class: 'ki-charts' }, [
        box('📈 Spend by month — last 12 months', [el('div', { html: barChart(bm, s1, function (r) { return monthLabel(r.month); }, short) })], legend(s1)),
        box('🍩 By category', [donut(cats.map(function (x, i) { var k = catOf(x.key); return { label: k.icon + ' ' + k.label, value: x.cents, color: EXP_COLORS[i % EXP_COLORS.length], text: cents(x.cents) + (ctot ? ' · ' + Math.round(x.cents / ctot * 100) + '%' : '') }; }), short(ctot / 100), 'in range')]),
        box('🏪 Top vendors', ven.length ? ven.map(function (x) {
          return el('div', { style: 'margin-bottom:9px' }, [
            el('div', { style: 'display:flex;justify-content:space-between;gap:8px;font-size:13px;margin-bottom:3px' }, [el('span', { style: 'font-weight:700;color:#334155;overflow:hidden;text-overflow:ellipsis;white-space:nowrap' }, [x.vendor]), el('b', {}, [cents(x.cents)])]),
            el('div', { class: 'ki-share' }, [el('b', { style: 'width:' + Math.max(3, x.cents / vmax * 100) + '%;background:linear-gradient(90deg,#1F6080,#1DB9C3)' })]),
          ]);
        }) : [el('div', { style: 'color:#94A3B8;font-size:13px' }, ['No vendors yet.'])]),
      ]));

      // filters
      var all = D.expenses || [];
      var fseg = el('div', { class: 'ki-seg', 'data-kt-noband': '1' });
      [['all', 'All'], ['submitted', 'Waiting approval'], ['approved', 'Approved'], ['reimbursed', 'Reimbursed'], ['rejected', 'Not approved']].forEach(function (f) {
        var n = f[0] === 'all' ? all.length : all.filter(function (x) { return x.status === f[0]; }).length;
        if (f[0] !== 'all' && !n) return;
        fseg.appendChild(el('button', { type: 'button', 'data-kt-iconized': '1', class: view.status === f[0] ? 'on' : '', onclick: function () { view.status = f[0]; paint(); } }, [f[1], el('i', {}, [String(n)])]));
      });
      var catSel = el('select', { style: 'height:32px;padding:0 28px 0 9px;border:1px solid #D9E1EA;border-radius:9px;font-size:13px;width:auto;max-width:220px' },
        [el('option', { value: '' }, ['All categories'])].concat((D.categories || []).map(function (k) { var o = el('option', { value: k.key }, [k.icon + ' ' + k.label]); if (view.cat === k.key) o.selected = true; return o; })));
      catSel.addEventListener('change', function () { view.cat = catSel.value; paint(); });
      var search = el('input', { type: 'search', value: view.q, placeholder: '🔍 Search vendor, notes, who…', style: 'height:32px;padding:0 11px;border:1px solid #D9E1EA;border-radius:9px;font-size:13px;width:240px;max-width:100%;box-sizing:border-box' });
      var t; search.addEventListener('input', function () { clearTimeout(t); t = setTimeout(function () { view.q = search.value.trim(); paintTable(); }, 200); });
      host.appendChild(el('div', { class: 'ki-bar' }, [fseg, el('div', { style: 'display:flex;gap:8px;flex-wrap:wrap' }, [catSel, search])]));
      var tblHost = el('div', { class: 'ki-wrap' }); host.appendChild(tblHost);

      function paintTable() {
        var q = view.q.toLowerCase();
        var rows = all.filter(function (x) { return (view.status === 'all' || x.status === view.status) && (!view.cat || x.category === view.cat) && (!q || [x.vendor, x.description, x.notes, x.user, catOf(x.category).label].join(' ').toLowerCase().indexOf(q) > -1); });
        clear(tblHost);
        if (!rows.length) { tblHost.appendChild(el('div', { style: 'padding:28px;text-align:center;color:#64748B' }, [all.length ? 'No expenses match.' : 'No expenses in this range yet. Use “Add expense” to log one.'])); return; }
        var tbl = el('table', { class: 'ki-tbl', 'data-kt-filtered': '1' });
        var heads = ['Date', 'Category', 'Vendor / what', D.is_super ? 'Who' : null, 'Amount', 'Paid by', 'Status', ''].filter(function (h) { return h !== null; });
        tbl.appendChild(el('thead', {}, [el('tr', {}, heads.map(function (h) { return el('th', { class: 'nos' + (h === 'Paid by' || h === 'Who' ? ' ki-hide-sm' : '') }, [h]); }))]));
        var tb = el('tbody');
        rows.forEach(function (x) {
          var k = catOf(x.category);
          var acts = [el('button', { type: 'button', onclick: function (ev) { ev.stopPropagation(); openView(x); } }, ['👁 View'])];
          if (x.can_edit) acts.push(el('button', { type: 'button', onclick: function (ev) { ev.stopPropagation(); openForm(x); } }, ['✏️ Edit']));
          if (D.is_super && x.status === 'submitted') acts.push(el('button', { type: 'button', onclick: function (ev) { ev.stopPropagation(); act(x, 'approve'); } }, ['✅ Approve']));
          if (D.is_super && (x.status === 'submitted' || x.status === 'approved')) acts.push(el('button', { type: 'button', onclick: function (ev) { ev.stopPropagation(); rejectIt(x); } }, ['❌ Don’t approve']));
          if (D.is_super && x.reimbursable && (x.status === 'approved' || x.status === 'submitted')) acts.push(el('button', { type: 'button', onclick: function (ev) { ev.stopPropagation(); act(x, 'reimburse'); } }, ['💸 Mark reimbursed']));
          if (x.has_receipt) acts.push(el('button', { type: 'button', onclick: function (ev) { ev.stopPropagation(); receipt(x); } }, ['📎 Download receipt']));
          if (x.can_edit) acts.push(el('button', { type: 'button', onclick: function (ev) { ev.stopPropagation(); del(x); } }, ['🗑 Delete']));
          var cells = [
            el('td', { style: 'white-space:nowrap;color:#475569' }, [dayLabel(x.expense_date)]),
            el('td', {}, [el('span', { style: 'white-space:nowrap;font-weight:700;color:#334155' }, [k.icon + ' ' + k.label]), x.recurring ? el('div', { style: 'font-size:11px;color:#10B981;font-weight:700' }, ['🔁 recurring']) : null]),
            el('td', {}, [el('div', { style: 'font-weight:700;color:#0D1B2A' }, [x.vendor || '—']), x.description ? el('div', { style: 'font-size:12px;color:#64748B;max-width:260px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap' }, [x.description]) : null]),
          ];
          if (D.is_super) cells.push(el('td', { class: 'ki-hide-sm', style: 'white-space:nowrap;color:#475569' }, [x.user || '—']));
          cells.push(el('td', { style: 'white-space:nowrap;font-weight:800;color:#0D1B2A' }, [curMoney(x.total, x.currency), x.has_receipt ? el('span', { title: 'Receipt attached', style: 'margin-left:5px' }, ['📎']) : null]));
          cells.push(el('td', { class: 'ki-hide-sm', style: 'white-space:nowrap;color:#475569;font-size:12.5px' }, [(D.methods || {})[x.payment_method] || x.payment_method]));
          cells.push(el('td', {}, [expPill(x.status)]));
          cells.push(el('td', { style: 'padding:6px 12px;text-align:center;white-space:nowrap' }, acts));
          tb.appendChild(el('tr', { class: 'go', onclick: function () { openView(x); } }, cells));
        });
        tbl.appendChild(tb);
        tblHost.appendChild(el('div', { style: 'overflow-x:auto' }, [tbl]));
        var cad = rows.filter(function (r) { return r.currency !== 'USD' && r.status !== 'rejected'; }).reduce(function (a, r) { return a + r.total; }, 0);
        var usd = rows.filter(function (r) { return r.currency === 'USD' && r.status !== 'rejected'; }).reduce(function (a, r) { return a + r.total; }, 0);
        tblHost.appendChild(el('div', { style: 'padding:10px 14px;border-top:1px solid #EEF1F5;font-size:12.5px;color:#64748B;display:flex;justify-content:space-between;gap:8px;flex-wrap:wrap' }, [
          el('span', {}, [rows.length + ' expense' + (rows.length === 1 ? '' : 's') + ' (not-approved ones left out of the total)']), el('b', { style: 'color:#0D1B2A' }, ['Total ' + curMoney(cad, 'CAD') + (usd ? ' + ' + curMoney(usd, 'USD') : '')]),
        ]));
      }
      paintTable();
    }

    function modal(title, body, acts, large) { if (KT.Shell && KT.Shell.Modal) KT.Shell.Modal.open({ title: title, body: body, actions: acts, large: !!large }); }
    function field(label, node, wide) { if (node && node.setAttribute && node.type !== 'file' && node.type !== 'checkbox') node.setAttribute('data-kt-nofit', '1'); return el('label', { style: 'display:block;' + (wide ? 'grid-column:1 / -1;' : '') }, [el('div', { style: 'font-size:12px;font-weight:700;color:#475569;margin-bottom:4px' }, [label]), node]); }
    var F = 'width:100%;box-sizing:border-box;height:34px;padding:0 10px;border:1px solid #D9E1EA;border-radius:9px;font:inherit;font-size:14px';

    /* VENDOR = ONE OF KIDDIETRAC'S SUPPLIERS (2026-11-02, Anthony: "can you make it where I can add a supplier and
       allow to choose this supplier in the vendor drop down"). A plain <select> — kt-select-sheet draws it on phones,
       kt-field-height sizes it — reading "Select a supplier…", "＋ Add new supplier…", then the suppliers A–Z. A
       vendor typed before the list existed (or an archived supplier) stays selected as its own option, so editing
       never loses it. "Add new" opens the house prompt (KT.prompt: navy-to-teal band, the guard's ✕) on top of the
       expense form; the supplier is created and selected. The expense still stores the NAME as `vendor` (Top
       vendors, search) plus supplier_id. Backend: SalesSupplierController. */
    var NEW_SUP = '__new__';
    function byName(a, b) { return String(a.name).localeCompare(String(b.name), undefined, { sensitivity: 'base' }); }
    function supById(id) { return (D && D.suppliers || []).filter(function (s) { return String(s.id) === String(id); })[0] || null; }
    function supByName(n) { var k = String(n || '').trim().toLowerCase(); return k ? (D && D.suppliers || []).filter(function (s) { return String(s.name).trim().toLowerCase() === k; })[0] || null : null; }
    function remember(s) {
      if (!D) return;
      D.suppliers = (D.suppliers || []).filter(function (z) { return z.id !== s.id; });
      if (!s.archived) D.suppliers.push({ id: s.id, name: s.name });
      D.suppliers.sort(byName);
    }
    /* Shell.Modal closes on Esc from a capture listener on document, so an Esc meant for the prompt on top would
       shut the expense form underneath as well. While the prompt is up, Esc cancels only the prompt. */
    function escOnlyPrompt() {
      function h(e) {
        if (e.key !== 'Escape') return;
        var c = document.querySelectorAll('[data-action="cancel"]');
        if (!c.length) return;
        e.stopImmediatePropagation(); e.preventDefault(); c[c.length - 1].click();
      }
      window.addEventListener('keydown', h, true);
      return function () { window.removeEventListener('keydown', h, true); };
    }
    function supplierPrompt(title, okLabel, v, withNotes) {
      v = v || {};
      var fields = [
        { key: 'name', label: 'Name *', placeholder: 'e.g. Staples', value: v.name || '' },
        { key: 'website', label: 'Website', placeholder: 'e.g. staples.ca', value: v.website || '' },
        { key: 'email', label: 'Email', type: 'email', placeholder: 'e.g. orders@supplier.com', value: v.email || '' },
        { key: 'phone', label: 'Phone', type: 'tel', placeholder: 'e.g. 1-800-555-0100', value: v.phone || '' },
      ];
      if (withNotes) fields.push({ key: 'notes', label: 'Notes', type: 'textarea', rows: 3, placeholder: 'Account number, who to call…', value: v.notes || '' });
      if (!KT.prompt) return Promise.resolve(null);
      var off = escOnlyPrompt();
      return KT.prompt({ title: title, okLabel: okLabel, fields: fields }).then(function (r) { off(); return r; }, function (e) { off(); throw e; });
    }
    function addSupplier(pre) {
      return supplierPrompt('🏪 Add a supplier', 'Add supplier', pre).then(function (v) {
        if (!v) return null;
        var name = String(v.name || '').trim();
        if (!name) { toast('⚠️', 'Give the supplier a name', '', '#DC2626'); return addSupplier(v); }
        return Api.post('/sales/suppliers', { name: name, website: (v.website || '').trim(), email: (v.email || '').trim(), phone: (v.phone || '').trim() })
          .then(function (r) {
            var sp = r.supplier; remember(sp);
            toast('🏪', r.existed ? sp.name + ' is already a supplier' : 'Supplier added', r.existed ? 'It is selected for you.' : sp.name);
            return sp;
          })
          .catch(function (e) { toast('⚠️', 'Could not add the supplier', (e && e.message) || '', '#DC2626'); return addSupplier(v); });
      });
    }
    function supplierSelect(curId, curText) {
      var sel = el('select', { style: F, 'aria-label': 'Vendor' });
      var extra = '', last = '';
      function fill(val) {
        clear(sel);
        sel.appendChild(el('option', { value: '' }, ['Select a supplier…']));
        sel.appendChild(el('option', { value: NEW_SUP }, ['＋ Add new supplier…']));
        (D && D.suppliers || []).slice().sort(byName).forEach(function (s) { sel.appendChild(el('option', { value: String(s.id) }, [s.name])); });
        if (extra) sel.appendChild(el('option', { value: 'txt:' + extra }, [extra]));
        sel.value = val; if (sel.value !== val) sel.value = '';
        last = sel.value;
      }
      var start = '';
      if (curId && supById(curId)) start = String(curId);
      else if (curText && supByName(curText)) start = String(supByName(curText).id);
      else if (curText) { extra = String(curText).trim(); start = 'txt:' + extra; }
      fill(start);
      sel.addEventListener('change', function () {
        if (sel.value !== NEW_SUP) { last = sel.value; return; }
        sel.value = last;                       // until the new one exists
        addSupplier().then(function (sp) {
          if (!sp || !sel.isConnected) return;
          fill(String(sp.id));
          sel.dispatchEvent(new Event('change', { bubbles: true }));
        });
      });
      sel.vendorValue = function () {
        var v = sel.value;
        if (!v || v === NEW_SUP) return { supplier_id: '', vendor: '' };
        if (v.indexOf('txt:') === 0) return { supplier_id: '', vendor: v.slice(4) };
        var sp = supById(v);
        return { supplier_id: v, vendor: sp ? sp.name : '' };
      };
      return sel;
    }

    /* Suppliers tab: everyone on the sales team sees the list and can add; a superadmin edits and archives
       (plain buttons in the last cell — kt-row-actions turns them into the house ⋮). */
    var supView = { q: '', archived: false };
    function paintSuppliers() {
      clear(host); host.appendChild(el('div', { style: 'padding:24px;color:#64748B' }, ['Loading…']));
      Api.get('/sales/suppliers', supView.archived ? { archived: 1 } : {}).then(function (r) {
        if (tab !== 'suppliers') return;
        var list = r.suppliers || [], sup = !!r.is_super;
        clear(host);
        var addB = btn('➕ Add supplier', true, function () { addSupplier().then(function (sp) { if (sp) paintSuppliers(); }); });
        var search = el('input', { type: 'search', value: supView.q, placeholder: '🔍 Search suppliers…', style: 'height:32px;padding:0 11px;border:1px solid #D9E1EA;border-radius:9px;font-size:13px;width:240px;max-width:100%;box-sizing:border-box' });
        var right = [search];
        if (sup) {
          var arch = el('input', { type: 'checkbox' }); arch.checked = supView.archived;
          arch.addEventListener('change', function () { supView.archived = arch.checked; paintSuppliers(); });
          right.unshift(el('label', { style: 'display:inline-flex;gap:7px;align-items:center;font-size:13px;color:#475569;font-weight:600' }, [arch, 'Show archived']));
        }
        host.appendChild(el('div', { class: 'ki-bar' }, [el('div', { 'data-kt-noband': '1', style: 'display:flex;gap:8px;flex-wrap:wrap' }, [addB]), el('div', { style: 'display:flex;gap:10px;flex-wrap:wrap;align-items:center' }, right)]));
        var wrap = el('div', { class: 'ki-wrap' }); host.appendChild(wrap);
        function draw() {
          var q = supView.q.toLowerCase();
          var rows = list.filter(function (s) { return !q || [s.name, s.website, s.email, s.phone, s.notes].join(' ').toLowerCase().indexOf(q) > -1; });
          clear(wrap);
          if (!rows.length) { wrap.appendChild(el('div', { style: 'padding:28px;text-align:center;color:#64748B' }, [list.length ? 'No suppliers match.' : 'No suppliers yet. Use “Add supplier”, or pick “＋ Add new supplier…” in an expense’s Vendor list.'])); return; }
          var tbl = el('table', { class: 'ki-tbl', 'data-kt-filtered': '1' });
          var heads = ['Supplier', 'Contact', 'Expenses', 'Added by'].concat(sup ? [''] : []);
          tbl.appendChild(el('thead', {}, [el('tr', {}, heads.map(function (h) { return el('th', { class: 'nos' + (h === 'Contact' || h === 'Added by' ? ' ki-hide-sm' : '') }, [h]); }))]));
          var tb = el('tbody');
          rows.forEach(function (s) {
            var web = s.website ? el('a', { href: s.website, target: '_blank', rel: 'noopener', onclick: function (ev) { ev.stopPropagation(); }, style: 'font-size:12px;color:#1F6080;text-decoration:none' }, [s.website.replace(/^https?:\/\//i, '').replace(/\/$/, '')]) : null;
            var cells = [
              el('td', {}, [el('div', { style: 'font-weight:700;color:#0D1B2A;display:flex;gap:6px;align-items:center;flex-wrap:wrap' }, [s.name, s.archived ? el('span', { class: 'ki-pill', style: 'color:#64748B;background:#F1F5F9' }, ['Archived']) : null]), web ? el('div', {}, [web]) : null]),
              el('td', { class: 'ki-hide-sm', style: 'font-size:12.5px;color:#475569' }, [[s.email, s.phone].filter(Boolean).join(' · ') || '—']),
              el('td', { style: 'white-space:nowrap;font-size:12.5px;color:#475569' }, [s.expense_count ? s.expense_count + ' · ' + cents(s.spent_cents, 2) : '—']),
              el('td', { class: 'ki-hide-sm', style: 'white-space:nowrap;font-size:12.5px;color:#475569' }, [s.created_by || '—']),
            ];
            if (sup) {
              var acts = [el('button', { type: 'button', onclick: function (ev) { ev.stopPropagation(); editSupplier(s); } }, ['✏️ Edit'])];
              acts.push(s.archived
                ? el('button', { type: 'button', onclick: function (ev) { ev.stopPropagation(); restoreSupplier(s); } }, ['♻️ Restore'])
                : el('button', { type: 'button', onclick: function (ev) { ev.stopPropagation(); archiveSupplier(s); } }, ['🗄 Archive']));
              cells.push(el('td', { style: 'padding:6px 12px;text-align:center;white-space:nowrap' }, acts));
            }
            var tr = el('tr', { class: sup ? 'go' : '', style: s.archived ? 'opacity:.6' : '' }, cells);
            if (sup) tr.addEventListener('click', function () { editSupplier(s); });
            tb.appendChild(tr);
          });
          tbl.appendChild(tb);
          wrap.appendChild(el('div', { style: 'overflow-x:auto' }, [tbl]));
          wrap.appendChild(el('div', { style: 'padding:10px 14px;border-top:1px solid #EEF1F5;font-size:12.5px;color:#64748B' }, [rows.length + ' supplier' + (rows.length === 1 ? '' : 's') + (sup ? '' : ' · a superadmin can rename or archive one')]));
        }
        var t; search.addEventListener('input', function () { clearTimeout(t); t = setTimeout(function () { supView.q = search.value.trim(); draw(); }, 200); });
        draw();
      }).catch(function (e) {
        clear(host); host.appendChild(el('div', { class: 'ki-box', style: 'color:#B91C1C' }, ['Could not load suppliers: ' + ((e && e.message) || e)]));
      });
    }
    function editSupplier(s) {
      supplierPrompt('✏️ Edit supplier', 'Save supplier', s, true).then(function (v) {
        if (!v) return;
        if (!String(v.name || '').trim()) { toast('⚠️', 'Give the supplier a name', '', '#DC2626'); return editSupplier(Object.assign({}, s, v)); }
        Api.patch('/sales/suppliers/' + s.id, { name: v.name.trim(), website: (v.website || '').trim(), email: (v.email || '').trim(), phone: (v.phone || '').trim(), notes: (v.notes || '').trim() })
          .then(function (r) { remember(r.supplier); toast('🏪', 'Supplier saved', r.supplier.name); paintSuppliers(); })
          .catch(function (e) { toast('⚠️', 'Could not save the supplier', (e && e.message) || '', '#DC2626'); editSupplier(Object.assign({}, s, v)); });
      });
    }
    async function archiveSupplier(s) {
      var ok = KT.confirm ? await KT.confirm({ title: 'Archive ' + s.name + '?', description: 'It leaves the Vendor list. Expenses already logged keep it, and you can bring it back from “Show archived”.', okLabel: 'Archive', tone: 'danger' }) : false;
      if (!ok) return;
      try { await Api.delete('/sales/suppliers/' + s.id); remember(Object.assign({}, s, { archived: true })); toast('🗄', 'Supplier archived', s.name); paintSuppliers(); }
      catch (e) { toast('⚠️', 'Could not archive', (e && e.message) || '', '#DC2626'); }
    }
    function restoreSupplier(s) {
      Api.patch('/sales/suppliers/' + s.id, { archived: false })
        .then(function (r) { remember(r.supplier); toast('♻️', 'Supplier back on the list', s.name); paintSuppliers(); })
        .catch(function (e) { toast('⚠️', 'Could not restore', (e && e.message) || '', '#DC2626'); });
    }

    function openForm(x) {
      var f = {};
      var cats = (D.categories || []).map(function (k) { var o = el('option', { value: k.key }, [k.icon + ' ' + k.label]); if (x && x.category === k.key) o.selected = true; return o; });
      f.date = el('input', { type: 'date', value: x ? x.expense_date : today(), style: F });
      f.cat = el('select', { style: F }, (x ? [] : [el('option', { value: '', selected: 'selected', disabled: 'disabled' }, ['Choose a category…'])]).concat(cats));
      f.vendor = supplierSelect(x ? x.supplier_id : null, x ? (x.vendor || '') : '');
      f.desc = el('input', { type: 'text', maxlength: '500', value: x ? (x.description || '') : '', placeholder: 'What it was for', style: F });
      f.amount = el('input', { type: 'number', step: '0.01', min: '0', value: x ? x.amount : '', placeholder: '0.00', style: F });
      f.tax = el('input', { type: 'number', step: '0.01', min: '0', value: x ? x.tax : '', placeholder: '0.00', style: F });
      f.cur = el('select', { style: F }, ['CAD', 'USD'].map(function (k) { var o = el('option', { value: k }, [k]); if (x && x.currency === k) o.selected = true; return o; }));
      f.method = el('select', { style: F }, Object.keys(D.methods || {}).map(function (k) { var o = el('option', { value: k }, [D.methods[k]]); if ((x ? x.payment_method : 'company_card') === k) o.selected = true; return o; }));
      f.file = el('input', { type: 'file', accept: '.pdf,image/*', 'data-kt-noface': '1', style: 'font-size:13px' });
      f.notes = el('textarea', { rows: '2', maxlength: '2000', placeholder: 'Anything else (who you met, which prospect…)', style: F.replace('height:34px;', 'min-height:60px;padding:8px 10px;') });
      f.notes.value = x ? (x.notes || '') : '';
      var appr = D.is_super && !x ? el('label', { style: 'grid-column:1 / -1;display:flex;gap:8px;align-items:center;font-size:13px;color:#334155' }, [f.approved = el('input', { type: 'checkbox', checked: 'checked' }), 'Log as approved (company spend — no review needed)']) : null;
      var body = el('div', { style: 'display:grid;grid-template-columns:1fr 1fr;gap:12px' }, [
        field('Date *', f.date), field('Category *', f.cat), field('Vendor', f.vendor), field('Paid by', f.method),
        field('What it was for', f.desc, true), field('Amount (before tax) *', f.amount), field('Tax', f.tax), field('Currency', f.cur),
        field(x && x.has_receipt ? 'Replace receipt (' + (x.receipt_name || 'attached') + ')' : 'Receipt (photo or PDF)', f.file), field('Notes', f.notes, true), appr,
        !D.is_super ? el('div', { style: 'grid-column:1 / -1;font-size:12px;color:#64748B' }, ['A superadmin reviews it. Pick “Paid personally” if you need it paid back to you.']) : null,
      ]);
      modal(x ? '✏️ Edit expense' : '🧾 Add expense', body, [
        { label: 'Cancel' },
        { label: x ? 'Save changes' : (D.is_super ? 'Add expense' : 'Submit for approval'), primary: true, handler: function () {
          if (!f.date.value || !f.cat.value || f.amount.value === '') { toast('⚠️', 'Date, category and amount are needed', '', '#DC2626'); return false; }
          var fd = new FormData();
          var vv = f.vendor.vendorValue();
          fd.append('expense_date', f.date.value); fd.append('category', f.cat.value); fd.append('vendor', vv.vendor); fd.append('supplier_id', vv.supplier_id); fd.append('description', f.desc.value.trim());
          fd.append('amount', f.amount.value); fd.append('tax', f.tax.value || '0'); fd.append('currency', f.cur.value); fd.append('payment_method', f.method.value);
          fd.append('notes', f.notes.value.trim());
          if (f.approved) fd.append('approved', f.approved.checked ? '1' : '0');
          if (f.file.files && f.file.files[0]) fd.append('receipt', f.file.files[0]);
          expSend('POST', x ? '/sales/expenses/' + x.id : '/sales/expenses', fd)
            .then(function (r) { toast('🧾', x ? 'Saved' : (r.status === 'submitted' ? 'Submitted for approval' : 'Expense added')); load(); })
            .catch(function (e) { toast('⚠️', 'Could not save', e.message || '', '#DC2626'); });
        } },
      ]);
    }
    function openView(x) {
      var k = catOf(x.category);
      var row = function (l, v) { return v ? el('div', { style: 'display:flex;gap:12px;padding:7px 0;border-bottom:1px solid #EEF2F6;font-size:13.5px' }, [el('div', { style: 'flex:0 0 130px;color:#64748B;font-weight:700' }, [l]), el('div', { style: 'flex:1;color:#0F172A' }, [v])]) : null; };
      var body = el('div', {}, [
        el('div', { style: 'display:flex;justify-content:space-between;align-items:center;gap:10px;margin-bottom:8px' }, [el('div', { style: 'font-size:24px;font-weight:800;color:#0D1B2A' }, [curMoney(x.total, x.currency)]), expPill(x.status)]),
        row('Category', k.icon + ' ' + k.label + (x.recurring ? ' · 🔁 recurring' : '')), row('Date', dayLabel(x.expense_date)), row('Vendor', x.vendor), row('What for', x.description),
        row('Amount', curMoney(x.amount, x.currency) + (x.tax ? ' + tax ' + curMoney(x.tax, x.currency) : '')), row('Paid by', (D.methods || {})[x.payment_method] + (x.reimbursable ? ' — to be paid back' : '')),
        row('Logged by', x.user), row(x.status === 'rejected' ? 'Reviewed by' : 'Approved by', x.approved_by ? x.approved_by + (x.approved_at ? ' · ' + dayLabel(x.approved_at) : '') : null),
        row('Reason', x.reject_reason), row('Reimbursed', x.reimbursed_at ? dayLabel(x.reimbursed_at) : null), row('Notes', x.notes),
        row('Receipt', x.has_receipt ? (x.receipt_name || 'Attached') : 'None attached'),
      ]);
      var acts = [{ label: 'Close' }];
      if (x.has_receipt) acts.unshift({ label: '📎 Download receipt', handler: function () { receipt(x); return false; } });
      if (x.can_edit) acts.unshift({ label: '✏️ Edit', handler: function () { setTimeout(function () { openForm(x); }, 0); } });
      if (D.is_super && x.status === 'submitted') acts.unshift({ label: '✅ Approve', primary: true, handler: function () { act(x, 'approve'); } });
      modal('🧾 Expense', body, acts);
    }
    function act(x, what) {
      Api.post('/sales/expenses/' + x.id + '/' + what, {})
        .then(function () { toast(what === 'approve' ? '✅' : '💸', what === 'approve' ? 'Approved' : 'Marked reimbursed'); load(); })
        .catch(function (e) { toast('⚠️', 'Could not update', e.message || '', '#DC2626'); });
    }
    function rejectIt(x) {
      var reason = el('textarea', { rows: '3', maxlength: '500', placeholder: 'Why (the person is told)', style: F.replace('height:34px;', 'min-height:70px;padding:8px 10px;') });
      modal('❌ Don’t approve this expense?', el('div', {}, [el('div', { style: 'margin-bottom:8px;font-size:13.5px' }, [curMoney(x.total, x.currency) + ' · ' + (x.vendor || catOf(x.category).label) + (x.user ? ' · ' + x.user : '')]), reason]), [
        { label: 'Cancel' },
        { label: 'Don’t approve', primary: true, handler: function () { Api.post('/sales/expenses/' + x.id + '/reject', { reason: reason.value.trim() }).then(function () { toast('❌', 'Not approved'); load(); }).catch(function (e) { toast('⚠️', 'Could not update', e.message || '', '#DC2626'); }); } },
      ]);
    }
    async function del(x) {
      var ok = KT.confirm ? await KT.confirm({ title: 'Delete this expense?', description: curMoney(x.total, x.currency) + ' · ' + (x.vendor || catOf(x.category).label), okLabel: 'Delete', tone: 'danger' }) : false;
      if (!ok) return;
      try { await Api.delete('/sales/expenses/' + x.id); toast('🗑', 'Deleted'); load(); } catch (e) { toast('⚠️', 'Could not delete', e.message || '', '#DC2626'); }
    }
    function receipt(x) {
      fetch(apiBase() + '/sales/expenses/' + x.id + '/receipt', { headers: { Authorization: 'Bearer ' + tok() } })
        .then(function (r) { if (!r.ok) throw new Error('HTTP ' + r.status); return r.blob(); })
        .then(function (b) { var u = URL.createObjectURL(b); var a = document.createElement('a'); a.href = u; a.download = x.receipt_name || ('receipt-' + x.id); document.body.appendChild(a); a.click(); a.remove(); setTimeout(function () { URL.revokeObjectURL(u); }, 3000); })
        .catch(function (e) { toast('⚠️', 'Could not download', e.message || '', '#DC2626'); });
    }
    function openRecurring() {
      var list = el('div');
      function draw() {
        clear(list);
        var R = D.recurring || [];
        if (!R.length) list.appendChild(el('div', { style: 'color:#64748B;font-size:13px;padding:6px 0 12px' }, ['No recurring costs yet. Add rent, software subscriptions, phone plans… once, and they are added on their date every period.']));
        R.forEach(function (rc) {
          var k = catOf(rc.category);
          list.appendChild(el('div', { style: 'display:flex;gap:10px;align-items:center;padding:9px 0;border-bottom:1px solid #EEF2F6;flex-wrap:wrap' + (rc.active ? '' : ';opacity:.55') }, [
            el('div', { style: 'flex:1 1 200px;min-width:0' }, [el('div', { style: 'font-weight:700' }, [k.icon + ' ' + (rc.vendor || k.label)]), el('div', { style: 'font-size:12px;color:#64748B' }, [curMoney(rc.amount + rc.tax, rc.currency) + ' ' + rc.interval + ' · next ' + dayLabel(rc.next_date) + (rc.active ? '' : ' · paused')])]),
            btn(rc.active ? '⏸ Pause' : '▶ Resume', false, function () { Api.patch('/sales/expense-recurring/' + rc.id, { active: !rc.active }).then(function () { rc.active = !rc.active; draw(); load(); }); }),
            btn('🗑', false, async function () { var ok = KT.confirm ? await KT.confirm({ title: 'Remove this recurring cost?', description: 'Expenses already added stay.', okLabel: 'Remove', tone: 'danger' }) : false; if (!ok) return; Api.delete('/sales/expense-recurring/' + rc.id).then(function () { D.recurring = R.filter(function (z) { return z.id !== rc.id; }); draw(); load(); }); }),
          ]));
        });
      }
      draw();
      var f = {};
      f.cat = el('select', { style: F }, [el('option', { value: '', selected: 'selected', disabled: 'disabled' }, ['Category…'])].concat((D.categories || []).map(function (k) { return el('option', { value: k.key }, [k.icon + ' ' + k.label]); })));
      f.vendor = supplierSelect(null, '');
      f.amount = el('input', { type: 'number', step: '0.01', min: '0', placeholder: 'Amount', style: F });
      f.tax = el('input', { type: 'number', step: '0.01', min: '0', placeholder: 'Tax', style: F });
      f.cur = el('select', { style: F }, ['CAD', 'USD'].map(function (k) { return el('option', { value: k }, [k]); }));
      f.int = el('select', { style: F }, [['monthly', 'Every month'], ['quarterly', 'Every 3 months'], ['annual', 'Every year']].map(function (k) { return el('option', { value: k[0] }, [k[1]]); }));
      f.next = el('input', { type: 'date', value: today(), style: F });
      var form = el('div', { style: 'display:grid;grid-template-columns:repeat(2,1fr);gap:10px;margin-top:14px;padding-top:12px;border-top:1px solid #E2E8F0' }, [
        el('div', { style: 'grid-column:1 / -1;font-weight:800;font-size:13.5px' }, ['➕ Add a recurring cost']),
        field('Category', f.cat), field('Vendor', f.vendor), field('Amount', f.amount), field('Tax', f.tax), field('Currency', f.cur), field('How often', f.int), field('First / next date', f.next, true),
      ]);
      modal('🔁 Recurring costs', el('div', {}, [list, form]), [
        { label: 'Close' },
        { label: 'Add recurring cost', primary: true, handler: function () {
          if (!f.cat.value || f.amount.value === '' || !f.next.value) { toast('⚠️', 'Category, amount and date are needed', '', '#DC2626'); return false; }
          var rv = f.vendor.vendorValue();
          Api.post('/sales/expense-recurring', { category: f.cat.value, vendor: rv.vendor, supplier_id: rv.supplier_id || null, amount: f.amount.value, tax: f.tax.value || 0, currency: f.cur.value, interval: f.int.value, next_date: f.next.value })
            .then(function () { toast('🔁', 'Recurring cost added', 'It is added on its date each period.'); load(); })
            .catch(function (e) { toast('⚠️', 'Could not add', e.message || '', '#DC2626'); });
        } },
      ], true);
    }
    paintSeg(); showTab();
  }

  function register() {
    if (!(KT.Shell && KT.Shell.registerScreen)) { setTimeout(register, 300); return; }
    // platform_admin resolves to the agency_admin shell, so register there too; each screen guards itself.
    ['sales_rep', 'platform_admin', 'agency_admin'].forEach(function (role) { KT.Shell.registerScreen(role + ':sales-quotes', renderQuotes); KT.Shell.registerScreen(role + ':sales-expenses', renderExpenses); KT.Shell.registerScreen(role + ':sales-customers', renderCustomers); });
    ['platform_admin', 'agency_admin'].forEach(function (role) { KT.Shell.registerScreen(role + ':platform-sales', renderPlatformSales); });
  }
  register();
})(window);
