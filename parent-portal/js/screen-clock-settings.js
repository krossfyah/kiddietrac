/* ═══════════════════════════════════════════════════════════════════
   KIDDIETRAC — Clock settings

   Everything about the time clock in one place, as two tabs:

     • Reminders     — when to nudge somebody who has not clocked in, and somebody
                       still on the clock. Previously hard-coded to 10:00 and 18:30
                       for every agency on the platform.
     • Auto sign-off — close shifts and days somebody forgot to close.

   Both were already per-agency settings living in agencies.settings; they were simply
   never presented together, and one of them was not presented at all.

   Written as one screen rather than nesting the existing auto sign-off screen in a
   tab: that screen brings its own page hero, and a hosted screen must not bring page
   furniture (see CONVENTIONS.md). Its own #auto-signoff hash still works.
   ═══════════════════════════════════════════════════════════════════ */
(function (window) {
  'use strict';
  var KT = window.KT;
  if (!KT || !KT.Shell || !KT.Shell.registerScreen) { return; }
  var Api = KT.Api;

  function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"]/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c];
    });
  }

  var TABS = [
    { id: 'reminders', label: '🔔 Reminders' },
    { id: 'signoff', label: '⏱️ Auto sign-off' },
    { id: 'timeoff', label: '🌴 Time-off approvals' },
  ];
  var active = 'reminders';

  async function render(main) {
    main.setAttribute('data-kt-pretty', '1');
    main.innerHTML = '<div style="padding:14px 24px;max-width:900px;">'
      + '<div class="kt-page-hero"><h2>⏱️ Clock settings</h2>'
      + '<p>When to remind people about the time clock, when to close what they forgot, and how often to remind approvers about pending time off. '
      + 'Times are in your agency&rsquo;s own timezone.</p></div>'
      + '<div id="cs-tabs" style="display:flex;gap:6px;margin:0 0 16px;"></div>'
      + '<div id="cs-body">Loading…</div></div>';

    paintTabs(main);
    await paintBody(main);
  }

  function paintTabs(main) {
    var host = main.querySelector('#cs-tabs');
    host.innerHTML = '';
    TABS.forEach(function (t) {
      var on = t.id === active;
      var b = document.createElement('button');
      b.type = 'button';
      b.textContent = t.label;
      b.style.cssText = 'border:1px solid ' + (on ? '#1F6080' : '#E2E8F0')
        + ';background:' + (on ? '#1F6080' : '#fff') + ';color:' + (on ? '#fff' : '#475569')
        + ';border-radius:999px;padding:7px 16px;font-size:13px;font-weight:700;cursor:pointer;';
      b.addEventListener('click', function () {
        if (active === t.id) { return; }
        active = t.id;
        paintTabs(main);
        paintBody(main);
      });
      host.appendChild(b);
    });
  }

  async function paintBody(main) {
    var body = main.querySelector('#cs-body');
    body.innerHTML = '<div class="kt-card" style="max-width:680px;color:#64748B;">Loading…</div>';
    try {
      if (active === 'reminders') { await paintReminders(body); }
      else if (active === 'timeoff') { await paintTimeoff(body); }
      else { await paintSignoff(body); }
    } catch (e) {
      body.innerHTML = '<div class="kt-card" style="max-width:680px;color:#B91C1C;">'
        + 'Could not load: ' + esc((e && e.message) || 'error') + '</div>';
    }
  }

  function row(label, hint, controlHtml) {
    return '<div style="display:flex;align-items:flex-start;justify-content:space-between;gap:16px;'
      + 'padding:12px 0;border-bottom:1px solid #F1F5F9;">'
      + '<div style="min-width:0;">'
      + '<div style="font-size:14px;color:#334155;font-weight:600;">' + label + '</div>'
      + (hint ? '<div style="font-size:12.5px;color:#64748B;margin-top:2px;">' + hint + '</div>' : '')
      + '</div>'
      + '<div style="flex-shrink:0;display:flex;align-items:center;gap:8px;">' + controlHtml + '</div>'
      + '</div>';
  }

  function timeInput(id, value) {
    return '<input id="' + id + '" type="time" value="' + esc(value || '') + '"'
      + ' style="padding:6px 9px;border:1px solid #D1D5DB;border-radius:8px;font-size:13px;background:#fff;">';
  }

  function toggle(id, on) {
    return '<input id="' + id + '" type="checkbox"' + (on ? ' checked' : '') + '>';
  }

  /* ── Reminders ─────────────────────────────────────────────────────── */
  async function paintReminders(body) {
    var res = await Api.get('/admin/clock-reminders');
    var s = res.settings || {};

    body.innerHTML = '<div class="kt-card" style="max-width:680px;">'
      + '<div class="kt-card-header"><h3 class="kt-card-title">🔔 Time clock reminders</h3></div>'
      + row('Remind people who have not clocked in',
            'Sent to educators and directors with no punch recorded yet that day.',
            toggle('cr-in', s.in_enabled) + timeInput('cr-in-at', s.in_at))
      + row('Remind people still on the clock',
            'Sent to anybody whose shift is still open, including one left open on an earlier day.',
            toggle('cr-out', s.out_enabled) + timeInput('cr-out-at', s.out_at))
      + row('Weekdays only',
            'There is no shift schedule to consult, so this keeps the reminders off people on a Saturday.',
            toggle('cr-wd', s.weekdays_only))
      + row('Send by email', '', toggle('cr-email', s.email))
      + row('Send to the app', 'A push notification on the phone app.', toggle('cr-push', s.push))
      + '<div style="font-size:12px;color:#94A3B8;margin-top:12px;line-height:1.5;">'
      + 'Reminders are checked once an hour, so they go out during the hour you choose '
      + 'rather than at the exact minute.</div>'
      + '<div style="display:flex;align-items:center;gap:12px;margin-top:16px;">'
      + '<button id="cr-save" class="kt-btn kt-btn-primary">Save reminders</button>'
      + '<span id="cr-msg" style="font-size:13px;"></span></div>'
      + '</div>';

    body.querySelector('#cr-save').addEventListener('click', function () {
      var btn = body.querySelector('#cr-save');
      var msg = body.querySelector('#cr-msg');
      btn.disabled = true;
      msg.style.color = '#64748B';
      msg.textContent = 'Saving…';

      Api.post('/admin/clock-reminders', {
        in_enabled: body.querySelector('#cr-in').checked,
        in_at: body.querySelector('#cr-in-at').value || '10:00',
        out_enabled: body.querySelector('#cr-out').checked,
        out_at: body.querySelector('#cr-out-at').value || '18:30',
        weekdays_only: body.querySelector('#cr-wd').checked,
        email: body.querySelector('#cr-email').checked,
        push: body.querySelector('#cr-push').checked,
      }).then(function () {
        btn.disabled = false;
        msg.style.color = '#1E8E60';
        msg.textContent = '✓ Saved';
      }).catch(function (e) {
        btn.disabled = false;
        msg.style.color = '#B91C1C';
        msg.textContent = (e && e.message) || 'Could not save';
      });
    });
  }

  /* ── Time-off approvals ─────────────────────────────────────────────── */
  /* Reminders to the people who approve time off (2026-10-06). Anthony: "have the system
     send reminders to the admin/director to approve time off requests - configurable to
     every day but can be configured based on how the agency wants this done or can be
     disabled". Each approver gets ONE digest of what is waiting on them; the schedule is
     the agency's. Agency admins only (a director sees a note, not the controls). */
  function sel(id, opts, val) {
    return '<select id="' + id + '" style="padding:6px 9px;border:1px solid #D1D5DB;border-radius:8px;font-size:13px;background:#fff;">'
      + opts.map(function (o) { return '<option value="' + esc(o[0]) + '"' + (String(o[0]) === String(val) ? ' selected' : '') + '>' + esc(o[1]) + '</option>'; }).join('')
      + '</select>';
  }
  function num(id, val, min, max) {
    return '<input id="' + id + '" type="number" min="' + min + '" max="' + max + '" value="' + esc(val) + '"'
      + ' style="width:72px;padding:6px 9px;border:1px solid #D1D5DB;border-radius:8px;font-size:13px;">';
  }
  async function paintTimeoff(body) {
    var res;
    try { res = await Api.get('/admin/timeoff-reminders'); }
    catch (e) {
      body.innerHTML = '<div class="kt-card" style="max-width:680px;color:#64748B;">Only agency administrators can change time-off approval reminders.</div>';
      return;
    }
    var s = res.settings || {};
    var DAYS = [[1, 'Monday'], [2, 'Tuesday'], [3, 'Wednesday'], [4, 'Thursday'], [5, 'Friday'], [6, 'Saturday'], [7, 'Sunday']];
    body.innerHTML = '<div class="kt-card" style="max-width:680px;">'
      + '<div class="kt-card-header"><h3 class="kt-card-title">🌴 Time-off approval reminders</h3></div>'
      + '<div style="font-size:12.5px;color:#64748B;line-height:1.5;margin:-4px 0 6px;">Approvers get one email listing every request still waiting on them, with Approve and Decline buttons. '
      + (res.pending_now ? '<b>' + res.pending_now + '</b> request' + (res.pending_now === 1 ? ' is' : 's are') + ' pending right now.' : 'Nothing is pending right now.')
      + (res.last_sent_at ? ' Last sent ' + esc(res.last_sent_at) + '.' : '') + '</div>'
      + row('Send reminders', 'Off means no reminders. People are still told when a request is first made.', toggle('to-on', s.enabled))
      + row('How often', '', sel('to-freq', [['daily', 'Every day'], ['weekdays', 'Weekdays only'], ['weekly', 'Once a week'], ['every_n_days', 'Every few days']], s.frequency)
          + '<span id="to-wd-wrap">' + sel('to-wd', DAYS, s.weekly_day) + '</span>'
          + '<span id="to-n-wrap">every ' + num('to-n', s.every_n_days, 1, 30) + ' days</span>')
      + row('Time of day', 'In ' + esc(res.tz || 'your agency’s timezone') + '. Checked every 15 minutes.', timeInput('to-time', s.time))
      + row('Only requests waiting at least', 'So a request made an hour ago is not chased the same morning.', num('to-min', s.min_hours, 0, 720) + ' hours')
      + row('Who is reminded', 'Directors only see their own centre’s requests. If a centre has no director, its requests go to the admins.',
          sel('to-who', [['both', 'Directors and admins'], ['directors', 'Directors only'], ['admins', 'Admins only']], s.recipients))
      + row('Send by email', '', toggle('to-email', s.email))
      + row('Show in the app', 'A notification in their bell.', toggle('to-app', s.in_app))
      + row('Push to phones', 'A push notification on the KiddieTrac app.', toggle('to-push', s.push))
      + '<div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;margin-top:16px;">'
      + '<button id="to-save" class="kt-btn kt-btn-primary">Save</button>'
      + '<button id="to-prev" class="kt-btn">👁 Preview</button>'
      + '<button id="to-test" class="kt-btn">📤 Send test…</button>'
      + '<span id="to-msg" style="font-size:13px;"></span></div>'
      + '</div>';

    function vals() {
      return {
        enabled: body.querySelector('#to-on').checked,
        frequency: body.querySelector('#to-freq').value,
        weekly_day: +body.querySelector('#to-wd').value,
        every_n_days: +body.querySelector('#to-n').value || 2,
        time: body.querySelector('#to-time').value || '09:00',
        min_hours: +body.querySelector('#to-min').value || 0,
        recipients: body.querySelector('#to-who').value,
        email: body.querySelector('#to-email').checked,
        in_app: body.querySelector('#to-app').checked,
        push: body.querySelector('#to-push').checked,
      };
    }
    function syncFreq() {
      var f = body.querySelector('#to-freq').value;
      body.querySelector('#to-wd-wrap').style.display = f === 'weekly' ? '' : 'none';
      body.querySelector('#to-n-wrap').style.display = f === 'every_n_days' ? '' : 'none';
    }
    body.querySelector('#to-freq').addEventListener('change', syncFreq);
    syncFreq();
    var msg = body.querySelector('#to-msg');
    function say(t, c) { msg.style.color = c || '#64748B'; msg.textContent = t; }

    body.querySelector('#to-save').addEventListener('click', function () {
      say('Saving…');
      Api.post('/admin/timeoff-reminders', vals())
        .then(function () { say('✓ Saved', '#1E8E60'); })
        .catch(function (e) { say((e && e.message) || 'Could not save', '#B91C1C'); });
    });
    body.querySelector('#to-prev').addEventListener('click', function () {
      say('Building preview…');
      Api.post('/admin/timeoff-reminders/preview', vals()).then(function (r) {
        say('');
        var ov = document.createElement('div');
        ov.setAttribute('data-no-modal-guard', '1');
        ov.className = 'kt-scrim';
        ov.style.cssText = 'position:fixed;inset:0;z-index:2147481200;display:flex;align-items:center;justify-content:center;padding:16px;';
        ov.innerHTML = '<div style="background:#fff;border-radius:14px;width:100%;max-width:700px;height:min(88vh,900px);display:flex;flex-direction:column;overflow:hidden;">'
          + '<div style="padding:12px 16px;border-bottom:1px solid #E2E8F0;display:flex;align-items:center;gap:10px;">'
          + '<div style="flex:1;min-width:0;"><div style="font-size:11px;font-weight:800;color:#64748B;letter-spacing:.5px;">PREVIEW — NOTHING IS SENT</div>'
          + '<div style="font-weight:800;font-size:14px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"></div>'
          + '<div style="font-size:12px;color:#64748B;">' + (r.sample ? 'Nothing is pending, so this uses a sample request.' : (r.requests + ' pending request(s); ' + r.approvers + ' approver(s) would get a reminder.')) + '</div></div>'
          + '<button type="button" class="kt-btn" data-close>Close</button></div>'
          + '<iframe sandbox="" style="flex:1;width:100%;border:0;background:#F4F6F8;"></iframe></div>';
        ov.querySelector('[style*="font-weight:800;font-size:14px"]').textContent = r.subject || '';
        ov.querySelector('iframe').srcdoc = r.html || '';
        function close() { ov.remove(); }
        ov.querySelector('[data-close]').addEventListener('click', close);
        ov.addEventListener('click', function (e) { if (e.target === ov) { close(); } });
        document.body.appendChild(ov);
      }).catch(function (e) { say((e && e.message) || 'Could not build the preview', '#B91C1C'); });
    });
    body.querySelector('#to-test').addEventListener('click', async function () {
      var to = await KT.prompt({ title: 'Send a test reminder', description: 'One copy of the reminder, marked [Test], to the address you enter. Its buttons only open the portal.', placeholder: 'name@example.com', type: 'email', okLabel: 'Send test' });
      if (to === null) { return; }
      to = String(to || '').trim();
      if (!/^[^@\s]+@[^@\s]+\.[^@\s]+$/.test(to)) { say('Enter an email address.', '#B91C1C'); return; }
      say('Sending…');
      Api.post('/admin/timeoff-reminders/test', Object.assign({ to: to }, vals())).then(function (r) {
        if (r.sent) { say('✓ Sent to ' + to, '#1E8E60'); }
        else { say('Not sent (' + (r.status || 'failed') + ')' + (r.reason ? ': ' + r.reason : ''), '#B45309'); }
      }).catch(function (e) { say((e && e.message) || 'Could not send', '#B91C1C'); });
    });
  }

  /* ── Auto sign-off ─────────────────────────────────────────────────── */
  async function paintSignoff(body) {
    var res = await Api.get('/admin/auto-signoff');
    // The endpoint returns the block under `auto_signoff`, alongside agency_name and a
    // count of what it would act on. A loose `res.settings || res` fallback silently
    // produced empty time fields, which read as "no time set" rather than as a bug.
    var s = res.auto_signoff || {};

    body.innerHTML = '<div class="kt-card" style="max-width:680px;">'
      + '<div class="kt-card-header"><h3 class="kt-card-title">⏱️ Auto sign-off</h3></div>'
      + '<div style="font-size:12.5px;color:#64748B;margin:-4px 0 6px;">'
      + 'A shift or a day left open is closed at the time you set <b>on the day it started</b>, '
      + 'never at whatever time the job happens to run — otherwise a punch opened on Tuesday '
      + 'and closed on Thursday would record a 48-hour shift.</div>'
      + row('Close staff shifts', 'Applies to a punch nobody clocked out of.',
            toggle('as-staff', s.staff_enabled) + timeInput('as-staff-at', s.staff_at))
      + row('Give up on a shift after',
            'A punch still open this many hours after it began is abandoned, not long.',
            '<input id="as-staff-max" type="number" min="1" max="24" value="' + esc(s.staff_max_hours || 14)
            + '" style="width:74px;padding:6px 9px;border:1px solid #D1D5DB;border-radius:8px;font-size:13px;text-align:right;">'
            + '<span style="font-size:12.5px;color:#64748B;">hours</span>')
      + row('Sign children out', 'Applies to a child nobody signed out at the end of the day.',
            toggle('as-kids', s.children_enabled) + timeInput('as-kids-at', s.children_at))
      + '<div style="display:flex;align-items:center;gap:12px;margin-top:16px;">'
      + '<button id="as-save" class="kt-btn kt-btn-primary">Save auto sign-off</button>'
      + '<span id="as-msg" style="font-size:13px;"></span></div>'
      + '</div>';

    body.querySelector('#as-save').addEventListener('click', function () {
      var btn = body.querySelector('#as-save');
      var msg = body.querySelector('#as-msg');
      btn.disabled = true;
      msg.style.color = '#64748B';
      msg.textContent = 'Saving…';

      Api.post('/admin/auto-signoff', {
        staff_enabled: body.querySelector('#as-staff').checked,
        staff_at: body.querySelector('#as-staff-at').value || '19:00',
        staff_max_hours: parseInt(body.querySelector('#as-staff-max').value, 10) || 14,
        children_enabled: body.querySelector('#as-kids').checked,
        children_at: body.querySelector('#as-kids-at').value || '18:30',
      }).then(function () {
        btn.disabled = false;
        msg.style.color = '#1E8E60';
        msg.textContent = '✓ Saved';
      }).catch(function (e) {
        btn.disabled = false;
        msg.style.color = '#B91C1C';
        msg.textContent = (e && e.message) || 'Could not save';
      });
    });
  }

  // paintSignoff is also drawn in Settings → Room Controls → Auto sign-off (2026-10-15).
  KT.ClockSettings = { render: render, paintSignoff: paintSignoff };
  ['agency_admin', 'centre_director', 'platform_admin'].forEach(function (r) {
    KT.Shell.registerScreen(r + ':clock-settings', render);
  });
})(window);
