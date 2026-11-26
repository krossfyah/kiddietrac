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

  /* Every size is set with 'important': kt-fullwidth.css forces fields in #appMain to
     max-width:480px !important, which a plain inline style cannot beat. */
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
    /* Text, by what it holds (2026-10-20: "fields like family name, street address are
       presented so long"). Wide enough for real values, not the whole popup. */
    if (type === 'email' || /e-?mail/.test(key)) { return 380; }
    if (type === 'url' || /website|\burl\b|link/.test(key)) { return 460; }
    if (/street|address|addr_?line|line ?1|line ?2/.test(key)) { return 520; }
    if (/\bcity\b|town|municipality/.test(key)) { return 280; }
    if (/country/.test(key)) { return 260; }
    if (/(first|last|middle|preferred|family|given|sur)_?name|nickname|\bname\b|full name|relationship|room|centre|center|school|employer|occupation|doctor/.test(key)) { return 380; }
    if (type === 'password') { return 340; }
    return 0;
  }

  function optedOut(el) { return !!(el.closest && el.closest('[data-kt-nofit]')); }

  /* POPUPS (2026-10-27): inside a dialog or a fixed overlay, a field in a multi-column row keeps the
     width of its column, and form rows are never packed like filter bars. */
  var POPUP = '[role="dialog"],[role="alertdialog"],[data-kt-dlg],.modal,.kt-modal,.kt-sheet,.kt-drawer,.kt-cf';
  function inPopup(el) {
    if (!el || !el.closest) { return false; }
    if (el.closest(POPUP)) { return true; }
    for (var n = el; n && n !== document.body && n.nodeType === 1; n = n.parentElement) {
      if (n.id === 'appMain') { return false; }
      if (getComputedStyle(n).position === 'fixed') { return true; }
    }
    return false;
  }
  function multiColumn(el) {
    for (var n = el.parentElement, d = 0; n && d < 4 && n !== document.body; n = n.parentElement, d++) {
      var cs = getComputedStyle(n);
      if (cs.display.indexOf('grid') !== -1 && cs.gridTemplateColumns.split(' ').filter(Boolean).length > 1) { return true; }
      if (cs.display.indexOf('flex') !== -1 && cs.flexDirection.indexOf('column') === -1) {
        var others = [].filter.call(n.children, function (k) { return !k.contains(el) && k.querySelector && (k.matches('input,select,textarea') || k.querySelector('input:not([type=hidden]),select,textarea')); });
        if (others.length) { return true; }
      }
    }
    return false;
  }
  function popupColumn(el) { try { return inPopup(el) && multiColumn(el); } catch (e) { return false; } }
  /* A NARROW POPUP'S COLUMN IS ALREADY THE FIELD WIDTH (2026-11-26, Anthony: "when I go to my agency and edit the
     agency the pop up prompts are not all fully aligned correctly - full sweep"). Capping each stacked field to what
     it holds left Edit agency with a 380px name, a 240px phone, a full subdomain and two selects sized to their
     options - five right edges in one 500px column. In a popup whose form column is no wider than the width at
     which tighten() would lay fields two across (600px), every field keeps the column, so the edges line up as they
     do in the multi-column rows above. The same in a WIDER popup that has any two-across row (New medication, Add
     background check): those rows already set the right edge, so a capped field beside them is the odd one out.
     Only a wide popup of nothing but stacked fields is still capped (the 2026-10-20 "Canada" rule). */
  var POPUP_COL = 600;
  function popupRoot(el) {
    var r = el.closest(POPUP);
    if (r) { return r; }
    for (var n = el.parentElement; n && n !== document.body; n = n.parentElement) { if (getComputedStyle(n).position === 'fixed') { return n; } }
    return null;
  }
  function popupHasColumns(el) {
    var root = popupRoot(el);
    if (!root) { return false; }
    var now = Date.now();
    if (root.__ktColsAt && now - root.__ktColsAt < 1500) { return root.__ktCols; }
    var found = false, ctls = root.querySelectorAll(CONTROL);
    for (var i = 0; i < ctls.length && !found; i++) {
      var c = ctls[i];
      if (c.type === 'checkbox' || c.type === 'radio' || c.type === 'hidden' || c.offsetParent === null) { continue; }
      if (multiColumn(c)) { found = true; }
    }
    root.__ktCols = found; root.__ktColsAt = now;
    return found;
  }
  function narrowPopupField(el, w) {
    // Dates and times keep their cap: a 500px "2026-11-26" is a slab, not an aligned field (kt-fullwidth does the same).
    if (/^(date|time|datetime-local|month|week)$/.test((el.getAttribute('type') || '').toLowerCase())) { return false; }
    try { return inPopup(el) && (w <= POPUP_COL || popupHasColumns(el)); } catch (e) { return false; }
  }

  function fitSelect(sel) {
    if (!sel.__ktFitCapped && popupColumn(sel)) { sel.__ktFitSig = (sel.options ? sel.options.length : 0) + ':' + (((sel.options || [])[(sel.options || []).length - 1] || {}).text || '').length; return; }
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
    if (!sel.__ktFitCapped && narrowPopupField(sel, now)) { return; }
    if (sel.__ktFitCapped) {
      sel.style.setProperty('max-width', need + 'px', 'important');            // options changed: follow them
      return;
    }
    if (now > need + SLACK) {
      sel.style.setProperty('max-width', need + 'px', 'important');
      sel.__ktFitCapped = true;
    }
  }

  function fitInput(inp) {
    if (inp.__ktFitDone) { return; }
    var cap = capFor(inp);
    inp.__ktFitDone = true;
    if (!cap || popupColumn(inp)) { return; }
    var w = inp.getBoundingClientRect().width;
    if (narrowPopupField(inp, w)) { return; }
    if (w > cap + 24) { inp.style.setProperty('max-width', cap + 'px', 'important'); }
  }

  /* PULL STACKED FIELDS TOGETHER (2026-10-20). A form that lists label + field blocks one per
     row, each the full width, is laid out as a responsive grid instead - two or three across,
     as the space allows. Measured, not assumed:
       - a FIELD BLOCK holds exactly one control and a short label (no buttons, tables, lists);
       - the container needs 3+ field blocks stacked vertically, is at least 600px wide and
         is not already a grid or a row;
       - anything else in it (help text, headings, nested rows, buttons) keeps a full row, and
         so does a block holding a textarea.
     Desktop/tablet only; data-kt-nogrid on the container (or an ancestor) opts out. */
  var CONTROL = 'input:not([type=hidden]),select,textarea';
  function isFieldBlock(el) {
    if (!el || el.nodeType !== 1 || /^(INPUT|SELECT|TEXTAREA|BUTTON|TABLE|UL|OL|H1|H2|H3|H4|H5|H6|HR|FORM)$/.test(el.tagName)) { return false; }
    var ctl = el.querySelectorAll(CONTROL);
    if (ctl.length !== 1) { return false; }
    var c = ctl[0];
    if (c.type === 'checkbox' || c.type === 'radio' || c.type === 'file') { return false; }
    if (el.querySelector('button,table,ul,ol,img,canvas,iframe,[role=tablist]')) { return false; }
    var text = (el.textContent || '').replace(/\s+/g, ' ').trim();
    if (c.tagName === 'SELECT') { text = text.replace((c.textContent || '').replace(/\s+/g, ' ').trim(), ''); }
    if (text.length > 140) { return false; }
    return el.querySelectorAll('*').length <= 12;
  }
  function gridify(container) {
    if (!container || container.__ktGridTried || (container.closest && container.closest('[data-kt-nogrid]'))) { return; }
    var kids = [].filter.call(container.children, function (k) { return getComputedStyle(k).display !== 'none' && !/^(SCRIPT|STYLE)$/.test(k.tagName); });
    if (kids.length < 3) { return; }
    var fields = kids.filter(isFieldBlock);
    if (fields.length < 3) { return; }
    var cs = getComputedStyle(container);
    if (/grid|flex/.test(cs.display) && !(cs.display === 'flex' && cs.flexDirection === 'column')) { container.__ktGridTried = true; return; }
    var cw = container.getBoundingClientRect().width;
    if (cw < 600) { return; }                       // may widen later: try again then
    // Stacked = each field block is (nearly) the full width of the container.
    var stacked = fields.filter(function (f) { return f.getBoundingClientRect().width >= cw * 0.9; });
    if (stacked.length < 3) { container.__ktGridTried = true; return; }
    container.__ktGridTried = true;
    container.setAttribute('data-kt-autogrid', '1');
    container.style.setProperty('display', 'grid', 'important');
    container.style.setProperty('grid-template-columns', 'repeat(auto-fill, minmax(min(260px, 100%), 1fr))', 'important');
    container.style.setProperty('column-gap', '16px', 'important');
    container.style.setProperty('align-items', 'start', 'important');
    kids.forEach(function (k) {
      var wide = fields.indexOf(k) === -1 || !!k.querySelector('textarea');
      if (wide) { k.style.setProperty('grid-column', '1 / -1', 'important'); }
      else { var c = k.querySelector(CONTROL); if (c) { c.__ktFitDone = false; } }
    });
  }
  function tighten(root) {
    var seen = [];
    var ctls = (root || document).querySelectorAll(CONTROL);
    for (var i = 0; i < ctls.length; i++) {
      var c = ctls[i];
      if (c.__ktGridSeen || c.offsetParent === null) { continue; }
      c.__ktGridSeen = true;
      // The block is the control's ancestor that is a direct child of a candidate container.
      for (var b = c.parentElement, depth = 0; b && b !== document.body && depth < 4; b = b.parentElement, depth++) {
        if (isFieldBlock(b) && b.parentElement && seen.indexOf(b.parentElement) === -1) {
          seen.push(b.parentElement);
          break;
        }
      }
    }
    seen.forEach(function (ct) { try { gridify(ct); } catch (e) {} });
  }

  /* PACK FILTER BARS (2026-10-25, Anthony: "make sure fields are not wasting space ... in chat
     conversation the active from and active until are spread apart - full sweep").
     A filter bar is a grid of equal 1fr columns. Once the fields in it are capped to what they
     hold (above), each sits at the left of a wide column and the bar reads as fields with holes
     between them. So a bar is re-laid as one packed row instead: fields side by side at their
     own width, a free-text search taking the room that is left, buttons after them.
       - A BAR: a grid or row whose visible children are all field blocks, bare controls or
         buttons, with 2+ fields, and that measurably wastes space (60px+ of empty column).
       - Never a stacked form (gridify above), never a phone (<=768px), never one opting out
         with data-kt-nopack. */
  function barItem(el) {
    if (/^(INPUT|SELECT)$/.test(el.tagName)) { return el.type === 'hidden' ? 'skip' : 'ctl'; }
    if (/^(BUTTON|A)$/.test(el.tagName)) { return 'btn'; }
    if (isFieldBlock(el)) { return 'field'; }
    if (!el.querySelector(CONTROL) && el.querySelector('button,a,[role=button]') && (el.textContent || '').trim().length < 80) { return 'btn'; }
    if (!el.querySelector(CONTROL) && !(el.textContent || '').trim() && !el.querySelector('img,svg,canvas')) { return 'skip'; }
    return null;
  }
  function growable(c) {
    var type = (c.getAttribute('type') || 'text').toLowerCase();
    return c.tagName === 'INPUT' && (type === 'search' || type === 'text') && !c.style.getPropertyValue('max-width');
  }
  function packBar(ct) {
    if (!ct || ct.__ktPacked || ct.hasAttribute('data-kt-autogrid') || (ct.closest && ct.closest('[data-kt-nopack],[data-kt-nogrid]'))) { return; }
    if (inPopup(ct)) { ct.__ktPackSig = 'popup'; return; }        // a form row in a popup is not a filter bar
    var sig = ct.children.length + ':' + Math.round(ct.getBoundingClientRect().width);
    if (ct.__ktPackSig === sig) { return; }        // looked at already, nothing changed since
    ct.__ktPackSig = sig;
    var cs = getComputedStyle(ct);
    var row = cs.display.indexOf('grid') !== -1 || (cs.display.indexOf('flex') !== -1 && cs.flexDirection.indexOf('column') === -1);
    if (!row) { return; }
    var kids = [].filter.call(ct.children, function (k) { return k.getClientRects().length && getComputedStyle(k).display !== 'none'; });
    if (kids.length < 2) { return; }
    var kinds = kids.map(barItem);
    if (kinds.indexOf(null) !== -1) { return; }
    var fields = 0, waste = 0;
    kids.forEach(function (k, i) {
      if (kinds[i] !== 'field' && kinds[i] !== 'ctl') { return; }
      fields++;
      if (kinds[i] === 'field') {
        var c = k.querySelector(CONTROL);
        if (c && !growable(c)) { waste += Math.max(0, k.getBoundingClientRect().width - c.getBoundingClientRect().width); }
      }
    });
    if (fields < 2 || waste < 60) { return; }
    ct.__ktPacked = true;
    ct.setAttribute('data-kt-packed', '1');
    ct.style.setProperty('display', 'flex', 'important');
    ct.style.setProperty('flex-wrap', 'wrap', 'important');
    ct.style.setProperty('align-items', 'flex-end', 'important');
    ct.style.setProperty('justify-content', 'flex-start', 'important');
    ct.style.setProperty('column-gap', '12px', 'important');
    ct.style.setProperty('row-gap', '10px', 'important');
    kids.forEach(function (k, i) {
      k.style.setProperty('grid-column', 'auto', 'important');
      if (kinds[i] === 'field') {
        var c = k.querySelector(CONTROL);
        if (c && growable(c)) { k.style.setProperty('flex', '1 1 240px', 'important'); k.style.setProperty('max-width', '480px', 'important'); }
        else { k.style.setProperty('flex', '0 0 auto', 'important'); k.style.setProperty('width', 'auto', 'important'); k.style.setProperty('min-width', '0', 'important'); }
      } else if (kinds[i] === 'ctl' && growable(k)) {
        k.style.setProperty('flex', '1 1 240px', 'important'); k.style.setProperty('max-width', '480px', 'important');
      } else {
        k.style.setProperty('flex', '0 0 auto', 'important');
      }
    });
  }
  function packBars(root) {
    var seen = [];
    var ctls = (root || document).querySelectorAll('select, input:not([type=hidden]):not([type=checkbox]):not([type=radio]):not([type=file])');
    for (var i = 0; i < ctls.length; i++) {
      var c = ctls[i];
      if (c.offsetParent === null) { continue; }
      // the bar is the control's parent (bare controls) or its field block's parent
      for (var b = c, depth = 0; b && b.parentElement && b.parentElement !== document.body && depth < 4; b = b.parentElement, depth++) {
        var ct = b.parentElement;
        if (ct.__ktPacked || seen.indexOf(ct) !== -1) { break; }
        if (b === c || isFieldBlock(b)) { seen.push(ct); if (b !== c) { break; } }
      }
    }
    seen.forEach(function (ct) { try { packBar(ct); } catch (e) {} });
  }

  function sweep(root) {
    if (!active()) { return; }
    if (window.innerWidth > 768) { try { tighten(root); } catch (e) {} }
    var list = (root || document).querySelectorAll('select, input:not([type=hidden]):not([type=checkbox]):not([type=radio]):not([type=file]):not([type=submit]):not([type=button]):not([type=range]):not([type=search])');
    for (var i = 0; i < list.length; i++) {
      var el = list[i];
      // Cheapest checks first: most fields were settled on an earlier pass.
      if (el.tagName !== 'SELECT' && el.__ktFitDone) { continue; }
      if (el.tagName === 'SELECT' && el.options && el.__ktFitSig === (el.options.length + ':' + ((el.options[el.options.length - 1] || {}).text || '').length)) { continue; }
      if (!el.isConnected || el.offsetParent === null || optedOut(el)) { continue; }
      try { if (el.tagName === 'SELECT') { fitSelect(el); } else { fitInput(el); } } catch (e) {}
    }
    if (window.innerWidth > 768) { try { packBars(root); } catch (e) {} }
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
