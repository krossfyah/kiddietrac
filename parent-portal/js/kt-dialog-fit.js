/* ═══════════════════════════════════════════════════════════════════
   KiddieTrac — MAKE A DIALOG FIT THE PHONE (2026-09-23).

   Anthony: "user management and pop ups in mobile is not showing up correctly and
   truncated and needs scrolling — full sweep on all popup windows for mobile."

   WHAT WAS ACTUALLY WRONG, measured on a 394px viewport rather than reasoned from
   the stylesheets. The user record dialog held a row 498px wide inside a 291px box,
   with no scroller and no wrap: five of its eight tabs — Clock in/out, Account & pay,
   Data & retention, About — could not be reached at all on a phone. The child record
   had six such rows. The dialogs themselves were fine: Shell.Modal caps its height and
   scrolls .modal-body, and the hand-rolled .kt-scrim dialogs scroll too (verified: 201px
   of scrollable overflow on the forms editor). The truncation is HORIZONTAL, and it is
   one mechanism in every case:

       a flex or grid child keeps min-width:auto, so it REFUSES to shrink below its
       own content, and the parent has neither wrap nor overflow to cope.

   That is the same root cause already recorded for grids in the layout-width rule; it
   applies to flex the same way.

   WHY THIS IS JS AND NOT A STYLESHEET. There are ~148 hand-rolled dialogs across 71
   files. Editing them is the fix that does not hold — the next dialog someone writes
   lands broken again, which is exactly how the z-index version of this bug went. A
   blanket stylesheet is the other trap: "every grid in a dialog becomes one column"
   also flattens the deliberate label/value grids that were never too wide. So this
   measures first and changes only what does not fit, which is a rule that keeps
   working on dialogs nobody has written yet.

   It runs ONLY on a phone (or the APK, whose WebView can report >768px — the
   breakpoint lesson from the scroll work), and only on an open dialog.
   ═══════════════════════════════════════════════════════════════════ */
(function () {
  'use strict';
  if (window.__ktDialogFit) { return; }
  window.__ktDialogFit = true;

  var SLACK = 4;          // px of overflow to ignore: sub-pixel rounding, not truncation
  var MIN_COL = 118;      // a grid column narrower than this cannot hold a date field

  function isNativeApp() {
    try {
      var C = window.Capacitor;
      if (C) {
        if (typeof C.isNativePlatform === 'function') { return C.isNativePlatform(); }
        if (C.isNative != null) { return !!C.isNative; }
        if (C.platform && C.platform !== 'web') { return true; }
      }
    } catch (e) {}
    return false;
  }
  function mobile() {
    try { return isNativeApp() || window.innerWidth <= 768; } catch (e) { return false; }
  }

  /* The open dialogs on screen. Same shape test kt-mobilenav.js uses for the chrome —
     one definition of "a dialog is open" in the portal, not two that drift — except
     that this one needs the ELEMENTS, not a yes/no, and must look past body's own
     children because Shell.Modal parents its backdrop to #modalRoot. */
  function openDialogs() {
    var out = [];
    try {
      var named = document.querySelectorAll('.modal-backdrop, .kt-modal, .kt-modal-overlay,'
        + ' .kt-scrim, [role="dialog"]');
      for (var i = 0; i < named.length; i++) { out.push(named[i]); }
      var kids = document.body ? document.body.children : [];
      for (var j = 0; j < kids.length; j++) {
        var el = kids[j];
        if (el.id === 'appMain' || el.id === 'kt-mobilenav' || el.id === 'kt-gear'
            || el.id === 'kt-agency-switcher') { continue; }
        if (el.tagName === 'SCRIPT' || el.tagName === 'STYLE' || el.tagName === 'LINK') { continue; }
        if (out.indexOf(el) !== -1) { continue; }
        var cs = window.getComputedStyle(el);
        if (cs.position !== 'fixed') { continue; }
        var z = parseInt(cs.zIndex, 10);
        if (isNaN(z) || z < 900) { continue; }
        var r = el.getBoundingClientRect();
        if (r.width < 250 || r.height < 200) { continue; }
        out.push(el);
      }
    } catch (e) {}
    /* NO opacity test here, deliberately, though the chrome's version of this test has
       one. Every dialog in the portal fades in, and the observer fires the moment it is
       INSERTED — which is the frame where that fade is still at opacity 0. Skipping it
       then means skipping it for good, because nothing else mutates afterwards. A
       dialog that is present but still transparent is a dialog. */
    return out.filter(function (el) {
      var cs = window.getComputedStyle(el);
      return cs.display !== 'none' && cs.visibility !== 'hidden';
    });
  }

  /* A checkbox is 16px wide and reports a scrollWidth of 25-45px. Nothing is clipped —
     that is just what a replaced control reports — but by the plain test it looks like
     the worst overflow in the dialog, and repairs aimed at it do nothing except churn.
     Controls that draw themselves, and anything too small to hold text, are not the
     kind of box this module is about. */
  var PHANTOM = { checkbox: 1, radio: 1, range: 1, color: 1, file: 1 };
  function overflowsX(n) {
    if (n.tagName === 'INPUT' && PHANTOM[n.type]) { return false; }
    if (n.clientWidth < 24) { return false; }
    return n.scrollWidth - n.clientWidth > SLACK;
  }

  /* A strip of tabs stays ONE row and scrolls sideways. Eight tabs squeezed into 291px
     is 36px each, which is not a tab any more; a row you swipe keeps every one of them
     legible and reachable. Recognised by the marker the user dialog already sets, by
     the ARIA role, or by shape — a non-wrapping row of three or more buttons whose
     labels are all short. */
  function isTabStrip(n) {
    if (n.getAttribute('data-kt-tabbar')) { return true; }
    var role = n.getAttribute('role');
    if (role === 'tablist') { return true; }
    var cls = (n.className || '').toString();
    if (/\btab(s|bar|-bar|strip)\b/i.test(cls)) { return true; }
    var kids = n.children;
    if (kids.length < 3) { return false; }
    var buttons = 0;
    for (var i = 0; i < kids.length; i++) {
      var k = kids[i];
      if (k.tagName !== 'BUTTON' && k.tagName !== 'A') { return false; }
      if ((k.textContent || '').trim().length > 26) { return false; }
      buttons++;
    }
    return buttons >= 3;
  }

  function fitElement(n) {
    var cs = window.getComputedStyle(n);
    var display = cs.display;

    if (isTabStrip(n)) {
      n.style.overflowX = 'auto';
      n.style.flexWrap = 'nowrap';
      n.style.scrollbarWidth = 'none';
      n.setAttribute('data-kt-fit', 'scroll-x');
      for (var i = 0; i < n.children.length; i++) {
        n.children[i].style.flex = '0 0 auto';
        n.children[i].style.whiteSpace = 'nowrap';
      }
      return true;
    }

    if (display === 'flex' || display === 'inline-flex') {
      // let the children shrink first; wrap only if that is not enough
      for (var j = 0; j < n.children.length; j++) { n.children[j].style.minWidth = '0'; }
      if (!overflowsX(n)) { n.setAttribute('data-kt-fit', 'shrink'); return true; }
      n.style.flexWrap = 'wrap';
      n.setAttribute('data-kt-fit', 'wrap');
      return !overflowsX(n);
    }

    if (display === 'grid' || display === 'inline-grid') {
      /* Fewer, wider columns rather than one: two 124px fields side by side still fit
         a phone and read better than a single column of eight. auto-fit does the
         arithmetic against the real width, so it lands on whatever actually fits. */
      n.style.gridTemplateColumns = 'repeat(auto-fit,minmax(' + MIN_COL + 'px,1fr))';
      for (var k = 0; k < n.children.length; k++) { n.children[k].style.minWidth = '0'; }
      n.setAttribute('data-kt-fit', 'grid-auto-fit');
      if (!overflowsX(n)) { return true; }
      n.style.gridTemplateColumns = '1fr';      // still too wide: one column
      n.setAttribute('data-kt-fit', 'grid-1col');
      return !overflowsX(n);
    }

    /* Not a layout box: an address or a filename is a single unbreakable token, and no
       amount of shrinking its container moves it. The token itself has to break.

       ONLY on an element holding text and nothing else. overflow-wrap INHERITS, so
       setting it on a container hands it to every descendant — applied to a wrapper it
       broke the eight tab labels mid-word, which "fixed" the overflow by making the
       strip unreadable. A container that is too wide is too wide because of something
       inside it, and that something is repaired on its own turn. */
    if (n.children.length) { return false; }
    n.style.overflowWrap = 'anywhere';
    n.setAttribute('data-kt-fit', 'break-word');
    return !overflowsX(n);
  }

  /* The dialog must also FIT VERTICALLY. Shell.Modal and .kt-scrim both do this
     already; a dialog written without either ends taller than the screen with nothing
     able to scroll to its buttons. Only applied when that is measurably the case. */
  function fitHeight(overlay) {
    try {
      var vh = window.innerHeight;
      var card = null;
      for (var i = 0; i < overlay.children.length; i++) {
        var c = overlay.children[i];
        if (c.getBoundingClientRect().height > 60) { card = c; break; }
      }
      if (!card) { return; }
      var r = card.getBoundingClientRect();
      if (r.bottom <= vh + 1 && r.top >= -1) { return; }            // fits: leave it
      if (overlay.scrollHeight - overlay.clientHeight > SLACK) { return; }  // already scrolls
      var ocs = window.getComputedStyle(overlay);
      if (ocs.position !== 'fixed') { return; }
      /* Centring is what makes the overflow unreachable: half of it goes off the TOP,
         where no scroll can follow. Anchored to the start, all of it is below. */
      overlay.style.alignItems = 'flex-start';
      overlay.style.overflowY = 'auto';
      overlay.style.webkitOverflowScrolling = 'touch';
      overlay.setAttribute('data-kt-fit', 'scroll-y');
    } catch (e) {}
  }

  function fitDialog(dlg) {
    if (!mobile()) { return; }
    var changed = 0;
    try {
      fitHeight(dlg);
      /* DEEPEST FIRST. In document order a wrapper is reached before the row that is
         actually too wide, and the coarse repair for a wrapper then pre-empts the right
         repair for the row — the tab strip never got its sideways scroll because an
         ancestor had already forced its labels to wrap. Repairing the innermost cause
         first usually means the ancestors no longer overflow at all, which is why each
         one is measured again on its turn. */
      var nodes = [].slice.call(dlg.querySelectorAll('*'));
      nodes.forEach(function (n) {
        var depth = 0;
        for (var p = n; p && p !== dlg; p = p.parentElement) { depth++; }
        n.__ktDepth = depth;
      });
      nodes.sort(function (a, b) { return b.__ktDepth - a.__ktDepth; });
      for (var i = 0; i < nodes.length; i++) {
        var n = nodes[i];
        if (!overflowsX(n)) { continue; }
        var cs = window.getComputedStyle(n);
        if (/auto|scroll/.test(cs.overflowX)) {
          /* Already scrolls sideways on its own — the child record's tab strip is
             written that way — so the content is reachable and nothing needs fixing.
             It is tagged anyway, only so it inherits the same hidden scrollbar: a
             16px scroll track drawn across a dialog on a phone reads as breakage. */
          if (!n.getAttribute('data-kt-fit')) { n.setAttribute('data-kt-fit', 'scroll-x'); }
          continue;
        }
        if (fitElement(n)) { changed++; }
      }
      /* An input carries an intrinsic width from its size attribute that no parent
         rule reaches, so it is the one thing set without measuring first. */
      var fields = dlg.querySelectorAll('input,select,textarea');
      for (var j = 0; j < fields.length; j++) {
        var fl = fields[j];
        if (!fl.style.maxWidth) { fl.style.maxWidth = '100%'; }
        /* max-width alone does not shrink a field: an input carries an intrinsic
           minimum from its size attribute, and that is what wins in a narrow grid
           cell. min-width:0 is the half that actually lets it fit. */
        if (!fl.style.minWidth) { fl.style.minWidth = '0'; }
      }

      /* A FIELD TOO NARROW TO READ ITS OWN VALUE.

         The pass above measures each box against its parent, and by that test a grid
         whose children all shrank is fine. It is not: three phone fields at 1fr each
         came out 78px wide holding a 121px number, so half of every number was out of
         sight. The box fits and the CONTENT does not, which is the same truncation
         one level down.

         So a field that cannot show its own value asks its grid for fewer columns.
         auto-fit works out how many actually fit rather than assuming one. */
      for (var q = 0; q < fields.length; q++) {
        var fd = fields[q];
        if (fd.scrollWidth - fd.clientWidth <= SLACK) { continue; }
        if (fd.tagName === 'TEXTAREA') { continue; }        // scrolls by design
        var g = fd.parentElement;
        while (g && g !== dlg && window.getComputedStyle(g).display.indexOf('grid') === -1) {
          g = g.parentElement;
        }
        if (!g || g === dlg || g.getAttribute('data-kt-fit')) { continue; }
        g.style.gridTemplateColumns = 'repeat(auto-fit,minmax(' + MIN_COL + 'px,1fr))';
        g.setAttribute('data-kt-fit', 'grid-roomier');
        changed++;
      }
    } catch (e) {}
    return changed;
  }

  /* One injected rule, for the one thing an inline style cannot express: WebKit hides
     its scrollbar only through a pseudo-element. A swipeable tab strip with a scrollbar
     sitting under the tabs on a phone looks like a mistake. */
  function installCss() {
    if (document.getElementById('kt-dialog-fit-css')) { return; }
    var st = document.createElement('style');
    st.id = 'kt-dialog-fit-css';
    st.textContent = '[data-kt-fit="scroll-x"]::-webkit-scrollbar{display:none}'
      + '[data-kt-fit="scroll-x"]{-webkit-overflow-scrolling:touch;scroll-behavior:smooth}';
    (document.head || document.documentElement).appendChild(st);
  }

  var pending = 0;
  function sweep() {
    pending = 0;
    if (!mobile()) { return; }
    var dlgs = openDialogs();
    for (var i = 0; i < dlgs.length; i++) { fitDialog(dlgs[i]); }
  }
  /* Coalesced with a timeout, NOT requestAnimationFrame: this portal runs in a WebView
     that is frequently document.hidden, where rAF never fires and a scan queued on it
     never runs at all. */
  /* A second pass a beat later. A record dialog opens EMPTY and fills from the API, and
     the row that does not fit usually arrives with that data — by which time the burst
     of mutations that opened the dialog has long since been coalesced away.

     It carries its OWN guard. Sharing `pending` would have let a burst of fifty
     mutations queue fifty of these, all landing together: the coalescing that makes the
     first pass cheap would have been paid back in full by the second. */
  var trailing = 0;
  function schedule() {
    if (!pending) { pending = window.setTimeout(sweep, 100); }
    if (!trailing) {
      trailing = window.setTimeout(function () { trailing = 0; sweep(); }, 700);
    }
  }

  function start() {
    if (!document.body) { return window.setTimeout(start, 50); }
    installCss();
    try {
      /* Opening a dialog, switching a tab inside one and loading its content are all
         subtree changes, so the whole body is watched rather than each dialog: a dialog
         that has not been created yet cannot be observed directly. */
      new MutationObserver(schedule).observe(document.body, { childList: true, subtree: true });
    } catch (e) {
      window.setInterval(sweep, 1200);
    }
    window.addEventListener('resize', schedule);
    window.addEventListener('orientationchange', schedule);
    schedule();
  }
  start();

  try {
    window.KT = window.KT || {};
    window.KT.dialogFit = { sweep: sweep, fit: fitDialog, openDialogs: openDialogs };
  } catch (e) {}
})();
