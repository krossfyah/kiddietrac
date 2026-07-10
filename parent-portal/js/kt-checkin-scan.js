/* ═══════════════════════════════════════════════════════════════════
   KiddieTrac — Parent QR check-in scanner (2026-07-10).
   A full-screen camera view that reads the centre's daily check-in QR
   ("KTCHK.<centre>.<ymd>.<sig>") and toggles the parent's enrolled
   children in / out via POST /parent/checkin/scan.

   • Uses the native BarcodeDetector (Android WebView / Chrome) — no CDN,
     nothing leaves the device until a valid KiddieTrac code is submitted.
   • Camera stream is always stopped on leave (back, success, or navigating
     away) so the light/indicator never stays on.
   • Registered as the `scan` screen for guardians; `checkin` also routes
     here so the Home “Check-in” tile opens the scanner.
   ═══════════════════════════════════════════════════════════════════ */
(function (window) {
  'use strict';
  var KT = window.KT;
  if (!KT || !KT.Shell || !KT.Shell.registerScreen) return;
  var Shell = KT.Shell;

  var TEAL = '#0E7C90', NAVY = '#0D1B2A';
  var stream = null, rafId = null, detector = null, busy = false;

  function stopCamera() {
    if (rafId) { cancelAnimationFrame(rafId); rafId = null; }
    if (stream) { try { stream.getTracks().forEach(function (t) { t.stop(); }); } catch (e) {} stream = null; }
  }

  // Whenever we leave #scan, kill the camera.
  window.addEventListener('hashchange', function () {
    var h = (location.hash || '').replace('#', '').split('?')[0];
    if (h !== 'scan') stopCamera();
  });

  function el(tag, style, text) {
    var e = document.createElement(tag);
    if (style) e.style.cssText = style;
    if (text != null) e.textContent = text;
    return e;
  }

  function post(code) {
    if (KT.Api && KT.Api.post) return KT.Api.post('/parent/checkin/scan', { code: code });
    var t = sessionStorage.getItem('kt_token') || localStorage.getItem('kt_token');
    var base = (window.KT_CONFIG && window.KT_CONFIG.apiBase) || 'https://api.kiddietrac.com/api/v1';
    var h = { 'Content-Type': 'application/json', 'Accept': 'application/json', 'Authorization': 'Bearer ' + t };
    try { var aid = sessionStorage.getItem('kt_active_agency_id'); if (aid) h['X-Active-Agency-Id'] = aid; } catch (e) {}
    return fetch(base + '/parent/checkin/scan', { method: 'POST', headers: h, body: JSON.stringify({ code: code }) })
      .then(function (r) { return r.json().then(function (j) { if (!r.ok) throw new Error(j.message || 'Check-in failed'); return j; }); });
  }

  function render(main) {
    stopCamera(); busy = false;
    main.innerHTML = '';

    var ov = el('div', 'position:fixed;inset:0;z-index:9700;background:' + NAVY + ';display:flex;flex-direction:column;overflow:hidden;');

    // Header
    var head = el('div', 'position:relative;z-index:3;display:flex;align-items:center;gap:10px;padding:calc(env(safe-area-inset-top,0px) + 12px) 14px 12px;color:#fff;');
    var close = el('button', 'background:rgba(255,255,255,.16);color:#fff;border:none;width:38px;height:38px;border-radius:50%;font-size:22px;line-height:1;cursor:pointer;flex-shrink:0;', '‹');
    close.setAttribute('aria-label', 'Back');
    close.addEventListener('click', function () { stopCamera(); location.hash = '#home'; });
    head.appendChild(close);
    head.appendChild(el('div', 'font-weight:800;font-size:16px;', 'Scan to check in / out'));
    ov.appendChild(head);

    // Camera area
    var camWrap = el('div', 'position:absolute;inset:0;z-index:1;background:#000;');
    var video = document.createElement('video');
    video.setAttribute('playsinline', ''); video.muted = true; video.autoplay = true;
    video.style.cssText = 'width:100%;height:100%;object-fit:cover;';
    camWrap.appendChild(video);
    ov.appendChild(camWrap);

    // Dim + scan frame overlay
    var frameWrap = el('div', 'position:absolute;inset:0;z-index:2;display:flex;align-items:center;justify-content:center;pointer-events:none;');
    var frame = el('div', 'width:min(68vw,260px);aspect-ratio:1;border-radius:26px;box-shadow:0 0 0 100vmax rgba(13,27,42,.55);position:relative;');
    // corner accents
    ['top:-2px;left:-2px;border-top;border-left;border-radius:26px 0 0 0',
     'top:-2px;right:-2px;border-top;border-right;border-radius:0 26px 0 0',
     'bottom:-2px;left:-2px;border-bottom;border-left;border-radius:0 0 0 26px',
     'bottom:-2px;right:-2px;border-bottom;border-right;border-radius:0 0 26px 0'].forEach(function (spec) {
      var parts = spec.split(';');
      var c = el('div', 'position:absolute;width:34px;height:34px;' + parts[0] + ';' + parts[1] + ';' +
        parts[2] + ':4px solid ' + TEAL + ';' + parts[3] + ':4px solid ' + TEAL + ';' + parts[4] + ';');
      frame.appendChild(c);
    });
    frameWrap.appendChild(frame);
    ov.appendChild(frameWrap);

    // Status / footer
    var foot = el('div', 'position:absolute;left:0;right:0;bottom:0;z-index:3;padding:20px 20px calc(env(safe-area-inset-bottom,0px) + 24px);text-align:center;color:#fff;');
    var status = el('div', 'font-size:15px;font-weight:600;line-height:1.5;text-shadow:0 1px 6px rgba(0,0,0,.6);min-height:44px;', 'Point your camera at the centre’s check-in QR code.');
    foot.appendChild(status);
    ov.appendChild(foot);

    main.appendChild(ov);

    startCamera(video, status, foot);
  }

  function startCamera(video, status, foot) {
    if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
      return fail(status, 'Camera not available on this device.', foot);
    }
    if ('BarcodeDetector' in window) {
      try { detector = new window.BarcodeDetector({ formats: ['qr_code'] }); } catch (e) { detector = null; }
    } else { detector = null; }

    navigator.mediaDevices.getUserMedia({ video: { facingMode: { ideal: 'environment' } }, audio: false })
      .then(function (s) {
        stream = s; video.srcObject = s;
        return video.play().catch(function () {});
      })
      .then(function () {
        if (!detector) { manualFallback(status, foot, 'This device can’t auto-scan. Enter the code shown under the centre’s QR:'); return; }
        loop(video, status, foot);
      })
      .catch(function (err) {
        var msg = (err && err.name === 'NotAllowedError')
          ? 'Camera permission was denied. Enable camera access for KiddieTrac in your phone settings.'
          : 'Could not start the camera.';
        fail(status, msg, foot);
        manualFallback(status, foot, 'Or enter the code shown under the centre’s QR:');
      });
  }

  function loop(video, status, foot) {
    if (!stream || busy) return;
    detector.detect(video).then(function (codes) {
      if (busy) return;
      var hit = (codes || []).map(function (c) { return c.rawValue || ''; }).find(function (v) { return /^KTCHK\./.test(v); });
      if (hit) { submit(hit, status, foot); return; }
      rafId = requestAnimationFrame(function () { loop(video, status, foot); });
    }).catch(function () {
      rafId = requestAnimationFrame(function () { loop(video, status, foot); });
    });
  }

  function submit(code, status, foot) {
    if (busy) return; busy = true;
    stopCamera();
    status.textContent = 'Checking you in…';
    if (navigator.vibrate) { try { navigator.vibrate(40); } catch (e) {} }
    post(code).then(function (res) {
      showResult((res && res.results) || [], status, foot);
    }).catch(function (e) {
      fail(status, (e && e.message) || 'Could not check in — please try again.', foot);
      retryBtn(foot);
    });
  }

  function showResult(results, status, foot) {
    var main = document.getElementById('appMain'); if (!main) return;
    main.innerHTML = '';
    var wrap = el('div', 'position:fixed;inset:0;z-index:9700;background:linear-gradient(160deg,#0E7C90,#0D1B2A);display:flex;flex-direction:column;align-items:center;justify-content:center;text-align:center;padding:28px;color:#fff;');
    wrap.appendChild(el('div', 'font-size:74px;line-height:1;margin-bottom:10px;', '✅'));
    wrap.appendChild(el('div', 'font-weight:800;font-size:22px;margin-bottom:18px;', 'All set!'));
    var card = el('div', 'background:rgba(255,255,255,.12);border-radius:18px;padding:16px 20px;max-width:320px;width:100%;');
    if (!results.length) {
      card.appendChild(el('div', 'font-size:15px;', 'Check-in recorded.'));
    } else {
      results.forEach(function (r) {
        var row = el('div', 'display:flex;align-items:center;justify-content:space-between;gap:12px;padding:9px 0;border-bottom:1px solid rgba(255,255,255,.14);');
        row.appendChild(el('div', 'font-weight:700;font-size:16px;', r.name));
        var tag = el('div', 'font-size:13px;font-weight:700;padding:4px 10px;border-radius:20px;background:' + (r.event === 'check_in' ? 'rgba(52,211,153,.25)' : 'rgba(251,191,36,.25)') + ';white-space:nowrap;', (r.label || '') + ' · ' + (r.time || ''));
        row.appendChild(tag);
        card.appendChild(row);
      });
      card.lastChild.style.borderBottom = 'none';
    }
    wrap.appendChild(card);
    var btns = el('div', 'display:flex;flex-direction:column;gap:10px;margin-top:24px;width:100%;max-width:320px;');
    var done = el('button', 'background:#fff;color:' + NAVY + ';border:none;border-radius:14px;padding:15px;font-size:16px;font-weight:800;cursor:pointer;', 'Done');
    done.addEventListener('click', function () { location.hash = '#home'; });
    var again = el('button', 'background:transparent;color:#fff;border:1.5px solid rgba(255,255,255,.5);border-radius:14px;padding:13px;font-size:15px;font-weight:700;cursor:pointer;', 'Scan again');
    again.addEventListener('click', function () { render(document.getElementById('appMain')); });
    btns.appendChild(done); btns.appendChild(again);
    wrap.appendChild(btns);
    main.appendChild(wrap);
  }

  function fail(status, msg, foot) {
    status.style.color = '#FCA5A5';
    status.textContent = msg;
  }

  function retryBtn(foot) {
    if (foot.querySelector('.kt-scan-retry')) return;
    var b = el('button', 'margin-top:14px;background:#fff;color:' + NAVY + ';border:none;border-radius:12px;padding:12px 22px;font-size:15px;font-weight:800;cursor:pointer;', 'Try again');
    b.className = 'kt-scan-retry';
    b.addEventListener('click', function () { render(document.getElementById('appMain')); });
    foot.appendChild(b);
  }

  function manualFallback(status, foot, prompt) {
    if (foot.querySelector('.kt-scan-manual')) return;
    var box = el('div', 'margin-top:14px;');
    box.className = 'kt-scan-manual';
    box.appendChild(el('div', 'font-size:13px;opacity:.85;margin-bottom:8px;', prompt));
    var row = el('div', 'display:flex;gap:8px;justify-content:center;');
    var inp = document.createElement('input');
    inp.type = 'text'; inp.placeholder = 'KTCHK.…';
    inp.style.cssText = 'flex:1;max-width:220px;padding:12px 14px;border:none;border-radius:12px;font-size:16px;';
    var go = el('button', 'background:' + TEAL + ';color:#fff;border:none;border-radius:12px;padding:0 18px;font-weight:800;font-size:15px;cursor:pointer;', 'Go');
    go.addEventListener('click', function () { var v = inp.value.trim(); if (v) submit(v, status, foot); });
    row.appendChild(inp); row.appendChild(go);
    box.appendChild(row);
    foot.appendChild(box);
  }

  Shell.registerScreen('guardian:scan', render);
})(window);
