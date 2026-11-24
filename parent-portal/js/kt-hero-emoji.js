/* ═══════════════════════════════════════════════════════════════════
   KIDDIETRAC v22p87 — consistent floating banner photo
   The shell auto-generated banners carry a big floating .kt-hero-emoji; many
   custom-built section banners (Forms, Tuition plans, etc.) had no decoration
   at all, so they looked dead next to the animated ones. This injects a
   floating emoji into any .kt-hero that lacks one — using the section's own
   icon (taken from its "greet"/heading) so it's contextual — matching the
   look of the shell banners. Banners that already have an emoji or an SVG
   illustration are left alone. Runs on load and on dynamic screen renders.
   ═══════════════════════════════════════════════════════════════════ */
(function (window) {
  'use strict';
  var doc = window.document;
  var EMOJI_RE = /(\p{Extended_Pictographic}️?(?:‍\p{Extended_Pictographic}️?)*)/u;

  /* ── A banner's own colour ───────────────────────────────────────────
     Every .kt-hero used to draw --kt-hero-grad, so screens differed only by a floating
     emoji — and buildAutoHero() takes that from the NAV icon, which is reused: 🧾 covers
     Billing, Expenses, Accounting and Invoices. Four banners a reader cannot tell apart.

     The colour comes from a stable hash of the banner's TITLE, so it is the same on every
     visit and after every deploy (a banner that changed colour between loads would be
     worse than one that matched its neighbour), and a screen added later gets a colour
     without anyone maintaining a list. */
  var GRADS = ['a', 'b', 'c', 'd', 'e', 'f', 'g', 'h'];

  function heroTitle(hero) {
    var h = hero.querySelector('h1, h2');
    return ((h && h.textContent) || hero.textContent || '').replace(/\s+/g, ' ').trim().toLowerCase();
  }

  function hashIndex(str, n) {
    var h = 2166136261;                       // FNV-1a: short, stable, well spread
    for (var i = 0; i < str.length; i++) {
      h ^= str.charCodeAt(i);
      h = (h * 16777619) >>> 0;
    }
    return h % n;
  }

  function tint(hero) {
    // A hero with its own inline background is deliberate artwork — never paint over it.
    var own = hero.getAttribute('style') || '';
    if (/background/i.test(own)) return;
    if (hero.querySelector('.kt-hero-svg')) return;
    var key = heroTitle(hero);
    if (!key) return;
    hero.style.background = 'var(--kt-hero-grad-' + GRADS[hashIndex(key, GRADS.length)] + ')';
  }

  /* Banner-specific glyphs for the titles whose NAV icon is shared with another screen.
     Reusing an icon in the sidebar is right — a nav full of unique glyphs is harder to
     scan — but on a banner it is the whole decoration. These are chosen to still MEAN the
     screen; a merely-different glyph would trade one problem for a worse one. Anything
     absent here keeps what the nav gave it. */
  var BANNER_EMOJI = {
    'billing': '🧾', 'expenses': '💸', 'accounting': '📒', 'invoices': '🗂',
    'custom forms': '📝', 'daily log': '📔', 'forms': '🖊', 'forms to fill in': '🖊',
    'new visit report': '🏡',
    'children': '🧒', 'roles & permissions': '🛡', 'background checks': '🔎',
    'room assignments': '🗺', 'room controls': '🎛️', 'tours': '🚪',
    'bus routes': '🚌', 'field trips': '🚐',
    'room rotations': '🔄', 'substitutes': '🧑‍🏫',
    'audit log': '📜', 'doc workflows': '🗃',
    'curriculum': '🧭', 'lesson plans': '📚',
    'ai doc extract': '🪄', 'photo ai tagging': '🏷',
    'announcements': '📢', 'news': '🗞',
    /* Messenger and Parent Feedback both take 💬 from the nav — the only pair of
       genuinely DIFFERENT screens still sharing a banner after the colour split.
       (Billing/Billing settings and Children/Children are one screen under two
       hashes, and should look the same.) */
    'parent feedback': '💭'   // not 🗣: Parent issues has that in the same sidebar (2026-10-28 sweep)
  };

  /* PRODUCT BRANDS (2026-10-26). A banner whose title names a branded feature shows that feature's mark - next to
     the title and as the large art - on the brand's own gradient, instead of an emoji on a hashed colour. */
  var BRANDS = [
    { re: /\bwalk watch\b/, mark: '/img/walk-watch.svg', art: '/img/walk-watch-ghost.svg', alt: 'Walk Watch',
      bg: 'linear-gradient(135deg,#0A1E55 0%,#12307A 52%,#0E8F99 100%)' },
    /* Safe Arrival (2026-10-27): the sidebar's green shield, not the 🛡 emoji. */
    { re: /\bsafe arrival\b/, mark: '/img/safe-arrival.svg', art: '/img/safe-arrival-ghost.svg', alt: 'Safe Arrival',
      bg: 'linear-gradient(135deg,#064E3B 0%,#047857 55%,#10B981 100%)' },
    /* Play Spark (2026-11-24): the Toy-box spark on its Midnight ink tile, not the 🧩 emoji. Parents' screen is "Play at home". */
    { re: /\bplay spark\b|^play at home\b/, mark: '/img/play-spark.svg?v=2', art: '/img/play-spark-ghost.svg', alt: 'Play Spark',
      bg: 'linear-gradient(135deg,#0B1026 0%,#1B2350 55%,#2A3570 100%)' }
  ];
  function brandOf(hero) {
    var t = heroTitle(hero);
    for (var i = 0; i < BRANDS.length; i++) { if (BRANDS[i].re.test(t)) { return BRANDS[i]; } }
    return null;
  }
  function applyBrand(hero, b) {
    hero.style.setProperty('background', b.bg, 'important');
    hero.style.setProperty('background-size', '200% 200%', 'important');
    hero.setAttribute('data-kt-brand', b.alt);
    // The emoji in front of the title becomes the mark.
    var h = hero.querySelector('h1, h2');
    if (h && !h.querySelector('.kt-hero-brandmark')) {
      for (var n = h.firstChild; n; n = n.nextSibling) {
        if (n.nodeType === 3 && EMOJI_RE.test(n.nodeValue)) { n.nodeValue = n.nodeValue.replace(EMOJI_RE, '').replace(/^\s+/, ''); break; }
        if (n.nodeType === 1 && EMOJI_RE.test(n.textContent || '') && (n.textContent || '').trim().length <= 3) { h.removeChild(n); break; }
        if (n.nodeType === 3 && n.nodeValue.trim()) { break; }
      }
      var m = doc.createElement('img');
      m.className = 'kt-hero-brandmark'; m.src = b.mark; m.alt = ''; m.setAttribute('aria-hidden', 'true');
      m.style.cssText = 'display:inline-block !important;width:1.15em !important;height:1.15em !important;max-width:none !important;'
        + 'position:static !important;opacity:1 !important;vertical-align:-0.2em;margin:0 .4em 0 0 !important;border-radius:.3em;box-shadow:0 0 0 1.5px rgba(255,255,255,.38),0 2px 8px rgba(0,0,0,.25);';
      h.insertBefore(m, h.firstChild);
    }
    // The large art: the mark, in the SAME floating element every other banner uses for its emoji - so it sits
    // far right, as large, as faint and floating exactly like theirs. Any emoji art already there goes.
    [].forEach.call(hero.querySelectorAll('.kt-hero-emoji, .kt-banner-art, .kt-hero-brandart'), function (o) {
      if (!o.hasAttribute('data-kt-brandart') && o.parentNode) { o.parentNode.removeChild(o); }
    });
    if (!hero.querySelector('[data-kt-brandart]')) {
      var art = doc.createElement('div');
      art.className = hero.classList.contains('kt-hero') ? 'kt-hero-emoji' : 'kt-banner-art';
      art.setAttribute('data-kt-brandart', '1');
      art.setAttribute('aria-hidden', 'true');
      var im = doc.createElement('img');
      im.src = b.art || b.mark; im.alt = '';
      im.style.cssText = 'display:block;width:1em;height:1em;border-radius:.26em;';
      art.appendChild(im);
      hero.appendChild(art);
    }
  }

  function pickEmoji(hero) {
    /* The screen's OWN icon first (2026-10-28 sweep, Anthony: "hero banners in educator view - make sure they are all
       unique"): the sidebar icon for this role, or the shell's table for screens reached from home tiles. Reading the
       title's emoji let screens that hard-code one clash (two 🗣, two 👀, two 🎓) and the rest fall to ✨. */
    try {
      var own = (window.KT && KT.Shell && KT.Shell.screenIcon) ? KT.Shell.screenIcon() : '';
      if (own) { return own; }
    } catch (e) { /* fall through */ }
    // A banner-specific glyph wins over the nav icon, where one is defined.
    var titled = BANNER_EMOJI[heroTitle(hero)];
    if (titled) return titled;

    var greet = hero.querySelector('.kt-hero-greet');
    var heading = hero.querySelector('h1, h2');
    var sources = [greet && greet.textContent, heading && heading.textContent, hero.textContent];
    for (var i = 0; i < sources.length; i++) {
      if (!sources[i]) continue;
      var m = sources[i].match(EMOJI_RE);
      if (m) return m[1];
    }
    // Subject-appropriate default instead of a generic sparkle.
    var _h = ((heading && heading.textContent) || '').toLowerCase();
    if (/\bfamily\b/.test(_h)) return '👨‍👩‍👧‍👦';
    if (/\bclassroom\b/.test(_h)) return '🧑‍🏫';
    return '✨';
  }

  function enhance(hero) {
    if (!hero || hero.__ktEmoji) return;
    hero.__ktEmoji = 1;

    // A page hero (.kt-page-hero) only ever gets a brand here; the rest of this file is for .kt-hero.
    if (!hero.classList.contains('kt-hero')) {
      var pb = brandOf(hero);
      if (pb) { applyBrand(hero, pb); }
      return;
    }

    var brand = brandOf(hero);
    if (brand) { applyBrand(hero, brand); return; }

    // Its own colour first — this applies to EVERY hero, including the ones that
    // already carry their own emoji and return below.
    tint(hero);

    // An auto hero's emoji comes from the nav icon, which is shared between screens.
    // Replace it where this banner has a glyph of its own.
    var existing = hero.querySelector('.kt-hero-emoji');
    if (existing) {
      var mine = BANNER_EMOJI[heroTitle(hero)];
      if (mine) existing.textContent = mine;
      return;
    }
    if (hero.querySelector('.kt-hero-svg')) return;
    var e = doc.createElement('div');
    e.className = 'kt-hero-emoji';
    e.setAttribute('aria-hidden', 'true');
    e.textContent = pickEmoji(hero);
    hero.appendChild(e);
  }

  function scan(root) {
    var heroes = (root || doc).querySelectorAll ? (root || doc).querySelectorAll('.kt-hero, .kt-page-hero') : [];
    for (var i = 0; i < heroes.length; i++) enhance(heroes[i]);
  }

  function start() {
    scan(doc);
    try {
      new MutationObserver(function (muts) {
        for (var i = 0; i < muts.length; i++) {
          var added = muts[i].addedNodes;
          for (var j = 0; j < added.length; j++) {
            var n = added[j];
            if (n.nodeType !== 1) continue;
            if (n.classList && (n.classList.contains('kt-hero') || n.classList.contains('kt-page-hero'))) enhance(n);
            else if (n.querySelector && n.querySelector('.kt-hero, .kt-page-hero')) scan(n);
          }
        }
      }).observe(doc.body, { childList: true, subtree: true });
    } catch (e) {}
  }

  if (doc.readyState === 'loading') doc.addEventListener('DOMContentLoaded', start);
  else start();
})(window);
