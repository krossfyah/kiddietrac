/* ═══════════════════════════════════════════════════════════════════
   KiddieTrac — the pulse (2026-08-13)

   ONE request that asks "has anything changed?", so a change made by ANOTHER
   user appears on your screen without you reloading.

   kt-live.js already refreshes on writes made in this browser. It cannot know
   about anyone else's: an educator checking a child in, a parent signing a
   form, a director editing a room. That is what this closes.

   Why one poller and not more: the portal already polls a great deal — three
   badge pollers in the top bar every 15s, message toasts every 12s,
   announcements every 30s — twenty to thirty requests a minute per open
   browser, none of which refreshed the screen you were actually looking at.
   /pulse returns cheap change markers (the highest id in each table that
   matters, scoped to what you can see). When one moves, the live-refresh bus
   re-renders — through the same safety rules, so it still never interrupts a
   dialog or someone typing.

   Politeness rules, because a poller that misbehaves is worse than none:
     • paused entirely while the tab is hidden (battery, and the APK)
     • paused when signed out
     • the FIRST response only records a baseline — it never triggers a refresh
     • failures back off to a minute, and recover on success
   ═══════════════════════════════════════════════════════════════════ */
(function (w) {
  'use strict';
  var d = w.document;

  var BASE_MS = 12000;        // while the tab is visible and healthy
  var MAX_MS = 60000;         // ceiling after repeated failures
  var timer = null;
  var delay = BASE_MS;
  var last = null;            // the marks we last saw; null until the baseline lands
  var running = false;

  function token() {
    try { return sessionStorage.getItem('kt_token') || localStorage.getItem('kt_token'); }
    catch (e) { return null; }
  }

  function stop() {
    if (timer) { clearTimeout(timer); timer = null; }
  }

  function schedule(ms) {
    stop();
    timer = setTimeout(tick, ms);
  }

  async function tick() {
    timer = null;
    if (d.hidden || !token() || !w.KT || !w.KT.Api) { schedule(BASE_MS); return; }
    if (running) { schedule(delay); return; }
    running = true;
    try {
      var res = await w.KT.Api.get('/pulse');
      var marks = (res && res.marks) || {};
      delay = BASE_MS;                                  // healthy again

      if (last === null) {
        last = marks;                                   // baseline only — never refresh on the first look
      } else {
        var changed = [];
        Object.keys(marks).forEach(function (k) {
          if (String(marks[k]) !== String(last[k])) changed.push(k);
        });
        if (changed.length) {
          last = marks;
          // Hand it to the same bus writes use, so a remote change and a local
          // one behave identically — deferred while a dialog is open or the user
          // is typing, coalesced, and rate-limited.
          try { if (w.KT.dataChanged) w.KT.dataChanged('pulse:' + changed.join(',')); } catch (e) {}
        }
      }
    } catch (e) {
      // A signed-out or unreachable API must not turn into a hot loop.
      delay = Math.min(MAX_MS, Math.round(delay * 2));
    } finally {
      running = false;
      schedule(delay);
    }
  }

  // Only run while the tab is actually being looked at.
  d.addEventListener('visibilitychange', function () {
    if (d.hidden) { stop(); return; }
    last = last;                 // keep the baseline; a hidden tab missed nothing it needs to replay
    schedule(1500);              // check soon after coming back
  });

  function start() {
    if (!token()) { schedule(BASE_MS); return; }   // keep checking; the user may sign in
    schedule(2500);                                 // let the first screen render first
  }

  if (d.readyState === 'loading') d.addEventListener('DOMContentLoaded', start);
  else start();

  w.KT = w.KT || {};
  w.KT.pulse = {
    now: function () { stop(); tick(); },
    state: function () { return { last: last, delay: delay }; },
    stop: stop,
  };
})(window);
