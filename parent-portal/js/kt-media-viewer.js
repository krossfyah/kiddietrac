/* ═══════════════════════════════════════════════════════════════════
   KiddieTrac — one photo & video viewer, with next / previous (2026-10-01).

   Anthony: "daily overview where there is photos and videos captured - if
   there are multiple when I click on one allow me to forward to the next or go
   back with using arrows - full sweep".

   Every gallery had its own one-item overlay (Daily overview, the parent's
   Photos on desktop and phone), the staff photo feed had none, and chat and
   support photos opened a browser tab — which in the Android app opens nothing.
   This is the one viewer they all use now:

     KT.mediaViewer.open(items, index, opts)
       items  [{ url, type: 'image'|'video', caption?, meta?, poster? }]
       index  the one that was tapped
       opts   { download: function (item, button) }  — a screen's own download; without it the viewer's own
              (download() below) is used; download: false leaves the button off

   ‹ › buttons, the keyboard's ← → and Esc, and a swipe on a phone. A counter
   ("3 / 12") when there is more than one. Videos play in place and stop when
   you move on. The neighbours are preloaded so the next photo is already there.
   ═══════════════════════════════════════════════════════════════════ */
(function (w, d) {
  'use strict';
  w.KT = w.KT || {};
  if (w.KT.mediaViewer) { return; }

  var Z = 2147483200;   // the lightbox layer (see kt-polish.js) — above gates' dialogs

  function isVideo(it) {
    return it && (it.type === 'video' || /video/i.test(it.type || '') || /\.(mp4|webm|mov|m4v)(\?|$)/i.test(it.url || ''));
  }
  function clean(s) { return String(s || '').replace(/^\[Demo\] /, ''); }

  function btn(label, aria, css) {
    var b = d.createElement('button');
    b.type = 'button';
    b.setAttribute('aria-label', aria);
    b.setAttribute('data-kt-iconized', '1');
    b.textContent = label;
    b.style.cssText = css;
    return b;
  }

  /* EVERY VIEWER CAN DOWNLOAD (2026-11-13, Anthony: "in chat messages when there is an image shared etc allow it to
     be downloaded as well"). Only the parent's gallery and chat passed a download function, so a photo opened from a
     staff chat, the chat window, a support ticket, a lesson plan or a child's feed could be looked at but not kept.
     This is the parent app's download (screen-parent.js downloadPhoto), here once, and the viewer's Download button
     uses it unless a screen passes its own (opts.download) or turns it off (opts.download === false):
       1. fetch the file (same origin, or storage that allows it), else
       2. through the API's media proxy (/media/download: signed in, image only, private hosts refused), then
       3. phones: the share sheet ("Save image"); the Android app without one opens it in the browser to save;
          computers: an ordinary file download. A video the proxy cannot carry opens in a new tab instead. */
  function nativeApp() {
    try { var C = w.Capacitor; if (C && (C.isNativePlatform ? C.isNativePlatform() : C.isNative)) { return true; } } catch (e) {}
    return d.documentElement.classList.contains('kt-native');
  }
  function openExternal(url) {
    try { var C = w.Capacitor; if (C && C.Plugins && C.Plugins.Browser && C.Plugins.Browser.open) { C.Plugins.Browser.open({ url: url }); return true; } } catch (e) {}
    if (!nativeApp()) { try { if (w.open(url, '_blank', 'noopener')) { return true; } } catch (e) {} }
    return false;
  }
  function fileName(it, type) {
    var base = clean(it.caption) || '';
    if (!base) { try { base = decodeURIComponent(String(it.url).split('?')[0].split('/').pop() || '').replace(/\.[a-z0-9]{2,5}$/i, ''); } catch (e) {} }
    base = ('kiddietrac-' + (base || (isVideo(it) ? 'video' : 'photo'))).replace(/[^a-z0-9]+/gi, '-').slice(0, 52).replace(/-+$/, '');
    var ext = /png/.test(type) ? 'png' : /webp/.test(type) ? 'webp' : /gif/.test(type) ? 'gif' : /mp4/.test(type) ? 'mp4'
      : /quicktime/.test(type) ? 'mov' : /webm/.test(type) ? 'webm' : (isVideo(it) ? 'mp4' : 'jpg');
    return base + '.' + ext;
  }
  function download(it, button) {
    if (!it || !it.url) { return; }
    var url = it.url, native = nativeApp(), phone = native || !!(w.matchMedia && w.matchMedia('(pointer: coarse)').matches);
    var old = button ? button.textContent : null;
    if (button) { button.textContent = 'Preparing…'; button.disabled = true; }
    function reset() { if (button) { button.textContent = old; button.disabled = false; } }
    function toast(icon, title, body, colour) { try { if (w.KT && w.KT.toast) { w.KT.toast(icon, title, body, colour); } } catch (e) {} }
    function external() {
      reset();
      var ok = openExternal(url);
      if (ok && native) { toast('⬇️', 'Opened in the browser', 'Press and hold the photo, then tap Save.', '#159FB4'); }
      else if (!ok) { toast('⚠️', 'Could not download it', 'Please try again in a moment.', '#B45309'); }
    }
    function save(b) {
      var type = b.type || (isVideo(it) ? 'video/mp4' : 'image/jpeg'), name = fileName(it, type);
      try {
        if (phone && navigator.canShare) {
          var f = new File([b], name, { type: type });
          if (navigator.canShare({ files: [f] })) { return navigator.share({ files: [f], title: name }).then(reset, reset); }
        }
      } catch (e) {}
      if (native) { external(); return; }
      var u = URL.createObjectURL(b), a = d.createElement('a');
      a.href = u; a.download = name; a.style.display = 'none';
      d.body.appendChild(a); a.click();
      setTimeout(function () { URL.revokeObjectURL(u); if (a.parentNode) { a.parentNode.removeChild(a); } }, 4000);
      reset();
      toast('⬇️', 'Downloaded', name, '#159FB4');
    }
    function get(u, opt) { return fetch(u, opt).then(function (r) { if (!r.ok) { throw new Error('HTTP ' + r.status); } return r.blob(); }); }
    function viaProxy() {
      if (isVideo(it)) { return Promise.reject(new Error('video')); }
      var t = ''; try { t = sessionStorage.getItem('kt_token') || localStorage.getItem('kt_token') || ''; } catch (e) {}
      var base = (w.KT && w.KT.API_BASE) || 'https://api.kiddietrac.com/api/v1';
      var abs = url; try { abs = new URL(url, location.href).href; } catch (e) {}
      return get(base + '/media/download?url=' + encodeURIComponent(abs) + '&name=' + encodeURIComponent(fileName(it, '')),
        { headers: { Authorization: 'Bearer ' + t, Accept: 'image/*' } });
    }
    var same = false; try { same = new URL(url, location.href).origin === location.origin; } catch (e) {}
    get(url, same ? { credentials: 'include' } : { mode: 'cors' }).then(save)
      .catch(function () { return viaProxy().then(save); })
      .catch(external);
  }

  function open(items, index, opts) {
    items = (items || []).filter(function (it) { return it && it.url; });
    if (!items.length) { return null; }
    opts = opts || {};
    var i = Math.max(0, Math.min(items.length - 1, index | 0));
    var many = items.length > 1;

    var ov = d.createElement('div');
    ov.className = 'kt-lightbox kt-media-viewer';   // the class every "is a dialog open?" check knows
    ov.setAttribute('role', 'dialog');
    ov.setAttribute('aria-modal', 'true');
    ov.setAttribute('aria-label', 'Photo viewer');
    ov.style.cssText = 'position:fixed;inset:0;z-index:' + Z + ';background:rgba(8,20,36,.92);'
      + 'display:flex;flex-direction:column;align-items:center;justify-content:center;'
      + 'padding:calc(var(--kt-safe-top, env(safe-area-inset-top, 0px)) + 52px) 12px calc(var(--kt-safe-bottom, env(safe-area-inset-bottom, 0px)) + 16px);'
      + 'box-sizing:border-box;touch-action:pan-y;user-select:none;-webkit-user-select:none;';

    var stage = d.createElement('div');
    stage.style.cssText = 'flex:1 1 auto;min-height:0;width:100%;display:flex;align-items:center;justify-content:center;position:relative;';
    ov.appendChild(stage);

    var cap = d.createElement('div');
    cap.style.cssText = 'color:#E2E8F0;font-size:14px;line-height:1.4;margin-top:12px;text-align:center;max-width:min(90vw,800px);min-height:1em;';
    ov.appendChild(cap);

    var bar = d.createElement('div');
    bar.style.cssText = 'display:flex;gap:10px;margin-top:12px;align-items:center;';
    ov.appendChild(bar);
    var dl = null;
    var dlFn = opts.download === false ? null : (typeof opts.download === 'function' ? opts.download : download);
    if (dlFn) {
      dl = btn('⬇️  Download', 'Download',
        'height:36px;background:#159FB4;color:#fff;border:0;border-radius:18px;padding:0 18px;font-weight:700;font-size:13.5px;cursor:pointer;');
      dl.addEventListener('click', function (e) { e.stopPropagation(); dlFn(items[i], dl); });
      bar.appendChild(dl);
    }

    var count = d.createElement('div');
    count.style.cssText = 'position:absolute;top:calc(var(--kt-safe-top, env(safe-area-inset-top, 0px)) + 14px);left:50%;transform:translateX(-50%);'
      + 'color:#CBD5E1;font-size:13px;font-weight:700;letter-spacing:.3px;background:rgba(15,23,42,.55);padding:4px 12px;border-radius:14px;';
    if (many) { ov.appendChild(count); }

    var x = btn('✕', 'Close',
      'position:absolute;top:calc(var(--kt-safe-top, env(safe-area-inset-top, 0px)) + 10px);right:12px;width:36px;height:36px;border-radius:50%;'
      + 'border:0;background:rgba(255,255,255,.14);color:#fff;font-size:16px;cursor:pointer;');
    ov.appendChild(x);

    var arrowCss = 'position:absolute;top:50%;transform:translateY(-50%);width:44px;height:44px;border-radius:50%;border:0;'
      + 'background:rgba(255,255,255,.16);color:#fff;font-size:26px;line-height:1;cursor:pointer;display:flex;align-items:center;justify-content:center;'
      + 'box-shadow:0 2px 10px rgba(0,0,0,.3);';
    var prev = btn('‹', 'Previous', arrowCss + 'left:10px;');
    var next = btn('›', 'Next', arrowCss + 'right:10px;');
    if (many) { ov.appendChild(prev); ov.appendChild(next); }

    var cur = null;
    /* Pinch / double-tap / Ctrl+wheel to zoom a photo (2026-10-20). Zoomed in, a swipe pans
       the photo instead of changing it. */
    var pz = (w.KT && w.KT.pinchZoom)
      ? w.KT.pinchZoom(stage, { mode: 'size', target: function () { return stage.querySelector('img'); }, controlHost: ov })
      : null;
    function stopCurrent() {
      if (cur && cur.tagName === 'VIDEO') { try { cur.pause(); } catch (e) {} }
    }
    function preload(k) {
      var it = items[(k + items.length) % items.length];
      if (it && !isVideo(it)) { var im = new Image(); im.src = it.url; }
    }
    function show(k) {
      stopCurrent();
      i = (k + items.length) % items.length;
      var it = items[i];
      while (stage.firstChild) { stage.removeChild(stage.firstChild); }
      var el;
      if (isVideo(it)) {
        el = d.createElement('video');
        el.src = it.url; el.controls = true; el.autoplay = true; el.playsInline = true;
        el.setAttribute('playsinline', '');            // iOS will not play inline without it
        if (it.poster) { el.poster = it.poster; }
      } else {
        el = d.createElement('img');
        el.src = it.url; el.alt = clean(it.caption) || 'Photo';
        el.draggable = false;
      }
      el.style.cssText = 'max-width:min(94vw,1200px);max-height:100%;border-radius:12px;object-fit:contain;'
        + 'background:#0b1626;box-shadow:0 20px 60px rgba(0,0,0,.5);';
      el.addEventListener('click', function (e) { e.stopPropagation(); });
      stage.appendChild(el);
      cur = el;
      var c = clean(it.caption), m = it.meta ? String(it.meta) : '';
      cap.textContent = c && m ? c + ' · ' + m : (c || m);
      count.textContent = (i + 1) + ' / ' + items.length;
      if (dl) { dl.style.display = isVideo(it) && dlFn !== download ? 'none' : ''; }
      if (many) { preload(i + 1); preload(i - 1); }
      // Each photo opens at 100%; a video has nothing to zoom.
      if (pz) { pz.reset(); if (pz.control) { pz.control.style.display = isVideo(it) ? 'none' : ''; } }
    }

    function onKey(e) {
      if (e.key === 'Escape') { e.preventDefault(); e.stopPropagation(); close(); }
      else if (many && e.key === 'ArrowRight') { e.preventDefault(); e.stopPropagation(); show(i + 1); }
      else if (many && e.key === 'ArrowLeft') { e.preventDefault(); e.stopPropagation(); show(i - 1); }
    }
    var closed = false;
    function close() {
      // popOverlay calls this back as the overlay's dismiss — once is enough.
      if (closed) { return; }
      closed = true;
      stopCurrent();
      d.removeEventListener('keydown', onKey, true);
      try { if (ov.parentNode) { ov.parentNode.removeChild(ov); } } catch (e) {}
      try { if (w.KT.popOverlay) { w.KT.popOverlay(ov); } } catch (e) {}
    }

    prev.addEventListener('click', function (e) { e.stopPropagation(); show(i - 1); });
    next.addEventListener('click', function (e) { e.stopPropagation(); show(i + 1); });
    x.addEventListener('click', function (e) { e.stopPropagation(); close(); });
    // A tap on the dark background closes; a tap on the photo, the bar or a button does not.
    ov.addEventListener('click', function (e) { if (e.target === ov || e.target === stage) { close(); } });
    d.addEventListener('keydown', onKey, true);

    // Swipe: horizontal, clearly sideways, far enough to mean it.
    var sx = 0, sy = 0, st = 0;
    ov.addEventListener('touchstart', function (e) {
      var t = e.touches && e.touches[0]; if (!t) { return; }
      sx = t.clientX; sy = t.clientY; st = Date.now();
    }, { passive: true });
    ov.addEventListener('touchend', function (e) {
      if (!many) { return; }
      if (pz && pz.busy()) { return; }
      var t = e.changedTouches && e.changedTouches[0]; if (!t) { return; }
      var dx = t.clientX - sx, dy = t.clientY - sy;
      if (Math.abs(dx) > 50 && Math.abs(dx) > Math.abs(dy) * 1.5 && Date.now() - st < 800) {
        show(dx < 0 ? i + 1 : i - 1);
      }
    }, { passive: true });

    show(i);
    d.body.appendChild(ov);
    // Registered so the Android back button and the app's ‹ back close it first.
    try { if (w.KT.pushOverlay) { w.KT.pushOverlay(ov, close); } } catch (e) {}
    return { close: close, show: show };
  }

  /* For screens that render <img> tiles as HTML strings: everything matching
     `selector` inside `root` becomes one sequence, in page order. Each element gives
     its full-size URL in data-kt-media-src (or its own src), and may say
     data-kt-media-type="video" and data-kt-media-caption. */
  function fromElements(root, selector, clicked, opts) {
    var els = Array.prototype.slice.call((root || d).querySelectorAll(selector));
    var items = els.map(function (el) {
      return {
        url: el.getAttribute('data-kt-media-src') || el.getAttribute('src') || el.getAttribute('href'),
        type: el.getAttribute('data-kt-media-type') || 'image',
        caption: el.getAttribute('data-kt-media-caption') || el.getAttribute('alt') || '',
      };
    });
    return open(items, Math.max(0, els.indexOf(clicked)), opts);
  }

  /* Markup-only opt-in: <img data-kt-media-group="chat"> opens the viewer with every
     image of the same group in the same scrolling area (a chat thread), in order. Capture
     phase, so a wrapping <a target="_blank"> never gets the click — in the Android app
     that tab never opened, which is why tapping a chat photo did nothing. */
  function scrollRoot(el) {
    for (var p = el.parentElement; p && p !== d.body; p = p.parentElement) {
      var oy = '';
      try { oy = w.getComputedStyle(p).overflowY; } catch (e) {}
      if ((oy === 'auto' || oy === 'scroll') && p.scrollHeight > p.clientHeight) { return p; }
    }
    return d;
  }
  d.addEventListener('click', function (e) {
    var img = e.target && e.target.closest ? e.target.closest('img[data-kt-media-group]') : null;
    if (!img) { return; }
    e.preventDefault();
    e.stopPropagation();
    var g = img.getAttribute('data-kt-media-group');
    fromElements(scrollRoot(img), 'img[data-kt-media-group="' + g.replace(/"/g, '') + '"]', img);
  }, true);

  w.KT.mediaViewer = { open: open, fromElements: fromElements, download: download };
})(window, document);
