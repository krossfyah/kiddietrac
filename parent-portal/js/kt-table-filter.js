/* v22p60 — universal table filter / search + toast notifications.
   Auto-attaches a search input above every <table> on data-kt-pretty
   screens that don't already have one. No per-screen wiring needed.
*/
(function (window) {
  'use strict';
  if (window.KT_TABLE_FILTER_LOADED) return;
  window.KT_TABLE_FILTER_LOADED = true;

  // ============================ Toast notifications ============================
  function ensureToastHost() {
    let host = document.getElementById('kt-toast-host');
    if (host) return host;
    host = document.createElement('div');
    host.id = 'kt-toast-host';
    host.style.cssText = 'position:fixed;top:18px;right:18px;z-index:999999;display:flex;flex-direction:column;gap:10px;pointer-events:none;';
    document.body.appendChild(host);
    return host;
  }
  function showToast(msg, kind = 'info', ttl = 4000) {
    // Unified toast: hand off to the canonical KT.toast (kt-toasts.js) so these
    // match every other toast. Guard against delegating to ourselves — we assign
    // window.KT.toast = showToast below, before kt-toasts.js overrides it, so a
    // bare delegation could recurse if kt-toasts.js hadn't loaded.
    if (window.KT && typeof window.KT.toast === 'function' && window.KT.toast !== showToast) {
      var _m = ({ info: ['ℹ️', '#1F6080'], success: ['✅', '#16A34A'], warning: ['⚠️', '#D97706'], error: ['⚠️', '#DC2626'] })[kind] || ['ℹ️', '#1F6080'];
      return window.KT.toast(_m[0], msg, '', _m[1]);
    }
    const host = ensureToastHost();
    const t = document.createElement('div');
    const colors = {
      info:    { bg: '#1F6080', icon: 'ℹ' },
      success: { bg: '#10B981', icon: '✓' },
      warning: { bg: '#F59E0B', icon: '⚠' },
      error:   { bg: '#EF4444', icon: '✗' },
    };
    const c = colors[kind] || colors.info;
    t.style.cssText = `pointer-events:auto;background:${c.bg};color:#fff;padding:12px 18px 12px 14px;border-radius:10px;box-shadow:0 8px 24px rgba(15,23,42,.18);font-size:14px;font-weight:600;max-width:380px;display:flex;align-items:flex-start;gap:10px;cursor:pointer;animation:kt-toast-in .2s ease-out;`;
    t.innerHTML = `<span style="font-size:18px;line-height:1;">${c.icon}</span><span>${String(msg).replace(/[&<>]/g, x => ({'&':'&amp;','<':'&lt;','>':'&gt;'}[x]))}</span>`;
    host.appendChild(t);
    setTimeout(() => { t.style.opacity = '0'; t.style.transform = 'translateX(20px)'; t.style.transition = 'all .2s'; setTimeout(() => t.remove(), 220); }, ttl);
    t.onclick = () => t.remove();
  }
  // Inject animation
  if (!document.getElementById('kt-toast-css')) {
    const s = document.createElement('style');
    s.id = 'kt-toast-css';
    s.textContent = '@keyframes kt-toast-in { from { opacity: 0; transform: translateX(20px); } to { opacity: 1; transform: translateX(0); } }';
    document.head.appendChild(s);
  }

  window.KT = window.KT || {};
  window.KT.toast = showToast;

  // ============================ Table filter + pagination ============================
  // Rows-per-page for the client-side pager. Tables at or below this show no pager
  // (everything fits on one page); larger tables get numbered page buttons + a jump box.
  const PAGE_SIZE = 25;

  function attachFilter(table) {
    // Opt-out for screens that own their own search. The email log, for one, searches
    // SERVER-side across every row — bolting this client-side box (which can only see
    // the rows currently loaded) beside it gives the user two search fields that
    // disagree with each other.
    if (table.hasAttribute('data-kt-no-filter')) return;
    if (table.dataset.ktFiltered) return;
    table.dataset.ktFiltered = '1';
    const tbody = table.querySelector('tbody');
    if (!tbody || tbody.children.length < 2) return; // skip 1-row tables

    const wrap = document.createElement('div');
    wrap.className = 'kt-table-filter';
    wrap.style.cssText = 'display:flex;justify-content:flex-start;align-items:center;gap:12px;margin:14px 0 10px;flex-wrap:wrap;';

    const left = document.createElement('div');
    left.style.cssText = 'position:relative;flex:1;min-width:240px;max-width:380px;';
    const input = document.createElement('input');
    input.type = 'search';
    input.placeholder = '🔍  Filter ' + tbody.children.length + ' rows…';
    input.className = 'kt-table-filter-input';
    input.style.cssText = 'width:100%;padding:9px 14px;border:1px solid #E2E8F0;border-radius:8px;font-size:13.5px;background:#fff;transition:border-color .15s;';
    input.addEventListener('focus', () => input.style.borderColor = '#1F6080');
    input.addEventListener('blur', () => input.style.borderColor = '#E2E8F0');
    left.appendChild(input);

    const right = document.createElement('div');
    right.style.cssText = 'display:flex;align-items:center;gap:14px;color:#64748B;font-size:13px;font-weight:600;flex-shrink:0;white-space:nowrap;margin-left:auto;';
    const counter = document.createElement('span');
    counter.className = 'kt-table-filter-count';
    counter.textContent = tbody.children.length + ' / ' + tbody.children.length;
    right.appendChild(counter);

    wrap.appendChild(left);
    wrap.appendChild(right);

    // Bounded height + internal scroll (Gmail-style): wrap the table in a
    // scroll container so long tables scroll WITHIN a reasonable box instead of
    // stretching the page. The toolbar (search + count) is inserted ABOVE the
    // scroll container — never inside an overflow box — so the count is never
    // clipped. Skipped for modal tables (they scroll themselves).
    let sc = table.closest('.kt-tbl-scroll');
    if (!sc && !table.closest('#modalRoot')) {
      sc = document.createElement('div');
      sc.className = 'kt-tbl-scroll';
      table.parentElement.insertBefore(sc, table);
      sc.appendChild(table);
    }
    (sc ? sc.parentElement : table.parentElement).insertBefore(wrap, sc || table);

    // Pagination footer — inserted BELOW the scroll container so it's always visible.
    const pager = document.createElement('div');
    pager.className = 'kt-table-pager';
    pager.style.cssText = 'display:none;justify-content:center;align-items:center;gap:6px;margin:12px 0 2px;flex-wrap:wrap;font-size:13px;';
    const anchor = sc || table;
    anchor.parentElement.insertBefore(pager, anchor.nextSibling);

    let page = 1;

    function pageBtn(label, target, active) {
      const b = document.createElement('button');
      b.type = 'button';
      b.textContent = label;
      const base = 'min-width:34px;padding:6px 10px;border-radius:7px;border:1px solid #E2E8F0;background:#fff;color:#334155;font-size:13px;font-weight:600;cursor:pointer;transition:all .12s;';
      b.style.cssText = active ? base + 'background:#1F6080;color:#fff;border-color:#1F6080;' : base;
      if (target == null) { b.disabled = true; b.style.opacity = '0.4'; b.style.cursor = 'default'; }
      else b.addEventListener('click', () => { page = target; render(); });
      return b;
    }

    // Windowed page list: first, last, and a few around the current page, with … gaps.
    function pageWindow(cur, tot) {
      const keep = new Set([1, tot, cur, cur - 1, cur + 1, cur - 2, cur + 2]);
      const arr = [...keep].filter(p => p >= 1 && p <= tot).sort((a, b) => a - b);
      const out = [];
      let prev = 0;
      for (const p of arr) { if (p - prev > 1) out.push('…'); out.push(p); prev = p; }
      return out;
    }

    function buildPager(totalPages) {
      if (totalPages <= 1) { pager.style.display = 'none'; pager.innerHTML = ''; return; }
      pager.style.display = 'flex';
      pager.innerHTML = '';
      pager.appendChild(pageBtn('‹ Prev', page > 1 ? page - 1 : null));
      for (const p of pageWindow(page, totalPages)) {
        if (p === '…') {
          const s = document.createElement('span');
          s.textContent = '…'; s.style.cssText = 'padding:0 4px;color:#94A3B8;';
          pager.appendChild(s);
        } else {
          pager.appendChild(pageBtn(String(p), p, p === page));
        }
      }
      pager.appendChild(pageBtn('Next ›', page < totalPages ? page + 1 : null));
      const jl = document.createElement('span');
      jl.textContent = 'Go to'; jl.style.cssText = 'margin-left:10px;color:#64748B;font-weight:600;';
      const jump = document.createElement('input');
      jump.type = 'number'; jump.min = '1'; jump.max = String(totalPages); jump.value = String(page);
      jump.style.cssText = 'width:60px;padding:5px 8px;border:1px solid #E2E8F0;border-radius:7px;font-size:13px;text-align:center;';
      const go = () => { const v = parseInt(jump.value, 10); if (v >= 1 && v <= totalPages) { page = v; render(); } };
      jump.addEventListener('keydown', e => { if (e.key === 'Enter') { e.preventDefault(); go(); } });
      jump.addEventListener('change', go);
      pager.appendChild(jl); pager.appendChild(jump);
    }

    // Single source of truth for row visibility: a row shows iff it matches the
    // filter AND falls on the current page (of the matched subset). Called on input,
    // on page change, and whenever the tbody changes (sort / screen re-render).
    let renderScheduled = false;
    function render() {
      renderScheduled = false;
      const q = (input.value || '').trim().toLowerCase();
      const rows = Array.from(tbody.children);
      const matched = q ? rows.filter(r => (r.textContent || '').toLowerCase().includes(q)) : rows;
      const total = matched.length;
      const totalPages = Math.max(1, Math.ceil(total / PAGE_SIZE));
      if (page > totalPages) page = totalPages;
      if (page < 1) page = 1;
      const start = (page - 1) * PAGE_SIZE, end = start + PAGE_SIZE;
      let mi = 0;
      for (const r of rows) {
        const ok = !q || (r.textContent || '').toLowerCase().includes(q);
        if (!ok) { r.style.display = 'none'; continue; }
        r.style.display = (mi >= start && mi < end) ? '' : 'none';
        mi++;
      }
      const from = total ? start + 1 : 0;
      const to = Math.min(end, total);
      counter.textContent = (total === rows.length)
        ? (from + '–' + to + ' of ' + total)
        : (from + '–' + to + ' of ' + total + ' (filtered from ' + rows.length + ')');
      counter.style.color = total ? '#64748B' : '#EF4444';
      buildPager(totalPages);
    }
    function scheduleRender() {
      if (renderScheduled) return;
      renderScheduled = true;
      setTimeout(render, 40);
    }

    input.addEventListener('input', () => { page = 1; render(); });

    // Re-paginate when the tbody's rows change (column-header sort reorders them;
    // a screen re-render replaces them). We watch childList only — render() itself
    // only flips style.display (an attribute change), so it can't retrigger this.
    try {
      const mo = new MutationObserver(scheduleRender);
      mo.observe(tbody, { childList: true });
    } catch (e) {}

    render();
  }

  function sweepTables() {
    // EVERY table in the app, not only screens flagged data-kt-pretty. Certifications
    // (and others) were never flagged, so they got no search, no sort and no live
    // record count. attachFilter is guarded by a dataset flag, so this attaches once
    // per table however often the sweep runs.
    document.querySelectorAll('#appMain table').forEach(t => {
      // skip tables with only a "no data" empty-state row
      const tbody = t.querySelector('tbody');
      if (!tbody) return;
      if (tbody.children.length === 1) {
        const td = tbody.children[0].querySelector('td');
        if (td && td.colSpan && td.colSpan > 1) return; // it's an empty-state row
      }
      attachFilter(t);
    });
  }

  // Run on hashchange + at intervals
  let sweepTimer = null;
  function scheduleSweep() {
    if (sweepTimer) clearTimeout(sweepTimer);
    sweepTimer = setTimeout(sweepTables, 350);
  }
  window.addEventListener('hashchange', scheduleSweep);
  (window.KT && KT.sweepBus) ? KT.sweepBus.on(sweepTables) : setInterval(sweepTables, 4000);    // was 2000ms — the toolbar landed long after the table
  setTimeout(sweepTables, 700);

  // ============================ Better empty states ============================
  function sweepEmptyStates() {
    document.querySelectorAll('[data-kt-pretty] tbody').forEach(tb => {
      if (tb.dataset.ktEmpty) return;
      if (tb.children.length === 1) {
        const td = tb.children[0].querySelector('td');
        if (td && td.colSpan && td.colSpan > 1 && /no\s+|none|empty|haven/i.test(td.textContent || '')) {
          // already an empty-state row — style it nicer
          td.style.padding = '40px 20px';
          if (!td.querySelector('.kt-empty-icon')) {
            td.innerHTML = '<div class="kt-empty-icon" style="font-size:42px;margin-bottom:8px;opacity:.4;">📭</div>' + td.innerHTML;
          }
          tb.dataset.ktEmpty = '1';
        }
      }
    });
  }
  (window.KT && KT.sweepBus) ? KT.sweepBus.on(sweepEmptyStates) : setInterval(sweepEmptyStates, 4000);
  setTimeout(sweepEmptyStates, 800);

  // ============================ Loading skeleton ============================
  // expose a helper screens can call: KT.skeleton(main, rowCount)
  window.KT.skeleton = function (host, rows = 5) {
    host.innerHTML = '<div style="padding:24px;max-width:1800px;margin:0 auto;">'
      + '<div style="background:linear-gradient(90deg,#F1F5F9 0%,#E2E8F0 50%,#F1F5F9 100%);background-size:200% 100%;animation:kt-shimmer 1.4s linear infinite;height:120px;border-radius:14px;margin-bottom:18px;"></div>'
      + Array.from({length: rows}).map(() =>
        '<div style="background:linear-gradient(90deg,#F1F5F9 0%,#E2E8F0 50%,#F1F5F9 100%);background-size:200% 100%;animation:kt-shimmer 1.4s linear infinite;height:60px;border-radius:10px;margin-bottom:8px;"></div>'
      ).join('')
      + '</div>';
    if (!document.getElementById('kt-shimmer-css')) {
      const s = document.createElement('style');
      s.id = 'kt-shimmer-css';
      s.textContent = '@keyframes kt-shimmer { 0% { background-position: 200% 0; } 100% { background-position: -200% 0; } }';
      document.head.appendChild(s);
    }
  };

  // ============================ Override window.alert to use toasts ============================
  // Only on /dashboard-y pages, not on auth pages.
  if (location.pathname.indexOf('dashboard') !== -1 || location.pathname === '/' || location.pathname.endsWith('.html')) {
    const origAlert = window.alert;
    window.alert = function (msg) {
      try {
        if (/error|fail|wrong|denied|expired|invalid/i.test(String(msg))) showToast(msg, 'error', 6000);
        else if (/saved|sent|approved|done|success|complete|removed|added|charged|paid/i.test(String(msg))) showToast(msg, 'success');
        else showToast(msg, 'info');
      } catch (e) { origAlert(msg); }
    };
  }
})(window);
