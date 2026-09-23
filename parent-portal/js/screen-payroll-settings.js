/* ============================================================
   KIDDIETRAC — Pay schedule (agency settings)
   GET/POST /admin/payroll-settings (agencies.settings JSON → "payroll").

   Drives the "Next payroll" card on the agency overview. It stays off, and the card
   says "Not set", until somebody fills this in — an agency that pays fortnightly must
   never be shown a made-up monthly date.

   The anchor is a REAL payday rather than a day-of-month, because fortnightly pay does
   not land on the same date each month; the only way to know which Friday is a payday
   is to count from one that was.
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

  var CADENCES = [
    { key: 'weekly', label: 'Every week', hint: 'Same day each week' },
    { key: 'biweekly', label: 'Every two weeks', hint: 'Same day, every other week' },
    { key: 'semimonthly', label: 'Twice a month', hint: 'Two fixed dates each month' },
    { key: 'monthly', label: 'Once a month', hint: 'Same date each month' },
  ];

  function group(title, sub, inner) {
    return '<div style="margin:16px 0 0;padding:14px 0 0;border-top:1px solid #EEF2F7;">'
      + '<div style="font-weight:800;font-size:14px;color:#0D1B2A;">' + esc(title) + '</div>'
      + (sub ? '<div style="font-size:12.5px;color:#64748B;margin:2px 0 8px;">' + esc(sub) + '</div>' : '')
      + inner + '</div>';
  }

  function card(inner) {
    return '<div class="kt-card" style="max-width:680px;">'
      + '<div class="kt-card-header"><h3 class="kt-card-title">📆 Pay schedule</h3></div>'
      + inner + '</div>';
  }

  async function render(main) {
    main.setAttribute('data-kt-pretty', '1');
    main.innerHTML = card('<p style="color:#64748B;font-size:12.5px;margin:0;">Loading…</p>');

    var data = await Api.get('/admin/payroll-settings').catch(function (e) {
      return { __err: (e && e.message) || 'error' };
    });
    if (data.__err) {
      main.innerHTML = card('<div style="background:#FEF3C7;border:1px solid #FDE68A;color:#92400E;'
        + 'border-radius:10px;padding:14px;font-size:13px;">This section is available to '
        + '<b>agency administrators</b>.</div>');
      return;
    }

    var p = data.payroll || {};
    var next = data.next_payday || null;

    var body = ''
      + '<p style="color:#64748B;font-size:13px;margin:0 0 4px;line-height:1.6;">'
      + 'Tells the overview when payroll is next due. Nothing here sends money or files '
      + 'anything &mdash; it is the date the team plans around.</p>'

      + '<label style="display:flex;align-items:flex-start;gap:12px;padding:14px 0 0;cursor:pointer;">'
      + '<input id="p_enabled" type="checkbox" ' + (p.enabled ? 'checked' : '')
      + ' data-kt-switch="1" style="margin-top:2px;flex-shrink:0;">'
      + '<span><span style="font-size:14px;color:#334155;font-weight:600;">Show the next payroll date</span>'
      + '<span style="display:block;font-size:12.5px;color:#64748B;margin-top:2px;">'
      + 'Off means the overview card reads &ldquo;Not set&rdquo; rather than guessing.</span></span></label>'

      + group('How often', 'Pick the rhythm you actually pay on.',
          '<select id="p_cadence" style="' + INP + 'width:100%;">'
          + CADENCES.map(function (c) {
            return '<option value="' + c.key + '"' + (p.cadence === c.key ? ' selected' : '') + '>'
              + esc(c.label) + ' — ' + esc(c.hint) + '</option>';
          }).join('') + '</select>')

      + '<div id="p_anchor_wrap">'
      + group('A recent payday',
          'Give one date payroll actually landed on. Every future date is counted from it, '
          + 'which is the only way a fortnightly cycle can be worked out.',
          '<input id="p_anchor" type="date" value="' + esc((p.anchor_date || '')) + '" style="' + INP + 'width:100%;">')
      + '</div>'

      + '<div id="p_semi_wrap">'
      + group('Which dates', 'Two dates each month. Use 31 for &ldquo;the last day&rdquo; &mdash; short months clamp to their end.',
          '<div style="display:flex;gap:10px;">'
          + '<input id="p_semi1" type="number" min="1" max="31" value="'
          + esc(((p.semimonthly_days || [15, 31])[0]) || 15) + '" style="' + INP + 'flex:1;">'
          + '<input id="p_semi2" type="number" min="1" max="31" value="'
          + esc(((p.semimonthly_days || [15, 31])[1]) || 31) + '" style="' + INP + 'flex:1;">'
          + '</div>')
      + '</div>'

      + '<div id="p_preview" style="margin:16px 0 0;padding:13px 15px;border-radius:11px;'
      + 'background:' + (next ? '#F0F9FF' : '#F8FAFC') + ';border:1px solid '
      + (next ? '#BAE6FD' : '#E2E8F0') + ';font-size:14px;color:#0F172A;">'
      + (next
        ? '<strong>Next payroll:</strong> ' + esc(next.long) + ' &middot; ' + esc(next.when)
        : '<span style="color:#64748B;">No date yet &mdash; switch it on and fill in the schedule.</span>')
      + '</div>'

      + '<div style="margin-top:18px;display:flex;align-items:center;gap:12px;">'
      + '<button id="p-save" class="kt-btn kt-btn-primary" type="button">Save changes</button>'
      + '<span id="p-status" style="font-size:13px;"></span></div>';

    main.innerHTML = card(body);

    var cadenceEl = main.querySelector('#p_cadence');
    var anchorWrap = main.querySelector('#p_anchor_wrap');
    var semiWrap = main.querySelector('#p_semi_wrap');

    /* Only one of the two matters at a time. Showing a "recent payday" box to somebody
       paying on the 15th and the 31st invites them to fill in something that is then
       ignored. */
    function syncCadence() {
      var semi = cadenceEl.value === 'semimonthly';
      semiWrap.style.display = semi ? '' : 'none';
      anchorWrap.style.display = semi ? 'none' : '';
    }
    cadenceEl.addEventListener('change', syncCadence);
    syncCadence();

    main.querySelector('#p-save').onclick = function () {
      var btn = main.querySelector('#p-save');
      var st = main.querySelector('#p-status');
      var semi = cadenceEl.value === 'semimonthly';

      var payload = {
        enabled: main.querySelector('#p_enabled').checked,
        cadence: cadenceEl.value,
      };
      if (semi) {
        payload.semimonthly_days = [
          +main.querySelector('#p_semi1').value || 15,
          +main.querySelector('#p_semi2').value || 31,
        ];
      } else {
        payload.anchor_date = main.querySelector('#p_anchor').value || null;
      }

      btn.disabled = true; btn.textContent = 'Saving…'; st.textContent = '';
      Api.post('/admin/payroll-settings', payload).then(function (res) {
        btn.disabled = false; btn.textContent = 'Save changes';
        st.style.color = '#1E8E60'; st.textContent = '✓ Saved';
        setTimeout(function () { st.textContent = ''; }, 2600);

        // Show the computed date straight back, so it is obvious whether the schedule
        // came out the way it was meant to before anyone relies on it.
        var n = res && res.next_payday;
        var box = main.querySelector('#p_preview');
        if (box) {
          box.style.background = n ? '#F0F9FF' : '#F8FAFC';
          box.style.borderColor = n ? '#BAE6FD' : '#E2E8F0';
          box.innerHTML = n
            ? '<strong>Next payroll:</strong> ' + esc(n.long) + ' &middot; ' + esc(n.when)
            : '<span style="color:#64748B;">No date yet &mdash; switch it on and fill in the schedule.</span>';
        }
        if (KT.Dom && KT.Dom.toast) KT.Dom.toast('Pay schedule saved', 'success');
      }).catch(function (e) {
        btn.disabled = false; btn.textContent = 'Save changes';
        st.style.color = '#BE4038'; st.textContent = (e && e.message) || 'Save failed';
      });
    };
  }

  KT.PayrollSettings = { render: render };

  ['agency_admin', 'platform_admin'].forEach(function (role) {
    Shell.registerScreen(role + ':payroll-settings', render);
  });
})(window);
