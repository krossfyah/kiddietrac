/* ═══════════════════════════════════════════════════════════════════
   KIDDIETRAC — QR helper (v22p85)
   Lightweight QR-code generation for check-in posters. The QR library is
   loaded from CDN on first use only (it never bloats normal page loads), and
   all encoding happens in-browser — the kiosk URL / token is NEVER sent to any
   third party.
   API:
     KT.qrImg(text, {size, cell, margin}) -> Promise<HTMLImageElement>
     KT.printQRPoster({title, subtitle, url, steps, footer}) -> Promise
   ═══════════════════════════════════════════════════════════════════ */
(function (window) {
  'use strict';
  var KT = window.KT = window.KT || {};
  var LIB = 'https://cdn.jsdelivr.net/npm/qrcode-generator@1.4.4/qrcode.js';
  var loading = null;

  function ensureLib() {
    if (window.qrcode) return Promise.resolve();
    if (loading) return loading;
    loading = new Promise(function (resolve, reject) {
      var s = document.createElement('script');
      s.src = LIB; s.async = true;
      s.onload = function () { window.qrcode ? resolve() : reject(new Error('QR library failed to initialise')); };
      s.onerror = function () { loading = null; reject(new Error('Could not load the QR library (check your connection).')); };
      document.head.appendChild(s);
    });
    return loading;
  }

  // Pick the smallest QR version that fits the data.
  function build(text) {
    for (var t = 2; t <= 40; t++) {
      try {
        var qr = window.qrcode(t, 'M');
        qr.addData(String(text || ''));
        qr.make();
        return qr;
      } catch (e) { /* too small — try a larger version */ }
    }
    throw new Error('QR encoding failed (data too long).');
  }

  function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"]/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c];
    });
  }

  KT.qrImg = function (text, opts) {
    opts = opts || {};
    var cell = opts.cell || 6;
    var margin = opts.margin != null ? opts.margin : 4;
    var size = opts.size || 180;
    return ensureLib().then(function () {
      var img = document.createElement('img');
      img.src = build(text).createDataURL(cell, margin);
      img.alt = 'QR code';
      img.style.cssText = 'width:' + size + 'px;height:' + size + 'px;image-rendering:pixelated;';
      return img;
    });
  };

  KT.printQRPoster = function (cfg) {
    cfg = cfg || {};
    return ensureLib().then(function () {
      var dataUrl = build(cfg.url || '').createDataURL(10, 4);
      // In-app overlay, NOT window.open — the APK WebView returns a stub window
      // that opened nothing yet trapped the user with no way back. This overlay
      // always has an X to close, centers the QR, and prints via the WebView's
      // own print (an injected @media print stylesheet isolates the poster).
      var prev = document.getElementById('kt-qrposter'); if (prev) prev.remove();
      var ov = document.createElement('div');
      ov.id = 'kt-qrposter';
      ov.style.cssText = 'position:fixed;inset:0;z-index:100000;background:#fff;overflow-y:auto;color:#111827;';
      ov.innerHTML =
        '<div style="min-height:100%;display:flex;flex-direction:column;align-items:center;justify-content:center;text-align:center;padding:56px 28px 40px;box-sizing:border-box;">'
        + '<h1 style="font-size:30px;margin:0 0 6px;color:#1F6080;">' + esc(cfg.title || '') + '</h1>'
        + (cfg.subtitle ? '<h2 style="font-size:17px;font-weight:500;color:#374151;margin:0 0 26px;">' + esc(cfg.subtitle) + '</h2>' : '')
        + '<img src="' + dataUrl + '" alt="Check-in QR code" style="width:min(78vw,340px);height:min(78vw,340px);image-rendering:pixelated;border:1px solid #E5E7EB;border-radius:14px;padding:12px;box-sizing:border-box;">'
        + (cfg.steps ? '<div style="margin:26px auto 0;max-width:430px;text-align:left;font-size:15px;color:#374151;line-height:1.7;">' + cfg.steps + '</div>' : '')
        + (cfg.url ? '<div style="margin-top:20px;font-size:12px;color:#64748B;word-break:break-all;font-family:ui-monospace,monospace;">' + esc(cfg.url) + '</div>' : '')
        + (cfg.footer ? '<div style="margin-top:12px;font-size:12px;color:#64748B;">' + esc(cfg.footer) + '</div>' : '')
        + '</div>'
        + '<div class="kt-qrposter-ctl" style="position:fixed;top:calc(env(safe-area-inset-top,0px) + 12px);right:14px;display:flex;gap:10px;">'
        +   '<button id="kt-qrposter-print" style="background:#1F6080;color:#fff;border:0;padding:10px 18px;border-radius:10px;font-size:14px;font-weight:800;cursor:pointer;">🖨️ Print</button>'
        +   '<button id="kt-qrposter-x" aria-label="Close" style="background:#fff;color:#111827;border:1px solid #E5E7EB;width:44px;height:44px;border-radius:50%;font-size:22px;line-height:1;cursor:pointer;">✕</button>'
        + '</div>';
      document.body.appendChild(ov);
      var st = document.createElement('style'); st.id = 'kt-qrposter-print-css';
      st.textContent = '@media print{body>*{display:none!important;}#kt-qrposter{display:block!important;position:static!important;background:#fff!important;}#kt-qrposter .kt-qrposter-ctl{display:none!important;}}';
      document.head.appendChild(st);
      var close = function () { if (ov.parentNode) ov.parentNode.removeChild(ov); if (st.parentNode) st.parentNode.removeChild(st); };
      ov.querySelector('#kt-qrposter-x').addEventListener('click', close);
      ov.querySelector('#kt-qrposter-print').addEventListener('click', function () { try { window.print(); } catch (e) {} });
      if (window.KT && KT.pushOverlay) KT.pushOverlay(ov, close);
    });
  };
})(window);
