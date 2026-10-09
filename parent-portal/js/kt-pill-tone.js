/* ═══════════════════════════════════════════════════════════════════════
   kt-pill-tone.js — colour-coded type / status pills across the portal (2026-10-09)

   Anthony: "closures and holidays the pill that shows the type make these colour coded to
   each type so its easier to read" — then "do this across the platform for other tables
   with pills - full sweep".

   Many tables drew every type in the same blue "info" pill — closure type, status,
   trigger, category, age band — and some printed the raw value ("stat_holiday"). This one
   pass, run on every screen, does three things to a GENERIC pill:

     · a meaning it recognises gets that meaning's colour (approved = green, pending =
       amber, failed = red, …), and each closure type its own;
     · any other value gets a stable colour of its own, so the same word is always the
       same colour wherever it appears;
     · a raw enum value is shown as words ("stat_holiday" → "Stat holiday").

   It never touches a pill the screen already coloured on purpose (success / warning /
   danger classes, an inline background), a number, or anything marked data-kt-no-tone.
   KT.pillTone(text) and KT.pillLabel(text) are exported for screens to use directly.
   ═══════════════════════════════════════════════════════════════════════ */
(function (window, document) {
  'use strict';
  var KT = window.KT || (window.KT = {});

  var TONES = {
    green:  ['#ECFDF5', '#047857', '#A7F3D0'],
    amber:  ['#FFFBEB', '#B45309', '#FDE68A'],
    red:    ['#FEF2F2', '#B91C1C', '#FECACA'],
    blue:   ['#EFF6FF', '#1D4ED8', '#BFDBFE'],
    purple: ['#F5F3FF', '#6D28D9', '#DDD6FE'],
    indigo: ['#EEF2FF', '#4338CA', '#C7D2FE'],
    teal:   ['#F0FDFA', '#0F766E', '#99F6E4'],
    pink:   ['#FDF2F8', '#BE185D', '#FBCFE8'],
    orange: ['#FFF7ED', '#C2410C', '#FED7AA'],
    slate:  ['#F1F5F9', '#475569', '#E2E8F0'],
  };
  // Exact values first (closure types and other well-known enums).
  var EXACT = {
    stat_holiday: 'indigo', holiday: 'purple', pd_day: 'teal', emergency: 'red', staff_leave: 'amber',
    renovation: 'orange', weather: 'blue', other: 'slate',
    high: 'red', urgent: 'red', medium: 'amber', normal: 'blue', low: 'slate',
    required: 'indigo', optional: 'slate',
  };
  // Then words that carry a meaning wherever they appear.
  var WORDS = [
    [/\b(approved|active|paid|complete[d]?|confirmed|enrolled|applied|succeeded|success|connected|resolved|signed|done|live|open now|in use|compliant|engaged|linked|accepted|attended|present|verified|ok)\b/, 'green'],
    [/\b(pending|draft|waiting|awaiting|scheduled|due( soon)?|review|sending|queued|partial|paused|in progress|invited|unsigned|not replied|sent back)\b/, 'amber'],
    [/\b(failed|failure|overdue|cancel+ed|denied|declined|rejected|expired|blocked|error|emergency|urgent|suspended|withdrawn|refunded|bounced|absent|out of ratio|late)\b/, 'red'],
    [/\b(closed|inactive|archived|deactivated|not in use|unset|none|skipped)\b/, 'slate'],
    [/\b(notified|info|sent|new)\b/, 'blue'],
  ];
  var HASH_TONES = ['blue', 'purple', 'teal', 'pink', 'indigo', 'orange'];

  function norm(t) { return String(t || '').replace(/\s+/g, ' ').trim().toLowerCase(); }
  function toneFor(text) {
    var n = norm(text), k = n.replace(/[\s-]+/g, '_');
    if (!n) return null;
    if (EXACT[k]) return EXACT[k];
    for (var i = 0; i < WORDS.length; i++) { if (WORDS[i][0].test(n)) return WORDS[i][1]; }
    var h = 0; for (var j = 0; j < k.length; j++) { h = (h * 31 + k.charCodeAt(j)) >>> 0; }
    return HASH_TONES[h % HASH_TONES.length];
  }
  var SPECIAL = { pd: 'PD', cwelcc: 'CWELCC', id: 'ID', qr: 'QR', sms: 'SMS', hdlh: 'HDLH', cacfp: 'CACFP', ece: 'ECE', rece: 'RECE' };
  function labelFor(text) {
    var t = String(text || '').trim();
    if (/^[a-z]+$/.test(t)) return SPECIAL[t] || (t.charAt(0).toUpperCase() + t.slice(1));   // a raw one-word value
    if (!/^[a-z0-9]+(_[a-z0-9]+)+$/.test(t)) return t;          // only raw snake_case values
    var w = t.split('_').map(function (x) { return SPECIAL[x] || x; });
    w[0] = w[0].charAt(0).toUpperCase() + w[0].slice(1);
    return w.join(' ');
  }
  function paint(el, tone) {
    var c = TONES[tone]; if (!c) return;
    el.style.background = c[0]; el.style.color = c[1]; el.style.border = '1px solid ' + c[2];
    el.setAttribute('data-kt-toned', tone);
  }
  KT.pillTone = toneFor;
  KT.pillLabel = labelFor;
  KT.pillPaint = function (el, text) { var tn = toneFor(text == null ? el.textContent : text); if (tn) paint(el, tn); };

  var EXPLICIT = /\bkt-pill-(success|ok|warning|warn|danger|error)\b/;
  function eligible(el) {
    if (el.hasAttribute('data-kt-toned') || el.closest('[data-kt-no-tone]')) return false;
    if (el.children.length > 1) return false;                       // composite chips stay as built
    var txt = (el.textContent || '').trim();
    if (!txt || txt.length > 40 || /^[\d$€£.,:%\s-]+([a-z]{1,4})?$/i.test(txt) || /^\d/.test(txt)) return false;   // numbers, money, counts
    return true;
  }
  function sweep(root) {
    var scope = root && root.querySelectorAll ? root : document;
    // 1) the shared pill, when it is generic (info / purple / no tone) and not inline-coloured
    scope.querySelectorAll('.kt-pill').forEach(function (el) {
      if (EXPLICIT.test(el.className) || /background/i.test(el.getAttribute('style') || '')) return;
      if (!eligible(el)) return;
      var lbl = labelFor(el.textContent);
      if (lbl !== el.textContent.trim() && el.children.length === 0) el.textContent = lbl;
      var tn = toneFor(el.textContent); if (tn) paint(el, tn);
    });
    // 2) any hand-drawn pill that is showing a RAW value ("stat_holiday"): words + a colour
    scope.querySelectorAll('span[style*="999px"], span[style*="border-radius:20px"], span[style*="border-radius: 999px"]').forEach(function (el) {
      if (!eligible(el) || el.children.length) return;
      var raw = el.textContent.trim();
      if (!/^[a-z0-9]+(_[a-z0-9]+)+$/.test(raw)) return;
      el.textContent = labelFor(raw);
      var tn = toneFor(raw); if (tn) paint(el, tn);
    });
  }
  KT.sweepPills = sweep;

  var timer = null;
  function schedule() { if (timer) return; timer = setTimeout(function () { timer = null; try { sweep(document); } catch (e) {} }, 120); }   // not rAF: it never fires on a hidden tab
  function start() {
    schedule();
    try { new MutationObserver(schedule).observe(document.body, { childList: true, subtree: true }); } catch (e) {}
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start); else start();
})(window, document);
