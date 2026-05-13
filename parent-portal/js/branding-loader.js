/* ═══════════════════════════════════════════════════════════════════
   KIDDIETRAC v9 — Branding Loader
   Fetches agency branding and injects CSS variables on every page load.
   Runs before any other screen renders, including login.
   ═══════════════════════════════════════════════════════════════════ */
(function (window) {
  'use strict';

  const STORAGE_KEY = 'kt_branding';
  const CACHE_TTL_MS = 5 * 60 * 1000; // 5 min

  function apply(branding) {
    if (!branding) return;
    const root = document.documentElement;
    if (branding.primary_color) root.style.setProperty('--brand-blue', branding.primary_color);
    if (branding.accent_color) root.style.setProperty('--brand-green', branding.accent_color);

    // Document title
    if (branding.product_name) {
      document.title = branding.product_name;
    }

    // Logo swap — find any .brand-logo / [data-brand-logo] / image with id 'brand-logo'
    if (branding.logo_url) {
      const logos = document.querySelectorAll('.brand-logo, [data-brand-logo], #brand-logo');
      logos.forEach(el => {
        if (el.tagName === 'IMG') {
          el.src = branding.logo_url;
        } else {
          // Replace text with image
          const img = document.createElement('img');
          img.src = branding.logo_url;
          img.style.maxHeight = '32px';
          img.alt = branding.product_name || 'Logo';
          el.innerHTML = '';
          el.appendChild(img);
        }
      });
    }

    // Product name spans
    const nameEls = document.querySelectorAll('.brand-name, [data-brand-name]');
    nameEls.forEach(el => {
      if (branding.product_name) el.textContent = branding.product_name;
    });

    // Tagline / subtitle
    if (branding.login_subtitle) {
      const subEls = document.querySelectorAll('.login-subtitle, [data-brand-subtitle]');
      subEls.forEach(el => { el.textContent = branding.login_subtitle; });
    }

    // Favicon
    if (branding.favicon_url) {
      let link = document.querySelector("link[rel*='icon']");
      if (!link) {
        link = document.createElement('link');
        link.rel = 'icon';
        document.head.appendChild(link);
      }
      link.href = branding.favicon_url;
    }
  }

  async function load() {
    // Apply cached first for instant visual
    try {
      const cached = localStorage.getItem(STORAGE_KEY);
      if (cached) {
        const parsed = JSON.parse(cached);
        if (parsed.expires > Date.now()) {
          apply(parsed.branding);
        }
      }
    } catch (e) { /* ignore */ }

    // Fetch fresh
    try {
      const apiBase = (window.KT && window.KT.Api && window.KT.Api.base) || 'https://api.kiddietrac.com/api/v1';
      const res = await fetch(apiBase + '/branding');
      if (!res.ok) return;
      const data = await res.json();
      if (data.branding) {
        apply(data.branding);
        try {
          localStorage.setItem(STORAGE_KEY, JSON.stringify({
            branding: data.branding,
            expires: Date.now() + CACHE_TTL_MS,
          }));
        } catch (e) { /* ignore quota */ }
      }
    } catch (e) {
      console.warn('Branding load failed', e);
    }
  }

  // Expose for the admin panel to call after save (instant apply without refetch)
  window.KT = window.KT || {};
  window.KT.applyBranding = apply;
  window.KT.reloadBranding = load;

  // Run on page load
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', load);
  } else {
    load();
  }
})(window);
