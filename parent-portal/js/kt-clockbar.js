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
  try { window.__KT_CLK_VER = 'breaks1105'; } catch (e) {}   // load-time stamp: proves THIS (new) file executed, even if paint() hasn't run

  var KT = window.KT || (window.KT = {});
  var POLL_MS = 60000;          // re-check the punch state once a minute
  var LONG_SHIFT_HOURS = 9;     // past this, start nudging them to clock out
  var openPunch = null;         // the current open punch, if any
  /* On lunch / a break (2026-11-05, App\Support\StaffBreaks): the shift row is closed but the
     day is not over. {kind, label, paid, started_at} from /staff/punches/me, else null. */
  var onBreak = null;
  /* Has a check ever actually SUCCEEDED? Until it has, we do not know whether this
     person is on the clock, and saying "You're not clocked in" is a claim we have not
     earned. Cassandra Schnarr reported being shown as clocked out while punch #334 was
     open in the database all along — see refresh(). */
  var punchKnown = false;
  /* The check has been tried and failed at least once — as opposed to simply not having
     answered yet. The two look the same on screen otherwise, and only one of them is a
     reason to offer a retry. */
  var checkFailed = false;
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

  /* WHY THE CHECK FAILED, RATHER THAN JUST THAT IT DID.

     This returned null for every failure — a 401, a 500, a dropped connection, a fetch
     wrapper throwing — so a strip stuck on "Checking your shift…" was undiagnosable from
     the outside and, because that state is deliberately not tappable, the educator had no
     way to clock in at all. Sukhman Malhi, 2026-09-16, 9am: stuck there while five
     colleagues clocked in from the same build, with /staff/punches/me answering 200 in
     150ms to her own token from the server side. Whatever broke was on the device, and
     nothing anywhere recorded a word about it.

     The reason is kept so the strip can show it and so kt-crash-report picks it up in the
     failed-HTTP breadcrumbs — the next time this happens it should be answerable. */
  var lastCheckError = null;

  function get(path) {
    var t = tok();
    if (!t) { lastCheckError = 'not signed in'; return Promise.resolve(null); }
    return fetch(apiBase() + path, { headers: { 'Authorization': 'Bearer ' + t, 'Accept': 'application/json' } })
      .then(function (r) {
        if (!r.ok) { lastCheckError = 'HTTP ' + r.status; return null; }
        lastCheckError = null;
        return r.json();
      })
      .catch(function (e) {
        lastCheckError = (e && e.message) ? e.message : 'network';
        return null;
      });
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

  /* THE LIVE STRIP, NEVER A PICTURE OF IT (2026-10-28, Sukhman could not clock in - the tap sent nothing).
     The shell's screen covers (__ktNavCover, __ktSnapshot) are cloneNode copies of #appMain: this strip included,
     same id, but no click listener. While the live strip was gone mid-render, getElementById found the COPY and
     mount() moved that dead button onto the screen. Only a strip outside a cover counts, and whichever strip is
     used is made sure to carry onTap. */
  function livePill() {
    var all = document.querySelectorAll('#kt-clockpill');
    for (var i = 0; i < all.length; i++) {
      if (!all[i].closest('[data-kt-refresh-snap], [data-kt-nav-cover]')) return all[i];
    }
    return null;
  }
  function armPill(el) {
    if (el && !el.__ktClockTap) { el.__ktClockTap = true; el.addEventListener('click', onTap); }
    return el;
  }
  function pill() {
    var el = livePill();
    if (el) return armPill(el);
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
    return armPill(el);
  }

  /* HR add-on geofence (2026-11-26, App\Support\HrClock): at a fenced centre the server answers 428 location_required.
     Ask with its consent text, read the location ONCE and send it with this clock-in only. Not now / blocked / no GPS =
     geo_declined (the agency rule then allows it flagged, or refuses with a plain reason). Never stored or watched. */
  KT.geoForClockIn = function (body) {
    var ask = KT.confirm ? KT.confirm({ title: 'Share your location to clock in', description: (body && body.consent) || '', okLabel: 'Share location', tone: 'info' }) : Promise.resolve(true);
    return Promise.resolve(ask).then(function (ok) {
      if (!ok || !navigator.geolocation) return { geo_declined: true };
      return new Promise(function (res) {
        navigator.geolocation.getCurrentPosition(function (p) {
          res({ geo_lat: p.coords.latitude, geo_lng: p.coords.longitude, geo_accuracy: Math.round(p.coords.accuracy || 0) });
        }, function () { res({ geo_declined: true }); }, { enableHighAccuracy: true, timeout: 15000, maximumAge: 60000 });
      });
    });
  };

  /* intent in|out (2026-11-05): the server answers a press that no longer matches the real
     state with that state, instead of flipping it. `extra` carries confirm_children. */
  function punchPost(intent, extra, path) {
    var t = tok();
    var body = { source: 'mobile' };
    if (intent) body.intent = intent;
    if (extra) for (var k in extra) body[k] = extra[k];
    return fetch(apiBase() + (path || '/staff/punch'), {
      method: 'POST',
      headers: { 'Authorization': 'Bearer ' + t, 'Accept': 'application/json', 'Content-Type': 'application/json' },
      body: JSON.stringify(body),
    }).then(function (r) {
      return r.json().catch(function () { return {}; }).then(function (d) {
        /* A REFUSAL IS NOT A SUCCESS. This used to return r.json() straight through, so a
           422 — the centre is closed, you are not on today's rota — arrived as a body with
           no `action` field, fell through both branches of the caller, and left the
           optimistic "Clocked in" pill standing. The server has been explaining itself
           politely to nobody. Reject with the server's own wording so the caller can say
           it out loud and put the pill back. */
        if (! r.ok) {
          if (d && d.location_required && KT.geoForClockIn && !(extra && (extra.geo_lat != null || extra.geo_declined))) {
            return KT.geoForClockIn(d).then(function (g) { return punchPost(intent, Object.assign({}, extra || {}, g), path); });
          }
          var err = new Error((d && d.message) || 'Could not clock in.');
          err.kt_refusal = true;
          err.kt_body = d || {};
          throw err;
        }
        return d;
      });
    });
  }

  // Tapping the strip toggles the clock. Both directions update the UI OPTIMISTICALLY
  // (flip the strip + glow the instant you tap) and POST in the background, so the
  // status changes immediately instead of waiting on a round-trip + re-fetch — the
  // "takes forever to show clocked in" complaint. Clocking out still confirms first
  // (it ends the paid shift). If the POST fails we revert.
  function onTap(sure) {
    if (!openPunch && onBreak) {
      if (sure === true) { backFromBreak(); return; }
      confirmBack(onBreak.kind).then(function (ok) { if (ok) backFromBreak(); });
      return;
    }
    if (!openPunch) {
      // CLOCK IN — instant. Flip to "Clocked in · 0m" now, reconcile after the POST.
      var nowUtc = new Date().toISOString().slice(0, 19).replace('T', ' ');   // server (UTC) format
      openPunch = { id: 0, punched_in_at: nowUtc };
      paint();
      if (KT.clockGlow) try { KT.clockGlow.update(); } catch (e) {}
      // Dimmed spinner on the strip while the punch confirms with the server.
      var pill = livePill();
      var doneBusy = (KT.busy && pill) ? KT.busy(pill) : function () {};
      punchPost('in')
        .then(function (d) {
          doneBusy();
          if (d && d.action === 'in') { if (d.id) openPunch.id = d.id; if (KT.toast) KT.toast('✅', 'Clocked in', 'Your shift has started — have a great day!', '#16A34A'); }
          else if (d && d.action === 'out') { openPunch = null; paint(); }   // server said we were already in → now out
          refresh();
        })
        .catch(function (e) {
          doneBusy(); openPunch = null; paint();
          if (e && e.kt_body && e.kt_body.reason === 'already_in') { refresh(); return; }   // it was right all along
          if (! KT.toast) return;
          /* A refusal is a normal answer, not a fault: say what the server said, in its
             own words, and in a calm colour. Only a genuine failure gets the red warning
             and "try again", because trying again will not change a closed centre. */
          if (e && e.kt_refusal) {
            var b = e.kt_body || {};
            KT.toast(b.not_scheduled ? '📅' : (b.closed ? '🏠' : 'ℹ️'),
                     b.not_scheduled ? 'Not on today\'s schedule' : (b.closed ? 'The centre is closed today' : 'Not clocked in'),
                     e.message, '#0E7C90');
          } else {
            KT.toast('⚠️', 'Could not clock in', 'Please try again in a moment.', '#B91C1C');
          }
        });
      return;
    }

    chooseOff();
  }

  /* LUNCH, BREAK OR THE END OF THE DAY (2026-11-05, Anthony: "if educator wants to sign out
     for lunch or break - wire something up to allow for that which doesnt completely sign
     them out for the day"). Tapping the strip while on the clock asks which one. */
  function chooseOff() {
    var e = elapsed(openPunch.punched_in_at);
    var ov = document.createElement('div');
    ov.style.cssText = 'position:fixed;inset:0;z-index:2147483000;background:rgba(8,17,33,.55);display:flex;align-items:center;justify-content:center;padding:20px;box-sizing:border-box;';
    var opt = function (id, icon, title, sub) {
      return '<button id="' + id + '" type="button" style="display:flex;align-items:center;gap:12px;width:100%;text-align:left;background:#F8FAFC;border:1px solid #E2E8F0;border-radius:12px;padding:12px 14px;margin:0 0 8px;cursor:pointer;font-family:inherit;">'
        + '<span style="font-size:22px;line-height:1;">' + icon + '</span><span style="display:flex;flex-direction:column;line-height:1.3;">'
        + '<span style="font-size:14.5px;font-weight:800;color:#0D1B2A;">' + title + '</span>'
        + '<span style="font-size:12px;color:#64748B;">' + sub + '</span></span></button>';
    };
    ov.innerHTML = '<div role="dialog" aria-modal="true" aria-label="Taking a break or finishing?" style="background:#fff;border-radius:18px;max-width:390px;width:100%;overflow:hidden;box-shadow:0 22px 60px rgba(0,0,0,.45);">'
      + '<div class="kt-dlg-head" style="position:relative;background:linear-gradient(135deg,#1B3A63 0%,#265B7A 50%,#2F8C86 100%);box-shadow:inset 0 -3px 0 #7FD3CC;color:#fff;padding:16px 48px 16px 18px;">'
      +   '<div style="font-size:17px;font-weight:800;">Taking a break or finishing?</div>'
      +   '<div style="font-size:12.5px;opacity:.9;margin-top:2px;">Clocked in for ' + e.text + '</div>'
      +   '<button id="kt-off-x" type="button" aria-label="Close" style="position:absolute;top:10px;right:10px;width:30px;height:30px;border:none;border-radius:8px;background:rgba(255,255,255,.16);color:#fff;font-size:16px;cursor:pointer;">✕</button>'
      + '</div>'
      + '<div style="padding:14px 16px 8px;">'
      +   opt('kt-off-lunch', '🍽️', 'Lunch', 'Off the floor - you stay on today\'s shift')
      +   opt('kt-off-break', '☕', 'Short break', 'Off the floor - you stay on today\'s shift')
      +   opt('kt-off-day', '👋', 'End my day', 'Clock out for today')
      + '</div></div>';
    document.body.appendChild(ov);
    var close = function () { if (ov.parentNode) ov.parentNode.removeChild(ov); };
    var pick = function (fn) { return function () { if (KT.popOverlay) { try { KT.popOverlay(ov); } catch (x) { close(); } } else close(); close(); fn(); }; };
    ov.querySelector('#kt-off-x').addEventListener('click', function () { if (KT.popOverlay) { try { KT.popOverlay(ov); return; } catch (x) {} } close(); });
    ov.addEventListener('click', function (ev) { if (ev.target === ov) close(); });
    ov.querySelector('#kt-off-lunch').addEventListener('click', pick(function () { startBreak('lunch'); }));
    ov.querySelector('#kt-off-break').addEventListener('click', pick(function () { startBreak('break'); }));
    ov.querySelector('#kt-off-day').addEventListener('click', pick(function () { clockOut(); }));
    if (KT.pushOverlay) { try { KT.pushOverlay(ov, close); } catch (x) {} }
  }

  /* Children still signed in: the SERVER names them (and who else is on the clock), and
     the person may go ahead after a clear warning (2026-11-05: warn, then allow). */
  function childrenWarning(body, doing, onProceed) {
    showChildrenStillInWarning(body, doing, onProceed);
  }

  function clockOut(confirmed) {
    var prev = openPunch;
    openPunch = null; paint();   // instant "Clocked out"
    if (KT.clockGlow) try { KT.clockGlow.update(); } catch (e) {}
    var pill = livePill();
    var doneBusy = (KT.busy && pill) ? KT.busy(pill) : function () {};
    punchPost('out', confirmed ? { confirm_children: true } : null)
      .then(function (d) { doneBusy(); refresh(); if (KT.toast) KT.toast('👋', 'Clocked out', 'Your shift has been closed. Have a good evening.', '#0E7C90'); })
      .catch(function (e) {
        doneBusy(); openPunch = prev; paint();
        var body = (e && e.kt_body) || {};
        if (body.reason === 'children_still_signed_in') { childrenWarning(body, 'Clock out anyway', function () { clockOut(true); }); return; }
        if (body.reason === 'not_clocked_in') { refresh(); return; }
        if (! KT.toast) return;
        if (e && e.kt_refusal) { KT.toast('ℹ️', 'Still clocked in', e.message, '#0E7C90'); }
        else { KT.toast('⚠️', 'Could not clock out', 'Please try again.', '#B91C1C'); }
      });
  }

  function startBreak(kind, confirmed) {
    var prev = openPunch;
    var nowUtc = new Date().toISOString().slice(0, 19).replace('T', ' ');
    openPunch = null; onBreak = { kind: kind, label: kind === 'lunch' ? 'Lunch' : 'Break', started_at: nowUtc }; paint();
    if (KT.clockGlow) try { KT.clockGlow.update(); } catch (e) {}
    if (KT.clockGlow) try { KT.clockGlow.update(); } catch (e) {}
    var pill = livePill();
    var doneBusy = (KT.busy && pill) ? KT.busy(pill) : function () {};
    var extra = { kind: kind }; if (confirmed) extra.confirm_children = true;
    punchPost(null, extra, '/staff/break/start')
      .then(function (d) {
        doneBusy();
        if (d && d.on_break) onBreak = d.on_break;
        paint(); refresh();
        if (KT.toast) KT.toast(kind === 'lunch' ? '🍽️' : '☕', kind === 'lunch' ? 'Enjoy your lunch' : 'Enjoy your break', 'Tap the strip when you are back.', '#6D28D9');
      })
      .catch(function (e) {
        doneBusy(); openPunch = prev; onBreak = null; paint();
        var body = (e && e.kt_body) || {};
        if (body.reason === 'children_still_signed_in') { childrenWarning(body, kind === 'lunch' ? 'Go on lunch anyway' : 'Take the break anyway', function () { startBreak(kind, true); }); return; }
        if (body.reason === 'not_clocked_in' || body.reason === 'already_on_break') { refresh(); return; }
        if (KT.toast) KT.toast(e && e.kt_refusal ? 'ℹ️' : '⚠️', 'Could not start the break', (e && e.kt_refusal) ? e.message : 'Please try again.', e && e.kt_refusal ? '#0E7C90' : '#B91C1C');
      });
  }

  /* ARE YOU BACK? (2026-11-06, Anthony: "when they come back and they click clock in - prompt them with a pop up are you
     back from break or lunch - just to be certain they didn't click on the clock in by accident"). Ending a break puts
     them back on the clock and in the room's ratio, so a stray tap on the strip or the time clock asks first. Used by
     the strip and the Clock in/out screen (screen-care.js); the "You're on lunch" gate's own "I'm back" already asked. */
  function confirmBack(kind) {
    var lunch = kind === 'lunch';
    if (!KT.confirm) return Promise.resolve(true);
    return Promise.resolve(KT.confirm({
      title: lunch ? 'Are you back from lunch?' : 'Are you back from your break?',
      description: 'You will be back on the clock and counted in your room again.',
      keepHeaderTitle: true,
      okLabel: 'Yes, I\u2019m back', cancelLabel: lunch ? 'Still on lunch' : 'Still on break',
    })).then(function (ok) { return !!ok; });
  }
  KT.confirmBackFromBreak = confirmBack;

  function backFromBreak() {
    var prev = onBreak;
    var nowUtc = new Date().toISOString().slice(0, 19).replace('T', ' ');
    onBreak = null; openPunch = { id: 0, punched_in_at: nowUtc }; paint();
    if (KT.clockGlow) try { KT.clockGlow.update(); } catch (e) {}
    var pill = livePill();
    var doneBusy = (KT.busy && pill) ? KT.busy(pill) : function () {};
    punchPost(null, null, '/staff/break/end')
      .then(function (d) { doneBusy(); refresh(); if (KT.toast) KT.toast('✅', 'Welcome back', (d && d.message) || 'You are back on the clock.', '#16A34A'); })
      .catch(function (e) {
        doneBusy(); onBreak = prev; openPunch = null; paint();
        var body = (e && e.kt_body) || {};
        if (body.reason === 'not_on_break') { refresh(); return; }
        if (KT.toast) KT.toast('⚠️', 'Could not end the break', (e && e.kt_refusal) ? e.message : 'Please try again.', '#B91C1C');
      });
  }

  // Big, red, impossible-to-miss warning: children still signed in. A genuine alarm, so the
  // red band (the popup header rule's one exception). Names come from the server.
  function showChildrenStillInWarning(body, proceedLabel, onProceed) {
    var esc = function (v) { return String(v == null ? '' : v).replace(/[&<>"]/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]; }); };
    var kids = (body && body.children) || [];
    var n = kids.length || 0;
    var list = kids.slice(0, 12).map(function (c) { return '<li style="margin:2px 0;">' + esc(c.label || c.name) + '</li>'; }).join('')
      + (kids.length > 12 ? '<li>and ' + (kids.length - 12) + ' more</li>' : '');
    var ov = document.createElement('div');
    ov.style.cssText = 'position:fixed;inset:0;z-index:2147483000;background:rgba(90,10,10,.6);display:flex;align-items:center;justify-content:center;padding:20px;box-sizing:border-box;';
    ov.innerHTML = '<div role="alertdialog" aria-modal="true" style="background:#fff;border-radius:18px;max-width:390px;width:100%;max-height:calc(100vh - 40px);display:flex;flex-direction:column;overflow:hidden;box-shadow:0 22px 60px rgba(0,0,0,.45);">'
      + '<div style="background:linear-gradient(135deg,#DC2626,#991B1B);color:#fff;padding:20px;text-align:center;flex-shrink:0;">'
      + '<div style="font-size:40px;line-height:1;">⚠️</div>'
      + '<div style="font-size:19px;font-weight:900;margin-top:8px;line-height:1.2;">' + n + (n === 1 ? ' child is' : ' children are') + ' still signed in</div>'
      + '</div>'
      + '<div style="padding:16px 20px;overflow:auto;">'
      + (list ? '<ul style="margin:0 0 12px;padding-left:18px;font-size:13.5px;color:#334155;line-height:1.45;">' + list + '</ul>' : '')
      + '<p style="margin:0 0 14px;font-size:13.5px;color:#334155;line-height:1.55;">' + esc((body && body.what_to_do) || 'Make sure every child is signed out or handed to another educator.') + '</p>'
      + '<button id="kt-cow-back" type="button" style="display:block;width:100%;background:linear-gradient(135deg,#16A34A,#15803D);color:#fff;border:none;border-radius:12px;padding:13px;font-size:15px;font-weight:800;cursor:pointer;">Go back and check the room</button>'
      + '<button id="kt-cow-out" type="button" style="display:block;width:100%;background:none;color:#B91C1C;border:none;border-radius:10px;padding:12px;font-size:13px;font-weight:700;cursor:pointer;margin-top:6px;">' + esc(proceedLabel || 'Continue anyway') + '</button>'
      + '</div></div>';
    document.body.appendChild(ov);
    var close = function () { if (ov.parentNode) ov.parentNode.removeChild(ov); };
    ov.querySelector('#kt-cow-back').addEventListener('click', function () { if (KT.popOverlay) { try { KT.popOverlay(ov); return; } catch (e) {} } close(); });
    ov.querySelector('#kt-cow-out').addEventListener('click', function () { if (KT.popOverlay) { try { KT.popOverlay(ov); } catch (e) {} } close(); onProceed(); });
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
  /* UNDER the header, as the comment always said (2026-10-06). It went in at
     main.firstChild — and #kt-topbar is #appMain's first child — so on the director's
     dashboard the pill landed ABOVE the greeting bar ~20ms into every render, and the bar
     jumped 80px down. It also re-checked "am I first?" and moved itself back on top each
     time the bar was re-inserted. Anchored the way the shell's heroAnchor() is: right
     after the live bar when there is one, first otherwise. Walked, not :scope. */
  /* AND UNDER THE BANNER (2026-10-09: "nothing between the top bar and the hero —
     consistent across the platform"). The page reads top bar, hero, then everything else,
     this strip included. When the screen has a hero the strip sits directly after it
     (inside whatever wraps it); until the hero exists, after the top bar as before —
     kt-banner-normalize then moves it below the hero the moment one appears, and this
     check leaves it there instead of pulling it back on top. */
  function mount(el) {
    var main = document.getElementById('appMain');
    if (!main) return;
    /* TODAY (2026-10-23, Anthony: "the clock in banner should be at the top like it is in the home
       page"): the day brief's cards now live OUTSIDE #kt-day-brief (screen-educator.js), so
       "right after the banner" puts the strip under the header and above the cards. */
    var hero = main.querySelector('.kt-hero, .kt-page-hero') || main.querySelector('[data-kt-hero], #kt-day-brief');
    if (hero && !hero.contains(el)) {
      if (el.previousElementSibling !== hero) hero.parentNode.insertBefore(el, hero.nextSibling);
      return;
    }
    var bar = null;
    for (var i = 0; i < main.children.length; i++) { if (main.children[i].id === 'kt-topbar') { bar = main.children[i]; break; } }
    var want = bar ? bar.nextSibling : main.firstChild;
    if (el.parentElement !== main || (bar ? el.previousElementSibling !== bar : main.firstElementChild !== el)) {
      if (want !== el) main.insertBefore(el, want);
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
      var old = livePill();
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
    /* Not yet checked — neutral, and deliberately NOT tappable. The strip paints on
       every screen render, before any request has returned, so without this it asserted
       "You're not clocked in" a beat before it knew, and again after any failed poll. */
    if (!punchKnown) {
      /* A FAILED CHECK IS NOT A REASON TO STOP SOMEBODY WORKING.

         "Checking your shift…" was deliberately not tappable, so that the strip could not
         assert the wrong state before the first answer came back. Right for the half
         second it was written for; a trap once the check fails, because nothing ever
         retries into a tappable state and clocking in is the first thing an educator
         does. Once it HAS failed, say so, make it tappable to try again, and let the tap
         fall through to clocking in — the server is the authority on whether a punch is
         allowed, and it refuses a second open punch on its own. */
      var failed = checkFailed;
      el.innerHTML =
        '<span style="' + ICON + '">' + (failed ? '⚠️' : '⏱') + '</span>'
        + '<span style="' + MID + '">'
        +   '<span style="font-size:15px;font-weight:800;">'
        +     (failed ? 'Couldn\'t check your shift' : 'Checking your shift…') + '</span>'
        +   '<span style="font-size:12px;font-weight:600;opacity:.9;">'
        +     (failed ? 'Tap to try again, or to clock in' : 'One moment') + '</span>'
        + '</span>'
        + (failed ? '<span style="' + CHIP + 'color:#B45309;">Retry</span>' : '');
      el.style.background = failed ? '#B45309' : '#64748B';
      el.style.color = '#fff';
      el.style.border = 'none';
      return;
    }
    if (!openPunch && onBreak) {
      var be = elapsed(onBreak.started_at);
      var bAt = '';
      try { bAt = parseTs(onBreak.started_at).toLocaleTimeString([], { hour: 'numeric', minute: '2-digit' }); } catch (x) {}
      el.innerHTML =
        '<span style="' + ICON + '">' + (onBreak.kind === 'lunch' ? '🍽️' : '☕') + '</span>'
        + '<span style="' + MID + '">'
        +   '<span style="font-size:15px;font-weight:800;">On ' + (onBreak.kind === 'lunch' ? 'lunch' : 'a break') + ' · ' + be.text + '</span>'
        +   '<span style="font-size:12px;font-weight:600;opacity:.9;">' + (bAt ? 'Since ' + bAt + ' · ' : '') + 'still on today\'s shift</span>'
        + '</span>'
        + '<span style="' + CHIP + 'color:#6D28D9;">I\'m back</span>';
      el.style.background = '#6D28D9';
      el.style.color = '#fff';
      el.style.border = 'none';
      return;
    }
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
      + '<span style="' + CHIP + 'color:' + (overdue ? '#B45309' : '#15803D') + ';">Break / out</span>';
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
      /* get() resolves NULL for EVERY failure — dropped connection, timeout, 500, a
         401 blip. This used to read `(d && d.punches) || []`, so a failure produced an
         empty list, which produced openPunch = null, which repainted the strip as
         "You're not clocked in" and offered a Clock in button. On a phone, one blip in
         the 60-second poll was enough to tell a working educator she was off the clock
         — and tapping that button would have opened a SECOND punch on top of her live
         one. A failed question is not a "no": keep the last state we actually know. */
      if (!d || !d.punches) {
        /* Still not a "no" — the last known state is kept. But it IS something to show:
           repaint so a first failure turns into "Couldn't check" rather than an
           indefinite "Checking…". */
        if (!d) { checkFailed = true; paint(); }
        return;
      }
      checkFailed = false;
      lastCheckError = null;
      openPunch = d.punches.find(function (p) { return !p.punched_out_at; }) || null;
      onBreak = (!openPunch && d.on_break) ? d.on_break : null;
      punchKnown = true;
      paint();
    });
  }

  KT.clockBar = {
    refresh: refresh,
    isClockedIn: function () { return !!openPunch; },
    isOnBreak: function () { return !!onBreak; },
    // Has the shift state been read from the server yet? Until then isClockedIn() is a guess (false), not a "no".
    isKnown: function () { return punchKnown; },
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
      + '<div style="font-size:18px;font-weight:800;color:#0D1B2A;margin-bottom:6px;">' + (onBreak ? 'You\'re on ' + (onBreak.kind === 'lunch' ? 'lunch' : 'a break') : 'Clock in first') + '</div>'
      + '<div style="font-size:14px;color:#475569;line-height:1.5;margin-bottom:18px;">' + (onBreak ? 'Come back from your break before you check children in or out, so the ratio counts you on the floor.' : 'You need to be clocked in before you can check children in or out. This keeps attendance and ratio records accurate.') + '</div>';
    var row = document.createElement('div'); row.style.cssText = 'display:flex;gap:10px;';
    var no = document.createElement('button'); no.type = 'button'; no.textContent = 'Not now';
    no.style.cssText = 'flex:1;background:#F1F5F9;color:#475569;border:none;border-radius:12px;padding:13px;font-size:14px;font-weight:700;cursor:pointer;';
    var yes = document.createElement('button'); yes.type = 'button'; yes.textContent = onBreak ? 'I\'m back' : 'Clock in now';
    yes.style.cssText = 'flex:1;background:#16A34A;color:#fff;border:none;border-radius:12px;padding:13px;font-size:14px;font-weight:800;cursor:pointer;';
    var close = function () { if (ov.parentNode) ov.parentNode.removeChild(ov); };
    no.addEventListener('click', close);
    yes.addEventListener('click', function () { close(); try { if (!openPunch) onTap(true); } catch (e) {} });
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
    if (m) {
      /* Re-binds when the shell swaps #appMain (kt:main-swapped); a MutationObserver follows a NODE, and this one used to die silently at the first render. */
      try {
        if (window.KT && KT.observeMain) { KT.observeMain(scheduleClk, { childList: true }); }
        else { new MutationObserver(scheduleClk).observe(m, { childList: true }); }
      } catch (e) {}
      scheduleClk(); return;
    }
    if (tries > 80) return;
    requestAnimationFrame(function () { watchMain(tries + 1); });
  })(0);
  paint();                          // immediate first paint (no wait)
  (window.KT && KT.sweepBus) ? KT.sweepBus.on(paint) : setInterval(paint, 4000);         // safety re-mount
  setInterval(refresh, POLL_MS);    // the punch state itself changes rarely
  setTimeout(refresh, 300);         // fetch the punch state promptly (was 2500) so in/out is right fast
  window.addEventListener('hashchange', function () {
    scheduleClk();                  // put the strip back on the new screen immediately
    setTimeout(refresh, 800);       // clocking in/out happens on #time-clock
  });
})(window);
