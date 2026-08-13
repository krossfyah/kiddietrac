/* ═══════════════════════════════════════════════════════════════════
   KiddieTrac — avatar zoom (2026-08-12, rev3)
   Click any profile photo to open it large, with who it belongs to: their
   full name, their role, and a way through to their record.

   ONE document-level capture-phase click delegate — no per-element wiring,
   survives a parent's stopPropagation, and works for avatars rendered long
   after load.

   WHY rev3: the portal renders avatars in TWO shapes, and rev2 only matched
   one of them.

     A. <span class="kt-avatar" style="…background-image:url(photo)">
        — what KT.avatar() emits. Matched, and always worked.

     B. <div style="border-radius:50%;overflow:hidden"><img src="photo"></div>
        — what avatarCircle() emits: the ROUND part is the wrapper and the
        photo is a plain square <img> inside it. rev2 tested roundness on the
        <img> itself, which is not round, and then looked for a
        background-image on the wrapper, which does not have one. So every
        avatar in user management, families and the staff lists was dead while
        the ones on the dashboard feed worked — "it worked once, now it
        doesn't", depending entirely on which screen you were looking at.

   So roundness is now judged on the element OR the wrapper that clips it.
   Placeholder avatars (emoji, initials, silhouettes, data: URIs) are still
   ignored — there is nothing to enlarge.
   ═══════════════════════════════════════════════════════════════════ */
(function (w) {
  'use strict';
  var d = w.document;

  function isRealPhotoUrl(src) {
    if (!src) return false;
    if (/^data:/i.test(src)) return false;                        // inline placeholder / emoji
    if (/logo-wordmark|placeholder|silhouette|avatar-default/i.test(src)) return false;
    return true;
  }

  // Pull a background-image url() off an element (computed, so inherited/class styles count).
  function bgPhoto(el) {
    if (!el || el.nodeType !== 1) return null;
    var bi = '';
    try { bi = w.getComputedStyle(el).backgroundImage || ''; } catch (e) { return null; }
    if (!bi || bi === 'none') return null;
    var m = bi.match(/url\((['"]?)(.*?)\1\)/i);
    if (!m) return null;
    return isRealPhotoUrl(m[2]) ? m[2] : null;
  }

  function isRound(el) {
    if (!el || el.nodeType !== 1) return false;
    if (el.classList && (el.classList.contains('kt-avatar') || el.classList.contains('avatar'))) return true;
    try {
      var cs = w.getComputedStyle(el);
      var br = cs.borderRadius || '';
      if (br.indexOf('%') > -1 && parseFloat(br) >= 40) return true;
      var wpx = el.clientWidth || el.offsetWidth || 0;
      if (wpx && parseFloat(br) >= (wpx / 2) - 4) return true;
    } catch (e) {}
    return false;
  }

  function sizeOk(el) {
    var wpx = el.clientWidth || el.offsetWidth || el.width || 0;
    return wpx === 0 || (wpx >= 16 && wpx <= 240);                // 0 = not laid out yet → allow
  }

  // Round, or clipped to round by the wrapper it fills — shape B above.
  function looksAvatar(el) {
    if (isRound(el)) return true;
    if (el.tagName === 'IMG') {
      var p = el.parentElement;
      if (p && isRound(p) && sizeOk(p)) return true;
    }
    return false;
  }

  function attr(el, name) {
    return (el && el.getAttribute && el.getAttribute(name)) || '';
  }

  // Name / role / user id may sit on the avatar itself or on the row or card
  // that contains it, so look upward a few levels for each.
  function metaFor(el) {
    var meta = { name: '', role: '', userId: '' };
    var node = el, hops = 0;
    while (node && node.nodeType === 1 && hops < 6) {
      meta.name = meta.name || attr(node, 'data-kt-name') || attr(node, 'alt')
        || attr(node, 'title') || attr(node, 'aria-label');
      meta.role = meta.role || attr(node, 'data-kt-role');
      meta.userId = meta.userId || attr(node, 'data-kt-user-id');
      node = node.parentElement; hops++;
    }
    meta.name = String(meta.name || '').trim();
    meta.role = String(meta.role || '').replace(/_/g, ' ').trim();
    return meta;
  }

  // From a click target, walk up a few levels to find an avatar photo.
  function findAvatar(target) {
    var el = target, hops = 0;
    while (el && el.nodeType === 1 && hops < 4) {
      if (el.getAttribute && el.getAttribute('data-kt-no-zoom') != null) return null;
      var src = null;
      if (el.tagName === 'IMG') {
        var isrc = el.currentSrc || el.getAttribute('src') || '';
        if (isRealPhotoUrl(isrc) && sizeOk(el) && looksAvatar(el)) src = isrc;
      }
      if (!src && el.classList && el.classList.contains('kt-avatar')) src = bgPhoto(el);
      if (!src) {
        var b = bgPhoto(el);
        if (b && sizeOk(el) && isRound(el)) src = b;
      }
      // The click can land on the round WRAPPER rather than the photo it clips —
      // its edge, or the gap left by object-fit. Look down as well as up, but only
      // into a small round holder (or a container holding nothing but the photo),
      // so this never turns a whole row or card into an avatar.
      if (!src && sizeOk(el) && (isRound(el) || el.childElementCount === 1) && el.querySelector) {
        var inner = el.querySelector('img');
        if (inner && inner !== el) {
          var innerSrc = inner.currentSrc || inner.getAttribute('src') || '';
          if (isRealPhotoUrl(innerSrc) && sizeOk(inner)) src = innerSrc;
        }
      }
      if (src) {
        var meta = metaFor(el);
        return { src: src, name: meta.name, role: meta.role, userId: meta.userId };
      }
      el = el.parentElement; hops++;
    }
    return null;
  }

  // Only offer the profile link to someone who can actually open a user record.
  function viewerCanOpenRecords() {
    try {
      var role = w.__ktActiveRole ? String(w.__ktActiveRole()) : '';
      return /platform_admin|agency_admin|centre_director|director|admin/.test(role);
    } catch (e) { return false; }
  }

  function open(src, name, role, userId) {
    var ov = d.createElement('div');
    ov.className = 'kt-av-zoom';
    ov.style.cssText = 'position:fixed;inset:0;z-index:2147483200;background:rgba(8,20,35,.82);display:flex;align-items:center;justify-content:center;padding:24px;cursor:zoom-out;opacity:0;transition:opacity .18s ease;';
    var fig = d.createElement('figure');
    fig.style.cssText = 'margin:0;display:flex;flex-direction:column;align-items:center;gap:12px;max-width:92vw;max-height:92vh;cursor:default;';
    var big = d.createElement('img');
    big.src = src;
    big.alt = name || '';
    big.style.cssText = 'max-width:min(520px,92vw);max-height:70vh;width:auto;height:auto;border-radius:18px;box-shadow:0 30px 80px rgba(0,0,0,.5);background:#fff;object-fit:contain;transform:scale(.94);transition:transform .18s ease;';
    fig.appendChild(big);

    if (name) {
      var cap = d.createElement('figcaption');
      cap.textContent = name;
      cap.style.cssText = 'color:#fff;font-weight:800;font-size:19px;letter-spacing:.2px;text-shadow:0 1px 3px rgba(0,0,0,.45);text-align:center;';
      fig.appendChild(cap);
    }
    if (role) {
      var rl = d.createElement('div');
      rl.textContent = role.replace(/\b\w/g, function (c) { return c.toUpperCase(); });
      rl.style.cssText = 'color:#0a1f44;background:rgba(255,255,255,.92);font-weight:700;font-size:12px;letter-spacing:.6px;text-transform:uppercase;padding:4px 12px;border-radius:999px;';
      fig.appendChild(rl);
    }
    // Straight through to the person's record, for anyone who can open one.
    if (userId && viewerCanOpenRecords() && w.KT && typeof w.KT.openUserRecord === 'function') {
      var go = d.createElement('button');
      go.type = 'button';
      go.textContent = 'View profile →';
      go.style.cssText = 'margin-top:2px;padding:9px 18px;border:0;border-radius:9px;background:linear-gradient(135deg,#13b7cc,#1F6080);color:#fff;font-size:13.5px;font-weight:700;cursor:pointer;box-shadow:0 8px 20px -8px rgba(0,0,0,.6);';
      go.addEventListener('click', function (e) {
        e.stopPropagation();
        close();
        try { w.KT.openUserRecord(userId); } catch (err) {}
      });
      fig.appendChild(go);
    }

    var hint = d.createElement('div');
    hint.textContent = 'Click anywhere or press Esc to close';
    hint.style.cssText = 'color:rgba(255,255,255,.6);font-size:12px;';
    fig.appendChild(hint);
    var x = d.createElement('button');
    x.setAttribute('aria-label', 'Close');
    x.textContent = '✕';
    x.style.cssText = 'position:fixed;top:18px;right:20px;width:40px;height:40px;border-radius:50%;border:0;background:rgba(255,255,255,.16);color:#fff;font-size:18px;cursor:pointer;';
    ov.appendChild(fig);
    ov.appendChild(x);
    function close() {
      // Mark it closing BEFORE the animation: the click delegate below treats a
      // marked overlay as gone, so a click during the 180ms fade still opens the
      // next avatar instead of being swallowed.
      ov.setAttribute('data-closing', '1');
      ov.style.opacity = '0';
      setTimeout(function () { if (ov.parentNode) ov.parentNode.removeChild(ov); }, 180);
      d.removeEventListener('keydown', onKey, true);
    }
    function onKey(e) { if (e.key === 'Escape') { e.preventDefault(); close(); } }
    ov.addEventListener('click', close);
    x.addEventListener('click', close);
    d.addEventListener('keydown', onKey, true);
    d.body.appendChild(ov);
    requestAnimationFrame(function () { ov.style.opacity = '1'; big.style.transform = 'scale(1)'; });
    return ov;
  }

  // ONE capture-phase listener catches every avatar click before any row/card
  // handler can swallow it, and works for elements added after load.
  d.addEventListener('click', function (e) {
    // Self-healing: a leftover overlay must never make every future avatar
    // click a no-op. Anything mid-fade, or orphaned by a re-render, is cleared.
    var cur = d.querySelector('.kt-av-zoom');
    if (cur) {
      if (cur.getAttribute('data-closing') || !cur.isConnected) { if (cur.parentNode) cur.parentNode.removeChild(cur); }
      else return;                                                // genuinely open
    }
    var hit = findAvatar(e.target);
    if (!hit) return;
    e.preventDefault();
    e.stopPropagation();
    open(hit.src, hit.name, hit.role, hit.userId);
  }, true);

  // Show a zoom cursor over photo avatars (best-effort; the click delegate is
  // the source of truth and does not depend on this).
  function markCursor() {
    try {
      d.querySelectorAll('img.kt-avatar,span.kt-avatar,img[src]:not([data-kt-zc])').forEach(function (el) {
        var src = el.tagName === 'IMG' ? (el.currentSrc || el.getAttribute('src')) : bgPhoto(el);
        if (isRealPhotoUrl(src) && looksAvatar(el)) el.style.cursor = 'zoom-in';
        el.setAttribute('data-kt-zc', '1');
      });
    } catch (e) {}
  }
  if (d.readyState === 'loading') d.addEventListener('DOMContentLoaded', markCursor); else markCursor();
  if (w.MutationObserver) {
    var t = null;
    new MutationObserver(function () { if (t) return; t = setTimeout(function () { t = null; markCursor(); }, 400); })
      .observe(d.body || d.documentElement, { childList: true, subtree: true });
  }

  w.KT = w.KT || {};
  w.KT.avatarZoom = {
    open: open,
    // Paste KT.avatarZoom.debug() in the console to see what the delegate makes
    // of every avatar-shaped thing on the current screen.
    debug: function () {
      var out = [];
      d.querySelectorAll('img,span,div').forEach(function (el) {
        var src = el.tagName === 'IMG' ? (el.currentSrc || el.getAttribute('src')) : bgPhoto(el);
        if (!isRealPhotoUrl(src)) return;
        if (!looksAvatar(el)) return;
        var m = metaFor(el);
        out.push({ tag: el.tagName, cls: el.className, src: String(src).slice(-42), name: m.name, role: m.role, userId: m.userId });
      });
      return out;
    }
  };
})(window);
