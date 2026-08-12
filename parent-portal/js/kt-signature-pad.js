/* ───────────────────────────────────────────────────────────────────
   KiddieTrac — reusable signature pad (2026-08-10)
   KT.signaturePad({title, subtitle, okLabel}) → Promise<dataURL|null>
   Canvas-based, works with finger (touch) or mouse. Returns a base64
   PNG data URL, or null if cancelled.
   ─────────────────────────────────────────────────────────────────── */
/* NOTE: this pad is opened from full-screen sheets (the form filler at
   z-index 2147480000, the NDA gate at 2147481000). At its old z-index of 100050 it
   rendered BEHIND them — the user tapped "Sign & submit" and nothing appeared to
   happen. It must out-rank anything that can summon it. */
(function (window) {
  var KT = window.KT || (window.KT = {});
  KT.signaturePad = function (opts) {
    opts = opts || {};
    return new Promise(function (resolve) {
      var ov = document.createElement('div');
      ov.style.cssText = 'position:fixed;inset:0;z-index:2147482000;background:rgba(15,23,42,.55);display:flex;align-items:center;justify-content:center;padding:16px;';
      ov.innerHTML =
        '<div style="background:#fff;border-radius:16px;max-width:460px;width:100%;padding:20px;box-shadow:0 20px 60px rgba(0,0,0,.4);">' +
          '<div style="font-size:16px;font-weight:800;color:#0F172A;margin-bottom:4px;">' + (opts.title || 'Sign here') + '</div>' +
          '<div style="font-size:12.5px;color:#64748B;margin-bottom:12px;line-height:1.5;">' + (opts.subtitle || 'Sign with your finger or mouse to confirm.') + '</div>' +
          '<canvas id="ktsig-c" style="width:100%;height:180px;border:2px dashed #CBD5E1;border-radius:12px;background:#FBFDFF;touch-action:none;display:block;"></canvas>' +
          '<div style="display:flex;justify-content:space-between;align-items:center;margin-top:12px;gap:8px;">' +
            '<button id="ktsig-clear" style="background:#F1F5F9;border:0;border-radius:10px;padding:9px 14px;font-weight:700;color:#475569;cursor:pointer;">Clear</button>' +
            '<div style="display:flex;gap:8px;">' +
              '<button id="ktsig-cancel" style="background:#F1F5F9;border:0;border-radius:10px;padding:9px 16px;font-weight:700;color:#475569;cursor:pointer;">Cancel</button>' +
              '<button id="ktsig-ok" style="background:#065F46;color:#fff;border:0;border-radius:10px;padding:9px 18px;font-weight:800;cursor:pointer;">' + (opts.okLabel || 'Confirm') + '</button>' +
            '</div>' +
          '</div>' +
        '</div>';
      document.body.appendChild(ov);
      var canvas = ov.querySelector('#ktsig-c'), ctx = canvas.getContext('2d');
      function fit() {
        var r = canvas.getBoundingClientRect();
        canvas.width = Math.max(1, r.width * 2); canvas.height = Math.max(1, r.height * 2);
        ctx.scale(2, 2); ctx.lineWidth = 2.4; ctx.lineCap = 'round'; ctx.lineJoin = 'round'; ctx.strokeStyle = '#0F172A';
      }
      setTimeout(fit, 30);
      var drawing = false, has = false, last = null;
      function pos(e) { var r = canvas.getBoundingClientRect(); var t = (e.touches && e.touches[0]) || e; return { x: t.clientX - r.left, y: t.clientY - r.top }; }
      function down(e) { drawing = true; has = true; last = pos(e); e.preventDefault(); }
      function move(e) { if (!drawing) return; var p = pos(e); ctx.beginPath(); ctx.moveTo(last.x, last.y); ctx.lineTo(p.x, p.y); ctx.stroke(); last = p; e.preventDefault(); }
      function up() { drawing = false; }
      canvas.addEventListener('mousedown', down); canvas.addEventListener('mousemove', move); window.addEventListener('mouseup', up);
      canvas.addEventListener('touchstart', down, { passive: false }); canvas.addEventListener('touchmove', move, { passive: false }); canvas.addEventListener('touchend', up);
      var done = function (v) { window.removeEventListener('mouseup', up); ov.remove(); resolve(v); };
      ov.querySelector('#ktsig-clear').onclick = function () { ctx.clearRect(0, 0, canvas.width, canvas.height); has = false; };
      ov.querySelector('#ktsig-cancel').onclick = function () { done(null); };
      ov.addEventListener('click', function (e) { if (e.target === ov) done(null); });
      ov.querySelector('#ktsig-ok').onclick = function () { if (!has) { alert('Please sign first.'); return; } done(canvas.toDataURL('image/png')); };
    });
  };
})(window);
