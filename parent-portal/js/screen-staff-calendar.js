/* ═══════════════════════════════════════════════════════════════════
   KIDDIETRAC v22p37 — Staff calendar
   Hash: #staff-calendar
   Visible to centre_director + agency_admin.

   Two views — Week (7-day grid) and Month (5-row calendar). Click any
   day cell to add a shift; click any shift to edit/delete. Filter by
   centre + by role.
   ═══════════════════════════════════════════════════════════════════ */
(function (window) {
  'use strict';
  if (!window.KT) return;
  var KT = window.KT;
  var Api = KT.Api;
  var Dom = KT.Dom;
  var Shell = KT.Shell;

  // ─── State ─────────────────────────────────────────────────────────
  var state = {
    view: localStorage.getItem('kt_cal_view') || 'week',
    centreId: parseInt(sessionStorage.getItem('kt_cal_centre_id') || '0', 10),
    cursor: new Date(),
    roleFilter: '',
    // WHO a shift belongs to, which is what people mean by "filter by educator".
    // The existing roleFilter is the role played ON the shift (lead/support), not the
    // person — so there was no way to say "just show me Bruni's week". (2026-08-30)
    staffFilter: '',
    centres: [],
    staff: [],
    rooms: [],
    days: {},
  };

  function esc(s) { return s == null ? '' : String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;'); }
  function pad2(n) { return n < 10 ? '0' + n : '' + n; }
  function ymd(d) { return d.getFullYear() + '-' + pad2(d.getMonth() + 1) + '-' + pad2(d.getDate()); }
  function startOfWeek(d) { var c = new Date(d); var dow = c.getDay(); var diff = dow === 0 ? -6 : 1 - dow; c.setDate(c.getDate() + diff); c.setHours(0,0,0,0); return c; }
  function startOfMonth(d) { return new Date(d.getFullYear(), d.getMonth(), 1); }
  function endOfMonth(d)   { return new Date(d.getFullYear(), d.getMonth() + 1, 0); }
  function addDays(d, n)   { var c = new Date(d); c.setDate(c.getDate() + n); return c; }
  function monthName(d)    { return d.toLocaleDateString('en-CA', { month: 'long', year: 'numeric' }); }

  var ROLE_COLORS = { lead: '#7C3AED', support: '#1F6080', floater: '#F59E0B', volunteer: '#8EC73C' };

  /**
   * Everything the grid is filtered by, in ONE place.
   *
   * The month cell, the week column, the day view and the agenda each carried their own
   * copy of the role test. Four copies means a new filter has to be added in four places,
   * and missing one filters the week but not the month — which is indistinguishable, to
   * the person using it, from "the filter doesn't work".
   */
  /**
   * Time off is somebody's too. Filtering the grid to one educator while another's
   * holiday stayed on it reads as the filter half-working — and the row carries user_id,
   * so there is nothing to look up. The ROLE filter deliberately does not apply here:
   * time off has no shift role to match.
   */
  function filterOffs(list) {
    var out = list || [];
    if (state.staffFilter) {
      out = out.filter(function (t) { return String(t.user_id) === String(state.staffFilter); });
    }
    return out;
  }

  /**
   * Overlay events are a mixed bag — birthdays, child absences, closures and STAFF time
   * off all arrive in one list. Filtering the calendar to one educator has to drop other
   * people's time off (otherwise the filter visibly half-works) while keeping everything
   * that is not about a particular staff member. Keyed on who==='staff' plus the user_id
   * the API now sends; an event without one is never dropped.
   */
  function filterOverlays(list) {
    var out = list || [];
    if (state.staffFilter) {
      out = out.filter(function (e) {
        if (e && e.who === 'staff' && e.user_id) {
          return String(e.user_id) === String(state.staffFilter);
        }
        return true;
      });
    }
    return out;
  }

  function applyFilters(list) {
    var out = list || [];
    if (state.roleFilter) {
      out = out.filter(function (s) { return s.role === state.roleFilter; });
    }
    if (state.staffFilter) {
      out = out.filter(function (s) { return String(s.user_id) === String(state.staffFilter); });
    }
    return out;
  }

  // v22p42: drag-select across day cells.
  // Mousedown on a cell hint -> remember its data-date.
  // Mousemove updates the highlighted range using elementFromPoint().
  // Mouseup with a range > 1 day -> open the New shift modal with a dateRange
  // prefill. The modal then loops to create one shift per day on save.
  function installDragSelect(grid, calRoot) {
    var anchor = null;     // start date (string YYYY-MM-DD)
    var current = null;    // hover date (string)
    var startEl = null;    // the cell node we started on (for visual highlight)
    var armed = false;

    function dateUnderPoint(x, y) {
      var el = document.elementFromPoint(x, y);
      while (el && el !== document.body) {
        if (el.dataset && el.dataset.date) return { date: el.dataset.date, el: el };
        el = el.parentElement;
      }
      return null;
    }

    function clearHighlights() {
      grid.querySelectorAll('[data-drag-active="1"]').forEach(function (n) {
        n.removeAttribute('data-drag-active');
        n.style.background = '';
        n.style.outline = '';
      });
    }

    function highlightRange(a, b) {
      if (!a || !b) return;
      var lo = a < b ? a : b;
      var hi = a < b ? b : a;
      grid.querySelectorAll('[data-date]').forEach(function (n) {
        var d = n.dataset.date;
        if (d >= lo && d <= hi) {
          n.dataset.dragActive = '1';
          n.style.background = 'rgba(31,96,128,.10)';
          n.style.outline = '2px solid rgba(31,96,128,.42)';
          n.style.outlineOffset = '-2px';
        }
      });
    }

    grid.addEventListener('mousedown', function (e) {
      if (e.button !== 0) return;
      var hit = dateUnderPoint(e.clientX, e.clientY);
      if (!hit) return;
      // Don't start drag when clicking ON a shift pill/chip
      var t = e.target;
      if (t.closest && (t.closest('[data-shift-id]'))) return;
      // Only arm AFTER a real movement so single click still opens modal
      anchor = hit.date; current = hit.date; startEl = hit.el; armed = false;
      e.preventDefault();
    });

    document.addEventListener('mousemove', function (e) {
      if (!anchor) return;
      var hit = dateUnderPoint(e.clientX, e.clientY);
      if (!hit) return;
      if (hit.date !== current) {
        current = hit.date;
        armed = true;
        clearHighlights();
        highlightRange(anchor, current);
      }
    });

    document.addEventListener('mouseup', function (e) {
      if (!anchor) return;
      var a = anchor, c = current, didDrag = armed;
      anchor = null; current = null; startEl = null; armed = false;
      clearHighlights();
      if (didDrag && a && c && a !== c) {
        var lo = a < c ? a : c;
        var hi = a < c ? c : a;
        openShiftModal({ dateRange: { start: lo, end: hi } }, calRoot);
      }
      // Single-click case is handled by the cell's own click handler.
    });
  }

  // ─── Bootstrap ─────────────────────────────────────────────────────
  function render(container) {
    Dom.clear(container);
    var wrap = Dom.el('div', { style: 'padding:24px;max-width:1800px;margin:0 auto;' });
    container.appendChild(wrap);

    var hero = Dom.el('div', { class: 'kt-hero', style: 'background:linear-gradient(135deg,#1F6080 0%,#7C3AED 60%,#16637A 100%);' });
    hero.innerHTML = '<div class="kt-hero-greet">📅 AGENCY</div><h1>Calendar</h1><div class="kt-hero-sub">Click a day to see everything on it, then any entry for its full details. Use + Add for closures, vacation holds, trips and conferences.</div>';
    wrap.appendChild(hero);

    var toolbar = Dom.el('div', { style: 'background:white;border-radius:12px;padding:14px 18px;box-shadow:0 1px 3px rgba(0,0,0,.04);margin:18px 0 14px;display:flex;align-items:center;gap:12px;flex-wrap:wrap;' });
    wrap.appendChild(toolbar);

    var calRoot = Dom.el('div', { id: 'kt-cal-root' });
    wrap.appendChild(calRoot);

    // v22p38: was /admin/centres (agency_admin-only) which 403'd for directors.
    // /director/centres works for both roles — agency_admin via the route-level
    // role gate, director via the controller's role-aware myCentres().
    Api.get('/director/centres').then(function (r) {
      state.centres = r.centres || [];
      if (!state.centreId && state.centres.length) state.centreId = state.centres[0].id;
      buildToolbar(toolbar, calRoot);
      reload(calRoot);
    }).catch(function (e) {
      calRoot.appendChild(Dom.el('div', { style: 'padding:24px;color:#DC2626;' }, 'Could not load centres: ' + (e.message || 'error')));
    });
  }

  function buildToolbar(toolbar, calRoot) {
    Dom.clear(toolbar);

    // ← prev
    var prev = Dom.el('button', { style: navBtnStyle() }, '◀');
    prev.addEventListener('click', function () { stepCursor(-1); reload(calRoot); });
    toolbar.appendChild(prev);

    // Today
    var today = Dom.el('button', { style: navBtnStyle() + 'min-width:auto;padding:7px 14px;' }, 'Today');
    today.addEventListener('click', function () { state.cursor = new Date(); reload(calRoot); });
    toolbar.appendChild(today);

    // → next
    var next = Dom.el('button', { style: navBtnStyle() }, '▶');
    next.addEventListener('click', function () { stepCursor(1); reload(calRoot); });
    toolbar.appendChild(next);

    // Title
    var title = Dom.el('div', { id: 'kt-cal-title', style: 'font-size:18px;font-weight:700;color:#111827;flex:1;min-width:140px;text-align:center;' });
    title.textContent = titleForCursor();
    toolbar.appendChild(title);

    // View toggle
    var grp = Dom.el('div', { style: 'display:inline-flex;background:#F3F4F6;border-radius:8px;padding:2px;' });
    ['day', 'week', 'month'].forEach(function (v) {
      var b = Dom.el('button', {
        style: 'background:' + (state.view === v ? 'white' : 'transparent') + ';color:' + (state.view === v ? '#1F6080' : '#6B7280') + ';border:none;padding:6px 14px;border-radius:6px;font-size:12px;font-weight:700;cursor:pointer;text-transform:capitalize;' + (state.view === v ? 'box-shadow:0 1px 2px rgba(0,0,0,.08);' : ''),
      }, v);
      b.addEventListener('click', function () {
        if (state.view === v) return;
        state.view = v; localStorage.setItem('kt_cal_view', v);
        buildToolbar(toolbar, calRoot); reload(calRoot);
      });
      grp.appendChild(b);
    });
    toolbar.appendChild(grp);

    // Centre filter
    if (state.centres.length > 1) {
      var sel = Dom.el('select', { style: selectStyle() });
      state.centres.forEach(function (c) {
        var o = Dom.el('option', { value: c.id }, c.name);
        if (c.id === state.centreId) o.selected = true;
        sel.appendChild(o);
      });
      sel.addEventListener('change', function () { state.centreId = parseInt(sel.value, 10); sessionStorage.setItem('kt_cal_centre_id', String(state.centreId)); reload(calRoot); });
      toolbar.appendChild(sel);
    }

    // Role filter
    var rsel = Dom.el('select', { style: selectStyle() });
    [['', 'All roles'], ['lead', 'Lead'], ['support', 'Support'], ['floater', 'Floater'], ['volunteer', 'Volunteer']].forEach(function (o) {
      var op = Dom.el('option', { value: o[0] }, o[1]);
      if (state.roleFilter === o[0]) op.selected = true;
      rsel.appendChild(op);
    });
    rsel.addEventListener('change', function () { state.roleFilter = rsel.value; reload(calRoot); });
    toolbar.appendChild(rsel);

    /* Staff filter — "show me this educator's shifts". Built empty and filled from
       /director/schedule/staff (the same list the New-shift form uses, so the two can
       never disagree about who works at this centre); if that call fails it falls back
       to the people who actually appear in the loaded range, so the control still works
       rather than sitting there empty. */
    var ssel = Dom.el('select', { style: selectStyle(), title: 'Filter by staff member' });
    var fillStaff = function (people) {
      Dom.clear(ssel);
      ssel.appendChild(Dom.el('option', { value: '' }, 'All staff'));
      (people || []).forEach(function (u) {
        var nm = u.name || trimName(u);
        var o = Dom.el('option', { value: String(u.id) }, nm);
        if (String(state.staffFilter) === String(u.id)) o.selected = true;
        ssel.appendChild(o);
      });
    };
    fillStaff(state.staff);
    if (! (state.staff || []).length) {
      Api.get('/director/schedule/staff?centre_id=' + state.centreId).then(function (r) {
        state.staff = (r && (r.staff || r.data)) || [];
        fillStaff(state.staff);
      }).catch(function () {
        // Whoever is actually on the rota this period — better than an empty picker.
        var seen = {};
        Object.keys(state.days || {}).forEach(function (k) {
          ((state.days[k] && state.days[k].shifts) || []).forEach(function (sh) {
            if (sh.user_id && !seen[sh.user_id]) seen[sh.user_id] = { id: sh.user_id, name: sh.user_name };
          });
        });
        state.staff = Object.keys(seen).map(function (k) { return seen[k]; })
          .sort(function (a, b) { return String(a.name).localeCompare(String(b.name)); });
        fillStaff(state.staff);
      });
    }
    ssel.addEventListener('change', function () { state.staffFilter = ssel.value; reload(calRoot); });
    toolbar.appendChild(ssel);

    // + Add — a menu now, because a shift is only one of the things that lands on a day.
    var add = Dom.el('button', {
      type: 'button', 'aria-haspopup': 'menu',
      style: 'background:#1F6080;color:white;border:none;padding:8px 16px;border-radius:8px;font-weight:700;cursor:pointer;font-size:13px;',
    }, '+ Add \u25be');
    add.addEventListener('click', function (e) { e.stopPropagation(); openAddMenu(add, calRoot); });
    toolbar.appendChild(add);
  }

  function stepCursor(direction) {
    var d = new Date(state.cursor);
    if (state.view === 'day') d.setDate(d.getDate() + direction);
    else if (state.view === 'week') d.setDate(d.getDate() + 7 * direction);
    else d.setMonth(d.getMonth() + direction);
    state.cursor = d;
  }

  /* The supporting lines under an overlay's title. Closures carry the most: a bare
     "Closed" cannot be read — it does not say for how long, whether fees still apply, or
     who decided it. Returns [] for overlays that have nothing extra to add. */
  function overlayExtraLines(ev) {
    if (!ev || ev.kind !== 'closure') { return []; }
    var out = [];

    // How long it runs. A single day needs no range; a fortnight very much does.
    if (ev.starts_on && ev.ends_on && ev.ends_on !== ev.starts_on) {
      out.push(fmtRange(ev.starts_on, ev.ends_on));
    }

    var bits = [];
    if (ev.type_label) { bits.push(ev.type_label); }
    // Whether families are still charged is the question an admin actually has.
    // affects_billing TRUE means billing is paused — the checkbox that sets it reads
    // "Pause billing on these days", and the closures table has always shown it as
    // "Paused". This line said the opposite when it was added.
    bits.push(ev.affects_billing ? 'Billing paused' : 'Billing unchanged');
    out.push(bits.join(' · '));

    if (ev.added_by || ev.added_at) {
      out.push('Added' + (ev.added_by ? ' by ' + ev.added_by : '')
        + (ev.added_at ? ' · ' + fmtStamp(ev.added_at) : ''));
    }
    return out;
  }

  var MONTH_ABBR = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun',
                    'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];

  /* Formatted from the string's own parts. Running a date-only value through Date()
     made this browser read '2026-08-11T00:00:00' as UTC and render it in Toronto as
     Aug 10 — a closure shown starting the day before it does. A plain calendar date
     has no timezone to convert between, so none is applied. */
  function fmtDayLabel(ymd) {
    var p = String(ymd || '').slice(0, 10).split('-');
    if (p.length !== 3) { return String(ymd || ''); }
    var m = parseInt(p[1], 10);
    return (MONTH_ABBR[m - 1] || p[1]) + ' ' + parseInt(p[2], 10);
  }

  function fmtRange(a, b) {
    try {
      var pa = String(a).slice(0, 10).split('-'), pb = String(b).slice(0, 10).split('-');
      // Date.UTC on both ends: the offsets cancel, so the count is a true day difference.
      var ua = Date.UTC(+pa[0], +pa[1] - 1, +pa[2]);
      var ub = Date.UTC(+pb[0], +pb[1] - 1, +pb[2]);
      var days = Math.round((ub - ua) / 86400000) + 1;
      return fmtDayLabel(a) + ' – ' + fmtDayLabel(b)
        + ' (' + days + ' day' + (days === 1 ? '' : 's') + ')';
    } catch (e) { return a + ' – ' + b; }
  }

  function fmtStamp(ts) {
    try {
      // Agency timezone, like every other date on this screen.
      var tz = (window.KT && KT.agencyTz && KT.agencyTz()) || undefined;
      return new Date(ts.replace(' ', 'T') + (/[Zz+]/.test(ts) ? '' : 'Z'))
        .toLocaleString('en-CA', { month: 'short', day: 'numeric', hour: 'numeric', minute: '2-digit', timeZone: tz });
    } catch (e) { return String(ts).slice(0, 16); }
  }

  function titleForCursor() {
    if (state.view === 'day') {
      return state.cursor.toLocaleDateString('en-CA', { weekday: 'long', month: 'long', day: 'numeric', year: 'numeric' });
    }
    if (state.view === 'week') {
      var s = startOfWeek(state.cursor); var e = addDays(s, 6);
      return s.toLocaleDateString('en-CA', { month: 'short', day: 'numeric' }) + ' – ' +
             e.toLocaleDateString('en-CA', { month: 'short', day: 'numeric', year: 'numeric' });
    }
    return monthName(state.cursor);
  }

  // ─── Data load + render ────────────────────────────────────────────
  /* A request carries a start and an end; a calendar needs one entry per day. Walked in
     agency-date terms via ymd() so a range that ends at midnight does not bleed into the
     following day. Capped at a year in case of a bad range — an open-ended loop over dates
     is how a calendar hangs the browser. */
  function indexTimeOff(rows) {
    var byDay = {};
    (rows || []).forEach(function (r) {
      var s = new Date(r.start_at);
      var e = new Date(r.end_at);
      if (isNaN(s) || isNaN(e) || e < s) return;
      var cur = new Date(s.getFullYear(), s.getMonth(), s.getDate());
      var last = new Date(e.getFullYear(), e.getMonth(), e.getDate());
      var guard = 0;
      while (cur <= last && guard++ < 366) {
        var k = ymd(cur);
        (byDay[k] || (byDay[k] = [])).push(r);
        cur = addDays(cur, 1);
      }
    });
    return byDay;
  }

  function timeOffChip(r) {
    var name = r.user_name || 'Someone';
    var type = String(r.request_type || 'time off');
    return Dom.el('div', {
      title: name + ' — ' + type + ' (approved)',
      style: 'position:relative;z-index:1;background:#FEF3C7;border-left:3px solid #F59E0B;'
        + 'border-radius:5px;padding:3px 6px;margin-bottom:3px;font-size:11px;font-weight:700;'
        + 'color:#92400E;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;',
    }, '🌴 ' + name);
  }

  function reload(calRoot) {
    var titleEl = document.getElementById('kt-cal-title');
    if (titleEl) titleEl.textContent = titleForCursor();
    Dom.clear(calRoot);
    calRoot.appendChild(Dom.el('div', { style: 'padding:30px;text-align:center;color:#64748B;font-size:13px;' }, 'Loading shifts…'));

    var start, end;
    if (state.view === 'day') {
      start = new Date(state.cursor); end = new Date(state.cursor);
    } else if (state.view === 'week') {
      start = startOfWeek(state.cursor); end = addDays(start, 6);
    } else {
      // Month grid spans from the Monday of the week containing day 1, to the
      // Sunday of the week containing the last day. Calendar pages render
      // those leading + trailing days greyed.
      start = startOfWeek(startOfMonth(state.cursor));
      var monthEnd = endOfMonth(state.cursor);
      end = addDays(startOfWeek(monthEnd), 6);
    }
    var q = new URLSearchParams({ centre_id: String(state.centreId), start: ymd(start), end: ymd(end) }).toString();
    // Approved leave, fetched alongside the shifts. /director/schedule/time-off-blocks has
    // existed all along and no screen ever called it — so a director scheduling a week
    // could not see who was away, which is the one thing they need before assigning a
    // shift. A failure here must not lose the calendar, so it degrades to no blocks.
    var tq = new URLSearchParams({ start: ymd(start), end: ymd(end) }).toString();
    // Birthdays, absences, vacations, staff time off and closures — already flattened
    // to one entry per day by the API, so nothing here needs to know which table any of
    // it came from. Degrades to an empty overlay rather than losing the calendar.
    var oq = new URLSearchParams({ from: ymd(start), to: ymd(end) }).toString();
    Promise.all([
      Api.get('/director/schedule/range?' + q),
      Api.get('/director/schedule/time-off-blocks?' + tq).catch(function () { return { data: [] }; }),
      Api.get('/director/calendar/overlays?' + oq).catch(function () { return { events: [] }; }),
    ]).then(function (both) {
      var data = both[0];
      state.timeOff = indexTimeOff((both[1] && both[1].data) || []);
      state.overlays = indexOverlays((both[2] && both[2].events) || []);
      state.days = data.days || {};
      state.rooms = data.rooms || [];
      Dom.clear(calRoot);
      var view = state.view === 'day' ? renderDay(start, calRoot)
        : state.view === 'week' ? renderWeek(start, end, calRoot)
        : renderMonth(start, end, calRoot);

      // Grid on the left, the week's agenda on the right. The Day view is already an
      // agenda, so it does not get a second one beside it.
      if (state.view === 'day') {
        calRoot.appendChild(view);
      } else {
        var shell = Dom.el('div', { style: 'display:flex;gap:14px;align-items:flex-start;' });
        var left = Dom.el('div', { style: 'flex:1 1 auto;min-width:0;' });
        left.appendChild(view);
        shell.appendChild(left);
        shell.appendChild(renderAgenda(calRoot));
        calRoot.appendChild(shell);
      }
    }).catch(function (e) {
      Dom.clear(calRoot);
      calRoot.appendChild(Dom.el('div', { style: 'padding:24px;color:#DC2626;' }, 'Could not load: ' + (e.message || 'error')));
    });
  }

  // ─── Week view: 7 day columns with hour gutter ─────────────────────
  function renderWeek(start, end, calRoot) {
    var grid = Dom.el('div', { style: 'background:white;border-radius:12px;box-shadow:0 1px 3px rgba(0,0,0,.04);overflow:hidden;' });
    // Header row
    var header = Dom.el('div', { style: 'display:grid;grid-template-columns:64px repeat(7,minmax(0,1fr));border-bottom:1px solid #E5E7EB;background:#FAFBFC;' });
    header.appendChild(Dom.el('div', { style: 'padding:10px;font-size:11px;color:#64748B;font-weight:700;text-transform:uppercase;' }, ''));
    for (var i = 0; i < 7; i++) {
      var d = addDays(start, i);
      var isToday = ymd(d) === ymd(new Date());
      var col = Dom.el('div', { style: 'padding:10px;text-align:center;border-left:1px solid #F3F4F6;'
        + (isToday ? 'background:#EFF6FF;box-shadow:inset 0 2px 0 #1F6080;' : '') });
      col.appendChild(Dom.el('div', { style: 'font-size:10px;font-weight:700;letter-spacing:0.5px;color:#6B7280;text-transform:uppercase;' }, d.toLocaleDateString('en-CA', { weekday: 'short' })));
      col.appendChild(Dom.el('div', {
        style: 'font-size:18px;font-weight:800;line-height:1.5;'
          + (isToday
              ? 'color:#fff;background:#1F6080;border-radius:999px;width:30px;height:30px;margin:0 auto;'
              : 'color:#111827;'),
      }, String(d.getDate())));
      header.appendChild(col);
    }
    grid.appendChild(header);

    // Body — single day-cell per column with shifts stacked
    var body = Dom.el('div', { style: 'display:grid;grid-template-columns:64px repeat(7,minmax(0,1fr));min-height:480px;' });
    body.appendChild(Dom.el('div', { style: 'border-right:1px solid #F3F4F6;background:#FAFBFC;font-size:10px;color:#64748B;padding:6px;text-align:right;line-height:1.4;' }, ' '));
    installDragSelect(body, calRoot);
    for (var j = 0; j < 7; j++) {
      var dj = addDays(start, j);
      var key = ymd(dj);
      var dayShifts = (state.days[key] && state.days[key].shifts) || [];
      dayShifts = applyFilters(dayShifts);
      var cell = Dom.el('div', { style: 'border-left:1px solid #F3F4F6;padding:6px;min-height:120px;cursor:pointer;position:relative;', 'data-date': key });
      cell.addEventListener('click', function (e) {
        // The day opens the DAY, not a blank shift form. Adding lives inside it.
        if (e.target === e.currentTarget || e.target.dataset.cellBg) {
          openDayModal(e.currentTarget.dataset.date, calRoot);
        }
      });
      // Cell hover hint
      var hint = Dom.el('div', { 'data-cell-bg': '1', style: 'position:absolute;inset:0;pointer-events:auto;' });
      cell.appendChild(hint);
      // Above the shifts: who is away is the thing you need before reading who is on.
      filterOffs((state.timeOff && state.timeOff[key]) || []).forEach(function (t) { cell.appendChild(timeOffChip(t)); });
      // Birthdays, absences, vacations and closures. These were added to the month view
      // only on the first pass, and week is the default view — so the whole feature was
      // invisible to anyone who had not switched to month.
      overlaysFor(key).forEach(function (ev) { cell.appendChild(overlayChip(ev, calRoot)); });
      dayShifts.forEach(function (s) { cell.appendChild(renderShiftPill(s, calRoot)); });
      body.appendChild(cell);
    }
    grid.appendChild(body);
    return grid;
  }

  // ─── Month view: full grid ─────────────────────────────────────────
  function renderMonth(start, end, calRoot) {
    var monthMid = state.cursor.getMonth();
    var grid = Dom.el('div', { style: 'background:white;border-radius:12px;box-shadow:0 1px 3px rgba(0,0,0,.04);overflow:hidden;' });
    /* minmax(0,...) rather than a bare 1fr on every track here. A grid ITEM defaults to
       min-width:auto, so 1fr is floored at the widest cell's min-content and the row
       grows past its card instead of dividing the space: the month grid measured 1372px
       inside a 1279px card, and the card clips, so Sunday was cut in half. The chips
       already ellipsis, so there is nothing to lose by letting the columns shrink. */
    var header = Dom.el('div', { style: 'display:grid;grid-template-columns:repeat(7,minmax(0,1fr));border-bottom:1px solid #E5E7EB;background:#FAFBFC;' });
    ['Mon','Tue','Wed','Thu','Fri','Sat','Sun'].forEach(function (n) {
      header.appendChild(Dom.el('div', { style: 'padding:10px;text-align:center;font-size:11px;font-weight:700;color:#6B7280;letter-spacing:1px;text-transform:uppercase;' }, n));
    });
    grid.appendChild(header);

    var body = Dom.el('div', { style: 'display:grid;grid-template-columns:repeat(7,minmax(0,1fr));' });
    installDragSelect(body, calRoot);
    var cursor = new Date(start);
    while (cursor <= end) {
      var key = ymd(cursor);
      var inMonth = cursor.getMonth() === monthMid;
      var isToday = key === ymd(new Date());
      var dayShifts = (state.days[key] && state.days[key].shifts) || [];
      dayShifts = applyFilters(dayShifts);
      // Today is an accent, the way a calendar is read: a tinted cell and a ring, so the
      // eye lands on it without reading a single date. A pill on the number alone is easy
      // to miss in a grid of forty-two of them.
      var cell = Dom.el('div', {
        style: 'border:1px solid ' + (isToday ? '#93C5FD' : '#F3F4F6') + ';padding:6px;min-height:96px;cursor:pointer;'
          + 'background:' + (isToday ? '#F5FAFF' : (inMonth ? 'white' : '#FAFBFC')) + ';position:relative;'
          + (isToday ? 'box-shadow:inset 0 2px 0 #1F6080;' : ''),
        'data-date': key,
      });
      cell.addEventListener('click', function (e) {
        if (e.target === e.currentTarget || e.target.dataset.cellBg) {
          openDayModal(e.currentTarget.dataset.date, calRoot);
        }
      });
      cell.appendChild(Dom.el('div', {
        style: 'font-size:12px;font-weight:' + (isToday ? '800' : '700') + ';margin-bottom:4px;display:inline-block;'
          + 'min-width:20px;text-align:center;padding:2px 6px;border-radius:999px;'
          + (isToday
              ? 'color:#fff;background:#1F6080;'
              : 'color:' + (inMonth ? '#374151' : '#9CA3AF') + ';background:transparent;'),
      }, String(cursor.getDate())));
      var hint = Dom.el('div', { 'data-cell-bg': '1', style: 'position:absolute;inset:0;' });
      cell.appendChild(hint);
      filterOffs((state.timeOff && state.timeOff[key]) || []).slice(0, 2).forEach(function (t) { cell.appendChild(timeOffChip(t)); });
      // Closures first — a closed day changes what every other chip on it means.
      overlaysFor(key).slice(0, 3).forEach(function (ev) { cell.appendChild(overlayChip(ev, calRoot)); });
      if (overlaysFor(key).length > 3) {
        cell.appendChild(Dom.el('div', { style: 'font-size:10px;color:#64748B;position:relative;z-index:1;' }, '+ ' + (overlaysFor(key).length - 3) + ' more'));
      }
      dayShifts.slice(0, 3).forEach(function (s) { cell.appendChild(renderShiftChip(s, calRoot)); });
      if (dayShifts.length > 3) cell.appendChild(Dom.el('div', { style: 'font-size:10px;color:#64748B;margin-top:2px;position:relative;z-index:1;' }, '+ ' + (dayShifts.length - 3) + ' more'));
      body.appendChild(cell);
      cursor = addDays(cursor, 1);
    }
    grid.appendChild(body);
    return grid;
  }

  /* ── Overlays: birthdays, absences, vacations, time off, closures ──────
     One flat list from the API, indexed by date here. Closures sort first because a
     closed day changes what every other entry on it means. */
  var OVERLAY_TONE = {
    celebrate: { bg: '#FCE7F3', fg: '#9D174D', bar: '#DB2777' },
    away:      { bg: '#E0F2FE', fg: '#075985', bar: '#0284C7' },
    pending:   { bg: '#FEF3C7', fg: '#92400E', bar: '#F59E0B' },
    closed:    { bg: '#FEE2E2', fg: '#991B1B', bar: '#DC2626' },
  };
  var OVERLAY_ORDER = { closure: 0, timeoff: 1, vacation: 2, absence: 3, birthday: 4 };

  function indexOverlays(events) {
    var byDay = {};
    (events || []).forEach(function (e) {
      if (!e || !e.date) return;
      (byDay[e.date] || (byDay[e.date] = [])).push(e);
    });
    Object.keys(byDay).forEach(function (k) {
      byDay[k].sort(function (a, b) {
        return (OVERLAY_ORDER[a.kind] == null ? 9 : OVERLAY_ORDER[a.kind])
             - (OVERLAY_ORDER[b.kind] == null ? 9 : OVERLAY_ORDER[b.kind]);
      });
    });
    return byDay;
  }

  /* The GRID's view of a day's overlays — staff-filtered, because six call sites each
     wrapping the call themselves is how one of them gets missed. The day modal wants the
     whole day and asks for it by name below. */
  function overlaysFor(key) { return filterOverlays(allOverlaysFor(key)); }
  function allOverlaysFor(key) { return (state.overlays && state.overlays[key]) || []; }

  function overlayChip(ev, calRoot) {
    var t = OVERLAY_TONE[ev.tone] || OVERLAY_TONE.away;
    return attachEventClick(Dom.el('div', {
      title: [(ev.icon || '') + ' ' + (ev.title || '') + (ev.detail ? ' — ' + ev.detail : '')]
        .concat(overlayExtraLines(ev)).join('\n'),
      style: 'position:relative;z-index:2;background:' + t.bg + ';border-left:3px solid ' + t.bar + ';'
        + 'border-radius:5px;padding:3px 6px;margin-bottom:3px;font-size:11px;font-weight:700;'
        + 'color:' + t.fg + ';overflow:hidden;text-overflow:ellipsis;white-space:nowrap;',
    }, (ev.icon || '') + ' ' + (ev.title || '')), ev, calRoot);
  }

  /* ── Day view ──────────────────────────────────────────────────────────
     An agenda, not a one-column hour grid: on a single day the question is "what is
     happening and who is missing", and an hour grid one column wide is mostly empty
     space. The week grid still answers "when". */
  function renderDay(day, calRoot) {
    var key = ymd(day);
    var wrap = Dom.el('div', { style: 'background:white;border-radius:12px;box-shadow:0 1px 3px rgba(0,0,0,.04);padding:16px;' });

    var shifts = (state.days[key] && state.days[key].shifts) || [];
    shifts = applyFilters(shifts);
    shifts = shifts.slice().sort(function (a, b) { return String(a.starts_hm).localeCompare(String(b.starts_hm)); });

    var evs = overlaysFor(key);
    var offs = filterOffs((state.timeOff && state.timeOff[key]) || []);

    if (evs.length) {
      wrap.appendChild(Dom.el('div', { style: 'font-size:11px;font-weight:800;letter-spacing:1px;color:#64748B;text-transform:uppercase;margin:0 0 8px;' }, 'On this day'));
      var list = Dom.el('div', { style: 'display:flex;flex-wrap:wrap;gap:8px;margin-bottom:18px;' });
      evs.forEach(function (ev) {
        var t = OVERLAY_TONE[ev.tone] || OVERLAY_TONE.away;
        var row = Dom.el('div', { style: 'background:' + t.bg + ';border-left:3px solid ' + t.bar + ';border-radius:8px;padding:7px 11px;min-width:190px;' });
        row.appendChild(Dom.el('div', { style: 'font-size:13px;font-weight:800;color:' + t.fg + ';' }, (ev.icon || '') + ' ' + (ev.title || '')));
        if (ev.detail) row.appendChild(Dom.el('div', { style: 'font-size:12px;color:' + t.fg + ';opacity:.85;margin-top:1px;' }, ev.detail));
        overlayExtraLines(ev).forEach(function (line, i) {
          row.appendChild(Dom.el('div', {
            style: 'font-size:11.5px;color:' + t.fg + ';opacity:' + (i === 0 ? '.8' : '.65') + ';margin-top:1px;line-height:1.45;',
          }, line));
        });
        list.appendChild(attachEventClick(row, ev, calRoot));
      });
      wrap.appendChild(list);
    }

    wrap.appendChild(Dom.el('div', { style: 'font-size:11px;font-weight:800;letter-spacing:1px;color:#64748B;text-transform:uppercase;margin:0 0 8px;' }, 'Shifts'));
    if (!shifts.length) {
      var none = Dom.el('div', { style: 'color:#64748B;font-size:13px;padding:10px 0 4px;' }, 'No shifts scheduled.');
      wrap.appendChild(none);
    } else {
      shifts.forEach(function (sh) { wrap.appendChild(renderShiftPill(sh, calRoot)); });
    }

    offs.forEach(function (o) { wrap.appendChild(timeOffChip(o)); });

    var add = Dom.el('button', {
      style: 'margin-top:14px;background:#1F6080;color:white;border:none;padding:9px 16px;border-radius:8px;font-size:13px;font-weight:700;cursor:pointer;',
    }, '+ Add a shift');
    add.addEventListener('click', function () { openShiftModal({ date: key }, calRoot); });
    wrap.appendChild(add);

    return wrap;
  }

  /* ── Week agenda sidebar ───────────────────────────────────────────────
     Always the seven days of the week containing the cursor, whichever view is on the
     left — the month grid shows five weeks, and an agenda that long is a list nobody
     reads. Hidden below 1100px, where it would squeeze the grid it is meant to explain. */
  function renderAgenda(calRoot) {
    // Below 1100px the sidebar would squeeze the grid it exists to explain, so it stands
    // down rather than competing for the width.
    if (!document.getElementById('kt-cal-agenda-css')) {
      var st = document.createElement('style');
      st.id = 'kt-cal-agenda-css';
      st.textContent = '@media (max-width:1100px){.kt-cal-agenda{display:none !important;}}';
      document.head.appendChild(st);
    }
    var wk = startOfWeek(state.cursor);
    var side = Dom.el('div', {
      class: 'kt-cal-agenda',
      style: 'flex:0 0 292px;width:292px;background:white;border-radius:12px;box-shadow:0 1px 3px rgba(0,0,0,.04);padding:14px;max-height:78vh;overflow-y:auto;',
    });

    side.appendChild(Dom.el('div', { style: 'font-size:11px;font-weight:800;letter-spacing:1px;color:#64748B;text-transform:uppercase;margin:0 0 10px;' },
      'This week · ' + wk.toLocaleDateString('en-CA', { month: 'short', day: 'numeric' }) + ' – ' + addDays(wk, 6).toLocaleDateString('en-CA', { month: 'short', day: 'numeric' })));

    var todayKey = ymd(new Date());
    var anything = false;

    for (var i = 0; i < 7; i++) {
      var d = addDays(wk, i);
      var key = ymd(d);
      var evs = overlaysFor(key);
      var offs = filterOffs((state.timeOff && state.timeOff[key]) || []);
      var shifts = (state.days[key] && state.days[key].shifts) || [];
      shifts = applyFilters(shifts);
      if (!evs.length && !offs.length && !shifts.length) continue;
      anything = true;

      var isToday = key === todayKey;
      // Days that have already happened are still worth having — you look up what was
      // meant to happen yesterday — but they should not compete with what is still to
      // come. So they recede rather than disappear, and today gets an accent rail.
      var isPast = key < todayKey;
      var dayBox = Dom.el('div', {
        style: 'margin:0 0 12px;'
          + (isToday ? 'border-left:3px solid #1F6080;padding-left:9px;margin-left:-12px;' : '')
          + (isPast ? 'opacity:.5;' : ''),
      });
      dayBox.appendChild(Dom.el('div', {
        style: 'font-size:12.5px;font-weight:800;margin:0 0 5px;'
          + (isToday
              ? 'color:#fff;background:#1F6080;display:inline-block;padding:2px 9px;border-radius:999px;'
              : 'color:' + (isPast ? '#94A3B8' : '#0D1B2A') + ';'),
      }, d.toLocaleDateString('en-CA', { weekday: 'short', month: 'short', day: 'numeric' }) + (isToday ? ' · today' : '')));

      evs.forEach(function (ev) {
        var t = OVERLAY_TONE[ev.tone] || OVERLAY_TONE.away;
        var r = Dom.el('div', { style: 'background:' + t.bg + ';border-left:3px solid ' + t.bar + ';border-radius:6px;padding:5px 8px;margin-bottom:4px;' });
        r.appendChild(Dom.el('div', { style: 'font-size:12.5px;font-weight:700;color:' + t.fg + ';' }, (ev.icon || '') + ' ' + (ev.title || '')));
        if (ev.detail) {
          r.appendChild(Dom.el('div', { style: 'font-size:11.5px;color:' + t.fg + ';opacity:.85;line-height:1.4;' }, ev.detail));
        }
        overlayExtraLines(ev).forEach(function (line, i) {
          r.appendChild(Dom.el('div', {
            style: 'font-size:11px;color:' + t.fg + ';opacity:' + (i === 0 ? '.8' : '.65') + ';line-height:1.4;',
          }, line));
        });
        dayBox.appendChild(attachEventClick(r, ev, calRoot));
      });

      offs.forEach(function (o) { dayBox.appendChild(timeOffChip(o)); });

      // Shifts are summarised, not listed: the grid beside this already draws every one,
      // and repeating them turns the agenda into a second, worse calendar.
      if (shifts.length) {
        dayBox.appendChild(Dom.el('div', { style: 'font-size:11.5px;color:#64748B;padding:2px 2px 0;' },
          shifts.length + (shifts.length === 1 ? ' shift' : ' shifts') + ' scheduled'));
      }
      side.appendChild(dayBox);
    }

    if (!anything) {
      side.appendChild(Dom.el('div', { style: 'color:#64748B;font-size:13px;padding:6px 2px;' }, 'Nothing scheduled or noted this week.'));
    }
    return side;
  }

  function renderShiftPill(s, calRoot) {
    var color = ROLE_COLORS[s.role] || '#1F6080';
    var pill = Dom.el('div', {
      style: 'background:color-mix(in srgb,' + color + ' 14%, white);border-left:3px solid ' + color + ';border-radius:6px;padding:4px 6px;margin-bottom:4px;font-size:11px;cursor:pointer;position:relative;z-index:1;',
    });
    pill.appendChild(Dom.el('div', { style: 'font-weight:700;color:' + color + ';' }, s.starts_hm + '–' + s.ends_hm));
    pill.appendChild(Dom.el('div', { style: 'color:#374151;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;' }, s.user_name));
    pill.appendChild(Dom.el('div', { style: 'color:#64748B;font-size:10px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;' }, s.room_name + ' · ' + s.role));
    pill.addEventListener('click', function (e) { e.stopPropagation(); openShiftModal(s, calRoot); });
    return pill;
  }

  function renderShiftChip(s, calRoot) {
    var color = ROLE_COLORS[s.role] || '#1F6080';
    var chip = Dom.el('div', {
      style: 'background:color-mix(in srgb,' + color + ' 14%, white);border-left:3px solid ' + color + ';border-radius:4px;padding:2px 5px;margin-bottom:2px;font-size:10px;color:#374151;cursor:pointer;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;position:relative;z-index:1;',
      title: s.user_name + ' · ' + s.starts_hm + '–' + s.ends_hm + ' · ' + s.role,
    }, s.starts_hm + ' ' + s.user_name);
    chip.addEventListener('click', function (e) { e.stopPropagation(); openShiftModal(s, calRoot); });
    return chip;
  }

  // ─── Shift modal (create/edit) ─────────────────────────────────────
  /* ── Event pop-out ──────────────────────────────────────────────────────
     A calendar is expected to open what you click. These chips could only be
     hovered for a tooltip, which is no use on a touch screen and hides the detail
     the row already knows from everybody else.

     Closures are the only overlay this screen owns, so they are the only kind that
     edits here. The others link to the screen that owns them — a second editor for
     time off or vacation holds would only drift from the real one. */
  var EVENT_HOME = {
    closure:  { hash: 'closures',           label: 'Closures' },
    timeoff:  { hash: 'time-off',           label: 'Time off requests' },
    vacation: { hash: 'vacation-holds',     label: 'Vacation holds' },
    absence:  { hash: 'attendance-pattern', label: 'Attendance' },
    birthday: { hash: 'children',           label: 'Children' },
  };

  var CLOSURE_TYPES = [
    ['holiday', 'Holiday'], ['pd_day', 'PD day'], ['emergency', 'Emergency'],
    ['renovation', 'Renovation'], ['other', 'Other'],
  ];

  var KIND_LABEL = {
    closure: 'Closure', timeoff: 'Staff time off', vacation: 'Family vacation',
    absence: 'Child absence', birthday: 'Birthday',
  };

  function attachEventClick(el, ev, calRoot) {
    el.style.cursor = 'pointer';
    el.setAttribute('role', 'button');
    el.setAttribute('tabindex', '0');
    // The cell underneath opens the new-shift form, and the month grid drag-selects
    // from mousedown — both have to be held off or clicking an event does two things.
    el.addEventListener('mousedown', function (e) { e.stopPropagation(); });
    el.addEventListener('click', function (e) {
      e.stopPropagation();
      openEventModal(ev, calRoot);
    });
    el.addEventListener('keydown', function (e) {
      if (e.key === 'Enter' || e.key === ' ') {
        e.preventDefault(); e.stopPropagation();
        openEventModal(ev, calRoot);
      }
    });
    return el;
  }

  function clearNode(el) { while (el.firstChild) { el.removeChild(el.firstChild); } }

  function fullDayLabel(d) {
    // Date-only, formatted from its own parts — see KT.dayLabel. Parsed, it lands on
    // the day before anywhere west of UTC.
    if (window.KT && KT.dayLabel) { return KT.dayLabel(d); }
    return fmtDayLabel(d);
  }

  /* ── The add menu ───────────────────────────────────────────────────────
     Everything drawn on this calendar except a shift belongs to another screen. Each
     entry sends you there with ?add=1 so that screen opens its own dialog, carrying
     the day you were looking at (kt-addfrom.js does the opening).

     `nav` is the hash the entry needs the user to actually have. A director who
     cannot reach Conferences should not be offered a shortcut into a screen that
     will turn them away, so entries whose screen is missing from the sidebar are not
     drawn at all. */
  var ADD_ITEMS = [
    { icon: '🧑‍🏫', label: 'Shift',            sub: 'On this calendar',   shift: true },
    { icon: '🚫',         label: 'Closure',          sub: 'Closures',           nav: 'closures' },
    { icon: '🏖',         label: 'Vacation hold',    sub: 'Vacation holds',     nav: 'vacation-holds' },
    { icon: '🚐',         label: 'Field trip',       sub: 'Field trips',        nav: 'field-trips' },
    { icon: '🗣',         label: 'Conference slots', sub: 'Conferences',        nav: 'conferences' },
    { icon: '🧒',         label: 'Child start date', sub: 'Children',           nav: ['children', 'admin-children', 'admin-families'], noDialog: true },
  ];

  /* The date a shortcut carries: the day the calendar is actually showing. In day view
     that is the cursor. In week or month it is today when today is on screen, and the
     first day shown otherwise — somebody who has paged forward to November and adds a
     closure does not mean today. */
  function addMenuDate() {
    var today = ymd(new Date());
    if (state.view === 'day') { return ymd(state.cursor); }
    var start, end;
    if (state.view === 'week') { start = startOfWeek(state.cursor); end = addDays(start, 6); }
    else { start = startOfMonth(state.cursor); end = endOfMonth(state.cursor); }
    var a = ymd(start), b = ymd(end);
    return (today >= a && today <= b) ? today : a;
  }

  // Returns the first candidate the user actually has, or null. Takes a string or a
  // list, because the same screen is reached by different hashes depending on role.
  function hasScreen(hash) {
    var list = (typeof hash === 'string') ? [hash] : (hash || []);
    for (var i = 0; i < list.length; i++) {
      if (document.querySelector('.app-sidebar a[href="#' + list[i] + '"], .sidebar-nav a[href="#' + list[i] + '"]')) {
        return list[i];
      }
    }
    return null;
  }

  function openAddMenu(anchor, calRoot) {
    var existing = document.getElementById('kt-cal-addmenu');
    if (existing) { existing.remove(); return; }        // second click closes it

    var menu = Dom.el('div', {
      id: 'kt-cal-addmenu',
      style: 'position:fixed;z-index:1200;background:#fff;border:1px solid #E2E8F0;border-radius:12px;'
        + 'box-shadow:0 12px 30px rgba(15,23,42,.16);padding:6px;min-width:236px;',
    });

    var when = addMenuDate();

    ADD_ITEMS.forEach(function (it) {
      var dest = it.nav ? hasScreen(it.nav) : null;
      if (it.nav && !dest) { return; }
      var row = Dom.el('button', {
        type: 'button',
        style: 'display:flex;align-items:center;gap:10px;width:100%;text-align:left;background:none;border:none;'
          + 'padding:8px 10px;border-radius:8px;cursor:pointer;font-size:13px;color:#0D1B2A;',
      });
      row.addEventListener('mouseenter', function () { row.style.background = '#F1F5F9'; });
      row.addEventListener('mouseleave', function () { row.style.background = 'none'; });
      row.appendChild(Dom.el('span', { style: 'font-size:16px;width:22px;text-align:center;flex-shrink:0;' }, it.icon));
      var col = Dom.el('div', { style: 'min-width:0;' });
      col.appendChild(Dom.el('div', { style: 'font-weight:700;line-height:1.25;' }, it.label));
      col.appendChild(Dom.el('div', { style: 'font-size:11.5px;color:#64748B;' }, it.sub));
      row.appendChild(col);
      row.addEventListener('click', function () {
        menu.remove();
        if (it.shift) { openShiftModal(null, calRoot); return; }
        // noDialog: a start date is edited on a child's own record, so there is no add
        // dialog to open — this is a way through to the right screen, nothing more.
        window.location.hash = '#' + dest + (it.noDialog ? '' : '?add=1&date=' + when);
      });
      menu.appendChild(row);
    });

    document.body.appendChild(menu);

    var r = anchor.getBoundingClientRect();
    var mw = menu.offsetWidth, mh = menu.offsetHeight;
    menu.style.left = Math.max(8, Math.min(r.right - mw, window.innerWidth - mw - 8)) + 'px';
    menu.style.top = (r.bottom + mh + 8 > window.innerHeight ? r.top - mh - 6 : r.bottom + 6) + 'px';

    setTimeout(function () {
      function away(e) {
        if (menu.contains(e.target) || anchor.contains(e.target)) { return; }
        menu.remove(); document.removeEventListener('mousedown', away); document.removeEventListener('keydown', esc2);
      }
      function esc2(e) {
        if (e.key !== 'Escape') { return; }
        menu.remove(); document.removeEventListener('mousedown', away); document.removeEventListener('keydown', esc2);
      }
      document.addEventListener('mousedown', away);
      document.addEventListener('keydown', esc2);
    }, 0);
  }

  function openEventModal(ev, calRoot) {
    var t = OVERLAY_TONE[ev.tone] || OVERLAY_TONE.away;
    var canEdit = ev.kind === 'closure' && ev.closure_id;

    var overlay = Dom.el('div', { style: 'position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:1000;display:flex;align-items:center;justify-content:center;padding:24px;' });
    var modal = Dom.el('div', { style: 'background:white;border-radius:14px;max-width:480px;width:100%;box-shadow:0 12px 36px rgba(0,0,0,.25);max-height:calc(100vh - 48px);overflow-y:auto;' });
    overlay.appendChild(modal);

    function close() {
      document.removeEventListener('keydown', onEsc);
      overlay.remove();
    }
    function onEsc(e) { if (e.key === 'Escape') { close(); } }
    document.addEventListener('keydown', onEsc);
    overlay.addEventListener('click', function (e) { if (e.target === overlay) { close(); } });

    function header(titleText, subText) {
      var head = Dom.el('div', { style: 'background:' + t.bg + ';border-left:5px solid ' + t.bar + ';border-radius:14px 14px 0 0;padding:18px 20px 16px;display:flex;align-items:flex-start;justify-content:space-between;gap:12px;' });
      var col = Dom.el('div', { style: 'min-width:0;' });
      col.appendChild(Dom.el('div', { style: 'font-size:17px;font-weight:800;color:' + t.fg + ';line-height:1.25;' }, titleText));
      if (subText) {
        col.appendChild(Dom.el('div', { style: 'font-size:13px;color:' + t.fg + ';opacity:.85;margin-top:3px;line-height:1.4;' }, subText));
      }
      head.appendChild(col);
      var x = Dom.el('button', { type: 'button', 'aria-label': 'Close', style: 'background:transparent;border:none;font-size:22px;line-height:1;color:' + t.fg + ';opacity:.6;cursor:pointer;padding:0 2px;flex-shrink:0;' }, '×');
      x.addEventListener('click', close);
      head.appendChild(x);
      return head;
    }

    function showDetails() {
      clearNode(modal);
      modal.appendChild(header((ev.icon || '') + ' ' + (ev.title || 'Event'), ev.detail || ''));

      var body = Dom.el('div', { style: 'padding:14px 20px 4px;' });
      var fact = function (label, value) {
        if (value == null || value === '') { return; }
        var r = Dom.el('div', { style: 'display:flex;gap:12px;padding:7px 0;border-bottom:1px solid #F1F5F9;' });
        r.appendChild(Dom.el('div', { style: 'flex:0 0 104px;font-size:12px;font-weight:700;color:#64748B;' }, label));
        r.appendChild(Dom.el('div', { style: 'flex:1;min-width:0;font-size:13px;color:#0D1B2A;line-height:1.45;' }, String(value)));
        body.appendChild(r);
      };

      // A multi-day event says so; a single day needs no range spelled out.
      var when = (ev.starts_on && ev.ends_on && ev.ends_on !== ev.starts_on)
        ? fmtRange(ev.starts_on, ev.ends_on)
        : fullDayLabel(ev.starts_on || ev.date);
      fact('When', when);
      fact('Kind', ev.type_label || KIND_LABEL[ev.kind] || ev.kind);
      fact('Centre', ev.centre_name);
      if (ev.kind === 'closure') {
        // affects_billing TRUE means billing is paused — the checkbox that sets it
        // reads "Pause billing on these days".
        fact('Billing', ev.affects_billing
          ? 'Paused for these days'
          : 'Unchanged — adjusted if the agreement with the family calls for it');
      }
      if (ev.added_by || ev.added_at) {
        fact('Added', (ev.added_by || 'Unknown')
          + (ev.added_at ? ' · ' + fmtStamp(ev.added_at) : ''));
      }
      modal.appendChild(body);

      var foot = Dom.el('div', { style: 'display:flex;align-items:center;justify-content:space-between;gap:10px;padding:14px 20px 18px;margin-top:8px;border-top:1px solid #E5E7EB;' });
      var left = Dom.el('div', { style: 'display:flex;gap:8px;' });
      var right = Dom.el('div', { style: 'display:flex;gap:8px;' });

      if (canEdit) {
        var del = Dom.el('button', { type: 'button', style: 'background:white;color:#DC2626;border:1px solid #DC2626;padding:8px 14px;border-radius:8px;font-weight:700;cursor:pointer;font-size:13px;' }, 'Delete');
        del.addEventListener('click', async function () {
          var ok = await KT.confirm('Delete this closure? Everyone who was told about it will be told it has been removed.');
          if (!ok) { return; }
          del.disabled = true; del.textContent = 'Deleting…';
          Api.delete('/operations/closures/' + ev.closure_id).then(function () {
            close(); reload(calRoot);
          }).catch(function (e) {
            del.disabled = false; del.textContent = 'Delete';
            if (KT.toast) KT.toast('⚠️', 'Could not delete', (e && e.message) || 'Please try again.', '#B91C1C');
          });
        });
        left.appendChild(del);
      }

      // Where this kind of event actually lives, for anything this screen does not own.
      var home = EVENT_HOME[ev.kind];
      if (home && !canEdit) {
        var go = Dom.el('button', { type: 'button', style: 'background:white;color:#1F6080;border:1px solid #CBD5E1;padding:8px 14px;border-radius:8px;font-weight:700;cursor:pointer;font-size:13px;' }, 'Open ' + home.label);
        go.addEventListener('click', function () { close(); window.location.hash = '#' + home.hash; });
        right.appendChild(go);
      }
      if (canEdit) {
        var edit = Dom.el('button', { type: 'button', style: 'background:white;color:#1F6080;border:1px solid #CBD5E1;padding:8px 14px;border-radius:8px;font-weight:700;cursor:pointer;font-size:13px;' }, 'Edit');
        edit.addEventListener('click', showEdit);
        right.appendChild(edit);
      }
      var closeBtn = Dom.el('button', { type: 'button', style: 'background:#1F6080;color:white;border:none;padding:8px 18px;border-radius:8px;font-weight:700;cursor:pointer;font-size:13px;' }, 'Close');
      closeBtn.addEventListener('click', close);
      right.appendChild(closeBtn);

      foot.appendChild(left);
      foot.appendChild(right);
      modal.appendChild(foot);
    }

    function showEdit() {
      clearNode(modal);
      modal.appendChild(header('✏️ Edit closure', ev.centre_name || ''));

      var body = Dom.el('div', { style: 'padding:16px 20px 0;' });

      var typeSel = Dom.el('select', { style: inputStyle() });
      CLOSURE_TYPES.forEach(function (p) {
        var o = Dom.el('option', { value: p[0] }, p[1]);
        if (p[0] === ev.closure_type) { o.selected = true; }
        typeSel.appendChild(o);
      });
      body.appendChild(labelEl('Type'));
      body.appendChild(typeSel);

      var startIn = Dom.el('input', { type: 'date', value: ev.starts_on || ev.date || '', style: inputStyle() });
      body.appendChild(labelEl('First day closed'));
      body.appendChild(startIn);

      var endIn = Dom.el('input', { type: 'date', value: ev.ends_on || '', style: inputStyle() });
      body.appendChild(labelEl('Last day closed (leave empty for a single day)'));
      body.appendChild(endIn);

      var reasonIn = Dom.el('input', { type: 'text', maxlength: '200', value: ev.reason || '', placeholder: 'e.g. Civic Holiday', style: inputStyle() });
      body.appendChild(labelEl('Reason'));
      body.appendChild(reasonIn);

      var billLbl = Dom.el('label', { style: 'display:flex;align-items:center;gap:9px;font-size:13px;color:#374151;font-weight:600;margin:2px 0 4px;cursor:pointer;' });
      var bill = Dom.el('input', { type: 'checkbox', style: 'width:16px;height:16px;accent-color:#1F6080;margin:0;' });
      bill.checked = !!ev.affects_billing;
      billLbl.appendChild(bill);
      billLbl.appendChild(Dom.el('span', {}, 'Pause billing on these days'));
      body.appendChild(billLbl);

      var msg = Dom.el('div', { style: 'font-size:12.5px;color:#B91C1C;font-weight:600;min-height:17px;margin-top:8px;' });
      body.appendChild(msg);
      modal.appendChild(body);

      var foot = Dom.el('div', { style: 'display:flex;align-items:center;justify-content:flex-end;gap:8px;padding:12px 20px 18px;border-top:1px solid #E5E7EB;margin-top:10px;' });
      var cancel = Dom.el('button', { type: 'button', style: 'background:white;color:#374151;border:1px solid #CBD5E1;padding:8px 14px;border-radius:8px;font-weight:700;cursor:pointer;font-size:13px;' }, 'Cancel');
      cancel.addEventListener('click', showDetails);
      var save = Dom.el('button', { type: 'button', style: 'background:#1F6080;color:white;border:none;padding:8px 18px;border-radius:8px;font-weight:700;cursor:pointer;font-size:13px;' }, 'Save changes');
      save.addEventListener('click', function () {
        msg.textContent = '';
        if (!startIn.value) { msg.textContent = 'Pick the first day closed.'; return; }
        // Checked here as well as on the server: a range that ends before it starts is
        // worth saying plainly rather than coming back as a validation error.
        if (endIn.value && endIn.value < startIn.value) {
          msg.textContent = 'The last day cannot be before the first day.';
          return;
        }
        save.disabled = true; save.textContent = 'Saving…';
        Api.patch('/operations/closures/' + ev.closure_id, {
          closure_type: typeSel.value,
          closure_date: startIn.value,
          end_date: (endIn.value && endIn.value !== startIn.value) ? endIn.value : null,
          reason: reasonIn.value.trim() || null,
          affects_billing: bill.checked,
        }).then(function () {
          close();
          reload(calRoot);
          if (KT.toast) { KT.toast('✅', 'Closure updated', 'Everyone affected has been told it changed.', '#1E8E60'); }
        }).catch(function (e) {
          save.disabled = false; save.textContent = 'Save changes';
          msg.textContent = (e && e.message) || 'Could not save. Please try again.';
        });
      });
      foot.appendChild(cancel);
      foot.appendChild(save);
      modal.appendChild(foot);
    }

    showDetails();
    document.body.appendChild(overlay);
  }

  /**
   * Everything on one day, in one list.
   *
   * Reads the state the grid already holds rather than fetching again — the day you
   * clicked is by definition inside the range on screen, so a round trip would only add
   * latency and a chance to disagree with the cells behind the modal.
   *
   * Rows delegate to the same modals the chips use. That is the point: the detail view,
   * "added by", Edit and Delete all already existed and were simply unreachable for
   * anything the cell had clipped.
   */
  function openDayModal(dateKey, calRoot) {
    var overlay = Dom.el('div', {
      style: 'position:fixed;inset:0;background:rgba(15,23,42,.45);z-index:9000;'
           + 'display:flex;align-items:center;justify-content:center;padding:20px;',
    });
    var modal = Dom.el('div', {
      style: 'background:#fff;border-radius:14px;max-width:520px;width:100%;'
           + 'max-height:82vh;display:flex;flex-direction:column;overflow:hidden;'
           + 'box-shadow:0 20px 60px rgba(15,23,42,.28);',
    });
    overlay.appendChild(modal);
    document.body.appendChild(overlay);

    function close() {
      document.removeEventListener('keydown', onEsc);
      overlay.remove();
    }
    function onEsc(e) { if (e.key === 'Escape') { close(); } }
    document.addEventListener('keydown', onEsc);
    overlay.addEventListener('click', function (e) { if (e.target === overlay) { close(); } });

    var isToday = dateKey === ymd(new Date());

    // ── header ──
    var head = Dom.el('div', {
      style: 'padding:16px 20px 14px;border-bottom:1px solid #E5E7EB;display:flex;'
           + 'align-items:flex-start;justify-content:space-between;gap:12px;flex:0 0 auto;',
    });
    var hcol = Dom.el('div', { style: 'min-width:0;' });
    hcol.appendChild(Dom.el('div', {
      style: 'font-size:17px;font-weight:800;color:#0D1B2A;line-height:1.25;',
    }, fullDayLabel(dateKey) + (isToday ? '  ·  Today' : '')));
    head.appendChild(hcol);
    var x = Dom.el('button', {
      type: 'button', 'aria-label': 'Close',
      style: 'background:transparent;border:none;font-size:22px;line-height:1;color:#64748B;'
           + 'cursor:pointer;padding:0 2px;flex-shrink:0;',
    }, '×');
    x.addEventListener('click', close);
    head.appendChild(x);
    modal.appendChild(head);

    // ── body ──
    var body = Dom.el('div', { style: 'padding:6px 20px 14px;overflow-y:auto;flex:1 1 auto;' });
    modal.appendChild(body);

    var section = function (label) {
      body.appendChild(Dom.el('div', {
        style: 'font-size:11px;font-weight:800;letter-spacing:.05em;text-transform:uppercase;'
             + 'color:#64748B;margin:14px 0 6px;',
      }, label));
    };
    var rowShell = function (icon, title, sub, tone) {
      var r = Dom.el('div', {
        style: 'display:flex;gap:10px;align-items:flex-start;padding:9px 10px;border-radius:9px;'
             + 'border:1px solid #EEF2F6;margin-bottom:6px;cursor:pointer;background:#fff;',
      });
      r.addEventListener('mouseenter', function () { r.style.background = '#F8FAFC'; });
      r.addEventListener('mouseleave', function () { r.style.background = '#fff'; });
      r.appendChild(Dom.el('span', { style: 'font-size:15px;line-height:1.3;flex:0 0 auto;' }, icon || '•'));
      var col = Dom.el('div', { style: 'min-width:0;flex:1;' });
      col.appendChild(Dom.el('div', {
        style: 'font-size:13.5px;font-weight:600;color:#0F172A;', }, title || ''));
      if (sub) {
        col.appendChild(Dom.el('div', {
          style: 'font-size:12px;color:' + (tone || '#64748B') + ';margin-top:1px;', }, sub));
      }
      r.appendChild(col);
      r.appendChild(Dom.el('span', { style: 'color:#CBD5E1;font-size:16px;flex:0 0 auto;' }, '›'));
      return r;
    };

    var evs = allOverlaysFor(dateKey);
    var offs = (state.timeOff && state.timeOff[dateKey]) || [];
    var shifts = (state.days[dateKey] && state.days[dateKey].shifts) || [];
    // The grid's own filters — role AND staff — are deliberately NOT applied here. You
    // clicked a day to see the day; silently hiding half of it because a filter is set
    // upstairs is how a shift gets double-booked.
    var total = evs.length + offs.length + shifts.length;

    if (!total) {
      body.appendChild(Dom.el('div', {
        style: 'padding:22px 4px 18px;text-align:center;color:#94A3B8;font-size:13.5px;',
      }, 'Nothing scheduled on this day.'));
    }

    if (evs.length) {
      section(evs.length === 1 ? 'Event' : 'Events');
      evs.forEach(function (ev) {
        var r = rowShell(ev.icon, ev.title, ev.detail || (ev.type_label || KIND_LABEL[ev.kind] || ''));
        r.addEventListener('click', function () { close(); openEventModal(ev, calRoot); });
        body.appendChild(r);
      });
    }

    if (offs.length) {
      section('Staff away');
      offs.forEach(function (t) {
        var who = t.user_name || t.name || 'Staff';
        var r = rowShell('🌴', who, t.reason || t.type || 'Time off', '#B45309');
        // Time off has its own screen; the chip did nothing, so neither does this beyond
        // saying who is away.
        r.style.cursor = 'default';
        r.removeChild(r.lastChild);
        body.appendChild(r);
      });
    }

    if (shifts.length) {
      section(shifts.length === 1 ? 'Shift' : 'Shifts');
      shifts.forEach(function (sh) {
        var who = sh.user_name || sh.name || 'Unassigned';
        var when = [sh.starts_at, sh.ends_at].filter(Boolean).join(' – ');
        var r = rowShell('👤', who,
          [when, sh.role, sh.room_name].filter(Boolean).join('  ·  '));
        r.addEventListener('click', function () { close(); openShiftModal(sh, calRoot); });
        body.appendChild(r);
      });
    }

    // ── footer ──
    var foot = Dom.el('div', {
      style: 'display:flex;gap:8px;justify-content:flex-end;padding:13px 20px 16px;'
           + 'border-top:1px solid #E5E7EB;flex:0 0 auto;',
    });
    var add = Dom.el('button', {
      type: 'button',
      style: 'background:#fff;color:#1F6080;border:1px solid #CBD5E1;padding:8px 14px;'
           + 'border-radius:8px;font-weight:700;cursor:pointer;font-size:13px;',
    }, '＋ Add shift');
    add.addEventListener('click', function () { close(); openShiftModal({ date: dateKey }, calRoot); });
    var done = Dom.el('button', {
      type: 'button',
      style: 'background:#1F6080;color:#fff;border:none;padding:8px 18px;border-radius:8px;'
           + 'font-weight:700;cursor:pointer;font-size:13px;',
    }, 'Close');
    done.addEventListener('click', close);
    foot.appendChild(add); foot.appendChild(done);
    modal.appendChild(foot);
  }

  function openShiftModal(prefill, calRoot) {
    var isEdit = prefill && prefill.id;
    var hasRange = prefill && prefill.dateRange && prefill.dateRange.start && prefill.dateRange.end;
    var defaultDate = hasRange
      ? prefill.dateRange.start
      : (prefill && prefill.date ? prefill.date : (prefill && prefill.starts_at ? prefill.starts_at.slice(0, 10) : ymd(new Date())));
    var defaultStart = prefill && prefill.starts_hm ? prefill.starts_hm : '09:00';
    var defaultEnd   = prefill && prefill.ends_hm   ? prefill.ends_hm   : '17:00';
    var rangeDays = hasRange ? (Math.round((new Date(prefill.dateRange.end) - new Date(prefill.dateRange.start)) / 86400000) + 1) : 1;

    var overlay = Dom.el('div', { style: 'position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:1000;display:flex;align-items:center;justify-content:center;padding:24px;' });
    var modal = Dom.el('div', { style: 'background:white;border-radius:14px;max-width:520px;width:100%;padding:24px;box-shadow:0 12px 36px rgba(0,0,0,.25);max-height:calc(100vh - 48px);overflow-y:auto;' });
    overlay.appendChild(modal);

    var hdr = Dom.el('div', { style: 'display:flex;align-items:center;justify-content:space-between;margin-bottom:14px;' });
    hdr.appendChild(Dom.el('h3', { style: 'margin:0;font-size:18px;' }, isEdit ? 'Edit shift' : 'New shift'));
    var x = Dom.el('button', { style: 'background:transparent;border:none;font-size:20px;color:#6B7280;cursor:pointer;' }, '×');
    x.addEventListener('click', function () { overlay.remove(); });
    hdr.appendChild(x);
    modal.appendChild(hdr);

    // Staff + room loads
    var staffSel = Dom.el('select', { style: inputStyle() });
    var roomSel  = Dom.el('select', { style: inputStyle() });
    staffSel.appendChild(Dom.el('option', { value: '' }, 'Loading staff…'));
    roomSel.appendChild(Dom.el('option', { value: '' }, 'Loading rooms…'));

    modal.appendChild(labelEl('Staff member'));
    modal.appendChild(staffSel);
    modal.appendChild(labelEl('Room'));
    modal.appendChild(roomSel);

    // v22p42: drag-selected multi-day banner
    if (hasRange && rangeDays > 1) {
      var rangeBanner = Dom.el('div', {
        style: 'background:#EFF6FF;border:1px solid #BFDBFE;color:#1E40AF;border-radius:8px;padding:10px 12px;font-size:13px;margin-bottom:14px;',
      }, '📅 Creating ' + rangeDays + ' shifts (' + prefill.dateRange.start + ' → ' + prefill.dateRange.end + ') with the same staff, room, time, and role.');
      modal.appendChild(rangeBanner);
    }

    var dateRow = Dom.el('div', { style: 'display:grid;grid-template-columns:minmax(0,1.4fr) minmax(0,1fr) minmax(0,1fr);gap:10px;margin-bottom:14px;' });
    var dIn = Dom.el('input', { type: 'date', value: defaultDate, style: 'padding:8px 10px;border:1px solid #D1D5DB;border-radius:8px;font-size:13px;' });
    var sIn = Dom.el('input', { type: 'time', value: defaultStart, style: 'padding:8px 10px;border:1px solid #D1D5DB;border-radius:8px;font-size:13px;' });
    var eIn = Dom.el('input', { type: 'time', value: defaultEnd, style: 'padding:8px 10px;border:1px solid #D1D5DB;border-radius:8px;font-size:13px;' });
    var dWrap = Dom.el('div', {}); dWrap.appendChild(labelEl('Date')); dWrap.appendChild(dIn);
    var sWrap = Dom.el('div', {}); sWrap.appendChild(labelEl('Start')); sWrap.appendChild(sIn);
    var eWrap = Dom.el('div', {}); eWrap.appendChild(labelEl('End'));   eWrap.appendChild(eIn);
    dateRow.appendChild(dWrap); dateRow.appendChild(sWrap); dateRow.appendChild(eWrap);
    modal.appendChild(dateRow);

    modal.appendChild(labelEl('Role'));
    var roleSel = Dom.el('select', { style: inputStyle() });
    [['lead','Lead'],['support','Support'],['floater','Floater'],['volunteer','Volunteer']].forEach(function (o) {
      var op = Dom.el('option', { value: o[0] }, o[1]);
      if (prefill && prefill.role === o[0]) op.selected = true;
      roleSel.appendChild(op);
    });
    modal.appendChild(roleSel);

    // Actions
    var actions = Dom.el('div', { style: 'display:flex;justify-content:space-between;align-items:center;margin-top:18px;padding-top:14px;border-top:1px solid #E5E7EB;' });
    var leftAct = Dom.el('div', {});
    if (isEdit) {
      var delBtn = Dom.el('button', { style: 'background:white;color:#DC2626;border:1px solid #DC2626;padding:8px 14px;border-radius:8px;font-weight:700;cursor:pointer;font-size:13px;' }, 'Delete');
      delBtn.addEventListener('click', async function () {
        if (!await KT.confirm('Delete this shift?')) return;
        Api.delete('/director/schedule/shift/' + prefill.id).then(function () { overlay.remove(); reload(calRoot); });
      });
      leftAct.appendChild(delBtn);
    }
    actions.appendChild(leftAct);
    var rightAct = Dom.el('div', {});
    var save = Dom.el('button', { style: 'background:#1F6080;color:white;border:none;padding:8px 18px;border-radius:8px;font-weight:700;cursor:pointer;font-size:13px;' }, isEdit ? 'Save changes' : 'Create shift');
    rightAct.appendChild(save);
    actions.appendChild(rightAct);
    modal.appendChild(actions);

    save.addEventListener('click', function () {
      if (!staffSel.value || !roomSel.value) { alert('Pick a staff member and a room.'); return; }
      var common = {
        user_id: parseInt(staffSel.value, 10),
        room_id: parseInt(roomSel.value, 10),
        role: roleSel.value,
      };
      save.disabled = true; save.textContent = 'Saving…';

      // v22p42: when the modal was opened via a drag across multiple cells,
      // loop and create one shift per day in the range. Single-day flow
      // unchanged.
      var dates = [];
      if (hasRange) {
        var d = new Date(prefill.dateRange.start);
        var endD = new Date(prefill.dateRange.end);
        while (d <= endD) { dates.push(ymd(d)); d = addDays(d, 1); }
      } else {
        dates.push(dIn.value);
      }

      if (isEdit) {
        var payload = Object.assign({}, common, {
          starts_at: dIn.value + ' ' + sIn.value + ':00',
          ends_at:   dIn.value + ' ' + eIn.value + ':00',
        });
        Api.patch('/director/schedule/shift/' + prefill.id, payload)
          .then(function () { overlay.remove(); reload(calRoot); })
          .catch(function (e) { alert('Save failed: ' + e.message); save.disabled = false; save.textContent = 'Save changes'; });
        return;
      }

      // Create N shifts sequentially so a partial failure doesn't lose the rest
      var i = 0, ok = 0, fail = 0;
      function next() {
        if (i >= dates.length) {
          overlay.remove();
          reload(calRoot);
          if (fail) alert(ok + ' shift(s) created, ' + fail + ' failed.');
          return;
        }
        var d = dates[i++];
        var payload = Object.assign({}, common, {
          starts_at: d + ' ' + sIn.value + ':00',
          ends_at:   d + ' ' + eIn.value + ':00',
        });
        Api.post('/director/schedule/shift', payload)
          .then(function () { ok++; next(); })
          .catch(function () { fail++; next(); });
      }
      next();
    });

    // Load staff + rooms in parallel
    Api.get('/director/schedule/staff?centre_id=' + state.centreId).then(function (r) {
      staffSel.innerHTML = '';
      (r.staff || []).forEach(function (u) {
        var o = Dom.el('option', { value: u.id }, u.first_name + ' ' + u.last_name + ' (' + (u.role || 'staff') + ')');
        if (prefill && prefill.user_id === u.id) o.selected = true;
        staffSel.appendChild(o);
      });
    }).catch(function () { staffSel.innerHTML = '<option value="">No staff loaded</option>'; });

    // v22p37: use the rooms list bundled with the range response (avoids the
    // centre-scoped /director/rooms endpoint which always returns the caller's
    // primary centre's rooms regardless of which centre the user picked).
    roomSel.innerHTML = '';
    if (state.rooms && state.rooms.length) {
      state.rooms.forEach(function (rm) {
        var o = Dom.el('option', { value: rm.id }, rm.name);
        if (prefill && prefill.room_id === rm.id) o.selected = true;
        roomSel.appendChild(o);
      });
    } else {
      roomSel.innerHTML = '<option value="">No rooms in this centre — add one in Centre setup first.</option>';
    }

    document.body.appendChild(overlay);
    overlay.addEventListener('click', function (e) { if (e.target === overlay) overlay.remove(); });
  }

  // ─── Styling helpers ───────────────────────────────────────────────
  function inputStyle() { return 'width:100%;padding:8px 12px;border:1px solid #D1D5DB;border-radius:8px;font-size:13px;box-sizing:border-box;margin-bottom:14px;background:white;'; }
  function trimName(u) {
    return String((u && (u.name || ((u.first_name || '') + ' ' + (u.last_name || '')))) || '').trim() || 'Staff';
  }
  function selectStyle() { return 'background:white;border:1px solid #D1D5DB;border-radius:8px;padding:7px 12px;font-size:13px;color:#374151;cursor:pointer;'; }
  function navBtnStyle() { return 'background:white;border:1px solid #D1D5DB;border-radius:8px;padding:7px 10px;cursor:pointer;font-size:13px;color:#374151;min-width:36px;'; }
  function labelEl(t) { return Dom.el('label', { style: 'display:block;font-size:12px;font-weight:700;color:#374151;margin-bottom:6px;' }, t); }

  // ─── Shell registration ────────────────────────────────────────────
  if (Shell && Shell.registerScreen) {
    // Both names. staff-calendar is in bookmarks, the quick-add menu and the search
    // palette and must keep working; `calendar` is what this screen actually is now.
    ['agency_admin', 'centre_director'].forEach(function (r) {
      Shell.registerScreen(r + ':staff-calendar', render);
      Shell.registerScreen(r + ':calendar', render);
    });
  }
  KT.StaffCalendar = { render: render };
})(window);
