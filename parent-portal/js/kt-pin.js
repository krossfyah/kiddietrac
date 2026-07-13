/* ═══════════════════════════════════════════════════════════════════
   KiddieTrac — quick-unlock PIN (2026-07-13).

   Before this, Settings happily let you "set a PIN" and stored a hash that
   NOTHING ever read — the PIN did nothing at all. This makes it real: a PIN
   is an alternative to (or fallback from) the biometric unlock, using the
   same launch-lock model as kt-biometric.js.

   Why it isn't just a hash + a plaintext token: the biometric vault parks the
   session token in localStorage in the clear, which is fine when the OS
   biometric guards it, but a PIN we verify ourselves would guard nothing —
   anyone reading localStorage could skip the check and take the token. So the
   PIN *encrypts* the session: PBKDF2(pin, salt, 210k, SHA-256) → AES-GCM key →
   the token is only recoverable by entering the PIN. A wrong PIN can't decrypt,
   and 5 wrong tries wipe the vault and fall back to password login.

   This is still a LOCAL gate (a 4–6 digit PIN is brute-forceable given the
   device and enough time) — its job is to keep a signed-in app off a borrowed
   phone, not to be a second factor.
   ═══════════════════════════════════════════════════════════════════ */
(function () {
  'use strict';
  if (window.__ktPin) return; window.__ktPin = true;

  var MAX_TRIES = 5;

  function get(k) { try { return localStorage.getItem(k); } catch (e) { return null; } }
  function set(k, v) { try { localStorage.setItem(k, v); } catch (e) {} }
  function del(k) { try { localStorage.removeItem(k); } catch (e) {} }
  function tok() { try { return sessionStorage.getItem('kt_token') || localStorage.getItem('kt_token'); } catch (e) { return null; } }
  function apiBase() { return (window.KT_CONFIG && window.KT_CONFIG.apiBase) || 'https://api.kiddietrac.com/api/v1'; }

  function b64(buf) { var b = new Uint8Array(buf), s = ''; for (var i = 0; i < b.length; i++) s += String.fromCharCode(b[i]); return btoa(s); }
  function unb64(str) { var bin = atob(str), b = new Uint8Array(bin.length); for (var i = 0; i < bin.length; i++) b[i] = bin.charCodeAt(i); return b; }
  function rand(n) { var a = new Uint8Array(n); (window.crypto || window.msCrypto).getRandomValues(a); return a; }

  async function keyFrom(pin, salt) {
    var base = await crypto.subtle.importKey('raw', new TextEncoder().encode(String(pin)), 'PBKDF2', false, ['deriveKey']);
    return crypto.subtle.deriveKey(
      { name: 'PBKDF2', salt: salt, iterations: 210000, hash: 'SHA-256' },
      base, { name: 'AES-GCM', length: 256 }, false, ['encrypt', 'decrypt']
    );
  }

  // The vault: {salt, iv, ct} where ct = AES-GCM(JSON of the session).
  async function seal(pin, payload) {
    var salt = rand(16), iv = rand(12);
    var key = await keyFrom(pin, salt);
    var ct = await crypto.subtle.encrypt({ name: 'AES-GCM', iv: iv }, key, new TextEncoder().encode(JSON.stringify(payload)));
    set('kt_pin_vault', JSON.stringify({ salt: b64(salt), iv: b64(iv), ct: b64(ct) }));
    set('kt_pin_enabled', '1');
    del('kt_pin_tries');
  }
  async function open(pin) {
    var raw = get('kt_pin_vault'); if (!raw) return null;
    var v; try { v = JSON.parse(raw); } catch (e) { return null; }
    try {
      var key = await keyFrom(pin, unb64(v.salt));
      var pt = await crypto.subtle.decrypt({ name: 'AES-GCM', iv: unb64(v.iv) }, key, unb64(v.ct));
      return JSON.parse(new TextDecoder().decode(pt));
    } catch (e) { return null; }   // wrong PIN → auth tag fails → null
  }

  function isSet() { return get('kt_pin_enabled') === '1' && !!get('kt_pin_vault'); }
  function remove() { del('kt_pin_vault'); del('kt_pin_enabled'); del('kt_pin_tries'); del('kt_pin_unlocked_at'); return Promise.resolve(true); }

  // Session snapshot — the same set of keys the biometric vault restores.
  function snapshot() {
    var t = tok(); if (!t) return null;
    var s = { token: t };
    try {
      s.user = sessionStorage.getItem('kt_user') || localStorage.getItem('kt_user') || '';
      s.agency = sessionStorage.getItem('kt_active_agency_id') || '';
      s.view = sessionStorage.getItem('kt_view_as') || '';
    } catch (e) {}
    return s;
  }
  // Re-seal the CURRENT session under the existing PIN. We can't do this without
  // the PIN (it's the key), so we keep the PIN for the life of the tab in memory
  // only — set at enrol/unlock time — and re-seal on each dashboard load so the
  // vault token doesn't go stale.
  var pinInMemory = null;
  function refresh() {
    if (!isSet() || !pinInMemory) return;
    var s = snapshot(); if (!s) return;
    seal(pinInMemory, s).catch(function () {});
  }
  async function enroll(pin) {
    var s = snapshot(); if (!s) return false;
    await seal(pin, s);
    pinInMemory = pin;
    return true;
  }
  function restore(sess) {
    try {
      sessionStorage.setItem('kt_token', sess.token);
      if (sess.user) sessionStorage.setItem('kt_user', sess.user);
      if (sess.agency) sessionStorage.setItem('kt_active_agency_id', sess.agency);
      if (sess.view) sessionStorage.setItem('kt_view_as', sess.view);
      sessionStorage.setItem('kt_login_at', String(Date.now()));
      sessionStorage.setItem('kt_last_activity', String(Date.now()));
      sessionStorage.setItem('kt_pin_session', '1');
    } catch (e) {}
  }

  // ── Lock screen: a real numeric keypad, not a text field ────────────
  function showLock(onDash) {
    if (document.getElementById('kt-pin-lock')) return;
    var ov = document.createElement('div'); ov.id = 'kt-pin-lock';
    ov.style.cssText = 'position:fixed;inset:0;z-index:2147482000;background:linear-gradient(160deg,#0E2A44,#081C41);display:flex;flex-direction:column;align-items:center;justify-content:center;padding:28px;color:#fff;font-family:system-ui,-apple-system,sans-serif;';
    ov.innerHTML =
      '<div style="font-size:52px;line-height:1;margin-bottom:10px;">🔢</div>'
      + '<div style="font-weight:800;font-size:20px;margin-bottom:6px;">Enter your PIN</div>'
      + '<div id="kt-pin-msg" style="font-size:13.5px;opacity:.8;margin-bottom:20px;min-height:18px;">Quick unlock for KiddieTrac</div>'
      + '<div id="kt-pin-dots" style="display:flex;gap:12px;margin-bottom:26px;"></div>'
      + '<div id="kt-pin-pad" style="display:grid;grid-template-columns:repeat(3,72px);gap:14px;"></div>'
      + '<button id="kt-pin-pw" style="background:transparent;color:rgba(255,255,255,.7);border:none;margin-top:22px;font-size:13px;font-weight:700;">Use password instead</button>';
    document.body.appendChild(ov);

    var entry = '';
    var dots = ov.querySelector('#kt-pin-dots');
    var msg = ov.querySelector('#kt-pin-msg');
    var paintDots = function () {
      dots.innerHTML = '';
      for (var i = 0; i < 6; i++) {
        var d = document.createElement('div');
        var filled = i < entry.length;
        d.style.cssText = 'width:13px;height:13px;border-radius:50%;background:' + (filled ? '#fff' : 'rgba(255,255,255,.25)') + ';transition:background .12s;';
        dots.appendChild(d);
      }
    };
    paintDots();

    var pad = ov.querySelector('#kt-pin-pad');
    var keys = ['1', '2', '3', '4', '5', '6', '7', '8', '9', '', '0', '⌫'];
    keys.forEach(function (k) {
      var b = document.createElement('button');
      b.type = 'button'; b.textContent = k;
      b.style.cssText = k
        ? 'width:72px;height:72px;border-radius:50%;border:1px solid rgba(255,255,255,.22);background:rgba(255,255,255,.08);color:#fff;font-size:24px;font-weight:700;cursor:pointer;'
        : 'width:72px;height:72px;visibility:hidden;';
      if (k) b.addEventListener('click', function () { press(k); });
      pad.appendChild(b);
    });

    var busy = false;
    function press(k) {
      if (busy) return;
      if (k === '⌫') { entry = entry.slice(0, -1); paintDots(); return; }
      if (entry.length >= 6) return;
      entry += k; paintDots();
      // 4 is the minimum; try to unlock at 4+ and let 5/6-digit PINs keep typing.
      if (entry.length >= 4) attempt();
    }
    function attempt() {
      var candidate = entry;
      busy = true; msg.textContent = 'Checking…';
      open(candidate).then(function (sess) {
        busy = false;
        if (sess && sess.token) {
          del('kt_pin_tries');
          pinInMemory = candidate;
          set('kt_pin_unlocked_at', String(Date.now()));
          restore(sess);
          commit(onDash);
          return;
        }
        // Only count a failure once the user has stopped typing at a plausible
        // length — otherwise a 6-digit PIN would burn tries at 4 and 5 digits.
        setTimeout(function () {
          if (entry !== candidate) return;          // they kept typing → not a failure
          var tries = (parseInt(get('kt_pin_tries') || '0', 10) || 0) + 1;
          set('kt_pin_tries', String(tries));
          entry = ''; paintDots();
          if (tries >= MAX_TRIES) {
            remove();
            msg.textContent = 'Too many attempts — sign in with your password.';
            setTimeout(function () { location.replace('/index.html?signed_out=pin_locked'); }, 1400);
          } else {
            msg.textContent = 'Wrong PIN — ' + (MAX_TRIES - tries) + ' attempt' + (MAX_TRIES - tries === 1 ? '' : 's') + ' left.';
          }
        }, 700);
      });
    }

    ov.querySelector('#kt-pin-pw').onclick = function () {
      ov.remove();
      if (onDash) location.replace('/index.html');
    };

    // Hardware keyboard (and the APK's soft keyboard, if one is attached).
    ov.tabIndex = -1; ov.focus();
    ov.addEventListener('keydown', function (e) {
      if (/^\d$/.test(e.key)) press(e.key);
      else if (e.key === 'Backspace') press('⌫');
    });
  }

  // Same reasoning as kt-biometric's commitUnlock: every module boots in a
  // logged-out state (this script loads last), so we hard-reload with the
  // restored token in place rather than trying to un-stick a half-booted app.
  // Validate first so a dead vault token falls back to the password instead of
  // looping through the lock screen.
  function commit(onDash) {
    var go = function () { if (onDash) location.reload(); else location.replace('/dashboard.html'); };
    var t = tok(); if (!t) { go(); return; }
    var settled = false;
    var timer = setTimeout(function () { if (!settled) { settled = true; go(); } }, 3500);
    fetch(apiBase() + '/auth/me', { headers: { 'Authorization': 'Bearer ' + t, 'Accept': 'application/json' } })
      .then(function (r) {
        if (settled) return; settled = true; clearTimeout(timer);
        if (r.status === 401 || r.status === 419) {
          remove();
          try { sessionStorage.removeItem('kt_token'); } catch (e) {}
          location.replace('/index.html?signed_out=pin_expired');
        } else go();
      })
      .catch(function () { if (!settled) { settled = true; clearTimeout(timer); go(); } });
  }

  window.KT = window.KT || {};
  window.KT.pin = {
    isSet: isSet, enroll: enroll, remove: remove, verify: open,
    showLock: showLock, refresh: refresh,
    // Settings needs to know whether we can re-seal silently on this page load.
    canRefresh: function () { return !!pinInMemory; },
  };

  function boot() {
    var onDash = /dashboard\.html/i.test(location.pathname);
    var hasSession = false; try { hasSession = !!sessionStorage.getItem('kt_token'); } catch (e) {}
    var unlockedThisSession = false; try { unlockedThisSession = sessionStorage.getItem('kt_pin_session') === '1'; } catch (e) {}
    var recentlyUnlocked = (Date.now() - (parseInt(get('kt_pin_unlocked_at') || '0', 10) || 0)) < 20000;

    // Biometric owns the launch lock when it's enrolled — its lock screen offers
    // "Use PIN instead", so we don't stack two lock screens on top of each other.
    var bioOwns = false;
    try { bioOwns = !!(window.KT && KT.biometric && KT.biometric.isEnabled && KT.biometric.isEnabled() && localStorage.getItem('kt_bio_token')); } catch (e) {}

    if (isSet() && !hasSession && !unlockedThisSession && !recentlyUnlocked && !bioOwns) {
      showLock(onDash);
      return;
    }
    // Signed in on the dashboard → keep the sealed vault's token fresh.
    if (hasSession && onDash) setTimeout(refresh, 2500);
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot); else boot();
})();
