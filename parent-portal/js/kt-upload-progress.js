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

  /* ── the "please wait" popup ──────────────────────────────────────────────
     Anthony, 2026-10-05: "when uploading files such as the event rsvp new image you
     should have a please wait popup of some sort shown - full sweep."

     This used to be a 3px bar across the top edge, which nobody noticed — so people
     clicked Upload again, or left the screen while the file was still going. Now an
     upload shows a centred popup over a dimmed page that also stops clicks underneath:
     what is uploading, how far it has got, and "Processing…" for the wait between the
     last byte and the server's answer (resizing an image, reading a PDF).

     It appears only after SHOW_DELAY, so a 20 KB avatar does not flash a dialog; once
     shown it stays at least MIN_SHOW, so it is read rather than flickered. Leaving the
     page mid-upload asks first. data-kt-keep-open keeps the navigation sweep from
     closing it. */
  var SHOW_DELAY = 300, MIN_SHOW = 600;
  var shownAt = 0, showTimer = null, wantShow = false, unloadOn = false;
  var titleEl = null, subEl = null, pctEl = null, ringEl = null;

  function styles() {
    if (d.getElementById('kt-up-css')) { return; }
    var s = d.createElement('style');
    s.id = 'kt-up-css';
    s.textContent =
      '#kt-upwait{position:fixed;inset:0;z-index:2147482600;display:flex;align-items:center;justify-content:center;'
      + 'padding:16px;background:rgba(15,23,42,.42);backdrop-filter:blur(2px);-webkit-backdrop-filter:blur(2px);'
      + 'cursor:progress;opacity:0;transition:opacity .16s ease;font-family:inherit;}'
      + '#kt-upwait.kt-up-show{opacity:1;}'
      + '#kt-upwait .kt-up-card{background:#fff;border-radius:16px;width:100%;max-width:360px;padding:22px 22px 18px;'
      + 'box-shadow:0 30px 70px -20px rgba(8,20,40,.55);text-align:center;color:#0F172A;}'
      + '#kt-upwait .kt-up-ring{width:38px;height:38px;margin:0 auto 12px;border-radius:50%;'
      + 'border:4px solid rgba(31,96,128,.18);border-top-color:#1F6080;animation:kt-up-spin .75s linear infinite;}'
      + '#kt-upwait .kt-up-ring.kt-up-ok{animation:none;border-color:#16A34A;background:#16A34A;position:relative;}'
      + '#kt-upwait .kt-up-ring.kt-up-ok:after{content:"\\2713";position:absolute;inset:0;color:#fff;font:800 20px/30px system-ui,sans-serif;}'
      + '#kt-upwait .kt-up-title{font-weight:800;font-size:15px;line-height:1.35;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;}'
      + '#kt-upwait .kt-up-sub{font-size:12.5px;color:#64748B;margin-top:4px;line-height:1.4;}'
      + '#kt-upwait .kt-up-track{height:6px;background:#E2E8F0;border-radius:99px;overflow:hidden;margin:14px 0 6px;}'
      + '#kt-upwait .kt-up-fill{height:100%;width:0;background:linear-gradient(90deg,#13B7CC,#8EC73C);border-radius:99px;transition:width .18s ease;}'
      + '#kt-upwait.kt-up-indet .kt-up-fill{width:35%;animation:kt-up-slide 1.1s ease-in-out infinite;}'
      + '#kt-upwait .kt-up-pct{font-size:12px;color:#475569;font-variant-numeric:tabular-nums;min-height:15px;}'
      + '@keyframes kt-up-spin{to{transform:rotate(360deg)}}'
      + '@keyframes kt-up-slide{0%{margin-left:-35%}100%{margin-left:100%}}'
      + '@media (prefers-reduced-motion:reduce){#kt-upwait .kt-up-ring{animation-duration:2s}#kt-upwait.kt-up-indet .kt-up-fill{animation:none;width:100%;opacity:.5}}';
    d.head.appendChild(s);
  }

  function ensureBar() {
    if (barWrap && d.body.contains(barWrap)) { return; }
    styles();
    barWrap = d.createElement('div');
    barWrap.id = 'kt-upwait';
    barWrap.setAttribute('role', 'alertdialog');
    barWrap.setAttribute('aria-modal', 'true');
    barWrap.setAttribute('aria-live', 'polite');
    barWrap.setAttribute('data-kt-keep-open', '1');
    barWrap.setAttribute('data-no-modal-guard', '1');
    barWrap.innerHTML =
      '<div class="kt-up-card">'
      + '<div class="kt-up-ring"></div>'
      + '<div class="kt-up-title"></div>'
      + '<div class="kt-up-sub"></div>'
      + '<div class="kt-up-track"><div class="kt-up-fill"></div></div>'
      + '<div class="kt-up-pct"></div>'
      + '</div>';
    // Swallow clicks and keys aimed at the page underneath while the upload runs.
    ['click', 'mousedown', 'touchstart', 'keydown'].forEach(function (t) {
      barWrap.addEventListener(t, function (e) { e.stopPropagation(); if (t !== 'touchstart') { e.preventDefault(); } }, true);
    });
    d.body.appendChild(barWrap);
    fill = barWrap.querySelector('.kt-up-fill');
    label = barWrap.querySelector('.kt-up-title');
    titleEl = label;
    subEl = barWrap.querySelector('.kt-up-sub');
    pctEl = barWrap.querySelector('.kt-up-pct');
    ringEl = barWrap.querySelector('.kt-up-ring');
  }

  function onBeforeUnload(e) {
    if (!Object.keys(jobs).length) { return; }
    e.preventDefault();
    e.returnValue = 'An upload is still in progress.';
    return e.returnValue;
  }
  function guardUnload(on) {
    if (on === unloadOn) { return; }
    unloadOn = on;
    if (on) { w.addEventListener('beforeunload', onBeforeUnload); } else { w.removeEventListener('beforeunload', onBeforeUnload); }
  }

  function reveal() {
    showTimer = null;
    if (!wantShow || !Object.keys(jobs).length) { return; }
    ensureBar();
    // Focus off whatever button started it, so Enter cannot fire it again.
    try { if (d.activeElement && d.activeElement.blur) { d.activeElement.blur(); } } catch (e) {}
    barWrap.style.display = 'flex';
    void barWrap.offsetWidth;
    barWrap.classList.add('kt-up-show');
    shownAt = Date.now();
    draw();
  }

  function human(b) {
    b = Number(b || 0);
    if (b >= 1048576) { return (b / 1048576).toFixed(1) + ' MB'; }
    return Math.max(1, Math.round(b / 1024)) + ' KB';
  }

  function draw() {
    if (!barWrap || !shownAt) { return; }
    var ids = Object.keys(jobs);
    if (!ids.length) { return; }
    var loaded = 0, total = 0, known = true, names = [], saving = true;
    for (var i = 0; i < ids.length; i++) {
      var j = jobs[ids[i]];
      loaded += j.loaded;
      if (j.total > 0) { total += j.total; } else { known = false; }
      if (j.name) { names.push(j.name); }
      if (!j.saving) { saving = false; }
    }
    var n = ids.length;
    ringEl.classList.remove('kt-up-ok');
    titleEl.textContent = saving ? ((n === 1 && jobs[ids[0]].title) ? jobs[ids[0]].title + '…' : 'Saving…')
      : (n > 1 ? ('Uploading ' + n + ' files…') : ('Uploading ' + (names[0] || 'your file') + '…'));
    var sent = known && total > 0 && loaded >= total;
    if (!saving && known && total > 0 && !sent) {
      barWrap.classList.remove('kt-up-indet');
      var pct = Math.max(2, Math.min(100, Math.round(loaded / total * 100)));
      fill.style.width = pct + '%';
      pctEl.textContent = pct + '%  ·  ' + human(loaded) + ' of ' + human(total);
      subEl.textContent = 'Please wait — don’t close this page.';
    } else {
      // Every byte is up (or the size is unknown): the server is working on it now.
      barWrap.classList.add('kt-up-indet');
      pctEl.textContent = sent ? 'Processing…' : (saving ? '' : human(loaded));
      subEl.textContent = sent ? 'Almost done — finishing up on our side.' : 'Please wait — don’t close this page.';
    }
  }

  function paint() {
    if (!Object.keys(jobs).length) { return; }
    guardUnload(true);
    if (hideTimer) { clearTimeout(hideTimer); hideTimer = null; }
    wantShow = true;
    if (shownAt) { draw(); return; }
    if (!showTimer) { showTimer = setTimeout(reveal, SHOW_DELAY); }
  }

  function hideNow() {
    if (!barWrap) { return; }
    barWrap.classList.remove('kt-up-show');
    shownAt = 0;
    setTimeout(function () {
      if (Object.keys(jobs).length || !barWrap) { return; }
      barWrap.style.display = 'none';
      fill.style.width = '0';
    }, 180);
  }

  function finish(id, ok) {
    delete jobs[id];
    if (Object.keys(jobs).length) { paint(); return; }
    guardUnload(false);
    wantShow = false;
    if (showTimer) { clearTimeout(showTimer); showTimer = null; }
    if (!shownAt) { return; }                       // never shown: nothing to take down
    var wait = Math.max(0, MIN_SHOW - (Date.now() - shownAt));
    if (ok) {
      // A brief tick, so "it finished" is seen rather than inferred from a vanishing box.
      barWrap.classList.remove('kt-up-indet');
      fill.style.width = '100%';
      ringEl.classList.add('kt-up-ok');
      titleEl.textContent = 'Done';
      subEl.textContent = '';
      pctEl.textContent = '';
      wait = Math.max(wait, 450);
    }
    // On failure, get out of the way: the screen shows its own error message.
    hideTimer = setTimeout(function () { hideTimer = null; if (!Object.keys(jobs).length) { hideNow(); } }, wait);
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
  var BIG_TEXT_BODY = 150 * 1024;   // a JSON body this size is carrying a file

  w.fetch = function (input, init) {
    var body = init && init.body;
    var isForm = body && (typeof FormData !== 'undefined') && (body instanceof FormData);
    var file = isForm ? fileIn(body) : null;
    if (!file) {
      /* A FILE SENT AS TEXT. Some screens read the file into base64 and post it inside
         JSON (signed documents, scanned forms). No progress events for those — fetch
         has none — but a body this big is a file on its way up, and the popup still
         says "please wait" until the answer comes back. */
      if (typeof body === 'string' && body.length >= BIG_TEXT_BODY) {
        var sid = 's' + (++seq);
        jobs[sid] = { loaded: 0, total: 0, name: '', saving: true };
        paint();
        var p;
        try { p = orig.apply(this, arguments); } catch (e) { finish(sid, false); throw e; }
        return p.then(function (r) { finish(sid, !!(r && r.ok)); return r; },
                      function (e) { finish(sid, false); throw e; });
      }
      return orig.apply(this, arguments);
    }

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

  /* The same popup for any other slow step a screen wants to cover (building a large
     PDF, a bulk import that is not a file upload):
         var done = KT.pleaseWait('Importing families');
         try { await work(); done(true); } catch (e) { done(false); throw e; } */
  w.KT.pleaseWait = function (title) {
    var id = 'w' + (++seq);
    jobs[id] = { loaded: 0, total: 0, name: title || '', saving: true, title: title || '' };
    paint();
    return function (ok) { if (jobs[id]) { finish(id, ok !== false); } };
  };
})(window);
