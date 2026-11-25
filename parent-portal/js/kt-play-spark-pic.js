/* ============================================================
   PLAY SPARK END-RESULT PICTURE (2026-11-24, Anthony: "playspark should also generate an image of the lesson
   generated as well to show the educator on what the end result will be with the steps provided").

   Claude draws it in the queue (App\Support\PlaySparkPicture + App\Jobs\DrawPlaySparkPicture): the finished set-up
   on top ("The end result"), numbered step panels under it. Every Play Spark idea gets one, and every weekly
   lesson-plan activity (queued when the plan is saved; keyed by the activity's words). AI Bundle only.

   One engine for every screen that shows it: Play Spark (Right now, Library, favourites, print, Play at home),
   the weekly lesson plan (staff + parents) and the provider day. The server sanitises the SVG; it is still only
   ever shown through <img src="data:..."> - an SVG inside <img> runs nothing.

   KT.PlaySparkPic.mount(el, fetcher, {key, title, thumb, link})  fetcher() -> Promise<{status, svg?}>
   KT.PlaySparkPic.idea(el, ideaId, title, opts)                   staff: GET provider/play-spark/ideas/{id}/picture
   KT.PlaySparkPic.open(svg, title)                                the big view (Shell.Modal, large)
   KT.PlaySparkPic.cached(key) / KT.PlaySparkPic.src(svg)
   ============================================================ */
(function (window) {
  'use strict';
  var KT = window.KT = window.KT || {};
  if (KT.PlaySparkPic) return;

  var CACHE = {};
  function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; });
  }
  function src(svg) { return 'data:image/svg+xml;charset=utf-8,' + encodeURIComponent(svg); }

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
    '.ps-pic-link{display:inline-flex;align-items:center;gap:5px;height:26px;padding:0 9px;border-radius:13px;border:1px solid var(--kt-border,#d7dde8);background:var(--kt-surface,#fff);color:inherit;font:inherit;font-size:12px;font-weight:700;cursor:pointer;}' +
    '.ps-pic-link:hover{border-color:#FFC531;}' +
    '@media print{.ps-pic-btn{border:0;max-width:100%;}.ps-pic-cap{display:none;}}';
  function addCss() {
    if (document.getElementById('ps-pic-css')) return;
    var s = document.createElement('style'); s.id = 'ps-pic-css'; s.textContent = CSS; document.head.appendChild(s);
  }

  function open(svg, title) {
    var b = document.createElement('div');
    b.innerHTML = '<img class="ps-pic-big" alt="' + esc((title || 'Play') + ': the end result and the steps') + '" src="' + src(svg) + '">' +
      '<div class="ps-pic-cap" style="margin-top:8px">Drawn by Play Spark to show the finished set-up and the steps. Yours will look a little different, and that is fine.</div>';
    var Shell = KT.Shell;
    if (Shell && Shell.Modal) Shell.Modal.open({ title: '🎨 ' + (title || 'The end result'), body: b, large: true, actions: [{ label: 'Close' }] });
  }

  function mount(el, fetcher, opts) {
    opts = opts || {};
    if (!el) return;
    addCss();
    var tries = 0;
    var show = function (svg) {
      if (opts.key) CACHE[opts.key] = svg;
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
      if (btn) btn.addEventListener('click', function (e) { e.preventDefault(); e.stopPropagation(); open(svg, opts.title); });
      if (btn) btn.addEventListener('keydown', function (e) { e.stopPropagation(); });   // a card behind it answers Enter too
    };
    if (opts.key && CACHE[opts.key]) { show(CACHE[opts.key]); return; }
    var tick = function () {
      if (tries > 0 && !el.isConnected) return;   // the screen moved on
      tries++;
      Promise.resolve().then(fetcher).then(function (r) {
        r = r || {};
        if (r.status === 'ready' && r.svg) { show(r.svg); return; }
        if (r.status === 'drawing' && tries < 45) {
          if (opts.link && !el.firstChild) {
            el.innerHTML = '<span class="ps-pic-link" style="cursor:default;opacity:.7">🎨 Drawing…</span>';
          } else if (!opts.thumb && !opts.link && !el.querySelector('.ps-pic-wait')) {
            el.innerHTML = '<div class="ps-pic-wait"><span class="dot"></span>🎨 Drawing the end result and the steps… it appears here in a minute or so.</div>';
          }
          setTimeout(tick, tries < 3 ? 5000 : 9000);
          return;
        }
        el.innerHTML = '';   // none, failed or off: the activity stands on its own, as before
      }).catch(function () { el.innerHTML = ''; });
    };
    tick();
  }

  function idea(el, id, title, opts) {
    mount(el, function () { return KT.Api.get('/provider/play-spark/ideas/' + id + '/picture'); },
      Object.assign({ key: 'i' + id, title: title }, opts || {}));
  }

  KT.PlaySparkPic = { mount: mount, idea: idea, open: open, src: src, addCss: addCss, cached: function (k) { return CACHE[k] || null; },
    remember: function (k, svg) { if (k && svg) CACHE[k] = svg; } };
})(window);
