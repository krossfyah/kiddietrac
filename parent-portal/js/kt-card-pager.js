/* ═══════════════════════════════════════════════════════════════════
   KiddieTrac — KT.cardPager: client-side pagination for CARD lists (not tables).
   Slice-and-render: only the current page's cards exist in the DOM, so it never
   fights the search/sort display-toggling that data-kt-list screens do.

       KT.cardPager(listEl, rows, renderOne, 10);

   renderOne(item, index) returns an HTMLElement OR an HTML string. Clears listEl,
   renders the current 10-item slice, and puts a numbered pager bar after it.
   ═══════════════════════════════════════════════════════════════════ */
(function (w) {
  'use strict';
  var KT = w.KT || (w.KT = {});
  if (KT.cardPager) return;

  KT.cardPager = function (listEl, rows, renderOne, pageSize) {
    if (!listEl || typeof renderOne !== 'function') return;
    pageSize = pageSize || 10;
    rows = rows || [];
    var page = 1;
    var totalPages = Math.max(1, Math.ceil(rows.length / pageSize));

    var bar = listEl.nextElementSibling;
    if (!bar || !bar.classList || !bar.classList.contains('kt-card-pager')) {
      bar = document.createElement('div');
      bar.className = 'kt-card-pager';
      bar.style.cssText = 'display:flex;justify-content:center;align-items:center;gap:6px;margin:14px 0 2px;flex-wrap:wrap;font-size:13px;';
      listEl.parentNode.insertBefore(bar, listEl.nextSibling);
    }

    function pageWindow(cur, tot) {
      var keep = {};
      [1, tot, cur, cur - 1, cur + 1, cur - 2, cur + 2].forEach(function (p) { if (p >= 1 && p <= tot) keep[p] = 1; });
      var arr = Object.keys(keep).map(Number).sort(function (a, b) { return a - b; });
      var out = [], prev = 0;
      arr.forEach(function (p) { if (p - prev > 1) out.push('…'); out.push(p); prev = p; });
      return out;
    }
    function btn(label, target, active) {
      var b = document.createElement('button');
      b.type = 'button'; b.textContent = label;
      var base = 'min-width:34px;padding:6px 10px;border-radius:7px;border:1px solid #E2E8F0;background:#fff;color:#334155;font-size:13px;font-weight:600;cursor:pointer;';
      b.style.cssText = active ? base + 'background:#1F6080;color:#fff;border-color:#1F6080;' : base;
      if (target == null) { b.disabled = true; b.style.opacity = '0.4'; b.style.cursor = 'default'; }
      else b.addEventListener('click', function () { page = target; draw(); });
      return b;
    }
    function draw() {
      if (page > totalPages) page = totalPages;
      if (page < 1) page = 1;
      while (listEl.firstChild) listEl.removeChild(listEl.firstChild);
      var start = (page - 1) * pageSize, end = start + pageSize;
      for (var i = start; i < end && i < rows.length; i++) {
        var node = renderOne(rows[i], i);
        if (typeof node === 'string') {
          var d = document.createElement('div');
          d.innerHTML = node;
          while (d.firstChild) listEl.appendChild(d.firstChild);
        } else if (node) {
          listEl.appendChild(node);
        }
      }
      bar.innerHTML = '';
      if (totalPages <= 1) { bar.style.display = 'none'; return; }
      bar.style.display = 'flex';
      bar.appendChild(btn('‹ Prev', page > 1 ? page - 1 : null));
      pageWindow(page, totalPages).forEach(function (p) {
        if (p === '…') {
          var s = document.createElement('span');
          s.textContent = '…'; s.style.cssText = 'padding:0 4px;color:#94A3B8;';
          bar.appendChild(s);
        } else {
          bar.appendChild(btn(String(p), p, p === page));
        }
      });
      bar.appendChild(btn('Next ›', page < totalPages ? page + 1 : null));
    }
    draw();
  };
})(window);
