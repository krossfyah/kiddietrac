/* ═══════════════════════════════════════════════════════════════════
   KiddieTrac — buttons never sit on top of each other on a phone (2026-10-23)

   Anthony: "under my settings in mobile and notifications you will see two buttons too close
   together - needs full sweep to check all buttons for this type of behaviour". On a phone a
   row of buttons wraps, and screens written for a desktop row leave the wrapped buttons
   touching, or stacked with a sliver between them. Editing every screen is the fix that does
   not hold, so this measures, like kt-tab-band.js and kt-overflow-guard.js:

     - Two buttons stacked (they overlap sideways) with less than 14px between them: the
       lower one gets the missing space as margin-top.
     - Two buttons side by side on one line with less than 8px between them: the right one
       gets the missing space as margin-left.
     - Only on a phone or in the app. Tab strips, segmented controls, tables, the audio
       player and anything inside [data-kt-nobreathe] are left alone.
   ═══════════════════════════════════════════════════════════════════ */
(function (w, d) {
  'use strict';
  if (w.__ktBreathe) return; w.__ktBreathe = true;

  var V_GAP = 14, H_GAP = 8;
  var SKIP = '[role=tablist], .kt-tabband, [data-kt-noband], [data-kt-nobreathe], .kt-ap, table, #kt-mobilenav, #appSidebar, #kt-topbar, .kdp-tabs, .kt-dp-grid, .kt-dp-keys, [role=group], [class*="seg"]'
    /* Emoji pickers, the ＋ menu and reaction rows are fixed grids of tiles, not rows of buttons: pushing one tile
       down or across broke the grid and made the picker jump after it opened (2026-11-05). */
    + ', #kt-st-emoji, .kt-emoji-panel, .kt-emoji-bar, .kt-plus-menu, .kt-react-pick, .kt-st-reactions, .kt-msg-reactions';

  function phone() {
    try { var C = w.Capacitor; if (C && (C.isNativePlatform ? C.isNativePlatform() : C.isNative)) return true; } catch (e) {}
    return w.innerWidth <= 768;
  }
  function near(a, b) {   // share an ancestor within two levels
    var pa = a.parentElement, pb = b.parentElement;
    if (!pa || !pb) return false;
    return pa === pb || pa === pb.parentElement || pa.parentElement === pb || (pa.parentElement && pa.parentElement === pb.parentElement);
  }

  function pass() {
    timer = 0;
    if (!phone()) return;
    var roots = [d.getElementById('appMain'), d.getElementById('modalRoot')].filter(Boolean);
    var bs = [];
    roots.forEach(function (root) {
      root.querySelectorAll('button, a.btn, [role=button]').forEach(function (b) {
        if (bs.length > 400 || b.closest(SKIP)) return;
        var r = b.getBoundingClientRect();
        if (r.width < 24 || r.height < 20) return;   // icons and close crosses are not "buttons" here
        bs.push({ el: b, r: r });
      });
    });
    /* PERF (phone speed sweep 2026-10-30): every button was compared with every other (up to 400 x 400), and each
       margin was read straight after the previous write, re-calculating the page's styles each time. Now only
       buttons whose tops are close enough to matter are compared (sorted by top), and every change is decided
       first and written after. Same rules, same results: a button is still adjusted at most once per direction. */
    bs.sort(function (x, y) { return x.r.top - y.r.top; });
    var maxH = 0;
    for (var k = 0; k < bs.length; k++) if (bs[k].r.height > maxH) maxH = bs[k].r.height;
    var plan = [], doneV = new Set(), doneH = new Set();
    for (var i = 0; i < bs.length; i++) {
      var a = bs[i];
      for (var j = 0; j < bs.length; j++) {
        if (i === j) continue;
        var b = bs[j];
        if (b.r.top < a.r.top - maxH) continue;
        if (b.r.top >= a.r.bottom + V_GAP) break;
        var ho = Math.min(a.r.right, b.r.right) - Math.max(a.r.left, b.r.left);
        var vo = Math.min(a.r.bottom, b.r.bottom) - Math.max(a.r.top, b.r.top);
        if (ho > Math.min(a.r.width, b.r.width) * 0.4) {
          var gv = b.r.top - a.r.bottom;
          if (gv >= -1 && gv < V_GAP && near(a.el, b.el) && !doneV.has(b.el) && !b.el.hasAttribute('data-kt-breathe-v')) {
            doneV.add(b.el);
            plan.push([b.el, 'margin-top', (parseFloat(getComputedStyle(b.el).marginTop) || 0) + (V_GAP - gv), 'data-kt-breathe-v']);
          }
        } else if (vo > Math.min(a.r.height, b.r.height) * 0.5) {
          var gh = b.r.left - a.r.right;
          if (gh >= -1 && gh < H_GAP && near(a.el, b.el) && !doneH.has(b.el) && !b.el.hasAttribute('data-kt-breathe-h')) {
            doneH.add(b.el);
            plan.push([b.el, 'margin-left', (parseFloat(getComputedStyle(b.el).marginLeft) || 0) + (H_GAP - gh), 'data-kt-breathe-h']);
          }
        }
      }
    }
    for (var q = 0; q < plan.length; q++) {
      plan[q][0].style.setProperty(plan[q][1], Math.round(plan[q][2]) + 'px', 'important');
      plan[q][0].setAttribute(plan[q][3], '1');
    }
  }
  var timer = 0;
  function soon() { if (!timer) timer = w.setTimeout(function () { if (w.requestIdleCallback) w.requestIdleCallback(pass, { timeout: 900 }); else pass(); }, 380); }

  function start() {
    if (w.KT && typeof w.KT.observeMain === 'function') w.KT.observeMain(soon, { childList: true, subtree: true });
    var mr = d.getElementById('modalRoot');
    if (mr) try { new MutationObserver(soon).observe(mr, { childList: true, subtree: true }); } catch (e) {}
    w.addEventListener('resize', soon);
    w.addEventListener('hashchange', soon);
    soon();
  }
  if (d.readyState === 'loading') d.addEventListener('DOMContentLoaded', start); else start();
  w.KT = w.KT || {}; w.KT.buttonBreathe = { run: pass };
})(window, document);
