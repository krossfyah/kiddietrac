/* ═══════════════════════════════════════════════════════════════════
   KiddieTrac — freeze watchdog (2026-08-31).

   "The system is freezing" was, until now, only ever a feeling somebody reported.
   TrackSlowRequests measures the SERVER side; this measures the side people actually
   experience — the main thread being blocked, so nothing scrolls, nothing taps, and
   the app looks dead.

   A heartbeat every second. If a tick arrives late, the thread was busy for that long
   and the interface was frozen for exactly that long.

   THE HARD PART IS NOT DETECTING GAPS, IT IS IGNORING THE INNOCENT ONES. A gap means
   nothing on its own: browsers throttle timers in a background tab, a phone suspends
   a backgrounded app entirely, and a laptop lid closes for an hour. Reporting those
   would bury the real freezes in noise from people simply not looking at the screen.
   So a gap counts only when the page was visible for the whole of it, and only when
   it is short enough to be a block rather than a sleep.
   ═══════════════════════════════════════════════════════════════════ */
(function (w, d) {
  'use strict';
  if (w.__ktFreezeWatch) return; w.__ktFreezeWatch = true;

  var TICK_MS    = 1000;      // heartbeat
  var FREEZE_MS  = 5000;      // a gap this long is a stall worth recording
  var REPORT_MS  = 8000;      // this long is worth a ticket
  /* Beyond this it is a suspended app or a sleeping device, not a freeze. Deliberately
     not generous: on a phone the common gap is a backgrounded web view, and anything
     approaching half a minute is far more likely to be that than a main thread genuinely
     blocked — a freeze that long would have to survive the watchdog itself being frozen. */
  var SLEEP_MS   = 25000;
  var MAX_REPORTS = 3;        // per session — a struggling device must not spam

  var last = Date.now();
  var visibleSince = d.hidden ? 0 : Date.now();
  var reports = 0;
  var reported = {};          // one report per screen per session

  /* Any visibility change resets the clock. A tab coming back to the foreground has a
     huge gap behind it that says nothing about our code, and a tab going away is about
     to accumulate one.

     ON A PHONE THIS IS THE WHOLE BALLGAME. The APK and the iOS build load this very
     page (capacitor.config.json points at dashboard.html), and a backgrounded app has
     its web view suspended outright — every return from the home screen arrives with a
     gap behind it that looks exactly like a freeze. Getting this wrong does not mean
     missing a freeze, it means filing a ticket every time somebody takes a phone call.

     So the clock is reset from every signal a resume can arrive on, not just one:
     visibilitychange, pageshow (bfcache restore), window focus, and Capacitor's
     appStateChange, which is the only one guaranteed to fire in a native web view.
     Belt and braces on purpose — a missed reset is a false accusation. */
  function resume() {
    last = Date.now();
    visibleSince = d.hidden ? 0 : Date.now();
  }
  try { d.addEventListener('visibilitychange', resume); } catch (e) {}
  try { w.addEventListener('pageshow', resume); } catch (e) {}
  try { w.addEventListener('focus', resume); } catch (e) {}
  try {
    var C = w.Capacitor;
    var App = C && C.Plugins && C.Plugins.App;
    if (App && App.addListener) {
      App.addListener('appStateChange', function (st) { if (st && st.isActive) resume(); });
    }
  } catch (e) {}

  function screenName() {
    try { return String(w.location.hash || '#').slice(0, 60); } catch (e) { return '#'; }
  }

  function tick() {
    var now = Date.now();
    var gap = now - last;
    last = now;

    if (gap <= TICK_MS + FREEZE_MS) return;              // normal jitter
    if (d.hidden || !visibleSince) return;               // not on screen; not our story
    if (now - visibleSince < gap) return;                // it was hidden during the gap
    if (gap > SLEEP_MS) return;                          // the device slept

    var secs = (gap / 1000).toFixed(1);
    var where = screenName();

    // Always leave a trail: if a crash follows, the report now carries the fact that
    // the interface had already locked up beforehand.
    try { if (w.KT && w.KT.crumb) w.KT.crumb('freeze', secs + 's on ' + where); } catch (e) {}

    if (gap < REPORT_MS) return;
    if (reports >= MAX_REPORTS) return;
    if (reported[where]) return;                         // one per screen is the signal
    reported[where] = 1;
    reports++;

    /* Reported through the crash pipe, so a freeze arrives with the same context a
       crash does — user, device, route, breadcrumbs — and de-duplicates into one
       ticket per screen rather than one per occurrence. Shaped like a trace because
       that first line is what the server uses as the ticket subject. */
    try {
      if (w.KT && w.KT.reportProblem) {
        w.KT.reportProblem(
          new Date().toISOString() + '  UI FROZE  The interface stopped responding for '
          + secs + 's on ' + where + '\n'
          + 'No error was thrown — the main thread was blocked, so nothing on screen '
          + 'responded for that time.\n'
          + 'Detected by the freeze watchdog, not by an exception.',
          { quiet: true }   // nothing broke visibly; a notice would be the only thing they saw
        );
      }
    } catch (e) {}
  }

  try { setInterval(tick, TICK_MS); } catch (e) {}
})(window, document);
