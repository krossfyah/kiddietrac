/* kt-pinch-zoom.js — pinch to zoom inside document and photo popups (2026-10-20).

   Anthony: "have ability to pinch zoom when opening up forms/documents etc in the popup -
   full sweep".

   WHY THE PHONE'S OWN PINCH DID NOTHING
   The popups draw PDFs onto canvases at the width of the panel, and the app's WebView does
   not zoom the page, so a two-finger pinch had nothing to act on: the form stayed at
   phone width, and small print stayed small. In a phone browser the page would zoom, but
   the whole portal zoomed with it, the header and buttons sliding away, not the document.

   So zooming happens INSIDE the popup's scrolling pane:
     - two-finger pinch (touch), anchored where the fingers are;
     - a trackpad pinch or Ctrl/⌘ + scroll wheel on a computer (browsers report a trackpad
       pinch as a Ctrl+wheel);
     - double-tap to zoom in to 2.5x there, and again to go back;
     - a small − 100% + control in the corner, for anyone who doesn't know the gesture.
   Zoomed in, one finger pans around the page as normal scrolling.

   Two ways to scale, chosen per pane:
     mode 'zoom' — CSS zoom on every page in the pane (PDF canvases, form pages with their
                   fill-in boxes), so the layout grows and the pane scrolls over it; boxes on
                   a form stay where they belong and can still be typed into.
     mode 'size' — one picture: its width and height are set, so it never blurs from
                   scaling a small box, and the pane switches from "centred" to scrollable.

   KT.pinchZoom(pane, { mode, target, max, onSettle, control }) -> { zoom(), set(z), reset() }
   onSettle(z) runs once a gesture ends, which is when a PDF pane re-draws its pages at the
   new resolution so text is sharp rather than stretched. */
(function (w, d) {
  'use strict';
  w.KT = w.KT || {};
  if (w.KT.pinchZoom) { return; }

  function clamp(v, a, b) { return Math.max(a, Math.min(b, v)); }
  function dist(t) { return Math.hypot(t[0].clientX - t[1].clientX, t[0].clientY - t[1].clientY); }
  function mid(t) { return { x: (t[0].clientX + t[1].clientX) / 2, y: (t[0].clientY + t[1].clientY) / 2 }; }
  function typing(el) {
    return !!(el && el.closest && el.closest('input,textarea,select,[contenteditable="true"],button,a'));
  }

  function pinchZoom(pane, opts) {
    if (!pane) { return null; }
    if (pane.__ktPZ) { return pane.__ktPZ; }
    opts = opts || {};
    var mode = opts.mode || 'zoom';
    var MAX = opts.max || 5, MIN = 1;
    var z = 1;
    var lastPinchAt = 0;
    var saved = null;      // size mode: the pane's layout before zooming
    var base = null;       // size mode: the picture's size at 1x

    function target() {
      return typeof opts.target === 'function' ? opts.target() : (opts.target || null);
    }
    function pages() {
      return Array.prototype.filter.call(pane.children, function (el) {
        return !el.classList.contains('kt-pz-ctl') && el.tagName !== 'STYLE' && el.tagName !== 'SCRIPT';
      });
    }

    /* WHERE ON WHICH PAGE (2026-10-28, "the pinch zoom ... isn't working accurately"). The spot
       under the fingers is MEASURED: the page (or picture) nearest the point, and the point as a
       fraction of that page's box. After each step the scroll is corrected so the same spot is
       back under the fingers - exact whatever the centring, gaps or padding around the pages. */
    function anchorAt(cx, cy) {
      var els = mode === 'size' ? [target()] : pages();
      var best = null, bd = Infinity;
      els.forEach(function (el) {
        if (!el) { return; }
        var q = el.getBoundingClientRect();
        if (!q.width || !q.height) { return; }
        var dx = cx < q.left ? q.left - cx : (cx > q.right ? cx - q.right : 0);
        var dy = cy < q.top ? q.top - cy : (cy > q.bottom ? cy - q.bottom : 0);
        var dd = dx * dx + dy * dy;
        if (dd < bd) { bd = dd; best = { el: el, fx: (cx - q.left) / q.width, fy: (cy - q.top) / q.height }; }
      });
      return best;
    }
    function pinTo(a, cx, cy) {
      if (!a || !a.el || !a.el.isConnected) { return; }
      var q = a.el.getBoundingClientRect();
      pane.scrollLeft += (q.left + a.fx * q.width) - cx;
      pane.scrollTop += (q.top + a.fy * q.height) - cy;
    }

    /* Keep the point under the fingers (or cursor) under them while scaling. */
    function apply(nz, cx, cy, anchor) {
      nz = clamp(nz, MIN, MAX);
      var r = pane.getBoundingClientRect();
      if (cx == null) { cx = r.left + r.width / 2; cy = r.top + r.height / 2; }
      var a = anchor || anchorAt(cx, cy);
      if (Math.abs(nz - z) < 0.002) { if (z > 1) { pinTo(a, cx, cy); } return; }   // fingers moved, scale did not: pan
      var px = cx - r.left, py = cy - r.top;

      if (mode === 'size') {
        var t = target();
        if (!t) { return; }
        var tr = t.getBoundingClientRect();
        if (!tr.width || !tr.height) { return; }
        if (z === 1) { base = { w: tr.width, h: tr.height }; }
        var fx = clamp((r.left + px - tr.left) / tr.width, 0, 1);
        var fy = clamp((r.top + py - tr.top) / tr.height, 0, 1);
        if (nz > 1 && !saved) {
          saved = { pane: pane.style.cssText, t: t.style.cssText };
          pane.style.display = 'block';
          pane.style.overflow = 'auto';
          pane.style.textAlign = 'left';
          t.style.maxWidth = 'none'; t.style.maxHeight = 'none';
          t.style.display = 'block';
        }
        z = nz;
        if (z <= 1.001) {
          z = 1;
          if (saved) { pane.style.cssText = saved.pane; t.style.cssText = saved.t; saved = null; }
          pane.scrollLeft = 0; pane.scrollTop = 0;
        } else {
          var nw = base.w * z, nh = base.h * z;
          t.style.width = nw + 'px'; t.style.height = nh + 'px';
          // Centred while smaller than the pane, from the edge once it is larger.
          var mx = Math.max(0, (pane.clientWidth - nw) / 2), my = Math.max(0, (pane.clientHeight - nh) / 2);
          t.style.margin = my + 'px ' + mx + 'px';
          pane.scrollLeft = mx + fx * nw - px;
          pane.scrollTop = my + fy * nh - py;
          pinTo(a, cx, cy);
        }
      } else {
        z = nz <= 1.001 ? 1 : nz;
        pages().forEach(function (el) { el.style.zoom = z === 1 ? '' : String(z); });
        if (z === 1) { pane.scrollLeft = 0; }
        pinTo(a, cx, cy);
      }
      paint();
    }

    var settleT = null;
    function settle() {
      clearTimeout(settleT);
      settleT = setTimeout(function () { try { if (opts.onSettle) { opts.onSettle(z); } } catch (e) {} }, 260);
    }

    // Pages added later (a long PDF still drawing) take the current zoom.
    if (mode === 'zoom' && w.MutationObserver) {
      new MutationObserver(function () {
        if (z === 1) { return; }
        pages().forEach(function (el) { if (el.style.zoom !== String(z)) { el.style.zoom = String(z); } });
      }).observe(pane, { childList: true });
    }

    /* ── touch: pinch, and double-tap ── */
    var pinch = null, raf = 0, pend = null, tap = null;
    pane.style.touchAction = 'pan-x pan-y';   // our pinch, the browser's one-finger scroll
    /* INSTANT SCROLL IN A ZOOM PANE (2026-10-28, "the pinch zoom ... isn't working accurately"). The portal
       sets scroll-behavior:smooth everywhere, so every scroll correction during a pinch only STARTED an
       animation - the next step read the old position and the page slid away from the fingers. */
    pane.style.setProperty('scroll-behavior', 'auto', 'important');
    pane.addEventListener('touchstart', function (e) {
      if (e.touches.length === 2) {
        var m0 = mid(e.touches);
        pinch = { d0: dist(e.touches) || 1, z0: z, a: anchorAt(m0.x, m0.y) };
        tap = null;
        return;
      }
      if (e.touches.length === 1 && !typing(e.target)) {
        var t = e.touches[0], now = Date.now();
        if (tap && now - tap.t < 300 && Math.hypot(t.clientX - tap.x, t.clientY - tap.y) < 30) {
          e.preventDefault();
          apply(z > 1.05 ? 1 : 2.5, t.clientX, t.clientY);
          settle();
          tap = null;
          return;
        }
        tap = { t: now, x: t.clientX, y: t.clientY };
      }
    }, { passive: false });
    pane.addEventListener('touchmove', function (e) {
      if (tap && e.touches.length === 1) {
        var t = e.touches[0];
        if (Math.hypot(t.clientX - tap.x, t.clientY - tap.y) > 12) { tap = null; }
      }
      if (!pinch || e.touches.length !== 2) { return; }
      e.preventDefault();
      var m = mid(e.touches);
      pend = { z: pinch.z0 * dist(e.touches) / pinch.d0, x: m.x, y: m.y, a: pinch.a };
      lastPinchAt = Date.now();
      if (!raf) {
        raf = w.requestAnimationFrame(function () {
          raf = 0;
          if (pend) { apply(pend.z, pend.x, pend.y, pend.a); pend = null; }
        });
      }
    }, { passive: false });
    function endTouch(e) {
      if (pinch && e.touches.length < 2) { pinch = null; lastPinchAt = Date.now(); settle(); }
    }
    pane.addEventListener('touchend', endTouch);
    pane.addEventListener('touchcancel', endTouch);
    // iOS Safari sends its own gesture events for a pinch; ours handles it instead.
    pane.addEventListener('gesturestart', function (e) { e.preventDefault(); });

    /* ── computer: trackpad pinch / Ctrl+wheel ── */
    pane.addEventListener('wheel', function (e) {
      if (!e.ctrlKey && !e.metaKey) { return; }
      e.preventDefault();
      apply(z * Math.exp(-e.deltaY * 0.0035), e.clientX, e.clientY);
      settle();
    }, { passive: false });

    /* ── the corner control ── */
    var ctl = null, pct = null;
    function paint() {
      if (pct) { pct.textContent = Math.round(z * 100) + '%'; }
      if (ctl) { ctl.setAttribute('data-zoomed', z > 1 ? '1' : '0'); }
    }
    if (opts.control !== false) {
      var host = opts.controlHost || pane.parentNode;
      if (host) {
        if (w.getComputedStyle(host).position === 'static') { host.style.position = 'relative'; }
        if (!d.getElementById('kt-pz-css')) {
          var st = d.createElement('style'); st.id = 'kt-pz-css';
          st.textContent = '.kt-pz-ctl{position:absolute;right:12px;bottom:12px;z-index:6;display:flex;align-items:center;gap:2px;'
            + 'background:rgba(15,23,42,.78);color:#fff;border-radius:999px;padding:3px;box-shadow:0 4px 14px rgba(0,0,0,.3);'
            + 'font:700 12px/1 system-ui,-apple-system,"Segoe UI",sans-serif;user-select:none;-webkit-user-select:none;}'
            + '.kt-pz-ctl button{all:unset;box-sizing:border-box;cursor:pointer;width:30px;height:30px;border-radius:50%;display:flex;'
            + 'align-items:center;justify-content:center;font-size:17px;color:#fff;}'
            + '.kt-pz-ctl button:hover{background:rgba(255,255,255,.16);}'
            + '.kt-pz-ctl .kt-pz-pct{min-width:44px;width:auto;font-size:12px;padding:0 4px;border-radius:999px;}'
            + '@media print{.kt-pz-ctl{display:none !important;}}';
          d.head.appendChild(st);
        }
        ctl = d.createElement('div');
        ctl.className = 'kt-pz-ctl';
        ctl.setAttribute('data-kt-iconized', '1');
        ctl.innerHTML = '<button type="button" class="kt-pz-out" aria-label="Zoom out" data-kt-iconized="1">−</button>'
          + '<button type="button" class="kt-pz-pct" aria-label="Reset zoom" title="Back to 100%" data-kt-iconized="1">100%</button>'
          + '<button type="button" class="kt-pz-in" aria-label="Zoom in" data-kt-iconized="1">+</button>';
        pct = ctl.querySelector('.kt-pz-pct');
        function stop(e) { e.stopPropagation(); }
        ctl.addEventListener('click', stop);
        ctl.querySelector('.kt-pz-out').onclick = function (e) { e.stopPropagation(); apply(z / 1.4); settle(); };
        ctl.querySelector('.kt-pz-in').onclick = function (e) { e.stopPropagation(); apply(z * 1.4); settle(); };
        pct.onclick = function (e) { e.stopPropagation(); apply(1); settle(); };
        host.appendChild(ctl);
        /* Over the bottom-right of the PANE, wherever it sits in its dialog: a note box or a
           button row under the pane must stay clear. */
        var place = function () {
          if (!ctl.isConnected) { w.removeEventListener('resize', place); return; }
          var hr = host.getBoundingClientRect(), pr = pane.getBoundingClientRect();
          if (!pr.height) { return; }
          ctl.style.bottom = 'auto';
          ctl.style.right = Math.max(8, hr.right - pr.right + 12) + 'px';
          ctl.style.top = Math.max(8, pr.bottom - hr.top - 48) + 'px';
        };
        w.requestAnimationFrame(place);
        w.addEventListener('resize', place);
        if (w.ResizeObserver) { new w.ResizeObserver(place).observe(pane); }
      }
    }

    var api = {
      zoom: function () { return z; },
      set: function (v, cx, cy) { apply(v, cx, cy); settle(); },
      reset: function () { if (z !== 1) { apply(1); } base = null; },
      /* True for a moment after a pinch, so a photo viewer's swipe does not also fire. */
      busy: function () { return z > 1.001 || Date.now() - lastPinchAt < 350 || !!pinch; },
      control: ctl
    };
    pane.__ktPZ = api;
    return api;
  }

  w.KT.pinchZoom = pinchZoom;
})(window, document);
