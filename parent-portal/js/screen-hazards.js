/* ═══════════════════════════════════════════════════════════════════════
   Hazards (#hazards) — 2026-10-13

   Ontario O. Reg. 137/15 s.24.3 (added by O. Reg. 197/26, in force 2027-01-01): parents
   are told about any non-routine hazard at the premises and the steps taken to deal with
   it. Anyone on staff can REPORT a hazard (educators see it first; their director is told
   at once). Directors (their centres) and agency admins manage: edit, record the steps
   taken, resolve, and send the parent notice / resolution notice — in-app + email, never a
   text. Times are in the agency's timezone.
   ═══════════════════════════════════════════════════════════════════════ */
(function (window) {
  'use strict';
  var KT = window.KT;
  if (!KT || !KT.Shell || !KT.Shell.registerScreen) return;
  var Api = KT.Api;

  function esc(s) { return String(s == null ? '' : s).replace(/[&<>"]/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]; }); }
  function toast(i, t, m, c) { try { KT.toast && KT.toast(i, t, m || '', c); } catch (e) {} }
  function errMsg(e) { return (e && (e.message || (e.data && e.data.message))) || 'Something went wrong.'; }
  var MON = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
  // "2026-10-13 14:05" (already agency time) → "Oct 13, 2026 2:05 PM", from its own parts.
  function when(s) {
    var m = /^(\d{4})-(\d{2})-(\d{2})(?: (\d{2}):(\d{2}))?/.exec(s || ''); if (!m) return '';
    var out = MON[+m[2] - 1] + ' ' + (+m[3]) + ', ' + m[1];
    if (m[4] != null) { var h = +m[4]; out += ' ' + ((h % 12) || 12) + ':' + m[5] + ' ' + (h < 12 ? 'AM' : 'PM'); }
    return out;
  }
  function nowLocal(tz) {
    try {
      var p = {}; new Intl.DateTimeFormat('en-CA', { timeZone: tz, year: 'numeric', month: '2-digit', day: '2-digit', hour: '2-digit', minute: '2-digit', hourCycle: 'h23' })
        .formatToParts(new Date()).forEach(function (x) { p[x.type] = x.value; });
      return p.year + '-' + p.month + '-' + p.day + 'T' + p.hour + ':' + p.minute;
    } catch (e) { return ''; }
  }
  var SEV = { low: ['#F1F5F9', '#475569'], medium: ['#FFFBEB', '#B45309'], high: ['#FEF2F2', '#B91C1C'] };
  function pill(bg, fg, t) { return '<span data-kt-no-tone style="display:inline-block;padding:2px 9px;border-radius:999px;font-size:12px;font-weight:700;background:' + bg + ';color:' + fg + ';white-space:nowrap;">' + esc(t) + '</span>'; }
  function sevPill(s, labels) { var c = SEV[s] || SEV.medium; return pill(c[0], c[1], (labels && labels[s]) || s); }

  var CSS_ID = 'kt-hazards-css';
  function css() {
    if (document.getElementById(CSS_ID)) return;
    var s = document.createElement('style'); s.id = CSS_ID;
    s.textContent = '.hz-wrap{padding:20px 24px;max-width:1800px;margin:0 auto;}'
      + '.hz-card{background:#fff;border:1px solid #E2E8F0;border-radius:12px;padding:14px 16px;margin-top:14px;}'
      + '.hz-row{display:flex;gap:8px;align-items:center;flex-wrap:wrap;}'
      + '.hz-i{height:32px;padding:0 10px;border:1px solid #CBD5E1;border-radius:8px;font-size:13px;background:#fff;box-sizing:border-box;max-width:100%;}'
      + 'textarea.hz-i{height:auto;min-height:60px;padding:6px 10px;font-family:inherit;resize:vertical;}'
      + '.hz-b{height:32px;padding:0 12px;border-radius:8px;border:1px solid #CBD5E1;background:#fff;font-weight:700;font-size:13px;cursor:pointer;}'
      + '.hz-b.p{background:#C2410C;border-color:#C2410C;color:#fff;}'
      + '.hz-tab{height:30px;padding:0 12px;border-radius:999px;border:1px solid #E2E8F0;background:#fff;font-size:12.5px;font-weight:700;cursor:pointer;color:#475569;}'
      + '.hz-tab.on{background:#0F172A;border-color:#0F172A;color:#fff;}'
      + '.hz-g{display:grid;grid-template-columns:repeat(auto-fit,minmax(min(200px,100%),1fr));gap:10px;}'
      + '.hz-l{display:block;font-size:11.5px;font-weight:800;color:#334155;text-transform:uppercase;letter-spacing:.4px;margin-bottom:4px;}'
      + '.hz-item{border-top:1px solid #F1F5F9;padding:10px 0;}'
      + '.hz-sub{font-size:12px;color:#64748B;}';
    document.head.appendChild(s);
  }

  var state = { status: 'open', centre: '' };
  var lastD = null;   // the last /hazards answer (centres, severities, timezone) for the dialogs

  /* The report / edit form, used inline (educators) and in the dialog (managers). */
  function formHtml(d, h, manager) {
    h = h || {};
    var centres = (d.centres || []);
    var sevs = d.severities || {};
    return '<div class="hz-g">'
      + (h.id ? '' : '<div><label class="hz-l">Centre</label><select class="hz-i" data-f="centre_id" style="width:100%;">' + centres.map(function (c) { return '<option value="' + c.id + '"' + (String(c.id) === String(state.centre) ? ' selected' : '') + '>' + esc(c.name) + '</option>'; }).join('') + '</select></div>')
      + '<div><label class="hz-l">Hazard</label><input class="hz-i" data-f="title" maxlength="200" placeholder="e.g. Broken fence board" value="' + esc(h.title || '') + '" style="width:100%;"></div>'
      + '<div><label class="hz-l">Where</label><input class="hz-i" data-f="location" maxlength="255" placeholder="Room or area" value="' + esc(h.location || '') + '" style="width:100%;"></div>'
      + '<div><label class="hz-l">Severity</label><select class="hz-i" data-f="severity" style="width:100%;">' + Object.keys(sevs).map(function (k) { return '<option value="' + k + '"' + (k === (h.severity || 'medium') ? ' selected' : '') + '>' + esc(sevs[k]) + '</option>'; }).join('') + '</select></div>'
      + '<div><label class="hz-l">Identified</label><input class="hz-i" data-f="identified_at" type="datetime-local" value="' + esc(h.identified_local ? h.identified_local.replace(' ', 'T') : nowLocal(d.timezone)) + '" style="width:100%;"></div>'
      + '</div>'
      + '<div class="hz-g" style="margin-top:10px;"><div><label class="hz-l">What is it</label><textarea class="hz-i" data-f="description" maxlength="4000" rows="2" placeholder="What you saw" style="width:100%;">' + esc(h.description || '') + '</textarea></div>'
      + '<div><label class="hz-l">Steps taken</label><textarea class="hz-i" data-f="steps_taken" maxlength="4000" rows="2" placeholder="What was done to keep children safe" style="width:100%;">' + esc(h.steps_taken || '') + '</textarea></div></div>'
      + (manager ? '<label style="display:flex;gap:8px;align-items:center;font-size:13px;margin-top:8px;cursor:pointer;"><input type="checkbox" data-f="notify_parents"' + (h.notify_parents ? ' checked' : '') + '> Not routine — parents need to be told (Ontario s.24.3)</label>' : '')
      + '<div class="hz-msg" style="font-size:13px;margin-top:6px;"></div>';
  }
  function readForm(root) {
    var o = {};
    root.querySelectorAll('[data-f]').forEach(function (el) {
      var k = el.getAttribute('data-f');
      o[k] = el.type === 'checkbox' ? el.checked : (el.value.trim() === '' ? null : el.value.trim());
    });
    if (o.centre_id) o.centre_id = +o.centre_id;
    return o;
  }

  async function render(main) {
    css();
    main.setAttribute('data-kt-pretty', '1');
    main.innerHTML = '<div class="hz-wrap" style="color:#64748B;">Loading hazards…</div>';
    var q = ['status=' + state.status]; if (state.centre) q.push('centre_id=' + state.centre);
    var d;
    try { d = await Api.get('/hazards?' + q.join('&')); }
    catch (e) { main.innerHTML = '<div class="hz-wrap" style="color:#B91C1C;">' + esc(errMsg(e)) + '</div>'; return; }
    lastD = d;
    var manager = !!d.can_manage_any;

    var h = '<div class="hz-wrap"><div class="kt-hero" style="background:linear-gradient(135deg,#431407 0%,#9A3412 55%,#EA580C 100%);">'
      + '<div class="kt-hero-greet">🚧 SAFETY</div><h1>' + (manager ? 'Hazards' : 'Report a hazard') + '</h1>'
      + '<div class="kt-hero-sub">' + (manager
        ? 'Non-routine hazards at your centres and what was done about them. Parents must be told about them and the steps taken (Ontario s.24.3) — send the notice from here.'
        : 'Spotted something unsafe — a broken gate, a spill, a loose board? Report it here and your director is told straight away.') + '</div></div>';

    if (!manager) {
      h += '<div class="hz-card"><div style="font-weight:800;font-size:15px;margin-bottom:8px;">Report a hazard</div><div id="hz-form">' + formHtml(d, null, false) + '</div>'
        + '<div class="hz-row" style="margin-top:10px;"><button class="hz-b p" id="hz-send" type="button">Report hazard</button></div></div>';
      h += '<div class="hz-card"><div style="font-weight:800;font-size:15px;margin-bottom:4px;">Open hazards at your centre</div>'
        + ((d.hazards || []).length ? (d.hazards || []).map(function (x) {
            return '<div class="hz-item"><div class="hz-row" style="justify-content:space-between;"><div style="font-weight:700;font-size:13.5px;min-width:0;">' + esc(x.title) + '</div>' + sevPill(x.severity, d.severities) + '</div>'
              + '<div class="hz-sub">' + esc([x.location, x.centre_name, when(x.identified_local)].filter(Boolean).join(' · ')) + '</div>'
              + (x.steps_taken ? '<div style="font-size:12.5px;color:#334155;margin-top:4px;"><b>Steps taken:</b> ' + esc(x.steps_taken) + '</div>' : '') + '</div>';
          }).join('') : '<div style="color:#64748B;font-size:13px;">Nothing open. Thank you for keeping an eye out.</div>')
        + '</div></div>';
      main.innerHTML = h;
      main.querySelector('#hz-send').addEventListener('click', async function () {
        var b = this, f = main.querySelector('#hz-form'); if (!f) return;
        var msg = f.querySelector('.hz-msg'), body = readForm(f);
        if (!body.title) { msg.style.color = '#B91C1C'; msg.textContent = 'Say what the hazard is.'; return; }
        b.disabled = true; msg.style.color = '#64748B'; msg.textContent = 'Sending…';
        try { await Api.post('/hazards', body); toast('🚧', 'Hazard reported', 'Your director has been told.', '#16A34A'); render(main); }
        catch (e) { b.disabled = false; msg.style.color = '#B91C1C'; msg.textContent = errMsg(e); }
      });
      return;
    }

    // Manager view. The tab strip is role=tablist so kt-icon-buttons leaves "Open" as a word.
    h += '<div class="hz-card"><div class="hz-row" style="justify-content:space-between;">'
      + '<div class="hz-row" role="tablist">' + [['open', 'Open (' + (d.open_count || 0) + ')'], ['resolved', 'Resolved'], ['all', 'All hazards']].map(function (t) { return '<button type="button" role="tab" aria-selected="' + (t[0] === state.status) + '" class="hz-tab' + (t[0] === state.status ? ' on' : '') + '" data-tab="' + t[0] + '">' + esc(t[1]) + '</button>'; }).join('') + '</div>'
      + '<div class="hz-row">' + ((d.centres || []).length > 1 ? '<select class="hz-i" id="hz-fc"><option value="">All my centres</option>' + d.centres.map(function (c) { return '<option value="' + c.id + '"' + (String(c.id) === String(state.centre) ? ' selected' : '') + '>' + esc(c.name) + '</option>'; }).join('') + '</select>' : '')
      + '<button class="hz-b p" id="hz-new" type="button">Report a hazard</button></div></div></div>';

    var rows = d.hazards || [];
    h += '<div class="kt-card" style="margin-top:14px;">' + (rows.length
      ? '<table><thead><tr><th>Identified</th><th>Hazard</th><th>Centre</th><th>Severity</th><th>Steps taken</th><th>Parents</th><th>Status</th><th></th></tr></thead><tbody>'
        + rows.map(function (x) {
            var parents = x.notified_local ? 'Notified ' + esc(when(x.notified_local)) + (x.notified_families != null ? ' · ' + x.notified_families + ' famil' + (x.notified_families === 1 ? 'y' : 'ies') : '')
                + (x.resolution_notified_local ? '<br>Resolution sent ' + esc(when(x.resolution_notified_local)) : '')
              : (x.notify_parents ? '<span style="color:#B45309;font-weight:700;">Not yet told</span>' : '<span class="hz-sub">Not sent</span>');
            var acts = '';
            if (x.can_manage) {
              acts += '<button type="button" data-act="edit" data-id="' + x.id + '">Edit</button>';
              acts += '<button type="button" data-act="notify" data-id="' + x.id + '">' + (x.notified_local ? 'Notify parents again' : 'Notify parents') + '</button>';
              if (x.status === 'open') acts += '<button type="button" data-act="resolve" data-id="' + x.id + '">Mark resolved</button>';
              else {
                acts += '<button type="button" data-act="resnotice" data-id="' + x.id + '">' + (x.resolution_notified_local ? 'Resend resolution notice' : 'Tell parents it is resolved') + '</button>';
                acts += '<button type="button" data-act="reopen" data-id="' + x.id + '">Reopen</button>';
              }
            }
            return '<tr><td style="white-space:nowrap;">' + esc(when(x.identified_local)) + '</td>'
              + '<td><b>' + esc(x.title) + '</b>' + (x.location ? '<div class="hz-sub">' + esc(x.location) + '</div>' : '') + (x.description ? '<div class="hz-sub">' + esc(x.description) + '</div>' : '')
              + (x.reported_by_name ? '<div class="hz-sub">Reported by ' + esc(x.reported_by_name) + '</div>' : '') + '</td>'
              + '<td>' + esc(x.centre_name) + '</td><td>' + sevPill(x.severity, d.severities) + '</td>'
              + '<td>' + (x.steps_taken ? esc(x.steps_taken) : '<span style="color:#B91C1C;font-size:12.5px;">Not recorded yet</span>') + (x.resolution_note ? '<div class="hz-sub">Resolved: ' + esc(x.resolution_note) + '</div>' : '') + '</td>'
              + '<td style="font-size:12.5px;">' + parents + '</td>'
              + '<td>' + (x.status === 'open' ? pill('#FFF7ED', '#C2410C', 'Open') : pill('#ECFDF5', '#047857', 'Resolved') + '<div class="hz-sub">' + esc(when(x.resolved_local)) + '</div>') + '</td>'
              + '<td style="text-align:center;white-space:nowrap;">' + acts + '</td></tr>';
          }).join('') + '</tbody></table>'
      : '<div style="padding:16px;color:#64748B;font-size:13px;">' + (state.status === 'open' ? 'No open hazards.' : 'Nothing here yet.') + '</div>') + '</div></div>';
    main.innerHTML = h;

    var byId = {}; rows.forEach(function (x) { byId[x.id] = x; });
    main.querySelectorAll('[data-tab]').forEach(function (b) { b.addEventListener('click', function () { state.status = b.getAttribute('data-tab'); render(main); }); });
    var fc = main.querySelector('#hz-fc'); if (fc) fc.addEventListener('change', function () { state.centre = fc.value; render(main); });
    main.querySelector('#hz-new').addEventListener('click', function () { openForm(main, d, null); });
    main.querySelectorAll('[data-act]').forEach(function (b) {
      b.addEventListener('click', function () {
        var x = byId[b.getAttribute('data-id')]; if (!x) return;
        var a = b.getAttribute('data-act');
        if (a === 'edit') openForm(main, d, x);
        else if (a === 'notify') notify(main, x, 'notice');
        else if (a === 'resnotice') notify(main, x, 'resolution');
        else if (a === 'resolve') resolve(main, x);
        else if (a === 'reopen') Api.post('/hazards/' + x.id + '/reopen', {}).then(function () { toast('🚧', 'Hazard reopened', '', '#16A34A'); render(main); }, function (e) { toast('⚠️', 'Could not reopen', errMsg(e), '#DC2626'); });
      });
    });
  }

  function openForm(main, d, x) {
    var body = document.createElement('div'); body.innerHTML = formHtml(d, x, true);
    KT.Shell.Modal.open({ title: x ? 'Edit hazard' : 'Report a hazard', body: body, actions: [{ label: 'Cancel' }, { label: x ? 'Save' : 'Report hazard', style: 'btn-primary', busyLabel: 'Saving…', handler: function () {
      var msg = body.querySelector('.hz-msg'), o = readForm(body);
      if (!o.title) { msg.style.color = '#B91C1C'; msg.textContent = 'Say what the hazard is.'; return false; }
      return (x ? Api.patch('/hazards/' + x.id, o) : Api.post('/hazards', o)).then(function () {
        toast('🚧', x ? 'Hazard saved' : 'Hazard recorded', o.notify_parents && !(x && x.notified_local) ? 'Use Notify parents when the steps taken are recorded.' : '', '#16A34A'); render(main);
      }, function (e) { msg.style.color = '#B91C1C'; msg.textContent = errMsg(e); return false; });
    } }] });
  }

  async function notify(main, x, kind) {
    if (kind === 'notice' && !x.steps_taken) { toast('⚠️', 'Record the steps taken first', 'The notice tells parents what was done.', '#DC2626'); openForm(main, lastD, x); return; }
    var a;
    try { a = await Api.get('/hazards/' + x.id + '/audience'); } catch (e) { toast('⚠️', 'Could not count parents', errMsg(e), '#DC2626'); return; }
    if (!a.parents) { toast('⚠️', 'Nobody to notify', 'This centre has no current families with a parent account.', '#DC2626'); return; }
    var again = kind === 'notice' ? x.notified_local : x.resolution_notified_local;
    var ok = await KT.confirm({
      title: kind === 'notice' ? (again ? 'Send the safety notice again?' : 'Notify parents?') : 'Tell parents it is resolved?',
      description: 'Sends ' + (kind === 'notice' ? 'a safety notice about "' + x.title + '" — what it is, where, and the steps taken —' : 'a short update that "' + x.title + '" has been resolved')
        + ' to ' + a.parents + ' parent' + (a.parents === 1 ? '' : 's') + ' in ' + a.families + ' current famil' + (a.families === 1 ? 'y' : 'ies') + ' at ' + x.centre_name
        + ': in the app, and by email to ' + a.emails + '. No text messages.' + (again ? ' They were already told on ' + when(again) + '.' : ''),
      okLabel: kind === 'notice' ? 'Send notice' : 'Send update'
    });
    if (!ok) return;
    try {
      var r = await Api.post('/hazards/' + x.id + '/notify', { kind: kind });
      toast('📣', kind === 'notice' ? 'Parents notified' : 'Resolution sent', r.families + ' famil' + (r.families === 1 ? 'y' : 'ies') + ': ' + r.in_app + ' in the app, ' + r.emails_sent + ' email' + (r.emails_sent === 1 ? '' : 's')
        + (r.emails_suppressed ? ' (' + r.emails_suppressed + ' held back by email settings)' : '') + (r.emails_failed ? ', ' + r.emails_failed + ' failed' : ''), r.emails_suppressed || r.emails_failed ? '#B45309' : '#16A34A');
      render(main);
    } catch (e) { toast('⚠️', 'Could not notify parents', errMsg(e), '#DC2626'); }
  }

  async function resolve(main, x) {
    var note = await KT.prompt({ title: 'Mark "' + x.title + '" resolved', description: 'What was done to fix it? This goes in the record and in the resolution notice to parents.',
      fields: [{ key: 'resolution_note', label: 'What was done', value: x.steps_taken || '', placeholder: 'e.g. Board replaced and checked' }], okLabel: 'Mark resolved' });
    if (note == null) return;
    try {
      await Api.post('/hazards/' + x.id + '/resolve', { resolution_note: String(note).trim() || null });
      toast('✅', 'Hazard resolved', x.notified_local ? 'Use "Tell parents it is resolved" to send the update.' : '', '#16A34A');
      render(main);
    } catch (e) { toast('⚠️', 'Could not resolve', errMsg(e), '#DC2626'); }
  }

  ['agency_admin', 'platform_admin', 'centre_director', 'educator'].forEach(function (r) { KT.Shell.registerScreen(r + ':hazards', render); });
})(window);
