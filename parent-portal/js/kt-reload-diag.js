/* ═══════════════════════════════════════════════════════════════════
   KiddieTrac — TEMPORARY reload-loop diagnostic (2026-09-08). Remove once solved.

   "billing under parent keeps reloading and reloading again." I blamed deploy churn,
   which was wrong — it kept happening after I stopped deploying. Reading the source
   has not found it either: the service worker swallows precache failures, activate
   runs once per version, and neither billing renderer re-enters.

   So record what actually happens across reloads. Every load appends one entry to a
   sessionStorage ring — sessionStorage survives a reload in the same tab, which is
   exactly what is needed to see a loop from the inside.

   The entry captures the things that separate the candidate causes:

     · gap        — ms since the previous load. A steady ~120s gap is the service-worker
                    path (kt-native-ui's GAP_MS is 120000); sub-second gaps are a script
                    reloading in a tight loop; irregular gaps point at user action.
     · swMark     — kt_sw_reloaded_at, which kt-native-ui writes immediately before it
                    reloads. If this advances on every load, the SW update path IS the
                    cause and nothing else needs looking at.
     · ctrl       — the controlling worker's URL, so a worker that keeps changing
                    identity is visible.
     · hash       — which screen each load landed on, since the report names billing.
     · lastError  — the most recent window error, in case a crash is driving it.

   Three loads inside 3 minutes opens the panel by itself. No button to find, because a
   page that keeps reloading gives you no time to press one.
   ═══════════════════════════════════════════════════════════════════ */
(function (w, d) {
  'use strict';
  var KEY = 'kt_reload_log';
  var lastError = null;

  w.addEventListener('error', function (e) {
    try { lastError = (e && e.message ? String(e.message) : 'error') + ' @ ' + (e.filename || '?') + ':' + (e.lineno || 0); } catch (x) {}
  });

  function read() {
    try { return JSON.parse(sessionStorage.getItem(KEY) || '[]'); } catch (e) { return []; }
  }
  function write(v) {
    try { sessionStorage.setItem(KEY, JSON.stringify(v.slice(-14))); } catch (e) {}
  }

  var log = read();
  var prev = log.length ? log[log.length - 1] : null;
  var now = Date.now();
  var entry = {
    t: now,
    gap: prev ? (now - prev.t) : null,
    hash: location.hash || '(none)',
    swMark: (function () { try { return Number(sessionStorage.getItem('kt_sw_reloaded_at') || 0); } catch (e) { return 0; } })(),
    swFlag: (function () { try { return sessionStorage.getItem('kt_sw_reloaded') || ''; } catch (e) { return ''; } })(),
    ctrl: (function () {
      try {
        var c = navigator.serviceWorker && navigator.serviceWorker.controller;
        return c ? c.scriptURL.split('/').pop() : 'none';
      } catch (e) { return '?'; }
    })(),
    nav: (function () {
      try {
        var n = performance.getEntriesByType('navigation')[0];
        return n ? n.type : '?';     // navigate | reload | back_forward
      } catch (e) { return '?'; }
    })(),
  };
  log.push(entry);
  write(log);

  try {
    if (navigator.serviceWorker) {
      navigator.serviceWorker.addEventListener('controllerchange', function () {
        var l = read();
        if (l.length) { l[l.length - 1].sawControllerChange = true; write(l); }
      });
    }
  } catch (e) {}

  function panel() {
    if (d.getElementById('kt-reload-diag')) { return; }
    var l = read();
    var L = [];
    L.push('RELOAD LOOP REPORT');
    L.push('loads recorded this session: ' + l.length);
    L.push('');
    L.push('#   gap(s)  nav          hash            controller            swMark advanced');
    for (var i = 0; i < l.length; i++) {
      var e = l[i];
      var advanced = (i > 0 && e.swMark > l[i - 1].swMark) ? 'YES <-- SW reload path' : '';
      L.push(
        String(i).padEnd(4)
        + (e.gap == null ? '—' : (e.gap / 1000).toFixed(1)).padEnd(8)
        + String(e.nav).padEnd(13)
        + String(e.hash).slice(0, 15).padEnd(16)
        + String(e.ctrl).slice(0, 21).padEnd(22)
        + advanced
        + (e.sawControllerChange ? '  [controllerchange]' : '')
      );
    }
    L.push('');
    L.push('SCREEN RE-RENDERS (#appMain emptied): ' + clears.length);
    var c0 = clears.length ? clears[0].t : 0;
    for (var ci = 0; ci < clears.length && ci < 20; ci++) {
      L.push('   +' + ((clears[ci].t - c0) / 1000).toFixed(1) + 's  ' + clears[ci].hash);
    }
    if (clears.length >= 5) {
      L.push('   -> the SCREEN is being torn down repeatedly; the page is NOT reloading');
    }
    L.push('');
    L.push('kt_sw_reloaded flag: ' + (l.length ? (l[l.length - 1].swFlag || '(unset)') : '?'));
    L.push('last JS error: ' + (lastError || 'none'));
    L.push('');
    L.push('READ IT LIKE THIS');
    L.push('  gaps ~120s + swMark advancing  -> the service-worker update path');
    L.push('  gaps under a second            -> a script reloading in a tight loop');
    L.push('  nav = back_forward             -> history navigation, not a reload');
    L.push('  irregular gaps, swMark steady  -> something on the screen itself');

    var box = d.createElement('div');
    box.id = 'kt-reload-diag';
    box.style.cssText = 'position:fixed;inset:6px;z-index:2147483600;background:#0F172A;'
      + 'color:#E2E8F0;font:11px/1.45 ui-monospace,Menlo,Consolas,monospace;padding:10px;'
      + 'border-radius:10px;overflow:auto;white-space:pre-wrap;';
    var x = d.createElement('button');
    x.textContent = 'Close';
    x.style.cssText = 'position:sticky;top:0;float:right;background:#1E293B;color:#E2E8F0;'
      + 'border:1px solid #334155;border-radius:8px;padding:7px 14px;font:inherit;cursor:pointer;';
    x.addEventListener('click', function () {
      box.remove();
      try { sessionStorage.removeItem(KEY); } catch (e) {}   // start a clean count
    });
    box.appendChild(x);
    box.appendChild(d.createTextNode(L.join('\n')));
    d.body.appendChild(box);
  }

  /* ── RE-RENDER WATCH ────────────────────────────────────────────────────────
     The page reloading and the SCREEN being repainted look identical from the outside
     and are completely different faults. This half catches the second: #appMain losing
     all of its children is a teardown, and five inside twenty seconds is a loop rather
     than someone navigating. */
  var clears = [];
  function watchRerenders() {
    var main = d.getElementById('appMain');
    if (!main) { return; }
    try {
      /* `main` is resolved per callback, not closed over: the shell swaps #appMain on
         every render, and a stale reference would make every comparison below false —
         so this diagnostic would quietly stop counting the very re-renders it exists to
         count. */
      var onMut = function (muts) {
        var main = d.getElementById('appMain');
        if (!main) { return; }
        for (var i = 0; i < muts.length; i++) {
          var m = muts[i];
          if (m.target !== main || !m.removedNodes.length || main.children.length) { continue; }
          clears.push({ t: Date.now(), hash: location.hash || '(none)' });
          if (clears.length > 40) { clears.shift(); }
          var recentClears = clears.filter(function (c) { return Date.now() - c.t < 20000; });
          if (recentClears.length >= 5 && !d.getElementById('kt-reload-diag')) {
            try { panel(); } catch (e) {}
          }
        }
      };
      if (w.KT && KT.observeMain) { KT.observeMain(onMut, { childList: true }); }
      else { new MutationObserver(onMut).observe(main, { childList: true }); }
    } catch (e) {}
  }
  /* #appMain exists from the markup, but wait for the body either way. */
  if (d.readyState === 'loading') { d.addEventListener('DOMContentLoaded', watchRerenders); }
  else { watchRerenders(); }

  /* Three loads inside three minutes is a loop, not someone navigating. */
  var recent = log.filter(function (e) { return now - e.t < 180000; });
  if (recent.length >= 3) {
    if (d.readyState === 'loading') { d.addEventListener('DOMContentLoaded', function () { setTimeout(panel, 400); }); }
    else { setTimeout(panel, 400); }
  }

  (w.KT = w.KT || {}).reloadDiag = { show: panel, clear: function () { try { sessionStorage.removeItem(KEY); } catch (e) {} } };
}(window, document));
