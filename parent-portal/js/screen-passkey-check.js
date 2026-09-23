/* ═══════════════════════════════════════════════════════════════════
   KiddieTrac — passkey device check (TEMPORARY, 2026-09-22)

   DELETE THIS FILE, its <script> tag, and the "Passkey check (temp)" nav entry in
   app-v2-shell.js once the iPhone and Android readings are in.

   Why it exists: the one unknown in the passkey scope is whether WebAuthn works inside
   the APK's web view. The APK loads the real origin (capacitor.config.ts
   server.url = https://app.kiddietrac.com), so the ceremony SHOULD work — but a web view
   can report a platform authenticator and still refuse, and that cannot be read from a
   spec. The probe page answers it with a real registration.

   The app has no address bar, so /passkey-check.html was unreachable from inside the app
   — the only place the answer matters. This is the way in.

   It deliberately does NOT iframe the probe: a cross-document iframe changes the WebAuthn
   origin rules, so an iframe could fail where the top-level page succeeds and the reading
   would be worthless. It navigates the web view to the real page instead.
   ═══════════════════════════════════════════════════════════════════ */
(function (window) {
  'use strict';
  var KT = window.KT;
  if (!KT || !KT.Shell || !KT.Shell.registerScreen) { return; }

  function render(main) {
    var inApp = false;
    try { inApp = !!(window.Capacitor && window.Capacitor.isNativePlatform && window.Capacitor.isNativePlatform()); } catch (e) {}

    main.innerHTML = '<div style="padding:14px 24px;max-width:640px;">'
      + '<div class="kt-page-hero"><h2>🔑 Passkey check</h2>'
      + '<p>A one-off device test. Nothing is saved and no account is touched.</p></div>'
      + '<div class="kt-card">'
      +   '<div style="font-size:13.5px;color:#334155;line-height:1.6;">'
      +     'You are currently in <b>' + (inApp ? 'the KiddieTrac app' : 'a browser') + '</b>.'
      +     (inApp
            ? ' That is exactly where the reading is needed.'
            : ' The browser answer is already known — please open this from <b>inside the app</b> instead.')
      +   '</div>'
      +   '<div style="margin-top:14px;">'
      +     '<button id="pk-go" class="kt-btn kt-btn-primary">Open the check</button>'
      +   '</div>'
      +   '<div style="margin-top:12px;font-size:12px;color:#64748B;line-height:1.55;">'
      +     'Your phone will ask for Face&nbsp;ID, a fingerprint or a PIN. Complete it, then send '
      +     'Claude the black box at the bottom. To come back afterwards, use the app’s back gesture.'
      +   '</div>'
      + '</div></div>';

    var b = main.querySelector('#pk-go');
    if (b) {
      b.addEventListener('click', function () {
        /* A full navigation, not an iframe — see the note at the top of this file. */
        window.location.href = '/passkey-check.html';
      });
    }
  }

  KT.Shell.registerScreen('platform_admin:passkey-check', render);
})(window);
