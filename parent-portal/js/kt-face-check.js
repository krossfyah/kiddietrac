/* ═══════════════════════════════════════════════════════════════════
   KiddieTrac — A PROFILE PHOTO HAS TO SHOW A FACE (2026-10-30).

   Anthony: "with uploading display pics is there a mechanism to detect a face/facial features and not
   allowing users to upload random photos that holds no value" - he chose: checked ON THE DEVICE, and
   BLOCK, for staff, parent and child profile photos.

   KT.faceCheck(blobOrCanvas, opts) -> Promise<{ ok, code, reason, faces, box, ms, engine }>
   KT.faceGate(blobOrCanvas, opts)  -> Promise<boolean>   the check + the "choose a clearer photo" popup
     opts: { who: 'self' | 'child' | 'person', name: 'Liam', retry: fn }   retry = re-open the picker

   - The photo never leaves the phone or computer for this: MediaPipe's BlazeFace (short range) runs in
     WebAssembly from our own server (/vendor/mediapipe-0.10.14/, self-hosted like the fonts), loaded only
     when somebody picks a profile photo. The browser's own FaceDetector (Chrome on Android/macOS) is a
     fast path: when it finds exactly one good face the photo passes at once; anything it would refuse is
     confirmed by MediaPipe first, because it misses faces MediaPipe finds.
   - Refused: no face; more than one person (a face at least 45% the size of the largest - a small
     stranger far in the background does not count); a face smaller than 10% of the photo's shorter side;
     a face too dark or too blurry to recognise.
   - FAILS OPEN: if the detector cannot load (very old phone, offline, no WebAssembly) the photo is
     allowed and a diag beacon says so. Nobody is ever locked out of setting a photo by this check.
   ═══════════════════════════════════════════════════════════════════ */
(function (w) {
  'use strict';
  var KT = w.KT || (w.KT = {});
  if (KT.faceCheck) return;

  var BASE = '/vendor/mediapipe-0.10.14/';
  var MIN_FACE = 0.10;      // face box (longer side) / photo's shorter side
  var OTHER_FACE = 0.45;    // a second face this big (vs the largest) means "more than one person"
  var MIN_SCORE = 0.65;     // real photos score 0.73 (sleeping baby) to 0.96; paintings' false faces ~0.55
  var OTHER_SCORE = 0.6;    // a second person counts from here
  var DARK = 42;            // mean luminance of the face (0-255)
  var BLUR = 18;            // variance of the Laplacian over the face at 128px

  var mp = null;            // Promise<FaceDetector | null>

  function beacon(kind, extra) {
    try {
      var base = (KT.API_BASE) || 'https://api.kiddietrac.com/api/v1';
      var body = JSON.stringify({ data: JSON.stringify(Object.assign({ facecheck: kind, ua: navigator.userAgent.slice(0, 120) }, extra || {})) });
      fetch(base + '/diag/scroll', { method: 'POST', headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' }, body: body, keepalive: true }).catch(function () {});
    } catch (e) {}
  }

  function withTimeout(p, ms) {
    return new Promise(function (res, rej) {
      var t = setTimeout(function () { rej(new Error('timeout')); }, ms);
      p.then(function (v) { clearTimeout(t); res(v); }, function (e) { clearTimeout(t); rej(e); });
    });
  }

  /* MediaPipe, loaded once. new Function keeps import() out of this file's own parse, so an old engine
     that cannot read it still runs everything else (and fails open). */
  function loadMediaPipe() {
    if (mp) return mp;
    var t0 = Date.now();
    mp = withTimeout((function () {
      if (typeof WebAssembly !== 'object') return Promise.reject(new Error('no WebAssembly'));
      var dynImport;
      try { dynImport = new Function('u', 'return import(u)'); } catch (e) { return Promise.reject(e); }
      return dynImport(BASE + 'vision_bundle.js').then(function (v) {
        return Promise.resolve(v.FilesetResolver.isSimdSupported ? v.FilesetResolver.isSimdSupported() : true).then(function (simd) {
          var stem = BASE + 'wasm/vision_wasm_' + (simd ? '' : 'nosimd_') + 'internal';
          return v.FaceDetector.createFromOptions({ wasmLoaderPath: stem + '.js', wasmBinaryPath: stem + '.wasm' }, {
            baseOptions: { modelAssetPath: BASE + 'blaze_face_short_range.tflite', delegate: 'CPU' },
            runningMode: 'IMAGE', minDetectionConfidence: 0.5, minSuppressionThreshold: 0.3,
          });
        });
      });
    })(), 30000).then(function (det) {
      KT.faceCheck.loadMs = Date.now() - t0;
      return det;
    }).catch(function (e) {
      beacon('load-failed', { error: String(e && e.message || e).slice(0, 200), ms: Date.now() - t0 });
      mp = null;            // a later pick may try again (e.g. back online)
      return null;
    });
    return mp;
  }

  // ── the picture, as a canvas no larger than 720px on its long side ──
  function toCanvas(src) {
    if (src && src.getContext) return Promise.resolve(src);
    return new Promise(function (resolve, reject) {
      function draw(img, iw, ih) {
        var s = Math.min(1, 720 / Math.max(iw, ih));
        var c = document.createElement('canvas');
        c.width = Math.max(1, Math.round(iw * s)); c.height = Math.max(1, Math.round(ih * s));
        c.getContext('2d').drawImage(img, 0, 0, c.width, c.height);
        resolve(c);
      }
      if (src && src.tagName === 'IMG') { draw(src, src.naturalWidth, src.naturalHeight); return; }
      if (w.createImageBitmap) {
        createImageBitmap(src).then(function (bm) { draw(bm, bm.width, bm.height); try { bm.close(); } catch (e) {} })
          .catch(function () { viaImg(); });
      } else viaImg();
      function viaImg() {
        var url = URL.createObjectURL(src), img = new Image();
        img.onload = function () { draw(img, img.naturalWidth, img.naturalHeight); setTimeout(function () { URL.revokeObjectURL(url); }, 1000); };
        img.onerror = function () { URL.revokeObjectURL(url); reject(new Error('not an image')); };
        img.src = url;
      }
    });
  }

  // Brightness and sharpness of a region (the face), measured at 128px.
  function quality(c, box) {
    var n = 128, t = document.createElement('canvas'); t.width = n; t.height = n;
    var g = t.getContext('2d');
    var x = Math.max(0, box.x), y = Math.max(0, box.y);
    var bw = Math.min(c.width - x, box.w), bh = Math.min(c.height - y, box.h);
    g.drawImage(c, x, y, Math.max(1, bw), Math.max(1, bh), 0, 0, n, n);
    var d = g.getImageData(0, 0, n, n).data, L = new Float32Array(n * n), sum = 0;
    for (var i = 0, j = 0; i < d.length; i += 4, j++) { L[j] = 0.299 * d[i] + 0.587 * d[i + 1] + 0.114 * d[i + 2]; sum += L[j]; }
    var mean = sum / (n * n), lap = [], ls = 0;
    for (var yy = 1; yy < n - 1; yy++) for (var xx = 1; xx < n - 1; xx++) {
      var k = yy * n + xx, v = 4 * L[k] - L[k - 1] - L[k + 1] - L[k - n] - L[k + n];
      lap.push(v); ls += v;
    }
    var lm = ls / lap.length, varsum = 0;
    for (var q = 0; q < lap.length; q++) varsum += (lap[q] - lm) * (lap[q] - lm);
    return { light: mean, sharp: varsum / lap.length };
  }

  // MediaPipe on the whole photo; if it sees nobody, again on overlapping tiles (it is a close-range
  // model, and a child photographed head-to-toe has a small face).
  function detectMP(det, c) {
    function run(canvas, ox, oy, sc) {
      return (det.detect(canvas).detections || []).map(function (d) {
        var b = d.boundingBox, s = (d.categories && d.categories[0] && d.categories[0].score) || 0;
        return { x: ox + b.originX / sc, y: oy + b.originY / sc, w: b.width / sc, h: b.height / sc, score: s };
      });
    }
    var all = run(c, 0, 0, 1);
    if (!all.filter(function (f) { return f.score >= MIN_SCORE; }).length) {
      var tw = Math.round(c.width * 0.62), th = Math.round(c.height * 0.62);
      [[0, 0], [c.width - tw, 0], [0, c.height - th], [c.width - tw, c.height - th], [(c.width - tw) / 2, (c.height - th) / 2]].forEach(function (o) {
        var t = document.createElement('canvas'), sc = Math.min(2, 640 / Math.max(tw, th));
        t.width = Math.round(tw * sc); t.height = Math.round(th * sc);
        t.getContext('2d').drawImage(c, o[0], o[1], tw, th, 0, 0, t.width, t.height);
        all = all.concat(run(t, o[0], o[1], sc));
      });
    }
    var kept = nms(all.filter(function (f) { return f.score >= OTHER_SCORE; }));
    // Somebody must be a confident face; the rest only need to be confident enough to be another person.
    return kept.some(function (f) { return f.score >= MIN_SCORE; }) ? kept : [];
  }
  function iou(a, b) {
    var x1 = Math.max(a.x, b.x), y1 = Math.max(a.y, b.y), x2 = Math.min(a.x + a.w, b.x + b.w), y2 = Math.min(a.y + a.h, b.y + b.h);
    var i = Math.max(0, x2 - x1) * Math.max(0, y2 - y1);
    return i / (a.w * a.h + b.w * b.h - i || 1);
  }
  function nms(fs) {
    fs.sort(function (a, b) { return b.score - a.score; });
    var keep = [];
    fs.forEach(function (f) { if (!keep.some(function (k) { return iou(k, f) > 0.3 || (f.x >= k.x && f.y >= k.y && f.x + f.w <= k.x + k.w && f.y + f.h <= k.y + k.h); })) keep.push(f); });
    return keep;
  }

  function detectNative(c) {
    if (!('FaceDetector' in w)) return Promise.resolve(null);
    try {
      return new w.FaceDetector({ fastMode: false, maxDetectedFaces: 5 }).detect(c).then(function (fs) {
        return fs.map(function (f) { var b = f.boundingBox; return { x: b.x, y: b.y, w: b.width, h: b.height, score: 1 }; });
      }).catch(function () { return null; });
    } catch (e) { return Promise.resolve(null); }
  }

  // The verdict on a set of faces.
  function judge(c, faces) {
    var short = Math.min(c.width, c.height);
    if (!faces.length) return { ok: false, code: 'no_face' };
    faces.sort(function (a, b) { return (b.w * b.h) - (a.w * a.h); });
    var main = faces[0], big = Math.max(main.w, main.h);
    var others = faces.slice(1).filter(function (f) { return Math.max(f.w, f.h) >= big * OTHER_FACE; });
    if (others.length) return { ok: false, code: 'many', faces: 1 + others.length, box: main };
    if (big / short < MIN_FACE) return { ok: false, code: 'small', box: main };
    var q = quality(c, main);
    if (q.light < DARK) return { ok: false, code: 'dark', box: main, q: q };
    if (q.sharp < BLUR) return { ok: false, code: 'blurry', box: main, q: q };
    return { ok: true, code: 'ok', box: main, q: q };
  }

  function reasonText(code, opts) {
    var who = opts && opts.who === 'child' ? ('just ' + (opts.name || 'your child')) : opts && opts.who === 'person' ? ('just ' + (opts.name || 'them')) : 'just you';
    return {
      no_face: 'No face could be found in this photo.',
      many: 'There is more than one person in this photo. Use a photo with ' + who + '.',
      small: 'The face is too small. Move closer, or pick a head-and-shoulders photo.',
      dark: 'The photo is too dark to see the face. Try one in better light.',
      blurry: 'The photo is too blurry to see the face. Hold steady and try again.',
    }[code] || '';
  }

  KT.faceCheck = function (src, opts) {
    var t0 = Date.now();
    return toCanvas(src).then(function (c) {
      return detectNative(c).then(function (nf) {
        if (nf) {
          var v = judge(c, nf);
          if (v.ok) return Object.assign(v, { engine: 'native', faces: 1 });
        }
        return loadMediaPipe().then(function (det) {
          if (!det) return { ok: true, code: 'unavailable', engine: 'none' };          // fail OPEN
          var faces = detectMP(det, c);
          var v2 = judge(c, faces);
          return Object.assign({ faces: faces.length }, v2, { engine: 'mediapipe' });
        });
      });
    }).then(function (r) {
      r.ms = Date.now() - t0;
      r.reason = r.ok ? '' : reasonText(r.code, opts);
      return r;
    }).catch(function (e) {
      beacon('check-failed', { error: String(e && e.message || e).slice(0, 200) });
      return { ok: true, code: 'error', engine: 'none', ms: Date.now() - t0, reason: '' };     // fail OPEN
    });
  };
  KT.faceCheck.preload = function () { loadMediaPipe(); };
  // Raw detections with scores, for checking thresholds (not used by the app).
  KT.faceCheck.debug = function (src) {
    return toCanvas(src).then(function (c) { return loadMediaPipe().then(function (det) {
      if (!det) return null;
      var raw = (det.detect(c).detections || []).map(function (d) { var b = d.boundingBox; return { x: b.originX, y: b.originY, w: b.width, h: b.height, s: d.categories[0].score }; });
      return { w: c.width, h: c.height, raw: raw, kept: detectMP(det, c) };
    }); });
  };
  KT.faceCheck.loadMs = null;
  /* Start loading the detector the moment somebody taps a profile-photo picker (marked data-kt-face), so the
     3 MB download happens while they choose and crop the photo. Not on a slow or data-saver connection. */
  document.addEventListener('click', function (e) {
    var t = e.target;
    if (!t || !t.matches || !t.matches('input[type=file][data-kt-face], #kt-av-cam')) return;
    try { var nc = navigator.connection; if (nc && (nc.saveData || /(^|-)2g/.test(nc.effectiveType || ''))) return; } catch (x) {}
    loadMediaPipe();
  }, true);

  // A small "Checking the photo…" note while the detector loads the first time.
  function busyNote() {
    var n = document.createElement('div');
    n.setAttribute('role', 'status');
    n.style.cssText = 'position:fixed;left:50%;bottom:calc(96px + var(--kt-safe-bottom, env(safe-area-inset-bottom, 0px)));transform:translateX(-50%);'
      + 'z-index:2147483000;background:#0F2A44;color:#fff;font:700 13px/1.2 system-ui,-apple-system,Segoe UI,Roboto,sans-serif;'
      + 'padding:10px 16px;border-radius:999px;box-shadow:0 10px 30px rgba(2,12,27,.35);pointer-events:none';
    n.textContent = '🔍 Checking the photo…';
    var t = setTimeout(function () { document.body.appendChild(n); }, 250);
    return function () { clearTimeout(t); if (n.parentNode) n.parentNode.removeChild(n); };
  }

  KT.faceGate = function (src, opts) {
    opts = opts || {};
    var done = busyNote();
    return KT.faceCheck(src, opts).then(function (r) {
      done();
      if (r.ok) return true;
      return toCanvas(src).then(function (c) {
        var box = document.createElement('div');
        box.style.cssText = 'display:flex;justify-content:center;margin:4px 0 2px';
        var im = document.createElement('img');
        try { im.src = c.toDataURL('image/jpeg', 0.7); } catch (e) {}
        im.alt = '';
        im.style.cssText = 'max-width:160px;max-height:160px;border-radius:12px;object-fit:cover;box-shadow:0 4px 14px rgba(2,12,27,.18)';
        box.appendChild(im);
        var ask = KT.confirm ? KT.confirm({
          title: '📷 Choose a clearer photo', keepHeaderTitle: true, tone: 'warning',
          description: (r.code === 'no_face' || r.code === 'small' ? 'We couldn’t find a clear face — try a well-lit head-and-shoulders photo. ' : '') + r.reason,
          extra: box, okLabel: 'Try again', cancelLabel: 'Cancel',
        }) : Promise.resolve(w.confirm('We couldn’t find a clear face — try a well-lit head-and-shoulders photo. ' + r.reason + '\n\nTry again?'));
        return ask.then(function (again) {
          if (again && typeof opts.retry === 'function') { try { opts.retry(); } catch (e) {} }
          return false;
        });
      }).catch(function () { return false; });
    }, function () { done(); return true; });
  };
  /* For a file input whose change handler uploads the picked file straight away (child photos):
       if (KT.faceGateInput && !KT.faceGateInput(input, { who: 'child', name: 'Liam' })) return;
     The first call checks the file and returns false; when the face passes, the same change event is
     fired again with the input marked as checked, so the screen's own handler carries on unchanged.
     A refused photo clears the input; "Try again" opens the picker. */
  KT.faceGateInput = function (input, opts) {
    if (!input || input.__ktFaceOk) return true;
    var f = input.files && input.files[0];
    if (!f || !KT.faceGate) return true;
    var o = Object.assign({ retry: function () { input.click(); } }, opts || {});
    KT.faceGate(f, o).then(function (ok) {
      if (!ok) { try { input.value = ''; } catch (e) {} return; }
      input.__ktFaceOk = true;
      try { input.dispatchEvent(new Event('change', { bubbles: true })); } finally { input.__ktFaceOk = false; }
    });
    return false;
  };
})(window);
