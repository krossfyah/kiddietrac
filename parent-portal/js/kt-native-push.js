/* ═══════════════════════════════════════════════════════════════════
   KiddieTrac — native (Capacitor/FCM) push registration.
   Inert in a plain browser and until @capacitor/push-notifications is in the
   build. Once the rebuilt APK has the plugin + google-services.json, this:
     • creates a HIGH-importance channel (so Android plays sound + vibrates)
     • asks permission, registers, and POSTs the FCM token to /push/device
     • deep-links on notification tap (data.link → location.hash)
   No web-portal changes are needed after the APK rebuild — this is already here.
   ═══════════════════════════════════════════════════════════════════ */
(function () {
  'use strict';
  if (window.__ktNativePush) return; window.__ktNativePush = true;

  function apiBase() { return (window.KT_CONFIG && window.KT_CONFIG.apiBase) || 'https://api.kiddietrac.com/api/v1'; }
  // 'ios' | 'android' | 'web'. The device token is only meaningful together with
  // the platform it came from — APNs and FCM tokens are not interchangeable.
  function platform() {
    try {
      if (window.Capacitor && Capacitor.getPlatform) return Capacitor.getPlatform();
    } catch (e) {}
    return 'android';
  }

  function token() { try { return sessionStorage.getItem('kt_token') || localStorage.getItem('kt_token'); } catch (e) { return null; } }

  async function init() {
    var Cap = window.Capacitor;
    if (!Cap || typeof Cap.isNativePlatform !== 'function' || !Cap.isNativePlatform()) return; // native only
    var PN = (Cap.Plugins && Cap.Plugins.PushNotifications) || window.PushNotifications;
    if (!PN) return; // plugin not in this build yet — no-op

    try {
      if (platform() === 'android' && PN.createChannel) {
        // v2 channel carries the custom KiddieTrac chime (res/raw/kt_notify.wav).
        // A channel's sound is fixed once created, so the custom tone needs a NEW
        // channel id — FcmService sends to 'kt_default_v2'. To swap the sound later,
        // drop a new file in res/raw and bump the channel id here + in FcmService.
        await PN.createChannel({
          id: 'kt_default_v2', name: 'KiddieTrac alerts',
          description: 'New messages, invoices and photos',
          importance: 5, sound: 'kt_notify', vibration: true, lights: true, visibility: 1,
        });
        // Keep the old channel registered too (older notifications / back-compat).
        await PN.createChannel({
          id: 'kt_default', name: 'KiddieTrac (classic)',
          description: 'New messages, invoices and photos',
          importance: 5, sound: 'default', vibration: true, lights: true, visibility: 1,
        });
      }
      var perm = await PN.requestPermissions();
      if (perm && perm.receive !== 'granted') return;
      await PN.register();

      PN.addListener('registration', function (t) {
        var tok = token(); if (!tok || !t || !t.value) return;
        fetch(apiBase() + '/push/device', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'Authorization': 'Bearer ' + tok },
          body: JSON.stringify({ platform: platform(), token: t.value }),
        }).catch(function () {});
      });

      // FOREGROUND: when the app is open, Android delivers the push to the app
      // instead of showing it in the bar — so post a local system notification
      // ourselves (via the native KtBio.notify bridge) so it ALWAYS appears in the
      // Android notification drawer with sound + vibration.
      PN.addListener('pushNotificationReceived', function (n) {
        try {
          var title = (n && n.title) || 'KiddieTrac';
          var body = (n && n.body) || '';
          var link = (n && n.data && n.data.link) || '';
          var KtBio = Cap.Plugins && Cap.Plugins.KtBio;
          if (KtBio && KtBio.notify) { KtBio.notify({ title: title, body: body, link: link }); }
        } catch (e) {}
      });

      PN.addListener('pushNotificationActionPerformed', function (a) {
        try { var link = a && a.notification && a.notification.data && a.notification.data.link; if (link) location.hash = link; } catch (e) {}
      });
    } catch (e) { if (window.console) console.warn('native push init failed', e); }
  }

  // Register once the auth token exists (retry briefly after boot).
  var tries = 0;
  (function waitAuth() {
    if (token()) { init(); return; }
    if (tries++ > 20) return;
    setTimeout(waitAuth, 1500);
  })();
})();
