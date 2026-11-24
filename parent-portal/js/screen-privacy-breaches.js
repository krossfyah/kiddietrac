/* ═══════════════════════════════════════════════════════════════════
   KiddieTrac — Privacy breaches (2026-11-06).

   Anthony: "add a breach section in the portal to record these types of
   events - fully wire this up", after the 2026-09-23 immunization emails
   reached provider staff they were never meant for.

   PIPEDA s. 10.3: every breach of security safeguards involving personal
   information is recorded and the record kept 24 months, reported or not.
   s. 10.1: a real risk of significant harm (RROSH) means reporting to the
   Privacy Commissioner and notifying each person as soon as feasible.
   This screen is that register: the list, one record per breach (what
   happened, who and what was involved, containment, the RROSH decision and
   its reasoning, reporting), and a timeline of every change and step.

   Agency admins change it (API: PrivacyBreachController, scoped to the
   active agency); centre directors read their centres' and agency-wide
   records (they get the alert emails). Nothing is ever deleted.
   ═══════════════════════════════════════════════════════════════════ */
(function (window) {
  'use strict';
  var KT = window.KT || {};
  var Shell = KT.Shell, Api = KT.Api;
  if (!Shell || !Api) return;

  var BASE = '/compliance/privacy-breaches';
  var MONTHS = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
  var STATUS = { open: ['Open', '#BE4038', '#FBE9E6'], assessing: ['Assessing', '#AE7015', '#FBF1DC'], reported: ['Reported', '#2C7BA0', '#E3F1F8'], closed: ['Closed', '#1E8E60', '#E7F4EC'] };

  function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"]/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c];
    });
  }
  // YYYY-MM-DD from its parts - never new Date(date), which shifts a day west of UTC.
  function day(ymd) {
    var m = /^(\d{4})-(\d{2})-(\d{2})/.exec(String(ymd || ''));
    return m ? MONTHS[+m[2] - 1] + ' ' + (+m[3]) + ', ' + m[1] : '—';
  }
  function pill(status) {
    var s = STATUS[status] || [status, '#475569', '#F1F5F9'];
    return '<span style="font:700 11px/1 system-ui,sans-serif;padding:4px 9px;border-radius:100px;background:' + s[2] + ';color:' + s[1] + ';white-space:nowrap;">' + esc(s[0]) + '</span>';
  }
  function rroshText(b) {
    if (b.rrosh === 'yes') return '<b style="color:#BE4038;">Yes</b>';
    if (b.rrosh === 'no') return 'No';
    return '<span style="color:#AE7015;">Not decided</span>';
  }
  function toast(icon, title, msg, colour) { if (KT.toast) KT.toast(icon, title, msg || '', colour); }

  var INPUT = 'width:100%;box-sizing:border-box;height:30px;padding:4px 8px;border:1px solid #CBD5E1;border-radius:7px;font-size:13px;background:#fff;color:#0F172A;';
  var AREA = 'width:100%;box-sizing:border-box;min-height:60px;padding:6px 8px;border:1px solid #CBD5E1;border-radius:7px;font-size:13px;font-family:inherit;resize:vertical;background:#fff;color:#0F172A;';
  var BTN = 'height:30px;padding:0 12px;border-radius:8px;border:1px solid #CBD5E1;background:#fff;color:#1E293B;font-size:12.5px;font-weight:600;cursor:pointer;';

  function field(label, html, wide, hint) {
    return '<label style="display:block;' + (wide ? 'grid-column:1/-1;' : '') + '"><span style="display:block;font-size:12px;font-weight:600;color:#475569;margin:0 0 4px;">' + esc(label)
      + (hint ? ' <span style="font-weight:400;color:#94A3B8;">' + esc(hint) + '</span>' : '') + '</span>' + html + '</label>';
  }
  function text(name, v, max) { return '<input name="' + name + '" value="' + esc(v) + '" maxlength="' + (max || 200) + '" style="' + INPUT + '">'; }
  function date(name, v) { return '<input type="date" name="' + name + '" value="' + esc(v ? String(v).slice(0, 10) : '') + '" style="' + INPUT + '">'; }
  function num(name, v) { return '<input type="number" min="0" name="' + name + '" value="' + esc(v == null ? '' : v) + '" style="' + INPUT + '">'; }
  function area(name, v, rows) { return '<textarea name="' + name + '" rows="' + (rows || 3) + '" style="' + AREA + '">' + esc(v) + '</textarea>'; }
  function select(name, v, opts) {
    return '<select name="' + name + '" style="' + INPUT + '">' + opts.map(function (o) {
      return '<option value="' + esc(o[0]) + '"' + (String(v == null ? '' : v) === o[0] ? ' selected' : '') + '>' + esc(o[1]) + '</option>';
    }).join('') + '</select>';
  }
  function section(title, intro, inner) {
    return '<div style="margin:0 0 16px;"><div style="font-size:13.5px;font-weight:700;color:#0F2540;margin:0 0 2px;">' + esc(title) + '</div>'
      + (intro ? '<div style="font-size:12px;color:#64748B;margin:0 0 8px;">' + intro + '</div>' : '')
      + '<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:10px 14px;">' + inner + '</div></div>';
  }

  var LEVELS = [['', 'Choose…'], ['low', 'Low'], ['medium', 'Medium'], ['high', 'High']];

  function formHtml(b, ctx) {
    b = b || {};
    var types = b.info_types || [];
    var centres = [['', 'Whole agency / not one centre']].concat((ctx.centres || []).map(function (c) { return [String(c.id), c.name]; }));
    var checks = Object.keys(ctx.info_types || {}).map(function (k) {
      return '<label style="display:flex;align-items:center;gap:6px;font-size:12.5px;color:#334155;"><input type="checkbox" name="info_types" value="' + esc(k) + '"'
        + (types.indexOf(k) >= 0 ? ' checked' : '') + ' style="flex:0 0 auto;width:15px;height:15px;margin:0;">' + esc(ctx.info_types[k]) + '</label>';
    }).join('');

    return section('What happened', '', ''
        + field('Title', text('title', b.title, 200), true, 'a short name, no one\'s name in it')
        + field('Discovered on', date('discovered_on', b.discovered_on || ctx.today))
        + field('Happened on', date('occurred_on', b.occurred_on))
        + field('Happened until', date('occurred_to', b.occurred_to), false, 'if it ran over days')
        + field('Centre', select('centre_id', b.centre_id == null ? '' : String(b.centre_id), centres))
        + field('Status', select('status', b.status || 'open', [['open', 'Open'], ['assessing', 'Assessing'], ['reported', 'Reported'], ['closed', 'Closed']]))
        + field('Confirmed as a breach on', date('determined_on', b.determined_on), false, 'starts the 24 months')
        + field('What happened', area('description', b.description, 4), true)
        + field('Cause', area('cause', b.cause, 2), true))
      + section('Information and people involved', 'Counts only here. Keep names of children and families out of this record.', ''
        + '<div style="grid-column:1/-1;display:grid;grid-template-columns:repeat(auto-fit,minmax(230px,1fr));gap:6px 14px;" data-kt-nofb>' + checks + '</div>'
        + field('Detail about the information', area('info_detail', b.info_detail, 2), true)
        + field('People affected', num('individuals_affected', b.individuals_affected))
        + field('Children affected', num('children_affected', b.children_affected))
        + field('Who received it or could see it', area('recipients', b.recipients, 2), true))
      + section('Containment', 'What was done to stop it and reduce harm (recall, ask recipients to delete, change passwords, fix the cause).', ''
        + field('Steps taken', area('containment', b.containment, 3), true)
        + field('Contained on', date('contained_on', b.contained_on)))
      + section('Real risk of significant harm', 'Weigh how sensitive the information is and how likely it is to be misused. '
          + 'Significant harm includes humiliation, damage to reputation or relationships, financial loss, identity theft and loss of opportunity. '
          + 'If the answer is yes, report to the Privacy Commissioner and notify the people affected as soon as feasible.', ''
        + field('Sensitivity', select('sensitivity', b.sensitivity, LEVELS))
        + field('Likelihood of misuse', select('misuse_likelihood', b.misuse_likelihood, LEVELS))
        + field('Real risk of significant harm?', select('rrosh', b.rrosh || 'undetermined', [['undetermined', 'Not decided yet'], ['yes', 'Yes'], ['no', 'No']]))
        + field('Reasoning', area('rrosh_reasoning', b.rrosh_reasoning, 3), true, 'why you decided this'))
      + section('Reporting and notification', 'Report through the Commissioner\'s online breach form (priv.gc.ca). Record the dates here.', ''
        + field('Reported to the Commissioner on', date('opc_reported_on', b.opc_reported_on))
        + field('Commissioner file number', text('opc_reference', b.opc_reference, 100))
        + field('People notified on', date('individuals_notified_on', b.individuals_notified_on))
        + field('How they were notified', text('notification_method', b.notification_method, 200))
        + field('Others notified', area('others_notified', b.others_notified, 2), true, 'e.g. the ministry, police, a bank'));
  }

  function collect(root) {
    var out = { info_types: [] };
    root.querySelectorAll('input[name],select[name],textarea[name]').forEach(function (el) {
      if (el.name === 'info_types') { if (el.checked) out.info_types.push(el.value); return; }
      var v = el.value;
      if (el.type === 'number') v = v === '' ? null : parseInt(v, 10);
      else if (el.name === 'centre_id') v = v === '' ? null : parseInt(v, 10);
      else if (v === '' && el.name !== 'title' && el.name !== 'description' && el.name !== 'discovered_on') v = null;
      out[el.name] = v;
    });
    return out;
  }

  function timelineHtml(events) {
    if (!events || !events.length) return '<div style="font-size:12.5px;color:#64748B;">Nothing yet.</div>';
    return events.slice().reverse().map(function (e) {
      var icon = e.kind === 'note' ? '📝' : (e.kind === 'created' ? '🆕' : '✏️');
      return '<div style="display:flex;gap:10px;padding:8px 0;border-top:1px solid #EEF2F6;">'
        + '<div style="flex:0 0 auto;font-size:14px;">' + icon + '</div><div style="min-width:0;">'
        + '<div style="font-size:12px;color:#64748B;">' + esc(e.at || '') + ' · ' + esc(e.by || '') + '</div>'
        + '<div style="font-size:13px;color:#0F172A;white-space:pre-wrap;word-break:break-word;">' + esc(e.body) + '</div></div></div>';
    }).join('');
  }

  function openRecord(main, ctx, id) {
    var wrap = document.createElement('div');
    var isNew = !id;
    function paint(b, events) {
      var due = (b && b.to_do) || [];
      wrap.innerHTML = (b ? '<div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;margin:0 0 12px;font-size:12.5px;color:#475569;">'
            + '<b style="font-family:ui-monospace,monospace;color:#0F2540;">' + esc(b.ref) + '</b>' + pill(b.status)
            + '<span>Keep this record until <b>' + day(b.retain_until) + '</b></span></div>'
            + (due.length ? '<div style="background:#FBF1DC;border:1px solid #F0DCA8;color:#7A4F0E;border-radius:9px;padding:8px 12px;font-size:12.5px;margin:0 0 14px;"><b>Still to do:</b> ' + due.map(esc).join(' · ') + '</div>' : '')
          : '<div style="font-size:12.5px;color:#475569;margin:0 0 12px;">Record every breach of the safeguards around personal information, even one you decide is low risk. '
            + 'Fill in what you know now; you can come back and add to it.</div>')
        + '<form data-pb-form onsubmit="return false">' + formHtml(b, ctx) + '</form>'
        + (b ? '<div style="margin:6px 0 0;"><div style="font-size:13.5px;font-weight:700;color:#0F2540;margin:0 0 6px;">Steps and changes</div>'
            + '<div style="display:flex;gap:8px;margin:0 0 6px;" data-kt-nofb><textarea data-pb-note rows="2" maxlength="4000" placeholder="Add a step taken, e.g. Asked the recipients to delete the email" style="' + AREA + 'min-height:30px;flex:1;"></textarea>'
            + '<button type="button" data-pb-addnote style="' + BTN + 'flex:0 0 auto;align-self:flex-start;">Add step</button></div>'
            + '<div data-pb-timeline>' + timelineHtml(events) + '</div></div>' : '');
      if (!ctx.canEdit) {
        wrap.querySelectorAll('[data-pb-form] input,[data-pb-form] select,[data-pb-form] textarea').forEach(function (el) { el.disabled = true; });
        var noteRow = wrap.querySelector('[data-pb-note]');
        if (noteRow) noteRow.parentElement.remove();
        var lead = document.createElement('div');
        lead.style.cssText = 'font-size:12.5px;color:#475569;margin:0 0 12px;';
        lead.textContent = 'You can read this record. Your agency admins make the changes.';
        wrap.insertBefore(lead, wrap.firstChild);
      }
      var add = wrap.querySelector('[data-pb-addnote]');
      if (add) add.onclick = function () {
        var ta = wrap.querySelector('[data-pb-note]'), body = (ta.value || '').trim();
        if (!body) { ta.focus(); return; }
        add.disabled = true;
        Api.post(BASE + '/' + b.id + '/notes', { body: body }).then(function (r) {
          ta.value = '';
          wrap.querySelector('[data-pb-timeline]').innerHTML = timelineHtml(r.events);
          render(main);
        }).catch(function (e) { toast('⚠️', 'Could not add the step', e && e.message, '#DC2626'); })
          .then(function () { add.disabled = false; });
      };
    }

    var modal = Shell.Modal.open({
      title: isNew ? 'Record a privacy breach' : 'Privacy breach',
      body: wrap,
      large: true,
      // A centre director reads; only an admin changes it (the server refuses the write either way).
      actions: !ctx.canEdit ? [{ label: 'Close' }] : [
        { label: 'Close' },
        { label: isNew ? 'Record breach' : 'Save changes', primary: true, busyLabel: 'Saving…', handler: function () {
          var data = collect(wrap.querySelector('[data-pb-form]'));
          if (!data.title || !data.description || !data.discovered_on) {
            toast('⚠️', 'A few things are needed', 'Title, Discovered on and What happened.', '#DC2626');
            return false;
          }
          var call = isNew ? Api.post(BASE, data) : Api.patch(BASE + '/' + id, data);
          return call.then(function (r) {
            toast('✅', isNew ? 'Breach recorded' : 'Saved', r && r.breach ? r.breach.ref : '', '#16A34A');
            render(main);
            // A new record closes and reopens as itself, so the timeline and "Save changes" appear.
            if (isNew && r && r.breach) { setTimeout(function () { openRecord(main, ctx, r.breach.id); }, 0); return; }
            paint(r.breach, r.events);
            return false;
          }).catch(function (e) { toast('⚠️', 'Could not save', e && e.message, '#DC2626'); return false; });
        } }
      ]
    });

    if (isNew) { paint(null, []); return modal; }
    wrap.innerHTML = '<div style="padding:20px;color:#64748B;font-size:13px;">Loading…</div>';
    Api.get(BASE + '/' + id).then(function (r) { paint(r.breach, r.events); })
      .catch(function (e) { wrap.innerHTML = '<div style="padding:20px;color:#BE4038;font-size:13px;">' + esc((e && e.message) || 'Could not open this record.') + '</div>'; });
    return modal;
  }

  /* WHO GETS THE ALERTS (2026-11-06, Anthony: "wire up settings for the breach to allow who gets emails,
     notifications etc"). Server: PrivacyBreachAlerts::settings, agencies.settings.privacy_breach_alerts. */
  function check(name, on, label, hint) {
    return '<label style="display:flex;align-items:flex-start;gap:8px;font-size:13px;color:#0F172A;margin:0 0 8px;">'
      + '<input type="checkbox" data-s="' + name + '"' + (on ? ' checked' : '') + ' style="flex:0 0 auto;width:15px;height:15px;margin:2px 0 0;">'
      + '<span>' + esc(label) + (hint ? '<span style="display:block;font-size:12px;color:#64748B;">' + esc(hint) + '</span>' : '') + '</span></label>';
  }
  function hourLabel(h) { return (h % 12 || 12) + ':00 ' + (h < 12 ? 'AM' : 'PM'); }

  function openSettings() {
    var wrap = document.createElement('div');
    var canPlatform = false;   // the Platform admins switch: platform admins change it, agencies see it
    wrap.innerHTML = '<div style="padding:20px;color:#64748B;font-size:13px;">Loading…</div>';
    Shell.Modal.open({
      title: 'Privacy breach alerts',
      body: wrap,
      large: true,
      actions: [
        { label: 'Close' },
        { label: 'Save', primary: true, busyLabel: 'Saving…', handler: function () {
          var q = function (n) { var el = wrap.querySelector('[data-s="' + n + '"]'); return el ? el.checked : false; };
          var mails = (wrap.querySelector('[data-s="extra_emails"]').value || '').split(/[\s,;]+/).map(function (s) { return s.trim(); }).filter(Boolean);
          var bad = mails.filter(function (m) { return !/^[^@\s]+@[^@\s]+\.[^@\s]+$/.test(m); });
          if (bad.length) { toast('⚠️', 'Check the email addresses', bad.join(', '), '#DC2626'); return false; }
          if (mails.length > 5) { toast('⚠️', 'Up to 5 extra addresses', '', '#DC2626'); return false; }
          var muted = [];
          wrap.querySelectorAll('[data-person]').forEach(function (el) { if (!el.checked) muted.push(+el.getAttribute('data-person')); });
          var body = {
            admins: q('admins'), directors: q('directors'), email: q('email'), bell: q('bell'), reminders: q('reminders'),
            muted: muted, extra_emails: mails,
            events: { recorded: q('ev_recorded'), risk: q('ev_risk'), reported: q('ev_reported'), closed: q('ev_closed') },
            reminder_hour: +wrap.querySelector('[data-s="reminder_hour"]').value,
            overdue_days: +wrap.querySelector('[data-s="overdue_days"]').value || 7
          };
          if (canPlatform) body.platform = q('platform');
          return Api.post(BASE + '/alert-settings', body).then(function () {
            toast('✅', 'Alert settings saved', '', '#16A34A');
          });
        } }
      ]
    });
    Api.get(BASE + '/alert-settings').then(function (r) {
      var s = r.settings || {}, ev = s.events || {}, muted = s.muted || [];
      canPlatform = !!r.can_platform;
      var people = (r.people || []).map(function (p) {
        return '<label style="display:flex;align-items:center;gap:8px;font-size:13px;color:#0F172A;padding:5px 0;border-top:1px solid #EEF2F6;">'
          + '<input type="checkbox" data-person="' + p.user_id + '"' + (muted.indexOf(p.user_id) < 0 ? ' checked' : '') + ' style="flex:0 0 auto;width:15px;height:15px;margin:0;">'
          + '<span style="flex:1;min-width:0;">' + esc(p.name) + '</span>'
          + '<span style="font-size:12px;color:#64748B;text-align:right;">' + (p.admin ? 'Agency admin' : 'Director · ' + esc((p.centres || []).join(', '))) + '</span></label>';
      }).join('') || '<div style="font-size:12.5px;color:#64748B;">No admins or directors yet.</div>';
      var hours = '';
      for (var h = 0; h < 24; h++) hours += '<option value="' + h + '"' + (h === s.reminder_hour ? ' selected' : '') + '>' + hourLabel(h) + '</option>';
      wrap.innerHTML = section('Who gets them', 'Directors only hear about breaches at their own centres and agency-wide ones, and can open those records read-only.', ''
          + '<div>' + check('platform', s.platform, 'Platform admins', canPlatform ? 'KiddieTrac platform admins, for every agency' : 'KiddieTrac platform admins. Only they can change this.')
          + check('admins', s.admins, 'Agency admins') + check('directors', s.directors, 'Centre directors') + '</div>'
          + '<div style="grid-column:1/-1;"><div style="font-size:12px;font-weight:600;color:#475569;margin:0 0 4px;">People (untick to leave someone out)</div>' + people + '</div>'
          + field('Also email these addresses', '<textarea data-s="extra_emails" rows="2" style="' + AREA + '" placeholder="privacy.officer@example.com">' + esc((s.extra_emails || []).join('\n')) + '</textarea>', true,
              'up to 5, e.g. a privacy officer or advisor. They get the summary email only and cannot open the register.'))
        + section('How', '', ''
          + '<div>' + check('email', s.email, 'Email') + check('bell', s.bell, 'Bell and phone notification') + '</div>')
        + section('When', '', ''
          + '<div>' + check('ev_recorded', ev.recorded, 'A breach is recorded')
          + check('ev_risk', ev.risk, 'Real risk of significant harm is set to Yes', 'Reporting to the Commissioner and notifying people is now due')
          + check('ev_reported', ev.reported, 'It is reported to the Commissioner')
          + check('ev_closed', ev.closed, 'It is closed') + '</div>'
          + '<div>' + check('reminders', s.reminders, 'Daily reminder while a breach is overdue', 'One email per person listing every overdue breach')
          + '<div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;font-size:13px;color:#334155;margin:4px 0 0 23px;" data-kt-nofb>'
          + 'Send at <select data-s="reminder_hour" style="' + INPUT + 'width:auto;">' + hours + '</select>'
          + ' when a risk decision is still open after <input type="number" min="1" max="30" data-s="overdue_days" value="' + esc(s.overdue_days) + '" style="' + INPUT + 'width:60px;"> days</div></div>');
      if (!canPlatform) { var pf = wrap.querySelector('[data-s="platform"]'); if (pf) pf.disabled = true; }
    }).catch(function (e) { wrap.innerHTML = '<div style="padding:20px;color:#BE4038;font-size:13px;">' + esc((e && e.message) || 'Could not load the settings.') + '</div>'; });
  }

  function exportCsv(btn, today) {
    if (!KT.fetchFileBlob) return;
    var base = KT.API_BASE || 'https://api.kiddietrac.com/api/v1';
    btn.disabled = true;
    KT.fetchFileBlob(base + BASE + '/export').then(function (b) {
      var a = document.createElement('a');
      a.href = URL.createObjectURL(new Blob([b], { type: 'text/csv' }));
      a.download = 'privacy-breach-register-' + today + '.csv';
      document.body.appendChild(a); a.click(); a.remove();
      setTimeout(function () { URL.revokeObjectURL(a.href); }, 60000);
    }).catch(function (e) { toast('⚠️', 'Download failed', e && e.message, '#DC2626'); })
      .then(function () { btn.disabled = false; });
  }

  function tile(n, label, colour) {
    return '<div style="background:#fff;border:1px solid #E7EBF0;border-radius:12px;padding:10px 16px;min-width:130px;">'
      + '<div style="font:600 24px/1 ui-monospace,monospace;color:' + colour + ';">' + n + '</div>'
      + '<div style="font-size:12px;color:#64748B;margin-top:5px;">' + esc(label) + '</div></div>';
  }

  async function render(main) {
    main.setAttribute('data-kt-pretty', '1');
    var head = '<div class="kt-page-hero"><h2>🔓 Privacy breaches</h2><p>The record of every breach of the safeguards around personal information, '
      + 'kept at least 24 months as PIPEDA requires, whether or not it was reported.</p></div>';
    if (!main.querySelector('[data-pb-root]')) main.innerHTML = '<div class="kt-section" data-pb-root>' + head + '<p style="color:#64748B;font-size:13px;">Loading…</p></div>';
    var data;
    try { data = await Api.get(BASE); }
    catch (e) {
      main.innerHTML = '<div class="kt-section" data-pb-root>' + head
        + '<div style="background:#FEF3C7;border:1px solid #FDE68A;color:#92400E;border-radius:12px;padding:14px 16px;max-width:640px;font-size:13px;">'
        + esc((e && e.message) || 'Only an agency admin can see the privacy breach register.') + '</div></div>';
      return;
    }
    var list = data.breaches || [], c = data.counts || {};
    var rows = list.length ? list.map(function (b) {
      var due = b.to_do || [];
      return '<tr>'
        + '<td style="font-family:ui-monospace,monospace;font-size:12.5px;white-space:nowrap;">' + esc(b.ref) + '</td>'
        + '<td><b style="font-size:13px;">' + esc(b.title) + '</b>' + (b.centre_name ? '<div style="font-size:12px;color:#64748B;">' + esc(b.centre_name) + '</div>' : '') + '</td>'
        + '<td style="white-space:nowrap;">' + day(b.discovered_on) + '</td>'
        + '<td>' + pill(b.status) + '</td>'
        + '<td>' + rroshText(b) + '</td>'
        + '<td style="font-size:12px;color:' + (due.length ? '#AE7015' : '#1E8E60') + ';">' + (due.length ? due.map(esc).join('<br>') : 'Nothing outstanding') + '</td>'
        + '<td style="white-space:nowrap;">' + day(b.retain_until) + '</td>'
        + '<td style="text-align:right;"><button type="button" data-pb-open="' + b.id + '" style="' + BTN + '">Open ' + esc(b.ref) + '</button></td>'
        + '</tr>';
    }).join('') : '<tr><td colspan="8" style="padding:28px;text-align:center;color:#64748B;">No breaches recorded. When one happens, record it here the same day, even if it looks minor.</td></tr>';

    var warn = '';
    if (c.report_due) warn += '<div style="background:#FBE9E6;border:1px solid #F2C9C3;color:#8F2A23;border-radius:10px;padding:9px 14px;font-size:13px;margin:0 0 10px;">'
      + '<b>' + c.report_due + '</b> breach' + (c.report_due === 1 ? ' has' : 'es have') + ' a real risk of significant harm and still need' + (c.report_due === 1 ? 's' : '')
      + ' reporting to the Privacy Commissioner or notifying the people affected. PIPEDA says as soon as feasible.</div>';
    var stale = list.filter(function (b) { return b.status !== 'closed' && b.rrosh === 'undetermined' && b.days_open > 7; }).length;
    if (stale) warn += '<div style="background:#FBF1DC;border:1px solid #F0DCA8;color:#7A4F0E;border-radius:10px;padding:9px 14px;font-size:13px;margin:0 0 10px;">'
      + '<b>' + stale + '</b> breach' + (stale === 1 ? ' was' : 'es were') + ' discovered over a week ago without a decision on the risk of harm.</div>';

    main.innerHTML = '<div class="kt-section" data-pb-root>' + head
      + '<div style="display:flex;gap:10px;flex-wrap:wrap;align-items:center;margin:0 0 14px;">'
      +   tile(c.open || 0, 'Open', c.open ? '#BE4038' : '#1E8E60')
      +   tile(c.needs_decision || 0, 'Risk not decided', c.needs_decision ? '#AE7015' : '#1E8E60')
      +   tile(c.report_due || 0, 'Report or notify due', c.report_due ? '#BE4038' : '#1E8E60')
      +   tile(c.kept || 0, 'On record', '#0F2540')
      +   (data.can_edit
          ? '<div style="margin-left:auto;display:flex;gap:8px;">'
            + '<button type="button" data-pb-settings style="' + BTN + '" title="Who gets breach emails and notifications, and when">⚙ Alert settings</button>'
            + '<button type="button" data-pb-export style="' + BTN + '" title="The whole register as a spreadsheet">⤓ Download register</button>'
            + '<button type="button" data-pb-new style="' + BTN + 'background:#0F2540;border-color:#0F2540;color:#fff;">+ Record a breach</button>'
            + '</div>'
          : '<div style="margin-left:auto;font-size:12.5px;color:#64748B;max-width:340px;">Breaches at your centres and agency-wide. To record one, tell your agency admin the same day.</div>')
      + '</div>'
      + warn
      + '<div class="kt-card"><table><thead><tr><th>Ref</th><th>Breach</th><th>Discovered</th><th>Status</th><th>Real risk of harm</th><th>Still to do</th><th>Keep until</th><th></th></tr></thead>'
      + '<tbody>' + rows + '</tbody></table></div></div>';

    var ctx = { centres: data.centres, info_types: data.info_types, today: data.today, canEdit: !!data.can_edit };
    var nb = main.querySelector('[data-pb-new]'), xb = main.querySelector('[data-pb-export]');
    if (nb) nb.onclick = function () { openRecord(main, ctx, null); };
    if (xb) xb.onclick = function () { exportCsv(this, data.today); };
    var sb = main.querySelector('[data-pb-settings]');
    if (sb) sb.onclick = openSettings;
    main.querySelectorAll('[data-pb-open]').forEach(function (b) {
      b.onclick = function () { openRecord(main, ctx, +b.getAttribute('data-pb-open')); };
    });
    // The alert emails and bell link to #privacy-breaches?id=N: open that record once, then drop the id so a
    // redraw (after a save) does not open it again.
    var m = /[?&]id=(\d+)/.exec(location.hash || '');
    if (m) {
      try { history.replaceState(null, '', location.pathname + location.search + '#privacy-breaches'); } catch (e) {}
      openRecord(main, ctx, +m[1]);
    }
  }

  /* centre_director (2026-11-13 role audit): the director menu has offered Privacy breaches since 2026-11-06 and the
     server answers them (their centres, can_edit false = read-only), but the screen was never registered for them, so
     the menu item silently drew the dashboard. */
  ['agency_admin', 'platform_admin', 'centre_director'].forEach(function (role) {
    Shell.registerScreen(role + ':privacy-breaches', render);
  });
})(window);
