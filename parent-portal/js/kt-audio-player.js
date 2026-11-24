/* ═══════════════════════════════════════════════════════════════════
   KiddieTrac — one audio player everywhere (2026-10-23)

   Anthony: "for audio clips in the proposal and wherever else add a play, pause, stop
   player as well". Every <audio controls> the portal shows (lead activity recordings,
   voicemails on the Phone screen and the dial pad, the platform voicemail list, chat voice
   notes) becomes the same small player: ▶ Play / ⏸ Pause, ⏹ Stop (back to the start), a
   bar you can tap or drag to jump, and the time. Only one clip plays at a time.

   An <audio> without the controls attribute (ringing, the call's own sound) is never
   touched. Opt out with data-kt-noplayer on the element or an ancestor.
   ═══════════════════════════════════════════════════════════════════ */
(function (w) {
  'use strict';
  if (w.__ktAudioPlayer) return; w.__ktAudioPlayer = true;

  (function css() {
    var st = document.createElement('style'); st.id = 'kt-ap-css';
    st.textContent = '.kt-ap{display:inline-flex;align-items:center;gap:6px;max-width:100%;min-width:230px;box-sizing:border-box;padding:4px 8px 4px 4px;border:1px solid #CFE3F1;border-radius:999px;background:#F5FAFD;vertical-align:middle;font-family:inherit}'
      + '.kt-ap button{flex:0 0 auto;width:30px;height:30px;min-height:0 !important;border:0;border-radius:50%;cursor:pointer;display:inline-flex;align-items:center;justify-content:center;padding:0;font-size:13px;line-height:1}'
      + '.kt-ap .kt-ap-play{background:#0F766E;color:#fff}.kt-ap .kt-ap-play:hover{background:#0B5F58}'
      + '.kt-ap .kt-ap-stop{background:#fff;color:#334155;border:1px solid #CBD5E1}.kt-ap .kt-ap-stop:disabled{opacity:.45;cursor:default}'
      + '.kt-ap .kt-ap-bar{flex:1 1 auto;min-width:60px;height:6px;border-radius:3px;background:#DCE7EF;position:relative;cursor:pointer;touch-action:none}'
      + '.kt-ap .kt-ap-fill{position:absolute;left:0;top:0;bottom:0;width:0;border-radius:3px;background:#0F766E}'
      + '.kt-ap .kt-ap-knob{position:absolute;top:50%;width:12px;height:12px;margin:-6px 0 0 -6px;border-radius:50%;background:#0F766E;left:0;box-shadow:0 0 0 2px #fff}'
      + '.kt-ap .kt-ap-time{flex:0 0 auto;font-size:11.5px;color:#475569;font-variant-numeric:tabular-nums;white-space:nowrap}'
      + '.kt-ap.err{border-color:#FCA5A5;background:#FEF2F2}.kt-ap.err .kt-ap-time{color:#B91C1C}';
    (document.head || document.documentElement).appendChild(st);
  })();

  var players = [];
  function fmt(s) { if (!isFinite(s) || s < 0) return '0:00'; s = Math.floor(s); return Math.floor(s / 60) + ':' + ('0' + (s % 60)).slice(-2); }

  function enhance(au) {
    if (au.__ktAp || !au.hasAttribute('controls') || au.closest('[data-kt-noplayer]')) return;
    au.__ktAp = true;
    var wrap = document.createElement('span'); wrap.className = 'kt-ap'; wrap.setAttribute('data-kt-noband', '1'); wrap.setAttribute('data-kt-iconized', '1');
    var wantW = au.style.width; if (wantW && /px|%/.test(wantW)) wrap.style.width = wantW;
    wrap.innerHTML = '<button type="button" class="kt-ap-play" aria-label="Play" title="Play">▶</button>'
      + '<button type="button" class="kt-ap-stop" aria-label="Stop" title="Stop" disabled>⏹</button>'
      + '<span class="kt-ap-bar" role="slider" aria-label="Position" tabindex="0"><span class="kt-ap-fill"></span><span class="kt-ap-knob"></span></span>'
      + '<span class="kt-ap-time">0:00</span>';
    au.parentNode.insertBefore(wrap, au);
    au.removeAttribute('controls'); au.style.display = 'none';
    wrap.appendChild(au);
    var play = wrap.querySelector('.kt-ap-play'), stop = wrap.querySelector('.kt-ap-stop'), bar = wrap.querySelector('.kt-ap-bar'),
      fill = wrap.querySelector('.kt-ap-fill'), knob = wrap.querySelector('.kt-ap-knob'), time = wrap.querySelector('.kt-ap-time');
    function paint() {
      var d = au.duration, t = au.currentTime || 0, pct = isFinite(d) && d > 0 ? Math.min(100, t / d * 100) : 0;
      fill.style.width = pct + '%'; knob.style.left = pct + '%';
      time.textContent = fmt(t) + (isFinite(d) && d > 0 ? ' / ' + fmt(d) : '');
      var on = !au.paused && !au.ended;
      play.textContent = on ? '⏸' : '▶'; play.title = on ? 'Pause' : 'Play'; play.setAttribute('aria-label', play.title);
      stop.disabled = !on && t === 0;
      bar.setAttribute('aria-valuenow', Math.round(pct));
    }
    ['timeupdate', 'durationchange', 'loadedmetadata', 'play', 'pause', 'ended', 'seeked'].forEach(function (ev) { au.addEventListener(ev, paint); });
    au.addEventListener('play', function () { players.forEach(function (p) { if (p !== au && !p.paused) p.pause(); }); });
    au.addEventListener('error', function () { wrap.classList.add('err'); time.textContent = 'Can’t play'; });
    play.addEventListener('click', function (e) { e.stopPropagation(); if (au.paused || au.ended) { var p = au.play(); if (p && p.catch) p.catch(function () {}); } else au.pause(); });
    stop.addEventListener('click', function (e) { e.stopPropagation(); au.pause(); try { au.currentTime = 0; } catch (er) {} paint(); });
    function seekAt(x) { var r = bar.getBoundingClientRect(), d = au.duration; if (!isFinite(d) || d <= 0 || !r.width) return; try { au.currentTime = Math.max(0, Math.min(1, (x - r.left) / r.width)) * d; } catch (er) {} paint(); }
    bar.addEventListener('pointerdown', function (e) {
      e.stopPropagation(); seekAt(e.clientX);
      try { bar.setPointerCapture(e.pointerId); } catch (er) {}
      var mv = function (ev) { seekAt(ev.clientX); }, up = function () { bar.removeEventListener('pointermove', mv); bar.removeEventListener('pointerup', up); };
      bar.addEventListener('pointermove', mv); bar.addEventListener('pointerup', up);
    });
    bar.addEventListener('keydown', function (e) {
      if (!isFinite(au.duration)) return;
      if (e.key === 'ArrowRight') { au.currentTime = Math.min(au.duration, au.currentTime + 5); e.preventDefault(); }
      if (e.key === 'ArrowLeft') { au.currentTime = Math.max(0, au.currentTime - 5); e.preventDefault(); }
    });
    players.push(au);
    paint();
  }

  function scan(root) {
    if (!root || !root.querySelectorAll) return;
    if (root.tagName === 'AUDIO') { enhance(root); return; }
    root.querySelectorAll('audio[controls]').forEach(enhance);
  }
  function start() {
    scan(document.body);
    new MutationObserver(function (muts) {
      for (var i = 0; i < muts.length; i++) {
        var a = muts[i].addedNodes;
        for (var j = 0; j < a.length; j++) if (a[j].nodeType === 1) scan(a[j]);
      }
      players = players.filter(function (p) { return p.isConnected; });
    }).observe(document.body, { childList: true, subtree: true });
  }
  if (document.body) start(); else document.addEventListener('DOMContentLoaded', start);
  w.KT = w.KT || {}; w.KT.audioPlayer = { enhance: enhance };
})(window);
