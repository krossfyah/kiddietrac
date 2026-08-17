/* ============================================================
   KIDDIETRAC — Birthday emails (agency settings)
   GET/POST /admin/birthday-settings (agencies.settings JSON → "birthdays").

   Four separate switches rather than one master toggle, because the recipients are
   not interchangeable: an agency may want its educators reminded a birthday is coming
   without mail going to families about it, and staff birthdays are a different
   decision again. Everything is off until somebody turns it on.
   ============================================================ */
(function (window) {
  'use strict';
  var KT = window.KT;
  var Api = KT.Api, Shell = KT.Shell;

  function esc(s) {
    return s == null ? '' : String(s)
      .replace(/&/g, '&amp;').replace(/</g, '&lt;')
      .replace(/>/g, '&gt;').replace(/"/g, '&quot;');
  }

  var INP = 'box-sizing:border-box;padding:9px 11px;border:1px solid #D6DEE7;border-radius:9px;font-size:14px;';

  function card(title, sub, inner) {
    return '<div style="background:#fff;border:1px solid #E7EBF0;border-radius:14px;padding:20px 22px;margin-bottom:16px;">'
      + '<div style="font-weight:800;font-size:15.5px;color:#0D1B2A;">' + esc(title) + '</div>'
      + (sub ? '<div style="font-size:13px;color:#64748B;margin:3px 0 14px;">' + esc(sub) + '</div>' : '<div style="height:12px;"></div>')
      + inner + '</div>';
  }

  function toggle(key, label, hint, on) {
    return '<label style="display:flex;align-items:flex-start;gap:12px;padding:10px 0;cursor:pointer;">'
      + '<input id="b_' + key + '" type="checkbox" ' + (on ? 'checked' : '') + ' style="margin-top:3px;width:17px;height:17px;flex-shrink:0;">'
      + '<span><span style="font-size:14px;color:#334155;font-weight:600;">' + esc(label) + '</span>'
      + (hint ? '<span style="display:block;font-size:12.5px;color:#64748B;margin-top:2px;">' + esc(hint) + '</span>' : '') + '</span></label>';
  }

  async function render(main) {
    main.setAttribute('data-kt-pretty', '1');
    main.innerHTML = '<div style="padding:14px 24px;max-width:840px;"><div class="kt-page-hero"><h2>🎂 Birthday emails</h2><p>Loading…</p></div></div>';

    var data = await Api.get('/admin/birthday-settings').catch(function (e) { return { __err: (e && e.message) || 'error' }; });
    if (data.__err) {
      main.innerHTML = '<div style="padding:14px 24px;"><div class="kt-page-hero"><h2>🎂 Birthday emails</h2></div>'
        + '<div style="background:#FEF3C7;border:1px solid #FDE68A;color:#92400E;border-radius:12px;padding:18px;max-width:640px;">This page is available to <b>agency administrators</b>.</div></div>';
      return;
    }

    var b = data.birthdays || {};
    var cov = data.coverage || {};
    var days = [0, 1, 2, 3, 5, 7, 14];

    // A birthday email can only find somebody with a date of birth on file. Saying so
    // here is the difference between "switched on and quietly doing nothing" and a
    // setting the admin can trust.
    var coverageNote = '';
    if (!cov.staff_with_dob) {
      coverageNote = '<div style="background:#FEF3C7;border:1px solid #FDE68A;color:#92400E;border-radius:10px;padding:12px 14px;font-size:13px;margin-bottom:16px;">'
        + '<b>No staff have a date of birth on file yet.</b> Staff birthday emails will stay silent until those are recorded on each person’s profile. '
        + esc(String(cov.children_with_dob || 0)) + ' children do have one.</div>';
    }

    main.innerHTML = '<div style="padding:14px 24px;max-width:840px;">'
      + '<div class="kt-page-hero"><h2>🎂 Birthday emails</h2><p>A warm note when a birthday is coming up at ' + esc(data.agency_name || 'your agency') + '. Each group below is switched separately, so you can remind your team without emailing families — or the other way round.</p></div>'
      + coverageNote

      + card('Birthday emails', 'The master switch. With this off, nothing below sends.',
          toggle('enabled', 'Send birthday emails', 'Runs each morning at 7am.', b.enabled)
          + '<div style="display:flex;align-items:center;gap:12px;padding:12px 0 2px;">'
          + '<label for="b_days_ahead" style="font-size:14px;color:#334155;font-weight:600;">Send</label>'
          + '<select id="b_days_ahead" style="' + INP + 'width:auto;">'
          + days.map(function (d) {
              var label = d === 0 ? 'on the day' : d === 1 ? '1 day before' : d + ' days before';
              return '<option value="' + d + '"' + (Number(b.days_ahead) === d ? ' selected' : '') + '>' + label + '</option>';
            }).join('')
          + '</select>'
          + '<span style="font-size:12.5px;color:#64748B;">Sending a day or two ahead gives a room time to plan.</span></div>')

      + card('Children’s birthdays', 'Who hears about a child’s birthday.',
          toggle('children_notify_guardians', 'Email the child’s parents', 'A warm note naming the child and the age they are turning.', b.children_notify_guardians)
          + toggle('children_notify_educators', 'Email the educators and director at their centre', 'A short heads-up so the room can mark the day.', b.children_notify_educators))

      + card('Staff birthdays', 'Who hears about a colleague’s birthday. Needs a date of birth on the person’s profile.',
          toggle('staff_notify_person', 'Email the person a birthday wish', '', b.staff_notify_person)
          + toggle('staff_notify_leads', 'Email admins and directors a heads-up', 'So somebody can arrange a card. Off by default.', b.staff_notify_leads))

      + '<div style="display:flex;align-items:center;gap:14px;justify-content:flex-end;">'
      + '<span id="b-status" style="font-size:13px;color:#1E8E60;"></span>'
      + '<button id="b-save" style="font-size:14px;font-weight:700;padding:11px 22px;border:0;border-radius:10px;background:linear-gradient(135deg,#0FA3B1,#1F6FB2);color:#fff;cursor:pointer;">Save changes</button></div>'
      + '</div>';

    var btn = main.querySelector('#b-save');
    btn.onclick = function () {
      var chk = function (id) { var e = main.querySelector('#b_' + id); return e ? e.checked : false; };
      var body = {
        enabled: chk('enabled'),
        days_ahead: +(main.querySelector('#b_days_ahead') || {}).value || 0,
        children_notify_guardians: chk('children_notify_guardians'),
        children_notify_educators: chk('children_notify_educators'),
        staff_notify_person: chk('staff_notify_person'),
        staff_notify_leads: chk('staff_notify_leads')
      };
      var st = main.querySelector('#b-status');
      btn.disabled = true; btn.textContent = 'Saving…'; st.textContent = '';
      Api.post('/admin/birthday-settings', body).then(function () {
        btn.disabled = false; btn.textContent = 'Save changes';
        st.style.color = '#1E8E60'; st.textContent = '✓ Saved';
        setTimeout(function () { st.textContent = ''; }, 2600);
        if (KT.Dom && KT.Dom.toast) KT.Dom.toast('Birthday settings saved', 'success');
      }).catch(function (e) {
        btn.disabled = false; btn.textContent = 'Save changes';
        st.style.color = '#BE4038'; st.textContent = 'Save failed: ' + ((e && e.message) || 'error');
      });
    };
  }

  ['agency_admin', 'platform_admin'].forEach(function (role) {
    Shell.registerScreen(role + ':birthday-settings', render);
  });
})(window);
