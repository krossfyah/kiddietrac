/* ═══════════════════════════════════════════════════════════════════════
   kt-phone-gutter.js — every screen uses the full phone width (2026-10-10)

   Anthony: "for mobile apk view make sure all sections are utilizing full screen as I
   noticed some are still not utilizing the full available space - full sweep".

   Measured at 390px across all five roles (153 screens): the shell already gives #appMain
   ONE 10px gutter, and most screens fill it (cards 370px wide, 10px in). The rest wrap
   their content in their own desktop page padding — `padding:20px 24px;max-width:1800px`,
   `max-width:640px;margin:0 auto;padding:16px`, `.qb-wrap`, `.director-shell` … — so on a
   phone the cards sat 20–34px in (as narrow as 322px of 390). kt-mobile-app.css flattened
   only the inline spellings it listed; every new screen needed another line.

   So the rule is applied by what an element IS, not how its style is spelled: on a phone
   (≤768px, the same breakpoint as kt-mobile-app.css), a TRANSPARENT wrapper at the top of
   #appMain (no background, border or shadow — i.e. page padding, never a card's own padding)
   loses its side padding and its max-width. Cards keep theirs. Two levels deep, because
   several screens nest a pane in a page.

   And the second half of "make this right": a grid or flex row that runs past the edge of
   the screen (outside a deliberate horizontal scroller, e.g. a wide table) is told to fit —
   grid tracks become one shrinkable column, flex rows wrap and their items may shrink. The
   sweep found Lesson plans' toolbar (443px), Help's content column, Allergy alerts' sort
   menu (−25px) and Compliance's 380px-minimum cards.

   Desktop is untouched; turning the phone sideways past 768px restores every inline style
   it changed. setTimeout, not rAF: rAF never fires on a hidden tab (memory).
     window.__ktNoPhoneGutter = true   — off
     KT.phoneGutter.report()           — what it changed on this screen
   ═══════════════════════════════════════════════════════════════════════ */
(function (w) {
  'use strict';
  var d = w.document;
  w.KT = w.KT || {};
  var PHONE = 768;
  var touched = [];          // [el, prop, previousValue, previousPriority]
  var timer = null;
  var waits = 0;

  function phone() { return w.innerWidth <= PHONE; }
  function clear(cs) {
    var bg = cs.backgroundColor;
    return (!bg || bg === 'rgba(0, 0, 0, 0)' || bg === 'transparent')
      && (!cs.backgroundImage || cs.backgroundImage === 'none')
      && parseFloat(cs.borderLeftWidth) === 0 && parseFloat(cs.borderRightWidth) === 0
      && (!cs.boxShadow || cs.boxShadow === 'none');
  }
  function skip(el) {
    if (!el || el.nodeType !== 1) return true;
    if (el.id === 'kt-topbar' || el.id === 'kt-day-brief') return true;
    if (el.hasAttribute('data-kt-keep-gutter')) return true;
    var c = typeof el.className === 'string' ? el.className : '';
    return /\bkt-(hero|page-hero)\b|kt-thread|kt-chat-full/.test(c) || el.hasAttribute('data-kt-hero');
  }
  function set(el, prop, val) {
    touched.push([el, prop, el.style.getPropertyValue(prop), el.style.getPropertyPriority(prop)]);
    el.style.setProperty(prop, val, 'important');
  }

  function flattenWrapper(el, depth) {
    if (skip(el) || el.__ktGutter) return;
    var cs = w.getComputedStyle(el);
    if (cs.display === 'none' || cs.position === 'fixed' || cs.position === 'absolute') return;
    if (!clear(cs)) return;                                   // a card: keep its padding
    var changed = false;
    if (parseFloat(cs.paddingLeft) > 0) { set(el, 'padding-left', '0px'); changed = true; }
    if (parseFloat(cs.paddingRight) > 0) { set(el, 'padding-right', '0px'); changed = true; }
    if (cs.maxWidth !== 'none' && cs.maxWidth !== '100%') { set(el, 'max-width', 'none'); changed = true; }
    if (parseFloat(cs.marginLeft) > 0 || parseFloat(cs.marginRight) > 0) {
      set(el, 'margin-left', '0px'); set(el, 'margin-right', '0px'); changed = true;
    }
    el.__ktGutter = true;
    if (changed) touched.push([el, '__mark']);
    if (depth < 2) [].forEach.call(el.children, function (c) { flattenWrapper(c, depth + 1); });
  }

  function inScroller(n, stop) {
    for (var p = n.parentElement; p && p !== stop; p = p.parentElement) {
      var o = w.getComputedStyle(p).overflowX;
      if (o === 'auto' || o === 'scroll') return true;
    }
    return false;
  }

  /* NEVER MEASURE A SCREEN WHILE IT IS MOVING (2026-10-15).

     Anthony, phone screenshots: agency overview cards one per row, the Everything launcher
     one huge tile per row, filters wrapped all over the place — "sometimes". On a phone every
     new screen slides in from translateX(34px) over .3s (kt-mobile-app.css kt-screen-in).
     getBoundingClientRect includes that transform, so a pass that landed mid-slide saw every
     card 34px past the right edge and "fitted" them: grids forced to ONE column, rows forced
     to wrap, for good. Whether it happened depended on timing alone.

     So: no pass while a finite animation is running in #appMain (it waits and looks again),
     the overflow is measured without transforms, and a grid keeps its column count — its
     tracks are only allowed to shrink. A two-up row of cards that is a few pixels too wide
     stays two-up. */
  function moving(main) {
    try {
      var list = main.getAnimations ? main.getAnimations({ subtree: true }) : [];
      for (var i = 0; i < list.length; i++) {
        var a = list[i];
        if (a.playState !== 'running') continue;
        var t = a.effect && a.effect.getComputedTiming ? a.effect.getComputedTiming() : null;
        if (t && t.endTime !== Infinity) return true;      // pulses/shimmers loop forever: ignore
      }
    } catch (e) {}
    return false;
  }
  function shiftX(el, main) {
    var x = 0;
    for (var p = el; p && p !== main; p = p.parentElement) {
      var tr = w.getComputedStyle(p).transform;
      if (tr && tr !== 'none') { try { x += new w.DOMMatrixReadOnly(tr).m41; } catch (e) {} }
    }
    return x;
  }
  function columns(cs) {
    var tracks = String(cs.gridTemplateColumns || '').trim();
    if (!tracks || tracks === 'none') return 1;
    return Math.max(1, tracks.split(/\s+(?![^(]*\))/).length);
  }

  function fitOverflow(main) {
    var limit = main.getBoundingClientRect();
    var cs0 = w.getComputedStyle(main);
    var right = limit.right - parseFloat(cs0.paddingRight) + 1;
    var left = limit.left + parseFloat(cs0.paddingLeft) - 1;
    var n = 0;
    var all = main.querySelectorAll('div,section,form,ul,nav,header,footer,main,table');
    for (var i = 0; i < all.length && n < 30; i++) {
      var el = all[i];
      if (el.__ktFit || el.closest('#kt-chat-dock,.kt-modal,[role=dialog],[data-kt-allow-overflow]')) continue;
      var r = el.getBoundingClientRect();
      if (r.width < 40) continue;
      var lx = r.left - shiftX(el, main), rx = lx + (el.offsetWidth || r.width);
      if (rx <= right && lx >= left) continue;
      if (inScroller(el, main)) continue;
      var cs = w.getComputedStyle(el);
      if (cs.position === 'fixed' || cs.position === 'absolute') continue;
      el.__ktFit = true;
      if (cs.display.indexOf('grid') > -1) {
        var cols = columns(cs);
        set(el, 'grid-template-columns', cols > 1 ? 'repeat(' + cols + ', minmax(0, 1fr))' : 'minmax(0, 1fr)'); n++;
      } else if (cs.display.indexOf('flex') > -1 && cs.flexDirection.indexOf('row') === 0) {
        set(el, 'flex-wrap', 'wrap'); set(el, 'max-width', '100%'); set(el, 'min-width', '0px');
        [].forEach.call(el.children, function (c) { set(c, 'min-width', '0px'); set(c, 'max-width', '100%'); });
        n++;
      } else if (el.tagName !== 'TABLE' && cs.display === 'block') {
        set(el, 'max-width', '100%'); set(el, 'min-width', '0px'); n++;
      }
      // A parent that sized itself to this child (a flex/grid item with min-width:auto) must
      // also be allowed to shrink, or the fix above has nothing to shrink into.
      var p = el.parentElement;
      if (p && p !== main && !p.__ktFitP) {
        p.__ktFitP = true; set(p, 'min-width', '0px');
        // An item too wide for its GRID track: the track's own minimum is the cause
        // (e.g. repeat(auto-fill, minmax(380px, 1fr)) on a 370px screen).
        var pcs = w.getComputedStyle(p);
        if (pcs.display.indexOf('grid') > -1 && !p.__ktFit) {
          p.__ktFit = true;
          var pc = columns(pcs);
          set(p, 'grid-template-columns', pc > 1 ? 'repeat(' + pc + ', minmax(0, 1fr))' : 'minmax(0, 1fr)');
        }
      }
    }
    // Form controls with a fixed width (selects especially) never wider than their row.
    [].forEach.call(main.querySelectorAll('select,input:not([type=checkbox]):not([type=radio]),textarea'), function (f) {
      if (f.__ktFitF) return;
      var r = f.getBoundingClientRect();
      if (r.right > right || r.left < left) { f.__ktFitF = true; set(f, 'max-width', '100%'); set(f, 'min-width', '0px'); }
    });
  }

  /* FILTER ROWS LINE UP (2026-10-15). Anthony: "full sweep on fixing the alignment of filter
     and search and drop down throughout". Screens build their filter rows with desktop
     widths (`width:200px`, `width:180px`, a 260px search), so on a phone they wrapped into
     a ragged staircase. A row is recognised by what it holds — two or more selects / text
     boxes side by side in a flex row — and marked data-kt-filterbar; kt-mobile-app.css
     then gives every dropdown an equal share of the line, the search box a line of its own,
     one height for all of them. */
  var CONTROL = 'select,input[type=search],input[type=text],input[type=date],input[type=month],input:not([type])';
  // Dropdowns and date pickers share a line; a typed search gets one of its own.
  function kind(f) { return (f.tagName === 'SELECT' || /^(date|month)$/.test(f.type)) ? 'pick' : 'text'; }
  function controlCell(c) {
    if (c.matches && c.matches(CONTROL)) return kind(c);
    // A search box wrapped with its magnifier icon (or a label) counts as the box.
    if (c.children.length && c.children.length <= 3 && c.querySelectorAll('select,input,textarea').length === 1) {
      var f = c.querySelector(CONTROL);
      if (f) return kind(f);
    }
    return '';
  }
  function markFilterBars(main) {
    var seen = [];
    [].forEach.call(main.querySelectorAll(CONTROL), function (f) {
      for (var p = f.parentElement, i = 0; p && p !== main && i < 2; p = p.parentElement, i++) {
        if (seen.indexOf(p) > -1) return;
        if (p.hasAttribute('data-kt-filterbar') || p.closest('#kt-topbar,table,.kt-modal,[role=dialog]')) return;
        var cs = w.getComputedStyle(p);
        if (cs.display.indexOf('flex') === -1 || cs.flexDirection.indexOf('row') !== 0) continue;
        var n = 0;
        [].forEach.call(p.children, function (c) {
          var k = controlCell(c);
          if (k) { n++; c.setAttribute('data-kt-fb', k); }
        });
        seen.push(p);
        if (n >= 2) { p.setAttribute('data-kt-filterbar', ''); touched.push([p, '__fb']); return; }
        [].forEach.call(p.querySelectorAll(':scope > [data-kt-fb]'), function (c) { c.removeAttribute('data-kt-fb'); });
      }
    });
  }

  /* EMPTY CARDS DO NOT STAND 400px TALL (2026-10-15). Anthony, phone screenshot: "Team on
     the floor — No staff active right now" in a box most of a screen high: "when there's no
     data why is the box/section so big". Desktop dashboards give their panels one fixed
     height (`height:404px`) so a row of them lines up; stacked one per row on a phone there
     is nothing to line up with. On a phone a CARD with a fixed inline height takes its
     content's height instead, with the old height as its ceiling (the inside still scrolls).
     Charts, maps, media and scroll lists that need the height are left alone. */
  function shrinkFixedCards(main) {
    [].forEach.call(main.querySelectorAll('[style*="height"]'), function (el) {
      if (el.__ktShrunk || el.closest('table,.kt-modal,[role=dialog],[data-kt-keep-height]')) return;
      var h = el.style.height;
      if (!/^\d+(\.\d+)?px$/.test(h) || parseFloat(h) < 160) return;
      if (el.querySelector('canvas,iframe,video,img[src]:not([width]),.leaflet-container,[data-kt-chart]')) return;
      var big = [].some.call(el.querySelectorAll('svg'), function (s) { return s.getBoundingClientRect().width > 120; });
      if (big) return;
      var cs = w.getComputedStyle(el);
      if (cs.position === 'fixed' || cs.position === 'absolute' || clear(cs)) return;
      if (cs.overflowY === 'auto' || cs.overflowY === 'scroll') return;    // the card itself is the scroller
      el.__ktShrunk = true;
      set(el, 'max-height', h);
      set(el, 'height', 'auto');
    });
  }

  function restore() {
    for (var i = touched.length - 1; i >= 0; i--) {
      var t = touched[i];
      if (t[1] === '__mark') continue;
      if (t[1] === '__fb') {
        t[0].removeAttribute('data-kt-filterbar');
        [].forEach.call(t[0].querySelectorAll(':scope > [data-kt-fb]'), function (c) { c.removeAttribute('data-kt-fb'); });
        continue;
      }
      try {
        if (t[2]) t[0].style.setProperty(t[1], t[2], t[3]); else t[0].style.removeProperty(t[1]);
      } catch (e) {}
      t[0].__ktGutter = t[0].__ktFit = t[0].__ktFitP = t[0].__ktFitF = t[0].__ktShrunk = false;
    }
    touched = [];
  }

  function pass() {
    timer = null;
    if (w.__ktNoPhoneGutter) return;
    var main = d.getElementById('appMain');
    if (!main) return;
    if (!phone()) { if (touched.length) restore(); return; }
    // Screens re-render into fresh nodes: forget the detached ones rather than hold them.
    touched = touched.filter(function (t) { return t[0].isConnected; });
    /* A screen rendered INSIDE a popup (the child record's "At a glance") brings its desktop
       page padding with it; the popup already has its gutter (kt-mobile-app.css). */
    try {
      [].forEach.call(d.querySelectorAll('.modal-backdrop .modal-body'), function (b) {
        [].forEach.call(b.children, function (c) { flattenWrapper(c, 0); });
      });
    } catch (e) {}
    if (moving(main) && waits < 20) { waits++; schedule(150); return; }
    waits = 0;
    try {
      [].forEach.call(main.children, function (c) { flattenWrapper(c, 0); });
      markFilterBars(main);
      shrinkFixedCards(main);
      fitOverflow(main);
    } catch (e) {}
  }
  function schedule(ms) { if (timer) return; timer = setTimeout(pass, ms == null ? 60 : ms); }

  w.KT.phoneGutter = {
    run: pass,
    report: function () {
      return touched.filter(function (t) { return t[1] !== '__mark'; }).map(function (t) {
        if (t[1] === '__fb') return 'filter row';
        var e = t[0];
        return e.tagName.toLowerCase() + (typeof e.className === 'string' && e.className.trim() ? '.' + e.className.trim().split(/\s+/)[0] : '') + ' ' + t[1];
      });
    },
  };

  function styles() {
    if (d.getElementById('kt-filterbar-css')) return;
    var s = d.createElement('style');
    s.id = 'kt-filterbar-css';
    s.textContent = '@media (max-width:' + PHONE + 'px){'
      + '#appMain [data-kt-filterbar]{flex-wrap:wrap !important;align-items:center !important;gap:8px !important;}'
      + '#appMain [data-kt-filterbar]>[data-kt-fb]{flex:1 1 130px !important;width:auto !important;min-width:0 !important;max-width:100% !important;margin:0 !important;box-sizing:border-box !important;}'
      + '#appMain [data-kt-filterbar]>[data-kt-fb=text]{flex-basis:100% !important;}'
      + '#appMain [data-kt-filterbar]>select[data-kt-fb],#appMain [data-kt-filterbar]>input[data-kt-fb],'
      + '#appMain [data-kt-filterbar]>[data-kt-fb]>select,#appMain [data-kt-filterbar]>[data-kt-fb]>input{height:40px !important;min-height:40px !important;font-size:14px !important;box-sizing:border-box !important;}'
      + '#appMain [data-kt-filterbar]>[data-kt-fb]>select,#appMain [data-kt-filterbar]>[data-kt-fb]>input{width:100% !important;max-width:100% !important;}'
      + '#appMain [data-kt-filterbar]>:not([data-kt-fb]):not(button):not(a){flex:0 0 auto;}'
      + '#appMain [data-kt-filterbar]>button,#appMain [data-kt-filterbar]>a.btn{min-height:40px;}'
      + '}';
    (d.head || d.documentElement).appendChild(s);
  }

  function start() {
    styles();
    if (w.KT.observeMain) w.KT.observeMain(function () { schedule(); }, { childList: true, subtree: true });
    else {
      try { new MutationObserver(function () { schedule(); }).observe(d.body, { childList: true, subtree: true }); } catch (e) {}
    }
    var popups = d.getElementById('modalRoot');
    if (popups) { try { new MutationObserver(function () { schedule(); }).observe(popups, { childList: true, subtree: true }); } catch (e) {} }
    w.addEventListener('resize', function () { schedule(150); });
    // A stylesheet that arrives late (kt-css-guard puts back a missing one) changes every
    // width on the screen: undo what was fitted without it and measure again.
    d.addEventListener('load', function (e) {
      if (e.target && e.target.tagName === 'LINK' && touched.length) { restore(); schedule(100); }
    }, true);
    w.addEventListener('hashchange', function () { schedule(300); });
    schedule(200);
    // Screens render in stages (data arrives late): a few follow-up passes after load.
    [800, 2000, 4000].forEach(function (t) { setTimeout(function () { schedule(0); }, t); });
  }
  if (d.readyState === 'loading') d.addEventListener('DOMContentLoaded', start); else start();
})(window);
