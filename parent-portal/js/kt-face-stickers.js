/* kt-face-stickers.js — cover children's faces with fun stickers before a photo is posted (2026-10-20).

   Anthony: "when uploading pictures of children captured can you have a feature to block kids
   faces with a feature that can use something silly as smiley faces and a bunch of other things".
   His choices: tap to place (no automatic detection), on EVERY photo upload in the portal and
   app, and always the poster's choice (nothing is covered unless they use it).

   HOW IT HOOKS IN, WITHOUT TOUCHING ANY SCREEN
   Every photo picker in the portal is an <input type=file>, but they are built three ways:
   in screen HTML (attached), with Dom.el and never attached, and with createElement + .click().
   A document-level "change" listener only hears the attached ones, so instead every file input
   is ARMED the moment it opens: a click on it (attached, or through its <label>) and the
   programmatic HTMLInputElement.click()/showPicker() both arm it first. Arming adds a CAPTURE
   listener for "input" and "change" on the element itself, which runs before the screen's own
   handlers (capture listeners fire first at the target), holds the event, asks
   "Hide faces?", and then re-fires both events with the chosen files (edited ones swapped in).
   Cancelling clears the selection, the same as closing the picker.

   WHAT IS SKIPPED
   - inputs marked data-kt-noface (logos, branding, face-recognition enrolment);
   - document pickers (accept lists pdf/doc/xls): those are paperwork, not photos;
   - files that are not images (videos and PDFs in a mixed selection pass through untouched).

   PRIVACY
   The stickers are burned into a NEW image drawn on a canvas; only that copy is handed to the
   screen, so the uncovered original is never uploaded. Re-encoding also drops EXIF (location,
   device). Blur and pixel stickers scramble the pixels under them: they are not overlays a
   viewer could remove. */
(function () {
  'use strict';
  if (window.KT && window.KT.faceStickers) { return; }
  window.KT = window.KT || {};

  var STICKERS = [
    '😊', '😁', '😎', '🤪', '😜', '🥳', '🤠', '😇', '🤡', '👽',
    '🐶', '🐱', '🐻', '🐼', '🦊', '🐸', '🦁', '🐵', '🐰', '🐨', '🦄', '🐥', '🐙',
    '🌟', '🌈', '🌸', '🌻', '☀️', '🍓', '🍉', '🍩', '🧁', '🎈', '❤️', '⚽', '🚀', '🎨', '🦖',
    'blur', 'pixel'
  ];
  var LAST_KEY = 'kt_face_sticker_last';
  var MAX_SIDE = 2560;
  var Z = 2147483300;

  function lastSticker() {
    try { var v = localStorage.getItem(LAST_KEY); if (v && STICKERS.indexOf(v) !== -1) { return v; } } catch (e) {}
    return '😊';
  }
  function rememberSticker(v) { try { localStorage.setItem(LAST_KEY, v); } catch (e) {} }

  function isImage(f) {
    if (!f) { return false; }
    if (/^image\/(jpeg|png|webp|gif|bmp)$/i.test(f.type || '')) { return true; }
    return !f.type && /\.(jpe?g|png|webp|gif|bmp)$/i.test(f.name || '');
  }
  function eligible(input) {
    if (!input || input.type !== 'file') { return false; }
    if (input.hasAttribute('data-kt-noface') || (input.closest && input.closest('[data-kt-noface]'))) { return false; }
    var acc = (input.getAttribute('accept') || '').toLowerCase();
    if (/pdf|\.doc|word|\.xls|sheet|csv/.test(acc)) { return false; }
    if (acc && !/image|\.jpe?g|\.png|\.webp|\.heic|\.gif/.test(acc)) { return false; }
    return true;
  }

  /* ── arming ─────────────────────────────────────────────────────────────── */
  function arm(input) {
    if (!eligible(input) || input.__ktFaceArmed) { return; }
    input.__ktFaceArmed = true;
    var held = null;   // the "input" event, re-fired with "change"
    function hold(e) {
      if (input.__ktFacePass) { return; }                 // our own re-fire: let it through
      var files = Array.prototype.slice.call(input.files || []);
      if (!files.some(isImage)) { return; }
      e.stopImmediatePropagation();
      if (e.type === 'input') { held = true; return; }   // wait for "change", which follows
      var hadInput = !!held; held = null;
      offer(files).then(function (out) {
        if (out === null) { try { input.value = ''; } catch (x) {} return; }   // cancelled
        if (out !== files) {
          try {
            var dt = new DataTransfer();
            out.forEach(function (f) { dt.items.add(f); });
            input.files = dt.files;
          } catch (x) { /* very old browser: the originals go, as before this existed */ }
        }
        input.__ktFacePass = true;
        try {
          if (hadInput) { input.dispatchEvent(new Event('input', { bubbles: true })); }
          input.dispatchEvent(new Event('change', { bubbles: true }));
        } finally { input.__ktFacePass = false; }
      });
    }
    input.addEventListener('input', hold, true);
    input.addEventListener('change', hold, true);
  }
  var _click = HTMLInputElement.prototype.click;
  HTMLInputElement.prototype.click = function () { try { arm(this); } catch (e) {} return _click.apply(this, arguments); };
  if (HTMLInputElement.prototype.showPicker) {
    var _show = HTMLInputElement.prototype.showPicker;
    HTMLInputElement.prototype.showPicker = function () { try { arm(this); } catch (e) {} return _show.apply(this, arguments); };
  }
  document.addEventListener('click', function (e) {
    var t = e.target;
    if (!t || !t.closest) { return; }
    var inp = t.closest('input[type=file]');
    if (!inp) { var lab = t.closest('label'); inp = lab && lab.control && lab.control.type === 'file' ? lab.control : null; }
    if (inp) { try { arm(inp); } catch (x) {} }
  }, true);
  // Keyboard-opened pickers (Enter/Space on a focused input) arm on focus.
  document.addEventListener('focusin', function (e) { if (e.target && e.target.type === 'file') { try { arm(e.target); } catch (x) {} } }, true);

  /* ── shared UI bits ─────────────────────────────────────────────────────── */
  function h(tag, css, html) { var el = document.createElement(tag); if (css) { el.style.cssText = css; } if (html != null) { el.innerHTML = html; } return el; }
  function esc(s) { return String(s == null ? '' : s).replace(/[&<>"]/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]; }); }
  var BTN = 'border:0;border-radius:10px;padding:9px 14px;font:inherit;font-size:13.5px;font-weight:800;cursor:pointer;';

  function loadImage(file) {
    return new Promise(function (res, rej) {
      var url = URL.createObjectURL(file);
      var img = new Image();
      img.onload = function () { res({ img: img, url: url }); };
      img.onerror = function () { URL.revokeObjectURL(url); rej(new Error('decode')); };
      img.src = url;
    });
  }

  /* ── step 1: "Hide faces?" ──────────────────────────────────────────────── */
  function offer(files) {
    return new Promise(function (resolve) {
      var out = files.slice();
      var edited = {};
      var ov = h('div', 'position:fixed;inset:0;z-index:' + Z + ';background:rgba(8,20,40,.55);display:flex;align-items:flex-end;justify-content:center;font-family:inherit;');
      ov.setAttribute('data-no-modal-guard', '1');
      ov.className = 'kt-face-offer';
      var card = h('div', 'background:#fff;width:100%;max-width:520px;border-radius:18px 18px 0 0;padding:16px 16px calc(16px + var(--kt-safe-bottom, env(safe-area-inset-bottom, 0px)));box-shadow:0 -10px 40px rgba(0,0,0,.25);max-height:88vh;overflow:auto;');
      if (window.matchMedia && window.matchMedia('(min-width:700px)').matches) { ov.style.alignItems = 'center'; card.style.borderRadius = '18px'; }
      var imgs = files.filter(isImage);
      card.appendChild(h('div', 'font-size:16px;font-weight:800;color:#0F172A;', '😊 Hide faces before posting?'));
      card.appendChild(h('div', 'font-size:13px;color:#64748B;margin:3px 0 12px;line-height:1.45;',
        'Tap a photo to cover faces with a fun sticker, a blur or pixels. Only the covered copy is posted.'));
      var grid = h('div', 'display:grid;grid-template-columns:' + (imgs.length === 1 ? 'minmax(0,200px);justify-content:center' : 'repeat(auto-fill,minmax(96px,1fr))') + ';gap:8px;');
      card.appendChild(grid);
      var urls = [];
      imgs.forEach(function (f) {
        var idx = files.indexOf(f);
        var tile = h('button', 'position:relative;aspect-ratio:1;border:2px solid #E2E8F0;border-radius:12px;overflow:hidden;padding:0;background:#F1F5F9 center/cover no-repeat;cursor:pointer;');
        tile.type = 'button';
        var u = URL.createObjectURL(f); urls.push(u);
        tile.style.backgroundImage = 'url("' + u + '")';
        var tag = h('span', 'position:absolute;left:6px;right:6px;bottom:6px;background:rgba(15,23,42,.78);color:#fff;border-radius:8px;font-size:11.5px;font-weight:800;padding:4px 6px;', '😊 Hide faces');
        tile.appendChild(tag);
        tile.onclick = function () {
          ov.style.visibility = 'hidden';
          edit(edited[idx] ? edited[idx].src : f).then(function (res) {
            ov.style.visibility = '';
            if (!res) { return; }
            if (res.unsupported) { tag.textContent = 'Can’t edit this format'; tag.style.background = '#B45309'; return; }
            edited[idx] = { src: f, file: res.file };
            out[idx] = res.file;
            var u2 = URL.createObjectURL(res.file); urls.push(u2);
            tile.style.backgroundImage = 'url("' + u2 + '")';
            tile.style.borderColor = '#16A34A';
            tag.textContent = '✓ Covered · edit again'; tag.style.background = '#15803D';
            go.textContent = 'Post covered photo' + (imgs.length > 1 ? 's' : '');
          });
        };
        grid.appendChild(tile);
      });
      var row = h('div', 'display:flex;gap:8px;margin-top:14px;align-items:center;');
      var cancel = h('button', BTN + 'background:#F1F5F9;color:#334155;', 'Cancel'); cancel.type = 'button';
      var go = h('button', BTN + 'background:#1F6080;color:#fff;margin-left:auto;', 'Post as is'); go.type = 'button';
      row.appendChild(cancel); row.appendChild(go);
      card.appendChild(row);
      ov.appendChild(card);
      document.body.appendChild(ov);
      function done(v) {
        if (ov.parentNode) { ov.parentNode.removeChild(ov); }
        setTimeout(function () { urls.forEach(function (u) { try { URL.revokeObjectURL(u); } catch (e) {} }); }, 60000);
        resolve(v);
      }
      cancel.onclick = function () { done(null); };
      go.onclick = function () { done(Object.keys(edited).length ? out : files); };
      // Never opens the editor by itself: "Hide faces" and "Post as is" are each one tap.
    });
  }

  /* ── step 2: the editor ─────────────────────────────────────────────────── */
  function edit(file) {
    return loadImage(file).then(function (L) { return editor(file, L.img, L.url); },
      function () { return { unsupported: true }; });   // e.g. HEIC where the browser cannot draw it
  }

  // Draw one sticker onto ctx, centred at (x,y), `size` px across.
  function drawSticker(ctx, src, s, x, y, size) {
    if (s.kind === 'blur' || s.kind === 'pixel') {
      var r = size / 2;
      var cells = s.kind === 'pixel' ? 7 : 5;
      var tmp = document.createElement('canvas'); tmp.width = cells; tmp.height = cells;
      var tc = tmp.getContext('2d');
      tc.imageSmoothingEnabled = true;
      tc.drawImage(src, (x - r) * s.scale, (y - r) * s.scale, size * s.scale, size * s.scale, 0, 0, cells, cells);
      ctx.save();
      ctx.beginPath(); ctx.ellipse(x, y, r, r * 1.12, 0, 0, Math.PI * 2); ctx.clip();
      ctx.imageSmoothingEnabled = s.kind !== 'pixel';
      if (s.kind === 'blur') {
        // two passes through a tiny canvas: smooth, and nothing of the face survives
        var t2 = document.createElement('canvas'); t2.width = 3; t2.height = 3;
        t2.getContext('2d').drawImage(tmp, 0, 0, 3, 3);
        ctx.drawImage(t2, x - r, y - r * 1.12, size, size * 1.12 * 1);
        ctx.globalAlpha = 0.55; ctx.drawImage(tmp, x - r, y - r * 1.12, size, size * 1.12); ctx.globalAlpha = 1;
      } else {
        ctx.drawImage(tmp, x - r, y - r * 1.12, size, size * 1.12 * 1);
      }
      ctx.restore();
      return;
    }
    ctx.save();
    ctx.font = Math.round(size * 0.92) + 'px "Apple Color Emoji","Segoe UI Emoji","Noto Color Emoji",sans-serif';
    ctx.textAlign = 'center'; ctx.textBaseline = 'middle';
    ctx.fillText(s.kind, x, y + size * 0.04);
    ctx.restore();
  }

  function editor(file, img, url) {
    return new Promise(function (resolve) {
      var W = img.naturalWidth, H = img.naturalHeight;
      var stickers = [];       // {kind, x, y, size} in IMAGE pixels
      var sel = -1;
      var current = lastSticker();

      var ov = h('div', 'position:fixed;inset:0;z-index:' + (Z + 1) + ';background:#0B1220;display:flex;flex-direction:column;font-family:inherit;color:#fff;touch-action:none;');
      ov.setAttribute('data-no-modal-guard', '1');
      ov.className = 'kt-face-editor';
      var top = h('div', 'display:flex;align-items:center;gap:8px;padding:10px 12px;padding-top:calc(10px + var(--kt-safe-top, env(safe-area-inset-top, 0px)));background:#0B2545;flex:0 0 auto;');
      var bCancel = h('button', BTN + 'background:rgba(255,255,255,.14);color:#fff;', 'Cancel'); bCancel.type = 'button';
      var title = h('div', 'flex:1;min-width:0;text-align:center;line-height:1.25;',
        '<div style="font-weight:800;font-size:14.5px;">Hide faces</div><div id="kf-hint" style="font-size:11.5px;opacity:.8;">Tap a face to cover it</div>');
      var bDone = h('button', BTN + 'background:#22B1B3;color:#fff;', 'Done'); bDone.type = 'button';
      top.appendChild(bCancel); top.appendChild(title); top.appendChild(bDone);
      ov.appendChild(top);

      var stage = h('div', 'flex:1;min-height:0;position:relative;display:flex;align-items:center;justify-content:center;overflow:hidden;');
      var view = document.createElement('canvas');
      view.style.cssText = 'max-width:100%;max-height:100%;touch-action:none;cursor:crosshair;display:block;';
      stage.appendChild(view);
      ov.appendChild(stage);

      var tools = h('div', 'flex:0 0 auto;background:#0F1B2D;padding:8px 10px calc(10px + var(--kt-safe-bottom, env(safe-area-inset-bottom)));');
      var actions = h('div', 'display:flex;gap:6px;align-items:center;margin-bottom:8px;flex-wrap:wrap;');
      var bSmaller = h('button', BTN + 'background:rgba(255,255,255,.12);color:#fff;padding:7px 12px;', '− Smaller'); bSmaller.type = 'button';
      var bBigger = h('button', BTN + 'background:rgba(255,255,255,.12);color:#fff;padding:7px 12px;', '+ Bigger'); bBigger.type = 'button';
      var bDel = h('button', BTN + 'background:rgba(239,68,68,.22);color:#FECACA;padding:7px 12px;', '🗑 Remove'); bDel.type = 'button';
      var bUndo = h('button', BTN + 'background:rgba(255,255,255,.12);color:#fff;padding:7px 12px;margin-left:auto;', '↶ Undo'); bUndo.type = 'button';
      actions.appendChild(bSmaller); actions.appendChild(bBigger); actions.appendChild(bDel); actions.appendChild(bUndo);
      tools.appendChild(actions);
      var pal = h('div', 'display:flex;gap:4px;overflow-x:auto;padding-bottom:2px;-webkit-overflow-scrolling:touch;');
      pal.setAttribute('data-kt-scroll', '1');
      STICKERS.forEach(function (k) {
        var b = h('button', 'flex:0 0 auto;width:44px;height:44px;border-radius:12px;border:2px solid transparent;background:rgba(255,255,255,.08);color:#fff;cursor:pointer;font-size:' + (k.length > 2 ? '10.5px;font-weight:800;' : '25px;') + 'line-height:1;padding:0;',
          k === 'blur' ? '◍<br>Blur' : k === 'pixel' ? '▦<br>Pixels' : k);
        b.type = 'button'; b.setAttribute('data-k', k);
        b.setAttribute('aria-label', k === 'blur' ? 'Blur' : k === 'pixel' ? 'Pixelate' : 'Sticker ' + k);
        b.onclick = function () {
          current = k; rememberSticker(k);
          if (sel >= 0) { stickers[sel].kind = k; render(); }
          paintPal();
        };
        pal.appendChild(b);
      });
      tools.appendChild(pal);
      ov.appendChild(tools);
      document.body.appendChild(ov);

      function paintPal() {
        Array.prototype.forEach.call(pal.children, function (b) {
          b.style.borderColor = b.getAttribute('data-k') === current ? '#22B1B3' : 'transparent';
          b.style.background = b.getAttribute('data-k') === current ? 'rgba(34,177,179,.22)' : 'rgba(255,255,255,.08)';
        });
        var on = sel >= 0;
        [bSmaller, bBigger, bDel].forEach(function (b) { b.disabled = !on; b.style.opacity = on ? '1' : '.4'; });
        bUndo.disabled = !stickers.length; bUndo.style.opacity = stickers.length ? '1' : '.4';
        ov.querySelector('#kf-hint').textContent = !stickers.length ? 'Tap a face to cover it'
          : on ? 'Drag to move · pick another sticker to swap' : stickers.length + ' covered · tap another face, or Done';
      }

      // The working canvas is the photo at display resolution; scale maps view px -> image px.
      var scale = 1;
      function layout() {
        var sw = stage.clientWidth, sh = stage.clientHeight;
        var k = Math.min(sw / W, sh / H, 1) * Math.min(2, window.devicePixelRatio || 1);
        view.width = Math.max(1, Math.round(W * k)); view.height = Math.max(1, Math.round(H * k));
        scale = W / view.width;
        var css = Math.min(sw / W, sh / H);
        view.style.width = Math.round(W * css) + 'px'; view.style.height = Math.round(H * css) + 'px';
        render();
      }
      function render() {
        var c = view.getContext('2d');
        c.clearRect(0, 0, view.width, view.height);
        c.drawImage(img, 0, 0, view.width, view.height);
        stickers.forEach(function (s, i) {
          var x = s.x / scale, y = s.y / scale, size = s.size / scale;
          drawSticker(c, img, { kind: s.kind, scale: scale }, x, y, size);
          if (i === sel) {
            c.save(); c.strokeStyle = '#22B1B3'; c.lineWidth = Math.max(2, view.width / 300); c.setLineDash([8, 6]);
            c.strokeRect(x - size / 2 - 4, y - size / 2 - 4, size + 8, size + 8); c.restore();
          }
        });
        paintPal();
      }
      function toImage(ev) {
        var r = view.getBoundingClientRect();
        return { x: (ev.clientX - r.left) / r.width * W, y: (ev.clientY - r.top) / r.height * H };
      }
      function hit(p) {
        for (var i = stickers.length - 1; i >= 0; i--) {
          var s = stickers[i];
          if (Math.abs(p.x - s.x) <= s.size * 0.55 && Math.abs(p.y - s.y) <= s.size * 0.6) { return i; }
        }
        return -1;
      }

      // Tap empty = new sticker; tap a sticker = select; drag = move; two fingers = resize.
      var drag = null, pointers = {}, pinch = null;
      view.addEventListener('pointerdown', function (ev) {
        ev.preventDefault();
        try { view.setPointerCapture(ev.pointerId); } catch (e) {}
        pointers[ev.pointerId] = { x: ev.clientX, y: ev.clientY };
        var ids = Object.keys(pointers);
        if (ids.length === 2 && sel >= 0) {
          var a = pointers[ids[0]], b = pointers[ids[1]];
          pinch = { d: Math.hypot(a.x - b.x, a.y - b.y), size: stickers[sel].size }; drag = null; return;
        }
        var p = toImage(ev), i = hit(p);
        if (i >= 0) { sel = i; drag = { dx: p.x - stickers[i].x, dy: p.y - stickers[i].y, moved: false }; }
        else {
          var size = Math.round(Math.min(W, H) * 0.16);
          stickers.push({ kind: current, x: p.x, y: p.y, size: size });
          sel = stickers.length - 1; drag = { dx: 0, dy: 0, moved: false };
        }
        render();
      });
      view.addEventListener('pointermove', function (ev) {
        if (pointers[ev.pointerId]) { pointers[ev.pointerId] = { x: ev.clientX, y: ev.clientY }; }
        if (pinch && sel >= 0) {
          var ids = Object.keys(pointers); if (ids.length < 2) { return; }
          var a = pointers[ids[0]], b = pointers[ids[1]];
          var d = Math.hypot(a.x - b.x, a.y - b.y);
          stickers[sel].size = Math.max(Math.min(W, H) * 0.04, Math.min(Math.max(W, H), pinch.size * d / pinch.d));
          render(); return;
        }
        if (!drag || sel < 0) { return; }
        var p = toImage(ev);
        stickers[sel].x = Math.max(0, Math.min(W, p.x - drag.dx));
        stickers[sel].y = Math.max(0, Math.min(H, p.y - drag.dy));
        drag.moved = true;
        render();
      });
      function up(ev) { delete pointers[ev.pointerId]; if (Object.keys(pointers).length < 2) { pinch = null; } drag = null; }
      view.addEventListener('pointerup', up);
      view.addEventListener('pointercancel', up);
      // Wheel over a selected sticker resizes it on desktop.
      view.addEventListener('wheel', function (ev) {
        if (sel < 0) { return; }
        ev.preventDefault();
        resize(ev.deltaY < 0 ? 1.1 : 1 / 1.1);
      }, { passive: false });

      function resize(f) {
        if (sel < 0) { return; }
        var s = stickers[sel];
        s.size = Math.max(Math.min(W, H) * 0.04, Math.min(Math.max(W, H), s.size * f));
        render();
      }
      bSmaller.onclick = function () { resize(1 / 1.2); };
      bBigger.onclick = function () { resize(1.2); };
      bDel.onclick = function () { if (sel >= 0) { stickers.splice(sel, 1); sel = -1; render(); } };
      bUndo.onclick = function () { if (stickers.length) { stickers.pop(); sel = -1; render(); } };

      function close(v) {
        window.removeEventListener('resize', layout);
        document.removeEventListener('keydown', onKey, true);
        if (ov.parentNode) { ov.parentNode.removeChild(ov); }
        try { URL.revokeObjectURL(url); } catch (e) {}
        resolve(v);
      }
      function onKey(e) {
        if (e.key === 'Escape') { e.stopPropagation(); close(null); }
        else if ((e.key === 'Delete' || e.key === 'Backspace') && sel >= 0) { e.preventDefault(); bDel.onclick(); }
      }
      document.addEventListener('keydown', onKey, true);
      bCancel.onclick = function () { close(null); };
      bDone.onclick = function () {
        if (!stickers.length) { close(null); return; }
        bDone.disabled = true; bDone.textContent = 'Saving…';
        // Final copy at full resolution (capped), stickers burned in, EXIF gone.
        var k = Math.min(1, MAX_SIDE / Math.max(W, H));
        var out = document.createElement('canvas');
        out.width = Math.round(W * k); out.height = Math.round(H * k);
        var c = out.getContext('2d');
        c.drawImage(img, 0, 0, out.width, out.height);
        stickers.forEach(function (s) { drawSticker(c, img, { kind: s.kind, scale: 1 / k }, s.x * k, s.y * k, s.size * k); });
        var png = /png/i.test(file.type || '');
        var type = png ? 'image/png' : 'image/jpeg';
        out.toBlob(function (blob) {
          if (!blob) { bDone.disabled = false; bDone.textContent = 'Done'; return; }
          var base = String(file.name || 'photo').replace(/\.[^.]+$/, '') || 'photo';
          var f;
          try { f = new File([blob], base + '-covered.' + (png ? 'png' : 'jpg'), { type: type, lastModified: Date.now() }); }
          catch (e) { f = blob; f.name = base + '-covered.' + (png ? 'png' : 'jpg'); }
          close({ file: f });
        }, type, 0.9);
      };

      window.addEventListener('resize', layout);
      requestAnimationFrame(layout);
    });
  }

  /* Also usable directly: KT.faceStickers.edit(file) -> Promise<File|null>. */
  window.KT.faceStickers = {
    edit: function (file) { return edit(file).then(function (r) { return r && r.file ? r.file : null; }); },
    offer: offer,
    arm: arm
  };
})();
