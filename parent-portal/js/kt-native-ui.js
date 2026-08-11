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
        navigator.serviceWorker.addEventListener('controllerchange', function () {
          try { if (sessionStorage.getItem('kt_sw_reloaded') === '1') return; sessionStorage.setItem('kt_sw_reloaded', '1'); } catch (e) {}
          try { location.reload(); } catch (e) {}
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
        if (!SB) return;
        if (SB.setOverlaysWebView) { try { SB.setOverlaysWebView({ overlay: false }); } catch (e) {} }
        if (SB.setBackgroundColor) { try { SB.setBackgroundColor({ color: '#081C41' }); } catch (e) {} }
        if (SB.setStyle) { try { SB.setStyle({ style: 'DARK' }); } catch (e) {} }
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
