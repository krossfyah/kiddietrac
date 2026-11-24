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

  /* ONE FIRST-RUN QUESTION AT A TIME (2026-11-03, Anthony's iPhone recording of a first sign-in: the biometric offer,
     the phone's "allow notifications" alert and What's new came back to back and on top of each other). Each of the
     three ran on its own clock. They now take turns: KT.promptTurn(name) resolves when nothing else is asking and a
     breather (1.5 s) has passed since the last one closed; KT.promptDone(name) frees the turn. When several are
     waiting, the order is biometric, then notifications, then What's new. Nothing is dropped - a question that is
     waiting keeps its place. While one is up, html.kt-prompt-up puts the toast stack under it (kt-toasts.js).
     Installed by whichever of kt-biometric.js, kt-native-push.js and kt-whatsnew.js loads first. */
  (function (w) {
    w.KT = w.KT || {};
    if (w.KT.promptTurn) return;
    var ORDER = { biometric: 1, push: 2, whatsnew: 3 }, GAP = 1500;
    var active = null, freeAt = 0, waiting = [];
    /* WHAT MOVES THE SCREEN RIGHT AFTER A QUESTION CLOSES (2026-11-03). On Anthony's iPhone, Learning Observations opened
       by itself ~2 s after "Not now" on the biometric offer, and the screen went back to Home when What's new closed;
       neither happens in a desktop or WebKit test. A screen change in the 6 s after one of these questions closes is
       reported once (diag/scroll, "PROMPTNAV"): from/to, how long after which question, and the last click (trusted or
       synthetic, where, on what). Diagnostic only - nothing is changed for the person. */
    var closedAt = 0, closedName = '', lastTap = null, told = 0;
    try {
      document.addEventListener('click', function (e) {
        var t = e.target, el = '';
        try { el = ((t.closest && t.closest('a,button,[data-hash],[role=button]')) || t).outerHTML.slice(0, 140); } catch (x) {}
        lastTap = { ago: Date.now(), trusted: !!e.isTrusted, x: Math.round(e.clientX || 0), y: Math.round(e.clientY || 0), el: el };
      }, true);
      w.addEventListener('hashchange', function (e) {
        if (!closedAt || Date.now() - closedAt > 6000 || told >= 3) return;
        told++;
        var tap = lastTap ? { trusted: lastTap.trusted, x: lastTap.x, y: lastTap.y, el: lastTap.el, msBefore: Date.now() - lastTap.ago } : null;
        var tok = ''; try { tok = sessionStorage.getItem('kt_token') || ''; } catch (x) {}
        fetch('https://api.kiddietrac.com/api/v1/diag/scroll', { method: 'POST', keepalive: true,
          headers: { 'Content-Type': 'application/json', Accept: 'application/json', Authorization: tok ? 'Bearer ' + tok : '' },
          body: JSON.stringify({ data: ('PROMPTNAV ' + String(e.oldURL || '').split('#')[1] + ' -> ' + location.hash + ' ' + (Date.now() - closedAt) + 'ms after ' + closedName
            + ' closed; vis=' + document.visibilityState + ' focus=' + document.hasFocus() + ' last click ' + JSON.stringify(tap)).slice(0, 600) }) }).catch(function () {});
      });
    } catch (e) {}
    function up(on) { try { document.documentElement.classList.toggle('kt-prompt-up', on); } catch (e) {} }
    function pump() {
      if (active || !waiting.length) return;
      var wait = freeAt + GAP - Date.now();
      if (wait > 0) { setTimeout(pump, wait + 20); return; }
      waiting.sort(function (a, b) { return (a.o - b.o) || (a.t - b.t); });
      var n = waiting.shift();
      active = n.name; up(true);
      n.go();
    }
    w.KT.promptTurn = function (name) {
      return new Promise(function (res) {
        waiting.push({ name: name, o: ORDER[name] || 9, t: Date.now(), go: res });
        setTimeout(pump, 0);
      });
    };
    w.KT.promptDone = function (name) {
      if (active !== name) return;
      active = null; freeAt = closedAt = Date.now(); closedName = name; up(false);
      setTimeout(pump, GAP + 20);
    };
  })(window);

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

  /* Links that name no screen (2026-10-22 sweep). Staff broadcasts pointed at #team-chat,
     which nothing answers, so the tap opened the home screen; staff messages are #chat.
     Old notifications still carry it, from the phone and from the bell alike. */
  /* 2026-11-04 sweep: the immunization reminder pointed parents at #my-children and the attendance reminder pointed
     educators at #care; neither screen exists (they are #immunizations and #today). */
  var ALIAS = { 'team-chat': 'chat', 'my-children': 'immunizations', 'care': 'today' };
  function aliased(link) {
    var m = /^#?([a-z0-9-]+)(.*)$/.exec(String(link || ''));
    return (m && ALIAS[m[1]]) ? '#' + ALIAS[m[1]] + m[2] : link;
  }
  window.addEventListener('hashchange', function () {
    var to = aliased(location.hash);
    if (to && to !== location.hash) { try { history.replaceState(null, '', to); } catch (e) {} if (window.KT && KT.Shell) { try { KT.Shell.renderScreen(); } catch (e) {} } }
  });

  /* WHERE DID THE TAP GO (2026-10-22). Anthony tapped a website-chat notification and did
     not land in the chat, and nothing recorded what happened. Each tap now reports the link
     it carried and the screen showing a few seconds after it was applied, as push.tapped
     in the audit log. Just the hash: no message content. */
  function reportTap(link, startedAt) {
    setTimeout(function () {
      try {
        var landed = location.hash || '(none)';
        var want = String(link).split('?')[0], got = landed.split('?')[0];
        var ok = want === got;
        var h = { 'Content-Type': 'application/json', 'Accept': 'application/json' };
        var t = token(); if (t) { h.Authorization = 'Bearer ' + t; }
        var aid = null; try { aid = sessionStorage.getItem('kt_active_agency_id'); } catch (e) {}
        if (aid) { h['X-Active-Agency-Id'] = aid; }
        fetch(apiBase() + '/diag/client-error', { method: 'POST', headers: h, keepalive: true,
          body: JSON.stringify({ status: 0, kind: 'push_tap', method: 'TAP', path: String(link).slice(0, 200),
            ms: Math.max(0, Date.now() - startedAt),
            text: (ok ? 'Notification tap opened ' : 'Notification tap for ' + want + ' ended on ') + got }) }).catch(function () {});
      } catch (e) {}
    }, 4000);
  }

  function rememberLink(link) {
    if (!link) { return; }
    link = aliased(link);
    /* Reported on ARRIVAL too (2026-11-01): reportTap() only fires once a link is applied,
       so a tap lost before that (sign-in, Face ID, a redirect) left no trace at all. */
    try {
      var h0 = { 'Content-Type': 'application/json', 'Accept': 'application/json' };
      var t0 = token(); if (t0) { h0.Authorization = 'Bearer ' + t0; }
      fetch(apiBase() + '/diag/client-error', { method: 'POST', headers: h0, keepalive: true,
        body: JSON.stringify({ status: 0, kind: 'push_tap', method: 'TAP', path: String(link).slice(0, 200), ms: 0,
          text: 'Notification tap received on ' + location.pathname + (t0 ? ' (signed in)' : ' (signed out)') }) }).catch(function () {});
    } catch (e) {}
    try { sessionStorage.setItem(PENDING, link); sessionStorage.setItem(PENDING + '_at', String(Date.now())); } catch (e) {}
    routePending();
  }

  function shellReady() {
    return !!document.getElementById('appMain') && !!(window.KT && KT.Shell && KT.Shell.renderScreen);   // the real shell, not the <head> placeholder
  }

  function routePending() {
    var link;
    try { link = sessionStorage.getItem(PENDING); } catch (e) { return; }
    if (!link) { return; }
    if (!token() || !shellReady()) { return; }      // try again on the next tick
    var tappedAt = Date.now();
    /* A tap from long ago (sign-in abandoned, app reopened hours later) no longer says where
       the reader wants to be. Half an hour covers any sign-in, reset code or Face ID retry. */
    try {
      var at0 = Number(sessionStorage.getItem(PENDING + '_at')) || 0;
      if (at0 && Date.now() - at0 > 30 * 60 * 1000) { sessionStorage.removeItem(PENDING); sessionStorage.removeItem(PENDING + '_at'); return; }
    } catch (e) {}
    try { tappedAt = Number(sessionStorage.getItem(PENDING + '_at')) || tappedAt; sessionStorage.removeItem(PENDING); sessionStorage.removeItem(PENDING + '_at'); } catch (e) {}
    link = aliased(link);

    /* A guardian's Messenger is #messages, not #chat — #chat renders another screen
       entirely for them. The thread id is kept for the day that screen learns to open
       one; landing on their inbox is already right, where the newest message is on top. */
    try {
      var u = JSON.parse(sessionStorage.getItem('kt_user') || localStorage.getItem('kt_user') || '{}');
      var roles = [].concat(u.roles || [], u.primary_role || []);
      var staff = roles.some(function (r) { return r && r !== 'guardian'; });
      /* Only a KNOWN guardian-only account is sent to #messages. With no roles stored yet (a
         cold start before /auth/me, a Face ID unlock) staff were being treated as parents and the
         tap ended on the home screen (2026-11-01). */
      if (roles.length && !staff && link.indexOf('#chat') === 0) { link = '#messages'; }
    } catch (e) {}

    if (location.hash === link) {
      // Already there: the hash will not fire, so ask for the repaint directly.
      try { KT.Shell.renderScreen(); } catch (e) {}
    } else {
      location.hash = link;
    }
    reportTap(link, tappedAt);
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

  /* THE ANDROID APP'S OWN TAP (2026-11-04, Anthony: a chat notification signed him in and left him on the Message
     Centre list, not the chat). From build 55 the app posts its notifications itself (per-category tones), so a tap
     never reaches pushNotificationActionPerformed above: MainActivity.handleDeepLink puts the link in
     localStorage.kt_pending_link, sets location.hash AND at once dispatches a synthetic hashchange. The synthetic event
     routed first - Message Centre opened the thread and trimmed the address to #chat - and the browser's own hashchange
     for the same change arrived ~25 ms later, read #chat and drew the list over the thread. It also never reported the
     tap, which is why no push.tapped row ever came from an Android phone.

     So, before any other listener (capture at the target runs first): a synthetic hashchange while a real one is still
     on its way (the address moved since the last route) is held back. If no real one follows in 150 ms it is let
     through, so a repaint asked for by a screen still works. A link the app left in localStorage is reported like any
     other tap, and kept through sign-in when the tap woke a signed-out phone. Needs no new app build. */
  (function nativeTapGuard() {
    var routed = location.hash, realAt = 0, pass = false;
    function adopt() {
      var link = null;
      try { link = localStorage.getItem(PENDING); if (link) { localStorage.removeItem(PENDING); } } catch (e) {}
      if (!link || link !== location.hash) { return; }   // only the app's own, just set: older leftovers are dropped
      if (token() && shellReady()) {
        // The address is already the link and the real hashchange routes it: record where it lands.
        try {
          var h0 = { 'Content-Type': 'application/json', 'Accept': 'application/json', 'Authorization': 'Bearer ' + token() };
          var aid = null; try { aid = sessionStorage.getItem('kt_active_agency_id'); } catch (e) {}
          if (aid) { h0['X-Active-Agency-Id'] = aid; }
          fetch(apiBase() + '/diag/client-error', { method: 'POST', headers: h0, keepalive: true,
            body: JSON.stringify({ status: 0, kind: 'push_tap', method: 'TAP', path: String(link).slice(0, 200), ms: 0,
              text: 'Notification tap received on ' + location.pathname + ' (signed in, app)' }) }).catch(function () {});
        } catch (e) {}
        reportTap(link, Date.now());
      } else {
        rememberLink(link);   // signed out, locked or still booting: applied once the shell is up
      }
    }
    window.addEventListener('hashchange', function (e) {
      if (pass) { routed = location.hash; return; }
      if (e.isTrusted) { routed = location.hash; realAt = Date.now(); return; }
      var waiting = location.hash !== routed;
      try { if (localStorage.getItem(PENDING)) { adopt(); } } catch (x) {}
      if (!waiting) { routed = location.hash; return; }
      e.stopImmediatePropagation();
      var heldAt = Date.now();
      setTimeout(function () {
        if (realAt >= heldAt) { return; }   // the real one came and routed it
        pass = true;
        try { window.dispatchEvent(new HashChangeEvent('hashchange')); } catch (x) {}
        pass = false;
      }, 150);
    }, true);
  })();

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

  /* THE PHONE THAT STOPPED HEARING FROM US (audit 2026-10-01).

     A Safe Arrival alert failed to two educators' phones: FCM had expired their tokens
     (404 → pruned), and nothing ever registered a new one. Two reasons:

       1. The 'registration' listener was added AFTER `await PN.register()`. Capacitor
          fires that event as soon as FCM answers, which on a warm device is before the
          await returns — so the token went to nobody and /push/device was never called.
       2. Registration only ran on a fresh page load. The app mostly RESUMES, so a token
          that rotated (app update, data cleared, FCM housekeeping) stayed unknown to the
          server until the user happened to swipe the app away.

     Now the listeners exist before register(), the token is remembered, and it is posted
     again whenever the signed-in account changes or a resume finds it stale. */
  var LAST_POST = 'kt_push_posted';           // "<user id>|<fcm token>|<ms>"
  var REPOST_MS = 6 * 3600 * 1000;
  var fcmToken = null;
  var inited = false;

  function userId() {
    try { return String(JSON.parse(sessionStorage.getItem('kt_user') || localStorage.getItem('kt_user') || '{}').id || ''); }
    catch (e) { return ''; }
  }

  /* The app's versionCode (2026-10-21). Builds from 55 have a notification channel per
     category, tone and vibration; the server sends per-category tones only to those
     (App\Support\NotificationSounds::MIN_BUILD), so an older install keeps its chime. */
  var appBuild = null;
  try {
    var _App = window.Capacitor && window.Capacitor.Plugins && window.Capacitor.Plugins.App;
    if (_App && _App.getInfo) {
      _App.getInfo().then(function (i) { var b = parseInt(i && i.build, 10); if (b > 0) { appBuild = b; window.KT_APP_BUILD = b; } }).catch(function () {});
    }
  } catch (e) {}

  function postDevice(force) {
    var tok = token();
    if (!tok || !fcmToken) return;
    // The build is part of the key, so updating the app re-registers straight away.
    var key = userId() + '|' + fcmToken + '#' + (appBuild || '');
    try {
      var prev = localStorage.getItem(LAST_POST) || '';
      var cut = prev.lastIndexOf('|');
      if (!force && cut > 0 && prev.slice(0, cut) === key && Date.now() - Number(prev.slice(cut + 1)) < REPOST_MS) return;
    } catch (e) {}
    fetch(apiBase() + '/push/device', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'Authorization': 'Bearer ' + tok },
      body: JSON.stringify(appBuild ? { platform: platform(), token: fcmToken, app_build: appBuild } : { platform: platform(), token: fcmToken }),
    }).then(function (r) {
      if (r && r.ok) { try { localStorage.setItem(LAST_POST, key + '|' + Date.now()); } catch (e) {} }
    }).catch(function () {});
  }

  /* The home screen is up: no boot splash / launch scene / fade, no dialog or lock, and #appMain has content.
     Then two more seconds of nothing new. Gives up waiting after 90s and asks anyway. */
  function calmMoment() {
    return new Promise(function (resolve) {
      var start = Date.now(), quietSince = 0;
      function busy() {
        var sel = '#kt-splash:not(.kt-hide), #kt-launch, #kt-portal-fade, #kt-pin-lock, #kt-bio-lock, #kt-bio-ov, #kt-clockgate, '
          + '#kt-agree, #kt-pw-gate, #kt-mfa-gate, #kt-upd, #kt-appupdate, #kt-whatsnew, [role="dialog"][aria-modal="true"], #modalRoot > *';
        var els = document.querySelectorAll(sel);
        for (var i = 0; i < els.length; i++) {
          if ((els[i].offsetWidth > 0 || els[i].offsetHeight > 0) && getComputedStyle(els[i]).opacity !== '0') return true;
        }
        var m = document.getElementById('appMain');
        return !m || (m.textContent || '').replace(/\s+/g, ' ').trim().length < 60;
      }
      (function tick() {
        if (Date.now() - start > 90000) { resolve(); return; }
        if (busy()) { quietSince = 0; } else if (!quietSince) { quietSince = Date.now(); }
        if (quietSince && Date.now() - quietSince >= 2000) { resolve(); return; }
        setTimeout(tick, 500);
      })();
    });
  }

  async function init() {
    var Cap = window.Capacitor;
    if (!Cap || typeof Cap.isNativePlatform !== 'function' || !Cap.isNativePlatform()) return; // native only
    var PN = (Cap.Plugins && Cap.Plugins.PushNotifications) || window.PushNotifications;
    if (!PN) return; // plugin not in this build yet — no-op
    if (inited) return;
    inited = true;

    // BEFORE register(): the event can fire before the await returns.
    PN.addListener('registration', function (t) {
      if (!t || !t.value) return;
      var changed = t.value !== fcmToken;
      fcmToken = t.value;
      postDevice(changed);
    });
    PN.addListener('registrationError', function (err) {
      if (window.console) console.warn('push registration failed', err);
    });

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
      /* NOT MID-BOOT (2026-10-30, Anthony's iPhone recording: after "Signing in…" a white screen, then the
         "Would like to send you notifications" alert, then the splash, then What's new). Already decided -> no
         prompt at all, carry on. Still undecided -> ask only once the home screen is showing and nothing else is
         on top of it, so the question arrives in a calm moment and not over a loading screen. */
      var cur = null;
      try { cur = PN.checkPermissions ? await PN.checkPermissions() : null; } catch (e) { cur = null; }
      if (cur && cur.receive === 'denied') { reportPerms(); return; }   // kt-notify-gate.js shows where to switch it on; armAfterGrant() when they do
      if (window.KT_PUBLIC_DEMO) return;   // website demo person: never ask for notification permission (2026-10-31)
      var perm;
      if (!cur || cur.receive !== 'granted') {
        // The phone's own question: a calm home screen, then its turn among the first-run questions (KT.promptTurn).
        await calmMoment();
        await window.KT.promptTurn('push');
        try { perm = await PN.requestPermissions(); } finally { window.KT.promptDone('push'); }
      } else {
        perm = await PN.requestPermissions();   // already allowed: no alert, nothing to queue
      }
      reportPerms();   // the answer reaches the readiness list now, not 8 s later (2026-11-02)
      if (perm && perm.receive !== 'granted') return;
      await armAfterGrant();
    } catch (e) { if (window.console) console.warn('native push init failed', e); }
  }

  function reportPerms() { try { if (window.KT && KT.devicePerms && KT.devicePerms.report) KT.devicePerms.report(true); } catch (e) {} }

  /* Everything that needs the permission: register for the push address, show pushes that arrive while the app is open.
     Once per page. Also called by kt-notify-gate.js when someone allows notifications on its screen or in Settings
     (2026-11-02): before, a phone that said no at first and was switched on later never got the in-app listener until
     the app was swiped away and opened again. */
  var armed = false;
  async function armAfterGrant() {
    var Cap = window.Capacitor;
    var PN = Cap && ((Cap.Plugins && Cap.Plugins.PushNotifications) || window.PushNotifications);
    if (!PN) return;
    if (!inited) { init(); return; }
    if (armed) { try { await PN.register(); } catch (e) {} return; }
    armed = true;
    try {
      await PN.register();

      // FOREGROUND: when the app is open, Android delivers the push to the app
      // instead of showing it in the bar — so post a local system notification
      // ourselves (via the native KtBio.notify bridge) so it ALWAYS appears in the
      // Android notification drawer with sound + vibration.
      PN.addListener('pushNotificationReceived', function (n) {
        try {
          // Per-category pushes are data-only: the title and body travel in data.
          var title = (n && n.title) || (n && n.data && n.data.title) || 'KiddieTrac';
          var body = (n && n.body) || (n && n.data && n.data.body) || '';
          var link = (n && n.data && n.data.link) || '';
          var KtBio = Cap.Plugins && Cap.Plugins.KtBio;
          // kt_category = the app (build 55+) already posted it with the chosen tone.
          var nativeShown = n && n.data && n.data.kt_category;
          // Either way the phone has just rung for this: keep the in-app tone (kt-sounds)
          // quiet for that category, or one message would sound twice.
          try { if (window.KT && KT.sounds) { KT.sounds.noteNative((n && n.data && n.data.kt_category) || null); } } catch (e) {}
          if (!nativeShown && KtBio && KtBio.notify) { KtBio.notify({ title: title, body: body, link: link }); }

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
            KT.urgentAlert.fromPush(title, body, link, n.data);
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

  window.KT = window.KT || {};
  KT.nativePush = { armAfterGrant: function () { return armAfterGrant(); } };

  // Register once the auth token exists (retry briefly after boot).
  var tries = 0;
  (function waitAuth() {
    if (token()) { init(); return; }
    if (tries++ > 20) return;
    setTimeout(waitAuth, 1500);
  })();

  /* On every resume: start if boot gave up waiting for a sign-in, otherwise ask FCM for
     the current token again. register() is idempotent — it re-emits 'registration' with
     the live token, and postDevice() only calls the server when the token or the account
     changed, or the last post is older than six hours. */
  function onResume() {
    if (!token()) return;
    if (!inited) { init(); return; }
    try {
      var Cap = window.Capacitor;
      var PN = Cap && ((Cap.Plugins && Cap.Plugins.PushNotifications) || window.PushNotifications);
      if (PN && PN.checkPermissions) {
        PN.checkPermissions().then(function (p) {
          if (p && p.receive === 'granted') { armAfterGrant(); }
        }).catch(function () {});
      }
    } catch (e) {}
  }
  document.addEventListener('visibilitychange', function () { if (!document.hidden) onResume(); });
  (function wireAppState() {
    var n = 0;
    (function attempt() {
      try {
        var App = window.Capacitor && window.Capacitor.Plugins && window.Capacitor.Plugins.App;
        if (App && App.addListener) {
          App.addListener('appStateChange', function (s) { if (s && s.isActive) onResume(); });
          return;
        }
      } catch (e) {}
      if (n++ < 40) setTimeout(attempt, 250);
    })();
  })();

  /* EMAIL LINKS OPEN THE APP (2026-11-05, Anthony: "if emails have open kiddie track as a button but the mobile phone has
     the app installed ... redirect that to the app installed vs a browser" / "but fall back if the app isnt installed to
     the browser"). Android App Links (/.well-known/assetlinks.json, app 3.12.19+) and iOS Universal Links hand
     https://app.kiddietrac.com/, /index.html and /dashboard.html to the app; with no app (or an older one) the phone opens
     the browser as it always did. The link reaches this page as appUrlOpen (app already running) or as the launch URL
     (cold start). Its #screen goes through rememberLink, so a signed-out phone signs in first and then lands on it.
     Another page of the portal (none is claimed today) is loaded as it is. Needs no new build for the routing itself. */
  (function wireAppLinks() {
    var LINK = /^https:\/\/app\.kiddietrac\.com(\/[^?#]*)?(\?[^#]*)?(#.*)?$/i;
    var DONE = 'kt_applink_done';
    function openLink(url, fromLaunch) {
      url = String(url || '');
      var m = LINK.exec(url);
      if (!m) { return; }                                   // com.kiddietrac.app://auth and the rest: not ours
      if (fromLaunch) {
        // The launch URL is reported again on every page load (sign-in, reload): act on it once.
        try { if (sessionStorage.getItem(DONE) === url) { return; } sessionStorage.setItem(DONE, url); } catch (e) {}
      }
      var path = m[1] || '/', hash = m[3] || '';
      if (/^\/(index\.html|dashboard\.html)?$/i.test(path)) {
        if (hash.indexOf('kt_sso=') !== -1 || hash.indexOf('__demo=') !== -1) { location.href = url; return; }
        if (hash.length > 1) { rememberLink(hash); }
        return;                                             // no #screen: the app opening is the whole job
      }
      location.href = url;
    }
    var n = 0;
    (function attempt() {
      try {
        var Cap = window.Capacitor;
        var App = Cap && Cap.isNativePlatform && Cap.isNativePlatform() && Cap.Plugins && Cap.Plugins.App;
        if (App && App.addListener) {
          App.addListener('appUrlOpen', function (ev) { openLink(ev && ev.url, false); });
          if (App.getLaunchUrl) { App.getLaunchUrl().then(function (r) { openLink(r && r.url, true); }).catch(function () {}); }
          return;
        }
      } catch (e) {}
      if (n++ < 40) setTimeout(attempt, 250);
    })();
  })();
})();
