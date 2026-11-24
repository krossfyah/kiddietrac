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
    if (err && (err.name === 'TypeError' || err.name === 'AbortError' || err.status === 0)) {
      return offline()
        ? 'You’re offline. Connect to Wi‑Fi or mobile data, then try again.'
        : 'We couldn’t reach KiddieTrac. Check your internet connection (turn off any VPN or data saver), then try again.';
    }
    // A server message is shown only when it is a sentence for a person (a firewall's wording is not - 2026-11-05).
    return (err && err.message) ? cleanError(err.message, err.status) : 'Something went wrong. Please try again.';
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

  /* EVERY OTHER fetch ON THE PAGE TOO (2026-10-22).

     Anthony, 2026-10-22, a photo of the sign-in page after an inactivity sign-out, with
     "Failed to fetch" under the password box and the button still reading "Log in": he
     had not pressed anything. The words came from the passkey helper, which asks the
     server for a challenge as the page opens, and that request (like dozens of others in
     the portal) printed the browser's TypeError text as-is. Chrome says "Failed to
     fetch", Safari "Load failed", Firefox "NetworkError when attempting to fetch
     resource." None of them tells a person anything.

     So a rejected fetch anywhere is re-thrown as a TypeError still (every `name ===
     'TypeError'` and `instanceof TypeError` check keeps working) whose message is the
     sentence above. Nothing is retried here: only the caller knows whether sending a
     request twice is safe (see KT.net.fetch, and app.js for GETs). A caller's own abort
     (AbortError) passes through untouched. */
  var _nativeFetch = w.fetch;
  if (typeof _nativeFetch === 'function' && !_nativeFetch.__ktNetWords) {
    var worded = function () {
      return _nativeFetch.apply(w, arguments).catch(function (e) {
        if (e && e.name === 'TypeError' && !e.__ktNet) {
          var f = new TypeError(message(e));
          f.__ktNet = true;
          f.cause = e;
          throw f;
        }
        throw e;
      });
    };
    worded.__ktNetWords = true;
    w.fetch = worded;
  }


  /* ── NO RAW ERROR MESSAGES, EVER (Anthony, 2026-10-22) ──

     "no raw error messages ever on the portal - full sweep". Screens print err.message
     in hundreds of places, and an exception's text is written for programmers:
     "Cannot read properties of undefined (reading 'id')", "Unexpected token '<'… is not
     valid JSON", "SQLSTATE[23000]…". The API now replaces its own (FriendlyErrorMessages);
     this is the portal's half, in two parts:

       cleanError(text, status) → the text itself if it is a sentence for a person, else
         a plain one. app.js runs every API error through it; so can anything else.
       a watcher over the page → if raw error text is put on screen anyway (a toast, a
         status line, a table cell), it is replaced with that sentence before anyone reads
         it, and reported so the screen that did it gets fixed.

     Both match only text no person would write; everything else is left as it is. Mark
     an element data-kt-raw to opt out (a developer page showing logs on purpose). */
  var NET_RX = /Failed to fetch|NetworkError when attempting to fetch|^\s*Load failed\s*$|net::ERR_|\bERR_(INTERNET_DISCONNECTED|NETWORK_CHANGED|CONNECTION_\w+|NAME_NOT_RESOLVED|TIMED_OUT)\b/;
  var CODE_RX = new RegExp([
    'SQLSTATE', 'Integrity constraint', 'Undefined (array key|variable|index|offset|property)',
    'Call to (undefined|a member function)', 'Attempt to read property', 'Allowed memory size',
    'Cannot read propert(y|ies) of (undefined|null)', 'Cannot set propert(y|ies) of (undefined|null)',
    "\\b[\\w$.\\]\\[)(]+ is not a function\\b", '\\bis not iterable\\b', 'Maximum call stack',
    "Unexpected token .{0,12}(in JSON|is not valid JSON)", 'is not valid JSON', 'Unexpected end of JSON input',
    'JSON\\.parse:', 'Request failed \\(\\d{3}\\)', 'Request failed with status code', 'Failed to execute \'',
    '^\\s*(Uncaught\\s+)?(TypeError|ReferenceError|SyntaxError|RangeError|EvalError|DOMException|InternalError)\\b',
    'Illuminate\\\\', 'Symfony\\\\', 'PDOException', 'ErrorException', '\\.php(:\\d+| on line)',
    '^\\s*(Internal )?Server Error\\s*$', '^\\s*(Bad Gateway|Service Unavailable|Gateway Time-?out)\\s*$',
    '^\\s*HTTP \\d{3}\\s*$',
    // A library that would not load names files and URLs (2026-10-25: pdf.js on a sign link).
    '(Setting up )?fake worker( failed)?:?', 'Cannot load script', 'Could not load https?://', 'Invalid PDF structure',
    '(Missing|Invalid|UnexpectedResponse)(PDF)?Exception', 'Failed to fetch dynamically imported module',
    'Loading (CSS )?chunk \\d+ failed', 'importScripts'
  ].join('|'));
  var OBJ_RX = /\[object (Object|Response|Promise|Error)\]/;
  /* THE HOST'S FIREWALL ANSWERED, NOT US (2026-11-05, Anthony: a sign-in screenshot reading "Access denied by Imunify360
     bot-protection. IPs used for automation should be whitelisted"). Imunify360 on the server sits in front of the app and
     can block an IP for a while (reputation, a burst of requests); it answers in its own words, which the sign-in page
     printed. Those words are for server admins - the person gets what happened and what to do. */
  var FW_RX = /Imunify360|bot-protection|IPs used for automation|ModSecurity|Web Application Firewall|Access denied by [\w ]*(protection|firewall)|Request (was )?blocked by/i;
  var FW_MSG = 'Our security filter paused requests from your network for a moment. Wait a minute and try again, or switch between Wi\u2011Fi and mobile data. If it keeps happening, contact us.';
  // Only as an ERROR message: a table cell reading "null" is not an error to explain.
  var BARE_RX = /^\s*(undefined|null|NaN|error|\{\}|\[\])\s*$/i;

  function generic(status) {
    return (status && status >= 500)
      ? 'Something went wrong on our side and we have been told about it. Please try again in a moment.'
      : 'Something went wrong. Please try again — if it keeps happening, contact support from Help.';
  }
  function isRaw(text) {
    var t = String(text == null ? '' : text);
    return NET_RX.test(t) || CODE_RX.test(t) || OBJ_RX.test(t) || FW_RX.test(t);
  }
  function cleanError(text, status) {
    var t = (text && typeof text === 'object') ? (text.message || '') : String(text == null ? '' : text);
    if (!t.trim()) return generic(status);
    if (FW_RX.test(t)) return FW_MSG;
    if (NET_RX.test(t)) return message(new TypeError('x'));
    if (CODE_RX.test(t) || OBJ_RX.test(t) || BARE_RX.test(t)) return generic(status);
    return t;
  }

  /* Report each kind of raw text once per page, so the screen that printed it is found. */
  var _seen = {};
  function reportRaw(text) {
    // Never a report about a report: the audit log SHOWS these entries, and reporting what
    // it shows fed a loop that nested each entry inside the next (2026-10-22).
    if (/reached the screen and was replaced/.test(String(text))) return;
    var k = String(text).slice(0, 80);
    if (_seen[k] || Object.keys(_seen).length > 8) return;
    _seen[k] = 1;
    try {
      var headers = { 'Content-Type': 'application/json', 'Accept': 'application/json' };
      try { var tk = w.sessionStorage.getItem('kt_token') || w.localStorage.getItem('kt_token'); if (tk) headers.Authorization = 'Bearer ' + tk; } catch (e) {}
      _nativeFetch.call(w, API() + '/diag/client-error', {
        method: 'POST', headers: headers, keepalive: true,
        body: JSON.stringify({ status: 0, method: 'UI', kind: 'raw_error_shown',
          path: (location.pathname + location.hash).slice(0, 200), text: String(text).slice(0, 300) }),
      }).catch(function () {});
    } catch (e) {}
  }

  var SKIP = 'script,style,textarea,pre,code,[contenteditable="true"],[data-kt-raw]';
  /* Screens whose JOB is to show what went wrong, word for word: the audit log, email and
     server logs, Server health. Rewriting their text hid the evidence from the people
     reading it, and re-reported every logged error each time the log was opened.
     Tickets too: the system files server errors as tickets, titled with the error. */
  var LOG_SCREENS = /^#(audit-logs?|email-logs?|logs|server-health|error-logs?|tickets?|support)(?![\w-])/;
  function fixTextNode(n) {
    if (LOG_SCREENS.test(location.hash || '')) return;
    var v = n.nodeValue;
    if (!v || v.length > 600 || !isRaw(v)) return;
    var p = n.parentElement;
    if (!p || (p.closest && p.closest(SKIP))) return;
    var m = OBJ_RX.exec(v);
    if (m && !NET_RX.test(v) && !CODE_RX.test(v)) {         // a value that was never turned into words
      n.nodeValue = v.replace(new RegExp(OBJ_RX.source, 'g'), '—');
      reportRaw(v);
      return;
    }
    var hit = NET_RX.exec(v) || CODE_RX.exec(v);
    var at = hit ? hit.index : 0;
    // Keep a lead-in a screen wrote itself ("Could not load: "), drop "Error: " / "TypeError: ".
    var lead = v.slice(0, at).replace(/\b(Uncaught\s+)?(\w*Error|Exception)\s*:?\s*$/, '');
    n.nodeValue = lead + cleanError(v.slice(at));
    reportRaw(v);
  }
  function sweep(root) {
    if (!root) return;
    if (root.nodeType === 3) { fixTextNode(root); return; }
    if (root.nodeType !== 1) return;
    var all = root.textContent || '';
    if (all.length > 200000 || !isRaw(all)) return;           // one test for the whole subtree
    var tw = document.createTreeWalker(root, 4 /* SHOW_TEXT */, null);
    var list = [], n;
    while ((n = tw.nextNode())) list.push(n);
    list.forEach(fixTextNode);
  }
  function watch() {
    if (!w.MutationObserver || !document.body || w.__ktRawWatch) return;
    w.__ktRawWatch = true;
    sweep(document.body);
    new MutationObserver(function (muts) {
      for (var i = 0; i < muts.length; i++) {
        var m = muts[i];
        if (m.type === 'characterData') { fixTextNode(m.target); continue; }
        for (var j = 0; j < m.addedNodes.length; j++) sweep(m.addedNodes[j]);
      }
    }).observe(document.body, { childList: true, subtree: true, characterData: true });
  }
  if (document.body) watch(); else document.addEventListener('DOMContentLoaded', watch);

  // The browser's own boxes are not part of the page, so they are cleaned on the way in.
  ['alert', 'confirm'].forEach(function (fn) {
    var orig = w[fn];
    if (typeof orig !== 'function' || orig.__ktClean) return;
    var wrapped = function (msg) { return orig.call(w, isRaw(msg) ? cleanError(msg) : msg); };
    wrapped.__ktClean = true;
    w[fn] = wrapped;
  });
  w.__ktCleanError = cleanError;

  KT.net = { fetch: fetchRetry, message: message, flush: flush, clean: cleanError, isRaw: isRaw };
  // A page that loads at all has a connection: send anything left from last time.
  try { setTimeout(flush, 2500); } catch (e) {}
})(window);
