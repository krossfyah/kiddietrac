/* ═══════════════════════════════════════════════════════════════════
   KIDDIETRAC — Settings → Room Controls → Daily records (2026-10-15)

   The evening reminder to educators (copying directors and agency admins) about
   observations, daily logs, photos/videos and meals not recorded, and the week's lesson
   plan and menu not scheduled. Sent by `records:remind` (App\Support\RecordReminders).
   Agency admins edit; directors see the settings and the last 14 days for their centres.
   ═══════════════════════════════════════════════════════════════════ */
(function (window) {
  'use strict';
  var KT = window.KT = window.KT || {};

  function esc(s) { return String(s == null ? '' : s).replace(/[&<>"]/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]; }); }

  function row(label, hint, ctl) {
    return '<div style="display:flex;align-items:flex-start;justify-content:space-between;gap:16px;flex-wrap:wrap;padding:12px 0;border-bottom:1px solid #F1F5F9;">'
      + '<div style="min-width:0;flex:1 1 240px;"><div style="font-size:14px;color:#334155;font-weight:600;">' + label + '</div>'
      + (hint ? '<div style="font-size:12.5px;color:#64748B;margin-top:2px;">' + hint + '</div>' : '') + '</div>'
      + '<div style="flex-shrink:0;display:flex;align-items:center;gap:8px;">' + ctl + '</div></div>';
  }

  async function paintSettings(pane) {
    var d;
    try { d = await KT.Api.get('/admin/record-reminders'); }
    catch (e) { pane.innerHTML = '<div class="kt-card" style="max-width:760px;color:#B91C1C;">' + esc((e && e.message) || 'Could not load') + '</div>'; return; }
    var s = d.settings || {}, ed = !!d.can_edit, dis = ed ? '' : ' disabled';
    function num(id, v, max) { return '<input id="' + id + '" type="number" min="0" max="' + (max || 50) + '" value="' + esc(v) + '"' + dis + ' style="width:70px;padding:6px 8px;border:1px solid #D1D5DB;border-radius:8px;font-size:13px;">'; }
    function chk(id, on) { return '<input id="' + id + '" type="checkbox"' + (on ? ' checked' : '') + dis + '>'; }

    var h = '<div class="kt-card" style="max-width:760px;"><div class="kt-card-header"><h3 class="kt-card-title">📝 Daily records reminders</h3></div>'
      + '<div style="font-size:12.5px;color:#64748B;line-height:1.5;margin:-4px 0 6px;">Each evening, educators who worked that day get a professional reminder about anything not recorded, '
      + 'with a count of how many days in the window had gaps and fresh tips every day. Their director and the agency admins are copied. '
      + 'The numbers are the same ones the educator\'s 7 p.m. "Your day at a glance" email shows.</div>'
      + row('Send daily records reminders', 'Only educators who worked that day, on days the centre is open.', chk('rr-on', s.enabled))
      + row('Send at', 'Your agency\'s time. Educators already finished for the day get it then.', '<input id="rr-at" type="time" value="' + esc(s.send_at) + '"' + dis + ' style="padding:6px 9px;border:1px solid #D1D5DB;border-radius:8px;font-size:13px;">')
      + '<div style="font-size:13px;font-weight:800;color:#0F172A;margin:16px 0 2px;">What educators are expected to record each day</div>'
      + row('Observations', 'At least this many per educator. 0 turns the check off.', num('rr-obs', s.observations_min))
      + row('Daily log entries', 'Meals, naps, toileting, activities and notes together.', num('rr-logs', s.logs_min))
      + row('Photos or videos', 'Shared with families.', num('rr-media', s.media_min))
      + row('Meals and snacks logged', 'At least one when children were in care.', chk('rr-meals', s.check_meals))
      + '<div style="font-size:13px;font-weight:800;color:#0F172A;margin:16px 0 2px;">Scheduled for the week</div>'
      + row('Lesson plan', 'A plan exists for the educator\'s room or centre this week.', chk('rr-lessons', s.check_lessons))
      + row('Menu', 'This week\'s menu is written and published.', chk('rr-menu', s.check_menu))
      + '<div style="font-size:13px;font-weight:800;color:#0F172A;margin:16px 0 2px;">Copies and escalation</div>'
      + row('Copy the director and agency admins', 'They are on the same email (CC).', chk('rr-cc', s.cc_managers))
      + row('Escalate after', 'Days with incomplete records within the window. The email then says it has been escalated under your documentation policy.', num('rr-esc', s.escalate_after) + ' days in ' + num('rr-win', s.window_days, 90) + ' days')
      + (ed ? '<div style="display:flex;align-items:center;gap:8px;margin-top:16px;"><button id="rr-save" class="kt-btn kt-btn-primary" data-kt-iconized="1">Save</button><span id="rr-msg" style="font-size:13px;"></span></div>'
            : '<div style="font-size:12.5px;color:#64748B;margin-top:12px;">Only agency administrators can change these settings.</div>')
      + '</div>';

    var rec = d.recent || [];
    h += '<div class="kt-card" style="max-width:760px;margin-top:14px;"><div class="kt-card-header"><h3 class="kt-card-title">Reminders sent — last 14 days</h3></div>'
      + (rec.length
        ? '<table class="kt-table" data-kt-no-bulk><thead><tr><th>Date</th><th>Educator</th><th>Not recorded</th><th>Days in window</th></tr></thead><tbody>'
          + rec.map(function (r) {
              return '<tr><td style="white-space:nowrap;">' + esc(r.date) + '</td><td>' + esc(r.educator) + (r.centre ? '<div style="font-size:11.5px;color:#64748B;">' + esc(r.centre) + '</div>' : '') + '</td>'
                + '<td>' + esc(r.missing.join(', ')) + '</td><td>' + r.days + (r.escalated ? ' <span style="color:#B91C1C;font-weight:700;">· escalated</span>' : '') + (r.emailed ? '' : ' <span style="color:#64748B;">(not emailed)</span>') + '</td></tr>';
            }).join('') + '</tbody></table>'
        : '<div style="font-size:13px;color:#64748B;">None yet.</div>')
      + '</div>';
    pane.innerHTML = h;

    var save = pane.querySelector('#rr-save');
    if (!save) return;
    save.addEventListener('click', function () {
      var msg = pane.querySelector('#rr-msg');
      var out = {
        enabled: pane.querySelector('#rr-on').checked, send_at: pane.querySelector('#rr-at').value || '18:30',
        observations_min: +pane.querySelector('#rr-obs').value, logs_min: +pane.querySelector('#rr-logs').value, media_min: +pane.querySelector('#rr-media').value,
        check_meals: pane.querySelector('#rr-meals').checked, check_lessons: pane.querySelector('#rr-lessons').checked, check_menu: pane.querySelector('#rr-menu').checked,
        cc_managers: pane.querySelector('#rr-cc').checked, escalate_after: +pane.querySelector('#rr-esc').value, window_days: +pane.querySelector('#rr-win').value,
      };
      msg.style.color = '#64748B'; msg.textContent = 'Saving…';
      KT.Api.post('/admin/record-reminders', out).then(function () { msg.style.color = '#1E8E60'; msg.textContent = '✓ Saved'; })
        .catch(function (e) { msg.style.color = '#B91C1C'; msg.textContent = (e && e.message) || 'Could not save'; });
    });
  }

  KT.RecordReminders = { paintSettings: paintSettings };
})(window);
