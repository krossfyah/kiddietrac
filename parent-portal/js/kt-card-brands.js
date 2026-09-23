/* ═══════════════════════════════════════════════════════════════════
   KiddieTrac — card brand marks (2026-09-03)

   KT.cardMark(brand, opts) → SVG markup for a card brand, sized to sit inline
   beside "ending 4242".

   Drawn as inline SVG rather than fetched from a CDN: a payment screen should not
   depend on a third party being up to tell a parent which card is on file, and an
   <img> to somebody else's server on a billing page is a tracker whether or not it
   is meant as one. Every mark here is a few hundred bytes.

   The marks are the brands' own acceptance marks, drawn to the proportions they
   publish — that is what they are for, and a card on file is exactly the context
   they exist to label. Where a brand's mark is a wordmark (Visa, Amex, Discover)
   the wordmark is set in the page's own font rather than the brand's licensed
   typeface, which is the usual compromise and reads correctly at 32px wide.

   An unknown brand falls back to a neutral card glyph rather than a guess. Showing
   the wrong brand on a payment method is worse than showing none.
   ═══════════════════════════════════════════════════════════════════ */
(function (window) {
  'use strict';
  var KT = window.KT || (window.KT = {});
  if (KT.cardMark) { return; }

  var FONT = '-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif';

  /* Each mark is drawn in a 48×32 box and scaled by the caller, so they all line
     up on a shared baseline no matter which brands a family has. */
  var MARKS = {
    visa: '<rect width="48" height="32" rx="4" fill="#fff" stroke="#E2E8F0"/>'
      + '<text x="24" y="21" text-anchor="middle" font-family="' + FONT + '"'
      + ' font-size="13" font-weight="700" font-style="italic"'
      + ' letter-spacing="0.5" fill="#1A1F71">VISA</text>',

    /* The interlocking circles are the mark itself, so these are drawn true. */
    mastercard: '<rect width="48" height="32" rx="4" fill="#fff" stroke="#E2E8F0"/>'
      + '<circle cx="19" cy="16" r="9" fill="#EB001B"/>'
      + '<circle cx="29" cy="16" r="9" fill="#F79E1B"/>'
      + '<path d="M24 9.2a9 9 0 0 0 0 13.6 9 9 0 0 0 0-13.6z" fill="#FF5F00"/>',

    amex: '<rect width="48" height="32" rx="4" fill="#006FCF"/>'
      + '<text x="24" y="20" text-anchor="middle" font-family="' + FONT + '"'
      + ' font-size="9" font-weight="800" letter-spacing="0.3" fill="#fff">AMEX</text>',

    discover: '<rect width="48" height="32" rx="4" fill="#fff" stroke="#E2E8F0"/>'
      + '<path d="M4 24h40a4 4 0 0 0 4-4v-2c-10 6-24 7-44 4v2a4 4 0 0 0 0 0z" fill="#FF6000"/>'
      + '<text x="24" y="15" text-anchor="middle" font-family="' + FONT + '"'
      + ' font-size="7" font-weight="800" letter-spacing="0.2" fill="#1A1F27">DISCOVER</text>'
      + '<circle cx="38" cy="21" r="4" fill="#FF6000"/>',

    diners: '<rect width="48" height="32" rx="4" fill="#fff" stroke="#E2E8F0"/>'
      + '<circle cx="24" cy="16" r="10" fill="#0079BE"/>'
      + '<path d="M24 8a8 8 0 0 0 0 16 8 8 0 0 0 0-16zm-1.6 12.9a5.2 5.2 0 0 1 0-9.8v9.8z'
      + 'm3.2 0v-9.8a5.2 5.2 0 0 1 0 9.8z" fill="#fff"/>',

    jcb: '<rect width="48" height="32" rx="4" fill="#fff" stroke="#E2E8F0"/>'
      + '<rect x="8" y="7" width="10" height="18" rx="3" fill="#0E4C96"/>'
      + '<rect x="19" y="7" width="10" height="18" rx="3" fill="#D4002A"/>'
      + '<rect x="30" y="7" width="10" height="18" rx="3" fill="#00A650"/>',

    unionpay: '<rect width="48" height="32" rx="4" fill="#fff" stroke="#E2E8F0"/>'
      + '<rect x="8" y="7" width="11" height="18" rx="3" fill="#E21836"/>'
      + '<rect x="18" y="7" width="11" height="18" rx="3" fill="#00447C"/>'
      + '<rect x="28" y="7" width="11" height="18" rx="3" fill="#007B84"/>',

    /* Unknown brand: a plain card. Never a guess — the wrong brand on somebody's
       payment method is worse than no brand at all. */
    unknown: '<rect width="48" height="32" rx="4" fill="#F1F5F9" stroke="#E2E8F0"/>'
      + '<rect x="4" y="12" width="40" height="4" fill="#CBD5E1"/>'
      + '<rect x="7" y="20" width="12" height="3" rx="1.5" fill="#CBD5E1"/>',
  };

  var LABELS = {
    visa: 'Visa', mastercard: 'Mastercard', amex: 'American Express',
    discover: 'Discover', diners: 'Diners Club', jcb: 'JCB', unionpay: 'UnionPay',
  };

  /**
   * @param {string} brand  visa | mastercard | amex | discover | diners | jcb | unionpay
   * @param {object} opts   { width } — height follows at the 48:32 ratio
   * @returns {string} SVG markup, safe to inject (no interpolated caller input)
   */
  KT.cardMark = function (brand, opts) {
    opts = opts || {};
    var key = String(brand || '').toLowerCase();
    var body = MARKS[key] || MARKS.unknown;
    var w = Number(opts.width) || 34;
    var h = Math.round(w * (32 / 48));
    var label = LABELS[key] || 'Card';

    return '<svg width="' + w + '" height="' + h + '" viewBox="0 0 48 32" role="img"'
      + ' aria-label="' + label + '" style="vertical-align:middle;flex:0 0 auto;">'
      + '<title>' + label + '</title>' + body + '</svg>';
  };

  /** "Mastercard debit ending 0077" — the sentence, not just the mark. */
  KT.cardLabel = function (brand, last4, kind) {
    var name = LABELS[String(brand || '').toLowerCase()] || 'Card';
    var k = kind === 'debit' ? ' debit' : (kind === 'credit' ? '' : '');
    return name + k + (last4 ? ' ending ' + last4 : '');
  };

  KT.cardBrandName = function (brand) {
    return LABELS[String(brand || '').toLowerCase()] || 'Card';
  };
})(window);
