/* ═══════════════════════════════════════════════════════════════════
   KiddieTrac — the educator's clock (2026-07-13).

   A pill at the top of the screen showing how long they have been clocked in
   ("⏱ 6h 12m"), tappable straight through to the time clock. If they are NOT
   clocked in, it says so — quietly, but visibly.

   Educators forget to clock OUT far more often than they forget to clock in:
   they hand over, walk out, and the clock runs all night, which then has to be
   corrected by hand and distorts payroll. So once they are past the end of a
   normal shift, or the centre has closed, the pill turns amber and nags.

   The clock is /staff/punch (time_punches) — the same source as My hours.
   ═══════════════════════════════════════════════════════════════════ */
(function (window) {
  'use strict';
  if (window.__ktClockBar) return; window.__ktClockBar = true;

  var KT = window.KT || (window.KT = {});
  var POLL_MS = 60000;          // re-check the punch state once a minute
  var LONG_SHIFT_HOURS = 9;     // past this, start nudging them to clock out
  var openPunch = null;         // the current open punch, if any
  var nudgedAt = 0;

  function tok() { try { return sessionStorage.getItem('kt_token') || localStorage.getItem('kt_token'); } catch (e) { return null; } }
  function apiBase() { return (window.KT_CONFIG && window.KT_CONFIG.apiBase) || 'https://api.kiddietrac.com/api/v1'; }

  function isStaff() {
    try {
      var va = sessionStorage.getItem('kt_view_as') || '';
      var roles;
      if (va) roles = [va];
      else {
        var u = JSON.parse(sessionStorage.getItem('kt_user') || localStorage.getItem('kt_user') || '{}');
        roles = u.roles || [];
      }
      return ['educator', 'centre_director'].some(function (r) { return roles.indexOf(r) > -1; });
    } catch (e) { return false; }
  }

  function get(path) {
    var t = tok(); if (!t) return Promise.resolve(null);
    return fetch(apiBase() + path, { headers: { 'Authorization': 'Bearer ' + t, 'Accept': 'application/json' } })
      .then(function (r) { return r.ok ? r.json() : null; })
      .catch(function () { return null; });
  }

  // MySQL timestamps are UTC with no zone marker — see kt-tz.js.
  function parseTs(ts) {
    if (KT.parseTs) return KT.parseTs(ts);
    return new Date(String(ts).replace(' ', 'T') + 'Z');
  }

  function elapsed(since) {
    var ms = Date.now() - parseTs(since).getTime();
    if (isNaN(ms) || ms < 0) ms = 0;
    var mins = Math.floor(ms / 60000);
    var h = Math.floor(mins / 60), m = mins % 60;
    return { hours: ms / 3600000, text: (h ? h + 'h ' : '') + m + 'm' };
  }

  function pill() {
    var el = document.getElementById('kt-clockpill');
    if (el) return el;
    el = document.createElement('button');
    el.id = 'kt-clockpill';
    el.type = 'button';
    el.setAttribute('aria-label', 'Time clock');
    // Centred: the logo owns the top-left and the ⚙️/QR buttons own the top-right,
    // so the middle is the only place it doesn't collide with something.
    el.style.cssText = 'position:fixed;top:calc(env(safe-area-inset-top,0px) + 10px);left:50%;transform:translateX(-50%);'
      + 'z-index:9440;display:inline-flex;align-items:center;gap:6px;border:none;border-radius:999px;padding:7px 12px;'
      + 'font-size:12.5px;font-weight:800;cursor:pointer;box-shadow:0 2px 10px rgba(15,23,42,.18);'
      + 'font-family:system-ui,-apple-system,sans-serif;white-space:nowrap;';
    el.addEventListener('click', function () { location.hash = '#time-clock'; });
    document.body.appendChild(el);
    return el;
  }

  function paint() {
    if (!isStaff() || !tok() || window.innerWidth > 600) {
      var old = document.getElementById('kt-clockpill');
      if (old) old.remove();
      return;
    }
    var el = pill();

    if (!openPunch) {
      el.textContent = '⏱ Not clocked in';
      el.style.background = '#fff';
      el.style.color = '#64748B';
      return;
    }

    var e = elapsed(openPunch.punched_in_at);
    var overdue = e.hours >= LONG_SHIFT_HOURS;
    el.textContent = (overdue ? '⚠️ ' : '⏱ ') + e.text;
    el.style.background = overdue ? '#B45309' : '#0E7C90';
    el.style.color = '#fff';

    // Past a long shift, say it out loud — once an hour, not every minute.
    if (overdue && Date.now() - nudgedAt > 3600000) {
      nudgedAt = Date.now();
      if (KT.toast) {
        KT.toast('⏰', "Don't forget to clock out",
          "You've been clocked in for " + e.text + '. Tap the timer when you finish.', '#B45309');
      }
    }
  }

  function refresh() {
    if (!isStaff() || !tok()) return;
    get('/staff/punches/me').then(function (d) {
      var rows = (d && d.punches) || [];
      openPunch = rows.find(function (p) { return !p.punched_out_at; }) || null;
      paint();
    });
  }

  KT.clockBar = { refresh: refresh, isClockedIn: function () { return !!openPunch; } };

  setInterval(paint, 30000);      // keep the elapsed time honest
  setInterval(refresh, POLL_MS);  // and the punch state
  setTimeout(refresh, 2500);
  window.addEventListener('hashchange', function () {
    // Clocking in/out happens on #time-clock — re-check as soon as they leave it.
    setTimeout(refresh, 1200);
  });
})(window);
