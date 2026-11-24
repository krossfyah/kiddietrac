/* ═══════════════════════════════════════════════════════════════════
   KiddieTrac — SUB-TABS SWITCH SMOOTHLY (2026-10-27).
   Anthony (video of Audit log → Payment integrations → Chat conversations on his phone):
   "when changing from one subtab to the next the data being loaded is not smooth - full
   sweep". Every tabbed screen in the portal does the same three things on a tab tap:
     1. wipes the pane to a "Loading…" line and fetches again, even for a tab you were on
        seconds ago;
     2. so the page collapses to that one line and jumps when the rows land;
     3. and the rows pop in.
   Each screen builds its tabs its own way, so this works the way kt-keep-tabs.js and
   kt-tab-band.js do: it recognises a tab TAP anywhere in #appMain and smooths what follows,
   with no per-screen code.

   THE RULES
   - A REAL tap only (event.isTrusted). kt-keep-tabs replays taps after a live refresh with
     .click(), and a refresh must always read fresh data.
   - For 2.5s after a tap, a list read (GET, no record id in the path) that was answered in
     the last 2 minutes is answered again from memory at once, and the real request still
     goes out quietly to refresh that copy. Reads of one record are never served from memory
     (opening a chat transcript is itself written to the audit log), nor counts, polls,
     sign-in, files or exports.
   - ANY write (POST/PUT/PATCH/DELETE to the API) empties the memory. Keys include the
     signed-in token and the agency being viewed, so nothing crosses an agency switch.
   - The content under the tabs keeps its height until the new tab has loaded (nothing in
     flight, no "Loading" line; 4s at most), so the page does not collapse or jump, and the
     new rows fade in.
   - The tab strip stays where it was scrolled while the tab re-renders.
   ═══════════════════════════════════════════════════════════════════ */
(function () {
  'use strict';
  if (window.__ktSmoothTabs || typeof window.fetch !== 'function') { return; }
  window.__ktSmoothTabs = true;

  var API = /^https:\/\/api\.kiddietrac\.com\/api\/v1\//;
  var FRESH_MS = 120000, WINDOW_MS = 2500, HOLD_MAX_MS = 4000, MAX_ENTRIES = 80, MAX_CHARS = 400000;
  var cache = new Map(), windowUntil = 0, gen = 0;

  function cacheable(u) {
    var p = u.replace(API, '').split('?')[0];
    if (/(^|\/)\d+(\/|$)/.test(p)) { return false; }
    return !/unread|count|badges|pulse|markers|presence|session|poll|diag|^auth\/|^me\/|media|export|download|pdf|csv|print/i.test(p);
  }
  function hdr(init, name) {
    try {
      var h = init && init.headers;
      if (!h) { return ''; }
      if (typeof h.get === 'function') { return h.get(name) || ''; }
      for (var k in h) { if (k.toLowerCase() === name.toLowerCase()) { return String(h[k]); } }
    } catch (e) {}
    return '';
  }
  function trim() {
    while (cache.size > MAX_ENTRIES) { cache.delete(cache.keys().next().value); }
  }

  var base = window.fetch;
  window.fetch = function (input, init) {
    var u = '', m = 'GET';
    try { u = String((input && input.url) || input); m = String((init && init.method) || (input && input.method) || 'GET').toUpperCase(); } catch (e) {}
    if (!API.test(u)) { return base.apply(this, arguments); }
    if (m !== 'GET' && m !== 'HEAD' && m !== 'OPTIONS') { cache.clear(); gen++; return base.apply(this, arguments); }
    if (m !== 'GET' || !cacheable(u)) { return base.apply(this, arguments); }
    var key = u + '|' + hdr(init, 'Authorization').slice(-24) + '|' + hdr(init, 'X-Active-Agency-Id') + '|' + hdr(init, 'X-View-As-Role');
    var self = this, args = arguments, g = gen, now = Date.now(), hit = cache.get(key);
    function real() {
      return base.apply(self, args).then(function (res) {
        try {
          var ct = (res.headers && res.headers.get('content-type')) || '';
          if (res.status === 200 && /json/i.test(ct) && g === gen) {
            res.clone().text().then(function (t) {
              if (g === gen && t.length <= MAX_CHARS) { cache.delete(key); cache.set(key, { at: Date.now(), body: t, ct: ct }); trim(); }
            }).catch(function () {});
          }
        } catch (e) {}
        return res;
      });
    }
    if (hit && now < windowUntil && now - hit.at < FRESH_MS) {
      real().catch(function () {});
      return Promise.resolve(new Response(hit.body, { status: 200, headers: { 'Content-Type': hit.ct } }));
    }
    return real();
  };

  /* ── Recognising a tab (the same rules as kt-keep-tabs.js) ─────────── */
  var TAB_ATTR = /^data-(?:[a-z0-9]+-)*[a-z0-9]*tabs?$/;
  var TAB_CLASS = /(^|\s)(kt-)?(sub)?tabs?(-btn|-button|-item|-link)?(\s|$)|(^|\s)[a-z-]*subtab[a-z-]*(\s|$)|(^|\s)[a-z]+Tab(\s|$)/;
  function attrOf(el) {
    if (!el.attributes) { return null; }
    for (var i = 0; i < el.attributes.length; i++) {
      var n = el.attributes[i].name;
      if (TAB_ATTR.test(n) && n.indexOf('table') < 0) { return n; }
    }
    return null;
  }
  function tabOf(target, main) {
    if (!main || !main.contains(target)) { return null; }
    if (target.closest && target.closest('.modal, .modal-backdrop, [role=dialog], .kt-scrim, .kt-modal-overlay')) { return null; }
    for (var e = target; e && e !== main; e = e.parentElement) {
      // kt-tab-band.js has already recognised every strip it restyles (its rule is wider:
      // "tab" anywhere in a button's class, e.g. the Audit log's .kt-al-tab).
      if (e.classList && (e.classList.contains('kt-tabbtn') || (e.parentElement && e.parentElement.classList.contains('kt-tabband')))) { return e; }
      if (e.getAttribute('role') === 'tab' || attrOf(e)) { return e; }
      if (typeof e.className === 'string' && TAB_CLASS.test(e.className) && !/table/i.test(e.className)) { return e; }
      var p = e.parentElement;
      if (p && (p.getAttribute('role') === 'tablist' || p.hasAttribute('data-kt-tabbar')) && /^(BUTTON|A|LI|DIV|SPAN)$/.test(e.tagName)) { return e; }
    }
    return null;
  }

  /* ── Hold the height, keep the strip, fade the rows in ─────────────── */
  var css = document.createElement('style');
  css.id = 'kt-smooth-tabs-css';
  // Played once: a node that is moved later (a strip re-placed under a banner) must not replay it.
  document.addEventListener('animationend', function (e) {
    if (e.animationName === 'ktStIn' && e.target && e.target.classList) { e.target.classList.remove('kt-st-in'); }
  }, true);
  css.textContent = '@keyframes ktStIn{from{opacity:0;transform:translateY(4px)}to{opacity:1;transform:none}}'
    + '.kt-st-in{animation:ktStIn .18s ease-out both;}'
    + '@media (prefers-reduced-motion: reduce){.kt-st-in{animation:none;}}';
  (document.head || document.documentElement).appendChild(css);

  /* THE STRIP STAYS PUT. Measured across 61 tabbed screens: switching to a SHORTER tab while
     scrolled down made the page snap up when the content shrank (Follow-ups 301px, Sales
     invoices 95px), and screens that rebuild their banner nudged the strip (Families 18px).
     So the strip is pinned to where it was on screen while the tab changes, and when the new
     tab is too short to stay scrolled where you were, blank space is left below it (a floor)
     instead of jumping. The floor goes as soon as you scroll back up, switch again or leave. */
  function scrollerOf(main) {
    var cs = getComputedStyle(main);
    if (/(auto|scroll)/.test(cs.overflowY) && main.scrollHeight > main.clientHeight + 1) { return main; }
    return document.scrollingElement || document.documentElement;
  }
  function viewH(sc) { return sc === document.scrollingElement || sc === document.documentElement ? window.innerHeight : sc.clientHeight; }
  function stripFor(main, label, fallback) {
    if (fallback && fallback.isConnected) { return fallback; }
    var bands = main.querySelectorAll('.kt-tabband, [role=tablist]');
    for (var i = 0; i < bands.length; i++) { if ((bands[i].textContent || '').indexOf(label) >= 0) { return bands[i]; } }
    return null;
  }
  /* THE BANNER AND THE STRIP ARE NOT "NEW CONTENT" (2026-10-28, Anthony's desktop video of the
     Audit log tabs: "screen is jumpy when moving from the subtabs in desktop view - full sweep").
     Screens such as the Audit log rebuild their whole pane on a tab click - banner included -
     and kt-banner-normalize moves the tab strip in under the new banner. The fade below was
     given to the outermost new node, so the banner AND the strip went to opacity 0 and slid
     4px: measured in a 1440px browser, banner opacity 0 -> 1 over ~200ms on every click, the
     strip lower still (it keeps .kt-st-in, so every re-insertion replayed the fade), plus the
     banner's 16s gradient restarting from 0% (a visible snap) and the strip moving 18-26px
     when the new banner was taller (Text messages 105px, Voice calls 123px), which the pin
     then "fixed" by scrolling the page so the banner sat 18px under the top bar.
     Now: banners and strips are never faded; the fade goes to the content blocks beside them;
     a rebuilt banner picks up the old one's gradient where it was and skips its entry fade;
     on one screen a banner never gets shorter than the tallest one its tabs have shown, so
     the strip under it stays put; and a page at the very top is not scrolled to pin. */
  var CHROME = '.kt-hero, .kt-page-hero, .page-header-v17, .kt-banner-fx, .kt-tabband, [role=tablist], .kt-al-tabs';
  var bannerMax = {};
  function isChrome(n) { return !!(n.matches && n.matches(CHROME)); }
  function hasChrome(n) { return isChrome(n) || !!(n.querySelector && n.querySelector(CHROME)); }
  // The new content inside a node that also holds a banner / strip: its pieces that hold neither.
  // Collected into out (the observer reads every height first, then writes - see READ ALL).
  function fadeParts(n, depth, out) {
    if (isChrome(n)) { return; }
    if (!hasChrome(n)) { if (!n.classList.contains('kt-st-in')) { out.push(n); } return; }
    if (depth > 4) { return; }
    [].forEach.call(n.children, function (k) { fadeParts(k, depth + 1, out); });
  }
  function finishEntry(n) {
    try { (n.getAnimations ? n.getAnimations() : []).forEach(function (a) { if (a.animationName && a.animationName !== 'ktStIn' && a.effect && isFinite(a.effect.getComputedTiming().endTime)) { a.finish(); } }); } catch (e) {}
  }
  function bannerKey(h) { return String(location.hash || '#').split('?')[0] + '|' + (h.label0 || ''); }
  function carryBanner(b, h, again) {
    // kt-banner-normalize may add .kt-banner-fx a moment later, which starts a NEW animation
    // list (the entry fade again): carry once more when its class changes, for 600ms.
    if (!again && window.MutationObserver) {
      try {
        var cmo = new MutationObserver(function () { carryBanner(b, h, true); });
        cmo.observe(b, { attributes: true, attributeFilter: ['class'] });
        setTimeout(function () { cmo.disconnect(); }, 600);
      } catch (e) {}
    }
    try {
      (b.getAnimations ? b.getAnimations() : []).forEach(function (a) {
        if (a.animationName === 'kt-hero-breathe') {
          if (h.breathe != null) { a.currentTime = h.breathe + (performance.now() - h.at); }
        } else if (a.animationName && a.effect && isFinite(a.effect.getComputedTiming().endTime)) { a.finish(); }   // the entry fade: it never went away
      });
    } catch (e) {}
    /* Measured AFTER the banner has its phone shape (2026-10-28). kt-banner-normalize cuts a phone banner to
       its title a frame after it is inserted; measured before that, a banner read 104-121px instead of 41px,
       bannerMax kept the bigger figure and the next banner was held to it (min-height below). */
    try { if (window.KT && KT.heroPhone) { KT.heroPhone(b); } } catch (e) {}
    try {
      var k = bannerKey(h), now = Math.round(b.getBoundingClientRect().height);
      var want = Math.max(bannerMax[k] || 0, h.bannerH || 0);
      if (want > now + 1 && want - now < 120) { b.style.setProperty('min-height', want + 'px', 'important'); }   // the banner rules set min-height with !important
      bannerMax[k] = Math.max(want, now);
    } catch (e) {}
  }
  var floor = null;
  function dropFloor() {
    if (!floor) { return; }
    var f = floor; floor = null;
    try { f.sc.removeEventListener('scroll', f.onScroll); } catch (e) {}
    f.el.style.minHeight = f.prev;
  }

  var hold = null;
  var lingering = null;
  function stopLinger() {
    var l = lingering; lingering = null;
    if (l) { try { clearTimeout(l.moTimer); l.mo.disconnect(); } catch (e) {} }
  }
  var touchedAt = 0;   // the person's own touch / wheel / key after a tab tap: every pin stops
  ['touchstart', 'touchmove', 'wheel', 'keydown', 'mousedown', 'pointerdown'].forEach(function (t) {
    window.addEventListener(t, function () { touchedAt = Date.now(); }, { passive: true, capture: true });
  });
  function pin(h) {
    if (touchedAt > h.start + 60 || h.atTop) { return; }
    var s = stripFor(h.main, h.label, h.strip);
    if (!s) { return; }
    var d = s.getBoundingClientRect().top - h.top0;
    if (Math.abs(d) > 1) { h.sc.scrollTop += d; }
  }
  function release(linger) {
    if (!hold) { return; }
    var h = hold; hold = null;
    clearInterval(h.timer);
    try {
      if (h.mo) {
        var left = linger ? h.start + 2000 - Date.now() : 0;
        if (left > 0) { var mo0 = h.mo; h.moTimer = setTimeout(function () { mo0.disconnect(); }, left); lingering = h; }
        else { h.mo.disconnect(); }
      }
    } catch (e) {}
    var want = h.sc.scrollTop;
    h.boxes.forEach(function (b, i) { b.style.minHeight = h.prevMin[i]; });
    // Too short to stay where you were: a floor of blank space instead of a jump.
    var lack = Math.round(want + viewH(h.sc) - h.sc.scrollHeight);
    if (lack > 0 && want > 0 && h.box && h.box.isConnected) {
      dropFloor();
      var el = h.box, prev = el.style.minHeight;
      el.style.minHeight = (el.getBoundingClientRect().height + lack) + 'px';
      var f = { el: el, prev: prev, sc: h.sc, top: want, onScroll: null };
      f.onScroll = function () { if (f.sc.scrollTop < f.top - 60) { dropFloor(); } };
      h.sc.addEventListener('scroll', f.onScroll, { passive: true });
      floor = f;
    }
    h.sc.scrollTop = want;
    pin(h);
    // Some screens scroll themselves to the top a moment AFTER their rows land. Keep the
    // strip pinned for half a second more, unless the person touches or scrolls first.
    var tailEnd = Date.now() + 500, stop = false;
    var cancel = function () { stop = true; };
    ['touchstart', 'wheel', 'keydown', 'mousedown'].forEach(function (t) { window.addEventListener(t, cancel, { once: true, passive: true, capture: true }); });
    (function tail() {
      if (stop || hold || Date.now() > tailEnd) { return; }
      pin(h);
      requestAnimationFrame(tail);
    })();
  }
  function settled(box) {
    if ((window.__ktInflight || 0) > 0) { return false; }
    var t = box.innerText || '';
    return !/(^|\n)\s*Loading\b[^\n]{0,40}(…|\.\.\.)/.test(t);
  }

  document.addEventListener('click', function (e) {
    if (!e.isTrusted) { return; }
    var main = document.getElementById('appMain');
    var tab = tabOf(e.target, main);
    if (!tab) { return; }
    var href = tab.getAttribute('href');
    if (href && href.charAt(0) === '#' && href.split('?')[0] !== (location.hash || '').split('?')[0]) { return; }   // navigation, not a tab
    windowUntil = Date.now() + WINDOW_MS;

    // Hold EVERY container between the strip and #appMain at its height: a screen often
    // throws away the wrapper its tabs sit in and builds a new one (the Audit log does), so
    // only the one that survives the re-render can keep the page from collapsing.
    var strip = tab.parentElement, boxes = [];
    var stripH = strip ? strip.getBoundingClientRect().height : 0;
    for (var n = strip; n && n !== main; n = n.parentElement) {
      if (n.getBoundingClientRect().height > stripH + 120) { boxes.push(n); }
    }
    stopLinger();
    release();
    dropFloor();
    {
      var box = boxes.length ? boxes[boxes.length - 1] : null;   // the outermost: the content is somewhere inside it
      var sc = scrollerOf(main);
      hold = { box: box, boxes: boxes, prevMin: boxes.map(function (b) { return b.style.minHeight; }), start: Date.now(), timer: null, mo: null,
               main: main, sc: sc, strip: strip, top0: strip.getBoundingClientRect().top, label: (tab.textContent || '').replace(/\s+/g, ' ').trim().slice(0, 24),
               atTop: sc.scrollTop <= 1, at: performance.now(), breathe: null, bannerH: 0,
               label0: strip ? (strip.textContent || '').replace(/\s+/g, ' ').trim().slice(0, 60) : '' };
      try {
        var oldB = [].filter.call(main.querySelectorAll('.kt-hero, .kt-page-hero, .page-header-v17'), function (x) { return x.getClientRects().length; })[0];
        if (oldB) {
          hold.bannerH = Math.round(oldB.getBoundingClientRect().height);
          (oldB.getAnimations ? oldB.getAnimations() : []).forEach(function (a) { if (a.animationName === 'kt-hero-breathe' && a.currentTime != null) { hold.breathe = a.currentTime; } });
        }
      } catch (e) {}
      boxes.forEach(function (b) { b.style.minHeight = Math.round(b.getBoundingClientRect().height) + 'px'; });
      var h = hold;
      if (window.MutationObserver) {
        h.mo = new MutationObserver(function (list) {
          /* READ ALL, THEN WRITE (2026-10-28, Anthony: "the bottom bar with the buttons freezes and
             I cannot click on anything"). offsetHeight then classList.add per node forced one layout
             per node; a long table turning into cards adds thousands. Rows, cells and anything inside
             a table or an already-fading block are skipped - the block around them fades. */
          var cand = [];
          list.forEach(function (rec) {
            [].forEach.call(rec.addedNodes, function (nd) {
              if (nd.nodeType !== 1) { return; }
              if (/^(TR|TD|TH|TBODY|THEAD|TFOOT|OPTION|STYLE|SCRIPT)$/.test(nd.tagName)) { return; }
              var up = nd.parentElement;
              if (up && up.closest('table, .kt-st-in')) { return; }
              /* A tab is not new content (2026-11-12, Anthony's phone video of the Message Centre): a screen that redraws
                 its tab buttons had them faded in from blank - measured before the band styled them, at the phone's
                 44px button size, they passed the 40px test below - so the whole tab row blinked out on every tap. */
              if (up && up.closest('.kt-tabband, .kt-tbwrap, [role=tablist], .kt-al-tabs')) { return; }
              // A "Loading…" line stays invisible for its first 350ms: a tab that answers
              // quickly (or from memory) goes straight to its rows, a slow one still says so.
              // Observer callbacks run before the frame is painted, so it never flashes.
              if (/^\s*Loading\b/.test(nd.textContent || '') && (nd.textContent || '').length < 80) {
                /* ONLY WHILE IT STILL SAYS "Loading" (2026-10-28, Anthony: "when moving from subtabs the screen
                   flashes"). The node hidden here is often the list CONTAINER while it holds only its loading
                   line (Audit log > Chat conversations: .kt-al-list). The rows arrived ~100ms later INTO this
                   hidden node and stayed invisible until the 350ms timer, then faded in: a blank table, then a
                   fade, on every switch. The moment its content is no longer a loading line it shows at once,
                   with no fade; a slow tab still shows "Loading..." after 350ms as before. */
                nd.style.opacity = '0';
                var shown = false;
                var showNow = function (fade) {
                  if (shown) { return; }
                  shown = true;
                  try { if (nd.__ktStLoadMo) { nd.__ktStLoadMo.disconnect(); } } catch (e) {}
                  if (!nd.isConnected) { return; }
                  if (fade) { nd.style.transition = 'opacity .2s'; }
                  nd.style.opacity = '';
                };
                try {
                  nd.__ktStLoadMo = new MutationObserver(function () {
                    var t = nd.textContent || '';
                    if (!(/^\s*Loading\b/.test(t) && t.length < 80)) { showNow(false); }
                  });
                  nd.__ktStLoadMo.observe(nd, { childList: true, subtree: true, characterData: true });
                } catch (e) {}
                setTimeout(function () { showNow(true); }, 350);
                return;
              }
              if (hasChrome(nd)) {
                [].forEach.call(isChrome(nd) ? [nd] : nd.querySelectorAll('.kt-hero, .kt-page-hero, .page-header-v17'), function (b) {
                  if (b.matches('.kt-hero, .kt-page-hero, .page-header-v17')) { carryBanner(b, h); }
                });
                if (nd.matches && nd.matches('.kt-tabband, [role=tablist], .kt-al-tabs')) { nd.classList.remove('kt-st-in'); }
                // A wrapper rebuilt straight into #appMain carries the shell's entry fade
                // (#appMain > * from opacity .6) - the banner and strip inside it never left.
                if (!isChrome(nd)) { finishEntry(nd); }
                fadeParts(nd, 0, cand);
                return;
              }
              if (!nd.classList.contains('kt-st-in')) { cand.push(nd); }
            });
          });
          var tall = cand.filter(function (nd) { return nd.isConnected && nd.offsetHeight > 40; });
          tall.forEach(function (nd) { nd.classList.add('kt-st-in'); });
        });
        // #appMain itself, not the held box: a screen that rebuilds all of #appMain on a tab click
        // (Accounting AR/AP) throws the box away, and an observer on a detached node hears nothing.
        h.mo.observe(main, { childList: true, subtree: true });
      }
      (function pinLoop() {
        if (hold !== h || touchedAt > h.start + 60 || Date.now() - h.start > 1200) { return; }
        pin(h);
        requestAnimationFrame(pinLoop);
      })();
      h.timer = setInterval(function () {
        if (hold !== h) { clearInterval(h.timer); return; }
        if (Date.now() - h.start > HOLD_MAX_MS || (Date.now() - h.start > 250 && settled(h.box || h.main))) { release(true); }
      }, 120);
    }

    // Keep the strip where it was scrolled (when it is the same element after the re-render).
    // A band (kt-tab-band.js) keeps its own place and brings the tapped tab to the middle; pinning it here as well
    // held it at the old spot for 900ms while the band slid it - the two fought (2026-11-12).
    if (strip && strip.scrollWidth > strip.clientWidth + 4 && !strip.classList.contains('kt-tabband')) {
      var left = strip.scrollLeft, until = Date.now() + 900;
      var tapAt = Date.now();
      (function keep() {
        if (Date.now() > until || !strip.isConnected || touchedAt > tapAt + 60) { return; }
        var act = strip.querySelector('[aria-selected="true"], .active, .on, .selected, .current') || tab;
        var ar = act.getBoundingClientRect(), sr = strip.getBoundingClientRect();
        var visibleAtLeft = (ar.left - sr.left + strip.scrollLeft - left) >= 0 && (ar.right - sr.left + strip.scrollLeft - left) <= sr.width;
        if (visibleAtLeft && Math.abs(strip.scrollLeft - left) > 1) { strip.scrollLeft = left; }
        requestAnimationFrame(keep);
      })();
    }
  }, true);

  window.addEventListener('hashchange', function () { stopLinger(); release(); dropFloor(); windowUntil = 0; });
  window.KT = window.KT || {};
  window.KT.smoothTabs = { clear: function () { cache.clear(); gen++; }, size: function () { return cache.size; } };
})();
