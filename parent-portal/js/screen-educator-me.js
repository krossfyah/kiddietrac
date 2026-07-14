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
    return g.charAt(0).toUpperCase() + g.slice(1);
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
  function fmtTime(iso) {
    if (!iso) return '—';
    var d = new Date(String(iso).replace(' ', 'T'));
    return isNaN(d) ? '—' : d.toLocaleTimeString([], { hour: 'numeric', minute: '2-digit' });
  }
  function fmtDay(iso) {
    if (!iso) return '';
    var d = new Date(String(iso).replace(' ', 'T'));
    return isNaN(d) ? '' : d.toLocaleDateString([], { weekday: 'short', month: 'short', day: 'numeric' });
  }
  function hoursBetween(a, b) {
    if (!a || !b) return 0;
    var s = new Date(String(a).replace(' ', 'T')), e = new Date(String(b).replace(' ', 'T'));
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
      + '<div id="eh-body"><div style="padding:24px;text-align:center;color:#94A3B8;">Loading…</div></div>';
    var body = main.querySelector('#eh-body');

    var punches = [], shifts = [];
    try { var p = await Api.get('/staff/punches/me'); punches = (p && p.punches) || []; } catch (e) {}
    try { var s = await Api.get('/provider/shifts/me'); shifts = (s && s.shifts) || []; } catch (e) {}

    // Group closed punches by day. An open punch (no punch-out) is "in progress"
    // and shouldn't be counted as worked time yet.
    var byDay = {}, total = 0, open = null;
    punches.forEach(function (p) {
      if (!p.punched_out_at) { open = p; return; }
      var day = String(p.punched_in_at).slice(0, 10);
      var hrs = hoursBetween(p.punched_in_at, p.punched_out_at);
      if (!byDay[day]) byDay[day] = { day: day, hours: 0, rows: [] };
      byDay[day].hours += hrs;
      byDay[day].rows.push(p);
      total += hrs;
    });
    var days = Object.keys(byDay).sort().reverse();

    // "This period" = the current calendar month, which is how the director's
    // payroll export is bucketed too.
    var monthKey = new Date().toISOString().slice(0, 7);
    var monthHours = days.filter(function (d) { return d.slice(0, 7) === monthKey; })
      .reduce(function (a, d) { return a + byDay[d].hours; }, 0);

    var scheduled = shifts.reduce(function (a, s) { return a + (s.hours || 0); }, 0);

    var html = '';
    html += '<div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:14px;">'
      + '<div style="' + CARD + 'margin:0;text-align:center;"><div style="font-size:11px;font-weight:800;color:#64748B;letter-spacing:.6px;">THIS MONTH</div>'
      + '<div style="font-size:26px;font-weight:800;color:#0F172A;margin-top:4px;">' + fmtHours(monthHours) + '</div>'
      + '<div style="font-size:11.5px;color:#94A3B8;">worked</div></div>'
      + '<div style="' + CARD + 'margin:0;text-align:center;"><div style="font-size:11px;font-weight:800;color:#64748B;letter-spacing:.6px;">SCHEDULED</div>'
      + '<div style="font-size:26px;font-weight:800;color:' + TEAL + ';margin-top:4px;">' + fmtHours(scheduled) + '</div>'
      + '<div style="font-size:11.5px;color:#94A3B8;">next 4 weeks</div></div>'
      + '</div>';

    if (open) {
      html += '<div style="' + CARD + 'border-left:5px solid #16A34A;">'
        + '<div style="font-weight:800;color:#16A34A;font-size:14px;">⏱ Clocked in now</div>'
        + '<div style="font-size:12.5px;color:#64748B;margin-top:3px;">Since ' + fmtTime(open.punched_in_at) + ' · ' + fmtDay(open.punched_in_at) + '</div>'
        + '<a href="#time-clock" style="display:inline-block;margin-top:10px;background:' + TEAL + ';color:#fff;border-radius:9px;padding:8px 14px;font-size:13px;font-weight:800;text-decoration:none;">Go to the time clock</a>'
        + '</div>';
    }

    html += '<div style="' + CARD + '">';
    html += '<div style="font-weight:800;font-size:15px;color:#0F172A;margin-bottom:10px;">Recent days</div>';
    if (!days.length) {
      html += '<div style="color:#94A3B8;font-size:13.5px;padding:8px 0;">No completed shifts on the clock yet. Hours appear here once you clock out.</div>';
    } else {
      days.slice(0, 30).forEach(function (d) {
        var e = byDay[d];
        html += '<div style="display:flex;justify-content:space-between;align-items:center;padding:10px 0;border-bottom:1px solid #F1F5F9;">'
          + '<div><div style="font-weight:700;font-size:13.5px;color:#0F172A;">' + esc(fmtDay(d)) + '</div>'
          + '<div style="font-size:11.5px;color:#94A3B8;">' + e.rows.map(function (r) { return fmtTime(r.punched_in_at) + '–' + fmtTime(r.punched_out_at); }).join(', ') + '</div></div>'
          + '<div style="font-weight:800;font-size:14px;color:' + TEAL + ';">' + fmtHours(e.hours) + '</div>'
          + '</div>';
      });
      html += '<div style="display:flex;justify-content:space-between;padding-top:10px;font-weight:800;font-size:14px;color:#0F172A;">'
        + '<span>Total on record</span><span>' + fmtHours(total) + '</span></div>';
    }
    html += '</div>';

    html += '<div style="font-size:11.5px;color:#94A3B8;text-align:center;padding:0 12px 8px;line-height:1.5;">'
      + 'Hours are taken from the time clock. Pay is worked out by your centre — talk to your director if something looks wrong.</div>';

    body.innerHTML = html;
  }

  // ── My calendar ─────────────────────────────────────────────────────
  async function renderMySchedule(main) {
    main.innerHTML = hero('My calendar', 'Your upcoming shifts')
      + '<div id="es-body"><div style="padding:24px;text-align:center;color:#94A3B8;">Loading…</div></div>';
    var body = main.querySelector('#es-body');

    var data = null;
    try { data = await Api.get('/provider/shifts/me'); } catch (e) {
      body.innerHTML = '<div style="' + CARD + 'text-align:center;color:#B91C1C;">Could not load your schedule.</div>';
      return;
    }
    var shifts = (data && data.shifts) || [];
    if (!shifts.length) {
      body.innerHTML = '<div style="' + CARD + 'text-align:center;color:#64748B;padding:28px 16px;">'
        + '<div style="font-size:38px;">📅</div>'
        + '<div style="font-weight:800;color:#0F172A;margin-top:8px;">No shifts scheduled</div>'
        + '<div style="font-size:13px;margin-top:4px;">When your director schedules you, your shifts show up here.</div>'
        + '<a href="#time-off" style="display:inline-block;margin-top:14px;color:' + TEAL + ';font-weight:800;font-size:13px;text-decoration:none;">Request time off →</a>'
        + '</div>';
      return;
    }

    // Group by day so a day with two shifts reads as one block.
    var byDay = {};
    shifts.forEach(function (s) { (byDay[s.date] = byDay[s.date] || []).push(s); });

    var today = new Date().toISOString().slice(0, 10);
    var html = '<div style="' + CARD + 'text-align:center;margin-bottom:14px;">'
      + '<div style="font-size:11px;font-weight:800;color:#64748B;letter-spacing:.6px;">SCHEDULED</div>'
      + '<div style="font-size:26px;font-weight:800;color:' + TEAL + ';margin-top:2px;">' + fmtHours(data.scheduled_hours || 0) + '</div>'
      + '<div style="font-size:11.5px;color:#94A3B8;">' + shifts.length + ' shift' + (shifts.length === 1 ? '' : 's') + ' · to ' + esc(data.to || '') + '</div></div>';

    Object.keys(byDay).sort().forEach(function (day) {
      var isToday = day === today;
      html += '<div style="' + CARD + (isToday ? 'border-left:5px solid ' + TEAL + ';' : '') + '">'
        + '<div style="font-weight:800;font-size:14px;color:#0F172A;margin-bottom:8px;">'
        + esc(fmtDay(day)) + (isToday ? ' <span style="color:' + TEAL + ';font-size:12px;">· Today</span>' : '') + '</div>';
      byDay[day].forEach(function (s) {
        var colour = s.room_colour || TEAL;
        html += '<div style="display:flex;align-items:center;gap:10px;padding:7px 0;">'
          + '<span style="width:9px;height:9px;border-radius:50%;background:' + esc(colour) + ';flex:0 0 auto;"></span>'
          + '<div style="flex:1;"><div style="font-weight:700;font-size:13.5px;color:#0F172A;">' + fmtTime(s.starts_at) + ' – ' + fmtTime(s.ends_at) + '</div>'
          + '<div style="font-size:11.5px;color:#94A3B8;">' + esc(s.room_name || 'Centre') + (s.role ? ' · ' + esc(s.role) : '') + '</div></div>'
          + (s.hours ? '<div style="font-weight:800;font-size:13px;color:#64748B;">' + fmtHours(s.hours) + '</div>' : '')
          + '</div>';
      });
      html += '</div>';
    });
    body.innerHTML = html;
  }

  // ── Child records ───────────────────────────────────────────────────
  async function renderChildren(main) {
    main.innerHTML = hero('Child records', 'Children in your rooms')
      + '<div id="ec-body"><div style="padding:24px;text-align:center;color:#94A3B8;">Loading…</div></div>';
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
          return !q || ((c.first_name || '') + ' ' + (c.last_name || '')).toLowerCase().indexOf(q) > -1;
        });
        if (!kids.length) return;
        shown += kids.length;
        var sec = document.createElement('div');
        sec.style.cssText = CARD;
        sec.innerHTML = '<div style="font-weight:800;font-size:14px;color:#0F172A;margin-bottom:6px;">'
          + esc(r.room.name) + ' <span style="color:#94A3B8;font-weight:600;">· ' + kids.length + '</span></div>';
        kids.forEach(function (c) {
          var row = document.createElement('button');
          row.type = 'button';
          row.style.cssText = 'display:flex;align-items:center;gap:11px;width:100%;text-align:left;background:none;border:none;border-top:1px solid #F1F5F9;padding:10px 0;cursor:pointer;';
          var flags = (c.urgent_flags || []).length;
          row.innerHTML = KT.avatar((c.first_name || '') + ' ' + (c.last_name || ''), { size: 38, photoUrl: c.photo_url ? absUrl(c.photo_url) : '' })
            + '<span style="flex:1;"><span style="display:block;font-weight:700;font-size:14px;color:#0F172A;">' + esc((c.first_name || '') + ' ' + (c.last_name || '')) + '</span>'
            + '<span style="display:block;font-size:11.5px;color:#94A3B8;">' + esc(c.age_human || '') + (c.is_at_centre ? ' · <span style="color:#16A34A;font-weight:700;">At centre</span>' : '') + '</span></span>'
            + (flags ? '<span style="font-size:15px;">⚠️</span>' : '')
            + '<span style="color:#CBD5E1;font-size:18px;">›</span>';
          row.addEventListener('click', function () { openChildRecord(c.id); });
          sec.appendChild(row);
        });
        list.appendChild(sec);
      });
      if (!shown) list.innerHTML = '<div style="' + CARD + 'text-align:center;color:#94A3B8;">No children match “' + esc(q) + '”.</div>';
    }
    paint('');
    search.addEventListener('input', function () { paint(search.value); });
  }

  // Full-screen child record. Registered with the overlay stack so the Android
  // back button (and the ‹ button) closes it instead of navigating away.
  async function openChildRecord(childId) {
    var ov = document.createElement('div');
    ov.style.cssText = 'position:fixed;inset:0;z-index:9600;background:#F6F9FC;overflow-y:auto;-webkit-overflow-scrolling:touch;';
    ov.innerHTML = '<div style="position:sticky;top:0;background:#fff;border-bottom:1px solid #E7EDF3;padding:12px 14px;display:flex;align-items:center;gap:10px;z-index:2;">'
      + '<button type="button" data-back style="background:none;border:none;font-size:22px;color:' + TEAL + ';cursor:pointer;padding:0 4px;">‹</button>'
      + '<span style="font-weight:800;font-size:16px;color:#0F172A;">Child record</span></div>'
      + '<div id="cr-body" style="padding:14px;"><div style="text-align:center;color:#94A3B8;padding:30px;">Loading…</div></div>';
    document.body.appendChild(ov);
    var close = function () { if (ov.parentNode) ov.parentNode.removeChild(ov); };
    ov.querySelector('[data-back]').addEventListener('click', function (e) {
      e.preventDefault(); e.stopPropagation();
      if (KT.popOverlay) KT.popOverlay(ov); else close();
    });
    if (KT.pushOverlay) KT.pushOverlay(ov, close);

    var d;
    try { d = await Api.get('/provider/children/' + childId); }
    catch (e) {
      ov.querySelector('#cr-body').innerHTML = '<div style="' + CARD + 'text-align:center;color:#B91C1C;">Could not load this record.</div>';
      return;
    }
    var c = d.child || {};
    var name = ((c.first_name || '') + ' ' + (c.last_name || '')).trim();
    var html = '';

    html += '<div style="' + CARD + 'display:flex;align-items:center;gap:13px;">'
      + KT.avatar(name, { size: 56, photoUrl: c.photo_url ? absUrl(c.photo_url) : '' })
      + '<div><div style="font-weight:800;font-size:17px;color:#0F172A;">' + esc(name) + '</div>'
      + '<div style="font-size:12.5px;color:#64748B;">'
      + esc([c.age_human, genderLabel(c.gender), c.room_name, c.pronouns].filter(Boolean).join(' · ') || '—')
      + '</div></div></div>';

    // Safety first — this is the reason an educator opens a record mid-room.
    var alerts = [
      { label: 'Allergies', value: c.allergies, colour: '#B91C1C', icon: '⚠️' },
      { label: 'Health alerts', value: c.health_alerts, colour: '#B45309', icon: '🩺' },
      { label: 'Medical notes', value: c.medical_notes, colour: '#0F172A', icon: '📋' },
      { label: 'Dietary', value: c.dietary_restrictions || c.dietary_notes, colour: '#0F172A', icon: '🍎' },
    ].filter(function (a) { return a.value; });

    if (alerts.length) {
      html += '<div style="' + CARD + 'border-left:5px solid #B91C1C;">';
      alerts.forEach(function (a, i) {
        html += '<div style="' + (i ? 'border-top:1px solid #F1F5F9;padding-top:9px;margin-top:9px;' : '') + '">'
          + '<div style="font-size:11px;font-weight:800;letter-spacing:.5px;color:' + a.colour + ';">' + a.icon + ' ' + a.label.toUpperCase() + '</div>'
          + '<div style="font-size:13.5px;color:#0F172A;margin-top:2px;line-height:1.45;">' + esc(a.value) + '</div></div>';
      });
      html += '</div>';
    } else {
      html += '<div style="' + CARD + 'color:#16A34A;font-weight:700;font-size:13.5px;">✓ No allergies or medical alerts on file.</div>';
    }

    var guardians = d.guardians || [];
    html += '<div style="' + CARD + '"><div style="font-weight:800;font-size:14px;color:#0F172A;margin-bottom:8px;">Parents / guardians</div>';
    if (!guardians.length) html += '<div style="color:#94A3B8;font-size:13px;">No guardians on file.</div>';
    guardians.forEach(function (g, i) {
      var gname = ((g.first_name || '') + ' ' + (g.last_name || '')).trim();
      html += '<div style="display:flex;align-items:center;gap:10px;padding:8px 0;' + (i ? 'border-top:1px solid #F1F5F9;' : '') + '">'
        + KT.avatar(gname, { size: 34, photoUrl: g.photo_url ? absUrl(g.photo_url) : '' })
        + '<div style="flex:1;"><div style="font-weight:700;font-size:13.5px;color:#0F172A;">' + esc(gname)
        + (g.is_primary ? ' <span style="font-size:10px;background:#E1F3F6;color:#0E7C90;border-radius:5px;padding:1px 5px;font-weight:800;">PRIMARY</span>' : '') + '</div>'
        + '<div style="font-size:11.5px;color:#94A3B8;">' + esc(g.relationship || 'Guardian') + (g.can_pickup ? ' · may collect' : '') + '</div></div>'
        + (g.phone ? '<a href="tel:' + esc(g.phone) + '" style="background:' + TEAL + ';color:#fff;border-radius:9px;padding:7px 12px;font-size:12.5px;font-weight:800;text-decoration:none;">Call</a>' : '')
        + '</div>';
    });
    if (c.primary_phone || c.primary_email) {
      html += '<div style="border-top:1px solid #F1F5F9;margin-top:8px;padding-top:8px;font-size:12px;color:#64748B;">'
        + 'Family: ' + esc(c.family_name || '') + (c.primary_phone ? ' · ' + esc(c.primary_phone) : '') + '</div>';
    }
    html += '</div>';

    var ec = d.emergency_contacts || [];
    html += '<div style="' + CARD + '"><div style="font-weight:800;font-size:14px;color:#0F172A;margin-bottom:8px;">Emergency contacts</div>';
    if (!ec.length) html += '<div style="color:#94A3B8;font-size:13px;">None on file.</div>';
    ec.forEach(function (e, i) {
      html += '<div style="display:flex;align-items:center;gap:10px;padding:8px 0;' + (i ? 'border-top:1px solid #F1F5F9;' : '') + '">'
        + '<div style="flex:1;"><div style="font-weight:700;font-size:13.5px;color:#0F172A;">' + esc(e.name) + '</div>'
        + '<div style="font-size:11.5px;color:#94A3B8;">' + esc(e.relationship || '') + (e.can_pickup ? ' · may collect' : '') + '</div></div>'
        + (e.phone ? '<a href="tel:' + esc(e.phone) + '" style="background:#0F172A;color:#fff;border-radius:9px;padding:7px 12px;font-size:12.5px;font-weight:800;text-decoration:none;">Call</a>' : '')
        + '</div>';
    });
    html += '</div>';

    // The child's own log history — every care moment AND every sign-in/out, with
    // who recorded it. This is the compliance trail: "who had this child, and
    // when" has to be answerable from the child's record.
    var hist = d.history || [];
    var ICONS = {
      check_in: '✅', check_out: '👋', diaper: '🧷', bathroom: '🚽', nap: '😴',
      meal: '🍽️', snack: '🍎', bottle: '🍼', sunscreen: '☀️', mood: '🙂',
    };
    var LABELS = { check_in: 'Signed in', check_out: 'Signed out' };
    var fmtHist = function (at) {
      if (KT.fmtDateTime) return KT.fmtDateTime(at);
      return String(at || '').slice(0, 16);
    };

    // Paged, not endless: a child with a year of logs would otherwise render a
    // scroll you can't get to the bottom of.
    var PAGE = 15;
    var shown = PAGE;

    html += '<div id="cr-history" style="' + CARD + '"></div>';
    var renderHistory = function () {
      var el = ov.querySelector('#cr-history');
      if (!el) return;
      var rows = hist.slice(0, shown);
      var h2 = '<div style="font-weight:800;font-size:14px;color:#0F172A;margin-bottom:8px;">'
        + 'Log history <span style="color:#94A3B8;font-weight:600;">· ' + hist.length + '</span></div>';
      if (!hist.length) {
        h2 += '<div style="color:#94A3B8;font-size:13px;">Nothing logged for this child yet.</div>';
      }
      h2 += rows.map(function (h, i) { return historyRow(h, i); }).join('');
      if (shown < hist.length) {
        h2 += '<button id="cr-more" style="width:100%;margin-top:10px;background:#F8FAFC;border:1px solid #E2E8F0;'
          + 'color:#0E7C90;border-radius:10px;padding:11px;font-size:13px;font-weight:800;cursor:pointer;">'
          + 'Show ' + Math.min(PAGE, hist.length - shown) + ' more · ' + (hist.length - shown) + ' left</button>';
      }
      el.innerHTML = h2;
      var more = el.querySelector('#cr-more');
      if (more) more.addEventListener('click', function () { shown += PAGE; renderHistory(); });
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
        + '<div style="font-size:11px;color:#94A3B8;margin-top:2px;">' + esc(fmtHist(h.at))
        + (h.by ? ' · by ' + esc(h.by) : '') + '</div>'
        + '</div></div>';
    }

    var pk = d.pickup_authorizations || [];
    html += '<div style="' + CARD + 'margin-bottom:40px;"><div style="font-weight:800;font-size:14px;color:#0F172A;margin-bottom:8px;">Authorised for pickup</div>';
    if (!pk.length) html += '<div style="color:#94A3B8;font-size:13px;">Only the guardians listed above.</div>';
    pk.forEach(function (p, i) {
      html += '<div style="display:flex;align-items:center;gap:10px;padding:8px 0;' + (i ? 'border-top:1px solid #F1F5F9;' : '') + '">'
        + KT.avatar(p.full_name, { size: 32, photoUrl: p.photo_id_url ? absUrl(p.photo_id_url) : '' })
        + '<div style="flex:1;"><div style="font-weight:700;font-size:13.5px;color:#0F172A;">' + esc(p.full_name) + '</div>'
        + '<div style="font-size:11.5px;color:#94A3B8;">' + esc(p.relationship || '') + (p.expires_at ? ' · until ' + esc(String(p.expires_at).slice(0, 10)) : '') + '</div></div>'
        + (p.phone ? '<a href="tel:' + esc(p.phone) + '" style="color:' + TEAL + ';font-weight:800;font-size:12.5px;text-decoration:none;">Call</a>' : '')
        + '</div>';
    });
    html += '</div>';

    ov.querySelector('#cr-body').innerHTML = html;
    renderHistory();
    makeCollapsible(ov);
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
      caret.style.cssText = 'float:right;color:#94A3B8;font-size:13px;transition:transform .18s;';
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

  // role-widgets.js mounts its stat strip ~350ms after a hashchange, but the
  // educator screen renders asynchronously (it awaits /provider/bootstrap) and
  // starts with Dom.clear(main) — so on a slow load the strip is wiped straight
  // after it mounts and the cards vanish. Put them back if they went missing.
  // Guarded on "no strip present", so this can't loop against its own mutation.
  function ensureWidgets() {
    if (!isEducator() || !KT.RoleWidgets) return;
    var hash = currentHash();
    if (hash !== 'dashboard' && hash !== 'today' && hash !== '') return;
    var main = document.getElementById('appMain');
    if (!main || !main.children.length) return;
    if (main.querySelector('.kt-role-widget-strip')) return;
    KT.RoleWidgets.mount(main, { prepend: true });   // stats first, then roster
  }

  function ensureLauncher() {
    ensureWidgets();
    if (!isEducator()) return;
    var hash = currentHash();
    if (hash !== 'dashboard' && hash !== 'today' && hash !== '') return;
    var main = document.getElementById('appMain');
    if (!main || !main.children.length) return;
    if (main.querySelector('#kt-edu-launcher')) return;
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
      // Same teardown the parent launcher does: drop the biometric enrolment and
      // purge the token from BOTH stores so nothing auto-resumes on a shared phone.
      try { if (KT.biometric && KT.biometric.disable) KT.biometric.disable(); } catch (e) {}
      try { if (KT.pin && KT.pin.remove) KT.pin.remove(); } catch (e) {}
      try { if (KT.Auth && KT.Auth.clear) KT.Auth.clear(); } catch (e) {}
      try { sessionStorage.clear(); localStorage.removeItem('kt_token'); localStorage.removeItem('kt_user'); } catch (e) {}
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
