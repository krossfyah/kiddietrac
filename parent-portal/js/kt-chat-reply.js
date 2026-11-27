/* REPLY TO ONE MESSAGE (2026-11-01, Anthony: "when there is a message, how do you reply to that specific
   message if there are multiple messages - similiar to how whatsap behaves").

   One module for every chat renderer: the family thread and the colleague thread (screen-chat.js, in the dock
   and full screen) and the website chat (kt-webchat-strip.js). A renderer only has to:
     - put KT.chatReply.btn(m.id, author, text) in each bubble's meta row and KT.chatReply.quote(m) above its text,
     - leave a <div class="kt-rp-slot"></div> just above its composer,
     - call KT.chatReply.wire(root, { key, input }) once it has painted (safe to call on every paint),
     - send KT.chatReply.peek(key) as reply_to_id, and KT.chatReply.clear(key) once the send succeeds.
   Desktop: the ↩ on the bubble (shown on hover). Phone: swipe the bubble right. The quote above a sent reply
   jumps to the original and flashes it; a deleted original reads "Original message" and does not jump.
   The server decides what may be quoted (App\Support\ChatReply): only a message in the same conversation. */
(function (w, d) {
  'use strict';
  var KT = w.KT = w.KT || {};
  var state = {};            // key -> { id, who, text }

  function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }
  function css() {
    if (d.getElementById('kt-rp-css')) return;
    var s = d.createElement('style'); s.id = 'kt-rp-css';
    s.textContent = ''
      + '.kt-rp-q{display:block;box-sizing:border-box;width:100%;margin:0 0 6px;padding:5px 9px;border:0;border-left:3px solid #159FB4;'
      + 'border-radius:7px;background:rgba(15,23,42,.06);font:inherit;font-size:12.5px;line-height:1.35;text-align:left;color:inherit;cursor:pointer;min-height:0}'
      + '.kt-rp-q[data-rp-none]{cursor:default}'
      + '.kt-rp-q b{display:block;font-size:11.5px;font-weight:800;color:#0E7490}'
      + '.kt-rp-q span{display:block;opacity:.8;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;max-width:260px}'
      + '.kt-rp-q.kt-rp-dark{background:rgba(255,255,255,.16);border-left-color:#7DD3FC}'
      + '.kt-rp-q.kt-rp-dark b{color:#BAE6FD}'
      + '.kt-chr-btn{background:none!important;border:0!important;box-shadow:none!important;cursor:pointer;color:rgba(13,27,42,.32)!important;font-size:12px;font-weight:600;line-height:1;padding:0 2px;margin-left:4px;min-height:0;min-width:0;height:auto;border-radius:4px}.kt-chr-btn:hover{color:rgba(13,27,42,.6)!important}'
      + '@media (hover:hover){.kt-chr-btn{opacity:0;transition:opacity .12s}[data-mid]:hover .kt-chr-btn,.kt-chr-btn:focus-visible{opacity:1}}'
      + '@media (hover:none){.kt-chr-btn{opacity:.4}}'
      + '.kt-rp-strip{display:flex;align-items:center;gap:8px;padding:7px 10px 0;background:transparent}'
      + '.kt-rp-strip .kt-rp-q{margin:0;flex:1;min-width:0;cursor:default}'
      + '.kt-rp-strip .kt-rp-q span{max-width:none}'
      + '.kt-rp-x{flex:0 0 auto;background:#F1F5F9;border:0;border-radius:50%;width:26px;height:26px;cursor:pointer;color:#475569;font-size:13px;line-height:1;min-height:0!important;min-width:0!important;padding:0!important}'
      + '@keyframes ktRpFlash{0%,35%{background:rgba(21,159,180,.22)}100%{background:transparent}}'
      + '.kt-rp-flash{animation:ktRpFlash 1.4s ease-out;border-radius:12px}'
      + '[data-kt-rp-swipe]{transition:transform .18s ease}';
    d.head.appendChild(s);
  }

  /* What a quote says about a message: an image or voice note with no words still has to read as something. */
  function snippetOf(text, attachments) {
    var t = String(text || '').replace(/\*\*([^*]+)\*\*/g, '$1').replace(/\s+/g, ' ').trim();
    if (t) return t.length > 90 ? t.slice(0, 89) + '…' : t;
    var a = (attachments || [])[0];
    if (!a) return 'Message';
    var u = String(a.url || '');
    if ((a.mime && a.mime.indexOf('audio/') === 0) || a.type === 'audio' || /\.(webm|mp3|m4a|ogg|wav|aac)$/i.test(u)) return '🎤 Voice note';
    if ((a.mime && a.mime.indexOf('image/') === 0) || a.type === 'image' || /\.(jpe?g|png|gif|webp|bmp|heic)$/i.test(u)) return '📷 Photo';
    return '📎 Attachment';
  }

  /* The ↩ control for one bubble. who = the author's first name, text = what the quote will show. */
  function btn(mid, who, text, attachments) {
    css();
    return '<button type="button" class="kt-chr-btn" data-rp-mid="' + esc(mid) + '" data-rp-who="' + esc(String(who || '').split(' ')[0] || 'Message')
      + '" data-rp-text="' + esc(snippetOf(text, attachments)) + '" title="Reply" aria-label="Reply to this message">↩</button>';
  }

  /* The quote above a reply. m.reply_to = { id, author_first_name, snippet, deleted } from the server. */
  function quote(m, opts) {
    if (!m || (!m.reply_to && !m.reply_to_id)) return '';
    css();
    var q = m.reply_to || {};
    var dark = opts && opts.dark ? ' kt-rp-dark' : '';
    if (!q.id || q.deleted) {
      return '<div class="kt-rp-q' + dark + '" data-rp-none="1"><span style="font-style:italic;">Original message</span></div>';
    }
    return '<button type="button" class="kt-rp-q' + dark + '" data-rp-jump="' + esc(q.id) + '" title="Show the original message">'
      + '<b>' + esc(q.author_first_name || 'Message') + '</b><span>' + esc(q.snippet || '') + '</span></button>';
  }

  function stripHtml(st) {
    return '<div class="kt-rp-strip"><div class="kt-rp-q"><b>Replying to ' + esc(st.who) + '</b><span>' + esc(st.text) + '</span></div>'
      + '<button type="button" class="kt-rp-x" title="Cancel reply" aria-label="Cancel reply">✕</button></div>';
  }
  /* Draw (or clear) the strip into the root's slot. Called on every wire(), so a renderer that repaints its
     whole thread on each poll keeps the reply someone is part-way through writing. */
  function paintStrip(root) {
    var o = root && root.__ktRp; if (!o) return;
    var slot = root.querySelector('.kt-rp-slot'); if (!slot) return;
    var st = state[o.key];
    slot.innerHTML = st ? stripHtml(st) : '';
  }

  function set(root, mid, who, text) {
    var o = root.__ktRp; if (!o) return;
    state[o.key] = { id: parseInt(mid, 10), who: who || 'Message', text: text || '' };
    paintStrip(root);
    try { var i = o.input && o.input(); if (i) i.focus(); } catch (e) {}
  }

  function jump(root, id) {
    var el = root.querySelector('[data-mid="' + String(id).replace(/"/g, '') + '"]');
    if (!el) { try { KT.toast && KT.toast('↩', 'Not loaded here', 'That message is further back than this view goes.', '#1F6080'); } catch (e) {} return; }
    try { el.scrollIntoView({ block: 'center', behavior: 'smooth' }); } catch (e) { el.scrollIntoView(); }
    var target = el.querySelector('.kt-wcx-b, [data-kt-rp-bubble]') || el;
    target.classList.remove('kt-rp-flash'); void target.offsetWidth; target.classList.add('kt-rp-flash');
    setTimeout(function () { target.classList.remove('kt-rp-flash'); }, 1500);
  }

  /* Delegated on the root, which outlives the renderer's repaints. Wired once per element; the options are
     replaced on every call so the key always names the thread that is open in it now (the dock reuses one
     content element for every conversation). */
  function wire(root, opts) {
    if (!root) return;
    css();
    root.__ktRp = opts;
    paintStrip(root);
    if (root.__ktRpWired) return;
    root.__ktRpWired = 1;

    root.addEventListener('click', function (e) {
      var t = e.target && e.target.closest ? e.target : null; if (!t) return;
      var b = t.closest('.kt-chr-btn');
      if (b && root.contains(b)) { e.preventDefault(); set(root, b.getAttribute('data-rp-mid'), b.getAttribute('data-rp-who'), b.getAttribute('data-rp-text')); return; }
      var x = t.closest('.kt-rp-x');
      if (x && root.contains(x)) { e.preventDefault(); if (root.__ktRp) delete state[root.__ktRp.key]; paintStrip(root); return; }
      var q = t.closest('[data-rp-jump]');
      if (q && root.contains(q)) { e.preventDefault(); jump(root, q.getAttribute('data-rp-jump')); }
    });

    /* SWIPE RIGHT TO REPLY (phones). Only a mostly-horizontal drag counts, so scrolling the thread never
       starts a reply; the bubble follows the finger a little and springs back. */
    var sx = 0, sy = 0, row = null, dx = 0, horiz = null;
    root.addEventListener('touchstart', function (e) {
      var t = e.touches && e.touches[0]; if (!t) return;
      var r = e.target.closest && e.target.closest('[data-mid]');
      if (!r || !root.contains(r) || !r.querySelector('.kt-chr-btn') || e.target.closest('audio,a,button,input,textarea')) { row = null; return; }
      row = r; sx = t.clientX; sy = t.clientY; dx = 0; horiz = null;
    }, { passive: true });
    root.addEventListener('touchmove', function (e) {
      if (!row) return;
      var t = e.touches && e.touches[0]; if (!t) return;
      var mx = t.clientX - sx, my = t.clientY - sy;
      if (horiz === null && (Math.abs(mx) > 8 || Math.abs(my) > 8)) horiz = Math.abs(mx) > Math.abs(my) * 1.6 && mx > 0;
      if (!horiz) return;
      dx = Math.max(0, Math.min(mx, 90));
      row.setAttribute('data-kt-rp-swipe', '1');
      row.style.transform = 'translateX(' + dx + 'px)';
    }, { passive: true });
    function end() {
      if (!row) return;
      var r = row; row = null;
      r.style.transform = '';
      setTimeout(function () { r.removeAttribute('data-kt-rp-swipe'); }, 220);
      if (horiz && dx >= 56) {
        var b = r.querySelector('.kt-chr-btn');
        if (b) {
          try { if (navigator.vibrate) navigator.vibrate(12); } catch (x) {}
          set(root, b.getAttribute('data-rp-mid'), b.getAttribute('data-rp-who'), b.getAttribute('data-rp-text'));
        }
      }
    }
    root.addEventListener('touchend', end, { passive: true });
    root.addEventListener('touchcancel', end, { passive: true });
  }

  KT.chatReply = {
    btn: btn,
    quote: quote,
    wire: wire,
    snippet: snippetOf,
    peek: function (key) { var st = state[key]; return st ? st.id : null; },
    clear: function (key, root) { delete state[key]; if (root) paintStrip(root); },
  };
})(window, document);
