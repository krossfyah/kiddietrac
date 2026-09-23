/**
 * kt-upload-progress.js — a progress bar for every upload in the portal.
 *
 * WHY THIS PATCHES fetch RATHER THAN KT.Api.
 *
 * Uploads are not funnelled through one helper. `KT.Api.postForm` has 14 call sites, and
 * TWENTY-TWO other screens build a FormData and hand it straight to `fetch()` — chat
 * attachments, the avatar picker, incidents, onboarding, e-documents. Teaching postForm to
 * report progress would have covered a third of them and left the rest silent, which is
 * the same inconsistency the request is about.
 *
 * And `fetch()` cannot report upload progress at all: there is no event for bytes sent. So
 * a request that is carrying a file is re-routed through XMLHttpRequest, which can, and the
 * answer is handed back as a REAL `Response` — built with the native constructor, so
 * `.ok`, `.status`, `.json()`, `.text()` and `.blob()` behave exactly as the call site
 * already expects. Nothing else about the request changes.
 *
 * Only requests whose body is a FormData carrying a File or Blob are touched. A JSON POST,
 * a GET, a FormData of plain fields — all go to the untouched original. On ANY error inside
 * this shim it falls back to the original fetch, because a progress bar must never be the
 * reason an upload fails.
 */
(function (w) {
  'use strict';
  if (w.__ktUploadProgress) { return; }
  w.__ktUploadProgress = true;

  var d = w.document;
  var jobs = {};          // id -> {loaded, total, name}
  var seq = 0;
  var barWrap = null, fill = null, label = null, hideTimer = null;

  /* ── the bar ─────────────────────────────────────────────────────────── */

  function styles() {
    if (d.getElementById('kt-up-css')) { return; }
    var s = d.createElement('style');
    s.id = 'kt-up-css';
    s.textContent =
      '#kt-upbar{position:fixed;left:0;right:0;z-index:2147400000;'
      + 'top:calc(var(--kt-safe-top, env(safe-area-inset-top, 0px)));'
      + 'background:#0F172A;color:#fff;font:600 12.5px/1.3 system-ui,-apple-system,Segoe UI,Roboto,sans-serif;'
      + 'box-shadow:0 6px 20px -8px rgba(8,20,40,.65);transform:translateY(-100%);'
      + 'transition:transform .22s ease;pointer-events:none;}'
      + '#kt-upbar.kt-up-show{transform:none;}'
      + '#kt-upbar .kt-up-row{display:flex;align-items:center;gap:10px;padding:8px 14px;}'
      + '#kt-upbar .kt-up-txt{flex:1;min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;}'
      + '#kt-upbar .kt-up-pct{flex:none;opacity:.85;font-variant-numeric:tabular-nums;}'
      + '#kt-upbar .kt-up-track{height:3px;background:rgba(255,255,255,.18);}'
      + '#kt-upbar .kt-up-fill{height:100%;width:0;background:linear-gradient(90deg,#13B7CC,#8EC73C);'
      + 'transition:width .18s ease;}'
      /* Total unknown (a stream, or a browser that reports no lengthComputable):
         a moving stripe says "working" without claiming a percentage it does not know. */
      + '#kt-upbar.kt-up-indet .kt-up-fill{width:35%;animation:kt-up-slide 1.1s ease-in-out infinite;}'
      + '@keyframes kt-up-slide{0%{margin-left:-35%}100%{margin-left:100%}}';
    d.head.appendChild(s);
  }

  function ensureBar() {
    if (barWrap && d.body.contains(barWrap)) { return; }
    styles();
    barWrap = d.createElement('div');
    barWrap.id = 'kt-upbar';
    barWrap.setAttribute('role', 'status');
    barWrap.setAttribute('aria-live', 'polite');
    barWrap.innerHTML =
      '<div class="kt-up-row"><span class="kt-up-txt"></span><span class="kt-up-pct"></span></div>'
      + '<div class="kt-up-track"><div class="kt-up-fill"></div></div>';
    d.body.appendChild(barWrap);
    fill = barWrap.querySelector('.kt-up-fill');
    label = barWrap.querySelector('.kt-up-txt');
  }

  function human(b) {
    b = Number(b || 0);
    if (b >= 1048576) { return (b / 1048576).toFixed(1) + ' MB'; }
    return Math.max(1, Math.round(b / 1024)) + ' KB';
  }

  function paint() {
    var ids = Object.keys(jobs);
    if (!ids.length) { return; }
    ensureBar();
    var loaded = 0, total = 0, known = true, names = [];
    for (var i = 0; i < ids.length; i++) {
      var j = jobs[ids[i]];
      loaded += j.loaded;
      if (j.total > 0) { total += j.total; } else { known = false; }
      if (j.name) { names.push(j.name); }
    }
    var n = ids.length;
    var what = n > 1 ? ('Uploading ' + n + ' files') : ('Uploading ' + (names[0] || 'file'));
    label.textContent = what;
    if (known && total > 0) {
      barWrap.classList.remove('kt-up-indet');
      var pct = Math.max(2, Math.min(100, Math.round(loaded / total * 100)));
      fill.style.width = pct + '%';
      barWrap.querySelector('.kt-up-pct').textContent = pct + '%  ·  ' + human(loaded) + ' / ' + human(total);
    } else {
      barWrap.classList.add('kt-up-indet');
      barWrap.querySelector('.kt-up-pct').textContent = human(loaded);
    }
    if (hideTimer) { clearTimeout(hideTimer); hideTimer = null; }
    barWrap.classList.add('kt-up-show');
  }

  function finish(id, ok) {
    delete jobs[id];
    if (Object.keys(jobs).length) { paint(); return; }
    if (!barWrap) { return; }
    // Let a completed bar be SEEN before it goes: a bar that vanishes the instant it
    // fills reads as a flicker rather than as "that finished".
    barWrap.classList.remove('kt-up-indet');
    fill.style.width = '100%';
    barWrap.querySelector('.kt-up-pct').textContent = ok ? 'Done' : 'Failed';
    label.textContent = ok ? 'Upload complete' : 'Upload failed';
    hideTimer = setTimeout(function () {
      if (Object.keys(jobs).length) { return; }
      barWrap.classList.remove('kt-up-show');
      setTimeout(function () { if (fill && !Object.keys(jobs).length) { fill.style.width = '0'; } }, 240);
    }, ok ? 650 : 1800);
  }

  /* ── does this request carry a file? ─────────────────────────────────── */

  function fileIn(fd) {
    try {
      if (typeof fd.entries !== 'function') { return null; }
      var it = fd.entries(), e;
      while (!(e = it.next()).done) {
        var v = e.value[1];
        if (v && typeof v === 'object' && (typeof File !== 'undefined' && v instanceof File)) { return v; }
        if (v && typeof Blob !== 'undefined' && v instanceof Blob) { return v; }
      }
    } catch (err) {}
    return null;
  }

  /* ── the shim ────────────────────────────────────────────────────────── */

  var orig = w.fetch;

  w.fetch = function (input, init) {
    var body = init && init.body;
    var isForm = body && (typeof FormData !== 'undefined') && (body instanceof FormData);
    var file = isForm ? fileIn(body) : null;
    if (!file) { return orig.apply(this, arguments); }

    var url, method;
    try {
      url = (typeof input === 'string') ? input : (input && input.url) || String(input);
      method = String((init && init.method) || 'POST').toUpperCase();
    } catch (e) { return orig.apply(this, arguments); }

    try {
      return new Promise(function (resolve, reject) {
        var id = 'u' + (++seq);
        jobs[id] = { loaded: 0, total: Number(file.size || 0), name: file.name || '' };
        paint();

        var xhr = new XMLHttpRequest();
        xhr.open(method, url, true);
        xhr.responseType = 'blob';

        // Replicate the caller's headers exactly; never invent a Content-Type — the
        // browser must set multipart/form-data plus its own boundary.
        try {
          var h = (init && init.headers) || {};
          if (typeof Headers !== 'undefined' && h instanceof Headers) {
            h.forEach(function (val, key) {
              if (String(key).toLowerCase() !== 'content-type') { xhr.setRequestHeader(key, val); }
            });
          } else {
            Object.keys(h).forEach(function (key) {
              if (String(key).toLowerCase() !== 'content-type') { xhr.setRequestHeader(key, h[key]); }
            });
          }
        } catch (e) {}

        if (xhr.upload) {
          xhr.upload.onprogress = function (ev) {
            if (!jobs[id]) { return; }
            jobs[id].loaded = ev.loaded || 0;
            if (ev.lengthComputable && ev.total) { jobs[id].total = ev.total; }
            paint();
          };
        }

        xhr.onload = function () {
          finish(id, xhr.status >= 200 && xhr.status < 400);
          try {
            // A REAL Response, so .ok/.json()/.text()/.blob() are the native ones the
            // call site already relies on. 204/205 may not carry a body.
            var noBody = (xhr.status === 204 || xhr.status === 205);
            var hdrs = new Headers();
            String(xhr.getAllResponseHeaders() || '').trim().split(/[\r\n]+/).forEach(function (line) {
              var i = line.indexOf(':');
              if (i > 0) { try { hdrs.append(line.slice(0, i).trim(), line.slice(i + 1).trim()); } catch (e) {} }
            });
            resolve(new Response(noBody ? null : xhr.response, {
              status: xhr.status || 500,
              statusText: xhr.statusText || '',
              headers: hdrs,
            }));
          } catch (e) {
            // Could not build a Response — do not strand the caller.
            reject(new TypeError('Failed to fetch'));
          }
        };
        xhr.onerror = function () { finish(id, false); reject(new TypeError('Failed to fetch')); };
        xhr.onabort = function () { finish(id, false); reject(new TypeError('Failed to fetch')); };
        xhr.ontimeout = function () { finish(id, false); reject(new TypeError('Failed to fetch')); };

        xhr.send(body);
      });
    } catch (e) {
      // Anything unexpected in the shim: the upload still has to happen.
      return orig.apply(this, arguments);
    }
  };

  /* Exposed so a screen doing its own thing (a chunked or resumable upload) can still
     drive the same bar rather than drawing a second one. */
  w.KT = w.KT || {};
  w.KT.uploadProgress = {
    start: function (name, total) {
      var id = 'm' + (++seq);
      jobs[id] = { loaded: 0, total: Number(total || 0), name: name || '' };
      paint();
      return id;
    },
    set: function (id, loaded, total) {
      if (!jobs[id]) { return; }
      jobs[id].loaded = Number(loaded || 0);
      if (total) { jobs[id].total = Number(total); }
      paint();
    },
    done: function (id, ok) { if (jobs[id]) { finish(id, ok !== false); } },
  };
})(window);
