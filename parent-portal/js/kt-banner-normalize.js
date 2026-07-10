/* ═══════════════════════════════════════════════════════════════════
   KiddieTrac — banner normalizer (2026-07-06)
   Every section top banner should look alike: an animated gradient shimmer
   AND a gently floating art icon. In practice only the shell's auto-hero and
   a handful of screens had the floating icon, and class-less custom banners
   (e.g. Tours) had neither. This finds THE banner on whatever screen is
   showing and guarantees both — regardless of how that screen built it.
   Purely additive/visual: it never removes or blocks anything (art is
   pointer-events:none). Respects prefers-reduced-motion via CSS.
   ═══════════════════════════════════════════════════════════════════ */
(function (w) {
  'use strict';

  // First emoji (pictographic char) in a string, or null.
  function firstEmoji(text) {
    if (!text) return null;
    try { var m = text.match(/\p{Extended_Pictographic}/u); return m ? m[0] : null; } catch (e) { return null; }
  }

  // Is the trimmed string made up only of emoji (+ joiners/variation selectors)?
  function isEmojiOnly(t) {
    if (!t) return false;
    try { return /^[\p{Extended_Pictographic}️‍\s]+$/u.test(t); } catch (e) { return false; }
  }

  // Locate the banner on the current screen: a known hero class, or a
  // gradient-backed block near the very top (custom inline banners).
  function findBanner(main) {
    var known = main.querySelector('.kt-hero, .kt-page-hero, .page-header-v17');
    if (known) return known;
    var f = main.firstElementChild;
    var cands = [f, f && f.firstElementChild, f && f.firstElementChild && f.firstElementChild.firstElementChild];
    for (var i = 0; i < cands.length; i++) {
      var el = cands[i];
      if (!el || el.nodeType !== 1) continue;
      try {
        var bg = getComputedStyle(el).backgroundImage || '';
        if (bg.indexOf('gradient') !== -1 && el.getBoundingClientRect().height > 60) return el;
      } catch (e) {}
    }
    return null;
  }

  function normalize() {
    var main = document.getElementById('appMain');
    if (!main) return;
    var banner = findBanner(main);
    if (!banner || banner.getAttribute('data-kt-banner') === '1') return;
    banner.setAttribute('data-kt-banner', '1');

    // 1) Shimmer — the .kt-banner-fx class carries the animated gradient (CSS).
    banner.classList.add('kt-banner-fx');
    if (getComputedStyle(banner).position === 'static') banner.style.position = 'relative';

    // 2) Exactly ONE floating art element. Gather every art element already in
    //    the banner — known classes, real illustrations, and stray custom
    //    absolutely-positioned emoji — then keep one (preferring an illustration)
    //    and hide the rest. This fixes banners that showed DOUBLE floating art.
    var illus = [].slice.call(banner.querySelectorAll('.kt-hero-svg, img, svg'));
    var emojiArt = [].slice.call(banner.querySelectorAll('.kt-hero-emoji, .kt-banner-art'));
    var nodes = banner.querySelectorAll('div, span');
    for (var i = 0; i < nodes.length && i < 60; i++) {
      var e = nodes[i];
      if (/kt-hero-emoji|kt-banner-art/.test(e.className || '')) continue;
      try {
        var cs = getComputedStyle(e);
        if ((cs.position === 'absolute' || cs.position === 'fixed') && parseFloat(cs.fontSize) >= 40) {
          var t = (e.textContent || '').trim();
          if (t.length <= 6 && isEmojiOnly(t)) emojiArt.push(e);
        }
      } catch (ex) {}
    }
    var art = illus.concat(emojiArt);
    if (art.length > 0) {
      var keeper = illus[0] || emojiArt[0];
      art.forEach(function (el) { if (el !== keeper && el.style) el.style.display = 'none'; });
    } else {
      var navActive = document.querySelector('#appSidebar .active, #appSidebar [aria-current="page"], .nav-item.active');
      var emoji = firstEmoji(banner.textContent) || firstEmoji(navActive && navActive.textContent) || '✨';
      var el2 = document.createElement('div');
      el2.className = 'kt-banner-art';
      el2.setAttribute('aria-hidden', 'true');
      el2.textContent = emoji;
      banner.appendChild(el2);
    }
  }

  function sweep() { try { normalize(); } catch (e) {} }

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', sweep);
  else sweep();

  var main0 = document.getElementById('appMain');
  if (w.MutationObserver && main0) {
    new MutationObserver(sweep).observe(main0, { childList: true });
  }
  // Safety net for async screen renders that swap the banner in late.
  setInterval(sweep, 900);

  if (w.KT) w.KT.normalizeBanner = sweep;
})(window);
