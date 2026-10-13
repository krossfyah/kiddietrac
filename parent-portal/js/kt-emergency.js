/* KT EMERGENCY (2026-10-13, Anthony: "for messenger to admin and to educators/parents and
   other staff add a button to flag as emergency otherwise treat the messenger as normal.
   Emergency will continuously ring/vibrate").

   The 🚨 toggle beside Send in every composer (Message Centre, colleague threads, the
   parent app). Off by default; turning it on asks once, because an emergency rings and
   vibrates on the other side for up to two minutes. The flag goes with that ONE message
   and the toggle resets after a send.

   The server decides what an emergency does (App\Support\EmergencyMessage); the ringing on
   arrival is kt-urgent-alert.js (in the app/browser) and KtMessagingService (on a phone).

   Public:
     KT.emergency.button({ wide })  → a toggle <button>; put it next to Send
     KT.emergency.isOn(btn) / reset(btn)
     KT.emergency.tagHtml() / tagEl() → the red "🚨 EMERGENCY" label for a bubble */
(function () {
  'use strict';
  if (window.__ktEmergencyLoaded) return;
  window.__ktEmergencyLoaded = true;
  var KT = window.KT = window.KT || {};

  function css() {
    if (document.getElementById('kt-em-css')) return;
    var s = document.createElement('style');
    s.id = 'kt-em-css';
    s.textContent = [
      '.kt-em-btn{flex:0 0 auto;display:inline-flex;align-items:center;justify-content:center;gap:5px;height:34px;min-width:34px;padding:0 8px;',
      '  border:1px solid #FECACA;border-radius:10px;background:#FFF5F5;color:#B91C1C;font:800 12.5px/1 inherit;font-family:inherit;cursor:pointer;',
      '  transition:background .15s,color .15s,box-shadow .15s;-webkit-tap-highlight-color:transparent;}',
      '.kt-em-btn:hover{background:#FEE2E2;}',
      /* Keep the composer on ONE line with the toggle in it: a global phone rule lets
         .kt-thread-compose wrap, which dropped the mic to a second row and squeezed this
         button to 21px. The text box takes whatever room is left instead. */
      '.kt-em-btn{min-width:34px !important;width:auto;}',
      '.kt-thread-compose:has(> .kt-em-btn),form:has(> .kt-em-btn){flex-wrap:nowrap !important;}',
      '.kt-thread-compose:has(> .kt-em-btn) > input[type="text"],.kt-thread-compose:has(> .kt-em-btn) > textarea,form:has(> .kt-em-btn) > input[type="text"]{flex:1 1 0 !important;min-width:0 !important;}',
      '@media (max-width:420px){.kt-thread-compose:has(> .kt-em-btn){gap:4px !important;}.kt-thread-compose:has(> .kt-em-btn) > button:not(.kt-em-btn){width:36px;}}',
      '.kt-em-btn[aria-pressed="true"]{background:#DC2626;border-color:#DC2626;color:#fff;box-shadow:0 0 0 3px rgba(220,38,38,.25);animation:kt-em-pulse 1.2s ease-in-out infinite;}',
      '.kt-em-btn .kt-em-ic{font-size:15px;line-height:1;filter:saturate(1.2);}',
      '@keyframes kt-em-pulse{0%,100%{box-shadow:0 0 0 3px rgba(220,38,38,.25);}50%{box-shadow:0 0 0 6px rgba(220,38,38,.12);}}',
      '@media (prefers-reduced-motion:reduce){.kt-em-btn[aria-pressed="true"]{animation:none;}}',
      '.kt-em-armed textarea,.kt-em-armed input[type="text"],.kt-em-armed input:not([type]){border-color:#DC2626 !important;box-shadow:0 0 0 2px rgba(220,38,38,.18) !important;}',
      '.kt-em-tag{display:inline-flex;align-items:center;gap:4px;background:#DC2626;color:#fff;font:800 10.5px/1.6 inherit;font-family:inherit;',
      '  letter-spacing:.06em;text-transform:uppercase;border-radius:6px;padding:1px 7px;margin:0 0 5px;}',
    ].join('\n');
    document.head.appendChild(s);
  }

  function set(btn, on) {
    btn.setAttribute('aria-pressed', on ? 'true' : 'false');
    btn.title = on ? 'This message will be sent as an EMERGENCY — tap to cancel' : 'Mark this message as an EMERGENCY';
    var scope = btn.parentElement;
    if (scope) scope.classList.toggle('kt-em-armed', !!on);
  }

  function ask() {
    var opts = {
      title: '🚨 Send as an emergency?',
      description: 'Their phone will ring and vibrate continuously until they open it (up to 2 minutes). Use this only for real emergencies — everyone who receives it is told it is urgent.',
      okLabel: 'Yes, emergency',
      cancelLabel: 'Cancel',
    };
    try {
      if (KT.confirm) return Promise.resolve(KT.confirm(opts)).then(function (r) { return !!r; });
    } catch (e) {}
    return Promise.resolve(window.confirm(opts.title + '\n\n' + opts.description));
  }

  function button(o) {
    css();
    o = o || {};
    var b = document.createElement('button');
    b.type = 'button';
    b.className = 'kt-em-btn';
    b.setAttribute('aria-label', 'Emergency');
    b.innerHTML = '<span class="kt-em-ic" aria-hidden="true">🚨</span>' + (o.wide ? '<span>Emergency</span>' : '');
    set(b, false);
    b.addEventListener('click', function (e) {
      e.preventDefault(); e.stopPropagation();
      if (isOn(b)) { set(b, false); return; }
      ask().then(function (ok) { if (ok) set(b, true); });
    });
    return b;
  }
  function isOn(b) { return !!(b && b.getAttribute('aria-pressed') === 'true'); }
  function reset(b) { if (b) set(b, false); }
  function tagHtml() { css(); return '<div><span class="kt-em-tag">🚨 Emergency</span></div>'; }
  function tagEl() { css(); var d = document.createElement('div'); d.innerHTML = '<span class="kt-em-tag">🚨 Emergency</span>'; return d; }

  KT.emergency = { button: button, isOn: isOn, reset: reset, tagHtml: tagHtml, tagEl: tagEl };
})();
