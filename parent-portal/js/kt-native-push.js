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

  /* THE TAP THAT ARRIVES BEFORE THE APP CAN ACT ON IT.

     Tapping a notification usually COLD-STARTS the app — that is the whole point of a
     notification. Capacitor delivers `pushNotificationActionPerformed` as soon as the
     web view exists, but the listener used to be added inside init(), which waits for an
     auth token (retried every 1.5s) and then awaits createChannel + requestPermissions +
     register. By the time it subscribed, the tap had been and gone: the link was dropped
     and the app opened on the home screen. Reported as "I get the notification and click
     open and it doesn't go to the chat".

     So the link is REMEMBERED the moment it arrives and applied when there is something
     to apply it to — a token, and a rendered shell. It survives the login screen too,
     which matters when the tap is what wakes a signed-out phone. (Anthony, 2026-09-09) */
  var PENDING = 'kt_pending_link';

  function rememberLink(link) {
    if (!link) { return; }
    try { sessionStorage.setItem(PENDING, link); } catch (e) {}
    routePending();
  }

  function shellReady() {
    return !!document.getElementById('appMain') && !!(window.KT && KT.Shell);
  }

  function routePending() {
    var link;
    try { link = sessionStorage.getItem(PENDING); } catch (e) { return; }
    if (!link) { return; }
    if (!token() || !shellReady()) { return; }      // try again on the next tick
    try { sessionStorage.removeItem(PENDING); } catch (e) {}

    /* A guardian's Messenger is #messages, not #chat — #chat renders another screen
       entirely for them. The thread id is kept for the day that screen learns to open
       one; landing on their inbox is already right, where the newest message is on top. */
    try {
      var u = JSON.parse(sessionStorage.getItem('kt_user') || localStorage.getItem('kt_user') || '{}');
      var roles = [].concat(u.roles || [], u.primary_role || []);
      var staff = roles.some(function (r) { return r && r !== 'guardian'; });
      if (!staff && link.indexOf('#chat') === 0) { link = '#messages'; }
    } catch (e) {}

    if (location.hash === link) {
      // Already there: the hash will not fire, so ask for the repaint directly.
      try { KT.Shell.renderScreen(); } catch (e) {}
    } else {
      location.hash = link;
    }
  }

  /* The shell can take a few seconds to boot on a cold start, and the tap may also land
     while the app is merely asleep. Cheap, bounded, and stops as soon as it lands. */
  (function drainPending() {
    routePending();
    var n = 0;
    var iv = setInterval(function () {
      var has = false;
      try { has = !!sessionStorage.getItem(PENDING); } catch (e) {}
      if (!has || ++n > 40) { clearInterval(iv); return; }   // ~60s
      routePending();
    }, 1500);
    document.addEventListener('visibilitychange', function () {
      if (!document.hidden) { routePending(); }
    });
  })();

  /* The WEB-PUSH tap, which arrives as a message from the service worker rather than
     through Capacitor. Same destination, same remembering, so both transports land in the
     same place — and this one is what a PWA install and the desktop browser use. */
  try {
    if (navigator.serviceWorker && navigator.serviceWorker.addEventListener) {
      navigator.serviceWorker.addEventListener('message', function (ev) {
        var d = ev && ev.data;
        if (d && d.type === 'kt-notification-click' && d.url) {
          var h = String(d.url);
          var i = h.indexOf('#');
          if (i > -1) { rememberLink(h.slice(i)); }
        }
      });
    }
  } catch (e) {}

  /* Subscribe to the tap IMMEDIATELY — no auth wait, no permission prompt first. This is
     the listener that has to exist before the event arrives. */
  (function wireTapEarly() {
    var tries = 0;
    (function attempt() {
      try {
        var Cap = window.Capacitor;
        if (Cap && typeof Cap.isNativePlatform === 'function' && Cap.isNativePlatform()) {
          var PN = (Cap.Plugins && Cap.Plugins.PushNotifications) || window.PushNotifications;
          if (PN && PN.addListener && !window.__ktTapWired) {
            window.__ktTapWired = true;
            PN.addListener('pushNotificationActionPerformed', function (a) {
              try { rememberLink(a && a.notification && a.notification.data && a.notification.data.link); } catch (e) {}
            });
            return;
          }
        }
      } catch (e) {}
      if (tries++ < 40) { setTimeout(attempt, 250); }   // the plugin can appear late
    })();
  })();

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

          /* AND take over the screen, now, with THIS message's link.

             The app is open and in front of somebody — that is exactly the case the
             in-app takeover exists for. It was only ever raised by a 15-second poll
             comparing unread COUNTS, so a message arriving in the foreground waited up to
             fifteen seconds and then announced itself as "you have N unread messages"
             with an Open button that went to the conversation list. Raised from the push
             itself it can say who it is from and open the actual thread.

             Only for an urgent push (kt_urgent), which is what chat sends — a routine
             notification should not seize the screen. urgentAlert applies its own staff
             and per-device-setting checks, and refuses to stack a second takeover. */
          /* The bell, immediately. The badge polls every 15s and catches up on focus,
             which is fine for a number nobody is watching — but a push IS the arrival,
             and leaving the indicator stale for up to fifteen seconds after the phone
             has already buzzed is what "the bell is not realtime" means. */
          if (window.KT && typeof KT.refreshBell === 'function') { KT.refreshBell(); }

          var urgent = n && n.data && (n.data.kt_urgent === '1' || n.data.kt_urgent === true);
          if (urgent && window.KT && KT.urgentAlert && KT.urgentAlert.fromPush) {
            KT.urgentAlert.fromPush(title, body, link);
          }
        } catch (e) {}
      });

      // Only if the early wiring above never managed to subscribe. Same handler either
      // way, so a tap is remembered rather than assigned straight to location.hash.
      if (!window.__ktTapWired) {
        window.__ktTapWired = true;
        PN.addListener('pushNotificationActionPerformed', function (a) {
          try { rememberLink(a && a.notification && a.notification.data && a.notification.data.link); } catch (e) {}
        });
      }
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
