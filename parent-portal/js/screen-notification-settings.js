/* ═══════════════════════════════════════════════════════════════════════
   Settings → Notifications (#notification-settings)  — 2026-10-09

   Anthony: "is there an email that gets sent to directors/admins to counter sign forms
   that came back as a reminder - these should be daily emails and configuration on the
   notifications in settings". The agency's scheduled reminders to its own reviewers live
   here. First section: completed forms waiting for counter-signature (one digest per
   admin/director, on the agency's schedule). Agency admins only.
   ═══════════════════════════════════════════════════════════════════════ */
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
  function row(label, hint, controlHtml) {
    return '<div style="display:flex;align-items:flex-start;justify-content:space-between;gap:16px;flex-wrap:wrap;'
      + 'padding:12px 0;border-bottom:1px solid #F1F5F9;">'
      + '<div style="min-width:0;flex:1 1 260px;">'
      + '<div style="font-size:14px;color:#334155;font-weight:600;">' + label + '</div>'
      + (hint ? '<div style="font-size:12.5px;color:#64748B;margin-top:2px;">' + hint + '</div>' : '')
      + '</div>'
      + '<div style="flex-shrink:0;display:flex;align-items:center;gap:8px;flex-wrap:wrap;">' + controlHtml + '</div>'
      + '</div>';
  }
  function toggle(id, on) { return '<input id="' + id + '" type="checkbox"' + (on ? ' checked' : '') + '>'; }
  function timeInput(id, value) {
    return '<input id="' + id + '" type="time" value="' + esc(value || '') + '"'
      + ' style="padding:6px 9px;border:1px solid #D1D5DB;border-radius:8px;font-size:13px;background:#fff;">';
  }
  function sel(id, opts, val) {
    return '<select id="' + id + '" style="padding:6px 9px;border:1px solid #D1D5DB;border-radius:8px;font-size:13px;background:#fff;">'
      + opts.map(function (o) { return '<option value="' + esc(o[0]) + '"' + (String(o[0]) === String(val) ? ' selected' : '') + '>' + esc(o[1]) + '</option>'; }).join('')
      + '</select>';
  }
  function num(id, val, min, max) {
    return '<input id="' + id + '" type="number" min="' + min + '" max="' + max + '" value="' + esc(val) + '"'
      + ' style="width:72px;padding:6px 9px;border:1px solid #D1D5DB;border-radius:8px;font-size:13px;">';
  }

  async function render(main) {
    main.setAttribute('data-kt-pretty', '1');
    main.innerHTML = '<div style="padding:14px 24px;max-width:900px;">'
      + '<div id="ns-body"><div class="kt-card" style="max-width:680px;color:#64748B;">Loading…</div></div></div>';
    var body = main.querySelector('#ns-body');
    try { await paintCountersign(body); }
    catch (e) {
      body.innerHTML = '<div class="kt-card" style="max-width:680px;color:#B91C1C;">Could not load: ' + esc((e && e.message) || 'error') + '</div>';
    }
  }

  /* ── Forms waiting for counter-signature ─────────────────────────────── */
  async function paintCountersign(body) {
    var res;
    try { res = await Api.get('/admin/countersign-reminders'); }
    catch (e) {
      body.innerHTML = '<div class="kt-card" style="max-width:680px;color:#64748B;">Only agency administrators can change these notifications.</div>';
      return;
    }
    var s = res.settings || {};
    var DAYS = [[1, 'Monday'], [2, 'Tuesday'], [3, 'Wednesday'], [4, 'Thursday'], [5, 'Friday'], [6, 'Saturday'], [7, 'Sunday']];
    body.innerHTML = '<div class="kt-card" style="max-width:680px;">'
      + '<div class="kt-card-header"><h3 class="kt-card-title">✍️ Forms waiting for counter-signature</h3></div>'
      + '<div style="font-size:12.5px;color:#64748B;line-height:1.5;margin:-4px 0 6px;">Admins and directors get one email listing the completed forms still waiting for their review and counter-signature. '
      + 'Only forms set to <b>Requires counter-signature</b> count (Forms Manager → Edit). '
      + (res.waiting_now ? '<b>' + res.waiting_now + '</b> form' + (res.waiting_now === 1 ? ' is' : 's are') + ' waiting right now.' : 'Nothing is waiting right now.')
      + (res.last_sent_at ? ' Last sent ' + esc(res.last_sent_at) + '.' : '') + '</div>'
      + row('Send reminders', 'Off means no reminders. The "Form submitted" notice still goes out when a form is signed.', toggle('cs-on', s.enabled))
      + row('How often', '', sel('cs-freq', [['daily', 'Every day'], ['weekdays', 'Weekdays only'], ['weekly', 'Once a week'], ['every_n_days', 'Every few days']], s.frequency)
          + '<span id="cs-wd-wrap">' + sel('cs-wd', DAYS, s.weekly_day) + '</span>'
          + '<span id="cs-n-wrap">every ' + num('cs-n', s.every_n_days, 1, 30) + ' days</span>')
      + row('Time of day', 'In ' + esc(res.tz || 'your agency’s timezone') + '. Checked every 15 minutes.', timeInput('cs-time', s.time))
      + row('Only forms signed at least', 'So a form signed a few minutes ago is not chased straight away.', num('cs-min', s.min_hours, 0, 720) + ' hours ago')
      + row('Who is reminded', 'Directors only see forms from their own provider. If a provider has no director, its forms go to the admins.',
          sel('cs-who', [['both', 'Directors and admins'], ['directors', 'Directors only'], ['admins', 'Admins only']], s.recipients))
      + row('Send by email', '', toggle('cs-email', s.email))
      + row('Show in the app', 'A notification in their bell.', toggle('cs-app', s.in_app))
      + row('Push to phones', 'A push notification on the KiddieTrac app.', toggle('cs-push', s.push))
      + '<div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;margin-top:16px;">'
      + '<button id="cs-save" class="kt-btn kt-btn-primary">Save</button>'
      + '<button id="cs-prev" class="kt-btn">👁 Preview</button>'
      + '<button id="cs-test" class="kt-btn">📤 Send test…</button>'
      + '<span id="cs-msg" style="font-size:13px;"></span></div>'
      + '</div>';

    function vals() {
      return {
        enabled: body.querySelector('#cs-on').checked,
        frequency: body.querySelector('#cs-freq').value,
        weekly_day: +body.querySelector('#cs-wd').value,
        every_n_days: +body.querySelector('#cs-n').value || 2,
        time: body.querySelector('#cs-time').value || '09:00',
        min_hours: +body.querySelector('#cs-min').value || 0,
        recipients: body.querySelector('#cs-who').value,
        email: body.querySelector('#cs-email').checked,
        in_app: body.querySelector('#cs-app').checked,
        push: body.querySelector('#cs-push').checked,
      };
    }
    function syncFreq() {
      var f = body.querySelector('#cs-freq').value;
      body.querySelector('#cs-wd-wrap').style.display = f === 'weekly' ? '' : 'none';
      body.querySelector('#cs-n-wrap').style.display = f === 'every_n_days' ? '' : 'none';
    }
    body.querySelector('#cs-freq').addEventListener('change', syncFreq);
    syncFreq();
    var msg = body.querySelector('#cs-msg');
    function say(t, c) { msg.style.color = c || '#64748B'; msg.textContent = t; }

    body.querySelector('#cs-save').addEventListener('click', function () {
      say('Saving…');
      Api.post('/admin/countersign-reminders', vals())
        .then(function () { say('✓ Saved', '#1E8E60'); })
        .catch(function (e) { say((e && e.message) || 'Could not save', '#B91C1C'); });
    });
    body.querySelector('#cs-prev').addEventListener('click', function () {
      say('Building preview…');
      Api.post('/admin/countersign-reminders/preview', vals()).then(function (r) {
        say('');
        var ov = document.createElement('div');
        ov.setAttribute('data-no-modal-guard', '1');
        ov.className = 'kt-scrim';
        ov.style.cssText = 'position:fixed;inset:0;z-index:2147481200;display:flex;align-items:center;justify-content:center;padding:16px;';
        ov.innerHTML = '<div style="background:#fff;border-radius:14px;width:100%;max-width:700px;height:min(88vh,900px);display:flex;flex-direction:column;overflow:hidden;">'
          + '<div style="padding:12px 16px;border-bottom:1px solid #E2E8F0;display:flex;align-items:center;gap:10px;">'
          + '<div style="flex:1;min-width:0;"><div style="font-size:11px;font-weight:800;color:#64748B;letter-spacing:.5px;">PREVIEW — NOTHING IS SENT</div>'
          + '<div data-subj style="font-weight:800;font-size:14px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"></div>'
          + '<div style="font-size:12px;color:#64748B;">' + (r.sample ? 'Nothing is waiting, so this uses a sample form.' : (r.forms + ' waiting form(s); ' + r.reviewers + ' reviewer(s) would get a reminder.')) + '</div></div>'
          + '<button type="button" class="kt-btn" data-close>Close</button></div>'
          + '<iframe sandbox="" style="flex:1;width:100%;border:0;background:#F4F6F8;"></iframe></div>';
        ov.querySelector('[data-subj]').textContent = r.subject || '';
        ov.querySelector('iframe').srcdoc = r.html || '';
        function close() { ov.remove(); }
        ov.querySelector('[data-close]').addEventListener('click', close);
        ov.addEventListener('click', function (e) { if (e.target === ov) { close(); } });
        document.body.appendChild(ov);
      }).catch(function (e) { say((e && e.message) || 'Could not build the preview', '#B91C1C'); });
    });
    body.querySelector('#cs-test').addEventListener('click', async function () {
      var to = await KT.prompt({ title: 'Send a test reminder', description: 'One copy of the reminder, marked [Test], to the address you enter.', placeholder: 'name@example.com', type: 'email', okLabel: 'Send test' });
      if (to === null) { return; }
      to = String(to || '').trim();
      if (!/^[^@\s]+@[^@\s]+\.[^@\s]+$/.test(to)) { say('Enter an email address.', '#B91C1C'); return; }
      say('Sending…');
      Api.post('/admin/countersign-reminders/test', Object.assign({ to: to }, vals())).then(function (r) {
        if (r.sent) { say('✓ Sent to ' + to, '#1E8E60'); }
        else { say('Not sent (' + (r.status || 'failed') + ')' + (r.reason ? ': ' + r.reason : ''), '#B45309'); }
      }).catch(function (e) { say((e && e.message) || 'Could not send', '#B91C1C'); });
    });
  }

  ['agency_admin', 'platform_admin'].forEach(function (r) {
    KT.Shell.registerScreen(r + ':notification-settings', render);
  });
})(window);
