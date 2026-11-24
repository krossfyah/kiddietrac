/* ───────────────────────────────────────────────────────────────────
   KiddieTrac — THE signature pads (2026-08-10, saved signatures 2026-11-06)

   KT.signaturePad(opts)              → Promise<dataURL|null>   full-screen popup
   KT.signaturePadInline(host, opts)  → handle                  pad drawn inside `host`
   KT.savedSignature                  → get / use / save / remove / renderInto(pane)

   opts (both pads):
     kind       'signature' (default) | 'initials'
     context    what is being signed, for the evidence row ("managed_form:12")
     allowSaved false hides "Use my saved signature"   (default: offered when one is saved)
     allowSave  false hides "Save this for next time"  (default: offered when signed in)
   popup only: title, subtitle, okLabel, saveOnly (My settings: draw in order to save)
   inline only: height, hint, onChange(isEmpty) - called whenever the ink changes (after each stroke)

   SAVED SIGNATURES (Anthony, 2026-11-06: "save a signature to be reused that is
   authorized by the user"). Saving needs the box ticked, with the authorization words
   beside it; applying needs a tap on "Use my saved signature", with the consent line
   beside it, and every tap is recorded server-side (SavedSignatureController). Nothing
   is ever applied for anyone automatically, and a person being viewed-as has none.

   EVERY SIGNING SCREEN USES ONE OF THESE TWO PADS. kt:signature-pad-check fails on a
   screen that draws its own signature canvas, so a new one cannot quietly miss the
   saved signature (or the fixes these pads carry).
   ─────────────────────────────────────────────────────────────────── */
/* NOTE: this pad is opened from full-screen sheets (the form filler at
   z-index 2147480000, the NDA gate at 2147481000). At its old z-index of 100050 it
   rendered BEHIND them — the user tapped "Sign & submit" and nothing appeared to
   happen. It must out-rank anything that can summon it. */
(function (window) {
  var KT = window.KT || (window.KT = {});

  var WORDS = {
    signature: { noun: 'signature', title: 'Sign here', hint: 'Sign here', use: 'Use my saved signature',
      save: 'Save this signature for next time', replace: 'Replace my saved signature with this one', h: 180 },
    initials: { noun: 'initials', title: 'Your initials', hint: 'Initial here', use: 'Use my saved initials',
      save: 'Save these initials for next time', replace: 'Replace my saved initials with these', h: 130 },
  };
  // The server's words win (SavedSignatureController::AUTHORIZE_TEXT / USE_TEXT); these show until it answers.
  var TEXT = {
    authorize: {
      signature: 'I authorize KiddieTrac to keep this signature for me. It will be applied to a document only when I tap "Use my saved signature" on that document, and doing so has the same effect as signing it by hand. I can remove it at any time in My settings.',
      initials: 'I authorize KiddieTrac to keep these initials for me. They will be applied to a document only when I tap "Use my saved initials" on that document, and doing so has the same effect as initialling it by hand. I can remove them at any time in My settings.',
    },
    use: {
      signature: 'By tapping, I sign this document with my saved signature.',
      initials: 'By tapping, I initial this document with my saved initials.',
    },
  };

  function esc(s) { return String(s == null ? '' : s).replace(/[&<>"]/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]; }); }
  function kindOf(o) { return o && o.kind === 'initials' ? 'initials' : 'signature'; }
  // Signed in to the portal? The public form/NDA/proposal pages have no KT.Api: no saved signatures there.
  function api() { return (KT.Api && typeof KT.Api.get === 'function') ? KT.Api : null; }

  /* ── saved signature store ───────────────────────────────────────────── */
  var cache = null;
  var Saved = {
    text: TEXT,
    /** {signature:{image,saved_at}|null, initials:…, viewing_as, uses} | null when not signed in. */
    get: function (fresh) {
      if (!api()) { return Promise.resolve(null); }
      if (!cache || fresh) {
        cache = api().get('/me/saved-signatures').then(function (d) {
          if (d && d.authorize_text) { TEXT.authorize = d.authorize_text; }
          if (d && d.use_text) { TEXT.use = d.use_text; }
          return d || null;
        }).catch(function () { cache = null; return null; });
      }
      return cache;
    },
    /** Apply the saved one to ONE document (recorded server-side). Resolves the image. */
    use: function (kind, context) {
      return api().post('/me/saved-signatures/' + kind + '/use', { context: context || null })
        .then(function (d) { if (!d || !d.image) { throw new Error('Your saved ' + WORDS[kind].noun + ' could not be loaded.'); } return d.image; });
    },
    save: function (kind, image) {
      return api().post('/me/saved-signatures', { kind: kind, image: image, agree: true })
        .then(function (d) { cache = null; return d; });
    },
    remove: function (kind) {
      return api().delete('/me/saved-signatures/' + kind).then(function (d) { cache = null; return d; });
    },
  };
  KT.savedSignature = Saved;

  function usable(d) { return !!(d && !d.viewing_as); }

  /* "Use my saved signature" + the consent line. onUse(btn) is called on the tap. */
  function savedBlock(kind, image, onUse) {
    var W = WORDS[kind];
    var box = document.createElement('div');
    box.style.cssText = 'display:flex;align-items:center;gap:12px;border:1.5px solid #2F8C86;background:#F0FDFA;border-radius:12px;padding:10px 12px;margin-bottom:10px;';
    box.innerHTML = '<img alt="" src="' + esc(image) + '" style="flex:0 0 auto;width:' + (kind === 'initials' ? 64 : 110) + 'px;height:44px;object-fit:contain;background:#fff;border:1px solid #E2E8F0;border-radius:8px;">'
      + '<div style="flex:1 1 auto;min-width:0;">'
      + '<button type="button" data-ktsig-use style="background:#1B3A63;color:#fff;border:0;border-radius:9px;padding:7px 12px;font-weight:800;font-size:13px;cursor:pointer;">' + esc(W.use) + '</button>'
      + '<div style="font-size:11.5px;color:#475569;margin-top:5px;line-height:1.4;">' + esc(TEXT.use[kind]) + '</div>'
      + '</div>';
    var b = box.querySelector('[data-ktsig-use]');
    b.onclick = function () { onUse(b); };
    return box;
  }

  /* "Save this for next time" + the authorization words. Returns {el, checked()}. */
  function saveRow(kind, replacing, required) {
    var W = WORDS[kind];
    var row = document.createElement('label');
    row.style.cssText = 'display:flex;gap:9px;align-items:flex-start;margin-top:10px;cursor:pointer;';
    row.innerHTML = '<input type="checkbox" data-ktsig-save style="width:17px;height:17px;flex:0 0 auto;margin-top:1px;accent-color:#2F8C86;">'
      + '<span style="font-size:12.5px;line-height:1.45;color:#334155;"><strong>' + esc(required ? 'I authorize saving my ' + W.noun : (replacing ? W.replace : W.save)) + '</strong>'
      + '<span style="display:block;color:#64748B;font-size:11.5px;margin-top:2px;">' + esc(TEXT.authorize[kind]) + '</span></span>';
    var cb = row.querySelector('[data-ktsig-save]');
    return { el: row, checked: function () { return !!cb.checked; } };
  }

  function toastSaveFailed(kind) {
    try { if (KT.toast) { KT.toast('⚠️', 'Not saved', 'Your ' + WORDS[kind].noun + ' was used for this document but could not be saved for next time.', '#B45309'); } } catch (e) {}
  }

  /* ── the popup pad ───────────────────────────────────────────────────── */
  KT.signaturePad = function (opts) {
    /* CALLED THE OLD WAY? SAY SO, DO NOT GUESS.

       The inline pad takes (hostElement, opts) and the overlay takes (opts). They shared
       a name for a month, so a caller written against the inline one reaches here with a
       DOM node as `opts` — and used to get a silent, wrong overlay whose Promise was then
       treated as a handle. Hand it to the real inline pad, and make the mistake visible
       rather than letting it fail three screens away. */
    if (opts && opts.nodeType === 1) {
      try { console.error('[kt-signature-pad] called with a host element — use KT.signaturePadInline(host, opts) for an in-page pad.'); } catch (e) {}
      return KT.signaturePadInline(opts, arguments[1] || {});
    }
    opts = opts || {};
    var kind = kindOf(opts), W = WORDS[kind];
    return new Promise(function (resolve) {
      var ov = document.createElement('div');
      ov.className = 'kt-scrim';
      ov.style.cssText = 'position:fixed;inset:0;z-index:2147482000;background:rgba(15,23,42,.55);display:flex;align-items:center;justify-content:center;padding:16px;';
      ov.innerHTML =
        '<div data-ktsig-card style="background:#fff;border-radius:16px;max-width:480px;width:100%;max-height:calc(100vh - 32px);display:flex;flex-direction:column;overflow:hidden;box-shadow:0 20px 60px rgba(0,0,0,.4);">' +
          '<div class="kt-dlg-head" style="flex:0 0 auto;padding:14px 18px;background:linear-gradient(135deg,#1B3A63 0%,#265B7A 50%,#2F8C86 100%);box-shadow:inset 0 -3px 0 #7FD3CC;color:#fff;">' +
            '<div style="font-size:16px;font-weight:800;">' + (opts.title || W.title) + '</div>' +
          '</div>' +
          '<div data-kt-scroll style="flex:1 1 auto;min-height:0;overflow-y:auto;padding:16px 18px;">' +
            '<div style="font-size:12.5px;color:#64748B;margin-bottom:12px;line-height:1.5;">' + (opts.subtitle || ('Draw your ' + W.noun + ' with your finger or mouse.')) + '</div>' +
            '<div data-ktsig-saved></div>' +
            '<canvas id="ktsig-c" style="width:100%;height:' + W.h + 'px;border:2px dashed #CBD5E1;border-radius:12px;background:#FBFDFF;touch-action:none;display:block;"></canvas>' +
            '<div data-ktsig-saverow></div>' +
            '<div data-ktsig-msg style="display:none;font-size:12.5px;color:#B91C1C;margin-top:8px;"></div>' +
          '</div>' +
          '<div style="flex:0 0 auto;display:flex;justify-content:space-between;align-items:center;padding:12px 18px;border-top:1px solid #EEF2F6;gap:8px;">' +
            '<button id="ktsig-clear" type="button" style="background:#F1F5F9;border:0;border-radius:10px;padding:9px 14px;font-weight:700;color:#475569;cursor:pointer;">Clear</button>' +
            '<div style="display:flex;gap:8px;">' +
              '<button id="ktsig-cancel" type="button" style="background:#F1F5F9;border:0;border-radius:10px;padding:9px 16px;font-weight:700;color:#475569;cursor:pointer;">Cancel</button>' +
              '<button id="ktsig-ok" type="button" style="background:#065F46;color:#fff;border:0;border-radius:10px;padding:9px 18px;font-weight:800;cursor:pointer;">' + (opts.okLabel || (opts.saveOnly ? 'Save' : 'Confirm')) + '</button>' +
            '</div>' +
          '</div>' +
        '</div>';
      document.body.appendChild(ov);
      var canvas = ov.querySelector('#ktsig-c'), ctx = canvas.getContext('2d');
      var msg = ov.querySelector('[data-ktsig-msg]');
      function say(t) { msg.textContent = t || ''; msg.style.display = t ? '' : 'none'; }
      function fit() {
        var r = canvas.getBoundingClientRect();
        canvas.width = Math.max(1, r.width * 2); canvas.height = Math.max(1, r.height * 2);
        ctx.scale(2, 2); ctx.lineWidth = kind === 'initials' ? 2.8 : 2.4; ctx.lineCap = 'round'; ctx.lineJoin = 'round'; ctx.strokeStyle = '#0F172A';
      }
      setTimeout(fit, 30);
      var drawing = false, has = false, last = null;
      function pos(e) { var r = canvas.getBoundingClientRect(); var t = (e.touches && e.touches[0]) || e; return { x: t.clientX - r.left, y: t.clientY - r.top }; }
      function down(e) { drawing = true; has = true; last = pos(e); say(''); e.preventDefault(); }
      function move(e) { if (!drawing) return; var p = pos(e); ctx.beginPath(); ctx.moveTo(last.x, last.y); ctx.lineTo(p.x, p.y); ctx.stroke(); last = p; e.preventDefault(); }
      function up() { drawing = false; }
      canvas.addEventListener('mousedown', down); canvas.addEventListener('mousemove', move); window.addEventListener('mouseup', up);
      canvas.addEventListener('touchstart', down, { passive: false }); canvas.addEventListener('touchmove', move, { passive: false }); canvas.addEventListener('touchend', up);
      var finished = false;
      var done = function (v) { if (finished) return; finished = true; window.removeEventListener('mouseup', up); ov.remove(); resolve(v); };

      var save = null;
      Saved.get().then(function (d) {
        if (finished) return;
        if (!usable(d)) {
          if (opts.saveOnly) { say(d && d.viewing_as ? 'Only the person themselves can save their ' + W.noun + '.' : 'Please sign in to save your ' + W.noun + '.'); }
          return;
        }
        var have = d[kind];
        if (have && have.image && !opts.saveOnly && opts.allowSaved !== false) {
          ov.querySelector('[data-ktsig-saved]').appendChild(savedBlock(kind, have.image, function (btn) {
            btn.disabled = true; btn.textContent = 'Applying…';
            Saved.use(kind, opts.context).then(done).catch(function (e) {
              btn.disabled = false; btn.textContent = W.use;
              say((e && e.message) || ('Your saved ' + W.noun + ' could not be used. Please draw it instead.'));
            });
          }));
        }
        if (opts.saveOnly || opts.allowSave !== false) {
          save = saveRow(kind, !!(have && have.image), !!opts.saveOnly);
          ov.querySelector('[data-ktsig-saverow]').appendChild(save.el);
        }
      });

      ov.querySelector('#ktsig-clear').onclick = function () { ctx.clearRect(0, 0, canvas.width, canvas.height); has = false; };
      ov.querySelector('#ktsig-cancel').onclick = function () { done(null); };
      ov.addEventListener('click', function (e) { if (e.target === ov) done(null); });
      ov.querySelector('#ktsig-ok').onclick = function () {
        if (!has) { say(kind === 'initials' ? 'Please add your initials first.' : 'Please sign first.'); return; }
        var img = canvas.toDataURL('image/png');
        if (opts.saveOnly) {
          if (!save || !save.checked()) { say('Tick the box to authorize saving your ' + W.noun + '.'); return; }
          var ok = ov.querySelector('#ktsig-ok'); ok.disabled = true; ok.textContent = 'Saving…';
          Saved.save(kind, img).then(function () { done(img); }).catch(function (e) {
            ok.disabled = false; ok.textContent = opts.okLabel || 'Save';
            say((e && e.message) || ('Your ' + W.noun + ' could not be saved.'));
          });
          return;
        }
        // Saving is a convenience: it must never stand between the person and the document.
        if (save && save.checked()) { Saved.save(kind, img).catch(function () { toastSaveFailed(kind); }); }
        done(img);
      };
    });
  };

  /* ── the in-page pad ─────────────────────────────────────────────────── */
  /* Pointer events rather than mouse+touch pairs: one code path for mouse, pen and
     finger, and setPointerCapture keeps the stroke attached when the finger leaves
     the canvas mid-signature. The backing store is scaled by devicePixelRatio,
     without which a signature on a phone is a blurry rectangle.

     Returns a handle; the caller asks isEmpty() before submitting, because an unsigned
     refund must be refused in the browser as well as on the server.

     KT.signaturePadInline — NOT KT.signaturePad. Two pads with one name silently replaced
     each other for a month (refund approval threw on pad.clear()). Moved here from
     kt-polish.js on 2026-11-06 so both pads, and the public pages that only load this
     file, share one implementation. */
  KT.signaturePadInline = function (host, opts) {
    opts = opts || {};
    var kind = kindOf(opts), W = WORDS[kind];
    var h = opts.height || (kind === 'initials' ? 100 : 130);
    host.innerHTML = '';
    host.style.position = 'relative';

    var top = document.createElement('div');
    host.appendChild(top);
    var box = document.createElement('div');
    box.style.cssText = 'position:relative;border:1px dashed #CBD5E1;border-radius:10px;background:#FFFFFF;overflow:hidden;';
    host.appendChild(box);
    var bottom = document.createElement('div');
    host.appendChild(bottom);

    var cv = document.createElement('canvas');
    cv.style.cssText = 'display:block;width:100%;height:' + h + 'px;touch-action:none;cursor:crosshair;';
    box.appendChild(cv);

    var hint = document.createElement('div');
    hint.textContent = opts.hint || W.hint;
    hint.style.cssText = 'position:absolute;inset:0;display:flex;align-items:center;'
      + 'justify-content:center;color:#94A3B8;font-size:13px;pointer-events:none;';
    box.appendChild(hint);

    var ctx, drawn = false, drawing = false, method = 'drawn', save = null, savedHash = null, dead = false;

    function size() {
      var r = Math.max(1, window.devicePixelRatio || 1);
      var w = cv.clientWidth || box.clientWidth || 320;
      // Resizing the canvas CLEARS it, so an already-drawn signature is preserved.
      var keep = drawn ? cv.toDataURL() : null;
      cv.width = Math.round(w * r);
      cv.height = Math.round(h * r);
      ctx = cv.getContext('2d');
      ctx.scale(r, r);
      ctx.lineWidth = 2;
      ctx.lineCap = 'round';
      ctx.lineJoin = 'round';
      ctx.strokeStyle = '#0F172A';
      if (keep) {
        var img = new Image();
        img.onload = function () { ctx.drawImage(img, 0, 0, w, h); };
        img.src = keep;
      }
    }
    size();
    var ro = null;
    try { ro = new ResizeObserver(size); ro.observe(box); } catch (e) {}

    function image() {
      var out = document.createElement('canvas');
      out.width = cv.width; out.height = cv.height;
      var o = out.getContext('2d');
      o.fillStyle = '#FFFFFF';
      o.fillRect(0, 0, out.width, out.height);
      o.drawImage(cv, 0, 0);
      return out.toDataURL('image/png');
    }
    function commit(url) {
      if (!(drawn && method === 'drawn' && save && save.checked())) { return; }
      url = url || image();
      var key = url.length + ':' + url.slice(-40);
      if (savedHash === key) { return; }
      savedHash = key;
      Saved.save(kind, url).catch(function () { toastSaveFailed(kind); });
    }
    function changed() { try { if (opts.onChange) { opts.onChange(!drawn); } } catch (e) {} }
    function wipe() {
      ctx.save(); ctx.setTransform(1, 0, 0, 1, 0, 0); ctx.clearRect(0, 0, cv.width, cv.height); ctx.restore();
      var was = drawn;
      drawn = false; method = 'drawn'; hint.style.display = '';
      if (was) { changed(); }
    }
    // The saved image, fitted inside the pad, so toDataURL() hands the caller an ordinary image.
    function drawSaved(src) {
      return new Promise(function (res, rej) {
        var img = new Image();
        img.onload = function () {
          wipe();
          var w = cv.clientWidth || 320, pad = 8;
          var s = Math.min((w - pad * 2) / img.width, (h - pad * 2) / img.height);
          var dw = img.width * s, dh = img.height * s;
          ctx.drawImage(img, (w - dw) / 2, (h - dh) / 2, dw, dh);
          drawn = true; method = 'saved'; hint.style.display = 'none';
          changed();
          res();
        };
        img.onerror = function () { rej(new Error('Your saved ' + W.noun + ' could not be loaded.')); };
        img.src = src;
      });
    }

    Saved.get().then(function (d) {
      if (dead || !usable(d)) return;
      var have = d[kind];
      if (have && have.image && opts.allowSaved !== false) {
        var blk = savedBlock(kind, have.image, function (btn) {
          btn.disabled = true; btn.textContent = 'Applying…';
          Saved.use(kind, opts.context).then(drawSaved).then(function () {
            btn.disabled = false; btn.textContent = W.use;
          }).catch(function (e) {
            btn.disabled = false; btn.textContent = W.use;
            hint.textContent = (e && e.message) || ('Please draw your ' + W.noun + '.'); hint.style.display = '';
          });
        });
        top.appendChild(blk);
      }
      if (opts.allowSave !== false) {
        save = saveRow(kind, !!(have && have.image), false);
        bottom.appendChild(save.el);
      }
    });

    function at(e) {
      var b = cv.getBoundingClientRect();
      return { x: e.clientX - b.left, y: e.clientY - b.top };
    }
    cv.addEventListener('pointerdown', function (e) {
      // Drawing over an applied saved signature starts a new, drawn one - never a mix of both.
      if (method === 'saved') { wipe(); }
      drawing = true;
      try { cv.setPointerCapture(e.pointerId); } catch (x) {}
      var p = at(e);
      ctx.beginPath();
      ctx.moveTo(p.x, p.y);
      e.preventDefault();
    });
    cv.addEventListener('pointermove', function (e) {
      if (!drawing) return;
      var p = at(e);
      ctx.lineTo(p.x, p.y);
      ctx.stroke();
      if (!drawn) { drawn = true; hint.style.display = 'none'; hint.textContent = opts.hint || W.hint; changed(); }
      e.preventDefault();
    });
    function stop(e) {
      if (!drawing) return;
      drawing = false;
      try { cv.releasePointerCapture(e.pointerId); } catch (x) {}
      if (drawn) { changed(); }
    }
    cv.addEventListener('pointerup', stop);
    cv.addEventListener('pointercancel', stop);
    cv.addEventListener('pointerleave', stop);

    return {
      isEmpty: function () { return !drawn; },
      clear: wipe,
      /** 'saved' when the person tapped "Use my saved …", otherwise 'drawn'. */
      method: function () { return method; },
      /* PNG on white: the server stores it as a data URI and it is rendered back into
         a PDF, where a transparent background prints as a black block in some viewers.
         Callers read it when the document is submitted, so a ticked "save for next time"
         is honoured then (once per drawing). A caller that reads it while the person is
         still drawing passes {save:false} and calls commit() on submit, or a half-drawn
         signature would be saved. */
      toDataURL: function (o2) {
        var url = image();
        if (!(o2 && o2.save === false)) { commit(url); }
        return url;
      },
      commit: function () { commit(); },
      destroy: function () { dead = true; try { if (ro) ro.disconnect(); } catch (e) {} },
    };
  };

  /* ── My settings → Signature ─────────────────────────────────────────── */
  Saved.renderInto = function (pane) {
    pane.innerHTML = '<div style="color:#64748B;font-size:13px;">Loading…</div>';
    Saved.get(true).then(function (d) {
      if (!d) { pane.innerHTML = '<div style="color:#B91C1C;font-size:13px;">Could not load. Please try again.</div>'; return; }
      if (d.viewing_as) { pane.innerHTML = '<div style="color:#64748B;font-size:13px;">A saved signature is private to its owner, so it is not shown while viewing as someone.</div>'; return; }
      pane.innerHTML = '<div style="font-size:13px;color:#475569;line-height:1.55;margin-bottom:14px;">Save your signature and initials to sign documents with one tap. '
        + 'They are only ever applied when you tap <strong>Use my saved signature</strong> on a document, and each time is recorded.</div>';
      var grid = document.createElement('div');
      grid.style.cssText = 'display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:14px;';
      pane.appendChild(grid);
      ['signature', 'initials'].forEach(function (kind) {
        var W = WORDS[kind], have = d[kind];
        var card = document.createElement('div');
        card.style.cssText = 'border:1px solid #E5E7EB;border-radius:12px;padding:14px;background:#fff;';
        var when = have && have.saved_at && KT.Fmt && KT.Fmt.date ? KT.Fmt.date(have.saved_at, { year: 'numeric', month: 'short', day: 'numeric' }) : '';
        card.innerHTML = '<div style="font-size:14px;font-weight:800;color:#0F172A;margin-bottom:8px;">' + (kind === 'initials' ? 'My initials' : 'My signature') + '</div>'
          + '<div style="height:86px;border:1px solid #E2E8F0;border-radius:10px;background:#FFFFFF;display:flex;align-items:center;justify-content:center;margin-bottom:8px;">'
          + (have && have.image ? '<img alt="" src="' + esc(have.image) + '" style="max-width:90%;max-height:74px;object-fit:contain;">' : '<span style="color:#94A3B8;font-size:12.5px;">Nothing saved</span>')
          + '</div>'
          + '<div style="font-size:11.5px;color:#64748B;margin-bottom:10px;">' + (have ? 'Saved ' + esc(when) : 'Not saved yet') + '</div>'
          + '<div style="display:flex;gap:8px;flex-wrap:wrap;">'
          + '<button type="button" data-ktsig-draw style="background:#1B3A63;color:#fff;border:0;border-radius:9px;padding:7px 12px;font-weight:800;font-size:13px;cursor:pointer;">' + (have ? 'Replace' : 'Draw and save') + '</button>'
          + (have ? '<button type="button" data-ktsig-remove style="background:#F1F5F9;color:#B91C1C;border:0;border-radius:9px;padding:7px 12px;font-weight:700;font-size:13px;cursor:pointer;">Remove</button>' : '')
          + '</div>';
        card.querySelector('[data-ktsig-draw]').onclick = function () {
          KT.signaturePad({ kind: kind, saveOnly: true, title: have ? 'Replace my ' + W.noun : 'Save my ' + W.noun,
            subtitle: 'Draw your ' + W.noun + ' as you would on paper.' }).then(function (img) {
            if (img) { Saved.renderInto(pane); }
          });
        };
        var rm = card.querySelector('[data-ktsig-remove]');
        if (rm) {
          // Two taps, no browser dialog (a native confirm blocks the app on phones).
          var armed = null;
          rm.onclick = function () {
            if (!armed) {
              rm.textContent = 'Tap again to remove';
              armed = setTimeout(function () { armed = null; rm.textContent = 'Remove'; }, 4000);
              return;
            }
            clearTimeout(armed); rm.disabled = true; rm.textContent = 'Removing…';
            Saved.remove(kind).then(function () { Saved.renderInto(pane); }).catch(function () {
              rm.disabled = false; rm.textContent = 'Remove';
            });
          };
        }
        grid.appendChild(card);
      });
      if (d.uses) {
        var u = document.createElement('div');
        u.style.cssText = 'font-size:11.5px;color:#94A3B8;margin-top:12px;';
        u.textContent = 'Applied to ' + d.uses + ' document' + (d.uses === 1 ? '' : 's') + ' so far.';
        pane.appendChild(u);
      }
    });
  };
})(window);
