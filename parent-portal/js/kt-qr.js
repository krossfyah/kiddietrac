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
      var w = window.open('', '_blank', 'width=720,height=920');
      if (!w) { alert('Pop-up blocked — allow pop-ups for this site to print the QR poster.'); return; }
      var d = w.document;
      d.write('<!doctype html><html><head><meta charset="utf-8"><title>' + esc(cfg.title || 'Check-in QR') + '</title>'
        + '<style>'
        + 'body{font-family:-apple-system,Segoe UI,Roboto,Helvetica,Arial,sans-serif;text-align:center;padding:48px 32px;color:#111827;}'
        + 'h1{font-size:32px;margin:0 0 6px;color:#1F6080;}'
        + 'h2{font-size:18px;font-weight:500;color:#374151;margin:0 0 30px;}'
        + 'img{width:340px;height:340px;image-rendering:pixelated;border:1px solid #E5E7EB;border-radius:14px;padding:12px;}'
        + '.steps{margin:28px auto 0;max-width:430px;text-align:left;font-size:15px;color:#374151;line-height:1.7;}'
        + '.url{margin-top:22px;font-size:12px;color:#9CA3AF;word-break:break-all;font-family:ui-monospace,monospace;}'
        + '.foot{margin-top:14px;font-size:12px;color:#9CA3AF;}'
        + '@media print{.noprint{display:none!important;}}'
        + '</style></head><body>'
        + '<h1>' + esc(cfg.title || '') + '</h1>'
        + (cfg.subtitle ? '<h2>' + esc(cfg.subtitle) + '</h2>' : '')
        + '<img src="' + dataUrl + '" alt="Check-in QR code">'
        + (cfg.steps ? '<div class="steps">' + cfg.steps + '</div>' : '')
        + (cfg.url ? '<div class="url">' + esc(cfg.url) + '</div>' : '')
        + (cfg.footer ? '<div class="foot">' + esc(cfg.footer) + '</div>' : '')
        + '<div class="noprint" style="margin-top:30px;"><button onclick="window.print()" style="background:#1F6080;color:#fff;border:0;padding:12px 30px;border-radius:8px;font-size:15px;cursor:pointer;">🖨️ Print</button></div>'
        + '</body></html>');
      d.close();
    });
  };
})(window);
