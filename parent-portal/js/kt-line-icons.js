/* ═══════════════════════════════════════════════════════════════════
   KiddieTrac — LINE ICONS FOR WINDOW AND CHAT CONTROLS (2026-11-13).
   Anthony (with a photo of thin outline icons - edit, history, ···, the diagonal expand arrows, ✕): "use these types
   of icons in popup windows where appropriate for maximize, minimize etc - full sweep", and for the typing bar ("for
   the chat bar mimic to make this cleaner with kiddietrac - full sweep for desktop and mobile").

   One set of thin outline icons (24px grid, 1.8 stroke, round ends, currentColor), swapped in for the text glyphs the
   portal's controls are drawn with, wherever they are drawn - so no screen has to change:
     ✕ × ✖ …            any button / link that is only a close glyph, anywhere (popups, banners, chips, panels)
     – ⤢ ❐ ⋯ ⋮ « »      only in a window's own control strip: popup headers, the chat dock, the dial pad
     😊 📷 🎤 ➤ ⏹ + 👋 📞  only on a chat typing bar (.kt-compose-row, kt-compose-plus.js)

   THE GLYPH STAYS IN THE BUTTON, hidden (.kt-li-g). Code reads it: kt-modal-guard finds a popup's own close by its ✕
   text, kt-dialog-move strips × from a header's title, and a screen may check what its button shows. A screen that
   writes a new glyph (the dial pad's ⤢ / ❐, the parent's 🎤 / ➤) gets the matching icon on the next frame.
   Opt out: data-kt-no-line-icon on the control or any ancestor. KT.lineIcon(name) returns the SVG for new code.
   ═══════════════════════════════════════════════════════════════════ */
(function (window, document) {
  'use strict';
  if (window.__ktLineIcons) return;
  window.__ktLineIcons = true;

  var P = {
    close: '<path d="M18 6 6 18M6 6l12 12"/>',
    minimize: '<path d="M5 12h14"/>',
    maximize: '<path d="M15 3h6v6M9 21H3v-6M21 3l-7 7M3 21l7-7"/>',
    restore: '<path d="M4 14h6v6M20 10h-6V4M14 10l7-7M3 21l7-7"/>',
    more: '<circle cx="5" cy="12" r="1.1"/><circle cx="12" cy="12" r="1.1"/><circle cx="19" cy="12" r="1.1"/>',
    morev: '<circle cx="12" cy="5" r="1.1"/><circle cx="12" cy="12" r="1.1"/><circle cx="12" cy="19" r="1.1"/>',
    edit: '<path d="M12 3H5a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.4 2.6a2.1 2.1 0 1 1 3 3L12 15l-4 1 1-4z"/>',
    history: '<path d="M3 12a9 9 0 1 0 9-9 9.75 9.75 0 0 0-6.74 2.74L3 8"/><path d="M3 3v5h5M12 7v5l4 2"/>',
    panelclose: '<rect x="3" y="3" width="18" height="18" rx="2"/><path d="M9 3v18M16 15l-3-3 3-3"/>',
    panelopen: '<rect x="3" y="3" width="18" height="18" rx="2"/><path d="M9 3v18M14 9l3 3-3 3"/>',
    plus: '<path d="M12 5v14M5 12h14"/>',
    paperclip: '<path d="m21.44 11.05-9.19 9.19a6 6 0 0 1-8.49-8.49l8.57-8.57A4 4 0 1 1 18 8.84l-8.59 8.57a2 2 0 0 1-2.83-2.83l8.49-8.48"/>',
    smile: '<circle cx="12" cy="12" r="9.5"/><path d="M8 14s1.5 2 4 2 4-2 4-2M9 9h.01M15 9h.01"/>',
    mic: '<path d="M12 2a3 3 0 0 0-3 3v7a3 3 0 0 0 6 0V5a3 3 0 0 0-3-3z"/><path d="M19 10v2a7 7 0 0 1-14 0v-2M12 19v3"/>',
    send: '<path d="M3.7 3.05a.5.5 0 0 0-.68.63l2.84 7.62a2 2 0 0 1 0 1.4l-2.84 7.62a.5.5 0 0 0 .68.63l18-8.5a.5.5 0 0 0 0-.9z"/><path d="M6 12h16"/>',
    stop: '<rect x="6" y="6" width="12" height="12" rx="2"/>',
    hand: '<path d="M18 11V6a2 2 0 0 0-4 0M14 10V4a2 2 0 0 0-4 0v2M10 10.5V6a2 2 0 0 0-4 0v8"/><path d="M18 8a2 2 0 1 1 4 0v6a8 8 0 0 1-8 8h-2c-2.8 0-4.5-.86-6-2.34l-3.6-3.6a2 2 0 0 1 2.83-2.82L7 15"/>',
    phone: '<path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.8 19.8 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6A19.8 19.8 0 0 1 2.12 4.18 2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72c.13.96.36 1.9.7 2.81a2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45c.9.34 1.85.57 2.81.7A2 2 0 0 1 22 16.92z"/>',
    sparkles: '<path d="M9.94 15.5A2 2 0 0 0 8.5 14.06l-6.14-1.58a.5.5 0 0 1 0-.96L8.5 9.94A2 2 0 0 0 9.94 8.5l1.58-6.14a.5.5 0 0 1 .96 0l1.58 6.14a2 2 0 0 0 1.44 1.44l6.14 1.58a.5.5 0 0 1 0 .96l-6.14 1.58a2 2 0 0 0-1.44 1.44l-1.58 6.14a.5.5 0 0 1-.96 0z"/>'
  };
  var LABEL = { close: 'Close', minimize: 'Minimise', maximize: 'Maximise', restore: 'Restore size', more: 'More', morev: 'More',
    panelclose: 'Hide conversations', panelopen: 'Show conversations', plus: 'More actions', paperclip: 'Attach', smile: 'Emoji',
    mic: 'Record a voice note', send: 'Send', stop: 'Stop', hand: 'Nudge', phone: 'Call' };

  function svg(name) {
    return '<svg class="kt-li" data-li="' + name + '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"'
      + ' stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">' + (P[name] || '') + '</svg>';
  }

  var CLOSE = { '✕': 1, '×': 1, '✖': 1, '⨯': 1, '✗': 1, '✘': 1 };
  var CHROME_MAP = { '–': 'minimize', '—': 'minimize', '−': 'minimize', '_': 'minimize',
    '⤢': 'maximize', '⛶': 'maximize', '⤡': 'maximize', '↗': 'maximize', '🗖': 'maximize',
    '❐': 'restore', '🗗': 'restore', '⋯': 'more', '…': 'more', '···': 'more',
    '⋮': 'morev', '«': 'panelclose', '»': 'panelopen' };
  var COMPOSE_MAP = { '😊': 'smile', '🙂': 'smile', '😀': 'smile',
    '📷': 'paperclip', '📸': 'paperclip', '📎': 'paperclip', '🖼': 'paperclip',
    '🎤': 'mic', '🎙': 'mic', '➤': 'send', '➢': 'send', '⏹': 'stop', '■': 'stop', '◼': 'stop',
    '+': 'plus', '＋': 'plus', '👋': 'hand', '📞': 'phone' };
  // A window's own control strip: popup headers (Shell.Modal, the guard's band), the chat dock, the dial pad.
  var CHROME = '.kt-dlg-head, .modal-header, #kt-chat-dock .kt-cd-ctrl, #kt-chat-dock .kt-cd-min, #kt-dpop .kdp-head, [role="dialog"] > header, .kt-win-ctrls';
  var CONTROL = 'button, [role="button"], a';

  // The control's own text, if it is only text (or text in plain spans, or what this file already drew); null otherwise.
  function raw(el) {
    var t = '';
    for (var c = el.firstChild; c; c = c.nextSibling) {
      if (c.nodeType === 3) t += c.nodeValue;
      else if (c.nodeType === 1) {
        if (c.classList.contains('kt-li')) continue;
        if (c.firstElementChild) return null;
        if (c.tagName !== 'SPAN' && c.tagName !== 'I' && c.tagName !== 'B') return null;
        t += c.textContent;
      }
    }
    return t;
  }
  function norm(t) { return String(t).replace(/[︎️\s]/g, ''); }

  function nameFor(el, k) {
    if (!k) return null;
    if (CLOSE[k]) return 'close';
    var p = el.parentElement;
    if (p && p.classList.contains('kt-compose-row') && COMPOSE_MAP[k]) return COMPOSE_MAP[k];
    if (CHROME_MAP[k] && el.closest(CHROME)) return CHROME_MAP[k];
    return null;
  }

  function paint(el) {
    if (!el || el.nodeType !== 1 || !el.isConnected) return;
    if (el.closest('[data-kt-no-line-icon], [contenteditable="true"], .kt-li-g')) return;
    var r = raw(el);
    if (r == null) return;
    var k = norm(r), name = nameFor(el, k);
    if (!name) return;
    var s = el.querySelector(':scope > svg.kt-li'), g = el.querySelector(':scope > .kt-li-g');
    if (s && g && s.getAttribute('data-li') === name && el.childNodes.length === 2) return;   // already drawn
    if (!el.getAttribute('aria-label')) {
      var lab = el.getAttribute('title') || el.getAttribute('data-kttip') || el.getAttribute('data-kttip-title') || LABEL[name];
      if (lab) el.setAttribute('aria-label', lab);
    }
    el.innerHTML = svg(name) + '<span class="kt-li-g" aria-hidden="true"></span>';
    el.lastChild.textContent = r.trim();
    el.classList.add('kt-li-btn');
    el.setAttribute('data-li-btn', name);
  }

  var pend = [], raf = 0;
  function flush() {
    raf = 0;
    var list = pend; pend = [];
    for (var i = 0; i < list.length; i++) {
      var n = list[i];
      if (!n.isConnected) continue;
      if (n.matches && n.matches(CONTROL)) paint(n);
      if (n.querySelectorAll) { var cs = n.querySelectorAll(CONTROL); for (var j = 0; j < cs.length; j++) paint(cs[j]); }
    }
  }
  function queue(n) {
    if (!n) return;
    if (n.nodeType === 3) n = n.parentElement;
    if (!n || n.nodeType !== 1) return;
    var c = n.closest && n.closest(CONTROL);
    pend.push(c || n);
    if (!raf) raf = requestAnimationFrame(flush);
  }

  function css() {
    if (document.getElementById('kt-line-icons-css')) return;
    var st = document.createElement('style');
    st.id = 'kt-line-icons-css';
    st.textContent = [
      '.kt-li{width:1.15em;height:1.15em;display:inline-block;vertical-align:middle;flex:none;pointer-events:none;overflow:visible;}',
      '.kt-li circle[r="1.1"]{fill:currentColor;}',
      '.kt-li-btn > .kt-li-g{display:none !important;}',
      '.kt-li-btn{font-size:inherit;}',
      // The popup's ✕ (kt-modal-guard) and Shell.Modal's: 18px, like the photo.
      '.kt-modal-x .kt-li,.modal-close .kt-li{width:18px;height:18px;}',
      '.modal-close.kt-li-btn{display:inline-flex !important;align-items:center;justify-content:center;}',
      '#kt-chat-dock .kt-cd-btn .kt-li{width:15px;height:15px;}',
      '#kt-chat-dock .kt-cd-btn.kt-li-btn{display:inline-flex;align-items:center;justify-content:center;padding:0;}',
      '#kt-dpop .kdp-btn .kt-li{width:16px;height:16px;}',
      '#kt-dpop .kdp-btn.kt-li-btn{display:inline-flex;align-items:center;justify-content:center;padding:0;}'
    ].join('\n');
    (document.head || document.documentElement).appendChild(st);
  }

  function start() {
    css();
    queue(document.body);
    new MutationObserver(function (ms) {
      for (var i = 0; i < ms.length; i++) {
        var m = ms[i];
        if (m.type === 'characterData') { queue(m.target); continue; }
        if (m.addedNodes.length) {
          // Text changed inside a control (a screen wrote a new glyph): look at the control itself.
          if (m.target.closest && m.target.closest(CONTROL)) queue(m.target);
          for (var j = 0; j < m.addedNodes.length; j++) {
            var a = m.addedNodes[j];
            if (a.nodeType === 1 && a.tagName !== 'svg' && !(a.classList && a.classList.contains('kt-li-g'))) queue(a);
          }
        }
      }
    }).observe(document.body, { childList: true, subtree: true, characterData: true });
  }
  if (document.body) start(); else document.addEventListener('DOMContentLoaded', start);

  window.KT = window.KT || {};
  window.KT.lineIcon = function (name) { return svg(name); };
  window.KT.lineIcons = { paint: paint, refresh: function () { queue(document.body); } };
})(window, document);
