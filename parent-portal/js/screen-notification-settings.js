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
    /* data-kt-self-live (2026-11-04): a Preview / Send test POST told the live refresh that data changed, and it
       redrew this screen from the saved settings - unsaved edits (certificate reminder days) and the preview vanished.
       Each tab here re-reads its own settings when opened. */
    main.innerHTML = '<div data-kt-self-live="1" style="padding:14px 24px;max-width:900px;"><div id="ns-host"></div></div>';
    KT.settingsTabs(main.querySelector('#ns-host'), 'notification-settings', [
      { key: 'countersign', label: '✍️ Counter-signature', paint: section(paintCountersign) },
      { key: 'formparents', label: '🧾 Forms-only parents', paint: section(paintTempParents) },
      { key: 'bios', label: '🧑‍🏫 Provider bios', paint: section(paintBio) },
      { key: 'files', label: '📎 Shared files', paint: section(paintFiles) },
      { key: 'messaging', label: '💬 Message Centre', paint: section(paintMessaging) },
      { key: 'summaries', label: '📨 Daily summaries', paint: section(paintSummaryTiming) },
      { key: 'moments', label: '🌈 Instant moments', paint: section(paintMoments) },
      { key: 'staffnotify', label: '📲 Required for staff', paint: section(paintStaffNotify) },
      { key: 'escalation', label: '🚩 Escalated daily records', paint: section(paintEscalation) },
      { key: 'topics', label: '💉 Immunizations & drills', paint: section(paintTopics) },
      { key: 'certs', label: '🎓 Staff certifications', paint: section(paintCerts) },
      // 📻 The walkie's agency settings moved here from its screen (2026-11-27, Anthony: "move the settings ... to the settings section").
      { key: 'walkie', label: '📻 Walkie-talkie', paint: function (pane) { return KT.walkie && KT.walkie.agencyPane ? KT.walkie.agencyPane(pane) : (pane.innerHTML = '<div class="kt-card">Loading…</div>'); } },
      // 📈 The NPS survey setting moved here from the NPS screen (2026-11-28, Anthony: "survey setting in NPS should be put into the settings section").
      { key: 'nps', label: '📈 NPS survey', paint: section(paintNps) },
      // Sleep checks moved to Settings → Room Controls → Sleep checks (2026-10-15).
    ]);
  }

  /* ── NPS survey: automatic rounds on/off, how often, families/staff (2026-11-28). App\Support\NpsSurvey holds the
     rules; agency admins and super admins only (the API refuses directors). Send now and the results stay on
     Engagement › NPS. ── */
  async function paintNps(body) {
    var res;
    try { res = await Api.get('/engagement/nps?settings_only=1'); }
    catch (e) {
      if (e && e.status === 400) {
        body.innerHTML = '<div class="kt-card" style="max-width:680px;color:#64748B;">Choose an agency in the agency switcher at the top to change its NPS survey.</div>';
      }
      return;
    }
    var s = res.settings || {};
    body.innerHTML = '<div class="kt-card" style="max-width:680px;">'
      + '<div class="kt-card-header"><h3 class="kt-card-title">📈 NPS survey — ask families and staff how likely they are to recommend you</h3></div>'
      + '<div style="font-size:12.5px;color:#64748B;line-height:1.5;margin:-4px 0 6px;">Each person gets an email and an app notice with one question, '
      + '0 to 10, and an optional comment. No sign-in is needed. A low score (0-6) tells the centre\'s managers. Results, and Send now for one '
      + 'round straight away, are on Engagement › NPS.</div>'
      + row('Send the survey automatically', s.enabled && s.next_due ? 'Next round: ' + esc(String(s.next_due).slice(0, 10)) + '.' : 'Off: no automatic rounds. You can still send one from the NPS screen.', toggle('nps-on', s.enabled))
      + row('How often', 'Each round asks everyone who is ticked below.', sel('nps-every', [[3, 'Every 3 months'], [6, 'Every 6 months'], [12, 'Every 12 months']], s.every_months || 6))
      + row('Families', 'Parents of active families.', toggle('nps-fam', s.families))
      + row('Staff', 'Staff of this agency.', toggle('nps-staff', s.staff))
      + '<div style="display:flex;align-items:center;gap:8px;margin-top:16px;flex-wrap:wrap;"><button id="nps-save" class="kt-btn kt-btn-primary">Save</button>'
      + '<a href="#nps" style="font-size:13px;font-weight:600;">📈 Open NPS results</a><span id="nps-msg" style="font-size:13px;"></span></div></div>';
    body.querySelector('#nps-save').addEventListener('click', function () {
      var msg = body.querySelector('#nps-msg');
      var v = { enabled: body.querySelector('#nps-on').checked, every_months: +body.querySelector('#nps-every').value,
        families: body.querySelector('#nps-fam').checked, staff: body.querySelector('#nps-staff').checked };
      if (v.enabled && !v.families && !v.staff) { msg.style.color = '#B91C1C'; msg.textContent = 'Tick Families, Staff or both.'; return; }
      msg.style.color = '#64748B'; msg.textContent = 'Saving…';
      Api.put('/engagement/nps/settings', v)
        .then(function () { msg.style.color = '#1E8E60'; msg.textContent = '✓ Saved'; })
        .catch(function (e) { msg.style.color = '#B91C1C'; msg.textContent = (e && e.message) || 'Could not save'; });
    });
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
    /* 👋 NUDGES (2026-11-04, App\\Support\\ChatNudge). Anthony: "wire up settings to control the nudge and any other
       chat seetings in the settings section". Saved with the rules below, in the same POST. */
    var nd = res.nudge || { enabled: true, families: true, cooldown: 60, break_through: true };
    var cl = res.calls || { enabled: true, families: true };   // 📞 App\\Support\\ChatCall, saved in the same POST
    var cds = (res.nudge_cooldowns || [30, 60, 120, 300, 600, 1800, 3600]).map(function (n) {
      return [String(n), n < 60 ? n + ' seconds' : n === 60 ? '1 minute' : n < 3600 ? (n / 60) + ' minutes' : '1 hour'];
    });
    function sw(id, on) {
      return '<label style="display:inline-flex;align-items:center;gap:6px;font-size:13px;color:#334155;white-space:nowrap;"><input type="checkbox" id="' + id + '"' + (on ? ' checked' : '') + '> On</label>';
    }
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
      + '<div class="kt-card-header"><h3 class="kt-card-title">💬 Message Centre — who can message, nudges and calls</h3></div>'
      + '<div style="font-size:12.5px;color:#64748B;line-height:1.5;margin:-4px 0 6px;">Limits who <b>educators</b> and <b>home visitors</b> can message in the Message Centre. '
      + 'Directors and admins can always message everyone. Parents can always reply to the people messaging them.</div>'
      + block('educator', 'Educators')
      + block('home_visitor', 'Home visitors')
      + row('Conversations these rules no longer allow', 'Read-only keeps the history visible; nothing new can be sent.',
          sel('mr-existing', [['read_only', 'Keep as read-only history'], ['hidden', 'Hide them']], s.existing || 'read_only'))
      + '<div style="padding:14px 0 4px;"><div style="font-size:13px;font-weight:800;color:#0F172A;margin-bottom:2px;">👋 Nudges</div>'
      + '<div style="font-size:12.5px;color:#64748B;line-height:1.5;">The 👋 beside the ＋ in every chat rings the other person\'s phone, shakes their screen and plays the nudge sound.'
      + ' Each person picks their nudge sound in Settings › Notifications › Sounds &amp; vibration.</div></div>'
      + row('Nudges', 'Off removes the 👋 button for everyone at the agency.', sw('nd-enabled', nd.enabled !== false))
      + row('Families can nudge staff', 'Off: only staff can nudge. Families can still message.', sw('nd-families', nd.families !== false))
      + row('Time between nudges', 'Per conversation, per person. Stops a nudge turning into an alarm.', sel('nd-cooldown', cds, String(nd.cooldown || 60)))
      + row('Ring through Do Not Disturb', 'On: a nudge rings like an urgent alert, even in quiet hours. Off: an ordinary notification.', sw('nd-break', nd.break_through !== false))
      + '<div style="padding:14px 0 4px;"><div style="font-size:13px;font-weight:800;color:#0F172A;margin-bottom:2px;">📞 Calls</div>'
      + '<div style="font-size:12.5px;color:#64748B;line-height:1.5;">The 📞 beside the 👋 in every chat starts a voice call inside KiddieTrac. No phone numbers are shared, and calls are not recorded.'
      + ' It rings on computers and Android phones; on an iPhone with the app closed it arrives as a notification to tap.</div></div>'
      + row('Calls', 'Off removes the 📞 button for everyone at the agency.', sw('cl-enabled', cl.enabled !== false))
      + row('Families can call staff', 'Off: only staff can call. Families can still message.', sw('cl-families', cl.families !== false))
      + '<div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;margin-top:16px;">'
      + '<button id="mr-save" class="kt-btn kt-btn-primary">Save</button><span id="mr-msg" style="font-size:13px;"></span></div>'
      + '</div>';
    var msg = body.querySelector('#mr-msg');
    body.querySelector('#mr-save').addEventListener('click', function () {
      var out = { existing: body.querySelector('#mr-existing').value,
        nudge: { enabled: body.querySelector('#nd-enabled').checked, families: body.querySelector('#nd-families').checked,
          cooldown: parseInt(body.querySelector('#nd-cooldown').value, 10) || 60, break_through: body.querySelector('#nd-break').checked },
        calls: { enabled: body.querySelector('#cl-enabled').checked, families: body.querySelector('#cl-families').checked } };
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

  /* ── Daily summaries: when they go (2026-10-29) ──
     Anthony: "send it out after 1 hour after the child has clocked out or can be changed via a
     setting ... this includes the educator summary". Sent by the scheduler every 10 minutes
     (App\\Support\\SummaryTiming); this only edits the setting. */
  async function paintSummaryTiming(body) {
    var res;
    try { res = await Api.get('/admin/summary-timing'); } catch (e) { return; }
    var s = res.settings || {};
    var delays = (res.delays || [0, 15, 30, 45, 60, 90, 120, 180, 240]).map(function (m) {
      return [m, m === 0 ? 'Straight away' : m < 60 ? m + ' minutes after' : (m % 60 ? (m / 60).toFixed(1) : m / 60) + (m === 60 ? ' hour after' : ' hours after')];
    });
    body.innerHTML = '<div class="kt-card" style="max-width:680px;">'
      + '<div class="kt-card-header"><h3 class="kt-card-title">📨 Daily summaries — when they are sent</h3></div>'
      + '<div style="font-size:12.5px;color:#64748B;line-height:1.5;margin:-4px 0 6px;">Each summary goes once a day. '
      + 'If nobody checks the child out (or the educator never clocks out), it goes at the end-of-day time instead. '
      + 'Times are on the agency\'s clock' + (res.timezone ? ' (' + esc(res.timezone) + ')' : '') + '.</div>'
      + row('Parents', 'After the child is checked out', sel('st-pd', delays, s.parent_delay_min))
      + row('Parents — end of day', 'If the child was never checked out', timeInput('st-pf', s.parent_fallback))
      + row('Educators', 'After they clock out', sel('st-ed', delays, s.educator_delay_min))
      + row('Educators — end of day', 'If they never clocked out', timeInput('st-ef', s.educator_fallback))
      + '<div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;margin-top:16px;">'
      + '<button id="st-save" class="kt-btn kt-btn-primary">Save</button><span id="st-msg" style="font-size:13px;"></span></div>'
      + '</div>';
    var msg = body.querySelector('#st-msg');
    body.querySelector('#st-save').addEventListener('click', function () {
      var out = {
        parent_delay_min: parseInt(body.querySelector('#st-pd').value, 10),
        educator_delay_min: parseInt(body.querySelector('#st-ed').value, 10),
        parent_fallback: body.querySelector('#st-pf').value,
        educator_fallback: body.querySelector('#st-ef').value,
      };
      msg.style.color = '#64748B'; msg.textContent = 'Saving…';
      Api.post('/admin/summary-timing', out)
        .then(function () { msg.style.color = '#1E8E60'; msg.textContent = '✓ Saved — applies from the next check'; })
        .catch(function (e) { msg.style.color = '#B91C1C'; msg.textContent = (e && e.message) || 'Could not save'; });
    });
  }

  /* ── Instant moments to families (2026-10-31, Anthony: "daily moments, observation logged etc should all be
     instant notifications in the app for the parent"). App\\Support\\ChildMomentNotify. ── */
  async function paintMoments(body) {
    var res;
    try { res = await Api.get('/admin/moment-notify-settings'); } catch (e) { return; }
    var s = res.settings || {};
    body.innerHTML = '<div class="kt-card" style="max-width:680px;">'
      + '<div class="kt-card-header"><h3 class="kt-card-title">🌈 Instant moments — tell families straight away</h3></div>'
      + '<div style="font-size:12.5px;color:#64748B;line-height:1.5;margin:-4px 0 6px;">When staff log something for a child, '
      + 'that child\'s parents get a notification in the app and on their phone right away. Several logs within two minutes are grouped '
      + 'into one ("3 new moments for Evelyn"). Each parent can still switch a category off in their own Settings → Notifications. '
      + 'Photos and sign in/out have their own notifications.</div>'
      + row('Instant moment notifications', 'Off: families only see these in the end-of-day summary.', toggle('im-on', s.enabled))
      + row('Daily moments', 'Meals, snacks, bottles, naps, diapers, moods, activities, outdoor play, sunscreen.', toggle('im-mo', s.moments))
      + row('Learning observations', 'Observations shared with the family.', toggle('im-ob', s.observations))
      + row('Medication doses', 'Each dose given, refused or missed.', toggle('im-me', s.medication))
      + row('Milestones', 'A milestone emerging, in progress or achieved.', toggle('im-mi', s.milestones))
      + '<div style="display:flex;align-items:center;gap:8px;margin-top:16px;"><button id="im-save" class="kt-btn kt-btn-primary">Save</button><span id="im-msg" style="font-size:13px;"></span></div></div>';
    body.querySelector('#im-save').addEventListener('click', function () {
      var msg = body.querySelector('#im-msg'); msg.style.color = '#64748B'; msg.textContent = 'Saving…';
      Api.post('/admin/moment-notify-settings', {
        enabled: body.querySelector('#im-on').checked, moments: body.querySelector('#im-mo').checked,
        observations: body.querySelector('#im-ob').checked, medication: body.querySelector('#im-me').checked,
        milestones: body.querySelector('#im-mi').checked,
      }).then(function () { msg.style.color = '#1E8E60'; msg.textContent = '✓ Saved'; })
        .catch(function (e) { msg.style.color = '#B91C1C'; msg.textContent = (e && e.message) || 'Could not save'; });
    });
  }

  /* ── Required app notifications for staff (2026-10-31, Anthony: "turn on app notifications for all educators
     as part of their duties"). App\\Support\\StaffNotifyRequirement; the in-app step is kt-notify-gate.js. ── */
  async function paintStaffNotify(body) {
    var res;
    try { res = await Api.get('/admin/staff-notify-settings'); } catch (e) { return; }
    var s = res.settings || {}, roles = res.roles || {};
    body.innerHTML = '<div class="kt-card" style="max-width:900px;">'
      + '<div class="kt-card-header"><h3 class="kt-card-title">📲 Required app notifications for staff</h3></div>'
      + '<div style="font-size:12.5px;color:#64748B;line-height:1.5;margin:-4px 0 6px;">When this is on, staff in the roles below must have KiddieTrac notifications '
      + 'switched on on their phone as part of their role. Until they do, the app shows them a step that turns them on (or, if they refused before, '
      + 'shows exactly where the switch is). Their headcount, Safe Arrival, staff, message and announcement notifications are shown as required in their settings. '
      + 'On a computer it is only a daily reminder. No email or text is sent unless you choose "Send setup instructions" below.</div>'
      + row('Require app notifications', 'Off: notifications stay each person\'s own choice.', toggle('sn-on', s.required))
      + '<div style="padding:12px 0;border-bottom:1px solid #F1F5F9;"><div style="font-size:14px;color:#334155;font-weight:600;margin-bottom:8px;">For these roles</div>'
      + '<div style="display:flex;flex-wrap:wrap;gap:8px 18px;">' + Object.keys(roles).map(function (k) {
          return '<label style="display:inline-flex;align-items:center;gap:6px;font-size:13px;color:#334155;white-space:nowrap;"><input type="checkbox" data-sn-role="' + k + '"'
            + ((s.roles || []).indexOf(k) >= 0 ? ' checked' : '') + '> ' + esc(roles[k]) + '</label>';
        }).join('') + '</div></div>'
      + '<div style="display:flex;align-items:center;gap:8px;margin-top:16px;"><button id="sn-save" class="kt-btn kt-btn-primary">Save</button><span id="sn-msg" style="font-size:13px;"></span></div>'
      + '<div style="margin-top:22px;font-size:14px;font-weight:800;color:#0F172A;">Who can receive notifications now</div>'
      + '<div id="sn-ready" style="margin-top:8px;"></div></div>';
    function readiness() { if (window.KT && KT.notifyReadiness) KT.notifyReadiness.mount(body.querySelector('#sn-ready')); }
    readiness();
    body.querySelector('#sn-save').addEventListener('click', function () {
      var msg = body.querySelector('#sn-msg'); msg.style.color = '#64748B'; msg.textContent = 'Saving…';
      var picked = [].map.call(body.querySelectorAll('[data-sn-role]:checked'), function (c) { return c.getAttribute('data-sn-role'); });
      Api.post('/admin/staff-notify-settings', { required: body.querySelector('#sn-on').checked, roles: picked })
        .then(function () { msg.style.color = '#1E8E60'; msg.textContent = '✓ Saved'; readiness(); })
        .catch(function (e) { msg.style.color = '#B91C1C'; msg.textContent = (e && e.message) || 'Could not save'; });
    });
  }

  /* ── Escalated daily records: who gets them (2026-11-26, Anthony: "escalated daily records - super admin should not
     be receiving this from ilearn - is there a setting you can wire up to set who gets this"). App\\Support\\RecordEscalation,
     sent by records:remind. Super admins, integration accounts and reseller access never receive it, even if chosen.
     Saving with nobody, or with no way to tell them, is refused by the server. ── */
  async function paintEscalation(body) {
    var res;
    try { res = await Api.get('/admin/record-escalation'); } catch (e) { return; }
    var s = res.settings || {}, roles = res.roles || {}, chans = res.channels || {}, staff = res.staff || [];
    var picked = {};
    (s.user_ids || []).forEach(function (id) { picked[id] = true; });
    var lab = function (attrs, label, on) {
      return '<label style="display:inline-flex;align-items:center;gap:6px;font-size:13px;color:#334155;white-space:nowrap;"><input type="checkbox" ' + attrs
        + (on ? ' checked' : '') + '> ' + esc(label) + '</label>';
    };
    var block = function (title, hint, inner) {
      return '<div style="padding:12px 0;border-bottom:1px solid #F1F5F9;"><div style="font-size:14px;color:#334155;font-weight:600;">' + title + '</div>'
        + (hint ? '<div style="font-size:12.5px;color:#64748B;margin-top:2px;">' + hint + '</div>' : '') + inner + '</div>';
    };
    var people = staff.map(function (p) {
      return '<label data-esc-person-row="' + esc((p.name + ' ' + (p.roles || []).join(' ')).toLowerCase()) + '" style="display:flex;align-items:center;gap:8px;padding:4px 2px;font-size:13px;color:#334155;">'
        + '<input type="checkbox" data-esc-person="' + p.id + '"' + (picked[p.id] ? ' checked' : '') + '> <span style="min-width:0;flex:1;">' + esc(p.name)
        + ' <span style="font-size:11.5px;color:#64748B;">' + esc((p.roles || []).join(', ')) + '</span></span></label>';
    }).join('');
    body.innerHTML = '<div class="kt-card" style="max-width:760px;">'
      + '<div class="kt-card-header"><h3 class="kt-card-title">🚩 Escalated daily records: who gets them</h3></div>'
      + '<div style="font-size:12.5px;color:#64748B;line-height:1.5;margin:-4px 0 6px;">When an educator\'s daily records reminder is escalated '
      + '(Settings › Room Controls › Daily records), the people chosen here are told. Directors only hear about educators at their own centre. '
      + 'A person picked by name hears only about the centres they work at, unless they are an agency admin. KiddieTrac super admins, integration accounts '
      + 'and reseller access never receive it. Each person can still switch it off for themselves in Settings › Notifications › Notify me about.</div>'
      + block('Roles', '', '<div style="display:flex;flex-wrap:wrap;gap:8px 18px;margin-top:10px;">' + Object.keys(roles).map(function (r) {
          return lab('data-esc-role="' + esc(r) + '"', roles[r], (s.roles || []).indexOf(r) >= 0);
        }).join('') + '</div>')
      + block('These people', 'Only your agency\'s own staff are listed.',
          '<input id="esc-find" type="search" placeholder="Search staff" style="margin-top:10px;width:100%;max-width:320px;height:30px;padding:4px 9px;border:1px solid #D1D5DB;border-radius:8px;font-size:13px;">'
          + '<div id="esc-people" style="margin-top:8px;max-height:220px;overflow-y:auto;border:1px solid #E2E8F0;border-radius:8px;padding:4px 8px;">'
          + (people || '<div style="font-size:13px;color:#64748B;padding:6px 0;">No staff yet.</div>') + '</div>')
      + block('How they are told', 'Email copies go on every daily records reminder. The bell and phone notification come only once a reminder is escalated. A phone notification also adds the bell.',
          '<div style="display:flex;flex-wrap:wrap;gap:8px 18px;margin-top:10px;">' + Object.keys(chans).map(function (c) {
            return lab('data-esc-ch="' + esc(c) + '"', chans[c], !!(s.channels || {})[c]);
          }).join('') + '</div>')
      + (res.copies_on === false ? '<div style="margin-top:10px;font-size:12.5px;color:#B45309;">Copies are switched off in Room Controls › Daily records, so nobody is copied until "Copy the people chosen for escalated reminders" is ticked there.</div>' : '')
      + '<div style="display:flex;align-items:center;gap:8px;margin-top:16px;"><button id="esc-save" class="kt-btn kt-btn-primary">Save</button><span id="esc-msg" style="font-size:13px;"></span></div>'
      + '<div style="margin-top:22px;font-size:14px;font-weight:800;color:#0F172A;">Who this reaches now</div>'
      + '<div id="esc-preview" style="margin-top:8px;"></div></div>';

    function paintPreview(list) {
      var el = body.querySelector('#esc-preview');
      el.innerHTML = (list || []).length ? (list || []).map(function (c) {
        return '<div style="display:flex;gap:12px;flex-wrap:wrap;padding:6px 0;border-bottom:1px solid #F1F5F9;font-size:13px;">'
          + '<div style="font-weight:700;color:#0F172A;flex:0 0 220px;min-width:0;">' + esc(c.centre) + '</div>'
          + '<div style="color:' + (c.people.length ? '#334155' : '#B91C1C') + ';flex:1 1 240px;">' + (c.people.length ? esc(c.people.join(', ')) : 'Nobody at this centre yet.') + '</div></div>';
      }).join('') : '<div style="font-size:13px;color:#64748B;">No open centres.</div>';
    }
    paintPreview(res.preview);

    body.querySelector('#esc-find').addEventListener('input', function () {
      var q = this.value.trim().toLowerCase();
      [].forEach.call(body.querySelectorAll('[data-esc-person-row]'), function (r) {
        r.style.display = !q || r.getAttribute('data-esc-person-row').indexOf(q) >= 0 ? '' : 'none';
      });
    });
    var bell = body.querySelector('[data-esc-ch="bell"]'), push = body.querySelector('[data-esc-ch="push"]');
    if (bell && push) {
      push.addEventListener('change', function () { if (push.checked) bell.checked = true; });
      bell.addEventListener('change', function () { if (!bell.checked) push.checked = false; });
    }
    body.querySelector('#esc-save').addEventListener('click', function () {
      var msg = body.querySelector('#esc-msg'); msg.style.color = '#64748B'; msg.textContent = 'Saving…';
      var ch = {};
      [].forEach.call(body.querySelectorAll('[data-esc-ch]'), function (c) { ch[c.getAttribute('data-esc-ch')] = c.checked; });
      Api.post('/admin/record-escalation', {
        roles: [].map.call(body.querySelectorAll('[data-esc-role]:checked'), function (c) { return c.getAttribute('data-esc-role'); }),
        user_ids: [].map.call(body.querySelectorAll('[data-esc-person]:checked'), function (c) { return +c.getAttribute('data-esc-person'); }),
        channels: ch,
      }).then(function (r) {
        msg.style.color = '#1E8E60'; msg.textContent = '✓ Saved';
        Api.get('/admin/record-escalation').then(function (x) { paintPreview(x.preview); }).catch(function () {});
      }).catch(function (e) { msg.style.color = '#B91C1C'; msg.textContent = (e && e.message) || 'Could not save'; });
    });
  }

  /* ── Immunizations & drills: which staff roles hear about them (2026-11-09) ──
     Anthony: "add to settings to turn off immunization notifications for educators and directors/admins ... also for
     fire drills same thing". Off for a role = no bell, no phone notification and no email about that topic.
     Enforced on the server (App\\Support\\StaffTopicNotify, in Notify::write and every email sender). */
  async function paintTopics(body) {
    var res;
    try { res = await Api.get('/admin/topic-notifications'); } catch (e) { return; }
    var s = res.settings || {}, topics = res.topics || {}, roles = res.roles || {};
    var hints = {
      immunization: 'A parent or the office files an immunization record, and the immunization reminder email to the office. '
        + 'Who gets that reminder email is also set on the Immunizations settings screen; a role switched off here never gets it.',
      drills: 'A drill is recorded, a drill is due or overdue, and the weekday "drills still owed" email.',
    };
    body.innerHTML = '<div class="kt-card" style="max-width:760px;">'
      + '<div class="kt-card-header"><h3 class="kt-card-title">💉 Immunization &amp; drill notifications</h3></div>'
      + '<div style="font-size:12.5px;color:#64748B;line-height:1.5;margin:-4px 0 6px;">Choose which staff roles are told. '
      + 'Switching a role off stops every notice about that topic for people in that role: the bell, the phone notification and the email. '
      + 'Someone with two roles is told if either role is on. Nothing is deleted, and the records stay on the child and in the Drill log.</div>'
      + Object.keys(topics).map(function (t) {
          return '<div style="padding:12px 0;border-bottom:1px solid #F1F5F9;">'
            + '<div style="font-size:14px;color:#334155;font-weight:600;">' + esc(topics[t]) + '</div>'
            + '<div style="font-size:12.5px;color:#64748B;margin-top:2px;">' + esc(hints[t] || '') + '</div>'
            + '<div style="display:flex;flex-wrap:wrap;gap:8px 18px;margin-top:10px;">' + Object.keys(roles).map(function (r) {
                return '<label style="display:inline-flex;align-items:center;gap:6px;font-size:13px;color:#334155;white-space:nowrap;"><input type="checkbox" data-tp="' + t + '" data-tr="' + r + '"'
                  + ((s[t] || {})[r] !== false ? ' checked' : '') + '> ' + esc(roles[r]) + '</label>';
              }).join('') + '</div></div>';
        }).join('')
      + '<div style="display:flex;align-items:center;gap:8px;margin-top:16px;"><button id="tp-save" class="kt-btn kt-btn-primary">Save</button><span id="tp-msg" style="font-size:13px;"></span></div></div>';
    body.querySelector('#tp-save').addEventListener('click', function () {
      var msg = body.querySelector('#tp-msg'); msg.style.color = '#64748B'; msg.textContent = 'Saving…';
      var out = {};
      [].forEach.call(body.querySelectorAll('[data-tp]'), function (c) {
        var t = c.getAttribute('data-tp');
        (out[t] = out[t] || {})[c.getAttribute('data-tr')] = c.checked;
      });
      Api.post('/admin/topic-notifications', { settings: out })
        .then(function () { msg.style.color = '#1E8E60'; msg.textContent = '✓ Saved'; })
        .catch(function (e) { msg.style.color = '#B91C1C'; msg.textContent = (e && e.message) || 'Could not save'; });
    });
  }

  /* ── Staff certificate expiry alerts (2026-11-03, Anthony: "send emails out as well with options with days in
     advance leading up to the expiry date configurable in the settings"). App\\Support\\CertExpiryAlerts, run daily
     at 8:00 AM by expiry:warn. Each step goes out once per certificate; a renewed certificate is never chased. ── */
  async function paintCerts(body) {
    var res;
    try { res = await Api.get('/admin/cert-alerts'); } catch (e) { return; }
    var s = res.settings || {};
    var steps = (s.steps || []).slice();
    var chk = function (id, label, on) {
      return '<label style="display:inline-flex;align-items:center;gap:6px;font-size:13px;color:#334155;white-space:nowrap;"><input type="checkbox" id="' + id + '"' + (on ? ' checked' : '') + '> ' + label + '</label>';
    };
    body.innerHTML = '<div class="kt-card" style="max-width:760px;">'
      + '<div class="kt-card-header"><h3 class="kt-card-title">🎓 Staff certifications — expiry alerts</h3></div>'
      + '<div style="font-size:12.5px;color:#64748B;line-height:1.5;margin:-4px 0 6px;">When a staff certificate (First Aid, CPR, a police check and so on) is about to run out, '
      + 'KiddieTrac tells the people you choose below — a bell and phone notification, and an email if that is on. Each reminder goes out once per certificate. '
      + 'Once someone adds their renewed certificate, the reminders for the old one stop. Sent each morning at 8:00 AM.</div>'
      + row('Expiry alerts', 'Off: nobody is told about expiring certificates.', toggle('ca-on', s.enabled))
      + '<div style="padding:12px 0;border-bottom:1px solid #F1F5F9;">'
      +   '<div style="font-size:14px;color:#334155;font-weight:600;">Remind this many days before it expires</div>'
      +   '<div style="font-size:12.5px;color:#64748B;margin-top:2px;">Up to 8 reminders, 1 to 365 days ahead. A certificate added late gets the reminder it is due, not every one it missed.</div>'
      +   '<div id="ca-steps" style="display:flex;flex-wrap:wrap;gap:6px;margin-top:10px;align-items:center;"></div>'
      +   '<input type="hidden" id="ca-steps-val" value="' + esc(steps.slice().sort(function (a, b) { return b - a; }).join(',')) + '">'
      + '</div>'
      + row('On the day it expires', 'One more reminder the day it runs out.', toggle('ca-day', s.on_day))
      + row('Every week after it expires', 'Keeps reminding weekly until the renewed certificate is added.', toggle('ca-weekly', s.weekly_after))
      + '<div style="padding:12px 0;border-bottom:1px solid #F1F5F9;"><div style="font-size:14px;color:#334155;font-weight:600;margin-bottom:8px;">Who is told</div>'
      +   '<div style="display:flex;flex-wrap:wrap;gap:8px 18px;">'
      +     chk('ca-holder', 'The staff member', s.holder)
      +     chk('ca-dir', 'Their centre directors', s.directors)
      +     chk('ca-admin', 'Agency admins', s.admins)
      +   '</div>'
      +   '<div style="font-size:12.5px;color:#64748B;margin-top:6px;">Directors and admins get one summary per morning listing every certificate that is due a reminder, not one message each.</div></div>'
      + row('Email as well', 'Off: the bell and phone notification only.', toggle('ca-email', s.email))
      + '<div style="display:flex;align-items:center;gap:8px;margin-top:16px;flex-wrap:wrap;">'
      +   '<button id="ca-save" class="kt-btn kt-btn-primary">Save</button>'
      +   '<button id="ca-preview" class="kt-btn" style="border:1px solid #CBD5E1;background:#fff;">Preview the next reminders</button>'
      +   '<span id="ca-msg" style="font-size:13px;"></span></div>'
      + '<div id="ca-out" style="margin-top:12px;"></div></div>';

    var stepsEl = body.querySelector('#ca-steps');
    function paintSteps() {
      steps.sort(function (a, b) { return b - a; });
      /* The chips are not fields, so the Save guard (kt-dirty-save.js) reads the list from this hidden one. */
      var hv = body.querySelector('#ca-steps-val');
      if (hv && hv.value !== steps.join(',')) { hv.value = steps.join(','); hv.dispatchEvent(new Event('change', { bubbles: true })); }
      stepsEl.innerHTML = steps.map(function (d, i) {
        return '<span style="display:inline-flex;align-items:center;gap:4px;height:28px;padding:0 4px 0 10px;border-radius:999px;background:#EEF6FA;border:1px solid #CFE3EC;font-size:13px;color:#1F6080;font-weight:600;">'
          + d + ' day' + (d === 1 ? '' : 's')
          + '<button type="button" data-ca-del="' + i + '" aria-label="Remove ' + d + ' days" style="border:0;background:none;cursor:pointer;color:#64748B;font-size:15px;line-height:1;padding:0 4px;">×</button></span>';
      }).join('')
        + (steps.length < 8 ? '<span style="display:inline-flex;align-items:center;gap:4px;">' + num('ca-add', '', 1, 365)
          + '<button type="button" id="ca-addbtn" class="kt-btn" style="height:30px;border:1px solid #CBD5E1;background:#fff;">Add</button></span>' : '');
      stepsEl.querySelectorAll('[data-ca-del]').forEach(function (b) {
        b.addEventListener('click', function () { steps.splice(+b.getAttribute('data-ca-del'), 1); paintSteps(); });
      });
      var add = stepsEl.querySelector('#ca-addbtn');
      if (add) {
        var addIt = function () {
          var inp = stepsEl.querySelector('#ca-add'), v = parseInt(inp.value, 10);
          if (!(v >= 1 && v <= 365)) { say('Enter a number of days from 1 to 365.', '#B45309'); return; }
          if (steps.indexOf(v) < 0) steps.push(v);
          paintSteps(); say('', '');
        };
        add.addEventListener('click', addIt);
        stepsEl.querySelector('#ca-add').addEventListener('keydown', function (e) { if (e.key === 'Enter') { e.preventDefault(); addIt(); } });
      }
    }
    paintSteps();

    function say(t, c) { var m = body.querySelector('#ca-msg'); m.style.color = c || '#64748B'; m.textContent = t; }
    function vals() {
      var q = function (id) { return body.querySelector('#' + id).checked; };
      return { enabled: q('ca-on'), steps: steps.slice(), on_day: q('ca-day'), weekly_after: q('ca-weekly'),
        holder: q('ca-holder'), directors: q('ca-dir'), admins: q('ca-admin'), email: q('ca-email') };
    }
    body.querySelector('#ca-save').addEventListener('click', function () {
      say('Saving…');
      Api.post('/admin/cert-alerts', vals()).then(function (r) {
        steps = ((r && r.settings && r.settings.steps) || steps).slice(); paintSteps(); say('✓ Saved', '#1E8E60');
        try { if (KT.dirtySave) KT.dirtySave.saved(body.querySelector('#ca-save')); } catch (e) {}
      }).catch(function (e) { say((e && e.message) || 'Could not save', '#B91C1C'); });
    });
    body.querySelector('#ca-preview').addEventListener('click', function () {
      var out = body.querySelector('#ca-out');
      say('Checking…');
      Api.post('/admin/cert-alerts/preview', vals()).then(function (r) {
        say('', '');
        var items = (r && r.items) || [];
        if (!items.length) { out.innerHTML = '<div style="font-size:13px;color:#64748B;">Nothing is due a reminder with these settings. No messages would go out.</div>'; return; }
        out.innerHTML = '<div style="font-size:13px;color:#334155;margin-bottom:6px;"><b>' + items.length + '</b> certificate' + (items.length === 1 ? '' : 's') + ' would get a reminder: '
          + (r.holders ? r.holders + ' staff member' + (r.holders === 1 ? '' : 's') : 'no staff') + ' and '
          + (r.managers ? r.managers + ' director' + (r.managers === 1 ? '' : 's') + '/admin' + (r.managers === 1 ? '' : 's') : 'no directors or admins') + ' told'
          + (r.email ? ', by bell and email.' : ', by bell only.') + ' Nothing has been sent.</div>'
          + '<div style="border:1px solid #E2E8F0;border-radius:10px;overflow:hidden;">' + items.map(function (i) {
            var col = i.days < 0 ? '#B91C1C' : (i.days <= 14 ? '#B45309' : '#334155');
            return '<div style="display:flex;gap:10px;justify-content:space-between;padding:8px 12px;border-top:1px solid #F1F5F9;font-size:13px;flex-wrap:wrap;">'
              + '<span style="color:#0F172A;font-weight:600;">' + esc(i.name) + ' · ' + esc(i.label) + '</span><span style="color:' + col + ';">' + esc(i.phrase) + '</span></div>';
          }).join('') + '</div>';
      }).catch(function (e) { say((e && e.message) || 'Could not check', '#B91C1C'); });
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
