/* ═══════════════════════════════════════════════════════════════════
   KiddieTrac — mobile bottom navigation (2026-07-08).
   On phones (≤768px, incl. the Android APK's WebView) a fixed bottom bar gives
   one-tap access to the most-used functions — Home, Messages, Alerts — plus a
   Menu button that slides the full sidebar in as an overlay. Larger touch
   targets + no hunting through a hidden menu.
   ═══════════════════════════════════════════════════════════════════ */
(function () {
  'use strict';

  /* ── THE CHROME STEPS ASIDE FOR A DIALOG ───────────────────────────────────
     ~65 hand-rolled overlays across the screens carry a z-index below the mobile
     chrome: 18 under the bottom nav (9500), and another 11 under the agency switcher
     and "Viewing as" pill (9001) that only a super admin has — which is why this reads
     as a role bug when nothing in the dialogs is role-aware. Fixing 65 call sites would
     not hold; the next hand-rolled dialog would land under the chrome again. So the
     chrome yields.

     Only the PERSISTENT furniture moves — bottom nav, gear, agency switcher. The drawer,
     its scrim, the select sheet and toasts are meant to sit above a dialog and are left
     alone. Everything returns the moment the dialog closes. (Anthony, 2026-09-07) */
  var DUCK_CSS = 'body.kt-dialog-open #kt-mobilenav,'
    + 'body.kt-dialog-open #kt-gear,'
    + 'body.kt-dialog-open #kt-agency-switcher{z-index:500 !important;}';

  /* Is a dialog on screen? The same shape test KT.uiBusy() uses, so the portal keeps ONE
     definition of "a dialog is open" rather than two that drift. uiBusy() itself is not
     called: it also answers true for a focused input, which is about deferring refreshes,
     not about furniture. */
  function dialogOnScreen() {
    try {
      if (document.querySelector('.kt-modal, .kt-modal-overlay, .modal-backdrop, .kt-scrim,'
          + ' .kt-lightbox, .kt-doc-viewer, .kt-av-zoom, [role="dialog"]')) { return true; }
      var mr = document.getElementById('modalRoot');
      if (mr && mr.firstElementChild) { return true; }
      var kids = document.body ? document.body.children : [];
      for (var i = 0; i < kids.length; i++) {
        var el = kids[i];
        if (el.id === 'appMain' || el.id === 'kt-mobilenav' || el.id === 'kt-gear'
            || el.id === 'kt-agency-switcher') { continue; }
        if (el.tagName === 'SCRIPT' || el.tagName === 'STYLE' || el.tagName === 'LINK') { continue; }
        if (el.hidden) { continue; }
        var cs = window.getComputedStyle(el);
        if (cs.position !== 'fixed' || cs.display === 'none' || cs.visibility === 'hidden') { continue; }
        if (parseFloat(cs.opacity || '1') < 0.05) { continue; }
        var z = parseInt(cs.zIndex, 10);
        if (isNaN(z) || z < 900) { continue; }
        var r = el.getBoundingClientRect();
        if (r.width < 200 || r.height < 120) { continue; }   // a badge, not a dialog
        return true;
      }
    } catch (e) {}
    return false;
  }

  var duckArmed = false;
  function duckChromeForDialogs() {
    if (duckArmed) { return; }
    duckArmed = true;
    if (!document.getElementById('kt-duck-css')) {
      var st = document.createElement('style');
      st.id = 'kt-duck-css';
      st.textContent = DUCK_CSS;
      document.head.appendChild(st);
    }
    var pending = 0;
    var apply = function () {
      pending = 0;
      try { document.body.classList.toggle('kt-dialog-open', dialogOnScreen()); } catch (e) {}
    };
    /* Coalesced: opening a dialog adds several nodes at once, and the shape test walks
       body's children with getComputedStyle. One pass per frame, not one per node. */
    var schedule = function () {
      if (pending) { return; }
      pending = window.requestAnimationFrame
        ? window.requestAnimationFrame(apply)
        : window.setTimeout(apply, 16);
    };
    try {
      new MutationObserver(schedule).observe(document.body, { childList: true });
    } catch (e) {
      window.setInterval(apply, 1200);   // WebViews without MutationObserver
    }
    apply();
  }
  if (window.__ktMobileNav) return; window.__ktMobileNav = true;
  try { window.__KT_NAV_VER = 'eduhome'; } catch (e) {}   // stamp: proves which nav JS actually ran (read by the diag chip)
  function tok() { try { return sessionStorage.getItem('kt_token') || localStorage.getItem('kt_token'); } catch (e) { return null; } }

  // Is this the native Capacitor APK (vs a web browser)? The APK's WebView can
  // report a CSS layout-viewport width ABOVE 768 (device/OEM/full-screen dependent),
  // which would make every `@media(max-width:768px)` mobile rule miss — the bottom
  // bar, the raised check-in button and the gear would all vanish at once. So the
  // native app forces the mobile layout regardless of the width it reports.
  function isNativeApp() {
    try {
      var C = window.Capacitor;
      if (C) {
        if (typeof C.isNativePlatform === 'function') return C.isNativePlatform();
        if (C.isNative != null) return !!C.isNative;
        if (C.platform && C.platform !== 'web') return true;
      }
      if (window.Capacitor && window.Capacitor.Plugins && window.Capacitor.Plugins.KtBio) return true;
    } catch (e) {}
    return false;
  }
  /* RE-EVALUATED, not frozen. This used to be read once at script parse; if
     Capacitor injects its bridge afterwards — more likely on iOS than Android — the
     answer stayed false for the whole session and every rule gated on `kt-app` was
     inert. Cached once it turns true, because a native app never stops being one. */
  var _native = null;
  function NATIVE_NOW() {
    if (_native) return true;
    _native = isNativeApp() || null;
    return !!_native;
  }
  var NATIVE = isNativeApp();

  // Being the app does not make you a phone. A Samsung tablet running the APK has a
  // desktop-sized viewport (~1280x800 landscape) and was still getting phone chrome,
  // because kt-native was applied on nativeness alone and 24 rules in kt-mobile-app.css
  // key off it at ANY width. kt-native now means "native AND phone-sized", so every one
  // of those rules — and every isMobile() check that tests the class — becomes
  // width-aware without touching them individually. kt-app stays on for the whole
  // session, for anything that genuinely needs to know it is the native app.
  var PHONE_MAX = 768;          // layout width at which the phone chrome applies
  var PHONE_SHORT_EDGE = 600;   // Android's own tablet cutoff (sw600dp), in CSS px

  // Judge the DEVICE, not the current rotation. The short edge of the screen does not
  // change when you turn the device: ~412 on a phone, ~800 on this tablet. Using the
  // live layout width alone would make a phone "become a tablet" in landscape (~915)
  // and lose its bottom bar, and it is also the value the APK WebView has been seen
  // inflating above 768 on a phone - the bug that once made the bar, the raised
  // check-in button and the gear vanish at once. The layout width still counts on its
  // own for a genuinely small window: the app in Android split-screen is phone-shaped
  // whatever device it is running on.
  function isPhoneDevice() {
    try {
      var s = window.screen || {};
      var edge = (s.width && s.height) ? Math.min(s.width, s.height) : (s.width || 0);
      if (edge) return edge <= PHONE_SHORT_EDGE;
      var w = window.innerWidth || 0;
      return w > 0 ? w <= PHONE_MAX : true;      // unknown -> assume phone (safe default)
    } catch (e) { return true; }
  }
  function isPhoneSized() {
    if (isPhoneDevice()) return true;
    var w = 0;
    try { w = window.innerWidth || 0; } catch (e) {}
    return w > 0 && w <= PHONE_MAX;
  }
  function syncNativeClasses() {
    try {
      var d = document.documentElement;
      d.classList.toggle('kt-app', NATIVE_NOW());
      d.classList.toggle('kt-native', NATIVE && isPhoneSized());
    } catch (e) {}
  }
  syncNativeClasses();
  // Rotating a tablet, or resizing into split-screen, crosses the boundary - so
  // re-evaluate rather than deciding once at load.
  try {
    window.addEventListener('resize', syncNativeClasses);
    window.addEventListener('orientationchange', syncNativeClasses);
  } catch (e) {}

  // On a phone the bottom bar must survive an inflated WebView width AND landscape,
  // so its media query stays effectively unbounded there - that is the original
  // intent, now scoped to devices that really are phones. On a tablet the app simply
  // follows the viewport, like any browser that size.
  var BP = isPhoneDevice() ? 100000 : PHONE_MAX;   // bottom bar + mobile rules: max-width
  var GEARBP = BP + 1;                             // gear hide: min-width

  function injectStyle() {
    if (document.getElementById('kt-mobilenav-style')) return;
    var s = document.createElement('style'); s.id = 'kt-mobilenav-style';
    s.textContent = [
      '#kt-mobilenav{display:none;}',
      // ≤768px, in the browser AND in the app. This was previously forced to
      // effectively-infinite for native, which gave TABLETS phone chrome. The APK
      // WebView can report an innerWidth >768 on a phone - which is what once made this
      // block miss and the bottom bar, raised check-in button and gear all vanish - so
      // isPhoneSized() above cross-checks screen.width rather than trusting innerWidth
      // alone. DO NOT narrow to 600.
      '@media(max-width:' + BP + 'px){',
        '#kt-mobilenav{display:flex;position:fixed;left:0;right:0;bottom:0;z-index:9500;background:#fff;',
        'border-top:1px solid #E5E7EB;box-shadow:0 -4px 16px -8px rgba(15,23,42,.2);',
        'padding:5px 4px calc(var(--kt-safe-bottom, env(safe-area-inset-bottom,0px)) + 5px);justify-content:space-around;}',
        '#kt-mobilenav button{position:relative;flex:1;background:transparent;border:none;display:flex;flex-direction:column;',
        'align-items:center;gap:2px;padding:8px 2px 6px;cursor:pointer;color:#64748B;font-weight:600;font-size:10.5px;line-height:1.2;min-height:52px;}',
        '#kt-mobilenav button .ic{font-size:21px;line-height:1;transition:transform .12s ease;}',
        '#kt-mobilenav button:active{color:#1F6080;}',
        '#kt-mobilenav button.on{color:#0E7C90;font-weight:800;}',
        '#kt-mobilenav button.on .ic{transform:translateY(-2px) scale(1.06);}',
        '#kt-mobilenav button.on::before{content:"";position:absolute;top:0;left:50%;transform:translateX(-50%);width:26px;height:3px;border-radius:0 0 3px 3px;background:#0E7C90;}',
        // Center raised camera/QR check-in button (restored to the original look).
        '#kt-mobilenav button.scan{color:#0E7C90;font-weight:800;}',
        '#kt-mobilenav button.scan .ic{width:50px;height:50px;margin-top:-22px;border-radius:50%;background:#0E7C90;color:#fff;display:flex;align-items:center;justify-content:center;font-size:23px;box-shadow:0 6px 16px -4px rgba(14,124,144,.7);border:3px solid #fff;}',
        '#kt-mobilenav button.scan.on .ic{transform:none;}',
        '#kt-mobilenav button.scan.on::before{display:none;}',
        // Keep the parent profile name clear of the floating settings gear.
        '#navUser{padding-right:56px !important;box-sizing:border-box !important;}',
        // The `font` shorthand needs a real family — `font:800 10px/16px inherit` is
        // INVALID, so the browser threw the whole declaration away and the badge
        // inherited the emoji's 21px size, which clipped the digit inside a 16px
        // pill. Longhand, so each property stands on its own.
        '#kt-mobilenav button .badge{position:absolute;top:-3px;left:50%;margin-left:5px;min-width:17px;height:17px;'
          + 'padding:0 4px;box-sizing:border-box;border-radius:9px;background:#EF4444;color:#fff;'
          + 'font-family:system-ui,-apple-system,sans-serif;font-weight:800;font-size:10.5px;line-height:17px;'
          + 'text-align:center;white-space:nowrap;box-shadow:0 0 0 2px #fff;}',
        // clear the bottom bar, nothing more — and never force a full-screen
        // min-height, which padded short sections with dead white space.
        // 62px was the flat-bar height. The bar is taller now (the raised
        // check-in/QR button pushes it to ~87px), so 62px left the last control
        // on a screen — the Sign out button on the home launcher — sitting
        // UNDERNEATH the bar and untappable. Clear the real height, with room to
        // spare; pinToVisualViewport() refines it from the measured bar.
        '.app-main,#appMain{padding-bottom:calc(var(--kt-safe-bottom, env(safe-area-inset-bottom,0px)) + 100px) !important;min-height:0 !important;}',
        // Kill browser scroll-anchoring — as tall screens (Home tiles) render in
        // stages it shoves the scroll down, then our reset yanks it up = the flash.
        'html,body,#appMain{overflow-anchor:none !important;}',
        '.app-shell,.app-shell--sidebar{min-height:0 !important;}',
        '#kt-mobile-bottom-nav{display:none !important;}',  /* kill the legacy overlapping bar */
        '#kt-topbar{top:4px;}',
        /* Menu → slide the sidebar in as an overlay */
        'body.kt-mnav-open #appSidebar{position:fixed !important;left:0;top:0;bottom:0;z-index:9600;display:block !important;',
        'width:84% !important;max-width:320px;transform:none !important;overflow-y:auto;-webkit-overflow-scrolling:touch;box-shadow:0 0 44px rgba(0,0,0,.45);}',
        'body.kt-mnav-open .app-shell.app-shell--sidebar{grid-template-columns:0 1fr !important;}',
        /* The whole nav is display:none on phones (bottom bar replaces it) — un-hide it inside the menu overlay. */
        'body.kt-mnav-open #appSidebar #navLinks{display:block !important;}',
        'body.kt-mnav-open #appSidebar .nav-label,body.kt-mnav-open #appSidebar .sidebar-section-label,body.kt-mnav-open #appSidebar .sidebar-section{display:revert !important;opacity:1 !important;}',
        'body.kt-mnav-open #appSidebar .nav-icon{display:inline-flex !important;}',
        '#kt-mnav-scrim{display:none;position:fixed;inset:0;background:rgba(0,0,0,.45);z-index:9550;}',
        'body.kt-mnav-open #kt-mnav-scrim{display:block;}',
      '}',
      '@media(min-width:' + GEARBP + 'px){#kt-gear{display:none !important;}}',
      'body.kt-mnav-open #kt-gear{display:none;}',
      // Hide the gear while a full-screen chat thread is open (it would float over the header).
      'body:has(.kt-thread-compose) #kt-gear{display:none !important;}'
    ].join('');
    document.head.appendChild(s);
  }

  function closeMenu() { document.body.classList.remove('kt-mnav-open'); }
  function go(hash) { location.hash = hash; closeMenu(); }

  // Keep the top-right user block (avatar + name) clear of the floating settings
  // gear. Stylesheet rules get out-specified by the sidebar's own #navUser rule,
  // so we force it inline (inline !important always wins) and re-apply as the SPA
  // re-renders the header.
  /* WHERE THE GEAR GOES: centred in the header, measured.

     It used to be positioned at `env(safe-area-inset-top) + 8px` — a value the iOS
     WebView reports as 0 — and then at `--kt-safe-top + 8px`, which is a guess at
     where the header ENDS rather than where it actually does. When the header grows
     (logo + greeting + role badge, or a wrapped name) the gear floated below it, over
     the banner. Reading the box cannot be wrong, and it re-centres on rotation, on a
     re-render, and when the greeting wraps. */
  /* Docking was wrong — the gear belongs BESIDE the name, not inside the controls
     row. Any previously docked gear (from a cached build) is floated again. */
  function undockGear(g) {
    if (!g || !g.getAttribute('data-kt-docked')) return;
    g.removeAttribute('data-kt-docked');
    g.style.position = 'fixed';
    g.style.right = '12px';
    document.body.appendChild(g);
  }

  function placeGear() {
    var g = document.getElementById('kt-gear');
    if (!g) return;
    undockGear(g);

    /* BESIDE THE NAME ROW — the one row every role has.

       "It should be like how the educator and other users see it in mobile view"
       (Anthony, 2026-09-06). For an educator the phone header IS the name row: logo,
       avatar, greeting, name, with the gear at its top right. A super admin has that
       same row with a controls row stacked above it.

       Aiming at #appSidebar (the whole header) or at the foot (the controls row) put the
       gear beside the wrong things — on the selectors, or between them. #navUser is the
       row the reference actually describes, and it exists for every role, so ONE rule
       reproduces the educator's placement for everybody. */
    var bar = document.getElementById('navUser') || document.getElementById('appSidebar');
    if (!bar) return;
    var r = bar.getBoundingClientRect();
    // A hidden or drawer-mode sidebar measures nothing useful; leave the last value.
    if (!(r.height > 0) || r.bottom <= 0) return;
    var top = Math.round(r.top + (r.height - 40) / 2);
    if (top < 4) top = 4;                       // never above the status bar
    var want = top + 'px';

    /* !important, or this does nothing at all.

       Seven rules in kt-mobile-app.css pin #kt-gear's top with !important
       (html.kt-app, html.kt-ios-inset-fallback, html.kt-native-fullscreen, each
       duplicated across that file's repeated blocks). An !important declaration in a
       stylesheet BEATS a plain inline style, so every value this function wrote was
       discarded by the cascade and the gear stayed at `max(safe-top,22px)+6px` — about
       28px down, on top of whatever the header put there.

       That is why four different anchors all measured correctly and nothing moved on
       screen: the number being verified was never the number being applied. */
    if (g.style.getPropertyValue('top') !== want
        || g.style.getPropertyPriority('top') !== 'important') {
      g.style.setProperty('top', want, 'important');
    }
    // Same treatment for right: nothing marks it !important today, and the next rule
    // that does must not bring this bug back.
    if (g.style.getPropertyPriority('right') !== 'important') {
      g.style.setProperty('right', '12px', 'important');
    }
  }

  function padGearClearance() {
    if (!NATIVE && window.innerWidth > 768) return;
    if (!document.getElementById('kt-gear')) return;
    var nu = document.getElementById('navUser');
    if (!nu) return;
    // The parent/educator chrome adds a right-aligned date/time (kt-pc-mobmeta) that
    // already clears the floating gear — padding navUser too just crushes the name.
    if (document.getElementById('kt-pc-navgreet')) { if (nu.style.paddingRight) nu.style.removeProperty('padding-right'); return; }
    // Each floating button is 40px wide with an 8px gap; the QR button (educators)
    // sits left of the gear, so the name/role block has to clear both.
    var pad = (document.getElementById('kt-eduqr') ? 106 : 58) + 'px';
    if (nu.style.paddingRight !== pad) nu.style.setProperty('padding-right', pad, 'important');
  }

  // The ACTIVE role = exactly what the shell renders (badge + tiles + screens):
  // a view-as override if present, else the user's primary_role. The bar MUST
  // match this. (The old logic keyed off the `roles` ARRAY with educator/admin
  // exclusions, which broke multi-role accounts — e.g. a guardian who ALSO has an
  // educator role got the staff bar with no check-in, and an account whose `roles`
  // array is empty but primary_role='guardian' fell through entirely.)
  // Mirror app-v2-shell.js `Roles.primaryRoleOf` EXACTLY: the shell derives the
  // active role from the `roles` ARRAY via a PRIORITY ORDER (not primary_role, not
  // roles[0]) — agency_admin > platform_admin(→agency_admin) > centre_director >
  // educator > guardian > home_visitor > auditor. A guardian who ALSO holds e.g.
  // home_visitor/auditor still resolves to 'guardian' (it outranks those), which is
  // why the shell shows the PARENT view. The old roles[0] fallback picked the wrong
  // element and gave those parents the staff bar. Only a platform_admin's view-as
  // overrides (the impersonation feature), matching the shell.
  function activeRole() {
    try {
      var u = JSON.parse(sessionStorage.getItem('kt_user') || localStorage.getItem('kt_user') || '{}');
      var roles = Array.isArray(u.roles) ? u.roles : [];
      var va = ''; try { va = sessionStorage.getItem('kt_view_as') || ''; } catch (e) {}
      if (va && roles.indexOf('platform_admin') !== -1 &&
          ['agency_admin', 'centre_director', 'educator', 'guardian', 'auditor', 'home_visitor'].indexOf(va) !== -1) return va;
      if (roles.indexOf('agency_admin') !== -1) return 'agency_admin';
      if (roles.indexOf('platform_admin') !== -1) return 'agency_admin';
      if (roles.indexOf('centre_director') !== -1) return 'centre_director';
      if (roles.indexOf('educator') !== -1) return 'educator';
      if (roles.indexOf('guardian') !== -1) return 'guardian';
      if (roles.indexOf('home_visitor') !== -1) return 'home_visitor';
      if (roles.indexOf('auditor') !== -1) return 'auditor';
      return u.primary_role || '';
    } catch (e) { return ''; }
  }
  try { window.__ktActiveRole = activeRole; } catch (e) {}

  // A parent (guardian) — by their active role, or a super-admin previewing the
  // parent view — gets the parent bottom bar (Home·Photos·Check-in·Messages·Billing).
  function isParentView() { return activeRole() === 'guardian'; }

  // An educator (active role, or previewed via view-as). Educators are phone-first
  // too, so they get the same floating settings gear + a check-in QR button.
  function isEducatorView() { return activeRole() === 'educator'; }

  // Fullscreen check-in QR for the educator's centre — parents scan it off the
  // educator's phone (or print it). The code is fetched fresh and rotates daily
  // server-side (CheckinScanController::centreCode → KTCHK.<centre>.<Ymd>.<sig>).
  /**
   * The quick-add sheet for admins and directors.
   *
   * Reads the actions out of .kt-v5a-actions (screen-director-v5a builds it in the
   * sidebar, hidden on phones since 2026-09-02) and forwards each tap to the original
   * button. Nothing about what "New family" does is duplicated here — a second copy
   * would be a second thing to keep in step with the modals.
   *
   * If the section has not been built yet — it is injected by the director screen — the
   * sheet says so rather than opening empty.
   */
  function openAdminQuickAdd() {
    if (document.getElementById('kt-qa-sheet')) return;
    var src = document.querySelectorAll('.kt-v5a-actions button');
    var items = [];
    for (var i = 0; i < src.length; i++) {
      var t = (src[i].textContent || '').trim();
      if (!t || /Quick add/i.test(t)) { continue; }   // the collapse header, not an action
      items.push(src[i]);
    }

    var ov = document.createElement('div');
    ov.id = 'kt-qa-sheet';
    ov.style.cssText = 'position:fixed;inset:0;z-index:99998;background:rgba(15,23,42,.5);'
      + 'display:flex;align-items:flex-end;';
    var card = document.createElement('div');
    card.style.cssText = 'width:100%;background:#fff;border-radius:18px 18px 0 0;padding:14px 14px '
      + 'calc(14px + var(--kt-safe-bottom, env(safe-area-inset-bottom,0px)));box-shadow:0 -8px 24px rgba(15,23,42,.18);';
    var h = document.createElement('div');
    h.style.cssText = 'font:800 14px/1.2 system-ui,sans-serif;color:#0F172A;margin:2px 0 10px;';
    h.textContent = '\u26A1 Quick add';
    card.appendChild(h);

    if (!items.length) {
      var none = document.createElement('div');
      none.style.cssText = 'font-size:13px;color:#64748B;padding:6px 2px 12px;';
      none.textContent = 'Nothing to add from here yet — open a centre screen first.';
      card.appendChild(none);
    }
    items.forEach(function (b) {
      var row = document.createElement('button');
      row.type = 'button';
      row.textContent = (b.textContent || '').trim();
      row.style.cssText = 'display:block;width:100%;text-align:left;background:#F8FAFC;border:1px solid #E7EBF0;'
        + 'border-radius:10px;padding:0 12px;height:44px;margin-bottom:8px;font:600 14px/1 system-ui,sans-serif;color:#0F172A;cursor:pointer;';
      row.addEventListener('click', function () { close(); b.click(); });
      card.appendChild(row);
    });

    var cancel = document.createElement('button');
    cancel.type = 'button';
    cancel.textContent = 'Cancel';
    cancel.style.cssText = 'display:block;width:100%;background:none;border:0;padding:10px;'
      + 'font:700 13px/1 system-ui,sans-serif;color:#64748B;cursor:pointer;';
    card.appendChild(cancel);
    ov.appendChild(card);

    function close() {
      document.removeEventListener('keydown', onKey, true);
      ov.remove();
    }
    function onKey(e) { if (e.key === 'Escape') { e.stopPropagation(); close(); } }
    cancel.addEventListener('click', close);
    ov.addEventListener('click', function (e) { if (e.target === ov) close(); });
    document.addEventListener('keydown', onKey, true);
    document.body.appendChild(ov);
  }

  function showCheckinQr() {
    // Guard: a stale/second overlay stacking made the button feel unresponsive
    // ("kept clicking"). If one's already up, don't build another.
    if (document.getElementById('kt-qr-overlay')) return;
    var u = {}; try { u = JSON.parse(sessionStorage.getItem('kt_user') || localStorage.getItem('kt_user') || '{}'); } catch (e) {}
    var centreId = u.centre_id;
    var tok = null; try { tok = sessionStorage.getItem('kt_token') || localStorage.getItem('kt_token'); } catch (e) {}
    var base = (window.KT_CONFIG && window.KT_CONFIG.apiBase) || 'https://api.kiddietrac.com/api/v1';
    var ov = document.createElement('div');
    ov.id = 'kt-qr-overlay';
    ov.style.cssText = 'position:fixed;inset:0;z-index:99999;background:rgba(8,28,65,.95);overflow-y:auto;color:#fff;';
    ov.innerHTML =
      '<div style="font-size:21px;font-weight:800;margin-bottom:4px;">📲 Check-in QR</div>'
      + '<div id="kt-qr-sub" style="font-size:13.5px;opacity:.85;margin-bottom:18px;">Loading…</div>'
      + '<div id="kt-qr-holder" style="background:#fff;padding:16px;border-radius:18px;width:250px;height:250px;display:flex;align-items:center;justify-content:center;color:#64748B;font-size:13px;">Generating…</div>'
      + '<div id="kt-qr-codebox" style="display:none;margin-top:14px;max-width:300px;">'
      +   '<div style="font-size:11.5px;opacity:.8;margin-bottom:6px;">📷 No camera? Enter this code in the app:</div>'
      +   '<div id="kt-qr-code" style="font-family:ui-monospace,Menlo,monospace;font-size:26px;font-weight:800;letter-spacing:5px;text-align:center;background:rgba(255,255,255,.16);border:1px dashed rgba(255,255,255,.4);border-radius:10px;padding:12px 14px;user-select:all;-webkit-user-select:all;"></div>'
      + '</div>'
      + '<div style="font-size:12px;opacity:.75;margin-top:14px;max-width:300px;line-height:1.5;">Parents scan this with the KiddieTrac app to sign in or out (or type the code above). It refreshes every day for security.</div>'
      + '<div style="display:flex;gap:12px;margin-top:22px;">'
      +   '<button id="kt-qr-print" style="background:#fff;color:#0B2545;border:none;border-radius:12px;padding:12px 24px;font-size:15px;font-weight:800;cursor:pointer;">🖨 Print</button>'
      +   '<button id="kt-qr-close" style="background:transparent;color:rgba(255,255,255,.85);border:1px solid rgba(255,255,255,.4);border-radius:12px;padding:12px 24px;font-size:15px;font-weight:700;cursor:pointer;">Close</button>'
      + '</div>';
    // Center the content but allow scrolling on short screens — previously the QR
    // + code + helper text could be taller than the viewport, so the top was
    // clipped and it looked off-centre. Wrap it all in a min-height flex box.
    var _qrInner = document.createElement('div');
    _qrInner.style.cssText = 'min-height:100%;display:flex;flex-direction:column;align-items:center;justify-content:center;text-align:center;padding:calc(var(--kt-safe-top, env(safe-area-inset-top,0px)) + 24px) 24px calc(var(--kt-safe-bottom, env(safe-area-inset-bottom,0px)) + 24px);box-sizing:border-box;';
    while (ov.firstChild) _qrInner.appendChild(ov.firstChild);
    ov.appendChild(_qrInner);
    document.body.appendChild(ov);
    var close = function () { if (ov && ov.parentNode) ov.parentNode.removeChild(ov); };
    ov.querySelector('#kt-qr-close').addEventListener('click', close);
    if (window.KT && KT.pushOverlay) KT.pushOverlay(ov, close); // Android back closes it
    if (!centreId) { ov.querySelector('#kt-qr-sub').textContent = 'No centre is assigned to your account.'; ov.querySelector('#kt-qr-holder').textContent = '—'; return; }
    fetch(base + '/checkin/centre-code/' + centreId, { headers: { 'Authorization': 'Bearer ' + tok, 'Accept': 'application/json' } })
      .then(function (r) { return r.ok ? r.json() : Promise.reject(new Error('load failed')); })
      .then(function (d) {
        var sub = ov.querySelector('#kt-qr-sub'); if (sub) sub.textContent = (d.centre_name || '') + ' · valid ' + (d.valid_for || 'today');
        // Show the short 6-char code (fresh per open, unique for tracking) so a
        // parent whose camera fails can type it. Falls back to the long code.
        var _mc = d.short_code || d.code;
        if (_mc) { var _cb = ov.querySelector('#kt-qr-codebox'), _cc = ov.querySelector('#kt-qr-code'); if (_cb && _cc) { _cc.textContent = _mc; _cb.style.display = 'block'; } }
        var holder = ov.querySelector('#kt-qr-holder');
        if (window.KT && KT.qrImg && d.code) {
          KT.qrImg(d.code, { size: 224, cell: 6, margin: 2 })
            .then(function (img) { if (holder) { holder.innerHTML = ''; holder.appendChild(img); } })
            .catch(function () { if (holder) holder.textContent = 'QR unavailable'; });
          var pb = ov.querySelector('#kt-qr-print');
          if (pb) pb.addEventListener('click', function () {
            if (window.KT && KT.printQRPoster) KT.printQRPoster({
              url: d.code, title: (d.centre_name || 'Check-in'),
              subtitle: 'Scan to sign your child in or out',
              steps: '<strong>1.</strong> Open the KiddieTrac app.<br><strong>2.</strong> Tap the camera / scan button.<br><strong>3.</strong> Point it at this code.',
              footer: 'Valid ' + (d.valid_for || 'today') + ' · Powered by KiddieTrac',
            });
          });
        } else if (holder) { holder.textContent = 'QR unavailable'; }
      })
      .catch(function () { var s = ov.querySelector('#kt-qr-sub'); if (s) s.textContent = 'Could not load the check-in code.'; var h = ov.querySelector('#kt-qr-holder'); if (h) h.textContent = '—'; });
  }

  function ensure() {
    if (!tok() || document.getElementById('kt-mobilenav')) return;
    injectStyle();
    var scrim = document.createElement('div'); scrim.id = 'kt-mnav-scrim'; scrim.addEventListener('click', closeMenu); document.body.appendChild(scrim);
    duckChromeForDialogs();
    var nav = document.createElement('div'); nav.id = 'kt-mobilenav';
    var btn = function (icon, label, fn, badgeId, hash) {
      var b = document.createElement('button'); b.type = 'button'; if (hash) b.setAttribute('data-hash', hash);
      b.innerHTML = '<span class="ic" style="position:relative;">' + icon + (badgeId ? '<span class="badge" id="' + badgeId + '" hidden></span>' : '') + '</span>' + label;
      b.addEventListener('click', fn); return b;
    };
    var parent = isParentView();
    document.body.classList.toggle('kt-parentview', parent);
    if (parent) {
      // Parents get a full, self-contained bottom bar — every section one tap away.
      // Home returns to the icon-tile launcher (#home), the parent's main screen.
      nav.appendChild(btn('🏠', 'Home', function () { go('#home'); }, null, 'home'));
      nav.appendChild(btn('🖼️', 'Photos', function () { go('#photos'); }, 'kt-b-photos', 'photos'));
      // Center, raised camera button — QR check-in / out. The "camera button".
      var scanBtn = btn('📷', 'Check in', function () { go('#scan'); }, null, 'scan');
      scanBtn.classList.add('scan');
      nav.appendChild(scanBtn);
      nav.appendChild(btn('💬', 'Messenger', function () { go('#messages'); }, 'kt-mnav-msg', 'messages'));
      nav.appendChild(btn('💳', 'Billing', function () { go('#billing'); }, 'kt-b-billing', 'billing'));
    } else {
      // No Menu button for anyone now. The dashboard carries the tile launcher
      // (every section, one tap), so the sidebar drawer was a second, redundant
      // way to reach the same places — and it was the only thing on the bar that
      // didn't navigate.
      // Home goes to whatever the SHELL considers this role's home, NOT a hardcoded
      // #dashboard: educators land on #home (the tile launcher; homeHashForRole →
      // 'home'), admins/directors on #dashboard. Hardcoding #dashboard sent educators
      // to the roster/"today" screen and never back to their launcher home.
      var homeH = 'dashboard';
      try { if (window.KT && KT.Shell && KT.Shell.homeHashForRole) homeH = KT.Shell.homeHashForRole(activeRole()) || 'dashboard'; } catch (e) {}
      /* Admins and directors now have a launcher too (screen-admin-home.js), so Home
         goes there rather than to the dashboard — on a phone the launcher IS how you
         reach anything, which is the whole reason it exists. Only the phone bar is
         changed; homeHashForRole still decides where the DESKTOP lands, where the
         sidebar is present and the dashboard is the right first screen. */
      var _adminRole = ['agency_admin', 'platform_admin', 'centre_director'].indexOf(activeRole()) !== -1;
      if (_adminRole) { homeH = 'home'; }
      nav.appendChild(btn('🏠', 'Home', (function (h) { return function () { go('#' + h); }; })(homeH), null, homeH));
      // Daily log is the thing an educator reaches for most times in a day —
      // it belongs on the bar, not two taps deep in the launcher.
      /* Daily log is an EDUCATOR's tool — it is what they reach for a dozen times a
         day. An admin or director almost never writes one, so on their bar it was a
         wasted slot out of five. They get Children instead, which is the record they
         actually open. The hash differs by role: the admin nav calls it admin-children,
         the director nav calls it children. */
      /* Quick add, moved off the top bar (2026-09-02).
         The actions themselves stay in screen-director-v5a — this reads its buttons and
         forwards the tap, so there is one implementation of "new child" and it is the
         one that already works. */
      if (_adminRole) {
        /* Daily Overview, not Children: it is the screen an admin or director opens each
           morning to see the day across the agency. Children is one tap away on the
           launcher, and #provider-day is registered for all three admin roles
           (screen-provider-day.js). */
        nav.appendChild(btn('🗓️', 'Daily', function () { go('#provider-day'); }, null, 'provider-day'));
      } else {
        nav.appendChild(btn('📝', 'Daily log', function () { go('#care-log'); }, null, 'care-log'));
      }

      /* Third of five, so it lands in the middle — the raised slot the parent bar
         uses for its camera. Order: Home · Children · Add · Messenger · Inbox. */
      if (_adminRole) {
        var qaBtn = btn('\u26A1', 'Add', function () { openAdminQuickAdd(); }, null, '__qadd');
        qaBtn.classList.add('scan');   // the raised centre treatment the parent bar uses
        nav.appendChild(qaBtn);
      }

      if (isEducatorView()) {
        // The educator's check-in QR, as a raised centre button — the same
        // affordance parents get for scanning. A 🔳 glyph floating in the corner
        // was too easy to miss for something parents queue up to scan.
        var qrBtn = btn('📷', 'Check-in QR', function () { showCheckinQr(); }, null, 'eduqr');
        qrBtn.classList.add('scan');
        nav.appendChild(qrBtn);
      }
      nav.appendChild(btn('💬', 'Messenger', function () { go('#chat'); }, 'kt-mnav-msg', 'chat'));
      // Alerts folded INTO the Inbox: an announcement already lands in the inbox
      // as a notification, so a separate Alerts tab showed the same thing twice.
      // (Composing an alert lives on the Home launcher, under Alerts.)
      nav.appendChild(btn('🔔', 'Inbox', function () { go('#notifications'); }, 'kt-b-inbox', 'notifications'));
      // Menu (☰) — admin / director / platform-admin ONLY. These roles have a full
      // sidebar but NO tile launcher (screen-role-home registers :home only for
      // guardian/educator/auditor), so without this the bar's four buttons are the
      // ONLY reachable sections on a phone — Children, Billing, Settings, Centres,
      // Staff, Reports, etc. are all stranded. The drawer-overlay CSS
      // (body.kt-mnav-open #appSidebar) already exists and reveals the real sidebar;
      // this button is the only thing that opens it. Scrim/Android-back/link-tap
      // all close it (see ensure() scrim + kt-back.js). Educators/auditors reach
      // their sections via the #home launcher, so they don't get this.
      var _isAdminNav = (function () {
        var va = ''; try { va = sessionStorage.getItem('kt_view_as') || ''; } catch (e) {}
        if (va) return ['agency_admin', 'platform_admin', 'centre_director'].indexOf(va) > -1;
        // Match the active view: primary_role first (the shell's signal), plus the
        // roles array as a fallback — so an admin whose `roles` array is empty but
        // primary_role is an admin role still gets the Menu drawer.
        var ADMIN = ['agency_admin', 'platform_admin', 'centre_director'];
        try {
          var u = JSON.parse(sessionStorage.getItem('kt_user') || localStorage.getItem('kt_user') || '{}');
          if (ADMIN.indexOf(u.primary_role || '') > -1) return true;
          var r = u.roles || [];
          return ADMIN.some(function (x) { return r.indexOf(x) > -1; });
        } catch (e) { return false; }
      })();
      /* The drawer is only drawn when there is no launcher to send people to.

         It existed because admins and directors had no tile launcher: it revealed the
         DESKTOP sidebar as an overlay — 94 items for an agency admin, one scrolling
         list, no search. They have a launcher as of 2026-09-01 (screen-admin-home.js),
         and Home opens it, so a Menu button would be a second and worse route to the
         same places: same items, same order, without the search or the grouping.

         KT.AdminHome is the launcher's own export, so this asks the thing itself
         whether it is there rather than assuming a file loaded. If it is missing —
         cached build, 404, rename — the drawer comes back exactly as it was, and its
         CSS and scrim were left in place for that reason. */
      var _hasLauncher = !!(window.KT && KT.AdminHome);
      if (_isAdminNav && !_hasLauncher) {
        var menuBtn = btn('☰', 'Menu', function () {
          var open = document.body.classList.toggle('kt-mnav-open');
          menuBtn.setAttribute('aria-expanded', open ? 'true' : 'false');
        }, null, '__menu');
        menuBtn.setAttribute('aria-expanded', 'false');
        menuBtn.setAttribute('aria-haspopup', 'menu');
        nav.appendChild(menuBtn);
      }
    }
    document.body.appendChild(nav);
    pinToVisualViewport();

    // Settings gear — top-right of the parent/educator mobile app (change
    // password, biometrics, PIN, photo, contact). Hidden on desktop (the top bar
    // covers it). Educators are phone-first too and had no gear before.
    /* Admins and directors get the gear too. They were excluded because they had no
       personal profile screen to send it to; screen-settings registers
       agency_admin and centre_director as of 2026-09-01, so it now goes somewhere
       — and it is where their two-factor lives. */
    var showGear = parent || isEducatorView() || _adminRole;
    if (showGear && !document.getElementById('kt-gear')) {
      var gear = document.createElement('button');
      gear.id = 'kt-gear'; gear.type = 'button'; gear.setAttribute('aria-label', 'Settings'); gear.textContent = '⚙️';
      /* top is set by placeGear() from the header's MEASURED box — never from
         env(safe-area-inset-top), which is the value this WebView reports as 0 and
         the reason kt-ios-safearea.js has to exist at all. */
      gear.style.cssText = 'position:fixed;top:8px;right:12px;z-index:9450;width:40px;height:40px;border-radius:50%;border:none;background:rgba(255,255,255,.94);box-shadow:0 2px 10px rgba(15,23,42,.2);font-size:20px;line-height:1;cursor:pointer;display:flex;align-items:center;justify-content:center;';
      gear.addEventListener('click', function () { go('#settings'); });
      document.body.appendChild(gear);
    }

    // The check-in QR now lives on the bottom bar as a raised centre button
    // (see above), so the old floating 🔳 is gone — one obvious entry point,
    // not two easy-to-miss ones. Any stale button from a cached build is removed.
    var staleQr = document.getElementById('kt-eduqr');
    if (staleQr) staleQr.remove();
    // The Menu button is gone, so nothing can open the drawer any more; make sure
    // a cached page can't leave the body stuck in the open state.
    document.body.classList.remove('kt-mnav-open');
    padGearClearance();
    placeGear();

    // Highlight the active section.
    var updateActive = function () {
      var h = (location.hash || '#dashboard').replace('#', '').split('?')[0];
      // For parents the launcher (#home) is the base; Today/dashboard are drilled in from it.
      if (nav.querySelector('button[data-hash="home"]') && (h === 'today' || h === 'dashboard')) h = 'home';
      [].forEach.call(nav.querySelectorAll('button'), function (b) { b.classList.toggle('on', b.getAttribute('data-hash') === h); });
    };
    updateActive();
    window.addEventListener('hashchange', updateActive);
    // close the sidebar overlay whenever a nav link is tapped inside it
    var sb = document.getElementById('appSidebar');
    if (sb && !sb.__ktMnavBound) { sb.__ktMnavBound = true; sb.addEventListener('click', function (e) { if (e.target.closest('a,.nav-link')) closeMenu(); }); }
  }

  // ── Pin the bar to the VISIBLE viewport bottom ──────────────────────
  // On some mobile WebViews the layout viewport (window.innerHeight) is TALLER
  // than the visible area (visualViewport) — e.g. 1513 vs 915 — so a plain
  // `bottom:0` fixed bar lands hundreds of px below the fold, invisible. We offset
  // it up by the difference so it's always glued to the visible bottom (and it
  // rides up with the on-screen keyboard instead of hiding behind it).
  /* iPadOS 13+ reports itself as a Macintosh, so the touch count matters. */
  function isIOS() {
    var ua = navigator.userAgent || '';
    return /iPad|iPhone|iPod/.test(ua)
      || (/Macintosh/.test(ua) && navigator.maxTouchPoints > 1);
  }

  /* The bottom inset, as whatever is authoritative on this device: kt-ios-safearea.js
     writes --kt-safe-bottom where iOS reports env() as zero, and env() itself elsewhere. */
  function bottomInset() {
    try {
      var v = parseFloat(getComputedStyle(document.documentElement)
        .getPropertyValue('--kt-safe-bottom'));
      if (v > 0) { return v; }
    } catch (e) {}
    try {
      var probe = document.createElement('div');
      probe.style.cssText = 'position:fixed;top:0;left:0;width:0;height:0;visibility:hidden;'
        + 'padding-bottom:env(safe-area-inset-bottom, 0px);';
      document.documentElement.appendChild(probe);
      var b = parseFloat(getComputedStyle(probe).paddingBottom) || 0;
      probe.remove();
      return b;
    } catch (e) { return 0; }
  }

  function pinToVisualViewport() {
    var nav = document.getElementById('kt-mobilenav'); if (!nav) return;
    var vv = window.visualViewport;
    if (vv) {
      var offset = window.innerHeight - (vv.offsetTop + vv.height);
      if (!(offset > 1)) { offset = 0; }

      /* THE BAR MUST NOT BE LIFTED BY SPACE IT ALREADY COVERS ITSELF. (2026-09-21)

         Anthony, on the iPhone: "bottom bar still not at the very bottom there is a gap."

         Two things were each paying for the home indicator, so it got paid for twice.
         The bar reserves it as its own padding-bottom (--kt-safe-bottom, so the labels
         clear the indicator), and this lift THEN raised the whole bar by the same amount
         again - leaving exactly one inset of page background showing underneath it.

         That double count is an iOS-only shape, and for the same reason the navy bottom
         strip is Android-only: on Android the bottom inset is an opaque system nav bar
         drawn over us, and the bar genuinely has to sit above it. On iOS it is the home
         indicator, a pill floating OVER the app - Apple's own tab bars run underneath it,
         which is what "bring the bar right to the bottom to fill the area" asks for.

         Subtracting rather than zeroing keeps the KEYBOARD case right, which is the other
         half of this function's job: with a 300pt keyboard up the bar lifts 300-34, and
         its own 34 of padding makes up the difference, so it still sits exactly on the
         keyboard. With no keyboard the offset IS the inset and the bar goes flush. */
      var _inset = bottomInset();
      if (isIOS()) {
        offset = Math.max(0, offset - _inset);
      }

      nav.style.bottom = Math.round(offset) + 'px';

      /* THE NUMBERS, WHERE SOMEBODY CAN READ THEM.

         Every previous round of this was argued from screenshots, which cannot tell a
         bar lifted by 34px from one padded by 34px - they look identical. Same reasoning
         as data-kt-insets on <html>: one attribute turns "still a gap" into a reading.
         Visible in a crash report and in the DOM. */
      try {
        nav.setAttribute('data-kt-pin',
          'vv ' + Math.round(vv.height) + '+' + Math.round(vv.offsetTop)
          + ' vs inner ' + window.innerHeight
          + ' | inset ' + _inset + (isIOS() ? ' (ios, subtracted)' : ' (kept)')
          + ' -> bottom ' + Math.round(offset));
      } catch (e) {}
    } else {
      nav.style.bottom = '0px';
    }
    // Reserve exactly as much room as the bar actually occupies. Hard-coding the
    // clearance is how the Sign out button ended up hidden under it — the bar's
    // height changes with the raised QR button and the safe-area inset.
    var main = document.getElementById('appMain');
    if (main && window.innerWidth <= 600) {
      var need = Math.round(nav.getBoundingClientRect().height) + 16;
      // If the floating back button (FAB, bottom-left) is showing, reserve enough
      // room that content clears IT too — it floats ABOVE the nav bar, so the
      // nav-only clearance leaves the last controls/text hidden behind the FAB.
      var backFab = document.getElementById('kt-role-back');
      if (backFab && getComputedStyle(backFab).display !== 'none') {
        var fr = backFab.getBoundingClientRect();
        if (fr.height > 0) need = Math.max(need, Math.round(window.innerHeight - fr.top) + 16);
      }
      if (need > 20 && main.style.paddingBottom !== need + 'px') {
        main.style.setProperty('padding-bottom', need + 'px', 'important');
      }
    }
  }
  if (window.visualViewport) {
    window.visualViewport.addEventListener('resize', pinToVisualViewport);
    window.visualViewport.addEventListener('scroll', pinToVisualViewport);
  }
  window.addEventListener('resize', pinToVisualViewport);
  window.addEventListener('orientationchange', pinToVisualViewport);
  setInterval(function () { pinToVisualViewport(); padGearClearance(); placeGear(); }, 700);   // catch SPA navigations / URL-bar changes

  // ── Screen-transition animation (phones only) ───────────────────────
  // A real navigation (hashchange) arms a one-shot; the first #appMain childList
  // mutation after it tags the new top-level nodes with .kt-screen-in so the CSS
  // plays a slide-up+fade. In-place data refreshes (no hashchange) are NOT armed,
  // so live-updating screens (chat, dashboards) don't flicker.
  // A nav opens a short window; every top-level node that lands during it gets
  // tagged once (screens often render in a few stages, so we can't just fire on
  // the first mutation). Tagging children — not #appMain itself — keeps a
  // transform off the container, which would otherwise re-anchor the fixed chat
  // overlay. Each node animates at most once, so nothing restarts mid-flight.
  var animUntil = 0;
  // Stop the WebView from restoring the previous scroll position on navigation
  // (that restore, fighting our reset, is the "scrolls down then jumps up" flicker).
  try { if ('scrollRestoration' in history) history.scrollRestoration = 'manual'; } catch (e) {}
  function scrollTop0() {
    try {
      window.scrollTo(0, 0);
      if (document.scrollingElement) document.scrollingElement.scrollTop = 0;
      if (document.documentElement) document.documentElement.scrollTop = 0;
      if (document.body) document.body.scrollTop = 0;
      var mm = document.getElementById('appMain'); if (mm) mm.scrollTop = 0;
    } catch (e) {}
  }
  window.addEventListener('hashchange', function () {
    // The screen renders ASYNC (data fetch), so a single reset on hashchange gets
    // undone by the post-render layout shift. Reset now AND across the render window.
    scrollTop0();
    /* ...but only until the reader takes over. A flick in the first half-second of a
       new screen was pulled back to the top up to four more times — "it jumps and goes
       back to the top". The shell records the last touch/wheel in __ktUserScrollAt. */
    var navAt = Date.now();
    var resetUnlessTouched = function () {
      if ((window.__ktUserScrollAt || 0) > navAt) { return; }
      scrollTop0();
    };
    requestAnimationFrame(resetUnlessTouched);
    [40, 120, 260, 450].forEach(function (d) { setTimeout(resetUnlessTouched, d); });
    if (window.innerWidth > 600) return;
    animUntil = Date.now() + 450;
  });
  function playScreenAnim(m) {
    if (Date.now() > animUntil) return;
    [].forEach.call(m.children, function (c) {
      if (c.__ktAnimated) return;
      c.__ktAnimated = true;
      c.classList.add('kt-screen-in');
    });
  }
  (function watchAppMain() {
    var m = document.getElementById('appMain');
    if (!m) { setTimeout(watchAppMain, 300); return; }
    if (m.__ktAnimObs) return; m.__ktAnimObs = true;

    /* Re-binds when the shell swaps #appMain. The callback resolves the node itself
       rather than closing over `m`, or after the first swap it would animate the node
       that was thrown away. */
    var play = function () {
      var live = document.getElementById('appMain');
      if (live) { playScreenAnim(live); }
    };
    if (window.KT && KT.observeMain) { KT.observeMain(play, { childList: true }); }
    else { new MutationObserver(play).observe(m, { childList: true }); }
  })();

  // ── Unread counters on the bottom bar ──────────────────────────────
  // Messages badge = unread chats; Photos/Billing/Home badges = unread
  // notifications of that kind (photos / invoices+payments / everything else).
  function _apiBase() { return (window.KT_CONFIG && window.KT_CONFIG.apiBase) || 'https://api.kiddietrac.com/api/v1'; }
  function _get(path) {
    var t = tok(); if (!t) return Promise.resolve(null);
    var h = { 'Authorization': 'Bearer ' + t, 'Accept': 'application/json' };
    try { var aid = sessionStorage.getItem('kt_active_agency_id'); if (aid) h['X-Active-Agency-Id'] = aid; var va = sessionStorage.getItem('kt_view_as'); if (va) h['X-View-As-Role'] = va; } catch (e) {}
    return fetch(_apiBase() + path, { headers: h }).then(function (r) { return r.ok ? r.json() : null; }).catch(function () { return null; });
  }
  function _setBadge(id, n) {
    var el = document.getElementById(id); if (!el) return;
    if (n > 0) { el.textContent = n > 99 ? '99+' : String(n); el.hidden = false; }
    else { el.hidden = true; el.textContent = ''; }
  }
  // Sections the user is currently viewing shouldn't keep nagging with a badge —
  // suppress the matching badge while its section is open (server-side read state
  // is marked by the screen; this is the instant local echo so it clears at once).
  function curSection() { return (location.hash || '').replace('#', '').split('?')[0]; }
  function refreshBadges() {
    if (!tok()) return;
    // Parents use the /parent/messages store (not /chats); staff use /chats.
    var msgPath = isParentView() ? '/parent/messages/unread-count' : '/chats/unread-count';
    _get(msgPath).then(function (d) { if (d) _setBadge('kt-mnav-msg', curSection() === 'messages' || curSection() === 'chat' ? 0 : (d.unread || 0)); });
    _get('/notifications').then(function (d) {
      if (!d) return;
      var rows = d.data || d.notifications || (Array.isArray(d) ? d : []);
      if (!Array.isArray(rows)) rows = [];
      var cat = { photos: 0, billing: 0, other: 0 };
      rows.forEach(function (n) {
        if (n.read_at) return;
        var s = ((n.type || '') + ' ' + (n.title || '') + ' ' + (n.body || '') + ' ' + (typeof n.data === 'string' ? n.data : JSON.stringify(n.data || ''))).toLowerCase();
        if (/photo|image|gallery|picture/.test(s)) cat.photos++;
        else if (/invoice|billing|payment|receipt/.test(s)) cat.billing++;
        else cat.other++;
      });
      var sec = curSection();
      _setBadge('kt-b-photos', sec === 'photos' ? 0 : cat.photos);
      _setBadge('kt-b-billing', sec === 'billing' ? 0 : cat.billing);
      // Staff bar: everything unread that isn't a message lands in Inbox, so the
      // count matches what the Notifications screen will actually show them.
      var totalUnread = cat.photos + cat.billing + cat.other;
      _setBadge('kt-b-inbox', sec === 'notifications' ? 0 : totalUnread);
    });
  }
  setInterval(refreshBadges, 15000);   // was 60s, and it disagreed with the top bar's 15s figure
  // On entering a section, instantly clear its badge, then re-sync shortly after
  // (the screen marks its notifications read on open).
  window.addEventListener('hashchange', function () {
    var sec = curSection();
    if (sec === 'billing') _setBadge('kt-b-billing', 0);
    if (sec === 'photos') _setBadge('kt-b-photos', 0);
    if (sec === 'notifications') _setBadge('kt-b-inbox', 0);
    if (sec === 'messages' || sec === 'chat') _setBadge('kt-mnav-msg', 0);
    setTimeout(refreshBadges, 1500);
  });

  (window.KT && KT.sweepBus) ? KT.sweepBus.on(ensure) : setInterval(ensure, 1500);
  function boot() { ensure(); setTimeout(refreshBadges, 1200); }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot); else boot();

  // ── One-shot DEVICE scroll diagnostic (native only) ─────────────────
  // On the first Home nav after launch, sample the real scroll state every 50ms
  // and POST it so we can SEE the phone's behaviour (which desktop can't reproduce).
  (function () {
    try {
      var C = window.Capacitor; var native = C && (C.isNativePlatform ? C.isNativePlatform() : C.isNative);
      if (!native) return;
    } catch (e) { return; }
    var done = false;
    window.addEventListener('hashchange', function () {
      var h = (location.hash || '').replace('#', '').split('?')[0];
      if (h !== 'home' || done) return;
      done = true;
      var samples = [], t0 = Date.now();
      var iv = setInterval(function () {
        var m = document.getElementById('appMain');
        var de = document.scrollingElement || document.documentElement;
        samples.push((Date.now() - t0) + ':aM=' + (m ? Math.round(m.scrollTop) : -1) + ',doc=' + Math.round(de.scrollTop) + ',vvTop=' + (window.visualViewport ? Math.round(window.visualViewport.offsetTop) : -1));
        if (Date.now() - t0 > 1300) {
          clearInterval(iv);
          try {
            var m2 = document.getElementById('appMain');
            var base = (window.KT_CONFIG && window.KT_CONFIG.apiBase) || 'https://api.kiddietrac.com/api/v1';
            var meta = 'aMh=' + (m2 ? m2.scrollHeight : '?') + ' aMclient=' + (m2 ? m2.clientHeight : '?') + ' innerH=' + innerHeight + ' vvH=' + (window.visualViewport ? Math.round(window.visualViewport.height) : '?') + ' shellDisp=' + (function () { var s = document.getElementById('appShell'); return s ? getComputedStyle(s).display : '?'; })();
            fetch(base + '/diag/scroll', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ data: meta + ' || ' + samples.join('  ') }) }).catch(function () {});
          } catch (e) {}
        }
      }, 50);
    });
  })();
})();
