/* ═══════════════════════════════════════════════════════════════════
   KiddieTrac — global document viewer (2026-08-12)

   Viewing a document keeps you inside the portal: it opens in a panel over a
   dimmed backdrop, the way Forms Manager already opened its PDFs. Handing the
   file to the browser instead opened a new tab — and in the APK an EXTERNAL
   browser, which loses the session and strands the user outside the app with
   no way back but the task switcher.

   Two ways in, so no screen has to be rewritten to benefit:

     1. KT.viewDocument(url, { title, label, filename }) — call it directly.
     2. A capture-phase click delegate that catches ordinary links to documents
        (/storage/…, .pdf, images, blob:) and routes them here instead.

   VIEW ONLY. Anything that genuinely downloads is left alone: a link carrying
   `download`, or `data-kt-download`, or an explicit `data-kt-no-viewer`, still
   behaves exactly as it did. The panel offers "Open in new tab" as the escape
   hatch for anyone who prefers the browser's own viewer or wants to save it.
   ═══════════════════════════════════════════════════════════════════ */
(function (w) {
  'use strict';
  var d = w.document;

  function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }

  var IMAGE_RE = /\.(png|jpe?g|gif|webp|bmp|svg)(\?|#|$)/i;
  var VIEWABLE_RE = /\.(pdf|png|jpe?g|gif|webp|bmp|svg|txt)(\?|#|$)/i;

  function isImage(url) { return IMAGE_RE.test(String(url || '')); }

  /* True when this is the packaged app, whose web view ignores window.print().
     Checked through Capacitor rather than by sniffing the user agent, because that is
     the same signal kt-doc-viewer already trusts for openExternally(). */
  function nativePrintUnavailable() {
    try {
      var C = w.Capacitor;
      return !!(C && (C.isNativePlatform ? C.isNativePlatform() : C.isNative));
    } catch (e) { return false; }
  }

  // Open a URL the old way — a real new tab, or the in-app browser on the APK.
  // Used only by the panel's own "Open in new tab" button.
  function openExternally(url) {
    try {
      var C = w.Capacitor;
      var native = C && (C.isNativePlatform ? C.isNativePlatform() : C.isNative);
      var B = C && C.Plugins && C.Plugins.Browser;
      if (native && B && B.open) { B.open({ url: url }); return; }
      if (native) { w.location.href = url; return; }
      var t = w.open(url, '_blank', 'noopener');
      if (!t) w.location.href = url;
    } catch (e) { try { w.location.href = url; } catch (_e) {} }
  }

  /* ANDROID'S WEBVIEW CANNOT RENDER A PDF. AT ALL.

     Desktop Chrome and iOS WKWebView both have a built-in PDF viewer, so
     `<iframe src="…pdf">` shows the document and the panel looked finished. Android's
     WebView has no such viewer and no plugin to fall back on: the iframe loads, renders
     nothing, and you get a blank white rectangle inside a correct-looking dialog.
     Anthony, 2026-09-15: "viewing immuzation upload files on mobile apk doesnt show the
     contents in the popup and a blank window."

     pdf.js draws to a canvas, which a WebView renders like any other drawing — it is how
     kt-form-filler already shows a fillable form inside the APK, so the library, the
     version and the worker URL are deliberately the same three constants. A second
     viewer built a different way would disagree with that one within a month.

     Only PDFs, and only where the frame cannot do it: an image is an <img>, and desktop
     keeps the iframe, which is lighter and needs no network. And if the library will not
     load — offline, CDN blocked — the panel says so and offers to hand the file to the
     system, which is the one thing it must never do SILENTLY. */
  /* SERVED BY US, NOT BY A CDN, and that is the whole reason this works.

     From unpkg, getDocument() parsed the file fine — page count, page size, all
     correct — and page.render() then hung forever against a blank canvas. Rendering
     is the part that needs the WORKER, and a Worker cannot be constructed from
     another origin; pdf.js falls back to a fake worker that never settles here,
     with nothing in the console to say so. Measured: settled='pending', ink=0 on a
     900x585 canvas that was fully opaque and completely empty.

     Same-origin also means it works with no network at all, which matters for an
     app people open in a car park. 1.4MB, fetched only when somebody actually opens
     a PDF. (2026-09-15) */
  /* Generous: a health-unit printout on a slow phone is a real case, and cutting a
     working render short to show an error would be its own bug. */
  var RENDER_WATCHDOG_MS = 12000;
  var PDFJS_URL = '/js/vendor/pdf.min.js';
  var PDFJS_WORKER = '/js/vendor/pdf.worker.min.js';

  function loadScript(src) {
    return new Promise(function (res, rej) {
      var existing = d.querySelector('script[data-kt-lib="' + src + '"]');
      if (existing) {
        if (existing.dataset.loaded) { return res(); }
        existing.addEventListener('load', function () { res(); });
        existing.addEventListener('error', rej);
        return;
      }
      var el = d.createElement('script');
      el.src = src; el.async = true; el.setAttribute('data-kt-lib', src);
      el.onload = function () { el.dataset.loaded = '1'; res(); };
      el.onerror = function () { rej(new Error('Could not load ' + src)); };
      d.head.appendChild(el);
    });
  }

  function ensurePdfJs() {
    return loadScript(PDFJS_URL).then(function () {
      var lib = w.pdfjsLib || w.pdfjsDistBuildPdf;
      if (!lib) { throw new Error('PDF viewer failed to load'); }
      try { lib.GlobalWorkerOptions.workerSrc = PDFJS_WORKER; } catch (e) {}
      return lib;
    });
  }

  /* Every page, top to bottom, in one scrolling column. A card is one page and a health
     unit printout is three; paging controls would be furniture for a document you are
     going to scroll anyway. */
  function renderPdfInto(host, buf) {
    return ensurePdfJs().then(function (pdfjs) {
      return pdfjs.getDocument({ data: new Uint8Array(buf.slice(0)) }).promise;
    }).then(function (doc) {
      host.innerHTML = '';
      var width = Math.max(280, Math.min(900, host.clientWidth - 24));
      var chain = Promise.resolve();
      for (var i = 1; i <= doc.numPages; i++) {
        (function (n) {
          chain = chain.then(function () {
            return doc.getPage(n).then(function (page) {
              var base = page.getViewport({ scale: 1 });
              var viewport = page.getViewport({ scale: width / base.width });
              var canvas = d.createElement('canvas');
              // Device resolution so text stays crisp, capped at 2 — at dpr 3 the canvas
              // is 9x the pixel area for no perceptible gain, which is most of why the
              // form filler used to crawl in the APK.
              var dpr = Math.min(2, w.devicePixelRatio || 1);
              canvas.width = Math.floor(viewport.width * dpr);
              canvas.height = Math.floor(viewport.height * dpr);
              canvas.style.cssText = 'display:block;margin:0 auto 12px;width:'
                + Math.floor(viewport.width) + 'px;max-width:100%;border-radius:6px;background:#fff;'
                + 'box-shadow:0 2px 10px rgba(8,20,40,.25);';
              host.appendChild(canvas);
              var ctx = canvas.getContext('2d');
              ctx.scale(dpr, dpr);
              return page.render({ canvasContext: ctx, viewport: viewport }).promise;
            });
          });
        }(i));
      }
      return chain;
    });
  }

  function view(url, opts) {
    if (!url) return null;
    opts = opts || {};
    var title = opts.title || opts.filename || 'Document';
    var label = (opts.label || 'Document').toUpperCase();

    var ov = d.createElement('div');
    // Same scrim + guard flag as the Forms Manager viewer this generalises, so it
    // dims like every other overlay in the portal and the modal guard leaves it be.
    ov.setAttribute('data-no-modal-guard', '1');
    ov.className = 'kt-scrim kt-doc-viewer';
    ov.style.cssText = 'position:fixed;inset:0;z-index:2147481200;display:flex;align-items:center;justify-content:center;padding:18px;';

    /* A blob: URL carries no file extension, so isImage() cannot tell a photo from a
       PDF and everything fetched through an authorised request landed in the iframe.
       An <img> scales and centres properly where an iframe shows a scrollbox, so the
       caller — who has the Blob and therefore its MIME type — can say. */
    /* A PDF in a WebView needs canvas, not a frame — see the note above. Decided from
       the MIME type the caller passed (it holds the Blob) and the URL as a fallback,
       because a blob: URL has no extension to read. */
    var mime = String(opts.mime || '');
    var looksPdf = /pdf/i.test(mime) || /\.pdf(\?|#|$)/i.test(String(url || ''));
    var usePdfJs = looksPdf && nativePrintUnavailable();

    var inner = usePdfJs
      ? '<div class="ktdv-pdf" data-kt-scroll="1" style="flex:1;overflow:auto;background:#0B1220;padding:14px 12px;">'
        + '<div style="color:#94A3B8;font-size:13px;text-align:center;padding:18px;">Opening the document…</div>'
        + '</div>'
      : (isImage(url) || opts.image)
      ? '<div style="flex:1;overflow:auto;background:#0B1220;display:flex;align-items:center;justify-content:center;padding:16px;">'
        + '<img src="' + esc(url) + '" alt="' + esc(title) + '" style="max-width:100%;max-height:100%;object-fit:contain;border-radius:8px;background:#fff;">'
        + '</div>'
      : '<iframe src="' + esc(url) + '" title="' + esc(title) + '" style="flex:1;width:100%;border:0;background:#fff;"></iframe>';

    ov.innerHTML =
      '<div style="background:#F6F9FC;border-radius:16px;width:100%;max-width:960px;height:min(92vh,1100px);'
      + 'display:flex;flex-direction:column;overflow:hidden;box-shadow:0 30px 80px -20px rgba(8,20,40,.6);">'
      + '<div style="background:#0B2545;color:#fff;padding:13px 16px;display:flex;align-items:center;gap:12px;flex:0 0 auto;">'
      +   '<div style="min-width:0;flex:1;">'
      +     '<div style="font-size:10.5px;font-weight:800;letter-spacing:1.2px;opacity:.75;">' + esc(label) + '</div>'
      +     '<div style="font-size:15.5px;font-weight:800;margin-top:2px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">' + esc(title) + '</div>'
      +   '</div>'
      +   '<button class="ktdv-fprint" type="button" data-kt-iconized="1" style="background:rgba(255,255,255,.14);color:#fff;border:0;border-radius:9px;padding:7px 12px;font-size:12.5px;font-weight:800;cursor:pointer;white-space:nowrap;">🖨 Print</button>'
      +   '<button class="ktdv-close" type="button" aria-label="Close" style="background:rgba(255,255,255,.14);color:#fff;border:0;border-radius:9px;width:34px;height:34px;font-size:17px;line-height:1;cursor:pointer;flex:0 0 auto;">✕</button>'
      + '</div>'
      + inner
      + '</div>';

    d.body.appendChild(ov);
    function close() {
      if (ov.parentNode) ov.parentNode.removeChild(ov);
      d.removeEventListener('keydown', onKey, true);
      // Lets a caller release what it created — an object URL leaks for the life of the
      // document otherwise, and "revoke after 60 seconds" races a reader who is still
      // looking at it.
      try { if (opts.onClose) opts.onClose(); } catch (e) {}
    }
    function onKey(e) { if (e.key === 'Escape') { e.preventDefault(); close(); } }
    if (usePdfJs) {
      var pdfHost = ov.querySelector('.ktdv-pdf');
      /* A WATCHDOG, because "it renders" is not something this can assume.

         pdf.js draws through a scheduler that depends on the page being live. A web view
         that has throttled the document — backgrounded, or mid-transition — stalls the
         render with the promise still pending and the canvas opaque and empty, and
         nothing is logged. That is indistinguishable, to the reader, from the blank
         iframe this was written to replace.

         So the render races a timer. If it has not produced a page in time the panel says
         so and offers the system viewer, which is the one thing that always works. Never
         a blank rectangle: whatever fails, the reader gets a sentence and a way forward. */
      var settled = false;
      var watchdog = new Promise(function (_res, rej) {
        w.setTimeout(function () {
          if (!settled) { rej(new Error('The document viewer did not finish loading.')); }
        }, RENDER_WATCHDOG_MS);
      });
      Promise.race([
        w.fetch(url)
          .then(function (r) { if (!r.ok) { throw new Error('HTTP ' + r.status); } return r.arrayBuffer(); })
          .then(function (buf) { return renderPdfInto(pdfHost, buf); })
          .then(function () { settled = true; }),
        watchdog,
      ])
        .catch(function (e) {
          settled = true;
          /* NEVER A BLANK PANEL. Say what happened and offer the one thing that still
             works — handing the file to the system viewer. */
          pdfHost.innerHTML = '';
          var box = d.createElement('div');
          box.style.cssText = 'max-width:420px;margin:40px auto;background:#fff;border-radius:12px;'
            + 'padding:20px;text-align:center;font-size:13.5px;color:#334155;';
          box.innerHTML = '<div style="font-size:30px;margin-bottom:8px;">📄</div>'
            + '<div style="font-weight:800;color:#0F172A;margin-bottom:6px;">'
            + 'This document could not be shown here</div>'
            + '<div style="color:#64748B;margin-bottom:14px;">'
            + esc((e && e.message) || 'The viewer could not load.')
            + ' You can still open it outside the app.</div>';
          var b2 = d.createElement('button');
          b2.type = 'button';
          b2.setAttribute('data-kt-iconized', '1');
          b2.textContent = 'Open the document';
          b2.style.cssText = 'background:#1F6080;color:#fff;border:0;border-radius:9px;'
            + 'padding:10px 18px;font-size:13.5px;font-weight:800;cursor:pointer;';
          b2.addEventListener('click', function () { openExternally(url); });
          box.appendChild(b2);
          pdfHost.appendChild(box);
        });
    }

    ov.querySelector('.ktdv-close').addEventListener('click', close);
    /* PRINT, WHERE "OPEN IN NEW TAB" USED TO BE.

       In the APK there was no way to print a record at all: the panel offered a new tab,
       which on a phone is the wrong idea anyway, and window.print() is a no-op in both
       web views — a host app has to drive the platform's print service, which needs a
       native rebuild. So on a native platform the file is handed to the device's real
       browser, where Print works normally. That needs a URL with no session on it, which
       is what the caller's externalPrint supplies (ProtectedMedia::sign). A blob: URL is
       useless to another app, so it is never what gets handed over.

       On desktop the frame is printed directly — printing the FRAME prints the document
       rather than the portal behind it — and an image is wrapped in a one-line document
       so it prints on its own page instead of dragging the dashboard along with it.
       (Anthony, 2026-09-15: "no print button in mobile apk, replace the open in new tab
       with print".) */
    ov.querySelector('.ktdv-fprint').addEventListener('click', function (ev) {
      var btn = ev.currentTarget;

      if (nativePrintUnavailable()) {
        var was = btn.textContent;
        btn.disabled = true;
        btn.textContent = 'Opening…';
        Promise.resolve()
          .then(function () {
            if (opts.externalPrint) { return opts.externalPrint(); }
            // A plain http(s) URL can go straight out; a blob cannot.
            return /^blob:/i.test(String(url)) ? null : url;
          })
          .then(function (out) {
            if (!out) { throw new Error('no printable link'); }
            openExternally(out);
            btn.textContent = was; btn.disabled = false;
          })
          .catch(function () {
            // Say so rather than appear to have worked.
            btn.textContent = 'Cannot print';
            w.setTimeout(function () { btn.textContent = was; btn.disabled = false; }, 2200);
          });
        return;
      }

      var f = ov.querySelector('iframe');
      if (f) {
        try { f.contentWindow.focus(); f.contentWindow.print(); } catch (e) {}
        return;
      }
      // An image, or pages drawn to canvas: print just the document.
      var sheets = [];
      var im = ov.querySelector('img');
      if (im) { sheets.push(im.src); }
      Array.prototype.forEach.call(ov.querySelectorAll('.ktdv-pdf canvas'), function (c) {
        try { sheets.push(c.toDataURL('image/png')); } catch (e) {}
      });
      if (!sheets.length) { return; }
      var pf = d.createElement('iframe');
      pf.setAttribute('aria-hidden', 'true');
      pf.style.cssText = 'position:fixed;left:-10000px;top:0;width:1px;height:1px;border:0;';
      d.body.appendChild(pf);
      var doc = pf.contentDocument;
      doc.open();
      doc.write('<!doctype html><meta charset="utf-8"><title>' + esc(title) + '</title>'
        + '<style>@page{margin:10mm}body{margin:0}img{display:block;width:100%;page-break-after:always}'
        + 'img:last-child{page-break-after:auto}</style>'
        + sheets.map(function (src) { return '<img src="' + src + '">'; }).join(''));
      doc.close();
      var go = function () {
        try { pf.contentWindow.focus(); pf.contentWindow.print(); } catch (e) {}
        w.setTimeout(function () { try { pf.remove(); } catch (e) {} }, 1000);
      };
      // Wait for the images to decode, or the sheet prints blank.
      var imgs = doc.images ? Array.prototype.slice.call(doc.images) : [];
      var left = imgs.length;
      if (!left) { go(); return; }
      imgs.forEach(function (i2) {
        if (i2.complete) { if (--left === 0) { go(); } return; }
        i2.addEventListener('load', function () { if (--left === 0) { go(); } });
        i2.addEventListener('error', function () { if (--left === 0) { go(); } });
      });
    });
    ov.addEventListener('click', function (e) { if (e.target === ov) close(); });
    d.addEventListener('keydown', onKey, true);
    return { close: close, el: ov };
  }

  /* ── generated HTML, shown in the same panel ─────────────────────────────
     view() takes a URL. Several screens instead BUILD a document — the emergency
     card, the compliance report, an invoice, a help article — and showed it with
     `window.open('', '_blank')` followed by document.write().

     That is the trap this file was written about, arriving by a second route.
     document.write() replaces the whole document, and everything the portal is
     goes with it: the router, so the bottom bar stops navigating; kt-native-ui's
     runtime StatusBar paint and dashboard.html's <meta name="theme-color">, so the
     Android/iOS status bar drops from navy to default; and any way back, because a
     written-into window has no history to go back through and close() is refused
     inside a web view.

     Reported three times over on the emergency card: "I cannot close the card",
     "cannot move to other screens using the bottom bar", "the top bar is no longer
     navy". One cause, and it is not worth diagnosing which of those a given web
     view does — keeping the portal's own document alive makes all three impossible.

     The document goes in an iframe, so its styles cannot leak into the portal and
     the portal's cannot leak into what gets printed.
     ──────────────────────────────────────────────────────────────────────── */
  function viewHtml(html, opts) {
    if (!html) return null;
    opts = opts || {};
    var title = opts.title || 'Document';
    var label = (opts.label || 'Document').toUpperCase();

    /* The embedded document usually carries its own toolbar, built for the standalone
       tab it used to open in. The panel supplies Close and Print, so hide the
       duplicate — its Close would act on the IFRAME, which is worse than useless. */
    var doc = String(html);
    if (opts.hide) {
      doc += '<style>' + String(opts.hide) + '{display:none !important;}</style>';
    }

    var ov = d.createElement('div');
    ov.setAttribute('data-no-modal-guard', '1');
    ov.className = 'kt-scrim kt-doc-viewer';
    ov.style.cssText = 'position:fixed;inset:0;z-index:2147481200;display:flex;align-items:center;justify-content:center;padding:18px;';

    ov.innerHTML =
      '<div style="background:#F6F9FC;border-radius:16px;width:100%;max-width:960px;height:min(92vh,1100px);'
      + 'display:flex;flex-direction:column;overflow:hidden;box-shadow:0 30px 80px -20px rgba(8,20,40,.6);">'
      + '<div style="background:#0B2545;color:#fff;padding:13px 16px;display:flex;align-items:center;gap:12px;flex:0 0 auto;">'
      +   '<div style="min-width:0;flex:1;">'
      +     '<div style="font-size:10.5px;font-weight:800;letter-spacing:1.2px;opacity:.75;">' + esc(label) + '</div>'
      +     '<div style="font-size:15.5px;font-weight:800;margin-top:2px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">' + esc(title) + '</div>'
      +   '</div>'
      // No "Open in new tab": there is no URL to open, and a new tab is the thing
      // being moved away from.
      +   '<button class="ktdv-print" type="button" data-kt-iconized="1" style="background:rgba(255,255,255,.14);color:#fff;border:0;border-radius:9px;padding:7px 12px;font-size:12.5px;font-weight:800;cursor:pointer;white-space:nowrap;">'
      +     (nativePrintUnavailable() && opts.externalPrint ? '🖨 Print in browser' : '🖨 Print') + '</button>'
      +   '<button class="ktdv-close" type="button" aria-label="Close" style="background:rgba(255,255,255,.14);color:#fff;border:0;border-radius:9px;width:34px;height:34px;font-size:17px;line-height:1;cursor:pointer;flex:0 0 auto;">✕</button>'
      + '</div>'
      + '<iframe class="ktdv-frame" title="' + esc(title) + '" style="flex:1;width:100%;border:0;background:#fff;"></iframe>'
      + '</div>';

    d.body.appendChild(ov);

    /* srcdoc rather than document.write into the frame: one assignment, no open/close
       dance, and the frame keeps its own origin-less sandbox for styling purposes. */
    var frame = ov.querySelector('.ktdv-frame');
    try { frame.srcdoc = doc; } catch (e) {
      try {
        var fd = frame.contentWindow.document;
        fd.open(); fd.write(doc); fd.close();
      } catch (e2) {}
    }

    function close() {
      if (ov.parentNode) ov.parentNode.removeChild(ov);
      d.removeEventListener('keydown', onKey, true);
    }
    function onKey(e) { if (e.key === 'Escape') { e.preventDefault(); close(); } }

    ov.querySelector('.ktdv-close').addEventListener('click', close);
    ov.querySelector('.ktdv-print').addEventListener('click', function (ev) {
      /* IN THE APP, window.print() DOES NOTHING.

         Neither Android's web view nor iOS's implements it — a host app has to drive
         the platform's own print service, which needs a native rebuild this one cannot
         have. Calling it there is a button that silently fails, which is worse than no
         button. So on a native platform the document is handed to the device's real
         browser through the Capacitor Browser plugin, where Print works normally; the
         caller supplies `externalPrint`, a function returning a promise of a URL that
         browser can open without a session. */
      if (nativePrintUnavailable() && opts.externalPrint) {
        var btn = ev.currentTarget;
        var was = btn.textContent;
        btn.disabled = true;
        btn.textContent = 'Opening…';
        Promise.resolve()
          .then(function () { return opts.externalPrint(); })
          .then(function (url) {
            if (url) { openExternally(url); }
            else { throw new Error('no link'); }
          })
          .catch(function (e) {
            // Say so rather than appear to have worked.
            btn.textContent = 'Could not open';
            setTimeout(function () { btn.textContent = was; btn.disabled = false; }, 2200);
            return;
          })
          .then(function () {
            if (btn.textContent === 'Opening…') { btn.textContent = was; btn.disabled = false; }
          });
        return;
      }
      // Printing the FRAME prints the document, not the portal behind it.
      try { frame.contentWindow.focus(); frame.contentWindow.print(); } catch (e) {}
    });
    ov.addEventListener('click', function (e) { if (e.target === ov) close(); });
    d.addEventListener('keydown', onKey, true);

    return { close: close, el: ov, frame: frame };
  }

  /* A stand-in for `window.open('', '_blank')`.

     Ten screens build a document and write it into a blank window. Rewriting each of
     those long HTML concatenations to hand viewHtml a string would be a big diff over
     code that is fiddly and rarely touched — and every one of them would be a chance to
     drop a closing tag.

     So this answers the same small API those call sites actually use — document.open,
     document.write, document.close, document.body.innerHTML, print(), close() — and
     renders into the portal's own panel instead of a window. A call site changes one
     line and nothing else.

     Writes are buffered and flushed on a microtask, so the pattern of writing a
     "Loading…" placeholder and replacing it a moment later still shows both. */
  function docWindow(opts) {
    opts = opts || {};
    var buf = '';
    var handle = null;
    var queued = false;

    function paint() {
      queued = false;
      var html = buf || '<p style="font-family:sans-serif;padding:20px;color:#64748B;">Loading…</p>';
      if (handle && handle.frame) {
        try { handle.frame.srcdoc = html + (opts.hide ? '<style>' + opts.hide + '{display:none !important;}</style>' : ''); return; }
        catch (e) { /* fall through and rebuild */ }
      }
      handle = viewHtml(html, opts);
    }

    function schedule() {
      if (queued) return;
      queued = true;
      // A microtask, so a run of .write() calls paints once.
      Promise.resolve().then(paint);
    }

    var bodyShim = {
      get innerHTML() { return buf; },
      set innerHTML(v) { buf = String(v == null ? '' : v); schedule(); },
    };

    return {
      document: {
        open: function () { buf = ''; },
        write: function (x) { buf += (x == null ? '' : String(x)); schedule(); },
        writeln: function (x) { buf += (x == null ? '' : String(x)) + '\n'; schedule(); },
        close: function () { schedule(); },
        get body() { return bodyShim; },
      },
      focus: function () { try { handle && handle.frame && handle.frame.contentWindow.focus(); } catch (e) {} },
      print: function () {
        try { handle.frame.contentWindow.focus(); handle.frame.contentWindow.print(); } catch (e) {}
      },
      close: function () { try { handle && handle.close(); } catch (e) {} },
      get closed() { return !handle || !handle.el || !handle.el.parentNode; },
    };
  }

  // ── the delegate: ordinary document links, without touching each screen ──
  function docUrlFrom(a) {
    var href = a.getAttribute('data-kt-doc-url') || a.getAttribute('href') || '';
    if (!href || href.charAt(0) === '#' || /^(mailto|tel|javascript):/i.test(href)) return null;
    if (/^blob:/i.test(href)) return href;                       // authed fetch → blob
    var abs;
    try { abs = new URL(href, w.location.href); } catch (e) { return null; }
    // Only OUR files. A link out to another site is that site's business.
    if (abs.origin !== w.location.origin && !/kiddietrac\.com$/i.test(abs.hostname)) return null;
    if (VIEWABLE_RE.test(abs.pathname) || /\/storage\//i.test(abs.pathname)) return abs.href;
    return null;
  }

  d.addEventListener('click', function (e) {
    var t = e.target, a = null, hops = 0;
    while (t && t.nodeType === 1 && hops < 4) {
      if (t.tagName === 'A' || (t.getAttribute && t.getAttribute('data-kt-doc-url'))) { a = t; break; }
      t = t.parentElement; hops++;
    }
    if (!a) return;
    // Downloading is not viewing — leave every save/download path exactly as it was.
    if (a.hasAttribute('download') || a.hasAttribute('data-kt-download') || a.hasAttribute('data-kt-no-viewer')) return;
    if (a.closest && a.closest('.kt-doc-viewer')) return;        // the panel's own controls
    var url = docUrlFrom(a);
    if (!url) return;
    e.preventDefault();
    e.stopPropagation();
    view(url, {
      title: a.getAttribute('data-kt-doc-title') || (a.textContent || '').trim().slice(0, 90) || 'Document',
      label: a.getAttribute('data-kt-doc-label') || 'Document'
    });
  }, true);

  w.KT = w.KT || {};
  /* ── a file you had to FETCH ──────────────────────────────────────────────
     A child's health record, a payslip, an incident report: not a public URL but an
     authorised request, so the screen ends up holding a Blob. Every one of them then
     did the same thing — createObjectURL, window.open — which is exactly what this
     file exists to stop. In the APK that opens an EXTERNAL browser, loses the session
     and strands the reader outside the app with no way back but the task switcher.

     So the Blob goes in the same panel as everything else, the MIME type decides
     whether it renders as an image, and the object URL is released when the panel
     closes rather than on a timer that races the reader. (Anthony, 2026-09-15) */
  function viewBlob(blob, opts) {
    if (!blob) { return null; }
    opts = opts || {};
    var url;
    try { url = w.URL.createObjectURL(blob); } catch (e) { return null; }
    var caller = opts.onClose;
    return view(url, Object.assign({}, opts, {
      mime: opts.mime || blob.type || '',
      // The blob cannot be printed by another app; the caller's session-less URL can.
      externalPrint: opts.externalPrint,
      image: opts.image != null ? opts.image : /^image\//i.test(blob.type || ''),
      onClose: function () {
        try { w.URL.revokeObjectURL(url); } catch (e) {}
        try { if (caller) caller(); } catch (e) {}
      },
    }));
  }

  w.KT.viewDocument = view;
  w.KT.viewBlob = viewBlob;
  w.KT.viewHtml = viewHtml;
  w.KT.docWindow = docWindow;
  w.KT.openDocumentExternally = openExternally;
})(window);
