/* ============================================================
   KIDDIETRAC — Website control center (platform_admin / super-admin ONLY)
   Route: #marketing-site
   Tabs: Overview · Subscribers · Content · SEO · Announcement · Analytics · Translations
   Backend:
     GET/PUT /api/v1/platform/marketing-site            (settings)
     GET     /api/v1/platform/marketing-site/leads      (subscribers)
     POST    /api/v1/platform/marketing-site/leads      (add subscriber)
     POST    /api/v1/platform/marketing-site/leads/delete (delete subscriber)
     GET     /api/v1/platform/marketing-site/analytics  (first-party views)
     GET/PUT/DELETE /api/v1/platform/marketing-site/translations[/{id}] (auto-translations)
   ============================================================ */
(function (window) {
  'use strict';
  if (!window.KT) return;
  var KT = window.KT, Api = KT.Api, Shell = KT.Shell;
  var TEAL = '#1F6080';

  function esc(s) {
    return s == null ? '' : String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;')
      .replace(/>/g, '&gt;').replace(/"/g, '&quot;');
  }
  function isPlatformAdmin() {
    try { var u = JSON.parse(sessionStorage.getItem('kt_user') || localStorage.getItem('kt_user') || '{}');
      return Array.isArray(u.roles) && u.roles.indexOf('platform_admin') !== -1; } catch (e) { return false; }
  }
  function field(label, id, val, ph, hint) {
    return '<div style="margin-bottom:16px">'
      + '<label style="display:block;font-size:13px;font-weight:700;color:#374151;margin-bottom:5px">' + esc(label) + '</label>'
      + '<input id="' + id + '" value="' + esc(val) + '" placeholder="' + esc(ph || '') + '" '
      + 'style="width:100%;padding:10px 12px;border:1px solid #D1D5DB;border-radius:8px;font-size:14px;font-family:inherit;box-sizing:border-box">'
      + (hint ? '<div style="font-size:12px;color:#64748B;margin-top:4px">' + esc(hint) + '</div>' : '')
      + '</div>';
  }
  function card(title, inner) {
    return '<div style="background:#fff;border:1px solid #E5E7EB;border-radius:14px;padding:22px;margin-bottom:18px">'
      + (title ? '<h2 style="font-size:16px;font-weight:800;color:#0a1e2c;margin-bottom:16px">' + esc(title) + '</h2>' : '') + inner + '</div>';
  }
  function toggleRow(label, id, on) {
    return '<label style="display:flex;align-items:center;gap:10px;margin-bottom:16px;cursor:pointer">'
      + '<input type="checkbox" id="' + id + '"' + (on ? ' checked' : '') + ' style="width:18px;height:18px;accent-color:' + TEAL + '">'
      + '<span style="font-size:14px;font-weight:700;color:#374151">' + esc(label) + '</span></label>';
  }
  function stat(label, value, sub, color) {
    return '<div style="flex:1;min-width:150px;background:#fff;border:1px solid #E5E7EB;border-radius:14px;padding:18px">'
      + '<div style="font-size:28px;font-weight:900;color:' + (color || '#0a1e2c') + ';line-height:1">' + esc(value) + '</div>'
      + '<div style="font-size:13px;font-weight:700;color:#374151;margin-top:6px">' + esc(label) + '</div>'
      + (sub ? '<div style="font-size:12px;color:#64748B;margin-top:2px">' + esc(sub) + '</div>' : '') + '</div>';
  }
  function saveBar(idmsg, idbtn) {
    return '<div style="display:flex;gap:12px;align-items:center;margin-top:4px">'
      + '<button id="' + idbtn + '" style="background:' + TEAL + ';color:#fff;border:none;border-radius:8px;padding:11px 24px;font-weight:700;font-size:14px;cursor:pointer">Save changes</button>'
      + '<span id="' + idmsg + '" style="font-size:13px;font-weight:700"></span></div>';
  }

  /* ---- panels ---- */
  function tabOverview(c, a) {
    var views14 = a ? a.views_14d : 0, leads = a ? a.total_leads : 0, conv = a ? a.conversion : 0, total = a ? a.total_views : 0;
    return '<div style="display:flex;gap:14px;flex-wrap:wrap;margin-bottom:20px">'
        + stat('Subscribers / leads', leads, 'captured all-time', '#16a34a')
        + stat('Pageviews (14 days)', views14, 'first-party', TEAL)
        + stat('Pageviews (all-time)', total, '', TEAL)
        + stat('Conversion', conv + '%', 'leads ÷ views (14d)', '#16a34a')
      + '</div>'
      + card('Status', '<div style="font-size:14px;color:#374151;line-height:2">'
          + '<div>Announcement bar: <b style="color:' + (c.announce_enabled ? '#16a34a' : '#9CA3AF') + '">' + (c.announce_enabled ? 'Live' : 'Hidden') + '</b></div>'
          + '<div>Google Analytics 4: <b style="color:' + (c.ga_id ? '#16a34a' : '#9CA3AF') + '">' + (c.ga_id ? esc(c.ga_id) : 'Not set') + '</b></div>'
          + '<div>Custom homepage SEO: <b style="color:' + (c.seo_title || c.seo_description ? '#16a34a' : '#9CA3AF') + '">' + (c.seo_title || c.seo_description ? 'Set' : 'Site defaults') + '</b></div>'
          + '</div>'
          + '<div style="margin-top:14px;display:flex;gap:14px;flex-wrap:wrap">'
          + '<a href="https://www.kiddietrac.com" target="_blank" rel="noopener" style="font-size:13px;color:' + TEAL + ';font-weight:700;text-decoration:none">Open marketing site ↗</a>'
          + '<a href="https://www.kiddietrac.com/sitemap.xml" target="_blank" rel="noopener" style="font-size:13px;color:' + TEAL + ';font-weight:700;text-decoration:none">View sitemap ↗</a></div>')
      + overviewCharts(a);
  }

  /* OVERVIEW CHARTS (2026-10-23, Anthony: "website section add some graphs and or charts
     under overview"). Plain SVG, no library: visits and leads over 30 days, where visitors
     are, the most-read pages, where leads come from, and 8 weeks of subscribers, website
     chats and meeting requests. From /platform/marketing-site/analytics. */
  var PALETTE = ['#2563EB', '#16A34A', '#F59E0B', '#DB2777', '#7C3AED', '#0891B2', '#94A3B8'];
  function chartCard(title, sub, inner) {
    return '<div style="background:#fff;border:1px solid #E5E7EB;border-radius:14px;padding:16px 18px;min-width:0">'
      + '<div style="font-size:14.5px;font-weight:800;color:#0a1e2c">' + esc(title) + '</div>'
      + (sub ? '<div style="font-size:12px;color:#64748B;margin:2px 0 8px">' + esc(sub) + '</div>' : '<div style="height:8px"></div>') + inner + '</div>';
  }
  function legend(items) {
    return '<div style="display:flex;gap:12px;flex-wrap:wrap;font-size:12px;color:#475569;margin-top:6px">' + items.map(function (x) {
      return '<span style="display:inline-flex;align-items:center;gap:5px"><span style="width:10px;height:10px;border-radius:3px;background:' + x[1] + '"></span>' + esc(x[0]) + '</span>';
    }).join('') + '</div>';
  }
  function niceMax(v) { return Math.max(2, Math.ceil(v / 2) * 2); }
  function shortDate(iso) { var d = new Date(iso + 'T12:00:00'); return d.toLocaleDateString(undefined, { month: 'short', day: 'numeric' }); }

  // Visits as a filled line, leads as bars, on two scales.
  function visitsChart(series) {
    if (!series || !series.length) return '<div style="color:#64748B;font-size:13px">No data yet.</div>';
    var W = 640, H = 200, L = 30, R = 30, T = 14, B = 28, n = series.length;
    var vmax = niceMax(Math.max.apply(null, series.map(function (x) { return x.views; }))), lmax = niceMax(Math.max.apply(null, series.map(function (x) { return x.leads || 0; })));
    var x = function (i) { return L + (W - L - R) * (n === 1 ? 0.5 : i / (n - 1)); };
    var yv = function (v) { return T + (H - T - B) * (1 - v / vmax); }, yl = function (v) { return T + (H - T - B) * (1 - v / lmax); };
    var g = '';
    for (var k = 0; k <= 2; k++) { var gy = T + (H - T - B) * k / 2; g += '<line x1="' + L + '" x2="' + (W - R) + '" y1="' + gy + '" y2="' + gy + '" stroke="#EEF2F6"/><text x="4" y="' + (gy + 4) + '" font-size="10" fill="#2563EB">' + Math.round(vmax * (2 - k) / 2) + '</text><text x="' + (W - 4) + '" y="' + (gy + 4) + '" font-size="10" fill="#16A34A" text-anchor="end">' + Math.round(lmax * (2 - k) / 2) + '</text>'; }
    var bw = Math.max(3, (W - L - R) / n * 0.5);
    series.forEach(function (p, i) { if (p.leads) g += '<rect x="' + (x(i) - bw / 2) + '" y="' + yl(p.leads) + '" width="' + bw + '" height="' + (H - B - yl(p.leads)) + '" rx="2" fill="#16A34A" opacity=".85"><title>' + esc(p.date + ': ' + p.leads + ' lead(s)') + '</title></rect>'; });
    var pts = series.map(function (p, i) { return x(i) + ',' + yv(p.views); }).join(' ');
    g += '<polygon points="' + x(0) + ',' + (H - B) + ' ' + pts + ' ' + x(n - 1) + ',' + (H - B) + '" fill="#2563EB" opacity=".12"/>';
    g += '<polyline points="' + pts + '" fill="none" stroke="#2563EB" stroke-width="2.2" stroke-linejoin="round"/>';
    series.forEach(function (p, i) { g += '<circle cx="' + x(i) + '" cy="' + yv(p.views) + '" r="2.6" fill="#2563EB"><title>' + esc(p.date + ': ' + p.views + ' views') + '</title></circle>'; if (i % 5 === 0 || i === n - 1) g += '<text x="' + x(i) + '" y="' + (H - 8) + '" font-size="10" fill="#64748B" text-anchor="middle">' + esc(shortDate(p.date)) + '</text>'; });
    return '<svg viewBox="0 0 ' + W + ' ' + H + '" style="width:100%;height:auto;display:block" role="img" aria-label="Visits and leads, last 30 days">' + g + '</svg>'
      + legend([['Page views', '#2563EB'], ['Leads', '#16A34A']]);
  }

  // A donut with its legend: [[label, value], ...]
  function donut(items, centreLabel) {
    items = (items || []).filter(function (x) { return x[1] > 0; });
    var total = items.reduce(function (t, x) { return t + x[1]; }, 0);
    if (!total) return '<div style="color:#64748B;font-size:13px">No data yet.</div>';
    if (items.length > 6) { var rest = items.slice(5).reduce(function (t, x) { return t + x[1]; }, 0); items = items.slice(0, 5).concat([['Other', rest]]); }
    var R = 52, r = 32, cx = 64, cy = 64, a0 = -Math.PI / 2, g = '';
    items.forEach(function (it, i) {
      var frac = it[1] / total, a1 = a0 + frac * Math.PI * 2, col = PALETTE[i % PALETTE.length];
      if (frac >= 0.9999) { g += '<circle cx="' + cx + '" cy="' + cy + '" r="' + ((R + r) / 2) + '" fill="none" stroke="' + col + '" stroke-width="' + (R - r) + '"><title>' + esc(it[0] + ': ' + it[1]) + '</title></circle>'; }
      else {
        var big = frac > 0.5 ? 1 : 0, p = function (ang, rad) { return (cx + rad * Math.cos(ang)).toFixed(2) + ' ' + (cy + rad * Math.sin(ang)).toFixed(2); };
        g += '<path d="M ' + p(a0, R) + ' A ' + R + ' ' + R + ' 0 ' + big + ' 1 ' + p(a1, R) + ' L ' + p(a1, r) + ' A ' + r + ' ' + r + ' 0 ' + big + ' 0 ' + p(a0, r) + ' Z" fill="' + col + '"><title>' + esc(it[0] + ': ' + it[1]) + '</title></path>';
      }
      a0 = a1;
    });
    g += '<text x="' + cx + '" y="' + (cy + 2) + '" text-anchor="middle" font-size="18" font-weight="800" fill="#0a1e2c">' + total + '</text><text x="' + cx + '" y="' + (cy + 16) + '" text-anchor="middle" font-size="9.5" fill="#64748B">' + esc(centreLabel || '') + '</text>';
    return '<div style="display:flex;gap:14px;align-items:center;flex-wrap:wrap"><svg viewBox="0 0 128 128" style="width:128px;height:128px;flex:0 0 auto">' + g + '</svg>'
      + '<div style="flex:1;min-width:140px;font-size:12.5px;color:#334155;line-height:1.9">' + items.map(function (it, i) {
        return '<div style="display:flex;gap:8px;align-items:center"><span style="width:10px;height:10px;border-radius:3px;background:' + PALETTE[i % PALETTE.length] + ';flex:0 0 auto"></span><span style="flex:1;min-width:0;overflow-wrap:anywhere">' + esc(it[0]) + '</span><b>' + it[1] + '</b><span style="color:#94A3B8;width:38px;text-align:right">' + Math.round(it[1] * 100 / total) + '%</span></div>';
      }).join('') + '</div></div>';
  }

  // Horizontal bars: [[label, value], ...]
  function hbars(items, color) {
    if (!items || !items.length) return '<div style="color:#64748B;font-size:13px">No data yet.</div>';
    var max = Math.max.apply(null, items.map(function (x) { return x[1]; })) || 1;
    return items.map(function (x) {
      return '<div style="display:flex;align-items:center;gap:10px;margin:5px 0;font-size:12.5px"><span style="flex:0 0 38%;min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;color:#334155" title="' + esc(x[0]) + '">' + esc(x[0]) + '</span>'
        + '<span style="flex:1;background:#F1F5F9;border-radius:6px;height:12px;overflow:hidden"><span style="display:block;height:100%;width:' + Math.max(2, Math.round(x[1] * 100 / max)) + '%;background:' + (color || '#2563EB') + ';border-radius:6px"></span></span>'
        + '<b style="width:34px;text-align:right">' + x[1] + '</b></div>';
    }).join('');
  }

  // Grouped weekly bars: [{week, subscribers, chats, meetings}]
  function weeksChart(weeks) {
    if (!weeks || !weeks.length) return '<div style="color:#64748B;font-size:13px">No data yet.</div>';
    var keys = [['subscribers', 'New subscribers', '#2563EB'], ['chats', 'Website chats', '#F59E0B'], ['meetings', 'Meeting requests', '#16A34A']];
    var W = 640, H = 190, L = 26, T = 14, B = 26, n = weeks.length, max = 1;
    weeks.forEach(function (w) { keys.forEach(function (k) { max = Math.max(max, w[k[0]] || 0); }); });
    max = niceMax(max);
    var gw = (W - L - 10) / n, bw = Math.max(4, (gw - 10) / keys.length), g = '';
    for (var k = 0; k <= 2; k++) { var gy = T + (H - T - B) * k / 2; g += '<line x1="' + L + '" x2="' + (W - 10) + '" y1="' + gy + '" y2="' + gy + '" stroke="#EEF2F6"/><text x="4" y="' + (gy + 4) + '" font-size="10" fill="#94A3B8">' + Math.round(max * (2 - k) / 2) + '</text>'; }
    weeks.forEach(function (w, i) {
      keys.forEach(function (kk, j) {
        var v = w[kk[0]] || 0, h = (H - T - B) * v / max, xx = L + i * gw + 5 + j * bw;
        g += '<rect x="' + xx + '" y="' + (H - B - h) + '" width="' + (bw - 2) + '" height="' + h + '" rx="2" fill="' + kk[2] + '"><title>' + esc(kk[1] + ': ' + v) + '</title></rect>';
        if (v) g += '<text x="' + (xx + (bw - 2) / 2) + '" y="' + (H - B - h - 3) + '" font-size="10" fill="#475569" text-anchor="middle">' + v + '</text>';
      });
      g += '<text x="' + (L + i * gw + gw / 2) + '" y="' + (H - 8) + '" font-size="10" fill="#64748B" text-anchor="middle">' + esc(shortDate(w.week)) + '</text>';
    });
    return '<svg viewBox="0 0 ' + W + ' ' + H + '" style="width:100%;height:auto;display:block" role="img" aria-label="Last 8 weeks">' + g + '</svg>' + legend(keys.map(function (k) { return [k[1], k[2]]; }));
  }

  var COUNTRY = { CA: 'Canada', US: 'United States', GB: 'United Kingdom', IE: 'Ireland', IN: 'India', AU: 'Australia', PH: 'Philippines', Other: 'Other', Unknown: 'Unknown' };
  function overviewCharts(a) {
    if (!a) return '';
    var s30 = a.series_30d || [];
    var v30 = s30.reduce(function (t, x) { return t + x.views; }, 0), l30 = s30.reduce(function (t, x) { return t + (x.leads || 0); }, 0);
    var subs = a.subscribers || {}, meet = a.meetings || {};
    var meetItems = [['Confirmed', meet.booked || 0], ['Waiting for a decision', meet.requested || 0], ['Declined', meet.declined || 0], ['Cancelled', meet.cancelled || 0]];
    return '<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(min(100%,460px),1fr));gap:14px;margin-bottom:18px">'
        + chartCard('Visits and leads — last 30 days', v30 + ' page views · ' + l30 + ' lead' + (l30 === 1 ? '' : 's'), visitsChart(s30))
        + chartCard('Activity — last 8 weeks', 'New subscribers, website chats and meeting requests each week', weeksChart(a.weeks))
      + '</div>'
      + '<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(min(100%,300px),1fr));gap:14px;margin-bottom:18px">'
        + chartCard('Where visitors are', 'By visitor timezone', donut((a.top_countries || []).map(function (x) { return [COUNTRY[x.country] || x.country, x.views]; }), 'views'))
        + chartCard('Where leads come from', 'All time', donut(Object.keys(a.lead_sources || {}).map(function (k) { return [k.replace(/-/g, ' '), a.lead_sources[k]]; }).sort(function (p, q) { return q[1] - p[1]; }), 'leads'))
        + chartCard('Subscribers', (subs.active || 0) + ' subscribed', donut([['Subscribed', subs.active || 0], ['Unsubscribed', subs.unsubscribed || 0]], 'people'))
        + chartCard('Meeting requests', 'From the website booking page', donut(meetItems, 'meetings'))
      + '</div>'
      + chartCard('Most-read pages', 'All time', hbars((a.top_paths || []).slice(0, 8).map(function (p) { return [p.path, p.views]; })));
  }

  function leadRowHtml(l) {
    var d = (l.at || '').replace('T', ' ').slice(0, 16);
    return '<tr class="leadRow" data-q="' + esc(((l.email || '') + ' ' + (l.agency || '') + ' ' + (l.source || '') + ' ' + (l.name || '')).toLowerCase()) + '" data-email="' + esc(l.email) + '" data-at="' + esc(l.at) + '" data-name="' + esc(l.name || '') + '" data-agency="' + esc(l.agency || '') + '">'
      + '<td style="padding:9px 10px;border-top:1px solid #F1F5F9;font-size:13px;color:#6b7280;white-space:nowrap">' + esc(d) + '</td>'
      + '<td style="padding:9px 10px;border-top:1px solid #F1F5F9;font-size:13px;font-weight:700;color:#0a1e2c">' + esc(l.email) + '</td>'
      + '<td class="cellName" style="padding:9px 10px;border-top:1px solid #F1F5F9;font-size:13px;color:#374151">' + esc(l.name || '—') + '</td>'
      + '<td class="cellAgency" style="padding:9px 10px;border-top:1px solid #F1F5F9;font-size:13px;color:#374151">' + esc(l.agency || '—') + '</td>'
      + '<td style="padding:9px 10px;border-top:1px solid #F1F5F9;font-size:12px"><span style="background:#EFF6FF;color:' + TEAL + ';padding:2px 8px;border-radius:100px;font-weight:700">' + esc(l.source || '—') + '</span></td>'
      + '<td class="cellAct" style="padding:9px 10px;border-top:1px solid #F1F5F9;text-align:right;white-space:nowrap">'
        + '<button class="leadEdit" title="Edit subscriber" style="background:none;border:none;color:' + TEAL + ';cursor:pointer;font-size:14px;margin-right:6px">✏️</button>'
        + '<button class="leadDel" title="Delete subscriber" style="background:none;border:none;color:#DC2626;cursor:pointer;font-size:15px">🗑</button></td></tr>';
  }

  function tabSubscribers(leads) {
    var addForm = '<div style="background:#F9FAFB;border:1px solid #E5E7EB;border-radius:12px;padding:16px;margin-bottom:16px">'
      + '<div style="font-size:13px;font-weight:800;color:#0a1e2c;margin-bottom:10px">➕ Add a subscriber manually</div>'
      + '<div style="display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end">'
      + '<div style="flex:2;min-width:180px"><input id="addEmail" type="email" placeholder="email@agency.com" style="width:100%;padding:9px 11px;border:1px solid #D1D5DB;border-radius:8px;font-size:13px;box-sizing:border-box"></div>'
      + '<div style="flex:1;min-width:120px"><input id="addName" placeholder="Name (optional)" style="width:100%;padding:9px 11px;border:1px solid #D1D5DB;border-radius:8px;font-size:13px;box-sizing:border-box"></div>'
      + '<div style="flex:1;min-width:120px"><input id="addAgency" placeholder="Agency (optional)" style="width:100%;padding:9px 11px;border:1px solid #D1D5DB;border-radius:8px;font-size:13px;box-sizing:border-box"></div>'
      + '<button id="addLeadBtn" style="background:' + TEAL + ';color:#fff;border:none;border-radius:8px;padding:10px 18px;font-weight:700;font-size:13px;cursor:pointer">Add</button>'
      + '<span id="addLeadMsg" style="font-size:12px;font-weight:700"></span></div></div>';
    var head = '<div style="display:flex;gap:12px;align-items:center;flex-wrap:wrap;margin-bottom:14px">'
      + '<input id="leadSearch" placeholder="Search email, agency, source…" style="flex:1;min-width:200px;padding:10px 12px;border:1px solid #D1D5DB;border-radius:8px;font-size:14px;box-sizing:border-box">'
      + '<span style="font-size:13px;color:#6b7280;font-weight:700"><span id="leadCount">' + leads.length + '</span> subscribers</span>'
      + '<button id="leadCsv" style="background:#fff;border:1px solid ' + TEAL + ';color:' + TEAL + ';border-radius:8px;padding:9px 16px;font-weight:700;font-size:13px;cursor:pointer">⬇ Export CSV</button></div>';
    var rows = leads.length ? leads.map(leadRowHtml).join('')
      : '<tr id="leadEmpty"><td colspan="6" style="padding:30px;text-align:center;color:#64748B;font-size:14px">No subscribers yet. They appear here as visitors download the checklist or sign up — or add one above.</td></tr>';
    return card('', addForm + head
      + '<div style="overflow-x:auto"><table data-kt-filtered="1" style="width:100%;border-collapse:collapse">'
      + '<thead><tr style="text-align:left">'
      + ['Date', 'Email', 'Name', 'Agency / centre', 'Source', ''].map(function (h) { return '<th style="padding:6px 10px;font-size:11px;text-transform:uppercase;letter-spacing:.5px;color:#64748B">' + h + '</th>'; }).join('')
      + '</tr></thead><tbody id="leadBody">' + rows + '</tbody></table></div>');
  }

  function twoCol(a, b) { return '<div style="display:flex;gap:14px;flex-wrap:wrap"><div style="flex:1;min-width:180px">' + a + '</div><div style="flex:1;min-width:180px">' + b + '</div></div>'; }
  function tabContent(c) {
    return card('Contact details', ''
        + field('Contact email', 'contact_email', c.contact_email, 'sales@kiddietrac.com', 'Replaces the primary sales@ email shown across the site.')
        + field('Chat transcripts to', 'chat_transcript_to', c.chat_transcript_to, 'sales@kiddietrac.com', 'Where a finished website chat is emailed, with the visitor\'s details. Leave blank to use the contact email above.')
        + twoCol(field('Phone', 'contact_phone', c.contact_phone, '+1 416 555 1234', ''), field('Address', 'contact_address', c.contact_address, '246372 Hockley Road, Mono, ON', ''))
        + saveBar('contactMsg', 'contactSave'))
      + card('Social links', ''
        + twoCol(field('Facebook URL', 'social_facebook', c.social_facebook, 'https://facebook.com/kiddietrac', ''), field('Instagram URL', 'social_instagram', c.social_instagram, 'https://instagram.com/kiddietrac', ''))
        + twoCol(field('LinkedIn URL', 'social_linkedin', c.social_linkedin, 'https://linkedin.com/company/kiddietrac', ''), field('X (Twitter) URL', 'social_x', c.social_x, 'https://x.com/kiddietrac', ''))
        + saveBar('socialMsg', 'socialSave'))
      + card('Homepage hero statistics', ''
        + '<p style="font-size:13px;color:#6b7280;margin-bottom:14px">The three highlighted numbers in the homepage hero. Leave blank to use the built-in defaults.</p>'
        + twoCol(field('Stat 1 — number', 'hero_stat1_num', c.hero_stat1_num, '2,400+', ''), field('Stat 1 — label', 'hero_stat1_lbl', c.hero_stat1_lbl, 'Children Tracked', ''))
        + twoCol(field('Stat 2 — number', 'hero_stat2_num', c.hero_stat2_num, '150+', ''), field('Stat 2 — label', 'hero_stat2_lbl', c.hero_stat2_lbl, 'Agencies', ''))
        + twoCol(field('Stat 3 — number', 'hero_stat3_num', c.hero_stat3_num, '98%', ''), field('Stat 3 — label', 'hero_stat3_lbl', c.hero_stat3_lbl, 'Satisfaction', ''))
        + saveBar('heroMsg', 'heroSave'))
      + card('Spam protection (reCAPTCHA)', ''
        + toggleRow('Require Google reCAPTCHA on the lead / subscribe form', 'recaptcha_enabled', !!c.recaptcha_enabled)
        + field('reCAPTCHA site key', 'recaptcha_site_key', c.recaptcha_site_key, '6Lxxxxx… (public key)', 'From the Google reCAPTCHA admin (v2 “I’m not a robot”). Public — shown on the site.')
        + field('reCAPTCHA secret key', 'recaptcha_secret', c.recaptcha_secret, '6Lxxxxx… (secret key)', 'Kept private on the server; used to verify submissions. Both keys + the toggle are required to activate.')
        + saveBar('rcMsg', 'rcSave'));
  }

  function tabSeo(c) {
    return card('Homepage SEO', ''
        + '<p style="font-size:13px;color:#6b7280;margin-bottom:16px">Override the homepage title, description and social share image. Leave blank to use the built-in per-page SEO.</p>'
        + field('Homepage title tag', 'seo_title', c.seo_title, 'KiddieTrac — Smart Childcare Platform | #1 in North America', 'Best under ~60 characters.')
        + field('Homepage meta description', 'seo_description', c.seo_description, '', '~150–160 characters.')
        + field('Social share image (OG image) URL', 'og_image', c.og_image, 'https://www.kiddietrac.com/og-image.png', '1200×630px recommended.')
        + saveBar('seoMsg', 'seoSave'))
      + card('Indexing', '<div style="font-size:14px;color:#374151;line-height:2">'
        + '<div><a href="https://www.kiddietrac.com/sitemap.xml" target="_blank" rel="noopener" style="color:' + TEAL + ';font-weight:700;text-decoration:none">sitemap.xml ↗</a> — all pages, real crawlable URLs</div>'
        + '<div><a href="https://www.kiddietrac.com/robots.txt" target="_blank" rel="noopener" style="color:' + TEAL + ';font-weight:700;text-decoration:none">robots.txt ↗</a></div></div>');
  }

  function tabAnnounce(c) {
    return card('Announcement bar', ''
      + toggleRow('Show the announcement bar at the top of the site', 'ms_on', !!c.announce_enabled)
      + field('Badge text', 'ms_tag', c.announce_tag, 'NEW · 2026', '')
      + field('Message', 'ms_text', c.announce_text, 'What’s new…', '')
      + field('Button text', 'ms_cta', c.announce_cta_text, 'Start your free trial →', '')
      + field('Button links to page', 'ms_page', c.announce_cta_page || 'contact', 'contact', 'e.g. contact, pricing, solutions, whitelabel — or get-app (each phone gets its own store), app-store, google-play')
      + saveBar('annMsg', 'annSave'));
  }

  function bars(series) {
    var max = 1; (series || []).forEach(function (d) { if (d.views > max) max = d.views; });
    return '<div style="display:flex;align-items:flex-end;gap:5px;height:120px;padding:8px 0">'
      + (series || []).map(function (d) {
        var h = Math.round((d.views / max) * 100);
        return '<div style="flex:1;display:flex;flex-direction:column;align-items:center;gap:4px">'
          + '<div title="' + d.views + ' views" style="width:100%;background:' + TEAL + ';border-radius:4px 4px 0 0;height:' + Math.max(h, 2) + '%;min-height:2px"></div>'
          + '<div style="font-size:9px;color:#64748B">' + esc(d.date.slice(5)) + '</div></div>';
      }).join('') + '</div>';
  }
  function tabAnalytics(c, a) {
    var topRows = (a && a.top_paths && a.top_paths.length)
      ? a.top_paths.map(function (p) { return '<tr><td style="padding:7px 10px;border-top:1px solid #F1F5F9;font-size:13px;font-weight:700;color:#0a1e2c">' + esc(p.path) + '</td><td style="padding:7px 10px;border-top:1px solid #F1F5F9;font-size:13px;color:#374151;text-align:right">' + p.views + '</td></tr>'; }).join('')
      : '<tr><td colspan="2" style="padding:20px;text-align:center;color:#64748B">No data yet.</td></tr>';
    var srcRows = (a && a.lead_sources && Object.keys(a.lead_sources).length)
      ? Object.keys(a.lead_sources).map(function (k) { return '<tr><td style="padding:7px 10px;border-top:1px solid #F1F5F9;font-size:13px;color:#374151">' + esc(k) + '</td><td style="padding:7px 10px;border-top:1px solid #F1F5F9;font-size:13px;font-weight:700;text-align:right">' + a.lead_sources[k] + '</td></tr>'; }).join('')
      : '<tr><td colspan="2" style="padding:16px;text-align:center;color:#64748B">No leads yet.</td></tr>';
    var CC = { CA: '🇨🇦 Canada', US: '🇺🇸 United States', GB: '🇬🇧 United Kingdom', IE: '🇮🇪 Ireland', FR: '🇫🇷 France', DE: '🇩🇪 Germany', ES: '🇪🇸 Spain', IT: '🇮🇹 Italy', NL: '🇳🇱 Netherlands', SE: '🇸🇪 Sweden', AU: '🇦🇺 Australia', NZ: '🇳🇿 New Zealand', IN: '🇮🇳 India', AE: '🇦🇪 UAE', SG: '🇸🇬 Singapore', JP: '🇯🇵 Japan', PH: '🇵🇭 Philippines', MX: '🇲🇽 Mexico', BR: '🇧🇷 Brazil', Other: '🌍 Other', Unknown: '❔ Unknown' };
    var countryRows = (a && a.top_countries && a.top_countries.length)
      ? a.top_countries.map(function (x) { return '<tr><td style="padding:7px 10px;border-top:1px solid #F1F5F9;font-size:13px;color:#374151">' + esc(CC[x.country] || x.country) + '</td><td style="padding:7px 10px;border-top:1px solid #F1F5F9;font-size:13px;font-weight:700;text-align:right">' + x.views + '</td></tr>'; }).join('')
      : '<tr><td colspan="2" style="padding:16px;text-align:center;color:#64748B">No data yet.</td></tr>';
    return '<div style="display:flex;gap:14px;flex-wrap:wrap;margin-bottom:18px">'
        + stat('Pageviews (14 days)', a ? a.views_14d : 0, '', TEAL) + stat('Pageviews (all-time)', a ? a.total_views : 0, '', TEAL)
        + stat('Leads', a ? a.total_leads : 0, '', '#16a34a') + stat('Conversion', (a ? a.conversion : 0) + '%', '', '#16a34a') + '</div>'
      + card('Pageviews — last 14 days (first-party)', bars(a ? a.series_14d : []))
      + '<div style="display:flex;gap:18px;flex-wrap:wrap">'
        + '<div style="flex:1;min-width:280px">' + card('Top pages', '<table data-kt-filtered="1" style="width:100%;border-collapse:collapse"><thead><tr><th style="text-align:left;padding:4px 10px;font-size:11px;color:#64748B;text-transform:uppercase">Page</th><th style="text-align:right;padding:4px 10px;font-size:11px;color:#64748B;text-transform:uppercase">Views</th></tr></thead><tbody>' + topRows + '</tbody></table>') + '</div>'
        + '<div style="flex:1;min-width:240px">' + card('Leads by source', '<table data-kt-filtered="1" style="width:100%;border-collapse:collapse"><thead><tr><th style="text-align:left;padding:4px 10px;font-size:11px;color:#64748B;text-transform:uppercase">Source</th><th style="text-align:right;padding:4px 10px;font-size:11px;color:#64748B;text-transform:uppercase">Leads</th></tr></thead><tbody>' + srcRows + '</tbody></table>') + '</div></div>'
      + card('Top countries (by visitor timezone)', '<table data-kt-filtered="1" style="width:100%;border-collapse:collapse"><thead><tr><th style="text-align:left;padding:4px 10px;font-size:11px;color:#64748B;text-transform:uppercase">Country</th><th style="text-align:right;padding:4px 10px;font-size:11px;color:#64748B;text-transform:uppercase">Views</th></tr></thead><tbody>' + countryRows + '</tbody></table>')
      + card('Google Analytics 4', field('GA4 Measurement ID', 'ms_ga', c.ga_id, 'G-XXXXXXXXXX', 'Loads only after visitors accept cookies. The charts above are first-party and always on.')
        + saveBar('gaMsg', 'gaSave')
        + '<div style="margin-top:12px"><a href="https://analytics.google.com/" target="_blank" rel="noopener" style="font-size:13px;color:' + TEAL + ';font-weight:700;text-decoration:none">Open Google Analytics ↗</a></div>');
  }

  /* WEBSITE CHAT (2026-10-16). Anthony: "website section and under chat add kebab to
     download transcript and delete and archive". Each conversation has a ⋮ with Download
     transcript, Archive / Unarchive and Delete; archived ones move to their own view.
     This list is cards, not a table, so kt-row-actions cannot build the ⋮: the button is
     ours (kt-ka-kebab) and the menu is KT.openActionMenu, per CONVENTIONS.md. Staff
     replies from the live inbox are merged in by the server, so a transcript is the whole
     conversation. Times are shown in Toronto time, KiddieTrac's own. */
  var CHAT_TZ = 'America/Toronto';
  function chatWhen(iso) {
    var d = iso ? new Date(iso) : null;
    if (!d || isNaN(d.getTime())) return String(iso || '').replace('T', ' ').slice(0, 16);
    try {
      return d.toLocaleString('en-CA', { timeZone: CHAT_TZ, year: 'numeric', month: 'short', day: 'numeric', hour: 'numeric', minute: '2-digit' });
    } catch (e) { return String(iso).replace('T', ' ').slice(0, 16); }
  }
  function chatWho(s, m) {
    if (m.sender === 'visitor') return s.name || 'Visitor';
    if (m.sender === 'agent') return (m.author || 'KiddieTrac team') + ' (KiddieTrac)';
    if (m.sender === 'system') return 'KiddieTrac';
    return 'Maya (KiddieTrac assistant)';
  }
  function tabChats(chats, showArchived) {
    chats = chats || [];
    if (!chats.length) return card('', '<div style="padding:24px;text-align:center;color:#64748B;font-size:14px">No chats yet. Conversations from the website chat widget appear here with the visitor\'s name &amp; email.</div>');
    var nArch = chats.filter(function (c) { return c.archived; }).length;
    var list = chats.filter(function (c) { return !!c.archived === !!showArchived; });
    function seg(on, label, n, v) {
      return '<button type="button" class="wsChatView" data-kt-keep-size data-arch="' + v + '"' + (on ? ' aria-current="true"' : '') + ' style="height:32px;padding:0 14px;border-radius:999px;font-family:inherit;font-weight:700;font-size:13px;line-height:1;cursor:pointer;border:1px solid ' + (on ? TEAL : '#CBD5E1') + ';background:' + (on ? TEAL : '#fff') + ';color:' + (on ? '#fff' : '#334155') + '">' + label + ' · ' + n + '</button>';
    }
    var head = '<div style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:14px">'
      + seg(!showArchived, 'Active', chats.length - nArch, '0') + seg(!!showArchived, 'Archived', nArch, '1') + '</div>';
    if (!list.length) {
      return card('', head + '<div style="padding:18px;text-align:center;color:#64748B;font-size:14px">'
        + (showArchived ? 'No archived conversations.' : 'No active conversations. Archived ones are under Archived.') + '</div>');
    }
    return card('', head + list.map(function (s) {
      var sid = esc(s.session);
      var who = (s.name || 'Anonymous') + (s.email ? ' · ' + s.email : '');
      var msgs = (s.messages || []).map(function (m) {
        var mine = m.sender === 'visitor';
        var by = m.sender === 'agent' ? '<div style="font-size:11px;color:#64748B;margin:0 0 2px 2px">' + esc(m.author || 'KiddieTrac team') + '</div>' : '';
        return '<div style="display:flex;justify-content:' + (mine ? 'flex-end' : 'flex-start') + ';margin:4px 0"><div style="max-width:78%">' + by + '<div style="padding:7px 11px;border-radius:10px;font-size:13px;line-height:1.45;white-space:pre-wrap;background:' + (mine ? TEAL : (m.sender === 'system' ? '#FFF7ED' : '#F1F5F9')) + ';color:' + (mine ? '#fff' : '#0a1e2c') + '">' + esc(m.message) + '</div></div></div>';
      }).join('');
      return '<div class="wsChat" data-s="' + sid + '" style="border:1px solid #E5E7EB;border-radius:12px;margin-bottom:10px;overflow:hidden">'
        + '<div style="display:flex;align-items:center;gap:6px;background:#F9FAFB;padding-right:8px">'
        + '<button type="button" class="chatToggle" data-s="' + sid + '" style="flex:1;min-width:0;text-align:left;background:none;border:none;padding:12px 16px;cursor:pointer;display:flex;justify-content:space-between;align-items:center;gap:10px;flex-wrap:wrap">'
        + '<span style="font-size:13.5px;font-weight:700;color:#0a1e2c;min-width:0;overflow-wrap:anywhere">💬 ' + esc(who) + '</span>'
        + '<span style="font-size:12px;color:#64748B;white-space:nowrap">' + s.count + ' msgs · ' + esc(chatWhen(s.last)) + '</span></button>'
        + '<button type="button" class="kt-ka-kebab wsChatMenu" data-s="' + sid + '" aria-label="Actions for this conversation" title="More actions" aria-haspopup="menu">⋮</button>'
        + '</div>'
        + '<div class="chatBody" data-s="' + sid + '" style="display:none;padding:12px 16px;background:#fff">' + msgs + '</div></div>';
    }).join(''));
  }

  var TABS = [
    { id: 'overview', label: 'Overview' }, { id: 'subscribers', label: 'Subscribers' },
    { id: 'chat', label: 'Chat' }, { id: 'content', label: 'Content' }, { id: 'seo', label: 'SEO' },
    { id: 'announce', label: 'Announcement' }, { id: 'analytics', label: 'Analytics' },
    { id: 'translations', label: 'Translations' }
  ];

  function view(c, a, leads, chats) {
    var tabBtns = TABS.map(function (t, i) {
      return '<button class="wsTab" data-tab="' + t.id + '" style="background:none;border:none;border-bottom:3px solid ' + (i === 0 ? TEAL : 'transparent') + ';color:' + (i === 0 ? TEAL : '#6b7280') + ';font-weight:700;font-size:14px;padding:10px 14px;cursor:pointer;white-space:nowrap">' + t.label + '</button>';
    }).join('');
    var panels = '<div class="wsPanel" data-panel="overview">' + tabOverview(c, a) + '</div>'
      // Subscribers: the numbers first, then Subscribed | Unsubscribed as sub-tabs (2026-10-23).
      + '<div class="wsPanel" data-panel="subscribers" style="display:none"><div id="ws-substats"></div>'
      +   '<div id="ws-subtabs" role="tablist" style="display:inline-flex;gap:4px;margin:0 0 12px"><button type="button" role="tab" aria-selected="true" data-sub-view="active">Subscribed</button><button type="button" role="tab" aria-selected="false" data-sub-view="unsubscribed">Unsubscribed</button></div>'
      +   '<div id="ws-subs">Loading…</div><div id="ws-unsub" style="display:none">Loading…</div></div>'
      + '<div class="wsPanel" data-panel="chat" style="display:none">' + tabChats(chats, false) + '</div>'
      + '<div class="wsPanel" data-panel="content" style="display:none">' + tabContent(c) + '</div>'
      + '<div class="wsPanel" data-panel="seo" style="display:none">' + tabSeo(c) + '</div>'
      + '<div class="wsPanel" data-panel="announce" style="display:none">' + tabAnnounce(c) + '</div>'
      + '<div class="wsPanel" data-panel="analytics" style="display:none">' + tabAnalytics(c, a) + '</div>'
      + '<div class="wsPanel" data-panel="translations" style="display:none"><div id="ws-tr">Loading…</div></div>';
    return '<div style="max-width:900px;margin:0 auto;padding:24px">'
      // The banner above says what this is (2026-10-23); only the timing note stays.
      + '<p style="color:#6b7280;margin:-4px 0 14px;font-size:12.5px">Changes go live on the site within about a minute.</p>'
      + '<div style="display:flex;gap:4px;border-bottom:1px solid #E5E7EB;margin-bottom:20px;overflow-x:auto">' + tabBtns + '</div>' + panels + '</div>';
  }

  /* The managed subscriber list. Rendered from site_subscribers rather than the signup
     file: the file is append-only, so it cannot say who has since unsubscribed and has no
     id to act on. */
  function subsApi(path, opts) {
    var base = (window.KT && KT.API_BASE) || 'https://api.kiddietrac.com/api/v1';
    var tok = sessionStorage.getItem('kt_token') || localStorage.getItem('kt_token');
    return fetch(base + path, Object.assign({
      headers: {
        Authorization: 'Bearer ' + tok,
        Accept: 'application/json',
        'Content-Type': 'application/json',
        'X-Active-Agency-Id': sessionStorage.getItem('kt_active_agency_id') || '',
      },
    }, opts || {})).then(function (r) {
      // The server's own words ("The email field must be a valid email address."), never "HTTP 422".
      if (!r.ok) { return r.json().catch(function () { return {}; }).then(function (j) { throw new Error((j && j.message) || 'That did not work. Please try again.'); }); }
      return r.json();
    });
  }

  function fmtStamp(ts) {
    if (!ts) { return '—'; }
    try {
      var tz = (window.KT && KT.agencyTz && KT.agencyTz()) || undefined;
      var s = String(ts).replace(' ', 'T');
      if (!/[Zz]|[+-]\d\d:?\d\d$/.test(s)) { s += 'Z'; }
      // Date AND time: "when did they unsubscribe" is the whole point of this screen.
      return new Date(s).toLocaleString('en-CA',
        { year: 'numeric', month: 'short', day: 'numeric', hour: 'numeric', minute: '2-digit', timeZone: tz });
    } catch (e) { return String(ts).slice(0, 16); }
  }

  function subsTable(rows, mode) {
    if (!rows.length) {
      return '<div class="kt-card" style="color:#6b7280;padding:22px;text-align:center;">'
        + (mode === 'unsubscribed'
            ? 'Nobody has unsubscribed. Anyone who does will appear here with the date and time.'
            : 'No subscribers yet. Sign-ups from www.kiddietrac.com appear here.')
        + '</div>';
    }
    var head = mode === 'unsubscribed'
      ? '<tr><th>Email</th><th>Name</th><th>Agency</th><th>Unsubscribed</th><th>By</th><th></th></tr>'
      : '<tr><th>Email</th><th>Name</th><th>Agency</th><th>Source</th><th>Subscribed</th><th></th></tr>';

    // Plain cells. The portal stylesheet already sizes and truncates table content; the
    // title keeps the full value reachable where a column is narrow.
    var cell = function (v, strong) {
      var t = esc(v == null || v === '' ? '—' : String(v));
      return '<td title="' + t + '">' + (strong ? '<strong>' + t + '</strong>' : t) + '</td>';
    };

    var body = rows.map(function (r) {
      var cells = mode === 'unsubscribed'
        ? cell(r.email, true) + cell(r.name) + cell(r.agency_name)
          + cell(fmtStamp(r.unsubscribed_at))
          + cell(r.unsubscribed_by === 'self' ? 'Themselves' : (r.unsubscribed_by ? 'Administrator' : ''))
        : cell(r.email, true) + cell(r.name) + cell(r.agency_name)
          + cell(r.source) + cell(fmtStamp(r.subscribed_at));

      // Plain buttons: kt-row-actions folds the last cell into a ⋮ on its own.
      var actions = mode === 'unsubscribed'
        ? '<button class="kt-act-icon kt-icon-tip" data-sub-resub="' + r.id + '" title="Re-subscribe" data-kttip="Re-subscribe">↩️</button>'
        : '<button class="kt-act-icon kt-icon-tip" data-sub-unsub="' + r.id + '" title="Unsubscribe" data-kttip="Unsubscribe">🚫</button>';
      actions += '<button class="kt-act-icon kt-act-danger kt-icon-tip" data-sub-del="' + r.id + '" title="Delete" data-kttip="Delete">🗑️</button>';

      return '<tr>' + cells + '<td>' + actions + '</td></tr>';
    }).join('');

    return '<div class="kt-card">'
      + '<table><thead>' + head + '</thead><tbody>' + body + '</tbody></table></div>';
  }

  function subStats(container, act, unsub) {
    var host = container.querySelector('#ws-substats');
    if (!host) return;
    var now = Date.now(), DAY = 864e5;
    var when = function (r) { var t = Date.parse(String(r.subscribed_at || r.created_at || '').replace(' ', 'T') + (/[zZ]|[+-]\d\d:?\d\d$/.test(String(r.subscribed_at || r.created_at || '')) ? '' : 'Z')); return isNaN(t) ? 0 : t; };
    var new7 = act.filter(function (r) { return now - when(r) < 7 * DAY; }).length;
    var new30 = act.filter(function (r) { return now - when(r) < 30 * DAY; }).length;
    var welcomed = act.filter(function (r) { return r.lead_welcome_sent_at; }).length;
    var out30 = unsub.filter(function (r) { var t = Date.parse(String(r.unsubscribed_at || '').replace(' ', 'T') + 'Z'); return !isNaN(t) && now - t < 30 * DAY; }).length;
    var src = {}; act.forEach(function (r) { var k = (r.source || 'unknown').replace(/-/g, ' '); src[k] = (src[k] || 0) + 1; });
    var topSrc = Object.keys(src).sort(function (p, q) { return src[q] - src[p]; })[0];
    var total = act.length + unsub.length;
    host.innerHTML = '<div style="display:flex;gap:12px;flex-wrap:wrap;margin-bottom:16px">'
      + stat('Subscribed', act.length, total ? Math.round(act.length * 100 / total) + '% of everyone who signed up' : '', '#16a34a')
      + stat('New this week', new7, new30 + ' in the last 30 days', TEAL)
      + stat('Unsubscribed', unsub.length, out30 + ' in the last 30 days', unsub.length ? '#B45309' : '#16a34a')
      + stat('Welcome email sent', act.length ? Math.round(welcomed * 100 / act.length) + '%' : '—', welcomed + ' of ' + act.length + ' subscribed', TEAL)
      + stat('Top source', topSrc ? topSrc : '—', topSrc ? src[topSrc] + ' subscriber' + (src[topSrc] === 1 ? '' : 's') : '', '#0a1e2c')
      + '</div>';
    var tabs = container.querySelectorAll('#ws-subtabs [data-sub-view]');
    tabs.forEach(function (b) { b.textContent = (b.getAttribute('data-sub-view') === 'active' ? 'Subscribed ' : 'Unsubscribed ') + (b.getAttribute('data-sub-view') === 'active' ? act.length : unsub.length); });
  }

  function loadSubs(container) {
    var got = {};
    ['active', 'unsubscribed'].forEach(function (mode) {
      var host = container.querySelector(mode === 'active' ? '#ws-subs' : '#ws-unsub');
      if (!host) { return; }
      subsApi('/platform/site-subscribers?status=' + mode)
        .then(function (j) {
          var rows = j.data || [];
          var head = mode === 'active'
            ? '<div style="display:flex;align-items:center;justify-content:space-between;gap:10px;flex-wrap:wrap;margin:0 0 10px;">'
              + '<div style="font-size:13px;color:#6b7280;">People who signed up on the public site. '
              + 'Unsubscribing keeps the record and stamps the time — it never deletes the person.</div>'
              + '<button id="ws-sub-add" class="kt-btn kt-btn-ghost" style="white-space:nowrap;">+ Add subscriber</button></div>'
            : '<div style="display:flex;align-items:center;justify-content:space-between;gap:10px;flex-wrap:wrap;margin:0 0 10px;">'
              + '<div style="font-size:13px;color:#6b7280;">Everyone who has opted out, and when. '
              + 'They stay suppressed from marketing email until re-subscribed.</div>'
              + '<button id="ws-unsub-add" class="kt-btn kt-btn-ghost" style="white-space:nowrap;">+ Add to unsubscribed</button></div>';
          host.innerHTML = head + subsTable(rows, mode);
          wireSubActions(container, host);
          got[mode] = rows;
          if (got.active && got.unsubscribed) subStats(container, got.active, got.unsubscribed);
        })
        .catch(function (e) {
          host.innerHTML = '<div style="color:#b91c1c;padding:14px;">Could not load subscribers: ' + esc(e.message || 'error') + '</div>';
        });
    });
  }

  function wireSubActions(container, host) {
    function act(sel, run, confirmMsg) {
      host.querySelectorAll(sel).forEach(function (b) {
        b.addEventListener('click', async function () {
          if (confirmMsg && window.KT && KT.confirm && !(await KT.confirm(confirmMsg))) { return; }
          b.disabled = true;
          try { await run(b); loadSubs(container); }
          catch (e) {
            b.disabled = false;
            if (window.KT && KT.Dom && KT.Dom.toast) { KT.Dom.toast(e.message || 'Could not do that', 'error'); }
          }
        });
      });
    }
    act('[data-sub-unsub]', function (b) {
      return subsApi('/platform/site-subscribers/' + b.getAttribute('data-sub-unsub') + '/unsubscribe', { method: 'POST', body: '{}' });
    }, 'Unsubscribe this person? They stay on the list with the date recorded.');
    act('[data-sub-resub]', function (b) {
      return subsApi('/platform/site-subscribers/' + b.getAttribute('data-sub-resub') + '/resubscribe', { method: 'POST', body: '{}' });
    }, 'Re-subscribe this person? Only do this if they have asked to come back.');
    act('[data-sub-del]', function (b) {
      return subsApi('/platform/site-subscribers/' + b.getAttribute('data-sub-del'), { method: 'DELETE' });
    }, 'Delete this record entirely? Unsubscribing is usually what you want — deleting loses the proof they opted out.');

    var add = host.querySelector('#ws-sub-add');
    if (add) {
      add.addEventListener('click', async function () {
        var email = await KT.prompt({ title: 'Add a subscriber', description: 'This address is added to the website mailing list.', placeholder: 'name@example.com', type: 'email', okLabel: 'Add subscriber' });
        if (!email) { return; }
        try {
          await subsApi('/platform/site-subscribers', { method: 'POST', body: JSON.stringify({ email: email.trim() }) });
          loadSubs(container);
        } catch (e) {
          if (window.KT && KT.Dom && KT.Dom.toast) { KT.Dom.toast(e.message || 'Could not add', 'error'); }
        }
      });
    }

    /* ADD SOMEONE TO THE UNSUBSCRIBED LIST BY HAND (2026-11-09) — asked by phone or email, or never to be mailed.
       A current subscriber is unsubscribed in place; a new address goes straight onto this list. */
    var addUn = host.querySelector('#ws-unsub-add');
    if (addUn) {
      addUn.addEventListener('click', async function () {
        var v = await KT.prompt({ title: 'Add to unsubscribed', description: 'This address will never get marketing email until it is re-subscribed.', okLabel: 'Add to unsubscribed',
          fields: [
            { key: 'email', label: 'Email', type: 'email', placeholder: 'name@example.com' },
            { key: 'name', label: 'Name (optional)', placeholder: 'Their name' },
            { key: 'note', label: 'Reason (optional)', placeholder: 'e.g. Asked by phone' },
            { key: 'notify', label: 'Email them a confirmation?', type: 'select', value: '0', options: [{ value: '0', label: 'No' }, { value: '1', label: 'Yes, confirm they are unsubscribed' }] },
          ] });
        if (!v) { return; }
        var email = String(v.email || '').trim();
        if (!/^[^@\s]+@[^@\s]+\.[^@\s]+$/.test(email)) {
          if (window.KT && KT.Dom && KT.Dom.toast) { KT.Dom.toast('Enter a full email address.', 'error'); }
          return;
        }
        try {
          var r = await subsApi('/platform/site-subscribers/suppress', { method: 'POST',
            body: JSON.stringify({ email: email, name: String(v.name || '').trim() || null, note: String(v.note || '').trim() || null, notify: v.notify === '1' }) });
          if (window.KT && KT.Dom && KT.Dom.toast) { KT.Dom.toast(r && r.already ? email + ' was already unsubscribed.' : email + ' is now unsubscribed.', 'success'); }
          loadSubs(container);
        } catch (e) {
          if (window.KT && KT.Dom && KT.Dom.toast) { KT.Dom.toast(e.message || 'Could not add', 'error'); }
        }
      });
    }
  }

  function wire(container, leads) {
    loadSubs(container);
    container.querySelectorAll('#ws-subtabs [data-sub-view]').forEach(function (b) {
      b.addEventListener('click', function () {
        var v = b.getAttribute('data-sub-view');
        container.querySelectorAll('#ws-subtabs [data-sub-view]').forEach(function (x) { x.setAttribute('aria-selected', x === b ? 'true' : 'false'); });
        container.querySelector('#ws-subs').style.display = v === 'active' ? '' : 'none';
        container.querySelector('#ws-unsub').style.display = v === 'active' ? 'none' : '';
      });
    });
    container.querySelectorAll('.wsTab').forEach(function (btn) {
      btn.addEventListener('click', function () {
        var id = btn.getAttribute('data-tab');
        container.querySelectorAll('.wsTab').forEach(function (b) { b.style.borderBottomColor = 'transparent'; b.style.color = '#6b7280'; });
        btn.style.borderBottomColor = TEAL; btn.style.color = TEAL;
        container.querySelectorAll('.wsPanel').forEach(function (p) { p.style.display = p.getAttribute('data-panel') === id ? '' : 'none'; });
        if (id === 'translations' && !container.__trLoaded) { container.__trLoaded = true; loadTr(container); }
      });
    });

    /* Website chat: views, open/close, and the ⋮ (Download transcript / Archive / Delete). */
    var chatPanel = container.querySelector('.wsPanel[data-panel="chat"]');
    function chatOf(sid) { return (container.__chats || []).filter(function (c) { return c.session === sid; })[0]; }
    function paintChats() { if (chatPanel) chatPanel.innerHTML = tabChats(container.__chats, container.__chatArch); }
    function chatSay(msg, ok) {
      if (KT.toast) { KT.toast(msg, ok ? 'success' : 'error'); return; }
      if (!ok && KT.alert) KT.alert(msg);
    }
    function downloadTranscript(s) {
      var lines = ['KiddieTrac website chat',
        'Visitor: ' + (s.name || 'Anonymous') + (s.email ? ' <' + s.email + '>' : ''),
        'Started: ' + chatWhen(s.started), 'Last message: ' + chatWhen(s.last),
        'Messages: ' + s.count, 'Times are Toronto time.', ''];
      (s.messages || []).forEach(function (m) {
        lines.push('[' + chatWhen(m.at) + '] ' + chatWho(s, m) + ':');
        lines.push(String(m.message || ''));
        lines.push('');
      });
      var slug = String(s.name || s.email || 'visitor').toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-+|-+$/g, '').slice(0, 40) || 'visitor';
      var day = String(s.started || '').slice(0, 10) || 'chat';
      var blob = new Blob([lines.join('\r\n')], { type: 'text/plain;charset=utf-8' });
      var url = URL.createObjectURL(blob);
      var a = document.createElement('a');
      a.href = url; a.download = 'kiddietrac-chat-' + slug + '-' + day + '.txt';
      document.body.appendChild(a); a.click(); a.remove();
      setTimeout(function () { URL.revokeObjectURL(url); }, 4000);
    }
    function archiveChat(s, on) {
      Api.post('/platform/marketing-site/chats/archive', { session: s.session, archived: on }, { quiet: true }).then(function () {
        s.archived = on; paintChats();
        chatSay(on ? 'Conversation archived.' : 'Conversation moved back to Active.', true);
      }).catch(function (e) { chatSay((e && e.message) || 'Could not update the conversation.'); });
    }
    async function deleteChat(s) {
      if (KT.confirm && !(await KT.confirm({ title: 'Delete this conversation?',
        description: 'The conversation with ' + (s.name || s.email || 'this visitor') + ' (' + s.count + ' messages) is removed for good, here and in the Website chat inbox. Download the transcript first if you need a copy.',
        okLabel: 'Delete' }))) { return; }
      Api.post('/platform/marketing-site/chats/delete', { session: s.session }).then(function () {
        container.__chats = (container.__chats || []).filter(function (c) { return c !== s; });
        paintChats(); chatSay('Conversation deleted.', true);
      }).catch(function (e) { chatSay((e && e.message) || 'Could not delete the conversation.'); });
    }
    if (chatPanel) chatPanel.addEventListener('click', function (e) {
      var v = e.target.closest('.wsChatView');
      if (v) { container.__chatArch = v.getAttribute('data-arch') === '1'; paintChats(); return; }
      var k = e.target.closest('.wsChatMenu');
      if (k) {
        e.preventDefault(); e.stopPropagation();
        var s = chatOf(k.getAttribute('data-s'));
        if (!s || !KT.openActionMenu) return;
        function item(label, fn) {
          var b = document.createElement('button'); b.type = 'button'; b.textContent = label;
          b.addEventListener('click', fn); return b;
        }
        KT.openActionMenu(k, [
          item('⬇️ Download transcript', function () { downloadTranscript(s); }),
          item(s.archived ? '↩️ Move back to Active' : '🗄️ Archive', function () { archiveChat(s, !s.archived); }),
          item('🗑️ Delete', function () { deleteChat(s); })
        ]);
        return;
      }
      var t = e.target.closest('.chatToggle');
      if (t) {
        var body = chatPanel.querySelector('.chatBody[data-s="' + t.getAttribute('data-s') + '"]');
        if (body) body.style.display = body.style.display === 'none' ? '' : 'none';
      }
    });
    function val(id) { var el = container.querySelector('#' + id); return el ? el.value.trim() : ''; }
    function fullBody() {
      var on = container.querySelector('#ms_on');
      return {
        ga_id: val('ms_ga'), announce_enabled: on ? on.checked : true, announce_tag: val('ms_tag'),
        announce_text: val('ms_text'), announce_cta_text: val('ms_cta'), announce_cta_page: val('ms_page') || 'contact',
        seo_title: val('seo_title'), seo_description: val('seo_description'), og_image: val('og_image'),
        contact_email: val('contact_email'), contact_phone: val('contact_phone'), contact_address: val('contact_address'),
        chat_transcript_to: val('chat_transcript_to'),
        social_facebook: val('social_facebook'), social_instagram: val('social_instagram'), social_linkedin: val('social_linkedin'), social_x: val('social_x'),
        hero_stat1_num: val('hero_stat1_num'), hero_stat1_lbl: val('hero_stat1_lbl'),
        hero_stat2_num: val('hero_stat2_num'), hero_stat2_lbl: val('hero_stat2_lbl'),
        hero_stat3_num: val('hero_stat3_num'), hero_stat3_lbl: val('hero_stat3_lbl'),
        recaptcha_enabled: (function () { var e = container.querySelector('#recaptcha_enabled'); return e ? e.checked : false; })(),
        recaptcha_site_key: val('recaptcha_site_key'), recaptcha_secret: val('recaptcha_secret')
      };
    }
    function bindSave(btnId, msgId) {
      var save = container.querySelector('#' + btnId); if (!save) return;
      save.addEventListener('click', function () {
        var msg = container.querySelector('#' + msgId);
        save.disabled = true; if (msg) { msg.textContent = 'Saving…'; msg.style.color = '#6b7280'; }
        Api.put('/platform/marketing-site', fullBody()).then(function () {
          if (msg) { msg.textContent = '✓ Saved — live shortly'; msg.style.color = '#16a34a'; } save.disabled = false;
        }).catch(function (e) { if (msg) { msg.textContent = '✕ ' + (e && e.message ? e.message : 'Save failed'); msg.style.color = '#DC2626'; } save.disabled = false; });
      });
    }
    ['seoSave:seoMsg', 'annSave:annMsg', 'gaSave:gaMsg', 'contactSave:contactMsg', 'socialSave:socialMsg', 'heroSave:heroMsg', 'rcSave:rcMsg'].forEach(function (p) { var x = p.split(':'); bindSave(x[0], x[1]); });

    // subscriber search
    var search = container.querySelector('#leadSearch');
    if (search) search.addEventListener('input', function () {
      var q = search.value.trim().toLowerCase(), shown = 0;
      container.querySelectorAll('.leadRow').forEach(function (r) {
        var match = !q || (r.getAttribute('data-q') || '').indexOf(q) !== -1; r.style.display = match ? '' : 'none'; if (match) shown++;
      });
      var cnt = container.querySelector('#leadCount'); if (cnt) cnt.textContent = shown;
    });
    function setCount(n) { var c = container.querySelector('#leadCount'); if (c) c.textContent = n; }
    // add subscriber
    var addBtn = container.querySelector('#addLeadBtn');
    if (addBtn) addBtn.addEventListener('click', function () {
      var email = val('addEmail'), name = val('addName'), agency = val('addAgency');
      var msg = container.querySelector('#addLeadMsg');
      if (!email) { if (msg) { msg.textContent = 'Email required'; msg.style.color = '#DC2626'; } return; }
      addBtn.disabled = true; if (msg) { msg.textContent = 'Adding…'; msg.style.color = '#6b7280'; }
      Api.post('/platform/marketing-site/leads', { email: email, name: name, agency: agency }).then(function (r) {
        var lead = r && r.lead ? r.lead : { email: email, name: name, agency: agency, source: 'manual', at: '' };
        leads.unshift(lead);
        var body = container.querySelector('#leadBody'); var empty = container.querySelector('#leadEmpty'); if (empty) empty.remove();
        body.insertAdjacentHTML('afterbegin', leadRowHtml(lead)); bindRow(body.querySelector('.leadRow'));
        setCount(leads.length);
        container.querySelector('#addEmail').value = ''; container.querySelector('#addName').value = ''; container.querySelector('#addAgency').value = '';
        if (msg) { msg.textContent = '✓ Added'; msg.style.color = '#16a34a'; } addBtn.disabled = false;
      }).catch(function (e) { if (msg) { msg.textContent = '✕ ' + (e && e.message ? e.message : 'Failed'); msg.style.color = '#DC2626'; } addBtn.disabled = false; });
    });
    // edit + delete subscriber
    function bindRow(row) {
      if (!row) return;
      var del = row.querySelector('.leadDel'), edt = row.querySelector('.leadEdit');
      var email = row.getAttribute('data-email'), at = row.getAttribute('data-at');
      if (del) del.addEventListener('click', async function () {
        if (!await KT.confirm('Delete subscriber ' + email + '?')) return; del.disabled = true;
        Api.post('/platform/marketing-site/leads/delete', { email: email, at: at }).then(function () {
          row.remove(); for (var i = 0; i < leads.length; i++) { if (leads[i].email === email && leads[i].at === at) { leads.splice(i, 1); break; } }
          setCount(leads.length);
        }).catch(function () { del.disabled = false; window.alert('Delete failed'); });
      });
      if (edt) edt.addEventListener('click', async function () {
        // One dialog for what used to be two prompts in a row.
        var v = await KT.prompt({
          title: 'Edit subscriber', description: email,
          fields: [
            { key: 'name', label: 'Subscriber name', value: row.getAttribute('data-name') || '' },
            { key: 'agency', label: 'Agency / centre', value: row.getAttribute('data-agency') || '' },
          ],
          okLabel: 'Save',
        });
        if (v === null) return;
        var nn = String(v.name || ''), aa = String(v.agency || '');
        nn = nn.trim(); aa = aa.trim(); edt.disabled = true;
        Api.post('/platform/marketing-site/leads/update', { email: email, at: at, name: nn, agency: aa }).then(function () {
          row.querySelector('.cellName').textContent = nn || '—'; row.querySelector('.cellAgency').textContent = aa || '—';
          row.setAttribute('data-name', nn); row.setAttribute('data-agency', aa);
          for (var i = 0; i < leads.length; i++) { if (leads[i].email === email && leads[i].at === at) { leads[i].name = nn; leads[i].agency = aa; break; } }
          edt.disabled = false;
        }).catch(function () { edt.disabled = false; window.alert('Update failed'); });
      });
    }
    container.querySelectorAll('.leadRow').forEach(bindRow);
    // CSV export
    var csv = container.querySelector('#leadCsv');
    if (csv) csv.addEventListener('click', function () {
      var head = ['date', 'email', 'name', 'agency', 'source'];
      var lines = [head.join(',')].concat((leads || []).map(function (l) {
        return [l.at || '', l.email || '', l.name || '', l.agency || '', l.source || ''].map(function (v) {
          v = String(v).replace(/"/g, '""'); return /[",\n]/.test(v) ? '"' + v + '"' : v;
        }).join(',');
      }));
      var blob = new Blob([lines.join('\n')], { type: 'text/csv' });
      var a = document.createElement('a'); a.href = URL.createObjectURL(blob); a.download = 'kiddietrac-subscribers.csv';
      document.body.appendChild(a); a.click(); setTimeout(function () { document.body.removeChild(a); URL.revokeObjectURL(a.href); }, 500);
    });
  }

  /* Translations. The site ships a checked French/Spanish dictionary; text added after that
     (a new blog post, an edited heading) is translated automatically the first time a
     French or Spanish visitor sees it and goes live at once. This tab is the review queue:
     'Automatic' rows are machine output, 'Edited' rows are a person's correction and are
     never overwritten. Deleting a row makes the site ask for a fresh translation. */
  var TR_LANG = { fr: 'Français', es: 'Español' };
  var trState = { lang: '', status: 'auto', q: '' };

  function trApi(path, opts) {
    var base = (window.KT && KT.API_BASE) || 'https://api.kiddietrac.com/api/v1';
    var tok = sessionStorage.getItem('kt_token') || localStorage.getItem('kt_token');
    return fetch(base + path, Object.assign({
      headers: {
        Authorization: 'Bearer ' + tok, Accept: 'application/json', 'Content-Type': 'application/json',
        'X-Active-Agency-Id': sessionStorage.getItem('kt_active_agency_id') || '',
      },
    }, opts || {})).then(function (r) {
      return r.json().catch(function () { return {}; }).then(function (j) {
        // a 422 carries the reason (markers changed) — show it, not "HTTP 422"
        if (!r.ok) { throw new Error((j && j.message) || ('HTTP ' + r.status)); }
        return j;
      });
    });
  }

  function trNotice(d) {
    var err = d.last_error;
    if (!d.configured) {
      return '<div style="background:#FEF2F2;border:1px solid #FECACA;color:#991B1B;border-radius:8px;padding:10px 12px;font-size:13px;margin-bottom:12px">'
        + '<b>Automatic translation is off</b> — no Anthropic API key is set on the server. New text shows in English to French and Spanish visitors until one is added.</div>';
    }
    if (err) {
      var msg = String(err.message || err);
      var credit = /credit balance/i.test(msg);
      return '<div style="background:#FEF2F2;border:1px solid #FECACA;color:#991B1B;border-radius:8px;padding:10px 12px;font-size:13px;margin-bottom:12px">'
        + '<b>' + (credit ? 'Automatic translation is paused — the Anthropic account is out of credit.' : 'The last automatic translation failed.') + '</b> '
        + (credit ? 'Add credit at console.anthropic.com → Billing. Until then, new text shows in English to French and Spanish visitors. ' : '')
        + '<span style="color:#7F1D1D">' + esc(msg.slice(0, 200)) + (err.at ? ' · ' + esc(fmtStamp(err.at)) : '') + '</span></div>';
    }
    return '<div style="background:#F0FDF4;border:1px solid #BBF7D0;color:#166534;border-radius:8px;padding:10px 12px;font-size:13px;margin-bottom:12px">'
      + '<b>Automatic translation is on.</b> New text is translated the first time a French or Spanish visitor sees it and goes live straight away. Review it here.</div>';
  }

  function trCounts(d) {
    var c = { auto: 0, edited: 0 };
    (d.counts || []).forEach(function (r) { c[r.status] = (c[r.status] || 0) + Number(r.c || 0); });
    return c;
  }

  function loadTr(container) {
    var host = container.querySelector('#ws-tr'); if (!host) { return; }
    var qs = '?status=' + encodeURIComponent(trState.status) + '&lang=' + encodeURIComponent(trState.lang) + '&q=' + encodeURIComponent(trState.q);
    trApi('/platform/marketing-site/translations' + qs).then(function (d) {
      var rows = d.translations || [], cnt = trCounts(d);
      var sel = 'height:30px;padding:0 8px;border:1px solid #D1D5DB;border-radius:6px;font-size:13px;font-family:inherit;background:#fff;width:auto;flex:0 0 auto;margin:0';
      var html = trNotice(d)
        + '<div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;margin-bottom:12px">'
        + '<select id="tr-status" style="' + sel + '">'
        + '<option value="auto"' + (trState.status === 'auto' ? ' selected' : '') + '>To review — automatic (' + cnt.auto + ')</option>'
        + '<option value="edited"' + (trState.status === 'edited' ? ' selected' : '') + '>Edited (' + cnt.edited + ')</option>'
        + '<option value=""' + (trState.status === '' ? ' selected' : '') + '>All</option></select>'
        + '<select id="tr-lang" style="' + sel + '"><option value="">All languages</option>'
        + Object.keys(TR_LANG).map(function (l) { return '<option value="' + l + '"' + (trState.lang === l ? ' selected' : '') + '>' + TR_LANG[l] + '</option>'; }).join('')
        + '</select>'
        + '<input id="tr-q" type="search" placeholder="Search English or translation" value="' + esc(trState.q) + '" style="' + sel + ';flex:1 1 180px;min-width:0">'
        + '</div>'
        + '<p style="font-size:12.5px;color:#6b7280;margin:0 0 12px">The hand-checked dictionary shipped with the site is not listed here — only text translated automatically since. Keep any <code>{0}…{/0}</code> markers and HTML tags exactly as they are: they are the links, bold words and icons.</p>';
      if (!rows.length) {
        html += '<div style="padding:28px;text-align:center;color:#6b7280;border:1px dashed #E5E7EB;border-radius:10px">'
          + (trState.status === 'auto' ? 'Nothing waiting for review.' : 'No translations match.') + '</div>';
      } else {
        html += rows.map(function (t) {
          var long = t.kind === 'Blog article' || String(t.en).length > 600;
          return '<div class="tr-row" data-id="' + t.id + '" style="border:1px solid #E5E7EB;border-radius:10px;padding:10px 12px;margin-bottom:10px;background:#fff">'
            + '<div style="display:flex;gap:6px;align-items:center;flex-wrap:wrap;font-size:12px;color:#6b7280;margin-bottom:6px">'
            + '<span style="font-weight:700;color:#0a1e2c">' + esc(TR_LANG[t.lang] || t.lang) + '</span> · ' + esc(t.kind)
            + ' · <span style="padding:1px 7px;border-radius:999px;font-weight:700;' + (t.status === 'edited' ? 'background:#DCFCE7;color:#166534">Edited' : 'background:#FEF3C7;color:#92400E">Automatic') + '</span>'
            + '<span style="margin-left:auto">' + esc(fmtStamp(t.updated_at)) + '</span></div>'
            + '<div style="font-size:13px;color:#374151;background:#F9FAFB;border-radius:6px;padding:7px 9px;margin-bottom:6px;white-space:pre-wrap;word-break:break-word;max-height:' + (long ? '120px' : 'none') + ';overflow:auto">' + esc(t.en) + '</div>'
            + '<textarea class="tr-text" rows="' + (long ? 8 : Math.min(6, Math.max(2, Math.ceil(String(t.text).length / 90)))) + '" style="display:block;width:100%;max-width:none !important;box-sizing:border-box;padding:7px 9px;border:1px solid #D1D5DB;border-radius:6px;font-size:13px;font-family:inherit;resize:vertical">' + esc(t.text) + '</textarea>'
            + '<div style="display:flex;gap:6px;justify-content:flex-end;align-items:center;margin-top:6px">'
            + '<span class="tr-msg" style="font-size:12px;margin-right:auto"></span>'
            + '<button type="button" class="tr-del" style="height:30px;padding:0 12px;border:1px solid #FECACA;background:#fff;color:#B91C1C;border-radius:6px;font-size:12.5px;font-weight:700;cursor:pointer">Delete</button>'
            + '<button type="button" class="tr-save" style="height:30px;padding:0 12px;border:none;background:' + TEAL + ';color:#fff;border-radius:6px;font-size:12.5px;font-weight:700;cursor:pointer">' + (t.status === 'auto' ? 'Approve' : 'Save') + '</button>'
            + '</div></div>';
        }).join('');
        if (rows.length >= 500) { html += '<p style="font-size:12px;color:#6b7280">Showing the 500 most recent — search to narrow.</p>'; }
      }
      host.innerHTML = html;
      wireTr(container, host);
    }).catch(function (e) {
      host.innerHTML = '<div style="color:#b91c1c;padding:14px">Could not load translations: ' + esc(e.message || 'error') + '</div>';
    });
  }

  function wireTr(container, host) {
    var st = host.querySelector('#tr-status'), lg = host.querySelector('#tr-lang'), q = host.querySelector('#tr-q'), tmr;
    if (st) st.addEventListener('change', function () { trState.status = st.value; loadTr(container); });
    if (lg) lg.addEventListener('change', function () { trState.lang = lg.value; loadTr(container); });
    if (q) q.addEventListener('input', function () {
      clearTimeout(tmr);
      tmr = setTimeout(function () { trState.q = q.value.trim(); loadTr(container); }, 400);
    });
    host.querySelectorAll('.tr-row').forEach(function (row) {
      var id = row.getAttribute('data-id'), msg = row.querySelector('.tr-msg');
      function say(t, ok) { msg.textContent = t; msg.style.color = ok ? '#16a34a' : '#DC2626'; }
      row.querySelector('.tr-save').addEventListener('click', function (ev) {
        var b = ev.currentTarget, text = row.querySelector('.tr-text').value.trim();
        if (!text) { say('A translation is required'); return; }
        b.disabled = true;
        trApi('/platform/marketing-site/translations/' + id, { method: 'PUT', body: JSON.stringify({ text: text }) })
          .then(function () {
            say('✓ Saved — live within a few minutes', true); b.disabled = false;
            // approved = no longer waiting for review: let it leave the queue
            if (trState.status === 'auto') { setTimeout(function () { loadTr(container); }, 1200); }
          })
          .catch(function (e) { say(e.message || 'Could not save'); b.disabled = false; });
      });
      row.querySelector('.tr-del').addEventListener('click', async function (ev) {
        var b = ev.currentTarget;
        if (KT.confirm && !(await KT.confirm({ title: 'Delete this translation?', description: 'The site will ask for a fresh automatic translation the next time a visitor sees this text.', okLabel: 'Delete' }))) { return; }
        b.disabled = true;
        trApi('/platform/marketing-site/translations/' + id, { method: 'DELETE' })
          .then(function () { row.remove(); })
          .catch(function (e) { say(e.message || 'Could not delete'); b.disabled = false; });
      });
    });
  }

  function render(container) {
    // 27 other screens set this; the shared table, card and spacing rules key off it.
    // Without it this screen was styled entirely on its own and looked like it.
    try { container.setAttribute('data-kt-pretty', '1'); } catch (e) {}
    if (!isPlatformAdmin()) {
      container.innerHTML = '<div style="max-width:560px;margin:60px auto;text-align:center;color:#6b7280">'
        + '<div style="font-size:40px;margin-bottom:10px">🔒</div>'
        + '<h2 style="font-size:18px;font-weight:800;color:#0a1e2c;margin-bottom:6px">Super-admin only</h2>'
        + '<p>The Website control center is restricted to platform super-admins.</p></div>';
      return;
    }
    container.innerHTML = '<div style="padding:24px;color:#6b7280">Loading website data…</div>';
    Promise.all([
      Api.get('/platform/marketing-site'),
      Api.get('/platform/marketing-site/analytics').catch(function () { return null; }),
      Api.get('/platform/marketing-site/leads').catch(function () { return { leads: [] }; }),
      Api.get('/platform/marketing-site/chats').catch(function () { return { sessions: [] }; })
    ]).then(function (res) {
      var cfg = res[0] || {}, a = res[1], leads = (res[2] || {}).leads || [], chats = (res[3] || {}).sessions || [];
      container.__chats = chats; container.__chatArch = false;
      container.innerHTML = view(cfg, a, leads, chats);
      wire(container, leads);
    }).catch(function (e) {
      container.innerHTML = '<div style="padding:24px;color:#DC2626">Could not load website data: ' + esc(e && e.message) + '</div>';
    });
  }

  if (Shell && Shell.registerScreen) {
    Shell.registerScreen('agency_admin:marketing-site', render);
    Shell.registerScreen('platform_admin:marketing-site', render);
  }
  KT.MarketingSiteScreen = { render: render };
})(window);
