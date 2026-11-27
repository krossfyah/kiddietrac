/* ═══════════════════════════════════════════════════════════════════════
   screen-hr-safety.js - KiddieTrac HR Phase 3 (2026-11-26): staff injuries and near misses.

   - HR > Injuries (directors: own centres; admins: the agency): the list, Form 7 countdown, return-to-work plans.
   - My HR > "Injury reports": a staff member reports their own and sees their own records.
   Health details (what happened, body part, injury, first aid, medical care, modified duties, check-in notes) are
   never in a list: a record shows them only after "Show health details", which the server audits every time.
   Lazy: loaded before screen-hr.js and screen-hr-talent.js (their "d" dependency), never on its own.
   Item 9 (2026-11-26): HR > Claims and My HR "Expense and mileage claims" (expense with receipt photo, or mileage at the
   agency's km rate); approved claims are paid on the next manual payslip as a reimbursement line.
   Item 10 (2026-11-26): HR > Rota (the planned week: cost for pay viewers, ratio warnings, availability conflicts, shift
   changes to approve) and My HR "My shifts and availability" (weekly availability, can't-work dates, give away / swap).
   Item 11 (2026-11-26): HR > Rota also lists this week's clock-ins away from the centre and people near or into
   overtime; admins set the clock-in rules (geofence off / flag / block, overtime hours) and each centre's area on a map.
   Item 12 (2026-11-26): HR > Letters: offer / contract / contractor / warning / termination templates (admins edit and tick
   "Reviewed by our lawyer"; the draft note shows in the editor only), sent for e-signature in My forms.
   Phase 4 batch 2 (2026-11-27): HR > Overtime (approve as pay or bank as time in lieu), HR > Timesheets (scheduled vs
   actual, paid hours after the agency's rules, missing-punch flags, approve + lock; admins unlock) and My HR "Overtime and
   time in lieu" (log extra hours, the Overtime bank balance).
   Phase 4 batch 3 (2026-11-27): HR > Open shifts (post shifts nobody is on yet, ratio shortfalls, confirm who takes one,
   shift-notice settings) and My HR "Open shifts you can pick up". Rota ratio warnings can be posted as open shifts.
   ═══════════════════════════════════════════════════════════════════════ */
(function (window) {
  'use strict';
  var KT = window.KT;
  if (!KT || !KT.Api) return;
  var Api = KT.Api;

  function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }
  function toast(i, t, m, c) { try { KT.toast && KT.toast(i, t, m || '', c); } catch (e) {} }
  function saved(m) { toast('✅', 'Saved', m || '', '#16A34A'); }
  function errMsg(e) {
    var d = e && e.data;
    if (d && d.errors) { var k = Object.keys(d.errors)[0]; if (k && d.errors[k] && d.errors[k][0]) return d.errors[k][0]; }
    return (d && d.message) || (e && e.message && !/^HTTP \d/.test(e.message) ? e.message : '') || 'Something went wrong. Please try again.';
  }
  var MON = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
  function day(s) { var m = /^(\d{4})-(\d{2})-(\d{2})/.exec(s || ''); return m ? MON[+m[2] - 1] + ' ' + (+m[3]) + ', ' + m[1] : ''; }
  function when(s) {
    var m = /^(\d{4}-\d{2}-\d{2})[ T](\d{2}):(\d{2})/.exec(s || '');
    if (!m) return day(s);
    var h = +m[2], ap = h >= 12 ? 'PM' : 'AM'; h = h % 12 || 12;
    return day(m[1]) + ' · ' + h + ':' + m[3] + ' ' + ap;
  }
  function today() { try { if (KT.agencyToday) return KT.agencyToday(); } catch (e) {} return new Date().toISOString().slice(0, 10); }
  function pill(bg, fg, t) { return '<span data-kt-no-tone style="display:inline-block;padding:2px 9px;border-radius:999px;font-size:12px;font-weight:700;background:' + bg + ';color:' + fg + ';white-space:nowrap;">' + esc(t) + '</span>'; }
  function opts(map, v, blank) {
    return (blank != null ? '<option value="">' + esc(blank) + '</option>' : '') + Object.keys(map || {}).map(function (k) { return '<option value="' + esc(k) + '"' + (String(v) === String(k) ? ' selected' : '') + '>' + esc(map[k]) + '</option>'; }).join('');
  }
  function modal(o) { return KT.Shell.Modal.open(o); }
  function closeModal() { try { var r = document.getElementById('modalRoot'); var x = r && r.querySelector('.modal-close'); if (x) x.click(); } catch (e) {} }
  function el(html) { var d = document.createElement('div'); d.innerHTML = html; return d; }
  function val(box, k) { var e = box.querySelector('[data-f="' + k + '"]'); if (!e) return null; if (e.type === 'checkbox') return e.checked; var v = String(e.value || '').trim(); return v === '' ? null : v; }
  function msg(box, t) { var m = box.querySelector('[data-msg]'); if (m) m.textContent = t || ''; }
  function field(label, inner, wide) { return '<div' + (wide ? ' style="grid-column:1/-1;"' : '') + '><label class="hr-l">' + esc(label) + '</label>' + inner + '</div>'; }
  function input(k, v, type) { return '<input class="hr-i" data-f="' + k + '" type="' + (type || 'text') + '" value="' + esc(v == null ? '' : v) + '" style="width:100%;">'; }
  function area(k, v, rows) { return '<textarea class="hr-i" data-f="' + k + '" rows="' + (rows || 3) + '" style="width:100%;min-height:' + ((rows || 3) * 22) + 'px;">' + esc(v || '') + '</textarea>'; }
  function select(k, inner) { return '<select class="hr-i" data-f="' + k + '" style="width:100%;">' + inner + '</select>'; }
  function check(k, v, label) { return '<label style="display:flex;gap:8px;align-items:center;font-size:13px;"><input type="checkbox" class="kt-nosw" data-f="' + k + '"' + (v ? ' checked' : '') + '> ' + esc(label) + '</label>'; }

  var KINDS = { injury: 'Injury', near_miss: 'Near miss' };
  var CARE = { none: 'No', doctor: 'Doctor or clinic', hospital: 'Hospital' };

  function kindPill(k) { return k === 'near_miss' ? pill('#FEF3C7', '#92400E', 'Near miss') : pill('#FEE2E2', '#991B1B', 'Injury'); }
  function statusPill(s) { return s === 'closed' ? pill('#F1F5F9', '#475569', 'Closed') : pill('#E0F2FE', '#075985', 'Open'); }
  /* The report to the compensation board, due within 3 business days everywhere (Anthony 2026-11-26):
     WSIB Form 7 in Ontario, neutral wording elsewhere. */
  function boardName(r) { return r.ontario ? 'Form 7' : 'Injury report'; }
  function dueLine(r, d) { return '<span>' + (r.ontario ? 'WSIB Form 7 due' : 'Injury report to your workers’ compensation board due') + '</span> <span data-no-i18n>' + esc(day(d)) + '</span>'; }
  function form7Pill(r) {
    if (r.kind !== 'injury') return '<span class="hr-sub">Not needed</span>';
    if (r.form7_filed_on) return pill('#DCFCE7', '#166534', 'Filed ' + day(r.form7_filed_on));
    if (!r.form7_needed) return '<span class="hr-sub">Not needed</span>';
    if (r.form7_due) {
      var n = r.form7_days_left;
      var t = n < 0 ? 'Overdue ' + (-n) + ' business day' + (n === -1 ? '' : 's') : n === 0 ? 'Due today' : n + ' business day' + (n === 1 ? '' : 's') + ' left';
      return pill(n <= 0 ? '#FEE2E2' : n === 1 ? '#FEF3C7' : '#E0F2FE', n <= 0 ? '#991B1B' : n === 1 ? '#92400E' : '#075985', t) + '<div class="hr-sub">' + dueLine(r, r.form7_due) + '</div>';
    }
    return pill('#FEF3C7', '#92400E', 'To file');
  }
  function rtwText(r) {
    if (!r.rtw_start) return '<span class="hr-sub">None</span>';
    return esc(day(r.rtw_start)) + (r.rtw_end ? ' to ' + esc(day(r.rtw_end)) : ' onward');
  }

  var S = { data: null, host: null };

  // ── HR > Injuries ───────────────────────────────────────────────────────
  async function drawInjuries(host) {
    S.host = host;
    try { S.data = await Api.get('/hr/injuries'); } catch (e) { host.innerHTML = '<div class="hr-note">' + esc(errMsg(e)) + '</div>'; return; }
    if (!host.isConnected) return;
    var d = S.data, rows = d.injuries || [];
    var due = rows.filter(function (r) { return r.form7_due && r.form7_days_left <= 1; }).length;
    var h = '<div class="hr-note">Workplace injuries and near misses for your team, separate from child incidents. Health details stay hidden in this list; open a record and choose Show health details (each view is recorded).'
      + (d.ontario ? ' In Ontario, WSIB Form 7 is due within 3 business days of learning of an injury that needed health care or cost time off: <a href="' + esc(d.wsib_url) + '" target="_blank" rel="noopener">WSIB: report an injury</a>.'
        : ' An injury that needed health care or cost time off is reported to your workers’ compensation board; the countdown gives 3 business days from the day you learned of it.') + '</div>'
      + '<div class="hr-bar"><button type="button" class="hr-b p" data-ij="new">Report an injury or near miss</button>'
      + (due ? pill('#FEE2E2', '#991B1B', due + ' ' + (d.ontario ? 'Form 7' : 'injury report') + (due === 1 ? '' : 's') + ' due soon') : '') + '</div>'
      + '<div class="kt-card">' + (rows.length ? '<table data-hrs="injuries"><thead><tr><th>Person</th><th>Type</th><th>When</th><th>Centre</th><th>' + (d.ontario ? 'WSIB Form 7' : 'Injury report') + '</th><th>Return to work</th><th>Status</th><th></th></tr></thead><tbody>'
        + rows.map(function (r) {
          return '<tr><td><b data-no-i18n>' + esc(r.person) + '</b>' + (r.reported_by && r.reported_by !== r.person ? '<div class="hr-sub">Reported by <span data-no-i18n>' + esc(r.reported_by) + '</span></div>' : '') + '</td>'
            + '<td>' + kindPill(r.kind) + '</td><td style="white-space:nowrap;">' + esc(when(r.occurred_at)) + '</td><td data-no-i18n>' + esc(r.centre || '') + '</td>'
            + '<td>' + form7Pill(r) + '</td><td style="white-space:nowrap;">' + rtwText(r) + '</td><td>' + statusPill(r.status) + '</td>'
            + '<td style="text-align:center;white-space:nowrap;"><button type="button" data-ij="open" data-id="' + r.id + '">View record</button></td></tr>';
        }).join('') + '</tbody></table>' : '<div style="padding:16px;" class="hr-sub">No staff injuries or near misses recorded.</div>') + '</div>';
    host.innerHTML = h;
    var reload = function () { if (host.isConnected) drawInjuries(host); };
    host.querySelectorAll('[data-ij]').forEach(function (b) {
      b.addEventListener('click', function () {
        var a = b.getAttribute('data-ij');
        if (a === 'new') openReport(d, true, reload);
        else if (a === 'open') openRecord(+b.getAttribute('data-id'), reload);
      });
    });
    var m = /[?&]injury=(\d+)/.exec(location.hash || '');
    if (m && rows.some(function (r) { return r.id === +m[1]; })) openRecord(+m[1], reload);
  }

  // ── Report form (own, or for a person a director / admin manages) ────────
  function openReport(d, forOthers, done) {
    var now = new Date(), pad = function (n) { return (n < 10 ? '0' : '') + n; };
    var local = today() + 'T' + pad(now.getHours()) + ':' + pad(now.getMinutes());
    var people = forOthers ? (d.people || []) : [];
    var box = el('<div class="hr-g">'
      + (forOthers ? field('Who was hurt (or nearly)', select('user_id', '<option value="">Choose a person</option>' + people.map(function (p) { return '<option value="' + p.id + '">' + esc(p.name) + '</option>'; }).join(''))) : '')
      + field('Type', select('kind', opts(KINDS, 'injury')))
      + field('When it happened', input('occurred_at', local, 'datetime-local'))
      + field('Reported to the employer on', input('learned_on', today(), 'date'))
      + field('Where', input('location', ''))
      + field('Witnesses', input('witnesses', ''))
      + field('What happened', area('what_happened', '', 4), true)
      + field('Body part', input('body_part', ''))
      + field('The injury', input('injury_nature', ''))
      + field('First aid given', area('first_aid', '', 2), true)
      + field('Went to a doctor or hospital?', select('medical_care', opts(CARE, 'none')))
      + field('Doctor or hospital details', input('medical_notes', ''))
      + '<div style="grid-column:1/-1;">' + check('lost_time', false, 'Time off work after the day it happened') + '</div>'
      + '</div><div class="hr-sub" style="margin-top:8px;">🔒 Health details are kept encrypted and shown only to you, your director and your agency admin.</div>'
      + '<div data-msg style="color:#B91C1C;font-size:13px;margin-top:6px;"></div>');
    modal({ title: forOthers ? 'Report a staff injury or near miss' : 'Report an injury or near miss', body: box, actions: [{ label: 'Cancel' }, { label: 'Send report', primary: true, busyLabel: 'Sending…', handler: function () {
      var o = { kind: val(box, 'kind'), occurred_at: (val(box, 'occurred_at') || '').replace('T', ' '), learned_on: val(box, 'learned_on'), location: val(box, 'location'),
        witnesses: val(box, 'witnesses'), what_happened: val(box, 'what_happened'), body_part: val(box, 'body_part'), injury_nature: val(box, 'injury_nature'),
        first_aid: val(box, 'first_aid'), medical_care: val(box, 'medical_care'), medical_notes: val(box, 'medical_notes'), lost_time: !!val(box, 'lost_time') };
      if (forOthers) { o.user_id = val(box, 'user_id'); if (!o.user_id) { msg(box, 'Choose the person.'); return false; } }
      if (!o.occurred_at) { msg(box, 'Enter when it happened.'); return false; }
      if (!o.what_happened) { msg(box, 'Describe what happened.'); return false; }
      return Api.post('/hr/injuries', o).then(function () { saved('Report sent.'); done(); }, function (e) { msg(box, errMsg(e)); return false; });
    } }] });
  }

  // ── One record ───────────────────────────────────────────────────────────
  async function openRecord(id, done) {
    var p;
    try { p = await Api.get('/hr/injuries/' + id); } catch (e) { return toast('⚠️', 'Could not open the record', errMsg(e), '#DC2626'); }
    var r = p.injury, man = !!p.can_manage;
    var kv = function (k, v) { return '<div class="hr-kv" style="display:flex;justify-content:space-between;gap:10px;padding:5px 0;border-bottom:1px solid #F1F5F9;font-size:13.5px;"><span style="color:#64748B;">' + esc(k) + '</span><b data-no-i18n style="text-align:right;">' + (v == null || v === '' ? '<span class="hr-sub">—</span>' : v) + '</b></div>'; };
    var h = '<div>' + kv('Person', esc(r.person)) + kv('Type', kindPill(r.kind)) + kv('When', esc(when(r.occurred_at))) + kv('Reported to the employer', esc(day(r.learned_on)))
      + kv('Where', esc(r.location || '')) + kv('Centre', esc(r.centre || '')) + kv('Witnesses', esc(r.witnesses || '')) + kv('Time off work', r.lost_time ? 'Yes' : 'No')
      + kv('Status', statusPill(r.status)) + '</div>'
      + '<div style="margin:12px 0 4px;font-weight:800;font-size:13px;">Health details</div><div data-health class="hr-sub" style="padding:8px 10px;background:#F8FAFC;border:1px solid #E2E8F0;border-radius:10px;">🔒 Hidden. <button type="button" class="hr-b" data-reveal style="margin-left:6px;">Show health details</button><div class="hr-sub" style="margin-top:4px;">Each time they are shown is recorded.</div></div>';
    if (r.kind === 'injury') {
      h += '<div style="margin:14px 0 4px;font-weight:800;font-size:13px;">' + (r.ontario ? 'WSIB Form 7' : 'Injury report to your workers’ compensation board') + '</div><div>' + kv('Needed', r.form7_needed ? 'Yes' : 'No') + kv('Status', form7Pill(r))
        + kv('Filed on', esc(day(r.form7_filed_on))) + kv('Reference', esc(r.form7_reference || '')) + '</div>'
        + '<div class="hr-sub" style="margin-top:4px;">Due within 3 business days of learning of it.' + (r.ontario ? ' <a href="' + esc(p.wsib_url) + '" target="_blank" rel="noopener">WSIB: report an injury</a>' : '') + '</div>';
    }
    h += '<div style="margin:14px 0 4px;font-weight:800;font-size:13px;">Return to work</div><div>' + kv('Modified duties', rtwText(r)) + '</div>'
      + '<div style="margin:14px 0 4px;font-weight:800;font-size:13px;">Check-ins</div>' + ((p.checkins || []).length ? '<div>' + p.checkins.map(function (c) { return kv(day(c.on_date), esc(c.by || '')); }).join('') + '</div>' : '<div class="hr-sub">None yet.</div>');
    var box = el(h);
    var acts = [{ label: 'Close' }];
    if (man) {
      acts = [{ label: 'Close' },
        { label: 'Check-in', handler: function () { closeModal(); setTimeout(function () { openCheckin(r, done); }, 60); return false; } },
        { label: 'Return to work', handler: function () { closeModal(); setTimeout(function () { openRtw(r, done); }, 60); return false; } }];
      if (r.kind === 'injury') acts.push({ label: boardName(r), handler: function () { closeModal(); setTimeout(function () { openForm7(r, done); }, 60); return false; } });
      acts.push({ label: r.status === 'closed' ? 'Reopen' : 'Close record', primary: true, handler: function () { return setStatus(r, r.status === 'closed' ? 'open' : 'closed', done); } });
    }
    modal({ title: (r.kind === 'near_miss' ? 'Near miss: ' : 'Injury: ') + r.person, body: box, actions: acts });
    var rv = box.querySelector('[data-reveal]');
    if (rv) rv.addEventListener('click', function () {
      Api.post('/hr/injuries/' + id + '/reveal', {}).then(function (x) {
        var hh = x.health || {}, notes = x.checkin_notes || {};
        var host = box.querySelector('[data-health]');
        host.className = '';
        host.style.cssText = 'padding:4px 10px;background:#FFFBEB;border:1px solid #FDE68A;border-radius:10px;';
        host.innerHTML = kv('What happened', esc(hh.what_happened || '')) + kv('Body part', esc(hh.body_part || '')) + kv('The injury', esc(hh.injury_nature || ''))
          + kv('First aid', esc(hh.first_aid || '')) + kv('Doctor or hospital', esc(CARE[hh.medical_care] || hh.medical_care || '')) + kv('Details', esc(hh.medical_notes || ''))
          + kv('Modified duties', esc(hh.rtw_duties || '')) + Object.keys(notes).map(function (k) { return kv('Check-in note', esc(notes[k] || '')); }).join('');
      }, function (e) { toast('⚠️', 'Could not show them', errMsg(e), '#DC2626'); });
    });
  }

  function setStatus(r, s, done) {
    return Api.get('/hr/injuries/' + r.id).then(function () {
      return Api.post('/hr/injuries/' + r.id + '/reveal', {});
    }).then(function (x) {
      var hh = x.health || {};
      return Api.put('/hr/injuries/' + r.id, { kind: r.kind, occurred_at: r.occurred_at, location: r.location, witnesses: r.witnesses, what_happened: hh.what_happened || '-',
        body_part: hh.body_part, injury_nature: hh.injury_nature, first_aid: hh.first_aid, medical_care: hh.medical_care || 'none', medical_notes: hh.medical_notes,
        lost_time: r.lost_time, status: s });
    }).then(function () { saved(s === 'closed' ? 'Record closed.' : 'Record reopened.'); done(); }, function (e) { toast('⚠️', 'Could not save it', errMsg(e), '#DC2626'); return false; });
  }

  function openForm7(r, done) {
    var box = el('<div class="hr-g">' + field('Filed on', input('form7_filed_on', r.form7_filed_on || today(), 'date')) + field('Reference / claim number', input('form7_reference', r.form7_reference || ''))
      + '<div style="grid-column:1/-1;">' + check('form7_needed', r.form7_needed, 'A report is needed (health care beyond first aid, or time off work)') + '</div></div>'
      + (r.ontario ? '<div class="hr-sub" style="margin-top:8px;">File it on the WSIB site, then record the date and the reference here.</div>' : '')
      + '<div data-msg style="color:#B91C1C;font-size:13px;margin-top:6px;"></div>');
    modal({ title: r.ontario ? 'WSIB Form 7' : 'Injury report to your workers’ compensation board', body: box, actions: [{ label: 'Cancel' }, { label: 'Save', primary: true, busyLabel: 'Saving…', handler: function () {
      return Api.put('/hr/injuries/' + r.id + '/form7', { form7_filed_on: val(box, 'form7_filed_on'), form7_reference: val(box, 'form7_reference'), form7_needed: !!val(box, 'form7_needed') })
        .then(function () { saved(); done(); }, function (e) { msg(box, errMsg(e)); return false; });
    } }] });
  }

  function openRtw(r, done) {
    // The duties are a health detail: they are fetched (and the view recorded) only to edit them.
    Api.post('/hr/injuries/' + r.id + '/reveal', {}).then(function (x) {
      var hh = x.health || {};
      var box = el('<div class="hr-g">' + field('Modified duties from', input('rtw_start', r.rtw_start || today(), 'date')) + field('Until', input('rtw_end', r.rtw_end || '', 'date'))
        + field('Modified duties', area('rtw_duties', hh.rtw_duties || '', 4), true) + '</div><div data-msg style="color:#B91C1C;font-size:13px;margin-top:6px;"></div>');
      modal({ title: 'Return to work: ' + r.person, body: box, actions: [{ label: 'Cancel' }, { label: 'Save plan', primary: true, busyLabel: 'Saving…', handler: function () {
        return Api.put('/hr/injuries/' + r.id + '/return-to-work', { rtw_start: val(box, 'rtw_start'), rtw_end: val(box, 'rtw_end'), rtw_duties: val(box, 'rtw_duties') })
          .then(function () { saved('Plan saved.'); done(); }, function (e) { msg(box, errMsg(e)); return false; });
      } }] });
    }, function (e) { toast('⚠️', 'Could not open the plan', errMsg(e), '#DC2626'); });
  }

  function openCheckin(r, done) {
    var box = el('<div class="hr-g">' + field('Date', input('on_date', today(), 'date')) + field('How it is going', area('note', '', 4), true) + '</div><div data-msg style="color:#B91C1C;font-size:13px;margin-top:6px;"></div>');
    modal({ title: 'Check-in: ' + r.person, body: box, actions: [{ label: 'Cancel' }, { label: 'Add check-in', primary: true, busyLabel: 'Saving…', handler: function () {
      var n = val(box, 'note'); if (!n) { msg(box, 'Write a note first.'); return false; }
      return Api.post('/hr/injuries/' + r.id + '/checkins', { on_date: val(box, 'on_date'), note: n }).then(function () { saved('Check-in added.'); done(); }, function (e) { msg(box, errMsg(e)); return false; });
    } }] });
  }

  // ── My HR: the person's own reports ──────────────────────────────────────
  async function mine(host) {
    var wrap = document.createElement('div'); wrap.setAttribute('data-hrs-mine', '1'); host.appendChild(wrap);
    var d;
    try { d = await Api.get('/hr/me/injuries'); } catch (e) { wrap.innerHTML = ''; return; }
    if (!wrap.isConnected) return;
    var rows = d.injuries || [];
    wrap.innerHTML = '<div class="hrt-sec">Injury reports</div><div class="hr-bar"><button type="button" class="hr-b p" data-mij="new">Report an injury or near miss</button></div>'
      + '<div class="kt-card">' + (rows.length ? '<table data-hrs="myinjuries"><thead><tr><th>Type</th><th>When</th><th>Where</th><th>Status</th><th></th></tr></thead><tbody>' + rows.map(function (r) {
        return '<tr><td>' + kindPill(r.kind) + '</td><td style="white-space:nowrap;">' + esc(when(r.occurred_at)) + '</td><td data-no-i18n>' + esc(r.location || '') + '</td><td>' + statusPill(r.status) + '</td>'
          + '<td style="text-align:center;"><button type="button" data-mij="open" data-id="' + r.id + '">View record</button></td></tr>';
      }).join('') + '</tbody></table>' : '<div style="padding:16px;" class="hr-sub">You have not reported an injury or near miss.</div>') + '</div>';
    var reload = function () { if (wrap.isConnected) { wrap.remove(); mine(host); } };
    wrap.querySelectorAll('[data-mij]').forEach(function (b) {
      b.addEventListener('click', function () {
        if (b.getAttribute('data-mij') === 'new') openReport(d, false, reload);
        else openRecord(+b.getAttribute('data-id'), reload);
      });
    });
  }

  /* ═══ HR Phase 3, item 9 (2026-11-26): expense and mileage claims ═══════════════════════════════════
     HR > Claims (directors: own centres; admins: the agency) approves or declines; approved claims go on the
     person's next manual payslip as a reimbursement line (Payroll > Run payroll), never paid from here.
     My HR > "Expense and mileage claims": file one (receipt photo from the phone camera), see own statuses. */
  function rateTxt(r) { return '$' + Number(r).toFixed(3).replace(/0$/, ''); }
  function money(n) { var v = Number(n) || 0; return '$' + v.toFixed(2).replace(/\B(?=(\d{3})+(?!\d))/g, ','); }
  var CL_ST = { submitted: ['#FEF3C7', '#92400E', 'Waiting for approval'], approved: ['#CCFBF1', '#115E59', 'Approved: in the next pay'],
    paid: ['#DCFCE7', '#166534', 'Paid'], declined: ['#FEE2E2', '#991B1B', 'Declined'], withdrawn: ['#F1F5F9', '#475569', 'Withdrawn'] };
  function claimPill(s) { var p = CL_ST[s] || CL_ST.submitted; return pill(p[0], p[1], p[2]); }
  function claimWhat(c) {
    if (c.kind === 'mileage') return '<b>Mileage</b> · <span data-no-i18n>' + esc(String(c.km)) + ' km</span><div class="hr-sub" data-no-i18n>' + esc((c.from_place || '') + ' → ' + (c.to_place || '')) + '</div>';
    return '<b data-no-i18n>' + esc(c.category || 'Expense') + '</b>' + (c.description ? '<div class="hr-sub" data-no-i18n>' + esc(c.description) + '</div>' : '');
  }
  async function fileBlob(path, name, view) {
    try {
      if (!KT.fetchFileBlob) throw new Error('');
      var b = await KT.fetchFileBlob((KT.API_BASE || '/api/v1') + path), u = URL.createObjectURL(b);
      if (view) window.open(u, '_blank', 'noopener');
      else { var a = document.createElement('a'); a.href = u; a.download = name || 'receipt'; document.body.appendChild(a); a.click(); a.remove(); }
      setTimeout(function () { URL.revokeObjectURL(u); }, 60000);
    } catch (e) { toast('⚠️', 'Could not open the receipt', errMsg(e), '#DC2626'); }
  }

  /* Filter chips + section titles for the claims screens: the same look as screen-hr-talent.js (whose CSS only loads with
     its own tabs). NOT aria-pressed (the portal turns those into switches); state = .on + aria-current. */
  function chipCss() {
    if (document.getElementById('kt-hrs-css')) return;
    var st = document.createElement('style'); st.id = 'kt-hrs-css';
    st.textContent = '.hrt-chips{display:flex;gap:6px;flex-wrap:wrap;align-items:center;margin:0 0 10px;}'
      + '#appMain .hrt-chips button.hrt-chip{display:inline-flex;align-items:center;gap:5px;height:30px;min-height:30px;padding:0 12px;border-radius:999px;border:1px solid #E2E8F0;'
      + 'background:#fff;font-size:12.5px;font-weight:700;line-height:1;white-space:nowrap;cursor:pointer;color:#334155;box-shadow:none;flex:0 0 auto;}'
      + '#appMain .hrt-chips button.hrt-chip .n{display:inline-flex;align-items:center;justify-content:center;min-width:18px;height:18px;padding:0 5px;border-radius:999px;background:#F1F5F9;color:#475569;font-size:11px;}'
      + '#appMain .hrt-chips button.hrt-chip.on{background:#0E7490;border-color:#0E7490;color:#fff;}'
      + '#appMain .hrt-chips button.hrt-chip.on .n{background:rgba(255,255,255,.22);color:#fff;}'
      + '.hrt-sec{font-weight:800;font-size:14px;margin:16px 0 8px;color:#0F172A;}'
      + 'html.kt-dark #appMain .hrt-chips button.hrt-chip{background:#0F172A;border-color:#334155;color:#E2E8F0;}'
      + 'html.kt-dark #appMain .hrt-chips button.hrt-chip.on{background:#0E7490;border-color:#0E7490;color:#fff;}html.kt-dark #appMain .hrt-chips button.hrt-chip .n{background:#1E293B;color:#CBD5E1;}html.kt-dark .hrt-sec{color:#F1F5F9;}';
    // Item 12: fill-in field chips in the letter editor (a popup, outside #appMain) and the letter preview's paragraphs.
    st.textContent += '#modalRoot .hrt-chips button.hrt-chip{display:inline-flex;align-items:center;height:28px;min-height:28px;padding:0 10px;border-radius:999px;border:1px solid #E2E8F0;'
      + 'background:#F8FAFC;font-size:12px;font-weight:700;line-height:1;white-space:nowrap;cursor:pointer;color:#334155;box-shadow:none;flex:0 0 auto;}'
      + '#modalRoot .hrt-chips button.hrt-chip:hover{border-color:#0E7490;color:#0E7490;}'
      + 'html.kt-dark #modalRoot .hrt-chips button.hrt-chip{background:#0F172A;border-color:#334155;color:#E2E8F0;}'
      + '#modalRoot [data-prev] p{margin:0 0 10px;}';
    st.textContent += '#appMain .hrt-chips .hrt-stat{display:inline-flex;align-items:center;gap:6px;height:30px;padding:0 12px;border-radius:999px;border:1px solid #E2E8F0;background:#F8FAFC;font-size:12.5px;font-weight:700;line-height:1;white-space:nowrap;color:#334155;}'
      + '#appMain .hrt-chips .hrt-stat .n{display:inline-flex;align-items:center;justify-content:center;min-width:18px;height:18px;padding:0 6px;border-radius:999px;background:#fff;color:#0F172A;font-size:11.5px;}'
      + '#appMain .hrt-chips .hrt-stat.warn{background:#FFFBEB;border-color:#FDE68A;color:#92400E;}'
      + 'html.kt-dark #appMain .hrt-chips .hrt-stat{background:#0F172A;border-color:#334155;color:#E2E8F0;}html.kt-dark #appMain .hrt-chips .hrt-stat .n{background:#1E293B;color:#E2E8F0;}'
      + 'html.kt-dark #appMain .hrt-chips .hrt-stat.warn{background:#422006;border-color:#92400E;color:#FDE68A;}';
    document.head.appendChild(st);
  }

  var CS = { st: 'submitted' };
  async function drawClaims(host) {
    chipCss();
    var d;
    try { d = await Api.get('/hr/claims?status=' + encodeURIComponent(CS.st)); } catch (e) { host.innerHTML = '<div class="hr-note">' + esc(errMsg(e)) + '</div>'; return; }
    if (!host.isConnected) return;
    var rows = d.claims || [], n = d.counts || {}, all = 0;
    Object.keys(n).forEach(function (k) { all += n[k]; });
    var chips = [['submitted', 'To approve'], ['approved', 'Approved'], ['paid', 'Paid'], ['declined', 'Declined'], ['all', 'All']];
    var h = '<div class="hr-note">Expense and mileage claims from your team. Approve or decline each one; an approved claim is added to the person\'s next payslip as a reimbursement when the payroll run is made (Payroll &gt; Run payroll).'
      + (d.settings && d.settings.km_rate ? ' Mileage rate: <b data-no-i18n>' + esc(rateTxt(d.settings.km_rate)) + '/km</b>.' : ' No mileage rate is set yet' + (d.is_admin ? ': set one in Claim settings.' : '.')) + '</div>'
      + '<div class="hrt-chips">' + chips.map(function (c) {
        var on = CS.st === c[0], k = c[0] === 'all' ? all : (n[c[0]] || 0);
        return '<button type="button" class="hrt-chip' + (on ? ' on' : '') + '" data-cst="' + c[0] + '"' + (on ? ' aria-current="true"' : '') + '>' + esc(c[1]) + ' <span class="n">' + k + '</span></button>';
      }).join('') + '<span style="flex:1"></span><button type="button" class="hr-b" data-cl="report">Claims report</button>'
      + (d.is_admin ? '<button type="button" class="hr-b" data-cl="settings">Claim settings</button>' : '') + '</div>'
      + '<div class="kt-card">' + (rows.length ? '<table data-hrs="claims"><thead><tr><th>Person</th><th>Date</th><th>Claim</th><th style="text-align:right;">Amount</th><th>Status</th><th></th></tr></thead><tbody>'
        + rows.map(function (c) {
          return '<tr><td><b data-no-i18n>' + esc(c.person) + '</b>' + (c.centre ? '<div class="hr-sub" data-no-i18n>' + esc(c.centre) + '</div>' : '') + '</td>'
            + '<td style="white-space:nowrap;">' + esc(day(c.claim_date)) + '</td><td>' + claimWhat(c) + '</td>'
            + '<td style="text-align:right;white-space:nowrap;" data-no-i18n><b>' + esc(money(c.total)) + '</b></td><td>' + claimPill(c.status) + '</td>'
            + '<td style="text-align:center;white-space:nowrap;"><button type="button" data-cl="open" data-id="' + c.id + '">View claim</button></td></tr>';
        }).join('') + '</tbody></table>' : '<div style="padding:16px;" class="hr-sub">No claims here.</div>') + '</div>';
    host.innerHTML = h;
    var reload = function () { if (host.isConnected) drawClaims(host); };
    host.querySelectorAll('[data-cst]').forEach(function (b) { b.addEventListener('click', function () { CS.st = b.getAttribute('data-cst'); drawClaims(host); }); });
    host.querySelectorAll('[data-cl]').forEach(function (b) {
      b.addEventListener('click', function () {
        var a = b.getAttribute('data-cl');
        if (a === 'open') { var c = rows.filter(function (x) { return x.id === +b.getAttribute('data-id'); })[0]; if (c) openClaim(c, c.user_id !== d.me, reload); }
        else if (a === 'report') openClaimReport(d.today);
        else if (a === 'settings') openClaimSettings(d.settings || {}, reload);
      });
    });
    var m = /[?&]claim=(\d+)/.exec(location.hash || '');
    if (m) {
      var c = rows.filter(function (x) { return x.id === +m[1]; })[0];
      if (c) openClaim(c, c.user_id !== d.me, reload);
      else if (CS.st !== 'all') { CS.st = 'all'; drawClaims(host); }
    }
  }

  function openClaim(c, manage, done) {
    var kv = function (k, v) { return '<div style="display:flex;justify-content:space-between;gap:10px;padding:5px 0;border-bottom:1px solid #F1F5F9;font-size:13.5px;"><span style="color:#64748B;">' + esc(k) + '</span><b data-no-i18n style="text-align:right;">' + (v == null || v === '' ? '<span class="hr-sub">—</span>' : v) + '</b></div>'; };
    var h = '<div>' + kv('Person', esc(c.person)) + kv('Date', esc(day(c.claim_date))) + kv('Type', c.kind === 'mileage' ? 'Mileage' : 'Expense');
    if (c.kind === 'mileage') h += kv('From', esc(c.from_place || '')) + kv('To', esc(c.to_place || '')) + kv('Distance', esc(String(c.km)) + ' km') + kv('Rate', c.km_rate != null ? esc(rateTxt(c.km_rate) + '/km') : '');
    else h += kv('Category', esc(c.category || '')) + kv('Amount before tax', esc(money(c.amount))) + kv('Tax', esc(money(c.tax)));
    h += kv('Description', esc(c.description || '')) + kv('Total', '<span style="font-size:15px;">' + esc(money(c.total)) + '</span>') + kv('Status', claimPill(c.status));
    if (c.decided_by) h += kv(c.status === 'declined' ? 'Declined by' : 'Approved by', esc(c.decided_by) + (c.decided_at ? ' · ' + esc(day(c.decided_at)) : ''));
    if (c.decision_comment) h += kv('Comment', esc(c.decision_comment));
    if (c.status === 'paid') h += kv('Paid', esc(day(c.paid_at)) + (c.payslip ? ' · ' + esc(c.payslip) : ''));
    h += '</div>';
    if (c.has_receipt) h += '<div class="hr-bar" style="margin:12px 0 0;"><button type="button" class="hr-b" data-rc="view">🧾 View receipt</button><button type="button" class="hr-b" data-rc="dl">Download</button></div>';
    else if (c.kind === 'expense') h += '<div class="hr-sub" style="margin-top:10px;">No receipt was added.</div>';
    var box = el(h);
    box.querySelectorAll('[data-rc]').forEach(function (b) { b.addEventListener('click', function () { fileBlob('/hr/claims/' + c.id + '/receipt' + (b.getAttribute('data-rc') === 'view' ? '?inline=1' : ''), c.receipt_name, b.getAttribute('data-rc') === 'view'); }); });
    var acts = [{ label: 'Close' }];
    var decide = function (dec, comment) {
      return Api.post('/hr/claims/' + c.id + '/decide', { decision: dec, comment: comment || null }).then(function () { saved(dec === 'approve' ? 'Claim approved.' : 'Claim declined.'); done(); }, function (e) { toast('⚠️', 'Could not save it', errMsg(e), '#DC2626'); return false; });
    };
    if (manage) {
      if (c.status === 'submitted' || (c.status === 'approved' && !c.payroll_document_id)) acts.push({ label: 'Decline', handler: function () { closeModal(); setTimeout(function () { openDecline(c, decide); }, 60); return false; } });
      if (c.status === 'submitted' || c.status === 'declined') acts.push({ label: 'Approve', primary: true, busyLabel: 'Saving…', handler: function () { return decide('approve'); } });
    } else if (c.status === 'submitted') {
      acts.push({ label: 'Withdraw claim', handler: function () {
        return Api.post('/hr/claims/' + c.id + '/withdraw', {}).then(function () { saved('Claim withdrawn.'); done(); }, function (e) { toast('⚠️', 'Could not withdraw it', errMsg(e), '#DC2626'); return false; });
      } });
    }
    modal({ title: (c.kind === 'mileage' ? 'Mileage claim: ' : 'Expense claim: ') + c.person, body: box, actions: acts });
  }

  function openDecline(c, decide) {
    var box = el('<div>' + field('Why is it declined? (the person sees this)', area('comment', '', 3), true) + '<div data-msg style="color:#B91C1C;font-size:13px;margin-top:6px;"></div></div>');
    modal({ title: 'Decline claim: ' + c.person, body: box, actions: [{ label: 'Cancel' }, { label: 'Decline claim', primary: true, busyLabel: 'Saving…', handler: function () {
      var t = val(box, 'comment'); if (!t) { msg(box, 'Say why the claim is declined.'); return false; }
      return decide('decline', t);
    } }] });
  }

  function openClaimReport(todayStr) {
    var t = todayStr || today();
    var box = el('<div><div class="hr-g">' + field('From', input('from', t.slice(0, 4) + '-01-01', 'date')) + field('To', input('to', t, 'date')) + '</div><div data-rep style="margin-top:10px;" class="hr-sub">Loading…</div></div>');
    var tbl = function (title, rows, key) {
      if (!rows.length) return '';
      return '<div class="hrt-sec">' + esc(title) + '</div><div class="kt-card"><table data-hrs="claims-report"><thead><tr><th>' + (key === 'month' ? 'Month' : title === 'By person' ? 'Person' : 'Category') + '</th><th style="text-align:right;">Claims</th><th style="text-align:right;">Approved</th><th style="text-align:right;">Paid</th></tr></thead><tbody>'
        + rows.map(function (r) {
          var nm = key === 'month' ? (function (m) { var p = /^(\d{4})-(\d{2})/.exec(m || ''); return p ? MON[+p[2] - 1] + ' ' + p[1] : m; })(r.month) : r.name;
          return '<tr><td data-no-i18n>' + esc(nm) + '</td><td style="text-align:right;">' + r.count + '</td><td style="text-align:right;" data-no-i18n>' + esc(money(r.total)) + '</td><td style="text-align:right;" data-no-i18n>' + esc(money(r.paid)) + '</td></tr>';
        }).join('') + '</tbody></table></div>';
    };
    var load = function () {
      var rep = box.querySelector('[data-rep]');
      Api.get('/hr/claims/report?from=' + encodeURIComponent(val(box, 'from') || '') + '&to=' + encodeURIComponent(val(box, 'to') || '')).then(function (r) {
        rep.className = '';
        rep.innerHTML = '<div class="hr-sub">Approved and paid claims: <b data-no-i18n>' + r.total.count + ' · ' + esc(money(r.total.total)) + '</b>' + (r.total.km ? ' · <span data-no-i18n>' + esc(String(r.total.km)) + ' km</span>' : '') + '</div>'
          + (r.total.count ? tbl('By person', r.by_person, 'name') + tbl('By category', r.by_category, 'name') + tbl('By month', r.by_month, 'month') : '<div class="hr-sub" style="padding:10px 0;">No approved claims in these dates.</div>');
      }, function (e) { rep.textContent = errMsg(e); });
    };
    box.querySelectorAll('[data-f]').forEach(function (i) { i.addEventListener('change', load); });
    modal({ title: 'Claims report', body: box, actions: [{ label: 'Close' }] });
    load();
  }

  function openClaimSettings(s, done) {
    var box = el('<div class="hr-g">' + field('Mileage rate ($ per km)', input('km_rate', s.km_rate == null ? '' : s.km_rate, 'number'))
      + '<div style="grid-column:1/-1;" class="hr-sub">Leave it empty to turn mileage claims off. Use the rate your agency pays; KiddieTrac does not fill in a government rate.</div>'
      + '<div style="grid-column:1/-1;">' + check('receipt_required', s.receipt_required !== false, 'A receipt photo is required for every expense claim') + '</div>'
      + field('Categories staff choose from (one per line)', area('categories', (s.categories || []).join('\n'), 7), true)
      + '</div><div data-msg style="color:#B91C1C;font-size:13px;margin-top:6px;"></div>');
    var r = box.querySelector('[data-f="km_rate"]'); if (r) { r.step = '0.001'; r.min = '0'; }
    modal({ title: 'Claim settings', body: box, actions: [{ label: 'Cancel' }, { label: 'Save settings', primary: true, busyLabel: 'Saving…', handler: function () {
      var cats = String(box.querySelector('[data-f="categories"]').value || '').split(/\n+/).map(function (x) { return x.trim(); }).filter(Boolean);
      if (!cats.length) { msg(box, 'Add at least one category.'); return false; }
      return Api.put('/hr/claims/settings', { km_rate: val(box, 'km_rate'), receipt_required: !!val(box, 'receipt_required'), categories: cats })
        .then(function () { saved('Claim settings saved.'); done(); }, function (e) { msg(box, errMsg(e)); return false; });
    } }] });
  }

  function openNewClaim(d, done) {
    var s = d.settings || {}, cats = {};
    (s.categories || []).forEach(function (c) { cats[c] = c; });
    var box = el('<div class="hr-g">' + field('Type', select('kind', opts({ expense: 'Expense (with a receipt)', mileage: 'Mileage' }, 'expense')))
      + field('Date', input('claim_date', d.today || today(), 'date'))
      + '<div data-k="expense" style="display:contents;">' + field('Category', select('category', opts(cats, '', 'Choose a category')))
        + field('Amount before tax', input('amount', '', 'number')) + field('Tax', input('tax', '', 'number'))
        + field('Receipt photo' + (s.receipt_required !== false ? '' : ' (optional)'), '<input class="hr-i" data-f="receipt" type="file" accept="image/*,application/pdf" style="width:100%;">', true) + '</div>'
      + '<div data-k="mileage" style="display:none;">' + field('From', input('from_place', '')) + field('To', input('to_place', '')) + field('Distance (km)', input('km', '', 'number'))
        + '<div style="grid-column:1/-1;" class="hr-sub" data-mi></div></div>'
      + field('What it was for', area('description', '', 2), true)
      + '</div><div class="hr-sub" style="margin-top:8px;">🔒 Your claim and receipt are seen only by you, your director and your agency admin. Approved claims are paid on your next payslip.</div>'
      + '<div data-msg style="color:#B91C1C;font-size:13px;margin-top:6px;"></div>');
    ['amount', 'tax', 'km'].forEach(function (k) { var i = box.querySelector('[data-f="' + k + '"]'); if (i) { i.step = k === 'km' ? '0.1' : '0.01'; i.min = '0'; i.inputMode = 'decimal'; } });
    var mi = box.querySelector('[data-mi]');
    var sync = function () {
      var k = val(box, 'kind');
      box.querySelector('[data-k="expense"]').style.display = k === 'expense' ? 'contents' : 'none';
      box.querySelector('[data-k="mileage"]').style.display = k === 'mileage' ? 'contents' : 'none';
      if (k === 'mileage') {
        var km = Number(val(box, 'km')) || 0;
        mi.innerHTML = s.km_rate ? 'Rate <b data-no-i18n>' + esc(rateTxt(s.km_rate)) + '/km</b>' + (km ? ' · <b data-no-i18n>' + esc(money(km * s.km_rate)) + '</b>' : '')
          : '<span style="color:#B91C1C;">Your agency has not set a mileage rate yet. Ask your agency admin.</span>';
      }
    };
    box.querySelector('[data-f="kind"]').addEventListener('change', sync);
    box.querySelector('[data-f="km"]').addEventListener('input', sync);
    sync();
    modal({ title: 'New expense or mileage claim', body: box, actions: [{ label: 'Cancel' }, { label: 'Send claim', primary: true, busyLabel: 'Sending…', handler: function () {
      var k = val(box, 'kind'), fd = new FormData();
      fd.append('kind', k); fd.append('claim_date', val(box, 'claim_date') || '');
      if (val(box, 'description')) fd.append('description', val(box, 'description'));
      if (k === 'expense') {
        if (!val(box, 'category')) { msg(box, 'Choose a category.'); return false; }
        if (!(Number(val(box, 'amount')) > 0)) { msg(box, 'Enter the amount.'); return false; }
        var f = box.querySelector('[data-f="receipt"]').files[0];
        if (!f && s.receipt_required !== false) { msg(box, 'Add a photo of the receipt.'); return false; }
        fd.append('category', val(box, 'category')); fd.append('amount', val(box, 'amount')); fd.append('tax', val(box, 'tax') || '0');
        if (f) fd.append('receipt', f);
      } else {
        if (!s.km_rate) { msg(box, 'Your agency has not set a mileage rate yet.'); return false; }
        if (!val(box, 'from_place') || !val(box, 'to_place')) { msg(box, 'Enter where you drove from and to.'); return false; }
        if (!(Number(val(box, 'km')) > 0)) { msg(box, 'Enter the distance.'); return false; }
        fd.append('from_place', val(box, 'from_place')); fd.append('to_place', val(box, 'to_place')); fd.append('km', val(box, 'km'));
      }
      return Api.postForm('/hr/claims', fd).then(function () { saved('Claim sent.'); done(); }, function (e) { msg(box, errMsg(e)); return false; });
    } }] });
  }

  async function mineClaims(host) {
    chipCss();
    var wrap = document.createElement('div'); wrap.setAttribute('data-hrs-claims', '1'); host.appendChild(wrap);
    var d;
    try { d = await Api.get('/hr/me/claims'); } catch (e) { wrap.innerHTML = ''; return; }
    if (!wrap.isConnected) return;
    var rows = d.claims || [];
    wrap.innerHTML = '<div class="hrt-sec">Expense and mileage claims</div><div class="hr-bar"><button type="button" class="hr-b p" data-mcl="new">New claim</button></div>'
      + '<div class="kt-card">' + (rows.length ? '<table data-hrs="myclaims"><thead><tr><th>Date</th><th>Claim</th><th style="text-align:right;">Amount</th><th>Status</th><th></th></tr></thead><tbody>' + rows.map(function (c) {
        return '<tr><td style="white-space:nowrap;">' + esc(day(c.claim_date)) + '</td><td>' + claimWhat(c) + '</td><td style="text-align:right;white-space:nowrap;" data-no-i18n><b>' + esc(money(c.total)) + '</b></td>'
          + '<td>' + claimPill(c.status) + (c.status === 'declined' && c.decision_comment ? '<div class="hr-sub" data-no-i18n>' + esc(c.decision_comment) + '</div>' : '') + '</td>'
          + '<td style="text-align:center;"><button type="button" data-mcl="open" data-id="' + c.id + '">View claim</button></td></tr>';
      }).join('') + '</tbody></table>' : '<div style="padding:16px;" class="hr-sub">You have not filed a claim.</div>') + '</div>';
    var reload = function () { if (wrap.isConnected) { wrap.remove(); mineClaims(host); } };
    wrap.querySelectorAll('[data-mcl]').forEach(function (b) {
      b.addEventListener('click', function () {
        if (b.getAttribute('data-mcl') === 'new') openNewClaim(d, reload);
        else { var c = rows.filter(function (x) { return x.id === +b.getAttribute('data-id'); })[0]; if (c) openClaim(c, false, reload); }
      });
    });
  }

  /* ── Item 10 (2026-11-26): HR > Rota (planning view) and My HR "My shifts and availability" ───────────────── */
  var DOW = { 1: 'Monday', 2: 'Tuesday', 3: 'Wednesday', 4: 'Thursday', 5: 'Friday', 6: 'Saturday', 7: 'Sunday' };
  var RS = { centre: null, week: null };
  function t12(hm) { var m = /^(\d{2}):(\d{2})/.exec(hm || ''); if (!m) return ''; var h = +m[1], ap = h >= 12 ? 'PM' : 'AM'; h = h % 12 || 12; return h + ':' + m[2] + ' ' + ap; }
  function span(s) { return '<span data-no-i18n>' + esc(t12(s.start) + ' – ' + t12(s.end)) + '</span>'; }
  function wday(d) { var m = /^(\d{4})-(\d{2})-(\d{2})/.exec(d || ''); if (!m) return ''; var x = new Date(Date.UTC(+m[1], +m[2] - 1, +m[3])); return ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'][x.getUTCDay()] + ' ' + MON[+m[2] - 1] + ' ' + (+m[3]); }
  function addDays(d, n) { var m = /^(\d{4})-(\d{2})-(\d{2})/.exec(d); var x = new Date(Date.UTC(+m[1], +m[2] - 1, +m[3] + n)); return x.toISOString().slice(0, 10); }
  function hrs(n) { n = Math.round((+n || 0) * 100) / 100; return n + ' h'; }
  var SW_ST = { open: ['#FEF3C7', '#92400E', 'Waiting for a colleague'], accepted: ['#E0F2FE', '#075985', 'Waiting for approval'], approved: ['#DCFCE7', '#166534', 'Approved'], declined: ['#FEE2E2', '#991B1B', 'Declined'], cancelled: ['#F1F5F9', '#475569', 'Cancelled'] };
  function swPill(s) { var p = SW_ST[s] || SW_ST.open; return pill(p[0], p[1], p[2]); }
  function swWhat(w) {
    var s = w.shift || {};
    var h = '<b>' + (w.kind === 'swap' ? 'Swap' : 'Giveaway') + '</b> <span class="hr-sub" data-no-i18n>' + esc(wday(s.date)) + '</span> ' + span(s);
    if (w.swap_shift) h += '<div class="hr-sub">for <span data-no-i18n>' + esc(wday(w.swap_shift.date)) + '</span> ' + span(w.swap_shift) + '</div>';
    return h;
  }

  async function drawRota(host) {
    chipCss();
    var q = [];
    if (RS.centre) q.push('centre_id=' + RS.centre);
    if (RS.week) q.push('week=' + RS.week);
    var d;
    try { d = await Api.get('/hr/schedule/plan' + (q.length ? '?' + q.join('&') : '')); } catch (e) { host.innerHTML = '<div class="hr-note">' + esc(errMsg(e)) + '</div>'; return; }
    if (!host.isConnected) return;
    RS.centre = d.centre_id; RS.week = d.week;
    var pay = !!d.can_see_pay, sh = d.shifts || [], gaps = d.ratio_gaps || [], sw = d.swaps || [];
    var conflicts = sh.filter(function (s) { return s.conflict; });
    var toApprove = sw.filter(function (w) { return w.status === 'accepted'; });
    var t = d.totals || {};
    var h = '<div class="hr-note"><span>The week as planned on the rota (Schedule), checked against each person\'s availability and approved leave, and against the room ratios for the children expected that day.</span>'
      + (pay ? ' <span>Cost uses each person\'s hourly pay rate; only people who can see pay see it.</span>' : '') + '</div>'
      + '<div class="hrt-chips">'
      + ((d.centres || []).length > 1 ? '<select class="hr-i" data-ro="centre" style="height:30px;max-width:220px;">' + d.centres.map(function (c) { return '<option value="' + c.id + '"' + (c.id === d.centre_id ? ' selected' : '') + ' data-no-i18n>' + esc(c.name) + '</option>'; }).join('') + '</select>' : '')
      + '<button type="button" class="hr-b" data-ro="prev" aria-label="Previous week">‹</button><b data-no-i18n style="font-size:13px;white-space:nowrap;">' + esc(wday(d.week) + ' – ' + wday(d.week_end)) + '</b>'
      + '<button type="button" class="hr-b" data-ro="next" aria-label="Next week">›</button><button type="button" class="hr-b" data-ro="now">This week</button>'
      + '<span style="flex:1"></span>'
      + '<span class="hrt-stat">Hours <span class="n" data-no-i18n>' + esc(String(t.hours || 0)) + '</span></span>'
      + (pay ? '<span class="hrt-stat">Cost <span class="n" data-no-i18n>' + esc(money(t.cost || 0)) + '</span></span>' : '')
      + '<span class="hrt-stat' + (gaps.length ? ' warn' : '') + '">Ratio warnings <span class="n">' + gaps.length + '</span></span>'
      + '<span class="hrt-stat' + (conflicts.length ? ' warn' : '') + '">Availability conflicts <span class="n">' + conflicts.length + '</span></span></div>';
    if (pay && (t.salaried || t.no_rate)) h += '<div class="hr-sub" style="margin:-4px 0 8px;">' + (t.salaried ? '<span data-no-i18n>' + t.salaried + '</span> <span>salaried shifts are not costed.</span> ' : '') + (t.no_rate ? '<span data-no-i18n>' + t.no_rate + '</span> <span>shifts have no pay rate on the employee file.</span>' : '') + '</div>';
    h += '<div class="hrt-sec">Shift changes</div><div class="kt-card">' + (sw.length ? '<table data-hrs="swaps"><thead><tr><th>From</th><th>Shift</th><th>To</th><th>Status</th><th></th></tr></thead><tbody>' + sw.map(function (w) {
      return '<tr><td><b data-no-i18n>' + esc(w.from) + '</b></td><td>' + swWhat(w) + '</td><td data-no-i18n>' + esc(w.accepted_by || w.to || '') + (!w.accepted_by && !w.to ? '<span class="hr-sub">Anyone at the centre</span>' : '')
        + (w.conflict ? '<div style="color:#B45309;font-size:12px;">⚠️ ' + esc(w.conflict) + '</div>' : '') + '</td><td>' + swPill(w.status) + '</td>'
        + '<td style="text-align:center;"><button type="button" data-ro="swap" data-id="' + w.id + '">View change</button></td></tr>';
    }).join('') + '</tbody></table>' : '<div style="padding:14px;" class="hr-sub">No shift giveaways or swaps waiting.</div>') + '</div>';
    h += '<div class="hrt-sec">Ratio warnings</div><div class="kt-card">' + (gaps.length ? '<table data-hrs="gaps"><thead><tr><th>Day</th><th>Room</th><th>Time</th><th>Children expected</th><th>Educators planned</th><th>Needed</th><th></th></tr></thead><tbody>' + gaps.map(function (g, gi) {
      return '<tr style="background:#FFFBEB;"><td style="white-space:nowrap;" data-no-i18n>' + esc(wday(g.date)) + '</td><td data-no-i18n>' + esc(g.room) + '</td><td style="white-space:nowrap;">' + span({ start: g.from, end: g.to }) + '</td>'
        + '<td data-no-i18n>' + g.children + '</td><td data-no-i18n>' + g.planned + '</td><td data-no-i18n><b style="color:#B45309;">' + g.required + '</b></td>'
        + '<td style="text-align:center;">' + (g.date >= (d.today || today()) ? '<button type="button" data-ro="post" data-i="' + gi + '">Post an open shift</button>' : '') + '</td></tr>';
    }).join('') + '</tbody></table>' : '<div style="padding:14px;" class="hr-sub">Every room has enough educators planned for the children expected.</div>') + '</div>';
    h += '<div class="hrt-sec">Shifts</div><div class="kt-card">' + (sh.length ? '<table data-hrs="shifts"><thead><tr><th>Person</th><th>Day</th><th>Time</th><th>Room</th><th style="text-align:right;">Hours</th>' + (pay ? '<th style="text-align:right;">Cost</th>' : '') + '<th>Availability</th></tr></thead><tbody>' + sh.map(function (s) {
      return '<tr' + (s.conflict ? ' style="background:#FFFBEB;"' : '') + '><td><b data-no-i18n>' + esc(s.person) + '</b></td><td style="white-space:nowrap;" data-no-i18n>' + esc(wday(s.date)) + '</td><td style="white-space:nowrap;">' + span(s) + '</td>'
        + '<td data-no-i18n>' + esc(s.room || '') + '</td><td style="text-align:right;" data-no-i18n>' + esc(hrs(s.hours)) + '</td>'
        + (pay ? '<td style="text-align:right;white-space:nowrap;">' + (s.cost != null ? '<span data-no-i18n>' + esc(money(s.cost)) + '</span>' : '<span class="hr-sub">' + esc(s.cost_note || '') + '</span>') + '</td>' : '')
        + '<td>' + (s.conflict ? pill('#FEF3C7', '#92400E', s.conflict) : '<span class="hr-sub">OK</span>') + '</td></tr>';
    }).join('') + '</tbody></table>' : '<div style="padding:14px;" class="hr-sub">No shifts planned this week. Add them on the Schedule screen.</div>') + '</div>';
    var bp = d.by_person || [];
    if (bp.length) h += '<div class="hrt-sec">By person</div><div class="kt-card"><table data-hrs="byperson"><thead><tr><th>Person</th><th style="text-align:right;">Shifts</th><th style="text-align:right;">Hours</th>' + (pay ? '<th style="text-align:right;">Cost</th>' : '') + '</tr></thead><tbody>'
      + bp.map(function (p) { return '<tr><td data-no-i18n><b>' + esc(p.name) + '</b></td><td style="text-align:right;" data-no-i18n>' + p.shifts + '</td><td style="text-align:right;" data-no-i18n>' + esc(hrs(p.hours)) + '</td>' + (pay ? '<td style="text-align:right;" data-no-i18n>' + (p.cost != null ? esc(money(p.cost)) : '—') + '</td>' : '') + '</tr>'; }).join('') + '</tbody></table></div>';
    host.innerHTML = h;
    var go = function () { if (host.isConnected) drawRota(host); };
    host.querySelectorAll('[data-ro]').forEach(function (b) {
      var a = b.getAttribute('data-ro');
      if (a === 'centre') { b.addEventListener('change', function () { RS.centre = +b.value; go(); }); return; }
      b.addEventListener('click', function () {
        if (a === 'prev') { RS.week = addDays(d.week, -7); go(); }
        else if (a === 'next') { RS.week = addDays(d.week, 7); go(); }
        else if (a === 'now') { RS.week = null; go(); }
        else if (a === 'swap') { var w = sw.filter(function (x) { return x.id === +b.getAttribute('data-id'); })[0]; if (w) openSwapDecision(w, d.me, go); }
        else if (a === 'post') {   // Phase 4 batch 3: an open shift for this ratio shortfall (HR > Open shifts)
          var g = gaps[+b.getAttribute('data-i')];
          if (g) Api.get('/hr/open-shifts?centre_id=' + d.centre_id + '&week=' + d.week).then(function (bd) { openPostOpen(bd, { room_id: g.room_id, date: g.date, start: g.from, end: g.to }, go); }, function (e) { toast('⚠️', 'Could not open it', errMsg(e), '#DC2626'); });
        }
      });
    });
    drawClockWatch(host);   // item 11: clock-ins away from the centre, overtime, the admin's clock-in rules
    var m = /[?&]swap=(\d+)/.exec(location.hash || '');
    if (m) { var w = sw.filter(function (x) { return x.id === +m[1]; })[0]; if (w) openSwapDecision(w, d.me, go); }
  }

  function openSwapDecision(w, me, done) {
    var kv = function (k, v) { return '<div style="display:flex;justify-content:space-between;gap:10px;padding:5px 0;border-bottom:1px solid #F1F5F9;font-size:13.5px;"><span style="color:#64748B;">' + esc(k) + '</span><b style="text-align:right;">' + v + '</b></div>'; };
    var s = w.shift || {};
    var h = kv('Type', w.kind === 'swap' ? 'Swap' : 'Giveaway') + kv('From', '<span data-no-i18n>' + esc(w.from) + '</span>')
      + kv('Shift', '<span data-no-i18n>' + esc(wday(s.date)) + '</span> ' + span(s) + ' <span class="hr-sub" data-no-i18n>' + esc(s.room || '') + '</span>');
    if (w.swap_shift) h += kv('In return', '<span data-no-i18n>' + esc(wday(w.swap_shift.date)) + '</span> ' + span(w.swap_shift));
    h += kv('Taken by', w.accepted_by ? '<span data-no-i18n>' + esc(w.accepted_by) + '</span>' : '<span class="hr-sub">Nobody yet</span>') + kv('Status', swPill(w.status));
    if (w.note) h += kv('Note', '<span data-no-i18n>' + esc(w.note) + '</span>');
    if (w.conflict) h += '<div style="margin-top:10px;padding:8px 10px;border-radius:8px;background:#FFFBEB;color:#92400E;font-size:13px;">⚠️ <span data-no-i18n>' + esc(w.accepted_by || w.to || '') + '</span>: ' + esc(w.conflict) + '</div>';
    h += '<div style="margin-top:10px;">' + field('Comment (optional, the people involved see it)', area('comment', '', 2), true) + '</div><div data-msg style="color:#B91C1C;font-size:13px;margin-top:6px;"></div>';
    var box = el(h);
    var mine = me === w.from_user_id || me === (w.accepted_by_id || 0);
    var decide = function (dec) {
      return Api.post('/hr/swaps/' + w.id + '/decide', { decision: dec, comment: val(box, 'comment') }).then(function () { saved(dec === 'approve' ? 'Shift change approved. The rota is updated.' : 'Shift change declined.'); done(); }, function (e) { msg(box, errMsg(e)); return false; });
    };
    var acts = [{ label: 'Close' }];
    if (!mine && (w.status === 'open' || w.status === 'accepted')) acts.push({ label: 'Decline', handler: function () { return decide('decline'); } });
    if (!mine && w.status === 'accepted') acts.push({ label: 'Approve', primary: true, busyLabel: 'Saving…', handler: function () { return decide('approve'); } });
    modal({ title: (w.kind === 'swap' ? 'Shift swap: ' : 'Shift giveaway: ') + w.from, body: box, actions: acts });
  }

  async function mineRota(host) {
    chipCss();
    var wrap = document.createElement('div'); wrap.setAttribute('data-hrs-rota', '1'); host.appendChild(wrap);
    var d;
    try { d = await Api.get('/hr/me/schedule'); } catch (e) { wrap.innerHTML = ''; return; }
    if (!wrap.isConnected) return;
    var reload = function () { if (wrap.isConnected) { wrap.remove(); mineRota(host); } };
    var sh = d.shifts || [], sw = d.swaps || [], offered = {};
    sw.forEach(function (w) { if (w.from_user_id === d.me && w.shift) offered[w.shift.id] = w; });
    var forMe = sw.filter(function (w) { return w.from_user_id !== d.me; });
    var weekly = d.weekly || [];
    var wk = {}; weekly.forEach(function (w) { (wk[w.dow] = wk[w.dow] || []).push(t12(w.start) + ' – ' + t12(w.end)); });
    var h = '<div class="hrt-sec">My shifts and availability</div><div class="hr-bar"><button type="button" class="hr-b p" data-mro="avail">Weekly availability</button><button type="button" class="hr-b" data-mro="unav">I can\'t work a date</button></div>'
      + '<div class="hr-note">' + (weekly.length ? '<span>You are available:</span> <span data-no-i18n>' + Object.keys(wk).map(function (k) { return esc(DOW[k].slice(0, 3) + ' ' + wk[k].join(', ')); }).join(' · ') + '.</span>' : '<span>You have not set your weekly availability, so you can be rostered any time the centre is open.</span>') + ' <span>The rota leaves out days you are on approved leave or can\'t work.</span></div>';
    if (forMe.length) h += '<div class="kt-card" style="margin-bottom:10px;"><table data-hrs="swapsforme"><thead><tr><th>From</th><th>Shift</th><th>Status</th><th></th></tr></thead><tbody>' + forMe.map(function (w) {
      return '<tr><td data-no-i18n><b>' + esc(w.from) + '</b></td><td>' + swWhat(w) + '</td><td>' + swPill(w.status) + '</td><td style="text-align:center;white-space:nowrap;">'
        + (w.status === 'open' ? '<button type="button" class="hr-b p" data-mro="take" data-id="' + w.id + '">' + (w.kind === 'swap' ? 'Accept swap' : 'Take shift') + '</button>' : '<button type="button" class="hr-b" data-mro="release" data-id="' + w.id + '">Let it go</button>') + '</td></tr>';
    }).join('') + '</tbody></table></div>';
    h += '<div class="kt-card">' + (sh.length ? '<table data-hrs="myshifts"><thead><tr><th>Day</th><th>Time</th><th>Room</th><th>Change</th><th></th></tr></thead><tbody>' + sh.map(function (s) {
      var o = offered[s.id];
      return '<tr><td style="white-space:nowrap;" data-no-i18n><b>' + esc(wday(s.date)) + '</b></td><td style="white-space:nowrap;">' + span(s) + '</td><td data-no-i18n>' + esc(s.room || '') + '</td>'
        + '<td>' + (o ? swPill(o.status) : '<span class="hr-sub">—</span>') + '</td><td style="text-align:center;white-space:nowrap;">'
        + (o ? '<button type="button" class="hr-b" data-mro="cancel" data-id="' + o.id + '">Cancel offer</button>' : '<button type="button" class="hr-b" data-mro="offer" data-id="' + s.id + '">Give away or swap</button>') + '</td></tr>';
    }).join('') + '</tbody></table>' : '<div style="padding:14px;" class="hr-sub">No shifts on the rota for the next four weeks.</div>') + '</div>';
    var un = d.unavailable || [];
    if (un.length) h += '<div class="kt-card" style="margin-top:10px;"><table data-hrs="unav"><thead><tr><th>Can\'t work</th><th>Time</th><th>Reason</th><th></th></tr></thead><tbody>' + un.map(function (u) {
      return '<tr><td data-no-i18n style="white-space:nowrap;">' + esc(wday(u.date_from) + (u.date_to !== u.date_from ? ' – ' + wday(u.date_to) : '')) + '</td><td>' + (u.start ? span(u) : 'All day') + '</td><td data-no-i18n>' + esc(u.reason || '') + '</td>'
        + '<td style="text-align:center;"><button type="button" class="hr-b" data-mro="unrm" data-id="' + u.id + '">Remove</button></td></tr>';
    }).join('') + '</tbody></table></div>';
    wrap.innerHTML = h;
    var post = function (url, body, ok) { return Api.post(url, body || {}).then(function () { saved(ok); reload(); }, function (e) { toast('⚠️', 'Could not save it', errMsg(e), '#DC2626'); }); };
    wrap.querySelectorAll('[data-mro]').forEach(function (b) {
      b.addEventListener('click', function () {
        var a = b.getAttribute('data-mro'), id = +b.getAttribute('data-id');
        if (a === 'avail') openAvailability(weekly, reload);
        else if (a === 'unav') openUnavailable(d.today, reload);
        else if (a === 'offer') { var s = sh.filter(function (x) { return x.id === id; })[0]; if (s) openOffer(s, reload); }
        else if (a === 'cancel') post('/hr/swaps/' + id + '/cancel', {}, 'Offer cancelled.');
        else if (a === 'take') post('/hr/swaps/' + id + '/accept', {}, 'Accepted. A director approves it next.');
        else if (a === 'release') post('/hr/swaps/' + id + '/cancel', {}, 'You let it go. It is open again.');
        else if (a === 'unrm') post('/hr/me/unavailability/' + id + '/remove', {}, 'Removed.');
      });
    });
  }

  function openAvailability(weekly, done) {
    var by = {}; weekly.forEach(function (w) { if (!by[w.dow]) by[w.dow] = w; });
    var rows = Object.keys(DOW).map(function (k) {
      var w = by[k];
      return '<div data-avrow style="margin-bottom:8px;">' + check('on' + k, !!w, DOW[k])
        + '<div style="display:flex;gap:6px;align-items:center;margin:4px 0 0 26px;max-width:340px;"><input class="hr-i" type="time" style="flex:1 1 0;min-width:0;width:auto;" data-f="s' + k + '" value="' + esc(w ? w.start : '07:00') + '">'
        + '<span class="hr-sub">to</span><input class="hr-i" type="time" style="flex:1 1 0;min-width:0;width:auto;" data-f="e' + k + '" value="' + esc(w ? w.end : '18:00') + '"></div></div>';
    }).join('');
    var box = el('<div class="hr-sub" style="margin-bottom:10px;">Tick the days you can work and the hours. Leave every day unticked to be available any time the centre is open.</div>' + rows + '<div data-msg style="color:#B91C1C;font-size:13px;margin-top:6px;"></div>');
    modal({ title: 'My weekly availability', body: box, actions: [{ label: 'Cancel' }, { label: 'Save', primary: true, busyLabel: 'Saving…', handler: function () {
      var days = [];
      for (var k = 1; k <= 7; k++) {
        if (!val(box, 'on' + k)) continue;
        var s = val(box, 's' + k), e = val(box, 'e' + k);
        if (!s || !e || s >= e) { msg(box, DOW[k] + ': the end time is after the start time.'); return false; }
        days.push({ dow: k, start: s.slice(0, 5), end: e.slice(0, 5) });
      }
      return Api.post('/hr/me/availability', { days: days }).then(function () { saved('Availability saved.'); done(); }, function (e) { msg(box, errMsg(e)); return false; });
    } }] });
  }

  function openUnavailable(today0, done) {
    var box = el('<div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;">' + field('From', input('date_from', today0, 'date')) + field('To (optional)', input('date_to', '', 'date'))
      + field('', check('part', false, 'Only part of the day'), true) + '<div data-part style="display:none;grid-column:1/-1;grid-template-columns:1fr 1fr;gap:10px;">' + field('From time', input('start', '09:00', 'time')) + field('To time', input('end', '12:00', 'time')) + '</div>'
      + field('Reason (optional)', input('reason', ''), true) + '</div><div data-msg style="color:#B91C1C;font-size:13px;margin-top:6px;"></div>');
    var p = box.querySelector('[data-f="part"]');
    p.addEventListener('change', function () { box.querySelector('[data-part]').style.display = p.checked ? 'grid' : 'none'; });
    modal({ title: 'I can\'t work a date', body: box, actions: [{ label: 'Cancel' }, { label: 'Save', primary: true, busyLabel: 'Saving…', handler: function () {
      var b = { date_from: val(box, 'date_from'), date_to: val(box, 'date_to'), reason: val(box, 'reason') };
      if (!b.date_from) { msg(box, 'Choose the date.'); return false; }
      if (val(box, 'part')) { b.start = (val(box, 'start') || '').slice(0, 5); b.end = (val(box, 'end') || '').slice(0, 5); }
      return Api.post('/hr/me/unavailability', b).then(function () { saved('Saved. You will not be rostered then.'); done(); }, function (e) { msg(box, errMsg(e)); return false; });
    } }] });
  }

  async function openOffer(s, done) {
    var o;
    try { o = await Api.get('/hr/swaps/options?shift_id=' + s.id); } catch (e) { toast('⚠️', 'Could not open it', errMsg(e), '#DC2626'); return; }
    var people = {}; (o.people || []).forEach(function (p) { people[p.id] = p.name; });
    var box = el('<div class="hr-note" style="margin-top:0;"><b data-no-i18n>' + esc(wday(s.date)) + '</b> ' + span(s) + ' <span class="hr-sub" data-no-i18n>' + esc(s.room || '') + '</span></div>'
      + '<div style="display:grid;grid-template-columns:1fr;gap:10px;">' + field('What do you want to do?', select('kind', opts({ giveaway: 'Give this shift away', swap: 'Swap it for one of a colleague\'s shifts' }, 'giveaway')))
      + field('Colleague', select('to_user_id', opts(people, '', 'Anyone at the centre'))) + '<div data-theirs style="display:none;">' + field('Their shift', select('swap_shift_id', '')) + '</div>'
      + field('Note (optional)', input('note', '')) + '</div><div class="hr-sub" style="margin-top:8px;">A colleague accepts it, then a director approves it before the rota changes.</div><div data-msg style="color:#B91C1C;font-size:13px;margin-top:6px;"></div>');
    var kind = box.querySelector('[data-f="kind"]'), to = box.querySelector('[data-f="to_user_id"]'), theirs = box.querySelector('[data-f="swap_shift_id"]');
    var sync = function () {
      var swap = kind.value === 'swap';
      box.querySelector('[data-theirs]').style.display = swap ? '' : 'none';
      to.options[0].textContent = swap ? 'Choose a colleague' : 'Anyone at the centre';
      var list = (o.shifts || []).filter(function (x) { return String(x.user_id) === to.value; });
      theirs.innerHTML = list.length ? list.map(function (x) { return '<option value="' + x.id + '">' + esc(wday(x.date) + ' ' + t12(x.start) + ' – ' + t12(x.end)) + '</option>'; }).join('') : '<option value="">No upcoming shifts</option>';
    };
    kind.addEventListener('change', sync); to.addEventListener('change', sync); sync();
    modal({ title: 'Give away or swap a shift', body: box, actions: [{ label: 'Cancel' }, { label: 'Offer it', primary: true, busyLabel: 'Sending…', handler: function () {
      var b = { shift_id: s.id, kind: kind.value, to_user_id: to.value ? +to.value : null, swap_shift_id: kind.value === 'swap' && theirs.value ? +theirs.value : null, note: val(box, 'note') };
      if (b.kind === 'swap' && (!b.to_user_id || !b.swap_shift_id)) { msg(box, 'Choose a colleague and one of their shifts.'); return false; }
      return Api.post('/hr/swaps', b).then(function () { saved('Offered. You keep the shift until a director approves the change.'); done(); }, function (e) { msg(box, errMsg(e)); return false; });
    } }] });
  }

  /* ── Item 11 (2026-11-26): clock-in rules on HR > Rota: clock-ins away from the centre, near / into overtime, and the
     admin's rule (geofence off / flag / block, overtime threshold) with each centre's area picked on a map. ───────── */
  var GEO_MODE = { off: 'Off: never ask for a location', flag: 'Allow, and flag clock-ins away from the centre', block: 'Block clock-ins away from the centre' };
  async function drawClockWatch(host) {
    var box = document.createElement('div'); box.setAttribute('data-hrs-clock', '1'); host.appendChild(box);
    var w;
    try { w = await Api.get('/hr/clock/watch'); } catch (e) { box.remove(); return; }
    if (!box.isConnected) return;
    var s = w.settings || {}, away = w.away || [], ot = w.overtime || [];
    var h = '<div class="hrt-sec" style="display:flex;align-items:center;gap:8px;"><span>Clock-ins this week</span><span style="flex:1"></span>'
      + (w.is_admin ? '<button type="button" class="hr-b" data-ck="rules">Clock-in rules</button>' : '') + '</div>'
      + '<div class="hr-sub" style="margin:-4px 0 8px;"><span>Location check:</span> <b>' + esc(s.geofence === 'block' ? 'Block away from the centre' : (s.geofence === 'flag' ? 'Flag away from the centre' : 'Off')) + '</b> · '
      + (s.overtime_on ? '<span>Overtime warning at</span> <b data-no-i18n>' + esc(String(s.warn_at_hours)) + ' h</b> <span>(overtime after</span> <b data-no-i18n>' + esc(String(s.overtime_hours)) + ' h</b>)' : '<span>Overtime warning off</span>') + '</div>'
      + '<div class="kt-card" style="margin-bottom:10px;">' + (ot.length ? '<table data-hrs="overtime"><thead><tr><th>Person</th><th style="text-align:right;">Worked</th><th style="text-align:right;">Still scheduled</th><th style="text-align:right;">Week total</th><th>Overtime</th></tr></thead><tbody>' + ot.map(function (o) {
        return '<tr' + (o.over ? ' style="background:#FEF2F2;"' : ' style="background:#FFFBEB;"') + '><td data-no-i18n><b>' + esc(o.person) + '</b></td><td style="text-align:right;" data-no-i18n>' + esc(hrs(o.worked)) + '</td><td style="text-align:right;" data-no-i18n>' + esc(hrs(o.scheduled)) + '</td><td style="text-align:right;" data-no-i18n><b>' + esc(hrs(o.hours)) + '</b></td><td>'
          + (o.over ? pill('#FEE2E2', '#991B1B', 'Into overtime') : pill('#FEF3C7', '#92400E', 'Close to overtime')) + '</td></tr>';
      }).join('') + '</tbody></table>' : '<div style="padding:14px;" class="hr-sub">Nobody is close to overtime this week.</div>') + '</div>'
      + '<div class="kt-card">' + (away.length ? '<table data-hrs="away"><thead><tr><th>Person</th><th>Clocked in</th><th>Centre</th><th>Location</th></tr></thead><tbody>' + away.map(function (a) {
        return '<tr><td data-no-i18n><b>' + esc(a.person) + '</b></td><td data-no-i18n style="white-space:nowrap;">' + esc(when(a.at)) + '</td><td data-no-i18n>' + esc(a.centre) + '</td><td>'
          + (a.status === 'outside' ? pill('#FEF3C7', '#92400E', 'Away from the centre') + ' <span class="hr-sub" data-no-i18n>' + esc(a.distance || '') + '</span>' : pill('#F1F5F9', '#475569', 'No location shared')) + '</td></tr>';
      }).join('') + '</tbody></table>' : '<div style="padding:14px;" class="hr-sub">No clock-ins away from the centre this week.</div>') + '</div>';
    box.innerHTML = h;
    var r = box.querySelector('[data-ck="rules"]');
    if (r) r.addEventListener('click', function () { openClockRules(function () { box.remove(); drawClockWatch(host); }); });
  }

  async function openClockRules(done) {
    var d;
    try { d = await Api.get('/hr/clock/settings'); } catch (e) { toast('⚠️', 'Could not open it', errMsg(e), '#DC2626'); return; }
    var s = d.settings || {};
    var cs = (d.centres || []).map(function (c) {
      var f = c.fence;
      return '<tr><td data-no-i18n><b>' + esc(c.name) + '</b>' + (c.address ? '<div class="hr-sub">' + esc(c.address) + '</div>' : '') + '</td><td>'
        + (f ? '<span data-no-i18n>' + esc(String(f.radius_m)) + ' m</span> <span class="hr-sub">' + (f.from_address ? 'around the address' : 'around a spot on the map') + '</span>' : '<span class="hr-sub">No area: not checked</span>')
        + '</td><td style="text-align:center;"><button type="button" class="hr-b" data-fc="' + c.id + '">Set area</button></td></tr>';
    }).join('');
    var box = el('<div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;">'
      + field('Location check at clock-in', select('geofence', opts(GEO_MODE, s.geofence || 'off')), true)
      + '<div class="hr-sub" style="grid-column:1/-1;margin-top:-4px;">Only at centres with an area below, and only when someone clocks in. People see this first: <i>' + esc(d.consent || '') + '</i></div>'
      + field('', check('overtime_on', s.overtime_on !== false, 'Warn about overtime (the person and their director)'), true)
      + field('Warn at (hours this week)', input('warn_at_hours', s.warn_at_hours, 'number')) + field('Overtime after (hours this week)', input('overtime_hours', s.overtime_hours, 'number'))
      + field('', check('skip_contractors', s.skip_contractors !== false, 'Leave out contractors'), true)
      + '</div><div class="hrt-sec">Clock-in area for each centre or home</div><div class="kt-card"><table data-hrs="fences"><thead><tr><th>Centre</th><th>Area</th><th></th></tr></thead><tbody>' + cs + '</tbody></table></div>'
      + '<div data-msg style="color:#B91C1C;font-size:13px;margin-top:6px;"></div>');
    box.querySelectorAll('[data-fc]').forEach(function (b) {
      b.addEventListener('click', function () {
        var c = (d.centres || []).filter(function (x) { return x.id === +b.getAttribute('data-fc'); })[0];
        if (c) { closeModal(); setTimeout(function () { openFence(c, function () { openClockRules(done); }); }, 60); }
      });
    });
    modal({ title: 'Clock-in rules', body: box, actions: [{ label: 'Cancel' }, { label: 'Save', primary: true, busyLabel: 'Saving…', handler: function () {
      var b = { geofence: val(box, 'geofence'), overtime_on: !!val(box, 'overtime_on'), warn_at_hours: Number(val(box, 'warn_at_hours')), overtime_hours: Number(val(box, 'overtime_hours')), skip_contractors: !!val(box, 'skip_contractors') };
      if (!(b.overtime_hours > 0) || !(b.warn_at_hours > 0)) { msg(box, 'Enter the hours.'); return false; }
      if (b.warn_at_hours > b.overtime_hours) { msg(box, 'The warning comes at or before overtime.'); return false; }
      return Api.put('/hr/clock/settings', b).then(function () { saved('Clock-in rules saved.'); done(); }, function (e) { msg(box, errMsg(e)); return false; });
    } }] });
  }

  var _leaf = null;
  function leaflet() {
    if (_leaf) return _leaf;
    _leaf = new Promise(function (res, rej) {
      if (window.L) { res(window.L); return; }
      if (!document.querySelector('link[data-kt-leaflet]')) { var l = document.createElement('link'); l.rel = 'stylesheet'; l.href = '/js/vendor/leaflet-1.9.4/leaflet.css'; l.setAttribute('data-kt-leaflet', '1'); document.head.appendChild(l); }
      var s = document.createElement('script'); s.src = '/js/vendor/leaflet-1.9.4/leaflet.js';
      s.onload = function () { res(window.L); }; s.onerror = function () { _leaf = null; rej(new Error('map')); };
      document.head.appendChild(s);
    });
    return _leaf;
  }

  function openFence(c, done) {
    var picked = c.lat != null && c.lng != null ? { lat: +c.lat, lng: +c.lng } : null;
    var box = el('<div class="hr-sub" style="margin-bottom:8px;">Clock-ins inside the circle count as at the centre. Click the map to put the centre point where the building is, or use the address.</div>'
      + '<div style="display:flex;gap:8px;flex-wrap:wrap;align-items:flex-end;margin-bottom:8px;">' + field('Radius (metres)', input('radius_m', c.radius_m || 150, 'number'))
      + '<button type="button" class="hr-b" data-fz="addr">Use the address</button><button type="button" class="hr-b" data-fz="here">Use where I am now</button></div>'
      + '<div style="display:flex;gap:6px;margin-bottom:8px;"><input class="hr-i" data-f="q" placeholder="Search for an address" style="flex:1;min-width:0;"><button type="button" class="hr-b" data-fz="find">Find</button></div>'
      + '<div data-map style="height:300px;border-radius:10px;overflow:hidden;border:1px solid #E5E7EB;"></div><div class="hr-sub" data-pt style="margin-top:6px;"></div>'
      + '<div data-msg style="color:#B91C1C;font-size:13px;margin-top:6px;"></div>');
    var map = null, mk = null, circ = null, L = null;
    var pt = function () {
      var t = box.querySelector('[data-pt]');
      t.textContent = picked ? 'Centre point: ' + picked.lat.toFixed(5) + ', ' + picked.lng.toFixed(5) : (c.latitude != null ? 'Using the centre\'s address.' : 'No point yet. Click the map.');
    };
    var draw = function () {
      if (!map) return;
      var p = picked || (c.latitude != null ? { lat: c.latitude, lng: c.longitude } : null);
      if (mk) { map.removeLayer(mk); mk = null; } if (circ) { map.removeLayer(circ); circ = null; }
      if (!p) return;
      mk = L.marker([p.lat, p.lng]).addTo(map);
      circ = L.circle([p.lat, p.lng], { radius: Math.max(25, +(val(box, 'radius_m') || 150)), color: '#0E7490', weight: 2, fillOpacity: 0.12 }).addTo(map);
      pt();
    };
    pt();
    box.querySelector('[data-f="radius_m"]').addEventListener('input', draw);
    box.querySelectorAll('[data-fz]').forEach(function (b) {
      b.addEventListener('click', function () {
        var a = b.getAttribute('data-fz');
        if (a === 'addr') { if (c.latitude == null) { msg(box, 'This centre has no map location from its address. Click the map instead.'); return; } picked = null; draw(); if (map) map.setView([c.latitude, c.longitude], 17); }
        else if (a === 'here' && navigator.geolocation) navigator.geolocation.getCurrentPosition(function (p) { picked = { lat: p.coords.latitude, lng: p.coords.longitude }; draw(); if (map) map.setView([picked.lat, picked.lng], 17); }, function () { msg(box, 'Your device did not share a location.'); }, { enableHighAccuracy: true, timeout: 15000 });
        else if (a === 'find') {
          var q = val(box, 'q'); if (!q) return;
          fetch('https://nominatim.openstreetmap.org/search?format=json&limit=1&q=' + encodeURIComponent(q), { headers: { Accept: 'application/json' } }).then(function (r) { return r.json(); }).then(function (j) {
            if (j && j[0] && map) map.setView([+j[0].lat, +j[0].lon], 17); else msg(box, 'Could not find that address.');
          }, function () { msg(box, 'Could not find that address.'); });
        }
      });
    });
    modal({ title: 'Clock-in area: ' + c.name, body: box, actions: [{ label: 'Cancel', handler: function () { setTimeout(done, 60); } },
      { label: 'Remove area', handler: function () { return Api.put('/hr/clock/centres/' + c.id + '/fence', { radius_m: null }).then(function () { saved('Area removed. Clock-ins here are not checked.'); setTimeout(done, 60); }, function (e) { msg(box, errMsg(e)); return false; }); } },
      { label: 'Save area', primary: true, busyLabel: 'Saving…', handler: function () {
        var r = Math.round(+(val(box, 'radius_m') || 0));
        if (!(r >= 25 && r <= 5000)) { msg(box, 'Choose a radius from 25 to 5000 metres.'); return false; }
        if (!picked && c.latitude == null) { msg(box, 'Click the map to put the centre point.'); return false; }
        return Api.put('/hr/clock/centres/' + c.id + '/fence', { radius_m: r, lat: picked ? picked.lat : null, lng: picked ? picked.lng : null }).then(function () { saved('Clock-in area saved.'); setTimeout(done, 60); }, function (e) { msg(box, errMsg(e)); return false; });
      } }] });
    leaflet().then(function (Lf) {
      L = Lf;
      var host = box.querySelector('[data-map]');
      if (!host || !host.isConnected) return;
      var p = picked || (c.latitude != null ? { lat: c.latitude, lng: c.longitude } : { lat: 43.65, lng: -79.38 });
      map = L.map(host, { scrollWheelZoom: false }).setView([p.lat, p.lng], picked || c.latitude != null ? 17 : 11);
      L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', { maxZoom: 19, attribution: '&copy; OpenStreetMap' }).addTo(map);
      map.on('click', function (e) { picked = { lat: e.latlng.lat, lng: e.latlng.lng }; draw(); });
      if (!picked && c.latitude == null && c.address) {   // no point yet: open on the centre's address, not a default city
        var look = function (q, z) { return fetch('https://nominatim.openstreetmap.org/search?format=json&limit=1&q=' + encodeURIComponent(q), { headers: { Accept: 'application/json' } }).then(function (r) { return r.json(); }).then(function (j) { if (j && j[0] && map) { map.setView([+j[0].lat, +j[0].lon], z); return true; } return false; }, function () { return false; }); };
        look(c.address, 17).then(function (ok) { var city = c.address.split(',').pop().trim(); if (!ok && city && city !== c.address) look(city, 12); });
      }
      draw();
      setTimeout(function () { try { map.invalidateSize(); } catch (e) {} }, 150);
    }, function () { box.querySelector('[data-map]').innerHTML = '<div class="hr-sub" style="padding:12px;">The map could not load. Use the address or where you are now.</div>'; });
  }

  /* ── Item 12 (2026-11-26): HR > Letters. Templates (admins edit + tick "Reviewed by our lawyer"), send for e-signature. ── */
  var DRAFT_NOTE = 'Draft – have an employment lawyer review before use';
  function reviewPill(t) { return t.reviewed_on ? pill('#DCFCE7', '#166534', 'Reviewed by our lawyer') : pill('#FEF3C7', '#92400E', 'Needs lawyer review'); }
  async function drawLetters(host) {
    chipCss();
    var d;
    try { d = await Api.get('/hr/letters'); } catch (e) { host.innerHTML = '<div class="hr-note">' + esc(errMsg(e)) + '</div>'; return; }
    if (!host.isConnected) return;
    var ts = d.templates || [], sent = d.sent || [];
    var h = '<div class="hr-note"><span>Offer letters, contracts, warnings and terminations, filled in from the employee file and sent for e-signature in My forms. A template can be sent only after an agency admin marks it reviewed by your employment lawyer.</span></div>'
      + '<div class="hr-bar"><button type="button" class="hr-b p" data-lt="send">Send a letter</button></div>'
      + '<div class="hrt-sec">Templates</div><div class="kt-card"><table data-hrs="letter-templates"><thead><tr><th>Letter</th><th>Status</th><th></th></tr></thead><tbody>' + ts.map(function (t) {
        return '<tr><td><b data-no-i18n>' + esc(t.title) + '</b>' + (t.is_default ? '<div class="hr-sub">Starting text, not yet edited</div>' : '') + '</td><td>' + reviewPill(t)
          + (t.reviewed_on ? ' <span class="hr-sub" data-no-i18n>' + esc(day(t.reviewed_on)) + '</span>' : '') + '</td>'
          + '<td style="text-align:center;"><button type="button" data-lt="edit" data-id="' + t.id + '">' + (d.is_admin ? 'Edit template' : 'View template') + '</button></td></tr>';
      }).join('') + '</tbody></table></div>'
      + '<div class="hrt-sec">Sent letters</div><div class="kt-card">' + (sent.length ? '<table data-hrs="letters-sent"><thead><tr><th>Letter</th><th>Person</th><th>Signs for the agency</th><th>Sent</th><th>Signed</th></tr></thead><tbody>' + sent.map(function (l) {
        return '<tr><td data-no-i18n><b>' + esc(l.title) + '</b></td><td data-no-i18n>' + esc(l.person) + '</td><td data-no-i18n>' + esc(l.signatory) + '</td><td data-no-i18n style="white-space:nowrap;">' + esc(day(l.sent_at)) + '</td><td>'
          + (l.signed_at ? pill('#DCFCE7', '#166534', 'Signed') + ' <span class="hr-sub" data-no-i18n>' + esc(day(l.signed_at)) + '</span>' : pill('#E0F2FE', '#075985', 'Waiting to sign')) + '</td></tr>';
      }).join('') + '</tbody></table>' : '<div style="padding:14px;" class="hr-sub">No letters sent yet.</div>') + '</div>';
    host.innerHTML = h;
    var reload = function () { if (host.isConnected) drawLetters(host); };
    host.querySelectorAll('[data-lt]').forEach(function (b) {
      b.addEventListener('click', function () {
        var a = b.getAttribute('data-lt');
        if (a === 'send') openSendLetter(d, reload);
        else { var t = ts.filter(function (x) { return x.id === +b.getAttribute('data-id'); })[0]; if (t) openTemplate(t, d, reload); }
      });
    });
  }

  function openTemplate(t, d, done) {
    var admin = !!d.is_admin;
    var fields = d.fields || {};
    var box = el((t.reviewed_on ? '' : '<div data-draft style="margin:0 0 10px;padding:9px 12px;border-radius:8px;background:#FEF3C7;border:1px solid #FDE68A;color:#92400E;font-weight:700;font-size:13px;">⚠️ ' + esc(DRAFT_NOTE) + '</div>')
      + field('Title', admin ? input('title', t.title) : '<div data-no-i18n><b>' + esc(t.title) + '</b></div>', true)
      + '<div style="margin-top:10px;">' + field('Letter text', admin ? '<textarea class="hr-i" data-f="body" rows="16" style="width:100%;min-height:340px;font-family:inherit;" data-no-i18n>' + esc(t.body) + '</textarea>'
        : '<div data-no-i18n style="white-space:pre-wrap;border:1px solid #E2E8F0;border-radius:8px;padding:10px;max-height:340px;overflow:auto;">' + esc(t.body) + '</div>', true) + '</div>'
      + (admin ? '<div class="hr-sub" style="margin:8px 0 4px;">Fill-in fields (click to add at the cursor):</div><div class="hrt-chips">' + Object.keys(fields).map(function (k) {
        return '<button type="button" class="hrt-chip" data-mf="' + esc(k) + '" data-kttip="' + esc(fields[k]) + '"><span data-no-i18n>{{' + esc(k) + '}}</span></button>';
      }).join('') + '</div>' : '')
      + '<div style="margin-top:12px;padding:10px 12px;border:1px solid #E2E8F0;border-radius:10px;">'
      + (admin ? '<label style="display:flex;gap:8px;align-items:center;font-size:13.5px;font-weight:700;flex-wrap:wrap;"><input type="checkbox" class="kt-nosw" data-f="rev"' + (t.reviewed_on ? ' checked' : '') + '> <span>Reviewed by our lawyer on</span> '
        + '<input class="hr-i" type="date" data-f="rev_on" value="' + esc(t.reviewed_on || today()) + '" style="width:170px;"></label><div class="hr-sub" style="margin-top:6px;">Changing the text clears this, so the new wording is reviewed before it is sent.</div>'
        : reviewPill(t) + (t.reviewed_on ? ' <span class="hr-sub" data-no-i18n>' + esc(day(t.reviewed_on)) + (t.reviewed_by ? ' · ' + esc(t.reviewed_by) : '') + '</span>' : ' <span class="hr-sub">Only an agency admin can mark it reviewed.</span>'))
      + '</div><div data-msg style="color:#B91C1C;font-size:13px;margin-top:6px;"></div>');
    var ta = box.querySelector('[data-f="body"]');
    box.querySelectorAll('[data-mf]').forEach(function (b) {
      b.addEventListener('click', function () {
        if (!ta) return;
        var tag = '{{' + b.getAttribute('data-mf') + '}}', s = ta.selectionStart || 0, e2 = ta.selectionEnd || 0;
        ta.value = ta.value.slice(0, s) + tag + ta.value.slice(e2); ta.focus(); ta.selectionStart = ta.selectionEnd = s + tag.length;
      });
    });
    var acts = [{ label: admin ? 'Cancel' : 'Close' }];
    if (admin) acts.push({ label: 'Save', primary: true, busyLabel: 'Saving…', handler: function () {
      var title = val(box, 'title'), body = ta ? ta.value.trim() : '';
      if (!title || !body) { msg(box, 'Give the letter a title and some text.'); return false; }
      var changed = title !== t.title || body !== String(t.body).trim();
      var rev = !!val(box, 'rev'), revOn = val(box, 'rev_on');
      if (rev && !revOn) { msg(box, 'Enter the date your lawyer reviewed it.'); return false; }
      return Api.put('/hr/letters/templates/' + t.id, { title: title, body: body }).then(function () {
        var want = rev ? revOn : null;
        // Ticked while changing the text: the admin is confirming the NEW wording was reviewed.
        if (changed || want !== (t.reviewed_on || null)) return Api.post('/hr/letters/templates/' + t.id + '/review', { reviewed_on: want });
      }).then(function () { saved('Template saved.'); done(); }, function (e) { msg(box, errMsg(e)); return false; });
    } });
    modal({ title: t.title, body: box, actions: acts });
  }

  function openSendLetter(d, done) {
    var tpls = {}; (d.templates || []).forEach(function (t) { tpls[t.id] = t.title + (t.reviewed_on ? '' : ' (needs lawyer review)'); });
    var ppl = {}; (d.people || []).forEach(function (p) { ppl[p.id] = p.name; });
    var sig = {}; (d.signatories || []).forEach(function (p) { sig[p.id] = p.name + ' (' + (p.roles || []).join(', ') + ')'; });
    var box = el('<div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;">' + field('Letter', select('template_id', opts(tpls, '', 'Choose a letter')), true)
      + field('Person', select('user_id', opts(ppl, '', 'Choose a person'))) + field('Signs for the agency', select('signatory_id', opts(sig, '', 'Choose who signs')))
      + field('Effective date', input('effective_date', '', 'date')) + '<div></div>'
      + field('Details (for {{details}}: the reason, the duties, the terms)', area('details', '', 4), true) + '</div>'
      + '<div class="hr-bar" style="margin:10px 0 0;"><button type="button" class="hr-b" data-lp="1">Preview</button></div>'
      + '<div data-prev style="display:none;margin-top:10px;border:1px solid #E2E8F0;border-radius:10px;padding:14px 16px;max-height:360px;overflow:auto;background:#fff;color:#111827;font-size:13px;line-height:1.55;"></div>'
      + '<div class="hr-sub" style="margin-top:8px;">The person signs it in My forms. A director or admin countersigns it there.</div><div data-msg style="color:#B91C1C;font-size:13px;margin-top:6px;"></div>');
    var body = function () { return { template_id: +val(box, 'template_id') || null, user_id: +val(box, 'user_id') || null, signatory_id: +val(box, 'signatory_id') || null, effective_date: val(box, 'effective_date'), details: val(box, 'details') }; };
    var ready = function (b) { if (!b.template_id || !b.user_id || !b.signatory_id) { msg(box, 'Choose the letter, the person and who signs.'); return false; } msg(box, ''); return true; };
    box.querySelector('[data-lp]').addEventListener('click', function () {
      var b = body(); if (!ready(b)) return;
      Api.post('/hr/letters/preview', b).then(function (p) {
        var pv = box.querySelector('[data-prev]'); pv.style.display = '';
        var soft = (p.missing || []).filter(function (m) { return (p.blocking || []).indexOf(m) < 0; });
        pv.innerHTML = (p.blocking && p.blocking.length ? '<div style="margin-bottom:10px;padding:8px 10px;border-radius:8px;background:#FEE2E2;color:#991B1B;font-size:12.5px;font-weight:700;"><span>Fill these in before sending (employee file or the boxes above):</span> <span data-no-i18n>' + esc(p.blocking.join(', ')) + '</span></div>' : '')
          + (soft.length ? '<div style="margin-bottom:10px;padding:8px 10px;border-radius:8px;background:#FFFBEB;color:#92400E;font-size:12.5px;"><span>Empty, left out of the letter:</span> <span data-no-i18n>' + esc(soft.join(', ')) + '</span></div>' : '')
          + (p.reviewed ? '' : '<div style="margin-bottom:10px;padding:8px 10px;border-radius:8px;background:#FEF3C7;color:#92400E;font-size:12.5px;font-weight:700;">' + esc(DRAFT_NOTE) + '</div>')
          + '<div data-no-i18n><b>' + esc(p.title) + '</b><br><br>' + p.html + '<br><b>' + esc(p.signatory) + '</b>' + (p.signatory_title ? ', ' + esc(p.signatory_title) : '') + '</div>';
      }, function (e) { msg(box, errMsg(e)); });
    });
    modal({ title: 'Send a letter for signature', body: box, actions: [{ label: 'Cancel' }, { label: 'Send for signature', primary: true, busyLabel: 'Sending…', handler: function () {
      var b = body(); if (!ready(b)) return false;
      return Api.post('/hr/letters/send', b).then(function () { saved('Sent. It is waiting in their My forms.'); done(); }, function (e) { msg(box, errMsg(e)); return false; });
    } }] });
  }


  /* ── HR Phase 4, batch 2 (2026-11-27): HR > Overtime, HR > Timesheets, My HR "Overtime and time in lieu" ─────────────
     Overtime: a staff member logs extra hours (or a director / admin logs them for someone); somebody else approves them
     as pay (a line on the next manual payslip) or banks them as time in lieu (the "Overtime bank" leave balance, booked
     with the ordinary time-off form). Timesheets: scheduled against actual per person for a centre and period, the paid
     hours after the agency's rules, missing-punch flags, approve + lock (an admin unlocks, with a reason). */
  function hh(min) { var v = Math.round((+min || 0) / 60 * 100) / 100; return v + ' h'; }
  function signedH(min) { var v = Math.round((+min || 0) / 60 * 100) / 100; return (v > 0 ? '+' : '') + v + ' h'; }
  var OT_ST = { submitted: ['#FEF3C7', '#92400E', 'Waiting for approval'], approved: ['#CCFBF1', '#115E59', 'Approved'],
    paid: ['#DCFCE7', '#166534', 'Paid'], declined: ['#FEE2E2', '#991B1B', 'Declined'], withdrawn: ['#F1F5F9', '#475569', 'Withdrawn'] };
  function otPill(o) {
    var p = OT_ST[o.status] || OT_ST.submitted, t = p[2];
    if (o.status === 'approved') t = o.outcome === 'bank' ? 'Banked as time in lieu' : 'Approved: in the next pay';
    return pill(p[0], p[1], t);
  }
  function otAsk(p) { return p === 'bank' ? 'Time in lieu' : p === 'pay' ? 'Pay' : 'Either'; }
  function rateX(r) { return '× ' + String(Math.round((+r || 1) * 100) / 100); }

  var OS = { st: 'submitted' };
  async function drawOvertime(host) {
    chipCss();
    var d;
    try { d = await Api.get('/hr/overtime?status=' + encodeURIComponent(OS.st)); } catch (e) { host.innerHTML = '<div class="hr-note">' + esc(errMsg(e)) + '</div>'; return; }
    if (!host.isConnected) return;
    var rows = d.overtime || [], n = d.counts || {}, all = 0, s = d.settings || {};
    Object.keys(n).forEach(function (k) { all += n[k]; });
    var chips = [['submitted', 'To approve'], ['approved', 'Approved'], ['paid', 'Paid'], ['declined', 'Declined'], ['all', 'All']];
    host.innerHTML = '<div class="hr-note">Extra hours your team worked. Approve each one as pay (added to the person\'s next payslip in Payroll &gt; Run payroll) or bank it as time in lieu (their Overtime bank, booked like any time off). Overtime rate: <b data-no-i18n>' + esc(rateX(s.rate)) + '</b>. Nobody approves their own.</div>'
      + '<div class="hrt-chips">' + chips.map(function (c) {
        var on = OS.st === c[0], k = c[0] === 'all' ? all : (n[c[0]] || 0);
        return '<button type="button" class="hrt-chip' + (on ? ' on' : '') + '" data-ost="' + c[0] + '"' + (on ? ' aria-current="true"' : '') + '>' + esc(c[1]) + ' <span class="n">' + k + '</span></button>';
      }).join('') + '<span style="flex:1"></span>' + ((d.people || []).length ? '<button type="button" class="hr-b p" data-ot="log">Log overtime for someone</button>' : '')
      + '<button type="button" class="hr-b" data-ot="settings">Overtime and timesheet rules</button></div>'
      + '<div class="kt-card">' + (rows.length ? '<table data-hrs="overtime"><thead><tr><th>Person</th><th>Date</th><th style="text-align:right;">Hours</th><th>What for</th><th>Asked for</th><th>Status</th><th></th></tr></thead><tbody>'
        + rows.map(function (o) {
          return '<tr><td><b data-no-i18n>' + esc(o.person) + '</b>' + (o.centre ? '<div class="hr-sub" data-no-i18n>' + esc(o.centre) + '</div>' : '') + '</td>'
            + '<td style="white-space:nowrap;">' + esc(day(o.work_date)) + '</td><td style="text-align:right;white-space:nowrap;" data-no-i18n><b>' + esc(hh(o.minutes)) + '</b></td>'
            + '<td data-no-i18n>' + esc(o.reason || '') + (o.logged_by ? '<div class="hr-sub">Logged by <span data-no-i18n>' + esc(o.logged_by) + '</span></div>' : '') + '</td>'
            + '<td>' + esc(otAsk(o.preference)) + '</td><td>' + otPill(o) + '</td>'
            + '<td style="text-align:center;white-space:nowrap;"><button type="button" data-ot="open" data-id="' + o.id + '">View overtime</button></td></tr>';
        }).join('') + '</tbody></table>' : '<div style="padding:16px;" class="hr-sub">No overtime here.</div>') + '</div>';
    var reload = function () { if (host.isConnected) drawOvertime(host); };
    host.querySelectorAll('[data-ost]').forEach(function (b) { b.addEventListener('click', function () { OS.st = b.getAttribute('data-ost'); drawOvertime(host); }); });
    host.querySelectorAll('[data-ot]').forEach(function (b) {
      b.addEventListener('click', function () {
        var a = b.getAttribute('data-ot');
        if (a === 'open') { var o = rows.filter(function (x) { return x.id === +b.getAttribute('data-id'); })[0]; if (o) openOvertime(o, o.user_id !== d.me, s, reload); }
        else if (a === 'log') openLogOvertime({ settings: s, today: d.today, people: d.people }, true, reload);
        else if (a === 'settings') openTimeSettings(reload);
      });
    });
    var m = /[?&]ot=(\d+)/.exec(location.hash || '');
    if (m) {
      var o = rows.filter(function (x) { return x.id === +m[1]; })[0];
      if (o) openOvertime(o, o.user_id !== d.me, s, reload);
      else if (OS.st !== 'all') { OS.st = 'all'; drawOvertime(host); }
    }
  }

  function openOvertime(o, manage, s, done) {
    var kv = function (k, v) { return '<div style="display:flex;justify-content:space-between;gap:10px;padding:5px 0;border-bottom:1px solid #F1F5F9;font-size:13.5px;"><span style="color:#64748B;">' + esc(k) + '</span><b data-no-i18n style="text-align:right;">' + (v == null || v === '' ? '<span class="hr-sub">—</span>' : v) + '</b></div>'; };
    var rate = (o.rate != null ? o.rate : s.rate) || 1;
    var h = '<div>' + kv('Person', esc(o.person)) + kv('Date', esc(day(o.work_date))) + kv('Hours worked', esc(hh(o.minutes)))
      + kv('What for', esc(o.reason || '')) + kv('Asked for', esc(otAsk(o.preference))) + (o.logged_by ? kv('Logged by', esc(o.logged_by)) : '') + kv('Status', otPill(o));
    if (o.status === 'approved' || o.status === 'paid') h += kv('Rate', esc(rateX(o.rate)));
    if (o.outcome === 'bank' && o.bank_hours != null) h += kv('Put in the Overtime bank', esc(String(o.bank_hours) + ' h'));
    if (o.decided_by) h += kv(o.status === 'declined' ? 'Declined by' : 'Approved by', esc(o.decided_by) + (o.decided_at ? ' · ' + esc(day(o.decided_at)) : ''));
    if (o.decision_comment) h += kv('Comment', esc(o.decision_comment));
    if (o.status === 'paid') h += kv('Paid', esc(day(o.paid_at)) + (o.payslip ? ' · ' + esc(o.payslip) : ''));
    h += '</div>';
    var canDecide = manage && (o.status === 'submitted' || o.status === 'declined');
    if (canDecide) {
      var bankH = Math.round(o.minutes / 60 * rate * 100) / 100;
      h += '<div class="hr-sub" style="margin-top:10px;">At your agency\'s rate (<span data-no-i18n>' + esc(rateX(rate)) + '</span>): paid as <b data-no-i18n>' + esc(hh(o.minutes)) + ' ' + esc(rateX(rate)) + '</b> on the next payslip, or <b data-no-i18n>' + esc(String(bankH)) + ' h</b> into their Overtime bank.</div>'
        + '<div style="margin-top:10px;">' + field('Comment (optional, the person sees it)', area('comment', '', 2), true) + '</div><div data-msg style="color:#B91C1C;font-size:13px;margin-top:6px;"></div>';
    }
    var box = el(h);
    var acts = [{ label: 'Close' }];
    var decide = function (dec, outcome, comment) {
      return Api.post('/hr/overtime/' + o.id + '/decide', { decision: dec, outcome: outcome || null, comment: comment || null })
        .then(function () { saved(dec === 'decline' ? 'Overtime declined.' : outcome === 'bank' ? 'Banked as time in lieu.' : 'Approved: it is offered on the next payslip.'); done(); },
          function (e) { if (box.querySelector('[data-msg]')) msg(box, errMsg(e)); else toast('⚠️', 'Could not save it', errMsg(e), '#DC2626'); return false; });
    };
    if (manage) {
      if (o.status === 'submitted' || (o.status === 'approved' && o.outcome === 'pay' && !o.payroll_document_id)) acts.push({ label: 'Decline', handler: function () { closeModal(); setTimeout(function () { openOtDecline(o, decide); }, 60); return false; } });
      if (canDecide && s.allow_bank !== false) acts.push({ label: 'Bank as time in lieu', primary: !s.allow_pay || o.preference === 'bank', busyLabel: 'Saving…', handler: function () { return decide('approve', 'bank', val(box, 'comment')); } });
      if (canDecide && s.allow_pay !== false) acts.push({ label: 'Approve as pay', primary: o.preference !== 'bank' || s.allow_bank === false, busyLabel: 'Saving…', handler: function () { return decide('approve', 'pay', val(box, 'comment')); } });
    } else if (o.status === 'submitted') {
      acts.push({ label: 'Withdraw', handler: function () {
        return Api.post('/hr/overtime/' + o.id + '/withdraw', {}).then(function () { saved('Overtime withdrawn.'); done(); }, function (e) { toast('⚠️', 'Could not withdraw it', errMsg(e), '#DC2626'); return false; });
      } });
    }
    modal({ title: 'Overtime: ' + o.person, body: box, actions: acts });
  }

  function openOtDecline(o, decide) {
    var box = el('<div>' + field('Why is it declined? (the person sees this)', area('comment', '', 3), true) + '<div data-msg style="color:#B91C1C;font-size:13px;margin-top:6px;"></div></div>');
    modal({ title: 'Decline overtime: ' + o.person, body: box, actions: [{ label: 'Cancel' }, { label: 'Decline overtime', primary: true, busyLabel: 'Saving…', handler: function () {
      var t = val(box, 'comment'); if (!t) { msg(box, 'Say why the overtime is declined.'); return false; }
      return decide('decline', null, t);
    } }] });
  }

  function openLogOvertime(d, forOthers, done) {
    var s = d.settings || {}, ppl = {};
    (d.people || []).forEach(function (p) { ppl[p.id] = p.name; });
    var prefs = {};
    if (s.allow_pay !== false && s.allow_bank !== false) prefs = { '': 'Either is fine', pay: 'Pay', bank: 'Time in lieu (bank it)' };
    var box = el('<div class="hr-g">' + (forOthers ? field('Person', select('user_id', opts(ppl, '', 'Choose a person')), true) : '')
      + field('Date worked', input('work_date', d.today || today(), 'date')) + field('Extra hours', input('hours', '', 'number'))
      + (Object.keys(prefs).length ? field(forOthers ? 'They would like' : 'I would like', select('preference', opts(prefs, ''))) : '')
      + field('What the extra hours were for', area('reason', '', 2), true)
      + '</div><div class="hr-sub" style="margin-top:8px;">' + (forOthers ? 'Another director or an admin can approve it, or you can, since it is not your own.' : 'Your director or agency admin approves it.') + ' Rate: <span data-no-i18n>' + esc(rateX(s.rate)) + '</span>.</div>'
      + '<div data-msg style="color:#B91C1C;font-size:13px;margin-top:6px;"></div>');
    var hi = box.querySelector('[data-f="hours"]'); if (hi) { hi.step = '0.25'; hi.min = '0.25'; hi.max = '16'; hi.inputMode = 'decimal'; }
    var di = box.querySelector('[data-f="work_date"]'); if (di) di.max = d.today || today();
    modal({ title: forOthers ? 'Log overtime for someone' : 'Log overtime', body: box, actions: [{ label: 'Cancel' }, { label: 'Send', primary: true, busyLabel: 'Sending…', handler: function () {
      var b = { work_date: val(box, 'work_date'), hours: val(box, 'hours'), reason: val(box, 'reason'), preference: val(box, 'preference') || null };
      if (forOthers) { b.user_id = +val(box, 'user_id') || null; if (!b.user_id) { msg(box, 'Choose a person.'); return false; } }
      if (!(Number(b.hours) >= 0.25)) { msg(box, 'Enter the extra hours (at least 0.25).'); return false; }
      if (!b.reason) { msg(box, 'Say what the extra hours were for.'); return false; }
      return Api.post('/hr/overtime', b).then(function () { saved(forOthers ? 'Overtime logged.' : 'Sent for approval.'); done(); }, function (e) { msg(box, errMsg(e)); return false; });
    } }] });
  }

  function openTimeSettings(done) {
    Api.get('/hr/time/settings').then(function (r) {
      var s = r.settings || {}, o = s.overtime || {}, t = s.timesheet || {}, admin = !!r.can_manage;
      var rnd = {}; (r.rounding || [0, 5, 6, 10, 15]).forEach(function (n) { rnd[n] = n ? 'To the nearest ' + n + ' minutes' : 'Off: exact clock times'; });
      var box = el('<div class="hrt-sec" style="margin-top:0;">Overtime</div><div class="hr-g">'
        + field('Overtime rate (1 = straight time, 1.5 = time and a half)', input('rate', o.rate, 'number'))
        + '<div style="grid-column:1/-1;display:grid;gap:6px;">' + check('staff_can_log', o.staff_can_log, 'Staff can log their own overtime (otherwise a director logs it for them)')
        + check('allow_pay', o.allow_pay, 'Overtime can be paid (a line on the next payslip)') + check('allow_bank', o.allow_bank, 'Overtime can be banked as time in lieu (the Overtime bank leave balance)') + '</div></div>'
        + '<div class="hrt-sec">Timesheets</div><div class="hr-g">' + field('Round clock-in and clock-out', select('rounding', opts(rnd, String(t.rounding || 0))))
        + field('Before payroll', select('export_rule', opts({ warn: 'Warn when a period is not approved', block: 'Only pay approved, locked periods' }, t.export_rule || 'warn')))
        + '<div style="grid-column:1/-1;display:grid;gap:6px;">' + check('clip_in', t.clip_in, 'Count from the shift start when someone clocks in early')
        + check('clip_out', t.clip_out, 'Count to the shift end when someone clocks out late (log the extra as overtime)') + '</div></div>'
        + '<div class="hr-sub" style="margin-top:8px;">All timesheet rules are off by default, so hours are exactly what the clock recorded. Rules change the paid hours on the timesheet and in the payroll run, never the clock-in record itself.'
        + (r.bank ? ' Banked overtime goes to the <b data-no-i18n>' + esc(r.bank.name) + '</b> leave type' + (r.bank.archived ? ' (archived: restore it in Leave types to bank again)' : '') + '.' : ' The Overtime bank leave type is made the first time overtime is banked.') + '</div>'
        + (admin ? '' : '<div class="hr-note" style="margin-top:8px;">Only an agency admin can change these.</div>')
        + '<div data-msg style="color:#B91C1C;font-size:13px;margin-top:6px;"></div>');
      var ri = box.querySelector('[data-f="rate"]'); if (ri) { ri.step = '0.25'; ri.min = '1'; ri.max = '3'; }
      if (!admin) box.querySelectorAll('[data-f]').forEach(function (i) { i.disabled = true; });
      var acts = [{ label: admin ? 'Cancel' : 'Close' }];
      if (admin) acts.push({ label: 'Save rules', primary: true, busyLabel: 'Saving…', handler: function () {
        var b = { overtime: { rate: Number(val(box, 'rate')) || 1, staff_can_log: !!val(box, 'staff_can_log'), allow_pay: !!val(box, 'allow_pay'), allow_bank: !!val(box, 'allow_bank') },
          timesheet: { rounding: +val(box, 'rounding') || 0, clip_in: !!val(box, 'clip_in'), clip_out: !!val(box, 'clip_out'), export_rule: val(box, 'export_rule') || 'warn' } };
        if (!b.overtime.allow_pay && !b.overtime.allow_bank) { msg(box, 'Allow paying overtime, banking it, or both.'); return false; }
        return Api.put('/hr/time/settings', b).then(function () { saved('Rules saved.'); done(); }, function (e) { msg(box, errMsg(e)); return false; });
      } });
      modal({ title: 'Overtime and timesheet rules', body: box, actions: acts });
    }, function (e) { toast('⚠️', 'Could not open the rules', errMsg(e), '#DC2626'); });
  }

  async function mineOvertime(host) {
    chipCss();
    var wrap = document.createElement('div'); wrap.setAttribute('data-hrs-ot', '1'); host.appendChild(wrap);
    var d;
    try { d = await Api.get('/hr/me/overtime'); } catch (e) { wrap.innerHTML = ''; return; }
    if (!wrap.isConnected) return;
    var rows = d.overtime || [];
    wrap.innerHTML = '<div class="hrt-sec">Overtime and time in lieu</div><div class="hr-bar">' + (d.can_log ? '<button type="button" class="hr-b p" data-mot="new">Log overtime</button>' : '<span class="hr-sub">Your director logs overtime for you.</span>')
      + (d.bank ? '<span class="hrt-chips" style="margin:0;"><span class="hrt-stat"><span data-no-i18n>' + esc(d.bank.name) + '</span> <span class="n" data-no-i18n>' + esc(String(d.bank.balance)) + ' h</span></span></span><a href="#time-off" class="hr-sub" style="font-weight:700;">Book time in lieu</a>' : '') + '</div>'
      + '<div class="kt-card">' + (rows.length ? '<table data-hrs="myovertime"><thead><tr><th>Date</th><th style="text-align:right;">Hours</th><th>What for</th><th>Status</th><th></th></tr></thead><tbody>' + rows.map(function (o) {
        return '<tr><td style="white-space:nowrap;">' + esc(day(o.work_date)) + '</td><td style="text-align:right;white-space:nowrap;" data-no-i18n><b>' + esc(hh(o.minutes)) + '</b></td><td data-no-i18n>' + esc(o.reason || '') + '</td>'
          + '<td>' + otPill(o) + (o.status === 'declined' && o.decision_comment ? '<div class="hr-sub" data-no-i18n>' + esc(o.decision_comment) + '</div>' : '') + '</td>'
          + '<td style="text-align:center;"><button type="button" data-mot="open" data-id="' + o.id + '">View overtime</button></td></tr>';
      }).join('') + '</tbody></table>' : '<div style="padding:16px;" class="hr-sub">You have not logged any overtime.</div>') + '</div>';
    var reload = function () { if (wrap.isConnected) { wrap.remove(); mineOvertime(host); } };
    wrap.querySelectorAll('[data-mot]').forEach(function (b) {
      b.addEventListener('click', function () {
        if (b.getAttribute('data-mot') === 'new') openLogOvertime({ settings: d.settings, today: d.today }, false, reload);
        else { var o = rows.filter(function (x) { return x.id === +b.getAttribute('data-id'); })[0]; if (o) openOvertime(o, false, d.settings || {}, reload); }
      });
    });
  }

  /* HR > Timesheets: one centre, one period. */
  var TS = { centre: null, from: null, to: null };
  function tsPeriods(t) {
    var mon = addDays(t, -((new Date(Date.UTC(+t.slice(0, 4), +t.slice(5, 7) - 1, +t.slice(8, 10))).getUTCDay() + 6) % 7));
    var m0 = t.slice(0, 8) + '01', pm = addDays(m0, -1);
    return [['Last week', addDays(mon, -7), addDays(mon, -1)], ['Last 2 weeks', addDays(mon, -14), addDays(mon, -1)],
      ['Last month', pm.slice(0, 8) + '01', pm], ['This month so far', m0, addDays(t, -1) < m0 ? m0 : addDays(t, -1)]];
  }
  async function drawTimesheets(host) {
    chipCss();
    var q = '/hr/timesheets?' + (TS.centre ? 'centre_id=' + TS.centre + '&' : '') + (TS.from ? 'from=' + TS.from + '&to=' + TS.to : '');
    var d;
    try { d = await Api.get(q); } catch (e) { host.innerHTML = '<div class="hr-note">' + esc(errMsg(e)) + '</div>'; return; }
    if (!host.isConnected) return;
    if (!(d.centres || []).length) { host.innerHTML = '<div class="hr-note">No centres to show.</div>'; return; }
    TS.centre = d.centre_id; TS.from = d.from; TS.to = d.to;
    var rows = d.rows || [], tot = d.totals || {}, lock = (d.locks || [])[0];
    var why = !d.ended ? 'You can approve this period once it has ended.' : lock ? '' : tot.open ? tot.open + ' clock-in' + (tot.open === 1 ? ' has' : 's have') + ' no clock-out. Add the clock-out first (Timesheets screen, the person\'s row).' : d.own_hours ? 'Your own hours are on this timesheet: another director or an agency admin approves it.' : '';
    var r = d.rules || {}, rulesTxt = [];
    if (r.rounding) rulesTxt.push('rounded to ' + r.rounding + ' min');
    if (r.clip_in) rulesTxt.push('counted from the shift start');
    if (r.clip_out) rulesTxt.push('counted to the shift end');
    var h = '<div class="hr-note">Scheduled shifts against clock-ins for one centre and period. Paid hours are what payroll uses' + (rulesTxt.length ? ' (your rules: ' + esc(rulesTxt.join(', ')) + ')' : ' (no rounding or shift rules: exactly the clock)') + '. Approve a period once it has ended: that locks it, and clock-ins on those days can no longer be changed until an agency admin unlocks it.</div>'
      + '<div class="hr-bar">' + (d.centres.length > 1 ? '<select class="hr-i" data-ts="centre" style="max-width:220px;">' + d.centres.map(function (c) { return '<option value="' + c.id + '"' + (c.id === d.centre_id ? ' selected' : '') + ' data-no-i18n>' + esc(c.name) + '</option>'; }).join('') + '</select>' : '<b data-no-i18n>' + esc(d.centres[0].name) + '</b>')
      + '<input class="hr-i" type="date" data-ts="from" value="' + esc(d.from) + '"><span class="hr-sub">to</span><input class="hr-i" type="date" data-ts="to" value="' + esc(d.to) + '">'
      + tsPeriods(d.today).map(function (p, i) { return '<button type="button" class="hr-b" data-tsp="' + i + '">' + esc(p[0]) + '</button>'; }).join('')
      + '<span style="flex:1"></span><button type="button" class="hr-b" data-ts="settings">Rules</button></div>'
      + '<div class="hrt-chips"><span class="hrt-stat">People <span class="n">' + (tot.people || 0) + '</span></span><span class="hrt-stat">Scheduled <span class="n" data-no-i18n>' + esc(hh(tot.scheduled_min)) + '</span></span>'
      + '<span class="hrt-stat">Worked <span class="n" data-no-i18n>' + esc(hh(tot.worked_min)) + '</span></span><span class="hrt-stat">Paid <span class="n" data-no-i18n>' + esc(hh(tot.paid_min)) + '</span></span>'
      + '<span class="hrt-stat' + (tot.open ? ' warn' : '') + '">No clock-out <span class="n">' + (tot.open || 0) + '</span></span><span class="hrt-stat' + (tot.no_show ? ' warn' : '') + '">Shift with no clock-in <span class="n">' + (tot.no_show || 0) + '</span></span>'
      + '<span class="hrt-stat">Hours with no shift <span class="n">' + (tot.unscheduled || 0) + '</span></span></div>';
    if (lock) {
      h += '<div class="hr-note" style="background:#ECFDF5;border-color:#A7F3D0;color:#065F46;display:flex;gap:10px;align-items:center;flex-wrap:wrap;">🔒 <span>Approved and locked: <b data-no-i18n>' + esc(day(lock.period_start)) + ' – ' + esc(day(lock.period_end)) + '</b>, by <span data-no-i18n>' + esc(lock.approved_by) + '</span> on <span data-no-i18n>' + esc(day(lock.approved_at)) + '</span>.</span>'
        + (d.is_admin ? '<span style="flex:1"></span><button type="button" class="hr-b" data-ts="unlock" data-id="' + lock.id + '">Unlock</button>' : '') + '</div>';
    } else {
      h += '<div class="hr-bar"><button type="button" class="hr-b p" data-ts="approve"' + (d.can_approve ? '' : ' disabled') + '>Approve and lock this period</button>' + (why ? '<span class="hr-sub">' + esc(why) + '</span>' : '') + '</div>';
    }
    h += '<div class="kt-card">' + (rows.length ? '<table data-hrs="timesheets"><thead><tr><th>Person</th><th style="text-align:right;">Scheduled</th><th style="text-align:right;">Worked</th><th style="text-align:right;">Paid</th><th style="text-align:right;">Paid vs scheduled</th><th>Flags</th><th style="text-align:right;">Approved overtime</th><th></th></tr></thead><tbody>'
      + rows.map(function (p) {
        var fl = [];
        if (p.open) fl.push(pill('#FEE2E2', '#991B1B', p.open + ' no clock-out'));
        if (p.no_show) fl.push(pill('#FEF3C7', '#92400E', p.no_show + ' shift with no clock-in'));
        if (p.unscheduled) fl.push(pill('#E0F2FE', '#075985', p.unscheduled + ' with no shift'));
        return '<tr><td><b data-no-i18n>' + esc(p.name) + '</b></td><td style="text-align:right;" data-no-i18n>' + esc(hh(p.scheduled_min)) + '</td><td style="text-align:right;" data-no-i18n>' + esc(hh(p.worked_min)) + '</td>'
          + '<td style="text-align:right;" data-no-i18n><b>' + esc(hh(p.paid_min)) + '</b></td><td style="text-align:right;' + (Math.abs(p.variance_min) >= 30 ? 'color:#B45309;font-weight:700;' : '') + '" data-no-i18n>' + esc(signedH(p.variance_min)) + '</td>'
          + '<td>' + (fl.join(' ') || '<span class="hr-sub">—</span>') + '</td><td style="text-align:right;" data-no-i18n>' + (p.overtime_min ? esc(hh(p.overtime_min)) : '<span class="hr-sub">—</span>') + '</td>'
          + '<td style="text-align:center;white-space:nowrap;"><button type="button" data-ts="person" data-id="' + p.user_id + '">View clock-ins</button></td></tr>';
      }).join('') + '</tbody></table>' : '<div style="padding:16px;" class="hr-sub">No shifts or clock-ins at this centre in these dates.</div>') + '</div>';
    var hist = d.history || [];
    if (hist.length) {
      h += '<div class="hrt-sec">Approved periods</div><div class="kt-card"><table data-hrs="ts-locks"><thead><tr><th>Period</th><th>Approved by</th><th>Status</th><th>Note</th></tr></thead><tbody>' + hist.map(function (l) {
        return '<tr><td style="white-space:nowrap;" data-no-i18n>' + esc(day(l.period_start)) + ' – ' + esc(day(l.period_end)) + '</td><td><span data-no-i18n>' + esc(l.approved_by) + '</span><div class="hr-sub" data-no-i18n>' + esc(day(l.approved_at)) + '</div></td>'
          + '<td>' + (l.status === 'locked' ? pill('#DCFCE7', '#166534', 'Locked') : pill('#F1F5F9', '#475569', 'Unlocked')) + (l.status !== 'locked' && l.unlocked_by ? '<div class="hr-sub"><span data-no-i18n>' + esc(l.unlocked_by) + '</span> · <span data-no-i18n>' + esc(day(l.unlocked_at)) + '</span></div>' : '') + '</td>'
          + '<td data-no-i18n>' + esc(l.status !== 'locked' ? (l.unlock_reason || '') : (l.note || '')) + '</td></tr>';
      }).join('') + '</tbody></table></div>';
    }
    host.innerHTML = h;
    var go = function () { if (host.isConnected) drawTimesheets(host); };
    host.querySelectorAll('[data-tsp]').forEach(function (b) { b.addEventListener('click', function () { var p = tsPeriods(d.today)[+b.getAttribute('data-tsp')]; TS.from = p[1]; TS.to = p[2]; go(); }); });
    host.querySelectorAll('[data-ts]').forEach(function (b) {
      var a = b.getAttribute('data-ts');
      if (a === 'centre') { b.addEventListener('change', function () { TS.centre = +b.value; go(); }); return; }
      if (a === 'from' || a === 'to') { b.addEventListener('change', function () { if (b.value) { TS[a] = b.value; if (TS.from > TS.to) { var x = TS.from; TS.from = TS.to; TS.to = x; } go(); } }); return; }
      b.addEventListener('click', function () {
        if (a === 'settings') openTimeSettings(go);
        else if (a === 'person') openTsPerson(d, +b.getAttribute('data-id'));
        else if (a === 'approve') openTsApprove(d, go);
        else if (a === 'unlock') openTsUnlock(+b.getAttribute('data-id'), go);
      });
    });
  }

  function openTsPerson(d, uid) {
    Api.get('/hr/timesheets/person?centre_id=' + d.centre_id + '&user_id=' + uid + '&from=' + d.from + '&to=' + d.to).then(function (r) {
      var p = r.person || {}, rules = r.rules_on;
      var box = el('<div class="hr-sub" style="margin-bottom:8px;">Scheduled <b data-no-i18n>' + esc(hh(p.scheduled_min)) + '</b> · worked <b data-no-i18n>' + esc(hh(p.worked_min)) + '</b> · paid <b data-no-i18n>' + esc(hh(p.paid_min)) + '</b></div>'
        + '<div>' + ((r.entries || []).length ? (r.entries || []).map(function (e) {
          var tag = e.no_show ? pill('#FEF3C7', '#92400E', 'No clock-in') : e.open ? pill('#FEE2E2', '#991B1B', 'No clock-out') : !e.shift ? pill('#E0F2FE', '#075985', 'No shift') : '';
          return '<div style="display:flex;justify-content:space-between;gap:10px;padding:7px 0;border-bottom:1px solid #F1F5F9;font-size:13px;"><div><b data-no-i18n>' + esc(wday(e.date)) + '</b>'
            + (e.shift ? '<div class="hr-sub">Shift <span data-no-i18n>' + esc(e.shift) + (e.room ? ' · ' + esc(e.room) : '') + '</span></div>' : '')
            + (e.no_show ? '' : '<div class="hr-sub">Clocked <span data-no-i18n>' + esc(e.in) + ' – ' + esc(e.out || '…') + '</span>' + (e.auto ? ' · automatic clock-out' : '') + (e.corrected ? ' · corrected' : '') + '</div>')
            + (rules && !e.no_show && !e.open && (e.paid_in !== e.in || e.paid_out !== e.out) ? '<div class="hr-sub">Paid as <span data-no-i18n>' + esc(e.paid_in) + ' – ' + esc(e.paid_out) + '</span></div>' : '') + '</div>'
            + '<div style="text-align:right;white-space:nowrap;">' + (e.no_show ? '' : '<b data-no-i18n>' + esc(hh(e.paid_min)) + '</b>' + (e.paid_min !== e.raw_min ? '<div class="hr-sub" data-no-i18n>' + esc(hh(e.raw_min)) + ' clocked</div>' : '')) + (tag ? '<div>' + tag + '</div>' : '') + '</div></div>';
        }).join('') : '<div class="hr-sub">Nothing in these dates.</div>') + '</div>'
        + (r.locked ? '<div class="hr-sub" style="margin-top:8px;">🔒 This period is approved and locked.</div>' : '<div class="hr-sub" style="margin-top:8px;">To correct a clock-in, use the Timesheets screen (Staff &gt; Timesheets), the person\'s row.</div>'));
      modal({ title: 'Clock-ins: ' + (p.name || ''), body: box, actions: [{ label: 'Close' }] });
    }, function (e) { toast('⚠️', 'Could not open it', errMsg(e), '#DC2626'); });
  }

  function openTsApprove(d, done) {
    var c = (d.centres || []).filter(function (x) { return x.id === d.centre_id; })[0] || {};
    var t = d.totals || {};
    var box = el('<div class="hr-sub" style="margin-bottom:8px;">You are approving <b data-no-i18n>' + esc(c.name || '') + '</b>, <b data-no-i18n>' + esc(day(d.from)) + ' – ' + esc(day(d.to)) + '</b>: ' + (t.people || 0) + ' people, <b data-no-i18n>' + esc(hh(t.paid_min)) + '</b> paid.'
      + (t.no_show ? ' ' + t.no_show + ' scheduled shift' + (t.no_show === 1 ? ' has' : 's have') + ' no clock-in (no approved leave either): check them first if they should be paid.' : '') + '</div>'
      + '<div class="hr-sub" style="margin-bottom:8px;">Once locked, clock-ins on these days cannot be added, corrected or removed until an agency admin unlocks the period.</div>'
      + field('Note (optional)', area('note', '', 2), true) + '<div data-msg style="color:#B91C1C;font-size:13px;margin-top:6px;"></div>');
    modal({ title: 'Approve and lock timesheet', body: box, actions: [{ label: 'Cancel' }, { label: 'Approve and lock', primary: true, busyLabel: 'Saving…', handler: function () {
      return Api.post('/hr/timesheets/approve', { centre_id: d.centre_id, from: d.from, to: d.to, note: val(box, 'note') }).then(function () { saved('Timesheet approved and locked.'); done(); }, function (e) { msg(box, errMsg(e)); return false; });
    } }] });
  }

  function openTsUnlock(id, done) {
    var box = el('<div><div class="hr-sub" style="margin-bottom:8px;">Unlocking lets clock-ins in this period be changed again. It is recorded with your name and reason, and the period needs approving again before payroll.</div>'
      + field('Why is it unlocked?', area('reason', '', 3), true) + '<div data-msg style="color:#B91C1C;font-size:13px;margin-top:6px;"></div></div>');
    modal({ title: 'Unlock timesheet', body: box, actions: [{ label: 'Cancel' }, { label: 'Unlock', primary: true, busyLabel: 'Saving…', handler: function () {
      var t = val(box, 'reason'); if (!t) { msg(box, 'Say why the timesheet is unlocked.'); return false; }
      return Api.post('/hr/timesheets/locks/' + id + '/unlock', { reason: t }).then(function () { saved('Timesheet unlocked.'); done(); }, function (e) { msg(box, errMsg(e)); return false; });
    } }] });
  }

  /* ── Phase 4 batch 3 (2026-11-27): HR > Open shifts (post, confirm who takes it) and My HR "Open shifts you can pick up".
     The board is ratio-aware: an open shift that covers a ratio shortfall in its room says so, and the week's shortfalls
     can be posted straight from the list. Shift notices (one per person per batch of rota changes) are set here too. */
  var OPS = { centre: null, week: null };
  var OP_ST = { open: ['#FEF3C7', '#92400E', 'Open'], filled: ['#DCFCE7', '#166534', 'Filled'], cancelled: ['#F1F5F9', '#475569', 'Taken off'] };
  var CL_PILL = { pending: ['#E0F2FE', '#075985', 'Waiting'], approved: ['#DCFCE7', '#166534', 'Confirmed'], declined: ['#FEE2E2', '#991B1B', 'Not this time'], withdrawn: ['#F1F5F9', '#475569', 'Withdrawn'] };
  function opPill(s) { var p = OP_ST[s] || OP_ST.open; return pill(p[0], p[1], p[2]); }
  function clPill(s) { var p = CL_PILL[s] || CL_PILL.pending; return pill(p[0], p[1], p[2]); }
  function roleTxt(r) { return { lead: 'Lead', support: 'Support', floater: 'Floater' }[r] || ''; }

  async function drawOpenShifts(host) {
    chipCss();
    var q = [];
    if (OPS.centre) q.push('centre_id=' + OPS.centre);
    if (OPS.week) q.push('week=' + OPS.week);
    var d;
    try { d = await Api.get('/hr/open-shifts' + (q.length ? '?' + q.join('&') : '')); } catch (e) { host.innerHTML = '<div class="hr-note">' + esc(errMsg(e)) + '</div>'; return; }
    if (!host.isConnected) return;
    OPS.centre = d.centre_id; OPS.week = d.week;
    var rows = d.open_shifts || [], gaps = d.ratio_gaps || [];
    var waiting = rows.filter(function (r) { return r.status === 'open' && (r.claims || []).some(function (c) { return c.status === 'pending'; }); }).length;
    var h = '<div class="hr-note"><span>Shifts nobody is on yet. Staff of the centre who are free, not already working then and hold any certificate you ask for see them in My HR and ask for one; a director or admin confirms who takes it and it goes on the rota. Nobody confirms their own.</span></div>'
      + '<div class="hrt-chips">'
      + ((d.centres || []).length > 1 ? '<select class="hr-i" data-op="centre" style="height:30px;max-width:220px;">' + d.centres.map(function (c) { return '<option value="' + c.id + '"' + (c.id === d.centre_id ? ' selected' : '') + ' data-no-i18n>' + esc(c.name) + '</option>'; }).join('') + '</select>' : '')
      + '<button type="button" class="hr-b" data-op="prev" aria-label="Previous week">‹</button><b data-no-i18n style="font-size:13px;white-space:nowrap;">' + esc(wday(d.week) + ' – ' + wday(d.week_end)) + '</b>'
      + '<button type="button" class="hr-b" data-op="next" aria-label="Next week">›</button><button type="button" class="hr-b" data-op="now">This week</button>'
      + '<span style="flex:1"></span>'
      + '<span class="hrt-stat' + (waiting ? ' warn' : '') + '">Waiting for you <span class="n">' + waiting + '</span></span>'
      + '<span class="hrt-stat' + (gaps.length ? ' warn' : '') + '">Ratio shortfalls <span class="n">' + gaps.length + '</span></span>'
      + '<button type="button" class="hr-b p" data-op="post" data-kt-iconized="1">Post an open shift</button>'
      + '<button type="button" class="hr-b" data-op="notices">Shift notices</button></div>';
    h += '<div class="hrt-sec">Open shifts</div><div class="kt-card">' + (rows.length ? '<table data-hrs="openshifts"><thead><tr><th>Day</th><th>Time</th><th>Room</th><th>Needs</th><th>Asked</th><th>Status</th><th></th></tr></thead><tbody>' + rows.map(function (r) {
      var pend = (r.claims || []).filter(function (c) { return c.status === 'pending'; }).length;
      return '<tr' + (r.status === 'open' && pend ? ' style="background:#F0F9FF;"' : '') + '><td style="white-space:nowrap;" data-no-i18n><b>' + esc(wday(r.date)) + '</b></td><td style="white-space:nowrap;">' + span(r) + '</td>'
        + '<td><span data-no-i18n>' + esc(r.room) + '</span>' + (r.covers ? '<div style="color:#B45309;font-size:12px;">⚠️ <span>Covers a ratio shortfall</span></div>' : '') + '</td>'
        + '<td>' + esc(roleTxt(r.role)) + (r.cert ? '<div class="hr-sub" data-no-i18n>' + esc(r.cert) + '</div>' : '') + '</td>'
        + '<td>' + (r.status === 'filled' ? '<span data-no-i18n>' + esc(r.filled_by || '') + '</span>' : (pend ? '<b data-no-i18n>' + pend + '</b> <span>waiting</span>' : '<span class="hr-sub">Nobody yet</span>') + (r.status === 'open' && r.eligible != null ? '<div class="hr-sub"><span data-no-i18n>' + r.eligible + '</span> <span>can take it</span></div>' : '')) + '</td>'
        + '<td>' + opPill(r.status) + '</td><td style="text-align:center;"><button type="button" data-op="open" data-id="' + r.id + '">View open shift</button></td></tr>';
    }).join('') + '</tbody></table>' : '<div style="padding:14px;" class="hr-sub">No open shifts this week.' + (d.next_open ? ' <button type="button" class="hr-b" data-op="next-open" data-kt-iconized="1" style="margin-left:6px;">Next one: <span data-no-i18n>' + esc(wday(d.next_open)) + '</span> ›</button>' : '') + '</div>') + '</div>';
    h += '<div class="hrt-sec">Ratio shortfalls this week</div><div class="kt-card">' + (gaps.length ? '<table data-hrs="opgaps"><thead><tr><th>Day</th><th>Room</th><th>Time</th><th>Children expected</th><th>Educators planned</th><th>Needed</th><th></th></tr></thead><tbody>' + gaps.map(function (g, i) {
      return '<tr style="background:#FFFBEB;"><td style="white-space:nowrap;" data-no-i18n>' + esc(wday(g.date)) + '</td><td data-no-i18n>' + esc(g.room) + '</td><td style="white-space:nowrap;">' + span({ start: g.from, end: g.to }) + '</td>'
        + '<td data-no-i18n>' + g.children + '</td><td data-no-i18n>' + g.planned + '</td><td data-no-i18n><b style="color:#B45309;">' + g.required + '</b>' + (g.posted ? '<div class="hr-sub">Open shift posted</div>' : '') + '</td>'
        + '<td style="text-align:center;"><button type="button" data-op="gap" data-i="' + i + '">Post an open shift</button></td></tr>';
    }).join('') + '</tbody></table>' : '<div style="padding:14px;" class="hr-sub">Every room has enough educators planned for the children expected.</div>') + '</div>';
    host.innerHTML = h;
    var go = function () { if (host.isConnected) drawOpenShifts(host); };
    host.querySelectorAll('[data-op]').forEach(function (b) {
      var a = b.getAttribute('data-op');
      if (a === 'centre') { b.addEventListener('change', function () { OPS.centre = +b.value; go(); }); return; }
      b.addEventListener('click', function () {
        if (a === 'next-open') { OPS.week = d.next_open; go(); }
        else if (a === 'prev') { OPS.week = addDays(d.week, -7); go(); }
        else if (a === 'next') { OPS.week = addDays(d.week, 7); go(); }
        else if (a === 'now') { OPS.week = null; go(); }
        else if (a === 'post') openPostOpen(d, {}, go);
        else if (a === 'gap') { var g = gaps[+b.getAttribute('data-i')]; if (g) openPostOpen(d, { room_id: g.room_id, date: g.date, start: g.from, end: g.to }, go); }
        else if (a === 'notices') openNoticeSettings(d, go);
        else if (a === 'open') { var r = rows.filter(function (x) { return x.id === +b.getAttribute('data-id'); })[0]; if (r) openOpenShift(r, go); }
      });
    });
    var m = /[?&]open=(\d+)/.exec(location.hash || '');
    if (m) { var r0 = rows.filter(function (x) { return x.id === +m[1]; })[0]; if (r0) openOpenShift(r0, go); }
  }

  function openPostOpen(d, pre, done) {
    var rooms = {}; (d.rooms || []).forEach(function (r) { rooms[r.id] = r.name; });
    var certs = d.certs || {};
    var date0 = pre.date || (addDays(d.today, 1) > d.week ? addDays(d.today, 1) : d.week);
    var box = el('<div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;">'
      + field('Room', select('room_id', opts(rooms, pre.room_id || ((d.rooms || [])[0] || {}).id))) + field('Role', select('role', opts(d.roles || {}, 'support')))
      + field('Day', input('date', date0, 'date')) + field('Repeat on the next days (optional)', select('repeat', opts({ 0: 'Just this day', 1: '+1 day', 2: '+2 days', 3: '+3 days', 4: '+4 days', 6: '+6 days', 13: '+13 days' }, '0')))
      + field('From', input('start', pre.start || d.open_time || '07:00', 'time')) + field('To', input('end', pre.end || d.close_time || '15:00', 'time'))
      + field('Must hold (optional)', select('cert_type', opts(certs, '', 'No certificate needed')), true)
      + field('Note for staff (optional)', input('note', ''), true) + '</div>'
      + '<div class="hr-sub" style="margin-top:8px;">Everyone at the centre who can take it is told once (bell, phone). Days the centre is closed are your call: check the calendar first.</div><div data-msg style="color:#B91C1C;font-size:13px;margin-top:6px;"></div>');
    modal({ title: 'Post an open shift', body: box, actions: [{ label: 'Cancel' }, { label: 'Post it', primary: true, busyLabel: 'Posting…', handler: function () {
      var dt = val(box, 'date'), n = +(val(box, 'repeat') || 0), s = (val(box, 'start') || '').slice(0, 5), e = (val(box, 'end') || '').slice(0, 5);
      if (!dt) { msg(box, 'Choose the day.'); return false; }
      if (!s || !e || s >= e) { msg(box, 'The shift ends after it starts.'); return false; }
      var dates = []; for (var i = 0; i <= n; i++) dates.push(addDays(dt, i));
      return Api.post('/hr/open-shifts', { centre_id: d.centre_id, room_id: +val(box, 'room_id'), dates: dates, start: s, end: e, role: val(box, 'role'), cert_type: val(box, 'cert_type'), note: val(box, 'note') })
        .then(function (r) { saved(dates.length === 1 ? 'Open shift posted.' : dates.length + ' open shifts posted.'); done(); }, function (er) { msg(box, errMsg(er)); return false; });
    } }] });
  }

  function openOpenShift(r, done) {
    var kv = function (k, v) { return '<div style="display:flex;justify-content:space-between;gap:10px;padding:5px 0;border-bottom:1px solid #F1F5F9;font-size:13.5px;"><span style="color:#64748B;">' + esc(k) + '</span><b style="text-align:right;">' + v + '</b></div>'; };
    var h = kv('Day', '<span data-no-i18n>' + esc(wday(r.date)) + '</span>') + kv('Time', span(r)) + kv('Room', '<span data-no-i18n>' + esc(r.room) + '</span>')
      + kv('Role', esc(roleTxt(r.role))) + (r.cert ? kv('Must hold', '<span data-no-i18n>' + esc(r.cert) + '</span>') : '') + kv('Status', opPill(r.status));
    if (r.note) h += kv('Note', '<span data-no-i18n>' + esc(r.note) + '</span>');
    if (r.covers) h += '<div style="margin-top:10px;padding:8px 10px;border-radius:8px;background:#FFFBEB;color:#92400E;font-size:13px;">⚠️ <span>Covers a ratio shortfall:</span> <span data-no-i18n>' + esc(r.covers.planned + ' / ' + r.covers.required) + '</span> <span>educators planned for the children expected.</span></div>';
    var cl = r.claims || [];
    h += '<div style="margin:14px 0 6px;font-weight:800;font-size:13px;">Who asked</div>';
    h += cl.length ? '<div class="hrt-task-list">' + cl.map(function (c) {
      var can = r.status === 'open' && c.status === 'pending' && !c.mine;
      return '<div style="display:flex;gap:8px;align-items:center;justify-content:space-between;padding:7px 0;border-bottom:1px solid #F1F5F9;"><div><b data-no-i18n>' + esc(c.person) + '</b> ' + clPill(c.status)
        + (c.note ? '<div class="hr-sub" data-no-i18n>' + esc(c.note) + '</div>' : '') + (c.problem ? '<div style="color:#B45309;font-size:12px;">⚠️ ' + esc(c.problem) + '</div>' : '') + (c.mine && c.status === 'pending' ? '<div class="hr-sub">Your own request: another director or an admin confirms it.</div>' : '') + '</div>'
        + (can ? '<div style="display:flex;gap:6px;flex:0 0 auto;"><button type="button" class="hr-b" style="height:28px;" data-cl-no="' + c.id + '">Decline</button>' + (c.problem ? '' : '<button type="button" class="hr-b p" style="height:28px;" data-cl-yes="' + c.id + '">Confirm</button>') + '</div>' : '') + '</div>';
    }).join('') + '</div>' : '<div class="hr-sub">Nobody has asked yet.</div>';
    h += '<div data-msg style="color:#B91C1C;font-size:13px;margin-top:6px;"></div>';
    var box = el(h);
    box.querySelectorAll('[data-cl-yes]').forEach(function (b) {
      b.addEventListener('click', function () {
        b.disabled = true;
        Api.post('/hr/open-shifts/' + r.id + '/confirm', { claim_id: +b.getAttribute('data-cl-yes') }).then(function () { saved('Confirmed. The shift is on the rota.'); closeModal(); done(); }, function (e) { b.disabled = false; msg(box, errMsg(e)); });
      });
    });
    box.querySelectorAll('[data-cl-no]').forEach(function (b) {
      b.addEventListener('click', function () {
        b.disabled = true;
        Api.post('/hr/open-shift-claims/' + b.getAttribute('data-cl-no') + '/decline', {}).then(function () { saved('Request declined.'); closeModal(); done(); }, function (e) { b.disabled = false; msg(box, errMsg(e)); });
      });
    });
    var acts = [{ label: 'Close' }];
    if (r.status === 'open') acts.push({ label: 'Take it off the board', handler: function () {
      return Api.post('/hr/open-shifts/' + r.id + '/cancel', {}).then(function () { saved('Taken off the board.'); done(); }, function (e) { msg(box, errMsg(e)); return false; });
    } });
    modal({ title: 'Open shift: ' + wday(r.date), body: box, actions: acts });
  }

  function openNoticeSettings(d, done) {
    var s = d.notice_settings || {}, edit = !!d.can_manage;
    var q = {}; (d.quiet_choices || [5]).forEach(function (n) { q[n] = n + ' minutes'; });
    var box = el('<div class="hr-sub" style="margin-bottom:10px;">Staff hear when their shifts are added, changed or cancelled: one notice (bell, phone and email) per person for each batch of changes, sent once the rota has been quiet for a few minutes. Each person can switch it off in Settings &gt; Notifications &gt; My shifts.</div>'
      + '<div style="display:grid;gap:10px;">' + check('enabled', s.enabled, 'Send shift notices') + check('email', s.email, 'Also by email')
      + check('nightly_fill', s.nightly_fill, 'Include shifts the nightly rota fill adds (it adds a day at a time)')
      + field('Wait for the rota to be quiet for', select('quiet_minutes', opts(q, String(s.quiet_minutes || 5)))) + '</div>'
      + (edit ? '' : '<div class="hr-sub" style="margin-top:8px;">Only an agency admin can change these.</div>') + '<div data-msg style="color:#B91C1C;font-size:13px;margin-top:6px;"></div>');
    if (!edit) box.querySelectorAll('[data-f]').forEach(function (i) { i.disabled = true; });
    var acts = [{ label: edit ? 'Cancel' : 'Close' }];
    if (edit) acts.push({ label: 'Save', primary: true, busyLabel: 'Saving…', handler: function () {
      return Api.put('/hr/shift-notices/settings', { enabled: !!val(box, 'enabled'), email: !!val(box, 'email'), nightly_fill: !!val(box, 'nightly_fill'), quiet_minutes: +val(box, 'quiet_minutes') })
        .then(function () { saved('Shift notice settings saved.'); done(); }, function (e) { msg(box, errMsg(e)); return false; });
    } });
    modal({ title: 'Shift notices', body: box, actions: acts });
  }

  async function mineOpen(host) {
    chipCss();
    var wrap = document.createElement('div'); wrap.setAttribute('data-hrs-open', '1'); host.appendChild(wrap);
    var d;
    try { d = await Api.get('/hr/me/open-shifts'); } catch (e) { wrap.innerHTML = ''; return; }
    if (!wrap.isConnected) return;
    var rows = d.open_shifts || [], hist = d.history || [];
    if (!rows.length && !hist.length) { wrap.innerHTML = ''; return; }
    var reload = function () { if (wrap.isConnected) { wrap.remove(); mineOpen(host); } };
    var h = '<div class="hrt-sec">Open shifts you can pick up</div><div class="kt-card">' + (rows.length ? '<table data-hrs="myopen"><thead><tr><th>Day</th><th>Time</th><th>Where</th><th>Status</th><th></th></tr></thead><tbody>' + rows.map(function (r) {
      var st = r.my_claim ? clPill(r.my_claim.status) : (r.problem ? '<span style="color:#B45309;font-size:12px;">' + esc(r.problem) + '</span>' : pill('#FEF3C7', '#92400E', 'Open'));
      return '<tr><td style="white-space:nowrap;" data-no-i18n><b>' + esc(wday(r.date)) + '</b></td><td style="white-space:nowrap;">' + span(r) + '</td><td><span data-no-i18n>' + esc(r.room) + '</span>'
        + '<div class="hr-sub" data-no-i18n>' + esc(r.centre) + '</div></td><td>' + st + '</td><td style="text-align:center;"><button type="button" data-mop="open" data-id="' + r.id + '">View open shift</button></td></tr>';
    }).join('') + '</tbody></table>' : '<div style="padding:14px;" class="hr-sub">No open shifts right now.</div>') + '</div>';
    if (hist.length) h += '<div class="hr-sub" style="margin-top:6px;">' + hist.map(function (x) { return '<span data-no-i18n>' + esc(wday(x.date)) + '</span> ' + span(x) + ': ' + clPill(x.claim_status); }).join(' · ') + '</div>';
    wrap.innerHTML = h;
    wrap.querySelectorAll('[data-mop]').forEach(function (b) {
      b.addEventListener('click', function () { var r = rows.filter(function (x) { return x.id === +b.getAttribute('data-id'); })[0]; if (r) openMyOpen(r, reload); });
    });
  }

  function openMyOpen(r, done) {
    var kv = function (k, v) { return '<div style="display:flex;justify-content:space-between;gap:10px;padding:5px 0;border-bottom:1px solid #F1F5F9;font-size:13.5px;"><span style="color:#64748B;">' + esc(k) + '</span><b style="text-align:right;">' + v + '</b></div>'; };
    var c = r.my_claim;
    var h = kv('Day', '<span data-no-i18n>' + esc(wday(r.date)) + '</span>') + kv('Time', span(r)) + kv('Where', '<span data-no-i18n>' + esc(r.room + ', ' + r.centre) + '</span>')
      + kv('Role', esc(roleTxt(r.role))) + (r.cert ? kv('Must hold', '<span data-no-i18n>' + esc(r.cert) + '</span>') : '') + (r.note ? kv('Note', '<span data-no-i18n>' + esc(r.note) + '</span>') : '')
      + (c ? kv('Your request', clPill(c.status)) : '');
    if (r.problem && !c) h += '<div style="margin-top:10px;padding:8px 10px;border-radius:8px;background:#FFFBEB;color:#92400E;font-size:13px;">' + esc(r.problem) + '.</div>';
    if (!c && !r.problem) h += '<div style="margin-top:10px;">' + field('Note for your director (optional)', input('note', ''), true) + '</div><div class="hr-sub" style="margin-top:6px;">A director confirms who takes it. You are told either way.</div>';
    h += '<div data-msg style="color:#B91C1C;font-size:13px;margin-top:6px;"></div>';
    var box = el(h);
    var acts = [{ label: 'Close' }];
    if (!c && !r.problem) acts.push({ label: 'Ask for this shift', primary: true, busyLabel: 'Sending…', handler: function () {
      return Api.post('/hr/open-shifts/' + r.id + '/claim', { note: val(box, 'note') }).then(function () { saved('Asked. A director confirms who takes it.'); done(); }, function (e) { msg(box, errMsg(e)); return false; });
    } });
    if (c && c.status === 'pending') acts.push({ label: 'Withdraw my request', handler: function () {
      return Api.post('/hr/open-shifts/' + r.id + '/withdraw', {}).then(function () { saved('Request withdrawn.'); done(); }, function (e) { msg(box, errMsg(e)); return false; });
    } });
    if (c && c.status === 'withdrawn' && !r.problem) acts.push({ label: 'Ask again', primary: true, handler: function () {
      return Api.post('/hr/open-shifts/' + r.id + '/claim', {}).then(function () { saved('Asked. A director confirms who takes it.'); done(); }, function (e) { msg(box, errMsg(e)); return false; });
    } });
    modal({ title: 'Open shift: ' + wday(r.date), body: box, actions: acts });
  }

  window.KTHrSafety = {
    tabs: [['injuries', 'Injuries'], ['claims', 'Claims'], ['overtime', 'Overtime'], ['timesheets', 'Timesheets'], ['rota', 'Rota'], ['openshifts', 'Open shifts'], ['letters', 'Letters']],
    has: function (tab) { return ['injuries', 'claims', 'overtime', 'timesheets', 'rota', 'openshifts', 'letters'].indexOf(tab) >= 0; },
    draw: function (tab, host) {
      if (tab === 'injuries') return drawInjuries(host); if (tab === 'claims') return drawClaims(host); if (tab === 'rota') return drawRota(host); if (tab === 'letters') return drawLetters(host);
      if (tab === 'overtime') return drawOvertime(host); if (tab === 'timesheets') return drawTimesheets(host); if (tab === 'openshifts') return drawOpenShifts(host);
    },
    mine: function (host) { mine(host); mineClaims(host); mineOvertime(host); mineRota(host); mineOpen(host); }
  };
})(window);
