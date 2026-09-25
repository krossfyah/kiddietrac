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

  /* ONE PAGER BAR FOR THE WHOLE PORTAL (2026-09-25).

     Anthony: "pagination on email log, email errors etc is not there and it just
     shows to load more - not what we have implemented throughout - full sweep".

     The numbered bar (‹ Prev · 1 2 … 9 · Next › · Go to) lived inside
     kt-table-filter.js and was copied once into cardPager below. Every screen that
     pages from the SERVER could not reach it, so each grew its own: "Load 100 more",
     "Show 10 more", "‹ Newer / Older ›", a bare "Page 2 of 7" with two buttons, a
     « ‹ [n] of N › » strip. Eleven of them, no two alike.

     This is the bar itself, with no opinion about where the rows come from:

         KT.pagerBar(barEl, page, totalPages, function (p) { ...fetch / slice p... });

     It renders nothing (and hides barEl) when there is one page. Pages are 1-based.
     Server-paged screens call it after every load; the client-side pagers call it
     after every slice. Change the look here and every pager in the portal moves. */
  function pageWindow(cur, tot) {
    var keep = {};
    [1, tot, cur, cur - 1, cur + 1, cur - 2, cur + 2].forEach(function (p) { if (p >= 1 && p <= tot) keep[p] = 1; });
    var arr = Object.keys(keep).map(Number).sort(function (a, b) { return a - b; });
    var out = [], prev = 0;
    arr.forEach(function (p) { if (p - prev > 1) out.push('…'); out.push(p); prev = p; });
    return out;
  }
  KT.pagerBar = function (bar, page, totalPages, onPage) {
    if (!bar) return bar;
    totalPages = Math.max(1, parseInt(totalPages, 10) || 1);
    page = Math.min(totalPages, Math.max(1, parseInt(page, 10) || 1));
    if (!bar.classList.contains('kt-pager-bar')) bar.classList.add('kt-pager-bar');
    bar.innerHTML = '';
    if (totalPages <= 1) { bar.style.display = 'none'; return bar; }
    bar.style.cssText = 'display:flex;justify-content:center;align-items:center;gap:6px;margin:12px 0 2px;flex-wrap:wrap;font-size:13px;';
    function btn(label, target, active) {
      var b = document.createElement('button');
      b.type = 'button'; b.textContent = label;
      var base = 'min-width:34px;padding:6px 10px;border-radius:7px;border:1px solid #E2E8F0;background:#fff;color:#334155;font-size:13px;font-weight:600;cursor:pointer;transition:all .12s;';
      b.style.cssText = active ? base + 'background:#1F6080;color:#fff;border-color:#1F6080;' : base;
      b.setAttribute('data-kt-inpage', '1');   // never the shell's "back" (app-v2-shell isBackControl)
      if (active) b.setAttribute('aria-current', 'page');
      if (target == null) { b.disabled = true; b.style.opacity = '0.4'; b.style.cursor = 'default'; }
      else if (!active) b.addEventListener('click', function () { onPage(target); });
      return b;
    }
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
    var jl = document.createElement('span');
    jl.textContent = 'Go to'; jl.style.cssText = 'margin-left:10px;color:#64748B;font-weight:600;';
    var jump = document.createElement('input');
    jump.type = 'number'; jump.min = '1'; jump.max = String(totalPages); jump.value = String(page);
    jump.setAttribute('aria-label', 'Go to page');
    jump.style.cssText = 'width:60px;padding:5px 8px;border:1px solid #E2E8F0;border-radius:7px;font-size:13px;text-align:center;';
    var go = function () { var v = parseInt(jump.value, 10); if (v >= 1 && v <= totalPages && v !== page) onPage(v); };
    jump.addEventListener('keydown', function (e) { if (e.key === 'Enter') { e.preventDefault(); go(); } });
    jump.addEventListener('change', go);
    bar.appendChild(jl); bar.appendChild(jump);
    return bar;
  };

  /* A bar element placed directly after `anchor`, reused on the next call. */
  KT.pagerBarAfter = function (anchor) {
    if (!anchor || !anchor.parentNode) return null;
    var bar = anchor.nextElementSibling;
    if (!bar || !bar.classList || !bar.classList.contains('kt-pager-bar')) {
      bar = document.createElement('div');
      bar.className = 'kt-pager-bar';
      anchor.parentNode.insertBefore(bar, anchor.nextSibling);
    }
    return bar;
  };

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
      bar.className = 'kt-card-pager kt-pager-bar';
      bar.style.cssText = 'display:flex;justify-content:center;align-items:center;gap:6px;margin:14px 0 2px;flex-wrap:wrap;font-size:13px;';
      listEl.parentNode.insertBefore(bar, listEl.nextSibling);
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
      KT.pagerBar(bar, page, totalPages, function (p) { page = p; draw(); });
    }
    draw();
  };
})(window);
