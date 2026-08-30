/* ═══════════════════════════════════════════════════════════════════
   KiddieTrac — Canned Reports (2026-07-08)
   A list of ready-to-run reports. Pick a date range + centre, hit Run, and
   get a branded (agency + centre logo), zebra-striped, printable document.
   Registered for :reports AFTER the legacy builder so this becomes the
   Reports screen. Data: GET /reports/canned + /reports/canned/run.
   ═══════════════════════════════════════════════════════════════════ */
(function (window) {
  'use strict';
  var KT = window.KT || {};
  var Shell = KT.Shell, Api = KT.Api;
  if (!Shell || !Api) return;

  function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"]/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c];
    });
  }
  function todayISO(offsetDays) {
    var d = new Date();
    if (offsetDays) d.setDate(d.getDate() + offsetDays);
    return d.toISOString().slice(0, 10);
  }
  function absUrl(u) {
    if (!u) return '';
    if (/^https?:|^data:/.test(u)) return u;
    return (u.charAt(0) === '/' ? '' : '/') + u;
  }
  function logoBox(logo, name, color) {
    if (logo) return '<img src="' + esc(absUrl(logo)) + '" alt="" style="height:46px;max-width:150px;object-fit:contain;">';
    var initial = esc((name || '?').charAt(0).toUpperCase());
    return '<div style="width:46px;height:46px;border-radius:10px;background:' + esc(color || '#1F6080') +
      ';color:#fff;display:flex;align-items:center;justify-content:center;font-weight:800;font-size:20px;">' + initial + '</div>';
  }

  var META = null;

  async function render(main) {
    main.setAttribute('data-kt-pretty', '1');
    main.innerHTML = '<div style="padding:14px 24px;"><div class="kt-page-hero"><h2>📋 Reports</h2><p>Loading…</p></div></div>';
    META = await Api.get('/reports/canned').catch(function () { return { reports: [], centres: [], agency: null }; });

    var centreOpts = '<option value="">All centres</option>' +
      (META.centres || []).map(function (c) { return '<option value="' + c.id + '">' + esc(c.name) + '</option>'; }).join('');

    /* Scheduling posts to /admin/report-schedules, which is admin-only - so for
       an educator this panel would render, then hand them a 403 from every button
       in it. The server says whether to draw it at all. */
    var canSchedule = META.can_schedule !== false;
    var scopeNote = META.scope_note
      ? '<div style="background:#EFF6FF;border:1px solid #BFDBFE;color:#1E3A8A;border-radius:10px;padding:9px 12px;font-size:12.5px;margin-bottom:14px;">' +
        '🔒 ' + esc(META.scope_note) + '</div>'
      : '';

    var cards = (META.reports || []).map(function (r) {
      return '<button class="kt-rep-card" data-type="' + esc(r.type) + '" data-dated="' + (r.dated ? '1' : '0') +
        '" style="text-align:left;background:#fff;border:1px solid #E7EBF0;border-radius:14px;padding:16px;cursor:pointer;display:flex;gap:12px;align-items:flex-start;transition:box-shadow .15s,border-color .15s;">' +
        '<div style="font-size:30px;line-height:1;flex-shrink:0;">' + esc(r.icon || '📄') + '</div>' +
        '<div><div style="font-weight:700;font-size:15px;color:#0D1B2A;margin-bottom:2px;">' + esc(r.title) + '</div>' +
        '<div style="font-size:12.5px;color:#64748B;line-height:1.35;">' + esc(r.desc || '') + '</div></div></button>';
    }).join('');

    main.innerHTML =
      '<div style="padding:14px 24px;">' +
        '<div class="kt-page-hero"><h2>📋 Reports</h2><p>Pick a report, set a date range and centre, then run it. Every report is branded and print-ready.</p></div>' +
        scopeNote +
        '<div class="kt-report-noprint" style="display:flex;flex-wrap:wrap;gap:14px;align-items:flex-end;background:#fff;border:1px solid #E7EBF0;border-radius:14px;padding:14px 16px;margin-bottom:16px;">' +
          '<label style="font-size:12px;font-weight:700;color:#475569;">Centre<br><select id="rep-centre" style="margin-top:4px;">' + centreOpts + '</select></label>' +
          '<label id="rep-from-wrap" style="font-size:12px;font-weight:700;color:#475569;">From<br><input type="date" id="rep-from" value="' + todayISO(-30) + '" style="margin-top:4px;"></label>' +
          '<label id="rep-to-wrap" style="font-size:12px;font-weight:700;color:#475569;">To<br><input type="date" id="rep-to" value="' + todayISO(0) + '" style="margin-top:4px;"></label>' +
          '<span style="font-size:12px;color:#64748B;align-self:center;">Date range applies to dated reports (attendance, payments, invoices, staff hours).</span>' +
        '</div>' +
        '<div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(280px,1fr));gap:14px;margin-bottom:18px;">' + cards + '</div>' +
        // ── Scheduled reports ──
        (canSchedule ?
        '<div class="kt-report-noprint" style="background:#fff;border:1px solid #E7EBF0;border-radius:14px;padding:16px;margin-bottom:16px;">' +
          '<div style="display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap;margin-bottom:10px;">' +
            '<div><div style="font-weight:800;font-size:15px;color:#0D1B2A;">📅 Scheduled reports</div>' +
            '<div style="font-size:12.5px;color:#64748B;">Email any report automatically — PDF and/or CSV — on a daily, weekly or monthly cadence.</div></div>' +
            '<button id="rep-add-sched" style="background:#1F6080;color:#fff;border:none;border-radius:9px;padding:9px 16px;font-weight:700;cursor:pointer;">＋ Schedule a report</button>' +
          '</div>' +
          '<div id="rep-sched-list"><div style="color:#94A3B8;font-size:13px;padding:6px 0;">Loading…</div></div>' +
        '</div>' : '') +
        '<div id="rep-out"></div>' +
      '</div>';

    main.querySelectorAll('.kt-rep-card').forEach(function (b) {
      b.addEventListener('mouseenter', function () { b.style.boxShadow = '0 6px 18px rgba(15,23,42,.10)'; b.style.borderColor = '#C9D3DE'; });
      b.addEventListener('mouseleave', function () { b.style.boxShadow = 'none'; b.style.borderColor = '#E7EBF0'; });
      b.addEventListener('click', function () { runReport(b.getAttribute('data-type')); });
    });

    if (canSchedule) {
      loadSchedules();
      var addSchedBtn = document.getElementById('rep-add-sched');
      if (addSchedBtn) addSchedBtn.addEventListener('click', function () { openScheduleModal(); });
    }
  }

  var DOW = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];
  /* What a status MEANS, as a colour. Matched on a normalised prefix so "Paid",
     "paid", "Partially paid" and "Absence recorded (but signed in)" all land somewhere
     sensible, and anything unrecognised stays neutral grey rather than picking a colour
     at random and implying something.

     Kept in step with cannedHtml() on the server, which tints the PDF and the emailed
     report — a printout that disagrees with the screen about which invoices are overdue
     is worse than no colour at all. */
  var STATUS_TINT = [
    [/^(paid|settled|complete|completed|approved|present|active|sent ok)/i, '#DCFCE7', '#15803D'],
    [/^(overdue|failed|declined|rejected|denied|absent|expired|breach)/i,   '#FEE2E2', '#B91C1C'],
    [/^(pending|awaiting|partial|partially|due|unpaid|draft|absence)/i,     '#FEF3C7', '#B45309'],
    [/^(open|sent|issued|scheduled|in progress|processing|invited)/i,       '#E0F2FE', '#0369A1'],
    [/^(void|cancelled|canceled|archived|closed|inactive|withdrawn)/i,      '#F1F5F9', '#64748B'],
  ];
  function statusTint(v) {
    var t = String(v == null ? '' : v).trim();
    for (var i = 0; i < STATUS_TINT.length; i++) {
      if (STATUS_TINT[i][0].test(t)) { return { bg: STATUS_TINT[i][1], fg: STATUS_TINT[i][2] }; }
    }
    return { bg: '#EEF2F7', fg: '#334155' };
  }

  function repToast(msg, kind) { if (window.KT && KT.Dom && KT.Dom.toast) KT.Dom.toast(msg, kind || 'info'); else if (window.KT && KT.toast) KT.toast('📅', 'Reports', msg, kind === 'error' ? '#B91C1C' : '#1F6080'); }

  async function loadSchedules() {
    var host = document.getElementById('rep-sched-list');
    if (!host) return;
    var d;
    try { d = await Api.get('/admin/report-schedules'); }
    catch (e) { host.innerHTML = '<div style="color:#B91C1C;font-size:13px;">Could not load schedules: ' + esc(e.message || '') + '</div>'; return; }
    var list = d.schedules || [];
    if (!list.length) { host.innerHTML = '<div style="color:#94A3B8;font-size:13px;padding:6px 0;">No schedules yet. Click “＋ Schedule a report” to create one.</div>'; return; }
    host.innerHTML = '<div style="display:flex;flex-direction:column;gap:8px;">' + list.map(function (s) {
      var fmt = s.format === 'both' ? 'PDF + CSV' : String(s.format || 'pdf').toUpperCase();
      return '<div style="display:flex;align-items:center;gap:12px;flex-wrap:wrap;border:1px solid #EEF2F7;border-radius:10px;padding:10px 12px;' + (s.active ? '' : 'opacity:.55;') + '">' +
        '<div style="flex:1;min-width:180px;"><div style="font-weight:700;color:#0D1B2A;font-size:14px;">' + esc(s.report_title) + ' <span style="font-weight:500;color:#64748B;font-size:12px;">· ' + esc(fmt) + '</span></div>' +
        '<div style="font-size:12px;color:#64748B;">' + esc(s.schedule_label) + ' → ' + esc(s.recipient) + (s.centre_name ? ' · ' + esc(s.centre_name) : '') + '</div>' +
        (s.last_sent_on ? '<div style="font-size:11px;color:#94A3B8;">Last sent ' + esc(s.last_sent_on) + '</div>' : '') + '</div>' +
        '<label style="font-size:11px;color:#64748B;display:flex;align-items:center;gap:5px;cursor:pointer;"><input type="checkbox" data-toggle="' + s.id + '"' + (s.active ? ' checked' : '') + '> Active</label>' +
        // Actions behind a kebab. Delete was sitting in red on every row of a list
        // somebody reads weekly; one slip removed a schedule with no way back.
        // Just the button. The menu is built on <body> when opened — see openKebab —
        // because an absolutely positioned menu lives inside whatever the row lives
        // inside, and something up that chain was cutting it off.
        '<button data-kebab="' + s.id + '" title="Actions" aria-label="Actions" aria-haspopup="true" aria-expanded="false" ' +
          'style="width:32px;height:32px;border-radius:9px;border:1px solid #E2E8F0;background:#fff;cursor:pointer;font-size:16px;line-height:1;color:#475569;">⋮</button>' +
        '</div>';
    }).join('') + '</div>';
    // One menu at a time, on <body>, so nothing up the DOM can clip it.
    var openMenu = null;
    function closeMenus() {
      if (openMenu && openMenu.parentNode) { openMenu.parentNode.removeChild(openMenu); }
      openMenu = null;
      host.querySelectorAll('[data-kebab]').forEach(function (b) { b.setAttribute('aria-expanded', 'false'); });
    }

    function openKebab(btn, sched) {
      closeMenus();
      var menu = document.createElement('div');
      menu.style.cssText = 'position:fixed;z-index:9998;background:#fff;border:1px solid #E2E8F0;border-radius:10px;' +
        'box-shadow:0 10px 30px rgba(15,23,42,.16);min-width:180px;overflow:hidden;padding:4px 0;';
      var item = function (label, colour, fn) {
        var b = document.createElement('button');
        b.type = 'button';
        b.textContent = label;
        b.dataset.ktIconized = '1';
        b.style.cssText = 'display:block;width:100%;text-align:left;border:none;background:none;padding:10px 14px;' +
          'font-size:13px;color:' + colour + ';cursor:pointer;';
        b.addEventListener('mouseenter', function () { b.style.background = '#F8FAFC'; });
        b.addEventListener('mouseleave', function () { b.style.background = 'none'; });
        b.addEventListener('click', function (e) { e.stopPropagation(); closeMenus(); fn(b); });
        menu.appendChild(b);
        return b;
      };

      item('📤 Send now', '#0F172A', function () { runSchedule(sched.id); });
      item('✏️ Edit', '#0F172A', function () { openScheduleModal(sched); });
      var del = item('🗑 Delete', '#B91C1C', function () { deleteSchedule(sched); });
      del.style.borderTop = '1px solid #F1F5F9';

      document.body.appendChild(menu);

      // Placed from the button's own rectangle, and flipped above it when there is no
      // room below — which is exactly the case for the last row in the list, the one
      // most likely to have been cut off.
      var r = btn.getBoundingClientRect();
      var h = menu.offsetHeight || 132;
      var w = menu.offsetWidth || 180;
      var top = (r.bottom + 6 + h > window.innerHeight) ? Math.max(8, r.top - h - 6) : r.bottom + 6;
      var left = Math.min(Math.max(8, r.right - w), window.innerWidth - w - 8);
      menu.style.top = top + 'px';
      menu.style.left = left + 'px';

      menu.addEventListener('click', function (e) { e.stopPropagation(); });
      btn.setAttribute('aria-expanded', 'true');
      openMenu = menu;
    }

    host.querySelectorAll('[data-kebab]').forEach(function (b) {
      b.dataset.ktIconized = '1';
      b.addEventListener('click', function (e) {
        e.stopPropagation();
        var wasOpen = b.getAttribute('aria-expanded') === 'true';
        var id = b.getAttribute('data-kebab');
        var sched = list.filter(function (x) { return String(x.id) === String(id); })[0];
        if (wasOpen || !sched) { closeMenus(); return; }
        openKebab(b, sched);
      });
    });
    // A fixed menu does not travel with the page, so it closes rather than floating
    // away from the row it belongs to.
    document.addEventListener('click', closeMenus);
    window.addEventListener('resize', closeMenus);
    window.addEventListener('scroll', closeMenus, true);

    host.querySelectorAll('[data-toggle]').forEach(function (cb) {
      cb.addEventListener('change', async function () {
        try { await Api.patch('/admin/report-schedules/' + cb.getAttribute('data-toggle'), { active: cb.checked }); repToast(cb.checked ? 'Schedule on' : 'Schedule paused', 'success'); }
        catch (e) { cb.checked = !cb.checked; repToast(e.message || 'Failed', 'error'); }
      });
    });
    // Named, because "Delete this schedule?" on a list of six is not a question anybody
    // can answer correctly — it reads back the report, the cadence and the recipient.
    async function deleteSchedule(sched) {
      if (window.KT && KT.confirm && !(await KT.confirm({
        title: 'Delete this schedule?',
        description: sched.report_title + ' — ' + sched.schedule_label + ' to ' + sched.recipient,
      }))) return;
      try { await Api.delete('/admin/report-schedules/' + sched.id); loadSchedules(); repToast('Schedule deleted', 'success'); }
      catch (e) { repToast(e.message || 'Failed', 'error'); }
    }

    async function runSchedule(id) {
      repToast('Sending…', 'info');
      try { await Api.post('/admin/report-schedules/' + id + '/run', {}); repToast('Report sent', 'success'); loadSchedules(); }
      catch (e) { repToast(e.message || 'Could not send', 'error'); }
    }
  }

  function schedFld(label, control) { return '<label style="font-size:13px;font-weight:700;color:#334155;display:block;">' + esc(label) + '<div style="margin-top:4px;">' + control + '</div></label>'; }

  /* Create AND edit. One form rather than two: nine fields duplicated across two modals
     drift apart, and the bug arrives later as "the edit screen forgets my centre". */
  function openScheduleModal(existing) {
    var editing = !!(existing && existing.id);
    var reps = META.reports || [], centres = META.centres || [];
    var isM = window.matchMedia && window.matchMedia('(max-width:768px)').matches;
    var inp = 'width:100%;padding:9px 11px;border:1.5px solid #E2E8F0;border-radius:9px;font-size:14px;box-sizing:border-box;font-family:inherit;';
    var ov = document.createElement('div');
    ov.style.cssText = 'position:fixed;inset:0;background:rgba(15,23,42,.5);z-index:9999;display:flex;align-items:center;justify-content:center;' + (isM ? 'padding:10px 12px calc(84px + env(safe-area-inset-bottom,0px)) 12px;' : 'padding:16px;');
    ov.innerHTML = '<div style="background:#fff;border-radius:16px;max-width:520px;width:100%;padding:22px;max-height:' + (isM ? 'calc(100vh - 150px)' : '92vh') + ';overflow:auto;">' +
      '<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:14px;"><div style="font-weight:800;font-size:18px;color:#0F172A;">' + (editing ? '✏️ Edit schedule' : '📅 Schedule a report') + '</div><button type="button" data-x style="background:none;border:none;font-size:22px;color:#94A3B8;cursor:pointer;line-height:1;">×</button></div>' +
      '<div style="display:grid;gap:12px;">' +
        schedFld('Report', '<select id="sc-type" style="' + inp + '">' + reps.map(function (r) { return '<option value="' + esc(r.type) + '">' + esc(r.title) + '</option>'; }).join('') + '</select>') +
        schedFld('Centre', '<select id="sc-centre" style="' + inp + '"><option value="">All centres</option>' + centres.map(function (c) { return '<option value="' + c.id + '">' + esc(c.name) + '</option>'; }).join('') + '</select>') +
        schedFld('Format', '<select id="sc-format" style="' + inp + '"><option value="pdf">PDF</option><option value="csv">CSV / Excel</option><option value="both">Both PDF + CSV</option></select>') +
        schedFld('Date range', '<select id="sc-range" style="' + inp + '"><option value="last_7d">Last 7 days</option><option value="last_30d">Last 30 days</option><option value="this_month">This month to date</option><option value="last_month">Last calendar month</option><option value="all">All records</option></select>') +
        '<div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;">' +
          schedFld('Frequency', '<select id="sc-freq" style="' + inp + '"><option value="daily">Daily</option><option value="weekly" selected>Weekly</option><option value="monthly">Monthly</option></select>') +
          schedFld('Time', '<input type="time" id="sc-time" value="07:00" style="' + inp + '">') +
        '</div>' +
        '<div id="sc-dow-wrap">' + schedFld('Day of week', '<select id="sc-dow" style="' + inp + '">' + DOW.map(function (d, i) { return '<option value="' + i + '"' + (i === 1 ? ' selected' : '') + '>' + d + '</option>'; }).join('') + '</select>') + '</div>' +
        '<div id="sc-dom-wrap" style="display:none;">' + schedFld('Day of month (1–28)', '<input type="number" id="sc-dom" min="1" max="28" value="1" style="' + inp + '">') + '</div>' +
        schedFld('Send to (email)', '<input type="email" id="sc-email" placeholder="name@example.com" style="' + inp + '">') +
        '<div style="font-size:11.5px;color:#94A3B8;margin-top:-4px;">Emailed to this address — staff, director, accountant, any valid email.</div>' +
      '</div>' +
      '<div id="sc-status" style="min-height:18px;font-size:13px;margin-top:8px;"></div>' +
      '<div style="display:flex;justify-content:flex-end;gap:8px;margin-top:6px;"><button type="button" data-x style="background:#F1F5F9;color:#475569;border:none;border-radius:10px;padding:9px 16px;font-weight:800;cursor:pointer;">Cancel</button><button type="button" id="sc-save" style="background:#1F6080;color:#fff;border:none;border-radius:10px;padding:9px 18px;font-weight:800;cursor:pointer;">' + (editing ? 'Save changes' : 'Save schedule') + '</button></div>' +
      '</div>';
    document.body.appendChild(ov);
    var close = function () { if (ov.parentNode) ov.parentNode.removeChild(ov); };
    ov.addEventListener('click', function (e) { if (e.target === ov) close(); });
    ov.querySelectorAll('[data-x]').forEach(function (b) { b.addEventListener('click', close); });
    // Fill from the existing schedule BEFORE syncFreq runs, so the weekly/monthly rows
    // show or hide according to the saved frequency rather than the form's default.
    if (editing) {
      var setVal = function (sel, val) {
        var el = ov.querySelector(sel);
        if (el && val !== null && val !== undefined && val !== '') { el.value = String(val); }
      };
      setVal('#sc-type', existing.report_type);
      setVal('#sc-centre', existing.centre_id);
      setVal('#sc-format', existing.format);
      setVal('#sc-range', existing.range_kind);
      setVal('#sc-freq', existing.frequency);
      // The API returns "07:00:00"; an <input type=time> wants "07:00" and silently
      // ignores anything else, which would reset the time on every edit.
      setVal('#sc-time', String(existing.send_time || '').slice(0, 5));
      setVal('#sc-dow', existing.day_of_week);
      setVal('#sc-dom', existing.day_of_month);
      setVal('#sc-email', existing.recipient_email || existing.recipient);
    }

    var freq = ov.querySelector('#sc-freq');
    function syncFreq() { ov.querySelector('#sc-dow-wrap').style.display = freq.value === 'weekly' ? '' : 'none'; ov.querySelector('#sc-dom-wrap').style.display = freq.value === 'monthly' ? '' : 'none'; }
    freq.addEventListener('change', syncFreq); syncFreq();
    ov.querySelector('#sc-save').addEventListener('click', async function () {
      var st = ov.querySelector('#sc-status');
      var body = {
        report_type: ov.querySelector('#sc-type').value,
        centre_id: ov.querySelector('#sc-centre').value || null,
        format: ov.querySelector('#sc-format').value,
        frequency: freq.value,
        send_time: ov.querySelector('#sc-time').value,
        range_kind: ov.querySelector('#sc-range').value,
        recipient_email: (ov.querySelector('#sc-email').value || '').trim() || null,
      };
      if (freq.value === 'weekly') body.day_of_week = parseInt(ov.querySelector('#sc-dow').value, 10);
      if (freq.value === 'monthly') body.day_of_month = parseInt(ov.querySelector('#sc-dom').value, 10) || 1;
      if (!body.recipient_email) { st.style.color = '#B91C1C'; st.textContent = 'Enter a recipient email.'; return; }
      if (!body.send_time) { st.style.color = '#B91C1C'; st.textContent = 'Pick a send time.'; return; }
      var save = ov.querySelector('#sc-save'); save.disabled = true;
      try {
        if (editing) { await Api.patch('/admin/report-schedules/' + existing.id, body); }
        else { await Api.post('/admin/report-schedules', body); }
        close(); loadSchedules(); repToast(editing ? 'Schedule updated' : 'Schedule created', 'success');
      }
      catch (e) { save.disabled = false; st.style.color = '#B91C1C'; st.textContent = '✗ ' + (e.message || 'Could not save'); }
    });
  }

  /* ── Criteria for Invoices & balances ──────────────────────────────────
     Only this report asks: it is the one where "everything" is rarely the question and
     the row count makes the difference between a page and a ream. Anything else runs
     straight off its button as before. */
  function openInvoiceCriteria(type) {
    var overlay = document.createElement('div');
    overlay.style.cssText = 'position:fixed;inset:0;background:rgba(15,23,42,.45);z-index:12000;display:flex;align-items:center;justify-content:center;padding:16px;';
    var box = function (id, label, hint, on) {
      return '<label style="display:flex;align-items:flex-start;gap:10px;padding:7px 0;cursor:pointer;">'
        + '<input id="' + id + '" type="checkbox" ' + (on ? 'checked' : '') + ' style="margin-top:3px;width:16px;height:16px;flex-shrink:0;">'
        + '<span><span style="font-size:14px;color:#334155;font-weight:600;">' + esc(label) + '</span>'
        + (hint ? '<span style="display:block;font-size:12px;color:#64748B;">' + esc(hint) + '</span>' : '') + '</span></label>';
    };
    overlay.innerHTML = '<div style="background:#fff;border-radius:14px;max-width:440px;width:100%;padding:20px 22px;box-shadow:0 18px 48px rgba(0,0,0,.28);">'
      + '<div style="font-size:16px;font-weight:800;color:#0D1B2A;margin:0 0 4px;">🧾 Invoices &amp; balances</div>'
      + '<div style="font-size:12.5px;color:#64748B;margin:0 0 12px;">Choose what to include. The date range above still applies.</div>'
      + '<div style="font-size:11px;font-weight:800;letter-spacing:1px;color:#64748B;text-transform:uppercase;margin:6px 0 2px;">Status</div>'
      + box('ic-open', 'Open', '', true)
      + box('ic-paid', 'Paid', '', true)
      + box('ic-overdue', 'Overdue', '', true)
      + box('ic-void', 'Voided', 'Raised, then cancelled — off by default.', false)
      + '<div style="font-size:11px;font-weight:800;letter-spacing:1px;color:#64748B;text-transform:uppercase;margin:14px 0 4px;">Period</div>'
      + '<select id="ic-period" style="width:100%;box-sizing:border-box;padding:9px 11px;border:1px solid #D6DEE7;border-radius:9px;font-size:14px;background:#fff;">'
      + '<option value="">The date range on the screen</option>'
      + '<option value="month">A whole month</option>'
      + '<option value="range">Specific dates</option>'
      + '<option value="all">Every invoice, all dates</option>'
      + '</select>'
      + '<div id="ic-month-wrap" style="display:none;margin-top:8px;">'
      +   '<input id="ic-month" type="month" style="width:100%;box-sizing:border-box;padding:9px 11px;border:1px solid #D6DEE7;border-radius:9px;font-size:14px;">'
      + '</div>'
      + '<div id="ic-range-wrap" style="display:none;gap:8px;margin-top:8px;">'
      +   '<input id="ic-from" type="date" style="flex:1;min-width:0;box-sizing:border-box;padding:9px 11px;border:1px solid #D6DEE7;border-radius:9px;font-size:14px;">'
      +   '<span style="align-self:center;color:#64748B;font-size:13px;">to</span>'
      +   '<input id="ic-to" type="date" style="flex:1;min-width:0;box-sizing:border-box;padding:9px 11px;border:1px solid #D6DEE7;border-radius:9px;font-size:14px;">'
      + '</div>'
      + '<div style="font-size:11px;font-weight:800;letter-spacing:1px;color:#64748B;text-transform:uppercase;margin:14px 0 4px;">Balance</div>'
      + '<select id="ic-balance" style="width:100%;box-sizing:border-box;padding:9px 11px;border:1px solid #D6DEE7;border-radius:9px;font-size:14px;background:#fff;">'
      + '<option value="">Any balance</option>'
      + '<option value="owing">Only invoices with money still owing</option>'
      + '<option value="settled">Only invoices settled in full</option>'
      + '</select>'
      + '<div id="ic-err" style="color:#DC2626;font-size:12.5px;min-height:17px;margin-top:8px;"></div>'
      + '<div style="display:flex;justify-content:flex-end;gap:8px;margin-top:6px;">'
      + '<button id="ic-cancel" style="background:#fff;color:#374151;border:1px solid #D1D5DB;padding:9px 16px;border-radius:9px;font-size:13px;font-weight:700;cursor:pointer;">Cancel</button>'
      + '<button id="ic-run" style="background:#1F6080;color:#fff;border:0;padding:9px 18px;border-radius:9px;font-size:13px;font-weight:700;cursor:pointer;">Run report</button>'
      + '</div></div>';
    document.body.appendChild(overlay);
    overlay.addEventListener('click', function (e) { if (e.target === overlay) overlay.remove(); });
    overlay.querySelector('#ic-cancel').addEventListener('click', function () { overlay.remove(); });

    // Only the chosen kind of period is on screen — three date controls at once invites
    // filling in the wrong pair.
    var periodSel = overlay.querySelector('#ic-period');
    var monthWrap = overlay.querySelector('#ic-month-wrap');
    var rangeWrap = overlay.querySelector('#ic-range-wrap');
    periodSel.addEventListener('change', function () {
      monthWrap.style.display = periodSel.value === 'month' ? 'block' : 'none';
      rangeWrap.style.display = periodSel.value === 'range' ? 'flex' : 'none';
    });
    // Default the month box to the one just gone: a month-to-date figure is rarely what
    // somebody opening a billing report wants.
    try {
      var d = new Date();
      d.setDate(1); d.setMonth(d.getMonth() - 1);
      overlay.querySelector('#ic-month').value = d.getFullYear() + '-' + ('0' + (d.getMonth() + 1)).slice(-2);
    } catch (e) {}

    overlay.querySelector('#ic-run').addEventListener('click', function () {
      var picked = [];
      ['open', 'paid', 'overdue', 'void'].forEach(function (k) {
        var el = overlay.querySelector('#ic-' + k);
        if (el && el.checked) picked.push(k);
      });
      // No status at all would return nothing and look like a broken report.
      if (! picked.length) {
        overlay.querySelector('#ic-err').textContent = 'Pick at least one status, or the report has nothing to show.';
        return;
      }
      var balance = (overlay.querySelector('#ic-balance') || {}).value || '';

      // Whatever the period control says wins over the boxes on the screen behind it.
      var period = periodSel.value;
      var from = null, to = null, allDates = false;
      if (period === 'month') {
        var m = (overlay.querySelector('#ic-month') || {}).value || '';
        if (! /^\d{4}-\d{2}$/.test(m)) {
          overlay.querySelector('#ic-err').textContent = 'Pick a month.';
          return;
        }
        var y = +m.slice(0, 4), mo = +m.slice(5, 7);
        from = m + '-01';
        // Day 0 of the NEXT month is the last day of this one — no leap-year table needed.
        var last = new Date(y, mo, 0);
        to = m + '-' + ('0' + last.getDate()).slice(-2);
      } else if (period === 'range') {
        from = (overlay.querySelector('#ic-from') || {}).value || '';
        to = (overlay.querySelector('#ic-to') || {}).value || '';
        if (! from && ! to) {
          overlay.querySelector('#ic-err').textContent = 'Give at least one date, or choose another period.';
          return;
        }
        if (from && to && from > to) {
          overlay.querySelector('#ic-err').textContent = 'The start date is after the end date.';
          return;
        }
      } else if (period === 'all') {
        allDates = true;
      }

      overlay.remove();
      runReport(type, { status: picked.join(','), balance: balance, from: from, to: to, allDates: allDates });
    });
  }

  var LAST_OPTS = {};

  async function runReport(type, opts) {
    // Ask first, run second — but only the first time, so the re-run after a sort or an
    // export does not re-prompt for criteria already chosen.
    if (type === 'invoices' && !opts) { return openInvoiceCriteria(type); }
    var out = document.getElementById('rep-out');
    if (!out) return;
    var centreId = (document.getElementById('rep-centre') || {}).value || '';
    var from = (document.getElementById('rep-from') || {}).value || '';
    var to = (document.getElementById('rep-to') || {}).value || '';
    out.innerHTML = '<div style="padding:24px;color:#64748B;">Generating report…</div>';
    // A period chosen in the criteria window overrides the boxes on the screen; "all
    // dates" clears them outright rather than quietly keeping yesterday's range.
    if (opts && opts.allDates) { from = ''; to = ''; }
    else if (opts && (opts.from || opts.to)) { from = opts.from || ''; to = opts.to || ''; }

    var qs = 'type=' + encodeURIComponent(type);
    if (centreId) qs += '&centre_id=' + encodeURIComponent(centreId);
    if (from) qs += '&from=' + encodeURIComponent(from);
    if (to) qs += '&to=' + encodeURIComponent(to);
    if (opts && opts.status) qs += '&status=' + encodeURIComponent(opts.status);
    if (opts && opts.balance) qs += '&balance=' + encodeURIComponent(opts.balance);
    // Remembered so a re-render (sort, export, print) reuses the same criteria rather
    // than silently widening the report back out to everything.
    LAST_OPTS[type] = opts || null;
    var r;
    try { r = await Api.get('/reports/canned/run?' + qs); }
    catch (e) { out.innerHTML = '<div style="padding:24px;color:#DC2626;">Could not run report: ' + esc(e.message || 'error') + '</div>'; return; }

    var cols = r.columns || [];
    var rows = r.rows || [];
    var ag = r.agency || {}, ce = r.centre || null;
    var range = (r.date_from || r.date_to)
      ? ((r.date_from || '…') + ' → ' + (r.date_to || '…'))
      : 'All dates';

    // An empty report should say where the records ARE, not tell you to go hunting.
    // available_range comes back only when the answer was zero, and only for the reports
    // the server can work it out for; without it this reads as it always did.
    function emptyRow(r, span) {
      var ranged = !!(r.date_from || r.date_to);
      var av = r.available_range;
      var head = 'No records' + (ranged ? ' between <strong>' + esc(r.date_from || '…') + '</strong> and <strong>' + esc(r.date_to || '…') + '</strong>' : '') + '.';
      var hint, action = '';
      if (av && av.count > 0 && av.from) {
        hint = 'This report has <strong>' + av.count + '</strong> record' + (av.count === 1 ? '' : 's') +
               ', from <strong>' + esc(av.from) + '</strong> to <strong>' + esc(av.to) + '</strong>.';
        action = '<div style="margin-top:12px;"><button type="button" id="rep-usefull" data-from="' + esc(av.from) +
                 '" data-to="' + esc(av.to) + '" style="font-size:13px;font-weight:700;padding:8px 16px;border-radius:8px;' +
                 'cursor:pointer;border:0;background:#1F6080;color:#fff;">Show ' + esc(av.from) + ' → ' + esc(av.to) + '</button></div>';
      } else if (av && av.count === 0) {
        // Not a date problem — there is nothing at all for this scope.
        hint = 'There are no records for this centre or agency yet, in any date range.';
      } else {
        hint = ranged ? 'Try widening the date range above.' : 'Nothing to show for this selection.';
      }
      return '<tr><td colspan="' + span + '" style="padding:30px;text-align:center;color:#64748B;">' + head +
             '<br><span style="font-size:12px;color:#94A3B8;">' + hint + '</span>' + action + '</td></tr>';
    }

    var thead = '<tr>' + cols.map(function (c, i) {
      var numeric = /amount|total|paid|balance|hours/i.test(c);
      return '<th style="text-align:' + (numeric && i > 0 ? 'right' : 'left') + ';padding:9px 12px;font-size:10.5px;font-weight:800;letter-spacing:.05em;text-transform:uppercase;color:#fff;background:' +
        esc(ag.color || '#1F6080') + ';">' + esc(c) + '</th>';
    }).join('') + '</tr>';

    var tbody = rows.length ? rows.map(function (row, ri) {
      var bg = ri % 2 ? '#F5F8FB' : '#FFFFFF';   // zebra
      return '<tr style="background:' + bg + ';">' + cols.map(function (c, i) {
        var v = row[c]; var numeric = /amount|total|paid|balance|hours/i.test(c);
        var isStatus = /status/i.test(c);
        var cell = esc(v);
        if (isStatus && v) {
          var tint = statusTint(v);
          cell = '<span style="display:inline-block;padding:2px 9px;border-radius:20px;font-size:11px;font-weight:700;' +
            'background:' + tint.bg + ';color:' + tint.fg + ';white-space:nowrap;">' + esc(v) + '</span>';
        }
        return '<td style="padding:8px 12px;font-size:12.5px;color:#1E293B;border-bottom:1px solid #E9EEF3;text-align:' + (numeric && i > 0 ? 'right' : 'left') + ';white-space:nowrap;">' + cell + '</td>';
      }).join('') + '</tr>';
    }).join('') : emptyRow(r, cols.length);

    // Explicit, visible button styles — kt-btn-ghost is white-on-transparent
    // (built for dark hero backgrounds) and vanished on this white footer.
    // The "show me where the data is" button has to survive this innerHTML rebuild, so it
    // is bound after the table is written, not here.
    setTimeout(function () {
      var b = document.getElementById('rep-usefull');
      if (!b) return;
      b.addEventListener('click', function () {
        var f = document.getElementById('rep-from'), t = document.getElementById('rep-to');
        if (f) f.value = b.dataset.from;
        if (t) t.value = b.dataset.to;
        runReport(type, LAST_OPTS[type]);
      });
    }, 0);

    var _dlBtn = 'font-size:13px;font-weight:600;padding:8px 15px;border-radius:8px;cursor:pointer;border:1px solid #D6DEE7;background:#F3F6F9;color:#1E293B;';
    var _primBtn = 'font-size:13px;font-weight:600;padding:8px 16px;border-radius:8px;cursor:pointer;border:0;background:' + (ag.color || '#1F6080') + ';color:#fff;';
    var _u = {}; try { _u = JSON.parse(sessionStorage.getItem('kt_user') || '{}'); } catch (e) {}
    var producedBy = _u.name || (((_u.first_name || '') + ' ' + (_u.last_name || '')).trim()) || _u.email || 'a signed-in user';
    var _nowStr = new Date().toLocaleString();

    out.innerHTML =
      '<div class="kt-report-doc" style="background:#fff;border:1px solid #E7EBF0;border-radius:14px;overflow:hidden;">' +
        '<div style="display:flex;justify-content:space-between;align-items:center;gap:16px;padding:18px 22px;border-bottom:3px solid ' + esc(ag.color || '#1F6080') + ';">' +
          '<div style="display:flex;align-items:center;gap:12px;">' + logoBox(ag.logo, ag.name, ag.color) +
            '<div><div style="font-weight:800;font-size:16px;color:#0D1B2A;">' + esc(ag.name || 'Agency') + '</div>' +
            '<div style="font-size:12px;color:#64748B;">' + esc(r.icon || '') + ' ' + esc(r.title) + '</div></div>' +
          '</div>' +
          '<div style="text-align:right;display:flex;align-items:center;gap:12px;">' +
            '<div><div style="font-size:12px;color:#64748B;">' + esc(ce ? ce.name : 'All centres') + '</div>' +
            '<div style="font-size:12px;color:#64748B;">' + esc(range) + '</div>' +
            '<div style="font-size:11px;color:#B6C0CC;">Generated ' + esc(todayISO(0)) + ' · ' + rows.length + ' rows</div></div>' +
            (ce ? logoBox(ce.logo, ce.name, ce.color) : '') +
          '</div>' +
        '</div>' +
        '<table style="width:100%;border-collapse:collapse;">' +
          '<thead>' + thead + '</thead><tbody>' + tbody + '</tbody></table>' +
        '<div style="padding:9px 18px;font-size:11px;color:#64748B;border-top:1px solid #EEF2F7;line-height:1.5;">' +
          'Generated ' + esc(_nowStr) + ' by ' + esc(producedBy) + ' · ' + esc(ag.name || '') +
          ' &nbsp;·&nbsp; <b style="color:#B91C1C;">PRIVATE &amp; CONFIDENTIAL</b> — Contains sensitive information; do not distribute without authorisation.' +
        '</div>' +
        '<div class="kt-report-noprint" style="padding:12px 16px;display:flex;gap:8px;justify-content:flex-end;flex-wrap:wrap;border-top:1px solid #EEF2F7;">' +
          '<button id="rep-xlsx" class="kt-icon-tip" title="Export to Excel" aria-label="Export to Excel" data-kttip="Export to Excel" style="' + _dlBtn + ';width:38px;height:38px;padding:0;font-size:17px;display:inline-flex;align-items:center;justify-content:center;position:relative;">📊</button>' +
          '<button id="rep-csv" class="kt-icon-tip" title="Export to CSV" aria-label="Export to CSV" data-kttip="Export to CSV" style="' + _dlBtn + ';width:38px;height:38px;padding:0;font-size:17px;display:inline-flex;align-items:center;justify-content:center;position:relative;">📄</button>' +
          '<button id="rep-pdf" class="kt-icon-tip" title="Export to PDF" aria-label="Export to PDF" data-kttip="Export to PDF" style="' + _dlBtn + ';width:38px;height:38px;padding:0;font-size:17px;display:inline-flex;align-items:center;justify-content:center;position:relative;">📕</button>' +
          '<button id="rep-print" class="kt-icon-tip" title="Print" aria-label="Print" data-kttip="Print" style="' + _primBtn + ';width:38px;height:38px;padding:0;font-size:17px;display:inline-flex;align-items:center;justify-content:center;position:relative;">🖨️</button>' +
        '</div>' +
      '</div>';

    var printCss = document.getElementById('kt-report-print-css');
    if (!printCss) {
      printCss = document.createElement('style');
      printCss.id = 'kt-report-print-css';
      printCss.textContent = '@media print{body *{visibility:hidden!important;}#appMain .kt-report-doc,#appMain .kt-report-doc *{visibility:visible!important;}#appMain .kt-report-doc{position:absolute;left:0;top:0;width:100%;border:0!important;}.kt-report-noprint{display:none!important;}}';
      document.head.appendChild(printCss);
    }
    out.scrollIntoView({ behavior: 'smooth', block: 'start' });
    document.getElementById('rep-print').onclick = function () { window.print(); };
    document.getElementById('rep-csv').onclick = function () { downloadCsv(r); };
    document.getElementById('rep-xlsx').onclick = function (ev) { downloadServerFile(qs, type, 'xlsx', ev.currentTarget); };
    document.getElementById('rep-pdf').onclick = function (ev) { downloadServerFile(qs, type, 'pdf', ev.currentTarget); };
  }

  // PDF (dompdf) + Excel (PhpSpreadsheet) are rendered server-side so they're
  // real, branded files (a proper .xlsx opens with no "format" error, with the
  // agency logo + colours + Private & Confidential footer). Both need the auth
  // token, so we fetch as a blob rather than a plain link.
  async function downloadServerFile(qs, type, kind, btn) {
    var base = (window.KT && window.KT.API_BASE) || 'https://api.kiddietrac.com/api/v1';
    var token = sessionStorage.getItem('kt_token') || localStorage.getItem('kt_token');
    var agency = '';
    try { agency = sessionStorage.getItem('kt_active_agency_id') || ''; } catch (e) {}
    var ext = kind === 'xlsx' ? '.xlsx' : '.pdf';
    var label = btn ? btn.textContent : '';
    if (btn) { btn.textContent = '…'; btn.disabled = true; }
    try {
      var res = await fetch(base + '/reports/canned/' + kind + '?' + qs, { headers: { 'Authorization': 'Bearer ' + token, 'X-Active-Agency-Id': agency } });
      if (!res.ok) throw new Error('HTTP ' + res.status);
      saveBlob(await res.blob(), (type || 'report') + '_' + todayISO(0) + ext);
    } catch (e) {
      alert(kind.toUpperCase() + ' export failed: ' + (e.message || e));
    } finally {
      if (btn) { btn.textContent = label; btn.disabled = false; }
    }
  }

  function saveBlob(blob, filename) {
    var url = URL.createObjectURL(blob);
    var a = document.createElement('a');
    a.href = url; a.download = filename;
    document.body.appendChild(a); a.click(); a.remove();
    setTimeout(function () { URL.revokeObjectURL(url); }, 4000);
  }

  function downloadCsv(r) {
    var cols = r.columns || [], rows = r.rows || [];
    var q = function (v) { v = String(v == null ? '' : v); return /[",\n]/.test(v) ? '"' + v.replace(/"/g, '""') + '"' : v; };
    var csv = cols.map(q).join(',') + '\n' + rows.map(function (row) { return cols.map(function (c) { return q(row[c]); }).join(','); }).join('\n');
    var a = document.createElement('a');
    a.href = 'data:text/csv;charset=utf-8,' + encodeURIComponent(csv);
    a.download = (r.type || 'report') + '_' + todayISO(0) + '.csv';
    document.body.appendChild(a); a.click(); a.remove();
  }

  /* Educators joined this list on 2026-08-30. They do NOT get the same screen:
     /reports/canned returns them a four-report subset (attendance, staff hours,
     observations, incidents), each cut down to their own rooms and their own
     clock-ins, and `can_schedule:false` so the scheduling panel never renders.
     The whole role decision is the server's - this file only draws what it is
     handed, which is why nothing here tests the body class. */
  ['agency_admin', 'platform_admin', 'centre_director', 'auditor', 'educator'].forEach(function (role) {
    Shell.registerScreen(role + ':reports', render);
  });
  if (KT) KT.CannedReports = { render: render };
})(window);
