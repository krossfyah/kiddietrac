/* ═══════════════════════════════════════════════════════════════════
   KiddieTrac — SETTINGS CARDS FILL THE SCREEN (2026-11-06).

   Anthony: "look at email settings, the cards are not filling all of the white spaces with the far right - can you
   wire this up where it auto fills automatically - full sweep of all settings sections with this problem".

   Nearly every settings screen draws its cards with its own inline max-width (680px on Email settings, Billing,
   Calendar, Clock, Payment providers; 480-720px elsewhere), so on a desktop two thirds of the screen sat empty to
   the right. Rewriting each screen is the fix that does not hold - the next card added would carry 680px again -
   so this recognises the cards on a settings screen and lays them out in place:

     - A CARD is a block (div/section/form/article/details) that looks like one (border, shadow or a filled
       background, or "card" in its class), held narrow by a max-width of 300-1100px, with room to its right.
     - Two or more cards side by side in the same parent: the parent becomes a grid of as many columns as fit
       (each at least 440px), so they flow into the empty space; anything else in that parent (a heading, a note,
       a Save row) spans the full row.
     - A card on its own: its max-width is lifted and it spans the width.
     - On a phone the grid is one column, exactly as before.
   Applied to the settings screens only (HASHES, plus any screen marked data-kt-fill), again whenever a tab or
   a late fetch adds cards (KT.observeMain). data-kt-nofill on a card or a parent opts it out.
   ═══════════════════════════════════════════════════════════════════ */
(function () {
  'use strict';
  if (window.__ktFillCards) { return; }
  window.__ktFillCards = true;

  // The Settings section of the sidebar, and the settings screens reached from elsewhere.
  var HASHES = ['notification-settings', 'billing-settings', 'calendar-settings', 'clock-settings', 'data-retention',
    'document-templates', 'educator-award-settings', 'email-settings', 'email-templates', 'learning-framework',
    'settings', 'payment-providers', 'sms-settings', 'quickbooks', 'admin-roles', 'educator-rooms', 'sage', 'xero',
    'mail-settings', 'social-settings', 'payroll-settings', 'photo-permissions', 'admin-branding'];
  var BLOCK = { DIV: 1, SECTION: 1, FORM: 1, ARTICLE: 1, DETAILS: 1 };
  var SKIP = '#kt-topbar, .kt-topbar, .kt-page-hero, [data-kt-nofill], .modal, [role="dialog"], table, .kt-tabband, [role="tablist"]';

  function css() {
    if (document.getElementById('kt-fill-cards-css')) { return; }
    var st = document.createElement('style');
    st.id = 'kt-fill-cards-css';
    st.textContent = ''
      /* Never over a hidden pane: a tab strip hides its panes with an inline display:none, and an !important grid
         beat it, so Email settings showed General on every tab. */
      + '.kt-fillgrid:not([hidden]):not([style*="display: none"]):not([style*="display:none"]){display:grid !important;}'
      + '.kt-fillgrid{grid-template-columns:repeat(auto-fit,minmax(min(100%,440px),1fr)) !important;'
      + 'gap:18px !important;align-items:start !important;max-width:none !important;}'
      + '.kt-fillgrid>.kt-fillcard{max-width:none !important;width:auto !important;margin:0 !important;min-width:0 !important;}'
      + '.kt-fillgrid>:not(.kt-fillcard){grid-column:1/-1;}'
      + '.kt-fillone{max-width:none !important;}'
      // Email templates: the list of template cards sat in a 1000px page column, not a card of its own.
      + '#appMain .kt-et2-page{max-width:none !important;}';
    document.head.appendChild(st);
  }

  function onSettings(main) {
    if (main.querySelector('[data-kt-fill]') || main.hasAttribute('data-kt-fill')) { return true; }
    var h = String(location.hash || '').replace(/^#/, '').split(/[?&/]/)[0];
    return HASHES.indexOf(h) >= 0;
  }

  function looksLikeCard(e, cs) {
    if (/card/i.test(String(e.className || ''))) { return true; }
    var border = cs.borderTopStyle !== 'none' && parseFloat(cs.borderTopWidth) > 0;
    var shadow = cs.boxShadow && cs.boxShadow !== 'none';
    var bg = cs.backgroundColor && cs.backgroundColor !== 'rgba(0, 0, 0, 0)' && cs.backgroundColor !== 'transparent';
    return border || shadow || bg;
  }

  function sweep() {
    var main = document.getElementById('appMain');
    if (!main || !onSettings(main)) { return; }
    if (window.innerWidth < 900) { return; }   // one column on a phone or a narrow window: nothing to fill
    var mr = main.getBoundingClientRect();
    var found = [];
    Array.prototype.forEach.call(main.querySelectorAll('div, section, form, article, details'), function (e) {
      if (!BLOCK[e.tagName] || e.classList.contains('kt-fillcard') || e.classList.contains('kt-fillone')) { return; }
      if (!e.offsetParent || e.closest(SKIP)) { return; }
      var cs = getComputedStyle(e);
      var mx = parseFloat(cs.maxWidth);
      if (!(mx >= 300 && mx <= 1100)) { return; }
      var r = e.getBoundingClientRect();
      if (r.width < 280 || r.height < 40 || (mr.right - r.right) < 240) { return; }
      if (!looksLikeCard(e, cs)) { return; }
      found.push(e);
    });
    // The outermost only: a narrow box inside a card moves with its card.
    found = found.filter(function (e) { return !found.some(function (o) { return o !== e && o.contains(e); }); });

    var byParent = new Map();
    found.forEach(function (e) {
      var p = e.parentElement;
      if (!p) { return; }
      if (!byParent.has(p)) { byParent.set(p, []); }
      byParent.get(p).push(e);
    });
    byParent.forEach(function (cards, p) {
      // Cards already in a row (the parent is flex/grid) keep the screen's own layout.
      var pd = getComputedStyle(p).display;
      if (/grid|flex/.test(pd) && !p.classList.contains('kt-fillgrid')) {
        if (/flex/.test(pd) && getComputedStyle(p).flexDirection === 'column') { /* a stack: fine to grid */ }
        else { return; }
      }
      if (cards.length >= 2) {
        p.classList.add('kt-fillgrid');
        cards.forEach(function (c) { c.classList.add('kt-fillcard'); });
      } else if (!p.classList.contains('kt-fillgrid')) {
        cards[0].classList.add('kt-fillone');
      } else {
        cards[0].classList.add('kt-fillcard');
      }
    });
  }

  var queued = false;
  function soon() {
    if (queued) { return; }
    queued = true;
    setTimeout(function () { queued = false; try { sweep(); } catch (e) {} }, 120);
  }

  function boot() {
    css();
    soon();
    if (window.KT && KT.observeMain) { KT.observeMain(soon, { childList: true, subtree: true }); }
    window.addEventListener('hashchange', soon);
    window.addEventListener('resize', soon);
    // A tab switch shows a pane that was display:none (its cards measured 0 wide); look again on a click.
    document.addEventListener('click', function () { setTimeout(soon, 60); }, true);
  }
  if (document.readyState === 'loading') { document.addEventListener('DOMContentLoaded', boot); } else { boot(); }
})();
