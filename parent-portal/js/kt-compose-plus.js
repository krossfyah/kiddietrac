/* ═══════════════════════════════════════════════════════════════════
   KiddieTrac — THE ＋ BESIDE ➤ (2026-11-04).
   Anthony: "for chat system where it shows the emoji, microphone etc, add a + next to the send to be able to
   select these actions with a small popup - full sweep".

   Every chat's typing row is .kt-compose-row (kt-chat-composer.css). Here, once for all of them: the row's tool
   buttons (😊 📷 🎤 🚨 …) are tucked away and a round ＋ sits between the box and the ➤. The ＋ opens a small menu
   of those same tools; choosing one clicks the screen's OWN button, so every handler, emoji panel and voice
   recorder keeps working exactly as before (nothing is moved: the recorder anchors to the button's row, the emoji
   panel is positioned in the row).

   A tool is a direct child <button> of the row that is not the ➤ (.kt-send-one). Buttons a screen hides itself
   (style display:none / hidden / disabled) are left out of the menu or shown greyed. Rows are found as they are
   drawn (one body observer, once per frame) - new chats, re-renders and a 🚨 added after the fact included.
   A row can opt out with data-kt-no-plus.

   👋 NUDGE (2026-11-04, App\\Support\\ChatNudge). Anthony: "where is the nudge button on the chat system add this to
   next to the + and it should shake the screen, mobile app and make a sound that catches someones attention and send
   app notification". A screen names its nudge endpoint on the row (data-kt-nudge="/provider/chats/12/nudge", plus
   data-kt-nudge-body='{"child_id":5}' when the endpoint needs one) and a round 👋 appears just before the ＋. The
   server keeps the cooldown (one a minute by default, per conversation); the button counts it down.
   Receiving: the nudge's bell row (type 'nudge') reaches the open page through the badges feed (kt-sounds.js fires
   kt:notification and plays the 👋 Nudges tone); here the screen shakes, the phone vibrates and a banner says who,
   with Open. The app's own push (urgent, through Do Not Disturb unless the agency turned that off) rings when closed.
   ═══════════════════════════════════════════════════════════════════ */
(function (window, document) {
  'use strict';
  if (window.__ktComposePlus) return;
  window.__ktComposePlus = true;

  var openMenu = null;
  function KTApi() { return (window.KT && KT.Api) || null; }

  function label(b) {
    var t = b.getAttribute('aria-label') || b.getAttribute('title') || b.getAttribute('data-kttip')
      || b.getAttribute('data-kttip-title') || b.getAttribute('data-kt-tooltip') || '';
    t = String(t).trim();
    if (t) return t.charAt(0).toUpperCase() + t.slice(1);
    return (b.textContent || '').trim() || 'Action';
  }
  /* Floating shortcuts (2026-11-04, "should be a floating shortcuts popup"): each tool is a round tile with a
     one- or two-word name under it; the full wording stays the tile's aria-label. */
  var SHORT = [[/^nudge/i, 'Nudge'], [/^call/i, 'Call'], [/emoji/i, 'Emoji'], [/photo|video|image|camera|attach/i, 'Photo'], [/voice|record|mic/i, 'Voice note'],
    [/emergenc/i, 'Emergency'], [/file|document/i, 'File'], [/gif/i, 'GIF'], [/location/i, 'Location']];
  var TINT = ['#FEF3C7', '#DBEAFE', '#EDE9FE', '#FEE2E2', '#DCFCE7', '#FCE7F3', '#E0F2FE'];
  function shortLabel(full) {
    for (var i = 0; i < SHORT.length; i++) if (SHORT[i][0].test(full)) return SHORT[i][1];
    return full.split(/\s+/).slice(0, 2).join(' ');
  }
  function icon(b) {
    var svg = b.querySelector('svg');
    if (svg) return svg.outerHTML;
    var txt = (b.textContent || '').trim();
    return txt.length <= 4 ? txt.replace(/[<>&]/g, '') : '•';
  }
  function tools(row) {
    return Array.prototype.filter.call(row.children, function (el) {
      return el.tagName === 'BUTTON' && !el.classList.contains('kt-send-one') && !el.classList.contains('kt-plus-btn')
        && !el.classList.contains('kt-nudge-btn') && !el.classList.contains('kt-call-btn') && !el.classList.contains('kt-plus-inline');
    });
  }
  /* THE BAR (2026-11-13, Anthony's photo of a typing bar: "for the chat bar mimic to make this cleaner with kiddietrac -
     full sweep for desktop and mobile"). One rounded bar holds everything: [＋] [📎] [ box ........ ] [📞 👋] [➤], thin
     line icons (kt-line-icons.js), and under it on a computer "Enter to send · Shift+Enter for a new line". The
     attach tool (photo / file) stays on the bar beside the ＋; the other tools are in the ＋'s menu as before. */
  var ATTACH = /photo|video|image|picture|camera|attach|file/i;
  function bar(row) {
    if (row.hasAttribute('data-kt-no-bar')) return;
    if (!row.classList.contains('kt-bar')) row.classList.add('kt-bar');
    var box = row.querySelector(':scope > input.kt-compose-input, :scope > textarea.kt-compose-input, :scope > textarea#kt-st-input');
    var hint = !box || box.disabled || box.readOnly ? '' : (box.tagName === 'TEXTAREA' ? 'Enter to send · Shift+Enter for a new line' : 'Enter to send');
    if ((row.getAttribute('data-kt-hint') || '') !== hint) { if (hint) row.setAttribute('data-kt-hint', hint); else row.removeAttribute('data-kt-hint'); }
  }
  function inlineAttach(row) {
    if (row.querySelector(':scope > .kt-plus-inline')) return;
    var cands = Array.prototype.filter.call(row.children, function (el) {
      return el.tagName === 'BUTTON' && !el.classList.contains('kt-send-one') && !el.classList.contains('kt-plus-btn')
        && !el.classList.contains('kt-nudge-btn') && ATTACH.test(label(el));
    });
    if (cands.length) { cands[0].classList.remove('kt-plus-tool'); cands[0].classList.add('kt-plus-inline'); }
  }
  function usable(b) { return !b.hidden && b.style.display !== 'none'; }
  function narrow() { return window.matchMedia && matchMedia('(max-width: 480px)').matches; }

  function closeMenu(focusBack) {
    if (!openMenu) return;
    var m = openMenu; openMenu = null;
    m.menu.remove();
    m.btn.setAttribute('aria-expanded', 'false');
    m.btn.classList.remove('kt-plus-open');
    document.removeEventListener('pointerdown', onOutside, true);
    document.removeEventListener('keydown', onKey, true);
    window.removeEventListener('resize', onResize);
    if (focusBack) { try { m.btn.focus({ preventScroll: true }); } catch (e) {} }
  }
  function onOutside(e) {
    if (!openMenu) return;
    if (openMenu.menu.contains(e.target) || openMenu.btn.contains(e.target)) return;
    closeMenu(false);
  }
  function onResize() { closeMenu(false); }
  function onKey(e) {
    if (!openMenu) return;
    var items = Array.prototype.slice.call(openMenu.menu.querySelectorAll('[role="menuitem"]:not([disabled])'));
    var i = items.indexOf(document.activeElement);
    if (e.key === 'Escape') { e.preventDefault(); e.stopPropagation(); closeMenu(true); }
    else if (e.key === 'ArrowDown' || e.key === 'ArrowRight') { e.preventDefault(); (items[i + 1] || items[0]).focus(); }
    else if (e.key === 'ArrowUp' || e.key === 'ArrowLeft') { e.preventDefault(); (items[i - 1] || items[items.length - 1]).focus(); }
    else if (e.key === 'Tab') { closeMenu(false); }
  }

  function open(row, btn) {
    if (openMenu && openMenu.btn === btn) { closeMenu(true); return; }
    closeMenu(false);
    var list = tools(row).filter(usable);
    /* A phone's bar keeps only ＋ 📎 [box] ➤ (2026-11-13): 📞 and 👋 are in this menu there (kt-chat-composer.css
       hides them on the bar below 480px), so the box keeps room for a sentence. */
    if (narrow()) Array.prototype.forEach.call(row.querySelectorAll(':scope > .kt-call-btn, :scope > .kt-nudge-btn'), function (b) { if (usable(b) && list.indexOf(b) < 0) list.push(b); });
    if (!list.length) return;
    /* QUICK POPOVER (2026-11-24): this short menu of actions is KT.quickPop (kt-quickpop.js), the one quick-choice look. */
    if (window.KT && KT.quickPop) {
      btn.setAttribute('aria-expanded', 'true');
      btn.classList.add('kt-plus-open');
      KT.quickPop({ anchor: btn, label: 'Shortcuts', items: list.map(function (t) {
        var full = label(t);
        return { icon: icon(t), label: shortLabel(full), aria: full, disabled: t.disabled, run: function () { t.click(); } };   // same tap: a file picker may still open
      }), onClose: function () { btn.setAttribute('aria-expanded', 'false'); btn.classList.remove('kt-plus-open'); } });
      return;
    }
    var menu = document.createElement('div');
    menu.className = 'kt-plus-menu';
    menu.setAttribute('role', 'menu');
    menu.setAttribute('aria-label', 'Shortcuts');
    menu.setAttribute('data-kt-no-close', '1');
    menu.style.setProperty('--kt-plus-cols', String(Math.min(4, list.length)));
    menu.setAttribute('data-no-modal-guard', '1');
    list.forEach(function (t) {
      var it = document.createElement('button');
      it.type = 'button';
      it.className = 'kt-plus-item';
      it.setAttribute('role', 'menuitem');
      it.setAttribute('data-kt-iconized', '1');
      if (t.disabled) it.disabled = true;
      var full = label(t);
      it.setAttribute('aria-label', full);
      it.innerHTML = '<span class="kt-plus-ico" aria-hidden="true" style="background:' + TINT[menu.children.length % TINT.length] + '">' + icon(t) + '</span><span class="kt-plus-lbl"></span>';
      it.querySelector('.kt-plus-lbl').textContent = shortLabel(full);
      it.addEventListener('click', function (e) {
        // Kept from reaching the page: a screen's "click outside closes the emoji panel" would shut what this opens.
        e.preventDefault(); e.stopPropagation();
        closeMenu(false);
        t.click();   // in the same tap, so a file picker may still open
      });
      menu.appendChild(it);
    });
    document.body.appendChild(menu);
    // Above the ＋, left edges lined up (it starts the bar); below it when there is no room above; always inside the window.
    var r = btn.getBoundingClientRect(), mw = menu.offsetWidth, mh = menu.offsetHeight, vw = window.innerWidth, vh = window.innerHeight;
    var left = Math.min(Math.max(8, r.left), vw - mw - 8);
    var top = r.top - mh - 8;
    if (top < 8) top = Math.min(r.bottom + 8, vh - mh - 8);
    menu.style.left = Math.round(left) + 'px';
    menu.style.top = Math.round(Math.max(8, top)) + 'px';
    btn.setAttribute('aria-expanded', 'true');
    btn.classList.add('kt-plus-open');
    openMenu = { menu: menu, btn: btn };
    document.addEventListener('pointerdown', onOutside, true);
    document.addEventListener('keydown', onKey, true);
    window.addEventListener('resize', onResize);
    var first = menu.querySelector('[role="menuitem"]:not([disabled])');
    if (first && !(window.matchMedia && matchMedia('(pointer: coarse)').matches)) first.focus({ preventScroll: true });
  }

  // ── 👋 the nudge button ──
  var cool = {};   // endpoint -> time the cooldown ends (ms), so a re-drawn row keeps counting
  function nudgeTick(b) {
    var ep = b.getAttribute('data-ep'), left = Math.ceil(((cool[ep] || 0) - Date.now()) / 1000);
    var badge = b.querySelector('.kt-nudge-left');
    if (left > 0) {
      b.disabled = true;
      if (!badge) { badge = document.createElement('span'); badge.className = 'kt-nudge-left'; b.appendChild(badge); }
      badge.textContent = left >= 60 ? Math.ceil(left / 60) + 'm' : left + 's';
      b.setAttribute('data-kttip', 'You can nudge again in ' + (left >= 60 ? Math.ceil(left / 60) + ' min' : left + ' s'));
      clearTimeout(b.__t); b.__t = setTimeout(function () { if (b.isConnected) nudgeTick(b); }, 1000);
    } else {
      b.disabled = false;
      if (badge) badge.remove();
      b.setAttribute('data-kttip', 'Nudge');
    }
  }
  function sendNudge(row, b) {
    var ep = row.getAttribute('data-kt-nudge');
    var body = {};
    try { body = JSON.parse(row.getAttribute('data-kt-nudge-body') || '{}') || {}; } catch (e) {}
    if (!ep || !KTApi() || b.disabled) return;
    b.disabled = true;
    b.classList.remove('kt-nudge-wave'); void b.offsetWidth; b.classList.add('kt-nudge-wave');
    KTApi().post(ep, body).then(function (r) {
      cool[ep] = Date.now() + ((r && r.cooldown) || 60) * 1000;
      nudgeTick(b);
      if (window.KT && KT.toast) KT.toast('👋', 'Nudge sent', 'They will get a notification that rings through.', '#159FB4');
      try { row.dispatchEvent(new CustomEvent('kt:nudged', { bubbles: true, detail: r })); } catch (e) {}
    }).catch(function (e) {
      var d = (e && e.data) || {};
      if (d.retry_after) cool[ep] = Date.now() + d.retry_after * 1000;
      nudgeTick(b);
      if (window.KT && KT.toast) KT.toast('👋', d.retry_after ? 'Just a moment' : 'Could not send the nudge', (d.message || (e && e.message) || ''), d.retry_after ? '#B45309' : '#DC2626');
    });
  }
  function wireNudge(row, plus, send) {
    var ep = row.getAttribute('data-kt-nudge');
    var b = row.__ktNudge;
    if (!ep) { if (b) { b.remove(); row.__ktNudge = null; } if (row.__ktCall) { row.__ktCall.remove(); row.__ktCall = null; } return; }
    if (!b || b.parentNode !== row) {
      b = document.createElement('button');
      b.type = 'button';
      b.className = 'kt-nudge-btn';
      b.setAttribute('aria-label', 'Nudge - ring their phone to get their attention');
      b.setAttribute('data-kttip', 'Nudge');
      b.setAttribute('data-kt-iconized', '1');
      b.setAttribute('data-kt-nospin', '1');
      b.textContent = '👋';
      b.addEventListener('click', function (e) { e.preventDefault(); e.stopPropagation(); sendNudge(row, b); });
      row.__ktNudge = b;
    }
    if (b.getAttribute('data-ep') !== ep) { b.setAttribute('data-ep', ep); nudgeTick(b); }
    // Just before the ➤ (the ＋ starts the bar since 2026-11-13), with the arrow's order, so they sit together.
    var next = send || ((plus && plus.parentNode === row) ? plus : null);
    if (next) { if (b.nextSibling !== next) row.insertBefore(b, next); }
    else if (b.parentNode !== row) row.appendChild(b);
    try {
      var o = getComputedStyle(next || b).order;
      if (b.style.getPropertyValue('order') !== o) b.style.setProperty('order', o, 'important');
    } catch (e) {}
    wireCall(row, b, ep);
  }

  /* 📞 CALL (2026-11-04, App\\Support\\ChatCall + kt-chat-call.js, free: browser to browser). Every chat that can be nudged can be called:
     the endpoint is the nudge's with /call for /nudge. Just before the 👋. */
  function wireCall(row, nudgeBtn, nudgeEp) {
    var ep = String(nudgeEp || '').replace(/\/nudge$/, '/call');
    var c = row.__ktCall;
    if (!ep || ep === nudgeEp || !(window.KT && KT.chatCall && KT.chatCall.supported())) { if (c) { c.remove(); row.__ktCall = null; } return; }
    if (!c || c.parentNode !== row) {
      c = document.createElement('button');
      c.type = 'button';
      c.className = 'kt-nudge-btn kt-call-btn';
      c.setAttribute('aria-label', 'Call - a voice call in KiddieTrac');
      c.setAttribute('data-kttip', 'Call');
      c.setAttribute('data-kt-iconized', '1');
      c.setAttribute('data-kt-nospin', '1');
      c.textContent = '📞';
      c.addEventListener('click', function (e) {
        e.preventDefault(); e.stopPropagation();
        var body = {};
        try { body = JSON.parse(row.getAttribute('data-kt-nudge-body') || '{}') || {}; } catch (x) {}
        KT.chatCall.start(c.getAttribute('data-ep'), body);
      });
      row.__ktCall = c;
    }
    c.setAttribute('data-ep', ep);
    if (c.nextSibling !== nudgeBtn) row.insertBefore(c, nudgeBtn);
    var o = nudgeBtn.style.getPropertyValue('order');
    if (o && c.style.getPropertyValue('order') !== o) c.style.setProperty('order', o, 'important');
  }

  // ── 👋 being nudged: shake, vibrate, a banner with Open ──
  var lastShake = 0;
  function nudged(detail) {
    var d = detail.data;
    try { if (typeof d === 'string') d = JSON.parse(d); } catch (e) { d = null; }
    d = d || {};
    var url = String(d.url || '');
    var hash = url.indexOf('#') >= 0 ? url.slice(url.indexOf('#')) : '#chat';
    if (Date.now() - lastShake > 1500) {
      lastShake = Date.now();
      document.body.classList.remove('kt-nudge-shaking'); void document.body.offsetWidth;
      document.body.classList.add('kt-nudge-shaking');
      setTimeout(function () { document.body.classList.remove('kt-nudge-shaking'); }, 1300);
      // The tone + the person's 👋 vibration play from kt-sounds.js; buzz here too when it could not (no tap yet).
      try { if (!(window.KT && KT.sounds && KT.sounds.recentlyPlayed && KT.sounds.recentlyPlayed(2000)) && navigator.vibrate) navigator.vibrate([300, 120, 300, 120, 600]); } catch (e) {}
    }
    var old = document.querySelector('.kt-nudge-banner');
    if (old) old.remove();
    var bn = document.createElement('div');
    bn.className = 'kt-nudge-banner';
    bn.setAttribute('role', 'alert');
    bn.setAttribute('data-kt-no-close', '1');
    bn.innerHTML = '<span class="kt-nb-hand" aria-hidden="true">👋</span><span class="kt-nb-txt"></span>'
      + '<button type="button" class="kt-nb-open">Open</button><button type="button" class="kt-nb-x" aria-label="Dismiss">✕</button>';
    var t = bn.querySelector('.kt-nb-txt');
    t.textContent = (d.from ? d.from + ' nudged you' : String(detail.title || 'Someone nudged you').replace(/^👋\s*/, ''));
    var sm = document.createElement('small'); sm.textContent = 'They are waiting for you in a chat.'; t.appendChild(sm);
    var gone = function () { if (bn.isConnected) bn.remove(); };
    bn.querySelector('.kt-nb-open').addEventListener('click', function () {
      gone();
      if (location.hash === hash) { try { window.dispatchEvent(new HashChangeEvent('hashchange')); } catch (e) {} }
      else location.hash = hash;
      if (detail.id && KTApi() && KTApi().patch) { try { KTApi().patch('/notifications/' + detail.id + '/read', {}).catch(function () {}); } catch (e) {} }
    });
    bn.querySelector('.kt-nb-x').addEventListener('click', gone);
    document.body.appendChild(bn);
    setTimeout(gone, 12000);
  }
  window.addEventListener('kt:notification', function (e) {
    var d = e && e.detail;
    if (d && (d.type === 'nudge' || d.cat === 'nudge')) nudged(d);
  });

  function wire(row) {
    bar(row);
    inlineAttach(row);
    if (row.hasAttribute('data-kt-no-plus')) { wireNudge(row, null, row.querySelector(':scope > .kt-send-one')); return; }
    var ts = tools(row);
    var plus = row.__ktPlus;
    if (!ts.length && !(narrow() && row.hasAttribute('data-kt-nudge'))) {
      if (plus) { plus.remove(); row.__ktPlus = null; }
      row.classList.remove('kt-plus-on');
      wireNudge(row, null, row.querySelector(':scope > .kt-send-one'));
      return;
    }
    ts.forEach(function (t) { if (!t.classList.contains('kt-plus-tool')) t.classList.add('kt-plus-tool'); });
    row.classList.add('kt-plus-on');
    var send = row.querySelector(':scope > .kt-send-one');
    if (!plus || !plus.isConnected || plus.parentNode !== row) {
      plus = document.createElement('button');
      plus.type = 'button';
      plus.className = 'kt-plus-btn';
      plus.setAttribute('aria-label', 'More actions');
      plus.setAttribute('data-kttip', 'More');
      plus.setAttribute('aria-haspopup', 'menu');
      plus.setAttribute('aria-expanded', 'false');
      plus.setAttribute('data-kt-iconized', '1');
      plus.textContent = '+';
      plus.addEventListener('click', function (e) { e.preventDefault(); e.stopPropagation(); open(row, plus); });
      row.__ktPlus = plus;
    }
    // First in the bar (2026-11-13), so the keyboard meets it where the eye does.
    if (row.firstElementChild !== plus) row.insertBefore(plus, row.firstElementChild);
    var any = ts.some(usable);
    plus.disabled = !any && !(narrow() && row.hasAttribute('data-kt-nudge'));
    wireNudge(row, plus, send);
  }

  var raf = 0;
  function scan() {
    raf = 0;
    var rows = document.querySelectorAll('.kt-compose-row');
    for (var i = 0; i < rows.length; i++) wire(rows[i]);
    if (openMenu && !openMenu.btn.isConnected) closeMenu(false);
  }
  function soon() { if (!raf) raf = requestAnimationFrame(scan); }

  function start() {
    scan();
    new MutationObserver(soon).observe(document.body, { childList: true, subtree: true });
  }
  if (document.body) start(); else document.addEventListener('DOMContentLoaded', start);
  window.KT = window.KT || {};
  window.KT.composePlus = { refresh: scan, close: function () { closeMenu(false); }, nudged: nudged };
})(window, document);
