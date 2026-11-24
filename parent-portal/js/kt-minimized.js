/* ═══════════════════════════════════════════════════════════════════
   KiddieTrac — minimised windows: movable, and never on top of each other (2026-10-23)

   Anthony: "when chat is minimized, make it where I can move the minimized chat or
   anything else minimized around and make it where it doesnt overlap with other
   minimized windows".

   The minimised windows are listed in MINIS: the chat dock's bar and the dial pad's pill.
   While one is minimised, this places it through two CSS variables and a class
   (kt-min-placed) whose rule only applies in the minimised state, so restoring the window
   hands it back to its own open position untouched. Drag a minimised window anywhere
   (mouse or touch); where it is left is remembered on this device. Placing one never lets
   it sit on another: it moves to the nearest free spot beside it. A drag is never taken
   as a click (which would open the window).

   To add another minimisable window: one line in MINIS, its id and its minimised class.
   ═══════════════════════════════════════════════════════════════════ */
(function (w) {
  'use strict';
  if (w.__ktMinimized) return; w.__ktMinimized = true;

  var MINIS = [
    { id: 'kt-chat-dock', cls: 'kt-cd-mini' },
    { id: 'kt-dpop', cls: 'min' },
  ];
  var KEY = 'kt_min_pos', GAP = 10, EDGE = 12;

  (function css() {
    var st = document.createElement('style'); st.id = 'kt-min-css';
    st.textContent = MINIS.map(function (m) {
      var s = '#' + m.id + '.' + m.cls + '.kt-min-placed';
      return s + '{left:var(--kt-min-x) !important;top:var(--kt-min-y) !important;right:auto !important;bottom:auto !important;transform:none !important;}'
        + '#' + m.id + '.' + m.cls + '{touch-action:none;}';
    }).join('') + '.kt-min-dragging{opacity:.92;box-shadow:0 18px 40px -10px rgba(15,23,42,.5) !important;transition:none !important;animation:none !important;cursor:grabbing !important}'
      + 'html.kt-min-noselect,html.kt-min-noselect *{user-select:none !important;-webkit-user-select:none !important}';
    (document.head || document.documentElement).appendChild(st);
  })();

  function saved() { try { return JSON.parse(localStorage.getItem(KEY) || '{}') || {}; } catch (e) { return {}; } }
  function save(id, x, y) { var s = saved(); s[id] = { x: Math.round(x), y: Math.round(y) }; try { localStorage.setItem(KEY, JSON.stringify(s)); } catch (e) {} }
  function minimised() {
    return MINIS.map(function (m) {
      var el = document.getElementById(m.id);
      return el && el.classList.contains(m.cls) && getComputedStyle(el).display !== 'none' ? el : null;
    }).filter(Boolean);
  }
  function place(el, x, y) {
    el.style.setProperty('--kt-min-x', Math.round(x) + 'px');
    el.style.setProperty('--kt-min-y', Math.round(y) + 'px');
    el.classList.add('kt-min-placed');
  }
  function clamp(v, lo, hi) { return Math.max(lo, Math.min(hi, v)); }
  function hits(a, b) { return a.x < b.x + b.w + GAP && b.x < a.x + a.w + GAP && a.y < b.y + b.h + GAP && b.y < a.y + a.h + GAP; }
  function bottomLimit() { return innerHeight - (innerWidth <= 768 ? 84 : 0) - EDGE; }

  /* Lay out every minimised window. The others keep their places; the one just dropped
     (`moving`, remembered until the next drop) goes to the nearest free spot if it landed
     on one of them: right to left, then a row up. */
  var lastMoved = null;
  function arrange(moving) {
    if (drag && drag.moved) return;          // never while one is being dragged
    var els = minimised();
    if (!els.length) return;
    moving = moving || lastMoved;
    if (moving && els.indexOf(moving) !== -1) { els.splice(els.indexOf(moving), 1); els.push(moving); }
    var s = saved(), taken = [];
    els.forEach(function (el) {
      var r = el.getBoundingClientRect();
      var box = { w: Math.max(40, r.width), h: Math.max(30, r.height) };
      var p = s[el.id];
      box.x = p ? p.x : innerWidth - box.w - EDGE - 6;
      box.y = p ? p.y : bottomLimit() - box.h;
      box.x = clamp(box.x, EDGE, innerWidth - box.w - EDGE);
      box.y = clamp(box.y, EDGE, bottomLimit() - box.h);
      for (var guard = 0; guard < 60 && taken.some(function (t) { return hits(box, t); }); guard++) {
        var blocker = taken.filter(function (t) { return hits(box, t); })[0];
        var left = blocker.x - box.w - GAP;
        if (left >= EDGE) { box.x = left; }
        else { box.x = innerWidth - box.w - EDGE - 6; box.y = blocker.y - box.h - GAP; }
        if (box.y < EDGE) { box.y = bottomLimit() - box.h; box.x = EDGE; break; }
      }
      place(el, box.x, box.y);
      if (p && (Math.round(box.x) !== p.x || Math.round(box.y) !== p.y)) save(el.id, box.x, box.y);
      taken.push(box);
    });
  }

  /* Dragging a minimised window. Buttons on it (restore, close) still click. */
  var drag = null, swallowClick = false;
  document.addEventListener('pointerdown', function (e) {
    var el = e.target && e.target.closest && e.target.closest(MINIS.map(function (m) { return '#' + m.id + '.' + m.cls; }).join(','));
    if (!el || e.button > 0 || e.target.closest('button')) return;
    var r = el.getBoundingClientRect();
    drag = { el: el, sx: e.clientX, sy: e.clientY, ox: r.left, oy: r.top, moved: false, id: e.pointerId };
  }, true);
  document.addEventListener('pointermove', function (e) {
    if (!drag || e.pointerId !== drag.id) return;
    var dx = e.clientX - drag.sx, dy = e.clientY - drag.sy;
    if (!drag.moved && Math.abs(dx) + Math.abs(dy) < 5) return;
    if (!drag.moved) {
      drag.moved = true;
      var r0 = drag.el.getBoundingClientRect();
      drag.w = r0.width; drag.h = r0.height;
      drag.el.classList.add('kt-min-dragging');
      document.documentElement.classList.add('kt-min-noselect');
      try { drag.el.setPointerCapture(e.pointerId); } catch (x) {}
    }
    drag.nx = clamp(drag.ox + dx, 0, innerWidth - drag.w);
    drag.ny = clamp(drag.oy + dy, 0, innerHeight - drag.h);
    if (!drag.raf) {
      drag.raf = requestAnimationFrame(function () {
        if (!drag) return;
        drag.raf = 0;
        place(drag.el, drag.nx, drag.ny);
      });
    }
    e.preventDefault();
  }, true);
  function end(e) {
    if (!drag || (e && e.pointerId !== drag.id)) return;
    var d = drag; drag = null;
    if (!d.moved) return;
    if (d.raf) { cancelAnimationFrame(d.raf); }
    place(d.el, d.nx, d.ny);                  // exactly where it was let go
    d.el.classList.remove('kt-min-dragging');
    document.documentElement.classList.remove('kt-min-noselect');
    save(d.el.id, d.nx, d.ny);
    swallowClick = true; setTimeout(function () { swallowClick = false; }, 350);
    lastMoved = d.el;
    arrange(d.el);
  }
  document.addEventListener('pointerup', end, true);
  document.addEventListener('pointercancel', end, true);
  // A drag that ends on the window must not also open it.
  document.addEventListener('click', function (e) {
    if (!swallowClick) return;
    if (e.target && e.target.closest && e.target.closest(MINIS.map(function (m) { return '#' + m.id; }).join(','))) { e.stopPropagation(); e.preventDefault(); swallowClick = false; }
  }, true);

  // Whenever a window is minimised, restored, opened or closed: lay them out again.
  var t = null;
  function soon() { clearTimeout(t); t = setTimeout(function () { arrange(); }, 60); }
  try {
    var OWN = /^kt-min-/;
    var relevant = function (m) {
      var before = String(m.oldValue || '').split(/\s+/).filter(Boolean), after = String(m.target.className || '').split(/\s+/).filter(Boolean);
      var changed = before.filter(function (c) { return after.indexOf(c) === -1; }).concat(after.filter(function (c) { return before.indexOf(c) === -1; }));
      return changed.some(function (c) { return !OWN.test(c); });
    };
    new MutationObserver(function (muts) {
      if (drag && drag.moved) return;
      for (var i = 0; i < muts.length; i++) {
        var m = muts[i], n = m.target;
        if (m.type === 'attributes' && n.id && MINIS.some(function (x) { return x.id === n.id; }) && relevant(m)) { soon(); return; }
        if (m.type === 'childList') {
          var all = [].slice.call(m.addedNodes).concat([].slice.call(m.removedNodes));
          if (all.some(function (a) { return a.id && MINIS.some(function (x) { return x.id === a.id; }); })) { soon(); return; }
        }
      }
    }).observe(document.body || document.documentElement, { attributes: true, attributeFilter: ['class'], attributeOldValue: true, subtree: true, childList: true });
  } catch (e) {}
  w.addEventListener('resize', soon);

  w.KT = w.KT || {};
  w.KT.minimized = { arrange: arrange, register: function (id, cls) { MINIS.push({ id: id, cls: cls }); soon(); } };
})(window);
