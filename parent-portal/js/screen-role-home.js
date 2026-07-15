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
        { hash: 'signed-docs',    icon: '✍️', label: 'Signed documents' },
        { hash: 'doc-workflows',  icon: '📜', label: 'Documents to sign' },
        { hash: 'immunizations',  icon: '🩹', label: 'Immunizations' },
        { hash: 'wellness',       icon: '🩺', label: 'Wellness check' },
        { hash: 'pickup-auth',    icon: '🪪', label: 'Pickup people' },
        { hash: 'trends',         icon: '📊', label: 'Trends' },
        { hash: 'autopay',        icon: '🔁', label: 'Autopay' },
        { hash: 'wallet',         icon: '👛', label: 'Wallet' },
        { hash: 'payment-plans',  icon: '🗓️', label: 'Payment plans' },
        { hash: 'ledger',         icon: '📒', label: 'Account ledger' },
        { hash: 'referrals',      icon: '🎁', label: 'Refer a friend' },
        { hash: 'tickets',        icon: '🎫', label: 'Support' },
      ],
    },
    educator: {
      title: 'My classroom',
      sub: 'Everything in one place',
      primary: [
        { hash: 'today',         icon: '✨', label: 'Today',        sub: 'Rooms & children' },
        { hash: 'children',      icon: '🧒', label: 'Child records' },
        { hash: 'care-log',      icon: '✅', label: 'Daily log' },
        { hash: 'observations',  icon: '👀', label: 'Observations' },
        { hash: 'lesson-plans',  icon: '📚', label: 'Lesson plans' },
        { hash: 'chat',          icon: '💬', label: 'Messages' },
        { hash: 'incidents',     icon: '⚠️', label: 'Incidents' },
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
  };

  function esc(s) {
    return String(s == null ? '' : s)
      .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
  }

  function tileHtml(t) {
    return '<a class="kt-tile" href="#' + esc(t.hash) + '">' +
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
        '</div>' +
        gridHtml(set.primary.concat(hasMore ? [{ hash: '', icon: '➕', label: 'More', _more: true }] : [])) +
        (hasMore
          ? '<div class="kt-tile-more" hidden>' +
              '<div class="kt-tile-more-label">More</div>' +
              gridHtml(set.more) +
            '</div>'
          : '') +
        '<button id="kt-home-signout" type="button" style="display:block;margin:22px auto 6px;background:none;border:none;color:#94A3B8;font:600 13px/1 inherit;cursor:pointer;padding:8px 14px;">Sign out</button>' +
      '</div>';

    main.insertAdjacentHTML('beforeend', html);

    // Sign-out (parents have no bottom-bar Menu, so the launcher carries it).
    var signout = main.querySelector('#kt-home-signout');
    if (signout) signout.addEventListener('click', function () {
      // Explicit sign-out: drop biometric enrolment + purge the token from BOTH
      // stores so nothing auto-resumes the session on a shared device.
      try { if (window.KT && KT.biometric && KT.biometric.disable) KT.biometric.disable(); } catch (e) {}
      try { if (KT.Auth && KT.Auth.clear) KT.Auth.clear(); } catch (e) {}
      try { sessionStorage.clear(); localStorage.removeItem('kt_token'); localStorage.removeItem('kt_user'); } catch (e) {}
      location.href = '/index.html';
    });

    // Wire the "More" tile (it has no hash — toggle the extra grid instead).
    var moreTile = main.querySelector('.kt-tile[href="#"]');
    var moreWrap = main.querySelector('.kt-tile-more');
    if (moreTile && moreWrap) {
      moreTile.addEventListener('click', function (e) {
        e.preventDefault();
        moreWrap.hidden = !moreWrap.hidden;
        if (!moreWrap.hidden) moreWrap.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
      });
    }
  }

  Shell.registerScreen('guardian:home', renderHome);
  Shell.registerScreen('educator:home', renderHome);
  Shell.registerScreen('auditor:home', renderHome);

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
    if (/\brole-(guardian|educator|auditor)\b/.test(document.body.className)) {
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
      btn.innerHTML = '← Back';
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
    var isRole = /\brole-(guardian|educator|auditor)\b/.test(document.body.className);
    var h = (window.location.hash || '').replace('#', '').split('?')[0];
    // An educator's home is the DASHBOARD (that's where the bottom bar's Home
    // button lands, and it now carries the tile launcher). Without this the back
    // button floated over the home screen — with nowhere to go back to — and sat
    // on top of the last tile.
    var isEducator = /\brole-educator\b/.test(document.body.className);
    var onHome = (h === '' || h === 'home' || (isEducator && h === 'dashboard'));
    if (isRole && !onHome) btn.removeAttribute('hidden');
    else btn.setAttribute('hidden', '');
  }
  window.addEventListener('hashchange', syncBack);
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', syncBack);
  setTimeout(syncBack, 200);
  setTimeout(syncBack, 800);
})(window);
