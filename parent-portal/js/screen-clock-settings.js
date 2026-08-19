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
  ];
  var active = 'reminders';

  async function render(main) {
    main.setAttribute('data-kt-pretty', '1');
    main.innerHTML = '<div style="padding:14px 24px;max-width:900px;">'
      + '<div class="kt-page-hero"><h2>⏱️ Clock settings</h2>'
      + '<p>When to remind people about the time clock, and when to close what they forgot. '
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

  KT.ClockSettings = { render: render };
  ['agency_admin', 'centre_director', 'platform_admin'].forEach(function (r) {
    KT.Shell.registerScreen(r + ':clock-settings', render);
  });
})(window);
