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
      if (r.width < 40 || (r.right <= right && r.left >= left)) continue;
      if (inScroller(el, main)) continue;
      var cs = w.getComputedStyle(el);
      if (cs.position === 'fixed' || cs.position === 'absolute') continue;
      el.__ktFit = true;
      if (cs.display.indexOf('grid') > -1) {
        set(el, 'grid-template-columns', 'minmax(0, 1fr)'); n++;
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
        if (pcs.display.indexOf('grid') > -1 && !p.__ktFit) { p.__ktFit = true; set(p, 'grid-template-columns', 'minmax(0, 1fr)'); }
      }
    }
    // Form controls with a fixed width (selects especially) never wider than their row.
    [].forEach.call(main.querySelectorAll('select,input:not([type=checkbox]):not([type=radio]),textarea'), function (f) {
      if (f.__ktFitF) return;
      var r = f.getBoundingClientRect();
      if (r.right > right || r.left < left) { f.__ktFitF = true; set(f, 'max-width', '100%'); set(f, 'min-width', '0px'); }
    });
  }

  function restore() {
    for (var i = touched.length - 1; i >= 0; i--) {
      var t = touched[i];
      if (t[1] === '__mark') continue;
      try {
        if (t[2]) t[0].style.setProperty(t[1], t[2], t[3]); else t[0].style.removeProperty(t[1]);
      } catch (e) {}
      t[0].__ktGutter = t[0].__ktFit = t[0].__ktFitP = t[0].__ktFitF = false;
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
    try {
      [].forEach.call(main.children, function (c) { flattenWrapper(c, 0); });
      fitOverflow(main);
    } catch (e) {}
  }
  function schedule(ms) { if (timer) return; timer = setTimeout(pass, ms == null ? 60 : ms); }

  w.KT.phoneGutter = {
    run: pass,
    report: function () {
      return touched.filter(function (t) { return t[1] !== '__mark'; }).map(function (t) {
        var e = t[0];
        return e.tagName.toLowerCase() + (typeof e.className === 'string' && e.className.trim() ? '.' + e.className.trim().split(/\s+/)[0] : '') + ' ' + t[1];
      });
    },
  };

  function start() {
    if (w.KT.observeMain) w.KT.observeMain(function () { schedule(); }, { childList: true, subtree: true });
    else {
      try { new MutationObserver(function () { schedule(); }).observe(d.body, { childList: true, subtree: true }); } catch (e) {}
    }
    w.addEventListener('resize', function () { schedule(150); });
    w.addEventListener('hashchange', function () { schedule(300); });
    schedule(200);
    // Screens render in stages (data arrives late): a few follow-up passes after load.
    [800, 2000, 4000].forEach(function (t) { setTimeout(function () { schedule(0); }, t); });
  }
  if (d.readyState === 'loading') d.addEventListener('DOMContentLoaded', start); else start();
})(window);
