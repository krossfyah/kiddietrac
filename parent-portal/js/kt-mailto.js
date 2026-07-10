/* ═══════════════════════════════════════════════════════════════════
   KIDDIETRAC — Global email linkifier (v22p83)
   Turns any plain-text email address rendered anywhere in the app content
   into a clickable mailto: link, without screens having to opt in.
   • Walks text nodes under #appMain (skips inputs, links, editable areas).
   • Re-runs on DOM changes (debounced) so dynamically-rendered lists are
     covered too. Conversion is idempotent and self-terminating: once an email
     lives inside an <a>, it is skipped on subsequent passes.
   • click → stopPropagation so linkified emails inside clickable rows/cards
     don't also trigger the row's navigation.
   ═══════════════════════════════════════════════════════════════════ */
(function (window) {
  'use strict';
  var document = window.document;
  var EMAIL_RE  = /[A-Za-z0-9._%+\-]+@[A-Za-z0-9.\-]+\.[A-Za-z]{2,}/;
  var EMAIL_RE_G = /[A-Za-z0-9._%+\-]+@[A-Za-z0-9.\-]+\.[A-Za-z]{2,}/g;
  var SKIP = { A: 1, BUTTON: 1, INPUT: 1, TEXTAREA: 1, SELECT: 1, OPTION: 1, SCRIPT: 1, STYLE: 1 };

  function linkify(root) {
    if (!root) return;
    var walker = document.createTreeWalker(root, NodeFilter.SHOW_TEXT, {
      acceptNode: function (node) {
        var v = node.nodeValue;
        if (!v || v.indexOf('@') < 0 || !EMAIL_RE.test(v)) return NodeFilter.FILTER_REJECT;
        var p = node.parentNode;
        while (p && p !== root) {
          if (SKIP[p.nodeName]) return NodeFilter.FILTER_REJECT;
          if (p.isContentEditable) return NodeFilter.FILTER_REJECT;
          p = p.parentNode;
        }
        return NodeFilter.FILTER_ACCEPT;
      }
    });
    var nodes = [], n;
    while ((n = walker.nextNode())) nodes.push(n);

    nodes.forEach(function (node) {
      var text = node.nodeValue;
      EMAIL_RE_G.lastIndex = 0;
      var frag = document.createDocumentFragment();
      var last = 0, m;
      while ((m = EMAIL_RE_G.exec(text))) {
        if (m.index > last) frag.appendChild(document.createTextNode(text.slice(last, m.index)));
        var a = document.createElement('a');
        a.href = 'mailto:' + m[0];
        a.textContent = m[0];
        a.className = 'kt-mail';
        a.style.color = '#1F6080';
        a.style.textDecoration = 'none';
        a.title = 'Email ' + m[0];
        a.addEventListener('click', function (e) { e.stopPropagation(); });
        frag.appendChild(a);
        last = m.index + m[0].length;
      }
      if (last < text.length) frag.appendChild(document.createTextNode(text.slice(last)));
      if (node.parentNode) node.parentNode.replaceChild(frag, node);
    });
  }

  var pending = false;
  function schedule() {
    if (pending) return;
    pending = true;
    setTimeout(function () {
      pending = false;
      try { linkify(document.querySelector('#appMain') || document.body); } catch (e) {}
    }, 200);
  }

  function start() {
    var root = document.querySelector('#appMain') || document.body;
    try { linkify(root); } catch (e) {}
    try {
      new MutationObserver(function (muts) {
        for (var i = 0; i < muts.length; i++) {
          if (muts[i].addedNodes && muts[i].addedNodes.length) { schedule(); break; }
        }
      }).observe(root, { childList: true, subtree: true });
    } catch (e) {}
  }

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start);
  else start();
})(window);
