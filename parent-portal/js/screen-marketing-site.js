/* ============================================================
   KIDDIETRAC — Website control center (platform_admin / super-admin ONLY)
   Route: #marketing-site
   Tabs: Overview · Subscribers · Content · SEO · Announcement · Analytics
   Backend:
     GET/PUT /api/v1/platform/marketing-site            (settings)
     GET     /api/v1/platform/marketing-site/leads      (subscribers)
     POST    /api/v1/platform/marketing-site/leads      (add subscriber)
     POST    /api/v1/platform/marketing-site/leads/delete (delete subscriber)
     GET     /api/v1/platform/marketing-site/analytics  (first-party views)
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
          + '<a href="https://www.kiddietrac.com/sitemap.xml" target="_blank" rel="noopener" style="font-size:13px;color:' + TEAL + ';font-weight:700;text-decoration:none">View sitemap ↗</a></div>');
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
      + field('Button links to page', 'ms_page', c.announce_cta_page || 'contact', 'contact', 'e.g. contact, pricing, solutions, whitelabel, checklist')
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

  function tabChats(chats) {
    if (!chats || !chats.length) return card('', '<div style="padding:24px;text-align:center;color:#64748B;font-size:14px">No chats yet. Conversations from the website chat widget appear here with the visitor\'s name &amp; email.</div>');
    return card('', '<div style="font-size:13px;color:#6b7280;font-weight:700;margin-bottom:14px">' + chats.length + ' conversation' + (chats.length === 1 ? '' : 's') + '</div>'
      + chats.map(function (s, i) {
        var when = (s.last || '').replace('T', ' ').slice(0, 16);
        var who = (s.name || 'Anonymous') + (s.email ? ' · ' + s.email : '');
        var msgs = (s.messages || []).map(function (m) {
          var mine = m.sender === 'visitor';
          return '<div style="display:flex;justify-content:' + (mine ? 'flex-end' : 'flex-start') + ';margin:4px 0"><div style="max-width:78%;padding:7px 11px;border-radius:10px;font-size:13px;line-height:1.45;white-space:pre-wrap;background:' + (mine ? TEAL : '#F1F5F9') + ';color:' + (mine ? '#fff' : '#0a1e2c') + '">' + esc(m.message) + '</div></div>';
        }).join('');
        return '<div style="border:1px solid #E5E7EB;border-radius:12px;margin-bottom:10px;overflow:hidden">'
          + '<button class="chatToggle" data-i="' + i + '" style="width:100%;text-align:left;background:#F9FAFB;border:none;padding:12px 16px;cursor:pointer;display:flex;justify-content:space-between;align-items:center;gap:10px">'
          + '<span style="font-size:13.5px;font-weight:700;color:#0a1e2c">💬 ' + esc(who) + '</span>'
          + '<span style="font-size:12px;color:#64748B;white-space:nowrap">' + s.count + ' msgs · ' + esc(when) + '</span></button>'
          + '<div class="chatBody" data-i="' + i + '" style="display:none;padding:12px 16px;background:#fff">' + msgs + '</div></div>';
      }).join(''));
  }

  var TABS = [
    { id: 'overview', label: 'Overview' }, { id: 'subscribers', label: 'Subscribers' },
    { id: 'chat', label: 'Chat' }, { id: 'content', label: 'Content' }, { id: 'seo', label: 'SEO' },
    { id: 'announce', label: 'Announcement' }, { id: 'analytics', label: 'Analytics' }
  ];

  function view(c, a, leads, chats) {
    var tabBtns = TABS.map(function (t, i) {
      return '<button class="wsTab" data-tab="' + t.id + '" style="background:none;border:none;border-bottom:3px solid ' + (i === 0 ? TEAL : 'transparent') + ';color:' + (i === 0 ? TEAL : '#6b7280') + ';font-weight:700;font-size:14px;padding:10px 14px;cursor:pointer;white-space:nowrap">' + t.label + '</button>';
    }).join('');
    var panels = '<div class="wsPanel" data-panel="overview">' + tabOverview(c, a) + '</div>'
      + '<div class="wsPanel" data-panel="subscribers" style="display:none">' + tabSubscribers(leads) + '</div>'
      + '<div class="wsPanel" data-panel="chat" style="display:none">' + tabChats(chats) + '</div>'
      + '<div class="wsPanel" data-panel="content" style="display:none">' + tabContent(c) + '</div>'
      + '<div class="wsPanel" data-panel="seo" style="display:none">' + tabSeo(c) + '</div>'
      + '<div class="wsPanel" data-panel="announce" style="display:none">' + tabAnnounce(c) + '</div>'
      + '<div class="wsPanel" data-panel="analytics" style="display:none">' + tabAnalytics(c, a) + '</div>';
    return '<div style="max-width:900px;margin:0 auto;padding:24px">'
      + '<p style="color:#6b7280;margin:-4px 0 18px;font-size:14px">Manage <b>www.kiddietrac.com</b> — subscribers, content, SEO, the announcement bar and analytics. Changes go live within about a minute.</p>'
      + '<div style="display:flex;gap:4px;border-bottom:1px solid #E5E7EB;margin-bottom:20px;overflow-x:auto">' + tabBtns + '</div>' + panels + '</div>';
  }

  function wire(container, leads) {
    container.querySelectorAll('.wsTab').forEach(function (btn) {
      btn.addEventListener('click', function () {
        var id = btn.getAttribute('data-tab');
        container.querySelectorAll('.wsTab').forEach(function (b) { b.style.borderBottomColor = 'transparent'; b.style.color = '#6b7280'; });
        btn.style.borderBottomColor = TEAL; btn.style.color = TEAL;
        container.querySelectorAll('.wsPanel').forEach(function (p) { p.style.display = p.getAttribute('data-panel') === id ? '' : 'none'; });
      });
    });

    container.querySelectorAll('.chatToggle').forEach(function (btn) {
      btn.addEventListener('click', function () {
        var body = container.querySelector('.chatBody[data-i="' + btn.getAttribute('data-i') + '"]');
        if (body) body.style.display = body.style.display === 'none' ? '' : 'none';
      });
    });
    function val(id) { var el = container.querySelector('#' + id); return el ? el.value.trim() : ''; }
    function fullBody() {
      var on = container.querySelector('#ms_on');
      return {
        ga_id: val('ms_ga'), announce_enabled: on ? on.checked : true, announce_tag: val('ms_tag'),
        announce_text: val('ms_text'), announce_cta_text: val('ms_cta'), announce_cta_page: val('ms_page') || 'contact',
        seo_title: val('seo_title'), seo_description: val('seo_description'), og_image: val('og_image'),
        contact_email: val('contact_email'), contact_phone: val('contact_phone'), contact_address: val('contact_address'),
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
      if (edt) edt.addEventListener('click', function () {
        var nn = window.prompt('Subscriber name:', row.getAttribute('data-name') || ''); if (nn === null) return;
        var aa = window.prompt('Agency / centre:', row.getAttribute('data-agency') || ''); if (aa === null) return;
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

  function render(container) {
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
