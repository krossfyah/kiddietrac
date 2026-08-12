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

  /** The signed-in user's full name, for the printed signature block. */
  function signerName() {
    try {
      var u = JSON.parse(sessionStorage.getItem('kt_user') || localStorage.getItem('kt_user') || '{}');
      return ((u.first_name || '') + ' ' + (u.last_name || '')).trim() || u.name || '';
    } catch (e) { return ''; }
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
      // ── Zoom ──────────────────────────────────────────────────────────────
      // ONE transform on a single wrapper, not one per page: that keeps everything
      // on a single composited layer, so a pinch is a GPU transform rather than a
      // relayout of 100 field widgets every frame. Nothing that triggers layout
      // (margins, width) is touched during the gesture.
      var zoomWrap = document.createElement('div');
      zoomWrap.id = 'kt-ff-zoomwrap';
      zoomWrap.style.cssText = 'transform-origin:0 0;will-change:transform;';
      var zoom = 1, zoomRaf = 0;
      function paintZoom() {
        zoomRaf = 0;
        zoomWrap.style.transform = zoom === 1 ? '' : 'scale(' + zoom + ')';
        // Reserve the scaled footprint so the scroll range matches what is drawn.
        // Done on the SPACER, never on the transformed layer itself.
        // Reserve the SCALED footprint so the scroll range matches what is drawn.
        // Measured with the transform removed, then restored, so the number is the
        // layer's true unscaled height rather than a compounding one.
        var prev = zoomWrap.style.transform;
        zoomWrap.style.transform = '';
        var h = zoomWrap.offsetHeight, wdt = zoomWrap.offsetWidth;
        zoomWrap.style.transform = prev;
        var spacer = zoomWrap.__spacer;
        if (!spacer) {
          spacer = document.createElement('div');
          spacer.style.cssText = 'pointer-events:none;';
          zoomWrap.__spacer = spacer;
          zoomWrap.parentNode.appendChild(spacer);
        }
        spacer.style.height = Math.max(0, Math.round(h * (zoom - 1))) + 'px';
        spacer.style.width = Math.max(0, Math.round(wdt * (zoom - 1))) + 'px';
      }
      function applyZoom() { if (!zoomRaf) zoomRaf = (w.requestAnimationFrame || setTimeout)(paintZoom); }
      /** Zoom about a point in the scroller so the form grows where the fingers are. */
      function zoomTo(next, cx, cy) {
        next = Math.max(0.5, Math.min(3, next));
        if (next === zoom) return;
        var rect = scroll.getBoundingClientRect();
        var px = (scroll.scrollLeft + (cx - rect.left)) / zoom;
        var py = (scroll.scrollTop + (cy - rect.top)) / zoom;
        zoom = next;
        applyZoom();
        scroll.scrollLeft = px * zoom - (cx - rect.left);
        scroll.scrollTop = py * zoom - (cy - rect.top);
      }
      function zoomCentre(next) {
        var r = scroll.getBoundingClientRect();
        zoomTo(next, r.left + r.width / 2, r.top + r.height / 2);
      }
      ov.querySelector('#kt-ff-zoomin').addEventListener('click', function () { zoomCentre(zoom + 0.25); });
      ov.querySelector('#kt-ff-zoomout').addEventListener('click', function () { zoomCentre(zoom - 0.25); });

      // PINCH. The APK's WebView has built-in zoom disabled, so the browser gesture
      // never fires — we drive the same transform from raw touch points. Continuous
      // (no rounding to steps) and rAF-throttled, so it tracks the fingers smoothly.
      var pinchStart = 0, zoomStart = 1, pinching = false;
      function dist(t) {
        var dx = t[0].clientX - t[1].clientX, dy = t[0].clientY - t[1].clientY;
        return Math.sqrt(dx * dx + dy * dy);
      }
      function mid(t) { return { x: (t[0].clientX + t[1].clientX) / 2, y: (t[0].clientY + t[1].clientY) / 2 }; }
      scroll.addEventListener('touchstart', function (e) {
        if (e.touches.length !== 2) return;
        pinching = true; pinchStart = dist(e.touches); zoomStart = zoom;
      }, { passive: true });
      scroll.addEventListener('touchmove', function (e) {
        if (!pinching || e.touches.length !== 2 || !pinchStart) return;
        e.preventDefault();                       // the sheet must not scroll mid-pinch
        var m = mid(e.touches);
        zoomTo(zoomStart * (dist(e.touches) / pinchStart), m.x, m.y);
      }, { passive: false });
      function endPinch(e) { if (!e.touches || e.touches.length < 2) { pinching = false; pinchStart = 0; } }
      scroll.addEventListener('touchend', endPinch, { passive: true });
      scroll.addEventListener('touchcancel', endPinch, { passive: true });
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

      // Re-read the draft from the server on open. The caller passes what it had
      // when the list was rendered, which goes stale the moment a draft is saved —
      // relying on it alone is how "nothing was saved" happens.
      var draftReady = KT.Api.get('/managed-forms/assigned').then(function (d) {
        var list = (d && d.forms) || [];
        for (var i = 0; i < list.length; i++) {
          if (String(list[i].id) === String(form.id) && list[i].draft_values) {
            form.draftValues = list[i].draft_values;
            break;
          }
        }
      }).catch(function () { /* fall back to whatever the caller handed us */ });

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
            scroll.appendChild(zoomWrap);
            // Progress line. A large form (100 fields) takes many seconds to lay out,
            // and a silent blank page reads as "nothing happened".
            var prog = document.createElement('div');
            prog.style.cssText = 'padding:14px;text-align:center;color:#64748B;font-size:13px;';
            prog.textContent = 'Preparing page 1 of ' + doc.numPages + '…';
            zoomWrap.appendChild(prog);
            hint.textContent = 'Preparing the form…';

            // TWO PHASES, so the form becomes readable as fast as possible.
            //
            // Phase 1 paints every page. Phase 2 attaches the interactive fields,
            // which is the expensive half — pdf.js builds a DOM widget per field, and
            // on a 100-field form that blocked the thread long enough that the page
            // sat blank and the sheet looked broken. Painting first means the user
            // sees their actual form within a beat; the boxes light up right after.
            var deferred = [];
            var chain = Promise.resolve();
            for (var n = 1; n <= doc.numPages; n++) {
              (function (pageNo) {
                chain = chain.then(function () {
                  prog.textContent = 'Loading page ' + pageNo + ' of ' + doc.numPages + '…';
                  return yieldFrame().then(function () { return renderPage(libs, doc, pageNo, zoomWrap); });
                }).then(function (res) { deferred.push(res); });
              })(n);
            }
            return chain.then(function () {
              if (!deferred.some(function (d) { return d && d.widgetCount; })) { if (prog.parentNode) prog.remove(); return; }
              prog.textContent = 'Adding the fields you can type into…';
              var fchain = Promise.resolve();
              deferred.forEach(function (d, i) {
                if (!d || !d.widgetCount) return;
                fchain = fchain.then(function () {
                  prog.textContent = 'Adding fields' + (deferred.length > 1 ? ' — page ' + (i + 1) + ' of ' + deferred.length : '') + '…';
                  // Yield between pages so the UI stays responsive and the progress
                  // line actually repaints instead of freezing on one message.
                  return yieldFrame().then(function () { return d.attachFields(fieldMap); });
                }).then(function (n2) { fieldCount += n2; });
              });
              return fchain.then(function () { if (prog.parentNode) prog.remove(); });
            });
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
            return draftReady.then(function () {
            if (form.draftValues && fieldCount) {
              var restored = 0;
              Object.keys(form.draftValues).forEach(function (name) {
                var el = zoomWrap.querySelector('.annotationLayer [name="' + (w.CSS && CSS.escape ? CSS.escape(name) : name) + '"]');
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
            });
          })
          .then(function () {
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
            // Saving a draft ends the sitting — close the sheet rather than leaving
            // the user staring at the form wondering whether it took.
            dirty = false;                       // saved, so no "discard?" prompt
            if (KT.toast) KT.toast('💾', 'Draft saved', 'Your answers are kept until you sign.', '#0F766E');
            close(false);                        // false = not submitted; list reloads
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
          fillAndFlatten(pdfBytes, values, sigDataUrl, signerName(), new Date().toLocaleDateString(undefined,
            { year: 'numeric', month: 'long', day: 'numeric' }))
            .catch(function (err) {
              // Previously this swallowed the error and submitted with no completed
              // PDF — the record then fell back to the ORIGINAL blank form, which is
              // exactly the "submitted form is blank" report. Warn, and keep going so
              // the answers themselves are never lost.
              try { console.error('[kt-form] could not build the completed PDF:', err); } catch (e) {}
              msg.style.color = '#B45309';
              msg.textContent = 'Saved your answers, but the completed PDF could not be generated.';
              return null;
            })
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

  /** Let the browser paint before the next chunk of work. */
  function yieldFrame() {
    return new Promise(function (res) {
      (w.requestAnimationFrame || function (f) { setTimeout(f, 16); })(function () { setTimeout(res, 0); });
    });
  }

  /**
   * PHASE 1 — paint the page. Returns { widgetCount, attachFields() }; the caller
   * runs attachFields() afterwards so the document is visible first.
   */
  function renderPage(libs, doc, pageNo, host) {
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
          if (!widgets.length) return { widgetCount: 0, attachFields: function () { return Promise.resolve(0); } };
          return { widgetCount: widgets.length, attachFields: function (fieldMap) {
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
          return Promise.resolve(widgets.length);
          } };
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
  function fillAndFlatten(originalBytes, values, sigDataUrl, signerName, signedOn) {
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
        // ── Signature page ───────────────────────────────────────────────────
        // Stamping the block into a corner of the last page kept colliding with the
        // form's own content — there is no reliable way to know what is already
        // printed there. So the signature gets CLEAN SPACE: if the last page has a
        // clear band at the foot (no form field within it, and the page is tall
        // enough) the block goes there; otherwise a dedicated page is appended.
        // Either way it is never drawn over anything.
        return (sigDataUrl ? pdfDoc.embedPng(sigDataUrl) : Promise.resolve(null)).then(function (png) {
          return Promise.all([
            pdfDoc.embedFont(PDFLib.StandardFonts.Helvetica),
            pdfDoc.embedFont(PDFLib.StandardFonts.HelveticaBold),
          ]).then(function (fonts) {
            var font = fonts[0], bold = fonts[1];
            var ink = PDFLib.rgb(0.06, 0.09, 0.16);
            var grey = PDFLib.rgb(0.42, 0.47, 0.55);
            var line = PDFLib.rgb(0.80, 0.84, 0.89);

            var pages = pdfDoc.getPages();
            var last = pages[pages.length - 1];
            var pw = last.getWidth(), ph = last.getHeight();

            var BLOCK_H = 120;                 // the band the block needs
            var MARGIN = 36;

            // ALWAYS a dedicated signature page.
            //
            // The previous version tried to detect a clear band at the foot of the
            // last page by checking form-field rectangles. That cannot work: a field
            // rectangle says nothing about the form's own PRINTED content, so on a
            // real inspection sheet it reported "clear" and stamped the block over
            // the page — verified by extracting the text of a signed PDF, where the
            // block landed mid-content ("…New Area located to ELECTRONICALLY SIGNED
            // Anthony Hosein …"). There is no dependable way to know what is already
            // drawn on a page, so we stop guessing and give the signature its own
            // page every time. Predictable, never overlapping, always legible.
            var page = pdfDoc.addPage([pw, ph]);
            var top = ph - MARGIN - 56;
            page.drawText('Signature', { x: MARGIN, y: ph - MARGIN - 16, size: 16, font: bold, color: ink });
            page.drawText('This page forms part of the completed document.', {
              x: MARGIN, y: ph - MARGIN - 32, size: 9, font: font, color: grey,
            });

            var boxW = Math.min(340, pw - MARGIN * 2);
            var x = MARGIN;
            var y = top - BLOCK_H;

            page.drawRectangle({
              x: x, y: y, width: boxW, height: BLOCK_H,
              color: PDFLib.rgb(1, 1, 1), borderColor: line, borderWidth: 1,
            });

            var PAD = 14;
            var cy = y + BLOCK_H - PAD - 6;
            page.drawText('ELECTRONICALLY SIGNED', { x: x + PAD, y: cy, size: 7, font: bold, color: grey });

            // Signature, scaled on BOTH axes so it can never spill out of the panel.
            var sigAreaH = 46, sigAreaW = boxW - PAD * 2;
            cy -= (sigAreaH + 8);
            if (png) {
              var ratio = png.height / png.width;
              var w2 = sigAreaW, h2 = w2 * ratio;
              if (h2 > sigAreaH) { h2 = sigAreaH; w2 = h2 / ratio; }
              page.drawImage(png, { x: x + PAD, y: cy + (sigAreaH - h2) / 2, width: w2, height: h2 });
            }

            cy -= 8;
            page.drawLine({ start: { x: x + PAD, y: cy }, end: { x: x + boxW - PAD, y: cy }, thickness: 0.8, color: line });

            cy -= 14;
            page.drawText(String(signerName || '').slice(0, 44) || '\u2014', {
              x: x + PAD, y: cy, size: 11, font: bold, color: ink,
            });

            cy -= 13;
            page.drawText('Date signed: ' + signedOn, { x: x + PAD, y: cy, size: 9, font: font, color: grey });

            if (formObj) { try { formObj.flatten(); } catch (e) {} }
            return pdfDoc.saveAsBase64();
          });
        });
      });
    }).catch(function () { return null; });
  }

  KT.formFiller = { open: open };
})(window);
