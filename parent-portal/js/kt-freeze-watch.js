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

  /* Did the page actually go off screen during the interval that just ended?

     This replaces an inference — comparing the gap against how long the page had been
     visible — which a single focus event was enough to falsify, and which therefore
     discarded every freeze anybody ever clicked on. Set only by signals that genuinely
     mean the page was suspended, read and cleared once per tick so it describes that
     interval and nothing else. */
  var awayDuringGap = d.hidden;
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
  function markAway() { awayDuringGap = true; }
  function resume() { last = Date.now(); }

  /* document.hidden is the signal that means what we need: the page is not being shown,
     which is when a browser suspends timers and a phone freezes the web view. Its
     transitions are recorded, not inferred. */
  try {
    d.addEventListener('visibilitychange', function () {
      if (d.hidden) { markAway(); }
      resume();
    });
  } catch (e) {}

  /* A bfcache restore is by definition a return from being away. */
  try { w.addEventListener('pageshow', function () { markAway(); resume(); }); } catch (e) {}

  /* FOCUS IS DELIBERATELY NOT A RESUME SIGNAL.

     It fires when somebody clicks a tab that never left the screen — which is precisely
     what a person does to a frozen interface, and resetting the clock there is what made
     this watchdog silent. Input focus says nothing about whether the page was suspended;
     visibilitychange above already covers the case where it was. */

  /* In a native web view visibilitychange is not guaranteed, so Capacitor reports the
     same fact. Both directions: going inactive is what a gap needs to be excused by. */
  try {
    var C = w.Capacitor;
    var App = C && C.Plugins && C.Plugins.App;
    if (App && App.addListener) {
      App.addListener('appStateChange', function (st) {
        if (st && st.isActive) { resume(); } else { markAway(); }
      });
    }
  } catch (e) {}

  /* ── A NATIVE DIALOG IS NOT A FREEZE ────────────────────────────────────
     window.alert / confirm / prompt block the main thread for exactly as long as the
     dialog is on screen. That is the browser doing what it was asked, not this app
     locking up — but the watchdog cannot see the dialog while it is open, because it
     is blocked too, so the gap left behind is indistinguishable from a real stall.

     That is ticket #67. An admin pressed "Delete user", the double-confirmation
     prompt() opened, she spent eleven seconds reading it and typing, and this
     watchdog filed a HIGH-PRIORITY "the interface stopped responding for 11.0s".
     Nothing was wrong. There are ~180 of these calls across 45 files, so left alone
     this files a false ticket every time anybody uses one — and a crash report that
     cries wolf is how people learn to ignore crash reports.

     Wrapping them records when one closed, which tick() then treats exactly as it
     treats a page that was off screen. The wrapper is transparent: same arguments,
     same return value, and it records even when the dialog throws. */
  var dialogClosedAt = 0;
  (function () {
    ['alert', 'confirm', 'prompt'].forEach(function (name) {
      var native = w[name];
      if (typeof native !== 'function') { return; }
      w[name] = function () {
        try {
          return native.apply(w, arguments);
        } finally {
          dialogClosedAt = Date.now();
        }
      };
    });
  })();

  /* ── Was the PAGE alive while the main thread was not? ──────────────────
     Every clock in this file is Date.now(), and Date.now() advances the same whether
     the thread was blocked or the device was asleep. document.hidden was meant to be
     the discriminator, but iOS often suspends a page on screen-lock WITHOUT firing
     visibilitychange — so a pocketed iPhone is indistinguishable from a 38-second
     freeze. That is ticket #47.

     A worker runs on its own thread: it keeps beating through a blocked main thread
     and stops when the device suspends. Beats sent during a block queue up and arrive
     on release still carrying the time they were SENT. */
  var beats = [];
  var beatOk = false;

  try {
    if (w.Worker && w.Blob && w.URL && w.URL.createObjectURL) {
      var hbSrc = 'setInterval(function(){postMessage(Date.now())},1000);';
      var hb = new w.Worker(w.URL.createObjectURL(new w.Blob([hbSrc], { type: 'application/javascript' })));
      hb.onmessage = function (e) {
        beats.push(Number(e.data) || 0);
        if (beats.length > 400) { beats.splice(0, beats.length - 400); }
      };
      beatOk = true;
    }
  } catch (e) { beatOk = false; }

  /* What fraction of [from, to] the worker can prove it was awake for. null when there
     is no heartbeat to ask. One beat per second, so an awake worker covers a gap almost
     entirely and a suspended one covers almost none of it. */
  function coverage(from, to) {
    if (!beatOk) { return null; }
    var span = to - from;
    if (span <= 0) { return null; }
    var inside = 0;
    for (var i = 0; i < beats.length; i++) {
      if (beats[i] > from && beats[i] < to) { inside++; }
    }
    return Math.min(1, (inside * 1000) / span);
  }

  function screenName() {
    try { return String(w.location.hash || '#').slice(0, 60); } catch (e) { return '#'; }
  }

  function tick() {
    var now = Date.now();
    var gap = now - last;
    last = now;

    /* Read and clear together: the flag describes the interval that just ended, so a
       page that was away ten minutes ago cannot excuse a freeze happening now. */
    var wasAway = awayDuringGap;
    awayDuringGap = false;

    /* Read and clear together, for the same reason wasAway is: a dialog somebody
       dismissed ten minutes ago must not be able to excuse a freeze happening now. */
    var hadDialog = dialogClosedAt >= now - gap;
    dialogClosedAt = 0;

    if (gap <= TICK_MS + FREEZE_MS) return;              // normal jitter
    if (wasAway) return;                                 // the page was off screen for part of it
    if (hadDialog) {                                     // alert/confirm/prompt held the thread
      try {
        if (w.KT && w.KT.crumb) {
          w.KT.crumb('freeze', 'ignored ' + (gap / 1000).toFixed(1) + 's on ' + screenName()
            + ' — a browser dialog was open');
        }
      } catch (e) {}
      return;
    }
    if (d.hidden) return;                                // not on screen now; not our story
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

    /* Ask the heartbeat before filing. Deferred: the queued beats and this timer are
       both waiting on the freed thread with no guaranteed order, so deciding now would
       read an empty queue and call every real freeze a sleeping device. */
    var gapFrom = now - gap;
    var gapTo = now;
    setTimeout(function () {
      var cov = coverage(gapFrom, gapTo);

      /* The worker slept too, so the DEVICE slept. A pocketed phone, not a freeze —
         dropped rather than filed, because a high-priority ticket every time somebody
         locks their screen teaches people to ignore crash tickets. */
      if (cov !== null && cov < 0.6) {
        try {
          if (w.KT && w.KT.crumb) {
            w.KT.crumb('freeze', 'ignored ' + secs + 's on ' + where + ' — device was asleep');
          }
        } catch (e) {}
        return;
      }

      if (reported[where]) { return; }
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
          /* What the visibility flag could not settle. iOS suspends a page on
             screen-lock without firing visibilitychange, so "visible" proved nothing;
             a worker on another thread does. */
          + (cov === null ? ''
              : 'A worker on another thread kept running for ' + Math.round(cov * 100)
                + '% of the gap, so the page was alive and the MAIN THREAD was blocked '
                + '— not a sleeping device.\n')
          + 'Detected by the freeze watchdog, not by an exception.\n'
          + (blocking
              ? 'Worst blocking tasks in the two minutes before this: ' + blocking + '\n'
              : 'No long task was recorded — either the browser does not report them '
                + '(Safari), or the block happened outside scripting.\n'),
          { quiet: true, longTasks: blocking }   // nothing broke visibly; a notice would be the only thing they saw
        );
      }
      } catch (e) {}
    }, 400);
  }

  try { setInterval(tick, TICK_MS); } catch (e) {}
})(window, document);
