/* ═══════════════════════════════════════════════════════════════════
   KiddieTrac — avatar zoom (2026-08-07, rev2)
   Click any profile photo to pop it open large in a lightbox with the
   person's name. Works everywhere — team on the floor, provider cards,
   rosters, user records — via ONE document-level capture-phase click
   delegate (no per-element wiring, survives parent stopPropagation, and
   handles avatars regardless of whether they render before layout).

   Handles BOTH avatar shapes used across the portal:
     • <img> photos (round, avatar-sized, real src)
     • <span class="kt-avatar"> / any element with background-image:url(photo)
       — this is what KT.avatar() emits, and the reason clicks felt "dead".
   Placeholder/emoji/silhouette avatars (data: URIs, initials) are ignored.
   ═══════════════════════════════════════════════════════════════════ */
(function (w) {
  'use strict';
  var d = w.document;

  function isRealPhotoUrl(src) {
    if (!src) return false;
    if (/^data:/i.test(src)) return false;                        // inline placeholder / emoji
    if (/logo-wordmark|placeholder|silhouette|avatar-default/i.test(src)) return false;
    return true;
  }

  // Pull a background-image url() off an element (computed, so inherited/class styles count).
  function bgPhoto(el) {
    if (!el || el.nodeType !== 1) return null;
    var bi = '';
    try { bi = w.getComputedStyle(el).backgroundImage || ''; } catch (e) { return null; }
    if (!bi || bi === 'none') return null;
    var m = bi.match(/url\((['"]?)(.*?)\1\)/i);
    if (!m) return null;
    var src = m[2];
    return isRealPhotoUrl(src) ? src : null;
  }

  function looksRound(el) {
    try {
      var cs = w.getComputedStyle(el);
      var br = cs.borderRadius || '';
      if (br.indexOf('%') > -1 && parseFloat(br) >= 40) return true;
      var wpx = el.clientWidth || el.offsetWidth || 0;
      if (wpx && parseFloat(br) >= (wpx / 2) - 4) return true;
    } catch (e) {}
    return el.classList && el.classList.contains('kt-avatar');
  }

  function sizeOk(el) {
    var wpx = el.clientWidth || el.offsetWidth || el.width || 0;
    return wpx === 0 || (wpx >= 16 && wpx <= 240);                // 0 = not laid out yet → allow
  }

  function nameOf(el) {
    return (el.getAttribute && (el.getAttribute('alt') || el.getAttribute('title')
      || el.getAttribute('aria-label') || el.getAttribute('data-kt-name'))) || '';
  }

  // From a click target, walk up a few levels to find an avatar photo. Returns
  // {src, name} or null. Only matches genuine photo avatars — never rows/cards.
  function findAvatar(target) {
    var el = target, hops = 0;
    while (el && el.nodeType === 1 && hops < 4) {
      if (el.getAttribute && el.getAttribute('data-kt-no-zoom') != null) return null;
      // 1) <img> photo
      if (el.tagName === 'IMG') {
        var isrc = el.currentSrc || el.getAttribute('src') || '';
        if (isRealPhotoUrl(isrc) && sizeOk(el) && (looksRound(el) || (el.classList && el.classList.contains('kt-avatar')))) {
          return { src: isrc, name: nameOf(el) };
        }
      }
      // 2) background-image avatar (KT.avatar span, avatarCircle, etc.)
      if (el.classList && el.classList.contains('kt-avatar')) {
        var b0 = bgPhoto(el);
        if (b0) return { src: b0, name: nameOf(el) };
      }
      var b = bgPhoto(el);
      if (b && sizeOk(el) && looksRound(el)) return { src: b, name: nameOf(el) };
      el = el.parentElement; hops++;
    }
    return null;
  }

  function open(src, name) {
    var ov = d.createElement('div');
    ov.className = 'kt-av-zoom';
    ov.style.cssText = 'position:fixed;inset:0;z-index:2147483200;background:rgba(8,20,35,.82);display:flex;align-items:center;justify-content:center;padding:24px;cursor:zoom-out;opacity:0;transition:opacity .18s ease;';
    var fig = d.createElement('figure');
    fig.style.cssText = 'margin:0;display:flex;flex-direction:column;align-items:center;gap:12px;max-width:92vw;max-height:92vh;';
    var big = d.createElement('img');
    big.src = src;
    big.alt = name || '';
    big.style.cssText = 'max-width:min(520px,92vw);max-height:80vh;width:auto;height:auto;border-radius:18px;box-shadow:0 30px 80px rgba(0,0,0,.5);background:#fff;object-fit:contain;transform:scale(.94);transition:transform .18s ease;';
    fig.appendChild(big);
    if (name) {
      var cap = d.createElement('figcaption');
      cap.textContent = name;
      cap.style.cssText = 'color:#fff;font-weight:800;font-size:16px;letter-spacing:.2px;text-shadow:0 1px 3px rgba(0,0,0,.45);text-align:center;';
      fig.appendChild(cap);
    }
    var hint = d.createElement('div');
    hint.textContent = 'Click anywhere or press Esc to close';
    hint.style.cssText = 'color:rgba(255,255,255,.6);font-size:12px;';
    fig.appendChild(hint);
    var x = d.createElement('button');
    x.setAttribute('aria-label', 'Close');
    x.textContent = '✕';
    x.style.cssText = 'position:fixed;top:18px;right:20px;width:40px;height:40px;border-radius:50%;border:0;background:rgba(255,255,255,.16);color:#fff;font-size:18px;cursor:pointer;';
    ov.appendChild(fig);
    ov.appendChild(x);
    function close() { ov.style.opacity = '0'; setTimeout(function () { if (ov.parentNode) ov.parentNode.removeChild(ov); }, 180); d.removeEventListener('keydown', onKey, true); }
    function onKey(e) { if (e.key === 'Escape') { e.preventDefault(); close(); } }
    ov.addEventListener('click', close);
    x.addEventListener('click', close);
    d.addEventListener('keydown', onKey, true);
    d.body.appendChild(ov);
    requestAnimationFrame(function () { ov.style.opacity = '1'; big.style.transform = 'scale(1)'; });
  }

  // ONE capture-phase listener catches every avatar click before any row/card
  // handler can swallow it, and works for elements added after load.
  d.addEventListener('click', function (e) {
    if (d.querySelector('.kt-av-zoom')) return;                   // lightbox already open
    var hit = findAvatar(e.target);
    if (!hit) return;
    e.preventDefault();
    e.stopPropagation();
    open(hit.src, hit.name);
  }, true);

  // Show a zoom cursor over photo avatars (best-effort; the click delegate is
  // the source of truth and does not depend on this).
  function markCursor() {
    try {
      d.querySelectorAll('img.kt-avatar,span.kt-avatar,img[src]:not([data-kt-zc])').forEach(function (el) {
        var src = el.tagName === 'IMG' ? (el.currentSrc || el.getAttribute('src')) : bgPhoto(el);
        if (isRealPhotoUrl(src)) el.style.cursor = 'zoom-in';
        el.setAttribute('data-kt-zc', '1');
      });
    } catch (e) {}
  }
  if (d.readyState === 'loading') d.addEventListener('DOMContentLoaded', markCursor); else markCursor();
  if (w.MutationObserver) {
    var t = null;
    new MutationObserver(function () { if (t) return; t = setTimeout(function () { t = null; markCursor(); }, 400); })
      .observe(d.body || d.documentElement, { childList: true, subtree: true });
  }
  w.KT = w.KT || {}; w.KT.avatarZoom = { open: open };
})(window);
