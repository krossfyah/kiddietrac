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
    'room assignments': '🗺', 'tours': '🚪',
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
    'parent feedback': '🗣'
  };

  function pickEmoji(hero) {
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
    var heroes = (root || doc).querySelectorAll ? (root || doc).querySelectorAll('.kt-hero') : [];
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
            if (n.classList && n.classList.contains('kt-hero')) enhance(n);
            else if (n.querySelector && n.querySelector('.kt-hero')) scan(n);
          }
        }
      }).observe(doc.body, { childList: true, subtree: true });
    } catch (e) {}
  }

  if (doc.readyState === 'loading') doc.addEventListener('DOMContentLoaded', start);
  else start();
})(window);
