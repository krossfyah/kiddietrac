/* ═══════════════════════════════════════════════════════════════════
   KiddieTrac — add information to a COMPLETED form (2026-09-22).

   The counter-signature flow needed a way to fill in what the parent left out —
   a missing allergy line, a room number, a correction — on the document itself,
   not in a note beside it. The parent's PDF is flattened by then, so there are no
   AcroForm fields left and kt-form-filler has nothing to fill: it reports "no
   fillable fields" and stops. This draws onto the page instead.

   WHAT IT DOES NOT DO: touch the parent's file. The bytes here are a COPY fetched
   for the occasion; the additions are drawn onto that copy and handed back to the
   caller, and `filled_file_url` on the sign-off still points at exactly what the
   parent signed. A record that merged the two could not answer "what did the
   parent actually agree to", which is the only question that matters in a dispute.

   Every addition is drawn in TEAL and tagged with the reviewer's initials-free
   marker, so nobody reading the finished PDF can mistake an agency addition for
   something the family wrote.

   Same two libraries the form filler already uses, loaded the same lazy way:
   pdf.js to render each page so the reviewer can see where they are clicking, and
   pdf-lib to write the text into the real document afterwards. The host has no
   server-side PDF library for this.

   Public: KT.formAnnotate.open({ url, title }) -> Promise<null | {
     base64:  the annotated PDF, no data: prefix
     notes:   [{ page, text }]   for the audit trail and the certificate page
   }>
   Resolves null when the reviewer cancels or adds nothing.
   ═══════════════════════════════════════════════════════════════════ */
(function (w) {
  'use strict';
  if (w.KT_FORM_ANNOTATE) { return; }
  w.KT_FORM_ANNOTATE = true;
  var KT = (w.KT = w.KT || {});

  var PDFJS_URL = 'https://unpkg.com/pdfjs-dist@3.11.174/build/pdf.min.js';
  var PDFJS_WORKER = 'https://unpkg.com/pdfjs-dist@3.11.174/build/pdf.worker.min.js';
  var PDFLIB_URL = 'https://unpkg.com/pdf-lib@1.17.1/dist/pdf-lib.min.js';

  var TEAL = { r: 0.06, g: 0.45, b: 0.55 };
  var FONT_PT = 11;

  function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"]/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c];
    });
  }

  function loadScript(src) {
    return new Promise(function (res, rej) {
      var existing = document.querySelector('script[data-kt-lib="' + src + '"]');
      if (existing) {
        if (existing.dataset.loaded) { return res(); }
        existing.addEventListener('load', function () { res(); });
        existing.addEventListener('error', rej);
        return;
      }
      var s = document.createElement('script');
      s.src = src; s.async = true; s.setAttribute('data-kt-lib', src);
      s.onload = function () { s.dataset.loaded = '1'; res(); };
      s.onerror = function () { rej(new Error('Could not load ' + src)); };
      document.head.appendChild(s);
    });
  }

  /* Already-loaded libraries are reused: the form filler may have pulled them in
     already this session, and fetching them twice is a second cold CDN round-trip
     for nothing. */
  function ensureLibs() {
    var needJs = !w.pdfjsLib;
    var needLib = !w.PDFLib;
    return (needJs ? loadScript(PDFJS_URL) : Promise.resolve())
      .then(function () {
        if (!w.pdfjsLib) { throw new Error('PDF viewer failed to load'); }
        try { w.pdfjsLib.GlobalWorkerOptions.workerSrc = PDFJS_WORKER; } catch (e) {}
        return needLib ? loadScript(PDFLIB_URL) : null;
      })
      .then(function () {
        if (!w.PDFLib) { throw new Error('PDF writer failed to load'); }
        return { pdfjs: w.pdfjsLib, pdflib: w.PDFLib };
      });
  }

  /**
   * @param {{url:string, title?:string}} opts
   * @returns {Promise<null|{base64:string, notes:Array}>}
   */
  function open(opts) {
    opts = opts || {};
    var url = opts.url;
    if (!url) { return Promise.resolve(null); }

    return new Promise(function (resolve) {
      var notes = [];        // {page, xPct, yPct, text, el}
      var originalBytes = null;
      var pageBoxes = [];    // the positioned wrapper per page, index 0 = page 1

      var ov = document.createElement('div');
      ov.className = 'kt-scrim';
      ov.setAttribute('data-no-modal-guard', '1');
      /* Above the counter-sign dialog (2147483001) because it is opened FROM it and
         must not repeat the signature-pad mistake of painting underneath its caller. */
      ov.style.cssText = 'position:fixed;inset:0;z-index:2147483200;display:flex;'
        + 'align-items:center;justify-content:center;padding:16px;';

      ov.innerHTML = '<div style="background:#F6F9FC;border-radius:16px;width:100%;max-width:1000px;'
          + 'height:min(94vh,1200px);display:flex;flex-direction:column;overflow:hidden;'
          + 'box-shadow:0 30px 80px -20px rgba(8,20,40,.6);">'
        + '<div style="background:#0B2545;color:#fff;padding:12px 16px;display:flex;align-items:center;gap:12px;flex:0 0 auto;">'
          + '<div style="min-width:0;flex:1;">'
            + '<div style="font-size:10.5px;font-weight:800;letter-spacing:1.2px;opacity:.75;">ADD INFORMATION TO THE FORM</div>'
            + '<div style="font-size:15px;font-weight:800;margin-top:2px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">'
              + esc(opts.title || 'Form') + '</div>'
          + '</div>'
          + '<span id="fa-count" style="font-size:12px;font-weight:700;background:rgba(255,255,255,.14);'
            + 'border-radius:999px;padding:5px 11px;white-space:nowrap;">0 added</span>'
          + '<button id="fa-x" type="button" aria-label="Close" style="background:rgba(255,255,255,.14);color:#fff;'
            + 'border:0;border-radius:9px;width:32px;height:32px;font-size:16px;cursor:pointer;">✕</button>'
        + '</div>'
        + '<div style="flex:0 0 auto;background:#ECFEFF;border-bottom:1px solid #A5F3FC;color:#155E75;'
          + 'padding:9px 16px;font-size:12.5px;display:flex;align-items:center;gap:10px;flex-wrap:wrap;">'
          + '<span><strong>Click anywhere on the form</strong> to add information there. Drag a box to move it.</span>'
          + '<button id="fa-clear" type="button" style="margin-left:auto;background:#fff;border:1px solid #A5F3FC;'
            + 'border-radius:8px;padding:5px 11px;font-size:12px;font-weight:700;color:#155E75;cursor:pointer;">Remove all</button>'
        + '</div>'
        + '<div id="fa-pages" style="flex:1;overflow:auto;padding:16px;background:#E8EEF4;min-height:0;">'
          + '<div id="fa-loading" style="text-align:center;color:#64748B;padding:40px;font-size:13.5px;">Loading the form…</div>'
        + '</div>'
        + '<div style="flex:0 0 auto;background:#fff;border-top:1px solid #E5E7EB;padding:12px 16px;display:flex;gap:10px;">'
          + '<button id="fa-cancel" type="button" style="background:#F1F5F9;color:#334155;border:0;border-radius:10px;'
            + 'padding:11px 16px;font-weight:700;font-size:13px;cursor:pointer;">Cancel</button>'
          + '<div style="flex:1;"></div>'
          + '<button id="fa-done" type="button" style="background:#065F46;color:#fff;border:0;border-radius:10px;'
            + 'padding:11px 20px;font-weight:800;font-size:13px;cursor:pointer;">Save the information</button>'
        + '</div>'
      + '</div>';

      document.body.appendChild(ov);
      var $ = function (s) { return ov.querySelector(s); };

      function close(val) {
        if (ov.parentNode) { ov.parentNode.removeChild(ov); }
        resolve(val);
      }
      function fail(msg) {
        $('#fa-pages').innerHTML = '<div style="text-align:center;color:#B91C1C;padding:40px;font-size:13.5px;">'
          + esc(msg) + '</div>';
      }
      function refreshCount() {
        var n = notes.filter(function (x) { return x.el && x.el.isConnected; }).length;
        $('#fa-count').textContent = n + (n === 1 ? ' added' : ' added');
      }

      $('#fa-x').onclick = function () { close(null); };
      $('#fa-cancel').onclick = function () { close(null); };
      $('#fa-clear').onclick = function () {
        notes.forEach(function (n) { if (n.el && n.el.parentNode) { n.el.parentNode.removeChild(n.el); } });
        notes = [];
        refreshCount();
      };

      /* ── one annotation box ──────────────────────────────────────────────── */
      function addNote(pageIdx, xPct, yPct, text) {
        var box = pageBoxes[pageIdx];
        if (!box) { return; }
        var el = document.createElement('div');
        el.style.cssText = 'position:absolute;left:' + (xPct * 100) + '%;top:' + (yPct * 100) + '%;'
          + 'min-width:170px;max-width:72%;background:rgba(236,254,255,.97);border:1.5px solid #0E7490;'
          + 'border-radius:7px;box-shadow:0 4px 14px rgba(8,20,40,.18);z-index:5;';
        el.innerHTML = '<div class="fa-grip" style="display:flex;align-items:center;gap:6px;cursor:move;'
            + 'background:#0E7490;color:#fff;padding:3px 7px;border-radius:5px 5px 0 0;font-size:10.5px;font-weight:800;">'
            + '<span style="flex:1;letter-spacing:.4px;">ADDED BY THE AGENCY</span>'
            + '<button type="button" class="fa-del" aria-label="Remove" style="background:transparent;border:0;color:#fff;'
              + 'font-size:14px;line-height:1;cursor:pointer;padding:0 2px;">✕</button>'
          + '</div>'
          + '<textarea class="fa-txt" rows="2" placeholder="Type the missing information…" '
            + 'style="width:100%;box-sizing:border-box;border:0;background:transparent;padding:6px 8px;'
            + 'font-size:12.5px;font-family:inherit;color:#0F172A;resize:vertical;outline:none;"></textarea>';
        box.appendChild(el);

        var rec = { page: pageIdx + 1, xPct: xPct, yPct: yPct, el: el, text: text || '' };
        notes.push(rec);
        var ta = el.querySelector('.fa-txt');
        ta.value = rec.text;
        ta.addEventListener('input', function () { rec.text = ta.value; });
        /* A click inside the box must not land on the page underneath and spawn a
           second annotation on top of the one being typed into. */
        el.addEventListener('mousedown', function (e) { e.stopPropagation(); });
        el.querySelector('.fa-del').onclick = function (e) {
          e.stopPropagation();
          if (el.parentNode) { el.parentNode.removeChild(el); }
          notes = notes.filter(function (n) { return n !== rec; });
          refreshCount();
        };

        /* drag by the grip, in page-relative percentages so it survives a resize */
        var grip = el.querySelector('.fa-grip');
        grip.addEventListener('mousedown', function (e) {
          if (e.target.classList.contains('fa-del')) { return; }
          e.preventDefault(); e.stopPropagation();
          var pr = box.getBoundingClientRect();
          var off = { x: e.clientX - el.getBoundingClientRect().left, y: e.clientY - el.getBoundingClientRect().top };
          function move(ev) {
            var nx = (ev.clientX - off.x - pr.left) / pr.width;
            var ny = (ev.clientY - off.y - pr.top) / pr.height;
            nx = Math.max(0, Math.min(0.97, nx));
            ny = Math.max(0, Math.min(0.99, ny));
            rec.xPct = nx; rec.yPct = ny;
            el.style.left = (nx * 100) + '%';
            el.style.top = (ny * 100) + '%';
          }
          function up() { document.removeEventListener('mousemove', move); document.removeEventListener('mouseup', up); }
          document.addEventListener('mousemove', move);
          document.addEventListener('mouseup', up);
        });

        refreshCount();
        setTimeout(function () { ta.focus(); }, 30);
      }

      /* ── render, then let the reviewer click ─────────────────────────────── */
      ensureLibs().then(function (libs) {
        return fetch(url, { credentials: 'omit' }).then(function (res) {
          if (!res.ok) { throw new Error('Could not load the form (' + res.status + ')'); }
          return res.arrayBuffer();
        }).then(function (buf) {
          originalBytes = buf;
          /* pdf.js takes ownership of the buffer it is given, so it gets a copy and
             pdf-lib keeps the pristine one for writing. */
          return libs.pdfjs.getDocument({ data: new Uint8Array(buf.slice(0)) }).promise;
        }).then(function (doc) {
          var host = $('#fa-pages');
          host.innerHTML = '';
          var chain = Promise.resolve();
          for (var i = 1; i <= doc.numPages; i++) {
            (function (pageNo) {
              chain = chain.then(function () {
                return doc.getPage(pageNo).then(function (page) {
                  var base = page.getViewport({ scale: 1 });
                  var avail = Math.min(host.clientWidth - 32, 880);
                  var scale = Math.max(0.4, avail / base.width);
                  var vp = page.getViewport({ scale: scale });

                  var wrap = document.createElement('div');
                  wrap.style.cssText = 'position:relative;margin:0 auto 16px;width:' + Math.floor(vp.width) + 'px;'
                    + 'box-shadow:0 2px 12px rgba(8,20,40,.18);background:#fff;cursor:crosshair;';
                  var canvas = document.createElement('canvas');
                  /* Capped at 2: at dpr 3 the canvas is 9x the pixel area for no
                     perceptible gain on a document this size. */
                  var dpr = Math.min(w.devicePixelRatio || 1, 2);
                  canvas.width = Math.floor(vp.width * dpr);
                  canvas.height = Math.floor(vp.height * dpr);
                  canvas.style.cssText = 'display:block;width:' + Math.floor(vp.width) + 'px;height:'
                    + Math.floor(vp.height) + 'px;';
                  wrap.appendChild(canvas);
                  host.appendChild(wrap);
                  pageBoxes[pageNo - 1] = wrap;

                  wrap.addEventListener('mousedown', function (e) {
                    if (e.button !== 0) { return; }
                    var r = wrap.getBoundingClientRect();
                    addNote(pageNo - 1, (e.clientX - r.left) / r.width, (e.clientY - r.top) / r.height, '');
                  });

                  var ctx = canvas.getContext('2d');
                  ctx.scale(dpr, dpr);
                  return page.render({ canvasContext: ctx, viewport: vp }).promise;
                });
              });
            })(i);
          }
          return chain;
        });
      }).catch(function (e) {
        fail((e && e.message) || 'The form could not be opened for editing.');
      });

      /* ── write the additions into the document ───────────────────────────── */
      $('#fa-done').onclick = function () {
        var live = notes.filter(function (n) {
          return n.el && n.el.isConnected && String(n.text || '').trim() !== '';
        });
        if (!live.length) { close(null); return; }     // nothing typed: same as cancel
        if (!originalBytes || !w.PDFLib) { fail('The form is not ready yet.'); return; }

        var btn = $('#fa-done');
        btn.disabled = true; btn.textContent = 'Saving…';

        var PDFLib = w.PDFLib;
        PDFLib.PDFDocument.load(originalBytes).then(function (pdf) {
          return pdf.embedFont(PDFLib.StandardFonts.Helvetica).then(function (font) {
            var pages = pdf.getPages();
            live.forEach(function (n) {
              var page = pages[n.page - 1];
              if (!page) { return; }
              var size = page.getSize();
              /* pdf-lib's origin is BOTTOM-left; the click was measured from the top. */
              var x = n.xPct * size.width + 3;
              var topY = size.height - (n.yPct * size.height);
              var lines = String(n.text).split(/\r?\n/);
              lines.forEach(function (line, i) {
                if (!line.trim()) { return; }
                page.drawText(line, {
                  x: x,
                  y: topY - 12 - (i * (FONT_PT + 2)),
                  size: FONT_PT,
                  font: font,
                  color: PDFLib.rgb(TEAL.r, TEAL.g, TEAL.b),
                  maxWidth: Math.max(80, size.width - x - 20)
                });
              });
            });
            return pdf.save();
          });
        }).then(function (bytes) {
          /* Chunked so a large document does not blow the argument limit of
             String.fromCharCode, which is how this fails on a 40-page scan. */
          var b = new Uint8Array(bytes), s = '', CH = 0x8000;
          for (var i = 0; i < b.length; i += CH) {
            s += String.fromCharCode.apply(null, b.subarray(i, i + CH));
          }
          close({
            base64: btoa(s),
            notes: live.map(function (n) { return { page: n.page, text: String(n.text).trim() }; })
          });
        }).catch(function (e) {
          btn.disabled = false; btn.textContent = 'Save the information';
          fail((e && e.message) || 'The information could not be written to the form.');
        });
      };
    });
  }

  KT.formAnnotate = { open: open };
})(window);
