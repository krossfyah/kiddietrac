/* ═══════════════════════════════════════════════════════════════════
   KIDDIETRAC v14 — Staff schedule (weekly view)
   ═══════════════════════════════════════════════════════════════════ */
(function (window) {
  'use strict';

  function token() { return sessionStorage.getItem('kt_token') || localStorage.getItem('kt_token'); }
  function apiBase() { return (window.KT && window.KT.API_BASE) || 'https://api.kiddietrac.com/api/v1'; }

  async function api(method, path, body) {
    const opts = { method, headers: { 'Authorization': 'Bearer ' + token(), 'Accept': 'application/json' } };
    if (body !== undefined) { opts.headers['Content-Type'] = 'application/json'; opts.body = JSON.stringify(body); }
    const res = await fetch(apiBase() + path, opts);
    const json = await res.json().catch(() => ({}));
    if (!res.ok) throw new Error(json.message || ('API ' + res.status));
    return json;
  }

  function esc(s) { return s == null ? '' : String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;'); }
  function $(s, r) { return (r || document).querySelector(s); }

  /* YYYY-MM-DD from local date parts. NEVER toISOString(): that converts to UTC, so
     west of Greenwich it returns tomorrow's date all evening — which would ring the wrong
     day and, because weekStartOf() uses it, could jump the grid to the wrong week entirely. */
  function ymd(d) {
    return d.getFullYear() + '-'
      + String(d.getMonth() + 1).padStart(2, '0') + '-'
      + String(d.getDate()).padStart(2, '0');
  }

  /* The agency's today, not the device's. A director in another timezone still schedules
     against the centre's calendar. Falls back to the device only if kt-tz is absent. */
  function todayStr() {
    try {
      if (window.KT && typeof window.KT.agencyToday === 'function') { return window.KT.agencyToday(); }
    } catch (e) { /* fall through */ }
    return ymd(new Date());
  }

  // 'YYYY-MM-DD' -> Date, by parts. new Date('2026-09-05') is parsed as UTC and names the
  // day before once you are west of Greenwich.
  function fromYmd(str) {
    const p = String(str).split('-');
    return new Date(+p[0], +p[1] - 1, +p[2]);
  }

  /* The Sunday that starts this date's week. Calendars in this portal start on Sunday —
     the date sheet and the dashboard widget always have — and getDay() is already
     Sunday-based (0), so the offset is simply the day number itself.

     Still called by the same name everywhere it was, but it is a week start, not a
     Monday: renaming it is the point. */
  function weekStartOf(date) {
    const d = typeof date === 'string' ? fromYmd(date) : new Date(date);
    d.setDate(d.getDate() - d.getDay());
    return ymd(d);
  }

  function firstOfMonth(date) {
    const d = typeof date === 'string' ? fromYmd(date) : new Date(date);
    return ymd(new Date(d.getFullYear(), d.getMonth(), 1));
  }

  let activeCentreId = null;
  let activeWeek = weekStartOf(todayStr());
  let activeMonth = firstOfMonth(todayStr());
  /* Remembered, because a director who works in months should not have to switch every
     time they open the screen. */
  let activeView = (function () {
    try { return localStorage.getItem('kt_sched_view') === 'month' ? 'month' : 'week'; }
    catch (e) { return 'week'; }
  })();

  async function getCentres() {
    // v21: hit /director/centres when user is a director, /admin/centres for agency_admin
    const user = (window.KT && window.KT.Auth && window.KT.Auth.user()) || {};
    const role = (window.KT && window.KT.Shell && window.KT.Shell.Roles && window.KT.Shell.Roles.primaryRoleOf(user)) || '';
    const url = role === 'agency_admin' ? '/admin/centres' : '/director/centres';
    try { const r = await api('GET', url); return r.centres || []; }
    catch (e) { return []; }
  }

  /* Replace the screen's contents WITHOUT destroying the page banner.

     The shell renders .kt-hero-auto into this same container and only watches for its
     removal for the first nine seconds. After that, an innerHTML assignment silently
     removes the banner for good — so changing the centre a minute into the screen left
     the page with no header. Lifting it out and putting it back is local, cheap, and
     cannot fight the shell the way re-arming its observer could. */
  function paint(container, html) {
    var hero = container.querySelector(':scope > .kt-hero, :scope > .kt-page-hero');
    if (hero) { hero.remove(); }
    container.innerHTML = html;
    if (hero) { container.insertBefore(hero, container.firstChild); }
  }

  async function render(container) {
    paint(container, '<div style="padding:32px;text-align:center;color:#6B7280;">Loading…</div>');

    const centres = await getCentres();
    if (centres.length === 0) {
      paint(container, '<div style="padding:24px;color:#DC2626;">No centres found.</div>');
      return;
    }
    if (!activeCentreId) activeCentreId = centres[0].id;

    let week, staff, month = null;
    try {
      /* The week endpoint is still fetched in month view: it carries the centre's hours
         and open days, which the Autofill dialog states before it writes anything. */
      week = await api('GET', '/director/schedule?centre_id=' + activeCentreId + '&week_starting=' + activeWeek);
      staff = await api('GET', '/director/schedule/staff?centre_id=' + activeCentreId);
      if (activeView === 'month') {
        // Whole weeks, so the grid starts on a Monday and ends on a Sunday with no gaps.
        const first = fromYmd(activeMonth);
        const gridStart = weekStartOf(activeMonth);
        const last = new Date(first.getFullYear(), first.getMonth() + 1, 0);
        const gridEnd = ymd(new Date(fromYmd(weekStartOf(ymd(last))).getTime() + 6 * 86400000));
        month = await api('GET', '/director/schedule/range?centre_id=' + activeCentreId
          + '&start=' + gridStart + '&end=' + gridEnd);
      }
    } catch (e) {
      paint(container, '<div style="padding:24px;color:#DC2626;">Could not load: ' + esc(e.message) + '</div>');
      return;
    }

    paint(container, `
      <div style="padding:24px;max-width:1800px;">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:14px;flex-wrap:wrap;gap:12px;">
          <div>
            <h2 style="font-size:24px;margin:0;">📅 Staff Schedule</h2>
            <p style="color:#6B7280;font-size:14px;margin:4px 0 0;">${
              activeView === 'month'
                ? esc(monthLabel(activeMonth)) + ' · ' + (month ? month.total_shifts : 0) + ' shift(s)'
                : week.total_shifts + ' shift(s) this week'
            }</p>
          </div>
          <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
            <div style="display:inline-flex;height:${CTL_H}px;border:1px solid #CBD5E1;border-radius:8px;overflow:hidden;">
              ${['week', 'month'].map(v => `<button data-view="${v}" style="${ctl('border:none;padding:0 16px;font-weight:700;background:' + (activeView === v ? '#1F6080' : '#fff') + ';color:' + (activeView === v ? '#fff' : '#475569') + ';')}">${v === 'week' ? 'Week' : 'Month'}</button>`).join('')}
            </div>
            <select id="kt-centre" style="${selectStyle()}">
              ${centres.map(c => `<option value="${c.id}" ${c.id==activeCentreId?'selected':''}>${esc(c.name)}</option>`).join('')}
            </select>
            <button id="kt-prev-week" title="${activeView === 'month' ? 'Previous month' : 'Previous week'}" style="${navBtnStyle()}">‹</button>
            ${activeView === 'month'
              ? `<div style="min-width:150px;text-align:center;font-weight:700;font-size:14px;color:#0F172A;">${esc(monthLabel(activeMonth))}</div>`
              : `<input type="date" id="kt-week" title="Jump to a week" value="${activeWeek}" style="${selectStyle()}height:${CTL_H}px;box-sizing:border-box;padding:0 10px;font-size:13px;width:150px;">`}
            <button id="kt-next-week" title="${activeView === 'month' ? 'Next month' : 'Next week'}" style="${navBtnStyle()}">›</button>
            <button id="kt-today" title="Jump to today" style="${ctl('padding:0 14px;font-weight:700;border:1px solid #D1D5DB;background:white;color:#475569;')}">Today</button>
            <span style="width:1px;height:22px;background:#E2E8F0;margin:0 2px;"></span>
            <button id="kt-autofill" title="Fill the displayed week from the centre's opening hours. Closure days and days already rostered are left alone." style="${textBtnStyle(false)}">⚡ Autofill ${activeView === 'month' ? 'month' : 'week'}</button>
            <button id="kt-new-shift" style="${textBtnStyle(true)}">+ New Shift</button>
          </div>
        </div>

        ${activeView === 'month' ? monthGrid(month) : `
        <div style="display:grid;grid-template-columns:repeat(7,1fr);gap:8px;">
          ${Object.entries(week.days).map(([date, info]) => dayColumn(date, info)).join('')}
        </div>`}

        <div style="margin-top:16px;display:flex;gap:10px;flex-wrap:wrap;">
          <a href="#timesheets" style="background:white;color:#1F6080;border:1px solid #1F6080;padding:10px 16px;border-radius:8px;font-weight:600;text-decoration:none;font-size:14px;">📊 Export Timesheets</a>
          <a href="#certifications" style="background:white;color:#1F6080;border:1px solid #1F6080;padding:10px 16px;border-radius:8px;font-weight:600;text-decoration:none;font-size:14px;">🎓 Certifications</a>
        </div>

        <div id="kt-mount"></div>
      </div>
    `);

    $('#kt-centre', container).addEventListener('change', (e) => { activeCentreId = parseInt(e.target.value, 10); render(container); });

    container.querySelectorAll('[data-view]').forEach((b) => {
      b.addEventListener('click', () => {
        const v = b.getAttribute('data-view');
        if (v === activeView) return;
        activeView = v;
        try { localStorage.setItem('kt_sched_view', v); } catch (e) {}
        render(container);
      });
    });

    const weekInput = $('#kt-week', container);
    if (weekInput) {
      weekInput.addEventListener('change', (e) => { activeWeek = weekStartOf(e.target.value); render(container); });
    }

    /* One pair of arrows, stepping by whichever unit is on screen. Months are stepped by
       setMonth on the 1st, so a 31-day month never lands on the 31st of a 30-day one. */
    const step = (n) => {
      if (activeView === 'month') {
        const d = fromYmd(activeMonth);
        activeMonth = ymd(new Date(d.getFullYear(), d.getMonth() + n, 1));
      } else {
        const d = fromYmd(activeWeek);
        d.setDate(d.getDate() + (7 * n));
        activeWeek = weekStartOf(ymd(d));
      }
      render(container);
    };
    $('#kt-prev-week', container).addEventListener('click', () => step(-1));
    $('#kt-next-week', container).addEventListener('click', () => step(1));
    $('#kt-today', container).addEventListener('click', () => {
      activeWeek = weekStartOf(todayStr());
      activeMonth = firstOfMonth(todayStr());
      render(container);
    });
    $('#kt-new-shift', container).addEventListener('click', () => openShiftModal(container, staff.staff || [], null));

    $('#kt-autofill', container).addEventListener('click', (e) => {
      /* Fill what is on screen. In month view this used to hand over the WEEK, so a
         director looking at October filled five days of September. */
      let period = week;
      if (activeView === 'month' && month && month.days) {
        /* The range endpoint does not return the centre's hours — only the week endpoint
           does — and the dialog states them before it writes anything. Same centre, so
           carry them across rather than leave it saying "the centre hours". */
        period = Object.assign({}, month, { open_time: week.open_time, close_time: week.close_time });
      }
      openAutofillModal(container, staff, period, e.currentTarget);
    });

    container.querySelectorAll('.kt-shift-card').forEach(el => el.addEventListener('click', () => {
      const shift = JSON.parse(el.dataset.shift);
      openShiftModal(container, staff.staff || [], shift);
    }));

    // A shift in the month grid opens the same editor as one in the week grid.
    container.querySelectorAll('.kt-mini-shift').forEach(el => el.addEventListener('click', () => {
      try { openShiftModal(container, staff.staff || [], JSON.parse(el.dataset.shift)); }
      catch (e) { /* a chip that cannot be parsed should not break the grid */ }
    }));
  }

  /* Who to roster, stated rather than assumed.

     The endpoint has a sensible default -- the centre's designated provider -- but a
     default that writes real rows on one click is how three people ended up on a rota
     meant for one. This asks, pre-ticks the obvious answer, and shows the hours and the
     closure days it will honour so the outcome is visible beforehand. */
  function openAutofillModal(container, staff, period, btn) {
    // `period` is the week or the month, whichever the grid is showing.
    const week = period;
    const dates = Object.keys(period.days);
    const from = dates[0];
    const to = dates[dates.length - 1];
    const list = (staff && staff.staff) || [];

    const closureDays = dates.filter((d) => week.days[d] && week.days[d].closure);
    const here = list.filter((p) => p.at_this_centre);
    const elsewhere = list.filter((p) => !p.at_this_centre);

    const row = (p) => `
      <label style="display:flex;align-items:center;gap:8px;padding:7px 10px;border-radius:7px;cursor:pointer;font-size:13px;">
        <input type="checkbox" value="${p.id}" ${p.is_provider ? 'checked' : ''} style="width:16px;height:16px;flex:none;cursor:pointer;">
        <span style="flex:1;min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">${esc(p.name)}</span>
        <span style="font-size:10px;color:#6B7280;text-transform:uppercase;letter-spacing:.4px;flex:none;">${esc(String(p.role || '').replace(/_/g, ' '))}</span>
        ${p.is_provider ? '<span style="font-size:10px;font-weight:700;color:#166534;background:#DCFCE7;padding:1px 6px;border-radius:8px;flex:none;">provider</span>' : ''}
      </label>`;

    const back = document.createElement('div');
    back.setAttribute('style', 'position:fixed;inset:0;background:rgba(15,23,42,.45);z-index:10000;display:flex;align-items:center;justify-content:center;padding:16px;');
    back.innerHTML = `
      <div role="dialog" aria-modal="true" aria-label="Autofill the schedule" style="background:white;border-radius:14px;max-width:520px;width:100%;max-height:86vh;display:flex;flex-direction:column;box-shadow:0 20px 50px rgba(0,0,0,.28);">
        <div style="padding:16px 18px 10px;border-bottom:1px solid #F1F5F9;">
          <h3 style="margin:0;font-size:17px;">Autofill ${esc(from)} \u2013 ${esc(to)}</h3>
          <p style="margin:6px 0 0;color:#6B7280;font-size:12.5px;line-height:1.5;">
            Shifts run <strong>${esc(
              (week.open_time && week.close_time)
                ? String(week.open_time).slice(0, 5) + '–' + String(week.close_time).slice(0, 5)
                : 'the centre hours')}</strong>
            on the centre's open days.
            ${closureDays.length
              ? 'The ' + closureDays.length + ' closure day' + (closureDays.length === 1 ? '' : 's') + ' in this range will be left clear.'
              : 'There are no closures in this range.'}
            Days already rostered are never overwritten.
          </p>
        </div>
        <div style="padding:8px 10px;overflow:auto;flex:1;">
          ${here.length ? '<div style="font-size:10.5px;font-weight:700;color:#6B7280;letter-spacing:.8px;text-transform:uppercase;padding:8px 10px 4px;">At this centre</div>' + here.map(row).join('') : ''}
          ${elsewhere.length ? '<div style="font-size:10.5px;font-weight:700;color:#6B7280;letter-spacing:.8px;text-transform:uppercase;padding:12px 10px 4px;">Other staff who could cover</div>' + elsewhere.map(row).join('') : ''}
          ${list.length ? '' : '<div style="padding:18px;color:#DC2626;font-size:13px;">No staff found for this centre.</div>'}
        </div>
        <div style="padding:12px 16px;border-top:1px solid #F1F5F9;display:flex;gap:8px;justify-content:flex-end;align-items:center;">
          <span id="kt-af-count" style="margin-right:auto;font-size:12px;color:#6B7280;"></span>
          <button id="kt-af-cancel" style="background:white;border:1px solid #D1D5DB;padding:9px 16px;border-radius:8px;font-weight:600;cursor:pointer;">Cancel</button>
          <button id="kt-af-go" style="background:#1F6080;color:white;border:none;padding:9px 18px;border-radius:8px;font-weight:700;cursor:pointer;">Fill schedule</button>
        </div>
      </div>`;
    document.body.appendChild(back);

    const boxes = () => Array.prototype.slice.call(back.querySelectorAll('input[type=checkbox]'));
    const picked = () => boxes().filter((b) => b.checked).map((b) => parseInt(b.value, 10));
    const go = back.querySelector('#kt-af-go');
    const count = back.querySelector('#kt-af-count');

    function sync() {
      const n = picked().length;
      count.textContent = n === 0 ? 'Nobody selected' : (n + ' selected');
      go.disabled = n === 0;
      go.style.opacity = n === 0 ? '.5' : '1';
      go.style.cursor = n === 0 ? 'not-allowed' : 'pointer';
    }
    boxes().forEach((b) => b.addEventListener('change', sync));
    sync();

    function close() { if (back.parentNode) back.parentNode.removeChild(back); }
    back.querySelector('#kt-af-cancel').addEventListener('click', close);
    back.addEventListener('click', (ev) => { if (ev.target === back) close(); });
    document.addEventListener('keydown', function esckey(ev) {
      if (ev.key === 'Escape') { close(); document.removeEventListener('keydown', esckey); }
    });

    go.addEventListener('click', async () => {
      const ids = picked();
      if (!ids.length) return;
      go.disabled = true;
      go.textContent = 'Filling\u2026';
      try {
        // The local api() helper -- this file does not import the shared Api wrapper.
        const r = await api('POST', '/director/schedule/autofill', {
          centre_id: activeCentreId, start: from, end: to, user_ids: ids,
        });
        close();
        const msg = r.message || 'Schedule filled.';
        if (window.KT && typeof window.KT.toast === 'function') window.KT.toast(msg);
        else alert(msg);
        render(container);
      } catch (err) {
        go.disabled = false;
        go.textContent = 'Fill schedule';
        const m = (err && err.message) || 'Autofill failed.';
        if (window.KT && typeof window.KT.toast === 'function') window.KT.toast(m, 'error');
        else alert(m);
      }
    });
  }

  function monthLabel(firstDay) {
    return fromYmd(firstDay).toLocaleDateString(undefined, { month: 'long', year: 'numeric' });
  }

  /* The month grid.

     Built by walking the dates the API returned rather than by counting days from the 1st:
     the range is requested as whole Monday-to-Sunday weeks, so the cells line up under
     their weekday headings without any padding arithmetic, and a DST weekend cannot shift
     the columns.

     Each cell says the same three things the week view does — closed, not open, or who is
     on — just smaller, because a month is for spotting gaps rather than reading detail. */
  function monthGrid(month) {
    if (!month || !month.days) {
      return '<div style="padding:24px;color:#6B7280;">No schedule for this month.</div>';
    }

    const today = todayStr();
    const inMonth = String(activeMonth).slice(0, 7);
    const names = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];

    const head = names.map(n =>
      `<div style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:1px;color:#64748B;padding:6px 4px;text-align:center;">${n}</div>`
    ).join('');

    const cells = Object.entries(month.days).map(([date, info]) => {
      const d = fromYmd(date);
      const isToday = date === today;
      // Days from the neighbouring months are shown but recede: the week still needs its
      // seven columns, and hiding them would leave holes.
      const other = date.slice(0, 7) !== inMonth;
      const closure = info.closure || null;
      const shut = info.is_open_day === false;

      const bg = isToday ? '#EFF6FF' : (closure ? '#FFF1F2' : (shut ? '#F9FAFB' : 'white'));
      const ring = isToday ? 'box-shadow:inset 0 0 0 2px #1F6080;' : '';

      const shifts = info.shifts || [];
      const chips = shifts.slice(0, 3).map(sh => {
        const colour = sh.role === 'lead' ? '#1F6080' : sh.role === 'support' ? '#8EC73C'
          : sh.role === 'floater' ? '#F59E0B' : '#A78BFA';
        return `<div class="kt-mini-shift" data-shift='${esc(JSON.stringify(sh)).replace(/&quot;/g, '"')}' title="${esc(sh.user_name + ' · ' + sh.starts_hm + '–' + sh.ends_hm + ' · ' + sh.role)}" style="background:${colour}1F;border-left:2px solid ${colour};border-radius:3px;padding:1px 4px;margin-bottom:2px;font-size:10px;line-height:1.35;cursor:pointer;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">${esc(sh.starts_hm)} ${esc(sh.user_name)}</div>`;
      }).join('');

      const more = shifts.length > 3
        ? `<div style="font-size:10px;color:#64748B;padding-left:2px;">+${shifts.length - 3} more</div>` : '';

      let body = chips + more;
      if (closure) {
        body = `<div title="${esc(closure.label)}" style="background:#FFE4E6;border-left:2px solid #E11D48;border-radius:3px;padding:1px 4px;margin-bottom:2px;font-size:10px;font-weight:700;color:#9F1239;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">${esc(closure.label)}</div>` + body;
      } else if (!shifts.length && shut) {
        body = '<div style="font-size:10px;color:#9CA3AF;">Not open</div>';
      }

      return `<div style="background:${bg};${ring}border-radius:8px;padding:6px;min-height:92px;opacity:${other ? '.5' : '1'};display:flex;flex-direction:column;">
          <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:4px;">
            <span style="font-size:12px;font-weight:${isToday ? '800' : '600'};color:${isToday ? '#1F6080' : (closure ? '#9F1239' : '#334155')};">${d.getDate()}</span>
            ${isToday ? '<span style="font-size:9px;font-weight:800;letter-spacing:.5px;color:#fff;background:#1F6080;border-radius:8px;padding:1px 6px;">TODAY</span>' : ''}
          </div>
          <div style="flex:1;overflow:hidden;">${body}</div>
        </div>`;
    }).join('');

    return `<div style="display:grid;grid-template-columns:repeat(7,1fr);gap:6px;">${head}</div>
      <div style="display:grid;grid-template-columns:repeat(7,1fr);gap:6px;margin-top:2px;">${cells}</div>`;
  }

  function dayColumn(date, info) {
    /* Numeric parts, not new Date('2026-09-05'): a date-only string is parsed as UTC, so
       west of Greenwich every column was headed with the previous day. */
    const p = String(date).split('-');
    const d = new Date(+p[0], +p[1] - 1, +p[2]);
    const dayLabel = d.toLocaleDateString(undefined, { weekday: 'short', month: 'short', day: 'numeric' });

    const closure = info.closure || null;
    const shut = info.is_open_day === false;
    // The agency's today, so a director elsewhere still sees the centre's day ringed.
    const isToday = date === todayStr();

    /* A closed day and an unrostered day were both an empty column with a dash. Only one
       of them is something to act on, so they should not look the same. */
    const bg = isToday ? '#EFF6FF' : (closure ? '#FFF1F2' : (shut ? '#F9FAFB' : 'white'));
    const head = isToday ? '#1F6080' : (closure ? '#9F1239' : '#6B7280');

    let body;
    if (closure) {
      body = `
        <div style="background:#FFE4E6;border-left:3px solid #E11D48;border-radius:6px;padding:6px 8px;margin-bottom:4px;font-size:11px;">
          <div style="font-weight:700;color:#9F1239;">${esc(closure.label)}</div>
          ${closure.from !== closure.to ? `<div style="color:#9F1239;font-size:10px;margin-top:2px;">${esc(closure.from)} \u2013 ${esc(closure.to)}</div>` : ''}
          ${closure.affects_billing ? '<div style="color:#9F1239;font-size:10px;margin-top:2px;">Affects billing</div>' : ''}
        </div>`;
      // A shift on a closure day is not impossible -- someone may be in doing paperwork --
      // so it is still shown, under the closure rather than instead of it.
      if (info.shifts.length) { body += info.shifts.map(s => shiftCard(s)).join(''); }
    } else if (info.shifts.length) {
      body = info.shifts.map(s => shiftCard(s)).join('');
    } else if (shut) {
      body = '<div style="color:#9CA3AF;font-size:11px;">Not open</div>';
    } else {
      body = '<div style="color:#D1D5DB;font-size:12px;">\u2014</div>';
    }

    return `
      <div style="background:${bg};border-radius:10px;padding:10px;min-height:200px;${isToday ? 'box-shadow:inset 0 0 0 2px #1F6080;' : ''}">
        <div style="font-size:11px;font-weight:700;text-transform:uppercase;color:${head};letter-spacing:1px;margin-bottom:8px;display:flex;align-items:center;justify-content:space-between;gap:6px;">
          <span>${esc(dayLabel)}</span>
          ${isToday ? '<span style="font-size:9px;font-weight:800;letter-spacing:.5px;color:#fff;background:#1F6080;border-radius:8px;padding:1px 6px;">TODAY</span>' : ''}
        </div>
        ${body}
      </div>
    `;
  }

  function shiftCard(s) {
    const color = s.role === 'lead' ? '#1F6080' : s.role === 'support' ? '#8EC73C' : s.role === 'floater' ? '#F59E0B' : '#A78BFA';
    return `
      <div class="kt-shift-card" data-shift='${esc(JSON.stringify(s)).replace(/&quot;/g,'\"')}' style="background:${color}20;border-left:3px solid ${color};border-radius:6px;padding:6px 8px;margin-bottom:4px;font-size:11px;cursor:pointer;">
        <div style="font-weight:600;">${esc(s.starts_hm)}–${esc(s.ends_hm)}</div>
        <div style="margin-top:2px;color:#374151;">${esc(s.user_name)}</div>
        <div style="color:#6B7280;font-size:10px;">${esc(s.room_name)} · ${esc(s.role)}</div>
      </div>
    `;
  }

  function openShiftModal(container, staff, existing) {
    const isEdit = !!existing;
    const mount = $('#kt-mount', container);
    mount.innerHTML = `
      <div class="kt-modal-overlay" style="position:fixed;inset:0;background:rgba(0,0,0,.5);display:flex;align-items:center;justify-content:center;z-index:9999;padding:16px;">
        <div style="background:white;border-radius:14px;max-width:480px;width:100%;padding:24px;">
          <h2 style="font-size:20px;margin:0 0 14px;">${isEdit ? 'Edit shift' : 'New shift'}</h2>
          <form id="kt-shift-form" onsubmit="return false;" style="display:grid;gap:10px;">
            <label style="font-size:13px;font-weight:600;">Staff *</label>
            <select name="user_id" required style="${selectStyle()}">
              <option value="">— Pick someone —</option>
              ${staff.map(u => `<option value="${u.id}" ${existing && existing.user_id==u.id?'selected':''}>${esc(u.name)}</option>`).join('')}
            </select>
            <label style="font-size:13px;font-weight:600;">Room *</label>
            <input type="number" name="room_id" required placeholder="Room ID" value="${existing?existing.room_id:''}" style="${selectStyle()}">
            <label style="font-size:13px;font-weight:600;">Starts</label>
            <input type="datetime-local" name="starts_at" required value="${existing?toLocalDt(existing.starts_at):activeWeek+'T09:00'}" style="${selectStyle()}">
            <label style="font-size:13px;font-weight:600;">Ends</label>
            <input type="datetime-local" name="ends_at" required value="${existing?toLocalDt(existing.ends_at):activeWeek+'T17:00'}" style="${selectStyle()}">
            <label style="font-size:13px;font-weight:600;">Role</label>
            <select name="role" style="${selectStyle()}">
              ${['lead','support','floater','volunteer'].map(r => `<option value="${r}" ${existing&&existing.role===r?'selected':''}>${r}</option>`).join('')}
            </select>
            <div id="kt-status" style="min-height:20px;font-size:14px;"></div>
            <div style="display:flex;justify-content:space-between;gap:8px;">
              <div>${isEdit ? '<button type="button" id="kt-delete" style="background:#FEE2E2;color:#991B1B;border:none;padding:10px 18px;border-radius:8px;font-weight:600;cursor:pointer;">Delete</button>' : ''}</div>
              <div style="display:flex;gap:8px;">
                <button type="button" id="kt-cancel" style="background:#F3F4F6;color:#374151;border:none;padding:10px 18px;border-radius:8px;font-weight:600;cursor:pointer;">Cancel</button>
                <button type="submit" style="background:#1F6080;color:white;border:none;padding:10px 22px;border-radius:8px;font-weight:700;cursor:pointer;">${isEdit?'Update':'Create'}</button>
              </div>
            </div>
          </form>
        </div>
      </div>
    `;
    const overlay = $('.kt-modal-overlay', mount);
    const close = () => mount.innerHTML = '';
    overlay.addEventListener('click', (e) => { if (e.target === overlay) close(); });
    $('#kt-cancel', mount).addEventListener('click', close);

    if (isEdit) {
      $('#kt-delete', mount).addEventListener('click', async () => {
        if (!await KT.confirm('Delete this shift?')) return;
        try {
          await api('DELETE', '/director/schedule/shift/' + existing.id);
          close(); render(container);
        } catch (e) { alert('Could not delete: ' + e.message); }
      });
    }

    $('#kt-shift-form', mount).addEventListener('submit', async (e) => {
      e.preventDefault();
      const data = {};
      new FormData(e.target).forEach((v, k) => data[k] = v);
      data.user_id = parseInt(data.user_id, 10);
      data.room_id = parseInt(data.room_id, 10);
      try {
        if (isEdit) await api('PATCH', '/director/schedule/shift/' + existing.id, data);
        else await api('POST', '/director/schedule/shift', data);
        $('#kt-status', mount).style.color = '#16A34A';
        $('#kt-status', mount).textContent = '✓ Saved';
        setTimeout(() => { close(); render(container); }, 600);
      } catch (e) {
        $('#kt-status', mount).style.color = '#DC2626';
        $('#kt-status', mount).textContent = '✗ ' + e.message;
      }
    });
  }

  function toLocalDt(iso) {
    if (!iso) return '';
    return iso.replace(' ', 'T').substring(0, 16);
  }
  /* One size for everything in the toolbar. Measured before this existed, the row held
     five different heights and four font sizes — the eye reads that as broken before it
     reads any of the labels. 36px and 14px are the portal's ordinary control size. */
  /* 32px / 13px: the portal's compact control size. kt-polish-v22.css sets selects and
     inputs to 13px with 5px padding (~30px) portal-wide with !important, so this sits two
     pixels off them and centres in the row — rather than overriding that rule on one
     screen, which is how each page ends up its own size. */
  var CTL_H = 32;
  function ctl(extra) {
    return 'height:' + CTL_H + 'px;box-sizing:border-box;border-radius:8px;font-size:13px;'
      + 'font-family:inherit;line-height:1;cursor:pointer;' + (extra || '');
  }
  /* An explicit height, because a global `#appMain select` rule sets its own padding —
     an ID selector, so it wins — and would otherwise leave the select shorter than the
     buttons next to it. */
  /* The select is left to the portal rule (kt-polish-v22.css wins with !important
     anyway); only the things it does not set are given here. */
  function selectStyle() {
    return 'border:1px solid #D1D5DB;border-radius:8px;background:white;color:#0F172A;';
  }
  /* The chevrons need a bigger glyph, done with line-height rather than font-size so the
     button does not grow. 'Today' sits in the same group and must NOT inherit that size. */
  function navBtnStyle() {
    return ctl('background:white;border:1px solid #D1D5DB;width:' + CTL_H + 'px;padding:0;'
      + 'font-size:18px;font-weight:700;color:#475569;display:inline-flex;align-items:center;justify-content:center;');
  }
  function textBtnStyle(primary) {
    return ctl('padding:0 16px;font-weight:700;border:1px solid '
      + (primary ? '#1F6080' : '#D1D5DB') + ';background:' + (primary ? '#1F6080' : 'white')
      + ';color:' + (primary ? '#fff' : '#1F6080') + ';');
  }

  window.KT = window.KT || {};
  window.KT.Schedule = { render };
})(window);
