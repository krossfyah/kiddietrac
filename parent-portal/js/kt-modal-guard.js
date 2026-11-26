/* ═══════════════════════════════════════════════════════════════════
   KIDDIETRAC — Global modal guard (v22p85, reworked 2026-10-21)
   Ensures EVERY popup in the portal:
     1. has a visible "✕" close control (top-right of the dialog), and
     2. stays contained within the viewport (centred, scrolls internally
        instead of pushing the page or spilling off-screen).
   Works without each screen opting in. Popups that already ship their own ✕
   (Shell.Modal's .modal-close, a ✕/× glyph, aria-label="Close") are left alone.
   Heuristics are size-based so toasts / the chat widget / the FAB (all small,
   fixed elements) are never treated as modals.

   2026-10-21 — Anthony: "all popup windows should have an X to close where appropriate
   - full sweep". The guard existed but missed most of them:
     - it looked once, one tick after a node was attached to <body>. A popup attached
       empty and filled after an await, or attached hidden and shown later, was measured
       as "not a dialog" and never looked at again;
     - popups mounted inside a screen or a wrapper (the schedule's shift editor lives in
       #kt-mount inside #appMain; the tuition-increase form sits in its own wrapper) were
       never seen at all;
     - a bottom "Cancel" counted as a close, so a long form with its Cancel scrolled out
       of sight had no way out at the top;
     - a dialog that rebuilt its own contents (a two-step form) lost the ✕ it was given.
   Now: one throttled scan on <body> subtree changes (never #appMain itself — it is a
   fresh node per render), a ✕ wherever there is no ✕ yet, wired to the dialog's own
   Cancel/Close when it has one so the screen's clean-up still runs, Escape closes the
   top dialog, and a click on the backdrop closes a dialog that has nothing typed into it.

   WHERE A ✕ IS NOT APPROPRIATE (never added):
     - gates and mandatory steps: 2FA (#kt-mfa-gate), terms (#kt-agree), biometric lock
       (#kt-bio-lock), session warning (#kt-session-warning), onboarding wizard and its
       catch-up (#kt-onboarding-overlay, #kt-onboarding-catchup), the emergency/urgent
       takeover (#kt-urgent), the update prompt (#kt-upd), the splash;
     - KT.confirm / KT.prompt (they ask a question and already have Cancel);
     - anything marked data-kt-no-close or data-kt-keep-open;
     - full-screen editors and lightboxes (no card smaller than the screen to put it on);
     - vendor iframes (their own ✕ lives in their document).
   Popups marked data-no-modal-guard keep their own layout; they get a ✕ only when their
   only way out is a worded Cancel/Close button, and the ✕ presses that button.
   ═══════════════════════════════════════════════════════════════════ */
(function (window) {
  'use strict';
  var doc = window.document;
  if (window.__ktModalGuard) { return; }
  window.__ktModalGuard = true;

  var FULLSCREEN_IDS = ['kt-bio-lock', 'kt-bio-ov', 'kt-pin-lock', 'kt-launch', 'kt-out-fade', 'kt-portal-fade', 'kt-urgent'];
  var NO_CLOSE_IDS = ['kt-mfa-gate', 'kt-agree', 'kt-bio-lock', 'kt-session-warning', 'kt-onboarding-overlay',
    'kt-onboarding-catchup', 'kt-urgent', 'kt-splash'];
  /* THE GRADIENT TOP BAR (2026-10-25, Anthony: "check all popup boxes to have the gradient top bar and x close at the
     top right ... add this as a guard/watcher"). Painted as a top-anchored background layer of the card, so it stays at
     the top while a long form scrolls inside it and adds no element that a screen's own code could trip over. */
  var BAR = 'linear-gradient(90deg,#1DB9C3 0%,#2563EB 55%,#7C3AED 100%)';
  var GLYPH = /^(×|✕|✖|⨯|✗|✘|x|X)$/;
  var WORD_CLOSE = /^(cancel|close|not now|dismiss|no thanks|later|maybe later)$/i;

  function vw() { return window.innerWidth || doc.documentElement.clientWidth; }
  function vh() { return window.innerHeight || doc.documentElement.clientHeight; }

  // Geometry and visibility: is `el` a full-screen backdrop holding something?
  function isOverlayShape(el) {
    if (!el || el.nodeType !== 1 || !el.isConnected) return false;
    // Not popups: the splash, the reload gate (the loading screen) and the page itself.
    if (el.id === 'kt-splash' || el.id === 'kt-reload-gate' || el.id === 'appMain') return false;
    /* NOT FULL-SCREEN SCREENS (2026-10-26). Anthony's phone: the fingerprint lock's wordmark showed on a white box with
       the gradient stripe - the lock has no card, so the guard took the logo image for one and dressed it. The locks,
       the launch scene, the sign-out fades and the emergency takeover are screens, not popups. */
    if (FULLSCREEN_IDS.indexOf(el.id) !== -1) return false;
    /* NOT THE REFRESH COVER (2026-10-06). __ktSnapshot appends a fixed, viewport-sized
       picture of #appMain to <body> during a background refresh — which is exactly what
       this guard looks for. Anthony's sign-in video: "the hero banner redraws and it has
       a scroll bar for a brief moment". */
    if (el.hasAttribute('data-kt-refresh-snap') || el.getAttribute('aria-hidden') === 'true' || el.inert) return false;
    var cs;
    try { cs = getComputedStyle(el); } catch (e) { return false; }
    if (cs.position !== 'fixed' && cs.position !== 'absolute') return false;
    // Not opacity: a dialog fading in reads 0 at the moment it is attached.
    if (cs.display === 'none' || cs.visibility === 'hidden') return false;
    var r = el.getBoundingClientRect();
    /* Against what you can SEE (2026-10-26): with the keyboard up, an overlay fitted to the visible area is shorter than
       the window and was dropped as "not a popup", which unlocked and scrolled the page behind it. */
    var seenH = vh();
    try { if (window.visualViewport && window.visualViewport.height) seenH = Math.min(seenH, window.visualViewport.height); } catch (e) {}
    if (r.width < vw() * 0.8 || r.height < seenH * 0.8) return false;
    if (!el.children || el.children.length === 0) return false;
    return true;
  }
  function optedOut(el) { return el.hasAttribute('data-no-modal-guard'); }
  /* A FULL-SCREEN SHEET IS A SCREEN, NOT A POPUP (2026-10-26). The headcount sheet paints the whole window itself: an
     opaque background, its own dark top bar and Close. Read as a popup, its largest block (the list of children) was taken
     for the card, so the first child's row was painted as a navy header with a second ✕ on it and the list was capped at
     90vh. An overlay whose own background is opaque has no card inside it, and keeps its own design. */
  function isSheet(el) {
    try {
      var m = /rgba?\((\d+),\s*(\d+),\s*(\d+)(?:,\s*([\d.]+))?/.exec(getComputedStyle(el).backgroundColor || '');
      return !!m && (m[4] === undefined || parseFloat(m[4]) >= 0.9);
    } catch (e) { return false; }
  }

  // The dialog card = the largest child that isn't itself full-screen.
  function findCard(overlay) {
    var best = null, bestArea = 0;
    for (var i = 0; i < overlay.children.length; i++) {
      var c = overlay.children[i];
      if (c.nodeType !== 1 || c.classList.contains('kt-modal-x')) continue;
      var r = c.getBoundingClientRect();
      if (r.width >= vw() * 0.94 && r.height >= vh() * 0.94) continue; // nested backdrop / full-screen editor
      var area = r.width * r.height;
      if (area > bestArea) { bestArea = area; best = c; }
    }
    return best;
  }

  /* A dialog whose body is a cross-origin iframe cannot be inspected — the vendor's
     own ✕ lives in THEIR document. Treat "cannot see" as "assume it has one". Guessing
     otherwise put a second ✕ on top of Zum's close button. */
  function isVendorFrame(card) {
    if (!card) { return false; }
    if (card.tagName === 'IFRAME') { return true; }
    return !!card.querySelector('iframe');
  }

  function visible(el) {
    if (!el || !el.getClientRects().length) return false;
    try { var cs = getComputedStyle(el); return cs.visibility !== 'hidden' && cs.display !== 'none'; } catch (e) { return false; }
  }

  function glyphClose(card) {
    var tagged = card.querySelector('.modal-close, .kt-modal-x, [data-close], [aria-label="Close" i], [aria-label^="Close " i], [title="Close" i]');
    if (tagged && visible(tagged)) return tagged;
    var els = card.querySelectorAll('button, a, span, i, div[role="button"]');
    for (var i = 0; i < els.length; i++) {
      var t = (els[i].textContent || '').trim();
      if (GLYPH.test(t) && visible(els[i]) && (els[i].tagName !== 'SPAN' && els[i].tagName !== 'I' || els[i].onclick || els[i].closest('button,a'))) {
        return els[i].closest('button,a') || els[i];
      }
    }
    return null;
  }
  // Is this close control in the card's top-right corner?
  function topRight(el, card) {
    try {
      var r = el.getBoundingClientRect(), c = card.getBoundingClientRect();
      return r.top - c.top < 72 && c.right - r.right < 90;
    } catch (e) { return false; }
  }

  function wordedClose(card) {
    var els = card.querySelectorAll('button, a[role="button"], a[href="#"], a:not([href])');
    for (var i = 0; i < els.length; i++) {
      if (WORD_CLOSE.test((els[i].textContent || '').trim()) && !els[i].disabled) return els[i];
    }
    return null;
  }
  /* KT.confirm / KT.prompt: a question with Cancel. Multi-field forms built on the same
     helper carry [data-action="cancel"] too, and those do want a ✕ — so a "question" is at
     most one plain input and no list or text box. */
  function isQuestion(card) {
    if (!card.querySelector('[data-action="cancel"]')) return false;
    return !card.querySelector('select, textarea')
      && card.querySelectorAll('input:not([type=hidden]):not([type=checkbox]):not([type=radio])').length <= 1;
  }

  function closeExcluded(ov) {
    if (NO_CLOSE_IDS.indexOf(ov.id) !== -1) return true;
    if (ov.closest('[data-kt-no-close],[data-kt-keep-open]') || ov.querySelector('[data-kt-no-close],[data-kt-keep-open]')) return true;
    return false;
  }

  function removeOverlay(ov) { if (ov && ov.parentNode) ov.parentNode.removeChild(ov); }

  // The dialog's own way out first, so whatever the screen does on close still happens.
  function closeOverlay(overlay, card) {
    var own = card.querySelector('[data-action="cancel"]') || wordedClose(card) || (card.querySelector('.modal-close'));
    if (own) { own.click(); return; }
    removeOverlay(overlay);
  }

  function luminance(rgb) {
    var m = /rgba?\((\d+),\s*(\d+),\s*(\d+)(?:,\s*([\d.]+))?/.exec(rgb || '');
    if (!m || (m[4] !== undefined && parseFloat(m[4]) < 0.35)) return null;
    return (0.2126 * m[1] + 0.7152 * m[2] + 0.0722 * m[3]) / 255;
  }
  // White ✕ on a dark header band, slate on a light card.
  function tint(x) {
    try {
      var r = x.getBoundingClientRect();
      var stack = doc.elementsFromPoint(r.left + r.width / 2, r.top + r.height / 2);
      for (var i = 0; i < stack.length; i++) {
        if (stack[i] === x) continue;
        /* A gradient or picture header behind the ✕ (not the card's own thin top bar) is a coloured band: white ✕. */
        var bi = getComputedStyle(stack[i]).backgroundImage || 'none';
        if (bi !== 'none' && !stack[i].hasAttribute('data-kt-dlg-bar')) { x.style.color = 'rgba(255,255,255,.95)'; x.__dark = true; return; }
        var L = luminance(getComputedStyle(stack[i]).backgroundColor);
        if (L !== null) { x.style.color = L < 0.5 ? 'rgba(255,255,255,.9)' : '#64748B'; x.__dark = L < 0.5; return; }
      }
    } catch (e) {}
  }

  /* THE ✕ (2026-10-25, Anthony: "the X button on all popups needs to be consistent at the top right corner"):
     one button, one size, one place - 10px in from the popup's top-right corner, pinned to the window so a long form
     scrolling under it never moves it and it never pushes a header aside. A popup's own close in that corner
     (Shell.Modal's, a screen's ✕ or "Close") is hidden and ours presses it, so the screen's own close still runs. */
  function makeX(overlay, card, own) {
    var x = doc.createElement('button');
    x.className = 'kt-modal-x';
    x.type = 'button';
    x.setAttribute('aria-label', 'Close');
    x.setAttribute('title', 'Close (Esc)');
    x.setAttribute('data-kt-auto-close', '1');
    x.textContent = '✕';
    x.style.cssText = 'position:fixed;z-index:2147483646;width:32px;height:32px;padding:0;margin:0;border:none;background:transparent;'
      + 'font-size:17px;line-height:32px;text-align:center;color:#64748B;cursor:pointer;border-radius:8px;'
      + 'transition:background .12s,color .12s;font-family:system-ui,-apple-system,sans-serif;';
    x.addEventListener('mouseenter', function () { x.style.background = x.__dark ? 'rgba(255,255,255,.18)' : '#F1F5F9'; });
    x.addEventListener('mouseleave', function () { x.style.background = 'transparent'; });
    x.addEventListener('click', function (e) {
      e.stopPropagation(); e.preventDefault();
      if (own && own.isConnected) { own.click(); return; }
      closeOverlay(overlay, card);
    });
    overlay.appendChild(x);
    return x;
  }
  /* THE ✕ LIVES INSIDE THE CARD (2026-10-31, Anthony: "the X for the close on the popups in the pay period/payroll are
     off the popup - full sweep and we need to make a rule to make this right each time!").
     The ✕ is position:fixed, but a fixed element is only fixed to the WINDOW when no ancestor has a transform, filter or
     backdrop-filter. Every .kt-scrim overlay has a backdrop blur, so the ✕ was really positioned against the OVERLAY and
     scrolled with it: once a tall popup (the pay-period detail) was scrolled, the window coordinates written into it were
     wrong by the scroll distance and the ✕ ended up above or beside the card.
     Now: the wanted spot is worked out in window coordinates (top-right of the card, centred on its header band, kept on
     screen while the card scrolls), the ✕ is moved there, then MEASURED and corrected by whatever offset its real
     containing block adds. A self-check re-homes it into the card itself if it is still outside, and reports it once. */
  function headOf(card) {
    var h = card.querySelector(':scope > .kt-dlg-head, :scope > .modal-header');
    if (!h) {
      var f = firstBlock(card);
      if (f) {
        var bi = '';
        try { bi = getComputedStyle(f).backgroundImage || ''; } catch (e) {}
        if (bi.indexOf('gradient') !== -1) h = f;
      }
    }
    if (!h) return null;
    var hr = h.getBoundingClientRect(), cr = card.getBoundingClientRect();
    return (hr.height > 0 && hr.height <= 200 && hr.top - cr.top <= 30) ? hr : null;
  }
  function safeTop() {
    try { return parseFloat(getComputedStyle(doc.documentElement).getPropertyValue('--kt-safe-top')) || 0; } catch (e) { return 0; }
  }
  var xReported = {};
  function reportX(card, why) {
    var key = (card.className || card.id || card.tagName).toString().slice(0, 60) + '|' + why;
    if (xReported[key]) return;
    xReported[key] = 1;
    var note = 'close X ' + why + ': ' + key + ' @' + (location.hash || '') + ' ' + window.innerWidth + 'x' + window.innerHeight;
    try { (window.__ktDialogIssues = window.__ktDialogIssues || []).push(note); } catch (e) {}
    try { if (window.KT && KT.crumb) KT.crumb('dialog', note); } catch (e) {}
    try {
      var base = (window.KT && KT.API_BASE) || 'https://api.kiddietrac.com/api/v1';
      var body = JSON.stringify({ data: 'XOUTSIDE ' + note });
      if (navigator.sendBeacon) navigator.sendBeacon(base + '/diag/scroll', new Blob([body], { type: 'application/json' }));
    } catch (e) {}
  }
  function rehomeX(overlay, x, card) {
    // Last resort: put the ✕ inside the card itself, absolutely positioned in its corner.
    try { if (getComputedStyle(card).position === 'static') card.style.position = 'relative'; } catch (e) {}
    x.style.position = 'absolute'; x.style.top = '10px'; x.style.right = '10px'; x.style.left = 'auto';
    card.appendChild(x);
    overlay.__ktXHomed = 1;
  }
  function placeX(overlay) {
    var x = overlay.__ktX, card = overlay.__ktXCard;
    if (!x || !card || !card.isConnected || overlay.__ktXHomed) return;
    var r = card.getBoundingClientRect();
    if (!r.width || !r.height) return;
    var hr = headOf(card);
    var want = hr ? hr.top + (Math.min(hr.height, 96) - 32) / 2 : r.top + 10;
    want = Math.max(want, safeTop() + 8);              // a scrolled-up card keeps its ✕ on screen...
    want = Math.min(want, r.bottom - 42);              // ...but never below the card
    var wantLeft = r.right - 42;
    x.style.top = Math.round(want) + 'px';
    x.style.left = Math.round(wantLeft) + 'px';
    var a = x.getBoundingClientRect();
    var dy = Math.round(want - a.top), dx = Math.round(wantLeft - a.left);
    if (dx || dy) {                                    // fixed inside a blurred/transformed overlay: undo its offset
      x.style.top = (Math.round(want) + dy) + 'px';
      x.style.left = (Math.round(wantLeft) + dx) + 'px';
      a = x.getBoundingClientRect();
    }
    var onScreen = r.bottom > 0 && r.top < window.innerHeight;
    if (onScreen && (a.left < r.left - 2 || a.right > r.right + 2 || a.top < r.top - 2 || a.bottom > r.bottom + 2)) {
      rehomeX(overlay, x, card);
      reportX(card, 'outside card');
      return;
    }
    // The colour behind the ✕ is read once the card has settled, not on every step of a drag (no flicker).
    if (!x.__tinted || x.__tintTop !== Math.round(r.top)) {
      x.__tintTop = Math.round(r.top);
      clearTimeout(x.__tintT);
      var first = !x.__tinted; x.__tinted = 1;
      var probe = function () { if (!x.isConnected) return; x.style.visibility = 'hidden'; tint(x); x.style.visibility = ''; };
      if (first) probe(); else x.__tintT = setTimeout(probe, 180);
    }
  }
  // Re-place on the next frame (many changes in one frame = one move).
  function placeSoon(overlay) {
    if (overlay.__ktXraf) return;
    overlay.__ktXraf = requestAnimationFrame(function () { overlay.__ktXraf = 0; placeX(overlay); });
  }
  function ensureClose(overlay) {
    if (closeExcluded(overlay)) return;
    var card = findCard(overlay);
    if (!card || isVendorFrame(card)) return;
    if (overlay.__ktX && overlay.__ktX.isConnected && overlay.__ktXCard === card) { placeX(overlay); return; }
    overlay.__ktXHomed = 0;
    var g = glyphClose(card);
    var own = g && topRight(g, card) ? g : null;
    if (!own) { var w = wordedClose(card); if (w && topRight(w, card)) own = w; }     // a "Close" word in the corner
    if (!own && optedOut(overlay) && !wordedClose(card)) return;                       // custom layout, no way out to press
    if (own) { own.style.visibility = 'hidden'; own.setAttribute('aria-hidden', 'true'); own.tabIndex = -1; }
    if (overlay.__ktX && overlay.__ktX.parentNode) overlay.__ktX.parentNode.removeChild(overlay.__ktX);
    overlay.__ktX = makeX(overlay, card, own);
    overlay.__ktXCard = card;
    overlay.__ktCloseCard = card;
    placeX(overlay);
    // Follow the card: it can grow, shrink or re-centre after it opens.
    try { if (window.ResizeObserver) { var ro = new ResizeObserver(function () { placeX(overlay); }); ro.observe(card); overlay.__ktXro = ro; } } catch (e) {}
    // A drag moves the card by its left/top (kt-dialog-move): follow every change, frame by frame.
    try { var mo = new MutationObserver(function (recs) { for (var i = 0; i < recs.length; i++) { if (recs[i].target !== overlay.__ktX) { placeSoon(overlay); return; } } }); mo.observe(overlay, { attributes: true, subtree: true, attributeFilter: ['style', 'class'] }); overlay.__ktXmo = mo; } catch (e) {}
    overlay.addEventListener('scroll', function () { placeSoon(overlay); }, true);
    var iv = setInterval(function () { if (!overlay.isConnected) { clearInterval(iv); return; } placeX(overlay); }, 400);
  }
  window.addEventListener('resize', function () { known.forEach(function (ov) { placeX(ov); }); });
  window.addEventListener('scroll', function () { known.forEach(function (ov) { placeSoon(ov); }); }, true);

  function contain(overlay) {
    if (overlay.__ktContained) return;
    var card = findCard(overlay);
    if (!card) return;
    overlay.__ktContained = 1;
    var ccs;
    try { ccs = getComputedStyle(card); } catch (e) { ccs = {}; }
    /* An iframe is sized by its own content and scrolls itself. Capping it at 90vh
       and forcing overflow from out here fights the vendor's own layout. */
    if (!isVendorFrame(card)) {
      if (!ccs.maxHeight || ccs.maxHeight === 'none') card.style.maxHeight = '90vh';
      if (ccs.overflowY === 'visible' || !ccs.overflowY) card.style.overflowY = 'auto';
    }
    if (ccs.position === 'static' || !ccs.position) card.style.position = 'relative';
  }

  /* POPUPS OPEN IN ONE MOVE (2026-10-28). Anthony's phone video: "how the pop ups load not instant/smooth". New chat
     showed a small plain box ("Loading families..."), gained its header a beat later, then jumped up and grew once the
     form arrived — three different pictures in 400 ms. Every hand-built overlay centres its card, so any change in the
     card's height moved it. On a phone the card now hangs from a fixed point near the top (it grows downwards only, so
     loading content never shifts it), and it fades in once instead of popping. Desktop keeps the centred layout. */
  function settle(overlay) {
    if (overlay.__ktSettled) return;
    overlay.__ktSettled = 1;
    var card = findCard(overlay);
    if (!card || isVendorFrame(card)) return;
    if (!doc.getElementById('kt-dlg-in-css')) {
      var st = doc.createElement('style'); st.id = 'kt-dlg-in-css';
      st.textContent = '@keyframes ktDlgIn{from{opacity:0;transform:translateY(10px) scale(.985)}to{opacity:1;transform:none}}'
        + '.kt-dlg-in{animation:ktDlgIn .16s cubic-bezier(.2,.8,.2,1) both}'
        + '@media (prefers-reduced-motion:reduce){.kt-dlg-in{animation:none}}';
      doc.head.appendChild(st);
    }
    try { if (!card.style.animation) card.classList.add('kt-dlg-in'); } catch (e) {}
    if (overlay.hasAttribute('data-kt-centre-always')) return;
    if (vw() > 768) return;
    var cs;
    try { cs = getComputedStyle(overlay); } catch (e) { return; }
    if (cs.display !== 'flex' && cs.display !== 'grid') return;
    /* IN THE MIDDLE OF THE SCREEN (2026-11-06, Anthony: "all pop ups and selections should be centered to the middle of
       the screen - full sweep across the mobile view"). On a phone the card used to hang from the top (2026-10-28), and
       screens that drew a bottom sheet (align-items:flex-end, square bottom corners, full width) stayed at the bottom.
       Every popup is now a card centred between the status bar and the home indicator: a bottom sheet is turned into one
       (rounded on all four corners, a gutter each side), and the space/scroll rules below still keep it on screen. A
       popup that must stay a bottom sheet says data-kt-sheet. */
    var bottom = /^(flex-end|end)$/.test(cs.alignItems) || /^(flex-end|end)$/.test(cs.alignContent);
    if (bottom && overlay.hasAttribute('data-kt-sheet')) return;
    if (cs.alignItems !== 'center' && cs.alignContent !== 'center' && !bottom) return;
    if (bottom) {
      overlay.style.alignItems = 'center';
      if (cs.display === 'grid') overlay.style.alignContent = 'center';
      try {
        var sc = getComputedStyle(card);
        var topR = parseFloat(sc.borderTopLeftRadius) || 0;
        if (topR && !(parseFloat(sc.borderBottomLeftRadius) || 0)) card.style.borderRadius = Math.max(topR, 16) + 'px';
        if (card.getBoundingClientRect().width >= vw() - 4) { card.style.width = 'calc(100% - 24px)'; card.style.maxWidth = '560px'; }
        if (sc.alignSelf === 'flex-end' || sc.alignSelf === 'end') card.style.alignSelf = 'center';
      } catch (e) {}
    }
    /* THE WHOLE SCREEN, TOP TO BOTTOM (2026-10-30, Anthony: "some of the pop up windows start a few space down from the
       top leaving the bottom cut off - e.g. the payments page where parents choose Interac e-Transfer"). The card hung
       56px below the status bar (104px on an iPhone), and only a height written as plain "NNvh" was capped to what is
       left: the many cards sized "calc(100vh - 48px)" (What's new, the Interac sheet's calc(100vh - 32px), ...) kept their
       full height and ran 50-90px off the bottom with their buttons. Now the card hangs just under the status bar, and
       whatever height it asked for is capped to the space between the status bar and the home indicator (the
       overlay's content box: 100%); a taller body scrolls inside the card. kt-dialog-fit keeps the overlay laid over
       what you can see (data-kt-hang), so the keyboard shrinks that space too. */
    overlay.style.paddingTop = 'calc(var(--kt-safe-top, env(safe-area-inset-top, 0px)) + 12px)';
    overlay.style.paddingBottom = 'calc(var(--kt-safe-bottom, env(safe-area-inset-bottom, 0px)) + 12px)';
    overlay.style.overflowY = overlay.style.overflowY || 'auto';
    overlay.setAttribute('data-kt-hang', '1');
    try { var cc = getComputedStyle(card); if (cc.marginTop === 'auto' || cc.marginBottom === 'auto') { card.style.marginTop = '0'; card.style.marginBottom = '0'; } } catch (e) {}
    var asked = card.style.maxHeight;
    if (!asked || asked === 'none') { try { asked = getComputedStyle(card).maxHeight; } catch (e) { asked = 'none'; } }
    card.style.maxHeight = (asked && asked !== 'none' && !/^100%$/.test(asked)) ? 'min(' + asked + ', 100%)' : '100%';
    try { if (getComputedStyle(card).overflowY === 'visible') { card.style.overflowY = 'auto'; card.style.overscrollBehavior = 'contain'; } } catch (e) {}
  }

  /* A DIALOG OPENED FROM A DIALOG GOES ON TOP (2026-10-09).
     Anthony: "when editing a child record I have to hit edit twice". The newest
     full-screen overlay is lifted above whatever dialog is already showing — only when
     it would otherwise sit beneath. */
  function zOf(el) {
    var best = 0;
    for (var n = el; n && n.nodeType === 1; n = n.parentElement) {
      var v = parseInt(getComputedStyle(n).zIndex, 10);
      if (!isNaN(v) && v > best) best = v;
    }
    return best;
  }
  function liftAboveOpenDialogs(overlay) {
    var top = 0;
    var cands = candidates();
    for (var i = 0; i < cands.length; i++) {
      var c = cands[i];
      if (c === overlay || c.contains(overlay) || overlay.contains(c)) continue;
      if (isGate(c) || c.classList.contains('kt-modal-x')) continue;      // a gate stays over everything
      if (c.id === 'kt-splash' || c.hasAttribute('data-kt-refresh-snap') || c.getAttribute('aria-hidden') === 'true') continue;
      var cs;
      try { cs = getComputedStyle(c); } catch (e) { continue; }
      if (cs.position !== 'fixed' || cs.display === 'none' || cs.visibility === 'hidden') continue;
      var r = c.getBoundingClientRect();
      if (r.width < vw() * 0.8 || r.height < vh() * 0.8) continue;
      top = Math.max(top, zOf(c));
    }
    if (top && zOf(overlay) <= top) {
      overlay.style.setProperty('z-index', String(Math.min(top + 10, 2147483645)), 'important');
    }
  }
  // Sign-in / password / lock gates and full-screen screens: nothing is lifted over them, and they are not checked below.
  function isGate(el) {
    return NO_CLOSE_IDS.indexOf(el.id) !== -1 || FULLSCREEN_IDS.indexOf(el.id) !== -1 || el.id === 'kt-pw-gate'
      || el.hasAttribute('data-kt-keep-open');
  }

  /* THE WAY OUT IS ALWAYS REACHABLE (2026-11-04, Anthony: "the X on popup windows are not always present and it loses
     focus - add guards ... to make this be consistent"). While a popup is open, twice a second: find the close control
     of the popup on top and ask the browser what a tap at its centre would hit. Covered by something else -> the popup
     is lifted to the top. On screen and no close control at all (and not a gate or a question) -> reported. Every case
     is beaconed once to /diag/scroll (storage/app/scroll-diag.log, "XREACH ...") so a new kind is found, not guessed. */
  var reachReported = {};
  function reportReach(ov, why) {
    var name = (ov.id || ov.className || ov.tagName).toString().slice(0, 50);
    var key = name + '|' + why;
    if (reachReported[key]) return;
    reachReported[key] = 1;
    var note = 'XREACH ' + why + ': ' + name + ' @' + (location.hash || '') + ' ' + window.innerWidth + 'x' + window.innerHeight;
    try { (window.__ktDialogIssues = window.__ktDialogIssues || []).push(note); } catch (e) {}
    try { if (window.KT && KT.crumb) KT.crumb('dialog', note); } catch (e) {}
    try {
      var base = (window.KT && KT.API_BASE) || 'https://api.kiddietrac.com/api/v1';
      if (navigator.sendBeacon) navigator.sendBeacon(base + '/diag/scroll', new Blob([JSON.stringify({ data: note })], { type: 'application/json' }));
    } catch (e) {}
  }
  function closeControlOf(ov) {
    if (ov.__ktX && ov.__ktX.isConnected && visible(ov.__ktX)) return ov.__ktX;
    return glyphClose(ov) || wordedClose(ov);
  }
  function checkReach() {
    var ov = null;
    for (var i = known.length - 1; i >= 0; i--) {
      var k = known[i];
      if (k.isConnected && isOverlayShape(k) && !isGate(k) && !closeExcluded(k)) { ov = k; break; }
    }
    if (!ov) return;
    try { var ocs = getComputedStyle(ov); if (ocs.pointerEvents === 'none' || parseFloat(ocs.opacity) < 0.5) return; } catch (e) { return; }
    var card = findCard(ov);
    if (card && isQuestion(card)) return;
    var c = closeControlOf(ov);
    if (!c) {
      // A vendor frame keeps its own X in its own document; anything else must have a way out.
      if (!(card && isVendorFrame(card))) reportReach(ov, 'no close control');
      return;
    }
    var r = c.getBoundingClientRect();
    if (!r.width || !r.height) return;
    var vv = window.visualViewport, top = vv ? vv.offsetTop : 0, h = vv ? vv.height : vh();
    var cx = r.left + r.width / 2, cy = r.top + r.height / 2;
    if (cy < top || cy > top + h || cx < 0 || cx > vw()) { reportReach(ov, 'close off screen'); return; }
    var hit = doc.elementFromPoint(cx, cy);
    if (!hit || hit === c || c.contains(hit) || ov.contains(hit)) return;
    /* A POPUP OPENED LATER IS MEANT TO BE ON TOP (2026-11-09, Anthony: "the open email popup that you just implemented
       doesnt popup in front of the existing window - check all popups"). The popup checked here is the newest one the
       guard looks after; one that keeps its own way out (data-kt-no-close: Ask KiddieTrac, the dialer, wallet pay, the
       linked-email viewer) is skipped, so the check fell back to the record popup BEHIND it, found its ✕ covered by the
       newer popup, and lifted the record over it - every half second, 10 higher each time. Covered by a popup that
       opened after it is correct stacking, not a fault. */
    for (var hn = hit; hn && hn !== doc.body; hn = hn.parentElement) {
      if (hn.__ktSeen && hn !== ov) { if (hn.__ktSeen > (ov.__ktSeen || 0)) return; break; }
    }
    // Something that is not this popup sits on its X: put the popup on top.
    var z = zOf(hit);
    if (!isGate(hit.closest('[id]') || hit)) {
      ov.style.setProperty('z-index', String(Math.min(Math.max(z, zOf(ov)) + 10, 2147483645)), 'important');
      reportReach(ov, 'close covered by ' + ((hit.id || hit.className || hit.tagName) + '').slice(0, 40));
    }
  }

  var known = [];   // overlays seen open, newest last
  var seenSeq = 0;  // numbers every popup as it is first seen

  function candidates() {
    var out = [];
    var add = function (list) { for (var i = 0; i < list.length; i++) { if (out.indexOf(list[i]) === -1) out.push(list[i]); } };
    try {
      add(doc.body ? doc.body.children : []);
      var mr = doc.getElementById('modalRoot');
      if (mr) add(mr.children);
      add(doc.querySelectorAll('[role="dialog"], [aria-modal="true"], .kt-modal-overlay, .modal-backdrop, .kt-scrim'));
      // Popups built inside a screen or any wrapper: inline position:fixed anywhere (queried, not observed).
      add(doc.querySelectorAll('[style*="position:fixed"], [style*="position: fixed"]'));
    } catch (e) {}
    return out;
  }

  /* THE DESIGNED HEADER (2026-10-25): Shell.Modal's navy gradient band with white text. A popup that already has a
     gradient header keeps it; one with a title row at the top gets the band on that row, edge to edge; a heading that
     sits loose at the top is wrapped into one. Otherwise the thin gradient bar below is the fallback. */
  var HEAD = 'linear-gradient(135deg,#1B3A63 0%,#265B7A 50%,#2F8C86 100%)';
  var HEAD_EDIT = 'linear-gradient(135deg,#1B3A63 0%,#265B7A 50%,#2F8C86 100%)';
  var HEAD_WARN = 'linear-gradient(135deg,#7F1D1D 0%,#B91C1C 60%,#DC2626 100%)';
  var EDIT_TITLE = /^\s*(\W{0,3}\s*)?(edit|editing|manage|new|add|create|upload)\b/i;
  /* ONE LOOK FOR EVERY POP-UP (2026-10-28, Anthony: "the gradient for the popup is good and needs to be
     consistent throughout the popup's in the portal"). Which variant a header gets: red for an alarm (a
     warm gradient of its own, or a red title), navy-to-teal for Add / Edit / New / Manage, navy otherwise. */
  function warmGradient(img) {
    var m = String(img || '').match(/rgba?\(\d+,\s*\d+,\s*\d+/g);
    if (!m || !m.length) return false;
    var warm = 0;
    m.forEach(function (c) { var v = c.match(/\d+/g).map(Number); if (v[0] > 120 && v[0] > v[2] * 1.6 && v[0] > v[1] * 1.3) warm++; });
    return warm * 2 >= m.length;
  }
  function headVariant(head, card) {
    // The title is the LARGEST bold text in the row (a small kicker such as "FORMS MANAGER" sits above it).
    var t = head, best = 0;
    [].forEach.call(head.querySelectorAll('h1,h2,h3,h4,[style*="font-weight:7"],[style*="font-weight:8"],[style*="font-weight: 8"],[style*="font-weight:9"],strong,b'), function (el) {
      if (el.closest('button')) return;
      var fs = 0; try { fs = parseFloat(getComputedStyle(el).fontSize) || 0; } catch (e) {}
      if (fs > best) { best = fs; t = el; }
    });
    var txt = (t.textContent || '').replace(/\s+/g, ' ').trim();
    var col = '';
    try { col = getComputedStyle(t).color || ''; } catch (e) {}
    var m = col.match(/(\d+),\s*(\d+),\s*(\d+)/);
    if (m && +m[1] > 170 && +m[2] < 90 && +m[3] < 90) return 'warn';
    return EDIT_TITLE.test(txt) ? 'edit' : '';
  }
  function paintVariant(head, v) {
    head.classList.remove('kt-dlg-edit', 'kt-dlg-warn');
    if (v) head.classList.add('kt-dlg-' + v);
  }
  /* A title row that paints its own gradient: restyled to the house band rather than kept. Only a real title
     row (short, no fields, near the top) and never one marked data-kt-own-head. Its own padding and ✕ stay. */
  function ownGradientHead(card) {
    if (card.querySelector('.kt-dlg-head, .modal-header')) return false;
    var first = firstBlock(card);
    if (!first || first.hasAttribute('data-kt-own-head') || first.closest('[data-kt-own-head]')) return false;
    var img = '';
    try { img = getComputedStyle(first).backgroundImage || ''; } catch (e) { return false; }
    if (img.indexOf('linear-gradient') === -1 || img.indexOf('url(') !== -1) return false;
    var fr = first.getBoundingClientRect(), cr = card.getBoundingClientRect();
    if (fr.height > 200 || fr.top - cr.top > 30) return false;
    if (first.querySelector('input,select,textarea,table,iframe,canvas,video')) return false;
    var variant = warmGradient(img) ? 'warn' : headVariant(first, card);
    headCss();
    first.classList.add('kt-dlg-head');
    paintVariant(first, variant);
    return true;
  }
  function headCss() {
    if (doc.getElementById('kt-dlg-head-css')) return;
    var st = doc.createElement('style'); st.id = 'kt-dlg-head-css';
    /* ONE HEADER (2026-10-30, Anthony: 'the gradient and line used on the popup with new purchase order should be used throughout with all popups on desktop and mobile'): every header is the navy-to-teal band with the teal line; only an alarm stays red. */
    st.textContent = '.kt-dlg-head{background:' + HEAD + ' !important;color:#fff !important;box-shadow:inset 0 -3px 0 #7FD3CC;}'
      + '.kt-dlg-head h1,.kt-dlg-head h2,.kt-dlg-head h3,.kt-dlg-head h4,.kt-dlg-head div,.kt-dlg-head span,.kt-dlg-head p,.kt-dlg-head strong,.kt-dlg-head b,.kt-dlg-head small,.kt-dlg-head label{color:#fff !important;}'
      + '.kt-dlg-head p,.kt-dlg-head small,.kt-dlg-head [data-sub]{color:rgba(255,255,255,.85) !important;}'
      // The same variants Shell.Modal and kt-dialog-move use, so every pop-up reads as one family (2026-10-28).
      + '.kt-dlg-head.kt-dlg-edit{background:' + HEAD_EDIT + ' !important;box-shadow:inset 0 -3px 0 #7FD3CC;}'
      + '.kt-dlg-head.kt-dlg-warn{background:' + HEAD_WARN + ' !important;box-shadow:none;}'
      // See tameHeadPanels: a pale box on the band turns see-through, so the white text on it reads.
      + '[data-kt-head-panel]{background:rgba(255,255,255,.14) !important;border-color:rgba(255,255,255,.25) !important;color:#fff !important;}'
      + '[data-kt-head-panel] a,[data-kt-head-panel] code,[data-kt-head-panel] em,[data-kt-head-panel] i,[data-kt-head-panel] td,[data-kt-head-panel] li{color:#fff !important;}';
    (doc.head || doc.documentElement).appendChild(st);
  }
  /* NO PALE BOX ON THE HEADER BAND (2026-11-07, Anthony on the Email log preview: "look at the white highlight - fix
     that - full sweep"). The band makes every word in a header white. A screen that put a light panel, chip or button
     in its header (the Cc/Bcc box, a white attachment button, a pale status pill) then showed white text on white.
     Any light, mostly solid background inside a header is marked data-kt-head-panel and drawn see-through instead.
     Fields keep their own white box (their text is not turned white). */
  var HP_SKIP = /^(INPUT|SELECT|TEXTAREA|OPTION|IMG|SVG|CANVAS|VIDEO|IFRAME|PICTURE|STYLE|SCRIPT)$/;
  function lightBg(el) {
    var m = /rgba?\(([\d.]+),\s*([\d.]+),\s*([\d.]+)(?:,\s*([\d.]+))?/.exec(getComputedStyle(el).backgroundColor || '');
    if (!m) return false;
    var a = m[4] == null ? 1 : parseFloat(m[4]);
    if (a < 0.4) return false;
    var lum = (0.2126 * m[1] + 0.7152 * m[2] + 0.0722 * m[3]) / 255;
    return lum > 0.6;
  }
  function tameHeadPanels() {
    var heads = doc.querySelectorAll('.kt-dlg-head, .modal > .modal-header');
    for (var h = 0; h < heads.length; h++) {
      var head = heads[h];
      if (!head.getClientRects().length) continue;
      var all = head.getElementsByTagName('*');
      for (var i = 0; i < all.length && i < 400; i++) {
        var el = all[i];
        if (el.hasAttribute('data-kt-head-panel') || HP_SKIP.test(el.tagName.toUpperCase())) continue;
        if (el.closest('[data-kt-own-head]')) continue;
        try { if (lightBg(el)) el.setAttribute('data-kt-head-panel', ''); } catch (e) {}
      }
    }
  }
  function hasGradientHead(card) {
    if (card.querySelector('.kt-dlg-head, .modal-header')) return true;
    var first = firstBlock(card);
    if (!first) return false;
    try { return (getComputedStyle(first).backgroundImage || '').indexOf('gradient') !== -1 || (getComputedStyle(first).backgroundImage || '').indexOf('url(') !== -1; } catch (e) { return false; }
  }
  function firstBlock(card) {
    for (var i = 0; i < card.children.length; i++) {
      var c = card.children[i];
      if (c.nodeType !== 1 || c.classList.contains('kt-modal-x') || /^(STYLE|SCRIPT)$/.test(c.tagName)) continue;
      if (!c.getClientRects().length) continue;
      return c;
    }
    return null;
  }
  /* A DRAG HANDLE IS NOT A TITLE (2026-11-06, Anthony on "Aria isn't attending today": "there is some weird design at
     the top left - make this popup look better overall - full sweep"). Bottom sheets start with a little grey grab bar
     (an empty 38x4px div). It was the card's first block, so it was taken for the title row and banded: a navy-to-teal
     stub at the top left, with the real title left plain underneath. Popups are centred now (no bottom sheets), so the
     handle has nothing to drag: it is hidden, and the title under it gets the band. */
  function dropGrabber(card) {
    var g = firstBlock(card);
    if (!g || g.children.length || (g.textContent || '').trim()) return;
    var r = g.getBoundingClientRect();
    if (r.height > 0 && r.height <= 8 && r.width <= 90) g.style.display = 'none';
  }
  function ensureHead(overlay) {
    var card = findCard(overlay);
    if (!card || isVendorFrame(card) || card.__ktHead) return !!(card && card.__ktHead);
    try { dropGrabber(card); } catch (e) {}
    if (ownGradientHead(card)) { card.__ktHead = 1; if (overlay.__ktX) { overlay.__ktX.__tinted = 0; placeX(overlay); } return true; }
    if (hasGradientHead(card)) { card.__ktHead = 1; return true; }
    var first = firstBlock(card);
    if (!first) return false;
    // Its own header, on purpose (a wallet sheet): never banded, whichever path would have caught it.
    if (first.hasAttribute('data-kt-own-head') || first.closest('[data-kt-own-head]') || overlay.hasAttribute('data-kt-own-head')) { card.__ktHead = 1; return true; }
    var cr = card.getBoundingClientRect(), fr = first.getBoundingClientRect();
    var ccs = getComputedStyle(card);
    var pt = parseFloat(ccs.paddingTop) || 0, pl = parseFloat(ccs.paddingLeft) || 0, pr = parseFloat(ccs.paddingRight) || 0;
    var head = null;
    var isHeading = /^H[1-4]$/.test(first.tagName);
    var formy = first.querySelector && first.querySelector('input,select,textarea,table,iframe,canvas,img[width]');
    if (!isHeading && !formy && fr.height <= 160 && fr.top - cr.top <= pt + 24 && (first.querySelector('h1,h2,h3,h4,strong,b,[style*="font-weight:7"],[style*="font-weight:8"],[style*="font-weight: 7"]') || first.children.length <= 3)) {
      head = first;                                    // a title row: band it
    } else if (isHeading) {
      head = doc.createElement('div');                 // a loose heading (and its description line): wrap them
      card.insertBefore(head, first);
      head.appendChild(first);
      var nx = head.nextElementSibling;
      if (nx && nx.tagName === 'P' && (nx.textContent || '').length < 260) head.appendChild(nx);
      first.style.margin = '0';
    }
    if (!head) return false;
    var variant = headVariant(head, card);   // read before the band turns the title white
    headCss();
    head.classList.add('kt-dlg-head');
    paintVariant(head, variant);
    var radius = ccs.borderTopLeftRadius || '14px';
    if (pt || pl || pr) {
      head.style.margin = (-pt) + 'px ' + (-pr) + 'px 14px ' + (-pl) + 'px';
      head.style.padding = Math.max(16, pt * 0.6) + 'px ' + Math.max(56, pr) + 'px ' + Math.max(14, pt * 0.5) + 'px ' + Math.max(20, pl) + 'px';
    } else {
      var hcs = getComputedStyle(head);
      if ((parseFloat(hcs.paddingTop) || 0) < 10) head.style.paddingTop = '14px';
      if ((parseFloat(hcs.paddingBottom) || 0) < 10) head.style.paddingBottom = '12px';
      if ((parseFloat(hcs.paddingRight) || 0) < 50) head.style.paddingRight = '56px';
      if ((parseFloat(hcs.paddingLeft) || 0) < 14) head.style.paddingLeft = '20px';
    }
    head.style.borderRadius = radius + ' ' + radius + ' 0 0';
    head.style.borderBottom = '0';
    card.__ktHead = 1;
    // The ✕ now sits on navy: white.
    if (overlay.__ktX) { overlay.__ktX.__tinted = 0; placeX(overlay); }
    return true;
  }
  // Our ✕ sits level with the header's title: lifted by the card's top padding, which the band now covers.
  function alignX(card) { /* the ✕ is pinned to the card's corner now (placeX) */ }

  function ensureBar(overlay) {
    var card = findCard(overlay);
    if (!card || isVendorFrame(card) || card.__ktBar) return;
    if (card.__ktHead) { card.__ktBar = 1; return; }   // the header band is the top bar
    var cs;
    try { cs = getComputedStyle(card); } catch (e) { return; }
    var img = cs.backgroundImage || 'none';
    /* Only a real card gets the bar: a box with a background of its own. A picture, or a transparent wrapper, is not a
       popup card - painting it white is how a logo ended up on a white box (2026-10-26). */
    if (/^(IMG|SVG|VIDEO|CANVAS|PICTURE)$/i.test(card.tagName)) { card.__ktBar = 1; return; }
    if ((cs.backgroundColor === 'rgba(0, 0, 0, 0)' || cs.backgroundColor === 'transparent') && img === 'none') { card.__ktBar = 1; return; }
    // A card that already paints a gradient band of its own keeps it; a card with a background picture gets the bar layered on top.
    if (img.indexOf('linear-gradient(90deg, rgb(29, 185, 195)') === 0) { card.__ktBar = 1; return; }
    card.style.backgroundImage = BAR + (img !== 'none' ? ', ' + img : '');
    card.style.backgroundRepeat = 'no-repeat' + (img !== 'none' ? ', ' + (cs.backgroundRepeat || 'no-repeat') : '');
    card.style.backgroundSize = '100% 5px' + (img !== 'none' ? ', ' + (cs.backgroundSize || 'auto') : '');
    card.style.backgroundPosition = 'top left' + (img !== 'none' ? ', ' + (cs.backgroundPosition || '0 0') : '');
    if (cs.backgroundColor === 'rgba(0, 0, 0, 0)' || cs.backgroundColor === 'transparent') card.style.backgroundColor = '#fff';
    card.setAttribute('data-kt-dlg-bar', '1');
    card.__ktBar = 1;
  }

  /* WATCHER: a popup that is still missing its bar or its ✕ after the guard has run is a new kind of popup the guard
     does not understand yet. Logged once per popup and left in the crash-report trail (KT.crumb) so it is found. */
  window.__ktDialogIssues = window.__ktDialogIssues || [];
  function audit(overlay) {
    if (overlay.__ktAudited) return;
    var card = findCard(overlay);
    if (!card || isVendorFrame(card)) return;
    var missing = [];
    if (!card.__ktHead) missing.push('gradient header (fell back to the thin bar)');
    var gx = glyphClose(card);
    if (!closeExcluded(overlay) && !(overlay.__ktX && overlay.__ktX.isConnected)) missing.push('close ✕ at the top right');
    overlay.__ktAudited = 1;
    if (!missing.length) return;
    var name = overlay.id || (card.querySelector('h1,h2,h3,[role="heading"]') || {}).textContent || overlay.className || 'popup';
    var note = String(name).trim().slice(0, 60) + ': missing ' + missing.join(' and ');
    window.__ktDialogIssues.push(note);
    try { console.warn('[KT dialog watcher]', note); } catch (e) {}
    try { if (window.KT && KT.crumb) KT.crumb('dialog', note); } catch (e) {}
  }

  /* ONE SCROLL REGION, THE HEADER PINNED, A SLIM SCROLLBAR INSIDE THE ROUNDED CARD (2026-11-02).
     Anthony: "the whats new popup look at the scroll bar and top header - full sweep to fix things like this
     throughout". Most hand-built popups make the CARD itself the scroll box (contain() and settle() do too). Three
     things went wrong with that, on every such popup:
       - the browser's 15px grey scrollbar, with its arrow buttons, ran the full height of the card: up beside the
         header band (leaving a white notch in it) and into both rounded corners;
       - a header that was just the first block of the card scrolled away with the content (KT.prompt, New
         announcement, any hand-built card);
       - where the card was taller than the window the backdrop scrolled too: two scrollbars.
     Fixed here once, for every popup the guard sees, without moving anyone's elements:
       - the scroll box gets .kt-dlg-scroll: a slim thumb on a clear track whose top starts BELOW the header and whose
         ends stop short of the rounded corners (--kt-sb-top / --kt-sb-bottom, measured);
       - a header inside the scroll box is pinned (.kt-dlg-pin, sticky at the card's top edge);
       - the header's band is also painted on the scroll box's own background, so the strip beside the header where
         the scrollbar lives is the band, not a white notch;
       - a card that scrolls inside a backdrop that also scrolls is capped to the backdrop, so only the card scrolls.
     Popups with data-no-modal-guard and vendor frames keep their own layout. */
  function scrollCss() {
    if (doc.getElementById('kt-dlg-scroll-css')) return;
    var st = doc.createElement('style'); st.id = 'kt-dlg-scroll-css';
    // The styled bar is for mouse screens only: a phone keeps its own thin, self-hiding overlay bar, which takes no width.
    var M = '@media (hover:hover) and (pointer:fine){';
    st.textContent = '.kt-dlg-scroll{overscroll-behavior:contain;}' + M
      + '.kt-dlg-scroll::-webkit-scrollbar{width:10px;height:10px;background:transparent;}'
      + '.kt-dlg-scroll::-webkit-scrollbar-button{display:none;width:0;height:0;}'
      + '.kt-dlg-scroll::-webkit-scrollbar-corner{background:transparent;}'
      + '.kt-dlg-scroll::-webkit-scrollbar-track{background:transparent;margin-top:var(--kt-sb-top,4px);margin-bottom:var(--kt-sb-bottom,4px);}'
      + '.kt-dlg-scroll::-webkit-scrollbar-thumb{background:rgba(100,116,139,.42);border-radius:10px;border:3px solid transparent;background-clip:padding-box;min-height:36px;}'
      + '.kt-dlg-scroll::-webkit-scrollbar-thumb:hover{background:rgba(71,85,105,.66);border:3px solid transparent;background-clip:padding-box;}}'
      // Firefox has no ::-webkit-scrollbar: the standard properties (Chrome would let them switch the styling above off).
      + '@supports not selector(::-webkit-scrollbar){.kt-dlg-scroll{scrollbar-width:thin;scrollbar-color:rgba(100,116,139,.5) transparent;}}'
      + '.kt-dlg-pin{position:sticky !important;z-index:6;}'
      // A data table inside a popup's scroll box is not capped to a box of its own (that was the second scrollbar
      // beside the first in the pay-period detail): its rows join the popup's one scroll, as on phones.
      + '.kt-dlg-scroll .kt-tbl-scroll{max-height:none;overscroll-behavior:auto;}'
      // Anything else that scrolls inside a popup (a list, a horizontal table) gets the same slim bar. :where() keeps
      // this at the lowest specificity, so a strip that hides its scrollbar on purpose still hides it.
      + M + ':where(.kt-dlg-scroll) *::-webkit-scrollbar{width:10px;height:10px;}'
      + ':where(.kt-dlg-scroll) *::-webkit-scrollbar-button{display:none;width:0;height:0;}'
      + ':where(.kt-dlg-scroll) *::-webkit-scrollbar-track,:where(.kt-dlg-scroll) *::-webkit-scrollbar-corner{background:transparent;}'
      + ':where(.kt-dlg-scroll) *::-webkit-scrollbar-thumb{background:rgba(100,116,139,.42);border-radius:10px;border:3px solid transparent;background-clip:padding-box;}}';
    (doc.head || doc.documentElement).appendChild(st);
  }
  function scrollsY(el) {
    try { return /auto|scroll/.test(getComputedStyle(el).overflowY); } catch (e) { return false; }
  }
  // The card's scroll box: the card itself, or the tallest scrolling block within three levels of it (a body under a fixed header).
  function scrollBoxOf(card, cr) {
    if (scrollsY(card)) return card;
    var best = null, bestH = cr.height * 0.35;
    (function walk(el, depth) {
      for (var i = 0; i < el.children.length; i++) {
        var c = el.children[i];
        if (c.nodeType !== 1 || /^(STYLE|SCRIPT|TEXTAREA|SELECT|IFRAME)$/.test(c.tagName) || c.classList.contains('kt-modal-x')) continue;
        if (scrollsY(c)) { var h = c.getBoundingClientRect().height; if (h > bestH) { bestH = h; best = c; } continue; }
        if (depth < 3) walk(c, depth + 1);
      }
    })(card, 1);
    return best;
  }
  function frameScroll(overlay) {
    var card = findCard(overlay);
    if (!card || isVendorFrame(card) || card.hasAttribute('data-kt-own-scroll')) return;
    var cr = card.getBoundingClientRect();
    if (!cr.height || !cr.width) return;
    // A popup that keeps its own layout (data-no-modal-guard) still gets the slim scrollbar, nothing structural.
    var light = optedOut(overlay);
    if (light && scrollsY(overlay) && !overlay.classList.contains('kt-dlg-scroll')) { scrollCss(); overlay.classList.add('kt-dlg-scroll'); }
    var sc = scrollBoxOf(card, cr);
    if (!sc) return;
    scrollCss();
    if (!sc.classList.contains('kt-dlg-scroll')) sc.classList.add('kt-dlg-scroll');
    var ccs = getComputedStyle(card), scs = getComputedStyle(sc);
    var rad = Math.max(parseFloat(ccs.borderTopRightRadius) || 0, parseFloat(ccs.borderBottomRightRadius) || 0);
    // Two scrollbars: the backdrop scrolls because the card is taller than it. Cap the card to the backdrop.
    if (!light && overlay !== card && overlay.scrollHeight > overlay.clientHeight + 2 && overlay.clientHeight > 200) {
      var ocs = getComputedStyle(overlay);
      var room = overlay.clientHeight - (parseFloat(ocs.paddingTop) || 0) - (parseFloat(ocs.paddingBottom) || 0)
        - (parseFloat(ccs.marginTop) || 0) - (parseFloat(ccs.marginBottom) || 0);
      if (room > 200 && cr.height > room + 1) {
        card.style.maxHeight = Math.floor(room) + 'px';
        if (card.style.height) card.style.height = '';
        if (sc !== card && !scrollsY(card)) {
          // The body scrolls inside a card that does not: let the card be a column so the body takes the slack.
          var cd = ccs.display;
          if (cd === 'block') { card.style.display = 'flex'; card.style.flexDirection = 'column'; }
          if (ccs.overflow === 'visible') card.style.overflow = 'hidden';
          sc.style.minHeight = '0'; sc.style.flex = sc.style.flex || '1 1 auto';
        }
        cr = card.getBoundingClientRect();
      }
    }
    var sr = sc.getBoundingClientRect();
    var bt = parseFloat(scs.borderTopWidth) || 0;
    var top = 4, bottom = 4;
    var head = card.querySelector(':scope > .kt-dlg-head, :scope > .modal-header') || (card.__ktHead ? firstBlock(card) : null);
    if (!light && head && sc.contains(head) && head !== sc) {
      var hh = head.offsetHeight;
      if (hh > 0 && hh <= Math.min(160, cr.height * 0.34) && head.parentNode === sc) {
        if (!head.classList.contains('kt-dlg-pin')) {
          head.classList.add('kt-dlg-pin');
          head.style.top = (-(parseFloat(scs.paddingTop) || 0)) + 'px';
        }
        top = hh + 6;
        // The band behind the header, painted on the scroll box itself so the scrollbar's strip beside the header is the band too.
        var img = '';
        try { img = getComputedStyle(head).backgroundImage || ''; } catch (e) {}
        var own = sc.style.backgroundImage || '';
        if (/^(initial|none|unset)$/.test(own)) own = '';   // a `background:#fff` shorthand writes background-image:initial
        if (img.indexOf('gradient') !== -1 && (!own || sc.__ktBand === own) && sc.offsetWidth - sc.clientWidth > 2) {
          var hy = Math.max(0, Math.round(head.getBoundingClientRect().top - sr.top - bt));
          var band = 'linear-gradient(#7FD3CC,#7FD3CC), ' + img;
          if (head.classList.contains('kt-dlg-warn')) band = img;
          if (sc.__ktBand !== band || sc.__ktBandH !== hh) {
            sc.style.backgroundImage = band;
            sc.style.backgroundRepeat = band === img ? 'no-repeat' : 'no-repeat, no-repeat';
            sc.style.backgroundSize = band === img ? ('100% ' + (hy + hh) + 'px') : ('100% 3px, 100% ' + (hy + hh) + 'px');
            sc.style.backgroundPosition = band === img ? '0 0' : ('0 ' + (hy + hh - 3) + 'px, 0 0');
            sc.__ktBand = sc.style.backgroundImage; sc.__ktBandH = hh;
          }
        }
      }
    } else if (sr.top - cr.top < rad) {
      top = Math.max(top, Math.ceil(rad - (sr.top - cr.top)));
    }
    if (cr.bottom - sr.bottom < rad) bottom = Math.max(bottom, Math.ceil(rad - (cr.bottom - sr.bottom)));
    var t = top + 'px', b = bottom + 'px';
    if (sc.style.getPropertyValue('--kt-sb-top') !== t) sc.style.setProperty('--kt-sb-top', t);
    if (sc.style.getPropertyValue('--kt-sb-bottom') !== b) sc.style.setProperty('--kt-sb-bottom', b);
    if (!overlay.__ktFrameRO && window.ResizeObserver) {
      try {
        var ro = new ResizeObserver(function () { if (!overlay.isConnected) { ro.disconnect(); return; } try { frameScroll(overlay); } catch (e) {} });
        ro.observe(card); if (head) ro.observe(head);
        overlay.__ktFrameRO = ro;
      } catch (e) {}
    }
  }

  function process(el) {
    if (/^(SCRIPT|STYLE|LINK|TEMPLATE)$/.test(el.tagName)) return;
    if (!isOverlayShape(el)) return;
    if (!el.hasAttribute('data-kt-scroll')) el.setAttribute('data-kt-scroll', '1');
    try { var sc = findCard(el); if (sc && !sc.hasAttribute('data-kt-scroll')) sc.setAttribute('data-kt-scroll', '1'); } catch (e) {}
    if (!el.__ktSeen) {
      el.__ktSeen = ++seenSeq;   // the order popups opened in (checkReach never lifts an older one over a newer)
      /* EVERY popup, its own layout or not (2026-11-04): the document viewer and Fill & Sign keep their layout but
         must still open ABOVE the popup they were opened from - they opened underneath it, X covered (Bruni). */
      liftAboveOpenDialogs(el);
    }
    if (isSheet(el)) {
      try { phoneSafe(el); } catch (e) {}
      // Its own way out stays the way out; only a sheet without one gets the ✕.
      if (glyphClose(el) || wordedClose(el)) { if (el.__ktX && el.__ktX.parentNode) el.__ktX.parentNode.removeChild(el.__ktX); el.__ktX = null; }
      else ensureClose(el);
      if (known.indexOf(el) === -1) known.push(el);
      return;
    }
    if (!optedOut(el)) { contain(el); settle(el); }
    /* ONE FORM LAYOUT IN EVERY POPUP (2026-11-26, Anthony: "edit the agency the pop up prompts are not all fully
       aligned"). The shared field rules (kt-field-height 32/38px, kt-popup-rows, kt-field-fit) find popups by
       role=dialog / .modal / .kt-scrim - a hand-built <div> overlay matched none of them, so its inputs were 28px,
       its selects 29px and its phone address row ragged. Every popup this guard dresses carries data-kt-dlg, and
       those rules name it. Own-layout popups (data-no-modal-guard: PDF field overlays), full-screen sheets and the gates
       (sign-in locks, MFA, password change: big-on-purpose fields) do not. */
    if (!optedOut(el) && !isGate(el) && !el.hasAttribute('data-kt-no-close') && !el.hasAttribute('data-kt-dlg')) el.setAttribute('data-kt-dlg', '1');
    try { phoneSafe(el); } catch (e) {}
    ensureHead(el);
    ensureClose(el);
    ensureBar(el);
    try { frameScroll(el); } catch (e) {}
    // A beat later, once the popup has finished drawing.
    if (!el.__ktAuditTimer) el.__ktAuditTimer = setTimeout(function () { try { if (el.isConnected) audit(el); } catch (e) {} }, 1200);
    if (known.indexOf(el) === -1) known.push(el);
  }

  /* EVERY POPUP CLEAR OF THE NOTCH, ON EVERY PHONE (2026-11-06, Anthony: "look at forms and medical consent as an
     example the screen is not filled properly and the x at the top is cut off - make this right for all phone types -
     full sweep with all similar"). settle() keeps a guarded popup between the status bar and the home indicator, but
     ~50 popups keep their own layout (data-no-modal-guard: the document viewer, Fill & Sign, the form filler, health
     editor, forms manager...) and only padded themselves a flat 12-18px - on an iPhone with a notch or Dynamic Island
     (47-59px) their header and ✕ sat under the status bar. Measured, not assumed: whatever the popup is, if its card
     (or, for a full-screen sheet, its top-most control) starts inside the status bar, the overlay is padded down past
     it; a centred card is also kept off the home indicator; and a document-sized card (70%+ of the screen, i.e. a form
     or a document) takes ALL the height between them instead of stopping short. Phones only; nothing changes where
     there is no inset. data-kt-own-safe opts out. */
  var insetProbe = null;
  function insets() {
    var t = 0, b = 0;
    try {
      if (!insetProbe) {
        insetProbe = doc.createElement('div');
        insetProbe.setAttribute('aria-hidden', 'true');
        insetProbe.style.cssText = 'position:fixed;left:-9999px;top:0;width:1px;height:1px;visibility:hidden;pointer-events:none;'
          + 'padding-top:env(safe-area-inset-top,0px);padding-bottom:env(safe-area-inset-bottom,0px);';
        (doc.body || doc.documentElement).appendChild(insetProbe);
      }
      var pc = getComputedStyle(insetProbe);
      t = parseFloat(pc.paddingTop) || 0; b = parseFloat(pc.paddingBottom) || 0;
    } catch (e) {}
    var rs = getComputedStyle(doc.documentElement);
    t = Math.max(t, parseFloat(rs.getPropertyValue('--kt-safe-top')) || 0);
    b = Math.max(b, parseFloat(rs.getPropertyValue('--kt-safe-bottom')) || 0);
    return { top: t, bottom: b };
  }
  function topControl(el) {
    var best = null, bestTop = Infinity;
    var list = el.querySelectorAll('button, a[href], [role="button"], h1, h2, h3, [class*="head"]');
    for (var i = 0; i < list.length && i < 60; i++) {
      var rr = list[i].getBoundingClientRect();
      if (!rr.width || !rr.height) continue;
      if (rr.top < bestTop) { bestTop = rr.top; best = list[i]; }
    }
    return best ? bestTop : null;
  }
  function phoneSafe(el) {
    if (vw() > 768 || el.hasAttribute('data-kt-own-safe')) return;
    var ins = insets();
    if (ins.top < 1 && ins.bottom < 1) return;
    var cs = getComputedStyle(el);
    if (cs.position !== 'fixed') return;
    var er = el.getBoundingClientRect();
    if (er.top > 2) return;                                   // already laid out below the status bar
    var pt = parseFloat(cs.paddingTop) || 0, pb = parseFloat(cs.paddingBottom) || 0;
    var GAP = 8;
    if (isSheet(el)) {
      // A full-screen sheet paints its own top bar: push it down only if its controls start under the status bar.
      var tc = topControl(el);
      if (tc !== null && tc < ins.top + 2 && pt < ins.top) { el.style.paddingTop = Math.ceil(ins.top + pt) + 'px'; el.style.boxSizing = 'border-box'; }
      return;
    }
    var card = findCard(el);
    if (!card) return;
    var cr = card.getBoundingClientRect();
    if (cr.height < 40) return;
    var ccs = getComputedStyle(card);
    var sheetBottom = /^(flex-end|end)$/.test(cs.alignItems) || /^(flex-end|end)$/.test(ccs.alignSelf);
    var wantT = Math.ceil(ins.top + GAP), wantB = Math.ceil(ins.bottom + GAP);
    var changed = false;
    if (cr.top < ins.top + 2 && pt < wantT) { el.style.paddingTop = wantT + 'px'; changed = true; }
    if (!sheetBottom && cr.bottom > vh() - ins.bottom - 2 && pb < wantB) { el.style.paddingBottom = wantB + 'px'; changed = true; }
    if (!changed && !el.__ktSafeDone) return;
    el.__ktSafeDone = 1;
    el.style.boxSizing = 'border-box';
    if (cs.display !== 'flex' && cs.display !== 'grid') return;
    // A form or a document: all the room between the status bar and the home indicator, not 92% of it.
    if (!sheetBottom && cr.height >= vh() * 0.7) {
      card.style.maxHeight = '100%';
      card.style.height = '100%';
    } else {
      var mh = card.style.maxHeight || ccs.maxHeight;
      card.style.maxHeight = (mh && mh !== 'none' && mh !== '100%') ? 'min(' + mh + ', 100%)' : '100%';
    }
  }

  function scan() {
    timer = null;
    var list = candidates();
    for (var i = 0; i < list.length; i++) {
      try { process(list[i]); } catch (e) {}
    }
    known = known.filter(function (k) { return k.isConnected; });
    try { scrollLock(list); } catch (e) {}
    try { headCss(); tameHeadPanels(); } catch (e) {}
  }
  /* THE PAGE BEHIND A POPUP STAYS STILL (2026-10-26). Anthony: "when viewing forms in forms manager/completed and
     scrolling, the entire screen sometimes scroll vs just the popup". A swipe that started on the popup's header or
     edge, or ran past the end of the document inside it, scrolled the page underneath. While any popup is open
     (including ones that keep their own layout), the page cannot scroll and a scroll inside the popup never chains
     out. A popup that does not take taps (pointer-events:none) does not count, so nothing invisible can freeze a page. */
  function scrollLock(list) {
    var open = false;
    for (var i = 0; i < list.length && !open; i++) {
      var el = list[i];
      if (!el.isConnected || !isOverlayShape(el)) continue;
      var cs = getComputedStyle(el);
      if (cs.pointerEvents === 'none' || parseFloat(cs.opacity) === 0) continue;
      open = true;
      el.style.overscrollBehavior = 'contain';
    }
    var root = doc.documentElement;
    if (open === root.classList.contains('kt-dlg-open')) { if (!open && scrollLock.iv) { clearInterval(scrollLock.iv); scrollLock.iv = null; } return; }
    if (!doc.getElementById('kt-dlg-lock-css')) {
      var st = doc.createElement('style'); st.id = 'kt-dlg-lock-css';
      st.textContent = 'html.kt-dlg-open{scrollbar-gutter:stable;}html.kt-dlg-open,html.kt-dlg-open body,html.kt-dlg-open #appMain#appMain,html.kt-dlg-open #appShell > #appMain#appMain{overflow:hidden !important;overscroll-behavior:none !important;}'
        + 'html.kt-dlg-open .kt-scrim,html.kt-dlg-open [role="dialog"]{overscroll-behavior:contain;}';
      doc.head.appendChild(st);
    }
    root.classList.toggle('kt-dlg-open', open);
    // A popup that closes by hiding (not removal) is not seen by the watcher: while locked, look again twice a second.
    if (open && !scrollLock.iv) scrollLock.iv = setInterval(function () { try { scrollLock(candidates()); } catch (e) {} try { checkReach(); } catch (e) {} }, 500);
    if (!open && scrollLock.iv) { clearInterval(scrollLock.iv); scrollLock.iv = null; }
  }
  var timer = null, late = null, raf = 0;
  /* SAME FRAME (2026-10-28): when a new element lands on <body> (or #modalRoot), or anything with an inline
     position:fixed, look in the next animation frame — which runs BEFORE the browser paints — so a popup's first
     picture already has its header, ✕ and phone placement. Text and list re-renders deeper in the page don't qualify. */
  function overlayAdded(recs) {
    if (!recs) return true;
    for (var i = 0; i < recs.length; i++) {
      var add = recs[i].addedNodes;
      for (var j = 0; j < add.length; j++) {
        var n = add[j];
        if (n.nodeType !== 1) continue;
        var par = n.parentNode;
        if (par === doc.body || (par && par.id === 'modalRoot')) return true;
        var st = n.getAttribute && n.getAttribute('style');
        if (st && /position:\s*fixed/i.test(st)) return true;
      }
    }
    return false;
  }
  // A second look shortly after: a dialog that is still animating or filling in from an
  // await is often not measurable on the first pass.
  function schedule(recs) {
    if (!raf && overlayAdded(recs)) { raf = requestAnimationFrame(function () { raf = 0; scan(); }); }
    if (!timer) { timer = setTimeout(scan, 120); }
    if (late) { clearTimeout(late); }
    late = setTimeout(function () { late = null; scan(); }, 600);
  }

  // The open dialog on top: the newest still-visible one that may be closed.
  function topDialog() {
    for (var i = known.length - 1; i >= 0; i--) {
      var ov = known[i];
      if (ov.isConnected && isOverlayShape(ov) && !closeExcluded(ov)) return ov;
    }
    return null;
  }
  function closeTop(ov) {
    var card = findCard(ov);
    if (!card) return;
    var x = (ov.__ktX && ov.__ktX.isConnected ? ov.__ktX : null) || glyphClose(card) || wordedClose(card)
      || (isQuestion(card) ? card.querySelector('[data-action="cancel"]') : null);
    if (x) { x.click(); return; }
    if (!optedOut(ov)) removeOverlay(ov);
  }

  /* Escape closes the dialog on top. Deferred one tick: a dialog that handles Escape
     itself has gone by then, and must not take the one underneath with it. */
  doc.addEventListener('keydown', function (e) {
    if (e.key !== 'Escape' || e.defaultPrevented) return;
    var ov = topDialog();
    if (!ov) return;
    setTimeout(function () { if (ov.isConnected && isOverlayShape(ov)) closeTop(ov); }, 0);
  });

  /* A click on the dim backdrop closes the dialog — only when nothing could be lost (no
     fields in it) and only when the press also STARTED on the backdrop, so a drag or a
     text selection that ends outside the card never closes it. */
  var downOn = null;
  doc.addEventListener('pointerdown', function (e) { downOn = e.target; }, true);
  doc.addEventListener('click', function (e) {
    var ov = e.target;
    if (!ov || ov !== downOn || known.indexOf(ov) === -1 || closeExcluded(ov)) return;
    var card = findCard(ov);
    if (!card || isQuestion(card) || card.querySelector('input:not([type=hidden]):not([type=checkbox]):not([type=radio]), textarea, select, [contenteditable="true"]')) return;
    setTimeout(function () { if (ov.isConnected && isOverlayShape(ov)) closeTop(ov); }, 0);
  });

  function start() {
    // childList only: style/class changes fire constantly (clocks, animations) and would
    // turn this into a layout read every few milliseconds.
    try { new MutationObserver(schedule).observe(doc.body, { childList: true, subtree: true }); } catch (e) {}
    /* AFTER A TAP, LOOK (2026-10-28). Some pop-ups exist from the start, hidden, and are SHOWN by a style change
       (Forms Manager > Upload a form): no node is added, so the watcher above never fired and the pop-up kept its
       flat header for 2-4 s on a desktop - until something unrelated changed the page. A click or Enter/Space
       just arms the same 120 ms look; it costs nothing when no pop-up opened. */
    doc.addEventListener('click', function () { schedule(null); }, true);
    doc.addEventListener('keyup', function (e) { if (e.key === 'Enter' || e.key === ' ') schedule(null); }, true);
    scan();
  }

  if (doc.readyState === 'loading') doc.addEventListener('DOMContentLoaded', start);
  else start();
})(window);
