/*
 * kt-spellcheck.js — the browser's own spelling underline where people write words,
 * and never where it is wrong (2026-10-09).
 *
 * Anthony: "with entering data into the portal is there a spell check feature that can
 * just highlight or underline a spelling error". The native spellchecker already exists
 * in every browser and in the Android app's WebView (the keyboard does the work there);
 * nothing in the portal decided where it applies, so it was on everywhere by default —
 * names, emails, postal codes and search boxes included — and the phone keyboard was
 * given no hint that a notes box is prose.
 *
 * ON  (spellcheck, lang, autocapitalize=sentences, autocorrect=on):
 *     every <textarea> and [contenteditable], and single-line inputs whose name / id /
 *     placeholder / label say prose: note, comment, description, message, subject,
 *     title, reason, summary, observation, details, bio, tagline …
 * OFF (spellcheck=false, autocorrect=off): passwords, email, url, tel, number, dates and
 *     times, search and filter boxes, names (autocapitalize=words), addresses, postal
 *     codes, phone, licence / reference / account numbers, amounts, codes, PINs, tokens,
 *     HTML/CSS/JSON/code editors — and any single-line input that does not read as prose.
 *
 * data-kt-spellcheck="on|off" on a field or any ancestor decides outright.
 * Containers with data-kt-noautofill are left to kt-no-autofill.js.
 * Watches document.body (never #appMain, which is replaced every render) and batches.
 */
(function () {
  'use strict';
  if (window.__ktSpellcheck) return;
  window.__ktSpellcheck = true;

  var OFF_TYPES = /^(password|email|url|tel|number|date|time|datetime-local|month|week|search|color|range)$/;
  var OFF_AUTOCOMPLETE = /(name|email|tel|username|password|address|postal|street|country|locality|region|one-time-code|cc-|bday|organization|url|sex)/;
  var NAMEY = /(^|[^a-z])(first|last|middle|given|family|sur|preferred|nick|full|child|parent|guardian|contact|educator|provider|owner|signer|staff|user)?[ _-]?name([^a-z]|$)/;
  var OFF_WORDS = /(e-?mail|phone|tel\b|mobile|cell\b|fax|url|website|web site|link|domain|sub-?domain|slug|username|user name|login|password|passcode|\bpin\b|code\b|token|api key|secret|postal|zip|street|address|\bcity\b|province|country|licen[cs]e|\bnumber\b|\bno\.|#|amount|price|\bfee\b|rate\b|\$|qty|quantity|\bdate\b|\btime\b|\bdob\b|birth|\bsin\b|search|filter|find\b|lookup|look up|colou?r|\bhex\b|sku|reference|\bref\b|iban|account|transit|institution|smtp|\bhost\b|\bport\b|merge|\{\{|html|\bcss\b|json|\bcode\b|regex|\bip\b|latitude|longitude|\blat\b|\blng\b|percent|%|hours?\b|minutes?\b|age\b|capacity|ratio|count\b|signature|initials)/;
  var PROSE = /(note|comment|descri|message|\bbody\b|reason|narrat|detail|summary|observ|\bbio\b|tagline|subject|title|heading|caption|feedback|explain|\bwhy\b|what happened|story|goal|\bplan\b|activit|remark|instruction|question|answer|topic|headline|greeting|announce|\btext\b|paragraph|intro|closing|opening|wording|follow-?up|concern|action taken|outcome|response|reply|update|highlight|learning|incident|injur|treatment|first aid given|witness|tip|blurb|about)/;

  var STRONG_PROSE = /(note|comment|descri|message|reason|narrat|explain|summary|observ|remark|feedback|instruction|what happened|action taken|subject)/;

  function lang() {
    return (document.documentElement.getAttribute('lang') || 'en-CA');
  }

  function labelText(el) {
    var t = '';
    try {
      if (el.id) {
        var l = document.querySelector('label[for="' + (window.CSS && CSS.escape ? CSS.escape(el.id) : el.id) + '"]');
        if (l) t += ' ' + l.textContent;
      }
      var p = el.closest('label');
      if (p) t += ' ' + p.textContent;
      var prev = el.previousElementSibling;
      if (prev && prev.tagName !== 'INPUT' && prev.tagName !== 'TEXTAREA') t += ' ' + (prev.textContent || '').slice(0, 80);
      if (!prev && el.parentElement) {
        var pp = el.parentElement.previousElementSibling;
        if (pp && pp.tagName === 'LABEL') t += ' ' + pp.textContent;
      }
    } catch (e) {}
    return t;
  }

  function key(el) {
    return [el.getAttribute('name'), el.id, el.getAttribute('data-k'), el.getAttribute('data-key'),
      el.getAttribute('placeholder'), el.getAttribute('aria-label'), el.getAttribute('title'), labelText(el)]
      .filter(Boolean).join(' ').toLowerCase().replace(/[_]+/g, ' ');
  }

  /** 'on' | 'off' | 'name' | null (leave alone). */
  function decide(el) {
    var forced = el.closest('[data-kt-spellcheck]');
    if (forced) {
      var v = (forced.getAttribute('data-kt-spellcheck') || '').toLowerCase();
      if (v === 'on' || v === 'off') return v;
    }
    if (el.closest('[data-kt-noautofill]')) return null;
    var tag = el.tagName;
    if (tag === 'TEXTAREA' || el.isContentEditable) {
      if (el.closest('.CodeMirror, .cm-editor, .ace_editor, .monaco-editor, [data-kt-code]')) return 'off';
      var kt = key(el);
      if (/(html|\bcss\b|json|regex|\bcode\b|address|location|venue|signature|merge tag)/.test(kt)) return 'off';
      return 'on';
    }
    if (tag !== 'INPUT') return null;
    var type = (el.getAttribute('type') || 'text').toLowerCase();
    if (/^(hidden|checkbox|radio|file|submit|button|reset|image)$/.test(type)) return null;
    if (OFF_TYPES.test(type)) return 'off';
    if (type !== 'text' && type !== '') return 'off';
    var ac = (el.getAttribute('autocomplete') || '').toLowerCase();
    if (ac && ac !== 'off' && ac !== 'on' && OFF_AUTOCOMPLETE.test(ac)) return /name/.test(ac) ? 'name' : 'off';
    var k = key(el);
    if (NAMEY.test(k) && !/(event|form|template|file|document|plan|activity|room|group|zone|lesson|theme|title)\s*name/.test(k)) return 'name';
    // "Reason for time off", "Notes about the amount": a sentence about an identifier is still a sentence.
    if (STRONG_PROSE.test(k)) return 'on';
    if (OFF_WORDS.test(k)) return 'off';
    if (PROSE.test(k) || /(event|form|template|document|plan|activity|lesson|theme|room|group|zone) name/.test(k)) return 'on';
    return 'off';   // a single-line box that does not read as prose: an identifier until shown otherwise
  }

  function apply(el) {
    if (!el || el.nodeType !== 1) return;
    var d = decide(el);
    if (d === null) return;
    var was = el.getAttribute('data-kt-spell');
    if (was === d) return;
    el.setAttribute('data-kt-spell', d);
    if (d === 'on') {
      el.setAttribute('spellcheck', 'true');
      if (!el.closest('[lang]') || el.closest('[lang]') === document.documentElement) el.setAttribute('lang', lang());
      if (!el.hasAttribute('autocapitalize') || el.__ktCap) { el.setAttribute('autocapitalize', 'sentences'); el.__ktCap = true; }
      el.setAttribute('autocorrect', 'on');
    } else {
      el.setAttribute('spellcheck', 'false');
      el.setAttribute('autocorrect', 'off');
      if (d === 'name') {
        if (!el.hasAttribute('autocapitalize') || el.__ktCap) { el.setAttribute('autocapitalize', 'words'); el.__ktCap = true; }
      } else if (!el.hasAttribute('autocapitalize') || el.__ktCap) {
        el.__ktCap = true;
        var t = (el.getAttribute('type') || 'text').toLowerCase();
        el.setAttribute('autocapitalize', /^(email|url|password)$/.test(t) || /e-?mail|url|username|login|code|token|slug|domain/.test(key(el)) ? 'none' : 'off');
      }
    }
  }

  var SEL = 'textarea, input, [contenteditable=""], [contenteditable="true"], [contenteditable="plaintext-only"]';
  function scan(root) {
    try {
      if (root.matches && root.matches(SEL)) apply(root);
      if (root.querySelectorAll) {
        var list = root.querySelectorAll(SEL);
        for (var i = 0; i < list.length; i++) apply(list[i]);
      }
    } catch (e) {}
  }

  var pending = [], timer = null;
  function flush() {
    timer = null;
    var batch = pending; pending = [];
    for (var i = 0; i < batch.length; i++) if (batch[i].isConnected) scan(batch[i]);
  }
  function queue(n) {
    pending.push(n);
    // setTimeout, not requestAnimationFrame: rAF never fires on a hidden tab.
    if (!timer) timer = setTimeout(flush, 60);
  }

  function start() {
    scan(document.body);
    try {
      new MutationObserver(function (muts) {
        for (var i = 0; i < muts.length; i++) {
          var m = muts[i];
          if (m.type === 'childList') {
            for (var j = 0; j < m.addedNodes.length; j++) if (m.addedNodes[j].nodeType === 1) queue(m.addedNodes[j]);
          } else if (m.type === 'attributes' && m.target.nodeType === 1) {
            // a placeholder / name / type set after the field was inserted
            m.target.removeAttribute('data-kt-spell');
            queue(m.target);
          }
        }
      }).observe(document.body, { childList: true, subtree: true, attributes: true,
        attributeFilter: ['placeholder', 'name', 'type', 'data-kt-spellcheck', 'aria-label'] });
    } catch (e) {}
    // Backstop for anything built in a way the observer missed: decide on first focus.
    document.addEventListener('focusin', function (e) { if (e.target && e.target.matches && e.target.matches(SEL)) apply(e.target); }, true);
  }

  window.KT = window.KT || {};
  window.KT.spellcheck = { decide: decide, apply: apply, scan: scan };

  if (document.body) start();
  else document.addEventListener('DOMContentLoaded', start);
})();
