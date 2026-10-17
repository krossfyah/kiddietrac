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
    'room-ratios', 'chat', 'files', 'notifications', 'admin-users', 'staff',
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

  /* YOUR OWN QUICK ACCESS (2026-10-16). Anthony: "quick access in mobile for directors/
     admins should allow to add remove functions". The eight defaults above are a starting
     point; ✏️ Customize lets each person add, remove and reorder their tiles. Kept per
     ROLE (a director and an agency-admin view of the same person want different things)
     in the account's ui-prefs, so it follows them to another phone, with a local copy so
     the launcher draws instantly. A hash this role cannot open is dropped on the way in —
     the tiles still come only from navItemsForRole. */
  var QA_MAX = 16;
  var QA_LOCAL = 'kt_quick_access';
  function qaUserId() {
    try {
      var u = JSON.parse(sessionStorage.getItem('kt_user') || localStorage.getItem('kt_user') || '{}');
      return u && u.id ? String(u.id) : '';
    } catch (e) { return ''; }
  }
  function qaReadLocal(role) {
    try {
      var all = JSON.parse(localStorage.getItem(QA_LOCAL) || '{}') || {};
      var mine = all[qaUserId()] || {};
      return Array.isArray(mine[role]) ? mine[role] : null;
    } catch (e) { return null; }
  }
  function qaWriteLocal(map) {
    try {
      var all = JSON.parse(localStorage.getItem(QA_LOCAL) || '{}') || {};
      all[qaUserId()] = map;
      localStorage.setItem(QA_LOCAL, JSON.stringify(all));
    } catch (e) {}
  }
  function qaLocalMap() {
    try { return (JSON.parse(localStorage.getItem(QA_LOCAL) || '{}') || {})[qaUserId()] || {}; } catch (e) { return {}; }
  }
  function qaSave(role, list) {
    var map = qaLocalMap();
    if (list) map[role] = list; else delete map[role];
    qaWriteLocal(map);
    try { if (KT.Api && KT.Api.put) KT.Api.put('/auth/me/ui-prefs', { quick_access: Object.keys(map).length ? map : null }).catch(function () {}); } catch (e) {}
  }

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

    function defaultQuick() {
      var out = [];
      var wanted = isSuperAdmin() ? QUICK_PLATFORM.concat(QUICK) : QUICK;
      wanted.forEach(function (h) {
        if (byHash[h] && out.length < 8 && out.indexOf(byHash[h]) === -1) { out.push(byHash[h]); }
      });
      return out;
    }
    function quickFrom(hashes) {
      var out = [];
      (hashes || []).forEach(function (h) { if (byHash[h] && out.indexOf(byHash[h]) === -1 && out.length < QA_MAX) out.push(byHash[h]); });
      return out;
    }
    var saved = qaReadLocal(role);
    var customised = !!saved;
    var quick = saved ? quickFrom(saved) : defaultQuick();

    var openMap = openState();
    function section(key, label, items, id, openByDefault) {
      var isOpen = Object.prototype.hasOwnProperty.call(openMap, key) ? !!openMap[key] : openByDefault;
      return '<section class="kt-ah-sec" data-label="' + esc(key) + '">'
        + '<button type="button" class="kt-ah-head" aria-expanded="' + (isOpen ? 'true' : 'false')
        + '" aria-controls="' + id + '">'
        + '<span>' + esc(label) + '</span>'
        + '<span class="kt-ah-count">' + items.length + '</span>'
        + '<span class="kt-ah-chev" aria-hidden="true">▾</span>'
        + '</button>'
        + '<div class="kt-tile-grid kt-ah-body" id="' + id + '"' + (isOpen ? '' : ' hidden') + '>'
        + items.map(tile).join('')
        + '</div></section>';
    }
    // First section open by default; the rest remember what you left them at.
    var body = secs.map(function (s, i) { return section(s.label, s.label, s.items, 'kt-ah-' + i, i === 0); }).join('');

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
      /* Quick access folds like every other section (open until you close it). It sits
         outside #kt-ah-sections so search can hide it on its own. */
      + '<div class="kt-ah-quick"></div>'
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

    /* WHY [hidden] NEEDS A RULE. The section bodies are .kt-tile-grid, and
       kt-topnav-fix.css gives that `display: grid` — an author rule, which beats the
       browser's own `[hidden] { display: none }`. So the button flipped `hidden` and
       the chevron, and the tiles stayed on screen: no section ever collapsed.
       (Anthony, 2026-10-15) */
    if (!document.getElementById('kt-admin-home-style')) {
      var _st = document.createElement('style'); _st.id = 'kt-admin-home-style';
      _st.textContent = '.kt-admin-home [hidden]{display:none !important;}'
        /* Each section is a card with the same soft gradient as the educator/parent
           Quick access (screen-role-home.js .kt-quickaccess), so the page is not a
           wall of white. `section.` out-specifies kt-mobile-app.css's .kt-ah-sec. */
        + '.kt-admin-home section.kt-ah-sec{background:radial-gradient(120% 95% at 10% 6%,rgba(21,159,180,.16) 0%,rgba(21,159,180,0) 46%),radial-gradient(115% 90% at 92% 14%,rgba(124,58,237,.15) 0%,rgba(124,58,237,0) 50%),radial-gradient(130% 105% at 82% 100%,rgba(245,158,11,.14) 0%,rgba(245,158,11,0) 55%),radial-gradient(120% 100% at 14% 102%,rgba(236,72,153,.11) 0%,rgba(236,72,153,0) 55%),linear-gradient(135deg,#FBFDFF 0%,#FAF8FE 100%);border:1px solid rgba(124,58,237,.09);border-radius:18px;padding:2px 12px;margin:0 0 10px;box-shadow:0 8px 22px -14px rgba(31,96,128,.28);}'
        + '.kt-admin-home section.kt-ah-sec .kt-ah-head{border-bottom-color:rgba(124,58,237,.08);}'
        + '.kt-admin-home section.kt-ah-sec .kt-ah-head[aria-expanded="false"]{border-bottom-color:transparent;}'
        + '.kt-admin-home section.kt-ah-sec .kt-ah-body{margin:10px 0 12px;}'
        + '.kt-admin-home section.kt-ah-sec .kt-ah-count{background:rgba(255,255,255,.75);}'
        + '.kt-qa-tools{display:flex;gap:8px;align-items:center;justify-content:flex-end;flex-wrap:wrap;margin:-4px 0 10px;}'
        /* PHONE SIZES (2026-10-16). kt-mobile-app.css gives every `#appMain button` a
           42px min-height under 768px, which drew the 26px ✕ as a 26×42 red oval and the
           ◀ ▶ as tall slabs ("the x to remove looks stretched"). These controls have their
           own sizes, so they opt out by name; `button.` + !important beats that rule's
           #appMain specificity. And `font: … inherit` is not valid shorthand (the family
           cannot be `inherit` there), so the whole declaration was being dropped. */
        /* Not scoped to .kt-admin-home: the + Add picker's buttons live in the modal. */
        + 'button.kt-qa-btn{height:32px;min-height:0 !important;padding:0 12px;border-radius:999px;border:1px solid #CBD5E1;background:#fff;color:#1F6080;font-family:inherit;font-weight:700;font-size:12.5px;line-height:1;cursor:pointer;flex:0 0 auto;}'
        + 'button.kt-qa-btn.p{background:#1F6080;border-color:#1F6080;color:#fff;}'
        + 'button.kt-qa-btn:disabled{opacity:.45;cursor:default;}'
        + '.kt-qa-editing .kt-tile{position:relative;}'
        + '.kt-qa-editing .kt-tile .kt-tile-icon{animation:kt-qa-wiggle .9s ease-in-out infinite;}'
        + '@keyframes kt-qa-wiggle{0%,100%{transform:rotate(-2deg);}50%{transform:rotate(2deg);}}'
        + '@media (prefers-reduced-motion:reduce){.kt-qa-editing .kt-tile .kt-tile-icon{animation:none;}}'
        + '.kt-admin-home button.kt-qa-x{position:absolute;top:2px;right:2px;width:26px !important;height:26px !important;min-width:0 !important;min-height:0 !important;border-radius:50%;border:2px solid #fff;background:#DC2626;color:#fff;font-family:inherit;font-weight:800;font-size:13px;line-height:1;display:flex;align-items:center;justify-content:center;cursor:pointer;z-index:2;box-shadow:0 2px 6px rgba(0,0,0,.25);padding:0;}'
        + '.kt-qa-mv{display:flex;gap:4px;justify-content:center;margin-top:4px;}'
        + '.kt-admin-home .kt-qa-mv button{width:34px;height:28px;min-width:0 !important;min-height:0 !important;border-radius:8px;border:1px solid #CBD5E1;background:#fff;color:#334155;font-family:inherit;font-weight:700;font-size:12px;line-height:1;cursor:pointer;padding:0;}'
        + '.kt-qa-mv button:disabled{opacity:.35;cursor:default;}'
        + '.kt-qa-add .kt-tile-icon{border:2px dashed #94A3B8 !important;background:#F8FAFC !important;color:#1F6080;}'
        + '.kt-qa-pick{display:flex;align-items:center;gap:10px;padding:9px 4px;border-bottom:1px solid #F1F5F9;}'
        + '.kt-qa-pick .ic{width:30px;text-align:center;font-size:20px;}'
        + '.kt-qa-pick .nm{flex:1;min-width:0;font-weight:700;font-size:14px;color:#0F172A;}'
        + '.kt-qa-pick .sc{font-weight:500;font-size:12px;color:#64748B;}';
      document.head.appendChild(_st);
    }

    /* ── Quick access: normal and ✏️ Customize ──────────────────────────────── */
    var qaEditing = false;
    var sectionOf = {};
    secs.forEach(function (sc) { sc.items.forEach(function (it) { if (it && it.hash && !sectionOf[it.hash]) sectionOf[it.hash] = sc.label; }); });
    function qaPersist() { customised = true; qaSave(role, quick.map(function (it) { return it.hash; })); }
    function paintQuick() {
      var wrap = main.querySelector('.kt-ah-quick');
      if (!wrap) return;
      openMap = openState();   // the open/closed state as it is now, not as it was at first paint
      wrap.innerHTML = section('__quick', 'Quick access', quick, 'kt-ah-quickgrid', true);
      var sec = wrap.querySelector('.kt-ah-sec');
      var grid = sec.querySelector('.kt-ah-body');
      var head = sec.querySelector('.kt-ah-head');
      head.addEventListener('click', function () {
        var open = grid.hidden;
        grid.hidden = !open;
        tools.hidden = !open;
        head.setAttribute('aria-expanded', open ? 'true' : 'false');
        var map = openState(); map.__quick = open; rememberOpen(map);
      });
      if (qaEditing) {
        sec.classList.add('kt-qa-editing');
        /* While someone is arranging tiles, no refresher may rebuild the screen under
           them (it would come back with Customize off). uiBusy() defers on this marker;
           Done repaints without it and any held refresh then runs. */
        sec.setAttribute('data-kt-no-autorefresh', '');
        [].forEach.call(grid.querySelectorAll('.kt-tile'), function (t, i) {
          t.removeAttribute('href');
          t.setAttribute('role', 'group');
          var x = document.createElement('button');
          x.type = 'button'; x.className = 'kt-qa-x'; x.textContent = '✕';
          x.setAttribute('aria-label', 'Remove ' + (quick[i].label || quick[i].hash) + ' from Quick access');
          x.addEventListener('click', function (e) { e.preventDefault(); e.stopPropagation(); quick.splice(i, 1); qaPersist(); paintQuick(); });
          t.appendChild(x);
          var mv = document.createElement('div'); mv.className = 'kt-qa-mv';
          mv.innerHTML = '<button type="button" data-mv="-1" aria-label="Move earlier">◀</button><button type="button" data-mv="1" aria-label="Move later">▶</button>';
          mv.querySelector('[data-mv="-1"]').disabled = i === 0;
          mv.querySelector('[data-mv="1"]').disabled = i === quick.length - 1;
          [].forEach.call(mv.querySelectorAll('button'), function (b) {
            b.addEventListener('click', function (e) {
              e.preventDefault(); e.stopPropagation();
              var j = i + parseInt(b.getAttribute('data-mv'), 10);
              if (j < 0 || j >= quick.length) return;
              var tmp = quick[i]; quick[i] = quick[j]; quick[j] = tmp;
              qaPersist(); paintQuick();
            });
          });
          t.appendChild(mv);
        });
        if (quick.length < QA_MAX) {
          var add = document.createElement('a');
          add.className = 'kt-tile kt-qa-add'; add.setAttribute('role', 'button'); add.tabIndex = 0;
          add.innerHTML = '<span class="kt-tile-icon" aria-hidden="true">＋</span><span class="kt-tile-label">Add</span>';
          add.addEventListener('click', function (e) { e.preventDefault(); openPicker(); });
          grid.appendChild(add);
        }
      }
      var tools = document.createElement('div');
      tools.className = 'kt-qa-tools';
      tools.hidden = grid.hidden;
      tools.innerHTML = qaEditing
        ? '<span style="font-size:12px;color:#64748B;margin-right:auto;">' + quick.length + ' of ' + QA_MAX + ' · tap ✕ to remove, ◀ ▶ to move</span>'
          + (customised ? '<button type="button" class="kt-qa-btn" data-qa="reset">Reset to default</button>' : '')
          + '<button type="button" class="kt-qa-btn p" data-qa="done">Done</button>'
        : '<button type="button" class="kt-qa-btn" data-qa="edit">✏️ Customize</button>';
      sec.appendChild(tools);
      [].forEach.call(tools.querySelectorAll('[data-qa]'), function (b) {
        b.addEventListener('click', function () {
          var act = b.getAttribute('data-qa');
          if (act === 'edit') { qaEditing = true; var m = openState(); m.__quick = true; rememberOpen(m); paintQuick(); }
          else if (act === 'done') { qaEditing = false; paintQuick(); }
          else if (act === 'reset') { quick = defaultQuick(); customised = false; qaSave(role, null); paintQuick(); }
        });
      });
      if (!quick.length && !qaEditing) {
        var none = document.createElement('div');
        none.style.cssText = 'font-size:13px;color:#64748B;padding:4px 2px 10px;';
        none.textContent = 'Nothing pinned. Tap ✏️ Customize to add the places you use most.';
        grid.appendChild(none);
      }
    }
    /* + Add: every place this role has, searchable, grouped by its menu section. */
    function openPicker() {
      var M = KT.Shell && KT.Shell.Modal;
      if (!M || !M.open) return;
      var body = document.createElement('div');
      body.innerHTML = '<input type="search" class="kt-qa-find" placeholder="🔍 Find a place…" autocomplete="off" '
        + 'style="display:block;width:100%;box-sizing:border-box;height:40px;padding:0 12px;border:1px solid #CBD5E1;border-radius:10px;font-size:14px;margin-bottom:8px;">'
        + '<div class="kt-qa-list"></div>';
      var list = body.querySelector('.kt-qa-list');
      var find = body.querySelector('.kt-qa-find');
      function paintList() {
        var q = (find.value || '').trim().toLowerCase();
        var all = Object.keys(byHash).map(function (h) { return byHash[h]; }).filter(function (it) {
          return !q || ((it.label || '') + ' ' + it.hash + ' ' + (sectionOf[it.hash] || '')).toLowerCase().indexOf(q) !== -1;
        });
        list.innerHTML = all.length ? all.map(function (it) {
          var on = quick.indexOf(it) !== -1;
          return '<div class="kt-qa-pick"><span class="ic" aria-hidden="true">' + (it.icon || '▫️') + '</span>'
            + '<span class="nm">' + esc(it.label || it.hash) + '<div class="sc">' + esc(sectionOf[it.hash] || '') + '</div></span>'
            + '<button type="button" class="kt-qa-btn' + (on ? '' : ' p') + '" data-h="' + esc(it.hash) + '"'
            + (!on && quick.length >= QA_MAX ? ' disabled' : '') + '>' + (on ? '✓ Added' : '+ Add') + '</button></div>';
        }).join('') : '<div style="padding:12px;color:#64748B;">Nothing matches that.</div>';
        [].forEach.call(list.querySelectorAll('[data-h]'), function (b) {
          b.addEventListener('click', function () {
            var it = byHash[b.getAttribute('data-h')];
            var k = quick.indexOf(it);
            if (k === -1) { if (quick.length >= QA_MAX) return; quick.push(it); } else { quick.splice(k, 1); }
            qaPersist(); paintQuick(); paintList();
          });
        });
      }
      find.addEventListener('input', paintList);
      paintList();
      M.open({ title: 'Add to Quick access', body: body, actions: [{ label: 'Done', primary: true }] });
    }
    paintQuick();
    /* The account copy wins when it differs: chosen on another phone, it should show here. */
    try {
      if (KT.Api && KT.Api.get) KT.Api.get('/auth/me/ui-prefs').then(function (j) {
        var map = j && j.prefs && j.prefs.quick_access;
        if (!map || typeof map !== 'object') return;
        var local = qaLocalMap();
        qaWriteLocal(map);
        if (JSON.stringify(map[role] || null) === JSON.stringify(local[role] || null) || qaEditing) return;
        if (!main.isConnected) return;
        customised = Array.isArray(map[role]);
        quick = customised ? quickFrom(map[role]) : defaultQuick();
        paintQuick();
      }).catch(function () {});
    } catch (e) {}

    /* ── collapse / expand ────────────────────────────────────────────────── */
    main.querySelectorAll('#kt-ah-sections .kt-ah-head').forEach(function (h) {
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
        var open = (was === undefined) ? (sec === main.querySelector('#kt-ah-sections .kt-ah-sec')) : !!was;
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
