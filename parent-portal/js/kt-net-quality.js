/* kt-net-quality.js — HOW FAST IS THIS CONNECTION, AND ACT ON IT (2026-10-29).
 *
 * Anthony: "on poor or slow internet connections things don't load or very slow to load (is
 * there anything you can wire up to make this work with slower internet connections) like a
 * middleware or something to monitor".
 *
 * Measures the portal's own API calls on this device (Resource Timing - what the person
 * actually waited, not what the server spent) plus the phone's connection estimate where the
 * browser gives one (Android Chrome; iPhones do not). From that:
 *   html.kt-slow-net + window.__ktSlowNet  - the request queue in dashboard.html (the read
 *        limiter) then holds badge counts and polls back to two at a time, so the screen being
 *        opened gets the bandwidth; the service worker skips its background re-downloads.
 *   a small "Slow connection - still loading" note when a request has been waiting 5s, so a
 *        slow screen says so instead of looking broken.
 *   one summary per session to /diag/net (and again the first time it turns slow) -
 *        Platform -> Server health -> "Connections" (App\Support\NetQuality).
 * Never throws; numbers only (no URLs or content leave the device).
 *   KT.netQuality.state()  - what it currently thinks
 */
(function (w) {
  'use strict';
  var d = w.document;
  w.KT = w.KT || {};
  var API = /^https:\/\/api\.kiddietrac\.com\/api\//;
  var SLOW_MEDIAN_MS = 2000, STALL_MS = 5000;
  var durs = [], failures = 0, pending = {}, seq = 0, slow = false, pill = null, pillTimer = null;

  function conn() { return (w.navigator && w.navigator.connection) || null; }
  function median(a) { if (!a.length) return null; var s = a.slice().sort(function (x, y) { return x - y; }); return s[s.length >> 1]; }
  function p90(a) { if (!a.length) return null; var s = a.slice().sort(function (x, y) { return x - y; }); return s[Math.min(s.length - 1, Math.floor(s.length * 0.9))]; }
  function platform() {
    try {
      var C = w.Capacitor;
      if (C && typeof C.getPlatform === 'function' && C.getPlatform() !== 'web') return C.getPlatform() + '-app';
      if (w.matchMedia && w.matchMedia('(display-mode: standalone)').matches) return 'pwa';
      if (w.navigator.standalone === true) return 'pwa';
    } catch (e) {}
    return /Mobi|Android|iPhone/.test(w.navigator.userAgent || '') ? 'mobile-web' : 'web';
  }

  function evaluate() {
    var c = conn(), m = durs.length >= 4 ? median(durs) : null;
    var byConn = !!(c && (c.saveData || /(^|-)2g|3g/.test(c.effectiveType || '')));
    var next = byConn || (m !== null && m > SLOW_MEDIAN_MS);
    if (next === slow) return;
    slow = next;
    w.__ktSlowNet = slow;
    try { d.documentElement.classList.toggle('kt-slow-net', slow); } catch (e) {}
    try { w.dispatchEvent(new CustomEvent('kt:netquality', { detail: state() })); } catch (e) {}
    if (slow) report('slow');
  }

  function state() {
    var c = conn();
    return { slow: slow, api_median: median(durs), api_p90: p90(durs), samples: durs.length, failures: failures,
      effective_type: c && c.effectiveType || null, rtt: c && typeof c.rtt === 'number' ? c.rtt : null,
      downlink: c && typeof c.downlink === 'number' ? c.downlink : null, platform: platform() };
  }

  /* ── measuring: every finished API request, from Resource Timing ── */
  try {
    new PerformanceObserver(function (list) {
      list.getEntries().forEach(function (e) {
        if (!API.test(e.name) || /\/diag\//.test(e.name)) return;
        var ms = e.responseEnd - e.startTime;
        if (!(ms > 0)) return;
        durs.push(Math.round(ms)); if (durs.length > 30) durs.shift();
      });
      evaluate();
    }).observe({ type: 'resource', buffered: true });
  } catch (e) {}
  try { var c0 = conn(); if (c0 && c0.addEventListener) c0.addEventListener('change', evaluate); } catch (e) {}

  /* ── waiting: requests still open, for the "still loading" note; failures, for the report ── */
  if (typeof w.fetch === 'function') {
    var base = w.fetch;
    w.fetch = function (input) {
      var u = ''; try { u = String((input && input.url) || input); } catch (e) {}
      if (!API.test(u) || /\/diag\//.test(u)) return base.apply(this, arguments);
      var id = ++seq; pending[id] = Date.now(); watch();
      var p = base.apply(this, arguments);
      p.then(function () { delete pending[id]; watch(); }, function () { delete pending[id]; failures++; watch(); });
      return p;
    };
  }
  function oldest() { var o = 0, now = Date.now(); for (var k in pending) { var a = now - pending[k]; if (a > o) o = a; } return o; }
  function watch() {
    if (pillTimer) return;
    pillTimer = w.setTimeout(function () {
      pillTimer = null;
      var o = oldest();
      if (o >= STALL_MS && !d.hidden && w.navigator.onLine !== false) showPill(); else hidePill();
      if (Object.keys(pending).length) watch();
    }, 1000);
  }
  function showPill() {
    if (pill && pill.isConnected) return;
    pill = d.createElement('div');
    pill.id = 'kt-slow-pill';
    pill.setAttribute('role', 'status');
    pill.style.cssText = 'position:fixed;left:50%;transform:translateX(-50%);bottom:calc(var(--kt-safe-bottom, env(safe-area-inset-bottom,0px)) + 86px);z-index:2147483000;'
      + 'background:rgba(15,23,42,.88);color:#fff;font:600 12.5px/1.2 system-ui,-apple-system,"Segoe UI",sans-serif;padding:8px 14px;border-radius:999px;'
      + 'box-shadow:0 6px 18px -6px rgba(0,0,0,.45);pointer-events:none;white-space:nowrap;opacity:0;transition:opacity .2s ease;';
    pill.textContent = '\u{1F4F6} Slow connection — still loading…';
    (d.body || d.documentElement).appendChild(pill);
    w.requestAnimationFrame(function () { if (pill) pill.style.opacity = '1'; });
  }
  function hidePill() { if (pill) { var p = pill; pill = null; p.style.opacity = '0'; w.setTimeout(function () { p.remove(); }, 220); } }

  /* ── reporting: one summary a session, and once more when it turns slow ── */
  function token() { try { return sessionStorage.getItem('kt_token') || localStorage.getItem('kt_token'); } catch (e) { return null; } }
  function report(why) {
    var flag = 'kt_net_rep_' + why;
    try { if (sessionStorage.getItem(flag)) return; sessionStorage.setItem(flag, '1'); } catch (e) {}
    var s = state(); if (!s.samples && why !== 'slow') return;
    var h = { 'Content-Type': 'application/json', Accept: 'application/json' };
    var t = token(); if (t) h.Authorization = 'Bearer ' + t;
    try { var a = sessionStorage.getItem('kt_active_agency_id') || localStorage.getItem('kt_active_agency_id'); if (a) h['X-Active-Agency-Id'] = a; } catch (e) {}
    try { fetch('https://api.kiddietrac.com/api/v1/diag/net', { method: 'POST', headers: h, body: JSON.stringify(s), keepalive: true }).catch(function () {}); } catch (e) {}
  }
  w.setTimeout(function () { report('session'); }, 30000);

  w.KT.netQuality = { state: state };
})(window);
