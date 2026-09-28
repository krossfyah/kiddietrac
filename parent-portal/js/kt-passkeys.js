/* ═══════════════════════════════════════════════════════════════════
   KiddieTrac — passkeys (client half).

   The server (PasskeyController, since 2026-09-22) runs the ceremonies; this is
   the browser side: turn the server's options into what navigator.credentials
   wants, and the browser's answer into what the server verifies.

     KT.passkeys.supported()   can this browser make / use one at all
     KT.passkeys.register()    add one to the signed-in account
     KT.passkeys.signIn()      usernameless sign-in → the same body /auth/login returns
     KT.passkeys.list()/remove(id)

   NOT OFFERED IN THE ANDROID APP. Its web view has no WebAuthn at all
   (PublicKeyCredential is undefined — Anthony's device test, 2026-09-22), so the
   option is hidden there rather than shown as a button that cannot work. Those
   users keep password + fingerprint unlock.

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

  function supported() {
    try {
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
    var r = await fetch(apiBase() + path, { method: method, headers: h, body: body ? JSON.stringify(body) : undefined });
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
    if (n === 'InvalidStateError') return new Error('This device already has a passkey for your account.');
    if (n === 'SecurityError') return new Error('Passkeys can only be used on app.kiddietrac.com.');
    if (n === 'NotSupportedError') return new Error('This device or browser cannot make a passkey.');
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
    return res.passkey;
  }

  async function signIn() {
    if (!supported()) throw new Error('Passkeys are not available in this browser or app.');
    var o = await call('POST', '/auth/passkey/options', {}, false);
    var cred;
    try {
      cred = await navigator.credentials.get({ publicKey: decodeOptions(o.options) });
    } catch (e) { throw explain(e); }
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
      device_platform: 'web',
    }, false);
  }

  KT.passkeys = {
    supported: supported,
    register: register,
    signIn: signIn,
    list: function () { return call('GET', '/passkeys', null, true); },
    remove: function (id) { return call('DELETE', '/passkeys/' + encodeURIComponent(id), null, true); },
  };
})(window);
