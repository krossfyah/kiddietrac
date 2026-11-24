/* ═══════════════════════════════════════════════════════════════════
   KiddieTrac — global tooltip engine (2026-08-05).
   ONE tooltip for the whole portal. Replaces the CSS pseudo-element bubbles
   (.kt-icon-tip[data-kttip]::after and [data-kt-tooltip]::after), which were
   position:absolute and therefore CLIPPED by any ancestor with overflow
   hidden/auto/scroll (tables, rounded cards, scroll boxes) — the "tooltip
   trapped inside its own window" bug.

   This bubble is appended to <body> with position:fixed and a very high z-index,
   so it is NEVER clipped, exactly like the ⋮ kebab menu. Delegated listeners
   mean it works on anything present now or rendered later — no per-screen wiring.

   Triggers on any element carrying data-kttip or data-kt-tooltip (both existing
   conventions). Native `title` on those elements is stripped on first hover so
   the OS tooltip never double-shows; the text is preserved in data-kttip.
   ═══════════════════════════════════════════════════════════════════ */
(function (w, d) {
  'use strict';
  var KT = w.KT || (w.KT = {});
  if (KT.tooltipEngineLoaded) return;
  KT.tooltipEngineLoaded = true;

  var SEL = '[data-kttip],[data-kt-tooltip]';
  var bubble = null, anchor = null, hideTimer = null;

  /* GUARD - ONE LABEL PER HOVER (2026-11-04, "the hover on the tool tips are doubled up"). Two ways a second label
     appears, both blocked here rather than screen by screen:
     a) a component's own CSS bubble (a hover ::after that prints the data-kttip text) - the header icons and the table
        export icons both had one. On hover we read the element's ::after; if it is drawing text, it is a second
        tooltip: data-kt-tt-own switches its ::after/::before off and the console names the culprit.
     b) a native `title` on the element, an ancestor or a descendant - the OS draws its own box beside ours. Every
        title in that chain is moved to data-kttip-title (the text is kept).
     deploy.py also refuses a file that adds a [data-kttip]/[data-kt-tooltip] ::after/::before with content:attr. */
  (function () {
    var st = d.createElement('style'); st.id = 'kt-tt-guard';
    st.textContent = '[data-kt-tt-own]::after,[data-kt-tt-own]::before{content:none !important;display:none !important;}';
    (d.head || d.documentElement).appendChild(st);
  })();
  function ownBubble(t) {
    if (t.hasAttribute('data-kt-tt-own')) return;
    var c = '';
    try { c = w.getComputedStyle(t, '::after').content || ''; } catch (e) { return; }
    // Only a ::after that is writing this same label (a caret or an icon in ::after is left alone).
    var txt = textFor(t).slice(0, 24);
    if (txt && c && c !== 'none' && c !== 'normal' && c.indexOf(txt) !== -1) {
      t.setAttribute('data-kt-tt-own', '1');
      try { console.warn('[kt-tooltip] second bubble switched off on', t.className || t.tagName, '- use data-kttip only, no ::after'); } catch (e) {}
    }
  }
  function stash(n) {
    if (!n.hasAttribute || !n.hasAttribute('title')) return;
    var v = n.getAttribute('title');
    if (v && !n.getAttribute('data-kttip') && !n.getAttribute('data-kt-tooltip')) n.setAttribute('data-kttip', v);
    n.setAttribute('data-kttip-title', v);
    n.removeAttribute('title');
  }
  function noNative(t) {
    for (var n = t; n && n !== d.body && n.nodeType === 1; n = n.parentNode) stash(n);
    var kids = t.querySelectorAll ? t.querySelectorAll('[title]') : [];
    for (var i = 0; i < kids.length; i++) stash(kids[i]);
  }

  function el() {
    if (bubble) return bubble;
    bubble = d.createElement('div');
    bubble.className = 'kt-tt-bubble';
    bubble.setAttribute('role', 'tooltip');
    bubble.style.cssText =
      'position:fixed;z-index:2147483600;background:#0F172A;color:#fff;' +
      'font:600 12px/1.45 system-ui,-apple-system,"Segoe UI",sans-serif;' +
      'padding:6px 10px;border-radius:7px;max-width:280px;' +
      'box-shadow:0 6px 20px rgba(15,23,42,.32);pointer-events:none;' +
      'opacity:0;transition:opacity .1s ease;white-space:normal;left:0;top:0;';
    d.body.appendChild(bubble);
    return bubble;
  }

  function textFor(t) {
    return t.getAttribute('data-kttip') || t.getAttribute('data-kt-tooltip') ||
      t.getAttribute('data-kttip-title') || t.getAttribute('title') ||
      t.getAttribute('aria-label') || '';
  }

  function show(t) {
    var txt = textFor(t);
    if (!txt) return;
    noNative(t);     // no OS box beside ours (element, ancestors, children) - text kept
    ownBubble(t);    // no component ::after bubble beside ours
    anchor = t;
    var b = el();
    b.textContent = txt;
    // Measure off-screen, then place above (flip below if it wouldn't fit).
    b.style.opacity = '0';
    b.style.left = '0'; b.style.top = '0';
    var r = t.getBoundingClientRect();
    var bw = b.offsetWidth, bh = b.offsetHeight;
    var left = r.left + r.width / 2 - bw / 2;
    left = Math.max(8, Math.min(left, w.innerWidth - bw - 8));
    var top = r.top - bh - 8;
    if (top < 8) top = r.bottom + 8;                       // flip below near the top
    if (top + bh > w.innerHeight - 8) top = Math.max(8, r.top - bh - 8);
    b.style.left = Math.round(left) + 'px';
    b.style.top = Math.round(top) + 'px';
    b.style.opacity = '1';
  }

  function hide() {
    anchor = null;
    if (bubble) bubble.style.opacity = '0';
  }

  d.addEventListener('mouseover', function (e) {
    var t = e.target.closest ? e.target.closest(SEL) : null;
    if (!t) return;
    clearTimeout(hideTimer);
    if (t === anchor) return;
    show(t);
  }, true);

  d.addEventListener('mouseout', function (e) {
    var t = e.target.closest ? e.target.closest(SEL) : null;
    if (!t) return;
    clearTimeout(hideTimer);
    hideTimer = setTimeout(hide, 50);                      // small grace for button→child moves
  }, true);

  d.addEventListener('focusin', function (e) {
    var t = e.target.closest ? e.target.closest(SEL) : null;
    if (t) show(t);
  });
  d.addEventListener('focusout', hide);

  // Any scroll/resize/nav invalidates the anchor's position — just hide.
  w.addEventListener('scroll', hide, true);
  w.addEventListener('resize', hide);
  w.addEventListener('hashchange', hide);
  // If the anchored element was re-rendered away, drop the bubble.
  d.addEventListener('click', function () { if (anchor && !d.contains(anchor)) hide(); }, true);

  KT.tooltipHide = hide;
})(window, document);
