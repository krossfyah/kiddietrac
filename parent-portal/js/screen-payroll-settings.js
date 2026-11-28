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

      + '<label style="display:flex;align-items:flex-start;gap:12px;padding:14px 0 0;cursor:pointer;">'
      + '<input id="p_announce" type="checkbox" ' + (p.announce_payslips !== false ? 'checked' : '')
      + ' data-kt-switch="1" style="margin-top:2px;flex-shrink:0;">'
      + '<span><span style="font-size:14px;color:#334155;font-weight:600;">Announce new payslips to staff</span>'
      + '<span style="display:block;font-size:12.5px;color:#64748B;margin-top:2px;">'
      + 'Each new payslip also appears in that person&rsquo;s Announcements, just for them, with a View payslip button.</span></span></label>'

      /* PAYSLIP EMAILS (2026-10-31): a new payslip - from the iLearn sync, Generate payroll or manual payroll - is
         emailed to that staff member's own account address (App\Support\PayslipNotices, every 2 minutes). */
      + group('Payslip emails', 'When a new payslip arrives, email it to the staff member at the address on their account.',
          '<label style="display:flex;align-items:flex-start;gap:12px;cursor:pointer;">'
          + '<input id="p_email" type="checkbox" ' + (p.email_payslips !== false ? 'checked' : '') + ' data-kt-switch="1" style="margin-top:2px;flex-shrink:0;">'
          + '<span><span style="font-size:14px;color:#334155;font-weight:600;">Email payslips to staff when they arrive</span>'
          + '<span style="display:block;font-size:12.5px;color:#64748B;margin-top:2px;">One email per payslip, with the net pay and a View in the app button.</span></span></label>'
          + '<label style="display:flex;align-items:flex-start;gap:12px;padding:12px 0 0;cursor:pointer;">'
          + '<input id="p_email_pdf" type="checkbox" ' + (p.email_attach_pdf !== false ? 'checked' : '') + ' data-kt-switch="1" style="margin-top:2px;flex-shrink:0;">'
          + '<span><span style="font-size:14px;color:#334155;font-weight:600;">Attach the payslip PDF</span>'
          + '<span style="display:block;font-size:12.5px;color:#64748B;margin-top:2px;">Password-protected payslips say how the password is made, never the password itself.</span></span></label>'
          + '<label style="display:flex;align-items:flex-start;gap:12px;padding:12px 0 0;cursor:pointer;">'
          + '<input id="p_email_upd" type="checkbox" ' + (p.email_updated_notices ? 'checked' : '') + ' data-kt-switch="1" style="margin-top:2px;flex-shrink:0;">'
          + '<span><span style="font-size:14px;color:#334155;font-weight:600;">Send updated-payslip notices</span>'
          + '<span style="display:block;font-size:12.5px;color:#64748B;margin-top:2px;">Email again when the net pay on a payslip already sent changes.</span></span></label>'
          + '<div style="display:flex;flex-wrap:wrap;gap:10px;align-items:center;padding:12px 0 0;">'
          + '<span style="font-size:14px;color:#334155;font-weight:600;">Send</span>'
          + '<select id="p_email_timing" style="' + INP + 'height:32px;padding:4px 10px;">'
          + '<option value="immediate"' + (p.email_timing !== 'batch' ? ' selected' : '') + '>As soon as they arrive</option>'
          + '<option value="batch"' + (p.email_timing === 'batch' ? ' selected' : '') + '>Once a day at</option></select>'
          + '<input id="p_email_time" type="time" value="' + esc(p.email_batch_time || '08:00') + '" style="' + INP + 'height:32px;padding:4px 10px;">'
          + '</div>'
          + '<div style="font-size:14px;color:#334155;font-weight:600;padding:12px 0 4px;">Also send a hidden copy to</div>'
          + '<div id="p_email_bcc" style="display:flex;flex-direction:column;gap:6px;">'
          + ((data.bcc_choices || []).length ? (data.bcc_choices || []).map(function (c) {
              var on = (p.email_bcc_user_ids || []).indexOf(c.id) !== -1;
              return '<label style="display:flex;gap:10px;align-items:center;font-size:13.5px;color:#334155;cursor:pointer;">'
                + '<input type="checkbox" data-bcc="' + c.id + '"' + (on ? ' checked' : '') + '> '
                + esc(c.name) + ' <span style="color:#64748B;font-size:12.5px;">' + esc(c.role) + ' &middot; ' + esc(c.email) + '</span></label>';
            }).join('') : '<span style="font-size:12.5px;color:#64748B;">No agency admins or centre directors to copy.</span>')
          + '</div>')

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
        announce_payslips: main.querySelector('#p_announce').checked,
        email_payslips: main.querySelector('#p_email').checked,
        email_attach_pdf: main.querySelector('#p_email_pdf').checked,
        email_updated_notices: main.querySelector('#p_email_upd').checked,
        email_timing: main.querySelector('#p_email_timing').value,
        email_batch_time: main.querySelector('#p_email_time').value || '08:00',
        email_bcc_user_ids: [].map.call(main.querySelectorAll('#p_email_bcc input[data-bcc]:checked'), function (i) { return +i.getAttribute('data-bcc'); }),
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

    /* Ontario workforce funding (WEG + CWELCC), 2026-10-13 — its own card under the pay
       schedule. Only Ontario agencies get one back from the API; elsewhere nothing shows. */
    if (KT.WorkforceFunding && KT.WorkforceFunding.paintSettings) {
      try { KT.WorkforceFunding.paintSettings(main); } catch (e) {}
    }
    paintBreaks(main);
    paintTerms(main, p, data.payslip_terms_default || '');
  }

  /* PAYSLIP TERMS AND CONDITIONS (2026-11-27, App\Support\Payroll\PayslipPdf): printed at the foot of every payslip PDF,
     above the NON-NEGOTIABLE line. Empty = the standard statements, printed in each reader's language (English, French,
     Spanish, Hindi). {days} = the report window below. Saved with the button (POST /admin/payroll-settings). */
  function paintTerms(main, p, dflt) {
    var box = document.createElement('div');
    box.className = 'kt-card';
    box.style.cssText = 'max-width:680px;margin-top:16px;';
    box.setAttribute('data-kt-self-live', '1');
    var custom = (p.payslip_terms || '').trim();
    var days = p.payslip_terms_days || 30;
    box.innerHTML = '<div class="kt-card-header"><h3 class="kt-card-title">📜 Payslip terms and conditions</h3></div>'
      + '<p style="color:#64748B;font-size:13px;margin:0 0 4px;line-height:1.6;">Printed at the bottom of every payslip PDF, one statement per line, '
      + 'above the <b>NON-NEGOTIABLE &mdash; THIS IS NOT A CHEQUE</b> line. Leave the standard wording and each person gets it in their own language '
      + '(English, French, Spanish or Hindi). <code>{days}</code> becomes the number of days below.</p>'
      + '<div style="display:flex;flex-wrap:wrap;gap:10px;align-items:center;padding:10px 0 0;">'
      + '<span style="font-size:14px;color:#334155;font-weight:600;">Staff report a pay error within</span>'
      + '<input id="pt_days" type="number" min="1" max="365" value="' + esc(days) + '" style="' + INP + 'width:80px;height:32px;padding:4px 10px;">'
      + '<span style="font-size:14px;color:#334155;">days of the pay date</span></div>'
      + '<textarea id="pt_text" rows="7" spellcheck="true" style="' + INP + 'width:100%;margin-top:12px;min-height:150px;font-size:13px;line-height:1.5;">'
      + esc(custom || dflt) + '</textarea>'
      + '<div style="margin-top:12px;display:flex;align-items:center;gap:12px;flex-wrap:wrap;">'
      + '<button id="pt-save" class="kt-btn kt-btn-primary" type="button">Save terms</button>'
      + '<button id="pt-reset" class="kt-btn" type="button"' + (custom ? '' : ' style="display:none;"') + '>Use the standard wording</button>'
      + '<span id="pt-status" style="font-size:13px;"></span></div>';
    main.appendChild(box);
    var st = box.querySelector('#pt-status');
    function save(text) {
      st.style.color = '#64748B'; st.textContent = 'Saving…';
      Api.post('/admin/payroll-settings', {
        payslip_terms: text,
        payslip_terms_days: Math.max(1, Math.min(365, parseInt(box.querySelector('#pt_days').value, 10) || 30)),
      }).then(function (res) {
        var saved = ((res && res.payroll && res.payroll.payslip_terms) || '').trim();
        box.querySelector('#pt-reset').style.display = saved ? '' : 'none';
        if (!saved) box.querySelector('#pt_text').value = dflt;
        st.style.color = '#1E8E60'; st.textContent = saved ? '✓ Saved - your wording is on every new payslip PDF' : '✓ Saved - the standard wording, in each person\'s language';
        setTimeout(function () { st.textContent = ''; }, 3200);
      }).catch(function (e) { st.style.color = '#BE4038'; st.textContent = (e && e.message) || 'Save failed'; });
    }
    box.querySelector('#pt-save').onclick = function () { save(box.querySelector('#pt_text').value); };
    box.querySelector('#pt-reset').onclick = function () { save(''); };
  }

  /* LUNCH AND BREAKS (2026-11-05, App\Support\StaffBreaks): whether an educator's lunch and
     short breaks are paid. Unpaid: the time away is left out of their hours. Paid: it stays
     inside the shift. Saved on change; applies to breaks started after the change. */
  function paintBreaks(main) {
    var box = document.createElement('div');
    box.className = 'kt-card';
    box.style.cssText = 'max-width:680px;margin-top:16px;';
    box.setAttribute('data-kt-self-live', '1');   // saving must not redraw the whole screen (kt-live)
    box.innerHTML = '<div class="kt-card-header"><h3 class="kt-card-title">🍽️ Lunch and breaks</h3></div>'
      + '<p style="color:#64748B;font-size:13px;margin:0 0 4px;line-height:1.6;">Educators tap <b>Lunch</b> or <b>Short break</b> on the clock. '
      + 'They come off the floor and out of ratios without ending their day, then tap <b>I&rsquo;m back</b>.</p>'
      + '<div id="sb-body"><p style="color:#64748B;font-size:12.5px;margin:10px 0 0;">Loading…</p></div>';
    main.appendChild(box);
    Api.get('/admin/staff-breaks').then(function (d) {
      var st = (d && d.settings) || { lunch_paid: false, break_paid: true };
      var mins = function (id, v, lo, hi) {
        return '<input id="' + id + '" type="number" min="' + lo + '" max="' + hi + '" value="' + Number(v) + '"'
          + ' style="width:68px;height:30px;box-sizing:border-box;padding:0 8px;border:1px solid #CBD5E1;border-radius:8px;font-size:13.5px;">';
      };
      var row = function (id, on, title, sub) {
        return '<label style="display:flex;align-items:flex-start;gap:12px;padding:14px 0 0;cursor:pointer;">'
          + '<input id="' + id + '" type="checkbox" ' + (on ? 'checked' : '') + ' data-kt-switch="1" style="margin-top:2px;flex-shrink:0;">'
          + '<span><span style="font-size:14px;color:#334155;font-weight:600;">' + title + '</span>'
          + '<span style="display:block;font-size:12.5px;color:#64748B;margin-top:2px;">' + sub + '</span></span></label>';
      };
      var body = box.querySelector('#sb-body');
      body.innerHTML = row('sb_lunch', st.lunch_paid, 'Lunch is paid', 'Off: lunch is taken off the hours worked (the usual unpaid meal break).')
        + row('sb_break', st.break_paid, 'Short breaks are paid', 'On: a short break stays on the clock.')
        /* Running over (2026-11-06): remind the educator at the limit, then tell the managers (BreakOverrunCommand). */
        + '<div style="margin-top:16px;border-top:1px solid #E2E8F0;padding-top:4px;">'
        + row('sb_over', st.overrun_enabled !== false, 'Remind when a lunch or break runs long', 'The educator is reminded at the limit. If they still aren\'t back, the people ticked below are alerted.')
        + '<div id="sb-over-opts" style="padding-left:30px;">'
        + '<div style="display:flex;flex-wrap:wrap;gap:8px 18px;align-items:center;margin-top:10px;font-size:13.5px;color:#334155;">'
        + '<label style="display:flex;align-items:center;gap:7px;">Lunch limit ' + mins('sb_ll', st.lunch_limit || 30, 5, 240) + ' min</label>'
        + '<label style="display:flex;align-items:center;gap:7px;">Break limit ' + mins('sb_bl', st.break_limit || 15, 5, 120) + ' min</label>'
        + '<label style="display:flex;align-items:center;gap:7px;">Alert managers ' + mins('sb_esc', st.overrun_escalate_after == null ? 10 : st.overrun_escalate_after, 0, 120) + ' min after that</label>'
        + '</div>'
        + row('sb_dir', st.overrun_notify_directors !== false, 'Alert the centre\'s directors', '')
        + row('sb_adm', st.overrun_notify_admins !== false, 'Alert the agency\'s admins', '')
        + '</div></div>'
        + '<div id="sb-status" style="font-size:13px;margin-top:10px;min-height:18px;"></div>';
      var save = function () {
        var num = function (id, lo, hi, dflt) { var v = parseInt(body.querySelector(id).value, 10); return isNaN(v) ? dflt : Math.max(lo, Math.min(hi, v)); };
        var out = { lunch_paid: body.querySelector('#sb_lunch').checked, break_paid: body.querySelector('#sb_break').checked,
          overrun_enabled: body.querySelector('#sb_over').checked,
          lunch_limit: num('#sb_ll', 5, 240, 30), break_limit: num('#sb_bl', 5, 120, 15), overrun_escalate_after: num('#sb_esc', 0, 120, 10),
          overrun_notify_directors: body.querySelector('#sb_dir').checked, overrun_notify_admins: body.querySelector('#sb_adm').checked };
        syncOver();
        var s = body.querySelector('#sb-status');
        s.style.color = '#64748B'; s.textContent = 'Saving…';
        Api.post('/admin/staff-breaks', out).then(function () {
          s.style.color = '#1E8E60'; s.textContent = '✓ Saved - applies to breaks started from now on';
          setTimeout(function () { s.textContent = ''; }, 3000);
        }).catch(function (e) { s.style.color = '#BE4038'; s.textContent = (e && e.message) || 'Save failed'; });
      };
      var syncOver = function () { body.querySelector('#sb-over-opts').style.opacity = body.querySelector('#sb_over').checked ? '' : '.5'; };
      syncOver();
      ['#sb_lunch', '#sb_break', '#sb_over', '#sb_ll', '#sb_bl', '#sb_esc', '#sb_dir', '#sb_adm'].forEach(function (id) {
        body.querySelector(id).addEventListener('change', save);
      });
    }).catch(function () { box.remove(); });
  }

  KT.PayrollSettings = { render: render };

  ['agency_admin', 'platform_admin'].forEach(function (role) {
    Shell.registerScreen(role + ':payroll-settings', render);
  });
})(window);
