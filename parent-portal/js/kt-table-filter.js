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

  // ============================ Table filter ============================
  function attachFilter(table) {
    if (table.dataset.ktFiltered) return;
    table.dataset.ktFiltered = '1';
    const tbody = table.querySelector('tbody');
    if (!tbody || tbody.children.length < 2) return; // skip 1-row tables

    const wrap = document.createElement('div');
    wrap.className = 'kt-table-filter';
    wrap.style.cssText = 'display:flex;justify-content:flex-start;align-items:center;gap:12px;margin:0 0 10px;flex-wrap:wrap;';

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

    input.addEventListener('input', () => {
      const q = input.value.trim().toLowerCase();
      const rows = tbody.children;
      let visible = 0;
      for (const row of rows) {
        if (!q) { row.style.display = ''; visible++; continue; }
        const text = (row.textContent || '').toLowerCase();
        if (text.includes(q)) { row.style.display = ''; visible++; }
        else row.style.display = 'none';
      }
      counter.textContent = visible + ' / ' + rows.length;
      counter.style.color = visible ? '#64748B' : '#EF4444';
    });
  }

  function sweepTables() {
    document.querySelectorAll('[data-kt-pretty] table').forEach(t => {
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
  setInterval(sweepTables, 2000);
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
  setInterval(sweepEmptyStates, 2000);
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
