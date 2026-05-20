/* v22p54 — XLSX export + import UI helpers.
   Wires every existing list screen to use the new XLSX endpoint and
   adds a single Import button on screens where import templates exist.
*/
(function (window) {
  'use strict';
  const KT = window.KT || {};
  const Api = KT.Api;
  if (!Api) { console.warn('v22p54: KT.Api unavailable'); return; }

  const apiBase = () => (KT.API_BASE) || 'https://api.kiddietrac.com/api/v1';
  const esc = (s) => String(s == null ? '' : s).replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));

  async function downloadAuthed(path, filename) {
    const tok = sessionStorage.getItem('kt_token');
    const r = await fetch(apiBase() + path, { headers: { Authorization: 'Bearer ' + tok } });
    if (!r.ok) { alert('Download failed: ' + r.status); return; }
    const blob = await r.blob();
    const a = document.createElement('a');
    a.href = URL.createObjectURL(blob);
    a.download = filename;
    a.click();
  }

  function buildImportModal(type, label, onDone) {
    const m = document.createElement('div');
    m.style.cssText = 'position:fixed;inset:0;background:rgba(0,0,0,0.45);z-index:99999;display:flex;align-items:center;justify-content:center;';
    m.innerHTML = `<div style="background:#fff;padding:24px;border-radius:12px;max-width:560px;width:92%;max-height:90vh;overflow:auto;">
      <h3 style="margin:0 0 6px;color:#1F6080;">Import ${esc(label)}</h3>
      <p style="color:#6B7280;font-size:13px;margin:0 0 14px;">Step 1: download the template. Step 2: fill in your data. Step 3: upload back.</p>
      <div style="background:#F9FAFB;padding:14px;border-radius:8px;margin-bottom:14px;">
        <button id="tpl-btn" style="background:#1F6080;color:#fff;border:0;padding:10px 18px;border-radius:6px;cursor:pointer;font-weight:600;">⤓ Download template</button>
        <span style="color:#6B7280;font-size:12px;margin-left:10px;">Branded XLSX with headers, samples, and instructions sheet.</span>
      </div>
      <div style="border:2px dashed #D1D5DB;padding:20px;border-radius:8px;text-align:center;">
        <input type="file" id="imp-file" accept=".xlsx,.xls,.csv" style="margin-bottom:10px;">
        <button id="imp-go" style="background:#10B981;color:#fff;border:0;padding:10px 18px;border-radius:6px;cursor:pointer;font-weight:600;">⤒ Upload + import</button>
      </div>
      <div id="imp-result" style="margin-top:14px;"></div>
      <div style="margin-top:18px;text-align:right;">
        <button id="imp-close" style="background:#F3F4F6;border:0;padding:9px 16px;border-radius:6px;cursor:pointer;">Close</button>
      </div></div>`;
    document.body.appendChild(m);
    m.querySelector('#tpl-btn').onclick = () => downloadAuthed(`/import/templates/${type}`, `${type}-import-template.xlsx`);
    m.querySelector('#imp-close').onclick = () => m.remove();
    m.querySelector('#imp-go').onclick = async () => {
      const f = m.querySelector('#imp-file').files[0];
      if (!f) { alert('Pick a file first'); return; }
      const fd = new FormData();
      fd.append('file', f);
      const res = m.querySelector('#imp-result');
      res.innerHTML = '<div style="color:#6B7280;font-size:13px;">Uploading + processing…</div>';
      try {
        const r = await fetch(apiBase() + `/import/${type}`, {
          method: 'POST',
          headers: { Authorization: 'Bearer ' + sessionStorage.getItem('kt_token') },
          body: fd,
        });
        const j = await r.json();
        if (!r.ok) {
          res.innerHTML = `<div style="background:#FEE2E2;color:#991B1B;padding:14px;border-radius:8px;">Import failed: ${esc(j.error || JSON.stringify(j))}</div>`;
          return;
        }
        res.innerHTML = `<div style="background:#DCFCE7;color:#166534;padding:14px;border-radius:8px;">
          ✓ <strong>${j.inserted || 0}</strong> inserted · <strong>${j.updated || 0}</strong> updated · <strong>${j.failed || 0}</strong> failed
        </div>
        ${(j.errors && j.errors.length) ? `<div style="background:#FEF3C7;color:#92400E;padding:12px;border-radius:8px;margin-top:10px;max-height:200px;overflow:auto;font-size:12px;">
          ${j.errors.slice(0, 20).map(e => `<div style="padding:4px 0;border-bottom:1px solid #FCD34D;">Row ${e.row}: ${esc(e.message)}</div>`).join('')}
          ${j.errors.length > 20 ? `<div style="padding:6px 0;font-style:italic;">… and ${j.errors.length - 20} more.</div>` : ''}
        </div>` : ''}`;
        if (typeof onDone === 'function') setTimeout(onDone, 1500);
      } catch (e) {
        res.innerHTML = `<div style="background:#FEE2E2;color:#991B1B;padding:14px;border-radius:8px;">${esc(e.message)}</div>`;
      }
    };
  }

  /**
   * Inject an XLSX-export + Import button pair next to every legacy CSV button.
   * Detects buttons by visible text ("⤓ CSV"), and the screen identifies via
   * a data-export-type attribute on the page container OR via heuristics on
   * the current hash.
   */
  function injectXlsxButtons() {
    document.querySelectorAll('button').forEach(btn => {
      const text = (btn.textContent || '').trim();
      if (!/⤓.*CSV/i.test(text)) return;
      if (btn.dataset.xlsxInjected) return;
      btn.dataset.xlsxInjected = '1';

      const hash = location.hash.replace('#', '').split('?')[0];
      const exportType = mapHashToExportType(hash);
      if (!exportType) return;

      // Replace CSV with Excel
      btn.textContent = '⤓ Excel';
      btn.title = 'Download branded XLSX with logo + colors';
      btn.onclick = () => downloadAuthed(exportType.path, exportType.filename);

      // Add Import sibling if available
      if (exportType.importType) {
        const imp = document.createElement('button');
        imp.textContent = '⤒ Import';
        imp.style.cssText = btn.style.cssText.replace(/background:#?[^;]+;?/, 'background:#7C3AED;');
        imp.title = 'Bulk import from XLSX/CSV';
        imp.onclick = () => buildImportModal(exportType.importType, exportType.label, () => location.reload());
        btn.parentElement.insertBefore(imp, btn.nextSibling);
      }
    });
  }

  function mapHashToExportType(hash) {
    const today = new Date().toISOString().slice(0, 10);
    const map = {
      'admin-users': { path: '/admin/users/xlsx', filename: `users-${today}.xlsx`, importType: 'users', label: 'Users' },
      'admin-families': { path: '/admin/families/xlsx', filename: `families-${today}.xlsx`, importType: 'families', label: 'Families' },
      'admin-children': { path: '/admin/children/xlsx', filename: `children-${today}.xlsx`, importType: 'children', label: 'Children' },
      'audit-logs': { path: '/admin/audit-logs/xlsx', filename: `audit-log-${today}.xlsx` },
      'payroll': { path: '/admin/payroll/xlsx', filename: `payroll-${today}.xlsx` },
      'background-checks': { path: '/admin/background-checks/xlsx', filename: `background-checks-${today}.xlsx` },
      'cwelcc': { path: '/compliance/cwelcc/monthly/xlsx', filename: `CWELCC-${today.substring(0, 7)}.xlsx` },
      'menu': { importType: 'menu', label: 'Menu items' },
      'inspection': { importType: 'inspection', label: 'Inspection items' },
    };
    return map[hash] || null;
  }

  // Also expose explicit injectors for screens that build their own buttons
  function attachToolbar(host, hash) {
    const t = mapHashToExportType(hash);
    if (!t) return;
    const wrap = document.createElement('div');
    wrap.style.cssText = 'display:inline-flex;gap:8px;';
    if (t.path) {
      const x = document.createElement('button');
      x.textContent = '⤓ Excel';
      x.style.cssText = 'background:#059669;color:#fff;border:0;padding:9px 16px;border-radius:6px;cursor:pointer;font-weight:600;';
      x.onclick = () => downloadAuthed(t.path, t.filename);
      wrap.appendChild(x);
    }
    if (t.importType) {
      const i = document.createElement('button');
      i.textContent = '⤒ Import';
      i.style.cssText = 'background:#7C3AED;color:#fff;border:0;padding:9px 16px;border-radius:6px;cursor:pointer;font-weight:600;';
      i.onclick = () => buildImportModal(t.importType, t.label, () => location.reload());
      wrap.appendChild(i);
    }
    host.appendChild(wrap);
  }

  // Auto-inject on hash change + initial load
  function tick() { try { injectXlsxButtons(); } catch (e) { /* swallow */ } }
  window.addEventListener('hashchange', () => setTimeout(tick, 300));
  setInterval(tick, 1500);
  setTimeout(tick, 800);

  window.KT = KT;
  window.KT.V22p54 = { downloadAuthed, buildImportModal, attachToolbar, injectXlsxButtons };
})(window);
