/* ═══════════════════════════════════════════════════════════════════
   KiddieTrac — iOS safe-area fallback.

   The stylesheet already does this correctly: `viewport-fit=cover` is set in
   dashboard.html, an unconditional `@supports (padding-top: env(safe-area-inset-top))`
   block pads the header, and the bottom bar adds `env(safe-area-inset-bottom)` to its
   own padding. On Android all of that works, which is why Android has always looked
   right.

   On the iPhone build it does not: the web view runs full-screen (the logo sits
   underneath the clock) while `env(safe-area-inset-*)` still resolves to 0px, so every
   rule that depends on it is inert. Reported repeatedly, and the CSS-only fixes could
   never have worked, because they all read a value the WebView reports as zero.

   So: MEASURE, do not assume. A probe element asks the browser what the inset actually
   computes to. If it is a real number, this file does nothing at all — no double
   padding, ever. Only when the inset is genuinely 0 on a device that clearly has a
   cutout does it substitute a measured-by-hand fallback.

   Self-disabling by construction: the day the iOS shell reports insets properly, the
   probe reads non-zero and this code steps aside.
   ═══════════════════════════════════════════════════════════════════ */
(function (w, d) {
  'use strict';

  function envInset(side) {
    // A detached probe cannot be affected by page styles.
    var probe = d.createElement('div');
    probe.style.cssText = 'position:fixed;top:0;left:0;width:0;height:0;visibility:hidden;'
      + 'padding-top:env(safe-area-inset-' + side + ', 0px);';
    d.documentElement.appendChild(probe);
    var v = 0;
    try { v = parseFloat(w.getComputedStyle(probe).paddingTop) || 0; } catch (e) { v = 0; }
    probe.remove();
    return v;
  }

  function isIOS() {
    var ua = navigator.userAgent || '';
    // iPadOS 13+ reports as Macintosh, so the touch check matters.
    return /iPad|iPhone|iPod/.test(ua)
      || (/Macintosh/.test(ua) && navigator.maxTouchPoints > 1);
  }

  /**
   * Does this screen have a cutout / home indicator? Every notched iPhone is at least
   * 812pt tall with a >=2 device pixel ratio. Older 4.7"/5.5" phones (667/736pt) have
   * neither, and must not be padded.
   */
  function hasCutout() {
    var h = Math.max(w.screen.height || 0, w.screen.width || 0);
    var dpr = w.devicePixelRatio || 1;
    return h >= 812 && dpr >= 2;
  }

  function apply() {
    var el = d.documentElement;
    var top = envInset('top');
    var bottom = envInset('bottom');

    if (!isIOS() || !hasCutout()) {
      el.classList.remove('kt-ios-inset-fallback');
      return;
    }

    /* PER SIDE, not all-or-nothing.
       The original guard stood down whenever EITHER side was non-zero. iOS reports a
       real bottom inset (34pt of home indicator) and 0 at the top — the WebView is laid
       out under a status bar it then draws over — so one honest side silenced the fix
       for the broken one. That is why the bottom of the app looked right and the logo
       sat under the clock, on every build since this file was written. (2026-08-27)

       Measured, not guessed: 47pt covers the notch and Dynamic Island status bar, 34pt
       the home indicator. In landscape the status bar collapses, so the top is 0 while
       the indicator stays. */
    var isLandscape = (w.innerWidth || 0) > (w.innerHeight || 0);

    /* TWO DIFFERENT CUTOUTS, TWO DIFFERENT INSETS. 47pt is a NOTCH (iPhone X–14).
       A DYNAMIC ISLAND phone reports ~59pt, and padding it by 47 leaves the header
       about 12pt short — the logo clears the clock while the greeting is still
       clipped behind the island. That partial miss is why this kept reading as
       "still not right" instead of "not applied".

       Screen height separates the families cleanly:
         >= 852pt  Dynamic Island (14 Pro 852, 16 Pro 874, Pro Max 932/956)
         >= 812pt  notch          (X/11 812, 12–14 844, XR/11 896, Pro Max 926) */
    var maxDim = Math.max(w.screen.height || 0, w.screen.width || 0);
    var fallbackTop = isLandscape ? 0 : (maxDim >= 852 ? 59 : 47);
    var fallbackBottom = isLandscape ? 21 : 34;

    /* TOO SMALL IS AS WRONG AS ZERO — and it failed in a worse way.

       This took any non-zero reading as honest and used it as-is. But a notched iPhone
       can report the LEGACY STATUS BAR height (about 20pt) instead of the cutout,
       depending on how the web view was laid out. 20 is not zero, so the old code
       believed it, used 20, and — because it only added the fallback class while
       something was being SUBSTITUTED — also dropped out of the class. The header then
       fell back to `max(var(--kt-safe-top), 22px)` = 22px on a phone that needs 59, and
       the logo, the avatar and the gear were all clipped by the status bar. Half-applied
       reads as "still broken", which is why this kept coming back after each fix.

       A cutout cannot be shorter than the cutout. On a device we have positively
       identified as notched, the inset is the LARGER of what the browser says and what
       that hardware actually needs. Where the browser is right the two agree and nothing
       changes; where it under-reports we stop believing it. It can never double-pad,
       because this writes a VALUE, not an extra padding — every rule reads the one
       variable. */
    var useTop = Math.max(top, fallbackTop);
    var useBottom = Math.max(bottom, fallbackBottom);

    el.style.setProperty('--kt-safe-top', useTop + 'px');
    el.style.setProperty('--kt-safe-bottom', useBottom + 'px');

    /* ONE SET OF RULES, ALWAYS, on a device with a cutout.

       The class used to come and go with whether a substitution was happening, so the
       layout was governed by one set of rules on some launches and another on others —
       for the same phone, depending on what the web view happened to report that time.
       Both sets read --kt-safe-*, so there is nothing to double up; making it constant
       just removes a variable from a problem that has had too many. */
    el.classList.add('kt-ios-inset-fallback');

    /* What was measured, where somebody can read it. Every previous round of this bug
       was argued from screenshots; a number settles it. Visible in a crash report and in
       the DOM. */
    try {
      el.setAttribute('data-kt-insets',
        'reported ' + top + '/' + bottom + ' → used ' + useTop + '/' + useBottom
        + ' (screen ' + maxDim + 'pt, dpr ' + (w.devicePixelRatio || 1) + ')');
    } catch (e) {}
  }

  /* Is the app running in its own window — an installed PWA or the native shell —
     rather than in a browser tab? Only then do the system-bar insets belong to the
     page. In a tab the browser owns that space: the top is already coloured by
     <meta name="theme-color" content="#081C41"> and the bottom is not ours to paint. */
  function ownsTheSystemBars() {
    try {
      if (w.Capacitor && w.Capacitor.isNativePlatform && w.Capacitor.isNativePlatform()) {
        return true;
      }
    } catch (e) {}
    try {
      if (w.navigator && w.navigator.standalone === true) { return true; }   // iOS home screen
      return !!(w.matchMedia
        && (w.matchMedia('(display-mode: standalone)').matches
         || w.matchMedia('(display-mode: fullscreen)').matches
         || w.matchMedia('(display-mode: minimal-ui)').matches));
    } catch (e) { return false; }
  }

  /* NAVY STRIPS OVER THE SYSTEM-BAR INSETS, matching the APK.

     kt-native-ui.js has drawn these for the native build for a while; an installed PWA
     never ran that code because it sits behind a Capacitor check, so the same app
     installed from Chrome had plain system bars. Same ids and same declarations as the
     native painter, so when both run in the APK the second write changes nothing.

     --kt-safe-* first, env() second: this file substitutes a real value where iOS
     reports zero, and trusting env() alone is what once collapsed the navy band on an
     iPhone. (Anthony, 2026-09-08) */
  function paintSystemBarFills() {
    var strip = function (id, edge) {
      var el = d.getElementById(id);
      var want = ownsTheSystemBars();
      if (!want) { if (el) { el.remove(); } return; }
      if (!el) {
        el = d.createElement('div');
        el.id = id;
        (d.body || d.documentElement).appendChild(el);
      }
      el.style.cssText = 'position:fixed;' + edge + ':0;left:0;right:0;'
        + 'height:var(--kt-safe-' + (edge === 'top' ? 'top' : 'bottom')
        + ', env(safe-area-inset-' + (edge === 'top' ? 'top' : 'bottom') + ', 0px));'
        + 'background:#081C41;z-index:2147483600;pointer-events:none;';
    };
    strip('kt-statusfill', 'top');
    strip('kt-navfill', 'bottom');
  }

  function boot() {
    try { apply(); } catch (e) { /* never let chrome-fixing break the app */ }
    /* After apply(), so the strips read the variables it has just written. */
    try { paintSystemBarFills(); } catch (e) {}
  }

  if (d.readyState === 'loading') {
    d.addEventListener('DOMContentLoaded', boot);
  } else {
    boot();
  }
  // Rotation changes which edges have insets.
  w.addEventListener('orientationchange', function () { setTimeout(boot, 250); });
  w.addEventListener('resize', function () { clearTimeout(w.__ktSafeT); w.__ktSafeT = setTimeout(boot, 300); });
})(window, document);
