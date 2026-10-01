/* ═══════════════════════════════════════════════════════════════════
   KiddieTrac — "turn on alerts" nudge for the browser (2026-10-01).

   The audit on 2026-10-01 found five people no notification could reach,
   including two educators who missed a Safe Arrival alert. Three were on an
   iPhone in Safari, where web push only exists once the portal is added to
   the Home Screen; the others had simply never been asked. The only switch
   was a button inside chat.

   So, once per session and at most once a week if dismissed:
     • iPhone/iPad in Safari (not installed) → how to add to the Home Screen.
     • any browser that can push but has not been asked → a "Turn on" button
       (the permission prompt needs a tap, so it is never raised by itself).
   Nothing in the Android/iOS app (kt-native-push.js owns that), nothing once
   notifications are on or blocked.
   ═══════════════════════════════════════════════════════════════════ */
(function () {
  'use strict';
  if (window.__ktPushNudge) return; window.__ktPushNudge = true;

  var SNOOZE = 'kt_push_nudge_until';
  var ID = 'kt-push-nudge';

  function token() { try { return sessionStorage.getItem('kt_token') || localStorage.getItem('kt_token'); } catch (e) { return null; } }
  function isNative() {
    try { return !!(window.Capacitor && Capacitor.isNativePlatform && Capacitor.isNativePlatform()); } catch (e) { return false; }
  }
  function isIOS() {
    var ua = navigator.userAgent || '';
    return /iPhone|iPad|iPod/.test(ua) || (/Macintosh/.test(ua) && navigator.maxTouchPoints > 1);
  }
  function standalone() {
    try { return navigator.standalone === true || window.matchMedia('(display-mode: standalone)').matches; } catch (e) { return false; }
  }
  function snoozed() {
    try { return Number(localStorage.getItem(SNOOZE) || 0) > Date.now(); } catch (e) { return false; }
  }
  function snooze(days) {
    try { localStorage.setItem(SNOOZE, String(Date.now() + days * 86400000)); } catch (e) {}
  }
  function esc(s) { return String(s).replace(/[&<>"]/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]; }); }

  function show(text, action) {
    if (document.getElementById(ID)) return;
    var el = document.createElement('div');
    el.id = ID;
    el.setAttribute('role', 'status');
    el.innerHTML =
      '<div class="kt-pn-ic" aria-hidden="true">🔔</div>'
      + '<div class="kt-pn-tx">' + text + '</div>'
      + (action ? '<button type="button" class="kt-pn-go" data-kt-iconized="1">' + esc(action) + '</button>' : '')
      + '<button type="button" class="kt-pn-x" aria-label="Not now" data-kt-iconized="1">✕</button>';
    document.body.appendChild(el);

    el.querySelector('.kt-pn-x').addEventListener('click', function () { snooze(7); el.remove(); });
    var go = el.querySelector('.kt-pn-go');
    if (go) {
      go.addEventListener('click', async function () {
        go.disabled = true; go.textContent = 'Asking…';
        var r = { status: 'error' };
        try { r = await KT.Push.subscribe(true); } catch (e) {}
        if (r.status === 'subscribed') {
          el.querySelector('.kt-pn-tx').innerHTML = '<b>Alerts are on.</b> You will get Safe Arrival alerts and messages on this device.';
          go.remove();
          setTimeout(function () { el.remove(); }, 4000);
        } else {
          // Denied, or the browser refused: do not ask again this week.
          snooze(7); el.remove();
        }
      });
    }
  }

  async function check() {
    if (!token() || isNative() || snoozed()) return;
    if (!document.getElementById('appMain')) return;            // not on the portal shell

    if (isIOS() && !standalone()) {
      /* mobile-helper-v11 shows parents the same Add-to-Home-Screen steps as a plain
         install pitch. This one says why (alerts) and covers staff too, so it replaces
         that banner rather than stacking on top of it. */
      var inst = document.getElementById('kt-install-banner');
      if (inst) { inst.remove(); }
      show('<b>Get alerts on this iPhone.</b> Tap <b>Share</b> <span aria-hidden="true">⬆︎</span>, then <b>Add to Home Screen</b>, and open KiddieTrac from there.');
      return;
    }
    if (!('serviceWorker' in navigator) || !('PushManager' in window) || typeof Notification === 'undefined') return;
    if (Notification.permission !== 'default') return;          // granted = push-client subscribes; denied = respect it
    var st = 'unknown';
    try { st = (window.KT && KT.Push && KT.Push.status) ? await KT.Push.status() : 'unknown'; } catch (e) {}
    if (st !== 'unsubscribed') return;
    show('<b>Turn on alerts</b> so you never miss a Safe Arrival alert or a message.', 'Turn on');
  }

  var css = document.createElement('style');
  css.textContent =
    '#' + ID + '{position:fixed;right:16px;bottom:16px;z-index:9000;display:flex;align-items:center;gap:10px;max-width:420px;'
    + 'padding:10px 10px 10px 12px;background:#fff;border:1px solid #CBD5E1;border-radius:12px;box-shadow:0 8px 24px rgba(15,23,42,.14);'
    + 'font-size:13px;line-height:1.4;color:#0F172A;}'
    + '#' + ID + ' .kt-pn-ic{font-size:18px;flex:0 0 auto;}'
    + '#' + ID + ' .kt-pn-tx{flex:1 1 auto;min-width:0;}'
    + '#' + ID + ' .kt-pn-go{flex:0 0 auto;height:30px;padding:0 12px;border:0;border-radius:8px;background:#1F6080;color:#fff;font-weight:800;font-size:12.5px;cursor:pointer;}'
    + '#' + ID + ' .kt-pn-x{flex:0 0 auto;width:28px;height:28px;border:0;border-radius:8px;background:transparent;color:#64748B;font-size:14px;cursor:pointer;}'
    + '@media (max-width:767px){#' + ID + '{left:12px;right:12px;max-width:none;'
    + 'bottom:calc(var(--kt-safe-bottom, env(safe-area-inset-bottom, 0px)) + 84px);}}';
  document.head.appendChild(css);

  /* Never UNDER something. The onboarding agreement, the biometric lock and the other
     gates sit far above this z-index, so a nudge raised behind one is invisible and
     untappable. If its middle is covered, take it down and try again later. */
  var tries = 0;
  function attempt() {
    if (document.hidden) { return; }
    check().then(function () {
      var el = document.getElementById(ID);
      if (!el) { return; }
      var r = el.getBoundingClientRect();
      var hit = document.elementFromPoint(r.left + r.width / 2, r.top + r.height / 2);
      // The app-install banner (Android/desktop) sits in the same spot: one at a time.
      if ((hit && !el.contains(hit)) || document.getElementById('kt-install-banner')) {
        el.remove();
        if (++tries < 10) { setTimeout(attempt, 30000); }
      }
    });
  }
  // After the screen has painted, never during boot.
  setTimeout(attempt, 6000);
})();
