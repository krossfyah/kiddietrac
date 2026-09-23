/* ═══════════════════════════════════════════════════════════════════
   KiddieTrac — how far this person has read, on the ACCOUNT rather than the device.

   The bell badge, the what's-new dot and the sales-chat badge each kept their read
   position in localStorage. That is per-browser: clearing the bell on a laptop left it lit
   on the phone, and a new device, a private window or a cleared cache began by claiming
   everything was unread.

   The server is now the truth (user_ui_markers). localStorage stays as a CACHE, and that
   is the whole design:

     · a badge paints instantly from the cached value, so nothing flashes on boot
     · the server's answer arrives a moment later and corrects it if another device moved on
     · a failed request changes nothing — "could not load" must never read as "nothing seen",
       which is precisely how the bell once came back as a hard 40

   Writes go to both, and the server refuses to move a marker backwards, so two devices
   racing cannot resurrect a badge somebody has already cleared.

   Public:
     KT.markers.get(key)            -> the best value known right now (never null; '' if none)
     KT.markers.set(key, value)     -> move forward, locally and on the server
     KT.markers.onSync(fn)          -> called once the server's values have landed
   (Anthony, 2026-09-10)
   ═══════════════════════════════════════════════════════════════════ */
(function (w) {
  'use strict';
  var KT = (w.KT = w.KT || {});
  if (KT.markers) { return; }

  var CACHE = {};        // key -> value, seeded from localStorage, corrected by the server
  var synced = false;
  var waiting = [];

  function lsGet(k) {
    try { return localStorage.getItem(k) || ''; } catch (e) { return ''; }
  }
  function lsSet(k, v) {
    try { localStorage.setItem(k, String(v)); } catch (e) { /* private mode */ }
  }

  /* Forward, by the same rule the server applies: numeric when both sides are numbers
     (a message id), lexical otherwise (ISO dates and timestamps sort that way). The two
     have to agree or the client will keep re-sending a write the server keeps refusing. */
  function isForward(current, next) {
    if (!current) { return true; }
    if (!next) { return false; }
    var a = Number(current), b = Number(next);
    if (isFinite(a) && isFinite(b) && String(current).trim() !== '' && String(next).trim() !== '') {
      return b > a;
    }
    return String(next) > String(current);
  }

  function token() {
    try { return sessionStorage.getItem('kt_token') || localStorage.getItem('kt_token') || ''; }
    catch (e) { return ''; }
  }

  KT.markers = {
    /** The best value known right now — cache first, then whatever localStorage holds. */
    get: function (key) {
      if (CACHE[key] !== undefined) { return CACHE[key]; }
      var v = lsGet(key);
      CACHE[key] = v;
      return v;
    },

    /**
     * Move a marker forward.
     *
     * Written locally first so the badge clears immediately — the person pressed
     * something, and waiting on a round trip to acknowledge it is how an interface feels
     * broken. The server call is best effort; if it fails the local value stands and the
     * next sync will reconcile.
     */
    set: function (key, value) {
      if (!value) { return; }
      var cur = KT.markers.get(key);
      if (!isForward(cur, value)) { return; }

      CACHE[key] = String(value);
      lsSet(key, value);

      if (!token()) { return; }
      try {
        if (KT.Api && KT.Api.put) {
          KT.Api.put('/me/markers', { key: key, value: String(value) }).then(function (r) {
            /* The server is the authority on where the marker ended up: another device
               may already have been further on. Taking its answer keeps the two in step
               instead of leaving this browser convinced it won. */
            if (r && r.value) {
              CACHE[key] = String(r.value);
              lsSet(key, r.value);
            }
          }).catch(function () { /* offline, or a 4xx — the local value stands */ });
        }
      } catch (e) {}
    },

    /** Called once the server's values have landed (or immediately, if they already have). */
    onSync: function (fn) {
      if (typeof fn !== 'function') { return; }
      if (synced) { fn(CACHE); return; }
      waiting.push(fn);
    },

    /** Pull the account's markers and reconcile. Safe to call again — it only moves forward. */
    sync: function () {
      if (!token() || !(KT.Api && KT.Api.get)) { return; }
      KT.Api.get('/me/markers').then(function (r) {
        var m = (r && r.markers) || {};
        Object.keys(m).forEach(function (k) {
          var server = m[k];
          if (!server) { return; }
          var local = KT.markers.get(k);
          if (isForward(local, server)) {
            /* Another device read further. Adopt it — that is the point of this. */
            CACHE[k] = String(server);
            lsSet(k, server);
          } else if (local && isForward(server, local)) {
            /* THIS device is ahead — which happens after a spell offline. Push, so the
               position is not lost the next time this browser's cache is cleared. */
            KT.markers.set(k, local);
          }
        });
        synced = true;
        var fns = waiting.splice(0);
        fns.forEach(function (fn) { try { fn(CACHE); } catch (e) {} });
      }).catch(function () {
        /* A FAILED LOAD IS NOT A RESET. Callers are released with whatever the cache
           holds, so a badge renders from the last known position rather than from
           nothing — the difference between a quiet bell and a sudden "40 unread". */
        synced = true;
        var fns = waiting.splice(0);
        fns.forEach(function (fn) { try { fn(CACHE); } catch (e) {} });
      });
    },
  };

  /* Seed from localStorage immediately so the first paint has something true-ish, then
     ask the server. Deferred a beat: KT.Api and the token are set up by app.js, and this
     file has no business racing it. */
  ['kt_notif_seen', 'kt_whatsnew_seen', 'kt_sales_chat_seen'].forEach(function (k) {
    CACHE[k] = lsGet(k);
  });
  setTimeout(function () { KT.markers.sync(); }, 1200);

  /* Coming back to a tab that has been open for hours is exactly when another device has
     moved on. Cheap: one request, and only when the page is actually being looked at. */
  try {
    document.addEventListener('visibilitychange', function () {
      if (!document.hidden) { KT.markers.sync(); }
    });
  } catch (e) {}
})(window);
