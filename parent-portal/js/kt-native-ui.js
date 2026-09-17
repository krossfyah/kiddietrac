/* ═══════════════════════════════════════════════════════════════════
   A NEW BUILD LANDS WITHOUT YANKING THE PAGE — ON THE WEB TOO.

   Everything below this block is gated on `native` and no-ops in a browser. The
   reload-when-idle logic there was written on 2026-08-27 for a real complaint ("a
   popup loses focus and another screen pops up randomly") but has therefore only
   ever protected the app. On the web the service worker force-navigated every open
   window the instant a deploy landed, through dialogs and half-typed forms alike,
   which is what "my browser tab froze" was: several windows each re-parsing 5 MB of
   script at the same moment. That call is gone (see service-worker.js), so this is
   now what delivers an update to a browser — politely, or not at all until the page
   is idle.
   ═══════════════════════════════════════════════════════════════════ */
(function () {
  'use strict';
  if (!('serviceWorker' in navigator)) return;

  /* Once every two minutes at most. A plain "already reloaded" flag — which is what
     the native path uses — never clears, so only the FIRST update of a session is ever
     picked up and a later deploy is ignored until somebody navigates by hand. A
     timestamp still makes a reload loop impossible while letting the next deploy
     land normally. */
  var GAP_MS = 120000;
  function recentlyReloaded() {
    try {
      var t = Number(sessionStorage.getItem('kt_sw_reloaded_at') || 0);
      return t > 0 && (Date.now() - t) < GAP_MS;
    } catch (e) { return false; }
  }
  function mark() {
    try { sessionStorage.setItem('kt_sw_reloaded_at', String(Date.now())); } catch (e) {}
  }

  var tries = 0;
  function busy() {
    try {
      // A dialog, sheet, lightbox or menu is open — reloading discards it.
      var mr = document.getElementById('modalRoot');
      if (mr && mr.firstElementChild) return true;
      if (document.querySelector('.modal-backdrop, [role="dialog"], .kt-scrim,'
        + ' .kt-doc-viewer, .kt-av-zoom, .kt-lightbox, [data-kt-open-menu],'
        + ' .kt-sheet-open, .kt-modal, .kt-modal-overlay')) return true;
      // Somebody is typing. Reloading mid-entry loses what they wrote.
      var a = document.activeElement;
      if (a) {
        if (/^(INPUT|TEXTAREA|SELECT)$/.test(a.tagName || '')) return true;
        if (a.isContentEditable) return true;
      }
      // Not on screen: wait for them to come back rather than reloading at nobody.
      if (document.hidden) return true;
    } catch (e) {}
    return false;
  }

  /* RELOAD WHEN THE SCREEN WAS GOING TO CHANGE ANYWAY.

     A service-worker update used to reload the moment nothing was "busy" — no dialog, not
     typing, tab visible. But somebody reading a stationary screen is not busy by that
     definition, and the whole app rebooting under them is the most visible refresh there
     is: white flash, boot spinner, scroll lost. In the APK it is worse than on the web,
     because checkForUpdate() runs on EVERY resume, so a phone that is picked up and put
     down all day meets every deploy.

     So the reload now waits for a moment that is already a transition — the app being
     resumed, or the reader navigating to another screen. The new assets still land, at a
     point where a repaint costs nothing and reads as the app opening rather than as the
     app randomly refreshing. If neither happens, the deferred loop below still gets there
     eventually. (Anthony, 2026-09-08) */
  var armed = false;
  function transitionSoon() {
    if (armed) { return; }
    armed = true;
    var fire = function () {
      if (busy()) { return; }              // still not a good moment; wait for the next one
      armed = false;
      mark();
      try { location.reload(); } catch (e) {}
    };
    // Coming back to the app: it is already repainting, so a reload hides inside that.
    document.addEventListener('visibilitychange', function () {
      if (!document.hidden) { setTimeout(fire, 60); }
    });
    // Moving to another screen: the content is being replaced regardless.
    window.addEventListener('hashchange', function () { setTimeout(fire, 60); });
  }

  function go() {
    /* HIDDEN FIRST, and deliberately before busy().

       busy() counts a hidden tab as busy — its reasoning was "wait for them to come back
       rather than reloading at nobody". For an update that is exactly backwards: nobody
       looking is the one moment a full reload costs nothing at all. Checking it after
       busy() also made this branch unreachable, which is how a deferred update stopped
       landing entirely. */
    if (document.hidden) {
      mark();
      try { location.reload(); } catch (e) {}
      return;
    }
    if (!busy()) {
      transitionSoon();
      return;
    }
    /* Give up after ~5 minutes of somebody living in a dialog — the stale page keeps
       working and the next navigation picks the new assets up anyway, which beats
       interrupting them. Time spent HIDDEN is not counted: a tab left open overnight
       should update the moment its owner returns, not be abandoned at minute five. */
    if (!document.hidden && ++tries > 300) return;
    setTimeout(go, 1000);
  }

  try {
    // Tells the Capacitor listener below that this one owns the reload.
    try { window.__ktSwHandled = true; } catch (e) {}
    navigator.serviceWorker.addEventListener('controllerchange', function () {
      if (recentlyReloaded()) return;
      go();
    });
  } catch (e) {}
})();

/* ═══════════════════════════════════════════════════════════════════
   KiddieTrac — reliable native auto-update (2026-07).

   NOTE: full-screen (StatusBar.hide + setOverlaysWebView) was REMOVED — on the
   APK it drew the WebView edge-to-edge WITHOUT the native display-cutout flag,
   which shifted content up under the status bar / notch and made the view look
   broken (it only misbehaved on the real device, never in desktop emulation,
   because this runs on native only). Full-screen + camera-cutout will be done
   PROPERLY via the APK rebuild (windowLayoutInDisplayCutoutMode in styles.xml +
   capacitor.config), never from JS at runtime.

   What this file still does: the Capacitor WebView keeps the page in memory, so
   tapping the icon (a resume) never reloads and deploys looked "stuck." On every
   resume (and at boot) force a service-worker update check, and when a new worker
   takes control, reload ONCE so the fresh JS/CSS shows. No-op on the web.
   ═══════════════════════════════════════════════════════════════════ */
(function () {
  'use strict';
  try {
    var C = window.Capacitor;
    var native = C && (C.isNativePlatform ? C.isNativePlatform() : C.isNative);
    if (!native) return;

    var checkForUpdate = function () {
      try {
        if (!('serviceWorker' in navigator)) return;
        navigator.serviceWorker.getRegistrations().then(function (regs) {
          regs.forEach(function (r) { try { var p = r.update(); if (p && p.catch) p.catch(function () {}); } catch (e) {} });
        }).catch(function () {});
      } catch (e) {}
    };
    try {
      if ('serviceWorker' in navigator) {
        /* A new worker has taken over, so the page is running against assets that no
           longer match and does need to reload — but not THIS INSTANT. Reloading on top
           of an open dialog throws away whatever the user was doing and drops them on a
           different screen, which is what "a popup loses focus and another screen pops up
           randomly" actually was. (2026-08-27)

           Deferred, never cancelled: it waits for a moment that costs nothing. */
        var reloadWhenIdle = (function () {
          var tries = 0;
          function unsafe() {
            try {
              // A dialog, sheet, lightbox or menu is open.
              var mr = document.getElementById('modalRoot');
              if (mr && mr.firstElementChild) return true;
              if (document.querySelector('.modal-backdrop, [role="dialog"], .kt-scrim,'
                + ' .kt-doc-viewer, .kt-av-zoom, .kt-lightbox, [data-kt-open-menu],'
                + ' .kt-sheet-open')) return true;
              // The user is typing. Reloading mid-entry loses what they wrote.
              var a = document.activeElement;
              if (a) {
                if (/^(INPUT|TEXTAREA|SELECT)$/.test(a.tagName || '')) return true;
                if (a.isContentEditable) return true;
              }
              // Tab is in the background — reload on their terms, when they come back.
              if (document.hidden) return true;
            } catch (e) {}
            return false;
          }
          return function go() {
            if (!unsafe()) {
              try { location.reload(); } catch (e) {}
              return;
            }
            /* Give up politely after ~5 minutes of the user living in a dialog. The stale
               page keeps working; the next navigation or manual reload picks up the new
               assets anyway, which is a far better outcome than interrupting them. */
            /* A hidden tab is not busy, it is ABSENT — so waiting for somebody to
               come back must not spend the budget meant for somebody mid-sentence.
               Otherwise a tab left open overnight is abandoned after five minutes and
               stays on the old build until it is navigated by hand. */
            if (!document.hidden && ++tries > 300) { return; }
            setTimeout(go, 1000);
          };
        })();

        /* ONE reloader, not two. This file registers a controllerchange handler at the
           top as well, and both called location.reload() on their own timing and their
           own flags — which is how a single deploy could reload the app twice. The one
           above now defers to a natural transition; this listener stays only as the
           fallback for a page where that one did not run, and is skipped whenever it
           did. */
        navigator.serviceWorker.addEventListener('controllerchange', function () {
          try {
            if (window.__ktSwHandled) { return; }
            if (sessionStorage.getItem('kt_sw_reloaded') === '1') { return; }
            sessionStorage.setItem('kt_sw_reloaded', '1');
          } catch (e) {}
          reloadWhenIdle();
        });
      }
    } catch (e) {}
    checkForUpdate();

    try {
      var App = C.Plugins && C.Plugins.App;
      if (App && App.addListener) {
        App.addListener('appStateChange', function (s) { if (s && s.isActive) checkForUpdate(); });
      }
    } catch (e) {}

    // NAVY STATUS BAR to match the PWA/browser (which colours it via the
    // <meta name="theme-color" content="#081C41">). The native APK ignores
    // theme-color, so set the Capacitor StatusBar plugin here at runtime — no
    // rebuild needed (the plugin already ships in the app). #081C41 background
    // with 'DARK' style = light/white icons (correct for a dark bar). Re-applied
    // on every resume in case the OS resets it.
    var paintStatusBar = function () {
      try {
        var SB = C.Plugins && C.Plugins.StatusBar;
        if (SB) {
          // Android 15/16 IGNORES setStatusBarColor — the bars are forced transparent.
          // So a solid NATIVE navy bar is impossible; instead draw the WebView BEHIND
          // the (transparent) status bar and paint that region ourselves. overlay:true
          // = edge-to-edge (makes env(safe-area-inset-top) non-zero); DARK style =
          // white icons for the navy strip.
          if (SB.setOverlaysWebView) { try { SB.setOverlaysWebView({ overlay: true }); } catch (e) {} }
          if (SB.setStyle) { try { SB.setStyle({ style: 'DARK' }); } catch (e) {} }
        }
      } catch (e) {}
      // Navy strips covering the TOP (status-bar) and BOTTOM (Android nav-bar) insets
      // — matches the PWA theme-color (#081C41). Heights follow the real safe-area
      // insets, so each is exactly the system bar. The bottom one sits UNDER the
      // fixed app bottom-nav (higher z is fine — it only fills the inset the app
      // already pads for, behind the home/back/recents buttons).
      try {
        var top = document.getElementById('kt-statusfill');
        if (!top) { top = document.createElement('div'); top.id = 'kt-statusfill'; (document.body || document.documentElement).appendChild(top); }
        /* var(--kt-safe-top) FIRST, env() second.

           kt-ios-safearea.js measures the real inset and substitutes 47pt where iOS
           reports zero — which it does at the top, because the WebView is laid out
           under a status bar it then draws over. This strip trusted env() alone, so on
           iPhone it collapsed to nothing: the navy band vanished, the page began under
           the clock, and the logo sat behind the time. Android reports honestly, the
           variable equals env() there, and nothing changes for it. */
        top.style.cssText = 'position:fixed;top:0;left:0;right:0;'
          + 'height:var(--kt-safe-top, env(safe-area-inset-top, 0px));'
          + 'background:#081C41;z-index:2147483600;pointer-events:none;';
        /* ANDROID ONLY. The bottom inset holds a real system nav bar there — home,
           back, recents — and the navy makes the app end where that bar begins. iOS has
           no such bar: the home indicator floats over the app, and Apple's own tab bars
           run underneath it. On the iPhone this strip painted a 34pt navy band across
           the bottom of our own white bottom nav, which is what Anthony reported on
           2026-09-17. #kt-mobilenav already pads itself by --kt-safe-bottom, so with the
           strip gone the bar's own background fills that area. Kept in step with the
           same rule in kt-ios-safearea.js, which draws these for the installed PWA. */
        var _iOS = /iPad|iPhone|iPod/.test(navigator.userAgent || '')
          || (/Macintosh/.test(navigator.userAgent || '') && navigator.maxTouchPoints > 1);
        var bot = document.getElementById('kt-navfill');
        if (_iOS) {
          if (bot) { bot.remove(); }
        } else {
          if (!bot) { bot = document.createElement('div'); bot.id = 'kt-navfill'; (document.body || document.documentElement).appendChild(bot); }
          bot.style.cssText = 'position:fixed;bottom:0;left:0;right:0;'
            + 'height:var(--kt-safe-bottom, env(safe-area-inset-bottom, 0px));'
            + 'background:#081C41;z-index:2147483600;pointer-events:none;';
        }
      } catch (e) {}
    };
    paintStatusBar();
    try {
      var App2 = C.Plugins && C.Plugins.App;
      if (App2 && App2.addListener) {
        App2.addListener('appStateChange', function (s) { if (s && s.isActive) paintStatusBar(); });
      }
    } catch (e) {}
  } catch (e) {}
})();
