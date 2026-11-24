/* ═══════════════════════════════════════════════════════════════════
   KiddieTrac — "Select a provider" first (2026-10-24).
   Anthony: "the drop down for provider should be select a provider … full sweep of the portal
   to ensure this is set appropriately for similar drop downs where it doesnt auto select".
   Screens that work on ONE provider/centre used to open on the first one in the list. With
   several to choose from they now call KT.selectFirst(...) and show this prompt until a choice
   is made; with only one there is nothing to choose and the screen opens on it as before.

   KT.selectFirst(container, { title, sub, items:[{id,name}], word, onPick(id) })
   ═══════════════════════════════════════════════════════════════════ */
(function (window) {
  'use strict';
  var KT = window.KT = window.KT || {};
  function esc(s) { return String(s == null ? '' : s).replace(/[&<>"]/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]; }); }

  KT.selectFirst = function (container, o) {
    o = o || {};
    var word = o.word || ((KT.centreWord && KT.centreWord()) || 'Centre');
    var lower = String(word).toLowerCase();
    var items = o.items || [];
    container.innerHTML =
      '<div style="padding:16px 24px;max-width:1800px;">' +
        (o.title ? '<h2 style="font-size:22px;margin:0 0 4px;">' + esc(o.title) + '</h2>' : '') +
        (o.sub ? '<p style="color:#64748B;font-size:13.5px;margin:0 0 14px;">' + esc(o.sub) + '</p>' : '') +
        '<div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;margin:6px 0 14px;">' +
          '<label for="kt-sf-pick" style="font-size:13px;font-weight:700;color:#334155;">' + esc(word) + '</label>' +
          '<select id="kt-sf-pick" style="height:36px;padding:0 30px 0 10px;border:1px solid #CBD5E1;border-radius:8px;font-size:13.5px;background-color:#fff;min-width:220px;max-width:320px;">' +
            '<option value="" selected disabled>Select a ' + esc(lower) + '…</option>' +
            items.map(function (c) { return '<option value="' + esc(c.id) + '">' + esc(c.name || ('#' + c.id)) + '</option>'; }).join('') +
          '</select>' +
        '</div>' +
        '<div style="background:#F8FAFC;border:1px dashed #CBD5E1;border-radius:12px;padding:26px 16px;text-align:center;color:#64748B;font-size:13.5px;">' +
          'Select a ' + esc(lower) + ' to continue.' +
        '</div>' +
      '</div>';
    var sel = container.querySelector('#kt-sf-pick');
    if (sel) sel.addEventListener('change', function () {
      var v = sel.value; if (!v) return;
      var n = parseInt(v, 10);
      o.onPick && o.onPick(String(n) === v ? n : v);
    });
  };
})(window);

/* KT.centreBar(container, { items, current, word, onChange(id) }) — a compact switcher put at the
   top of a screen that had none (CACFP, wellness digest were fixed to the first centre). */
(function (window) {
  'use strict';
  var KT = window.KT = window.KT || {};
  KT.centreBar = function (container, o) {
    if (!container || !o || !(o.items || []).length || o.items.length < 2) return;
    var word = o.word || ((KT.centreWord && KT.centreWord()) || 'Centre');
    var bar = document.createElement('div');
    bar.style.cssText = 'display:flex;align-items:center;gap:10px;flex-wrap:wrap;padding:12px 24px 0;';
    var lab = document.createElement('label'); lab.textContent = word; lab.style.cssText = 'font-size:13px;font-weight:700;color:#334155;';
    var sel = document.createElement('select');
    sel.style.cssText = 'height:34px;padding:0 30px 0 10px;border:1px solid #CBD5E1;border-radius:8px;font-size:13.5px;background-color:#fff;min-width:200px;max-width:320px;';
    o.items.forEach(function (c) { var op = document.createElement('option'); op.value = c.id; op.textContent = c.name || ('#' + c.id); if (String(c.id) === String(o.current)) op.selected = true; sel.appendChild(op); });
    sel.addEventListener('change', function () { var n = parseInt(sel.value, 10); o.onChange && o.onChange(isNaN(n) ? sel.value : n); });
    bar.appendChild(lab); bar.appendChild(sel);
    container.insertBefore(bar, container.firstChild);
  };
})(window);
