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
  /* Above this a stall is reported as a LONG STALL rather than dropped. It used to
     be a hard 25s ceiling that returned early, which discarded every serious freeze:
     a tab frozen for minutes produced a gap far over it and was written off as a
     sleeping device, which is why this watchdog had filed nothing while freezes were
     being reported by hand. The visibility guard below is the real discriminator --
     the page must have been on screen for the entire gap -- so the ceiling now only
     decides how a stall is LABELLED. */
  var LONG_MS    = 25000;
  /* Past this a closed lid or a suspended device genuinely is the better explanation
     than a main thread blocked that long, so it is still dropped. */
  var SLEEP_MS   = 600000;
  var MAX_REPORTS = 3;        // per session — a struggling device must not spam

  /* WHAT was blocking, not just that something was.

     Every task over 50ms is reported here with an attribution naming the script
     responsible, and a freeze is simply a very long one (or a run of them). Holding
     the worst dozen means the report can name the file instead of leaving whoever
     reads the ticket to guess. Anything under 200ms is ordinary rendering and is
     ignored, so this costs nothing on a healthy session. */
  var BLOCK_MS = 200;
  var KEEP = 12;
  var blockers = [];

  function noteTask(e) {
    var who = '';
    try {
      var a = (e.attribution || [])[0];
      if (a) {
        who = String(a.containerType || a.name || '');
        var src = String(a.containerSrc || a.containerName || '');
        if (src) { who += ' ' + src.split('/').pop(); }
      }
    } catch (x) {}
    blockers.push({ ms: Math.round(e.duration), who: who, at: Date.now() });
    if (blockers.length > KEEP) { blockers.shift(); }
  }

  try {
    if (typeof PerformanceObserver === 'function') {
      new PerformanceObserver(function (list) {
        var es = list.getEntries();
        for (var i = 0; i < es.length; i++) {
          if (es[i].duration >= BLOCK_MS) { noteTask(es[i]); }
        }
      }).observe({ entryTypes: ['longtask'] });
    }
  } catch (e) { /* not supported (Safari) — the report is just less specific */ }

  /* The worst offenders from the last two minutes, newest first, as one line. */
  function blockerSummary() {
    try {
      var cut = Date.now() - 120000;
      var recent = blockers.filter(function (b) { return b.at >= cut; })
        .sort(function (a, b) { return b.ms - a.ms; }).slice(0, 5);
      if (! recent.length) { return ''; }
      return recent.map(function (b) {
        return b.ms + 'ms' + (b.who ? ' ' + b.who : '');
      }).join(', ');
    } catch (x) { return ''; }
  }

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
    if (gap > SLEEP_MS) return;                          // beyond plausible: a sleeping device

    var secs = (gap / 1000).toFixed(1);
    var where = screenName();
    /* A stall past the ceiling is still real enough to record -- it is just less
       certain, so it is labelled and counted separately rather than thrown away. */
    var isLong = gap > LONG_MS;

    // Always leave a trail: if a crash follows, the report now carries the fact that
    // the interface had already locked up beforehand.
    var blocking = blockerSummary();
    try { if (w.KT && w.KT.crumb) w.KT.crumb('freeze', secs + 's on ' + where + (blocking ? ' [' + blocking + ']' : '')); } catch (e) {}

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
          new Date().toISOString() + (isLong ? '  UI LONG STALL  ' : '  UI FROZE  ')
          + 'The interface stopped responding for ' + secs + 's on ' + where + '\n'
          + 'No error was thrown — the main thread was blocked, so nothing on screen '
          + 'responded for that time.\n'
          + (isLong
              ? 'Longer than ' + (LONG_MS / 1000) + 's, so a suspended device cannot be ruled '
                + 'out — but the page reported itself visible for the whole gap.\n'
              : '')
          + 'Detected by the freeze watchdog, not by an exception.\n'
          + (blocking
              ? 'Worst blocking tasks in the two minutes before this: ' + blocking + '\n'
              : 'No long task was recorded — either the browser does not report them '
                + '(Safari), or the block happened outside scripting.\n'),
          { quiet: true, longTasks: blocking }   // nothing broke visibly; a notice would be the only thing they saw
        );
      }
    } catch (e) {}
  }

  try { setInterval(tick, TICK_MS); } catch (e) {}
})(window, document);
