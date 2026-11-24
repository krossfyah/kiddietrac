/* ONE CREDENTIAL REQUEST AT A TIME (2026-10-21) - window.KT_credExclusive
   Anthony: "biometric says a request is pending - something is broken here". The browser
   allows one WebAuthn request at a time. The sign-in page keeps a passkey-autofill request
   (mediation: 'conditional') waiting in the background, so the biometric unlock's own
   navigator.credentials.get failed with "A request is already pending". Every request now
   goes through here: an explicit request first stops the background autofill and waits
   for it to let go, requests never overlap, and a collision is retried once, silently.
   The autofill comes back on its own renew timer (index.html, under 2 minutes).
   window.KT_credExclusive(fn) runs anything else that must not overlap - the native fingerprint
   prompt (kt-biometric.js) uses it. Loaded first on the sign-in page and the dashboard. */
(function (w) {
  'use strict';
  var C = w.navigator && w.navigator.credentials;
  // Its own global: app.js replaces window.KT wholesale.
  if (!C || C.__ktMutex || typeof C.get !== 'function') {
    if (!w.KT_credExclusive) w.KT_credExclusive = function (fn) { return Promise.resolve().then(fn); };
    return;
  }
  var oget = C.get.bind(C);
  var ocreate = typeof C.create === 'function' ? C.create.bind(C) : null;
  var chain = Promise.resolve();
  var cond = null;                                    // { ac, p } - the background autofill
  function wait(ms) { return new Promise(function (r) { setTimeout(r, ms); }); }
  function collision(e) { return !!e && /pending|in progress|already/i.test(String(e.message || '')); }
  function stopAutofill() {
    if (!cond) return Promise.resolve();
    var c = cond; cond = null;
    try { c.ac.abort(); } catch (e) {}
    return c.p.then(function () {}, function () {}).then(function () { return wait(60); });
  }
  function exclusive(fn) {
    var run = function () {
      return stopAutofill().then(fn).catch(function (e) {
        if (!collision(e)) throw e;
        return stopAutofill().then(function () { return wait(400); }).then(fn);   // once, silently
      });
    };
    var p = chain.then(run, run);
    chain = p.then(function () {}, function () {});
    return p;
  }
  C.get = function (opts) {
    if (opts && opts.mediation === 'conditional') {
      // Our own controller, chained to the caller's, so an explicit request can stop it.
      var ac = new AbortController();
      var s = opts.signal;
      if (s) { if (s.aborted) ac.abort(); else s.addEventListener('abort', function () { try { ac.abort(); } catch (e) {} }); }
      if (cond) { try { cond.ac.abort(); } catch (e) {} }
      var o = {}; for (var k in opts) o[k] = opts[k];
      o.signal = ac.signal;
      var me = { ac: ac, p: null };
      me.p = chain.then(function () {
        if (ac.signal.aborted) throw new DOMException('The operation was aborted.', 'AbortError');
        return oget(o);
      });
      cond = me;
      var done = function () { if (cond === me) cond = null; };
      me.p.then(done, done);
      return me.p;
    }
    return exclusive(function () { return oget(opts); });
  };
  if (ocreate) C.create = function (opts) { return exclusive(function () { return ocreate(opts); }); };
  C.__ktMutex = true;
  w.KT_credExclusive = exclusive;
})(window);
