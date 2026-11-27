/* ═══════════════════════════════════════════════════════════════════════
   screen-hr-safety.js - KiddieTrac HR Phase 3 (2026-11-26): staff injuries and near misses.

   - HR > Injuries (directors: own centres; admins: the agency): the list, Form 7 countdown, return-to-work plans.
   - My HR > "Injury reports": a staff member reports their own and sees their own records.
   Health details (what happened, body part, injury, first aid, medical care, modified duties, check-in notes) are
   never in a list: a record shows them only after "Show health details", which the server audits every time.
   Lazy: loaded before screen-hr.js and screen-hr-talent.js (their "d" dependency), never on its own.
   Item 9 (2026-11-26): HR > Claims and My HR "Expense and mileage claims" (expense with receipt photo, or mileage at the
   agency's km rate); approved claims are paid on the next manual payslip as a reimbursement line.
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

  window.KTHrSafety = {
    tabs: [['injuries', 'Injuries'], ['claims', 'Claims']],
    has: function (tab) { return tab === 'injuries' || tab === 'claims'; },
    draw: function (tab, host) { if (tab === 'injuries') return drawInjuries(host); if (tab === 'claims') return drawClaims(host); },
    mine: function (host) { mine(host); mineClaims(host); }
  };
})(window);
