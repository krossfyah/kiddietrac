/* kt-overflow-guard.js — TEXT NEVER SPILLS OUT OF ITS BOX (2026-10-09).
 *
 * Anthony: "the pill for the device type for desktop is overflowing - can you make sure
 * this never happens to any other pills and sections in the portal - full sweep".
 *
 * The cause is always the same flexbox default: a flex or grid item's min-width is
 * `auto`, i.e. "as wide as my content", so a label with white-space:nowrap (or a long
 * word) refuses to shrink and runs straight through the tile's border. The sweep found
 * it on the device tiles, External billing's stat tiles and Help's category tiles.
 *
 * Those are fixed at source. This is the net for the next one: after each render, any
 * element whose OWN text runs past the edge of the nearest bordered or filled box is
 * fixed in place: every flex/grid item between the text and that box gets min-width:0,
 * and the text wraps. A single-line pill (nowrap) is ellipsised instead, with the full
 * text in a tooltip, because wrapping a pill changes its height.
 *
 * Left alone on purpose: anything inside a horizontal scroller (wide tables scroll),
 * text an element truncates deliberately with an ellipsis, and [data-kt-allow-overflow].
 * Never throws; capped per pass; each element is fixed at most once.
 *   KT.overflowGuard.report()  — what it fixed on this page
 *   window.__ktNoOverflowGuard = true — off (used by the sweep to test source fixes)
 */
(function (w) {
  'use strict';
  var d = w.document;
  w.KT = w.KT || {};
  var fixed = [];
  var done = (typeof WeakSet !== 'undefined') ? new WeakSet() : null;
  var timer = null;

  function ownText(n) {
    for (var c = n.firstChild; c; c = c.nextSibling) {
      if (c.nodeType === 3 && c.nodeValue && c.nodeValue.trim()) return true;
    }
    return false;
  }
  function boxed(cs) {
    return parseFloat(cs.borderLeftWidth) > 0 || parseFloat(cs.borderRightWidth) > 0
      || (cs.backgroundColor && cs.backgroundColor !== 'rgba(0, 0, 0, 0)' && cs.backgroundColor !== 'transparent');
  }
  function inScroller(n, stop) {
    for (var p = n.parentElement; p && p !== stop; p = p.parentElement) {
      var o = w.getComputedStyle(p).overflowX;
      if (o === 'auto' || o === 'scroll') return true;
    }
    return false;
  }
  function label(n) {
    return n.tagName.toLowerCase() + (typeof n.className === 'string' && n.className.trim() ? '.' + n.className.trim().split(/\s+/)[0] : '')
      + ' "' + (n.textContent || '').replace(/\s+/g, ' ').trim().slice(0, 40) + '"';
  }

  /* A COLUMN IS AS WIDE AS WHAT IT HOLDS (2026-10-29). Anthony, Message Centre on desktop: "the
     active overlapping the time - full sweep to find issues like this and resolve", then "add logic
     to auto size properly - guards". The pass below leaves every horizontal scroller alone, and on
     desktop every list table IS one (kt-desktop-fluid makes filtered tables scroll sideways), so a
     fixed-layout table whose Status column was set to 110px drew "Delivered" + the ACTIVE pill
     (~170px) straight over the Date beside it.

     For each table-layout:fixed table: a cell whose content is wider than its column (and is not
     clipping on purpose) widens that column's header to fit, capped at 420px; the columns without
     a set width (the message, the description) give up the room. Reads first, then writes; checks
     the first 40 rows; each column is only ever widened. */
  function fitCells(main) {
    var tables = main.querySelectorAll('table');
    var grow = [];
    for (var t = 0; t < tables.length && t < 30; t++) {
      var tb = tables[t];
      if (!tb.offsetParent || tb.closest('[data-kt-allow-overflow]')) continue;
      if (w.getComputedStyle(tb).tableLayout !== 'fixed') continue;
      var head = tb.tHead && tb.tHead.rows.length ? tb.tHead.rows[tb.tHead.rows.length - 1] : null;
      if (!head) continue;
      var heads = head.cells, need = {};
      var rows = tb.tBodies.length ? tb.tBodies[0].rows : [];
      for (var r = 0; r < rows.length && r < 40; r++) {
        var cells = rows[r].cells;
        if (cells.length !== heads.length) continue;            // colspan rows: columns do not line up
        for (var c = 0; c < cells.length; c++) {
          var td = cells[c];
          var over = td.scrollWidth - td.clientWidth;
          if (over <= 2) continue;
          var cs = w.getComputedStyle(td);
          if (cs.overflowX !== 'visible' || cs.textOverflow === 'ellipsis') continue;   // clipping on purpose
          if (!need[c] || over > need[c]) need[c] = over;
        }
      }
      for (var k in need) {
        var th = heads[k];
        if (!th) continue;
        var cur = th.getBoundingClientRect().width;
        var want = Math.min(Math.ceil(cur + need[k] + 6), 420);
        if (want > cur + 1) grow.push([th, want, tb]);
      }
    }
    for (var g = 0; g < grow.length; g++) {
      grow[g][0].style.width = grow[g][1] + 'px';
      fixed.push({ hash: w.location.hash, el: label(grow[g][0]), by: grow[g][1], as: 'column' });
      if (fixed.length > 200) fixed.shift();
    }
    return grow.length;
  }

  function pass() {
    timer = null;
    if (w.__ktNoOverflowGuard) return;
    var main = d.getElementById('appMain');
    if (!main || d.hidden) return;
    try { fitCells(main); } catch (e) { /* never break a page */ }
    var all = main.querySelectorAll('div,span,strong,b,small,em,p,a,label,li,h1,h2,h3,h4,h5,h6,button');
    var n = 0, budget = 5000;
    for (var i = 0; i < all.length && i < budget && n < 40; i++) {
      var el = all[i];
      if (done && done.has(el)) continue;
      if (!ownText(el)) continue;
      if (el.closest('#kt-topbar,[data-kt-refresh-snap],[data-kt-allow-overflow]')) continue;
      var cs = w.getComputedStyle(el);
      if (cs.display === 'none' || cs.visibility === 'hidden') continue;
      // Deliberate truncation stays as it is.
      if (cs.overflowX !== 'visible') continue;
      var r = el.getBoundingClientRect();
      if (r.width < 2 || r.height < 2) continue;
      var range = d.createRange(); range.selectNodeContents(el);
      var tr = range.getBoundingClientRect();
      if (!tr.width) continue;

      // The box the text belongs in: the element itself if it is bordered/filled, else the
      // nearest such ancestor. Stop (and leave alone) at a scroller or a deliberate clip.
      var box = null, chain = [];
      if (boxed(cs) && el.tagName !== 'A') box = el;
      else {
        for (var p = el.parentElement; p && p !== main; p = p.parentElement) {
          var ps = w.getComputedStyle(p);
          if (ps.overflowX === 'auto' || ps.overflowX === 'scroll') { box = null; chain = null; break; }
          if (ps.overflowX !== 'visible' && ps.textOverflow === 'ellipsis') { box = null; chain = null; break; }
          chain.push(p);
          if (boxed(ps)) { box = p; break; }
        }
      }
      if (!box || !chain) continue;
      var br = box.getBoundingClientRect();
      var bs = w.getComputedStyle(box);
      var innerRight = br.right - parseFloat(bs.borderRightWidth || 0);
      if (tr.right <= innerRight + 1.5) continue;
      if (inScroller(box, main)) continue;

      // Fix: let every flex/grid item on the way shrink, then wrap (or ellipsise a pill).
      try {
        var nodes = [el].concat(chain);
        for (var k = 0; k < nodes.length; k++) {
          var nd = nodes[k], par = nd.parentElement;
          if (!par || nd === box) continue;
          var pd = w.getComputedStyle(par).display;
          if (/flex|grid/.test(pd)) { nd.style.minWidth = '0'; }
        }
        if (el !== box && /flex|grid/.test(w.getComputedStyle(el.parentElement).display)) el.style.minWidth = '0';
        var pill = cs.whiteSpace === 'nowrap' && (/inline/.test(w.getComputedStyle(box).display) || br.height <= 40);
        if (pill) {
          el.style.maxWidth = '100%';
          el.style.overflow = 'hidden';
          el.style.textOverflow = 'ellipsis';
          if (!el.title) el.title = (el.textContent || '').replace(/\s+/g, ' ').trim();
          if (box !== el) { box.style.maxWidth = '100%'; box.style.minWidth = '0'; }
        } else {
          el.style.whiteSpace = 'normal';
          el.style.overflowWrap = 'anywhere';
        }
        if (done) done.add(el);
        n++;
        fixed.push({ hash: w.location.hash, el: label(el), by: Math.round(tr.right - innerRight), as: pill ? 'ellipsis' : 'wrap' });
        if (fixed.length > 200) fixed.shift();
      } catch (e) { /* never break a page */ }
    }
    if (n && w.KT && typeof w.KT.crumb === 'function') {
      try { w.KT.crumb('layout', 'overflow guard fixed ' + n + ' element(s)'); } catch (e) {}
    }
  }

  function soon() {
    if (timer) return;
    timer = w.setTimeout(function () {
      if (w.requestIdleCallback) w.requestIdleCallback(pass, { timeout: 1200 }); else pass();
    }, 450);
  }

  w.KT.overflowGuard = { run: pass, report: function () { return fixed.slice(); } };
  function start() {
    if (w.KT && typeof w.KT.observeMain === 'function') {
      w.KT.observeMain(soon, { childList: true, subtree: true, characterData: true });
    }
    w.addEventListener('resize', soon);
    w.addEventListener('hashchange', soon);
    soon();
  }
  if (d.readyState === 'loading') d.addEventListener('DOMContentLoaded', start); else start();
})(window);
