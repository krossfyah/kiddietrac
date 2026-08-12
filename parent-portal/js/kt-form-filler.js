/* ═══════════════════════════════════════════════════════════════════
   KiddieTrac — fill & sign a managed PDF form (2026-08-11).

   For managed forms flagged `fillable` at upload. The PDF's OWN AcroForm fields
   become real on-screen inputs, the recipient types into them, signs once at the
   bottom, and submits — on desktop and inside the APK.

   Why it works this way:
   • pdf.js renders each page to a canvas and, via its annotation layer, positions
     real HTML inputs exactly over the PDF's field widgets. We never guess where a
     field is — the coordinates come from the document itself.
   • pdf-lib then writes the typed values back into those same AcroForm fields,
     embeds the signature image, and flattens the result, so the completed PDF
     looks like the original with the answers printed in place. No server-side PDF
     library is involved (the host has none), and the original file is untouched.
   • Both libraries are loaded lazily from CDN on first use — the same pattern
     kt-walk-tracker.js already uses for Leaflet — so they cost nothing on screens
     that never open a form.

   Public: KT.formFiller.open({ id, title, fileUrl }) → Promise<boolean> (true when
   submitted). Falls back to a clear message if the PDF has no fillable fields.
   ═══════════════════════════════════════════════════════════════════ */
(function (w) {
  'use strict';
  if (w.KT_FORM_FILLER) return; w.KT_FORM_FILLER = true;
  var KT = (w.KT = w.KT || {});

  var PDFJS_URL = 'https://unpkg.com/pdfjs-dist@3.11.174/build/pdf.min.js';
  var PDFJS_WORKER = 'https://unpkg.com/pdfjs-dist@3.11.174/build/pdf.worker.min.js';
  var PDFLIB_URL = 'https://unpkg.com/pdf-lib@1.17.1/dist/pdf-lib.min.js';

  function loadScript(src) {
    return new Promise(function (res, rej) {
      var existing = document.querySelector('script[data-kt-lib="' + src + '"]');
      if (existing) { if (existing.dataset.loaded) return res(); existing.addEventListener('load', function () { res(); }); existing.addEventListener('error', rej); return; }
      var s = document.createElement('script');
      s.src = src; s.async = true; s.setAttribute('data-kt-lib', src);
      s.onload = function () { s.dataset.loaded = '1'; res(); };
      s.onerror = function () { rej(new Error('Could not load ' + src)); };
      document.head.appendChild(s);
    });
  }

  function ensureLibs() {
    return loadScript(PDFJS_URL)
      .then(function () {
        var lib = w.pdfjsLib || (w.pdfjsDistBuildPdf);
        if (!lib) throw new Error('PDF viewer failed to load');
        try { lib.GlobalWorkerOptions.workerSrc = PDFJS_WORKER; } catch (e) {}
        return loadScript(PDFLIB_URL);
      })
      .then(function () {
        if (!w.PDFLib) throw new Error('PDF writer failed to load');
        return { pdfjs: w.pdfjsLib, pdflib: w.PDFLib };
      });
  }

  // Storage is served by BOTH hosts, but only the app origin is same-origin to us.
  // api.kiddietrac.com sends no Access-Control-Allow-Origin for /storage, so
  // fetching the bytes cross-origin is blocked — and the service worker turned that
  // failure into the HTML shell, which surfaced as "Invalid PDF structure". Always
  // read the file from our own origin.
  function absUrl(u) {
    if (!u) return '';
    var path = String(u);
    var m = /^https?:\/\/[^/]+(\/.*)$/.exec(path);
    if (m) path = m[1];                       // strip whatever host was handed to us
    if (path.charAt(0) !== '/') path = '/' + path;
    return w.location.origin + path;
  }

  function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
      return ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c];
    });
  }

  /** Full-screen sheet. Mobile gets the whole viewport; desktop a tall card. */
  function buildSheet(title) {
    var ov = document.createElement('div');
    ov.id = 'kt-ff';
    ov.setAttribute('data-no-modal-guard', '1');
    ov.style.cssText = 'position:fixed;inset:0;z-index:2147480000;background:rgba(8,17,33,.72);'
      + 'display:flex;align-items:center;justify-content:center;padding:0;';
    if (!document.getElementById('kt-ff-css')) {
      var st = document.createElement('style'); st.id = 'kt-ff-css';
      st.textContent = [
        '#kt-ff .kt-ff-card{background:#F6F9FC;width:100%;height:100%;display:flex;flex-direction:column;overflow:hidden;}',
        '@media(min-width:769px){#kt-ff{padding:20px;}#kt-ff .kt-ff-card{max-width:900px;height:min(94vh,1000px);border-radius:16px;box-shadow:0 30px 80px -20px rgba(8,20,40,.6);}}',
        '#kt-ff .kt-ff-scroll{flex:1;overflow:auto;-webkit-overflow-scrolling:touch;padding:14px;touch-action:pan-x pan-y pinch-zoom;}',
        // The page wrapper is positioned so pdf.js can place field widgets over it.
        '#kt-ff .kt-ff-page{position:relative;margin:0 auto 16px;background:#fff;box-shadow:0 2px 10px rgba(15,23,42,.14);border-radius:6px;overflow:hidden;width:fit-content;}',
        '#kt-ff .kt-ff-page canvas{display:block;}',
        '#kt-ff .annotationLayer{position:absolute;inset:0;}',
        // pdf.js gives widgets absolute positions; we only make them legible.
        '#kt-ff .annotationLayer section{position:absolute;}',
        '#kt-ff .annotationLayer input,#kt-ff .annotationLayer textarea,#kt-ff .annotationLayer select{',
        '  width:100%;height:100%;box-sizing:border-box;font-size:13px !important;padding:2px 4px !important;',
        '  border:1.5px solid #1F6FB2 !important;border-radius:4px !important;background:rgba(239,246,255,.85) !important;',
        '  color:#0F172A !important;min-height:0 !important;font-family:inherit;}',
        '#kt-ff .annotationLayer input[type="checkbox"],#kt-ff .annotationLayer input[type="radio"]{',
        '  width:100%;height:100%;accent-color:#1F6FB2;padding:0 !important;}',
        // Action row: full-width pair on a phone (thumb reach), right-aligned on
        // desktop. One shape for both buttons so they read as a set.
        '#kt-ff .kt-ff-actions{display:flex;gap:10px;}',
        '#kt-ff .kt-ff-btn{flex:1;min-height:46px;border-radius:12px;font-size:15px;font-weight:800;cursor:pointer;',
        '  display:inline-flex;align-items:center;justify-content:center;gap:7px;border:1.5px solid transparent;',
        '  transition:filter .15s ease,background .15s ease;font-family:inherit;}',
        '#kt-ff .kt-ff-btn:active{filter:brightness(.95);}',
        '#kt-ff .kt-ff-btn:disabled{opacity:.55;cursor:not-allowed;}',
        '#kt-ff .kt-ff-btn--ghost{background:#fff;color:#1F6080;border-color:#CBD5E1;}',
        '#kt-ff .kt-ff-btn--ghost:hover{background:#F8FAFC;}',
        '#kt-ff .kt-ff-btn--go{background:linear-gradient(135deg,#16A34A,#15803D);color:#fff;}',
        '@media(min-width:769px){#kt-ff .kt-ff-actions{justify-content:flex-end;}',
        '  #kt-ff .kt-ff-btn{flex:0 0 auto;min-height:40px;padding:0 20px;font-size:14px;}}',
        '#kt-ff .annotationLayer input:focus,#kt-ff .annotationLayer textarea:focus{',
        '  outline:none !important;border-color:#0FA3B1 !important;background:#fff !important;',
        '  box-shadow:0 0 0 3px rgba(15,163,177,.22) !important;}',
      ].join('\n');
      document.head.appendChild(st);
    }
    ov.innerHTML =
      '<div class="kt-ff-card">'
      + '<div style="background:#0B2545;color:#fff;padding:13px 16px;flex:0 0 auto;display:flex;align-items:center;gap:12px;">'
      + '  <div style="min-width:0;flex:1;">'
      + '    <div style="font-size:10.5px;font-weight:800;letter-spacing:1.2px;opacity:.75;">FILL &amp; SIGN</div>'
      + '    <div style="font-size:16px;font-weight:800;margin-top:2px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">' + esc(title) + '</div>'
      + '  </div>'
      + '  <button id="kt-ff-zoomout" type="button" aria-label="Zoom out" style="background:rgba(255,255,255,.14);color:#fff;border:0;border-radius:9px;width:34px;height:34px;font-size:18px;line-height:1;cursor:pointer;flex:0 0 auto;">−</button>'
      + '  <button id="kt-ff-zoomin" type="button" aria-label="Zoom in" style="background:rgba(255,255,255,.14);color:#fff;border:0;border-radius:9px;width:34px;height:34px;font-size:18px;line-height:1;cursor:pointer;flex:0 0 auto;">+</button>'
      + '  <button id="kt-ff-close" type="button" aria-label="Close" style="background:rgba(255,255,255,.14);color:#fff;border:0;border-radius:9px;width:34px;height:34px;font-size:17px;line-height:1;cursor:pointer;flex:0 0 auto;">✕</button>'
      + '</div>'
      + '<div id="kt-ff-hint" style="flex:0 0 auto;background:#EFF6FF;color:#1E40AF;font-size:12.5px;padding:9px 16px;border-bottom:1px solid #DBEAFE;">Tap a highlighted box to type. Scroll for more pages.</div>'
      + '<div class="kt-ff-scroll" id="kt-ff-scroll"><div style="padding:40px;text-align:center;color:#64748B;font-size:13.5px;">Opening the form…</div></div>'
      + '<div style="flex:0 0 auto;background:#fff;border-top:1px solid #E7EDF3;padding:10px 14px calc(env(safe-area-inset-bottom,0px) + 12px);">'
      + '  <div id="kt-ff-msg" style="font-size:12.5px;color:#64748B;min-height:16px;margin-bottom:8px;"></div>'
      + '  <div class="kt-ff-actions">'
      + '    <button id="kt-ff-draft" type="button" class="kt-ff-btn kt-ff-btn--ghost">Save draft</button>'
      + '    <button id="kt-ff-submit" type="button" class="kt-ff-btn kt-ff-btn--go">Sign &amp; submit</button>'
      + '  </div>'
      + '</div>'
      + '</div>';
    return ov;
  }

  /**
   * Open the filler. Resolves true once the form has been submitted.
   */
  function open(form) {
    return new Promise(function (resolve) {
      var ov = buildSheet(form.title || 'Form');
      document.body.appendChild(ov);
      var prevOverflow = document.body.style.overflow;
      document.body.style.overflow = 'hidden';

      var scroll = ov.querySelector('#kt-ff-scroll');
      // Zoom by transforming the rendered pages. Scaling the wrapper moves the
      // canvas AND its field overlay together, so inputs never drift off their
      // boxes — and it is instant, unlike re-rendering every page at a new scale.
      var zoom = 1;
      function applyZoom() {
        var pages = scroll.querySelectorAll('.kt-ff-page');
        for (var i = 0; i < pages.length; i++) {
          pages[i].style.transformOrigin = 'top left';
          pages[i].style.transform = zoom === 1 ? '' : 'scale(' + zoom + ')';
          pages[i].style.marginBottom = (16 * zoom) + 'px';
        }
      }
      ov.querySelector('#kt-ff-zoomin').addEventListener('click', function () {
        zoom = Math.min(2.5, Math.round((zoom + 0.25) * 100) / 100); applyZoom();
      });
      ov.querySelector('#kt-ff-zoomout').addEventListener('click', function () {
        zoom = Math.max(0.5, Math.round((zoom - 0.25) * 100) / 100); applyZoom();
      });
      var msg = ov.querySelector('#kt-ff-msg');
      var submitBtn = ov.querySelector('#kt-ff-submit');
      var hint = ov.querySelector('#kt-ff-hint');
      var done = false;

      function close(result) {
        if (done) return; done = true;
        document.body.style.overflow = prevOverflow;
        ov.remove();
        resolve(!!result);
      }
      ov.querySelector('#kt-ff-close').addEventListener('click', function () {
        if (dirty && !w.confirm('Close without submitting? Anything you typed will be lost.')) return;
        close(false);
      });

      var pdfBytes = null;      // original file, reused by pdf-lib on submit
      var annotationStorage = null;
      var fieldCount = 0;
      var dirty = false;
      // pdf.js keys its annotation storage by ANNOTATION ID ("9R", "13R"), not by
      // field name. Stored raw, the answers were unreadable and pdf-lib could not
      // find the fields to write into. Map id → fieldName while rendering.
      var fieldMap = {};

      ensureLibs().then(function (libs) {
        return fetch(absUrl(form.fileUrl), { credentials: 'omit' })
          .then(function (r) {
            if (!r.ok) throw new Error('Could not download the form (' + r.status + ')');
            return r.arrayBuffer();
          })
          .then(function (buf) {
            pdfBytes = buf.slice(0);          // keep a pristine copy for pdf-lib
            return libs.pdfjs.getDocument({ data: new Uint8Array(buf.slice(0)) }).promise;
          })
          .then(function (doc) {
            annotationStorage = doc.annotationStorage;
            scroll.innerHTML = '';
            // Progress line. A large form (100 fields) takes many seconds to lay out,
            // and a silent blank page reads as "nothing happened".
            var prog = document.createElement('div');
            prog.style.cssText = 'padding:14px;text-align:center;color:#64748B;font-size:13px;';
            prog.textContent = 'Preparing page 1 of ' + doc.numPages + '…';
            scroll.appendChild(prog);
            hint.textContent = 'Preparing the form…';

            var chain = Promise.resolve();
            for (var n = 1; n <= doc.numPages; n++) {
              (function (pageNo) {
                chain = chain.then(function () {
                  prog.textContent = 'Preparing page ' + pageNo + ' of ' + doc.numPages + '…';
                  // Yield a frame so the message actually paints before the heavy
                  // render work blocks the thread.
                  return new Promise(function (r) { setTimeout(r, 0); })
                    .then(function () { return renderPage(libs, doc, pageNo, scroll, fieldMap); });
                }).then(function (widgets) { fieldCount += widgets; });
              })(n);
            }
            return chain.then(function () { if (prog.parentNode) prog.remove(); });
          })
          .then(function () {
            if (!fieldCount) {
              // Honest failure: the admin ticked "fillable" but this PDF carries no
              // form fields, so there is nothing to type into. Say so plainly rather
              // than showing an inert page.
              hint.style.background = '#FFFBEB'; hint.style.color = '#92400E'; hint.style.borderBottomColor = '#FDE68A';
              hint.textContent = 'This PDF has no fillable fields — you can read it and sign it, but there is nothing to type into.';
            } else {
              hint.textContent = fieldCount === 1
                ? 'Tap the highlighted box to type. Then sign and submit.'
                : 'Tap any highlighted box to type (' + fieldCount + ' fields). Then sign and submit.';
            }
            // Put a saved draft back into the fields. Setting .value alone would look
            // right but leave pdf.js's annotation storage empty, so the answers would
            // not be submitted — dispatch input/change so the storage updates too.
            if (form.draftValues && fieldCount) {
              var restored = 0;
              Object.keys(form.draftValues).forEach(function (name) {
                var el = scroll.querySelector('.annotationLayer [name="' + (w.CSS && CSS.escape ? CSS.escape(name) : name) + '"]');
                if (!el) return;
                var v = form.draftValues[name];
                if (el.type === 'checkbox' || el.type === 'radio') {
                  var on = (v === true || v === 'true' || v === 'On');
                  if (el.checked !== on) { el.click(); restored++; }
                } else {
                  el.value = String(v);
                  el.dispatchEvent(new Event('input', { bubbles: true }));
                  el.dispatchEvent(new Event('change', { bubbles: true }));
                  restored++;
                }
              });
              if (restored) {
                msg.style.color = '#0F766E';
                msg.textContent = 'Your saved draft has been restored (' + restored + ' answers).';
              }
            }
            scroll.addEventListener('input', function () { dirty = true; }, true);
            scroll.addEventListener('change', function () { dirty = true; }, true);
          })
          .catch(function (e) {
            scroll.innerHTML = '<div style="padding:30px;text-align:center;color:#B91C1C;font-size:13.5px;">'
              + esc((e && e.message) || 'Could not open this form.') + '</div>';
            submitBtn.disabled = true;
          });
      }).catch(function (e) {
        scroll.innerHTML = '<div style="padding:30px;text-align:center;color:#B91C1C;font-size:13.5px;">'
          + esc((e && e.message) || 'Could not load the PDF viewer.') + '</div>';
        submitBtn.disabled = true;
      });

      var draftBtn = ov.querySelector('#kt-ff-draft');
      draftBtn.addEventListener('click', function () {
        draftBtn.disabled = true; draftBtn.textContent = 'Saving…';
        msg.style.color = '#64748B'; msg.textContent = '';
        KT.Api.post('/managed-forms/' + form.id + '/draft', { field_values: collectValues(annotationStorage, fieldMap) })
          .then(function () {
            dirty = false;                       // saved — closing is now safe
            draftBtn.disabled = false; draftBtn.textContent = 'Save draft';
            msg.style.color = '#0F766E';
            msg.textContent = 'Draft saved — you can come back and finish this later.';
            if (KT.toast) KT.toast('💾', 'Draft saved', 'Your answers are kept until you sign.', '#0F766E');
          })
          .catch(function (e) {
            draftBtn.disabled = false; draftBtn.textContent = 'Save draft';
            msg.style.color = '#B91C1C';
            msg.textContent = (e && e.message) || 'Could not save the draft.';
          });
      });

      submitBtn.addEventListener('click', function () {
        if (!KT.signaturePad) {
          msg.style.color = '#B91C1C'; msg.textContent = 'Signature pad unavailable.';
          return;
        }
        var values = collectValues(annotationStorage, fieldMap);
        KT.signaturePad({
          title: 'Sign: ' + (form.title || 'form'),
          subtitle: 'Draw your signature to submit this completed form.',
          okLabel: 'Submit form',
        }).then(function (sigDataUrl) {
          if (!sigDataUrl) return;
          submitBtn.disabled = true; submitBtn.textContent = 'Submitting…';
          msg.style.color = '#64748B'; msg.textContent = 'Preparing your completed form…';
          fillAndFlatten(pdfBytes, values, sigDataUrl)
            .catch(function () { return null; })   // never block the submit on PDF writing
            .then(function (filledB64) {
              return KT.Api.post('/managed-forms/' + form.id + '/sign', {
                signature: sigDataUrl,
                field_values: values,
                filled_file: filledB64 || null,
              });
            })
            .then(function () {
              if (KT.toast) KT.toast('✅', 'Form submitted', 'Thank you — your completed form has been filed.', '#16A34A');
              close(true);
            })
            .catch(function (e) {
              submitBtn.disabled = false; submitBtn.textContent = 'Sign & submit';
              msg.style.color = '#B91C1C';
              msg.textContent = (e && e.message) || 'Could not submit — please try again.';
            });
        });
      });
    });
  }

  /** Render one page + its interactive field widgets. Returns the widget count. */
  function renderPage(libs, doc, pageNo, host, fieldMap) {
    return doc.getPage(pageNo).then(function (page) {
      // Sizing. Fitting a Letter page (612pt) into a ~360px phone gives scale ~0.55,
      // which is what made the form unreadably tiny in the APK — a 100-field form
      // ends up with 8px boxes. On a narrow screen we render at a MINIMUM legible
      // scale and let the page scroll sideways instead of shrinking to fit.
      var avail = Math.max(280, Math.min(host.clientWidth - 28, 860));
      var base = page.getViewport({ scale: 1 });
      var fit = avail / base.width;
      var narrow = host.clientWidth < 700;
      var scale = narrow ? Math.max(fit, 1.15) : Math.min(2, fit);
      var viewport = page.getViewport({ scale: scale });

      var wrap = document.createElement('div');
      wrap.className = 'kt-ff-page';
      wrap.style.width = Math.floor(viewport.width) + 'px';
      wrap.style.height = Math.floor(viewport.height) + 'px';
      var canvas = document.createElement('canvas');
      // Render at device resolution so text stays crisp on phones.
      // Cap at 2: at dpr 3 the canvas is 9x the pixel area for no perceptible gain,
      // and that raster cost is a big part of why the form took so long in the APK.
      var dpr = Math.min(2, w.devicePixelRatio || 1);
      canvas.width = Math.floor(viewport.width * dpr);
      canvas.height = Math.floor(viewport.height * dpr);
      canvas.style.width = Math.floor(viewport.width) + 'px';
      canvas.style.height = Math.floor(viewport.height) + 'px';
      wrap.appendChild(canvas);
      host.appendChild(wrap);

      var ctx = canvas.getContext('2d');
      ctx.scale(dpr, dpr);
      return page.render({ canvasContext: ctx, viewport: viewport }).promise
        .then(function () { return page.getAnnotations({ intent: 'display' }); })
        .then(function (annotations) {
          var widgets = (annotations || []).filter(function (a) { return a.subtype === 'Widget'; });
          if (!widgets.length) return 0;
          widgets.forEach(function (a) {
            if (fieldMap && a && a.id != null && a.fieldName) fieldMap[String(a.id)] = a.fieldName;
          });
          var layer = document.createElement('div');
          layer.className = 'annotationLayer';
          wrap.appendChild(layer);

          var linkService = {
            getDestinationHash: function () { return ''; },
            getAnchorUrl: function () { return ''; },
            addLinkAttributes: function () {},
            externalLinkTarget: 0,
          };
          var params = {
            viewport: viewport.clone({ dontFlip: true }),
            div: layer,
            annotations: annotations,
            page: page,
            renderInteractiveForms: true,          // pdf.js 2.x name
            renderForms: true,                     // pdf.js 3.x name
            annotationStorage: doc.annotationStorage,
            linkService: linkService,
            downloadManager: null,
            enableScripting: false,
          };
          // pdf.js changed this API between majors: v2 exposes a STATIC
          // AnnotationLayer.render(params); v3 makes AnnotationLayer a class you
          // construct with the page/viewport and then call .render() on. Support
          // both so a CDN version bump can't silently kill form fields.
          var AL = libs.pdfjs.AnnotationLayer;
          if (AL && typeof AL.render === 'function') {
            AL.render(params);
          } else if (typeof AL === 'function') {
            new AL({
              div: layer,
              accessibilityManager: null,
              annotationCanvasMap: null,
              l10n: undefined,
              page: page,
              viewport: params.viewport,
            }).render(params);
          } else {
            throw new Error('This PDF viewer build cannot render form fields.');
          }
          return widgets.length;
        });
    });
  }

  /**
   * FIELD NAME → value, out of pdf.js's annotation storage. The storage is keyed by
   * annotation id, so translate through the id→name map built during render; only
   * fall back to the raw id if a widget somehow had no field name.
   */
  function collectValues(storage, fieldMap) {
    var out = {};
    try {
      var all = storage && storage.getAll ? storage.getAll() : null;
      if (!all) return out;
      Object.keys(all).forEach(function (k) {
        var v = all[k];
        var val = (v && typeof v === 'object' && 'value' in v) ? v.value : v;
        if (val === null || val === undefined || val === '') return;
        out[(fieldMap && fieldMap[k]) || k] = val;
      });
    } catch (e) {}
    return out;
  }

  /**
   * Write the answers into the PDF's own fields, stamp the signature on the last
   * page, and flatten. Returns base64 (no data: prefix), or null on any failure —
   * the submit still goes through with the raw values, so a PDF-writing problem
   * can never cost the user their answers.
   */
  function fillAndFlatten(originalBytes, values, sigDataUrl) {
    return Promise.resolve().then(function () {
      var PDFLib = w.PDFLib;
      if (!PDFLib || !originalBytes) return null;
      return PDFLib.PDFDocument.load(originalBytes).then(function (pdfDoc) {
        var formObj = null;
        try { formObj = pdfDoc.getForm(); } catch (e) { formObj = null; }
        if (formObj) {
          Object.keys(values).forEach(function (name) {
            var v = values[name];
            try {
              var f = formObj.getField(name);
              var t = f.constructor && f.constructor.name;
              if (t === 'PDFCheckBox') { if (v === true || v === 'true' || v === 'On') f.check(); else f.uncheck(); }
              else if (t === 'PDFDropdown' || t === 'PDFOptionList') { f.select(String(v)); }
              else if (t === 'PDFRadioGroup') { f.select(String(v)); }
              else { f.setText(String(v)); }
            } catch (e) { /* a field we can't set must not abort the rest */ }
          });
        }
        // Signature: stamp bottom-right of the last page, above the margin.
        return (sigDataUrl ? pdfDoc.embedPng(sigDataUrl) : Promise.resolve(null)).then(function (png) {
          if (png) {
            var pages = pdfDoc.getPages();
            var last = pages[pages.length - 1];
            var pw = last.getWidth();
            var targetW = Math.min(180, pw * 0.36);
            var ratio = png.height / png.width;
            last.drawImage(png, { x: pw - targetW - 40, y: 40, width: targetW, height: targetW * ratio });
          }
          if (formObj) { try { formObj.flatten(); } catch (e) {} }
          return pdfDoc.saveAsBase64();
        });
      });
    }).catch(function () { return null; });
  }

  KT.formFiller = { open: open };
})(window);
