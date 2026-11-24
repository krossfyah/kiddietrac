/* ============================================================
   KIDDIETRAC — e-Transfer requests (2026-10-30)
   Hash: #etransfer-requests — agency_admin, centre_director (own centre), platform_admin
   Backend: /api/v1/billing/etransfer-requests (EtransferRequestController)

   Anthony: "...send a request to have the etransfer link sent to them by the service provider".
   Families ask (from the e-Transfer sheet on their invoice) for the centre to SEND them an Interac
   e-Transfer request. This is the centre's list: who asked, how much, for which invoices, and where to
   send it - copy the details into your bank's "Request money", then mark it sent; mark it paid when
   the money arrives (recorded on the invoices like any e-Transfer payment).
   ============================================================ */
(function (window) {
  'use strict';
  if (!window.KT) return;
  var KT = window.KT, Api = KT.Api, Dom = KT.Dom, Shell = KT.Shell;

  var TABS = [['requested', 'To send'], ['sent', 'Sent'], ['paid', 'Paid'], ['cancelled', 'Cancelled']];
  var state = { tab: 'requested', data: null };
  try { var t = sessionStorage.getItem('kt_etr_tab'); if (t) state.tab = t; } catch (e) {}

  function money(n) { return '$' + (Number(n) || 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 }); }
  function toast(icon, title, body, col) { if (KT.toast) KT.toast(icon, title, body || '', col || '#1F6080'); }
  function copy(text, btn) {
    var done = function () { var was = btn.textContent; btn.textContent = 'Copied ✓'; setTimeout(function () { btn.textContent = was; }, 1400); };
    try { if (navigator.clipboard && navigator.clipboard.writeText) { navigator.clipboard.writeText(text).then(done, function () {}); return; } } catch (e) {}
    try { var ta = document.createElement('textarea'); ta.value = text; ta.style.cssText = 'position:fixed;left:-9999px;'; document.body.appendChild(ta); ta.select(); document.execCommand('copy'); ta.remove(); done(); } catch (e) {}
  }
  function chip(label, value) {
    var c = Dom.el('div', { style: 'display:flex;align-items:center;gap:8px;border:1px solid #E2E8F0;border-radius:10px;padding:6px 6px 6px 10px;min-width:0;background:#fff;' });
    c.appendChild(Dom.el('div', { style: 'flex:1;min-width:0;' }, [
      Dom.el('div', { style: 'font-size:10.5px;font-weight:800;letter-spacing:.5px;text-transform:uppercase;color:#64748B;' }, label),
      Dom.el('div', { style: 'font-size:13.5px;font-weight:700;color:#0F172A;word-break:break-all;' }, value),
    ]));
    var b = Dom.el('button', { type: 'button', 'data-kt-iconized': '1', 'data-kt-btn-free': '1', style: 'flex:0 0 auto;height:28px;min-height:0;padding:0 10px;border-radius:8px;border:1px solid #CBD5E1;background:#fff;color:#1F6080;font-weight:700;font-size:12px;cursor:pointer;' }, 'Copy');
    b.addEventListener('click', function () { copy(value, b); });
    c.appendChild(b);
    return c;
  }
  function btn(label, kind, fn) {
    var css = kind === 'primary' ? 'border:0;background:linear-gradient(135deg,#1B3A63,#2F8C86);color:#fff;'
      : kind === 'good' ? 'border:0;background:#15803D;color:#fff;'
      : 'border:1px solid #CBD5E1;background:#fff;color:#475569;';
    var b = Dom.el('button', { type: 'button', 'data-kt-iconized': '1', style: 'height:32px;padding:0 14px;border-radius:9px;font-weight:800;font-size:13px;cursor:pointer;' + css }, label);
    b.addEventListener('click', function () { fn(b); });
    return b;
  }
  function act(r, path, okTitle, okBody, b) {
    b.disabled = true;
    return Api.post('/billing/etransfer-requests/' + r.id + '/' + path, {}).then(function () {
      toast('✅', okTitle, okBody, '#15803D');
      return load();
    }).catch(function (e) { b.disabled = false; toast('⚠️', 'Could not update the request', (e && e.message) || '', '#B91C1C'); });
  }
  function confirmThen(opts, fn) {
    if (KT.confirm) { KT.confirm(opts).then(function (ok) { if (ok) fn(); }).catch(function () {}); } else { fn(); }
  }

  var root = null;
  function load() {
    return Api.get('/billing/etransfer-requests?status=all').then(function (d) { state.data = d || { requests: [] }; paint(); })
      .catch(function (e) { if (root) { Dom.clear(root); root.appendChild(Dom.el('div', { style: 'padding:24px;color:#B91C1C;' }, 'Could not load: ' + ((e && e.message) || ''))); } });
  }

  function card(r, email) {
    var c = Dom.el('div', { style: 'background:#fff;border:1px solid #E2E8F0;border-radius:14px;padding:14px;box-shadow:0 1px 2px rgba(15,23,42,.04);' });
    var top = Dom.el('div', { style: 'display:flex;align-items:flex-start;gap:10px;flex-wrap:wrap;' });
    top.appendChild(Dom.el('div', { style: 'flex:1;min-width:180px;' }, [
      Dom.el('div', { style: 'font-weight:800;font-size:15px;color:#0F172A;' }, (r.family ? r.family + ' family' : 'Family') + (r.parent ? ' · ' + r.parent : '')),
      // Times come labelled on the agency's clock by the server.
      Dom.el('div', { style: 'font-size:12.5px;color:#64748B;margin-top:2px;' }, [r.centre, 'Asked ' + (r.requested_label || ''),
        r.sent_label ? 'Sent ' + r.sent_label : null, r.paid_label ? 'Paid ' + r.paid_label : null, r.cancelled_label ? 'Cancelled ' + r.cancelled_label : null].filter(Boolean).join(' · ')),
    ]));
    top.appendChild(Dom.el('div', { style: 'font-size:20px;font-weight:900;color:#0F172A;font-variant-numeric:tabular-nums;' }, money(r.amount)));
    c.appendChild(top);
    var grid = Dom.el('div', { style: 'display:grid;grid-template-columns:repeat(auto-fit,minmax(min(100%,230px),1fr));gap:8px;margin-top:10px;' });
    grid.appendChild(chip(r.contact_type === 'email' ? 'Send the request to (email)' : 'Send the request to (mobile)', r.contact));
    grid.appendChild(chip('Amount', (Number(r.amount) || 0).toFixed(2)));
    grid.appendChild(chip('Message / invoice #', r.numbers || ''));
    c.appendChild(grid);
    var acts = Dom.el('div', { style: 'display:flex;gap:8px;flex-wrap:wrap;margin-top:12px;' });
    if (r.status === 'requested') {
      acts.appendChild(btn('📨 Mark request sent', 'primary', function (b) {
        act(r, 'sent', 'Marked as sent', (r.parent || 'The family') + ' was told to look for it in their bank.', b);
      }));
    }
    if (r.status === 'requested' || r.status === 'sent') {
      acts.appendChild(btn('✓ Mark paid', 'good', function (b) {
        confirmThen({ title: 'Mark ' + money(r.amount) + ' as received?', description: 'Records an e-Transfer payment of ' + money(r.amount) + ' against ' + (r.numbers || 'the invoices') + ' and tells the family.', okLabel: 'Mark paid' },
          function () { act(r, 'paid', 'Payment recorded', money(r.amount) + ' against ' + (r.numbers || 'the invoices') + '.', b); });
      }));
      acts.appendChild(btn('Cancel', 'ghost', function (b) {
        confirmThen({ title: 'Cancel this e-Transfer request?', description: 'The family is told, and can still pay with the e-Transfer details on the invoice.', okLabel: 'Cancel request' },
          function () { act(r, 'cancel', 'Request cancelled', '', b); });
      }));
    }
    if (acts.childNodes.length) c.appendChild(acts);
    return c;
  }

  function paint() {
    if (!root) return;
    Dom.clear(root);
    var d = state.data || { requests: [] };
    var reqs = d.requests || [];
    var count = function (s) { return reqs.filter(function (r) { return r.status === s; }).length; };
    var bar = Dom.el('div', { role: 'tablist', 'aria-label': 'e-Transfer requests', style: 'display:flex;gap:4px;margin-bottom:12px;' });
    TABS.forEach(function (t) {
      var n = count(t[0]);
      var b = Dom.el('button', { type: 'button', role: 'tab', 'aria-selected': state.tab === t[0] ? 'true' : 'false', 'data-kt-iconized': '1',
        style: 'height:32px;padding:0 14px;border:0;border-radius:10px;background:transparent;font-weight:700;font-size:13px;cursor:pointer;white-space:nowrap;' }, t[1] + (n ? ' (' + n + ')' : ''));
      b.addEventListener('click', function () { state.tab = t[0]; try { sessionStorage.setItem('kt_etr_tab', t[0]); } catch (e) {} paint(); });
      bar.appendChild(b);
    });
    root.appendChild(bar);
    if (state.tab === 'requested') {
      root.appendChild(Dom.el('div', { style: 'font-size:13px;color:#475569;background:#F1F5F9;border-radius:12px;padding:10px 12px;margin-bottom:12px;line-height:1.5;' },
        'In your bank: Interac e-Transfer → Request money. Copy the family’s email or mobile, the amount and the invoice numbers (as the message) from the request below, send it, then tap Mark request sent - the family is told to look for it.'
        + (d.interac_email ? ' Payments arrive at ' + d.interac_email + '.' : '')));
    }
    var shown = reqs.filter(function (r) { return r.status === state.tab; });
    if (!shown.length) {
      root.appendChild(Dom.el('div', { style: 'background:#fff;border:1px dashed #CBD5E1;border-radius:14px;padding:32px 16px;text-align:center;color:#64748B;font-size:13.5px;' },
        state.tab === 'requested' ? 'No families are waiting for an e-Transfer request.' : 'Nothing here yet.'));
      return;
    }
    var list = Dom.el('div', { style: 'display:flex;flex-direction:column;gap:10px;' });
    shown.forEach(function (r) { list.appendChild(card(r, d.interac_email)); });
    root.appendChild(list);
  }

  function render(main) {
    root = main.querySelector('#etr-root');
    if (!root) { Dom.clear(main); root = document.createElement('div'); root.id = 'etr-root'; root.className = 'kt-section'; main.appendChild(root); }
    Dom.clear(root);
    root.appendChild(Dom.el('div', { style: 'padding:24px;color:#94A3B8;font-size:13px;' }, 'Loading…'));
    load();
  }

  if (Shell && Shell.registerScreen) {
    Shell.registerScreen('agency_admin:etransfer-requests', render);
    Shell.registerScreen('centre_director:etransfer-requests', render);
    Shell.registerScreen('platform_admin:etransfer-requests', render);
  }
  KT.EtransferRequestsScreen = { render: render };
})(window);
