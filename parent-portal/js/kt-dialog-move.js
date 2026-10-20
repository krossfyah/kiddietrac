/* ═══════════════════════════════════════════════════════════════════
   KiddieTrac — EVERY POPUP CAN BE MOVED AND RESIZED (2026-10-20).

   Anthony: "make the popup windows resizable and movable throughout - full sweep".

   One module, not 148 edits. The portal has Shell.Modal plus ~148 hand-rolled dialogs
   across 71 files (see kt-dialog-fit.js); changing each is the fix that does not hold,
   because the next dialog somebody writes would be fixed in place again. So this finds
   open dialogs the same way kt-dialog-fit.js does and upgrades whichever appear:

     - MOVE: drag the title bar (.modal-header, or the top 44px of a dialog that has no
       header). Kept on screen so the bar can always be grabbed again.
     - RESIZE: drag the grip in the bottom-right corner. The body keeps scrolling inside.
     - Double-click the title bar to put it back where and how it opened.

   Deliberately NOT done:
     - Phones and the APK: dialogs there are full-screen sheets (kt-dialog-fit.js).
     - Moving with `transform`: a transform makes the dialog the containing block for any
       position:fixed child, which is exactly how dropdowns and sheets inside popups were
       knocked out of place before ([[kiddietrac-phone-popup-containing-block]]). Offsets
       (left/top) are used instead.
     - A drag that ends over the backdrop must not close the dialog: the click that follows
       a drag is swallowed ([[kiddietrac-modal-dragout-close]]).
     - Full-screen overlays (lightboxes, the emergency takeover, splash) are left alone, and
       anything marked data-kt-nomove opts out.
   ═══════════════════════════════════════════════════════════════════ */
(function () {
  'use strict';
  if (window.__ktDialogMove) { return; }
  window.__ktDialogMove = true;

  var MIN_W = 320, MIN_H = 220, HANDLE_H = 44, KEEP = 60;

  function isNativeApp() {
    try {
      var C = window.Capacitor;
      if (C && typeof C.isNativePlatform === 'function') { return C.isNativePlatform(); }
    } catch (e) {}
    return false;
  }
  function enabled() {
    try { return !isNativeApp() && window.innerWidth > 768 && window.matchMedia('(pointer: fine)').matches; }
    catch (e) { return false; }
  }

  /* The same notion of "a dialog is open" as kt-dialog-fit.js. */
  function overlays() {
    var out = [];
    try {
      var named = document.querySelectorAll('.modal-backdrop, .kt-modal, .kt-modal-overlay, .kt-scrim, [role="dialog"]');
      for (var i = 0; i < named.length; i++) { out.push(named[i]); }
      var kids = document.body ? document.body.children : [];
      for (var j = 0; j < kids.length; j++) {
        var el = kids[j];
        if (el.id === 'appMain' || el.id === 'kt-mobilenav' || el.id === 'kt-gear' || el.id === 'kt-agency-switcher') { continue; }
        if (/^(SCRIPT|STYLE|LINK)$/.test(el.tagName) || out.indexOf(el) !== -1) { continue; }
        var cs = getComputedStyle(el);
        if (cs.position !== 'fixed') { continue; }
        var z = parseInt(cs.zIndex, 10);
        if (isNaN(z) || z < 900) { continue; }
        var r = el.getBoundingClientRect();
        if (r.width < 250 || r.height < 200) { continue; }
        out.push(el);
      }
    } catch (e) {}
    return out;
  }

  function opaque(cs) {
    var bg = cs.backgroundColor || '';
    return (bg && bg !== 'transparent' && !/rgba\([^)]*,\s*0\)$/.test(bg)) || (cs.backgroundImage && cs.backgroundImage !== 'none');
  }

  /* The visible box inside an overlay: the dialog itself, not the dimmed backdrop. */
  function panelOf(ov) {
    if (ov.closest && ov.closest('[data-kt-nomove]')) { return null; }
    var vw = window.innerWidth, vh = window.innerHeight;
    function fits(el) {
      var r = el.getBoundingClientRect();
      return r.width >= 200 && r.height >= 120 && r.width < vw * 0.97 && r.height < vh * 0.98;
    }
    var m = ov.classList && ov.classList.contains('modal-backdrop') ? ov.querySelector(':scope > .modal') : null;
    if (m) { return m; }
    var role = ov.getAttribute && ov.getAttribute('role');
    if (role === 'dialog') {
      var cs0 = getComputedStyle(ov);
      if (opaque(cs0) && fits(ov)) { return ov; }
    }
    var best = null, bestArea = 0;
    var kids = ov.children || [];
    for (var i = 0; i < kids.length; i++) {
      var k = kids[i];
      if (/^(SCRIPT|STYLE)$/.test(k.tagName)) { continue; }
      var cs = getComputedStyle(k);
      if (cs.display === 'none' || !opaque(cs) || !fits(k)) { continue; }
      var r = k.getBoundingClientRect(), a = r.width * r.height;
      if (a > bestArea) { best = k; bestArea = a; }
    }
    return best;
  }

  function interactive(t, panel) {
    for (var n = t; n && n !== panel; n = n.parentElement) {
      if (/^(INPUT|TEXTAREA|SELECT|BUTTON|A|LABEL|OPTION|VIDEO|AUDIO|CANVAS)$/.test(n.tagName)) { return true; }
      if (n.isContentEditable || n.getAttribute('role') === 'button' || n.getAttribute('role') === 'tab') { return true; }
    }
    return false;
  }

  function swallowNextClick() {
    var stop = function (e) { e.stopPropagation(); e.preventDefault(); window.removeEventListener('click', stop, true); };
    window.addEventListener('click', stop, true);
    setTimeout(function () { window.removeEventListener('click', stop, true); }, 350);
  }

  var CSS_ID = 'kt-dialog-move-css';
  function ensureCss() {
    if (document.getElementById(CSS_ID)) { return; }
    var st = document.createElement('style');
    st.id = CSS_ID;
    st.textContent = ''
      + '[data-kt-movable] .modal-header,[data-kt-movable] [data-kt-drag-handle]{cursor:move;user-select:none;}'
      + '.kt-resize-grip{position:absolute;right:2px;bottom:2px;width:16px;height:16px;cursor:nwse-resize;z-index:5;opacity:.55;'
      + 'background:linear-gradient(135deg,transparent 0 50%,#94A3B8 50% 58%,transparent 58% 68%,#94A3B8 68% 76%,transparent 76%);border-bottom-right-radius:6px;}'
      + '.kt-resize-grip:hover{opacity:1;}'
      + 'html[data-kt-moving],html[data-kt-moving] *{user-select:none !important;-webkit-user-select:none !important;}';
    document.head.appendChild(st);
  }

  function upgrade(panel) {
    if (!panel || panel.hasAttribute('data-kt-movable')) { return; }
    var cs = getComputedStyle(panel);
    panel.setAttribute('data-kt-movable', '1');
    ensureCss();

    var mode = cs.position;                       // static/relative: offset; fixed/absolute: left/top
    var rect0 = panel.getBoundingClientRect();
    var orig = {
      position: panel.style.position, left: panel.style.left, top: panel.style.top, right: panel.style.right,
      bottom: panel.style.bottom, width: panel.style.width, height: panel.style.height, maxWidth: panel.style.maxWidth,
      maxHeight: panel.style.maxHeight, margin: panel.style.margin, transform: panel.style.transform,
    };
    if (mode === 'static') { panel.style.position = 'relative'; mode = 'relative'; }

    var header = panel.querySelector(':scope > .modal-header');
    if (!header) {
      var first = panel.firstElementChild;
      if (first && first.getBoundingClientRect().height <= 90) { header = first; first.setAttribute('data-kt-drag-handle', '1'); }
    }

    // Where the box is now, in the terms its positioning uses.
    function pos() {
      if (mode === 'relative') { return { x: parseFloat(panel.style.left) || 0, y: parseFloat(panel.style.top) || 0 }; }
      var r = panel.getBoundingClientRect();
      return { x: r.left, y: r.top };
    }
    function pinFixedBox() {
      // A fixed/absolute box centred with transform or margins is pinned to its current
      // screen position first, so the first drag does not jump.
      // Every box is pinned on its first drag or resize - including Shell.Modal's, which
      // sits centred in a flex backdrop: resized in place it would grow from both sides
      // and the grip would move at half the speed of the pointer.
      if (panel.__ktPinned) { return; }
      var r = panel.getBoundingClientRect();
      panel.style.position = 'fixed';
      panel.style.transform = 'none';
      panel.style.margin = '0';
      panel.style.right = 'auto';
      panel.style.bottom = 'auto';
      panel.style.left = r.left + 'px';
      panel.style.top = r.top + 'px';
      panel.__ktPinned = true;
      mode = 'fixed';
    }

    function startDrag(e) {
      if (e.button !== 0 || interactive(e.target, panel)) { return; }
      if (!header || !header.contains(e.target)) {
        var rr = panel.getBoundingClientRect();
        if (header || e.clientY - rr.top > HANDLE_H) { return; }
      }
      e.preventDefault();
      pinFixedBox();
      var p0 = pos(), sx = e.clientX, sy = e.clientY, moved = false;
      var r0 = panel.getBoundingClientRect();
      panel.setAttribute('data-kt-moving', '1'); document.documentElement.setAttribute('data-kt-moving', '1');
      function mv(ev) {
        var dx = ev.clientX - sx, dy = ev.clientY - sy;
        if (!moved && Math.abs(dx) + Math.abs(dy) < 3) { return; }
        moved = true;
        // Keep the bar reachable: at least KEEP px of the box stays on screen sideways,
        // and its top never goes above the window or below the bottom edge.
        var vw = window.innerWidth, vh = window.innerHeight;
        var nl = r0.left + dx, nt = r0.top + dy;
        nl = Math.min(Math.max(nl, KEEP - r0.width), vw - KEEP);
        nt = Math.min(Math.max(nt, 0), vh - HANDLE_H);
        panel.style.left = (p0.x + (nl - r0.left)) + 'px';
        panel.style.top = (p0.y + (nt - r0.top)) + 'px';
      }
      function up() {
        document.removeEventListener('pointermove', mv, true);
        document.removeEventListener('pointerup', up, true);
        panel.removeAttribute('data-kt-moving'); document.documentElement.removeAttribute('data-kt-moving'); try { getSelection().removeAllRanges(); } catch (e) {}
        if (moved) { swallowNextClick(); }
      }
      document.addEventListener('pointermove', mv, true);
      document.addEventListener('pointerup', up, true);
    }
    panel.addEventListener('pointerdown', startDrag);

    // Double-click the bar: back to where and how it opened.
    (header || panel).addEventListener('dblclick', function (e) {
      if (interactive(e.target, panel)) { return; }
      if (!header) { var rr = panel.getBoundingClientRect(); if (e.clientY - rr.top > HANDLE_H) { return; } }
      Object.keys(orig).forEach(function (k) { panel.style[k] = orig[k]; });
      if (getComputedStyle(panel).position === 'static') { panel.style.position = 'relative'; }
      mode = getComputedStyle(panel).position === 'relative' ? 'relative' : getComputedStyle(panel).position;
      panel.__ktPinned = false;
      syncInnerHeight();
    });

    /* A body that was given a fixed height (the child record's scroller) follows the box. */
    function syncInnerHeight() {
      var host = panel.querySelector('.kt-cd-host');
      if (!host) { return; }
      var h = header ? header.getBoundingClientRect().height : 0;
      var foot = panel.querySelector(':scope > .modal-footer');
      var f = foot ? foot.getBoundingClientRect().height : 0;
      if (panel.style.height) { host.style.height = Math.max(120, panel.clientHeight - h - f) + 'px'; }
      else { host.style.height = 'min(76vh, 860px)'; }
    }

    var grip = document.createElement('div');
    grip.className = 'kt-resize-grip';
    grip.setAttribute('aria-hidden', 'true');
    grip.title = 'Drag to resize';
    panel.appendChild(grip);
    grip.addEventListener('pointerdown', function (e) {
      if (e.button !== 0) { return; }
      e.preventDefault(); e.stopPropagation();
      pinFixedBox();
      var r = panel.getBoundingClientRect(), sx = e.clientX, sy = e.clientY;
      panel.style.maxWidth = 'none';
      panel.style.maxHeight = 'none';
      panel.setAttribute('data-kt-moving', '1'); document.documentElement.setAttribute('data-kt-moving', '1');
      function mv(ev) {
        var w = Math.max(MIN_W, Math.min(window.innerWidth - r.left - 4, r.width + ev.clientX - sx));
        var h = Math.max(MIN_H, Math.min(window.innerHeight - r.top - 4, r.height + ev.clientY - sy));
        panel.style.width = w + 'px';
        panel.style.height = h + 'px';
        syncInnerHeight();
      }
      function up() {
        document.removeEventListener('pointermove', mv, true);
        document.removeEventListener('pointerup', up, true);
        panel.removeAttribute('data-kt-moving'); document.documentElement.removeAttribute('data-kt-moving'); try { getSelection().removeAllRanges(); } catch (e) {}
        swallowNextClick();
      }
      document.addEventListener('pointermove', mv, true);
      document.addEventListener('pointerup', up, true);
    });
    // Shell.Modal: the box is a flex column, so a taller box gives the body (the scroller) the room.
    if (panel.classList.contains('modal')) { panel.style.display = panel.style.display || ''; }
    void rect0;
  }


  /* ── THE SAME LOOK ON EVERY POPUP (2026-10-20) ──────────────────────────────────────
     Anthony: "apply the same to the families section with the popup and all other popups
     with the gradient, resizing and moving and edge to edge". The record popups (child,
     user, family) already had it by hand. Everything else gets it here, on every device:
       - Shell.Modal: the soft navy bar; a title starting Edit / Manage / New / ✏️ gets the
         navy-to-teal "editing" bar; the body padding is one even 16px 18px.
       - A tab strip at the top of a popup becomes a full-width tinted band (measured, so it
         bleeds exactly to the edges whatever padding the popup uses).
       - A hand-rolled popup whose first row is its title gets that row as the same bar,
         edge to edge. A red (warning) title gets a red bar so the warning still reads as
         one. A row that already has its own colour is left exactly as it is.
     Opt out with data-kt-nochrome. */
  var CHROME_ID = 'kt-dialog-chrome-css';
  function ensureChromeCss() {
    if (document.getElementById(CHROME_ID)) { return; }
    var st = document.createElement('style');
    st.id = CHROME_ID;
    st.textContent = ''
      + '.modal{overflow:hidden;border:1px solid #1B3A63;}'
      + '.modal>.modal-header{background:linear-gradient(135deg,#1B3A63 0%,#2A5580 55%,#4A7FA6 100%);border-bottom:0;padding:13px 18px;}'
      + '.modal>.modal-header h2{color:#FFFFFF;font-size:16px;font-weight:800;letter-spacing:.2px;}'
      + '.modal>.modal-header .modal-close{color:#FFFFFF;background:rgba(255,255,255,.14);border-radius:8px;opacity:1;}'
      + '.modal>.modal-header .modal-close:hover{background:rgba(255,255,255,.26);}'
      + '.modal.kt-editing>.modal-header{background:linear-gradient(135deg,#1B3A63 0%,#265B7A 50%,#2F8C86 100%);box-shadow:inset 0 -3px 0 #7FD3CC;}'
      + '.modal>.modal-body{padding:16px 18px;}'
      + '.kt-band{background:#EEF3F8 !important;border:0 !important;border-bottom:1px solid #E2E8F0 !important;border-radius:0 !important;}'
      + '.kt-dlg-head{background:linear-gradient(135deg,#1B3A63 0%,#2A5580 55%,#4A7FA6 100%) !important;color:#FFFFFF !important;}'
      + '.kt-dlg-head.kt-dlg-edit{background:linear-gradient(135deg,#1B3A63 0%,#265B7A 50%,#2F8C86 100%) !important;box-shadow:inset 0 -3px 0 #7FD3CC;}'
      + '.kt-dlg-head.kt-dlg-warn{background:linear-gradient(135deg,#7F1D1D 0%,#B91C1C 60%,#DC2626 100%) !important;}'
      + '.kt-dlg-head,.kt-dlg-head h1,.kt-dlg-head h2,.kt-dlg-head h3,.kt-dlg-head h4,.kt-dlg-head p,.kt-dlg-head span,.kt-dlg-head div{color:#FFFFFF !important;}'
      + '.kt-dlg-head button{color:#FFFFFF !important;background:rgba(255,255,255,.14) !important;border-color:transparent !important;}';
    document.head.appendChild(st);
  }

  var EDIT_RX = /^\s*(✏️\s*)?(edit|editing|manage|new|add)\b/i;

  function transparentBg(el) {
    var cs = getComputedStyle(el);
    return (!cs.backgroundImage || cs.backgroundImage === 'none')
      && (/rgba\([^)]*,\s*0\)$/.test(cs.backgroundColor) || cs.backgroundColor === 'transparent');
  }
  function reddish(el) {
    var m = (getComputedStyle(el).color || '').match(/(\d+),\s*(\d+),\s*(\d+)/);
    return !!m && +m[1] > 170 && +m[2] < 90 && +m[3] < 90;
  }
  function isTabStrip(n) {
    if (!n || n.nodeType !== 1) { return false; }
    if (n.getAttribute('data-kt-tabbar') || n.getAttribute('role') === 'tablist') { return true; }
    var kids = n.children;
    if (kids.length < 3) { return false; }
    for (var i = 0; i < kids.length; i++) {
      if (kids[i].tagName !== 'BUTTON' && kids[i].tagName !== 'A') { return false; }
      if ((kids[i].textContent || '').trim().length > 28) { return false; }
    }
    return true;
  }

  // Pull a band out to the edges of `frame` (left, right and - if it is the first thing - top).
  function bleed(band, frame, top) {
    if (band.__ktBled) { return; }
    var fr = frame.getBoundingClientRect(), br = band.getBoundingClientRect();
    var fcs = getComputedStyle(frame);
    var l = br.left - fr.left - (parseFloat(fcs.borderLeftWidth) || 0);
    var r = fr.right - br.right - (parseFloat(fcs.borderRightWidth) || 0);
    var t = top ? (br.top - fr.top - (parseFloat(fcs.borderTopWidth) || 0) + (frame.scrollTop || 0)) : 0;
    if (l < 0 || r < 0 || l > 60 || r > 60 || t < 0 || t > 60) { return; }
    var bcs = getComputedStyle(band);
    band.style.marginLeft = ((parseFloat(bcs.marginLeft) || 0) - l) + 'px';
    band.style.marginRight = ((parseFloat(bcs.marginRight) || 0) - r) + 'px';
    if (top) { band.style.marginTop = ((parseFloat(bcs.marginTop) || 0) - t) + 'px'; }
    band.__ktBled = { l: l, r: r, t: t };
  }

  function firstVisible(el) {
    var c = el && el.firstElementChild;
    while (c && (getComputedStyle(c).display === 'none' || /^(STYLE|SCRIPT)$/.test(c.tagName))) { c = c.nextElementSibling; }
    return c;
  }

  function decorate(panel) {
    if (!panel || panel.hasAttribute('data-kt-chromed') || (panel.closest && panel.closest('[data-kt-nochrome]'))) { return; }
    panel.setAttribute('data-kt-chromed', '1');
    ensureChromeCss();

    if (panel.classList.contains('modal')) {
      var title = panel.querySelector(':scope > .modal-header h2');
      if (title && EDIT_RX.test(title.textContent || '') && !panel.classList.contains('kt-child-modal')) {
        panel.classList.add('kt-editing');
      }
      // The record popups lay out their own bands; everything else: a tab strip at the top
      // of the body becomes a full-width band.
      if (panel.classList.contains('kt-child-modal') || panel.classList.contains('kt-record-modal')) { return; }
      var body = panel.querySelector(':scope > .modal-body');
      var first = firstVisible(body);
      if (first && !isTabStrip(first)) { var inner = firstVisible(first); if (isTabStrip(inner) && getComputedStyle(first).paddingTop === '0px') { first = inner; } else { first = null; } }
      if (first && isTabStrip(first)) {
        first.classList.add('kt-band');
        bleed(first, body, true);
      }
      return;
    }

    // A hand-rolled popup: its first row, if that row is its title, becomes the bar.
    var head = firstVisible(panel);
    if (!head) { return; }
    var isHeading = /^H[1-4]$/.test(head.tagName);
    if (!isHeading) {
      var hr = head.getBoundingClientRect();
      var h = head.querySelector('h1,h2,h3,h4');
      if (!h || hr.height > 90 || head.querySelector('input,select,textarea,img,table')) { return; }
      var hText = (h.textContent || '').trim(), allText = (head.textContent || '').replace(/[×✕✖]/g, '').trim();
      if (allText.length > hText.length + 24) { return; }   // more than a title (+ a close button)
    }
    if (!transparentBg(head)) { return; }                     // it already has its own look
    var titleEl = isHeading ? head : head.querySelector('h1,h2,h3,h4');
    var warn = titleEl && reddish(titleEl);
    var pcs = getComputedStyle(panel);
    var pt = parseFloat(pcs.paddingTop) || 0, pl = parseFloat(pcs.paddingLeft) || 0, pr = parseFloat(pcs.paddingRight) || 0;
    var rad = pcs.borderTopLeftRadius || '0px';
    head.classList.add('kt-dlg-head');
    if (warn) { head.classList.add('kt-dlg-warn'); }
    else if (titleEl && EDIT_RX.test(titleEl.textContent || '')) { head.classList.add('kt-dlg-edit'); }
    var hcs = getComputedStyle(head);
    head.style.margin = (-pt) + 'px ' + (-pr) + 'px ' + Math.max(14, parseFloat(hcs.marginBottom) || 0) + 'px ' + (-pl) + 'px';
    head.style.padding = '13px ' + Math.max(pr, 18) + 'px 13px ' + Math.max(pl, 18) + 'px';
    head.style.borderRadius = rad + ' ' + rad + ' 0 0';
    if (isHeading) { head.style.fontSize = '16px'; head.style.fontWeight = '800'; }
  }

  var t = null;
  function scan() {
    t = null;
    var move = enabled();
    overlays().forEach(function (ov) {
      var panel = null;
      try { panel = panelOf(ov); } catch (e) {}
      if (!panel) { return; }
      try { decorate(panel); } catch (e) {}
      if (move) { try { upgrade(panel); } catch (e) {} }
    });
  }
  function schedule() { if (!t) { t = setTimeout(scan, 120); } }

  function boot() {
    try { new MutationObserver(schedule).observe(document.body, { childList: true, subtree: true }); } catch (e) {}
    schedule();
  }
  if (document.readyState === 'loading') { document.addEventListener('DOMContentLoaded', boot); } else { boot(); }
})();
