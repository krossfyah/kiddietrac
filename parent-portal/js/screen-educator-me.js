/* ═══════════════════════════════════════════════════════════════════
   KiddieTrac — educator self-service screens (2026-07-13).

   Educators had no access to their own hours or schedule, and no way to look
   up a child's record (allergies, who may collect them, who to call). The
   existing payroll/schedule screens are director-only and centre-wide — they
   list EVERY staff member — so these are new, self-scoped screens on top of
   the new /provider/shifts/me + /provider/children/{child} endpoints.

   "My hours" is hours, not money: the schema has no pay-rate column anywhere,
   so we show worked hours (from the time clock) against scheduled hours.

   Also hosts the tile launcher on the educator dashboard: the bottom bar no
   longer has a Menu button, so the dashboard carries the full tile grid.
   ═══════════════════════════════════════════════════════════════════ */
(function (window) {
  'use strict';
  var KT = window.KT;
  if (!KT || !KT.Shell || !KT.Shell.registerScreen) return;
  var Api = KT.Api, Shell = KT.Shell;

  var TEAL = '#159FB4';
  var CARD = 'background:#fff;border-radius:16px;box-shadow:0 1px 6px rgba(15,23,42,.06);padding:16px;margin-bottom:14px;';


  // Male / Female on the record — staff need it (nappy changes, toileting,
  // and it's on every regulator's enrolment form), and it was simply missing.
  function genderLabel(g) {
    if (!g) return '';
    var k = String(g).toLowerCase();
    if (k === 'm' || k === 'male') return 'Male';
    if (k === 'f' || k === 'female') return 'Female';
    if (k === 'x' || k === 'other' || k === 'nonbinary' || k === 'non_binary') return 'Other';
    // Stored values are words joined by "_" (prefer_not_to_say): "Prefer not to say", not "Prefer_not_to_say".
    var w = String(g).replace(/_/g, ' ');
    return w.charAt(0).toUpperCase() + w.slice(1);
  }
  /* Allergies, health alerts and dietary needs are stored as lists ([{allergen, severity}], or plain strings) -
     shown as words, never as the raw ["Dairy"] (2026-11-04, the educator's child record). */
  function healthText(raw, key) {
    if (raw == null || raw === '') return '';
    var arr = raw;
    if (typeof raw === 'string') {
      var t = raw.trim();
      if (!t) return '';
      if (t.charAt(0) !== '[' && t.charAt(0) !== '{') return t;
      try { arr = JSON.parse(t); } catch (e) { return t; }
    }
    if (!Array.isArray(arr)) arr = [arr];
    return arr.map(function (x) {
      if (x == null) return '';
      if (typeof x !== 'object') return String(x).trim();
      var n = String(x[key] || x.name || x.label || x.allergen || x.restriction || '').trim();
      return n ? n + (x.severity ? ' (' + String(x.severity).replace(/_/g, ' ') + ')' : '') : '';
    }).filter(Boolean).join(', ');
  }

  function esc(s) {
    return String(s == null ? '' : s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
  }
  function h(html) { var d = document.createElement('div'); d.innerHTML = html; return d.firstElementChild; }
  function apiBase() { return (window.KT_CONFIG && window.KT_CONFIG.apiBase) || 'https://api.kiddietrac.com/api/v1'; }
  function absUrl(u) { if (!u) return ''; if (/^https?:\/\//.test(u)) return u; return apiBase().replace(/\/api\/v1\/?$/, '') + (u.charAt(0) === '/' ? u : '/' + u); }
  function hero(title, sub) {
    // .kt-hero suppresses the shell's auto-banner (it checks for this class).
    return '<div class="kt-hero"><h1>' + esc(title) + '</h1><div class="kt-hero-sub">' + esc(sub || '') + '</div></div>';
  }
  // Punches and shifts come back as UTC with no marker — parse as UTC and show in
  // the agency's timezone, or an educator sees their shift hours out.
  function ktParse(iso) {
    if (window.KT && KT.parseTs) return KT.parseTs(iso);
    var v = String(iso || '').trim();
    return new Date(/(Z|[+-]\d{2}:?\d{2})$/.test(v) ? v.replace(' ', 'T') : v.replace(' ', 'T') + 'Z');
  }
  function ktTz() { return (window.KT && KT.tz) ? KT.tz() : 'America/Toronto'; }
  function fmtTime(iso) {
    if (!iso) return '—';
    var d = ktParse(iso);
    return isNaN(d) ? '—' : d.toLocaleTimeString([], { timeZone: ktTz(), hour: 'numeric', minute: '2-digit' });
  }
  function fmtDay(iso) {
    if (!iso) return '';
    var d = ktParse(iso);
    return isNaN(d) ? '' : d.toLocaleDateString([], { timeZone: ktTz(), weekday: 'short', month: 'short', day: 'numeric' });
  }
  function hoursBetween(a, b) {
    if (!a || !b) return 0;
    var s = ktParse(a), e = ktParse(b);
    if (isNaN(s) || isNaN(e)) return 0;
    return Math.max(0, (e - s) / 36e5);
  }
  function fmtHours(n) {
    var hrs = Math.floor(n), mins = Math.round((n - hrs) * 60);
    if (mins === 60) { hrs++; mins = 0; }
    return hrs + 'h' + (mins ? ' ' + mins + 'm' : '');
  }

  // ── My hours (the "payroll" view) ───────────────────────────────────
  async function renderMyHours(main) {
    main.innerHTML = hero('My hours', 'Your clocked time and scheduled shifts')
      + '<div id="eh-body"><div style="padding:24px;text-align:center;color:#64748B;">Loading…</div></div>';
    var body = main.querySelector('#eh-body');

    var punches = [], shifts = [];
    try { var p = await Api.get('/staff/punches/me'); punches = (p && p.punches) || []; } catch (e) {}
    try { var s = await Api.get('/provider/shifts/me'); shifts = (s && s.shifts) || []; } catch (e) {}

    // Group closed punches by day. An open punch (no punch-out) is "in progress"
    // and shouldn't be counted as worked time yet.
    var byDay = {}, total = 0, open = null;
    punches.forEach(function (p) {
      if (!p.punched_out_at) { open = p; return; }
      // The AGENCY's day, not the UTC date: an evening shift is that day, not tomorrow (2026-11-05).
      var day = agencyKeyOf(p.punched_in_at) || String(p.punched_in_at).slice(0, 10);
      var hrs = hoursBetween(p.punched_in_at, p.punched_out_at);
      if (!byDay[day]) byDay[day] = { day: day, hours: 0, rows: [] };
      byDay[day].hours += hrs;
      byDay[day].rows.push(p);
      total += hrs;
    });
    var days = Object.keys(byDay).sort().reverse();

    // "This period" = the current calendar month, which is how the director's
    // payroll export is bucketed too.
    var monthKey = (agencyKeyOf(new Date().toISOString()) || new Date().toISOString()).slice(0, 7);
    var monthHours = days.filter(function (d) { return d.slice(0, 7) === monthKey; })
      .reduce(function (a, d) { return a + byDay[d].hours; }, 0);

    var scheduled = shifts.reduce(function (a, s) { return a + (s.hours || 0); }, 0);

    var html = '';
    html += '<div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:14px;">'
      + '<div style="' + CARD + 'margin:0;text-align:center;"><div style="font-size:11px;font-weight:800;color:#64748B;letter-spacing:.6px;">THIS MONTH</div>'
      + '<div style="font-size:26px;font-weight:800;color:#0F172A;margin-top:4px;">' + fmtHours(monthHours) + '</div>'
      + '<div style="font-size:11.5px;color:#64748B;">worked</div></div>'
      + '<div style="' + CARD + 'margin:0;text-align:center;"><div style="font-size:11px;font-weight:800;color:#64748B;letter-spacing:.6px;">SCHEDULED</div>'
      + '<div style="font-size:26px;font-weight:800;color:' + TEAL + ';margin-top:4px;">' + fmtHours(scheduled) + '</div>'
      + '<div style="font-size:11.5px;color:#64748B;">next 4 weeks</div></div>'
      + '</div>';

    if (open) {
      html += '<div style="' + CARD + '--kt-edge:#16A34A;">'
        + '<div style="font-weight:800;color:#16A34A;font-size:14px;">⏱ Clocked in now</div>'
        + '<div style="font-size:12.5px;color:#64748B;margin-top:3px;">Since ' + fmtTime(open.punched_in_at) + ' · ' + fmtDay(open.punched_in_at) + '</div>'
        + '<a href="#time-clock" style="display:inline-block;margin-top:10px;background:' + TEAL + ';color:#fff;border-radius:9px;padding:8px 14px;font-size:13px;font-weight:800;text-decoration:none;">Go to the time clock</a>'
        + '</div>';
    }

    html += '<div style="' + CARD + '">';
    html += '<div style="font-weight:800;font-size:15px;color:#0F172A;margin-bottom:10px;">Recent days</div>';
    if (!days.length) {
      html += '<div style="color:#64748B;font-size:13.5px;padding:8px 0;">No completed shifts on the clock yet. Hours appear here once you clock out.</div>';
    } else {
      days.slice(0, 30).forEach(function (d) {
        var e = byDay[d];
        html += '<div style="display:flex;justify-content:space-between;align-items:center;padding:10px 0;border-bottom:1px solid #F1F5F9;">'
          + '<div><div style="font-weight:700;font-size:13.5px;color:#0F172A;">' + esc(fmtDay(d)) + '</div>'
          + '<div style="font-size:11.5px;color:#64748B;">' + e.rows.slice().reverse().map(function (r) { return fmtTime(r.punched_in_at) + '–' + fmtTime(r.punched_out_at) + (r.out_reason === 'lunch' ? ' (lunch)' : (r.out_reason === 'break' ? ' (break)' : '')); }).join(', ') + '</div></div>'
          + '<div style="font-weight:800;font-size:14px;color:' + TEAL + ';">' + fmtHours(e.hours) + '</div>'
          + '</div>';
      });
      html += '<div style="display:flex;justify-content:space-between;padding-top:10px;font-weight:800;font-size:14px;color:#0F172A;">'
        + '<span>Total on record</span><span>' + fmtHours(total) + '</span></div>';
    }
    html += '</div>';

    html += '<div style="font-size:11.5px;color:#64748B;text-align:center;padding:0 12px 8px;line-height:1.5;">'
      + 'Hours are taken from the time clock. Pay is worked out by your centre — talk to your director if something looks wrong.</div>';

    body.innerHTML = html;
  }

  // ── My calendar ─────────────────────────────────────────────────────
  /* REDESIGN (2026-10-28, Anthony: "for educators and my calendar make this look better - full sweep"). It was a
     list of upcoming shifts under a generic banner, and an educator could not see that the centre was closed next
     Friday, or their own approved day off, on it. Now a real calendar from GET /provider/calendar/me: their shifts
     (room colours), their time off, closures and birthdays for their centres - a month grid with the selected
     day's detail beside it (below it on a phone), a "next shift" card and the month's figures. */
  var CAL = { cursor: null, sel: null, data: null };
  function calKey(d) { return d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0'); }
  function agencyKeyOf(iso) {
    // The agency's calendar day a UTC timestamp falls on (a 7 pm shift is that day, not tomorrow in UTC).
    var d = ktParse(iso); if (isNaN(d)) { return ''; }
    try { return new Intl.DateTimeFormat('en-CA', { timeZone: ktTz(), year: 'numeric', month: '2-digit', day: '2-digit' }).format(d); } catch (e) { return String(iso).slice(0, 10); }
  }
  function ensureCalCss() {
    if (document.getElementById('kt-mycal-css')) { return; }
    var s = document.createElement('style'); s.id = 'kt-mycal-css';
    s.textContent =
      '.mc-top{display:grid;grid-template-columns:1.3fr 1fr 1fr 1fr;gap:12px;margin-bottom:14px;}' +
      '.mc-next{position:relative;overflow:hidden;border-radius:18px;padding:16px 18px;color:#fff;background:linear-gradient(135deg,#0E7490 0%,#159FB4 45%,#6366F1 120%);box-shadow:0 16px 32px -18px rgba(14,116,144,.8);}' +
      '.mc-next:after{content:"📅";position:absolute;right:14px;bottom:4px;font-size:58px;opacity:.18;}' +
      '.mc-next .l{font-size:11px;font-weight:800;letter-spacing:.6px;text-transform:uppercase;opacity:.85;}' +
      '.mc-next .d{font-size:22px;font-weight:900;margin-top:4px;line-height:1.15;}.mc-next .t{font-size:13.5px;opacity:.95;margin-top:3px;}' +
      '.mc-stat{background:#fff;border:1px solid #E2E8F0;border-radius:16px;padding:13px 15px;min-width:0;}' +
      '.mc-stat .l{font-size:10.5px;font-weight:800;letter-spacing:.5px;text-transform:uppercase;color:#64748B;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}' +
      '.mc-stat .v{font-size:24px;font-weight:900;color:#0F172A;margin-top:6px;}.mc-stat .s{font-size:11.5px;color:#64748B;margin-top:2px;}' +
      '.mc-wrap{display:grid;grid-template-columns:minmax(0,1fr) 320px;gap:14px;align-items:start;}' +
      '.mc-cal{background:#fff;border:1px solid #E2E8F0;border-radius:18px;padding:14px;min-width:0;}' +
      '.mc-bar{display:flex;align-items:center;gap:8px;flex-wrap:wrap;margin-bottom:10px;}' +
      '.mc-bar h2{margin:0 auto 0 4px;font-size:18px;color:#0F172A;}' +
      '.mc-btn{height:32px;min-width:32px;padding:0 10px;border-radius:9px;border:1px solid #CBD5E1;background:#fff;color:#1F2937;font-weight:700;font-size:13px;cursor:pointer;font-family:inherit;}' +
      '.mc-btn.pri{background:' + TEAL + ';border-color:' + TEAL + ';color:#fff;}' +
      '.mc-grid{display:grid;grid-template-columns:repeat(7,minmax(0,1fr));gap:4px;}' +
      '.mc-dow{font-size:10.5px;font-weight:800;color:#94A3B8;text-align:center;text-transform:uppercase;letter-spacing:.5px;padding:2px 0 4px;}' +
      '.mc-day{min-height:92px;border:1px solid #EEF2F7;border-radius:10px;padding:5px;cursor:pointer;background:#fff;display:flex;flex-direction:column;gap:3px;min-width:0;text-align:left;font-family:inherit;}' +
      '.mc-day:hover{border-color:#B6E3EA;}.mc-day.out{background:#F8FAFC;opacity:.6;}.mc-day.closed{background:#FEF2F2;border-color:#FECACA;}' +
      '.mc-day.sel{box-shadow:0 0 0 2px ' + TEAL + ';border-color:' + TEAL + ';}' +
      '.mc-num{font-size:12px;font-weight:800;color:#334155;display:flex;align-items:center;justify-content:space-between;}' +
      '.mc-day.today .mc-num b{background:' + TEAL + ';color:#fff;border-radius:999px;min-width:22px;height:22px;display:inline-flex;align-items:center;justify-content:center;padding:0 5px;}' +
      '.mc-chip{font-size:10.5px;font-weight:700;border-radius:6px;padding:2px 5px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;line-height:1.35;}' +
      '.mc-dots{display:none;gap:3px;flex-wrap:wrap;margin-top:auto;}.mc-dots i{width:6px;height:6px;border-radius:50%;display:inline-block;}' +
      '.mc-side{background:#fff;border:1px solid #E2E8F0;border-radius:18px;padding:14px 16px;min-width:0;}' +
      '.mc-side h3{margin:0 0 2px;font-size:15px;color:#0F172A;}.mc-item{display:flex;gap:10px;align-items:flex-start;padding:9px 0;border-top:1px solid #F1F5F9;}' +
      '.mc-item:first-of-type{border-top:0;}.mc-ic{width:30px;height:30px;border-radius:9px;display:flex;align-items:center;justify-content:center;font-size:15px;flex:0 0 auto;}' +
      '.mc-legend{display:flex;gap:12px;flex-wrap:wrap;font-size:11.5px;color:#64748B;margin-top:10px;}.mc-legend span{display:inline-flex;align-items:center;gap:5px;}' +
      '.mc-legend i{width:9px;height:9px;border-radius:3px;display:inline-block;}' +
      '@media(max-width:1000px){.mc-top{grid-template-columns:1fr 1fr;}.mc-wrap{grid-template-columns:1fr;}}' +
      '@media(max-width:600px){.mc-day{min-height:52px;padding:4px;}.mc-chip{display:none;}.mc-dots{display:flex;}.mc-cal{padding:10px;}' +
        '.mc-stat .v{font-size:20px;}.mc-next .d{font-size:19px;}.mc-bar h2{font-size:16px;}}';
    document.head.appendChild(s);
  }

  async function renderMySchedule(main) {
    ensureCalCss();
    if (!CAL.cursor) { var t0 = (window.KT && KT.agencyToday) ? KT.agencyToday() : calKey(new Date()); CAL.cursor = new Date(+t0.slice(0, 4), +t0.slice(5, 7) - 1, 1); CAL.sel = t0; }
    main.innerHTML = hero('My calendar', 'Your shifts, days off, closures and birthdays')
      + '<div id="es-body"><div style="padding:24px;text-align:center;color:#64748B;">Loading…</div></div>';
    var body = main.querySelector('#es-body');
    var today = (window.KT && KT.agencyToday) ? KT.agencyToday() : calKey(new Date());
    var y = CAL.cursor.getFullYear(), m = CAL.cursor.getMonth();
    var first = new Date(y, m, 1), gridStart = new Date(y, m, 1 - first.getDay());
    var gridEnd = new Date(gridStart); gridEnd.setDate(gridStart.getDate() + 41);
    // Fetch from today when that is earlier, so "next shift" and this week's hours are always right.
    var from = calKey(gridStart) < today ? calKey(gridStart) : today;
    var to = calKey(gridEnd);
    var next30 = new Date(+today.slice(0, 4), +today.slice(5, 7) - 1, +today.slice(8, 10) + 45);
    if (calKey(next30) > to) { to = calKey(next30); }

    var data;
    try { data = await Api.get('/provider/calendar/me?from=' + from + '&to=' + to); }
    catch (e) {
      body.innerHTML = '<div style="' + CARD + 'text-align:center;color:#B91C1C;">Could not load your calendar.<div style="font-size:12px;color:#64748B;margin-top:6px;">'
        + esc((e && e.message) || 'The server did not respond.') + '</div><button type="button" class="kt-retry mc-btn pri" style="margin-top:12px;">Try again</button></div>';
      var rb = body.querySelector('.kt-retry'); if (rb) { rb.onclick = function () { renderMySchedule(main); }; }
      return;
    }

    // Index everything by agency day.
    var byDay = {};
    function slot(k) { return byDay[k] = byDay[k] || { shifts: [], events: [] }; }
    (data.shifts || []).forEach(function (s) { var k = agencyKeyOf(s.starts_at); if (k) { slot(k).shifts.push(s); } });
    (data.events || []).forEach(function (ev) { slot(ev.date).events.push(ev); });

    // ── figures ──
    var wk = new Date(+today.slice(0, 4), +today.slice(5, 7) - 1, +today.slice(8, 10)); wk.setDate(wk.getDate() - wk.getDay());
    var wkStart = calKey(wk), wkEnd = calKey(new Date(wk.getFullYear(), wk.getMonth(), wk.getDate() + 6));
    var monthKey = y + '-' + String(m + 1).padStart(2, '0');
    var weekHours = 0, monthShifts = 0, monthOff = 0, monthClosed = 0, next = null;
    Object.keys(byDay).sort().forEach(function (k) {
      var d = byDay[k];
      d.shifts.forEach(function (s) {
        if (k >= wkStart && k <= wkEnd) { weekHours += hoursBetween(s.starts_at, s.ends_at); }
        if (k.slice(0, 7) === monthKey) { monthShifts++; }
        var endAt = ktParse(s.ends_at || s.starts_at);
        if (!next && !isNaN(endAt) && endAt > new Date()) { next = { k: k, s: s }; }
      });
      if (k.slice(0, 7) === monthKey) {
        if (d.events.some(function (e) { return e.kind === 'timeoff' && e.tone !== 'pending'; })) { monthOff++; }
        if (d.events.some(function (e) { return e.kind === 'closure'; })) { monthClosed++; }
      }
    });

    var html = '<div class="mc-top" data-kt-nosechead="1">'
      + '<div class="mc-next"><div class="l">' + (next ? (next.k === today ? 'Your shift today' : 'Your next shift') : 'Next shift') + '</div>'
      + (next ? '<div class="d">' + esc(fmtDay(next.s.starts_at)) + '</div><div class="t">' + esc(fmtTime(next.s.starts_at)) + ' – ' + esc(fmtTime(next.s.ends_at))
          + (next.s.room_name ? ' · ' + esc(next.s.room_name) : '') + '</div>'
        : '<div class="d">Nothing scheduled</div><div class="t">Your director has not scheduled you in the next few weeks.</div>')
      + '</div>'
      + '<div class="mc-stat"><div class="l">This week</div><div class="v">' + fmtHours(weekHours) + '</div><div class="s">scheduled</div></div>'
      + '<div class="mc-stat"><div class="l">' + esc(CAL.cursor.toLocaleDateString(undefined, { month: 'long' })) + ' shifts</div><div class="v">' + monthShifts + '</div><div class="s">in this month</div></div>'
      + '<div class="mc-stat"><div class="l">Days off · closures</div><div class="v">' + monthOff + ' · ' + monthClosed + '</div><div class="s">this month</div></div>'
      + '</div>';

    html += '<div class="mc-wrap"><div class="mc-cal" data-kt-nosechead="1"><div class="mc-bar">'
      + '<h2>' + esc(CAL.cursor.toLocaleDateString(undefined, { month: 'long', year: 'numeric' })) + '</h2>'
      + '<button type="button" class="mc-btn" data-mc="prev" aria-label="Previous month">‹</button>'
      + '<button type="button" class="mc-btn" data-mc="today">Today</button>'
      + '<button type="button" class="mc-btn" data-mc="next" aria-label="Next month">›</button>'
      + '<a class="mc-btn pri" href="#time-off" style="display:inline-flex;align-items:center;text-decoration:none;">🌴 Request time off</a></div>'
      + '<div class="mc-grid">' + ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'].map(function (n) { return '<div class="mc-dow">' + n + '</div>'; }).join('');
    for (var i = 0; i < 42; i++) {
      var dd = new Date(gridStart.getFullYear(), gridStart.getMonth(), gridStart.getDate() + i), k = calKey(dd), d = byDay[k] || { shifts: [], events: [] };
      var closed = d.events.some(function (e) { return e.kind === 'closure'; });
      var cls = 'mc-day' + (dd.getMonth() !== m ? ' out' : '') + (k === today ? ' today' : '') + (k === CAL.sel ? ' sel' : '') + (closed ? ' closed' : '');
      var chips = '', dots = '';
      d.events.filter(function (e) { return e.kind === 'closure'; }).slice(0, 1).forEach(function () { chips += '<div class="mc-chip" style="background:#FEE2E2;color:#B91C1C;">🚫 Closed</div>'; dots += '<i style="background:#EF4444"></i>'; });
      d.shifts.slice(0, 2).forEach(function (s) {
        var c = s.room_colour || TEAL;
        chips += '<div class="mc-chip" style="background:' + esc(c) + '22;color:#0F172A;--kt-edge:' + esc(c) + ';">' + esc(fmtTime(s.starts_at)) + (s.room_name ? ' ' + esc(s.room_name) : '') + '</div>';
        dots += '<i style="background:' + esc(c) + '"></i>';
      });
      d.events.filter(function (e) { return e.kind === 'timeoff'; }).slice(0, 1).forEach(function (e) { chips += '<div class="mc-chip" style="background:#ECFDF5;color:#047857;">🌴 ' + esc(e.title) + '</div>'; dots += '<i style="background:#10B981"></i>'; });
      var bd = d.events.filter(function (e) { return e.kind === 'birthday'; });
      if (bd.length) { chips += '<div class="mc-chip" style="background:#FDF2F8;color:#BE185D;">🎂 ' + (bd.length > 1 ? bd.length + ' birthdays' : esc(String(bd[0].title).split(' ')[0])) + '</div>'; dots += '<i style="background:#EC4899"></i>'; }
      var more = d.shifts.length - 2; if (more > 0) { chips += '<div style="font-size:10px;color:#64748B;font-weight:700;">+' + more + ' more</div>'; }
      html += '<button type="button" class="' + cls + '" data-day="' + k + '"><div class="mc-num"><b>' + dd.getDate() + '</b></div>' + chips + '<div class="mc-dots">' + dots + '</div></button>';
    }
    html += '</div><div class="mc-legend"><span><i style="background:' + TEAL + '"></i>Shift</span><span><i style="background:#10B981"></i>Time off</span>'
      + '<span><i style="background:#EF4444"></i>Closed</span><span><i style="background:#EC4899"></i>Birthday</span></div></div>'
      + '<div class="mc-side" data-kt-nosechead="1" id="mc-side"></div></div>';
    body.innerHTML = html;

    function paintSide(k) {
      var d = byDay[k] || { shifts: [], events: [] }, side = body.querySelector('#mc-side');
      var dt = new Date(+k.slice(0, 4), +k.slice(5, 7) - 1, +k.slice(8, 10));
      var out = '<h3>' + esc(dt.toLocaleDateString(undefined, { weekday: 'long', month: 'long', day: 'numeric' })) + '</h3>'
        + '<div style="font-size:12px;color:#64748B;margin-bottom:8px;">' + (k === today ? 'Today' : (k < today ? 'Past day' : 'Coming up')) + '</div>';
      var n = 0;
      d.events.filter(function (e) { return e.kind === 'closure'; }).forEach(function (e) { n++; out += '<div class="mc-item"><div class="mc-ic" style="background:#FEE2E2;">🚫</div><div><b style="color:#B91C1C;">' + esc(e.title) + '</b><div style="font-size:12px;color:#64748B;">' + esc(e.detail || '') + (e.date_label ? ' · ' + esc(e.date_label) : '') + '</div></div></div>'; });
      d.shifts.forEach(function (s) {
        n++;
        var hrs = hoursBetween(s.starts_at, s.ends_at);
        out += '<div class="mc-item"><div class="mc-ic" style="background:' + esc(s.room_colour || TEAL) + '22;">🧑‍🏫</div><div><b>' + esc(fmtTime(s.starts_at)) + ' – ' + esc(fmtTime(s.ends_at)) + '</b>'
          + '<div style="font-size:12px;color:#64748B;">' + esc([s.room_name, s.centre_name].filter(Boolean).join(' · ') || 'Centre') + (s.role ? ' · ' + esc(s.role) : '') + (hrs ? ' · ' + fmtHours(hrs) : '') + '</div></div></div>';
      });
      d.events.filter(function (e) { return e.kind === 'timeoff'; }).forEach(function (e) { n++; out += '<div class="mc-item"><div class="mc-ic" style="background:#ECFDF5;">🌴</div><div><b>' + esc(e.title) + '</b>' + (e.detail ? '<div style="font-size:12px;color:#64748B;">' + esc(e.detail) + '</div>' : '') + '</div></div>'; });
      d.events.filter(function (e) { return e.kind === 'birthday'; }).forEach(function (e) { n++; out += '<div class="mc-item"><div class="mc-ic" style="background:#FDF2F8;">🎂</div><div><b>' + esc(e.title) + '</b><div style="font-size:12px;color:#64748B;">' + esc(e.detail || 'Birthday') + '</div></div></div>'; });
      if (!n) { out += '<div style="padding:18px 0;text-align:center;color:#94A3B8;font-size:13px;">Nothing on this day.</div>'; }
      side.innerHTML = out;
    }
    var selKey = (CAL.sel && CAL.sel.slice(0, 7) === monthKey) ? CAL.sel : (today.slice(0, 7) === monthKey ? today : monthKey + '-01');
    paintSide(selKey);

    Array.prototype.forEach.call(body.querySelectorAll('[data-day]'), function (b) {
      b.addEventListener('click', function () {
        CAL.sel = b.getAttribute('data-day');
        Array.prototype.forEach.call(body.querySelectorAll('.mc-day.sel'), function (x) { x.classList.remove('sel'); });
        b.classList.add('sel');
        paintSide(CAL.sel);
        if (window.matchMedia('(max-width:1000px)').matches) { var sd = body.querySelector('#mc-side'); if (sd) { sd.scrollIntoView({ behavior: 'smooth', block: 'nearest' }); } }
      });
    });
    body.querySelector('[data-mc="prev"]').addEventListener('click', function () { CAL.cursor = new Date(y, m - 1, 1); renderMySchedule(main); });
    body.querySelector('[data-mc="next"]').addEventListener('click', function () { CAL.cursor = new Date(y, m + 1, 1); renderMySchedule(main); });
    body.querySelector('[data-mc="today"]').addEventListener('click', function () { CAL.cursor = new Date(+today.slice(0, 4), +today.slice(5, 7) - 1, 1); CAL.sel = today; renderMySchedule(main); });
  }

  // ── Child records ───────────────────────────────────────────────────
  async function renderChildren(main) {
    main.innerHTML = hero('Child records', 'Children in your rooms')
      + '<div id="ec-body"><div style="padding:24px;text-align:center;color:#64748B;">Loading…</div></div>';
    var body = main.querySelector('#ec-body');

    var boot;
    try { boot = await Api.get('/provider/bootstrap'); } catch (e) {
      body.innerHTML = '<div style="' + CARD + 'text-align:center;color:#B91C1C;">Could not load your rooms.</div>';
      return;
    }
    var rooms = (boot && boot.rooms) || [];
    if (!rooms.length) {
      body.innerHTML = '<div style="' + CARD + 'text-align:center;color:#64748B;">No rooms are assigned to you yet.</div>';
      return;
    }

    // Pull every room's roster in parallel — an educator usually has 1–4 rooms.
    var rosters = await Promise.all(rooms.map(function (r) {
      return Api.get('/provider/rooms/' + r.id + '/roster')
        .then(function (d) { return { room: r, children: (d && d.roster) || [] }; })
        .catch(function () { return { room: r, children: [] }; });
    }));

    var search = h('<input type="search" placeholder="Search children…" style="width:100%;box-sizing:border-box;padding:11px 14px;font-size:16px;border:1.5px solid #E3EAF1;border-radius:11px;margin-bottom:12px;">');
    body.innerHTML = '';
    body.appendChild(search);
    var list = document.createElement('div');
    body.appendChild(list);

    function paint(q) {
      q = (q || '').trim().toLowerCase();
      list.innerHTML = '';
      var shown = 0;
      rosters.forEach(function (r) {
        var kids = r.children.filter(function (c) {
          return !q || ((c.first_name || '') + ' ' + (c.last_name || '')).toLowerCase().indexOf(q) > -1
            || !!(window.KT && KT.refMatch && KT.refMatch(c.ref_code, q));   // or the child's ID (2026-10-14)
        });
        if (!kids.length) return;
        shown += kids.length;
        var sec = document.createElement('div');
        sec.style.cssText = CARD;
        sec.innerHTML = '<div style="font-weight:800;font-size:14px;color:#0F172A;margin-bottom:6px;">'
          + esc(r.room.name) + ' <span style="color:#64748B;font-weight:600;">· ' + kids.length + '</span></div>';
        kids.forEach(function (c) {
          var row = document.createElement('button');
          row.type = 'button';
          row.style.cssText = 'display:flex;align-items:center;gap:11px;width:100%;text-align:left;background:none;border:none;border-top:1px solid #F1F5F9;padding:10px 0;cursor:pointer;';
          var flags = (c.urgent_flags || []).length;
          row.innerHTML = KT.avatar((c.first_name || '') + ' ' + (c.last_name || ''), { size: 38, photoUrl: c.photo_url ? absUrl(c.photo_url) : '' })
            + '<span style="flex:1;"><span style="display:block;font-weight:700;font-size:14px;color:#0F172A;">' + esc((c.first_name || '') + ' ' + (c.last_name || '')) + '</span>'
            + '<span style="display:block;font-size:11.5px;color:#64748B;">' + esc(c.age_human || '') + (c.is_at_centre ? ' · <span style="color:#16A34A;font-weight:700;">At centre</span>' : '') + '</span></span>'
            + (flags ? '<span style="font-size:15px;">⚠️</span>' : '')
            + '<span style="color:#CBD5E1;font-size:18px;">›</span>';
          row.addEventListener('click', function () { location.hash = '#child-record?id=' + c.id; });
          sec.appendChild(row);
        });
        list.appendChild(sec);
      });
      if (!shown) list.innerHTML = '<div style="' + CARD + 'text-align:center;color:#64748B;">No children match “' + esc(q) + '”.</div>';
    }
    paint('');
    search.addEventListener('input', function () { paint(search.value); });
  }

  // Full-screen child record. Registered with the overlay stack so the Android
  // back button (and the ‹ button) closes it instead of navigating away.
  // Child record renders as a NORMAL in-#appMain screen (hash: #child-record?id=N)
  // so it keeps the app chrome (top bar + bottom nav) and behaves like every other
  // screen. It used to be a position:fixed;inset:0 body overlay that covered the
  // whole app — that read as "opens full screen, not like the other screens".
  /* "09:00" -> "9:00 am". A wall-clock schedule value: no timezone conversion. */
  function t12(hhmm) {
    var m = /^(\d{1,2}):(\d{2})/.exec(String(hhmm || ''));
    if (!m) return '\u2014';
    var h = +m[1], ap = h >= 12 ? 'pm' : 'am';
    h = h % 12; if (!h) h = 12;
    return h + ':' + m[2] + ' ' + ap;
  }

  (function rememberFrom() {
    window.addEventListener('hashchange', function (e) {
      try {
        var to = '#' + (String(e.newURL).split('#')[1] || ''), was = '#' + (String(e.oldURL).split('#')[1] || '');
        if (/^#child-record/.test(to) && !/^#child-record/.test(was)) sessionStorage.setItem('kt_child_record_from', was);
      } catch (er) {}
    });
  })();
  async function renderChildRecordScreen(main) {
    var childId = null;
    try { childId = new URLSearchParams((location.hash.split('?')[1] || '')).get('id'); } catch (e) {}
    /* Coming BACK here can arrive without the ?id= (2026-09-30: Natasha opened child 123,
       went to Settings, came back, and the screen asked the server for child "null" —
       a 500). Remember the last child opened in this tab and use it when the hash lost
       it; with neither, go back to the list rather than ask for nobody. */
    if (/^\d+$/.test(String(childId || ''))) {
      try { sessionStorage.setItem('kt_child_record_id', childId); } catch (e) {}
    } else {
      childId = null;
      try { childId = sessionStorage.getItem('kt_child_record_id'); } catch (e) {}
      if (!/^\d+$/.test(String(childId || ''))) { location.hash = '#children'; return; }
    }
    var ov = document.createElement('div');
    ov.setAttribute('data-kt-no-autohero', '1');   // we render our own compact header below
    ov.innerHTML = '<div style="display:flex;align-items:center;gap:10px;margin:2px 0 12px;">'
      + '<button type="button" data-back style="background:none;border:none;font-size:24px;color:' + TEAL + ';cursor:pointer;padding:0 2px;line-height:1;">‹</button>'
      + '<span style="font-weight:800;font-size:18px;color:#0F172A;">Child record</span></div>'
      + '<div id="cr-body"><div style="text-align:center;color:#64748B;padding:30px;">Loading…</div></div>';
    main.appendChild(ov);
    /* BACK GOES BACK (2026-10-23, Anthony: "clicking back arrow where it shows child record does
       nothing"). history.back() did nothing when the record was the first screen in the tab or
       was opened from a notification. It now goes to the screen it was opened from, else the list. */
    ov.querySelector('[data-back]').addEventListener('click', function (e) {
      e.preventDefault();
      var from = '';
      try { from = sessionStorage.getItem('kt_child_record_from') || ''; } catch (er) {}
      location.hash = (from && !/^#child-record/.test(from)) ? from : '#children';
    });

    var d;
    try { d = await Api.get('/provider/children/' + childId); }
    catch (e) {
      ov.querySelector('#cr-body').innerHTML = '<div style="' + CARD + 'text-align:center;color:#B91C1C;">Could not load this record.</div>';
      return;
    }
    var c = d.child || {};
    var name = ((c.first_name || '') + ' ' + (c.last_name || '')).trim();
    var html = '';

    // Wraps (2026-10-23): on a narrow phone the Emergency card button squeezed the name out of sight.
    html += '<div style="' + CARD + 'display:flex;flex-wrap:wrap;align-items:center;gap:10px 13px;">'
      + '<span style="flex:0 0 auto;">' + KT.avatar(name, { size: 56, photoUrl: c.photo_url ? absUrl(c.photo_url) : '' }) + '</span>'
      + '<div style="flex:1 1 150px;min-width:0;"><div style="font-weight:800;font-size:17px;color:#0F172A;overflow-wrap:anywhere;">' + esc(name || 'Child') + '</div>'
      + '<div style="font-size:12.5px;color:#64748B;">'
      + esc([c.age_human, genderLabel(c.gender), c.room_name, c.pronouns].filter(Boolean).join(' · ') || '—')
      + '</div>'
      /* refA-2026-10-14: reference IDs (KT.refPill, kt-refid.js) */
      + ((c.ref_code && KT.refPillHtml) ? '<div style="margin-top:5px;">' + KT.refPillHtml(c.ref_code, { label: 'Child ID' }) + '</div>' : '')
      + '</div>'
      /* EMERGENCY CARD (2026-10-09, Anthony: "educators should be able to see the emergency
         card for their children"). The same printable card directors open, for a child
         in this educator's own rooms; every view is audited. */
      + '<button type="button" data-ecard style="flex:0 0 auto;margin-left:auto;background:#B91C1C;color:#fff;border:0;border-radius:9px;padding:8px 12px;font-size:12.5px;font-weight:800;cursor:pointer;white-space:nowrap;">🆘 Emergency card</button>'
      + '</div>';

    // Safety first — this is the reason an educator opens a record mid-room.
    var alerts = [
      { label: 'Allergies', value: healthText(c.allergies, 'allergen'), colour: '#B91C1C', icon: '⚠️' },
      { label: 'Health alerts', value: healthText(c.health_alerts, 'label'), colour: '#B45309', icon: '🩺' },
      { label: 'Medical notes', value: c.medical_notes, colour: '#0F172A', icon: '📋' },
      { label: 'Dietary', value: healthText(c.dietary_restrictions, 'restriction') || c.dietary_notes, colour: '#0F172A', icon: '🍎' },
    ].filter(function (a) { return a.value; });

    if (alerts.length) {
      html += '<div style="' + CARD + '--kt-edge:#B91C1C;">';
      alerts.forEach(function (a, i) {
        html += '<div style="' + (i ? 'border-top:1px solid #F1F5F9;padding-top:9px;margin-top:9px;' : '') + '">'
          + '<div style="font-size:11px;font-weight:800;letter-spacing:.5px;color:' + a.colour + ';">' + a.icon + ' ' + a.label.toUpperCase() + '</div>'
          + '<div style="font-size:13.5px;color:#0F172A;margin-top:2px;line-height:1.45;">' + esc(a.value) + '</div></div>';
      });
      html += '</div>';
    } else {
      html += '<div style="' + CARD + 'color:#16A34A;font-weight:700;font-size:13.5px;">✓ No allergies or medical alerts on file.</div>';
    }

    /* NOTES (2026-10-09, Anthony: "allow educators to add notes to child record and see
       time/days and can edit"). Filled by KT.renderChildNotes once the record is drawn. */
    html += '<div style="' + CARD + '"><div style="font-weight:800;font-size:14px;color:#0F172A;margin-bottom:8px;">📝 Notes</div><div id="cr-notes"></div></div>';

    /* Usual drop-off / pick-up. The room plans the day around these, and an
       educator wondering "is someone late?" should not have to leave the record
       to find out. Stored as a wall-clock TIME, so nothing is converted. */
    if (c.expected_dropoff_time || c.expected_pickup_time) {
      html += '<div style="' + CARD + '">'
        + '<div style="font-weight:800;font-size:14px;color:#0F172A;margin-bottom:8px;">Usual times</div>'
        + '<div style="display:flex;gap:26px;">'
        + '<div><div style="font-size:11px;font-weight:800;letter-spacing:.5px;color:#64748B;">DROP-OFF</div>'
        + '<div style="font-size:15px;font-weight:800;color:#0F172A;margin-top:2px;">' + esc(t12(c.expected_dropoff_time)) + '</div></div>'
        + '<div><div style="font-size:11px;font-weight:800;letter-spacing:.5px;color:#64748B;">PICK-UP</div>'
        + '<div style="font-size:15px;font-weight:800;color:#0F172A;margin-top:2px;">' + esc(t12(c.expected_pickup_time)) + '</div></div>'
        + '</div></div>';
    }

    var guardians = d.guardians || [];
    html += '<div style="' + CARD + '"><div style="display:flex;align-items:center;gap:8px;margin-bottom:8px;"><div style="font-weight:800;font-size:14px;color:#0F172A;flex:1;">Parents / guardians</div>'
      + (guardians.length ? '<button type="button" data-msg="all" style="background:#fff;color:' + TEAL + ';border:1px solid ' + TEAL + ';border-radius:9px;padding:6px 11px;font-size:12.5px;font-weight:800;cursor:pointer;">💬 Message parents</button>' : '')
      + (guardians.length ? '<button type="button" data-sendfile style="margin-left:6px;background:#fff;color:' + TEAL + ';border:1px solid ' + TEAL + ';border-radius:9px;padding:6px 11px;font-size:12.5px;font-weight:800;cursor:pointer;">📎 Send a file</button>' : '')
      + '</div>';
    if (!guardians.length) html += '<div style="color:#64748B;font-size:13px;">No guardians on file.</div>';
    guardians.forEach(function (g, i) {
      var gname = ((g.first_name || '') + ' ' + (g.last_name || '')).trim();
      html += '<div style="display:flex;align-items:center;gap:10px;padding:8px 0;' + (i ? 'border-top:1px solid #F1F5F9;' : '') + '">'
        + KT.avatar(gname, { size: 34, photoUrl: g.photo_url ? absUrl(g.photo_url) : '' })
        + '<div style="flex:1;"><div style="font-weight:700;font-size:13.5px;color:#0F172A;">' + esc(gname)
        + (g.is_primary ? ' <span style="font-size:10px;background:#E1F3F6;color:#0E7C90;border-radius:5px;padding:1px 5px;font-weight:800;">PRIMARY</span>' : '') + '</div>'
        + '<div style="font-size:11.5px;color:#64748B;">' + esc(g.relationship || 'Guardian') + (g.can_pickup ? ' · may collect' : '') + '</div></div>'
        + '<div style="display:flex;gap:6px;flex-wrap:wrap;justify-content:flex-end;">'
        + (g.phone ? '<a href="tel:' + esc(g.phone) + '" style="background:' + TEAL + ';color:#fff;border-radius:9px;padding:7px 12px;font-size:12.5px;font-weight:800;text-decoration:none;">Call</a>' : '')
        + (g.id ? '<button type="button" data-msg="' + esc(g.id) + '" data-ch="sms" style="background:#fff;color:' + TEAL + ';border:1px solid ' + TEAL + ';border-radius:9px;padding:6px 11px;font-size:12.5px;font-weight:800;cursor:pointer;">Text</button>'
          + '<button type="button" data-msg="' + esc(g.id) + '" data-ch="email" style="background:#fff;color:' + TEAL + ';border:1px solid ' + TEAL + ';border-radius:9px;padding:6px 11px;font-size:12.5px;font-weight:800;cursor:pointer;">Email</button>' : '')
        + '</div></div>';
    });
    if (c.primary_phone || c.primary_email) {
      html += '<div style="border-top:1px solid #F1F5F9;margin-top:8px;padding-top:8px;font-size:12px;color:#64748B;">'
        + 'Family: ' + esc(c.family_name || '') + (c.primary_phone ? ' · ' + esc(c.primary_phone) : '') + '</div>';
    }
    html += '</div>';

    var ec = d.emergency_contacts || [];
    html += '<div style="' + CARD + '"><div style="font-weight:800;font-size:14px;color:#0F172A;margin-bottom:8px;">Emergency contacts</div>';
    if (!ec.length) html += '<div style="color:#64748B;font-size:13px;">None on file.</div>';
    ec.forEach(function (e, i) {
      html += '<div style="display:flex;align-items:center;gap:10px;padding:8px 0;' + (i ? 'border-top:1px solid #F1F5F9;' : '') + '">'
        + '<div style="flex:1;"><div style="font-weight:700;font-size:13.5px;color:#0F172A;">' + esc(e.name) + '</div>'
        + '<div style="font-size:11.5px;color:#64748B;">' + esc(e.relationship || '') + (e.can_pickup ? ' · may collect' : '') + '</div></div>'
        + (e.phone ? '<a href="tel:' + esc(e.phone) + '" style="background:#0F172A;color:#fff;border-radius:9px;padding:7px 12px;font-size:12.5px;font-weight:800;text-decoration:none;">Call</a>' : '')
        + '</div>';
    });
    html += '</div>';

    // The child's own log history — every care moment AND every sign-in/out, with
    // who recorded it. This is the compliance trail: "who had this child, and
    // when" has to be answerable from the child's record.
    var hist = d.history || [];
    var ICONS = {
      check_in: '✅', check_out: '👋', diaper: '🧷', bathroom: '🚽', nap: '😴', outdoor: '🌳',
      meal: '🍽️', snack: '🍎', bottle: '🍼', sunscreen: '☀️', mood: '🙂', medicine: '💊', clothing: '👕',
    };
    var LABELS = { check_in: 'Signed in', check_out: 'Signed out' };
    var fmtHist = function (at) {
      if (KT.fmtDateTime) return KT.fmtDateTime(at);
      return String(at || '').slice(0, 16);
    };

    // Paged, not endless: a child with a year of logs would otherwise render a
    // scroll you can't get to the bottom of. Numbered pages (KT.pagerBar), like
    // every other list — this was a "Show 15 more" button until 2026-09-25.
    var PAGE = 15;
    var page = 1;

    html += '<div id="cr-history" style="' + CARD + '"></div>';
    var renderHistory = function () {
      var el = ov.querySelector('#cr-history');
      if (!el) return;
      var rows = hist.slice((page - 1) * PAGE, page * PAGE);
      var h2 = '<div style="font-weight:800;font-size:14px;color:#0F172A;margin-bottom:8px;">'
        + 'Log history <span style="color:#64748B;font-weight:600;">· ' + hist.length + '</span></div>';
      if (!hist.length) {
        h2 += '<div style="color:#64748B;font-size:13px;">Nothing logged for this child yet.</div>';
      }
      h2 += rows.map(function (h, i) { return historyRow(h, i); }).join('');
      h2 += '<div class="cr-pager"></div>';
      el.innerHTML = h2;
      if (window.KT && KT.pagerBar) KT.pagerBar(el.querySelector('.cr-pager'), page, Math.ceil(hist.length / PAGE), function (p) {
        page = p; renderHistory();
        try { el.scrollIntoView({ block: 'start' }); } catch (e) {}
      });
    };

    function historyRow(h, i) {
      var isAttendance = h.group === 'attendance';
      return '<div style="display:flex;align-items:flex-start;gap:10px;padding:8px 0;' + (i ? 'border-top:1px solid #F1F5F9;' : '') + '">'
        + '<span style="font-size:16px;width:22px;text-align:center;">' + (ICONS[h.kind] || '•') + '</span>'
        + '<div style="flex:1;min-width:0;">'
        + '<div style="font-weight:700;font-size:13.5px;color:' + (isAttendance ? '#0E7C90' : '#0F172A') + ';">'
        + esc(LABELS[h.kind] || (h.kind.charAt(0).toUpperCase() + h.kind.slice(1)))
        + (h.detail ? ' <span style="font-weight:400;color:#475569;">· ' + esc(h.detail) + '</span>' : '') + '</div>'
        + (h.note ? '<div style="font-size:12px;color:#64748B;margin-top:1px;">' + esc(h.note) + '</div>' : '')
        + '<div style="font-size:11px;color:#64748B;margin-top:2px;">' + esc(fmtHist(h.at))
        + (h.by ? ' · by ' + esc(h.by) : '') + '</div>'
        + '</div></div>';
    }

    var pk = d.pickup_authorizations || [];
    html += '<div style="' + CARD + 'margin-bottom:40px;"><div style="font-weight:800;font-size:14px;color:#0F172A;margin-bottom:8px;">Authorised for pickup</div>';
    if (!pk.length) html += '<div style="color:#64748B;font-size:13px;">Only the guardians listed above.</div>';
    pk.forEach(function (p, i) {
      html += '<div style="display:flex;align-items:center;gap:10px;padding:8px 0;' + (i ? 'border-top:1px solid #F1F5F9;' : '') + '">'
        + KT.avatar(p.full_name, { size: 32, photoUrl: p.photo_id_url ? absUrl(p.photo_id_url) : '' })
        + '<div style="flex:1;"><div style="font-weight:700;font-size:13.5px;color:#0F172A;">' + esc(p.full_name) + '</div>'
        + '<div style="font-size:11.5px;color:#64748B;">' + esc(p.relationship || '') + (p.expires_at ? ' · until ' + esc(String(p.expires_at).slice(0, 10)) : '') + '</div></div>'
        + (p.phone ? '<a href="tel:' + esc(p.phone) + '" style="color:' + TEAL + ';font-weight:800;font-size:12.5px;text-decoration:none;">Call</a>' : '')
        + '</div>';
    });
    html += '<div id="cr-tpick"></div></div>';

    ov.querySelector('#cr-body').innerHTML = html;
    if (KT.tempPickupList) KT.tempPickupList(ov.querySelector('#cr-tpick'), childId, { cancel: false });
    var notesBox = ov.querySelector('#cr-notes');
    if (notesBox && KT.renderChildNotes) KT.renderChildNotes(notesBox, childId);
    renderHistory();
    makeCollapsible(ov);

    // Files to this child's family (screen-files.js), with the family already picked.
    var sfBtn = ov.querySelector('[data-sendfile]');
    if (sfBtn) sfBtn.addEventListener('click', function (e) {
      e.preventDefault(); e.stopPropagation();
      if (KT.sendFamilyFile) KT.sendFamilyFile({ familyId: c.family_id, childId: childId, childName: c.first_name });
      else location.hash = '#files';
    });
    var ecBtn = ov.querySelector('[data-ecard]');
    if (ecBtn) ecBtn.addEventListener('click', function (e) { e.preventDefault(); e.stopPropagation(); openEmergencyCard(childId, name); });
    ov.querySelectorAll('[data-msg]').forEach(function (b) {
      b.addEventListener('click', function (e) {
        e.preventDefault(); e.stopPropagation();
        var who = b.getAttribute('data-msg');
        openParentMessage(childId, c.first_name || name, who === 'all' ? null : +who, b.getAttribute('data-ch') || null);
      });
    });
  }

  function authHeaders(accept) {
    var h = { Accept: accept || 'application/json' };
    var t = (window.KT && KT.Auth && KT.Auth.token && KT.Auth.token()) || sessionStorage.getItem('kt_token') || localStorage.getItem('kt_token');
    if (t) h.Authorization = 'Bearer ' + t;
    var aa = sessionStorage.getItem('kt_active_agency_id'); if (aa) h['X-Active-Agency-Id'] = aa;
    return h;
  }

  /* The child's emergency card in the portal's own viewer, with Print (same as directors). */
  function openEmergencyCard(childId, name) {
    var base = apiBase();
    fetch(base + '/provider/children/' + childId + '/emergency-card', { headers: authHeaders('text/html') })
      .then(function (r) { if (!r.ok) throw new Error(r.status === 403 ? 'This card is only for children in your rooms.' : 'HTTP ' + r.status); return r.text(); })
      .then(function (htmlDoc) {
        if (window.KT && KT.viewHtml) {
          KT.viewHtml(htmlDoc, {
            title: (name || 'Child') + ' — emergency card', label: 'Emergency card', hide: '.toolbar',
            externalPrint: function () {
              return fetch(base + '/provider/children/' + childId + '/emergency-card/print-link', { headers: authHeaders() })
                .then(function (r) { if (!r.ok) throw new Error('HTTP ' + r.status); return r.json(); })
                .then(function (d) { return d && d.url; });
            },
          });
          return;
        }
        var w = window.open('', '_blank');
        if (w) { w.document.open(); w.document.write(htmlDoc); w.document.close(); }
      })
      .catch(function (e) { if (KT.toast) KT.toast('⚠️', 'Could not open the emergency card', (e && e.message) || '', '#DC2626'); });
  }

  /* TEXT OR EMAIL A CHILD'S PARENTS THROUGH KIDDIETRAC (2026-10-09). Sent from the agency's
     number and mailer — never the educator's own — and logged on the child. A parent who
     has not agreed to texts is shown as such and can still be emailed. */
  async function openParentMessage(childId, childFirst, onlyId, channel) {
    var M = window.KT && KT.Shell && KT.Shell.Modal;
    if (!M) return;
    var d;
    try { d = await Api.get('/provider/children/' + childId + '/parent-contacts'); }
    catch (e) { if (KT.toast) KT.toast('⚠️', 'Could not load parents', (e && e.message) || '', '#DC2626'); return; }
    var gs = d.guardians || [];
    var max = d.sms_max || 480;
    var box = document.createElement('div');
    var wantSms = channel ? channel === 'sms' : gs.some(function (g) { return g.sms_ok; });
    var wantEmail = channel ? channel === 'email' : true;
    box.innerHTML = '<div style="font-size:12.5px;color:#64748B;line-height:1.5;margin:-2px 0 10px;">Sent from the agency\'s number and email, not yours, and kept on ' + esc(childFirst) + '\'s record. Parents also see it in their app.</div>'
      + '<div style="font-size:11.5px;font-weight:800;letter-spacing:.4px;color:#334155;text-transform:uppercase;margin-bottom:4px;">To</div>'
      + gs.map(function (g) {
          var on = onlyId ? g.user_id === onlyId : true;
          return '<label style="display:flex;gap:9px;align-items:flex-start;padding:7px 0;border-top:1px solid #F1F5F9;cursor:pointer;">'
            + '<input type="checkbox" data-g="' + g.user_id + '"' + (on ? ' checked' : '') + ' style="margin-top:3px;">'
            + '<span style="flex:1;min-width:0;"><b style="font-size:13.5px;color:#0F172A;">' + esc(g.name) + '</b>'
            + '<span style="font-size:12px;color:#64748B;"> · ' + esc(g.relationship || 'Parent') + '</span><br>'
            + '<span style="font-size:12px;color:' + (g.sms_ok ? '#047857' : '#B45309') + ';">📱 ' + (g.sms_ok ? 'Can be texted' : esc(g.sms_note || 'Cannot be texted')) + '</span>'
            + '<span style="font-size:12px;color:' + (g.email_ok ? '#047857' : '#B45309') + ';"> · ✉️ ' + (g.email_ok ? 'Can be emailed' : 'No email on file') + '</span></span></label>';
        }).join('')
      + '<div style="display:flex;gap:16px;margin:10px 0 6px;font-size:13.5px;">'
      + '<label style="display:flex;gap:6px;align-items:center;cursor:pointer;"><input type="checkbox" data-ch="sms"' + (wantSms ? ' checked' : '') + '> 📱 Text</label>'
      + '<label style="display:flex;gap:6px;align-items:center;cursor:pointer;"><input type="checkbox" data-ch="email"' + (wantEmail ? ' checked' : '') + '> ✉️ Email</label></div>'
      + '<textarea data-body rows="5" placeholder="e.g. ' + esc(childFirst) + ' had a great morning but is a little warm — could you call us when you can?" style="width:100%;box-sizing:border-box;border:1px solid #CBD5E1;border-radius:9px;padding:9px 11px;font-size:14px;font-family:inherit;resize:vertical;"></textarea>'
      + '<div data-count style="font-size:11.5px;color:#64748B;text-align:right;margin-top:3px;"></div>'
      + '<div data-out style="font-size:13px;margin-top:6px;"></div>'
      + (d.recent && d.recent.length ? '<details style="margin-top:10px;"><summary style="font-size:12.5px;color:#475569;cursor:pointer;">Recent messages about ' + esc(childFirst) + '</summary>'
          + d.recent.map(function (r) {
              return '<div style="font-size:12px;color:#334155;padding:5px 0;border-top:1px solid #F1F5F9;">' + (r.channel === 'sms' ? '📱' : '✉️') + ' '
                + esc(KT.fmtDateTime ? KT.fmtDateTime(r.created_at) : r.created_at) + ' · ' + esc(r.sender || '') + ' · <span style="color:' + (r.status === 'sent' ? '#047857' : '#B45309') + ';">' + esc(r.status) + '</span><br>'
                + esc(String(r.body || '').slice(0, 160)) + '</div>';
            }).join('') + '</details>' : '');
    var ta = box.querySelector('[data-body]'), cnt = box.querySelector('[data-count]'), out = box.querySelector('[data-out]');
    function upd() {
      var n = (ta.value || '').trim().length, sms = box.querySelector('[data-ch="sms"]').checked;
      cnt.textContent = n + (sms ? ' / ' + max + ' (text)' : ' characters');
      cnt.style.color = sms && n > max ? '#B91C1C' : '#64748B';
    }
    ta.addEventListener('input', upd); box.querySelector('[data-ch="sms"]').addEventListener('change', upd); upd();
    var done = false;
    M.open({
      title: '💬 Message ' + childFirst + '\'s parents', body: box,
      actions: [
        { label: 'Close' },
        { label: 'Send', primary: true, busyLabel: 'Sending…', handler: function () {
            if (done) return;   // already sent: Send now just closes
            var ids = [].map.call(box.querySelectorAll('[data-g]:checked'), function (x) { return +x.getAttribute('data-g'); });
            var chs = [].map.call(box.querySelectorAll('[data-ch]:checked'), function (x) { return x.getAttribute('data-ch'); });
            var body = (ta.value || '').trim();
            out.style.color = '#B91C1C';
            if (!ids.length) { out.textContent = 'Choose at least one parent.'; return false; }
            if (!chs.length) { out.textContent = 'Choose Text, Email or both.'; return false; }
            if (body.length < 2) { out.textContent = 'Write a message.'; ta.focus(); return false; }
            if (chs.indexOf('sms') > -1 && body.length > max) { out.textContent = 'A text can be at most ' + max + ' characters — shorten it or send by email only.'; return false; }
            return Api.post('/provider/children/' + childId + '/message-parents', { guardian_ids: ids, channels: chs, body: body }).then(function (r) {
              var res = (r && r.results) || [];
              out.style.color = '#0F172A';
              out.innerHTML = res.map(function (x) {
                var ok = x.status === 'sent';
                return '<div style="color:' + (ok ? '#047857' : '#B45309') + ';">' + (ok ? '✓ ' : '• ') + esc(x.name || '') + ' — ' + (x.channel === 'sms' ? 'text' : 'email') + ': '
                  + (ok ? 'sent' : esc(x.status) + (x.note ? ' (' + esc(x.note) + ')' : '')) + '</div>';
              }).join('');
              done = true;
              if (KT.toast) KT.toast('💬', r.sent ? 'Message sent' : 'Nothing was sent', r.sent ? (r.sent + ' delivered to the carrier/mailer') : 'See the reasons in the window', r.sent ? '#16A34A' : '#B45309');
              return false;   // keep the window open so the results can be read
            }).catch(function (e) { out.style.color = '#B91C1C'; out.textContent = (e && e.message) || 'Could not send.'; return false; });
          } },
      ],
    });
  }

  // Every card on the child record collapses. On a phone the record is a long
  // scroll — allergies, guardians, emergency contacts, pickup, and a year of
  // logs — and an educator opening it mid-room usually wants exactly one of
  // those. Safety cards (allergies/alerts) stay OPEN by default; the rest start
  // closed. The state is remembered, so their preference sticks.
  function makeCollapsible(root) {
    if (window.innerWidth > 600) return;   // desktop has the room

    var OPEN_BY_DEFAULT = /allerg|alert|medical|child record/i;
    var cards = root.querySelectorAll('#cr-body > div');

    [].forEach.call(cards, function (card) {
      var head = card.firstElementChild;
      if (!head || card.children.length < 2) return;             // nothing to fold
      if (card.querySelector('.kt-avatar') && card.children.length === 2) return;  // the name/photo header card

      var title = (head.textContent || '').trim();
      if (!title) return;

      var key = 'kt_cr_open_' + title.toLowerCase().replace(/[^a-z]+/g, '_').slice(0, 30);
      var stored = null;
      try { stored = localStorage.getItem(key); } catch (e) {}
      var open = stored === null ? OPEN_BY_DEFAULT.test(title) : stored === '1';

      var body = document.createElement('div');
      while (head.nextSibling) body.appendChild(head.nextSibling);
      card.appendChild(body);

      var caret = document.createElement('span');
      caret.style.cssText = 'float:right;color:#64748B;font-size:13px;transition:transform .18s;';
      caret.textContent = '▾';
      head.appendChild(caret);
      head.style.cursor = 'pointer';
      head.style.userSelect = 'none';

      var paint = function () {
        body.style.display = open ? '' : 'none';
        caret.style.transform = open ? 'none' : 'rotate(-90deg)';
      };
      paint();

      head.addEventListener('click', function () {
        open = !open;
        try { localStorage.setItem(key, open ? '1' : '0'); } catch (e) {}
        paint();
      });
    });
  }

  Shell.registerScreen('educator:my-hours', renderMyHours);
  Shell.registerScreen('educator:my-schedule', renderMySchedule);
  Shell.registerScreen('educator:children', renderChildren);
  // Child record is now a real screen (was a full-screen body overlay) so it keeps
  // the app chrome and matches every other screen. Registered for the roles that can
  // reach the educator children list (incl. platform-admin view-as-educator).
  Shell.registerScreen('educator:child-record', renderChildRecordScreen);
  Shell.registerScreen('home_visitor:child-record', renderChildRecordScreen);

  // ── Tile launcher on the educator dashboard ─────────────────────────
  // The bottom bar lost its Menu button, so the dashboard carries every section.
  // Appended by observation (rather than by re-registering educator:dashboard)
  // so screen-educator.js stays the single owner of that screen.
  function isEducator() {
    try {
      var va = sessionStorage.getItem('kt_view_as') || '';
      if (va) return va === 'educator';
      var u = JSON.parse(sessionStorage.getItem('kt_user') || localStorage.getItem('kt_user') || '{}');
      var r = u.roles || [];
      return r.indexOf('educator') > -1 && ['agency_admin', 'platform_admin', 'centre_director'].every(function (x) { return r.indexOf(x) === -1; });
    } catch (e) { return false; }
  }
  function currentHash() { return (location.hash || '').replace('#', '').split('?')[0]; }

  // NOTE: the educator Today/Dashboard used to FORCE-mount the role-widgets KPI
  // strip here (it once belonged on that screen). It no longer does — the roster
  // screen now carries its own "Today at a glance" brief and role-widgets.js
  // deliberately suppresses the strip when .educator-shell is present. Re-mounting
  // it here fought that suppression: this file re-added it, role-widgets cleared
  // it, over and over — the "cards flapping back and forth" bug. Left removed.

  function ensureLauncher() {
    if (!isEducator()) return;
    // DESKTOP: the tiles belong ONLY on the home launcher (#home / renderHome), not
    // stamped onto the Today/roster screen. On desktop the top-bar carries Home +
    // navigation, so this "Everything else" grid (and its bottom Sign-out button)
    // was pure mobile-chrome leaking into the desktop Today view — remove it there.
    // Mobile keeps it: the bottom bar has no full menu, so the tiles are the menu.
    if (window.innerWidth > 768) return;
    var hash = currentHash();
    // The icon tile grid ('Everything else') belongs ONLY on Home, never on the
    // Today/roster screen. Home (empty hash) already renders the full .kt-tilehome
    // launcher (guarded below), so this keeps the icons off Today entirely.
    if (hash !== '' && hash !== 'home') return;
    var main = document.getElementById('appMain');
    if (!main || !main.children.length) return;
    if (main.querySelector('#kt-edu-launcher')) return;
    // On the empty-hash home the shell renders educator:home (screen-role-home.js)
    // — the full .kt-tilehome launcher, which already lists every section. Adding
    // our "Everything else" grid on top of it duplicated every tile. Only inject
    // when the visible screen is NOT the launcher (e.g. the #today/#dashboard
    // roster, which has no tile grid of its own).
    if (main.querySelector('.kt-tilehome') || main.querySelector('.kt-tile-grid')) return;
    if (!KT.roleTilesHtml) return;

    var wrap = document.createElement('div');
    wrap.id = 'kt-edu-launcher';
    wrap.style.cssText = 'margin-top:18px;';
    wrap.innerHTML = '<div style="font-weight:800;font-size:15px;color:#0F172A;margin:0 2px 10px;">Everything else</div>'
      + KT.roleTilesHtml('educator')
      // The ONE sign-out button, on the home screen — it used to be buried at the
      // bottom of Settings, which is not where anyone looks for it.
      + '<button id="kt-edu-signout" type="button" style="display:block;width:100%;max-width:280px;margin:16px auto 4px;'
      + 'background:#fff;border:1.5px solid #E2E8F0;color:#B91C1C;border-radius:12px;padding:12px;'
      + 'font-size:14px;font-weight:800;cursor:pointer;">Sign out</button>';
    main.appendChild(wrap);

    var so = wrap.querySelector('#kt-edu-signout');
    if (so) so.addEventListener('click', function () {
      // Same teardown the parent launcher does: end the session and purge the
      // token from BOTH stores, but KEEP biometric/PIN enrolment so relaunching
      // prompts to unlock. Full removal is in Settings → "Turn off biometric".
      try { if (KT.Auth && KT.Auth.clear) KT.Auth.clear(); } catch (e) {}
      try {
        var bio = {}; ['kt_biometric_enabled','kt_bio_token','kt_bio_user','kt_bio_agency','kt_bio_view','kt_bio_cred','kt_pin_vault','kt_pin_enabled'].forEach(function(k){var v=localStorage.getItem(k); if(v!=null) bio[k]=v;});
        sessionStorage.clear();
        localStorage.removeItem('kt_token'); localStorage.removeItem('kt_user');
        Object.keys(bio).forEach(function(k){ localStorage.setItem(k, bio[k]); });
      } catch (e) {}
      location.href = '/index.html';
    });
  }

  var pending = null;
  function schedule() { clearTimeout(pending); pending = setTimeout(ensureLauncher, 400); }
  window.addEventListener('hashchange', schedule);
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', schedule);
  else schedule();
  // The dashboard renders in stages (roster loads async) — re-check for a while.
  setInterval(ensureLauncher, 1500);
})(window);
