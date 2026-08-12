/* ═══════════════════════════════════════════════════════════════════
   KiddieTrac — reusable avatar cropper.
   KT.AvatarCropper.open(file, onDone): shows the picked image in a circular
   viewport, lets the user DRAG to reposition, ZOOM (slider / pinch), and ENHANCE
   (brightness / contrast / saturation), then renders a high-res (512²) crop that
   matches the viewport EXACTLY and hands back a JPEG Blob.
   ═══════════════════════════════════════════════════════════════════ */
(function (window, document) {
  'use strict';
  var KT = (window.KT = window.KT || {});
  if (KT.AvatarCropper) return;

  var OUT = 512;  // exported resolution (high-def)

  function btn(label, bg, col) {
    var b = document.createElement('button');
    b.type = 'button'; b.textContent = label;
    b.style.cssText = 'border:0;border-radius:10px;padding:10px 20px;font-weight:800;font-size:14px;cursor:pointer;background:' + bg + ';color:' + col + ';';
    return b;
  }
  function sliderRow(label, min, max, val, step) {
    var wrap = document.createElement('div');
    wrap.style.cssText = 'display:flex;align-items:center;gap:10px;margin-top:8px;';
    var lb = document.createElement('span');
    lb.textContent = label;
    lb.style.cssText = 'font-size:12px;color:#64748B;width:82px;text-align:left;flex:0 0 auto;';
    var s = document.createElement('input');
    s.type = 'range'; s.min = String(min); s.max = String(max); s.value = String(val); s.step = String(step || 1);
    s.style.cssText = 'flex:1;accent-color:#1F6FB2;';
    wrap.appendChild(lb); wrap.appendChild(s);
    wrap.slider = s;
    return wrap;
  }

  function open(file, onDone) {
    if (!file) return;
    var url = URL.createObjectURL(file);
    var img = new Image();
    try { img.crossOrigin = 'anonymous'; } catch (e) {}
    img.onload = function () { build(img, url, onDone); };
    img.onerror = function () { try { URL.revokeObjectURL(url); } catch (e) {} if (window.KT && KT.toast) KT.toast('⚠️', 'Bad image', 'Could not read that image file.', '#DC2626'); };
    img.src = url;
  }

  function build(img, url, onDone) {
    var iw = img.naturalWidth, ih = img.naturalHeight;
    // Responsive circular viewport — never wider than the screen allows.
    var VP = Math.max(200, Math.min(300, (window.innerWidth || 360) - 80));
    var minScale = Math.max(VP / iw, VP / ih);   // smallest scale that still covers the circle
    var maxScale = minScale * 5;
    var scale = minScale;
    var ox = (VP - iw * scale) / 2, oy = (VP - ih * scale) / 2;
    var bright = 100, contrast = 100, sat = 100;

    var ov = document.createElement('div');
    ov.style.cssText = 'position:fixed;inset:0;z-index:2147483000;background:rgba(8,17,33,.62);display:flex;align-items:center;justify-content:center;padding:16px;box-sizing:border-box;';
    var panel = document.createElement('div');
    panel.style.cssText = 'background:#fff;border-radius:18px;padding:20px;max-width:380px;width:100%;max-height:92vh;overflow:auto;box-shadow:0 24px 60px -20px rgba(0,0,0,.6);text-align:center;font-family:system-ui,-apple-system,sans-serif;';
    panel.innerHTML = '<div style="font-weight:800;font-size:16px;color:#0F172A;margin-bottom:3px;">Position your photo</div>'
      + '<div style="font-size:12.5px;color:#64748B;margin-bottom:14px;">Drag to reposition · pinch or use Zoom to fill the circle.</div>';

    var vpEl = document.createElement('div');
    vpEl.style.cssText = 'position:relative;width:' + VP + 'px;height:' + VP + 'px;margin:0 auto;border-radius:50%;overflow:hidden;background:#F1F5F9;touch-action:none;cursor:grab;box-shadow:0 0 0 3px #E2E8F0, inset 0 0 0 1px rgba(0,0,0,.06);';
    var imEl = document.createElement('img');
    imEl.src = url; imEl.draggable = false;
    // max-width/height:none is ESSENTIAL — the global `img{max-width:100%}` would
    // otherwise cap the width but not the height, squishing the photo narrow AND
    // breaking the crop math (which assumes the image renders at natural×scale).
    imEl.style.cssText = 'position:absolute;top:0;left:0;max-width:none;max-height:none;transform-origin:0 0;user-select:none;-webkit-user-drag:none;pointer-events:none;width:' + iw + 'px;height:' + ih + 'px;';
    vpEl.appendChild(imEl);

    var zoom = sliderRow('Zoom', 100, 500, 100, 1);
    var brightR = sliderRow('Brightness', 50, 150, 100, 1);
    var contrastR = sliderRow('Contrast', 50, 150, 100, 1);
    var satR = sliderRow('Saturation', 0, 200, 100, 1);

    var actions = document.createElement('div');
    actions.style.cssText = 'display:flex;gap:10px;justify-content:center;margin-top:14px;';
    var cancel = btn('Cancel', '#F1F5F9', '#334155');
    var use = btn('Use photo', 'linear-gradient(135deg,#0FA3B1,#1F6FB2 60%,#2456A6)', '#fff');
    actions.appendChild(cancel); actions.appendChild(use);

    panel.appendChild(vpEl);
    var controls = document.createElement('div');
    controls.style.cssText = 'margin-top:14px;text-align:left;';
    controls.appendChild(zoom); controls.appendChild(brightR); controls.appendChild(contrastR); controls.appendChild(satR);
    panel.appendChild(controls);
    panel.appendChild(actions);
    ov.appendChild(panel); document.body.appendChild(ov);

    function filterStr() { return 'brightness(' + bright + '%) contrast(' + contrast + '%) saturate(' + sat + '%)'; }
    function apply() {
      ox = Math.min(0, Math.max(VP - iw * scale, ox));
      oy = Math.min(0, Math.max(VP - ih * scale, oy));
      imEl.style.transform = 'translate(' + ox + 'px,' + oy + 'px) scale(' + scale + ')';
      imEl.style.filter = filterStr();
    }
    apply();

    function zoomTo(newScale) {
      newScale = Math.min(maxScale, Math.max(minScale, newScale));
      var cx = VP / 2, cy = VP / 2;
      var ix = (cx - ox) / scale, iy = (cy - oy) / scale;
      scale = newScale;
      ox = cx - ix * scale; oy = cy - iy * scale;
      apply();
    }
    zoom.slider.addEventListener('input', function () { zoomTo(minScale * (parseFloat(zoom.slider.value) / 100)); });
    brightR.slider.addEventListener('input', function () { bright = parseFloat(brightR.slider.value); apply(); });
    contrastR.slider.addEventListener('input', function () { contrast = parseFloat(contrastR.slider.value); apply(); });
    satR.slider.addEventListener('input', function () { sat = parseFloat(satR.slider.value); apply(); });

    var dragging = false, lastX = 0, lastY = 0, pinch = null;
    function pt(e) { var t = e.touches && e.touches[0]; return { x: t ? t.clientX : e.clientX, y: t ? t.clientY : e.clientY }; }
    function down(e) { if (e.touches && e.touches.length === 2) return; dragging = true; vpEl.style.cursor = 'grabbing'; var p = pt(e); lastX = p.x; lastY = p.y; }
    function move(e) {
      if (e.touches && e.touches.length === 2) {
        var dx = e.touches[0].clientX - e.touches[1].clientX, dy = e.touches[0].clientY - e.touches[1].clientY;
        var d = Math.hypot(dx, dy);
        if (pinch) { zoomTo(scale * (d / pinch)); zoom.slider.value = String((scale / minScale) * 100); }
        pinch = d; e.preventDefault(); return;
      }
      if (!dragging) return;
      var p = pt(e); ox += (p.x - lastX); oy += (p.y - lastY); lastX = p.x; lastY = p.y; apply();
      if (e.cancelable) e.preventDefault();
    }
    function up() { dragging = false; pinch = null; vpEl.style.cursor = 'grab'; }
    vpEl.addEventListener('mousedown', down);
    window.addEventListener('mousemove', move);
    window.addEventListener('mouseup', up);
    vpEl.addEventListener('touchstart', down, { passive: false });
    vpEl.addEventListener('touchmove', move, { passive: false });
    vpEl.addEventListener('touchend', up);

    function close() {
      try { URL.revokeObjectURL(url); } catch (e) {}
      window.removeEventListener('mousemove', move);
      window.removeEventListener('mouseup', up);
      ov.remove();
    }
    cancel.onclick = close;
    ov.addEventListener('click', function (e) { if (e.target === ov) close(); });

    use.onclick = function () {
      var canvas = document.createElement('canvas');
      canvas.width = OUT; canvas.height = OUT;
      var ctx = canvas.getContext('2d');
      ctx.imageSmoothingEnabled = true; ctx.imageSmoothingQuality = 'high';
      ctx.fillStyle = '#ffffff'; ctx.fillRect(0, 0, OUT, OUT);
      // Bake the enhancement filters into the output too (falls back gracefully
      // if the browser doesn't support canvas ctx.filter).
      try { ctx.filter = filterStr(); } catch (e) {}
      // The viewport shows image region [sx,sy .. sx+sSize] → drawn 1:1 to OUT².
      var sSize = VP / scale;
      var sx = -ox / scale, sy = -oy / scale;
      ctx.drawImage(img, sx, sy, sSize, sSize, 0, 0, OUT, OUT);
      canvas.toBlob(function (blob) { close(); if (onDone) onDone(blob || null); }, 'image/jpeg', 0.92);
    };
  }

  KT.AvatarCropper = { open: open };
})(window, document);
