/* ═══════════════════════════════════════════════════════════════════
   KiddieTrac — KT.busy(el): a dimmed "working…" overlay with a spinner circle.
   Used for actions that hit the network and shouldn't feel like nothing happened
   (child check-in/out, educator clock-in). Overlays the given element with a
   translucent backdrop + a spinning ring, and returns a done() that removes it.

       var done = KT.busy(cardEl);
       try { await something(); } finally { done(); }
   ═══════════════════════════════════════════════════════════════════ */
(function (w) {
  'use strict';
  var KT = w.KT || (w.KT = {});
  if (KT.busy) return;

  function ensureCss() {
    if (document.getElementById('kt-busy-css')) return;
    var s = document.createElement('style');
    s.id = 'kt-busy-css';
    s.textContent =
      '.kt-busy-ovl{position:absolute;inset:0;display:flex;align-items:center;justify-content:center;' +
      'background:rgba(255,255,255,.62);z-index:60;border-radius:inherit;cursor:progress;' +
      'animation:kt-busy-fade .12s ease-out;}' +
      '.kt-busy-ovl .kt-busy-ring{width:26px;height:26px;border-radius:50%;' +
      'border:3px solid rgba(31,96,128,.22);border-top-color:#1F6080;' +
      'animation:kt-busy-spin .7s linear infinite;}' +
      '@keyframes kt-busy-spin{to{transform:rotate(360deg);}}' +
      '@keyframes kt-busy-fade{from{opacity:0;}to{opacity:1;}}';
    document.head.appendChild(s);
  }

  // Overlay `el` with a dimmed backdrop + spinner circle. Returns a done() that removes it.
  KT.busy = function (el) {
    if (!el) return function () {};
    ensureCss();
    var changedPos = false;
    try {
      if (getComputedStyle(el).position === 'static') { el.style.position = 'relative'; changedPos = true; }
    } catch (e) {}
    var ovl = document.createElement('div');
    ovl.className = 'kt-busy-ovl';
    ovl.setAttribute('aria-busy', 'true');
    ovl.innerHTML = '<div class="kt-busy-ring" role="status" aria-label="Working"></div>';
    el.appendChild(ovl);
    var done = false;
    return function () {
      if (done) return;
      done = true;
      if (ovl.parentNode) ovl.parentNode.removeChild(ovl);
      if (changedPos) { try { el.style.position = ''; } catch (e) {} }
    };
  };
})(window);
