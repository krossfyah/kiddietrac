/* ═══════════════════════════════════════════════════════════════════
   KiddieTrac — Safe Arrival (2026-09-29)

   Anthony: "the safe arrival gap -- can you see what we are missing with our system vs
   the competitor and build this out". The board for staff: who is expected today, who
   has arrived, who is reported absent, and -- first -- who is overdue with nobody having
   said why. Parents are asked automatically (kiddietrac:safe-arrival, every 5 minutes);
   when they do not answer, the room's educators and the director are alerted and land
   here. Each case is closed with what happened, and that is kept.

   Educators see their rooms, directors their centres, admins the agency.
   ═══════════════════════════════════════════════════════════════════ */
(function (window) {
  'use strict';
  var API = function () { return (window.KT && window.KT.API_BASE) || 'https://api.kiddietrac.com/api/v1'; };
  function hdrs(json) {
    var h = { Authorization: 'Bearer ' + (sessionStorage.getItem('kt_token') || localStorage.getItem('kt_token') || ''), Accept: 'application/json' };
    var aid = sessionStorage.getItem('kt_active_agency_id'); if (aid) h['X-Active-Agency-Id'] = aid;
    if (json) h['Content-Type'] = 'application/json';
    return h;
  }
  async function call(method, path, body) {
    var r = await fetch(API() + path, { method: method, headers: hdrs(!!body), body: body ? JSON.stringify(body) : undefined });
    var j = await r.json().catch(function () { return {}; });
    if (!r.ok) throw new Error(j.message || ('Request failed (' + r.status + ')'));
    return j;
  }
  function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }

  var STATE = {
    escalated: ['#FEE2E2', '#991B1B', 'Staff alerted'],
    overdue:   ['#FEF3C7', '#92400E', 'Overdue'],
    waiting:   ['#F1F5F9', '#475569', 'Not due yet'],
    resolved:  ['#E0F2FE', '#075985', 'Accounted for'],
    absent:    ['#EDE9FE', '#5B21B6', 'Absent'],
    arrived:   ['#DCFCE7', '#166534', 'Arrived'],
    on_the_way:  ['#E0F2FE', '#075985', 'On the way (parent)'],
    dropped_off: ['#FEE2E2', '#991B1B', 'Parent says dropped off — NOT signed in'],
    counted:     ['#FEF9C3', '#854D0E', 'Counted in headcount — not signed in'],
    running_late: ['#E0E7FF', '#3730A3', 'Late drop-off'],
  };
  var LATE_BTN = 'height:28px;padding:0 10px;border-radius:8px;border:1px solid #C7D2FE;background:#fff;color:#3730A3;font-weight:800;font-size:12px;cursor:pointer;';
  /* End of day — Safe Departure (2026-10-22): children still signed in past pickup/closing. */
  var DSTATE = {
    unconfirmed: ['#FEE2E2', '#991B1B', 'Signed out automatically — pickup NOT confirmed'],
    parents:     ['#FEE2E2', '#991B1B', 'Parents asked'],
    escalated:   ['#FEE2E2', '#991B1B', 'Directors/admins alerted'],
    overdue:     ['#FEF3C7', '#92400E', 'Still signed in'],
    running_late: ['#E0E7FF', '#3730A3', 'Late pickup'],
    waiting:     ['#F1F5F9', '#475569', 'Signed in'],
    checked_out: ['#DCFCE7', '#166534', 'Signed out'],
    resolved:    ['#E0F2FE', '#075985', 'Accounted for'],
  };
  var DURGENT = { unconfirmed: 1, parents: 1, escalated: 1, overdue: 1 };
  function lateWho(l) {
    if (!l || !l.logged_by) return l && l.source === 'parent' ? ' (parent)' : '';
    return ' (' + esc(l.logged_by) + (l.source === 'parent' ? ', parent' : '') + ')';
  }
  function dchip(state) {
    var s = DSTATE[state] || DSTATE.waiting;
    return '<span style="background:' + s[0] + ';color:' + s[1] + ';font-size:11.5px;font-weight:800;padding:2px 9px;border-radius:999px;white-space:nowrap;">' + s[2] + '</span>';
  }
  function guardianLine(r) {
    return r.guardians && r.guardians.length ? '<div style="display:flex;gap:14px;flex-wrap:wrap;margin-top:10px;font-size:13px;">' + r.guardians.map(function (g) {
        return '<span>👤 ' + esc(g.name) + (g.relationship ? ' <span style="color:#94A3B8;">(' + esc(g.relationship) + ')</span>' : '')
          + (g.phone ? ' · <a href="tel:' + esc(g.phone) + '" style="color:#1F6080;font-weight:800;">📞 ' + esc(g.phone) + '</a>' : ' · <span style="color:#94A3B8;">no phone</span>') + '</span>';
      }).join('') + '</div>' : '<div style="font-size:12.5px;color:#94A3B8;margin-top:8px;">No guardian on file.</div>';
  }
  function departuresHtml(d, s) {
    var rows = d.departures || [];
    if (!s.departure_enabled && !rows.length) return '';
    var urgent = rows.filter(function (r) { return DURGENT[r.state]; });
    var rest = rows.filter(function (r) { return !DURGENT[r.state]; });
    var many = d.centres && d.centres.length > 1;
    var BTN = 'height:32px;padding:0 12px;border-radius:8px;font-weight:800;font-size:12.5px;cursor:pointer;';
    return '<h3 style="margin:24px 0 4px;font-size:15px;color:#0F172A;">🕔 End of day — still signed in</h3>'
      + '<div style="font-size:12.5px;color:#64748B;margin:0 0 10px;line-height:1.5;">'
      + (s.departure_enabled
        ? 'A child still signed in ' + s.departure_grace_minutes + ' min after their pickup time (or the centre\'s closing time) alerts the room\'s educators; after ' + s.departure_escalate_after_minutes + ' more min, ' + whoUp(s) + '; after ' + s.departure_parents_after_minutes + ' more, the parents are asked.'
        : '<b style="color:#B91C1C;">Off.</b> Nobody is alerted about children still signed in. Turn it on in ⚙️ Settings.')
      + '</div>'
      + (urgent.length ? urgent.map(function (r) {
          var red = r.state !== 'overdue';
          return '<div style="border:' + (r.state === 'unconfirmed' ? '2px' : '1px') + ' solid ' + (red ? '#FCA5A5' : '#FCD34D') + ';background:' + (red ? '#FFF7F7' : '#FFFDF5') + ';border-radius:12px;padding:14px 16px;margin-bottom:10px;">'
            + '<div style="display:flex;justify-content:space-between;gap:10px;flex-wrap:wrap;align-items:center;">'
            + '<div><div style="font-weight:800;font-size:15px;color:#0F172A;">' + esc(r.name) + ' ' + dchip(r.state) + '</div>'
            + '<div style="font-size:12.5px;color:#475569;margin-top:3px;">' + esc(r.room_name || '') + (many ? ' · ' + esc(r.centre_name) : '')
            + (r.checked_in ? ' · signed in ' + esc(r.checked_in) : '')
            + ' · ' + (r.expected_source === 'pickup' ? 'usual pickup ' : r.expected_source === 'closing' ? 'closes ' : 'expected out ') + esc(r.expected)
            + (r.educator_alerted ? ' · educators alerted ' + esc(r.educator_alerted) : '')
            + (r.escalated ? ' · escalated ' + esc(r.escalated) : '')
            + (r.parents_notified ? ' · parents asked ' + esc(r.parents_notified) : '') + '</div>'
            + (r.state === 'unconfirmed' ? '<div style="font-size:12.5px;font-weight:800;margin-top:4px;color:#991B1B;">Signed out automatically at ' + esc(r.signed_out || r.auto_signed_out || '') + ' — nobody confirmed the pickup. Find out where ' + esc(r.name.split(' ')[0]) + ' is.</div>' : '')
            + (r.staff_unreached ? '<div style="font-size:12.5px;color:#991B1B;font-weight:700;margin-top:4px;">⚠️ No phone alert reached: ' + esc(r.staff_unreached) + ' (emailed instead)</div>' : '')
            + '</div>'
            + '<div style="display:flex;gap:6px;flex-wrap:wrap;">'
            + '<button type="button" data-sd-act="picked_up" data-child="' + r.child_id + '" data-kt-iconized="1" style="' + BTN + 'border:0;background:#166534;color:#fff;">Picked up at…</button>'
            + (r.state !== 'unconfirmed' ? '<button type="button" data-sd-act="still_here" data-child="' + r.child_id + '" data-kt-iconized="1" style="' + BTN + 'border:1px solid #CBD5E1;background:#fff;color:#1F6080;">Still here</button>' : '')
            + (r.state !== 'unconfirmed' ? '<button type="button" data-sd-late="' + r.child_id + '" data-kt-iconized="1" style="' + LATE_BTN + 'height:32px;font-size:12.5px;">⏰ Late pickup</button>' : '')
            + '<button type="button" data-sd-act="other" data-child="' + r.child_id + '" data-kt-iconized="1" style="' + BTN + 'border:1px solid #CBD5E1;background:#fff;color:#334155;font-weight:700;">Other…</button>'
            + '</div></div>'
            + guardianLine(r)
            + '</div>';
        }).join('') : (s.departure_enabled ? '<div style="padding:14px 16px;background:#F0FDF4;border-radius:12px;color:#166534;font-weight:700;font-size:13.5px;">✓ Nobody is overdue for pickup.</div>' : ''))
      + (rest.length ? '<table data-kt-no-kebab style="width:100%;border-collapse:collapse;margin-top:10px;"><thead><tr>'
        + ['Child', 'Room', 'Out by', 'Status', 'Details'].map(function (h) { return '<th style="text-align:left;padding:8px;border-bottom:1px solid #E5E7EB;font-size:12px;color:#6B7280;">' + h + '</th>'; }).join('')
        + '</tr></thead><tbody>' + rest.map(function (r) {
          var det = r.state === 'waiting' ? 'Signed in ' + esc(r.checked_in || '') + ' · check at ' + esc(r.due)
                + ' <button type="button" data-sd-late="' + r.child_id + '" data-kt-iconized="1" style="' + LATE_BTN + 'margin-left:6px;">⏰ Late pickup</button>'
            : r.state === 'running_late' && r.late ? 'Picked up by <b style="color:#3730A3;">' + esc(r.late.by) + '</b>' + (r.late.note ? ' — ' + esc(r.late.note) : '')
                + lateWho(r.late) + '. Nothing sent before ' + esc(r.late.check_at) + '. '
                + '<button type="button" data-sd-late="' + r.child_id + '" data-kt-iconized="1" style="' + LATE_BTN + '">Change</button>'
            : r.state === 'checked_out' ? 'Signed out ' + esc(r.signed_out || '')
            : esc(({ picked_up: 'Picked up', still_here: 'Still here (late pickup)', other: 'Other', checked_out: 'Signed out' })[r.resolution] || (r.resolution || '')) + (r.note ? ' — ' + esc(r.note) : '') + (r.resolved ? ' (' + esc(r.resolved) + ')' : '');
          return '<tr><td style="padding:9px 8px;border-bottom:1px solid #F3F4F6;font-size:13px;font-weight:600;">' + esc(r.name) + '</td>'
            + '<td style="padding:9px 8px;border-bottom:1px solid #F3F4F6;font-size:13px;">' + esc(r.room_name || '') + '</td>'
            + '<td style="padding:9px 8px;border-bottom:1px solid #F3F4F6;font-size:13px;">' + esc(r.expected) + '</td>'
            + '<td style="padding:9px 8px;border-bottom:1px solid #F3F4F6;">' + dchip(r.state) + '</td>'
            + '<td style="padding:9px 8px;border-bottom:1px solid #F3F4F6;font-size:12.5px;color:#475569;">' + det + '</td></tr>';
        }).join('') + '</tbody></table>' : '');
  }
  function whoUp(s) {
    var w = [];
    if (s.notify_directors !== false) w.push('the director');
    if (s.notify_admins) w.push('the admins');
    return (w.length ? w.join(' and ') : 'the admins') + ' ' + (w.length > 1 ? 'are' : 'is') + ' alerted'
      + (s.up_timing === 'after' ? ' ' + (s.up_after_minutes || 10) + ' min later if the child still isn\'t here' : '');
  }

  var REASONS = [['sick', 'Sick'], ['appointment', 'Appointment'], ['holiday', 'Holiday'], ['family', 'Family'], ['other', 'Other']];
  var centreFilter = '';
  var timer = null;

  /* ── HISTORY (2026-11-03) ─────────────────────────────────────────────
     Anthony: "for safe arrival i need to be able to see past history on the safe arrival page". Past checks,
     morning and end of day, each with its escalation path and - by name - who was alerted: room educators,
     directors/admins and parents (GET /safe-arrival/history). The same evening he could not see from the
     board that iLearn's admins had been reached; this is where that shows. */
  var view = 'today';
  var hist = { from: '', to: '', kind: 'all', q: '', alerted: false, centre: '' };
  var knownCentres = [];
  function tabsHtml() {
    return '<div role="tablist" aria-label="Safe Arrival" style="display:flex;gap:6px;margin:0 0 14px;">'
      + [['today', 'Today'], ['history', '🕘 History']].map(function (t) {
          return '<button type="button" role="tab" data-sa-view="' + t[0] + '" aria-selected="' + (view === t[0]) + '">' + t[1] + '</button>';
        }).join('') + '</div>';
  }
  function wireTabs(main, root) {
    root.querySelectorAll('[data-sa-view]').forEach(function (b) {
      b.onclick = function () {
        var v = b.getAttribute('data-sa-view');
        if (v === view) return;
        view = v;
        root.innerHTML = '<div style="color:#64748B;">Loading…</div>';
        render(main);
      };
    });
  }
  var HOUT = {
    'Signed in': ['#DCFCE7', '#166534'], 'Signed out': ['#DCFCE7', '#166534'], 'Picked up': ['#DCFCE7', '#166534'],
    'Absent': ['#EDE9FE', '#5B21B6'], 'Parent contacted': ['#E0F2FE', '#075985'], 'Other': ['#F1F5F9', '#475569'],
    'Still here (late pickup)': ['#FEF3C7', '#92400E'], 'Still open': ['#FEE2E2', '#991B1B'], 'Never closed': ['#FEE2E2', '#991B1B'],
    'Signed out automatically, pickup not confirmed': ['#FEE2E2', '#991B1B'],
  };
  function hchip(txt) {
    var c = HOUT[txt] || ['#F1F5F9', '#475569'];
    return '<span style="background:' + c[0] + ';color:' + c[1] + ';font-size:11.5px;font-weight:800;padding:2px 9px;border-radius:999px;white-space:nowrap;">' + esc(txt) + '</span>';
  }
  function whoLine(label, list) {
    return list && list.length ? '<div style="font-size:12.5px;color:#334155;margin-top:3px;"><b style="color:#64748B;">' + label + ':</b> ' + list.map(esc).join(', ') + '</div>' : '';
  }
  async function renderHistory(main, root) {
    var FLD = 'height:32px;padding:0 10px;border:1px solid #CBD5E1;border-radius:8px;font-size:13px;background:#fff;';
    if (!knownCentres.length) {
      try { knownCentres = (await call('GET', '/safe-arrival/today')).centres || []; } catch (e) { knownCentres = []; }
      if (!root.isConnected) return;
    }
    var q = ['kind=' + hist.kind];
    if (hist.from) q.push('from=' + hist.from);
    if (hist.to) q.push('to=' + hist.to);
    if (hist.q) q.push('q=' + encodeURIComponent(hist.q));
    if (hist.alerted) q.push('alerted=1');
    if (hist.centre) q.push('centre_id=' + encodeURIComponent(hist.centre));
    var d;
    try { d = await call('GET', '/safe-arrival/history?' + q.join('&')); }
    catch (e) { if (root.isConnected) { root.innerHTML = tabsHtml() + '<div style="color:#B91C1C;">' + esc(e.message) + '</div>'; wireTabs(main, root); } return; }
    if (!root.isConnected || view !== 'history') return;
    hist.from = d.from; hist.to = d.to;

    var byDate = {};
    (d.rows || []).forEach(function (r) { (byDate[r.date] = byDate[r.date] || []).push(r); });
    var dayLabel = function (iso) {
      if (iso === d.today) return 'Today';
      try { return new Date(iso + 'T12:00:00').toLocaleDateString(undefined, { weekday: 'long', month: 'long', day: 'numeric', year: 'numeric' }); } catch (e) { return iso; }
    };
    var card = function (r) {
      var steps = (r.steps || []).map(function (s) { return '<span style="white-space:nowrap;"><b style="color:#0F172A;">' + esc(s[0]) + '</b> ' + esc(s[1]) + '</span>'; }).join(' <span style="color:#CBD5E1;">→</span> ');
      return '<div style="border:1px solid #E5E7EB;border-radius:12px;padding:12px 14px;margin-bottom:8px;background:#fff;">'
        + '<div style="display:flex;justify-content:space-between;gap:8px;flex-wrap:wrap;align-items:center;">'
        + '<div style="font-weight:800;font-size:14.5px;color:#0F172A;">' + esc(r.name)
        + ' <span style="font-size:11px;font-weight:800;color:' + (r.kind === 'departure' ? '#7C2D12;background:#FFEDD5' : '#1E3A8A;background:#DBEAFE') + ';padding:2px 8px;border-radius:999px;margin-left:4px;">' + (r.kind === 'departure' ? '🕔 End of day' : '🌅 Arrival') + '</span></div>'
        + hchip(r.outcome) + '</div>'
        + '<div style="font-size:12.5px;color:#475569;margin-top:2px;">' + esc(r.room_name || '') + (knownCentres.length > 1 && r.centre_name && r.centre_name !== r.room_name ? ' · ' + esc(r.centre_name) : '')
        + (r.expected ? ' · ' + (r.kind === 'departure' ? 'pickup ' : 'expected ') + esc(r.expected) : '') + '</div>'
        + (steps ? '<div style="font-size:12.5px;color:#475569;margin-top:6px;line-height:1.7;">' + steps + '</div>' : '')
        + ((r.educators.length || r.managers.length || r.parents.length) ? '<div style="margin-top:6px;padding-top:6px;border-top:1px dashed #E5E7EB;">'
          + whoLine('Room educators alerted', r.educators) + whoLine('Directors / admins alerted', r.managers) + whoLine('Parents asked', r.parents) + '</div>' : '')
        + (r.unreached ? '<div style="font-size:12.5px;color:#991B1B;font-weight:700;margin-top:4px;">⚠️ No phone alert reached: ' + esc(r.unreached) + ' (emailed instead)</div>' : '')
        + (r.note ? '<div style="font-size:12.5px;color:#334155;margin-top:4px;">📝 ' + esc(r.note) + '</div>' : '')
        + '</div>';
    };

    root.innerHTML = tabsHtml()
      + '<div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;margin-bottom:12px;">'
      + '<label style="font-size:12px;color:#64748B;display:flex;gap:6px;align-items:center;">From <input type="date" id="sah-from" style="' + FLD + '" value="' + esc(hist.from) + '" max="' + esc(d.today) + '"></label>'
      + '<label style="font-size:12px;color:#64748B;display:flex;gap:6px;align-items:center;">To <input type="date" id="sah-to" style="' + FLD + '" value="' + esc(hist.to) + '" max="' + esc(d.today) + '"></label>'
      + '<select id="sah-kind" style="' + FLD + '">' + [['all', 'Arrival + end of day'], ['arrival', 'Arrival only'], ['departure', 'End of day only']].map(function (k) {
          return '<option value="' + k[0] + '"' + (hist.kind === k[0] ? ' selected' : '') + '>' + k[1] + '</option>'; }).join('') + '</select>'
      + (knownCentres.length > 1 ? '<select id="sah-centre" style="' + FLD + '"><option value="">All centres</option>' + knownCentres.map(function (x) {
          return '<option value="' + x.id + '"' + (String(x.id) === String(hist.centre) ? ' selected' : '') + '>' + esc(x.name) + '</option>'; }).join('') + '</select>' : '')
      + '<input type="search" id="sah-q" placeholder="Child’s name" style="' + FLD + 'width:170px;" value="' + esc(hist.q) + '">'
      + '<label style="font-size:13px;color:#334155;display:flex;gap:6px;align-items:center;cursor:pointer;height:32px;"><input type="checkbox" id="sah-alerted"' + (hist.alerted ? ' checked' : '') + '> Only ones that alerted staff</label>'
      + '</div>'
      + '<div style="font-size:12.5px;color:#64748B;margin-bottom:10px;">' + (d.counts.checks || 0) + ' check' + (d.counts.checks === 1 ? '' : 's') + ', ' + (d.counts.alerted || 0) + ' alerted staff · up to 92 days at a time</div>'
      + (Object.keys(byDate).length ? Object.keys(byDate).map(function (day) {
          return '<h3 style="margin:16px 0 8px;font-size:14px;color:#0F172A;">' + esc(dayLabel(day)) + ' <span style="color:#94A3B8;font-weight:600;">· ' + byDate[day].length + '</span></h3>' + byDate[day].map(card).join('');
        }).join('') : '<div style="padding:18px;color:#64748B;text-align:center;background:#F8FAFC;border-radius:12px;">No Safe Arrival checks in these dates.</div>');

    wireTabs(main, root);
    var reload = function () { root.querySelector('#sah-from') && render(main); };
    root.querySelector('#sah-from').onchange = function () { hist.from = this.value; reload(); };
    root.querySelector('#sah-to').onchange = function () { hist.to = this.value; reload(); };
    root.querySelector('#sah-kind').onchange = function () { hist.kind = this.value; reload(); };
    var cs = root.querySelector('#sah-centre'); if (cs) cs.onchange = function () { hist.centre = this.value; reload(); };
    root.querySelector('#sah-alerted').onchange = function () { hist.alerted = this.checked; reload(); };
    var qt = null, qi = root.querySelector('#sah-q');
    qi.oninput = function () { clearTimeout(qt); var v = qi.value.trim(); qt = setTimeout(function () { hist.q = v; reload(); }, 400); };
  }

  /* A headcount count that ran out without a sign-in (2026-11-03): Safe Arrival is checking on the child again. */
  function expNote(r) {
    if (!r.count_expired) return '';
    return '<span style="background:#F1F5F9;color:#475569;font-size:11.5px;font-weight:800;padding:2px 9px;border-radius:999px;white-space:nowrap;">Headcount expired</span> '
      + '<span style="font-size:12.5px;color:#475569;">Counted at ' + esc(r.count_expired.at || '') + ', expired ' + esc(r.count_expired.until || '') + ' with no sign-in. </span>';
  }

  function chip(state, extra) {
    var s = STATE[state] || STATE.waiting;
    return '<span style="background:' + s[0] + ';color:' + s[1] + ';font-size:11.5px;font-weight:800;padding:2px 9px;border-radius:999px;white-space:nowrap;">' + s[2] + (extra ? ' ' + esc(extra) : '') + '</span>';
  }

  async function render(main) {
    if (timer) { clearTimeout(timer); timer = null; }
    main.setAttribute('data-kt-no-autorefresh', '1');
    main.setAttribute('data-kt-self-live', '1');
    if (!main.querySelector('#sa-root')) main.innerHTML = '<div id="sa-root" style="padding:24px;max-width:1400px;margin:0 auto;"><div style="color:#64748B;">Loading Safe Arrival…</div></div>';
    var root = main.querySelector('#sa-root');
    if (view === 'history') { return renderHistory(main, root); }
    var d;
    try { d = await call('GET', '/safe-arrival/today' + (centreFilter ? '?centre_id=' + encodeURIComponent(centreFilter) : '')); }
    catch (e) { if (root.isConnected) root.innerHTML = '<div style="color:#B91C1C;">' + esc(e.message) + '</div>'; return; }
    if (!root.isConnected || view !== 'today') return;
    knownCentres = d.centres || [];

    var c = d.counts || {};
    var s = d.settings || {};
    var URGENT = { escalated: 1, overdue: 1, on_the_way: 1, dropped_off: 1 };
    var urgent = d.rows.filter(function (r) { return URGENT[r.state]; });
    var rest = d.rows.filter(function (r) { return !URGENT[r.state]; });
    var kpi = function (label, n, bg, fg) {
      return '<div style="flex:1;min-width:130px;background:' + bg + ';padding:12px 14px;border-radius:10px;"><div style="font-size:11px;font-weight:800;color:' + fg + ';letter-spacing:.04em;">' + label + '</div><div style="font-size:22px;font-weight:900;color:' + fg + ';">' + (n || 0) + '</div></div>';
    };

    root.innerHTML = tabsHtml()
      + '<div style="display:flex;justify-content:space-between;align-items:flex-start;gap:12px;flex-wrap:wrap;">'
      + '<div><div style="font-size:13px;color:#64748B;max-width:760px;line-height:1.5;">'
      + (s.enabled
        ? '<b style="color:#166534;">On.</b> Parents are asked ' + s.grace_minutes + ' min after a child\'s expected drop-off time if the child isn\'t signed in and no absence is reported.' + (s.call_parents ? ' No answer after ' + s.call_after_minutes + ' min: they get a phone call (1 absent · 2 on the way · 3 dropped off).' : '') + ' If they don\'t answer within ' + s.escalate_after_minutes + ' min, the room\'s educators are alerted and ' + whoUp(s) + '. Children without an expected time use ' + esc(s.default_time) + '.'
        : '<b style="color:#B91C1C;">Off.</b> Nobody is being checked automatically. This board still shows who hasn\'t arrived.')
      + '</div></div>'
      + '<div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;">'
      + (d.centres && d.centres.length > 1
        ? '<select id="sa-centre" style="height:34px;padding:0 10px;border:1px solid #CBD5E1;border-radius:8px;font-size:13px;"><option value="">All centres</option>'
          + d.centres.map(function (x) { return '<option value="' + x.id + '"' + (String(x.id) === String(centreFilter) ? ' selected' : '') + '>' + esc(x.name) + '</option>'; }).join('') + '</select>' : '')
      + (d.can_configure ? '<button type="button" id="sa-settings" data-kt-iconized="1" style="height:34px;padding:0 14px;border-radius:8px;border:1px solid #CBD5E1;background:#fff;color:#1F6080;font-weight:800;font-size:13px;cursor:pointer;">⚙️ Settings</button>' : '')
      + '<button type="button" id="sa-refresh" data-kt-iconized="1" style="height:34px;padding:0 14px;border-radius:8px;border:1px solid #CBD5E1;background:#fff;color:#334155;font-weight:700;font-size:13px;cursor:pointer;">↻ Refresh</button>'
      + '</div></div>'
      + '<div style="display:flex;gap:10px;margin:16px 0;flex-wrap:wrap;">'
      + kpi('EXPECTED TODAY', c.expected, '#EFF6FF', '#1E40AF')
      + kpi('ARRIVED', c.arrived, '#F0FDF4', '#166534')
      + kpi('ABSENT', c.absent, '#F5F3FF', '#5B21B6')
      + kpi('NOT DUE YET', (c.waiting || 0) + (c.running_late || 0), '#F8FAFC', '#475569')
      + kpi('NEEDS ATTENTION', (c.overdue || 0) + (c.escalated || 0), (c.overdue || c.escalated) ? '#FEF2F2' : '#F8FAFC', (c.overdue || c.escalated) ? '#991B1B' : '#475569')
      + '</div>'
      + '<h3 style="margin:8px 0 10px;font-size:15px;color:#0F172A;">Needs attention</h3>'
      + (urgent.length ? urgent.map(function (r) {
          var red = r.state === 'escalated' || r.state === 'dropped_off';
          return '<div style="border:' + (r.state === 'dropped_off' ? '2px' : '1px') + ' solid ' + (red ? '#FCA5A5' : (r.state === 'on_the_way' ? '#BAE6FD' : '#FCD34D')) + ';background:' + (red ? '#FFF7F7' : (r.state === 'on_the_way' ? '#F5FBFF' : '#FFFDF5')) + ';border-radius:12px;padding:14px 16px;margin-bottom:10px;">'
            + '<div style="display:flex;justify-content:space-between;gap:10px;flex-wrap:wrap;align-items:center;">'
            + '<div><div style="font-weight:800;font-size:15px;color:#0F172A;">' + esc(r.name) + ' ' + chip(r.state) + '</div>'
            + '<div style="font-size:12.5px;color:#475569;margin-top:3px;">' + esc(r.room_name) + (d.centres && d.centres.length > 1 ? ' · ' + esc(r.centre_name) : '')
            + ' · expected ' + esc(r.expected) + (r.expected_is_default ? ' (default)' : '')
            + (r.parents_notified ? ' · parents asked ' + esc(r.parents_notified) : '')
            + (r.called ? ' · called ' + esc(r.called) : '')
            + (r.escalated ? ' · staff alerted ' + esc(r.escalated) : '') + '</div>'
            + (r.parent_response && r.note ? '<div style="font-size:12.5px;font-weight:700;margin-top:4px;color:' + (r.state === 'dropped_off' ? '#991B1B' : '#075985') + ';">📞 ' + esc(r.note) + (r.parent_response_at ? ' (' + esc(r.parent_response_at) + ')' : '') + (r.state === 'dropped_off' ? ' Find the child and sign them in now.' : '') + '</div>' : '')
            + (r.late ? '<div style="font-size:12.5px;font-weight:700;margin-top:4px;color:#3730A3;">⏰ Late drop-off: expected by ' + esc(r.late.by) + (r.late.note ? ' — ' + esc(r.late.note) : '') + (r.late.logged_by ? ' (' + esc(r.late.logged_by) + ')' : '') + '. Still not signed in.</div>' : '')
            + (r.count_expired ? '<div style="margin-top:4px;">' + expNote(r) + '</div>' : '')
            + (r.staff_unreached ? '<div style="font-size:12.5px;color:#991B1B;font-weight:700;margin-top:4px;">⚠️ No phone alert reached: ' + esc(r.staff_unreached) + ' (emailed instead)</div>' : '')
            + '</div>'
            + '<div style="display:flex;gap:6px;flex-wrap:wrap;">'
            + '<button type="button" data-sa-act="absent" data-child="' + r.child_id + '" data-kt-iconized="1" style="height:32px;padding:0 12px;border-radius:8px;border:0;background:#5B21B6;color:#fff;font-weight:800;font-size:12.5px;cursor:pointer;">Record absence</button>'
            + '<button type="button" data-sa-act="parent_contacted" data-child="' + r.child_id + '" data-kt-iconized="1" style="height:32px;padding:0 12px;border-radius:8px;border:1px solid #CBD5E1;background:#fff;color:#1F6080;font-weight:800;font-size:12.5px;cursor:pointer;">Parent contacted</button>'
            + '<button type="button" data-sa-act="other" data-child="' + r.child_id + '" data-kt-iconized="1" style="height:32px;padding:0 12px;border-radius:8px;border:1px solid #CBD5E1;background:#fff;color:#334155;font-weight:700;font-size:12.5px;cursor:pointer;">Other…</button>'
            + (r.state !== 'dropped_off' ? '<button type="button" data-sa-late="' + r.child_id + '" data-kt-iconized="1" style="' + LATE_BTN + 'height:32px;font-size:12.5px;">⏰ Late drop-off</button>' : '')
            + '</div></div>'
            + (r.guardians.length ? '<div style="display:flex;gap:14px;flex-wrap:wrap;margin-top:10px;font-size:13px;">' + r.guardians.map(function (g) {
                return '<span>👤 ' + esc(g.name) + (g.relationship ? ' <span style="color:#94A3B8;">(' + esc(g.relationship) + ')</span>' : '')
                  + (g.phone ? ' · <a href="tel:' + esc(g.phone) + '" style="color:#1F6080;font-weight:800;">📞 ' + esc(g.phone) + '</a>' : ' · <span style="color:#94A3B8;">no phone</span>') + '</span>';
              }).join('') + '</div>' : '<div style="font-size:12.5px;color:#94A3B8;margin-top:8px;">No guardian on file.</div>')
            + '</div>';
        }).join('') : '<div style="padding:14px 16px;background:#F0FDF4;border-radius:12px;color:#166534;font-weight:700;font-size:13.5px;">✓ Every child due so far is accounted for.</div>')
      + departuresHtml(d, s)
      + '<h3 style="margin:22px 0 10px;font-size:15px;color:#0F172A;">Everyone else today</h3>'
      + '<table data-kt-no-kebab style="width:100%;border-collapse:collapse;"><thead><tr>'
      + ['Child', 'Room', 'Expected', 'Status', 'Details'].map(function (h) { return '<th style="text-align:left;padding:8px;border-bottom:1px solid #E5E7EB;font-size:12px;color:#6B7280;">' + h + '</th>'; }).join('')
      + '</tr></thead><tbody>'
      + (rest.length ? rest.map(function (r) {
          var det = r.state === 'arrived' ? 'Signed in ' + esc(r.arrived)
            : r.state === 'absent' ? (r.absent && r.absent.reason ? esc(r.absent.reason) : 'Reported absent') + (r.absent && r.absent.note ? ' — ' + esc(r.absent.note) : '')
            : r.state === 'resolved' ? esc(r.resolution === 'unresolved' ? 'not resolved by end of day' : (r.resolution || '').replace('_', ' ')) + (r.note ? ' — ' + esc(r.note) : '') + (r.resolved ? ' (' + esc(r.resolved) + ')' : '')
            : r.state === 'counted' ? 'Counted at ' + esc(r.counted || '') + (r.counted_until ? ' — holds until ' + esc(r.counted_until) : '') + (r.counted_by ? ' (' + esc(r.counted_by) + ')' : '') + '. No message sent. <b style="color:#854D0E;">Please sign them in.</b>'
            : r.state === 'running_late' && r.late ? 'Expected by <b style="color:#3730A3;">' + esc(r.late.by) + '</b>' + (r.late.note ? ' — ' + esc(r.late.note) : '')
                + lateWho(r.late) + '. Nothing sent before ' + esc(r.late.check_at) + '. '
                + '<button type="button" data-sa-late="' + r.child_id + '" data-kt-iconized="1" style="' + LATE_BTN + '">Change</button>'
            : expNote(r) + 'Check at ' + esc(r.due)
                + (r.state === 'waiting' ? ' <button type="button" data-sa-late="' + r.child_id + '" data-kt-iconized="1" style="' + LATE_BTN + 'margin-left:6px;">⏰ Late drop-off</button>' : '');
          return '<tr><td style="padding:9px 8px;border-bottom:1px solid #F3F4F6;font-size:13px;font-weight:600;">' + esc(r.name) + '</td>'
            + '<td style="padding:9px 8px;border-bottom:1px solid #F3F4F6;font-size:13px;">' + esc(r.room_name) + '</td>'
            + '<td style="padding:9px 8px;border-bottom:1px solid #F3F4F6;font-size:13px;">' + esc(r.expected) + (r.expected_is_default ? ' <span style="color:#94A3B8;">(default)</span>' : '') + '</td>'
            + '<td style="padding:9px 8px;border-bottom:1px solid #F3F4F6;">' + chip(r.state) + '</td>'
            + '<td style="padding:9px 8px;border-bottom:1px solid #F3F4F6;font-size:12.5px;color:#475569;">' + det + '</td></tr>';
        }).join('') : '<tr><td colspan="5" style="padding:18px;color:#64748B;text-align:center;">Nobody else is scheduled today.</td></tr>')
      + '</tbody></table>';

    wireTabs(main, root);
    var sel = root.querySelector('#sa-centre');
    if (sel) sel.onchange = function () { centreFilter = sel.value; render(main); };
    root.querySelector('#sa-refresh').onclick = function () { render(main); };
    var st = root.querySelector('#sa-settings');
    if (st) st.onclick = function () { settingsDialog(main, s); };
    root.querySelectorAll('[data-sd-act]').forEach(function (b) {
      b.onclick = function () {
        var row = (d.departures || []).filter(function (r) { return String(r.child_id) === b.getAttribute('data-child'); })[0];
        departureDialog(main, row, b.getAttribute('data-sd-act'));
      };
    });
    root.querySelectorAll('[data-sa-act]').forEach(function (b) {
      b.onclick = function () {
        var row = d.rows.filter(function (r) { return String(r.child_id) === b.getAttribute('data-child'); })[0];
        resolveDialog(main, row, b.getAttribute('data-sa-act'));
      };
    });
    root.querySelectorAll('[data-sd-late]').forEach(function (b) {
      b.onclick = function () {
        var row = (d.departures || []).filter(function (r) { return String(r.child_id) === b.getAttribute('data-sd-late'); })[0];
        if (row) runningLate(row, { kind: 'departure', onDone: function () { render(main); } });
      };
    });
    root.querySelectorAll('[data-sa-late]').forEach(function (b) {
      b.onclick = function () {
        var row = d.rows.filter(function (r) { return String(r.child_id) === b.getAttribute('data-sa-late'); })[0];
        if (row) runningLate(row, { onDone: function () { render(main); } });
      };
    });

    // Keep it current while it is on screen: a minute is plenty for a 5-minute job.
    timer = setTimeout(function () { if (main.isConnected && root.isConnected && view === 'today' && !document.querySelector('.sa-modal')) render(main); }, 60000);
  }

  function modal(title, inner, okLabel) {
    var m = document.createElement('div');
    m.className = 'sa-modal';
    m.style.cssText = 'position:fixed;inset:0;background:rgba(15,23,42,.5);display:flex;align-items:center;justify-content:center;z-index:10000;padding:16px;';
    m.innerHTML = '<div style="background:#fff;border-radius:14px;max-width:460px;width:100%;padding:22px;max-height:90vh;overflow:auto;">'
      + '<h3 style="margin:0 0 12px;font-size:18px;color:#0F172A;">' + esc(title) + '</h3>' + inner
      + '<div data-err style="color:#B91C1C;font-size:13px;min-height:18px;margin-top:8px;"></div>'
      + '<div style="display:flex;justify-content:flex-end;gap:8px;margin-top:6px;">'
      + '<button type="button" data-cancel style="background:#F1F5F9;color:#334155;border:0;padding:9px 16px;border-radius:8px;font-weight:700;cursor:pointer;">Cancel</button>'
      + '<button type="button" data-ok style="background:#1F6080;color:#fff;border:0;padding:9px 18px;border-radius:8px;font-weight:800;cursor:pointer;">' + esc(okLabel || 'Save') + '</button></div></div>';
    document.body.appendChild(m);
    m.querySelector('[data-cancel]').onclick = function () { m.remove(); };
    return m;
  }
  var FLD = 'width:100%;box-sizing:border-box;padding:8px 10px;border:1px solid #D1D5DB;border-radius:8px;font-size:14px;';
  var LBL = 'display:block;font-size:12px;font-weight:700;color:#374151;margin:10px 0 4px;';
  var SEL = 'width:100%;box-sizing:border-box;height:32px;padding:0 9px;border:1px solid #D1D5DB;border-radius:8px;font-size:13.5px;background:#fff;';

  function resolveDialog(main, row, act) {
    var title = act === 'absent' ? 'Record absence — ' + row.name : act === 'parent_contacted' ? 'Parent contacted — ' + row.name : 'Account for ' + row.name;
    var m = modal(title,
      (act === 'absent'
        ? '<label style="' + LBL + '">Reason</label><select data-reason style="' + FLD + '">' + REASONS.map(function (x) { return '<option value="' + x[0] + '">' + x[1] + '</option>'; }).join('') + '</select>'
          + '<div style="font-size:12px;color:#64748B;margin-top:6px;">Recorded as today\'s absence, the same as a parent\'s "Not attending today", so the roster and reminders see it.</div>'
        : '<div style="font-size:12.5px;color:#475569;line-height:1.5;">Say what you found out — it is kept with today\'s attendance record.</div>')
      + '<label style="' + LBL + '">' + (act === 'absent' ? 'Note (optional)' : 'What happened') + '</label>'
      + '<textarea data-note rows="3" maxlength="1000" style="' + FLD + 'resize:vertical;" placeholder="' + (act === 'parent_contacted' ? 'e.g. Spoke to mum at 9:40 — running late, arriving 10:15' : '') + '"></textarea>',
      act === 'absent' ? 'Record absence' : 'Save');
    m.querySelector('[data-ok]').onclick = async function () {
      var body = { resolution: act, note: m.querySelector('[data-note]').value.trim() || null };
      var rs = m.querySelector('[data-reason]'); if (rs) body.reason = rs.value;
      if (act !== 'absent' && !body.note) { m.querySelector('[data-err]').textContent = 'Add a short note saying what happened.'; return; }
      this.disabled = true;
      try { await call('POST', '/safe-arrival/' + row.child_id + '/resolve', body); m.remove(); render(main); }
      catch (e) { this.disabled = false; m.querySelector('[data-err]').textContent = e.message; }
    };
  }

  function departureDialog(main, row, act) {
    var now = new Date();
    var hhmm = String(now.getHours()).padStart(2, '0') + ':' + String(now.getMinutes()).padStart(2, '0');
    var first = row.name.split(' ')[0];
    var title = act === 'picked_up' ? first + ' was picked up' : act === 'still_here' ? first + ' is still here' : 'Account for ' + row.name;
    var m = modal(title,
      (act === 'picked_up'
        ? '<label style="' + LBL + '">Picked up at</label><input type="time" data-time style="' + FLD + '" value="' + (row.state === 'unconfirmed' ? '' : hhmm) + '">'
          + '<div style="font-size:12px;color:#64748B;margin-top:6px;line-height:1.45;">' + (row.state === 'unconfirmed'
            ? 'Replaces the automatic sign-off time on today\'s attendance (it stays marked as corrected).'
            : first + ' is signed out at this time on today\'s attendance.') + '</div>'
        : act === 'still_here'
          ? '<div style="font-size:12.5px;color:#475569;line-height:1.5;">' + esc(first) + ' stays signed in. Sign them out when they are picked up — and log a late pickup if it applies.</div>'
          : '<div style="font-size:12.5px;color:#475569;line-height:1.5;">Say what you found out — it is kept with today\'s attendance record.</div>')
      + '<label style="' + LBL + '">' + (act === 'other' ? 'What happened' : 'Note (optional)') + '</label>'
      + '<textarea data-note rows="3" maxlength="1000" style="' + FLD + 'resize:vertical;" placeholder="' + (act === 'picked_up' ? 'e.g. Picked up by dad, sign-out was missed' : act === 'still_here' ? 'e.g. Mum running late, arriving 6:15' : '') + '"></textarea>',
      act === 'picked_up' ? 'Sign out' : 'Save');
    m.querySelector('[data-ok]').onclick = async function () {
      var body = { resolution: act, note: m.querySelector('[data-note]').value.trim() || null };
      var t = m.querySelector('[data-time]');
      if (t) { if (!t.value) { m.querySelector('[data-err]').textContent = 'Enter the time ' + first + ' was picked up.'; return; } body.time = t.value; }
      if (act === 'other' && !body.note) { m.querySelector('[data-err]').textContent = 'Add a short note saying what happened.'; return; }
      var b = this; b.disabled = true;
      try { await call('POST', '/safe-arrival/departure/' + row.child_id + '/resolve', body); m.remove(); render(main); }
      catch (e) { b.disabled = false; m.querySelector('[data-err]').textContent = e.message; }
    };
  }

  /* Settings, including the phone call's wording and options (2026-10-13, Anthony: "make this
     more configurable"). The call section reads GET /safe-arrival/settings for the agency's
     words and the shipped defaults; "Play test call" rings the admin's own phone with the
     words as they are in the boxes now, before anything is saved. */
  var CALL_TEXT = [
    ['intro', 'Opening', 'Said first. Who is calling and why.'],
    ['prompt_absent', 'Press 1 — absent today', 'Always offered.'],
    ['prompt_late', 'Press 2 — on the way', ''],
    ['prompt_dropped', 'Press 3 — already dropped off', ''],
    ['reply_absent', 'After 1 is pressed', ''],
    ['reply_late', 'After 2 is pressed', ''],
    ['reply_dropped', 'After 3 is pressed', ''],
    ['reply_no_answer', 'No key pressed', 'Then the next guardian is called.'],
    ['reply_already', 'Child already accounted for', 'Another guardian or staff answered first.'],
    ['reply_invalid', 'Wrong key', 'Followed by the keys to press.'],
  ];
  async function settingsDialog(main, s) {
    var full = { settings: s, call_defaults: {} };
    try { full = await call('GET', '/safe-arrival/settings'); } catch (e) {}
    var cs = (full.settings && full.settings.call) || {};
    var defs = full.call_defaults || {};
    var TA = FLD + 'height:auto;min-height:52px;resize:vertical;font-family:inherit;line-height:1.4;padding:7px 9px;';
    var callBox = '<details data-callbox style="margin-top:14px;border:1px solid #E2E8F0;border-radius:10px;padding:10px 12px;"' + (s.call_parents ? ' open' : '') + '>'
      + '<summary style="cursor:pointer;font-weight:800;font-size:14px;color:#0F172A;">📞 Phone call — wording and options</summary>'
      + '<div style="font-size:12px;color:#64748B;margin:8px 0 4px;line-height:1.45;">Use <b>{child}</b>, <b>{child_full}</b>, <b>{centre}</b>, <b>{room}</b> and <b>{time}</b> — they are filled in on each call. Leave a box empty to use the standard wording. The keys always mean the same thing: 1 absent, 2 on the way, 3 dropped off.</div>'
      + '<div style="display:grid;grid-template-columns:1fr 1fr;gap:8px 12px;margin-top:6px;">'
      + '<label style="display:flex;align-items:center;gap:8px;font-size:13px;font-weight:700;color:#0F172A;"><input type="checkbox" data-c="offer_late" ' + (cs.offer_late !== false ? 'checked' : '') + '> Offer 2 (on the way)</label>'
      + '<label style="display:flex;align-items:center;gap:8px;font-size:13px;font-weight:700;color:#0F172A;"><input type="checkbox" data-c="offer_dropped" ' + (cs.offer_dropped !== false ? 'checked' : '') + '> Offer 3 (dropped off)</label>'
      + '<div><label style="' + LBL + '">Who is called</label><select data-c="who" style="' + FLD + 'background:#fff;">'
      +   '<option value="all"' + (cs.who !== 'primary' ? ' selected' : '') + '>Each guardian in turn, primary first</option>'
      +   '<option value="primary"' + (cs.who === 'primary' ? ' selected' : '') + '>Primary guardian only</option></select></div>'
      + '<div><label style="' + LBL + '">Ask the question</label><select data-c="tries" style="' + FLD + 'background:#fff;">'
      +   [1, 2, 3].map(function (n) { return '<option value="' + n + '"' + (Number(cs.tries || 2) === n ? ' selected' : '') + '>' + (n === 1 ? 'Once' : n + ' times') + ' if no key is pressed</option>'; }).join('') + '</select></div>'
      + '<div><label style="' + LBL + '">Wait for a key (seconds)</label><input type="number" data-c="wait_secs" min="5" max="30" style="' + FLD + '" value="' + esc(cs.wait_secs || 10) + '"></div>'
      // Time between guardians (2026-10-23): the next one is rung this long after an unanswered call ends.
      + '<div><label style="' + LBL + '">Before calling the next guardian</label><select data-c="gap_secs" style="' + FLD + 'background:#fff;">'
      +   [[0, 'Call right away'], [30, 'Wait 30 seconds'], [60, 'Wait 1 minute'], [120, 'Wait 2 minutes'], [180, 'Wait 3 minutes'], [300, 'Wait 5 minutes']].map(function (o) { var cur = cs.gap_secs === undefined || cs.gap_secs === null ? 60 : Number(cs.gap_secs); return '<option value="' + o[0] + '"' + (cur === o[0] ? ' selected' : '') + '>' + o[1] + '</option>'; }).join('') + '</select></div>'
      + '</div>'
      + CALL_TEXT.map(function (t) {
          var v = cs[t[0]] && cs[t[0]] !== defs[t[0]] ? cs[t[0]] : '';
          return '<label style="' + LBL + '">' + esc(t[1]) + (t[2] ? ' <span style="font-weight:500;color:#94A3B8;">· ' + esc(t[2]) + '</span>' : '') + '</label>'
            + '<textarea data-t="' + t[0] + '" maxlength="400" rows="2" style="' + TA + '" placeholder="' + esc(defs[t[0]] || '') + '">' + esc(v) + '</textarea>';
        }).join('')
      + '<div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;margin-top:10px;">'
      + '<button type="button" data-testcall style="height:30px;padding:0 12px;border-radius:8px;border:1px solid #1F6080;background:#fff;color:#1F6080;font-weight:800;font-size:12.5px;cursor:pointer;">▶ Play test call to my phone</button>'
      + '<button type="button" data-resetcall style="height:30px;padding:0 12px;border-radius:8px;border:1px solid #CBD5E1;background:#fff;color:#475569;font-weight:700;font-size:12.5px;cursor:pointer;">Reset wording to standard</button>'
      + '<span data-testmsg style="font-size:12px;color:#475569;"></span></div>'
      + '<div style="font-size:11.5px;color:#94A3B8;margin-top:6px;">The test rings the mobile number on your own profile, uses "Test Child", and records nothing. The voice is set in Carrier settings → Voice calls.</div>'
      + '</details>';

    var m = modal('Safe Arrival settings',
      '<label style="display:flex;align-items:center;gap:10px;font-size:14px;font-weight:700;color:#0F172A;cursor:pointer;">'
      + '<input type="checkbox" data-enabled ' + (s.enabled ? 'checked' : '') + ' style="width:18px;height:18px;"> Check that every scheduled child arrives</label>'
      + '<div style="font-size:12px;color:#64748B;margin:4px 0 0 28px;line-height:1.45;">When on, parents are messaged automatically — through the channels each parent chose — when their child is late and no absence was reported.</div>'
      + '<label style="' + LBL + '">⏱ Arrival threshold — start escalating this many minutes after the expected drop-off time (parents are asked first)</label>'
      + '<input type="number" data-grace min="0" max="180" style="' + FLD + '" value="' + esc(s.grace_minutes) + '">'
      + '<label style="' + LBL + '">Alert the room\'s educators and the people chosen below if parents haven\'t answered after (minutes)</label>'
      + '<input type="number" data-esc min="5" max="240" style="' + FLD + '" value="' + esc(s.escalate_after_minutes) + '">'
      + '<label style="display:flex;align-items:center;gap:10px;font-size:14px;font-weight:700;color:#0F172A;cursor:pointer;margin-top:14px;">'
      + '<input type="checkbox" data-call ' + (s.call_parents ? 'checked' : '') + ' style="width:18px;height:18px;"> Phone the parents if they don\'t answer</label>'
      + '<div style="font-size:12px;color:#64748B;margin:4px 0 0 28px;line-height:1.45;">An automated call names the child and asks the parent to press a key. The answer is recorded on the child, absences go on the calendar, and the room\'s educators, the director and admins are told. Parents who opted out of calls are not rung.</div>'
      + '<label style="' + LBL + '">Call this many minutes after the parents were asked</label>'
      + '<input type="number" data-callafter min="5" max="120" style="' + FLD + '" value="' + esc(s.call_after_minutes || 10) + '">'
      + callBox
      + '<label style="' + LBL + '">Expected time for a child without one</label>'
      + '<input type="time" data-def style="' + FLD + '" value="' + esc(s.default_time) + '">'
      + '<div style="font-size:12px;color:#64748B;margin-top:6px;line-height:1.45;">Set each child\'s own drop-off time on their record (Usual times) for the most accurate checks. Only days the child is scheduled and the centre is open are checked.</div>'
      /* New children start tomorrow (2026-10-28). */
      + '<label style="display:flex;align-items:flex-start;gap:10px;font-size:13.5px;font-weight:700;color:#0F172A;cursor:pointer;margin:12px 0 0;"><input type="checkbox" data-nextday ' + (s.start_next_day !== false ? 'checked' : '') + ' style="width:18px;height:18px;margin-top:1px;">'
      + '<span>Start checking a new child the day after they are added<span style="display:block;font-weight:400;font-size:12px;color:#64748B;margin-top:2px;">A child added to KiddieTrac today isn\'t checked until tomorrow, so a family who is still being set up doesn\'t get a "where is your child?" text on day one.</span></span></label>'
      /* Headcounts count (2026-11-03). */
      + '<label style="' + LBL + '">Headcounts</label><select data-hcm style="' + SEL + '">'
      +   '<option value="present"' + (s.headcount_mode !== 'off' ? ' selected' : '') + '>A child ticked in a headcount counts as arrived</option>'
      +   '<option value="off"' + (s.headcount_mode === 'off' ? ' selected' : '') + '>Ignore headcounts. Only a sign-in counts</option></select>'
      + '<label style="' + LBL + '">Count holds for</label><select data-hch style="' + SEL + '">'
      +   [[60, '1 hour'], [120, '2 hours'], [180, '3 hours'], [0, 'Rest of the day']].map(function (o) {
            var cur = s.headcount_hold_minutes === undefined || s.headcount_hold_minutes === null ? 120 : Number(s.headcount_hold_minutes);
            return '<option value="' + o[0] + '"' + (cur === o[0] ? ' selected' : '') + '>' + o[1] + '</option>'; }).join('') + '</select>'
      + '<div style="font-size:12px;color:#64748B;margin-top:6px;line-height:1.45;">Each room headcount lists the children who aren\'t signed in yet. If an educator ticks one, the parents aren\'t messaged, there\'s no call and no staff alert, and the board asks staff to sign the child in. The count holds for the time chosen from that headcount (a newer tick restarts it). If it runs out without a sign-in, or a later headcount leaves the child unticked, checking starts again.</div>'
      /* Who is alerted, morning and evening (2026-10-22). */
      + '<div style="margin-top:16px;border-top:1px solid #E2E8F0;padding-top:12px;">'
      + '<div style="font-weight:800;font-size:14px;color:#0F172A;">Who is alerted</div>'
      + '<div style="font-size:12px;color:#64748B;margin:3px 0 6px;line-height:1.45;">The room\'s educators are always alerted. Also alert:</div>'
      + '<label style="display:flex;align-items:center;gap:10px;font-size:13.5px;font-weight:700;color:#0F172A;cursor:pointer;margin:4px 0;"><input type="checkbox" data-nd ' + (s.notify_directors !== false ? 'checked' : '') + ' style="width:18px;height:18px;"> Directors of the child\'s centre</label>'
      + '<label style="display:flex;align-items:center;gap:10px;font-size:13.5px;font-weight:700;color:#0F172A;cursor:pointer;margin:4px 0;"><input type="checkbox" data-na ' + (s.notify_admins ? 'checked' : '') + ' style="width:18px;height:18px;"> All agency admins</label>'
      /* When and how directors/admins hear (2026-10-30). */
      + '<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:0 12px;">'
      + '<div><label style="' + LBL + '">When directors/admins are alerted</label><select data-ut style="' + SEL + '">'
      +   '<option value="with_educators"' + (s.up_timing !== 'after' ? ' selected' : '') + '>At the same time as the educators</option>'
      +   '<option value="after"' + (s.up_timing === 'after' ? ' selected' : '') + '>Only if the child still isn\'t here after…</option></select></div>'
      + '<div data-uawrap><label style="' + LBL + '">…this many minutes after the educators</label><input type="number" data-ua min="5" max="120" style="' + SEL + '" value="' + esc(s.up_after_minutes || 10) + '"></div>'
      + '<div><label style="' + LBL + '">Email directors/admins</label><select data-ue style="' + SEL + '">'
      +   [['fallback', 'Only if no push notification reaches them'], ['always', 'Always, as well as the push'], ['off', 'Never']].map(function (o) { return '<option value="' + o[0] + '"' + ((s.up_email || 'fallback') === o[0] ? ' selected' : '') + '>' + o[1] + '</option>'; }).join('')
      + '</select></div>'
      + '<div style="display:flex;align-items:flex-end;"><label style="display:flex;align-items:center;gap:10px;font-size:13.5px;font-weight:700;color:#0F172A;cursor:pointer;min-height:32px;margin-top:10px;"><input type="checkbox" data-us ' + (s.up_sms ? 'checked' : '') + '> Also text them (SMS)</label></div>'
      + '</div>'
      + '<div style="font-size:12px;color:#64748B;margin-top:6px;line-height:1.45;">Everyone gets the alert in the app and as a push notification. Texts go to the mobile number on each person\'s profile, and your agency\'s SMS setting applies. The end-of-day check uses the same people and channels, on its own timing below.</div>'
      + '</div>'
      /* End of day — Safe Departure. */
      + '<div style="margin-top:16px;border-top:1px solid #E2E8F0;padding-top:12px;">'
      + '<label style="display:flex;align-items:center;gap:10px;font-size:14px;font-weight:700;color:#0F172A;cursor:pointer;">'
      + '<input type="checkbox" data-den ' + (s.departure_enabled ? 'checked' : '') + ' style="width:18px;height:18px;"> End of day: check on every child still signed in</label>'
      + '<div style="font-size:12px;color:#64748B;margin:4px 0 0 28px;line-height:1.45;">Past the child\'s usual pickup time (or the centre\'s closing time): the room\'s educators are alerted first, then the people chosen above, then the parents. The automatic sign-off still closes the day, but marks the pickup as not confirmed and alerts again.</div>'
      + '<div style="display:grid;grid-template-columns:1fr 1fr;gap:0 12px;">'
      + '<div><label style="' + LBL + '">⏱ Departure threshold — start escalating this many minutes after pickup/closing (educators first)</label><input type="number" data-dgrace min="0" max="240" style="' + FLD + '" value="' + esc(s.departure_grace_minutes != null ? s.departure_grace_minutes : 30) + '"></div>'
      + '<div><label style="' + LBL + '">Then alert directors/admins after (minutes)</label><input type="number" data-desc min="5" max="240" style="' + FLD + '" value="' + esc(s.departure_escalate_after_minutes || 15) + '"></div>'
      + '<div><label style="' + LBL + '">Then ask the parents after (minutes)</label><input type="number" data-dpar min="5" max="240" style="' + FLD + '" value="' + esc(s.departure_parents_after_minutes || 15) + '"></div>'
      + '<div><label style="' + LBL + '">Closing time for a centre without one</label><input type="time" data-ddef style="' + FLD + '" value="' + esc(s.departure_default_time || '18:00') + '"></div>'
      + '</div>'
      + '<div style="font-size:12px;color:#64748B;margin-top:6px;line-height:1.45;">A child\'s own pickup time (Usual times on their record) is used when set; otherwise the centre\'s closing time.</div>'
      + '</div>'
      /* Running late from a parent (2026-11-06): who gets the heads-up. */
      + '<div style="margin-top:16px;border-top:1px solid #E2E8F0;padding-top:12px;">'
      + '<div style="font-weight:800;font-size:14px;color:#0F172A;">⏰ When a parent says they\'re running late</div>'
      + '<div style="font-size:12px;color:#64748B;margin:3px 0 6px;line-height:1.45;">Parents can say their child will be dropped off or picked up late. Nobody is asked or alerted before that time. Who is told:</div>'
      + '<label style="display:flex;align-items:center;gap:10px;font-size:13.5px;font-weight:700;color:#0F172A;cursor:pointer;margin:4px 0;"><input type="checkbox" data-lne ' + (s.late_notify_educators !== false ? 'checked' : '') + ' style="width:18px;height:18px;"> The child\'s room educators</label>'
      + '<label style="display:flex;align-items:center;gap:10px;font-size:13.5px;font-weight:700;color:#0F172A;cursor:pointer;margin:4px 0;"><input type="checkbox" data-lnd ' + (s.late_notify_directors !== false ? 'checked' : '') + ' style="width:18px;height:18px;"> Directors of the child\'s centre</label>'
      + '<label style="display:flex;align-items:center;gap:10px;font-size:13.5px;font-weight:700;color:#0F172A;cursor:pointer;margin:4px 0;"><input type="checkbox" data-lna ' + (s.late_notify_admins !== false ? 'checked' : '') + ' style="width:18px;height:18px;"> All agency admins</label>'
      + '</div>');
    var box = m.firstElementChild;
    if (box) box.style.maxWidth = '640px';
    var syncUp = function () { var w = m.querySelector('[data-uawrap]'); if (w) w.style.display = m.querySelector('[data-ut]').value === 'after' ? '' : 'none'; };
    m.querySelector('[data-ut]').onchange = syncUp;
    syncUp();

    function readCall() {
      var c = {
        offer_late: m.querySelector('[data-c="offer_late"]').checked,
        offer_dropped: m.querySelector('[data-c="offer_dropped"]').checked,
        who: m.querySelector('[data-c="who"]').value,
        tries: parseInt(m.querySelector('[data-c="tries"]').value, 10) || 2,
        wait_secs: Math.max(5, Math.min(30, parseInt(m.querySelector('[data-c="wait_secs"]').value, 10) || 10)),
        gap_secs: Math.max(0, Math.min(300, parseInt(m.querySelector('[data-c="gap_secs"]').value, 10) || 0)),
      };
      m.querySelectorAll('[data-t]').forEach(function (ta) { c[ta.getAttribute('data-t')] = ta.value.trim(); });
      return c;
    }
    // Hide the wording for a choice that is not offered.
    function syncOffers() {
      [['offer_late', ['prompt_late', 'reply_late']], ['offer_dropped', ['prompt_dropped', 'reply_dropped']]].forEach(function (p) {
        var on = m.querySelector('[data-c="' + p[0] + '"]').checked;
        p[1].forEach(function (k) {
          var ta = m.querySelector('[data-t="' + k + '"]');
          if (ta) { ta.style.display = on ? '' : 'none'; if (ta.previousElementSibling) ta.previousElementSibling.style.display = on ? '' : 'none'; }
        });
      });
    }
    m.querySelectorAll('[data-c^="offer_"]').forEach(function (cb) { cb.onchange = syncOffers; });
    syncOffers();
    m.querySelector('[data-resetcall]').onclick = function () { m.querySelectorAll('[data-t]').forEach(function (ta) { ta.value = ''; }); };
    m.querySelector('[data-testcall]').onclick = async function () {
      var b = this, msg = m.querySelector('[data-testmsg]');
      b.disabled = true; msg.style.color = '#475569'; msg.textContent = 'Placing the call…';
      try { var r = await call('POST', '/safe-arrival/test-call', { call: readCall() }); msg.style.color = '#166534'; msg.textContent = r.message || 'Calling now.'; }
      catch (e) { msg.style.color = '#B91C1C'; msg.textContent = e.message; }
      setTimeout(function () { b.disabled = false; }, 8000);
    };
    m.querySelector('[data-ok]').onclick = async function () {
      var body = {
        enabled: m.querySelector('[data-enabled]').checked,
        grace_minutes: parseInt(m.querySelector('[data-grace]').value, 10) || 0,
        escalate_after_minutes: parseInt(m.querySelector('[data-esc]').value, 10) || 20,
        default_time: m.querySelector('[data-def]').value || '09:00',
        call_parents: m.querySelector('[data-call]').checked,
        call_after_minutes: parseInt(m.querySelector('[data-callafter]').value, 10) || 10,
        start_next_day: m.querySelector('[data-nextday]').checked,
        headcount_mode: m.querySelector('[data-hcm]').value === 'off' ? 'off' : 'present',
        headcount_hold_minutes: parseInt(m.querySelector('[data-hch]').value, 10) || 0,
        notify_directors: m.querySelector('[data-nd]').checked,
        notify_admins: m.querySelector('[data-na]').checked,
        up_timing: m.querySelector('[data-ut]').value === 'after' ? 'after' : 'with_educators',
        up_after_minutes: Math.max(5, Math.min(120, parseInt(m.querySelector('[data-ua]').value, 10) || 10)),
        up_email: m.querySelector('[data-ue]').value || 'fallback',
        up_sms: m.querySelector('[data-us]').checked,
        departure_enabled: m.querySelector('[data-den]').checked,
        departure_grace_minutes: Math.max(0, parseInt(m.querySelector('[data-dgrace]').value, 10) || 0),
        departure_escalate_after_minutes: Math.max(5, parseInt(m.querySelector('[data-desc]').value, 10) || 15),
        departure_parents_after_minutes: Math.max(5, parseInt(m.querySelector('[data-dpar]').value, 10) || 15),
        departure_default_time: m.querySelector('[data-ddef]').value || '18:00',
        late_notify_educators: m.querySelector('[data-lne]').checked,
        late_notify_directors: m.querySelector('[data-lnd]').checked,
        late_notify_admins: m.querySelector('[data-lna]').checked,
        call: readCall(),
      };
      this.disabled = true;
      try { await call('PUT', '/safe-arrival/settings', body); m.remove(); render(main); }
      catch (e) { this.disabled = false; m.querySelector('[data-err]').textContent = e.message; }
    };
  }

  /* ── RUNNING LATE (2026-11-06) ────────────────────────────────────────
     The family said the child will be in later today (kind arrival), or picked up later (kind departure, the same
     day). Staff log the time (Today's quick actions on a child, or the board); Safe Arrival / the end-of-day check
     waits until then, plus the grace, before asking the family or alerting anyone. Nothing by then: it checks as usual.
     Parents log the same notice from their own Today screen (screen-parent.js).
     child = { child_id, name, first_name?, late?: { by, by_hm, note } }; opts.kind, opts.onDone runs after a save. */
  function runningLate(child, opts) {
    opts = opts || {};
    var Shell = window.KT && KT.Shell;
    if (!Shell || !Shell.Modal) return;
    var pickup = opts.kind === 'departure';
    var kind = pickup ? 'departure' : 'arrival';
    var first = child.first_name || String(child.name || 'This child').split(' ')[0];
    var had = (pickup ? (child.late_pickup || child.late) : (child.late || child.running_late)) || null;
    var dflt = had && had.by_hm ? had.by_hm : (function () {
      var t = new Date(Date.now() + 30 * 60000), m = Math.ceil(t.getMinutes() / 5) * 5;
      t.setMinutes(m, 0, 0);
      return String(t.getHours()).padStart(2, '0') + ':' + String(t.getMinutes()).padStart(2, '0');
    })();
    var body = document.createElement('div');
    body.innerHTML = '<div style="font-size:13.5px;color:#475569;line-height:1.55;">' + (pickup
        ? esc(first) + ' will be picked up later today. The end-of-day check waits until this time, plus its grace period, '
          + 'before alerting anyone or asking the family. If ' + esc(first) + ' is still signed in by then, it checks as usual.'
        : esc(first) + ' will be dropped off later today. Safe Arrival waits until this time, plus its grace period, before asking the family or alerting anyone. '
          + 'If ' + esc(first) + ' still isn\'t signed in by then, it checks as usual.') + '</div>'
      + '<label style="' + LBL + '" for="sa-late-time">' + (pickup ? 'Picked up by' : 'Expected by') + '</label>'
      + '<input type="time" id="sa-late-time" step="300" value="' + esc(dflt) + '" style="' + SEL + 'width:150px;">'
      + '<label style="' + LBL + '" for="sa-late-note">Note (optional)</label>'
      + '<input type="text" id="sa-late-note" maxlength="500" style="' + SEL + '" placeholder="' + (pickup ? 'e.g. Dad stuck in traffic' : 'e.g. Mum called, doctor\'s appointment') + '" value="' + esc(had && had.note ? had.note : '') + '">'
      + '<div data-err role="alert" style="color:#B91C1C;font-size:13px;font-weight:700;min-height:18px;margin-top:8px;"></div>';
    var err = body.querySelector('[data-err]');
    var done = function (msg) {
      if (window.KT && KT.toast) KT.toast('⏰', pickup ? 'Late pickup' : 'Late drop-off', msg);
      if (opts.onDone) { try { opts.onDone(); } catch (e) {} }
    };
    var actions = [{ label: 'Cancel' }];
    if (had) {
      actions.push({ label: 'Take it back', busyLabel: 'Saving…', handler: async function () {
        try { await call('DELETE', '/safe-arrival/' + child.child_id + '/late?kind=' + kind); }
        catch (e) { err.textContent = e.message; return false; }
        done(first + ': ' + (pickup ? 'the end-of-day check' : 'Safe Arrival') + ' runs at the usual time again.');
      } });
    }
    actions.push({ label: had ? 'Save new time' : 'Save', primary: true, busyLabel: 'Saving…', handler: async function () {
      var t = body.querySelector('#sa-late-time').value;
      if (!/^\d{2}:\d{2}$/.test(t)) { err.textContent = 'Enter the time ' + first + (pickup ? ' will be picked up by.' : ' is expected by.'); return false; }
      var note = body.querySelector('#sa-late-note').value.trim();
      try { await call('POST', '/safe-arrival/' + child.child_id + '/late', { time: t, note: note || null, kind: kind }); }
      catch (e) { err.textContent = e.message; return false; }
      var hh = +t.slice(0, 2), lbl = ((hh % 12) || 12) + ':' + t.slice(3) + (hh < 12 ? ' AM' : ' PM');
      done(first + (pickup ? ' will be picked up by ' : ' is expected by ') + lbl + '. Nobody is ' + (pickup ? 'alerted' : 'asked or alerted') + ' before then.');
    } });
    var h = Shell.Modal.open({ title: (pickup ? '⏰ Late pickup: ' : '⏰ Late drop-off: ') + (child.name || first), body: body, actions: actions });
    // A new entry comes with a suggested time, so Save is ready at once (kt-dirty-save would hold it as "no changes").
    var save = !had && h && h.backdrop && h.backdrop.querySelector('.modal-footer .btn-primary');
    if (save) save.setAttribute('data-kt-always-save', '1');
    setTimeout(function () { var i = body.querySelector('#sa-late-time'); if (i && !(window.matchMedia && matchMedia('(pointer: coarse)').matches)) i.focus(); }, 30);
  }

  window.KT = window.KT || {};
  window.KT.SafeArrival = { render: render };
  window.KT.runningLate = runningLate;
  (function reg(n) {
    var S = window.KT && window.KT.Shell;
    if (!S || !S.registerScreen) { if ((n || 0) < 100) setTimeout(function () { reg((n || 0) + 1); }, 100); return; }
    ['agency_admin', 'centre_director', 'educator', 'platform_admin'].forEach(function (r) { S.registerScreen(r + ':safe-arrival', render); });
  })(0);
})(window);
