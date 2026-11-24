/*
 * kt-spellcheck.js — the browser's own spelling underline where people write words,
 * and never where it is wrong (2026-10-09, widened 2026-11-02).
 *
 * Anthony: "with entering data into the portal is there a spell check feature that can
 * just highlight or underline a spelling error". The native spellchecker already exists
 * in every browser and in the Android / iPhone app's web view (the keyboard does the work
 * there); nothing in the portal decided where it applies, so it was on everywhere by
 * default — names, emails, postal codes and search boxes included — and the phone keyboard
 * was given no hint that a notes box is prose.
 *
 * 2026-11-02 (Anthony: "educators typing with many spelling mistakes - full sweep"): the
 * first version switched spellcheck OFF on every single-line box it could not prove was
 * prose. On a phone that is worse than doing nothing: spellcheck=false is what tells the
 * Android keyboard to drop its suggestion strip and autocorrect, and iOS to drop its
 * underline. Short free-text boxes ("e.g. Broken fence board", "Where to?", training
 * names) lost the help the browser gives by default. Now an unlabelled-as-identifier text
 * box is ON, and OFF has to be earned (type, autocomplete, inputmode, pattern, the shape
 * of the placeholder, or an identifier word in its name / label).
 *
 * ON  (spellcheck=true, autocapitalize=sentences, autocorrect=on):
 *     every <textarea> and [contenteditable], and single-line text inputs that are not
 *     identifiers.
 * OFF (spellcheck=false, autocorrect=off): passwords, email, url, tel, number, dates and
 *     times, search and filter boxes, names (autocapitalize=words), usernames, addresses,
 *     postal codes, phone, licence / reference / account numbers, amounts, codes, PINs,
 *     tokens, HTML/CSS/JSON/code editors, numeric / email / url keyboards (inputmode),
 *     fields with a pattern, and placeholders shaped like data ("0.00", "A1B 2C3", "@").
 *
 * Opt out: data-kt-nospell (or data-kt-spellcheck="off") on a field or any ancestor.
 * Force on: data-kt-spellcheck="on". A spellcheck attribute written in the markup by the
 * screen itself is respected. Containers with data-kt-noautofill are left to
 * kt-no-autofill.js.
 * The dictionary follows <html lang>, which this file keeps in step with kt_locale
 * (fr / es / hi); fields inherit it, nothing pins a language on a field.
 * Watches document.body (never #appMain, which is replaced every render) and batches,
 * so screens that load later (kt-screen-loader.js) and popups in #modalRoot are covered.
 */
(function () {
  'use strict';
  if (window.__ktSpellcheck) return;
  window.__ktSpellcheck = true;

  var OFF_TYPES = /^(password|email|url|tel|number|date|time|datetime-local|month|week|search|color|range)$/;
  var OFF_AUTOCOMPLETE = /(name|email|tel|username|password|address|postal|street|country|locality|region|one-time-code|cc-|bday|organization|url|sex)/;
  var OFF_INPUTMODE = /^(numeric|decimal|tel|email|url|search|none)$/;
  var USERNAME = /(user ?name|login|log-in|sign-?in id|handle)/;
  var NAMEY = /(^|[^a-z])(first|last|middle|given|family|sur|preferred|nick|full|child|parent|guardian|contact|educator|provider|owner|signer|staff)?[ _-]?names?([^a-z]|$)/;
  var OFF_WORDS = /(e-?mail|phone|tel\b|mobile|cell\b|fax|url|website|web site|link|domain|sub-?domain|slug|username|user name|login|password|passcode|\bpin\b|code\b|token|api key|secret|postal|zip|street|address|\bcity\b|province|country|licen[cs]e|\bnumber\b|\bno\.|#|amount|price|\bfee\b|rate\b|\$|qty|quantity|\bdate\b|\btime\b|\bdob\b|birth|\bsin\b|search|filter|find\b|lookup|look up|colou?r|\bhex\b|sku|reference|\bref\b|iban|account|transit|institution|smtp|\bhost\b|\bport\b|merge|\{\{|html|\bcss\b|json|\bcode\b|regex|\bip\b|latitude|longitude|\blat\b|\blng\b|percent|%|hours?\b|minutes?\b|age\b|capacity|ratio|count\b|signature|initials|go to page|\bid\b|client id|tenant|public key|webhook|base64|reach you)/;
  var STRONG_PROSE = /(note|comment|descri|message|reason|narrat|explain|summary|observ|remark|feedback|instruction|what happened|action taken|subject)/;
  var PROSE_NAME = /(event|form|template|file|document|plan|activity|room|group|zone|lesson|theme|title|award|course|training|campaign|walk|trip|outing)\s*name/;

  /** The locale the person chose (kt-i18n keeps it in kt_locale), as <html lang>. */
  function syncDocLang() {
    try {
      var l = localStorage.getItem('kt_locale');
      if (!l || l === 'en') return;
      var cur = (document.documentElement.getAttribute('lang') || '').toLowerCase();
      if (cur.split('-')[0] !== l.toLowerCase().split('-')[0]) document.documentElement.setAttribute('lang', l);
    } catch (e) {}
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
      // The label in front of the field's wrapper — but not when the field already sits in
      // its own <label>: then the label before it belongs to the NEIGHBOURING field
      // ("Training" was read as "Date" and lost its speller).
      if (!prev && !p && el.parentElement) {
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

  /** A placeholder that shows the SHAPE of data rather than words: "0.00", "A1B 2C3",
      "(416) 555-0199", "HH:MM", "you@example.com", "https://…". */
  function dataShaped(ph) {
    if (!ph) return false;
    ph = ph.replace(/^\s*(e\.g\.|eg|ex\.?|example:?)\s*/i, '');
    if (/@|:\/\/|^www\.|\.(com|ca|org|net)\b/i.test(ph)) return true;
    if (!/[A-Za-z\u00C0-\u024F\u0900-\u097F]{3,}/.test(ph)) return true;   // no real word in it
    if (/\d/.test(ph) && /^[A-Z0-9\s\-\/.:()#]+$/.test(ph)) return true;      // "A1B 2C3", "KT-PLN-0001"
    return false;
  }

  /** 'on' | 'off' | 'name' | 'user' | null (leave alone). */
  function decide(el) {
    if (el.closest('[data-kt-nospell]')) return 'off';
    var forced = el.closest('[data-kt-spellcheck]');
    if (forced) {
      var v = (forced.getAttribute('data-kt-spellcheck') || '').toLowerCase();
      if (v === 'on' || v === 'off') return v;
    }
    if (el.closest('[data-kt-noautofill]')) return null;
    // The screen wrote spellcheck="…" itself (login, HTML template editor, client ids): its call.
    if (el.hasAttribute('spellcheck') && !el.__ktSpellOwn) return null;
    var tag = el.tagName;
    if (tag === 'TEXTAREA' || el.isContentEditable) {
      if (el.closest('.CodeMirror, .cm-editor, .ace_editor, .monaco-editor, [data-kt-code]')) return 'off';
      var kt = key(el);
      if (/(html|\bcss\b|json|regex|\bcode\b|address|signature|merge tag|api key|private key|\bpem\b|\bcsv\b)/.test(kt)) return 'off';
      return 'on';
    }
    if (tag !== 'INPUT') return null;
    var type = (el.getAttribute('type') || 'text').toLowerCase();
    if (/^(hidden|checkbox|radio|file|submit|button|reset|image)$/.test(type)) return null;
    if (OFF_TYPES.test(type)) return 'off';
    if (type !== 'text' && type !== '') return 'off';
    if (el.readOnly && !el.closest('[data-kt-noautofill]')) return null;
    var ac = (el.getAttribute('autocomplete') || '').toLowerCase();
    if (ac && ac !== 'off' && ac !== 'on' && OFF_AUTOCOMPLETE.test(ac)) {
      if (/username/.test(ac)) return 'user';
      return /name/.test(ac) ? 'name' : 'off';
    }
    if (OFF_INPUTMODE.test((el.getAttribute('inputmode') || '').toLowerCase())) return 'off';
    if (el.hasAttribute('pattern')) return 'off';
    var max = parseInt(el.getAttribute('maxlength') || '0', 10);
    if (max > 0 && max <= 8) return 'off';                      // codes, initials, PINs
    var k = key(el);
    if (!k.replace(/\s+/g, '')) return null;                     // nothing to go on: the browser default
    if (/(search|filter|look ?up|find\b)/.test((el.getAttribute('placeholder') || '').toLowerCase())) return 'off';
    if (USERNAME.test(k)) return 'user';
    if (NAMEY.test(k) && !PROSE_NAME.test(k)) return 'name';
    // "Reason for time off", "Notes about the amount": a sentence about an identifier is still a sentence.
    if (STRONG_PROSE.test(k)) return 'on';
    if (OFF_WORDS.test(k)) return 'off';
    if (dataShaped(el.getAttribute('placeholder'))) return 'off';
    return 'on';    // a short line of words ("e.g. Broken fence board", "Where to?"): help the speller
  }

  function apply(el) {
    if (!el || el.nodeType !== 1) return;
    var d = decide(el);
    if (d === null) return;
    var was = el.getAttribute('data-kt-spell');
    if (was === d) return;
    el.setAttribute('data-kt-spell', d);
    el.__ktSpellOwn = true;
    var canCap = !el.hasAttribute('autocapitalize') || el.__ktCap;
    if (d === 'on') {
      el.setAttribute('spellcheck', 'true');
      if (canCap) { el.setAttribute('autocapitalize', 'sentences'); el.__ktCap = true; }
      el.setAttribute('autocorrect', 'on');
    } else {
      el.setAttribute('spellcheck', 'false');
      el.setAttribute('autocorrect', 'off');
      if (!canCap) return;
      el.__ktCap = true;
      if (d === 'name') { el.setAttribute('autocapitalize', 'words'); return; }
      var t = (el.getAttribute('type') || 'text').toLowerCase();
      el.setAttribute('autocapitalize', d === 'user' || /^(email|url|password)$/.test(t) || /e-?mail|url|username|login|code|token|slug|domain/.test(key(el)) ? 'none' : 'off');
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
    syncDocLang();
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
        attributeFilter: ['placeholder', 'name', 'type', 'data-kt-spellcheck', 'data-kt-nospell', 'aria-label', 'inputmode', 'contenteditable'] });
    } catch (e) {}
    // Backstop for anything built in a way the observer missed: decide on first focus,
    // before the keyboard opens (the phone keyboard reads the flags when it attaches).
    document.addEventListener('focusin', function (e) { if (e.target && e.target.matches && e.target.matches(SEL)) apply(e.target); }, true);
  }

  window.KT = window.KT || {};
  window.KT.spellcheck = { decide: decide, apply: apply, scan: scan };

  if (document.body) start();
  else document.addEventListener('DOMContentLoaded', start);
})();
