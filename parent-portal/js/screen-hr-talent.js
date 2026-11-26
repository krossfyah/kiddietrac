/* ═══════════════════════════════════════════════════════════════════════
   KiddieTrac HR, Phase 2 (2026-11-28): Hiring, Onboarding, Performance and Courses.

   Loaded before screen-hr.js (its lazy "d" dependency) and on its own for My HR (#my-hr).
   - window.KTHrTalent.tabs        the four extra HR tabs  [key, label]
   - window.KTHrTalent.draw(tab, host)   draws one of them into the HR screen's body
   - window.KTHrTalent.profile(host, userId)   the onboarding / reviews / goals / courses
     cards on a person's HR profile
   - #my-hr                        the person's own view: their checklist, courses, reviews,
     goals and one-to-ones (educators, home visitors, directors, admins)

   The server scopes everything (admins = agency, directors = own centres, everyone = their own
   records; nobody manages their own). House patterns: data-kt-pretty tables with plain labelled
   buttons in the last cell (kt-row-actions makes the ⋮ menu), table.ktBulkActions for bulk,
   KT.Shell.Modal for popups, one .kt-hero banner, "Saved" toasts, friendly errors. Dates are
   the agency's: date-only values from their parts, timestamps through KT.agencyDateOf.
   ═══════════════════════════════════════════════════════════════════════ */
(function (window) {
  'use strict';
  var KT = window.KT;
  if (!KT || !KT.Shell || !KT.Shell.registerScreen) return;
  var Api = KT.Api;

  function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }
  function toast(i, t, m, c) { try { KT.toast && KT.toast(i, t, m || '', c); } catch (e) {} }
  function saved(m) { toast('✅', 'Saved', m || '', '#16A34A'); }
  function errMsg(e) {
    var d = e && e.data;
    if (d && d.errors) { var k = Object.keys(d.errors)[0]; if (k && d.errors[k] && d.errors[k][0]) return d.errors[k][0]; }
    return (d && d.message) || (e && e.message && !/^HTTP \d/.test(e.message) ? e.message : '') || 'Something went wrong. Please try again.';
  }
  function fail(t, e) { toast('⚠️', t, errMsg(e), '#DC2626'); }
  var MON = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
  function day(s) { var m = /^(\d{4})-(\d{2})-(\d{2})/.exec(s || ''); return m ? MON[+m[2] - 1] + ' ' + (+m[3]) + ', ' + m[1] : ''; }
  function tsDay(ts) { if (!ts) return ''; try { if (KT.agencyDateOf) return day(KT.agencyDateOf(ts)); } catch (e) {} return day(ts); }
  function today() { try { if (KT.agencyToday) return KT.agencyToday(); } catch (e) {} return new Date().toISOString().slice(0, 10); }
  function addDays(ymd, n) { var p = ymd.split('-'); var d = new Date(Date.UTC(+p[0], +p[1] - 1, +p[2] + n)); return d.toISOString().slice(0, 10); }
  function pill(bg, fg, t) { return '<span data-kt-no-tone style="display:inline-block;padding:2px 9px;border-radius:999px;font-size:12px;font-weight:700;background:' + bg + ';color:' + fg + ';white-space:nowrap;">' + esc(t) + '</span>'; }
  function opts(map, v, blank) {
    return (blank != null ? '<option value="">' + esc(blank) + '</option>' : '') + Object.keys(map).map(function (k) { return '<option value="' + esc(k) + '"' + (String(v) === String(k) ? ' selected' : '') + '>' + esc(map[k]) + '</option>'; }).join('');
  }
  function list(arr, v, blank, label) {
    return (blank != null ? '<option value="">' + esc(blank) + '</option>' : '') + (arr || []).map(function (x) { return '<option value="' + esc(x.id) + '"' + (String(v) === String(x.id) ? ' selected' : '') + '>' + esc(x[label || 'name']) + '</option>'; }).join('');
  }
  function bar(pct, late) { pct = Math.max(0, Math.min(100, +pct || 0)); return '<span class="hrt-bar" title="' + pct + '%"><i style="width:' + pct + '%;background:' + (late ? '#F59E0B' : '#0E7490') + ';"></i></span> <span class="hr-sub">' + pct + '%</span>'; }
  function modal(o) { return KT.Shell.Modal.open(o); }
  function closeModal() { try { var r = document.getElementById('modalRoot'); var x = r && r.querySelector('.modal-close'); if (x) x.click(); } catch (e) {} }
  function el(html) { var d = document.createElement('div'); d.innerHTML = html; return d; }
  function val(box, k) { var e = box.querySelector('[data-f="' + k + '"]'); if (!e) return null; if (e.type === 'checkbox') return e.checked; var v = String(e.value || '').trim(); return v === '' ? null : v; }
  function msg(box, t) { var m = box.querySelector('[data-msg]'); if (m) m.textContent = t || ''; }
  function field(label, inner, wide) { return '<div' + (wide ? ' style="grid-column:1/-1;"' : '') + '><label class="hr-l">' + esc(label) + '</label>' + inner + '</div>'; }
  function input(k, v, type, extra) { return '<input class="hr-i" data-f="' + k + '" type="' + (type || 'text') + '" value="' + esc(v == null ? '' : v) + '" style="width:100%;"' + (extra || '') + '>'; }
  function area(k, v, rows) { return '<textarea class="hr-i" data-f="' + k + '" rows="' + (rows || 4) + '" style="width:100%;min-height:' + ((rows || 4) * 22) + 'px;">' + esc(v || '') + '</textarea>'; }
  function select(k, inner) { return '<select class="hr-i" data-f="' + k + '" style="width:100%;">' + inner + '</select>'; }
  async function blob(path, name, view) {
    try {
      var url = (KT.API_BASE || '/api/v1') + path, b;
      if (!KT.fetchFileBlob) throw new Error('');
      b = await KT.fetchFileBlob(url);
      var u = URL.createObjectURL(b);
      if (view) { window.open(u, '_blank', 'noopener'); }
      else { var a = document.createElement('a'); a.href = u; a.download = name || 'file'; document.body.appendChild(a); a.click(); a.remove(); }
      setTimeout(function () { URL.revokeObjectURL(u); }, 60000);
    } catch (e) { fail('Could not open the file', e); }
  }

  var CSS_ID = 'kt-hrt-css';
  function css() {
    if (document.getElementById(CSS_ID)) return;
    var s = document.createElement('style'); s.id = CSS_ID;
    s.textContent = ''
      + '.hr-bar{display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin:0 0 10px;}'
      + '.hr-i{height:30px;padding:0 9px;border:1px solid #CBD5E1;border-radius:8px;font-size:13px;background:#fff;color:#0F172A;box-sizing:border-box;max-width:100%;}'
      + 'textarea.hr-i{height:auto;padding:6px 9px;font-family:inherit;resize:vertical;}'
      + '.hr-b{height:30px;padding:0 12px;border-radius:8px;border:1px solid #CBD5E1;background:#fff;font-weight:700;font-size:12.5px;cursor:pointer;color:#0F172A;white-space:nowrap;}'
      + '.hr-b.p{background:#0E7490;border-color:#0E7490;color:#fff;}'
      + '.hr-l{display:block;font-size:11.5px;font-weight:800;color:#334155;text-transform:uppercase;letter-spacing:.4px;margin-bottom:4px;}'
      + '.hr-g{display:grid;grid-template-columns:repeat(auto-fit,minmax(min(210px,100%),1fr));gap:10px;}'
      + '.hr-sub{font-size:12px;color:#64748B;}'
      + '.hr-note{font-size:12.5px;color:#475569;background:#F0F9FF;border:1px solid #BAE6FD;border-radius:10px;padding:9px 12px;margin:0 0 10px;line-height:1.5;}'
      + '.hr-pc{border:1px solid #E2E8F0;border-radius:12px;padding:11px 13px;background:#fff;}'
      + '.hr-pc h4{margin:0 0 7px;font-size:12px;letter-spacing:.05em;text-transform:uppercase;color:#0E7490;}'
      + '.hr-pf{display:grid;grid-template-columns:repeat(auto-fit,minmax(min(260px,100%),1fr));gap:10px;}'
      + '.hr-link{background:none;border:0;padding:0;color:#0E7490;font-weight:700;font-size:12.5px;cursor:pointer;text-decoration:underline;}'
      + '.hrt-bar{display:inline-block;width:90px;height:8px;border-radius:99px;background:#E2E8F0;overflow:hidden;vertical-align:middle;}.hrt-bar i{display:block;height:100%;}'
      + '.hrt-chips{display:flex;gap:6px;flex-wrap:wrap;margin:0 0 10px;}'
      + '.hrt-chip{height:28px;padding:0 11px;border-radius:999px;border:1px solid #E2E8F0;background:#fff;font-size:12.5px;font-weight:700;cursor:pointer;color:#475569;}'
      + '.hrt-chip[aria-pressed="true"]{background:#E0F2FE;border-color:#7DD3FC;color:#075985;}'
      + '.hrt-sec{font-weight:800;font-size:14px;margin:16px 0 8px;color:#0F172A;}'
      + '.hrt-task{display:flex;gap:10px;align-items:flex-start;padding:8px 0;border-bottom:1px solid #F1F5F9;}'
      + '.hrt-task:last-child{border-bottom:0;}.hrt-task .t{flex:1;min-width:0;}.hrt-task .t b{font-size:13.5px;}'
      + '.hrt-ck{flex:0 0 auto;width:22px;height:22px;border-radius:50%;border:2px solid #CBD5E1;display:inline-flex;align-items:center;justify-content:center;font-size:12px;color:#fff;margin-top:1px;}'
      + '.hrt-ck.on{background:#16A34A;border-color:#16A34A;}.hrt-ck.late{border-color:#F59E0B;}'
      + '.hrt-acts{display:flex;gap:6px;flex-wrap:wrap;margin-top:5px;}'
      + '.hrt-sect{border:1px solid #E2E8F0;border-radius:12px;padding:11px 13px;margin-bottom:10px;}'
      + '.hrt-sect h5{margin:0 0 3px;font-size:13.5px;}'
      + '.hrt-rate{display:flex;gap:6px;flex-wrap:wrap;margin:6px 0;}'
      + '.hrt-rate label{display:inline-flex;align-items:center;gap:4px;border:1px solid #E2E8F0;border-radius:999px;padding:3px 10px;font-size:12.5px;cursor:pointer;}'
      + '.hrt-rate input{margin:0;}.hrt-two{display:grid;grid-template-columns:repeat(auto-fit,minmax(min(280px,100%),1fr));gap:10px;}'
      + '.hrt-ro{font-size:13px;color:#334155;white-space:pre-wrap;background:#F8FAFC;border-radius:8px;padding:7px 9px;}'
      + '.hrt-row{display:grid;grid-template-columns:150px 1fr 1fr 90px auto;gap:6px;align-items:center;margin-bottom:6px;}'
      + '@media (max-width:640px){.hrt-row{grid-template-columns:1fr 1fr;}}'
      + '.hrt-pick{max-height:220px;overflow:auto;border:1px solid #E2E8F0;border-radius:10px;padding:6px 9px;}'
      + '.hrt-pick label{display:flex;align-items:center;gap:7px;font-size:13px;padding:3px 0;}'
      + 'html.kt-dark .hr-i,html.kt-dark .hr-b,html.kt-dark .hr-pc,html.kt-dark .hrt-chip,html.kt-dark .hrt-sect,html.kt-dark .hrt-pick,html.kt-dark .hrt-rate label{background:#0F172A;border-color:#334155;color:#E2E8F0;}'
      + 'html.kt-dark .hr-b.p{background:#0E7490;border-color:#0E7490;color:#fff;}html.kt-dark .hr-l{color:#CBD5E1;}html.kt-dark .hr-pc h4{color:#5EEAD4;}'
      + 'html.kt-dark .hrt-chip[aria-pressed="true"]{background:#0B2530;border-color:#155E75;color:#CFFAFE;}html.kt-dark .hrt-sec{color:#F1F5F9;}'
      + 'html.kt-dark .hrt-ro{background:#111827;color:#E2E8F0;}html.kt-dark .hrt-task{border-color:#1E293B;}html.kt-dark .hrt-bar{background:#334155;}'
      + 'html.kt-dark .hr-note{background:#0B2530;border-color:#155E75;color:#CFFAFE;}';
    document.head.appendChild(s);
  }

  var STAGE_TONE = { new: ['#E0F2FE', '#075985'], reviewing: ['#EDE9FE', '#5B21B6'], interview: ['#FEF3C7', '#92400E'], offer: ['#DCFCE7', '#166534'],
    hired: ['#16A34A', '#FFFFFF'], declined: ['#F1F5F9', '#475569'], on_file: ['#F1F5F9', '#334155'], withdrawn: ['#F1F5F9', '#64748B'] };
  var REVIEW_LBL = { open: 'Not started', self_done: 'Self review in', manager_done: 'Manager review in', signing: 'Ready to sign', complete: 'Complete', cancelled: 'Cancelled' };
  var REVIEW_TONE = { open: ['#F1F5F9', '#475569'], self_done: ['#E0F2FE', '#075985'], manager_done: ['#EDE9FE', '#5B21B6'], signing: ['#FEF3C7', '#92400E'], complete: ['#DCFCE7', '#166534'], cancelled: ['#F1F5F9', '#64748B'] };
  var GOAL_TONE = { not_started: ['#F1F5F9', '#475569'], on_track: ['#DCFCE7', '#166534'], at_risk: ['#FEF3C7', '#92400E'], done: ['#16A34A', '#FFFFFF'], dropped: ['#F1F5F9', '#64748B'] };
  var GOAL_LBL = { not_started: 'Not started', on_track: 'On track', at_risk: 'At risk', done: 'Done', dropped: 'Dropped' };
  function stagePill(s, labels) { var c = STAGE_TONE[s] || STAGE_TONE.new; return pill(c[0], c[1], (labels && labels[s]) || s); }
  function reviewPill(s) { var c = REVIEW_TONE[s] || REVIEW_TONE.open; return pill(c[0], c[1], REVIEW_LBL[s] || s); }
  function goalPill(s) { var c = GOAL_TONE[s] || GOAL_TONE.not_started; return pill(c[0], c[1], GOAL_LBL[s] || s); }

  var H = { hiring: null, stage: 'open', post: '' };
  var GOALS_CACHE = {};

  // ══ HIRING ═══════════════════════════════════════════════════════════════
  async function drawHiring(host) {
    var d;
    try { d = H.hiring = await Api.get('/hr/hiring'); } catch (e) { host.innerHTML = '<div class="hr-note">' + esc(errMsg(e)) + '</div>'; return; }
    if (!host.isConnected) return;
    var st = d.stages || {}, et = d.employment_types || {};
    var openSet = { new: 1, reviewing: 1, interview: 1, offer: 1 };
    var apps = (d.applications || []).filter(function (a) { return a.job_post_id; });
    var counts = {}; apps.forEach(function (a) { counts[a.status] = (counts[a.status] || 0) + 1; });
    var shown = apps.filter(function (a) {
      return (!H.post || String(a.job_post_id) === String(H.post))
        && (H.stage === 'all' || (H.stage === 'open' ? openSet[a.status] : a.status === H.stage));
    });
    var postTitle = {}; (d.posts || []).forEach(function (p) { postTitle[p.id] = p.title; });
    var h = '<div class="hr-note">Your jobs page: <a href="' + esc(d.public_url) + '" target="_blank" rel="noopener" data-no-i18n>' + esc(d.public_url) + '</a>'
      + ' <button type="button" class="hr-link" id="hj-copy">Copy link</button>. People apply there; their applications land below.</div>'
      + '<div class="hr-bar"><button type="button" class="hr-b p" id="hj-new">New job post</button>'
      + (d.can_settings ? '<button type="button" class="hr-b" id="hj-set">Hiring settings</button>' : '') + '</div>'
      + '<div class="kt-card">' + ((d.posts || []).length ? '<table data-hrt="posts"><thead><tr><th>Job</th><th>Centre</th><th>Type</th><th>Pay</th><th>Applicants</th><th>Status</th><th></th></tr></thead><tbody>'
        + d.posts.map(function (p) {
          return '<tr data-id="' + p.id + '"><td><b data-no-i18n>' + esc(p.title) + '</b><div class="hr-sub">Posted ' + esc(tsDay(p.created_at)) + '</div></td>'
            + '<td data-no-i18n>' + esc(p.centre || 'All centres') + '</td><td>' + esc(et[p.employment_type] || '') + '</td><td data-no-i18n>' + esc(p.pay || '') + '</td>'
            + '<td>' + p.applicants + (p.open_applicants ? ' <span class="hr-sub">(' + p.open_applicants + ' active)</span>' : '') + '</td>'
            + '<td>' + (p.status === 'open' ? pill('#DCFCE7', '#166534', 'Open') : pill('#F1F5F9', '#475569', 'Closed')) + '</td>'
            + '<td style="text-align:center;white-space:nowrap;"><button type="button" data-pa="applicants" data-id="' + p.id + '">See applicants</button>'
            + '<button type="button" data-pa="edit" data-id="' + p.id + '">Edit</button>'
            + '<button type="button" data-pa="' + (p.status === 'open' ? 'close' : 'reopen') + '" data-id="' + p.id + '">' + (p.status === 'open' ? 'Close' : 'Reopen') + '</button></td></tr>';
        }).join('') + '</tbody></table>' : '<div style="padding:16px;" class="hr-sub">No job posts yet. Add one and share your jobs page.</div>') + '</div>';
    h += '<div class="hrt-sec">Applicants</div><div class="hrt-chips">'
      + [['open', 'Active'], ['new', st.new], ['reviewing', st.reviewing], ['interview', st.interview], ['offer', st.offer], ['hired', st.hired], ['declined', st.declined], ['on_file', st.on_file], ['all', 'All']].map(function (c) {
        var n = c[0] === 'all' ? apps.length : c[0] === 'open' ? apps.filter(function (a) { return openSet[a.status]; }).length : (counts[c[0]] || 0);
        var on = H.stage === c[0];
        return '<button type="button" class="hrt-chip" data-stage="' + c[0] + '" aria-pressed="' + on + '" style="height:28px;padding:0 11px;border-radius:999px;font-size:12.5px;font-weight:700;cursor:pointer;'
          + (on ? 'background:#E0F2FE;border:1px solid #7DD3FC;color:#075985;' : 'background:#fff;border:1px solid #E2E8F0;color:#475569;') + '">' + esc(c[1] || c[0]) + ' · ' + n + '</button>';
      }).join('')
      + ((d.posts || []).length > 1 ? '<select class="hr-i" id="hj-post"><option value="">Every job</option>' + d.posts.map(function (p) { return '<option value="' + p.id + '"' + (String(p.id) === String(H.post) ? ' selected' : '') + '>' + esc(p.title) + '</option>'; }).join('') + '</select>' : '')
      + '</div>';
    h += '<div class="kt-card">' + (shown.length ? '<table data-hrt="apps"><thead><tr><th>Applicant</th><th>Job</th><th>Applied</th><th>Stage</th><th>Interview</th><th></th></tr></thead><tbody>'
      + shown.map(function (a) {
        var live = openSet[a.status];
        return '<tr data-id="' + a.id + '"><td><b data-no-i18n>' + esc(a.name) + '</b><div class="hr-sub" data-no-i18n>' + esc(a.email || '') + '</div></td>'
          + '<td data-no-i18n>' + esc(postTitle[a.job_post_id] || a.role || '') + (a.centre ? '<div class="hr-sub">' + esc(a.centre) + '</div>' : '') + '</td>'
          + '<td style="white-space:nowrap;">' + esc(tsDay(a.created_at)) + '</td><td>' + stagePill(a.status, st) + '</td><td data-no-i18n>' + esc(a.interview || '') + '</td>'
          + '<td style="text-align:center;white-space:nowrap;"><button type="button" data-aa="view" data-id="' + a.id + '">View application</button>'
          + (live ? '<button type="button" data-aa="move" data-id="' + a.id + '">Move to a stage</button><button type="button" data-aa="interview" data-id="' + a.id + '">Book an interview</button>'
            + (d.can_hire ? '<button type="button" data-aa="hire" data-id="' + a.id + '">Hire</button>' : '')
            + '<button type="button" data-aa="on_file" data-id="' + a.id + '">Keep on file</button><button type="button" data-aa="decline" data-id="' + a.id + '">Decline</button>' : '')
          + '</td></tr>';
      }).join('') + '</tbody></table>' : '<div style="padding:16px;" class="hr-sub">No applicants here.</div>') + '</div>';
    host.innerHTML = h;
    var redraw = function () { drawHiring(host); };
    var cp = host.querySelector('#hj-copy'); if (cp) cp.addEventListener('click', function () { try { navigator.clipboard.writeText(d.public_url); toast('📋', 'Link copied', '', '#0E7490'); } catch (e) {} });
    host.querySelector('#hj-new').addEventListener('click', function () { openPost(null, redraw); });
    var sb = host.querySelector('#hj-set'); if (sb) sb.addEventListener('click', function () { openHiringSettings(d.settings || {}); });
    host.querySelectorAll('[data-stage]').forEach(function (b) { b.addEventListener('click', function () { H.stage = b.getAttribute('data-stage'); drawHiring(host); }); });
    var hp = host.querySelector('#hj-post'); if (hp) hp.addEventListener('change', function () { H.post = hp.value; drawHiring(host); });
    host.querySelectorAll('[data-pa]').forEach(function (b) {
      b.addEventListener('click', async function () {
        var id = +b.getAttribute('data-id'), a = b.getAttribute('data-pa'), p = (d.posts || []).filter(function (x) { return x.id === id; })[0];
        if (a === 'applicants') { H.post = String(id); H.stage = 'all'; return drawHiring(host); }
        if (a === 'edit') return openPost(p, redraw);
        try { await Api.post('/hr/jobs/' + id + '/' + a, {}); saved(a === 'close' ? 'Job closed: it is off your jobs page.' : 'Job reopened.'); redraw(); } catch (e) { fail('Could not change the job', e); }
      });
    });
    host.querySelectorAll('[data-aa]').forEach(function (b) {
      b.addEventListener('click', function () {
        var id = +b.getAttribute('data-id'), a = b.getAttribute('data-aa'), app = apps.filter(function (x) { return x.id === id; })[0];
        if (a === 'view') return openApplication(id, redraw);
        if (a === 'move') return openMove([id], redraw);
        if (a === 'interview') return openInterview(app, redraw);
        if (a === 'hire') return openHire(app, redraw);
        return openLetter(app, a, redraw);
      });
    });
    var t = host.querySelector('table[data-hrt="apps"]');
    if (t) t.ktBulkActions = [
      { label: 'Move to a stage', run: function (rows) { openMove(rows.map(function (r) { return +r.getAttribute('data-id'); }), redraw); } }
    ];
  }

  function openPost(p, done) {
    var d = H.hiring || {}, et = d.employment_types || {};
    var box = el('<div class="hr-g">' + field('Job title', input('title', p && p.title, 'text', ' maxlength="120"'), true)
      + field('Centre', select('centre_id', list(d.centres, p && p.centre_id, (d.can_settings ? 'All centres' : null)))) + field('Type', select('employment_type', opts(et, p && p.employment_type, '—')))
      + field('Pay from', input('pay_min', p && p.pay_min, 'number', ' min="0" step="0.01"')) + field('Pay to', input('pay_max', p && p.pay_max, 'number', ' min="0" step="0.01"'))
      + field('Pay is', select('pay_unit', opts({ hour: 'An hourly rate', year: 'A yearly salary' }, (p && p.pay_unit) || 'hour')))
      + field('Description', area('description', p && p.description, 8), true) + '</div>'
      + '<div class="hr-sub" style="margin-top:6px;">The pay range is optional. Leave both empty to keep pay off the posting.</div><div data-msg style="color:#B91C1C;font-size:13px;margin-top:6px;"></div>');
    modal({ title: p ? 'Edit job post' : 'New job post', body: box, actions: [{ label: 'Cancel' }, { label: p ? 'Save' : 'Post the job', primary: true, busyLabel: 'Saving…', handler: function () {
      var o = { title: val(box, 'title'), centre_id: val(box, 'centre_id') ? +val(box, 'centre_id') : null, employment_type: val(box, 'employment_type'),
        pay_min: val(box, 'pay_min'), pay_max: val(box, 'pay_max'), pay_unit: val(box, 'pay_unit'), description: val(box, 'description') };
      return (p ? Api.patch('/hr/jobs/' + p.id, o) : Api.post('/hr/jobs', o)).then(function () { saved(p ? 'Job post updated.' : 'Job posted on your jobs page.'); done(); }, function (e) { msg(box, errMsg(e)); return false; });
    } }] });
  }

  function openHiringSettings(s) {
    var box = el('<div class="hr-g">' + field('Keep applicants on file for (months)', input('keep_months', s.keep_months || 24, 'number', ' min="1" max="60"'))
      + field('Replies to applicants go to', input('reply_to', s.reply_to, 'email', ' placeholder="hiring@yourcentre.ca"'))
      + field('Words at the top of your jobs page', area('apply_intro', s.apply_intro, 4), true) + '</div>'
      + '<div class="hr-sub" style="margin-top:6px;">An applicant kept on file is told how long you keep their resume; after that it is deleted automatically.</div><div data-msg style="color:#B91C1C;font-size:13px;margin-top:6px;"></div>');
    modal({ title: 'Hiring settings', body: box, actions: [{ label: 'Cancel' }, { label: 'Save', primary: true, busyLabel: 'Saving…', handler: function () {
      return Api.put('/hr/hiring/settings', { keep_months: +val(box, 'keep_months'), reply_to: val(box, 'reply_to'), apply_intro: val(box, 'apply_intro') })
        .then(function () { saved('Hiring settings saved.'); }, function (e) { msg(box, errMsg(e)); return false; });
    } }] });
  }

  async function openApplication(id, done) {
    var r;
    try { r = await Api.get('/hr/applications/' + id); } catch (e) { return fail('Could not open the application', e); }
    var a = r.application, st = r.stages || {};
    var box = el('<div class="hr-pf"><div class="hr-pc"><h4>Applicant</h4><div style="font-size:16px;font-weight:800;" data-no-i18n>' + esc(a.name) + '</div>'
      + '<div class="hr-sub" data-no-i18n>' + esc(a.role || '') + '</div><div style="margin-top:6px;font-size:13px;" data-no-i18n>' + esc(a.email || '') + (a.phone ? '<br>' + esc(a.phone) : '')
      + (a.linkedin ? '<br><a href="' + esc(a.linkedin) + '" target="_blank" rel="noopener">LinkedIn profile</a>' : '') + '</div>'
      + '<div style="margin-top:8px;">' + stagePill(a.status, st) + '</div>'
      + (a.interview ? '<div class="hr-sub" style="margin-top:6px;">Interview: ' + esc(a.interview.when) + (a.interview.where ? ' · ' + esc(a.interview.where) : '') + '</div>' : '')
      + (a.keep_until ? '<div class="hr-sub" style="margin-top:6px;">On file until ' + esc(day(a.keep_until)) + '</div>' : '') + '</div>'
      + '<div class="hr-pc"><h4>Files</h4><div class="hrt-acts">' + (a.has_resume ? '<button type="button" class="hr-b" data-file="view/resume">View resume</button><button type="button" class="hr-b" data-file="file/resume">Download resume</button>' : '<span class="hr-sub">No resume.</span>')
      + (a.has_cover ? '<button type="button" class="hr-b" data-file="file/cover">Download cover letter</button>' : '') + '</div>'
      + (a.cover_text ? '<div class="hr-l" style="margin-top:10px;">Cover note</div><div class="hrt-ro" data-no-i18n>' + esc(a.cover_text) + '</div>' : '') + '</div>'
      + '<div class="hr-pc"><h4>History</h4>' + (r.events || []).map(function (e) {
        return '<div class="hr-sub" style="padding:3px 0;">' + esc(tsDay(e.at)) + ' · ' + esc(st[e.to] || e.to) + (e.by ? ' · <span data-no-i18n>' + esc(e.by) + '</span>' : '') + '</div>';
      }).join('') + '</div></div>');
    modal({ title: 'Job application', body: box, large: true, actions: [{ label: 'Close' }] });
    box.querySelectorAll('[data-file]').forEach(function (b) {
      b.addEventListener('click', async function () {
        var k = b.getAttribute('data-file');
        if (k === 'view/resume') {
          try {
            var v = await Api.get('/hr/applications/' + id + '/view/resume');
            if (v && v.html != null) { var w = window.open('', '_blank'); if (w) { w.document.write('<!doctype html><meta charset="utf-8"><title>Resume</title><body style="font-family:sans-serif;max-width:800px;margin:20px auto;">' + v.html + '</body>'); w.document.close(); } return; }
          } catch (e) { if (!(e && e.status === 200)) { /* a PDF comes back as a file, not JSON */ } }
          return blob('/hr/applications/' + id + '/view/resume', 'resume', true);
        }
        blob('/hr/applications/' + id + '/' + k, k.indexOf('cover') > 0 ? 'cover-letter' : 'resume');
      });
    });
  }

  function openMove(ids, done) {
    var st = (H.hiring && H.hiring.stages) || {};
    var pick = {}; ['new', 'reviewing', 'interview', 'offer', 'declined', 'withdrawn'].forEach(function (k) { if (st[k]) pick[k] = st[k]; });
    var box = el(field('Move to', select('status', opts(pick, 'reviewing'))) + '<div class="hr-sub" style="margin-top:6px;">This only changes the stage. No email is sent: use Book an interview, Keep on file or Decline to write to the applicant.</div><div data-msg style="color:#B91C1C;font-size:13px;margin-top:6px;"></div>');
    modal({ title: ids.length > 1 ? 'Move ' + ids.length + ' applicants' : 'Move to a stage', body: box, actions: [{ label: 'Cancel' }, { label: 'Move', primary: true, busyLabel: 'Moving…', handler: function () {
      var s = val(box, 'status');
      var p = ids.length > 1 ? Api.post('/hr/applications/bulk-status', { ids: ids, status: s }) : Api.patch('/hr/applications/' + ids[0], { status: s });
      return p.then(function () { saved('Moved to ' + (st[s] || s) + '.'); done(); }, function (e) { msg(box, errMsg(e)); return false; });
    } }] });
  }

  function previewInto(box, id, payload) {
    var pv = box.querySelector('[data-preview]');
    pv.innerHTML = '<div class="hr-sub">Loading the letter…</div>';
    return Api.post('/hr/applications/' + id + '/preview', payload).then(function (r) {
      pv.innerHTML = '<div class="hr-sub" style="margin-bottom:4px;">To <span data-no-i18n>' + esc(r.to) + '</span> · <b data-no-i18n>' + esc(r.subject) + '</b></div>';
      var f = document.createElement('iframe'); f.setAttribute('sandbox', ''); f.style.cssText = 'width:100%;height:420px;border:1px solid #E2E8F0;border-radius:10px;background:#fff;';
      f.srcdoc = r.html; pv.appendChild(f);
    }, function (e) { pv.innerHTML = '<div class="hr-sub" style="color:#B91C1C;">' + esc(errMsg(e)) + '</div>'; });
  }

  function openInterview(a, done) {
    var t = addDays(today(), 2);
    var box = el('<div class="hr-g">' + field('Date', input('date', t, 'date')) + field('Time', input('time', '10:00', 'time')) + field('Length (minutes)', input('minutes', 30, 'number', ' min="15" max="180" step="15"'))
      + field('How', select('how', opts({ in_person: 'In person', phone: 'By phone (we call them)', video: 'Video call (paste the link)' }, 'in_person')))
      + field('Where', input('location', '', 'text', ' placeholder="Centre address and room"')) + field('Video call link', input('link', '', 'url', ' placeholder="https://"'))
      + field('A note in the email (optional)', area('note', '', 3), true) + '</div>'
      + '<div class="hr-bar" style="margin-top:8px;"><button type="button" class="hr-b" data-pv>Preview the email</button></div><div data-preview></div><div data-msg style="color:#B91C1C;font-size:13px;margin-top:6px;"></div>');
    var payload = function () { return { kind: 'interview', date: val(box, 'date'), time: val(box, 'time'), minutes: +val(box, 'minutes') || 30, how: val(box, 'how'), location: val(box, 'location'), link: val(box, 'link'), note: val(box, 'note') }; };
    modal({ title: 'Book an interview: ' + a.name, body: box, large: true, actions: [{ label: 'Cancel' }, { label: 'Send the invitation', primary: true, busyLabel: 'Sending…', handler: function () {
      return Api.post('/hr/applications/' + a.id + '/interview', payload()).then(function (r) {
        toast('📅', r.sent ? 'Invitation sent' : 'Interview booked', r.sent ? r.when : 'The email could not be sent; the interview is on your calendar.', '#16A34A'); done();
      }, function (e) { msg(box, errMsg(e)); return false; });
    } }] });
    box.querySelector('[data-pv]').addEventListener('click', function () { previewInto(box, a.id, payload()); });
  }

  function openLetter(a, kind, done) {
    var keep = kind === 'on_file';
    var box = el('<div class="hr-note">' + (keep ? 'They are told you keep their resume on file and may contact them about future openings. It is deleted automatically after your keep-on-file period.'
      : 'A kind email tells them you are not moving forward. Their application stays here as Declined.') + '</div>'
      + field('A note in the email (optional)', area('note', '', 3), true)
      + '<div class="hr-bar" style="margin-top:8px;"><button type="button" class="hr-b" data-pv>Preview the email</button></div><div data-preview></div><div data-msg style="color:#B91C1C;font-size:13px;margin-top:6px;"></div>');
    modal({ title: (keep ? 'Keep on file: ' : 'Decline: ') + a.name, body: box, large: true, actions: [{ label: 'Cancel' }, { label: keep ? 'Keep on file and email them' : 'Decline and email them', primary: true, busyLabel: 'Sending…', handler: function () {
      return Api.post('/hr/applications/' + a.id + '/' + (keep ? 'keep' : 'decline'), { note: val(box, 'note') }).then(function (r) {
        saved(r.sent ? 'Done, and the email went out.' : 'Done. The email could not be sent.'); done();
      }, function (e) { msg(box, errMsg(e)); return false; });
    } }] });
    box.querySelector('[data-pv]').addEventListener('click', function () { previewInto(box, a.id, { kind: keep ? 'on_file' : 'decline', note: val(box, 'note') }); });
  }

  async function openHire(a, done) {
    var d = H.hiring || {}, ob = null, post = (d.posts || []).filter(function (p) { return p.id === a.job_post_id; })[0] || {};
    try { ob = await Api.get('/hr/onboarding'); } catch (e) { ob = { templates: [] }; }
    var tpls = (ob.templates || []).filter(function (t) { return !t.archived; });
    var mgrs = []; try { mgrs = (await Api.get('/hr/performance')).managers || []; } catch (e) {}
    var box = el('<div class="hr-note">This creates their KiddieTrac account (the same as adding someone on the Users screen), emails them a link to set their password, fills their employee file and starts their onboarding checklist.</div>'
      + '<div class="hr-g">' + field('Role', select('role', opts({ educator: 'Educator', centre_director: 'Centre director', home_visitor: 'Home visitor' }, 'educator')))
      + field('Centre', select('centre_id', list(d.centres, post.centre_id, '—'))) + field('Start date', input('start_date', addDays(today(), 7), 'date'))
      + field('Job title', input('job_title', post.title || a.role)) + field('Employment', select('employment_type', opts(d.employment_types || {}, post.employment_type, '—')))
      + field('Manager', select('manager_user_id', list(mgrs, '', '—'))) + field('Onboarding checklist', select('template_id', list(tpls, (tpls[0] || {}).id, 'None for now')))
      + field('Email for their account', input('email', a.email, 'email')) + field('Username (optional)', input('username', ''))
      + '<label style="display:flex;gap:7px;align-items:center;font-size:13px;grid-column:1/-1;"><input type="checkbox" data-f="send_invite" checked> Email them the link to set their password</label>'
      + '</div><div data-msg style="color:#B91C1C;font-size:13px;margin-top:6px;"></div>');
    modal({ title: 'Hire ' + a.name, body: box, large: true, actions: [{ label: 'Cancel' }, { label: 'Hire and create the account', primary: true, busyLabel: 'Creating…', handler: function () {
      var o = { role: val(box, 'role'), centre_id: val(box, 'centre_id') ? +val(box, 'centre_id') : null, start_date: val(box, 'start_date'), job_title: val(box, 'job_title'),
        employment_type: val(box, 'employment_type'), manager_user_id: val(box, 'manager_user_id') ? +val(box, 'manager_user_id') : null,
        template_id: val(box, 'template_id') ? +val(box, 'template_id') : null, email: val(box, 'email'), username: val(box, 'username'), send_invite: val(box, 'send_invite') };
      return Api.post('/hr/applications/' + a.id + '/hire', o).then(function (r) {
        toast('🎉', 'Hired', (r.invite_sent ? 'Their sign-in link is on its way.' : (r.note || 'Their account is ready.')) + (r.onboarding_id ? ' Onboarding has started.' : ''), '#16A34A'); done();
      }, function (e) { msg(box, errMsg(e)); return false; });
    } }] });
  }

  // ══ ONBOARDING ═══════════════════════════════════════════════════════════
  var OB = null;
  async function drawOnboarding(host) {
    try { OB = await Api.get('/hr/onboarding'); } catch (e) { host.innerHTML = '<div class="hr-note">' + esc(errMsg(e)) + '</div>'; return; }
    if (!host.isConnected) return;
    var rows = OB.onboardings || [];
    var h = '<div class="hr-bar"><button type="button" class="hr-b p" id="ho-start">Start onboarding</button></div>'
      + '<div class="kt-card">' + (rows.length ? '<table data-hrt="onb"><thead><tr><th>Person</th><th>Checklist</th><th>Start date</th><th>Progress</th><th>Overdue</th><th>Status</th><th></th></tr></thead><tbody>'
        + rows.map(function (o) {
          return '<tr data-id="' + o.id + '"><td><b data-no-i18n>' + esc(o.name) + '</b></td><td data-no-i18n>' + esc(o.template || 'Custom') + '</td><td style="white-space:nowrap;">' + esc(day(o.start_date)) + '</td>'
            + '<td style="white-space:nowrap;">' + bar(o.percent, o.overdue) + ' <span class="hr-sub">' + o.done + ' of ' + o.total + '</span></td>'
            + '<td>' + (o.overdue ? pill('#FEF3C7', '#92400E', o.overdue + ' overdue') : '<span class="hr-sub">—</span>') + '</td>'
            + '<td>' + (o.status === 'complete' ? pill('#DCFCE7', '#166534', 'Complete') : pill('#E0F2FE', '#075985', 'In progress')) + '</td>'
            + '<td style="text-align:center;white-space:nowrap;"><button type="button" data-oa="open" data-id="' + o.id + '">Open checklist</button>'
            + (o.status === 'active' ? '<button type="button" data-oa="cancel" data-id="' + o.id + '">Cancel checklist</button>' : '') + '</td></tr>';
        }).join('') + '</tbody></table>' : '<div style="padding:16px;" class="hr-sub">Nobody is onboarding right now. Hiring someone starts their checklist; you can also start one here.</div>') + '</div>';
    if (OB.can_templates) {
      var tp = OB.templates || [];
      h += '<div class="hrt-sec">Checklist templates</div><div class="hr-bar"><button type="button" class="hr-b" id="ho-tnew">New checklist template</button></div><div class="kt-card">'
        + (tp.length ? '<table data-hrt="otpl"><thead><tr><th>Template</th><th>For</th><th>Tasks</th><th>Status</th><th></th></tr></thead><tbody>' + tp.map(function (t) {
          return '<tr data-id="' + t.id + '"><td><b data-no-i18n>' + esc(t.name) + '</b></td><td>' + esc((OB.choices.roles || {})[t.role] || 'Anyone') + '</td><td>' + t.tasks.length + '</td>'
            + '<td>' + (t.archived ? pill('#F1F5F9', '#475569', 'Archived') : pill('#DCFCE7', '#166534', 'In use')) + '</td>'
            + '<td style="text-align:center;white-space:nowrap;"><button type="button" data-ta="edit" data-id="' + t.id + '">Edit</button><button type="button" data-ta="' + (t.archived ? 'restore' : 'archive') + '" data-id="' + t.id + '">' + (t.archived ? 'Restore' : 'Archive') + '</button></td></tr>';
        }).join('') + '</tbody></table>' : '<div style="padding:16px;" class="hr-sub">No templates yet. A template is the list of tasks a new person gets, each due a number of days after they start.</div>') + '</div>';
    }
    host.innerHTML = h;
    var redraw = function () { drawOnboarding(host); };
    host.querySelector('#ho-start').addEventListener('click', function () { openStart(redraw); });
    var tn = host.querySelector('#ho-tnew'); if (tn) tn.addEventListener('click', function () { openTemplate(null, redraw); });
    host.querySelectorAll('[data-oa]').forEach(function (b) {
      b.addEventListener('click', async function () {
        var id = +b.getAttribute('data-id');
        if (b.getAttribute('data-oa') === 'open') return openChecklist(id, redraw);
        var ok = await KT.confirm({ title: 'Cancel this checklist?', description: 'Its tasks stop and no more reminders go out. Finished tasks stay on the record.', okLabel: 'Cancel checklist', cancelLabel: 'Keep it' });
        if (!ok) return;
        try { await Api.post('/hr/onboarding/' + id + '/cancel', {}); saved('Checklist cancelled.'); redraw(); } catch (e) { fail('Could not cancel it', e); }
      });
    });
    host.querySelectorAll('[data-ta]').forEach(function (b) {
      b.addEventListener('click', async function () {
        var id = +b.getAttribute('data-id'), a = b.getAttribute('data-ta');
        if (a === 'edit') return openTemplate((OB.templates || []).filter(function (t) { return t.id === id; })[0], redraw);
        try { await Api.post('/hr/onboarding-templates/' + id + '/' + a, {}); saved(a === 'archive' ? 'Template archived.' : 'Template restored.'); redraw(); } catch (e) { fail('Could not change the template', e); }
      });
    });
  }

  function openStart(done) {
    var tpls = (OB.templates || []).filter(function (t) { return !t.archived; });
    var box = el('<div class="hr-g">' + field('Person', select('user_id', list(OB.people, '', 'Choose…'))) + field('Checklist', select('template_id', list(tpls, (tpls[0] || {}).id, 'An empty checklist')))
      + field('Start date', input('start_date', today(), 'date')) + '</div><div data-msg style="color:#B91C1C;font-size:13px;margin-top:6px;"></div>');
    var us = box.querySelector('[data-f="user_id"]');
    us.addEventListener('change', function () { var p = (OB.people || []).filter(function (x) { return String(x.id) === us.value; })[0]; if (p && p.start_date) box.querySelector('[data-f="start_date"]').value = p.start_date; });
    modal({ title: 'Start onboarding', body: box, actions: [{ label: 'Cancel' }, { label: 'Start', primary: true, busyLabel: 'Starting…', handler: function () {
      return Api.post('/hr/onboarding', { user_id: +val(box, 'user_id') || 0, template_id: val(box, 'template_id') ? +val(box, 'template_id') : null, start_date: val(box, 'start_date') })
        .then(function () { saved('Onboarding started. They see it under My HR.'); done(); }, function (e) { msg(box, errMsg(e)); return false; });
    } }] });
  }

  function refPicker(ch, kind, t) {
    if (kind === 'policy') return '<select class="hr-i" data-k="ref_id">' + list(ch.policies, t.ref_id, 'Choose a policy…', 'title') + '</select>';
    if (kind === 'course') return '<select class="hr-i" data-k="ref_id">' + list(ch.courses, t.ref_id, 'Choose a course…', 'title') + '</select>';
    if (kind === 'certificate') return '<select class="hr-i" data-k="ref_key"><option value="">Choose a certificate…</option>' + (ch.certificates || []).map(function (c) { return '<option value="' + esc(c.code) + '"' + (c.code === t.ref_key ? ' selected' : '') + '>' + esc(c.label) + '</option>'; }).join('') + '</select>';
    return '<span class="hr-sub">' + (kind === 'document' ? 'They upload it' : 'Ticked off by hand') + '</span>';
  }

  function openTemplate(t, done) {
    var ch = OB.choices || {}, kinds = ch.kinds || {};
    var tasks = (t && t.tasks.length) ? t.tasks.slice() : [{ kind: 'document', title: 'Upload your criminal record check', due_days: 0 }, { kind: 'custom', title: 'Tour of the centre with your director', due_days: 1 }];
    var box = el('<div class="hr-g">' + field('Template name', input('name', t ? t.name : 'New educator')) + field('For', select('role', opts(ch.roles || {}, t && t.role, 'Anyone'))) + '</div>'
      + '<div class="hrt-sec">Tasks</div><div class="hr-sub" style="margin-bottom:6px;">Due = days after the start date (0 = the first day). Policy, certificate and course tasks tick themselves off when the real thing is done.</div>'
      + '<div data-rows></div><button type="button" class="hr-b" data-add>Add a task</button><div data-msg style="color:#B91C1C;font-size:13px;margin-top:6px;"></div>');
    var host = box.querySelector('[data-rows]');
    function draw() {
      host.innerHTML = tasks.map(function (x, i) {
        return '<div class="hrt-row" data-i="' + i + '"><select class="hr-i" data-k="kind">' + opts(kinds, x.kind) + '</select>'
          + '<input class="hr-i" data-k="title" value="' + esc(x.title || '') + '" placeholder="What to do">' + refPicker(ch, x.kind, x)
          + '<input class="hr-i" data-k="due_days" type="number" min="-30" max="365" value="' + esc(x.due_days == null ? 7 : x.due_days) + '" title="Days after the start date">'
          + '<button type="button" class="hr-b" data-del="' + i + '">Remove</button></div>';
      }).join('');
      host.querySelectorAll('.hrt-row').forEach(function (r) {
        var i = +r.getAttribute('data-i');
        r.querySelectorAll('[data-k]').forEach(function (e) {
          e.addEventListener('change', function () {
            var k = e.getAttribute('data-k'); tasks[i][k] = k === 'due_days' ? +e.value : (k === 'ref_id' ? (+e.value || null) : e.value);
            if (k === 'kind') { tasks[i].ref_id = null; tasks[i].ref_key = null; draw(); }
          });
        });
      });
      host.querySelectorAll('[data-del]').forEach(function (b) { b.addEventListener('click', function () { tasks.splice(+b.getAttribute('data-del'), 1); draw(); }); });
    }
    draw();
    box.querySelector('[data-add]').addEventListener('click', function () { tasks.push({ kind: 'custom', title: '', due_days: 7 }); draw(); });
    modal({ title: t ? 'Edit checklist template' : 'New checklist template', body: box, large: true, actions: [{ label: 'Cancel' }, { label: 'Save template', primary: true, busyLabel: 'Saving…', handler: function () {
      host.querySelectorAll('.hrt-row').forEach(function (r) { var i = +r.getAttribute('data-i'); var ti = r.querySelector('[data-k="title"]'); tasks[i].title = ti.value.trim(); });
      var o = { name: val(box, 'name'), role: val(box, 'role'), tasks: tasks.filter(function (x) { return x.title; }) };
      return (t ? Api.put('/hr/onboarding-templates/' + t.id, o) : Api.post('/hr/onboarding-templates', o)).then(function () { saved('Checklist template saved.'); done(); }, function (e) { msg(box, errMsg(e)); return false; });
    } }] });
  }

  /** The checklist (managers from HR > Onboarding; the person from My HR). */
  async function openChecklist(id, done) {
    var r;
    try { r = await Api.get('/hr/onboarding/' + id); } catch (e) { return fail('Could not open the checklist', e); }
    var o = r.onboarding, mgr = r.can_manage, ch = r.choices || {};
    var box = el('<div class="hr-bar"><b data-no-i18n>' + esc(o.name) + '</b><span class="hr-sub">Started ' + esc(day(o.start_date)) + '</span>' + bar(o.percent, o.overdue) + '</div><div data-tasks></div>'
      + (mgr && o.status !== 'cancelled' ? '<div class="hr-bar" style="margin-top:8px;"><button type="button" class="hr-b" data-addtask>Add a task</button></div>' : ''));
    box.querySelector('[data-tasks]').innerHTML = taskList(o.tasks, mgr, o.status === 'active');
    modal({ title: 'Onboarding checklist', body: box, large: true, actions: [{ label: 'Close' }], onClose: function () { if (done) done(); } });
    wireTasks(box, function () { closeModal(); setTimeout(function () { openChecklist(id, done); }, 0); });
    var at = box.querySelector('[data-addtask]');
    if (at) at.addEventListener('click', function () {
      var t = { kind: 'custom' };
      var b2 = el('<div class="hr-g">' + field('Kind', '<select class="hr-i" data-f="kind" style="width:100%;">' + opts(ch.kinds || {}, 'custom') + '</select>') + field('Task', input('title', '')) + '<div data-ref></div>'
        + field('Due', input('due_date', addDays(today(), 7), 'date')) + field('Details (optional)', area('detail', '', 2), true) + '</div><div data-msg style="color:#B91C1C;font-size:13px;margin-top:6px;"></div>');
      var ref = b2.querySelector('[data-ref]');
      var drawRef = function () { t.kind = val(b2, 'kind'); ref.innerHTML = '<label class="hr-l">Which one</label>' + refPicker(ch, t.kind, {}); };
      b2.querySelector('[data-f="kind"]').addEventListener('change', drawRef); drawRef();
      modal({ title: 'Add a task', body: b2, actions: [{ label: 'Cancel' }, { label: 'Add', primary: true, busyLabel: 'Adding…', handler: function () {
        var ri = ref.querySelector('[data-k="ref_id"]'), rk = ref.querySelector('[data-k="ref_key"]');
        return Api.post('/hr/onboarding/' + id + '/tasks', { kind: t.kind, title: val(b2, 'title'), detail: val(b2, 'detail'), due_date: val(b2, 'due_date'), ref_id: ri && ri.value ? +ri.value : null, ref_key: rk && rk.value ? rk.value : null })
          .then(function () { saved('Task added.'); setTimeout(function () { openChecklist(id, done); }, 0); }, function (e) { msg(b2, errMsg(e)); return false; });
      } }] });
    });
  }

  var HOW = { auto: 'done automatically', manager: 'ticked by', self: 'ticked by' };
  function taskList(tasks, mgr, active) {
    if (!tasks.length) return '<div class="hr-sub">No tasks.</div>';
    return tasks.map(function (t) {
      var a = '';
      if (active && !t.done) {
        if (t.kind === 'document') a += '<button type="button" class="hr-b" data-up="' + t.id + '">' + (t.has_file ? 'Replace the file' : 'Upload') + '</button>';
        if (t.kind === 'policy') a += '<button type="button" class="hr-b" data-go="my-policies">Open Policies to review</button>';
        if (t.kind === 'certificate') a += '<button type="button" class="hr-b" data-go="my-certificates">Open My certificates</button>';
        if (t.kind === 'custom' || mgr) a += '<button type="button" class="hr-b' + (t.kind === 'custom' ? ' p' : '') + '" data-done="' + t.id + '">Mark done</button>';
      }
      if (t.has_file) a += '<button type="button" class="hr-b" data-tfile="' + t.id + '" data-name="' + esc(t.file_name || 'document') + '">Open the file</button>';
      if (t.done && mgr && active) a += '<button type="button" class="hr-b" data-undo="' + t.id + '">Reopen</button>';
      return '<div class="hrt-task"><span class="hrt-ck' + (t.done ? ' on' : (t.overdue ? ' late' : '')) + '">' + (t.done ? '✓' : '') + '</span><div class="t"><b data-no-i18n>' + esc(t.title) + '</b>'
        + '<div class="hr-sub">' + esc((({ document: 'Document', certificate: 'Certificate', policy: 'Policy', course: 'Course', custom: 'Task' })[t.kind]) || '')
        + (t.done ? ' · Done ' + esc(tsDay(t.done_at)) + (t.done_how === 'auto' ? ' (automatically)' : (t.done_by ? ' by <span data-no-i18n>' + esc(t.done_by) + '</span>' : ''))
          : (t.due_date ? ' · Due ' + esc(day(t.due_date)) + (t.overdue ? ' · <b style="color:#B45309;">overdue</b>' : '') : '')) + '</div>'
        + (t.detail ? '<div class="hr-sub" style="margin-top:2px;" data-no-i18n>' + esc(t.detail) + '</div>' : '')
        + (t.kind === 'course' && !t.done ? '<div class="hr-sub" style="margin-top:2px;">Finish the course under Courses on this page.</div>' : '')
        + (a ? '<div class="hrt-acts">' + a + '</div>' : '') + '</div></div>';
    }).join('');
  }

  function wireTasks(box, reload) {
    box.querySelectorAll('[data-done]').forEach(function (b) { b.addEventListener('click', async function () { b.disabled = true; try { await Api.post('/hr/onboarding-tasks/' + b.getAttribute('data-done') + '/done', {}); saved('Task done.'); reload(); } catch (e) { b.disabled = false; fail('Could not tick it off', e); } }); });
    box.querySelectorAll('[data-undo]').forEach(function (b) { b.addEventListener('click', async function () { try { await Api.post('/hr/onboarding-tasks/' + b.getAttribute('data-undo') + '/undo', {}); saved('Task reopened.'); reload(); } catch (e) { fail('Could not reopen it', e); } }); });
    box.querySelectorAll('[data-tfile]').forEach(function (b) { b.addEventListener('click', function () { blob('/hr/onboarding-tasks/' + b.getAttribute('data-tfile') + '/file', b.getAttribute('data-name')); }); });
    box.querySelectorAll('[data-go]').forEach(function (b) { b.addEventListener('click', function () { closeModal(); location.hash = '#' + b.getAttribute('data-go'); }); });
    box.querySelectorAll('[data-up]').forEach(function (b) {
      b.addEventListener('click', function () {
        var f = document.createElement('input'); f.type = 'file'; f.accept = '.pdf,.doc,.docx,.png,.jpg,.jpeg,.heic,.webp';
        f.addEventListener('change', async function () {
          if (!f.files[0]) return;
          var fd = new FormData(); fd.append('file', f.files[0]); b.disabled = true; b.textContent = 'Uploading…';
          try { await Api.postForm('/hr/onboarding-tasks/' + b.getAttribute('data-up') + '/upload', fd); saved('Document uploaded.'); reload(); } catch (e) { b.disabled = false; b.textContent = 'Upload'; fail('Could not upload it', e); }
        });
        f.click();
      });
    });
  }

  // ══ PERFORMANCE ══════════════════════════════════════════════════════════
  var PF = null;
  async function drawPerformance(host) {
    try { PF = await Api.get('/hr/performance'); } catch (e) { host.innerHTML = '<div class="hr-note">' + esc(errMsg(e)) + '</div>'; return; }
    if (!host.isConnected) return;
    var rows = PF.reviews || [];
    var h = '<div class="hr-bar"><button type="button" class="hr-b p" id="hp-new">Start a review</button></div>'
      + '<div class="kt-card">' + (rows.length ? '<table data-hrt="rev"><thead><tr><th>Person</th><th>Review</th><th>Reviewer</th><th>Due</th><th>Goals</th><th>Status</th><th></th></tr></thead><tbody>'
        + rows.map(function (r) {
          return '<tr data-id="' + r.id + '"><td><b data-no-i18n>' + esc(r.name) + '</b></td><td data-no-i18n>' + esc(r.title) + '</td><td data-no-i18n>' + esc(r.manager) + '</td>'
            + '<td style="white-space:nowrap;">' + esc(day(r.due_date)) + '</td><td>' + r.goals + '</td><td>' + reviewPill(r.status) + '</td>'
            + '<td style="text-align:center;white-space:nowrap;"><button type="button" data-ra="open" data-id="' + r.id + '">Open review</button>'
            + (r.status !== 'complete' ? '<button type="button" data-ra="cancel" data-id="' + r.id + '">Cancel review</button>' : '') + '</td></tr>';
        }).join('') + '</tbody></table>' : '<div style="padding:16px;" class="hr-sub">No reviews yet. Start a probation or annual review: the person writes a self review, the reviewer writes theirs, and you both sign.</div>') + '</div>';
    if (PF.can_templates) {
      var tp = PF.templates || [];
      h += '<div class="hrt-sec">Review templates</div><div class="hr-bar"><button type="button" class="hr-b" id="hp-tnew">New review template</button></div><div class="kt-card">'
        + (tp.length ? '<table data-hrt="rtpl"><thead><tr><th>Template</th><th>Sections</th><th>Rating scale</th><th>Status</th><th></th></tr></thead><tbody>' + tp.map(function (t) {
          return '<tr data-id="' + t.id + '"><td><b data-no-i18n>' + esc(t.name) + '</b></td><td>' + t.sections.length + '</td><td data-no-i18n>' + esc(t.scale.map(function (s) { return s.label; }).join(' · ')) + '</td>'
            + '<td>' + (t.archived ? pill('#F1F5F9', '#475569', 'Archived') : pill('#DCFCE7', '#166534', 'In use')) + '</td>'
            + '<td style="text-align:center;white-space:nowrap;"><button type="button" data-rt="edit" data-id="' + t.id + '">Edit</button><button type="button" data-rt="' + (t.archived ? 'restore' : 'archive') + '" data-id="' + t.id + '">' + (t.archived ? 'Restore' : 'Archive') + '</button></td></tr>';
        }).join('') + '</tbody></table>' : '<div style="padding:16px;" class="hr-sub">No templates yet: reviews use the standard one (four childcare sections, rated 1 to 5). Add your own to change the sections and the scale.</div>') + '</div>';
    }
    host.innerHTML = h;
    var redraw = function () { drawPerformance(host); };
    host.querySelector('#hp-new').addEventListener('click', function () { openNewReview(redraw); });
    var tn = host.querySelector('#hp-tnew'); if (tn) tn.addEventListener('click', function () { openReviewTemplate(null, redraw); });
    host.querySelectorAll('[data-ra]').forEach(function (b) {
      b.addEventListener('click', async function () {
        var id = +b.getAttribute('data-id');
        if (b.getAttribute('data-ra') === 'open') return openReview(id, redraw);
        var ok = await KT.confirm({ title: 'Cancel this review?', description: 'It stops here and no more reminders go out.', okLabel: 'Cancel review', cancelLabel: 'Keep it' });
        if (!ok) return;
        try { await Api.post('/hr/reviews/' + id + '/cancel', {}); saved('Review cancelled.'); redraw(); } catch (e) { fail('Could not cancel it', e); }
      });
    });
    host.querySelectorAll('[data-rt]').forEach(function (b) {
      b.addEventListener('click', async function () {
        var id = +b.getAttribute('data-id'), a = b.getAttribute('data-rt');
        if (a === 'edit') return openReviewTemplate((PF.templates || []).filter(function (t) { return t.id === id; })[0], redraw);
        try { await Api.post('/hr/review-templates/' + id + '/' + a, {}); saved(a === 'archive' ? 'Template archived.' : 'Template restored.'); redraw(); } catch (e) { fail('Could not change the template', e); }
      });
    });
  }

  function openNewReview(done) {
    var tpls = (PF.templates || []).filter(function (t) { return !t.archived; });
    var y = today().slice(0, 4);
    var box = el('<div class="hr-g">' + field('Person', select('user_id', list(PF.people, '', 'Choose…'))) + field('Reviewer', select('manager_user_id', list(PF.managers, '', 'Choose…')))
      + field('Cycle', select('cycle', opts(PF.cycles || {}, 'annual'))) + field('Template', select('template_id', list(tpls, '', 'Standard review')))
      + field('Title (optional)', input('title', '')) + field('Due', input('due_date', addDays(today(), 21), 'date'))
      + field('Period from', input('period_start', y + '-01-01', 'date')) + field('Period to', input('period_end', today(), 'date')) + '</div>'
      + '<div class="hr-sub" style="margin-top:6px;">Nobody reviews themselves: the reviewer has to be someone else.</div><div data-msg style="color:#B91C1C;font-size:13px;margin-top:6px;"></div>');
    var us = box.querySelector('[data-f="user_id"]'), ms = box.querySelector('[data-f="manager_user_id"]');
    us.addEventListener('change', function () {
      var p = (PF.people || []).filter(function (x) { return String(x.id) === us.value; })[0];
      Array.prototype.forEach.call(ms.options, function (o) { o.disabled = o.value && o.value === us.value; });
      if (p && p.manager_user_id && String(p.manager_user_id) !== us.value) ms.value = String(p.manager_user_id);
    });
    modal({ title: 'Start a review', body: box, actions: [{ label: 'Cancel' }, { label: 'Start the review', primary: true, busyLabel: 'Starting…', handler: function () {
      var o = { user_id: +val(box, 'user_id') || 0, manager_user_id: +val(box, 'manager_user_id') || 0, cycle: val(box, 'cycle'), template_id: val(box, 'template_id') ? +val(box, 'template_id') : null,
        title: val(box, 'title'), due_date: val(box, 'due_date'), period_start: val(box, 'period_start'), period_end: val(box, 'period_end') };
      return Api.post('/hr/reviews', o).then(function () { saved('Review started. They have been told to add their self review.'); done(); }, function (e) { msg(box, errMsg(e)); return false; });
    } }] });
  }

  function openReviewTemplate(t, done) {
    var sc = t ? t.scale : (PF.default_scale || []), se = t ? t.sections : (PF.default_sections || []);
    var box = el(field('Template name', input('name', t ? t.name : 'Annual review')) + '<div class="hrt-two" style="margin-top:10px;">'
      + field('Rating scale, lowest first (one per line)', area('scale', sc.map(function (s) { return s.label; }).join('\n'), 6))
      + field('Sections (one per line; add " | a prompt" for a question under it)', area('sections', se.map(function (s) { return s.title + (s.prompt ? ' | ' + s.prompt : ''); }).join('\n'), 6)) + '</div>'
      + '<div data-msg style="color:#B91C1C;font-size:13px;margin-top:6px;"></div>');
    modal({ title: t ? 'Edit review template' : 'New review template', body: box, large: true, actions: [{ label: 'Cancel' }, { label: 'Save template', primary: true, busyLabel: 'Saving…', handler: function () {
      var lines = function (k) { return String(val(box, k) || '').split('\n').map(function (x) { return x.trim(); }).filter(Boolean); };
      var o = { name: val(box, 'name'), scale: lines('scale').map(function (l) { return { label: l }; }),
        sections: lines('sections').map(function (l) { var p = l.split('|'); return { title: p[0].trim(), prompt: (p.slice(1).join('|') || '').trim() }; }) };
      return (t ? Api.put('/hr/review-templates/' + t.id, o) : Api.post('/hr/review-templates', o)).then(function () { saved('Review template saved.'); done(); }, function (e) { msg(box, errMsg(e)); return false; });
    } }] });
  }

  function answersHtml(scale, sections, ans, editable, part) {
    ans = ans || { sections: [], overall: '' };
    return sections.map(function (s, i) {
      var a = (ans.sections || [])[i] || {};
      var rate = editable ? '<div class="hrt-rate">' + scale.map(function (x) { return '<label><input type="radio" name="r-' + part + '-' + i + '" value="' + x.value + '"' + (+a.rating === +x.value ? ' checked' : '') + '> ' + esc(x.value + ' · ' + x.label) + '</label>'; }).join('') + '</div>'
        : (a.rating ? '<div class="hr-sub" style="margin:4px 0;">Rating: <b>' + esc(a.rating + ' · ' + ((scale.filter(function (x) { return +x.value === +a.rating; })[0] || {}).label || '')) + '</b></div>' : '');
      return '<div style="margin-bottom:10px;"><div style="font-weight:700;font-size:13px;" data-no-i18n>' + esc(s.title) + '</div>' + rate
        + (editable ? '<textarea class="hr-i" data-c="' + i + '" rows="3" style="width:100%;min-height:66px;">' + esc(a.comment || '') + '</textarea>' : (a.comment ? '<div class="hrt-ro" data-no-i18n>' + esc(a.comment) + '</div>' : '<span class="hr-sub">No comment.</span>')) + '</div>';
    }).join('') + '<div style="font-weight:700;font-size:13px;">Overall</div>'
      + (editable ? '<textarea class="hr-i" data-overall rows="4" style="width:100%;min-height:88px;">' + esc(ans.overall || '') + '</textarea>' : (ans.overall ? '<div class="hrt-ro" data-no-i18n>' + esc(ans.overall) + '</div>' : '<span class="hr-sub">—</span>'));
  }

  function collect(box, sections, part) {
    return { sections: sections.map(function (s, i) {
      var r = box.querySelector('input[name="r-' + part + '-' + i + '"]:checked'), c = box.querySelector('[data-part="' + part + '"] [data-c="' + i + '"]');
      return { rating: r ? +r.value : null, comment: c ? c.value : '' };
    }), overall: (box.querySelector('[data-part="' + part + '"] [data-overall]') || {}).value || '' };
  }

  /** A review: the self part, the manager part, goals and signatures. Opened by both people and by managers in scope. */
  async function openReview(id, done) {
    var r;
    try { r = await Api.get('/hr/reviews/' + id); } catch (e) { return fail('Could not open the review', e); }
    var v = r.review, can = r.can, scale = v.scale, secs = v.sections;
    var mine = can.self ? 'self' : (can.manager ? 'manager' : null);
    var selfBox = '<div class="hrt-sect" data-part="self"><h5>Self review · <span data-no-i18n>' + esc(v.name) + '</span></h5><div class="hr-sub" style="margin-bottom:8px;">'
      + (v.self_submitted_at ? 'Submitted ' + esc(tsDay(v.self_submitted_at)) : 'Not submitted yet') + '</div>'
      + (can.self ? answersHtml(scale, secs, v.self, true, 'self') : (v.self ? answersHtml(scale, secs, v.self, false, 'self') : '<span class="hr-sub">' + (v.self_submitted_at ? '' : 'The self review shows here once it is submitted.') + '</span>')) + '</div>';
    var mgrBox = '<div class="hrt-sect" data-part="manager"><h5>Manager review · <span data-no-i18n>' + esc(v.manager) + '</span></h5><div class="hr-sub" style="margin-bottom:8px;">'
      + (v.manager_submitted_at ? 'Submitted ' + esc(tsDay(v.manager_submitted_at)) : 'Not submitted yet') + '</div>'
      + (can.manager ? answersHtml(scale, secs, v.manager_part, true, 'manager') : (v.manager_part ? answersHtml(scale, secs, v.manager_part, false, 'manager') : '<span class="hr-sub">The manager review shows here once it is submitted.</span>')) + '</div>';
    var sig = '<div class="hrt-sect"><h5>Sign-off</h5><div class="hr-sub">' + (v.employee_signed_at ? 'Signed by <span data-no-i18n>' + esc(v.employee_signed_name) + '</span> on ' + esc(tsDay(v.employee_signed_at)) : 'Employee: not signed yet') + '<br>'
      + (v.manager_signed_at ? 'Signed by <span data-no-i18n>' + esc(v.manager_signed_name) + '</span> on ' + esc(tsDay(v.manager_signed_at)) : 'Reviewer: not signed yet') + '</div>'
      + (can.sign ? '<div class="hr-bar" style="margin-top:8px;"><input class="hr-i" data-sign placeholder="Type your full name to sign" style="flex:1;min-width:200px;"><button type="button" class="hr-b p" data-dosign>Sign</button></div>' : '') + '</div>';
    var goals = '<div class="hrt-sect"><h5>Goals</h5><div data-goals>' + goalsHtml(r.goals) + '</div><div class="hrt-acts" style="margin-top:8px;"><button type="button" class="hr-b" data-goal>Add a goal</button>'
      + (can.ai ? '<button type="button" class="hr-b" data-aigoals>✨ Suggest goals</button>' : '') + '</div></div>';
    var box = el('<div class="hr-bar"><b data-no-i18n>' + esc(v.title) + '</b>' + reviewPill(v.status) + (v.due_date ? '<span class="hr-sub">Due ' + esc(day(v.due_date)) + '</span>' : '')
      + (v.period_start ? '<span class="hr-sub">Covers ' + esc(day(v.period_start)) + ' to ' + esc(day(v.period_end)) + '</span>' : '') + '</div>'
      + (mine && can.ai ? '<div class="hr-bar"><button type="button" class="hr-b" data-aidraft>✨ Draft my comments</button><span class="hr-sub">A starting point from the records: read it and make it yours.</span></div>' : '')
      + '<div class="hrt-two">' + selfBox + mgrBox + '</div>' + goals + sig + '<div data-msg style="color:#B91C1C;font-size:13px;margin-top:6px;"></div>');
    var reopen = function () { closeModal(); setTimeout(function () { openReview(id, done); }, 0); };
    var actions = [{ label: 'Close' }];
    if (mine) {
      var path = '/hr/reviews/' + id + '/' + mine;
      actions.push({ label: 'Save draft', busyLabel: 'Saving…', handler: function () { return Api.put(path, { answers: collect(box, secs, mine), submit: false }).then(function () { saved('Draft saved.'); return false; }, function (e) { msg(box, errMsg(e)); return false; }); } });
      actions.push({ label: 'Submit', primary: true, busyLabel: 'Submitting…', handler: function () {
        return Api.put(path, { answers: collect(box, secs, mine), submit: true }).then(function () { saved(mine === 'self' ? 'Self review submitted. Your reviewer has been told.' : 'Review submitted. They can read it and sign now.'); if (done) done(); },
          function (e) { msg(box, errMsg(e)); return false; });
      } });
    }
    modal({ title: 'Performance review', body: box, large: true, actions: actions, onClose: null });
    var sb = box.querySelector('[data-dosign]');
    if (sb) sb.addEventListener('click', async function () {
      var n = box.querySelector('[data-sign]').value.trim(); sb.disabled = true;
      try { await Api.post('/hr/reviews/' + id + '/sign', { name: n }); saved('Signed.'); if (done) done(); reopen(); } catch (e) { sb.disabled = false; msg(box, errMsg(e)); }
    });
    box.querySelector('[data-goal]').addEventListener('click', function () { openGoal(null, v.user_id, id, reopen); });
    wireGoals(box, reopen);
    var ag = box.querySelector('[data-aigoals]');
    if (ag) ag.addEventListener('click', async function () {
      ag.disabled = true; ag.textContent = 'Thinking…';
      try {
        var d = await Api.post('/hr/ai/draft', { user_id: v.user_id, kind: 'goals' });
        var lines = String(d.text || '').split('\n').map(function (x) { return x.replace(/^\s*[-*•\d.]+\s*/, '').trim(); }).filter(Boolean).slice(0, 5);
        var pick = el('<div class="hr-sub" style="margin-bottom:6px;">Tick the ones to add. You can edit them after.</div><div class="hrt-pick">' + lines.map(function (l, i) { return '<label><input type="checkbox" data-g="' + i + '" checked> <span data-no-i18n>' + esc(l) + '</span></label>'; }).join('') + '</div>');
        modal({ title: 'Suggested goals', body: pick, actions: [{ label: 'Cancel', handler: function () { setTimeout(reopen, 0); } }, { label: 'Add the ticked goals', primary: true, busyLabel: 'Adding…', handler: async function () {
          var chosen = lines.filter(function (l, i) { var c = pick.querySelector('[data-g="' + i + '"]'); return c && c.checked; });
          for (var i = 0; i < chosen.length; i++) { try { await Api.post('/hr/goals', { user_id: v.user_id, review_id: id, title: chosen[i].slice(0, 200) }); } catch (e) { fail('Could not add a goal', e); break; } }
          saved(chosen.length + ' goal(s) added.'); setTimeout(reopen, 0);
        } }] });
      } catch (e) { ag.disabled = false; ag.textContent = '✨ Suggest goals'; fail('No suggestions', e); }
    });
    var ad = box.querySelector('[data-aidraft]');
    if (ad) ad.addEventListener('click', async function () {
      ad.disabled = true; ad.textContent = 'Drafting…';
      try {
        var d = await Api.post('/hr/ai/draft', { user_id: v.user_id, kind: 'review', review_id: id, part: mine });
        var text = String(d.text || ''), part = box.querySelector('[data-part="' + mine + '"]');
        secs.forEach(function (s, i) {
          var re = new RegExp('(^|\\n)\\s*\\**' + s.title.replace(/[.*+?^${}()|[\]\\]/g, '\\$&') + '\\**\\s*:\\s*([\\s\\S]*?)(?=\\n\\s*\\**[A-Z][^\\n:]{2,60}\\**\\s*:|$)', 'i');
          var m = re.exec(text), c = part.querySelector('[data-c="' + i + '"]');
          if (m && c && !c.value.trim()) c.value = m[2].trim();
        });
        toast('✨', 'Draft added', 'Read it and make it yours before you submit.', '#0E7490');
      } catch (e) { fail('No draft', e); }
      ad.disabled = false; ad.textContent = '✨ Draft my comments';
    });
  }

  function goalsHtml(goals) {
    if (!goals || !goals.length) return '<span class="hr-sub">No goals yet.</span>';
    goals.forEach(function (g) { GOALS_CACHE[g.id] = g; });   // Edit needs the full record
    return goals.map(function (g) {
      var last = (g.checkins || [])[0];
      return '<div class="hrt-task"><div class="t"><b data-no-i18n>' + esc(g.title) + '</b> ' + goalPill(g.status) + ' ' + bar(g.progress, g.status === 'at_risk')
        + '<div class="hr-sub">' + (g.due_date ? 'Due ' + esc(day(g.due_date)) : 'No due date') + (last ? ' · Last check-in ' + esc(tsDay(last.at)) + (last.by ? ' by <span data-no-i18n>' + esc(last.by) + '</span>' : '') : '') + '</div>'
        + (last ? '<div class="hr-sub" style="margin-top:2px;" data-no-i18n>“' + esc(last.note) + '”</div>' : '')
        + '<div class="hrt-acts"><button type="button" class="hr-b" data-check="' + g.id + '" data-st="' + g.status + '" data-pr="' + g.progress + '">Check in</button>'
        + '<button type="button" class="hr-b" data-gedit="' + g.id + '">Edit</button></div></div></div>';
    }).join('');
  }

  function wireGoals(box, reload) {
    box.querySelectorAll('[data-check]').forEach(function (b) {
      b.addEventListener('click', function () {
        var c = el('<div class="hr-g">' + field('Status', select('status', opts(GOAL_LBL, b.getAttribute('data-st')))) + field('Progress (%)', input('progress', b.getAttribute('data-pr'), 'number', ' min="0" max="100" step="5"'))
          + field('How is it going?', area('note', '', 3), true) + '</div><div data-msg style="color:#B91C1C;font-size:13px;margin-top:6px;"></div>');
        modal({ title: 'Goal check-in', body: c, actions: [{ label: 'Cancel', handler: function () { setTimeout(reload, 0); } }, { label: 'Save check-in', primary: true, busyLabel: 'Saving…', handler: function () {
          return Api.post('/hr/goals/' + b.getAttribute('data-check') + '/checkins', { status: val(c, 'status'), progress: +val(c, 'progress') || 0, note: val(c, 'note') })
            .then(function () { saved('Check-in saved.'); setTimeout(reload, 0); }, function (e) { msg(c, errMsg(e)); return false; });
        } }] });
      });
    });
    box.querySelectorAll('[data-gedit]').forEach(function (b) {
      b.addEventListener('click', function () { var g = GOALS_CACHE[b.getAttribute('data-gedit')]; if (g) openGoal(g, g.user_id, g.review_id, reload); });
    });
  }

  function openGoal(g, userId, reviewId, done) {
    var c = el('<div class="hr-g">' + field('Goal', input('title', g && g.title, 'text', ' maxlength="200"'), true) + field('Due', input('due_date', g ? g.due_date : addDays(today(), 90), 'date'))
      + (g ? field('Status', select('status', opts(GOAL_LBL, g.status))) : '') + field('Details (optional)', area('detail', g && g.detail, 3), true) + '</div><div data-msg style="color:#B91C1C;font-size:13px;margin-top:6px;"></div>');
    modal({ title: g ? 'Edit goal' : 'Add a goal', body: c, actions: [{ label: 'Cancel', handler: function () { setTimeout(done, 0); } }, { label: 'Save goal', primary: true, busyLabel: 'Saving…', handler: function () {
      var o = { title: val(c, 'title'), due_date: val(c, 'due_date'), detail: val(c, 'detail') };
      var p = g ? Api.patch('/hr/goals/' + g.id, Object.assign(o, { status: val(c, 'status') })) : Api.post('/hr/goals', Object.assign(o, { user_id: userId, review_id: reviewId || null }));
      return p.then(function () { saved('Goal saved.'); setTimeout(done, 0); }, function (e) { msg(c, errMsg(e)); return false; });
    } }] });
  }

  // ══ COURSES ══════════════════════════════════════════════════════════════
  var CO = null;
  async function drawCourses(host) {
    try { CO = await Api.get('/hr/courses'); } catch (e) { host.innerHTML = '<div class="hr-note">' + esc(errMsg(e)) + '</div>'; return; }
    if (!host.isConnected) return;
    var rows = CO.courses || [], kinds = CO.kinds || {};
    var h = (CO.can_manage ? '<div class="hr-bar"><button type="button" class="hr-b p" id="hc-new">New course</button></div>' : '')
      + '<div class="kt-card">' + (rows.length ? '<table data-hrt="courses"><thead><tr><th>Course</th><th>Content</th><th>PD hours</th><th>Assigned</th><th>Completed</th><th>Overdue</th><th>Status</th><th></th></tr></thead><tbody>'
        + rows.map(function (c) {
          return '<tr data-id="' + c.id + '"><td><b data-no-i18n>' + esc(c.title) + '</b></td><td>' + esc(kinds[c.content_kind] || '') + '</td>'
            + '<td>' + (c.counts_pd ? esc(c.pd_hours) : '<span class="hr-sub">—</span>') + '</td><td>' + c.assigned + '</td>'
            + '<td style="white-space:nowrap;">' + c.completed + (c.assigned ? ' ' + bar(Math.round(c.completed * 100 / c.assigned)) : '') + '</td>'
            + '<td>' + (c.overdue ? pill('#FEF3C7', '#92400E', c.overdue + ' overdue') : '<span class="hr-sub">—</span>') + '</td>'
            + '<td>' + (c.archived ? pill('#F1F5F9', '#475569', 'Archived') : pill('#DCFCE7', '#166534', 'In use')) + '</td>'
            + '<td style="text-align:center;white-space:nowrap;"><button type="button" data-ca="who" data-id="' + c.id + '">See who has it</button>'
            + (!c.archived ? '<button type="button" data-ca="assign" data-id="' + c.id + '">Assign</button>' : '')
            + (CO.can_manage ? '<button type="button" data-ca="edit" data-id="' + c.id + '">Edit</button><button type="button" data-ca="' + (c.archived ? 'restore' : 'archive') + '" data-id="' + c.id + '">' + (c.archived ? 'Restore' : 'Archive') + '</button>' : '') + '</td></tr>';
        }).join('') + '</tbody></table>' : '<div style="padding:16px;" class="hr-sub">No courses yet.' + (CO.can_manage ? ' Add a link, a video, a file or one of KiddieTrac\'s training videos, then assign it.' : ' Your agency admin adds them.') + '</div>') + '</div>'
      + '<div class="hr-sub" style="margin-top:8px;">Completing a course issues a certificate. A course set to count toward PD hours adds them to the person\'s professional development hours.</div>';
    host.innerHTML = h;
    var redraw = function () { drawCourses(host); };
    var nb = host.querySelector('#hc-new'); if (nb) nb.addEventListener('click', function () { openCourse(null, redraw); });
    host.querySelectorAll('[data-ca]').forEach(function (b) {
      b.addEventListener('click', async function () {
        var id = +b.getAttribute('data-id'), a = b.getAttribute('data-ca'), c = rows.filter(function (x) { return x.id === id; })[0];
        if (a === 'who') return openAssignments(c, redraw);
        if (a === 'assign') return openAssign(c, redraw);
        if (a === 'edit') return openCourse(c, redraw);
        try { await Api.post('/hr/courses/' + id + '/' + a, {}); saved(a === 'archive' ? 'Course archived.' : 'Course restored.'); redraw(); } catch (e) { fail('Could not change the course', e); }
      });
    });
  }

  function openCourse(c, done) {
    var kinds = CO.kinds || {};
    var box = el('<div class="hr-g">' + field('Course title', input('title', c && c.title, 'text', ' maxlength="160"'), true)
      + field('Content', select('content_kind', opts(kinds, (c && c.content_kind) || 'link'))) + '<div data-src></div>'
      + field('PD hours', input('pd_hours', c && c.pd_hours, 'number', ' min="0.25" max="100" step="0.25"'))
      + '<label style="display:flex;gap:7px;align-items:center;font-size:13px;"><input type="checkbox" data-f="counts_pd"' + (c && c.counts_pd ? ' checked' : '') + '> Counts toward PD hours</label>'
      + '<label style="display:flex;gap:7px;align-items:center;font-size:13px;"><input type="checkbox" data-f="needs_ack"' + (!c || c.needs_ack ? ' checked' : '') + '> They type their name to confirm they understood it</label>'
      + field('Description (optional)', area('description', c && c.description, 4), true) + '</div><div data-msg style="color:#B91C1C;font-size:13px;margin-top:6px;"></div>');
    var src = box.querySelector('[data-src]'), kindSel = box.querySelector('[data-f="content_kind"]'), fileIn = null;
    var drawSrc = function () {
      var k = kindSel.value;
      if (k === 'library') src.innerHTML = field('Training video', select('training_video_id', list(CO.videos, c && c.training_video_id, 'Choose…', 'title')));
      else if (k === 'file') { src.innerHTML = field('File', '<input type="file" data-file class="hr-i" style="width:100%;padding-top:3px;" accept=".pdf,.doc,.docx,.ppt,.pptx,.mp4,.m4v,.mov,.png,.jpg,.jpeg">' + (c && c.file_name ? '<div class="hr-sub" data-no-i18n>Now: ' + esc(c.file_name) + '</div>' : '')); fileIn = src.querySelector('[data-file]'); }
      else src.innerHTML = field(k === 'video' ? 'Video link' : 'Link', input('url', c && c.url, 'url', ' placeholder="https://"'));
    };
    kindSel.addEventListener('change', drawSrc); drawSrc();
    modal({ title: c ? 'Edit course' : 'New course', body: box, large: true, actions: [{ label: 'Cancel' }, { label: 'Save course', primary: true, busyLabel: 'Saving…', handler: async function () {
      var k = val(box, 'content_kind'), f = fileIn && fileIn.files && fileIn.files[0];
      if (k === 'file' && !f && !(c && c.file_name)) { msg(box, 'Choose the file to upload.'); return false; }
      var o = { title: val(box, 'title'), content_kind: k === 'file' ? 'file' : k, url: val(box, 'url'), training_video_id: val(box, 'training_video_id') ? +val(box, 'training_video_id') : null,
        pd_hours: val(box, 'pd_hours'), counts_pd: val(box, 'counts_pd'), needs_ack: val(box, 'needs_ack'), description: val(box, 'description') };
      try {
        var id = c ? c.id : null;
        if (c) await Api.patch('/hr/courses/' + c.id, o); else id = (await Api.post('/hr/courses', o)).id;
        if (f) { var fd = new FormData(); fd.append('file', f); await Api.postForm('/hr/courses/' + id + '/file', fd); }
        saved(c ? 'Course updated.' : 'Course added. Assign it to people or a role.'); done();
      } catch (e) { msg(box, errMsg(e)); return false; }
    } }] });
  }

  function openAssign(c, done) {
    var roles = CO.roles || {};
    var box = el(field('Due', input('due_date', addDays(today(), 30), 'date')) + '<div class="hrt-sec" style="margin-top:10px;">Everyone in a role</div><div class="hrt-pick" style="max-height:none;">'
      + Object.keys(roles).map(function (k) { return '<label><input type="checkbox" data-role="' + k + '"> ' + esc(roles[k]) + '</label>'; }).join('') + '</div>'
      + '<div class="hr-sub" style="margin-top:4px;">The people in the role today. Someone who joins later is assigned by hand (or from their onboarding checklist).</div>'
      + '<div class="hrt-sec">Or choose people</div><input class="hr-i" data-q placeholder="Search names" style="width:100%;margin-bottom:6px;"><div class="hrt-pick">'
      + (CO.people || []).map(function (p) { return '<label data-name="' + esc(p.name.toLowerCase()) + '"><input type="checkbox" data-user="' + p.id + '"> <span data-no-i18n>' + esc(p.name) + '</span></label>'; }).join('') + '</div>'
      + '<div data-msg style="color:#B91C1C;font-size:13px;margin-top:6px;"></div>');
    var q = box.querySelector('[data-q]');
    q.addEventListener('input', function () { var s = q.value.trim().toLowerCase(); box.querySelectorAll('[data-name]').forEach(function (l) { l.style.display = !s || l.getAttribute('data-name').indexOf(s) >= 0 ? '' : 'none'; }); });
    modal({ title: 'Assign: ' + c.title, body: box, large: true, actions: [{ label: 'Cancel' }, { label: 'Assign', primary: true, busyLabel: 'Assigning…', handler: function () {
      var o = { due_date: val(box, 'due_date'), roles: [], user_ids: [] };
      box.querySelectorAll('[data-role]:checked').forEach(function (x) { o.roles.push(x.getAttribute('data-role')); });
      box.querySelectorAll('[data-user]:checked').forEach(function (x) { o.user_ids.push(+x.getAttribute('data-user')); });
      return Api.post('/hr/courses/' + c.id + '/assign', o).then(function (r) { saved(r.assigned + ' assigned' + (r.already ? ', ' + r.already + ' already had it' : '') + '.'); done(); }, function (e) { msg(box, errMsg(e)); return false; });
    } }] });
  }

  async function openAssignments(c, done) {
    var r;
    try { r = await Api.get('/hr/courses/' + c.id + '/assignments'); } catch (e) { return fail('Could not load the course', e); }
    var rows = r.assignments || [];
    var open = rows.filter(function (a) { return !a.completed_at; });
    var box = el((open.length > 1 ? '<div class="hr-bar"><button type="button" class="hr-b" data-remindall>Remind everyone not done (' + open.length + ')</button></div>' : '')
      + (rows.length ? rows.map(function (a) {
        return '<div class="hrt-task"><span class="hrt-ck' + (a.completed_at ? ' on' : (a.overdue ? ' late' : '')) + '">' + (a.completed_at ? '✓' : '') + '</span><div class="t"><b data-no-i18n>' + esc(a.name) + '</b> '
          + (a.completed_at ? pill('#DCFCE7', '#166534', 'Completed ' + tsDay(a.completed_at)) : (a.overdue ? pill('#FEF3C7', '#92400E', 'Overdue') : pill('#E0F2FE', '#075985', 'To do')))
          + '<div class="hr-sub">' + (a.due_date ? 'Due ' + esc(day(a.due_date)) : 'No due date') + (a.certificate_no ? ' · Certificate <span data-no-i18n>' + esc(a.certificate_no) + '</span>' : '') + '</div>'
          + '<div class="hrt-acts">' + (a.completed_at ? '<button type="button" class="hr-b" data-xa="cert" data-id="' + a.id + '">Download certificate</button>'
            : '<button type="button" class="hr-b" data-xa="remind" data-id="' + a.id + '">Send a reminder</button><button type="button" class="hr-b" data-xa="remove" data-id="' + a.id + '">Remove</button>') + '</div></div></div>';
      }).join('') : '<div class="hr-sub">Nobody has this course yet.</div>'));
    modal({ title: c.title, body: box, large: true, actions: [{ label: 'Close' }, { label: 'Assign more', primary: true, handler: function () { setTimeout(function () { openAssign(c, done); }, 0); } }], onClose: null });
    var reopen = function () { closeModal(); done(); setTimeout(function () { openAssignments(c, done); }, 0); };
    var remind = async function (ids) { try { var x = await Api.post('/hr/course-assignments/remind', { ids: ids }); toast('🔔', 'Reminder sent', x.reminded + ' reminded.', '#0E7490'); } catch (e) { fail('Could not send it', e); } };
    box.querySelectorAll('[data-xa]').forEach(function (b) {
      b.addEventListener('click', async function () {
        var id = +b.getAttribute('data-id'), a = b.getAttribute('data-xa');
        if (a === 'cert') return blob('/hr/course-assignments/' + id + '/certificate', 'certificate.pdf');
        if (a === 'remind') return remind([id]);
        var ok = await KT.confirm({ title: 'Remove this assignment?', description: 'They will no longer have this course to do.', okLabel: 'Remove' });
        if (!ok) return;
        try { await Api.delete('/hr/course-assignments/' + id); saved('Assignment removed.'); reopen(); } catch (e) { fail('Could not remove it', e); }
      });
    });
    var ra = box.querySelector('[data-remindall]');
    if (ra) ra.addEventListener('click', function () { remind(open.map(function (a) { return a.id; })); });
  }

  // ══ MY COURSES (the person) ══════════════════════════════════════════════
  async function openMyCourse(a, reload) {
    var r;
    if (a.kind === 'file') {
      await blob('/hr/course-assignments/' + a.id + '/open', a.title, true);
    } else {
      try { r = await Api.get('/hr/course-assignments/' + a.id + '/open'); } catch (e) { return fail('Could not open the course', e); }
    }
    var body = '';
    if (r) {
      if (r.kind === 'library' && r.video) body = '<video controls playsinline style="width:100%;max-height:60vh;border-radius:10px;background:#000;"' + (r.video.poster ? ' poster="' + esc(r.video.poster) + '"' : '') + ' src="' + esc(r.video.url) + '">'
        + (r.video.captions ? '<track kind="captions" src="' + esc(r.video.captions) + '" default>' : '') + '</video>';
      else if (r.url) body = '<div class="hr-bar"><a class="hr-b p" style="display:inline-flex;align-items:center;text-decoration:none;" href="' + esc(r.url) + '" target="_blank" rel="noopener">Open the course</a><span class="hr-sub">It opens in a new tab. Come back here to mark it complete.</span></div>';
      if (r.description) body += '<div class="hrt-ro" style="margin-top:8px;" data-no-i18n>' + esc(r.description) + '</div>';
    } else body = '<div class="hr-sub">The course file opened in a new tab. Come back here to mark it complete.</div>';
    body += a.completed_at ? '<div class="hr-note" style="margin-top:10px;">Completed ' + esc(tsDay(a.completed_at)) + '. Certificate ' + esc(a.certificate_no || '') + '.</div>'
      : '<div class="hrt-sect" style="margin-top:10px;"><h5>Finished?</h5>' + (a.needs_ack ? '<div class="hr-sub" style="margin-bottom:6px;">Type your name to confirm you completed this course and understood it.</div><input class="hr-i" data-ack style="width:100%;" placeholder="Your full name">' : '<div class="hr-sub">Mark it complete to get your certificate.</div>') + '</div>';
    var box = el(body + '<div data-msg style="color:#B91C1C;font-size:13px;margin-top:6px;"></div>');
    var acts = [{ label: 'Close' }];
    if (a.completed_at) acts.push({ label: 'Download certificate', primary: true, handler: function () { blob('/hr/course-assignments/' + a.id + '/certificate', 'certificate.pdf'); return false; } });
    else acts.push({ label: 'Mark complete', primary: true, busyLabel: 'Saving…', handler: function () {
      var n = box.querySelector('[data-ack]');
      return Api.post('/hr/course-assignments/' + a.id + '/complete', { name: n ? n.value.trim() : '' }).then(function (x) {
        toast('🎓', 'Course complete', 'Certificate ' + (x.certificate_no || '') + (x.pd_hours ? ' · ' + x.pd_hours + ' PD hours added' : ''), '#16A34A'); if (reload) reload();
      }, function (e) { msg(box, errMsg(e)); return false; });
    } });
    modal({ title: (r && r.title) || a.title, body: box, large: true, actions: acts });
  }

  // ══ HR PROFILE CARDS + MY HR ═════════════════════════════════════════════
  function cards(d, uid, own, reload) {
    var onb = (d.onboarding || []).filter(function (o) { return o.status !== 'cancelled'; })[0];
    var h = '<div class="hr-pf">';
    h += '<div class="hr-pc"><h4>Onboarding</h4>' + (onb ? '<div>' + bar(onb.percent, onb.overdue) + ' <span class="hr-sub">' + onb.done + ' of ' + onb.total + ' done' + (onb.overdue ? ' · ' + onb.overdue + ' overdue' : '') + '</span></div>'
      + '<div class="hr-sub" style="margin-top:4px;">' + esc(onb.status === 'complete' ? 'Complete' : 'Started ' + day(onb.start_date)) + '</div>'
      + '<div style="margin-top:6px;"><button type="button" class="hr-link" data-onb="' + onb.id + '">Open checklist</button></div>' : '<span class="hr-sub">No checklist.</span>') + '</div>';
    h += '<div class="hr-pc"><h4>Reviews</h4>' + ((d.reviews || []).length ? d.reviews.slice(0, 4).map(function (r) {
      return '<div style="display:flex;justify-content:space-between;gap:8px;padding:3px 0;font-size:13px;"><button type="button" class="hr-link" data-rev="' + r.id + '" data-no-i18n>' + esc(r.title) + '</button>' + reviewPill(r.status) + '</div>';
    }).join('') : '<span class="hr-sub">No reviews.</span>') + '</div>';
    h += '<div class="hr-pc"><h4>Goals</h4>' + ((d.goals || []).length ? d.goals.slice(0, 5).map(function (g) {
      return '<div style="display:flex;justify-content:space-between;gap:8px;padding:3px 0;font-size:13px;"><span data-no-i18n>' + esc(g.title) + '</span>' + goalPill(g.status) + '</div>';
    }).join('') : '<span class="hr-sub">No goals.</span>') + '<div style="margin-top:6px;"><button type="button" class="hr-link" data-goals>Goals and one-to-ones</button></div></div>';
    h += '<div class="hr-pc"><h4>Courses</h4>' + ((d.courses || []).length ? d.courses.slice(0, 6).map(function (c) {
      return '<div style="display:flex;justify-content:space-between;gap:8px;padding:3px 0;font-size:13px;">' + (own ? '<button type="button" class="hr-link" data-course="' + c.id + '" data-no-i18n>' + esc(c.title) + '</button>' : '<span data-no-i18n>' + esc(c.title) + '</span>')
        + (c.completed_at ? pill('#DCFCE7', '#166534', 'Done') : (c.overdue ? pill('#FEF3C7', '#92400E', 'Overdue') : pill('#E0F2FE', '#075985', c.due_date ? 'Due ' + day(c.due_date).replace(/, \d{4}$/, '') : 'To do'))) + '</div>';
    }).join('') : '<span class="hr-sub">No courses.</span>') + '</div>';
    return h + '</div>';
  }

  function wireCards(host, d, uid, own, reload) {
    host.querySelectorAll('[data-onb]').forEach(function (b) { b.addEventListener('click', function () { openChecklist(+b.getAttribute('data-onb'), reload); }); });
    host.querySelectorAll('[data-rev]').forEach(function (b) { b.addEventListener('click', function () { openReview(+b.getAttribute('data-rev'), reload); }); });
    host.querySelectorAll('[data-course]').forEach(function (b) { b.addEventListener('click', function () { var a = (d.courses || []).filter(function (x) { return String(x.id) === b.getAttribute('data-course'); })[0]; if (a) openMyCourse(a, reload); }); });
    host.querySelectorAll('[data-goals]').forEach(function (b) { b.addEventListener('click', function () { openGoalsAndNotes(uid, own, reload); }); });
  }

  /** Goals (all, not just a review's) and the one-to-one notes of one person. */
  async function openGoalsAndNotes(uid, own, done) {
    var g, n;
    try { g = await Api.get('/hr/goals?user_id=' + uid); n = await Api.get('/hr/one-on-ones?user_id=' + uid); } catch (e) { return fail('Could not load them', e); }
    (g.goals || []).forEach(function (x) { GOALS_CACHE[x.id] = x; });
    var box = el('<div class="hrt-sect"><h5>Goals</h5>' + goalsHtml(g.goals) + '<div class="hrt-acts" style="margin-top:8px;"><button type="button" class="hr-b" data-goal>Add a goal</button></div></div>'
      + '<div class="hrt-sect"><h5>One-to-ones</h5><div class="hr-sub" style="margin-bottom:6px;">Private: only the two of you, your agency admins and your centre\'s director see these.</div>'
      + ((n.notes || []).length ? n.notes.map(function (x) {
        return '<div class="hrt-task"><div class="t"><b>' + esc(day(x.met_on)) + '</b> <span class="hr-sub">· written by <span data-no-i18n>' + esc(x.author || '') + '</span></span>'
          + '<div class="hrt-ro" style="margin-top:4px;" data-no-i18n>' + esc(x.notes) + '</div>' + (x.next_steps ? '<div class="hr-sub" style="margin-top:4px;">Next steps: <span data-no-i18n>' + esc(x.next_steps) + '</span></div>' : '')
          + (x.mine ? '<div class="hrt-acts"><button type="button" class="hr-b" data-nedit="' + x.id + '">Edit</button></div>' : '') + '</div></div>';
      }).join('') : '<span class="hr-sub">No notes yet.</span>')
      + '<div class="hrt-acts" style="margin-top:8px;"><button type="button" class="hr-b" data-note>Add a one-to-one note</button></div></div>');
    var reopen = function () { closeModal(); setTimeout(function () { openGoalsAndNotes(uid, own, done); }, 0); };
    modal({ title: 'Goals and one-to-ones', body: box, large: true, actions: [{ label: 'Close' }], onClose: function () { if (done) done(); } });
    box.querySelector('[data-goal]').addEventListener('click', function () { openGoal(null, uid, null, reopen); });
    wireGoals(box, reopen);
    var noteForm = function (x) {
      var c = el('<div class="hr-g">' + field('Met on', input('met_on', x ? x.met_on : today(), 'date')) + field('Notes', area('notes', x && x.notes, 5), true) + field('Next steps (optional)', area('next_steps', x && x.next_steps, 2), true)
        + '</div><div data-msg style="color:#B91C1C;font-size:13px;margin-top:6px;"></div>');
      modal({ title: x ? 'Edit one-to-one note' : 'One-to-one note', body: c, actions: [{ label: 'Cancel', handler: function () { setTimeout(reopen, 0); } }, { label: 'Save note', primary: true, busyLabel: 'Saving…', handler: function () {
        var o = { user_id: uid, met_on: val(c, 'met_on'), notes: val(c, 'notes'), next_steps: val(c, 'next_steps') };
        return (x ? Api.patch('/hr/one-on-ones/' + x.id, o) : Api.post('/hr/one-on-ones', o)).then(function () { saved('Note saved.'); setTimeout(reopen, 0); }, function (e) { msg(c, errMsg(e)); return false; });
      } }] });
    };
    box.querySelector('[data-note]').addEventListener('click', function () { noteForm(null); });
    box.querySelectorAll('[data-nedit]').forEach(function (b) { b.addEventListener('click', function () { noteForm((n.notes || []).filter(function (x) { return String(x.id) === b.getAttribute('data-nedit'); })[0]); }); });
  }

  /** The Phase 2 cards inside the HR profile popup (screen-hr.js openProfile). */
  async function profile(host, uid) {
    css();
    host.innerHTML = '<div class="hr-sub">Loading…</div>';
    var d;
    try { d = await Api.get('/hr/people/' + uid + '/talent'); } catch (e) { host.innerHTML = ''; return; }
    if (!host.isConnected) return;
    var redraw = function () { if (host.isConnected) profile(host, uid); };
    host.innerHTML = cards(d, uid, false, redraw);
    wireCards(host, d, uid, false, redraw);
  }

  // ── My HR (#my-hr) ──────────────────────────────────────────────────────
  async function renderMine(main) {
    css();
    main.setAttribute('data-kt-pretty', '1');
    main.innerHTML = '<div class="kt-section"><div class="kt-hero"><div class="kt-hero-greet">🧭 MY HR</div><h1>My HR</h1>'
      + '<div class="kt-hero-sub">Your onboarding checklist, your courses, your reviews and goals, in one place.</div></div><div id="mh-body"><div class="hr-sub" style="padding:12px 2px;">Loading…</div></div></div>';
    var b = main.querySelector('#mh-body'), d;
    try { d = await Api.get('/hr/me/talent'); } catch (e) { b.innerHTML = '<div class="hr-note">' + esc(errMsg(e)) + '</div>'; return; }
    if (!main.isConnected) return;
    var reload = function () { if (main.isConnected) renderMine(main); };
    var onb = (d.onboarding || []).filter(function (o) { return o.status === 'active'; })[0];
    var h = '';
    if (onb) {
      h += '<div class="hrt-sec">Your onboarding</div><div class="kt-card" style="padding:12px 14px;" data-kt-nogrid><div class="hr-bar">' + bar(onb.percent, onb.overdue) + '<span class="hr-sub">' + onb.done + ' of ' + onb.total + ' done' + (onb.overdue ? ' · ' + onb.overdue + ' overdue' : '') + '</span></div>'
        + '<div data-mytasks>' + taskList(onb.tasks, false, true) + '</div></div>';
    }
    var cs = d.courses || [];
    h += '<div class="hrt-sec">Your courses</div><div class="kt-card">' + (cs.length ? '<table data-hrt="mycourses"><thead><tr><th>Course</th><th>Due</th><th>Status</th><th></th></tr></thead><tbody>' + cs.map(function (c) {
      return '<tr data-id="' + c.id + '"><td><b data-no-i18n>' + esc(c.title) + '</b>' + (c.pd_hours ? '<div class="hr-sub">' + esc(c.pd_hours) + ' PD hours</div>' : '') + '</td><td style="white-space:nowrap;">' + esc(day(c.due_date)) + '</td>'
        + '<td>' + (c.completed_at ? pill('#DCFCE7', '#166534', 'Completed ' + tsDay(c.completed_at)) : (c.overdue ? pill('#FEF3C7', '#92400E', 'Overdue') : pill('#E0F2FE', '#075985', 'To do'))) + '</td>'
        + '<td style="text-align:center;white-space:nowrap;"><button type="button" data-mc="' + c.id + '">' + (c.completed_at ? 'Open' : 'Start the course') + '</button>'
        + (c.completed_at ? '<button type="button" data-mcert="' + c.id + '">Download certificate</button>' : '') + '</td></tr>';
    }).join('') + '</tbody></table>' : '<div style="padding:16px;" class="hr-sub">No courses assigned to you.</div>') + '</div>';
    var rs = d.reviews || [];
    h += '<div class="hrt-sec">Your reviews</div><div class="kt-card">' + (rs.length ? '<table data-hrt="myrev"><thead><tr><th>Review</th><th>Reviewer</th><th>Due</th><th>Status</th><th></th></tr></thead><tbody>' + rs.map(function (r) {
      return '<tr data-id="' + r.id + '"><td><b data-no-i18n>' + esc(r.title) + '</b></td><td data-no-i18n>' + esc(r.manager || '') + '</td><td style="white-space:nowrap;">' + esc(day(r.due_date)) + '</td><td>' + reviewPill(r.status) + '</td>'
        + '<td style="text-align:center;white-space:nowrap;"><button type="button" data-mr="' + r.id + '">Open review</button></td></tr>';
    }).join('') + '</tbody></table>' : '<div style="padding:16px;" class="hr-sub">No reviews yet.</div>') + '</div>';
    var gs = d.goals || [];
    h += '<div class="hrt-sec">Your goals</div><div class="kt-card" style="padding:12px 14px;" data-kt-nogrid>' + (gs.length ? gs.map(function (g) {
      return '<div style="display:flex;justify-content:space-between;gap:8px;padding:4px 0;font-size:13.5px;flex-wrap:wrap;"><span data-no-i18n>' + esc(g.title) + '</span><span>' + goalPill(g.status) + ' ' + bar(g.progress, g.status === 'at_risk') + '</span></div>';
    }).join('') : '<span class="hr-sub">No goals yet.</span>') + '<div class="hr-bar" style="margin:10px 0 0;"><button type="button" class="hr-b" data-mg>Goals and one-to-ones</button></div></div>';
    b.innerHTML = h;
    if (onb) wireTasks(b.querySelector('[data-mytasks]'), reload);
    b.querySelectorAll('[data-mc]').forEach(function (x) { x.addEventListener('click', function () { var a = cs.filter(function (c) { return String(c.id) === x.getAttribute('data-mc'); })[0]; if (a) openMyCourse(a, reload); }); });
    b.querySelectorAll('[data-mcert]').forEach(function (x) { x.addEventListener('click', function () { blob('/hr/course-assignments/' + x.getAttribute('data-mcert') + '/certificate', 'certificate.pdf'); }); });
    b.querySelectorAll('[data-mr]').forEach(function (x) { x.addEventListener('click', function () { openReview(+x.getAttribute('data-mr'), reload); }); });
    var mg = b.querySelector('[data-mg]'); if (mg) mg.addEventListener('click', function () { openGoalsAndNotes(d.me, true, reload); });
  }

  window.KTHrTalent = {
    tabs: [['hiring', 'Hiring'], ['onboarding', 'Onboarding'], ['performance', 'Performance'], ['courses', 'Courses']],
    draw: function (tab, host) {
      css();
      if (tab === 'hiring') return drawHiring(host);
      if (tab === 'onboarding') return drawOnboarding(host);
      if (tab === 'performance') return drawPerformance(host);
      if (tab === 'courses') return drawCourses(host);
    },
    profile: profile
  };
  ['educator', 'home_visitor', 'centre_director', 'agency_admin', 'platform_admin'].forEach(function (r) { KT.Shell.registerScreen(r + ':my-hr', renderMine); });
})(window);
