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
  /* ── ROWS PER PAGE, EVERY TABLE (2026-10-20) ─────────────────────────────────────
     Anthony: "based on all tables in the portal can you add how many rows to show - this
     needs to impact all tables full sweep".

     One control, used by every pager: the table pager (kt-table-filter), the long-table
     pager (kt-polish-v2) and the screens that page from the server (through the opts
     argument of KT.pagerBar). The choice is remembered per table in this browser, keyed
     by the screen and the table's column headings, so a screen's two tables keep their
     own. "All" (0) is offered where the rows are already in the page; a server-paged
     screen offers up to 100. */
  var RPP_OPTIONS = [10, 25, 50, 100];
  KT.rowsPerPage = {
    options: RPP_OPTIONS,
    /* A stable name for a table: screen + its first column headings. */
    keyFor: function (table, extra) {
      var screen = String(location.hash || '').replace(/^#/, '').split(/[\/?&]/)[0] || 'home';
      var named = table && table.getAttribute && table.getAttribute('data-kt-rows-key');
      if (named) return screen + ':' + named;
      var heads = [];
      try {
        var th = table.querySelectorAll('thead th');
        for (var i = 0; i < th.length && heads.length < 3; i++) {
          var t = (th[i].textContent || '').replace(/[^A-Za-z0-9 ]+/g, '').trim().toLowerCase();
          if (t) heads.push(t.slice(0, 16));
        }
      } catch (e) {}
      return screen + ':' + (heads.join('|') || 'table') + (extra ? ':' + extra : '');
    },
    /* The remembered size: a number, 0 for All, or null when never chosen. */
    get: function (key) {
      try {
        var v = localStorage.getItem('kt_rpp:' + key);
        if (v === null || v === '') return null;
        var n = parseInt(v, 10);
        return (n === 0 || RPP_OPTIONS.indexOf(n) !== -1) ? n : null;
      } catch (e) { return null; }
    },
    set: function (key, n) { try { localStorage.setItem('kt_rpp:' + key, String(n)); } catch (e) {} },
    /* <span class="kt-rpp">Rows per page [select]</span>. onChange(n), n = 0 for All. */
    control: function (current, onChange, allowAll, max) {
      var wrap = document.createElement('span');
      wrap.className = 'kt-rpp';
      wrap.style.cssText = 'display:inline-flex;align-items:center;gap:6px;font-size:12.5px;color:#64748B;font-weight:600;white-space:nowrap;';
      var lab = document.createElement('span'); lab.textContent = 'Rows per page';
      var sel = document.createElement('select');
      sel.setAttribute('aria-label', 'Rows per page');
      sel.setAttribute('data-kt-nofit', '1');
      sel.style.cssText = 'height:28px;padding:2px 6px;border:1px solid #E2E8F0;border-radius:7px;background:#fff;font-size:12.5px;color:#334155;cursor:pointer;width:auto;';
      var opts = RPP_OPTIONS.filter(function (n) { return !max || n <= max; }); if (allowAll) opts.push(0);
      if (opts.indexOf(current) === -1 && current > 0) { opts.push(current); opts.sort(function (a, b) { return (a || 1e9) - (b || 1e9); }); }
      opts.forEach(function (n) {
        var o = document.createElement('option'); o.value = String(n); o.textContent = n === 0 ? 'All' : String(n);
        if (n === current) o.selected = true;
        sel.appendChild(o);
      });
      sel.addEventListener('change', function () { onChange(parseInt(sel.value, 10) || 0); });
      wrap.appendChild(lab); wrap.appendChild(sel);
      return wrap;
    },
  };

  function pageWindow(cur, tot) {
    var keep = {};
    [1, tot, cur, cur - 1, cur + 1, cur - 2, cur + 2].forEach(function (p) { if (p >= 1 && p <= tot) keep[p] = 1; });
    var arr = Object.keys(keep).map(Number).sort(function (a, b) { return a - b; });
    var out = [], prev = 0;
    arr.forEach(function (p) { if (p - prev > 1) out.push('…'); out.push(p); prev = p; });
    return out;
  }
  /* opts (optional): { size, onSize(n), total, allowAll } adds the rows-per-page choice
     to the bar. Without opts the bar is exactly as before. */
  KT.pagerBar = function (bar, page, totalPages, onPage, opts) {
    if (!bar) return bar;
    totalPages = Math.max(1, parseInt(totalPages, 10) || 1);
    page = Math.min(totalPages, Math.max(1, parseInt(page, 10) || 1));
    if (!bar.classList.contains('kt-pager-bar')) bar.classList.add('kt-pager-bar');
    bar.innerHTML = '';
    var sizing = !!(opts && typeof opts.onSize === 'function');
    // The choice is worth showing once there are more rows than the smallest page.
    var showSize = sizing && (totalPages > 1 || (parseInt(opts.total, 10) || 0) > RPP_OPTIONS[0]);
    if (totalPages <= 1 && !showSize) { bar.style.display = 'none'; return bar; }
    bar.style.cssText = 'display:flex;justify-content:center;align-items:center;gap:6px;margin:12px 0 2px;flex-wrap:wrap;font-size:13px;';
    if (totalPages <= 1) {
      var only = KT.rowsPerPage.control(opts.size, opts.onSize, !!opts.allowAll, opts.max);
      bar.style.justifyContent = 'flex-end';
      bar.appendChild(only);
      return bar;
    }
    /* ON A PHONE, ONE NEAT LINE (2026-10-23, Anthony: "the pagination presentation in mobile is
       horrible looking - full sweep"). Prev, a row of numbers, Next and a Go-to box wrapped
       into three ragged lines. Here: ‹ · "Page 2 of 9" (tap it to pick any page) · ›. Every
       pager in the portal comes through this function, so all of them change together. */
    var phone = false;
    // matchMedia, not innerWidth: read just after the bar was re-styled, innerWidth forces a layout (PERF 2026-10-28).
    var narrow = function () { try { return w.matchMedia ? w.matchMedia('(max-width:768px)').matches : w.innerWidth <= 768; } catch (e) { return false; } };
    try { var C = w.Capacitor; phone = (C && (C.isNativePlatform ? C.isNativePlatform() : C.isNative)) || narrow(); } catch (e) { phone = narrow(); }
    if (phone) {
      bar.style.cssText = 'display:flex;justify-content:center;align-items:center;gap:8px;margin:12px 0 4px;flex-wrap:wrap;';
      bar.setAttribute('data-kt-noband', '1'); bar.setAttribute('data-kt-nobreathe', '1');
      var arrow = function (label, target, aria) {
        var b = document.createElement('button');
        b.type = 'button'; b.textContent = label; b.setAttribute('aria-label', aria); b.setAttribute('data-kt-inpage', '1'); b.setAttribute('data-kt-iconized', '1');
        b.style.cssText = 'width:40px;height:40px;min-height:0 !important;min-width:0 !important;padding:0 !important;margin:0 !important;border-radius:11px;border:1px solid #CBD5E1;background:#fff;color:#1F6080;font-size:22px;font-weight:800;line-height:1;cursor:pointer;flex:0 0 auto;';
        if (target == null) { b.disabled = true; b.style.opacity = '.35'; b.style.cursor = 'default'; }
        else b.addEventListener('click', function () { onPage(target); });
        return b;
      };
      var pick = document.createElement('label');
      pick.style.cssText = 'position:relative;display:inline-flex;align-items:center;justify-content:center;gap:5px;height:40px;padding:0 12px;border-radius:11px;background:#F1F5F9;font-size:14px;font-weight:800;color:#0F172A;cursor:pointer;flex:0 1 auto;min-width:0;white-space:nowrap;box-sizing:border-box;';
      pick.innerHTML = '<span>Page ' + page + ' of ' + totalPages + '</span><span style="font-size:11px;color:#64748B;">▾</span>';
      var sel = document.createElement('select');
      sel.setAttribute('aria-label', 'Page');
      sel.style.cssText = 'position:absolute;inset:0;width:100%;height:100%;opacity:0;cursor:pointer;border:0;padding:0;margin:0;';
      for (var i = 1; i <= totalPages; i++) { var o = document.createElement('option'); o.value = String(i); o.textContent = 'Page ' + i + ' of ' + totalPages; if (i === page) o.selected = true; sel.appendChild(o); }
      sel.addEventListener('change', function () { var v = parseInt(sel.value, 10); if (v && v !== page) onPage(v); });
      pick.appendChild(sel);
      // The three stay on one line, whatever the width.
      var line = document.createElement('div');
      line.style.cssText = 'display:flex;flex-wrap:nowrap;align-items:center;justify-content:center;gap:6px;max-width:100%;';
      line.appendChild(arrow('‹', page > 1 ? page - 1 : null, 'Previous page'));
      line.appendChild(pick);
      line.appendChild(arrow('›', page < totalPages ? page + 1 : null, 'Next page'));
      bar.appendChild(line);
      if (showSize) {
        var rcm = KT.rowsPerPage.control(opts.size, opts.onSize, !!opts.allowAll, opts.max);
        rcm.style.cssText += ';flex-basis:100%;justify-content:center;display:flex;font-size:12.5px;color:#64748B;';
        bar.appendChild(rcm);
      }
      return bar;
    }
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
    if (showSize) {
      var rc = KT.rowsPerPage.control(opts.size, opts.onSize, !!opts.allowAll, opts.max);
      rc.style.marginLeft = '14px';
      bar.appendChild(rc);
    }
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
