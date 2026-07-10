/* ═══════════════════════════════════════════════════════════════════
   KiddieTrac — in-app update check (2026-07-09).
   Runs ONLY inside the Android APK (Capacitor bridge present) — a no-op in a
   normal browser. Compares the installed native build to /version.json and, if a
   newer build is published, shows a slide-in "Update available" banner linking to
   the new APK. Web/feature changes need no update (the app loads the live site);
   this covers native-shell releases.

   To publish a release: bump versionCode + versionName in android/app/build.gradle,
   rebuild the APK, upload it to /dl/, then bump "versionCode" (+ apkUrl/notes) in
   /version.json. Every app will then prompt to update.
   ═══════════════════════════════════════════════════════════════════ */
(function () {
  'use strict';
  if (window.__ktAppUpdate) return; window.__ktAppUpdate = true;

  function cap() { return window.Capacitor; }
  function isNative() { var c = cap(); return !!(c && (c.isNativePlatform ? c.isNativePlatform() : c.isNative)); }
  function platform() { var c = cap(); try { return c && c.getPlatform ? c.getPlatform() : 'android'; } catch (e) { return 'android'; } }

  async function appInfo() {
    try {
      var c = cap(); if (!c) return null;
      var App = (c.Plugins && c.Plugins.App) || window.CapacitorApp;
      if (App && App.getInfo) return await App.getInfo();
    } catch (e) {}
    return null;
  }

  function openUrl(url) {
    try { window.open(url, '_system'); } catch (e) { try { window.location.href = url; } catch (e2) {} }
  }

  function banner(meta) {
    if (document.getElementById('kt-appupdate')) return;
    var bar = document.createElement('div');
    bar.id = 'kt-appupdate';
    bar.style.cssText = 'position:fixed;left:12px;right:12px;bottom:calc(env(safe-area-inset-bottom,0px) + 12px);z-index:13000;'
      + 'background:#0E7C90;color:#fff;border-radius:14px;padding:13px 15px;display:flex;align-items:center;gap:12px;'
      + 'box-shadow:0 14px 34px -12px rgba(0,0,0,.5);font-family:system-ui,-apple-system,sans-serif;animation:kt-appup-in .35s ease both;';
    var txt = document.createElement('div'); txt.style.cssText = 'flex:1;min-width:0;';
    txt.innerHTML = '<div style="font-weight:800;font-size:14px;">Update available</div>'
      + '<div style="font-size:12px;opacity:.9;margin-top:1px;">' + (meta.notes ? String(meta.notes).replace(/[<>]/g, '') : 'A newer version of the app is ready.') + '</div>';
    var btn = document.createElement('button'); btn.textContent = 'Update';
    btn.style.cssText = 'flex-shrink:0;background:#fff;color:#0C6070;border:none;border-radius:9px;padding:9px 16px;font-weight:800;font-size:13px;cursor:pointer;';
    btn.addEventListener('click', function () { openUrl(meta.apkUrl); });
    var x = document.createElement('button'); x.textContent = '✕';
    x.style.cssText = 'flex-shrink:0;background:transparent;border:none;color:rgba(255,255,255,.85);font-size:16px;cursor:pointer;';
    x.addEventListener('click', function () { bar.remove(); try { if (!meta.mandatory) sessionStorage.setItem('kt_update_dismissed', String(meta.versionCode)); } catch (e) {} });
    bar.appendChild(txt); bar.appendChild(btn); if (!meta.mandatory) bar.appendChild(x);
    var st = document.createElement('style'); st.textContent = '@keyframes kt-appup-in{from{opacity:0;transform:translateY(20px);}to{opacity:1;transform:none;}}';
    document.head.appendChild(st); document.body.appendChild(bar);
  }

  async function check() {
    if (!isNative()) return;                       // browser → nothing to do
    var info = await appInfo(); if (!info) return;
    var installed = parseInt(info.build, 10) || 0; // Capacitor 'build' == Android versionCode
    var r; try { r = await fetch('/version.json?t=' + Date.now()); } catch (e) { return; }
    if (!r || !r.ok) return;
    var j; try { j = await r.json(); } catch (e) { return; }
    var meta = j && j[platform()]; if (!meta || !meta.apkUrl) return;
    var latest = parseInt(meta.versionCode, 10) || 0;
    if (latest <= installed) return;               // up to date
    var dismissed = 0; try { dismissed = parseInt(sessionStorage.getItem('kt_update_dismissed'), 10) || 0; } catch (e) {}
    if (dismissed === latest && !meta.mandatory) return;
    banner(meta);
  }

  setTimeout(check, 4000);
})();
