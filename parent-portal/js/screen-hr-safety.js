/* ═══════════════════════════════════════════════════════════════════════
   screen-hr-safety.js - KiddieTrac HR Phase 3 (2026-11-26): staff injuries and near misses.

   - HR > Injuries (directors: own centres; admins: the agency): the list, Form 7 countdown, return-to-work plans.
   - My HR > "Injury reports": a staff member reports their own and sees their own records.
   Health details (what happened, body part, injury, first aid, medical care, modified duties, check-in notes) are
   never in a list: a record shows them only after "Show health details", which the server audits every time.
   Lazy: loaded before screen-hr.js and screen-hr-talent.js (their "d" dependency), never on its own.
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

  window.KTHrSafety = {
    tabs: [['injuries', 'Injuries']],
    has: function (tab) { return tab === 'injuries'; },
    draw: function (tab, host) { if (tab === 'injuries') return drawInjuries(host); },
    mine: mine
  };
})(window);
