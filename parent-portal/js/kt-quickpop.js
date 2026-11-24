/* ═══════════════════════════════════════════════════════════════════
   KiddieTrac — ONE quick popover for every quick choice (2026-11-24).

   Anthony: "the pop up should be a transparent quick popup for the quick tap", then "quick add centre button pop up
   isnt the transparent popup like how we have designed throughout - full sweep to find all similar popups and make
   this consistent". A short menu of ACTIONS opened from a button (bar centre buttons, + / Add / New menus, quick
   action sheets) is this popover - never a Shell.Modal. Forms, confirmations, pickers and content stay standard
   popups (the one-header rule); this is his explicit exception for quick choices only.

   KT.quickPop({ anchor, items: [{ icon, label, sub, run, aria, danger, disabled }], label, empty, width })
     -> { close }
   • Look: the page stays visible under a light frosted dim; each choice is a big rounded glass button (icon + label
     + optional sub line). Dark-mode aware (prefers-color-scheme and the portal's html.kt-dark / data-theme=dark).
   • Anchoring: above the anchor when it sits in the lower half of the screen (a bottom-bar button), dropping down
     from it otherwise (a top-bar icon, a + in a header). No anchor: centred above the bottom bar.
   • ~120ms scale + fade. Tap outside, Esc, or Android back (KT.pushOverlay) closes; a choice closes and runs at once.
     Arrow keys move between choices; focus starts on the first and returns to the anchor.
   • z-index 2147483000/1: over the bottom bar and every popup, under the idle warning (2147483647) and #kt-urgent.
   • Opening it again from the same anchor while it is up closes it (a toggle). One at a time.
   ═══════════════════════════════════════════════════════════════════ */
(function (window) {
  'use strict';
  var KT = window.KT || (window.KT = {});
  if (KT.quickPop) return;

  var cur = null;

  function css() {
    if (document.getElementById('kt-qp-css')) return;
    var st = document.createElement('style');
    st.id = 'kt-qp-css';
    var D = [['.kt-qp-dim', 'background:rgba(0,0,0,.24);'],
      ['.kt-qp-item', 'color:#F1F5F9;background:rgba(13,27,51,.6);border-color:rgba(255,255,255,.16);'],
      ['.kt-qp-item:hover', 'background:rgba(13,27,51,.76);'],
      ['.kt-qp-item.danger', 'color:#FCA5A5;'],
      ['.kt-qp-none', 'color:#E2E8F0;background:rgba(13,27,51,.6);']];
    var dark = D.map(function (d) { return d[0] + '{' + d[1] + '}'; }).join('');
    var themed = D.map(function (d) { return 'html.kt-dark ' + d[0] + ',html[data-theme="dark"] ' + d[0] + '{' + d[1] + '}'; }).join('');
    st.textContent = ''
      + '.kt-qp-dim{position:fixed;inset:0;z-index:2147483000;background:rgba(8,28,65,.16);-webkit-backdrop-filter:blur(1px);backdrop-filter:blur(1px);opacity:0;transition:opacity .12s ease;}'
      + '.kt-qp{position:fixed;z-index:2147483001;display:grid;gap:10px;width:268px;max-width:calc(100vw - 24px);max-height:calc(100vh - 120px);overflow-y:auto;overscroll-behavior:contain;'
      +   'padding:2px;opacity:0;transform:scale(.94);transition:opacity .12s ease,transform .12s ease;scrollbar-width:none;}'
      + '.kt-qp::-webkit-scrollbar{display:none;}'
      + '.kt-qp.on,.kt-qp-dim.on{opacity:1;}.kt-qp.on{transform:scale(1);}'
      + '.kt-qp-item{display:flex;align-items:center;gap:12px;width:100%;min-height:54px;padding:9px 16px;border-radius:18px;cursor:pointer;text-align:left;font:inherit;'
      +   'color:#0B1B33;background:rgba(255,255,255,.66);border:1px solid rgba(255,255,255,.8);-webkit-backdrop-filter:blur(16px) saturate(1.4);backdrop-filter:blur(16px) saturate(1.4);'
      +   'box-shadow:0 10px 30px -12px rgba(8,28,65,.45);transition:transform .08s ease,background .12s ease;}'
      + '.kt-qp-item:hover{background:rgba(255,255,255,.82);}.kt-qp-item:active{transform:scale(.97);}'
      + '.kt-qp-item:focus-visible{outline:3px solid #2A8F9D;outline-offset:2px;}'
      + '.kt-qp-item[disabled]{opacity:.5;cursor:default;}.kt-qp-item.danger{color:#B91C1C;}'
      + '.kt-qp-i{font-size:24px;line-height:1;flex:0 0 auto;width:28px;display:flex;justify-content:center;}.kt-qp-i svg{width:26px;height:26px;display:block;}'
      + '.kt-qp-t{font-weight:800;font-size:15px;line-height:1.2;min-width:0;}.kt-qp-s{display:block;font-weight:600;font-size:12px;opacity:.72;margin-top:2px;}'
      + '.kt-qp-none{padding:12px 16px;border-radius:16px;font-size:13.5px;font-weight:600;color:#334155;background:rgba(255,255,255,.72);-webkit-backdrop-filter:blur(16px);backdrop-filter:blur(16px);}'
      + '@media (prefers-color-scheme: dark){' + dark + '}'
      + themed
      + '@media (prefers-reduced-motion: reduce){.kt-qp,.kt-qp-dim{transition:none;}}';
    document.head.appendChild(st);
  }

  function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }

  /** Split a label like "➕ Add child" into its leading emoji and the words (for menus built from existing buttons). */
  KT.quickPopSplit = function (text) {
    var t = String(text || '').trim();
    var m = t.match(/^((?:\p{Extended_Pictographic}|\p{Regional_Indicator}|[←-⇿☀-➿＋+＋])(?:️|‍(?:\p{Extended_Pictographic})️?)*)\s*/u);
    return m ? { icon: m[1], label: t.slice(m[0].length) } : { icon: '', label: t };
  };

  KT.quickPop = function (opts) {
    opts = opts || {};
    var anchor = opts.anchor || null;
    if (cur) { var same = cur.anchor === anchor; cur.close(); if (same && anchor) return { close: function () {} }; }
    css();
    var dim = document.createElement('div');
    dim.className = 'kt-qp-dim';
    var pop = document.createElement('div');
    pop.className = 'kt-qp';
    if (opts.id) pop.id = opts.id;
    pop.setAttribute('role', 'menu');
    pop.setAttribute('aria-label', opts.label || 'Quick choices');
    // Not a dialog: the modal guard must not add a ✕ or a header to it, and "close on outside" helpers leave it be.
    pop.setAttribute('data-no-modal-guard', '1');
    pop.setAttribute('data-kt-no-close', '1');
    dim.setAttribute('data-no-modal-guard', '1');
    var items = (opts.items || []).filter(Boolean);
    var btns = [];
    if (!items.length) {
      var none = document.createElement('div');
      none.className = 'kt-qp-none';
      none.setAttribute('role', 'note');
      none.textContent = opts.empty || 'Nothing here yet.';
      pop.appendChild(none);
    }
    items.forEach(function (it) {
      var b = document.createElement('button');
      b.type = 'button';
      b.className = 'kt-qp-item' + (it.danger ? ' danger' : '');
      b.setAttribute('role', 'menuitem');
      if (it.aria) b.setAttribute('aria-label', it.aria);
      if (it.disabled) b.disabled = true;
      var icon = it.icon ? (String(it.icon).charAt(0) === '<' ? it.icon : esc(it.icon)) : '';
      b.innerHTML = (icon ? '<span class="kt-qp-i" aria-hidden="true">' + icon + '</span>' : '')
        + '<span class="kt-qp-t">' + esc(it.label) + (it.sub ? '<span class="kt-qp-s">' + esc(it.sub) + '</span>' : '') + '</span>';
      b.addEventListener('click', function (e) {
        e.preventDefault(); e.stopPropagation();
        close(true);
        try { if (typeof it.run === 'function') it.run(); } catch (err) { if (window.console) console.error(err); }
      });
      btns.push(b);
      pop.appendChild(b);
    });
    if (opts.width) pop.style.width = opts.width + 'px';
    document.body.appendChild(dim);
    document.body.appendChild(pop);

    // Place it by the button that opened it.
    var r = anchor && anchor.getBoundingClientRect ? anchor.getBoundingClientRect() : null;
    var W = window.innerWidth, H = window.innerHeight, pw = Math.min(pop.offsetWidth || 268, W - 24);
    if (r && (r.width || r.height)) {
      var left = Math.max(12, Math.min(W - pw - 12, r.left + r.width / 2 - pw / 2));
      pop.style.left = left + 'px';
      var ox = (r.left + r.width / 2 - left) + 'px';
      if (r.top > H / 2) { pop.style.bottom = (H - r.top + 10) + 'px'; pop.style.maxHeight = (r.top - 24) + 'px'; pop.style.transformOrigin = ox + ' 100%'; }
      else { pop.style.top = (r.bottom + 8) + 'px'; pop.style.maxHeight = (H - r.bottom - 24) + 'px'; pop.style.transformOrigin = ox + ' 0'; }
    } else {
      pop.style.left = Math.max(12, (W - pw) / 2) + 'px';
      pop.style.bottom = 'calc(env(safe-area-inset-bottom,0px) + 96px)';
    }

    function onKey(e) {
      if (e.key === 'Escape') { e.preventDefault(); e.stopPropagation(); close(); return; }
      if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
        var live = btns.filter(function (b) { return !b.disabled; });
        if (!live.length) return;
        var i = live.indexOf(document.activeElement);
        i = e.key === 'ArrowDown' ? (i + 1) % live.length : (i <= 0 ? live.length - 1 : i - 1);
        e.preventDefault(); live[i].focus();
      }
    }
    var closed = false;
    function close(chosen) {
      if (closed) return; closed = true;
      document.removeEventListener('keydown', onKey, true);
      if (dim.parentNode) dim.parentNode.removeChild(dim);
      if (pop.parentNode) pop.parentNode.removeChild(pop);
      if (cur && cur.pop === pop) cur = null;
      if (!chosen) { try { if (anchor && anchor.focus) anchor.focus({ preventScroll: true }); } catch (e) {} }
      if (typeof opts.onClose === 'function') { try { opts.onClose(); } catch (e) {} }
    }
    document.addEventListener('keydown', onKey, true);
    dim.addEventListener('click', function (e) { e.preventDefault(); e.stopPropagation(); close(); });
    if (KT.pushOverlay) KT.pushOverlay(pop, function () { close(); });   // Android back
    cur = { anchor: anchor, pop: pop, close: function () { close(); } };
    requestAnimationFrame(function () {
      dim.classList.add('on'); pop.classList.add('on');
      var first = btns.filter(function (b) { return !b.disabled; })[0];
      try { if (first) first.focus({ preventScroll: true }); } catch (e) {}
    });
    return { close: function () { close(); } };
  };
  KT.quickPopOpen = function () { return !!cur; };
})(window);
