/* ═══════════════════════════════════════════════════════════════════
   KiddieTrac — AFTER YOU SEND, KEEP TYPING (2026-10-23).

   Anthony: "when typing into the chat and I enter a message and send, it loses focus on
   the type a message bar where if I wanted to write more messages I have to click that
   area again - make this not a problem throughout the portal - full sweep".

   THE MECHANISM. Every message box loses focus on send for one of three reasons: the
   Send button takes focus when clicked; the box is disabled while the message goes (a
   disabled field drops focus and never takes it back); or the thread re-renders and the
   box is replaced by a new one. The composers are many - chat, staff threads, team chat,
   the parent app, tickets, the sales live chat, SMS, Ask AI - and each would need the same
   fix, so this is one rule for all of them:

     When a message is sent from a message box (Enter in it, or a Send button beside it),
     the box - or the new box that replaced it - gets the focus back as soon as it is
     usable again, within a few seconds, unless the person has meanwhile clicked into
     something else.

   A "message box" is a textarea or text input whose placeholder or label reads like one
   (Type a message…, Write a reply…, Reply…, Message…, Ask a question…), never a search box.
   ═══════════════════════════════════════════════════════════════════ */
(function () {
  'use strict';
  if (window.__ktComposeFocus) return;
  window.__ktComposeFocus = true;

  var MSG = /(^|\s)(type|write|reply|message|ask|say|send)\b|message…|message\.\.\.|reply…|reply\.\.\./i;
  var NOT = /search|filter|find|e\.g\.|@|subject|title|name/i;
  var SEND = /send|➤|➢|▶|📤|✈|↑|reply|post/i;

  function isComposer(el) {
    if (!el || !el.tagName) return false;
    var tag = el.tagName;
    if (tag !== 'TEXTAREA' && !(tag === 'INPUT' && /^(text|)$/.test((el.getAttribute('type') || '').toLowerCase()))) return false;
    if (el.hasAttribute('data-kt-nofocus-keep')) return false;
    var ph = (el.getAttribute('placeholder') || '') + ' ' + (el.getAttribute('aria-label') || '');
    if (el.hasAttribute('data-kt-composer')) return true;
    return MSG.test(ph) && !NOT.test(ph);
  }

  function visible(el) {
    if (!el || !el.isConnected) return false;
    var r = el.getBoundingClientRect();
    return r.width > 0 && r.height > 0 && getComputedStyle(el).visibility !== 'hidden';
  }

  /* Enough to find the box again if the thread re-rendered it. */
  function signature(el) {
    return { el: el, id: el.id || '', cls: (typeof el.className === 'string' ? el.className.trim().split(/\s+/)[0] : '') || '', ph: el.getAttribute('placeholder') || '', tag: el.tagName };
  }
  function find(sig) {
    if (sig.el && sig.el.isConnected && visible(sig.el)) return sig.el;
    var c = null;
    if (sig.id) c = document.getElementById(sig.id);
    if (!(c && visible(c))) {
      var sel = sig.tag.toLowerCase() + (sig.cls ? '.' + CSS.escape(sig.cls) : '');
      var all = [].slice.call(document.querySelectorAll(sel)).filter(function (x) { return (x.getAttribute('placeholder') || '') === sig.ph && visible(x); });
      c = all[all.length - 1] || null;
    }
    return c && visible(c) ? c : null;
  }

  var watch = null;   // { sig, until, timer, obs }
  function stop() { if (!watch) return; clearInterval(watch.timer); if (watch.obs) watch.obs.disconnect(); watch = null; }

  function attempt() {
    if (!watch) return;
    if (Date.now() > watch.until) { stop(); return; }
    var a = document.activeElement;
    // The person moved on to another field on purpose: leave them there.
    if (a && a !== document.body && a !== watch.sig.el && (a.tagName === 'INPUT' || a.tagName === 'TEXTAREA' || a.tagName === 'SELECT' || a.isContentEditable) && !isComposer(a)) { stop(); return; }
    var box = find(watch.sig);
    if (!box || box.disabled || box.readOnly) return;           // still sending; try again shortly
    if (document.activeElement === box) { if (Date.now() > watch.settle) stop(); return; }
    try { box.focus({ preventScroll: true }); } catch (e) { try { box.focus(); } catch (e2) {} }
  }

  function arm(el) {
    stop();
    watch = { sig: signature(el), until: Date.now() + 4000, settle: Date.now() + 1500 };
    watch.timer = setInterval(attempt, 120);
    if (window.MutationObserver) { watch.obs = new MutationObserver(attempt); watch.obs.observe(document.body, { childList: true, subtree: true, attributes: true, attributeFilter: ['disabled', 'readonly'] }); }
    setTimeout(attempt, 0);
  }

  // Enter (not Shift+Enter) in a message box.
  document.addEventListener('keydown', function (e) {
    if (e.key !== 'Enter' || e.shiftKey || e.isComposing) return;
    if (isComposer(e.target)) arm(e.target);
  }, true);

  // A Send button beside a message box.
  function composerNear(btn) {
    for (var p = btn.parentElement, i = 0; p && i < 4; p = p.parentElement, i++) {
      var boxes = [].filter.call(p.querySelectorAll('textarea, input'), isComposer);
      if (boxes.length === 1) return boxes[0];
      if (boxes.length > 1) return null;
    }
    return null;
  }
  document.addEventListener('click', function (e) {
    var btn = e.target && e.target.closest ? e.target.closest('button, [role=button], input[type=submit]') : null;
    if (!btn) return;
    var label = (btn.textContent || '') + ' ' + (btn.getAttribute('title') || '') + ' ' + (btn.getAttribute('aria-label') || '') + ' ' + (btn.value || '');
    if (!SEND.test(label) && !btn.hasAttribute('data-kt-send')) return;
    var box = composerNear(btn);
    if (box) arm(box);
  }, true);

  // A message box inside a <form> that submits.
  document.addEventListener('submit', function (e) {
    var box = e.target && [].filter.call(e.target.querySelectorAll('textarea, input'), isComposer)[0];
    if (box) arm(box);
  }, true);

  window.KT = window.KT || {};
  window.KT.keepComposerFocus = function (el) { if (el) arm(el); };
})();
