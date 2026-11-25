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
   • Super admin with no agency picked (platform view): Meetings (the KiddieTrac team calendar) and Tasks only.
   • State per person in localStorage kt_rp_<user id>: { open, section, month }.
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
    video: '<rect x="2.5" y="6" width="13" height="12" rx="2.5"/><path d="m15.5 10.5 6-3.5v10l-6-3.5"/>'
  };
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
    return '';
  }
  function phoneChrome() {
    var mn = d.getElementById('kt-mobilenav');
    return !!(mn && getComputedStyle(mn).display !== 'none');
  }
  function eligible() { return !!roleNow() && w.innerWidth >= MIN_W && !phoneChrome() && !!uid(); }
  function platformView() { return !agency(); }   // super admin, no agency picked
  function sections() { return platformView() ? ['meetings', 'tasks'] : SECTIONS; }
  function meetingScope() { return platformView() ? 'platform' : 'agency'; }

  /* ── remembered state, per person ─────────────────────────────────── */
  var S = { open: true, section: 'calendar', month: '', sel: '' };
  function key() { return 'kt_rp_' + uid(); }
  function load() {
    try { var j = JSON.parse(localStorage.getItem(key()) || 'null'); if (j && typeof j === 'object') { S.open = j.open !== false; if (SECTIONS.indexOf(j.section) !== -1) S.section = j.section; } } catch (e) {}
  }
  function save() { try { localStorage.setItem(key(), JSON.stringify({ open: S.open, section: S.section })); } catch (e) {} }

  /* ── dates (agency's) ─────────────────────────────────────────────── */
  function today() { try { if (KT.agencyToday) return KT.agencyToday(); } catch (e) {} var t = new Date(); return t.getFullYear() + '-' + pad(t.getMonth() + 1) + '-' + pad(t.getDate()); }
  function pad(n) { return (n < 10 ? '0' : '') + n; }
  function ymdParts(s) { var p = String(s).slice(0, 10).split('-'); return [+p[0], +p[1], +p[2]]; }
  function utcDate(s) { var p = ymdParts(s); return new Date(Date.UTC(p[0], p[1] - 1, p[2], 12)); }
  function addDays(s, n) { var x = utcDate(s); x.setUTCDate(x.getUTCDate() + n); return x.toISOString().slice(0, 10); }
  function fmtD(s, opts) { try { return new Intl.DateTimeFormat(undefined, Object.assign({ timeZone: 'UTC' }, opts)).format(utcDate(s)); } catch (e) { return s; } }
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
    return api().get('/meetings?scope=' + meetingScope() + '&from=' + from + '&to=' + to).then(function (j) {
      c.meetings[k] = (j && j.meetings) || []; c.at['m:' + k] = Date.now(); return c.meetings[k];
    });
  }
  function getOverlays(from, to) {
    var c = cache(), k = from + ':' + to;
    if (platformView()) return Promise.resolve([]);
    if (c.overlays[k] && fresh('o:' + k, 600000)) return Promise.resolve(c.overlays[k]);
    return api().get('/director/calendar/overlays?from=' + from + '&to=' + to).then(function (j) {
      c.overlays[k] = (j && j.events) || []; c.at['o:' + k] = Date.now(); return c.overlays[k];
    }).catch(function () { return []; });
  }
  function getTasks(force) {
    var c = cache();
    if (!force && c.tasks && fresh('t', 120000)) return Promise.resolve(c.tasks);
    var mine = api().get('/tasks/mine').then(function (j) { return (j && j.tasks) || []; }).catch(function () { return []; });
    var all = platformView() ? Promise.resolve([]) : api().get('/tasks').then(function (j) { return (j && j.tasks) || []; }).catch(function () { return []; });
    return Promise.all([mine, all]).then(function (r) {
      var id = +uid();
      var open = function (t) { return t.status === 'open' || t.status === 'in_progress'; };
      c.tasks = { mine: r[0].filter(open), byMe: r[1].filter(function (t) { return open(t) && +t.assigned_by === id; }) };
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
    return Promise.all([dash, sa]).then(function (r) {
      var t = { dir: dir, dash: r[0], sa: r[1], at: new Date() };
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
      ['.kt-rp-cal-d.sel', 'background:#0F766E;color:#fff;'],
      ['.kt-rp-in,.kt-rp-sel', 'background:#0E1A2E;border-color:#2A3B5A;color:#E2E8F0;'],
      ['.kt-rp-stat', 'background:#162238;border-color:#22324F;'],
      ['.kt-rp-stat b', 'color:#F1F5F9;'],
      ['.kt-rp-btn.ghost', 'background:#162238;border-color:#2A3B5A;color:#E2E8F0;'],
      ['.kt-rp-link', 'color:#5EEAD4;'],
      ['.kt-rp-due', 'background:#1E2B44;color:#CBD5E1;']
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
      + '.kt-rp-bar.ev{background:#F59E0B;}.kt-rp-bar.tk{background:#6366F1;}.kt-rp-bar.pf{background:#7C3AED;}'
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
      + '.kt-rp-cal-d.today{box-shadow:inset 0 0 0 1.5px #0F766E;font-weight:800;}'
      + '.kt-rp-cal-d.sel{background:#0F766E;color:#fff;}'
      + '.kt-rp-cal-d i{position:absolute;left:50%;bottom:4px;transform:translateX(-50%);display:flex;gap:2px;}'
      + '.kt-rp-cal-d i s{width:4px;height:4px;border-radius:50%;background:#2A8F9D;display:block;}'
      + '.kt-rp-cal-d i s.ev{background:#F59E0B;}.kt-rp-cal-d i s.tk{background:#6366F1;}'
      + '.kt-rp-cal-d.sel i s{background:#fff;}'
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
    var nb = body && body.querySelector('[data-rp="new"]'); if (nb) nb.style.display = platformView() && !isPlatformTeam() ? 'none' : '';
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
    var r = monthRange(S.month);
    if (force) { cache(); C.at = {}; }
    return Promise.all([getMeetings(r[0], r[1]), getOverlays(r[0], r[1]), getTasks(force).catch(function () { return { mine: [], byMe: [] }; })]).then(function (x) {
      var ms = x[0], ev = x[1], tk = x[2];
      var by = {};
      var put = function (day, kind, item) { (by[day] = by[day] || { m: [], e: [], t: [] })[kind].push(item); };
      ms.forEach(function (m) { var dd = m.date; var end = m.end_date || m.date; var g = 0; while (dd <= end && g++ < 40) { put(dd, 'm', m); dd = addDays(dd, 1); } });
      ev.forEach(function (e) { if (e.date) put(e.date, 'e', e); });
      tk.mine.concat(tk.byMe).forEach(function (t) { if (t.due_date) put(String(t.due_date).slice(0, 10), 't', t); });
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
        if (b) { if (b.m.length) dots += '<s></s>'; if (b.e.length) dots += '<s class="ev"></s>'; if (b.t.length) dots += '<s class="tk"></s>'; }
        h += '<button type="button" class="kt-rp-cal-d' + (day.slice(0, 7) !== S.month ? ' out' : '') + (day === t ? ' today' : '') + (day === S.sel ? ' sel' : '') + '" data-day="' + day + '" aria-label="' + esc(fmtD(day, { weekday: 'long', month: 'long', day: 'numeric' })) + '">' + (+day.slice(8)) + (dots ? '<i>' + dots + '</i>' : '') + '</button>';
      }
      h += '</div></div><div class="kt-rp-sec" id="kt-rp-agenda">' + agendaHtml(S.sel) + '</div>';
      return h;
    });
  }
  function agendaHtml(day) {
    var b = (C.calBy || {})[day] || { m: [], e: [], t: [] };
    var h = '<h4>' + esc(dayTitle(day)) + '</h4>';
    if (!b.m.length && !b.e.length && !b.t.length) h += '<div class="kt-rp-muted">Nothing scheduled.</div>';
    b.m.slice().sort(function (a, c) { return String(a.start).localeCompare(String(c.start)); }).forEach(function (m) { h += meetingItem(m, true); });
    b.e.forEach(function (e) {
      h += '<button type="button" class="kt-rp-item" data-go="staff-calendar"><span class="kt-rp-bar ev"></span><span><b data-no-i18n>' + esc((e.icon ? e.icon + ' ' : '') + (e.title || '')) + '</b>'
        + (e.detail ? '<span class="kt-rp-sub">' + esc(e.detail) + '</span>' : '') + '</span></button>';
    });
    b.t.forEach(function (t) { h += '<button type="button" class="kt-rp-item" data-go="tasks"><span class="kt-rp-bar tk"></span><span><b data-no-i18n>' + esc(t.title) + '</b><span class="kt-rp-sub">' + esc(T('Task due')) + (t.centre_name ? ' · ' + esc(t.centre_name) : '') + '</span></span></button>'; });
    h += '<div class="kt-rp-row" style="margin-top:4px">' + (canMeet() ? '<button type="button" class="kt-rp-btn sm" data-new-meeting="' + day + '">' + svg('plus', 13) + ' New meeting</button>' : '')
      + '<button type="button" class="kt-rp-link" data-go="staff-calendar">Open calendar</button></div>';
    return h;
  }
  function canMeet() { return platformView() ? isPlatformTeam() : true; }

  // Meetings: the next 45 days, by day.
  function meetingItem(m, inDay) {
    var sub = [mTime(m)];
    if (m.location) sub.push(m.location);
    var resp = m.my_response === 'pending' && !m.is_organizer ? ' · <em style="font-style:normal;color:#B45309;font-weight:700">' + esc(T('Reply needed')) + '</em>' : '';
    return '<div class="kt-rp-item" role="button" tabindex="0" data-meeting="' + m.id + '"><span class="kt-rp-bar' + (m.scope === 'platform' ? ' pf' : '') + '"></span><span style="flex:1;min-width:0"><b data-no-i18n>' + esc(m.title) + '</b>'
      + '<span class="kt-rp-sub">' + esc(sub.join(' · ')) + resp + '</span></span>'
      + (m.join_url && /^https?:\/\//i.test(m.join_url) ? '<a class="kt-rp-btn sm" href="' + esc(m.join_url) + '" target="_blank" rel="noopener" data-join="1">' + svg('video', 13) + ' Join</a>' : '')
      + '</div>';
  }
  function meetingsHtml(force) {
    var t = today(), to = addDays(t, 45);
    if (force) { cache(); C.at = {}; }
    return getMeetings(t, to).then(function (ms) {
      var now = Date.now();
      ms = ms.filter(function (m) { return !m.end || new Date(m.end).getTime() > now; }).sort(function (a, b) { return String(a.start).localeCompare(String(b.start)); });
      var h = '<div class="kt-rp-sec">' + (canMeet() ? '<div class="kt-rp-row" style="margin-bottom:8px"><button type="button" class="kt-rp-btn sm" data-new-meeting="' + t + '">' + svg('plus', 13) + ' New meeting</button></div>' : '');
      if (!ms.length) h += '<div class="kt-rp-muted">No meetings in the next 45 days.</div>';
      var cur = '';
      ms.slice(0, 25).forEach(function (m) {
        var dd = m.date < t ? t : m.date;
        if (dd !== cur) { cur = dd; h += '<h4>' + esc(dayTitle(dd)) + '</h4>'; }
        h += meetingItem(m);
      });
      h += '<div class="kt-rp-row"><button type="button" class="kt-rp-link" data-go="' + (platformView() ? 'sales-calendar' : 'staff-calendar') + '">Open calendar</button></div></div>';
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
      + '<span style="flex:1;min-width:0" role="button" tabindex="0" data-go="tasks"><b data-no-i18n>' + esc(t.title) + '</b>'
      + '<span class="kt-rp-sub">' + esc(who === 'mine' ? (t.assigned_by_name ? T('From') + ' ' + t.assigned_by_name : '') : (t.assigned_to_name ? T('For') + ' ' + t.assigned_to_name : '')) + (t.centre_name ? ' · ' + esc(t.centre_name) : '') + '</span>'
      + dueChip(t) + '</span></div>';
  }
  function tasksHtml(force) {
    return getTasks(force).then(function (tk) {
      var h = '<div class="kt-rp-sec">';
      if (!platformView()) {
        h += '<form class="kt-rp-add" id="kt-rp-add" autocomplete="off"><input class="kt-rp-in" name="title" maxlength="200" placeholder="Add a task…" aria-label="Add a task">'
          + '<div class="kt-rp-add-more"><select class="kt-rp-sel" name="to" aria-label="Assign to"><option value="">Assign to…</option></select>'
          + '<input class="kt-rp-in" type="date" name="due" aria-label="Due date"></div>'
          + '<div class="kt-rp-add-go"><button type="button" class="kt-rp-btn ghost sm" data-add="cancel">Cancel</button><button type="submit" class="kt-rp-btn sm">Assign task</button></div></form>';
      }
      if (tk.mine.length) { h += '<h4>Assigned to me</h4>'; tk.mine.forEach(function (t) { h += taskItem(t, 'mine'); }); }
      if (!platformView()) {
        h += '<h4>Assigned by me</h4>';
        if (!tk.byMe.length) h += '<div class="kt-rp-muted">No open tasks you assigned.</div>';
        tk.byMe.slice(0, 40).forEach(function (t) { h += taskItem(t, 'byme'); });
      } else if (!tk.mine.length) h += '<div class="kt-rp-muted">No open tasks.</div>';
      h += '<div class="kt-rp-row"><button type="button" class="kt-rp-link" data-go="tasks">All tasks</button></div></div>';
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
      if (t.dir) (dash.rooms || []).forEach(function (r) {
        var n = T('Children') + ' ' + (r.children_present || 0) + ' · ' + T('Staff') + ' ' + (r.educators_present || 0);
        if (r.status === 'breach' || r.status === 'over_group') ratio.push({ name: r.room_name, sub: T(r.status === 'over_group' ? 'Over group size' : 'Over ratio') + ' · ' + n + (r.ratio_target ? ' · ' + r.ratio_target : '') });
        else if (r.status === 'attention') check.push({ name: r.room_name, sub: (r.warnings && r.warnings[0]) || n });
      });
      else (dash.centres || []).forEach(function (c) { if (c.rooms_in_breach > 0) ratio.push({ name: c.name, sub: T('Children here with no staff on the floor') }); });
      var sa = t.sa, saOn = !!(sa && sa.settings && sa.settings.enabled), over = sa && sa.counts ? (sa.counts.overdue || 0) : 0;
      var late = sa && sa.rows ? sa.rows.filter(function (r) { return r.state === 'overdue'; }) : [];
      var h = '<div class="kt-rp-sec"><div class="kt-rp-stats">'
        + stat(present == null ? '–' : present, 'At Centre', 'headcounts', '', enrolled)
        + stat(staff == null ? '–' : staff, 'Staff on floor', 'dashboard', '')
        + stat(!sa ? '–' : (saOn ? over : 'Off'), saOn ? 'Not arrived' : 'Safe Arrival', 'safe-arrival', saOn ? (over ? 'warn' : 'ok') : '')
        + stat(t.dash ? ratio.length : '–', 'Ratio alerts', t.dir ? 'dashboard' : 'dashboard', t.dash ? (ratio.length ? 'bad' : 'ok') : '')
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
        rows.slice(0, 6).forEach(function (r) { h += '<button type="button" class="kt-rp-item" data-go="dashboard"><span class="kt-rp-bar" style="background:' + colour + '"></span><span><b data-no-i18n>' + esc(r.name) + '</b><span class="kt-rp-sub">' + esc(r.sub) + '</span></span></button>'; });
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
  function wireAdd() {
    var f = scroller.querySelector('#kt-rp-add'); if (!f) return;
    var title = f.querySelector('[name=title]'), sel = f.querySelector('[name=to]'), filled = false;
    var fill = function () {
      if (filled) return; filled = true;
      getAssignees().then(function (list) {
        var groups = {};
        list.forEach(function (a) { (groups[a.role_label] = groups[a.role_label] || []).push(a); });
        Object.keys(groups).forEach(function (g) {
          var og = d.createElement('optgroup'); og.label = g;
          groups[g].forEach(function (a) { var o = d.createElement('option'); o.value = a.id; o.textContent = a.name + (a.centre_name ? ' · ' + a.centre_name : ''); og.appendChild(o); });
          sel.appendChild(og);
        });
        if (!list.length) { var o = d.createElement('option'); o.disabled = true; o.textContent = 'Nobody to assign to'; sel.appendChild(o); }
      }).catch(function () { filled = false; });
    };
    title.addEventListener('focus', function () { f.classList.add('open'); fill(); });
    title.addEventListener('input', function () { f.classList.add('open'); fill(); });
    sel.addEventListener('focus', fill); sel.addEventListener('pointerdown', fill);
    f.addEventListener('submit', function (e) {
      e.preventDefault();
      var tt = (title.value || '').trim(), to = parseInt(sel.value, 10), due = f.querySelector('[name=due]').value || null;
      if (!tt) { title.focus(); return; }
      if (!to) { toast('Pick who the task is for', 'error'); sel.focus(); return; }
      var btn = f.querySelector('[type=submit]'); btn.disabled = true;
      api().post('/tasks', { assigned_to: to, title: tt, due_date: due, priority: 'normal', notify_channels: ['app'] }).then(function () {
        toast('Task assigned');
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
    if (!platformView()) items.push({ icon: '☑️', label: 'New task', run: function () { S.section = 'tasks'; save(); apply(true); renderSection(); setTimeout(function () { var i = scroller.querySelector('#kt-rp-add [name=title]'); if (i) i.focus(); }, 400); } });
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
    var rz = null;
    w.addEventListener('resize', function (e) { if (e && e.isTrusted === false) return; clearTimeout(rz); rz = setTimeout(function () { apply(); }, 150); });
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
  KT.rightPanel = { open: function (s) { if (s && SECTIONS.indexOf(s) !== -1) S.section = s; setOpen(true); }, close: function () { setOpen(false); }, refresh: function () { cacheFor = null; renderSection(true); } };
})(window, document);
