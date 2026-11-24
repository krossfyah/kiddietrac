/* ═══════════════════════════════════════════════════════════════════
   KiddieTrac — clock-in status glow (2026-07-27)
   A soft pulsing border around the whole app screen that tells an educator
   their clock status at a glance, from any screen:
     · ORANGE glow  = NOT clocked in  (a nudge to clock in)
     · GREEN  glow  = clocked in
     · YELLOW glow  = on lunch or a break (2026-11-06, Anthony: "when educator clocks out as on lunch or break use a
                      yellow glow around the edges of the screen instead") - it was orange, as if they had left
   Reads the shared clock state from KT.clockBar.isClockedIn() (kt-clockbar.js),
   so it flips the moment they clock in/out. Gated to the educator view only —
   parents/admins never see it. Pure overlay: pointer-events:none, so it never
   blocks a tap.
   ═══════════════════════════════════════════════════════════════════ */
(function (window) {
  'use strict';
  if (window.__ktClockGlow) return; window.__ktClockGlow = true;
  var doc = window.document;
  var KT = window.KT || (window.KT = {});

  // Active view is 'educator' (mirrors the shell's body.role-* class, which is set
  // from primaryRoleOf). We only glow for educators, per the clock-in workflow.
  function isEducatorView() {
    try {
      if (doc.body && doc.body.classList.contains('role-educator')) return true;
      var va = ''; try { va = sessionStorage.getItem('kt_view_as') || ''; } catch (e) {}
      if (va) return va === 'educator';
      var u = JSON.parse(sessionStorage.getItem('kt_user') || localStorage.getItem('kt_user') || '{}');
      var r = u.roles || [];
      // educator, and not out-ranked by an admin/director role (matches the shell)
      return r.indexOf('educator') > -1 &&
        ['agency_admin', 'platform_admin', 'centre_director'].every(function (x) { return r.indexOf(x) === -1; });
    } catch (e) { return false; }
  }

  function ensure() {
    if (doc.getElementById('kt-clockglow')) return doc.getElementById('kt-clockglow');
    var st = doc.createElement('style'); st.id = 'kt-clockglow-style';
    st.textContent =
      '#kt-clockglow{position:fixed;left:0;right:0;top:var(--kt-safe-top, env(safe-area-inset-top, 0px));bottom:var(--kt-safe-bottom, env(safe-area-inset-bottom, 0px));z-index:2147480500;pointer-events:none;display:none;border-radius:2px;}' +
      '#kt-clockglow.kt-cg-on{display:block;}' +
      /* iPhone app (2026-10-30, Anthony's recording): no system bar below the app there - the home indicator floats
         over it - so stopping the frame 34pt up drew a green line across the bottom nav, which read as "the bar does
         not reach the bottom". The frame now follows the screen's own rounded corners. */
      /* THE SCREEN'S OWN CORNERS (2026-10-31, Anthony: "can the outer glow ... conform to the screen with the circular
         edges"). When the app owns the whole screen (the iPhone/Android apps, a Home Screen install, the website demo
         phones) the frame runs edge to edge - over the status bar and the home indicator too - and rounds with the
         display (--kt-screen-radius, worked out below). An inset box-shadow follows border-radius, so the glow curves
         into each corner instead of drawing straight bars that the bezel cuts off. A browser tab is not the screen, so
         it keeps the plain frame. */
      /* Above the painted status-bar band (#kt-statusfill / #kt-demo-statusfill, z 2147483600), or the top corners lose
         their curve under it; still under the forced-update and emergency takeovers. */
      'html.kt-cg-edge #kt-clockglow{top:0;bottom:0;border-radius:var(--kt-screen-radius,0px);z-index:2147483601;}' +
      /* FULLY ON THE GLASS (2026-10-31, Anthony's TestFlight recording on an iPhone XR: "the outer line with the green
         for clocked in educators is not fully outlined correctly"). Two parts of the line were drawn where the phone
         cannot show them. (1) The corners: a phone's display corner is a continuous curve, not the circular arc CSS
         draws, so a 3px line on the very edge was shaved off where each curve begins. 3px in from the edge keeps the
         whole line inside the glass. (2) A NOTCH (iPhone X - 14, not the Dynamic Island, which floats clear of the
         edge) covers the middle third of the top edge, so the top line was half missing. There the frame's top runs
         just under the status bar instead, square-cornered because the screen's sides are straight at that height. */
      /* iPhone only (2026-11-04): on Android the 3px pull-in left a strip of page between the line and every edge
         (Anthony's Galaxy S25 Ultra) - there the line sits on the edges, round with the corner Android reports. */
      'html.kt-cg-edge.kt-cg-ios:not(.kt-demo-frame) #kt-clockglow{top:3px;left:3px;right:3px;bottom:3px;}' +
      'html.kt-cg-edge.kt-cg-notch #kt-clockglow{top:var(--kt-safe-top, env(safe-area-inset-top, 0px));border-top-left-radius:0;border-top-right-radius:0;}' +
      // ORANGE — not clocked in (a little more insistent).
      '@keyframes kt-cg-out{0%,100%{box-shadow:inset 0 0 0 3px rgba(249,115,22,.95),inset 0 0 15px 2px rgba(249,115,22,.55);}' +
      '50%{box-shadow:inset 0 0 0 4px rgba(249,115,22,1),inset 0 0 34px 7px rgba(249,115,22,.75);}}' +
      // GREEN — clocked in (calmer, slower).
      '@keyframes kt-cg-in{0%,100%{box-shadow:inset 0 0 0 3px rgba(34,197,94,.9),inset 0 0 13px 1px rgba(34,197,94,.4);}' +
      '50%{box-shadow:inset 0 0 0 3px rgba(34,197,94,1),inset 0 0 28px 4px rgba(34,197,94,.6);}}' +
      // YELLOW — on lunch or a break (still on today's shift, just off the floor).
      '@keyframes kt-cg-brk{0%,100%{box-shadow:inset 0 0 0 3px rgba(250,204,21,.95),inset 0 0 15px 2px rgba(250,204,21,.5);}' +
      '50%{box-shadow:inset 0 0 0 4px rgba(250,204,21,1),inset 0 0 32px 6px rgba(250,204,21,.7);}}' +
      '#kt-clockglow.kt-cg-out{animation:kt-cg-out 2.3s ease-in-out infinite;}' +
      '#kt-clockglow.kt-cg-in{animation:kt-cg-in 3.4s ease-in-out infinite;}' +
      '#kt-clockglow.kt-cg-brk{animation:kt-cg-brk 3s ease-in-out infinite;}' +
      '@media(prefers-reduced-motion:reduce){#kt-clockglow.kt-cg-out,#kt-clockglow.kt-cg-in,#kt-clockglow.kt-cg-brk{animation:none;}}';
    (doc.head || doc.documentElement).appendChild(st);
    var el = doc.createElement('div');
    el.id = 'kt-clockglow'; el.setAttribute('aria-hidden', 'true');
    doc.body.appendChild(el);
    return el;
  }

  /* Display corner radius in CSS px. There is no web API for it, so: the demo phone says what it draws; an iPhone is
     known by its screen size (points) and pixel ratio; an Android phone gets a measured-typical curve. 0 = square. */
  var IPHONE = {
    '375x812': function (dpr) { return 41; },          // X / XS / 11 Pro 39, 12-13 mini 44
    '414x896': function (dpr) { return dpr >= 3 ? 39 : 41.5; },   // XS Max / 11 Pro Max 39, XR / 11 41.5
    '390x844': function () { return 47.3; },           // 12, 13, 14, 12-13 Pro
    '428x926': function () { return 53.3; },           // 12-13 Pro Max, 14 Plus
    '393x852': function () { return 55; },             // 14 Pro, 15, 15 Pro, 16
    '430x932': function () { return 55; },             // 14 Pro Max, 15 Plus / Pro Max, 16 Plus
    '402x874': function () { return 62; },             // 16 Pro
    '440x956': function () { return 62; }              // 16 Pro Max
  };
  function isIOS() {
    var ua = navigator.userAgent || '';
    return /iPad|iPhone|iPod/.test(ua) || (/Macintosh/.test(ua) && navigator.maxTouchPoints > 1);
  }
  function envInset(side) {
    var p = doc.createElement('div'), v = 0;
    p.style.cssText = 'position:fixed;top:0;left:0;width:0;height:0;visibility:hidden;padding-top:env(safe-area-inset-' + side + ',0px)';
    doc.documentElement.appendChild(p);
    try { v = parseFloat(window.getComputedStyle(p).paddingTop) || 0; } catch (e) {}
    p.remove();
    return v;
  }
  function ownsScreen() {
    var root = doc.documentElement;
    if (root.classList.contains('kt-demo-frame')) return !!root.style.getPropertyValue('--kt-screen-radius');
    var native = false, standalone = false;
    try { native = !!(window.Capacitor && window.Capacitor.isNativePlatform && window.Capacitor.isNativePlatform()); } catch (e) {}
    try { standalone = window.matchMedia('(display-mode: standalone)').matches || window.navigator.standalone === true; } catch (e) {}
    if (!native && !standalone) return false;                      // a browser tab is not the screen
    if (root.classList.contains('kt-ios-app')) return true;          // the iPhone app draws under both system bars
    // Edge to edge = the page reaches the screen's own top and bottom (Android 15 apps, Home Screen installs).
    var sh = window.screen.height || 0;
    return envInset('top') > 0 || (window.innerHeight || 0) >= (sh || 0) - 4;
  }
  function screenRadius() {
    var root = doc.documentElement;
    var given = parseFloat(root.style.getPropertyValue('--kt-screen-radius'));
    if (root.classList.contains('kt-demo-frame')) return isNaN(given) ? 0 : given;
    var w = window.screen.width || 0, h = window.screen.height || 0;
    if (w > h) { var t = w; w = h; h = t; }
    if (isIOS()) {
      var f = IPHONE[w + 'x' + h];
      return f ? f(window.devicePixelRatio || 1) : (h >= 812 ? 44 : 0);
    }
    /* ANDROID TELLS US (2026-11-04). The app (MainActivity.reportCorners, 3.12.18+) reads the display's own corner
       radius from Android (WindowInsets.getRoundedCorner) and leaves it in localStorage kt_corner_radius, in CSS px.
       The old guess - 9.2% of the short side, 38px on a 411px Galaxy S25 Ultra - is far rounder than that phone's
       tight corners, so the line curved in well short of each corner. The guess stays only for older app builds. */
    var nat = nativeRadius();
    if (nat !== null) return nat;
    // Android without a reported radius: phone corner curves run ~8.5-10% of the short side (Pixel 8 412pt ~ 38pt).
    return w >= 600 ? 16 : Math.round(Math.min(Math.max(w * 0.092, 24), 44));
  }
  function nativeRadius() {
    var v = null;
    try { v = localStorage.getItem('kt_corner_radius'); } catch (e) {}
    var n = parseFloat(v);
    return (v !== null && !isNaN(n) && n >= 0 && n < 120) ? n : null;
  }
  /* A notched iPhone in portrait: its status bar is 44-50pt (X 44, 12-14 47, XR/11 48, 12-13 mini 50); a Dynamic
     Island phone's is 54pt or more (59/62). The measured inset from kt-ios-safearea.js first, env() as the fallback. */
  function hasNotch() {
    if (doc.documentElement.classList.contains('kt-demo-frame') || !isIOS()) return false;
    if ((window.innerWidth || 0) > (window.innerHeight || 0)) return false;
    var top = parseFloat(window.getComputedStyle(doc.documentElement).getPropertyValue('--kt-safe-top'));
    if (!(top > 0)) top = envInset('top');
    return top >= 40 && top < 52;
  }
  var shaped = '';
  function shape() {
    try {
      var root = doc.documentElement, edge = ownsScreen();
      var r = edge ? screenRadius() : 0;
      var notch = edge && hasNotch();
      var key = edge + ':' + r + ':' + notch;
      if (key === shaped) return;
      shaped = key;
      root.classList.toggle('kt-cg-edge', edge);
      root.classList.toggle('kt-cg-ios', isIOS());
      root.classList.toggle('kt-cg-notch', notch);
      if (edge && !root.classList.contains('kt-demo-frame')) root.style.setProperty('--kt-screen-radius', r + 'px');
    } catch (e) {}
  }
  window.addEventListener('resize', function () { shaped = ''; shape(); });
  // The Android app hands over the real corner radius after the page loads.
  window.addEventListener('kt:corners', function () { shaped = ''; shape(); });

  function bootCovered() {
    var h = doc.documentElement;
    if (h.classList.contains('kt-booting') || h.classList.contains('kt-gating')) return true;
    var sp = doc.getElementById('kt-splash');
    if (sp && sp.isConnected && !sp.classList.contains('kt-hide') && sp.style.display !== 'none') return true;
    return !!(doc.getElementById('kt-launch') || doc.getElementById('kt-bio-lock') || doc.getElementById('kt-pin-lock') || doc.getElementById('kt-reload-gate'));
  }
  function update() {
    try {
      if (!doc.body) return;
      shape();
      var el = ensure();
      if (!isEducatorView()) { el.classList.remove('kt-cg-on', 'kt-cg-in', 'kt-cg-out', 'kt-cg-brk'); return; }
      /* NOT BEFORE THE APP IS UP, AND NOT ON A GUESS (2026-10-31, Anthony's TestFlight recording + the Pixel 8 emulator).
         Above the splash (it has to clear the status-bar band) the frame was drawn around the boot logo, and before the
         first /staff/punches answer isClockedIn() is false - so a clocked-in educator saw ORANGE for a beat, then green.
         Hidden while a boot cover or lock is up, and until the shift state has actually been read. */
      if (bootCovered() || (KT.clockBar && KT.clockBar.isKnown && !KT.clockBar.isKnown())) { el.classList.remove('kt-cg-on'); return; }
      var clockedIn = !!(KT.clockBar && KT.clockBar.isClockedIn && KT.clockBar.isClockedIn());
      var onBreak = !clockedIn && !!(KT.clockBar && KT.clockBar.isOnBreak && KT.clockBar.isOnBreak());
      el.classList.add('kt-cg-on');
      el.classList.toggle('kt-cg-in', clockedIn);
      el.classList.toggle('kt-cg-brk', onBreak);
      el.classList.toggle('kt-cg-out', !clockedIn && !onBreak);
    } catch (e) {}
  }

  // Poll (the punch state changes rarely, but this also catches role/view changes
  // and the clock bar loading after us) + react to navigation.
  (window.KT && KT.sweepBus) ? KT.sweepBus.on(update) : setInterval(update, 3000);
  window.addEventListener('hashchange', function () { setTimeout(update, 800); });
  // The boot cover lifting and the first punch answer are not DOM changes the sweep bus sees: look every 500ms
  // for the first 30s, so the frame arrives with the home screen instead of on the next sweep.
  var bootTicks = 0, bootTimer = setInterval(function () { update(); if (++bootTicks >= 60) clearInterval(bootTimer); }, 500);
  if (doc.readyState === 'loading') doc.addEventListener('DOMContentLoaded', function () { setTimeout(update, 1500); });
  else setTimeout(update, 1500);

  KT.clockGlow = { update: update };
})(window);
