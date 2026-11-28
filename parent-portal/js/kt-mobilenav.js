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
    + 'body.kt-dialog-open #kt-hrq-fab,'
    + 'body.kt-dialog-open #kt-agency-switcher{z-index:500 !important;}'
    // The dial pad's floating 📞 sat ON TOP of every popup (z 9300). Gone while one is open (2026-10-23).
    + 'body.kt-dialog-open #kt-dp-fab{display:none !important;}'
    /* NOTHING BEHIND A POPUP ANSWERS A TAP (2026-10-23, Anthony: "I can still click on the bottom
       bar and goto other sections while this popup is still on the screen - full sweep"). The
       z-index duck relied on every popup covering the bar; on some phones one did not. While a
       popup is open, the bottom bar, the gear and the agency switcher simply ignore taps. */
    /* SWALLOWED, NOT CLICK-THROUGH (2026-10-28, Anthony: "hitting phone at the bottom bar, it pops
       up a random screen and it locks up"). pointer-events:none made a tap on the bar land on
       whatever lay UNDER it - a Families row, whose popup then opened behind the dial pad. The bar
       keeps its pointer events; swallowTaps() below eats the tap while a popup is open. */
    + '';
  var CHROME_IDS = ['kt-mobilenav', 'kt-gear', 'kt-agency-switcher'];
  function inChrome(t) {
    for (var n = t; n && n.nodeType === 1; n = n.parentElement) { if (CHROME_IDS.indexOf(n.id) !== -1) { return true; } }
    return false;
  }
  /* RE-CHECKED AT THE TAP (2026-10-28, Anthony: "closed the popup ... the bottom bar is frozen"). The flag is
     refreshed on DOM changes; if it is ever stale, a tap must not be eaten for a popup that is gone. */
  var dlgBeaconed = {};
  function dlgBeacon(kind, what) {
    if (dlgBeaconed[kind]) { return; }
    dlgBeaconed[kind] = 1;
    try {
      var tok = sessionStorage.getItem('kt_token') || localStorage.getItem('kt_token');
      fetch('https://api.kiddietrac.com/api/v1/diag/scroll', { method: 'POST', keepalive: true,
        headers: { 'Content-Type': 'application/json', Accept: 'application/json', Authorization: tok ? 'Bearer ' + tok : '' },
        body: JSON.stringify({ data: kind + ' ' + location.hash + ' ' + String(what || '').slice(0, 300) }) }).catch(function () {});
    } catch (err) {}
  }
  function swallowTaps(e) {
    if (!document.body || !document.body.classList.contains('kt-dialog-open') || !inChrome(e.target)) { return; }
    var what = dialogOnScreen(true);
    if (!what) {
      document.body.classList.remove('kt-dialog-open');
      dlgBeacon('STALEDLG', 'flag was on with no popup; tap let through');
      return;
    }
    if (e.type === 'click') { dlgBeacon('DLGBLOCK', what); }
    e.preventDefault(); e.stopPropagation(); if (e.stopImmediatePropagation) { e.stopImmediatePropagation(); }
  }
  ['click', 'pointerup', 'touchend', 'mouseup'].forEach(function (t) { window.addEventListener(t, swallowTaps, { capture: true, passive: false }); });
  /* Not blocking dialogs: the phone dial pad and its Texts sheet. The bar stays live under them -
     kt-dialer closes the pad when another bar item is tapped, and Phone toggles it. */
  var NONBLOCKING = '#kt-dpop, #kt-txfull, #kt-dpop *, #kt-txfull *';

  /* Is a dialog on screen? The same shape test KT.uiBusy() uses, so the portal keeps ONE
     definition of "a dialog is open" rather than two that drift. uiBusy() itself is not
     called: it also answers true for a focused input, which is about deferring refreshes,
     not about furniture. */
  /* VISIBLE only (2026-10-28): a hidden leftover node of a closed popup used to count. */
  function shown(el) {
    if (!el.getClientRects().length) { return false; }
    var cs = window.getComputedStyle(el);
    if (cs.visibility === 'hidden' || parseFloat(cs.opacity || '1') < 0.05) { return false; }
    var r = el.getBoundingClientRect();
    return r.width > 40 && r.height > 40;
  }
  function label(el) { return el.tagName + '#' + (el.id || '') + '.' + String(el.className || '').slice(0, 50); }
  function dialogOnScreen(describe) {
    try {
      var found = document.querySelectorAll('.kt-modal, .kt-modal-overlay, .modal-backdrop, .kt-scrim,'
          + ' .kt-lightbox, .kt-doc-viewer, .kt-av-zoom, [role="dialog"]');
      for (var f = 0; f < found.length; f++) { if (!found[f].matches(NONBLOCKING) && shown(found[f])) { return describe ? label(found[f]) : true; } }
      var mr = document.getElementById('modalRoot');
      if (mr && mr.firstElementChild && shown(mr.firstElementChild)) { return describe ? 'modalRoot > ' + label(mr.firstElementChild) : true; }
      var kids = document.body ? document.body.children : [];
      for (var i = 0; i < kids.length; i++) {
        var el = kids[i];
        if (el.id === 'appMain' || el.id === 'kt-mobilenav' || el.id === 'kt-gear'
            || el.id === 'kt-agency-switcher' || el.id === 'kt-dpop' || el.id === 'kt-txfull') { continue; }
        if (el.tagName === 'SCRIPT' || el.tagName === 'STYLE' || el.tagName === 'LINK') { continue; }
        if (el.hidden) { continue; }
        var cs = window.getComputedStyle(el);
        if (cs.position !== 'fixed' || cs.display === 'none' || cs.visibility === 'hidden') { continue; }
        if (parseFloat(cs.opacity || '1') < 0.05) { continue; }
        /* A layer that takes no taps is decoration, not a popup (2026-10-23): the clocked-in
           screen glow (#kt-clockglow) counted as one, so for anyone on the clock the bar was
           always "behind a dialog" — and with taps now blocked behind dialogs, unusable. */
        if (cs.pointerEvents === 'none' || el.id === 'kt-clockglow' || /^claude-/.test(el.id || '')) { continue; }
        var z = parseInt(cs.zIndex, 10);
        if (isNaN(z) || z < 900) { continue; }
        var r = el.getBoundingClientRect();
        if (r.width < 200 || r.height < 120) { continue; }   // a badge, not a dialog
        return describe ? 'fixed ' + label(el) + ' z' + z : true;
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
    /* A POPUP THAT FADES IN IS STILL A POPUP (2026-11-03). The test runs one frame after a node lands on <body>,
       and shown() rightly ignores anything under 5% opacity - but a popup that fades in from opacity 0 (the photo
       viewer, kt-avatar-zoom: 0 -> 1 over 180ms) is still at ~0 in that frame, and nothing else on <body> changes
       afterwards, so it was never counted: no kt-dialog-open, the bar was not ducked and taps on it were not
       swallowed for as long as the viewer stayed open. Measured: dialogOpen false at 60, 400 and 900ms.
       The test now runs again when a transition or animation on a top-level layer ends (the end of the fade).
       Only <body>'s own children and #modalRoot's are considered, so the many small transitions inside a
       screen cost nothing. */
    var layerSettled = function (e) {
      var t = e && e.target;
      if (!t || t.nodeType !== 1) { return; }
      var p = t.parentElement;
      if (p === document.body || (p && p.id === 'modalRoot')) { schedule(); }
    };
    document.addEventListener('transitionend', layerSettled, true);
    document.addEventListener('animationend', layerSettled, true);
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
        '#kt-mobilenav{--duo-ink:#1E3A5F;--duo-fill:rgba(42,143,157,.16);}',
        '#kt-mobilenav button.on{--duo-ink:#0E7C90;--duo-fill:rgba(14,124,144,.34);}',
        '#kt-mobilenav button.scan .ic{--duo-ink:#fff;--duo-fill:rgba(255,255,255,.28);}',
        '#kt-mobilenav .kt-duo{display:block;width:24px;height:24px;overflow:visible;}',
        '#kt-mobilenav button.scan .kt-duo{width:26px;height:26px;}',
        '#kt-mobilenav .kt-duo .f *{fill:var(--duo-fill);stroke:none;}',
        '#kt-mobilenav .kt-duo .l *{fill:none;stroke:var(--duo-ink);stroke-width:1.8;stroke-linecap:round;stroke-linejoin:round;}',
        '#kt-mobilenav .kt-duo .l [stroke-width]{stroke-width:2.6;}',
        '#kt-mobilenav .kt-mnav-g{position:absolute;width:1px;height:1px;overflow:hidden;clip:rect(0 0 0 0);clip-path:inset(50%);white-space:nowrap;}',
        'html.kt-dark #kt-mobilenav,html[data-theme="dark"] #kt-mobilenav{--duo-ink:#CBD5E1;--duo-fill:rgba(45,212,191,.18);}',
        'html.kt-dark #kt-mobilenav button.on,html[data-theme="dark"] #kt-mobilenav button.on{--duo-ink:#5EEAD4;--duo-fill:rgba(45,212,191,.36);}',
        '#kt-mobilenav button:active{color:#1F6080;}',
        '#kt-mobilenav button.on{color:#0E7C90;font-weight:800;}',
        '#kt-mobilenav button.on .ic{transform:translateY(-2px) scale(1.06);}',
        '#kt-mobilenav button.on::before{content:"";position:absolute;top:0;left:50%;transform:translateX(-50%);width:26px;height:3px;border-radius:0 0 3px 3px;background:#0E7C90;}',
        // Center raised camera/QR check-in button (restored to the original look).
        '#kt-mobilenav button.scan{color:#0E7C90;font-weight:800;}',
        '#kt-mobilenav button.scan .ic{width:50px;height:50px;margin-top:-22px;border-radius:50%;background:#0E7C90;color:#fff;display:flex;align-items:center;justify-content:center;font-size:23px;box-shadow:0 6px 16px -4px rgba(14,124,144,.7);border:3px solid #fff;}',
        '#kt-mobilenav button.scan.on .ic{transform:none;}',
        '#kt-mobilenav button.scan.on::before{display:none;}',
        /* ACTIVE RAISED BUTTON (2026-11-24): .scan is always bold + coloured, so "on" showed nothing. While its screen
           or its popover is up it gets the tab indicator under its label and a white inner ring on the circle. */
        '#kt-mobilenav#kt-mobilenav button.scan.on::before{display:block;top:auto;bottom:2px;}',
        '#kt-mobilenav button.scan.on .ic{outline:2px solid rgba(255,255,255,.92);outline-offset:-8px;}',
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
      /* The HR "+" (#kt-hrq-fab) lives on the gear's row, so it goes wherever the gear goes: every rule that hides the
         gear hides it too (2026-11-27, Anthony's phone: the "+" stayed over a team chat thread's "+ Add people" after
         the gear had gone - the gear is hidden here by CSS the moment the thread draws, and kt-hr-quick only looked
         again on a hash change). */
      '@media(min-width:' + GEARBP + 'px){#kt-gear,#kt-hrq-fab{display:none !important;}}',
      'body.kt-mnav-open #kt-gear,body.kt-mnav-open #kt-hrq-fab{display:none;}',
      // Hide the gear while a full-screen chat thread is open (it would float over the header).
      'body:has(.kt-thread-compose) #kt-gear,body:has(.kt-thread-compose) #kt-hrq-fab{display:none !important;}',
      // The 📞 docked beside the gear (body.kt-dp-docked) is on that row too: it floated over a parent's chat thread.
      'body.kt-dp-docked:has(.kt-thread-compose) #kt-dp-fab,body.kt-dp-docked.kt-mnav-open #kt-dp-fab{display:none !important;}'
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
    dockDialFab(want);
    dockHrq(want);
  }

  /* THE HR "＋" BESIDE THE GEAR (HR Phase 4 batch 10, 2026-11-27): kt-hr-quick.js adds #kt-hrq-fab (quick requests:
     time off, sick, overtime, claim, hazard, injury) for staff roles. It docks on the gear's row, left of the gear and
     of the 📞 when that is docked too - the bars keep their own five tabs. */
  function hrqOn() { return !!document.getElementById('kt-hrq-fab'); }
  function dockHrq(top) {
    var f = document.getElementById('kt-hrq-fab');
    if (!f) return;
    var right = (dialFabOn() && document.body.classList.contains('kt-dp-docked') ? 108 : 60) + 'px';
    if (f.style.getPropertyValue('top') !== top || f.style.getPropertyPriority('top') !== 'important') f.style.setProperty('top', top, 'important');
    if (f.style.getPropertyValue('right') !== right) f.style.setProperty('right', right, 'important');
  }

  /* THE 📞 BESIDE THE GEAR (2026-11-06, Anthony: "the floating call ... fix those too"). kt-dialer.js drew the dial
     pad as a round button above the bottom bar, over the page, so it covered the bottom-left stat card or tile on every
     staff phone. With a gear on screen it now sits in the header, left of the gear on the same row (body.kt-dp-docked,
     styled in kt-dialer.js), and padGearClearance makes room for it as it did for the educator's old QR button. */
  function dialFabOn() { var f = document.getElementById('kt-dp-fab'); return !!f && window.getComputedStyle(f).display !== 'none'; }
  function dockDialFab(top) {
    var f = document.getElementById('kt-dp-fab');
    var on = !!f && !!document.getElementById('kt-gear');
    if (document.body.classList.contains('kt-dp-docked') !== on) document.body.classList.toggle('kt-dp-docked', on);
    if (on && (f.style.getPropertyValue('top') !== top || f.style.getPropertyPriority('top') !== 'important')) f.style.setProperty('top', top, 'important');
  }

  function padGearClearance() {
    if (!NATIVE && window.innerWidth > 768) return;
    if (!document.getElementById('kt-gear')) return;
    var nu = document.getElementById('navUser');
    if (!nu) return;
    /* Each floating button is 40px wide with an 8px gap; the QR button (educators) sits left
       of the gear, so the name/role block has to clear both.
       This used to bail out whenever the greeting eyebrow existed, on the grounds that a
       right-aligned date block (kt-pc-mobmeta) already cleared the gear - but that block was
       folded into the eyebrow long ago, and on a 375px iPhone the gear sat on the role pill
       (2026-10-29). The eyebrow clears nothing. Its row is tighter (kt-phone-compact.css trims
       the logo's side padding), so the gap it needs is just the button and a breath. */
    var eyebrow = !!document.getElementById('kt-pc-navgreet');
    var pad = (((document.getElementById('kt-eduqr') || dialFabOn()) ? (eyebrow ? 94 : 106) : (eyebrow ? 46 : 58)) + (hrqOn() ? 48 : 0)) + 'px';
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
          ['agency_admin', 'centre_director', 'educator', 'guardian', 'auditor', 'home_visitor', 'sales_rep'].indexOf(va) !== -1) return va;
      if (roles.indexOf('agency_admin') !== -1) return 'agency_admin';
      if (roles.indexOf('platform_admin') !== -1) return 'agency_admin';
      if (roles.indexOf('centre_director') !== -1) return 'centre_director';
      if (roles.indexOf('educator') !== -1) return 'educator';
      if (roles.indexOf('guardian') !== -1) return 'guardian';
      if (roles.indexOf('home_visitor') !== -1) return 'home_visitor';
      if (roles.indexOf('auditor') !== -1) return 'auditor';
      if (roles.indexOf('sales_rep') !== -1) return 'sales_rep';
      if (roles.indexOf('reseller_admin') !== -1) return 'reseller_admin';
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
  /* For the desktop/tablet top bar (kt-topbar), which has no bottom bar to carry the
     educator's Check-in QR (2026-10-05). Same overlay, same daily code. */
  window.KT = window.KT || {};
  window.KT.showCheckinQr = function () { showCheckinQr(); };
  window.KT.mnavIsEducator = function () { return isEducatorView(); };
  window.KT.mnavIsPhone = function () { return isPhoneSized(); };
  // HR quick "＋" (kt-hr-quick.js) calls this after adding or removing its header button.
  window.KT.mnavPlace = function () { try { padGearClearance(); placeGear(); } catch (e) {} };

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
  function openAdminQuickAdd(anchor) {
    if (document.getElementById('kt-qa-sheet')) return;
    var src = document.querySelectorAll('.kt-v5a-actions button');
    var items = [];
    for (var i = 0; i < src.length; i++) {
      var t = (src[i].textContent || '').trim();
      if (!t || /Quick add/i.test(t)) { continue; }   // the collapse header, not an action
      items.push(src[i]);
    }

    /* QUICK POPOVER (2026-11-24): this short menu of actions is KT.quickPop (kt-quickpop.js), the one quick-choice look. */
    if (window.KT && KT.quickPop) {
      KT.quickPop({ anchor: anchor, id: 'kt-qa-pop', label: 'Quick add', empty: 'Nothing to add from here yet. Open a centre screen first.',
        items: items.map(function (b) { var s = KT.quickPopSplit(b.textContent); return { icon: s.icon, label: s.label, run: function () { b.click(); } }; }) });
      return;
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

  /* ONE-TAP = TWO CHOICES (2026-11-24, Anthony: "the one tap on the educator view should have the QR or the Camera as
     an option"). A small standard popup: show the centre's One-Tap code (the code parents scan, unchanged) or scan a
     temporary pickup person's pass with the camera (KT.pickupPassScan in kt-polish.js). Remembers nothing. */
  /* ONE-TAP = TWO CHOICES, AS A QUICK GLASS POPOVER (2026-11-24, Anthony: "the pop up should be a transparent quick
     popup for the quick tap"). His explicit exception to the one-popup-header rule, for THIS chooser only: no header,
     no footer, no ✕. The page stays visible behind a light frosted dim; two big glass buttons. On a phone it pops up
     just above the raised One-Tap button; on desktop it drops down from the One-Tap icon. Tap outside, Esc or Android
     back closes it; a choice closes it and runs at once. Remembers nothing. The code popup and the pass popup it opens
     stay standard. */
  function oneTapMenu(anchor) {
    /* QUICK POPOVER (2026-11-24): this short menu of actions is KT.quickPop (kt-quickpop.js), the one quick-choice look. */
    if (!(window.KT && KT.pickupPassScan && KT.quickPop)) { showCheckinQr(); return; }
    KT.quickPop({ anchor: anchor, id: 'kt-otm', label: 'One-Tap', items: [
      { icon: '📲', label: 'Show the centre’s code', sub: 'Parents scan it', aria: 'Show the centre’s One-Tap code', run: function () { showCheckinQr(); } },
      { icon: '📷', label: 'Scan a pickup pass', sub: 'With the camera', aria: 'Scan a temporary pickup person’s pass with the camera', run: function () { KT.pickupPassScan(); } },
    ] });
  }
  /* The OFFICIAL One-Tap mark (fingertip + teal arcs, white-paper onetap_mark()), drawn as-is: Anthony 2026-11-24, "the one
     tap icon instead of using a camera use a better icon". The parent's #scan camera stays 📷 (a different action). */
  var ONETAP_MARK = '<svg width="30" height="30" viewBox="0 0 64 64" xmlns="http://www.w3.org/2000/svg" aria-hidden="true" focusable="false" style="display:block;"><defs><linearGradient id="ktOtmBar" x1="0" y1="0" x2="1" y2="1"><stop offset="0" stop-color="#12307A"/><stop offset="1" stop-color="#0A1E55"/></linearGradient></defs><rect width="64" height="64" rx="17" fill="url(#ktOtmBar)"/><path d="M19.55 31.01 A13 13 0 0 1 32.99 44.45" fill="none" stroke="#1DB9C3" stroke-width="4.6" stroke-linecap="round" opacity="1"/><path d="M19.27 23.01 A21 21 0 0 1 40.99 44.73" fill="none" stroke="#1DB9C3" stroke-width="4.6" stroke-linecap="round" opacity="0.8"/><path d="M18.99 15.02 A29 29 0 0 1 48.98 45.01" fill="none" stroke="#1DB9C3" stroke-width="4.6" stroke-linecap="round" opacity="0.6"/><circle cx="20" cy="44" r="7.2" fill="#fff"/><circle cx="20" cy="44" r="2.6" fill="#1DB9C3"/></svg>';
  window.KT.oneTapMenu = function (anchor) { oneTapMenu(anchor); };

  function showCheckinQr() {
    // Guard: a stale/second overlay stacking made the button feel unresponsive
    // ("kept clicking"). If one's already up, don't build another.
    if (document.getElementById('kt-qr-overlay')) return;
    var u = {}; try { u = JSON.parse(sessionStorage.getItem('kt_user') || localStorage.getItem('kt_user') || '{}'); } catch (e) {}
    var centreId = u.centre_id;
    var tok = null; try { tok = sessionStorage.getItem('kt_token') || localStorage.getItem('kt_token'); } catch (e) {}
    var base = (window.KT_CONFIG && window.KT_CONFIG.apiBase) || 'https://api.kiddietrac.com/api/v1';
    /* A STANDARD POPUP (2026-11-06, ticket #225). This was a full-window navy overlay with Print / Close at the foot
       of the content. In App Review's iPhone-app-on-iPad window (466x678) the content ran taller than the window and
       Close sat off screen (breadcrumb "XREACH close off screen: kt-qr-overlay"). Now Shell.Modal: the navy-to-teal
       header with the ✕ inside it, the body the one scroll region, Print / Close pinned in the footer - reachable at
       any size. The body keeps id kt-qr-overlay, so the one-at-a-time guard above still holds. */
    var ov = document.createElement('div');
    ov.id = 'kt-qr-overlay';
    ov.style.cssText = 'display:flex;flex-direction:column;align-items:center;text-align:center;color:#0F172A;';
    ov.innerHTML =
      '<div id="kt-qr-sub" style="font-size:13.5px;color:#475569;margin-bottom:12px;">Loading…</div>'
      + '<div id="kt-qr-holder" style="background:#fff;border:1px solid #E2E8F0;padding:12px;border-radius:14px;width:236px;height:236px;max-width:100%;box-sizing:border-box;display:flex;align-items:center;justify-content:center;color:#64748B;font-size:13px;">Generating…</div>'
      + '<div id="kt-qr-codebox" style="display:none;margin-top:12px;width:100%;max-width:300px;">'
      +   '<div style="font-size:12px;color:#475569;margin-bottom:6px;">📷 No camera? Enter this code in the app:</div>'
      +   '<div id="kt-qr-code" style="font-family:ui-monospace,Menlo,monospace;font-size:24px;font-weight:800;letter-spacing:5px;text-align:center;color:#0B2545;background:#F1F5F9;border:1px dashed #94A3B8;border-radius:10px;padding:10px 12px;user-select:all;-webkit-user-select:all;"></div>'
      + '</div>'
      + '<div style="font-size:12px;color:#64748B;margin-top:12px;max-width:300px;line-height:1.5;">One-Tap: parents scan this with the KiddieTrac app to check in or out, contactless (or type the code above). It refreshes every day for security.</div>';
    var qrData = null;
    var close = function () {};
    var Sh = window.KT && KT.Shell;
    if (Sh && Sh.Modal && Sh.Modal.open) {
      var handle = Sh.Modal.open({
        title: '📲 One-Tap code', body: ov,
        actions: [
          { label: '🖨 Print', primary: true, busyLabel: 'Preparing…', handler: function () {
            /* Prints the poster on its own (kt-qr.js), never the page behind this code:
               agency name and logo, centre, QR, the typeable code and the steps. Stays open. */
            var d = qrData;
            if (!d || !d.code || !(window.KT && KT.printQRPoster)) return false;
            return KT.printQRPoster({
              data: d.code, kind: 'app', centreId: centreId, centreName: d.centre_name || '',
              shortCode: d.short_code || '', validFor: d.valid_for || '',
              agencyName: d.agency_name || '', agencyLogo: d.agency_logo || '',
              brandColor: d.brand_color || '', poweredBy: d.powered_by !== false,
            }).catch(function () {}).then(function () { return false; });
          } },
          { label: 'Close' },
        ],
      });
      close = function () { try { handle.close(); } catch (e) {} };
      if (window.KT && KT.pushOverlay && handle && handle.backdrop) KT.pushOverlay(handle.backdrop, close); // Android back closes it
    } else {
      // The shell is not up (should not happen once signed in): the same content, simply on the page.
      var bd = document.createElement('div');
      bd.style.cssText = 'position:fixed;inset:0;z-index:99999;background:rgba(8,28,65,.6);display:flex;align-items:center;justify-content:center;padding:16px;';
      var card = document.createElement('div');
      card.style.cssText = 'background:#fff;border-radius:16px;max-height:100%;overflow-y:auto;padding:18px;box-sizing:border-box;width:100%;max-width:360px;';
      card.appendChild(ov);
      var cb = document.createElement('button'); cb.type = 'button'; cb.textContent = 'Close';
      cb.style.cssText = 'margin-top:14px;height:32px;padding:0 16px;border-radius:8px;border:1px solid #CBD5E1;background:#fff;font-weight:700;';
      card.appendChild(cb); bd.appendChild(card); document.body.appendChild(bd);
      close = function () { if (bd.parentNode) bd.parentNode.removeChild(bd); };
      cb.addEventListener('click', close);
    }
    if (!centreId) { ov.querySelector('#kt-qr-sub').textContent = 'No centre is assigned to your account.'; ov.querySelector('#kt-qr-holder').textContent = '—'; return; }
    fetch(base + '/checkin/centre-code/' + centreId, { headers: { 'Authorization': 'Bearer ' + tok, 'Accept': 'application/json' } })
      .then(function (r) { return r.ok ? r.json() : Promise.reject(new Error('load failed')); })
      .then(function (d) {
        var sub = ov.querySelector('#kt-qr-sub'); if (sub) sub.textContent = (d.centre_name || '') + ' · valid ' + (d.valid_for || 'today');
        // Show the short 6-char code (fresh per open, unique for tracking) so a
        // parent whose camera fails can type it. Falls back to the long code.
        var _mc = d.short_code || d.code;
        if (_mc) { var _cb = ov.querySelector('#kt-qr-codebox'), _cc = ov.querySelector('#kt-qr-code'); if (_cb && _cc) { _cc.textContent = _mc; _cb.style.display = 'block'; } }
        qrData = d;
        var holder = ov.querySelector('#kt-qr-holder');
        if (window.KT && KT.qrImg && d.code) {
          KT.qrImg(d.code, { size: 210, cell: 6, margin: 2 })
            .then(function (img) { if (holder) { img.style.maxWidth = '100%'; img.style.height = 'auto'; holder.innerHTML = ''; holder.appendChild(img); } })
            .catch(function () { if (holder) holder.textContent = 'One-Tap code unavailable'; });
        } else if (holder) { holder.textContent = 'One-Tap code unavailable'; }
      })
      .catch(function () { var s = ov.querySelector('#kt-qr-sub'); if (s) s.textContent = 'Could not load the One-Tap code.'; var h = ov.querySelector('#kt-qr-holder'); if (h) h.textContent = '—'; });
  }

  /* DUOTONE BAR ICONS (2026-11-24, Anthony: "on the bottom bar for mobile use higher quality images for the quick
     access buttons", duotone chosen). Each bar emoji is drawn as a 24px outline (1.8 stroke, round ends, the
     kt-line-icons grid) over a soft teal fill; the active tab turns the stroke teal and the fill stronger (CSS tokens
     --duo-ink / --duo-fill on the button). The emoji stays in the button, hidden (.kt-mnav-g), for anything that reads
     it. Anything not in this map (the official One-Tap mark) is drawn as given. */
  var DUO = {
    '🏠': ['<path d="M5.5 10.2 12 4.6l6.5 5.6V20h-13z"/>', '<path d="M3.5 11.5 12 4.3l8.5 7.2"/><path d="M5.5 10v10h13V10"/><path d="M10 20v-5.5h4V20"/>'],
    '📝': ['<rect x="4.5" y="3.5" width="12" height="17" rx="2"/>', '<rect x="4.5" y="3.5" width="12" height="17" rx="2"/><path d="M8 8h5M8 11.5h5M8 15h2.5"/><path d="M19.6 9.4 14 15l-.7 2.7 2.7-.7 5.6-5.6a1.5 1.5 0 0 0-2-2z"/>'],
    '✍️': ['<rect x="4.5" y="3.5" width="12" height="17" rx="2"/>', '<rect x="4.5" y="3.5" width="12" height="17" rx="2"/><path d="M8 8h5M8 11.5h3"/><path d="M7.5 17c1.2-1.4 2-1.4 2.6 0s1.4 1.4 2.6-.4"/><path d="M19.6 9.4 14 15l-.7 2.7 2.7-.7 5.6-5.6a1.5 1.5 0 0 0-2-2z"/>'],
    '💬': ['<path d="M4 6.5A2.5 2.5 0 0 1 6.5 4h11A2.5 2.5 0 0 1 20 6.5v7a2.5 2.5 0 0 1-2.5 2.5H10l-4.5 3.5V16A2.5 2.5 0 0 1 4 13.5z"/>', '<path d="M4 6.5A2.5 2.5 0 0 1 6.5 4h11A2.5 2.5 0 0 1 20 6.5v7a2.5 2.5 0 0 1-2.5 2.5H10l-4.5 3.5V16A2.5 2.5 0 0 1 4 13.5z"/><path d="M8.5 10h.01M12 10h.01M15.5 10h.01" stroke-width="2.6"/>'],
    '🗨️': ['<path d="M4 6.5A2.5 2.5 0 0 1 6.5 4h11A2.5 2.5 0 0 1 20 6.5v7a2.5 2.5 0 0 1-2.5 2.5H10l-4.5 3.5V16A2.5 2.5 0 0 1 4 13.5z"/>', '<path d="M4 6.5A2.5 2.5 0 0 1 6.5 4h11A2.5 2.5 0 0 1 20 6.5v7a2.5 2.5 0 0 1-2.5 2.5H10l-4.5 3.5V16A2.5 2.5 0 0 1 4 13.5z"/><path d="M8 8.5h8M8 11.5h5"/>'],
    '🔔': ['<path d="M6 16v-5a6 6 0 0 1 12 0v5l1.5 2h-15z"/>', '<path d="M6 16v-5a6 6 0 0 1 12 0v5l1.5 2h-15z"/><path d="M10 20.5a2.2 2.2 0 0 0 4 0"/>'],
    '⚡': ['<path d="M13 2.5 5 13.5h6l-1 8 8-11h-6z"/>', '<path d="M13 2.5 5 13.5h6l-1 8 8-11h-6z"/>'],
    '📋': ['<rect x="5" y="4.5" width="14" height="16" rx="2"/>', '<rect x="5" y="4.5" width="14" height="16" rx="2"/><path d="M9 4.5v-.7a1.3 1.3 0 0 1 1.3-1.3h3.4A1.3 1.3 0 0 1 15 3.8v.7"/><path d="M8.5 10h7M8.5 13.5h7M8.5 17h4"/>'],
    '✅': ['<path d="M12 3 19 6v5.5c0 4.5-3 7.8-7 9.5-4-1.7-7-5-7-9.5V6z"/>', '<path d="M12 3 19 6v5.5c0 4.5-3 7.8-7 9.5-4-1.7-7-5-7-9.5V6z"/><path d="m8.8 12 2.3 2.3 4.2-4.6"/>'],
    '📜': ['<path d="M6 3.5h9l4 4v13H6z"/>', '<path d="M6 3.5h9l4 4v13H6z"/><path d="M15 3.5v4h4"/><path d="M9 11h7M9 14.5h7M9 18h4"/>'],
    '📄': ['<path d="M6 3.5h9l4 4v13H6z"/>', '<path d="M6 3.5h9l4 4v13H6z"/><path d="M15 3.5v4h4"/><path d="M9 12h6M9 15.5h6"/>'],
    '🖼️': ['<rect x="3.5" y="5" width="17" height="14" rx="2.5"/>', '<rect x="3.5" y="5" width="17" height="14" rx="2.5"/><circle cx="9" cy="10" r="1.7"/><path d="m4 17.5 5-5 4 4 2.5-2.5 5 4.5"/>'],
    '📷': ['<path d="M3.5 8.5a2 2 0 0 1 2-2h2.2l1.6-2h5.4l1.6 2h2.2a2 2 0 0 1 2 2V18a2 2 0 0 1-2 2h-13a2 2 0 0 1-2-2z"/>', '<path d="M3.5 8.5a2 2 0 0 1 2-2h2.2l1.6-2h5.4l1.6 2h2.2a2 2 0 0 1 2 2V18a2 2 0 0 1-2 2h-13a2 2 0 0 1-2-2z"/><circle cx="12" cy="13" r="3.6"/>'],
    '💳': ['<rect x="3" y="5.5" width="18" height="13" rx="2.5"/>', '<rect x="3" y="5.5" width="18" height="13" rx="2.5"/><path d="M3 10h18M7 15h3"/>'],
    '📊': ['<rect x="4" y="4" width="16" height="16" rx="2.5"/>', '<rect x="4" y="4" width="16" height="16" rx="2.5"/><path d="M8 16.5v-4M12 16.5V8M16 16.5v-6"/>'],
    '🎯': ['<circle cx="12" cy="12" r="8.5"/>', '<circle cx="12" cy="12" r="8.5"/><circle cx="12" cy="12" r="5"/><circle cx="12" cy="12" r="1.4"/>'],
    '🏪': ['<path d="M5 11h14v9H5z"/>', '<path d="M3.5 10 5 4.5h14l1.5 5.5"/><path d="M3.5 10a2.8 2.8 0 0 0 5.6 0 2.8 2.8 0 0 0 5.6 0 2.8 2.8 0 0 0 5.8 0"/><path d="M5 12.5V20h14v-7.5"/><path d="M10 20v-4.5h4V20"/>'],
    '➕': ['<circle cx="12" cy="12" r="8.5"/>', '<circle cx="12" cy="12" r="8.5"/><path d="M12 8v8M8 12h8"/>'],
    '🎧': ['<rect x="3.5" y="12" width="4" height="6.5" rx="1.5"/><rect x="16.5" y="12" width="4" height="6.5" rx="1.5"/>', '<rect x="3.5" y="12" width="4" height="6.5" rx="1.5"/><rect x="16.5" y="12" width="4" height="6.5" rx="1.5"/><path d="M4 13v-1a8 8 0 0 1 16 0v1"/><path d="M18.5 18.5v.5a2.5 2.5 0 0 1-2.5 2.5h-3"/>'],
    '⚙️': ['<circle cx="12" cy="12" r="6.2"/>', '<circle cx="12" cy="12" r="6.2"/><circle cx="12" cy="12" r="2.6"/><path d="M12 2.8v2.4M12 18.8v2.4M2.8 12h2.4M18.8 12h2.4M5.5 5.5l1.7 1.7M16.8 16.8l1.7 1.7M5.5 18.5l1.7-1.7M16.8 7.2l1.7-1.7"/>'],
    '📞': ['<path d="M6.6 3.5h2.6l1.4 4-1.9 1.3a10.5 10.5 0 0 0 6.5 6.5l1.3-1.9 4 1.4v2.6a2 2 0 0 1-2.2 2A16.5 16.5 0 0 1 4.6 5.7a2 2 0 0 1 2-2.2z"/>', '<path d="M6.6 3.5h2.6l1.4 4-1.9 1.3a10.5 10.5 0 0 0 6.5 6.5l1.3-1.9 4 1.4v2.6a2 2 0 0 1-2.2 2A16.5 16.5 0 0 1 4.6 5.7a2 2 0 0 1 2-2.2z"/>'],
    '🗓️': ['<rect x="3.5" y="5" width="17" height="15.5" rx="2.5"/>', '<rect x="3.5" y="5" width="17" height="15.5" rx="2.5"/><path d="M3.5 9.5h17M8 3v4M16 3v4"/><path d="M8 13.5h.01M12 13.5h.01M16 13.5h.01M8 17h.01M12 17h.01" stroke-width="2.6"/>'],
    '⏳': ['<path d="M7.5 4h9c0 4-4.5 5.5-4.5 8s4.5 4 4.5 8h-9c0-4 4.5-5.5 4.5-8S7.5 8 7.5 4z"/>', '<path d="M7.5 4h9c0 4-4.5 5.5-4.5 8s4.5 4 4.5 8h-9c0-4 4.5-5.5 4.5-8S7.5 8 7.5 4z"/><path d="M6 3.5h12M6 20.5h12"/>'],
    '☰': ['', '<path d="M4 7h16M4 12h16M4 17h16"/>']
  };
  function duoIcon(icon) {
    var d = DUO[icon];
    if (!d) return icon;
    return '<span class="kt-mnav-g">' + icon + '</span><svg class="kt-duo" viewBox="0 0 24 24" aria-hidden="true" focusable="false">'
      + (d[0] ? '<g class="f">' + d[0] + '</g>' : '') + '<g class="l">' + d[1] + '</g></svg>';
  }

  function ensure() {
    if (!tok() || document.getElementById('kt-mobilenav')) return;
    injectStyle();
    var scrim = document.createElement('div'); scrim.id = 'kt-mnav-scrim'; scrim.addEventListener('click', closeMenu); document.body.appendChild(scrim);
    duckChromeForDialogs();
    var nav = document.createElement('div'); nav.id = 'kt-mobilenav';
    var btn = function (icon, label, fn, badgeId, hash) {
      var b = document.createElement('button'); b.type = 'button'; if (hash) b.setAttribute('data-hash', hash);
      b.innerHTML = '<span class="ic" style="position:relative;">' + duoIcon(icon) + (badgeId ? '<span class="badge" id="' + badgeId + '" hidden></span>' : '') + '</span>' + label;
      b.addEventListener('click', fn); return b;
    };
    var parent = isParentView();
    document.body.classList.toggle('kt-parentview', parent);
    var pending = parent && (function () {
      /* Not linked to a family yet (/auth/me family_pending, 2026-10-21): only what works
         without one. The shell shows the waiting screen for everything else. */
      try {
        if (sessionStorage.getItem('kt_view_as')) return false;
        return !!JSON.parse(sessionStorage.getItem('kt_user') || localStorage.getItem('kt_user') || '{}').family_pending;
      } catch (e) { return false; }
    })();
    if (pending) {
      nav.appendChild(btn('⏳', 'Status', function () { go('#pending'); }, null, 'pending'));
      nav.appendChild(btn('✍️', 'Forms', function () { go('#my-forms'); }, null, 'my-forms'));
      nav.appendChild(btn('📄', 'Documents', function () { go('#my-documents'); }, null, 'my-documents'));
      nav.appendChild(btn('🔔', 'Inbox', function () { go('#notifications'); }, 'kt-b-inbox', 'notifications'));
    } else if (parent) {
      // Parents get a full, self-contained bottom bar — every section one tap away.
      // Home returns to the icon-tile launcher (#home), the parent's main screen.
      nav.appendChild(btn('🏠', 'Home', function () { go('#home'); }, null, 'home'));
      nav.appendChild(btn('🖼️', 'Photos', function () { go('#photos'); }, 'kt-b-photos', 'photos'));
      // Center, raised camera button — QR check-in / out. The "camera button".
      var scanBtn = btn('📷', 'One-Tap', function () { go('#scan'); }, null, 'scan');
      scanBtn.classList.add('scan');
      nav.appendChild(scanBtn);
      nav.appendChild(btn('💬', 'Message Centre', function () { go('#messages'); }, 'kt-mnav-msg', 'messages'));
      nav.appendChild(btn('💳', 'Billing', function () { go('#billing'); }, 'kt-b-billing', 'billing'));
    } else if (activeRole() === 'sales_rep') {
      /* A SALES REP'S OWN BAR (2026-11-06, Anthony: "make sure all other roles ... are consistent ... full sweep on
         mobile"). They fell through to the centre-staff bar, whose Daily log (#care-log) and Message Centre (#chat)
         have no sales_rep screen: two of five tabs opened "Screen not available". Now the sales sidebar's own
         first stops, in its order. */
      nav.appendChild(btn('🏠', 'Home', function () { go('#home'); }, null, 'home'));
      nav.appendChild(btn('📊', 'Pipeline', function () { go('#sales'); }, null, 'sales'));
      nav.appendChild(btn('🎯', 'Leads', function () { go('#sales-leads'); }, null, 'sales-leads'));
      nav.appendChild(btn('🗨️', 'Website chat', function () { go('#sales-webchat'); }, 'kt-mnav-msg', 'sales-webchat'));
      nav.appendChild(btn('🔔', 'Inbox', function () { go('#notifications'); }, 'kt-b-inbox', 'notifications'));
    } else if (activeRole() === 'reseller_admin') {
      // Same fall-through for a reseller: no daily log, no Message Centre. Their sidebar's sections instead.
      nav.appendChild(btn('🏪', 'Agencies', function () { go('#reseller'); }, null, 'reseller'));
      nav.appendChild(btn('➕', 'Onboard', function () { go('#reseller-onboard'); }, null, 'reseller-onboard'));
      nav.appendChild(btn('🎧', 'Support', function () { go('#reseller-support'); }, null, 'reseller-support'));
      nav.appendChild(btn('⚙️', 'Settings', function () { go('#settings'); }, null, 'settings'));
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
        /* THE SUPERADMIN GETS THE PHONE HERE (2026-10-23, Anthony: "super admin view under mobile
           needs to have the dialer phone on the bottom bar and remove daily"). Their own view
           only — viewing as someone else keeps that role's bar. The badge is the missed-call /
           voicemail / texts count kt-dialer.js keeps. */
        var _super = (function () {
          try {
            if (sessionStorage.getItem('kt_view_as')) return false;
            var u = JSON.parse(sessionStorage.getItem('kt_user') || localStorage.getItem('kt_user') || '{}');
            return (u.roles || []).indexOf('platform_admin') !== -1;
          } catch (e) { return false; }
        })();
        if (_super) {
          var phoneBtn = btn('📞', 'Phone', function () { if (window.KT && KT.dialer && KT.dialer.open) KT.dialer.open(); else go('#phone'); }, 'kt-b-phone', 'phone');
          // The dial pad (kt-dialer: html.kt-dpop-full) and its Texts sheet (html.kt-txfull-open) are panels, not a hash.
          phoneBtn.__ktOpen = function () { var c = document.documentElement.classList; return c.contains('kt-dpop-full') || c.contains('kt-txfull-open'); };
          nav.appendChild(phoneBtn);
          setTimeout(function () { try { if (window.KT && KT.dialer && KT.dialer.paint) KT.dialer.paint(); } catch (e) {} }, 0);
        } else {
          nav.appendChild(btn('🗓️', 'Daily', function () { go('#provider-day'); }, null, 'provider-day'));
        }
      } else {
        /* Daily log is the educator's slot. A home visitor and an auditor have no #care-log screen, so this tab
           opened "Screen not available" for them (2026-11-06 role sweep). They get their own first tool instead:
           the visit report for a home visitor, Compliance for an auditor. */
        var _ar = activeRole();
        if (_ar === 'home_visitor') {
          /* Five tabs like every other phone bar (2026-11-06, home visitor consistency): My reports, then New visit as
             the raised centre button - the slot the educator's One-Tap and the parent's camera use. */
          nav.appendChild(btn('📋', 'My reports', function () { go('#home-visits'); }, null, 'home-visits'));
          var nvBtn = btn('📝', 'New visit', function () { go('#new-home-visit'); }, null, 'new-home-visit');
          nvBtn.classList.add('scan');
          nav.appendChild(nvBtn);
        } else if (_ar === 'auditor') {
          nav.appendChild(btn('✅', 'Compliance', function () { go('#compliance'); }, null, 'compliance'));
        } else {
          nav.appendChild(btn('📝', 'Daily log', function () { go('#care-log'); }, null, 'care-log'));
        }
      }

      /* Third of five, so it lands in the middle — the raised slot the parent bar
         uses for its camera. Order: Home · Children · Add · Message Centre · Inbox. */
      if (_adminRole) {
        var qaBtn = btn('\u26A1', 'Add', function () { openAdminQuickAdd(qaBtn); }, null, '__qadd');
        qaBtn.classList.add('scan');   // the raised centre treatment the parent bar uses
        qaBtn.__ktOpen = function () { return !!(document.getElementById('kt-qa-pop') || document.getElementById('kt-qa-sheet')); };
        nav.appendChild(qaBtn);
      }

      if (isEducatorView()) {
        // The educator's check-in QR, as a raised centre button — the same
        // affordance parents get for scanning. A 🔳 glyph floating in the corner
        // was too easy to miss for something parents queue up to scan.
        var qrBtn = btn(ONETAP_MARK, 'One-Tap', function () { oneTapMenu(qrBtn); }, null, 'eduqr');
        qrBtn.classList.add('scan');
        qrBtn.__ktOpen = function () { return !!(document.getElementById('kt-otm') || document.getElementById('kt-qr-overlay')); };
        nav.appendChild(qrBtn);
      }
      // An auditor has no Message Centre (no auditor:chat screen); Audit logs is their next stop.
      if (activeRole() === 'auditor') {
        nav.appendChild(btn('📜', 'Audit logs', function () { go('#audit-logs'); }, null, 'audit-logs'));
      } else {
        nav.appendChild(btn('💬', 'Message Centre', function () { go('#chat'); }, 'kt-mnav-msg', 'chat'));
      }
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
        menuBtn.__ktOpen = function () { return document.body.classList.contains('kt-mnav-open'); };
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
    /* Every role (2026-11-06, Anthony: home visitor's phone header "all messed up"): a home visitor, sales rep, auditor
       or reseller had no gear, so the parent-chrome header had no gear slot (no body.kt-pc-gear) and wrapped onto
       two rows. screen-settings.js registers :settings for all of them. */
    var showGear = parent || isEducatorView() || _adminRole
      || ['home_visitor', 'sales_rep', 'auditor', 'reseller_admin'].indexOf(activeRole()) !== -1;
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
    /* ONE ACTIVE-STATE RULE (2026-11-24, Anthony: "under super admin in mobile the phone icon when you click it doesn't
       highlight like it does with the other icons on the bottom bar - full sweep"). Phone opens the dial pad, a PANEL -
       the hash never changes - so a hash-only match could never light it (Home stayed lit under the pad). The same
       was true of ⚡ Add, the educator's One-Tap and ☰ Menu (popovers/drawer). Now:
         • a button that opens a panel says so (b.__ktOpen); while its panel is up it alone is lit;
         • otherwise the button whose hash is the current screen, read the way the shell's navKey reads it
           (no ?query, &…, or /sub-screen), so #chat?id=4 or #settings/about still light their tab.
       Re-run on hashchange and whenever a panel opens or closes (html/body class or a body child changing). */
    var updateActive = function () {
      var bs = [].slice.call(nav.querySelectorAll('button'));
      var panel = null;
      bs.forEach(function (b) { try { if (!panel && b.__ktOpen && b.__ktOpen()) panel = b; } catch (e) {} });
      var h = (location.hash || '#dashboard').replace('#', '').split('?')[0].split('&')[0].split('/')[0];
      // For parents the launcher (#home) is the base; Today/dashboard are drilled in from it.
      if (nav.querySelector('button[data-hash="home"]') && (h === 'today' || h === 'dashboard')) h = 'home';
      bs.forEach(function (b) {
        var on = panel ? b === panel : b.getAttribute('data-hash') === h;
        if (b.classList.contains('on') !== on) b.classList.toggle('on', on);
        if (b.__ktOpen) b.setAttribute('aria-expanded', b === panel ? 'true' : 'false');
        if (on && !b.__ktOpen) b.setAttribute('aria-current', 'page'); else b.removeAttribute('aria-current');
      });
    };
    updateActive();
    window.addEventListener('hashchange', updateActive);
    var _uaQ = false;
    var _uaSoon = function () { if (_uaQ) return; _uaQ = true; requestAnimationFrame(function () { _uaQ = false; if (nav.isConnected) updateActive(); else { try { _uaObs.disconnect(); } catch (e) {} } }); };
    try {
      var _uaObs = new MutationObserver(_uaSoon);
      _uaObs.observe(document.documentElement, { attributes: true, attributeFilter: ['class'] });
      _uaObs.observe(document.body, { attributes: true, attributeFilter: ['class'], childList: true });
    } catch (e) {}
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
    /* ONE PROBE, KEPT (2026-10-28). It used to be created, read and removed on every call -
       every 700 ms - and each insert/remove threw away the page's style and layout, so the
       bar's next measurement re-laid out the whole screen (seconds of frozen taps on a long
       list at phone speed). A permanent, invisible probe reads the same env() for nothing. */
    try {
      if (!_insetProbe || !_insetProbe.isConnected) {
        _insetProbe = document.createElement('div');
        _insetProbe.setAttribute('aria-hidden', 'true');
        _insetProbe.style.cssText = 'position:fixed;top:0;left:0;width:0;height:0;visibility:hidden;pointer-events:none;'
          + 'padding-bottom:env(safe-area-inset-bottom, 0px);';
        (document.body || document.documentElement).appendChild(_insetProbe);
      }
      return parseFloat(getComputedStyle(_insetProbe).paddingBottom) || 0;
    } catch (e) { return 0; }
  }
  var _insetProbe = null;
  function setVar(name, val) {
    var st = document.documentElement.style;
    if (st.getPropertyValue(name) !== val) st.setProperty(name, val);
  }

  /* KEYBOARD UP = NO BAR (2026-10-20, Closed Testing Report issue 3).

     Pinning to the visible bottom meant that with the keyboard open the bar sat on top of
     it, mid-screen, over half of the New award form. While a text field has focus AND the
     visible height is well below the tallest seen without typing (the keyboard; adjustResize
     in the APK shrinks innerHeight too, so innerHeight alone cannot tell), html gets
     .kt-kb-open and kt-mobile-app.css hides the bar and the back button. */
  var kbBase = 0, kbOrient = null;
  function typingTarget() {
    var ae = document.activeElement;
    if (!ae) return false;
    if (ae.isContentEditable || ae.tagName === 'TEXTAREA') return true;
    return ae.tagName === 'INPUT'
      && !/^(checkbox|radio|button|submit|reset|file|range|color|image|hidden)$/i.test(ae.type || '');
  }
  function trackKeyboard(vv) {
    var h = vv ? vv.height : window.innerHeight;
    var o = (screen.orientation && screen.orientation.type) || (window.innerWidth > window.innerHeight ? 'l' : 'p');
    if (o !== kbOrient) { kbOrient = o; kbBase = 0; }
    var typing = typingTarget();
    if (!typing && h > kbBase) kbBase = h;
    if (!kbBase) kbBase = Math.max(h, window.innerHeight);
    var open = typing && (kbBase - h) > 120;
    document.documentElement.classList.toggle('kt-kb-open', open);
  }

  /* THE IPHONE HOME-SCREEN GAP (2026-10-27), MEASURED. A real iPhone (390x844, opened from
     the Home Screen) reported through the IOSLAYOUT beacon: inner 797, 100lvh 844, insets
     47/34 - the page is given the screen MINUS the status bar, so everything fixed to the
     bottom (this bar, the clocked-in glow) stopped 47px above the real edge with white under
     it (Anthony's screenshot, 2026-10-27). The bar's own padding was right (34 for the home
     indicator, lift 0), so this is not the double count of 2026-09-21. 100lvh still measures
     the whole screen, so the gap is lvh - innerHeight, and the bar is moved down by it.
     Home Screen app only: in a Safari tab that difference is Safari's own toolbar, which
     the bar must stay above. Never while the keyboard is up. */
  var _gapProbe = null;
  function standaloneGap() {
    try {
      if (!isIOS() || !(navigator.standalone || (window.matchMedia && matchMedia('(display-mode: standalone)').matches))) return 0;
      if (!_gapProbe) {
        _gapProbe = document.createElement('div');
        _gapProbe.style.cssText = 'position:fixed;left:0;top:0;width:1px;height:100lvh;visibility:hidden;pointer-events:none;';
        document.body.appendChild(_gapProbe);
      }
      var gap = Math.round(_gapProbe.getBoundingClientRect().height - window.innerHeight);
      return gap > 0 && gap <= 80 ? gap : 0;   // a status bar's worth at most; anything bigger is not this
    } catch (e) { return 0; }
  }

  function pinToVisualViewport() {
    var nav = document.getElementById('kt-mobilenav'); if (!nav) return;
    var vv = window.visualViewport;
    try { trackKeyboard(vv); } catch (e) {}
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

      var _gap = offset === 0 ? standaloneGap() : 0;
      var _bot = (_gap ? -_gap : Math.round(offset)) + 'px';
      if (nav.style.bottom !== _bot) nav.style.bottom = _bot;
      try {
        // The clocked-in glow is drawn to the same short viewport: extend it to the edge too.
        var _glow = document.getElementById('kt-clockglow');
        if (_glow) { _glow.style.bottom = _gap ? (-_gap) + 'px' : ''; }
        setVar('--kt-ios-gap', _gap + 'px');
      } catch (e) {}

      /* THE NUMBERS, WHERE SOMEBODY CAN READ THEM.

         Every previous round of this was argued from screenshots, which cannot tell a
         bar lifted by 34px from one padded by 34px - they look identical. Same reasoning
         as data-kt-insets on <html>: one attribute turns "still a gap" into a reading.
         Visible in a crash report and in the DOM. */
      try {
        var _pin = 'vv ' + Math.round(vv.height) + '+' + Math.round(vv.offsetTop)
          + ' vs inner ' + window.innerHeight
          + ' | inset ' + _inset + (isIOS() ? ' (ios, subtracted)' : ' (kept)')
          + ' -> bottom ' + Math.round(offset) + (_gap ? ' | home-screen gap ' + _gap + ' (moved down)' : '');
        if (nav.getAttribute('data-kt-pin') !== _pin) nav.setAttribute('data-kt-pin', _pin);
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
      // How much of the VISIBLE screen the bar covers (not its height: on an iPhone Home Screen
      // app the bar is moved below the page's own bottom - see standaloneGap).
      try { var _vvb = window.visualViewport ? window.visualViewport.offsetTop + window.visualViewport.height : window.innerHeight;
        setVar('--kt-nav-h', Math.max(0, Math.round(_vvb - nav.getBoundingClientRect().top)) + 'px'); } catch (e) {}
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
  // Focus changes before the keyboard finishes animating; look again once it has.
  document.addEventListener('focusin', function () { setTimeout(pinToVisualViewport, 350); }, true);
  document.addEventListener('focusout', function () { setTimeout(pinToVisualViewport, 120); }, true);
  /* ONLY WHEN SOMETHING CHANGED (2026-10-28, Anthony: "the bottom bar with the buttons freezes
     and I cannot click on anything"). This ran the full measure every 700 ms whatever happened;
     each pass reads layout (the bar, the name row), and on a screen still settling - a long list
     being turned into cards - that meant laying out the whole list again, over and over. Now the
     tick compares a few numbers that cost nothing to read (window and visible-area size, the
     keyboard, the screen, the back button) and measures only when one moved, plus every 4 s as
     a backstop. Resize / scroll / focus listeners above still measure at once. */
  var _tickSig = '', _tickAt = 0;
  setInterval(function () {
    var vv = window.visualViewport, now = Date.now();
    var sig = window.innerWidth + 'x' + window.innerHeight
      + (vv ? '|' + Math.round(vv.width) + 'x' + Math.round(vv.height) + '+' + Math.round(vv.offsetTop) : '')
      + '|' + (typingTarget() ? 1 : 0) + '|' + location.hash
      + '|' + (document.getElementById('kt-role-back') ? 1 : 0) + (document.getElementById('kt-gear') ? 1 : 0)
      + (document.getElementById('kt-mobilenav') ? 1 : 0) + (dialFabOn() ? 1 : 0);
    if (sig === _tickSig && now - _tickAt < 4000) return;
    _tickSig = sig; _tickAt = now;
    pinToVisualViewport(); padGearClearance(); placeGear();
  }, 700);   // catch SPA navigations / URL-bar changes

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
    /* One answer for every badge (kt-badges.js, 2026-10-30). The Inbox counted the unread among the newest 200
       rows (17) while the bell counted them all (38), and opening the inbox did not reset either. */
    if (window.KT && KT.badges) {
      KT.badges.get().then(function (b) {
        if (!b) return;
        var sec = curSection();
        _setBadge('kt-mnav-msg', sec === 'messages' || sec === 'chat' ? 0 : (isParentView() ? (b.parent_messages || 0) : (KT.badges.msgs ? KT.badges.msgs(b) : (b.chats || 0))));
        var cat = b.cat || {};
        _setBadge('kt-b-photos', sec === 'photos' ? 0 : (cat.photos || 0));
        _setBadge('kt-b-billing', sec === 'billing' ? 0 : (cat.billing || 0));
        _setBadge('kt-b-inbox', sec === 'notifications' ? 0 : (b.notifications || 0));
      });
      return;
    }
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
  window.addEventListener('kt:badges', refreshBadges);   // real time: any change repaints at once
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

  /* THE IPHONE BOTTOM, MEASURED (2026-10-27). Anthony's screenshot: white space under the
     bottom bar on an iPhone, and the clocked-in outline (position:fixed; inset:0) ends ~30px
     above the screen's edge too - so the layout viewport itself stops short, not just the
     bar. Two earlier rounds of this were fixed from screenshots (memory: ios-inset-double-
     count, ios-env-inset-is-zero). This sends the real numbers ONCE per session from an
     iPhone, to /diag/scroll (prefix IOSLAYOUT), so the next fix is made from a reading. */
  (function () {
    var ua = navigator.userAgent || '';
    if (!/iPhone|iPad/.test(ua)) return;
    try { if (sessionStorage.getItem('kt_ioslayout_sent')) return; } catch (e) {}
    setTimeout(function () {
      try {
        var vv = window.visualViewport, de = document.documentElement;
        var nav = document.getElementById('kt-mobilenav');
        var nr = nav ? nav.getBoundingClientRect() : null;
        var probe = document.createElement('div');
        probe.style.cssText = 'position:fixed;left:0;right:0;bottom:0;height:1px;pointer-events:none;visibility:hidden;';
        document.body.appendChild(probe);
        var fixedBottom = Math.round(probe.getBoundingClientRect().bottom);
        probe.style.cssText = 'position:fixed;left:0;top:0;height:100vh;width:1px;visibility:hidden;';
        var vh100 = Math.round(probe.getBoundingClientRect().height);
        probe.style.cssText = 'position:fixed;left:0;top:0;height:100dvh;width:1px;visibility:hidden;';
        var dvh = Math.round(probe.getBoundingClientRect().height);
        probe.style.cssText = 'position:fixed;left:0;top:0;height:100lvh;width:1px;visibility:hidden;';
        var lvh = Math.round(probe.getBoundingClientRect().height);
        probe.remove();
        var data = 'IOSLAYOUT standalone=' + (!!navigator.standalone || (window.matchMedia && matchMedia('(display-mode: standalone)').matches))
          + ' screen=' + screen.width + 'x' + screen.height + ' dpr=' + (window.devicePixelRatio || 1)
          + ' inner=' + innerWidth + 'x' + innerHeight + ' outerH=' + outerHeight + ' clientH=' + de.clientHeight
          + ' vv=' + (vv ? Math.round(vv.height) + '+' + Math.round(vv.offsetTop) : '-')
          + ' fixedBottom=' + fixedBottom + ' 100vh=' + vh100 + ' dvh=' + dvh + ' lvh=' + lvh
          + ' nav=' + (nr ? Math.round(nr.top) + '..' + Math.round(nr.bottom) + ' pad' + getComputedStyle(nav).paddingBottom + ' style.bottom=' + nav.style.bottom : '-')
          + ' insets=' + (de.getAttribute('data-kt-insets') || '-') + ' pin=' + (nav ? nav.getAttribute('data-kt-pin') : '-')
          + ' safeB=' + getComputedStyle(de).getPropertyValue('--kt-safe-bottom') + ' hash=' + (location.hash || '').split('?')[0];
        var base = (window.KT_CONFIG && window.KT_CONFIG.apiBase) || 'https://api.kiddietrac.com/api/v1';
        fetch(base + '/diag/scroll', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ data: data }) }).catch(function () {});
        try { sessionStorage.setItem('kt_ioslayout_sent', '1'); } catch (e) {}
      } catch (e) {}
    }, 4000);
  })();
})();

/* "WHEN THE PORTAL REFRESHES I LOSE ACCESS TO THE SALES AND PLATFORM VIEWS" (Anthony,
   2026-10-27). A refresh re-applies the last role picked in View as (kt_view_as_pref, by
   design since 2026-09-17), and a clean test keeps both sections - so what decides it lives on
   the device. Once per session, for an account that HOLDS platform_admin, report what this
   load ended up showing: real roles, the view-as in force and the remembered one, the agency,
   and whether Platform / Sales actually appeared. Read with: grep ROLEVIEW scroll-diag.log. */
(function () {
  try { if (sessionStorage.getItem('kt_roleview_sent')) return; } catch (e) { return; }
  setTimeout(function () {
    try {
      var u = JSON.parse(sessionStorage.getItem('kt_user') || localStorage.getItem('kt_user') || '{}');
      var roles = (u && u.roles) || [];
      if (roles.indexOf('platform_admin') < 0) return;
      var txt = '';
      ['appSidebar', 'appMain'].forEach(function (id) { var el = document.getElementById(id); if (el) txt += ' ' + (el.innerText || ''); });
      var pref = null; try { pref = JSON.parse(localStorage.getItem('kt_view_as_pref') || 'null'); } catch (e) {}
      var C = window.Capacitor, app = !!(C && (C.isNativePlatform ? C.isNativePlatform() : C.isNative));
      var data = 'ROLEVIEW uid=' + u.id + ' roles=' + roles.join(',') + ' viewAs=' + (sessionStorage.getItem('kt_view_as') || '-')
        + ' pref=' + (pref ? (pref.role + (String(pref.uid) === String(u.id) ? '' : '(other uid)')) : '-')
        + ' agency=' + (sessionStorage.getItem('kt_active_agency_id') || '-') + ' last_agency=' + (u.last_agency_id || '-')
        + ' platformShown=' + /PLATFORM/i.test(txt) + ' salesShown=' + /\bSALES\b|Pipeline/.test(txt)
        + ' app=' + app + ' w=' + innerWidth + ' hash=' + String(location.hash || '').split('?')[0]
        + ' tokenIn=' + (sessionStorage.getItem('kt_token') ? 'session' : (localStorage.getItem('kt_token') ? 'local' : 'none'));
      var base = (window.KT_CONFIG && window.KT_CONFIG.apiBase) || 'https://api.kiddietrac.com/api/v1';
      fetch(base + '/diag/scroll', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ data: data }) }).catch(function () {});
      sessionStorage.setItem('kt_roleview_sent', '1');
    } catch (e) {}
  }, 7000);
})();
