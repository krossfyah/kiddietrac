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
    // A full-width strip BELOW the header rather than a pill floating over it:
    // centred, it sat on top of the user's name and the room pills. As a strip it
    // covers nothing, and there is room to actually say "Clocked in".
    el.style.cssText = 'display:flex;align-items:center;justify-content:center;gap:8px;width:100%;'
      + 'border:none;border-radius:12px;padding:9px 12px;margin:0 0 10px;'
      + 'font-size:13px;font-weight:800;cursor:pointer;font-family:inherit;box-sizing:border-box;';
    el.addEventListener('click', onTap);
    return el;
  }

  // Tapping the strip clocks you OUT — but never silently: clocking out ends the
  // shift that pays them, so it asks first. Tapping when clocked out takes them
  // to the time clock to clock in.
  function onTap() {
    if (!openPunch) { location.hash = '#time-clock'; return; }

    var e = elapsed(openPunch.punched_in_at);
    var msg = "Clock out now? You've been clocked in for " + e.text + '. This ends your shift for today.';

    var go = function () {
      var t = tok();
      fetch(apiBase() + '/staff/punch', {
        method: 'POST',
        headers: { 'Authorization': 'Bearer ' + t, 'Accept': 'application/json', 'Content-Type': 'application/json' },
        body: '{}',
      })
        .then(function (r) { return r.json().catch(function () { return {}; }); })
        .then(function (d) {
          openPunch = null;
          refresh();
          if (KT.toast) KT.toast('👋', 'Clocked out', 'Your shift has been closed. Have a good evening.', '#0E7C90');
        })
        .catch(function () {
          if (KT.toast) KT.toast('⚠️', 'Could not clock out', 'Please use the time clock screen.', '#B91C1C');
          location.hash = '#time-clock';
        });
    };

    if (KT.confirm) KT.confirm(msg, go);
    else if (window.confirm(msg)) go();
  }

  // The strip lives at the top of the screen content, under the header.
  function mount(el) {
    var main = document.getElementById('appMain');
    if (!main) return;
    if (el.parentElement !== main || main.firstElementChild !== el) {
      main.insertBefore(el, main.firstChild);
    }
  }

  function paint() {
    if (!isStaff() || !tok() || window.innerWidth > 600) {
      var old = document.getElementById('kt-clockpill');
      if (old) old.remove();
      return;
    }
    var el = pill();
    mount(el);

    if (!openPunch) {
      el.innerHTML = '<span>⏱</span><span>Not clocked in</span>'
        + '<span style="opacity:.8;font-weight:700;">· tap to clock in</span>';
      el.style.background = '#fff';
      el.style.color = '#64748B';
      el.style.border = '1.5px solid #E2E8F0';
      return;
    }

    var e = elapsed(openPunch.punched_in_at);
    var overdue = e.hours >= LONG_SHIFT_HOURS;
    el.innerHTML = '<span>' + (overdue ? '⚠️' : '⏱') + '</span>'
      + '<span>Clocked in · ' + e.text + '</span>'
      + '<span style="opacity:.85;font-weight:700;">· tap to clock out</span>';
    el.style.background = overdue ? '#B45309' : '#0E7C90';
    el.style.color = '#fff';
    el.style.border = 'none';

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

  // The shell clears #appMain on every screen render, which takes the strip with
  // it — so re-mount often, not just when the clock ticks.
  setInterval(paint, 4000);
  setInterval(refresh, POLL_MS);  // the punch state itself changes rarely
  setTimeout(refresh, 2500);
  window.addEventListener('hashchange', function () {
    setTimeout(paint, 350);       // put the strip back on the new screen
    setTimeout(refresh, 1200);    // clocking in/out happens on #time-clock
  });
})(window);
