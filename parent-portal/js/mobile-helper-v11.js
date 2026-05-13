/* ═══════════════════════════════════════════════════════════════════
   KIDDIETRAC v11 — Mobile helper
   - Tags <body> with data-role and data-device so CSS can target precisely
   - Wires up PWA "Add to home screen" prompt
   - Adds pull-to-refresh hint on parent screens
   - Manages safe-area insets on iOS notched devices
   ═══════════════════════════════════════════════════════════════════ */
(function (window) {
  'use strict';

  function detectDevice() {
    const w = window.innerWidth;
    if (w <= 600) return 'phone';
    if (w <= 1024) return 'tablet';
    return 'desktop';
  }

  function readUser() {
    try {
      return JSON.parse(sessionStorage.getItem('kt_user') || localStorage.getItem('kt_user') || '{}');
    } catch (e) {
      return {};
    }
  }

  function tagBody() {
    const body = document.body;
    if (!body) return;
    const user = readUser();
    const role = user.primary_role || (user.roles && user.roles[0]) || 'guest';
    body.setAttribute('data-role', role);
    body.setAttribute('data-device', detectDevice());
    body.setAttribute('data-theme', 'auto');
  }

  function setupResize() {
    let resizeTimer;
    window.addEventListener('resize', () => {
      clearTimeout(resizeTimer);
      resizeTimer = setTimeout(() => {
        document.body.setAttribute('data-device', detectDevice());
      }, 150);
    });
  }

  // ─── PWA install prompt ──────────────────────────────────────
  let deferredInstallPrompt = null;

  function setupPWA() {
    window.addEventListener('beforeinstallprompt', (e) => {
      e.preventDefault();
      deferredInstallPrompt = e;
      // Show install banner only on phones for parent users
      const user = readUser();
      if (detectDevice() === 'phone' && user.primary_role === 'guardian') {
        showInstallBanner();
      }
    });

    window.addEventListener('appinstalled', () => {
      hideInstallBanner();
      deferredInstallPrompt = null;
    });
  }

  function showInstallBanner() {
    if (document.getElementById('kt-install-banner')) return;
    if (sessionStorage.getItem('kt_install_dismissed')) return;

    const banner = document.createElement('div');
    banner.id = 'kt-install-banner';
    banner.style.cssText = `
      position: fixed; bottom: 64px; left: 12px; right: 12px;
      background: white; border-radius: 12px;
      box-shadow: 0 8px 24px rgba(0,0,0,0.15);
      padding: 14px 16px;
      display: flex; align-items: center; gap: 12px;
      z-index: 200;
      border: 1px solid rgba(31,96,128,0.15);
    `;
    banner.innerHTML = `
      <div style="font-size: 32px;">📱</div>
      <div style="flex: 1; font-size: 14px;">
        <div style="font-weight: 700;">Add Kiddietrac to your home screen</div>
        <div style="color: #6B7280; font-size: 12px;">Faster access · feels like a native app</div>
      </div>
      <button id="kt-install-yes" style="background: #1F6080; color: white; border: none; padding: 8px 14px; border-radius: 8px; font-weight: 700; cursor: pointer;">Add</button>
      <button id="kt-install-no" style="background: transparent; color: #6B7280; border: none; padding: 4px 8px; cursor: pointer; font-size: 18px;">×</button>
    `;
    document.body.appendChild(banner);

    document.getElementById('kt-install-yes').addEventListener('click', async () => {
      if (!deferredInstallPrompt) return hideInstallBanner();
      deferredInstallPrompt.prompt();
      const choice = await deferredInstallPrompt.userChoice;
      hideInstallBanner();
      deferredInstallPrompt = null;
    });

    document.getElementById('kt-install-no').addEventListener('click', () => {
      sessionStorage.setItem('kt_install_dismissed', '1');
      hideInstallBanner();
    });
  }

  function hideInstallBanner() {
    const el = document.getElementById('kt-install-banner');
    if (el) el.remove();
  }

  // ─── iOS-only fallback PWA install instructions ──────────────
  function maybeShowIOSInstructions() {
    const ua = navigator.userAgent;
    const isIOS = /iPhone|iPad|iPod/.test(ua) && !window.MSStream;
    const isStandalone = window.matchMedia('(display-mode: standalone)').matches || window.navigator.standalone;
    if (!isIOS || isStandalone) return;

    const user = readUser();
    if (user.primary_role !== 'guardian') return;
    if (sessionStorage.getItem('kt_ios_install_dismissed')) return;
    if (detectDevice() !== 'phone') return;

    // Show after a small delay so user sees the app first
    setTimeout(() => {
      if (document.getElementById('kt-install-banner')) return;
      const banner = document.createElement('div');
      banner.id = 'kt-install-banner';
      banner.style.cssText = `
        position: fixed; bottom: 64px; left: 12px; right: 12px;
        background: white; border-radius: 12px;
        box-shadow: 0 8px 24px rgba(0,0,0,0.15);
        padding: 14px 16px;
        z-index: 200;
        font-size: 13px;
        border: 1px solid rgba(31,96,128,0.15);
      `;
      banner.innerHTML = `
        <button id="kt-install-no" style="position: absolute; top: 8px; right: 8px; background: transparent; border: none; font-size: 20px; cursor: pointer; color: #6B7280;">×</button>
        <div style="font-weight: 700; margin-bottom: 4px;">📱 Install Kiddietrac on your iPhone</div>
        <div style="color: #6B7280;">Tap the Share icon in Safari, then choose <strong>"Add to Home Screen"</strong>.</div>
      `;
      document.body.appendChild(banner);
      document.getElementById('kt-install-no').addEventListener('click', () => {
        sessionStorage.setItem('kt_ios_install_dismissed', '1');
        hideInstallBanner();
      });
    }, 4000);
  }

  // ─── Wire up ───────────────────────────────────────────────────
  function init() {
    tagBody();
    setupResize();
    setupPWA();
    maybeShowIOSInstructions();
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }

  // Re-tag body when user changes (after login)
  window.addEventListener('storage', (e) => {
    if (e.key === 'kt_user' || e.key === 'kt_token') tagBody();
  });
})(window);
