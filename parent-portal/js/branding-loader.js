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

    // v22p29: "Powered by Kiddietrac" footer is hidden when tenant pays for white-label
    if (typeof branding.powered_by_visible !== 'undefined') {
      var poweredBy = document.querySelectorAll('.kt-powered-by, [data-powered-by]');
      poweredBy.forEach(function (el) {
        el.style.display = branding.powered_by_visible ? '' : 'none';
      });
    }
  }

  // v22p29: which agency should the loader brand for?
  // Priority: sessionStorage.kt_active_agency_id (set by agency-switcher after multi-agency users pick)
  //          -> first agency on the user record (if logged in)
  //          -> URL ?slug= or hostname-based fallback
  function activeAgencyHint() {
    var hint = {};
    try {
      var aid = sessionStorage.getItem('kt_active_agency_id');
      if (aid) { hint.agency_id = aid; return hint; }
    } catch (e) {}
    try {
      var u = JSON.parse(sessionStorage.getItem('kt_user') || 'null');
      if (u && u.agency_id) { hint.agency_id = String(u.agency_id); return hint; }
    } catch (e) {}
    // URL slug override (e.g. ?tenant=acme on the public login page)
    var qs = new URLSearchParams(window.location.search);
    var slug = qs.get('tenant') || qs.get('slug');
    if (slug) hint.slug = slug;
    return hint;
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

    // Fetch fresh — include agency hint so multi-tenant + multi-agency users see the
    // right branding for the agency they're currently logged into
    try {
      const apiBase = (window.KT && window.KT.Api && window.KT.Api.base) || 'https://api.kiddietrac.com/api/v1';
      const hint = activeAgencyHint();
      const qs = new URLSearchParams(hint).toString();
      const res = await fetch(apiBase + '/branding' + (qs ? '?' + qs : ''));
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

  // Re-fire on agency switch (some flows reload the page; others dispatch the event)
  window.addEventListener('kt:agency-switched', function () {
    // Drop the cached branding for the previous agency so the new one applies cleanly
    try { localStorage.removeItem(STORAGE_KEY); } catch (e) {}
    load();
  });

  // Run on page load
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', load);
  } else {
    load();
  }
})(window);
