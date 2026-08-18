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
    hero.innerHTML = '<div class="kt-hero-greet">📅 STAFF</div><h1>Calendar</h1><div class="kt-hero-sub">Schedule shifts for your educators. Click a day to add a shift, click a shift to edit or delete it.</div>';
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
    ['week', 'month'].forEach(function (v) {
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

    // + add shift
    var add = Dom.el('button', { style: 'background:#1F6080;color:white;border:none;padding:8px 16px;border-radius:8px;font-weight:700;cursor:pointer;font-size:13px;' }, '+ Add shift');
    add.addEventListener('click', function () { openShiftModal(null, calRoot); });
    toolbar.appendChild(add);
  }

  function stepCursor(direction) {
    var d = new Date(state.cursor);
    if (state.view === 'week') d.setDate(d.getDate() + 7 * direction);
    else d.setMonth(d.getMonth() + direction);
    state.cursor = d;
  }

  function titleForCursor() {
    if (state.view === 'week') {
      var s = startOfWeek(state.cursor); var e = addDays(s, 6);
      return s.toLocaleDateString('en-CA', { month: 'short', day: 'numeric' }) + ' – ' +
             e.toLocaleDateString('en-CA', { month: 'short', day: 'numeric', year: 'numeric' });
    }
    return monthName(state.cursor);
  }

  // ─── Data load + render ────────────────────────────────────────────
  function reload(calRoot) {
    var titleEl = document.getElementById('kt-cal-title');
    if (titleEl) titleEl.textContent = titleForCursor();
    Dom.clear(calRoot);
    calRoot.appendChild(Dom.el('div', { style: 'padding:30px;text-align:center;color:#64748B;font-size:13px;' }, 'Loading shifts…'));

    var start, end;
    if (state.view === 'week') {
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
    Api.get('/director/schedule/range?' + q).then(function (data) {
      state.days = data.days || {};
      state.rooms = data.rooms || [];
      Dom.clear(calRoot);
      calRoot.appendChild(state.view === 'week' ? renderWeek(start, end, calRoot) : renderMonth(start, end, calRoot));
    }).catch(function (e) {
      Dom.clear(calRoot);
      calRoot.appendChild(Dom.el('div', { style: 'padding:24px;color:#DC2626;' }, 'Could not load: ' + (e.message || 'error')));
    });
  }

  // ─── Week view: 7 day columns with hour gutter ─────────────────────
  function renderWeek(start, end, calRoot) {
    var grid = Dom.el('div', { style: 'background:white;border-radius:12px;box-shadow:0 1px 3px rgba(0,0,0,.04);overflow:hidden;' });
    // Header row
    var header = Dom.el('div', { style: 'display:grid;grid-template-columns:64px repeat(7,1fr);border-bottom:1px solid #E5E7EB;background:#FAFBFC;' });
    header.appendChild(Dom.el('div', { style: 'padding:10px;font-size:11px;color:#64748B;font-weight:700;text-transform:uppercase;' }, ''));
    for (var i = 0; i < 7; i++) {
      var d = addDays(start, i);
      var isToday = ymd(d) === ymd(new Date());
      var col = Dom.el('div', { style: 'padding:10px;text-align:center;border-left:1px solid #F3F4F6;' + (isToday ? 'background:#EFF6FF;' : '') });
      col.appendChild(Dom.el('div', { style: 'font-size:10px;font-weight:700;letter-spacing:0.5px;color:#6B7280;text-transform:uppercase;' }, d.toLocaleDateString('en-CA', { weekday: 'short' })));
      col.appendChild(Dom.el('div', { style: 'font-size:18px;font-weight:800;color:' + (isToday ? '#1F6080' : '#111827') + ';' }, String(d.getDate())));
      header.appendChild(col);
    }
    grid.appendChild(header);

    // Body — single day-cell per column with shifts stacked
    var body = Dom.el('div', { style: 'display:grid;grid-template-columns:64px repeat(7,1fr);min-height:480px;' });
    body.appendChild(Dom.el('div', { style: 'border-right:1px solid #F3F4F6;background:#FAFBFC;font-size:10px;color:#64748B;padding:6px;text-align:right;line-height:1.4;' }, ' '));
    installDragSelect(body, calRoot);
    for (var j = 0; j < 7; j++) {
      var dj = addDays(start, j);
      var key = ymd(dj);
      var dayShifts = (state.days[key] && state.days[key].shifts) || [];
      if (state.roleFilter) dayShifts = dayShifts.filter(function (s) { return s.role === state.roleFilter; });
      var cell = Dom.el('div', { style: 'border-left:1px solid #F3F4F6;padding:6px;min-height:120px;cursor:pointer;position:relative;', 'data-date': key });
      cell.addEventListener('click', function (e) {
        // Only open new-shift modal when clicking the cell itself, not a shift
        if (e.target === e.currentTarget || e.target.dataset.cellBg) {
          openShiftModal({ date: e.currentTarget.dataset.date }, calRoot);
        }
      });
      // Cell hover hint
      var hint = Dom.el('div', { 'data-cell-bg': '1', style: 'position:absolute;inset:0;pointer-events:auto;' });
      cell.appendChild(hint);
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
    var header = Dom.el('div', { style: 'display:grid;grid-template-columns:repeat(7,1fr);border-bottom:1px solid #E5E7EB;background:#FAFBFC;' });
    ['Mon','Tue','Wed','Thu','Fri','Sat','Sun'].forEach(function (n) {
      header.appendChild(Dom.el('div', { style: 'padding:10px;text-align:center;font-size:11px;font-weight:700;color:#6B7280;letter-spacing:1px;text-transform:uppercase;' }, n));
    });
    grid.appendChild(header);

    var body = Dom.el('div', { style: 'display:grid;grid-template-columns:repeat(7,1fr);' });
    installDragSelect(body, calRoot);
    var cursor = new Date(start);
    while (cursor <= end) {
      var key = ymd(cursor);
      var inMonth = cursor.getMonth() === monthMid;
      var isToday = key === ymd(new Date());
      var dayShifts = (state.days[key] && state.days[key].shifts) || [];
      if (state.roleFilter) dayShifts = dayShifts.filter(function (s) { return s.role === state.roleFilter; });
      var cell = Dom.el('div', { style: 'border:1px solid #F3F4F6;padding:6px;min-height:96px;cursor:pointer;background:' + (inMonth ? 'white' : '#FAFBFC') + ';position:relative;', 'data-date': key });
      cell.addEventListener('click', function (e) {
        if (e.target === e.currentTarget || e.target.dataset.cellBg) {
          openShiftModal({ date: e.currentTarget.dataset.date }, calRoot);
        }
      });
      cell.appendChild(Dom.el('div', { style: 'font-size:12px;font-weight:700;color:' + (isToday ? '#1F6080' : (inMonth ? '#374151' : '#9CA3AF')) + ';margin-bottom:4px;display:inline-block;padding:2px 6px;border-radius:6px;background:' + (isToday ? '#DBEAFE' : 'transparent') + ';' }, String(cursor.getDate())));
      var hint = Dom.el('div', { 'data-cell-bg': '1', style: 'position:absolute;inset:0;' });
      cell.appendChild(hint);
      dayShifts.slice(0, 3).forEach(function (s) { cell.appendChild(renderShiftChip(s, calRoot)); });
      if (dayShifts.length > 3) cell.appendChild(Dom.el('div', { style: 'font-size:10px;color:#64748B;margin-top:2px;position:relative;z-index:1;' }, '+ ' + (dayShifts.length - 3) + ' more'));
      body.appendChild(cell);
      cursor = addDays(cursor, 1);
    }
    grid.appendChild(body);
    return grid;
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

    var dateRow = Dom.el('div', { style: 'display:grid;grid-template-columns:1.4fr 1fr 1fr;gap:10px;margin-bottom:14px;' });
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
  function selectStyle() { return 'background:white;border:1px solid #D1D5DB;border-radius:8px;padding:7px 12px;font-size:13px;color:#374151;cursor:pointer;'; }
  function navBtnStyle() { return 'background:white;border:1px solid #D1D5DB;border-radius:8px;padding:7px 10px;cursor:pointer;font-size:13px;color:#374151;min-width:36px;'; }
  function labelEl(t) { return Dom.el('label', { style: 'display:block;font-size:12px;font-weight:700;color:#374151;margin-bottom:6px;' }, t); }

  // ─── Shell registration ────────────────────────────────────────────
  if (Shell && Shell.registerScreen) {
    Shell.registerScreen('agency_admin:staff-calendar',   render);
    Shell.registerScreen('centre_director:staff-calendar', render);
  }
  KT.StaffCalendar = { render: render };
})(window);
