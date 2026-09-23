/* ═══════════════════════════════════════════════════════════════════════════
   KiddieTrac — readable dropdowns on phones and in the APK.

   THE BUG. On Android the <option> list is not drawn by the page at all: the
   WebView hands the choices to Android, which draws its own dialog using the
   APP's theme colours. On a device in dark mode that dialog came out with a
   light background and WHITE text — invisible. kt-select-fix.css asks for
   `color-scheme: light only`, which is the right thing to ask for and still
   loses, because that dialog is not ours to style. No amount of CSS can fix it.

   THE FIX. On touch devices we draw the list ourselves, in the page, where our
   colours actually apply. The real <select> stays exactly where it is and stays
   the source of truth — we set its value and fire `change`, so every screen that
   listens for a change keeps working and no calling code has to change.

   Desktop is left alone: a mouse-driven <select> renders fine and people expect
   the native one.
   ═══════════════════════════════════════════════════════════════════════════ */
(function (w, d) {
  'use strict';
  var KT = w.KT || (w.KT = {});
  if (KT.selectSheetLoaded) return;
  KT.selectSheetLoaded = true;

  /* Touch-primary only. `pointer: coarse` is true in the APK and on phones and
     tablets, and false for a mouse — which is exactly the line we care about. */
  function coarse() {
    try { return w.matchMedia && w.matchMedia('(pointer: coarse)').matches; }
    catch (e) { return false; }
  }
  if (!coarse()) return;

  // Above everything, including the portal's own modals at z-index 99999 — a
  // <select> inside a dialog has to open on top of that dialog.
  var Z = 2147483000;

  function eligible(sel) {
    if (!sel || sel.tagName !== 'SELECT') return false;
    if (sel.disabled || sel.multiple) return false;
    if (sel.size > 1) return false;                              // already a list box
    if (sel.hasAttribute('data-kt-native-select')) return false; // explicit opt-out
    if (!sel.options || !sel.options.length) return false;
    return true;
  }

  var openSheet = null;

  /* When the sheet opened. A tap on Android is three events — pointerdown, mousedown,
     then click — and the click is delivered to whatever sits under the finger AT THAT
     MOMENT, which by then is the sheet this very tap just opened. Without this guard the
     trailing click landed on the backdrop and shut the sheet again, so tapping a dropdown
     looked like it did nothing at all. Everything the sheet does ignores that first
     moment. */
  var openedAt = 0;

  /* Has the finger gone DOWN inside the sheet since it opened? That is what separates
     the opening tap's stray trailing click from a real choice, and it is the only
     reliable difference: the opening tap's pointerdown went to the <select> before this
     sheet existed, so a click arriving with no press inside is that stray one. A genuine
     tap on a row always begins with a press on the row.

     Time alone cannot tell them apart. The window below used to be the whole test, and
     450ms is far longer than the ~50-100ms a synthetic click takes, so a fast deliberate
     tap was swallowed too — measured on a phone, tapping the second row within a second
     of the screen painting, which is simply what everyone does with a dropdown whose
     first entry is already selected. (Anthony, 2026-09-07) */
  var sawPressInside = false;
  function fromOpeningTap() { return !sawPressInside && (Date.now() - openedAt) < 450; }

  function close() {
    if (!openSheet) return;
    var el = openSheet;
    openSheet = null;
    el.style.opacity = '0';
    var panel = el.firstChild;
    if (panel) panel.style.transform = 'translateY(14px)';
    setTimeout(function () { if (el.parentNode) el.parentNode.removeChild(el); }, 170);
    d.removeEventListener('keydown', onKey, true);
  }

  function onKey(e) { if (e.key === 'Escape') { e.stopPropagation(); close(); } }

  function labelFor(sel) {
    // Whatever names this control on screen — its <label>, or the little caption
    // sitting above it — so the sheet says what is being chosen.
    try {
      var byFor = sel.id && d.querySelector('label[for="'
        + (w.CSS && CSS.escape ? CSS.escape(sel.id) : sel.id) + '"]');
      var prev = sel.previousElementSibling;
      var lab = byFor
        || (sel.closest ? sel.closest('label') : null)
        || (prev && /^(LABEL|DIV|SPAN)$/.test(prev.tagName) ? prev : null);
      if (lab) {
        var t = (lab.textContent || '').trim();
        if (t && t.length <= 60) return t;
      }
    } catch (e) { /* no label is fine */ }
    return sel.getAttribute('aria-label') || 'Choose';
  }

  function open(sel) {
    close();

    var wrap = d.createElement('div');
    wrap.className = 'kt-select-sheet';
    wrap.setAttribute('data-no-modal-guard', '1');
    wrap.style.cssText = 'position:fixed;inset:0;z-index:' + Z + ';background:rgba(15,23,42,.5);'
      + 'display:flex;align-items:flex-end;justify-content:center;opacity:0;'
      + 'transition:opacity .17s ease;-webkit-tap-highlight-color:transparent;';

    var panel = d.createElement('div');
    // Every colour is stated outright. Inheriting is what made the native dialog
    // unreadable in the first place.
    panel.style.cssText = 'background:#fff;color:#0F172A;width:100%;max-width:560px;'
      + 'border-radius:18px 18px 0 0;max-height:78vh;display:flex;flex-direction:column;'
      + 'box-shadow:0 -8px 30px rgba(15,23,42,.28);transform:translateY(14px);'
      + 'transition:transform .17s ease;padding-bottom:var(--kt-safe-bottom, env(safe-area-inset-bottom,0px));';

    var head = d.createElement('div');
    head.style.cssText = 'padding:15px 18px 11px;border-bottom:1px solid #E2E8F0;display:flex;'
      + 'align-items:center;gap:12px;flex:0 0 auto;';

    var title = d.createElement('div');
    title.style.cssText = 'flex:1;min-width:0;font-size:15px;font-weight:700;color:#0F172A;'
      + 'overflow:hidden;text-overflow:ellipsis;white-space:nowrap;';
    title.textContent = labelFor(sel);
    head.appendChild(title);

    var x = d.createElement('button');
    x.type = 'button';
    x.setAttribute('aria-label', 'Close');
    x.textContent = '✕';
    x.style.cssText = 'flex:0 0 auto;width:34px;height:34px;border-radius:50%;'
      + 'border:1px solid #E2E8F0;background:#F8FAFC;color:#475569;font-size:15px;'
      + 'line-height:1;cursor:pointer;';
    x.addEventListener('click', function (e) { e.preventDefault(); close(); });
    head.appendChild(x);

    var list = d.createElement('div');
    list.style.cssText = 'overflow-y:auto;-webkit-overflow-scrolling:touch;flex:1 1 auto;'
      + 'padding:6px 0 10px;';

    var groupOf = null;
    for (var i = 0; i < sel.options.length; i++) {
      var o = sel.options[i];

      // Repeat the optgroup heading so a grouped list still reads as grouped.
      var g = (o.parentNode && o.parentNode.tagName === 'OPTGROUP') ? o.parentNode.label : null;
      if (g && g !== groupOf) {
        groupOf = g;
        var gh = d.createElement('div');
        gh.style.cssText = 'padding:12px 18px 5px;font-size:11.5px;font-weight:800;'
          + 'letter-spacing:.5px;text-transform:uppercase;color:#94A3B8;';
        gh.textContent = g;
        list.appendChild(gh);
      }

      var on = (i === sel.selectedIndex);

      /* An option that carries its own colours means something by them — the
         attendance grid tints each rotation so a week can be read by colour. The
         native list shows those; this one has to as well, or the same control looks
         different on a phone than it does on a desktop. */
      var ownBg = (o.style && o.style.backgroundColor) || '';
      var ownFg = (o.style && o.style.color) || '';

      var row = d.createElement('button');
      row.type = 'button';
      row.setAttribute('data-idx', String(i));
      row.disabled = !!o.disabled;
      row.style.cssText = 'display:flex;width:100%;box-sizing:border-box;align-items:center;'
        + 'gap:12px;padding:14px 18px;border:0;text-align:left;font-size:15.5px;'
        + 'font-family:inherit;cursor:pointer;'
        + 'background:' + (on ? '#F0F9FF' : 'transparent') + ';'
        + 'color:' + (o.disabled ? '#94A3B8' : '#0F172A') + ';'
        + 'font-weight:' + (on ? '700' : '500') + ';';

      var txt = d.createElement('span');
      txt.style.cssText = 'flex:1;min-width:0;';
      txt.textContent = o.textContent;   // textContent, so a label cannot inject markup
      // Shown as a chip rather than by painting the whole row: a full-width wash of
      // pale colour behind 15px text is harder to read, not easier.
      if (ownBg && !o.disabled) {
        txt.style.display = 'inline-block';
        txt.style.flex = '0 1 auto';
        txt.style.background = ownBg;
        txt.style.color = ownFg || '#0F172A';
        txt.style.padding = '4px 12px';
        txt.style.borderRadius = '999px';
        txt.style.fontWeight = on ? '800' : '600';
        // Not the current choice = dimmed, matching how the native list reads.
        txt.style.opacity = on ? '1' : '.7';
      }
      row.appendChild(txt);

      // Keep the tick hard against the right edge when the label is a chip.
      if (ownBg && !o.disabled) {
        var spacer = d.createElement('span');
        spacer.style.cssText = 'flex:1 1 auto;';
        row.appendChild(spacer);
      }

      var tick = d.createElement('span');
      tick.style.cssText = 'flex:0 0 auto;width:20px;height:20px;border-radius:50%;'
        + 'box-sizing:border-box;'
        + 'border:2px solid ' + (on ? '#159FB4' : '#CBD5E1') + ';'
        + 'background:' + (on ? '#159FB4' : 'transparent') + ';'
        + 'box-shadow:' + (on ? 'inset 0 0 0 3px #fff' : 'none') + ';';
      row.appendChild(tick);

      /* THE ROW OWNS ITS CLICK. Not delegation from the list: resolving the row with
         closest(e.target) assumes nothing has reshaped the DOM between dispatch and
         bubble, and on the device it had — the tap was seen on row 1 at document capture
         and closest() returned null a moment later at the list, because a sweep replaced
         the button's contents mid-click and orphaned the node the event was aimed at.
         `idx` is closed over rather than read back from the attribute, so a sweep that
         strips data-idx cannot break it either. (Anthony, 2026-09-07) */
      (function (thisRow, thisIdx) {
        thisRow.addEventListener('click', function (ev) { commitRow(thisRow, thisIdx, ev); });
      }(row, i));

      list.appendChild(row);
    }

    function commitRow(row, idx, e) {
      if (fromOpeningTap()) { return; }   // the tap that opened this, still travelling
      if (row.disabled) { return; }
      e.preventDefault();

      /* COMMIT AGAINST THE SELECT THAT IS ACTUALLY ON THE PAGE.

         A screen can be rebuilt while this sheet is open on top of it — several are on a
         refresh timer — which replaces the <select> we captured at open() with an
         identical new one. Setting the value on the old element then fires `change` from
         a node that is no longer in the document: nobody is listening, the visible
         control still shows the previous choice, and the tap is thrown away. That is
         indistinguishable from a dropdown that does nothing.

         KT.uiBusy() now names this sheet so a refresh is deferred while it is open, but
         uiBusy only defers work that has not started yet — a refresh already in flight
         when the sheet opened still lands. So re-resolve here as well. Only when the id
         and the option count both match, otherwise keep the original and behave exactly
         as before. */
      var target = sel;
      try {
        if (sel.id && !d.contains(sel)) {
          var live = d.getElementById(sel.id);
          if (live && live.tagName === 'SELECT' && live.options.length === sel.options.length) {
            target = live;
          }
        }
      } catch (e) { target = sel; }

      if (target.selectedIndex !== idx) {
        target.selectedIndex = idx;
        // The real control is still the source of truth, so anything already
        // listening — validation, dependent dropdowns, autosave — just works.
        target.dispatchEvent(new Event('input', { bubbles: true }));
        target.dispatchEvent(new Event('change', { bubbles: true }));
      }
      close();
    }

    wrap.addEventListener('click', function (e) {
      if (fromOpeningTap()) return;      // ditto — this is what was shutting it instantly
      if (e.target === wrap) close();
    });

    panel.appendChild(head);
    panel.appendChild(list);
    wrap.appendChild(panel);
    d.body.appendChild(wrap);
    openSheet = wrap;
    openedAt = Date.now();

    /* A press INSIDE this sheet means whatever click follows is the user's own, not the
       tail of the tap that opened it. pointerdown covers modern WebViews; touchstart and
       mousedown keep it true where PointerEvent is missing. Capture, so a handler that
       stops propagation cannot hide the press from us. */
    sawPressInside = false;
    var notePress = function () { sawPressInside = true; };
    wrap.addEventListener('pointerdown', notePress, true);
    wrap.addEventListener('touchstart', notePress, true);
    wrap.addEventListener('mousedown', notePress, true);

    // Bring the current choice into view before the sheet is visible, so it does
    // not visibly jump.
    var cur = list.querySelector('button[data-idx="' + sel.selectedIndex + '"]');
    if (cur && cur.offsetTop > list.clientHeight - 60) list.scrollTop = cur.offsetTop - 90;

    requestAnimationFrame(function () {
      wrap.style.opacity = '1';
      panel.style.transform = 'translateY(0)';
    });

    d.addEventListener('keydown', onKey, true);
  }

  /* Suppress the native popup. Android decides to open it on the pointer press, so that
     is where it has to be stopped — cancelling the later click is too late and the OS
     dialog has already appeared.

     ONE press event, not three. A single tap fires pointerdown, then mousedown, then
     click; opening on more than one of them built the sheet twice per tap, tearing down
     the first while the second went up. Pointer events cover mouse and touch alike, so
     where they exist they are the only press we listen to. The later events are still
     swallowed so nothing else reacts to them. */
  var PRESS = w.PointerEvent ? 'pointerdown' : 'mousedown';

  function intercept(e) {
    var sel = e.target && e.target.closest ? e.target.closest('select') : null;
    if (!eligible(sel)) return;
    e.preventDefault();
    e.stopPropagation();
    if (e.type !== PRESS) return;

    /* If drawing the sheet ever fails, hand this control back to the browser rather
       than leaving somebody with a dropdown that does nothing. An unreadable native
       list is a bad day; a dead one is a broken app. */
    try {
      open(sel);
    } catch (err) {
      sel.setAttribute('data-kt-native-select', '1');
      if (w.console && console.warn) {
        console.warn('[kt-select-sheet] falling back to the native list:', err);
      }
    }
  }

  d.addEventListener(PRESS, intercept, true);
  if (PRESS !== 'mousedown') { d.addEventListener('mousedown', intercept, true); }
  d.addEventListener('click', intercept, true);

  // Some WebViews still open the list from the keyboard or assistive input.
  d.addEventListener('keydown', function (e) {
    if (!eligible(e.target)) return;
    if (e.key === ' ' || e.key === 'Enter') { e.preventDefault(); open(e.target); }
  }, true);

  // The hardware back button should shut the sheet, not leave the screen.
  w.addEventListener('popstate', function () { if (openSheet) close(); });
})(window, document);
