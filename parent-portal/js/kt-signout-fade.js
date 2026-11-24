/* SIGN-OUT FADE (2026-10-21) - window.KT_out
   Anthony: "when signing out smoothly fade out of the mobile view back to the login page".
   Every way out of the portal (menu / launcher sign-out, idle or 12-hour timeout, a 401,
   access changed, "use password instead" on the lock, another tab signing out) now:
     1. fades the portal to the brand background (~400 ms; the agency's own splash colour
        for a white-label session, the KiddieTrac navy gradient otherwise, never a logo),
     2. navigates while covered, leaving sessionStorage kt_out_fade = that background,
     3. and the sign-in page (kt-launch-scene.js, arrive()) opens on that same frame and
        fades it away once its own logo is drawn - no white flash, no second logo, and
        the full launch scene does not replay.
   KT_out.cover(cb)  start the fade; cb runs once the portal is fully covered.
   KT_out.go(url, replace)  cover, then navigate (first call wins). */
(function (w, d) {
  'use strict';
  /* Words drawn here run before kt-i18n.js has loaded, so they carry their own
     translations (same wording as the kt-i18n dictionary). 2026-10-22 */
  var KT_LS_T = {
    fr: { 'Smart Childcare Management Platform': 'Plateforme intelligente de gestion des services de garde', '#1 in North America': 'N° 1 en Amérique du Nord', 'Signing out…': 'Déconnexion…' },
    es: { 'Smart Childcare Management Platform': 'Plataforma inteligente de gestión de cuidado infantil', '#1 in North America': 'N.º 1 en Norteamérica', 'Signing out…': 'Cerrando sesión…' },
    hi: { 'Smart Childcare Management Platform': 'स्मार्ट चाइल्डकेयर प्रबंधन प्लेटफ़ॉर्म', '#1 in North America': 'उत्तरी अमेरिका में नंबर 1', 'Signing out…': 'साइन आउट हो रहा है…' }
  };
  function ktLsT(s) {
    try { var t = KT_LS_T[localStorage.getItem('kt_locale')]; return (t && t[s]) || s; } catch (e) { return s; }
  }

  if (w.KT_out) return;
  var root = d.documentElement;
  var reduced = false;
  try { reduced = w.matchMedia('(prefers-reduced-motion: reduce)').matches; } catch (e) {}
  var KT_BG = 'radial-gradient(120% 90% at 20% 15%,#1F6080 0%,#0F2E52 45%,#0A1C3A 100%)';
  function sget(k) { try { return w.sessionStorage.getItem(k); } catch (e) { return null; } }
  function lget(k) { try { return w.localStorage.getItem(k); } catch (e) { return null; } }
  function safeColour(c) { return (typeof c === 'string' && /^#[0-9a-f]{3,8}$/i.test(c.trim())) ? c.trim() : null; }

  /* THE DESTINATION'S COLOURS (2026-10-21). Anthony: "its using a purple background and
     not our navy blue background when signing out - make this right and also with
     whitelabel use the colour scheme chosen". The cover is the first frame of the sign-in
     page this person is going back to: that page carries no agency brand when signed out,
     so it is KiddieTrac's navy and teal - unless the session came through a reseller's own
     app / link (?tenant=, kt_tenant), whose sign-in page wears that brand. Then their
     scheme: the hero gradient, else a dark-enough splash colour, else the gradient's darker
     stop or their primary - anything white text and a spinner read on (3:1), never pale. */
  function wl(id) {
    if (!id || id === 'all') return null;
    try { var e = JSON.parse(lget('kt_brand_a' + id) || 'null'); return e && e.theme && e.theme.white_label ? e.theme : null; } catch (x) { return null; }
  }
  function tenantSlug() {
    try { return new URLSearchParams(w.location.search).get('tenant') || sget('kt_tenant'); } catch (e) { return null; }
  }
  function tenantTheme(slug) {
    if (!slug) return null;
    try {
      var c = JSON.parse(lget('kt_branding') || 'null');
      if (c && c.theme && c.theme.white_label && (slug === c.agency_slug || slug === c.reseller_slug)) return c.theme;
    } catch (e) {}
    return null;
  }
  function lum(c) {
    var m = /^#([0-9a-f]{3}|[0-9a-f]{6})$/i.exec(c || '');
    if (!m) return 1;
    var h = m[1].length === 3 ? m[1].replace(/./g, '$&$&') : m[1], n = parseInt(h, 16);
    var f = function (v) { v /= 255; return v <= 0.03928 ? v / 12.92 : Math.pow((v + 0.055) / 1.055, 2.4); };
    return 0.2126 * f(n >> 16) + 0.7152 * f((n >> 8) & 255) + 0.0722 * f(n & 255);
  }
  function onWhite(c) { return !!c && lum(c) <= 0.30; }          // white on it >= 3:1
  var accNow = '#13B7CC', slugNow = null;
  function brandBg() {
    slugNow = tenantSlug();
    var t = tenantTheme(slugNow);
    // Signed in, the shared cache holds the AGENCY's entry (no reseller slug): the tenant's
    // sign-in page will still wear the brand, so this session's own white-label theme.
    if (!t && slugNow) {
      var u = null;
      try { u = JSON.parse(sget('kt_user') || lget('kt_user') || 'null'); } catch (e) {}
      if (!u && !sget('kt_active_agency_id')) return null;     // nothing left to read (a 401 cleared it): keep what load saw
      t = wl(sget('kt_active_agency_id')) || (u && (wl(u.landing_agency_id) || wl(u.agency_id))) || null;
    }
    accNow = '#13B7CC';
    if (!t) return KT_BG;
    accNow = safeColour(t.accent_color) || safeColour(t.primary_color) || '#13B7CC';
    var h = t.hero || {}, hf = safeColour(h.from), ht = safeColour(h.to);
    var sb = safeColour(t.splash_bg), pc = safeColour(t.primary_color);
    if (onWhite(hf) && onWhite(ht)) return 'linear-gradient(' + ((+h.angle) || 135) + 'deg,' + hf + ' 0%,' + ht + ' 100%)';
    if (onWhite(sb)) return sb;
    var dk = [hf, ht].filter(onWhite).sort(function (a, b) { return lum(a) - lum(b); })[0];
    if (dk) return dk;
    if (onWhite(pc)) return pc;
    return '#0A1C3A';
  }
  // Remembered while the session is still readable: some paths clear storage before leaving.
  var last = null, lastAcc = '#13B7CC';
  var lastSlug = null;
  function remember() { try { var b = brandBg(); if (b) { last = b; lastSlug = slugNow; lastAcc = (accNow.toLowerCase() === b.toLowerCase()) ? '#ffffff' : accNow; } } catch (e) {} }
  remember();
  d.addEventListener('DOMContentLoaded', remember);
  w.addEventListener('hashchange', remember);

  var el = null, covered = null, gone = false;
  function cover(cb, opts) {
    if (!covered) {
      remember();
      var instant = !!(opts && opts.instant);
      covered = new Promise(function (res) {
        try {
          el = d.createElement('div');
          el.id = 'kt-out-fade';
          el.setAttribute('aria-hidden', 'true');
          // Instant (the idle timeout, 2026-10-23): the brand frame at once, no words -
          // nothing to wait through on the way to the sign-in page.
          var ms = instant ? 0 : (reduced ? 120 : 400);
          el.style.cssText = 'position:fixed;inset:0;z-index:2147483646;pointer-events:auto;opacity:0;'
            + 'transition:opacity ' + ms + 'ms cubic-bezier(.4,0,.2,1);background:' + (last || KT_BG);
          if (!d.getElementById('ktso-style')) {
            var st = d.createElement('style'); st.id = 'ktso-style';
            st.textContent = '@keyframes ktso-spin{to{transform:rotate(360deg)}}@media (prefers-reduced-motion:reduce){.ktso-spin{animation-duration:1.6s!important}}';
            (d.head || root).appendChild(st);
          }
          var ACC = lastAcc;
          el.innerHTML = '<div style="position:absolute;inset:0;display:flex;flex-direction:column;align-items:center;justify-content:center;gap:12px;pointer-events:none">'
        + '<span class="ktso-spin" style="display:block;width:28px;height:28px;box-sizing:border-box;border-radius:50%;border:3px solid rgba(255,255,255,.2);border-top-color:' + ACC + ';animation:ktso-spin .8s linear infinite"></span>'
        + (instant ? '' : '<span style="font:600 13px/1 \'Plus Jakarta Sans\',system-ui,sans-serif;color:rgba(255,255,255,.85);letter-spacing:.02em">' + ktLsT('Signing out\u2026') + '</span>') + '</div>';
          (d.body || root).appendChild(el);
          void el.offsetWidth;                    // commit the transparent state, then fade
          el.style.opacity = '1';
          var ok = false;
          var once = function () { if (!ok) { ok = true; res(); } };
          el.addEventListener('transitionend', once);
          setTimeout(once, instant ? 0 : ms + 60);             // transitionend is not guaranteed
        } catch (e) { res(); }
      });
      try { w.__ktSigningOut = true; } catch (e) {}
    }
    if (cb) covered.then(cb, cb);
    return covered;
  }
  function go(url, replace, opts) {
    if (gone) return;
    gone = true;
    cover(null, opts).then(function () {
      try {
        // A reseller session's sign-in page stays theirs, even where the sign-out wiped the tab.
        if (lastSlug && !sget('kt_tenant')) w.sessionStorage.setItem('kt_tenant', lastSlug);
        w.sessionStorage.setItem('kt_out_fade', last || KT_BG);
        w.sessionStorage.setItem('kt_out_acc', lastAcc);      // the sign-in page keeps the spinner up
        w.sessionStorage.setItem('kt_launch_seen', '1');   // a quiet return, not the launch scene
      } catch (e) {}
      try { if (replace) w.location.replace(url); else w.location.href = url; } catch (e) { w.location.href = url; }
    });
  }
  w.KT_out = { cover: cover, go: go };

  /* SIGNED OUT IN ANOTHER TAB. Auth.clear() there removes the shared kt_token; when it is
     the very token this tab holds, this tab's session is over too. A different account's
     tab (a test tab keeps its token in its own sessionStorage) is never touched. */
  w.addEventListener('storage', function (e) {
    try {
      if (e.key !== 'kt_token' || e.newValue !== null || !e.oldValue) return;
      if (e.oldValue !== sget('kt_token')) return;
      w.sessionStorage.removeItem('kt_token');
      w.sessionStorage.removeItem('kt_user');
      go('/index.html?signed_out=other_tab');
    } catch (x) {}
  });
})(window, document);
