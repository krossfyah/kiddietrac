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
      // "Script error." with no filename and no line is the browser refusing to
      // describe an error raised by a cross-origin script - typically an extension,
      // a content blocker or a password manager injected into the page. There is no
      // stack behind it to recover. Say so, rather than filing a high-priority
      // ticket whose trace looks like something a human failed to read properly.
      if (!(e && e.filename) && /^script error\.?/i.test(String((e && e.message) || ''))) {
        stack = 'Script error (opaque). The browser withheld the detail because the '
              + 'error came from a cross-origin script - usually a browser extension '
              + 'or injected script, not KiddieTrac code. No file, line or stack is '
              + 'recoverable for this class of error.';
      }
      var _t = nowIso() + '  JS ERROR  ' + String(stack).slice(0, 4000);
      set('kt_js_error', _t);          // stashed first, so a failed send still survives
      autoSend(_t);
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
      var _t = nowIso() + '  UNHANDLED PROMISE  ' + String(s).slice(0, 4000);
      set('kt_js_error', _t);
      autoSend(_t);
    } catch (x) {}
  });

  // What was the user doing? A stack trace alone does not say which screen they
  // were on, who they are, or what they had just touched - all of which is the
  // difference between reproducing a report and guessing at it.
  /* ── BREADCRUMBS ──────────────────────────────────────────────────────────
     `lastAction` only ever recorded CLICKS, so a failure with no click behind it —
     a background poller, a screen that never finished loading, a request that 404'd —
     filed a report saying "Last action: not captured". Ticket #18 was exactly that,
     and it is why "Bruni's screens would not load" could not be answered from the
     data: nothing recorded what her session was doing.

     A small ring buffer of what actually happened: navigations, failed requests,
     service-worker changes and clicks, newest last. Capped hard — this rides along
     with a crash report, it is not a telemetry pipe, and it must never become one.
     (2026-08-26) */
  var CRUMB_MAX = 25;
  var ktCrumbs = [];
  function crumb(kind, detail) {
    try {
      ktCrumbs.push({
        t: new Date().toISOString().slice(11, 19),
        k: String(kind).slice(0, 12),
        d: String(detail == null ? '' : detail).slice(0, 120),
      });
      if (ktCrumbs.length > CRUMB_MAX) ktCrumbs.shift();
    } catch (x) {}
  }
  // Exposed so any screen can drop a marker: KT.crumb('walk', 'started tracking').
  try { (window.KT = window.KT || {}).crumb = crumb; } catch (x) {}

  // Where they went.
  try {
    crumb('boot', location.hash || '#');
    window.addEventListener('hashchange', function () { crumb('nav', location.hash || '#'); });
  } catch (x) {}

  /* A fetch wrapper used to live here, recording failed requests as crumbs. REMOVED
     2026-08-26: it wrapped EVERY request in the portal, and a messenger render fault was
     reported hours after it shipped. The wrapper was not proven to be the cause — a
     direct test showed it did not throw — but a global interception of every request is
     not something to leave in place while a global-feeling bug is unexplained. The cheap
     move is to remove the variable, not to defend it.

     If failed-request crumbs are wanted again, hook the portal's OWN Api helper instead,
     which is one well-known call site rather than all of them. */

  // A new build taking over mid-session explains a great deal of "it went weird".
  try {
    if (navigator.serviceWorker) {
      navigator.serviceWorker.addEventListener('controllerchange', function () {
        crumb('sw', 'controller changed — new build took over');
      });
    }
  } catch (x) {}

  var lastAction = '';
  try {
    document.addEventListener('click', function (e) {
      try {
        var t = e.target;
        if (!t || t.nodeType !== 1) return;
        // Never record a click inside the crash dialog itself. The report body is built
        // when "Send report" is pressed, so without this the breadcrumb was overwritten
        // with the Send button a moment before being read — every report ever filed said
        // the last action was button#kt-crash-send, which is the one thing it cannot be.
        if (t.closest && t.closest('#kt-crash-ov')) return;
        var id = t.id ? '#' + t.id : '';
        var cls = (typeof t.className === 'string' && t.className) ? '.' + t.className.trim().split(/\s+/)[0] : '';
        var txt = (t.textContent || '').trim().replace(/\s+/g, ' ').slice(0, 40);
        lastAction = t.tagName.toLowerCase() + id + cls + (txt ? ' "' + txt + '"' : '');
        crumb('click', lastAction);
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

  /* ── AUTOMATIC REPORTING ──────────────────────────────────────────────────
     A report used to be sent only if the person TAPPED "Send report", and only on
     the next launch. Two ways that loses the report entirely: they decline, or the
     crash is what stops them coming back. So the errors most worth seeing were the
     least likely to arrive.

     Reports are sent as they happen now. The prompt is gone; a short notice takes its
     place so nobody is surprised that something was sent on their behalf — technical
     detail plus who was signed in, to our own server, which is what the prompt was
     asking permission for anyway.

     Guarded, because "send every error immediately" is one bad loop away from being a
     denial of service against our own API:
       • the SAME trace is sent once per session, not once per occurrence;
       • at most SEND_MAX reports per session however many distinct errors there are;
       • a failed send falls back to the old stash, so a report is retried on the next
         launch rather than lost — offline is the ordinary case here, not an edge. */
  var SEND_MAX = 5;
  var sentCount = 0;
  var sentTraces = {};

  function traceKey(trace) {
    // The first meaningful line, minus the timestamp: two occurrences of one bug
    // differ only in when they happened.
    try {
      var t = String(trace || '').replace(/^\S+\s+/, '');
      return t.split('\n')[0].slice(0, 200);
    } catch (x) { return String(trace || '').slice(0, 200); }
  }

  function autoSend(trace, opts) {
    opts = opts || {};
    try {
      var key = traceKey(trace);
      if (!key) return;
      if (sentTraces[key]) return;               // already reported this one
      if (sentCount >= SEND_MAX) return;         // something is looping; stop adding to it
      sentTraces[key] = 1;
      sentCount++;
      /* opts is forwarded so the freeze watchdog's long-task summary reaches the
         server as its own field, not only as prose inside the trace. */
      sendReport(trace, function (ok) {
        if (ok) {
          // It is on the server; nothing to offer at next launch.
          del('kt_js_error'); del('kt_last_crash');
          if (!opts.quiet) notifyReported();
        } else {
          // Keep it for the next launch — this is the offline case.
          sentTraces[key] = 0;
          sentCount--;
        }
      }, opts);
    } catch (x) {}
  }

  /* Told, not asked. A small line that fades, rather than a dialog demanding a
     decision from somebody who has just watched the app break. */
  function notifyReported() {
    try {
      if (document.getElementById('kt-crash-note')) return;
      var n = document.createElement('div');
      n.id = 'kt-crash-note';
      n.setAttribute('role', 'status');
      n.style.cssText = 'position:fixed;left:50%;transform:translateX(-50%);bottom:calc(env(safe-area-inset-bottom,0px) + 84px);'
        + 'z-index:2147481000;background:#0D1B2A;color:#fff;font-family:system-ui,-apple-system,sans-serif;'
        + 'font-size:13px;line-height:1.45;padding:10px 16px;border-radius:12px;max-width:86vw;'
        + 'box-shadow:0 10px 30px -8px rgba(0,0,0,.5);opacity:0;transition:opacity .25s ease;';
      n.textContent = 'Sorry — something went wrong. Our team has been sent a report.';
      document.body.appendChild(n);
      requestAnimationFrame(function () { n.style.opacity = '1'; });
      setTimeout(function () {
        n.style.opacity = '0';
        setTimeout(function () { try { n.remove(); } catch (x) {} }, 400);
      }, 5200);
    } catch (x) {}
  }

  // Any part of the app can report a problem it has decided is worth reporting —
  // the freeze watchdog uses this.
  try { (window.KT = window.KT || {}).reportProblem = autoSend; } catch (x) {}

  /* Reports that have been built but not yet acknowledged. If the page goes away
     with anything in here it is beaconed out on the way -- see the pagehide handler
     below. A frozen tab gets CLOSED, and a report sent by fetch dies with it. */
  var pending = [];

  /* sendBeacon survives the document. It cannot report success, so it is used only
     where fetch cannot be trusted to finish: the page is already hidden, or it is
     actively going away. */
  function beacon(body) {
    try {
      if (! navigator.sendBeacon) { return false; }
      var blob = new Blob([JSON.stringify(body)], { type: 'application/json' });
      return navigator.sendBeacon(apiBase() + '/diag/crash', blob);
    } catch (x) { return false; }
  }

  try {
    window.addEventListener('pagehide', function () {
      while (pending.length) { beacon(pending.pop()); }
    });
  } catch (x) {}

  function sendReport(trace, onDone, extra) {
    var body = { device: navigator.userAgent, os: (navigator.platform || 'web'), app: 'webview', trace: trace, email: 1 };
    /* The watchdog's long-task summary: which script blocked the thread, and for how
       long. Stored as its own field so freezes can be grouped by cause, not only read. */
    try { if (extra && extra.longTasks) { body.long_tasks = String(extra.longTasks).slice(0, 600); } } catch (x) {}
    try {
      var u = currentUser();
      for (var k in u) if (Object.prototype.hasOwnProperty.call(u, k)) body[k] = u[k];
      body.url = String(location.href).slice(0, 300);
      body.screen = String(location.hash || '#').slice(0, 120);
      body.app_version = String(window.KT_VERSION || '');
      body.last_action = String(lastAction || '').slice(0, 160);
      // Newest last, so the report reads like a story ending at the failure.
      try {
        body.breadcrumbs = ktCrumbs.map(function (c) { return c.t + ' ' + c.k + ' ' + c.d; }).join('\n').slice(0, 2400);
      } catch (x) {}
      body.viewport = (window.innerWidth || 0) + 'x' + (window.innerHeight || 0);
      body.native = !!window.__KT_NATIVE;
      body.online = navigator.onLine !== false;
      body.lang = navigator.language || '';
      body.screen_size = (screen && screen.width ? screen.width + 'x' + screen.height : '');
      body.dpr = String(window.devicePixelRatio || 1);
      // Which service-worker build served this session — the stale-asset question
      // is the first thing asked of any "it broke for me" report.
      try {
        var reg = navigator.serviceWorker && navigator.serviceWorker.controller;
        body.sw = reg ? String(reg.scriptURL || '').split('/').pop() : 'none';
      } catch (x) {}
    } catch (x) {}
    /* Already hidden? fetch may never finish. Beacon it and stop. */
    try {
      if (document.visibilityState === 'hidden') {
        var ok = beacon(body);
        onDone && onDone(ok);
        return;
      }
    } catch (x) {}

    pending.push(body);
    function settle(ok) {
      var i = pending.indexOf(body);
      if (i !== -1) { pending.splice(i, 1); }
      onDone && onDone(ok);
    }
    try {
      fetch(apiBase() + '/diag/crash', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body) })
        .then(function (r) { settle(r && r.ok); })
        .catch(function () { settle(beacon(body)); });   // network refused it — try the beacon
    } catch (e) { settle(beacon(body)); }
  }

  /* promptSend() lived here: a modal asking "Send a quick error report?" before
     anything was transmitted. Reports are sent automatically now (see autoSend
     above), so nothing called it. Removed rather than left as sixty lines of dead
     dialog — git has it if the consent step is ever wanted back. (2026-08-31) */

  // On launch, if a crash was captured (native from MainActivity, or a prior JS
  // error), offer to send it. Delayed so it never fights the biometric lock.
  // How old is a stored trace? Both are written with a leading ISO timestamp.
  function traceAgeMs(t) {
    try {
      var m = String(t || '').match(/^(\d{4}-\d{2}-\d{2}T[\d:.]+Z)/);
      if (!m) return 0;                       // no stamp - treat as current
      var age = Date.now() - new Date(m[1]).getTime();
      return (isFinite(age) && age > 0) ? age : 0;
    } catch (e) { return 0; }
  }
  var MAX_TRACE_AGE_MS = 6 * 60 * 60 * 1000;  // six hours

  setTimeout(function () {
    var native = get('kt_last_crash');
    var js = get('kt_js_error');

    // A trace only ever cleared when somebody ANSWERS the prompt will outlive the
    // session that produced it - close the tab and it is offered again on every
    // launch from then on. That is how a report filed on 13 August came to carry an
    // error from 6 August, sending triage a week away from what the user was
    // actually doing. Anything stale is dropped, not offered.
    if (native && traceAgeMs(native) > MAX_TRACE_AGE_MS) { del('kt_last_crash'); native = null; }
    if (js && traceAgeMs(js) > MAX_TRACE_AGE_MS) { del('kt_js_error'); js = null; }

    /* Whatever is still here either happened while the page was dying, or is a NATIVE
       crash written by MainActivity before the app went — neither could be sent at the
       time. Send it now rather than asking. Quiet: the notice belongs next to the
       failure, and this is a later launch where it would only be confusing. */
    var trace = native || js;
    if (trace) autoSend(trace, { quiet: true });
  }, 3500);
})();
