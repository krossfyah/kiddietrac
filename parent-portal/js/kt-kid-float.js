/* ═══════════════════════════════════════════════════════════════════
   kt-kid-float.js: the floating shortcuts card on a child (2026-11-06).

   Built for the educator's roster (screen-educator.js, 2026-11-04): tap a
   child and a dark glass card opens beside them over a dimmed page, a compact
   head, then the shortcuts. Moved here so the parent's Today screen opens the
   SAME card from the "+" on their child's photo (Anthony: "pressing the +
   should be the same type of popup like we did on the educator for the child
   (dimmed background etc..)"). One card, one look, both screens.

   KT.kidFloat.open(body, anchor, name)  body: the head (.kt-kr-pop-head) and
                                         the .kt-kr-tiles; anchor: the photo.
   KT.kidFloat.close()
   KT.kidFloat.photo(c, px)              the round photo for the head.
   ═══════════════════════════════════════════════════════════════════ */
(function () {
  'use strict';
  if (window.__ktKidFloat) return; window.__ktKidFloat = true;
  // KT.Dom (app.js) when it is there, looked up at call time; a plain builder otherwise.
  var Dom = {
    el: function (tag, attrs, kids) {
      if (window.KT && KT.Dom && KT.Dom.el) return KT.Dom.el(tag, attrs, kids);
      var e = document.createElement(tag);
      Object.keys(attrs || {}).forEach(function (k) { e.setAttribute(k, attrs[k]); });
      [].concat(kids == null ? [] : kids).forEach(function (k) { if (k == null) return; e.appendChild(typeof k === 'string' ? document.createTextNode(k) : k); });
      return e;
    },
  };

  function css() {
    if (document.getElementById('kt-kid-float-css')) return;
    var st = document.createElement('style'); st.id = 'kt-kid-float-css';
    st.textContent =
    ''
        + '.kt-kr-float{position:fixed;z-index:2147483500;width:218px;max-width:calc(100vw - 16px);max-height:calc(100vh - 16px);overflow-y:auto;box-sizing:border-box;background:rgba(15,23,42,.58);-webkit-backdrop-filter:blur(20px) saturate(1.6);backdrop-filter:blur(20px) saturate(1.6);border:1px solid rgba(255,255,255,.16);border-radius:20px;box-shadow:0 20px 48px rgba(0,0,0,.35),inset 0 1px 0 rgba(255,255,255,.12);color:#fff;padding:10px 6px 6px;animation:kt-kr-float-in .16s cubic-bezier(.2,.9,.3,1.2);}'
        + '@supports not ((backdrop-filter:blur(1px)) or (-webkit-backdrop-filter:blur(1px))){.kt-kr-float{background:rgba(15,23,42,.92);}}'
        + '.kt-kr-scrim{position:fixed;inset:0;z-index:2147483499;background:rgba(15,23,42,.32);animation:kt-kr-scrim-in .16s ease-out;}'
        + '@keyframes kt-kr-scrim-in{from{opacity:0;}to{opacity:1;}}'
        + '.kt-kr-float .kt-kr-pop-head b{color:#fff;}'
        + '@keyframes kt-kr-float-in{from{opacity:0;transform:translateY(6px) scale(.94);}to{opacity:1;transform:none;}}'
        + '.kt-kr-float .kt-kr-pop-head{gap:8px;margin:0 4px 6px;padding-right:28px;position:relative;}'
        + '.kt-kr-float .kt-kr-pop-head .kt-kr-ring{width:36px;height:36px;}'
        + '.kt-kr-float .kt-kr-pop-head b{font-size:14px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}'
        + '.kt-kr-pm{display:inline-block;margin-top:3px;padding:1px 7px;border-radius:999px;font-size:11px;font-weight:800;line-height:1.5;white-space:nowrap;}'
        + '.kt-kr-pm.family{background:rgba(253,230,138,.95);color:#78350F;}'
        + '.kt-kr-pm.none{background:rgba(254,202,202,.95);color:#7F1D1D;}'
        + '.kt-kr-float .kt-kr-pop-head span.sub{font-size:12px;margin-top:1px;}'
        + '.kt-kr-float .kt-kr-x{position:absolute;top:-2px;right:-2px;width:26px;height:26px;border-radius:50%;border:0;background:rgba(255,255,255,.14);color:#fff;font-size:16px;line-height:1;cursor:pointer;display:inline-flex;align-items:center;justify-content:center;}'
        + '.kt-kr-float .kt-kr-flags{margin:0 4px 8px;}'
        + '.kt-kr-float .kt-kr-last{margin:0 4px 8px;padding:6px 9px;font-size:12px;background:rgba(248,250,252,.7);}'
        + '.kt-kr-float .kt-kr-tiles{display:flex;flex-direction:column;gap:2px;}'
        + '.kt-kr-float .kt-kr-tile{display:grid;grid-template-columns:32px minmax(0,1fr);column-gap:10px;align-items:center;justify-items:start;text-align:left;border:0;background:none;border-radius:10px;padding:4px 6px;min-height:0;font-size:13.5px;font-weight:700;color:#fff;transition:background .12s ease;}'
        + '.kt-kr-float .kt-kr-tile:hover,.kt-kr-float .kt-kr-tile:focus-visible{background:rgba(255,255,255,.12);outline:none;}'
        + '.kt-kr-float .kt-kr-tile .ico{width:32px;height:32px;border-radius:50%;display:inline-flex;align-items:center;justify-content:center;font-size:16px;background:var(--kt-kr-tint,#F1F5F9);transition:transform .12s ease;}'
        + '.kt-kr-float .kt-kr-tile:hover .ico,.kt-kr-float .kt-kr-tile:focus-visible .ico{transform:scale(1.06);}'
        + '.kt-kr-float .kt-kr-tile.go,.kt-kr-float .kt-kr-tile.out{background:none;color:#fff;}'
        + '.kt-kr-float .kt-kr-tile.go:hover,.kt-kr-float .kt-kr-tile.out:hover{background:rgba(255,255,255,.12);}'
        + '.kt-kr-float .kt-kr-tile.go .ico{background:linear-gradient(135deg,#16A34A,#15803D);color:#fff;}'
        + '.kt-kr-float .kt-kr-tile.out .ico{background:linear-gradient(135deg,#F59E0B,#D97706);color:#fff;}'
        + '.kt-kr-float .kt-kr-tile .lbl{line-height:1.2;max-width:100%;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;}'
        + '.kt-kr-float .kt-kr-tile small{display:block;align-self:start;font-size:11.5px;font-weight:600;color:#64748B;line-height:1.25;max-width:100%;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;}'
        + '.kt-kr-float .kt-kr-tile.go small,.kt-kr-float .kt-kr-tile.out small{color:#64748B;}'
        + '.kt-kr-float .kt-kr-tile[disabled]{opacity:.4;}'
        + '.kt-kr-float [data-kt-sup-child]:not(:empty){margin:8px 2px 0;padding:8px;border-radius:12px;background:rgba(255,255,255,.94);color:#0F172A;}'
      // The head's photo (the educator's .kt-kr-ring / .kt-kr-ph, which the parent screen does not otherwise load).
      + '.kt-kr-float .kt-kr-kid{display:flex;flex:0 0 auto;background:none;border:0;}'
      + '.kt-kr-float .kt-kr-ring{position:relative;display:block;box-sizing:border-box;width:36px;height:36px;border-radius:50%;padding:2px;background:#CBD5E1;flex:0 0 auto;}'
      + '.kt-kr-float .kt-kr-kid[data-st="in"] .kt-kr-ring{background:linear-gradient(135deg,#4ADE80,#16A34A);}'
      + '.kt-kr-float .kt-kr-ph{position:relative;display:flex;align-items:center;justify-content:center;width:100%;height:100%;border-radius:50%;overflow:hidden;background:#F1F5F9;border:2px solid #fff;box-sizing:border-box;line-height:1;}'
      + '.kt-kr-float .kt-kr-ph img{position:absolute;inset:0;width:100%;height:100%;object-fit:cover;}'
      + '.kt-kr-float .kt-kr-pop-head{display:flex;align-items:center;}'
      + '.kt-kr-float .kt-kr-pop-head b{display:block;font-weight:800;line-height:1.2;}'
      + '.kt-kr-float .kt-kr-pop-head span.sub{display:block;color:rgba(255,255,255,.75);}'
      + '.kt-kr-float .kt-kr-note{margin:0 4px 8px;padding:6px 9px;border-radius:10px;font-size:12px;font-weight:700;background:rgba(240,251,253,.92);color:#0E7C90;}';
    document.head.appendChild(st);
  }

  function photo(c, px) {
    var ph = Dom.el('span', { class: 'kt-kr-ph', 'aria-hidden': 'true', 'data-kt-noface': '1', style: 'font-size:' + Math.round(px * 0.5) + 'px;' }, '🧒');
    if (c.photo_url) {
      var src = (window.KT && KT.absUrl) ? KT.absUrl(c.photo_url) : c.photo_url;
      var img = Dom.el('img', { src: src, alt: '', decoding: 'async', draggable: 'false' });
      img.addEventListener('error', function () { img.remove(); });
      ph.textContent = '';
      ph.appendChild(img);
    }
    return ph;
  }

  let _float = null;
  function closeFloat() {
    if (!_float) return;
    const f = _float; _float = null;
    f.el.remove();
    if (f.scrim) f.scrim.remove();
    document.removeEventListener('pointerdown', f.outside, true);
    document.removeEventListener('keydown', f.key, true);
    window.removeEventListener('resize', closeFloat);
    window.removeEventListener('scroll', f.scroll, true);
    if (f.anchor && f.anchor.isConnected && f.focusBack) { try { f.anchor.focus({ preventScroll: true }); } catch (e) {} }
  }
  function openFloat(body, anchor, name) {
    css();
    closeFloat();
    const el = Dom.el('div', { class: 'kt-kr-float', role: 'dialog', 'aria-label': 'Shortcuts for ' + name, 'data-no-modal-guard': '1', 'data-kt-nochrome': '1', 'data-kt-no-tips': '1' });   // short tile names, no glossary "?"
    const head = body.querySelector('.kt-kr-pop-head');
    const x = Dom.el('button', { type: 'button', class: 'kt-kr-x', 'aria-label': 'Close', 'data-kt-iconized': '1' }, '✕');
    x.addEventListener('click', function () { closeFloat(); });
    if (head) head.appendChild(x); else el.appendChild(x);
    el.appendChild(body);
    const scrim = Dom.el('div', { class: 'kt-kr-scrim', 'aria-hidden': 'true' });
    document.body.appendChild(scrim);
    document.body.appendChild(el);
    const r = anchor && anchor.getBoundingClientRect ? anchor.getBoundingClientRect() : { left: innerWidth / 2, right: innerWidth / 2, top: innerHeight / 2, bottom: innerHeight / 2, width: 0 };
    const w = el.offsetWidth, h = el.offsetHeight, vw = window.innerWidth, vh = window.innerHeight;
    let left = r.left + r.width / 2 - w / 2;
    left = Math.min(Math.max(8, left), vw - w - 8);
    let top = r.bottom + 8;
    if (top + h > vh - 8) top = r.top - h - 8;
    if (top < 8) top = Math.max(8, vh - h - 8);
    el.style.left = Math.round(left) + 'px';
    el.style.top = Math.round(top) + 'px';
    el.style.transformOrigin = (top < r.top ? 'bottom' : 'top') + ' center';
    const st = { el: el, scrim: scrim, anchor: anchor, focusBack: false };
    st.outside = function (e) { if (!el.contains(e.target)) { if (e.target === scrim) { e.preventDefault(); e.stopPropagation(); } closeFloat(); } };
    st.key = function (e) {
      if (e.key === 'Escape') { e.preventDefault(); st.focusBack = true; closeFloat(); return; }
      const items = Array.prototype.slice.call(el.querySelectorAll('.kt-kr-tile:not([disabled])'));
      const i = items.indexOf(document.activeElement);
      if (i === -1) return;
      if (e.key === 'ArrowRight' || e.key === 'ArrowDown') { e.preventDefault(); (items[i + 1] || items[0]).focus(); }
      else if (e.key === 'ArrowLeft' || e.key === 'ArrowUp') { e.preventDefault(); (items[i - 1] || items[items.length - 1]).focus(); }
    };
    // The page scrolling away from the child closes it; scrolling inside the card does not.
    st.scroll = function (e) { if (!el.contains(e.target)) closeFloat(); };
    _float = st;
    setTimeout(function () {
      if (_float !== st) return;
      document.addEventListener('pointerdown', st.outside, true);
      document.addEventListener('keydown', st.key, true);
      window.addEventListener('resize', closeFloat);
      window.addEventListener('scroll', st.scroll, true);
    }, 0);
    if (!(window.matchMedia && matchMedia('(pointer: coarse)').matches)) {
      const first = el.querySelector('.kt-kr-tile:not([disabled])');
      if (first) first.focus({ preventScroll: true });
    }
  }

  window.KT = window.KT || {};
  KT.kidFloat = { open: openFloat, close: closeFloat, photo: photo, css: css };
})();
