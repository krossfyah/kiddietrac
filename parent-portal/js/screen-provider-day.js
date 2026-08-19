/* ═══════════════════════════════════════════════════════════════════
   KiddieTrac — Provider Daily Overview (director/admin quick glance).
   Pick a provider + date → attendance roster (kids by name, in/out, QR vs
   manual), staff clock in/out, the week's meal plan, activities & all daily
   logs, plus summary stats with charts. Today by default; any past date works.
   Registered for agency_admin / centre_director / platform_admin.
   ═══════════════════════════════════════════════════════════════════ */
(function (window) {
  'use strict';
  var KT = (window.KT = window.KT || {});
  var Shell = KT.Shell;
  var Api = KT.Api;

  function esc(s) { return String(s == null ? '' : s).replace(/[&<>"]/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]; }); }
  // Presence dot: green = available (active in the app), amber = idle (on shift but
  // quiet). No dot for anything else (e.g. clocked out).
  function presenceDot(p) {
    if (p !== 'available' && p !== 'idle') return '';
    var c = p === 'available' ? '#16A34A' : '#F59E0B';
    return '<span title="' + (p === 'available' ? 'Available' : 'Idle') + '" style="display:inline-block;width:9px;height:9px;border-radius:50%;background:' + c + ';box-shadow:0 0 0 2px #fff;margin-right:7px;vertical-align:middle;"></span>';
  }
  function todayISO() { var d = new Date(); return d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0'); }
  function avatar(name, photo, sex, size, child) {
    if (window.KT && KT.avatar) return KT.avatar(name, { size: size, photoUrl: photo ? _abs(photo) : '', sex: sex, kind: child ? 'child' : undefined });
    return '<span style="width:' + size + 'px;height:' + size + 'px;border-radius:50%;background:#E2E8F0;display:inline-block;"></span>';
  }
  function _abs(u) { if (!u) return ''; if (/^https?:\/\//.test(u)) return u; var b = (KT.API_BASE) || 'https://api.kiddietrac.com/api/v1'; return b.replace(/\/api\/v1\/?$/, '') + (u.charAt(0) === '/' ? u : '/' + u); }

  var state = { centres: [], centreId: null, date: todayISO() };

  // Donut: QR (teal) vs Manual (amber) share of check-ins.
  function donut(qr, manual) {
    var total = qr + manual;
    if (!total) return '<div style="color:#94A3B8;font-size:12px;padding:20px 0;text-align:center;">No check-ins on this day.</div>';
    var C = 2 * Math.PI * 42, qrLen = C * (qr / total);
    return '<div style="display:flex;align-items:center;gap:16px;">'
      + '<svg width="112" height="112" viewBox="0 0 120 120" style="flex-shrink:0;">'
      + '<circle cx="60" cy="60" r="42" fill="none" stroke="#F59E0B" stroke-width="15"/>'
      + '<circle cx="60" cy="60" r="42" fill="none" stroke="#0EA5E9" stroke-width="15" stroke-dasharray="' + qrLen + ' ' + (C - qrLen) + '" transform="rotate(-90 60 60)" stroke-linecap="round"/>'
      + '<text x="60" y="57" text-anchor="middle" font-size="21" font-weight="800" fill="#0F172A">' + Math.round((qr / total) * 100) + '%</text>'
      + '<text x="60" y="74" text-anchor="middle" font-size="9.5" fill="#64748B">via QR</text></svg>'
      + '<div style="font-size:12.5px;">'
      + '<div style="display:flex;align-items:center;gap:7px;margin-bottom:6px;"><span style="width:11px;height:11px;border-radius:3px;background:#0EA5E9;display:inline-block;"></span><b>' + qr + '</b> QR / kiosk scan' + (qr === 1 ? '' : 's') + '</div>'
      + '<div style="display:flex;align-items:center;gap:7px;"><span style="width:11px;height:11px;border-radius:3px;background:#F59E0B;display:inline-block;"></span><b>' + manual + '</b> manual check-in' + (manual === 1 ? '' : 's') + '</div>'
      + '</div></div>';
  }
  // Stacked attendance bar: in / out / away.
  function attBar(s) {
    var total = Math.max(1, s.enrolled);
    var seg = function (n, col, lbl) { var w = (n / total) * 100; return w > 0 ? '<div title="' + lbl + ': ' + n + '" style="width:' + w + '%;background:' + col + ';"></div>' : ''; };
    var away = Math.max(0, s.enrolled - s.attended);
    return '<div style="display:flex;height:16px;border-radius:8px;overflow:hidden;background:#F1F5F9;">'
      + seg(s.still_in, '#16A34A', 'Still in') + seg(s.went_home, '#F59E0B', 'Went home') + seg(away, '#CBD5E1', 'Absent') + '</div>'
      + '<div style="display:flex;gap:14px;margin-top:8px;font-size:11.5px;color:#475569;flex-wrap:wrap;">'
      + '<span><span style="color:#16A34A;">●</span> ' + s.still_in + ' still in</span>'
      + '<span><span style="color:#F59E0B;">●</span> ' + s.went_home + ' went home</span>'
      + '<span><span style="color:#CBD5E1;">●</span> ' + away + ' absent</span></div>';
  }
  function card(title, inner) {
    return '<div style="background:#fff;border:1px solid #E7EBF0;border-radius:14px;padding:16px 18px;box-shadow:0 1px 4px rgba(15,23,42,.05);">'
      + '<div style="font-weight:800;font-size:12px;letter-spacing:.5px;text-transform:uppercase;color:#64748B;margin-bottom:12px;">' + title + '</div>' + inner + '</div>';
  }
  // A donut/pie of the day's logged-moment breakdown.
  var PIE_COLS = ['#0EA5E9', '#7C3AED', '#F59E0B', '#DB2777', '#94A3B8', '#16A34A'];
  function pie(data) {
    var keys = Object.keys(data).filter(function (k) { return data[k] > 0; });
    var total = keys.reduce(function (a, k) { return a + data[k]; }, 0);
    if (!total) return '<div style="color:#94A3B8;font-size:12px;padding:20px 0;text-align:center;">No moments logged.</div>';
    var C = 2 * Math.PI * 42, off = 0, segs = '';
    keys.forEach(function (k, i) {
      var frac = data[k] / total, len = C * frac;
      segs += '<circle cx="60" cy="60" r="42" fill="none" stroke="' + PIE_COLS[i % PIE_COLS.length] + '" stroke-width="16" stroke-dasharray="' + len + ' ' + (C - len) + '" stroke-dashoffset="' + (-off) + '" transform="rotate(-90 60 60)"></circle>';
      off += len;
    });
    var legend = keys.map(function (k, i) {
      // Count sits right after the label (small gap), NOT pushed to the far right —
      // the old flex:1 on the label stretched it across the whole column.
      return '<div style="display:flex;align-items:center;gap:7px;margin-bottom:5px;font-size:12.5px;"><span style="width:11px;height:11px;border-radius:3px;background:' + PIE_COLS[i % PIE_COLS.length] + ';display:inline-block;flex-shrink:0;"></span><span style="color:#334155;text-transform:capitalize;">' + k + '</span><b style="color:#0F172A;">' + data[k] + '</b></div>';
    }).join('');
    return '<div style="display:flex;align-items:center;gap:18px;flex-wrap:wrap;"><svg width="120" height="120" viewBox="0 0 120 120" style="flex-shrink:0;">' + segs
      + '<text x="60" y="56" text-anchor="middle" font-size="20" font-weight="800" fill="#0F172A">' + total + '</text>'
      + '<text x="60" y="73" text-anchor="middle" font-size="9" fill="#64748B">moments</text></svg>'
      + '<div style="min-width:150px;">' + legend + '</div></div>';
  }
  function metric(label, value, sub, accent) {
    return '<div style="background:#fff;border:1px solid #E7EBF0;border-top:3px solid ' + accent + ';border-radius:12px;padding:13px 14px;text-align:center;">'
      + '<div style="font-size:24px;font-weight:800;color:' + accent + ';">' + value + '</div>'
      + '<div style="font-size:10.5px;font-weight:700;text-transform:uppercase;letter-spacing:.4px;color:#64748B;margin-top:2px;">' + label + '</div>'
      + (sub ? '<div style="font-size:11px;color:#94A3B8;margin-top:2px;">' + sub + '</div>' : '') + '</div>';
  }

  async function render(container) {
    container.innerHTML = '<div style="padding:24px;max-width:1100px;margin:0 auto;color:#0F172A;">'
      + '<h2 style="margin:0 0 4px;font-size:21px;font-weight:800;">🗓️ Daily Overview</h2>'
      + '<div style="color:#64748B;font-size:13px;margin-bottom:16px;line-height:1.5;">A quick glance at a provider on any day — attendance, clock in/out, the week’s meals, activities and every daily log.</div>'
      + '<div id="pd-controls" style="display:flex;gap:12px;flex-wrap:wrap;align-items:flex-end;margin-bottom:18px;"></div>'
      + '<div id="pd-body"></div></div>';

    if (!state.centres.length) {
      try { var r = await Api.get('/admin/centres'); state.centres = (r && r.centres || []).map(function (c) { return { id: c.id, name: c.name }; }); }
      catch (e) { state.centres = []; }
    }
    // Pre-select the provider when arriving via a "Review day" shortcut (one-shot).
    if (!state.centreId) { try { var pre = sessionStorage.getItem('kt_pd_centre'); if (pre) { sessionStorage.removeItem('kt_pd_centre'); if (state.centres.some(function (c) { return String(c.id) === String(pre); })) state.centreId = parseInt(pre, 10); } } catch (e) {} }
    if (!state.centreId && state.centres.length) state.centreId = state.centres[0].id;

    var ctrl = container.querySelector('#pd-controls');
    ctrl.innerHTML =
      '<div style="display:flex;flex-direction:column;gap:5px;"><span style="font-size:11.5px;font-weight:700;color:#475569;">Provider</span>'
      + '<select id="pd-centre" style="padding:9px 12px;border:1px solid #DCE3EC;border-radius:10px;font-size:13.5px;min-width:220px;background:#fff;">'
      + state.centres.map(function (c) { return '<option value="' + c.id + '"' + (c.id == state.centreId ? ' selected' : '') + '>' + esc(c.name) + '</option>'; }).join('') + '</select></div>'
      + '<div style="display:flex;flex-direction:column;gap:5px;"><span style="font-size:11.5px;font-weight:700;color:#475569;">Date</span>'
      + '<input id="pd-date" type="date" value="' + state.date + '" max="' + todayISO() + '" style="padding:9px 12px;border:1px solid #DCE3EC;border-radius:10px;font-size:13.5px;"></div>'
      + '<button id="pd-today" style="padding:9px 14px;border:1px solid #CBD5E1;background:#fff;border-radius:10px;font-size:12.5px;font-weight:700;cursor:pointer;">Today</button>';
    ctrl.querySelector('#pd-centre').addEventListener('change', function (e) { state.centreId = parseInt(e.target.value, 10); load(container); });
    ctrl.querySelector('#pd-date').addEventListener('change', function (e) { state.date = e.target.value || todayISO(); load(container); });
    ctrl.querySelector('#pd-today').addEventListener('click', function () { state.date = todayISO(); container.querySelector('#pd-date').value = state.date; load(container); });
    load(container);
  }

  async function load(container) {
    var body = container.querySelector('#pd-body');
    if (!state.centreId) { body.innerHTML = '<div style="color:#64748B;padding:20px;">No providers found.</div>'; return; }
    body.innerHTML = '<div style="color:#94A3B8;padding:24px;text-align:center;">Loading…</div>';
    var d;
    try { d = await Api.get('/provider/day-activity?centre_id=' + state.centreId + '&date=' + state.date); }
    catch (e) { body.innerHTML = '<div style="color:#B91C1C;padding:20px;">Could not load: ' + esc(e.message) + '</div>'; return; }
    var s = d.summary || {};

    // Summary cards
    var cards = [
      ['👶', 'Attended', s.attended + ' / ' + s.enrolled, '#1F6080'],
      ['🟢', 'Still in', s.still_in, '#16A34A'],
      ['👋', 'Went home', s.went_home, '#B45309'],
      ['🍽️', 'Meals & snacks', s.meals, '#0EA5E9'],
      ['😴', 'Naps', s.naps, '#7C3AED'],
      ['✨', 'Activities', s.activities, '#DB2777'],
      ['⚠️', 'Incidents', s.incidents, s.incidents > 0 ? '#DC2626' : '#94A3B8'],
      ['📋', 'Moments', s.moments, '#0F172A'],
      ['🚶', 'Walked', (s.walk_km ? s.walk_km + ' km' : '—')
        + (s.walk_count ? ' · ' + s.walk_count : ''), '#0284C7'],
    ].map(function (c) {
      return '<div style="background:#fff;border:1px solid #E7EBF0;border-left:4px solid ' + c[3] + ';border-radius:12px;padding:11px 13px;box-shadow:0 1px 3px rgba(15,23,42,.04);">'
        + '<div style="font-size:18px;line-height:1;">' + c[0] + '</div>'
        + '<div style="font-size:21px;font-weight:800;color:' + c[3] + ';margin-top:5px;">' + esc(String(c[2])) + '</div>'
        + '<div style="font-size:10.5px;font-weight:700;text-transform:uppercase;letter-spacing:.4px;color:#94A3B8;">' + c[1] + '</div></div>';
    }).join('');

    // Roster table
    var TH = { in: ['#16A34A', 'In'], out: ['#F59E0B', 'Home'], away: ['#CBD5E1', 'Absent'] };
    var roster = (d.roster || []).map(function (r) {
      var t = TH[r.status] || TH.away;
      var srcBadge = r.source ? '<span style="font-size:10.5px;font-weight:800;border-radius:6px;padding:2px 7px;background:' + (r.source === 'QR' ? '#E0F2FE;color:#0369A1' : '#FEF3C7;color:#92400E') + ';">' + r.source + '</span>' : '<span style="color:#CBD5E1;">—</span>';
      var care = r.care || {};
      var napTxt = (care.naps || []).map(function (n) { return esc(n.start || '?') + (n.end ? '→' + esc(n.end) : '…'); }).join(', ');
      var careBits = [];
      if (care.naps && care.naps.length) careBits.push('😴 ' + napTxt);
      if (care.meals && care.meals.length) careBits.push('🍽️ ' + care.meals.length);
      if (care.diapers) careBits.push('🧷 ' + care.diapers);
      var careHtml = careBits.length ? '<span style="font-size:11.5px;color:#475569;">' + careBits.join(' · ') + '</span>' : '<span style="color:#CBD5E1;">—</span>';
      return '<tr style="border-top:1px solid #F1F5F9;">'
        + '<td style="padding:8px 6px;"><div style="display:flex;align-items:center;gap:9px;">' + avatar(r.name, r.photo_url, r.gender, 30, true) + '<span style="font-weight:700;font-size:13px;">' + esc(r.name) + '</span></div></td>'
        + '<td style="padding:8px 6px;font-size:12.5px;font-variant-numeric:tabular-nums;">' + (r.in ? esc(r.in) : '<span style="color:#CBD5E1;">—</span>') + '</td>'
        + '<td style="padding:8px 6px;font-size:12.5px;font-variant-numeric:tabular-nums;">' + (r.out ? esc(r.out) : '<span style="color:#CBD5E1;">—</span>') + '</td>'
        + '<td style="padding:8px 6px;">' + careHtml + '</td>'
        + '<td style="padding:8px 6px;">' + srcBadge + '</td>'
        + '<td style="padding:8px 6px;"><span style="font-size:11px;font-weight:800;color:' + t[0] + ';">● ' + t[1] + '</span></td></tr>';
    }).join('');
    var rosterTable = (d.roster && d.roster.length)
      ? '<div style="overflow-x:auto;"><table style="width:100%;border-collapse:collapse;font-size:13px;">'
        + '<thead><tr style="text-align:left;color:#94A3B8;font-size:10.5px;text-transform:uppercase;letter-spacing:.5px;"><th style="padding:0 6px 6px;">Child</th><th style="padding:0 6px 6px;">In</th><th style="padding:0 6px 6px;">Out</th><th style="padding:0 6px 6px;">Care today</th><th style="padding:0 6px 6px;">Via</th><th style="padding:0 6px 6px;">Status</th></tr></thead>'
        + '<tbody>' + roster + '</tbody></table></div>'
      : '<div style="color:#94A3B8;font-size:12.5px;padding:12px 0;">No children enrolled.</div>';

    // Staff clock
    var staff = (d.staff || []).map(function (st) {
      return '<div style="display:flex;align-items:center;gap:10px;padding:8px 0;border-top:1px solid #F1F5F9;">'
        + avatar(st.name, st.photo_url, st.sex, 30, false)
        + '<div style="flex:1;min-width:0;font-weight:700;font-size:13px;">' + presenceDot(st.presence) + esc(st.name) + (st.source ? ' <span style="font-size:10px;color:#94A3B8;font-weight:600;">(' + esc(st.source) + ')</span>' : '') + '</div>'
        + '<div style="font-size:12.5px;color:#334155;font-variant-numeric:tabular-nums;">' + esc(st.in || '—') + ' → ' + (st.out ? esc(st.out) : '<span style="color:#16A34A;">on shift</span>') + '</div></div>';
    }).join('');
    var staffCard = (d.staff && d.staff.length) ? staff : '<div style="color:#94A3B8;font-size:12.5px;padding:8px 0;">No staff clock-ins recorded.</div>';

    // Weekly menu
    var DAYMAP = { '1': 'Mon', '2': 'Tue', '3': 'Wed', '4': 'Thu', '5': 'Fri', '6': 'Sat', '7': 'Sun', monday: 'Mon', tuesday: 'Tue', wednesday: 'Wed', thursday: 'Thu', friday: 'Fri', saturday: 'Sat', sunday: 'Sun', mon: 'Mon', tue: 'Tue', wed: 'Wed', thu: 'Thu', fri: 'Fri' };
    var ORDER = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri'];
    var menu = d.menu || {};
    var byDay = {};
    Object.keys(menu).forEach(function (k) { var lbl = DAYMAP[String(k).toLowerCase()] || DAYMAP[k] || k; (byDay[lbl] = byDay[lbl] || []).push.apply(byDay[lbl], menu[k]); });
    var menuHasData = Object.keys(byDay).length > 0;
    // Highlight the day being viewed so the right day's meals jump out of the week.
    var _vd = new Date((String(d.date || '').slice(0, 10) || todayISO()) + 'T12:00:00');
    var todayLbl = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'][_vd.getDay()];
    var isTodayView = String(d.date || '').slice(0, 10) === todayISO();
    var menuGrid = menuHasData
      ? '<div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(150px,1fr));gap:10px;">'
        + ORDER.filter(function (day) { return byDay[day]; }).map(function (day) {
          var hi = (day === todayLbl);
          return '<div style="background:' + (hi ? 'rgba(14,124,144,.12)' : '#F8FAFC') + ';border:' + (hi ? '2px solid #0E7C90' : '1px solid #EEF2F6') + ';border-radius:10px;padding:10px;' + (hi ? 'box-shadow:0 5px 14px -7px rgba(14,124,144,.55);' : '') + '">'
            + '<div style="font-weight:800;font-size:11px;color:' + (hi ? '#0B5563' : '#1F6080') + ';text-transform:uppercase;letter-spacing:.5px;margin-bottom:6px;">' + day
            + (hi ? ' <span style="background:#0E7C90;color:#fff;border-radius:5px;padding:1px 6px;font-size:9px;letter-spacing:.3px;vertical-align:middle;">' + (isTodayView ? 'TODAY' : 'THIS DAY') + '</span>' : '') + '</div>'
            + byDay[day].map(function (m) { return '<div style="font-size:12px;margin-bottom:5px;"><span style="color:#94A3B8;font-size:10.5px;text-transform:capitalize;">' + esc(m.meal || '') + '</span><br><b>' + esc(m.name || '') + '</b></div>'; }).join('')
            + '</div>';
        }).join('') + '</div>'
      : '<div style="color:#94A3B8;font-size:12.5px;padding:8px 0;">No menu published for the week of ' + esc(d.week_start || '') + '.</div>';

    // Timeline
    var tl = d.timeline || [];
    // Child filter dropdown (only when there's more than one child in the feed).
    var _feedKids = Array.from(new Set(tl.map(function (e) { return e.child; }).filter(Boolean))).sort();
    var feedFilter = _feedKids.length > 1
      ? '<div style="margin-bottom:12px;display:flex;align-items:center;gap:8px;flex-wrap:wrap;">'
        + '<label style="font-size:12px;font-weight:700;color:#475569;">Child</label>'
        + '<select id="pd-child-filter" style="padding:8px 11px;border:1px solid #E2E8F0;border-radius:9px;font-size:13px;background:#fff;min-width:180px;">'
        + '<option value="">All children</option>' + _feedKids.map(function (k) { return '<option value="' + esc(k) + '">' + esc(k) + '</option>'; }).join('')
        + '</select><span id="pd-feed-count" style="font-size:12px;color:#94A3B8;"></span></div>'
      : '';
    var feed = feedFilter + (tl.length
      ? '<div id="pd-feed">' + tl.map(function (e) {
        return '<div class="pd-feed-row" data-child="' + esc(e.child || '') + '" style="display:flex;gap:12px;padding:9px 0;border-bottom:1px solid #F1F5F9;align-items:flex-start;">'
          + '<div style="font-size:12px;font-weight:700;color:#64748B;min-width:70px;text-align:right;font-variant-numeric:tabular-nums;">' + esc(e.time || '') + '</div>'
          + '<div style="font-size:16px;width:22px;text-align:center;">' + (e.icon || '•') + '</div>'
          + '<div style="flex:1;min-width:0;"><b style="font-size:13px;">' + esc(e.child || '') + '</b> <span style="font-size:13px;color:#334155;">— ' + esc(e.title || '') + (e.detail ? ' <span style="color:#64748B;">· ' + esc(e.detail) + '</span>' : '') + '</span></div></div>';
      }).join('') + '</div>'
      : '<div style="color:#94A3B8;padding:18px;text-align:center;">Nothing logged on ' + esc(d.date) + '.</div>');

    // Performance analytics + moment-breakdown pie
    var an = d.analytics || {};
    var perfMetrics = '<div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(115px,1fr));gap:11px;margin-bottom:14px;">'
      + metric('Attendance', (an.attendance_rate || 0) + '%', s.attended + '/' + s.enrolled, '#1F6080')
      + metric('Care logged', (an.care_coverage_pct || 0) + '%', 'of present kids', '#0F9D6B')
      + metric('QR check-ins', (an.qr_pct || 0) + '%', 'vs manual', '#0EA5E9')
      + metric('Avg moments', (an.avg_moments || 0), 'per child', '#7C3AED')
      + '</div>';

    // Photos & videos
    var pics = d.photos || [];
    var photosHtml = pics.length
      ? '<div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(92px,1fr));gap:8px;">'
        + pics.map(function (p) {
          var isVid = /video/i.test(p.type || '');
          return '<a href="' + esc(p.url) + '" target="_blank" rel="noopener" style="display:block;position:relative;border-radius:10px;overflow:hidden;padding-top:100%;background:#EEF2F6 center/cover no-repeat;background-image:url(' + esc(p.thumb || p.url) + ');text-decoration:none;" title="' + esc((p.caption || '') + ' · ' + (p.time || '')) + '">'
            + (isVid ? '<span style="position:absolute;inset:0;display:flex;align-items:center;justify-content:center;font-size:24px;color:#fff;text-shadow:0 1px 4px rgba(0,0,0,.6);">▶</span>' : '')
            + '<span style="position:absolute;bottom:0;left:0;right:0;background:linear-gradient(transparent,rgba(0,0,0,.55));color:#fff;font-size:9.5px;padding:10px 5px 3px;">' + esc(p.time || '') + '</span></a>';
        }).join('') + '</div>'
      : '<div style="color:#94A3B8;font-size:12.5px;padding:8px 0;">No photos or videos captured on this day.</div>';

    // Educator ↔ parent chat log
    var msgs = d.chat || [];
    var chatHtml = msgs.length
      ? '<div style="max-height:360px;overflow:auto;">'
        + msgs.map(function (m) {
          var p = m.is_parent;
          var bg = p ? '#EAF3F6' : '#1F6080', fg = p ? '#0C6070' : '#fff', al = p ? 'flex-start' : 'flex-end';
          return '<div style="display:flex;justify-content:' + al + ';margin-bottom:8px;">'
            + '<div style="max-width:80%;background:' + bg + ';color:' + fg + ';border-radius:12px;padding:8px 12px;">'
            + '<div style="font-size:10px;font-weight:800;opacity:.72;margin-bottom:2px;">' + esc(m.from) + (p ? ' · parent' : ' · staff') + ' · ' + esc(m.time || '') + '</div>'
            + '<div style="font-size:13px;line-height:1.45;">' + esc(m.body || '') + '</div></div></div>';
        }).join('') + '</div>'
      : '<div style="color:#94A3B8;font-size:12.5px;padding:8px 0;">No messages between educators and parents on this day.</div>';

    // Observations recorded this day (learning stories / HDLH notes)
    var obsList = d.observations || [];
    var obsHtml = obsList.length
      ? '<div style="max-height:420px;overflow:auto;display:flex;flex-direction:column;gap:10px;">'
        + obsList.map(function (o) {
          var chips = [o.framework, o.domain].filter(Boolean).map(function (t) {
            return '<span style="display:inline-block;font-size:10px;font-weight:800;letter-spacing:.3px;text-transform:uppercase;color:#6D28D9;background:#F3F0FF;border:1px solid rgba(124,58,237,.16);border-radius:999px;padding:2px 9px;">' + esc(t) + '</span>';
          }).join(' ');
          var shareChip = o.shared
            ? '<span style="font-size:10px;font-weight:800;color:#0F9D6B;background:#E7F8F0;border-radius:999px;padding:2px 9px;">Shared with family</span>'
            : '<span style="font-size:10px;font-weight:800;color:#94A3B8;background:#F1F5F9;border-radius:999px;padding:2px 9px;">Not shared</span>';
          return '<div style="border:1px solid #EEF0F4;border-left:3px solid #7C3AED;border-radius:10px;padding:11px 13px;background:#FCFCFF;">'
            + '<div style="display:flex;justify-content:space-between;gap:10px;align-items:baseline;flex-wrap:wrap;">'
            +   '<b style="font-size:13.5px;color:#0D1B2A;">' + esc(o.child || '') + (o.title ? ' — <span style="font-weight:700;color:#334155;">' + esc(o.title) + '</span>' : '') + '</b>'
            +   '<span style="font-size:11px;color:#94A3B8;white-space:nowrap;">' + esc(o.time || '') + (o.educator ? ' · ' + esc(o.educator) : '') + '</span>'
            + '</div>'
            + (o.body ? '<div style="font-size:12.5px;color:#475569;line-height:1.5;margin-top:5px;white-space:pre-wrap;">' + esc(o.body) + '</div>' : '')
            + '<div style="margin-top:7px;display:flex;gap:5px;flex-wrap:wrap;">' + chips + shareChip + '</div>'
            + '</div>';
        }).join('') + '</div>'
      : '<div style="color:#94A3B8;font-size:12.5px;padding:8px 0;">No observations recorded on this day.</div>';

    // Walks & outings on this day — with live map for active ones.
    var _wd = function (m) { if (m == null) return '—'; return m >= 1000 ? (m / 1000).toFixed(2) + ' km' : Math.round(m) + ' m'; };
    var _ws = function (n) { if (n == null) return '—'; return n >= 1000 ? (n / 1000).toFixed(1) + 'k' : String(n); };
    var _wt = function (m) { if (!m) return '—'; return m < 60 ? m + ' min' : Math.floor(m / 60) + 'h ' + (m % 60) + 'm'; };
    var walks = d.walks || [];
    var walksHtml = walks.length
      ? walks.map(function (w) {
          var live = w.status === 'active';
          var stat = live
            ? '<span style="font-size:10.5px;font-weight:800;color:#065F46;background:#D1FAE5;border-radius:999px;padding:2px 9px;">● LIVE</span>'
            : '<span style="font-size:10.5px;font-weight:800;color:#475569;background:#F1F5F9;border-radius:999px;padding:2px 9px;">Ended</span>';
          return '<div style="border:1px solid #E5E7EB;border-radius:12px;padding:12px 14px;background:#fff;">'
            + '<div style="display:flex;gap:12px;align-items:flex-start;flex-wrap:wrap;">'
            +   '<div style="flex:1;min-width:0;">'
            +     '<div style="font-weight:800;color:#0F172A;">🚶 ' + esc(w.title || 'Walk') + ' &nbsp;' + stat + '</div>'
            +     '<div style="font-size:12.5px;color:#64748B;margin-top:2px;">' + esc(w.destination || '') + ' · ' + esc(w.lead) + ' · ' + w.children + ' child' + (w.children === 1 ? '' : 'ren') + (w.depart ? ' · out ' + esc(w.depart) + (w.return ? '–' + esc(w.return) : '') : '') + '</div>'
            +     '<div style="font-size:12px;color:#334155;margin-top:4px;font-weight:700;">' + _wd(w.distance_m) + ' · ' + _ws(w.steps_est) + ' steps · ' + _wt(w.duration_min) + '</div>'
            +   '</div>'
            +   (w.has_location ? '<button class="kt-walk-map-btn" data-id="' + w.id + '" data-t="' + esc(w.title || 'Walk') + '" style="background:#EFF6FF;color:#1E40AF;border:1px solid #BFDBFE;border-radius:10px;padding:9px 13px;font-weight:800;font-size:12.5px;cursor:pointer;white-space:nowrap;">📍 ' + (live ? 'Live map' : 'View map') + '</button>' : '<span style="font-size:11.5px;color:#94A3B8;align-self:center;">No location shared</span>')
            + '</div>'
            + (live && w.has_location ? '<div class="kt-walk-inline" data-id="' + w.id + '" style="height:240px;margin-top:10px;border-radius:12px;overflow:hidden;border:1px solid #E2E8F0;background:#EAF2F8;"></div>' : '')
            // Finished: keep the route visible. The same rendered PNG the parent is
            // emailed, so staff and families are looking at exactly the same picture.
            + (!live && w.map_url ? '<img src="' + esc(w.map_url) + '" alt="Route walked" loading="lazy" style="display:block;width:100%;max-width:600px;height:auto;margin-top:10px;border-radius:12px;border:1px solid #E2E8F0;">' : '')
            + '</div>';
        }).join('<div style="height:10px;"></div>')
      : '<div style="color:#94A3B8;font-size:12.5px;padding:8px 0;">No walks or outings logged on this day.</div>';

    // Provider identity header — the provider's own photo (matched by email) + name,
    // so the overview clearly shows WHOSE day this is (not just a centre name).
    var _pc = d.centre || {};
    var provHeader = '<div style="display:flex;align-items:center;gap:13px;margin-bottom:16px;padding:12px 15px;background:linear-gradient(135deg,#F0FAFC,#F8FAFC);border:1px solid #DCEDF1;border-radius:13px;">'
      + avatar(_pc.name, _pc.provider_photo_url, null, 50, false)
      + '<div><div style="font-size:17px;font-weight:800;color:#0D1B2A;line-height:1.2;">' + esc(_pc.name || 'Provider') + '</div>'
      + '<div style="font-size:12px;color:#64748B;margin-top:2px;">Daily overview · ' + esc(d.date || state.date) + '</div></div></div>';

    body.innerHTML =
      provHeader
      + '<div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(120px,1fr));gap:11px;margin-bottom:18px;">' + cards + '</div>'
      + (function () {
          // Above the record of the day, because "what were they meant to be doing" is
          // the question a director is holding while reading everything below it.
          var lp = d.lesson_plan;
          if (!lp || !(lp.items || []).length) { return ''; }
          var DOMAIN = {
            social_emotional: 'Social & emotional', physical: 'Physical',
            language_literacy: 'Language & literacy', cognitive: 'Cognitive',
            creative_arts: 'Creative arts', self_care: 'Self-care', outdoor: 'Outdoor',
          };
          var rows = lp.items.map(function (a) {
            var meta = [a.time_label || a.time || '', DOMAIN[a.domain] || ''].filter(Boolean).join(' · ');
            return '<div style="padding:7px 0;border-top:1px solid #F1F5F9;">'
              + '<div style="font-size:14px;font-weight:700;color:#0F172A;">' + esc(a.title) + '</div>'
              + (meta ? '<div style="font-size:12px;color:#64748B;margin-top:1px;">' + esc(meta) + '</div>' : '')
              + (a.notes ? '<div style="font-size:13px;color:#475569;margin-top:3px;line-height:1.5;">' + esc(a.notes) + '</div>' : '')
              + '</div>';
          }).join('');
          var head = (lp.theme ? '<div style="font-size:14px;font-weight:800;color:#0F172A;margin-bottom:2px;">' + esc(lp.theme) + '</div>' : '')
            + (lp.room_name ? '<div style="font-size:12px;color:#64748B;margin-bottom:4px;">' + esc(lp.room_name) + '</div>' : '');
          return '<div style="margin-bottom:16px;">' + card('📚 Planned for this day', head + rows) + '</div>';
        })()
      + '<div style="margin-bottom:16px;">' + card('🚶 Walks &amp; outings', walksHtml) + '</div>'
      + '<div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-bottom:16px;">'
      +   card('📊 Provider performance', perfMetrics + pie(an.breakdown || {}))
      +   card('Check-in method', donut((d.qr_vs_manual || {}).qr || 0, (d.qr_vs_manual || {}).manual || 0) + '<div style="height:14px;"></div>' + attBar(s))
      + '</div>'
      + '<div style="display:grid;grid-template-columns:1.4fr 1fr;gap:16px;margin-bottom:16px;">'
      +   card('Children — clock in / out &amp; care', rosterTable)
      +   card('Staff on the floor — clock in / out', staffCard)
      + '</div>'
      + '<div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-bottom:16px;">'
      +   card('📸 Photos &amp; videos captured', photosHtml)
      +   card('💬 Educator ↔ parent chat', chatHtml)
      + '</div>'
      + '<div style="margin-bottom:16px;">' + card('👀 Observations recorded', obsHtml) + '</div>'
      + '<div style="margin-bottom:16px;">' + card('This week’s meal plan', menuGrid) + '</div>'
      + card('Activities &amp; daily logs — ' + esc(d.date), feed)
      + '<div style="height:24px;"></div>';

    // Child filter for the Activities & daily logs feed.
    var _cf = body.querySelector('#pd-child-filter');
    if (_cf) {
      var _applyFilter = function () {
        var v = _cf.value, shown = 0, rows = body.querySelectorAll('.pd-feed-row');
        rows.forEach(function (r) { var ok = !v || r.getAttribute('data-child') === v; r.style.display = ok ? '' : 'none'; if (ok) shown++; });
        var cnt = body.querySelector('#pd-feed-count'); if (cnt) cnt.textContent = v ? (shown + ' of ' + rows.length + ' entries') : '';
      };
      _cf.addEventListener('change', _applyFilter);
    }

    // Walk map buttons + inline live maps for active walks.
    body.querySelectorAll('.kt-walk-map-btn').forEach(function (b) {
      b.onclick = function () { if (window.KT && KT.WalkTracker && KT.WalkTracker.openMap) KT.WalkTracker.openMap(+b.getAttribute('data-id'), b.getAttribute('data-t')); };
    });
    body.querySelectorAll('.kt-walk-inline').forEach(function (el) {
      try { if (window.KT && KT.WalkTracker && KT.WalkTracker.mountLiveMap) KT.WalkTracker.mountLiveMap(el, +el.getAttribute('data-id')); } catch (e) {}
    });

    // responsive: stack 2-col grids on narrow screens
    if (window.innerWidth < 720) {
      body.querySelectorAll('[style*="grid-template-columns:1fr 1fr"],[style*="grid-template-columns:1.4fr 1fr"]').forEach(function (g) { g.style.gridTemplateColumns = '1fr'; });
    }
  }

  if (Shell && Shell.registerScreen) {
    ['agency_admin', 'centre_director', 'platform_admin'].forEach(function (r) { Shell.registerScreen(r + ':provider-day', render); });
  }
  KT.ProviderDayScreen = { render: render };
})(window);
