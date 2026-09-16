/* ═══════════════════════════════════════════════════════════════════
   KIDDIETRAC — Admin / director launcher home

   WHY THIS EXISTS. Guardians, educators, auditors and sales reps all had a tile
   launcher (screen-role-home.js). Agency admins and centre directors did not — their
   only route to anything on a phone was the ☰ drawer, which reveals the DESKTOP
   sidebar: 94 items for an agency admin, in one scrolling list, with no search.
   kt-mobilenav.js says so itself, in the comment above the Menu button: "without this
   the bar's four buttons are the ONLY reachable sections on a phone — Children,
   Billing, Settings, Centres, Staff, Reports, etc. are all stranded."

   So this is not a tidier version of the drawer. It is the thing the drawer was
   standing in for: search first, then the eight places you actually go, then
   everything else grouped the way the sidebar groups it, collapsed by default.

   BUILT FROM THE NAV, NOT FROM A LIST. Every tile comes from
   KT.Shell.navItemsForRole(role) — the same function that builds the sidebar. A
   hand-written tile list would be a second copy of the menu that goes stale the first
   time somebody adds a screen, and worse, could offer a role a screen it cannot open.
   Here that is impossible by construction: if it is not in your sidebar, it is not on
   your launcher.
   ═══════════════════════════════════════════════════════════════════ */
(function (window) {
  'use strict';
  var KT = window.KT || (window.KT = {});
  if (!KT || !KT.Shell || !KT.Shell.registerScreen) return;
  var Shell = KT.Shell;

  /* The eight an admin or director actually opens, in order of how often. Filtered
     against the role's real nav below, so a hash this role does not have simply does
     not appear rather than becoming a dead tile. Deliberately short: a "quick access"
     row of twenty is just the menu again. */
  var QUICK = [
    'dashboard', 'admin-children', 'children', 'admin-families', 'families',
    'room-ratios', 'chat', 'notifications', 'admin-users', 'staff',
    'reports', 'incidents', 'audit-logs', 'care-log',
  ];

  /* What a platform operator opens, ahead of the tenant tiles. Filtered against the
     role's real nav like the rest, so nothing here can become a dead tile. */
  var QUICK_PLATFORM = ['agencies', 'admin-mrr', 'sales-invoices', 'admin-features', 'maintenance', 'marketing-site'];

  /* Sections that belong to running the platform rather than running a centre. Matched
     by label against navItemsForRole's output. */
  var PLATFORM_SECTIONS = ['reseller', 'website'];

  /* A superadmin in their own right -- not a platform_admin currently viewing as an
     agency admin, who should get the ordinary tenant launcher. */
  function isSuperAdmin() {
    try {
      var u = JSON.parse(sessionStorage.getItem('kt_user') || localStorage.getItem('kt_user') || '{}');
      var va = sessionStorage.getItem('kt_view_as') || '';
      return Array.isArray(u.roles) && u.roles.indexOf('platform_admin') !== -1
        && (!va || va === 'platform_admin');
    } catch (e) { return false; }
  }

  var COLLAPSE_KEY = 'kt_admin_home_open';

  function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
      return ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c];
    });
  }

  function sectionsFor(role) {
    try {
      var secs = (Shell.navItemsForRole && Shell.navItemsForRole(role)) || [];
      secs = secs.filter(function (s) { return s && s.items && s.items.length; });
      if (!isSuperAdmin()) { return secs; }
      // Stable partition: platform sections first, everything else in its original order.
      var plat = [], rest = [];
      secs.forEach(function (sec) {
        var label = String(sec.label || '').toLowerCase();
        (PLATFORM_SECTIONS.indexOf(label) !== -1 ? plat : rest).push(sec);
      });
      return plat.concat(rest);
    } catch (e) { return []; }
  }

  function openState() {
    try { return JSON.parse(localStorage.getItem(COLLAPSE_KEY) || '{}') || {}; }
    catch (e) { return {}; }
  }
  function rememberOpen(map) {
    try { localStorage.setItem(COLLAPSE_KEY, JSON.stringify(map)); } catch (e) {}
  }

  function tile(it) {
    var label = it.label || it.hash;
    return '<a class="kt-tile" href="#' + esc(it.hash) + '"'
      + ' data-find="' + esc((label + ' ' + (it.hash || '')).toLowerCase()) + '"'
      + ' aria-label="' + esc(label) + '">'
      + '<span class="kt-tile-icon" aria-hidden="true">' + (it.icon || '▫️') + '</span>'
      + '<span class="kt-tile-label">' + esc(label) + '</span>'
      + '</a>';
  }

  function renderAdminHome(main, ctx) {
    var role = (ctx && ctx.role) || 'agency_admin';
    var secs = sectionsFor(role);

    var first = '';
    try {
      var u = (ctx && ctx.user) || (KT.Auth && KT.Auth.user && KT.Auth.user()) || {};
      first = ((u.first_name || u.name || '') + '').split(' ')[0];
    } catch (e) {}
    var greet = KT.greetingForNow ? KT.greetingForNow(first) : ('Welcome' + (first ? ', ' + first : ''));

    // Every item once, so Quick access can be picked from what this role really has.
    var byHash = {};
    secs.forEach(function (s) {
      s.items.forEach(function (it) { if (it && it.hash && !byHash[it.hash]) byHash[it.hash] = it; });
    });
    var total = Object.keys(byHash).length;

    var quick = [];
    var wanted = isSuperAdmin() ? QUICK_PLATFORM.concat(QUICK) : QUICK;
    wanted.forEach(function (h) {
      if (byHash[h] && quick.length < 8 && quick.indexOf(byHash[h]) === -1) { quick.push(byHash[h]); }
    });

    var openMap = openState();
    var body = secs.map(function (s, i) {
      var id = 'kt-ah-' + i;
      // First section open by default; the rest remember what you left them at.
      var isOpen = Object.prototype.hasOwnProperty.call(openMap, s.label) ? !!openMap[s.label] : (i === 0);
      return '<section class="kt-ah-sec" data-label="' + esc(s.label) + '">'
        + '<button type="button" class="kt-ah-head" aria-expanded="' + (isOpen ? 'true' : 'false')
        + '" aria-controls="' + id + '">'
        + '<span>' + esc(s.label) + '</span>'
        + '<span class="kt-ah-count">' + s.items.length + '</span>'
        + '<span class="kt-ah-chev" aria-hidden="true">▾</span>'
        + '</button>'
        + '<div class="kt-tile-grid kt-ah-body" id="' + id + '"' + (isOpen ? '' : ' hidden') + '>'
        + s.items.map(tile).join('')
        + '</div></section>';
    }).join('');

    main.innerHTML =
      '<div class="kt-tilehome kt-admin-home">'
      + '<div class="kt-hero kt-hero-tiles">'
      +   '<div class="kt-hero-greet">' + esc(greet) + '</div>'
      +   '<h1>Everything</h1>'
      +   '<div class="kt-hero-sub">' + total + ' places, grouped. Search, or tap a section.</div>'
      + '</div>'
      + '<div class="kt-ah-searchwrap">'
      +   '<input id="kt-ah-search" type="search" autocomplete="off" placeholder="Search…" '
      +     'aria-label="Search every section" class="kt-ah-search">'
      + '</div>'
      + '<div id="kt-ah-results" class="kt-ah-body" hidden></div>'
      + '<div id="kt-ah-noresult" class="kt-ah-noresult" hidden>Nothing matches that.</div>'
      + (quick.length
          ? '<div class="kt-ah-quick"><div class="kt-tile-sectionhead">Quick access</div>'
            + '<div class="kt-tile-grid">' + quick.map(tile).join('') + '</div></div>'
          : '')
      + '<div id="kt-ah-sections">' + body + '</div>'
      /* The same footer parents and educators have. Their launcher carries sign-out
         because the phone bar has no Menu; neither does this one now. */
      + '<button id="kt-home-signout" type="button" data-kt-iconized="1"'
      +   ' style="display:block;margin:22px auto 6px;background:none;border:none;'
      +   'color:#64748B;font:600 13px/1 inherit;cursor:pointer;padding:8px 14px;">'
      +   '\uD83C\uDFC3 Sign out</button>'
      + '</div>';

    /* Sign out — KT.signOut is the parent/educator launcher's implementation, shared
       rather than copied: it preserves biometric enrolment and blocks the auto-relogin
       that otherwise made you sign out twice. */
    var _so = main.querySelector('#kt-home-signout');
    if (_so) {
      _so.addEventListener('click', function () {
        if (window.KT && typeof KT.signOut === 'function') { KT.signOut(); return; }
        try { sessionStorage.clear(); } catch (e) {}
        location.href = '/index.html';
      });
    }

    /* ── collapse / expand ────────────────────────────────────────────────── */
    main.querySelectorAll('.kt-ah-head').forEach(function (h) {
      h.addEventListener('click', function () {
        var sec = h.parentNode;
        var grid = sec.querySelector('.kt-ah-body');
        var open = grid.hidden;
        grid.hidden = !open;
        h.setAttribute('aria-expanded', open ? 'true' : 'false');
        var map = openState();
        map[sec.getAttribute('data-label')] = open;
        rememberOpen(map);
      });
    });

    /* ── search ───────────────────────────────────────────────────────────────
       Filters across every section at once and opens the sections that still have a
       hit, so a match is never hidden inside a collapsed group. Clearing the box puts
       the sections back the way they were. */
    var input = main.querySelector('#kt-ah-search');
    var nores = main.querySelector('#kt-ah-noresult');
    var quickWrap = main.querySelector('.kt-ah-quick');
    var results = main.querySelector('#kt-ah-results');
    var sectionsWrap = main.querySelector('#kt-ah-sections');

    /* SEARCHING IS NOT FILTERING.

       This used to hide the tiles that did not match and leave the rest exactly where
       they were — so the answer stayed buried in whichever section it belongs to, under
       every earlier section that also had a hit, and the match was a single contiguous
       substring so "audit logs" found nothing at all.

       Now a query replaces the sections with ONE list, best first, using the same ranker
       the sidebar widget and the palette use (KT.navSearch: word-by-word, hash included,
       plural-tolerant, sorted before it is capped). Clearing the box puts the sections
       back exactly as they were. (Anthony, 2026-09-15) */
    function restoreSections() {
      results.hidden = true;
      results.innerHTML = '';
      sectionsWrap.hidden = false;
      main.querySelectorAll('#kt-ah-sections .kt-ah-sec').forEach(function (sec) {
        sec.hidden = false;
        sec.querySelectorAll('.kt-tile').forEach(function (t) { t.hidden = false; });
        var grid = sec.querySelector('.kt-ah-body');
        var head = sec.querySelector('.kt-ah-head');
        var map = openState();
        var was = map[sec.getAttribute('data-label')];
        var open = (was === undefined) ? (sec === main.querySelector('.kt-ah-sec')) : !!was;
        grid.hidden = !open;
        head.setAttribute('aria-expanded', open ? 'true' : 'false');
      });
    }

    input.addEventListener('input', function () {
      var q = (input.value || '').trim();
      var searching = q.length > 0;
      if (quickWrap) quickWrap.hidden = searching;

      if (!searching) {
        nores.hidden = true;
        restoreSections();
        return;
      }

      var hits = (window.KT && KT.navSearch) ? KT.navSearch(q, { limit: 20 }) : null;

      if (!hits) {
        /* The ranker is not loaded. Rather than show nothing, fall back to the old
           substring filter — worse, but still a working search. */
        var shown = 0;
        sectionsWrap.hidden = false;
        results.hidden = true;
        var ql = q.toLowerCase();
        main.querySelectorAll('#kt-ah-sections .kt-ah-sec').forEach(function (sec) {
          var n = 0;
          sec.querySelectorAll('.kt-tile').forEach(function (t) {
            var hit = t.getAttribute('data-find').indexOf(ql) !== -1;
            t.hidden = !hit;
            if (hit) { n++; }
          });
          sec.hidden = n === 0;
          sec.querySelector('.kt-ah-body').hidden = false;
          sec.querySelector('.kt-ah-head').setAttribute('aria-expanded', 'true');
          shown += n;
        });
        nores.hidden = shown !== 0;
        return;
      }

      sectionsWrap.hidden = true;
      results.innerHTML = hits.map(function (h) {
        return tile({ label: h.label, hash: String(h.hash || '').replace(/^#/, ''), icon: h.icon });
      }).join('');
      results.hidden = hits.length === 0;
      nores.hidden = hits.length !== 0;
    });
  }

  /* platform_admin renders with the agency_admin nav (see navItemsForRole), so the
     same launcher serves all three. */
  Shell.registerScreen('agency_admin:home', renderAdminHome);
  Shell.registerScreen('centre_director:home', renderAdminHome);
  Shell.registerScreen('platform_admin:home', renderAdminHome);

  KT.AdminHome = { render: renderAdminHome };
})(window);
