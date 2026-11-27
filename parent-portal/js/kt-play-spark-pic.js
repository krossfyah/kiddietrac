/* ============================================================
   PLAY SPARK END-RESULT PICTURE (2026-11-24, Anthony: "playspark should also generate an image of the lesson
   generated as well to show the educator on what the end result will be with the steps provided").

   Claude draws it in the queue (App\Support\PlaySparkPicture + App\Jobs\DrawPlaySparkPicture): the finished set-up
   on top ("The end result"), numbered step panels under it. Every Play Spark idea gets one, and every weekly
   lesson-plan activity (queued when the plan is saved; keyed by the activity's words). AI Bundle only.

   v2 (2026-11-27, Anthony: "the playspark images that are drawn needs to be better and more precise so the educators
   can follow this"): planned, drawn and checked in three passes; big close-up step panels with full instructions.
   The big view also lists the steps as TEXT (r.steps) so they read on a phone, and staff get "↻ Redraw picture"
   (opts.redraw); while a fresh drawing is on its way (r.redrawing) the old picture stays and is swapped when ready.

   One engine for every screen that shows it: Play Spark (Right now, Library, favourites, print, Play at home),
   the weekly lesson plan (staff + parents) and the provider day. The server sanitises the SVG; it is still only
   ever shown through <img src="data:..."> - an SVG inside <img> runs nothing.

   KT.PlaySparkPic.mount(el, fetcher, {key, title, thumb, link, redraw})  fetcher() -> Promise<{status, svg?, steps?, redrawing?}>
   KT.PlaySparkPic.idea(el, ideaId, title, opts)                           staff: GET provider/play-spark/ideas/{id}/picture
   KT.PlaySparkPic.open(svg, title, {steps, redraw, key})                  the big view (Shell.Modal, large)
   KT.PlaySparkPic.redrawIdea(id) / redrawLesson(body)                     staff: functions for opts.redraw
   KT.PlaySparkPic.cached(key) / remember(key, svg, steps) / steps(key) / src(svg)
   ============================================================ */
(function (window) {
  'use strict';
  var KT = window.KT = window.KT || {};
  if (KT.PlaySparkPic) return;

  var CACHE = {}, STEPS = {};
  function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; });
  }
  function src(svg) { return 'data:image/svg+xml;charset=utf-8,' + encodeURIComponent(svg); }
  function remember(k, svg, steps) {
    if (k && svg) CACHE[k] = svg;
    if (k && Array.isArray(steps)) STEPS[k] = steps;
  }

  var CSS = '' +
    /* Its own white paper in light and dark mode alike: it is a drawing, not UI. */
    '.ps-pic{margin:10px 0 2px;}' +
    '.ps-pic:empty{display:none;}' +
    '.ps-pic-btn{display:block;width:100%;max-width:720px;padding:0;border:1px solid var(--kt-border,#d7dde8);border-radius:12px;overflow:hidden;background:#fff;cursor:zoom-in;}' +
    '.ps-pic-btn img{display:block;width:100%;height:auto;}' +
    '.ps-pic-cap{font-size:11.5px;color:var(--kt-text-faint,#64748b);margin-top:4px;}' +
    '.ps-pic-wait{display:flex;align-items:center;gap:10px;max-width:720px;padding:12px 14px;border:1px dashed var(--kt-border,#d7dde8);border-radius:12px;font-size:13px;color:var(--kt-text-muted,#475569);background:var(--kt-bg,#f6f8fb);}' +
    '.ps-pic-wait .dot{width:10px;height:10px;border-radius:50%;background:#FFC531;flex:none;animation:psPicPulse 1.2s ease-in-out infinite;}' +
    '@keyframes psPicPulse{0%,100%{opacity:.35;transform:scale(.8)}50%{opacity:1;transform:scale(1.1)}}' +
    '@media (prefers-reduced-motion:reduce){.ps-pic-wait .dot{animation:none}}' +
    '.ps-thumb{display:block;width:100%;aspect-ratio:800/440;object-fit:cover;object-position:top;border-radius:9px;margin:0 0 8px;background:#fff;border:1px solid var(--kt-border,#d7dde8);}' +
    '.ps-thumb-wrap:empty{display:none;}' +
    '.ps-pic-big{display:block;width:100%;height:auto;border-radius:10px;background:#fff;}' +
    '.ps-pic-steps{margin:10px 0 0;padding:0 0 0 22px;font-size:13.5px;line-height:1.45;color:var(--kt-text,#1e293b);}' +
    '.ps-pic-steps li{margin:0 0 4px;}' +
    '.ps-pic-link{display:inline-flex;align-items:center;gap:5px;height:26px;padding:0 9px;border-radius:13px;border:1px solid var(--kt-border,#d7dde8);background:var(--kt-surface,#fff);color:inherit;font:inherit;font-size:12px;font-weight:700;cursor:pointer;}' +
    '.ps-pic-link:hover{border-color:#FFC531;}' +
    '@media print{.ps-pic-btn{border:0;max-width:100%;}.ps-pic-cap{display:none;}}';
  function addCss() {
    if (document.getElementById('ps-pic-css')) return;
    var s = document.createElement('style'); s.id = 'ps-pic-css'; s.textContent = CSS; document.head.appendChild(s);
  }

  function toast(msg) { if (KT.toast) KT.toast('🎨', 'Play Spark', msg); }

  /* The big view. opts.steps = the captions as text; opts.redraw() -> Promise (staff only: "↻ Redraw picture"). */
  function open(svg, title, opts) {
    opts = opts || {};
    var steps = opts.steps || (opts.key && STEPS[opts.key]) || null;
    var b = document.createElement('div');
    b.innerHTML = '<img class="ps-pic-big" alt="' + esc((title || 'Play') + ': the end result and the steps') + '" src="' + src(svg) + '">' +
      (steps && steps.length ? '<ol class="ps-pic-steps">' + steps.map(function (s) { return '<li>' + esc(s) + '</li>'; }).join('') + '</ol>' : '') +
      '<div class="ps-pic-cap" style="margin-top:8px">Drawn by Play Spark to show the finished set-up and the steps. Yours will look a little different, and that is fine.</div>';
    var Shell = KT.Shell;
    var actions = [];
    if (typeof opts.redraw === 'function') {
      actions.push({ label: '↻ Redraw picture', busyLabel: 'Asking…', handler: function () {
        return Promise.resolve(opts.redraw()).then(function (r) {
          if (opts.key) { delete CACHE[opts.key]; delete STEPS[opts.key]; }
          toast((r && r.message) || 'A fresh picture is being drawn. It replaces this one in a few minutes.');
        });
      } });
    }
    actions.push({ label: 'Close' });
    if (Shell && Shell.Modal) Shell.Modal.open({ title: '🎨 ' + (title || 'The end result'), body: b, large: true, actions: actions });
  }

  function mount(el, fetcher, opts) {
    opts = opts || {};
    if (!el) return;
    addCss();
    var tries = 0, shown = '';
    var show = function (svg, steps) {
      remember(opts.key, svg, steps);
      if (svg === shown) return;
      shown = svg;
      var alt = esc((opts.title || 'Play') + ': the end result and the steps');
      if (opts.link) {
        el.innerHTML = '<button type="button" class="ps-pic-link" data-kttip="What it looks like set up, and the steps">🎨 End result</button>';
      } else if (opts.thumb) {
        el.innerHTML = '<img class="ps-thumb" alt="' + alt + '" src="' + src(svg) + '">';
      } else {
        el.innerHTML = '<button type="button" class="ps-pic-btn" aria-label="Make the picture bigger"><img alt="' + alt + '" src="' + src(svg) + '"></button>' +
          '<div class="ps-pic-cap">🎨 The end result and the steps · tap to make it bigger</div>';
      }
      var btn = el.querySelector('button');
      if (btn) btn.addEventListener('click', function (e) {
        e.preventDefault(); e.stopPropagation();
        open(svg, opts.title, { steps: steps, key: opts.key, redraw: opts.redraw });
      });
      if (btn) btn.addEventListener('keydown', function (e) { e.stopPropagation(); });   // a card behind it answers Enter too
    };
    if (opts.key && CACHE[opts.key]) { show(CACHE[opts.key], STEPS[opts.key]); return; }
    var tick = function () {
      if (tries > 0 && !el.isConnected) return;   // the screen moved on
      tries++;
      Promise.resolve().then(fetcher).then(function (r) {
        r = r || {};
        if (r.status === 'ready' && r.svg) {
          show(r.svg, r.steps);
          // A fresh drawing on its way: keep this one up and look again, slowly, for a few minutes.
          if (r.redrawing && tries < 25 && !opts.thumb) setTimeout(tick, 15000);
          return;
        }
        if (r.status === 'drawing' && tries < 45) {
          if (opts.link && !el.firstChild) {
            el.innerHTML = '<span class="ps-pic-link" style="cursor:default;opacity:.7">🎨 Drawing…</span>';
          } else if (!opts.thumb && !opts.link && !el.querySelector('.ps-pic-wait')) {
            el.innerHTML = '<div class="ps-pic-wait"><span class="dot"></span>🎨 Drawing the end result and the steps… it appears here in two or three minutes.</div>';
          }
          setTimeout(tick, tries < 3 ? 8000 : 12000);
          return;
        }
        if (!shown) el.innerHTML = '';   // none, failed or off: the activity stands on its own, as before
      }).catch(function () { if (!shown) el.innerHTML = ''; });
    };
    tick();
  }

  function redrawIdea(id) { return function () { return KT.Api.post('/provider/play-spark/ideas/' + id + '/picture/redraw', {}); }; }
  function redrawLesson(body) { return function () { return KT.Api.post('/provider/play-spark/lesson-picture/redraw', body); }; }

  function idea(el, id, title, opts) {
    mount(el, function () { return KT.Api.get('/provider/play-spark/ideas/' + id + '/picture'); },
      Object.assign({ key: 'i' + id, title: title, redraw: redrawIdea(id) }, opts || {}));
  }

  KT.PlaySparkPic = { mount: mount, idea: idea, open: open, src: src, addCss: addCss, redrawIdea: redrawIdea, redrawLesson: redrawLesson,
    cached: function (k) { return CACHE[k] || null; }, steps: function (k) { return STEPS[k] || null; }, remember: remember };
})(window);
