/* ═══════════════════════════════════════════════════════════════════
   KiddieTrac — onboarding: Privacy Policy & NDA (2026-07-13).

   Every new user, in any role, reads and signs this once before using the app.
   It blocks the dashboard until signed (the only way past it is to sign, or to
   sign out) — that's the point of an onboarding agreement.

   The signature is drawn on a canvas (finger on a phone, mouse on a desktop)
   and posted as a PNG. The server records who signed, when, from what IP, and a
   hash of the exact wording they saw, files the signed copy against their user
   record, and emails them a copy.

   The Continue button stays disabled until they have actually scrolled to the
   bottom of the agreement, typed their name, AND drawn something — clicking "I
   agree" without any of that isn't consent.
   ═══════════════════════════════════════════════════════════════════ */
(function (window) {
  'use strict';
  if (window.__ktAgreement) return; window.__ktAgreement = true;

  var KT = window.KT || (window.KT = {});

  function tok() { try { return sessionStorage.getItem('kt_token') || localStorage.getItem('kt_token'); } catch (e) { return null; } }
  function apiBase() { return (window.KT_CONFIG && window.KT_CONFIG.apiBase) || 'https://api.kiddietrac.com/api/v1'; }

  function api(method, path, body) {
    var opts = { method: method, headers: { 'Authorization': 'Bearer ' + tok(), 'Accept': 'application/json' } };
    if (body !== undefined) {
      opts.headers['Content-Type'] = 'application/json';
      opts.body = JSON.stringify(body);
    }
    return fetch(apiBase() + path, opts).then(function (r) {
      return r.json().catch(function () { return {}; }).then(function (j) {
        if (!r.ok) throw new Error(j.message || ('Error ' + r.status));
        return j;
      });
    });
  }

  function show(info) {
    if (document.getElementById('kt-agree')) return;

    var ov = document.createElement('div');
    ov.id = 'kt-agree';
    ov.style.cssText = 'position:fixed;inset:0;z-index:2147481000;background:#F6F9FC;display:flex;flex-direction:column;'
      + 'font-family:system-ui,-apple-system,sans-serif;';
    ov.innerHTML =
      '<div style="background:#0B2545;color:#fff;padding:16px 18px;flex:0 0 auto;">'
      + '  <div style="font-size:10.5px;font-weight:800;letter-spacing:1.2px;opacity:.75;">BEFORE YOU START</div>'
      + '  <div style="font-size:19px;font-weight:800;margin-top:2px;">Privacy Policy &amp; NDA</div>'
      + '  <div style="font-size:12.5px;opacity:.85;margin-top:3px;">Please read this and sign it — it only takes a minute.</div>'
      + '</div>'
      + '<div id="kt-agree-scroll" style="flex:1;overflow-y:auto;-webkit-overflow-scrolling:touch;padding:16px 18px;">'
      + '  <div id="kt-agree-text" style="background:#fff;border:1px solid #E7EDF3;border-radius:14px;padding:16px;font-size:14px;line-height:1.6;color:#334155;"></div>'
      + '  <div id="kt-agree-hint" style="text-align:center;font-size:12px;color:#94A3B8;margin-top:10px;">Scroll to the end to continue ↓</div>'
      + '  <div id="kt-agree-form" style="opacity:.45;pointer-events:none;transition:opacity .25s;">'
      + '    <div style="background:#fff;border:1px solid #E7EDF3;border-radius:14px;padding:16px;margin-top:14px;">'
      + '      <div style="font-size:11px;font-weight:800;letter-spacing:.5px;color:#64748B;text-transform:uppercase;margin-bottom:6px;">Your full name</div>'
      + '      <input id="kt-agree-name" type="text" autocomplete="name" placeholder="Type your full name"'
      + '        style="width:100%;box-sizing:border-box;padding:12px;font-size:16px;border:1.5px solid #E3EAF1;border-radius:10px;">'
      + '      <div style="display:flex;justify-content:space-between;align-items:baseline;margin:14px 0 6px;">'
      + '        <span style="font-size:11px;font-weight:800;letter-spacing:.5px;color:#64748B;text-transform:uppercase;">Signature</span>'
      + '        <button id="kt-agree-clear" type="button" style="background:none;border:none;color:#159FB4;font-size:12px;font-weight:700;cursor:pointer;">Clear</button>'
      + '      </div>'
      + '      <canvas id="kt-agree-pad" style="width:100%;height:170px;border:1.5px dashed #CBD5E1;border-radius:12px;background:#FCFDFE;touch-action:none;display:block;"></canvas>'
      + '      <div style="font-size:11.5px;color:#94A3B8;margin-top:6px;">Sign with your finger or mouse.</div>'
      + '      <div id="kt-agree-date" style="font-size:12.5px;color:#475569;margin-top:10px;font-weight:700;"></div>'
      + '      <label style="display:flex;gap:9px;align-items:flex-start;margin-top:12px;font-size:13.5px;color:#0F172A;line-height:1.45;">'
      + '        <input id="kt-agree-check" type="checkbox" style="width:19px;height:19px;flex:0 0 auto;margin-top:1px;accent-color:#159FB4;">'
      + '        <span>I have read and agree to the Privacy Policy &amp; Non-Disclosure Agreement.</span>'
      + '      </label>'
      + '    </div>'
      + '    <div id="kt-agree-err" style="color:#B91C1C;font-size:13px;min-height:17px;margin:8px 2px;"></div>'
      + '  </div>'
      + '</div>'
      + '<div style="flex:0 0 auto;padding:12px 18px calc(env(safe-area-inset-bottom,0px) + 14px);background:#fff;border-top:1px solid #E7EDF3;">'
      + '  <button id="kt-agree-submit" disabled style="width:100%;border:none;border-radius:13px;padding:15px;font-size:16px;font-weight:800;'
      + '    color:#fff;background:#159FB4;opacity:.5;cursor:not-allowed;">Agree &amp; continue</button>'
      + '  <button id="kt-agree-out" type="button" style="width:100%;background:none;border:none;color:#94A3B8;font-size:12.5px;font-weight:700;padding:10px;cursor:pointer;">Sign out instead</button>'
      + '</div>';
    document.body.appendChild(ov);

    ov.querySelector('#kt-agree-text').innerHTML = info.body_html || '';
    var now = new Date();
    ov.querySelector('#kt-agree-date').textContent = 'Date: ' + now.toLocaleDateString([], { year: 'numeric', month: 'long', day: 'numeric' })
      + ' · ' + now.toLocaleTimeString([], { hour: 'numeric', minute: '2-digit' });

    // ── Gate: they must reach the end of the text ──
    var scroller = ov.querySelector('#kt-agree-scroll');
    var form = ov.querySelector('#kt-agree-form');
    var hint = ov.querySelector('#kt-agree-hint');
    var readEnd = false;
    function checkScroll() {
      if (readEnd) return;
      var textEl = ov.querySelector('#kt-agree-text');
      var bottomReached = (textEl.getBoundingClientRect().bottom - scroller.getBoundingClientRect().bottom) < 40;
      if (bottomReached) {
        readEnd = true;
        form.style.opacity = '1';
        form.style.pointerEvents = 'auto';
        hint.style.display = 'none';
        paint();
      }
    }
    scroller.addEventListener('scroll', checkScroll);
    setTimeout(checkScroll, 400);   // short agreements may already be fully visible

    // ── Signature pad ──
    var canvas = ov.querySelector('#kt-agree-pad');
    var ctx = canvas.getContext('2d');
    var drawn = false, drawing = false;
    function sizeCanvas() {
      var ratio = window.devicePixelRatio || 1;
      var r = canvas.getBoundingClientRect();
      var data = drawn ? canvas.toDataURL() : null;
      canvas.width = Math.round(r.width * ratio);
      canvas.height = Math.round(r.height * ratio);
      ctx.setTransform(ratio, 0, 0, ratio, 0, 0);
      ctx.lineWidth = 2.2; ctx.lineCap = 'round'; ctx.lineJoin = 'round'; ctx.strokeStyle = '#0F172A';
      if (data) { var img = new Image(); img.onload = function () { ctx.drawImage(img, 0, 0, r.width, r.height); }; img.src = data; }
    }
    sizeCanvas();
    window.addEventListener('resize', sizeCanvas);

    function pos(e) {
      var r = canvas.getBoundingClientRect();
      var p = e.touches ? e.touches[0] : e;
      return { x: p.clientX - r.left, y: p.clientY - r.top };
    }
    function start(e) { e.preventDefault(); drawing = true; var p = pos(e); ctx.beginPath(); ctx.moveTo(p.x, p.y); }
    function move(e) {
      if (!drawing) return;
      e.preventDefault();
      var p = pos(e);
      ctx.lineTo(p.x, p.y); ctx.stroke();
      if (!drawn) { drawn = true; paint(); }
    }
    function end() { drawing = false; }
    canvas.addEventListener('pointerdown', start);
    canvas.addEventListener('pointermove', move);
    window.addEventListener('pointerup', end);

    ov.querySelector('#kt-agree-clear').addEventListener('click', function () {
      ctx.clearRect(0, 0, canvas.width, canvas.height);
      drawn = false; paint();
    });

    // ── Submit gating ──
    var nameIn = ov.querySelector('#kt-agree-name');
    var check = ov.querySelector('#kt-agree-check');
    var submit = ov.querySelector('#kt-agree-submit');
    var err = ov.querySelector('#kt-agree-err');
    function ready() { return readEnd && drawn && check.checked && nameIn.value.trim().length >= 2; }
    function paint() {
      var ok = ready();
      submit.disabled = !ok;
      submit.style.opacity = ok ? '1' : '.5';
      submit.style.cursor = ok ? 'pointer' : 'not-allowed';
    }
    nameIn.addEventListener('input', paint);
    check.addEventListener('change', paint);
    paint();

    submit.addEventListener('click', function () {
      if (!ready()) return;
      submit.disabled = true; submit.textContent = 'Filing your signature…'; err.textContent = '';
      api('POST', '/auth/agreement/sign', {
        full_name: nameIn.value.trim(),
        signature: canvas.toDataURL('image/png'),
        agreed: true,
      }).then(function () {
        ov.remove();
        if (KT.toast) KT.toast('✅', 'Thank you', 'A copy has been emailed to you for your records.', '#16A34A');
      }).catch(function (e) {
        submit.disabled = false; submit.textContent = 'Agree & continue';
        err.textContent = e.message || 'Could not save your signature — please try again.';
      });
    });

    ov.querySelector('#kt-agree-out').addEventListener('click', function () {
      try { if (KT.Auth && KT.Auth.clear) KT.Auth.clear(); } catch (e) {}
      try { sessionStorage.clear(); localStorage.removeItem('kt_token'); localStorage.removeItem('kt_user'); } catch (e) {}
      location.href = '/index.html';
    });
  }

  KT.agreement = { show: show, check: check };

  function check() {
    if (!tok()) return;
    if (!/dashboard\.html/i.test(location.pathname)) return;
    api('GET', '/auth/agreement')
      .then(function (info) { if (info && info.required) show(info); })
      .catch(function () { /* never block the app on a failed check */ });
  }

  // After the app has booted (and after any biometric/PIN unlock has settled).
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', function () { setTimeout(check, 1800); });
  else setTimeout(check, 1800);
})(window);
