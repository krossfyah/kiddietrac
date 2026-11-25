/* ═══════════════════════════════════════════════════════════════════
   KiddieTrac — MY DAY: THE RIGHT PANEL, LIKE OUTLOOK (2026-11-25).

   Anthony: "can you add a collapsable right panel for tasks, calendar, meetings (similiar to how outlook has this)
   for the desktop view admins/directors/superadmins etc." His choices: all four sections (mini calendar + the
   picked day's agenda, upcoming meetings, tasks with tick-to-complete and quick add, today at the centre);
   remember each person's choice; agency admins, centre directors and super admins.

   • Desktop only: hidden below 1200px wide and on phones (the phone chrome / bottom bar owns those).
   • A thin icon rail is always docked on the right (Calendar, Meetings, Tasks, Today). An icon opens the panel on
     that section; the same icon again, or the chevron, folds it back to the rail.
   • 1440px and wider: the open panel takes its own column - the screen reflows beside it, nothing is covered.
     1200-1439px: the open panel floats over the screen's right edge and closes on a click outside, and it never
     opens by itself there.
   • Data comes from the screens' own endpoints, so every list is scoped exactly as those screens are (active
     agency, a director's centres): /meetings, /director/calendar/overlays, /tasks/mine, /tasks, /tasks/assignees,
     /agency/dashboard or /director/dashboard, /safe-arrival/today. Nothing is fetched while folded; a section loads
     when it is opened, and the open section refreshes at most every 2 minutes, nudged by the badges poll.
   • Super admin with no agency picked (platform view) and sales reps: Calendar, Meetings (the KiddieTrac team
     calendar) and Tasks - no "Today at the centre".
   • Follow-up (2026-11-25, Anthony):
     - Private to-dos: "Me" is the first and default assignee in quick add (POST /tasks private=1). Only the
       creator ever sees one (TaskController + every task reader), it never notifies.
     - Admins' ratio alerts come from the room ratio engine across every centre they can see
       (/operations/ratio-status, the Room ratios screen's own feed), grouped by centre.
     - Sales calendar (/sales/calendar: website bookings, lead follow-ups, sales events) shows for sales reps and
       super admins in their own (platform) role, in pink, never for agency admins or directors.
     - "Days ahead": a Coming up list under the calendar and the Meetings range follow one per-person setting
       (Today / 3 days / 1 week / 2 weeks / 1 month, default 1 week), saved server-side in ui-prefs my_day and
       set from the panel's gear or My settings.
   • Outlook / Google (2026-11-25, Anthony: "make it so the calendar is connected to the email ... (optional)"): once
     someone connects their own calendar or the agency's in Settings > Calendar (App\Support\ExternalCalendar), its
     events join the calendar dots, the agenda, Coming up and Meetings (blue bar; the agency's private events read
     "Busy"). One GET /me/calendar-connections/events per open section, cached 5 minutes here and on the server; when
     nothing is connected the panel stops asking for the session and offers "Connect Outlook or Google calendar".
   • State per person in localStorage kt_rp_<user id>: { open, section, days }.
   • Times and "today" are the agency's (KT.agencyToday / KT.fmtTime). Text is translated by kt-i18n's sweep.
   ═══════════════════════════════════════════════════════════════════ */
(function (w, d) {
  'use strict';
  if (w.__ktRightPanel) return; w.__ktRightPanel = true;
  var KT = (w.KT = w.KT || {});

  var MIN_W = 1200, PUSH_W = 1440, RAIL = 44, BODY_W = 300;
  var SECTIONS = ['calendar', 'meetings', 'tasks', 'today'];
  var LABEL = { calendar: 'Calendar', meetings: 'Meetings', tasks: 'Tasks', today: 'Today at the centre' };
  var ICON = {
    calendar: '<rect x="3" y="5" width="18" height="16" rx="2.5"/><path d="M16 3v4M8 3v4M3 10h18"/>',
    meetings: '<rect x="2.5" y="6" width="13" height="12" rx="2.5"/><path d="m15.5 10.5 6-3.5v10l-6-3.5"/>',
    tasks: '<rect x="3" y="3" width="18" height="18" rx="3.5"/><path d="m8 12.5 2.8 2.8L16.5 9"/>',
    today: '<path d="M3 12h4l2.5-6.5 5 13 2.5-6.5h4"/>',
    fold: '<path d="m9 6 6 6-6 6"/>',
    unfold: '<path d="m15 6-6 6 6 6"/>',
    plus: '<path d="M12 5v14M5 12h14"/>',
    refresh: '<path d="M20.5 12a8.5 8.5 0 1 1-2.6-6.1L20.5 8.5"/><path d="M20.5 3.5v5h-5"/>',
    prev: '<path d="m15 6-6 6 6 6"/>',
    next: '<path d="m9 6 6 6-6 6"/>',
    video: '<rect x="2.5" y="6" width="13" height="12" rx="2.5"/><path d="m15.5 10.5 6-3.5v10l-6-3.5"/>',
    clock: '<circle cx="12" cy="12" r="8.5"/><path d="M12 7.5V12l3 2"/>',
    lock: '<rect x="5" y="11" width="14" height="10" rx="2.5"/><path d="M8 11V8a4 4 0 0 1 8 0v3"/>',
    repeat: '<path d="M17 2.5 20.5 6 17 9.5"/><path d="M3.5 11V9.5A3.5 3.5 0 0 1 7 6h13.5"/><path d="M7 21.5 3.5 18 7 14.5"/><path d="M20.5 13v1.5A3.5 3.5 0 0 1 17 18H3.5"/>',
    gear: '<circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.7 1.7 0 0 0 .3 1.8l.1.1a2 2 0 1 1-2.8 2.8l-.1-.1a1.7 1.7 0 0 0-1.8-.3 1.7 1.7 0 0 0-1 1.5V21a2 2 0 1 1-4 0v-.1a1.7 1.7 0 0 0-1.1-1.5 1.7 1.7 0 0 0-1.8.3l-.1.1a2 2 0 1 1-2.8-2.8l.1-.1a1.7 1.7 0 0 0 .3-1.8 1.7 1.7 0 0 0-1.5-1H3a2 2 0 1 1 0-4h.1a1.7 1.7 0 0 0 1.5-1.1 1.7 1.7 0 0 0-.3-1.8l-.1-.1a2 2 0 1 1 2.8-2.8l.1.1a1.7 1.7 0 0 0 1.8.3H9a1.7 1.7 0 0 0 1-1.5V3a2 2 0 1 1 4 0v.1a1.7 1.7 0 0 0 1 1.5 1.7 1.7 0 0 0 1.8-.3l.1-.1a2 2 0 1 1 2.8 2.8l-.1.1a1.7 1.7 0 0 0-.3 1.8V9a1.7 1.7 0 0 0 1.5 1H21a2 2 0 1 1 0 4h-.1a1.7 1.7 0 0 0-1.5 1z"/>'
  };
  // "Days ahead" (2026-11-25): how far the Coming up list and the Meetings section look.
  var RANGES = [[1, 'Today'], [3, '3 days'], [7, '1 week'], [14, '2 weeks'], [30, '1 month']];
  function rangeLabel(n) { for (var i = 0; i < RANGES.length; i++) if (RANGES[i][0] === n) return RANGES[i][1]; return '1 week'; }
  function svg(name, size) {
    return '<svg viewBox="0 0 24 24" width="' + (size || 20) + '" height="' + (size || 20) + '" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">' + ICON[name] + '</svg>';
  }
  function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }
  function api() { return KT.Api; }
  // Fixed words go through the portal's dictionary when they are joined to a name, date or number (the DOM sweep only
  // matches whole text); a whole label is left to the sweep.
  function T(s) { try { return (KT.i18n && KT.i18n.t) ? KT.i18n.t(s) : s; } catch (e) { return s; } }
  function toast(msg, kind) { try { if (KT.toast) KT.toast(msg, kind || 'success'); } catch (e) {} }
  function said(e, fb) { var m = (e && (e.message || (e.data && e.data.message))) || ''; return m && !/fetch|network|undefined|null/i.test(m) ? m : fb; }

  /* ── who, where ─────────────────────────────────────────────────── */
  function store(k) { try { return sessionStorage.getItem(k) || localStorage.getItem(k) || ''; } catch (e) { return ''; } }
  function me() { try { return JSON.parse(store('kt_user') || '{}') || {}; } catch (e) { return {}; } }
  function uid() { var u = me(); return u && u.id ? String(u.id) : ''; }
  function agency() { var a = ''; try { a = sessionStorage.getItem('kt_active_agency_id') || ''; } catch (e) {} return /^\d+$/.test(a) ? a : ''; }
  function isPlatformTeam() { var u = me(); return !!(u.is_platform_admin || (Array.isArray(u.roles) && u.roles.indexOf('platform_admin') !== -1)); }
  function roleNow() {
    var b = d.body; if (!b) return '';
    if (b.classList.contains('role-centre-director')) return 'director';
    if (b.classList.contains('role-agency-admin')) return 'admin';
    if (b.classList.contains('role-platform-admin')) return 'platform';
    if (b.classList.contains('role-sales-rep')) return 'sales';
    return '';
  }
  function hasRole(r) { var u = me(); return Array.isArray(u.roles) && u.roles.indexOf(r) !== -1; }
  /* Sales calendar: sales reps and super admins as themselves. A super admin runs through the agency admin shell
     (body role-agency-admin) - "View as" (sessionStorage kt_view_as) is what makes them an agency admin or director,
     and then it never shows: sales data stays with the platform team. */
  function viewingAs() { try { return sessionStorage.getItem('kt_view_as') || ''; } catch (e) { return ''; } }
  function salesOn() {
    var r = roleNow();
    if (r === 'sales') return hasRole('sales_rep') || (isPlatformTeam() && viewingAs() === 'sales_rep');
    return isPlatformTeam() && !viewingAs() && (r === 'admin' || r === 'platform');
  }
  // Can assign to other people (educators, home visitors, parents): admins and directors in an agency.
  function canAssign() { var r = roleNow(); return !!agency() && (r === 'admin' || r === 'director' || r === 'platform'); }
  function phoneChrome() {
    var mn = d.getElementById('kt-mobilenav');
    return !!(mn && getComputedStyle(mn).display !== 'none');
  }
  function eligible() { return !!roleNow() && w.innerWidth >= MIN_W && !phoneChrome() && !!uid(); }
  // Platform view: a super admin with no agency picked, or a sales rep (the KiddieTrac team calendar, no centre).
  function platformView() { return !agency() || roleNow() === 'sales'; }
  function sections() { return platformView() ? ['calendar', 'meetings', 'tasks'] : SECTIONS; }
  function meetingScope() { return platformView() ? 'platform' : 'agency'; }

  /* ── remembered state, per person ─────────────────────────────────── */
  var S = { open: true, section: 'calendar', month: '', sel: '', days: 7 };
  function key() { return 'kt_rp_' + uid(); }
  function okDays(n) { n = +n; return RANGES.some(function (r) { return r[0] === n; }) ? n : 0; }
  function load() {
    try { var j = JSON.parse(localStorage.getItem(key()) || 'null'); if (j && typeof j === 'object') { S.open = j.open !== false; if (SECTIONS.indexOf(j.section) !== -1) S.section = j.section; if (okDays(j.days)) S.days = okDays(j.days); } } catch (e) {}
  }
  function save() { try { localStorage.setItem(key(), JSON.stringify({ open: S.open, section: S.section, days: S.days })); } catch (e) {} }
  // The days-ahead choice follows the person to every device: ui-prefs my_day (server), read once at start.
  function loadServerPrefs() {
    try {
      api().get('/auth/me/ui-prefs').then(function (j) {
        var n = okDays(j && j.prefs && j.prefs.my_day && j.prefs.my_day.days);
        if (n && n !== S.days) { S.days = n; save(); if (S.section === 'calendar' || S.section === 'meetings') renderSection(); }
      }).catch(function () {});
    } catch (e) {}
  }
  function setDays(n) {
    n = okDays(n); if (!n) return Promise.resolve(false);
    S.days = n; save();
    if (isOpen() && (S.section === 'calendar' || S.section === 'meetings')) renderSection();
    try { w.dispatchEvent(new CustomEvent('kt:my-day-range', { detail: { days: n } })); } catch (e) {}
    return api().put('/auth/me/ui-prefs', { my_day: { days: n } }).then(function () { return true; });
  }

  /* ── dates (agency's) ─────────────────────────────────────────────── */
  function today() { try { if (KT.agencyToday) return KT.agencyToday(); } catch (e) {} var t = new Date(); return t.getFullYear() + '-' + pad(t.getMonth() + 1) + '-' + pad(t.getDate()); }
  function pad(n) { return (n < 10 ? '0' : '') + n; }
  function ymdParts(s) { var p = String(s).slice(0, 10).split('-'); return [+p[0], +p[1], +p[2]]; }
  function utcDate(s) { var p = ymdParts(s); return new Date(Date.UTC(p[0], p[1] - 1, p[2], 12)); }
  function addDays(s, n) { var x = utcDate(s); x.setUTCDate(x.getUTCDate() + n); return x.toISOString().slice(0, 10); }
  // Dates and times speak the person's portal language (kt_locale), not just the browser's.
  function lang() { var l = ''; try { l = localStorage.getItem('kt_locale') || ''; } catch (e) {} l = l || (d.documentElement.lang || ''); return /^[a-z]{2}(-[A-Za-z]{2})?$/.test(l) ? (l === 'en' ? 'en-CA' : l) : undefined; }
  function fmtD(s, opts) { try { return new Intl.DateTimeFormat(lang(), Object.assign({ timeZone: 'UTC' }, opts)).format(utcDate(s)); } catch (e) { return s; } }
  function dayTitle(s) { var t = today(); if (s === t) return T('Today'); if (s === addDays(t, 1)) return T('Tomorrow'); return fmtD(s, { weekday: 'short', month: 'short', day: 'numeric' }); }
  function hm12(hm) { var p = String(hm || '').split(':'); var h = +p[0], m = p[1] || '00'; if (isNaN(h)) return ''; return ((h % 12) || 12) + ':' + m + ' ' + (h < 12 ? 'AM' : 'PM'); }
  function mTime(m) {
    if (m.all_day) return T('All day');
    var a = null, b = null;
    try { if (KT.fmtTime && !platformView()) { a = KT.fmtTime(m.start); b = KT.fmtTime(m.end); } } catch (e) {}
    return (a || hm12(m.start_time)) + ' – ' + (b || hm12(m.end_time));
  }

  /* ── data (cached per agency; nothing while folded) ──────────────── */
  var C = {};          // { tasksMine, tasksAll, assignees, meetings:{range:list}, overlays:{month:list}, today, at:{} }
  var cacheFor = null;
  function cache() { var k = agency() + '|' + roleNow(); if (cacheFor !== k) { C = { meetings: {}, overlays: {}, at: {} }; cacheFor = k; } return C; }
  function fresh(name, ms) { return C.at[name] && (Date.now() - C.at[name] < ms); }

  function getMeetings(from, to) {
    var c = cache(), k = meetingScope() + ':' + from + ':' + to;
    if (c.meetings[k] && fresh('m:' + k, 120000)) return Promise.resolve(c.meetings[k]);
    var one = function (sc) { return api().get('/meetings?scope=' + sc + '&from=' + from + '&to=' + to).then(function (j) { return (j && j.meetings) || []; }); };
    // A super admin working in an agency also sees the KiddieTrac team's (sales) meetings.
    var both = meetingScope() === 'agency' && salesOn() ? [one('agency'), one('platform').catch(function () { return []; })] : [one(meetingScope())];
    return Promise.all(both).then(function (r) {
      var seen = {}, list = [];
      r.forEach(function (ms) { ms.forEach(function (m) { if (!seen[m.id]) { seen[m.id] = 1; list.push(m); } }); });
      c.meetings[k] = list; c.at['m:' + k] = Date.now(); return list;
    });
  }
  /* Sales calendar (sales reps, super admins): website bookings, lead follow-ups and sales events, as panel items
     { kind:'sales', date, start, label, sub, go }. Their times are the sales calendar's own zone (Eastern). */
  function tzParts(iso, tz) {
    try {
      var p = {}; new Intl.DateTimeFormat('en-CA', { timeZone: tz, year: 'numeric', month: '2-digit', day: '2-digit', hour: '2-digit', minute: '2-digit', hourCycle: 'h23' })
        .formatToParts(new Date(iso)).forEach(function (x) { p[x.type] = x.value; });
      return { date: p.year + '-' + p.month + '-' + p.day, hm: p.hour + ':' + p.minute };
    } catch (e) { return { date: String(iso).slice(0, 10), hm: String(iso).slice(11, 16) }; }
  }
  function getSales(from, to) {
    var c = cache(), k = from + ':' + to;
    if (!salesOn()) return Promise.resolve([]);
    if (c.sales && c.sales[k] && fresh('s:' + k, 120000)) return Promise.resolve(c.sales[k]);
    return api().get('/sales/calendar?from=' + from + '&to=' + to).then(function (j) {
      var tz = (j && j.tz) || 'America/Toronto', out = [];
      ((j && j.bookings) || []).forEach(function (b) {
        if (b.status === 'declined' || b.status === 'cancelled') return;
        var s = tzParts(b.start, tz);
        out.push({ kind: 'sales', id: 'b' + b.id, date: s.date, startIso: b.start, endIso: b.end, tz: tz, all: false, label: (b.title || T('Website booking')),
          sub: b.status === 'requested' ? T('Needs a decision') : (b.teams_url ? T('Microsoft Teams meeting') : T('Website booking')), tent: b.status === 'requested', go: 'sales-bookings?id=' + b.id, join: b.teams_url || '' });
      });
      ((j && j.activities) || []).forEach(function (a) {
        if (!a.date) return;
        out.push({ kind: 'sales', id: 'a' + a.id, date: String(a.date).slice(0, 10), all: true, label: (a.title || ''),
          sub: T(a.type === 'meeting' ? 'Lead meeting' : 'Lead follow-up'), go: a.lead_id ? 'sales-lead?id=' + a.lead_id : 'sales-calendar' });
      });
      ((j && j.events) || []).forEach(function (e) {
        if (e.kind === 'where' || !e.start) return;   // whereabouts are not events
        var s = tzParts(e.start, tz);
        out.push({ kind: 'sales', id: 'e' + e.id, date: s.date, startIso: e.start, endIso: e.end, tz: tz, all: !!e.all_day, label: e.title || T('Sales calendar'),
          sub: T('Sales calendar'), go: 'sales-calendar' });
      });
      c.sales = c.sales || {}; c.sales[k] = out; c.at['s:' + k] = Date.now(); return out;
    }).catch(function () { return []; });
  }
  function getOverlays(from, to) {
    var c = cache(), k = from + ':' + to;
    if (platformView()) return Promise.resolve([]);
    if (c.overlays[k] && fresh('o:' + k, 600000)) return Promise.resolve(c.overlays[k]);
    return api().get('/director/calendar/overlays?from=' + from + '&to=' + to).then(function (j) {
      c.overlays[k] = (j && j.events) || []; c.at['o:' + k] = Date.now(); return c.overlays[k];
    }).catch(function () { return []; });
  }
  /* Outlook / Google events (ExternalCalendar). xcalNone: nothing connected - not asked again this session/agency. */
  function getExternal(from, to) {
    var c = cache();
    if (c.xcalNone) return Promise.resolve([]);
    var k = 'x:' + from + ':' + to;
    if (c[k] && fresh(k, 300000)) return Promise.resolve(c[k]);
    return api().get('/me/calendar-connections/events?from=' + from + '&to=' + to).then(function (j) {
      var cals = (j && j.calendars) || [], tz = (j && j.timezone) || undefined, out = [];
      if (!cals.length) { c.xcalNone = true; return []; }
      c.xcalBad = cals.filter(function (x) { return !x.ok; }).map(function (x) { return x.address; });
      ((j && j.events) || []).forEach(function (e) {
        var date = e.all_day ? String(e.start).slice(0, 10) : (tzParts(e.start, tz).date || String(e.start).slice(0, 10));
        var last = e.all_day ? addDays(String(e.end).slice(0, 10), -1) : date;
        out.push({ kind: 'x', id: e.id, date: date, last: last < date ? date : last, startIso: e.all_day ? null : e.start, endIso: e.all_day ? null : e.end, tz: tz, all: !!e.all_day,
          label: e.title, sub: e.location || (e.organizer || (e.source === 'agency' ? T('Agency calendar') : (e.provider === 'google' ? 'Google' : 'Outlook'))),
          tent: !!e.tentative, priv: !!e.private && e.title !== 'Busy', rec: !!e.recurring, join: e.join_url, web: e.web_link });
      });
      c[k] = out; c.at[k] = Date.now(); return out;
    }).catch(function () { return []; });
  }
  function xcalFooter() {
    var c = cache();
    if (c.xcalNone) return '<div class="kt-rp-row" style="margin-top:6px"><button type="button" class="kt-rp-link" data-go="settings/calendar">' + esc(T('Connect Outlook or Google calendar')) + '</button></div>';
    if (c.xcalBad && c.xcalBad.length) return '<div class="kt-rp-row" style="margin-top:6px"><button type="button" class="kt-rp-link" data-go="settings/calendar">' + esc(T('Calendar needs reconnecting')) + '</button></div>';
    return '';
  }
  function getTasks(force) {
    var c = cache();
    if (!force && c.tasks && fresh('t', 120000)) return Promise.resolve(c.tasks);
    var mine = api().get('/tasks/mine').then(function (j) { return (j && j.tasks) || []; }).catch(function () { return []; });
    var all = !canAssign() ? Promise.resolve([]) : api().get('/tasks').then(function (j) { return (j && j.tasks) || []; }).catch(function () { return []; });
    return Promise.all([mine, all]).then(function (r) {
      var id = +uid();
      var open = function (t) { return t.status === 'open' || t.status === 'in_progress'; };
      // Private to-dos are mine (assigned to me); "Assigned by me" is the tasks I gave other people.
      c.tasks = { mine: r[0].filter(open), byMe: r[1].filter(function (t) { return open(t) && +t.assigned_by === id && !t.is_private; }) };
      c.at.t = Date.now(); return c.tasks;
    });
  }
  function getAssignees() {
    var c = cache();
    if (c.assignees) return Promise.resolve(c.assignees);
    return api().get('/tasks/assignees').then(function (j) { c.assignees = (j && j.assignees) || []; return c.assignees; });
  }
  function getToday(force) {
    var c = cache();
    if (!force && c.today && fresh('d', 120000)) return Promise.resolve(c.today);
    var dir = roleNow() === 'director';
    var dash = api().get(dir ? '/director/dashboard' : '/agency/dashboard').catch(function () { return null; });
    var sa = api().get('/safe-arrival/today').catch(function () { return null; });
    // Admins: every room's live ratio across the centres they can see - the Room ratios screen's own feed.
    var rr = dir ? Promise.resolve(null) : api().get('/operations/ratio-status').catch(function () { return null; });
    return Promise.all([dash, sa, rr]).then(function (r) {
      var t = { dir: dir, dash: r[0], sa: r[1], rooms: r[2], at: new Date() };
      c.today = t; c.at.d = Date.now(); return t;
    });
  }

  /* ── styles ───────────────────────────────────────────────────────── */
  function css() {
    if (d.getElementById('kt-rp-css')) return;
    var st = d.createElement('style'); st.id = 'kt-rp-css';
    var R = RAIL + 'px', O = (RAIL + BODY_W) + 'px';
    var DK = [
      ['#kt-rp-rail', 'background:#0E1A2E;border-color:#1E2B44;'],
      ['#kt-rp-body', 'background:#111C30;border-color:#1E2B44;color:#E2E8F0;'],
      ['.kt-rp-h', 'background:#111C30;border-color:#1E2B44;'],
      ['.kt-rp-h b,.kt-rp-sec h4,.kt-rp-item b,.kt-rp-cal-m', 'color:#F1F5F9;'],
      ['.kt-rp-ib', 'color:#94A3B8;'],
      ['.kt-rp-ib:hover', 'background:#1B2A44;color:#E2E8F0;'],
      ['.kt-rp-rb', 'color:#94A3B8;'],
      ['.kt-rp-rb:hover', 'background:#1B2A44;color:#E2E8F0;'],
      ['.kt-rp-rb.on', 'background:#16324A;color:#5EEAD4;'],
      ['.kt-rp-item', 'background:#162238;border-color:#22324F;'],
      ['.kt-rp-item:hover', 'background:#1B2A44;'],
      ['.kt-rp-sub,.kt-rp-muted,.kt-rp-cal-w', 'color:#94A3B8;'],
      ['.kt-rp-cal-d', 'color:#CBD5E1;'],
      ['.kt-rp-cal-d:hover', 'background:#1B2A44;'],
      ['.kt-rp-cal-d.out', 'color:#475569;'],
      ['.kt-rp-cal-d.today', 'background:rgba(45,212,191,.16);color:#5EEAD4;'],
      ['.kt-rp-cal-d.sel', 'box-shadow:inset 0 0 0 1.5px #2DD4BF;'],
      ['.kt-rp-ahead-h span,.kt-rp-dh', 'color:#CBD5E1;'],
      ['.kt-rp-dh b,.kt-rp-ec b,.kt-rp-t1', 'color:#F1F5F9;'],
      ['.kt-rp-day', 'border-color:#1E2B44;'],
      ['.kt-rp-ev:hover,.kt-rp-addt:hover', 'background:#1B2A44;'],
      ['.kt-rp-ec span,.kt-rp-t2,.kt-rp-none,.kt-rp-addt,.kt-rp-ic', 'color:#94A3B8;'],
      ['.kt-rp-ev.next', 'background:rgba(45,212,191,.14);'],
      ['.kt-rp-ev.next .kt-rp-ec b,.kt-rp-addt:hover', 'color:#5EEAD4;'],
      ['.kt-rp-next', 'background:#14B8A6;color:#042F2E;'],
      ['.kt-rp-ic.join', 'background:#16324A;color:#5EEAD4;'],
      ['.kt-rp-in,.kt-rp-sel', 'background:#0E1A2E;border-color:#2A3B5A;color:#E2E8F0;'],
      ['.kt-rp-stat', 'background:#162238;border-color:#22324F;'],
      ['.kt-rp-stat b', 'color:#F1F5F9;'],
      ['.kt-rp-btn.ghost', 'background:#162238;border-color:#2A3B5A;color:#E2E8F0;'],
      ['.kt-rp-link', 'color:#5EEAD4;'],
      ['.kt-rp-due', 'background:#1E2B44;color:#CBD5E1;'],
      ['.kt-rp-ctr', 'color:#94A3B8;'],
      ['.kt-rp-pv', 'background:#2E1F5E;color:#C4B5FD;']
    ];
    var themed = DK.map(function (x) { return x[0].split(',').map(function (s) { return 'html.kt-dark ' + s + ',html[data-theme="dark"] ' + s; }).join(',') + '{' + x[1] + '}'; }).join('');
    st.textContent = ''
      // Room for the rail (and, wide, the open panel): the shell's own column shrinks, so the screen reflows.
      + 'body.kt-rp-on #appShell{padding-right:' + R + ';box-sizing:border-box;transition:padding-right .2s ease;}'
      + 'body.kt-rp-on.kt-rp-push #appShell{padding-right:' + O + ';}'
      // Right-anchored floating bits move in beside the panel.
      + 'body.kt-rp-on #kt-toast-wrap,body.kt-rp-on #kt-push-nudge,body.kt-rp-on #kt-chat-dock,body.kt-rp-on #kt-mfa-nudge,body.kt-rp-on #kt-bio-nudge{margin-right:' + R + ';}'
      + 'body.kt-rp-on.kt-rp-push #kt-toast-wrap,body.kt-rp-on.kt-rp-push #kt-push-nudge,body.kt-rp-on.kt-rp-push #kt-chat-dock,body.kt-rp-on.kt-rp-push #kt-mfa-nudge,body.kt-rp-on.kt-rp-push #kt-bio-nudge{margin-right:' + O + ';}'
      + '#kt-rp{display:none;}body.kt-rp-on #kt-rp{display:block;}'
      + '#kt-rp-rail{position:fixed;top:0;right:0;bottom:0;width:' + R + ';z-index:120;background:#F8FAFC;border-left:1px solid #E2E8F0;display:flex;flex-direction:column;align-items:center;gap:4px;padding:12px 0;box-sizing:border-box;}'
      + '.kt-rp-rb{position:relative;width:34px;height:34px;border:0;border-radius:10px;background:transparent;color:#475569;display:flex;align-items:center;justify-content:center;cursor:pointer;padding:0;}'
      + '.kt-rp-rb:hover{background:#E2E8F0;color:#0F172A;}.kt-rp-rb.on{background:#E0F2F1;color:#0F766E;}'
      + '.kt-rp-rb:focus-visible,.kt-rp-ib:focus-visible,.kt-rp-cal-d:focus-visible,.kt-rp-item:focus-visible{outline:2px solid #2A8F9D;outline-offset:1px;}'
      + '.kt-rp-rb .kt-rp-dot{position:absolute;top:4px;right:4px;min-width:15px;height:15px;padding:0 3px;border-radius:8px;background:#DC2626;color:#fff;font:800 9.5px/15px system-ui,sans-serif;box-sizing:border-box;}'
      + '.kt-rp-sep{width:22px;height:1px;background:#E2E8F0;margin:4px 0;}'
      + '#kt-rp-body{position:fixed;top:0;right:' + R + ';bottom:0;width:' + BODY_W + 'px;z-index:119;background:#fff;border-left:1px solid #E2E8F0;display:none;flex-direction:column;box-sizing:border-box;color:#0F172A;font-size:13px;}'
      + 'body.kt-rp-open #kt-rp-body{display:flex;}'
      + 'body.kt-rp-open:not(.kt-rp-push) #kt-rp-body{box-shadow:-18px 0 40px -18px rgba(15,23,42,.35);}'
      + '.kt-rp-h{display:flex;align-items:center;gap:6px;padding:12px 10px 10px 14px;border-bottom:1px solid #EEF2F6;flex:0 0 auto;}'
      + '.kt-rp-h b{flex:1;font-size:14.5px;font-weight:800;color:#0F172A;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}'
      + '.kt-rp-ib{width:28px;height:28px;border:0;border-radius:8px;background:transparent;color:#64748B;display:inline-flex;align-items:center;justify-content:center;cursor:pointer;padding:0;flex:0 0 auto;}'
      + '.kt-rp-ib:hover{background:#F1F5F9;color:#0F172A;}'
      + '.kt-rp-scroll{flex:1;overflow-y:auto;overflow-x:hidden;overscroll-behavior:contain;padding:10px 12px 18px;scrollbar-width:thin;}'
      + '.kt-rp-sec h4{margin:12px 2px 6px;font-size:11px;font-weight:800;letter-spacing:.06em;text-transform:uppercase;color:#334155;}'
      + '.kt-rp-sec h4:first-child{margin-top:2px;}'
      + '.kt-rp-item{display:flex;gap:9px;align-items:flex-start;width:100%;box-sizing:border-box;text-align:left;background:#F8FAFC;border:1px solid #EEF2F6;border-radius:10px;padding:8px 10px;margin-bottom:6px;cursor:pointer;font:inherit;color:inherit;}'
      + '.kt-rp-item:hover{background:#F1F5F9;}'
      + '.kt-rp-item b{display:block;font-size:13px;font-weight:700;color:#0F172A;line-height:1.3;overflow-wrap:anywhere;}'
      + '.kt-rp-sub{display:block;font-size:11.5px;color:#64748B;margin-top:2px;line-height:1.35;}'
      + '.kt-rp-bar{width:3px;align-self:stretch;border-radius:2px;background:#2A8F9D;flex:0 0 auto;}'
      + '.kt-rp-bar.ev{background:#F59E0B;}.kt-rp-bar.tk{background:#6366F1;}.kt-rp-bar.pf{background:#7C3AED;}.kt-rp-bar.sl{background:#DB2777;}'
      + '.kt-rp-ctr{font-size:11.5px;font-weight:700;color:#64748B;margin:6px 2px 4px;}'
      + '.kt-rp-pv{display:inline-block;font-size:10px;font-weight:800;padding:0 5px;border-radius:5px;background:#EDE9FE;color:#5B21B6;margin-left:4px;vertical-align:1px;}'
      + '.kt-rp-muted{color:#64748B;font-size:12.5px;padding:6px 2px;}'
      + '.kt-rp-err{color:#B91C1C;font-size:12.5px;padding:6px 2px;}'
      + '.kt-rp-btn{display:inline-flex;align-items:center;gap:5px;height:28px;padding:0 11px;border-radius:8px;border:1px solid #0F766E;background:#0F766E;color:#fff;font:700 12.5px/1 inherit;cursor:pointer;white-space:nowrap;}'
      + '.kt-rp-btn:hover{filter:brightness(1.08);}.kt-rp-btn[disabled]{opacity:.55;cursor:default;}'
      + '.kt-rp-btn.ghost{background:#fff;color:#0F172A;border-color:#CBD5E1;}'
      + '.kt-rp-btn.sm{height:24px;padding:0 8px;font-size:11.5px;}'
      + '.kt-rp-row{display:flex;gap:6px;align-items:center;flex-wrap:wrap;}'
      + '.kt-rp-link{background:none;border:0;padding:4px 2px;color:#0F766E;font:700 12.5px inherit;cursor:pointer;}'
      + '.kt-rp-link:hover{text-decoration:underline;}'
      // mini calendar
      + '.kt-rp-cal-top{display:flex;align-items:center;gap:2px;margin-bottom:4px;}'
      + '.kt-rp-cal-m{flex:1;font-weight:800;font-size:13.5px;color:#0F172A;text-transform:capitalize;}'
      + '.kt-rp-cal{display:grid;grid-template-columns:repeat(7,1fr);gap:2px;}'
      + '.kt-rp-cal-w{font-size:10.5px;font-weight:700;color:#64748B;text-align:center;padding:2px 0;text-transform:uppercase;}'
      + '.kt-rp-cal-d{position:relative;height:32px;border:0;border-radius:8px;background:transparent;color:#1E293B;font:600 12.5px/1 inherit;cursor:pointer;padding:0;}'
      + '.kt-rp-cal-d:hover{background:#F1F5F9;}.kt-rp-cal-d.out{color:#CBD5E1;}'
      // Today = a teal tint (like Outlook); a picked day = a ring. Event dots stay on both.
      + '.kt-rp-cal-d.today{background:rgba(15,118,110,.14);color:#0F766E;font-weight:800;}'
      + '.kt-rp-cal-d.sel{box-shadow:inset 0 0 0 1.5px #0F766E;}.kt-rp-cal-d.today.sel{box-shadow:none !important;}'
      // the Outlook-style agenda
      + '.kt-rp-ahead-h{justify-content:space-between;margin:12px 2px 0;}.kt-rp-ahead-h span{font-size:11px;font-weight:800;letter-spacing:.06em;text-transform:uppercase;color:#334155;}'
      + '.kt-rp-day{padding-bottom:4px;border-bottom:1px solid #EEF2F6;margin-bottom:2px;}.kt-rp-day:last-of-type{border-bottom:0;}'
      + '.kt-rp-dh{display:flex;gap:6px;align-items:center;font-size:12.5px;font-weight:600;color:#475569;margin:10px 2px 5px;}'
      + '.kt-rp-dh b{font-weight:800;color:#0F172A;}.kt-rp-dh i{font-style:normal;color:#94A3B8;font-size:9px;}'
      + '.kt-rp-none{font-size:12.5px;color:#64748B;padding:0 2px 2px 10px;}'
      + '.kt-rp-cne{padding:0 2px 2px 10px;font-weight:600;}'
      + '.kt-rp-ev{display:flex;align-items:stretch;gap:9px;padding:6px 6px 6px 4px;border-radius:8px;cursor:pointer;min-width:0;margin-bottom:2px;}'
      + '.kt-rp-ev:hover{background:#F1F5F9;}'
      + '.kt-rp-vb{width:4px;flex:0 0 4px;border-radius:2px;box-sizing:border-box;background:#0F9488;border:1.5px solid #0F9488;}'
      + '.kt-rp-vb.sl{background:#DB2777;border-color:#DB2777;}.kt-rp-vb.ev{background:#F59E0B;border-color:#F59E0B;}.kt-rp-vb.tk{background:#7C3AED;border-color:#7C3AED;}'
      + '.kt-rp-vb.xc{background:#2563EB;border-color:#2563EB;}'
      + '.kt-rp-vb.tent{background:transparent;}'
      + '.kt-rp-tc{flex:0 0 60px;display:flex;flex-direction:column;justify-content:center;gap:1px;min-width:0;}'
      + '.kt-rp-t1{font-size:11.5px;font-weight:700;color:#1E293B;white-space:nowrap;}.kt-rp-t2{font-size:10.5px;color:#64748B;white-space:nowrap;}'
      + '.kt-rp-ec{flex:1;min-width:0;display:flex;flex-direction:column;justify-content:center;gap:1px;}'
      + '.kt-rp-ec b{font-size:12.5px;font-weight:700;color:#0F172A;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}'
      + '.kt-rp-ec span{font-size:11.5px;color:#64748B;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}'
      + '.kt-rp-ic{flex:0 0 auto;align-self:center;color:#64748B;display:inline-flex;align-items:center;justify-content:center;}'
      + '.kt-rp-ic.join{width:26px;height:26px;border-radius:7px;color:#0F766E;background:#E0F2F1;}.kt-rp-ic.join:hover{filter:brightness(.95);}'
      + '.kt-rp-ev.next{background:rgba(15,118,110,.10);}.kt-rp-ev.next .kt-rp-ec b{color:#0F766E;}'
      + '.kt-rp-next{display:inline-flex;align-items:center;gap:4px;height:20px;padding:0 9px;border-radius:999px;background:#0F766E;color:#fff;font-size:11px;font-weight:700;margin:2px 0 4px 2px;}'
      + '.kt-rp-addt{display:flex;align-items:center;gap:9px;width:100%;border:0;background:none;color:#64748B;font:500 12.5px inherit;padding:6px 4px;cursor:pointer;text-align:left;border-radius:8px;}'
      + '.kt-rp-addt:hover{color:#0F766E;background:#F1F5F9;}'
      + '.kt-rp-qt{display:flex;gap:6px;padding:4px 0;}'
      + '.kt-rp-cal-d i{position:absolute;left:50%;bottom:4px;transform:translateX(-50%);display:flex;gap:2px;}'
      + '.kt-rp-cal-d i s{width:4px;height:4px;border-radius:50%;background:#2A8F9D;display:block;}'
      + '.kt-rp-cal-d i s.ev{background:#F59E0B;}.kt-rp-cal-d i s.tk{background:#6366F1;}.kt-rp-cal-d i s.sl{background:#DB2777;}.kt-rp-cal-d i s.xc{background:#2563EB;}'
      // tasks
      + '.kt-rp-in,.kt-rp-sel{height:30px;box-sizing:border-box;border:1px solid #CBD5E1;border-radius:8px;padding:0 9px;font:500 12.5px inherit;color:#0F172A;background:#fff;min-width:0;}'
      + '.kt-rp-in{width:100%;}.kt-rp-in:focus,.kt-rp-sel:focus{outline:2px solid #2A8F9D;outline-offset:0;border-color:#2A8F9D;}'
      + '.kt-rp-add{display:grid;gap:6px;margin-bottom:8px;}'
      + '.kt-rp-add-more{display:none;grid-template-columns:minmax(0,1fr) minmax(0,128px);gap:6px;}.kt-rp-add.open .kt-rp-add-more{display:grid;}'
      + '.kt-rp-add-more>*{width:100% !important;min-width:0 !important;max-width:100% !important;}'
      + '.kt-rp-add-go{display:none;justify-content:flex-end;gap:6px;}.kt-rp-add.open .kt-rp-add-go{display:flex;}'
      + '.kt-rp-chk{width:20px;height:20px;margin:0;padding:0;flex:0 0 auto;border-radius:50%;border:1.8px solid #94A3B8;background:transparent;color:transparent;display:inline-flex;align-items:center;justify-content:center;cursor:pointer;}'
      + '.kt-rp-chk:hover{border-color:#0F766E;color:#0F766E;}.kt-rp-chk[aria-checked="true"]{background:#0F766E;border-color:#0F766E;color:#fff;}'
      + '.kt-rp-due{display:inline-block;font-size:10.5px;font-weight:800;padding:1px 6px;border-radius:6px;background:#EEF2F6;color:#475569;margin-top:3px;}'
      + '.kt-rp-due.late{background:#FEE2E2;color:#B91C1C;}.kt-rp-due.now{background:#FEF3C7;color:#92400E;}'
      // today
      + '.kt-rp-stats{display:grid;grid-template-columns:1fr 1fr;gap:6px;}'
      + '.kt-rp-stat{display:block;text-align:left;border:1px solid #EEF2F6;background:#F8FAFC;border-radius:10px;padding:9px 10px;cursor:pointer;font:inherit;color:inherit;}'
      + '.kt-rp-stat:hover{filter:brightness(.98);}'
      + '.kt-rp-stat b{display:block;font-size:20px;font-weight:800;color:#0F172A;line-height:1.1;}'
      + '.kt-rp-stat span{display:block;font-size:11px;font-weight:700;color:#64748B;margin-top:2px;}'
      + '.kt-rp-stat.warn b{color:#B45309;}.kt-rp-stat.bad b{color:#B91C1C;}.kt-rp-stat.ok b{color:#047857;}'
      + '.kt-rp-skel{height:42px;border-radius:10px;background:linear-gradient(90deg,rgba(148,163,184,.12),rgba(148,163,184,.24),rgba(148,163,184,.12));background-size:200% 100%;animation:ktrpsk 1.2s linear infinite;margin-bottom:6px;}'
      + '@keyframes ktrpsk{from{background-position:200% 0}to{background-position:-200% 0}}'
      // Dark only when the portal itself is dark (html.kt-dark / data-theme=dark): the desktop portal has no OS-dark
      // theme, and a dark panel beside a light screen would be the odd one out.
      + themed
      + '@media (prefers-reduced-motion: reduce){body.kt-rp-on #appShell{transition:none;}.kt-rp-skel{animation:none;}}';
    d.head.appendChild(st);
  }

  /* ── the frame ────────────────────────────────────────────────────── */
  var root = null, rail = null, body = null, scroller = null, titleEl = null;
  function build() {
    if (root) return;
    css();
    root = d.createElement('div'); root.id = 'kt-rp'; root.setAttribute('data-kt-nogrid', '1'); root.setAttribute('data-kt-no-line-icon', '1'); root.setAttribute('data-kt-noswitch', '1');
    rail = d.createElement('nav'); rail.id = 'kt-rp-rail'; rail.setAttribute('aria-label', 'My day');
    body = d.createElement('aside'); body.id = 'kt-rp-body'; body.setAttribute('aria-label', 'My day');
    body.innerHTML = '<div class="kt-rp-h"><b id="kt-rp-title"></b>'
      + '<button type="button" class="kt-rp-ib" data-rp="new" data-kttip="' + esc(T('New')) + '" aria-label="New">' + svg('plus', 18) + '</button>'
      + '<button type="button" class="kt-rp-ib" data-rp="range" data-kttip="' + esc(T('Days ahead')) + '" aria-label="Days ahead">' + svg('gear', 17) + '</button>'
      + '<button type="button" class="kt-rp-ib" data-rp="refresh" data-kttip="' + esc(T('Refresh')) + '" aria-label="Refresh">' + svg('refresh', 17) + '</button>'
      + '<button type="button" class="kt-rp-ib" data-rp="fold" data-kttip="' + esc(T('Collapse')) + '" aria-label="Collapse">' + svg('fold', 18) + '</button></div>'
      + '<div class="kt-rp-scroll" id="kt-rp-scroll"></div>';
    root.appendChild(body); root.appendChild(rail);
    d.body.appendChild(root);
    scroller = body.querySelector('#kt-rp-scroll'); titleEl = body.querySelector('#kt-rp-title');
    body.addEventListener('click', onHeadClick);
    rail.addEventListener('click', onRailClick);
    d.addEventListener('pointerdown', onOutside, true);
    d.addEventListener('keydown', function (e) { if (e.key === 'Escape' && S.open && !pushMode() && d.body.classList.contains('kt-rp-open') && !d.querySelector('.kt-qp')) { setOpen(false); } });
  }
  function drawRail() {
    var secs = sections(), html = '';
    // Folded: the chevron opens it again. Open: the panel's own header carries the collapse chevron.
    if (!isOpen()) html += '<button type="button" class="kt-rp-rb" data-rp="toggle" data-kttip="' + esc(T('Expand')) + '" aria-label="Expand" aria-expanded="false">' + svg('unfold', 18) + '</button><span class="kt-rp-sep"></span>';
    else html += '<span style="height:38px;flex:0 0 auto"></span><span class="kt-rp-sep"></span>';
    secs.forEach(function (s) {
      var on = isOpen() && S.section === s;
      html += '<button type="button" class="kt-rp-rb' + (on ? ' on' : '') + '" data-rp-sec="' + s + '" data-kttip="' + esc(T(LABEL[s])) + '" aria-label="' + esc(LABEL[s]) + '" aria-pressed="' + on + '">' + svg(s, 20) + '</button>';
    });
    rail.innerHTML = html;
  }
  function onRailClick(e) {
    var b = e.target.closest('button'); if (!b) return;
    if (b.getAttribute('data-rp') === 'toggle') { setOpen(!isOpen()); return; }
    var s = b.getAttribute('data-rp-sec'); if (!s) return;
    if (isOpen() && S.section === s) { setOpen(false); return; }
    S.section = s; save(); setOpen(true);
  }
  function onHeadClick(e) {
    var b = e.target.closest('[data-rp]'); if (!b || !body.contains(b)) return;
    var a = b.getAttribute('data-rp');
    if (a === 'fold') setOpen(false);
    else if (a === 'refresh') { cacheFor = null; renderSection(true); }
    else if (a === 'new') newMenu(b);
    else if (a === 'range') rangeMenu(b);
  }
  function rangeMenu(anchor) {
    var items = RANGES.map(function (r) {
      return { icon: r[0] === S.days ? '✓' : '·', label: r[1], run: function () { setDays(r[0]).then(function () { if (KT.savedToast) KT.savedToast(); }).catch(function () { toast('Could not save that setting.', 'error'); }); } };
    });
    if (KT.quickPop) KT.quickPop({ anchor: anchor, items: items, label: 'Days ahead' });
  }
  function onOutside(e) {
    if (!root || !isOpen() || pushMode()) return;
    if (root.contains(e.target)) return;
    if (e.target.closest && e.target.closest('.kt-qp,.kt-qp-dim,.modal-backdrop,.kt-modal-backdrop,[role="dialog"]')) return;
    setOpen(false);
  }

  /* ── layout state ─────────────────────────────────────────────────── */
  var narrowOpen = false;   // 1200-1439: open only after a click, never by itself
  function pushMode() { return w.innerWidth >= PUSH_W; }
  function isOpen() { return pushMode() ? S.open : narrowOpen; }
  function setOpen(v) {
    if (pushMode()) { S.open = !!v; save(); } else { narrowOpen = !!v; if (v) { S.open = true; save(); } else if (!v) { /* folding the overlay is not "I want it folded" on a wide screen */ } }
    apply(true);
    if (v) renderSection();
  }
  var lastOn = null, lastPush = null;
  function apply(fromUser) {
    var on = eligible();
    if (!on) {
      if (root) d.body.classList.remove('kt-rp-on', 'kt-rp-open', 'kt-rp-push');
      if (lastOn !== false) { lastOn = false; relayout(); }
      return;
    }
    build();
    if (sections().indexOf(S.section) === -1) S.section = sections()[0];
    var open = isOpen(), push = open && pushMode();
    var cl = d.body.classList;
    var changed = !cl.contains('kt-rp-on') || cl.contains('kt-rp-open') !== open || cl.contains('kt-rp-push') !== push;
    cl.add('kt-rp-on'); cl.toggle('kt-rp-open', open); cl.toggle('kt-rp-push', push);
    drawRail();
    if (titleEl) titleEl.textContent = LABEL[S.section];
    if (changed || lastOn !== true || lastPush !== push) { lastOn = true; lastPush = push; relayout(); }
    if (open && !fromUser && !scroller.firstChild) renderSection();
  }
  // Screens size themselves on resize (tab bands' arrows, charts, tables): tell them the column changed.
  var rlT = null;
  function relayout() { clearTimeout(rlT); rlT = setTimeout(function () { try { w.dispatchEvent(new Event('resize')); } catch (e) {} }, 230); }

  /* ── sections ─────────────────────────────────────────────────────── */
  var renderSeq = 0;
  function renderSection(force) {
    if (!scroller || !isOpen()) return;
    var seq = ++renderSeq, s = S.section;
    if (titleEl) titleEl.textContent = LABEL[s];
    if (!scroller.firstChild || scroller.getAttribute('data-sec') !== s) scroller.innerHTML = '<div class="kt-rp-skel"></div><div class="kt-rp-skel"></div><div class="kt-rp-skel"></div>';
    scroller.setAttribute('data-sec', s);
    var done = function (html) { if (seq !== renderSeq || S.section !== s) return; scroller.innerHTML = html; wire(s); };
    var fail = function (e) { if (seq !== renderSeq) return; scroller.innerHTML = '<div class="kt-rp-err">' + esc(said(e, 'Could not load this right now.')) + '</div>'; };
    try {
      if (s === 'calendar') calendarHtml(force).then(done, fail);
      else if (s === 'meetings') meetingsHtml(force).then(done, fail);
      else if (s === 'tasks') tasksHtml(force).then(done, fail);
      else if (s === 'today') todayHtml(force).then(done, fail);
    } catch (e) { fail(e); }
  }

  // Calendar: month grid + the picked day's agenda (meetings, centre dates, tasks due).
  function monthRange(m) { var p = m.split('-'); var first = m + '-01'; var last = new Date(Date.UTC(+p[0], +p[1], 0)).toISOString().slice(0, 10); return [first, last]; }
  function calendarHtml(force) {
    if (!S.month) S.month = today().slice(0, 7);
    if (!S.sel) S.sel = today();
    var r = monthRange(S.month), t0 = today(), aheadTo = addDays(t0, S.days - 1);
    // One fetch covers the month on show and the Coming up days (which may run into next month).
    var from = r[0] < t0 ? r[0] : t0, to = r[1] > aheadTo ? r[1] : aheadTo;
    if (force) { cache(); C.at = {}; }
    return Promise.all([getMeetings(from, to), getOverlays(from, to), getTasks(force).catch(function () { return { mine: [], byMe: [] }; }), getSales(from, to), getExternal(from, to)]).then(function (x) {
      var ms = x[0], ev = x[1], tk = x[2], sl = x[3], xs = x[4];
      var by = {};
      var put = function (day, kind, item) { (by[day] = by[day] || { m: [], e: [], t: [], s: [], x: [] })[kind].push(item); };
      xs.forEach(function (e) { var dd = e.date, g = 0; while (dd <= e.last && g++ < 40) { put(dd, 'x', e); dd = addDays(dd, 1); } });
      ms.forEach(function (m) { var dd = m.date; var end = m.end_date || m.date; var g = 0; while (dd <= end && g++ < 40) { put(dd, 'm', m); dd = addDays(dd, 1); } });
      ev.forEach(function (e) { if (e.date) put(e.date, 'e', e); });
      tk.mine.concat(tk.byMe).forEach(function (t) { if (t.due_date) put(String(t.due_date).slice(0, 10), 't', t); });
      sl.forEach(function (s) { put(s.date, 's', s); });
      C.calBy = by;
      var p = S.month.split('-'), y = +p[0], mo = +p[1];
      var first = new Date(Date.UTC(y, mo - 1, 1)), start = addDays(r[0], -first.getUTCDay());
      var h = '<div class="kt-rp-sec"><div class="kt-rp-cal-top">'
        + '<span class="kt-rp-cal-m">' + esc(fmtD(r[0], { month: 'long', year: 'numeric' })) + '</span>'
        + '<button type="button" class="kt-rp-ib" data-cal="prev" aria-label="Previous month" data-kttip="' + esc(T('Previous month')) + '">' + svg('prev', 16) + '</button>'
        + '<button type="button" class="kt-rp-btn ghost sm" data-cal="today">Today</button>'
        + '<button type="button" class="kt-rp-ib" data-cal="next" aria-label="Next month" data-kttip="' + esc(T('Next month')) + '">' + svg('next', 16) + '</button></div>'
        + '<div class="kt-rp-cal" role="grid">';
      for (var i = 0; i < 7; i++) h += '<span class="kt-rp-cal-w">' + esc(fmtD(addDays(start, i), { weekday: 'narrow' })) + '</span>';
      var t = today();
      for (var k = 0; k < 42; k++) {
        var day = addDays(start, k);
        if (k >= 35 && day.slice(0, 7) !== S.month) break;
        var b = by[day], dots = '';
        if (b) { if (b.m.length) dots += '<s></s>'; if (b.x.length) dots += '<s class="xc"></s>'; if (b.s.length) dots += '<s class="sl"></s>'; if (b.e.length) dots += '<s class="ev"></s>'; if (b.t.length) dots += '<s class="tk"></s>'; }
        h += '<button type="button" class="kt-rp-cal-d' + (day.slice(0, 7) !== S.month ? ' out' : '') + (day === t ? ' today' : '') + (day === S.sel ? ' sel' : '') + '" data-day="' + day + '" aria-label="' + esc(fmtD(day, { weekday: 'long', month: 'long', day: 'numeric' })) + '">' + (+day.slice(8)) + (dots ? '<i>' + dots + '</i>' : '') + '</button>';
      }
      h += '</div></div><div class="kt-rp-sec" id="kt-rp-agenda">' + agendaHtml(S.sel) + '</div>'
        + '<div class="kt-rp-sec" id="kt-rp-ahead">' + aheadHtml() + xcalFooter() + '</div>';
      return h;
    });
  }
  /* ── THE AGENDA, LIKE OUTLOOK (2026-11-25, Anthony's screenshot) ─────────────────────────────────────
     "Today • Wed, Nov 25" day headers; a time column (start time, the length under it, or All day); a bar coloured
     by kind (meeting teal, sales pink, centre dates amber, tasks purple) - filled when it's on, an outline when it
     still needs a reply or a decision; the title on one line and one line under it. Today's finished events drop
     off ("No more events scheduled today"), and the next one gets an "Up next" pill and a teal tint. */
  var SALES_TZ = 'America/Toronto';
  function clock(iso, tz) {
    try { if (!platformView() && KT.fmtTime) { var x = KT.fmtTime(iso); if (x) return x; } } catch (e) {}
    try { return new Intl.DateTimeFormat(lang(), { hour: 'numeric', minute: '2-digit', timeZone: tz || SALES_TZ }).format(new Date(iso)); } catch (e) { return ''; }
  }
  function dur(min) {
    if (!(min > 0)) return '';
    var L = lang();
    try {
      var nf = function (n, unit, disp) { return new Intl.NumberFormat(L, { style: 'unit', unit: unit, unitDisplay: disp }).format(n); };
      var en = !L || /^en/.test(L);   // Outlook's own "45 min" (Intl says "45 mins" in English)
      if (min < 60) return en ? min + ' min' : nf(min, 'minute', 'short');
      var h = Math.floor(min / 60), m = min % 60;
      return m ? nf(h, 'hour', 'narrow') + ' ' + (en ? m + ' min' : nf(m, 'minute', 'short')) : nf(h, 'hour', 'long');
    } catch (e) { return min + ' min'; }
  }
  function dayHead(day) {
    var t = today(), pre = day === t ? T('Today') : (day === addDays(t, 1) ? T('Tomorrow') : '');
    return '<div class="kt-rp-dh">' + (pre ? '<b>' + esc(pre) + '</b><i>•</i>' : '') + '<span>' + esc(fmtD(day, { weekday: 'short', month: 'short', day: 'numeric' })) + '</span></div>';
  }
  // One day's items, all-day first, then by start. map = { day: { m, e, t, s } }.
  function itemsFor(day, map) {
    var b = (map || {})[day] || {}, out = [];
    (b.m || []).forEach(function (m) {
      var s = Date.parse(m.start), e = Date.parse(m.end), all = !!m.all_day || (m.date !== day);
      var sales = salesOn() && !!(m.lead_id || m.booking_id);
      var sub = m.video === 'teams' ? T('Microsoft Teams meeting') : (m.location || (m.join_url ? T('Video meeting') : ''));
      if (!m.is_organizer && m.organizer && m.organizer.name) sub = sub ? sub + ' · ' + m.organizer.name : m.organizer.name;
      out.push({ k: sales ? 'sl' : 'mt', all: all, s: s, e: e, time: all ? '' : clock(m.start, m.timezone), dur: all ? '' : dur(Math.round((e - s) / 60000)),
        title: m.title, sub: sub, tent: !m.is_organizer && (m.my_response === 'pending' || m.my_response === 'tentative'),
        tip: m.my_response === 'pending' && !m.is_organizer ? T('Reply needed') : '', attr: 'data-meeting="' + m.id + '"', join: m.join_url });
    });
    (b.s || []).forEach(function (x) {
      var s = x.startIso ? Date.parse(x.startIso) : NaN, e = x.endIso ? Date.parse(x.endIso) : s;
      out.push({ k: 'sl', all: !!x.all || isNaN(s), s: s, e: e, time: x.all || isNaN(s) ? '' : clock(x.startIso, x.tz), dur: x.all || isNaN(s) ? '' : dur(Math.round((e - s) / 60000)),
        title: x.label, sub: T('Sales') + (x.sub ? ' · ' + x.sub : ''), tent: !!x.tent, attr: 'data-go="' + esc(x.go) + '"', join: x.join });
    });
    (b.x || []).forEach(function (x) {
      var s = x.startIso ? Date.parse(x.startIso) : NaN, e = x.endIso ? Date.parse(x.endIso) : s, all = !!x.all || isNaN(s) || x.date !== day;
      out.push({ k: 'xc', all: all, s: s, e: e, time: all ? '' : clock(x.startIso, x.tz), dur: all ? '' : dur(Math.round((e - s) / 60000)),
        title: x.label, sub: x.sub, tent: !!x.tent, priv: !!x.priv, rec: !!x.rec, join: x.join,
        attr: x.web && /^https:\/\//i.test(x.web) ? 'data-xlink="' + esc(x.web) + '"' : '' });
    });
    (b.e || []).forEach(function (x) {
      out.push({ k: 'ev', all: true, title: (x.icon ? x.icon + ' ' : '') + (x.title || ''), sub: x.detail || '', attr: 'data-go="staff-calendar"' });
    });
    (b.t || []).forEach(function (x) {
      out.push({ k: 'tk', all: true, allLabel: T('Due'), title: x.title, sub: T('Task') + (x.centre_name ? ' · ' + x.centre_name : ''), priv: !!x.is_private,
        attr: tasksHash() ? 'data-go="' + tasksHash() + '"' : 'data-rp-sec-go="tasks"' });
    });
    return out.sort(function (a, c) { if (a.all !== c.all) return a.all ? -1 : 1; return (a.s || 0) - (c.s || 0); });
  }
  function evRow(it, next) {
    var tc = it.all ? '<span class="kt-rp-t1">' + esc(it.allLabel || T('All day')) + '</span>'
      : '<span class="kt-rp-t1">' + esc(it.time) + '</span>' + (it.dur ? '<span class="kt-rp-t2">' + esc(it.dur) + '</span>' : '');
    return (next ? '<span class="kt-rp-next">' + svg('clock', 12) + esc(T('Up next')) + '</span>' : '')
      + '<div class="kt-rp-ev' + (next ? ' next' : '') + '" role="button" tabindex="0" ' + it.attr + '>'
      + '<span class="kt-rp-vb ' + it.k + (it.tent ? ' tent' : '') + '"' + (it.tip ? ' data-kttip="' + esc(it.tip) + '"' : '') + '></span>'
      + '<span class="kt-rp-tc">' + tc + '</span>'
      + '<span class="kt-rp-ec"><b data-no-i18n data-kttip="' + esc(it.title) + '">' + esc(it.title) + '</b>' + (it.sub ? '<span data-no-i18n>' + esc(it.sub) + '</span>' : '') + '</span>'
      + (it.priv ? '<span class="kt-rp-ic" data-kttip="' + esc(T('Private')) + '">' + svg('lock', 13) + '</span>' : '')
      + (it.rec ? '<span class="kt-rp-ic" data-kttip="' + esc(T('Repeats')) + '">' + svg('repeat', 13) + '</span>' : '')
      + (it.join && /^https?:\/\//i.test(it.join) ? '<a class="kt-rp-ic join" href="' + esc(it.join) + '" target="_blank" rel="noopener" data-join="1" data-kttip="' + esc(T('Join')) + '" aria-label="Join">' + svg('video', 14) + '</a>' : '')
      + '</div>';
  }
  /* Days in order. Today: finished timed events drop off; nothing left = "No more events scheduled today".
     opts: { map, keepEmpty (show a day even with nothing), addToday (the "+ Add a task due today" row) }. */
  function daysHtml(days, opts) {
    var now = Date.now(), t = today(), nextDone = false, h = '', shown = 0, count = 0;
    days.forEach(function (day) {
      var its = itemsFor(day, opts.map);
      if (day === t) its = its.filter(function (it) { return it.all || !(it.e <= now); });
      if (day !== t && !its.length && !opts.keepEmpty) return;
      shown++;
      h += '<div class="kt-rp-day">' + dayHead(day);
      if (!its.length) {
        if (day === t) h += '<div class="kt-rp-none">' + esc(T('No more events scheduled today')) + '</div>'
          + (canMeet() ? '<button type="button" class="kt-rp-link kt-rp-cne" data-new-meeting="' + t + '">' + esc(T('Create new event')) + '</button>' : '');
        else h += '<div class="kt-rp-none">' + esc(T('Nothing scheduled.')) + '</div>';
      }
      count += its.length;
      its.forEach(function (it) { var nx = !nextDone && !it.all && it.e > now; if (nx) nextDone = true; h += evRow(it, nx); });
      if (day === t && opts.addToday) h += '<button type="button" class="kt-rp-addt" data-rp-addtoday="1">' + svg('plus', 15) + '<span>' + esc(T('Add a task due today')) + '</span></button>';
      h += '</div>';
    });
    return { html: h, days: shown, items: count };
  }
  // A picked day other than today shows on its own; the Coming up list always starts today.
  function agendaHtml(day) {
    if (day === today()) return '';
    return daysHtml([day], { map: C.calBy, keepEmpty: true }).html
      + (canMeet() ? '<div class="kt-rp-row" style="margin:2px 0 4px"><button type="button" class="kt-rp-btn sm" data-new-meeting="' + day + '">' + svg('plus', 13) + ' New meeting</button></div>' : '');
  }
  function rangeDays(n) { var t = today(), a = []; for (var i = 0; i < n; i++) a.push(addDays(t, i)); return a; }
  // Coming up: today, then each day through the person's "days ahead" that has something on it.
  function aheadHtml() {
    var n = S.days, h = '<div class="kt-rp-row kt-rp-ahead-h"><span>' + esc(T('Coming up')) + ' · ' + esc(T(rangeLabel(n))) + '</span><button type="button" class="kt-rp-link" data-rp-range="1">' + esc(T('Change')) + '</button></div>';
    var r = daysHtml(rangeDays(n), { map: C.calBy, addToday: true });
    h += r.html;
    if (r.days < 2 && n > 1) h += '<div class="kt-rp-none" style="margin-top:8px">' + esc(T('Nothing else coming up.')) + '</div>';
    h += '<div class="kt-rp-row" style="margin-top:8px"><button type="button" class="kt-rp-link" data-go="' + calHash() + '">Open calendar</button></div>';
    return h;
  }
  function calHash() { return platformView() ? 'sales-calendar' : 'staff-calendar'; }
  function canMeet() { return platformView() ? (isPlatformTeam() || hasRole('sales_rep')) : true; }
  // The Tasks screen exists for admins, directors and super admins; a sales rep's to-dos live in the panel only.
  function tasksHash() { return roleNow() === 'sales' ? '' : 'tasks'; }

  // Meetings: the next "days ahead", by day (plus sales bookings and follow-ups for the sales team).
  function meetingsHtml(force) {
    var t = today(), to = addDays(t, S.days - 1);
    if (force) { cache(); C.at = {}; }
    return Promise.all([getMeetings(t, to), getSales(t, to), getExternal(t, to)]).then(function (x) {
      var map = {};
      var put = function (day, k, it) { (map[day] = map[day] || { m: [], s: [], x: [] })[k].push(it); };
      x[2].forEach(function (e) { var dd = e.date < t ? t : e.date, g = 0; while (dd <= e.last && dd <= to && g++ < 40) { put(dd, 'x', e); dd = addDays(dd, 1); } });
      x[0].forEach(function (m) { var dd = m.date < t ? t : m.date, end = m.end_date || m.date, g = 0; while (dd <= end && dd <= to && g++ < 40) { put(dd, 'm', m); dd = addDays(dd, 1); } });
      x[1].forEach(function (s) { put(s.date, 's', s); });
      var h = '<div class="kt-rp-sec"><div class="kt-rp-row" style="margin-bottom:4px;justify-content:space-between">' + (canMeet() ? '<button type="button" class="kt-rp-btn sm" data-new-meeting="' + t + '">' + svg('plus', 13) + ' New meeting</button>' : '<span></span>')
        + '<button type="button" class="kt-rp-link" data-rp-range="1">' + esc(T(rangeLabel(S.days))) + '</button></div>';
      var r = daysHtml(rangeDays(S.days), { map: map });
      h += r.html;
      if (!r.items) h += '<div class="kt-rp-none" style="margin-top:8px">' + esc(T('No meetings coming up.')) + '</div>';
      h += '<div class="kt-rp-row" style="margin-top:8px"><button type="button" class="kt-rp-link" data-go="' + calHash() + '">Open calendar</button></div>' + xcalFooter() + '</div>';
      return h;
    });
  }

  // Tasks: assigned to me, then the open tasks I assigned. Tick = done. Quick add assigns one.
  function dueChip(t) {
    if (!t.due_date) return '';
    var dd = String(t.due_date).slice(0, 10), td = today();
    var cls = dd < td ? ' late' : (dd === td ? ' now' : '');
    var lbl = dd < td ? T('Overdue') + ' · ' + fmtD(dd, { month: 'short', day: 'numeric' }) : (dd === td ? T('Due today') : T('Due') + ' ' + fmtD(dd, { month: 'short', day: 'numeric' }));
    return '<span class="kt-rp-due' + cls + '">' + esc(lbl) + '</span>';
  }
  function taskItem(t, who) {
    return '<div class="kt-rp-item" data-task="' + t.id + '"><button type="button" class="kt-rp-chk" role="checkbox" aria-checked="false" data-done="' + t.id + '" aria-label="Mark done: ' + esc(t.title) + '" data-kttip="' + esc(T('Mark done')) + '"><svg viewBox="0 0 24 24" width="12" height="12" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m5 12.5 4.5 4.5L19 7.5"/></svg></button>'
      + '<span style="flex:1;min-width:0"' + (tasksHash() ? ' role="button" tabindex="0" data-go="' + tasksHash() + '"' : '') + '><b data-no-i18n>' + esc(t.title) + '</b>'
      + (t.is_private ? '<span class="kt-rp-sub">' + esc(T('Private - only you can see it')) + '</span>'
        : '<span class="kt-rp-sub">' + esc(who === 'mine' ? (t.assigned_by_name ? T('From') + ' ' + t.assigned_by_name : '') : (t.assigned_to_name ? T('For') + ' ' + t.assigned_to_name : '')) + (t.centre_name ? ' · ' + esc(t.centre_name) : '') + '</span>')
      + dueChip(t) + '</span></div>';
  }
  function tasksHtml(force) {
    return getTasks(force).then(function (tk) {
      var h = '<div class="kt-rp-sec">';
      // "Me" first and chosen: a private to-do. Admins and directors can pick someone else instead.
      h += '<form class="kt-rp-add" id="kt-rp-add" autocomplete="off"><input class="kt-rp-in" name="title" maxlength="200" placeholder="Add a task…" aria-label="Add a task">'
        + '<div class="kt-rp-add-more"><select class="kt-rp-sel" name="to" aria-label="Assign to"><option value="me" selected>' + esc(T('Me (private)')) + '</option></select>'
        + '<input class="kt-rp-in" type="date" name="due" aria-label="Due date"></div>'
        + '<div class="kt-rp-add-go"><button type="button" class="kt-rp-btn ghost sm" data-add="cancel">Cancel</button><button type="submit" class="kt-rp-btn sm">Add task</button></div></form>';
      var priv = tk.mine.filter(function (t) { return t.is_private; }), given = tk.mine.filter(function (t) { return !t.is_private; });
      h += '<h4>My to-dos</h4>';
      if (!priv.length) h += '<div class="kt-rp-muted">' + esc(T('Nothing on your list.')) + '</div>';
      priv.forEach(function (t) { h += taskItem(t, 'mine'); });
      if (given.length) { h += '<h4>Assigned to me</h4>'; given.forEach(function (t) { h += taskItem(t, 'mine'); }); }
      if (canAssign()) {
        h += '<h4>Assigned by me</h4>';
        if (!tk.byMe.length) h += '<div class="kt-rp-muted">No open tasks you assigned.</div>';
        tk.byMe.slice(0, 40).forEach(function (t) { h += taskItem(t, 'byme'); });
      }
      if (tasksHash()) h += '<div class="kt-rp-row"><button type="button" class="kt-rp-link" data-go="tasks">All tasks</button></div>';
      h += '</div>';
      return h;
    });
  }

  // Today at the centre.
  function todayHtml(force) {
    return getToday(force).then(function (t) {
      var dash = t.dash || {}, st = t.dir ? (dash.stats || {}) : (dash.totals || {});
      var present = st.present_now, enrolled = t.dir ? st.total_enrolled : st.enrolled, staff = st.staff_on_floor;
      var ratio = [], check = [];
      // A director's rooms carry the ratio engine's state: breach / over_group are ratio alerts (as the agency count's
      // "rooms over ratio"); attention is "check staffing" (one adult where two are needed, no RECE in the group).
      // Admins: the same engine, room by room, across every centre they can see (grouped by centre below).
      var rooms = t.dir ? (dash.rooms || []) : ((t.rooms && t.rooms.data) || []).map(function (r) {
        return { room_name: r.room_name, centre: r.centre_name, status: r.status, children_present: r.children_present, educators_present: r.staff_present, ratio_target: r.standard && r.standard.ratio, warnings: r.warnings };
      });
      rooms.forEach(function (r) {
        var n = T('Children') + ' ' + (r.children_present || 0) + ' · ' + T('Staff') + ' ' + (r.educators_present || 0);
        if (r.status === 'breach' || r.status === 'over_group') ratio.push({ name: r.room_name, centre: r.centre, sub: T(r.status === 'over_group' ? 'Over group size' : 'Over ratio') + ' · ' + n + (r.ratio_target ? ' · ' + r.ratio_target : '') });
        else if (r.status === 'attention') check.push({ name: r.room_name, centre: r.centre, sub: (r.warnings && r.warnings[0]) || n });
      });
      var ratioKnown = t.dir ? !!t.dash : !!t.rooms;
      var sa = t.sa, saOn = !!(sa && sa.settings && sa.settings.enabled), over = sa && sa.counts ? (sa.counts.overdue || 0) : 0;
      var late = sa && sa.rows ? sa.rows.filter(function (r) { return r.state === 'overdue'; }) : [];
      var h = '<div class="kt-rp-sec"><div class="kt-rp-stats">'
        + stat(present == null ? '–' : present, 'At Centre', 'headcounts', '', enrolled)
        + stat(staff == null ? '–' : staff, 'Staff on floor', 'dashboard', '')
        + stat(!sa ? '–' : (saOn ? over : 'Off'), saOn ? 'Not arrived' : 'Safe Arrival', 'safe-arrival', saOn ? (over ? 'warn' : 'ok') : '')
        + stat(ratioKnown ? ratio.length : '–', 'Ratio alerts', 'room-ratios', ratioKnown ? (ratio.length ? 'bad' : 'ok') : '')
        + '</div>';
      if (saOn && late.length) {
        h += '<h4>Not arrived</h4>';
        late.slice(0, 6).forEach(function (r) {
          h += '<button type="button" class="kt-rp-item" data-go="safe-arrival"><span class="kt-rp-bar ev"></span><span><b data-no-i18n>' + esc(shortName(r.name)) + '</b><span class="kt-rp-sub">'
            + esc([r.room_name, r.expected ? T('Expected') + ' ' + r.expected : ''].filter(Boolean).join(' · ')) + '</span></span></button>';
        });
        if (late.length > 6) h += '<button type="button" class="kt-rp-link" data-go="safe-arrival">+ ' + (late.length - 6) + ' ' + esc(T('more')) + '</button>';
      }
      var list = function (title, rows, colour) {
        if (!rows.length) return;
        h += '<h4>' + esc(title) + '</h4>';
        var lastC = null;
        rows.slice(0, 12).forEach(function (r) {
          if (r.centre && r.centre !== lastC) { lastC = r.centre; h += '<div class="kt-rp-ctr" data-no-i18n>' + esc(r.centre) + '</div>'; }
          h += '<button type="button" class="kt-rp-item" data-go="room-ratios"><span class="kt-rp-bar" style="background:' + colour + '"></span><span><b data-no-i18n>' + esc(r.name) + '</b><span class="kt-rp-sub">' + esc(r.sub) + '</span></span></button>';
        });
        if (rows.length > 12) h += '<button type="button" class="kt-rp-link" data-go="room-ratios">+ ' + (rows.length - 12) + ' ' + esc(T('more')) + '</button>';
      };
      list('Ratio alerts', ratio, '#DC2626');
      list('Check staffing', check, '#F59E0B');
      var at = t.at; h += '<div class="kt-rp-muted" style="margin-top:6px">' + esc(T('Updated')) + ' ' + esc(KT.fmtTime ? KT.fmtTime(at.toISOString()) : at.toLocaleTimeString([], { hour: 'numeric', minute: '2-digit' })) + '</div></div>';
      return h;
    });
  }
  function stat(n, label, go, tone, of) { return '<button type="button" class="kt-rp-stat' + (tone ? ' ' + tone : '') + '" data-go="' + go + '"><b data-no-i18n>' + esc(n) + (of != null ? '<small style="font-size:12px;font-weight:700;opacity:.6"> / ' + esc(of) + '</small>' : '') + '</b><span>' + esc(label) + '</span></button>'; }
  function shortName(n) { var p = String(n || '').trim().split(/\s+/); return p.length > 1 ? p[0] + ' ' + p[p.length - 1].charAt(0) + '.' : p[0] || ''; }

  /* ── interactions inside a section ────────────────────────────────── */
  function go(hash) { if (!hash) return; if (!pushMode()) setOpen(false); location.hash = '#' + hash; }
  function wire(s) {
    if (s === 'tasks') wireAdd();
  }
  function onScrollClick(e) {
    var t = e.target;
    if (t.closest('[data-join]')) { e.stopPropagation(); return; }
    var nm = t.closest('[data-new-meeting]'); if (nm) { newMeeting(nm.getAttribute('data-new-meeting')); return; }
    var rg = t.closest('[data-rp-range]'); if (rg) { rangeMenu(rg); return; }
    var at = t.closest('[data-rp-addtoday]'); if (at) { addToday(at); return; }
    var sg = t.closest('[data-rp-sec-go]'); if (sg) { S.section = sg.getAttribute('data-rp-sec-go'); save(); apply(true); renderSection(); return; }
    var cal = t.closest('[data-cal]');
    if (cal) {
      var a = cal.getAttribute('data-cal'), p = S.month.split('-'), y = +p[0], m = +p[1];
      if (a === 'today') { S.month = today().slice(0, 7); S.sel = today(); }
      else { m += a === 'next' ? 1 : -1; if (m > 12) { m = 1; y++; } if (m < 1) { m = 12; y--; } S.month = y + '-' + pad(m); }
      renderSection(); return;
    }
    var day = t.closest('[data-day]');
    if (day) {
      S.sel = day.getAttribute('data-day');
      if (S.sel.slice(0, 7) !== S.month) { S.month = S.sel.slice(0, 7); renderSection(); return; }
      [].forEach.call(scroller.querySelectorAll('.kt-rp-cal-d.sel'), function (x) { x.classList.remove('sel'); });
      day.classList.add('sel');
      var ag = scroller.querySelector('#kt-rp-agenda'); if (ag) ag.innerHTML = agendaHtml(S.sel);
      return;
    }
    var ck = t.closest('.kt-rp-chk'); if (ck) { tickDone(ck); return; }
    var xl = t.closest('[data-xlink]');
    if (xl) { var wn = null; try { wn = w.open(xl.getAttribute('data-xlink'), '_blank'); if (wn) wn.opener = null; } catch (er) {} return; }
    var mt = t.closest('[data-meeting]');
    if (mt) { if (KT.Meeting && KT.Meeting.view) KT.Meeting.view(+mt.getAttribute('data-meeting'), { onChange: function () { cacheFor = null; renderSection(true); } }); return; }
    var g = t.closest('[data-go]'); if (g) { go(g.getAttribute('data-go')); return; }
    var ad = t.closest('[data-add="cancel"]'); if (ad) { var f = scroller.querySelector('#kt-rp-add'); if (f) { f.reset(); f.classList.remove('open'); } }
  }
  function onScrollKey(e) {
    if (e.key !== 'Enter' && e.key !== ' ') return;
    var r = e.target.closest('[role="button"]'); if (!r || e.target.tagName === 'INPUT') return;
    e.preventDefault(); r.click();
  }
  function tickDone(c) {
    if (c.getAttribute('aria-checked') === 'true') return;
    var id = +c.getAttribute('data-done'), row = c.closest('.kt-rp-item');
    c.setAttribute('aria-checked', 'true'); c.disabled = true; if (row) row.style.opacity = '.55';
    api().patch('/tasks/' + id + '/status', { status: 'done' }).then(function () {
      toast('Task done');
      if (C.tasks) { C.tasks.mine = C.tasks.mine.filter(function (x) { return x.id !== id; }); C.tasks.byMe = C.tasks.byMe.filter(function (x) { return x.id !== id; }); }
      if (row) row.remove();
      try { w.dispatchEvent(new CustomEvent('kt:tasks-changed')); } catch (e2) {}
    }).catch(function (err) { c.setAttribute('aria-checked', 'false'); c.disabled = false; if (row) row.style.opacity = ''; toast(said(err, 'Could not update the task.'), 'error'); });
  }
  // "+ Add a task due today" under Today: a private to-do of mine, due today. Enter adds it; Escape or leaving it empty closes it.
  function addToday(btn) {
    var f = d.createElement('form'); f.className = 'kt-rp-qt'; f.setAttribute('autocomplete', 'off');
    f.innerHTML = '<input class="kt-rp-in" name="title" maxlength="200" placeholder="' + esc(T('Add a task due today')) + '" aria-label="' + esc(T('Add a task due today')) + '">'
      + '<button type="submit" class="kt-rp-btn sm">' + esc(T('Add')) + '</button>';
    btn.replaceWith(f);
    var inp = f.querySelector('input'); inp.focus();
    var close = function () { if (f.isConnected) renderSection(); };
    inp.addEventListener('keydown', function (e) { if (e.key === 'Escape') { e.stopPropagation(); close(); } });
    inp.addEventListener('blur', function () { setTimeout(function () { if (f.isConnected && !inp.value.trim() && d.activeElement !== f.querySelector('button')) close(); }, 150); });
    f.addEventListener('submit', function (e) {
      e.preventDefault();
      var tt = inp.value.trim(); if (!tt) { inp.focus(); return; }
      var b = f.querySelector('button'); b.disabled = true;
      api().post('/tasks', { private: 1, title: tt, due_date: today(), priority: 'normal' }).then(function () {
        toast('Task added');
        try { w.dispatchEvent(new CustomEvent('kt:tasks-changed')); } catch (e2) {}
      }).catch(function (err) { b.disabled = false; toast(said(err, 'Could not add the task.'), 'error'); });
    });
  }
  function wireAdd() {
    var f = scroller.querySelector('#kt-rp-add'); if (!f) return;
    var title = f.querySelector('[name=title]'), sel = f.querySelector('[name=to]'), filled = false;
    var fill = function () {
      if (filled || !canAssign()) return; filled = true;
      getAssignees().then(function (list) {
        var groups = {};
        list.forEach(function (a) { (groups[a.role_label] = groups[a.role_label] || []).push(a); });
        Object.keys(groups).forEach(function (g) {
          var og = d.createElement('optgroup'); og.label = g;
          groups[g].forEach(function (a) { var o = d.createElement('option'); o.value = a.id; o.textContent = a.name + (a.centre_name ? ' · ' + a.centre_name : ''); og.appendChild(o); });
          sel.appendChild(og);
        });
      }).catch(function () { filled = false; });
    };
    var btnLbl = function () { var b = f.querySelector('[type=submit]'); if (b) b.textContent = T(sel.value === 'me' ? 'Add task' : 'Assign task'); };
    sel.addEventListener('change', btnLbl);
    title.addEventListener('focus', function () { f.classList.add('open'); fill(); });
    title.addEventListener('input', function () { f.classList.add('open'); fill(); });
    sel.addEventListener('focus', fill); sel.addEventListener('pointerdown', fill);
    f.addEventListener('submit', function (e) {
      e.preventDefault();
      var tt = (title.value || '').trim(), mine = sel.value === 'me', to = parseInt(sel.value, 10), due = f.querySelector('[name=due]').value || null;
      if (!tt) { title.focus(); return; }
      if (!mine && !to) { toast('Pick who the task is for', 'error'); sel.focus(); return; }
      var btn = f.querySelector('[type=submit]'); btn.disabled = true;
      // Me = a private to-do: nobody else sees it and nothing is sent.
      var bodyJ = mine ? { private: 1, title: tt, due_date: due, priority: 'normal' } : { assigned_to: to, title: tt, due_date: due, priority: 'normal', notify_channels: ['app'] };
      api().post('/tasks', bodyJ).then(function () {
        toast(mine ? 'Task added' : 'Task assigned');
        try { w.dispatchEvent(new CustomEvent('kt:tasks-changed')); } catch (e2) {}
        renderSection(true);
      }).catch(function (err) { btn.disabled = false; toast(said(err, 'Could not assign the task.'), 'error'); });
    });
  }
  function newMeeting(day) {
    if (!(KT.Meeting && KT.Meeting.compose)) { go(platformView() ? 'sales-calendar' : 'staff-calendar'); return; }
    KT.Meeting.compose({ scope: meetingScope(), date: day || today(), onSaved: function () { cacheFor = null; renderSection(true); } });
  }
  function newMenu(anchor) {
    var items = [];
    if (canMeet()) items.push({ icon: '📅', label: 'New meeting', run: function () { newMeeting(S.section === 'calendar' ? S.sel : today()); } });
    items.push({ icon: '☑️', label: 'New task', run: function () { S.section = 'tasks'; save(); apply(true); renderSection(); setTimeout(function () { var i = scroller.querySelector('#kt-rp-add [name=title]'); if (i) i.focus(); }, 400); } });
    if (!items.length) return;
    if (KT.quickPop) KT.quickPop({ anchor: anchor, items: items, label: 'New' }); else items[0].run();
  }

  /* ── refresh: only the open section, at most every 2 minutes ─────── */
  var lastTick = 0;
  function tick() {
    if (!root || !isOpen() || d.visibilityState !== 'visible') return;
    if (Date.now() - lastTick < 120000) return;
    lastTick = Date.now();
    if (d.activeElement && root.contains(d.activeElement) && /INPUT|SELECT|TEXTAREA/.test(d.activeElement.tagName)) return;   // never under someone's typing
    renderSection(true);
  }
  // The badges poll is the heartbeat; the listener only schedules (never fetches inside it).
  w.addEventListener('kt:badges', function () { setTimeout(tick, 0); });
  setInterval(tick, 60000);

  /* ── boot + watchers ──────────────────────────────────────────────── */
  var booted = false;
  function boot() {
    if (booted || !d.body) return;
    booted = true;
    load();
    apply();
    if (eligible()) loadServerPrefs();
    var rz = null;
    w.addEventListener('resize', function (e) { if (e && e.isTrusted === false) return; clearTimeout(rz); rz = setTimeout(function () { apply(); }, 150); });
    // A task ticked or added on the Tasks screen (or here): the panel's list is stale.
    w.addEventListener('kt:tasks-changed', function () {
      if (C.at) C.at.t = 0;
      if (isOpen() && (S.section === 'tasks' || S.section === 'calendar')) setTimeout(function () { renderSection(true); }, 50);
    });
    w.addEventListener('kt:agency-changed', function () { cacheFor = null; if (scroller) scroller.innerHTML = ''; apply(); });
    // Role (View as) and phone chrome come and go as body classes: re-check on a change.
    var last = d.body.className;
    new MutationObserver(function () {
      var now = d.body.className; if (now === last) return;
      var roleChanged = (last.match(/\brole-[a-z-]+/g) || []).join() !== (now.match(/\brole-[a-z-]+/g) || []).join();
      last = now;
      if (roleChanged) { cacheFor = null; if (scroller) scroller.innerHTML = ''; }
      if (roleChanged || !root) apply();
    }).observe(d.body, { attributes: true, attributeFilter: ['class'] });
    scrollerWire();
  }
  var wired = false;
  function scrollerWire() {
    if (wired) return;
    var t = setInterval(function () {
      if (!scroller) { if (eligible()) apply(); return; }
      clearInterval(t); wired = true;
      scroller.addEventListener('click', onScrollClick);
      scroller.addEventListener('keydown', onScrollKey);
    }, 500);
  }
  // Start after the first screen has settled, so the panel never competes with it for the network.
  var started = false;
  function start() { if (started) return; started = true; boot(); }
  w.addEventListener('kt:screen-settled', function () { setTimeout(start, 300); }, { once: true });
  setTimeout(start, 6000);
  KT.rightPanel = { open: function (s) { if (s && SECTIONS.indexOf(s) !== -1) S.section = s; setOpen(true); }, close: function () { setOpen(false); }, refresh: function () { cacheFor = null; renderSection(true); },
    // My settings > My day panel uses these (the same per-person "days ahead" as the panel's gear).
    ranges: RANGES, getDays: function () { if (!booted) load(); return S.days; }, setDays: setDays, loadDays: function () {
      return api().get('/auth/me/ui-prefs').then(function (j) { var n = okDays(j && j.prefs && j.prefs.my_day && j.prefs.my_day.days); if (n) { S.days = n; save(); } return S.days; }).catch(function () { return S.days; });
    } };
})(window, document);
