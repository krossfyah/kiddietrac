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
  };
  var REASONS = [['sick', 'Sick'], ['appointment', 'Appointment'], ['holiday', 'Holiday'], ['family', 'Family'], ['other', 'Other']];
  var centreFilter = '';
  var timer = null;

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
    var d;
    try { d = await call('GET', '/safe-arrival/today' + (centreFilter ? '?centre_id=' + encodeURIComponent(centreFilter) : '')); }
    catch (e) { if (root.isConnected) root.innerHTML = '<div style="color:#B91C1C;">' + esc(e.message) + '</div>'; return; }
    if (!root.isConnected) return;

    var c = d.counts || {};
    var s = d.settings || {};
    var urgent = d.rows.filter(function (r) { return r.state === 'escalated' || r.state === 'overdue'; });
    var rest = d.rows.filter(function (r) { return r.state !== 'escalated' && r.state !== 'overdue'; });
    var kpi = function (label, n, bg, fg) {
      return '<div style="flex:1;min-width:130px;background:' + bg + ';padding:12px 14px;border-radius:10px;"><div style="font-size:11px;font-weight:800;color:' + fg + ';letter-spacing:.04em;">' + label + '</div><div style="font-size:22px;font-weight:900;color:' + fg + ';">' + (n || 0) + '</div></div>';
    };

    root.innerHTML =
      '<div style="display:flex;justify-content:space-between;align-items:flex-start;gap:12px;flex-wrap:wrap;">'
      + '<div><div style="font-size:13px;color:#64748B;max-width:760px;line-height:1.5;">'
      + (s.enabled
        ? '<b style="color:#166534;">On.</b> Parents are asked ' + s.grace_minutes + ' min after a child\'s expected drop-off time if the child isn\'t signed in and no absence is reported. If they don\'t answer within ' + s.escalate_after_minutes + ' min, the room\'s educators and the director are alerted. Children without an expected time use ' + esc(s.default_time) + '.'
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
      + kpi('NOT DUE YET', c.waiting, '#F8FAFC', '#475569')
      + kpi('NEEDS ATTENTION', (c.overdue || 0) + (c.escalated || 0), (c.overdue || c.escalated) ? '#FEF2F2' : '#F8FAFC', (c.overdue || c.escalated) ? '#991B1B' : '#475569')
      + '</div>'
      + '<h3 style="margin:8px 0 10px;font-size:15px;color:#0F172A;">Needs attention</h3>'
      + (urgent.length ? urgent.map(function (r) {
          return '<div style="border:1px solid ' + (r.state === 'escalated' ? '#FCA5A5' : '#FCD34D') + ';background:' + (r.state === 'escalated' ? '#FFF7F7' : '#FFFDF5') + ';border-radius:12px;padding:14px 16px;margin-bottom:10px;">'
            + '<div style="display:flex;justify-content:space-between;gap:10px;flex-wrap:wrap;align-items:center;">'
            + '<div><div style="font-weight:800;font-size:15px;color:#0F172A;">' + esc(r.name) + ' ' + chip(r.state) + '</div>'
            + '<div style="font-size:12.5px;color:#475569;margin-top:3px;">' + esc(r.room_name) + (d.centres && d.centres.length > 1 ? ' · ' + esc(r.centre_name) : '')
            + ' · expected ' + esc(r.expected) + (r.expected_is_default ? ' (default)' : '')
            + (r.parents_notified ? ' · parents asked ' + esc(r.parents_notified) : '')
            + (r.escalated ? ' · staff alerted ' + esc(r.escalated) : '') + '</div>'
            + (r.staff_unreached ? '<div style="font-size:12.5px;color:#991B1B;font-weight:700;margin-top:4px;">⚠️ No phone alert reached: ' + esc(r.staff_unreached) + ' (emailed instead)</div>' : '')
            + '</div>'
            + '<div style="display:flex;gap:6px;flex-wrap:wrap;">'
            + '<button type="button" data-sa-act="absent" data-child="' + r.child_id + '" data-kt-iconized="1" style="height:32px;padding:0 12px;border-radius:8px;border:0;background:#5B21B6;color:#fff;font-weight:800;font-size:12.5px;cursor:pointer;">Record absence</button>'
            + '<button type="button" data-sa-act="parent_contacted" data-child="' + r.child_id + '" data-kt-iconized="1" style="height:32px;padding:0 12px;border-radius:8px;border:1px solid #CBD5E1;background:#fff;color:#1F6080;font-weight:800;font-size:12.5px;cursor:pointer;">Parent contacted</button>'
            + '<button type="button" data-sa-act="other" data-child="' + r.child_id + '" data-kt-iconized="1" style="height:32px;padding:0 12px;border-radius:8px;border:1px solid #CBD5E1;background:#fff;color:#334155;font-weight:700;font-size:12.5px;cursor:pointer;">Other…</button>'
            + '</div></div>'
            + (r.guardians.length ? '<div style="display:flex;gap:14px;flex-wrap:wrap;margin-top:10px;font-size:13px;">' + r.guardians.map(function (g) {
                return '<span>👤 ' + esc(g.name) + (g.relationship ? ' <span style="color:#94A3B8;">(' + esc(g.relationship) + ')</span>' : '')
                  + (g.phone ? ' · <a href="tel:' + esc(g.phone) + '" style="color:#1F6080;font-weight:800;">📞 ' + esc(g.phone) + '</a>' : ' · <span style="color:#94A3B8;">no phone</span>') + '</span>';
              }).join('') + '</div>' : '<div style="font-size:12.5px;color:#94A3B8;margin-top:8px;">No guardian on file.</div>')
            + '</div>';
        }).join('') : '<div style="padding:14px 16px;background:#F0FDF4;border-radius:12px;color:#166534;font-weight:700;font-size:13.5px;">✓ Every child due so far is accounted for.</div>')
      + '<h3 style="margin:22px 0 10px;font-size:15px;color:#0F172A;">Everyone else today</h3>'
      + '<table data-kt-no-kebab style="width:100%;border-collapse:collapse;"><thead><tr>'
      + ['Child', 'Room', 'Expected', 'Status', 'Details'].map(function (h) { return '<th style="text-align:left;padding:8px;border-bottom:1px solid #E5E7EB;font-size:12px;color:#6B7280;">' + h + '</th>'; }).join('')
      + '</tr></thead><tbody>'
      + (rest.length ? rest.map(function (r) {
          var det = r.state === 'arrived' ? 'Signed in ' + esc(r.arrived)
            : r.state === 'absent' ? (r.absent && r.absent.reason ? esc(r.absent.reason) : 'Reported absent') + (r.absent && r.absent.note ? ' — ' + esc(r.absent.note) : '')
            : r.state === 'resolved' ? esc((r.resolution || '').replace('_', ' ')) + (r.note ? ' — ' + esc(r.note) : '') + (r.resolved ? ' (' + esc(r.resolved) + ')' : '')
            : 'Check at ' + esc(r.due);
          return '<tr><td style="padding:9px 8px;border-bottom:1px solid #F3F4F6;font-size:13px;font-weight:600;">' + esc(r.name) + '</td>'
            + '<td style="padding:9px 8px;border-bottom:1px solid #F3F4F6;font-size:13px;">' + esc(r.room_name) + '</td>'
            + '<td style="padding:9px 8px;border-bottom:1px solid #F3F4F6;font-size:13px;">' + esc(r.expected) + (r.expected_is_default ? ' <span style="color:#94A3B8;">(default)</span>' : '') + '</td>'
            + '<td style="padding:9px 8px;border-bottom:1px solid #F3F4F6;">' + chip(r.state) + '</td>'
            + '<td style="padding:9px 8px;border-bottom:1px solid #F3F4F6;font-size:12.5px;color:#475569;">' + det + '</td></tr>';
        }).join('') : '<tr><td colspan="5" style="padding:18px;color:#64748B;text-align:center;">Nobody else is scheduled today.</td></tr>')
      + '</tbody></table>';

    var sel = root.querySelector('#sa-centre');
    if (sel) sel.onchange = function () { centreFilter = sel.value; render(main); };
    root.querySelector('#sa-refresh').onclick = function () { render(main); };
    var st = root.querySelector('#sa-settings');
    if (st) st.onclick = function () { settingsDialog(main, s); };
    root.querySelectorAll('[data-sa-act]').forEach(function (b) {
      b.onclick = function () {
        var row = d.rows.filter(function (r) { return String(r.child_id) === b.getAttribute('data-child'); })[0];
        resolveDialog(main, row, b.getAttribute('data-sa-act'));
      };
    });

    // Keep it current while it is on screen: a minute is plenty for a 5-minute job.
    timer = setTimeout(function () { if (main.isConnected && root.isConnected && !document.querySelector('.sa-modal')) render(main); }, 60000);
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

  function settingsDialog(main, s) {
    var m = modal('Safe Arrival settings',
      '<label style="display:flex;align-items:center;gap:10px;font-size:14px;font-weight:700;color:#0F172A;cursor:pointer;">'
      + '<input type="checkbox" data-enabled ' + (s.enabled ? 'checked' : '') + ' style="width:18px;height:18px;"> Check that every scheduled child arrives</label>'
      + '<div style="font-size:12px;color:#64748B;margin:4px 0 0 28px;line-height:1.45;">When on, parents are messaged automatically — through the channels each parent chose — when their child is late and no absence was reported.</div>'
      + '<label style="' + LBL + '">Ask parents this many minutes after the expected drop-off time</label>'
      + '<input type="number" data-grace min="0" max="180" style="' + FLD + '" value="' + esc(s.grace_minutes) + '">'
      + '<label style="' + LBL + '">Alert the room\'s educators and the director if parents haven\'t answered after (minutes)</label>'
      + '<input type="number" data-esc min="5" max="240" style="' + FLD + '" value="' + esc(s.escalate_after_minutes) + '">'
      + '<label style="' + LBL + '">Expected time for a child without one</label>'
      + '<input type="time" data-def style="' + FLD + '" value="' + esc(s.default_time) + '">'
      + '<div style="font-size:12px;color:#64748B;margin-top:6px;line-height:1.45;">Set each child\'s own drop-off time on their record (Usual times) for the most accurate checks. Only days the child is scheduled and the centre is open are checked.</div>');
    m.querySelector('[data-ok]').onclick = async function () {
      var body = {
        enabled: m.querySelector('[data-enabled]').checked,
        grace_minutes: parseInt(m.querySelector('[data-grace]').value, 10) || 0,
        escalate_after_minutes: parseInt(m.querySelector('[data-esc]').value, 10) || 20,
        default_time: m.querySelector('[data-def]').value || '09:00',
      };
      this.disabled = true;
      try { await call('PUT', '/safe-arrival/settings', body); m.remove(); render(main); }
      catch (e) { this.disabled = false; m.querySelector('[data-err]').textContent = e.message; }
    };
  }

  window.KT = window.KT || {};
  window.KT.SafeArrival = { render: render };
  (function reg(n) {
    var S = window.KT && window.KT.Shell;
    if (!S || !S.registerScreen) { if ((n || 0) < 100) setTimeout(function () { reg((n || 0) + 1); }, 100); return; }
    ['agency_admin', 'centre_director', 'educator', 'platform_admin'].forEach(function (r) { S.registerScreen(r + ':safe-arrival', render); });
  })(0);
})(window);
