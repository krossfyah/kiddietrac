/* ═══════════════════════════════════════════════════════════════════
   KIDDIETRAC v2 — App Shell
   Common router, modal system, role detection
   ═══════════════════════════════════════════════════════════════════ */

(function (window) {
  'use strict';

  // ─── v17.3: Suppress legacy nav-additions IMMEDIATELY ─────────
  // The v12/v13/v14/v15 nav-additions files run on DOMContentLoaded
  // and inject their own <div id="kt-v##-nav-extra"> elements as
  // siblings of #navLinks. If they have the guard we shipped in v17
  // (an early-return checking this flag), they bail. Setting the flag
  // at IIFE-load time guarantees the guard sees it set regardless of
  // script execution order.
  window.KT_V17_NAV_INSTALLED = true;

  const { Auth, Api, Fmt, Dom } = window.KT;

  /* ─── A PER-RENDER #appMain ─────────────────────────────────────────────────
     THE PROBLEM. A screen is handed `main` (#appMain) and paints into it AFTER an
     await. Navigate — or let a background refresh land — while that fetch is still in
     flight, and the abandoned screen's late paint arrives in the screen that replaced
     it. Serialising renders cannot reach this: that render already returned.

     165 render paths in 50 files write to the handed container after an await. Guarding
     each one (the liveEl() + generation pattern in screen-provider-day.js) is 165 edits
     that must all stay correct forever. Swapping the NODE fixes all of them at once: a
     stale writer keeps its reference and paints into an orphan, where nothing is seen.

     WHAT IT COSTS. `#appMain` id-based CSS keeps working — 47 rules select `#appMain > *`
     and they match the new node exactly as they did the old one. But a MutationObserver
     binds to a NODE, so every observer watching #appMain stops silently at the swap. Ten
     of them do: kt-animate, kt-banner-normalize, kt-clockbar, kt-mobile-tables,
     kt-mobilenav, kt-phone, kt-reload-diag, role-widgets, screen-dashboard-widgets, and
     the shell's own. Silently is the dangerous word — no error, just phone tables that
     stop restacking and a clock bar that never reappears.

     So the swap announces itself and the observers re-bind through observeMain() below.
     Checked first: none of those files HOLDS the node across calls — every one resolves
     it with getElementById at use time — so the binding is the only thing at risk. */
  KT.observeMain = function (fn, opts) {
    var options = opts || { childList: true, subtree: true };
    var obs;
    try { obs = new MutationObserver(fn); } catch (e) { return null; }

    function bind() {
      var m = document.getElementById('appMain');
      try { obs.disconnect(); } catch (e) {}
      if (m) { try { obs.observe(m, options); } catch (e) {} }
    }

    bind();
    try { document.addEventListener('kt:main-swapped', bind); } catch (e) {}

    return obs;
  };

  /* Replace #appMain with an empty node of the same identity, and hand the new one back.
     Falls back to the old behaviour on ANY failure, and can be turned off outright with
     window.__ktNoMainSwap — this sits on the path every screen in the portal renders
     through, so it must never be the thing that takes the app down. */
  /* SCREEN-SCOPED MARKERS (2026-10-09). Anthony: "look at what happens when i refresh the
     screen is not full size at times". data-kt-pretty / data-kt-screen / data-kt-narrow
     describe ONE screen's layout ([data-kt-pretty] > div is the 1800px content width, plus
     the table, label and button styling). Screens set them during render and the route
     list in screen-v22p55 sets them 250ms after a hashchange, but nothing ever removed
     data-kt-pretty, and the copy below carried it onto every later screen. So a screen's
     width depended on where you came from: Xero measured 1100px after a reload and 1613px
     after visiting Admin → Children (same build, same viewport), and a refresh "shrank" it.
     On NAVIGATION these three are now dropped and the route's own markers re-applied at
     once (KT.applyPrettyClass), so each screen looks the same however you arrive; a
     same-screen re-render still keeps them, which is what the copy was for. */
  var __KT_SCREEN_MARKERS = { 'data-kt-pretty': 1, 'data-kt-screen': 1, 'data-kt-narrow': 1 };
  function __ktSwapMain(main, isNavigation) {
    try {
      if (window.__ktNoMainSwap || !main || !main.parentNode) { Dom.clear(main); return main; }

      var fresh = document.createElement(main.tagName);
      /* EVERY attribute, including data-kt-pretty. It is applied 250ms after a
         navigation, so a fresh node without it would drop the table styling on every
         refresh and get it back a quarter-second later — a flash, which is the opposite
         of what this work is for. */
      for (var i = 0; i < main.attributes.length; i++) {
        var a = main.attributes[i];
        /* EXCEPT the reveal hold. data-kt-revealing hides every child of #appMain until
           that navigation's reveal clears it - and the reveal clears the node it was SET
           on. A same-screen re-render inside that window (live refresh, data arriving)
           swapped in a fresh node that copied the hold, while the reveal and all three
           backstops then cleared the old, detached node: the live page stayed opacity 0
           for good, top bar visible, everything else white (Anthony, 2026-10-06:
           "randomly portal goes to a blank white screen when navigating"; reproduced
           5/5 with a renderScreen() 150ms after a navigation). A navigation sets its own
           hold on the node it has just swapped in, so nothing is lost by not copying it. */
        if (a.name === 'data-kt-revealing') { continue; }
        if (isNavigation && __KT_SCREEN_MARKERS[a.name]) { continue; }
        try { fresh.setAttribute(a.name, a.value); } catch (e) {}
      }

      /* #appMain is the ONE scroller on a phone. A fresh node starts at the top, which
         would throw the reader back to the beginning of a list on every refresh. */
      var top = 0;
      try { top = main.scrollTop || 0; } catch (e) {}

      main.parentNode.replaceChild(fresh, main);
      try { fresh.scrollTop = top; } catch (e) {}
      // The new screen's route-level markers, now rather than 250ms from now (no flash).
      if (isNavigation) { try { if (window.KT && KT.applyPrettyClass) KT.applyPrettyClass(); } catch (e) {} }

      try {
        document.dispatchEvent(new CustomEvent('kt:main-swapped', { detail: { node: fresh } }));
      } catch (e) {
        /* Older WebViews without the CustomEvent constructor still get their observers
           back, because a failure here must not leave ten sweeps dead. */
        try {
          var ev = document.createEvent('Event');
          ev.initEvent('kt:main-swapped', false, false);
          document.dispatchEvent(ev);
        } catch (e2) {}
      }

      return fresh;
    } catch (e) {
      try { Dom.clear(main); } catch (e2) {}

      return main;
    }
  }

  // ──────────────── Role detection ─────────────────────────────
  /* THE ROLES THIS SCREEN IS FOR (2026-11-09, Anthony: "check the guardian as a user and see why are some subtabs
     pulling up as if he is a staff"). While a super admin previews a role (View as), every screen must answer as
     that role. Screens that read kt_user.roles directly saw the real account - platform admin, agency admin and,
     for Anthony, guardian too - so Payment schedules opened its staff family picker, Conferences its staff view,
     Support its staff tickets, and staff pollers kept asking staff routes, all inside the parent preview.
     ktRoles(u) is the same rule as Roles.primaryRoleOf: the previewed role when a platform admin is previewing,
     otherwise the account's own roles. Ask it instead of u.roles whenever the answer changes what is DRAWN. */
  window.ktRoles = function (u) {
    try { if (!u) u = JSON.parse(sessionStorage.getItem('kt_user') || localStorage.getItem('kt_user') || '{}') || {}; } catch (e) { u = {}; }
    if (u && u.user && !u.roles) u = u.user;
    var roles = (u && Array.isArray(u.roles)) ? u.roles.slice() : [];
    var va = ''; try { va = sessionStorage.getItem('kt_view_as') || ''; } catch (e) {}
    if (va && (roles.indexOf('platform_admin') !== -1 || (u && u.is_platform_admin))) return [va];
    return roles;
  };
  const Roles = {
    primaryRoleOf(user) {
      if (!user || !user.roles) return null;
      var _va = null; try { _va = sessionStorage.getItem('kt_view_as'); } catch (e) {}
      if (_va && user.roles.indexOf('platform_admin') !== -1 && ['agency_admin','centre_director','educator','guardian','auditor','home_visitor','sales_rep'].indexOf(_va) !== -1) return _va;
      // v22p39: platform_admin gets routed through the agency_admin shell
      // so a user who holds ONLY the platform role still gets a nav and
      // dashboard rendered. Previously primary_role came back as null and
      // the shell hung on 'Loading your workspace…' indefinitely. The
      // platform-overview + all-agencies links are injected separately
      // by agency-switcher.js, so nothing is lost.
      if (user.roles.includes('agency_admin'))    return 'agency_admin';
      if (user.roles.includes('platform_admin'))  return 'agency_admin';
      if (user.roles.includes('centre_director')) return 'centre_director';
      if (user.roles.includes('educator'))        return 'educator';
      if (user.roles.includes('guardian'))        return 'guardian';
      if (user.roles.includes('home_visitor'))    return 'home_visitor';
      if (user.roles.includes('sales_rep'))       return 'sales_rep';
      // A reseller admin manages one reseller's brand (2026-10-21, screen-resellers.js).
      if (user.roles.includes('reseller_admin'))  return 'reseller_admin';
      if (user.roles.includes('auditor'))         return 'auditor';
      return null;
    },

    isStaff(user) {
      const r = this.primaryRoleOf(user);
      return r === 'agency_admin' || r === 'centre_director' || r === 'educator';
    },

    isDirector(user) {
      const r = this.primaryRoleOf(user);
      return r === 'agency_admin' || r === 'centre_director';
    },

    isEducator(user) {
      return this.primaryRoleOf(user) === 'educator';
    },

    isGuardian(user) {
      return this.primaryRoleOf(user) === 'guardian';
    },
  };

  // ──────────────── Nav builder (v17 — grouped sidebar) ────────
  // Each section: { label, items: [{ hash, label, icon, badgeKey? }] }
  /* FEATURE FLAGS, FINALLY CONNECTED TO SOMETHING (2026-09-17).

     A flag could be switched off for an agency and every menu item stayed exactly where
     it was — the flags were stored, shown in an admin screen, and read by nothing. The
     API gate is the half that matters for entitlement (CheckFeatureFlag); this is the
     half people can see.

     The list of hidden screens arrives on the user payload as `hidden_screens`, computed
     from the catalog on the server. Deliberately NOT a copy of the feature→screen map in
     JavaScript: one mapping, in one file, or the next screen added to a feature gets
     remembered in one of the two places.

     An EMPTY list is the normal case — a flag has to be explicitly switched off to
     appear here — so this costs a filter over a dozen items and changes nothing for
     almost everybody. A section left with no items is dropped rather than rendered as a
     bare heading. */
  /* FILTERING navItemsForRole() IS NOT ENOUGH — three things build this menu.

     The sidebar sections come from navItemsForRole(), but `agency-switcher.js` injects a
     whole Platform/Sales block into #navLinks after its /auth/agencies fetch returns, and
     `screen-role-home.js` paints tiles into the screen itself. Filtering the first one
     left the sales items sitting in the menu with the flag off — which is what testing
     on Test Agency showed, and is exactly the sort of thing that makes a feature flag
     untrustworthy.

     So the rule is applied to the LIVE DOM as well: anything pointing at a hidden hash
     goes, whoever put it there and whenever. Runs only for an agency that has switched
     something off, and a nav link is cheap to look up. (2026-09-17) */
  function hiddenHashes() {
    try {
      var u = Auth.user();
      return notLocked((u && Array.isArray(u.hidden_screens)) ? u.hidden_screens : [], u);
    } catch (e) { return []; }
  }
  /* PLAY SPARK FOR EVERY PLAN (2026-11-24): a parent's and a home visitor's Play Spark is AI only. That rule moved
     to the server (FeatureFlagController::hiddenHashesFor, 2026-11-26), so hidden_screens is the one answer. */

  /* LOCKED FEATURES: AN AGENCY ADMIN SEES THEM AS AN UPGRADE (2026-11-26, Anthony: "show to AGENCY ADMINS only as
     upsell - greyed menu items/tiles with an Upgrade badge opening a clean info popup"). The server sends
     locked_screens (App\Support\PlanUpsell) only to an agency admin of a licensed agency: hash -> {feature, label,
     blurb, via}. Those hashes stay in hidden_screens - search, the route guard and the API still treat them as off -
     but the menu and the tiles keep them, greyed with a badge, and a tap explains instead of navigating. Staff and
     parents get no locked_screens, so for them nothing changes: the item is simply not there. */
  function lockedMap(u) {
    try {
      u = u || Auth.user();
      var m = u && u.locked_screens;
      return (m && typeof m === 'object' && !Array.isArray(m)) ? m : {};
    } catch (e) { return {}; }
  }
  function notLocked(list, u) {
    var L = lockedMap(u);
    return (list || []).filter(function (h) { return !L[h]; });
  }
  (function upsellStyle() {
    try {
      if (document.getElementById('kt-upsell-css')) return;
      var st = document.createElement('style'); st.id = 'kt-upsell-css';
      st.textContent = '.kt-locked{opacity:.55;filter:grayscale(.6);cursor:pointer}'
        + '.kt-locked:hover{opacity:.8}'
        + '.kt-upgrade-badge{display:inline-flex;align-items:center;margin-left:auto;padding:1px 7px;border-radius:999px;font-size:10px;font-weight:800;letter-spacing:.02em;line-height:16px;color:#fff;background:linear-gradient(135deg,#1E3A8A,#0D9488);opacity:1;filter:none;white-space:nowrap;flex:0 0 auto}'
        + '.kt-tile .kt-upgrade-badge{position:absolute;top:8px;right:8px;margin:0}'
        + '.kt-upsell p{margin:0 0 10px;font-size:14px;line-height:1.5;color:var(--kt-ink,#334155)}'
        + '.kt-upsell .kt-upsell-via{display:inline-block;margin:2px 0 12px;padding:4px 10px;border-radius:8px;font-size:12.5px;font-weight:700;background:rgba(13,148,136,.12);color:#0F766E}'
        + '.kt-upsell .kt-upsell-note{font-size:12px;color:#64748B;margin:0}';
      (document.head || document.documentElement).appendChild(st);
    } catch (e) {}
  })();
  function markLocked(root) {
    var L = lockedMap();
    var keys = Object.keys(L);
    if (!keys.length || !root) { return; }
    keys.forEach(function (h) {
      var nodes;
      try { nodes = root.querySelectorAll('[href="#' + h + '"],[data-hash="' + h + '"]'); } catch (e) { return; }
      [].forEach.call(nodes, function (el) {
        if (el.getAttribute('data-kt-locked')) { return; }
        el.setAttribute('data-kt-locked', h);
        el.setAttribute('aria-disabled', 'true');
        el.classList.add('kt-locked');
        el.setAttribute('data-kttip', 'Not in your plan yet - tap to see how to add it');
        var b = document.createElement('span');
        b.className = 'kt-upgrade-badge';
        b.textContent = 'Upgrade';
        el.appendChild(b);
      });
    });
  }
  window.KT.markLocked = markLocked;
  function upsellBody(info) {
    var wrap = Dom.el('div', { class: 'kt-upsell' });
    wrap.appendChild(Dom.el('p', {}, info.blurb || info.label));
    wrap.appendChild(Dom.el('div', { class: 'kt-upsell-via' }, 'Comes with ' + (info.via || 'an upgrade')));
    wrap.appendChild(Dom.el('p', { class: 'kt-upsell-note' }, typeof info.note === 'string' ? info.note
      : 'Only agency admins see this. Your team and families will see it once it is part of your plan.'));
    return wrap;
  }
  async function askForUpgrade(info, hash) {
    await window.KT.Api.post('/tickets', {
      request_type: 'addon',
      impact: 'question',
      subject: 'Add to our plan: ' + info.label,
      body: 'We would like to add ' + info.label + ' (' + (info.via || 'upgrade') + ') to our plan.',
      context: { screen: '#' + hash, feature: info.feature },
    });
    if (Dom.toast) Dom.toast('Sent - we will be in touch about ' + info.label + '.', 'success');
  }
  function openUpsell(hash) {
    var info = lockedMap()[hash];
    if (!info) { return; }
    openUpsellInfo(info, hash);
  }
  function openUpsellInfo(info, hash) {
    var platform = false;
    try { platform = Roles.primaryRoleOf(Auth.user()) === 'platform_admin'; } catch (e) {}
    Modal.open({
      title: '🔒 ' + info.label,
      body: upsellBody(info),
      actions: platform
        // A platform admin adds it themselves, on the Licences screen.
        ? [{ label: 'Not now' }, { label: 'Open Licences', primary: true, handler: function () { location.hash = '#licences'; } }]
        : [
        { label: 'Not now' },
        { label: 'See my plan', handler: function () { location.hash = '#licence'; } },
        { label: 'Ask about adding it', primary: true, busyLabel: 'Sending…', handler: function () { return askForUpgrade(info, hash || (location.hash || '').slice(1)); } },
      ],
    });
  }
  window.KT.openUpsell = openUpsell;
  window.KT.openUpsellInfo = openUpsellInfo;
  // An API refusal carrying an upsell (the centre cap) - raised by api-helpers-v10.js.
  window.addEventListener('kt:upsell', function (ev) {
    var info = ev && ev.detail;
    if (!info || !info.label) { return; }
    setTimeout(function () { openUpsellInfo(info); }, 0);   // after the caller's own popup has closed or reset
  });
  // A tap on a locked item explains instead of opening a screen the plan does not include.
  document.addEventListener('click', function (ev) {
    var a = ev.target && ev.target.closest ? ev.target.closest('[data-kt-locked]') : null;
    if (!a) { return; }
    ev.preventDefault();
    ev.stopPropagation();
    if (ev.stopImmediatePropagation) { ev.stopImmediatePropagation(); }
    openUpsell(a.getAttribute('data-kt-locked'));
  }, true);

  function pruneHiddenLinks(root) {
    try { markLocked(root); } catch (e) {}
    var hidden = hiddenHashes();
    if (!hidden.length || !root) { return; }
    hidden.forEach(function (h) {
      var sel = '[href="#' + h + '"],[data-hash="' + h + '"]';
      var nodes;
      try { nodes = root.querySelectorAll(sel); } catch (e) { return; }
      [].forEach.call(nodes, function (el) {
        /* The tile or link itself, not a wrapper that might hold siblings — except a
           nav-link wrapper the switcher builds, which exists only for this item. */
        var target = el.closest('.kt-tile, .nav-link, li') || el;
        if (target && target.parentNode && target !== root) { target.remove(); }
      });
    });
  }
  window.KT.pruneHiddenLinks = pruneHiddenLinks;

  function hideSwitchedOff(sections, user) {
    var hidden = notLocked((user && Array.isArray(user.hidden_screens)) ? user.hidden_screens : [], user);
    if (!hidden.length) { return sections; }
    var off = {};
    hidden.forEach(function (h) { off[String(h)] = true; });

    return (sections || []).map(function (sec) {
      if (!sec || !Array.isArray(sec.items)) { return sec; }
      var kept = sec.items.filter(function (it) { return !(it && off[String(it.hash)]); });
      return { label: sec.label, keepOrder: sec.keepOrder, items: kept };   // keepOrder: Human Resources keeps its order (2026-11-26)
    }).filter(function (sec) { return sec && Array.isArray(sec.items) && sec.items.length; });
  }

  function navItemsForRole(role) {
    if (role === 'agency_admin') {
      // v22p34: hide legacy 'Agencies' entry for callers who are ALSO platform_admin
      // — they already get a richer 'All agencies' link from agency-switcher.js,
      // and the legacy '/admin/agencies' route shows essentially the same data
      // with fewer SaaS-level controls (no suspend/resume, no white-label edit).
      var u_v22p34 = (function () { try { return JSON.parse(sessionStorage.getItem('kt_user') || '{}'); } catch (e) { return {}; } })();
      // v22p98: platform-only sections (Reseller, Website) must appear ONLY in the
      // real "Super admin (default)" view — NOT while previewing as agency admin.
      // The account holds both roles, so gate on the view-as selection too.
      var viewAs_v22p98 = (function () { try { return sessionStorage.getItem('kt_view_as') || ''; } catch (e) { return ''; } })();
      var isPlatformAdmin_v22p34 = Array.isArray(u_v22p34.roles) && u_v22p34.roles.indexOf('platform_admin') !== -1
        && (viewAs_v22p98 === '' || viewAs_v22p98 === 'platform_admin');
      // Home-visitor inspection forms are limited to iLearn (2) + Test Agency (6).
      // Fail-open when the active agency is unknown; the screen itself re-checks server-side.
      var _hccAg_v = parseInt(sessionStorage.getItem('kt_active_agency_id') || localStorage.getItem('kt_active_agency_id') || '0', 10);
      var hccEnabled_v = (!_hccAg_v) || _hccAg_v === 2 || _hccAg_v === 6 || isPlatformAdmin_v22p34;
      var overviewItems = [{ hash: 'dashboard', label: 'Agency overview', icon: '🏠' }, { hash: 'admin-centres', label: 'Centres', icon: '🏫' }, { hash: 'provider-day', label: 'Daily Overview', icon: '🗓️' }, { hash: 'provider-map', label: 'Provider map', icon: '🗺️' }];
      if (!isPlatformAdmin_v22p34) overviewItems.push({ hash: 'agencies', label: 'My agency', icon: '🏢' });   // an agency admin sees only their own (2026-10-05)
      // A Reseller works as agency admin inside the agencies it owns; its own section leads (2026-10-21).
      var isReseller_v = !isPlatformAdmin_v22p34 && Array.isArray(u_v22p34.roles) && u_v22p34.roles.indexOf('reseller_admin') !== -1
        && (viewAs_v22p98 === '' || viewAs_v22p98 === 'reseller_admin');
      return [
        ...(isReseller_v ? [{ label: 'Reseller', items: [
          { hash: 'reseller',          label: 'All my agencies', icon: '🏪' },
          { hash: 'reseller-onboard',  label: 'Onboard an agency', icon: '➕' },
          { hash: 'reseller-billing',  label: 'Billing & commissions', icon: '💳' },
          { hash: 'reseller-support',  label: 'Support desk',  icon: '🎧' },
          { hash: 'reseller-brand',    label: 'Branding',        icon: '🎨' },
          { hash: 'reseller-agencies', label: 'Agencies',        icon: '🏢' },
        ]}] : []),
        { label: 'Overview', items: overviewItems },
        { label: 'Operations', items: [
          { hash: 'notifications', label: 'Notifications', icon: '🔔', badgeKey: 'notif_unread' },
          { hash: 'contacts',       label: 'All contacts',    icon: '📇' },
          { hash: 'safe-arrival',   label: 'Safe Arrival',    icon: '🛡️' },
          { hash: 'educator-rewards', label: 'Educator rewards', icon: '⭐' },
          { hash: 'parent-issues',  label: 'Parent issues',   icon: '🗣️' },
          { hash: 'staff-calendar', label: 'Calendar',        icon: '📅' },
          { hash: 'care-log',       label: 'Daily log',        icon: '📝' },

          { hash: 'chat',           label: 'Message Centre',         icon: '💬', badgeKey: 'chat_unread' },
          { hash: 'phone',          label: 'Phone',                  icon: '📞' },   // shown only with html.kt-can-dial (app-v2.css)
          { hash: 'files',          label: 'Files',          icon: '📎' },
          { hash: 'email',          label: 'Email Client',     icon: '✉️' },
          { hash: 'announcements',  label: 'Announcements',    icon: '📢', badgeKey: 'announcement_unread' },
          { hash: 'observations',   label: 'Observations',     icon: '\ud83d\udc40' },
          { hash: 'play-spark', label: 'Play Spark', icon: '🧩' },   // play ideas + weekly lesson plans, merged 2026-11-24 (AI tabs need the AI Bundle)
          { hash: 'awards',         label: 'Awards',           icon: '\ud83c\udfc6' },
          { hash: 'schedule',       label: 'Schedule',         icon: '🗒️' },
          { hash: 'certifications', label: 'Certifications',   icon: '🎓' },
          { hash: 'incidents',     label: 'Incidents',        icon: '⚠️' },
          { hash: 'headcounts',    label: 'Headcounts',       icon: '🔢' },
          // Ontario sleep supervision (2026-10-13): hidden unless sleep checks are on (screen-sleep-room.js).
          { hash: 'sleep-room',    label: 'Sleep room',       icon: '😴' },
          { hash: 'hazards',       label: 'Hazards',         icon: '🚧' },
          // Ohio (2026-10-10): shown only for US-OH agencies or where switched on (screen-ohio.js).
          { hash: 'pfcc',          label: 'Ohio PFCC',        icon: '🎗️' },
          { hash: 'sutq',          label: 'Step Up To Quality', icon: '🌟' },
          { hash: 'withdrawals',   label: 'Withdrawals',      icon: '🚸' },
          { hash: 'medications',   label: 'Medications',      icon: '💊' },
          { hash: 'immunizations', label: 'Immunizations',    icon: '🩹' },
        ]},
        { label: 'Growth', items: [
          { hash: 'marketing-campaigns', label: 'Marketing',   icon: '📯' },
          { hash: 'tours',               label: 'Tours',       icon: '🚪' },
          { hash: 'sms',                 label: 'SMS broadcast', icon: '📱', badgeKey: 'sms_replies' },
          { hash: 'ai-churn',            label: 'Churn risk',  icon: '📉' },
          { hash: 'ai-docs',             label: 'AI doc extract', icon: '🪄' },
          { hash: 'forecast',            label: 'Enrolment forecast', icon: '📈' },
        ]},
        { label: 'Programs', items: [
          { hash: 'menu',                label: 'Weekly menu',     icon: '🍽' },
          { hash: 'field-trips',         label: 'Field trips',     icon: '🚐' },
          { hash: 'allergy-alerts',      label: 'Allergy alerts',  icon: '🥜' },
        ]},
        { label: 'Staff', items: [
          { hash: 'tasks',              label: 'Tasks',              icon: '☑️' },
          { hash: 'notes', label: 'Notes', icon: '📃' },   // Quick notes (2026-11-26, kt-quick-notes.js)
          { hash: 'substitutes',        label: 'Substitutes',        icon: '🙋' },
          // Fire drills and each educator's drill dates are a staff matter (2026-11-09, Anthony: "move drill log to staff section").
          { hash: 'drills',             label: 'Drill log',          icon: '🚒' },
        ]},
        // HUMAN RESOURCES (2026-11-26, Anthony): HR, My HR, time off, timesheets, payroll and background checks in one group. Each item keeps
        // its own gate (HR + My HR need the hr add-on via hidden_screens; the others show without it); an empty group is dropped.
        { label: 'Human Resources', keepOrder: true, items: [
          { hash: 'hr',                 label: 'HR',                 icon: '🧑‍💼' },
          { hash: 'my-hr',              label: 'My HR',              icon: '🧭' },
          { hash: 'time-off',           label: 'Time off requests',  icon: '🌴' },
          { hash: 'timesheets',         label: 'Timesheets',         icon: '🕘' },   // moved from Operations (2026-11-26, Anthony)
          { hash: 'payroll',            label: 'Payroll',            icon: '💼' },   // moved from Finance (2026-11-26, Anthony)
          { hash: 'background-checks',  label: 'Background checks',  icon: '🪪' },
        ]},
        // Home Visitors — own section (moved out of Compliance for clarity).
        { label: 'Home Visitors', items: [
          { hash: 'home-visit-reports', label: 'Home visit reports', icon: '🏘️' },
          ...(hccEnabled_v ? [{ hash: 'hcc-forms', label: 'Inspection forms', icon: '🏡' }] : []),
        ]},
        { label: 'Compliance', items: [
          { hash: 'policies',           label: 'Policies',             icon: '📘' },
          { hash: 'inspection',         label: 'Inspection checklist', icon: '🔎' },
          { hash: 'renewals',           label: 'Renewals calendar',    icon: '♻️' },
          { hash: 'cwelcc',             label: 'Subsidies',           icon: '💵' },
          // Ontario OCCMS reporting (2026-10-13): hidden unless an Ontario agency (screen-subsidy-roa.js).
          { hash: 'subsidy-roa',        label: 'OCCMS reporting',     icon: '🏷️' },
          { hash: 'retention',          label: 'Retention',          icon: '📊' },
          { hash: 'anomalies',          label: 'Anomalies',          icon: '🔍' },
        ]},
        // v22p98: the Reseller section (MRR, feature flags, branding, platform
        // tooling) is super-admin only — hide it from tenant agency admins.
        ...(isPlatformAdmin_v22p34 ? [{ label: 'Reseller', items: [
          { hash: 'resellers',      label: 'Resellers',        icon: '🤝' },   // 2026-10-21
          { hash: 'reseller',       label: 'Reseller overview', icon: '🏪' },
          // Invoices moved to the Platform section, beside Licences (2026-10-25).
          // MRR, Usage & spend, Feature flags, Branding, Maintenance, Email and AI digest status moved to the
          // Platform section (agency-switcher.js injectPlatformNav), 2026-10-25.
          // Phone & voicemail moved to Settings → Phone & carriers (#sms-settings), 2026-10-22.
        ]}] : []),
        // Website now sits in the Platform section (agency-switcher.js injectPlatformNav), 2026-10-23.
        { label: 'Enrollment', items: [
          { hash: 'invitation-codes', label: 'Invitation codes', icon: '🔖' },
          { hash: 'edocuments',       label: 'eDocuments',       icon: '🖋️' },
          { hash: 'synced-waitlist',  label: 'Waitlist',          icon: '⏳' },
        ]},
        { label: 'Finance', items: [
          { hash: 'late-pickups',     label: 'Late pickups',     icon: '🌙' },
          { hash: 'bulk-invoices',    label: 'Bulk invoice run', icon: '💸' },
          { hash: 'payment-run',      label: 'Payment run',      icon: '💳' },
          { hash: 'billing-schedule', label: 'Billing schedule', icon: '🧮' },
          { hash: 'payment-plans',    label: 'Payment schedules', icon: '🏧' },
          { hash: 'refunds',          label: 'Refunds',          icon: '↩' },
          { hash: 'tuition-increases',label: 'Tuition increases',icon: '📈' },
          { hash: 'expenses',         label: 'Expenses',         icon: '🧾' },
          { hash: 'external-billing', label: 'Accounting',        icon: '🗃️' },
          { hash: 'account-ledgers',  label: 'Account ledgers',  icon: '📔' },
          { hash: 'donations',        label: 'Donations',        icon: '💝' },
          { hash: 'grants',           label: 'Grants',           icon: '🏛️' },
          // Ontario WEG + workforce compensation (2026-10-13): hidden unless Ontario + on (screen-workforce-funding.js).
          { hash: 'workforce-funding',  label: 'Workforce funding',  icon: '🍁' },
        ]},
        { label: 'Administration', items: [
          { hash: 'admin-users',      label: 'User management',  icon: '🆔' },
          { hash: 'admin-families',   label: 'Families',         icon: '👪' },
          { hash: 'admin-children',   label: 'Children',         icon: '🧒' },
          { hash: 'admin-forms',      label: 'Custom forms',     icon: '🗳️' },
          { hash: 'compliance',       label: 'Compliance',       icon: '✅' },
          // Staff training & household residents (2026-10-10): shown only where the jurisdiction has rules (kt-staff-compliance.js).
          { hash: 'household',        label: 'Household residents', icon: '🛋️' },
          { hash: 'audit-logs',       label: 'Audit log',        icon: '📜' },
          { hash: 'privacy-breaches', label: 'Privacy breaches', icon: '🔓' },   // PIPEDA breach register (2026-11-06)
          { hash: 'privacy-requests', label: 'Privacy requests', icon: '📥' },   // PIPEDA s. 8 access / correction requests (2026-11-10)
          { hash: 'photo-permissions', label: 'Photo permissions', icon: '🤳' },  // per-child photo consent (2026-11-06)
          { hash: 'closures',         label: 'Closures',         icon: '🚫' },
          { hash: 'room-ratios',      label: 'Room ratios',      icon: '⚖️' },
          { hash: 'vacation-holds',   label: 'Vacation holds',   icon: '🏖' },
          { hash: 'bus-routes',       label: 'Bus routes',       icon: '🚌' },
          { hash: 'room-rotations',   label: 'Room rotations',   icon: '🔄' },
          { hash: 'reports',          label: 'Reports',          icon: '📋' },

          { hash: 'cacfp',            label: 'CACFP meals',      icon: '🥕' },
          { hash: 'report-cards',     label: 'Report cards',    icon: '📑' },
          { hash: 'zones',            label: 'Activity zones',  icon: '🧩' },
          { hash: 'tickets',          label: 'Support tickets', icon: '🎫' },
          { hash: 'attendance-pattern',label: 'Attendance days', icon: '📍' },
          { hash: 'trip-gps',         label: 'Walk Watch',      icon: '📍' },   // brand, 2026-10-26 (route unchanged)
          { hash: 'wellness-digest',  label: 'Wellness digest', icon: '🩺' },
          { hash: 'doc-workflows',    label: 'Doc workflows',   icon: '🔀' },
        ]},
        { label: 'Engagement', items: [
          { hash: 'reenrollment',     label: 'Re-enrollment',  icon: '🔁' },
          { hash: 'engagement',       label: 'Engagement score', icon: '💚' },
          { hash: 'nps',              label: 'NPS', icon: '💯' },
          { hash: 'signed-docs',      label: 'Signed documents', icon: '✍' },
          { hash: 'drip-campaigns',   label: 'Drip campaigns',  icon: '💧' },
          { hash: 'curriculum',       label: 'Curriculum',      icon: '🌱' },
          { hash: 'hdlh-gaps',        label: 'Framework gaps',  icon: '🔬' },
          { hash: 'videos',           label: 'Video feed',      icon: '🎬' },
          { hash: 'conferences',      label: 'Conferences',     icon: '🪑' },
          { hash: 'rsvp',             label: 'Event RSVPs',     icon: '🎟️' },
          { hash: 'forms-manager',    label: 'Forms Manager',   icon: '🗂️' },
        ]},
        { label: 'Settings', items: [
          /* YOUR OWN SETTINGS (2026-10-21). Anthony: "where in the desktop view can I change
             the notification sounds". Everything else in this group is the AGENCY's; your
             notification sounds, two-step and profile panes live at #settings, which an
             admin could only reach by typing it. */
          { hash: 'settings', label: 'My settings', icon: '⚙️' },
          { hash: 'billing-settings',  label: 'Billing', icon: '💱' },
          // The agency's licence is NOT here: it sits in the sidebar foot beside the build line
          // (2026-10-25, Anthony). See installBuildFoot().
          { hash: 'clock-settings' ,     label: 'Clock settings',          icon: '⏱️' },
          // Automatic monthly thank-you awards (2026-11-02, Anthony: "the automatic awards should be a setting in the settings section").
          { hash: 'educator-award-settings', label: 'Educator awards',  icon: '🏅' },
          // Scheduled reminders to the agency's own reviewers (2026-10-09).
          // The AGENCY's scheduled reminders, named so it is not mistaken for your own.
          { hash: 'notification-settings', label: 'Agency notifications',  icon: '📳' },
          { hash: 'payment-providers',  label: 'Payment providers',       icon: '🔌' },
          { hash: 'learning-framework', label: 'Learning framework',      icon: '🧭' },
          /* SETTINGS, not Reseller. Backups are platform-level (one database sits behind
             every agency, so the controller still requires platform_admin) but "where do
             I check the backups" is a settings question, and Reseller is where the
             billing-the-agencies screens live. Anthony went to Settings and did not find
             it, which is the whole argument. */
          // Backups and Compliance evidence moved to the Platform section (2026-10-25, Anthony's choice).
          /* No "My profile" here (2026-10-01, Anthony): your name in the top bar opens
             it, so Settings holds agency settings only. #my-profile still routes. */
          { hash: 'educator-rooms', label: 'Room Controls', icon: '🎛️' },
          { hash: 'calendar-settings',     label: 'Calendar settings',           icon: '🕰️' },
          { hash: 'admin-roles',        label: 'Roles & permissions', icon: '🛂' },
          { hash: 'email-settings',     label: 'Email settings',      icon: '📩' },
          /* Two carriers and two channels live behind this now, so naming it after
             one of them was already wrong and naming it after SMS alone hides the
             voice half. The HASH is untouched — it is in bookmarks and in the
             screen registry. */
          /* Super admins also manage KiddieTrac's toll-free line here (its Toll-free line
             tab, moved from Reseller → Phone & voicemail on 2026-10-22). */
          { hash: 'sms-settings',       label: isPlatformAdmin_v22p34 ? 'Phone & carriers' : 'Carrier settings', icon: '📡' },
          { hash: 'email-templates',    label: 'Email templates',     icon: '📧' },
          // Seasonal email headers moved to Branding › Seasonal email headers (2026-10-15).
          { hash: 'document-templates', label: 'Document templates', icon: '📐' },
          { hash: 'quickbooks',         label: 'QuickBooks (Intuit)', icon: '📒' },
          { hash: 'xero',               label: 'Xero',                icon: '📗' },
          { hash: 'sage',               label: 'Sage',                icon: '🌿' },



          { hash: 'data-retention',     label: 'Data retention & compliance', icon: '📦' },
          { hash: 'help',               label: 'Help & guides',       icon: '📖' },
        ] },   // Sign-in methods and Security alerts moved to the Platform section (2026-10-25).
      ];
    }
    if (role === 'centre_director') {
      return [
        /* 2026-10-05: agency-level configuration (email accounts and settings, clock,
           calendar and billing settings) and admin-only screens (bulk invoice run,
           account ledgers) left this menu. Their APIs are agency_admin only, so they
           showed a 403 or fell back to the dashboard. Expenses and Tuition plans
           joined: their APIs now admit a director for their own centres. */
        /* 2026-11-13: the SAME screens an agency admin has (Anthony: "things need to be consistent across
           roles"), scoped to the director's centres by the server: the agency overview with every room, roster
           and clock-in; Children; Families; User management (their staff and parents - the old thin Staff list
           folded in here; #children, #families and #staff still land on these). */
        { label: 'Overview', items: [
          { hash: 'dashboard',      label: 'Dashboard',        icon: '🏠' },
          { hash: 'admin-children', label: 'Children',         icon: '🧒' },
          { hash: 'admin-families', label: 'Families',         icon: '👪' },
          // 2026-10-07: their own centre's staff and parents — Manage edits details, not sign-in.
          { hash: 'admin-users',    label: 'User management',  icon: '🆔' },
        ]},
        // Home Visitors — directors review their agency's home-visit reports +
        // inspection forms (screens are registered for centre_director too).
        { label: 'Home Visitors', items: [
          { hash: 'home-visit-reports', label: 'Home visit reports', icon: '🏘️' },
          { hash: 'hcc-forms',          label: 'Inspection forms',   icon: '🏡' },
        ]},
        { label: 'Operations', items: [
          { hash: 'notifications', label: 'Notifications', icon: '🔔', badgeKey: 'notif_unread' },
          { hash: 'contacts',       label: 'All contacts',    icon: '📇' },
          { hash: 'safe-arrival',   label: 'Safe Arrival',    icon: '🛡️' },
          { hash: 'educator-rewards', label: 'Educator rewards', icon: '⭐' },
          { hash: 'parent-issues',  label: 'Parent issues',   icon: '🗣️' },
          { hash: 'staff-calendar', label: 'Calendar',        icon: '📅' },
          { hash: 'closures',       label: 'Closures',         icon: '🚫' },
          { hash: 'today',          label: 'Today',            icon: '🌤️' },
          { hash: 'care-log',       label: 'Daily log',        icon: '📝' },

          { hash: 'chat',           label: 'Message Centre',         icon: '💬', badgeKey: 'chat_unread' },
          { hash: 'phone',          label: 'Phone',                  icon: '📞' },   // shown only with html.kt-can-dial (app-v2.css)
          { hash: 'files',          label: 'Files',          icon: '📎' },
          // 2026-11-25: their OWN mailbox (Anthony) - never the agency's; linking emails to people stays with admins.
          { hash: 'email',          label: 'Email Client',     icon: '✉️' },
          { hash: 'announcements',  label: 'Announcements',    icon: '📢', badgeKey: 'announcement_unread' },
          { hash: 'observations',   label: 'Observations',     icon: '\ud83d\udc40' },
          { hash: 'play-spark', label: 'Play Spark', icon: '🧩' },   // play ideas + weekly lesson plans, merged 2026-11-24 (AI tabs need the AI Bundle)
          { hash: 'awards',         label: 'Awards',           icon: '\ud83c\udfc6' },
          { hash: 'schedule',       label: 'Schedule',         icon: '🗒️' },
          { hash: 'certifications', label: 'Certifications',   icon: '🎓' },
          { hash: 'audit-logs',     label: 'Audit log',        icon: '📜' },
          { hash: 'incidents',     label: 'Incidents',        icon: '⚠️' },
          { hash: 'headcounts',    label: 'Headcounts',       icon: '🔢' },
          // Ontario sleep supervision (2026-10-13): hidden unless sleep checks are on (screen-sleep-room.js).
          { hash: 'sleep-room',    label: 'Sleep room',       icon: '😴' },
          { hash: 'hazards',       label: 'Hazards',         icon: '🚧' },
          // Ohio (2026-10-10): shown only for US-OH agencies or where switched on (screen-ohio.js).
          { hash: 'pfcc',          label: 'Ohio PFCC',        icon: '🎗️' },
          { hash: 'sutq',          label: 'Step Up To Quality', icon: '🌟' },
          { hash: 'withdrawals',   label: 'Withdrawals',      icon: '🚸' },
          { hash: 'medications',   label: 'Medications',      icon: '💊' },
          { hash: 'immunizations', label: 'Immunizations',    icon: '🩹' },
          { hash: 'privacy-breaches', label: 'Privacy breaches', icon: '🔓' },   // read-only, own centres (2026-11-06)
          { hash: 'photo-permissions', label: 'Photo permissions', icon: '🤳' },  // own centres; records paper forms (2026-11-06)
          // Their centres' forms, sign-offs and file requests; uploads tie to their centre (CentreScope::people, 2026-11-13).
          { hash: 'forms-manager',     label: 'Forms Manager',     icon: '🗂️' },
          { hash: 'digest-status', label: 'AI digest status', icon: '📰' },
        ]},
        { label: 'Growth', items: [
          { hash: 'marketing-campaigns', label: 'Marketing',  icon: '📯' },
          { hash: 'tours',              label: 'Tours',       icon: '🚪' },
          { hash: 'sms',                label: 'SMS broadcast', icon: '📱', badgeKey: 'sms_replies' },
          { hash: 'ai-churn',           label: 'Churn risk',  icon: '📉' },
          { hash: 'ai-docs',            label: 'AI doc extract', icon: '🪄' },
        ]},
        { label: 'Staff', items: [
          { hash: 'tasks',             label: 'Tasks',              icon: '☑️' },
          { hash: 'notes', label: 'Notes', icon: '📃' },   // Quick notes (2026-11-26, kt-quick-notes.js)
          { hash: 'time-clock',        label: 'Time clock',         icon: '⏱' },
          { hash: 'substitutes',       label: 'Substitutes',        icon: '🙋' },
          // Fire drills and each educator's drill dates are a staff matter (2026-11-09, Anthony: "move drill log to staff section").
          { hash: 'drills',             label: 'Drill log',          icon: '🚒' },
        ]},
        // HUMAN RESOURCES (2026-11-26, Anthony): HR, My HR, time off, timesheets, payroll and background checks in one group. Each item keeps
        // its own gate (HR + My HR need the hr add-on via hidden_screens; the others show without it); an empty group is dropped.
        { label: 'Human Resources', keepOrder: true, items: [
          { hash: 'hr',                label: 'HR',                 icon: '🧑‍💼' },
          { hash: 'my-hr',             label: 'My HR',              icon: '🧭' },
          { hash: 'time-off',          label: 'Time off requests',  icon: '🌴' },
          { hash: 'timesheets',        label: 'Timesheets',         icon: '🕘' },   // moved from Operations (2026-11-26, Anthony)
          { hash: 'payroll',           label: 'Payroll',            icon: '💼' },   // moved from Administration (2026-11-26, Anthony)
          { hash: 'background-checks', label: 'Background checks',  icon: '🪪' },
        ]},
        { label: 'Programs', items: [
          { hash: 'curriculum',        label: 'Curriculum library', icon: '🌱' },   // Ontario units (2026-11-03)
          { hash: 'menu',              label: 'Weekly menu',     icon: '🍽' },
          { hash: 'field-trips',       label: 'Field trips',     icon: '🚐' },
          { hash: 'conferences',       label: 'Conferences',     icon: '🪑' },
          { hash: 'allergy-alerts',    label: 'Allergy alerts',  icon: '🥜' },
        ]},
        { label: 'Compliance', items: [
          { hash: 'compliance',        label: 'Compliance',           icon: '✅' },
          { hash: 'policies',          label: 'Policies',             icon: '📘' },
          { hash: 'my-policies',       label: 'Policies to review',   icon: '✍️' },
          { hash: 'my-training',       label: 'My training',          icon: '🎒' },
          { hash: 'my-certificates',   label: 'My certificates',      icon: '❤️' },
          { hash: 'household',         label: 'Household residents',  icon: '🛋️' },
          { hash: 'inspection',        label: 'Inspection checklist', icon: '🔎' },
          { hash: 'renewals',          label: 'Renewals calendar',    icon: '♻️' },
          { hash: 'cwelcc',            label: 'Subsidies',         icon: '💵' },
          // Ontario OCCMS reporting (2026-10-13): hidden unless an Ontario agency (screen-subsidy-roa.js).
          { hash: 'subsidy-roa',        label: 'OCCMS reporting',     icon: '🏷️' },
          { hash: 'retention',         label: 'Retention',        icon: '📊' },
          { hash: 'forecast',          label: 'Enrolment forecast',icon: '📈' },
          { hash: 'anomalies',         label: 'Anomalies',        icon: '🔍' },
        ]},
        { label: 'Enrollment', items: [
          { hash: 'invitation-codes', label: 'Invitation codes', icon: '🔖' },
          { hash: 'vacation-holds',   label: 'Vacation holds',   icon: '🏖' },
          { hash: 'edocuments',       label: 'eDocuments',       icon: '🖋️' },
          { hash: 'synced-waitlist',  label: 'Waitlist',          icon: '⏳' },
        ]},
        { label: 'Finance', items: [
          { hash: 'late-pickups',     label: 'Late pickups',     icon: '🌙' },
          { hash: 'billing-schedule', label: 'Billing schedule', icon: '🧮' },
          { hash: 'payment-plans',    label: 'Payment schedules', icon: '🏧' },
          { hash: 'tuition-plans',    label: 'Tuition plans',    icon: '🧷' },
          { hash: 'expenses',         label: 'Expenses',         icon: '🧾' },
          { hash: 'refunds',          label: 'Refunds',          icon: '↩' },
          { hash: 'tuition-increases',label: 'Tuition increases',icon: '📈' },
          { hash: 'external-billing', label: 'Accounting',        icon: '🗃️' },
          { hash: 'grants',           label: 'Grants',           icon: '🏛️' },
        ]},
        { label: 'Settings', items: [
          { hash: 'settings', label: 'My settings', icon: '⚙️' },
          { hash: 'educator-rooms', label: 'Room Controls', icon: '🎛️' },
          // Read only for a director: how the agency's automatic thank-you awards are set up (2026-11-02).
          { hash: 'educator-award-settings', label: 'Educator awards', icon: '🏅' },
          /* No "My profile" here either: a director's name in the top bar opens
             #my-profile (kt-topbar openProfile). */



          { hash: 'help',             label: 'Help & guides',    icon: '📖' },
        ]},
      ];
    }
    if (role === 'educator') {
      // Curated, classroom-relevant set (mirrors the icon-tile home). The long
      // tail was removed to keep the educator view dead simple.
      return [
        { label: 'Menu', items: [
          { hash: 'home',          label: 'Home',          icon: '🏠' },
          { hash: 'today',         label: 'Today',         icon: '🌤️' },
          { hash: 'my-tasks',      label: 'My tasks',      icon: '📋' },
          { hash: 'parent-feedback', label: 'Parent Feedback', icon: '💭' },
          { hash: 'parent-issues', label: 'Parent issues', icon: '🗣️' },
          // v22p98: educators clock in/out here — needed for ratio compliance,
          // payroll and reporting. The time-clock screen was already built and
          // registered for the educator role; it was just missing from this nav.
          { hash: 'time-clock',    label: 'Clock in/out',  icon: '⏱' },
          { hash: 'care-log',      label: 'Daily log',     icon: '📝' },
          { hash: 'observations',  label: 'Observations',  icon: '👀' },
          { hash: 'photo-permissions', label: 'Photo permissions', icon: '🤳' },   // who may be photographed (2026-11-06)
          { hash: 'play-spark', label: 'Play Spark', icon: '🧩' },   // play ideas + weekly lesson plans, merged 2026-11-24 (AI tabs need the AI Bundle)
          { hash: 'curriculum',    label: 'Curriculum library', icon: '🌱' },   // Ontario units (2026-11-03)
          { hash: 'chat',          label: 'Message Centre',      icon: '💬', badgeKey: 'chat_unread' },
          { hash: 'phone',          label: 'Phone',                  icon: '📞' },   // shown only with html.kt-can-dial (app-v2.css)
          { hash: 'files',          label: 'Files',          icon: '📎' },
          { hash: 'incidents',     label: 'Incidents',     icon: '⚠️' },
          { hash: 'drills',        label: 'Drill log',        icon: '🚒' },
          { hash: 'headcounts',    label: 'Headcounts',       icon: '🔢' },
          // Who is due in and who is running late, for their own rooms (2026-11-06; the screen was always open to educators).
          { hash: 'safe-arrival',  label: 'Safe Arrival',     icon: '🛡️' },
          // Ontario sleep supervision (2026-10-13): hidden unless sleep checks are on (screen-sleep-room.js).
          { hash: 'sleep-room',    label: 'Sleep room',       icon: '😴' },
          { hash: 'hazards',       label: 'Report a hazard', icon: '🚧' },
          { hash: 'late-pickups',  label: 'Late pickups',  icon: '🌙' },
          { hash: 'medications',   label: 'Medications',   icon: '💊' },
          { hash: 'announcements', label: 'News',          icon: '📢', badgeKey: 'announcement_unread' },
          { hash: 'time-off',      label: 'Time off',      icon: '🌴' },
          // 2026-08-30: the canned Reports screen, scoped server-side to this
          // educator's own rooms and their own clock-ins.
          { hash: 'reports',       label: 'Reports',       icon: '📊' },
          /* Forms sent from the Forms Manager land here — the same fill-and-sign the
             emailed link opens, and a form leaves the list the moment it is signed.
             It was on the home tile grid and nowhere else, so on a desktop, where the
             sidebar IS the navigation, there was no way to reach it. */
          { hash: 'my-forms',      label: 'Forms to sign', icon: '✍️' },
          /* Their own filed paperwork — every form they have signed, plus the
             Terms/Privacy/NDA and anything an admin attached to their record.
             Signed forms were only ever visible to admins before this. */
          { hash: 'my-documents',  label: 'My documents', icon: '📄', badgeKey: 'my_documents' },
          { hash: 'my-hr', label: 'My HR', icon: '🧭' },   // KiddieTrac HR phase 2 (2026-11-28): own checklist, courses, reviews, goals
          { hash: 'my-training',   label: 'My training',  icon: '🎒' },
          { hash: 'my-certificates', label: 'My certificates', icon: '❤️' },
          { hash: 'my-points',     label: 'My points',    icon: '⭐' },
          { hash: 'my-policies',   label: 'Policies to review', icon: '📘' },
          { hash: 'household',     label: 'Household residents', icon: '🛋️' },
        ]},
        { label: 'Account', items: [
          { hash: 'notes', label: 'Notes', icon: '📃' },   // Quick notes (2026-11-26, kt-quick-notes.js)
          { hash: 'notifications', label: 'Notifications', icon: '🔔', badgeKey: 'notif_unread' },
          { hash: 'settings', label: 'My settings', icon: '⚙️' },
          { hash: 'help',          label: 'Help',          icon: '📖' },
        ]},
      ];
    }
    if (role === 'home_visitor') {
      // Home visitor: agency-attached practitioner who files home-visit reports
      // against any centre in their agency.
      return [
        { label: 'Menu', items: [
          { hash: 'home',           label: 'Home',             icon: '🏠' },
          { hash: 'new-home-visit', label: 'New visit report', icon: '📝' },
          { hash: 'play-spark', label: 'Play Spark', icon: '🧩' },   // play ideas + weekly lesson plans, merged 2026-11-24 (AI tabs need the AI Bundle)
          { hash: 'home-visits',    label: 'My reports',       icon: '📋' },
          { hash: 'my-tasks',       label: 'My tasks',         icon: '✅' },
          /* Forms sent from the Forms Manager land here — the same fill-and-sign the
             emailed link opens, and a form leaves the list the moment it is signed.
             It was on the home tile grid and nowhere else, so on a desktop, where the
             sidebar IS the navigation, there was no way to reach it. */
          { hash: 'my-forms',       label: 'Forms to sign',    icon: '✍️' },
          /* Their own filed paperwork — every form they have signed, plus the
             Terms/Privacy/NDA and anything an admin attached to their record.
             Signed forms were only ever visible to admins before this. */
          { hash: 'my-documents',  label: 'My documents', icon: '📄', badgeKey: 'my_documents' },
          { hash: 'my-hr', label: 'My HR', icon: '🧭' },   // KiddieTrac HR phase 2 (2026-11-28): own checklist, courses, reviews, goals
          { hash: 'my-training',   label: 'My training',  icon: '🎒' },
          { hash: 'my-certificates', label: 'My certificates', icon: '❤️' },
          { hash: 'my-points',     label: 'My points',    icon: '⭐' },
          { hash: 'my-policies',   label: 'Policies to review', icon: '📘' },
          { hash: 'household',     label: 'Household residents', icon: '🛋️' },
          { hash: 'files',          label: 'Files',          icon: '📎' },
          { hash: 'hazards',        label: 'Report a hazard', icon: '🚧' },   // 2026-10-15
        ]},
        { label: 'Account', items: [
          { hash: 'notes', label: 'Notes', icon: '📃' },   // Quick notes (2026-11-26, kt-quick-notes.js)
          { hash: 'notifications', label: 'Notifications', icon: '🔔', badgeKey: 'notif_unread' },
          { hash: 'settings',      label: 'Settings',      icon: '⚙️' },
          { hash: 'help',          label: 'Help',          icon: '📖' },
        ]},
      ];
    }
    if (role === 'sales_rep') {
      // Platform sales CRM: pipeline, leads, quotes, follow-ups + demo launcher.
      return [
        { label: 'Sales', items: [
          { hash: 'home',            label: 'Home',            icon: '🏠' },
          { hash: 'sales',           label: 'Pipeline',        icon: '📊' },
          { hash: 'sales-webchat',   label: 'Website chat',    icon: '🗨️' },
          { hash: 'phone',          label: 'Phone',                  icon: '📞' },   // shown only with html.kt-can-dial (app-v2.css)
          { hash: 'sales-leads',     label: 'Leads',           icon: '🎯' },
          { hash: 'sales-quotes',    label: 'Proposals',       icon: '📄' },
          { hash: 'sales-customers', label: 'Customers',       icon: '🥇' },   // every won lead, its services (2026-11-10)   // every proposal, stats + charts (2026-10-24)
          { hash: 'sales-expenses',  label: 'Expenses',        icon: '🪙' },   // their own expenses, for approval (2026-10-24)
          { hash: 'sales-campaigns', label: 'Campaigns',       icon: '📣' },   // intro emails to prospects, tracked (2026-10-26)
          { hash: 'sales-email',     label: 'Sales email',     icon: '📮' },   // the shared sales@ mailbox (2026-10-26)
          { hash: 'sales-bookings',  label: 'Bookings',        icon: '📌' },   // website meeting requests (2026-10-23)
          { hash: 'sales-calendar',  label: 'Calendar',        icon: '📆' },   // bookings, hours, time off, whereabouts (2026-10-23)
          { hash: 'prospect-ndas',   label: 'Prospect NDAs',   icon: '🤝' },   // 2026-10-09
          { hash: 'sales-demo',      label: 'Launch demo',     icon: '🚀' },
        ]},
        { label: 'Account', items: [
          { hash: 'notes', label: 'Notes', icon: '📃' },   // Quick notes (2026-11-26, kt-quick-notes.js)
          { hash: 'notifications', label: 'Notifications', icon: '🔔', badgeKey: 'notif_unread' },
          { hash: 'help',          label: 'Help',          icon: '❓' },
          { hash: 'settings',      label: 'Settings',      icon: '⚙️' },
        ]},
      ];
    }
    if (role === 'reseller_admin') {
      // Its own brand and the names of its agencies - nothing inside them (2026-10-21).
      return [
        { label: 'Your brand', items: [
          { hash: 'reseller',          label: 'All my agencies', icon: '🏪' },
          { hash: 'reseller-onboard',  label: 'Onboard an agency', icon: '➕' },
          { hash: 'reseller-billing',  label: 'Billing & commissions', icon: '💳' },
          { hash: 'reseller-support',  label: 'Support desk',  icon: '🎧' },
          { hash: 'reseller-brand',    label: 'Branding',  icon: '🎨' },
          { hash: 'reseller-agencies', label: 'Agencies',  icon: '🏢' },
        ]},
        { label: 'Account', items: [
          { hash: 'help',     label: 'Help',     icon: '❓' },
          { hash: 'settings', label: 'Settings', icon: '⚙️' },
        ]},
      ];
    }
    if (role === 'auditor') {
      // Read-only audit/compliance set (mirrors the icon-tile home).
      return [
        { label: 'Menu', items: [
          { hash: 'home',       label: 'Home',       icon: '🏠' },
          { hash: 'compliance', label: 'Compliance', icon: '✅' },
          { hash: 'audit-logs', label: 'Audit logs', icon: '📜' },
          { hash: 'children',   label: 'Children',   icon: '🧒' },
          { hash: 'forms',      label: 'Forms',      icon: '📝' },
          { hash: 'forms-manager', label: 'Forms Manager', icon: '🗂️' },
          { hash: 'help',       label: 'Help',       icon: '📖' },
        ]},
        { label: 'Account', items: [
          { hash: 'notifications', label: 'Notifications', icon: '🔔', badgeKey: 'notif_unread' },
          { hash: 'settings', label: 'My settings', icon: '⚙️' },
        ]},
      ];
    }
    /* A parent the agency has not linked to a family yet (user.family_pending) gets only
       what works without one: the waiting screen, forms and documents sent to them, and
       their own account. See familyPending() below. */
    if (familyPending()) {
      return [
        { label: 'Menu', items: [
          { hash: 'pending',      label: 'Account status', icon: '⏳' },
          { hash: 'my-forms',     label: 'Forms to sign',  icon: '✍️' },
          { hash: 'my-documents', label: 'Documents',      icon: '📄', badgeKey: 'my_documents' },
        ]},
        { label: 'Account', items: [
          { hash: 'notifications', label: 'Notifications', icon: '🔔', badgeKey: 'notif_unread' },
          { hash: 'settings', label: 'My settings', icon: '⚙️' },
          { hash: 'help',          label: 'Help',          icon: '📖' },
        ]},
      ];
    }
    // guardian (default) — curated, family-relevant set (mirrors the icon-tile
    // home). The long tail lives behind the home screen's "More" tile.
    return [
      { label: 'Menu', items: [
        { hash: 'home',               label: 'Home',       icon: '🏠' },
        { hash: 'today',              label: 'Today',      icon: '🌈' },
        { hash: 'photos',             label: 'Photos & video', icon: '📸', badgeKey: 'photos_new' },
        { hash: 'play-spark', label: 'Play at home', icon: '🧩' },   // Play Spark for families, AI Bundle (2026-11-24)
        { hash: 'messages',           label: 'Message Centre',   icon: '💬', badgeKey: 'chat_unread' },
        { hash: 'files',          label: 'Files',          icon: '📎' },
        { hash: 'my-tasks',           label: 'My tasks',   icon: '📋' },
        /* Forms sent from the Forms Manager land here — the same fill-and-sign the
           emailed link opens, and a form leaves the list the moment it is signed.
           It was on the home tile grid and nowhere else, so on a desktop, where the
           sidebar IS the navigation, there was no way to reach it. */
        { hash: 'my-forms',           label: 'Forms to sign', icon: '✍️' },
        { hash: 'checkin',            label: 'Check-in',   icon: '☀' },
        /* Running late for drop-off or pickup: tells the centre, holds Safe Arrival back (2026-11-06). */
        { hash: 'running-late',       label: 'Running late', icon: '⏰' },
        { hash: 'billing',            label: 'Billing',    icon: '💳', badgeKey: 'billing_due' },
        { hash: 'attendance-pattern', label: 'Attendance', icon: '📍' },
        { hash: 'medications',        label: 'Health',     icon: '💊' },
        /* The screen was registered for guardians but reachable by nobody — it was
           in the staff nav only, so a parent had no route to it from desktop or
           mobile. That is where the upload lives. */
        { hash: 'immunizations',      label: 'Immunization', icon: '🩹' },
        { hash: 'announcements',      label: 'News',       icon: '📢', badgeKey: 'announcement_unread' },
        /* The family's report cards, and asking for this quarter's (2026-11-04). */
        { hash: 'report-cards',       label: 'Report cards', icon: '📑' },
        /* Reports the centre has shared — incident reports today. Filed copies a
           family can come back to, rather than an email they have to keep. */
        { hash: 'my-documents',       label: 'Documents',  icon: '📄', badgeKey: 'my_documents' },
        /* Tell the centre about something that needs looking into (2026-10-13). */
        { hash: 'report-issue',       label: 'Report an issue', icon: '🗣️' },
        /* The family's guardians and emergency contacts, and asking the centre for a
           contact's pick-up login (2026-10-13). */
        { hash: 'family-contacts',    label: 'Family & pick-ups', icon: '👪' },
      ]},
      { label: 'Account', items: [
        { hash: 'notifications', label: 'Notifications', icon: '🔔', badgeKey: 'notif_unread' },
        { hash: 'settings', label: 'My settings', icon: '⚙️' },   // Photo permissions is its Photos tab (2026-11-06)
        { hash: 'help',          label: 'Help',          icon: '📖' },
      ]},
    ];
  }

  /* WHERE PEOPLE ACTUALLY LOOK FOR A VERSION NUMBER.

     "Which build am I on?" and "what does this say about licensing?" are answered on the
     About tab of My profile & security — which is correct, and which nobody found,
     because for an admin that screen is fourth inside a collapsed Settings group at the
     bottom of a long sidebar. Reported by Anthony, 2026-09-16, as the About section
     being MISSING for superadmin, admin and director. It was not missing; it was
     unreachable in practice, and for this particular panel those are the same thing.

     So the build sits in the foot of the sidebar, under the account, where a desktop
     user looks for it — answering the common question outright and opening the panel
     for the rest. One muted line; it must not compete with the navigation above it. */
  /* ...AND ONLY WHERE A SIDEBAR FOOT IS ACTUALLY A FOOT.

     On a phone, and for the top-nav roles, #appSidebar is not a column down the side of
     the page — it is a full-width header strip, so its "foot" lands directly under the
     top bar, above the first real thing on the screen. Anthony, 2026-09-17, with the
     phone in hand: "the about information is shown just below the top bar ... we needed
     this information added to the user profile view for desktop." Right on both counts:
     the panel itself belongs on the About tab of a person's own record, where it now is,
     and this line is a desktop convenience that has no business costing a phone a row.

     Measured rather than guessed from the role — a sidebar as wide as the viewport is a
     header. One rule covers the phone, the APK, a narrow desktop window and the
     guardian/educator top-nav layout on a big screen, without naming any of them. */
  function footIsSidebarColumn() {
    var sb = document.getElementById('appSidebar');
    if (!sb) { return false; }
    var vw = window.innerWidth || document.documentElement.clientWidth || 0;
    // Narrower than 981px it is never a column: answered without measuring the sidebar, which forced a layout on
    // every phone navigation (300 ms on the Users screen at 4x CPU, phone speed sweep 2026-10-30).
    if (!(vw >= 981)) { return false; }
    var w = sb.getBoundingClientRect().width;
    if (!(w > 0) || !(vw > 0)) { return false; }
    return vw >= 981 && w <= vw * 0.5;
  }

  var NAV_MARK = {
    'safe-arrival': '<svg width="18" height="18" viewBox="0 0 100 100" style="display:block;margin:0 auto;"><defs><linearGradient id="saNavMark" x1="0" y1="0" x2="1" y2="1"><stop offset="0" stop-color="#34d399"/><stop offset="1" stop-color="#047857"/></linearGradient></defs>'
      + '<path d="M50 4 88 18v30c0 25-16 41-38 49C28 89 12 73 12 48V18z" fill="url(#saNavMark)"/><path d="M50 4 88 18v30c0 25-16 41-38 49" fill="none" stroke="#065f46" stroke-opacity=".25" stroke-width="3"/>'
      + '<circle cx="58" cy="31" r="8.5" fill="#fff"/><path d="M30 54l15 15 27-27" fill="none" stroke="#fff" stroke-width="10" stroke-linecap="round" stroke-linejoin="round"/>'
      + '<circle cx="78" cy="22" r="7" fill="#fbbf24" stroke="#fff" stroke-width="2.5"/></svg>',
    /* WALK WATCH (2026-10-26): the walks & outings brand, the same mark as the website and the film. */
    /* PLAY SPARK (2026-11-24): the on-the-spot play ideas brand, the same mark as the white paper and website. */
    'play-spark': '<img src="/img/play-spark.svg?v=2" alt="" width="18" height="18" style="display:block;margin:0 auto;border-radius:5px;">',
    'trip-gps': '<img src="/img/walk-watch.svg" alt="" width="18" height="18" style="display:block;margin:0 auto;border-radius:5px;">',
    'walk': '<img src="/img/walk-watch.svg" alt="" width="18" height="18" style="display:block;margin:0 auto;border-radius:5px;">',
    'walks': '<img src="/img/walk-watch.svg" alt="" width="18" height="18" style="display:block;margin:0 auto;border-radius:5px;">',
  };

  /* THE FOOT, QUIETER (2026-10-25, Anthony: "tighten up where it shows the agency plan, build number
     and show admin controls tighter and less apparent"). Small muted type, little padding, one
     tight stack - present when looked for, not competing with the navigation above it. */
  function footCss() {
    if (document.getElementById('kt-foot-tight')) { return; }
    var st = document.createElement('style'); st.id = 'kt-foot-tight';
    st.textContent = ''
      + '#kt-sidebar-foot{gap:1px !important;padding-bottom:6px !important;}'
      + '#kt-adminctl .kt-ac-link{font-size:10px !important;font-weight:600 !important;color:#B4BFCD !important;padding:1px 0 !important;line-height:1.3 !important;}'
      + '#kt-adminctl .kt-ac-link:hover,#kt-adminctl .kt-ac-link:focus-visible{color:#64748B !important;}'
      + '#kt-adminctl .kt-ac-hide{font-size:10px !important;color:#94A3B8 !important;padding:1px 0 !important;}'
      + '#kt-foot-build{gap:0 !important;}'
      + 'button#kt-foot-build,#kt-foot-build > button{font-size:10px !important;color:#B4BFCD !important;padding:1px 0 !important;line-height:1.3 !important;}'
      + 'button#kt-foot-build:hover,#kt-foot-build > button:hover{color:#64748B !important;}'
      + '#kt-foot-build .kt-foot-plan{font-size:9.5px !important;font-weight:700 !important;padding:0 !important;background:none !important;color:#94A3B8 !important;line-height:1.4 !important;letter-spacing:.2px;}'
      + '#kt-foot-build a.kt-foot-plan:hover{color:#1F6080 !important;text-decoration:underline;}';
    document.head.appendChild(st);
  }

  function installBuildFoot() {
    footCss();
    var existing = document.getElementById('kt-foot-build');
    /* Removes as well as adds: a desktop window dragged narrow, or a tablet turned, has
       to lose the line the same way it never gets one on a phone. */
    if (!footIsSidebarColumn()) { if (existing) { existing.remove(); } return; }
    if (existing) { return; }
    if (!(window.KT && KT.sidebarFoot)) { return; }
    var v = window.KT_VERSION || '';
    var b = document.createElement('button');
    b.id = 'kt-foot-build';
    b.type = 'button';
    /* data-kt-iconized: the icon engine swaps a short button label for a glyph, and a
       version number rendered as a picture helps nobody. */
    b.setAttribute('data-kt-iconized', '1');
    b.setAttribute('aria-label', 'About KiddieTrac, build and diagnostics');
    b.style.cssText = 'appearance:none;background:none;border:0;padding:4px 2px;cursor:pointer;'
      + 'font:inherit;font-size:11px;line-height:1.4;color:#94A3B8;text-align:left;'
      + 'white-space:nowrap;overflow:hidden;text-overflow:ellipsis;';
    b.textContent = 'KiddieTrac' + (v ? ' · build ' + v : '') + ' · About';
    b.title = 'Build, licensing and diagnostics';
    b.addEventListener('click', function () { window.location.hash = '#settings/about'; });
    /* THE LICENCE, BESIDE THE BUILD (2026-10-25, Anthony: "License should show for
       admins/directors by the side tool bar where it shows kiddie trac build"). It was a
       Settings menu item; it is a fact about the account, like the build, so it lives here.
       Admins and directors only (Roles.isDirector covers both, and a superadmin). */
    /* (2026-10-25, second pass: "should show the license type and not a button ... only the
       superadmin and agency admin should have a clickable"). The plan's NAME, from /auth/me
       licence_label; admins (and a superadmin) can open the Licence page from it, a director
       just reads it. */
    var foot = b, u = null;
    try { u = JSON.parse(sessionStorage.getItem('kt_user') || localStorage.getItem('kt_user') || 'null'); } catch (e) {}
    var planName = u && u.licence_label;
    if (u && planName && Roles.isDirector(u)) {
      var canOpen = Roles.primaryRoleOf(u) === 'agency_admin';
      b.removeAttribute('id');
      /* Stacked (2026-10-25, Anthony: "sidebar at the bottom no longer shows the version
         information"): side by side, the plan label squeezed the build line to "build…". */
      b.style.maxWidth = '100%';
      foot = document.createElement('div');
      foot.id = 'kt-foot-build';
      foot.style.cssText = 'display:flex;flex-direction:column;align-items:flex-start;gap:3px;min-width:0;max-width:100%;';
      var lic = document.createElement(canOpen ? 'a' : 'span');
      lic.setAttribute('data-kt-iconized', '1');
      lic.className = 'kt-foot-plan';
      lic.textContent = planName;
      lic.style.cssText = 'font-size:11px;font-weight:700;line-height:1.4;color:#475569;white-space:nowrap;flex:0 0 auto;'
        + 'padding:1px 8px;border-radius:999px;background:#EEF4F8;' + (canOpen ? 'cursor:pointer;text-decoration:none;' : '');
      if (canOpen) {
        lic.href = '#licence';
        lic.title = 'Your licence: plan, dates, features and account summary';
        lic.setAttribute('aria-label', planName + ' — open your licence');
      } else {
        lic.title = 'Your agency\'s KiddieTrac plan';
      }
      foot.appendChild(b);
      foot.appendChild(lic);
    }
    if (!KT.sidebarFoot(40, foot)) { return; }
  }

  /* AND PUT ITSELF BACK IF SOMETHING TAKES IT AWAY.

     installBuildFoot() runs once, from buildNav(), which itself runs once per sign-in.
     Four controls from four different files share this foot — the agency switcher, both
     view-as controls and this — and any of them rebuilding it, or a sweep clearing the
     sidebar, would take the build line with it for the rest of the session with nothing
     to notice and nothing to restore it.

     Reported as exactly that shape: seen once, then gone. I could not reproduce it —
     the button held through boot, navigation, both roles and phone width — so rather
     than guess at a cause, it now checks after each navigation and re-places itself if
     it has gone. A getElementById against a live document, on a hashchange, is not a
     cost worth optimising; a control that vanishes and cannot come back is.
     (Anthony, 2026-09-16) */
  window.addEventListener('hashchange', function () {
    try { installBuildFoot(); } catch (e) {}
  });

  /* Layout, not navigation, decides whether the line belongs — so a resize has to be
     asked too, or a window dragged narrow keeps a foot the layout no longer has room
     for. Debounced: a drag fires this continuously and the work is a measurement. */
  /* The measurement needs a laid-out sidebar, and buildNav() runs before the stylesheets
     have finished having their say — measured too early, a desktop reads as a phone and
     quietly drops the line for the rest of the session. So ask again next frame and once
     more after the paint has settled; installBuildFoot() is idempotent in both
     directions, so a repeat costs a getBoundingClientRect. */
  function scheduleBuildFoot() {
    try { installBuildFoot(); } catch (e) {}
    requestAnimationFrame(function () { try { installBuildFoot(); } catch (e) {} });
    setTimeout(function () { try { installBuildFoot(); } catch (e) {} }, 400);
  }

  var _footRz = null;
  window.addEventListener('resize', function () {
    if (_footRz) { clearTimeout(_footRz); }
    _footRz = setTimeout(function () { try { installBuildFoot(); } catch (e) {} }, 180);
  });

  function buildNav(user) {
    const links = Dom.$('#navLinks');
    if (!links) return;
    Dom.clear(links);

    const role = Roles.primaryRoleOf(user);
    const sections = hideSwitchedOff(navItemsForRole(role), user);
    scheduleBuildFoot();

    /* The switcher's Platform/Sales block lands in #navLinks after an async fetch, so a
       one-shot prune here would run before it exists. Watch instead — for an agency with
       nothing switched off this never attaches at all. */
    try {
      if ((hiddenHashes().length || Object.keys(lockedMap(user)).length) && !window.__ktNavPruneObs) {
        var _nl = Dom.$('#navLinks');
        if (_nl && window.MutationObserver) {
          window.__ktNavPruneObs = new MutationObserver(function () { pruneHiddenLinks(_nl); });
          window.__ktNavPruneObs.observe(_nl, { childList: true, subtree: true });
        }
      }
    } catch (e) {}
    const isSidebar = (role === 'agency_admin' || role === 'centre_director');

    if (isSidebar) {
      // Grouped sidebar layout (sections with labels)
      sections.forEach(section => {
        const sectionEl = Dom.el('div', { class: 'sidebar-section' });
        // Anthony's own order for a group (Human Resources, 2026-11-26): kt-nav-sort.js leaves it as listed.
        if (section.keepOrder) sectionEl.setAttribute('data-kt-keep-order', '1');
        if (section.label) {
          sectionEl.appendChild(Dom.el('div', { class: 'sidebar-section-label' }, section.label));
        }
        section.items.forEach(item => sectionEl.appendChild(buildNavLink(item)));
        links.appendChild(sectionEl);
      });
    } else {
      // Flat horizontal list for top-nav layout (educator + guardian)
      sections.forEach(section => {
        section.items.forEach(item => links.appendChild(buildNavLink(item)));
      });
    }

    // Mark that v17 buildNav has run — nav-additions-v12/13/14/15 see this
    // flag and skip their old injectNav() side-effects.
    window.KT_V17_NAV_INSTALLED = true;

    updateActiveNav();
  }

  function buildNavLink(item) {
    const a = Dom.el('a', {
      href: '#' + item.hash,
      class: 'nav-link' + (item.sub ? ' nav-link--sub' : ''),
      'data-hash': item.hash,
    });
    // Sub-items sit indented under their parent (e.g. "New lead" under "Leads").
    // The collapsed rail zeroes side padding, so the indent only shows when expanded.
    if (item.sub) a.style.paddingLeft = '30px';
    if (item.icon) {
      /* A brand mark where the product has one (2026-10-25, Anthony: "safe arrival on the side bar
         does not show us the right safe arrival icon"): the same shield as the website and brochure. */
      if (NAV_MARK[item.hash]) {
        var mk = Dom.el('span', { class: 'nav-icon', 'data-kt-iconized': '1', 'aria-hidden': 'true' });
        mk.innerHTML = NAV_MARK[item.hash];
        a.appendChild(mk);
      } else {
        a.appendChild(Dom.el('span', { class: 'nav-icon' }, item.icon));
      }
    }
    a.appendChild(Dom.el('span', { class: 'nav-label' }, item.label));
    if (item.badgeKey) {
      a.appendChild(Dom.el('span', {
        class: 'nav-badge',
        'data-badge-key': item.badgeKey,
        style: 'display:none;',
      }, ''));
    }
    return a;
  }

  // ──────────────── Badge updater ──────────────────────────────
  // External code (push-client, chat polling, announcement polling)
  // can call KT.Shell.setBadge('chat_unread', 4) to update a count.
  /* WRITE ONLY ON A CHANGE (2026-11-24, freeze tickets #257-#264). A same-value write is still a DOM mutation:
     screen-my-documents.js watches the page and repaints this badge on every mutation, so rewriting the same
     number woke it again (~16 cycles/s on an idle screen, each one re-running updateActiveNav). */
  function setBadge(key, count) {
    document.querySelectorAll('[data-badge-key="' + key + '"]').forEach(el => {
      if (count > 0) {
        const txt = count > 99 ? '99+' : String(count);
        if (el.textContent !== txt) el.textContent = txt;
        if (el.style.display !== '') el.style.display = '';
      } else if (el.style.display !== 'none') {
        el.style.display = 'none';
      }
    });
  }

  const navKey = (h) => String(h || '').replace(/^#/, '').split('?')[0].split('&')[0].split('/')[0];
  let lastNavKey = '';
  /* SUBTABS KEEP THEIR SECTION (2026-10-30). Anthony: "when i click on leads and from leads i click on a subtab the
     sidebar changes to another section that is totally unrelated with the highlight - full sweep". A screen with no menu
     item of its own was matched to the LONGEST MENU ITEM ITS NAME STARTS WITH, so Leads → Cold reach (#sales-prospects)
     lit "Sales pipeline" (#sales), and #email-seasons lit the Email client. Now, in order:
       1. the screen's own menu item;
       2. its declared parent (NAV_PARENT below - add one when a subtab screen gets its own hash);
       3. the "detail of a list" guess (incident-new -> incidents);
       4. reached by a link or subtab INSIDE the page: the section you were in stays lit;
       5. only then the name-prefix guess (a deep link straight to a screen), and last the section you came from.
     Swept against every registered screen hash. */
  const NAV_PARENT = {
    'sales-prospects': 'sales-leads',
    'sales-followups': 'sales-leads',   // a Leads sub-tab (2026-11-04)
    'sales-lead': 'sales-leads',
    'email-seasons': 'mail-settings',
    'inspection-form': 'hcc-forms',
    'inspection-forms': 'hcc-forms',
    'payroll-report': 'reports',
    'campaign-report': 'reports',
    'kt-reports': 'reports',
  };
  let inPageNavAt = 0;
  document.addEventListener('click', function (e) {
    const a = e.target && e.target.closest ? e.target.closest('#appMain a[href^="#"], #appMain [data-kt-inpage]') : null;
    if (a) inPageNavAt = Date.now();
  }, true);
  function updateActiveNav() {
    const base = navKey(window.location.hash) || 'dashboard';
    const links = Array.from(document.querySelectorAll('.nav-link'));
    const keys = links.map((l) => navKey(l.dataset.hash || l.getAttribute('href')));
    const has = (k) => k && keys.indexOf(k) !== -1;
    let want = has(base) ? base : '';
    if (!want && NAV_PARENT[base] && has(NAV_PARENT[base])) want = NAV_PARENT[base];
    if (!want) {
      // A detail screen lights the list it belongs to.
      const cands = [base + 's', base.replace(/-(detail|details|edit|view|new|profile)$/, ''), base.replace(/-(detail|details|edit|view|new|profile)$/, '') + 's'];
      want = cands.find(has) || '';
    }
    // Reached from inside the page (a subtab, a "view" link): stay in the section you were in.
    if (!want && has(lastNavKey) && Date.now() - inPageNavAt < 4000) want = lastNavKey;
    if (!want) {
      // Else the longest menu item the screen name starts with ("platform-agency-x" -> "platform-agencies" will not, "admin-users-import" -> "admin-users" will).
      keys.forEach((k) => { if (k && base.indexOf(k + '-') === 0 && k.length > want.length) want = k; });
    }
    if (!want && has(lastNavKey)) want = lastNavKey;   // a screen with no menu item of its own: keep the section you came from
    if (want) lastNavKey = want;
    links.forEach((link, i) => {
      const on = !!want && keys[i] === want;
      link.classList.toggle('active', on);
      if (on) { if (link.getAttribute('aria-current') !== 'page') link.setAttribute('aria-current', 'page'); }   // no same-value write (see setBadge)
      else if (link.getAttribute('aria-current') === 'page') link.removeAttribute('aria-current');
    });
  }
  // Groups added or rebuilt after the screen drew (Platform / Sales, every few seconds) are marked again.
  (function watchSidebarLinks() {
    let t = null;
    const kick = () => { if (!t) t = setTimeout(() => { t = null; try { updateActiveNav(); } catch (e) {} }, 60); };
    const attach = () => {
      const sb = document.querySelector('#appSidebar, .app-sidebar');
      if (!sb) { setTimeout(attach, 500); return; }
      try { new MutationObserver((muts) => { if (muts.some((m) => m.addedNodes.length)) kick(); }).observe(sb, { childList: true, subtree: true }); } catch (e) {}
    };
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', attach); else attach();
  })();

  // ──────────────── Screen router ──────────────────────────────
  const screens = {}; // populated by individual screen modules

  function registerScreen(name, screenFn) {
    screens[name] = screenFn;
  }

  // The "home" screen for a role = the first item in its nav. Admin/director
  // home is 'dashboard'; guardian/educator home is 'today'. Used so a role
  // always lands on a screen it actually has (not a foreign #dashboard).
  function homeHashForRole(role) {
    /* Phones land on the launcher, which is also where the Home button goes. Without
       this a platform_admin signed in to an agency dashboard -- primaryRoleOf() resolves
       them to 'agency_admin', so the first nav item is one tenant's numbers -- and on a
       phone there is no sidebar to get from there to the platform sections. The launcher
       is built from navItemsForRole(), so a superadmin's carries Reseller and a tenant
       admin's does not; each role lands on its own view of the portal.
       Desktop is deliberately unchanged: the sidebar is present there. */
    /* A PHONE IS A PHONE FROM ITS FIRST FRAME (2026-09-28).

       Anthony: after a fingerprint unlock the APK showed the agency dashboard (stat
       cards, centre cards) and then switched to the launcher. The width test alone is
       taken at boot, and an Android WebView can report a desktop-sized layout for its
       first moments (a 1513px layout on a 915px screen is on record), which answered
       "desktop" and landed a phone on the dashboard. __KT_PHONE is set by an inline
       <head> script from the PHYSICAL screen, which does not wobble — either signal
       saying phone is enough. Desktop windows narrower than 601px still get the
       launcher as before. */
    try {
      var phone = !window.matchMedia('(min-width: 601px)').matches || window.__KT_PHONE === true;
      if (phone && ['agency_admin', 'centre_director', 'platform_admin'].indexOf(role) !== -1) {
        return 'home';
      }
    } catch (e) { /* no matchMedia — fall through to the nav-order answer below */ }

    var secs = navItemsForRole(role) || [];
    if (secs[0] && secs[0].items && secs[0].items[0] && secs[0].items[0].hash) {
      return secs[0].items[0].hash;
    }
    return 'dashboard';
  }

  /* THE SIDEBAR AS DRAWN (2026-11-03, Anthony: "sales email hero banner not unique and matching the sidebar icon -
     full sweep"). Other files add sidebar sections this shell's lists never see: agency-switcher.js draws the whole
     Platform section, and a super admin's Sales section comes from the sales-rep list. Those screens fell through to
     a title-cased hash and the generic ✨ ("✨ Sales Email" beside 📮 Sales email). What the person can see in the
     sidebar is what the banner must say, so it is read first. */
  function drawnNavItem(base) {
    try {
      var sb = document.getElementById('appSidebar');
      if (!sb || !base) return null;
      var a = sb.querySelector('a[data-hash="' + base + '"], a[href="#' + base + '"]');
      if (!a) return null;
      var ic = a.querySelector('.nav-icon'), lb = a.querySelector('.nav-label');
      var icon = ic && !ic.querySelector('img,svg') ? (ic.textContent || '').trim() : '';
      var label = (lb ? lb.textContent : '').trim();
      return (icon || label) ? { icon: icon, label: label || base, section: 'drawn' } : null;
    } catch (e) { return null; }
  }

  function navItemFor(role, hash) {
    var base = String(hash || '').split('/')[0];
    var drawn = drawnNavItem(base);
    if (drawn && drawn.icon) return drawn;
    var secs = navItemsForRole(role) || [];
    for (var i = 0; i < secs.length; i++) {
      var items = secs[i].items || [];
      for (var j = 0; j < items.length; j++) {
        if (items[j].hash === base) return { icon: items[j].icon || '', label: items[j].label || base, section: secs[i].label || '' };
      }
    }
    /* Drawn with a picture rather than an emoji (Growth plan, Safe Arrival, Walk Watch): keep its sidebar name, and
       take the banner emoji from the table below, never from another role's list (that is how Growth plan's banner
       came out 🔑, the same as Licences). */
    /* Another role's sidebar names this screen (a super admin opening a sales-rep screen on a phone, with no sidebar
       to read): one screen, one icon, wherever it is opened from. */
    var OTHER = ['agency_admin', 'sales_rep', 'centre_director', 'educator', 'home_visitor', 'reseller_admin', 'guardian'];
    for (var r = 0; r < (drawn ? 0 : OTHER.length); r++) {
      if (OTHER[r] === role) continue;
      var os = [];
      try { os = navItemsForRole(OTHER[r]) || []; } catch (e) { os = []; }
      for (var oi = 0; oi < os.length; oi++) {
        var oit = os[oi].items || [];
        for (var oj = 0; oj < oit.length; oj++) {
          if (oit[oj].hash === base && oit[oj].icon) return { icon: oit[oj].icon, label: oit[oj].label || base, section: os[oi].label || '' };
        }
      }
    }
    // Icons for screens reached outside the sidebar nav (home tiles, deep links)
    // so their auto-banner gets a unique glyph instead of the generic ✨ default.
    /* my-hours / my-pay: the staff pay screen (2026-10-28, Anthony: "payroll hero in educator and staff view needs to be
       unique") - it fell through to the generic ✨, the same banner as every unlisted screen. */
    var EXTRA_ICONS = { 'awards': '🏆', 'walk': '🚶', 'walks': '🚶', 'prospect-ndas': '🤝', 'server-health': '🖥️', 'marketing-site': '🌍', 'ai-usage': '🤖', 'usage-spend': '📶', 'ask': '✨', 'licence': '🪴', 'billing-setup': '🧰',
      'my-hours': '💵', 'my-pay': '💵',
      /* 2026-10-28 sweep (Anthony: "hero banners in educator view - make sure they are all unique, check the parent view
         as well"): screens reached from home tiles, not the sidebar, that were all ✨. */
      'my-schedule': '📅', 'children': '🧒', 'menu': '🍽️', 'support': '🛟', 'scan': '📷', 'pickup-auth': '🔐',
      'ledger': '📒', 'trends': '📈', 'conferences': '🪑', 'directory': '📇', 'payment-plans': '🏧', 'wellness': '🩺' };

    /* And a NAME, where the hash is not one. The fallback below turns "my-hours"
       into "My Hours" — which is where the banner title came from, so renaming the
       home tile alone changed the tile and left the banner saying something else.

       Educators call this Payroll: the screen shows issued payslips, what is owed,
       and where it will be sent. That is payroll, not a timesheet.

       Per-role because the same hash is reached by a centre director, and what a
       director wants it called is their question, not one to answer here.

       THE HASH IS UNTOUCHED. #my-hours is in bookmarks, the home tiles,
       role-widgets and the Settings shortcut; renaming a route to change a word on
       screen would break every one of them. */
    /* #forms is "Documents" for staff (2026-10-09): their tile says Documents and the
       screen is the shared Documents table, so the banner must say so too. */
    var EXTRA_LABELS = { educator: { 'my-hours': 'Payroll', 'forms': 'Documents' }, home_visitor: { 'forms': 'Documents', 'my-pay': 'Payroll' },
      centre_director: { 'my-hours': 'My pay' } };
    /* Names the title-case fallback gets wrong ("Prospect Ndas"), for every role. */
    /* Website (2026-10-23): it moved into the Platform section, which this list does not read,
       so its banner fell back to "Marketing Site" with the default ✨ (Anthony: "not unique"). */
    var EXTRA_NAMES = { 'prospect-ndas': 'Prospect NDAs', 'marketing-site': 'Website', 'walk': 'Walk Watch', 'walks': 'Walk Watch', 'play-spark': 'Play Spark',
      'ai-usage': 'AI usage', 'usage-spend': 'Usage & spend', 'ask': 'Ask KiddieTrac' };   // were "Ai Usage" / "Usage Spend" (2026-10-28)
    var override = (EXTRA_LABELS[role] || {})[base] || EXTRA_NAMES[base];
    // 🗂️, not 📄: "My documents" already has 📄 in the same sidebar (2026-10-28 sweep).
    if (override === 'Documents' && base !== 'my-documents') { EXTRA_ICONS[base] = '🗂️'; }

    return {
      icon: EXTRA_ICONS[base] || '',
      label: (drawn && drawn.label) || override || base.replace(/-/g, ' ').replace(/\b\w/g, function (c) { return c.toUpperCase(); }),
      section: '',
    };
  }
  // One-line description under each banner. Keyed by screen hash, so the wording lives
  // in one place instead of being re-invented per screen (which is why some banners had
  // a description and some had none).
  var SCREEN_DESC = window.__ktScreenDesc = {
    // 2026-10-28 sweep: banners that had a title and nothing under it.
    // 2026-10-30 hero sweep: the rest.
    'sales-campaigns': 'Email campaigns to leads and prospects, with opens, clicks and replies.',
    'sales-email': 'The sales@ mailbox: read, reply and file emails against leads.',
    'provider-day': 'Today across your rooms: attendance, ratios, logs and what still needs doing.',
    'email': 'Your mailbox inside KiddieTrac: read, reply and send.',
    'withdrawals': 'Children leaving care: notice dates, final invoices and records.',
    'email-templates': 'The wording of every email KiddieTrac sends for you.',
    'settings': 'Your profile, sign-in, notifications and language.',
    'support': 'Ask the KiddieTrac team for help, and follow your requests.',
    'scan': 'Scan a One-Tap code to sign a child in or out.',
    'pickup-auth': 'Who may collect your child, and one-time pick-up permissions.',
    'ledger': 'Every charge and payment on your account, with a running balance.',
    'menu': 'This week\'s meals and snacks.',
    'children': 'The children in your rooms.',
    'walk': 'Live GPS walks & outings.',
    'walks': 'Live GPS walks & outings.',
    'trends': 'How your child\'s days have looked over time.',
    'my-hours': 'What you have been paid, what is still owed to you, your payslips and your pay ledger.',
    'my-pay': 'What you have been paid, what is still owed to you, your payslips and your pay ledger.',
    'dashboard': 'Today at a glance: enrolment, attendance, staffing and receivables.',
    'platform-overview': 'Every agency on the platform, with usage and revenue.',
    'platform-agencies': 'Create, configure and suspend agencies.',
    'agencies': 'Create, configure and suspend agencies.',
    'provider-map': 'Where your centres are, and who is on the floor.',

    'care-log': 'Meals, naps, nappies and notes for each child, as the day happens.',
    'chat': 'Conversations with families and staff.',
    'messages': 'Conversations with families and staff.',
    'announcements': 'Broadcast news to a centre, a room, or the whole agency.',
    'awards': 'Celebrate a child’s effort — daily, weekly or monthly. Parents see it too, and you can print a certificate.',
    'lesson-plans': 'Weekly plans for each room.',
    'play-spark': 'Play ideas and weekly lesson plans for every room.',
    'observations': 'Learning stories and developmental observations for each child.',
    'curriculum': 'Ontario units built on How Does Learning Happen?, ready to plan with.',
    'hdlh-gaps': 'Where your programme is light against your learning framework.',
    'learning-framework': 'The framework you plan and report against: HDLH, ELECT, BC ELF, Flight, EYFS and more.',
    'schedule': 'Who is working, and where.',
    'staff-calendar': 'Closures, trips, conferences, time off, birthdays and shifts across the agency.',
    'calendar': 'Closures, trips, conferences, time off, birthdays and shifts across the agency.',
    'auto-signoff': 'Close shifts and days somebody forgot to close.',
    'clock-settings': 'Reminders about the time clock, and closing what somebody forgot.',
    'notification-settings': 'Reminders and notices, one tab each: counter-signature reminders, provider bios, shared files and who staff can message.',
    'payment-providers': 'Your own Zum Rails, Stripe and Helcim accounts, plus the Giftbit / Tango Card gift card accounts for educator awards.',
    'billing-settings': 'Tax rate and other invoicing defaults.',
    'phone-voicemail': 'The toll-free greeting (voice and wording) and the voicemails callers leave.',
    'calendar-settings': 'Choose which layers the calendar draws.',
    'tasks': 'Assign tasks to educators and track them to completion.',
    'my-tasks': 'Tasks assigned to you — mark them in progress or done.',
    'certifications': 'Staff qualifications and their expiry dates.',
    'timesheets': 'Hours worked, ready for payroll.',
    'time-clock': 'Clock in and out.',
    'time-off': 'Leave requests awaiting a decision.',
    'hr': 'Employee files, leave, hiring, onboarding, reviews and courses.',
    'my-hr': 'Your onboarding checklist, courses, reviews and goals.',
    'substitutes': 'Cover for absent staff.',
    'payroll': 'Pay runs and hours.',
    'workforce-funding': 'Ontario WEG and workforce compensation: wage components, the 32-day check and the year-end report.',
    'background-checks': 'Criminal record and background screening for every staff member.',

    'waitlist': 'Families waiting for a space.',
    'tours': 'Prospective families who booked a centre tour.',
    'incidents': 'Injuries, behaviour and safeguarding reports.',
    'drills': 'Fire, weather, lockdown and missing-child drills — what is owed under your province or state.',
    'sleep-room': 'Sleeping children under 24 months: when each is due a check, record checks, and the inspector report.',
    'hazards': 'Non-routine hazards at your centres, the steps taken, and the notice to parents.',
    'pfcc': 'Ohio publicly funded child care: authorizations, copays, absent days and the weekly TAP reconciliation.',
    'sutq': 'Step Up To Quality: screening and assessment timers, referrals, the improvement plan and the evidence binder.',
    'medications': 'Medication authorised, given and outstanding.',
    'immunizations': 'Immunization records and what is missing.',
    'immun-schedule': 'Children with an immunization due.',
    'running-late': 'Running late for drop-off or pickup? Tell the centre, and nobody chases you before the time you give.',
    /* Read by parents as well as staff — "the roster" is not a thing a parent has. */
    'immunizations': 'Where each child stands against the immunization schedule, the records on file, and a place to send one in.',
    'allergy-alerts': 'Allergies and dietary requirements, by room.',
    'room-ratios': 'Live child-to-educator ratios against licensing.',
    'room-rotations': 'Which educator is in which room.',
    'closures': 'Holidays and unplanned closures.',
    'late-pickups': 'Late pickups and arrivals: log them, then approve the fee, waive it or decline.',
    'field-trips': 'Outings, consent and headcounts.',
    'trip-gps': 'Live GPS walks & outings: who is out, who has them, and where they are.',
    'bus-routes': 'Routes, stops and riders.',
    'zones': 'Activity zones within each room.',
    'menu': 'This week on the menu.',
    'cacfp': 'CACFP meal counts and claims.',
    'attendance-pattern': 'Attendance patterns and days booked.',
    'photos': 'Photos and clips shared with families.',
    'videos': 'Video clips shared with families.',
    'photo-tagging': 'Faces suggested by AI, for you to confirm.',
    'conferences': 'Parent-teacher conferences.',
    'report-cards': 'Progress reports for each child.',
    'wellness-digest': 'A weekly wellbeing summary for each child.',

    'admin-users': 'Staff accounts, roles and access.',
    'admin-centres': 'Centres and the rooms inside them.',
    'admin-children': 'Every child enrolled, and their records.',
    'admin-families': 'Families, guardians and contact details.',
    'parent-feedback': 'What families said about their child’s day.',
    'admin-roles': 'What each role is allowed to do.',
    'admin-forms': 'Forms families and staff are asked to complete.',
    'forms-manager': 'Upload a form, assign it to parents, educators or home visitors, and track who has signed it.',
    'ask': 'Ask about your centres in plain words: who owes money, who is not in yet, expiring certifications, room numbers and more.',
    'usage-spend': 'Texts, phone calls and AI spend per agency, the rates behind it, and the weekly thresholds for the superadmin summary.',
    'ai-usage': 'The Claude (AI) connection, its estimated credits and every AI call KiddieTrac makes - by agency, feature and model.',
    'admin-branding': 'Your logo, colours and the name families see.',
    'resellers': 'Partners who sell under their own brand.',
    'reseller-brand': 'The logos, colours and name your agencies show.',
    'reseller': 'Every agency and centre you brought to the platform, as of right now.',
    'reseller-onboard': 'Add an agency under your brand and invite its first admin.',
    'reseller-billing': 'What the platform billed your agencies, what was collected, and your commission.',
    'reseller-support': 'Tickets from all your agencies: answer them, or send them to the platform team.',
    'reseller-agencies': 'The agencies that carry your brand.',
    'admin-features': 'Turn features on and off per agency.',
    'admin-mrr': 'Recurring revenue across the platform.',
    'invitation-codes': 'Codes that let a family or educator join.',
    'synced-waitlist': 'Waitlist leads and enquiries.',
    'edocuments': 'Documents on file, and what is still outstanding.',
    'my-documents': 'Forms you have signed and reports shared with you, kept for you to open any time.',
    'report-issue': 'Tell the centre about something that needs looking into, and see their replies.',
    'parent-issues': 'Issues parents have reported. Reply, add an internal note, change the status or assign it.',
    'family-contacts': 'The people on your family\'s file. Ask the centre to give an emergency contact their own pick-up login.',
    'my-forms': 'Forms waiting for your signature. Once you sign one it moves to Documents, where it is kept.',
    'signed-docs': 'Agreements that have been signed.',
    'doc-workflows': 'Who has to sign what, and in which order.',
    'compliance': 'Licensing obligations and the evidence for them.',
    'hcc-forms': 'Monitoring & quarterly inspection forms submitted by home visitors.',
    'home-visit-reports': 'Home-visit reports filed by your home visitors, with edit history.',
    'inspection': 'Walk the licensing checklist before an inspector does.',
    'renewals': 'Licences and certificates coming up for renewal.',
    'cwelcc': 'CWELCC and provincial fee subsidies.',
    'subsidy-roa': 'Monthly fee subsidy Record of Attendance and CWELCC summary to enter in OCCMS.',
    'safe-arrival': 'Every scheduled child, accounted for.',
    'my-points': 'Points for every observation, log, walk and photo. Rewards arrive at month end.',
    'educator-rewards': 'Each educator\'s points for the month, and the bonuses, payouts and gift cards they earned.',
    'educator-award-settings': 'Automatic monthly thank-you awards: when they run, who qualifies, and who is told.',
    'audit-logs': 'Every change made in the portal, and by whom.',
    'security-alerts': 'Sign-in anomalies and security events.',
    'server-health': 'The server behind KiddieTrac: load, memory, disk, database, jobs, errors and backups.',
    'birthday-settings': 'Warm notes when a child or colleague has a birthday coming up.',
    'payroll-settings': 'When payroll is next due, so the overview can count down to it.',
    'data-retention': 'How long records are kept before deletion.',
    'privacy-breaches': 'The record of every privacy breach, kept 24 months as PIPEDA requires.',
    'privacy-requests': 'Requests to see, correct or delete personal information, with the 30-day deadline.',
    'signin-locks': 'Pause sign-in for a person, a centre or provider, and lift it when ready.',
    'photo-permissions': 'What each family allows for photos and videos of their child.',
    'anomalies': 'Unusual activity worth a second look.',


    'external-billing': 'Invoices and balances for the agency.',
    'billing': 'Invoices, payments and balances.',
    'billing-setup': 'Fees, schedules and automatic reminders.',
    'billing-schedule': 'When invoices go out, and when payment is due.',
    'bulk-invoices': 'Raise invoices for everyone in one run.',
    'refunds': 'Money returned to families.',
    'payment-plans': 'Agree a schedule of dated instalments, each raising its own invoice.',
    'tuition-plans': 'The fee plans a family can be placed on.',
    'tuition-increases': 'Plan and communicate a fee increase.',
    'sibling-discounts': 'Discounts for second and subsequent children.',
    'donations': 'Fundraising campaigns, gifts and donation receipts.',
    'rsvp': 'Invite people to an event, collect RSVPs, and report who was sent, signed up and opted out.',
    'grants': 'Operating and wage-enhancement funding: awarded, received, spent and reported.',
    'payment-run': 'Charge every auto-pay family\'s open invoices on their saved card, with a preview and results.',
    'compliance-evidence': 'SOC 2 readiness: policies, the control matrix and live evidence exports.',
    'vacation-holds': 'Places held while a family is away.',
    'expenses': 'Suppliers, purchase orders and bills.',
    'quickbooks': 'Sync invoices and payments with QuickBooks.',
    'xero': 'Send invoices, voids and payments to your Xero organisation.',
    'files': 'Printables, homework and notes between educators and families.',
    'sage': 'Sync with Sage Accounting, or export import files for Sage 50.',
    'forecast': 'Projected enrolment and revenue.',
    'reports': 'Ready-made reports you can print or export.',
    'retention': 'Which families stay, and which leave.',
    'reenrollment': 'Families due to re-enrol for next term.',
    'engagement': 'How engaged each family is with the app.',
    'nps': 'What families think of you.',
    'ai-churn': 'Families at risk of leaving.',
    'ai-docs': 'Pull the details out of an uploaded document.',
    'digest-status': 'Whether the daily AI summaries went out.',
    'maintenance': 'Schedule downtime and notify users by email.',
    'email-log': 'Every email the system sent — preview the exact message + open tracking.',

    'marketing-campaigns': 'Campaigns to fill your empty spaces.',
    'drip-campaigns': 'Automated follow-up with prospective families.',
    'marketing-site': 'www.kiddietrac.com: subscribers, website chat, content, SEO, the announcement bar, analytics and translations.',
    'sms': 'Send a one-off text to staff or families.',
    'email-settings': 'The mailbox your agency sends from.',
    'mail-settings': 'How the platform sends email, and the fallback that keeps it going.',   // Reseller › Email settings (platform)
    'backups': 'The nightly copy of the platform database — when it last ran, and how many are kept.',
    'sms-settings': 'The carriers your agency texts and calls through.',
    'notifications': 'What the app tells people about, and how.',
    'tickets': 'Operational issues, tracked to resolution.',
    'social-settings': 'Google, Microsoft and Facebook sign-in.',
    'mfa': 'Two-factor authentication for your team.',
    'help': 'Guides for every part of KiddieTrac.',
    'reports-canned': 'Ready-made reports you can print or export.'
  };

  var SCREEN_DESC_ROLE = {
    'dashboard:guardian': 'Your child’s day at a glance: check-in, photos, messages and what is due.',
    'dashboard:educator': 'Your rooms today: who is here, ratios, daily logs and tasks.',
    'dashboard:home_visitor': 'Your visits today, and what still needs doing.'
  };

  function buildAutoHero(info, hash) {
    var b = document.createElement('div');
    b.className = 'kt-hero kt-hero-auto kt-banner-fx';
    var h1 = document.createElement('h1');
    h1.textContent = String(info.label || '');   // textContent keeps the "&" in "Help & guides"
    b.appendChild(h1);

    var key = String(hash || '').split('/')[0].split('?')[0];
    var desc = SCREEN_DESC[key] || (info.label === 'Documents' ? SCREEN_DESC['my-documents'] : null);
    /* The same screen means different things to different people: the dashboard line ("enrolment, attendance,
       staffing and receivables") was shown to parents too (2026-10-30 iOS sweep). */
    try {
      var _rk = Roles.primaryRoleOf(Auth.user()) || '';
      if (SCREEN_DESC_ROLE[key + ':' + _rk]) { desc = SCREEN_DESC_ROLE[key + ':' + _rk]; }
    } catch (e) {}
    if (desc) {
      var sub = document.createElement('div');
      sub.className = 'kt-hero-sub';
      sub.textContent = desc;
      b.appendChild(sub);
    }

    var art = document.createElement('div');
    art.className = 'kt-hero-emoji';
    art.setAttribute('aria-hidden', 'true');
    art.textContent = String(info.icon || '\u2728');
    b.appendChild(art);
    return b;
  }

  // The banner belongs directly BELOW the top bar, never above it. Both are inserted at
  // the top of #appMain, so without this they race and the order flips per screen.
  // The toolbar that sits directly beneath the banner and holds its action buttons.
  function pageActions(main, hero) {
    var bar = main.querySelector('.kt-page-actions');
    if (!bar) {
      bar = document.createElement('div');
      bar.className = 'kt-page-actions';
      if (hero && hero.parentNode) hero.parentNode.insertBefore(bar, hero.nextSibling);
      else main.appendChild(bar);
    }
    return bar;
  }

  function heroAnchor(main) {
    var bar = main.querySelector('#kt-topbar');
    return bar ? bar.nextSibling : main.firstChild;
  }
  // Remount the top bar, but never more than a few times per screen visit.
  //
  // THE CAP IS LOAD-BEARING. Some screens (Support tickets) re-render themselves in
  // response to any #appMain mutation, so re-adding the bar makes them wipe it again —
  // an uncapped remount is an infinite render loop that freezes the tab. After the cap
  // we leave it to kt-topbar's own 1.2s poll, which is slow enough not to spiral.
  /* LIVE bar only (a direct child of the live #appMain): the refresh cover is a clone that
     carries its own id="kt-topbar", and getElementById found that one, so a background
     refresh skipped the remount and the bar was missing when the cover came off.

     The loop guard is a RATE, not a per-visit budget. A per-visit cap of 3 ran out after
     three background refreshes of the same screen (~2 minutes on a 45s poll) and from then
     on the shell stopped putting the bar back at all. A remount loop is many mounts in a
     moment, so that is what is capped: 3 within 2s. (2026-10-06) */
  var _tbStamps = [];
  function liveTopbar() {
    var m = document.getElementById('appMain');
    if (!m) return null;
    for (var i = 0; i < m.children.length; i++) { if (m.children[i].id === 'kt-topbar') return m.children[i]; }
    return null;
  }
  function ensureTopbar(hash) {
    void hash;
    if (liveTopbar()) return;
    var now = Date.now();
    _tbStamps = _tbStamps.filter(function (t) { return now - t < 2000; });
    if (_tbStamps.length >= 3) return;
    try {
      if (window.KT && KT.topbar && KT.topbar.ensure) {
        KT.topbar.ensure();
        if (liveTopbar()) _tbStamps.push(now);
      }
    } catch (e) {}
  }

  // Shared, per-screen-visit budget for banner normalisation. Deliberately survives
  // re-renders of the same screen — that is the whole point (see __ensure).
  var _bannerHash = null, _bannerRuns = 0;
  function bannerSync(hash) {
    var h = String(hash || '').split('/')[0];
    if (h !== _bannerHash) { _bannerHash = h; _bannerRuns = 0; }
  }
  // Is there budget left? (Cheap look — costs nothing.)
  function bannerBudgetLeft(hash) { bannerSync(hash); return _bannerRuns < 15; }
  // Charge the budget — called ONLY after we actually changed the DOM, so a screen
  // that merely re-renders a lot does not starve itself, while a screen that fights us
  // over its banner still runs out and is left alone.
  function bannerSpend(hash) { bannerSync(hash); _bannerRuns++; }
  // Putting back a banner that is missing: a RATE, like the top bar's remount guard.
  var _heroStamps = [];
  function heroInsertAllowed() {
    var now = Date.now();
    _heroStamps = _heroStamps.filter(function (t) { return now - t < 2000; });
    if (_heroStamps.length >= 3) return false;
    _heroStamps.push(now);
    return true;
  }

  // Strip emoji/punctuation so "\ud83d\udcda Lesson Plans" and "Lesson plans" compare equal.
  function heroKey(t) {
    return String(t || '').replace(/[^\p{L}\p{N} ]/gu, ' ').replace(/\s+/g, ' ').trim().toLowerCase();
  }

  // Collapse whatever a screen rendered at the top into the single standard hero:
  //   1. legacy header (.page-header-v17 / .kt-page-hero) -> its subtitle and action
  //      buttons are MOVED into the hero (moved, not rebuilt, so ids and already-bound
  //      listeners survive), then the header is removed;
  //   2. a screen's own inline-gradient banner (Tours) -> removed;
  //   3. a heading that merely repeats the hero title ("Medications" under the
  //      Medications banner) -> removed, leaving any buttons beside it in place.
  //
  // Cheap and idempotent by construction: it only ever looks at main's first few
  // levels, reads the inline style ATTRIBUTE rather than computed style (no forced
  // layout), and marks what it has handled.
  // Lift action buttons out of ANY banner into the toolbar beneath it. Buttons inside a
  // banner sit on top of the artwork and read as decoration rather than controls.
  // Returns true if it moved anything.
  function liftBannerButtons(main) {
    var banner = main.querySelector('.kt-hero, .kt-page-hero');
    if (!banner) return false;
    var btns = main.querySelectorAll('.kt-hero button, .kt-hero a.btn, .kt-page-hero button, .kt-page-hero a.btn');
    if (!btns.length) return false;
    var host = pageActions(main, banner);
    for (var q = 0; q < btns.length; q++) host.appendChild(btns[q]);   // move: keeps ids + listeners
    return true;
  }

  /**
   * A screen that draws its OWN banner very often ALSO draws a plain <h1> + subtitle
   * above it, so the page opens with the title twice — once as grey text, once in the
   * branded banner further down — and the banner no longer leads the page.
   * (Anthony, 2026-08-30, on #admin-families: "the banner is not at the top and there is
   * other text at the top which is redundant to the banner".)
   *
   * De-duplication already existed for the shell's own `.kt-hero-auto` and for the
   * legacy `.page-header-v17`, which is why this looked handled — a bare <h1>+<div> pair
   * matched neither. Fixed HERE rather than in screen-admin.js so it covers every screen
   * with the pattern, including ones written later.
   *
   * Deliberately narrow, because this deletes markup a screen rendered:
   *   • the heading must say the SAME thing as the banner (heroKey-normalised, so
   *     "👪 Families" and "Families" match while "Families" and "Children" never do);
   *   • it must come BEFORE the banner in document order;
   *   • its container is only removed when it holds nothing interactive — otherwise just
   *     the heading goes and whatever else was in there stays.
   */
  function tidyOwnBanner(main) {
    var banner = main.querySelector('.kt-hero:not(.kt-hero-auto), .kt-page-hero');
    if (!banner) return false;
    var changed = false;
    var title = heroKey((banner.querySelector('h1, h2') || {}).textContent);

    if (title) {
      var heads = main.querySelectorAll('h1, h2');
      for (var i = 0; i < heads.length; i++) {
        var h = heads[i];
        if (banner.contains(h)) continue;
        if (heroKey(h.textContent) !== title) continue;
        // Only a heading ABOVE the banner is the redundant one. A matching heading
        // further down is a section of the page that happens to share the name.
        if (!(h.compareDocumentPosition(banner) & Node.DOCUMENT_POSITION_FOLLOWING)) continue;

        var block = h.parentElement;
        var disposable = block && block !== main && block.children.length <= 3 &&
          [].every.call(block.children, function (c) {
            return c === h || !c.querySelector('button, input, select, textarea, table, a');
          });
        if (disposable) block.parentNode.removeChild(block);
        else h.parentNode.removeChild(h);
        changed = true;
        break;
      }
    }

    /* And the banner leads its own section. Bounded to the first few positions: a banner
       sitting third behind a title and a tab strip is one that was meant to be at the
       top, whereas one halfway down the page belongs where the screen put it. */
    var par = banner.parentElement;
    if (par) {
      /* THE TOP BAR IS CHROME, NOT THE SCREEN.
         This hoisted the banner to par.firstElementChild, and on any screen whose hero is
         a DIRECT child of #appMain that first child is #kt-topbar — so the banner was
         lifted ABOVE the greeting bar and the page read hero, then greeting, then content.
         Immunizations was the visible one; screens that wrap their hero in a padding div
         were untouched, which is why it looked like one screen's bug rather than this
         function's. heroAnchor() below has always known about the bar; this did not.

         Walked rather than matched with :scope, which older WebViews in the packaged app
         do not all support — and a normaliser that throws leaves every banner unshimmered. */
      var bar = null;
      for (var bi = 0; bi < par.children.length; bi++) {
        if (par.children[bi].id === 'kt-topbar') { bar = par.children[bi]; break; }
      }
      var anchor = bar ? bar.nextElementSibling : par.firstElementChild;
      var base = bar ? 1 : 0;
      if (anchor !== banner) {
        var idx = [].indexOf.call(par.children, banner);
        /* Same bounded window as before, measured from the first real content slot: a
           banner sitting third behind a title and a tab strip was meant to be at the top,
           one halfway down the page belongs where the screen put it. */
        if (idx > base && idx <= base + 2) { par.insertBefore(banner, anchor); changed = true; }
      }
    }
    return changed;
  }

  /* ONE BANNER PER SCREEN — THE RULE IN ONE PLACE (2026-10-21).

     Anthony: "security alerts section has two hero banners - look into this and full sweep
     to make sure this never happens again throughout the portal".

     Two functions answered "did the screen bring its own banner?" differently. __hasHero()
     ignored .kt-page-hero (so the shell added its automatic banner), and normaliseBanners()
     counted it (so the shell removed that banner again). Every timer tick and every
     mutation repeated the insert-and-remove - 9 times per visit to Security alerts - and
     each removal spent the per-screen budget of 15. One in-place refresh (kt-auto-refresh,
     kt-live) spent the rest; from then on the automatic banner was still put back (a missing
     banner is restored whatever the budget says) but nothing took it away again, and the
     page showed two.

     ownBanner() is now the single answer, used by both: any .kt-hero the screen drew (as
     before), or a .kt-page-hero that LEADS the screen - among the first content slots at
     each of the top few levels. A .kt-page-hero further in is a section header (Settings'
     Wallet tab), which is why that class was taken out of __hasHero on 2026-08-31; leading
     position keeps that fix while counting the real page banners.

     enforceOneBanner() is the guarantee: it runs on every pass, outside the budget, and only
     ever removes banners the SHELL made - never markup a screen rendered - so it is
     idempotent and cannot ping-pong. */
  function bannerChrome(el) {
    return el.id === 'kt-topbar' || (el.classList && el.classList.contains('kt-page-actions'))
      || /^(SCRIPT|STYLE|LINK|TEMPLATE)$/.test(el.tagName);
  }
  function leadsMain(main, el) {
    var depth = 0, n = el;
    while (n && n !== main) {
      if (++depth > 4) return false;
      var par = n.parentElement;
      if (!par) return false;
      if (n.hidden || (n.style && n.style.display === 'none')) return false;
      var pos = 0;
      for (var c = par.firstElementChild; c && c !== n; c = c.nextElementSibling) { if (!bannerChrome(c)) pos++; }
      if (pos > 2) return false;
      n = par;
    }
    return n === main;
  }
  function ownBanner(main) {
    var own = main.querySelector('.kt-hero:not(.kt-hero-auto)');
    if (own) return own;
    var ph = main.querySelectorAll('.kt-page-hero');
    for (var i = 0; i < ph.length; i++) {
      if (leadsMain(main, ph[i])) {
        /* The page banner LOOKS like one wherever it is (2026-10-21, Reseller section): the
           gradient for .kt-page-hero only applied under [data-kt-pretty], which screens set
           themselves - Resellers did not, so its banner was a plain white card. The class
           is a hook for kt-consistency-polish.css; set once (a class change is not a
           childList mutation, so the banner observer does not re-fire). */
        if (!ph[i].classList.contains('kt-hero-lead')) { ph[i].classList.add('kt-hero-lead'); }
        return ph[i];
      }
    }
    return null;
  }
  /* THE AUTOMATIC BANNER LINES UP WITH THE PAGE (2026-10-21).
     Anthony: "reseller section is not consistent with the size of the view like other
     sections". The shell's banner sits directly in #appMain (20px in) while nearly every
     screen pads its own content another 24px, so on every automatic-banner screen (MRR,
     Feature flags, Usage & spend, Schedule ...) the banner was wider than the page under it.
     Measured, not assumed: the banner and its action bar take the left/right edges of the
     screen's own content box, so this holds for any wrapper, and phones (where
     kt-phone-gutter.js removes wrapper padding) get 0. Margins only: no childList change,
     so the banner observer does not re-fire. */
  /* THE PAGE LINES UP WITH THE BANNER, NOT THE OTHER WAY ROUND (2026-11-09, Anthony: "look at settings under
     educator as example and you will see the hero banner and body is not sized like the other sections - full sweep").
     The 10-21 rule above pulled the banner IN to each screen's own padding, so 13 educator screens (Settings, Help,
     Medications, Alerts, Safe Arrival, Lesson plans, Payroll ...) had a banner and body 14-24px narrower on each side
     than every other screen. Now the banner always sits at the page edge (#appMain's padding, like every screen that
     draws its own) and a PLAIN wrapper under it - no background, border or shadow - drops its side padding and any
     centred max-width, so the body takes the banner's edges. A card keeps its own inner padding; its box lines up.
     Inline styles only: no childList change, so the banner observer does not re-fire. */
  function plainBox(cs) {
    return (cs.backgroundColor === 'rgba(0, 0, 0, 0)' || cs.backgroundColor === 'transparent') && cs.backgroundImage === 'none'
      && !(parseFloat(cs.borderLeftWidth) > 0) && !(parseFloat(cs.borderRightWidth) > 0) && cs.boxShadow === 'none';
  }
  function flattenInset(el) {
    if (el.hasAttribute('data-kt-keep-inset')) { return; }
    var cs = getComputedStyle(el);
    if (cs.position === 'fixed' || cs.position === 'absolute') { return; }
    var st = el.style;
    if (parseFloat(cs.marginLeft) > 0) { st.setProperty('margin-left', '0', 'important'); }
    if (parseFloat(cs.marginRight) > 0) { st.setProperty('margin-right', '0', 'important'); }
    if (cs.maxWidth !== 'none') { st.setProperty('max-width', 'none', 'important'); }
    if (plainBox(cs)) {
      if (parseFloat(cs.paddingLeft) > 0) { st.setProperty('padding-left', '0', 'important'); }
      if (parseFloat(cs.paddingRight) > 0) { st.setProperty('padding-right', '0', 'important'); }
    }
  }
  function alignAutoHero(main) {
    if (!main) { return; }
    var hero = null, c;
    for (c = main.firstElementChild; c; c = c.nextElementSibling) {
      if (c.classList && c.classList.contains('kt-hero-auto')) { hero = c; break; }
    }
    /* A screen's OWN leading banner inside its padded wrapper (Drills, Files, Payment run ...): the plain wrappers
       between it and #appMain lose their side inset, so the banner and the body under it take the page edges. */
    if (!hero) {
      var own = main.querySelector('.kt-hero, .kt-page-hero');
      if (own && own.parentElement !== main && leadsMain(main, own)) {
        for (var n = own.parentElement; n && n !== main; n = n.parentElement) { flattenInset(n); }
      }
      return;
    }
    var targets = [hero];
    for (c = hero.nextElementSibling; c; c = c.nextElementSibling) {
      if (c.classList && c.classList.contains('kt-page-actions')) { targets.push(c); break; }
      if (!bannerChrome(c)) { break; }
    }
    targets.forEach(function (t) {
      if (t.style.marginLeft) { t.style.marginLeft = ''; }
      if (t.style.marginRight) { t.style.marginRight = ''; }
      if (t.style.getPropertyValue('width') === 'auto') { t.style.removeProperty('width'); }
    });
    for (c = hero.nextElementSibling; c; c = c.nextElementSibling) {
      if (bannerChrome(c) || c.hidden || !c.offsetWidth) { continue; }
      flattenInset(c);
    }
  }
  /* NO SECOND COPY OF THE BANNER UNDER IT (2026-11-09, Anthony: "under tasks (educator view) you will see under the
     hero banner repetitive words as to what the hero banner shows - clean this up (full sweep with all sections)").
     Screens written before the shell drew banners still open with their own heading and intro line ("Tasks assigned
     to you by your childcare agency." under "My tasks · Tasks assigned to you"). One rule, for every screen now and
     later: a heading or plain line sitting on the page (not in a card, no buttons or fields) within the first ~200px
     under the banner that repeats the banner's title or subtitle is hidden. data-kt-keep-intro keeps one. */
  function echoWords(t) {
    return String(t || '').toLowerCase().replace(/[\u2600-\u27BF\uFE0F\u200D]|[\uD83C-\uDBFF][\uDC00-\uDFFF]/g, '')
      .replace(/[^a-z0-9\u00C0-\u024F ]+/g, ' ').split(/\s+/).filter(function (w) { return w.length > 2; });
  }
  function echoes(a, b, heading) {
    var A = echoWords(a), B = echoWords(b);
    if (!A.length || !B.length) { return false; }
    var inB = A.filter(function (w) { return B.indexOf(w) >= 0; }).length;
    // "Documents" under "My documents", "Support & Feedback" under "Support": one holds the other, one word to spare.
    if (heading) { var inA0 = B.filter(function (w) { return A.indexOf(w) >= 0; }).length; return (inB === A.length || inA0 === B.length) && Math.abs(A.length - B.length) <= 1; }
    var inA = B.filter(function (w) { return A.indexOf(w) >= 0; }).length;
    return Math.min(inB, inA) >= 3 && Math.min(inB, inA) / Math.min(A.length, B.length) >= 0.5;
  }
  function dropEchoes(main) {
    if (!main) { return; }
    var hero = null, c;
    for (c = main.firstElementChild; c; c = c.nextElementSibling) {
      if (c.classList && c.classList.contains('kt-hero-auto')) { hero = c; break; }
    }
    if (!hero) { var own = main.querySelector('.kt-hero, .kt-page-hero'); if (own && leadsMain(main, own)) { hero = own; } }
    if (!hero || !hero.offsetWidth) { return; }
    var tEl = hero.querySelector('h1, h2'), sEl = hero.querySelector('.kt-hero-sub, p');
    var title = tEl ? tEl.textContent : '', sub = sEl ? sEl.textContent : '';
    if (!title && !sub) { return; }
    var bottom = hero.getBoundingClientRect().bottom;
    var all = main.querySelectorAll('h1, h2, h3, p, div, span');
    for (var i = 0; i < all.length; i++) {
      var e = all[i];
      if (hero.contains(e) || e.contains(hero) || e.hasAttribute('data-kt-echo') || !e.offsetWidth) { continue; }
      var r = e.getBoundingClientRect();
      if (r.top < bottom - 2) { continue; }
      if (r.top > bottom + 200) { break; }
      if (e.closest('[data-kt-keep-intro], button, a, label, table, nav, [role="tab"], [role="tablist"], [role="dialog"]')) { continue; }
      if (e.querySelector('button, a, input, select, textarea, img, svg, canvas, table')) { continue; }
      var blocky = false;
      for (var k = 0; k < e.children.length; k++) { if (/^(DIV|P|H\d|UL|OL|SECTION|FORM)$/.test(e.children[k].tagName)) { blocky = true; break; } }
      if (blocky) { continue; }
      var txt = (e.textContent || '').trim();
      if (!txt || txt.length > 240) { continue; }
      // On the page itself, not inside a card or panel.
      var onPage = true;
      for (var n = e.parentElement; n && n !== main; n = n.parentElement) { if (!plainBox(getComputedStyle(n))) { onPage = false; break; } }
      if (!onPage) { continue; }
      var cs = getComputedStyle(e);
      var heading = /^H[1-3]$/.test(e.tagName) || (parseFloat(cs.fontSize) >= 17 && parseInt(cs.fontWeight, 10) >= 600);
      if ((heading && echoes(txt, title, true)) || (!heading && (echoes(txt, sub, false) || echoes(txt, title + ' ' + sub, false)))) {
        e.setAttribute('data-kt-echo', '1');
        e.style.setProperty('display', 'none', 'important');
      }
    }
  }
  (function () {
    var tm = null;
    try {
      window.addEventListener('resize', function () {
        clearTimeout(tm);
        tm = setTimeout(function () { try { alignAutoHero(document.getElementById('appMain')); } catch (e) {} }, 150);
      });
    } catch (e) {}
  })();

  function enforceOneBanner(main) {
    if (!main) return false;
    var autos = main.querySelectorAll('.kt-hero-auto');
    if (!autos.length) return false;
    var keep = ownBanner(main) ? null : autos[0];
    var changed = false;
    for (var i = 0; i < autos.length; i++) {
      if (autos[i] !== keep && autos[i].parentNode) { autos[i].parentNode.removeChild(autos[i]); changed = true; }
    }
    return changed;
  }

  function normaliseBanners(main, hash) {
    var hero = main.querySelector('.kt-hero-auto');

    // If the screen rendered its OWN banner after we had already added the automatic one
    // (the Admin tabs do this: each tab draws its own .kt-hero), drop OURS — theirs is
    // the more specific one, and otherwise two banners stack. We only ever remove the
    // element we created, never one the screen owns.
    if (hero) {
      var own = ownBanner(main);
      if (own) { hero.parentNode.removeChild(hero); tidyOwnBanner(main); return true; }
    }
    // No auto-banner: the screen brought its own (role-home's greeting, or a
    // .kt-page-hero). Its buttons still come out — and so does any heading repeating it.
    if (!hero) { var t = tidyOwnBanner(main); return liftBannerButtons(main) || t; }
    var changed = liftBannerButtons(main);
    var h1 = hero.querySelector('h1');
    var title = heroKey(h1 && h1.textContent);

    // 1. legacy headers
    var legacy = main.querySelectorAll('.page-header-v17');
    for (var i = 0; i < legacy.length; i++) {
      var L = legacy[i];
      if (hero.contains(L)) continue;

      // The legacy header's own <h1> is the page's real title ("AI-generated Lesson
      // Plans"), and it beats the nav label — let alone the hash-derived fallback
      // ("Lesson Plans Ai"). Carry it into the banner.
      var lh1 = L.querySelector('h1');
      if (lh1 && h1) {
        var lt = (lh1.textContent || '').trim();
        if (lt) { h1.textContent = lt; title = heroKey(lt); }
      }

      var sub = L.querySelector('.sub, .subtitle');
      if (sub && !hero.querySelector('.kt-hero-sub')) {
        var sd = document.createElement('div');
        sd.className = 'kt-hero-sub';
        sd.textContent = (sub.textContent || '').trim();
        var em = hero.querySelector('.kt-hero-emoji');
        hero.insertBefore(sd, em || null);
      }

      var acts = L.querySelector('.actions');
      var btns = acts ? acts.children : L.querySelectorAll('button, a.btn');
      if (btns && btns.length) {
        var bar = pageActions(main, hero);
        while (btns.length) bar.appendChild(btns[0]);   // move: keeps listeners + ids
      }
      L.parentNode.removeChild(L);
      changed = true;
    }

    // 3. The heading a screen prints as its own page title.
    //
    // Two cases, both ending with ONE banner and no repeated title underneath:
    //  (a) it repeats the banner ("Medications" under a Medications banner) -> drop it;
    //  (b) it IS the page's name but the banner is showing the nav SECTION instead
    //      ("Medications" under a banner reading "Health", because the nav item for
    //      #medications is labelled Health) -> promote it into the banner, then drop it.
    //      Promotion is deliberately limited to a heading that matches the page's own
    //      hash, so an ordinary card title can never rename the banner.
    var pageKey = heroKey(String(hash || '').split('/')[0].replace(/-/g, ' '));
    var heads = main.querySelectorAll('h1, h2, h3');
    for (var k = 0; k < heads.length && k < 6; k++) {
      var H = heads[k];
      if (hero.contains(H)) continue;
      var hk = heroKey(H.textContent);
      if (!hk) continue;
      if (title && hk === title) {                 // (a) duplicate
        H.parentNode.removeChild(H);
        changed = true;
        break;
      }
      /* (c) the heading EXTENDS the banner's title — "White-Label Branding" under a
         banner reading "Branding", which is the same page named twice, just not
         character-for-character, so (a) never caught it. The longer one is the real
         name: promote it, drop the heading. Whole-word containment only, so "Room
         ratios" is never folded into a banner titled "Rooms". */
      /* endsWith/startsWith, NOT indexOf-arithmetic: `hk.indexOf(' '+title) === hk.length
         - title.length - 1` is true whenever BOTH sides are -1, i.e. whenever the two
         strings merely happen to be the same LENGTH. That promoted "Live invoice preview"
         (20 chars) into a banner titled "White-Label Branding" (20 chars) the moment this
         shipped. Compared as strings there is no sentinel to collide with. */
      if (title && hk !== title &&
          (hk.indexOf(title + ' ') === 0 || hk.slice(-(title.length + 1)) === ' ' + title)) {
        if (h1) h1.textContent = (H.textContent || '').replace(/[^\p{L}\p{N} &'\-]/gu, '').trim();
        H.parentNode.removeChild(H);
        changed = true;
        break;
      }
      if (pageKey && hk === pageKey) {             // (b) the real page title
        if (h1) h1.textContent = (H.textContent || '').replace(/[^\p{L}\p{N} &'\-]/gu, '').trim();
        H.parentNode.removeChild(H);
        changed = true;
        break;
      }
    }
    return changed;
  }

  var _hashStack = [];
  function _trackNav(h) {
    if (_hashStack.length > 1 && _hashStack[_hashStack.length - 2] === h) { _hashStack.pop(); }
    else if (_hashStack[_hashStack.length - 1] !== h) { _hashStack.push(h); }
  }
  /* Is the user in the middle of something a screen teardown would destroy?
     ONE answer, shared by kt-auto-refresh and kt-live, which each had their own partial
     selector list. Returns true to DEFER a refresh, never to cancel it. */
  window.KT = window.KT || {};
  window.KT.uiBusy = function () {
    try {
      // 1. Anything focused that holds text or a choice.
      var a = document.activeElement;
      if (a) {
        var t = (a.tagName || '').toUpperCase();
        if (t === 'INPUT' || t === 'TEXTAREA' || t === 'SELECT' || a.isContentEditable) return true;
        /* Focus inside a dialog counts even when it is on a button: the classic way to
           lose your work is to type, then tick a box, and have the tick move focus off
           the input just as the timer fires. */
        if (a.closest && a.closest('[role="dialog"], .kt-modal, .kt-modal-overlay, .modal-backdrop, .kt-scrim')) return true;
      }

      // 2. The dialog shapes this portal actually uses, by name.
      /* .kt-select-sheet is named here rather than left to the shape test below,
         because that test skips anything under 0.05 opacity and the sheet fades in from
         opacity:0. For those ~170ms a refresher saw no dialog at all and was free to
         rebuild the screen underneath an open dropdown — which orphans the <select> the
         sheet is holding, so the user's choice fires `change` from a detached element
         and is silently lost. A class name has no timing hole. (Anthony, 2026-09-07) */
      if (document.querySelector(
        '.kt-modal, .kt-modal-overlay, .modal-backdrop, .kt-scrim, .kt-lightbox,'
        + ' .kt-doc-viewer, .kt-av-zoom, [role="dialog"], [data-kt-open-menu], .kt-sheet-open,'
        + ' .kt-select-sheet'
      )) return true;
      var mr = document.getElementById('modalRoot');
      if (mr && mr.firstElementChild) return true;

      /* 3. And by SHAPE, for the dozen dialogs built with inline position:fixed and no
            class at all. Only body's own children are measured — dialogs are appended
            there — so this stays a handful of getComputedStyle calls. */
      var kids = document.body ? document.body.children : [];
      for (var i = 0; i < kids.length; i++) {
        var el = kids[i];
        if (el.id === 'appMain' || el.tagName === 'SCRIPT' || el.tagName === 'STYLE' || el.tagName === 'LINK') continue;
        if (el.hidden) continue;
        var cs = window.getComputedStyle(el);
        if (cs.position !== 'fixed' || cs.display === 'none' || cs.visibility === 'hidden') continue;
        if (parseFloat(cs.opacity || '1') < 0.05) continue;
        var z = parseInt(cs.zIndex, 10);
        if (isNaN(z) || z < 900) continue;              // toasts and bars sit lower
        var r = el.getBoundingClientRect();
        // Big enough to be a dialog rather than a badge or a floating button.
        if (r.width < 200 || r.height < 120) continue;
        return true;
      }

      // 4. A screen that says it updates itself, or asks not to be refreshed.
      if (document.querySelector('[data-kt-no-autorefresh], [data-kt-self-live]')) return true;
    } catch (e) { /* never let this throw and block a refresh forever */ }
    return false;
  };

  window.ktBack = function () {
    if (_hashStack.length > 1) { var prev = _hashStack[_hashStack.length - 2]; window.location.hash = (prev.charAt(0) === '#' ? prev : '#' + prev); }
    else { try { window.location.hash = '#' + homeHashForRole(Roles.primaryRoleOf(Auth.user())); } catch (e) { window.location.hash = '#dashboard'; } }
  };
  /* Is this control "go back", or something else that merely starts with an arrow?
     Getting this wrong is expensive: the handler below captures, so a false positive
     both cancels the control's own click AND navigates away from the screen.

     The old test was "starts with an arrow and is 8 characters or shorter", which caught
     every '‹ Prev' pager in the portal — the table pager, chat, forms, parent, care's
     previous-day, notifications' '‹ Newer' — and '← OUT', which checks a child out.
     kt-icon-buttons.js had already solved the same problem; this matches its reasoning. */
  function isBackControl(el, t) {
    if (el.hasAttribute && el.hasAttribute('data-back')) return true;
    if (el.classList && el.classList.contains('kt-back')) return true;

    /* AN IN-PAGE CONTROL IS NEVER BROWSER-BACK, whatever it is called.

       This listener captures and calls stopPropagation(), so anything it claims by
       mistake loses its OWN click handler and navigates away from the screen instead.
       The word test below claims every control whose label contains "back" — and a
       screen has every right to say "Back to Active", "Back to edit", "Back to
       incidents": those move between views WITHIN the screen and have nothing to do
       with history.

       Screens had been coping by never writing the word (see the note in
       screen-account-ledgers.js, which renamed its pair "All accounts" / "One account"
       purely to dodge this listener). That is a workaround, and it forces labels to be
       chosen around a bug rather than for the reader. This is the actual fix: a control
       marks itself data-kt-inpage and the shell leaves it alone.

       Found via the archived chat list: "Back to inbox" was silently doing a history
       back — it left Message Centre for whatever screen you were on before, instead of
       returning to the active conversations. (Anthony, 2026-09-09) */
    if (el.hasAttribute && el.hasAttribute('data-kt-inpage')) return false;

    /* A "Back" INSIDE A POPUP is the popup's own previous step, never browser history
       (2026-10-13, Anthony: "when going back in the adding family the pop-up window
       closed on its own"). The New family wizard's "← Back" was claimed here, its own
       step-back handler never ran, and the hash navigation that followed closed the
       dialog. Explicit back controls (data-back / .kt-back, above) still count. */
    /* KT.confirm cards are role="alertdialog" (.kt-cf): its "Yes, I'm back" was swallowed here, so nobody could
       come back from lunch (2026-11-06). */
    if (el.closest && el.closest('#modalRoot, [role="dialog"], [role="alertdialog"], .kt-cf, .kt-modal, .modal')) return false;

    /* A link with a real destination already says where it goes; honouring the href is
       always at least as correct as guessing history. */
    if (el.tagName === 'A') {
      var href = el.getAttribute('href') || '';
      if (href && href !== '#') return false;
    }

    var s = (t || '').toLowerCase();
    /* A LABEL, NOT CONTENT (2026-11-03, Anthony: "in dialpad when in text messages and trying to view text
       messages it doesnt show me that text message and it leaves the page"). A conversation row is a button
       whose text includes the last message - "...I'll get back to you" - and the word test below claimed it:
       the row's own click never ran and the page went back to the dashboard. The same goes for any list row
       that shows what people wrote (chats, email, leads). A real back control is a short label ("‹ Back",
       "Back to inbox", "← Back to incidents"), so the word only counts on one. */
    if (s.replace(/\s+/g, ' ').trim().length > 32 || s.trim().split(/\s+/).length > 5) return false;
    // Paging and check-in/out are actions on the page, never navigation history.
    if (/\b(prev|previous|next|newer|older|forward)\b/.test(s)) return false;
    /* "I'm back" (the end of a lunch or break, kt-clockbar.js + the Clock in/out screen) says the person is back,
       it does not ask to go back: claimed here, the Time clock's "I'm back" went to the previous screen and the
       break never ended (2026-11-06, Anthony). */
    if (/\b(i['’]?m|i am|we['’]?re|we are|be|come|came|welcome|right)\s+back\b/.test(s)) return false;
    if (/\bback\b/.test(s)) return true;

    /* A bare long arrow, alone — with an optional U+FE0F, because kt-icon-buttons.js
       rewrites a back label to the emoji '⬅️' on mobile (desktop keeps the text), and
       that is the glyph plus a variation selector.

       '‹' is deliberately absent: in this codebase it is the pagination glyph — a dozen
       pagers use it and nothing uses it to mean back. Real back controls read '← Back'
       or '‹ Back' and are caught by the word above. */
    return /^[←⟵⬅⭠]️?$/.test((t || '').trim());
  }

  document.addEventListener('click', function (e) {
    var el = e.target && e.target.closest ? e.target.closest('a,button,[data-back]') : null;
    if (!el) return;
    if (!isBackControl(el, (el.textContent || '').trim())) return;
    e.preventDefault(); e.stopPropagation();
    // Overlay-aware: closes an open thread/compose/invoice back to the list
    // it sits on, and only navigates the hash when nothing is stacked.
    if (window.KT && KT.goBack) KT.goBack(); else window.ktBack();
  }, true);

  /* THE ROLE YOU CHOSE, REMEMBERED FOR NEXT TIME (2026-09-17).

     Anthony: "super admin logging into mobile APK can you default my role to super admin
     or the last role that I chose when logging off please."

     `kt_view_as` lives in sessionStorage and dies with the session, which is right - a
     preview of a lower role should not outlive the sitting. What made it sticky on the
     APK is that kt-biometric.js copied it into localStorage and restored it on every
     unlock, so a role previewed once came back for good, and an unlock is the NORMAL way
     into the app on a phone.

     So the preference is recorded HERE instead, at the only moment somebody actually
     chooses a role, and the biometric path no longer guesses.

     KEYED TO THE USER. A device is shared - a director's tablet, a kiosk - and a
     preference stored loose in localStorage would hand the next person to sign in the
     previous person's role. The same trap as the active agency, which is session-only
     for exactly this reason ([[kiddietrac-active-agency-localstorage]]). An entry whose
     uid does not match the account signing in is ignored, not applied.

     Choosing "Super admin (default)" is a choice too: it is stored as an empty role so
     the next sign-in honours it rather than falling through to some older preference. */
  window.ktViewAsPrefSave = function (role) {
    try {
      var u = JSON.parse(sessionStorage.getItem('kt_user') || localStorage.getItem('kt_user') || '{}');
      if (!u || !u.id) { return; }
      localStorage.setItem('kt_view_as_pref', JSON.stringify({ uid: u.id, role: role || '' }));
    } catch (e) {}
  };

  /** The remembered role for the account signing in, or null when there isn't one. */
  window.ktViewAsPrefFor = function (userId) {
    try {
      var raw = localStorage.getItem('kt_view_as_pref');
      if (!raw) { return null; }
      var p = JSON.parse(raw);
      if (!p || String(p.uid) !== String(userId)) { return null; }

      return typeof p.role === 'string' ? p.role : null;
    } catch (e) { return null; }
  };

  window.ktViewAs = function (role) {
    try { if (role) sessionStorage.setItem('kt_view_as', role); else sessionStorage.removeItem('kt_view_as'); } catch (e) {}
    window.ktViewAsPrefSave(role);
    // Drop the current hash so the new role lands on ITS home screen (e.g. a
    // parent's "today") instead of the previous role's page or a bogus #dashboard.
    try { history.replaceState(null, '', location.pathname + location.search); } catch (e) {}
    location.reload();
  };
  /* THE FOOT OF THE SIDEBAR — one container, one order.

     Three files mount a control under the user's name (the role preview here, the
     agency switcher, and the impersonation launcher), at three different moments:
     boot, after an async agencies fetch, and DOMContentLoaded. Three independent
     insertBefore calls against the same anchor produce an order that depends on
     network timing, and it was how one of them ended up above the name.

     Each control claims a numbered slot and the container keeps its children sorted,
     so the result is identical whatever sequence they arrive in:
        10 agency selector · 20 view as (role) · 30 view as… (person)

     Defined here because this file loads before the other two. */
  /* Match the menu's own left and right insets.

     A nav link runs 16..209 inside a 231px column — NOT symmetric, because #navLinks
     reserves a scrollbar gutter on the right. That is why picking a single padding value
     by hand kept looking wrong on one side. Measured from a real link instead, so the
     foot sits in the same column as the fifty items above it, and stays there if the nav
     is restyled. */
  function alignSidebarFoot(foot) {
    var link = document.querySelector('#navLinks .nav-link');
    var sb = document.getElementById('appSidebar');
    if (!link || !sb) { return; }
    var l = link.getBoundingClientRect();
    var s = sb.getBoundingClientRect();
    var left = Math.round(l.left - s.left);
    var right = Math.round(s.right - l.right);
    // Sanity: a hidden or collapsed nav measures nonsense; keep the fallback padding.
    if (!(l.width > 40) || left < 0 || right < 0 || left > 60 || right > 60) { return; }
    foot.style.paddingLeft = left + 'px';
    foot.style.paddingRight = right + 'px';
  }

  window.KT = window.KT || {};
  window.KT.sidebarFoot = function (slot, el) {
    var sb = document.getElementById('appSidebar');
    var nu = document.getElementById('navUser');
    if (!sb || !nu || nu.parentNode !== sb) { return null; }

    var foot = document.getElementById('kt-sidebar-foot');
    if (!foot) {
      foot = document.createElement('div');
      foot.id = 'kt-sidebar-foot';
      /* The container owns the spacing. Its three controls come from three files that
         had never been neighbours and each brought its own margins, giving three
         widths and three left edges — close enough to read as a mistake.

         width:100% is load-bearing, not belt-and-braces: without it the foot is sized
         by its CONTENT and measured 234px inside a 232px sidebar, hanging off both
         edges and taking the controls with it. */
      foot.style.cssText = 'flex-shrink:0;display:flex;flex-direction:column;gap:8px;'
        + 'width:100%;box-sizing:border-box;align-self:stretch;padding:0 10px 10px;';
      nu.insertAdjacentElement('afterend', foot);
    }
    if (!el) { return foot; }

    el.setAttribute('data-foot-slot', String(slot));
    // Insert before the first child with a HIGHER slot; otherwise append.
    var kids = [].slice.call(foot.children);
    var before = null;
    for (var i = 0; i < kids.length; i++) {
      if (Number(kids[i].getAttribute('data-foot-slot') || 0) > Number(slot)) { before = kids[i]; break; }
    }
    /* Normalised on the way in, so a control keeps whatever look it likes but not its
       own idea of how wide it should be. A fourth one added later lines up for free. */
    el.style.margin = '0';
    el.style.width = '100%';
    el.style.boxSizing = 'border-box';

    if (before) { foot.insertBefore(el, before); } else { foot.appendChild(el); }

    /* Now, and again next frame: the foot can be built before #navLinks has rendered
       its items, and there is nothing to measure until it has. */
    alignSidebarFoot(foot);
    requestAnimationFrame(function () { alignSidebarFoot(foot); });

    return foot;
  };

  function _injectViewAs(user) {
    if (!user || !Array.isArray(user.roles) || user.roles.indexOf('platform_admin') === -1) return;
    var cur = ''; try { cur = sessionStorage.getItem('kt_view_as') || ''; } catch (e) {}
    var old = document.getElementById('kt-view-as'); if (old) old.remove();
    var roles = [['', 'Super admin'], ['agency_admin', '\uD83C\uDFE2 Agency admin'], ['centre_director', '\uD83C\uDFEB Centre director'], ['educator', '\uD83C\uDF93 Educator'], ['guardian', '\uD83D\uDC6A Parent / guardian'], ['home_visitor', '\uD83C\uDFE1 Home visitor'], ['sales_rep', '\uD83D\uDCBC Sales rep'], ['auditor', '\uD83D\uDD0D Auditor']];
    var wrap = document.createElement('div'); wrap.id = 'kt-view-as';
    // Embedded in the sidebar (not a floating overlay) so it never blocks content.
    /* Quiet when it is doing nothing, LOUD when it is. Sitting in the sidebar foot
       between the agency switcher and the person picker, a permanent dark slab read as
       an alert for a control that is idle 99% of the time. Idle now matches its two
       neighbours; the purple stays for when a role IS being previewed, which is exactly
       when it should be impossible to miss. */
    wrap.style.cssText = 'margin:0 10px 8px;border-radius:8px;padding:4px 10px;font-size:12px;'
      + 'display:flex;align-items:center;gap:8px;font-family:inherit;flex-wrap:nowrap;min-height:40px;'
      + (cur
          ? 'background:#7C3AED;color:#fff;border:1px solid #7C3AED;'
          : 'background:rgba(31,96,128,0.06);color:#1F6080;border:1px solid rgba(31,96,128,0.18);');
    var lab = document.createElement('span'); lab.textContent = cur ? '\uD83D\uDC41 Viewing as' : '\uD83D\uDC41 View as'; lab.style.cssText = 'font-weight:700;white-space:nowrap';
    var sel = document.createElement('select');
    /* The select keeps the portal's standard size: kt-polish-v22.css sets its padding
       and font with !important on every select, so anything set here is overruled —
       an earlier attempt to shrink it to 21px applied inline and changed nothing.
       min-width:0 IS load-bearing: without it the select will not shrink and the row
       wraps onto two lines in a narrow sidebar. */
    sel.style.cssText = 'border-radius:7px;font-family:inherit;cursor:pointer;'
      + 'flex:1;min-width:0;max-width:100%;';
    roles.forEach(function (r) { var o = document.createElement('option'); o.value = r[0]; o.textContent = r[1]; if (r[0] === cur) o.selected = true; sel.appendChild(o); });
    sel.addEventListener('change', function () { window.ktViewAs(sel.value); });
    wrap.appendChild(lab); wrap.appendChild(sel);
    /* Desktop: the label sits on its own line so the picker gets the full width. Beside
       it the select was 99px and "Super admin (default)" read "Supe..." (2026-10-16 sweep);
       every role fits at full width. Phones keep their own layout (kt-mobile-app.css). */
    if (!document.getElementById('kt-view-as-fit')) {
      var st = document.createElement('style'); st.id = 'kt-view-as-fit';
      st.textContent = '@media (min-width:769px){#kt-view-as{flex-wrap:wrap !important;row-gap:4px;padding-top:6px !important;padding-bottom:7px !important}'
        + '#kt-view-as>span{flex:1 1 100%}#kt-view-as>select{flex:1 1 100% !important;width:100%}}';
      document.head.appendChild(st);
    }
    var _sb = document.getElementById('appSidebar');
    // Slot 20: below the name and the agency selector, above the person picker.
    if (_sb && window.KT && KT.sidebarFoot && KT.sidebarFoot(20, wrap)) { /* placed */ }
    else if (_sb) { _sb.appendChild(wrap); }
    else { wrap.style.position = 'fixed'; wrap.style.left = '14px'; wrap.style.bottom = '14px'; wrap.style.zIndex = '9000'; document.body.appendChild(wrap); }
    try { _injectAdminCtlToggle(cur); } catch (e) {}
  }

  /* COLLAPSIBLE SUPER-ADMIN CONTROLS (2026-10-16). Anthony: "add ability to collapse top bar
     where it shows view as and change agency type for super admin in mobile view and
     desktop". On a phone the View-as picker and the agency switcher take a whole row of the
     header; on desktop two boxes of the sidebar foot. Collapsed, they fold into one chip
     that says what is selected ("👁 Super admin · 🏢 Test Agency ▸"), purple while a role is
     being previewed so that is never hidden. Remembered per device (localStorage), and the
     same class drives the desktop sidebar and the phone header. */
  var ADMINCTL_KEY = 'kt_adminctl_collapsed';
  function _injectAdminCtlToggle(viewingAs) {
    var old = document.getElementById('kt-adminctl'); if (old) old.remove();
    var collapsed = false; try { collapsed = localStorage.getItem(ADMINCTL_KEY) === '1'; } catch (e) {}
    if (!document.getElementById('kt-adminctl-style')) {
      var st = document.createElement('style'); st.id = 'kt-adminctl-style';
      // ...and the "View as…" button with them (2026-10-23); the orange bar shown WHILE viewing as stays.
      st.textContent = 'body.kt-adminctl-collapsed #kt-view-as,body.kt-adminctl-collapsed #kt-agency-switcher,body.kt-adminctl-collapsed #kt-imp-fab,'
        + 'body.kt-adminctl-collapsed .kt-tb-selectors{display:none !important;}'
        + '#kt-adminctl{display:flex;align-items:center;gap:6px;font-family:inherit;}'
        + '#kt-adminctl .kt-ac-chip{flex:1 1 auto;min-width:0;display:flex;align-items:center;gap:6px;height:auto;padding:6px 10px;'
        + 'border-radius:8px;border:1px solid rgba(31,96,128,.18);background:rgba(31,96,128,.06);color:#1F6080;font-weight:700;font-size:12px;line-height:1;'
        + 'font-family:inherit;cursor:pointer;min-height:32px !important;text-align:left;}'
        + '#kt-adminctl .kt-ac-chip.on{background:#7C3AED;border-color:#7C3AED;color:#fff;}'
        + '#kt-adminctl .kt-ac-chip span{white-space:normal;overflow-wrap:anywhere;line-height:1.3;min-width:0;}'
        /* Hidden = gone, with one small link to bring them back (Anthony, 2026-10-23:
           "completely hide and only show a link to restore the controls"). */
        + '#kt-adminctl .kt-ac-link{background:none;border:0;padding:3px 0;margin:0;color:#A0AEC0;font-weight:600;font-size:11.5px;line-height:1.3;'
        + 'font-family:inherit;cursor:pointer;min-height:0 !important;text-align:left;text-decoration:none;box-shadow:none;}'
        + '#kt-adminctl .kt-ac-link:hover,#kt-adminctl .kt-ac-link:focus-visible{color:#64748B;text-decoration:underline;text-underline-offset:2px;}'
        + '#kt-adminctl .kt-ac-link .kt-ac-va{display:inline-block;margin-left:6px;padding:1px 7px;border-radius:999px;background:#7C3AED;color:#fff;text-decoration:none;font-size:10.5px;}'
        + '#kt-adminctl .kt-ac-hide{margin-left:auto;background:none;border:0;color:#64748B;font-weight:700;font-size:11px;line-height:1;font-family:inherit;'
        + 'cursor:pointer;padding:4px 2px;min-height:0 !important;}'
        + 'body.kt-sidebar-collapsed #kt-adminctl{display:none !important;}'
        + '@media (max-width:768px){#appSidebar #kt-adminctl{order:3 !important;flex:1 1 100% !important;}'
        + '#appSidebar #kt-view-as{order:4 !important;}#appSidebar #kt-agency-switcher{order:5 !important;}'
        + '#kt-sidebar-foot > #kt-adminctl{flex:1 1 100% !important;width:auto !important;}}';
      document.head.appendChild(st);
    }
    var box = document.createElement('div'); box.id = 'kt-adminctl';
    function agencyName() {
      var b = document.querySelector('#kt-agency-switcher button');
      return b ? b.textContent.replace(/\u{1F3E2}|▾|PLAT/gu, '').replace(/\s+/g, ' ').trim() : '';
    }
    function roleName() {
      var s = document.querySelector('#kt-view-as select');
      return s && s.selectedIndex >= 0 ? s.options[s.selectedIndex].text.replace(/^\W+\s*/u, '') : 'Super admin';
    }
    function paint() {
      document.body.classList.toggle('kt-adminctl-collapsed', collapsed);
      box.innerHTML = '';
      if (collapsed) {
        /* Only a link. While a role is being previewed it says so (purple), so hiding the
           controls can never hide the fact that this is not your own view. */
        var link = document.createElement('button');
        link.type = 'button'; link.className = 'kt-ac-link';
        link.setAttribute('data-kt-iconized', '1'); link.setAttribute('aria-expanded', 'false');
        link.title = 'Show View as and the agency switcher';
        link.innerHTML = '▸ Show admin controls' + (viewingAs ? '<span class="kt-ac-va">Viewing as ' + _escAC(roleName()) + '</span>' : '');
        link.addEventListener('click', function () { collapsed = false; save(); paint(); acShown(); });
        box.appendChild(link);
      } else {
        var hide = document.createElement('button');
        hide.type = 'button'; hide.className = 'kt-ac-hide'; hide.setAttribute('data-kt-iconized', '1');
        hide.setAttribute('aria-expanded', 'true'); hide.textContent = '▾ Hide admin controls';
        hide.addEventListener('click', function () { collapsed = true; save(); paint(); });
        box.appendChild(hide);
      }
    }
    function save() { try { localStorage.setItem(ADMINCTL_KEY, collapsed ? '1' : '0'); } catch (e) {} }
    /* AUTO-COLLAPSE (2026-11-24, Anthony: "show admin controls should auto collapse after x seconds - wire this up
       for super admin"; he chose 15 seconds). Opened, the controls fold away again after 15 seconds untouched. A
       pointer, key or focus inside them (View as, the agency switcher and its menu) restarts the count, and it waits
       while one of them has focus, the pointer is over them, or a popup opened SINCE they were shown (the person
       picker) is still up. A popup that was already open (the notifications gate, What's New) does not hold them
       open - the first build waited on any open dialog and never folded behind the notifications gate. */
    var AC_IDLE_MS = 15000;
    var acZone = '#kt-adminctl,#kt-view-as,#kt-agency-switcher,.kt-tb-selectors,#kt-imp-fab';
    var inZone = function (n) { return !!(n && n.closest && n.closest(acZone)); };
    var openDialogs = function () {
      return [].slice.call(document.querySelectorAll('.modal-backdrop, .kt-modal-backdrop, [role="dialog"][aria-modal="true"]'))
        .filter(function (e) { return e.getClientRects().length > 0 && getComputedStyle(e).display !== 'none'; });
    };
    var acShown = function () { window.__ktAcPre = openDialogs(); acArm(); };
    function acArm() {
      if (window.__ktAcTimer) { clearTimeout(window.__ktAcTimer); window.__ktAcTimer = null; }
      if (collapsed) return;
      window.__ktAcTimer = setTimeout(function () {
        window.__ktAcTimer = null;
        if (collapsed || !box.isConnected) return;
        var busy = inZone(document.activeElement) || window.__ktAcHover
          || openDialogs().some(function (e) { return (window.__ktAcPre || []).indexOf(e) < 0; });
        if (busy) { acArm(); return; }
        collapsed = true; save(); paint();
      }, AC_IDLE_MS);
    }
    window.__ktAcArm = acArm;
    if (!window.__ktAcBound) {
      window.__ktAcBound = true;
      var poke = function (e) { if (inZone(e.target) && window.__ktAcArm) window.__ktAcArm(); };
      ['pointerdown', 'keydown', 'focusin', 'input', 'change'].forEach(function (ev) { document.addEventListener(ev, poke, true); });
      document.addEventListener('pointerover', function (e) { var h = inZone(e.target); if (h !== window.__ktAcHover) { window.__ktAcHover = h; if (h && window.__ktAcArm) window.__ktAcArm(); } }, true);
    }
    // Slot 5: above the agency switcher (10) and View as (20).
    if (window.KT && KT.sidebarFoot && KT.sidebarFoot(5, box)) { /* placed */ }
    else { var sb = document.getElementById('appSidebar'); if (sb) sb.appendChild(box); else return; }
    paint();
    acShown();   // left open on another visit: it folds away after 15 seconds here too
    /* The agency switcher arrives after its own fetch; keep the chip's summary current. */
    var foot = box.parentNode;
    if (foot && window.MutationObserver && !foot.__ktAcObs) {
      foot.__ktAcObs = new MutationObserver(function () {
        if (!collapsed || !viewingAs) return;
        var c = box.querySelector('.kt-ac-va'); if (!c) return;
        var want = 'Viewing as ' + roleName();
        if (c.textContent !== want) c.textContent = want;
      });
      foot.__ktAcObs.observe(foot, { childList: true, subtree: true, characterData: true });
    }
  }
  function _escAC(s) { return String(s == null ? '' : s).replace(/[&<>"]/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]; }); }

  /* ── Holding the reader's place across a same-screen re-render ─────────────
     A re-render of the screen you are already on (a live update after a write, the
     45s poll) is not navigation, and it must not cost you your position.

     Restoring on the next animation frame is not enough, and that was the bug behind
     "the view keeps jumping". Most screens return from their render function BEFORE
     their data arrives — the audit log paints a "Loading…" line and fetches — so one
     frame later the document is a few hundred pixels tall, the browser CLAMPS
     scrollTo(0, 4000) to the bottom of that short page, and when the real table lands
     the reader is somewhere they never asked to be.

     So keep re-applying the target while the page is still growing, and stop the
     moment the reader touches the scroll themselves — putting somebody back where they
     were is help, moving them while they are scrolling is a fight they always lose.
     (Anthony, 2026-08-26) */
  /* A BACKGROUND REFRESH SHOULD NOT BE VISIBLE.
     renderScreen() clears #appMain and then awaits the screen's async render, so for a
     few hundred milliseconds the page is empty and the content visibly disappears and
     comes back. Scroll and height are already pinned; the flash is what is left.

     Rendering into a detached node and swapping it in would remove the gap, but screens
     reach for document.getElementById on their own markup while rendering, and a
     detached screen silently fails that way — that is the stuck-skeleton bug.

     So the screen still renders exactly where it always did, and an inert CLONE is laid
     over the top for the duration. The reader keeps seeing the old screen until the new
     one is ready, then it is uncovered in one frame. The clone is pointer-events:none, so
     it is purely something to look at — clicks go to the real DOM underneath. */
  /* WHOSE COVER IS IT? (2026-09-17)

     One cover element, and any number of renders that might take it away. A render that
     is superseded must not pull the cover off the screen that replaced it, and the
     render that put it up must not find it already gone. So the cover is stamped with
     the generation that raised it, and a drop that names a generation is ignored unless
     it owns the thing it is dropping. The failsafe and the early-exit paths drop
     unconditionally, which is right: at that point nobody is going to. */
  function __ktSnapshot(main, gen) {
    try {
      __ktDropSnapshot();
      var r = main.getBoundingClientRect();
      if (!r || r.width < 2 || r.height < 2) { return null; }
      /* Where the live top bar is, before the clone is made (see below). */
      var _barTop = null;
      try {
        for (var _bi = 0; _bi < main.children.length; _bi++) {
          if (main.children[_bi].id === 'kt-topbar') { _barTop = main.children[_bi].getBoundingClientRect().top; break; }
        }
      } catch (e) {}
      /* EVERY STICKY THING, not just the bar (2026-10-26): where each one is on screen right now, by its
         index in the subtree, so its copy can be put back on the same spot below. */
      var _stk = [];
      try {
        var _la = main.querySelectorAll('*');
        if (_la.length <= 6000) {
          for (var _li = 0; _li < _la.length; _li++) {
            var _le = _la[_li];
            if (_le.id === 'kt-topbar') { continue; }
            if (getComputedStyle(_le).position === 'sticky') { _stk.push([_li, _le.getBoundingClientRect().top]); }
          }
        }
      } catch (e) { _stk = []; }
      var snap = main.cloneNode(true);
      /* THE COVER KEEPS THE NAME #appMain (2026-09-29).

         Anthony's APK video: after signing in, the launcher showed its round icons, then
         for half a second big gradient CARDS, then the round icons again. The cards were
         this clone. It used to be renamed kt-refresh-snap, and 730 rules across the
         stylesheets are written against #appMain - the launcher's round-icon look among
         them (kt-mobile-app.css strips each tile's card background under #appMain). The
         clone lost every one of them and fell back to the base card styling, so the
         "picture of the screen" was a picture of a different screen.

         Nearly all of those rules are bare #appMain or keyed on html/body classes, which a
         clone appended to <body> still matches, so keeping the id makes the clone render
         as the screen does. It is appended AFTER the live #appMain, so getElementById and
         querySelector still return the live node. It is found by data-kt-refresh-snap.
         Its geometry is inline !important, because #appMain rules set padding and
         position with !important and would otherwise move it. */
      snap.id = 'appMain';
      snap.setAttribute('data-kt-refresh-snap', '1');
      // Not a dialog: the modal guard must not give it a scroll box and a × (see kt-modal-guard).
      snap.setAttribute('data-no-modal-guard', '1');
      snap.setAttribute('aria-hidden', 'true');
      try { snap.inert = true; } catch (e) {}
      /* THE CLONE KEEPS data-kt-pretty (2026-10-25). Anthony's video of the email log: every
         background refresh, the page "zoomed" for half a second - bolder, bigger text, wider
         tabs, browser-drawn dropdowns - then snapped back. That was this cover: dropping the
         attribute took ~250 [data-kt-pretty] rules off the picture, so it was a picture of an
         unstyled screen. It was dropped so the [data-kt-pretty] JS sweeps would leave the
         clone alone, but every one of them (bulk select, pagination, mobile labels, counters,
         tooltips, empty states) skips a node carrying its own data-kt-* marker, and the clone
         is a copy of a screen that has already been swept, markers included.

         AND THE LIVE VALUES. cloneNode copies attributes, not state: every <select> in the
         picture fell back to its first option ("Whose: Platform-level" read "All - every
         agency" under the cover), typed text vanished and charts went blank. Copied across
         pairwise below. */
      try {
        var _lf = main.querySelectorAll('input, select, textarea, canvas');
        var _sf = snap.querySelectorAll('input, select, textarea, canvas');
        if (_lf.length === _sf.length) {
          for (var _fi = 0; _fi < _lf.length; _fi++) {
            var _a = _lf[_fi], _b = _sf[_fi];
            if (_a.tagName === 'CANVAS') {
              try { _b.width = _a.width; _b.height = _a.height; _b.getContext('2d').drawImage(_a, 0, 0); } catch (e) {}
            } else if (_a.tagName === 'SELECT') {
              _b.selectedIndex = _a.selectedIndex;
            } else if (_a.type === 'checkbox' || _a.type === 'radio') {
              _b.checked = _a.checked;
            } else if (_a.type !== 'file') {
              _b.value = _a.value;
            }
          }
        }
      } catch (e) {}
      var _mcs = getComputedStyle(main);
      var _bg = _mcs.backgroundColor || '#fff';
      snap.style.cssText = '';
      /* THE SAME BOX, PADDING INCLUDED (2026-10-06). Dropping data-kt-pretty (undone
         2026-10-25, see above) also dropped the
         rules that give #appMain its compact 12px padding, so the clone fell back to 28px:
         everything in the picture — the top bar first — sat 16px lower than the live page
         (bar at y=34 under the cover, y=12 live) and jumped up when the cover came off.
         Copy the live node's computed padding, as its position and size already are. */
      [['position', 'fixed'], ['left', r.left + 'px'], ['top', r.top + 'px'], ['right', 'auto'], ['bottom', 'auto'],
       ['width', r.width + 'px'], ['height', r.height + 'px'], ['max-width', 'none'], ['max-height', 'none'],
       ['margin', '0'], ['transform', 'none'], ['overflow', 'hidden'], ['pointer-events', 'none'],
       ['padding-top', _mcs.paddingTop], ['padding-right', _mcs.paddingRight],
       ['padding-bottom', _mcs.paddingBottom], ['padding-left', _mcs.paddingLeft], ['box-sizing', _mcs.boxSizing],
       ['z-index', '300'], ['visibility', 'visible'], ['opacity', '1'], ['background', _bg]].forEach(function (p) {
        snap.style.setProperty(p[0], p[1], 'important');
      });
      document.body.appendChild(snap);
      /* A PICTURE DOES NOT ANIMATE IN (2026-10-06). The clone is a new node, so every
         entrance fade on it (#appMain > *, the banners) replayed from .6 the moment it
         went up: the "frozen" screen visibly flashed pale over the live one during each
         background refresh. Pinned exactly as the live screen is - entrance fades already
         over, the banner shimmer at the same point in its loop. */
      try { __ktPinAnimations(snap); } catch (e) {}
      /* THE PICTURE OF THE BAR GOES WHERE THE BAR IS (2026-10-06). The bar is
         position:sticky, and inside the clone it sticks to the CLONE (an overflow:hidden
         box), not to the page — so it sat 6px off the live bar and moved when the cover
         came off. Measured, not modelled: whatever layout the page is in (window scroll on
         desktop, #appMain scroll on a phone, stuck or not), pin the copy to the live
         bar's position as it was the moment the picture was taken. */
      try {
        if (_barTop != null) {
          var _sb = null;
          for (var _sj = 0; _sj < snap.children.length; _sj++) { if (snap.children[_sj].id === 'kt-topbar') { _sb = snap.children[_sj]; break; } }
          if (_sb) {
            _sb.style.setProperty('position', 'relative', 'important');
            _sb.style.setProperty('top', '0px', 'important');
            var _d = _barTop - _sb.getBoundingClientRect().top;
            if (Math.abs(_d) > 0.5) { _sb.style.setProperty('top', _d + 'px', 'important'); }
          }
        }
      } catch (e) {}
      /* The same for every other sticky element (a bottom save bar, a table's sticky header): inside the clone
         they stick to the clone and land somewhere else - Educator rooms' save bar dropped out of view for the
         whole refresh. Measured live above; moved here. */
      try {
        if (_stk.length) {
          var _sa = snap.querySelectorAll('*');
          _stk.forEach(function (it) {
            var c = _sa[it[0]];
            if (!c) { return; }
            c.style.setProperty('position', 'relative', 'important');
            c.style.setProperty('top', '0px', 'important');
            c.style.setProperty('bottom', 'auto', 'important');
            var dd = it[1] - c.getBoundingClientRect().top;
            if (Math.abs(dd) > 0.5) { c.style.setProperty('top', dd + 'px', 'important'); }
          });
        }
      } catch (e) {}
      window.__ktSnapGen = (gen == null ? null : gen);
      /* AND HIDE WHAT IS UNDERNEATH IT (2026-09-17).

         The clone is a picture of #appMain pinned at the rect #appMain occupied when it
         was taken. The live screen then rebuilds underneath — and it does not land in
         exactly the same place: the top bar is re-inserted, a banner is inserted and
         then normalised, the content is a different height. Anything that ends up
         outside the clone's rect is not covered by it, so the reader sees the frozen
         banner AND the new banner a few dozen pixels below it, at once. Anthony
         photographed precisely that on the audit log: the same hero twice, the upper one
         clipped mid-sentence by the clone's overflow:hidden, "Loading audit log…"
         underneath.

         A cover that only covers what used to be there is not a cover. `visibility` —
         not `display` — because layout must be preserved: screens measure their own
         markup while rendering, and a display:none column measures zero. Restored in
         __ktDropSnapshot, which every exit path goes through. */
      try { main.style.visibility = 'hidden'; } catch (e) {}
      // Mobile scrolls #appMain itself, so an unscrolled clone would show the top of the
      // page while the reader is halfway down it.
      try { snap.scrollTop = main.scrollTop; } catch (e) {}
      /* A render that throws must never leave the page frozen behind a picture of
         itself. This fires regardless of what the render does. */
      /* Must outlast __ktWaitSettled's own ceiling, or the failsafe uncovers the
         screen in the middle of the wait it was meant to back up. */
      window.__ktSnapKill = setTimeout(__ktDropSnapshot, SNAP_KILL_MS);
      return snap;
    } catch (e) { return null; }
  }

  /* How long the cover may hold, and how long entrance animations stay suppressed.
     The second MUST be longer than the first: a suppression that expires while the
     screen is still covered is a re-render the reader watches happen. */
  var SNAP_KILL_MS = 7000;
  var SILENT_WINDOW_MS = SNAP_KILL_MS + 1500;

  function __ktDropSnapshot(gen) {
    // Named a generation and it is not ours any more: a newer render owns this cover.
    try {
      if (gen != null && window.__ktSnapGen != null && window.__ktSnapGen !== gen) { return; }
    } catch (e) {}
    try { if (window.__ktSnapKill) { clearTimeout(window.__ktSnapKill); window.__ktSnapKill = null; } } catch (e) {}
    try {
      var old = document.querySelector('[data-kt-refresh-snap]');
      if (old && old.parentNode) { old.parentNode.removeChild(old); }
      window.__ktSnapGen = null;
      /* A GRACE ON THE WAY OUT. A screen that finishes painting on the same frame the
         cover is removed would otherwise animate in full view — the cover did its job
         right up to the moment it stopped. Only ever extends, never shortens, so a
         navigation that legitimately wants its animations is unaffected: it sets the
         window to 0 and nothing here raises it. */
      if (window.__ktSilentUntil) {
        window.__ktSilentUntil = Math.max(window.__ktSilentUntil, Date.now() + 800);
      }
    } catch (e) {}
    /* ALWAYS, and outside the try above: if the clone is gone and #appMain is still
       hidden, the reader is looking at an empty page. Every failure path in this file
       ends here, which is the point — there is one place that can un-hide the screen and
       it runs even when the removal above throws. */
    try {
      var m = document.getElementById('appMain');
      if (m && m.style.visibility === 'hidden') { m.style.visibility = ''; }
    } catch (e) {}
  }

  /* WHEN IS A SCREEN ACTUALLY FINISHED?

     The cover above was dropped as soon as fn() returned, and that is the wrong moment.
     Almost no screen in this portal finishes inside its render function: the usual shape is

         Dom.clear(listWrap);
         listWrap.appendChild(el('div', 'Loading audit log...'));
         Api.get('/admin/audit-logs?...').then(function (data) { ...build the table... });

     — the function returns at once, with a "Loading..." line on screen. So the reader was
     shown the old page for two frames, then the loading placeholder for as long as the
     request took, then the new page. The cover was hiding the only part that was never
     visible anyway, and the flash it was written to remove was still there in full.
     (Anthony, 2026-09-15: "the screen flashes as it appears its refreshing".)

     Settled means all three:
       - nothing this render started is still in flight (KT.Api counts, window.__ktInflight)
       - #appMain has stopped changing for QUIET_MS — this catches screens with their own
         fetch helper, and the global sweeps (search/sort, mobile tables, icon buttons)
         that rearrange a table right after it appears
       - MAX_HOLD_MS has not elapsed; a slow or broken screen must never stay covered

     And it ends immediately if the reader touches anything. The cover is inert
     (pointer-events:none) but it is a still picture, so a scroll would slide the page out
     from under it. Whoever is interacting gets the real screen, loading line and all. */
  var __KT_QUIET_MS = 160;      // no mutation for this long = the screen has stopped moving
  var __KT_REQ_GRACE_MS = 140;  // after the last request lands, long enough for its .then()
  /* 2600 until 2026-09-25. On a phone at the morning peak a request routinely takes
     longer than that, so the cover came off over a "Loading…" stub, the scroll had
     already been clamped to that stub's height, and the reader watched the page jump
     to the top and back. The cover is a picture of real (slightly old) content and it
     ends the moment anybody touches the screen, so holding it longer costs nothing. */
  var __KT_MAX_HOLD_MS = 5000;  // ceiling; a slow screen uncovers rather than freezing

  /* A REBUILT ELEMENT RESTARTS ITS ANIMATIONS, AND THAT IS THE FLASH THAT WAS LEFT.

     Anthony, 2026-09-15: "the only thing that messes up is the hero banner reloads and
     affects the screen." Measured on #audit-logs across one silent refresh:

       before   kt-hero-breathe currentTime 6033ms   background-position 87.5% 50%
       after    kt-hero-breathe currentTime 2967ms   background-position 28.6% 50%

     kt-hero-breathe is a 16s infinite gradient sweep on every .kt-hero / .kt-page-hero /
     .page-header-v17 (kt-consistency-polish.css). Dom.clear() destroys the banner and the
     screen builds a new one, so the sweep starts again at 0% — the gradient SNAPS across
     the full width of the banner. Nothing was reloading; the animation was being reborn.
     The 0.12s kt-fade-in on #appMain > * replays for the same reason.

     Covering it is not the fix. The cover comes off eventually, and if the phase is wrong
     underneath, the jump is simply deferred to the moment of the uncover.

     So carry the phase across the rebuild. An animation's position is its elapsed time
     modulo its duration, and a NEGATIVE animation-delay is how you say "start it already
     that far in" — so the new element picks up exactly where the old one left off and the
     gradient never moves. A one-shot entry animation is landed as already finished, which
     is the honest thing for a screen that never went away.

     Read from getComputedStyle rather than hard-coding the two current animations, so a
     banner that gains a third keeps working. Silent refresh only: navigation SHOULD look
     like something happened. */
  var __KT_ANIM_EPOCH = Date.now();

  function __ktSeconds(v) {
    v = String(v || '').trim();
    if (!v) { return 0; }
    var n = parseFloat(v);
    if (!isFinite(n)) { return 0; }
    return /ms$/.test(v) ? n / 1000 : n;
  }

  function __ktPinAnimations(main) {
    try {
      var elapsed = (Date.now() - __KT_ANIM_EPOCH) / 1000;
      var seen = [];
      var targets = [];
      try {
        var banners = main.querySelectorAll('.kt-hero, .kt-page-hero, .page-header-v17, .kt-banner-fx');
        for (var b = 0; b < banners.length; b++) { targets.push(banners[b]); }
      } catch (e) {}
      // #appMain > * carries the 0.12s entry fade. It normally finishes under the cover,
      // but a screen that renders straight from memory can uncover inside that window.
      for (var c = 0; c < main.children.length; c++) { targets.push(main.children[c]); }

      for (var i = 0; i < targets.length; i++) {
        var el = targets[i];
        if (seen.indexOf(el) !== -1) { continue; }
        seen.push(el);
        var cs = null;
        try { cs = getComputedStyle(el); } catch (e) { continue; }
        if (!cs) { continue; }
        var names = String(cs.animationName || '').split(',');
        if (!names.length || names[0].trim() === '' || names[0].trim() === 'none') { continue; }
        var durs = String(cs.animationDuration || '').split(',');
        var iters = String(cs.animationIterationCount || '').split(',');
        var delays = [];
        for (var k = 0; k < names.length; k++) {
          var dur = __ktSeconds(durs[k % durs.length]);
          var infinite = String(iters[k % iters.length] || '').trim() === 'infinite';
          if (infinite && dur > 0) {
            delays.push('-' + (elapsed % dur).toFixed(3) + 's');   // same point in the loop
          } else {
            delays.push('-' + (dur + 1).toFixed(3) + 's');         // already over
          }
        }
        // Inline !important: the stylesheet declares `animation: ... !important`, and an
        // inline important declaration is the only thing that outranks it.
        el.style.setProperty('animation-delay', delays.join(', '), 'important');
      }
    } catch (e) {}
  }

  /* See "A NEW SCREEN APPEARS ONCE, FINISHED" in renderScreenOnce. */
  var NAV_REVEAL_MS = 800;
  var NAV_REVEAL_FAILSAFE_MS = 1200;
  /* A PHONE ANSWERS THE TAP AT ONCE (2026-10-28, Anthony: "with mobile when moving around from sections and from the
     tool bars - its slow and not instant and very poor experience - full sweep and fix this once and for all").
     Measured at phone speed: the old screen stayed up 0.6-2.7s after a tap while the new one "settled" behind it
     (the 800ms cap is counted in a main thread that is busy building the screen, so it stretched). On a phone the new
     screen now shows within 350ms, finished if it is quick, otherwise with its own loading state; desktop keeps 800. */
  try {
    if (window.matchMedia && window.matchMedia('(max-width:768px)').matches) { NAV_REVEAL_MS = 350; NAV_REVEAL_FAILSAFE_MS = 600; }
  } catch (e) {}
  var NAV_REVEAL_HANG_MS = 6000;
  function __ktStampSettled() {
    try {
      if (!window.__ktScreenSettledAt) { window.__ktScreenSettledAt = Date.now(); }
      document.dispatchEvent(new CustomEvent('kt:screen-settled'));
    } catch (e) {}
  }
  function __ktRevealStyle() {
    if (document.getElementById('kt-reveal-style')) { return; }
    try {
      var st = document.createElement('style');
      st.id = 'kt-reveal-style';
      st.textContent = '#appMain[data-kt-revealing] > :not(#kt-topbar){opacity:0 !important;}'
        + '#appMain.kt-revealing-in > :not(#kt-topbar){transition:opacity .16s ease-out;}'
        /* A PHONE SCREEN ARRIVES ONCE (2026-10-28 PM, Anthony: "its jumps and its not smooth at all ... add
           guards"). Measured: after the cross-fade showed the new screen in full, kt-screen-in (a 0 -> 1 slide) ran
           AGAIN from opacity 0, because it had only been suspended by classes that were removed later - and
           taking animation:none away starts an animation from its first frame. A screen that has been revealed
           carries data-kt-nav-shown for the rest of its life (each render gets a new #appMain), and under it no
           entrance animation of any kind can run: not kt-screen-in, not kt-enter-soft on its blocks, and the
           banner keeps only its slow shimmer. The doubled [attribute] out-ranks #appMain > .kt-screen-in. */
        + '#appMain[data-kt-nav-shown][data-kt-nav-shown] > *{animation:none !important;}'
        + '#appMain[data-kt-nav-shown][data-kt-nav-shown] .kt-banner-fx{animation:kt-hero-breathe 16s ease-in-out infinite !important;}'
        /* The one entrance: 140ms from .82, after the old screen is gone (never both at once, never from blank).
           transition:none so the reveal's opacity 0 -> 1 does not also run as a transition, which out-ranks
           animations in the cascade. */
        + '@keyframes ktNavIn{from{opacity:.82}to{opacity:1}}'
        + '#appMain[data-kt-nav-shown].kt-nav-in > :not(#kt-topbar){animation:ktNavIn .14s ease-out both !important;transition:none !important;}'
        // The copy is a still picture: nothing in it moves, and it is never faded - it is removed in one frame.
        + '#appMain[data-kt-nav-cover], #appMain[data-kt-nav-cover] *{animation:none !important;transition:none !important;}';
      document.head.appendChild(st);
    } catch (e) {}
  }

  /* ── THE CROSS-FADE ON A PHONE (2026-10-28, Anthony: "on mobile when moving from one section to the next etc
     needs to be smooth transition or a fade - full sweep"). Measured: the tap emptied the screen (a fresh #appMain),
     the new screen was held invisible until it settled (data-kt-revealing, up to NAV_REVEAL_MS), then appeared -
     a white page in between, every time. The cover is a copy of the old screen, fixed exactly where it was, that
     dims a little at once (the tap is acknowledged) and fades away when _ktReveal shows the new screen, which
     fades in underneath it: one screen dissolving into the next, never a blank. Phones only (<=768px); it can
     never outlive NAV_REVEAL_HANG_MS, takes no taps, and a newer navigation replaces it. */
  var __ktNavCoverEl = null, __ktNavCoverKill = null;
  function __ktNavCoverDrop(now) {
    var c = __ktNavCoverEl;
    __ktNavCoverEl = null;
    try { clearTimeout(__ktNavCoverKill); } catch (e) {}
    if (!c) { return; }
    /* Always in one frame (2026-10-28 PM): fading it out over the new screen was the double exposure. */
    try { c.remove(); } catch (e) {}
  }
  function __ktNavCover(main) {
    __ktNavCoverDrop(true);
    try {
      if (window.__ktNoNavFade || !window.matchMedia || !window.matchMedia('(max-width:768px)').matches) { return; }
      var r = main.getBoundingClientRect();
      if (!r || r.width < 50 || r.height < 50) { return; }
      if (main.getElementsByTagName('*').length > 8000) { return; }   // a giant page: not worth the copy
      var top = main.scrollTop || 0;
      /* ONLY WHAT IS ON SCREEN (2026-10-28, PERF). The cover was a deep copy of the WHOLE old screen - a long list
         or table meant thousands of new nodes for every page-wide watcher to walk, on the tap itself. Blocks that are
         off screen are copied as empty spacers of the same height, so what you see is identical and positions hold. */
      var c = main.cloneNode(false);
      var vb = r.bottom + 40, vt = r.top - 40;
      for (var ci = 0; ci < main.children.length; ci++) {
        var ch = main.children[ci], cr = ch.getBoundingClientRect();
        if (cr.bottom < vt || cr.top > vb) {
          var sp = document.createElement('div');
          sp.style.cssText = 'height:' + ch.offsetHeight + 'px;margin:0;padding:0;';
          c.appendChild(sp);
        } else {
          c.appendChild(ch.cloneNode(true));
        }
      }
      /* KEEPS the id, like the refresh cover (__ktSnapshot): hundreds of rules are written against #appMain, and
         without it the copy would render as a different screen. It is appended after the live node, so
         getElementById / querySelector still find the live one first. */
      __ktRevealStyle();
      c.setAttribute('data-kt-nav-cover', '1');
      /* No live id inside the picture that a script looks up while the real one is mid-render: the clock strip's
         copy was found by getElementById and moved onto the screen with no click handler (2026-10-28). */
      try { [].forEach.call(c.querySelectorAll('#kt-clockpill'), function (n) { n.removeAttribute('id'); n.setAttribute('data-kt-cover-copy', 'kt-clockpill'); }); } catch (e) {}
      // The decorators (tooltips, modal guard, overflow guard, top bar) already leave a refresh cover alone.
      c.setAttribute('data-kt-refresh-snap', 'nav');
      c.setAttribute('aria-hidden', 'true');
      c.removeAttribute('data-kt-revealing');
      var cs = getComputedStyle(main);
      c.style.cssText = 'position:fixed;left:' + r.left + 'px;top:' + r.top + 'px;width:' + r.width + 'px;height:' + r.height + 'px;'
        + 'margin:0;overflow:hidden;pointer-events:none;z-index:40;box-sizing:border-box;'
        + 'padding:' + cs.padding + ';background:' + (cs.backgroundColor && cs.backgroundColor !== 'rgba(0, 0, 0, 0)' ? cs.backgroundColor : 'var(--kt-page-bg,#F4F7FA)') + ';'
        + 'opacity:1;will-change:opacity;';
      document.body.appendChild(c);
      try { c.scrollTop = top; } catch (e) {}
      __ktNavCoverEl = c;
      /* Not dimmed (2026-10-28 PM): the dim held the old screen at 72% while the next loaded - the washed-out,
         laggy look. The tap is acknowledged by the bottom bar's own highlight. */
      __ktNavCoverKill = setTimeout(function () { if (__ktNavCoverEl === c) { __ktNavCoverDrop(); } }, NAV_REVEAL_HANG_MS);
    } catch (e) { __ktNavCoverDrop(true); }
  }
  // A touch, a scroll or the Back key while the cover is up: show the real screen now.
  try {
    ['touchstart', 'wheel', 'keydown'].forEach(function (t) {
      window.addEventListener(t, function () { if (__ktNavCoverEl) { __ktNavCoverDrop(); } }, { passive: true, capture: true });
    });
  } catch (e) {}

  function __ktWaitSettled(main, done, maxMs, screenOnly) {
    var holdMs = maxMs || __KT_MAX_HOLD_MS;
    var finished = false;
    var started = Date.now();
    var lastMut = started;
    /* WHICH SIGNAL DECIDES.
       DOM quiet alone never arrives on most screens: renderScreen schedules seven
       deferred banner passes out to 7.5s plus a MutationObserver, and each one touches
       #appMain, so the quiet timer resets forever and every refresh ran to the ceiling.
       The cover then sat there showing a picture that was already a second out of date.

       So when the screen fetched anything — which is nearly all of them — the request is
       the signal: settled once nothing is outstanding and its .then() has had a moment to
       paint. DOM quiet is the fallback for a screen that renders purely from memory and
       therefore issues no request at all. */
    var sawRequest = false;
    var lastBusy = started;
    var obs = null;
    var iv = null;
    var EVENTS = ['wheel', 'touchstart', 'pointerdown', 'keydown', 'mousedown'];
    var opts = { passive: true, capture: true };

    function cleanup() {
      try { if (obs) obs.disconnect(); } catch (e) {}
      try { if (iv) clearInterval(iv); } catch (e) {}
      EVENTS.forEach(function (ev) {
        try { window.removeEventListener(ev, finish, opts); } catch (e) {}
      });
    }
    /* done(capped): capped === true when the ceiling ended the wait rather than the
       screen settling - callers that only want a GENUINE settle can tell the two apart. */
    function finish(capped) {
      if (finished) { return; }
      finished = true;
      cleanup();
      try { done(capped === true); } catch (e) {}
    }

    try {
      obs = new MutationObserver(function () { lastMut = Date.now(); });
      obs.observe(main, { childList: true, subtree: true, characterData: true, attributes: true });
    } catch (e) { obs = null; }

    EVENTS.forEach(function (ev) {
      try { window.addEventListener(ev, finish, opts); } catch (e) {}
    });

    iv = setInterval(function () {
      var now = Date.now();
      if (now - started >= holdMs) { finish(true); return; }
      var pending = 0;
      /* screenOnly: count only requests made from a screen file (app.js __ktScreenInflight), not the
         background modules that keep calling for seconds after boot. Used by the phone boot cover. */
      try { pending = (screenOnly ? window.__ktScreenInflight : window.__ktInflight) || 0; } catch (e) {}
      if (pending > 0) { sawRequest = true; lastBusy = now; return; }
      if (sawRequest) {
        if (now - lastBusy >= __KT_REQ_GRACE_MS) { finish(); }
        return;
      }
      if (now - lastMut < __KT_QUIET_MS) { return; }
      finish();
    }, 50);
  }

  /* WHICH ELEMENT ACTUALLY SCROLLS.

     Everything below used to read and write window.scrollY. On a desktop browser the
     document scrolls and that is correct. ON A PHONE IT IS NOT: kt-mobile-app.css gives
     #appMain `overflow-y: auto` and makes it the one scroller, the document never moves,
     and window.scrollY is 0 for the whole session.

     So the refresh was faithfully saving 0, clearing #appMain — which resets ITS
     scrollTop to 0 — and then restoring 0. The position was never preserved at all, on
     the platform where it matters most. Every silent refresh dumped the reader at the
     top of the screen they were reading, and the machinery written to prevent exactly
     that reported success. Anthony, 2026-09-16: "when looking at reports and refresh it
     moves away from the screen".

     Asked of the COMPUTED overflow rather than of scrollHeight, because immediately
     after Dom.clear() the element is empty and does not overflow — it is still the
     scroller, and that is precisely the moment this gets consulted. */
  function __ktScrollHost() {
    try {
      var m = document.getElementById('appMain');
      if (m && /auto|scroll/.test(getComputedStyle(m).overflowY || '')) { return m; }
    } catch (e) {}
    return null;                       // null = the document scrolls
  }

  function __ktScrollPos() {
    var h = __ktScrollHost();
    if (h) { return h.scrollTop || 0; }
    return window.scrollY || (document.scrollingElement || {}).scrollTop || 0;
  }

  function __ktScrollSet(y) {
    var h = __ktScrollHost();
    if (h) { try { h.scrollTop = y; } catch (e) {} return; }
    try { window.scrollTo(0, y); } catch (e) {}
  }

  /* How far it COULD scroll — the ceiling the restore clamps to while the screen is
     still filling in. */
  function __ktScrollMax() {
    var h = __ktScrollHost();
    if (h) { return Math.max(0, h.scrollHeight - h.clientHeight); }
    var doc = document.documentElement;
    return Math.max(0, (doc ? doc.scrollHeight : 0) - window.innerHeight);
  }

  function __ktSettleScroll(y, releaseEl) {
    var tries = 0, aborted = false;
    var lastSet = -1;                      // the position WE last asked for
    function abort() { aborted = true; }
    var opts = { passive: true, capture: true };
    var EVENTS = ['wheel', 'touchstart', 'touchmove', 'keydown', 'mousedown'];
    EVENTS.forEach(function (ev) { window.addEventListener(ev, abort, opts); });

    /* The input events above only catch a scroll that STARTS after the re-render.
       A wheel or a swipe already in flight when the screen was torn down has
       already fired, so nothing aborted and the loop below spent the next second
       and a half dragging the reader back — which is what "it won't let me
       scroll" is. So also watch the result: if the page reports a position that
       is not the one we set, somebody else moved it, and they win. */
    function onScroll() {
      if (lastSet < 0) { return; }

      /* A SCROLLER THAT CANNOT YET HOLD THE POSITION HAS NOT BEEN SCROLLED BY ANYBODY.

         Dom.clear(#appMain) empties the element that IS the scroller on a phone, so the
         browser clamps its scrollTop to 0 and fires a scroll event. That looks identical
         to the reader scrolling, and this guard — written to let a real swipe win —
         treated it as one and gave up on the restore. Measured before this: a reader at
         3000px came back at 215.

         Pinning min-height does not help here the way it does for the document: on a
         scrolling element min-height sizes the ELEMENT, while scrollHeight follows its
         CONTENT, which is momentarily nothing.

         So the question is only meaningful once the page is tall enough to be where we
         asked: until then a reported position is the browser clamping, not a decision. */
      if (__ktScrollMax() < lastSet - 4) { return; }

      var at = __ktScrollPos();
      if (Math.abs(at - lastSet) > 4) { aborted = true; }
    }
    /* Listened for on the window with capture:true so it catches the scroll event from
       whichever element is doing the scrolling — a scroll on #appMain does not bubble,
       but it does capture. */
    window.addEventListener('scroll', onScroll, opts);

    function release() {
      EVENTS.forEach(function (ev) {
        try { window.removeEventListener(ev, abort, opts); } catch (e) {}
      });
      try { window.removeEventListener('scroll', onScroll, opts); } catch (e) {}
      // Let the screen size itself again — the pin exists only for the blank moment.
      if (releaseEl) { try { releaseEl.style.minHeight = ''; } catch (e) {} }
    }
    function step() {
      if (aborted) { release(); return; }
      var max = __ktScrollMax();
      var target = Math.min(y, max);
      lastSet = target;
      __ktScrollSet(target);
      // Tall enough to honour the request, or out of patience (~6s of async render —
      // it was 1.6s, shorter than a phone's request at peak, so a slow screen was
      // abandoned as a stub and the reader landed near the top).
      if (max >= y || ++tries > 120) { release(); return; }
      setTimeout(step, 50);
    }

    /* A FRAME THAT NEVER COMES IS NOT A DELAY, IT IS A CANCELLATION.

       This started with requestAnimationFrame alone. rAF does not fire while the tab or
       the app is in the background — the same trap that once left the refresh cover
       frozen over a screen — so a refresh that happened while the phone was in a pocket,
       or during the moment an APK is resuming, never restored the position at all. The
       reader came back to the top and nothing had gone wrong as far as the code knew.

       Measured while proving this: in a backgrounded context the restore never ran once,
       and the scroller stayed wherever the browser had clamped it.

       So the first step races a frame against a timer, whichever arrives first, and runs
       exactly once either way. The frame keeps it smooth when visible; the timer
       guarantees it happens when not. */
    var started = false;
    var kick = function () { if (started) { return; } started = true; step(); };
    try { requestAnimationFrame(kick); } catch (e) {}
    setTimeout(kick, 60);

    /* One last placement, made by the silent-refresh path just BEFORE the cover comes
       off: whatever the loop above managed, the finished screen is put where the reader
       was while it is still hidden, so the correction is never seen. */
    return {
      apply: function () {
        if (aborted) { return; }
        var target = Math.min(y, __ktScrollMax());
        lastSet = target;
        __ktScrollSet(target);
      }
    };
  }

  /* ONE ANSWER TO "WHERE IS THE READER" FOR THE WHOLE PORTAL (2026-09-25).

     window.scrollY is 0 for the whole session on a phone — #appMain is the scroller —
     and files that asked it were blind there. kt-auto-refresh's "is somebody reading
     down the audit log?" check was one: it always answered no, so the log rebuilt
     under the reader every 45 seconds in the APK and never on a desktop. Ask this. */
  KT.scroll = {
    host: function () { return __ktScrollHost(); },
    pos: function () { return __ktScrollPos(); },
    set: function (y) { __ktScrollSet(y); },
    max: function () { return __ktScrollMax(); },
    /* Bring el's top to `offset` px below the top of whatever scrolls. */
    toEl: function (el, offset) {
      try {
        var h = __ktScrollHost();
        var top = el.getBoundingClientRect().top - (h ? h.getBoundingClientRect().top : 0) + __ktScrollPos();
        __ktScrollSet(Math.max(0, top - (offset || 0)));
      } catch (e) {}
    },
    /* When the reader last scrolled by hand (touch, wheel, keys). */
    lastInput: function () { return window.__ktUserScrollAt || 0; }
  };
  (function () {
    var mark = function () { window.__ktUserScrollAt = Date.now(); };
    ['touchstart', 'touchmove', 'wheel', 'keydown'].forEach(function (ev) {
      try { window.addEventListener(ev, mark, { passive: true, capture: true }); } catch (e) {}
    });
  })();

  /* ONE RENDER AT A TIME (2026-09-17).

     Anthony: *"when the portal refreshes silently, graphics/screens are re-rendering
     amongst itself and it looks like its doubling up."* That is exactly what it was.

     THE DEFECT. renderScreen() is async and suspends twice — at
     `await KT.screenLoader.ensure(...)` and at `await fn(main, ctx)` — and nothing
     stopped a second call from starting during either. Three callers fire it with no
     knowledge of each other: kt-live ~600ms after EVERY write (and again in every other
     tab, over the storage event), kt-auto-refresh every 45s on a live screen and on
     return-to-app, and the shell itself on hashchange. A write while a screen is still
     fetching is not an edge case; it is a Tuesday.

     Proved with a screen that paints, awaits, then paints again — the shape of almost
     every screen here. Two renders 600ms apart left ONE #appMain holding:

         P2 header
         P1 body-after-await      <- the previous render's content, in the new screen
         P2 body-after-await

     Both renders had cleared #appMain and both kept painting into it afterwards. Two
     screens' content interleaved, each sweep and banner pass running over the other's
     work: "re-rendering amongst itself", and where the two produce similar blocks,
     doubled.

     THE RULE. A background refresh has nobody waiting on it, so it QUEUES: if a render
     is already running, remember that one more is wanted and run it — once, however many
     arrive — when the current one is done. Navigation is different: somebody just
     clicked, so it starts immediately and the in-flight render stands down at its next
     checkpoint instead (see __ktGen below). Coalescing also means a burst of writes
     costs one repaint rather than four.

     What this does NOT reach: a screen that returns while its own fetch is still in
     flight and then paints into the container it was handed. Serialising renders cannot
     help there — that render has already finished — and the fix is a per-render
     container, which #appMain cannot be without moving 47 CSS rules and three observers
     that bind to the node. Left alone deliberately, and written down here rather than
     half-done. */
  var _rsRunning = 0;
  var _rsQueued = false;
  var _rsQueuedFor = '';

  async function renderScreen() {
    var _silentNow = false;
    try { _silentNow = !!window.__ktSilentRefresh; } catch (e) {}

    /* A background refresh waits its turn — and several of them collapse into the one
       follow-up, so a burst of writes costs a single repaint. Navigation never waits:
       somebody just clicked. It runs alongside, and the older render stands down at its
       next checkpoint rather than finishing into a screen that has moved on. */
    if (_rsRunning > 0 && _silentNow) {
      _rsQueued = true;
      _rsQueuedFor = location.hash;
      // Consumed by the follow-up, not by a render that is already past reading it.
      try { window.__ktSilentRefresh = false; } catch (e) {}
      return;
    }

    _rsRunning++;
    try {
      await renderScreenOnce();
    } finally {
      _rsRunning--;
      if (_rsRunning === 0 && _rsQueued) {
        var _for = _rsQueuedFor;
        _rsQueued = false; _rsQueuedFor = '';
        /* Dropped if the reader has since moved: that navigation rendered the new screen
           from fresh data a moment ago, and refreshing it again would be a second
           rebuild of a page nobody has finished reading yet. */
        if (_for === location.hash) {
          setTimeout(function () {
            try { window.__ktSilentRefresh = true; } catch (e) {}
            renderScreen();
          }, 0);
        }
      }
    }
  }

  async function renderScreenOnce() {
    /* A re-render of the screen you are already on (a save, a tab switch) is NOT
       navigation. The resets below are correct for navigation and stay as they are;
       this records where you were so the same-screen case can be put back. */
    var _ktSameScreen = (location.hash === window.__ktLastHash);
    /* A background refresh of a screen that has since written its tab into the address
       (#admin-roles -> #admin-roles?tab=roles) is still the same screen: it must keep the
       reader's tab, search and place, not land them back at the top (2026-10-23 sweep). */
    if (!_ktSameScreen && window.__ktSilentRefresh && window.__ktLastHash
        && (location.hash || '').split('?')[0] === String(window.__ktLastHash).split('?')[0]) { _ktSameScreen = true; }
    var _ktPrevScroll = _ktSameScreen ? __ktScrollPos() : 0;
    var _ktScrollCtl = null;
    var _ktNavAt = Date.now();
    window.__ktLastHash = location.hash;
    let main = Dom.$('#appMain');   // reassigned by __ktSwapMain below
    // Reset scroll to the top BEFORE we clear + render. If we only reset after
    // render, the shell's hashchange listener (registered before kt-mobilenav's)
    // paints the new, tall screen at the OLD scroll position for a frame first —
    // that's the "shows the bottom, then jumps to the top" flash. Resetting here,
    // while the old content is still in place, means the new screen paints at 0.
    /* Landing at the top is right for NAVIGATION and wrong for a re-render of the
       screen you are already on — that is somebody's place on a page, not a new page. */
    if (!_ktSameScreen) {
      try {
        window.scrollTo(0, 0);
        if (document.scrollingElement) document.scrollingElement.scrollTop = 0;
        if (document.documentElement) document.documentElement.scrollTop = 0;
        if (document.body) document.body.scrollTop = 0;
        if (main) main.scrollTop = 0;
      } catch (e) {}
    } else if (main) {
      /* Hold the page's height across the blank moment. Dom.clear() empties #appMain,
         the document collapses to nothing, and the browser clamps the scroll position
         to the bottom of an empty page — the reader is already lost before the new
         content has even been asked for. Pinning the old height keeps the scrollbar
         still; __ktSettleScroll releases it once the screen has painted. */
      try {
        var _h = main.getBoundingClientRect().height;
        if (_h > 0) { main.style.minHeight = _h + 'px'; }
      } catch (e) {}
    }
    /* Only for a background refresh of the screen you are already on. Navigation SHOULD
        look like something happened, and a save wants its own feedback. The flag is set by
        kt-auto-refresh / kt-live and consumed here so it cannot leak into the next render. */
    var _ktSilent = false;
    try {
      _ktSilent = !!window.__ktSilentRefresh && _ktSameScreen;
      window.__ktSilentRefresh = false;

      /* PUBLISH IT, so the decorators can see it too. (2026-09-21)

         Anthony: "when the portal refreshes we still see the refresh as the icons,
         images re-render etc."

         _ktSilent was a local, used only to decide whether to lay the cover over the
         screen. Everything else that runs after a render had no idea a refresh was
         silent - and kt-animate in particular treats every card it has not seen before
         as an ARRIVAL: opacity 0, 12px down, fade and slide in over half a second, count
         the headline number up from zero, redraw the chart strokes.

         Its guards are expando properties on the DOM nodes (__ktSeen, __ktRevealed,
         __ktCounted). A refresh calls Dom.clear(main) and builds BRAND NEW nodes, so
         nothing is remembered and the whole entrance replays - every 45 seconds, and
         again ~600ms after every write, in this tab and every other one. The cards carry
         the icons and the avatars, so what the reader sees is the screen dissolving and
         rebuilding itself while they are using it.

         A timestamp rather than a flag: the cards do not exist yet at this point. Almost
         no screen here finishes inside its render function - it paints a placeholder,
         returns, and fills in from a .then() a second or two later - so a flag consumed
         on the next line would be long gone by the time the cards appear. The window
         covers the render and its late paint; navigation clears it, because navigation
         SHOULD look like something happened. */
      /* IT HAS TO OUTLAST THE COVER. (2026-09-23)

         This was 3000 while __ktSnapKill holds the cover for 6000, which left a
         three-second hole: the screen is still hidden behind the clone, but the
         animations are no longer suppressed. Anything arriving in that hole is treated
         as an arrival — opacity 0, slide up, count the numbers from zero — and the
         reader sees the tail of it the moment the cover lifts.

         On a desk that hole is theoretical: a screen fills in well under three seconds
         on wifi and the window never expires early. On the APK over mobile data three
         seconds is an ordinary time for a screen to finish, which is exactly why this
         reproduced on the phone and nowhere else. Same code, slower network, different
         outcome.

         Tied to the cover's own ceiling with a margin rather than to another hand-picked
         number, so the two cannot drift apart again. */
      window.__ktSilentUntil = _ktSilent ? (Date.now() + SILENT_WINDOW_MS) : 0;
    } catch (e) {}
    if (_ktSilent && main) { __ktSnapshot(main, (window.__ktRenderGen || 0) + 1); }

    /* A FRESH NODE, not a cleared one — see __ktSwapMain. Anything still holding the old
       #appMain (a screen that returned with its fetch in flight) now paints into an
       orphan instead of into whatever replaced it.

       AFTER the snapshot on purpose: __ktSnapshot clones the node it is given and sets
       visibility:hidden on it, and the swap copies every attribute across — so the new
       node inherits that hidden style and stays covered. __ktDropSnapshot re-queries
       #appMain by id, so it un-hides whichever node is live by then. */
    /* A PHONE SWITCHES AT ONCE (2026-10-28, Anthony: "slow and not instant ... fix this once and for all"). Filmed at
       phone speed (Dashboard -> Families): with the cover (a copy of the old screen held up while the new one settles)
       the new screen appeared at ~1.05s and its rows at ~2.2s - building the copy and holding it cost more than it hid.
       Without it: the new screen at ~0.17s, its rows at ~1.5s. On a phone there is no cover and no hold; the desktop
       keeps the cross-fade (it costs nothing there). */
    var _ktPhoneNow = false;
    try { _ktPhoneNow = !!(window.matchMedia && window.matchMedia('(max-width:768px)').matches); } catch (e) {}
    if (!_ktSameScreen && !_ktSilent && !window.__ktNoNavReveal && !_ktPhoneNow) { __ktNavCover(main); }
    main = __ktSwapMain(main, !_ktSameScreen);
    try { if (window.__ktBannerObs) window.__ktBannerObs.disconnect(); } catch (e) {}

    /* A NEW SCREEN APPEARS ONCE, FINISHED (2026-09-28).

       Anthony: "when pages load it resizes and then loads correctly why cant pages load
       correctly the first time around? (desktop and mobile)".

       A screen paints the moment its render function writes, and most write twice: a
       shell with "Loading…", then the data a few hundred ms later — cards fill, widgets
       grow, a table replaces a line of text. Each of those is the page visibly
       rearranging itself. The decorators are fixed separately (kt-sweep-bus follows the
       swap; tooltips land before the frame; the top bar is put back before the frame).

       What is left is the data arriving, and that is solved the way a background refresh
       already is: the screen's content is held back until it has SETTLED (requests done,
       DOM quiet — __ktWaitSettled) and then shown in one piece. Navigation only, capped
       at NAV_REVEAL_MS so a slow screen shows its loading state rather than nothing, and
       any touch/scroll/key reveals it at once. The top bar is never hidden. */
    var _ktRevealDone = true;
    var _ktReveal = function () {};
    if (!_ktSameScreen && !window.__ktNoNavReveal && !_ktPhoneNow) {
      __ktRevealStyle();
      _ktRevealDone = false;
      var _revealMain = main;
      _ktReveal = function (settled) {
        if (_ktRevealDone) { return; }
        _ktRevealDone = true;
        /* The boot covers (splash / reload gate in dashboard.src.html) wait for THIS,
           not for the placeholder to vanish, so the first thing seen is a finished
           screen. Only a genuinely settled screen may say so: a failsafe reveal is a
           give-up, and letting it stamp this lifted the boot cover over a screen that
           had not drawn yet (measured: "settled" at 1.8s, content at 4.0s). */
        if (settled === true) { __ktStampSettled(); }
        try {
          var _phoneNav = false;
          try { _phoneNav = !!(window.matchMedia && window.matchMedia('(max-width:768px)').matches); } catch (e) {}
          if (_phoneNav) {
            // Marked BEFORE it is shown, so not one frame of an entrance animation can run (see the style above).
            _revealMain.setAttribute('data-kt-nav-shown', '1');
            _revealMain.classList.add('kt-nav-in');
          } else {
            _revealMain.classList.add('kt-revealing-in');
          }
          _revealMain.removeAttribute('data-kt-revealing');
          __ktNavCoverDrop(true);   // the old screen goes in the same frame the new one appears: never both
          setTimeout(function () { try { _revealMain.classList.remove('kt-revealing-in', 'kt-nav-in'); } catch (e) {} }, 220);
        } catch (e) { __ktNavCoverDrop(true); }
      };
      try { main.setAttribute('data-kt-revealing', '1'); } catch (e) { _ktRevealDone = true; }
      // Absolute backstop for a screen that throws or hangs before it returns.
      setTimeout(_ktReveal, NAV_REVEAL_HANG_MS);
      /* ON A PHONE THE CAP COUNTS FROM THE TAP (2026-10-28). Counted from when the screen's code returns, it came
         after the screen's data (Families: ~1.4s at phone speed) plus the settle wait - the old screen stayed up 2.5s.
         From the tap, the new screen is on screen 350ms later whatever it is still waiting for. */
      try { if (window.matchMedia && window.matchMedia('(max-width:768px)').matches) setTimeout(_ktReveal, NAV_REVEAL_MS); } catch (e) {}
    }

    /* THIS RENDER'S GENERATION. Every deferred banner pass carries the number it was
       scheduled under and stands down if the screen has moved on since.

       Without it, the eight deferred passes below (7 timers out to 7.5s, plus a
       MutationObserver) keep running after you navigate — and because they close over
       the OLD screen's title and icon while #appMain is shared by every screen, they
       insert the previous section's banner into the page you are now on. They also run
       normaliseBanners() over content they know nothing about, which is the flashing
       and the banner moving as you change filters. */
    var __ktGen = (window.__ktRenderGen = (window.__ktRenderGen || 0) + 1);



    const user = Auth.user();
    const role = Roles.primaryRoleOf(user);

    /* NO ROLE MEANS NO ROLE — it does not mean "parent".

       primaryRoleOf() returns null when an account holds no active role assignment,
       and everything downstream then fell through to the guardian default: buildNav()
       ends with the family menu, and the home screen calls /parent/children. The
       account is not a guardian, so the API answered exactly what it should —
       "Forbidden. Required role: guardian" — and that is the message the person got,
       naming a role they had never asked for and could not act on.

       It happened on 2026-09-10 to an educator account whose role assignment had been
       withdrawn (deliberately, as a mis-filing) five days earlier: the sign-in itself
       succeeded, so from her side the app simply accused her of not being a parent.

       Say the true thing instead. This is a state an administrator has to resolve, so
       the screen names it and stops, rather than routing on a guess. Platform admins
       are exempt: they legitimately hold no tenant role and are routed through the
       agency_admin shell above. (2026-09-10) */
    const _isPlatformAdmin = !!(user && Array.isArray(user.roles)
      && user.roles.indexOf('platform_admin') !== -1);
    /* NO TOKEN IS A SIGN-OUT IN PROGRESS, NOT AN ACCOUNT WITHOUT A ROLE (2026-09-29).
       The idle timeout clears the session and then navigates to the login page. A
       refresh that landed in between (resuming the app is exactly when both fire) saw
       no user, so no role, and painted "This account has no access yet" to someone
       whose only problem was being signed out. Paint nothing; the navigation is coming. */
    if (!role && !_isPlatformAdmin && !Auth.token()) {
      _ktReveal(true);   // a finished panel: show it now, not after the 6s backstop
      __ktDropSnapshot();
      return;
    }
    if (!role && !_isPlatformAdmin) {
      Dom.clear(main);
      main.appendChild(emptyState(
        '🔒',
        'This account has no access yet',
        'Your sign-in worked, but this account holds no active role, so there is nothing '
        + 'here for it to open. Ask your administrator to assign a role — or sign in with '
        + 'the account you normally use.'
      ));
      _ktReveal(true);   // a finished panel: show it now, not after the 6s backstop
      __ktDropSnapshot();
      return;
    }

    /* e-Transfer requests moved onto the invoice (2026-11-02): the old list screen is gone and its
       links (old bell rows, bookmarks) open Accounting, where each invoice's ⋮ menu sends one. */
    if (/^#etransfer-requests(?:[/?]|$)/.test(window.location.hash || '')) {
      try { history.replaceState(null, '', location.pathname + location.search + '#external-billing'); } catch (e) { location.hash = '#external-billing'; }
    }
    const hash = (window.location.hash || ('#' + homeHashForRole(role))).replace('#', '').split('?')[0];
    _trackNav(hash);

    /* NO LICENCE, NO OPERATION (2026-10-25, Anthony's rule). /auth/me licence_lapsed is set
       when the agency's plan licence has ended past its grace; the API refuses everything but
       sign-in, the licence and help (EnforcePlanFeatures), so the shell shows why instead of
       a page of errors. Never for a superadmin (the server leaves it false for them). */
    var LAPSED_OK = ['licence', 'help', 'notifications', 'settings', 'my-profile', 'profile'];
    if (user && user.licence_lapsed && LAPSED_OK.indexOf(hash.split('/')[0]) === -1) {
      ensureTopbar('licence');
      updateActiveNav();
      var isAdm = role === 'agency_admin';
      var lw = Dom.el('div', { style: 'margin:36px auto;padding:0 16px;' });
      lw.innerHTML = '<div style="max-width:600px;margin:0 auto;background:#fff;border:1px solid #FECACA;border-radius:18px;padding:28px 24px;text-align:center;box-shadow:0 12px 30px -20px rgba(153,27,27,.45)">'
        + '<div style="font-size:44px;line-height:1;margin-bottom:10px">🔒</div>'
        + '<h2 style="margin:0 0 8px;font-size:22px;color:#0F172A">This account is paused</h2>'
        + '<p style="margin:0 auto 16px;max-width:470px;color:#475569;font-size:14.5px;line-height:1.6">'
        + (isAdm ? 'Your licence has ended, so the portal is paused for everyone at your agency. Every record is kept safe — renew and everything switches straight back on.'
                 : 'Your agency’s licence has ended, so the portal is paused for now. Your records are safe. Please contact your agency’s administrator.') + '</p>'
        + '<div style="display:flex;gap:8px;justify-content:center;flex-wrap:wrap">'
        + (isAdm ? '<a class="kt-btn" href="#licence" style="font-size:13.5px">🔑 View your licence</a>' : '')
        + '<a class="kt-btn" href="#help" style="font-size:13.5px">💬 Contact support</a></div></div>';
      main.appendChild(lw);
      _ktReveal(true);   // a finished panel: show it now, not after the 6s backstop
      __ktDropSnapshot();
      return;
    }

    /* Not linked to a family yet: anything but the screens that work without one shows
       the waiting screen instead (see familyPending). */
    if (role === 'guardian' && familyPending(user) && PENDING_OK.indexOf(hash.split('/')[0]) === -1) {
      ensureTopbar('pending');
      updateActiveNav();
      main.appendChild(pendingScreen(user));
      _ktReveal(true);   // a finished panel: show it now, not after the 6s backstop
      __ktDropSnapshot();
      return;
    }

    /* A hidden menu item is not a closed door — the hash still works if you type it,
       and several screens are linked to from other screens. Say so plainly instead of
       rendering a feature the agency does not have. The API refuses it too
       (CheckFeatureFlag); this is the part that explains itself. (2026-09-17) */
    try {
      var _hidden = (user && Array.isArray(user.hidden_screens)) ? user.hidden_screens : [];
      if (_hidden.indexOf(hash) !== -1 && lockedMap(user)[hash]) {
        ensureTopbar(hash);
        var _li = lockedMap(user)[hash];
        var _up = emptyState('🔒', _li.label, '');
        var _ub = upsellBody(_li); _ub.style.maxWidth = '520px'; _ub.style.margin = '0 auto';
        _up.appendChild(_ub);
        _up.appendChild(Dom.el('div', { style: 'display:flex;gap:8px;justify-content:center;margin-top:14px;flex-wrap:wrap' },
          Dom.el('a', { class: 'btn btn-secondary', href: '#licence' }, 'See my plan'),
          Dom.el('button', { class: 'btn btn-primary', type: 'button', onClick: function () { openUpsell(hash); } }, 'Ask about adding it')));
        main.appendChild(_up);
        _ktReveal(true);
        __ktDropSnapshot();
        return;
      }
      if (_hidden.indexOf(hash) !== -1) {
        ensureTopbar(hash);
        main.appendChild(emptyState(
          '🔒',
          'Not included in this plan',
          'This section is switched off for your agency. Your administrator can turn it '
          + 'back on, or contact your account manager about adding it.'
        ));
        _ktReveal(true);   // a finished panel: show it now, not after the 6s backstop
        __ktDropSnapshot();
        return;
      }
    } catch (e) {}

    // Clearing #appMain took the top bar with it. Put it back before the screen renders
    // a single node, so the bar is already there when the banner draws — otherwise it
    // was missing until kt-topbar's 1.2s poll, and popped in on top of the banner.
    ensureTopbar(hash);

    updateActiveNav();

    /* The role's screen modules are no longer <script> tags — they are fetched for
       the role that actually signs in. Awaited HERE, immediately before the lookup,
       so a screen is never reported missing just because its file is still in
       flight. Resolves immediately once loaded, and resolves anyway if the loader
       is absent or a file 404s, which leaves the original behaviour intact. */
    if (window.KT && KT.screenLoader) {
      var _ktGot = true;
      try { _ktGot = await KT.screenLoader.ensure(role, hash); } catch (e) {}
      /* CHECKPOINT. Fetching a role's screen files can take a while on a cold cache,
         and navigation supersedes rather than queues — so by the time this resolves the
         reader may be two screens along. Painting now would drop this screen's content
         into theirs. Our cover goes with us — if a newer render raised its own, the
         generation test leaves that one alone. */
      if (__ktGen !== window.__ktRenderGen) { __ktDropSnapshot(__ktGen); return; }
      /* SCREENS LOAD ON DEMAND (2026-11-02). This screen's file could not be fetched (offline,
         or a deploy mid-flight). Say so, with a way to try again, rather than falling through
         to the dashboard as if that were the page asked for. The next visit fetches again. */
      if (_ktGot === false) {
        var _ktFail = emptyState('📶', 'This page didn’t load', 'Check your connection, then try again.');
        var _ktRetry = document.createElement('button');
        _ktRetry.type = 'button'; _ktRetry.className = 'kt-btn'; _ktRetry.textContent = 'Try again';
        _ktRetry.style.marginTop = '12px';
        _ktRetry.addEventListener('click', function () { renderScreen(); });
        _ktFail.appendChild(_ktRetry);
        main.appendChild(_ktFail);
        _ktReveal(true);
        __ktDropSnapshot();
        return;
      }
    }

    // Route based on role + hash
    const screenKey = `${role}:${hash}`;
    const fallbackKey = `${role}:dashboard`;

    // Super-admins operate under the resolved `agency_admin` role, but several
    // screens (Maintenance, Email/mail-settings, and other super-admin-only
    // Reseller tools) are registered ONLY under `platform_admin:`. Try that key
    // before falling back to the dashboard — otherwise those nav items silently
    // landed on the home screen ("Maintenance / Email don't open").
    const isPlatform = !!(user && Array.isArray(user.roles) && user.roles.indexOf('platform_admin') !== -1)
      || sessionStorage.getItem('kt_is_platform_admin') === '1';

    /* A SUB-PATH IS A PARAMETER, NOT A DIFFERENT SCREEN (2026-09-16).

       Only `?` was stripped, so `#help/timesheets` was looked up whole and matched
       nothing — every deep link into a guide landed on the dashboard. That includes the
       link the Help screen's own "Copy link" button produces, and every cross-reference
       between two guides.

       Tried only AFTER the exact key, so nothing that resolves today changes: a screen
       whose hash genuinely contains a slash would still win. The screen reads the tail
       out of location.hash itself, exactly as the Help screen already does. */
    const baseHash = hash.indexOf('/') !== -1 ? hash.split('/')[0] : '';

    const fn = screens[screenKey]
      || (isPlatform ? screens['platform_admin:' + hash] : null)
      || (user && Array.isArray(user.roles) && user.roles.indexOf('reseller_admin') !== -1 ? screens['reseller_admin:' + hash] : null)
      || (baseHash ? screens[role + ':' + baseHash] : null)
      || (baseHash && isPlatform ? screens['platform_admin:' + baseHash] : null)
      || screens[fallbackKey] || screens['guardian:today'];

    if (!fn) {
      main.appendChild(emptyState('🤔', 'Screen not available', 'No screen registered for this role + page.'));
      // Early return: uncover, or the reader stares at a picture of the old screen until
      // the safety timeout fires.
      _ktReveal(true);   // a finished panel: show it now, not after the 6s backstop
      __ktDropSnapshot();
      return;
    }

    try {
      await fn(main, { user, role, params: parseParams() });
      /* CHECKPOINT. Everything below this line acts on #appMain — restoring a scroll
         position, dropping the cover, inserting and normalising a banner. If the reader
         has navigated while this screen was rendering, all of it would be done TO
         SOMEBODY ELSE'S SCREEN: their page yanked to our scroll position, their cover
         pulled off mid-render, our section's banner inserted over their content. The
         deferred banner passes already carried this test; the work that runs right here
         did not. */
      if (__ktGen !== window.__ktRenderGen) { __ktDropSnapshot(__ktGen); return; }
      /* SAME SCREEN, SAME TAB (2026-10-23): a re-render of the screen you are on rebuilds it
         on its first tab; put back the tabs you had chosen (kt-keep-tabs.js) before the
         refresh is uncovered and before the scroll restore below lands. */
      if (_ktSameScreen && window.KT && KT.__keepTabs) {
        try { await KT.__keepTabs.restore(main); } catch (e) {}
        if (__ktGen !== window.__ktRenderGen) { __ktDropSnapshot(__ktGen); return; }
      }
      /* The phone has no hold (see _ktPhoneNow), but the boot cover still waits for the first screen to settle. */
      if (_ktPhoneNow && !_ktSameScreen && !window.__ktScreenSettledAt) {
        /* ...for the SCREEN's own requests only, and no second round (2026-11-02). It waited on every request
           on the page, then up to 3s more: the home screen was drawn at 0.56s and the cover lifted at 3.3s
           (Anthony's Samsung, all files cached: dcl 0.4s, splash gone 3.7s). Show the screen once it has
           painted and its first data is in; anything still arriving (quick-access sync, gates, pulse) lands
           on a screen people can already see. */
        __ktWaitSettled(main, function () { __ktStampSettled(); }, NAV_REVEAL_MS + 900, true);
      }
      if (!_ktRevealDone) {
        var _settleMain = main;
        __ktWaitSettled(main, function (capped) {
          _ktReveal(!capped);
          // Shown at the cap, but not finished: keep listening, and only say "settled"
          // once it really is (the boot cover is waiting on that, capped at 8s there).
          if (capped) { __ktWaitSettled(_settleMain, function (c2) { if (!c2) { __ktStampSettled(); } }, 5000); }
        }, NAV_REVEAL_MS);
        // Counted from here, not from the swap: a cold start can spend longer than
        // this just fetching the screen's own file.
        setTimeout(_ktReveal, NAV_REVEAL_FAILSAFE_MS);
      }
      /* Uncover on the next frame: the render has returned, so the new content is in the
         DOM, and one frame lets the browser paint it before the picture of the old screen
         is taken away. Removing it synchronously here shows a blank flash again. */
      if (_ktSilent) {
        /* Wait for the screen to SETTLE, not just for its render function to return —
           see __ktWaitSettled. Then uncover on the next frame, so the browser has
           painted the finished screen before the picture of the old one is taken away.
           requestAnimationFrame does not fire in a backgrounded tab, so a 250ms timer
           runs alongside it: the frame is what makes the swap seamless when the tab is
           visible, the timer is what guarantees it ends. */
        var _dropped = false;
        var _drop = function () {
          if (_dropped) { return; }
          _dropped = true;
          try { if (_ktScrollCtl) { _ktScrollCtl.apply(); } } catch (e) {}
          __ktDropSnapshot(__ktGen);
        };
        __ktWaitSettled(main, function () {
          /* BEFORE the uncover, never after: the phase has to be right in the DOM the
             reader is about to be shown, or the banner still snaps — just later. */
          __ktPinAnimations(main);
          try { requestAnimationFrame(function () { requestAnimationFrame(_drop); }); } catch (e) {}
          setTimeout(_drop, 250);
        });
      }
      // Land every freshly-rendered screen at the very top. Doing it AFTER render
      // (not just on hashchange, before the async content exists) is what stops the
      // "shows the bottom, then scrolls to the top" flash on tall screens like Home.
      try {
        if (_ktSameScreen) {
          /* Same screen: never scroll to 0 first. The old code reset to the top and then
             raced a single restore frame against it — which is why a live refresh flung
             you to the top and sometimes left you there. */
          _ktScrollCtl = __ktSettleScroll(_ktPrevScroll, main);
        } else if ((window.__ktUserScrollAt || 0) > _ktNavAt) {
          /* The reader has already started scrolling the new screen while it loaded.
             Snapping them back to 0 now is the "jumps to the top" they reported. */
        } else {
          window.scrollTo(0, 0);
          if (document.scrollingElement) document.scrollingElement.scrollTop = 0;
          if (document.documentElement) document.documentElement.scrollTop = 0;
          if (document.body) document.body.scrollTop = 0;
          main.scrollTop = 0;
          requestAnimationFrame(function () { try { window.scrollTo(0, 0); if (document.scrollingElement) document.scrollingElement.scrollTop = 0; } catch (e) {} });
        }
      } catch (e) { try { if (main) main.style.minHeight = ''; } catch (e2) {} }
      try {
        var __info = navItemFor(role, hash);
        // ONE banner per screen, and it always looks the same.
        //
        // Only the standard .kt-hero component counts as "this screen brought its own
        // banner" (role-home's greeting tile is the one legitimate case). The legacy
        // headers — .page-header-v17 / .kt-page-hero, and the hand-rolled inline-gradient
        // banner Tours builds — no longer suppress the auto-hero; normaliseBanners()
        // folds them INTO it. That is why AI Lesson Plans looked nothing like the rest
        // of the site and Tours stacked two banners.
        var __hasHero = function () {
          // A screen can opt out of the auto-hero explicitly (educator Today renders
          // its own "Today at a glance" banner in that slot) via data-kt-no-autohero,
          // without needing a .kt-hero element that global banner-fx would decorate.
          //
          // .kt-page-hero is NOT in this list, though it was until 2026-08-31 -- which
          // contradicted the paragraph directly above and cost the parent Settings screen
          // its banner. The class is used for SECTION headers as well as page ones, and
          // Settings has one on its Payments tab ("Wallet"). Buried in a tab panel that
          // is not even on screen, it still answered "this screen brought its own
          // banner", so the shell stood down and Settings became the only screen in the
          // portal without one.
          //
          // A selector that means "the screen has a banner" cannot be one that also
          // matches things which are not banners. .kt-hero is the component; a legacy
          // .kt-page-hero gets folded into the auto-hero by normaliseBanners(), which is
          // what the comment above always said would happen.
          return !!main.querySelector('.kt-hero, [data-kt-no-autohero]') || !!ownBanner(main);
        };
        var __ensure = function () {
          // Belongs to a screen that is no longer on display — do nothing at all.
          if (__ktGen !== window.__ktRenderGen) { return; }
          // The budget is GLOBAL per screen visit, not per render pass. A screen that
          // re-renders itself (support tickets does) calls renderScreen again, which
          // would reset a per-pass counter to zero — so a screen that re-adds the banner
          // we just folded in ping-pongs with us forever. That froze the tab. With a
          // shared budget the worst case is that we stop touching the screen and it
          // simply keeps its own banner.
          ensureTopbar(hash);   // capped — see below
          /* A MISSING BANNER GOES BACK WHATEVER THE BUDGET SAYS (2026-10-21).
             Anthony: "educator rewards the hero banner disappears when navigating to the
             subtabs". Subtabs that change the hash (#x/settings) re-render through here and
             share one per-screen budget, so after a few tab switches it ran out and the
             banner was simply not put back. The budget exists to stop a ping-pong over
             normalising; adding a banner that is not there at all is rate-capped instead
             (3 within 2s), which is what actually stops a self-re-rendering screen looping. */
          if (!__hasHero() && heroInsertAllowed()) { main.insertBefore(buildAutoHero(__info, hash), heroAnchor(main)); }
          try { enforceOneBanner(main); } catch (e) {}
          try { alignAutoHero(main); } catch (e) {}
          try { dropEchoes(main); } catch (e) {}
          if (!bannerBudgetLeft(hash)) return;
          try { if (normaliseBanners(main, hash)) bannerSpend(hash); } catch (e) {}
        };
        __ensure();
        // Tiles for a switched-off feature, painted into the screen by role-home.
        try { pruneHiddenLinks(main); } catch (e) {}
        // Some screens (Vacation holds) clear main and render their header seconds
        // later — after the old 4s window had closed, which left them with no banner
        // at all. The 15-run budget is what keeps this cheap.
        [120, 400, 900, 1800, 3200, 5200, 7500].forEach(function (ms) { setTimeout(__ensure, ms); });
        if (window.MutationObserver) {
          var __obs = new MutationObserver(function () {
            // Stop watching the moment this screen is replaced, rather than waiting out
            // the 9s timeout while another screen's content churns underneath us.
            if (__ktGen !== window.__ktRenderGen) { try { __obs.disconnect(); } catch (e) {} return; }
            __ensure();
          });
          window.__ktBannerObs = __obs;
          __obs.observe(main, { childList: true });
          setTimeout(function () { try { __obs.disconnect(); } catch (e) {} }, 9000);
        }
      } catch (e) {}
    } catch (e) {
      console.error('Screen render error:', e);
      /* Uncover FIRST. The cover hides #appMain while it is up, so an error state
         rendered behind it is an error state nobody can read — six seconds of blank
         page until the failsafe fires. */
      __ktDropSnapshot();
      Dom.clear(main);
      main.appendChild(emptyState('⚠️', 'Something went wrong', e.message || 'Please refresh the page.'));
    }
  }

  function parseParams() {
    const hashParts = (window.location.hash || '').split('?');
    if (hashParts.length < 2) return {};
    const params = {};
    new URLSearchParams(hashParts[1]).forEach((v, k) => { params[k] = v; });
    return params;
  }

  function navigate(hash) {
    window.location.hash = '#' + hash;
  }

  // ──────────────── Modal system ───────────────────────────────
  /* The page's own overflow, captured when the FIRST popup opens. A popup opened over
     (in place of) another one must not record "hidden" as the value to go back to, or
     the page stays unscrollable after the last popup closes. */
  let _modalBaseOverflow = null;

  const Modal = {
    open({ title, body, actions = [], onClose = null, large = false }) {
      const root = Dom.$('#modalRoot');
      const _htmlEl = document.documentElement;
      if (!root.firstChild || _modalBaseOverflow === null) { _modalBaseOverflow = _htmlEl.style.overflow; }
      Dom.clear(root);
      // a11y: remember what had focus so we can restore it when the modal closes.
      const prevFocus = document.activeElement;

      // Lock the page behind the modal so a mouse-wheel scroll inside the dialog
      // doesn't scroll the whole window — it stays focused on the popup.
      _htmlEl.style.overflow = 'hidden';

      /* A POPUP ONLY CLOSES ITSELF (2026-10-09: "it asks you to hit edit twice - full
         sweep to remove this type of behaviour throughout the portal"). There is one
         #modalRoot, and a popup opened from inside another (View -> Edit) replaces it.
         The old one's close() then still ran - a footer action closes its popup after
         its handler returns, Esc was still wired to it - and cleared #modalRoot, wiping
         the popup that had just opened. The first press looked like it did nothing, so
         people pressed again. A popup that has been replaced now leaves #modalRoot
         alone; it only unhooks itself. */
      const close = () => {
        document.removeEventListener('keydown', onKeyDown, true);
        const mine = backdrop && backdrop.isConnected && root.contains(backdrop);
        if (mine) {
          _htmlEl.style.overflow = _modalBaseOverflow || '';
          _modalBaseOverflow = null;
          Dom.clear(root);
          try { if (prevFocus && prevFocus.focus) prevFocus.focus(); } catch (e) {}
        }
        if (onClose) onClose();
      };

      // Esc closes; Tab is trapped inside the dialog so keyboard focus can't wander
      // onto the page behind the backdrop — standard accessible-dialog behaviour.
      function onKeyDown(e) {
        if (e.key === 'Escape') { e.preventDefault(); close(); return; }
        if (e.key === 'Tab') {
          const f = modal.querySelectorAll('a[href],button:not([disabled]),textarea:not([disabled]),input:not([disabled]):not([type=hidden]),select:not([disabled]),[tabindex]:not([tabindex="-1"])');
          if (!f.length) return;
          const first = f[0], last = f[f.length - 1];
          if (e.shiftKey && document.activeElement === first) { e.preventDefault(); last.focus(); }
          else if (!e.shiftKey && document.activeElement === last) { e.preventDefault(); first.focus(); }
        }
      }

      const backdrop = Dom.el('div', { class: 'modal-backdrop' });
      // Close only when a click BOTH starts and ends on the backdrop. Tracking the
      // mousedown origin stops a text-selection drag that begins inside a field and
      // releases on the backdrop from closing the dialog mid-edit (annoying bug).
      let _downOnBackdrop = false;
      backdrop.addEventListener('mousedown', (e) => { _downOnBackdrop = (e.target === backdrop); });
      backdrop.addEventListener('click', (e) => { if (e.target === backdrop && _downOnBackdrop) close(); });

      const modal = Dom.el('div', { class: 'modal' + (large ? ' modal-large' : '') });
      modal.setAttribute('role', 'dialog');
      modal.setAttribute('aria-modal', 'true');
      modal.setAttribute('aria-labelledby', 'kt-modal-title');
      modal.setAttribute('tabindex', '-1');

      // Header
      const header = Dom.el('div', { class: 'modal-header' });
      const titleEl = Dom.el('h2', {}, title);
      titleEl.id = 'kt-modal-title';
      header.appendChild(titleEl);
      const xBtn = Dom.el('button', {
        class: 'modal-close',
        onClick: close,
      }, '×');
      xBtn.setAttribute('aria-label', 'Close');
      header.appendChild(xBtn);
      modal.appendChild(header);

      // Body — scrolls inside the dialog (overscroll-behavior:contain stops the
      // scroll from chaining to the page once the body hits its top/bottom).
      const bodyEl = Dom.el('div', { class: 'modal-body' });
      // Scrolling lives in the stylesheet now: the shell is a flex column that does
      // not scroll and the body is the only scroll region, sized by flex. The inline
      // "90vh minus 128px" had to guess the height of the header and footer, and
      // paired with the shell's own overflow it produced two scrollbars.
      if (typeof body === 'string') {
        bodyEl.innerHTML = body;
      } else if (body instanceof Node) {
        bodyEl.appendChild(body);
      }
      modal.appendChild(bodyEl);

      // Footer
      if (actions.length) {
        const footer = Dom.el('div', { class: 'modal-footer' });
        actions.forEach(a => {
          // v22p3.1: accept either `handler` (preferred) OR `onClick` (legacy from
          // screen-admin.js). Without this back-compat, every screen-admin action
          // button no-ops and the modal just closes — that's why "Create centre"
          // and "Save user" appeared to do nothing.
          const cb = a.handler || a.onClick;
          const isPrimary = a.style === 'btn-primary' || a.primary;
          const btn = Dom.el('button', {
            class: 'btn ' + (a.style || (isPrimary ? 'btn-primary' : 'btn-secondary')),
            onClick: async () => {
              if (cb) {
                btn.disabled = true;
                btn.textContent = a.busyLabel || 'Saving…';
                try {
                  const result = await cb();
                  // If the callback returned `false` (legacy onClick callers do
                  // this on validation error), leave the modal open and restore
                  // the button. Otherwise close.
                  if (result === false) {
                    btn.disabled = false;
                    btn.textContent = a.label;
                  } else {
                    close();
                  }
                } catch (err) {
                  btn.disabled = false;
                  btn.textContent = a.label;
                  if (Dom.toast) Dom.toast(err.message || 'Something went wrong', 'error');
                  else console.error(err);
                }
              } else {
                close();
              }
            },
          }, a.label);
          footer.appendChild(btn);
        });
        modal.appendChild(footer);
      }

      backdrop.appendChild(modal);
      root.appendChild(backdrop);

      // Trap focus + wire Esc, then move focus into the dialog (prefer the first
      // field for data entry, else the first non-close button, else the dialog).
      document.addEventListener('keydown', onKeyDown, true);
      const focusables = modal.querySelectorAll('a[href],button:not([disabled]),textarea:not([disabled]),input:not([disabled]):not([type=hidden]),select:not([disabled]),[tabindex]:not([tabindex="-1"])');
      let toFocus = null;
      for (let i = 0; i < focusables.length; i++) { if (/^(INPUT|TEXTAREA|SELECT)$/.test(focusables[i].tagName)) { toFocus = focusables[i]; break; } }
      if (!toFocus) { for (let j = 0; j < focusables.length; j++) { if (!focusables[j].classList.contains('modal-close')) { toFocus = focusables[j]; break; } } }
      try { (toFocus || modal).focus(); } catch (e) {}

      return { close, backdrop };
    },

    // v22p3.1: facade-level close so callers can do Shell.Modal.close() without
    // holding the handle returned by open().
    close() {
      const root = Dom.$('#modalRoot');
      if (root) Dom.clear(root);
      // Release the background scroll lock set in open().
      try { document.documentElement.style.overflow = _modalBaseOverflow || ''; } catch (e) {}
      _modalBaseOverflow = null;
    },

    /* A CONFIRM BELONGS TO THE SCREEN THAT ASKED IT (2026-10-02).

       "Check Simran in?" stayed up after the phone's Back button took the educator to
       the time clock, where "Yes, check in" would still have checked Simran in, from a
       screen that says nothing about her. The backdrop stops taps reaching the nav, but
       not Back. So a confirm now closes, as a Cancel, once the hash leaves the screen it
       was opened on. Compared to the hash at open rather than closing on any hashchange,
       because a screen that sets the hash and then confirms in the same tick gets that
       hashchange afterwards, for the screen it is already on. Plain Modal.open is left
       alone: forms are opened mid-navigation on purpose. */
    confirm({ title, message, confirmLabel = 'Confirm', destructive = false, onConfirm }) {
      const openHash = location.hash;
      const onNav = () => {
        if (!h.backdrop.isConnected) { window.removeEventListener('hashchange', onNav); return; }
        if (location.hash !== openHash) { window.removeEventListener('hashchange', onNav); h.close(); }
      };
      const h = this.open({
        onClose: () => window.removeEventListener('hashchange', onNav),
        title,
        body: Dom.el('p', { style: 'font-size: 15px; line-height: 1.6;' }, message),
        actions: [
          { label: 'Cancel', style: 'btn-secondary' },
          {
            label: confirmLabel,
            style: destructive ? 'btn-danger' : 'btn-primary',
            handler: onConfirm,
            busyLabel: 'Working…',
          },
        ],
      });
      window.addEventListener('hashchange', onNav);
      return h;
    },
  };

  /* A DIALOG BELONGS TO THE SCREEN IT WAS OPENED ON (2026-10-05).

     Confirms already close when you leave their screen (above). Everything else did not:
     the closure, conference and background-check dialogs, the Shell's own forms and the
     ~85 hand-built overlays stayed up on the next screen — after the phone's Back button,
     a dialog about one screen sat answerable on top of another.

     Each full-screen overlay is stamped with the hash it opened on. When the hash leaves
     that screen it is closed, but only if it is an ordinary dialog: it offers its own way
     out (Cancel / Close / ×) and it is not one of the gates that must outlive navigation —
     terms, MFA, PIN or biometric unlock, the session-timeout warning, urgent alerts, the
     onboarding tour. Same-tick openings (set the hash, then open) are stamped with the new
     hash, so they are never closed by their own navigation. */
  (function closeDialogsOnNavigation() {
    const stamp = new WeakMap();
    const GATE = '[id^="kt-agree"],[id^="kt-mfa-gate"],[id^="kt-pin"],[id^="kt-bio"],[id^="kt-session"],[id^="kt-urgent"],[id^="kt-onboarding"],[id^="kt-step-"],[id^="kt-decl"],[data-kt-keep-open]';
    const isOverlay = (el) => {
      if (!el || el.nodeType !== 1) return false;
      const cs = getComputedStyle(el);
      if (cs.position !== 'fixed') return false;
      const r = el.getBoundingClientRect();
      return r.width >= innerWidth * 0.9 && r.height >= innerHeight * 0.9;
    };
    const dismissable = (el) => Array.from(el.querySelectorAll('button,[role=button],a'))
      .some((b) => /^(cancel|close|×|✕|✖|x|done|not now)$/i.test((b.textContent || b.getAttribute('aria-label') || '').trim()));
    new MutationObserver((muts) => {
      muts.forEach((m) => m.addedNodes.forEach((n) => { if (n.nodeType === 1 && !stamp.has(n)) stamp.set(n, location.hash); }));
    }).observe(document.body, { childList: true });
    const modalRoot = () => document.getElementById('modalRoot');
    let modalHash = null;
    const mr = modalRoot();
    if (mr) {
      new MutationObserver(() => { modalHash = mr.children.length ? (modalHash || location.hash) : null; })
        .observe(mr, { childList: true });
    }
    window.addEventListener('hashchange', () => {
      const now = location.hash;
      let closed = 0;
      Array.from(document.body.children).forEach((el) => {
        if (!stamp.has(el) || stamp.get(el) === now) return;
        if (!isOverlay(el) || el.matches(GATE) || el.querySelector(GATE) || !dismissable(el)) return;
        el.remove(); closed++;
      });
      if (modalHash && modalHash !== now && mr && mr.children.length && !mr.querySelector(GATE)) { Modal.close(); modalHash = null; closed++; }
      // A removed overlay cannot undo the scroll lock it set: release it if nothing is left open.
      if (closed && !Array.from(document.body.children).some((el) => isOverlay(el) && el.id !== 'appShell')) {
        document.documentElement.style.overflow = ''; document.body.style.overflow = '';
        document.body.classList.remove('kt-dialog-open');
      }
    });
  })();

  // ──────────────── Generic helpers ────────────────────────────
  /* WAITING FOR THE AGENCY (2026-10-21). /auth/me sets family_pending on a parent account
     that holds the guardian role but belongs to no family yet — typically one made so a
     form could be sent before the family was set up. Every other parent screen needs a
     family, so they are not offered: the server would refuse them anyway. */
  var PENDING_OK = ['pending', 'my-forms', 'my-documents', 'notifications', 'help',
    'settings', 'my-profile', 'profile', 'whats-new'];
  function familyPending(user) {
    try {
      var u = user || JSON.parse(sessionStorage.getItem('kt_user') || localStorage.getItem('kt_user') || '{}');
      if (!u || !u.family_pending) return false;
      // A super admin previewing the parent view is not this account.
      var va = ''; try { va = sessionStorage.getItem('kt_view_as') || ''; } catch (e) {}
      return !va;
    } catch (e) { return false; }
  }

  function pendingScreen(user) {
    var ag = (user && user.pending_agency) || {};
    var name = ag.name || 'your agency';
    var esc = function (s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); };
    var phone = String(ag.phone || '').replace(/\D/g, '');
    var phoneShow = phone.length === 10 ? '(' + phone.slice(0, 3) + ') ' + phone.slice(3, 6) + '-' + phone.slice(6) : (ag.phone || '');
    var first = (user && (user.preferred_name || user.first_name)) || '';
    var wrap = Dom.el('div', { class: 'kt-pending', style: 'margin:28px auto;padding:0 16px;' });
    wrap.innerHTML =
      '<div style="max-width:600px !important;margin:0 auto;background:#fff;border:1px solid #FDE68A;border-radius:18px;padding:26px 24px;box-shadow:0 10px 26px -18px rgba(146,64,14,.45);text-align:center;">'
      + '<div style="font-size:42px;line-height:1;margin-bottom:10px;">⏳</div>'
      + '<h2 style="margin:0 0 8px;font-size:21px;color:#0F172A;">Your account is pending approval</h2>'
      + '<p style="margin:0 auto 14px;max-width:470px;color:#475569;font-size:14.5px;line-height:1.55;">'
      + (first ? 'Welcome, ' + esc(first) + '. ' : '') + 'Your sign-in works, but <b>' + esc(name) + '</b> hasn’t finished setting up your family yet. '
      + 'Once they link your account to your family, your child’s updates, photos, messages and billing will appear here.</p>'
      + '<p style="margin:0 auto 18px;max-width:470px;color:#92400E;background:#FFFBEB;border-radius:10px;padding:10px 12px;font-size:14px;line-height:1.5;">'
      + 'If you’ve been waiting a while, please reach out to your agency administrator at ' + esc(name) + '.</p>'
      + ((ag.email || phoneShow) ? '<div style="display:flex;gap:8px;justify-content:center;flex-wrap:wrap;margin-bottom:18px;">'
        + (ag.email ? '<a class="kt-btn" href="mailto:' + esc(ag.email) + '" style="font-size:13px;">✉️ ' + esc(ag.email) + '</a>' : '')
        + (phoneShow ? '<a class="kt-btn" href="tel:' + esc(phone || ag.phone) + '" style="font-size:13px;">📞 ' + esc(phoneShow) + '</a>' : '')
        + '</div>' : '')
      + '<div style="border-top:1px solid #F1F5F9;padding-top:16px;color:#64748B;font-size:13px;margin-bottom:10px;">In the meantime you can:</div>'
      + '<div style="display:flex;gap:8px;justify-content:center;flex-wrap:wrap;">'
      + '<a class="kt-btn kt-btn-primary" href="#my-forms" style="font-size:13px;">✍️ Forms to sign</a>'
      + '<a class="kt-btn" href="#my-documents" style="font-size:13px;">📄 Documents</a>'
      + '<a class="kt-btn" href="#settings" style="font-size:13px;">👤 My account</a>'
      + '</div></div>';
    return wrap;
  }

  function emptyState(emoji, title, sub) {
    return Dom.el('div', { class: 'empty-large' },
      Dom.el('div', { class: 'empty-emoji' }, emoji),
      Dom.el('h3', {}, title),
      Dom.el('p', {}, sub),
    );
  }

  function statusTagFor(ratioStatus) {
    return ratioStatus === 'ok' ? { class: 'tag-success', text: 'COMPLIANT' }
      : ratioStatus === 'tight' ? { class: 'tag-warn', text: 'TIGHT' }
      : { class: 'tag-danger', text: 'BREACH' };
  }

  // ──────────────── Bootstrap ──────────────────────────────────
  async function startApp() {
    if (!Auth.requireLogin()) return;

    const user = Auth.user();
    const role = Roles.primaryRoleOf(user);

    // v17.3: Defensive cleanup — even with the early flag set, if any
    // v12/v13/v14/v15 nav-additions file is missing the guard (maybe
    // the guard patcher never ran), this observer catches injected
    // <div id="kt-v##-nav-extra"> elements and removes them as soon as
    // they're added to the sidebar. Belt-and-suspenders.
    const sidebarEl0 = Dom.$('#appSidebar');
    if (sidebarEl0 && typeof MutationObserver !== 'undefined') {
      const navObserver = new MutationObserver((mutations) => {
        for (const m of mutations) {
          for (const node of m.addedNodes) {
            if (node.nodeType === 1 && node.id && /^kt-v\d+-nav-extra$/.test(node.id)) {
              node.remove();
            }
          }
        }
      });
      navObserver.observe(sidebarEl0, { childList: true });
      // Also clean up any that snuck in before we got here
      sidebarEl0.querySelectorAll('[id^="kt-v"][id$="-nav-extra"]').forEach(n => n.remove());
    }

    // v17.2: layout class is applied in HTML by default. For non-sidebar
    // roles, we must remove BOTH the outer .app-shell--sidebar AND the
    // .app-sidebar class on the aside element — otherwise the aside's
    // vertical-column styles fight the .app-nav horizontal-row styles
    // and the top nav renders as a 100vh-tall column.
    const isSidebarRole = (role === 'agency_admin' || role === 'centre_director');
    const shell = Dom.$('#appShell');
    const sidebarEl = Dom.$('#appSidebar');

    // No resolvable role is a real state — a deactivated role_assignment, or an
    // /auth/me that answered before its roles were attached. Calling .replace() on it
    // threw during boot and left a blank shell with nothing to act on. Boot without the
    // role class instead, and say what is wrong.
    const roleClass = role ? ('role-' + role.replace('_', '-')) : null;

    /* An EARLY GUESS may already be on <body>, applied inline at parse time from the
       cached user so the phone header is not desktop-sized for the whole boot (see
       dashboard.src.html). It is a guess from cache, and a cached role can be stale —
       so if the authoritative role disagrees, take the guess off before adding the
       real one. Leaving both would style the page as two roles at once. */
    try {
      const early = document.body.getAttribute('data-kt-early-role');
      if (early) {
        if (early !== roleClass) {
          document.body.classList.remove(early);
          if (!isSidebarRole) document.body.classList.remove('layout-sidebar');
        }
        document.body.removeAttribute('data-kt-early-role');
      }
    } catch (e) {}
    if (isSidebarRole && roleClass) {
      document.body.classList.add('layout-sidebar', roleClass);
    } else {
      if (shell)     shell.classList.remove('app-shell--sidebar');
      if (sidebarEl) sidebarEl.classList.remove('app-sidebar');
      if (roleClass) { document.body.classList.add(roleClass); }
    }
    if (!role) {
      console.warn('[shell] no role resolved for this account — booting without a role scope');
      try {
        var _rw = document.createElement('div');
        _rw.style.cssText = 'background:#FEF3C7;border-bottom:1px solid #FDE68A;color:#92400E;'
          + 'padding:10px 14px;font-size:13.5px;text-align:center;';
        _rw.textContent = 'Your account does not have a role assigned yet, so parts of the app are unavailable. '
          + 'Please ask your administrator to assign one.';
        document.body.insertBefore(_rw, document.body.firstChild);
      } catch (e) {}
    }

    /* A SIGNED PHOTO URL EXPIRES, AND THE CACHED USER OUTLIVES IT (2026-09-17).

       Protected media is served from `/api/v1/media/f?expires=…` — SignProtectedMedia
       rewrites every `/storage/…` path on the way out of the API, and 37 of the 67 users
       with a photo still hold a raw `/storage/…` in the column, which answers **403 on
       both hosts**, by design. So the only link a client can use is the one the API just
       handed it, and `kt_user` is a CACHE: the app keeps a session for days, the expiry
       rolls past, the avatar 403s, and `onerror` falls back to initials for good.

       Anthony, 2026-09-17, on an educator's iPhone: initials where her photo should be.
       Nothing was wrong with her upload — the file is there and the API signs it
       correctly. The phone was asking with a link that had gone stale. kt-topbar.js
       already refreshed this, but it returns early for anyone who is not an admin, so
       every educator, home visitor and parent kept the expired one.

       One fetch, shared by every avatar on the page, and written back to BOTH stores —
       fixing the picture on screen without fixing the cache behind it just moves the 403
       to the next cold start. */
    // Set up nav user pill
    const navAvatar = Dom.$('#navAvatar');
    const navName   = Dom.$('#navName');
    const navRole   = Dom.$('#navRole');
    if (navAvatar) {
      // v22p3.2: render photo if user.photo_url is set, fallback to initials.
      if (user && user.photo_url) {
        navAvatar.textContent = '';
        const apiBase = (window.KT && window.KT.API_BASE) || 'https://api.kiddietrac.com/api/v1';
        const apiHost = apiBase.replace(/\/api\/v1\/?$/, '');
        const src = /^https?:\/\//i.test(user.photo_url) ? user.photo_url : (apiHost + user.photo_url);
        const img = document.createElement('img');
        img.src = src;
        img.alt = user.name || '';
        img.style.cssText = 'width:100%;height:100%;object-fit:cover;border-radius:50%;display:block;';
        /* Remove via img's OWN parent, never navAvatar's.

         navAvatar.removeChild(img) throws NotFoundError whenever img is not a child
         of it right then -- which happens if the pill was re-rendered since, and on
         WebKit if error fires for an already-failed cached URL BEFORE the appendChild
         below has run. That threw during boot on iPhone and left a dead shell. */
      img.onerror = () => {
        /* Ask the server for a fresh link ONCE before giving up. A 403 here almost
           always means the signature expired, not that the photo is gone. */
        if (!img.getAttribute('data-kt-retried')) {
          img.setAttribute('data-kt-retried', '1');
          KT.refreshUserPhoto().then(function (fresh) {
            if (fresh) {
              img.src = /^https?:\/\//i.test(fresh) ? fresh : (apiHost + fresh);
              return;
            }
            if (img.parentNode) { img.parentNode.removeChild(img); }
            navAvatar.textContent = Fmt.initials(user && user.name);
          });
          return;
        }
        if (img.parentNode) { img.parentNode.removeChild(img); }
        navAvatar.textContent = Fmt.initials(user && user.name);
      };
        navAvatar.appendChild(img);
      } else {
        // Not user.name: the branch above tests `user && user.photo_url`, so reaching
        // here with a null user is expected — a valid token whose cached user object is
        // missing. Calling .name on it killed boot behind a blank shell.
        navAvatar.textContent = Fmt.initials(user && user.name);
      }
    }
    // `user.name?.` guards a missing NAME, not a null USER — which is the case that
    // actually occurs here.
    // First name only — "just leave my display pic/avatar and first name" (2026-09-06).
    if (navName)   navName.textContent   = ((user && user.name) || '').split(' ')[0] || 'You';
    if (navRole && role) {
      // Distinguish a platform_admin (primaryRoleOf resolves them to agency_admin)
      // from a genuine agency admin — a real agency admin was mislabelled "Platform
      // admin". home_visitor was missing → the pill showed the raw enum. Aligns with
      // kt-topbar.js's ROLE map.
      const _roles = (user.roles || []);
      const roleLabel = _roles.indexOf('platform_admin') !== -1
        ? 'Platform admin'
        : _roles.indexOf('reseller_admin') !== -1 ? 'Reseller'   // works as agency admin in its agencies (2026-10-21)
        : ({
            agency_admin:    'Agency admin',
            centre_director: 'Director',
            educator:        'Educator',
            guardian:        'Parent',
            home_visitor:    'Home visitor',
            sales_rep:       'Sales rep',
            reseller_admin:  'Reseller',
            auditor:         'Auditor',
          }[role] || role);
      navRole.textContent = roleLabel;
      /* Hidden ONLY on the desktop admin sidebar — "on the side bar where it shows my
         name at the bottom ... just leave my display pic/avatar and first name".

         For a guardian, educator, home visitor or sales rep this same user block IS
         their top bar (kt-parent-chrome moves it there), and the role belongs on it.
         Hiding it for everyone took it off those bars too. Still computed either way;
         the role map is worth keeping. */
      var _pcRole = /\brole-(guardian|educator|home-visitor|sales-rep)\b/.test(document.body.className || '');
      var _wide = !window.matchMedia || window.matchMedia('(min-width: 769px)').matches;
      navRole.style.display = (_wide && !_pcRole) ? 'none' : '';
    }

    Dom.$('#navUser')?.addEventListener('click', () => {
      // A KiddieTrac dialog, not the browser's (which also froze the page in the app).
      (window.KT && KT.confirm
        ? KT.confirm({ title: 'Sign out?', description: 'You will need to sign in again to use KiddieTrac on this device.', okLabel: 'Sign out' })
        : Promise.resolve(window.confirm('Sign out?'))
      ).then((yes) => { if (yes) Auth.logout(); });
    });

    buildNav(user);
    try { _injectViewAs(user); } catch (e) {}

    window.addEventListener('hashchange', renderScreen);
    renderScreen();
  }

  /* ONE refresh for every avatar on the page — see the note at the nav user pill.
     Deduped while in flight, and released after 30s so a session long enough to outlive
     a second expiry can ask again. */
  var _photoRefresh = null;
  window.KT.refreshUserPhoto = function () {
    if (_photoRefresh) { return _photoRefresh; }
    var tok = null;
    try { tok = Auth.token(); } catch (e) {}
    if (!tok) { return Promise.resolve(null); }
    var api = (window.KT && window.KT.API_BASE) || 'https://api.kiddietrac.com/api/v1';
    _photoRefresh = fetch(api + '/auth/me', {
      headers: { 'Authorization': 'Bearer ' + tok, 'Accept': 'application/json' },
    }).then(function (r) {
      return r.ok ? r.json() : null;
    }).then(function (fresh) {
      if (!fresh || !fresh.photo_url) { return null; }
      try {
        /* kt_bio_user too: the biometric unlock copies it back into kt_user, so leaving it
           stale put the expired link back on every unlock (audit 2026-10-01: eleven 403s
           in two days, every one somebody's OWN avatar). */
        [['kt_user', window.sessionStorage], ['kt_user', window.localStorage], ['kt_bio_user', window.localStorage]].forEach(function (p) {
          var raw = p[1].getItem(p[0]);
          if (!raw) { return; }
          var u = JSON.parse(raw);
          if (!u || typeof u !== 'object') { return; }
          u.photo_url = fresh.photo_url;
          p[1].setItem(p[0], JSON.stringify(u));
        });
      } catch (e) {}
      return fresh.photo_url;
    }).catch(function () { return null; });
    _photoRefresh.then(function () {
      setTimeout(function () { _photoRefresh = null; }, 30000);
    });
    return _photoRefresh;
  };

  /* Refresh BEFORE the link dies, not after it 403s. The stored avatar link is signed and
     expires after 12-18 hours; an app that is only ever resumed keeps drawing it past
     that, the image fails, and only then is a new one fetched. On boot and on every
     resume, a link within 30 minutes of expiry is swapped for a fresh one, and any
     <img> already showing the old one is pointed at the new. */
  (function keepOwnPhotoFresh() {
    function stored() {
      try { return (JSON.parse(sessionStorage.getItem('kt_user') || localStorage.getItem('kt_user') || '{}') || {}).photo_url || ''; }
      catch (e) { return ''; }
    }
    function check() {
      var old = stored();
      var m = /[?&]expires=(\d+)/.exec(old);
      if (!m || Number(m[1]) * 1000 - Date.now() > 30 * 60000) { return; }
      window.KT.refreshUserPhoto().then(function (fresh) {
        if (!fresh || fresh === old) { return; }
        var host = ((window.KT && window.KT.API_BASE) || 'https://api.kiddietrac.com/api/v1').replace(/\/api\/v1\/?$/, '');
        var abs = function (u) { return /^https?:\/\//i.test(u) ? u : host + u; };
        var from = abs(old), to = abs(fresh);
        Array.prototype.forEach.call(document.images, function (img) {
          if (img.src === from) { img.src = to; }
        });
      });
    }
    setTimeout(check, 1500);
    document.addEventListener('visibilitychange', function () { if (!document.hidden) { check(); } });
  })();

  // Export
  window.KT.Shell = {
    /* The icon this screen goes by - its sidebar icon for this role, else the shell's table for screens reached
       another way (2026-10-28). kt-banner-normalize puts it on every banner, so a screen's banner always matches its
       sidebar entry and no screen falls through to the generic sparkle. */
    screenIcon: function (hash) {
      try {
        var u = JSON.parse(sessionStorage.getItem('kt_user') || localStorage.getItem('kt_user') || '{}');
        var h = String(hash || location.hash || '').replace(/^#/, '').split('?')[0];
        return (navItemFor(Roles.primaryRoleOf(u), h) || {}).icon || '';
      } catch (e) { return ''; }
    },
    // A registered screen's render function, so one screen can host another (2026-10-25, Accounting AR/AP -> Expenses).
    screenFn: function (name) { return screens[name] || null; },
    enforceOneBanner: function (m) { try { return enforceOneBanner(m || document.getElementById('appMain')); } catch (e) { return false; } },
    registerScreen,
    renderScreen,
    navigate,
    Modal,
    Roles,
    emptyState,
    statusTagFor,
    buildNav,
    setBadge,
    startApp,
    homeHashForRole,
    /* Exposed for the admin/director launcher (screen-admin-home.js), which builds its
       tiles from the role's REAL nav rather than a second hand-written copy of the
       menu. A copy would go stale the first time a screen was added, and could offer a
       role a screen it cannot open. */
    navItemsForRole,
    // Lift any action buttons out of the current screen's banner into the toolbar
    // beneath it. Screens that RE-RENDER themselves (e.g. a Refresh button that
    // rebuilds its own hero) must call this afterwards — the shell's automatic
    // normalisation is budget-capped per visit and its observer disconnects after a
    // few seconds, so a late self-re-render would otherwise leave the buttons sitting
    // inside the banner artwork. Idempotent: a no-op once the buttons are already out.
    liftHeroButtons: function (m) {
      try { return liftBannerButtons(m || document.getElementById('appMain')); }
      catch (e) { return false; }
    },
  };
  /* SCREENS THAT ARRIVED FIRST (2026-10-23, ticket #181). A screen file that runs before this
     one - a page loaded in the middle of a deploy - registered itself with the placeholder
     set up in dashboard.html's <head> instead of crashing on "registerScreen of undefined".
     Hand those registrations to the real shell now. */
  try {
    var _pend = window.__ktPendingScreens || [];
    window.__ktPendingScreens = [];
    _pend.forEach(function (p) { try { registerScreen(p[0], p[1]); } catch (e) {} });
  } catch (e) {}
})(window);
