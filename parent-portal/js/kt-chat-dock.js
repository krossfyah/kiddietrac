/* ===================================================================
   KiddieTrac — Chat Dock (desktop, 2026-07-23).

   A persistent, minimisable chat window that lives on <body> (NOT inside
   #appMain), so an open conversation survives SPA navigation between
   sections. Used by every role's chat:
     • guardian  → screen-parent.js  (openThreadMobile)
     • educator / director / admin / home_visitor → screen-chat.js (openThread)

   The chat screens render their existing thread markup into
   KT.ChatDock.contentEl(); the dock adds the window chrome (minimise / close)
   and a minimised bar that flashes when a new message arrives while collapsed.
   Desktop only — phones keep their full-screen thread.
   =================================================================== */
(function (w) {
  'use strict';
  var KT = w.KT; if (!KT) return;
  if (KT.ChatDock) return;

  function isDesktop() { return w.matchMedia && w.matchMedia('(min-width: 769px)').matches; }

  var dock = null, bodyEl = null, titleEl = null, minTitleEl = null;
  /* Set as soon as the user opens any thread. restoreSession() waits up to 8s for a
     token and then re-enters itself after pullRemote — long enough that a restore could
     land on top of a thread the user had already clicked into. */
  var userOpened = false;
  var minimized = false, onClose = null;

  function injectStyle() {
    if (document.getElementById('kt-chat-dock-style')) return;
    var s = document.createElement('style'); s.id = 'kt-chat-dock-style';
    s.textContent = [
      // Autosizing window: never wider than the viewport (min 320 → up to 400px),
      // never taller than the space above the fold. dvh handles browser URL bars.
      '#kt-chat-dock{position:fixed;right:clamp(10px,1.6vw,24px);bottom:0;z-index:9400;',
      '  width:min(400px,calc(100vw - 20px));height:min(78vh,620px);height:min(78dvh,620px);',
      '  min-height:340px;max-height:calc(100vh - 76px);max-height:calc(100dvh - 76px);',
      '  background:#fff;border:1px solid #E5E7EB;border-bottom:none;',
      '  border-radius:16px 16px 0 0;box-shadow:0 24px 70px rgba(8,20,36,.35);display:flex;flex-direction:column;',
      '  overflow:hidden;animation:kt-cd-rise .2s cubic-bezier(.22,.61,.36,1);}',
      // A short viewport (small laptop / landscape) — let the window use the full height.
      '@media (max-height:560px){#kt-chat-dock{min-height:0;height:calc(100dvh - 60px);top:52px;bottom:0;}}',
      '@keyframes kt-cd-rise{from{transform:translateY(24px);opacity:0;}to{transform:none;opacity:1;}}',
      // Expanded control strip (minimise / close)
      '#kt-chat-dock .kt-cd-ctrl{cursor:grab;flex:0 0 auto;display:flex;align-items:center;gap:6px;justify-content:flex-end;',
      '  padding:6px 8px;background:linear-gradient(135deg,#EAF3FB,#F3F0FF);border-bottom:1px solid rgba(31,96,128,.10);}',
      '#kt-chat-dock .kt-cd-ctrl .kt-cd-ct-title{flex:1;min-width:0;font-weight:800;font-size:13px;color:#0F172A;',
      '  overflow:hidden;text-overflow:ellipsis;white-space:nowrap;padding-left:4px;}',
      '#kt-chat-dock .kt-cd-btn{width:26px;height:26px;border:none;border-radius:7px;background:rgba(15,23,42,.06);',
      '  color:#334155;font-size:16px;line-height:1;cursor:pointer;display:flex;align-items:center;justify-content:center;padding:0;}',
      '#kt-chat-dock .kt-cd-btn:hover{background:rgba(15,23,42,.14);}',
      '#kt-chat-dock .kt-cd-body{flex:1 1 auto;min-height:0;display:flex;flex-direction:column;overflow:hidden;}',
      // Two panes. The dock is wider when the list is showing, because 400px split
      // two ways leaves neither side usable.
      '#kt-chat-dock{width:min(560px,calc(100vw - 20px));}',
      '#kt-chat-dock.kt-cd-narrow{width:min(400px,calc(100vw - 20px));}',
      '#kt-chat-dock .kt-cd-main{flex:1 1 auto;min-height:0;display:flex;overflow:hidden;}',
      '#kt-chat-dock .kt-cd-side{flex:0 0 var(--kt-cd-sw,210px);min-width:0;display:flex;flex-direction:column;',
      '  border-right:1px solid #E7EBF0;background:#FAFBFC;overflow:hidden;}',
      '#kt-chat-dock.kt-cd-narrow .kt-cd-side{flex:0 0 0 !important;width:0 !important;padding:0 !important;border-right:0;overflow:hidden;}',
      '#kt-chat-dock .kt-cd-side-hd{flex:0 0 auto;display:flex;align-items:center;justify-content:space-between;',
      '  padding:8px 10px;font-size:10.5px;font-weight:800;color:#64748B;letter-spacing:.06em;',
      '  text-transform:uppercase;border-bottom:1px solid #EEF2F6;white-space:nowrap;}',
      '#kt-chat-dock .kt-cd-side-toggle{background:none;border:0;color:#64748B;cursor:pointer;',
      '  font-size:13px;line-height:1;padding:2px 4px;border-radius:5px;}',
      '#kt-chat-dock .kt-cd-gutter{flex:0 0 5px;cursor:col-resize;background:transparent;}',
      '#kt-chat-dock .kt-cd-gutter:hover,#kt-chat-dock .kt-cd-gutter.on{background:#CBD5E1;}',
      '#kt-chat-dock.kt-cd-narrow .kt-cd-gutter{display:none;}',
      '#kt-chat-dock .kt-cd-newchat{background:none;border:0;color:#1F6080;cursor:pointer;',
      '  font-size:17px;line-height:1;padding:0 4px;border-radius:5px;font-weight:700;}',
      '#kt-chat-dock .kt-cd-newchat:hover{background:#E8F1F6;}',
      '#kt-chat-dock .kt-cd-pick-search{width:100%;box-sizing:border-box;border:1px solid #D9E2EA;',
      '  border-radius:6px;padding:6px 8px;font:inherit;font-size:12px;margin:8px 8px 4px;',
      '  width:calc(100% - 16px);}',
      '#kt-chat-dock .kt-cd-back{background:none;border:0;color:#64748B;cursor:pointer;',
      '  font-size:11px;padding:6px 10px;font-weight:700;}',
      '#kt-chat-dock .kt-cd-compose{padding:8px 10px;display:flex;flex-direction:column;gap:6px;}',
      '#kt-chat-dock .kt-cd-compose textarea{border:1px solid #D9E2EA;border-radius:6px;',
      '  padding:6px 8px;font:inherit;font-size:12px;min-height:66px;resize:vertical;}',
      '#kt-chat-dock .kt-cd-compose button{background:#1F6080;color:#fff;border:0;border-radius:6px;',
      '  padding:7px 10px;font:inherit;font-size:12px;font-weight:700;cursor:pointer;}',
      '#kt-chat-dock .kt-cd-compose button[disabled]{opacity:.55;cursor:default;}',
      '#kt-chat-dock .kt-cd-side-list{flex:1 1 auto;min-height:0;overflow-y:auto;}',
      '#kt-chat-dock .kt-cd-conv{display:block;width:100%;text-align:left;background:none;border:0;',
      '  border-bottom:1px solid #F1F5F9;padding:8px 10px;cursor:pointer;font:inherit;}',
      '#kt-chat-dock .kt-cd-conv:hover{background:#F1F5F9;}',
      // Unread needs to be visible at a glance down a list of fifty. Tint plus a
      // left accent bar; the .on rule below still wins for the row you are reading.
      '#kt-chat-dock .kt-cd-conv.unread{background:#EFF5FB;box-shadow:inset 3px 0 0 #8EC73C;}',
      '#kt-chat-dock .kt-cd-conv.unread:hover{background:#E4EEF7;}',
      '#kt-chat-dock .kt-cd-conv.on{background:#E8F1F6;box-shadow:inset 3px 0 0 #1F6080;}',
      // A phone cannot show two panes at 400px — the list collapses away.
      '@media (max-width:900px){#kt-chat-dock .kt-cd-side{display:none;}',
      '  #kt-chat-dock{width:min(400px,calc(100vw - 20px));}}',
      // The thread markup the screens inject fills the body; kill its own fixed sizing.
      '#kt-chat-dock .kt-cd-body > *{flex:1 1 auto;min-height:0;height:auto !important;max-height:none !important;',
      '  position:static !important;transform:none !important;width:auto !important;border-radius:0 !important;',
      '  box-shadow:none !important;border:none !important;display:flex !important;flex-direction:column !important;}',
      // The thread carries its own header (title + nudge) — keep it, drop its back/close
      // (the dock owns those). The parent thread\'s back is a class-less first button.
      '#kt-chat-dock .kt-back,#kt-chat-dock .kt-thread-close-desk,#kt-chat-dock .kt-thread-header > button:first-child{display:none !important;}',
      // Minimised bar — the whole dock collapses to this slim, clickable strip.
      '#kt-chat-dock .kt-cd-min{display:none;align-items:center;gap:8px;padding:11px 12px;cursor:pointer;',
      '  background:linear-gradient(135deg,#EAF3FB,#F3F0FF);}',
      '#kt-chat-dock .kt-cd-min .kt-cd-mtitle{flex:1;min-width:0;font-weight:800;font-size:14px;color:#0F172A;',
      '  overflow:hidden;text-overflow:ellipsis;white-space:nowrap;}',
      '#kt-chat-dock.kt-cd-mini{height:auto !important;min-height:0 !important;max-height:none !important;top:auto !important;left:auto !important;right:16px !important;bottom:16px !important;width:min(300px,calc(100vw - 20px)) !important;}',
      '#kt-chat-dock.kt-cd-mini .kt-cd-ctrl,#kt-chat-dock.kt-cd-mini .kt-cd-body,#kt-chat-dock.kt-cd-mini .kt-cd-main{display:none !important;}',
      '#kt-chat-dock.kt-cd-mini .kt-cd-min{display:flex;}',
      // Flash the minimised bar when a new message lands while collapsed.
      '#kt-chat-dock.kt-cd-flash .kt-cd-min{animation:kt-cd-flash 1s ease-in-out infinite;}',
      '@keyframes kt-cd-flash{0%,100%{background:linear-gradient(135deg,#EAF3FB,#F3F0FF);}',
      '  50%{background:linear-gradient(135deg,#159FB4,#7C6BB0);}}',
      '#kt-chat-dock.kt-cd-flash .kt-cd-min .kt-cd-mtitle,#kt-chat-dock.kt-cd-flash .kt-cd-min .kt-cd-dot{transition:color .3s;}',
      '#kt-chat-dock .kt-cd-dot{width:9px;height:9px;border-radius:50%;background:#159FB4;flex-shrink:0;}',
      '#kt-chat-dock.kt-cd-flash .kt-cd-min .kt-cd-dot{background:#fff;}',
      // Resize grips: the bottom-right corner, plus thin strips down the right edge
      // and along the bottom for one-axis resizing. The dock is positioned from its
      // top-left, so growing right/down never moves it.
      '#kt-chat-dock .kt-cd-grip{position:absolute;z-index:3;touch-action:none;}',
      '#kt-chat-dock .kt-cd-grip-r{top:34px;right:0;width:8px;bottom:14px;cursor:ew-resize;}',
      '#kt-chat-dock .kt-cd-grip-b{left:0;right:14px;bottom:0;height:8px;cursor:ns-resize;}',
      '#kt-chat-dock .kt-cd-grip-c{right:0;bottom:0;width:16px;height:16px;cursor:nwse-resize;}',
      // The corner gets a visible texture; the edges stay invisible until hovered.
      '#kt-chat-dock .kt-cd-grip-c::after{content:"";position:absolute;right:3px;bottom:3px;',
      '  width:9px;height:9px;border-right:2px solid rgba(15,23,42,.28);',
      '  border-bottom:2px solid rgba(15,23,42,.28);border-radius:0 0 3px 0;}',
      '#kt-chat-dock.kt-cd-mini .kt-cd-grip{display:none;}',
      '#kt-chat-dock.kt-cd-sizing{user-select:none;}',
      '#kt-chat-dock.kt-cd-sizing .kt-cd-body{pointer-events:none;}',
      '@media (max-width:768px){#kt-chat-dock{display:none !important;}}'
    ].join('');
    document.head.appendChild(s);
  }

  /* ── Position: draggable, and remembered ───────────────────────────────
     Saved as a fraction of the viewport rather than pixels, so a position chosen on a
     large monitor lands somewhere sensible on a small one instead of off-screen. Every
     restore is clamped to the current window regardless — a dock whose close button sits
     past the edge cannot be dismissed. */
  var POS_KEY = 'kt_chat_dock_pos';
  var SES_KEY = 'kt_chat_dock_session';

  /* The dock remembers that it is open and which thread it holds, so minimising survives
     a screen change, a reload and a sign-out. Only the intent is stored — the thread is
     re-opened for real on restore, so nobody comes back to a frozen copy of a
     conversation that has moved on. */
  var session = { open: false, minimized: false, key: null, title: '' };
  var opener = null;

  /* The account-level copy. localStorage stays the fast path — it is read synchronously
     so the dock appears where you left it with no flicker — and the server copy is what
     makes that position follow you to another machine. Writes are debounced because
     dragging fires continuously and a PUT per pixel is absurd. */
  function apiBase() { return (w.KT && w.KT.API_BASE) || 'https://api.kiddietrac.com/api/v1'; }
  function token() {
    try { return sessionStorage.getItem('kt_token') || localStorage.getItem('kt_token'); } catch (e) { return null; }
  }

  var pushTimer = null;
  function pushRemote() {
    clearTimeout(pushTimer);
    pushTimer = setTimeout(function () {
      var tok = token();
      if (!tok) { return; }
      var pos = null;
      try { pos = JSON.parse(localStorage.getItem(POS_KEY) || 'null'); } catch (e) {}
      // A closed dock sends null, which the server treats as "forget this".
      var payload = session.open ? { pos: pos, session: session } : null;
      try {
        fetch(apiBase() + '/auth/me/ui-prefs', {
          method: 'PUT',
          headers: { 'Authorization': 'Bearer ' + tok, 'Accept': 'application/json', 'Content-Type': 'application/json' },
          body: JSON.stringify({ chat_dock: payload }),
        }).catch(function () {});
      } catch (e) {}
    }, 700);
  }

  /** Pull the account copy and adopt it when this browser has nothing of its own. */
  function pullRemote(done) {
    var tok = token();
    if (!tok) { done && done(); return; }
    fetch(apiBase() + '/auth/me/ui-prefs', {
      headers: { 'Authorization': 'Bearer ' + tok, 'Accept': 'application/json' },
    }).then(function (r) { return r.ok ? r.json() : null; }).then(function (j) {
      var d = j && j.prefs && j.prefs.chat_dock;
      if (d) {
        // This browser wins if it already has an opinion — you moved it here, on this
        // screen, most recently. The account copy is for a machine that has none.
        try {
          if (d.pos && !localStorage.getItem(POS_KEY)) { localStorage.setItem(POS_KEY, JSON.stringify(d.pos)); }
          if (d.session && !localStorage.getItem(SES_KEY)) {
            /* Stamped on the way in. This copy came back from THIS account's own
               ui-prefs, so its owner is not in doubt — and restoreSession() will not
               touch an unstamped record. */
            d.session.user = currentUser();
            localStorage.setItem(SES_KEY, JSON.stringify(d.session));
          }
        } catch (e) {}
      }
      done && done();
    }).catch(function () { done && done(); });
  }

  function saveSession() {
    try {
      if (session.open) { localStorage.setItem(SES_KEY, JSON.stringify(session)); }
      else { localStorage.removeItem(SES_KEY); }
    } catch (e) {}
    pushRemote();
  }

  /** Screens call this so the dock knows what to re-open. */
  function setOpener(fn) {
    opener = fn;
    restoreSession();
  }

  /** Called by a screen as it opens a thread into the dock. The ONLY writer of the
      saved session, so key and title can never drift apart. Called twice per open:
      once with a placeholder title as the thread starts loading, then again with the
      real name once it has loaded. */
  /** Which agency the portal is scoped to right now. A thread only means something
      inside its own agency, so the saved session has to carry it. */
  function currentAgency() {
    try {
      return String(sessionStorage.getItem('kt_active_agency_id')
        || localStorage.getItem('kt_active_agency_id') || '');
    } catch (e) { return ''; }
  }

  /** WHO SAVED IT. localStorage is shared by every account that signs in on this
      browser, and this record survives a sign-out by design, so without an owner the
      dock re-opens the LAST person's conversation for the NEXT person. It did: on
      2026-09-17 a staff thread between two Test Agency educators was restored under
      Anthony's own session, and only the API refusing him — 404, not a participant —
      kept the messages off the screen. A shared tablet in a room is the same shape with
      two real educators. The agency stamp already here was not enough: the thread was
      in the agency he was scoped to. */
  function currentUser() {
    try {
      var raw = sessionStorage.getItem('kt_user') || localStorage.getItem('kt_user');
      var id = raw ? (JSON.parse(raw) || {}).id : null;
      return id == null ? '' : String(id);
    } catch (e) { return ''; }
  }

  function rememberThread(key, title) {
    var k = key == null ? null : String(key);
    // A different thread than the one remembered: the old title must not survive it.
    var sameThread = (k !== null && k === session.key);
    session.open = true;
    session.key = k;
    session.title = title || (sameThread ? session.title : '') || '';
    session.agency = currentAgency();
    session.user = currentUser();
    // Whatever the user just opened outranks a restore still queued from last session.
    userOpened = true;
    saveSession();
  }

  /** Drop the remembered thread — it no longer resolves, so stop re-opening it. */
  function forget() {
    session.open = false;
    session.key = null;
    session.title = '';
    userOpened = true;          // and do not let a queued restore put it back
    saveSession();
  }

  function restoreSession() {
    if (!opener || !isDesktop()) { return; }
    // The user has already opened something themselves — restoring now would replace
    // the conversation they are actually looking at.
    if (userOpened) { return; }
    // Not before the app can actually fetch: this runs at script load, when the token may
    // not be in place yet. Re-opening too early puts an auth error in the dock instead of
    // the conversation, which is worse than restoring a moment later.
    var tok = null;
    try { tok = sessionStorage.getItem('kt_token') || localStorage.getItem('kt_token'); } catch (e) {}
    if (!tok) {
      if (restoreSession._tries == null) { restoreSession._tries = 0; }
      if (restoreSession._tries++ < 20) { setTimeout(restoreSession, 400); }
      return;
    }
    if (!restoreSession._pulled) {
      // Once per load, and only after a token exists: adopt the account copy first so a
      // machine that has never seen this dock still opens it where the person left it.
      restoreSession._pulled = true;
      pullRemote(function () { restoreSession(); });
      return;
    }
    var saved = null;
    try { saved = JSON.parse(localStorage.getItem(SES_KEY) || 'null'); } catch (e) {}
    if (!saved || !saved.open || !saved.key) { return; }
    /* SOMEBODY ELSE'S CONVERSATION. Not "not yours to see" — the API decides that, and
       it did — but not yours to have re-opened either. An unstamped record predates this
       check and its owner is unknown, which is the same answer: drop it, and let the
       next thread this person opens write a clean one. */
    var me = currentUser();
    if (!me) { return; }          // cannot tell whose it is yet — leave it alone
    if (String(saved.user || '') !== me) {
      try { localStorage.removeItem(SES_KEY); } catch (e) {}
      return;
    }
    /* Belongs to a different agency than the one we are scoped to. Restoring it would
       fetch a thread this session cannot see and print a 404 in the dock — which is
       exactly what was happening on every load, because the portal resets the active
       agency on reload. Left saved, not forgotten: switch back and it reopens. */
    if (saved.agency && currentAgency() && saved.agency !== currentAgency()) { return; }
    // Re-opening is the screen's job; it knows how to fetch and render the thread.
    try {
      var p = opener(saved.key, saved.title);
      var settle = function () {
        if (saved.minimized) { minimize(); }
      };
      if (p && typeof p.then === 'function') { p.then(settle).catch(function () {}); }
      else { setTimeout(settle, 0); }
    } catch (e) {}
  }

  function savePos() {
    if (!dock) return;
    try {
      var r = dock.getBoundingClientRect();
      var vw = Math.max(1, w.innerWidth - r.width);
      var vh = Math.max(1, w.innerHeight - r.height);
      var prev = {};
      try { prev = JSON.parse(localStorage.getItem(POS_KEY) || '{}') || {}; } catch (e2) {}
      localStorage.setItem(POS_KEY, JSON.stringify({
        x: Math.min(1, Math.max(0, r.left / vw)),
        y: Math.min(1, Math.max(0, r.top / vh)),
        // Size travels with the position: one record, one sync, never out of step.
        // Preserved when this is a move rather than a resize.
        // Store the THREAD width so the sidebar can be added back on top later.
        w: Math.round(prev.w || (r.width - sideWidth())),
        h: Math.round(prev.h || r.height),
      }));
    } catch (e) {}
    pushRemote();
  }

  /** How wide the conversation list is right now: 0 when collapsed or on a phone,
      otherwise the reader's saved choice. Every sizing calculation reads THIS --
      172 used to be written out separately in three places and they drifted. */
  var SIDE_MIN = 150, SIDE_MAX = 340, SIDE_DEFAULT = 210;
  function savedSideWidth() {
    var v = parseInt(localStorage.getItem('kt_cd_sw') || '', 10);
    return (v >= SIDE_MIN && v <= SIDE_MAX) ? v : SIDE_DEFAULT;
  }
  function sideWidth() {
    if (!dock) { return 0; }
    if (dock.classList.contains('kt-cd-narrow') || w.innerWidth <= 900) { return 0; }
    return savedSideWidth();
  }

  /** Smallest usable dock; below this the header and composer collide. */
  var MIN_W = 320, MIN_H = 340;

  /** Size first — the position clamp below depends on how big the dock is. */
  function applySize(saved) {
    if (!dock || !saved) { return; }
    if (typeof saved.w !== 'number' || typeof saved.h !== 'number') { return; }
    // Clamped to THIS screen: a size chosen on a large monitor must not open a dock
    // that hangs off a laptop display.
    var maxW = Math.max(MIN_W, w.innerWidth - 20);
    var maxH = Math.max(MIN_H, w.innerHeight - 76);
    /* The saved width is the width of the THREAD — that is what the person dragged.
       With the conversation list expanded the dock has to be that much WIDER, or the
       list eats the thread: a dock saved at 417px left only 243px to read in. */
    var sideW = sideWidth();
    dock.style.width = Math.round(Math.min(maxW, Math.max(MIN_W, saved.w) + sideW)) + 'px';
    dock.style.height = Math.round(Math.min(maxH, Math.max(MIN_H, saved.h))) + 'px';
    dock.style.maxHeight = 'none';
  }

  /** Drag the gutter to resize the conversation list.
      The dock keeps its overall width and the two panes redistribute, which is how
      a divider is expected to behave. The stored thread width is updated to match,
      so re-opening the dock does not undo the drag. */
  function installSideResize() {
    var g = dock.querySelector('.kt-cd-gutter');
    if (!g) { return; }
    g.addEventListener('pointerdown', function (e) {
      e.preventDefault();
      e.stopPropagation();            // the header drag handler must not see this
      var side = dock.querySelector('.kt-cd-side');
      if (!side) { return; }
      var startX = e.clientX, w0 = side.getBoundingClientRect().width;
      g.classList.add('on');
      try { g.setPointerCapture(e.pointerId); } catch (err) {}

      function move(ev) {
        var px = Math.round(Math.min(SIDE_MAX, Math.max(SIDE_MIN, w0 + (ev.clientX - startX))));
        dock.style.setProperty('--kt-cd-sw', px + 'px');
        side.style.flex = '0 0 ' + px + 'px';
      }
      function up(ev) {
        g.classList.remove('on');
        try { g.releasePointerCapture(ev.pointerId); } catch (err) {}
        g.removeEventListener('pointermove', move);
        g.removeEventListener('pointerup', up);
        var px = Math.round(side.getBoundingClientRect().width);
        try { localStorage.setItem('kt_cd_sw', String(px)); } catch (err) {}
        /* The stored dock geometry records the THREAD width. Re-derive it from
           what is on screen now, or the next open restores the pre-drag split. */
        try {
          var pos = JSON.parse(localStorage.getItem(POS_KEY) || '{}') || {};
          pos.w = Math.round(dock.getBoundingClientRect().width - px);
          localStorage.setItem(POS_KEY, JSON.stringify(pos));
        } catch (err) {}
      }
      g.addEventListener('pointermove', move);
      g.addEventListener('pointerup', up);
    });
  }

  function installResize() {
    dock.querySelectorAll('.kt-cd-grip').forEach(function (grip) {
      grip.addEventListener('pointerdown', function (e) {
        e.preventDefault();
        e.stopPropagation();          // never let the drag handler see this
        var axis = grip.getAttribute('data-axis') || 'xy';
        var r = dock.getBoundingClientRect();
        var startX = e.clientX, startY = e.clientY, w0 = r.width, h0 = r.height;
        // Pin the top-left, so resizing grows the dock rather than moving it.
        dock.style.left = Math.round(r.left) + 'px';
        dock.style.top = Math.round(r.top) + 'px';
        dock.style.right = 'auto';
        dock.style.bottom = 'auto';
        dock.style.maxHeight = 'none';
        dock.classList.add('kt-cd-sizing');
        try { grip.setPointerCapture(e.pointerId); } catch (e2) {}

        function move(ev) {
          if (axis !== 'y') {
            var maxW = Math.max(MIN_W, w.innerWidth - r.left - 8);
            dock.style.width = Math.round(Math.min(maxW, Math.max(MIN_W, w0 + (ev.clientX - startX)))) + 'px';
          }
          if (axis !== 'x') {
            var maxH = Math.max(MIN_H, w.innerHeight - r.top - 8);
            dock.style.height = Math.round(Math.min(maxH, Math.max(MIN_H, h0 + (ev.clientY - startY)))) + 'px';
          }
        }
        function up() {
          document.removeEventListener('pointermove', move);
          document.removeEventListener('pointerup', up);
          dock.classList.remove('kt-cd-sizing');
          var rr = dock.getBoundingClientRect();
          // Write the new size into the shared record, then let savePos persist + sync.
          try {
            var cur = JSON.parse(localStorage.getItem(POS_KEY) || '{}') || {};
            cur.w = Math.round(rr.width);
            cur.h = Math.round(rr.height);
            localStorage.setItem(POS_KEY, JSON.stringify(cur));
          } catch (e2) {}
          savePos();
        }
        document.addEventListener('pointermove', move);
        document.addEventListener('pointerup', up);
      });
    });
  }

  function applyPos() {
    if (!dock) return;
    var saved = null;
    try { saved = JSON.parse(localStorage.getItem(POS_KEY) || 'null'); } catch (e) {}
    if (!saved) { return; }
    applySize(saved);
    if (typeof saved.x !== 'number' || typeof saved.y !== 'number') { return; }
    // Measured after the dock is visible, or the rect is zero and everything clamps to 0.
    var r = dock.getBoundingClientRect();
    var maxL = Math.max(0, w.innerWidth - r.width);
    var maxT = Math.max(0, w.innerHeight - r.height);
    dock.style.left = Math.round(Math.min(maxL, Math.max(0, saved.x * maxL))) + 'px';
    dock.style.top = Math.round(Math.min(maxT, Math.max(0, saved.y * maxT))) + 'px';
    dock.style.right = 'auto';
    dock.style.bottom = 'auto';
  }

  function installDrag() {
    var bar = dock.querySelector('.kt-cd-ctrl');
    var mini = dock.querySelector('.kt-cd-min');
    [bar, mini].forEach(function (handle) {
      if (!handle) return;
      handle.addEventListener('pointerdown', function (e) {
        // Buttons on the bar keep doing their own job.
        if (e.target.closest('button')) { return; }
        e.preventDefault();
        var r = dock.getBoundingClientRect();
        var dx = e.clientX - r.left;
        var dy = e.clientY - r.top;
        dock.style.right = 'auto';
        dock.style.bottom = 'auto';
        handle.style.cursor = 'grabbing';

        function move(ev) {
          var maxL = Math.max(0, w.innerWidth - dock.offsetWidth);
          var maxT = Math.max(0, w.innerHeight - dock.offsetHeight);
          dock.style.left = Math.round(Math.min(maxL, Math.max(0, ev.clientX - dx))) + 'px';
          dock.style.top = Math.round(Math.min(maxT, Math.max(0, ev.clientY - dy))) + 'px';
        }
        function up() {
          document.removeEventListener('pointermove', move);
          document.removeEventListener('pointerup', up);
          handle.style.cursor = 'grab';
          savePos();
        }
        document.addEventListener('pointermove', move);
        document.addEventListener('pointerup', up);
      });
    });

    // A window that shrinks must not strand the dock outside it.
    w.addEventListener('resize', function () {
      if (!dock || dock.style.display === 'none') { return; }
      if (dock.style.left === '' || dock.style.left === 'auto') { return; }
      applyPos();
    });
  }

  function ensure() {
    if (dock) return dock;
    injectStyle();
    dock = document.createElement('div');
    dock.id = 'kt-chat-dock';
    dock.innerHTML =
      '<div class="kt-cd-min" role="button" tabindex="0" title="Open chat">'
        + '<span class="kt-cd-dot"></span><span class="kt-cd-mtitle">Chat</span>'
        + '<button class="kt-cd-btn kt-cd-restore" title="Expand">↗</button>'
        + '<button class="kt-cd-btn kt-cd-x2" title="Close">×</button></div>'
      + '<div class="kt-cd-ctrl"><span class="kt-cd-ct-title"></span>'
        + '<button class="kt-cd-btn kt-cd-side-toggle" title="Hide conversations" aria-label="Hide conversations">\u00AB</button>'
        + '<button class="kt-cd-btn kt-cd-mini" title="Minimise">–</button>'
        + '<button class="kt-cd-btn kt-cd-x" title="Close">×</button></div>'
      + '<div class="kt-cd-main">'
        + '<aside class="kt-cd-side">'
          + '<div class="kt-cd-side-hd">Conversations'
            + '<button class="kt-cd-newchat" title="New conversation" aria-label="New conversation">+</button>'
          + '</div>'
          + '<div class="kt-cd-side-list"></div>'
        + '</aside>'
        + '<div class="kt-cd-gutter" title="Drag to resize"></div>'
        + '<div class="kt-cd-body"></div>'
      + '</div>'
      + '<div class="kt-cd-grip kt-cd-grip-r" data-axis="x"></div>'
      + '<div class="kt-cd-grip kt-cd-grip-b" data-axis="y"></div>'
      + '<div class="kt-cd-grip kt-cd-grip-c" data-axis="xy"></div>';
    document.body.appendChild(dock);
    bodyEl = dock.querySelector('.kt-cd-body');
    titleEl = dock.querySelector('.kt-cd-ct-title');
    minTitleEl = dock.querySelector('.kt-cd-mtitle');


    /* Other conversations, without leaving the dock.
       Overlays the body rather than splitting it: at 400px wide a permanent sidebar
       would leave neither pane usable, and this keeps the thread at full width while
       you are reading it. The list is whatever the Messenger screen already fetched —
       no second source of truth to drift. */
    var listBtn = dock.querySelector('.kt-cd-list');
    if (listBtn) {
      listBtn.addEventListener('click', function (e) {
        e.stopPropagation();
        var open = dock.querySelector('.kt-cd-switch');
        if (open) { open.remove(); return; }

        var panel = document.createElement('div');
        panel.className = 'kt-cd-switch';
        panel.style.cssText = 'position:absolute;left:0;right:0;top:34px;bottom:0;background:#fff;'
          + 'z-index:4;overflow-y:auto;border-top:1px solid #E7EBF0;';
        panel.innerHTML = '<div style="padding:10px 12px;font-size:11px;font-weight:800;'
          + 'color:#64748B;letter-spacing:.06em;border-bottom:1px solid #F1F5F9;">CONVERSATIONS</div>'
          + '<div class="kt-cd-switch-body" style="padding:6px 0;font-size:13px;color:#64748B;">'
          + '<div style="padding:14px 12px;">Loading\u2026</div></div>';
        dock.appendChild(panel);

        var body = panel.querySelector('.kt-cd-switch-body');
        var api = (w.KT && KT.Chat && KT.Chat.conversationsForSwitcher);
        Promise.resolve(api ? api() : []).then(function (rows) {
          if (!rows || !rows.length) {
            body.innerHTML = '<div style="padding:14px 12px;">Open Messenger once to load your conversations.</div>';
            return;
          }
          body.innerHTML = '';
          rows.slice(0, 40).forEach(function (c) {
            var item = document.createElement('button');
            item.type = 'button';
            item.style.cssText = 'display:block;width:100%;text-align:left;background:none;border:0;'
              + 'border-bottom:1px solid #F6F8FA;padding:9px 12px;cursor:pointer;font:inherit;';
            var unread = c.unread_count > 0;
            item.innerHTML = '<span style="display:block;font-weight:' + (unread ? '800' : '600')
              + ';color:#0D1B2A;font-size:13px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">'
              + (c.name || 'Conversation')
              + (unread ? ' <span style="background:#8EC73C;color:#fff;font-size:10px;font-weight:800;'
                  + 'padding:1px 6px;border-radius:9px;margin-left:4px;">' + c.unread_count + '</span>' : '')
              + '</span>'
              + '<span style="display:block;font-size:11.5px;color:#64748B;overflow:hidden;'
              + 'text-overflow:ellipsis;white-space:nowrap;margin-top:2px;">' + (c.preview || '') + '</span>';
            item.onmouseenter = function () { item.style.background = '#F7F9FB'; };
            item.onmouseleave = function () { item.style.background = 'none'; };
            item.onclick = function () {
              panel.remove();
              try {
                if (w.KT && KT.Chat && KT.Chat.openThread) { KT.Chat.openThread(c.id, contentEl()); }
              } catch (err) {}
            };
            body.appendChild(item);
          });
        }).catch(function () {
          body.innerHTML = '<div style="padding:14px 12px;">Could not load your conversations.</div>';
        });
      });
    }


    /* The conversation list, beside the thread. Data comes from the Messenger screen's
       own provider, so the two can never show different things. Refreshed each time
       the dock opens rather than polled — it is a switcher, not a live feed. */
    var newChatBtn = dock.querySelector('.kt-cd-newchat');
    if (newChatBtn) {
      newChatBtn.addEventListener('click', function (e) {
        e.stopPropagation();
        openNewChat();
      });
    }

    var sideToggle = dock.querySelector('.kt-cd-side-toggle');
    if (sideToggle) {
      sideToggle.addEventListener('click', function (e) {
        e.stopPropagation();
        var narrow = dock.classList.toggle('kt-cd-narrow');
        /* Inline: the stylesheet rule kept losing to the base declaration even
           with !important. An element style is unambiguous. */
        setSideWidth(narrow);
        // Re-apply the size so the dock grows or shrinks by the list's width
        // instead of the thread changing size underneath the reader.
        try { applyPos(); } catch (err) {}
        sideToggle.innerHTML = narrow ? '\u00BB' : '\u00AB';
        sideToggle.title = narrow ? 'Show conversations' : 'Collapse list';
        try { localStorage.setItem('kt_cd_side', narrow ? '0' : '1'); } catch (err) {}
      });
      try {
        if (localStorage.getItem('kt_cd_side') === '0') {
          dock.classList.add('kt-cd-narrow');
          setSideWidth(true);
          sideToggle.innerHTML = '\u00BB';
        }
      } catch (err) {}
    }

    dock.querySelector('.kt-cd-mini').addEventListener('click', function (e) { e.stopPropagation(); minimize(); });
    dock.querySelector('.kt-cd-x').addEventListener('click', function (e) { e.stopPropagation(); close(); });
    dock.querySelector('.kt-cd-x2').addEventListener('click', function (e) { e.stopPropagation(); close(); });
    dock.querySelector('.kt-cd-min').addEventListener('click', function () { restore(); });
    dock.querySelector('.kt-cd-restore').addEventListener('click', function (e) { e.stopPropagation(); restore(); });
    installDrag();
    installResize();
    installSideResize();

    /* Keep the list current while the dock is open.
       It used to render once on show() and then sit there, so unread counts, ordering
       and presence dots all froze until you closed and reopened it. */
    (function () {
      if (dock.__ktListLive) { return; }
      dock.__ktListLive = true;

      function listVisible() {
        return dock && dock.style.display !== 'none'
          && !dock.classList.contains('kt-cd-mini')
          && !document.hidden;
      }

      function refreshList() {
        if (!listVisible()) { return; }
        /* Never redraw underneath someone mid-action: the picker and the compose box
           both live in this container, and repainting would discard what they typed. */
        if (dock.querySelector('.kt-cd-pick-search') || dock.querySelector('.kt-cd-compose')) { return; }
        // An undo still on offer is a pending decision; repainting would discard it.
        if (dock.querySelector('.kt-cd-undone')) { return; }
        try { fillSideList(); } catch (e) {}
      }

      // Any successful write in the portal rings the bus, so your own actions show at
      // once. Both signals: the custom event for this tab, storage for the others.
      try {
        w.addEventListener('kt:data-changed', refreshList);
        w.addEventListener('storage', function (e) {
          if (e && e.key === 'kt_data_changed') { refreshList(); }
        });
      } catch (e) {}

      // Floor for what other people do, which no local write can tell us about.
      setInterval(refreshList, 20000);
    })();
    /* Apply the saved width before the first paint, so the dock never shows the
       default and then jumps. */
    dock.style.setProperty('--kt-cd-sw', savedSideWidth() + 'px');
    return dock;
  }

  // The element the chat screen renders its thread into.
  /** Collapse or expand the conversation pane. */
  function setSideWidth(narrow) {
    if (!dock) { return; }
    var el = dock.querySelector('.kt-cd-side');
    if (!el) { return; }
    el.style.flex = narrow ? '0 0 0px' : ('0 0 ' + savedSideWidth() + 'px');
    el.style.width = narrow ? '0px' : '';
    el.style.overflow = 'hidden';
    el.style.borderRightWidth = narrow ? '0' : '1px';
  }

  function contentEl() { ensure(); return bodyEl; }

  /** Auth + agency headers, the same ones every other call in the portal sends. */
  function dockHeaders() {
    var tok = null, ag = '';
    try { tok = sessionStorage.getItem('kt_token') || localStorage.getItem('kt_token'); } catch (e) {}
    try { ag = sessionStorage.getItem('kt_active_agency_id') || ''; } catch (e) {}
    return { 'Content-Type': 'application/json', Accept: 'application/json',
             Authorization: 'Bearer ' + tok, 'X-Active-Agency-Id': ag };
  }

  function dockApiBase() {
    return (w.KT && w.KT.API_BASE) || 'https://api.kiddietrac.com/api/v1';
  }

  /** Start a new conversation: pick a colleague, then write the first message.
      Two steps because the endpoint needs both -- and because an empty thread landing
      in someone's inbox saying nothing is worse than no thread at all. */
  function openNewChat() {
    if (!dock) { return; }
    var list = dock.querySelector('.kt-cd-side-list');
    if (!list) { return; }
    dock.classList.remove('kt-cd-narrow');
    setSideWidth(false);

    list.innerHTML = '<button type="button" class="kt-cd-back">\u2190 Back</button>'
      + '<input class="kt-cd-pick-search" type="search" placeholder="Search colleagues\u2026">'
      + '<div class="kt-cd-pick-body" style="padding:10px;font-size:12px;color:#94A3B8;">Loading\u2026</div>';
    list.querySelector('.kt-cd-back').onclick = function () { fillSideList(); };

    var body = list.querySelector('.kt-cd-pick-body');
    var search = list.querySelector('.kt-cd-pick-search');

    fetch(dockApiBase() + '/provider/team-contacts', { headers: dockHeaders() })
      .then(function (r) { return r.ok ? r.json() : null; })
      .then(function (j) {
        var people = (j && j.contacts) || [];
        if (!people.length) {
          body.innerHTML = '<div style="padding:10px;font-size:12px;color:#94A3B8;">No colleagues to message.</div>';
          return;
        }
        function draw(filter) {
          var f = String(filter || '').toLowerCase();
          var shown = people.filter(function (p) { return !f || String(p.name).toLowerCase().indexOf(f) !== -1; });
          body.innerHTML = '';
          if (!shown.length) {
            body.innerHTML = '<div style="padding:10px;font-size:12px;color:#94A3B8;">No match.</div>';
            return;
          }
          shown.forEach(function (p) {
            var b = document.createElement('button');
            b.type = 'button';
            b.className = 'kt-cd-conv';
            b.innerHTML = '<span style="display:block;font-size:12px;font-weight:600;color:#0D1B2A;'
              + 'overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">' + esc(p.name) + '</span>'
              + '<span style="display:block;font-size:10.5px;color:#94A3B8;">' + esc(p.role || 'Staff') + '</span>';
            b.onclick = function () { compose(p); };
            body.appendChild(b);
          });
        }
        search.oninput = function () { draw(search.value); };
        draw('');
      })
      .catch(function () {
        body.innerHTML = '<div style="padding:10px;font-size:12px;color:#94A3B8;">Could not load colleagues.</div>';
      });

    function compose(person) {
      list.innerHTML = '<button type="button" class="kt-cd-back">\u2190 Back</button>'
        + '<div class="kt-cd-compose">'
        + '<div style="font-size:12px;font-weight:800;color:#0D1B2A;">' + esc(person.name) + '</div>'
        + '<textarea placeholder="Write your message\u2026"></textarea>'
        + '<button type="button" class="kt-cd-send">Send</button>'
        + '<div class="kt-cd-err" style="font-size:11px;color:#B91C1C;"></div>'
        + '</div>';
      list.querySelector('.kt-cd-back').onclick = function () { openNewChat(); };
      var ta = list.querySelector('textarea');
      var send = list.querySelector('.kt-cd-send');
      var err = list.querySelector('.kt-cd-err');
      ta.focus();
      send.onclick = function () {
        var text = String(ta.value || '').trim();
        if (!text) { err.textContent = 'Write a message first.'; return; }
        send.disabled = true;
        err.textContent = '';
        fetch(dockApiBase() + '/provider/team-threads/start', {
          method: 'POST', headers: dockHeaders(),
          body: JSON.stringify({ recipient_user_id: person.id, body: text }),
        }).then(function (r) {
          return r.json().then(function (j) { return { ok: r.ok, j: j }; });
        }).then(function (res) {
          if (!res.ok) {
            send.disabled = false;
            err.textContent = (res.j && res.j.message) || 'Could not start that conversation.';
            return;
          }
          var tid = res.j && (res.j.thread_id || res.j.id || (res.j.thread && res.j.thread.id));
          fillSideList();
          if (tid) {
            try {
              if (w.KT && w.KT.Chat && w.KT.Chat.openThread) {
                w.KT.Chat.openThread('staff:' + tid, contentEl());
              }
            } catch (e) {}
          }
        }).catch(function () {
          send.disabled = false;
          err.textContent = 'Could not start that conversation.';
        });
      };
    }
  }

  /** Six seconds to change your mind.
      The row holds its place showing "Removed - Undo" and only then disappears. Undo
      posts archived:false, which is the same call that restored the inbox after this
      control quietly emptied it. */
  function offerUndo(b, c) {
    var kind = String(c.id).indexOf('staff:') === 0 ? 'staff' : 'family';
    var refId = kind === 'staff' ? parseInt(String(c.id).slice(6), 10) : parseInt(String(c.id), 10);

    b.style.opacity = '';
    b.classList.add('kt-cd-undone');
    b.innerHTML = '<span style="flex:1 1 auto;font-size:12px;color:#64748B;">Removed</span>'
      + '<button type="button" class="kt-cd-undo" style="flex:0 0 auto;background:none;border:0;'
      + 'color:#1F6080;font:inherit;font-size:12px;font-weight:800;cursor:pointer;padding:2px 6px;">Undo</button>';

    var done = setTimeout(function () { try { b.remove(); } catch (e) {} }, 6000);

    var undo = b.querySelector('.kt-cd-undo');
    if (!undo) { return; }
    undo.addEventListener('click', function (ev) {
      ev.stopPropagation();        // the row's own click handler must not fire
      ev.preventDefault();
      clearTimeout(done);
      undo.disabled = true;
      undo.textContent = 'Restoring\u2026';
      fetch(dockApiBase() + '/chat-archive', {
        method: 'POST', headers: dockHeaders(),
        body: JSON.stringify({ kind: kind, id: refId, archived: false }),
      }).then(function (r) {
        if (r && r.ok) {
          b.classList.remove('kt-cd-undone');
          fillSideList();          // redraw from the server, not from a stashed copy
          return;
        }
        undo.disabled = false;
        undo.textContent = 'Undo';
      }).catch(function () {
        undo.disabled = false;
        undo.textContent = 'Undo';
      });
    });
  }

  /** Render the conversation list into the side pane. */
  function fillSideList() {
    if (!dock) { return; }
    var list = dock.querySelector('.kt-cd-side-list');
    if (!list) { return; }
    var provider = w.KT && w.KT.Chat && w.KT.Chat.conversationsForSwitcher;
    if (!provider) { list.innerHTML = '<div style="padding:12px 10px;font-size:12px;color:#94A3B8;">Open Messenger once.</div>'; return; }
    Promise.resolve(provider()).then(function (rows) {
      if (!rows || !rows.length) { list.innerHTML = '<div style="padding:12px 10px;font-size:12px;color:#94A3B8;">No conversations yet.</div>'; return; }
      list.innerHTML = '';
      rows.slice(0, 60).forEach(function (c) {
        var b = document.createElement('button');
        b.type = 'button';
        var unreadRow = (c.unread_count || 0) > 0;
        b.className = 'kt-cd-conv' + (unreadRow ? ' unread' : '')
          + (String(c.id) === String(session.key) ? ' on' : '');
        var unread = c.unread_count > 0;
        /* A face, or coloured initials when there is no photo — the same fallback
           the Messenger list uses, so the two look like one product. */
        var initials = String(c.name || '?').trim().split(/\s+/)
          .map(function (x) { return x[0]; }).slice(0, 2).join('').toUpperCase();
        var hue = 0;
        for (var hi = 0; hi < String(c.name || '').length; hi++) {
          hue = (hue * 31 + String(c.name).charCodeAt(hi)) % 360;
        }
        var pic = c.photo
          ? '<span style="flex:0 0 26px;width:26px;height:26px;border-radius:50%;background:#E5E7EB '
            + 'center/cover no-repeat url(' + esc(c.photo) + ');"></span>'
          : '<span style="flex:0 0 26px;width:26px;height:26px;border-radius:50%;background:hsl('
            + hue + ',52%,58%);color:#fff;font-size:10px;font-weight:800;display:flex;'
            + 'align-items:center;justify-content:center;">' + esc(initials) + '</span>';
        /* Presence. Green = seen in the last 2 minutes, amber = the last 15.
           Offline draws nothing: a grey dot on every row is noise, and an absent
           dot already reads as "not around". The ring is the list's own background
           colour, so the dot reads as sitting on top of the avatar. */
        var dotColour = c.presence === 'online' ? '#22C55E'
          : (c.presence === 'away' ? '#F59E0B' : '');
        if (dotColour) {
          pic = '<span style="position:relative;display:inline-flex;flex:0 0 26px;">' + pic
            + '<span title="' + (c.presence === 'online' ? 'Online' : 'Away') + '" '
            + 'style="position:absolute;right:-1px;bottom:-1px;width:9px;height:9px;'
            + 'border-radius:50%;background:' + dotColour + ';box-shadow:0 0 0 2px #FAFBFC;"></span>'
            + '</span>';
        }
        b.style.display = 'flex';
        b.style.alignItems = 'center';
        b.style.gap = '8px';
        b.innerHTML = pic + '<span style="flex:1 1 auto;min-width:0;">'
          + '<span style="display:block;font-size:12px;font-weight:' + (unread ? '800' : '600') + ';'
          + 'color:#0D1B2A;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">' + esc(c.name)
          + (unread ? ' <span style="background:#8EC73C;color:#fff;font-size:9.5px;font-weight:800;padding:0 5px;border-radius:8px;">' + c.unread_count + '</span>' : '')
          + '</span><span style="display:block;font-size:10.5px;color:#94A3B8;overflow:hidden;'
          + 'text-overflow:ellipsis;white-space:nowrap;margin-top:1px;">' + esc(c.preview || '') + '</span>'
          + '</span>'
          /* Remove from the list. Archives rather than destroys — a care record should
             not vanish because somebody tidied their inbox. */
          + '<span class="kt-cd-conv-x" title="Remove from list" role="button" '
          + 'style="flex:0 0 auto;color:#CBD5E1;font-size:15px;line-height:1;padding:2px 4px;">&times;</span>';
        b.onclick = function (ev) {
          /* The x removes the conversation from this list instead of opening it.
             It archives — the thread and its history stay, they just leave the
             inbox, which is what 'end the chat' should mean for a care record. */
          if (ev && ev.target && ev.target.classList.contains('kt-cd-conv-x')) {
            ev.stopPropagation();
            b.style.opacity = '.4';
            var isStaff = String(c.id).indexOf('staff:') === 0;
            var payload = isStaff
              ? { kind: 'staff', id: parseInt(String(c.id).slice(6), 10), archived: true }
              : { kind: 'family', id: parseInt(String(c.id), 10), archived: true };
            var tok = null;
            try { tok = sessionStorage.getItem('kt_token') || localStorage.getItem('kt_token'); } catch (e2) {}
            fetch(((w.KT && w.KT.API_BASE) || 'https://api.kiddietrac.com/api/v1') + '/chat-archive', {
              method: 'POST',
              headers: { 'Content-Type': 'application/json', Accept: 'application/json',
                         Authorization: 'Bearer ' + tok,
                         'X-Active-Agency-Id': (function () {
                           try { return sessionStorage.getItem('kt_active_agency_id') || ''; }
                           catch (e4) { return ''; }
                         })() },
              body: JSON.stringify(payload),
            }).then(function (res) {
              /* fetch does not reject on 4xx. Without this check a rejected archive still
                 removed the row, and the conversation reappeared on the next open. */
              if (res && res.ok) { offerUndo(b, c); return; }
              b.style.opacity = '';
              try {
                if (w.KT && w.KT.toast) { w.KT.toast('Could not remove that conversation.'); }
              } catch (e3) {}
            })
              .catch(function () { b.style.opacity = ''; });
            return;
          }
          try { if (w.KT && w.KT.Chat && w.KT.Chat.openThread) { w.KT.Chat.openThread(c.id, contentEl()); } } catch (e) {}
          Array.prototype.forEach.call(list.children, function (x) { x.classList.remove('on'); });
          b.classList.add('on');
        };
        list.appendChild(b);
      });
    }).catch(function () {
      list.innerHTML = '<div style="padding:12px 10px;font-size:12px;color:#94A3B8;">Could not load.</div>';
    });
  }

  function esc(v) {
    return String(v == null ? '' : v).replace(/&/g, '&amp;').replace(/</g, '&lt;')
      .replace(/>/g, '&gt;').replace(/"/g, '&quot;');
  }

  function show(title, closeFn) {
    ensure();
    onClose = closeFn || null;
    var t = title || 'Chat';
    if (titleEl) titleEl.textContent = t;
    if (minTitleEl) minTitleEl.textContent = t;
    /* Display only — deliberately does NOT write the saved session.
       It has no idea which thread is being shown, so writing session.title here
       stamped a new name onto whatever key was saved last, and the dock came back
       later labelled with somebody who was not in the conversation.
       rememberThread(key, title) owns the session, and takes both together. */
    minimized = false;
    dock.classList.remove('kt-cd-mini', 'kt-cd-flash');
    dock.style.display = '';
    try { fillSideList(); } catch (e) {}
    // After display, never before: a hidden element measures as zero and every
    // clamp below would collapse the restored position to the top-left corner.
    applyPos();
  }

  function minimize() {
    if (!dock) return;
    minimized = true;
    dock.classList.add('kt-cd-mini');
    session.minimized = true;
    saveSession();
  }

  function restore() {
    if (!dock) return;
    minimized = false;
    dock.classList.remove('kt-cd-mini', 'kt-cd-flash');
    session.minimized = false;
    saveSession();
  }

  function close() {
    // Closing is the only thing that clears the remembered session — that is the whole
    // distinction between closing and minimising.
    session = { open: false, minimized: false, key: null, title: '' };
    saveSession();
    var cb = onClose; onClose = null;
    if (dock) { dock.classList.remove('kt-cd-mini', 'kt-cd-flash'); dock.style.display = 'none'; if (bodyEl) bodyEl.innerHTML = ''; }
    minimized = false;
    try { if (cb) cb(); } catch (e) {}
  }

  // Called by the screens' pollers when a fresh INCOMING message arrives.
  function flashIncoming() { if (dock && minimized) dock.classList.add('kt-cd-flash'); }

  function isActive() { return !!(dock && dock.style.display !== 'none' && bodyEl && bodyEl.children.length); }
  function isMinimized() { return minimized; }

  KT.ChatDock = {
    enabled: isDesktop,
    contentEl: contentEl,
    show: show,
    forget: forget,
    hide: close,
    minimize: minimize,
    restore: restore,
    close: close,
    flashIncoming: flashIncoming,
    isActive: isActive,
    isMinimized: isMinimized,
    setOpener: setOpener,
    rememberThread: rememberThread,
  };
})(window);
