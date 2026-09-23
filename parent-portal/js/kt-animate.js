/* ═══════════════════════════════════════════════════════════════════
   KiddieTrac — kt-animate (2026-07-20)
   App-wide, additive polish for dashboard cards:
     • cards fade/slide up as they enter the viewport (staggered);
     • their big number counts up from 0 to the real value;
     • line/area SVG charts draw their stroke in.
   Purely presentational — it never changes values, only how they arrive.
   Driven by IntersectionObserver + a MutationObserver on #appMain so it
   self-applies to every SPA re-render. Honours prefers-reduced-motion.
   NOTE: it targets shared card classes only (.kt-kpi-tile, .kt-rw-card,
   .kt-lift, .stat-tile*). The platform "Business metrics" panel uses none
   of these, so it is deliberately left alone.
   ═══════════════════════════════════════════════════════════════════ */
(function (w) {
  'use strict';
  if (!w.document || !w.requestAnimationFrame) return;

  var reduce = false;
  try { reduce = w.matchMedia && w.matchMedia('(prefers-reduced-motion: reduce)').matches; } catch (e) {}

  if (!document.getElementById('kt-animate-style')) {
    var st = document.createElement('style');
    st.id = 'kt-animate-style';
    st.textContent =
      '.kt-anim-init{opacity:0;transform:translateY(12px);}' +
      '.kt-anim-in{opacity:1;transform:none;transition:opacity .5s ease, transform .55s cubic-bezier(.22,1,.36,1);}' +
      '.kt-kpi-tile{transition:transform .15s ease, box-shadow .15s ease;}' +
      '.kt-kpi-tile:hover{transform:translateY(-3px);box-shadow:0 14px 24px -14px rgba(15,23,42,.28);}' +
      // OVERFLOW SAFETY NET for every shared stat/KPI tile: let flex children shrink
      // and wrap long values (big money totals, live-poll growth) instead of pushing
      // past the tile edge. Value nodes carry data-kpi or a .value/.kt-rw-value class.
      '.kt-kpi-tile,.kt-rw-card,.kt-lift,.stat-tile,.stat-tile-v17{min-width:0;}' +
      '.kt-kpi-tile>*,.kt-rw-card>*,.stat-tile>*,.stat-tile-v17>*{min-width:0;}' +
      '.kt-kpi-tile [data-kpi],.kt-rw-card [data-kpi],.kt-rw-value,.stat-tile .value,.stat-tile-v17 .value,.kt-kpi-tile [data-kpi-sub]{overflow-wrap:anywhere;word-break:break-word;min-width:0;}' +
      '@media (prefers-reduced-motion: reduce){.kt-anim-init{opacity:1 !important;transform:none !important;}}';
    document.head.appendChild(st);
  }

  var CARD_SEL = '.kt-kpi-tile, .kt-rw-card, .kt-lift, .stat-tile, .stat-tile-v17';
  var NUM_RE = /^(\D*?)(\d[\d,]*(?:\.\d+)?)(\D*)$/;

  /* A BACKGROUND REFRESH IS NOT AN ARRIVAL. (2026-09-21)

     Everything below decides what to do with a card it has not seen before, and after a
     silent refresh that is EVERY card - the shell clears #appMain and builds new nodes,
     and the __ktSeen/__ktRevealed/__ktCounted guards live on the nodes that were just
     thrown away. So the full entrance replayed on a screen somebody was reading: cards
     fading up from nothing, numbers counting from zero, chart strokes redrawing. Every
     45s, and again after every write, in every open tab.

     The shell publishes a window while a silent render is in flight (see _ktSilent in
     app-v2-shell.js). Inside it the cards are shown as they are - already there, because
     as far as the reader is concerned they never left. */
  /* WHAT THIS CARD SAID LAST TIME.

     Keyed by the card's wording with every digit, currency mark and percent stripped out
     — "TOTAL ENROLLED / of capacity" — which survives a re-render and survives the value
     changing, but still tells two cards apart. Kept on window so it outlives the nodes,
     which is the whole point: the guards that used to decide this lived ON the nodes and
     died with them. */
  var LAST = (w.__ktCardValues = w.__ktCardValues || {});

  function signatureOf(card) {
    try {
      return (card.textContent || '')
        .replace(/[\d.,$%]/g, ' ')
        .replace(/\s+/g, ' ')
        .trim()
        .slice(0, 70);
    } catch (e) { return ''; }
  }

  function numberIn(el) {
    if (!el) { return null; }
    var m = (el.textContent || '').trim().match(NUM_RE);
    if (!m) { return null; }
    var n = parseFloat(m[2].replace(/,/g, ''));

    return isFinite(n) ? n : null;
  }

  function silentNow() {
    try { return Date.now() < (w.__ktSilentUntil || 0); } catch (e) { return false; }
  }

  function easeOutCubic(t) { return 1 - Math.pow(1 - t, 3); }

  function fmt(n, decimals, comma) {
    var s = decimals > 0 ? n.toFixed(decimals) : String(Math.round(n));
    if (comma) {
      var parts = s.split('.');
      parts[0] = parts[0].replace(/\B(?=(\d{3})+(?!\d))/g, ',');
      s = parts.join('.');
    }
    return s;
  }

  function countUp(el, from) {
    if (!el || el.__ktCounted) return;
    var raw = (el.textContent || '').trim();
    var m = raw.match(NUM_RE);
    if (!m) return;
    var prefix = m[1], numStr = m[2], suffix = m[3];
    var target = parseFloat(numStr.replace(/,/g, ''));
    if (!isFinite(target)) return;
    el.__ktCounted = true;
    if (reduce || target === 0) return;                 // leave the real text as-is

    /* NEVER ANIMATE WHERE NOBODY IS WATCHING. (2026-09-21)

       The first thing this used to do was overwrite the real figure with 0 and then rely
       on rAF to walk it back up - and rAF does not run on a hidden tab or a backgrounded
       APK. So a card could be left reading "0 TOTAL ENROLLED" for as long as the tab
       stayed in the background, which is not a cosmetic bug: it is the wrong number, on
       a dashboard somebody makes decisions from. Leave the true value alone instead. */
    if (document.hidden) { return; }

    var comma = /,/.test(numStr);
    var decimals = (numStr.split('.')[1] || '').length;
    var start0 = (typeof from === 'number' && isFinite(from)) ? from : 0;
    if (start0 === target) { return; }

    var dur = 900, start = null, done = false;
    var settle = function () {
      if (done) { return; }
      done = true;
      el.textContent = prefix + fmt(target, decimals, comma) + suffix;
    };

    el.textContent = prefix + fmt(start0, decimals, comma) + suffix;

    function step(ts) {
      if (done) { return; }
      if (start === null) start = ts;
      var p = Math.min((ts - start) / dur, 1);
      el.textContent = prefix + fmt(start0 + (target - start0) * easeOutCubic(p), decimals, comma) + suffix;
      if (p < 1) { requestAnimationFrame(step); } else { settle(); }
    }
    requestAnimationFrame(step);

    /* And if the tab is hidden PART WAY through - the reader switches app mid-roll -
       rAF stops and the walk freezes at whatever it had reached. This lands the true
       figure regardless. */
    setTimeout(settle, dur + 400);
  }

  // The card's headline number = the largest-font leaf whose text is numeric.
  function valueNodeOf(card) {
    var best = null, bestSize = -1;
    var nodes = card.querySelectorAll('*');
    for (var i = 0; i < nodes.length; i++) {
      var n = nodes[i];
      if (n.children.length) continue;                  // leaf only
      var txt = (n.textContent || '').trim();
      if (!NUM_RE.test(txt)) continue;
      var fs = 0; try { fs = parseFloat(getComputedStyle(n).fontSize) || 0; } catch (e) {}
      if (fs > bestSize) { bestSize = fs; best = n; }
    }
    return best;
  }

  function drawCharts(card) {
    var paths = card.querySelectorAll('svg path');
    for (var i = 0; i < paths.length; i++) {
      (function (p) {
        if (p.__ktDrawn) return;
        var len = 0; try { len = p.getTotalLength(); } catch (e) { return; }
        var stroke = ''; try { stroke = getComputedStyle(p).stroke; } catch (e) {}
        if (!len || !stroke || stroke === 'none') return;
        p.__ktDrawn = true;
        if (reduce) return;
        p.style.transition = 'none';
        p.style.strokeDasharray = len;
        p.style.strokeDashoffset = len;
        p.getBoundingClientRect();                        // force reflow
        p.style.transition = 'stroke-dashoffset 1s ease';
        p.style.strokeDashoffset = '0';
      })(paths[i]);
    }
  }

  function reveal(card, instant) {
    if (card.__ktRevealed) return;
    card.__ktRevealed = true;
    card.classList.remove('kt-anim-init');

    var v = valueNodeOf(card);

    if (instant) {
      /* No kt-anim-in: that class carries the 0.5s fade-and-slide, and playing it on a
         card that never went away is the churn this work removes. The chart guards are
         stamped WITHOUT running for the same reason.

         BUT THE NUMBER IS DIFFERENT. (2026-09-21)

         Anthony: "the animation with the stats cards where the numbers rolled is no
         longer working." Suppressing it wholesale was too blunt — and it went missing in
         the most common path of all, because kt-auto-refresh fires a silent refresh every
         time the app is brought back to the front, which is exactly when somebody is
         looking at it.

         So: roll the number when it has actually CHANGED, from the old figure to the new
         one, and hold perfectly still when it has not. A re-render is not news and should
         look like nothing happened; 34 children becoming 35 IS news, and the movement is
         what makes it noticeable. Better than the original, which rolled up from zero
         whether or not anything had changed. */
      var paths = card.querySelectorAll('svg path');
      for (var i = 0; i < paths.length; i++) { paths[i].__ktDrawn = true; }

      var sig = signatureOf(card);
      var now = numberIn(v);
      var was = sig ? LAST[sig] : undefined;

      if (v && sig && typeof was === 'number' && now !== null && was !== now) {
        countUp(v, was);
      } else if (v) {
        v.__ktCounted = true;
      }
      if (sig && now !== null) { LAST[sig] = now; }

      return;
    }

    card.classList.add('kt-anim-in');
    var sig0 = signatureOf(card);
    var n0 = numberIn(v);
    countUp(v, 0);
    if (sig0 && n0 !== null) { LAST[sig0] = n0; }
    drawCharts(card);
  }

  var io = ('IntersectionObserver' in w) ? new IntersectionObserver(function (entries) {
    entries.forEach(function (e) { if (e.isIntersecting) { reveal(e.target); io.unobserve(e.target); } });
  }, { threshold: 0.12 }) : null;

  function scan() {
    var cards = document.querySelectorAll(CARD_SEL);
    /* Asked ONCE for the whole pass, not per card: a render that straddles the end of the
       window would otherwise animate the tail of its own cards and not the rest, which
       looks worse than either. */
    var instant = silentNow();

    for (var i = 0; i < cards.length; i++) {
      var c = cards[i];
      if (c.__ktSeen) continue;
      c.__ktSeen = true;
      if (instant) {
        /* Deliberately before the kt-anim-init branch: staging the card at opacity 0 and
           revealing it in the same frame is what produced the flash this removes. */
        reveal(c, true);
      } else if (io && !reduce) {
        c.classList.add('kt-anim-init');
        io.observe(c);
        // Safety net: never leave a card hidden if IO somehow doesn't fire.
        (function (card) { setTimeout(function () { if (!card.__ktRevealed) reveal(card); }, 2500); })(c);
      } else {
        reveal(c);
      }
    }
  }

  var scheduled = false;
  function schedule() {
    if (scheduled) return;
    scheduled = true;

    /* rAF DOES NOT FIRE ON A BACKGROUNDED TAB, and this used to wait for it forever.
       (2026-09-21)

       `scheduled` was raised and only ever lowered inside the rAF callback, so a single
       mutation arriving while the tab was hidden - or while the APK was in the background,
       which is most of its life - left the flag up permanently and every later scan was
       dead for the rest of the page's life. Found by measurement, not by reading: a live
       dashboard had THIRTEEN cards on screen and not one of them carried __ktSeen.

       Benign-looking, because an unscanned card is simply a card that never animates. But
       it also means the safety net below never gets attached, so anything that DID stage a
       card at opacity 0 before the wedge had nothing left to reveal it.

       Same fix as kt-sweep-bus and the shell's cover: race rAF against a timer and take
       whichever arrives first. See [[kiddietrac-sweep-bus-wedge]]. */
    var ran = false;
    var go = function () {
      if (ran) return;
      ran = true;
      scheduled = false;
      scan();
    };

    (w.requestAnimationFrame || function (f) { setTimeout(f, 16); })(go);
    setTimeout(go, 250);
  }

  function boot() {
    scan();
    try {
      var main = document.getElementById('appMain') || document.body;
      /* Re-binds when the shell swaps #appMain (kt:main-swapped); a MutationObserver follows a NODE, and this one used to die silently at the first render. */
      if (w.KT && KT.observeMain) { KT.observeMain(schedule, { childList: true, subtree: true }); }
      else { new MutationObserver(schedule).observe(main, { childList: true, subtree: true }); }
    } catch (e) {}
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot);
  else boot();
})(window);
