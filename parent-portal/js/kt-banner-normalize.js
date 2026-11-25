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

  /* THE BANNER STAYS FOR AS LONG AS THE SCREEN DOES (2026-10-21).

     Anthony: "educator rewards the hero banner disappears when navigating to the subtabs
     within that section - full sweep to ensure this never happens again with the entire
     portal". The shell guarantees a banner only in the ~9s after a navigation. A subtab
     that re-renders IN PLACE later (main.innerHTML = …, or a screen rebuilding itself
     without its header) took the banner with it, and nothing ever put it back.

     So this remembers the page-level banner of the screen on display (same render
     generation, same address) and, when a re-render leaves the page with none at all,
     puts THAT element back under the top bar, before the frame is painted. Never a
     banner from another screen: the generation token (window.__ktRenderGen, bumped by
     every renderScreen) has to match, which is the stale-banner rule. If the screen then
     draws a banner of its own again, the restored one steps aside, so there is never two.
     Rate-capped (3 within 2s) so a screen that re-renders on every mutation can't loop.
     Opt out by putting data-kt-hero-off anywhere in the screen. */
  var kept = null, putBack = [];
  function screenKey() { return String(location.hash || '').replace(/^#/, '').split('?')[0]; }
  function depthIn(main, el) { var d = 0; for (var n = el; n && n !== main; n = n.parentElement) d++; return n === main ? d : 99; }
  function pageBanners(main) {
    return [].slice.call(main.querySelectorAll('.kt-hero, .kt-page-hero, .page-header-v17, [data-kt-hero], #kt-day-brief'))
      .filter(function (b) { return depthIn(main, b) <= 3; });
  }
  function keepHero(main) {
    if (main.querySelector('[data-kt-hero-off]')) { kept = null; return; }
    var gen = w.__ktRenderGen || 0, key = screenKey();
    var page = pageBanners(main);
    // The screen drew its own banner again: the one we put back steps aside.
    if (kept && kept.restored && kept.node.isConnected && page.length > 1) {
      var other = page.filter(function (b) { return b !== kept.node && !kept.node.contains(b); })[0];
      if (other) { kept.node.parentNode.removeChild(kept.node); kept = { gen: gen, key: key, node: other, restored: false }; return; }
    }
    if (page.length) {
      if (!kept || kept.node !== page[0] || kept.gen !== gen) kept = { gen: gen, key: key, node: page[0], restored: false };
      return;
    }
    if (!kept || kept.gen !== gen || kept.key !== key || kept.node.isConnected) return;
    if (!main.firstElementChild) return;                    // emptied mid-render: wait for content
    var now = Date.now();
    putBack = putBack.filter(function (t) { return now - t < 2000; });
    if (putBack.length >= 3) return;
    putBack.push(now);
    var bar = null;
    for (var i = 0; i < main.children.length; i++) { if (main.children[i].id === 'kt-topbar') { bar = main.children[i]; break; } }
    main.insertBefore(kept.node, bar ? bar.nextSibling : main.firstChild);
    kept.restored = true;
  }

  /* A CLEAN BANNER ON A PHONE (2026-10-27).

     Anthony: "the hero banner in mobile view has too much text and needs to be clean and
     simple - full sweep here". Banners carried a kicker ("📜 ADMIN", "DAILY LOG") above
     the title and a two-to-four line explanation under it, which on a phone pushed the
     page a third of a screen down - and tabs whose banners differ in length made the tab
     strip jump on every switch. On a phone (600px and under) a banner is now its title,
     plus its sub-line only when that is a short one-liner (Leads: "Every prospect in the
     pipeline."). Long text blocks and the kicker are hidden; nothing is removed, so the
     desktop banner is unchanged. Buttons, inputs, links, chips and figures in a banner are
     never touched (only blocks of plain text over 70 characters). data-kt-hero-full on a
     banner keeps it whole. */
  /* Title only (2026-10-27, same day): a short sub-line was kept at first, but tabs that
     swap it (Families: "Archived families - records kept after...") changed the banner's
     height and moved the tab strip under your finger. So on a phone every text block is
     hidden; buttons, links and inputs still show. */
  var HERO_LONG = 0;
  var HERO_FONT = '"DM Sans", system-ui, -apple-system, "Segoe UI", Roboto, sans-serif', HERO_SIZE = '17px';
  /* The watcher's guard: on a phone, the banner title must compute to the locked style. A
     screen that sets its own inline !important font beats the stylesheet, so the guard puts
     the locked values inline (once per element) and records which screen did it, readable as
     KT.heroGuard.offenders() - so the next one is found, not shipped. */
  var heroOffenders = {};
  function heroTitleGuard(banner) {
    if (!w.matchMedia || !w.matchMedia('(max-width:600px)').matches) return;
    var t = banner.querySelector('h1, h2');
    if (!t) return;
    var cs = getComputedStyle(t);
    if (cs.fontSize === HERO_SIZE && String(cs.fontWeight) === '800' && /DM Sans/i.test(cs.fontFamily)) return;
    var key = String(location.hash || '#').split('?')[0];
    heroOffenders[key] = cs.fontSize + ' ' + cs.fontWeight + ' ' + cs.fontFamily.split(',')[0];
    t.style.setProperty('font-family', HERO_FONT, 'important');
    t.style.setProperty('font-size', HERO_SIZE, 'important');
    t.style.setProperty('font-weight', '800', 'important');
    t.style.setProperty('line-height', '1.25', 'important');
    t.style.setProperty('letter-spacing', '-0.3px', 'important');
    try { console.warn('[hero-guard] banner title on ' + key + ' was ' + heroOffenders[key] + ' - locked'); } catch (e) {}
  }
  w.KT = w.KT || {};
  w.KT.heroGuard = { offenders: function () { return heroOffenders; } };
  /* For kt-smooth-tabs: shape a banner the moment it is inserted, before anything measures it (2026-10-28). */
  w.KT.heroPhone = function (b) { try { simplifyForPhone(b); } catch (e) {} };
  (function () {
    if (document.getElementById('kt-hero-phone-css')) return;
    var st = document.createElement('style');
    st.id = 'kt-hero-phone-css';
    st.textContent = '@media (max-width:600px){'
      + '#appMain .kt-hero-long,#appMain .kt-hero-kicker{display:none !important;}'
      + '#appMain [data-kt-hero-phone] h1,#appMain [data-kt-hero-phone] h2{margin-top:0 !important;margin-bottom:2px !important;'
      /* ONE TITLE STYLE (2026-10-27, Anthony: "the font size and type needs to be consistent").
         Measured on ~70 screens: all DM Sans but weights 700/800/900, letter-spacing -0.5 to
         normal, and 16, 18 or 22px (Phone, Sales invoices). Locked here; heroTitleGuard()
         below re-applies it inline if a screen's own !important style wins. */
      +   'font-family:' + HERO_FONT + ' !important;font-size:' + HERO_SIZE + ' !important;font-weight:800 !important;'
      +   'line-height:1.25 !important;letter-spacing:-0.3px !important;font-style:normal !important;text-transform:none !important;}'
      + '}';
    (document.head || document.documentElement).appendChild(st);
  })();
  function simplifyForPhone(banner) {
    if (!banner || banner.hasAttribute('data-kt-hero-full')) return;
    /* Once per banner content, not once per DOM change: every pass toggled classes and then read the title's
       computed style, a forced style recalculation each time anything on the page moved (PERF 2026-10-28). */
    var sig = (banner.textContent || '').length + '|' + banner.childElementCount + '|' + (w.matchMedia && w.matchMedia('(max-width:600px)').matches ? 1 : 0);
    if (banner.__ktPhoneSig === sig) return;
    banner.__ktPhoneSig = sig;
    var title = banner.querySelector('h1, h2');
    if (!title) return;                       // no title to fall back on: leave it whole
    var blocks = banner.querySelectorAll('.kt-hero-greet, .kt-hero-sub, p, div, span');
    for (var i = 0; i < blocks.length && i < 80; i++) {
      var b = blocks[i];
      if (b === title || (title && (b.contains(title) || title.contains(b)))) continue;
      if (/kt-banner-art|kt-hero-emoji|kt-hero-svg/.test(b.className || '')) continue;
      if (b.querySelector('button, a, input, select, textarea, h1, h2, img, svg, canvas')) continue;
      var t = (b.textContent || '').replace(/\s+/g, ' ').trim();
      if (!t) continue;
      // The kicker: the small label line ABOVE the title.
      var above = title && (b.compareDocumentPosition(title) & Node.DOCUMENT_POSITION_FOLLOWING);
      if (above && t.length <= 60) { b.classList.add('kt-hero-kicker'); continue; }
      if (t.length > HERO_LONG) b.classList.add('kt-hero-long');
      else b.classList.remove('kt-hero-long');
    }
    banner.setAttribute('data-kt-hero-phone', '1');
    try { heroTitleGuard(banner); } catch (e) {}
  }

  function normalize() {
    var main = document.getElementById('appMain');
    if (!main) return;
    /* EVERY BANNER ON THE SCREEN, not only the first (2026-10-28). The Audit log builds its next tab in a
       hidden pane beside the shown one; findBanner() returns the first banner - the old one - so the new
       banner kept its kicker and explanation while hidden, was measured at full height by kt-smooth-tabs,
       and that height was then held on every banner after it (104-121px instead of 41px). */
    try { simplifyForPhone(findBanner(main)); } catch (e) {}
    try { [].forEach.call(main.querySelectorAll('.kt-hero, .kt-page-hero'), function (b, i) { if (i < 6) simplifyForPhone(b); }); } catch (e) {}
    try { keepHero(main); } catch (e) {}
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
    retireDecor(banner);
    var illus = [].slice.call(banner.querySelectorAll('.kt-hero-svg, img:not(.kt-hero-brandmark), svg'))
      .filter(function (el) { return !(el.closest && el.closest('[data-kt-brandart]')) && !isDecor(el); });
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
    /* Only FLOATING pictures are banner art (2026-11-25). The agency overview's logo sits in the title row, in the
       flow of the text; counting it as "the illustration" hid that banner's floating 🏠, so it was the one banner
       on the screen list with no moving art. An in-flow logo still keeps the title free of a second icon (below). */
    var floats = function (el) {
      for (var n = el; n && n !== banner; n = n.parentElement) {
        var p = getComputedStyle(n).position;
        if (p === 'absolute' || p === 'fixed') return true;
      }
      return false;
    };
    var inFlow = illus.filter(function (el) { return !floats(el); });
    illus = illus.filter(floats);
    var art = illus.concat(emojiArt);
    // A real floating illustration (an <img>/<svg>: the Phone handset, brand art) carries
    // its own identity — those keep it and are exempt from the emoji treatment.
    var hasIllustration = illus.length > 0;
    var iconEmoji = null;
    if (art.length > 0) {
      var keeper = banner.querySelector('[data-kt-brandart]') || illus[0] || emojiArt[0];
      art.forEach(function (el) { if (el !== keeper && el.style) el.style.display = 'none'; });
      if (!hasIllustration) {
        /* A screen that draws its own emoji art still goes by its sidebar icon (2026-11-03 sweep): Pipeline drew 💼
           beside 📊 in the sidebar, Substitutes 🧑 beside 🔄, and the hard-coded ones clashed across screens. */
        var ownArt = '';
        try { ownArt = (window.KT && KT.Shell && KT.Shell.screenIcon) ? KT.Shell.screenIcon() : ''; } catch (e) { ownArt = ''; }
        if (ownArt && firstEmoji(keeper.textContent) && firstEmoji(keeper.textContent) !== firstEmoji(ownArt) && !keeper.querySelector('img,svg')) { keeper.textContent = ownArt; }
        iconEmoji = firstEmoji(keeper.textContent);
      }
    } else {
      var navActive = document.querySelector('#appSidebar .active, #appSidebar [aria-current="page"], .nav-item.active');
      /* The screen's OWN icon first (2026-10-28 sweep). Phones and the educator/parent apps have no sidebar to read,
         so a screen whose title carried no emoji fell to ✨, and screens that hard-code an emoji in their title clashed
         (two 🗣, two 👀, two 🎓). KT.Shell.screenIcon is the sidebar icon for this role, or the shell's own table. */
      var own = '';
      try { own = (window.KT && KT.Shell && KT.Shell.screenIcon) ? KT.Shell.screenIcon() : ''; } catch (e) { own = ''; }
      var emoji = own || firstEmoji(banner.textContent) || firstEmoji(navActive && navActive.textContent) || '✨';
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
    if (iconEmoji && !hasIllustration && !inFlow.length) {
      var titleEl = banner.querySelector('h1, h2');
      // A title that hard-codes a DIFFERENT emoji takes the screen's own, so the title icon and the art agree.
      if (titleEl && iconEmoji && startsWithEmoji((titleEl.textContent || '').replace(/^\s+/, ''))) {
        try {
          var tn = titleEl.firstChild;
          while (tn && tn.nodeType !== 3) { tn = tn.firstChild; }
          if (tn && firstEmoji(tn.nodeValue) && tn.nodeValue.replace(/^\s+/, '').indexOf(iconEmoji) !== 0) {
            tn.nodeValue = tn.nodeValue.replace(/^\s*\p{Extended_Pictographic}[\u{FE0F}\u{200D}\p{Extended_Pictographic}]*\s*/u, iconEmoji + ' ');
          }
        } catch (e) { /* leave the title as it was */ }
      }
      if (titleEl && !startsWithEmoji((titleEl.textContent || '').replace(/^\s+/, ''))) {
        titleEl.insertBefore(document.createTextNode(iconEmoji + ' '), titleEl.firstChild);
      }
    }
  }

  /* DECORATIVE DRAWINGS ARE NOT THE BANNER'S ART (2026-11-25, Anthony: "some hero banners such as the families
     section lack the animated hero animation image compared to the rest - full sweep").
     Agency overview, the director's dashboard and screen-admin's tabHero banners (Centres, Users, Families) plus
     Children drew KT.Illustrations' generic clouds-and-stars (.kt-hero-svg). On a desktop that drawing is squeezed
     to 62px at .48 opacity (kt-consistency-polish 9b) - a faint smudge - and its presence made BOTH art paths stand
     down: kt-hero-emoji.js skipped any banner with a drawing, and normalize() kept the drawing as "the
     illustration". So those banners were the only ones without the big floating screen icon. The phone-only
     fix below (10-28) papered over it on phones alone.
     A generic drawing now counts as decoration: it is hidden and the banner gets the same floating screen-icon art
     as every other banner, at every width. A drawing that IS the banner's identity is still kept: the Phone
     screen's handset (.kt-phone-hero), brand art, and anything marked data-kt-art-keep. */
  function isDecor(el) {
    // The drawing's holder, or anything inside it (its <svg>): the inner svg must not count as an illustration.
    var d = el && el.closest ? el.closest('.kt-hero-svg') : null;
    if (!d) return false;
    if (d.hasAttribute('data-kt-art-keep')) return false;
    return !d.closest('.kt-phone-hero, [data-kt-brand]');
  }
  function retireDecor(banner) {
    [].forEach.call(banner.querySelectorAll('.kt-hero-svg'), function (el) {
      if (isDecor(el) && !el.hasAttribute('data-kt-decor')) {
        el.setAttribute('data-kt-decor', '1');
        el.style.setProperty('display', 'none', 'important');
      }
    });
  }
  w.KT = w.KT || {};
  w.KT.heroDecor = isDecor;   // kt-hero-emoji.js asks the same question

  /* PHONE ART FOR DRAWING-ONLY BANNERS (2026-10-28, Anthony: "check all hero banners in mobile view as some do
     not have the animating image floating - full sweep"). Swept all 156 screens at 392px: six banners (Agency
     overview, Centres, Users, Families, Children, Phone) carry only the cloud/bear drawing (.kt-hero-svg), which
     every phone stylesheet hides at <=768px - and kt-hero-emoji.js skips a banner that has a drawing - so they
     were the only bare banners on a phone. They now get the same floating .kt-banner-art as the rest, marked
     data-kt-art-phone: hidden on a desktop (the drawing shows there), shown by the phone rule
     (#appMain .kt-banner-art{display:block !important}) exactly where the drawing is hidden. */
  (function () {
    if (document.getElementById('kt-art-phone-css')) return;
    var st = document.createElement('style');
    st.id = 'kt-art-phone-css';
    st.textContent = '#appMain .kt-banner-art[data-kt-art-phone]{display:none;}'
      + '@media (max-width:768px){#appMain .kt-banner-art[data-kt-art-phone]{display:block !important;}}';
    (document.head || document.documentElement).appendChild(st);
  })();
  function phoneArt(main) {
    var bs = main.querySelectorAll('.kt-hero, .kt-page-hero');
    for (var i = 0; i < bs.length && i < 6; i++) {
      var b = bs[i];
      if (b.hasAttribute('data-kt-art-checked')) continue;
      if (!b.querySelector('.kt-hero-svg')) continue;
      b.setAttribute('data-kt-art-checked', '1');
      retireDecor(b);
      if (b.querySelector('.kt-hero-emoji, .kt-banner-art, [data-kt-brandart]')) continue;
      // A drawing that is the banner's own art (the handset) still shows on a desktop: phones only, as before.
      var keepsDrawing = !!b.querySelector('.kt-hero-svg:not([data-kt-decor])');
      var t = b.querySelector('h1, h2');
      var nav = document.querySelector('#appSidebar .active, #appSidebar [aria-current="page"], .nav-item.active');
      var own = '';
      try { own = (w.KT && KT.Shell && KT.Shell.screenIcon) ? KT.Shell.screenIcon() : ''; } catch (e) { own = ''; }
      var emoji = own || firstEmoji(t && t.textContent) || firstEmoji(nav && nav.textContent) || '🏢';
      var el = document.createElement('div');
      el.className = 'kt-banner-art';
      el.setAttribute('aria-hidden', 'true');
      if (keepsDrawing) el.setAttribute('data-kt-art-phone', '1');
      el.textContent = emoji;
      if (getComputedStyle(b).position === 'static') b.style.position = 'relative';
      b.appendChild(el);
    }
  }

  function sweep() { try { normalize(); } catch (e) {} try { var m = document.getElementById('appMain'); if (m) phoneArt(m); } catch (e) {} }

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
