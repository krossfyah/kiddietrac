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

  function pickEmoji(hero) {
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
    // Already has a floating emoji or an SVG illustration → leave it.
    if (hero.querySelector('.kt-hero-emoji') || hero.querySelector('.kt-hero-svg')) return;
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
