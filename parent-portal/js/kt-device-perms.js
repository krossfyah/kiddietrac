/* ═══════════════════════════════════════════════════════════════════
   KiddieTrac — WHAT THIS DEVICE LETS THE APP DO (2026-10-31).

   Anthony: "and the type of permissions set to use the KiddieTrac app". Reads, never asks: notifications,
   location, camera, microphone, photos and biometric unlock, through whatever the installed build exposes
   (PushNotifications / KtBio / barcode-scanner plugins) or the browser's Permissions API, and reports the
   statuses to POST /me/device-permissions for the staff "Who can receive notifications" list. On start and
   when the app comes back to the front, at most once an hour unless something changed. Statuses only.

   2026-11-02 (Sukhman's iPhone, invisible on the list): the app also sends a per-install id and its build, so the
   server keeps the phone (device_presence) even before it has a push address; the first report goes ~2 s after
   sign-in instead of 8 s (she left the app about six seconds after the home screen appeared), and one more goes
   with keepalive as the app leaves the screen if none has. The iPhone's location comes from the app's own
   location manager (KT.appPerms, kt-notify-gate.js), not the WebView's Permissions API.
   ═══════════════════════════════════════════════════════════════════ */
(function (w) {
  'use strict';
  var KT = w.KT || (w.KT = {});
  if (KT.devicePerms) return;
  var KEY = 'kt_perms_sent';
  var HOUR = 3600000;

  function cap() { return w.Capacitor && w.Capacitor.Plugins ? w.Capacitor.Plugins : null; }
  function platform() {
    try { var p = w.Capacitor && w.Capacitor.getPlatform && w.Capacitor.getPlatform(); if (p === 'android' || p === 'ios') return p; } catch (e) {}
    return 'web';
  }
  function norm(v) {
    v = String(v || '');
    if (v === 'granted') return 'granted';
    if (v === 'denied') return 'denied';
    if (v === 'limited') return 'limited';
    if (v.indexOf('prompt') === 0 || v === 'default') return 'prompt';
    return 'unknown';
  }
  function timeout(p, ms) { return Promise.race([p, new Promise(function (r) { setTimeout(function () { r(null); }, ms); })]); }
  function query(name) {
    try {
      if (!navigator.permissions || !navigator.permissions.query) return Promise.resolve('unknown');
      return timeout(navigator.permissions.query({ name: name }).then(function (s) { return norm(s && s.state); }).catch(function () { return 'unknown'; }), 1500)
        .then(function (v) { return v || 'unknown'; });
    } catch (e) { return Promise.resolve('unknown'); }
  }
  function get(k) { try { return localStorage.getItem(k); } catch (e) { return null; } }
  function tok() { return get('kt_token') || (function () { try { return sessionStorage.getItem('kt_token'); } catch (e) { return null; } })(); }
  function appPerms() { return (w.KT && w.KT.appPerms) || null; }

  async function read() {
    var plat = platform(), P = cap() || {}, out = {};
    // Notifications
    try {
      if (plat !== 'web' && P.PushNotifications && P.PushNotifications.checkPermissions) {
        var n = await timeout(P.PushNotifications.checkPermissions(), 2000);
        out.notifications = n ? norm(n.receive) : 'unknown';
      } else if ('Notification' in w) {
        out.notifications = norm(w.Notification.permission);
      } else out.notifications = 'unavailable';
    } catch (e) { out.notifications = 'unknown'; }
    // Location + microphone: the app's own plugin on Android knows exactly; the iPhone app asks its own location
    // manager (KT.appPerms); a browser asks the browser.
    var kb = P.KtBio || null;
    try {
      var ap = appPerms();
      if (plat === 'ios' && ap && ap.loc) {
        out.location = (await timeout(ap.loc(), 3000)) || 'unknown';
      } else if (plat === 'android' && kb && kb.checkPermissions) {
        var kp = await timeout(kb.checkPermissions(), 2000);
        out.location = kp ? norm(kp.location) : 'unknown';
      } else out.location = await query('geolocation');
    } catch (e) { out.location = 'unknown'; }
    try {
      if (plat === 'android' && kb && kb.micState) {
        var ms = await timeout(kb.micState(), 2000);
        out.microphone = ms ? norm(ms.state) : 'unknown';
      } else out.microphone = await query('microphone');
    } catch (e) { out.microphone = 'unknown'; }
    // Camera: the native scanner plugin when it answers, else the browser.
    try {
      var bs = P.CapacitorBarcodeScanner || null;
      if (plat !== 'web' && bs && bs.checkPermissions) {
        var cp = await timeout(bs.checkPermissions(), 2000);
        out.camera = cp && cp.camera ? norm(cp.camera) : await query('camera');
      } else out.camera = await query('camera');
    } catch (e) { out.camera = 'unknown'; }
    // Photos: Android 13+ and iPhone use the system photo picker, which needs no standing permission.
    out.photos = plat === 'web' ? 'unknown' : 'granted';
    // Biometric unlock: is it possible on this device, and has this person turned it on in KiddieTrac?
    try {
      var avail = null;
      if (plat !== 'web' && kb && kb.available) { var a = await timeout(kb.available(), 2000); avail = a ? !!a.available : null; }
      else if (w.PublicKeyCredential && PublicKeyCredential.isUserVerifyingPlatformAuthenticatorAvailable) {
        avail = await timeout(PublicKeyCredential.isUserVerifyingPlatformAuthenticatorAvailable(), 1500);
      }
      out.biometric = avail === null ? 'unknown' : (avail ? 'yes' : 'unavailable');
      out.biometric_enrolled = get('kt_biometric_enabled') === '1' ? 'yes' : (avail === false ? 'unavailable' : 'no');
    } catch (e) { out.biometric = 'unknown'; }
    return { platform: plat, perms: out };
  }

  function fcmToken() {
    var v = get('kt_push_posted') || '';   // "<user id>|<fcm token>#<build>|<ms>" (kt-native-push.js)
    var i = v.indexOf('|'), j = v.lastIndexOf('|');
    if (i < 0 || j <= i) return null;
    var t = v.slice(i + 1, j);
    var h = t.indexOf('#');
    return (h >= 0 ? t.slice(0, h) : t) || null;
  }

  /* A random id per app install, kept in the app's own storage (a reinstall is a new install). Browsers send none. */
  function installId() {
    var v = get('kt_install_id');
    if (!v || v.length < 16) {
      v = '';
      try { var a = new Uint8Array(16); (w.crypto || w.msCrypto).getRandomValues(a); for (var i = 0; i < a.length; i++) v += ('0' + a[i].toString(16)).slice(-2); }
      catch (e) { v = (Date.now().toString(16) + Math.random().toString(16).slice(2) + Math.random().toString(16).slice(2)).slice(0, 32); }
      try { localStorage.setItem('kt_install_id', v); } catch (e) {}
    }
    return v;
  }
  var appBuild = null;
  try {
    var _A = cap() && cap().App;
    if (_A && _A.getInfo) _A.getInfo().then(function (i) { var b = parseInt(i && i.build, 10); if (b > 0) appBuild = b; }).catch(function () {});
  } catch (e) {}

  function send(body, keep) {
    var t = tok();
    if (!t) return Promise.reject(new Error('signed out'));
    var h = { 'Content-Type': 'application/json', 'Accept': 'application/json', 'Authorization': 'Bearer ' + t };
    try { var a = sessionStorage.getItem('kt_active_agency_id'); if (a) h['X-Active-Agency-Id'] = a; } catch (e) {}
    // keepalive: a report sent as the app goes to the background still arrives.
    return fetch((KT.API_BASE || (w.KT_CONFIG && w.KT_CONFIG.apiBase) || 'https://api.kiddietrac.com/api/v1') + '/me/device-permissions',
      { method: 'POST', headers: h, body: JSON.stringify(body), keepalive: !!keep })
      .then(function (r) { if (!r.ok) throw new Error('HTTP ' + r.status); return r; });
  }

  var busy = false, sent = false;
  async function report(force, keep) {
    if (busy) return;
    if (w.KT_PUBLIC_DEMO) return;   // website demo phones: nothing to report
    if (!tok()) return;
    busy = true;
    try {
      var r = await read();
      var uid = '';
      try { uid = String((JSON.parse(get('kt_user') || sessionStorage.getItem('kt_user') || '{}') || {}).id || ''); } catch (e) {}
      var ft = r.platform !== 'web' ? fcmToken() : null;
      var sig = uid + '|' + r.platform + '|' + JSON.stringify(r.perms) + '|' + (ft ? ft.slice(-12) : '') + '|' + (appBuild || '');
      var prev = get(KEY) || '';
      var cut = prev.lastIndexOf('|');
      if (!force && cut > 0 && prev.slice(0, cut) === sig && Date.now() - Number(prev.slice(cut + 1)) < HOUR) { sent = true; return; }
      var body = { platform: r.platform, perms: r.perms, source: 'kt-device-perms' };
      if (ft) body.fcm_token = ft;
      if (r.platform !== 'web') { body.install_id = installId(); if (appBuild) body.app_build = appBuild; }
      await send(body, keep);
      sent = true;
      try { localStorage.setItem(KEY, sig + '|' + Date.now()); } catch (e) {}
    } catch (e) {
    } finally { busy = false; }
  }

  KT.devicePerms = { read: read, report: report, installId: installId };
  // Soon after there is a sign-in, then whenever the app returns to the front, and as it leaves if nothing went yet.
  (function first() {
    var n = 0;
    (function wait() {
      if (tok()) { setTimeout(function () { report(false); }, 1500); return; }
      if (++n < 90) setTimeout(wait, 1000);
    })();
  })();
  document.addEventListener('visibilitychange', function () {
    if (document.visibilityState === 'visible') setTimeout(function () { report(false); }, 1500);
    else if (!sent) report(true, true);
  });
  try { var App = cap() && cap().App; if (App && App.addListener) App.addListener('resume', function () { setTimeout(function () { report(false); }, 1500); }); } catch (e) {}
})(window);
