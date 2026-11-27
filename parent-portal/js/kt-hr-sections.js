/* ═══════════════════════════════════════════════════════════════════════
   KiddieTrac HR sections (2026-11-27, Anthony: "instead of piling everything into HR as subtabs create new
   subsections under Human resources to help jump right to the sections").

   ONE LIST of the Human Resources entries an agency admin or centre director sees, each with only its own few
   tabs. Loaded eagerly (before screen-legacy-shim.js), because four of the entries are screens that existed
   before HR and must keep working without the hr add-on:

     Time off            #time-off          Requests (the old screen) · Balances · Leave types (admins)
     Rota & shifts       #schedule          Schedule (moved from Operations) · Rota plan · Open shifts
     Clock-ins & …       #timesheets        Timesheets · Approve & lock · Overtime
     Payroll             #payroll           Payroll · Claims & mileage
     Educator awards     #educator-rewards  Awards & points · Award settings (#educator-award-settings)
     Health & safety     #hr-safety         Staff injuries · Risk assessments · Chemicals (WHMIS) · Safe work procedures ·
                                            H&S duties · H&S tasks · Hazards (#hazards) · Drill log (#drills)
     Documents & letters #hr-documents      Company documents · Letters · Background checks (#background-checks)

   The rest (#hr dashboard, #hr-people, #hr-absence, #hr-hiring, #hr-performance, #hr-reports, #hr-settings)
   are HR-only. An HR tab on a pre-HR entry is `#<hash>?tab=<key>`; when the plan leaves the pre-HR screen out,
   the entry falls back to its HR-only twin (`alt`, e.g. #hr-payroll) so the HR tabs stay reachable.

   HOW THE TABS GET THERE. HR tabs are drawn by screen-hr.js (KTHr.renderSection). A pre-HR screen is drawn by
   its own code, untouched; its registration is wrapped so the section's tab row is placed right under that
   screen's banner (and put back if the screen redraws itself). One tab available = no row at all, so an agency
   without HR sees those screens exactly as before. Old links (#hr?tab=claims …) are redirected by screen-hr.js
   through redirectFor() below.
   ═══════════════════════════════════════════════════════════════════════ */
(function (window) {
  'use strict';
  var KT = window.KT;
  if (!KT || !KT.Shell || !KT.Shell.registerScreen) return;
  var Shell = KT.Shell;
  var ROLES = ['agency_admin', 'platform_admin', 'centre_director'];

  // k = tab key in ?tab=; draw = the key screen-hr.js draws (default k); screen = a pre-HR screen's own hash.
  var SECTIONS = [
    { id: 'dashboard', hash: 'hr', icon: '🧑‍💼', label: 'HR dashboard', sub: 'Your HR setup, who is off, probation and what needs doing next.',
      tabs: [{ k: 'dashboard', label: 'Dashboard' }] },
    { id: 'people', hash: 'hr-people', icon: '👥', label: 'People', sub: 'Employee files, pay and role history, each person\'s documents, the staff directory, the org chart and leavers.',
      tabs: [{ k: 'people', label: 'People' }, { k: 'directory', label: 'Directory' }, { k: 'orgchart', label: 'Org chart' }, { k: 'leavers', label: 'Leavers' }] },
    { id: 'timeoff', hash: 'time-off', alt: 'hr-time-off', icon: '🌴', label: 'Time off', sub: 'Time off requests, leave balances, leave types, and closures taken from leave.',
      tabs: [{ k: 'requests', label: 'Requests', screen: 'time-off' }, { k: 'balances', label: 'Balances' }, { k: 'types', label: 'Leave types', admin: true }, { k: 'leavemaths', label: 'Closures & checks' }] },   // HR4B9
    { id: 'absence', hash: 'hr-absence', icon: '🤒', label: 'Absence & lateness', sub: 'Absence report, lateness against the rota, and absence scores.',
      tabs: [{ k: 'absence', label: 'Absence & lateness' }] },
    { id: 'rota', hash: 'schedule', alt: 'hr-rota', icon: '🗒️', label: 'Rota & shifts', sub: 'Who is working and where, the rota plan, availability, swaps and open shifts.',
      tabs: [{ k: 'schedule', label: 'Schedule', screen: 'schedule' }, { k: 'rota', label: 'Rota plan' }, { k: 'openshifts', label: 'Open shifts' }, { k: 'rotatools', label: 'Shift tools' }] },
    { id: 'time', hash: 'timesheets', alt: 'hr-timesheets', icon: '🕘', label: 'Clock-ins & timesheets', sub: 'Hours worked, approving and locking each pay period, and overtime.',
      tabs: [{ k: 'sheets', label: 'Timesheets', screen: 'timesheets' }, { k: 'lock', draw: 'timesheets', label: 'Approve & lock' }, { k: 'punchfix', label: 'Clock-in fixes' }, { k: 'overtime', label: 'Overtime' }] },
    { id: 'payroll', hash: 'payroll', alt: 'hr-payroll', icon: '💼', label: 'Payroll', sub: 'Pay runs and hours, and expense and mileage claims.',
      // Payroll Engine P2 (2026-11-27): pay: true = shown only while the agency's payroll engine is on (screen-hr-tax.js).
      tabs: [{ k: 'payroll', label: 'Payroll', screen: 'payroll' }, { k: 'payruns', label: 'Pay runs', pay: true }, { k: 'remit', label: 'Remittances', pay: true },
        { k: 'ytd', label: 'Opening balances', pay: true, admin: true }, { k: 'claims', label: 'Claims & mileage' }] },
    { id: 'hiring', hash: 'hr-hiring', icon: '🧲', label: 'Hiring & onboarding', sub: 'Job posts, applicants and onboarding checklists.',
      tabs: [{ k: 'hiring', label: 'Hiring' }, { k: 'onboarding', label: 'Onboarding' }] },
    { id: 'performance', hash: 'hr-performance', icon: '🎯', label: 'Performance & learning', sub: 'Reviews, goals, one-to-ones and courses.',
      tabs: [{ k: 'performance', label: 'Reviews & goals' }, { k: 'courses', label: 'Courses' }] },
    { id: 'awards', hash: 'educator-rewards', icon: '⭐', label: 'Educator awards', sub: '',
      tabs: [{ k: 'rewards', label: 'Awards & points', screen: 'educator-rewards' }, { k: 'kudos', label: 'Recognition' }, { k: 'awardsettings', label: 'Award settings', screen: 'educator-award-settings' }] },
    // HR Phase 4 batch 7 (2026-11-27): risk, WHMIS, procedures, duties, H&S tasks (screen-hr-hs.js). Hazards keep their own
    // screen (#hazards, every staff role) and join the tab row only when HR is on (hr: true), so without HR #hazards and
    // #drills look exactly as before.
    { id: 'safety', hash: 'hr-safety', icon: '🦺', label: 'Health & safety', sub: 'Staff injuries, risk assessments, chemicals and safety data sheets, safe work procedures, first aiders and fire wardens, H&S tasks, hazards and drills.',
      tabs: [{ k: 'injuries', label: 'Staff injuries' }, { k: 'risk', label: 'Risk assessments' }, { k: 'chemicals', label: 'Chemicals (WHMIS)' },
        { k: 'procedures', label: 'Safe work procedures' }, { k: 'duties', label: 'H&S duties' }, { k: 'hstasks', label: 'H&S tasks' },
        { k: 'hazards', label: 'Hazards', screen: 'hazards', hr: true }, { k: 'drills', label: 'Drill log', screen: 'drills' }] },
    { id: 'documents', hash: 'hr-documents', icon: '📂', label: 'Documents & letters', sub: 'Company documents, HR letters and background checks.',
      tabs: [{ k: 'docs', label: 'Company documents' }, { k: 'letters', label: 'Letters' }, { k: 'checks', label: 'Background checks', screen: 'background-checks' }] },
    { id: 'reports', hash: 'hr-reports', icon: '📑', label: 'HR reports', sub: 'Headcount, turnover, leave, overtime, lateness, probation and more.',
      tabs: [{ k: 'reports', label: 'Reports' }] },
    { id: 'settings', hash: 'hr-settings', icon: '🛠️', label: 'HR settings', admin: true, sub: 'Leave year, short absences, attendance rules, and who approves time off.',
      tabs: [{ k: 'settings', label: 'Settings', admin: true }, { k: 'approvers', label: 'Approvers', admin: true }] }
  ];
  var BY_ID = {};
  SECTIONS.forEach(function (s) { BY_ID[s.id] = s; });

  /* THE "?" ON EVERY HR TAB moved to the banner (2026-11-27): kt-page-help.js draws ONE "?" in the same spot of every
     screen, tab-aware, and the tab → article map that lived here (HR Phase 4 batch 10) is now each article's own
      "screens:" front matter (e.g. hr-leave-types.md: screens: time-off?tab=types). The tab row no longer carries one,
     so the HR tabs never show two. helpFor/helpRow stay for old callers. */
  function helpFor(k) {
    var sec = null; SECTIONS.forEach(function (s) { s.tabs.forEach(function (t) { if (t.k === k) sec = sec || s; }); });
    var h = sec ? '#' + sec.hash + '?tab=' + k : location.hash;
    return (KT.pageHelp && KT.pageHelp.slugsFor(h)) || [];
  }
  function helpEl() { return null; }
  function helpRow() { return null; }

  // Old #hr?tab=<key> → [section id, new tab key].
  var OLD = { dashboard: ['dashboard'], people: ['people'], leavers: ['people', 'leavers'], balances: ['timeoff', 'balances'],
    types: ['timeoff', 'types'], absence: ['absence'], reports: ['reports'], hiring: ['hiring', 'hiring'], onboarding: ['hiring', 'onboarding'],
    performance: ['performance', 'performance'], courses: ['performance', 'courses'], injuries: ['safety', 'injuries'],
    claims: ['payroll', 'claims'], overtime: ['time', 'overtime'], timesheets: ['time', 'lock'], rota: ['rota', 'rota'],
    openshifts: ['rota', 'openshifts'], letters: ['documents', 'letters'], docs: ['documents', 'docs'], settings: ['settings'],
    directory: ['people', 'directory'], rotatools: ['rota', 'rotatools'], punchfix: ['time', 'punchfix'], orgchart: ['people', 'orgchart'], kudos: ['awards', 'kudos'], approvers: ['settings', 'approvers'] };

  function user() { try { return (KT.Auth && KT.Auth.user && KT.Auth.user()) || JSON.parse(sessionStorage.getItem('kt_user') || '{}') || {}; } catch (e) { return {}; } }
  // Every hash the plan leaves out, locked (Upgrade) ones included: a locked screen does not open either.
  function off(h) { var u = user(); return !!(u && Array.isArray(u.hidden_screens) && u.hidden_screens.indexOf(h) !== -1); }
  function hrOn() { return !off('hr'); }
  function isAdmin(role) { return role === 'agency_admin' || role === 'platform_admin'; }
  function baseOf(h) { return String(h || '').replace(/^#/, '').split('?')[0]; }
  function tabParam() { var m = /[?&]tab=([a-z]+)/.exec(location.hash || ''); return m ? m[1] : ''; }

  /* PAYROLL ENGINE TABS (P2, 2026-11-27): asked once per page load; HR settings' Save tells this through setPay(). When the
     answer arrives as "on" while a Payroll page is open, it is drawn again so its tab row has the new tabs. */
  var PAY = null, payAsked = false;
  function payOn() {
    if (PAY === null && !payAsked && KT.Api && KT.Api.get) {
      payAsked = true;
      KT.Api.get('/hr/payroll/engine').then(function (r) { setPay(!!(r && r.enabled)); }, function () { PAY = false; });
    }
    return PAY === true;
  }
  function setPay(v) {
    var was = PAY === true; PAY = !!v;
    if (was === PAY) return;
    /* The tab row on screen was built before the answer: a same-hash navigate does not redraw it (2026-11-27 browser
       check), so rebuild the row in place, or draw the pay tab itself when the address already names one. */
    if (!ACTIVE || !ACTIVE.sec || baseOf(location.hash) !== ACTIVE.hash) return;
    var k = tabParam();
    if (PAY && k && ACTIVE.sec.tabs.some(function (t) { return t.k === k && t.pay; })) {
      var main = document.getElementById('appMain');
      if (main) { drawHr(main, ACTIVE.sec, k, ACTIVE.role); }
      return;
    }
    var nb = band(ACTIVE.sec, ACTIVE.k, ACTIVE.role), old = ACTIVE.el;
    var host = old && old.parentNode && old.parentNode.classList && old.parentNode.classList.contains('kt-tbwrap') ? old.parentNode : old;
    if (nb && host && host.parentNode) { host.parentNode.replaceChild(nb, host); ACTIVE.el = nb; }
  }
  function tabOk(t, role) {
    if (t.admin && !isAdmin(role)) return false;
    if (t.pay && !payOn()) return false;
    if (t.hr && !hrOn()) return false;   // a pre-HR screen that joins this row only with HR
    // Every screen named here is registered for admins and directors alike (screen files load on demand).
    return t.screen ? !off(t.screen) : hrOn();
  }
  function tabsOf(sec, role) { return sec.tabs.filter(function (t) { return tabOk(t, role); }); }
  // The hash an HR tab of this section lives on: the pre-HR entry while it is on, else its HR-only twin.
  function home(sec) { return sec.alt && off(sec.hash) ? sec.alt : sec.hash; }
  function hrefOf(sec, t) {
    if (t.screen) return '#' + t.screen;
    var first = sec.tabs.filter(function (x) { return !x.screen; })[0];
    var h = home(sec);
    // On an HR-only hash the first HR tab is the bare hash; on a pre-HR entry an HR tab always names itself.
    var hrOnly = !BY_SCREEN[h];
    return '#' + h + (hrOnly && first && first.k === t.k ? '' : '?tab=' + t.k);
  }

  var CSS_ID = 'kt-hrsec-css';
  function css() {
    if (document.getElementById(CSS_ID)) return;
    var s = document.createElement('style'); s.id = CSS_ID;
    s.textContent = '.kt-hrsec{display:flex;gap:6px;flex-wrap:wrap;margin:14px 0 10px;}'
      + '.kt-hrsec .hrsec-tab{height:30px;padding:0 13px;border-radius:999px;border:1px solid #E2E8F0;background:#fff;font-size:12.5px;font-weight:700;cursor:pointer;color:#475569;}'
      + '.kt-hrsec .hrsec-tab[aria-selected="true"]{background:#0E7490;border-color:#0E7490;color:#fff;}'
      + 'html.kt-dark .kt-hrsec .hrsec-tab{background:#0F172A;border-color:#334155;color:#E2E8F0;}'
      + 'html.kt-dark .kt-hrsec .hrsec-tab[aria-selected="true"]{background:#0E7490;border-color:#0E7490;color:#fff;}';
    document.head.appendChild(s);
  }

  /* The section's tab row, or null when there is only one tab to show. */
  function band(sec, activeKey, role) {
    var ts = tabsOf(sec, role);
    if (ts.length < 2) return null;
    css();
    var el = document.createElement('div');
    el.className = 'kt-hrsec'; el.setAttribute('role', 'tablist'); el.setAttribute('data-kt-hrsec', sec.id);
    el.setAttribute('aria-label', sec.label);
    ts.forEach(function (t) {
      var b = document.createElement('button');
      b.type = 'button'; b.className = 'hrsec-tab'; b.setAttribute('role', 'tab');
      b.setAttribute('data-kt-iconized', '1');   // kt-icon-buttons would turn "Open shifts" into an ℹ️
      b.setAttribute('data-hrsec-tab', t.k);
      b.setAttribute('aria-selected', String(t.k === activeKey));
      b.textContent = t.label;
      b.addEventListener('click', function () {
        if (t.k === activeKey) return;
        var to = hrefOf(sec, t);
        if (location.hash === to && KT.Shell.navigate) { try { KT.Shell.navigate(to.slice(1)); } catch (e) {} return; }
        location.hash = to;
      });
      el.appendChild(b);
    });
    var hb = helpEl(activeKey); if (hb) el.appendChild(hb);   // HR4B10: the "?" for the tab on screen
    return el;
  }

  /* ── the tab row under a pre-HR screen's banner ─────────────────────────────────────────────
     The screen paints into #appMain on its own schedule (the legacy bridge waits for its module, and some
     redraw the whole of #appMain after an action), so the row is kept in place by one observer, through
     KT.observeMain (it re-binds when the shell swaps #appMain). It acts only while the hash is still the one
     the row was made for. */
  var ACTIVE = null, obs = null, queued = false;
  function place() {
    queued = false;
    if (!ACTIVE || baseOf(location.hash) !== ACTIVE.hash) return;
    var main = document.getElementById('appMain'); if (!main) return;
    /* On a narrow screen kt-tab-band wraps an overflowing row as div.kt-tbwrap [‹][row][›]. Move the wrapper, never
       the row out of it: that left the arrows behind as a row of their own under the tabs (390px check, 2026-11-27). */
    var el = ACTIVE.el, hero = main.querySelector('.kt-hero');
    var pw = el.parentNode;
    if (pw && pw.classList && pw.classList.contains('kt-tbwrap')) el = pw;
    if (hero && hero.parentNode) {
      if (el.parentNode === hero.parentNode && el.previousElementSibling === hero) return;
      hero.parentNode.insertBefore(el, hero.nextSibling);
      return;
    }
    if (el.parentNode === main) return;
    var bar = main.querySelector('#kt-topbar');
    main.insertBefore(el, bar && bar.parentNode === main ? bar.nextSibling : main.firstChild);
  }
  function watch() {
    if (obs || !KT.observeMain) return;
    obs = KT.observeMain(function () { if (ACTIVE && !queued) { queued = true; (window.requestAnimationFrame || setTimeout)(place); } });
  }
  window.addEventListener('hashchange', function () { if (ACTIVE && baseOf(location.hash) !== ACTIVE.hash) ACTIVE = null; });

  function ensureHr(role) {
    var p = (KT.screenLoader && KT.screenLoader.ensure) ? KT.screenLoader.ensure(role, 'hr') : Promise.resolve(true);
    return Promise.resolve(p).then(function () {
      if (window.KTHr && window.KTHr.renderSection) return true;
      return new Promise(function (res) {   // the file has run but its module may still be settling
        var n = 0; (function w() { if ((window.KTHr && window.KTHr.renderSection) || ++n > 60) return res(true); setTimeout(w, 50); })();
      });
    });
  }
  function drawHr(main, sec, t, role, ctx) {
    ACTIVE = null;
    return ensureHr(role).then(function () {
      if (!main.isConnected) return;
      if (!window.KTHr || !window.KTHr.renderSection) { main.innerHTML = '<div class="kt-section"><div class="hr-sub" style="padding:16px;">This page did not load. Please refresh.</div></div>'; return; }
      return window.KTHr.renderSection(main, sec, t, role, ctx);
    });
  }

  // Which section a pre-HR (or member) screen belongs to, and as which tab.
  var BY_SCREEN = {};
  SECTIONS.forEach(function (s) { s.tabs.forEach(function (t) { if (t.screen) BY_SCREEN[t.screen] = { sec: s, tab: t }; }); });

  function wrap(name, fn) {
    if (typeof fn !== 'function' || fn.__ktHrSec) return fn;
    var i = String(name).indexOf(':'); if (i < 0) return fn;
    var role = name.slice(0, i), hash = name.slice(i + 1);
    var hit = BY_SCREEN[hash];
    if (!hit || ROLES.indexOf(role) === -1) return fn;
    var w = function (main, ctx) {
      var self = this, args = arguments;
      if (baseOf(location.hash) === hash) {
        var sec = hit.sec, k = tabParam();
        // ?tab=<HR tab> on the pre-HR entry: that HR tab instead of the screen.
        var t = k && hash === sec.hash ? tabsOf(sec, role).filter(function (x) { return x.k === k && !x.screen; })[0] : null;
        if (t) return drawHr(main, sec, t.k, role, ctx);
        var el = band(sec, hit.tab.k, role);
        ACTIVE = el ? { hash: hash, el: el, sec: sec, k: hit.tab.k, role: role } : null;
        if (el) { watch(); }
        var r = fn.apply(self, args);
        if (el) { Promise.resolve(r).then(place, place); place(); }
        return r;
      }
      return fn.apply(self, args);
    };
    w.__ktHrSec = 1;
    return w;
  }

  // Wrap what is registered already, and everything registered from now on (lazy files included).
  var reg = Shell.registerScreen;
  Shell.registerScreen = function (name, fn) { return reg.call(this, name, wrap(name, fn)); };
  ROLES.forEach(function (r) {
    Object.keys(BY_SCREEN).forEach(function (h) { var f = Shell.screenFn(r + ':' + h); if (f && !f.__ktHrSec) reg.call(Shell, r + ':' + h, wrap(r + ':' + h, f)); });
  });

  // The HR-only entries (and the HR-only twins of the pre-HR ones). #hr itself is screen-hr.js's.
  SECTIONS.forEach(function (sec) {
    [sec.hash, sec.alt].forEach(function (h) {
      if (!h || h === 'hr' || BY_SCREEN[h] || h.indexOf('hr-') !== 0) return;
      ROLES.forEach(function (role) {
        reg.call(Shell, role + ':' + h, function (main, ctx) {
          if (sec.admin && !isAdmin(role)) { location.replace('#hr'); return; }
          var k = tabParam(), ts = tabsOf(sec, role).filter(function (x) { return !x.screen; });
          var t = ts.filter(function (x) { return x.k === k; })[0] || ts[0];
          return drawHr(main, sec, t ? t.k : sec.tabs[0].k, role, ctx);
        });
      });
    });
  });

  /* Old #hr?tab=<key>&… → its new entry, keeping the other parameters (person=, open=, view=, report=). */
  function redirectFor(hash) {
    var k = /[?&]tab=([a-z]+)/.exec(hash || ''); if (!k || !OLD[k[1]]) return '';
    var o = OLD[k[1]], sec = BY_ID[o[0]];
    if (sec.id === 'dashboard') return '';
    var t = o[1] ? sec.tabs.filter(function (x) { return x.k === o[1]; })[0] : null;
    var rest = String(hash).split('?')[1] || '';
    rest = rest.split('&').filter(function (p) { return p && !/^tab=/.test(p); }).join('&');
    var to = t ? hrefOf(sec, t) : '#' + home(sec);
    if (rest) to += (to.indexOf('?') === -1 ? '?' : '&') + rest;
    return to.slice(1);
  }

  // Ask about the payroll engine as the page loads, so the Payroll tab row is usually right first time.
  try { if (sessionStorage.getItem('kt_token') || localStorage.getItem('kt_token')) setTimeout(payOn, 0); } catch (e) {}

  window.KTHrSections = { list: SECTIONS, byId: BY_ID, band: band, tabsOf: tabsOf, hrefOf: hrefOf, home: home, redirectFor: redirectFor, isAdmin: isAdmin,
    helpFor: helpFor, helpRow: helpRow, setPay: setPay };
})(window);
