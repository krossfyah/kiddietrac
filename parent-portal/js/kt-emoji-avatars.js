/* ═══════════════════════════════════════════════════════════════════
   KiddieTrac — platform-wide emoji avatars (2026-08-07).
   Anywhere a photo-less person is drawn as an initials circle (tables,
   widgets, lists that build their own avatar markup instead of calling
   KT.avatar), swap the 1–2 letters for a person EMOJI (👦👧👨👩🧑).
   Sex comes from a data-sex/data-gender hint if present, else is assumed
   deterministically from the name (self-corrects once a real sex is set).
   Conservative: only leaf elements that are a small round circle whose
   ENTIRE text is 1–2 latin letters and that have no photo are touched.
   ═══════════════════════════════════════════════════════════════════ */
(function (w, d) {
  'use strict';
  var KT = w.KT || (w.KT = {});
  if (KT.emojiAvatarsLoaded) return;
  KT.emojiAvatarsLoaded = true;

  function normSex(v) {
    if (KT.normSex) return KT.normSex(v);
    var s = String(v == null ? '' : v).trim().toLowerCase();
    if (/^(m|male|boy|man)$/.test(s)) return 'male';
    if (/^(f|female|girl|woman)$/.test(s)) return 'female';
    return '';
  }
  function guessSex(name) {
    if (KT.guessSex) return KT.guessSex(name);
    var s = String(name == null ? '' : name), h = 0;
    for (var i = 0; i < s.length; i++) h = (h * 31 + s.charCodeAt(i)) >>> 0;
    return h % 2 === 0 ? 'male' : 'female';
  }
  function emojiFor(sex, isChild) {
    if (KT.emojiFor) return KT.emojiFor(sex, isChild);
    var s = normSex(sex);
    if (isChild) return s === 'female' ? '👧' : s === 'male' ? '👦' : '🧒';
    return s === 'female' ? '👩' : s === 'male' ? '👨' : '🧑';
  }

  function isInitialsCircle(el) {
    if (!el || el.nodeType !== 1) return false;
    if (el.getAttribute('data-kt-emoji')) return false;          // already done
    if (el.children && el.children.length) return false;          // leaf only (fast reject)
    var txt = (el.textContent || '').trim();
    if (!/^[A-Za-z]{1,2}$/.test(txt)) return false;               // whole text = 1-2 letters
    var cs = w.getComputedStyle(el);
    if (cs.backgroundImage && cs.backgroundImage !== 'none') return false; // has a photo
    var wid = parseFloat(cs.width), hgt = parseFloat(cs.height);
    if (!(wid >= 18 && wid <= 80 && Math.abs(wid - hgt) <= 6)) return false; // small square
    var br = cs.borderRadius || '';
    var round = /50%|9999px/.test(br) || (parseFloat(br) >= (wid / 2) - 2);
    return round;
  }

  function convert(el) {
    var name = el.getAttribute('title') || el.getAttribute('aria-label')
      || el.getAttribute('data-name') || (el.textContent || '').trim();
    var isChild = el.hasAttribute('data-child') || /\b(child|kid)\b/i.test(el.className || '');
    var sex = normSex(el.getAttribute('data-sex') || el.getAttribute('data-gender')) || guessSex(name);
    var wid = parseFloat(w.getComputedStyle(el).width) || el.offsetWidth || 32;
    el.setAttribute('data-kt-initials', (el.textContent || '').trim());  // keep the letters for reference
    el.setAttribute('data-kt-emoji', '1');
    el.textContent = emojiFor(sex, isChild);
    el.style.fontSize = Math.round(wid * 0.6) + 'px';
    el.style.lineHeight = '1';
    if (!/flex/.test(el.style.display)) {
      el.style.display = 'inline-flex';
      el.style.alignItems = 'center';
      el.style.justifyContent = 'center';
    }
  }

  function sweep() {
    var main = d.getElementById('appMain') || d.body;
    if (!main) return;
    var nodes = main.querySelectorAll('span,div,b,strong');
    for (var i = 0; i < nodes.length; i++) {
      try { if (isInitialsCircle(nodes[i])) convert(nodes[i]); } catch (e) {}
    }
  }

  KT.sweepEmojiAvatars = function () { try { sweep(); } catch (e) {} };
  if (KT.sweepBus && KT.sweepBus.on) KT.sweepBus.on(sweep); else setInterval(sweep, 3000);
  w.addEventListener('hashchange', function () { setTimeout(sweep, 200); });
  setTimeout(sweep, 500);
})(window, document);
