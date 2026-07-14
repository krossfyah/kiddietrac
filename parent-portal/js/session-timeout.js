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

  function getConfig() {
    return {
      idleMs: readOverride('kt_session_idle_ms', DEFAULTS.idleMs),
      absoluteMs: readOverride('kt_session_abs_ms', DEFAULTS.absoluteMs),
      warnMs: DEFAULTS.warnMs,
      checkIntervalMs: DEFAULTS.checkIntervalMs,
    };
  }

  function hasToken() {
    try { return !!sessionStorage.getItem('kt_token'); } catch (e) { return false; }
  }

  // Activity tracking ---------------------------------------------------------

  var lastMouseMove = 0;

  function touchActivity() {
    try { sessionStorage.setItem('kt_last_activity', String(Date.now())); } catch (e) {}
    hideWarningModal();
  }

  function onActivity(e) {
    if (e && e.type === 'mousemove') {
      var now = Date.now();
      if (now - lastMouseMove < DEFAULTS.mouseMoveThrottleMs) return;
      lastMouseMove = now;
    }
    touchActivity();
  }

  // Warning modal -------------------------------------------------------------

  var warningEl = null;
  var countdownTimer = null;

  function showWarningModal(secondsRemaining) {
    if (warningEl) { updateCountdown(secondsRemaining); return; }
    var overlay = document.createElement('div');
    overlay.id = 'kt-session-warning';
    overlay.style.cssText = 'position:fixed;inset:0;background:rgba(0,0,0,.45);display:flex;align-items:center;justify-content:center;z-index:9999;font-family:inherit;';

    var box = document.createElement('div');
    box.style.cssText = 'background:white;padding:24px 28px;border-radius:14px;max-width:400px;width:90%;box-shadow:0 12px 32px rgba(0,0,0,.25);text-align:center;';
    box.innerHTML =
      '<div style="font-size:38px;margin-bottom:8px;">⏱️</div>' +
      '<div style="font-size:18px;font-weight:700;margin-bottom:6px;">You will be signed out soon</div>' +
      '<div id="kt-session-msg" style="font-size:14px;color:#6B7280;margin-bottom:18px;line-height:1.4;">For security, your session ends after a period of inactivity. Pick an option below.</div>' +
      '<div style="display:flex;gap:10px;justify-content:center;">' +
      '  <button id="kt-session-extend" style="background:#1F6080;color:white;border:none;padding:10px 18px;border-radius:8px;font-size:14px;font-weight:600;cursor:pointer;">Stay signed in</button>' +
      '  <button id="kt-session-out" style="background:white;color:#6B7280;border:1px solid #D1D5DB;padding:10px 18px;border-radius:8px;font-size:14px;font-weight:600;cursor:pointer;">Sign out now</button>' +
      '</div>';
    overlay.appendChild(box);
    document.body.appendChild(overlay);
    warningEl = overlay;

    document.getElementById('kt-session-extend').addEventListener('click', function () {
      touchActivity();
    });
    document.getElementById('kt-session-out').addEventListener('click', function () {
      forceLogout('signed out');
    });

    updateCountdown(secondsRemaining);
    countdownTimer = setInterval(function () {
      var msLeft = lastActivity() + getConfig().idleMs - Date.now();
      if (msLeft <= 0) {
        forceLogout('idle');
        return;
      }
      updateCountdown(Math.ceil(msLeft / 1000));
    }, 1000);
  }

  function updateCountdown(secondsRemaining) {
    var msg = document.getElementById('kt-session-msg');
    if (!msg) return;
    var m = Math.floor(secondsRemaining / 60);
    var s = secondsRemaining % 60;
    msg.textContent = 'For security, your session ends after a period of inactivity. ' +
      (m > 0 ? m + ' min ' : '') + s + ' sec remaining.';
  }

  function hideWarningModal() {
    if (countdownTimer) { clearInterval(countdownTimer); countdownTimer = null; }
    if (warningEl && warningEl.parentNode) warningEl.parentNode.removeChild(warningEl);
    warningEl = null;
  }

  // Lifecycle -----------------------------------------------------------------

  function lastActivity() {
    var raw = sessionStorage.getItem('kt_last_activity');
    var n = raw ? parseInt(raw, 10) : 0;
    if (!n) { n = Date.now(); sessionStorage.setItem('kt_last_activity', String(n)); }
    return n;
  }

  function loginAt() {
    var raw = sessionStorage.getItem('kt_login_at');
    var n = raw ? parseInt(raw, 10) : 0;
    if (!n) {
      // No stamp from login screen — set now so the absolute window starts from
      // this load. Existing sessions before this code shipped get a fresh clock.
      n = Date.now();
      sessionStorage.setItem('kt_login_at', String(n));
    }
    return n;
  }

  function forceLogout(reason) {
    hideWarningModal();
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
    } catch (e) {}
    var path = window.location.pathname || '';
    var qs = reason ? ('?signed_out=' + encodeURIComponent(reason)) : '';
    if (path.endsWith('index.html') || path === '/' || path === '') return;
    window.location.href = '/index.html' + qs;
  }

  function tick() {
    if (!hasToken()) return;
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
    var events = ['click', 'keydown', 'mousemove', 'touchstart', 'scroll', 'focus'];
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
      } catch (e) {}
    },
    forceLogout: forceLogout,
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
