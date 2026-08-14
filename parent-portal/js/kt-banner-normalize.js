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

  // Does the trimmed string START with an emoji? (used to avoid double-prefixing a title)
  function startsWithEmoji(t) {
    if (!t) return false;
    try { return /^\s*\p{Extended_Pictographic}/u.test(t); } catch (e) { return false; }
  }

  // Locate the banner on the current screen: a known hero class, or a
  // gradient-backed block near the very top (custom inline banners).
  function findBanner(main) {
    // ONLY a real banner. The old fallback guessed by sniffing the first few elements for
    // a gradient background, which is how the SMS form's card ended up wearing banner
    // artwork — a floating emoji sat on top of the message box. The shell now guarantees
    // every screen has a .kt-hero, so there is nothing left to guess at.
    return main.querySelector('.kt-hero, .kt-page-hero, .page-header-v17');
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
    // A real illustration/logo (an <img>/<svg>, e.g. the agency-overview banner) carries
    // its own identity — those keep their logo and are exempt from the emoji treatment.
    var hasIllustration = illus.length > 0;
    var iconEmoji = null;
    if (art.length > 0) {
      var keeper = illus[0] || emojiArt[0];
      art.forEach(function (el) { if (el !== keeper && el.style) el.style.display = 'none'; });
      if (!hasIllustration) iconEmoji = firstEmoji(keeper.textContent);
    } else {
      var navActive = document.querySelector('#appSidebar .active, #appSidebar [aria-current="page"], .nav-item.active');
      var emoji = firstEmoji(banner.textContent) || firstEmoji(navActive && navActive.textContent) || '✨';
      var el2 = document.createElement('div');
      el2.className = 'kt-banner-art';
      el2.setAttribute('aria-hidden', 'true');
      el2.textContent = emoji;
      banner.appendChild(el2);
      iconEmoji = emoji;
    }

    // 3) Leading icon on the title — the SAME emoji as the floating art, so EVERY section
    //    reads "<icon> Title" with its matching art hovering on the right. Some banners
    //    (auto-hero, Provider map, Expenses, Audit log...) had the art but no leading
    //    icon; others (Support tickets, Reports...) had both — this makes them all alike.
    //    Added as a separate text node so it survives translation and never clobbers the
    //    title's own child nodes (breadcrumbs, <strong>, etc.).
    if (iconEmoji && !hasIllustration) {
      var titleEl = banner.querySelector('h1, h2');
      if (titleEl && !startsWithEmoji((titleEl.textContent || '').replace(/^\s+/, ''))) {
        titleEl.insertBefore(document.createTextNode(iconEmoji + ' '), titleEl.firstChild);
      }
    }
  }

  function sweep() { try { normalize(); } catch (e) {} }

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', sweep);
  else sweep();

  // Coalesce a burst of mutations into a single sweep on the next frame. Without
  // this, subtree observation would call normalize() once per inserted node.
  var pending = false;
  function sweepSoon() {
    if (pending) return;
    pending = true;
    var run = function () { pending = false; sweep(); };
    (w.requestAnimationFrame ? w.requestAnimationFrame(run) : setTimeout(run, 16));
  }

  var main0 = document.getElementById('appMain');
  if (w.MutationObserver && main0) {
    // subtree: true, because childList alone only fires for DIRECT children of
    // #appMain. Every tabbed screen swaps its banner deeper than that — the Audit
    // screen's Email log tab, and screen-admin's Centres, Users and Families tabs via
    // tabHero() — so their heroes appeared unshimmered until the periodic sweep
    // happened past. Watching only the top level meant the guarantee this file exists
    // to provide silently did not hold on exactly the screens that re-render most.
    //
    // Safe against self-triggering: normalize() mutates the banner it just found, which
    // trips this observer, but the next pass sees data-kt-banner and returns without
    // mutating. It settles after one extra frame.
    new MutationObserver(sweepSoon).observe(main0, { childList: true, subtree: true });
  }
  // Safety net for async screen renders that swap the banner in late.
  (window.KT && KT.sweepBus) ? KT.sweepBus.on(sweep) : setInterval(sweep, 4000);

  if (w.KT) w.KT.normalizeBanner = sweep;
})(window);
