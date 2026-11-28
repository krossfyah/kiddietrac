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
  /* UNDER A FIRST-RUN QUESTION (2026-11-03, Anthony's iPhone recording: "Good morning, Saurav!" sat bright on top of
     the biometric offer's dimmed backdrop). The stack is above everything on purpose (z 2147483600), but while one of
     the first-run questions holds the screen (html.kt-prompt-up - KT.promptTurn in kt-biometric.js / kt-native-push.js
     / kt-whatsnew.js) it sits under that popup's backdrop, and comes back up when the question closes. */
  try {
    var _pst = document.createElement('style');
    _pst.textContent = 'html.kt-prompt-up #kt-toast-wrap{z-index:11000!important}';
    (document.head || document.documentElement).appendChild(_pst);
  } catch (e) {}
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
  /* NEVER OVER A TEXT BOX (2026-11-02, Anthony's iPhone recording: "Pick a child to log for..." sat on top of the
     chat's "Type a message" box). On a phone the stack sits just above the tab bar - exactly where a chat composer,
     a comment box or the field being typed in is. When a visible text field is in the lower part of what is on
     screen, or one has focus (the keyboard is up), the stack sits just ABOVE that field instead (over content,
     never over the field, the header or the back arrow); with no room above it, just below it. */
  var FIELD_SEL = 'textarea, input:not([type]), input[type=text], input[type=search], input[type=email], input[type=tel], input[type=number], input[type=url], input[type=password], [contenteditable="true"]';
  function shown(el) {
    var cs = getComputedStyle(el);
    return cs.visibility !== 'hidden' && cs.display !== 'none' && +cs.opacity !== 0;
  }
  function fieldRect() {
    try {
      var vv = window.visualViewport;
      var vt = vv ? vv.offsetTop : 0, vb = vv ? vv.offsetTop + vv.height : window.innerHeight;
      var ae = document.activeElement;
      if (ae && ae !== document.body && ae.matches && ae.matches(FIELD_SEL)) {
        var ra = ae.getBoundingClientRect();
        if (ra.width && ra.bottom > vt && ra.top < vb) return { r: ra, vt: vt, vb: vb };
      }
      var best = null, els = document.querySelectorAll(FIELD_SEL);
      for (var i = 0; i < els.length; i++) {
        var el = els[i];
        if (el.closest && el.closest('#kt-toast-wrap')) continue;
        var r = el.getBoundingClientRect();
        if (!r.width || !r.height || r.top >= vb || r.bottom <= vt) continue;
        if (r.bottom > vb - 280 && shown(el) && (!best || r.top < best.top)) best = r;
      }
      return best ? { r: best, vt: vt, vb: vb } : null;
    } catch (e) { return null; }
  }
  var BASE = 'position:fixed;left:12px;right:12px;transform:none;z-index:2147483600;display:flex;flex-direction:column;align-items:stretch;gap:10px;width:auto;max-width:none;pointer-events:none;';
  function place(c) {
    var f = isMobile() ? fieldRect() : null;
    if (f) {
      var ih = window.innerHeight;
      if (f.r.top - f.vt >= 150) {          // room above the field: the stack's bottom edge 10px above it
        c.style.cssText = BASE + 'top:auto;bottom:' + Math.round(ih - f.r.top + 10) + 'px;max-height:' + Math.round(f.r.top - f.vt - 20) + 'px;justify-content:flex-end;overflow:hidden;';
      } else {                              // a field near the top: just below it
        c.style.cssText = BASE + 'bottom:auto;top:' + Math.round(f.r.bottom + 10) + 'px;max-height:' + Math.max(80, Math.round(f.vb - f.r.bottom - 20)) + 'px;overflow:hidden;';
      }
      c.setAttribute('data-kt-at', 'field');
      return;
    }
    c.removeAttribute('data-kt-at');
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
      try { if (window.visualViewport) window.visualViewport.addEventListener('scroll', function () { if (c.children.length) place(c); }); } catch (e) {}
      document.addEventListener('focusin', function () { if (c.children.length) place(c); }, true);
      document.addEventListener('focusout', function () { if (c.children.length) setTimeout(function () { place(c); }, 60); }, true);
      /* A NEW SCREEN CLEARS THE LAST ONE'S MESSAGES (same recording: the "Log a moment" hints followed him onto
         Message Centre and into a chat). On a route change every toast that is about the screen being left goes:
         anything up for more than 0.8 s that is not a toast pointing somewhere (a new message / alert with a
         destination stays). A "Saved" shown just before a screen moves on is younger than that and stays. */
      window.addEventListener('hashchange', function () {
        var now = Date.now();
        Array.prototype.slice.call(c.children).forEach(function (t) {
          if (t.__ktTap || !t.__ktAt || now - t.__ktAt < 800) return;
          if (t.ktDismiss) t.ktDismiss(); else t.remove();
        });
        setTimeout(function () { if (c.children.length) place(c); }, 400);
      });
      // A screen can draw its text box after the toast went up: re-check while any toast is showing.
      setInterval(function () { if (c.children.length) place(c); }, 700);
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
    t.style.cssText = 'pointer-events:auto;width:100%;max-width:100%;box-sizing:border-box;display:flex;gap:11px;align-items:flex-start;background:#fff;border:1px solid #E7EBF0;--kt-edge:' + (color || '#0E7C90') + ';border-radius:12px;padding:12px 14px;box-shadow:0 12px 30px -12px rgba(15,23,42,.4);animation:kt-toast-in .35s cubic-bezier(.2,.7,.2,1) both;font-family:system-ui,-apple-system,sans-serif;';
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
      if (dup && dup.ktReset) { dup.ktReset(); dup.__ktAt = Date.now(); t.remove(); return dup; }
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
    t.ktDismiss = dismiss; t.__ktAt = Date.now();
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

  /* ═══ THE "LOGGED" TOAST (2026-11-28, Anthony: "when logging daily moments shows a toast saying saved - can you not
     make that better with a better toast"). Every daily-log save (Log a moment, the room roster's quick logs, a
     room-wide log, the sleep room) said only "Saved" or "Meal logged ✓". KT.logToast(entry) says what was logged,
     for whom and when, whether the family sees it, and offers Undo for a few seconds where the entry can be taken back
     (POST /care/logs/undo: the person's own entries from the last few minutes - CareController::undoLogs).
       entry = { type, label?, icon?, title?, names: [first names], count?: children without a name, at?: ISO,
                 shared: true (families see it) | false (staff only) | null (say nothing), note?,
                 undo?: { care: [daily_care_logs ids], events: [daily_events ids] }, onUndone?: fn(res), view?: '#hash' }
     ONE toast: a second log while it is up joins it (the count goes up) instead of stacking another card.
     role=status + aria-live=polite; swipe it sideways to dismiss on a phone; hover or touch pauses it.
     Generic settings "✅ Saved" toasts are not this - they stay as they are (kt-progress.js). ═══ */
  (function () {
    var EMO = { meal: '🍽️', snack: '🍎', bottle: '🍼', nap: '😴', nap_start: '😴', nap_end: '🌅', diaper: '🧷',
      bathroom: '🚽', mood: '🙂', activity: '🎨', note: '📝', outdoor: '🌳', sunscreen: '🧴', medicine: '💊',
      medication: '💊', clothing: '👕', late: '⏰', walk: '🚶' };
    var LBL = { meal: 'Meal', snack: 'Snack', bottle: 'Bottle', nap: 'Nap', nap_start: 'Nap started', nap_end: 'Woke up',
      diaper: 'Diaper', bathroom: 'Bathroom', mood: 'Mood', activity: 'Activity', note: 'Note', outdoor: 'Outdoor play',
      sunscreen: 'Sunscreen', medicine: 'Medicine', medication: 'Medication', clothing: 'Change of clothes', late: 'Late' };
    var TINT = { meal: '#16A34A', snack: '#F59E0B', bottle: '#65A30D', nap: '#7C3AED', nap_start: '#7C3AED', nap_end: '#7C3AED',
      diaper: '#EA580C', bathroom: '#1F6080', mood: '#7C3AED', outdoor: '#2E7D32', sunscreen: '#D97706', medicine: '#DB2777',
      clothing: '#0284C7', late: '#DC2626' };
    var LIFE_UNDO = 6000, LIFE = 4000;
    function T(s) { try { return (window.KT && KT.i18n && KT.i18n.t) ? KT.i18n.t(s, 'log-toast') : s; } catch (e) { return s; } }
    function fill(s, o) { return String(s).replace(/\{(\w+)\}/g, function (m, k) { return o[k] == null ? m : o[k]; }); }
    function esc(s) { return String(s == null ? '' : s).replace(/[&<>"]/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]; }); }
    function first(n) { n = String(n || '').trim(); return n.split(/\s+/)[0] || n; }
    function who(names, extra) {
      var n = names.length + extra;
      if (!names.length) return n === 1 ? T('1 child') : fill(T('{n} children'), { n: n });
      if (n === 1) return names[0];
      if (n === 2 && names.length === 2) return fill(T('{a} and {b}'), { a: names[0], b: names[1] });
      return n - 1 === 1 ? fill(T('{name} and 1 other'), { name: names[0] }) : fill(T('{name} and {n} others'), { name: names[0], n: n - 1 });
    }
    function clock(at) {
      try { if (window.KT && KT.fmtTime) return KT.fmtTime(at || new Date().toISOString()); } catch (e) {}
      var d = at ? new Date(at) : new Date();
      return d.toLocaleTimeString([], { hour: 'numeric', minute: '2-digit' });
    }
    function labelOf(e) { return e.label || LBL[e.type] || 'Entry'; }

    var st = document.createElement('style');
    st.textContent =
      '.kt-lt{pointer-events:auto;position:relative;overflow:hidden;width:100%;box-sizing:border-box;display:flex;gap:11px;align-items:flex-start;background:#fff;color:#0D1B2A;' +
      'border:1px solid #E2E8F0;border-left:4px solid var(--lt-c,#0E7C90);border-radius:12px;padding:11px 10px 12px 12px;box-shadow:0 14px 32px -14px rgba(15,23,42,.45),0 2px 6px rgba(15,23,42,.06);' +
      'font-family:system-ui,-apple-system,"Segoe UI",sans-serif;animation:kt-toast-in .3s cubic-bezier(.2,.7,.2,1) both;touch-action:pan-y;user-select:none;-webkit-user-select:none;}' +
      '.kt-lt-ic{flex:0 0 34px;height:34px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:18px;line-height:1;background:#F1F5F9;background:color-mix(in srgb,var(--lt-c,#0E7C90) 13%,#fff);}' +
      '.kt-lt-b{min-width:0;flex:1;}' +
      '.kt-lt-t{font-weight:700;font-size:13.5px;line-height:1.3;color:#0D1B2A;overflow-wrap:anywhere;}' +
      '.kt-lt-l{font-size:12.5px;color:#475569;margin-top:2px;line-height:1.35;overflow-wrap:anywhere;}' +
      '.kt-lt-m{display:flex;flex-wrap:wrap;align-items:center;gap:4px 8px;margin-top:5px;font-size:12px;color:#64748B;}' +
      '.kt-lt-chip{display:inline-flex;align-items:center;gap:4px;padding:1px 8px;border-radius:999px;font-weight:600;font-size:11.5px;line-height:18px;}' +
      '.kt-lt-chip.fam{background:#ECFDF5;color:#047857;}.kt-lt-chip.staff{background:#F1F5F9;color:#334155;}' +
      '.kt-lt-a{display:flex;align-items:center;gap:4px;flex-shrink:0;margin-left:auto;align-self:center;}' +
      '.kt-lt-u{height:30px;padding:0 12px;border-radius:8px;border:1px solid #CBD5E1;background:#fff;color:#1F6080;font-weight:700;font-size:12.5px;cursor:pointer;white-space:nowrap;font-family:inherit;}' +
      '.kt-lt-u:hover{background:#F0F7FA;border-color:#1F6080;}.kt-lt-u:focus-visible,.kt-lt-x:focus-visible{outline:2px solid #1F6080;outline-offset:1px;}' +
      '.kt-lt-u[disabled]{opacity:.6;cursor:default;}' +
      '.kt-lt-x{width:28px;height:28px;border:none;background:transparent;color:#94A3B8;font-size:17px;line-height:1;cursor:pointer;border-radius:7px;flex-shrink:0;}' +
      '.kt-lt-x:hover{background:#F1F5F9;color:#475569;}' +
      '.kt-lt-bar{position:absolute;left:0;bottom:0;height:3px;background:var(--lt-c,#0E7C90);opacity:.55;transform-origin:left;}' +
      '@media (prefers-reduced-motion:reduce){.kt-lt{animation:none;}.kt-lt-bar{display:none;}}';
    (document.head || document.documentElement).appendChild(st);

    var cur = null;   // the toast that is up: { el, entries, state, timer, left, until }

    function summary(es) {
      var types = [], names = [], extra = 0;
      es.forEach(function (e) {
        if (types.indexOf(e.type + '|' + labelOf(e)) === -1) types.push(e.type + '|' + labelOf(e));
        (e.names || []).forEach(function (n) { n = first(n); if (n && names.indexOf(n) === -1) names.push(n); });
        extra += Math.max(0, (e.count || 0) - (e.names || []).length);
      });
      return { types: types, names: names, extra: extra, kids: names.length + extra };
    }

    function render() {
      var c = cur, es = c.entries, last = es[es.length - 1], s = summary(es), el = c.el;
      var icon, title, line = '', color;
      if (c.state === 'undone') {
        icon = '↩️'; color = '#475569'; title = T('Undone');
        line = esc(c.msg || '');
      } else if (c.state === 'failed') {
        icon = '⚠️'; color = '#DC2626'; title = T('Could not undo');
        line = esc(c.msg || '');
      } else if (s.types.length === 1) {
        icon = last.icon || EMO[last.type] || '📝'; color = TINT[last.type] || '#0E7C90';
        title = (es.length === 1 && last.title) ? T(last.title)
          : fill(T('{what} logged for {who}'), { what: T(labelOf(last)), who: who(s.names, s.extra) });
        if (es.length > 1) line = fill(T('{n} entries'), { n: es.length });
        else if (last.note) line = T(last.note);
        title = esc(title); line = esc(line);
      } else {
        icon = '📝'; color = '#0E7C90';
        title = esc(fill(T('{n} entries logged'), { n: es.length }));
        line = es.slice(-3).reverse().map(function (e) {
          return esc((e.icon || EMO[e.type] || '') + ' ' + T(labelOf(e)) + ' · ' + who((e.names || []).map(first), Math.max(0, (e.count || 0) - (e.names || []).length)));
        }).join('<br>') + (es.length > 3 ? '<br>…' : '');
      }
      el.style.setProperty('--lt-c', color);
      var meta = '';
      if (c.state === 'live') {
        meta = '<span>' + esc(clock(last.at)) + '</span>';
        var sh = es.some(function (e) { return e.shared === true; }), staff = es.every(function (e) { return e.shared === false; });
        if (sh) meta += '<span class="kt-lt-chip fam">👪 ' + esc(T(s.kids > 1 ? 'Shared with families' : 'Shared with family')) + '</span>';
        else if (staff) meta += '<span class="kt-lt-chip staff">🔒 ' + esc(T('Staff only')) + '</span>';
      }
      var canUndo = c.state === 'live' && es.every(function (e) { return e.undo && ((e.undo.care || []).length || (e.undo.events || []).length); });
      var view = c.state === 'live' && !canUndo ? (last.view || '') : '';
      el.innerHTML = '<span class="kt-lt-ic" aria-hidden="true">' + icon + '</span>' +
        '<div class="kt-lt-b"><div class="kt-lt-t">' + title + '</div>' + (line ? '<div class="kt-lt-l">' + line + '</div>' : '') +
        (meta ? '<div class="kt-lt-m">' + meta + '</div>' : '') + '</div>' +
        '<div class="kt-lt-a">' +
        (canUndo ? '<button type="button" class="kt-lt-u" data-lt="undo">' + esc(T(es.length > 1 ? 'Undo all' : 'Undo')) + '</button>' : '') +
        (view ? '<button type="button" class="kt-lt-u" data-lt="view">' + esc(T('View')) + '</button>' : '') +
        '<button type="button" class="kt-lt-x" data-lt="x" aria-label="' + esc(T('Dismiss')) + '">×</button></div>' +
        (canUndo ? '<span class="kt-lt-bar" aria-hidden="true"></span>' : '');
      c.canUndo = canUndo;
      var u = el.querySelector('[data-lt="undo"]'); if (u) u.onclick = function (ev) { ev.stopPropagation(); undo(); };
      var v = el.querySelector('[data-lt="view"]'); if (v) v.onclick = function (ev) { ev.stopPropagation(); dismiss(); location.hash = view; };
      el.querySelector('[data-lt="x"]').onclick = function (ev) { ev.stopPropagation(); dismiss(); };
    }

    function arm(ms) {
      var c = cur; if (!c) return;
      clearTimeout(c.timer);
      c.left = ms; c.until = Date.now() + ms;
      c.timer = setTimeout(dismiss, ms);
      var bar = c.el.querySelector('.kt-lt-bar');
      if (bar) {
        bar.style.transition = 'none'; bar.style.width = '100%';
        void bar.offsetWidth;
        bar.style.transition = 'width ' + ms + 'ms linear'; bar.style.width = '0%';
      }
    }
    function pause() {
      var c = cur; if (!c || c.paused) return;
      c.paused = true; clearTimeout(c.timer);
      c.left = Math.max(1500, c.until - Date.now());
      var bar = c.el.querySelector('.kt-lt-bar');
      if (bar) { var w = getComputedStyle(bar).width; bar.style.transition = 'none'; bar.style.width = w; }
    }
    function resume() {
      var c = cur; if (!c || !c.paused) return;
      c.paused = false; arm(c.left);
    }
    function dismiss(fly) {
      var c = cur; if (!c) return;
      cur = null; clearTimeout(c.timer);
      var el = c.el;
      el.style.transition = 'transform .22s ease,opacity .22s ease';
      if (fly) { el.style.transform = 'translateX(' + (fly > 0 ? 110 : -110) + '%)'; el.style.opacity = '0'; }
      else el.style.animation = 'kt-toast-out .28s ease forwards';
      setTimeout(function () { el.remove(); }, 300);
    }

    function undo() {
      var c = cur; if (!c || c.state !== 'live') return;
      var care = [], events = [];
      c.entries.forEach(function (e) { care = care.concat(e.undo.care || []); events = events.concat(e.undo.events || []); });
      clearTimeout(c.timer); c.state = 'undoing';
      var b = c.el.querySelector('[data-lt="undo"]'); if (b) { b.disabled = true; b.textContent = T('Undoing…'); }
      var bar = c.el.querySelector('.kt-lt-bar'); if (bar) bar.remove();
      var shared = c.entries.some(function (e) { return e.shared === true; });
      var Api = window.KT && KT.Api;
      var p = Api ? Api.post('/care/logs/undo', { care_ids: care, event_ids: events }, { quiet: true })
        : Promise.reject(new Error('Not available'));
      p.then(function (r) {
        if (cur !== c) return;
        var refused = (r && r.refused) || 0;
        c.state = 'undone';
        c.msg = refused ? fill(T('{n} could not be undone: it is more than 10 minutes old or a sleep check is recorded on it.'), { n: refused })
          : (T('Taken off the log.') + (shared ? ' ' + T('A notification may already have reached the family.') : ''));
        render(); arm(refused ? LIFE_UNDO : LIFE);
        c.entries.forEach(function (e) { try { if (e.onUndone) e.onUndone(r); } catch (x) {} });
      }, function (er) {
        if (cur !== c) return;
        c.state = 'failed';
        c.msg = (er && er.message && er.message !== 'Not available') ? er.message : T('It is still on the log. Remove it from the child’s log instead.');
        render(); arm(LIFE_UNDO);
        c.entries.forEach(function (e) { try { if (e.onUndone) e.onUndone(null); } catch (x) {} });
      });
    }

    function bindGestures(el) {
      el.addEventListener('mouseenter', pause);
      el.addEventListener('mouseleave', resume);
      el.addEventListener('focusin', pause);
      el.addEventListener('focusout', function () { setTimeout(function () { if (cur && !cur.el.contains(document.activeElement)) resume(); }, 0); });
      var x0 = null, y0 = 0, dx = 0, id = null;
      el.addEventListener('pointerdown', function (e) {
        if (e.target.closest('button') || (e.pointerType === 'mouse' && e.button !== 0)) return;
        x0 = e.clientX; y0 = e.clientY; dx = 0; id = e.pointerId; pause();
        el.style.transition = 'none';
      });
      el.addEventListener('pointermove', function (e) {
        if (x0 === null || e.pointerId !== id) return;
        dx = e.clientX - x0;
        if (Math.abs(e.clientY - y0) > 30 && Math.abs(dx) < 20) { x0 = null; el.style.transform = ''; return; }
        el.style.transform = 'translateX(' + dx + 'px)';
        el.style.opacity = String(Math.max(0.25, 1 - Math.abs(dx) / 260));
      });
      function end(e) {
        if (x0 === null || (e && e.pointerId !== id)) return;
        x0 = null;
        if (Math.abs(dx) > 70) { dismiss(dx); return; }
        el.style.transition = 'transform .18s ease,opacity .18s ease'; el.style.transform = ''; el.style.opacity = '';
        if (e && e.pointerType !== 'mouse') resume();
      }
      el.addEventListener('pointerup', end);
      el.addEventListener('pointercancel', end);
    }

    function logToast(entry) {
      entry = entry || {};
      if (!entry.at) entry.at = new Date().toISOString();
      if (cur && cur.state === 'live' && document.documentElement.contains(cur.el)) {
        cur.entries.push(entry);
        render();
        cur.el.style.animation = 'none'; void cur.el.offsetWidth; cur.el.style.animation = 'kt-toast-in .18s ease';
        if (!cur.paused) arm(cur.canUndo ? LIFE_UNDO : LIFE); else cur.left = cur.canUndo ? LIFE_UNDO : LIFE;
        return cur.el;
      }
      if (cur) dismiss();
      var el = document.createElement('div');
      el.className = 'kt-lt';
      el.setAttribute('role', 'status');
      el.setAttribute('aria-live', 'polite');
      el.setAttribute('aria-atomic', 'true');
      el.setAttribute('data-i18n-ctx', 'log-toast');
      el.__ktAt = Date.now();
      el.ktDismiss = function () { if (cur && cur.el === el) dismiss(); else el.remove(); };
      cur = { el: el, entries: [entry], state: 'live', timer: null, paused: false };
      render();
      bindGestures(el);
      var host = container();
      host.appendChild(el);
      try { while (host.children.length > 4) host.removeChild(host.firstElementChild); } catch (e) {}
      arm(cur.canUndo ? LIFE_UNDO : LIFE);
      return el;
    }
    window.KT.logToast = logToast;
  })();

  /* NOT OVER A SCREEN THAT NEEDS AN ANSWER (2026-11-04, App Store screenshots: on a first sign-in the "Good afternoon,
     Taylor! Welcome" toast sat on top of the Terms screen's "Agree & continue" button). While the Terms, a password
     change, two-step sign-in or a lock is up, the welcome waits; it shows once that screen is done. */
  var GATES = '#kt-agree, #kt-pw-gate, #kt-mfa-gate, #kt-pin-lock, #kt-bio-lock, #kt-bio-ov, #kt-clockgate';
  function gateUp() {
    var els = document.querySelectorAll(GATES);
    for (var i = 0; i < els.length; i++) { if (els[i].offsetWidth > 0 || els[i].offsetHeight > 0) return true; }
    return false;
  }
  function welcome(waited) {
    waited = waited || 0;
    if (gateUp()) { if (waited < 600) setTimeout(function () { welcome(Math.max(waited, 0) + 1); }, 1000); return; }
    if (waited > 0) { setTimeout(function () { welcome(-1); }, 600); return; }   // a breath after the gate leaves
    try { if (sessionStorage.getItem('kt_welcomed') === '1') return; sessionStorage.setItem('kt_welcomed', '1'); } catch (e) {}
    var u = user(); var name = (u.name || '').split(' ')[0] || 'there';
    var h = ((window.KT && KT.agencyHM) ? KT.agencyHM() : { h: new Date().getHours() }).h;   // the centre's hour
    // Same scale as kt-topbar/kt-parent-chrome/kt-illustrations, incl. pre-dawn.
    var g = h < 5 ? 'Good night' : h < 12 ? 'Good morning' : h < 17 ? 'Good afternoon' : h < 22 ? 'Good evening' : 'Good night';
    var seenKey = 'kt_seen_u' + (u.id || ''), first = false;
    try { first = !localStorage.getItem(seenKey); localStorage.setItem(seenKey, '1'); } catch (e) {}
    toast('👋', g + ', ' + name + '!', first ? 'Welcome to KiddieTrac.' : 'Welcome back to KiddieTrac.', '#0E7C90');
  }

  var lastCount = null, lastScope = null;
  /* The count belongs to the agency and view it was asked for (2026-11-02): opening another agency in place (Platform
     → All agencies → Open) is a new count, not new messages, so it starts a new baseline instead of a toast. */
  function countScope() {
    try { return (sessionStorage.getItem('kt_active_agency_id') || '') + '|' + (sessionStorage.getItem('kt_view_as') || ''); } catch (e) { return ''; }
  }

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
    // One answer for every badge (kt-badges.js): no request of its own when it is there.
    /* ALL MESSAGES, NOT FAMILY CHATS ALONE (2026-11-01, Anthony: "why doesnt the browser when logged in show that I have
     new notifications or chat messages"). This read b.chats - family conversations only - so a staff team message or a
     website chat moved KT.badges and nothing on screen. KT.badges.msgs() is the one sum of every kind. */
    (window.KT && KT.badges ? KT.badges.get().then(function (b) { return b ? { unread: (KT.badges.msgs ? KT.badges.msgs(b) : b.chats) } : null; })
      : fetch(API + '/chats/unread-count', { headers: { 'Authorization': 'Bearer ' + t } }).then(function (r) { return r.ok ? r.json() : null; }))
      .then(function (d) {
        if (!d) return;
        var n = d.unread_count != null ? d.unread_count : (d.count != null ? d.count : (d.unread != null ? d.unread : 0));
        n = parseInt(n, 10) || 0;
        var sc = countScope(); if (sc !== lastScope) { lastScope = sc; lastCount = null; }
        if (lastCount !== null && n > lastCount) {
          var diff = n - lastCount;
          var mt = toast('💬', diff === 1 ? 'New message' : diff + ' new messages', 'Open Messages to read.', '#1F6080');
          openOnTap(mt, '#chat');
          // Pop the conversation open rather than only announcing it. The dock already
          // survives SPA navigation, so this puts the sender's thread in front of you
          // the way a desktop chat client would.
          try { popOpenNewest(); } catch (e) {}
        }
        lastCount = n;
      }).catch(function () {});
  }

  /* A toast that says where to go, goes there when tapped (the ✕ still only dismisses). */
  function openOnTap(t, hash) {
    if (!t || !hash || t.__ktTap) return; t.__ktTap = 1; t.style.cursor = 'pointer';
    t.addEventListener('click', function (e) {
      if (e.target && e.target.getAttribute && e.target.getAttribute('aria-label') === 'Dismiss') return;
      if (location.hash !== hash) location.hash = hash;
      t.remove();
    });
  }

  /* NEW ALERTS GET A TOAST (2026-11-01, same report: "No toast is shown"). A new notification only moved the bell -
     on a desktop, easy to miss. kt-badges announces the person's list (kt:notification-list) once at start and again
     only when a newer notification exists, so: the first list is the baseline, and each later one toasts the unread
     rows newer than it. Messages are left to the message toast above (one toast per message, not two), and an
     emergency is kt-urgent-alert's full-screen alarm. At most two at once, then "N new alerts". */
  var notifBase = null;
  function linkOf(n) {
    var d = n && n.data; try { if (typeof d === 'string') d = JSON.parse(d); } catch (e) { d = null; }
    var l = String((d && (d.url || d.link || d.href || d.hash)) || '') || ({ announcement: 'announcements', award: 'awards', user_invited: 'admin-users' })[n && n.type] || ''; var i = l.indexOf('#');
    return i > -1 ? l.slice(i) : (/^[a-z0-9-]+(\?.*)?$/i.test(l) ? '#' + l : '#notifications');
  }
  function isEmergency(n) { var d = n && n.data; try { if (typeof d === 'string') d = JSON.parse(d); } catch (e) { d = null; } return !!(d && (d.emergency === 1 || d.emergency === '1' || d.emergency === true)); }
  function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }
  function onList(e) {
    var j = e && e.detail; if (!j) return;
    var rows = j.data || j.notifications || (Array.isArray(j) ? j : []); if (!Array.isArray(rows)) return;
    var top = 0; rows.forEach(function (n) { if (+n.id > top) top = +n.id; });
    if (notifBase === null) { notifBase = top; return; }
    var fresh = rows.filter(function (n) { return +n.id > notifBase && !n.read_at && !isEmergency(n)
      && !/^(chat|team_message|message)$/.test(String(n.type || '')); });
    if (top > notifBase) notifBase = top;
    if (!fresh.length) return;
    var sec = (location.hash || '').replace('#', '').split('?')[0];
    if (sec === 'notifications') return;   // the list itself is on screen
    if (fresh.length > 2) { openOnTap(toast('🔔', fresh.length + ' new alerts', 'Open Notifications to see them.', '#1F6080'), '#notifications'); return; }
    fresh.forEach(function (n) {
      openOnTap(toast('🔔', esc(n.title || 'New notification'), n.body ? esc(String(n.body).slice(0, 140)) : '', '#1F6080'), linkOf(n));
    });
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
    whenAppReady(function () { welcome(0); });   // welcome toast waits for the app to be visible (and any first-run gate)
    pollMessages();                     // (no UI on first poll — safe to start now)
    setInterval(pollMessages, 12000);   // near-real-time message alerts (served from the badges answer)
    window.addEventListener('kt:badges', pollMessages);   // real time: the moment the count moves
    try { var s0 = window.KT && KT.badges && KT.badges.now(); if (s0 && s0.newest_id != null) notifBase = +s0.newest_id; } catch (e) {}   // the list may already have been announced
    window.addEventListener('kt:notification-list', onList);   // new alerts (kt-badges fetches the list only when one exists)
    // Instant catch-up when the tab/app is brought back to the foreground.
    document.addEventListener('visibilitychange', function () { if (document.visibilityState === 'visible') pollMessages(); });
    window.addEventListener('focus', function () { pollMessages(); });
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start); else start();
})();
