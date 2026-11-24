/* WARM THE DASHBOARD WHILE THE PERSON SIGNS IN (2026-11-02 dw, Anthony's TestFlight recording: the first dashboard
   after a fresh install loaded 234 files, 18 from cache, 1848 KB, and its splash held for 2.7 s).
   The sign-in page is a few seconds of someone typing. Once its launch scene has gone and the page is idle, this
   reads dashboard.html's own list of versioned scripts and stylesheets and puts every one the app does not already
   have into the service worker's versioned-asset cache (the cache the worker answers /js|/css ?v= requests from
   first - service-worker.js reads it before the network, and a miss there bypasses the HTTP cache, so the HTTP
   cache would not help). Then the dashboard opens from local copies.
     - Only the installed app (iPhone / Android) or a Home Screen install - where the dashboard comes next.
     - Never on a slow or data-saver connection; three requests at a time, low priority; never fetches while the
       launch scene plays; stops for good the moment Log in is pressed (the sign-in request goes alone).
     - The cache name is read from the live service-worker.js, so a renamed cache is followed, never guessed.
     - Off switch: localStorage kt_no_warm=1. Result in window.__ktWarm (state, want/had/got, kb, ms).
   STARTS SOONER, KEEPS GOING (2026-11-05, the App Review recording: a fresh install on a far, slow connection - 625 ms
   to connect - still opened a dashboard with 64 of 240 files cached, 8.7 s to DOMContentLoaded). It waited for the
   sign-in page's full load event, which on that link had not come 4 s in; it fetched three at a time; and it gave up
   the moment Log in was pressed. Now it starts once the page is parsed and the scene has gone (load or not), runs
   six at a time (one HTTP/2 connection, so this is queue depth, not sockets), and on Log in drops to one lane instead
   of stopping, so the second or so the sign-in takes still fills the cache. The result is kept in sessionStorage
   kt_warm, which the dashboard's boot beacon reports - the sign-in page's own beacon has gone before this starts. */
(function (w, d) {
  'use strict';
  try {
    if (!('caches' in w) || !w.fetch || !w.Promise) { return; }
    var C = w.Capacitor;
    var nat = !!(C && typeof C.isNativePlatform === 'function' && C.isNativePlatform());
    var home = false;
    try { home = w.matchMedia('(display-mode: standalone)').matches || w.navigator.standalone === true; } catch (e) {}
    if (!nat && !home) { return; }
    try { if (w.localStorage.getItem('kt_no_warm') === '1') { return; } } catch (e) {}
    var nc = w.navigator.connection;
    if (nc && (nc.saveData || /(^|-)2g|3g/.test(nc.effectiveType || ''))) { return; }
  } catch (e) { return; }

  var W = w.__ktWarm = { state: 'wait', want: 0, had: 0, got: 0, kb: 0, ms: 0 };
  var stopped = false, narrow = false, T0 = 0;
  function keep() { try { w.sessionStorage.setItem('kt_warm', JSON.stringify(W)); } catch (e) {} }
  function stop(why) { if (!stopped) { stopped = true; if (W.state !== 'done') { W.state = 'stopped:' + why; } W.kb = Math.round(W.kb); if (T0) { W.ms = Date.now() - T0; } keep(); } }
  // Log in: the sign-in request goes nearly alone - one lane carries on until the page is left.
  function login() { if (!narrow) { narrow = true; W.login = W.got; keep(); } }
  d.addEventListener('submit', login, true);
  d.addEventListener('click', function (e) { var t = e.target; if (t && t.closest && t.closest('#submitBtn')) { login(); } }, true);
  w.addEventListener('pagehide', function () { stop('leave'); });

  function idle(fn, ms) {
    setTimeout(function () { if (w.requestIdleCallback) { w.requestIdleCallback(fn, { timeout: 2000 }); } else { fn(); } }, ms);
  }
  function sceneGone() { return !d.getElementById('kt-launch'); }
  function begin() {
    if (stopped) { return; }
    if (!sceneGone() || d.readyState === 'loading') { idle(begin, 500); return; }
    W.state = 'run';
    var t0 = T0 = Date.now();
    Promise.all([
      fetch('/service-worker.js', { cache: 'no-cache', credentials: 'same-origin' }).then(function (r) { return r.ok ? r.text() : ''; }),
      fetch('/dashboard.html', { cache: 'no-cache', credentials: 'same-origin' }).then(function (r) { return r.ok ? r.text() : ''; })
    ]).then(function (both) {
      var m = /const\s+STATIC\s*=\s*["']([^"']+)["']/.exec(both[0] || '');
      if (!m || !both[1]) { W.state = 'skip:list'; return null; }
      var seen = {}, urls = [], re = /(?:src|href)="(\/(?:js|css)\/[^"?#]+\?v=[^"#]+)"/g, x;
      while ((x = re.exec(both[1]))) { if (!seen[x[1]]) { seen[x[1]] = 1; urls.push(x[1]); } }
      W.want = urls.length;
      return caches.open(m[1]).then(function (cache) {
        return Promise.all(urls.map(function (u) { return cache.match(u).then(function (hit) { return hit ? null : u; }); }))
          .then(function (missing) {
            missing = missing.filter(Boolean);
            W.had = urls.length - missing.length;
            var i = 0;
            function next(lane) {
              if (stopped || i >= missing.length || (narrow && lane > 0)) { return Promise.resolve(); }
              var u = missing[i++];
              var opt = { credentials: 'same-origin', cache: 'reload' };
              try { opt.priority = 'low'; } catch (e) {}
              return fetch(u, opt).then(function (res) {
                if (stopped || !res || res.status !== 200) { return; }
                W.kb += (parseInt(res.headers.get('content-length') || '0', 10) || 0) / 1024;   // wire size when sent; no second read of the body
                return cache.put(u, res);
              }).catch(function () {}).then(function () { W.got++; return next(lane); });
            }
            return Promise.all([next(0), next(1), next(2), next(3), next(4), next(5)]);
          });
      });
    }).then(function () {
      W.ms = Date.now() - t0; W.kb = Math.round(W.kb);
      if (!stopped) { W.state = 'done'; }
      keep();
    }, function () { W.state = 'error'; keep(); });
  }
  // Once the sign-in page is parsed (its scene gone), a moment of quiet first - not its load event, which a slow
  // link can hold back for the whole time the person is typing.
  if (d.readyState !== 'loading') { idle(begin, 800); } else { d.addEventListener('DOMContentLoaded', function () { idle(begin, 800); }); }
})(window, document);
