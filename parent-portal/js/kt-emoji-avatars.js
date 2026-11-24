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

  function convert(el, measured) {
    var name = el.getAttribute('title') || el.getAttribute('aria-label')
      || el.getAttribute('data-name') || (el.textContent || '').trim();
    var isChild = el.hasAttribute('data-child') || /\b(child|kid)\b/i.test(el.className || '');
    var sex = normSex(el.getAttribute('data-sex') || el.getAttribute('data-gender')) || guessSex(name);
    var wid = measured || parseFloat(w.getComputedStyle(el).width) || el.offsetWidth || 32;
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

  /* PERF (2026-10-28): 0.9 s of a 4x-throttled Families render. Each circle was measured and then converted
     before the next was measured (every measurement a full style recalculation), and every 1-2 letter label that
     is NOT an avatar was measured again on every DOM change. Now all are measured, then all converted, and a
     rejected label is not looked at again until its text changes. */
  var rejected = (typeof WeakMap === 'function') ? new WeakMap() : null;
  function sweep() {
    var main = d.getElementById('appMain') || d.body;
    if (!main) return;
    var nodes = main.querySelectorAll('span,div,b,strong');
    var hits = [];
    for (var i = 0; i < nodes.length; i++) {
      var n = nodes[i];
      if (n.children.length || n.getAttribute('data-kt-emoji')) continue;
      var txt = n.textContent;
      if (rejected && rejected.get(n) === txt) continue;
      try {
        if (isInitialsCircle(n)) hits.push([n, parseFloat(w.getComputedStyle(n).width) || n.offsetWidth || 32]);
        else if (rejected && txt && txt.length <= 4) rejected.set(n, txt);
      } catch (e) {}
    }
    for (var j = 0; j < hits.length; j++) { try { convert(hits[j][0], hits[j][1]); } catch (e) {} }
  }

  /* BEFORE THE FIRST PAINT (2026-10-30, Anthony: "avatars and display pics in the emulator pop in and out"). The sweep
     ran on the sweep bus / a timer, i.e. AFTER the screen had painted, so every re-render showed the letter ("E"), then
     the face a beat later - on every screen change, in the phone app most of all. A MutationObserver callback runs
     before the browser paints the change, so a circle is converted in the same frame it appears. Only the new nodes are
     looked at, and only leaves whose whole text is 1-2 letters are measured (all reads, then all writes); the sweep
     stays as the safety net for anything this misses. */
  function freshCircles(muts) {
    var cand = [], seen = [];
    function take(el) {
      if (!el || el.nodeType !== 1 || el.children.length || el.getAttribute('data-kt-emoji')) return;
      if (!/^\s*[A-Za-z]{1,2}\s*$/.test(el.textContent || '')) return;
      if (seen.indexOf(el) !== -1) return;
      seen.push(el); cand.push(el);
    }
    for (var i = 0; i < muts.length; i++) {
      var add = muts[i].addedNodes;
      for (var j = 0; j < add.length; j++) {
        var n = add[j];
        if (n.nodeType === 3) { take(n.parentElement); continue; }
        if (n.nodeType !== 1) continue;
        if (/^(SPAN|DIV|B|STRONG)$/.test(n.tagName)) take(n);
        if (n.firstElementChild) {
          var inner = n.querySelectorAll('span,div,b,strong');
          for (var k = 0; k < inner.length && cand.length < 400; k++) take(inner[k]);
        }
      }
    }
    if (!cand.length) return;
    var hits = [];
    for (var c = 0; c < cand.length; c++) {
      try { if (cand[c].isConnected && isInitialsCircle(cand[c])) hits.push([cand[c], parseFloat(w.getComputedStyle(cand[c]).width) || 32]); } catch (e) {}
    }
    for (var h = 0; h < hits.length; h++) { try { convert(hits[h][0], hits[h][1]); } catch (e) {} }
  }
  try {
    var mo = new MutationObserver(function (m) { try { freshCircles(m); } catch (e) {} });
    var start = function () { if (d.body) mo.observe(d.body, { childList: true, subtree: true }); };
    if (d.body) start(); else d.addEventListener('DOMContentLoaded', start);
  } catch (e) {}

  KT.sweepEmojiAvatars = function () { try { sweep(); } catch (e) {} };
  if (KT.sweepBus && KT.sweepBus.on) KT.sweepBus.on(sweep); else setInterval(sweep, 3000);
  w.addEventListener('hashchange', function () { setTimeout(sweep, 200); });
  setTimeout(sweep, 500);
})(window, document);
