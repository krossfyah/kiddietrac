/* ============================================================
   KIDDIETRAC — Auto sign-off (agency settings)
   GET/POST /admin/auto-signoff (agencies.settings JSON → "auto_signoff").

   Staff and children are configured separately because they are different problems:
   a forgotten clock-out corrupts payroll, a child left checked in corrupts ratio and
   attendance. Both are off until somebody turns them on.
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

  function toggle(key, label, hint, on) {
    return '<label style="display:flex;align-items:flex-start;gap:12px;padding:10px 0;cursor:pointer;">'
      + '<input id="a_' + key + '" type="checkbox" ' + (on ? 'checked' : '') + ' style="margin-top:3px;width:17px;height:17px;flex-shrink:0;">'
      + '<span><span style="font-size:14px;color:#334155;font-weight:600;">' + esc(label) + '</span>'
      + (hint ? '<span style="display:block;font-size:12.5px;color:#64748B;margin-top:2px;">' + esc(hint) + '</span>' : '') + '</span></label>';
  }

  function group(title, sub, inner) {
    return '<div style="margin:16px 0 0;padding:14px 0 0;border-top:1px solid #EEF2F7;">'
      + '<div style="font-weight:800;font-size:14px;color:#0D1B2A;">' + esc(title) + '</div>'
      + (sub ? '<div style="font-size:12.5px;color:#64748B;margin:2px 0 6px;">' + esc(sub) + '</div>' : '')
      + inner + '</div>';
  }

  function timeRow(key, label, val) {
    return '<div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;padding:6px 0 2px;">'
      + '<label for="a_' + key + '" style="font-size:14px;color:#334155;font-weight:600;">' + esc(label) + '</label>'
      + '<input id="a_' + key + '" type="time" value="' + esc(val || '') + '" style="' + INP + 'width:auto;">'
      + '</div>';
  }

  async function render(main) {
    main.setAttribute('data-kt-pretty', '1');
    main.innerHTML = '<div style="padding:14px 24px;max-width:840px;"><div class="kt-page-hero"><h2>⏱️ Auto sign-off</h2><p>Loading…</p></div></div>';

    var data = await Api.get('/admin/auto-signoff').catch(function (e) { return { __err: (e && e.message) || 'error' }; });
    if (data.__err) {
      main.innerHTML = '<div style="padding:14px 24px;max-width:840px;"><div class="kt-page-hero"><h2>⏱️ Auto sign-off</h2></div>'
        + '<div class="kt-card" style="max-width:680px;"><div style="background:#FEF3C7;border:1px solid #FDE68A;color:#92400E;border-radius:10px;padding:14px;font-size:13px;">This page is available to <b>directors and agency administrators</b>.</div></div></div>';
      return;
    }

    var a = data.auto_signoff || {};
    var openNow = (data.pending && data.pending.open_punches) || 0;

    // Say what is sitting there unresolved right now. "20 punches are open" is the
    // difference between a switch somebody understands and one they guess at.
    var pendingNote = openNow
      ? '<div style="background:#FEF3C7;border:1px solid #FDE68A;color:#92400E;border-radius:10px;padding:12px 14px;font-size:13px;margin:0 0 12px;">'
        + '<b>' + esc(String(openNow)) + ' staff punch' + (openNow === 1 ? ' is' : 'es are') + ' currently open.</b> '
        + 'Some may be shifts still running. Any that are not will be closed the next time this runs, once you switch it on.</div>'
      : '';

    main.innerHTML = '<div style="padding:14px 24px;max-width:840px;">'
      + '<div class="kt-page-hero"><h2>⏱️ Auto sign-off</h2><p>Close shifts and days that somebody forgot to close, so reports are not thrown out by a punch left running. Staff and children are set separately.</p></div>'

      + '<div class="kt-card" style="max-width:680px;">'
      + '<div class="kt-card-header"><h3 class="kt-card-title">⏱️ Auto sign-off for ' + esc(data.agency_name || 'your agency') + '</h3></div>'
      + pendingNote
      + '<p style="color:#64748B;font-size:12.5px;margin:0 0 6px;">Anything closed automatically is stamped at the sign-off time <b>on the day it started</b> — never at the moment the job runs — so a punch left open overnight cannot turn into a 30-hour shift. Every automatic closure is marked as such, so a real clock-out is still tellable from one the system wrote.</p>'

      + group('Staff', 'Educators and other staff who forget to clock out. Affects timesheets and payroll.',
          toggle('staff_enabled', 'Close forgotten clock-outs automatically', '', a.staff_enabled)
          + timeRow('staff_at', 'Sign off at', a.staff_at)
          + '<div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;padding:6px 0 2px;">'
          + '<label for="a_staff_max_hours" style="font-size:14px;color:#334155;font-weight:600;">Never record a shift longer than</label>'
          + '<input id="a_staff_max_hours" type="number" min="1" max="24" value="' + esc(String(a.staff_max_hours || 14)) + '" style="' + INP + 'width:76px;text-align:right;">'
          + '<span style="font-size:12.5px;color:#64748B;">hours — used for late shifts that start after the sign-off time.</span></div>')

      + group('Children', 'Children left checked in at the end of the day. Affects attendance and ratio.',
          toggle('children_enabled', 'Check out children automatically', 'Recorded as an automatic check-out, not a signed one.', a.children_enabled)
          + timeRow('children_at', 'Check out at', a.children_at))

      + '<div style="display:flex;align-items:center;gap:14px;justify-content:flex-end;margin-top:16px;">'
      + '<span id="a-status" style="font-size:13px;color:#1E8E60;"></span>'
      + '<button id="a-save" style="font-size:14px;font-weight:700;padding:10px 20px;border:0;border-radius:10px;background:linear-gradient(135deg,#0FA3B1,#1F6FB2);color:#fff;cursor:pointer;">Save changes</button></div>'
      + '</div></div>';

    var btn = main.querySelector('#a-save');
    btn.onclick = function () {
      var val = function (id) { var e = main.querySelector('#a_' + id); return e ? e.value : null; };
      var chk = function (id) { var e = main.querySelector('#a_' + id); return e ? e.checked : false; };
      var body = {
        staff_enabled: chk('staff_enabled'),
        staff_at: val('staff_at') || '19:00',
        staff_max_hours: +val('staff_max_hours') || 14,
        children_enabled: chk('children_enabled'),
        children_at: val('children_at') || '18:30'
      };
      var st = main.querySelector('#a-status');
      btn.disabled = true; btn.textContent = 'Saving…'; st.textContent = '';
      Api.post('/admin/auto-signoff', body).then(function () {
        btn.disabled = false; btn.textContent = 'Save changes';
        st.style.color = '#1E8E60'; st.textContent = '✓ Saved';
        setTimeout(function () { st.textContent = ''; }, 2600);
        if (KT.Dom && KT.Dom.toast) KT.Dom.toast('Auto sign-off saved', 'success');
      }).catch(function (e) {
        btn.disabled = false; btn.textContent = 'Save changes';
        st.style.color = '#BE4038'; st.textContent = 'Save failed: ' + ((e && e.message) || 'error');
      });
    };
  }

  KT.AutoSignOff = { render: render };

  ['agency_admin', 'platform_admin', 'centre_director'].forEach(function (role) {
    Shell.registerScreen(role + ':auto-signoff', render);
  });
})(window);
