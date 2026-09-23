/* ═══════════════════════════════════════════════════════════════════
   KiddieTrac — a discreet scroll indicator for phones (2026-09-08).

   Anthony: "for pages that require scrolling in mobile view - add a discrete
   scroll bar to help with the scrolling ... I don't see the scroll bar".

   WHY CSS COULD NOT DO THIS. Two reasons, both learned the hard way:

   1. On a phone this portal does NOT scroll the document. kt-mobile-app.css makes
      the shell a 100dvh flex column with `html, body { overflow-y: hidden }`, and
      #appMain is the ONE scroller. A previous attempt styled ::-webkit-scrollbar on
      tables and modal bodies and never touched #appMain, so there was nothing to see.

   2. Even aimed correctly, ::-webkit-scrollbar is unreliable on Android/iOS, where
      scrollbars are transient overlays the page does not own. A bar that appears only
      while you are already moving is telling you something you already know.

   So the indicator is a real element we draw and control: 3px, rounded, low-contrast,
   pinned to the right of the scroller. It is brightest while scrolling and settles to a
   faint resting state rather than vanishing, because the point is to show that a page
   HAS more content — which is exactly what a vanishing bar fails to do.

   It never intercepts touches (pointer-events:none), hides itself when everything fits,
   and follows whichever element actually scrolls, so it keeps working if the shell's
   layout changes again.
   ═══════════════════════════════════════════════════════════════════ */
(function (w, d) {
  'use strict';
  var KT = w.KT || (w.KT = {});
  if (KT.scrollIndicatorLoaded) { return; }
  KT.scrollIndicatorLoaded = true;

  var PHONE = '(max-width: 600px)';
  function isPhone() {
    try { return w.matchMedia && w.matchMedia(PHONE).matches; } catch (e) { return false; }
  }

  var MIN_THUMB = 28;     // never a dot: below this it reads as a speck, not a position
  var FADE_MS = 900;      // how long it stays bright after the finger stops

  var bar = null, thumb = null, target = null, fadeTimer = 0, raf = 0;

  var DIALOGS = '.modal-backdrop, .kt-modal, .kt-modal-overlay, [role="dialog"],'
    + ' .kt-doc-viewer, .kt-lightbox, .kt-av-zoom';
  /* Dialog bodies this portal builds itself. Tried before any scanning, because they are
     right almost every time and cost one query each. */
  var DIALOG_BODIES = '.modal-body, .kt-modal-body, .kt-sheet-body, .kt-thread-body,'
    + ' [data-kt-scroll]';

  function scrolls(el) {
    if (!el) { return false; }
    if (el.scrollHeight <= el.clientHeight + 4) { return false; }
    var oy = w.getComputedStyle(el).overflowY;
    return oy === 'auto' || oy === 'scroll';
  }

  /* The scrolling region of the dialog on top, or null when no dialog is open.

     Topmost = LAST match in the DOM: dialogs are appended to body, so document order is
     stacking order at equal z-index and is also most-recently-opened. */
  function dialogScroller() {
    var open = d.querySelectorAll(DIALOGS);
    if (!open.length) { return null; }
    var top = open[open.length - 1];

    var known = top.querySelectorAll(DIALOG_BODIES);
    for (var i = 0; i < known.length; i++) {
      if (scrolls(known[i])) { return known[i]; }
    }
    if (scrolls(top)) { return top; }

    /* Nothing known overflowed — look for any scrollable descendant, but bounded, so a
       large dialog cannot make this expensive on a phone. */
    var all = top.querySelectorAll('*');
    var limit = Math.min(all.length, 250);
    for (var j = 0; j < limit; j++) {
      if (scrolls(all[j])) { return all[j]; }
    }
    /* A dialog IS open and nothing in it scrolls: show nothing. Falling through to the
       page behind would put its scroll position over a popup the user is reading. */
    return 'none';
  }

  /* The element that actually scrolls. #appMain by design on a phone, but resolved
     rather than assumed — the shell has changed shape before, and an indicator pinned
     to the wrong element is worse than none. A dialog on top wins: it is what the
     finger is on. */
  function scroller() {
    var dlg = dialogScroller();
    if (dlg === 'none') { return null; }
    if (dlg) { return dlg; }
    var m = d.getElementById('appMain');
    if (m && m.scrollHeight > m.clientHeight + 4) { return m; }
    var de = d.scrollingElement || d.documentElement;
    if (de && de.scrollHeight > de.clientHeight + 4) { return de; }
    return m || de || null;
  }

  function build() {
    if (bar) { return; }
    bar = d.createElement('div');
    bar.id = 'kt-scrollbar';
    bar.setAttribute('aria-hidden', 'true');
    /* Above dialogs (100000) so it shows on a popup, below the select-sheet band
       (2147483000) so a dropdown opened inside one covers it — which is the right
       order. Safe on top: 3px wide and pointer-events:none, so it never takes a tap. */
    bar.style.cssText = 'position:fixed;right:2px;width:3px;z-index:100001;'
      + 'pointer-events:none;opacity:0;transition:opacity .25s ease;';
    thumb = d.createElement('div');
    thumb.style.cssText = 'position:absolute;left:0;width:3px;border-radius:999px;'
      + 'background:rgba(15,23,42,.32);transition:background .25s ease;';
    bar.appendChild(thumb);
    d.body.appendChild(bar);
  }

  function hide() {
    if (bar) { bar.style.opacity = '0'; }
  }

  function draw(bright) {
    if (!isPhone()) { hide(); return; }
    var el = scroller();
    if (!el) { hide(); return; }
    target = el;

    var isDoc = (el === d.documentElement || el === d.body || el === d.scrollingElement);
    var view = isDoc ? w.innerHeight : el.clientHeight;
    var full = el.scrollHeight;
    var top = el.scrollTop;

    // Everything fits — there is nothing to indicate, so say nothing.
    if (full <= view + 4) { hide(); return; }

    build();

    var rect = isDoc ? { top: 0, height: w.innerHeight, right: w.innerWidth }
                     : el.getBoundingClientRect();
    /* Inset from the scroller's own top and bottom so the bar never runs under the
       fixed nav or a rounded corner. */
    var pad = 6;
    var trackTop = Math.max(0, rect.top + pad);
    var trackH = Math.max(0, rect.height - pad * 2);

    var h = Math.max(MIN_THUMB, Math.round(trackH * (view / full)));
    var maxOffset = trackH - h;
    var ratio = maxOffset > 0 ? (top / (full - view)) : 0;
    var y = Math.round(Math.min(1, Math.max(0, ratio)) * maxOffset);

    bar.style.top = trackTop + 'px';
    bar.style.height = trackH + 'px';
    bar.style.right = Math.max(2, w.innerWidth - rect.right + 2) + 'px';
    thumb.style.height = h + 'px';
    thumb.style.transform = 'translateY(' + y + 'px)';
    thumb.style.background = bright ? 'rgba(15,23,42,.42)' : 'rgba(15,23,42,.22)';
    bar.style.opacity = bright ? '1' : '.55';
  }

  function schedule(bright) {
    if (raf) { return; }
    raf = w.requestAnimationFrame ? w.requestAnimationFrame(function () {
      raf = 0; draw(bright);
    }) : (w.setTimeout(function () { raf = 0; draw(bright); }, 16), 1);
  }

  function onScroll() {
    schedule(true);
    if (fadeTimer) { w.clearTimeout(fadeTimer); }
    fadeTimer = w.setTimeout(function () { draw(false); }, FADE_MS);
  }

  /* Scroll events do not bubble, so listen in the CAPTURE phase at document level —
     that catches whichever element is scrolling without having to re-bind every time
     a screen re-renders and replaces it. */
  d.addEventListener('scroll', onScroll, true);
  w.addEventListener('resize', function () { schedule(false); });
  w.addEventListener('orientationchange', function () { w.setTimeout(function () { draw(false); }, 250); });
  w.addEventListener('hashchange', function () { w.setTimeout(function () { draw(false); }, 260); });

  /* Content grows and shrinks under us (a screen renders, a list loads), and that
     changes whether there is anything to indicate at all. */
  try {
    new MutationObserver(function () { schedule(false); })
      .observe(d.documentElement, { childList: true, subtree: true });
  } catch (e) {
    w.setInterval(function () { draw(false); }, 1500);
  }

  if (d.readyState === 'loading') {
    d.addEventListener('DOMContentLoaded', function () { draw(false); });
  } else {
    draw(false);
  }

  KT.scrollIndicator = { refresh: function () { draw(false); } };
}(window, document));
