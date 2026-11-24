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

  /* PRINT A ONE-TAP POSTER — ONLY THE POSTER (2026-10-21).

     Anthony: "when educator uses QR and wants to print from desktop or phone, it doesnt
     just only print the QR it prints the entire page". This used to lay an overlay over
     the portal and call window.print(), trusting a print stylesheet to hide the rest. The
     portal's own print rules won, a phone browser printed the page behind, and the app's
     web view ignores window.print() entirely.

     The poster is now its own page, /print/one-tap-poster.html, carrying what it shows in
     the URL fragment (never sent to a server). It is printed the way each device can:
       - desktop browser: loaded into a hidden frame and that FRAME is printed, so the
         print is the poster and nothing else, with no tab opened;
       - phone browser: opened in a new tab, which prints itself (and has a Print button);
       - the Android/iOS app: handed to the device's browser, where Print works.
     It names the AGENCY as well as the centre ("the QR printable should have the ilearn
     agency name as well"), with its logo; that comes from /checkin/poster-info.

     cfg: { data, kind:'app'|'kiosk', centreId, centreName, shortCode, validFor,
            agencyName, agencyLogo, brandColor, poweredBy }
     The old { url, title } names still work. Resolves when the print was handed over. */
  function isNative() {
    try { var C = window.Capacitor; return !!(C && (C.isNativePlatform ? C.isNativePlatform() : C.isNative)); }
    catch (e) { return false; }
  }
  function touchPhone() {
    try {
      return !!(window.matchMedia && window.matchMedia('(pointer:coarse)').matches)
        && Math.min(window.screen.width || 9999, window.screen.height || 9999) < 820;
    } catch (e) { return false; }
  }
  function appOrigin() {
    var o = window.location.origin || '';
    return /^https:\/\//i.test(o) && !/localhost|capacitor:/i.test(o) ? o : 'https://app.kiddietrac.com';
  }
  function apiBase() { return (window.KT_CONFIG && window.KT_CONFIG.apiBase) || 'https://api.kiddietrac.com/api/v1'; }
  function authHeaders() {
    var h = { Accept: 'application/json' };
    try {
      var t = sessionStorage.getItem('kt_token') || localStorage.getItem('kt_token');
      if (t) h.Authorization = 'Bearer ' + t;
      var aid = sessionStorage.getItem('kt_active_agency_id'); if (aid) h['X-Active-Agency-Id'] = aid;
    } catch (e) {}
    return h;
  }
  function brandFor(cfg) {
    if (cfg.agencyName || !cfg.centreId) return Promise.resolve(cfg);
    return fetch(apiBase() + '/checkin/poster-info/' + encodeURIComponent(cfg.centreId), { headers: authHeaders() })
      .then(function (r) { return r.ok ? r.json() : null; })
      .then(function (d) {
        if (d) {
          cfg.agencyName = d.agency_name; cfg.agencyLogo = d.agency_logo; cfg.brandColor = d.brand_color;
          cfg.poweredBy = d.powered_by; cfg.centreName = cfg.centreName || d.centre_name;
        }
        return cfg;
      })
      .catch(function () { return cfg; });
  }
  function posterUrl(cfg, auto) {
    var payload = {
      d: cfg.data || cfg.url || '', k: cfg.kind === 'kiosk' ? 'kiosk' : 'app',
      t: cfg.centreName || cfg.title || '', a: cfg.agencyName || '', l: cfg.agencyLogo || '',
      c: cfg.brandColor || '', s: cfg.shortCode || '', v: cfg.validFor || '',
      p: cfg.poweredBy === false ? 0 : 1,
    };
    var b64 = btoa(unescape(encodeURIComponent(JSON.stringify(payload))))
      .replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');
    return appOrigin() + '/print/one-tap-poster.html#' + b64 + (auto ? '&auto=1' : '');
  }
  function printInFrame(url) {
    return new Promise(function (resolve) {
      var prev = document.getElementById('kt-qrposter-frame'); if (prev) prev.remove();
      var f = document.createElement('iframe');
      f.id = 'kt-qrposter-frame';
      f.setAttribute('aria-hidden', 'true');
      f.style.cssText = 'position:fixed;left:-10000px;top:0;width:900px;height:1200px;border:0;visibility:hidden;';
      var fired = false;
      var go = function () {
        if (fired) return; fired = true;
        window.removeEventListener('message', onMsg);
        try { f.contentWindow.focus(); f.contentWindow.print(); } catch (e) { window.open(url, '_blank'); }
        // The print window held the page while it was open: not a freeze (tickets #160, #161).
        try { (window.__ktNoteBlockingDialog || function () {})(); } catch (e) {}
        // Leave the frame until the dialog has had it; a removed frame prints blank.
        setTimeout(function () { try { f.remove(); } catch (e) {} }, 60000);
        resolve();
      };
      var onMsg = function (ev) { if (ev.source === f.contentWindow && ev.data && ev.data.ktPoster === 'ready') go(); };
      window.addEventListener('message', onMsg);
      f.src = url;
      document.body.appendChild(f);
      setTimeout(go, 6000);   // never strand the button if the ready message is lost
    });
  }

  KT.printQRPoster = function (cfg) {
    cfg = cfg || {};
    if (!(cfg.data || cfg.url)) return Promise.reject(new Error('Nothing to print yet.'));
    // A phone or the app must open the page from the tap itself, before any await,
    // or the browser treats it as a popup. Open first, point it at the poster after.
    var native = isNative(), phone = !native && touchPhone();
    var tab = phone ? window.open('about:blank', '_blank') : null;
    return brandFor(cfg).then(function (c) {
      if (native) {
        var url = posterUrl(c, true);
        var B = window.Capacitor && window.Capacitor.Plugins && window.Capacitor.Plugins.Browser;
        if (B && B.open) { B.open({ url: url }); } else { window.location.href = url; }
        return;
      }
      if (phone) {
        var u2 = posterUrl(c, true);
        if (tab && !tab.closed) { tab.location.href = u2; } else { window.location.href = u2; }
        return;
      }
      return printInFrame(posterUrl(c, false));
    });
  };
})(window);
