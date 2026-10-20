/* ═══════════════════════════════════════════════════════════════════
   KiddieTrac — FIELDS AS WIDE AS WHAT THEY HOLD (2026-10-20).

   Anthony: "fields under families such as canada is so long - clean this up with fields like
   this throughout the portal including filters, and dropdowns to make this look and feel
   better - full sweep".

   Hundreds of forms set width:100% on every control, so a country dropdown holding "Canada"
   ran the full width of the popup, as did a billing-split or status filter. Editing each form
   is the fix that does not hold (the next form is written the same way), so this measures
   instead, the way kt-dialog-fit.js does:

     - A <select> is capped at the width of its longest option plus the arrow - measured with
       the select's own font, and re-measured when its options change (lists that load later).
       Only a select that is clearly wider than it needs is touched.
     - Inputs whose values are short by nature get a sensible cap: dates, times, numbers,
       phone numbers, postal/ZIP codes, provinces/states, PINs, amounts. Names, emails,
       addresses and notes keep their width.
     - It sets max-width, never width: a field can still shrink in a narrow column.
     - Phones (<=600px) are left alone: a full-width field is the easier one to tap.
     - Opt out with data-kt-nofit on a field or any ancestor.

   Plus one quiet look for every field: rounded border, hover tint and a clear focus ring.
   Padding and font size are left to the existing rules (kt-polish forces those).
   ═══════════════════════════════════════════════════════════════════ */
(function () {
  'use strict';
  if (window.__ktFieldFit) { return; }
  window.__ktFieldFit = true;

  var ARROW = 38, SLACK = 70, MIN_SELECT = 120;

  function active() { try { return window.innerWidth > 600; } catch (e) { return false; } }

  var ctx = null;
  function textWidth(s, font) {
    if (!ctx) { ctx = document.createElement('canvas').getContext('2d'); }
    ctx.font = font;
    return ctx.measureText(s).width;
  }

  function capFor(input) {
    var type = (input.getAttribute('type') || 'text').toLowerCase();
    var key = ((input.name || '') + ' ' + (input.id || '') + ' ' + (input.getAttribute('placeholder') || '') + ' '
      + (input.getAttribute('autocomplete') || '')).toLowerCase();
    if (type === 'date') { return 190; }
    if (type === 'time') { return 150; }
    if (type === 'datetime-local') { return 240; }
    if (type === 'month' || type === 'week') { return 190; }
    if (type === 'number') { return /year|age|qty|quantity|count|capacity|minutes|days|hours|pin/.test(key) ? 140 : 180; }
    if (type === 'color') { return 80; }
    if (type === 'tel' || /\bphone\b|\btel\b|mobile|cell/.test(key)) { return 240; }
    if (/postal|zip|postcode/.test(key)) { return 150; }
    if (/province|state(?!ment)|region code/.test(key)) { return 200; }
    if (/\bpin\b|last4|last 4|cvc|cvv/.test(key)) { return 140; }
    if (/amount|price|fee|rate|\$|cost|wage|salary/.test(key) && type !== 'email') { return 200; }
    if (/\bsin\b|sin number|health card/.test(key)) { return 220; }
    return 0;
  }

  function optedOut(el) { return !!(el.closest && el.closest('[data-kt-nofit]')); }

  function fitSelect(sel) {
    var opts = sel.options;
    if (!opts || !opts.length || sel.multiple || (sel.size && sel.size > 1)) { return; }
    var sig = opts.length + ':' + (opts[opts.length - 1].text || '').length;
    if (sel.__ktFitSig === sig) { return; }
    sel.__ktFitSig = sig;
    var cs = getComputedStyle(sel);
    var font = cs.fontStyle + ' ' + cs.fontWeight + ' ' + cs.fontSize + ' ' + cs.fontFamily;
    var widest = 0;
    for (var i = 0; i < opts.length && i < 400; i++) {
      var w = textWidth(opts[i].text || '', font);
      if (w > widest) { widest = w; }
    }
    var pad = (parseFloat(cs.paddingLeft) || 0) + (parseFloat(cs.paddingRight) || 0) + (parseFloat(cs.borderLeftWidth) || 0) + (parseFloat(cs.borderRightWidth) || 0);
    var need = Math.ceil(Math.max(MIN_SELECT, widest + pad + ARROW));
    var now = sel.getBoundingClientRect().width;
    if (sel.__ktFitCapped) {
      sel.style.maxWidth = need + 'px';            // options changed: follow them
      return;
    }
    if (now > need + SLACK) {
      sel.style.maxWidth = need + 'px';
      sel.__ktFitCapped = true;
    }
  }

  function fitInput(inp) {
    if (inp.__ktFitDone) { return; }
    var cap = capFor(inp);
    inp.__ktFitDone = true;
    if (!cap) { return; }
    if (inp.getBoundingClientRect().width > cap + 24) { inp.style.maxWidth = cap + 'px'; }
  }

  function sweep(root) {
    if (!active()) { return; }
    var list = (root || document).querySelectorAll('select, input:not([type=hidden]):not([type=checkbox]):not([type=radio]):not([type=file]):not([type=submit]):not([type=button]):not([type=range]):not([type=search])');
    for (var i = 0; i < list.length; i++) {
      var el = list[i];
      // Cheapest checks first: most fields were settled on an earlier pass.
      if (el.tagName !== 'SELECT' && el.__ktFitDone) { continue; }
      if (el.tagName === 'SELECT' && el.options && el.__ktFitSig === (el.options.length + ':' + ((el.options[el.options.length - 1] || {}).text || '').length)) { continue; }
      if (!el.isConnected || el.offsetParent === null || optedOut(el)) { continue; }
      try { if (el.tagName === 'SELECT') { fitSelect(el); } else { fitInput(el); } } catch (e) {}
    }
  }

  var CSS_ID = 'kt-field-fit-css';
  function ensureCss() {
    if (document.getElementById(CSS_ID)) { return; }
    var st = document.createElement('style');
    st.id = CSS_ID;
    /* :where() keeps these at ZERO specificity: they only fill in where a screen has not
       styled its fields itself (rounded search pills, the chat box and the rest keep theirs). */
    var F = 'select:not([multiple]),input[type=text],input[type=email],input[type=tel],input[type=number],input[type=date],'
      + 'input[type=time],input[type=datetime-local],input[type=password],input[type=url],input:not([type]),textarea';
    st.textContent = ''
      + ':where(' + F + '){border-radius:8px;border-color:#CBD5E1;transition:border-color .15s,box-shadow .15s;}'
      + ':where(' + F + '):hover{border-color:#94A3B8;}'
      + ':where(' + F + '):focus{outline:none;border-color:#2A5580;box-shadow:0 0 0 3px rgba(42,85,128,.16);}'
      + ':where(select:not([multiple])){cursor:pointer;}'
      + ':where(select,input,textarea):disabled{background-color:#F8FAFC;color:#64748B;cursor:not-allowed;}';
    document.head.appendChild(st);
  }

  var t = null;
  function schedule() { if (!t) { t = setTimeout(function () { t = null; sweep(document); }, 150); } }

  function boot() {
    ensureCss();
    try {
      new MutationObserver(schedule).observe(document.body, { childList: true, subtree: true });
    } catch (e) {}
    window.addEventListener('resize', schedule);
    schedule();
  }
  if (document.readyState === 'loading') { document.addEventListener('DOMContentLoaded', boot); } else { boot(); }
})();
