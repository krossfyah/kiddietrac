/* ═══════════════════════════════════════════════════════════════════
   KiddieTrac — REQUIRED APP NOTIFICATIONS FOR STAFF (2026-10-31)
   + REQUIRED APP PERMISSIONS IN THE PHONE APPS (2026-11-02).

   Anthony: "turn on app notifications for all educators as part of their duties with iLearn". A phone's
   notification permission can only be switched on by its owner, so when the agency requires it
   (Settings → Agency notifications → Required for staff; App\Support\StaffNotifyRequirement) staff in those
   roles get this step until their phone can receive notifications:

     - a phone browser: turns on browser notifications, or explains how to get the app;
     - a computer: never blocks - one gentle reminder a day that it is needed on their phone.

   THE APP (Android / iPhone), 2026-11-02. Anthony: "can you reprompt those that didn't turn on notifications on the
   app for both android and ios? this should be mandatory for the app to work properly including GPS" - block until
   allowed, everyone in the app (staff of every agency and parents). The server says who (GET /me/notify-requirement
   → app_gate; App\Support\DevicePresence::gateFor: not platform admins, website-demo people or service logins; the
   platform switch app.require_permissions turns it off at once). The screen cannot be closed. It lists what is
   missing - Notifications, Location while using the app - with one button each: never asked → the phone's own
   question; refused → the app's page in phone Settings (Android KtBio.openAppSettings; iPhone
   BackgroundGeolocation.openSettings, which opens Settings → KiddieTrac) - and checks again whenever the app
   comes back. Sign out, the phone (dialer) and urgent screens stay reachable: it steps aside while an urgent
   alert, Safe Arrival / headcount / emergency screen, forced update, lock screen or call is showing, and never
   appears before the biometric / PIN unlock. Background ("always") location is never asked for.

   EVERY BROWSER, EVERYONE (2026-11-04). Anthony: "always prompt user to turn on notifications on browser if its not on".
   The browser step is no longer only for staff whose agency requires it, and no longer phones only: anyone signed in
   whose browser can show notifications and has not allowed them gets the step (computer included); blocked shows where
   the switch is; allowed quietly subscribes this browser. "Not now" still means an hour. Required staff keep their wording.

   KT.appPerms (also read by kt-device-perms.js): notif() / loc() → 'granted' | 'denied' | 'prompt' | 'unknown'.
   ═══════════════════════════════════════════════════════════════════ */
(function () {
  'use strict';
  if (window.__ktNotifyGate) return; window.__ktNotifyGate = true;

  var ID = 'kt-notify-gate';
  var SNOOZE_MS = 60 * 60 * 1000;
  var HEAD = 'linear-gradient(135deg,#0B2545 0%,#13315C 45%,#0E7C90 100%)';
  var busyShow = false, lastReq = null, askWaited = false;

  function T(s) { try { return (window.KT && KT.i18n && KT.i18n.t) ? KT.i18n.t(s) : s; } catch (e) { return s; } }
  function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }
  function token() { try { return sessionStorage.getItem('kt_token') || localStorage.getItem('kt_token'); } catch (e) { return null; } }
  function user() { try { return JSON.parse(sessionStorage.getItem('kt_user') || localStorage.getItem('kt_user') || '{}') || {}; } catch (e) { return {}; } }
  function apiBase() { return (window.KT && KT.API_BASE) || 'https://api.kiddietrac.com/api/v1'; }
  function cap() { return window.Capacitor && typeof Capacitor.isNativePlatform === 'function' && Capacitor.isNativePlatform() ? Capacitor : null; }
  function plugins() { var c = cap(); return (c && c.Plugins) || {}; }
  function PN() { var c = cap(); return c && ((c.Plugins && c.Plugins.PushNotifications) || window.PushNotifications); }
  function platform() { var c = cap(); try { return c ? c.getPlatform() : 'web'; } catch (e) { return 'web'; } }
  function isPhoneBrowser() { return !cap() && (window.matchMedia && matchMedia('(max-width: 820px)').matches) && ('ontouchstart' in window || navigator.maxTouchPoints > 0); }
  function isIOSWeb() { return /iPhone|iPad|iPod/.test(navigator.userAgent) && !cap(); }
  function standalone() { return (window.matchMedia && matchMedia('(display-mode: standalone)').matches) || window.navigator.standalone === true; }
  function viewingAs() { try { return !!sessionStorage.getItem('kt_view_as'); } catch (e) { return false; } }
  function snoozeKey() { return 'kt_ngate_snooze_' + (user().id || ''); }
  function snoozed() { try { return Date.now() < Number(localStorage.getItem(snoozeKey()) || 0); } catch (e) { return false; } }
  function lsGet(k) { try { return localStorage.getItem(k); } catch (e) { return null; } }
  function lsSet(k, v) { try { localStorage.setItem(k, v); } catch (e) {} }
  function timeout(p, ms, v) { return Promise.race([p, new Promise(function (r) { setTimeout(function () { r(v); }, ms); })]); }

  function api(path) {
    var h = { Accept: 'application/json', Authorization: 'Bearer ' + token() };
    try { var a = sessionStorage.getItem('kt_active_agency_id'); if (a) h['X-Active-Agency-Id'] = a; } catch (e) {}
    return fetch(apiBase() + path, { headers: h }).then(function (r) { return r.ok ? r.json() : null; }).catch(function () { return null; });
  }

  function norm(v) {
    v = String(v || '');
    if (v === 'granted') return 'granted';
    if (v === 'denied') return 'denied';
    if (v.indexOf('prompt') === 0 || v === 'default') return 'prompt';
    return 'unknown';
  }

  /* ── WHAT THE APP MAY DO (read-only checks; nothing here asks) ── */
  function notif() {
    var pn = PN();
    if (!pn || !pn.checkPermissions) return Promise.resolve('unknown');
    return timeout(pn.checkPermissions().then(function (p) { return norm(p && p.receive); }, function () { return 'unknown'; }), 3000, 'unknown');
  }

  /* iPhone location without asking: a watcher that may not ask. Authorised → a position (cached ones count) and the
     install remembers it (kt_loc_ok); refused → NOT_AUTHORIZED straight away; never asked → silence. Each watcher
     has its own location manager, so Walk Watch's watcher is untouched. */
  function iosLocProbe(request) {
    var BG = plugins().BackgroundGeolocation;
    if (!BG || !BG.addWatcher) return Promise.resolve('unknown');
    return new Promise(function (resolve) {
      var id = null, done = false;
      function fin(v) {
        if (done) return; done = true;
        if (v === 'granted') { lsSet('kt_loc_ok', '1'); lsSet('kt_loc_at', String(Date.now())); }
        if (v === 'denied') lsSet('kt_loc_ok', '0');
        try { if (id && BG.removeWatcher) BG.removeWatcher({ id: id }); } catch (e) {}
        resolve(v);
      }
      try {
        BG.addWatcher({ requestPermissions: !!request, stale: true, distanceFilter: 0 }, function (loc, err) {
          if (err) { fin(err.code === 'NOT_AUTHORIZED' ? 'denied' : 'granted'); return; }   // any other error = authorised, no fix
          if (loc) fin('granted');
        }).then(function (w) { id = w; if (done) { try { BG.removeWatcher({ id: id }); } catch (e) {} } }, function () { fin('unknown'); });
      } catch (e) { fin('unknown'); }
      // Asking waits for the answer (the system question is on screen); a silent check gives up quickly.
      /* Silence: no position and no refusal. Before this install has ever asked, that is "never asked". After it has
         asked, iPhone cannot be undecided (its question has no cancel), so silence is an allowed phone without a fix
         yet (indoors): never block on that. */
      setTimeout(function () { fin(lsGet('kt_loc_ok') === '1' ? 'granted' : (lsGet('kt_loc_asked') === '1' ? 'unknown' : 'prompt')); }, request ? 20000 : 2500);
    });
  }

  var quietProbe = null;
  function loc() {
    var plat = platform(), P = plugins();
    if (plat === 'android' && P.KtBio && P.KtBio.checkPermissions) {
      return timeout(P.KtBio.checkPermissions().then(function (r) { return norm(r && r.location); }, function () { return 'unknown'; }), 3000, 'unknown');
    }
    if (plat === 'ios') {
      // Checked and allowed within the last half hour: no new watcher (each one lights the location arrow briefly).
      if (lsGet('kt_loc_ok') === '1' && Date.now() - Number(lsGet('kt_loc_at') || 0) < 30 * 60 * 1000) return Promise.resolve('granted');
      if (!quietProbe) quietProbe = iosLocProbe(false).then(function (v) { quietProbe = null; return v; });   // one watcher at a time
      return quietProbe;
    }
    if (plat === 'android') return Promise.resolve('unknown');   // a build without KtBio's location check: never block on a guess
    try {
      if (navigator.permissions && navigator.permissions.query) {
        return timeout(navigator.permissions.query({ name: 'geolocation' }).then(function (s) { return norm(s && s.state); }, function () { return 'unknown'; }), 1500, 'unknown');
      }
    } catch (e) {}
    return Promise.resolve('unknown');
  }

  function askNotif() {
    var pn = PN();
    if (!pn || !pn.requestPermissions) return Promise.resolve('unknown');
    return pn.requestPermissions().then(function (r) {
      var v = norm(r && r.receive);
      if (v === 'granted') {
        try { if (window.KT && KT.nativePush && KT.nativePush.armAfterGrant) KT.nativePush.armAfterGrant(); else pn.register(); } catch (e) {}
      }
      return v;
    }, function () { return 'unknown'; });
  }

  function askLoc() {
    var plat = platform(), P = plugins();
    if (plat === 'android' && P.KtBio && P.KtBio.requestLocation) {
      return P.KtBio.requestLocation().then(function (r) {
        if (r && r.granted) return 'granted';
        return P.KtBio.checkPermissions ? P.KtBio.checkPermissions().then(function (s) { var v = norm(s && s.location); return v === 'granted' ? 'granted' : (v === 'prompt' ? 'prompt' : 'denied'); }, function () { return 'denied'; }) : 'denied';
      }, function () { return 'unknown'; });
    }
    if (plat === 'ios') { lsSet('kt_loc_asked', '1'); return iosLocProbe(true); }
    return new Promise(function (resolve) {
      try { navigator.geolocation.getCurrentPosition(function () { resolve('granted'); }, function (e) { resolve(e && e.code === 1 ? 'denied' : 'granted'); }, { timeout: 20000, maximumAge: 600000 }); }
      catch (e) { resolve('unknown'); }
    });
  }

  /* Both plugin calls return a promise. iOS can refuse to open Settings (the plugin rejects "Failed to open
     settings"), which went unhandled and the button did nothing - App Review ticket #226. onFail tells the person
     where to go instead. Returns false when there is nothing to call at all. */
  function openSettings(onFail) {
    var P = plugins(), r = null;
    var fail = function () { if (onFail) { try { onFail(); } catch (x) {} } };
    try {
      if (platform() === 'android' && P.KtBio && P.KtBio.openAppSettings) r = P.KtBio.openAppSettings();
      else if (P.BackgroundGeolocation && P.BackgroundGeolocation.openSettings) r = P.BackgroundGeolocation.openSettings();
      else return false;
    } catch (e) { fail(); return true; }
    if (r && r.then) r.then(null, fail);
    return true;
  }

  /* Calm moment: the home screen is up and nothing else is on top (same rule as the native permission ask). */
  function busy() {
    var sel = '#kt-splash:not(.kt-hide), #kt-launch, #kt-portal-fade, #kt-pin-lock, #kt-bio-lock, #kt-bio-ov, #kt-clockgate, '
      + '#kt-agree, #kt-pw-gate, #kt-mfa-gate, #kt-upd, #kt-appupdate, #kt-update-required, #kt-whatsnew, #kt-urgent, .kt-urgent, #kt-session-timeout, #kt-session-warning, '
      + '[role="dialog"][aria-modal="true"]:not(#' + ID + '), #modalRoot > *';
    var els = document.querySelectorAll(sel);
    for (var i = 0; i < els.length; i++) {
      if ((els[i].offsetWidth > 0 || els[i].offsetHeight > 0) && getComputedStyle(els[i]).opacity !== '0') return true;
    }
    if (/^#(safe-arrival|headcounts?|emergency)/.test(location.hash || '')) return true;
    var m = document.getElementById('appMain');
    return !m || (m.textContent || '').replace(/\s+/g, ' ').trim().length < 60;
  }

  /* What the mandatory app screen steps aside for while it is up: anything urgent or anything that must come first. */
  function yieldNow() {
    var sel = '#kt-urgent, .kt-urgent, #kt-update-required, #kt-upd, #kt-appupdate, #kt-pin-lock, #kt-bio-lock, #kt-session-warning, #kt-session-timeout, '
      + '#kt-mfa-gate, #kt-pw-gate, #kt-agree, #kt-dpop, .kt-dialer-call, #kt-call-screen';
    var els = document.querySelectorAll(sel);
    for (var i = 0; i < els.length; i++) {
      if (els[i].id === ID || els[i].closest('#' + ID)) continue;
      if ((els[i].offsetWidth > 0 || els[i].offsetHeight > 0) && getComputedStyle(els[i]).display !== 'none' && getComputedStyle(els[i]).opacity !== '0') return true;
    }
    return /^#(safe-arrival|headcounts?|emergency)/.test(location.hash || '');
  }

  /* Can this device receive notifications right now? 'ok' | 'ask' | 'denied' | 'web-ask' | 'web-denied' | 'get-app' | 'unsupported' */
  function deviceState(req) {
    var pn = PN();
    if (pn && pn.checkPermissions) {
      return pn.checkPermissions().then(function (p) {
        var r = p && p.receive;
        if (r === 'granted') { try { pn.register(); } catch (e) {} return 'ok'; }
        return r === 'denied' ? 'denied' : 'ask';
      }).catch(function () { return 'ask'; });
    }
    if (isIOSWeb() && !standalone()) return Promise.resolve('get-app');
    if (!('Notification' in window) || !('PushManager' in window) || !('serviceWorker' in navigator)) return Promise.resolve(isPhoneBrowser() ? 'get-app' : 'unsupported');
    if (Notification.permission === 'denied') return Promise.resolve('web-denied');
    if (Notification.permission === 'granted') {
      // Allowed already: make sure this browser is subscribed. No question is asked once allowed.
      try { if (window.KT && KT.Push && KT.Push.subscribe) KT.Push.subscribe(false).catch(function () {}); } catch (e) {}
      return Promise.resolve('ok');
    }
    return Promise.resolve('web-ask');
  }

  function close() { var g = document.getElementById(ID); if (g) g.remove(); document.documentElement.classList.remove('kt-ngate-open'); stopYield(); }

  function styles() {
    return '<style>'
      + '#' + ID + '{position:fixed;inset:0;z-index:2147481000;display:flex;align-items:center;justify-content:center;padding:calc(var(--kt-safe-top,env(safe-area-inset-top,0px)) + 16px) 16px calc(var(--kt-safe-bottom,env(safe-area-inset-bottom,0px)) + 16px);background:rgba(8,20,40,.62);}'
      + '#' + ID + '.kt-ng-full{background:#0B2545;align-items:flex-start;overflow:auto;}'
      + '#' + ID + '.kt-ng-away{display:none!important;}'
      + '#' + ID + ' .kt-ng-card{width:100%;max-width:420px;max-height:100%;overflow:auto;background:#fff;border-radius:18px;box-shadow:0 24px 60px -12px rgba(0,0,0,.45);font-family:inherit;}'
      + '#' + ID + '.kt-ng-full .kt-ng-card{max-height:none;margin:auto;}'
      + '#' + ID + ' .kt-ng-head{background:' + HEAD + ';color:#fff;padding:16px 18px 14px;border-bottom:3px solid #2DD4BF;border-radius:18px 18px 0 0;}'
      + '#' + ID + ' .kt-ng-head h2{margin:0;font-size:18px;font-weight:800;line-height:1.25;color:#fff;}'
      + '#' + ID + ' .kt-ng-head div{font-size:12.5px;opacity:.85;margin-top:3px;}'
      + '#' + ID + ' .kt-ng-body{padding:16px 18px 18px;color:#334155;font-size:14.5px;line-height:1.55;}'
      + '#' + ID + ' .kt-ng-list{margin:10px 0 12px;padding:0;list-style:none;display:grid;gap:6px;}'
      + '#' + ID + ' .kt-ng-list li{display:flex;gap:8px;align-items:center;font-size:13.5px;color:#0F172A;}'
      + '#' + ID + ' .kt-ng-steps{margin:8px 0 12px;padding-left:20px;font-size:13.5px;color:#0F172A;}'
      + '#' + ID + ' .kt-ng-steps li{margin:4px 0;}'
      + '#' + ID + ' .kt-ng-btn{display:flex;width:100%;align-items:center;justify-content:center;gap:8px;min-height:44px;border-radius:12px;border:0;font-weight:800;font-size:15px;cursor:pointer;margin-top:8px;}'
      + '#' + ID + ' .kt-ng-pri{background:#0E7C90;color:#fff;}'
      + '#' + ID + ' .kt-ng-sec{background:#F1F5F9;color:#0F172A;}'
      + '#' + ID + ' .kt-ng-later{background:transparent;color:#64748B;font-weight:700;font-size:13px;min-height:36px;}'
      + '#' + ID + ' .kt-ng-msg{font-size:13px;margin-top:8px;min-height:18px;}'
      + '#' + ID + ' .kt-ng-item{border:1px solid #E2E8F0;border-radius:14px;padding:12px 12px 10px;margin:10px 0 0;}'
      + '#' + ID + ' .kt-ng-item.ok{border-color:#BBF7D0;background:#F0FDF4;}'
      + '#' + ID + ' .kt-ng-row{display:flex;align-items:center;gap:10px;}'
      + '#' + ID + ' .kt-ng-ico{font-size:22px;line-height:1;}'
      + '#' + ID + ' .kt-ng-name{font-weight:800;color:#0F172A;font-size:15px;}'
      + '#' + ID + ' .kt-ng-why{font-size:12.5px;color:#64748B;}'
      + '#' + ID + ' .kt-ng-pill{margin-left:auto;padding:2px 9px;border-radius:999px;font-size:11.5px;font-weight:800;white-space:nowrap;}'
      + '#' + ID + ' .kt-ng-foot{display:flex;gap:8px;margin-top:12px;}'
      + '#' + ID + ' .kt-ng-foot .kt-ng-btn{margin-top:0;font-size:13.5px;min-height:40px;}'
      + '</style>';
  }

  function steps(state) {
    var android = platform() === 'android' || (!cap() && /Android/i.test(navigator.userAgent));
    if (state === 'web-denied') {
      return '<div style="margin:8px 0 12px;font-size:13.5px;color:#0F172A;">This browser has blocked notifications for KiddieTrac. Tap <b>Show me where</b> for the steps on this browser, '
        + 'or use the KiddieTrac app on your phone instead.</div>';
    }
    if (state === 'denied') {
      return android
        ? '<ol class="kt-ng-steps"><li>Tap <b>Open settings</b> below.</li><li>Tap <b>Notifications</b> and switch on <b>All KiddieTrac notifications</b>.</li>'
          + '<li>Make sure <b>Staff reminders</b> and <b>Headcount reminders</b> are on too.</li><li>Come back here — it checks again by itself.</li></ol>'
        : '<ol class="kt-ng-steps"><li>Open the iPhone <b>Settings</b> app.</li><li>Scroll to <b>KiddieTrac</b> → <b>Notifications</b>.</li>'
          + '<li>Turn on <b>Allow Notifications</b> (Sounds and Banners too).</li><li>Come back here and tap <b>Check again</b>.</li></ol>';
    }
    if (state === 'get-app') {
      return '<ol class="kt-ng-steps">' + (/Android/i.test(navigator.userAgent)
        ? '<li>Install <b>KiddieTrac</b> from Google Play (button below) and sign in.</li><li>Tap <b>Allow</b> when it asks about notifications.</li>'
        : (iosStore()
          // The App Store (2026-11-06): KiddieTrac's iPhone app is live - KiddieTrac's own brand only.
          ? '<li>Install <b>KiddieTrac</b> from the App Store (button below) and sign in.</li><li>Tap <b>Allow</b> when it asks about notifications.</li>'
          : '<li>Open the KiddieTrac app on this iPhone and sign in (ask your director for the link if you don\'t have it yet).</li>'
            + '<li>Or in Safari: tap <b>Share</b> → <b>Add to Home Screen</b>, open KiddieTrac from the Home Screen and allow notifications.</li>')) + '</ol>';
    }
    return '';
  }

  /* KiddieTrac's iPhone app on the App Store (2026-11-06). Never offered on a white-labelled page (kt-wl): that brand
     has its own app, and KiddieTrac's would not let its people sign in. */
  var IOS_STORE = 'https://apps.apple.com/app/kiddietrac/id6827700968';
  function iosStore() { return !document.documentElement.classList.contains('kt-wl'); }

  /* The browser / soft step (agency "Required for staff"): unchanged from 2026-10-31. */
  function render(req, state) {
    close();
    var agency = esc(req.agency || 'Your agency');
    var required = !!req.required;
    var here = isPhoneBrowser() || cap() ? T('this phone') : T('this browser');
    var roles = [].concat(user().roles || [], user().primary_role || []);
    var parentOnly = roles.length && !roles.some(function (r) { return r && r !== 'guardian'; });
    var denied = state === 'denied' || state === 'web-denied';
    var getApp = state === 'get-app';
    var g = document.createElement('div');
    g.id = ID;
    g.setAttribute('role', 'dialog'); g.setAttribute('aria-modal', 'true'); g.setAttribute('aria-labelledby', ID + '-t');
    g.setAttribute('data-kt-no-close', '1'); g.setAttribute('data-kt-centre-always', '1');
    g.innerHTML = styles()
      + '<div class="kt-ng-card"><div class="kt-ng-head"><h2 id="' + ID + '-t">🔔 ' + esc(T('Turn on notifications')) + '</h2>'
      + '<div>' + (required ? agency + ' · ' + esc(T('required for your role')) : esc(T('So you never miss a message or an alert'))) + '</div></div>'
      + '<div class="kt-ng-body">'
      + '<div>' + (required ? agency + ' ' + esc(T('requires notifications on your phone so you never miss:')) : esc(T('Turn on notifications on ' + here + ' so you never miss:'))) + '</div>'
      + (parentOnly
        ? '<ul class="kt-ng-list"><li>💬 ' + esc(T('Messages from your centre')) + '</li><li>📢 ' + esc(T('Announcements and closures')) + '</li>'
          + '<li>🧾 ' + esc(T('Invoices and payment reminders')) + '</li></ul>'
        : '<ul class="kt-ng-list"><li>🧮 ' + esc(T('Headcount reminders')) + '</li><li>🛡️ ' + esc(T('Safe Arrival and urgent alerts')) + '</li>'
          + '<li>💬 ' + esc(T('Messages and announcements')) + '</li></ul>')
      + (denied ? '<div><b>' + esc(T('Notifications are switched off for KiddieTrac on ' + here + '.')) + '</b> ' + esc(T('Only you can switch them back on:')) + '</div>' : '')
      + (getApp ? '<div><b>' + esc(T('This browser can\'t receive notifications.')) + '</b> ' + esc(T('Use the KiddieTrac app on your phone:')) + '</div>' : '')
      + steps(state)
      + (state === 'ask' || state === 'web-ask' ? '<button type="button" class="kt-ng-btn kt-ng-pri" data-ng="allow">🔔 ' + esc(T('Turn on notifications')) + '</button>' : '')
      + (state === 'denied' && platform() === 'android' ? '<button type="button" class="kt-ng-btn kt-ng-pri" data-ng="settings">⚙️ ' + esc(T('Open settings')) + '</button>' : '')
      + (state === 'web-denied' && window.KT && KT.Push && KT.Push.helpBlocked ? '<button type="button" class="kt-ng-btn kt-ng-pri" data-ng="webhelp">⚙️ ' + esc(T('Show me where')) + '</button>' : '')
      + (getApp && /Android/i.test(navigator.userAgent) ? '<a class="kt-ng-btn kt-ng-pri" style="text-decoration:none" href="https://play.google.com/store/apps/details?id=com.kiddietrac.app" target="_blank" rel="noopener">▶ ' + esc(T('Get it on Google Play')) + '</a>' : '')
      + (getApp && !/Android/i.test(navigator.userAgent) && iosStore() ? '<a class="kt-ng-btn kt-ng-pri" style="text-decoration:none" href="' + IOS_STORE + '" target="_blank" rel="noopener">📲 ' + esc(T('Download on the App Store')) + '</a>' : '')
      + (denied || getApp ? '<button type="button" class="kt-ng-btn kt-ng-sec" data-ng="check">↻ ' + esc(T('Check again')) + '</button>' : '')
      + '<div class="kt-ng-msg" aria-live="polite"></div>'
      + '<button type="button" class="kt-ng-btn kt-ng-later" data-ng="later">' + esc(T('Not now — remind me in an hour')) + '</button>'
      + '</div></div>';
    document.body.appendChild(g);
    document.documentElement.classList.add('kt-ngate-open');
    var msg = g.querySelector('.kt-ng-msg');
    g.addEventListener('click', function (e) {
      var b = e.target.closest('[data-ng]'); if (!b) return;
      var act = b.getAttribute('data-ng');
      if (act === 'later') { try { localStorage.setItem(snoozeKey(), String(Date.now() + SNOOZE_MS)); } catch (x) {} close(); return; }
      if (act === 'settings') { var nos = function () { msg.style.color = '#B45309'; msg.textContent = T('Open the phone\'s Settings app, then KiddieTrac.'); }; if (!openSettings(nos)) nos(); return; }
      if (act === 'webhelp') { try { KT.Push.helpBlocked(); } catch (x) {} return; }
      if (act === 'check') { msg.style.color = '#64748B'; msg.textContent = T('Checking…'); check(true); return; }
      if (act === 'allow') {
        msg.style.color = '#64748B'; msg.textContent = T('Waiting for your answer…');
        var pn = PN();
        var p = pn ? askNotif().then(function (v) { return v === 'granted'; })
          : (window.KT && KT.Push ? KT.Push.subscribe(true).then(function (r) { return r && r.status === 'subscribed'; }) : Promise.resolve(false));
        p.then(function (ok) {
          if (ok) { done(); } else { check(true); }
        }).catch(function () { check(true); });
      }
    });
  }

  function done() {
    var g = document.getElementById(ID);
    if (g) {
      var body = g.querySelector('.kt-ng-body');
      body.innerHTML = '<div style="text-align:center;padding:10px 0 4px;"><div style="font-size:40px;line-height:1">✅</div>'
        + '<div style="font-weight:800;font-size:16px;color:#0F172A;margin-top:8px;">' + esc(T(g.getAttribute('data-ng-app') ? 'All set' : 'Notifications are on')) + '</div>'
        + '<div style="font-size:13.5px;color:#64748B;margin-top:4px;">' + esc(T(g.getAttribute('data-ng-app') ? 'KiddieTrac can now send you alerts and use your location while the app is open.' : 'You\'ll get alerts and messages on this device.')) + '</div></div>';
      setTimeout(close, 1800);
    }
    try { if (window.KT && KT.devicePerms && KT.devicePerms.report) KT.devicePerms.report(true); } catch (e) {}
  }

  /* ── THE APP SCREEN (mandatory) ── */
  var tried = { notifications: false, location: false };   // asked once this session and still not allowed → Settings
  var yieldTimer = null;
  function stopYield() { if (yieldTimer) { clearInterval(yieldTimer); yieldTimer = null; } }
  function startYield() {
    stopYield();
    var tick = function () {
      var g = document.getElementById(ID); if (!g) { stopYield(); return; }
      g.classList.toggle('kt-ng-away', yieldNow());
    };
    tick();
    yieldTimer = setInterval(tick, 700);
  }

  function item(key, state) {
    var ios = platform() === 'ios';
    var ok = state === 'granted';
    var meta = key === 'notifications'
      ? { ico: '🔔', name: T('Notifications'), why: T('Headcount reminders, Safe Arrival and urgent alerts, messages and announcements.') }
      : { ico: '📍', name: T('Location while using the app'), why: T('Check-in at your centre, walks (Walk Watch) and arrival alerts. Only while KiddieTrac is open.') };
    var settings = state === 'denied' || (tried[key] && !ok);
    var pill = ok ? ['✓ ' + T('Allowed'), '#166534', '#DCFCE7'] : (settings ? [T('Turned off'), '#991B1B', '#FEE2E2'] : [T('Not allowed yet'), '#92400E', '#FEF3C7']);
    var how = '';
    if (!ok && settings) {
      how = ios
        ? '<ol class="kt-ng-steps"><li>' + (key === 'notifications'
            ? 'Tap <b>Open Settings</b> (or open the iPhone <b>Settings</b> app → <b>KiddieTrac</b>).</li><li>Tap <b>Notifications</b> and turn on <b>Allow Notifications</b>.'
            : 'Tap <b>Open Settings</b> (or open the iPhone <b>Settings</b> app → <b>KiddieTrac</b>).</li><li>Tap <b>Location</b> and choose <b>While Using the App</b>.')
          + '</li><li>Come back to KiddieTrac — it checks again by itself.</li></ol>'
        : '<ol class="kt-ng-steps"><li>Tap <b>Open settings</b>.</li><li>' + (key === 'notifications'
            ? 'Tap <b>Notifications</b> and switch on <b>All KiddieTrac notifications</b>.'
            : 'Tap <b>Permissions</b> → <b>Location</b> and choose <b>Allow only while using the app</b>.')
          + '</li><li>Come back to KiddieTrac — it checks again by itself.</li></ol>';
    }
    return '<div class="kt-ng-item' + (ok ? ' ok' : '') + '" data-ng-item="' + key + '">'
      + '<div class="kt-ng-row"><span class="kt-ng-ico" aria-hidden="true">' + meta.ico + '</span><div style="min-width:0"><div class="kt-ng-name">' + esc(meta.name) + '</div>'
      + '<div class="kt-ng-why">' + esc(meta.why) + '</div></div>'
      + '<span class="kt-ng-pill" style="color:' + pill[1] + ';background:' + pill[2] + ';">' + esc(pill[0]) + '</span></div>'
      + how
      + (ok ? '' : (settings
          ? '<button type="button" class="kt-ng-btn kt-ng-pri" data-ng="settings">⚙️ ' + esc(T(ios ? 'Open Settings' : 'Open settings')) + '</button>'
          : '<button type="button" class="kt-ng-btn kt-ng-pri" data-ng="ask-' + key + '">' + meta.ico + ' ' + esc(T(key === 'notifications' ? 'Allow notifications' : 'Allow location')) + '</button>'))
      + '</div>';
  }

  var appState = null, appBusy = false;
  function renderApp(st) {
    appState = st;
    var g = document.getElementById(ID);
    if (!g || !g.getAttribute('data-ng-app')) {
      close();
      g = document.createElement('div');
      g.id = ID; g.className = 'kt-ng-full';
      g.setAttribute('data-ng-app', '1');
      g.setAttribute('role', 'dialog'); g.setAttribute('aria-modal', 'true'); g.setAttribute('aria-labelledby', ID + '-t');
      g.setAttribute('data-kt-no-close', '1'); g.setAttribute('data-kt-keep-open', '1');
      document.body.appendChild(g);
      document.documentElement.classList.add('kt-ngate-open');
      g.addEventListener('click', onAppClick);
      startYield();
    }
    var canCall = !!(window.KT && KT.dialer && KT.dialer.can && KT.dialer.can() && KT.dialer.open);
    g.innerHTML = styles()
      + '<div class="kt-ng-card"><div class="kt-ng-head"><h2 id="' + ID + '-t">📲 ' + esc(T('Allow KiddieTrac to work properly')) + '</h2>'
      + '<div>' + esc(T('Needed on this phone to use the app')) + '</div></div>'
      + '<div class="kt-ng-body">'
      + '<div>' + esc(T('KiddieTrac needs these on this phone. Only you can switch them on:')) + '</div>'
      + item('notifications', st.notifications) + item('location', st.location)
      + '<div class="kt-ng-msg" aria-live="polite"></div>'
      + '<button type="button" class="kt-ng-btn kt-ng-sec" data-ng="recheck">↻ ' + esc(T('Check again')) + '</button>'
      + '<div class="kt-ng-foot">'
      + (canCall ? '<button type="button" class="kt-ng-btn kt-ng-sec" data-ng="phone">📞 ' + esc(T('Phone')) + '</button>' : '')
      + '<button type="button" class="kt-ng-btn kt-ng-later" data-ng="signout">' + esc(T('Sign out')) + '</button>'
      + '</div></div></div>';
  }

  function onAppClick(e) {
    var b = e.target.closest('[data-ng]'); if (!b) return;
    var act = b.getAttribute('data-ng');
    var g = document.getElementById(ID);
    var msg = g && g.querySelector('.kt-ng-msg');
    var say = function (t, c) { if (msg) { msg.style.color = c || '#64748B'; msg.textContent = t; } };
    if (act === 'signout') { try { if (KT.Auth && KT.Auth.logout) { KT.Auth.logout(); return; } } catch (x) {} location.href = '/index.html'; return; }
    if (act === 'phone') { try { KT.dialer.open(); } catch (x) {} return; }
    if (act === 'settings') { var nos = function () { say(T('Open the phone\'s Settings app, then KiddieTrac.'), '#B45309'); }; if (!openSettings(nos)) nos(); return; }
    if (act === 'recheck') { say(T('Checking…')); appCheck(true); return; }
    if (act === 'ask-notifications' || act === 'ask-location') {
      var key = act.slice(4);
      if (appBusy) return; appBusy = true;
      say(T('Waiting for your answer…'));
      (key === 'notifications' ? askNotif() : askLoc()).then(function (v) {
        appBusy = false;
        if (v === 'denied') tried[key] = true;
        if (appState && v !== 'unknown') { appState[key] = v; renderApp(appState); }   // show the answer now; the full check follows
        appCheck(true);
      }, function () { appBusy = false; tried[key] = true; appCheck(true); });
    }
  }

  function appStateNow() {
    return Promise.all([notif(), loc()]).then(function (r) { return { notifications: r[0], location: r[1] }; });
  }

  /* 'unknown' (an old build that cannot say) never blocks: only a state the phone reported. */
  function missing(st) { return (st.notifications !== 'granted' && st.notifications !== 'unknown') || (st.location !== 'granted' && st.location !== 'unknown'); }

  var appChecking = false, appAgain = false, firstAppAt = 0;
  function appCheck(force) {
    if (appChecking) { appAgain = true; return; }   // a check is reading the phone: look again straight after it
    appChecking = true; appAgain = false;
    appStateNow().then(function (st) {
      appChecking = false;
      if (appAgain) { appAgain = false; appCheck(true); return; }
      try { if (window.KT && KT.devicePerms && KT.devicePerms.report) KT.devicePerms.report(false); } catch (e) {}
      var g = document.getElementById(ID);
      if (!missing(st)) { if (g && g.getAttribute('data-ng-app')) done(); else close(); return; }
      if (st.notifications === 'granted') { try { if (window.KT && KT.nativePush && KT.nativePush.armAfterGrant) KT.nativePush.armAfterGrant(); } catch (e) {} }
      if (g && g.getAttribute('data-ng-app')) { renderApp(st); return; }
      // Not up yet: wait for a calm moment (up to 45 s), and give the app's own notification question time to be answered first.
      if (!firstAppAt) firstAppAt = Date.now();
      var waited = Date.now() - firstAppAt;
      if (!force && busy() && waited < 45000) { setTimeout(function () { appCheck(false); }, 3000); return; }
      if (!force && st.notifications === 'prompt' && !askWaited) { askWaited = true; setTimeout(function () { appCheck(false); }, 9000); return; }
      renderApp(st);
    }, function () { appChecking = false; if (appAgain) { appAgain = false; appCheck(true); } });
  }

  function check(force) {
    if (!token() || window.KT_PUBLIC_DEMO || viewingAs()) { close(); return; }
    var native = !!cap() && /^(ios|android)$/.test(platform());
    var appUp = !!(document.getElementById(ID) && document.getElementById(ID).getAttribute('data-ng-app'));
    if (busyShow) return;
    if (!force && !appUp && !native && snoozed()) return;   // "Not now" belongs to the soft browser step; the app screen comes back on every open
    busyShow = true;
    api('/me/notify-requirement').then(function (req) {
      lastReq = req;
      busyShow = false;
      if (native && req && req.app_gate) { appCheck(force); return; }
      if (appUp) close();   // switched off on the server, or no longer required
      if (native && (!req || !req.required)) { close(); return; }
      req = req || {};
      if (!force && snoozed()) return;
      busyShow = true;
      return deviceState(req).then(function (state) {
        busyShow = false;
        if (state === 'ok' || state === 'unsupported') {
          if (document.getElementById(ID)) done();
          if (req.required && !native && !isPhoneBrowser()) desktopReminder(req);   // the agency also wants it on their phone
          return;
        }
        if (!force && busy() && !document.getElementById(ID)) { setTimeout(function () { check(false); }, 4000); return; }
        /* The app asks for permission itself at the first calm moment (kt-native-push.js); give that system prompt
           time to be answered before showing our step on top of it. */
        if (state === 'ask' && cap() && !force && !askWaited) { askWaited = true; setTimeout(function () { check(false); }, 9000); return; }
        render(req, state);
      });
    }).catch(function () { busyShow = false; });
  }

  function desktopReminder(req) {
    var key = 'kt_ngate_desk_' + (user().id || '') + '_' + new Date().toDateString();
    try { if (localStorage.getItem(key)) return; localStorage.setItem(key, '1'); } catch (e) { return; }
    var text = (req.agency || 'Your agency') + ' ' + T('requires KiddieTrac notifications on your phone. Open the app on your phone and allow notifications.');
    if (window.KT && typeof KT.toast === 'function') { try { KT.toast(text, 'info'); return; } catch (e) {} }
  }

  KT_boot();
  function KT_boot() {
    var n = 0;
    (function wait() {
      if (token() && document.getElementById('appMain')) { setTimeout(function () { check(false); }, 2500); return; }
      if (++n < 120) setTimeout(wait, 1000);
    })();
    /* Back from Settings (or anywhere): look again. The app screen re-checks the phone itself; the soft step only if it is up. */
    function back() { if (token()) setTimeout(function () { var g = document.getElementById(ID); check(!!(g && g.getAttribute('data-ng-app')) || !!g); }, 800); }
    document.addEventListener('visibilitychange', function () { if (!document.hidden) back(); });
    try { var App = plugins().App; if (App && App.addListener) App.addListener('resume', back); } catch (e) {}
    setInterval(function () { if (token() && !document.getElementById(ID)) check(false); }, 10 * 60 * 1000);
  }

  window.KT = window.KT || {};
  KT.notifyGate = { check: function () { check(true); }, last: function () { return lastReq; } };
  KT.appPerms = { notif: notif, loc: loc, askNotif: askNotif, askLoc: askLoc, openSettings: openSettings };
})();
