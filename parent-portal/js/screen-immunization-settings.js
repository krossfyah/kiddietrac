/* ═══════════════════════════════════════════════════════════════════
   KiddieTrac — Immunization reminders (Settings → Email → Immunization).

   A standing nudge to the people who can actually chase a parent, listing the
   children whose immunization record is missing or out of date. Off until an
   agency turns it on: a recurring email that starts sending the moment the code
   ships is how a team learns to filter the sender.

   Backed by /admin/immunization-reminders (GET/POST).
   ═══════════════════════════════════════════════════════════════════ */
(function (window) {
  'use strict';
  var KT = (window.KT = window.KT || {});

  function API() { return KT.API_BASE || 'https://api.kiddietrac.com/api/v1'; }

  function api(path, method, body) {
    var tok = null;
    try { tok = sessionStorage.getItem('kt_token') || localStorage.getItem('kt_token'); } catch (e) {}
    var h = { 'Authorization': 'Bearer ' + tok, 'Accept': 'application/json' };
    var aa = null;
    try { aa = sessionStorage.getItem('kt_active_agency_id'); } catch (e) {}
    if (aa) { h['X-Active-Agency-Id'] = aa; }
    if (body) { h['Content-Type'] = 'application/json'; }
    return fetch(API() + path, {
      method: method || 'GET', headers: h, body: body ? JSON.stringify(body) : undefined,
    }).then(function (r) {
      return r.json().then(function (j) {
        if (!r.ok) { throw new Error(j.message || ('HTTP ' + r.status)); }
        return j;
      });
    });
  }

  function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"]/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c];
    });
  }

  var DAYS = [[1, 'Monday'], [2, 'Tuesday'], [3, 'Wednesday'], [4, 'Thursday'],
    [5, 'Friday'], [6, 'Saturday'], [7, 'Sunday']];

  function render(pane) {
    pane.innerHTML = '<div class="kt-card" style="max-width:680px;color:#64748B;">Loading immunization reminders…</div>';
    api('/admin/immunization-reminders').then(function (d) { paint(pane, d); })
      .catch(function (e) {
        pane.innerHTML = '<div class="kt-card" style="max-width:680px;color:#B91C1C;">Could not load: ' + esc(e.message) + '</div>';
      });
  }

  function paint(pane, d) {
    var r = d.reminders || {};
    var outstanding = (d.preview && d.preview.count) || 0;

    var lbl = 'display:block;font-size:13px;font-weight:700;color:#334155;margin:14px 0 4px;';
    var inp = 'width:100%;box-sizing:border-box;padding:9px 11px;border:1px solid #DCE3EC;'
      + 'border-radius:9px;font-size:13.5px;background:#fff;color:#0F172A;font-family:inherit;';
    var row = 'display:flex;align-items:center;gap:10px;margin:10px 0;font-size:13.5px;color:#334155;';

    function check(id, on, label, hint) {
      return '<label style="' + row + 'cursor:pointer;">'
        + '<input type="checkbox" id="' + id + '"' + (on ? ' checked' : '')
        + ' style="width:17px;height:17px;cursor:pointer;flex:none;">'
        + '<span><strong style="font-weight:600;">' + esc(label) + '</strong>'
        + (hint ? '<span style="display:block;color:#64748B;font-size:12.5px;">' + esc(hint) + '</span>' : '')
        + '</span></label>';
    }

    pane.innerHTML =
      '<div class="kt-card" style="max-width:680px;">'
      + '<h3 style="margin:0 0 4px;font-size:16px;">Immunization reminders</h3>'
      + '<p style="margin:0 0 4px;color:#64748B;font-size:13px;">'
      + 'Emails your team a list of the children whose immunization record is missing or '
      + 'out of date, so someone can ask the parent for it.</p>'
      + (outstanding
        ? '<div style="margin:12px 0 4px;padding:10px 12px;border-radius:9px;background:#FEF3C7;color:#92400E;font-size:13px;font-weight:600;">'
          + outstanding + (outstanding === 1 ? ' child is' : ' children are')
          + ' outstanding right now.</div>'
        : '<div style="margin:12px 0 4px;padding:10px 12px;border-radius:9px;background:#DCFCE7;color:#166534;font-size:13px;font-weight:600;">'
          + 'Nothing outstanding right now — no reminder would be sent.</div>')

      + check('imm-enabled', !!r.enabled, 'Send these reminders',
          'Nothing is sent while this is off. A reminder is skipped when nothing is outstanding.')

      + '<div id="imm-body" style="' + (r.enabled ? '' : 'opacity:.5;pointer-events:none;') + '">'

      + '<label style="' + lbl + '">How often</label>'
      + '<select id="imm-freq" style="' + inp + '">'
      + '<option value="weekly"' + (r.frequency !== 'monthly' ? ' selected' : '') + '>Weekly</option>'
      + '<option value="monthly"' + (r.frequency === 'monthly' ? ' selected' : '') + '>Monthly</option>'
      + '</select>'

      + '<div id="imm-weekly" style="' + (r.frequency === 'monthly' ? 'display:none;' : '') + '">'
      + '<label style="' + lbl + '">Day of the week</label>'
      + '<select id="imm-dow" style="' + inp + '">'
      + DAYS.map(function (x) {
        return '<option value="' + x[0] + '"' + (Number(r.day_of_week) === x[0] ? ' selected' : '') + '>' + x[1] + '</option>';
      }).join('')
      + '</select></div>'

      + '<div id="imm-monthly" style="' + (r.frequency === 'monthly' ? '' : 'display:none;') + '">'
      + '<label style="' + lbl + '">Day of the month</label>'
      + '<input id="imm-dom" type="number" min="1" max="28" value="' + esc(r.day_of_month || 1) + '" style="' + inp + '">'
      + '<span style="display:block;color:#64748B;font-size:12px;margin-top:4px;">1–28, so every month has one.</span>'
      + '</div>'

      + '<label style="' + lbl + '">Time of day</label>'
      + '<input id="imm-time" type="time" value="' + esc(r.send_time || '09:00') + '" style="' + inp + '">'
      + '<span style="display:block;color:#64748B;font-size:12px;margin-top:4px;">Your agency\'s local time.</span>'

      + '<label style="' + lbl + '">Who gets it</label>'
      + check('imm-admins', !!r.notify_agency_admins, 'Agency admins')
      + check('imm-directors', !!r.notify_directors, 'Centre directors')
      + check('imm-educators', !!r.notify_educators, 'Educators',
          'Usually the office chases these rather than the room.')

      + '<label style="' + lbl + '">What counts as outstanding</label>'
      + check('imm-missing', r.include_missing !== false, 'Children with no record at all')
      + '<label style="' + lbl + '">…and records older than</label>'
      + '<select id="imm-stale" style="' + inp + '">'
      + [[0, 'Never counts as out of date'], [6, '6 months'], [12, '12 months'],
         [18, '18 months'], [24, '24 months']].map(function (x) {
        return '<option value="' + x[0] + '"' + (Number(r.stale_after_months) === x[0] ? ' selected' : '') + '>' + x[1] + '</option>';
      }).join('')
      + '</select>'

      /* THE FAMILY-FACING REMINDER — a second, separate send, off until switched on.
         Everything above tells the office which children to chase; this tells the
         family. It is deliberately the last block and deliberately spells out what a
         parent will actually receive, because "notify parents" is the one setting here
         that writes to somebody outside the building. (2026-09-17) */
      + '<div style="margin-top:20px;padding:14px 16px;border:1px solid #E5E7EB;border-radius:10px;background:#FAFCFE;">'
      + '<div style="font-size:12px;font-weight:800;letter-spacing:1px;text-transform:uppercase;color:#1F6080;margin-bottom:8px;">Also tell the family</div>'
      + check('imm-parents', !!r.notify_parents, 'Email parents about their own child',
          'Sent on the same schedule as above. A family with two children gets one email.')
      + check('imm-parent-bcc', r.parent_bcc_staff !== false, 'Blind-copy the office on it',
          'So whoever chases this can see what the family was told.')
      + check('imm-parent-push', r.parent_push !== false, 'Send an app notification too')
      + check('imm-parent-req', r.parent_required_only !== false, 'Required vaccines only',
          'An optional vaccine is a conversation, not a compliance chase.')
      + '<label style="' + lbl + '">Tell them about doses due within</label>'
      + '<select id="imm-parent-lead" style="' + inp + '">'
      + [[1, '1 month'], [2, '2 months'], [3, '3 months'], [6, '6 months']].map(function (x) {
        return '<option value="' + x[0] + '"' + (Number(r.parent_lead_months || 2) === x[0] ? ' selected' : '') + '>' + x[1] + '</option>';
      }).join('')
      + '</select>'
      /* The one thing somebody switching this on has to know, said where they are
         switching it on — not in a release note they will never read. */
      + '<div style="margin-top:10px;padding:10px 12px;border-left:3px solid #B45309;background:#FFF8EC;'
      + 'font-size:12.5px;line-height:1.6;color:#7C4A11;">'
      + '<strong>A child with nothing recorded is not told they are overdue.</strong> '
      + 'The schedule says what a child of that age owes; the record says what this centre '
      + 'has actually typed up. Where nothing has been typed up, the family is asked for the '
      + 'record instead — and if their card is already on file, they are not written to at '
      + 'all, because that one is ours to transcribe.'
      + '</div>'
      + '</div>'

      + '<label style="' + lbl + '">Extra line in the email (optional)</label>'
      + '<textarea id="imm-msg" rows="2" maxlength="500" style="' + inp + 'font-family:inherit;resize:vertical;"'
      + ' placeholder="e.g. Please chase these before month end.">' + esc(r.custom_message || '') + '</textarea>'

      + '</div>'

      + '<div style="margin-top:18px;display:flex;align-items:center;gap:12px;flex-wrap:wrap;">'
      + '<button id="imm-save" type="button" style="padding:9px 16px;border-radius:9px;border:0;'
      + 'background:#1F6080;color:#fff;font-size:13.5px;font-weight:700;cursor:pointer;">Save</button>'
      + '<span id="imm-out" style="font-size:13px;"></span>'
      + '</div>'
      + '</div>';

    var $ = function (id) { return pane.querySelector('#' + id); };

    // The whole schedule is meaningless while the feature is off, so it dims with it.
    $('imm-enabled').addEventListener('change', function () {
      var body = $('imm-body');
      body.style.opacity = this.checked ? '' : '.5';
      body.style.pointerEvents = this.checked ? '' : 'none';
    });

    $('imm-freq').addEventListener('change', function () {
      var monthly = this.value === 'monthly';
      $('imm-weekly').style.display = monthly ? 'none' : '';
      $('imm-monthly').style.display = monthly ? '' : 'none';
    });

    $('imm-save').addEventListener('click', function () {
      var out = $('imm-out');
      var btn = $('imm-save');
      btn.disabled = true;
      out.style.color = '#64748B';
      out.textContent = 'Saving…';
      api('/admin/immunization-reminders', 'POST', {
        enabled: $('imm-enabled').checked,
        frequency: $('imm-freq').value,
        day_of_week: Number($('imm-dow').value) || 1,
        day_of_month: Number($('imm-dom').value) || 1,
        send_time: $('imm-time').value || '09:00',
        notify_agency_admins: $('imm-admins').checked,
        notify_directors: $('imm-directors').checked,
        notify_educators: $('imm-educators').checked,
        include_missing: $('imm-missing').checked,
        stale_after_months: Number($('imm-stale').value),
        notify_parents: $('imm-parents').checked,
        parent_bcc_staff: $('imm-parent-bcc').checked,
        parent_push: $('imm-parent-push').checked,
        parent_required_only: $('imm-parent-req').checked,
        parent_lead_months: Number($('imm-parent-lead').value),
        custom_message: $('imm-msg').value,
      }).then(function () {
        out.style.color = '#166534';
        out.textContent = 'Saved.';
      }).catch(function (e) {
        out.style.color = '#B91C1C';
        out.textContent = e.message;
      }).then(function () { btn.disabled = false; });
    });
  }

  KT.ImmunizationSettings = { render: render };
})(window);
