/* ═══════════════════════════════════════════════════════════════════
   KiddieTrac — re-render diagnostic (2026-09-21)

   WHY THIS EXISTS. "Pages are still re-rendering" has been chased three times from
   screenshots and from a browser that cannot reproduce it: the automation tab is always
   `document.hidden`, which is exactly the condition kt-auto-refresh checks before it
   polls (`if (document.hidden) return;`) — and rAF never fires there either, so every
   timing measured that way is wrong by an order of magnitude. Reasoning from those
   numbers produced two confident theories that both turned out to be false.

   So: measure it where it actually happens. This records, in the real session, WHAT
   triggered each re-render, HOW LONG the screen was covered, and how long after the
   content arrived the decorators were still writing. It costs nothing until switched on.

   TO TURN IT ON  — in the browser console, or the phone's remote console:
       localStorage.kt_diag = '1'; location.reload();
   TO READ IT     — the panel bottom-left, or:  copy(KT.diag.dump())
   TO TURN IT OFF — localStorage.removeItem('kt_diag'); location.reload();

   Deliberately NOT always-on: it patches DOM methods to attribute writes, which is fine
   for a diagnostic session and not something to carry on every phone in the agency.
   ═══════════════════════════════════════════════════════════════════ */
(function (w, d) {
  'use strict';

  var ON = false;
  try { ON = w.localStorage && localStorage.getItem('kt_diag') === '1'; } catch (e) {}
  if (!ON) { return; }

  var KT = w.KT || (w.KT = {});
  var log = [];
  var MAX = 300;
  var t0 = Date.now();
  var renderStart = 0;
  var contentAt = 0;
  var panel = null;

  function now() { return Date.now() - t0; }

  function add(kind, detail) {
    log.push({ t: now(), kind: kind, detail: detail || '' });
    if (log.length > MAX) { log.shift(); }
    paint();
  }

  /* ── WHAT STARTED THIS RENDER ────────────────────────────────────────
     The three callers set different flags on their way in, so the trigger can be read
     rather than guessed. This is the question every previous round could not answer. */
  function trigger() {
    try {
      if (w.__ktDiagTrigger) { var t = w.__ktDiagTrigger; w.__ktDiagTrigger = null; return t; }
      if (w.__ktSilentRefresh) { return 'silent (kt-live or auto-refresh)'; }
    } catch (e) {}

    return 'navigation';
  }

  /* Tag the two silent callers so their renders are distinguishable. Wrapping rather
     than editing them keeps this file self-contained and removable. */
  try {
    var origSet = Object.getOwnPropertyDescriptor(w, '__ktSilentRefresh');
    if (!origSet) {
      var _silent = false;
      Object.defineProperty(w, '__ktSilentRefresh', {
        configurable: true,
        get: function () { return _silent; },
        set: function (v) {
          if (v && !_silent) {
            /* Who is asking? The stack names the file, which is the whole point. */
            var st = (new Error()).stack || '';
            var m = st.match(/\/js\/(kt-live|kt-auto-refresh)[^:]*:(\d+)/);
            w.__ktDiagTrigger = m ? (m[1] + ':' + m[2]) : 'silent (unknown caller)';
          }
          _silent = v;
        }
      });
    }
  } catch (e) {}

  /* ── THE RENDER ITSELF ───────────────────────────────────────────────
     kt:main-swapped fires once per render, from the shell, after the node is replaced.
     That is the moment the old screen stopped being on screen. */
  d.addEventListener('kt:main-swapped', function () {
    renderStart = now();
    contentAt = 0;
    add('RENDER', trigger());
  });

  /* ── WHO IS WRITING, AND WHEN ────────────────────────────────────────
     Attributes each write to the file that made it, so "the screen kept moving after it
     appeared" becomes a list of names and millisecond offsets. */
  var P = Element.prototype;
  if (!P.__ktDiagPatched) {
    P.__ktDiagPatched = true;
    var oAppend = P.appendChild;
    var oRemove = P.removeChild;
    var ihDesc = Object.getOwnPropertyDescriptor(P, 'innerHTML');

    var writes = {};
    var flushT = null;

    function inMain(node) {
      var m = d.getElementById('appMain');

      return m && node && (node === m || m.contains(node));
    }

    function record() {
      var st = (new Error()).stack || '';
      var lines = st.split('\n').slice(2, 7);
      var who = 'unknown';
      for (var i = 0; i < lines.length; i++) {
        var mm = lines[i].match(/\/js\/([\w.\-]+)\.js[^:]*:(\d+)/);
        if (mm && mm[1].indexOf('rerender-diag') === -1) { who = mm[1] + ':' + mm[2]; break; }
      }
      writes[who] = (writes[who] || 0) + 1;
      if (!contentAt) { contentAt = now(); }

      /* Coalesced: one line per burst, not one per appendChild — 31 kebab buttons is a
         single event to a reader, and 31 lines would bury everything else. */
      if (flushT) { clearTimeout(flushT); }
      flushT = setTimeout(function () {
        var parts = [];
        for (var k in writes) { if (writes[k] > 0) { parts.push(k + '×' + writes[k]); } }
        writes = {};
        var since = renderStart ? (' [+' + (now() - renderStart) + 'ms after render]') : '';
        add('writes', parts.join(', ') + since);
      }, 120);
    }

    P.appendChild = function (n) { if (inMain(this)) { record(); } return oAppend.call(this, n); };
    P.removeChild = function (n) { if (inMain(this)) { record(); } return oRemove.call(this, n); };
    Object.defineProperty(P, 'innerHTML', {
      configurable: true,
      get: ihDesc.get,
      set: function (v) { if (inMain(this)) { record(); } return ihDesc.set.call(this, v); }
    });
  }

  /* ── THE COVER ───────────────────────────────────────────────────────
     How long the screen was a frozen picture. If this is long, the reader is looking at
     stale content; if it ends before the writes do, they watched it assemble. */
  var coverUp = 0;
  setInterval(function () {
    var on = !!d.getElementById('kt-refresh-snap');
    if (on && !coverUp) { coverUp = now(); add('cover ON', ''); }
    else if (!on && coverUp) { add('cover OFF', 'held ' + (now() - coverUp) + 'ms'); coverUp = 0; }
  }, 60);

  /* ── THE PANEL ───────────────────────────────────────────────────────
     Small, bottom-left, out of the way of the mobile nav bar on the right. */
  function paint() {
    if (!panel) { return; }
    var rows = log.slice(-14).map(function (e) {
      var c = e.kind === 'RENDER' ? '#FCA5A5'
        : e.kind.indexOf('cover') === 0 ? '#93C5FD'
        : '#CBD5E1';

      return '<div style="color:' + c + ';white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">'
        + (e.t / 1000).toFixed(1) + 's  <b>' + e.kind + '</b> ' + e.detail + '</div>';
    }).join('');
    panel.innerHTML = '<div style="color:#FDE68A;font-weight:700;margin-bottom:3px;">'
      + 'kt re-render diag · ' + log.filter(function (e) { return e.kind === 'RENDER'; }).length
      + ' renders</div>' + rows;
  }

  function mount() {
    if (panel || !d.body) { return; }
    panel = d.createElement('div');
    panel.id = 'kt-rerender-diag';
    panel.style.cssText = 'position:fixed;left:6px;bottom:6px;z-index:2147483640;'
      + 'width:min(370px,72vw);max-height:38vh;overflow:auto;background:rgba(15,23,42,.93);'
      + 'color:#E2E8F0;font:11px/1.45 ui-monospace,Menlo,Consolas,monospace;padding:7px 9px;'
      + 'border-radius:9px;pointer-events:auto;box-shadow:0 8px 24px rgba(0,0,0,.4);';
    panel.addEventListener('dblclick', function () { log.length = 0; paint(); });
    d.body.appendChild(panel);
    paint();
  }

  if (d.readyState === 'loading') { d.addEventListener('DOMContentLoaded', mount); }
  else { mount(); }

  KT.diag = {
    dump: function () {
      return log.map(function (e) {
        return (e.t / 1000).toFixed(1) + 's\t' + e.kind + '\t' + e.detail;
      }).join('\n');
    },
    clear: function () { log.length = 0; paint(); },
    log: log
  };
})(window, document);
