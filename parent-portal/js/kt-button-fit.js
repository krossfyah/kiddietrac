/* ═══════════════════════════════════════════════════════════════════
   KiddieTrac — a button beside a field is the field's height (2026-10-28).

   Anthony: "audit log section where it shows reset button (its too big and not the same size
   as the other fields in that row - full sweep to finding things similar with consistency)".
   Measured on the Audit log: the date / account fields 32px (kt-field-height.css), Reset 34px
   tall and stretched 128px wide by its own flex:1, the ↻ beside it 35px. Every field in the
   app has ONE height (32px on a computer, 38px on a phone) but buttons kept whatever padding
   their screen gave them, so a filter row ended in something bigger than everything before it.

   A stylesheet cannot know that a button sits next to a field, so this looks: a button with a
   short label in the same flex/grid row container as a text field / dropdown (within three
   levels - see pass()) takes that field's height, and a short label never stretches
   wider than it needs. A one- or two-character button (↻, ✕, ⚙) becomes a square. Reads
   every size first and writes after (no layout thrash), runs on the sweep bus, and opts out
   with data-kt-btn-free. Tables, tab strips, pagers, the bottom bar and the top bar are left
   alone - their buttons are not in a field row.
   ═══════════════════════════════════════════════════════════════════ */
(function (w) {
  'use strict';
  var KT = w.KT || (w.KT = {});
  if (KT.buttonFit) return;

  var SKIP = 'table, [role="tablist"], .kt-tabband, .kt-pager-bar, #kt-mobilenav, .kt-mobilenav, #kt-topbar, '
    + '[data-kt-btn-free], [data-kt-nav-cover], [data-kt-refresh-snap], #kt-dialer, .kt-dp-keys, [data-kt-tall], '
    // THE ONE send button is always a 44px round arrow (kt-chat-composer.css); never shrink it to a field's height.
    // A chat's typing row (.kt-compose-row) sizes its own tools and box there too: the 🚨 became a 46px slab.
    + '.kt-send-one, .kt-compose-row';
  var FIELD = 'input:not([type="checkbox"]):not([type="radio"]):not([type="hidden"]):not([type="range"]):not([type="color"]):not([type="file"]):not([type="submit"]):not([type="button"]):not([type="reset"]):not([type="image"]), select:not([multiple]):not([size])';
  var fixed = [];   // every button this has sized, so a resize can redo them

  function shown(el) {
    var r = el.getBoundingClientRect();
    if (r.width < 4 || r.height < 4) return null;
    return r;
  }

  function scopes() {
    var out = [];
    var m = document.getElementById('appMain');
    if (m) out.push(m);
    [].forEach.call(document.querySelectorAll('.kt-modal, [role="dialog"]'), function (d) { out.push(d); });
    return out;
  }

  function pass() {
    var plan = [];
    scopes().forEach(function (root) {
      var fields = [];
      [].forEach.call(root.querySelectorAll(FIELD), function (f) {
        if (f.closest(SKIP)) return;
        var r = shown(f);
        if (r && r.height >= 24 && r.height <= 48) fields.push([f, r]);
      });
      if (!fields.length) return;
      [].forEach.call(root.querySelectorAll('button'), function (b) {
        if (b.closest(SKIP) || b.closest('.kt-ka-kebab, .kt-row-actions')) return;
        var label = (b.textContent || '').replace(/\s+/g, ' ').trim();
        if (!label || label.length > 28 || /^[⋮⋯︙…]$/.test(label)) return;   // a row menu keeps its own size
        var r = shown(b);
        if (!r || r.height < 16 || r.height > 64) return;
        /* THE SAME FIELD ROW, BY STRUCTURE (2026-10-28, Anthony: "i see the reset button resized but when i
           move to another tab or refresh it comes back"). This first matched by distance - a field ending
           within 40px on the same line - and that failed whenever the layout moved: kt-field-fit narrows date
           fields (the Audit log's last one 251 -> 190px, a 71px gap) before this pass on a re-render, and at
           1280px the toolbar wraps Reset onto its own line. Now: the nearest flex/grid row container (within
           three levels) that holds a field is the button's field row, wherever the button lands in it. A
           column stack (a form) is not a row, and a button over 60% of the row's width is a full-width
           action under a form, not part of a filter row. */
        var best = null;
        var p = b.parentElement;
        for (var k = 0; k < 3 && p && p !== root && !best; k++, p = p.parentElement) {
          var pcs = getComputedStyle(p);
          if (!/flex|grid/.test(pcs.display)) continue;
          if (/flex/.test(pcs.display) && /column/.test(pcs.flexDirection)) continue;
          /* A GRID IS A PAGE LAYOUT, NOT A FIELD ROW, unless the field sits on the button's own line (2026-10-30,
             Anthony: "file repository ... some of the subtabs are not aligned"). The Files repository is a grid of
             folders | files; the files' filter box made the whole grid a "field row", so every folder's x was
             blown up to the field height (32px desktop, 38px phone) and the folder rows stood taller than All files.
             A one-column grid is a stack; in a wider grid the button's middle must sit within the field's height. */
          var isGrid = /grid/.test(pcs.display);
          if (isGrid && String(pcs.gridTemplateColumns || '').trim().split(/\s+/).length < 2) continue;
          for (var i = 0; i < fields.length; i++) {
            if (!p.contains(fields[i][0])) continue;
            if (isGrid && ((r.top + r.bottom) / 2 < fields[i][1].top || (r.top + r.bottom) / 2 > fields[i][1].bottom)) continue;
            best = { h: Math.round(fields[i][1].height), box: p }; break;
          }
        }
        if (!best) return;
        if (r.width > best.box.getBoundingClientRect().width * 0.6) return;
        var cs = getComputedStyle(b);
        var square = [...label].length <= 2;
        var grows = parseFloat(cs.flexGrow) > 0 && !square;
        var needW = square && Math.abs(r.width - best.h) > 1;
        if (Math.abs(r.height - best.h) <= 1 && !grows && !needW) return;
        plan.push({ b: b, h: best.h, square: square, grows: grows, flex: cs.display.indexOf('flex') > -1 });
      });
    });
    // Writes, after every read above.
    plan.forEach(function (p) {
      var s = p.b.style;
      s.setProperty('height', p.h + 'px', 'important');
      s.setProperty('min-height', p.h + 'px', 'important');
      s.setProperty('max-height', p.h + 'px', 'important');
      s.setProperty('box-sizing', 'border-box', 'important');
      s.setProperty('padding-top', '0', 'important');
      s.setProperty('padding-bottom', '0', 'important');
      s.setProperty('line-height', '1', 'important');
      if (!p.flex) {
        s.setProperty('display', 'inline-flex', 'important');
        s.setProperty('align-items', 'center', 'important');
        s.setProperty('justify-content', 'center', 'important');
      }
      if (p.square) {
        s.setProperty('width', p.h + 'px', 'important');
        s.setProperty('min-width', p.h + 'px', 'important');
        s.setProperty('padding-left', '0', 'important');
        s.setProperty('padding-right', '0', 'important');
      }
      if (p.grows) { s.setProperty('flex', '0 0 auto', 'important'); s.setProperty('width', 'auto', 'important'); }
      if (fixed.indexOf(p.b) === -1) fixed.push(p.b);
    });
  }

  function run() { try { pass(); } catch (e) {} }

  // A rotation or a window crossing 768px changes the field height (32 ↔ 38): size them again.
  var lastPhone = null;
  function onResize() {
    var phone = w.matchMedia && w.matchMedia('(max-width:768px)').matches;
    if (phone === lastPhone) return;
    lastPhone = phone;
    fixed = fixed.filter(function (b) { return b.isConnected; });
    fixed.forEach(function (b) { ['height', 'min-height', 'max-height', 'width', 'min-width'].forEach(function (p) { b.style.removeProperty(p); }); });
    run();
  }
  try { lastPhone = w.matchMedia('(max-width:768px)').matches; w.addEventListener('resize', onResize); } catch (e) {}

  KT.buttonFit = { run: run };
  (KT.sweepBus && KT.sweepBus.on) ? KT.sweepBus.on(run) : setInterval(run, 3000);
  // Field rows inside pop-ups are not under #appMain, which the bus watches: look when one opens.
  try {
    new MutationObserver(function (muts) {
      for (var i = 0; i < muts.length; i++) {
        var a = muts[i].addedNodes;
        for (var j = 0; j < a.length; j++) {
          var n = a[j];
          if (n.nodeType === 1 && (n.matches('.kt-modal, [role="dialog"], .modal-backdrop') || n.querySelector('.kt-modal, [role="dialog"]'))) {
            setTimeout(run, 60); setTimeout(run, 400); return;
          }
        }
      }
    }).observe(document.body, { childList: true });
  } catch (e) {}
})(window);
