/* ═══════════════════════════════════════════════════════════════════
   KiddieTrac — the voice note recorder (2026-09-10).

   ONE recorder for every composer that can send audio. Before this there were
   three separate MediaRecorder blocks — the Messenger family thread, the
   colleague thread, and the parent Messages screen — and they behaved like three
   different products:

     · the family thread turned the 🎤 red and, the moment you pressed stop,
       SENT the recording. No review, no undo, no way to discard a false start.
       You found out what you had said by listening to it in the thread, along
       with everybody else.
     · the colleague thread staged the file with no way to hear it first.
     · only the parent screen let you play it back before sending.

   None of the three showed that the microphone was actually picking anything up,
   which is the one thing a person wants to know while they are talking into a
   phone. A recorder that shows nothing is indistinguishable from a broken one,
   and the way you discover the difference is by sending silence to a parent.

   So: a real recorder. A live level meter driven by the audio itself, a running
   timer, and STOP THEN LISTEN — nothing leaves until the person has heard it and
   chosen to send. Re-record throws the take away and starts again.

   API — one call, resolves to a File or to null if they backed out:

       var file = await KT.recordVoiceNote();          // 'Send'
       var file = await KT.recordVoiceNote({ acceptLabel: 'Attach' });
       if (file) { ...hand it to the composer... }

   The caller keeps whatever it did with the file before. This module owns the
   microphone, the UI and the discipline; it does not know what a conversation is.
   ═══════════════════════════════════════════════════════════════════ */
(function (window, document) {
  'use strict';

  var KT = window.KT || (window.KT = {});
  if (KT.recordVoiceNote) return;

  var MAX_MS = 5 * 60 * 1000;   // a voice note, not a podcast — auto-stops here
  var MIN_BYTES = 800;          // below this the take is silence/noise, not speech
  var BARS = 24;

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
    var acceptLabel = opts.acceptLabel || 'Send';

    return new Promise(function (resolve) {
      if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia || !window.MediaRecorder) {
        toast('⚠️', 'Not supported', 'This device cannot record audio.', '#DC2626');
        return resolve(null);
      }

      var stream = null, rec = null, chunks = [], mime = '';
      var audioCtx = null, analyser = null, rafId = null, tickId = null;
      var startedAt = 0, blob = null, blobUrl = null, done = false;

      /* EVERY exit goes through here. A live track leaves the browser's recording
         indicator burning after the dialog is gone, which reads as "it is still
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
        try { if (blobUrl) URL.revokeObjectURL(blobUrl); } catch (e) {}
        try { if (ov && ov.parentNode) ov.parentNode.removeChild(ov); } catch (e) {}
        document.removeEventListener('keydown', onKey, true);
        resolve(file || null);
      }

      function onKey(e) {
        if (e.key === 'Escape') { e.preventDefault(); e.stopPropagation(); finish(null); }
      }

      // ── the panel ──────────────────────────────────────────────────────────
      /* role="dialog" + .kt-modal on purpose: that is the shape KT.uiBusy() looks
         for, so the background refreshers hold off instead of re-rendering the
         screen out from under an open recorder. */
      var ov = document.createElement('div');
      ov.className = 'kt-modal kt-voice-recorder';
      ov.setAttribute('role', 'dialog');
      ov.setAttribute('aria-modal', 'true');
      ov.setAttribute('aria-label', 'Voice note recorder');
      ov.style.cssText = 'position:fixed;inset:0;z-index:2147483000;background:rgba(13,27,42,.55);'
        + 'display:flex;align-items:center;justify-content:center;padding:16px;';

      var panel = document.createElement('div');
      panel.style.cssText = 'width:100%;max-width:420px;background:#fff;border-radius:16px;'
        + 'box-shadow:0 18px 48px rgba(0,0,0,.28);padding:18px 18px 14px;font-family:inherit;';
      ov.appendChild(panel);

      panel.innerHTML =
          '<div style="display:flex;align-items:center;gap:8px;margin-bottom:14px;">'
        +   '<span style="font-size:18px;">🎤</span>'
        +   '<strong style="font-size:15px;color:#0D1B2A;">Voice note</strong>'
        +   '<span data-el="state" style="margin-left:auto;font-size:12px;font-weight:700;color:#DC2626;">● Recording</span>'
        + '</div>'
        + '<div data-el="meter" style="display:flex;align-items:center;justify-content:center;gap:3px;'
        +   'height:56px;padding:0 4px;margin-bottom:8px;"></div>'
        + '<div data-el="time" style="text-align:center;font-size:22px;font-weight:800;color:#0D1B2A;'
        +   'font-variant-numeric:tabular-nums;margin-bottom:4px;">0:00</div>'
        + '<div data-el="hint" style="text-align:center;font-size:12px;color:#64748B;margin-bottom:14px;">'
        +   'Speak, then press Stop to listen back.</div>'
        + '<div data-el="playback" style="display:none;margin-bottom:14px;"></div>'
        + '<div data-el="actions" style="display:flex;gap:8px;"></div>';

      var elState = panel.querySelector('[data-el="state"]');
      var elMeter = panel.querySelector('[data-el="meter"]');
      var elTime = panel.querySelector('[data-el="time"]');
      var elHint = panel.querySelector('[data-el="hint"]');
      var elPlay = panel.querySelector('[data-el="playback"]');
      var elActions = panel.querySelector('[data-el="actions"]');

      var bars = [];
      for (var b = 0; b < BARS; b++) {
        var bar = document.createElement('span');
        bar.style.cssText = 'display:block;width:4px;height:4px;border-radius:2px;background:#CBD5E1;'
          + 'transition:height .06s linear,background-color .12s linear;';
        elMeter.appendChild(bar);
        bars.push(bar);
      }

      function button(label, kind) {
        var el = document.createElement('button');
        el.type = 'button';
        var base = 'flex:1;height:40px;border-radius:10px;font-size:13.5px;font-weight:700;cursor:pointer;'
          + 'display:inline-flex;align-items:center;justify-content:center;gap:6px;';
        el.style.cssText = base + (kind === 'primary'
          ? 'background:#159FB4;color:#fff;border:1px solid #159FB4;'
          : (kind === 'danger'
            ? 'background:#fff;color:#DC2626;border:1px solid #FECACA;'
            : 'background:#fff;color:#334155;border:1px solid #E5E7EB;'));
        el.textContent = label;
        return el;
      }

      // ── level meter ────────────────────────────────────────────────────────
      /* Driven by the microphone itself, not by an animation pretending to listen.
         A meter that moves whether or not sound is arriving would answer the only
         question it exists to answer — "is this picking me up?" — with a lie. */
      function startMeter() {
        try {
          var Ctx = window.AudioContext || window.webkitAudioContext;
          if (!Ctx) return;
          audioCtx = new Ctx();
          var src = audioCtx.createMediaStreamSource(stream);
          analyser = audioCtx.createAnalyser();
          analyser.fftSize = 512;
          analyser.smoothingTimeConstant = 0.75;
          src.connect(analyser);          // analyser only — never to destination, or it echoes
          var data = new Uint8Array(analyser.frequencyBinCount);
          var peak = 0;

          var draw = function () {
            if (!analyser) return;
            analyser.getByteTimeDomainData(data);
            var sum = 0;
            for (var i = 0; i < data.length; i++) { var v = (data[i] - 128) / 128; sum += v * v; }
            var rms = Math.sqrt(sum / data.length);
            var level = Math.min(1, rms * 3.2);
            peak = Math.max(level, peak * 0.92);

            for (var j = 0; j < bars.length; j++) {
              /* Centre-weighted so it reads as a voice, loudest in the middle,
                 rather than a flat wall of equal bars. */
              var d = Math.abs(j - (bars.length - 1) / 2) / ((bars.length - 1) / 2);
              var h = 4 + Math.round(level * 48 * (1 - d * 0.72) * (0.75 + Math.random() * 0.5));
              bars[j].style.height = Math.max(4, Math.min(52, h)) + 'px';
              bars[j].style.backgroundColor = level > 0.02 ? '#159FB4' : '#CBD5E1';
            }
            /* Nothing at all is arriving: say so, because a muted headset or a
               microphone another app has grabbed looks exactly like a working one. */
            elHint.textContent = peak < 0.015
              ? 'No sound is reaching the microphone yet — try speaking up.'
              : 'Speak, then press Stop to listen back.';
            elHint.style.color = peak < 0.015 ? '#B45309' : '#64748B';
            rafId = requestAnimationFrame(draw);
          };
          rafId = requestAnimationFrame(draw);
        } catch (e) { /* no meter is survivable; recording is not */ }
      }

      function restMeter() {
        if (rafId) { cancelAnimationFrame(rafId); rafId = null; }
        for (var j = 0; j < bars.length; j++) {
          bars[j].style.height = '4px';
          bars[j].style.backgroundColor = '#CBD5E1';
        }
      }

      // ── review step ────────────────────────────────────────────────────────
      function showReview() {
        restMeter();
        elState.textContent = '✓ Ready to send';
        elState.style.color = '#0F766E';
        elHint.textContent = 'Listen to it before you send. Re-record starts again.';
        elHint.style.color = '#64748B';

        blobUrl = URL.createObjectURL(blob);
        elPlay.innerHTML = '';
        var au = document.createElement('audio');
        au.controls = true;
        au.src = blobUrl;
        au.style.cssText = 'width:100%;height:40px;display:block;';
        elPlay.appendChild(au);
        elPlay.style.display = 'block';

        elActions.innerHTML = '';
        var again = button('Re-record');
        var cancel = button('Cancel', 'danger');
        var accept = button(acceptLabel, 'primary');
        elActions.appendChild(cancel);
        elActions.appendChild(again);
        elActions.appendChild(accept);

        cancel.addEventListener('click', function () { finish(null); });
        again.addEventListener('click', function () {
          try { au.pause(); } catch (e) {}
          try { if (blobUrl) URL.revokeObjectURL(blobUrl); } catch (e) {}
          blobUrl = null; blob = null;
          elPlay.style.display = 'none';
          elPlay.innerHTML = '';
          begin();
        });
        accept.addEventListener('click', function () {
          var type = blob.type || mime || 'audio/webm';
          finish(new File([blob], 'voice-' + Date.now() + '.' + extFor(type), { type: type }));
        });
      }

      // ── recording step ─────────────────────────────────────────────────────
      function begin() {
        chunks = [];
        elState.textContent = '● Recording';
        elState.style.color = '#DC2626';
        elTime.textContent = '0:00';

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
          var type = (chunks[0] && chunks[0].type) || mime || 'audio/webm';
          blob = new Blob(chunks, { type: type });
          if (blob.size < MIN_BYTES) {
            /* Too short to be anything. Sending it would put an empty bubble in
               front of a parent, so say what happened and stay open to try again. */
            toast('🎤', 'Nothing recorded', 'That was too short — hold on a moment longer.', '#B45309');
            blob = null;
            begin();
            return;
          }
          showReview();
        };

        rec.start();
        startedAt = Date.now();
        startMeter();

        tickId = setInterval(function () {
          var ms = Date.now() - startedAt;
          elTime.textContent = mmss(ms);
          if (ms >= MAX_MS) {
            toast('🎤', 'Five minutes reached', 'The recording was stopped so you can review it.', '#B45309');
            try { if (rec && rec.state === 'recording') rec.stop(); } catch (e) {}
          }
        }, 200);

        elActions.innerHTML = '';
        var cancel = button('Cancel', 'danger');
        var stop = button('Stop', 'primary');
        elActions.appendChild(cancel);
        elActions.appendChild(stop);
        cancel.addEventListener('click', function () { finish(null); });
        stop.addEventListener('click', function () {
          stop.disabled = true;
          stop.style.opacity = '.6';
          try { if (rec && rec.state === 'recording') rec.stop(); } catch (e) {}
        });
      }

      // ── go ─────────────────────────────────────────────────────────────────
      navigator.mediaDevices.getUserMedia({
        audio: { echoCancellation: true, noiseSuppression: true, autoGainControl: true },
      }).then(function (s) {
        stream = s;
        mime = pickMime();
        document.body.appendChild(ov);
        document.addEventListener('keydown', onKey, true);
        begin();
      }).catch(function () {
        toast('🎤', 'Microphone blocked', 'Allow microphone access to record a voice note.', '#DC2626');
        finish(null);
      });
    });
  };
}(window, document));
