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
    return (h >= 812 && dpr >= 2) || displayZoomed();
  }

  /* DISPLAY ZOOM (2026-11-06). Anthony: "the x at the top is cut off - make this right for all phone types". The guard's
     beacons came from an iPhone in the app reporting a 317x686 window: Settings > Display & Brightness > Display Zoom
     (or "Larger Text") turns a 390x844 / 375x812 notched iPhone into a 320-point-wide screen about 693 tall. That is
     under the 812 test above, so the phone was filed as "no cutout", nothing was substituted for the web view's 0
     inset, and every popup's header and ✕ (Fill & Sign, the document viewer, forms) sat under the clock. No iPhone
     iOS 18 runs on is 320 wide with a tall screen unless it is zoomed; zoomed, the status bar is also fewer CSS
     pixels (59pt / 1.22 ~ 48), so a zoomed phone gets 48/28. */
  function displayZoomed() {
    var mn = Math.min(w.screen.height || 0, w.screen.width || 0);
    var mx = Math.max(w.screen.height || 0, w.screen.width || 0);
    return mn > 0 && mn <= 330 && mx >= 680;
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
    if (displayZoomed()) { fallbackTop = isLandscape ? 0 : 48; fallbackBottom = isLandscape ? 17 : 28; }

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
    /* BELIEVE A REAL READING (2026-10-30, Anthony's iPhone recording). The floor exists for a web view that reports
       0 or the legacy ~20pt. But an iPhone XR / 11 is 896pt tall, so the height test above filed it with the Dynamic
       Island phones and forced 59 while the app honestly reported 48: an 11pt navy band under the clock on every
       screen (beacon "reported 48/34 -> used 59/34 (screen 896pt)"). A reading of 40+ is a real cutout inset - the
       smallest notch is 44 - so it is used as is; anything smaller is the shortfall the floor is for. */
    var useTop = top >= 40 ? top : Math.max(top, fallbackTop);
    var useBottom = bottom >= 20 ? bottom : Math.max(bottom, fallbackBottom);

    /* NOT IN A SAFARI TAB (2026-10-20). The floor above is for the app's own web view,
       which runs under the status bar and reports 0. Safari does not: it keeps the page
       clear of the status bar and of its own toolbar, and what env() reports there is
       true. Forcing 59/34 anyway padded the space Safari had already reserved, twice:
       Anthony's iPhone in Safari showed a band the height of the status bar again above
       the header, and a gap under the bottom bar. Android tabs already trust env(); an
       iPhone tab now does the same. The app and a Home Screen install keep the floor. */
    if (inBrowserTab()) {
      useTop = top;
      useBottom = bottom;
    }

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
        (inBrowserTab() ? 'tab, ' : 'app, ') + 'reported ' + top + '/' + bottom + ' → used ' + useTop + '/' + useBottom
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

  /* A real browser tab: Safari (or Chrome/Firefox on iOS, which carry the same token) and
     not installed. The app's web view has no "Safari/" in its user agent. */
  function inBrowserTab() {
    return /Safari\//.test(navigator.userAgent || '') && !ownsTheSystemBars();
  }

  /* NAVY STRIPS OVER THE SYSTEM-BAR INSETS, matching the APK.

     kt-native-ui.js has drawn these for the native build for a while; an installed PWA
     never ran that code because it sits behind a Capacitor check, so the same app
     installed from Chrome had plain system bars. Same ids and same declarations as the
     native painter, so when both run in the APK the second write changes nothing.

     --kt-safe-* first, env() second: this file substitutes a real value where iOS
     reports zero, and trusting env() alone is what once collapsed the navy band on an
     iPhone. (Anthony, 2026-09-08) */
  /* THE BOTTOM INSET IS NOT THE SAME THING ON BOTH PLATFORMS (2026-09-17).

     On ANDROID the bottom inset is occupied by a real system nav bar — home, back,
     recents — drawn over our content. Painting it navy is what makes the app look like
     it ends where the system bar begins, and that is why this strip exists.

     On iOS there is no such bar. The bottom inset is the home indicator, a thin pill
     that floats OVER the app, and Apple's own apps run their tab bar underneath it.
     So the strip was painting a navy band across the bottom of our own white bottom
     nav — 34pt of it — and the reader saw the bar, then a navy band, then whatever the
     native shell draws below the web view. Anthony, 2026-09-17, on the iPhone: "the
     bottom bar has a navy and then another white bar ... we should just bring the
     kiddietrac bar right to the bottom to fill the area."

     The bar already pays the inset itself (kt-mobile-app.css pads #kt-mobilenav by
     --kt-safe-bottom), so with the strip gone its own background fills that area — which
     is exactly what was asked for. The TOP strip stays on both: the status bar is drawn
     over the app on either platform, and Anthony says the top now looks right. */
  function wantsBottomFill() {
    return !isIOS();
  }

  /* ANDROID CHROME TABS DRAW EDGE-TO-EDGE NOW (2026-10-16). Anthony: "when logging into
     mobile through app.kiddietrac.com the bottom bar from android with back home etc is in
     white and not navy". ownsTheSystemBars() said a browser tab never owns the bottom, but
     current Chrome on Android draws the page UNDER the back/home/recents bar when the page
     asks for viewport-fit=cover (ours does), so that bar showed our white bottom nav through
     it. On Android the bottom strip is now drawn in a tab too. Where Chrome does not draw
     edge-to-edge, env(safe-area-inset-bottom) is 0 and the strip is 0px tall: no change. */
  function isAndroid() { return /Android/i.test(navigator.userAgent || ''); }

  function paintSystemBarFills() {
    var strip = function (id, edge) {
      var el = d.getElementById(id);
      var want = edge === 'top'
        ? ownsTheSystemBars()
        : (wantsBottomFill() && (ownsTheSystemBars() || isAndroid()));
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

  /* "IT'S STILL WHITE" (2026-10-16, same evening). Where Chrome does NOT draw under the
     system bar, the strip above is 0px and Chrome paints that bar itself, in the colour of
     the page's background: the root (<html>) background, which styles.css sets to the light
     --kt-bg along with body. The sign-in page is navy on both, which is why it looked right
     there. So in the app on Android the ROOT is navy and the body keeps its light colour:
     nothing visible changes (the body fills the screen), and the bar Chrome paints is navy. */
  function navyRootOnAndroid() {
    if (!isAndroid() || !/\/dashboard(\.src)?\.html$/.test(w.location.pathname || '')) { return; }
    var r = d.documentElement, b = d.body;
    r.style.setProperty('background-color', '#081C41', 'important');
    if (b) {
      b.style.setProperty('background-color', 'var(--kt-bg, #F7F9FC)');
      b.style.setProperty('min-height', '100vh');
    }
  }

  /* iOS-ONLY LAYOUT FIXES (2026-10-30, from Anthony's iPhone screen recording of the app).

     - html.kt-ios on every iPhone/iPad, html.kt-ios-app when we own the screen (the app, a Home Screen install).
     - DATE AND TIME FIELDS: iOS draws them with a fixed intrinsic width that ignores width:100%, so "Date of birth"
       ran past the right edge of My settings while every other field fitted. Plain appearance + min-width:0 makes
       them obey the form like any other field; tapping still opens the iOS wheel picker.
     - THE KEYBOARD: when it opens iOS scrolls the whole page to show the field, so on the sign-in screen the logo
       slid up under the clock; when it closed the page often stayed scrolled, leaving a big empty band at the
       bottom. html.kt-kb-open (visualViewport shorter than the window) lets the sign-in hero shrink while typing,
       and when the keyboard goes away a page that is not taller than the screen is put back at the top. */
  var KB = { open: false, t: null, tight: 0 };
  function iosCss() {
    if (d.getElementById('kt-ios-fix-css')) { return; }
    var st = d.createElement('style');
    st.id = 'kt-ios-fix-css';
    st.textContent = ''
      + 'html.kt-ios input[type="date"],html.kt-ios input[type="time"],html.kt-ios input[type="datetime-local"],html.kt-ios input[type="month"]'
      + '{-webkit-appearance:none;appearance:none;min-width:0 !important;max-width:100% !important;box-sizing:border-box !important;}'
      + 'html.kt-ios input[type="date"]::-webkit-date-and-time-value,html.kt-ios input[type="time"]::-webkit-date-and-time-value,'
      + 'html.kt-ios input[type="datetime-local"]::-webkit-date-and-time-value{text-align:left;min-height:1.25em;}'
      + 'html.kt-kb-open .ktl-form-side{justify-content:flex-start !important;padding-top:calc(var(--kt-safe-top, 0px) + 12px) !important;}'
      + 'html.kt-kb-open .ktl-mobile-hero .ktl-mhero-tag{display:none !important;}'
      + 'html.kt-kb-open .ktl-mobile-hero{margin-top:0 !important;margin-bottom:6px !important;padding-top:0 !important;}'
      + 'html.kt-kb-open .ktl-mobile-hero .ktl-mhero-card{transform:scale(.55);transform-origin:50% 0;margin-bottom:-53px !important;}'
      /* WHILE TYPING, THE FORM FITS ABOVE THE KEYBOARD (2026-11-02 if, Anthony's iPhone: "you have to scroll"). The
         subtitle and the "No account yet" line are dropped and the error line is compact while the keyboard is
         really up, so email, password and Log in all sit above it, the 4.7" SE (363pt visible) included. Driven
         only by kt-kb-open (a keyboard that is there), never by a touch or focus. */
      + 'html.kt-kb-open .ktl-form-side .ktl-sub,html.kt-kb-open .ktl-form-side .ktl-foot{display:none !important;}'
      + 'html.kt-kb-open .ktl-form-side .ktl-title{margin-bottom:6px !important;}'
      + 'html.kt-kb-open .ktl-form-side #errorMsg{padding:6px 10px !important;margin:4px 0 8px !important;font-size:13px !important;line-height:1.3 !important;min-height:0 !important;}'
      /* STILL UNDER THE KEYBOARD: TWO MORE STEPS (2026-11-03, verify lane SE, "The password field is required." showing:
         Log in 326-376 under a 339pt visible screen). The page fits the window, so WebKit puts back any scroll the hold
         makes (trace: sy 50 -> 0, twice) - only the layout can lift Log in. Measured by the hold, one step at a time:
         1 the "Welcome back" title goes, 2 the logo goes. Both leave when the keyboard does. */
      + 'html.kt-kb-open.kt-kb-tight1 .ktl-form-side .ktl-title{display:none !important;}'
      + 'html.kt-kb-open.kt-kb-tight2 .ktl-mobile-hero{display:none !important;}';
    (d.head || d.documentElement).appendChild(st);
  }
  /* KEYBOARD FIXES, REBUILT AND OPT-IN (2026-11-02, after the sign-in loop).
     Two fixes went live this morning and came out the same day: the sign-in page held in place while the keyboard
     opens, and an open conversation sized to the visible screen while typing. The sign-in one put the compact
     layout on at TOUCHSTART. That moves the form under a finger that is still down, so the tap's click landed on
     whatever slid under it - on a phone with "The email field is required." showing, the Log in button sits exactly
     where the email box was - and every tap on the email box submitted an empty form (nine 422s from the TestFlight
     iPhone, 15:37-15:38 UTC), then the layout went back 700 ms later because no field had focus. Repeat per tap.
     Rules for this version:
       - nothing changes the layout on touch or focus; only a keyboard that is really there (visualViewport at least
         150px shorter than the window, closed again under 100px - hysteresis) does;
       - events are coalesced (one look per 60 ms), a re-entrancy flag stops a look from triggering itself;
       - it never focuses or blurs anything, never acts on autofocus, never fights a finger (a touch ends the hold);
       - opt-in for now: localStorage kt_kbfix=1 (set by ?kbfix=1 or #kbfix=1 on any app page, cleared by kbfix=0)
         or a signed-in user listed in KBFIX_USERS; kill switch localStorage kt_no_kbfix=1 beats everything.
     Off = the 2026-10-30 behaviour exactly (kt-kb-open follows the keyboard, page back to the top when it closes). */
  var KBFIX_USERS = [1];
  function kbFixOn() {
    try {
      var ls = w.localStorage;
      var m = /(?:^|[?&#])kbfix=([01])\b/.exec((w.location.search || '') + '#' + (w.location.hash || '').replace(/^#/, ''));
      if (m) { ls.setItem('kt_kbfix', m[1]); }
      if (ls.getItem('kt_no_kbfix') === '1') { return false; }
      var v = ls.getItem('kt_kbfix');
      if (v === '1') { return true; }
      if (v === '0') { return false; }
      var u = null;
      try { u = JSON.parse(w.sessionStorage.getItem('kt_user') || 'null'); } catch (e2) { u = null; }
      if (u && KBFIX_USERS.indexOf(+u.id) !== -1) { ls.setItem('kt_kbfix', '1'); return true; }
    } catch (e) {}
    return false;
  }
  function signInPage() { return !!d.querySelector('.ktl-form-side') && !d.getElementById('appMain'); }
  function textField(el) {
    if (!el || !el.tagName) { return false; }
    var t = el.tagName;
    if (t === 'TEXTAREA') { return true; }
    if (t !== 'INPUT') { return false; }
    return !/^(checkbox|radio|button|submit|reset|file|image|range|color|hidden)$/i.test(el.type || '');
  }
  function keyboardClosedScroll() {
    clearTimeout(KB.t);
    KB.t = setTimeout(function () {
      var se = d.scrollingElement || d.documentElement;
      // Only a page that fits the screen: a long page keeps the place the person scrolled to.
      if (se && se.scrollTop > 0 && se.scrollHeight <= (w.innerHeight || 0) + 2) { w.scrollTo(0, 0); }
    }, 80);
  }

  /* SIGN-IN HOLD (opt-in). WebKit scrolls the page to reveal the field as the keyboard rises, measured on the FULL
     sign-in layout; the compact layout then arrives and the page sits scrolled past its own content. Once the
     keyboard is really up, the page is put at the least scroll that shows the focused field above it (0 for every
     sign-in field on a phone) - a few looks over 1.2 s while WebKit finishes moving, timer-driven only, and a touch
     ends it so the person can scroll. */
  var HOLD = { timers: [], until: 0 };
  function holdStop() { for (var i = 0; i < HOLD.timers.length; i++) { clearTimeout(HOLD.timers[i]); } HOLD.timers = []; HOLD.until = 0; }
  function holdLook() {
    try {
      if (!KB.open || Date.now() > HOLD.until || !signInPage()) { return; }
      var el = d.activeElement;
      if (!textField(el)) { return; }
      var vv = w.visualViewport, root = d.documentElement;
      var vh = vv.height, off = vv.offsetTop || 0;
      var sy = w.pageYOffset || (d.scrollingElement || root).scrollTop || 0;
      var r = el.getBoundingClientRect();
      var top = r.top + sy, bottom = r.bottom + sy;
      // Log in too, when it fits under the field: the whole form above the keyboard, nothing to scroll for.
      var btn = d.getElementById('submitBtn');
      if (btn && btn.offsetParent) {
        var br = btn.getBoundingClientRect();
        // Log in below the visible screen: one compact step (re-measured on the next look), not a scroll WebKit undoes.
        if (br.bottom > r.bottom && br.bottom + 8 > off + vh && KB.tight < 2) {
          KB.tight++;
          root.classList.add('kt-kb-tight' + KB.tight);
          return;
        }
        if (br.bottom > r.bottom && br.bottom - r.top + 22 <= vh) { bottom = br.bottom + sy; }
      }
      var want = Math.max(0, bottom + 14 - vh);
      if (want > top - 8) { want = Math.max(0, top - 8); }
      if (Math.abs(sy + off - want) > 3) { w.scrollTo(0, Math.max(0, want - off)); }
    } catch (e) {}
  }
  function tightOff() {
    KB.tight = 0;
    d.documentElement.classList.remove('kt-kb-tight1', 'kt-kb-tight2');
  }
  function holdStart() {
    holdStop();
    HOLD.until = Date.now() + 1200;
    [0, 120, 300, 550, 900].forEach(function (ms) { HOLD.timers.push(setTimeout(holdLook, ms)); });
  }

  /* CONVERSATION FITS THE VISIBLE SCREEN (opt-in). On a phone an open conversation is a full-screen fixed panel
     (100dvh); the keyboard does not shrink 100dvh in the app, so WebKit slid the whole screen up and the header and
     back arrow went off the top. While the keyboard is really up and the typing is in the panel, the panel is the
     visible part of the screen: header at the top, composer on the keyboard. Phone-width only. */
  var THREAD = { el: null };
  var THREAD_PROPS = ['top', 'bottom', 'height', 'max-height'];
  function threadPanel() {
    var c = d.querySelector('#appMain .kt-thread-compose');
    var p = c && c.parentElement;
    if (!p || p.id === 'appMain') { return null; }
    try { if (w.getComputedStyle(p).position !== 'fixed') { return null; } } catch (e) { return null; }
    return p;
  }
  function unfitThread(p) {
    if (!p || !p.hasAttribute('data-kt-kb-fit')) { return; }
    THREAD_PROPS.forEach(function (k) { p.style.removeProperty(k); });
    var c = p.querySelector('.kt-thread-compose');
    if (c) { c.style.removeProperty('padding-bottom'); }
    p.removeAttribute('data-kt-kb-fit');
  }
  function fitThread() {
    var vv = w.visualViewport;
    var p = d.getElementById('appMain') ? threadPanel() : null;
    if (THREAD.el && THREAD.el !== p) { unfitThread(THREAD.el); THREAD.el = null; }
    if (!p) { return; }
    var want = KB.open && (w.innerWidth || 0) <= 600 && textField(d.activeElement) && p.contains(d.activeElement);
    if (!want) { unfitThread(p); THREAD.el = null; return; }
    var top = Math.max(0, Math.round(vv.offsetTop || 0)), h = Math.round(vv.height);
    if (p.getAttribute('data-kt-kb-fit') === top + ':' + h) { return; }        // already there: no writes
    var body = p.querySelector('.kt-thread-body');
    var atEnd = body ? (body.scrollHeight - body.scrollTop - body.clientHeight < 40) : false;
    p.style.setProperty('top', top + 'px', 'important');
    p.style.setProperty('bottom', 'auto', 'important');
    p.style.setProperty('height', h + 'px', 'important');
    p.style.setProperty('max-height', h + 'px', 'important');
    var c = p.querySelector('.kt-thread-compose');
    if (c) { c.style.setProperty('padding-bottom', '8px', 'important'); }   // the home-indicator gap is under the keyboard
    p.setAttribute('data-kt-kb-fit', top + ':' + h);
    THREAD.el = p;
    if (body && atEnd) { body.scrollTop = body.scrollHeight; }                // the newest message stays in view
  }

  /* THE SCREEN'S FULL HEIGHT, KEYBOARD OR NOT (2026-11-02 kh). iOS 27 WebKit shrinks window.innerHeight with the keyboard
     (verify lane, iPhone SE: innerHeight 667 -> 495 while visualViewport.height was 366), so innerHeight - vv.height was
     129 and the keyboard never counted as open: no compact sign-in form, Log in under the keyboard bar. The root
     element's clientHeight stayed 667. The larger of the two is the full height on every iOS (older ones keep
     innerHeight full, as before). */
  function fullHeight() { return Math.max(w.innerHeight || 0, d.documentElement.clientHeight || 0); }

  function keyboardWatch() {
    var vv = w.visualViewport;
    if (!vv || w.__ktKbWatch) { return; }
    w.__ktKbWatch = true;
    /* SIGN-IN HOLD FOR EVERYONE (2026-11-02 if). Opt-in left it off exactly where it mattered: a signed-out phone has
       no kt_user, so Anthony's freshly installed TestFlight app typed into an email box scrolled off the top (WebKit
       revealed the field on the full layout, then kt-kb-open shrank the hero under that scroll). The hold is the
       keyboard-driven, coalesced path below; it never reacts to a touch or a focus. The conversation fit stays
       opt-in (kbFixOn). kt_no_kbfix=1 still restores the 2026-10-30 behaviour exactly. */
    var threadFit = kbFixOn();
    var killed = false;
    try { killed = w.localStorage.getItem('kt_no_kbfix') === '1'; } catch (e) {}
    if (killed) {
      w.__ktKbFix = 'off';
      vv.addEventListener('resize', function () {
        var open = vv.height < fullHeight() - 120;
        if (open === KB.open) { return; }
        KB.open = open;
        d.documentElement.classList.toggle('kt-kb-open', open);
        if (!open) { keyboardClosedScroll(); }
      });
      return;
    }
    w.__ktKbFix = threadFit ? 'on' : 'hold';
    var busy = false, queued = null;
    function look() {
      queued = null;
      if (busy) { return; }
      busy = true;
      try {
        var shrink = fullHeight() - vv.height;
        var open = KB.open ? shrink > 100 : shrink > 150;                  // a real keyboard, with hysteresis
        if (open !== KB.open) {
          KB.open = open;
          d.documentElement.classList.toggle('kt-kb-open', open);
          if (open) { if (signInPage()) { holdStart(); } } else { holdStop(); tightOff(); keyboardClosedScroll(); }
          KB.vh = open ? vv.height : 0;
        } else if (open && vv.height < KB.vh - 8) {
          /* THE KEYBOARD GREW AFTER IT OPENED (2026-11-02 kg): iOS adds the Passwords/QuickType bar a few hundred ms
             later (lane SE: visualViewport 366 -> 339) - after the 1.2 s hold had looked, so with "The email field is
             required." showing, Log in stayed under the bar. A taller keyboard looks again; never under a finger. */
          KB.vh = vv.height;
          if (signInPage() && !KB.finger) { holdStart(); }
        } else if (open && vv.height > KB.vh) { KB.vh = vv.height; }
        if (threadFit) { fitThread(); }
      } catch (e) {}
      busy = false;
    }
    function soon() { if (queued === null) { queued = setTimeout(look, 60); } }
    vv.addEventListener('resize', soon);
    vv.addEventListener('scroll', soon);
    // Focus leaving the conversation while the keyboard stays (rare): the panel lets go. Restores only; never fits.
    d.addEventListener('focusout', soon, true);
    // A finger on the screen is the person in charge: the sign-in hold stops.
    d.addEventListener('touchstart', function () { KB.finger = true; if (HOLD.until) { holdStop(); } }, { capture: true, passive: true });
    d.addEventListener('touchend', function () { KB.finger = false; }, { capture: true, passive: true });
    d.addEventListener('touchcancel', function () { KB.finger = false; }, { capture: true, passive: true });
  }

  function boot() {
    try {
      if (isIOS()) {
        d.documentElement.classList.add('kt-ios');
        d.documentElement.classList.toggle('kt-ios-app', ownsTheSystemBars());
        iosCss();
        keyboardWatch();
      }
    } catch (e) {}
    try { navyRootOnAndroid(); } catch (e) {}
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
