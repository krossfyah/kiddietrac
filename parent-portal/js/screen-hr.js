/* ═══════════════════════════════════════════════════════════════════════
   KiddieTrac HR (#hr) — 2026-11-27, Phase 1 of the HR add-on (feature `hr`).

   Tabs: People (the employee file + HR profile), Balances, Absence report, Phase 2's Hiring,
   Onboarding, Performance and Courses (screen-hr-talent.js, KTHrTalent), and for agency
   admins Leave types and Settings. Admins see the whole agency, directors their own
   centres' staff (the server scopes every list; nothing is filtered here).

   House patterns: data-kt-pretty tables (sort, filter, export bar, ⋮ row menu from
   kt-row-actions: plain labelled buttons in the last cell), View/Edit in KT.Shell.Modal,
   one banner (.kt-hero; the shell adds the shimmer and the floating art), "Saved" toasts,
   friendly errors. Dates are the agency's; date-only values are shown from their parts.
   Leave rules are the agency's own: nothing here knows any province's law.
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
    var m = (e && ((e.data && e.data.message) || e.message)) || '';
    return m && !/fetch|network|undefined|null|status \d/i.test(m) ? m : 'That did not work. Please try again.';
  }
  function fail(t, e) { toast('⚠️', t, errMsg(e), '#DC2626'); }
  var MON = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
  function day(s) { var m = /^(\d{4})-(\d{2})-(\d{2})/.exec(s || ''); return m ? MON[+m[2] - 1] + ' ' + (+m[3]) + ', ' + m[1] : ''; }
  function num(v) { v = Math.round((+v || 0) * 100) / 100; return String(v); }
  function amt(v, unit) { var n = num(v); return n + ' ' + (unit === 'hours' ? (n === '1' ? 'hour' : 'hours') : (n === '1' ? 'day' : 'days')); }
  function today() { try { if (KT.agencyToday) return KT.agencyToday(); } catch (e) {} return new Date().toISOString().slice(0, 10); }
  function pill(bg, fg, t) { return '<span data-kt-no-tone style="display:inline-block;padding:2px 9px;border-radius:999px;font-size:12px;font-weight:700;background:' + bg + ';color:' + fg + ';white-space:nowrap;">' + esc(t) + '</span>'; }
  var STATUS_TONE = { active: ['#ECFDF5', '#047857'], on_leave: ['#FFFBEB', '#B45309'], terminated: ['#F1F5F9', '#475569'] };
  function statusPill(s, labels) { var c = STATUS_TONE[s] || STATUS_TONE.active; return pill(c[0], c[1], (labels && labels[s]) || s || 'Active'); }
  function dot(c) { return '<span style="display:inline-block;width:10px;height:10px;border-radius:50%;background:' + esc(c || '#64748B') + ';margin-right:6px;vertical-align:-1px;"></span>'; }
  function avatar(p, size) { try { if (KT.avatar) return KT.avatar(p.name, { size: size || 28, photoUrl: p.photo_url, sex: p.sex, userId: p.id }); } catch (e) {} return ''; }

  var CSS_ID = 'kt-hr-css';
  function css() {
    if (document.getElementById(CSS_ID)) return;
    var s = document.createElement('style'); s.id = CSS_ID;
    s.textContent = ''
      + '.hr-tabs{display:flex;gap:6px;flex-wrap:wrap;margin:14px 0 10px;}'
      + '.hr-tab{height:30px;padding:0 13px;border-radius:999px;border:1px solid #E2E8F0;background:#fff;font-size:12.5px;font-weight:700;cursor:pointer;color:#475569;}'
      + '.hr-tab[aria-selected="true"]{background:#0E7490;border-color:#0E7490;color:#fff;}'
      + '.hr-bar{display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin:0 0 10px;}'
      + '.hr-i{height:30px;padding:0 9px;border:1px solid #CBD5E1;border-radius:8px;font-size:13px;background:#fff;color:#0F172A;box-sizing:border-box;max-width:100%;}'
      + 'textarea.hr-i{height:auto;padding:6px 9px;font-family:inherit;resize:vertical;}'
      + '.hr-b{height:30px;padding:0 12px;border-radius:8px;border:1px solid #CBD5E1;background:#fff;font-weight:700;font-size:12.5px;cursor:pointer;color:#0F172A;white-space:nowrap;}'
      + '.hr-b.p{background:#0E7490;border-color:#0E7490;color:#fff;}'
      + '.hr-l{display:block;font-size:11.5px;font-weight:800;color:#334155;text-transform:uppercase;letter-spacing:.4px;margin-bottom:4px;}'
      + '.hr-g{display:grid;grid-template-columns:repeat(auto-fit,minmax(min(210px,100%),1fr));gap:10px;}'
      + '.hr-sub{font-size:12px;color:#64748B;}'
      + '.hr-note{font-size:12.5px;color:#475569;background:#F0F9FF;border:1px solid #BAE6FD;border-radius:10px;padding:9px 12px;margin:0 0 10px;line-height:1.5;}'
      + '.hr-stats{display:flex;gap:8px;flex-wrap:wrap;margin:0 0 10px;}'
      + '.hr-stat{background:#fff;border:1px solid #E2E8F0;border-radius:10px;padding:8px 12px;min-width:110px;}'
      + '.hr-stat b{display:block;font-size:18px;color:#0F172A;}.hr-stat span{font-size:11.5px;color:#64748B;font-weight:700;}'
      + '.hr-pf{display:grid;grid-template-columns:repeat(auto-fit,minmax(min(260px,100%),1fr));gap:10px;}'
      + '.hr-pc{border:1px solid #E2E8F0;border-radius:12px;padding:11px 13px;background:#fff;}'
      + '.hr-pc h4{margin:0 0 7px;font-size:12px;letter-spacing:.05em;text-transform:uppercase;color:#0E7490;}'
      + '.hr-kv{display:flex;justify-content:space-between;gap:10px;font-size:13px;padding:3px 0;border-bottom:1px dashed #EEF2F6;}'
      + '.hr-kv:last-child{border-bottom:0;}.hr-kv span{color:#64748B;}.hr-kv b{color:#0F172A;text-align:right;font-weight:600;}'
      + '.hr-chip{display:inline-flex;align-items:center;gap:6px;border:1px solid #E2E8F0;border-radius:999px;padding:4px 10px;font-size:12.5px;margin:0 6px 6px 0;background:#fff;}'
      + '.hr-link{background:none;border:0;padding:0;color:#0E7490;font-weight:700;font-size:12.5px;cursor:pointer;text-decoration:underline;}'
      + '.hr-led{max-height:220px;overflow:auto;margin-top:6px;}'
      + '.hr-led div{display:flex;justify-content:space-between;gap:8px;font-size:12.5px;padding:4px 0;border-bottom:1px solid #F1F5F9;}'
      + '.hr-rule{display:grid;grid-template-columns:1.4fr 1fr 1fr auto;gap:6px;align-items:center;margin-bottom:6px;}'
      + 'html.kt-dark .hr-tab,html.kt-dark .hr-i,html.kt-dark .hr-b,html.kt-dark .hr-stat,html.kt-dark .hr-pc,html.kt-dark .hr-chip{background:#0F172A;border-color:#334155;color:#E2E8F0;}'
      + 'html.kt-dark .hr-tab[aria-selected="true"],html.kt-dark .hr-b.p{background:#0E7490;border-color:#0E7490;color:#fff;}'
      + 'html.kt-dark .hr-stat b,html.kt-dark .hr-kv b{color:#F1F5F9;}html.kt-dark .hr-l{color:#CBD5E1;}html.kt-dark .hr-pc h4{color:#5EEAD4;}'
      + 'html.kt-dark .hr-note{background:#0B2530;border-color:#155E75;color:#CFFAFE;}html.kt-dark .hr-kv{border-color:#1E293B;}html.kt-dark .hr-led div{border-color:#1E293B;}';
    document.head.appendChild(s);
  }

  var S = { tab: 'people', status: 'active', centre: '', admin: false, people: null, setup: null,
    abs: { from: '', to: '', group: 'person', centre: '', type: '', short_only: false, score_only: false, view: 'leave', late_only: false } };

  /* HR NAV RESTRUCTURE (2026-11-27, Anthony): the one #hr screen with 18 tabs became the Human Resources entries
     in kt-hr-sections.js (KTHrSections), each with only its own tabs. Every entry's HR tabs are drawn here:
     renderSection(main, section, tabKey, role). #hr itself is the HR dashboard; an old #hr?tab=<key> link is sent
     on to its new entry (person=, open=, view= and report= are kept). */
  async function renderSection(main, sec, key, role) {
    css();
    main.setAttribute('data-kt-pretty', '1');
    var tab = sec.tabs.filter(function (t) { return t.k === key && !t.screen; })[0] || sec.tabs.filter(function (t) { return !t.screen; })[0];
    S.tab = tab.draw || tab.k;
    if (/[?&]view=late\b/.test(location.hash || '')) S.abs.view = 'late';   // the lateness bell (HR Phase 4)
    main.innerHTML = '<div class="kt-section"><div class="kt-hero"><div class="kt-hero-greet">' + sec.icon + ' HUMAN RESOURCES</div><h1>' + esc(sec.label) + '</h1>'
      + (sec.sub ? '<div class="kt-hero-sub">' + esc(sec.sub) + '</div>' : '') + '</div>'
      + '<div id="hr-tabs"></div><div id="hr-body"><div class="hr-sub" style="padding:12px 2px;">Loading…</div></div></div>';
    var bandEl = window.KTHrSections ? window.KTHrSections.band(sec, tab.k, role) : null;
    // HR4B10: a one-tab section still gets its "?" (the tab row holds it otherwise).
    if (!bandEl && window.KTHrSections && window.KTHrSections.helpRow) bandEl = window.KTHrSections.helpRow(tab.k);
    var th = main.querySelector('#hr-tabs');
    if (bandEl && th) th.appendChild(bandEl); else if (th) th.style.height = '10px';
    // HR4B10: the role's starter path on the HR dashboard (kt-hr-quick.js), above the dashboard cards.
    if (sec.id === 'dashboard' && KT.hrQuick && KT.hrQuick.starterCard) {
      var stH = document.createElement('div'); stH.id = 'hr-starter';
      var bodyEl = main.querySelector('#hr-body'); if (bodyEl) bodyEl.parentNode.insertBefore(stH, bodyEl);
      KT.hrQuick.starterCard(stH, role === 'centre_director' ? 'centre_director' : 'agency_admin');
    }
    try { S.people = await Api.get('/hr/people'); }
    catch (e) { var b0 = main.querySelector('#hr-body'); if (b0) b0.innerHTML = '<div class="hr-note">' + esc(errMsg(e)) + '</div>'; return; }
    if (!main.isConnected) return;
    S.admin = !!S.people.can_manage_setup;
    if ((S.tab === 'types' || S.tab === 'settings' || S.tab === 'approvers') && !S.admin) { var b1 = body(main); if (b1) b1.innerHTML = '<div class="hr-note">Only your agency\'s admins can change this.</div>'; return; }
    drawTab(main);
    // ?person=ID: "Open HR file" from the user record (2026-11-26). Only someone on this list opens.
    var pm = /[?&]person=(\d+)/.exec(location.hash || '');
    if (pm && S.tab === 'people' && ((S.people && S.people.people) || []).some(function (p) { return p.id === +pm[1]; })) openProfile(main, +pm[1]);
  }

  async function render(main, ctx) {
    var KS = window.KTHrSections;
    var role = (ctx && ctx.role) || 'agency_admin';
    var to = KS ? KS.redirectFor(location.hash) : '';
    if (to) { location.replace(location.pathname + location.search + '#' + to); return; }
    return renderSection(main, KS ? KS.byId.dashboard : { icon: '🧑‍💼', label: 'HR dashboard', tabs: [{ k: 'dashboard' }] }, 'dashboard', role);
  }
  window.KTHr = { renderSection: renderSection };

  function body(main) { return main.querySelector('#hr-body'); }

  function drawTab(main) {
    var b = body(main); if (!b) return;
    b.innerHTML = '<div class="hr-sub" style="padding:12px 2px;">Loading…</div>';
    if (S.tab === 'people') return drawPeople(main);
    if (S.tab === 'balances') return drawBalances(main);
    if (S.tab === 'absence') return drawAbsence(main);
    if (S.tab === 'types') return drawTypes(main);
    if (S.tab === 'settings') return drawSettings(main);
    if (window.KTHrSafety && window.KTHrSafety.has(S.tab)) return window.KTHrSafety.draw(S.tab, b);
    if (window.KTHrFile && window.KTHrFile.has(S.tab)) return window.KTHrFile.draw(S.tab, b);
    if (window.KTHrReports && window.KTHrReports.has(S.tab)) return window.KTHrReports.draw(S.tab, b);
    if (window.KTHrPeople && window.KTHrPeople.has(S.tab)) return window.KTHrPeople.draw(S.tab, b);   // HR Phase 4 batch 6 (screen-hr-people.js)
    if (window.KTHrHs && window.KTHrHs.has(S.tab)) return window.KTHrHs.draw(S.tab, b);               // HR Phase 4 batch 7 (screen-hr-hs.js)
    if (window.KTHrRota && window.KTHrRota.has(S.tab)) return window.KTHrRota.draw(S.tab, b);         // HR Phase 4 batch 8 (screen-hr-rota.js)
    if (window.KTHrLeave && window.KTHrLeave.has(S.tab)) return window.KTHrLeave.draw(S.tab, b);       // HR4B9 closures & checks (screen-hr-leave.js)
    if (window.KTHrTax && window.KTHrTax.has && window.KTHrTax.has(S.tab)) return window.KTHrTax.draw(S.tab, b);   // Payroll P2: pay runs, remittances, opening balances (screen-hr-tax.js)
    if (window.KTHrTalent) return window.KTHrTalent.draw(S.tab, b);
  }

  async function reloadPeople() { try { S.people = await Api.get('/hr/people'); } catch (e) {} }

  // ── People ─────────────────────────────────────────────────────────────
  function drawPeople(main) {
    var d = S.people || { people: [] }, b = body(main);
    var rows = (d.people || []).filter(function (p) {
      return (!S.status || (p.status || 'active') === S.status) && (!S.centre || String(p.primary_centre_id) === String(S.centre));
    });
    var et = d.employment_types || {}, st = d.statuses || {};
    var h = '<div class="hr-bar"><select class="hr-i" id="hr-fs"><option value="">Everyone</option>'
      + Object.keys(st).map(function (k) { return '<option value="' + k + '"' + (S.status === k ? ' selected' : '') + '>' + esc(st[k]) + '</option>'; }).join('') + '</select>'
      + ((d.centres || []).length > 1 ? '<select class="hr-i" id="hr-fc"><option value="">All centres</option>' + d.centres.map(function (c) { return '<option value="' + c.id + '"' + (String(c.id) === String(S.centre) ? ' selected' : '') + '>' + esc(c.name) + '</option>'; }).join('') + '</select>' : '')
      + '<span class="hr-sub">' + rows.length + ' of ' + (d.people || []).length + ' people</span></div>';
    h += '<div class="kt-card">' + (rows.length ? '<table><thead><tr><th>Name</th><th>Job title</th><th>Employment</th><th>Centre</th><th>Start date</th><th>Manager</th><th>Status</th><th></th></tr></thead><tbody>'
      + rows.map(function (p) {
        return '<tr><td><span style="display:inline-flex;align-items:center;gap:8px;">' + avatar(p, 26) + '<b data-no-i18n>' + esc(p.name) + '</b></span></td>'
          + '<td data-no-i18n>' + esc(p.job_title || '') + '</td><td>' + esc(et[p.employment_type] || '') + '</td><td data-no-i18n>' + esc(p.centre_name || '') + '</td>'
          + '<td style="white-space:nowrap;">' + esc(day(p.start_date)) + '</td><td data-no-i18n>' + esc(p.manager_name || '') + '</td><td>' + statusPill(p.status, st) + '</td>'
          + '<td style="text-align:center;white-space:nowrap;"><button type="button" data-act="view" data-id="' + p.id + '">View HR profile</button>'
          + '<button type="button" data-act="edit" data-id="' + p.id + '">Edit file</button>'
          + '<button type="button" data-act="adjust" data-id="' + p.id + '">Adjust balance</button>'
          + (KT.openUserRecord ? '<button type="button" data-act="user" data-id="' + p.id + '">View user</button>' : '') + '</td></tr>';
      }).join('') + '</tbody></table>' : '<div style="padding:16px;" class="hr-sub">Nobody matches these filters.</div>') + '</div>';
    b.innerHTML = h;
    var fs = b.querySelector('#hr-fs'); if (fs) fs.addEventListener('change', function () { S.status = fs.value; drawPeople(main); });
    var fc = b.querySelector('#hr-fc'); if (fc) fc.addEventListener('change', function () { S.centre = fc.value; drawPeople(main); });
    b.querySelectorAll('[data-act]').forEach(function (x) {
      x.addEventListener('click', function () {
        var id = +x.getAttribute('data-id'), a = x.getAttribute('data-act');
        if (a === 'view') openProfile(main, id);
        else if (a === 'edit') openFile(main, id);
        else if (a === 'adjust') openAdjust(main, id);
        else if (a === 'user') { try { KT.openUserRecord(id); } catch (e) {} }   // the user record (2026-11-26)
      });
    });
  }

  // ── HR profile (the house popup) ───────────────────────────────────────
  async function openProfile(main, id) {
    var p;
    try { p = await Api.get('/hr/people/' + id); } catch (e) { return fail('Could not open the profile', e); }
    var f = p.file || {}, per = p.person || {}, et = p.employment_types || {}, st = p.statuses || {};
    var kv = function (k, v) { return '<div class="hr-kv"><span>' + esc(k) + '</span><b data-no-i18n>' + (v == null || v === '' ? '<span class="hr-sub">—</span>' : v) + '</b></div>'; };
    var box = document.createElement('div');
    var certs = (p.certifications || []);
    var certTone = { expired: ['#FEF2F2', '#B91C1C', 'Expired'], soon: ['#FFFBEB', '#B45309', 'Expires soon'], ok: ['#ECFDF5', '#047857', 'Current'] };
    box.innerHTML = '<div style="display:flex;align-items:center;gap:12px;margin-bottom:12px;">' + avatar({ name: per.name, photo_url: per.photo_url, sex: per.sex, id: per.id }, 46)
      + '<div style="min-width:0;"><div style="font-size:17px;font-weight:800;" data-no-i18n>' + esc(per.name) + '</div>'
      + '<div class="hr-sub" data-no-i18n>' + esc([f.job_title, (per.centres || []).map(function (c) { return c.name; }).join(', ')].filter(Boolean).join(' · ')) + '</div></div>'
      + '<div style="margin-left:auto;">' + statusPill(f.status, st) + '</div></div>'
      + '<div class="hr-pf">'
      + '<div class="hr-pc"><h4>Employment</h4>' + kv('Job title', esc(f.job_title)) + kv('Employment type', esc(et[f.employment_type] || '')) + kv('Start date', esc(day(per.start_date)))
      + kv('Years of service', per.start_date ? esc(num(per.years_of_service)) : '') + kv('Primary centre', esc(f.primary_centre_name)) + kv('Manager', esc(f.manager_name))
      + (f.status === 'terminated' || f.end_date ? kv('End date', esc(day(f.end_date))) + kv('Reason', esc(f.end_reason)) : '') + '</div>'
      + '<div class="hr-pc"><h4>Leave balances</h4><div>' + ((p.leave || []).length ? (p.leave || []).map(function (l) {
          return '<span class="hr-chip">' + dot(l.colour) + '<b>' + esc(l.name) + '</b> ' + (l.tracked ? esc(amt(l.balance, l.unit)) : '<span class="hr-sub">not tracked</span>')
            + (l.pending ? ' <span class="hr-sub">(' + esc(amt(l.pending, l.unit)) + ' pending)</span>' : '') + '</span>';
        }).join('') : '<span class="hr-sub">No leave types yet.</span>') + '</div>'
      + '<button type="button" class="hr-link" data-led>Balance history</button><div class="hr-led" data-ledbox hidden></div></div>'
      + '<div class="hr-pc"><h4>Recent time off</h4>' + ((p.requests || []).length ? (p.requests || []).slice(0, 8).map(function (r) {
          return '<div class="hr-kv"><span>' + dot(r.colour) + esc(r.type) + '</span><b>' + esc(r.when) + (r.amount != null ? ' · ' + esc(amt(r.amount, r.unit)) : '') + ' · ' + esc(r.status) + '</b></div>';
        }).join('') : '<span class="hr-sub">No requests yet.</span>') + '<div style="margin-top:6px;"><button type="button" class="hr-link" data-go="time-off">Open Time off requests</button></div></div>'
      + '<div class="hr-pc"><h4>Certifications</h4>' + (certs.length ? certs.map(function (c) {
          var t = certTone[c.state] || certTone.ok;
          return '<div class="hr-kv"><span data-no-i18n>' + esc(c.title) + '</span><b>' + (c.expires_at ? esc(day(c.expires_at)) + ' ' : '') + pill(t[0], t[1], t[2]) + '</b></div>';
        }).join('') : '<span class="hr-sub">None on file.</span>') + '<div style="margin-top:6px;"><button type="button" class="hr-link" data-go="certifications">Open Certifications</button></div></div>'
      + '<div class="hr-pc"><h4>Policies & training</h4>' + kv('Policies acknowledged', esc((p.policies || {}).acknowledged + ' of ' + (p.policies || {}).total))
      + kv('Last acknowledged', esc(day((p.policies || {}).last))) + kv('PD hours this year', esc(num((p.pd_hours || {}).this_year))) + kv('PD hours in total', esc(num((p.pd_hours || {}).total)))
      + '<div style="margin-top:6px;"><button type="button" class="hr-link" data-go="policies">Open Policies</button></div></div>'
      + '<div class="hr-pc"><h4>Emergency contact</h4>' + (p.emergency && (p.emergency.name || p.emergency.phone)
          ? kv('Name', esc(p.emergency.name)) + kv('Relationship', esc(p.emergency.relation)) + kv('Phone', esc(p.emergency.phone)) : '<span class="hr-sub">None on file.</span>')
      + (KT.openUserRecord ? '<div style="margin-top:6px;"><button type="button" class="hr-link" data-user>Open user record</button></div>' : '') + '</div>'
      + (p.pay ? '<div class="hr-pc"><h4>Pay</h4>' + kv('Pay rate', p.pay.pay_rate != null ? esc('$' + (+p.pay.pay_rate).toFixed(2) + (p.pay.pay_type ? ' · ' + p.pay.pay_type : '')) : '')
          + '<div style="margin-top:6px;"><button type="button" class="hr-link" data-go="payroll">Open Payroll</button></div></div>' : '')
      + (p.sin ? '<div class="hr-pc" data-kt-private><h4>' + esc(p.sin.label) + '</h4>' + kv('On file', p.sin.on_file ? esc(p.sin.hint) : '')
          + '<div data-sinout class="hr-sub" style="margin-top:4px;"></div><div class="hr-bar" style="margin:8px 0 0;">'
          + (p.sin.on_file ? '<button type="button" class="hr-b" data-sin="reveal">Reveal</button>' : '')
          + '<button type="button" class="hr-b" data-sin="set">' + (p.sin.on_file ? 'Change' : 'Add') + '</button>'
          + (p.sin.on_file ? '<button type="button" class="hr-b" data-sin="clear">Remove</button>' : '') + '</div>'
          + '<div class="hr-sub" style="margin-top:6px;">Stored encrypted. Only agency admins can see or change it, and every reveal is recorded in the audit log.</div></div>' : '')
      + '</div>' + (window.KTHrFile ? '<div data-hrfile style="margin-top:10px;"></div>' : '')
      + (window.KTHrTalent ? '<div data-hrtalent style="margin-top:10px;"></div>' : '')
      + (window.KTHrTax ? '<div data-hrtax style="margin-top:10px;"></div>' : '');   // Payroll P1: tax setup card (screen-hr-tax.js; nothing until payroll is on)
    var close = null;
    var M = KT.Shell.Modal;
    M.open({ title: 'HR profile', body: box, large: true, actions: [
      { label: 'Close' },
      { label: 'Adjust balance', handler: function () { setTimeout(function () { openAdjust(main, id); }, 0); } },
      { label: 'Edit file', primary: true, handler: function () { setTimeout(function () { openFile(main, id); }, 0); } }
    ] });
    box.querySelectorAll('[data-go]').forEach(function (g) { g.addEventListener('click', function () { closeModal(); location.hash = '#' + g.getAttribute('data-go'); }); });
    var tl = box.querySelector('[data-hrtalent]'); if (tl && window.KTHrTalent) window.KTHrTalent.profile(tl, id);
    var hf = box.querySelector('[data-hrfile]'); if (hf && window.KTHrFile) window.KTHrFile.profile(hf, id, p);   // documents, pay + role history, leaving
    var hx = box.querySelector('[data-hrtax]'); if (hx && window.KTHrTax) window.KTHrTax.profile(hx, id);   // Payroll P1: tax setup + TD1s
    var ub = box.querySelector('[data-user]'); if (ub) ub.addEventListener('click', function () { closeModal(); try { KT.openUserRecord(id); } catch (e) {} });
    var lb = box.querySelector('[data-led]');
    if (lb) lb.addEventListener('click', function () {
      var h = box.querySelector('[data-ledbox]'); if (!h) return;
      if (!h.hidden) { h.hidden = true; return; }
      h.hidden = false; h.innerHTML = '<span class="hr-sub">Loading…</span>';
      Api.get('/hr/ledger?user_id=' + id).then(function (r) { h.innerHTML = ledgerHtml(r.entries || []); }, function (e) { h.innerHTML = '<span class="hr-sub">' + esc(errMsg(e)) + '</span>'; });
    });
    box.querySelectorAll('[data-sin]').forEach(function (sb) {
      sb.addEventListener('click', function () { sinAction(main, id, sb.getAttribute('data-sin'), box, p.sin.label); });
    });
  }
  function closeModal() { try { var r = document.getElementById('modalRoot'); var x = r && r.querySelector('.modal-close'); if (x) x.click(); } catch (e) {} }

  var KIND = { grant: 'Granted', accrual: 'Earned', carry_forfeit: 'Did not carry over', expiry: 'Expired', adjustment: 'Adjusted', taken: 'Taken', returned: 'Given back', overtime: 'Overtime banked', shutdown: 'Centre closed' };   // HR4B9 shutdown
  function ledgerHtml(rows) {
    if (!rows.length) return '<span class="hr-sub">No changes yet.</span>';
    return rows.map(function (r) {
      var sign = r.amount > 0 ? '+' : '';
      return '<div><span>' + esc(day(r.date)) + ' · ' + dot(r.colour) + esc(r.type) + ' · ' + esc(KIND[r.kind] || r.kind)
        + (r.reason ? '<br><span class="hr-sub" data-no-i18n>' + esc(r.reason) + (r.by ? ' — ' + esc(r.by) : '') + '</span>' : '') + '</span>'
        + '<b style="white-space:nowrap;color:' + (r.amount < 0 ? '#B91C1C' : '#047857') + ';">' + sign + esc(amt(r.amount, r.unit)) + '</b></div>';
    }).join('');
  }

  async function sinAction(main, id, a, box, label) {
    var out = box.querySelector('[data-sinout]');
    if (a === 'reveal') {
      var ok = await KT.confirm({ title: 'Reveal this number?', description: 'This is recorded in the audit log with your name.', okLabel: 'Reveal' });
      if (!ok) return;
      try { var r = await Api.post('/hr/people/' + id + '/sin/reveal', {}); if (out) { out.textContent = r.sin; out.style.color = '#0F172A'; out.style.fontWeight = '800'; setTimeout(function () { if (out) out.textContent = ''; }, 30000); } }
      catch (e) { fail('Could not reveal it', e); }
      return;
    }
    if (a === 'clear') {
      var ok2 = await KT.confirm({ title: 'Remove this number?', description: 'It is deleted from KiddieTrac.', okLabel: 'Remove' });
      if (!ok2) return;
      try { await Api.delete('/hr/people/' + id + '/sin'); saved('Number removed.'); closeModal(); openProfile(main, id); } catch (e) { fail('Could not remove it', e); }
      return;
    }
    var v = await KT.prompt({ title: label, description: 'Enter the 9 digits. It is stored encrypted and never shown in lists, exports or search.', fields: [{ key: 'sin', label: 'Number', value: '', placeholder: '123 456 789' }], okLabel: 'Save' });
    if (v == null) return;
    try { await Api.put('/hr/people/' + id + '/sin', { sin: String(v) }); saved(); closeModal(); openProfile(main, id); } catch (e) { fail('Could not save it', e); }
  }

  // ── Edit the employee file ─────────────────────────────────────────────
  async function openFile(main, id) {
    var p;
    try { p = await Api.get('/hr/people/' + id); } catch (e) { return fail('Could not open the file', e); }
    var f = p.file || {}, et = p.employment_types || {}, st = p.statuses || {};
    var opt = function (map, v, blank) { return (blank ? '<option value="">' + esc(blank) + '</option>' : '') + Object.keys(map).map(function (k) { return '<option value="' + k + '"' + (String(v) === String(k) ? ' selected' : '') + '>' + esc(map[k]) + '</option>'; }).join(''); };
    var box = document.createElement('div');
    box.innerHTML = '<div class="hr-sub" style="margin-bottom:10px;" data-no-i18n>' + esc(p.person.name) + '</div><div class="hr-g">'
      + '<div><label class="hr-l">Job title</label><input class="hr-i" data-f="job_title" maxlength="120" style="width:100%;" value="' + esc(f.job_title || '') + '" placeholder="e.g. Lead Teacher"></div>'
      + '<div><label class="hr-l">Employment type</label><select class="hr-i" data-f="employment_type" style="width:100%;">' + opt(et, f.employment_type, 'Not set') + '</select></div>'
      + '<div><label class="hr-l">Status</label><select class="hr-i" data-f="status" style="width:100%;">' + opt(st, f.status || 'active') + '</select></div>'
      + '<div><label class="hr-l">Start date</label><input class="hr-i" type="date" data-f="start_date" style="width:100%;" value="' + esc(p.person.start_date || '') + '"></div>'
      + '<div><label class="hr-l">Primary centre</label><select class="hr-i" data-f="primary_centre_id" style="width:100%;"><option value="">Not set</option>'
      + (p.centre_choices || []).map(function (c) { return '<option value="' + c.id + '"' + (String(c.id) === String(f.primary_centre_id) ? ' selected' : '') + '>' + esc(c.name) + '</option>'; }).join('') + '</select></div>'
      + '<div><label class="hr-l">Manager</label><select class="hr-i" data-f="manager_user_id" style="width:100%;"><option value="">Not set</option>'
      + (p.managers || []).map(function (m) { return '<option value="' + m.id + '"' + (String(m.id) === String(f.manager_user_id) ? ' selected' : '') + '>' + esc(m.name) + '</option>'; }).join('') + '</select></div>'
      + '<div><label class="hr-l">End date</label><input class="hr-i" type="date" data-f="end_date" style="width:100%;" value="' + esc(f.end_date ? String(f.end_date).slice(0, 10) : '') + '"></div>'
      + '<div><label class="hr-l">End reason</label><input class="hr-i" data-f="end_reason" maxlength="255" style="width:100%;" value="' + esc(f.end_reason || '') + '" placeholder="e.g. Resigned, moved away"></div>'
      + '</div><div class="hr-sub" style="margin-top:8px;">A terminated file needs an end date. Leave balances stop growing for terminated staff.</div><div data-msg style="font-size:13px;color:#B91C1C;margin-top:6px;"></div>';
    KT.Shell.Modal.open({ title: 'Edit employee file', body: box, actions: [{ label: 'Cancel' }, { label: 'Save', primary: true, busyLabel: 'Saving…', handler: function () {
      var o = {}; box.querySelectorAll('[data-f]').forEach(function (el) { var v = el.value.trim(); o[el.getAttribute('data-f')] = v === '' ? null : v; });
      if (o.manager_user_id) o.manager_user_id = +o.manager_user_id;
      if (o.primary_centre_id) o.primary_centre_id = +o.primary_centre_id;
      if (o.status === 'terminated' && !o.end_date) { box.querySelector('[data-msg]').textContent = 'Add the end date.'; return false; }
      return Api.put('/hr/people/' + id, o).then(function () { saved('Employee file updated.'); return reloadPeople().then(function () { if (S.tab === 'people') drawPeople(main); }); },
        function (e) { box.querySelector('[data-msg]').textContent = errMsg(e); return false; });
    } }] });
  }

  // ── Adjust a balance (a new ledger row, with a reason) ─────────────────
  async function openAdjust(main, id, typeId) {
    var p;
    try { p = await Api.get('/hr/people/' + id); } catch (e) { return fail('Could not open the balances', e); }
    if (!p.can || !p.can.adjust) { toast('⚠️', 'Not your own balance', 'Someone else has to adjust your own balance.', '#DC2626'); return; }
    var types = p.leave || [];
    if (!types.length) { toast('⚠️', 'No leave types yet', 'An agency admin adds them under HR → Leave types.', '#DC2626'); return; }
    var box = document.createElement('div');
    box.innerHTML = '<div class="hr-sub" style="margin-bottom:10px;" data-no-i18n>' + esc(p.person.name) + '</div><div class="hr-g">'
      + '<div><label class="hr-l">Leave type</label><select class="hr-i" data-f="leave_type_id" style="width:100%;">' + types.map(function (t) { return '<option value="' + t.leave_type_id + '"' + (String(t.leave_type_id) === String(typeId) ? ' selected' : '') + '>' + esc(t.name + ' — ' + amt(t.balance, t.unit) + ' now') + '</option>'; }).join('') + '</select></div>'
      + '<div><label class="hr-l">Change</label><select class="hr-i" data-f="dir" style="width:100%;"><option value="1">Add to the balance</option><option value="-1">Take off the balance</option></select></div>'
      + '<div><label class="hr-l">Amount</label><input class="hr-i" type="number" min="0.25" step="0.25" data-f="amount" style="width:100%;" placeholder="e.g. 1"></div></div>'
      + '<label class="hr-l" style="margin-top:10px;">Reason (required)</label><textarea class="hr-i" data-f="reason" maxlength="500" rows="3" style="width:100%;min-height:72px;" placeholder="Why, so anyone reading the history understands it"></textarea>'
      + '<div class="hr-sub" style="margin-top:6px;">Adjustments are added to the balance history and never edited. To undo one, add the opposite adjustment.</div><div data-msg style="font-size:13px;color:#B91C1C;margin-top:6px;"></div>';
    KT.Shell.Modal.open({ title: 'Adjust leave balance', body: box, actions: [{ label: 'Cancel' }, { label: 'Save adjustment', primary: true, busyLabel: 'Saving…', handler: function () {
      var g = function (k) { var el = box.querySelector('[data-f="' + k + '"]'); return el ? el.value.trim() : ''; };
      var a = +g('amount'), msg = box.querySelector('[data-msg]');
      if (!(a > 0)) { msg.textContent = 'Enter an amount.'; return false; }
      if (g('reason').length < 3) { msg.textContent = 'Say why.'; return false; }
      return Api.post('/hr/adjustments', { user_id: id, leave_type_id: +g('leave_type_id'), amount: a * (+g('dir')), reason: g('reason') }).then(function (r) {
        var t = types.filter(function (x) { return String(x.leave_type_id) === g('leave_type_id'); })[0];
        saved((t ? t.name + ': ' : '') + 'now ' + amt(r.balance, t ? t.unit : 'days') + '.');
        if (S.tab === 'balances') drawBalances(main);
      }, function (e) { msg.textContent = errMsg(e); return false; });
    } }] });
  }

  // ── Balances ───────────────────────────────────────────────────────────
  async function drawBalances(main) {
    var b = body(main), d;
    try { d = await Api.get('/hr/balances'); } catch (e) { b.innerHTML = '<div class="hr-note">' + esc(errMsg(e)) + '</div>'; return; }
    if (!b.isConnected || S.tab !== 'balances') return;
    var types = d.types || [];
    if (!types.length) { b.innerHTML = '<div class="hr-note">No leave types yet. ' + (S.admin ? 'Add your own under Leave types, or load the example set and edit it.' : 'An agency admin adds them under HR → Leave types.') + '</div>'; return; }
    b.innerHTML = '<div class="hr-note">Balances are the sum of each person\'s balance history: grants, accruals, approved time off and adjustments. Pending requests are shown beside them and are taken off when approved.</div>'
      + '<div class="kt-card"><table><thead><tr><th>Name</th>' + types.map(function (t) { return '<th>' + dot(t.colour) + '<span data-no-i18n>' + esc(t.name) + '</span></th>'; }).join('') + '<th></th></tr></thead><tbody>'
      + (d.people || []).map(function (p) {
        return '<tr><td><b data-no-i18n>' + esc(p.name) + '</b></td>' + types.map(function (t) {
          var c = (p.balances || {})[t.id] || { balance: 0, pending: 0 };
          return '<td style="white-space:nowrap;' + (c.balance < 0 ? 'color:#B91C1C;font-weight:700;' : '') + '">' + esc(amt(c.balance, t.unit)) + (c.pending ? '<div class="hr-sub">' + esc(amt(c.pending, t.unit)) + ' pending, ' + esc(amt(c.balance - c.pending, t.unit)) + ' after</div>' : '')   /* HR4B9 */ + '</td>';
        }).join('') + '<td style="text-align:center;white-space:nowrap;"><button type="button" data-act="adjust" data-id="' + p.id + '">Adjust balance</button><button type="button" data-act="view" data-id="' + p.id + '">View HR profile</button></td></tr>';
      }).join('') + '</tbody></table></div>';
    b.querySelectorAll('[data-act]').forEach(function (x) {
      x.addEventListener('click', function () { var id = +x.getAttribute('data-id'); if (x.getAttribute('data-act') === 'adjust') openAdjust(main, id); else openProfile(main, id); });
    });
  }

  // ── Absence report ─────────────────────────────────────────────────────
  function absQuery() {
    var a = S.abs, q = ['group=' + a.group];
    if (a.from) q.push('from=' + a.from); if (a.to) q.push('to=' + a.to);
    if (a.centre) q.push('centre_id=' + a.centre); if (a.type) q.push('leave_type_id=' + a.type);
    if (a.short_only && a.group === 'person') q.push('short_only=1');
    if (a.score_only && a.group === 'person') q.push('score_only=1');
    return q.join('&');
  }
  /* Time off | Lateness (HR Phase 4, 2026-11-27): the same report, two views sharing the dates and centre. */
  function absViews(cur) {
    return '<div class="hr-tabs" role="tablist" style="margin:0 0 10px;">' + [['leave', 'Time off'], ['late', 'Lateness']].map(function (v) {
      return '<button type="button" role="tab" class="hr-tab" data-absview="' + v[0] + '" aria-selected="' + (cur === v[0]) + '">' + v[1] + '</button>';
    }).join('') + '</div>';
  }
  function wireViews(main, b) {
    b.querySelectorAll('[data-absview]').forEach(function (x) {
      x.addEventListener('click', function () { S.abs.view = x.getAttribute('data-absview'); drawAbsence(main); });
    });
  }
  async function drawAbsence(main) {
    if (S.abs.view === 'late') return drawLateness(main);
    var b = body(main), d;
    try { d = await Api.get('/hr/absence?' + absQuery()); } catch (e) { b.innerHTML = '<div class="hr-note">' + esc(errMsg(e)) + '</div>'; return; }
    if (!b.isConnected || S.tab !== 'absence' || S.abs.view !== 'leave') return;
    var a = S.abs; a.from = d.from; a.to = d.to;
    var first = { person: 'Person', type: 'Leave type', month: 'Month', centre: 'Centre' }[d.group];
    var r = d.rule || {}, sr = d.score_rule || {};
    var h = absViews('leave') + '<div class="hr-bar" data-kt-nogrid>'
      + '<label class="hr-sub">From <input class="hr-i" type="date" id="hr-af" value="' + esc(d.from) + '"></label>'
      + '<label class="hr-sub">To <input class="hr-i" type="date" id="hr-at" value="' + esc(d.to) + '"></label>'
      + '<select class="hr-i" id="hr-ag">' + [['person', 'By person'], ['type', 'By leave type'], ['month', 'By month'], ['centre', 'By centre']].map(function (o) { return '<option value="' + o[0] + '"' + (d.group === o[0] ? ' selected' : '') + '>' + o[1] + '</option>'; }).join('') + '</select>'
      + ((d.centres || []).length > 1 ? '<select class="hr-i" id="hr-ac"><option value="">All centres</option>' + d.centres.map(function (c) { return '<option value="' + c.id + '"' + (String(c.id) === String(a.centre) ? ' selected' : '') + '>' + esc(c.name) + '</option>'; }).join('') + '</select>' : '')
      + '<select class="hr-i" id="hr-aty"><option value="">All leave types</option>' + (d.types || []).map(function (t) { return '<option value="' + t.id + '"' + (String(t.id) === String(a.type) ? ' selected' : '') + '>' + esc(t.name) + '</option>'; }).join('') + '</select>'
      + (d.group === 'person' ? '<label class="hr-sub" style="display:inline-flex;gap:6px;align-items:center;cursor:pointer;"><input type="checkbox" id="hr-as"' + (a.short_only ? ' checked' : '') + ' style="flex:0 0 auto;width:16px;height:16px;"> Only repeated short absences</label>'
        + '<label class="hr-sub" style="display:inline-flex;gap:6px;align-items:center;cursor:pointer;"><input type="checkbox" id="hr-asc"' + (a.score_only ? ' checked' : '') + ' style="flex:0 0 auto;width:16px;height:16px;"> Only above the absence score</label>' : '')
      + '<button type="button" class="hr-b p" id="hr-ax">Export to Excel</button></div>'
      + '<div class="hr-stats"><div class="hr-stat"><b>' + esc(num(d.totals.days)) + '</b><span>Days off</span></div><div class="hr-stat"><b>' + esc(num(d.totals.hours)) + '</b><span>Hours off</span></div>'
      + '<div class="hr-stat"><b>' + esc(d.totals.people) + '</b><span>People</span></div><div class="hr-stat"><b style="color:' + (d.totals.flagged ? '#B45309' : '#0F172A') + ';">' + esc(d.totals.flagged) + '</b><span>Repeated short absences</span></div>'
      + '<div class="hr-stat"><b style="color:' + (d.totals.score_flagged ? '#B45309' : '#0F172A') + ';">' + esc(d.totals.score_flagged || 0) + '</b><span>Above the absence score</span></div></div>'
      + '<div class="hr-sub" style="margin:0 0 8px;">Approved time off, ' + esc(day(d.from)) + ' to ' + esc(day(d.to)) + '. Repeated short absences: ' + esc(r.count) + ' or more absences of ' + esc(num(r.max_days)) + ' day' + (+r.max_days === 1 ? '' : 's') + ' or less in ' + esc(r.months) + ' months' + (S.admin ? ' (change it under Settings).' : '.')
      + ' Absence score: absences × absences × days off, for ' + esc((sr.types || []).length ? sr.types.join(', ') : 'sick leave') + ', ' + esc(day(sr.from)) + ' to ' + esc(day(d.to))
      + '; ' + esc(sr.threshold) + ' or more is marked. Many short absences score higher than one long one.</div>';
    var rows = d.rows || [];
    h += '<div class="kt-card">' + (rows.length ? '<table><thead><tr><th>' + first + '</th>' + (d.group === 'person' ? '<th>Centre</th>' : '') + '<th>Days off</th><th>Hours off</th><th>Absences</th>'
      + (d.group === 'person' ? '<th>Short absences</th><th>Absence score</th>' : '<th>People</th>') + '<th>By leave type</th>' + (d.group === 'person' ? '<th></th>' : '') + '</tr></thead><tbody>'
      + rows.map(function (x) {
        return '<tr><td>' + (x.colour ? dot(x.colour) : '') + '<b data-no-i18n>' + esc(x.label) + '</b>' + (x.flagged ? ' ' + pill('#FFFBEB', '#B45309', 'Repeated short absences') : '') + '</td>'
          + (d.group === 'person' ? '<td data-no-i18n>' + esc(x.centre || '') + '</td>' : '') + '<td>' + esc(num(x.days)) + '</td><td>' + esc(num(x.hours)) + '</td><td>' + esc(x.occurrences) + '</td>'
          + '<td>' + esc(d.group === 'person' ? x.short_count : x.people) + '</td>'
          + (d.group === 'person' ? '<td style="white-space:nowrap;"><b' + (x.score_flag ? ' style="color:#B45309;"' : '') + '>' + esc(x.score || 0) + '</b>' + (x.score_flag ? ' ' + pill('#FFFBEB', '#B45309', 'Above ' + sr.threshold) : '')
            + (x.score_spells ? '<div class="hr-sub">' + esc(x.score_spells + ' × ' + x.score_spells + ' × ' + num(x.score_days)) + '</div>' : '') + '</td>' : '')
          + '<td class="hr-sub" data-no-i18n>' + esc((x.by_type || []).map(function (t) { return t.type + ' ' + num(t.days); }).join(', ')) + '</td>'
          + (d.group === 'person' ? '<td style="text-align:center;"><button type="button" data-act="view" data-id="' + x.user_id + '">View HR profile</button></td>' : '') + '</tr>';
      }).join('') + '</tbody></table>' : '<div style="padding:16px;" class="hr-sub">No approved time off in this range.</div>') + '</div>';
    b.innerHTML = h;
    var redraw = function () { drawAbsence(main); };
    b.querySelector('#hr-af').addEventListener('change', function () { a.from = this.value; redraw(); });
    b.querySelector('#hr-at').addEventListener('change', function () { a.to = this.value; redraw(); });
    b.querySelector('#hr-ag').addEventListener('change', function () { a.group = this.value; redraw(); });
    var ac = b.querySelector('#hr-ac'); if (ac) ac.addEventListener('change', function () { a.centre = this.value; redraw(); });
    b.querySelector('#hr-aty').addEventListener('change', function () { a.type = this.value; redraw(); });
    var as = b.querySelector('#hr-as'); if (as) as.addEventListener('change', function () { a.short_only = this.checked; redraw(); });
    var asc = b.querySelector('#hr-asc'); if (asc) asc.addEventListener('change', function () { a.score_only = this.checked; redraw(); });
    wireViews(main, b);
    b.querySelector('#hr-ax').addEventListener('click', function () { exportXlsx(this); });
    b.querySelectorAll('[data-act="view"]').forEach(function (x) { x.addEventListener('click', function () { openProfile(main, +x.getAttribute('data-id')); }); });
  }
  async function exportXlsx(btn, late) {
    btn.disabled = true;
    try {
      var url = (KT.API_BASE || '/api/v1') + '/exports/hr-absence?' + (late ? lateQuery() + '&view=late' : absQuery()), blob;
      if (KT.fetchFileBlob) blob = await KT.fetchFileBlob(url);
      else throw new Error('');
      var a = document.createElement('a'); a.href = URL.createObjectURL(blob);
      a.download = (late ? 'lateness-' : 'absence-report-') + S.abs.from + '-to-' + S.abs.to + '.xlsx'; document.body.appendChild(a); a.click();
      setTimeout(function () { URL.revokeObjectURL(a.href); a.remove(); }, 2000);
      toast('📥', 'Excel file ready', '', '#16A34A');
    } catch (e) { fail('Could not export', e); }
    btn.disabled = false;
  }

  // ── Lateness (HR Phase 4, 2026-11-27) ──────────────────────────────────
  /* Clock-ins compared with the scheduled shift (the server stores the minutes on each punch; the agency's grace
     window decides what counts as late). Same dates and centre as the Time off view. */
  function lateQuery() {
    var a = S.abs, q = [];
    if (a.from) q.push('from=' + a.from); if (a.to) q.push('to=' + a.to);
    if (a.centre) q.push('centre_id=' + a.centre);
    if (a.late_only) q.push('late_only=1');
    return q.join('&');
  }
  function minsText(m) { m = +m || 0; if (m < 60) return m + ' min'; var h = Math.floor(m / 60), r = m % 60; return h + ' h' + (r ? ' ' + r + ' min' : ''); }
  async function drawLateness(main) {
    var b = body(main), d;
    try { d = await Api.get('/hr/lateness?' + lateQuery()); } catch (e) { b.innerHTML = absViews('late') + '<div class="hr-note">' + esc(errMsg(e)) + '</div>'; wireViews(main, b); return; }
    if (!b.isConnected || S.tab !== 'absence' || S.abs.view !== 'late') return;
    var a = S.abs; a.from = d.from; a.to = d.to;
    var t = d.totals || {};
    var h = absViews('late') + '<div class="hr-bar" data-kt-nogrid>'
      + '<label class="hr-sub">From <input class="hr-i" type="date" id="hr-lf" value="' + esc(d.from) + '"></label>'
      + '<label class="hr-sub">To <input class="hr-i" type="date" id="hr-lt" value="' + esc(d.to) + '"></label>'
      + ((d.centres || []).length > 1 ? '<select class="hr-i" id="hr-lc"><option value="">All centres</option>' + d.centres.map(function (c) { return '<option value="' + c.id + '"' + (String(c.id) === String(a.centre) ? ' selected' : '') + '>' + esc(c.name) + '</option>'; }).join('') + '</select>' : '')
      + '<label class="hr-sub" style="display:inline-flex;gap:6px;align-items:center;cursor:pointer;"><input type="checkbox" id="hr-lo"' + (a.late_only ? ' checked' : '') + ' style="flex:0 0 auto;width:16px;height:16px;"> Only people who were late</label>'
      + '<button type="button" class="hr-b p" id="hr-lx">Export to Excel</button></div>'
      + '<div class="hr-stats"><div class="hr-stat"><b>' + esc(t.compared || 0) + '</b><span>Clock-ins compared</span></div>'
      + '<div class="hr-stat"><b style="color:' + (t.late ? '#B45309' : '#0F172A') + ';">' + esc(t.late || 0) + '</b><span>Late</span></div>'
      + '<div class="hr-stat"><b>' + esc(minsText(t.minutes)) + '</b><span>Late in all</span></div>'
      + '<div class="hr-stat"><b>' + esc(t.people_late || 0) + '</b><span>People late</span></div></div>'
      + '<div class="hr-sub" style="margin:0 0 8px;">Each person\'s first clock-in of a scheduled shift, ' + esc(day(d.from)) + ' to ' + esc(day(d.to)) + '. Late means more than '
      + esc(d.grace) + ' minute' + (+d.grace === 1 ? '' : 's') + ' after the shift started' + (S.admin ? ' (change the grace time under Settings).' : '.')
      + ' Back from a break and days with no shift are not counted.</div>';
    var rows = d.rows || [];
    h += '<div class="kt-card">' + (rows.length ? '<table><thead><tr><th>Person</th><th>Centre</th><th>Compared</th><th>Late</th><th>Late in all</th><th>Average</th><th>Most</th><th>On time</th><th>Pattern</th><th></th></tr></thead><tbody>'
      + rows.map(function (x) {
        return '<tr><td><b data-no-i18n>' + esc(x.name) + '</b></td><td data-no-i18n>' + esc(x.centre || '') + '</td><td>' + esc(x.compared) + '</td>'
          + '<td>' + (x.late ? '<b style="color:#B45309;">' + esc(x.late) + '</b>' : '0') + '</td><td>' + esc(x.late ? minsText(x.minutes) : '') + '</td>'
          + '<td>' + esc(x.late ? minsText(x.average) : '') + '</td><td>' + esc(x.late ? minsText(x.worst) : '') + '</td>'
          + '<td>' + (x.on_time_pct == null ? '' : esc(x.on_time_pct + '%')) + '</td><td>' + esc(x.pattern || '') + '</td>'
          + '<td style="text-align:center;white-space:nowrap;"><button type="button" data-act="late" data-id="' + x.user_id + '">View clock-ins</button>'
          + '<button type="button" data-act="view" data-id="' + x.user_id + '">View HR profile</button></td></tr>';
      }).join('') + '</tbody></table>' : '<div style="padding:16px;" class="hr-sub">No clock-ins to compare in this range. Lateness needs shifts on the Schedule: a clock-in on a day with no shift is not compared.</div>') + '</div>';
    b.innerHTML = h;
    var redraw = function () { drawLateness(main); };
    b.querySelector('#hr-lf').addEventListener('change', function () { a.from = this.value; redraw(); });
    b.querySelector('#hr-lt').addEventListener('change', function () { a.to = this.value; redraw(); });
    var lc = b.querySelector('#hr-lc'); if (lc) lc.addEventListener('change', function () { a.centre = this.value; redraw(); });
    b.querySelector('#hr-lo').addEventListener('change', function () { a.late_only = this.checked; redraw(); });
    b.querySelector('#hr-lx').addEventListener('click', function () { exportXlsx(this, true); });
    wireViews(main, b);
    b.querySelectorAll('[data-act]').forEach(function (x) {
      x.addEventListener('click', function () {
        var id = +x.getAttribute('data-id');
        if (x.getAttribute('data-act') === 'late') openLateness(id); else openProfile(main, id);
      });
    });
  }
  async function openLateness(id) {
    var r;
    try { r = await Api.get('/hr/lateness/' + id + '?from=' + S.abs.from + '&to=' + S.abs.to); } catch (e) { return fail('Could not open the clock-ins', e); }
    var box = document.createElement('div');
    var list = r.entries || [];
    box.innerHTML = '<div class="hr-sub" style="margin-bottom:8px;"><span data-no-i18n>' + esc(r.person) + '</span> · ' + esc(day(r.from)) + ' to ' + esc(day(r.to))
      + ' · late = more than ' + esc(r.grace) + ' min after the shift started</div>'
      + (list.length ? list.map(function (e) {
        return '<div class="hr-kv"><span>' + esc(e.weekday + ', ' + day(e.date)) + (e.room ? ' · <span data-no-i18n>' + esc(e.room) + '</span>' : '') + '</span><b>'
          + esc('Shift ' + (e.shift_start || '') + ', in ' + e.clocked_in) + ' '
          + (e.late ? pill('#FFFBEB', '#B45309', minsText(e.late_minutes) + ' late') : pill('#ECFDF5', '#047857', e.late_minutes ? minsText(e.late_minutes) + ', within grace' : 'On time')) + '</b></div>';
      }).join('') : '<span class="hr-sub">No compared clock-ins in this range.</span>');
    KT.Shell.Modal.open({ title: 'Clock-ins against the schedule', body: box, large: true, actions: [{ label: 'Close' }] });
  }

  // ── Leave types (agency admins) ────────────────────────────────────────
  var ACCR = { lump: 'Yearly, all at once', pay_period: 'Each pay period', hours_worked: 'By hours worked', none: 'Not tracked' };
  var CARRY = { none: 'None', all: 'All of it', capped: 'Up to a limit' };
  async function loadSetup() { S.setup = await Api.get('/hr/setup'); return S.setup; }
  function ruleText(t) {
    if (t.accrual === 'none') return 'No balance';
    var rs = t.rules || [];
    if (!rs.length) return '<span style="color:#B45309;">No entitlement rules yet</span>';
    var et = (S.setup && S.setup.employment_types) || {};
    return rs.map(function (r) { return esc(num(r.amount) + ' ' + t.unit + (+r.min_years ? ' after ' + num(r.min_years) + ' yr' : '') + (r.employment_type ? ' (' + (et[r.employment_type] || r.employment_type) + ')' : '')); }).join('<br>');
  }
  async function drawTypes(main) {
    var b = body(main), d;
    try { d = await loadSetup(); } catch (e) { b.innerHTML = '<div class="hr-note">' + esc(errMsg(e)) + '</div>'; return; }
    if (!b.isConnected || S.tab !== 'types') return;
    var rows = d.types || [], rt = d.request_types || {};
    var h = '<div class="hr-note"><b>Your agency\'s own rules.</b> No province or state employment law is applied for you. Add the leave types your team uses and the entitlement for each. '
      + 'The example set is only a starting point to edit: it is never applied by itself.</div>'
      + '<div class="hr-bar"><button type="button" class="hr-b p" id="hr-tnew">Add leave type</button><button type="button" class="hr-b" id="hr-tex">Load example set</button>'
      + '<button type="button" class="hr-b" id="hr-tacc">Run grants and accruals now</button><span class="hr-sub">Grants and accruals also run every night.</span></div>';
    h += '<div class="kt-card">' + (rows.length ? '<table><thead><tr><th>Leave type</th><th>Unit</th><th>Paid</th><th>Staff see it</th><th>Approval</th><th>Entitlement</th><th>Builds up</th><th>Carry-over</th><th>Old requests</th><th>Status</th><th></th></tr></thead><tbody>'
      + rows.map(function (t) {
        var arch = !!t.archived_at;
        return '<tr' + (arch ? ' style="opacity:.6"' : '') + '><td>' + dot(t.colour) + '<b data-no-i18n>' + esc(t.name) + '</b>' + (t.is_example ? ' ' + pill('#F0F9FF', '#0369A1', 'Example') : '') + '</td>'
          + '<td>' + esc(t.unit === 'hours' ? 'Hours' : 'Days') + '</td><td>' + (+t.paid ? 'Paid' : 'Unpaid') + '</td><td>' + (+t.visible_to_staff ? 'Yes' : 'No') + '</td>'
          + '<td>' + (+t.needs_approval ? 'Needs approval' : 'Recorded, then reviewed') + '</td><td>' + ruleText(t) + '</td>'
          + '<td>' + esc(ACCR[t.accrual] || t.accrual) + (t.accrual === 'pay_period' ? '<div class="hr-sub">' + esc(t.periods_per_year) + ' a year</div>' : '') + (t.accrual === 'hours_worked' ? '<div class="hr-sub">1 ' + esc(t.unit === 'hours' ? 'hour' : 'day') + ' per ' + esc(num(t.hours_per_unit)) + ' hours worked</div>' : '') + '</td>'
          + '<td>' + esc(CARRY[t.carry_mode] || '') + (t.carry_mode === 'capped' ? '<div class="hr-sub">up to ' + esc(amt(t.carry_cap, t.unit)) + '</div>' : '') + (t.carry_expiry_months ? '<div class="hr-sub">expires after ' + esc(t.carry_expiry_months) + ' months</div>' : '') + '</td>'
          + '<td>' + esc(t.maps_request_type ? rt[t.maps_request_type] : '') + '</td><td>' + (arch ? pill('#F1F5F9', '#475569', 'Archived') : pill('#ECFDF5', '#047857', 'In use')) + '</td>'
          + '<td style="text-align:center;white-space:nowrap;"><button type="button" data-act="edit" data-id="' + t.id + '">Edit</button>'
          + (t.accrual !== 'none' ? '<button type="button" data-act="rules" data-id="' + t.id + '">Entitlement rules</button>' : '')
          + (t.maps_request_type && !arch ? '<button type="button" data-act="count" data-id="' + t.id + '">Count this year\'s approved requests</button>' : '')
          + '<button type="button" data-act="' + (arch ? 'restore' : 'archive') + '" data-id="' + t.id + '">' + (arch ? 'Restore' : 'Archive') + '</button></td></tr>';
      }).join('') + '</tbody></table>' : '<div style="padding:16px;" class="hr-sub">No leave types yet. Add your own, or load the example set and edit it.</div>') + '</div>';
    b.innerHTML = h;
    var byId = {}; rows.forEach(function (t) { byId[t.id] = t; });
    b.querySelector('#hr-tnew').addEventListener('click', function () { openType(main, null); });
    b.querySelector('#hr-tex').addEventListener('click', async function () {
      var ok = await KT.confirm({ title: 'Load the example set?', description: 'Adds example leave types (Vacation, Sick days, Personal days, Bereavement, Unpaid leave) with example amounts, marked "Example". They are not any law\'s minimums: change every value to your own policy. Names you already use are skipped.', okLabel: 'Load examples' });
      if (!ok) return;
      try { var r = await Api.post('/hr/leave-types/example', {}); saved(r.added && r.added.length ? 'Added: ' + r.added.join(', ') + '.' : 'You already have all of them.'); drawTypes(main); } catch (e) { fail('Could not load the examples', e); }
    });
    b.querySelector('#hr-tacc').addEventListener('click', async function () {
      this.disabled = true;
      try { var r = await Api.post('/hr/accrue', {}); saved(r.rows + ' balance change' + (r.rows === 1 ? '' : 's') + ' for ' + r.people + ' people.'); } catch (e) { fail('Could not run them', e); }
      this.disabled = false;
    });
    b.querySelectorAll('[data-act]').forEach(function (x) {
      x.addEventListener('click', async function () {
        var t = byId[x.getAttribute('data-id')], a = x.getAttribute('data-act'); if (!t) return;
        if (a === 'edit') return openType(main, t);
        if (a === 'rules') return openRules(main, t);
        if (a === 'count') {
          var ok = await KT.confirm({ title: 'Count approved requests?', description: 'Requests already approved this leave year and filed as "' + (rt[t.maps_request_type] || '') + '" are taken off ' + t.name + ' balances. Run it once, after setting up the entitlement. Requests themselves are not changed.', okLabel: 'Count them' });
          if (!ok) return;
          try { var r = await Api.post('/hr/leave-types/' + t.id + '/count-approved', {}); saved(r.rows + ' request' + (r.rows === 1 ? '' : 's') + ' counted.'); } catch (e) { fail('Could not count them', e); }
          return;
        }
        try { await Api.post('/hr/leave-types/' + t.id + '/' + a, {}); saved(a === 'archive' ? t.name + ' archived. Balances are kept.' : t.name + ' restored.'); drawTypes(main); } catch (e) { fail('Could not change it', e); }
      });
    });
  }

  function openType(main, t) {
    var d = S.setup || {}, rt = d.request_types || {};
    t = t || { name: '', colour: '#0E7490', paid: 1, unit: 'days', visible_to_staff: 1, needs_approval: 1, maps_request_type: '', accrual: 'lump', periods_per_year: 26, hours_per_unit: 30, carry_mode: 'none', carry_cap: '', carry_expiry_months: '' };
    var sel = function (k, map, v) { return '<select class="hr-i" data-f="' + k + '" style="width:100%;">' + Object.keys(map).map(function (o) { return '<option value="' + o + '"' + (String(v) === String(o) ? ' selected' : '') + '>' + esc(map[o]) + '</option>'; }).join('') + '</select>'; };
    var yn = function (k, v, y, n) { return sel(k, { '1': y, '0': n }, (+v ? '1' : '0')); };
    var box = document.createElement('div');
    box.innerHTML = '<div class="hr-g">'
      + '<div><label class="hr-l">Name</label><input class="hr-i" data-f="name" maxlength="80" style="width:100%;" value="' + esc(t.name) + '" placeholder="e.g. Vacation"></div>'
      + '<div><label class="hr-l">Colour</label><input class="hr-i" type="color" data-f="colour" style="width:100%;padding:2px;" value="' + esc(t.colour || '#0E7490') + '"></div>'
      + '<div><label class="hr-l">Counted in</label>' + sel('unit', { days: 'Days', hours: 'Hours' }, t.unit) + '</div>'
      + '<div><label class="hr-l">Pay</label>' + yn('paid', t.paid, 'Paid', 'Unpaid') + '</div>'
      + '<div><label class="hr-l">Staff can see and request it</label>' + yn('visible_to_staff', t.visible_to_staff, 'Yes', 'No, managers only') + '</div>'
      + '<div><label class="hr-l">Approval</label>' + yn('needs_approval', t.needs_approval, 'Needs approval', 'No approval: recorded at once, manager reviews it') + '</div>'
      + '<div><label class="hr-l">Paid out when someone leaves</label>' + sel('payout_on_leaving', { default: 'Default (vacation and overtime bank only)', yes: 'Yes, pay out the balance', no: 'No' }, t.payout_on_leaving == null ? 'default' : (+t.payout_on_leaving ? 'yes' : 'no')) + '</div>'
      + '<div><label class="hr-l">How it builds up</label>' + sel('accrual', ACCR, t.accrual) + '</div>'
      + '<div data-show="pay_period"><label class="hr-l">Pay periods a year</label>' + sel('periods_per_year', { '12': '12 (monthly)', '24': '24 (twice a month)', '26': '26 (every two weeks)', '52': '52 (weekly)' }, t.periods_per_year || 26) + '</div>'
      + '<div data-show="hours_worked"><label class="hr-l">Hours worked to earn 1 unit</label><input class="hr-i" type="number" min="1" step="0.5" data-f="hours_per_unit" style="width:100%;" value="' + esc(t.hours_per_unit || 30) + '"></div>'
      + '<div><label class="hr-l">At the end of the leave year</label>' + sel('carry_mode', CARRY, t.carry_mode) + '</div>'
      + '<div data-show="capped"><label class="hr-l">Carry-over limit</label><input class="hr-i" type="number" min="0" step="0.5" data-f="carry_cap" style="width:100%;" value="' + esc(t.carry_cap == null ? '' : t.carry_cap) + '"></div>'
      + '<div data-show="carry"><label class="hr-l">Carried balance expires after (months)</label><input class="hr-i" type="number" min="1" max="24" data-f="carry_expiry_months" style="width:100%;" value="' + esc(t.carry_expiry_months || '') + '" placeholder="Never"></div>'
      + '<div><label class="hr-l">Old requests filed as</label><select class="hr-i" data-f="maps_request_type" style="width:100%;"><option value="">None</option>' + Object.keys(rt).map(function (k) { return '<option value="' + k + '"' + (t.maps_request_type === k ? ' selected' : '') + '>' + esc(rt[k]) + '</option>'; }).join('') + '</select></div>'
      + '</div><div class="hr-sub" style="margin-top:8px;">Amounts count working days: weekends and the holidays and closures on each centre calendar are skipped. "Old requests filed as" lets requests made before HR (Vacation, Sick, Personal…) count as this type. The requests themselves never change.</div>'
      + '<div data-msg style="font-size:13px;color:#B91C1C;margin-top:6px;"></div>';
    var sync = function () {
      var v = function (k) { var el = box.querySelector('[data-f="' + k + '"]'); return el ? el.value : ''; };
      box.querySelectorAll('[data-show]').forEach(function (el) {
        var w = el.getAttribute('data-show');
        el.style.display = (w === 'pay_period' || w === 'hours_worked') ? (v('accrual') === w ? '' : 'none') : w === 'capped' ? (v('carry_mode') === 'capped' ? '' : 'none') : (v('carry_mode') !== 'none' ? '' : 'none');
      });
    };
    box.addEventListener('change', sync); sync();
    KT.Shell.Modal.open({ title: t.id ? 'Edit leave type' : 'Add leave type', body: box, actions: [{ label: 'Cancel' }, { label: 'Save', primary: true, busyLabel: 'Saving…', handler: function () {
      var o = {}; box.querySelectorAll('[data-f]').forEach(function (el) { o[el.getAttribute('data-f')] = el.value.trim(); });
      ['paid', 'visible_to_staff', 'needs_approval'].forEach(function (k) { o[k] = o[k] === '1'; });
      o.maps_request_type = o.maps_request_type || null;
      o.payout_on_leaving = o.payout_on_leaving === 'default' ? null : o.payout_on_leaving === 'yes';
      o.periods_per_year = o.accrual === 'pay_period' ? +o.periods_per_year : null;
      o.hours_per_unit = o.accrual === 'hours_worked' ? +o.hours_per_unit : null;
      o.carry_cap = o.carry_mode === 'capped' ? +o.carry_cap : null;
      o.carry_expiry_months = o.carry_mode !== 'none' && o.carry_expiry_months ? +o.carry_expiry_months : null;
      var msg = box.querySelector('[data-msg]');
      if (!o.name) { msg.textContent = 'Give it a name.'; return false; }
      return (t.id ? Api.patch('/hr/leave-types/' + t.id, o) : Api.post('/hr/leave-types', o)).then(function (r) {
        saved(t.id ? '' : 'Now add its entitlement rules.');
        drawTypes(main);
        if (!t.id && r && r.id && o.accrual !== 'none') setTimeout(function () { loadSetup().then(function (sd) { var nt = (sd.types || []).filter(function (x) { return x.id === r.id; })[0]; if (nt) openRules(main, nt); }); }, 50);
      }, function (e) { msg.textContent = errMsg(e); return false; });
    } }] });
  }

  function openRules(main, t) {
    var et = (S.setup && S.setup.employment_types) || {};
    var rules = (t.rules || []).map(function (r) { return { employment_type: r.employment_type || '', min_years: +r.min_years, amount: +r.amount }; });
    if (!rules.length) rules.push({ employment_type: '', min_years: 0, amount: '' });
    var box = document.createElement('div');
    var draw = function () {
      box.innerHTML = '<div class="hr-sub" style="margin-bottom:8px;">How much <b data-no-i18n>' + esc(t.name) + '</b> each person gets in a leave year, in ' + esc(t.unit)
        + (t.accrual === 'pay_period' ? ' (spread over the pay periods)' : t.accrual === 'hours_worked' ? ' (the most they can earn from hours worked; leave empty for no limit)' : '') + '. The rule with the most years of service a person has reached applies; a rule for their employment type beats one for everyone.</div>'
        + '<div class="hr-rule"><span class="hr-l">Employment type</span><span class="hr-l">After years of service</span><span class="hr-l">Amount a year</span><span></span></div>'
        + rules.map(function (r, i) {
          return '<div class="hr-rule" data-i="' + i + '"><select class="hr-i" data-k="employment_type"><option value="">Everyone</option>' + Object.keys(et).map(function (k) { return '<option value="' + k + '"' + (r.employment_type === k ? ' selected' : '') + '>' + esc(et[k]) + '</option>'; }).join('') + '</select>'
            + '<input class="hr-i" type="number" min="0" step="0.5" data-k="min_years" value="' + esc(r.min_years) + '">'
            + '<input class="hr-i" type="number" min="0" step="0.25" data-k="amount" value="' + esc(r.amount) + '" placeholder="e.g. 10">'
            + '<button type="button" class="hr-b" data-del="' + i + '" aria-label="Remove rule">Remove</button></div>';
        }).join('')
        + '<button type="button" class="hr-b" data-add>Add a rule</button><div data-msg style="font-size:13px;color:#B91C1C;margin-top:6px;"></div>';
      box.querySelectorAll('[data-k]').forEach(function (el) { el.addEventListener('input', function () { var i = +el.closest('[data-i]').getAttribute('data-i'); rules[i][el.getAttribute('data-k')] = el.value; }); el.addEventListener('change', function () { var i = +el.closest('[data-i]').getAttribute('data-i'); rules[i][el.getAttribute('data-k')] = el.value; }); });
      box.querySelectorAll('[data-del]').forEach(function (x) { x.addEventListener('click', function () { rules.splice(+x.getAttribute('data-del'), 1); draw(); }); });
      box.querySelector('[data-add]').addEventListener('click', function () { rules.push({ employment_type: '', min_years: 0, amount: '' }); draw(); });
    };
    draw();
    KT.Shell.Modal.open({ title: 'Entitlement rules', body: box, actions: [{ label: 'Cancel' }, { label: 'Save rules', primary: true, busyLabel: 'Saving…', handler: function () {
      var clean = rules.filter(function (r) { return String(r.amount) !== ''; }).map(function (r) { return { employment_type: r.employment_type || null, min_years: +r.min_years || 0, amount: +r.amount }; });
      return Api.put('/hr/leave-types/' + t.id + '/rules', { rules: clean }).then(function () { saved('Use "Run grants and accruals now" to apply them today.'); drawTypes(main); },
        function (e) { box.querySelector('[data-msg]').textContent = errMsg(e); return false; });
    } }] });
  }

  /* Attendance rules (HR Phase 4, 2026-11-27): lateness, the absence score and leave clashes with each centre's
     minimum staff. Saved on their own (PUT /hr/attendance/settings), so the leave settings above are untouched. */
  async function drawAttendanceSettings(host) {
    var d;
    try { d = await Api.get('/hr/attendance/settings'); } catch (e) { host.innerHTML = '<div class="hr-note">' + esc(errMsg(e)) + '</div>'; return; }
    if (!host.isConnected) return;
    var s = d.settings || {}, l = s.lateness || {}, a = s.absence_score || {}, c = s.clash || {};
    var picked = {}; (a.type_ids || []).forEach(function (id) { picked[id] = 1; });
    var yn = function (id, v, y, n) { return '<select class="hr-i" id="' + id + '" style="width:100%;"><option value="1"' + (v ? ' selected' : '') + '>' + esc(y) + '</option><option value="0"' + (!v ? ' selected' : '') + '>' + esc(n) + '</option></select>'; };
    var dflt = (d.default_score_types || []).length ? d.default_score_types.join(', ') : 'old requests filed as Sick';
    host.innerHTML = '<div class="kt-card" style="padding:14px 16px;margin-top:12px;" data-kt-nogrid>'
      + '<div style="font-weight:800;font-size:13.5px;">Lateness</div>'
      + '<div class="hr-sub" style="margin:2px 0 8px;">Each first clock-in of a scheduled shift is compared with the shift start. The absence report\'s Lateness view lists it.</div><div class="hr-g">'
      + '<div><label class="hr-l">Grace time (minutes)</label><input class="hr-i" type="number" min="0" max="120" id="ha-gr" style="width:100%;" value="' + esc(l.grace_minutes == null ? 5 : l.grace_minutes) + '"></div>'
      + '<div><label class="hr-l">Tell the centre\'s directors</label>' + yn('ha-nd', l.notify_directors, 'Yes, a notice when someone is late', 'No, the report only') + '</div>'
      + '<div><label class="hr-l">Contractors</label>' + yn('ha-sc', l.skip_contractors !== false, 'Not compared', 'Compared like everyone') + '</div></div>'
      + '<div style="margin-top:14px;font-weight:800;font-size:13.5px;">Absence score</div>'
      + '<div class="hr-sub" style="margin:2px 0 8px;">Absences × absences × days off over the period, so many short absences score higher than one long one (the Bradford formula). The absence report marks anyone at or above the threshold. A prompt for a conversation, never an automatic decision.</div><div class="hr-g">'
      + '<div><label class="hr-l">Mark at or above</label><input class="hr-i" type="number" min="1" max="100000" id="ha-th" style="width:100%;" value="' + esc(a.threshold || 50) + '"></div>'
      + '<div><label class="hr-l">Over the last (months)</label><input class="hr-i" type="number" min="1" max="24" id="ha-mo" style="width:100%;" value="' + esc(a.months || 12) + '"></div></div>'
      + '<label class="hr-l" style="margin-top:10px;">Leave types it counts</label><div>' + ((d.types || []).length ? d.types.map(function (t) {
          return '<label class="hr-chip" style="cursor:pointer;"><input type="checkbox" data-st="' + t.id + '"' + (picked[t.id] ? ' checked' : '') + ' style="width:15px;height:15px;margin:0;">' + dot(t.colour) + '<span data-no-i18n>' + esc(t.name) + '</span></label>';
        }).join('') : '') + '</div><div class="hr-sub">None ticked = <span data-no-i18n>' + esc(dflt) + '</span>.</div>'
      + '<div style="margin-top:14px;font-weight:800;font-size:13.5px;">Leave clashes and minimum staff</div>'
      + '<div class="hr-sub" style="margin:2px 0 8px;">When someone asks for time off, they see how many others at their centre are already off, and the approver sees who. With a minimum set, the approver is told which days would go below it.</div><div class="hr-g">'
      + '<div><label class="hr-l">Show who else is off</label>' + yn('ha-cw', c.warn !== false, 'Yes', 'No') + '</div>'
      + '<div><label class="hr-l">Below the minimum staff</label><select class="hr-i" id="ha-mm" style="width:100%;"><option value="warn"' + (c.min_mode !== 'block' ? ' selected' : '') + '>Warn the approver</option><option value="block"' + (c.min_mode === 'block' ? ' selected' : '') + '>Don\'t allow approving it</option></select></div></div>'
      + '<div class="hr-sub" style="margin:8px 0 4px;">Leave that needs no approval (such as sick days) is never refused, only shown.</div>'
      + '<div class="kt-card" style="margin-top:6px;"><table><thead><tr><th>Centre</th><th>Staff placed there</th><th>Minimum staff on a working day</th></tr></thead><tbody>'
      + (d.centres || []).map(function (x) {
        return '<tr><td data-no-i18n>' + esc(x.name) + '</td><td>' + esc(x.staff) + '</td><td><input class="hr-i" type="number" min="0" max="500" data-min="' + x.id + '" style="width:110px;" value="' + esc(x.min_staff || '') + '" placeholder="No minimum"></td></tr>';
      }).join('') + '</tbody></table></div>'
      + (d.can_manage ? '<div class="hr-bar" style="margin:14px 0 0;"><button type="button" class="hr-b p" id="ha-save">Save attendance rules</button><span id="ha-msg" style="font-size:13px;color:#B91C1C;"></span></div>' : '')
      + '</div>';
    var sv = host.querySelector('#ha-save');
    if (sv) sv.addEventListener('click', async function () {
      var g = function (id) { return host.querySelector('#' + id).value; };
      var types = []; host.querySelectorAll('[data-st]').forEach(function (x) { if (x.checked) types.push(+x.getAttribute('data-st')); });
      var mins = []; host.querySelectorAll('[data-min]').forEach(function (x) { mins.push({ centre_id: +x.getAttribute('data-min'), min: x.value === '' ? null : Math.max(0, +x.value || 0) }); });
      var o = { lateness: { grace_minutes: Math.max(0, Math.min(120, +g('ha-gr') || 0)), notify_directors: g('ha-nd') === '1', skip_contractors: g('ha-sc') === '1' },
        absence_score: { threshold: Math.max(1, +g('ha-th') || 50), months: Math.max(1, Math.min(24, +g('ha-mo') || 12)), type_ids: types.length ? types : null },
        clash: { warn: g('ha-cw') === '1', min_mode: g('ha-mm') }, min_staff: mins };
      this.disabled = true;
      try { await Api.put('/hr/attendance/settings', o); host.querySelector('#ha-msg').textContent = ''; saved('Attendance rules saved.'); } catch (e) { host.querySelector('#ha-msg').textContent = errMsg(e); }
      this.disabled = false;
    });
  }

  // ── Google Jobs (seo1127, 2026-11-27): list the open posts of the agency's jobs page in Google Jobs ──
  // Off by default; agency admins switch it. jobs.html carries JobPosting data only while it is on, noindex otherwise.
  async function drawGoogleJobs(host) {
    if (!host) return;
    var d;
    try { d = await Api.get('/hr/hiring/google-jobs'); } catch (e) { host.innerHTML = ''; return; }
    if (!host.isConnected) return;
    host.innerHTML = '<div class="kt-card" style="padding:14px 16px;margin-top:12px;" data-kt-nogrid>'
      + '<div style="font-weight:800;font-size:13.5px;">Google Jobs</div>'
      + '<div class="hr-sub" style="margin:2px 0 8px;">List the open jobs on your jobs page in Google Jobs. Each job then carries its title, description, type, pay if you set it, your agency\'s name and logo, and the town it is in, never a street address. Closed and filled jobs drop out by themselves. While this is off, search engines are asked not to list your jobs page.</div><div class="hr-g">'
      + '<div><label class="hr-l">List our open jobs in Google Jobs</label><select class="hr-i" id="hg-on" style="width:100%;"' + (d.can_change ? '' : ' disabled') + '><option value="0"' + (!d.enabled ? ' selected' : '') + '>No (default)</option><option value="1"' + (d.enabled ? ' selected' : '') + '>Yes, list them</option></select></div>'
      + '<div><label class="hr-l">Open jobs on your jobs page</label><div style="padding:6px 0;font-weight:700;" data-no-i18n>' + esc(d.open_posts) + '</div></div></div>'
      + (d.jobs_url ? '<div class="hr-sub" style="margin-top:8px;"><a href="' + esc(d.jobs_url) + '" target="_blank" rel="noopener">Open your jobs page</a></div><div class="hr-sub">Google can take a few days to show new jobs.</div>' : '')
      + (d.can_change ? '<div class="hr-bar" style="margin:14px 0 0;"><button type="button" class="hr-b p" id="hg-save">Save Google Jobs</button><span id="hg-msg" style="font-size:13px;color:#B91C1C;"></span></div>' : '<div class="hr-sub" style="margin-top:8px;">Only an agency admin can change this.</div>')
      + '</div>';
    var sv = host.querySelector('#hg-save');
    if (sv) sv.addEventListener('click', async function () {
      this.disabled = true;
      try { await Api.put('/hr/hiring/google-jobs', { enabled: host.querySelector('#hg-on').value === '1' }); host.querySelector('#hg-msg').textContent = ''; saved('Google Jobs setting saved.'); }
      catch (e) { host.querySelector('#hg-msg').textContent = errMsg(e); }
      this.disabled = false;
    });
  }

  // ── Settings (agency admins) ───────────────────────────────────────────
  async function drawSettings(main) {
    var b = body(main), d;
    try { d = await loadSetup(); } catch (e) { b.innerHTML = '<div class="hr-note">' + esc(errMsg(e)) + '</div>'; return; }
    if (!b.isConnected || S.tab !== 'settings') return;
    var s = d.settings || {}, sa = s.short_absence || {};
    var md = String(s.leave_year_start || '01-01').split('-');
    var months = ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'];
    b.innerHTML = '<div class="kt-card" style="padding:14px 16px;" data-kt-nogrid><div class="hr-g">'
      + '<div><label class="hr-l">Leave year starts</label><select class="hr-i" id="hs-ly" style="width:100%;"><option value="fixed"' + (s.leave_year !== 'anniversary' ? ' selected' : '') + '>On the same date for everyone</option><option value="anniversary"' + (s.leave_year === 'anniversary' ? ' selected' : '') + '>On each person\'s start-date anniversary</option></select></div>'
      + '<div id="hs-fx"><label class="hr-l">Date</label><span style="display:flex;gap:6px;"><select class="hr-i" id="hs-m" style="flex:1;">' + months.map(function (m, i) { var v = (i < 9 ? '0' : '') + (i + 1); return '<option value="' + v + '"' + (v === md[0] ? ' selected' : '') + '>' + m + '</option>'; }).join('') + '</select>'
      + '<input class="hr-i" type="number" min="1" max="31" id="hs-d" style="width:70px;" value="' + (+md[1] || 1) + '"></span></div>'
      + '<div><label class="hr-l">Requests that go below zero</label><select class="hr-i" id="hs-neg" style="width:100%;"><option value="0"' + (!s.allow_negative ? ' selected' : '') + '>Refuse them</option><option value="1"' + (s.allow_negative ? ' selected' : '') + '>Allow them, with a warning</option></select></div>'
      + '<div><label class="hr-l">Hours in a working day</label><input class="hr-i" type="number" min="1" max="24" step="0.25" id="hs-hpd" style="width:100%;" value="' + esc(s.hours_per_day || 8) + '"></div>'
      + '</div><div style="margin-top:14px;font-weight:800;font-size:13.5px;">Repeated short absences</div>'
      + '<div class="hr-sub" style="margin:2px 0 8px;">The absence report marks anyone with this many short absences in the period. A simple count, set by you.</div><div class="hr-g">'
      + '<div><label class="hr-l">Absences</label><input class="hr-i" type="number" min="2" max="50" id="hs-c" style="width:100%;" value="' + esc(sa.count || 3) + '"></div>'
      + '<div><label class="hr-l">Of this many days or less</label><input class="hr-i" type="number" min="0.5" max="10" step="0.5" id="hs-md" style="width:100%;" value="' + esc(sa.max_days || 2) + '"></div>'
      + '<div><label class="hr-l">Within (months)</label><input class="hr-i" type="number" min="1" max="24" id="hs-mo" style="width:100%;" value="' + esc(sa.months || 6) + '"></div>'
      + '</div><div class="hr-bar" style="margin:14px 0 0;"><button type="button" class="hr-b p" id="hs-save">Save settings</button><span id="hs-msg" style="font-size:13px;color:#B91C1C;"></span></div></div>'
      + '<div id="hs-att"></div><div id="hs-pay"></div><div id="hs-gj"></div>';
    drawAttendanceSettings(b.querySelector('#hs-att'));   // HR Phase 4
    drawGoogleJobs(b.querySelector('#hs-gj'));   // seo1127
    if (window.KTHrTax && window.KTHrTax.settings) window.KTHrTax.settings(b.querySelector('#hs-pay'));   // Payroll P2: the engine switch + employer details
    var ly = b.querySelector('#hs-ly'), fx = b.querySelector('#hs-fx');
    var syncLy = function () { fx.style.display = ly.value === 'fixed' ? '' : 'none'; };
    ly.addEventListener('change', syncLy); syncLy();
    b.querySelector('#hs-save').addEventListener('click', async function () {
      var dd = Math.max(1, Math.min(31, +b.querySelector('#hs-d').value || 1));
      var o = { leave_year: ly.value, leave_year_start: b.querySelector('#hs-m').value + '-' + (dd < 10 ? '0' : '') + dd, allow_negative: b.querySelector('#hs-neg').value === '1',
        hours_per_day: +b.querySelector('#hs-hpd').value, short_absence: { count: +b.querySelector('#hs-c').value, max_days: +b.querySelector('#hs-md').value, months: +b.querySelector('#hs-mo').value } };
      this.disabled = true;
      try { await Api.put('/hr/settings', o); b.querySelector('#hs-msg').textContent = ''; saved('HR settings saved.'); } catch (e) { b.querySelector('#hs-msg').textContent = errMsg(e); }
      this.disabled = false;
    });
  }

  ['agency_admin', 'platform_admin', 'centre_director'].forEach(function (r) { KT.Shell.registerScreen(r + ':hr', render); });   // the HR dashboard; the other entries are kt-hr-sections.js's
})(window);
