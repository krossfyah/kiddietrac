/* KT.statRings - the circle stats (moved here from screen-admin.js on 2026-11-09 so Field trips can use them
   too; every screen that calls it lists kt-stat-rings.js under "d" in window.__KT_LAZY). */
(function () {
  'use strict';
  var KT = (window.KT = window.KT || {});
  /* CIRCLE STATS (2026-11-04, Anthony: "add some circle stats in the children and user section - keep it simple").
     KT.statRings([{ label, value, of, color }]) -> one compact row of rings. The ring fills value/of; the middle says
     the number and the line under the label says the share. Leave `of` out for a plain total (a full ring). */
  KT.statRings = function (items) {
    if (!document.getElementById('kt-stat-rings-css')) {
      var st = document.createElement('style'); st.id = 'kt-stat-rings-css';
      st.textContent = '.kt-rings{display:flex;flex-wrap:wrap;gap:10px;margin:0 0 14px}'
        + '.kt-ring{display:flex;align-items:center;gap:10px;flex:1 1 170px;min-width:0;background:var(--surface,#fff);border:1px solid var(--ink-100,#E5E7EB);border-radius:12px;padding:10px 12px}'
        + '.kt-ring-c{width:52px;height:52px;border-radius:50%;flex:none;display:flex;align-items:center;justify-content:center}'
        + '.kt-ring-c b{width:40px;height:40px;border-radius:50%;background:var(--surface,#fff);display:flex;align-items:center;justify-content:center;font-size:14px;font-weight:800}'
        + '.kt-ring-l{font-size:13px;font-weight:700;color:var(--ink-700,#334155);line-height:1.25}.kt-ring-s{font-size:11.5px;color:var(--ink-500,#64748B);margin-top:2px}'
        + '@media (max-width:768px){.kt-ring{flex:1 1 calc(50% - 5px);padding:8px 10px;gap:8px}.kt-ring-c{width:44px;height:44px}.kt-ring-c b{width:34px;height:34px;font-size:12.5px}}';
      document.head.appendChild(st);
    }
    var escapeHtml = function (x) { return String(x == null ? '' : x).replace(/[&<>"]/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]; }); };
    var row = document.createElement('div'); row.className = 'kt-rings';
    row.innerHTML = items.map(function (it) {
      var whole = it.of == null, pct = whole ? 100 : (it.of ? Math.round(it.value * 100 / it.of) : 0), col = it.color || '#1F6080';
      return '<div class="kt-ring"><div class="kt-ring-c" style="background:conic-gradient(' + col + ' ' + (pct * 3.6) + 'deg,var(--ink-100,#E5E7EB) 0)"><b style="color:' + col + '">' + it.value + '</b></div>'
        + '<div style="min-width:0"><div class="kt-ring-l">' + escapeHtml(it.label) + '</div><div class="kt-ring-s">' + escapeHtml(whole ? (it.sub || 'in total') : pct + '% of ' + it.of) + '</div></div></div>';
    }).join('');
    return row;
  };
})();
