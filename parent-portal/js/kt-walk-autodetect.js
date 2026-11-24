/* ═══════════════════════════════════════════════════════════════════
   KiddieTrac — noticing that an outing has started.

   A walk gets logged when somebody remembers to log it, which is at the door, with
   coats and a double buggy. So walks go unrecorded, and the one record that says
   which children left the property is the one that is missing.

   This watches for the educator's phone leaving the provider's address while children
   are signed in, and offers — once — to start the walk. It does not start anything by
   itself: a walk names the children who went, and only the person there knows that.

   TWO THINGS IT DELIBERATELY DOES NOT DO
   ──────────────────────────────────────
   1. It never sends a location anywhere. The fence is fetched once and the distance
      is worked out on the device. A feature that exists to notice one moment must not
      leave behind a continuous record of where staff are.
   2. It does not assume background location. Catching a departure with the phone in
      a pocket and the app closed needs an always-on permission, a permanent
      notification on the educator's phone, and an app-store justification — a
      decision about staff privacy, not a technical gap. So it runs three ways, in
      increasing order of intrusiveness, and stops wherever the app actually is:

        · a poll while the app is open;
        · an immediate check the moment the app returns to the foreground, which is
          when an educator already out on a walk takes the phone from a pocket;
        · a background watcher IF the native build ships a background-geolocation
          plugin — same fence, same rules, same prompt. Absent the plugin this last
          one silently does nothing, so enabling it later is a build decision rather
          than a change here.
   ═══════════════════════════════════════════════════════════════════ */
(function (window) {
  'use strict';
  var KT = (window.KT = window.KT || {});

  var POLL_MS = 90000;        // how often to re-check the fence + position
  var CONFIRM_FIXES = 2;      // consecutive out-of-fence fixes before believing it
  var DISMISS_KEY = 'kt_walk_prompt_dismissed';

  /* 2026-10-21 (Anthony: "the auto detect walk for educators is that working and wired up
     correctly - full sweep"). It was not: this file was committed on 2026-09-01 and never
     loaded by any page, so it had never run; its "Start a walk" went to #walks, the PARENT
     walk screen (staff use #walk); and it would have started the background watcher on
     every educator's phone the moment it loaded. Now: loaded on the dashboard, sends
     staff to #walk, never fires a cold location prompt, background only when the agency
     turns it on (fence.background), and it also offers to END a walk on the way back in. */
  var state = { fence: null, outCount: 0, inCount: 0, wasOut: false, prompting: false, timer: null, started: false, bgId: null, endAskedFor: null };
  var END_DISMISS_KEY = 'kt_walk_end_prompt_dismissed';

  function tok() {
    try { return sessionStorage.getItem('kt_token') || localStorage.getItem('kt_token'); }
    catch (e) { return null; }
  }
  function apiBase() { return (KT.API_BASE) || 'https://api.kiddietrac.com/api/v1'; }

  function get(path) {
    var h = { 'Authorization': 'Bearer ' + tok(), 'Accept': 'application/json' };
    try {
      var aa = sessionStorage.getItem('kt_active_agency_id');
      if (aa) { h['X-Active-Agency-Id'] = aa; }
    } catch (e) {}
    return fetch(apiBase() + path, { headers: h }).then(function (r) {
      if (!r.ok) { throw new Error('HTTP ' + r.status); }
      return r.json();
    });
  }

  function isProvider() {
    try {
      var u = JSON.parse(sessionStorage.getItem('kt_user') || localStorage.getItem('kt_user') || '{}');
      var roles = (u.roles || []).slice();
      if (u.role_key) { roles.push(u.role_key); }
      if (u.primary_role) { roles.push(u.primary_role); }
      if (window.ktRoles && sessionStorage.getItem('kt_view_as')) { roles = window.ktRoles(u); }   // previewing a parent: no staff geofence
      return ['educator', 'centre_director', 'agency_admin'].some(function (r) {
        return roles.indexOf(r) !== -1;
      });
    } catch (e) { return false; }
  }

  /** Dismissed for today — asking again after "not now" is how a prompt becomes noise. */
  function dismissedToday() {
    try {
      var d = sessionStorage.getItem(DISMISS_KEY);
      return d === new Date().toDateString();
    } catch (e) { return false; }
  }
  function dismissForToday() {
    try { sessionStorage.setItem(DISMISS_KEY, new Date().toDateString()); } catch (e) {}
  }

  /* Straight-line distance in metres. The equirectangular approximation is plenty at
     these ranges and avoids the trigonometry of haversine for a 200 m question. */
  function metresApart(lat1, lon1, lat2, lon2) {
    var R = 6371000;
    var toRad = Math.PI / 180;
    var x = (lon2 - lon1) * toRad * Math.cos(((lat1 + lat2) / 2) * toRad);
    var y = (lat2 - lat1) * toRad;
    return Math.sqrt(x * x + y * y) * R;
  }

  function prompt(fence, metres) {
    if (state.prompting) { return; }
    state.prompting = true;

    var scrim = document.createElement('div');
    scrim.style.cssText = 'position:fixed;inset:0;background:rgba(15,23,42,.45);z-index:10000;'
      + 'display:flex;align-items:flex-end;justify-content:center;padding:16px 16px calc(16px + var(--kt-safe-bottom, env(safe-area-inset-bottom, 0px)));';
    var card = document.createElement('div');
    card.style.cssText = 'background:#fff;border-radius:18px;max-width:460px;width:100%;'
      + 'padding:20px 22px;box-shadow:0 20px 50px rgba(15,23,42,.3);';
    card.innerHTML =
      '<div style="font-size:15px;font-weight:800;color:#0F172A;margin-bottom:4px;">'
      + '🚶 Are you heading out?</div>'
      + '<div style="color:#475569;font-size:13.5px;line-height:1.5;">'
      + 'It looks like you have left ' + (fence.centre_name ? String(fence.centre_name) : 'the home')
      + ' — about ' + Math.round(metres) + ' m away — and '
      + fence.children_present + ' child' + (fence.children_present === 1 ? ' is' : 'ren are')
      + ' signed in. Start a walk so parents can see who is out with you?</div>'
      + '<div style="display:flex;gap:10px;margin-top:16px;">'
      + '<button id="kwa-no" style="flex:1;padding:11px;border-radius:10px;border:1px solid #CBD5E1;'
      + 'background:#fff;font-weight:700;font-size:14px;cursor:pointer;">Not now</button>'
      + '<button id="kwa-yes" style="flex:1;padding:11px;border-radius:10px;border:0;'
      + 'background:#1F6080;color:#fff;font-weight:700;font-size:14px;cursor:pointer;">Start a walk</button>'
      + '</div>';
    scrim.appendChild(card);
    document.body.appendChild(scrim);

    function close() {
      try { document.body.removeChild(scrim); } catch (e) {}
      state.prompting = false;
    }
    card.querySelector('#kwa-no').addEventListener('click', function () {
      /* Not now means not again today. The educator knows what they are doing; a
         second prompt on the same outing is the thing that gets a feature muted. */
      dismissForToday();
      state.outCount = 0;
      // Not stop(): the start offer is snoozed for today, but a walk started later by hand
      // should still get the "back at the centre?" check.
      close();
    });
    card.querySelector('#kwa-yes').addEventListener('click', function () {
      close();
      dismissForToday();          // they are dealing with it either way
      state.outCount = 0;
      // #walk is the staff walk screen; #walks is the PARENT one (it sent staff there).
      window.location.hash = 'walk';
    });
  }

  /* On the way back in: a walk left running is a record that says children are still
     off the property. Asked once per walk; ending happens on the walk screen, where the
     return headcount lives. */
  function endDismissed(id) {
    try { return sessionStorage.getItem(END_DISMISS_KEY) === String(id); } catch (e) { return false; }
  }
  function promptEnd(fence) {
    if (state.prompting || !fence.walk_id || endDismissed(fence.walk_id)) { return; }
    state.prompting = true;
    state.endAskedFor = fence.walk_id;
    var scrim = document.createElement('div');
    scrim.style.cssText = 'position:fixed;inset:0;background:rgba(15,23,42,.45);z-index:10000;'
      + 'display:flex;align-items:flex-end;justify-content:center;padding:16px 16px calc(16px + var(--kt-safe-bottom, env(safe-area-inset-bottom, 0px)));';
    var card = document.createElement('div');
    card.style.cssText = 'background:#fff;border-radius:18px;max-width:460px;width:100%;'
      + 'padding:20px 22px;box-shadow:0 20px 50px rgba(15,23,42,.3);';
    card.innerHTML =
      '<div style="font-size:15px;font-weight:800;color:#0F172A;margin-bottom:4px;">🏠 Back at '
      + (fence.centre_name ? String(fence.centre_name).replace(/[<>&]/g, '') : 'the centre') + '?</div>'
      + '<div style="color:#475569;font-size:13.5px;line-height:1.5;">Your walk is still running. '
      + 'End it so parents and your director can see everyone is back.</div>'
      + '<div style="display:flex;gap:10px;margin-top:16px;">'
      + '<button id="kwe-no" style="flex:1;padding:11px;border-radius:10px;border:1px solid #CBD5E1;'
      + 'background:#fff;font-weight:700;font-size:14px;cursor:pointer;">Still out</button>'
      + '<button id="kwe-yes" style="flex:1;padding:11px;border-radius:10px;border:0;'
      + 'background:#1F6080;color:#fff;font-weight:700;font-size:14px;cursor:pointer;">End the walk</button>'
      + '</div>';
    scrim.appendChild(card);
    document.body.appendChild(scrim);
    function close() { try { document.body.removeChild(scrim); } catch (e) {} state.prompting = false; }
    card.querySelector('#kwe-no').addEventListener('click', function () {
      try { sessionStorage.setItem(END_DISMISS_KEY, String(fence.walk_id)); } catch (e) {}
      close();
    });
    card.querySelector('#kwe-yes').addEventListener('click', function () {
      try { sessionStorage.setItem(END_DISMISS_KEY, String(fence.walk_id)); } catch (e) {}
      close();
      window.location.hash = 'walk';
    });
  }

  /* One decision, whichever source the fix came from. */
  function evaluate(lat, lon, accuracy) {
    var f = state.fence;
    if (!f || !f.enabled || state.prompting) { return; }

    /* A fix worse than the fence itself tells us nothing — indoors, a phone can report
       a 500 m accuracy circle while sitting perfectly still inside it. */
    if (accuracy && accuracy > f.radius_m) { return; }

    var m = metresApart(lat, lon, f.latitude, f.longitude);

    if (f.walk_in_progress) {
      // Out on a walk: once we have seen them away, two fixes back inside = home again.
      if (m > f.radius_m) { state.wasOut = true; state.inCount = 0; return; }
      /* After a restart mid-walk this phone never SAW itself leave, so it never asked (2026-10-28). A walk out for
         ten minutes or more that is now inside the fence has come back. */
      if (!state.wasOut && f.walk_minutes != null && f.walk_minutes >= 10) { state.wasOut = true; }
      if (state.wasOut) {
        state.inCount++;
        if (state.inCount >= CONFIRM_FIXES) { promptEnd(f); }
      }
      return;
    }
    state.wasOut = false; state.inCount = 0;
    if (dismissedToday() || !f.children_present) { return; }
    if (m > f.radius_m) {
      state.outCount++;
      if (state.outCount >= CONFIRM_FIXES) { prompt(f, m); }
    } else {
      state.outCount = 0;
    }
  }

  /* NEVER A COLD PERMISSION PROMPT. A location dialog out of nowhere, 20 seconds after
     signing in, is how this gets denied for good. Detection runs once location has been
     allowed (the walk screen asks, with its reason on screen). 'prompt' = not yet asked:
     stay quiet. No Permissions API (some web views): go ahead, as before. */
  function locationAllowed() {
    try {
      if (!navigator.permissions || !navigator.permissions.query) { return Promise.resolve(true); }
      return navigator.permissions.query({ name: 'geolocation' })
        .then(function (p) { return p.state === 'granted'; }, function () { return true; });
    } catch (e) { return Promise.resolve(true); }
  }

  function check() {
    if (state.prompting) { return; }
    if (document.hidden) { return; }

    get('/provider/walks/geofence').then(function (f) {
      if (!f || !f.enabled) { state.outCount = 0; return; }
      var startable = !f.walk_in_progress && f.children_present && !dismissedToday();
      var endable = f.walk_in_progress && f.walk_id && !endDismissed(f.walk_id);
      if (!startable && !endable) { state.outCount = 0; state.fence = f; syncBackground(f); return; }
      state.fence = f;
      syncBackground(f);
      if (!navigator.geolocation) { return; }

      locationAllowed().then(function (ok) {
        if (!ok) { return; }
        navigator.geolocation.getCurrentPosition(function (pos) {
          evaluate(pos.coords.latitude, pos.coords.longitude, pos.coords.accuracy);
        }, function () {
          /* No fix. Nothing to say — an optional convenience, not something to nag about. */
        }, { enableHighAccuracy: false, timeout: 15000, maximumAge: 60000 });
      });
    }).catch(function () { /* offline or not permitted; try again next tick */ });
  }

  /* ── background, IF the app is ever built with it ──────────────────────────
     The Capacitor build can carry a background-geolocation plugin. If one is present
     its watcher feeds the same evaluation as the foreground poll — same fence, same
     rules, same prompt — so turning background on later is a native build decision
     rather than a rewrite here. If it is absent, nothing below runs and the
     foreground poll is the whole feature. */
  function backgroundPlugin() {
    try {
      var cap = window.Capacitor;
      if (!cap || !cap.Plugins) { return null; }
      return cap.Plugins.BackgroundGeolocation || null;
    } catch (e) { return null; }
  }

  /* GOOGLE PLAY BUILD (2026-10-21). The Play app (KtBio.dist() === 'play') must not offer the
     sideload "download the new APK" banner (Play updates it) and has no background-location
     service. Older apps have no dist() and are sideload builds. */
  var _ktDist = null;
  function appDist() {
    if (_ktDist) { return Promise.resolve(_ktDist); }
    try {
      var bio = window.Capacitor && window.Capacitor.Plugins && window.Capacitor.Plugins.KtBio;
      if (!bio || typeof bio.dist !== 'function') { return Promise.resolve(_ktDist = 'sideload'); }
      return bio.dist().then(function (r) { return (_ktDist = (r && r.dist) || 'sideload'); },
        function () { return (_ktDist = 'sideload'); });
    } catch (e) { return Promise.resolve(_ktDist = 'sideload'); }
  }

  function startBackground() {
    var plugin = backgroundPlugin();
    if (!plugin || state.bgId || typeof plugin.addWatcher !== 'function') { return false; }
    state.bgId = 'pending';        // one watcher, even if two checks land before it resolves
    try {
      plugin.addWatcher({
        /* Shown in the permanent notification Android requires for background
           location. Worth wording carefully — this is what an educator reads on their
           own phone, and a vague string is what makes people revoke the permission. */
        backgroundMessage: 'Watching for the start of an outing so parents can be told.',
        backgroundTitle: 'KiddieTrac',
        requestPermissions: true,
        stale: false,
        distanceFilter: 60,
      }, function (position, error) {
        /* Permission refused ("only while using the app" / "deny") arrives here as an
           error: say nothing and carry on with foreground checks — no nagging. */
        if (error || !position) { return; }
        evaluate(position.latitude, position.longitude, position.accuracy);
      }).then(function (id) {
        // Switched off while it was starting: take it straight back down.
        if (state.bgId !== 'pending') { try { plugin.removeWatcher({ id: id }); } catch (e) {} return; }
        state.bgId = id;
      }, function () { state.bgId = null; });

      return true;
    } catch (e) { state.bgId = null; return false; }
  }

  /* The agency's switch (Room Controls → Walks), re-read on every check: turned on, the
     watcher starts on the next check; turned off, it stops — no app rebuild either way.
     Educators only: a director or admin's phone is not the one going out on walks. */
  function isEducator() {
    try {
      var u = JSON.parse(sessionStorage.getItem('kt_user') || localStorage.getItem('kt_user') || '{}');
      var r = (u.roles || []).concat([u.role_key, u.primary_role]);
      return r.indexOf('educator') !== -1;
    } catch (e) { return false; }
  }
  function stopBackground() {
    var plugin = backgroundPlugin();
    if (state.bgId && state.bgId !== 'pending' && plugin && typeof plugin.removeWatcher === 'function') {
      try { plugin.removeWatcher({ id: state.bgId }); } catch (e) {}
    }
    state.bgId = null;              // a pending start sees this and removes its own watcher
  }
  function syncBackground(f) {
    if (f && f.enabled && f.background && isEducator()) {
      appDist().then(function (d) { if (d !== 'play') { startBackground(); } });
    }
    else if (state.bgId) { stopBackground(); }
  }

  function start() {
    if (state.started || !tok() || !isProvider()) { return; }
    state.started = true;

    // Not immediately: boot is busy, and the first minute is the least likely moment
    // for somebody to be walking out of the door.
    setTimeout(check, 20000);
    state.timer = setInterval(check, POLL_MS);

    /* The moment the app comes back to the foreground is the single most likely time
       to catch an outing: the educator is out, takes the phone from a pocket, and the
       poll would otherwise not run for another minute and a half. Costs nothing and
       needs no permission beyond the one the walk screen already asks for. */
    document.addEventListener('visibilitychange', function () {
      if (!document.hidden && state.started) { setTimeout(check, 1200); }
    });
    // Background watching starts from check(), only when the agency has turned it on.
  }

  function stop() {
    if (state.timer) { clearInterval(state.timer); state.timer = null; }
    stopBackground();
    state.started = false;
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', start);
  } else {
    start();
  }

  KT.WalkAutodetect = { start: start, stop: stop, check: check, _metresApart: metresApart };
})(window);
