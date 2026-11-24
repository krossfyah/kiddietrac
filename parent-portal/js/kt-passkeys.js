/* ═══════════════════════════════════════════════════════════════════
   KiddieTrac — passkeys (client half).

   The server (PasskeyController, since 2026-09-22) runs the ceremonies; this is
   the browser side: turn the server's options into what navigator.credentials
   wants, and the browser's answer into what the server verifies.

     KT.passkeys.supported()   can this browser make / use one at all
     KT.passkeys.register()    add one to the signed-in account
     KT.passkeys.signIn()      usernameless sign-in → the same body /auth/login returns
     KT.passkeys.list()/remove(id)

   THE ANDROID APP. Up to 3.10.2 its web view had no WebAuthn at all
   (PublicKeyCredential undefined — Anthony's device test, 2026-09-22), so the option
   is hidden there rather than shown as a button that cannot work. From 3.10.3
   (2026-10-10) the app turns WebAuthn on (WebSettingsCompat, FOR_APP), kiddietrac.com
   links the app in /.well-known/assetlinks.json and the server accepts the app's
   apk-key-hash origin, so supported() is simply true there and everything below works
   unchanged. Older apps keep password + fingerprint unlock.

   A passkey is an ADDITIONAL way in: the password still works and still expires
   on the normal schedule (Anthony, 2026-09-22).
   ═══════════════════════════════════════════════════════════════════ */
(function (window) {
  'use strict';
  var KT = window.KT || (window.KT = {});

  function apiBase() {
    return (KT.API_BASE) || (window.KT_CONFIG && window.KT_CONFIG.apiBase) || 'https://api.kiddietrac.com/api/v1';
  }
  function token() {
    try { return sessionStorage.getItem('kt_token') || localStorage.getItem('kt_token'); } catch (e) { return null; }
  }

  /* THE iPHONE APP (2026-10-30 iOS sweep). Its WKWebView exposes PublicKeyCredential, but WebKit only lets an app
     use WebAuthn for a domain it is associated with: an Associated Domains "webcredentials:kiddietrac.com"
     entitlement in the app AND https://kiddietrac.com/.well-known/apple-app-site-association listing the app (served
     without a redirect). Neither exists yet, so the button could only fail. Off in the iOS app until both ship;
     Face ID unlock (KtBio) is unaffected. To turn on: set KT.IOS_PASSKEYS = true once the build with the
     entitlement is live. */
  function iosAppWithoutWebCredentials() {
    try {
      var C = window.Capacitor;
      return !!(C && C.isNativePlatform && C.isNativePlatform() && C.getPlatform && C.getPlatform() === 'ios' && KT.IOS_PASSKEYS !== true);
    } catch (e) { return false; }
  }

  function supported() {
    try {
      if (iosAppWithoutWebCredentials()) return false;
      if (typeof window.PublicKeyCredential !== 'function') return false;
      if (!navigator.credentials || typeof navigator.credentials.create !== 'function') return false;
      return window.isSecureContext !== false;
    } catch (e) { return false; }
  }

  /* ── encoding ──
     lbuchs/webauthn serialises binary as "=?BINARY?B?<base64>?=". Every such string,
     wherever it sits in the options (challenge, user.id, excludeCredentials[].id),
     becomes an ArrayBuffer; nothing else is touched. */
  var BIN = /^=\?BINARY\?B\?(.*)\?=$/;
  function b64ToBuf(b64) {
    var s = atob(b64.replace(/-/g, '+').replace(/_/g, '/'));
    var u = new Uint8Array(s.length);
    for (var i = 0; i < s.length; i++) u[i] = s.charCodeAt(i);
    return u.buffer;
  }
  function bufToB64u(buf) {
    var b = new Uint8Array(buf), s = '';
    for (var i = 0; i < b.length; i++) s += String.fromCharCode(b[i]);
    return btoa(s).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');
  }
  function decodeOptions(v) {
    if (typeof v === 'string') { var m = v.match(BIN); return m ? b64ToBuf(m[1]) : v; }
    if (Array.isArray(v)) return v.map(decodeOptions);
    if (v && typeof v === 'object') {
      var o = {};
      Object.keys(v).forEach(function (k) { o[k] = decodeOptions(v[k]); });
      return o;
    }
    return v;
  }

  async function call(method, path, body, auth) {
    var h = { 'Accept': 'application/json' };
    if (body) h['Content-Type'] = 'application/json';
    if (auth) { var t = token(); if (t) h.Authorization = 'Bearer ' + t; }
    /* The challenge ("options") is safe to ask for twice, so it is retried through a bad
       connection; a "verify" spends the passkey's signature and is sent once. A request
       that never got an answer is marked .network, so the sign-in page can tell "no
       connection" from "the server said no" (2026-10-22). */
    var init = { method: method, headers: h, body: body ? JSON.stringify(body) : undefined };
    var r;
    try {
      r = (window.KT && KT.net)
        ? await KT.net.fetch(apiBase() + path, init, { path: path.replace(/^\//, ''), retries: /options$/.test(path) ? 2 : 0 })
        : await fetch(apiBase() + path, init);
    } catch (e) {
      var ne = new Error((window.KT && KT.net) ? KT.net.message(e) : 'We couldn’t reach KiddieTrac. Check your internet connection, then try again.');
      ne.network = true;
      throw ne;
    }
    var d = await r.json().catch(function () { return {}; });
    if (!r.ok) {
      var e = new Error(d.message || ('Request failed (' + r.status + ')'));
      e.status = r.status;
      throw e;
    }
    return d;
  }

  /* The browser's refusals, in words a person can act on. A cancelled prompt is the
     commonest outcome and is not an error: it is said as a cancellation. */
  function explain(e) {
    var n = e && e.name;
    if (n === 'NotAllowedError' || n === 'AbortError') {
      var c = new Error('Cancelled — nothing was changed.'); c.cancelled = true; return c;
    }
    /* The phone (or its Google / iCloud password manager, which syncs between devices)
       already holds one of this account's passkeys, so it will not make a second. Say
       what to do instead of only that it refused (2026-10-22). */
    if (n === 'InvalidStateError') return new Error('This device already has a passkey for your account (it may be saved in your Google or iCloud password manager). Use “Sign in with a passkey” — there is no need to add another.');
    if (n === 'SecurityError') return new Error('Passkeys can only be used on app.kiddietrac.com.');
    if (n === 'NotSupportedError') return new Error('This device or browser cannot make a passkey.');
    /* THE PHONE'S PASSWORD MANAGER DID NOT ANSWER (2026-11-04). Android's Credential Manager (Google Password Manager,
       Samsung Pass) fails with UnknownError "An unknown error occurred while talking to the credential manager." when
       no passkey provider is set up or it is busy. That sentence means nothing to a parent; say what to do. */
    if (n === 'UnknownError' || /credential manager/i.test(String((e && e.message) || ''))) {
      var u = new Error('This phone\u2019s password manager did not answer. Sign in with your email and password instead, or try the passkey again in a moment.');
      u.device = true; return u;
    }
    return e instanceof Error ? e : new Error(String(e));
  }

  async function register(label) {
    if (!supported()) throw new Error('Passkeys are not available in this browser or app.');
    var o = await call('POST', '/passkeys/register/options', {}, true);
    var cred;
    try {
      cred = await navigator.credentials.create({ publicKey: decodeOptions(o.options) });
    } catch (e) { throw explain(e); }
    if (!cred) throw new Error('No passkey was created.');
    var res = await call('POST', '/passkeys/register/verify', {
      handle: o.handle,
      client_data: bufToB64u(cred.response.clientDataJSON),
      attestation: bufToB64u(cred.response.attestationObject),
      label: label || undefined,
    }, true);
    markHere();
    return res.passkey;
  }

  /* opts.conditional: the browser's own autofill list offers the passkey when the email
     box is focused, instead of a prompt. It waits indefinitely, so it takes an
     AbortSignal: the button (or a fresh challenge) cancels it first — only one
     credential request may be pending at a time. */
  async function signIn(opts) {
    opts = opts || {};
    if (!supported()) throw new Error('Passkeys are not available in this browser or app.');
    // Marked, so the sign-in page can tell "could not get a challenge" (nobody acted yet) from a refused passkey.
    var o;
    try { o = await call('POST', '/auth/passkey/options', {}, false); } catch (e) { e.phase = 'options'; throw e; }
    var cred;
    try {
      var req = { publicKey: decodeOptions(o.options) };
      if (opts.conditional) { req.mediation = 'conditional'; }
      if (opts.signal) { req.signal = opts.signal; }
      cred = await navigator.credentials.get(req);
    } catch (e) { var x = explain(e); x.phase = 'get'; throw x; }   // the browser / phone, not our server
    if (!cred) throw new Error('No passkey was chosen.');
    var r = cred.response;
    var userHandle = null;
    try { if (r.userHandle && r.userHandle.byteLength) userHandle = new TextDecoder().decode(r.userHandle); } catch (e) {}
    return call('POST', '/auth/passkey/verify', {
      handle: o.handle,
      credential_id: bufToB64u(cred.rawId),
      client_data: bufToB64u(r.clientDataJSON),
      authenticator_data: bufToB64u(r.authenticatorData),
      signature: bufToB64u(r.signature),
      user_handle: userHandle,
      device_name: navigator.userAgent.substring(0, 100),
      // The app says it is the app (2026-11-02: the iPhone app's sign-in was filed as a browser).
      device_platform: (function () { try { var C = window.Capacitor; if (C && C.isNativePlatform && C.isNativePlatform()) { var p = C.getPlatform(); if (p === 'ios' || p === 'android') return p; } } catch (e) {} return 'web'; })(),
    }, false);
  }

  /* ── "This device has one" ──
     Remembered locally per account, so the offer below is not made on a device that
     already signs in with a passkey. */
  function uid() { try { return (JSON.parse(sessionStorage.getItem('kt_user') || localStorage.getItem('kt_user') || '{}') || {}).id || 0; } catch (e) { return 0; } }
  function markHere() { try { var u = uid(); if (u) localStorage.setItem('kt_pk_here_' + u, '1'); } catch (e) {} }
  function hasHere() { try { var u = uid(); return !!u && localStorage.getItem('kt_pk_here_' + u) === '1'; } catch (e) { return false; } }

  async function conditionalAvailable() {
    try {
      return supported() && typeof PublicKeyCredential.isConditionalMediationAvailable === 'function'
        && await PublicKeyCredential.isConditionalMediationAvailable();
    } catch (e) { return false; }
  }

  /* ── THE OFFER, once, after a PASSWORD sign-in (Anthony, 2026-09-28) ──

     Nobody finds a setting they do not know exists — 0 passkeys were registered while
     the feature sat in Settings. So after somebody signs in with their password on a
     device that can make one, ask once:

       • only after a password sign-in (the sign-in page sets kt_pk_offer), never on a
         reload, never after signing in WITH a passkey;
       • not in the Android app (no WebAuthn — supported() is false there);
       • not if the account already has a passkey, or this device already signed in with one;
       • not on the same visit as the fingerprint-unlock card: where that card is about
         to be offered, it goes first and this waits for a later sign-in;
       • "Not now" snoozes it for 30 days on EVERY device (an account marker).  */
  var SNOOZE_KEY = 'kt_passkey_offer_snooze';
  function snoozed() {
    var v = 0;
    try { v = Number((KT.markers && KT.markers.get(SNOOZE_KEY)) || localStorage.getItem(SNOOZE_KEY) || 0); } catch (e) {}
    return v > Date.now();
  }
  function snooze(days) {
    var until = String(Date.now() + days * 86400000);
    try { if (KT.markers) KT.markers.set(SNOOZE_KEY, until); else localStorage.setItem(SNOOZE_KEY, until); } catch (e) {}
  }
  async function fingerprintCardDue() {
    try {
      var bio = KT.biometric;
      if (!bio || !bio.available) return false;
      if (bio.isEnabled && bio.isEnabled()) return false;
      var dc = parseInt(localStorage.getItem('kt_biometric_declined') || '0', 10) || 0;
      if (dc && Date.now() - dc < 7 * 86400000) return false;
      return !!(await bio.available());
    } catch (e) { return false; }
  }
  /* Is something sitting over the page? Whatever is at the centre of the screen: if it,
     or anything it is inside, is a fixed layer stacked high, a gate or dialog is open. */
  function covered() {
    try {
      if (document.getElementById('kt-bio-ov') || document.getElementById('kt-mfa-gate')) return true;
      var n = document.elementFromPoint(window.innerWidth / 2, window.innerHeight / 2);
      for (; n && n !== document.body && n !== document.documentElement; n = n.parentElement) {
        var cs = getComputedStyle(n);
        if (cs.position === 'fixed' && (parseInt(cs.zIndex, 10) || 0) >= 10000) return true;
      }
    } catch (e) {}
    return false;
  }
  async function maybeOffer() {
    var flagged = false;
    try { flagged = sessionStorage.getItem('kt_pk_offer') === '1'; sessionStorage.removeItem('kt_pk_offer'); } catch (e) {}
    if (!flagged || !supported() || !token() || hasHere()) return;
    try { if (sessionStorage.getItem('kt_force_password_change') === '1') return; } catch (e) {}
    if (window.KT_PUBLIC_DEMO) return;   // a shared public demo account: passkeys are switched off there (2026-10-31)
    // The account's snooze may live on the server: wait for the markers to land.
    if (KT.markers && KT.markers.onSync) { await new Promise(function (r) { KT.markers.onSync(r); setTimeout(r, 4000); }); }
    if (snoozed()) return;
    if (await fingerprintCardDue()) return;
    var have;
    try { have = ((await KT.passkeys.list()).passkeys || []).length; } catch (e) { return; }
    if (have) return;
    // Let the first screen settle; and never on top of something already open — the
    // two-factor requirement, the agreement gate, the fingerprint card, any dialog.
    // Wait for it to clear; if it has not in two minutes, not this visit (no snooze).
    await new Promise(function (r) { setTimeout(r, 3500); });
    for (var waited = 0; covered(); waited += 5000) {
      if (waited >= 120000) return;
      await new Promise(function (r) { setTimeout(r, 5000); });
    }
    if (!KT.confirm) return;
    var ok = await KT.confirm({
      // Explicit: KT.confirm turns red on its own when it sees a word like "remove",
      // and this is an offer, not a warning.
      tone: 'default',
      title: 'Sign in faster next time?',
      description: 'Add a passkey and sign in with your face, fingerprint or device PIN — no password to type. Your password still works, and you can remove it any time in your security settings.',
      okLabel: 'Add a passkey',
      cancelLabel: 'Not now',
    });
    if (!ok) { snooze(30); return; }
    try {
      var p = await register();
      if (KT.toast) KT.toast('🔑', 'Passkey added', (p && p.label ? p.label + ' — ' : '') + 'next time, tap "Sign in with a passkey".', '#1F6080');
    } catch (e) {
      if (e && e.cancelled) { snooze(7); return; }
      if (KT.toast) KT.toast('⚠️', 'Passkey not added', (e && e.message) || 'Please try again from your security settings.', '#B91C1C');
    }
  }
  if (/dashboard\.html/i.test(location.pathname)) {
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', function () { setTimeout(maybeOffer, 1500); });
    else setTimeout(maybeOffer, 1500);
  }

  KT.passkeys = {
    conditionalAvailable: conditionalAvailable,
    markHere: markHere,
    supported: supported,
    register: register,
    signIn: signIn,
    list: function () { return call('GET', '/passkeys', null, true); },
    remove: function (id) { return call('DELETE', '/passkeys/' + encodeURIComponent(id), null, true); },
  };
})(window);
