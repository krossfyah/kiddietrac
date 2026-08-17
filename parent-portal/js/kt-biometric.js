/* ═══════════════════════════════════════════════════════════════════
   KiddieTrac — biometric login/unlock (2026-07-20 rework).

   Native path: the KtBio Capacitor plugin — Android BiometricPrompt,
   iOS LocalAuthentication (Face ID / Touch ID) — a bare yes/no identity
   check with NO keystore/CryptoObject (the thing that crashed the @capgo
   plugin natively). WebAuthn is only a fallback for plain browsers.

   This is a LOCAL unlock gate: after a normal password sign-in we stash the
   bearer token under SEPARATE kt_bio_* keys (NOT kt_token, so ordinary auth
   can't auto-resume it), and on a fresh launch the biometric unlock restores
   it. The server assertion isn't verified — biometric just re-opens an
   already-authenticated session behind the phone's own fingerprint/face.

   2026-07-20 changes (login prompt was never firing):
     - On-device telemetry showed enabled/vault=false on every boot: the
       login prompt is gated on prior enrolment, and "Sign out" was WIPING
       enrolment (biometric.disable()), so after one sign-out it never
       prompted again. Sign-out now KEEPS enrolment (Settings has an explicit
       "turn off"); only that toggle or a dead token clears it.
     - The login page now shows a real, tappable "Sign in with fingerprint /
       Face ID" button — no longer a silent auto-prompt with no retry.
     - boot() shows the unlock screen whenever enrolled+vaulted, without
       gating on a possibly-flaky available() probe; the attempt itself
       reports any error and offers the password fallback.
     - Much richer /diag/bio telemetry (native-plugin presence, available()
       result+code, lastError, enrol + unlock outcomes) so a device test is
       conclusive.
   ═══════════════════════════════════════════════════════════════════ */
(function () {
  'use strict';
  if (window.__ktBiometric) return; window.__ktBiometric = true;

  var lastError = '';
  var lastAvail = undefined;   // cache of the last available() result for diag

  function nativeBio() { try { return window.Capacitor && Capacitor.Plugins && Capacitor.Plugins.KtBio; } catch (e) { return null; } }
  function isNativePlatform() { try { return !!(window.Capacitor && Capacitor.isNativePlatform && Capacitor.isNativePlatform()); } catch (e) { return false; } }
  // Biometric unlock is a phone / tablet / APK feature. Desktop browsers ALSO
  // expose a WebAuthn platform authenticator (Windows Hello, macOS Touch ID),
  // which wrongly triggered the "Enable biometric unlock?" prompt on desktop.
  // A coarse PRIMARY pointer is true on phones/tablets and false on desktops —
  // and on touch-LAPTOPS too, whose primary pointer is the trackpad (fine).
  function isMobileLike() {
    try {
      if (window.matchMedia && window.matchMedia('(pointer: coarse)').matches) return true;
      var ua = navigator.userAgent || '';
      if (/Android|iPhone|iPad|iPod|Mobile|Tablet|Silk|Kindle|Windows Phone/i.test(ua)) return true;
      if ((navigator.maxTouchPoints || 0) > 1 && /Macintosh/.test(ua)) return true; // iPadOS reports as Mac
      return false;
    } catch (e) { return false; }
  }
  function isBiometricPlatform() { return isNativePlatform() || isMobileLike(); }

  function b64url(buf) { var b = new Uint8Array(buf), s = ''; for (var i = 0; i < b.length; i++) s += String.fromCharCode(b[i]); return btoa(s).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, ''); }
  function fromB64url(str) { str = String(str).replace(/-/g, '+').replace(/_/g, '/'); while (str.length % 4) str += '='; var bin = atob(str), buf = new Uint8Array(bin.length); for (var i = 0; i < bin.length; i++) buf[i] = bin.charCodeAt(i); return buf.buffer; }
  function rand(n) { var a = new Uint8Array(n || 32); (window.crypto || window.msCrypto).getRandomValues(a); return a; }
  function get(k) { try { return localStorage.getItem(k); } catch (e) { return null; } }
  function set(k, v) { try { localStorage.setItem(k, v); } catch (e) {} }
  function del(k) { try { localStorage.removeItem(k); } catch (e) {} }
  function user() { try { return JSON.parse(sessionStorage.getItem('kt_user') || localStorage.getItem('kt_user') || '{}'); } catch (e) { return {}; } }
  function tok() { try { return sessionStorage.getItem('kt_token') || localStorage.getItem('kt_token'); } catch (e) { return null; } }
  function apiBase() { return (window.KT_CONFIG && window.KT_CONFIG.apiBase) || 'https://api.kiddietrac.com/api/v1'; }

  // Device diagnostics → /diag/bio. Now carries WHY biometric is/isn't working.
  function bioDiag(step, extra) {
    try {
      var hs = 0; try { hs = !!sessionStorage.getItem('kt_token'); } catch (e) {}
      var payload = {
        step: step, path: location.pathname, search: location.search || '',
        hasSession: hs, enabled: isEnabled(), vault: hasVault(),
        native: isNativePlatform(), ktbio: !!nativeBio(), avail: lastAvail,
        err: lastError || null, declined: get('kt_biometric_declined') || null,
        unlockedAt: (get('kt_bio_unlocked_at') || '0'), extra: extra || null, ts: Date.now()
      };
      var durl = apiBase() + '/diag/bio', dbody = JSON.stringify({ data: JSON.stringify(payload) });
      // sendBeacon survives the location.replace() that commitUnlock() fires on a
      // 401, so the 'validated'/'disable' events aren't lost to the navigation.
      if (navigator.sendBeacon) { try { navigator.sendBeacon(durl, new Blob([dbody], { type: 'application/json' })); return; } catch (e) {} }
      fetch(durl, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: dbody, keepalive: true }).catch(function () {});
    } catch (e) {}
  }
  function rpId() { var h = location.hostname; return h || 'app.kiddietrac.com'; }

  async function available() {
    // Mobile / tablet / APK only — desktop browsers report Windows Hello / Touch ID
    // as a platform authenticator, but we don't offer biometric unlock there.
    if (!isBiometricPlatform()) { lastAvail = 'none:desktop'; return null; }
    // 1) Native plugin (the APK) — preferred.
    var nb = nativeBio();
    if (nb && nb.available) {
      try {
        var r = await nb.available();
        if (!(r && r.available)) lastError = 'native canAuthenticate code ' + (r && r.code);
        lastAvail = (r && r.available) ? ('biometric' + (r.biometry ? ':' + r.biometry : '')) : ('none:' + (r && r.code));
        return (r && r.available) ? 'biometric' : null;
      } catch (e) { lastError = 'native available: ' + ((e && (e.message || e.name)) || e); /* fall through */ }
    }
    // 2) WebAuthn fallback (plain browsers only — unreliable in a WebView).
    try {
      if (!window.PublicKeyCredential || !PublicKeyCredential.isUserVerifyingPlatformAuthenticatorAvailable) { lastError = lastError || 'no biometric API'; lastAvail = 'none:no-api'; return null; }
      var ok = await PublicKeyCredential.isUserVerifyingPlatformAuthenticatorAvailable();
      if (!ok) { lastError = lastError || 'no platform authenticator'; }
      lastAvail = ok ? 'biometric:webauthn' : 'none:no-authenticator';
      return ok ? 'biometric' : null;
    } catch (e) { lastError = 'available: ' + ((e && (e.message || e.name)) || e); lastAvail = 'none:error'; return null; }
  }

  async function nativeVerify(subtitle) {
    var nb = nativeBio();
    try { var r = await nb.authenticate({ title: 'KiddieTrac', subtitle: subtitle || 'Confirm your identity' }); if (!(r && r.success)) lastError = 'native: ' + (r && r.error); return !!(r && r.success); }
    catch (e) { lastError = 'native authenticate: ' + ((e && (e.message || e.name)) || e); return false; }
  }

  async function enroll() {
    lastError = '';
    var result = false;
    var kind = await available();
    if (!kind) { bioDiag('enroll', { ok: false, kind: kind }); return false; }
    var nb = nativeBio();
    if (nb && nb.authenticate) {
      var ok = await nativeVerify('Turn on fingerprint / face unlock');
      if (ok) { set('kt_biometric_enabled', '1'); del('kt_bio_cred'); del('kt_biometric_declined'); persistSession(); lastError = ''; }
      result = ok;
    } else {
      // WebAuthn: register a platform credential (local gate, no server ceremony).
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
        if (cred && cred.rawId) { set('kt_bio_cred', b64url(cred.rawId)); set('kt_biometric_enabled', '1'); del('kt_biometric_declined'); persistSession(); lastError = ''; result = true; }
        else { lastError = 'no credential returned'; }
      } catch (e) { lastError = 'enroll: ' + ((e && (e.message || e.name)) || e); }
    }
    // Confirm the vault actually took — enabled without a token can't unlock.
    if (result && !hasVault()) { lastError = 'no session token to store'; }
    bioDiag('enroll', { ok: result, kind: kind, vault: hasVault() });
    // Only on a real enrolment, and only after it worked: an alert for something that
    // did not happen is worse than no alert, because it teaches people to ignore them.
    if (result) {
      set('kt_bio_reported', '1');       // already told them; no catch-up needed
      report('/me/biometric-enrolled', { catch_up: false });
    }
    return result;
  }

  // Full removal (Settings "turn off", or a dead vault token). Ordinary sign-out
  // NO LONGER calls this — biometric survives sign-out so the login prompt works.
  function disable() {
    var by = ''; try { by = (new Error().stack || '').split('\n').slice(2, 6).join(' <- ').replace(/https?:\/\/[^\s)]*\//g, '').slice(0, 320); } catch (e) {}
    bioDiag('disable', { by: by });
    // Reported BEFORE the local flags go, while there is still something to report, and
    // only if it was actually on — disable() also runs as a cleanup for a dead vault
     // token, and that is not somebody switching the feature off.
    if (get('kt_biometric_enabled') === '1') { report('/me/biometric-revoked', {}); }
    del('kt_biometric_enabled'); del('kt_bio_cred'); del('kt_biometric_declined');
    del('kt_bio_token'); del('kt_bio_user'); del('kt_bio_agency'); del('kt_bio_view');
    del('kt_bio_reported');
    return Promise.resolve(true);
  }
  /* Tell the server. Fire-and-forget, always — biometric unlock has to work on a bad
     connection or with the API down, so a failed report must never block enrolling and
     must never throw into the caller. The server fills in device, IP and time from the
     request itself; sending them from here would be both redundant and forgeable. */
  function report(path, payload) {
    try {
      if (!window.Api || !Api.post || !tok()) { return; }
      Api.post(path, payload || {}).catch(function () {});
    } catch (e) {}
  }

  /* Enrolment that happened BEFORE any of this existed. Reported once per device, ever —
     the marker is local because the question "has this device told the server yet" is a
     property of the device, and asking the server first would cost a round trip on every
     load to learn nothing. */
  function reportCatchUp() {
    try {
      if (get('kt_biometric_enabled') !== '1') { return; }
      if (get('kt_bio_reported') === '1') { return; }
      set('kt_bio_reported', '1');
      report('/me/biometric-enrolled', { catch_up: true });
    } catch (e) {}
  }

  function isEnabled() { return get('kt_biometric_enabled') === '1'; }
  function hasVault() { return !!get('kt_bio_token'); }

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

  // Hard-reload after unlock so app.js/shell/session-timeout all boot
  // authenticated (kt-biometric.js loads last; removing the overlay left them
  // logged-out and the app bounced to login). Validate the token first so a
  // dead vault falls back to password instead of looping.
  function commitUnlock(onDash, ov) {
    var go = function () { if (onDash) location.reload(); else location.replace('/dashboard.html'); };
    var t = tok();
    if (!t) { go(); return; }
    var settled = false;
    var timer = setTimeout(function () { if (!settled) { settled = true; go(); } }, 3500);
    try {
      fetch(apiBase() + '/auth/me', { headers: { 'Authorization': 'Bearer ' + t, 'Accept': 'application/json' } })
        .then(function (r) {
          if (settled) return; settled = true; try { clearTimeout(timer); } catch (e) {}
          bioDiag('validated', { status: r.status });
          if (r.status === 401 || r.status === 419) {
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
    try {
      var id = get('kt_bio_cred'); if (!id) { lastError = 'not enrolled'; return false; }
      var assertion = await navigator.credentials.get({
        publicKey: { challenge: rand(32), rpId: rpId(), allowCredentials: [{ type: 'public-key', id: fromB64url(id), transports: ['internal'] }], userVerification: 'required', timeout: 60000 }
      });
      return !!assertion;
    } catch (e) { lastError = 'signIn: ' + ((e && (e.message || e.name)) || e); return false; }
  }

  window.KT = window.KT || {};
  window.KT.biometric = { available: available, enroll: enroll, signIn: signIn, disable: disable, isEnabled: isEnabled, hasVault: hasVault, verify: signIn, lastError: function () { return lastError; }, showLock: function () { showLock(/dashboard\.html/i.test(location.pathname)); } };

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
  function showLock(onDash) {
    if (document.getElementById('kt-bio-lock')) return;
    var ov = document.createElement('div'); ov.id = 'kt-bio-lock';
    // Navy unlock screen — matches the login + PIN navy theme (per request).
    ov.style.cssText = 'position:fixed;inset:0;z-index:2147482000;background:radial-gradient(120% 55% at 50% 0%, rgba(19,183,204,.18) 0%, rgba(19,183,204,0) 55%), linear-gradient(168deg,#0a1f44 0%,#0c2857 46%,#0a1f44 100%);display:flex;flex-direction:column;align-items:center;justify-content:center;text-align:center;padding:28px;color:#ffffff;font-family:system-ui,-apple-system,sans-serif;';
    ov.innerHTML = '<div style="font-size:64px;line-height:1;margin-bottom:14px;">🔒</div>'
      + '<div style="font-weight:800;font-size:20px;margin-bottom:8px;color:#fff;">Welcome back</div>'
      + '<div style="font-size:14px;color:#93a8c2;margin-bottom:26px;max-width:280px;line-height:1.5;">Unlock KiddieTrac with your fingerprint or face.</div>'
      + '<button id="kt-bio-unlock" style="background:linear-gradient(135deg,#13b7cc,#4bc47a 55%,#8ec73c);color:#05263b;border:none;border-radius:14px;padding:14px 30px;font-size:16px;font-weight:800;box-shadow:0 12px 28px -10px rgba(19,183,204,.7);cursor:pointer;">Unlock</button>'
      + '<button id="kt-bio-pin" hidden style="background:rgba(255,255,255,.08);color:#dbe7f3;border:1px solid rgba(255,255,255,.22);border-radius:12px;padding:11px 24px;margin-top:14px;font-size:14px;font-weight:700;cursor:pointer;">Use PIN instead</button>'
      + '<div id="kt-bio-err" style="font-size:12.5px;color:#ffb4b4;margin-top:16px;min-height:16px;max-width:280px;line-height:1.4;"></div>'
      + '<button id="kt-bio-signout" style="background:transparent;color:#9fb6cf;border:none;margin-top:6px;font-size:13px;font-weight:700;cursor:pointer;">Use password instead</button>';
    document.body.appendChild(ov);
    try {
      if (window.KT && KT.pin && KT.pin.isSet()) {
        var pinBtn = ov.querySelector('#kt-bio-pin');
        pinBtn.hidden = false;
        pinBtn.onclick = function () { ov.remove(); KT.pin.showLock(onDash); };
      }
    } catch (e) {}
    var errEl = ov.querySelector('#kt-bio-err');
    var busy = false;
    var attempt = function () {
      if (busy) return; busy = true;
      if (errEl) errEl.textContent = '';
      signIn().then(function (ok) {
        busy = false;
        bioDiag('unlock-attempt', { ok: ok });
        if (!ok) {
          if (errEl) errEl.textContent = lastError ? ('Couldn’t verify: ' + lastError + ' — tap Unlock to retry.') : '';
          return;
        }
        try { sessionStorage.setItem('kt_bio_session', '1'); } catch (e) {}
        set('kt_bio_unlocked_at', String(Date.now()));
        try {
          ov.innerHTML = '<div style="display:flex;flex-direction:column;align-items:center;gap:22px;padding:0 28px;">'
            + '<img src="/login-wordmark.png" alt="" style="width:min(60vw,220px);height:auto;filter:drop-shadow(0 6px 20px rgba(19,183,204,.28));" onerror="this.style.display=\'none\'">'
            + '<div style="font-size:14px;font-weight:700;color:#a9c2dc;">Signing you in…</div></div>';
        } catch (e) {}
        restoreSession();
        commitUnlock(onDash, ov);
      });
    };
    ov.querySelector('#kt-bio-unlock').onclick = attempt;
    ov.querySelector('#kt-bio-signout').onclick = function () {
      // Fall back to the password this time; biometric stays enrolled.
      ov.remove();
      if (!onDash) injectLoginButton();     // leave a way back to biometric
      if (onDash) location.replace('/index.html');
    };
    attempt();   // auto-fire the prompt on launch
  }

  // ── UI: visible biometric button on the LOGIN page ──────────────────
  // So the prompt isn't a one-shot silent auto-fire — the user can always tap.
  function injectLoginButton() {
    if (document.getElementById('kt-bio-loginbtn')) return;
    if (!isBiometricPlatform()) return;   // desktop never shows the biometric login button
    if (!(isEnabled() && hasVault())) return;
    var form = document.getElementById('loginForm');
    var anchor = document.getElementById('submitBtn');
    var host = form || (anchor && anchor.parentElement);
    if (!host) return;
    var wrap = document.createElement('div');
    wrap.id = 'kt-bio-loginbtn-wrap';
    wrap.style.cssText = 'margin-top:14px;';
    var b = document.createElement('button');
    b.type = 'button'; b.id = 'kt-bio-loginbtn';
    b.style.cssText = 'display:flex;align-items:center;justify-content:center;gap:9px;width:100%;background:#fff;color:#0E7C90;border:1.6px solid #0E7C90;border-radius:13px;padding:14px;font-weight:800;font-size:15px;cursor:pointer;font-family:inherit;';
    b.innerHTML = '<span style="font-size:19px;line-height:1;">🔒</span><span>Fingerprint / Face ID</span>';
    b.onclick = function () { showLock(false); };
    wrap.appendChild(b);
    if (anchor && anchor.parentElement === host) { anchor.insertAdjacentElement('afterend', wrap); }
    else { host.appendChild(wrap); }
  }

  async function tick() {
    var kind = await available(); if (!kind) return;
    var onDashboard = /dashboard\.html/i.test(location.pathname);
    if (tok() && onDashboard) {
      if (isEnabled()) { persistSession(); return; }   // keep the vault token fresh
      // Re-offer after a while instead of suppressing forever on one "Not now".
      var dc = parseInt(get('kt_biometric_declined') || '0', 10) || 0;
      if (dc && (Date.now() - dc) < (7 * 24 * 3600 * 1000)) return;
      card('<div style="font-weight:800;font-size:19px;color:#0D1B2A;">Enable biometric unlock?</div>'
        + '<div style="font-size:13.5px;color:#64748B;margin-top:7px;line-height:1.5;">Next time, open the app straight from your fingerprint or face — no password to type.</div>',
        function () { enroll().then(function (ok) { if (window.KT.toast) window.KT.toast(ok ? '✅' : '⚠️', ok ? 'Biometric sign-in enabled' : 'Could not enable — ' + (lastError || 'try again'), '', '#0E7C90'); }); },
        function () { set('kt_biometric_declined', String(Date.now())); }, 'Enable');
    }
  }

  async function boot() {
    var onDash = /dashboard\.html/i.test(location.pathname);
    var hasSession = false; try { hasSession = !!sessionStorage.getItem('kt_token'); } catch (e) {}
    var unlockedThisSession = false; try { unlockedThisSession = sessionStorage.getItem('kt_bio_session') === '1'; } catch (e) {}
    var recentlyUnlocked = (Date.now() - (parseInt(get('kt_bio_unlocked_at') || '0', 10) || 0)) < 20000;
    // Just explicitly signed out → do NOT auto-prompt biometric (that's what made
    // sign-out take two taps). One-shot: consume the flag; the manual biometric
    // login button is still shown below so the user can opt back in.
    var justSignedOut = false; try { justSignedOut = get('kt_signed_out') === '1'; if (justSignedOut) del('kt_signed_out'); } catch (e) {}

    // Probe availability once (populates lastAvail/lastError for the diag).
    try { await available(); } catch (e) {}
    bioDiag('boot', { onDash: onDash });

    // FRESH LAUNCH with a stored biometric session → show the unlock screen.
    // Not gated on available() — the attempt reports its own error + offers
    // password, so a flaky probe can't silently suppress the prompt.
    if (!justSignedOut && isBiometricPlatform() && isEnabled() && hasVault() && !hasSession && !unlockedThisSession && !recentlyUnlocked) {
      showLock(onDash);
      if (!onDash) injectLoginButton();
      return;
    }
    // Login page, enrolled but (e.g.) already has a session or was recently
    // unlocked → still surface the manual biometric button.
    if (!onDash && isEnabled() && hasVault()) injectLoginButton();

    // Enrolment that predates the reporting endpoint — told once, from a signed-in
    // session so the server knows whose it is. Delayed past the boot rush: it is a
    // background housekeeping call and nothing waits on it.
    setTimeout(reportCatchUp, 4000);

    // Active session on the dashboard → keep the vault fresh + maybe offer enable.
    setTimeout(tick, 2500);
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot); else boot();
})();
