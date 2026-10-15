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

  /* ONE SECTION PER SUBTAB (2026-10-10, Anthony: "under notifications in settings there
     are multiple settings on one page add the sections in its own substabs within
     notifications to clean this up"). Four unrelated settings, each with its own Save, were
     stacked in one long scroll. Each is now its own tab (KT.settingsTabs), painted when
     first opened; #notification-settings?tab=bios opens one directly. */
  function section(fn) {
    return function (pane) {
      return Promise.resolve(fn(pane)).then(function () {
        // The painters return quietly when the API refuses (not this person's to change).
        if (/^\s*Loading…\s*$/.test(pane.textContent || '')) {
          pane.innerHTML = '<div class="kt-card" style="max-width:680px;color:#64748B;">Only agency administrators can change this.</div>';
        }
      });
    };
  }
  async function render(main) {
    main.setAttribute('data-kt-pretty', '1');
    main.innerHTML = '<div style="padding:14px 24px;max-width:900px;"><div id="ns-host"></div></div>';
    KT.settingsTabs(main.querySelector('#ns-host'), 'notification-settings', [
      { key: 'countersign', label: '✍️ Counter-signature', paint: section(paintCountersign) },
      { key: 'formparents', label: '🧾 Forms-only parents', paint: section(paintTempParents) },
      { key: 'bios', label: '🧑‍🏫 Provider bios', paint: section(paintBio) },
      { key: 'files', label: '📎 Shared files', paint: section(paintFiles) },
      { key: 'messaging', label: '💬 Message Centre', paint: section(paintMessaging) },
      // Sleep checks moved to Settings → Room Controls → Sleep checks (2026-10-15).
    ]);
  }

  /* ── Message Centre: who educators and home visitors may message (2026-10-09) ──
     Anthony: "this should be configurable in the settings to allowing educators to message
     who including other staff members ... also to home visitors as well". Defaults are his
     chosen behaviour: their own room families, plus admins, directors and super admins.
     Enforced on the server (App\\Support\\MessagingRules); this only edits the setting. */
  async function paintMessaging(body) {
    var res;
    try { res = await Api.get('/admin/messaging-rules'); } catch (e) { return; }
    var s = res.settings || {};
    var STAFF = [['agency_admin', 'Agency admins'], ['centre_director', 'Directors'], ['platform_admin', 'Super admin'], ['educator', 'Other educators'], ['home_visitor', 'Home visitors']];
    function block(role, title) {
      var r = s[role] || { families: 'rooms', staff: {} };
      return '<div style="padding:10px 0 4px;border-bottom:1px solid #F1F5F9;">'
        + '<div style="font-size:13px;font-weight:800;color:#0F172A;margin-bottom:2px;">' + title + '</div>'
        + row('Families they can message', 'Rooms = only families of the children in rooms they are assigned to.',
            sel('mr-' + role + '-fam', [['rooms', 'Families in their rooms'], ['centre', 'All families at their centre']], r.families))
        // Five switches do not fit beside a label: they get the row's full width and wrap
        // (they were running off the right edge of the card, 2026-10-13).
        + '<div style="padding:12px 0;border-bottom:1px solid #F1F5F9;">'
        +   '<div style="font-size:14px;color:#334155;font-weight:600;margin-bottom:8px;">Staff they can message</div>'
        +   '<div style="display:flex;flex-wrap:wrap;gap:8px 18px;">'
        +   STAFF.map(function (p) {
              return '<label style="display:inline-flex;align-items:center;gap:6px;font-size:13px;color:#334155;white-space:nowrap;">'
                + '<input type="checkbox" data-mr="' + role + '" data-staff="' + p[0] + '"' + (r.staff && r.staff[p[0]] ? ' checked' : '') + '> ' + p[1] + '</label>';
            }).join('')
        +   '</div></div>'
        + '</div>';
    }
    body.innerHTML = '<div class="kt-card" style="max-width:680px;">'
      + '<div class="kt-card-header"><h3 class="kt-card-title">💬 Message Centre — who staff can message</h3></div>'
      + '<div style="font-size:12.5px;color:#64748B;line-height:1.5;margin:-4px 0 6px;">Limits who <b>educators</b> and <b>home visitors</b> can message in the Message Centre. '
      + 'Directors and admins can always message everyone. Parents can always reply to the people messaging them.</div>'
      + block('educator', 'Educators')
      + block('home_visitor', 'Home visitors')
      + row('Conversations these rules no longer allow', 'Read-only keeps the history visible; nothing new can be sent.',
          sel('mr-existing', [['read_only', 'Keep as read-only history'], ['hidden', 'Hide them']], s.existing || 'read_only'))
      + '<div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;margin-top:16px;">'
      + '<button id="mr-save" class="kt-btn kt-btn-primary">Save</button><span id="mr-msg" style="font-size:13px;"></span></div>'
      + '</div>';
    var msg = body.querySelector('#mr-msg');
    body.querySelector('#mr-save').addEventListener('click', function () {
      var out = { existing: body.querySelector('#mr-existing').value };
      ['educator', 'home_visitor'].forEach(function (role) {
        var staff = {};
        body.querySelectorAll('[data-mr="' + role + '"]').forEach(function (cb) { staff[cb.getAttribute('data-staff')] = cb.checked; });
        out[role] = { families: body.querySelector('#mr-' + role + '-fam').value, staff: staff };
      });
      msg.style.color = '#64748B'; msg.textContent = 'Saving…';
      Api.post('/admin/messaging-rules', out)
        .then(function () { msg.style.color = '#1E8E60'; msg.textContent = '✓ Saved — applies straight away'; })
        .catch(function (e) { msg.style.color = '#B91C1C'; msg.textContent = (e && e.message) || 'Could not save'; });
    });
  }

  /* ── Files between educators and families (2026-10-09) ── */
  async function paintFiles(body) {
    var res;
    try { res = await Api.get('/provider/files/settings'); } catch (e) { return; }
    var s = res.settings || {};
    body.innerHTML = '<div class="kt-card" style="max-width:680px;">'
      + '<div class="kt-card-header"><h3 class="kt-card-title">📎 Shared files</h3></div>'
      + '<div style="font-size:12.5px;color:#64748B;line-height:1.5;margin:-4px 0 6px;">When an educator sends a family a file (Files), or a family sends one back, the people it is for always get a notification in the app. '
      + 'These choose whether an email is sent too — it only says a file is waiting; the file is never attached.</div>'
      + row('Email parents about new files', '', toggle('ff-ep', s.email_parents))
      + row('Email staff when a family sends a file', 'Off: staff see it in their notifications only.', toggle('ff-es', s.email_staff))
      + '<div style="display:flex;align-items:center;gap:8px;margin-top:16px;"><button id="ff-save" class="kt-btn kt-btn-primary">Save</button><span id="ff-msg" style="font-size:13px;"></span></div></div>';
    body.querySelector('#ff-save').addEventListener('click', function () {
      var msg = body.querySelector('#ff-msg'); msg.style.color = '#64748B'; msg.textContent = 'Saving…';
      Api.post('/provider/files/settings', { email_parents: body.querySelector('#ff-ep').checked, email_staff: body.querySelector('#ff-es').checked })
        .then(function () { msg.style.color = '#1E8E60'; msg.textContent = '✓ Saved'; })
        .catch(function (e) { msg.style.color = '#B91C1C'; msg.textContent = (e && e.message) || 'Could not save'; });
    });
  }

  /* ── Provider bios (2026-10-09) ──────────────────────────────────────────
     Anthony: "add reminders for educators to write a bio and show them where to do this
     ... and have this configurable in the settings as well". See App\Support\BioReminders. */
  async function paintBio(body) {
    var res;
    try { res = await Api.get('/admin/bio-reminders'); } catch (e) { return; }
    var s = res.settings || {};
    var miss = res.missing || [];
    var DAYS = [[1, 'Monday'], [2, 'Tuesday'], [3, 'Wednesday'], [4, 'Thursday'], [5, 'Friday'], [6, 'Saturday'], [7, 'Sunday']];
    var list = miss.length
      ? '<div style="margin:6px 0 4px;border:1px solid #E2E8F0;border-radius:10px;overflow:hidden;">' + miss.map(function (p, i) {
          return '<div style="display:flex;gap:8px;align-items:center;padding:7px 10px;font-size:13px;' + (i ? 'border-top:1px solid #F1F5F9;' : '') + '">'
            + '<b style="flex:1;min-width:0;">' + esc(p.name) + '</b>'
            + '<span style="color:#64748B;">' + (p.length ? p.length + ' characters' : 'no bio') + (p.has_photo ? '' : ' · no photo') + '</span>'
            + '<span style="color:#94A3B8;">reminded ' + p.reminders + '×</span></div>';
        }).join('') + '</div>'
      : '';
    body.innerHTML = '<div class="kt-card" style="max-width:680px;">'
      + '<div class="kt-card-header"><h3 class="kt-card-title">✍️ Provider bios</h3></div>'
      + '<div style="font-size:12.5px;color:#64748B;line-height:1.5;margin:-4px 0 6px;">A provider\'s bio goes in every new family\'s welcome email and on their provider card in the parents\' app. '
      + 'Providers without one are reminded why it matters and given a link straight to <b>Settings → Profile → Your bio</b>. Reminders stop once the bio is saved. '
      + (miss.length ? '<b>' + miss.length + '</b> provider' + (miss.length === 1 ? ' has' : 's have') + ' no bio yet:' : 'Every provider has a bio.')
      + (res.last_sent_at ? ' Last sent ' + esc(res.last_sent_at) + '.' : '') + '</div>'
      + list
      + row('Send reminders', 'Off means no emails or bell notifications about bios.', toggle('bio-on', s.enabled))
      + row('How often', '', sel('bio-freq', [['daily', 'Every day'], ['weekdays', 'Weekdays only'], ['weekly', 'Once a week'], ['every_n_days', 'Every few days']], s.frequency)
          + '<span id="bio-wd-wrap">' + sel('bio-wd', DAYS, s.weekly_day) + '</span>'
          + '<span id="bio-n-wrap">every ' + num('bio-n', s.every_n_days, 1, 30) + ' days</span>')
      + row('Time of day', 'In ' + esc(res.tz || 'your agency’s timezone') + '.', timeInput('bio-time', s.time))
      + row('A bio counts once it has at least', 'The bio box asks for 40 at least.', num('bio-min', s.min_chars, 40, 400) + ' characters')
      + row('Stop after', 'How many reminders one provider gets before they stop. 0 = keep reminding.', num('bio-max', s.max_reminders, 0, 52) + ' reminders')
      + row('Send by email', 'Explains why it matters, where to write it, and what to say.', toggle('bio-email', s.email))
      + row('Show in the app', 'A notification in their bell that opens the bio box.', toggle('bio-app', s.in_app))
      + row('Push to phones', '', toggle('bio-push', s.push))
      + row('Show a reminder card', 'A small "Families would love to meet you" card on every screen until the bio is written. They can hide it for the day.', toggle('bio-banner', s.banner))
      + row('Send admins a summary', 'One email to agency admins listing who still has no bio, when reminders go out.', toggle('bio-cc', s.cc_summary))
      + '<div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;margin-top:16px;">'
      + '<button id="bio-save" class="kt-btn kt-btn-primary">Save</button>'
      + '<button id="bio-prev" class="kt-btn">👁 Preview</button>'
      + '<button id="bio-test" class="kt-btn">📤 Send test…</button>'
      + '<span id="bio-msg" style="font-size:13px;"></span></div>'
      + '</div>';
    function q(id) { return body.querySelector('#' + id); }
    function vals() {
      return { enabled: q('bio-on').checked, frequency: q('bio-freq').value, weekly_day: +q('bio-wd').value, every_n_days: +q('bio-n').value || 3,
        time: q('bio-time').value || '09:00', min_chars: Math.max(40, +q('bio-min').value || 40), max_reminders: Math.max(0, +q('bio-max').value || 0),
        email: q('bio-email').checked, in_app: q('bio-app').checked, push: q('bio-push').checked, banner: q('bio-banner').checked, cc_summary: q('bio-cc').checked };
    }
    function syncFreq() {
      var f = q('bio-freq').value;
      q('bio-wd-wrap').style.display = f === 'weekly' ? '' : 'none';
      q('bio-n-wrap').style.display = f === 'every_n_days' ? '' : 'none';
    }
    q('bio-freq').addEventListener('change', syncFreq); syncFreq();
    var msg = q('bio-msg');
    function say(t, c) { msg.style.color = c || '#64748B'; msg.textContent = t; }
    q('bio-save').addEventListener('click', function () {
      say('Saving…');
      Api.post('/admin/bio-reminders', vals()).then(function () { say('✓ Saved', '#1E8E60'); }).catch(function (e) { say((e && e.message) || 'Could not save', '#B91C1C'); });
    });
    q('bio-prev').addEventListener('click', function () {
      say('Building preview…');
      Api.post('/admin/bio-reminders/preview', vals()).then(function (r) {
        say('');
        showPreview(r.subject, r.html, r.sample ? 'Every provider has a bio, so this uses a sample provider.' : (r.missing + ' provider(s) would get a reminder like this one.'));
      }).catch(function (e) { say((e && e.message) || 'Could not build the preview', '#B91C1C'); });
    });
    q('bio-test').addEventListener('click', async function () {
      var to = await KT.prompt({ title: 'Send a test reminder', description: 'One copy of the bio reminder, marked [Test], to the address you enter.', placeholder: 'name@example.com', type: 'email', okLabel: 'Send test' });
      if (to === null) { return; }
      to = String(to || '').trim();
      if (!/^[^@\s]+@[^@\s]+\.[^@\s]+$/.test(to)) { say('Enter an email address.', '#B91C1C'); return; }
      say('Sending…');
      Api.post('/admin/bio-reminders/test', Object.assign({ to: to }, vals())).then(function (r) {
        if (r.sent) { say('✓ Sent to ' + to, '#1E8E60'); } else { say('Not sent (' + (r.status || 'failed') + ')' + (r.reason ? ': ' + r.reason : ''), '#B45309'); }
      }).catch(function (e) { say((e && e.message) || 'Could not send', '#B91C1C'); });
    });
  }

  function showPreview(subject, html, note) {
    var ov = document.createElement('div');
    ov.setAttribute('data-no-modal-guard', '1');
    ov.className = 'kt-scrim';
    ov.style.cssText = 'position:fixed;inset:0;z-index:2147481200;display:flex;align-items:center;justify-content:center;padding:16px;';
    ov.innerHTML = '<div style="background:#fff;border-radius:14px;width:100%;max-width:700px;height:min(88vh,900px);display:flex;flex-direction:column;overflow:hidden;">'
      + '<div style="padding:12px 16px;border-bottom:1px solid #E2E8F0;display:flex;align-items:center;gap:10px;">'
      + '<div style="flex:1;min-width:0;"><div style="font-size:11px;font-weight:800;color:#64748B;letter-spacing:.5px;">PREVIEW — NOTHING IS SENT</div>'
      + '<div data-subj style="font-weight:800;font-size:14px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"></div>'
      + '<div data-note style="font-size:12px;color:#64748B;"></div></div>'
      + '<button type="button" class="kt-btn" data-close>Close</button></div>'
      + '<iframe sandbox="" style="flex:1;width:100%;border:0;background:#F4F6F8;"></iframe></div>';
    ov.querySelector('[data-subj]').textContent = subject || '';
    ov.querySelector('[data-note]').textContent = note || '';
    ov.querySelector('iframe').srcdoc = html || '';
    function close() { ov.remove(); }
    ov.querySelector('[data-close]').addEventListener('click', close);
    ov.addEventListener('click', function (e) { if (e.target === ov) { close(); } });
    document.body.appendChild(ov);
  }

  /* ── Forms-only (temporary) parents ─────────────────────────────────────
     Accounts the forms feature made for parents who are not on a family yet
     (App\Support\TempGuardians). Adding their family with the same email links them;
     unused ones are removed after the days set here (2026-10-13). */
  async function paintTempParents(body) {
    var res, list;
    try { res = await Api.get('/admin/temp-guardians/settings'); list = await Api.get('/admin/temp-guardians'); }
    catch (e) {
      body.innerHTML = '<div class="kt-card" style="max-width:760px;color:#64748B;">Only agency administrators can change this.</div>';
      return;
    }
    var days = (res.settings && res.settings.remove_after_days != null) ? res.settings.remove_after_days : 30;
    var rows = (list && list.temp_guardians) || [];
    function esc2(s) { return String(s == null ? '' : s).replace(/[&<>"]/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]; }); }
    function fmt(d) { try { return new Date(String(d).replace(' ', 'T') + (String(d).length <= 10 ? 'T12:00:00' : 'Z')).toLocaleDateString(undefined, { month: 'short', day: 'numeric', year: 'numeric' }); } catch (e) { return d; } }
    body.innerHTML = '<div class="kt-card" style="max-width:760px;">'
      + '<div class="kt-card-header"><h3 class="kt-card-title">🧾 Parents with forms but no family yet</h3></div>'
      + '<div style="font-size:12.5px;color:#64748B;line-height:1.55;margin:-4px 0 12px;">When forms are sent to a parent who is not in KiddieTrac, a temporary account is made for them. '
      + 'Add their family with the <b>same email</b> (or pick them from the suggestion in Add family) and the account — with its signed forms — joins the family. '
      + 'They are listed in the daily and weekly summary until then.</div>'
      + '<div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;background:#F8FAFC;border:1px solid #E2E8F0;border-radius:10px;padding:10px 12px;margin-bottom:14px;">'
      + '<label for="kt-tp-days" style="font-size:13px;font-weight:700;color:#334155;">Remove unused accounts after</label>'
      + '<input id="kt-tp-days" type="number" min="0" max="365" value="' + esc2(days) + '" style="width:72px;height:30px;border:1px solid #CBD5E1;border-radius:8px;padding:0 8px;font:inherit;font-size:13px;">'
      + '<span style="font-size:13px;color:#334155;">days</span>'
      + '<button type="button" id="kt-tp-save" style="height:30px;padding:0 14px;border:0;border-radius:8px;background:#0E7C90;color:#fff;font-weight:700;font-size:13px;cursor:pointer;">Save</button>'
      + '<span id="kt-tp-msg" style="font-size:12.5px;color:#64748B;"></span>'
      + '<div style="flex-basis:100%;font-size:12px;color:#64748B;">Unused = never signed in and never signed a form. 0 = never remove. An account somebody has used is never removed automatically.</div>'
      + '</div>'
      + (rows.length ? '<table style="width:100%;border-collapse:collapse;font-size:13px;">'
        + '<thead><tr style="text-align:left;color:#64748B;font-size:11.5px;text-transform:uppercase;letter-spacing:.05em;"><th style="padding:6px 8px;">Parent</th><th style="padding:6px 8px;">Forms</th><th style="padding:6px 8px;">Status</th><th></th></tr></thead><tbody>'
        + rows.map(function (r) {
          return '<tr data-id="' + r.id + '" style="border-top:1px solid #EEF2F7;">'
            + '<td style="padding:8px;"><div style="font-weight:700;color:#0F172A;">' + esc2(r.name) + '</div><div style="color:#64748B;font-size:12px;">' + esc2(r.email) + ' · since ' + esc2(fmt(r.created_at)) + '</div></td>'
            + '<td style="padding:8px;color:#334155;">' + r.forms_signed + ' / ' + r.forms_assigned + ' signed</td>'
            + '<td style="padding:8px;">' + (r.used
              ? '<span style="background:#DCFCE7;color:#166534;font-weight:700;font-size:11.5px;border-radius:999px;padding:2px 8px;">In use — add their family</span>'
              : '<span style="background:#FEF3C7;color:#92400E;font-weight:700;font-size:11.5px;border-radius:999px;padding:2px 8px;">Unused' + (r.removes_on ? ' · removed ' + esc2(fmt(r.removes_on)) : '') + '</span>') + '</td>'
            + '<td style="padding:8px;text-align:right;"><button type="button" data-remove="' + r.id + '" style="height:28px;padding:0 10px;border:1px solid #FECACA;background:#FFF5F5;color:#B91C1C;border-radius:8px;font-weight:700;font-size:12px;cursor:pointer;">Remove</button></td>'
            + '</tr>';
        }).join('') + '</tbody></table>'
        : '<div style="padding:16px;text-align:center;color:#64748B;background:#F8FAFC;border-radius:10px;">No parents waiting — everyone with forms is on a family.</div>')
      + '</div>';

    body.querySelector('#kt-tp-save').addEventListener('click', function () {
      var v = parseInt(body.querySelector('#kt-tp-days').value, 10);
      var m = body.querySelector('#kt-tp-msg');
      if (isNaN(v) || v < 0 || v > 365) { m.style.color = '#B91C1C'; m.textContent = 'Enter 0 to 365.'; return; }
      m.style.color = '#64748B'; m.textContent = 'Saving…';
      Api.post('/admin/temp-guardians/settings', { remove_after_days: v })
        .then(function () { m.style.color = '#15803D'; m.textContent = '✓ Saved'; paintTempParents(body); })
        .catch(function (e) { m.style.color = '#B91C1C'; m.textContent = (e && e.message) || 'Could not save'; });
    });
    body.querySelectorAll('[data-remove]').forEach(function (b) {
      b.addEventListener('click', async function () {
        var row = rows.filter(function (r) { return String(r.id) === b.getAttribute('data-remove'); })[0] || {};
        var ok = window.KT && KT.confirm ? await KT.confirm({ title: 'Remove ' + (row.name || 'this account') + '?',
          description: 'Their login is removed. Forms they already signed stay on record. Do this only if they are not joining — adding their family links them instead.',
          okLabel: 'Remove account' }) : window.confirm('Remove this account?');
        if (!ok) return;
        b.disabled = true;
        try { await Api.delete('/admin/temp-guardians/' + row.id); paintTempParents(body); }
        catch (e) { b.disabled = false; if (KT.toast) KT.toast('⚠️', 'Not removed', (e && e.message) || '', '#DC2626'); }
      });
    });
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
