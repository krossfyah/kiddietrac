/* ═══════════════════════════════════════════════════════════════════
   KiddieTrac — desktop table row actions → ⋮ kebab overflow menu (2026-08-05).
   Every data-table row's action buttons (the last <td>) collapse into a single
   ⋮ kebab. Clicking it opens a popup listing each action as icon + label;
   selecting one forwards a REAL click to the original (hidden) button, so all
   existing per-button handlers AND container delegation keep working untouched.

   • Desktop / tablet only (≥601px). Phones keep the card layout from
     kt-mobile-tables.js (which cards tables at ≤600px) — on mobile we RESTORE
     the original buttons so nothing changes there.
   • Idempotent + reversible: safe to re-run on every render (kt-sweep-bus) and
     on breakpoint changes (resize between phone/desktop widths).
   • Forwarding via el.click() reproduces a genuine bubbling click with the right
     target, so it fires whether the action is wired directly on the button or
     delegated on a parent container.
   ═══════════════════════════════════════════════════════════════════ */
(function (w, d) {
  'use strict';
  var KT = w.KT || (w.KT = {});
  if (KT.rowActionsLoaded) return;
  KT.rowActionsLoaded = true;

  var DESKTOP = w.matchMedia('(min-width: 601px)');
  // Actions whose label/intent is destructive → shown in red in the menu.
  var DESTRUCTIVE = /(delet|remov|\bdeny\b|reject|declin|revok|\bcancel\b|withdraw|deactiv|trash|\bblock\b|unassign|archive|discard|terminat|\bvoid\b)/i;
  var openMenu = null; // { menu, kebab }

  /* ---- one-time styles ------------------------------------------------ */
  (function injectCSS() {
    if (d.getElementById('kt-ka-css')) return;
    var s = d.createElement('style');
    s.id = 'kt-ka-css';
    s.textContent = [
      '.kt-ka-kebab{display:inline-flex;align-items:center;justify-content:center;width:32px;height:28px;min-height:0!important;padding:0!important;margin:0!important;border:1px solid #E2E8F0!important;border-radius:8px!important;background:#fff!important;color:#475569!important;cursor:pointer;font-size:18px;line-height:1;vertical-align:middle;transition:background .12s,border-color .12s;}',
      '.kt-ka-kebab:hover{background:#F1F5F9!important;border-color:#CBD5E1!important;}',
      '.kt-ka-kebab[aria-expanded="true"]{background:#E2E8F0!important;border-color:#94A3B8!important;}',
      '.kt-ka-hidden{display:none!important;}',
      '.kt-ka-menu{position:fixed;z-index:2147483000;min-width:172px;max-width:288px;background:#fff;border:1px solid #E2E8F0;border-radius:12px;box-shadow:0 12px 32px rgba(15,23,42,.18);padding:6px;font:500 14px/1.2 system-ui,-apple-system,"Segoe UI",sans-serif;color:#1F2937;animation:kt-ka-in .11s ease-out;}',
      '@keyframes kt-ka-in{from{opacity:0;transform:translateY(-4px)}to{opacity:1;transform:none}}',
      '.kt-ka-item{display:flex;align-items:center;gap:10px;width:100%;box-sizing:border-box;text-align:left;background:none;border:0;border-radius:8px;padding:9px 12px;cursor:pointer;color:inherit;font:inherit;min-height:0!important;}',
      '.kt-ka-item:hover,.kt-ka-item:focus{background:#F1F5F9;outline:none;}',
      '.kt-ka-item.kt-ka-danger{color:#B91C1C;}',
      '.kt-ka-item.kt-ka-danger:hover,.kt-ka-item.kt-ka-danger:focus{background:#FEE2E2;}',
      '.kt-ka-item[disabled]{opacity:.45;cursor:not-allowed;}',
      '.kt-ka-ico{flex:0 0 auto;width:20px;text-align:center;font-size:15px;}',
      '.kt-ka-lbl{flex:1 1 auto;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}'
    ].join('');
    (d.head || d.documentElement).appendChild(s);
  })();

  /* ---- helpers -------------------------------------------------------- */
  // Fallback icons for common actions rendered as plain text (no emoji of their
  // own), so every menu item reads as icon + label consistently.
  function fallbackIcon(label) {
    var t = label.toLowerCase();
    if (/delet|remov|trash|discard/.test(t)) return '🗑️';
    if (/\bedit\b|rename|modif|updat/.test(t)) return '✏️';
    if (/view|open|details|preview|\bsee\b/.test(t)) return '👁️';
    if (/approv|accept|confirm|\bmark\b.*(done|complete)|complete/.test(t)) return '✅';
    if (/deny|reject|declin/.test(t)) return '⛔';
    if (/revok|deactiv|disable|suspend|block/.test(t)) return '🚫';
    if (/download|export/.test(t)) return '⬇️';
    if (/upload|import/.test(t)) return '⬆️';
    if (/print/.test(t)) return '🖨️';
    if (/send|email|notify|remind/.test(t)) return '✉️';
    if (/\bpay\b|invoice|charge|refund/.test(t)) return '💳';
    if (/duplicat|\bcopy\b|clone/.test(t)) return '📋';
    if (/\badd\b|\bnew\b|creat|invite/.test(t)) return '➕';
    if (/resend|renew|refresh|reload|restore|reactiv/.test(t)) return '🔄';
    if (/assign|manage|configure|settings/.test(t)) return '⚙️';
    if (/message|chat|reply|comment/.test(t)) return '💬';
    if (/withdraw/.test(t)) return '📤';
    if (/archive/.test(t)) return '🗄️';
    if (/\bcancel\b/.test(t)) return '✖️';
    return '';
  }

  // The reverse of fallbackIcon(): a button that is ONLY an icon, with no label on
  // it anywhere, still has to read as something in a text menu. "Deactivate" beats
  // "Action" even if the original wording was "Deactivate form".
  var ICON_LABEL = [
    ['🗑', 'Delete'], ['✏', 'Edit'], ['📝', 'Edit'], ['👁', 'View'], ['🔍', 'View'],
    ['⏸', 'Deactivate'], ['▶', 'Activate'], ['🚫', 'Deactivate'], ['✅', 'Approve'],
    ['⛔', 'Reject'], ['✖', 'Cancel'], ['❌', 'Cancel'], ['⬇', 'Download'], ['📥', 'Download'],
    ['⬆', 'Upload'], ['📤', 'Send'], ['🖨', 'Print'], ['✉', 'Email'], ['📧', 'Email'],
    ['💬', 'Message'], ['🔄', 'Refresh'], ['⚙', 'Manage'], ['➕', 'Add'], ['📋', 'Copy'],
    ['🗄', 'Archive'], ['💳', 'Payment'], ['📄', 'View document'], ['📎', 'Attachment'],
    ['🔒', 'Lock'], ['🔓', 'Unlock'], ['⭐', 'Feature'], ['📅', 'Schedule'],
  ];
  function labelFromIcon(icon) {
    if (!icon) return '';
    for (var i = 0; i < ICON_LABEL.length; i++) {
      if (icon.indexOf(ICON_LABEL[i][0]) !== -1) return ICON_LABEL[i][1];
    }
    return '';
  }

  // Pull an icon (leading emoji/symbol) + a readable label out of a button.
  function parse(el) {
    var raw = (el.textContent || '').replace(/\s+/g, ' ').trim();
    // Every place a sweep might have moved the label to. kt-icon-buttons.js puts it
    // in data-kttip when it swaps the text for an icon; kt-tooltip-global.js strips
    // the native title on first hover and keeps it in data-kttip / data-kttip-title.
    // Reading only aria-label and title is why items read "Action" — and why the
    // same menu could show one correct label beside two wrong ones, depending on
    // which buttons had been swept or hovered.
    var aria = (el.getAttribute('data-kttip') || el.getAttribute('data-kttip-title')
      || el.getAttribute('data-kt-tooltip') || el.getAttribute('aria-label')
      || el.getAttribute('title') || el.getAttribute('data-kt-label') || '').trim();
    var icon = '', label = '';
    if (raw && !/[0-9A-Za-z]/.test(raw)) {           // emoji-only button (e.g. 🗑️)
      icon = raw; label = aria;
    } else {
      var m = raw.match(/^([^0-9A-Za-z]+?)\s*([0-9A-Za-z].*)$/); // "🗑️ Delete"
      if (m) { icon = m[1].trim(); label = m[2].trim(); }
      else { label = raw; }
    }
    if (!label) label = aria || labelFromIcon(icon) || labelFromIcon(raw) || 'Action';
    if (!icon) icon = fallbackIcon(label);           // consistent icon for text-only actions
    return { icon: icon, label: label };
  }

  function isAction(el) {
    if (el.classList && el.classList.contains('kt-ka-kebab')) return false;
    if (el.tagName === 'BUTTON') return true;
    if (el.tagName === 'A') return el.hasAttribute('href') || el.hasAttribute('data-id') || el.hasAttribute('onclick');
    return el.getAttribute && el.getAttribute('role') === 'button';
  }

  function actionsInCell(cell) {
    var els = cell.querySelectorAll('button, a, [role="button"]');
    var out = [];
    for (var i = 0; i < els.length; i++) if (isAction(els[i])) out.push(els[i]);
    return out;
  }

  // The actions cell = the LAST <td> of the row, and only if it holds actions.
  // Conservative on purpose: never hunts earlier columns, so data links (a name
  // linking to a detail page, etc.) are never swallowed into the menu.
  function rowActionCell(tr) {
    var kids = tr.children;
    for (var i = kids.length - 1; i >= 0; i--) {
      var c = kids[i];
      if (c.tagName !== 'TD') continue;             // skip trailing <th>, etc.
      return actionsInCell(c).length ? c : null;    // decide on the last <td> only
    }
    return null;
  }

  // Card-list support (screens like Waitlist that render rows as <div>s inside a
  // [data-kt-list] container instead of a <table>). The action bar is the row's
  // LAST element child, and only when it's a PURE bar of action controls — every
  // element child is a button/link. That keeps content columns (a name, a notes
  // blurb) from ever being mistaken for actions.
  function isActionsBar(el) {
    if (!el || el.nodeType !== 1) return false;
    var kids = el.children, acts = 0, other = 0;
    for (var i = 0; i < kids.length; i++) {
      var k = kids[i];
      if (k.classList && k.classList.contains('kt-ka-kebab')) continue;
      if (isAction(k)) acts++; else other++;
    }
    return acts >= 1 && other === 0;
  }

  function cardActionCell(row) {
    if (!row || row.nodeType !== 1) return null;
    for (var i = row.children.length - 1; i >= 0; i--) {
      var c = row.children[i];
      if (c.nodeType !== 1) continue;
      return isActionsBar(c) ? c : null;            // decide on the last element child only
    }
    return null;
  }

  /* ---- menu ----------------------------------------------------------- */
  function onKey(e) {
    if (!openMenu) return;
    if (e.key === 'Escape') {
      e.stopPropagation();
      var k = openMenu.kebab; closeMenu(); if (k) k.focus();
    } else if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
      e.preventDefault();
      var items = [].slice.call(openMenu.menu.querySelectorAll('.kt-ka-item:not([disabled])'));
      if (!items.length) return;
      var idx = items.indexOf(d.activeElement);
      idx = e.key === 'ArrowDown' ? (idx + 1) % items.length : (idx - 1 + items.length) % items.length;
      items[idx].focus();
    }
  }

  function closeMenu() {
    if (!openMenu) return;
    try { openMenu.menu.remove(); } catch (e) {}
    if (openMenu.kebab) openMenu.kebab.setAttribute('aria-expanded', 'false');
    openMenu = null;
    d.removeEventListener('keydown', onKey, true);
  }

  function openFor(kebab, actions) {
    closeMenu();
    var menu = d.createElement('div');
    menu.className = 'kt-ka-menu';
    menu.setAttribute('role', 'menu');

    actions.forEach(function (el) {
      var info = parse(el);
      var it = d.createElement('button');
      it.type = 'button';
      it.className = 'kt-ka-item';
      it.setAttribute('role', 'menuitem');
      var hay = info.label + ' ' + (el.getAttribute('data-act') || '') + ' ' +
        (el.getAttribute('data-action') || '') + ' ' + (el.getAttribute('aria-label') || '') + ' ' +
        (el.className || '');
      if (DESTRUCTIVE.test(hay)) it.className += ' kt-ka-danger';
      var ico = d.createElement('span'); ico.className = 'kt-ka-ico'; ico.textContent = info.icon || '';
      var lbl = d.createElement('span'); lbl.className = 'kt-ka-lbl'; lbl.textContent = info.label;
      it.appendChild(ico); it.appendChild(lbl);
      if (el.disabled) it.disabled = true;
      it.addEventListener('click', function (ev) {
        ev.preventDefault(); ev.stopPropagation();
        if (el.disabled) return;
        closeMenu();
        // Forward a genuine click to the original (hidden) control on the next
        // tick — fires its own handler and any delegated container handler.
        setTimeout(function () { try { el.click(); } catch (e) {} }, 0);
      });
      menu.appendChild(it);
    });

    d.body.appendChild(menu);

    // Position: right-aligned under the kebab, flip above if it would overflow.
    var r = kebab.getBoundingClientRect();
    var mw = menu.offsetWidth, mh = menu.offsetHeight;
    var left = Math.min(r.right - mw, w.innerWidth - mw - 8);
    left = Math.max(8, left);
    var top = r.bottom + 6;
    if (top + mh > w.innerHeight - 8) top = r.top - mh - 6;
    if (top < 8) top = 8;
    menu.style.left = Math.round(left) + 'px';
    menu.style.top = Math.round(top) + 'px';

    kebab.setAttribute('aria-expanded', 'true');
    openMenu = { menu: menu, kebab: kebab };
    d.addEventListener('keydown', onKey, true);
    var first = menu.querySelector('.kt-ka-item:not([disabled])');
    if (first) first.focus();
  }

  /* ---- transform / restore ------------------------------------------- */
  function kebabify(cell) {
    if (cell.getAttribute('data-kt-ka')) return;
    var actions = actionsInCell(cell);
    if (!actions.length) return;
    actions.forEach(function (el) {
      el.classList.add('kt-ka-hidden');
      // Inline display:none !important beats class rules like .kt-btn{display:…!important},
      // which otherwise left the raw action buttons visible NEXT TO the kebab.
      el.style.setProperty('display', 'none', 'important');
    });

    var kebab = d.createElement('button');
    kebab.type = 'button';
    kebab.className = 'kt-ka-kebab';
    kebab.setAttribute('aria-haspopup', 'true');
    kebab.setAttribute('aria-expanded', 'false');
    kebab.setAttribute('aria-label', 'Actions');
    kebab.title = 'Actions';
    kebab.textContent = '⋮';
    kebab.addEventListener('click', function (ev) {
      ev.preventDefault(); ev.stopPropagation();
      if (openMenu && openMenu.kebab === kebab) { closeMenu(); return; }
      openFor(kebab, actionsInCell(cell)); // re-read live so conditional actions stay current
    });
    cell.appendChild(kebab);
    cell.setAttribute('data-kt-ka', '1');
  }

  function restore(cell) {
    var kb = cell.querySelector('.kt-ka-kebab');
    if (kb) kb.remove();
    var hidden = cell.querySelectorAll('.kt-ka-hidden');
    for (var i = 0; i < hidden.length; i++) { hidden[i].classList.remove('kt-ka-hidden'); hidden[i].style.removeProperty('display'); }
    cell.removeAttribute('data-kt-ka');
  }

  /* ---- sweep ---------------------------------------------------------- */
  function sweep() {
    var main = d.getElementById('appMain');
    if (!main) return;

    if (!DESKTOP.matches) {
      // Phone width — undo everything so kt-mobile-tables' card layout is clean.
      var done = main.querySelectorAll('[data-kt-ka]');
      for (var j = 0; j < done.length; j++) restore(done[j]);
      closeMenu();
      return;
    }

    var rows = main.querySelectorAll('table tr');
    for (var i = 0; i < rows.length; i++) {
      // Opt-out, same attribute the card-list path below already honours. It was
      // only ever checked for [data-kt-list], so a TABLE marked data-kt-no-kebab
      // was still kebabified — and a screen that draws its own ⋮ (Forms Manager's
      // Completed tab) ended up with a kebab inside a kebab.
      var ownerTable = rows[i].closest ? rows[i].closest('table') : null;
      if (ownerTable && ownerTable.hasAttribute('data-kt-no-kebab')) continue;
      var cell = rowActionCell(rows[i]);
      if (cell) kebabify(cell);
    }
    // Card-list rows (Waitlist, etc.) — same kebab treatment on their action bar.
    var lists = main.querySelectorAll('[data-kt-list]');
    for (var li = 0; li < lists.length; li++) {
      // Opt-out: a list marked data-kt-no-kebab keeps its buttons inline (e.g. the
      // educator Today roster, where "Check in" must be a one-tap primary button).
      if (lists[li].hasAttribute('data-kt-no-kebab')) continue;
      var crows = lists[li].children;
      for (var ri = 0; ri < crows.length; ri++) {
        var ccell = cardActionCell(crows[ri]);
        if (ccell) kebabify(ccell);
      }
    }
    // If the row/kebab behind an open menu got re-rendered away, drop the menu.
    if (openMenu && !d.contains(openMenu.kebab)) closeMenu();
  }

  /* ---- wiring --------------------------------------------------------- */
  // Public hook so screens that re-render in place (Waitlist changing centre,
  // etc.) can force an immediate re-sweep instead of waiting for the bus/interval.
  KT.sweepRowActions = function () { try { sweep(); } catch (e) {} };
  if (KT.sweepBus) KT.sweepBus.on(sweep); else setInterval(sweep, 4000);
  if (DESKTOP.addEventListener) DESKTOP.addEventListener('change', sweep);
  else if (DESKTOP.addListener) DESKTOP.addListener(sweep); // older WebView
  w.addEventListener('hashchange', function () { setTimeout(sweep, 140); });

  // Dismiss the popup on outside interaction / scroll / resize.
  d.addEventListener('mousedown', function (e) {
    if (!openMenu) return;
    var t = e.target;
    if (t.closest && (t.closest('.kt-ka-menu') || t.closest('.kt-ka-kebab'))) return;
    closeMenu();
  }, true);
  w.addEventListener('scroll', function () { if (openMenu) closeMenu(); }, true);
  w.addEventListener('resize', function () { if (openMenu) closeMenu(); });

  setTimeout(sweep, 400);
})(window, document);
