/* ═══════════════════════════════════════════════════════════════════════
   KiddieTrac HR Phase 4, batch 9 (2026-11-27): window.KTHrLeave — a lazy dependency of screen-hr.js.

   Time off › Closures & checks (kt-hr-sections.js section "timeoff", tab leavemaths):
     Leave to review      a closure was added, moved or removed after leave was booked, so the booked amount may be
                          wrong. Nothing was changed: a manager (never the person) uses the new amount or keeps it.
     Closures             per centre closure (holiday / emergency / other), an agency admin chooses to take the days
                          off a leave balance, or not. Always a preview first; people can be left out; Reverse gives
                          it all back. Never automatic. Directors see their centres' list.
     Part-time pro-rata   (admins) NEW grants for part-time staff = their share of the company's full-time week.
   The server decides everything (App\Support\HrLeaveMaths, HrLeaveMathsController). Every balance change is a new
   row in the balance history with a reason; nothing is edited.
   ═══════════════════════════════════════════════════════════════════════ */
(function (window) {
  'use strict';
  var KT = window.KT;
  if (!KT || !KT.Shell) return;
  var Api = KT.Api;

  function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }
  function toast(i, t, m, c) { try { KT.toast && KT.toast(i, t, m || '', c); } catch (e) {} }
  function saved(m) { toast('✅', 'Saved', m || '', '#16A34A'); }
  function errMsg(e) {
    var m = (e && ((e.data && e.data.message) || e.message)) || '';
    return m && !/fetch|network|undefined|null|status \d/i.test(m) ? m : 'That did not work. Please try again.';
  }
  function fail(t, e) { toast('⚠️', t, errMsg(e), '#DC2626'); }
  function num(v) { v = Math.round((+v || 0) * 100) / 100; return String(v); }
  function amt(v, unit) { var n = num(v); return n + ' ' + (unit === 'hours' ? (n === '1' ? 'hour' : 'hours') : (n === '1' ? 'day' : 'days')); }
  function pill(bg, fg, t) { return '<span data-kt-no-tone style="display:inline-block;padding:2px 9px;border-radius:999px;font-size:12px;font-weight:700;background:' + bg + ';color:' + fg + ';white-space:nowrap;">' + esc(t) + '</span>'; }
  function el(html) { var d = document.createElement('div'); d.innerHTML = html; return d; }
  function msg(box, t) { var m = box.querySelector('[data-msg]'); if (m) m.textContent = t || ''; }
  var ERR = '<div data-msg style="color:#B91C1C;font-size:13px;margin-top:8px;"></div>';
  var MON = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
  function day(s) { var m = /^(\d{4})-(\d{2})-(\d{2})/.exec(s || ''); return m ? MON[+m[2] - 1] + ' ' + (+m[3]) + ', ' + m[1] : ''; }
  var CLOSURE = { holiday: 'Holiday', emergency: 'Emergency', other: 'Other' };

  var CSS_ID = 'kt-hrleave-css';
  function css() {
    if (document.getElementById(CSS_ID)) return;
    var s = document.createElement('style'); s.id = CSS_ID;
    s.textContent = '.lm-sec{font-size:12px;font-weight:800;letter-spacing:.05em;text-transform:uppercase;color:#0E7490;margin:18px 0 8px;display:flex;align-items:center;gap:8px;flex-wrap:wrap;}'
      + '.lm-sec:first-child{margin-top:4px;}.lm-sec .sp{flex:1;}'
      + '.lm-ppl{border:1px solid #E2E8F0;border-radius:10px;}'
      + '.lm-ppl table{width:100%;border-collapse:collapse;font-size:13px;}.lm-ppl th,.lm-ppl td{padding:6px 9px;border-bottom:1px solid #F1F5F9;text-align:left;}'
      + '.lm-ppl th{background:#F8FAFC;font-size:11.5px;color:#475569;}'
      + 'html.kt-dark .lm-sec{color:#5EEAD4;}html.kt-dark .lm-ppl{border-color:#334155;}html.kt-dark .lm-ppl th{background:#0F172A;color:#CBD5E1;}html.kt-dark .lm-ppl td{border-color:#1E293B;}';
    document.head.appendChild(s);
  }

  var S = { done: false };

  async function draw(host) {
    css();
    host.innerHTML = '<div data-lm-review><div class="hr-sub" style="padding:10px 2px;">Loading…</div></div><div data-lm-shut></div><div data-lm-pro></div>';
    drawReview(host);
    drawShutdowns(host);
    drawProRata(host);
  }

  // ── Leave to review ────────────────────────────────────────────────────
  async function drawReview(host) {
    var b = host.querySelector('[data-lm-review]'); if (!b) return;
    var d;
    try { d = await Api.get('/hr/leave-rechecks' + (S.done ? '?status=done' : '')); } catch (e) { b.innerHTML = '<div class="hr-note">' + esc(errMsg(e)) + '</div>'; return; }
    if (!b.isConnected) return;
    var rows = d.rows || [];
    var h = '<div class="lm-sec">Leave to review<span class="sp"></span>'
      + '<button type="button" class="hr-b" data-kt-iconized="1" data-lm-toggle>' + (S.done ? 'Show what needs review' : 'Show reviewed') + '</button>'
      + (d.can_scan && !S.done ? '<button type="button" class="hr-b" data-kt-iconized="1" data-lm-scan>Check booked leave now</button>' : '') + '</div>';
    if (!S.done) h += '<div class="hr-note">When a closure or holiday is added, moved or removed after time off was booked, the days that time off covers can change. KiddieTrac does not change a balance by itself: each booking that would now count differently is listed here, and you choose.</div>';
    h += '<div class="kt-card">' + (rows.length ? '<table><thead><tr><th>Who</th><th>Dates</th><th>Leave type</th><th>Booked</th><th>Counts now</th><th>Why</th>' + (S.done ? '<th>Outcome</th>' : '') + '<th></th></tr></thead><tbody>'
      + rows.map(function (r) {
        var diff = Math.round((r.fresh - r.booked) * 100) / 100;
        var out = { corrected: ['#ECFDF5', '#047857', 'Corrected'], kept: ['#F1F5F9', '#475569', 'Kept as booked'], cleared: ['#F1F5F9', '#475569', 'No longer needed'] }[r.status];
        return '<tr><td><b data-no-i18n>' + esc(r.person) + '</b>' + (r.kind === 'shutdown' ? '<div class="hr-sub">Closure deduction</div>' : (r.status_of_request === 'pending' ? '<div class="hr-sub">Waiting for a decision</div>' : '')) + '</td>'
          + '<td style="white-space:nowrap;" data-no-i18n>' + esc(r.when) + '</td><td data-no-i18n>' + esc(r.leave_type) + '</td>'
          + '<td style="white-space:nowrap;">' + esc(amt(r.booked, r.unit)) + '</td>'
          + '<td style="white-space:nowrap;"><b>' + esc(amt(r.fresh, r.unit)) + '</b>' + (diff ? '<div class="hr-sub">' + (diff < 0 ? esc(amt(-diff, r.unit)) + ' less' : esc(amt(diff, r.unit)) + ' more') + '</div>' : '') + '</td>'
          + '<td class="hr-sub" data-no-i18n>' + esc(r.cause || '') + (r.note ? '<div>' + esc(r.note) + '</div>' : '') + '</td>'
          + (S.done ? '<td>' + (out ? pill(out[0], out[1], out[2]) : '') + (r.resolved_by ? '<div class="hr-sub" data-no-i18n>' + esc(r.resolved_by) + '</div>' : '') + '</td>' : '')
          + '<td style="text-align:center;white-space:nowrap;">' + (!S.done && !r.own ? '<button type="button" data-rc="correct" data-id="' + r.id + '">Use the new amount</button><button type="button" data-rc="keep" data-id="' + r.id + '">Keep as booked</button>' : '')
          + (r.request_id ? '<button type="button" data-rc="open" data-id="' + r.id + '">Open time off requests</button>' : '') + '</td></tr>';
      }).join('') + '</tbody></table>' : '<div style="padding:14px;" class="hr-sub">' + (S.done ? 'Nothing reviewed yet.' : 'Nothing to review: every booking matches the closures on the calendar.') + '</div>') + '</div>';
    b.innerHTML = h;
    var byId = {}; rows.forEach(function (r) { byId[r.id] = r; });
    b.querySelector('[data-lm-toggle]').addEventListener('click', function () { S.done = !S.done; drawReview(host); });
    var sc = b.querySelector('[data-lm-scan]');
    if (sc) sc.addEventListener('click', function () {
      sc.disabled = true;
      Api.post('/hr/leave-rechecks/scan', {}).then(function (r) {
        toast('✅', 'Checked', r.checked + ' booking' + (r.checked === 1 ? '' : 's') + ' checked, ' + r.open + ' to review.', '#16A34A'); drawReview(host);
      }, function (e) { sc.disabled = false; fail('Could not check', e); });
    });
    b.querySelectorAll('[data-rc]').forEach(function (x) {
      x.addEventListener('click', function () {
        var r = byId[x.getAttribute('data-id')], a = x.getAttribute('data-rc');
        if (a === 'open') { location.hash = '#time-off'; return; }
        resolve(r, a, function () { drawReview(host); drawShutdowns(host); });
      });
    });
  }

  function resolve(r, outcome, after) {
    var correct = outcome === 'correct';
    var diff = Math.round((r.fresh - r.booked) * 100) / 100;
    var what = r.kind === 'shutdown'
      ? (correct ? 'The closure deduction is redone for the closure as it is now: only the difference is added to or taken off each person\'s balance.' : 'The closure deduction stays as it was applied.')
      : (correct ? (r.status_of_request === 'approved'
        ? 'One new line goes in the balance history: ' + (diff < 0 ? amt(-diff, r.unit) + ' given back.' : amt(diff, r.unit) + ' taken off.')
        : 'The request will take ' + amt(r.fresh, r.unit) + ' when it is approved.') : 'The booking stays at ' + amt(r.booked, r.unit) + '.');
    var box = el('<div class="hr-kv"><span>Who</span><b data-no-i18n>' + esc(r.person) + '</b></div><div class="hr-kv"><span>Dates</span><b data-no-i18n>' + esc(r.when) + '</b></div>'
      + '<div class="hr-kv"><span>Booked</span><b>' + esc(amt(r.booked, r.unit)) + '</b></div><div class="hr-kv"><span>Counts now</span><b>' + esc(amt(r.fresh, r.unit)) + '</b></div>'
      + '<div class="hr-note" style="margin-top:10px;">' + esc(what) + '</div>'
      + '<label class="hr-l" style="margin-top:8px;">Note (optional)</label><textarea class="hr-i" data-f="note" rows="2" maxlength="300" style="width:100%;min-height:48px;"></textarea>' + ERR);
    KT.Shell.Modal.open({ title: correct ? 'Use the new amount' : 'Keep as booked', body: box, actions: [{ label: 'Cancel' }, { label: correct ? 'Use the new amount' : 'Keep as booked', primary: true, busyLabel: 'Saving…', handler: function () {
      var n = (box.querySelector('[data-f="note"]').value || '').trim();
      return Api.post('/hr/leave-rechecks/' + r.id + '/resolve', { outcome: outcome, note: n || null }).then(function () { saved(correct ? 'Corrected. The balance history shows why.' : 'Kept as booked.'); if (after) after(); },
        function (e) { msg(box, errMsg(e)); return false; });
    } }] });
  }

  // ── Closures (shutdowns) ───────────────────────────────────────────────
  async function drawShutdowns(host) {
    var b = host.querySelector('[data-lm-shut]'); if (!b) return;
    var d;
    try { d = await Api.get('/hr/shutdowns'); } catch (e) { b.innerHTML = '<div class="lm-sec">Closures</div><div class="hr-note">' + esc(errMsg(e)) + '</div>'; return; }
    if (!b.isConnected) return;
    var rows = d.rows || [];
    var h = '<div class="lm-sec">Closures: take the days off leave?</div>'
      + '<div class="hr-note">For a centre closure (a holiday, emergency or other closure on the calendar; never a statutory holiday) ' + (d.can_decide ? 'you' : 'an agency admin') + ' can choose to take the closed working days off the staff\'s leave, for example a winter shutdown taken as vacation. Nothing is taken until ' + (d.can_decide ? 'you apply' : 'an admin applies') + ' it, and you always see who loses how much first.</div>'
      + '<div class="kt-card">' + (rows.length ? '<table><thead><tr><th>Centre</th><th>Dates</th><th>Closure</th><th>Leave</th><th></th></tr></thead><tbody>' + rows.map(function (r) {
        var dc = r.decision, st;
        if (!dc) st = pill('#FFFBEB', '#B45309', 'Not decided');
        else if (dc.status === 'applied') st = pill('#ECFDF5', '#047857', 'Taken from ' + (dc.leave_type || 'leave')) + '<div class="hr-sub">' + esc(amt(dc.total, dc.unit)) + ' · ' + dc.people + ' ' + (dc.people === 1 ? 'person' : 'people') + '</div>';
        else if (dc.status === 'reversed') st = pill('#F1F5F9', '#475569', 'Reversed');
        else st = pill('#F1F5F9', '#475569', 'Not taken from leave');
        if (dc && dc.recheck_id) st += ' ' + pill('#FEF3C7', '#92400E', 'Dates changed - review');
        var acts = '';
        if (d.can_decide) {
          if (!dc || dc.status !== 'applied') acts += r.removed ? '' : '<button type="button" data-sd="deduct" data-cid="' + r.closure_id + '">Take from leave…</button>';
          if (!dc && !r.removed) acts += '<button type="button" data-sd="none" data-cid="' + r.closure_id + '">Don\'t take from leave</button>';
          if (dc && dc.status === 'applied') acts += '<button type="button" data-sd="view" data-cid="' + r.closure_id + '">Who it was taken from</button><button type="button" data-sd="reverse" data-cid="' + r.closure_id + '">Reverse</button>';
        } else if (!r.removed) acts += '<button type="button" data-sd="view" data-cid="' + r.closure_id + '">Who it affects</button>';
        return '<tr><td data-no-i18n>' + esc(r.centre) + '</td><td style="white-space:nowrap;">' + esc(r.when) + (r.past ? '<div class="hr-sub">Over</div>' : r.started ? '<div class="hr-sub">Happening now</div>' : '') + '</td>'
          + '<td>' + esc(CLOSURE[r.type] || r.type) + (r.reason ? '<div class="hr-sub" data-no-i18n>' + esc(r.reason) + '</div>' : '') + (r.removed ? '<div class="hr-sub">Removed from the calendar</div>' : '') + '</td>'
          + '<td>' + st + (dc && dc.by ? '<div class="hr-sub" data-no-i18n>' + esc(dc.by) + '</div>' : '') + '</td><td style="text-align:center;white-space:nowrap;">' + acts + '</td></tr>';
      }).join('') + '</tbody></table>' : '<div style="padding:14px;" class="hr-sub">No holiday, emergency or other closures on the calendar from the last year on. Add closures under Settings › Closures.</div>') + '</div>';
    b.innerHTML = h;
    var byC = {}; rows.forEach(function (r) { byC[r.closure_id] = r; });
    b.querySelectorAll('[data-sd]').forEach(function (x) {
      x.addEventListener('click', function () {
        var r = byC[x.getAttribute('data-cid')], a = x.getAttribute('data-sd');
        var again = function () { drawShutdowns(host); drawReview(host); };
        if (a === 'deduct' || a === 'view') return openPreview(r, d, a === 'view' || !d.can_decide, again);
        if (a === 'none') return decideNone(r, again);
        if (a === 'reverse') return reverse(r, again);
      });
    });
  }

  async function decideNone(r, after) {
    var ok = await KT.confirm({ title: 'Don\'t take this closure from leave?', description: r.centre + ', ' + r.when + '. Nobody\'s balance changes; the choice is recorded.', okLabel: 'Don\'t take from leave' });
    if (!ok) return;
    try { await Api.post('/hr/shutdowns/decide', { closure_id: r.closure_id, choice: 'none' }); saved('Recorded: not taken from leave.'); after(); } catch (e) { fail('Could not save', e); }
  }

  function reverse(r, after) {
    var dc = r.decision || {};
    var box = el('<div class="hr-note">Gives back the ' + esc(amt(dc.total, dc.unit)) + ' taken from ' + dc.people + ' ' + (dc.people === 1 ? 'person' : 'people') + ' for ' + esc(r.when) + '. New lines go in each balance history; nothing is deleted.</div>'
      + '<label class="hr-l">Reason (required)</label><textarea class="hr-i" data-f="reason" rows="2" maxlength="300" style="width:100%;min-height:48px;" placeholder="e.g. Taken from the wrong leave type"></textarea>' + ERR);
    KT.Shell.Modal.open({ title: 'Reverse the closure deduction', body: box, actions: [{ label: 'Cancel' }, { label: 'Reverse', primary: true, busyLabel: 'Reversing…', handler: function () {
      var why = (box.querySelector('[data-f="reason"]').value || '').trim();
      if (why.length < 3) { msg(box, 'Say why.'); return false; }
      return Api.post('/hr/shutdowns/' + dc.id + '/reverse', { reason: why }).then(function (x) { saved('Given back to ' + x.people + ' ' + (x.people === 1 ? 'person' : 'people') + '.'); after(); },
        function (e) { msg(box, errMsg(e)); return false; });
    } }] });
  }

  async function openPreview(r, list, readOnly, after) {
    var types = list.types || [];
    var dc = r.decision || null;
    var typeId = (dc && dc.leave_type_id) || list.default_type_id || (types[0] && types[0].id);
    if (!typeId) { toast('⚠️', 'No leave type to take from', 'Add a leave type with a balance first (Time off › Leave types).', '#DC2626'); return; }
    var exclude = null;
    var box = el('<div data-lm-head></div><div data-lm-body><div class="hr-sub" style="padding:10px 2px;">Loading…</div></div>' + ERR);
    var applied = dc && dc.status === 'applied';
    var M = KT.Shell.Modal.open({ title: applied || readOnly ? 'Who this closure affects' : 'Take the closure from leave', body: box, large: true, actions: readOnly || applied ? [{ label: 'Close' }] : [
      { label: 'Cancel' },
      { label: 'Take from leave', primary: true, busyLabel: 'Applying…', handler: function () {
        var past = box.querySelector('[data-f="past"]');
        if (past && !past.checked) { msg(box, 'This closure is over: tick the box to take the leave off anyway.'); return false; }
        return Api.post('/hr/shutdowns/decide', { closure_id: r.closure_id, choice: 'deduct', leave_type_id: typeId, exclude_user_ids: exclude || [], confirm_past: !!past })
          .then(function (x) { saved('Taken from ' + x.people + ' ' + (x.people === 1 ? 'person' : 'people') + '. Each balance history shows why.'); after(); }, function (e) { msg(box, errMsg(e)); return false; });
      } }] });
    async function load() {
      var q = '/hr/shutdowns/preview?closure_id=' + r.closure_id + '&leave_type_id=' + typeId;
      if (exclude) q += exclude.length ? exclude.map(function (i) { return '&exclude[]=' + i; }).join('') : '&exclude[]=0';
      var p;
      try { p = await Api.get(q); } catch (e) { box.querySelector('[data-lm-body]').innerHTML = '<div class="hr-note">' + esc(errMsg(e)) + '</div>'; return; }
      if (!box.isConnected) return;
      if (exclude === null) exclude = p.rows.filter(function (x) { return x.left_out; }).map(function (x) { return x.user_id; });
      var u = p.leave_type.unit;
      box.querySelector('[data-lm-head]').innerHTML = '<div class="hr-kv"><span>Closure</span><b data-no-i18n>' + esc(r.centre) + ' · ' + esc(r.when) + (r.reason ? ' · ' + esc(r.reason) : '') + '</b></div>'
        + '<div class="hr-bar" style="margin-top:8px;"><label class="hr-sub">Take from <select class="hr-i" data-f="type"' + (readOnly || applied ? ' disabled' : '') + '>' + types.map(function (t) { return '<option value="' + t.id + '"' + (+t.id === +typeId ? ' selected' : '') + ' data-no-i18n>' + esc(t.name) + '</option>'; }).join('') + '</select></label>'
        + '<span class="hr-sub">Each working day closed = ' + esc(amt(p.per_day, u)) + '. Weekends, statutory holidays, days before someone started and days already taken by another closure are not counted.</span></div>';
      var inc = p.rows.filter(function (x) { return !x.skip; });
      var h = '<div class="hr-stats"><div class="hr-stat"><b>' + p.people + '</b><span>People</span></div><div class="hr-stat"><b>' + esc(amt(p.total, u)) + '</b><span>Taken in all</span></div></div>'
        + '<div class="lm-ppl"><table><thead><tr>' + (readOnly || applied ? '' : '<th style="width:30px;"></th>') + '<th>Name</th><th>Working days closed</th><th>Takes</th><th>Balance now</th><th>After</th></tr></thead><tbody>'
        + p.rows.map(function (x) {
          var neg = !x.skip && !x.left_out && x.after < 0;
          return '<tr' + (x.skip || x.left_out ? ' style="opacity:.6;"' : '') + '>' + (readOnly || applied ? '' : '<td>' + (x.skip ? '' : '<input type="checkbox" data-inc="' + x.user_id + '"' + (x.left_out ? '' : ' checked') + ' aria-label="Include">') + '</td>')
            + '<td><b data-no-i18n>' + esc(x.name) + '</b>' + (x.user_id === p.me ? ' <span class="hr-sub">(you)</span>' : '') + (x.review ? '<div style="font-size:11.5px;font-weight:700;color:#B45309;">Booked time off on these dates needs review first</div>' : '') + '</td>'
            + '<td>' + (x.skip ? '<span class="hr-sub">' + esc(x.skip) + '</span>' : esc(x.days.length) + (x.days.length ? '<div class="hr-sub" data-no-i18n>' + esc(x.days.map(function (dd) { return day(dd).replace(/, \d{4}$/, ''); }).join(', ')) + '</div>' : '')) + '</td>'
            + '<td style="white-space:nowrap;">' + (x.skip ? '—' : x.left_out ? '<span class="hr-sub">Left out</span>' : '<b>' + esc(amt(x.amount, u)) + '</b>') + '</td>'
            + '<td style="white-space:nowrap;">' + esc(amt(x.balance, u)) + '</td><td style="white-space:nowrap;' + (neg ? 'color:#B91C1C;font-weight:700;' : '') + '">' + esc(amt(x.after, u)) + '</td></tr>';
        }).join('') + '</tbody></table></div>';
      if (!inc.length) h += '<div class="hr-sub" style="margin-top:8px;">Nobody works at this centre on those days.</div>';
      if (applied) h += '<div class="hr-note" style="margin-top:10px;">This was taken from ' + esc(dc.leave_type || 'leave') + ' by ' + esc(dc.by || 'an admin') + '. Reverse it to change it.</div>';
      if (!readOnly && !applied && p.rows.some(function (x) { return !x.skip && !x.left_out && x.after < 0; })) h += '<div class="hr-sub" style="margin-top:8px;color:#B91C1C;">Some balances go below zero. Leave those people out, or take it anyway.</div>';
      if (!readOnly && !applied && r.past) h += '<label style="display:flex;gap:8px;align-items:flex-start;font-size:13.5px;margin-top:10px;cursor:pointer;"><input type="checkbox" data-f="past" style="margin-top:2px;"><span>This closure is over. Take the leave off anyway.</span></label>';
      box.querySelector('[data-lm-body]').innerHTML = h;
      var ts = box.querySelector('[data-f="type"]');
      if (ts) ts.addEventListener('change', function () { typeId = +ts.value; load(); });
      box.querySelectorAll('[data-inc]').forEach(function (c) {
        c.addEventListener('change', function () {
          var id = +c.getAttribute('data-inc');
          exclude = exclude.filter(function (v) { return v !== id; });
          if (!c.checked) exclude.push(id);
          load();
        });
      });
    }
    load();
    return M;
  }

  // ── Part-time pro-rata (admins) ────────────────────────────────────────
  async function drawProRata(host) {
    var b = host.querySelector('[data-lm-pro]'); if (!b) return;
    var d;
    try { d = await Api.get('/hr/leave-maths/settings'); } catch (e) { return; }
    if (!b.isConnected || !d.can_edit) return;
    var s = d.settings || {}, types = d.types || [], et = d.employment_types || {};
    b.innerHTML = '<div class="lm-sec">Part-time pro-rata</div><div class="kt-card" style="padding:12px 14px;">'
      + '<div class="hr-sub" style="margin-bottom:10px;">When this is on, a NEW grant (a new leave year, or each new pay period) for someone part-time is their share of the full entitlement: their hours a week on their employee file, out of the company\'s full-time week. Grants already in the balance history never change. Anyone with no hours a week on their file gets the full amount.</div>'
      + '<label style="display:flex;gap:9px;align-items:center;font-size:14px;font-weight:700;cursor:pointer;margin-bottom:10px;"><input type="checkbox" data-kt-switch="1" data-f="on"' + (s.pro_rata ? ' checked' : '') + '> Pro-rata new grants for part-time staff</label>'
      + '<div class="hr-g"><div><label class="hr-l">Full-time week (hours)</label><input class="hr-i" type="number" min="1" max="80" step="0.5" data-f="full" value="' + esc(s.full_time_hours) + '" style="width:100%;"></div>'
      + '<div><label class="hr-l">Leave types</label>' + (types.length ? types.map(function (t) { return '<label style="display:flex;gap:8px;align-items:center;font-size:13px;cursor:pointer;"><input type="checkbox" data-ty="' + t.id + '"' + ((s.type_ids || []).indexOf(t.id) !== -1 ? ' checked' : '') + '><span data-no-i18n>' + esc(t.name) + '</span></label>'; }).join('') : '<span class="hr-sub">No granted or accrued leave types yet.</span>') + '</div>'
      + '<div><label class="hr-l">Counts as part-time</label>' + Object.keys(et).map(function (k) { return '<label style="display:flex;gap:8px;align-items:center;font-size:13px;cursor:pointer;"><input type="checkbox" data-et="' + k + '"' + ((s.employment_types || []).indexOf(k) !== -1 ? ' checked' : '') + '><span>' + esc(et[k]) + '</span></label>'; }).join('') + '</div></div>'
      + '<div class="hr-bar" style="margin:12px 0 0;"><button type="button" class="hr-b" data-kt-iconized="1" data-pr="preview">Preview</button><button type="button" class="hr-b p" data-kt-iconized="1" data-pr="save">Save</button></div>'
      + '<div data-pr-out style="margin-top:10px;"></div></div>';
    function input() {
      var o = { pro_rata: b.querySelector('[data-f="on"]').checked, full_time_hours: +b.querySelector('[data-f="full"]').value || 40, type_ids: [], employment_types: [] };
      b.querySelectorAll('[data-ty]').forEach(function (c) { if (c.checked) o.type_ids.push(+c.getAttribute('data-ty')); });
      b.querySelectorAll('[data-et]').forEach(function (c) { if (c.checked) o.employment_types.push(c.getAttribute('data-et')); });
      return o;
    }
    var out = b.querySelector('[data-pr-out]');
    b.querySelector('[data-pr="preview"]').addEventListener('click', async function () {
      var o = input();
      if (!o.type_ids.length) { out.innerHTML = '<div class="hr-sub" style="color:#B91C1C;">Choose at least one leave type.</div>'; return; }
      var q = '/hr/leave-maths/pro-rata-preview?pro_rata=1&full_time_hours=' + o.full_time_hours + o.type_ids.map(function (i) { return '&type_ids[]=' + i; }).join('') + o.employment_types.map(function (k) { return '&employment_types[]=' + k; }).join('');
      var p;
      try { p = await Api.get(q); } catch (e) { out.innerHTML = '<div class="hr-sub" style="color:#B91C1C;">' + esc(errMsg(e)) + '</div>'; return; }
      var rows = p.rows || [];
      out.innerHTML = (rows.length ? '<div class="lm-ppl"><table><thead><tr><th>Name</th><th>Hours a week</th><th>Yearly entitlement</th><th>With pro-rata</th></tr></thead><tbody>' + rows.map(function (x) {
        return '<tr><td><b data-no-i18n>' + esc(x.name) + '</b></td><td>' + (x.hours_per_week == null ? '<span class="hr-sub">Not on file: full amount</span>' : esc(num(x.hours_per_week))) + '</td>'
          + '<td data-no-i18n>' + esc(x.lines.map(function (l) { return l.type + ' ' + amt(l.full, l.unit); }).join(', ')) + '</td>'
          + '<td data-no-i18n><b>' + esc(x.lines.map(function (l) { return l.type + ' ' + amt(l.pro_rata, l.unit); }).join(', ')) + '</b></td></tr>';
      }).join('') + '</tbody></table></div>' : '<div class="hr-sub">Nobody part-time gets these leave types yet.</div>')
        + (p.missing_hours ? '<div class="hr-sub" style="margin-top:6px;">' + p.missing_hours + ' part-time ' + (p.missing_hours === 1 ? 'person has' : 'people have') + ' no hours a week on their file. Add them in the HR profile of each person, under Pay and role history, to pro-rata their grants.</div>' : '')
        + '<div class="hr-sub" style="margin-top:6px;">This is what each NEW grant would be. Nothing changes until you save, and grants already given stay as they are.</div>';
    });
    b.querySelector('[data-pr="save"]').addEventListener('click', async function () {
      var o = input(), btn = this;
      if (o.pro_rata && !o.type_ids.length) { out.innerHTML = '<div class="hr-sub" style="color:#B91C1C;">Choose at least one leave type.</div>'; return; }
      if (o.pro_rata && !s.pro_rata) {
        var ok = await KT.confirm({ title: 'Turn on pro-rata?', description: 'From the next grant on, part-time staff get their share of these leave types. Grants already given are not changed.', okLabel: 'Turn on' });
        if (!ok) return;
      }
      btn.disabled = true;
      try { var r = await Api.put('/hr/leave-maths/settings', o); s = r.settings; saved(s.pro_rata ? 'Pro-rata is on for new grants.' : 'Pro-rata is off.'); }
      catch (e) { fail('Could not save', e); }
      btn.disabled = false;
    });
  }

  window.KTHrLeave = {
    tabs: ['leavemaths'],
    has: function (t) { return this.tabs.indexOf(t) >= 0; },
    draw: function (tab, host) { if (tab === 'leavemaths') return draw(host); },
    resolve: resolve
  };
})(window);
