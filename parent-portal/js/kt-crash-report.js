/* ═══════════════════════════════════════════════════════════════════
   KiddieTrac — crash management + report prompt (2026-07-11).
   Captures JS errors + unhandled rejections in the mobile/web view, and picks
   up native crashes surfaced by MainActivity (localStorage.kt_last_crash). On
   the next launch after a problem, it offers a "Send report" prompt; sending
   POSTs to /diag/crash, which logs it and emails info@kiddietrac.com.
   ═══════════════════════════════════════════════════════════════════ */
(function () {
  'use strict';
  if (window.__ktCrashReport) return; window.__ktCrashReport = true;

  function apiBase() { return (window.KT_CONFIG && window.KT_CONFIG.apiBase) || 'https://api.kiddietrac.com/api/v1'; }
  function get(k) { try { return localStorage.getItem(k); } catch (e) { return null; } }
  function set(k, v) { try { localStorage.setItem(k, v); } catch (e) {} }
  function del(k) { try { localStorage.removeItem(k); } catch (e) {} }
  function nowIso() { try { return new Date().toISOString(); } catch (e) { return ''; } }

  // ── Capture JS-level problems as they happen ────────────────────────
  window.addEventListener('error', function (e) {
    try {
      if (isNetworkNoise(e && (e.error || e.message))) return;
      var where = (e && e.filename ? e.filename : '') + ':' + (e && e.lineno ? e.lineno : '') + ':' + (e && e.colno ? e.colno : '');
      var stack = (e && e.error && e.error.stack) ? e.error.stack : ((e && e.message ? e.message : 'error') + ' @ ' + where);
      set('kt_js_error', nowIso() + '  JS ERROR  ' + String(stack).slice(0, 4000));
    } catch (x) {}
  });
  // A transient CONNECTIVITY failure (dropped/slow network, offline, timeout) is
  // NOT an app crash — the user just needs to retry — so it must never trip the
  // "send a crash report?" prompt. ApiError uses type:'network'/status:0; also match
  // the common fetch-failure messages as a backstop.
  function isNetworkNoise(r) {
    try {
      if (r && (r.type === 'network' || r.status === 0)) return true;
      var m = String((r && (r.message || r.reason)) || r || '');
      return /network error|failed to fetch|load failed|networkerror|the internet connection|err_internet|err_network|err_timed_out|err_connection|err_name_not_resolved|failed to update a serviceworker|error occurred when fetching the script|serviceworker.*script/i.test(m);
    } catch (x) { return false; }
  }
  window.addEventListener('unhandledrejection', function (e) {
    try {
      var r = e && e.reason;
      if (isNetworkNoise(r)) return;
      var s = (r && r.stack) ? r.stack : String(r);
      set('kt_js_error', nowIso() + '  UNHANDLED PROMISE  ' + String(s).slice(0, 4000));
    } catch (x) {}
  });

  // What was the user doing? A stack trace alone does not say which screen they
  // were on, who they are, or what they had just touched - all of which is the
  // difference between reproducing a report and guessing at it.
  var lastAction = '';
  try {
    document.addEventListener('click', function (e) {
      try {
        var t = e.target;
        if (!t || t.nodeType !== 1) return;
        var id = t.id ? '#' + t.id : '';
        var cls = (typeof t.className === 'string' && t.className) ? '.' + t.className.trim().split(/\s+/)[0] : '';
        var txt = (t.textContent || '').trim().replace(/\s+/g, ' ').slice(0, 40);
        lastAction = t.tagName.toLowerCase() + id + cls + (txt ? ' "' + txt + '"' : '');
      } catch (x) {}
    }, true);
  } catch (x) {}

  function currentUser() {
    var out = {};
    try {
      var raw = localStorage.getItem('kt_user') || sessionStorage.getItem('kt_user');
      var u = raw ? JSON.parse(raw) : null;
      if (u) {
        out.user_id = u.id || null;
        out.user_email = u.email || '';
        out.user_name = [u.first_name, u.last_name].filter(Boolean).join(' ') || u.name || '';
        out.role = (u.roles && u.roles.length ? u.roles[0] : (u.role || '')) || '';
      }
      out.agency_id = sessionStorage.getItem('kt_active_agency_id') || localStorage.getItem('kt_active_agency_id') || null;
    } catch (x) {}
    return out;
  }

  function sendReport(trace, onDone) {
    var body = { device: navigator.userAgent, os: (navigator.platform || 'web'), app: 'webview', trace: trace, email: 1 };
    try {
      var u = currentUser();
      for (var k in u) if (Object.prototype.hasOwnProperty.call(u, k)) body[k] = u[k];
      body.url = String(location.href).slice(0, 300);
      body.screen = String(location.hash || '#').slice(0, 120);
      body.app_version = String(window.KT_VERSION || '');
      body.last_action = String(lastAction || '').slice(0, 160);
      body.viewport = (window.innerWidth || 0) + 'x' + (window.innerHeight || 0);
      body.native = !!window.__KT_NATIVE;
      body.online = navigator.onLine !== false;
    } catch (x) {}
    try {
      fetch(apiBase() + '/diag/crash', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body) })
        .then(function (r) { onDone && onDone(r && r.ok); })
        .catch(function () { onDone && onDone(false); });
    } catch (e) { onDone && onDone(false); }
  }

  function promptSend(trace) {
    if (document.getElementById('kt-crash-ov')) return;
    // A centred dialog over a dimmed page, not a full-width bar glued to the bottom
    // edge. The bar spanned the whole screen and its "Send report" button stretched
    // with it, which read as a system-level failure rather than an ordinary prompt.
    var ov = document.createElement('div'); ov.id = 'kt-crash-ov';
    ov.style.cssText = 'position:fixed;inset:0;z-index:2147481000;display:flex;align-items:center;justify-content:center;padding:20px;'
      + 'background:rgba(8,28,65,.55);backdrop-filter:blur(2px);-webkit-backdrop-filter:blur(2px);'
      + 'font-family:system-ui,-apple-system,sans-serif;animation:kt-crash-fade .18s ease both;';
    var st = document.createElement('style');
    st.textContent = '@keyframes kt-crash-fade{from{opacity:0;}to{opacity:1;}}'
      + '@keyframes kt-crash-pop{from{opacity:0;transform:translateY(8px) scale(.98);}to{opacity:1;transform:none;}}';
    document.head.appendChild(st);

    ov.innerHTML = '<div role="dialog" aria-modal="true" aria-labelledby="kt-crash-t" style="width:100%;max-width:380px;background:#0D1B2A;color:#fff;border-radius:16px;padding:22px;box-shadow:0 24px 60px -12px rgba(0,0,0,.55);animation:kt-crash-pop .22s ease both;">'
      + '<div id="kt-crash-t" style="font-weight:800;font-size:16px;margin-bottom:6px;">⚠️ KiddieTrac had a problem</div>'
      + '<div style="font-size:13px;opacity:.85;margin-bottom:16px;line-height:1.5;">Send a quick error report so our team can fix it? Only technical details are shared.</div>'
      + '<div style="display:flex;gap:8px;justify-content:flex-end;align-items:center;">'
      + '<button id="kt-crash-no" style="background:transparent;color:rgba(255,255,255,.7);border:none;padding:10px 12px;font-weight:700;font-size:13px;cursor:pointer;border-radius:9px;">Not now</button>'
      + '<button id="kt-crash-send" style="background:#0E7C90;color:#fff;border:none;border-radius:9px;padding:10px 18px;font-weight:800;font-size:13px;cursor:pointer;">Send report</button>'
      + '</div></div>';
    document.body.appendChild(ov);
    document.getElementById('kt-crash-send').onclick = function () {
      var b = document.getElementById('kt-crash-send'); b.textContent = 'Sending…'; b.disabled = true;
      sendReport(trace, function (ok) {
        del('kt_last_crash'); del('kt_js_error'); ov.remove();
        if (window.KT && KT.toast) KT.toast(ok ? '✅' : '⚠️', ok ? 'Report sent — thank you' : 'Could not send the report', '', '#0E7C90');
      });
    };
    document.getElementById('kt-crash-no').onclick = function () { del('kt_last_crash'); del('kt_js_error'); ov.remove(); };
  }

  // On launch, if a crash was captured (native from MainActivity, or a prior JS
  // error), offer to send it. Delayed so it never fights the biometric lock.
  setTimeout(function () {
    var trace = get('kt_last_crash') || get('kt_js_error');
    if (trace) promptSend(trace);
  }, 3500);
})();
