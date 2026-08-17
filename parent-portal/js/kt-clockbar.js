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
  try { window.__KT_CLK_VER = 'clkfix'; } catch (e) {}   // load-time stamp: proves THIS (new) file executed, even if paint() hasn't run

  var KT = window.KT || (window.KT = {});
  var POLL_MS = 60000;          // re-check the punch state once a minute
  var LONG_SHIFT_HOURS = 9;     // past this, start nudging them to clock out
  var openPunch = null;         // the current open punch, if any
  var nudgedAt = 0;

  function tok() { try { return sessionStorage.getItem('kt_token') || localStorage.getItem('kt_token'); } catch (e) { return null; } }
  function apiBase() { return (window.KT_CONFIG && window.KT_CONFIG.apiBase) || 'https://api.kiddietrac.com/api/v1'; }

  function isStaff() {
    try {
      var u = JSON.parse(sessionStorage.getItem('kt_user') || localStorage.getItem('kt_user') || '{}');
      var roles = Array.isArray(u.roles) ? u.roles.slice() : [];
      if (u.primary_role) roles.push(u.primary_role);   // some accounts have empty roles[] but a primary_role
      var va = sessionStorage.getItem('kt_view_as') || '';
      // A view-as override ONLY applies to a platform_admin (this mirrors the shell's
      // Roles.primaryRoleOf). The old code did `roles = [va]` unconditionally, so a
      // STALE kt_view_as — e.g. left in sessionStorage after previewing a parent
      // account, and surviving a logout/login — flipped a real educator OUT of
      // "staff" and hid the clock bar. Only honour va when the user actually holds
      // the platform_admin role; otherwise the real roles win.
      if (va && roles.indexOf('platform_admin') > -1) {
        return va === 'educator' || va === 'centre_director';
      }
      return roles.indexOf('educator') > -1 || roles.indexOf('centre_director') > -1;
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
    el.style.cssText = 'display:flex;align-items:center;gap:12px;width:100%;text-align:left;'
      + 'border:none;border-radius:16px;padding:13px 14px;margin:0 0 12px;'
      + 'cursor:pointer;font-family:inherit;box-sizing:border-box;box-shadow:0 8px 20px -10px rgba(15,23,42,.45);';
    el.addEventListener('click', onTap);
    return el;
  }

  function punchPost() {
    var t = tok();
    return fetch(apiBase() + '/staff/punch', {
      method: 'POST',
      headers: { 'Authorization': 'Bearer ' + t, 'Accept': 'application/json', 'Content-Type': 'application/json' },
      body: JSON.stringify({ source: 'mobile' }),
    }).then(function (r) { return r.json().catch(function () { return {}; }); });
  }

  // Tapping the strip toggles the clock. Both directions update the UI OPTIMISTICALLY
  // (flip the strip + glow the instant you tap) and POST in the background, so the
  // status changes immediately instead of waiting on a round-trip + re-fetch — the
  // "takes forever to show clocked in" complaint. Clocking out still confirms first
  // (it ends the paid shift). If the POST fails we revert.
  function onTap() {
    if (!openPunch) {
      // CLOCK IN — instant. Flip to "Clocked in · 0m" now, reconcile after the POST.
      var nowUtc = new Date().toISOString().slice(0, 19).replace('T', ' ');   // server (UTC) format
      openPunch = { id: 0, punched_in_at: nowUtc };
      paint();
      if (KT.clockGlow) try { KT.clockGlow.update(); } catch (e) {}
      // Dimmed spinner on the strip while the punch confirms with the server.
      var pill = document.getElementById('kt-clockpill');
      var doneBusy = (KT.busy && pill) ? KT.busy(pill) : function () {};
      punchPost()
        .then(function (d) {
          doneBusy();
          if (d && d.action === 'in') { if (d.id) openPunch.id = d.id; if (KT.toast) KT.toast('✅', 'Clocked in', 'Your shift has started — have a great day!', '#16A34A'); }
          else if (d && d.action === 'out') { openPunch = null; paint(); }   // server said we were already in → now out
          refresh();
        })
        .catch(function () { doneBusy(); openPunch = null; paint(); if (KT.toast) KT.toast('⚠️', 'Could not clock in', 'Please try again in a moment.', '#B91C1C'); });
      return;
    }

    var go = function () {
      var prev = openPunch;
      openPunch = null; paint();   // instant "Clocked out"
      if (KT.clockGlow) try { KT.clockGlow.update(); } catch (e) {}
      // Same dimmed spinner as clock-in while the punch confirms with the server.
      var pill = document.getElementById('kt-clockpill');
      var doneBusy = (KT.busy && pill) ? KT.busy(pill) : function () {};
      punchPost()
        .then(function (d) { doneBusy(); refresh(); if (KT.toast) KT.toast('👋', 'Clocked out', 'Your shift has been closed. Have a good evening.', '#0E7C90'); })
        .catch(function () { doneBusy(); openPunch = prev; paint(); if (KT.toast) KT.toast('⚠️', 'Could not clock out', 'Please try again.', '#B91C1C'); });
    };
    var normalConfirm = function () {
      var e = elapsed(openPunch.punched_in_at);
      ktConfirmThen({
        title: 'Clock out now?',
        message: "You've been clocked in for " + e.text + '. This ends your shift for today.',
        confirmLabel: 'Yes, clock out',
        tone: 'default',
      }, go);
    };
    // SAFETY: never let an educator clock out while children are still signed in
    // without a loud warning — they must be handed over or checked out first.
    get('/provider/present-count')
      .then(function (d) {
        var n = (d && d.present) || 0;
        if (n > 0) { showChildrenStillInWarning(n, go); return; }
        normalConfirm();
      })
      .catch(function () { normalConfirm(); });
  }

  // Big, red, impossible-to-miss warning shown when clocking out with children in.
  function showChildrenStillInWarning(n, onProceed) {
    var ov = document.createElement('div');
    ov.style.cssText = 'position:fixed;inset:0;z-index:2147483000;background:rgba(90,10,10,.6);display:flex;align-items:center;justify-content:center;padding:20px;box-sizing:border-box;';
    ov.innerHTML = '<div style="background:#fff;border-radius:18px;max-width:390px;width:100%;overflow:hidden;box-shadow:0 22px 60px rgba(0,0,0,.45);">'
      + '<div style="background:linear-gradient(135deg,#DC2626,#991B1B);color:#fff;padding:22px 20px;text-align:center;">'
      + '<div style="font-size:46px;line-height:1;">⚠️</div>'
      + '<div style="font-size:20px;font-weight:900;margin-top:8px;line-height:1.2;">' + n + (n === 1 ? ' child is' : ' children are') + ' still checked in</div>'
      + '</div>'
      + '<div style="padding:18px 20px;">'
      + '<p style="margin:0 0 16px;font-size:14px;color:#334155;line-height:1.55;">Before you clock out, please make sure every child has been <b>signed out or handed to another educator</b>. Leaving while a child is still checked in is a serious safety risk.</p>'
      + '<button id="kt-cow-back" type="button" style="display:block;width:100%;background:linear-gradient(135deg,#16A34A,#15803D);color:#fff;border:none;border-radius:12px;padding:14px;font-size:15px;font-weight:800;cursor:pointer;">Go back and check the room</button>'
      + '<button id="kt-cow-out" type="button" style="display:block;width:100%;background:none;color:#B91C1C;border:none;border-radius:10px;padding:12px;font-size:13px;font-weight:700;cursor:pointer;margin-top:6px;">Clock out anyway</button>'
      + '</div></div>';
    document.body.appendChild(ov);
    var close = function () { if (ov.parentNode) ov.parentNode.removeChild(ov); };
    ov.querySelector('#kt-cow-back').addEventListener('click', function () { if (KT.popOverlay) { try { KT.popOverlay(ov); return; } catch (e) {} } close(); });
    ov.querySelector('#kt-cow-out').addEventListener('click', function () { close(); onProceed(); });
    if (KT.pushOverlay) { try { KT.pushOverlay(ov, close); } catch (e) {} }
  }

  // KT.confirm returns a PROMISE — it does not take a callback. Passing one meant
  // the action never ran: the confirm box appeared, you pressed Yes, and nothing
  // happened. This wraps both shapes safely.
  async function ktConfirmThen(message, onYes) {
    try {
      if (window.KT && KT.confirm) {
        var r = KT.confirm(message);
        if (r && typeof r.then === 'function') { r.then(function (ok) { if (ok) onYes(); }); return; }
        return;   // a non-promise KT.confirm would already have handled it
      }
    } catch (e) {}
    if (await KT.confirm(message)) onYes();
  }


  // The strip lives at the top of the screen content, under the header.
  function mount(el) {
    var main = document.getElementById('appMain');
    if (!main) return;
    if (el.parentElement !== main || main.firstElementChild !== el) {
      main.insertBefore(el, main.firstChild);
    }
  }

  function currentHash() { return (location.hash || '').replace('#', '').split('?')[0]; }
  function onHome() {
    var h = currentHash();
    // Include 'today' — the educator overview (screen-educator.js) is registered for
    // BOTH #today AND #dashboard, so a clock strip that only recognised 'dashboard'
    // vanished whenever the educator was on the identical #today view. Plus 'home'
    // (the tile launcher, where Home now lands). These are the screens an educator
    // arrives/leaves on, which is exactly where the clock strip belongs.
    return h === '' || h === 'dashboard' || h === 'home' || h === 'today';
  }

  function paint() {
    // Home screen only. A clock strip pinned to the top of every section (chat,
    // observations, a child's record) is clutter — the educator checks the clock
    // when they arrive and when they leave, and home is where they land.
    // Mobile only: ≤768 in a browser, OR always in the native APK (whose WebView can
    // report a width >768). Was `> 600`, which the inflated APK width could trip.
    // Show on DESKTOP too now (was mobile-only via a width gate): an educator on a
    // computer had no clock indicator at all — the admin top bar doesn't render for
    // them. The strip is prominent + in-your-face, matching the APK.
    try { window.__KT_CLK = currentHash() + '/st' + (isStaff() ? 1 : 0) + '/oh' + (onHome() ? 1 : 0); } catch (e) {}
    if (!isStaff() || !tok() || !onHome()) {
      var old = document.getElementById('kt-clockpill');
      if (old) old.remove();
      return;
    }
    var el = pill();
    mount(el);

    // Green = on the clock. Amber = off the clock (and amber is a nudge, not an
    // error — you're not working yet, or you've finished).
    var ICON = 'flex:0 0 auto;width:42px;height:42px;border-radius:50%;background:rgba(255,255,255,.22);display:flex;align-items:center;justify-content:center;font-size:22px;';
    var MID = 'flex:1;min-width:0;display:flex;flex-direction:column;line-height:1.25;';
    var CHIP = 'flex:0 0 auto;background:#fff;font-size:13px;font-weight:800;padding:9px 15px;border-radius:11px;white-space:nowrap;';
    if (!openPunch) {
      el.innerHTML =
        '<span style="' + ICON + '">⏱</span>'
        + '<span style="' + MID + '">'
        +   '<span style="font-size:15px;font-weight:800;">You\'re not clocked in</span>'
        +   '<span style="font-size:12px;font-weight:600;opacity:.9;">Tap to start your shift</span>'
        + '</span>'
        + '<span style="' + CHIP + 'color:#B45309;">Clock in</span>';
      el.style.background = '#F59E0B';
      el.style.color = '#fff';
      el.style.border = 'none';
      return;
    }

    var e = elapsed(openPunch.punched_in_at);
    var overdue = e.hours >= LONG_SHIFT_HOURS;
    var inTime = '';
    try { inTime = parseTs(openPunch.punched_in_at).toLocaleTimeString([], { hour: 'numeric', minute: '2-digit' }); } catch (x) {}
    el.innerHTML =
      '<span style="' + ICON + '">' + (overdue ? '⚠️' : '⏱') + '</span>'
      + '<span style="' + MID + '">'
      +   '<span style="font-size:15px;font-weight:800;">Clocked in · ' + e.text + '</span>'
      +   '<span style="font-size:12px;font-weight:600;opacity:.9;">' + (overdue ? 'Long shift — please clock out' : (inTime ? 'Since ' + inTime : 'On the clock')) + '</span>'
      + '</span>'
      + '<span style="' + CHIP + 'color:' + (overdue ? '#B45309' : '#15803D') + ';">Clock out</span>';
    el.style.background = overdue ? '#B45309' : '#16A34A';
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

  KT.clockBar = {
    refresh: refresh,
    isClockedIn: function () { return !!openPunch; },
    clockIn: function () { if (!openPunch) onTap(); },
  };

  // Gate an action on being clocked in. Returns true when clocked in; otherwise
  // shows a dimmed "Clock in first" warning and returns false — so an educator
  // can't check children in/out until their own shift has started.
  function showClockGate() {
    if (document.getElementById('kt-clockgate')) return;
    if (!document.getElementById('kt-cg-fade-kf')) { var kf = document.createElement('style'); kf.id = 'kt-cg-fade-kf'; kf.textContent = '@keyframes kt-cg-fade{from{opacity:0}to{opacity:1}}'; document.head.appendChild(kf); }
    var ov = document.createElement('div');
    ov.id = 'kt-clockgate';
    ov.style.cssText = 'position:fixed;inset:0;z-index:14050;background:rgba(8,17,33,.55);display:flex;align-items:center;justify-content:center;padding:24px;animation:kt-cg-fade .18s ease both;';
    var card = document.createElement('div');
    card.style.cssText = 'background:#fff;border-radius:18px;max-width:340px;width:100%;padding:24px 22px;text-align:center;box-shadow:0 24px 60px rgba(0,0,0,.35);';
    card.innerHTML = '<div style="font-size:46px;line-height:1;margin-bottom:10px;">\u23F0</div>'
      + '<div style="font-size:18px;font-weight:800;color:#0D1B2A;margin-bottom:6px;">Clock in first</div>'
      + '<div style="font-size:14px;color:#475569;line-height:1.5;margin-bottom:18px;">You need to be clocked in before you can check children in or out. This keeps attendance and ratio records accurate.</div>';
    var row = document.createElement('div'); row.style.cssText = 'display:flex;gap:10px;';
    var no = document.createElement('button'); no.type = 'button'; no.textContent = 'Not now';
    no.style.cssText = 'flex:1;background:#F1F5F9;color:#475569;border:none;border-radius:12px;padding:13px;font-size:14px;font-weight:700;cursor:pointer;';
    var yes = document.createElement('button'); yes.type = 'button'; yes.textContent = 'Clock in now';
    yes.style.cssText = 'flex:1;background:#16A34A;color:#fff;border:none;border-radius:12px;padding:13px;font-size:14px;font-weight:800;cursor:pointer;';
    var close = function () { if (ov.parentNode) ov.parentNode.removeChild(ov); };
    no.addEventListener('click', close);
    yes.addEventListener('click', function () { close(); try { if (!openPunch) onTap(); } catch (e) {} });
    row.appendChild(no); row.appendChild(yes);
    card.appendChild(row); ov.appendChild(card);
    ov.addEventListener('click', function (e) { if (e.target === ov) close(); });
    document.body.appendChild(ov);
    if (KT.pushOverlay) KT.pushOverlay(ov, close);
  }
  // True when clocked in; otherwise shows the warning and returns false.
  KT.requireClockedIn = function () { if (openPunch) return true; showClockGate(); return false; };

  // The shell clears #appMain on every screen render, which takes the strip with it.
  // Re-mount the INSTANT the screen content changes via a MutationObserver — so the
  // strip appears immediately with the screen, instead of "popping in" a few seconds
  // later when the old 4s tick finally fired. paint() renders "Clocked out" right away
  // (default state); refresh() then corrects it to "Clocked in" if there's an open punch.
  var _clkSched = false;
  function scheduleClk() {
    if (_clkSched) return; _clkSched = true;
    requestAnimationFrame(function () { _clkSched = false; paint(); });
  }
  (function watchMain(tries) {
    var m = document.getElementById('appMain');
    if (m) { try { new MutationObserver(scheduleClk).observe(m, { childList: true }); } catch (e) {} scheduleClk(); return; }
    if (tries > 80) return;
    requestAnimationFrame(function () { watchMain(tries + 1); });
  })(0);
  paint();                          // immediate first paint (no wait)
  setInterval(paint, 4000);         // safety re-mount
  setInterval(refresh, POLL_MS);    // the punch state itself changes rarely
  setTimeout(refresh, 300);         // fetch the punch state promptly (was 2500) so in/out is right fast
  window.addEventListener('hashchange', function () {
    scheduleClk();                  // put the strip back on the new screen immediately
    setTimeout(refresh, 800);       // clocking in/out happens on #time-clock
  });
})(window);
