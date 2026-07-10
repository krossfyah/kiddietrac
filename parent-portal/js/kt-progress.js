/* ═══════════════════════════════════════════════════════════════════
   KiddieTrac — global request progress (2026-07-09).
   When a form/save/action hits the API, the screen used to just pause with no
   feedback (people hit Submit twice). This shows a slim top progress bar for the
   whole duration of any mutating request (POST/PUT/PATCH/DELETE) — plus, if a
   button triggered it, a spinner + disabled state on that button so it can't be
   double-clicked. Purely a wrapper around window.fetch; no app changes needed.
   ═══════════════════════════════════════════════════════════════════ */
(function () {
  'use strict';
  if (window.__ktProgress) return; window.__ktProgress = true;

  var active = 0, bar = null, creep = null, lastBtn = null, btnTimer = null;

  function ensure() {
    if (bar) return bar;
    var st = document.createElement('style');
    st.textContent =
      '#kt-progress{position:fixed;top:0;left:0;height:3px;width:0;z-index:99999;opacity:0;pointer-events:none;' +
      'background:linear-gradient(90deg,#0E7C90,#3BBBBE,#7C3AED);box-shadow:0 0 10px rgba(14,124,144,.6);' +
      'transition:width .2s ease,opacity .35s ease;}' +
      '.kt-busy{position:relative;pointer-events:none;opacity:.75;}' +
      '.kt-busy::after{content:"";position:absolute;top:50%;left:50%;width:15px;height:15px;margin:-8px 0 0 -8px;' +
      'border:2px solid rgba(255,255,255,.5);border-top-color:#fff;border-radius:50%;animation:kt-spin .6s linear infinite;}' +
      '@keyframes kt-spin{to{transform:rotate(360deg);}}';
    document.head.appendChild(st);
    bar = document.createElement('div'); bar.id = 'kt-progress';
    document.body.appendChild(bar);
    return bar;
  }

  function start() {
    active++;
    var b = ensure();
    if (active === 1) {
      clearInterval(creep);
      b.style.transition = 'none'; b.style.width = '0'; b.style.opacity = '1';
      // force reflow so the reset applies before we animate
      void b.offsetWidth;
      b.style.transition = 'width .2s ease,opacity .35s ease';
      var w = 10; b.style.width = '10%';
      creep = setInterval(function () { w += (90 - w) * 0.14; b.style.width = w + '%'; }, 350);
      // spinner on the button that triggered this (best effort)
      lastBtn = (document.activeElement && /BUTTON|A/.test(document.activeElement.tagName)) ? document.activeElement : null;
      if (lastBtn && !lastBtn.classList.contains('kt-busy')) {
        lastBtn.classList.add('kt-busy');
        clearTimeout(btnTimer);
        btnTimer = setTimeout(function () { if (lastBtn) lastBtn.classList.remove('kt-busy'); }, 30000);
      }
    }
  }

  function done() {
    active = Math.max(0, active - 1);
    if (active === 0) {
      clearInterval(creep);
      var b = ensure();
      b.style.width = '100%';
      setTimeout(function () { b.style.opacity = '0'; setTimeout(function () { b.style.width = '0'; }, 350); }, 220);
      if (lastBtn) { clearTimeout(btnTimer); lastBtn.classList.remove('kt-busy'); lastBtn = null; }
    }
  }

  var orig = window.fetch;
  window.fetch = function (input, init) {
    var method = ((init && init.method) || (input && input.method) || 'GET').toUpperCase();
    var url = (typeof input === 'string') ? input : (input && input.url) || '';
    var track = /^(POST|PUT|PATCH|DELETE)$/.test(method) && /api\.kiddietrac\.com|\/api\/v1\//.test(url);
    // Don't flash the bar for silent background writes.
    if (/unread-count|\/e\/o\/|typing|presence|heartbeat|chat-presence/.test(url)) track = false;
    if (!track) return orig.apply(this, arguments);
    start();
    var p;
    try { p = orig.apply(this, arguments); } catch (e) { done(); throw e; }
    return p.then(function (r) { done(); return r; }, function (e) { done(); throw e; });
  };
})();
