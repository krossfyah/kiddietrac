/* ═══════════════════════════════════════════════════════════════════
   KIDDIETRAC v22p31 — Session timeout (compliance / security)

   Two parallel timers run on every authenticated page:

     IDLE_MS      — log out after this many ms of no user activity.
                    Default 30 min. PHIPA/PIPEDA "reasonable" practice
                    for portals carrying child-care records.
     ABSOLUTE_MS  — log out after this many ms since login regardless
                    of activity. Default 12 hours. Caps the worst case
                    where a user leaves a tab open all night.

   A warning modal fires WARN_MS before idle timeout (default 5 min)
   so the user can choose to extend. Activity events (click, keydown,
   mousemove every >1s, touchstart, scroll) refresh the idle timer.

   Storage keys:
     kt_last_activity   — epoch ms, refreshed on activity
     kt_login_at        — epoch ms, stamped at login (or here on first
                          load if missing)
     kt_session_idle_ms — optional per-device override, milliseconds
     kt_session_abs_ms  — optional per-device override, milliseconds
   ═══════════════════════════════════════════════════════════════════ */
(function (window) {
  'use strict';

  /* What floor staff get, and the least they may ever get. */
  var FLOOR_SESSION_MS = 16 * 60 * 60 * 1000;   // 16h
  var FLOOR_MIN_MS = 12 * 60 * 60 * 1000;       // never below a 12h shift

  var DEFAULTS = {
    idleMs: 30 * 60 * 1000,
    absoluteMs: 12 * 60 * 60 * 1000,
    warnMs: 5 * 60 * 1000,
    checkIntervalMs: 30 * 1000,
    mouseMoveThrottleMs: 1000,
  };

  function readOverride(key, fallback) {
    var raw = localStorage.getItem(key);
    if (!raw) return fallback;
    var n = parseInt(raw, 10);
    return (isNaN(n) || n <= 0) ? fallback : n;
  }

  /* EVERY role this person holds, not just the headline one.

     This used to read a single role — `primary_role`, else `roles[0]` — and decide from
     that. Two ways it quietly gave an educator the 30-minute admin timeout:

       · pickPrimaryRole() ranks centre_director ABOVE educator, so anyone who is both
         was treated as an office user and timed out on the floor;
       · if kt_user could not be read at all it returned '' — no match, so the tighter
         default applied to whoever it was.

     Holding an educator or home-visitor role is what matters here, however the account
     is otherwise labelled: it says this person is using the app on the floor all day. */
  function roleSet() {
    var out = [];
    try {
      var va = sessionStorage.getItem('kt_view_as');
      if (va) { return [String(va)]; }
      var u = JSON.parse(sessionStorage.getItem('kt_user') || localStorage.getItem('kt_user') || '{}');
      if (u.primary_role) { out.push(String(u.primary_role)); }
      if (u.role) { out.push(String(u.role)); }
      if (Array.isArray(u.roles)) { out = out.concat(u.roles.map(String)); }
    } catch (e) {}
    return out;
  }

  /* A PARENT'S CLOCKS SURVIVE A RELAUNCH (2026-10-01).

     Both clocks lived in sessionStorage, which a new tab or a reopened browser starts
     empty — while Auth.rememberSession() restores the sign-in itself from localStorage.
     So every reopen came back signed in with a brand-new 30 minutes and 12 hours, and a
     parent used one sign-in for three weeks. For a parent-only account the clocks are
     now kept in localStorage beside the saved sign-in, so reopening is judged against
     the real last activity and the real sign-in time. Staff are deliberately unchanged
     (Anthony, 2026-10-01). */
  var P_ACT = 'kt_p_last_activity', P_LOGIN = 'kt_p_login_at', P_ID = 'kt_p_token_id';
  /* Which sign-in the saved clocks belong to: the token's id (the number before "|"),
     which is not the secret part. Clocks saved for another sign-in are ignored, so a
     passkey or social sign-in — which never calls stampLogin — cannot inherit the last
     session's clock and be signed straight out. */
  function tokenId() {
    try {
      var t = sessionStorage.getItem('kt_token') || localStorage.getItem('kt_token') || '';
      var i = t.indexOf('|');
      return i > 0 ? t.slice(0, i) : '';
    } catch (e) { return ''; }
  }
  function isParentOnly() {
    var r = roleSet();
    if (!r.length) { return false; }
    for (var i = 0; i < r.length; i++) { if (r[i] !== 'guardian') { return false; } }
    return true;
  }
  function persisted(key) {
    try {
      var id = tokenId();
      if (!id || localStorage.getItem(P_ID) !== id) { return 0; }
      var n = parseInt(localStorage.getItem(key) || '', 10);
      return n > 0 ? n : 0;
    } catch (e) { return 0; }
  }
  function persist(key, v) {
    try {
      if (!isParentOnly()) { return; }
      var id = tokenId();
      if (!id) { return; }
      if (localStorage.getItem(P_ID) !== id) {
        // A different sign-in: start its pair cleanly.
        localStorage.setItem(P_ID, id);
        localStorage.removeItem(P_ACT); localStorage.removeItem(P_LOGIN);
      }
      localStorage.setItem(key, String(v));
    } catch (e) {}
  }

  function isFloorStaff() {
    var r = roleSet();
    return r.indexOf('educator') !== -1 || r.indexOf('home_visitor') !== -1;
  }

  function getConfig() {
    /* Floor staff use the app as an all-day tool — a 30-minute idle logout mid-shift is
       wrong for them (a phone in a pocket through nap time should not sign anyone out).
       Keep them in through the day; the absolute cap still ends the session overnight.
       Admins and directors keep the tighter compliance idle. A per-device override
       always wins.

       FLOOR_MIN_MS is a floor, not a default: whatever else changes here, somebody
       working a shift is guaranteed a session longer than the shift. (Anthony asked for
       at least 12 hours, 2026-09-08.) */
    var floorStaff = isFloorStaff();
    var idleDefault = floorStaff ? FLOOR_SESSION_MS : DEFAULTS.idleMs;
    var absDefault = floorStaff ? FLOOR_SESSION_MS : DEFAULTS.absoluteMs;
    var idle = readOverride('kt_session_idle_ms', idleDefault);
    var abs = readOverride('kt_session_abs_ms', absDefault);
    if (floorStaff) {
      // A per-device override must not be able to cut a shift short either.
      if (idle < FLOOR_MIN_MS) { idle = FLOOR_MIN_MS; }
      if (abs < FLOOR_MIN_MS) { abs = FLOOR_MIN_MS; }
    }
    return {
      idleMs: idle,
      absoluteMs: abs,
      warnMs: DEFAULTS.warnMs,
      checkIntervalMs: DEFAULTS.checkIntervalMs,
    };
  }

  function hasToken() {
    try { return !!sessionStorage.getItem('kt_token'); } catch (e) { return false; }
  }

  // Activity tracking ---------------------------------------------------------

  var lastMouseMove = 0, lastX = null, lastY = null;

  function touchActivity() {
    var now = Date.now();
    try { sessionStorage.setItem('kt_last_activity', String(now)); } catch (e) {}
    persist(P_ACT, now);
    hideWarningModal();
  }

  /* ONLY A PERSON COUNTS (2026-11-07, same report). Activity used to include `focus` and `scroll`, which code fires on
     its own: a popup focusing its first button, a list scrolling itself to the newest row, a screen restoring its
     place. A popup opening over an idle screen could therefore restart the idle clock with nobody there. Now:
       - only events the browser marks as real input (isTrusted), so code calling .click() is not a person;
       - no focus / scroll: a person who scrolls also touches, wheels or presses a key, and those count;
       - a mousemove only when the pointer really moved: Chrome sends one when something new appears under a
         mouse that has not moved (a popup opening over a parked cursor). */
  function onActivity(e) {
    if (locked) return;                      // touching the lock screen is not activity
    if (warningEl) return;                   // the warning needs an explicit choice (its buttons call touchActivity)
    if (e && e.isTrusted === false) return;
    if (e && e.type === 'mousemove') {
      var x = e.screenX, y = e.screenY;
      if (x === lastX && y === lastY) return;
      lastX = x; lastY = y;
      var now = Date.now();
      if (now - lastMouseMove < DEFAULTS.mouseMoveThrottleMs) return;
      lastMouseMove = now;
    }
    touchActivity();
  }

  // Warning modal -------------------------------------------------------------

  var warningEl = null;
  var countdownTimer = null;

  /* STAY SIGNED IN, IN THE MIDDLE (2026-10-31, Anthony: "the stay signed in prompt on mobile should be in the middle of
     the screen with a dimmed background"). On a phone kt-modal-guard hung it from the top (top 12px, the house rule for
     tall popups); it now opts out with data-kt-centre-always and kt-dialog-fit centres it over what you can see (above
     the bottom bar and the keyboard). The ONE popup header (navy-to-teal band + teal line; it is not an alarm, so not
     red), a big countdown, Stay signed in / Sign out. A modal: focus stays inside, Esc and taps on the dim do nothing -
     an explicit choice is required. Above every popup (z 2147482000), below the urgent/emergency takeover
     (2147483000) and the forced app update (2147483646). */
  var HEAD_BG = 'linear-gradient(135deg,#1B3A63 0%,#265B7A 50%,#2F8C86 100%)';
  var lastFocus = null;
  /* ABOVE THE FORCED UPDATE (2026-11-05, Anthony: yes to "make the countdown show above the update screen"). The
     "Update required" screen (kt-appupdate.js, #kt-update-required, z 2147483646) covered this warning, so someone left
     on it was signed out with no countdown. While that screen is up the warning goes to the very top; otherwise it
     stays below the emergency takeover. Re-checked every second, as the update screen can arrive mid-countdown. */
  /* ON TOP OF EVERY POPUP (2026-11-07, Anthony: "when the new update to the portal pops up the timer for the idle
     session does not work - full sweep with any pop ups to ensure idle works add guards"). The warning sat at
     2147482000, and some 25 popups sit higher: clock-in, calls, the dial pad, the child card, the photo cropper and
     zoom, form signing, face check, the forced update. Behind any of them the countdown ran unseen and the person was
     signed out with no warning. It now takes the very top, except while the urgent / emergency takeover (#kt-urgent,
     2147483000) is up: an emergency is read first, and the warning sits just under it. Re-checked every second. */
  var Z_TOP = 2147483647, Z_UNDER_URGENT = 2147482999;
  function placeWarning() {
    if (!warningEl) return;
    var urgent = document.getElementById('kt-urgent');
    var z = String(urgent && urgent.isConnected && urgent.offsetWidth > 0 ? Z_UNDER_URGENT : Z_TOP);
    if (warningEl.style.zIndex !== z) warningEl.style.zIndex = z;
  }

  function showWarningModal(secondsRemaining) {
    if (warningEl) { placeWarning(); updateCountdown(secondsRemaining); return; }
    lastFocus = document.activeElement;
    var overlay = document.createElement('div');
    overlay.id = 'kt-session-warning';
    overlay.setAttribute('role', 'alertdialog');
    overlay.setAttribute('aria-modal', 'true');
    overlay.setAttribute('aria-labelledby', 'kt-session-title');
    overlay.setAttribute('aria-describedby', 'kt-session-msg');
    overlay.setAttribute('data-kt-centre-always', '1');
    overlay.setAttribute('data-kt-no-close', '1');
    overlay.setAttribute('data-kt-nomove', '1');   // a timed security prompt is not dragged or resized (kt-dialog-move)
    overlay.style.cssText = 'position:fixed;inset:0;background:rgba(8,17,33,.62);display:flex;align-items:center;justify-content:center;'
      + 'z-index:2147482000;font-family:inherit;box-sizing:border-box;overscroll-behavior:contain;'
      + 'padding:calc(var(--kt-safe-top, env(safe-area-inset-top, 0px)) + 16px) 16px calc(var(--kt-safe-bottom, env(safe-area-inset-bottom, 0px)) + 16px);';

    /* REDESIGN (2026-10-31, Anthony: "the count down timer before signout ... looks sloppy right now"). Was: an emoji
       stopwatch, a bare 40px number over an all-caps caption, two lines of copy, and a primary button that a
       white-label brand's colour rewrite (branding-loader rewrites inline #0E7C90) turned mustard with dark text. Now:
       a clean SVG clock in the house header, a ring that empties as the time runs out (amber in the last 30s - an
       alarm red is for emergencies only), one line of copy, and buttons styled from a stylesheet so no brand rewrite
       can muddy them. Phone: stacked, Stay first. Wider: side by side, Stay on the right. */
    ensureWarningStyles();
    var R = 37, CIRC = 2 * Math.PI * R;
    var box = document.createElement('div');
    box.className = 'kt-idle-card';
    box.innerHTML =
      '<div class="kt-dlg-head kt-idle-head" data-kt-own-head="1" style="background:' + HEAD_BG + ';">' +
      '  <span class="kt-idle-ico" aria-hidden="true"><svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="13" r="8"/><path d="M12 9v4l2.5 2.5M9.5 2.5h5M12 2.5V5"/></svg></span>' +
      '  <div id="kt-session-title" class="kt-idle-title">Are you still there?</div>' +
      '</div>' +
      '<div class="kt-idle-body">' +
      '  <div class="kt-idle-ring" aria-hidden="true">' +
      '    <svg viewBox="0 0 88 88" width="88" height="88"><circle cx="44" cy="44" r="' + R + '" class="kt-idle-track"/>' +
      '      <circle cx="44" cy="44" r="' + R + '" class="kt-idle-prog" id="kt-session-prog" stroke-dasharray="' + CIRC.toFixed(2) + '" stroke-dashoffset="0" transform="rotate(-90 44 44)"/></svg>' +
      '    <div class="kt-idle-time"><span id="kt-session-count"></span><small>remaining</small></div>' +
      '  </div>' +
      '  <div id="kt-session-msg" class="kt-idle-msg"><b>You’ve been inactive for a while.</b>For your security, you’ll be signed out automatically when the timer runs out.</div>' +
      '  <div id="kt-session-sr" class="kt-idle-sr" aria-live="polite"></div>' +
      '  <div class="kt-idle-actions">' +
      '    <button id="kt-session-out" type="button" class="kt-idle-btn kt-idle-ghost">Sign out</button>' +
      '    <button id="kt-session-extend" type="button" class="kt-idle-btn kt-idle-primary">Stay signed in</button>' +
      '  </div>' +
      '</div>';
    overlay.appendChild(box);
    document.body.appendChild(overlay);
    warningEl = overlay;
    placeWarning();

    var stay = document.getElementById('kt-session-extend');
    var out = document.getElementById('kt-session-out');
    stay.addEventListener('click', function () { touchActivity(); });
    out.addEventListener('click', function () { forceLogout('signed out'); });
    // Taps on the dim are ignored on purpose; keys stay inside the card (Tab cycles, Esc does nothing).
    overlay.addEventListener('keydown', function (e) {
      if (e.key === 'Escape') { e.preventDefault(); e.stopPropagation(); return; }
      if (e.key !== 'Tab') return;
      var first = out, last = stay;   // DOM order: Sign out, then Stay signed in
      if (e.shiftKey && document.activeElement === first) { e.preventDefault(); last.focus(); }
      else if (!e.shiftKey && document.activeElement === last) { e.preventDefault(); first.focus(); }
      else if (document.activeElement !== first && document.activeElement !== last) { e.preventDefault(); first.focus(); }
    });
    try { stay.focus({ preventScroll: true }); } catch (e) { try { stay.focus(); } catch (e2) {} }

    updateCountdown(secondsRemaining);
    countdownTimer = setInterval(function () {
      placeWarning();
      var msLeft = lastActivity() + getConfig().idleMs - Date.now();
      if (msLeft <= 0) {
        forceLogout('idle');
        return;
      }
      updateCountdown(Math.ceil(msLeft / 1000));
    }, 1000);
  }

  function updateCountdown(secondsRemaining) {
    var c = document.getElementById('kt-session-count');
    if (!c) return;
    var sec = Math.max(0, secondsRemaining | 0);
    var m = Math.floor(sec / 60), s = sec % 60;
    c.textContent = m + ':' + (s < 10 ? '0' : '') + s;
    // The ring: the share of the warning window still left (a longer preview just shows it full).
    var prog = document.getElementById('kt-session-prog');
    if (prog) {
      var total = Math.max(1, Math.round(getConfig().warnMs / 1000));
      var circ = parseFloat(prog.getAttribute('stroke-dasharray')) || 0;
      prog.setAttribute('stroke-dashoffset', (circ * (1 - Math.min(1, sec / total))).toFixed(2));
    }
    if (warningEl) warningEl.classList.toggle('kt-idle-low', sec <= 30);
    // Screen readers hear it on the minute and in the last 10s, not every second.
    var sr = document.getElementById('kt-session-sr');
    if (sr && (s === 0 || sec <= 10 || !sr.textContent)) {
      sr.textContent = sec > 59 ? (m + ' minute' + (m === 1 ? '' : 's') + ' until you are signed out') : (sec + ' seconds until you are signed out');
    }
  }

  function ensureWarningStyles() {
    if (document.getElementById('kt-idle-style')) return;
    var st = document.createElement('style');
    st.id = 'kt-idle-style';
    st.textContent =
      /* COMPACT AND SEALED (2026-11-02, Anthony's phone screenshot: "look at the buttons ... the screen is also too big
         and the background is not dimmed"). The buttons were the toggle-switch rule (button.kt-sw-btn, a 44x26 pill)
         landing on this popup's old class names - so it has its own kt-idle-* names now, and its button box is set with
         !important because house button rules elsewhere use it too. A 320px card, an 88px ring, both buttons on one row,
         and a darker dim with a soft blur pinned on the overlay itself. */
      '#kt-session-warning{background:rgba(8,17,33,.62)!important;-webkit-backdrop-filter:blur(3px);backdrop-filter:blur(3px);animation:kt-idle-dim .2s ease both}' +
      '@keyframes kt-idle-dim{from{opacity:0}to{opacity:1}}' +
      '@keyframes kt-idle-pop{from{opacity:0;transform:translateY(8px) scale(.98)}to{opacity:1;transform:none}}' +
      '#kt-session-warning .kt-idle-card{background:#fff;border-radius:16px;max-width:320px;width:100%;max-height:100%;overflow:hidden auto;' +
        'box-shadow:0 24px 60px -12px rgba(8,17,33,.5),0 0 0 1px rgba(8,17,33,.06);animation:kt-idle-pop .24s cubic-bezier(.2,.8,.2,1) both;text-align:center}' +
      '#kt-session-warning .kt-idle-head{color:#fff;padding:11px 14px;box-shadow:inset 0 -3px 0 #7FD3CC;display:flex;align-items:center;gap:9px;text-align:left}' +
      '#kt-session-warning .kt-idle-ico{flex:0 0 auto;width:26px;height:26px;border-radius:50%;background:rgba(255,255,255,.16);display:inline-flex;align-items:center;justify-content:center;color:#fff}' +
      '#kt-session-warning .kt-idle-ico svg{width:15px;height:15px}' +
      '#kt-session-warning .kt-idle-title{font-size:15px;font-weight:800;letter-spacing:.1px;color:#fff;line-height:1.2}' +
      '#kt-session-warning .kt-idle-body{padding:16px 16px 14px}' +
      '#kt-session-warning .kt-idle-ring{position:relative;width:88px;height:88px;margin:0 auto 12px}' +
      '#kt-session-warning .kt-idle-ring svg{display:block}' +
      '#kt-session-warning .kt-idle-track{fill:none;stroke:#E8EEF4;stroke-width:6}' +
      '#kt-session-warning .kt-idle-prog{fill:none;stroke:#1593A6;stroke-width:6;stroke-linecap:round;transition:stroke-dashoffset 1s linear,stroke .3s ease}' +
      '#kt-session-warning.kt-idle-low .kt-idle-prog{stroke:#D97706}' +
      '#kt-session-warning .kt-idle-time{position:absolute;inset:0;display:flex;flex-direction:column;align-items:center;justify-content:center}' +
      '#kt-session-warning #kt-session-count{font-size:21px;font-weight:800;color:#0F2340;font-variant-numeric:tabular-nums;letter-spacing:-.3px;line-height:1}' +
      '#kt-session-warning.kt-idle-low #kt-session-count{color:#B45309}' +
      '#kt-session-warning .kt-idle-time small{margin-top:3px;font-size:10px;font-weight:600;color:#7A8A9E;letter-spacing:.2px}' +
      '#kt-session-warning .kt-idle-msg{font-size:13px;color:#5B6B80;line-height:1.45;margin:0 auto 14px;max-width:270px}' +
      '#kt-session-warning .kt-idle-msg b{display:block;font-size:14px;font-weight:700;color:#0F2340;margin-bottom:2px}' +
      '#kt-session-warning .kt-idle-sr{position:absolute;width:1px;height:1px;overflow:hidden;clip:rect(0 0 0 0);white-space:nowrap}' +
      '#kt-session-warning .kt-idle-actions{display:flex;flex-direction:row;gap:8px}' +
      '#kt-session-warning button.kt-idle-btn{flex:1 1 0!important;width:auto!important;min-width:0!important;max-width:none!important;height:auto!important;max-height:none!important;' +
        'min-height:40px!important;padding:0 12px!important;margin:0!important;border-radius:10px!important;font:inherit;font-size:14px!important;font-weight:700!important;' +
        'line-height:1.2!important;white-space:nowrap!important;overflow-wrap:normal!important;word-break:normal!important;cursor:pointer;outline:none;' +
        'transition:background-color .15s ease,box-shadow .15s ease;-webkit-tap-highlight-color:transparent;display:inline-flex!important;align-items:center;justify-content:center}' +
      '#kt-session-warning button.kt-idle-primary{background:#0D6E80!important;color:#fff!important;border:0!important;box-shadow:0 6px 16px -8px rgba(13,110,128,.8)!important}' +
      '#kt-session-warning button.kt-idle-primary:hover{background:#0B5F6E!important}' +
      '#kt-session-warning button.kt-idle-ghost{background:#fff!important;color:#475569!important;border:1px solid #D5DDE6!important;font-weight:600!important;box-shadow:none!important}' +
      '#kt-session-warning button.kt-idle-ghost:hover{background:#F5F7FA!important}' +
      '#kt-session-warning button.kt-idle-btn:focus-visible{box-shadow:0 0 0 3px rgba(21,147,166,.35)!important}' +
      '@media (prefers-reduced-motion:reduce){#kt-session-warning,#kt-session-warning .kt-idle-card{animation:none}#kt-session-warning .kt-idle-prog{transition:none}}';
    (document.head || document.documentElement).appendChild(st);
  }

  function hideWarningModal() {
    if (countdownTimer) { clearInterval(countdownTimer); countdownTimer = null; }
    if (warningEl && warningEl.parentNode) warningEl.parentNode.removeChild(warningEl);
    warningEl = null;
    try { if (lastFocus && lastFocus.focus && document.contains(lastFocus)) lastFocus.focus({ preventScroll: true }); } catch (e) {}
    lastFocus = null;
  }

  // Lifecycle -----------------------------------------------------------------

  function lastActivity() {
    var raw = sessionStorage.getItem('kt_last_activity');
    var n = raw ? parseInt(raw, 10) : 0;
    // A reopened browser: a parent's real last activity, not "now".
    if (!n && isParentOnly()) { n = persisted(P_ACT); if (n) { sessionStorage.setItem('kt_last_activity', String(n)); } }
    if (!n) { n = Date.now(); sessionStorage.setItem('kt_last_activity', String(n)); persist(P_ACT, n); }
    return n;
  }

  function loginAt() {
    var raw = sessionStorage.getItem('kt_login_at');
    var n = raw ? parseInt(raw, 10) : 0;
    if (!n && isParentOnly()) { n = persisted(P_LOGIN); if (n) { sessionStorage.setItem('kt_login_at', String(n)); } }
    if (!n) {
      // No stamp from login screen — set now so the absolute window starts from
      // this load. Existing sessions before this code shipped get a fresh clock.
      n = Date.now();
      sessionStorage.setItem('kt_login_at', String(n));
      persist(P_LOGIN, n);
    }
    return n;
  }

  /* End a parent's browser session on the SERVER too, and say why — the audit log reads
     "post:api/v1/auth/logout" with the reason in its input. Not when a fingerprint/PIN
     lock is enrolled: that vault holds this same token, and revoking it would break the
     unlock that is meant to let them straight back in. */
  function revokeOnServer(reason) {
    try {
      if (!isParentOnly()) { return; }
      if (localStorage.getItem('kt_biometric_enabled') === '1' || localStorage.getItem('kt_pin_enabled') === '1') { return; }
      var t = sessionStorage.getItem('kt_token') || localStorage.getItem('kt_token');
      if (!t) { return; }
      var base = (window.KT && window.KT.API_BASE) || 'https://api.kiddietrac.com/api/v1';
      fetch(base + '/auth/logout', {
        method: 'POST', keepalive: true,
        headers: { 'Authorization': 'Bearer ' + t, 'Accept': 'application/json', 'Content-Type': 'application/json' },
        body: JSON.stringify({ reason: 'timeout_' + (reason || 'idle') }),
      }).catch(function () {});
    } catch (e) {}
  }

  /* LOCK, DON'T SIGN OUT (Anthony, 2026-10-23: "when I launch the app again, it shows
     signing out and then brings me to the sign on page ... having me to wait").

     Coming back to the app after the idle limit used to: fade to "Signing out…", wipe the
     session, load the sign-in page, and only THEN offer the fingerprint, which reloaded
     the portal again. His phone's own telemetry showed exactly that path. Where this
     device has a fingerprint/face or PIN unlock set up, the lock now goes up right here,
     over the screen they were on, and unlocking simply carries on - no sign-out, no page
     loads. Nobody gets past it without the unlock, which is what the timeout is for.
     "Use password instead" on the lock still signs out properly. Without an unlock the
     session ends as before, but straight to the sign-in page (no "Signing out…" stop). */
  var locked = false;
  function onPhone() {
    try {
      var C = window.Capacitor;
      if (C && ((typeof C.isNativePlatform === 'function' && C.isNativePlatform()) || C.isNative)) { return true; }
      return !!(window.__KT_NATIVE || window.__KT_PHONE);
    } catch (e) { return false; }
  }
  function lockInPlace(reason) {
    var p = window.location.pathname || '';
    if (!/dashboard\.html$/i.test(p) || !onPhone()) { return false; }
    var K = window.KT || {};
    var bio = false, pin = false;
    try { bio = !!(K.biometric && K.biometric.isEnabled() && K.biometric.hasVault()); } catch (e) {}
    try { pin = !!(K.pin && K.pin.isSet()); } catch (e) {}
    if (!bio && !pin) { return false; }
    locked = true;
    hideWarningModal();
    try {
      sessionStorage.setItem('kt_locked', '1');          // a reload while locked still shows the lock
      sessionStorage.removeItem('kt_bio_session');
      sessionStorage.removeItem('kt_pin_session');
    } catch (e) {}
    var opts = {
      inPlace: true,
      done: function () {
        locked = false;
        try {
          sessionStorage.removeItem('kt_locked');
          if (reason === 'absolute') { sessionStorage.setItem('kt_login_at', String(Date.now())); persist(P_LOGIN, Date.now()); }
        } catch (e) {}
        touchActivity();
      },
      out: function () {
        locked = false;
        try { sessionStorage.removeItem('kt_locked'); localStorage.setItem('kt_signed_out', '1'); } catch (e) {}
        forceLogoutNow(reason, true);
      },
    };
    try {
      if (bio) { K.biometric.showLock(opts); } else { K.pin.showLock(true, opts); }
    } catch (e) { locked = false; return false; }
    return true;
  }

  function forceLogout(reason) {
    if (locked) return;
    if (reason === 'idle' || reason === 'absolute') {
      if (lockInPlace(reason)) return;
      forceLogoutNow(reason, true);                       // no unlock here: straight to sign-in
      return;
    }
    /* Fade the portal out first (window.KT_out); the session is cleared once it is
       covered, so nothing signed-out ever paints underneath (see COVER FIRST below). */
    var _pp = window.location.pathname || '';
    if (window.KT_out && !forceLogout._fading && !(_pp.endsWith('index.html') || _pp === '/' || _pp === '')) {
      forceLogout._fading = true;
      window.__ktSigningOut = true;
      hideWarningModal();
      window.KT_out.cover(function () { forceLogoutNow(reason); });
      return;
    }
    forceLogoutNow(reason);
  }

  function forceLogoutNow(reason, instant) {
    hideWarningModal();
    if (reason === 'idle' || reason === 'absolute') { revokeOnServer(reason); }
    try { localStorage.removeItem(P_ACT); localStorage.removeItem(P_LOGIN); localStorage.removeItem(P_ID); } catch (e) {}
    /* COVER FIRST, THEN CLEAR (2026-09-29).
       Anthony's APK video: back into the app, the old screen, then "This account has no
       access yet", then the fingerprint lock. Clearing the session under a live page
       lets anything that renders in the next few hundred ms (a resume refresh, a poll)
       paint a signed-out view. The navy cover is the same one the dashboard puts up at
       parse time for the lock (html.kt-bio-pending), so the next thing seen is the lock
       or the login page, with nothing in between. */
    var _p = window.location.pathname || '';
    var _leaving = !(_p.endsWith('index.html') || _p === '/' || _p === '');
    // Only when a navigation follows: on the login page itself nothing would lift it.
    if (_leaving) { try { document.documentElement.classList.add('kt-bio-pending'); } catch (e) {} }
    window.__ktSigningOut = true;
    /* A tab-only session (kt_no_persist) leaves the browser owner's shared sign-in alone (app.js ownsSharedSession,
       2026-11-13). Read before this tab's token goes. */
    var shared = true;
    try { if (window.KT && KT.ownsSharedSession) shared = KT.ownsSharedSession(); } catch (e) {}
    try { if (window.KT && KT.markSignedOut) KT.markSignedOut(); } catch (e) {}   // one sign-out mark (app.js)
    /* Where they were, so a fingerprint unlock can take them back there rather than to
       the home screen. Only the hash, only for an hour, only read by the unlock. */
    try {
      if (shared && window.location.hash && window.location.hash.length > 1) {
        localStorage.setItem('kt_resume_to', JSON.stringify({ hash: window.location.hash, at: Date.now() }));
      }
    } catch (e) {}
    try {
      sessionStorage.removeItem('kt_token');
      sessionStorage.removeItem('kt_user');
      sessionStorage.removeItem('kt_last_activity');
      sessionStorage.removeItem('kt_login_at');
      sessionStorage.removeItem('kt_active_agency_id');
      sessionStorage.removeItem('kt_active_agency_name');
      // These two mark "this app session has already been unlocked", and
      // kt-biometric / kt-pin skip their lock screen when they're set. Leaving
      // them behind after an idle sign-out meant the biometric prompt never came
      // back — the user was dumped on the login page and had to type a password,
      // which is precisely what biometric unlock exists to avoid. The session is
      // over, so the unlock is over with it.
      sessionStorage.removeItem('kt_bio_session');
      sessionStorage.removeItem('kt_pin_session');

      /* ...and the SAME keys in localStorage.

         Auth.rememberSession() restores kt_token (and kt_user with it) from
         localStorage on the next page load, so a session-only clear here meant a
         reload silently put the timed-out session back. Auth.clear() already
         purges both stores on an explicit sign-out; the automatic timeout must not
         be weaker than the manual one.

         Biometric / PIN unlock keep their own bearer token under separate
         kt_bio_* / kt_pin_* keys, so clearing these does not disarm them. */
      if (shared) {
        localStorage.removeItem('kt_token');
        localStorage.removeItem('kt_user');
        localStorage.removeItem('kt_active_agency_id');
        localStorage.removeItem('kt_active_agency_name');
      }
    } catch (e) {}
    var path = window.location.pathname || '';
    var qs = reason ? ('?signed_out=' + encodeURIComponent(reason)) : '';
    if (path.endsWith('index.html') || path === '/' || path === '') return;
    if (window.KT_out) window.KT_out.go('/index.html' + qs, !!instant, instant ? { instant: true } : null); else window.location.href = '/index.html' + qs;
  }

  // Make sure a parent's saved pair exists for THIS sign-in (sign-in time first: a new
  // token id resets the pair, then activity joins it).
  function syncPersisted() {
    if (!isParentOnly()) { return; }
    if (!persisted(P_LOGIN)) { persist(P_LOGIN, loginAt()); }
    if (!persisted(P_ACT)) { persist(P_ACT, lastActivity()); }
  }

  function tick() {
    if (!hasToken() || locked) return;
    try { syncPersisted(); } catch (e) {}
    var cfg = getConfig();
    var now = Date.now();
    var idleFor = now - lastActivity();
    var sessionAge = now - loginAt();

    if (sessionAge >= cfg.absoluteMs) {
      forceLogout('absolute');
      return;
    }
    if (idleFor >= cfg.idleMs) {
      forceLogout('idle');
      return;
    }
    var timeToIdleLogout = cfg.idleMs - idleFor;
    if (timeToIdleLogout <= cfg.warnMs) {
      showWarningModal(Math.ceil(timeToIdleLogout / 1000));
    } else {
      hideWarningModal();
    }
  }

  function bindActivity() {
    var events = ['pointerdown', 'click', 'keydown', 'mousemove', 'touchstart', 'wheel'];
    events.forEach(function (ev) {
      window.addEventListener(ev, onActivity, { passive: true, capture: true });
    });
  }

  function init() {
    if (hasToken()) {
      // First page-load after the v22p31 ship may not have these keys — seed them
      lastActivity();
      loginAt();
    }
    bindActivity();
    setInterval(tick, getConfig().checkIntervalMs);
    /* CHECK ON THE WAY BACK IN (2026-09-29). The clock only ran every 30 seconds, so an
       app resumed after a long idle showed its old screen, refreshed it, and only then
       discovered the session had expired. Resuming is the moment to decide, before
       anything repaints. The native web view reliably reports appStateChange; browsers
       report visibilitychange and pageshow. */
    var onBack = function () { try { tick(); } catch (e) {} };
    document.addEventListener('visibilitychange', function () { if (!document.hidden) { onBack(); } });
    window.addEventListener('pageshow', onBack);
    try {
      var App = window.Capacitor && window.Capacitor.Plugins && window.Capacitor.Plugins.App;
      if (App && App.addListener) { App.addListener('appStateChange', function (st) { if (st && st.isActive) { onBack(); } }); }
    } catch (e) {}
    // Run once immediately to surface stale absolute-timeout cases at load.
    tick();
  }

  // Public surface for the login screen + admin "session settings"
  window.KT = window.KT || {};
  window.KT.SessionTimeout = {
    stampLogin: function () {
      var now = Date.now();
      try {
        sessionStorage.setItem('kt_login_at', String(now));
        sessionStorage.setItem('kt_last_activity', String(now));
        // A fresh sign-in starts a fresh saved pair (tied to its token id on first use).
        localStorage.removeItem(P_ID); localStorage.removeItem(P_LOGIN); localStorage.removeItem(P_ACT);
      } catch (e) {}
    },
    forceLogout: forceLogout,
    previewWarning: function (sec) { showWarningModal(sec || 120); },
    isLocked: function () { return locked; },
    config: getConfig,
    setIdleMinutes: function (minutes) {
      if (minutes < 1) return;
      localStorage.setItem('kt_session_idle_ms', String(minutes * 60 * 1000));
    },
    setAbsoluteHours: function (hours) {
      if (hours < 1) return;
      localStorage.setItem('kt_session_abs_ms', String(hours * 60 * 60 * 1000));
    },
  };

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})(window);
