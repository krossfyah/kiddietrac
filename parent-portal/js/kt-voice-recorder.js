/* ═══════════════════════════════════════════════════════════════════
   KiddieTrac — the voice note recorder (2026-09-10).

   ONE recorder for every composer that can send audio: the Messenger family
   thread, the colleague thread, and the parent Messages screen. Before this they
   were three separate MediaRecorder blocks that behaved like three products —
   the family thread SENT the moment you pressed stop, with no review and no
   undo; the colleague thread staged the file with no way to hear it; only the
   parent screen let you play it back. None of the three showed that the
   microphone was picking anything up, and a recorder that shows nothing is
   indistinguishable from a broken one until a parent gets silence.

   IT LIVES IN THE COMPOSER, NOT IN A DIALOG. The first version put this in a
   modal over the page, which is too much furniture for holding down a button and
   talking for six seconds. It takes the place of the message row it belongs to,
   for as long as it is needed, and then gives it back.

   One row, and it says only what matters:

       ●  0:07   ▁▃▅▂▇▃▁▅   ✕   ⏹        while recording
       ▶  0:07   ▁▃▅▂▇▃▁▅   ✕   Send     once it has stopped

   API — resolves to a File, or to null if they backed out:

       var file = await KT.recordVoiceNote({ anchor: inputRowEl });

   `anchor` is the composer row to stand in for; it is hidden while the recorder
   is up and restored on every exit. The caller keeps whatever it did with the
   file — this module owns the microphone and the discipline, not the
   conversation.
   ═══════════════════════════════════════════════════════════════════ */
(function (window, document) {
  'use strict';

  var KT = window.KT || (window.KT = {});
  if (KT.recordVoiceNote) return;

  var MAX_MS = 5 * 60 * 1000;   // a voice note, not a podcast — auto-stops here
  var MIN_BYTES = 800;          // below this the take is silence, not speech
  var BARS = 14;                // enough to read as a voice, few enough to stay a row

  /* The container MediaRecorder will actually give us. Safari/iOS has no webm and
     produces audio/mp4; asking for an unsupported type throws, so probe first. */
  function pickMime() {
    var MR = window.MediaRecorder;
    if (!MR || !MR.isTypeSupported) return '';
    var want = ['audio/webm;codecs=opus', 'audio/webm', 'audio/mp4', 'audio/ogg'];
    for (var i = 0; i < want.length; i++) { if (MR.isTypeSupported(want[i])) return want[i]; }
    return '';
  }

  function extFor(type) {
    if (type.indexOf('mp4') >= 0) return 'm4a';
    if (type.indexOf('ogg') >= 0) return 'ogg';
    return 'webm';
  }

  function mmss(ms) {
    var s = Math.floor(ms / 1000);
    return Math.floor(s / 60) + ':' + ('0' + (s % 60)).slice(-2);
  }

  function toast(icon, title, msg, colour) {
    try { if (KT.toast) KT.toast(icon, title, msg, colour); } catch (e) {}
  }

  KT.recordVoiceNote = function (opts) {
    opts = opts || {};
    var anchor = opts.anchor || null;
    var acceptLabel = opts.acceptLabel || 'Send';

    return new Promise(function (resolve) {
      if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia || !window.MediaRecorder) {
        toast('⚠️', 'Not supported', 'This device cannot record audio.', '#DC2626');
        return resolve(null);
      }
      if (!anchor || !anchor.parentNode) {
        toast('⚠️', 'Cannot record here', '', '#DC2626');
        return resolve(null);
      }

      var stream = null, rec = null, chunks = [], mime = '';
      var audioCtx = null, analyser = null, rafId = null, tickId = null;
      var startedAt = 0, heldMs = 0, blob = null, blobUrl = null, audioEl = null, done = false;
      var anchorDisplay = anchor.style.display;

      /* Every exit goes through here. A live track leaves the browser's recording
         indicator burning after the bar is gone, which reads as "it is still
         listening to me" — and on a phone it is a real battery and privacy cost. */
      function release() {
        if (rafId) { cancelAnimationFrame(rafId); rafId = null; }
        if (tickId) { clearInterval(tickId); tickId = null; }
        try { if (rec && rec.state === 'recording') rec.stop(); } catch (e) {}
        try { if (stream) stream.getTracks().forEach(function (t) { t.stop(); }); } catch (e) {}
        stream = null;
        try { if (audioCtx && audioCtx.close) audioCtx.close(); } catch (e) {}
        audioCtx = null; analyser = null;
      }

      function finish(file) {
        if (done) return;
        done = true;
        release();
        try { if (audioEl) audioEl.pause(); } catch (e) {}
        try { if (blobUrl) URL.revokeObjectURL(blobUrl); } catch (e) {}
        try { if (bar.parentNode) bar.parentNode.removeChild(bar); } catch (e) {}
        anchor.style.display = anchorDisplay;      // the composer comes back
        document.removeEventListener('keydown', onKey, true);
        resolve(file || null);
      }

      function onKey(e) {
        if (e.key === 'Escape') { e.preventDefault(); e.stopPropagation(); finish(null); }
      }

      // ── the row ────────────────────────────────────────────────────────────
      var bar = document.createElement('div');
      bar.className = 'kt-voice-bar';
      bar.style.cssText = 'display:flex;align-items:center;gap:10px;padding:8px 10px 10px;min-height:44px;'
        + 'box-sizing:border-box;';

      var lead = document.createElement('button');
      lead.type = 'button';
      lead.style.cssText = 'flex:0 0 auto;width:28px;height:28px;border-radius:50%;border:none;cursor:default;'
        + 'background:#FEE2E2;color:#DC2626;font-size:13px;line-height:1;display:inline-flex;'
        + 'align-items:center;justify-content:center;padding:0;';
      lead.textContent = '●';
      lead.setAttribute('aria-hidden', 'true');

      var time = document.createElement('span');
      time.style.cssText = 'flex:0 0 auto;font-size:12.5px;font-weight:700;color:#0D1B2A;'
        + 'font-variant-numeric:tabular-nums;min-width:34px;';
      time.textContent = '0:00';

      var meter = document.createElement('div');
      meter.style.cssText = 'flex:1;min-width:0;display:flex;align-items:center;gap:2px;height:24px;overflow:hidden;';

      var bars = [];
      for (var b = 0; b < BARS; b++) {
        var el = document.createElement('span');
        el.style.cssText = 'flex:1;min-width:2px;height:3px;border-radius:2px;background:#CBD5E1;'
          + 'transition:height .06s linear;';
        meter.appendChild(el);
        bars.push(el);
      }

      function iconBtn(glyph, title, colour) {
        var el = document.createElement('button');
        el.type = 'button';
        el.title = title;
        el.setAttribute('aria-label', title);
        el.style.cssText = 'flex:0 0 auto;width:32px;height:32px;border-radius:50%;border:none;cursor:pointer;'
          + 'background:transparent;color:' + (colour || '#64748B') + ';font-size:16px;line-height:1;'
          + 'display:inline-flex;align-items:center;justify-content:center;padding:0;';
        el.textContent = glyph;
        return el;
      }

      var discard = iconBtn('✕', 'Discard', '#94A3B8');
      var stopBtn = iconBtn('⏹', 'Stop', '#DC2626');

      var sendBtn = document.createElement('button');
      sendBtn.type = 'button';
      sendBtn.style.cssText = 'flex:0 0 auto;height:32px;padding:0 14px;border-radius:16px;border:none;'
        + 'background:#159FB4;color:#fff;font-size:13px;font-weight:700;cursor:pointer;display:none;';
      sendBtn.textContent = acceptLabel;

      bar.appendChild(lead);
      bar.appendChild(time);
      bar.appendChild(meter);
      bar.appendChild(discard);
      bar.appendChild(stopBtn);
      bar.appendChild(sendBtn);

      discard.addEventListener('click', function () { finish(null); });

      // ── level meter, driven by the microphone itself ───────────────────────
      /* Not an animation pretending to listen: a meter that moves whether or not
         sound is arriving would answer the only question it exists to answer —
         "is this picking me up?" — with a lie. */
      function startMeter() {
        try {
          var Ctx = window.AudioContext || window.webkitAudioContext;
          if (!Ctx) return;
          audioCtx = new Ctx();
          var src = audioCtx.createMediaStreamSource(stream);
          analyser = audioCtx.createAnalyser();
          analyser.fftSize = 512;
          analyser.smoothingTimeConstant = 0.75;
          src.connect(analyser);        // analyser only — never to destination, or it echoes
          var data = new Uint8Array(analyser.frequencyBinCount);
          var seen = 0;

          var draw = function () {
            if (!analyser) return;
            analyser.getByteTimeDomainData(data);
            var sum = 0;
            for (var i = 0; i < data.length; i++) { var v = (data[i] - 128) / 128; sum += v * v; }
            var level = Math.min(1, Math.sqrt(sum / data.length) * 3.2);
            seen = Math.max(level, seen * 0.94);

            for (var j = 0; j < bars.length; j++) {
              var d = Math.abs(j - (bars.length - 1) / 2) / ((bars.length - 1) / 2);
              var h = 3 + Math.round(level * 21 * (1 - d * 0.6) * (0.7 + Math.random() * 0.6));
              bars[j].style.height = Math.max(3, Math.min(24, h)) + 'px';
              bars[j].style.background = level > 0.02 ? '#159FB4' : '#CBD5E1';
            }
            /* Nothing arriving at all: say so quietly, because a muted headset or a
               microphone another app has grabbed looks exactly like a working one. */
            time.style.color = seen < 0.015 ? '#B45309' : '#0D1B2A';
            time.title = seen < 0.015 ? 'No sound is reaching the microphone' : '';
            rafId = requestAnimationFrame(draw);
          };
          rafId = requestAnimationFrame(draw);
        } catch (e) { /* no meter is survivable; recording is not */ }
      }

      // ── review ─────────────────────────────────────────────────────────────
      function showReview() {
        if (rafId) { cancelAnimationFrame(rafId); rafId = null; }
        blobUrl = URL.createObjectURL(blob);
        audioEl = new Audio(blobUrl);

        lead.style.background = '#E0F2F5';
        lead.style.color = '#0E7490';
        lead.style.cursor = 'pointer';
        lead.textContent = '▶';
        lead.removeAttribute('aria-hidden');
        lead.title = 'Play it back';
        lead.setAttribute('aria-label', 'Play it back');
        time.textContent = mmss(heldMs);
        time.style.color = '#0D1B2A';
        time.title = '';
        stopBtn.style.display = 'none';
        sendBtn.style.display = 'inline-flex';
        discard.title = 'Delete this recording';

        // A still waveform, so the row still reads as a recording rather than a blank.
        for (var j = 0; j < bars.length; j++) {
          var d = Math.abs(j - (bars.length - 1) / 2) / ((bars.length - 1) / 2);
          bars[j].style.height = (4 + Math.round(14 * (1 - d * 0.7))) + 'px';
          bars[j].style.background = '#94A3B8';
        }

        lead.addEventListener('click', function () {
          if (audioEl.paused) { audioEl.play().catch(function () {}); }
          else { audioEl.pause(); }
        });
        audioEl.addEventListener('play', function () { lead.textContent = '⏸'; });
        audioEl.addEventListener('pause', function () { lead.textContent = '▶'; });
        audioEl.addEventListener('ended', function () { lead.textContent = '▶'; });
        audioEl.addEventListener('timeupdate', function () {
          if (!isNaN(audioEl.currentTime)) time.textContent = mmss(audioEl.currentTime * 1000);
        });

        sendBtn.addEventListener('click', function () {
          var type = blob.type || mime || 'audio/webm';
          finish(new File([blob], 'voice-' + Date.now() + '.' + extFor(type), { type: type }));
        });
      }

      // ── recording ──────────────────────────────────────────────────────────
      function begin() {
        chunks = [];
        try {
          rec = mime ? new window.MediaRecorder(stream, { mimeType: mime })
                     : new window.MediaRecorder(stream);
        } catch (e) {
          try { rec = new window.MediaRecorder(stream); } catch (e2) {
            toast('⚠️', 'Cannot record', 'This browser refused to start the recorder.', '#DC2626');
            return finish(null);
          }
        }

        rec.ondataavailable = function (ev) { if (ev.data && ev.data.size) chunks.push(ev.data); };
        rec.onstop = function () {
          if (tickId) { clearInterval(tickId); tickId = null; }
          heldMs = Date.now() - startedAt;
          var type = (chunks[0] && chunks[0].type) || mime || 'audio/webm';
          blob = new Blob(chunks, { type: type });
          if (blob.size < MIN_BYTES) {
            /* Too short to be anything. Sending it would put an empty bubble in front
               of a parent, so say so and stay open rather than send silence. */
            toast('🎤', 'Too short', 'Hold on a moment longer.', '#B45309');
            blob = null;
            return finish(null);
          }
          showReview();
        };

        rec.start();
        startedAt = Date.now();
        startMeter();

        tickId = setInterval(function () {
          var ms = Date.now() - startedAt;
          time.textContent = mmss(ms);
          if (ms >= MAX_MS) {
            toast('🎤', 'Five minutes reached', 'Stopped so you can review it.', '#B45309');
            try { if (rec && rec.state === 'recording') rec.stop(); } catch (e) {}
          }
        }, 200);

        stopBtn.addEventListener('click', function () {
          stopBtn.disabled = true;
          stopBtn.style.opacity = '.5';
          try { if (rec && rec.state === 'recording') rec.stop(); } catch (e) {}
        });
      }

      // ── go ─────────────────────────────────────────────────────────────────
      navigator.mediaDevices.getUserMedia({
        audio: { echoCancellation: true, noiseSuppression: true, autoGainControl: true },
      }).then(function (s) {
        stream = s;
        mime = pickMime();
        anchor.parentNode.insertBefore(bar, anchor);
        anchor.style.display = 'none';
        document.addEventListener('keydown', onKey, true);
        begin();
      }).catch(function () {
        toast('🎤', 'Microphone blocked', 'Allow microphone access to record a voice note.', '#DC2626');
        finish(null);
      });
    });
  };
}(window, document));
