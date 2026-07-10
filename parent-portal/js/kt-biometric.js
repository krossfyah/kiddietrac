/* ═══════════════════════════════════════════════════════════════════
   KiddieTrac — biometric lock via WebAuthn (2026-07-11, rewritten AGAIN).
   The @capgo native-biometric plugin crashed at the NATIVE level on the
   Samsung S25 / Android 16 (a native crash Java can't catch), so we no longer
   call it at all. Instead we use the browser's WebAuthn platform authenticator
   — the WebView hands off to the OS's own fingerprint/face (Samsung Pass /
   Android biometric), with NO native plugin in the path, so it cannot crash the
   app. If the WebView doesn't support it, we report "unavailable" gracefully.

   This is a LOCAL unlock gate (we don't verify the assertion server-side) — its
   job is to lock the already-signed-in app behind the phone's biometric.
   ═══════════════════════════════════════════════════════════════════ */
(function () {
  'use strict';
  if (window.__ktBiometric) return; window.__ktBiometric = true;

  var lastError = '';

  // Prefer the native BiometricPrompt plugin (KtBio) — a bare yes/no biometric
  // check with no keystore, so it can't hit the native crash the @capgo plugin did.
  // Falls back to WebAuthn (for non-APK browsers / if the plugin is absent).
  function nativeBio() { try { return window.Capacitor && Capacitor.Plugins && Capacitor.Plugins.KtBio; } catch (e) { return null; } }

  function b64url(buf) { var b = new Uint8Array(buf), s = ''; for (var i = 0; i < b.length; i++) s += String.fromCharCode(b[i]); return btoa(s).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, ''); }
  function fromB64url(str) { str = String(str).replace(/-/g, '+').replace(/_/g, '/'); while (str.length % 4) str += '='; var bin = atob(str), buf = new Uint8Array(bin.length); for (var i = 0; i < bin.length; i++) buf[i] = bin.charCodeAt(i); return buf.buffer; }
  function rand(n) { var a = new Uint8Array(n || 32); (window.crypto || window.msCrypto).getRandomValues(a); return a; }
  function get(k) { try { return localStorage.getItem(k); } catch (e) { return null; } }
  function set(k, v) { try { localStorage.setItem(k, v); } catch (e) {} }
  function del(k) { try { localStorage.removeItem(k); } catch (e) {} }
  function user() { try { return JSON.parse(sessionStorage.getItem('kt_user') || localStorage.getItem('kt_user') || '{}'); } catch (e) { return {}; } }
  function tok() { try { return sessionStorage.getItem('kt_token') || localStorage.getItem('kt_token'); } catch (e) { return null; } }
  function apiBase() { return (window.KT_CONFIG && window.KT_CONFIG.apiBase) || 'https://api.kiddietrac.com/api/v1'; }
  function rpId() { var h = location.hostname; return h || 'app.kiddietrac.com'; }

  async function available() {
    // 1) Native plugin (the APK) — preferred.
    var nb = nativeBio();
    if (nb && nb.available) {
      try { var r = await nb.available(); if (!(r && r.available)) lastError = 'native canAuthenticate code ' + (r && r.code); return (r && r.available) ? 'biometric' : null; }
      catch (e) { lastError = 'native available: ' + ((e && (e.message || e.name)) || e); /* fall through to WebAuthn */ }
    }
    // 2) WebAuthn fallback (browsers).
    try {
      if (!window.PublicKeyCredential || !PublicKeyCredential.isUserVerifyingPlatformAuthenticatorAvailable) { lastError = lastError || 'no biometric API'; return null; }
      var ok = await PublicKeyCredential.isUserVerifyingPlatformAuthenticatorAvailable();
      if (!ok) { lastError = lastError || 'no platform authenticator'; }
      return ok ? 'biometric' : null;
    } catch (e) { lastError = 'available: ' + ((e && (e.message || e.name)) || e); return null; }
  }

  // Show the OS biometric prompt. Native = a bare yes/no; WebAuthn = create/assert.
  async function nativeVerify(subtitle) {
    var nb = nativeBio();
    try { var r = await nb.authenticate({ title: 'KiddieTrac', subtitle: subtitle || 'Confirm your identity' }); if (!(r && r.success)) lastError = 'native: ' + (r && r.error); return !!(r && r.success); }
    catch (e) { lastError = 'native authenticate: ' + ((e && (e.message || e.name)) || e); return false; }
  }

  async function enroll() {
    if (!(await available())) return false;
    var nb = nativeBio();
    if (nb && nb.authenticate) {
      var ok = await nativeVerify('Turn on fingerprint / face unlock');
      if (ok) { set('kt_biometric_enabled', '1'); del('kt_bio_cred'); persistSession(); lastError = ''; }
      return ok;
    }
    // WebAuthn: register a platform credential (local unlock gate, no server ceremony).
    try {
      var u = user();
      var cred = await navigator.credentials.create({
        publicKey: {
          challenge: rand(32),
          rp: { name: 'KiddieTrac', id: rpId() },
          user: { id: rand(16), name: (u.email || 'user'), displayName: ((u.first_name || '') + ' ' + (u.last_name || '')).trim() || 'KiddieTrac user' },
          pubKeyCredParams: [{ type: 'public-key', alg: -7 }, { type: 'public-key', alg: -257 }],
          authenticatorSelection: { authenticatorAttachment: 'platform', userVerification: 'required', residentKey: 'preferred' },
          timeout: 60000, attestation: 'none'
        }
      });
      if (cred && cred.rawId) { set('kt_bio_cred', b64url(cred.rawId)); set('kt_biometric_enabled', '1'); lastError = ''; return true; }
      lastError = 'no credential returned'; return false;
    } catch (e) { lastError = 'enroll: ' + ((e && (e.message || e.name)) || e); return false; }
  }

  function disable() {
    del('kt_biometric_enabled'); del('kt_bio_cred');
    del('kt_bio_token'); del('kt_bio_user'); del('kt_bio_agency'); del('kt_bio_view');
    return Promise.resolve(true);
  }
  function isEnabled() { return get('kt_biometric_enabled') === '1'; }
  function hasVault() { return !!get('kt_bio_token'); }

  // Stash the session behind biometrics under SEPARATE keys (kt_bio_*), NOT kt_token
  // — so the normal auth flow can't auto-login from it; only a biometric unlock
  // restores it into sessionStorage.
  function persistSession() {
    try {
      var t = tok(); if (!t) return;
      set('kt_bio_token', t);
      var u = sessionStorage.getItem('kt_user') || localStorage.getItem('kt_user'); if (u) set('kt_bio_user', u);
      var a = sessionStorage.getItem('kt_active_agency_id'); if (a) set('kt_bio_agency', a);
      var v = sessionStorage.getItem('kt_view_as'); if (v) set('kt_bio_view', v);
    } catch (e) {}
  }
  function restoreSession() {
    try {
      var t = get('kt_bio_token'); if (t) sessionStorage.setItem('kt_token', t);
      var u = get('kt_bio_user'); if (u) sessionStorage.setItem('kt_user', u);
      var a = get('kt_bio_agency'); if (a) sessionStorage.setItem('kt_active_agency_id', a);
      var v = get('kt_bio_view'); if (v) sessionStorage.setItem('kt_view_as', v);
      sessionStorage.setItem('kt_login_at', String(Date.now()));
      sessionStorage.setItem('kt_last_activity', String(Date.now()));
    } catch (e) {}
  }

  // After a successful unlock we HARD-RELOAD rather than just removing the
  // overlay. app.js / session-timeout / the shell all boot in a logged-out state
  // (kt-biometric.js is the last script to load), so ov.remove() left them stale
  // and the app bounced straight back to the login screen a moment later. A full
  // reload — with the restored token already in sessionStorage — boots every
  // module authenticated. First validate the token so a dead/revoked vault token
  // falls back to password login instead of looping.
  function commitUnlock(onDash, ov) {
    var go = function () { if (onDash) location.reload(); else location.replace('/dashboard.html'); };
    var t = tok();
    if (!t) { go(); return; }
    var settled = false;
    var timer = setTimeout(function () { if (!settled) { settled = true; go(); } }, 3500); // slow network → proceed
    try {
      fetch(apiBase() + '/auth/me', { headers: { 'Authorization': 'Bearer ' + t, 'Accept': 'application/json' } })
        .then(function (r) {
          if (settled) return; settled = true; try { clearTimeout(timer); } catch (e) {}
          if (r.status === 401 || r.status === 419) {
            // Vault token is dead — clear it and fall back to password login (no loop).
            disable();
            try { sessionStorage.removeItem('kt_token'); } catch (e) {}
            location.replace('/index.html?signed_out=biometric_expired');
          } else { go(); }
        })
        .catch(function () { if (!settled) { settled = true; try { clearTimeout(timer); } catch (e) {} go(); } });
    } catch (e) { if (!settled) { settled = true; go(); } }
  }

  async function signIn() {
    var nb = nativeBio();
    if (nb && nb.authenticate) return nativeVerify('Use your fingerprint or face to continue');
    // WebAuthn unlock.
    try {
      var id = get('kt_bio_cred'); if (!id) { lastError = 'not enrolled'; return false; }
      var assertion = await navigator.credentials.get({
        publicKey: { challenge: rand(32), rpId: rpId(), allowCredentials: [{ type: 'public-key', id: fromB64url(id), transports: ['internal'] }], userVerification: 'required', timeout: 60000 }
      });
      return !!assertion;
    } catch (e) { lastError = 'signIn: ' + ((e && (e.message || e.name)) || e); return false; }
  }

  window.KT = window.KT || {};
  window.KT.biometric = { available: available, enroll: enroll, signIn: signIn, disable: disable, isEnabled: isEnabled, verify: signIn, lastError: function () { return lastError; } };

  // ── UI: enable prompt (after login) ─────────────────────────────────
  function card(html, onYes, onNo, yesLabel) {
    if (document.getElementById('kt-bio-ov')) return;
    var st = document.createElement('style');
    st.textContent = '@keyframes kt-bio-fade{from{opacity:0;}to{opacity:1;}}@keyframes kt-bio-pop{from{opacity:0;transform:scale(.92);}to{opacity:1;transform:none;}}';
    document.head.appendChild(st);
    var ov = document.createElement('div'); ov.id = 'kt-bio-ov';
    ov.style.cssText = 'position:fixed;inset:0;z-index:14000;background:rgba(8,17,33,.64);-webkit-backdrop-filter:blur(3px);backdrop-filter:blur(3px);display:flex;align-items:center;justify-content:center;padding:24px;animation:kt-bio-fade .25s ease both;';
    var el = document.createElement('div');
    el.style.cssText = 'background:#fff;border-radius:22px;max-width:340px;width:100%;padding:28px 24px 20px;text-align:center;box-shadow:0 28px 64px -14px rgba(0,0,0,.6);animation:kt-bio-pop .32s cubic-bezier(.2,.8,.2,1) both;font-family:system-ui,-apple-system,sans-serif;';
    el.innerHTML = '<div style="font-size:56px;line-height:1;margin-bottom:6px;">🔐</div>' + html
      + '<div style="display:flex;flex-direction:column;gap:9px;margin-top:20px;">'
      + '<button id="kt-bio-yes" style="background:#0E7C90;color:#fff;border:none;border-radius:13px;padding:14px;font-weight:800;font-size:15px;">' + yesLabel + '</button>'
      + '<button id="kt-bio-no" style="background:transparent;color:#64748B;border:none;padding:9px;font-weight:700;font-size:13px;">Not now</button></div>';
    ov.appendChild(el); document.body.appendChild(ov);
    el.querySelector('#kt-bio-yes').onclick = function () { ov.remove(); onYes(); };
    el.querySelector('#kt-bio-no').onclick = function () { ov.remove(); onNo && onNo(); };
  }

  // ── UI: full-screen lock on launch → biometric → restore session ────
  // onDash: are we on dashboard.html (just unlock) or the login page (unlock +
  // navigate to the dashboard with the restored session)?
  function showLock(onDash) {
    if (document.getElementById('kt-bio-lock')) return;
    var ov = document.createElement('div'); ov.id = 'kt-bio-lock';
    ov.style.cssText = 'position:fixed;inset:0;z-index:2147482000;background:linear-gradient(160deg,#0E2A44,#081C41);display:flex;flex-direction:column;align-items:center;justify-content:center;text-align:center;padding:28px;color:#fff;font-family:system-ui,-apple-system,sans-serif;';
    ov.innerHTML = '<div style="font-size:64px;line-height:1;margin-bottom:14px;">🔒</div>'
      + '<div style="font-weight:800;font-size:20px;margin-bottom:8px;">Welcome back</div>'
      + '<div style="font-size:14px;opacity:.8;margin-bottom:26px;max-width:280px;line-height:1.5;">Unlock KiddieTrac with your fingerprint or face.</div>'
      + '<button id="kt-bio-unlock" style="background:#fff;color:#081C41;border:none;border-radius:14px;padding:14px 30px;font-size:16px;font-weight:800;">Unlock</button>'
      + '<button id="kt-bio-signout" style="background:transparent;color:rgba(255,255,255,.7);border:none;margin-top:16px;font-size:13px;font-weight:700;">Use password instead</button>';
    document.body.appendChild(ov);
    var busy = false;
    var attempt = function () {
      if (busy) return; busy = true;
      signIn().then(function (ok) {
        busy = false;
        if (!ok) return;
        // Mark unlocked so boot() can't re-lock this session (breaks the re-prompt loop).
        try { sessionStorage.setItem('kt_bio_session', '1'); } catch (e) {}
        set('kt_bio_unlocked_at', String(Date.now()));
        restoreSession();
        commitUnlock(onDash, ov);
      });
    };
    ov.querySelector('#kt-bio-unlock').onclick = attempt;
    ov.querySelector('#kt-bio-signout').onclick = function () {
      // Keep biometric enrolled, just fall back to the password this time.
      ov.remove();
      if (onDash) location.replace('/index.html');
    };
    attempt();   // auto-fire the prompt on launch
  }

  async function tick() {
    var kind = await available(); if (!kind) return;
    var onDashboard = /dashboard\.html/i.test(location.pathname);
    if (tok() && onDashboard) {
      if (isEnabled()) { persistSession(); return; }   // keep the vault token fresh
      if (get('kt_biometric_declined') === '1') return;
      card('<div style="font-weight:800;font-size:19px;color:#0D1B2A;">Enable biometric unlock?</div>'
        + '<div style="font-size:13.5px;color:#64748B;margin-top:7px;line-height:1.5;">Next time, open the app straight from your fingerprint or face — no password to type.</div>',
        function () { enroll().then(function (ok) { if (window.KT.toast) window.KT.toast(ok ? '✅' : '⚠️', ok ? 'Biometric sign-in enabled' : 'Could not enable — ' + (lastError || 'try again'), '', '#0E7C90'); }); },
        function () { set('kt_biometric_declined', '1'); }, 'Enable');
    }
  }

  function boot() {
    var onDash = /dashboard\.html/i.test(location.pathname);
    var hasSession = false; try { hasSession = !!sessionStorage.getItem('kt_token'); } catch (e) {}
    var unlockedThisSession = false; try { unlockedThisSession = sessionStorage.getItem('kt_bio_session') === '1'; } catch (e) {}
    var recentlyUnlocked = (Date.now() - (parseInt(get('kt_bio_unlocked_at') || '0', 10) || 0)) < 20000;
    // FRESH LAUNCH only: no active session, not already unlocked this session, and
    // not just unlocked seconds ago (the guards STOP the re-prompt loop).
    if (isEnabled() && hasVault() && !hasSession && !unlockedThisSession && !recentlyUnlocked) {
      available().then(function (k) { if (k) showLock(onDash); });
      return;
    }
    // Active session on the dashboard → keep the vault fresh + maybe offer to enable.
    setTimeout(tick, 2500);
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot); else boot();
})();
