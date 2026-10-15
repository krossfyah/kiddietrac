/* ═══════════════════════════════════════════════════════════════════
   KIDDIETRAC v9 — Branding Loader
   Fetches agency branding and injects CSS variables on every page load.
   Runs before any other screen renders, including login.

   WHITE-LABEL LOGOS (2026-10-15). The KiddieTrac logo in the chrome — the top bar
   for every role, the post-sign-in splash, the unlock screen, the login page — is
   replaced by the agency's logo ONLY when:
     1. the agency is on the white-label licence (powered_by_visible = 0, set by a
        platform admin; the same flag emails, invoices and forms already use), and
     2. the /branding answer is for the agency this session is actually in.
   (2) matters because /branding with no hint falls back to the FIRST agency: the
   login page's fetch would otherwise put one agency's logo on every user's screen.
   Every logo the loader swaps remembers its KiddieTrac src and gets it back when
   the agency changes to one that is not white-label.
   ═══════════════════════════════════════════════════════════════════ */
(function (window) {
  'use strict';

  const STORAGE_KEY = 'kt_branding';
  const TENANT_KEY = 'kt_tenant';            // ?tenant= from a white-label app build
  const CACHE_TTL_MS = 5 * 60 * 1000; // 5 min
  const LOGO_SEL = '.brand-logo, [data-brand-logo], #brand-logo';

  function apiBase() {
    return (window.KT && window.KT.Api && window.KT.Api.base) || 'https://api.kiddietrac.com/api/v1';
  }
  // Uploaded logos are stored as /storage/branding/…, served by the API host.
  function absUrl(u) {
    if (!u) return '';
    if (/^(https?:|data:)/i.test(u)) return u;
    return apiBase().replace(/\/api\/v1\/?$/, '') + (u.charAt(0) === '/' ? '' : '/') + u;
  }

  function apply(branding) {
    if (!branding) return;
    const root = document.documentElement;
    if (branding.primary_color) root.style.setProperty('--brand-blue', branding.primary_color);
    if (branding.accent_color) root.style.setProperty('--brand-green', branding.accent_color);

    // Document title
    if (branding.product_name) {
      document.title = branding.product_name;
    }

    // Logos are NOT swapped here — see applyLogos(), which knows whose branding this is.

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
  //          -> URL ?tenant= / ?slug= (remembered: a white-label app opens with it, then
  //             the sign-in redirect drops it)
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
    var qs = new URLSearchParams(window.location.search);
    var slug = qs.get('tenant') || qs.get('slug');
    try {
      if (slug && /^[a-z0-9-]{1,80}$/i.test(slug)) localStorage.setItem(TENANT_KEY, slug);
      if (!slug) slug = localStorage.getItem(TENANT_KEY);
    } catch (e) {}
    if (slug) hint.slug = slug;
    return hint;
  }

  /* The agency logo to show in the chrome, or '' for KiddieTrac. `entry` is a
     {branding, agency_id, agency_slug} record (fresh or cached). */
  function whiteLabelLogo(entry, hint) {
    if (!entry || !entry.branding) return '';
    var b = entry.branding;
    if (b.powered_by_visible !== false || !b.logo_url) return '';
    var mine = (hint.agency_id && String(entry.agency_id) === String(hint.agency_id))
      || (!hint.agency_id && hint.slug && entry.agency_slug === hint.slug);
    return mine ? absUrl(b.logo_url) : '';
  }

  function applyLogos(logo, name) {
    document.documentElement.classList.toggle('kt-wl', !!logo);
    document.querySelectorAll(LOGO_SEL).forEach(function (el) {
      if (el.tagName !== 'IMG') return;
      if (!el.hasAttribute('data-kt-default-src')) {
        el.setAttribute('data-kt-default-src', el.getAttribute('src') || '');
        el.setAttribute('data-kt-default-alt', el.getAttribute('alt') || '');
      }
      if (logo) {
        if (el.getAttribute('src') !== logo) {
          el.onerror = function () { el.onerror = null; restore(el); };
          el.src = logo;
        }
        el.alt = name || '';
        el.setAttribute('data-kt-wl', '1');
        el.style.display = '';
      } else if (el.hasAttribute('data-kt-wl')) {
        restore(el);
      }
    });
  }
  function restore(el) {
    el.src = el.getAttribute('data-kt-default-src') || el.src;
    el.alt = el.getAttribute('data-kt-default-alt') || '';
    el.removeAttribute('data-kt-wl');
  }

  function readCache() {
    try { return JSON.parse(localStorage.getItem(STORAGE_KEY) || 'null'); } catch (e) { return null; }
  }

  async function load() {
    const hint = activeAgencyHint();
    // Apply cached first for instant visual
    try {
      const parsed = readCache();
      if (parsed) {
        if (parsed.expires > Date.now()) {
          apply(parsed.branding);
        }
        // The logo is checked against the session's own agency, so a stale entry is
        // still safe to show while the fresh one loads.
        applyLogos(whiteLabelLogo(parsed, hint), parsed.branding && parsed.branding.product_name);
      }
    } catch (e) { /* ignore */ }

    // Fetch fresh — include agency hint so multi-tenant + multi-agency users see the
    // right branding for the agency they're currently logged into
    try {
      const qs = new URLSearchParams(hint).toString();
      const res = await fetch(apiBase() + '/branding' + (qs ? '?' + qs : ''));
      if (!res.ok) return;
      const data = await res.json();
      if (data.branding) {
        apply(data.branding);
        applyLogos(whiteLabelLogo(data, hint), data.branding.product_name);
        try {
          localStorage.setItem(STORAGE_KEY, JSON.stringify({
            branding: data.branding,
            agency_id: data.agency_id,
            agency_slug: data.agency_slug,
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
  /* For chrome built after load (the unlock screen): the white-label logo for this
     session, from cache, or '' for KiddieTrac. `agencyId` overrides the session
     hint (the unlock screen knows the enrolled agency before the session exists). */
  window.KT.brandLogo = function (agencyId) {
    var hint = agencyId ? { agency_id: String(agencyId) } : activeAgencyHint();
    return whiteLabelLogo(readCache(), hint);
  };

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
