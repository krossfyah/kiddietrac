/* REFERRAL CREDITS (2026-11-26, Anthony: "wire up a way to apply a referral fee to a future invoice to one of our
   customers (one time) - full sweep"). A one-time credit off a KiddieTrac customer's invoice for a chosen month, for
   referring a lead or customer: a fixed $ or a % of that invoice, before tax, never below $0 (a fixed credit's
   remainder carries to the next invoice). Backend: App\Support\ReferralCredits, /platform/referral-credits
   (platform_admin only).

   Shared by Accounting AR/AP (🤝 Referral credits tab) and the Sales customer popup:
     KT.ReferralCredits.panel(host)                       - the list with Add / Cancel
     KT.ReferralCredits.openAdd({ agency_id, onDone })    - the Add popup (Shell.Modal) */
(function (global) {
  'use strict';
  var KT = global.KT = global.KT || {};
  var STATUS = {
    pending: ['Waiting', '#0369A1', '#E0F2FE'],
    partially_applied: ['Part used', '#B45309', '#FEF3C7'],
    applied: ['Applied', '#047857', '#D1FAE5'],
    cancelled: ['Cancelled', '#475569', '#E2E8F0'],
  };

  function esc(v) { return String(v == null ? '' : v).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;'); }
  function money(cents, cur) {
    var v = (Number(cents) || 0) / 100;
    try { return new Intl.NumberFormat('en-CA', { style: 'currency', currency: cur || 'CAD', currencyDisplay: 'code' }).format(v); }
    catch (e) { return (cur || 'CAD') + ' ' + v.toFixed(2); }
  }
  function monthLabel(ym) {
    if (!ym) return '';
    var p = String(ym).split('-');
    try { return new Date(Number(p[0]), Number(p[1]) - 1, 1).toLocaleDateString('en-CA', { month: 'long', year: 'numeric' }); } catch (e) { return ym; }
  }
  function toast(icon, title, sub, color) { if (KT.toast) KT.toast(icon, title, sub || '', color || '#16A34A'); }
  function amountText(c) {
    if (!c.type) return '';
    return c.type === 'percent' ? (Number(c.value) + '% of the invoice') : money(Math.round(Number(c.value) * 100), c.currency);
  }
  function pill(st) {
    var s = STATUS[st] || [st, '#475569', '#E2E8F0'];
    return '<span style="display:inline-block;font-size:11.5px;font-weight:700;border-radius:999px;padding:1px 8px;color:' + s[1] + ';background:' + s[2] + ';">' + esc(s[0]) + '</span>';
  }
  function used(c) {
    if (c.status === 'cancelled') return c.applied_cents ? money(c.applied_cents, c.currency) + ' used before cancelling' : '';
    var t = c.applied_cents ? money(c.applied_cents, c.currency) + ' off' + (c.invoices && c.invoices.length ? ' ' + c.invoices.join(', ') : '') : '';
    if (c.status === 'partially_applied' && c.remaining_cents) t += (t ? ' · ' : '') + money(c.remaining_cents, c.currency) + ' carries to the next invoice';
    return t;
  }

  /** The Add popup. opts.agency_id pre-picks (and locks) the customer. */
  function openAdd(opts) {
    opts = opts || {};
    var M = KT.Shell && KT.Shell.Modal;
    if (!M || !KT.Api) return;
    var body = document.createElement('div');
    body.style.cssText = 'font-size:13.5px;color:#334155;';
    body.innerHTML = '<div style="color:#94A3B8">Loading…</div>';
    var data = null;
    M.open({ title: '🤝 Add a referral credit', body: body, actions: [
      { label: 'Cancel' },
      { label: 'Add credit', primary: true, busyLabel: 'Adding…', handler: async function () {
        if (!data) return false;
        var g = function (k) { return body.querySelector('[data-rc="' + k + '"]'); };
        /* Referred is optional (2026-11-26): "No specific referral" = a general one-time credit with a required reason. */
        var send = { agency_id: Number(g('agency').value), referred: g('lead').value || '', type: g('type').value,
          value: Number(g('value').value), month: g('month').value, notes: g('notes').value.trim() };
        var err = !send.agency_id ? 'Pick the customer whose invoice gets the credit.' : (!send.referred && !send.notes) ? 'With no specific referral, give the reason. It shows on the invoice.'
          : !(send.value > 0) ? 'Enter an amount above zero.' : (send.type === 'percent' && send.value > 100) ? 'A percentage can be at most 100%.' : !send.month ? 'Pick the invoice month.' : '';
        if (err) { toast('⚠️', err, '', '#B45309'); return false; }
        try {
          var r = await KT.Api.post('/platform/referral-credits', send);
          var c = r.credit || {};
          toast('✅', 'Referral credit added', c.status === 'pending' ? 'It comes off the ' + monthLabel(c.month) + ' invoice when it is raised.' : 'It is on the ' + monthLabel(c.month) + ' draft invoice now.');
          if (opts.onDone) opts.onDone(c);
        } catch (e) { toast('⚠️', 'Could not add the credit', e.message || '', '#DC2626'); return false; }
      } },
    ] });
    KT.Api.get('/platform/referral-credits').then(function (d) {
      data = d;
      var F = 'height:34px;padding:0 10px;border:1px solid #D9E1EA;border-radius:9px;font-size:13.5px;box-sizing:border-box;width:100%;';
      var L = 'display:block;font-size:12px;font-weight:700;color:#475569;margin:10px 0 4px;';
      var ag = (d.agencies || []).map(function (a) {
        return '<option value="' + a.id + '"' + (opts.agency_id && Number(opts.agency_id) === a.id ? ' selected' : '') + '>' + esc(a.name) + (a.customer_number ? ' · ' + esc(a.customer_number) : '') + '</option>';
      }).join('');
      /* Every lead and every customer (a customer with a lead shows once), labelled; test agencies tagged Test. */
      var leads = '<option value="" selected>— No specific referral (a one-time credit; give the reason below) —</option>'
        + (d.parties || []).map(function (l) {
          return '<option value="' + esc(l.key) + '" data-ag="' + (l.agency_id || '') + '"' + (l.taken ? ' disabled' : '') + '>'
            + (l.kind === 'customer' ? '[Customer] ' : '[Lead] ') + (l.test ? '[Test] ' : '') + esc(l.name)
            + (l.customer_number ? ' · ' + esc(l.customer_number) : '') + (l.city ? ' · ' + esc(l.city) : '') + (l.stage && l.kind === 'lead' ? ' · ' + esc(l.stage) : '')
            + (l.taken ? ' (already credited)' : '') + '</option>';
        }).join('');
      body.innerHTML =
        '<div style="font-size:12.5px;color:#64748B;">A one-time thank-you off this customer\'s KiddieTrac invoice for the month you pick. It comes off before tax and never takes the invoice below $0; any part of a $ credit not used carries to their next invoice.</div>'
        + '<label><span style="' + L + '">Customer (gets the credit)</span><select data-rc="agency" style="' + F + '"' + (opts.agency_id ? ' disabled' : '') + '><option value="">Choose…</option>' + ag + '</select></label>'
        + '<label><span style="' + L + '">They referred (optional)</span><input data-rc="leadq" placeholder="Type to filter leads and customers" style="' + F + 'margin-bottom:6px;"><select data-rc="lead" size="6" style="' + F + 'height:auto;padding:4px;">' + leads + '</select></label>'
        + '<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:0 10px;">'
        + '<label><span style="' + L + '">Credit as</span><select data-rc="type" style="' + F + '"><option value="fixed">A $ amount</option><option value="percent">A % of the invoice</option></select></label>'
        + '<label><span style="' + L + '" data-rc="vlabel">Amount ($)</span><input data-rc="value" type="number" min="0.01" step="0.01" style="' + F + '"></label>'
        + '<label><span style="' + L + '">Invoice month</span><input data-rc="month" type="month" min="' + esc(d.this_month) + '" value="' + esc(d.this_month) + '" style="' + F + '"></label>'
        + '</div>'
        + '<label><span style="' + L + '" data-rc="nlabel">Reason (shown on the invoice, required)</span><input data-rc="notes" maxlength="500" placeholder="e.g. Thank you for hosting our open house" style="' + F + '"></label>';
      var q = body.querySelector('[data-rc="leadq"]'), sel = body.querySelector('[data-rc="lead"]'), agSel = body.querySelector('[data-rc="agency"]');
      // The reason is required (and on the invoice) only with no referral; otherwise it is an internal note.
      var syncNote = function () {
        var none = !sel.value;
        body.querySelector('[data-rc="nlabel"]').textContent = none ? 'Reason (shown on the invoice, required)' : 'Note (internal, optional)';
        body.querySelector('[data-rc="notes"]').placeholder = none ? 'e.g. Thank you for hosting our open house' : 'e.g. Referred at the October open house';
      };
      // A customer cannot be the one it referred: hide its own entry.
      var syncOwn = function () {
        Array.prototype.forEach.call(sel.options, function (o) { if (o.value) o.disabled = o.getAttribute('data-ag') === agSel.value && !!agSel.value || / \(already credited\)$/.test(o.textContent); });
        if (sel.selectedOptions[0] && sel.selectedOptions[0].disabled) sel.value = '';
        syncNote();
      };
      sel.addEventListener('change', syncNote); agSel.addEventListener('change', syncOwn); syncOwn();
      q.addEventListener('input', function () {
        var s = q.value.trim().toLowerCase();
        Array.prototype.forEach.call(sel.options, function (o) { o.hidden = !!s && o.textContent.toLowerCase().indexOf(s) === -1; });
      });
      var type = body.querySelector('[data-rc="type"]');
      type.addEventListener('change', function () { body.querySelector('[data-rc="vlabel"]').textContent = type.value === 'percent' ? 'Percent (%)' : 'Amount ($)'; });
    }).catch(function (e) { body.innerHTML = '<div style="color:#B91C1C">' + esc(e.message || 'Could not load.') + '</div>'; });
  }

  async function cancel(c, after) {
    var ok = KT.confirm ? await KT.confirm({ title: 'Cancel this referral credit?', description: c.applied_cents ? 'What is already on an invoice stays there; the rest will not be applied.' : 'It will not be applied to any invoice.', okLabel: 'Cancel credit', tone: 'danger' }) : true;
    if (!ok) return;
    try { await KT.Api.post('/platform/referral-credits/' + c.id + '/cancel', {}); toast('✅', 'Referral credit cancelled'); if (after) after(); }
    catch (e) { toast('⚠️', 'Could not cancel', e.message || '', '#DC2626'); }
  }

  /** The list, for Accounting AR/AP. */
  function panel(host) {
    host.innerHTML = '<div style="color:#64748B;font-size:13px;padding:8px 0;">Loading…</div>';
    KT.Api.get('/platform/referral-credits').then(function (d) {
      var rows = d.credits || [];
      var TH = 'text-align:left;padding:7px 8px;font-size:11px;color:#6B7280;text-transform:uppercase;border-bottom:1px solid #E5E7EB;';
      var h = '<div style="background:linear-gradient(180deg,#F5F9FF 0%,#FFFFFF 140px);border:1px solid #DCE7F3;border-radius:10px;padding:14px 16px;margin:12px 0 16px;" data-kt-nopack="1">'
        + '<div style="display:flex;gap:10px;align-items:flex-start;flex-wrap:wrap;"><div style="flex:1 1 260px;"><div style="font-weight:700;">Referral credits</div>'
        + '<div style="font-size:12.5px;color:#64748B;margin:2px 0 10px;">A one-time thank-you off a customer\'s invoice for the month you pick, for referring a lead or customer. One credit per referral; it comes off before tax.</div></div>'
        + '<button class="kt-btn kt-btn-sm" data-rcadd>🤝 Add referral credit</button></div>';
      if (!rows.length) h += '<div style="color:#64748B;font-size:13px;">No referral credits yet.</div>';
      else {
        h += '<div style="overflow-x:auto;"><table data-kt-no-kebab data-kt-no-controls data-kt-no-filter style="width:100%;border-collapse:collapse;font-size:13px;"><thead><tr>'
          + ['Customer', 'Referred', 'Credit', 'Invoice month', 'Status', 'Used', ''].map(function (t) { return '<th style="' + TH + '">' + t + '</th>'; }).join('') + '</tr></thead><tbody>';
        rows.forEach(function (c) {
          var TD = 'padding:7px 8px;border-bottom:1px solid #F1F5F9;vertical-align:top;' + (c.status === 'cancelled' ? 'color:#94A3B8;' : '');
          h += '<tr><td style="' + TD + '">' + esc(c.agency_name || '') + (c.customer_number ? '<div style="font-size:11.5px;color:#1F6080;font-weight:700;">' + esc(c.customer_number) + '</div>' : '') + '</td>'
            + '<td style="' + TD + '">' + (c.general ? '<i style="color:#64748B;">No specific referral</i>' : esc(c.referred_name || 'a new customer')) + (c.notes ? '<div style="font-size:11.5px;color:#64748B;">' + esc(c.notes) + '</div>' : '') + '</td>'
            + '<td style="' + TD + 'white-space:nowrap;font-weight:700;color:#047857;">' + esc(amountText(c)) + '</td>'
            + '<td style="' + TD + 'white-space:nowrap;">' + esc(monthLabel(c.month)) + '</td>'
            + '<td style="' + TD + '">' + pill(c.status) + '</td>'
            + '<td style="' + TD + 'font-size:12.5px;">' + esc(used(c)) + '</td>'
            + '<td style="' + TD + 'text-align:right;">' + (c.status === 'pending' || c.status === 'partially_applied' ? '<button class="kt-btn kt-btn-secondary kt-btn-sm" data-rccancel="' + c.id + '">Cancel</button>' : '') + '</td></tr>';
        });
        h += '</tbody></table></div>';
      }
      host.innerHTML = h + '</div>';
      var reopen = function () { panel(host); };
      host.querySelector('[data-rcadd]').onclick = function () { openAdd({ onDone: reopen }); };
      host.querySelectorAll('[data-rccancel]').forEach(function (b) {
        b.onclick = function () { var c = rows.find(function (x) { return String(x.id) === b.getAttribute('data-rccancel'); }); if (c) cancel(c, reopen); };
      });
    }).catch(function (e) { host.innerHTML = '<div style="color:#B91C1C;">' + esc(e.message) + '</div>'; });
  }

  /** For the customer popup: rows as plain elements. refs = customer.referrals; canEdit = superadmin. */
  function customerBlock(refs, opts) {
    opts = opts || {};
    var box = document.createElement('div');
    refs = refs || { given: [], referred_by: null };
    var h = '';
    if (refs.referred_by) h += '<div style="margin-bottom:8px;">🤝 <b>Referred by</b> ' + esc(refs.referred_by.agency_name || '') + (refs.referred_by.customer_number ? ' <span style="color:#1F6080;font-weight:700;">' + esc(refs.referred_by.customer_number) + '</span>' : '') + '</div>';
    var given = refs.given || [];
    if (given.length) {
      h += given.map(function (c) {
        return '<div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;padding:6px 0;border-bottom:1px solid #F1F5F9;">'
          + '<span style="flex:1 1 220px;min-width:0;">' + (c.general ? 'One-time credit' + (c.notes ? ': <b>' + esc(c.notes) + '</b>' : '') : 'Referral credit for referring <b>' + esc(c.referred_name || 'a new customer') + '</b>')
          + (c.type ? ': <span style="color:#047857;font-weight:700;">' + esc(amountText(c)) + '</span>' : '') + ' · ' + esc(monthLabel(c.month)) + ' invoice'
          + (used(c) ? '<div style="font-size:12px;color:#64748B;">' + esc(used(c)) + '</div>' : '') + '</span>' + pill(c.status)
          + (opts.canEdit && (c.status === 'pending' || c.status === 'partially_applied') ? ' <button class="kt-btn kt-btn-secondary kt-btn-sm" data-rccancel="' + c.id + '">Cancel</button>' : '') + '</div>';
      }).join('');
    } else if (!refs.referred_by) h += '<div style="color:#94A3B8;">No referrals yet.</div>';
    box.innerHTML = h;
    if (opts.canEdit && opts.agency_id) {
      var b = document.createElement('button');
      b.type = 'button'; b.className = 'kt-btn kt-btn-sm'; b.style.marginTop = '8px'; b.textContent = '🤝 Add referral credit';
      b.addEventListener('click', function () { openAdd({ agency_id: opts.agency_id, onDone: opts.onChange }); });
      box.appendChild(b);
    }
    box.querySelectorAll('[data-rccancel]').forEach(function (btn) {
      btn.addEventListener('click', function () { var c = given.find(function (x) { return String(x.id) === btn.getAttribute('data-rccancel'); }); if (c) cancel(c, opts.onChange); });
    });
    return box;
  }

  KT.ReferralCredits = { panel: panel, openAdd: openAdd, customerBlock: customerBlock, monthLabel: monthLabel };
})(window);
