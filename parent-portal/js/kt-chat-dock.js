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
      '#kt-chat-dock.kt-cd-mini{height:auto !important;min-height:0 !important;max-height:none !important;top:auto !important;width:min(300px,calc(100vw - 20px)) !important;}',
      '#kt-chat-dock.kt-cd-mini .kt-cd-ctrl,#kt-chat-dock.kt-cd-mini .kt-cd-body{display:none !important;}',
      '#kt-chat-dock.kt-cd-mini .kt-cd-min{display:flex;}',
      // Flash the minimised bar when a new message lands while collapsed.
      '#kt-chat-dock.kt-cd-flash .kt-cd-min{animation:kt-cd-flash 1s ease-in-out infinite;}',
      '@keyframes kt-cd-flash{0%,100%{background:linear-gradient(135deg,#EAF3FB,#F3F0FF);}',
      '  50%{background:linear-gradient(135deg,#159FB4,#7C6BB0);}}',
      '#kt-chat-dock.kt-cd-flash .kt-cd-min .kt-cd-mtitle,#kt-chat-dock.kt-cd-flash .kt-cd-min .kt-cd-dot{transition:color .3s;}',
      '#kt-chat-dock .kt-cd-dot{width:9px;height:9px;border-radius:50%;background:#159FB4;flex-shrink:0;}',
      '#kt-chat-dock.kt-cd-flash .kt-cd-min .kt-cd-dot{background:#fff;}',
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

  function saveSession() {
    try {
      if (session.open) { localStorage.setItem(SES_KEY, JSON.stringify(session)); }
      else { localStorage.removeItem(SES_KEY); }
    } catch (e) {}
  }

  /** Screens call this so the dock knows what to re-open. */
  function setOpener(fn) {
    opener = fn;
    restoreSession();
  }

  /** Called by a screen as it opens a thread into the dock. */
  function rememberThread(key, title) {
    session.open = true;
    session.key = key == null ? null : String(key);
    session.title = title || session.title || '';
    saveSession();
  }

  function restoreSession() {
    if (!opener || !isDesktop()) { return; }
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
    var saved = null;
    try { saved = JSON.parse(localStorage.getItem(SES_KEY) || 'null'); } catch (e) {}
    if (!saved || !saved.open || !saved.key) { return; }
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
      localStorage.setItem(POS_KEY, JSON.stringify({
        x: Math.min(1, Math.max(0, r.left / vw)),
        y: Math.min(1, Math.max(0, r.top / vh)),
      }));
    } catch (e) {}
  }

  function applyPos() {
    if (!dock) return;
    var saved = null;
    try { saved = JSON.parse(localStorage.getItem(POS_KEY) || 'null'); } catch (e) {}
    if (!saved || typeof saved.x !== 'number' || typeof saved.y !== 'number') { return; }
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
        + '<button class="kt-cd-btn kt-cd-mini" title="Minimise">–</button>'
        + '<button class="kt-cd-btn kt-cd-x" title="Close">×</button></div>'
      + '<div class="kt-cd-body"></div>';
    document.body.appendChild(dock);
    bodyEl = dock.querySelector('.kt-cd-body');
    titleEl = dock.querySelector('.kt-cd-ct-title');
    minTitleEl = dock.querySelector('.kt-cd-mtitle');

    dock.querySelector('.kt-cd-mini').addEventListener('click', function (e) { e.stopPropagation(); minimize(); });
    dock.querySelector('.kt-cd-x').addEventListener('click', function (e) { e.stopPropagation(); close(); });
    dock.querySelector('.kt-cd-x2').addEventListener('click', function (e) { e.stopPropagation(); close(); });
    dock.querySelector('.kt-cd-min').addEventListener('click', function () { restore(); });
    dock.querySelector('.kt-cd-restore').addEventListener('click', function (e) { e.stopPropagation(); restore(); });
    installDrag();
    return dock;
  }

  // The element the chat screen renders its thread into.
  function contentEl() { ensure(); return bodyEl; }

  function show(title, closeFn) {
    ensure();
    onClose = closeFn || null;
    var t = title || 'Chat';
    if (titleEl) titleEl.textContent = t;
    if (minTitleEl) minTitleEl.textContent = t;
    session.open = true;
    session.title = t || session.title || '';
    saveSession();
    minimized = false;
    dock.classList.remove('kt-cd-mini', 'kt-cd-flash');
    dock.style.display = '';
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
