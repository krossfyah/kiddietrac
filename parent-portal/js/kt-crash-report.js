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
      var where = (e && e.filename ? e.filename : '') + ':' + (e && e.lineno ? e.lineno : '') + ':' + (e && e.colno ? e.colno : '');
      var stack = (e && e.error && e.error.stack) ? e.error.stack : ((e && e.message ? e.message : 'error') + ' @ ' + where);
      set('kt_js_error', nowIso() + '  JS ERROR  ' + String(stack).slice(0, 4000));
    } catch (x) {}
  });
  window.addEventListener('unhandledrejection', function (e) {
    try {
      var r = e && e.reason;
      var s = (r && r.stack) ? r.stack : String(r);
      set('kt_js_error', nowIso() + '  UNHANDLED PROMISE  ' + String(s).slice(0, 4000));
    } catch (x) {}
  });

  function sendReport(trace, onDone) {
    var body = { device: navigator.userAgent, os: (navigator.platform || 'web'), app: 'webview', trace: trace, email: 1 };
    try {
      fetch(apiBase() + '/diag/crash', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body) })
        .then(function (r) { onDone && onDone(r && r.ok); })
        .catch(function () { onDone && onDone(false); });
    } catch (e) { onDone && onDone(false); }
  }

  function promptSend(trace) {
    if (document.getElementById('kt-crash-ov')) return;
    var ov = document.createElement('div'); ov.id = 'kt-crash-ov';
    ov.style.cssText = 'position:fixed;left:0;right:0;bottom:0;z-index:2147481000;padding:16px 16px calc(env(safe-area-inset-bottom,0px) + 16px);background:#0D1B2A;color:#fff;box-shadow:0 -6px 24px rgba(0,0,0,.4);font-family:system-ui,-apple-system,sans-serif;animation:kt-crash-up .3s ease both;';
    var st = document.createElement('style'); st.textContent = '@keyframes kt-crash-up{from{transform:translateY(100%);}to{transform:none;}}'; document.head.appendChild(st);
    ov.innerHTML = '<div style="font-weight:800;font-size:15px;margin-bottom:4px;">⚠️ KiddieTrac had a problem</div>'
      + '<div style="font-size:13px;opacity:.85;margin-bottom:12px;line-height:1.5;">Send a quick error report so our team can fix it? Only technical details are shared.</div>'
      + '<div style="display:flex;gap:10px;">'
      + '<button id="kt-crash-send" style="flex:1;background:#0E7C90;color:#fff;border:none;border-radius:11px;padding:12px;font-weight:800;font-size:14px;cursor:pointer;">Send report</button>'
      + '<button id="kt-crash-no" style="background:transparent;color:rgba(255,255,255,.7);border:none;padding:12px 14px;font-weight:700;font-size:13px;cursor:pointer;">Not now</button></div>';
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
