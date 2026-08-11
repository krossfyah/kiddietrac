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

  function countUp(el) {
    if (!el || el.__ktCounted) return;
    var raw = (el.textContent || '').trim();
    var m = raw.match(NUM_RE);
    if (!m) return;
    var prefix = m[1], numStr = m[2], suffix = m[3];
    var target = parseFloat(numStr.replace(/,/g, ''));
    if (!isFinite(target)) return;
    el.__ktCounted = true;
    if (reduce || target === 0) return;                 // leave the real text as-is
    var comma = /,/.test(numStr);
    var decimals = (numStr.split('.')[1] || '').length;
    var dur = 900, start = null;
    el.textContent = prefix + fmt(0, decimals, comma) + suffix;
    function step(ts) {
      if (start === null) start = ts;
      var p = Math.min((ts - start) / dur, 1);
      el.textContent = prefix + fmt(target * easeOutCubic(p), decimals, comma) + suffix;
      if (p < 1) requestAnimationFrame(step);
      else el.textContent = prefix + fmt(target, decimals, comma) + suffix;
    }
    requestAnimationFrame(step);
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

  function reveal(card) {
    if (card.__ktRevealed) return;
    card.__ktRevealed = true;
    card.classList.remove('kt-anim-init');
    card.classList.add('kt-anim-in');
    countUp(valueNodeOf(card));
    drawCharts(card);
  }

  var io = ('IntersectionObserver' in w) ? new IntersectionObserver(function (entries) {
    entries.forEach(function (e) { if (e.isIntersecting) { reveal(e.target); io.unobserve(e.target); } });
  }, { threshold: 0.12 }) : null;

  function scan() {
    var cards = document.querySelectorAll(CARD_SEL);
    for (var i = 0; i < cards.length; i++) {
      var c = cards[i];
      if (c.__ktSeen) continue;
      c.__ktSeen = true;
      if (io && !reduce) {
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
    requestAnimationFrame(function () { scheduled = false; scan(); });
  }

  function boot() {
    scan();
    try {
      var main = document.getElementById('appMain') || document.body;
      new MutationObserver(schedule).observe(main, { childList: true, subtree: true });
    } catch (e) {}
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot);
  else boot();
})(window);
