/* ═══════════════════════════════════════════════════════════════════════════
   Coalesce the badge polls.  2026-08-29

   Measured from a month of production access logs while chasing "the system keeps
   freezing":

       /api/v1/chats/unread-count              645,388 requests   (318,363 empty)
       /api/v1/notifications/unread-count      160,506
       /api/v1/provider/team-threads/unread…    60,173
       /api/v1/tasks/mine                       55,833

   chats/unread-count alone is polled independently by SIX modules — kt-topbar,
   kt-mobilenav, kt-toasts, kt-urgent-alert, kt-parent-chrome and screen-chat — each
   with its own timer, all asking the same question. Half the answers are "nothing".

   Why that hurts: this account runs on CloudLinux LVE, which caps concurrent PHP
   processes per account. The logs carry 2,383 × HTTP 508 ("Resource Limit Is Reached")
   and 882 × 503, and among the requests refused were 742 hits on
   /care/logs/child/{id} — an educator opening a child's care log and being turned away.
   That refusal is what people describe as freezing. Badge polling was crowding out real
   work against a hard ceiling.

   What this does: one in-flight request per URL, and a short freshness window. Six
   callers asking the same question within the window get one answer, shared. No module
   is modified and no caller changes: they still call KT.Api.get and still receive the
   same JSON.

   Deliberately narrow — ONLY the read-only badge counts listed below. Anything that
   shows real content, and every write, goes straight through untouched. A stale badge
   for a few seconds is invisible; a stale roster would not be.
   ═══════════════════════════════════════════════════════════════════════════ */
(function (w) {
  'use strict';
  var KT = w.KT;
  if (!KT || !KT.Api || !KT.Api.get) { return; }
  if (w.__ktPollCoalesce) { return; }
  w.__ktPollCoalesce = true;

  /* Counts only. Each is a small integer a badge renders; none of them is content, and
     none is used to make a decision that a few seconds would change. */
  var COALESCE = [
    'chats/unread-count',
    'notifications/unread-count',
    'team-threads/unread-count',
    'tasks/mine/count',
    'agency/centre-term',      // configuration; changes about once a year
    'locale'                   // ditto
  ];

  /* Long enough to collapse six pollers running on 2-4s timers, short enough that a new
     message still lights the badge within a few seconds. */
  var FRESH_MS = 9000;

  var cache = {};     // url -> { at, value }
  var inflight = {};  // url -> promise

  function coalesced(url) {
    for (var i = 0; i < COALESCE.length; i++) {
      if (url.indexOf(COALESCE[i]) !== -1) { return true; }
    }
    return false;
  }

  var origGet = KT.Api.get.bind(KT.Api);

  KT.Api.get = function (url, opts) {
    if (typeof url !== 'string' || !coalesced(url)) {
      return origGet(url, opts);
    }

    /* ── HIDDEN GUARD ────────────────────────────────────────────────────────
       Nobody can read a badge on a hidden tab. kt-mobilenav, kt-parent-chrome and
       nav-additions-v14 have no visibility check of their own and keep polling in a
       backgrounded tab — which, on a phone left open all day, is a continuous drain on
       an account with a hard concurrency cap.

       The last known value is returned so callers render exactly what they rendered
       before; visibilitychange below flushes the cache, so the first poll after the tab
       is focused goes to the server and the badge is correct again immediately. */
    if (w.document.hidden) {
      var held = cache[url];
      return Promise.resolve(held ? held.value : null);
    }

    // A request for this exact URL is already on the wire — join it rather than
    // opening a second one. This alone removes most of the duplication, because the
    // six pollers fire within milliseconds of each other.
    if (inflight[url]) { return inflight[url]; }

    var hit = cache[url];
    if (hit && (Date.now() - hit.at) < FRESH_MS) {
      return Promise.resolve(hit.value);
    }

    var p = origGet(url, opts).then(function (res) {
      cache[url] = { at: Date.now(), value: res };
      delete inflight[url];
      return res;
    }).catch(function (e) {
      delete inflight[url];
      // Never serve a stale value as if it were fresh after a failure — let the caller
      // see the error and decide, exactly as before.
      throw e;
    });

    inflight[url] = p;
    return p;
  };

  /* A send, a read or a screen change should show its effect immediately, so anything
     that writes drops the cache. Cheap: the next poll refills it. */
  function invalidate() { cache = {}; }
  ['post', 'put', 'patch', 'delete', 'del'].forEach(function (m) {
    if (typeof KT.Api[m] !== 'function') { return; }
    var orig = KT.Api[m].bind(KT.Api);
    KT.Api[m] = function () {
      var r = orig.apply(null, arguments);
      if (r && r.then) { return r.then(function (v) { invalidate(); return v; }); }
      invalidate();
      return r;
    };
  });
  w.addEventListener('hashchange', invalidate);

  // Coming back to the tab should feel live, not cached.
  w.document.addEventListener('visibilitychange', function () {
    if (!w.document.hidden) { invalidate(); }
  });

  KT.pollCoalesce = {
    stats: function () { return { cached: Object.keys(cache).length, freshMs: FRESH_MS }; },
    flush: invalidate
  };
})(window);
