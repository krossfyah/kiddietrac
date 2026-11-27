/* ═══════════════════════════════════════════════════════════════════════
   KiddieTrac — "?" Help for this page, on every screen, for every role (2026-11-27).

   Anthony: "help for this page should be added throughout the portal" and "make the help detailed with step by
   step to allow users to understand how to use the features".

   ONE MAP, AND IT LIVES WITH THE ARTICLES. Each help article says which screens it explains in its own front
   matter (backend/resources/help/{role,shared}/slug.md):

       screens: time-off, time-off?tab=balances, ~hr-settings

   GET /help/page-map (HelpService::pageMapForRole) turns that into  screen key → [{slug, title, fallback}]  for
   the person asking: only the articles their role may read, their own library before the shared one, a
   dedicated article before a "~" fallback, the most specific article first. The same function is what the
   kt:page-help-check guard reads, so the "?" and the guard can never disagree about what a screen opens.

   SCREEN KEY. The hash without its "#": "payroll", "payroll?tab=claims", "settings/about". A tab key wins when
   an article names it, then the bare hash, then the part before "/". An HR entry shown as its HR-only twin
   (#hr-payroll when the plan leaves Payroll out) reads as the entry's own key (kt-hr-sections.js SECTIONS).

   WHERE IT IS DRAWN. Always the same place: the top-right corner of the screen's banner (.kt-hero — the shell
   guarantees every screen has one). It is a span[role=button], not a <button>: the shell lifts every banner
   button into the toolbar under it (liftBannerButtons) and kt-icon-buttons rewrites #appMain buttons by label.
   It replaces the HR tabs' own "?" (HR Phase 4 batch 10, KTHrSections.HELP), which is now front matter too.

   #appMain is a fresh node per render, so it is watched through KT.observeMain — never observed directly.
   ═══════════════════════════════════════════════════════════════════════ */
(function (window, document) {
  'use strict';
  var KT = window.KT = window.KT || {};
  if (KT.pageHelp) return;

  var TIP = 'Help for this page';
  var MAP = null, MAP_WHO = '', LOADING = null, LOADING_WHO = '';

  function store(k) { try { return sessionStorage.getItem(k) || localStorage.getItem(k) || ''; } catch (e) { return ''; } }
  function signedIn() { return !!store('kt_token'); }
  // The map is per person, role preview and language: a View-as switch or a language change asks again.
  function who() {
    var u = {};
    try { u = JSON.parse(store('kt_user') || '{}') || {}; } catch (e) { u = {}; }
    var va = ''; try { va = sessionStorage.getItem('kt_view_as') || ''; } catch (e) {}
    var lang = ''; try { lang = (KT.i18n && KT.i18n.locale && KT.i18n.locale()) || document.documentElement.lang || ''; } catch (e) {}
    return [u.id || '', va, store('kt_active_agency_id'), lang].join('|');
  }
  function load() {
    var w = who();
    if (MAP && MAP_WHO === w) return Promise.resolve(MAP);
    if (LOADING && LOADING_WHO === w) return LOADING;
    if (!KT.Api || !KT.Api.get || !signedIn()) return Promise.resolve(null);
    LOADING_WHO = w;
    LOADING = KT.Api.get('/help/page-map').then(function (r) {
      MAP = (r && r.map && typeof r.map === 'object') ? r.map : {};
      MAP_WHO = w; LOADING = null;
      return MAP;
    }, function () { LOADING = null; return null; });
    return LOADING;
  }

  /* hash → the keys to try, most specific first. */
  function keysFor(hash) {
    var h = String(hash == null ? location.hash : hash).replace(/^#/, '');
    var base = h.split('?')[0], q = h.split('?')[1] || '';
    var m = /(?:^|&)tab=([\w-]+)/.exec(q), tab = m ? m[1] : '';
    var S = window.KTHrSections && window.KTHrSections.list;
    if (S && S.length) {
      for (var i = 0; i < S.length; i++) {
        var s = S[i];
        if (s.alt && s.alt === base) {
          // The HR-only twin: its bare hash is the first HR tab of the entry.
          var first = s.tabs.filter(function (t) { return !t.screen; })[0];
          base = s.hash; tab = tab || (first ? first.k : '');
          break;
        }
      }
    }
    var out = [];
    if (tab) out.push(base + '?tab=' + tab);
    out.push(base);
    if (base.indexOf('/') !== -1) out.push(base.split('/')[0]);
    return out;
  }
  function entriesFor(hash, map) {
    map = map || MAP;
    if (!map) return null;
    var ks = keysFor(hash);
    for (var i = 0; i < ks.length; i++) {
      var e = map[ks[i]];
      if (e && e.length) return { key: ks[i], list: e };
    }
    return null;
  }

  var CSS_ID = 'kt-pghelp-css';
  function css() {
    if (document.getElementById(CSS_ID)) return;
    var s = document.createElement('style'); s.id = CSS_ID;
    // The HR "?" look (kt-hr-quick .hrq-help), pinned to the banner's top-right corner.
    s.textContent = ''
      + '.kt-pghelp{position:absolute;top:10px;right:12px;z-index:4;width:28px;height:28px;min-width:28px;box-sizing:border-box;padding:0;border-radius:50%;'
      + 'border:1px solid #BAE6FD;background:#F0F9FF;color:#0E7490;font-family:inherit;font-weight:800;font-size:14px;line-height:1;cursor:pointer;display:inline-flex;align-items:center;justify-content:center;'
      + 'box-shadow:0 1px 4px rgba(15,23,42,.18);user-select:none;-webkit-user-select:none;transition:background .15s,color .15s,border-color .15s;}'
      + '.kt-pghelp:hover,.kt-pghelp:focus-visible{background:#0E7490;border-color:#0E7490;color:#fff;outline:none;}'
      + '.kt-pghelp:focus-visible{box-shadow:0 0 0 3px rgba(14,116,144,.35);}'
      + 'html.kt-dark .kt-pghelp{background:#0B2530;border-color:#155E75;color:#5EEAD4;}'
      + 'html.kt-dark .kt-pghelp:hover{background:#0E7490;color:#fff;}'
      // The phone banner hides everything but its title; the "?" stays (top-right, which is mid-height on the one-line phone banner).
      + '#appMain :is(.kt-hero,.kt-page-hero,.page-header-v17,[data-kt-brief-banner],[data-kt-page-banner]) > .kt-pghelp{display:inline-flex!important;visibility:visible!important;opacity:1!important;}'
      + '@media (max-width:600px){#appMain .kt-pghelp{top:7px;right:10px;width:28px;height:28px;min-width:28px;font-size:14px;}}'
      + '@media print{.kt-pghelp{display:none!important;}}';
    (document.head || document.documentElement).appendChild(s);
  }

  function open(hash) {
    var e = entriesFor(hash);
    if (!e) return false;
    var slugs = e.list.map(function (x) { return x.slug; });
    if (KT.openHelpDrawer) KT.openHelpDrawer(slugs, { related: e.list });
    else location.hash = '#help/' + slugs[0];
    return true;
  }

  function makeBtn(key) {
    css();
    var b = document.createElement('span');
    b.className = 'kt-pghelp';
    b.setAttribute('role', 'button');
    b.setAttribute('tabindex', '0');
    b.setAttribute('data-kt-pghelp', key);
    var tip = (KT.i18n && KT.i18n.t) ? KT.i18n.t(TIP) : TIP;
    b.setAttribute('data-kttip', tip);
    b.setAttribute('aria-label', tip);
    b.setAttribute('data-kt-iconized', '1');
    b.textContent = '?';
    function go(ev) { ev.preventDefault(); ev.stopPropagation(); open(location.hash); }
    b.addEventListener('click', go);
    b.addEventListener('keydown', function (ev) { if (ev.key === 'Enter' || ev.key === ' ') go(ev); });
    return b;
  }

  /* The page's own banner: the first one, no more than six levels into #appMain (a card's .kt-hero further
     down is not the page's — the same bound kt-banner-normalize uses). */
  function banner(main) {
    // [data-kt-brief-banner] is the educator's "Today at a glance" banner (screen-educator.js), which stands in for .kt-hero;
    // any other screen with its own banner can opt in with data-kt-page-banner.
    var b = main.querySelector('.kt-hero, .kt-page-hero, .page-header-v17, [data-kt-brief-banner], [data-kt-page-banner]');
    if (!b) return null;
    var d = 0;
    for (var n = b; n && n !== main; n = n.parentElement) { if (++d > 7) return null; }
    return b;
  }

  var queued = false;
  function place() {
    queued = false;
    var main = document.getElementById('appMain');
    if (!main) return;
    var map = MAP;
    var e = map ? entriesFor(location.hash, map) : null;
    var hero = e ? banner(main) : null;
    var mine = main.querySelectorAll('.kt-pghelp');
    for (var i = 0; i < mine.length; i++) {
      if (!hero || mine[i].parentNode !== hero || mine[i].getAttribute('data-kt-pghelp') !== e.key) mine[i].remove();
    }
    if (!hero || hero.querySelector(':scope > .kt-pghelp')) return;
    if (getComputedStyle(hero).position === 'static') hero.style.position = 'relative';
    hero.appendChild(makeBtn(e.key));
  }
  function queue() {
    if (queued) return;
    queued = true;
    (window.requestAnimationFrame || setTimeout)(function () {
      if (MAP && MAP_WHO === who()) { place(); return; }
      load().then(function () { place(); }, function () { queued = false; });
    });
  }

  function boot() {
    if (KT.observeMain) {
      KT.observeMain(function (muts) {
        // Our own insert/remove is a mutation too: ignore records that only touched the "?".
        for (var i = 0; i < muts.length; i++) {
          var m = muts[i], n = m.addedNodes[0] || m.removedNodes[0];
          if (m.addedNodes.length + m.removedNodes.length === 1 && n && n.classList && n.classList.contains('kt-pghelp')) continue;
          queue(); return;
        }
      }, { childList: true, subtree: true });
    }
    document.addEventListener('kt:main-swapped', queue);
    window.addEventListener('hashchange', queue);
    window.addEventListener('storage', function (ev) { if (ev && /kt_view_as|kt_user|kt_token/.test(ev.key || '')) queue(); });
    queue();
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot); else boot();

  KT.pageHelp = {
    load: load,
    keysFor: keysFor,
    entriesFor: entriesFor,
    slugsFor: function (hash) { var e = entriesFor(hash); return e ? e.list.map(function (x) { return x.slug; }) : []; },
    open: open,
    refresh: function () { MAP = null; queue(); }
  };
})(window, document);
