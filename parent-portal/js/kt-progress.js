/* ═══════════════════════════════════════════════════════════════════
   KiddieTrac — global request progress (2026-07-09).
   When a form/save/action hits the API, the screen used to just pause with no
   feedback (people hit Submit twice). This shows a slim top progress bar for the
   whole duration of any mutating request (POST/PUT/PATCH/DELETE) — plus, if a
   button triggered it, a spinner + disabled state on that button so it can't be
   double-clicked. Purely a wrapper around window.fetch; no app changes needed.

   THE SAVING PROMPT (2026-11-11, Anthony: "when saving any records in the portal make a prompt with circle
   progress showing saving ... to make certain that user knows a record is being saved - desktop and mobile").
   The 3px bar was easy to miss. A save the person asked for now also shows a card at the top centre: a turning
   circle and "Saving…", then a green ✓ "Saved" (or a red "Not saved" when the server refused it - the screen's
   own message says why). It never blocks the page and never stops anyone working.
     · Only saves a person started: a write within GESTURE_MS of a tap, click, key or form submit. Background
       writes (presence, diagnostics, push registration, read receipts, draft autosave) stay silent - QUIET.
     · Not for chat sends (the bubble appears at once, chat-optimistic), file uploads (kt-upload-progress.js has
       its own popup with a percentage) or a card already showing KT.busy (check-in, clock-in).
     · Deleting… / Deleted and Sending… / Sent where that is what the write does.
     · A call site can opt out with init.ktQuiet = true (KT.Api: the last argument { quiet: true }).

   NOT FOR TIDYING UP (2026-11-25, Anthony: "when closing a chat message why does the save toast show up - full
   sweep"). The x that takes a conversation off the chat list archives it (POST /chat-archive): a write a click
   started, so the prompt said "Saving... Saved" - for closing a chat. Writes that tidy rather than save are quiet now:
   archiving or restoring a conversation, the website-chat actions (join, close, hand back, reply, nudge), marking an
   announcement read. Marked at the call site ({ quiet: true } / ktQuiet), with chat-archive and announcement state
   in QUIET for the screens that call fetch themselves. And when the screen shows its own toast for the write
   ("Archived", "Nudged", "Lead created"), the prompt leaves without a verdict instead of adding "Saved" on top.

   SAVED TOAST FOR CHANGES WITH NO SAVE BUTTON (2026-11-13, Anthony: "when changing notification sound types we need
   a saved pop up shown so we know the change is made - full sweep for other types of changes where there is no
   specific save button"). The pill's 1.1 s "Saved" was too quick to notice. A write started by a control that saves
   by itself - a field CHANGED (select, checkbox, radio, slider, input) or a switch/checkbox/toggle CLICKED - now also
   gets the house toast: ✅ Saved, or ⚠️ Not saved when the server refused it. Not when the screen showed its own toast
   for that save (any KT.toast since the write started), so nothing confirms twice. Settings kept on the device only
   (never a fetch) call KT.savedToast() themselves - screen-settings.js.
   ═══════════════════════════════════════════════════════════════════ */
(function () {
  'use strict';
  if (window.__ktProgress) return; window.__ktProgress = true;

  var active = 0, bar = null, creep = null, lastBtn = null, btnTimer = null;

  function ensure() {
    if (bar) return bar;
    var st = document.createElement('style');
    st.textContent =
      '#kt-progress{position:fixed;top:0;left:0;height:3px;width:0;z-index:99999;opacity:0;pointer-events:none;' +
      'background:linear-gradient(90deg,#0E7C90,#3BBBBE,#7C3AED);box-shadow:0 0 10px rgba(14,124,144,.6);' +
      'transition:width .2s ease,opacity .35s ease;}' +
      '.kt-busy{position:relative;pointer-events:none;opacity:.75;}' +
      '.kt-busy::after{content:"";position:absolute;top:50%;left:50%;width:15px;height:15px;margin:-8px 0 0 -8px;' +
      'border:2px solid rgba(255,255,255,.5);border-top-color:#fff;border-radius:50%;animation:kt-spin .6s linear infinite;}' +
      '@keyframes kt-spin{to{transform:rotate(360deg);}}' +
      // The saving prompt.
      '#kt-saving{position:fixed;left:0;right:0;top:calc(var(--kt-safe-top, env(safe-area-inset-top, 0px)) + 14px);margin:0 auto;' +
      'width:max-content;max-width:calc(100vw - 32px);z-index:2147482500;pointer-events:none;display:flex;align-items:center;gap:10px;' +
      'padding:9px 18px 9px 12px;border-radius:999px;background:#fff;color:#0F172A;border:1px solid #E2E8F0;' +
      'box-shadow:0 14px 34px -12px rgba(8,20,40,.45),0 2px 6px rgba(8,20,40,.08);font-weight:700;font-size:14px;line-height:1.2;font-family:inherit;' +
      'opacity:0;transform:translateY(-8px);transition:opacity .16s ease,transform .16s ease;}' +
      '#kt-saving.kt-sv-show{opacity:1;transform:none;}' +
      '#kt-saving .kt-sv-ring{flex:0 0 auto;width:22px;height:22px;border-radius:50%;box-sizing:border-box;position:relative;' +
      'border:3px solid rgba(31,96,128,.18);border-top-color:#1F6080;animation:kt-spin .7s linear infinite;}' +
      '#kt-saving.kt-sv-ok .kt-sv-ring{animation:none;border-color:#16A34A;background:#16A34A;}' +
      '#kt-saving.kt-sv-bad .kt-sv-ring{animation:none;border-color:#DC2626;background:#DC2626;}' +
      '#kt-saving.kt-sv-ok .kt-sv-ring:after,#kt-saving.kt-sv-bad .kt-sv-ring:after{position:absolute;inset:0;display:flex;' +
      'align-items:center;justify-content:center;color:#fff;font:900 12px/1 system-ui,sans-serif;}' +
      '#kt-saving.kt-sv-ok .kt-sv-ring:after{content:"\\2713";}' +
      '#kt-saving.kt-sv-bad .kt-sv-ring:after{content:"!";font-size:13px;}' +
      '#kt-saving .kt-sv-text{white-space:nowrap;overflow:hidden;text-overflow:ellipsis;color:#0F172A;}' +
      '#kt-saving.kt-sv-ok .kt-sv-text{color:#15803D;}#kt-saving.kt-sv-bad .kt-sv-text{color:#B91C1C;}' +
      '@media (max-width:700px){#kt-saving{padding:8px 16px 8px 10px;font-size:13.5px;}}' +
      '@media (prefers-reduced-motion:reduce){#kt-saving{transition:none;}#kt-saving .kt-sv-ring{animation-duration:2s;}}';
    document.head.appendChild(st);
    bar = document.createElement('div'); bar.id = 'kt-progress';
    document.body.appendChild(bar);
    return bar;
  }

  function start() {
    active++;
    var b = ensure();
    if (active === 1) {
      clearInterval(creep);
      b.style.transition = 'none'; b.style.width = '0'; b.style.opacity = '1';
      // force reflow so the reset applies before we animate
      void b.offsetWidth;
      b.style.transition = 'width .2s ease,opacity .35s ease';
      var w = 10; b.style.width = '10%';
      creep = setInterval(function () { w += (90 - w) * 0.14; b.style.width = w + '%'; }, 350);
      // spinner on the button that triggered this (best effort)
      lastBtn = (document.activeElement && /BUTTON|A/.test(document.activeElement.tagName)) ? document.activeElement : null;
      if (lastBtn && !lastBtn.classList.contains('kt-busy')) {
        lastBtn.classList.add('kt-busy');
        clearTimeout(btnTimer);
        btnTimer = setTimeout(function () { if (lastBtn) lastBtn.classList.remove('kt-busy'); }, 30000);
      }
    }
  }

  function done() {
    active = Math.max(0, active - 1);
    if (active === 0) {
      clearInterval(creep);
      var b = ensure();
      b.style.width = '100%';
      setTimeout(function () { b.style.opacity = '0'; setTimeout(function () { b.style.width = '0'; }, 350); }, 220);
      if (lastBtn) { clearTimeout(btnTimer); lastBtn.classList.remove('kt-busy'); lastBtn = null; }
    }
  }

  /* ── the saving prompt ─────────────────────────────────────────────── */
  var GESTURE_MS = 4000, MIN_SPIN = 450, OK_MS = 1100, BAD_MS = 2200, OWN_MS = 250;
  var QUIET = /unread-count|\/e\/o\/|typing|presence|heartbeat|\/diag\/|\/push\/|\/badges|\/pulse|marketing-site|\/notifications\/|mark-read|\/(seen|read|ping|draft|react|ack)(\?|$)|\/me\/(markers|device-permissions|privacy-notice)|\/auth\/(login|logout|passkey|mfa|reset|forgot|refresh|me\/ui-prefs|me\/auto-signoff)|\/parent\/messages(\?|$)|\/(chats|team-threads|threads|rooms)\/\d+\/(send|messages)(\?|$)|\/client-error|\/chat-archive|\/chats\/archive|\/announcements\/state(\?|$)/;
  /* READS SENT AS A WRITE (2026-11-12). Previews, checks, searches and "viewed" pings go out as POST because they carry
     a body, but nothing is saved: opening a sub-tab that shows an email or reminder preview (or a family's billing
     split) said "Saving... Saved". */
  var READS = /[\/-]preview(\?|$)|\/(options|check|search|view|lookup|validate|estimate)(\?|$)/;

  /* WHAT COUNTS AS ASKING FOR A SAVE (2026-11-12, Anthony: "the save popup randomly pops up when scrolling through
     subtabs"). Any finger on the screen used to count - pointerdown and any key - so swiping a tab row, scrolling a
     list or tapping a tab started a 6-second window in which whatever the app wrote in the background (or a sub-tab
     loading a preview) showed "Saving... Saved". Now only an action does: a click on a button, link-button, switch,
     checkbox or menu item, a form submit, or a field changed - and never on navigation (tab rows and their arrows,
     the sidebar, the phone's bottom bar, plain #links, pagers). A swipe never makes a click, so it never counts. */
  var NAV = '.kt-tabband, .kt-tbwrap, [role="tab"], [role="tablist"], [data-kt-tabbar], .kt-tabbtn, .kt-tbarrow, '
    + '#appSidebar, #kt-mobilenav, .nav-link, .kt-pg-host, .kt-pager';
  var ACTION = 'button, input, select, textarea, label, [role="button"], [role="switch"], [role="checkbox"], [role="menuitem"], [role="option"], a';
  function isAction(t) {
    var el = t && t.closest ? t.closest(ACTION) : null;
    if (!el || el.closest(NAV)) return false;
    // A plain link to another screen is navigation, not an action.
    if (el.tagName === 'A' && !el.getAttribute('role') && /^#/.test(el.getAttribute('href') || '#')) return false;
    return true;
  }
  var lastGesture = 0, lastAuto = 0;
  // A control that saves on its own: a switch, checkbox or toggle (the click) or any field that changed.
  var AUTO = 'input[type="checkbox"], input[type="radio"], [role="switch"], [role="checkbox"], [aria-pressed], .kt-sw-btn, .kt-toggle, [data-kt-autosave]';
  document.addEventListener('click', function (e) {
    try { if (isAction(e.target)) { lastGesture = Date.now(); if (e.target.closest(AUTO)) lastAuto = lastGesture; } } catch (x) {}
  }, true);
  document.addEventListener('change', function (e) { try { if (isAction(e.target)) lastGesture = lastAuto = Date.now(); } catch (x) {} }, true);
  document.addEventListener('submit', function () { lastGesture = Date.now(); }, true);

  var sv = null, svText = null, svJobs = 0, svBad = false, svStarted = 0, svTimer = null, svWord = null;
  var WORDS = { save: ['Saving…', 'Saved', 'Not saved'], del: ['Deleting…', 'Deleted', 'Not deleted'], send: ['Sending…', 'Sent', 'Not sent'] };

  function svEnsure() {
    ensure();
    if (sv && document.body.contains(sv)) return;
    sv = document.createElement('div');
    sv.id = 'kt-saving';
    sv.setAttribute('role', 'status');
    sv.setAttribute('aria-live', 'polite');
    sv.setAttribute('data-kt-keep-open', '1');
    sv.innerHTML = '<span class="kt-sv-ring" aria-hidden="true"></span><span class="kt-sv-text"></span>';
    svText = sv.lastChild;
    document.body.appendChild(sv);
  }

  function svWanted(method, url, init) {
    if (init && init.ktQuiet) return null;
    if (Date.now() - lastGesture > GESTURE_MS) return null;      // nobody asked for this one
    if (QUIET.test(url) || READS.test(url.split('?')[0])) return null;
    var body = init && init.body;
    try {
      if (body && typeof FormData !== 'undefined' && body instanceof FormData) {
        var it = body.values ? body.values() : null, v;
        while (it && !(v = it.next()).done) { if (typeof Blob !== 'undefined' && v.value instanceof Blob) return null; }   // kt-upload-progress
      }
      if (typeof body === 'string' && body.length >= 150 * 1024) return null;   // a file sent as text: kt-upload-progress
    } catch (e) {}
    if (document.querySelector('.kt-busy-ovl, #kt-upwait.kt-up-show')) return null;   // this card already says it is working
    if (method === 'DELETE' || /\/(delete|remove|destroy)(\?|$)/.test(url)) return 'del';
    if (/\/(send|send-now|resend|email|text-email)(\?|$)/.test(url)) return 'send';
    return 'save';
  }

  // Just under the bar pinned at the top (desktop topbar, phone header), so it never covers the header's controls.
  function svTop() {
    var top = 0;
    try {
      // A few heights: the desktop topbar floats 12px down from the edge.
      [4, 24, 44].some(function (y) {
        var el = document.elementFromPoint(window.innerWidth / 2, y);
        for (; el && el !== document.body; el = el.parentElement) {
          var pos = getComputedStyle(el).position;
          if (pos === 'fixed' || pos === 'sticky') { var b = el.getBoundingClientRect().bottom; if (b > 0 && b < 160) top = b; return true; }
        }
        return false;
      });
    } catch (e) {}
    sv.style.top = top ? (Math.round(top) + 10) + 'px' : '';
  }

  function svBegin(kind) {
    svEnsure();
    if (!svJobs) svTop();
    clearTimeout(svTimer);
    if (!svJobs) { svBad = false; svStarted = Date.now(); svWord = kind; }
    else if (svWord !== kind) svWord = 'save';                        // two kinds at once: plain "Saving…"
    svJobs++;
    sv.classList.remove('kt-sv-ok', 'kt-sv-bad');
    svText.textContent = WORDS[svWord][0];
    sv.style.display = 'flex';
    void sv.offsetWidth;
    sv.classList.add('kt-sv-show');
  }

  // outcome: 'ok', 'bad', or 'quiet' (the screen is asking something itself: hide without a verdict)
  function svEnd(outcome) {
    svJobs = Math.max(0, svJobs - 1);
    if (outcome === 'bad') svBad = true;
    if (outcome === 'quiet' && !svJobs && !svBad) { svHide(0); return; }
    if (svJobs) return;
    var wait = Math.max(OWN_MS, MIN_SPIN - (Date.now() - svStarted));
    svTimer = setTimeout(function () {
      if (svJobs) return;
      // The screen answered for this write itself (a toast since it started): no "Saved" on top of it.
      if (toastAt >= svStarted) { svHide(0); return; }
      sv.classList.add(svBad ? 'kt-sv-bad' : 'kt-sv-ok');
      svText.textContent = WORDS[svWord][svBad ? 2 : 1];
      svHide(svBad ? BAD_MS : OK_MS);
    }, wait);
  }

  function svHide(after) {
    clearTimeout(svTimer);
    svTimer = setTimeout(function () {
      if (svJobs || !sv) return;
      sv.classList.remove('kt-sv-show');
      setTimeout(function () { if (!svJobs && sv) sv.style.display = 'none'; }, 180);
    }, after);
  }

  // What a refused save means: a question the portal asks itself (not signed in, password change, signed out)
  // is not a failed save.
  function svOutcome(r) {
    if (r && r.ok) return Promise.resolve('ok');
    if (!r || r.status === 401) return Promise.resolve('quiet');
    return r.clone().json().then(function (j) {
      return (j && (j.message === 'not_signed_in' || j.password_change_required)) ? 'quiet' : 'bad';
    }, function () { return 'bad'; });
  }

  /* ── the Saved toast ── */
  var toastAt = 0, confirmAt = 0;
  // Note every toast the screen shows, so a save it already confirmed (or explained) is not confirmed again.
  function watchOne(host, key) {
    key = key || 'toast';
    if (!host || typeof host[key] !== 'function' || host[key].__ktSavedWatch) return;
    var t = host[key];
    var w = function () { toastAt = Date.now(); return t.apply(this, arguments); };
    w.__ktSavedWatch = true;
    for (var k in t) { try { if (!(k in w)) w[k] = t[k]; } catch (e) {} }
    host[key] = w;
  }
  function watchToasts() {
    var K = window.KT;
    watchOne(K);
    watchOne(K, 'logToast');   // the daily-log "logged" toast (kt-toasts.js) answers for its save too (2026-11-28)
    watchOne(window.Dom || (K && K.Dom));
  }
  function savedToast(body, bad) {
    var K = window.KT;
    if (!K || typeof K.toast !== 'function') return;
    if (bad) K.toast('⚠️', 'Not saved', body || 'Your change was not saved. Try again.', '#DC2626');
    else K.toast('✅', 'Saved', body || 'Your change was saved.', '#16A34A');
  }
  window.KT = window.KT || {};
  window.KT.savedToast = savedToast;
  function confirmSave(outcome, since) {
    if (outcome === 'quiet') return;
    setTimeout(function () {
      if (toastAt >= since) return;                                   // the screen said it itself
      if (Date.now() - confirmAt < 1500 && outcome === 'ok') return;   // one change, several writes: one toast
      confirmAt = Date.now();
      savedToast(null, outcome === 'bad');
    }, 700);
  }

  var orig = window.fetch;
  window.fetch = function (input, init) {
    var method = ((init && init.method) || (input && input.method) || 'GET').toUpperCase();
    var url = (typeof input === 'string') ? input : (input && input.url) || '';
    var track = /^(POST|PUT|PATCH|DELETE)$/.test(method) && /api\.kiddietrac\.com|\/api\/v1\//.test(url);
    // Don't flash the bar for silent background writes.
    if (/unread-count|\/e\/o\/|typing|presence|heartbeat|chat-presence/.test(url)) track = false;
    if (!track) return orig.apply(this, arguments);
    var kind = null;
    try { kind = svWanted(method, url, init); } catch (e) {}
    var auto = kind === 'save' && Date.now() - lastAuto <= GESTURE_MS, since = Date.now();
    if (kind) { try { watchToasts(); } catch (e) {} }
    start();
    if (kind) { try { svBegin(kind); } catch (e) { kind = null; } }
    var p;
    try { p = orig.apply(this, arguments); } catch (e) { done(); if (kind) svEnd('bad'); throw e; }
    return p.then(function (r) {
      done();
      if (kind) svOutcome(r).then(function (o) { svEnd(o); if (auto) confirmSave(o, since); }, function () { svEnd('bad'); });
      return r;
    }, function (e) { done(); if (kind) svEnd('bad'); if (auto) confirmSave('bad', since); throw e; });
  };
})();
