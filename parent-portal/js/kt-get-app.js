/*
 * "Get the app" (2026-11-01, Anthony: a prompt on the web sign-in to download the right store
 * app - "pop up and badge under the form all devices"; iPhone gets an Add to Home Screen tip
 * until KiddieTrac is on the App Store; a white-label brand links to its own listing, else none).
 *
 * - Sign-in page (#loginForm): a block under the form. Android + desktop: Google's official
 *   badge, linked to the brand's listing. iPhone/iPad: "tap Share, then Add to Home Screen".
 *   Desktop also carries the iPhone line.
 * - Dashboard: the same thing once per browser, as a popup (KT.Shell.Modal, so it gets the
 *   one popup header), only when the dashboard has settled - no splash, lock, gate, tour or
 *   other popup up, and never for a parent still waiting on their family (family_pending).
 * - Never inside the app (Capacitor), an installed home-screen app, or a frame (the website's
 *   demo phone).
 *
 * The listing comes from the brand theme's play_url (App\Support\Brand::playUrl): KiddieTrac's
 * own brand -> KiddieTrac on Google Play; a white-label brand -> its reseller's listing, or
 * null, and then nothing shows at all - never a KiddieTrac badge on a white-label page.
 *
 * THE APP STORE (2026-11-06, Anthony: "all communication that already has the google add the apple next to it").
 * KiddieTrac's iPhone app is live: iPhone/iPad now get Apple's "Download on the App Store" badge instead of the
 * Add to Home Screen tip, desktop gets both badges side by side. The listing is the brand theme's ios_url
 * (App\Support\Brand::iosUrl: KiddieTrac's own brand only; a white-label brand has none, so iPhones there keep
 * the tip). iPhones that already saw the old tip-only popup get it once more (SEEN_IOS).
 */
(function (w, d) {
  'use strict';
  if (w.KT_GET_APP) return;
  w.KT_GET_APP = true;

  var KT_PLAY = 'https://play.google.com/store/apps/details?id=com.kiddietrac.app';
  var KT_IOS = 'https://apps.apple.com/app/kiddietrac/id6827700968';
  var SEEN = 'kt_get_app_seen', SEEN_IOS = 'kt_get_app_seen_ios';

  var T = {
    'Get the app': { fr: 'Obtenir l’application', es: 'Obtén la aplicación', hi: 'ऐप पाएँ' },
    'Faster sign-in, and alerts the moment something happens.': {
      fr: 'Connexion plus rapide, et des alertes dès que quelque chose se passe.',
      es: 'Inicio de sesión más rápido y alertas en cuanto ocurre algo.',
      hi: 'तेज़ साइन-इन, और कुछ भी होते ही अलर्ट।' },
    'On iPhone or iPad: tap Share, then Add to Home Screen.': {
      fr: 'Sur iPhone ou iPad : touchez Partager, puis Sur l’écran d’accueil.',
      es: 'En iPhone o iPad: toca Compartir y luego Agregar a inicio.',
      hi: 'iPhone या iPad पर: शेयर पर टैप करें, फिर होम स्क्रीन में जोड़ें।' },
    'Not now': { fr: 'Pas maintenant', es: 'Ahora no', hi: 'अभी नहीं' },
    'Get it on Google Play': { fr: 'Disponible sur Google Play', es: 'Disponible en Google Play', hi: 'Google Play पर पाएँ' },
    'Download on the App Store': { fr: 'Télécharger dans l’App Store', es: 'Descargar en el App Store', hi: 'App Store से डाउनलोड करें' }
  };
  // The site-wide sweep (kt-i18n.js) translates these too, wherever they show.
  function register() {
    try { var D = w.KT && KT.i18n && KT.i18n.dict; if (D) for (var k in T) if (!D[k]) D[k] = T[k]; } catch (e) {}
  }
  function lang() {
    var l = 'en';
    try { l = (w.KT && KT.i18n && KT.i18n.locale && KT.i18n.locale()) || localStorage.getItem('kt_locale') || 'en'; } catch (e) {}
    l = String(l).slice(0, 2).toLowerCase();
    return /^(fr|es|hi)$/.test(l) ? l : 'en';
  }
  function t(s) { var l = lang(), e = T[s]; return (l !== 'en' && e && e[l]) || s; }
  function esc(s) { return String(s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }

  function isIOS() {
    var ua = navigator.userAgent || '';
    return /iPhone|iPad|iPod/i.test(ua) || (/Macintosh/i.test(ua) && (navigator.maxTouchPoints || 0) > 1);
  }
  function isAndroid() { return /Android/i.test(navigator.userAgent || ''); }
  function blocked() {
    if (w.__KT_NATIVE) return true;
    var C = w.Capacitor;
    try { if (C && ((typeof C.isNativePlatform === 'function' && C.isNativePlatform()) || (C.getPlatform && C.getPlatform() !== 'web'))) return true; } catch (e) {}
    try { if (w.matchMedia && w.matchMedia('(display-mode: standalone)').matches) return true; } catch (e) {}
    if (navigator.standalone) return true;
    try { if (w.top !== w) return true; } catch (e) { return true; }
    return false;
  }

  /* The listing for the brand on screen. undefined = not known yet (stay hidden). */
  function sessionGet(k) { try { return sessionStorage.getItem(k); } catch (e) { return null; } }
  function localJson(k) { try { return JSON.parse(localStorage.getItem(k) || 'null'); } catch (e) { return null; } }
  /* Both listings: { play, ios } (each a URL or null), or undefined while the brand is not known. */
  var KT_BOTH = { play: KT_PLAY, ios: KT_IOS };
  function fromTheme(th) {
    return th.white_label ? { play: th.play_url || null, ios: th.ios_url || null }
      : { play: th.play_url || KT_PLAY, ios: th.ios_url || KT_IOS };
  }
  function signinPlay() {
    var de = d.documentElement, wl = de.classList.contains('kt-wl') || de.classList.contains('kt-wl-boot') || de.classList.contains('kt-tenant');
    if (!wl) return KT_BOTH;
    var c = localJson('kt_branding'), th = c && c.theme;
    return th && th.white_label ? fromTheme(th) : undefined;
  }
  function dashPlay() {
    var a = sessionGet('kt_active_agency_id');
    if (!a || a === 'all') {
      try { var u = JSON.parse(sessionGet('kt_user') || 'null'); a = u && (u.landing_agency_id || u.agency_id); } catch (e) {}
    }
    var c = a ? localJson('kt_brand_a' + a) : null, th = c && c.theme;
    if (th) return fromTheme(th);
    // No cached brand: KiddieTrac's own unless this session is white-labelled.
    return d.documentElement.classList.contains('kt-wl') ? undefined : KT_BOTH;
  }

  function badge(url, h) {
    var l = lang(), ww = Math.round(h * (l === 'en' ? 564 / 168 : 646 / 192));
    return '<a class="kt-ga-badge" href="' + esc(url) + '" target="_blank" rel="noopener" style="display:inline-block;line-height:0;border-radius:8px">'
      + '<img src="/images/brands/google-play-badge-' + l + '.png" width="' + ww + '" height="' + h + '" alt="' + esc(t('Get it on Google Play')) + '" style="display:block;width:' + ww + 'px;height:' + h + 'px;border:0"></a>';
  }
  // Apple's official badge (Apple's own SVG, per language; Hindi uses the English one), the same height as Google's.
  function iosBadge(url, h) {
    var l = lang(), ww = Math.round(h * (l === 'fr' ? 126.50751 : 119.66407) / 40);
    return '<a class="kt-ga-badge" href="' + esc(url) + '" target="_blank" rel="noopener" style="display:inline-block;line-height:0;border-radius:8px">'
      + '<img src="/images/brands/app-store-badge-' + l + '.svg" width="' + ww + '" height="' + h + '" alt="' + esc(t('Download on the App Store')) + '" style="display:block;width:' + ww + 'px;height:' + h + 'px;border:0"></a>';
  }
  function badgeRow(L, p, h) {
    return (p.ios ? iosBadge(L.ios, h) : '') + (p.badge ? badge(L.play, h) : '');
  }
  // Apple's share glyph, drawn (no artwork of Apple's), in the text colour.
  var SHARE = '<svg width="13" height="16" viewBox="0 0 13 16" aria-hidden="true" style="vertical-align:-2px;margin:0 2px"><path d="M6.5 1v9M3.5 4l3-3 3 3" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/><path d="M4 6.5H2.5v8h8v-8H9" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linejoin="round"/></svg>';
  function iosTip() { return '<span class="kt-ga-tip">' + SHARE + ' ' + esc(t('On iPhone or iPad: tap Share, then Add to Home Screen.')) + '</span>'; }

  /* What to show here: { badge: bool (Google Play), ios: bool (App Store), tip: bool } or null for nothing. */
  function plan(L) {
    if (!L || (!L.play && !L.ios)) return null;  // white-label without its own listing (or unknown)
    if (isIOS()) return L.ios ? { badge: false, ios: true, tip: false } : (L.play ? { badge: false, ios: false, tip: true } : null);
    if (isAndroid()) return L.play ? { badge: true, ios: false, tip: false } : null;
    return { badge: !!L.play, ios: !!L.ios, tip: !L.ios };   // desktop: either phone
  }

  function css() {
    if (d.getElementById('kt-ga-css')) return;
    var st = d.createElement('style');
    st.id = 'kt-ga-css';
    st.textContent = '.kt-ga{margin-top:14px!important;text-align:center}'
      + '.kt-ga .kt-ga-row{display:flex;justify-content:center;flex-wrap:wrap;gap:8px;margin-top:6px}'
      + '.kt-ga .kt-ga-tip{font-size:12px;line-height:1.4}'
      + '.kt-ga-badge:focus-visible{outline:2px solid #13B7CC;outline-offset:2px}';
    (d.head || d.documentElement).appendChild(st);
  }

  // ── Sign-in page ─────────────────────────────────────────────────────────────────────────
  function renderSignin() {
    var form = d.getElementById('loginForm');
    if (!form) return;
    var box = d.getElementById('ktGetApp');
    var p = blocked() ? null : plan(signinPlay());
    if (!p) { if (box) box.hidden = true; return; }
    var L = signinPlay();
    var key = [L.play, L.ios, p.badge, p.ios, p.tip, lang()].join('|');
    if (box && box.getAttribute('data-k') === key) { box.hidden = false; return; }
    if (!box) {
      box = d.createElement('div');
      box.id = 'ktGetApp';
      box.className = 'ktl-foot kt-ga';               // the footer's colours, white card or navy phone page
      box.setAttribute('data-no-i18n', '');
      css();
      form.parentNode.insertBefore(box, form.nextSibling);
    }
    box.setAttribute('data-k', key);
    box.innerHTML = (p.badge || p.ios ? '<div class="kt-ga-row">' + badgeRow(L, p, 36) + '</div>' : '')
      + (p.tip ? '<div class="kt-ga-row">' + iosTip() + '</div>' : '');
    box.hidden = false;
  }

  // ── Dashboard popup, once per browser ───────────────────────────────────────────────────
  function seenKey() { return isIOS() ? SEEN_IOS : SEEN; }   // iPhones saw only the tip before 2026-11-06
  function seen() { try { return localStorage.getItem(seenKey()) === '1'; } catch (e) { return true; } }
  function markSeen() { try { localStorage.setItem(seenKey(), '1'); } catch (e) {} }
  function visible(el) { return !!(el && (el.offsetWidth || el.offsetHeight) && getComputedStyle(el).visibility !== 'hidden'); }
  // Anything big and fixed over the page - a gate, the tour, a lock, another popup - means "not now".
  function covered() {
    var de = d.documentElement, cl = de.classList;
    if (cl.contains('kt-pw-locked') || cl.contains('kt-ngate-open') || cl.contains('kt-bio-pending')) return true;
    var sp = d.getElementById('kt-splash');
    if (sp && visible(sp)) return true;
    var mr = d.getElementById('modalRoot');
    if (mr && mr.firstChild) return true;
    var vw = w.innerWidth, vh = w.innerHeight, all = d.body ? d.body.querySelectorAll('body > *, body > * > *') : [];
    for (var i = 0; i < all.length; i++) {
      var el = all[i];
      if (el.id === 'modalRoot' || /^(SCRIPT|STYLE|LINK|TEMPLATE)$/.test(el.tagName)) continue;
      var cs = getComputedStyle(el);
      if (cs.position !== 'fixed' || cs.display === 'none' || cs.visibility === 'hidden' || +cs.opacity === 0) continue;
      var r = el.getBoundingClientRect();
      if (r.width * r.height >= vw * vh * 0.6) return true;
    }
    return false;
  }
  function familyPending() {
    try { var u = JSON.parse(sessionGet('kt_user') || 'null'); return !!(u && u.family_pending); } catch (e) { return false; }
  }
  function popup() {
    var L = dashPlay(), p = plan(L);
    if (!p) return false;
    var M = w.KT && KT.Shell && KT.Shell.Modal;
    if (!M || !M.open) return false;
    var body = '<div data-no-i18n style="text-align:center;padding:4px 2px 2px">'
      + '<p style="margin:0 0 14px;font-size:14px;line-height:1.45;color:#334155">' + esc(t('Faster sign-in, and alerts the moment something happens.')) + '</p>'
      + (p.badge || p.ios ? '<div style="display:flex;justify-content:center;flex-wrap:wrap;gap:10px;margin:0 0 ' + (p.tip ? '14px' : '4px') + '">' + badgeRow(L, p, 44) + '</div>' : '')
      + (p.tip ? '<p style="margin:0;font-size:13px;line-height:1.45;color:#475569">' + iosTip() + '</p>' : '')
      + '</div>';
    markSeen();                                  // once per browser, however it is closed
    M.open({ title: t('Get the app'), body: body, actions: [{ label: t('Not now') }] });
    return true;
  }
  function watchDashboard() {
    if (blocked() || seen() || !sessionGet('kt_token')) return;
    var calm = 0, tries = 0;
    var tick = function () {
      if (seen()) return;
      tries++;
      if (familyPending() || covered()) calm = 0; else calm++;
      if (calm >= 3 && popup()) return;
      if (tries < 80) setTimeout(tick, 1500);   // give up after ~2 min; next session tries again
    };
    setTimeout(tick, 5000);
  }

  function start() {
    register();
    if (d.getElementById('loginForm')) {
      renderSignin();
      // The brand can land after first paint (branding-loader / boot.js); the language too.
      [600, 1800, 4000].forEach(function (ms) { setTimeout(renderSignin, ms); });
      w.addEventListener('storage', renderSignin);
    } else {
      watchDashboard();
    }
  }
  if (d.readyState === 'loading') d.addEventListener('DOMContentLoaded', start); else start();
})(window, document);
