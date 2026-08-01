/* ===================================================================
   KiddieTrac — Simple icon-tile HOME for Parent (guardian) + Educator.

   A "dead simple" launcher: big tappable icon tiles for direct access to
   the functions that matter to each role, on desktop and mobile. Each tile
   just links to the existing screen hash, so no screen logic is duplicated.
   The long tail sits behind a "More" tile so the default view stays clean.
   =================================================================== */
(function (window) {
  'use strict';
  var KT = window.KT;
  if (!KT || !KT.Shell || !KT.Shell.registerScreen) return;
  var Shell = KT.Shell;

  // Global avatar helper — initials in a deterministic colour, or a photo.
  // Usage: KT.avatar('Jane Doe', { size: 32, photoUrl: '/storage/...' }) → HTML string.
  if (!KT.avatar) {
    KT.avatar = function (name, opts) {
      opts = opts || {};
      var size = opts.size || 32;
      var nm = String(name == null ? '' : name).trim();
      var initials = (nm.split(/\s+/).map(function (w) { return w.charAt(0); }).slice(0, 2).join('') || '?').toUpperCase();
      var palette = ['#7C3AED', '#E91E8C', '#0EA5E9', '#10B981', '#F59E0B', '#EF4444', '#8B5CF6', '#0f9d6b', '#DB2777', '#0891B2'];
      var h = 0; for (var i = 0; i < nm.length; i++) { h = (h * 31 + nm.charCodeAt(i)) >>> 0; }
      var bg = palette[h % palette.length];
      var base = 'width:' + size + 'px;height:' + size + 'px;border-radius:50%;flex-shrink:0;display:inline-flex;align-items:center;justify-content:center;font-weight:700;font-size:' + Math.round(size * 0.4) + 'px;color:#fff;background:' + bg + ';overflow:hidden;vertical-align:middle;';
      var p = opts.photoUrl;
      if (p) return '<span class="kt-avatar" title="' + nm.replace(/"/g, '') + '" style="' + base + 'background-image:url(' + p + ');background-size:cover;background-position:center;"></span>';
      return '<span class="kt-avatar" title="' + nm.replace(/"/g, '') + '" style="' + base + '">' + initials + '</span>';
    };
  }

  // Curated, role-relevant tiles. `primary` shows by default; `more` is
  // revealed by the "More" tile.
  var TILES = {
    guardian: {
      title: 'My family',
      sub: 'Everything in one place',
      primary: [
        { hash: 'today',              icon: '✨', label: 'Today',        sub: "Your child's day" },
        { hash: 'photos',             icon: '📸', label: 'Photos' },
        { hash: 'messages',           icon: '💬', label: 'Messages' },
        { hash: 'my-tasks',           icon: '📋', label: 'My tasks' },
        { hash: 'menu',               icon: '🍽️', label: 'Menu' },
        { hash: 'notifications',      icon: '🔔', label: 'Inbox' },
        { hash: 'scan',               icon: '📷', label: 'Check in / out', sub: 'Scan the QR' },
        { hash: 'parent-forms',       icon: '📝', label: 'Forms' },
        { hash: 'billing',            icon: '💳', label: 'Billing' },
        { hash: 'attendance-pattern', icon: '📅', label: 'Attendance' },
        { hash: 'medications',        icon: '💊', label: 'Health' },
        { hash: 'announcements',      icon: '📢', label: 'News' },
        { hash: 'support',            icon: '🛟', label: 'Support' },
        { hash: 'help',               icon: '📖', label: 'Help' },
      ],
      more: [
        { hash: 'videos',         icon: '🎬', label: 'Videos' },
        { hash: 'directory',      icon: '👪', label: 'Family directory' },
        { hash: 'conferences',    icon: '🗣', label: 'Conferences' },
        { hash: 'signed-docs',    icon: '✍️', label: 'Documents' },
        { hash: 'immunizations',  icon: '🩹', label: 'Immunizations' },
        { hash: 'wellness',       icon: '🩺', label: 'Wellness check' },
        { hash: 'pickup-auth',    icon: '🪪', label: 'Pickup people' },
        { hash: 'trends',         icon: '📊', label: 'Trends' },
        { hash: 'payment-plans',  icon: '🗓️', label: 'Payment plans' },
        { hash: 'ledger',         icon: '📒', label: 'Account ledger' },
        { hash: 'referrals',      icon: '🎁', label: 'Refer a friend' },
        { hash: 'withdraw',       icon: '🚸', label: 'Withdraw from care' },
      ],
    },
    educator: {
      title: 'My classroom',
      sub: 'Everything in one place',
      primary: [
        { hash: 'today',         icon: '✨', label: 'Today',        sub: 'Rooms & children' },
        { hash: 'my-tasks',      icon: '📋', label: 'My tasks' },
        { hash: 'children',      icon: '🧒', label: 'Child records' },
        { hash: 'care-log',      icon: '✅', label: 'Daily log' },
        { hash: 'observations',  icon: '👀', label: 'Observations' },
        { hash: 'lesson-plans',  icon: '📚', label: 'Lesson plans' },
        { hash: 'menu',          icon: '🍽️', label: 'Weekly menu' },
        { hash: 'chat',          icon: '💬', label: 'Messages' },
        { hash: 'forms',         icon: '📝', label: 'Forms' },
        { hash: 'incidents',     icon: '⚠️', label: 'Incidents' },
        { hash: 'late-pickups',  icon: '⏰', label: 'Late pickup' },
        { hash: 'medications',   icon: '💊', label: 'Medications' },
        { hash: 'time-clock',    icon: '⏱', label: 'Clock in/out' },
        { hash: 'my-schedule',   icon: '📅', label: 'My calendar' },
        { hash: 'my-hours',      icon: '💰', label: 'My hours' },
        { hash: 'time-off',      icon: '🌴', label: 'Time off' },
        { hash: 'announcements', icon: '📢', label: 'News' },
        { hash: 'notifications', icon: '🔔', label: 'Inbox' },
        { hash: 'support',       icon: '🛟', label: 'Support' },
        { hash: 'settings',      icon: '⚙️', label: 'Settings' },
        { hash: 'help',          icon: '📖', label: 'Help' },
      ],
      more: [],
    },
    auditor: {
      title: 'Audit & compliance',
      sub: 'Read-only · tap to open',
      primary: [
        { hash: 'compliance',  icon: '✅', label: 'Compliance' },
        { hash: 'audit-logs',  icon: '📋', label: 'Audit logs' },
        { hash: 'children',    icon: '🧒', label: 'Children' },
        { hash: 'forms',       icon: '📝', label: 'Forms' },
        { hash: 'help',        icon: '📖', label: 'Help' },
      ],
      more: [],
    },
    sales_rep: {
      title: 'Sales',
      sub: 'Your pipeline at a glance',
      primary: [
        { hash: 'sales',           icon: '📊', label: 'Pipeline',        sub: 'Track your deals' },
        { hash: 'sales-leads',     icon: '🎯', label: 'Leads' },
        { hash: 'sales-new',       icon: '➕', label: 'New lead' },
        { hash: 'sales-followups', icon: '⏰', label: 'Follow-ups' },
        { hash: 'sales-plans',     icon: '💲', label: 'Plans & pricing' },
        { hash: 'sales-demo',      icon: '🚀', label: 'Launch demo' },
        { hash: 'notifications',   icon: '🔔', label: 'Inbox' },
        { hash: 'mfa',             icon: '🔐', label: 'Two-factor' },
        { hash: 'settings',        icon: '⚙️', label: 'Settings' },
        { hash: 'help',            icon: '📖', label: 'Help' },
      ],
      more: [],
    },
  };

  function esc(s) {
    return String(s == null ? '' : s)
      .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
  }

  // Brief hover descriptions (title tooltip) per tile, so people can see what
  // each icon does. Falls back to a tile's own `sub`, then its label.
  var TILE_DESC = {
    today: "Your child's day at a glance", photos: 'Photos & videos shared by the centre',
    messages: 'Message your centre team', chat: 'Message families and staff',
    notifications: 'Your alerts and inbox', scan: 'Scan the QR to check your child in or out',
    'parent-forms': 'Forms to review and sign', forms: 'Forms and documents',
    billing: 'Invoices and payments', 'attendance-pattern': "Your child's attendance history",
    attendance: 'Attendance records', medications: 'Medications & health info',
    announcements: 'Latest news from your centre', support: 'Get help or report an issue',
    help: 'Guides and answers', children: 'Child records & details',
    'my-tasks': 'Tasks assigned to you', tasks: 'Assign and track educator tasks',
    'care-log': 'Log daily care moments', observations: 'Learning observations',
    'lesson-plans': 'Weekly lesson plans', menu: "This week's meals", incidents: 'Report & view incidents',
    'time-clock': 'Clock in and out', 'my-schedule': 'Your work schedule',
    'my-hours': 'Your logged hours', 'time-off': 'Request time off',
    settings: 'Your account settings', compliance: 'Compliance overview',
    'audit-logs': 'System activity log', videos: 'Video moments', directory: 'Family directory',
    conferences: 'Parent–teacher conferences', 'signed-docs': 'Your signed documents',
    'doc-workflows': 'Documents awaiting signature', immunizations: 'Immunization records',
    wellness: 'Daily wellness checks', 'pickup-auth': 'Authorized pickup people',
    trends: 'Trends & insights', autopay: 'Automatic payments', wallet: 'Saved payment methods',
    'payment-plans': 'Payment plans', ledger: 'Account ledger', referrals: 'Refer a friend', tickets: 'Support tickets',
    sales: 'Your deal pipeline', 'sales-leads': 'All leads, searchable',
    'sales-new': 'Add a prospect', 'sales-followups': 'Follow-ups due',
    'sales-plans': 'Preset plans & pricing', 'sales-demo': 'Open the demo environment', mfa: 'Two-factor security',
  };
  function tileHtml(t) {
    var desc = t.sub || TILE_DESC[t.hash] || t.label;
    return '<a class="kt-tile" href="#' + esc(t.hash) + '" title="' + esc(desc) + '" aria-label="' + esc(t.label + ' — ' + desc) + '">' +
      '<span class="kt-tile-icon" aria-hidden="true">' + t.icon + '</span>' +
      '<span class="kt-tile-label">' + esc(t.label) + '</span>' +
      (t.sub ? '<span class="kt-tile-sub">' + esc(t.sub) + '</span>' : '') +
      '</a>';
  }

  function gridHtml(items) {
    return '<div class="kt-tile-grid">' + items.map(tileHtml).join('') + '</div>';
  }

  function renderHome(main, ctx) {
    var role = (ctx && ctx.role) || 'guardian';
    var set = TILES[role] || TILES.guardian;

    var firstName = '';
    try {
      var u = (ctx && ctx.user) || (KT.Auth && KT.Auth.user && KT.Auth.user()) || {};
      firstName = ((u.first_name || u.name || '') + '').split(' ')[0];
    } catch (e) {}
    var greet = (KT.greetingForNow ? KT.greetingForNow(firstName) : ('Welcome' + (firstName ? ', ' + firstName : '')));

    var hasMore = set.more && set.more.length;
    var html =
      '<div class="kt-tilehome">' +
        // .kt-hero suppresses the shell's auto-hero
        '<div class="kt-hero kt-hero-tiles">' +
          '<div class="kt-hero-greet">' + esc(greet) + '</div>' +
          '<h1>' + esc(set.title) + '</h1>' +
          '<div class="kt-hero-sub">' + esc(set.sub) + '</div>' +
          // Explicit floating hero icon so the banner isn't a generic sparkle:
          // "My family" → family, "My classroom" → teacher. Pre-seeding a
          // .kt-hero-emoji makes kt-hero-emoji.js leave it alone (it dedupes).
          (/family/i.test(set.title) ? '<span class="kt-hero-emoji" aria-hidden="true">👨‍👩‍👧‍👦</span>'
            : /classroom/i.test(set.title) ? '<span class="kt-hero-emoji" aria-hidden="true">🧑‍🏫</span>' : '') +
        '</div>' +
        '<div class="kt-quickaccess">' +
        '<div class="kt-tile-sectionhead">Quick access</div>' +
        gridHtml(set.primary.concat(hasMore ? [{ hash: '', icon: '➕', label: 'More', _more: true }] : [])) +
        (hasMore
          ? '<div class="kt-tile-more" hidden>' +
              '<div class="kt-tile-more-label">More</div>' +
              gridHtml(set.more) +
            '</div>'
          : '') +
        '</div>' +
        '<button id="kt-home-signout" type="button" style="display:block;margin:22px auto 6px;background:none;border:none;color:#64748B;font:600 13px/1 inherit;cursor:pointer;padding:8px 14px;">🏃 Sign out</button>' +
      '</div>';

    if (!document.getElementById('kt-quickaccess-style')) {
      var _qs = document.createElement('style'); _qs.id = 'kt-quickaccess-style';
      _qs.textContent = '.kt-quickaccess{background:radial-gradient(120% 95% at 10% 6%,rgba(21,159,180,.16) 0%,rgba(21,159,180,0) 46%),radial-gradient(115% 90% at 92% 14%,rgba(124,58,237,.15) 0%,rgba(124,58,237,0) 50%),radial-gradient(130% 105% at 82% 100%,rgba(245,158,11,.14) 0%,rgba(245,158,11,0) 55%),radial-gradient(120% 100% at 14% 102%,rgba(236,72,153,.11) 0%,rgba(236,72,153,0) 55%),linear-gradient(135deg,#FBFDFF 0%,#FAF8FE 100%);border:1px solid rgba(124,58,237,.09);border-radius:20px;padding:16px 13px 13px;margin:8px 0 14px;box-shadow:0 8px 22px -14px rgba(31,96,128,.28);}'
        + '.kt-quickaccess .kt-tile-sectionhead{margin-top:2px;}'
        + '.kt-quickaccess .kt-tile-more{margin-top:6px;}';
      document.head.appendChild(_qs);
    }
    main.insertAdjacentHTML('beforeend', html);

    // Sign-out (parents have no bottom-bar Menu, so the launcher carries it).
    var signout = main.querySelector('#kt-home-signout');
    if (signout) signout.addEventListener('click', function () {
      // Sign-out ends the session and purges the token from BOTH stores, but
      // KEEPS biometric enrolment (the kt_bio_* vault) so relaunching prompts
      // for fingerprint/Face ID to get back in — the whole point of biometric
      // login. Fully removing it lives in Settings → "Turn off biometric".
      try { if (KT.Auth && KT.Auth.clear) KT.Auth.clear(); } catch (e) {}
      try {
        // Preserve the biometric vault across the blanket sessionStorage/localStorage wipe.
        var bio = {}; ['kt_biometric_enabled','kt_bio_token','kt_bio_user','kt_bio_agency','kt_bio_view','kt_bio_cred','kt_pin_vault','kt_pin_enabled'].forEach(function(k){var v=localStorage.getItem(k); if(v!=null) bio[k]=v;});
        sessionStorage.clear();
        localStorage.removeItem('kt_token'); localStorage.removeItem('kt_user');
        Object.keys(bio).forEach(function(k){ localStorage.setItem(k, bio[k]); });
        // "Sign out twice" fix: the biometric layer auto-restored the session right
        // back — the dashboard re-hydrate (kt_bio_unlocked_at < 30s) and the login
        // page's auto-prompt would sign the user straight back in. Clear the recent-
        // unlock stamp + set a one-shot flag so the login page shows (no auto-login);
        // biometric is still available as a button + kept enrolled.
        localStorage.removeItem('kt_bio_unlocked_at');
        localStorage.setItem('kt_signed_out', '1');
      } catch (e) {}
      location.href = '/index.html';
    });

    // Wire the "More" tiles (no hash — toggle the extra grid instead). Persist the
    // open/closed state per tile-set so returning Home (mobile/APK back button)
    // keeps "More" expanded where the user left it.
    var moreTiles = main.querySelectorAll('.kt-tile[href="#"]');
    var moreWraps = main.querySelectorAll('.kt-tile-more');
    moreTiles.forEach(function (moreTile, idx) {
      var moreWrap = moreWraps[idx];
      if (!moreWrap) return;
      var moreKey = 'kt_more_open_' + idx;
      try { if (sessionStorage.getItem(moreKey) === '1') moreWrap.hidden = false; } catch (e) {}
      moreTile.addEventListener('click', function (e) {
        e.preventDefault();
        moreWrap.hidden = !moreWrap.hidden;
        try { sessionStorage.setItem(moreKey, moreWrap.hidden ? '0' : '1'); } catch (e) {}
        if (!moreWrap.hidden) moreWrap.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
      });
    });
  }

  Shell.registerScreen('guardian:home', renderHome);
  Shell.registerScreen('educator:home', renderHome);
  Shell.registerScreen('auditor:home', renderHome);
  Shell.registerScreen('sales_rep:home', renderHome);

  // Expose the launcher so other screens can carry it. The staff bottom bar no
  // longer has a Menu button, so the dashboard hosts these tiles instead — the
  // tiles ARE the menu now, and they should only be defined in one place.
  KT.roleTilesHtml = function (role) {
    var set = TILES[role];
    if (!set) return '';
    return gridHtml(set.primary.concat(set.more || []));
  };

  // Auditor read-only screens — lazily delegate to existing render functions
  // (looked up at render time so script load-order doesn't matter).
  function regAuditor(hash, obj, method) {
    Shell.registerScreen('auditor:' + hash, function (main, ctx) {
      var o = window.KT && window.KT[obj];
      if (o && typeof o[method] === 'function') return o[method](main, ctx);
      main.innerHTML = '<div style="padding:28px;color:#6b7280;text-align:center;">This section isn’t available.</div>';
    });
  }
  regAuditor('compliance', 'Compliance', 'render');
  regAuditor('audit-logs', 'AuditLogs', 'render');
  regAuditor('children', 'AdminChildrenScreen', 'render');
  regAuditor('forms', 'Forms', 'render');

  // With the top nav hidden for these roles, the logo is the way back to the
  // tile home. Delegated so it works no matter when the header is built.
  document.addEventListener('click', function (e) {
    var brand = e.target.closest && e.target.closest('#navBrand, .nav-brand');
    if (!brand) return;
    if (/\brole-(guardian|educator|auditor|home-visitor|sales-rep)\b/.test(document.body.className)) {
      e.preventDefault();
      if (window.location.hash !== '#home') window.location.hash = '#home';
    }
  }, true);

  // Visible "← Back" button on every sub-screen (the top nav is hidden, so users
  // need an obvious way back to the tile menu). Floating so it survives the
  // shell clearing #appMain on each render.
  function ensureBackBtn() {
    var btn = document.getElementById('kt-role-back');
    if (!btn) {
      btn = document.createElement('button');
      btn.id = 'kt-role-back';
      btn.type = 'button';
      // Arrow + label; on mobile/APK the label is hidden so only the arrow shows.
      btn.innerHTML = '<span aria-hidden="true">←</span><span class="kt-back-label">&nbsp;Back</span>';
      if (!document.getElementById('kt-role-back-style')) {
        var _bs = document.createElement('style'); _bs.id = 'kt-role-back-style';
        _bs.textContent = '@media (max-width:600px){#kt-role-back .kt-back-label{display:none;}#kt-role-back{padding-left:12px !important;padding-right:12px !important;}}';
        document.head.appendChild(_bs);
      }
      btn.setAttribute('hidden', '');
      // Go to the PREVIOUS screen (not always home), via the unified debounced
      // back handler so overlapping handlers can't double-navigate.
      btn.addEventListener('click', function (e) {
        e.preventDefault(); e.stopPropagation();
        // Close any open overlay (chat thread, invoice, scanner) or the menu drawer first.
        if (window.KT && KT.hasOverlay && KT.hasOverlay()) { KT.goBack(); return; }
        if (document.body.classList.contains('kt-mnav-open')) { document.body.classList.remove('kt-mnav-open'); return; }
        // Otherwise return to the HOME tile menu. For these roles every tab (Today,
        // Photos, Messages, Billing...) hangs off the home launcher, so "back" means
        // home — not the browser-previous screen (Today used to go to whatever tab was
        // visited before it, or nothing).
        var educator = /\brole-educator\b/.test(document.body.className);
        window.location.hash = educator ? '#dashboard' : '#home';
      });
      (document.body || document.documentElement).appendChild(btn);
    }
    return btn;
  }
  function syncBack() {
    var btn = ensureBackBtn();
    var isRole = /\brole-(guardian|educator|auditor|home-visitor|sales-rep)\b/.test(document.body.className);
    var h = (window.location.hash || '').replace('#', '').split('?')[0];
    // An educator's home is the DASHBOARD (that's where the bottom bar's Home
    // button lands, and it now carries the tile launcher). Without this the back
    // button floated over the home screen — with nowhere to go back to — and sat
    // on top of the last tile.
    var isEducator = /\brole-educator\b/.test(document.body.className);
    var onHome = (h === '' || h === 'home' || (isEducator && h === 'dashboard'));
    var show = isRole && !onHome;
    if (show) btn.removeAttribute('hidden');
    else btn.setAttribute('hidden', '');
    // Body flag lets the CSS reserve top space on mobile so the floating arrow
    // never overlaps the screen's content (e.g. the Today balance-due tile).
    document.body.classList.toggle('kt-show-back', show);
  }
  window.addEventListener('hashchange', syncBack);
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', syncBack);
  setTimeout(syncBack, 200);
  setTimeout(syncBack, 800);
})(window);
