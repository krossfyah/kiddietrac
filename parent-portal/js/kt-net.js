/* KT.net — requests that survive a bad connection, on the pages you use before you are
   signed in (2026-10-14).

   An educator in the Android app tapped "Sign in" again and again and saw only "Failed to
   fetch". The server was fine: those attempts never arrived. Two earlier attempts from the
   same phone had arrived; the same account signed in from a second phone minutes later; and
   that phone could not even load the page's web fonts. Its connection was dropping requests.

   fetch() rejects with a bare TypeError when no reply reaches the page at all (no signal,
   a VPN or filter, a captive portal, a reply the browser may not read). The page printed
   that TypeError's text, which tells nobody anything. So:

     · each attempt has a time limit (a hung request otherwise spins until the OS gives up);
     · a dropped attempt is retried twice, with a pause, before anyone is told anything;
     · then the person is told what it means and what to try, in words;
     · and the failure is remembered on the device and reported (/diag/client-error, the
       audit log) the next time a request gets through — otherwise these failures are
       invisible to us by definition, since they never reach the server.

   Retrying a sign-in is safe: at worst a reply was lost and a second session is opened.
   Callers that must not repeat (a payment, a check-in) pass { retries: 0 }.

     KT.net.fetch(url, init, { path, retries, timeoutMs, onRetry }) -> Promise<Response>
     KT.net.message(err) -> the sentence to show
*/
(function (w) {
  'use strict';
  var KT = w.KT || (w.KT = {});
  if (KT.net) return;
  var QK = 'kt_netfail_q';
  var API = function () { return (w.KT_CONFIG && w.KT_CONFIG.apiBase) || 'https://api.kiddietrac.com/api/v1'; };

  function offline() { try { return w.navigator && w.navigator.onLine === false; } catch (e) { return false; } }

  function message(err) {
    if (err && err.__ktNet) return err.message;
    if (err && (err.name === 'TypeError' || err.name === 'AbortError')) {
      return offline()
        ? 'You’re offline. Connect to Wi‑Fi or mobile data, then try again.'
        : 'We couldn’t reach KiddieTrac. Check your internet connection (turn off any VPN or data saver), then try again.';
    }
    return (err && err.message) || 'Something went wrong. Please try again.';
  }

  function remember(path, ms, tries) {
    try {
      var q = JSON.parse(w.localStorage.getItem(QK) || '[]');
      q.push({ p: String(path || '').slice(0, 120), ms: ms, t: tries, at: Date.now(), on: !offline() });
      w.localStorage.setItem(QK, JSON.stringify(q.slice(-20)));
    } catch (e) {}
  }

  /* Report what was remembered, once something gets through. Stamped server-side with the
     IP and device, and with the account when a token is present. */
  function flush() {
    var q;
    try { q = JSON.parse(w.localStorage.getItem(QK) || '[]'); } catch (e) { return; }
    if (!q || !q.length || offline()) return;
    try { w.localStorage.removeItem(QK); } catch (e) {}
    var headers = { 'Content-Type': 'application/json', 'Accept': 'application/json' };
    try {
      var t = w.sessionStorage.getItem('kt_token') || w.localStorage.getItem('kt_token');
      if (t) headers.Authorization = 'Bearer ' + t;
    } catch (e) {}
    q.slice(-10).forEach(function (f) {
      var ago = Math.max(0, Math.round((Date.now() - (f.at || Date.now())) / 60000));
      fetch(API() + '/diag/client-error', {
        method: 'POST', headers: headers, keepalive: true,
        body: JSON.stringify({
          status: 0, path: (f.p || 'unknown').replace(/^https?:\/\/[^/]+/, '').replace(/^\/api\/v1\//, ''), method: 'POST',
          kind: ('net_' + (f.on ? 'online' : 'offline') + '_x' + (f.t || 1) + '_' + ago + 'm_ago').slice(0, 40),
          ms: Math.min(f.ms || 0, 600000),
        }),
      }).catch(function () {
        remember(f.p, f.ms, f.t);       // still cannot get through: keep it for next time
      });
    });
  }

  async function fetchRetry(url, init, cfg) {
    cfg = cfg || {};
    var retries = cfg.retries == null ? 2 : cfg.retries;
    var limit = cfg.timeoutMs || 25000;
    var started = Date.now();
    var last = null;
    for (var i = 0; i <= retries; i++) {
      var ac = w.AbortController ? new AbortController() : null;
      var timer = ac ? setTimeout(function () { try { ac.abort(); } catch (e) {} }, limit) : null;
      try {
        var opts = Object.assign({}, init || {});
        if (ac) opts.signal = ac.signal;
        var res = await fetch(url, opts);
        if (timer) clearTimeout(timer);
        setTimeout(flush, 1500);          // it got through: report any earlier failures
        return res;
      } catch (e) {
        if (timer) clearTimeout(timer);
        last = e;
        if (offline()) break;             // no point retrying with no network at all
        if (i < retries) {
          if (typeof cfg.onRetry === 'function') { try { cfg.onRetry(i + 1); } catch (x) {} }
          await new Promise(function (r) { setTimeout(r, 1200 * (i + 1)); });
        }
      }
    }
    remember(cfg.path || url, Date.now() - started, i > retries ? retries + 1 : i + 1);
    var err = new Error(message(last));
    err.__ktNet = true;
    err.cause = last;
    throw err;
  }

  KT.net = { fetch: fetchRetry, message: message, flush: flush };
  // A page that loads at all has a connection: send anything left from last time.
  try { setTimeout(flush, 2500); } catch (e) {}
})(window);
