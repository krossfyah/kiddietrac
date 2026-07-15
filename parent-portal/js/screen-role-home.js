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

  // ── Parent home glance stats (skeleton-first so they paint WITH the tiles, then
  //    fill in — no "cards load after the banner" stagger). Guardian only. ──
  function injectHomeStatsCss() {
    if (document.getElementById('kt-home-stats-css')) return;
    var st = document.createElement('style'); st.id = 'kt-home-stats-css';
    st.textContent = [
      '.kt-home-stats{display:grid;grid-template-columns:repeat(4,1fr);gap:14px;margin:0 0 22px;}',
      '.kt-hstat{display:flex;align-items:center;gap:12px;background:#fff;border:1px solid #E7ECF3;border-radius:16px;padding:15px 18px;text-decoration:none;color:inherit;box-shadow:0 1px 3px rgba(15,23,42,.05);transition:transform .12s,box-shadow .12s,border-color .12s;}',
      '.kt-hstat:hover{transform:translateY(-2px);box-shadow:0 10px 24px rgba(13,27,42,.10);border-color:#c9d7ea;}',
      '.kt-hstat-icon{font-size:23px;width:44px;height:44px;border-radius:12px;background:#F1F5F9;display:flex;align-items:center;justify-content:center;flex-shrink:0;}',
      '.kt-hstat-body{min-width:0;}',
      '.kt-hstat-label{font-size:11px;font-weight:700;letter-spacing:.04em;text-transform:uppercase;color:#94A3B8;margin-bottom:3px;}',
      '.kt-hstat-value{font-size:22px;font-weight:800;color:#0F172A;line-height:1.1;white-space:nowrap;}',
      '.kt-hstat-unit{font-size:12px;font-weight:600;color:#94A3B8;}',
      '.kt-hstat.kt-hstat-warn .kt-hstat-value{color:#B45309;}',
      '.kt-hstat.kt-hstat-ok .kt-hstat-value{color:#047857;}',
      '.kt-hstat-sk{display:inline-block;min-width:66px;height:22px;border-radius:6px;background:linear-gradient(90deg,#EEF2F7 25%,#E2E8F0 37%,#EEF2F7 63%);background-size:400% 100%;animation:kt-sk 1.2s ease infinite;}',
      '@keyframes kt-sk{0%{background-position:100% 0}100%{background-position:0 0}}',
      '@media(max-width:900px){.kt-home-stats{grid-template-columns:repeat(2,1fr);}}',
      '@media(max-width:600px){.kt-home-stats{grid-template-columns:repeat(2,1fr);gap:8px;margin-bottom:14px;}.kt-hstat{padding:11px;gap:9px;border-radius:13px;}.kt-hstat-icon{width:34px;height:34px;font-size:18px;}.kt-hstat-value{font-size:17px;}}',
    ].join('');
    document.head.appendChild(st);
  }

  function homeStatsShell() {
    var card = function (id, hash, icon, label) {
      return '<a class="kt-hstat" id="' + id + '" href="#' + hash + '">'
        + '<div class="kt-hstat-icon">' + icon + '</div>'
        + '<div class="kt-hstat-body"><div class="kt-hstat-label">' + label + '</div>'
        + '<div class="kt-hstat-value"><span class="kt-hstat-sk">&nbsp;</span></div></div></a>';
    };
    return '<div class="kt-home-stats">'
      + card('hstat-status', 'today', '📍', "Today's status")
      + card('hstat-balance', 'billing', '💳', 'Outstanding balance')
      + card('hstat-photos', 'photos', '📸', 'Photos this week')
      + card('hstat-children', 'today', '🧒', 'Your children')
      + '</div>';
  }

  async function loadHomeStats() {
    var Api = KT.Api; if (!Api || !Api.get) return;
    var setVal = function (id, html, cls) {
      var el = document.getElementById(id); if (!el) return;
      var v = el.querySelector('.kt-hstat-value'); if (v) v.innerHTML = html;
      if (cls) el.classList.add(cls);
    };
    var kids = [];
    try { var d = await Api.get('/parent/children'); kids = d.children || []; } catch (e) {}
    setVal('hstat-children', kids.length + ' <span class="kt-hstat-unit">enrolled</span>');
    var atc = kids.filter(function (k) { return k.is_at_centre; }).length;
    var stat = !kids.length ? '—' : (atc === kids.length ? 'At the centre' : (atc === 0 ? 'At home' : (atc + ' of ' + kids.length + ' in')));
    setVal('hstat-status', stat, atc > 0 ? 'kt-hstat-ok' : '');
    try { var l = await Api.get('/parent/ledger'); var bal = Number(l.current_balance || 0); setVal('hstat-balance', '$' + bal.toFixed(2), bal > 0.005 ? 'kt-hstat-warn' : 'kt-hstat-ok'); }
    catch (e) { setVal('hstat-balance', '—'); }
    try {
      var weekAgo = new Date(Date.now() - 7 * 864e5).toISOString().slice(0, 10);
      var count = 0;
      for (var i = 0; i < kids.length; i++) {
        var pd = await Api.get('/parent/children/' + kids[i].id + '/photos').catch(function () { return null; });
        if (pd && pd.photos) count += pd.photos.filter(function (ph) { return (ph.taken_at || '').slice(0, 10) >= weekAgo; }).length;
      }
      setVal('hstat-photos', count + ' <span class="kt-hstat-unit">' + (count === 1 ? 'photo' : 'photos') + '</span>');
    } catch (e) { setVal('hstat-photos', '—'); }
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
        (role === 'guardian' ? homeStatsShell() : '') +
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
    if (role === 'guardian') { injectHomeStatsCss(); loadHomeStats(); }

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
