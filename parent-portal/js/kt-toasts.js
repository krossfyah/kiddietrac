/* ═══════════════════════════════════════════════════════════════════
   KiddieTrac — slide-in toasts (2026-07-08).
   • Welcome toast once per sign-in.
   • New-message toasts (polls the accurate unread-message count and slides in
     when it goes up — messages from any user).
   Exposes window.KT.toast(icon,title,body,color) for other code to reuse.
   ═══════════════════════════════════════════════════════════════════ */
(function () {
  'use strict';
  if (window.__ktToasts) return; window.__ktToasts = true;
  var API = (window.KT_CONFIG && window.KT_CONFIG.apiBase) || 'https://api.kiddietrac.com/api/v1';
  function tok() { try { return sessionStorage.getItem('kt_token') || localStorage.getItem('kt_token'); } catch (e) { return null; } }
  function user() { try { return JSON.parse(sessionStorage.getItem('kt_user') || '{}'); } catch (e) { return {}; } }

  // True on phones/APK. Kept as a live check so an orientation change re-pins.
  function isMobile() {
    try { return (window.matchMedia && window.matchMedia('(max-width:768px)').matches) || window.innerWidth <= 768; }
    catch (e) { return window.innerWidth <= 768; }
  }
  // Apply the correct position to the (persistent) container. Done in JS rather
  // than trusting a CSS @media rule — on the APK the media rule kept losing to
  // the element's inline cssText and the welcome toast stayed pinned top-right,
  // off-screen. Setting the inline style directly is unambiguous.
  function place(c) {
    if (isMobile()) {
      // BOTTOM-CENTER, well clear of the bottom nav bar. z-index above everything
      // and max-height so a toast can never spill past the top of the screen.
      // Center with left+right anchors (NO transform). The old left:50%+
      // translateX(-50%)+width:100vw flung the toast off-screen whenever a
      // transformed/offset ancestor became its containing block (mobile
      // slide-in screen transitions do exactly this) — verified in the DOM.
      // left/right anchoring is immune to that.
      c.style.cssText = 'position:fixed;top:auto;bottom:calc(var(--kt-safe-bottom, env(safe-area-inset-bottom,0px)) + 96px);left:12px;right:12px;transform:none;z-index:2147483600;display:flex;flex-direction:column;align-items:stretch;gap:10px;width:auto;max-width:none;max-height:66vh;pointer-events:none;';
    } else {
      var topPad = 'max(var(--kt-safe-top, env(safe-area-inset-top, 0px)), 34px)';
      c.style.cssText = 'position:fixed;top:calc(' + topPad + ' + 10px);right:12px;left:auto;bottom:auto;transform:none;z-index:2147483600;display:flex;flex-direction:column;align-items:flex-end;gap:10px;width:min(360px, calc(100vw - 24px));max-width:calc(100vw - 24px);pointer-events:none;';
    }
  }
  function container() {
    var c = document.getElementById('kt-toast-wrap');
    if (!c) {
      c = document.createElement('div'); c.id = 'kt-toast-wrap';
      // Append to <html>, NOT <body>: a transformed ancestor (mobile screen-in
      // animations put transforms on #appMain/#appShell) turns position:fixed into
      // "relative to that ancestor", which was clipping the toast off-screen.
      (document.documentElement || document.body).appendChild(c);
      var st = document.createElement('style');
      st.textContent = '@keyframes kt-toast-in{from{opacity:0;transform:translateY(-16px);}to{opacity:1;transform:none;}}@keyframes kt-toast-out{to{opacity:0;transform:translateY(-16px);}}';
      document.head.appendChild(st);
      // Re-pin on rotate/resize + mobile keyboard/nav changes (visualViewport).
      try { window.addEventListener('resize', function () { place(c); }); } catch (e) {}
      try { if (window.visualViewport) window.visualViewport.addEventListener('resize', function () { place(c); }); } catch (e) {}
    }
    place(c);
    return c;
  }
  function toast(icon, title, body, color) {
    // Back-compat shim: legacy callers use the 2-arg form toast(message, kind).
    // The canonical form is toast(icon, title, body, color). Detect the legacy
    // shape (no body/color, and the 2nd arg is a kind word or absent) and remap,
    // so a message no longer renders as a giant emoji with "success" as its title.
    if (body === undefined && color === undefined) {
      var _KINDS = { success: ['✅', '#16A34A'], saved: ['✅', '#16A34A'], error: ['⚠️', '#DC2626'],
        danger: ['⚠️', '#DC2626'], fail: ['⚠️', '#DC2626'], warning: ['⚠️', '#D97706'], info: ['ℹ️', '#1F6080'] };
      var _kind = (typeof title === 'string') ? title.toLowerCase() : (title === undefined ? '__none__' : null);
      if (_kind !== null && (_kind === '__none__' || _KINDS[_kind])) {
        var _meta = _KINDS[_kind] || ['💬', '#0E7C90'];
        title = icon; icon = _meta[0]; color = _meta[1]; body = undefined;   // 1st arg was the message
      }
    }
    var c = container();
    var t = document.createElement('div');
    t.style.cssText = 'pointer-events:auto;width:100%;max-width:100%;box-sizing:border-box;display:flex;gap:11px;align-items:flex-start;background:#fff;border:1px solid #E7EBF0;border-left:4px solid ' + (color || '#0E7C90') + ';border-radius:12px;padding:12px 14px;box-shadow:0 12px 30px -12px rgba(15,23,42,.4);animation:kt-toast-in .35s cubic-bezier(.2,.7,.2,1) both;font-family:system-ui,-apple-system,sans-serif;';
    t.innerHTML = '<span style="font-size:20px;line-height:1.15;flex-shrink:0;">' + icon + '</span>' +
      '<div style="min-width:0;"><div style="font-weight:700;font-size:13.5px;color:#0D1B2A;">' + title + '</div>' +
      (body ? '<div style="font-size:12.5px;color:#64748B;margin-top:2px;">' + body + '</div>' : '') + '</div>';
    var x = document.createElement('button'); x.textContent = '×'; x.setAttribute('aria-label', 'Dismiss');
    x.style.cssText = 'margin-left:auto;border:none;background:transparent;color:#94A3B8;font-size:17px;cursor:pointer;line-height:1;flex-shrink:0;';
    /* Same toast already up? Refresh it rather than stack a copy. Repeat taps on
       the APK were leaving a column of identical toasts covering the screen. */
    try {
      var sig = [icon, title, body].join('');
      var dup = c.querySelector('[data-kt-sig="' + CSS.escape(sig) + '"]');
      if (dup && dup.ktReset) { dup.ktReset(); t.remove(); return dup; }
      t.setAttribute('data-kt-sig', sig);
    } catch (e) { /* CSS.escape is missing on very old webviews — just stack */ }

    var timer = setTimeout(function () { dismiss(); }, 6000);
    /* Lets a repeat tap restart this toast's countdown from the outside. */
    t.ktReset = function () {
      clearTimeout(timer);
      timer = setTimeout(function () { dismiss(); }, 6000);
      t.style.animation = 'none';
      void t.offsetWidth;                       // force a reflow so it replays
      t.style.animation = 'kt-toast-in .18s ease';
    };
    function dismiss() { clearTimeout(timer); t.style.animation = 'kt-toast-out .3s ease forwards'; setTimeout(function () { t.remove(); }, 320); }
    x.onclick = dismiss; t.appendChild(x);
    c.appendChild(t);
    /* Hard ceiling. Different messages should stack, but not without limit — on a
       phone a handful already fills the screen. Oldest goes first. */
    try {
      while (c.children.length > 4) { c.removeChild(c.firstElementChild); }
    } catch (e) {}
    return t;
  }
  window.KT = window.KT || {}; window.KT.toast = toast;

  function welcome() {
    try { if (sessionStorage.getItem('kt_welcomed') === '1') return; sessionStorage.setItem('kt_welcomed', '1'); } catch (e) {}
    var u = user(); var name = (u.name || '').split(' ')[0] || 'there';
    var h = new Date().getHours();
    // Same scale as kt-topbar/kt-parent-chrome/kt-illustrations, incl. pre-dawn.
    var g = h < 5 ? 'Good night' : h < 12 ? 'Good morning' : h < 17 ? 'Good afternoon' : h < 22 ? 'Good evening' : 'Good night';
    toast('👋', g + ', ' + name + '!', 'Welcome back to KiddieTrac.', '#0E7C90');
  }

  var lastCount = null;

  /* Open the thread the newest unread message belongs to.
     Deliberately conservative:
       • desktop only — the dock is a desktop window, and hijacking a phone screen
         mid-task would be hostile
       • never while a thread is already open, so it cannot yank you out of a
         conversation you are in the middle of
       • never on the Messenger screen itself, which is already showing the list */
  function popOpenNewest() {
    if (popOpenNewest.off) { return; }
    try {
      if (!(window.matchMedia && window.matchMedia('(min-width: 769px)').matches)) { return; }
      var KTx = window.KT;
      if (!KTx || !KTx.ChatDock || !KTx.ChatDock.isActive) { return; }
      // Already showing a thread — never yank somebody out of a live conversation.
      if (KTx.ChatDock.isActive()) { return; }
      if ((location.hash || '').indexOf('chat') !== -1) { return; }

      var t = tok(); if (!t) { return; }

      // A parent has no provider surface — asking for it returns 403 and the window
      // pops open onto an error. Same choice the rest of the app makes.
      var role = (user() || {}).role || '';
      var path = (role === 'guardian') ? '/parent/chats' : '/provider/chats';

      fetch(API + path + '?per_page=1&page=1', {
        headers: { 'Authorization': 'Bearer ' + t, 'X-Active-Agency-Id': agencyId() || '' },
      })
        .then(function (r) {
          if (!r.ok) {
            // Do not keep retrying a call that is not allowed — once is a glitch,
            // every twelve seconds is a fault the user has to watch.
            if (r.status === 401 || r.status === 403) { popOpenNewest.off = true; }
            return null;
          }
          return r.json();
        })
        .then(function (d) {
          var c = d && d.conversations && d.conversations[0];
          if (!c || !c.unread_count) { return; }
          // screen-chat exposes openThread once Messenger has been mounted at least
          // once this session. Before that there is nothing to open into, so fall back
          // to navigating — which still puts the conversation in front of them.
          /* openThread(cid, container) — the container was missing here, unlike
             every other caller. Passing the Messenger mount keeps it consistent, and a
             conversation that will not open is skipped rather than shown as a raw API
             error the moment somebody logs in. */
          if (KTx.Chat && KTx.Chat.openThread) {
            try {
              var mount = document.querySelector('#appMain') || document.body;
              var p = KTx.Chat.openThread(c.id, mount);
              if (p && typeof p.catch === 'function') { p.catch(function () {}); }
            } catch (e) { /* a convenience must never break the login */ }
          }
          else { location.hash = '#chat'; }
        })
        .catch(function () {});
    } catch (e) { /* never let a convenience break the poller */ }
  }

  function agencyId() {
    try { return sessionStorage.getItem('kt_active_agency_id') || ''; } catch (e) { return ''; }
  }

  function pollMessages() {
    var t = tok(); if (!t) return;
    fetch(API + '/chats/unread-count', { headers: { 'Authorization': 'Bearer ' + t } })
      .then(function (r) { return r.ok ? r.json() : null; })
      .then(function (d) {
        if (!d) return;
        var n = d.unread_count != null ? d.unread_count : (d.count != null ? d.count : (d.unread != null ? d.unread : 0));
        n = parseInt(n, 10) || 0;
        if (lastCount !== null && n > lastCount) {
          var diff = n - lastCount;
          toast('💬', diff === 1 ? 'New message' : diff + ' new messages', 'Open Messages to read.', '#1F6080');
          // Pop the conversation open rather than only announcing it. The dock already
          // survives SPA navigation, so this puts the sender's thread in front of you
          // the way a desktop chat client would.
          try { popOpenNewest(); } catch (e) {}
        }
        lastCount = n;
      }).catch(function () {});
  }

  // Don't pop the welcome toast until the launch splash is GONE. Firing it during
  // boot showed it over the splash AND mid-transition (transformed ancestors),
  // which pushed it off-screen. Wait for #kt-splash to be removed, then a short
  // settle delay, so the toast only appears once the user is actually in the app.
  function whenAppReady(cb) {
    var tries = 0;
    (function check() {
      var splash = document.getElementById('kt-splash');
      var gone = !splash || splash.classList.contains('kt-hide');
      if (gone || tries > 75) { setTimeout(cb, 350); return; }   // 75*200ms = 15s hard cap
      tries++; setTimeout(check, 200);
    })();
  }
  function start() {
    if (!tok()) return;
    whenAppReady(welcome);              // welcome toast waits for the app to be visible
    pollMessages();                     // (no UI on first poll — safe to start now)
    setInterval(pollMessages, 12000);   // near-real-time message alerts
    // Instant catch-up when the tab/app is brought back to the foreground.
    document.addEventListener('visibilitychange', function () { if (document.visibilityState === 'visible') pollMessages(); });
    window.addEventListener('focus', function () { pollMessages(); });
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start); else start();
})();
