/* ═══════════════════════════════════════════════════════════════════
   KiddieTrac — Synced accounting (invoices from a connected external system).
   Agency-wide view of every invoice pulled LIVE from the external platform
   via the Integration API. Admins + directors only — parents see
   their own under Billing. Read-only mirror; KiddieTrac never collects
   payment on these. GET /agency/external-invoices.
   Registered for agency_admin / centre_director / platform_admin.
   ═══════════════════════════════════════════════════════════════════ */
(function (window) {
  'use strict';
  var KT = (window.KT = window.KT || {});
  var Shell = KT.Shell;
  var Api = KT.Api;

  function esc(s) { return String(s == null ? '' : s).replace(/[&<>"]/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]; }); }
  function money(n, ccy) {
    var v = Number(n) || 0;
    try { return v.toLocaleString('en-CA', { style: 'currency', currency: ccy || 'CAD' }); }
    catch (e) { return '$' + v.toFixed(2); }
  }
  function fmtDate(s) {
    if (!s) return '—';
    var d = new Date(String(s).replace(' ', 'T'));
    if (isNaN(d.getTime())) return esc(s);
    return d.toLocaleDateString('en-CA', { year: 'numeric', month: 'short', day: 'numeric' });
  }
  function statusBadge(status, isOpen) {
    var s = String(status || '').toLowerCase();
    var m = {
      paid:     { bg: '#DCFCE7', fg: '#166534', t: 'Paid' },
      void:     { bg: '#E5E7EB', fg: '#4B5563', t: 'Void' },
      overdue:  { bg: '#FEE2E2', fg: '#991B1B', t: 'Overdue' },
      partial:  { bg: '#FEF3C7', fg: '#92400E', t: 'Partial' },
    };
    var b = m[s] || (isOpen ? { bg: '#E0F2FE', fg: '#075985', t: status || 'Open' } : { bg: '#F1F5F9', fg: '#475569', t: status || '—' });
    return '<span style="background:' + b.bg + ';color:' + b.fg + ';padding:3px 9px;border-radius:999px;font-size:11px;font-weight:800;white-space:nowrap;">' + esc(b.t) + '</span>';
  }

  var state = { page: 1, family_id: 0, search: '', sort: '', dir: 'asc', busy: false };

  function statCard(label, value, sub, c1, c2, ink, tint) {
    return '<div style="background:' + tint + ';border:1px solid rgba(15,23,42,.06);border-radius:16px;padding:16px 17px;">'
      + '<div style="font-size:10.5px;font-weight:800;letter-spacing:.6px;text-transform:uppercase;color:' + ink + ';opacity:.72;">' + esc(label) + '</div>'
      + '<div style="font-size:30px;font-weight:900;line-height:1.05;color:' + ink + ';margin-top:6px;">' + value + '</div>'
      + '<div style="font-size:11.5px;color:#64748b;margin-top:3px;">' + esc(sub) + '</div></div>';
  }

  function openInvoiceEdit(container, inv) {
    var ov = document.createElement('div');
    ov.style.cssText = 'position:fixed;inset:0;background:rgba(15,23,42,.55);z-index:10000;display:flex;align-items:flex-start;justify-content:center;padding:32px 18px;overflow:auto;';
    var m = document.createElement('div');
    m.style.cssText = 'background:#fff;border-radius:16px;max-width:520px;width:100%;box-shadow:0 24px 60px rgba(0,0,0,.35);';
    function fld(label, id, val, type) {
      return '<label style="display:block;font-size:12.5px;font-weight:700;color:#334155;margin-bottom:10px;">' + label
        + '<input id="' + id + '" type="' + (type || 'text') + '" value="' + esc(val == null ? '' : val) + '" style="width:100%;margin-top:4px;padding:9px 11px;border:1px solid #E2E8F0;border-radius:9px;font-size:14px;box-sizing:border-box;"></label>';
    }
    m.innerHTML = '<div style="padding:18px 22px;border-bottom:1px solid #E5E7EB;"><h3 style="margin:0;font-size:17px;">✏️ Edit invoice ' + esc(inv.number || '') + '</h3>'
      + '<div style="font-size:12px;color:#B45309;margin-top:2px;">Edits the KiddieTrac copy — the source may overwrite it on the next sync.</div></div>'
      + '<div style="padding:18px 22px;">'
      + fld('Invoice #', 'iv-number', inv.number)
      + '<div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">' + fld('Status', 'iv-status', inv.status) + fld('Issued', 'iv-issued', (inv.issued_at || '').slice(0, 10), 'date') + '</div>'
      + '<div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">' + fld('Due', 'iv-due', (inv.due_at || '').slice(0, 10), 'date') + fld('Total', 'iv-total', inv.total, 'number') + '</div>'
      + fld('Amount paid', 'iv-paid', inv.amount_paid, 'number')
      + '<label style="display:block;font-size:12.5px;font-weight:700;color:#334155;">Description<textarea id="iv-desc" rows="2" style="width:100%;margin-top:4px;padding:9px 11px;border:1px solid #E2E8F0;border-radius:9px;font-size:14px;box-sizing:border-box;font-family:inherit;">' + esc(inv.description || '') + '</textarea></label>'
      + '<div id="iv-err" style="color:#DC2626;font-size:12.5px;min-height:16px;margin-top:6px;"></div>'
      + '<div style="display:flex;gap:10px;justify-content:flex-end;"><button id="iv-cancel" style="padding:9px 16px;border:1px solid #E2E8F0;border-radius:9px;background:#fff;font-weight:700;cursor:pointer;">Cancel</button><button id="iv-save" class="kt-btn kt-btn-primary" style="padding:9px 18px;">Save</button></div></div>';
    ov.appendChild(m); document.body.appendChild(ov);
    var close = function () { ov.remove(); };
    ov.addEventListener('click', function (ev) { if (ev.target === ov) close(); });
    m.querySelector('#iv-cancel').addEventListener('click', close);
    m.querySelector('#iv-save').addEventListener('click', function () {
      var btn = m.querySelector('#iv-save'); btn.disabled = true; btn.textContent = 'Saving…';
      Api.patch('/agency/external-invoices/' + inv.id, {
        number: (m.querySelector('#iv-number').value || '').trim() || null,
        status: (m.querySelector('#iv-status').value || '').trim() || null,
        issued_at: m.querySelector('#iv-issued').value || null,
        due_at: m.querySelector('#iv-due').value || null,
        total: parseFloat(m.querySelector('#iv-total').value) || 0,
        amount_paid: parseFloat(m.querySelector('#iv-paid').value) || 0,
        description: (m.querySelector('#iv-desc').value || '').trim() || null,
      }).then(function () { close(); load(container); if (KT.toast) KT.toast('✅', 'Saved', 'Invoice updated.', '#16A34A'); })
        .catch(function (err) { btn.disabled = false; btn.textContent = 'Save'; m.querySelector('#iv-err').textContent = (err && err.message) || 'Could not save.'; });
    });
  }

  async function load(container) {
    if (state.busy) return;
    state.busy = true;
    var body = container.querySelector('#xb-body');
    if (body) body.innerHTML = '<div style="padding:36px;text-align:center;color:#94A3B8;">Loading…</div>';
    var qs = '?page=' + state.page + '&per_page=20'
      + (state.family_id ? '&family_id=' + state.family_id : '')
      + (state.search ? '&search=' + encodeURIComponent(state.search) : '')
      + (state.sort ? '&sort=' + state.sort + '&dir=' + state.dir : '')
      + (state.status ? '&status=' + encodeURIComponent(state.status) : '');
    var d;
    try { d = await Api.get('/agency/external-invoices' + qs); }
    catch (e) {
      state.busy = false;
      if (body) body.innerHTML = '<div class="kt-card" style="text-align:center;color:#DC2626;padding:30px;">Could not load: ' + esc(e.message || 'error') + '</div>';
      return;
    }
    state.busy = false;
    renderTable(container, d);
  }

  function renderTable(container, d) {
    var invoices = d.invoices || [];
    var stats = d.stats || {};
    var meta = d.meta || { page: 1, pages: 1, total: 0 };
    var fams = d.families || [];

    // Family filter (rebuild only when empty, so typing search doesn't reset it)
    var famSel = container.querySelector('#xb-family');
    if (famSel && !famSel.getAttribute('data-built')) {
      famSel.innerHTML = '<option value="0">All families (' + fams.length + ')</option>'
        + fams.map(function (f) { return '<option value="' + f.id + '">' + esc(f.label) + '</option>'; }).join('');
      famSel.setAttribute('data-built', '1');
      famSel.value = String(state.family_id);
    }

    var statsRow = container.querySelector('#xb-stats');
    if (statsRow) {
      statsRow.innerHTML =
        statCard('Outstanding', money(stats.open_total), (stats.open_count || 0) + ' open invoice' + ((stats.open_count === 1) ? '' : 's'), '#FDBA74', '#F97316', '#C2410C', '#FFF7ED')
        + statCard('Collected', money(stats.paid_total), (stats.paid_count || 0) + ' paid', '#86EFAC', '#16A34A', '#15803D', '#F0FDF4')
        + statCard('Families', String(fams.length), 'with synced invoices', '#93C5FD', '#2563EB', '#1D4ED8', '#EFF6FF')
        + statCard('Invoices', String(meta.total), 'in this view', '#C4B5FD', '#7C3AED', '#6D28D9', '#F5F3FF');
    }

    var body = container.querySelector('#xb-body');
    if (!invoices.length) {
      body.innerHTML = '<div class="kt-card" style="text-align:center;color:#64748B;padding:40px;">No synced invoices' + (state.search || state.family_id ? ' match this filter.' : ' yet. Invoices appear here automatically as the external platform pushes them.') + '</div>';
      return;
    }
    var th = 'text-align:left;padding:10px 12px;font-size:10.5px;font-weight:800;color:#64748B;text-transform:uppercase;letter-spacing:.5px;white-space:nowrap;';
    var td = 'padding:10px 12px;font-size:13px;color:#334155;border-top:1px solid #F1F5F9;vertical-align:middle;';
    var rows = invoices.map(function (i) {
      var acts = '';
      if (i.pdf_url) {
        acts += '<button type="button" class="xb-act" data-act="view" data-id="' + i.id + '" data-pdf="' + esc(i.pdf_url) + '" style="border:none;background:none;cursor:pointer;color:#2563EB;font-weight:600;font-size:12.5px;padding:3px 7px;">👁 View</button>';
        acts += '<button type="button" class="xb-act" data-act="download" data-id="' + i.id + '" data-pdf="' + esc(i.pdf_url) + '" style="border:none;background:none;cursor:pointer;color:#0F766E;font-weight:600;font-size:12.5px;padding:3px 7px;">⬇ Download</button>';
      }
      acts += '<button type="button" class="xb-act" data-act="edit" data-id="' + i.id + '" style="border:none;background:none;cursor:pointer;color:#334155;font-weight:600;font-size:12.5px;padding:3px 7px;">✏️ Edit</button>';
      return '<tr>'
        + '<td style="' + td + 'font-weight:700;color:#0F172A;">' + esc(i.family || '—') + '</td>'
        + '<td style="' + td + 'font-variant-numeric:tabular-nums;">' + esc(i.number || '—') + '</td>'
        + '<td style="' + td + '">' + statusBadge(i.status, i.is_open) + '</td>'
        + '<td style="' + td + 'white-space:nowrap;color:#64748B;">' + fmtDate(i.issued_at) + '</td>'
        + '<td style="' + td + 'white-space:nowrap;color:#64748B;">' + fmtDate(i.due_at) + '</td>'
        + '<td style="' + td + 'text-align:right;font-variant-numeric:tabular-nums;">' + money(i.total, i.currency) + '</td>'
        + '<td style="' + td + 'text-align:right;font-variant-numeric:tabular-nums;color:#16A34A;">' + money(i.amount_paid, i.currency) + '</td>'
        + '<td style="' + td + 'text-align:right;font-variant-numeric:tabular-nums;font-weight:800;color:' + (i.is_open ? '#B45309' : '#16A34A') + ';">' + money(i.balance_due, i.currency) + '</td>'
        + '<td style="' + td + 'text-align:right;white-space:nowrap;">' + acts + '</td>'
        + '</tr>';
    }).join('');
    var pager = meta.pages > 1
      ? '<div style="display:flex;align-items:center;justify-content:flex-end;gap:10px;padding:12px;font-size:13px;color:#475569;">'
        + '<button id="xb-prev" ' + (meta.page <= 1 ? 'disabled' : '') + ' style="padding:6px 12px;border:1px solid #E2E8F0;border-radius:8px;background:#fff;cursor:' + (meta.page <= 1 ? 'default' : 'pointer') + ';opacity:' + (meta.page <= 1 ? '.5' : '1') + ';">‹ Prev</button>'
        + '<span>Page ' + meta.page + ' of ' + meta.pages + '</span>'
        + '<button id="xb-next" ' + (meta.page >= meta.pages ? 'disabled' : '') + ' style="padding:6px 12px;border:1px solid #E2E8F0;border-radius:8px;background:#fff;cursor:' + (meta.page >= meta.pages ? 'default' : 'pointer') + ';opacity:' + (meta.page >= meta.pages ? '.5' : '1') + ';">Next ›</button></div>'
      : '';
    body.innerHTML = '<div class="kt-card" style="padding:0;overflow:hidden;">'
      + '<div style="overflow-x:auto;"><table style="width:100%;border-collapse:collapse;min-width:820px;">'
      + '<thead><tr style="background:#F8FAFC;">'
      + [
          { h: 'Family', k: 'family', a: '' },
          { h: 'Invoice #', k: 'number', a: '' },
          { h: 'Status', k: 'status', a: '' },
          { h: 'Issued', k: 'issued', a: '' },
          { h: 'Due', k: 'due', a: '' },
          { h: 'Total', k: 'total', a: 'text-align:right;' },
          { h: 'Paid', k: '', a: 'text-align:right;' },
          { h: 'Balance', k: 'amount', a: 'text-align:right;' },
          { h: '', k: '', a: 'text-align:right;' }
        ].map(function (c) {
          var active = c.k && state.sort === c.k;
          var arrow = active ? (state.dir === 'desc' ? ' ▼' : ' ▲') : (c.k ? ' <span style="opacity:.28;">↕</span>' : '');
          var cur = c.k ? 'cursor:pointer;user-select:none;' : '';
          return '<th data-sort="' + c.k + '" style="' + th + c.a + cur + (active ? 'color:#2563EB;' : '') + '">' + c.h + arrow + '</th>';
        }).join('')
      + '</tr></thead><tbody>' + rows + '</tbody></table></div>' + pager + '</div>';

    var prev = body.querySelector('#xb-prev'), next = body.querySelector('#xb-next');
    if (prev) prev.addEventListener('click', function () { if (state.page > 1) { state.page--; load(container); } });
    if (next) next.addEventListener('click', function () { if (state.page < meta.pages) { state.page++; load(container); } });
    body.querySelectorAll('th[data-sort]').forEach(function (thEl) {
      var k = thEl.getAttribute('data-sort');
      if (!k) return;
      thEl.addEventListener('click', function () {
        if (state.sort === k) { state.dir = (state.dir === 'asc' ? 'desc' : 'asc'); }
        else { state.sort = k; state.dir = 'asc'; }
        state.page = 1; load(container);
      });
    });
    // Row actions (collapsed into one kebab by kt-row-actions on desktop).
    // View/Download open the source invoice in the SYSTEM browser (the APK's
    // in-app WebView can't load the external host directly). Edit opens a modal.
    body.querySelectorAll('.xb-act').forEach(function (b) {
      b.addEventListener('click', function () {
        var act = b.getAttribute('data-act');
        if (act === 'view' || act === 'download') {
          var u = b.getAttribute('data-pdf');
          try { window.open(u, '_blank', 'noopener'); } catch (e) { location.href = u; }
          return;
        }
        if (act === 'edit') {
          var inv = (d.invoices || []).find(function (x) { return String(x.id) === b.getAttribute('data-id'); });
          if (inv) openInvoiceEdit(container, inv);
        }
      });
    });
  }

  async function render(container) {
    container.setAttribute('data-kt-pretty', '1');
    state = { page: 1, family_id: 0, search: '', sort: '', dir: 'asc', busy: false, status: '' };
    container.innerHTML =
      '<div style="padding:24px;max-width:1400px;margin:0 auto;">'
      + '<div class="kt-page-hero"><h2>🧾 Accounting</h2><p>Invoices and balances for the agency. Read-only — payments and balances update automatically as they change at the source.</p></div>'
      + '<div id="xb-stats" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(170px,1fr));gap:12px;margin-bottom:16px;"></div>'
      // Voided invoices are excluded from the default list on purpose — they are not
      // part of what is outstanding — so they need a tab of their own to be reachable.
      + '<div id="xb-tabs" style="display:flex;gap:6px;flex-wrap:wrap;border-bottom:1px solid #E2E8F0;margin:0 0 14px;padding:0 0 2px;"></div>'
      + '<div class="kt-card" style="display:flex;gap:12px;flex-wrap:wrap;align-items:center;margin-bottom:14px;">'
      +   '<select id="xb-family" style="padding:9px 11px;border:1px solid #E2E8F0;border-radius:9px;font-size:13.5px;min-width:220px;background:#fff;"><option value="0">All families</option></select>'
      +   '<input id="xb-search" placeholder="🔍 Search invoice # / description / status…" style="flex:1;min-width:220px;padding:9px 12px;border:1px solid #E2E8F0;border-radius:9px;font-size:13.5px;box-sizing:border-box;">'
      + '</div>'
      + '<div id="xb-body"></div></div>';

    var TABS = [
      { key: '', label: 'Outstanding', hint: 'Everything except voided' },
      { key: 'open', label: 'Open' },
      { key: 'paid', label: 'Paid' },
      { key: 'overdue', label: 'Overdue' },
      { key: 'void', label: 'Voided', hint: 'Raised, then cancelled' },
    ];
    var tabBar = container.querySelector('#xb-tabs');
    function paintTabs() {
      tabBar.innerHTML = TABS.map(function (t) {
        var on = state.status === t.key;
        return '<button type="button" data-xb-tab="' + t.key + '"' + (t.hint ? ' title="' + esc(t.hint) + '"' : '')
          + ' style="background:none;border:0;border-bottom:2px solid ' + (on ? '#1F6FB2' : 'transparent')
          + ';padding:9px 13px;font-size:13.5px;font-weight:700;color:' + (on ? '#0F172A' : '#64748B')
          + ';cursor:pointer;border-radius:8px 8px 0 0;">' + esc(t.label) + '</button>';
      }).join('');
      tabBar.querySelectorAll('[data-xb-tab]').forEach(function (b) {
        b.addEventListener('click', function () {
          state.status = b.getAttribute('data-xb-tab');
          state.page = 1;
          paintTabs();
          load(container);
        });
      });
    }
    paintTabs();

    var famSel = container.querySelector('#xb-family');
    famSel.addEventListener('change', function () { state.family_id = +famSel.value || 0; state.page = 1; load(container); });
    var searchEl = container.querySelector('#xb-search');
    var t = null;
    searchEl.addEventListener('input', function () {
      clearTimeout(t);
      t = setTimeout(function () { state.search = searchEl.value.trim(); state.page = 1; load(container); }, 300);
    });

    load(container);
  }

  if (Shell && Shell.registerScreen) {
    ['agency_admin', 'centre_director', 'platform_admin'].forEach(function (r) {
      Shell.registerScreen(r + ':external-billing', render);
    });
  }
  KT.ExternalBilling = { render: render };
})(window);
