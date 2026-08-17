/* ============================================================
   KIDDIETRAC — Calendar settings (agency)
   GET/POST /admin/calendar-settings (agencies.settings JSON → "calendar").

   Which layers the calendar draws, the way Outlook lets you switch calendars on and
   off. Filtering happens server-side: a layer that is off is never sent, not merely
   not drawn. Everything defaults on — the switches are for quietening a busy view,
   not for making it usable in the first place.
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

  function toggle(key, label, hint, on) {
    return '<label style="display:flex;align-items:flex-start;gap:12px;padding:9px 0;cursor:pointer;">'
      + '<input id="c_' + key + '" type="checkbox" ' + (on ? 'checked' : '') + ' style="margin-top:3px;width:17px;height:17px;flex-shrink:0;">'
      + '<span><span style="font-size:14px;color:#334155;font-weight:600;">' + esc(label) + '</span>'
      + (hint ? '<span style="display:block;font-size:12.5px;color:#64748B;margin-top:2px;">' + esc(hint) + '</span>' : '') + '</span></label>';
  }

  function group(title, sub, inner) {
    return '<div style="margin:16px 0 0;padding:14px 0 0;border-top:1px solid #EEF2F7;">'
      + '<div style="font-weight:800;font-size:14px;color:#0D1B2A;">' + esc(title) + '</div>'
      + (sub ? '<div style="font-size:12.5px;color:#64748B;margin:2px 0 6px;">' + esc(sub) + '</div>' : '')
      + inner + '</div>';
  }

  var KEYS = ['show_closures', 'show_birthdays', 'show_child_birthdays', 'show_staff_birthdays',
              'show_absences', 'show_timeoff', 'show_vacations', 'show_pending'];

  async function render(main) {
    main.setAttribute('data-kt-pretty', '1');
    main.innerHTML = '<div style="padding:14px 24px;max-width:840px;"><div class="kt-page-hero"><h2>📅 Calendar settings</h2><p>Loading…</p></div></div>';

    var data = await Api.get('/admin/calendar-settings').catch(function (e) { return { __err: (e && e.message) || 'error' }; });
    if (data.__err) {
      main.innerHTML = '<div style="padding:14px 24px;max-width:840px;"><div class="kt-page-hero"><h2>📅 Calendar settings</h2></div>'
        + '<div class="kt-card" style="max-width:680px;"><div style="background:#FEF3C7;border:1px solid #FDE68A;color:#92400E;border-radius:10px;padding:14px;font-size:13px;">This page is available to <b>directors and agency administrators</b>.</div></div></div>';
      return;
    }

    var c = data.calendar || {};

    main.innerHTML = '<div style="padding:14px 24px;max-width:840px;">'
      + '<div class="kt-page-hero"><h2>📅 Calendar settings</h2><p>Choose what the calendar shows for ' + esc(data.agency_name || 'your agency') + '. Anything switched off is left out of the calendar entirely, not just hidden.</p></div>'

      + '<div class="kt-card" style="max-width:680px;">'
      + '<div class="kt-card-header"><h3 class="kt-card-title">📅 What the calendar shows</h3></div>'
      + '<p style="color:#64748B;font-size:12.5px;margin:0 0 6px;">Everything is on by default — these are all things your agency already records. Switch a layer off when it is making the view too busy to read.</p>'

      + group('Closures and holidays', 'Days your centres are closed.',
          toggle('show_closures', 'Show closures and holidays', 'Marked on the day, above everything else — a closed day changes what every other entry on it means.', c.show_closures))

      + group('Birthdays', 'Recurring each year, drawn on the day itself.',
          toggle('show_birthdays', 'Show birthdays', 'The master switch for both kinds below.', c.show_birthdays)
          + toggle('show_child_birthdays', 'Children’s birthdays', '', c.show_child_birthdays)
          + toggle('show_staff_birthdays', 'Staff birthdays', 'Only appears for people with a date of birth on their profile.', c.show_staff_birthdays))

      + group('Who is away', '',
          toggle('show_absences', 'Child absences', 'With the reason the parent or educator gave.', c.show_absences)
          + toggle('show_vacations', 'Family vacations', 'Booked holds on a child’s place.', c.show_vacations)
          + toggle('show_timeoff', 'Staff time off', 'Approved leave and vacation.', c.show_timeoff)
          + toggle('show_pending', 'Include requests that are still pending', 'Off means only settled leave is shown. On is useful when planning cover.', c.show_pending))

      + '<div style="display:flex;align-items:center;gap:14px;justify-content:flex-end;margin-top:16px;">'
      + '<span id="c-status" style="font-size:13px;color:#1E8E60;"></span>'
      + '<button id="c-save" style="font-size:14px;font-weight:700;padding:10px 20px;border:0;border-radius:10px;background:linear-gradient(135deg,#0FA3B1,#1F6FB2);color:#fff;cursor:pointer;">Save changes</button></div>'
      + '</div></div>';

    // The two birthday sub-switches only mean anything while the master is on.
    var master = main.querySelector('#c_show_birthdays');
    var subs = ['c_show_child_birthdays', 'c_show_staff_birthdays'].map(function (id) { return main.querySelector('#' + id); });
    function syncSubs() {
      subs.forEach(function (el) {
        if (!el) return;
        el.disabled = !master.checked;
        var row = el.closest('label');
        if (row) row.style.opacity = master.checked ? '1' : '.45';
      });
    }
    master.addEventListener('change', syncSubs);
    syncSubs();

    var btn = main.querySelector('#c-save');
    btn.onclick = function () {
      var body = {};
      KEYS.forEach(function (k) {
        var el = main.querySelector('#c_' + k);
        body[k] = el ? el.checked : true;
      });
      var st = main.querySelector('#c-status');
      btn.disabled = true; btn.textContent = 'Saving…'; st.textContent = '';
      Api.post('/admin/calendar-settings', body).then(function () {
        btn.disabled = false; btn.textContent = 'Save changes';
        st.style.color = '#1E8E60'; st.textContent = '✓ Saved';
        setTimeout(function () { st.textContent = ''; }, 2600);
        if (KT.Dom && KT.Dom.toast) KT.Dom.toast('Calendar settings saved', 'success');
      }).catch(function (e) {
        btn.disabled = false; btn.textContent = 'Save changes';
        st.style.color = '#BE4038'; st.textContent = 'Save failed: ' + ((e && e.message) || 'error');
      });
    };
  }

  KT.CalendarSettings = { render: render };

  ['agency_admin', 'platform_admin', 'centre_director'].forEach(function (role) {
    Shell.registerScreen(role + ':calendar-settings', render);
  });
})(window);
