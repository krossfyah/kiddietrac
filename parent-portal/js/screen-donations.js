/* ═══════════════════════════════════════════════════════════════════
   KIDDIETRAC — Finance → Donations  (agency admin)

   Campaigns with a public giving page, gifts (pledged from that page, or recorded here:
   cash, cheque, e-transfer), and receipts. An OFFICIAL receipt for income tax purposes is
   issued only when the agency's charity details and the donor's full name and address are
   complete; otherwise the gift gets an acknowledgement, and the screen says why. Receipts
   are never edited: void, then issue a replacement.

   Backend: DonationController (/admin/donations*, /admin/donation-receipts/*).
   ═══════════════════════════════════════════════════════════════════ */
(function (window) {
  'use strict';
  var KT = window.KT;
  var Api;
  var tab = 'gifts', filt = { campaign_id: '', status: '', year: String(new Date().getFullYear()) };
  var cache = { campaigns: [], settings: null, gifts: [] };

  function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }
  function money(v) { return '$' + Number(v || 0).toLocaleString('en-CA', { minimumFractionDigits: 2, maximumFractionDigits: 2 }); }
  function toast(m, t) { if (KT.toast) { KT.toast(m, t || 'info'); } }
  function day(d) { if (!d) { return '—'; } var p = String(d).slice(0, 10).split('-'); return new Date(Date.UTC(+p[0], +p[1] - 1, +p[2], 12)).toLocaleDateString('en-CA', { month: 'short', day: 'numeric', year: 'numeric', timeZone: 'UTC' }); }
  var METHOD = { cash: 'Cash', cheque: 'Cheque', etransfer: 'e-Transfer', card: 'Card', other: 'Other' };

  async function download(path, filename) {
    var tok = sessionStorage.getItem('kt_token') || localStorage.getItem('kt_token');
    var headers = { Authorization: 'Bearer ' + tok };
    var aid = sessionStorage.getItem('kt_active_agency_id'); if (aid) { headers['X-Active-Agency-Id'] = aid; }
    var base = (KT.API_BASE || 'https://api.kiddietrac.com/api/v1');
    var r = await fetch(base + path, { headers: headers });
    if (!r.ok) { var msg = 'Download failed (' + r.status + ')'; try { var j = await r.json(); if (j.message) { msg = j.message; } } catch (e) {} toast(msg, 'error'); return; }
    var blob = await r.blob(), a = document.createElement('a');
    a.href = URL.createObjectURL(blob); a.download = filename; document.body.appendChild(a); a.click(); a.remove();
  }

  var CSS = '<style id="dn-css">'
    + '.dn-tabs{display:flex;gap:6px;margin:0 0 14px;flex-wrap:wrap}.dn-tabs button{border:1px solid #CBD5E1;background:#fff;border-radius:9px;padding:6px 14px;font-weight:700;font-size:13px;cursor:pointer;color:#334155}'
    + '.dn-tabs button.on{background:#1F6080;border-color:#1F6080;color:#fff}'
    + '.dn-stats{display:grid;grid-template-columns:repeat(auto-fit,minmax(170px,1fr));gap:10px;margin-bottom:14px}'
    + '.dn-stat{background:#fff;border:1px solid #E2E8F0;border-radius:12px;padding:12px 14px}.dn-stat b{display:block;font-size:20px;color:#0F172A}.dn-stat span{font-size:12px;color:#64748B;font-weight:600}'
    + '.dn-bar{display:flex;gap:8px;flex-wrap:wrap;align-items:center;margin-bottom:10px}.dn-bar select,.dn-bar input{padding:6px 10px;border:1px solid #CBD5E1;border-radius:8px;font-size:13px;height:32px}'
    + '.dn-pill{display:inline-block;border-radius:10px;padding:1px 8px;font-size:11.5px;font-weight:700}'
    + '.dn-camp{background:#fff;border:1px solid #E2E8F0;border-radius:14px;padding:14px 16px;margin-bottom:10px}'
    + '.dn-prog{height:8px;border-radius:6px;background:#E2E8F0;overflow:hidden;margin:8px 0 4px}.dn-prog i{display:block;height:100%;background:linear-gradient(90deg,#1F6080,#6DBE45)}'
    + '.dn-ov{position:fixed;inset:0;background:rgba(15,23,42,.5);display:flex;align-items:center;justify-content:center;z-index:1000000;padding:16px}'
    + '.dn-dlg{background:#fff;border-radius:16px;width:100%;max-width:640px;max-height:90vh;overflow:auto;padding:20px 22px;box-shadow:0 24px 60px rgba(0,0,0,.25)}'
    + '.dn-dlg h3{margin:0 0 12px;font-size:17px}.dn-f{display:grid;grid-template-columns:1fr 1fr;gap:10px}.dn-f .full{grid-column:1/-1}'
    + '.dn-f label{display:block;font-size:12px;font-weight:700;color:#334155;margin-bottom:3px}'
    + '.dn-f input,.dn-f select,.dn-f textarea{width:100%;box-sizing:border-box;padding:7px 10px;border:1px solid #CBD5E1;border-radius:8px;font-size:13.5px;font-family:inherit}'
    + '.dn-f textarea{min-height:64px}.dn-actions{display:flex;justify-content:flex-end;gap:8px;margin-top:14px}'
    + '.dn-why{font-size:12px;color:#92400E;background:#FFFBEB;border:1px solid #FDE68A;border-radius:8px;padding:6px 9px;margin-top:4px}'
    + '@media (max-width:640px){.dn-f{grid-template-columns:minmax(0,1fr)}}'
    + '</style>';

  function dialog(title, bodyHtml, onSave, saveLabel) {
    var ov = document.createElement('div');
    ov.className = 'dn-ov';
    ov.innerHTML = '<div class="dn-dlg" role="dialog" aria-modal="true"><h3>' + esc(title) + '</h3>' + bodyHtml
      + '<div class="dn-actions"><button type="button" class="kt-btn" data-x>Cancel</button><button type="button" class="kt-btn kt-btn-primary" data-ok>' + esc(saveLabel || 'Save') + '</button></div></div>';
    document.body.appendChild(ov);
    var down = null;
    ov.addEventListener('mousedown', function (e) { down = e.target; });
    ov.addEventListener('click', function (e) { if (e.target === ov && down === ov) { ov.remove(); } });   // a drag that ends outside does not close it
    ov.querySelector('[data-x]').onclick = function () { ov.remove(); };
    ov.querySelector('[data-ok]').onclick = async function () {
      var b = this; b.disabled = true;
      try { var keep = await onSave(ov.querySelector('.dn-dlg')); if (keep !== false) { ov.remove(); } } catch (e) { toast((e && e.message) || 'Could not save', 'error'); }
      b.disabled = false;
    };
    var f = ov.querySelector('input,select,textarea'); if (f) { f.focus(); }
    return ov;
  }
  function vals(root) {
    var o = {};
    root.querySelectorAll('[data-k]').forEach(function (el) {
      var k = el.getAttribute('data-k');
      o[k] = el.type === 'checkbox' ? el.checked : el.value.trim();
    });
    return o;
  }
  function field(k, label, v, opts) {
    opts = opts || {};
    var cls = opts.full ? ' class="full"' : '';
    if (opts.select) {
      return '<div' + cls + '><label>' + esc(label) + '</label><select data-k="' + k + '">' + opts.select.map(function (o) {
        return '<option value="' + esc(o[0]) + '"' + (String(o[0]) === String(v == null ? '' : v) ? ' selected' : '') + '>' + esc(o[1]) + '</option>'; }).join('') + '</select></div>';
    }
    if (opts.area) { return '<div' + cls + '><label>' + esc(label) + '</label><textarea data-k="' + k + '" maxlength="' + (opts.max || 1000) + '">' + esc(v || '') + '</textarea></div>'; }
    if (opts.check) { return '<div' + cls + '><label style="display:flex;gap:8px;align-items:center;font-weight:600"><input type="checkbox" data-k="' + k + '"' + (v ? ' checked' : '') + ' style="width:auto"> ' + esc(label) + '</label></div>'; }
    return '<div' + cls + '><label>' + esc(label) + '</label><input data-k="' + k + '" type="' + (opts.type || 'text') + '"' + (opts.step ? ' step="' + opts.step + '"' : '')
      + ' value="' + esc(v == null ? '' : v) + '"' + (opts.ph ? ' placeholder="' + esc(opts.ph) + '"' : '') + '></div>';
  }

  /* ── render ── */
  async function render(main) {
    Api = KT.Api;
    main.setAttribute('data-kt-pretty', '1');
    main.innerHTML = CSS + '<div style="padding:14px 24px;max-width:1300px;">'
      + '<div class="kt-page-hero"><h2>💝 Donations &amp; fundraising</h2><p>Run campaigns with a shareable giving page, record gifts, and issue receipts.</p></div>'
      + '<div class="dn-tabs"><button data-tab="gifts">Gifts</button><button data-tab="campaigns">Campaigns</button><button data-tab="settings">Receipts &amp; settings</button></div>'
      + '<div id="dn-body">Loading…</div></div>';
    main.querySelector('.dn-tabs').addEventListener('click', function (e) {
      var b = e.target.closest('[data-tab]'); if (!b) { return; }
      tab = b.getAttribute('data-tab'); paint(main);
    });
    await paint(main);
  }

  async function paint(main) {
    main.querySelectorAll('.dn-tabs button').forEach(function (b) { b.classList.toggle('on', b.getAttribute('data-tab') === tab); });
    var body = main.querySelector('#dn-body');
    try {
      var c = await Api.get('/admin/donations/campaigns');
      cache.campaigns = c.campaigns || []; cache.settings = c.settings;
    } catch (e) { body.innerHTML = '<div class="kt-card" style="color:#B91C1C">Could not load: ' + esc(e.message || e) + '</div>'; return; }
    if (tab === 'settings') { return paintSettings(main, body); }
    if (!cache.settings.enabled) {
      body.innerHTML = '<div class="kt-card" style="background:#EFF6FF;border:1px solid #BFDBFE;color:#1E40AF;font-size:14px;">Donations are switched off for this agency. '
        + 'Turn them on in <a href="#donations" data-go-settings style="font-weight:800">Settings</a>, and add your charity details if you issue tax receipts.</div>';
      body.querySelector('[data-go-settings]').onclick = function (e) { e.preventDefault(); tab = 'settings'; paint(main); };
      return;
    }
    if (tab === 'campaigns') { return paintCampaigns(main, body); }
    return paintGifts(main, body);
  }

  /* ── gifts ── */
  async function paintGifts(main, body) {
    var q = Object.keys(filt).filter(function (k) { return filt[k]; }).map(function (k) { return k + '=' + encodeURIComponent(filt[k]); }).join('&');
    var r = await Api.get('/admin/donations' + (q ? '?' + q : ''));
    var gifts = cache.gifts = r.donations || [];
    var received = 0, pledged = 0, receipts = 0;
    gifts.forEach(function (g) {
      if (g.status === 'received') { received += +g.amount; }
      if (g.status === 'pledged') { pledged += +g.amount; }
      receipts += (g.receipts || []).filter(function (x) { return x.status === 'issued'; }).length;
    });
    var years = []; for (var y = new Date().getFullYear(); y >= 2024; y--) { years.push(y); }
    body.innerHTML = '<div class="dn-stats"><div class="dn-stat"><b>' + money(received) + '</b><span>Received</span></div>'
      + '<div class="dn-stat"><b>' + money(pledged) + '</b><span>Pledged, not yet received</span></div>'
      + '<div class="dn-stat"><b>' + gifts.length + '</b><span>Gifts</span></div><div class="dn-stat"><b>' + receipts + '</b><span>Receipts issued</span></div></div>'
      + '<div class="dn-bar"><select data-f="campaign_id"><option value="">All campaigns</option>' + cache.campaigns.map(function (c) { return '<option value="' + c.id + '"' + (String(c.id) === filt.campaign_id ? ' selected' : '') + '>' + esc(c.title) + '</option>'; }).join('') + '</select>'
      + '<select data-f="status"><option value="">Any status</option>' + [['pledged', 'Pledged'], ['received', 'Received'], ['cancelled', 'Cancelled']].map(function (s) { return '<option value="' + s[0] + '"' + (s[0] === filt.status ? ' selected' : '') + '>' + s[1] + '</option>'; }).join('') + '</select>'
      + '<select data-f="year">' + years.map(function (y) { return '<option' + (String(y) === filt.year ? ' selected' : '') + '>' + y + '</option>'; }).join('') + '</select>'
      + '<span style="flex:1"></span><button class="kt-btn" data-csv>⬇ Export CSV</button><button class="kt-btn kt-btn-primary" data-new>+ Record a gift</button></div>'
      + (gifts.length ? '<div class="kt-card" style="padding:0;overflow:auto"><table><thead><tr><th>Date</th><th>Donor</th><th>Campaign</th><th style="text-align:right">Amount</th><th>Method</th><th>Status</th><th>Receipt</th><th></th></tr></thead><tbody>'
        + gifts.map(function (g) {
          var rc = (g.receipts || []).filter(function (x) { return x.status === 'issued'; })[0];
          var st = { pledged: ['#FEF3C7', '#92400E'], received: ['#DCFCE7', '#166534'], cancelled: ['#F1F5F9', '#64748B'] }[g.status] || ['#F1F5F9', '#334155'];
          return '<tr><td>' + esc(day(g.received_on || g.created_at)) + '</td>'
            + '<td><strong>' + esc((g.donor_first_name || '') + ' ' + (g.donor_last_name || '')) + '</strong>' + (g.anonymous ? ' <span class="dn-pill" style="background:#F1F5F9;color:#475569">anonymous</span>' : '')
            + (g.donor_email ? '<br><span style="font-size:12px;color:#64748B">' + esc(g.donor_email) + '</span>' : '') + '</td>'
            + '<td>' + esc(g.campaign || '—') + '</td><td style="text-align:right;font-weight:700">' + money(g.amount) + '</td><td>' + esc(METHOD[g.method] || g.method) + '</td>'
            + '<td><span class="dn-pill" style="background:' + st[0] + ';color:' + st[1] + '">' + esc(g.status) + '</span></td>'
            + '<td>' + (rc ? '<span title="' + esc(rc.kind === 'official' ? 'Official receipt' : 'Acknowledgement') + '">' + (rc.kind === 'official' ? '🧾 ' : '💌 ') + esc(rc.serial) + '</span>' + (rc.emailed_at ? '<br><span style="font-size:11px;color:#16A34A">emailed</span>' : '') : '—') + '</td>'
            + '<td style="white-space:nowrap">' + actionsFor(g, rc) + '</td></tr>';
        }).join('') + '</tbody></table></div>'
        : '<div class="kt-card" style="text-align:center;color:#64748B;padding:30px">No gifts yet. Share a campaign’s giving page, or record a gift you received.</div>');

    body.querySelectorAll('[data-f]').forEach(function (s) { s.onchange = function () { filt[s.getAttribute('data-f')] = s.value; paintGifts(main, body); }; });
    body.querySelector('[data-new]').onclick = function () { giftDialog(null, main); };
    body.querySelector('[data-csv]').onclick = function () { download('/admin/donations/export.csv?year=' + filt.year, 'donations-' + filt.year + '.csv'); };
    body.querySelectorAll('[data-act]').forEach(function (b) { b.onclick = function () { act(b.getAttribute('data-act'), +b.getAttribute('data-id'), main); }; });
  }
  function actionsFor(g, rc) {
    var b = function (a, label, primary) { return '<button class="kt-btn' + (primary ? ' kt-btn-primary' : '') + '" style="padding:4px 9px;font-size:12px;margin:1px" data-act="' + a + '" data-id="' + (a.indexOf('r-') === 0 ? rc.id : g.id) + '">' + label + '</button>'; };
    var out = '';
    if (g.status === 'pledged') { out += b('receive', 'Mark received', true); }
    out += b('edit', 'Edit');
    if (g.status === 'received' && !rc) { out += b('issue', g.receipt_kind && g.receipt_kind.kind === 'official' ? 'Issue receipt' : 'Issue acknowledgement', true); }
    if (rc) { out += b('r-pdf', 'PDF') + (g.donor_email ? b('r-email', 'Email') : '') + b('r-void', 'Void'); }
    return out;
  }
  async function act(a, id, main) {
    var g = cache.gifts.find(function (x) { return +x.id === id; });
    if (a === 'edit') { return giftDialog(g, main); }
    if (a === 'receive') { return giftDialog(Object.assign({}, g, { status: 'received', received_on: new Date().toISOString().slice(0, 10) }), main, 'Mark gift as received'); }
    if (a === 'issue') {
      var k = g.receipt_kind || {};
      var ok = await KT.confirm({ title: k.kind === 'official' ? 'Issue an official tax receipt?' : 'Issue an acknowledgement?',
        description: k.kind === 'official' ? 'The receipt gets the next serial number and cannot be edited afterwards.'
          : 'This gift can’t get an official receipt because ' + (k.why || []).join('; ') + '. The donor will get a thank-you acknowledgement that says it is not a tax receipt.',
        okLabel: k.kind === 'official' ? 'Issue receipt' : 'Issue acknowledgement' });
      if (!ok) { return; }
      try { var r = await Api.post('/admin/donations/' + id + '/receipt', {}); toast((r.receipt.kind === 'official' ? 'Receipt ' : 'Acknowledgement ') + r.receipt.serial + ' issued', 'success'); }
      catch (e) { toast(e.message || 'Could not issue', 'error'); }
      return paint(main);
    }
    var rc = null;
    cache.gifts.forEach(function (x) { (x.receipts || []).forEach(function (r) { if (+r.id === id) { rc = r; g = x; } }); });
    if (a === 'r-pdf') { return download('/admin/donation-receipts/' + id + '/pdf', (rc.kind === 'official' ? 'Donation-Receipt-' : 'Donation-Acknowledgement-') + rc.serial + '.pdf'); }
    if (a === 'r-email') {
      var ok2 = await KT.confirm({ title: 'Email ' + rc.serial + ' to ' + g.donor_email + '?', description: 'The PDF is attached to a thank-you email from your agency.', okLabel: 'Send email' });
      if (!ok2) { return; }
      try { await Api.post('/admin/donation-receipts/' + id + '/email', {}); toast('Emailed to ' + g.donor_email, 'success'); } catch (e) { toast(e.message || 'Could not send', 'error'); }
      return paint(main);
    }
    if (a === 'r-void') {
      var reason = await KT.prompt({ title: 'Void receipt ' + rc.serial, fields: [{ key: 'reason', label: 'Reason (kept with the voided receipt)', placeholder: 'e.g. Wrong address' }], okLabel: 'Void receipt' });
      if (reason === null || !String(reason).trim()) { return; }
      var again = await KT.confirm({ title: 'Issue a replacement now?', description: 'A replacement gets a new serial and says which receipt it replaces. Correct the gift first if something on it was wrong.', okLabel: 'Issue replacement', cancelLabel: 'Just void it' });
      try { var v = await Api.post('/admin/donation-receipts/' + id + '/void', { reason: String(reason).trim(), replace: !!again }); toast(v.receipt ? 'Voided — replacement ' + v.receipt.serial + ' issued' : 'Receipt voided', 'success'); }
      catch (e) { toast(e.message || 'Could not void', 'error'); }
      return paint(main);
    }
  }
  function giftDialog(g, main, title) {
    g = g || { status: 'received', method: 'etransfer', received_on: new Date().toISOString().slice(0, 10), campaign_id: filt.campaign_id };
    var camps = [['', '— General donation —']].concat(cache.campaigns.map(function (c) { return [c.id, c.title]; }));
    var html = '<div class="dn-f">'
      + field('donor_first_name', 'First name *', g.donor_first_name) + field('donor_last_name', 'Last name', g.donor_last_name)
      + field('donor_email', 'Email', g.donor_email, { type: 'email' }) + field('donor_phone', 'Phone', g.donor_phone)
      + field('address_line1', 'Street address', g.address_line1, { full: true }) + field('city', 'City', g.city) + field('province', 'Province / state', g.province)
      + field('postal_code', 'Postal code', g.postal_code) + field('campaign_id', 'Campaign', g.campaign_id, { select: camps })
      + field('amount', 'Amount *', g.amount, { type: 'number', step: '0.01' }) + field('advantage_amount', 'Value of anything given back (advantage)', g.advantage_amount || 0, { type: 'number', step: '0.01' })
      + field('method', 'Method', g.method, { select: [['etransfer', 'e-Transfer'], ['cheque', 'Cheque'], ['cash', 'Cash'], ['card', 'Card'], ['other', 'Other']] })
      + field('status', 'Status', g.status, { select: [['received', 'Received'], ['pledged', 'Pledged'], ['cancelled', 'Cancelled']] })
      + field('received_on', 'Date received', (g.received_on || '').slice(0, 10), { type: 'date' }) + field('reference', 'Reference (cheque #, e-Transfer ID)', g.reference)
      + field('anonymous', 'Keep this donor anonymous in public totals and lists', g.anonymous, { check: true, full: true })
      + field('notes', 'Internal notes', g.notes, { area: true, full: true, max: 2000 })
      + '<div class="full" style="font-size:12px;color:#64748B">For an official tax receipt, enter the donor’s full name and mailing address.</div></div>';
    dialog(title || (g.id ? 'Edit gift' : 'Record a gift'), html, async function (root) {
      var v = vals(root);
      if (!v.donor_first_name || !(+v.amount > 0)) { toast('A first name and an amount are required.', 'error'); return false; }
      if (!v.campaign_id) { delete v.campaign_id; }
      if (v.status !== 'received') { v.received_on = v.received_on || null; }
      if (g.id) { await Api.post('/admin/donations/' + g.id, v); } else { await Api.post('/admin/donations', v); }
      toast('Gift saved', 'success');
      paint(main);
    });
  }

  /* ── campaigns ── */
  function paintCampaigns(main, body) {
    body.innerHTML = '<div class="dn-bar"><span style="font-size:13px;color:#64748B">Share a campaign’s link in announcements, emails or on social media. Families and supporters can pledge from it.</span><span style="flex:1"></span><button class="kt-btn kt-btn-primary" data-newc>+ New campaign</button></div>'
      + (cache.campaigns.length ? cache.campaigns.map(function (c) {
        var pct = c.goal_amount ? Math.min(100, Math.round(c.received / c.goal_amount * 100)) : null;
        var st = { active: ['#DCFCE7', '#166534'], draft: ['#F1F5F9', '#475569'], closed: ['#FEE2E2', '#991B1B'] }[c.status] || ['#F1F5F9', '#334155'];
        return '<div class="dn-camp"><div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap"><strong style="font-size:15px">' + esc(c.title) + '</strong>'
          + '<span class="dn-pill" style="background:' + st[0] + ';color:' + st[1] + '">' + esc(c.status) + '</span><span style="flex:1"></span>'
          + '<button class="kt-btn" style="padding:4px 10px;font-size:12px" data-copy="' + esc(c.public_url) + '">🔗 Copy link</button>'
          + '<a class="kt-btn" style="padding:4px 10px;font-size:12px" href="' + esc(c.public_url) + '" target="_blank" rel="noopener">Open page</a>'
          + '<button class="kt-btn" style="padding:4px 10px;font-size:12px" data-editc="' + c.id + '">Edit</button></div>'
          + (c.goal_amount ? '<div class="dn-prog"><i style="width:' + pct + '%"></i></div>' : '')
          + '<div style="font-size:13px;color:#475569"><strong>' + money(c.received) + '</strong> received' + (c.goal_amount ? ' of ' + money(c.goal_amount) + ' (' + pct + '%)' : '')
          + (c.pledged ? ' · ' + money(c.pledged) + ' pledged' : '') + ' · ' + c.gifts + ' gift' + (c.gifts === 1 ? '' : 's')
          + (c.ends_on ? ' · ends ' + esc(day(c.ends_on)) : '') + '</div></div>';
      }).join('') : '<div class="kt-card" style="text-align:center;color:#64748B;padding:30px">No campaigns yet. Create one for a playground, a field trip fund or your annual appeal.</div>');
    body.querySelector('[data-newc]').onclick = function () { campaignDialog(null, main); };
    body.querySelectorAll('[data-editc]').forEach(function (b) { b.onclick = function () { campaignDialog(cache.campaigns.find(function (c) { return +c.id === +b.getAttribute('data-editc'); }), main); }; });
    body.querySelectorAll('[data-copy]').forEach(function (b) {
      b.onclick = function () {
        var url = b.getAttribute('data-copy');
        (navigator.clipboard ? navigator.clipboard.writeText(url) : Promise.reject()).then(function () { toast('Link copied', 'success'); }, function () { window.prompt('Copy this link', url); });
      };
    });
  }
  function campaignDialog(c, main) {
    c = c || { status: 'active', show_progress: true, suggested_amounts: '25,50,100,250' };
    var html = '<div class="dn-f">' + field('title', 'Title *', c.title, { full: true, ph: 'e.g. New playground 2026' })
      + field('description', 'Tell supporters what it’s for', c.description, { area: true, full: true, max: 4000 })
      + field('goal_amount', 'Goal (optional)', c.goal_amount, { type: 'number', step: '1' }) + field('suggested_amounts', 'Suggested amounts', c.suggested_amounts, { ph: '25,50,100,250' })
      + field('starts_on', 'Starts', (c.starts_on || '').slice(0, 10), { type: 'date' }) + field('ends_on', 'Ends', (c.ends_on || '').slice(0, 10), { type: 'date' })
      + field('status', 'Status', c.status, { select: [['active', 'Active — taking pledges'], ['draft', 'Draft — page hidden'], ['closed', 'Closed']] })
      + field('show_progress', 'Show the amount raised on the page', c.show_progress == null ? true : !!+c.show_progress, { check: true })
      + field('thank_you_message', 'Thank-you message (sent with each pledge)', c.thank_you_message, { area: true, full: true }) + '</div>';
    dialog(c.id ? 'Edit campaign' : 'New campaign', html, async function (root) {
      var v = vals(root);
      if (!v.title) { toast('Give the campaign a title.', 'error'); return false; }
      ['goal_amount', 'starts_on', 'ends_on', 'suggested_amounts', 'description', 'thank_you_message'].forEach(function (k) { if (v[k] === '') { v[k] = null; } });
      await Api.post('/admin/donations/campaigns' + (c.id ? '/' + c.id : ''), v);
      toast('Campaign saved', 'success');
      paint(main);
    });
  }

  /* ── settings ── */
  async function paintSettings(main, body) {
    var r = await Api.get('/admin/donations/settings');
    var s = r.settings, ready = r.official_ready;
    body.innerHTML = '<div class="kt-card" style="max-width:760px"><div class="dn-f">'
      + field('enabled', 'Take donations (show campaigns and giving pages)', s.enabled, { check: true, full: true })
      + '<div class="full" style="font-weight:800;margin-top:6px">Receipts</div>'
      + '<div class="full" style="font-size:12.5px;color:#475569">A registered charity can issue official receipts for income tax purposes. Leave the registration number blank if you are not one: donors then get an acknowledgement instead.</div>'
      + field('charity_number', 'CRA charity registration number', s.charity_number, { ph: '123456789 RR 0001' }) + field('legal_name', 'Legal name (as registered)', s.legal_name)
      + field('receipt_address', 'Charity address (as registered)', s.receipt_address, { full: true }) + field('receipt_location', 'Place receipts are issued', s.receipt_location, { ph: 'e.g. Mono, ON' })
      + '<div></div>' + field('signatory_name', 'Authorised signatory', s.signatory_name) + field('signatory_title', 'Signatory title', s.signatory_title)
      + '<div class="full"><label>Signature on receipts</label><div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap">'
      + (s.signature ? '<img data-sigimg src="' + esc(s.signature) + '" style="height:46px;border:1px solid #E2E8F0;border-radius:6px;background:#fff;padding:2px">' : '<span data-sigimg style="font-size:12.5px;color:#64748B">None yet</span>')
      + '<button type="button" class="kt-btn" data-sig>✍️ ' + (s.signature ? 'Replace' : 'Add') + ' signature</button>' + (s.signature ? '<button type="button" class="kt-btn" data-sigclear>Remove</button>' : '') + '</div></div>'
      + (ready.ready ? '<div class="full" style="font-size:12.5px;color:#166534;font-weight:700">✓ Ready to issue official receipts.</div>'
        : (s.charity_number ? '<div class="full dn-why">Official receipts need: ' + esc(ready.missing.join(', ')) + '.</div>' : ''))
      + '<div class="full" style="font-weight:800;margin-top:6px">Giving page</div>'
      + field('giving_instructions', 'How donors complete a pledge (shown on the page and in the thank-you email)', s.giving_instructions, { area: true, full: true, max: 1500 })
      + field('notify_email', 'Email new pledges to (optional)', s.notify_email, { type: 'email', ph: 'office@youragency.ca', full: true })
      + '</div><div class="dn-actions"><button class="kt-btn kt-btn-primary" data-save>Save settings</button></div></div>';
    var sig = s.signature || '';
    var sb = body.querySelector('[data-sig]');
    if (sb) { sb.onclick = async function () { var d = await KT.signaturePad({ title: 'Signature for donation receipts', subtitle: 'This appears on official receipts above the signatory’s name.', okLabel: 'Use signature' }); if (d) { sig = d; toast('Signature captured — press Save settings', 'info'); } }; }
    var sc = body.querySelector('[data-sigclear]'); if (sc) { sc.onclick = function () { sig = ''; body.querySelector('[data-sigimg]').outerHTML = '<span data-sigimg style="font-size:12.5px;color:#64748B">Removed — press Save settings</span>'; }; }
    body.querySelector('[data-save]').onclick = async function () {
      var v = vals(body); v.signature = sig || null;
      try { await Api.post('/admin/donations/settings', v); toast('Donation settings saved', 'success'); paint(main); }
      catch (e) { toast(e.message || 'Could not save', 'error'); }
    };
  }

  function reg(n) {
    if (!window.KT || !KT.Shell || !KT.Shell.registerScreen) { if ((n || 0) < 100) { setTimeout(function () { reg((n || 0) + 1); }, 100); } return; }
    KT = window.KT;
    ['agency_admin', 'platform_admin'].forEach(function (r) { KT.Shell.registerScreen(r + ':donations', render); });
  }
  reg(0);
})(window);
