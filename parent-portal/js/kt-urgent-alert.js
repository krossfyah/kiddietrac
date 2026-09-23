/* ═══════════════════════════════════════════════════════════════════
   KiddieTrac — urgent in-app alert for staff (2026-07-13).

   An educator on the room floor can't be checking a badge. When a new message
   or alert lands while the app is OPEN, this takes the whole screen: a pulsing
   flash, a repeating chime, a long vibration pattern, and it will not go away
   until they Open it or Dismiss it.

   Scope + limits (deliberate):
   - This is the IN-APP layer. When the app is closed or backgrounded, the OS
     notification does the work — its sound/vibration are set by the Android
     channel, not by anything here (see kt-native-push.js + KtMessagingService).
   - Staff only (educator / director). Parents keep the calm badge behaviour;
     nagging a parent mid-evening about a photo would be obnoxious.
   - Can be switched off per device in Settings (kt_urgent_alerts = '0'). Staff
     who don't want to be startled shouldn't have to uninstall the app.
   - The chime is generated with WebAudio (no asset, no autoplay-blocked <audio>),
     and only after the user has interacted with the page at least once —
     browsers won't let us make noise before that. Vibration needs the VIBRATE
     permission in the APK; where it's missing, navigator.vibrate is a no-op and
     the flash + sound still fire.
   ═══════════════════════════════════════════════════════════════════ */
(function (window) {
  'use strict';
  if (window.__ktUrgent) return; window.__ktUrgent = true;

  var POLL_MS = 15000;          // how often we look for something new
  var CHIME_EVERY_MS = 2600;    // keep chiming until acknowledged
  var VIBE = [700, 250, 700, 250, 900];   // long + strong, repeated

  var KT = window.KT || (window.KT = {});
  var active = null;            // the open overlay, if any
  var timers = [];
  var lastSeen = null;          // {msg:int, notif:int} — null until first poll

  function tok() { try { return sessionStorage.getItem('kt_token') || localStorage.getItem('kt_token'); } catch (e) { return null; } }
  function apiBase() { return (window.KT_CONFIG && window.KT_CONFIG.apiBase) || 'https://api.kiddietrac.com/api/v1'; }
  function enabled() { try { return localStorage.getItem('kt_urgent_alerts') !== '0'; } catch (e) { return true; } }

  /* EVERY member of staff, not just the two role strings this used to name.
     'educator' and 'centre_director' left out agency_admin, platform_admin and
     home_visitor — so an admin never saw a takeover at all and reported that the screen
     "doesn't pop up on its own". Asking whether somebody is staff is the question; a list
     of role strings is a guess that silently rots every time a role is added.
     Guardians are still excluded on purpose: nagging a parent mid-evening about a photo
     would be obnoxious. (Anthony, 2026-09-09) */
  function isStaffView() {
    try {
      var va = sessionStorage.getItem('kt_view_as') || '';
      var roles;
      if (va) roles = [va];
      else {
        var u = JSON.parse(sessionStorage.getItem('kt_user') || localStorage.getItem('kt_user') || '{}');
        roles = [].concat(u.roles || [], u.primary_role ? [u.primary_role] : []);
      }
      var STAFF = ['educator', 'centre_director', 'agency_admin', 'platform_admin',
                   'home_visitor', 'auditor'];
      return STAFF.some(function (r) { return roles.indexOf(r) > -1; });
    } catch (e) { return false; }
  }

  /* ON THE FLOOR vs AT A DESK — and why this distinction had to exist.

     This module was written for "an educator on the room floor can't be checking a badge":
     it blocks the screen and CHIMES EVERY 2.6 SECONDS until acknowledged. That is right
     for somebody with a room full of children and a phone in their pocket.

     On 2026-09-09 I widened isStaffView() so admins would stop being skipped entirely, and
     handed them that same treatment — a repeating tone and a screen takeover for ANY
     unread notification. So "Safia Ali added a new family" seized a director's screen and
     would not stop making noise. That is not an urgent alert, and a director at a desk is
     already looking at the badge.

     So: floor staff keep the insistent behaviour they were designed around. Everybody else
     is told ONCE — one chime, and only for an actual message, never for routine portal
     activity. (Anthony, 2026-09-09) */
  function isFloorStaff() {
    try {
      var va = sessionStorage.getItem('kt_view_as') || '';
      var roles;
      if (va) roles = [va];
      else {
        var u = JSON.parse(sessionStorage.getItem('kt_user') || localStorage.getItem('kt_user') || '{}');
        roles = [].concat(u.roles || [], u.primary_role ? [u.primary_role] : []);
      }
      // A director who also holds an educator role is on the floor; an admin is not.
      return ['educator', 'home_visitor'].some(function (r) { return roles.indexOf(r) > -1; });
    } catch (e) { return false; }
  }

  /* '/dashboard.html#chat?c=8' and 'chat?c=8' and '#chat?c=8' all mean the same screen.
     Notification rows store the first form; raise() wants a bare hash. */
  function toHash(link) {
    var l = String(link || '').trim();
    if (!l) return '';
    var i = l.indexOf('#');
    if (i > -1) l = l.slice(i + 1);
    return l.replace(/^#/, '');
  }

  function get(path) {
    var t = tok(); if (!t) return Promise.resolve(null);
    return fetch(apiBase() + path, { headers: { 'Authorization': 'Bearer ' + t, 'Accept': 'application/json' } })
      .then(function (r) { return r.ok ? r.json() : null; })
      .catch(function () { return null; });
  }

  // ── Sound: a two-note chime, synthesised so we ship no audio asset ──
  var audioCtx = null;
  var canSound = false;
  ['pointerdown', 'keydown', 'touchstart'].forEach(function (e) {
    window.addEventListener(e, function () { canSound = true; }, { once: true, capture: true });
  });
  function chime() {
    if (!canSound) return;
    try {
      audioCtx = audioCtx || new (window.AudioContext || window.webkitAudioContext)();
      if (audioCtx.state === 'suspended') audioCtx.resume();
      [[880, 0], [1245, 0.18]].forEach(function (pair) {
        var osc = audioCtx.createOscillator(), gain = audioCtx.createGain();
        var t0 = audioCtx.currentTime + pair[1];
        osc.type = 'sine'; osc.frequency.value = pair[0];
        gain.gain.setValueAtTime(0.0001, t0);
        gain.gain.exponentialRampToValueAtTime(0.32, t0 + 0.02);
        gain.gain.exponentialRampToValueAtTime(0.0001, t0 + 0.42);
        osc.connect(gain); gain.connect(audioCtx.destination);
        osc.start(t0); osc.stop(t0 + 0.45);
      });
    } catch (e) {}
  }
  function buzz() { try { if (navigator.vibrate) navigator.vibrate(VIBE); } catch (e) {} }

  function stopAll() {
    timers.forEach(clearInterval); timers = [];
    try { if (navigator.vibrate) navigator.vibrate(0); } catch (e) {}
    document.body.classList.remove('kt-urgent-flash');
  }

  function style() {
    if (document.getElementById('kt-urgent-style')) return;
    var s = document.createElement('style');
    s.id = 'kt-urgent-style';
    s.textContent = [
      '@keyframes kt-urgent-pulse{0%,100%{background:rgba(220,38,38,.96);}50%{background:rgba(248,113,113,.96);}}',
      '@keyframes kt-urgent-msg-pulse{0%,100%{background:rgba(21,159,180,.97);}50%{background:rgba(56,189,208,.97);}}',
      '@keyframes kt-urgent-shake{0%,100%{transform:translateX(0);}25%{transform:translateX(-6px);}75%{transform:translateX(6px);}}',
      '#kt-urgent{position:fixed;inset:0;z-index:2147483000;display:flex;flex-direction:column;align-items:center;justify-content:center;',
      '  text-align:center;padding:30px 26px;color:#fff;font-family:system-ui,-apple-system,sans-serif;animation:kt-urgent-pulse 1s ease-in-out infinite;}',
      '#kt-urgent.msg{animation:kt-urgent-msg-pulse 1s ease-in-out infinite;}',
      '#kt-urgent .ic{font-size:66px;line-height:1;margin-bottom:14px;animation:kt-urgent-shake .6s ease-in-out 3;}',
      '#kt-urgent h2{font-size:25px;font-weight:800;margin:0 0 8px;}',
      '#kt-urgent p{font-size:15.5px;line-height:1.5;margin:0 0 6px;max-width:320px;opacity:.95;}',
      '#kt-urgent .from{font-size:13px;opacity:.85;margin-bottom:22px;}',
      '#kt-urgent button{width:100%;max-width:300px;border:none;border-radius:14px;padding:16px;font-size:16px;font-weight:800;cursor:pointer;}',
      '#kt-urgent .open{background:#fff;color:#0B2545;margin-bottom:10px;}',
      '#kt-urgent .dismiss{background:transparent;color:rgba(255,255,255,.9);border:1.5px solid rgba(255,255,255,.55);}',
      // The whole screen "lights up" even behind the overlay (visible in the
      // status-bar area / on a re-render).
      'body.kt-urgent-flash{animation:kt-urgent-pulse 1s ease-in-out infinite;}',
    ].join('\n');
    document.head.appendChild(s);
  }

  // kind: 'message' | 'alert'
  function raise(kind, title, body, hash) {
    if (active) return;             // one at a time — don't stack takeovers
    style();
    var ov = document.createElement('div');
    ov.id = 'kt-urgent';
    if (kind === 'message') ov.className = 'msg';
    ov.innerHTML =
      '<div class="ic">' + (kind === 'message' ? '💬' : '🚨') + '</div>'
      + '<h2>' + (kind === 'message' ? 'New message' : 'New alert') + '</h2>'
      + '<p id="kt-urgent-body"></p>'
      + '<div class="from" id="kt-urgent-from"></div>'
      + '<button type="button" class="open">Open</button>'
      // aria-label="Close" also tells kt-modal-guard.js this card already has a
      // close control, so it won't graft its own "×" into the middle of it.
      + '<button type="button" class="dismiss" data-close aria-label="Close">Dismiss</button>';
    document.body.appendChild(ov);
    ov.querySelector('#kt-urgent-body').textContent = title || (kind === 'message' ? 'You have a new message.' : 'You have a new alert.');
    ov.querySelector('#kt-urgent-from').textContent = body || '';
    document.body.classList.add('kt-urgent-flash');
    active = ov;

    chime(); buzz();
    /* Repeating is for somebody who cannot look at the screen. For everyone else one tone
       is the whole notification — a tone every 2.6s until you find the Dismiss button is
       not urgency, it is nagging, and it is what changed for admins today. */
    if (isFloorStaff()) {
      timers.push(setInterval(chime, CHIME_EVERY_MS));
      timers.push(setInterval(buzz, CHIME_EVERY_MS));
    }

    var close = function (go) {
      stopAll();
      if (ov.parentNode) ov.parentNode.removeChild(ov);
      active = null;
      if (!go) return;
      /* THE THING THAT WAS ANNOUNCED, not the screen it lives on.

         'Open' used to navigate to a bare '#chat' or '#notifications' — the LIST — even
         though the notification row it was raised from carries the exact destination in
         data.url. So being told "a message arrived" and pressing Open dropped you on a
         list and left you to find it.

         And when the hash was ALREADY that value, assigning it fires no hashchange, so
         the button did visibly nothing at all — which is how this was reported: "I click
         open and it does nothing and just goes into the portal". Re-render explicitly in
         that case. (Anthony, 2026-09-09) */
      var target = '#' + toHash(hash);
      if (location.hash === target) {
        try { if (window.KT && KT.Shell && KT.Shell.renderScreen) { KT.Shell.renderScreen(); return; } } catch (e) {}
      }
      location.hash = target;
    };
    ov.querySelector('.open').addEventListener('click', function () { close(true); });
    ov.querySelector('.dismiss').addEventListener('click', function () { close(false); });
    // Register with the overlay stack so Android back closes it too — but it
    // still requires a deliberate action, which is the point.
    if (KT.pushOverlay) KT.pushOverlay(ov, function () { close(false); });
  }

  function poll() {
    if (!tok() || !isStaffView() || !enabled()) return;
    if (active) return;                       // already shouting
    if (document.hidden) return;              // backgrounded → the OS notification owns it

    Promise.all([get('/chats/unread-count'), get('/notifications')]).then(function (res) {
      var msg = (res[0] && (res[0].unread | 0)) || 0;
      var rows = res[1] ? (res[1].data || res[1].notifications || (Array.isArray(res[1]) ? res[1] : [])) : [];
      if (!Array.isArray(rows)) rows = [];
      var unread = rows.filter(function (n) { return !n.read_at; });
      var notif = unread.length;

      // First poll of the session just establishes the baseline — otherwise
      // every launch would scream about messages the user already knows about.
      if (lastSeen === null) { lastSeen = { msg: msg, notif: notif }; return; }

      var sec = (location.hash || '').replace('#', '').split('?')[0];
      /* The newest unread row's own destination, when it has one. A chat message writes a
         notification row carrying data.url = the THREAD, so a takeover raised by a message
         can open that message rather than the conversation list. */
      var top = unread[0] || {};
      var topLink = '';
      try {
        var dat = top.data;
        if (typeof dat === 'string') { dat = JSON.parse(dat); }
        topLink = toHash((dat && dat.url) || '');
      } catch (e) {}

      if (msg > lastSeen.msg && sec !== 'chat') {
        // Prefer the thread the newest unread CHAT row names; fall back to the list.
        var chatLink = (top.type === 'chat' || top.type === 'team_message') && topLink ? topLink : 'chat';
        raise('message', 'You have ' + msg + ' unread message' + (msg === 1 ? '' : 's') + '.', 'Tap Open to read it now.', chatLink);
      } else if (notif > lastSeen.notif && sec !== 'notifications' && sec !== 'announcements') {
        /* A general notification is portal ACTIVITY — a family added, a form signed, an
           invoice issued. Worth a badge; not worth seizing the screen. Only floor staff,
           who cannot watch a badge, get a takeover for one. Everybody else finds it in the
           bell, exactly as before today. */
        if (isFloorStaff()) {
          raise('alert', top.title || 'You have a new alert.', top.body || '', topLink || 'notifications');
        }
      }
      lastSeen = { msg: msg, notif: notif };
    });
  }

  KT.urgentAlert = {
    test: function (kind) { raise(kind || 'message', 'Test alert', 'This is what a new message looks like.', kind === 'alert' ? 'notifications' : 'chat'); },
    // Show a real takeover on demand — used when the app is cold-launched by an
    // urgent push's full-screen intent (the message wasn't "received" in-app).
    show: function (title, body, link) { raise('message', title || 'New message', body || '', toHash(link) || 'chat'); },
    /* Raised straight from an incoming urgent push while the app is OPEN. The poll only
       looks every 15s and only compares counts, so a message arriving in the foreground
       waited up to fifteen seconds and then announced itself as "you have N unread"
       rather than as the message that just landed. */
    fromPush: function (title, body, link) {
      if (!isStaffView() || !enabled()) { return false; }
      raise('message', title || 'New message', body || '', toHash(link) || 'chat');
      return true;
    },
    isEnabled: enabled,
    setEnabled: function (on) { try { localStorage.setItem('kt_urgent_alerts', on ? '1' : '0'); } catch (e) {} },
  };

  // Cold-launch from a full-screen-intent push: MainActivity stashes
  // kt_pending_takeover; show the takeover once the module is ready.
  try {
    var pend = localStorage.getItem('kt_pending_takeover');
    if (pend) {
      localStorage.removeItem('kt_pending_takeover');
      var info = {}; try { info = JSON.parse(pend); } catch (e) {}
      setTimeout(function () { try { KT.urgentAlert.show(info.title, info.body, info.link); } catch (e) {} }, 1200);
    }
  } catch (e) {}

  setInterval(poll, POLL_MS);
  setTimeout(poll, 4000);       // establish the baseline shortly after boot
  // Coming back to the app is the moment to check — the badge may have moved
  // while we were away.
  document.addEventListener('visibilitychange', function () { if (!document.hidden) setTimeout(poll, 800); });
})(window);
