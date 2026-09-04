/* ═══════════════════════════════════════════════════════════════════
   KIDDIETRAC v23 — Expenses (accounts payable)
   Suppliers · Purchase orders · Bills (supplier invoices) · Payments.
   Hash: #expenses  ·  agency_admin / platform_admin / centre_director
   ═══════════════════════════════════════════════════════════════════ */
(function (window) {
  'use strict';
  if (!window.KT) return;
  var KT = window.KT;
  var Api = KT.Api, Dom = KT.Dom, Shell = KT.Shell;

  var _centres = [];
  var _suppliers = [];
  var _tab = 'summary';

  // ── helpers ────────────────────────────────────────────────────────
  function money(n) { n = Number(n) || 0; try { return new Intl.NumberFormat(undefined, { style: 'currency', currency: 'CAD' }).format(n); } catch (e) { return '$' + n.toFixed(2); } }
  function inputStyle() { return 'width:100%;padding:9px 12px;border:1px solid #D1D5DB;border-radius:8px;font-size:14px;box-sizing:border-box;'; }
  function label(t) { return Dom.el('label', { style: 'display:block;font-size:13px;font-weight:600;color:#374151;margin-bottom:4px;' }, t); }
  function btnPrimary() { return 'background:#1F6080;color:white;border:none;padding:9px 18px;border-radius:8px;font-weight:700;cursor:pointer;font-size:14px;'; }
  function toast(m, t) { if (Dom.toast) Dom.toast(m, t || 'success'); }
  function fmtDate(d) { if (!d) return '—'; try { return new Date(d).toLocaleDateString(undefined, { year: 'numeric', month: 'short', day: 'numeric' }); } catch (e) { return String(d).slice(0, 10); } }

  var STATUS_STYLES = {
    draft:             ['#F3F4F6', '#6B7280', 'Draft'],
    awaiting_approval: ['#FEF3C7', '#92400E', 'Awaiting approval'],
    approved:          ['#DBEAFE', '#1E40AF', 'Approved'],
    partial:           ['#CCFBF1', '#0F766E', 'Partially paid'],
    paid:              ['#DCFCE7', '#166534', 'Paid'],
    overdue:           ['#FEE2E2', '#991B1B', 'Overdue'],
    void:              ['#F3F4F6', '#9CA3AF', 'Void'],
    ordered:           ['#DBEAFE', '#1E40AF', 'Ordered'],
    received:          ['#DCFCE7', '#166534', 'Received'],
    cancelled:         ['#F3F4F6', '#9CA3AF', 'Cancelled'],
  };
  function statusTag(s) {
    var st = STATUS_STYLES[s] || ['#F3F4F6', '#6B7280', s];
    return Dom.el('span', { style: 'background:' + st[0] + ';color:' + st[1] + ';font-size:11px;font-weight:700;padding:3px 10px;border-radius:999px;white-space:nowrap;' }, st[2]);
  }

  function centreName(id) { if (!id) return 'Agency-wide'; var c = _centres.filter(function (x) { return String(x.id) === String(id); })[0]; return c ? c.name : ('Centre #' + id); }

  // ── line-item mini editor ──────────────────────────────────────────
  function lineEditor(initial) {
    var wrap = Dom.el('div', {});
    var head = Dom.el('div', { style: 'display:grid;grid-template-columns:1fr 70px 90px 90px 28px;gap:6px;font-size:11px;font-weight:700;color:#64748B;text-transform:uppercase;padding:0 2px 4px;' });
    ['Description', 'Qty', 'Unit $', 'Amount', ''].forEach(function (h) { head.appendChild(Dom.el('div', {}, h)); });
    wrap.appendChild(head);
    var rows = Dom.el('div', {});
    wrap.appendChild(rows);

    function addRow(l) {
      l = l || { description: '', quantity: 1, unit_price: '', amount: '' };
      var r = Dom.el('div', { style: 'display:grid;grid-template-columns:1fr 70px 90px 90px 28px;gap:6px;margin-bottom:6px;align-items:center;' });
      var desc = Dom.el('input', { type: 'text', value: l.description || '', placeholder: 'Item', style: inputStyle() });
      var qty = Dom.el('input', { type: 'number', step: '0.01', min: '0', value: (l.quantity != null ? l.quantity : 1), style: inputStyle() });
      var unit = Dom.el('input', { type: 'number', step: '0.01', min: '0', value: (l.unit_price != null && l.unit_price !== '' ? l.unit_price : ''), style: inputStyle() });
      var amt = Dom.el('input', { type: 'number', step: '0.01', min: '0', value: (l.amount != null && l.amount !== '' ? l.amount : ''), style: inputStyle() + 'background:#F9FAFB;' });
      var del = Dom.el('button', { type: 'button', title: 'Remove', style: 'background:none;border:none;color:#EF4444;font-size:18px;cursor:pointer;line-height:1;' }, '×');
      function recomputeAmt() { var a = (parseFloat(qty.value) || 0) * (parseFloat(unit.value) || 0); if (a) amt.value = a.toFixed(2); syncTotal(); }
      qty.addEventListener('input', recomputeAmt);
      unit.addEventListener('input', recomputeAmt);
      amt.addEventListener('input', syncTotal);
      del.addEventListener('click', function () { rows.removeChild(r); syncTotal(); });
      r.appendChild(desc); r.appendChild(qty); r.appendChild(unit); r.appendChild(amt); r.appendChild(del);
      r._get = function () { return { description: desc.value.trim(), quantity: parseFloat(qty.value) || 0, unit_price: parseFloat(unit.value) || 0, amount: parseFloat(amt.value) || 0 }; };
      rows.appendChild(r);
    }
    (initial && initial.length ? initial : [null]).forEach(addRow);

    var addBtn = Dom.el('button', { type: 'button', style: 'background:#EFF6FF;color:#1D4ED8;border:1px dashed #93C5FD;padding:6px 12px;border-radius:8px;font-size:12px;font-weight:600;cursor:pointer;margin-top:2px;' }, '+ Add line');
    addBtn.addEventListener('click', function () { addRow(); });
    wrap.appendChild(addBtn);

    var totals = Dom.el('div', { style: 'display:grid;grid-template-columns:1fr 120px;gap:6px;margin-top:12px;align-items:center;' });
    totals.appendChild(Dom.el('div', { style: 'text-align:right;font-size:13px;color:#374151;' }, 'Tax ($)'));
    var taxIn = Dom.el('input', { type: 'number', step: '0.01', min: '0', value: (initial && initial._tax != null ? initial._tax : 0), style: inputStyle() });
    totals.appendChild(taxIn);
    wrap.appendChild(totals);

    var totalLine = Dom.el('div', { style: 'text-align:right;font-weight:800;font-size:15px;color:#111827;margin-top:8px;' }, '');
    wrap.appendChild(totalLine);

    function getLines() { return Array.prototype.map.call(rows.children, function (r) { return r._get(); }).filter(function (l) { return l.description; }); }
    function subtotal() { return getLines().reduce(function (s, l) { return s + (l.amount || 0); }, 0); }
    function syncTotal() { var sub = subtotal(); var tax = parseFloat(taxIn.value) || 0; totalLine.textContent = 'Subtotal ' + money(sub) + '  ·  Total ' + money(sub + tax); }
    taxIn.addEventListener('input', syncTotal);
    syncTotal();

    return { el: wrap, getLines: getLines, getTax: function () { return parseFloat(taxIn.value) || 0; } };
  }

  function supplierSelect(selectedId) {
    var sel = Dom.el('select', { style: inputStyle() });
    sel.appendChild(Dom.el('option', { value: '' }, _suppliers.length ? '— Select supplier —' : 'No suppliers yet'));
    _suppliers.forEach(function (s) { var o = Dom.el('option', { value: String(s.id) }, s.name); if (String(selectedId) === String(s.id)) o.selected = true; sel.appendChild(o); });
    return sel;
  }
  function centreSelect(selectedId) {
    var sel = Dom.el('select', { style: inputStyle() });
    sel.appendChild(Dom.el('option', { value: '' }, 'Agency-wide (no centre)'));
    _centres.forEach(function (c) { var o = Dom.el('option', { value: String(c.id) }, c.name); if (String(selectedId) === String(c.id)) o.selected = true; sel.appendChild(o); });
    return sel;
  }

  /* The newest render wins.

     render() awaits two API calls before it paints, and a tab click calls render()
     again -- which clears the container out from under the first one. Without this
     the older promise resolves and paints into a node that is no longer in the
     document: legal, silent, and it leaves the screen showing "Loading…" for good.
     Every paint below checks it is still the current render first. */
  var _seq = 0;

  /* Suppliers and centres do not change while the screen is open, but every render
     re-fetched both -- 24 tab clicks measured 87 requests. Held for the life of the
     screen and dropped by anything that edits a supplier. */
  var _refs = null;
  function invalidateRefs() { _refs = null; }

  function loadRefs() {
    if (_refs) { return Promise.resolve(_refs); }
    return Promise.all([
      Api.get('/admin/suppliers').catch(function () { return { suppliers: [] }; }),
      Api.get('/admin/centres').catch(function () { return { centres: [] }; }),
    ]).then(function (res) {
      _refs = {
        suppliers: (res[0].suppliers || []).map(function (s) { return { id: s.id, name: s.name }; }),
        centres: (res[1].centres || []).map(function (c) { return { id: c.id, name: c.name }; }),
      };
      return _refs;
    });
  }

  // ── main render ─────────────────────────────────────────────────────
  function render(container) {
    var mySeq = ++_seq;
    Dom.clear(container);
    var wrap = Dom.el('div', { style: 'padding:24px;max-width:1120px;margin:0 auto;' });
    container.appendChild(wrap);

    var hero = Dom.el('div', { class: 'kt-hero', style: 'background:linear-gradient(135deg,#1F6080 0%,#16637A 70%,#0EA5A0 100%);' });
    hero.innerHTML = '<div class="kt-hero-greet">💼 FINANCE</div><h1>Expenses</h1><div class="kt-hero-sub">Track suppliers, purchase orders and bills — from PO to approval to payment.</div>';
    wrap.appendChild(hero);

    var tabs = Dom.el('div', { style: 'display:flex;gap:4px;margin:18px 0 16px;border-bottom:1px solid #E5E7EB;flex-wrap:wrap;' });
    [['summary', '📊 Summary'], ['bills', '🧾 Bills'], ['pos', '📦 Purchase orders'], ['suppliers', '🏢 Suppliers']].forEach(function (t) {
      var on = _tab === t[0];
      var b = Dom.el('button', { style: 'background:none;border:none;border-bottom:3px solid ' + (on ? '#1F6080' : 'transparent') + ';padding:10px 14px;font-size:14px;font-weight:700;color:' + (on ? '#1F6080' : '#6B7280') + ';cursor:pointer;' }, t[1]);
      b.addEventListener('click', function () { _tab = t[0]; render(container); });
      tabs.appendChild(b);
    });
    wrap.appendChild(tabs);

    var content = Dom.el('div', {});
    wrap.appendChild(content);
    content.appendChild(Dom.el('div', { style: 'padding:40px;text-align:center;color:#64748B;' }, 'Loading…'));

    // Reference data (cached), then draw the tab.
    loadRefs().then(function (refs) {
      /* A newer render replaced us while this was in flight, or the screen is gone.
         Painting now would fill a detached node and strand the user on "Loading…". */
      if (mySeq !== _seq || !container.isConnected) { return; }
      _suppliers = refs.suppliers;
      _centres = refs.centres;
      Dom.clear(content);
      if (_tab === 'summary') return drawSummary(content, container);
      if (_tab === 'bills') return drawBills(content, container);
      if (_tab === 'pos') return drawPOs(content, container);
      if (_tab === 'suppliers') return drawSuppliers(content, container);
    }).catch(function (e) {
      if (mySeq !== _seq || !container.isConnected) { return; }
      Dom.clear(content); content.appendChild(Dom.el('div', { style: 'padding:24px;color:#DC2626;' }, 'Could not load: ' + (e.message || 'error')));
    });
  }

  // ── Summary tab ─────────────────────────────────────────────────────
  function drawSummary(content, container) {
    content.appendChild(Dom.el('div', { style: 'padding:24px;text-align:center;color:#64748B;' }, 'Loading summary…'));
    Api.get('/admin/expenses/summary').then(function (d) {
      var s = d.summary || {};
      Dom.clear(content);
      var tiles = Dom.el('div', { style: 'display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:14px;margin-bottom:22px;' });
      function tile(labelTxt, value, color, sub) {
        var t = Dom.el('div', { style: 'background:white;border:1px solid #EEF0F3;border-radius:12px;padding:16px 18px;box-shadow:0 1px 3px rgba(0,0,0,.03);' });
        t.appendChild(Dom.el('div', { style: 'font-size:12px;font-weight:700;color:#64748B;text-transform:uppercase;letter-spacing:.4px;' }, labelTxt));
        t.appendChild(Dom.el('div', { style: 'font-size:26px;font-weight:800;color:' + color + ';margin-top:6px;' }, value));
        if (sub) t.appendChild(Dom.el('div', { style: 'font-size:12px;color:#6B7280;margin-top:2px;' }, sub));
        return t;
      }
      tiles.appendChild(tile('Outstanding', money(s.outstanding), '#B45309', 'Unpaid bill balance'));
      tiles.appendChild(tile('Overdue', money(s.overdue_amount), '#B91C1C', (s.overdue_count || 0) + ' bill' + (s.overdue_count === 1 ? '' : 's') + ' past due'));
      tiles.appendChild(tile('Paid this month', money(s.paid_this_month), '#166534', null));
      tiles.appendChild(tile('Suppliers', String(s.supplier_count || 0), '#1F6080', null));
      tiles.appendChild(tile('Open POs', String(s.open_po_count || 0), '#1E40AF', 'Draft or ordered'));
      content.appendChild(tiles);

      var cols = Dom.el('div', { style: 'display:grid;grid-template-columns:1fr 1fr;gap:18px;' });
      // By status
      var c1 = card('By status');
      (s.by_status || []).forEach(function (r) {
        c1.body.appendChild(kvRow(statusTag(r.status), money(r.total) + '  ·  ' + r.c));
      });
      if (!(s.by_status || []).length) c1.body.appendChild(emptyLine('No bills yet.'));
      cols.appendChild(c1.el);
      // By category
      var c2 = card('Spend by category');
      (s.by_category || []).forEach(function (r) {
        c2.body.appendChild(kvRow(Dom.el('span', { style: 'font-size:13px;color:#374151;font-weight:600;' }, r.category), money(r.total)));
      });
      if (!(s.by_category || []).length) c2.body.appendChild(emptyLine('No spend recorded.'));
      cols.appendChild(c2.el);
      content.appendChild(cols);
    }).catch(function (e) { Dom.clear(content); content.appendChild(Dom.el('div', { style: 'padding:24px;color:#DC2626;' }, 'Could not load summary: ' + (e.message || 'error'))); });
  }
  function card(title) {
    var el = Dom.el('div', { style: 'background:white;border:1px solid #EEF0F3;border-radius:12px;overflow:hidden;' });
    el.appendChild(Dom.el('div', { style: 'padding:12px 16px;font-weight:700;color:#111827;border-bottom:1px solid #F3F4F6;font-size:14px;' }, title));
    var body = Dom.el('div', { style: 'padding:6px 16px 12px;' });
    el.appendChild(body);
    return { el: el, body: body };
  }
  function kvRow(left, right) {
    var r = Dom.el('div', { style: 'display:flex;justify-content:space-between;align-items:center;gap:10px;padding:8px 0;border-bottom:1px solid #F6F7F9;' });
    var l = Dom.el('div', {}); if (typeof left === 'string') l.textContent = left; else l.appendChild(left);
    r.appendChild(l); r.appendChild(Dom.el('div', { style: 'font-weight:700;color:#111827;font-size:14px;' }, right));
    return r;
  }
  function emptyLine(t) { return Dom.el('div', { style: 'padding:12px 0;color:#64748B;font-size:13px;' }, t); }

  // ── Bills tab ───────────────────────────────────────────────────────
  function drawBills(content, container) {
    var bar = Dom.el('div', { style: 'display:flex;justify-content:space-between;align-items:center;gap:12px;margin-bottom:14px;flex-wrap:wrap;' });
    var filter = Dom.el('select', { style: inputStyle() + 'width:auto;' });
    [['', 'All statuses'], ['awaiting_approval', 'Awaiting approval'], ['approved', 'Approved'], ['partial', 'Partially paid'], ['overdue', 'Overdue'], ['paid', 'Paid'], ['draft', 'Draft']].forEach(function (o) { filter.appendChild(Dom.el('option', { value: o[0] }, o[1])); });
    bar.appendChild(filter);
    var add = Dom.el('button', { style: btnPrimary() }, '+ New bill');
    add.addEventListener('click', function () { openBillEditor(null, container); });
    bar.appendChild(add);
    content.appendChild(bar);

    var list = Dom.el('div', { 'data-kt-list': '1', 'data-kt-sort': 'amount:Amount:num,due:Due date:date,status:Status', style: 'background:white;border:1px solid #EEF0F3;border-radius:12px;overflow:hidden;' });
    content.appendChild(list);
    function load() {
      Dom.clear(list); list.appendChild(Dom.el('div', { style: 'padding:36px;text-align:center;color:#64748B;' }, 'Loading…'));
      Api.get('/admin/expense-invoices' + (filter.value ? '?status=' + filter.value : '')).then(function (d) {
        var bills = d.expense_invoices || [];
        Dom.clear(list);
        if (!bills.length) { list.appendChild(Dom.el('div', { style: 'padding:44px;text-align:center;color:#6B7280;' }, 'No bills yet. Click + New bill to add one.')); return; }
        bills.forEach(function (b) { list.appendChild(billRow(b, container)); });
      }).catch(function (e) { Dom.clear(list); list.appendChild(Dom.el('div', { style: 'padding:20px;color:#DC2626;' }, e.message || 'error')); });
    }
    filter.addEventListener('change', load);
    load();
  }
  function billRow(b, container) {
    var row = Dom.el('div', { style: 'display:flex;align-items:center;gap:14px;padding:13px 18px;border-bottom:1px solid #F3F4F6;cursor:pointer;' });
    row.setAttribute('data-sort-amount', b.total); row.setAttribute('data-sort-due', b.due_date || ''); row.setAttribute('data-sort-status', b.status || '');
    row.addEventListener('mouseenter', function () { row.style.background = '#FAFBFC'; });
    row.addEventListener('mouseleave', function () { row.style.background = 'white'; });
    row.addEventListener('click', function () { openBillDetail(b.id, container); });
    var main = Dom.el('div', { style: 'flex:1;min-width:0;' });
    var t = Dom.el('div', { style: 'display:flex;align-items:center;gap:8px;flex-wrap:wrap;' });
    t.appendChild(Dom.el('span', { style: 'font-weight:700;color:#111827;font-size:14px;' }, b.supplier_name || 'Supplier'));
    t.appendChild(statusTag(b.status));
    main.appendChild(t);
    var sub = b.reference + (b.invoice_number ? ' · #' + b.invoice_number : '') + ' · ' + (b.category || 'Uncategorised') + ' · due ' + fmtDate(b.due_date);
    main.appendChild(Dom.el('div', { style: 'font-size:12px;color:#6B7280;margin-top:2px;' }, sub));
    row.appendChild(main);
    var amt = Dom.el('div', { style: 'text-align:right;flex-shrink:0;' });
    amt.appendChild(Dom.el('div', { style: 'font-weight:800;color:#111827;font-size:15px;' }, money(b.total)));
    if (Number(b.balance) > 0 && b.status !== 'void') amt.appendChild(Dom.el('div', { style: 'font-size:12px;color:#B45309;' }, money(b.balance) + ' due'));
    else if (b.status === 'paid') amt.appendChild(Dom.el('div', { style: 'font-size:12px;color:#166534;' }, 'Paid'));
    row.appendChild(amt);
    return row;
  }

  function openBillDetail(id, container) {
    Api.get('/admin/expense-invoices/' + id).then(function (d) {
      var b = d.expense_invoice; if (!b) return;
      var body = Dom.el('div', { style: 'width:min(620px,88vw);' });
      var head = Dom.el('div', { style: 'display:flex;justify-content:space-between;align-items:flex-start;gap:12px;margin-bottom:10px;' });
      var hl = Dom.el('div', {});
      hl.appendChild(Dom.el('div', { style: 'font-weight:800;font-size:17px;color:#111827;' }, (b.supplier && b.supplier.name) || 'Supplier'));
      hl.appendChild(Dom.el('div', { style: 'font-size:12px;color:#6B7280;margin-top:2px;' }, b.reference + (b.invoice_number ? ' · supplier #' + b.invoice_number : '') + (b.purchase_order ? ' · from ' + b.purchase_order : '')));
      head.appendChild(hl); head.appendChild(statusTag(b.status));
      body.appendChild(head);

      var meta = Dom.el('div', { style: 'display:grid;grid-template-columns:1fr 1fr 1fr;gap:8px;margin:10px 0 14px;font-size:13px;' });
      meta.appendChild(metaBox('Total', money(b.total)));
      meta.appendChild(metaBox('Paid', money(b.amount_paid)));
      meta.appendChild(metaBox('Balance', money(b.balance)));
      meta.appendChild(metaBox('Category', b.category || '—'));
      meta.appendChild(metaBox('Issued', fmtDate(b.issue_date)));
      meta.appendChild(metaBox('Due', fmtDate(b.due_date)));
      meta.appendChild(metaBox('Centre', centreName(b.centre_id)));
      body.appendChild(meta);

      if ((b.lines || []).length) {
        var lc = Dom.el('div', { style: 'border:1px solid #EEF0F3;border-radius:8px;overflow:hidden;margin-bottom:12px;' });
        b.lines.forEach(function (l) {
          lc.appendChild(kvRow(Dom.el('span', { style: 'font-size:13px;color:#374151;' }, l.description + (Number(l.quantity) !== 1 ? '  ×' + l.quantity : '')), money(l.amount)));
        });
        body.appendChild(lc);
      }
      if ((b.payments || []).length) {
        body.appendChild(Dom.el('div', { style: 'font-size:12px;font-weight:700;color:#64748B;text-transform:uppercase;margin:6px 0 4px;' }, 'Payments'));
        b.payments.forEach(function (p) {
          body.appendChild(kvRow(Dom.el('span', { style: 'font-size:13px;color:#374151;' }, fmtDate(p.paid_at) + ' · ' + (p.method || '') + (p.reference ? ' · ' + p.reference : '')), money(p.amount)));
        });
      }

      var actions = [];
      if (b.status === 'draft' || b.status === 'awaiting_approval') {
        actions.push({ label: '✓ Approve', primary: true, handler: function () { return Api.post('/admin/expense-invoices/' + id + '/approve', {}).then(function () { toast('Bill approved'); Shell.Modal.close && Shell.Modal.close(); render(container); }).catch(function (e) { toast(e.message || 'Failed', 'error'); return false; }); } });
      }
      if (b.status !== 'paid' && b.status !== 'void') {
        actions.push({ label: '💵 Record payment', primary: b.status === 'approved' || b.status === 'partial' || b.status === 'overdue', handler: function () { openPayment(b, container); return false; } });
      }
      actions.push({ label: 'Edit', style: 'btn-secondary', handler: function () { openBillEditor(b, container); return false; } });
      if (b.status !== 'void') actions.push({ label: 'Void', style: 'btn-secondary', handler: async function () { if (!await KT.confirm('Void this bill?')) return false; return Api.post('/admin/expense-invoices/' + id + '/void', {}).then(function () { toast('Bill voided'); Shell.Modal.close && Shell.Modal.close(); render(container); }).catch(function (e) { toast(e.message || 'Failed', 'error'); return false; }); } });

      Shell.Modal.open({ title: 'Bill ' + b.reference, body: body, actions: actions });
    }).catch(function (e) { toast(e.message || 'Could not open bill', 'error'); });
  }
  function metaBox(k, v) {
    var d = Dom.el('div', { style: 'background:#F9FAFB;border-radius:8px;padding:8px 10px;' });
    d.appendChild(Dom.el('div', { style: 'font-size:11px;color:#64748B;font-weight:700;text-transform:uppercase;' }, k));
    d.appendChild(Dom.el('div', { style: 'font-weight:700;color:#111827;margin-top:2px;' }, v));
    return d;
  }

  function openPayment(b, container) {
    var body = Dom.el('div', { style: 'width:min(420px,88vw);' });
    body.appendChild(Dom.el('div', { style: 'background:#F0F9FF;border:1px solid #BAE6FD;border-radius:8px;padding:10px 12px;margin-bottom:12px;font-size:13px;color:#075985;' }, 'Balance owing: ' + money(b.balance)));
    var g = Dom.el('div', { style: 'display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-bottom:10px;' });
    var aWrap = Dom.el('div', {}); aWrap.appendChild(label('Amount *')); var amt = Dom.el('input', { type: 'number', step: '0.01', min: '0.01', value: b.balance, style: inputStyle() }); aWrap.appendChild(amt); g.appendChild(aWrap);
    var mWrap = Dom.el('div', {}); mWrap.appendChild(label('Method')); var method = Dom.el('select', { style: inputStyle() });
    [['bank_transfer', 'Bank transfer'], ['cheque', 'Cheque'], ['e_transfer', 'e-Transfer'], ['credit_card', 'Credit card'], ['cash', 'Cash'], ['other', 'Other']].forEach(function (m) { method.appendChild(Dom.el('option', { value: m[0] }, m[1])); });
    mWrap.appendChild(method); g.appendChild(mWrap);
    body.appendChild(g);
    var rWrap = Dom.el('div', {}); rWrap.appendChild(label('Reference (optional)')); var ref = Dom.el('input', { type: 'text', style: inputStyle(), placeholder: 'Cheque #, confirmation…' }); rWrap.appendChild(ref); body.appendChild(rWrap);

    Shell.Modal.open({
      title: 'Record payment · ' + b.reference, body: body,
      actions: [{ label: 'Record payment', primary: true, handler: function () {
        var v = parseFloat(amt.value) || 0; if (v <= 0) { toast('Enter an amount', 'error'); return false; }
        return Api.post('/admin/expense-invoices/' + b.id + '/payments', { amount: v, method: method.value, reference: ref.value.trim() || null })
          .then(function () { toast('Payment recorded'); render(container); }).catch(function (e) { toast(e.message || 'Failed', 'error'); return false; });
      } }],
    });
  }

  function openBillEditor(existing, container) {
    var b = existing || { supplier_id: '', centre_id: '', invoice_number: '', category: '', issue_date: new Date().toISOString().slice(0, 10), due_date: '', notes: '', lines: [], tax: 0 };
    var body = Dom.el('div', { style: 'width:min(660px,90vw);' });
    var g1 = Dom.el('div', { style: 'display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-bottom:10px;' });
    var supWrap = Dom.el('div', {}); supWrap.appendChild(label('Supplier *')); var sup = supplierSelect(b.supplier_id); supWrap.appendChild(sup); g1.appendChild(supWrap);
    var cenWrap = Dom.el('div', {}); cenWrap.appendChild(label('Centre')); var cen = centreSelect(b.centre_id); cenWrap.appendChild(cen); g1.appendChild(cenWrap);
    body.appendChild(g1);
    var g2 = Dom.el('div', { style: 'display:grid;grid-template-columns:1fr 1fr 1fr;gap:10px;margin-bottom:10px;' });
    var invWrap = Dom.el('div', {}); invWrap.appendChild(label('Supplier invoice #')); var inv = Dom.el('input', { type: 'text', value: b.invoice_number || '', style: inputStyle() }); invWrap.appendChild(inv); g2.appendChild(invWrap);
    var catWrap = Dom.el('div', {}); catWrap.appendChild(label('Category')); var cat = Dom.el('input', { type: 'text', value: b.category || '', placeholder: 'Rent, Supplies…', style: inputStyle() }); catWrap.appendChild(cat); g2.appendChild(catWrap);
    var dueWrap = Dom.el('div', {}); dueWrap.appendChild(label('Due date')); var due = Dom.el('input', { type: 'date', value: b.due_date || '', style: inputStyle() }); dueWrap.appendChild(due); g2.appendChild(dueWrap);
    body.appendChild(g2);
    body.appendChild(label('Line items'));
    var billLines = (b.lines || []).map(function (l) { return { description: l.description, quantity: l.quantity, unit_price: l.unit_price, amount: l.amount }; });
    billLines._tax = (existing && existing.tax != null) ? existing.tax : 0;
    var le = lineEditor(billLines);
    body.appendChild(le.el);

    Shell.Modal.open({
      title: existing ? 'Edit bill ' + (existing.reference || '') : 'New bill', body: body,
      actions: [{ label: existing ? 'Save' : 'Create bill', primary: true, handler: function () {
        if (!sup.value) { toast('Choose a supplier', 'error'); return false; }
        var lines = le.getLines(); if (!lines.length) { toast('Add at least one line item', 'error'); return false; }
        var payload = { supplier_id: parseInt(sup.value, 10), centre_id: cen.value ? parseInt(cen.value, 10) : null, invoice_number: inv.value.trim() || null, category: cat.value.trim() || null, due_date: due.value || null, tax: le.getTax(), lines: lines };
        if (!existing) payload.issue_date = b.issue_date;
        var req = existing ? Api.patch('/admin/expense-invoices/' + existing.id, payload) : Api.post('/admin/expense-invoices', payload);
        return req.then(function () { toast(existing ? 'Bill saved' : 'Bill created'); render(container); }).catch(function (e) { toast(e.message || 'Save failed', 'error'); return false; });
      } }],
    });
  }

  // ── Purchase orders tab ─────────────────────────────────────────────
  function drawPOs(content, container) {
    var bar = Dom.el('div', { style: 'display:flex;justify-content:flex-end;margin-bottom:14px;' });
    var add = Dom.el('button', { style: btnPrimary() }, '+ New purchase order');
    add.addEventListener('click', function () { openPoEditor(null, container); });
    bar.appendChild(add); content.appendChild(bar);
    var list = Dom.el('div', { 'data-kt-list': '1', 'data-kt-sort': 'amount:Amount:num,status:Status', style: 'background:white;border:1px solid #EEF0F3;border-radius:12px;overflow:hidden;' });
    content.appendChild(list);
    list.appendChild(Dom.el('div', { style: 'padding:36px;text-align:center;color:#64748B;' }, 'Loading…'));
    Api.get('/admin/purchase-orders').then(function (d) {
      var pos = d.purchase_orders || []; Dom.clear(list);
      if (!pos.length) { list.appendChild(Dom.el('div', { style: 'padding:44px;text-align:center;color:#6B7280;' }, 'No purchase orders yet.')); return; }
      pos.forEach(function (po) { list.appendChild(poRow(po, container)); });
    }).catch(function (e) { Dom.clear(list); list.appendChild(Dom.el('div', { style: 'padding:20px;color:#DC2626;' }, e.message || 'error')); });
  }
  function poRow(po, container) {
    var row = Dom.el('div', { style: 'display:flex;align-items:center;gap:14px;padding:13px 18px;border-bottom:1px solid #F3F4F6;cursor:pointer;' });
    row.addEventListener('mouseenter', function () { row.style.background = '#FAFBFC'; });
    row.addEventListener('mouseleave', function () { row.style.background = 'white'; });
    row.addEventListener('click', function () { openPoDetail(po.id, container); });
    var main = Dom.el('div', { style: 'flex:1;min-width:0;' });
    var t = Dom.el('div', { style: 'display:flex;align-items:center;gap:8px;flex-wrap:wrap;' });
    t.appendChild(Dom.el('span', { style: 'font-weight:700;color:#111827;font-size:14px;' }, po.supplier_name || 'Supplier'));
    t.appendChild(statusTag(po.status));
    main.appendChild(t);
    main.appendChild(Dom.el('div', { style: 'font-size:12px;color:#6B7280;margin-top:2px;' }, po.po_number + ' · ordered ' + fmtDate(po.order_date) + (po.category ? ' · ' + po.category : '')));
    row.appendChild(main);
    row.appendChild(Dom.el('div', { style: 'font-weight:800;color:#111827;font-size:15px;' }, money(po.total)));
    return row;
  }
  function openPoDetail(id, container) {
    Api.get('/admin/purchase-orders/' + id).then(function (d) {
      var po = d.purchase_order; if (!po) return;
      var body = Dom.el('div', { style: 'width:min(560px,88vw);' });
      var head = Dom.el('div', { style: 'display:flex;justify-content:space-between;align-items:flex-start;gap:12px;margin-bottom:10px;' });
      var hl = Dom.el('div', {});
      hl.appendChild(Dom.el('div', { style: 'font-weight:800;font-size:17px;color:#111827;' }, (po.supplier && po.supplier.name) || 'Supplier'));
      hl.appendChild(Dom.el('div', { style: 'font-size:12px;color:#6B7280;margin-top:2px;' }, po.po_number + ' · ' + centreName(po.centre_id)));
      head.appendChild(hl); head.appendChild(statusTag(po.status)); body.appendChild(head);
      if ((po.lines || []).length) {
        var lc = Dom.el('div', { style: 'border:1px solid #EEF0F3;border-radius:8px;overflow:hidden;margin:8px 0 12px;' });
        po.lines.forEach(function (l) { lc.appendChild(kvRow(Dom.el('span', { style: 'font-size:13px;color:#374151;' }, l.description + (Number(l.quantity) !== 1 ? '  ×' + l.quantity : '')), money(l.amount))); });
        lc.appendChild(kvRow(Dom.el('strong', {}, 'Total (incl. tax ' + money(po.tax) + ')'), money(po.total)));
        body.appendChild(lc);
      }
      var actions = [];
      var alreadyBill = false;
      if (po.status !== 'received' && po.status !== 'cancelled') {
        actions.push({ label: '→ Convert to bill', primary: true, handler: function () {
          return Api.post('/admin/purchase-orders/' + id + '/convert-to-bill', {}).then(function (r) { toast('Bill ' + (r.reference || '') + ' created'); Shell.Modal.close && Shell.Modal.close(); _tab = 'bills'; render(container); }).catch(function (e) { toast(e.message || 'Failed', 'error'); return false; });
        } });
        var nextStatus = po.status === 'draft' ? 'ordered' : 'received';
        actions.push({ label: 'Mark ' + nextStatus, style: 'btn-secondary', handler: function () { return Api.post('/admin/purchase-orders/' + id + '/status', { status: nextStatus }).then(function () { toast('Updated'); Shell.Modal.close && Shell.Modal.close(); render(container); }).catch(function (e) { toast(e.message || 'Failed', 'error'); return false; }); } });
      }
      actions.push({ label: 'Edit', style: 'btn-secondary', handler: function () { openPoEditor(po, container); return false; } });
      Shell.Modal.open({ title: 'PO ' + po.po_number, body: body, actions: actions });
    }).catch(function (e) { toast(e.message || 'Could not open PO', 'error'); });
  }
  function openPoEditor(existing, container) {
    var po = existing || { supplier_id: '', centre_id: '', status: 'draft', order_date: new Date().toISOString().slice(0, 10), category: '', notes: '', lines: [], tax: 0 };
    var body = Dom.el('div', { style: 'width:min(640px,90vw);' });
    var g1 = Dom.el('div', { style: 'display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-bottom:10px;' });
    var supWrap = Dom.el('div', {}); supWrap.appendChild(label('Supplier *')); var sup = supplierSelect(po.supplier_id); supWrap.appendChild(sup); g1.appendChild(supWrap);
    var cenWrap = Dom.el('div', {}); cenWrap.appendChild(label('Centre')); var cen = centreSelect(po.centre_id); cenWrap.appendChild(cen); g1.appendChild(cenWrap);
    body.appendChild(g1);
    var g2 = Dom.el('div', { style: 'display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-bottom:10px;' });
    var odWrap = Dom.el('div', {}); odWrap.appendChild(label('Order date')); var od = Dom.el('input', { type: 'date', value: po.order_date || '', style: inputStyle() }); odWrap.appendChild(od); g2.appendChild(odWrap);
    var catWrap = Dom.el('div', {}); catWrap.appendChild(label('Category')); var cat = Dom.el('input', { type: 'text', value: po.category || '', style: inputStyle() }); catWrap.appendChild(cat); g2.appendChild(catWrap);
    body.appendChild(g2);
    body.appendChild(label('Line items'));
    var poLines = (po.lines || []).map(function (l) { return { description: l.description, quantity: l.quantity, unit_price: l.unit_price, amount: l.amount }; });
    poLines._tax = (existing && existing.tax != null) ? existing.tax : 0;
    var le = lineEditor(poLines);
    body.appendChild(le.el);
    Shell.Modal.open({
      title: existing ? 'Edit ' + (existing.po_number || 'PO') : 'New purchase order', body: body,
      actions: [{ label: existing ? 'Save' : 'Create PO', primary: true, handler: function () {
        if (!sup.value) { toast('Choose a supplier', 'error'); return false; }
        var lines = le.getLines(); if (!lines.length) { toast('Add at least one line item', 'error'); return false; }
        var payload = { supplier_id: parseInt(sup.value, 10), centre_id: cen.value ? parseInt(cen.value, 10) : null, order_date: od.value || null, category: cat.value.trim() || null, tax: le.getTax(), lines: lines };
        if (!existing) payload.status = 'ordered';
        var req = existing ? Api.patch('/admin/purchase-orders/' + existing.id, payload) : Api.post('/admin/purchase-orders', payload);
        return req.then(function () { toast(existing ? 'PO saved' : 'PO created'); render(container); }).catch(function (e) { toast(e.message || 'Save failed', 'error'); return false; });
      } }],
    });
  }

  // ── Suppliers tab ───────────────────────────────────────────────────
  function drawSuppliers(content, container) {
    var bar = Dom.el('div', { style: 'display:flex;justify-content:flex-end;margin-bottom:14px;' });
    var add = Dom.el('button', { style: btnPrimary() }, '+ New supplier');
    add.addEventListener('click', function () { openSupplierEditor(null, container); });
    bar.appendChild(add); content.appendChild(bar);
    var list = Dom.el('div', { 'data-kt-list': '1', 'data-kt-sort': 'outstanding:Outstanding:num', style: 'background:white;border:1px solid #EEF0F3;border-radius:12px;overflow:hidden;' });
    content.appendChild(list);
    list.appendChild(Dom.el('div', { style: 'padding:36px;text-align:center;color:#64748B;' }, 'Loading…'));
    Api.get('/admin/suppliers').then(function (d) {
      var sups = d.suppliers || []; Dom.clear(list);
      if (!sups.length) { list.appendChild(Dom.el('div', { style: 'padding:44px;text-align:center;color:#6B7280;' }, 'No suppliers yet. Add your first vendor.')); return; }
      sups.forEach(function (s) { list.appendChild(supplierRow(s, container)); });
    }).catch(function (e) { Dom.clear(list); list.appendChild(Dom.el('div', { style: 'padding:20px;color:#DC2626;' }, e.message || 'error')); });
  }
  function supplierRow(s, container) {
    var row = Dom.el('div', { style: 'display:flex;align-items:center;gap:14px;padding:13px 18px;border-bottom:1px solid #F3F4F6;cursor:pointer;' });
    row.addEventListener('mouseenter', function () { row.style.background = '#FAFBFC'; });
    row.addEventListener('mouseleave', function () { row.style.background = 'white'; });
    row.addEventListener('click', function () { openSupplierEditor(s, container); });
    row.appendChild(Dom.el('div', { style: 'width:42px;height:42px;border-radius:10px;background:#EAF3F6;color:#1F6080;display:flex;align-items:center;justify-content:center;font-size:16px;font-weight:800;flex-shrink:0;' }, (s.name || '?').slice(0, 1).toUpperCase()));
    var main = Dom.el('div', { style: 'flex:1;min-width:0;' });
    var t = Dom.el('div', { style: 'display:flex;align-items:center;gap:8px;flex-wrap:wrap;' });
    t.appendChild(Dom.el('span', { style: 'font-weight:700;color:#111827;font-size:14px;' }, s.name));
    if (!s.is_active) t.appendChild(Dom.el('span', { style: 'background:#F3F4F6;color:#6B7280;font-size:10px;font-weight:700;padding:2px 8px;border-radius:999px;' }, 'INACTIVE'));
    main.appendChild(t);
    var sub = [s.contact_name, s.email, s.phone].filter(Boolean).join(' · ') || (s.default_category || 'Vendor');
    main.appendChild(Dom.el('div', { style: 'font-size:12px;color:#6B7280;margin-top:2px;' }, sub));
    row.appendChild(main);
    if (Number(s.outstanding) > 0) row.appendChild(Dom.el('div', { style: 'text-align:right;color:#B45309;font-weight:700;font-size:13px;' }, money(s.outstanding) + ' due'));
    return row;
  }
  function openSupplierEditor(existing, container) {
    var s = existing || { name: '', centre_id: '', contact_name: '', email: '', phone: '', address: '', tax_number: '', default_category: '', notes: '', is_active: true };
    var body = Dom.el('div', { style: 'width:min(520px,90vw);' });
    var nameWrap = Dom.el('div', { style: 'margin-bottom:10px;' }); nameWrap.appendChild(label('Supplier name *')); var name = Dom.el('input', { type: 'text', value: s.name || '', style: inputStyle() }); nameWrap.appendChild(name); body.appendChild(nameWrap);
    var g1 = Dom.el('div', { style: 'display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-bottom:10px;' });
    var cWrap = Dom.el('div', {}); cWrap.appendChild(label('Contact name')); var contact = Dom.el('input', { type: 'text', value: s.contact_name || '', style: inputStyle() }); cWrap.appendChild(contact); g1.appendChild(cWrap);
    var cenWrap = Dom.el('div', {}); cenWrap.appendChild(label('Centre')); var cen = centreSelect(s.centre_id); cenWrap.appendChild(cen); g1.appendChild(cenWrap);
    body.appendChild(g1);
    var g2 = Dom.el('div', { style: 'display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-bottom:10px;' });
    var eWrap = Dom.el('div', {}); eWrap.appendChild(label('Email')); var email = Dom.el('input', { type: 'email', value: s.email || '', style: inputStyle() }); eWrap.appendChild(email); g2.appendChild(eWrap);
    var pWrap = Dom.el('div', {}); pWrap.appendChild(label('Phone')); var phone = Dom.el('input', { type: 'text', value: s.phone || '', style: inputStyle() }); pWrap.appendChild(phone); g2.appendChild(pWrap);
    body.appendChild(g2);
    var g3 = Dom.el('div', { style: 'display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-bottom:10px;' });
    var catWrap = Dom.el('div', {}); catWrap.appendChild(label('Default category')); var cat = Dom.el('input', { type: 'text', value: s.default_category || '', placeholder: 'Supplies…', style: inputStyle() }); catWrap.appendChild(cat); g3.appendChild(catWrap);
    var taxWrap = Dom.el('div', {}); taxWrap.appendChild(label('Tax / business #')); var taxn = Dom.el('input', { type: 'text', value: s.tax_number || '', style: inputStyle() }); taxWrap.appendChild(taxn); g3.appendChild(taxWrap);
    body.appendChild(g3);
    var addrWrap = Dom.el('div', { style: 'margin-bottom:10px;' }); addrWrap.appendChild(label('Address')); var addr = Dom.el('textarea', { style: inputStyle() + 'min-height:48px;font-family:inherit;resize:vertical;' }); addr.value = s.address || ''; addrWrap.appendChild(addr); body.appendChild(addrWrap);
    var actWrap = Dom.el('label', { style: 'display:flex;align-items:center;gap:8px;font-size:14px;color:#374151;cursor:pointer;' }); var act = Dom.el('input', { type: 'checkbox' }); if (s.is_active !== false) act.checked = true; actWrap.appendChild(act); actWrap.appendChild(Dom.el('span', {}, 'Active')); body.appendChild(actWrap);

    var actions = [{ label: existing ? 'Save' : 'Create supplier', primary: true, handler: function () {
      if (!name.value.trim()) { toast('Name is required', 'error'); return false; }
      var payload = { name: name.value.trim(), centre_id: cen.value ? parseInt(cen.value, 10) : null, contact_name: contact.value.trim() || null, email: email.value.trim() || null, phone: phone.value.trim() || null, address: addr.value.trim() || null, default_category: cat.value.trim() || null, tax_number: taxn.value.trim() || null, is_active: act.checked };
      var req = existing ? Api.patch('/admin/suppliers/' + existing.id, payload) : Api.post('/admin/suppliers', payload);
      return req.then(function () { toast('Supplier saved'); invalidateRefs(); render(container); }).catch(function (e) { toast(e.message || 'Save failed', 'error'); return false; });
    } }];
    if (existing) actions.unshift({ label: 'Delete', style: 'btn-secondary', handler: async function () { if (!await KT.confirm('Delete supplier “' + existing.name + '”?')) return false; return Api.delete('/admin/suppliers/' + existing.id).then(function () { toast('Supplier deleted'); invalidateRefs(); render(container); }).catch(function (e) { toast(e.message || 'Delete failed', 'error'); return false; }); } });
    Shell.Modal.open({ title: existing ? 'Edit supplier' : 'New supplier', body: body, actions: actions });
  }

  // ── register ────────────────────────────────────────────────────────
  if (Shell && Shell.registerScreen) {
    ['agency_admin', 'platform_admin', 'centre_director'].forEach(function (role) {
      Shell.registerScreen(role + ':expenses', function (container) { _tab = 'summary'; render(container); });
    });
  }
  KT.Expenses = { render: render };
})(window);
