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

  /* THE BANNER LEADS THE PAGE (2026-10-09).

     Anthony: "the active and archived tabs are above the hero banner under children - this
     needs to be consistent across the platform - full sweep". The rule: top bar, then the
     hero, then everything else. The shell's own hoist (app-v2-shell tidyOwnBanner) only
     runs in the sweeps after a navigation, so a screen that re-renders IN PLACE later —
     a tab or an Active | Archived flip — could leave a tab strip, a filter or a toolbar
     above its banner for as long as you stayed. This runs on every mutation (rAF, so
     before the frame is painted) and moves whatever a screen drew before its hero to
     just below it, in the same order.

     Bounded so it can only ever fix a stray bar, never rearrange a page: at most four
     elements, none taller than 240px, and the banner no more than six levels deep (a
     card's own .kt-hero further down is not the page's). Anything that must stay above
     opts out with data-kt-above-hero. */
  function heroLeads(main, banner) {
    if (!banner || !main.contains(banner)) return false;
    var chain = [];
    for (var n = banner; n && n !== main; n = n.parentElement) chain.unshift(n);
    if (!chain.length || chain.length > 6) return false;
    var movers = [], homes = [];
    for (var lv = 0; lv < chain.length; lv++) {
      var node = chain[lv], par = node.parentElement;
      for (var sib = par.firstElementChild; sib && sib !== node; sib = sib.nextElementSibling) {
        if (sib.id === 'kt-topbar' || /^(SCRIPT|STYLE|LINK|TEMPLATE|META)$/.test(sib.tagName)) continue;
        if (sib.hidden || sib.hasAttribute('data-kt-above-hero')) continue;
        var cs = getComputedStyle(sib);
        if (cs.display === 'none' || cs.position === 'fixed' || cs.position === 'absolute' || cs.position === 'sticky') continue;
        var r = sib.getBoundingClientRect();
        if (r.height < 2 && !(sib.textContent || '').trim()) continue;
        if (r.height > 240) return false;           // real content above: not ours to move
        movers.push(sib);
        homes.push({ node: sib, home: par, anchor: node });
      }
    }
    if (!movers.length || movers.length > 4) return false;
    var ref = banner;
    movers.forEach(function (m) { ref.parentNode.insertBefore(m, ref.nextSibling); ref = m; });
    /* Remember where a bar came from when it was moved DOWN into a deeper container. */
    homes.forEach(function (h) { if (h.home !== banner.parentNode) movedHome.push(h); });
    if (movedHome.length > 12) movedHome.splice(0, movedHome.length - 12);
    return true;
  }

  /* A MOVED BAR MUST SURVIVE ITS NEW HOME BEING CLEARED (2026-10-09).

     Anthony: the Audit log's "subtabs not visible". heroLeads() moved the tab row (which
     the screen keeps OUTSIDE the pane it re-renders) down to just under the hero — i.e.
     INTO that pane. The next tab click cleared the pane and the tab row went with it: every
     screen with a persistent tab strip over a re-rendered pane lost its tabs on the first
     switch. So a bar moved into a deeper container is put back where the screen left it
     whenever it is detached while that spot still exists (the pane was emptied, not
     replaced); heroLeads() then places it under the new hero in the same frame. If the
     screen re-rendered the whole thing (the spot is gone too), it is left alone — the
     screen drew a new bar of its own. */
  var movedHome = [];
  function restoreMoved() {
    for (var i = movedHome.length - 1; i >= 0; i--) {
      var h = movedHome[i];
      if (h.node.isConnected) continue;
      if (h.anchor.isConnected && h.anchor.parentNode === h.home) h.home.insertBefore(h.node, h.anchor);
      movedHome.splice(i, 1);
    }
  }

  /* The page's LEADING banner for ordering only: the standard hero, or a screen's own
     header that stands in for it (the educator's "Today at a glance" brief, or anything
     marked data-kt-hero). Only findBanner()'s result is decorated below. */
  function findLead(main) {
    return findBanner(main) || main.querySelector('[data-kt-hero], #kt-day-brief');
  }

  function normalize() {
    var main = document.getElementById('appMain');
    if (!main) return;
    var banner = findBanner(main);
    try { restoreMoved(); } catch (e) {}
    try { heroLeads(main, findLead(main)); } catch (e) {}
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
    /* Re-binds when the shell swaps #appMain (kt:main-swapped); a MutationObserver follows a NODE, and this one used to die silently at the first render. */
    if (w.KT && KT.observeMain) { KT.observeMain(sweepSoon, { childList: true, subtree: true }); }
    else { new MutationObserver(sweepSoon).observe(main0, { childList: true, subtree: true }); }
  }
  // Safety net for async screen renders that swap the banner in late.
  (window.KT && KT.sweepBus) ? KT.sweepBus.on(sweep) : setInterval(sweep, 4000);

  if (w.KT) w.KT.normalizeBanner = sweep;
})(window);
