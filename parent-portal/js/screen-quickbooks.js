/* ═══════════════════════════════════════════════════════════════════
   KIDDIETRAC — Settings → QuickBooks (#quickbooks), rebuilt 2026-10-09
   The same three steps as Settings → Xero (screen-xero.js), so the two read alike:
     1. Your Intuit app — client ID + secret (developer.intuit.com), environment, and the
        redirect URI to paste there.
     2. Connect — Intuit's consent screen in a popup (the company is chosen there).
     3. Products, accounts & sync — the service item for tuition (and subsidy), where
        payments are deposited, tax codes, from which date, payments, automatic sync —
        then Sync now and the sync log.
   Replaces the old renderQuickbooks in screen-integrations-v22p72.js, whose invoice push
   could never succeed. Agency admins and platform admins.
   ═══════════════════════════════════════════════════════════════════ */
(function (window) {
  'use strict';

  function api() { var a = window.KT && window.KT.Api; if (!a) throw new Error('KT.Api not loaded'); return a; }
  function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c]; }); }
  function toast(msg, kind) { if (window.KT && window.KT.toast) window.KT.toast(msg, kind || 'info'); }
  function tz() { return (window.KT && KT.tz) ? KT.tz() : 'America/Toronto'; }
  function when(t) {
    if (!t) return '—';
    try {
      var d = (window.KT && KT.parseTs) ? KT.parseTs(t) : new Date(String(t).replace(' ', 'T') + 'Z');
      return new Intl.DateTimeFormat('en-CA', { timeZone: tz(), month: 'short', day: 'numeric', year: 'numeric', hour: 'numeric', minute: '2-digit' }).format(d);
    } catch (e) { return t; }
  }

  var ACC = '#2CA01C';
  function ensureCss() {
    if (document.getElementById('kt-qbo-css')) return;
    var s = document.createElement('style'); s.id = 'kt-qbo-css';
    s.textContent = ''
      + '.qb-wrap{padding:20px 24px;max-width:1100px;margin:0 auto;}'
      + '.qb-card{background:#fff;border:1px solid #E2E8F0;border-radius:12px;padding:16px 18px;margin-top:14px;}'
      + '.qb-card.off{opacity:.55;pointer-events:none;}'
      + '.qb-h{display:flex;align-items:center;gap:10px;margin:0 0 10px;font-size:15px;font-weight:800;color:#0F172A;}'
      + '.qb-n{width:24px;height:24px;border-radius:50%;background:' + ACC + ';color:#fff;font-size:12.5px;display:inline-flex;align-items:center;justify-content:center;flex:none;}'
      + '.qb-n.done{background:#16A34A;}'
      + '.qb-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(230px,1fr));gap:12px;}'
      + '.qb-l{display:block;font-size:11.5px;font-weight:800;color:#334155;text-transform:uppercase;letter-spacing:.4px;margin-bottom:4px;}'
      + '.qb-i{width:100%;box-sizing:border-box;height:32px;padding:0 10px;border:1px solid #CBD5E1;border-radius:8px;font-size:13px;background:#fff;}'
      + '.qb-hint{color:#64748B;font-size:12px;margin-top:4px;line-height:1.45;}'
      + '.qb-row{display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin-top:12px;}'
      + '.qb-b{height:30px;padding:0 12px;border-radius:8px;border:1px solid #CBD5E1;background:#fff;font-weight:700;font-size:13px;cursor:pointer;color:#0F172A;}'
      + '.qb-b.p{background:' + ACC + ';border-color:' + ACC + ';color:#fff;}'
      + '.qb-b.d{background:#FEF2F2;border-color:#FECACA;color:#B91C1C;}'
      + '.qb-b:disabled{opacity:.6;cursor:default;}'
      + '.qb-pill{display:inline-block;padding:2px 9px;border-radius:999px;font-size:12px;font-weight:700;}'
      + '.qb-code{font-family:ui-monospace,Consolas,monospace;font-size:12.5px;background:#F1F5F9;border:1px solid #E2E8F0;border-radius:6px;padding:5px 8px;word-break:break-all;}'
      + '.qb-tog{display:flex;gap:8px;align-items:flex-start;font-size:13px;color:#0F172A;margin-top:8px;}'
      + '.qb-log{width:100%;border-collapse:collapse;font-size:12.5px;}'
      + '.qb-log td,.qb-log th{padding:6px 8px;border-top:1px solid #EEF2F6;text-align:left;vertical-align:top;}'
      + '.qb-log th{background:#F8FAFC;color:#475569;font-size:11px;text-transform:uppercase;letter-spacing:.4px;border-top:0;}'
      + '.qb-stat{display:flex;gap:18px;flex-wrap:wrap;font-size:13px;color:#334155;margin-top:4px;}'
      + '.qb-stat b{font-size:16px;color:#0F172A;}';
    document.head.appendChild(s);
  }

  var TONE = { created: ['#ECFDF5', '#047857'], updated: ['#EFF6FF', '#1D4ED8'], unchanged: ['#F1F5F9', '#475569'], skipped: ['#FFFBEB', '#B45309'],
    failed: ['#FEF2F2', '#B91C1C'], voided: ['#F5F3FF', '#6D28D9'], deleted: ['#F5F3FF', '#6D28D9'] };
  function pill(text) { var t = TONE[text] || TONE.unchanged; return '<span class="qb-pill" data-kt-no-tone style="background:' + t[0] + ';color:' + t[1] + ';">' + esc(text) + '</span>'; }

  async function render(main) {
    ensureCss();
    main.innerHTML = '<div class="qb-wrap" style="color:#64748B;">Loading QuickBooks settings…</div>';
    var c;
    try { c = await api().get('/admin/qbo-config'); }
    catch (e) { main.innerHTML = '<div class="qb-wrap" style="color:#B91C1C;">' + esc(e.message || 'Could not load QuickBooks settings.') + '</div>'; return; }

    var step1 = c.configured, step2 = c.connected;
    var h = '<div class="qb-wrap">'
      + '<div class="kt-hero" style="background:linear-gradient(135deg,#0B2E14 0%,#1C6B12 55%,#2CA01C 100%);">'
      +   '<div class="kt-hero-greet">📒 SETTINGS</div><h1>QuickBooks Online</h1>'
      +   '<div class="kt-hero-sub">Send your KiddieTrac invoices, voids and payments to your QuickBooks company. '
      +   'Families become customers, invoices keep their KiddieTrac numbers, and syncing again updates them instead of making copies.</div></div>';

    h += '<div class="qb-card"><div class="qb-h"><span class="qb-n' + (step1 ? ' done' : '') + '">' + (step1 ? '✓' : '1') + '</span>Your Intuit app</div>'
      + '<div class="qb-hint" style="margin:-4px 0 10px;">In <a href="https://developer.intuit.com/app/developer/dashboard" target="_blank" rel="noopener">developer.intuit.com → Dashboard</a>, '
      + 'create an app with the <b>Accounting</b> scope, add the redirect URI below under <b>Keys &amp; credentials</b>, then copy the <b>Client ID</b> and <b>Client secret</b> here. '
      + 'Use the <b>Production</b> keys for your real company, or <b>Development</b> keys with a sandbox company to try it out.</div>'
      + '<div class="qb-l">Redirect URI</div><div style="display:flex;gap:8px;align-items:center;"><div class="qb-code" id="qb-ruri" style="flex:1;">' + esc(c.redirect_uri) + '</div>'
      + '<button type="button" class="qb-b" id="qb-copy">Copy</button></div>'
      + '<div class="qb-grid" style="margin-top:12px;">'
      +   '<div><label class="qb-l" for="qb-cid">Client ID</label><input class="qb-i" id="qb-cid" autocomplete="off" spellcheck="false" value="' + esc(c.client_id) + '"></div>'
      +   '<div><label class="qb-l" for="qb-sec">Client secret</label><input class="qb-i" id="qb-sec" type="password" autocomplete="new-password" placeholder="' + (c.has_secret ? '•••••••• saved — leave blank to keep' : 'Paste the secret') + '"></div>'
      +   '<div><label class="qb-l" for="qb-env">Environment</label><select class="qb-i" id="qb-env">'
      +     '<option value="production"' + (c.environment !== 'sandbox' ? ' selected' : '') + '>Production (your real company)</option>'
      +     '<option value="sandbox"' + (c.environment === 'sandbox' ? ' selected' : '') + '>Sandbox (testing)</option></select></div>'
      + '</div>'
      + (c.using_platform_fallback ? '<div class="qb-hint">No app of your own saved — the KiddieTrac platform app is being used.</div>' : '')
      + '<div class="qb-row"><button type="button" class="qb-b p" id="qb-save1">Save app details</button>'
      + (c.client_id || c.has_secret ? '<button type="button" class="qb-b d" id="qb-clear">Clear & disconnect</button>' : '')
      + '<span id="qb-msg1" class="qb-hint" style="margin:0;"></span></div></div>';

    h += '<div class="qb-card' + (step1 ? '' : ' off') + '"><div class="qb-h"><span class="qb-n' + (step2 ? ' done' : '') + '">' + (step2 ? '✓' : '2') + '</span>Connect your company</div>';
    if (step2) {
      h += '<div style="font-size:13.5px;">Connected to <b>' + esc(c.company_name || 'your QuickBooks company') + '</b>'
        + (c.environment === 'sandbox' ? ' <span class="qb-pill" data-kt-no-tone style="background:#FFFBEB;color:#B45309;">sandbox</span>' : '')
        + (c.connected_by ? ' by ' + esc(c.connected_by) : '') + (c.connected_at ? ' · ' + esc(when(c.connected_at)) : '') + '</div>'
        + '<div class="qb-row"><button type="button" class="qb-b" id="qb-connect">Reconnect</button><button type="button" class="qb-b d" id="qb-disc">Disconnect</button></div>';
    } else {
      h += '<div class="qb-hint" style="margin:0 0 4px;">Opens QuickBooks in a new window. Sign in, pick your company and press <b>Connect</b>.</div>'
        + '<div class="qb-row"><button type="button" class="qb-b p" id="qb-connect">Connect to QuickBooks →</button></div>';
      if (c.last_error) h += '<div class="qb-hint" style="color:#B91C1C;">' + esc(c.last_error) + '</div>';
    }
    h += '</div>';

    h += '<div class="qb-card' + (step2 ? '' : ' off') + '"><div class="qb-h"><span class="qb-n' + (step2 && c.income_item_id ? ' done' : '') + '">'
      + (step2 && c.income_item_id ? '✓' : '3') + '</span>Products, accounts & sync</div>'
      + (step2 ? '<div id="qb-opts" class="qb-hint">Reading your products and accounts from QuickBooks…</div>' : '<div class="qb-hint">Connect first.</div>')
      + '</div>';

    if (step2) {
      h += '<div class="qb-card"><div class="qb-h">🔄 Sync</div>'
        + '<div class="qb-stat"><span><b>' + c.pending + '</b> waiting to sync</span><span><b>' + c.linked.invoices + '</b> invoices in QuickBooks</span>'
        + '<span><b>' + c.linked.payments + '</b> payments</span><span><b>' + c.linked.customers + '</b> families as customers</span></div>'
        + '<div class="qb-hint">Last sync: ' + esc(when(c.last_sync_at)) + (c.auto_sync ? ' · automatic sync every 30 minutes is on' : ' · automatic sync is off') + '</div>'
        + (c.last_error ? '<div class="qb-hint" style="color:#B91C1C;">⚠️ ' + esc(c.last_error) + '</div>' : '')
        + '<div class="qb-row"><button type="button" class="qb-b p" id="qb-sync"' + (c.income_item_id ? '' : ' disabled title="Choose the product/service first"') + '>Sync now</button>'
        + '<span id="qb-sync-out" class="qb-hint" style="margin:0;"></span></div></div>'
        + '<div class="qb-card"><div class="qb-h">📜 Sync log</div><div id="qb-log" class="qb-hint">Loading…</div></div>';
    }
    h += '</div>';
    main.innerHTML = h;

    var $ = function (id) { return document.getElementById(id); };
    function busy(btn, on, label) { if (!btn) return; btn.disabled = on; if (label) btn.textContent = label; }

    $('qb-copy').onclick = function () {
      try { navigator.clipboard.writeText(c.redirect_uri); toast('Redirect URI copied', 'success'); }
      catch (e) { var r = document.createRange(); r.selectNodeContents($('qb-ruri')); var s = window.getSelection(); s.removeAllRanges(); s.addRange(r); }
    };
    $('qb-save1').onclick = async function () {
      var b = this; busy(b, true, 'Saving…');
      var body = { client_id: $('qb-cid').value.trim(), environment: $('qb-env').value };
      if ($('qb-sec').value.trim()) body.client_secret = $('qb-sec').value.trim();
      if (c.connected && (body.client_id !== c.client_id || body.environment !== c.environment || body.client_secret)
          && !(await KT.confirm({ title: 'Change the Intuit app?', description: 'The current connection belongs to the old app or environment, so QuickBooks will be disconnected and you will connect again.', okLabel: 'Change app' }))) { busy(b, false, 'Save app details'); return; }
      try { await api().patch('/admin/qbo-config', body); toast('QuickBooks app details saved', 'success'); render(main); }
      catch (e) { $('qb-msg1').style.color = '#B91C1C'; $('qb-msg1').textContent = e.message || 'Could not save.'; busy(b, false, 'Save app details'); }
    };
    if ($('qb-clear')) $('qb-clear').onclick = async function () {
      if (!(await KT.confirm({ title: 'Clear QuickBooks?', description: 'This disconnects QuickBooks and removes your app\'s client ID and secret. Invoices already in QuickBooks stay there.', okLabel: 'Clear & disconnect' }))) return;
      try { await api().delete('/admin/qbo-config'); toast('QuickBooks cleared', 'success'); render(main); } catch (e) { toast(e.message || 'Could not clear', 'error'); }
    };
    if ($('qb-connect')) $('qb-connect').onclick = function () {
      var b = this, label = b.textContent; busy(b, true, 'Opening QuickBooks…');
      var w = window.open('about:blank', 'kt-qbo', 'width=620,height=760');
      api().get('/qbo/connect').then(function (r) {
        if (w && !w.closed) w.location.href = r.authorize_url; else window.location.href = r.authorize_url;
        busy(b, true, 'Waiting for QuickBooks…');
        var done = false;
        function finish() { if (done) return; done = true; window.removeEventListener('message', onMsg); clearInterval(poll); render(main); }
        function onMsg(ev) { if (ev && ev.data && ev.data.kt === 'qbo') finish(); }
        window.addEventListener('message', onMsg);
        var poll = setInterval(function () { if (!w || w.closed) finish(); }, 800);
      }).catch(function (e) { if (w) w.close(); toast(e.message || 'Could not start the QuickBooks connection', 'error'); busy(b, false, label); });
    };
    if ($('qb-disc')) $('qb-disc').onclick = async function () {
      if (!(await KT.confirm({ title: 'Disconnect QuickBooks?', description: 'Invoices stop syncing. Everything already in QuickBooks stays there, and reconnecting the same company picks up where it left off.', okLabel: 'Disconnect' }))) return;
      try { await api().post('/qbo/disconnect', {}); toast('QuickBooks disconnected', 'success'); render(main); } catch (e) { toast(e.message || 'Could not disconnect', 'error'); }
    };

    if (!step2) return;

    api().get('/qbo/options').then(function (o) {
      var box = $('qb-opts'); if (!box) return;
      function sel(id, list, cur, blank) {
        var has = false;
        var opts = (blank ? '<option value="">' + esc(blank) + '</option>' : '') + list.map(function (a) {
          if (String(a.id) === String(cur || '')) has = true;
          return '<option value="' + esc(a.id) + '"' + (String(a.id) === String(cur || '') ? ' selected' : '') + '>' + esc(a.name) + '</option>';
        }).join('');
        if (cur && !has) opts += '<option value="' + esc(cur) + '" selected>#' + esc(cur) + ' (no longer in QuickBooks)</option>';
        return '<select class="qb-i" id="' + id + '">' + opts + '</select>';
      }
      var hasTax = (o.tax_codes || []).length > 0;
      box.className = '';
      box.innerHTML = (o.company && o.company.name ? '<div class="qb-hint" style="margin:-4px 0 10px;">' + esc(o.company.name) + (o.company.country ? ' · ' + esc(o.company.country) : '') + '</div>' : '')
        + '<div class="qb-grid">'
        + '<div><label class="qb-l" for="qb-item">Product/service for tuition *</label>' + sel('qb-item', o.items, c.income_item_id, 'Choose…')
        +   '<div class="qb-hint">Invoice lines use this item, so they land in its income account.</div></div>'
        + '<div><label class="qb-l" for="qb-sitem">Product/service for subsidies</label>' + sel('qb-sitem', o.items, c.subsidy_item_id, 'Same as tuition')
        +   '<div class="qb-hint">Subsidy (e.g. CWELCC) goes in as a negative line.</div></div>'
        + '<div><label class="qb-l" for="qb-dep">Deposit payments to</label>' + sel('qb-dep', o.deposit_accounts, c.deposit_account_id, 'Undeposited Funds (default)')
        +   '<div class="qb-hint">Card and bank payments taken in KiddieTrac are received here.</div></div>'
        + (hasTax
          ? '<div><label class="qb-l" for="qb-tex">Tax code for tax-free lines</label>' + sel('qb-tex', o.tax_codes, c.tax_code_exempt, 'Let QuickBooks decide')
            + '<div class="qb-hint">Canadian companies need one (usually "Exempt"). Child care is usually exempt.</div></div>'
            + '<div><label class="qb-l" for="qb-ttx">Tax code for taxed lines</label>' + sel('qb-ttx', o.tax_codes, c.tax_code_taxable, 'None — our invoices are tax-free')
            + '<div class="qb-hint">Only used for invoices that charge tax; such invoices wait until one is chosen.</div></div>'
          : '')
        + '<div><label class="qb-l" for="qb-from">Only invoices issued on or after</label><input class="qb-i" type="date" id="qb-from" value="' + esc(c.sync_from || '') + '">'
        +   '<div class="qb-hint">Leave blank to send every issued invoice. Set it to your QuickBooks start date so old invoices are not duplicated.</div></div>'
        + '</div>'
        + '<label class="qb-tog"><input type="checkbox" id="qb-pay"' + (c.sync_payments ? ' checked' : '') + '> <span>Record payments and refunds against the invoices in QuickBooks</span></label>'
        + '<label class="qb-tog"><input type="checkbox" id="qb-auto"' + (c.auto_sync ? ' checked' : '') + '> <span>Sync automatically every 30 minutes'
        +   '<span class="qb-hint" style="display:block;margin:0;">Off: nothing goes to QuickBooks until you press Sync now.</span></span></label>'
        + '<div class="qb-row"><button type="button" class="qb-b p" id="qb-save3">Save products & sync settings</button><span id="qb-msg3" class="qb-hint" style="margin:0;"></span></div>';
      $('qb-save3').onclick = async function () {
        var b = this;
        if (!$('qb-item').value) { $('qb-msg3').style.color = '#B91C1C'; $('qb-msg3').textContent = 'Choose the product/service for tuition.'; return; }
        busy(b, true, 'Saving…');
        var body = { income_item_id: $('qb-item').value, subsidy_item_id: $('qb-sitem').value, deposit_account_id: $('qb-dep').value,
          sync_from: $('qb-from').value || null, sync_payments: $('qb-pay').checked, auto_sync: $('qb-auto').checked };
        if ($('qb-tex')) { body.tax_code_exempt = $('qb-tex').value; body.tax_code_taxable = $('qb-ttx').value; }
        try { await api().patch('/admin/qbo-config', body); toast('QuickBooks settings saved', 'success'); render(main); }
        catch (e) { $('qb-msg3').style.color = '#B91C1C'; $('qb-msg3').textContent = e.message || 'Could not save.'; busy(b, false, 'Save products & sync settings'); }
      };
    }).catch(function (e) {
      var box = $('qb-opts'); if (box) { box.style.color = '#B91C1C'; box.textContent = (e && e.message) || 'Could not read your products and accounts from QuickBooks.'; }
    });

    $('qb-sync').onclick = async function () {
      var b = this, out = $('qb-sync-out'); busy(b, true, 'Syncing…'); out.style.color = '#64748B'; out.textContent = 'Sending to QuickBooks…';
      try {
        var r = await api().post('/qbo/sync', {});
        var parts = [];
        ['created', 'updated', 'voided', 'unchanged', 'skipped', 'failed'].forEach(function (k) { if (r[k]) parts.push(r[k] + ' ' + k); });
        out.style.color = r.failed ? '#B91C1C' : '#047857';
        out.textContent = (parts.length ? parts.join(', ') : 'Nothing to send') + (r.remaining ? ' — ' + r.remaining + ' more waiting, press Sync now again' : '') + (r.error ? ' — ' + r.error : '');
        loadLog();
      } catch (e) { out.style.color = '#B91C1C'; out.textContent = e.message || 'Sync failed.'; }
      busy(b, false, 'Sync now');
    };

    function loadLog() {
      api().get('/qbo/log', { limit: 60 }).then(function (d) {
        var rows = d.log || [], box = $('qb-log'); if (!box) return;
        if (!rows.length) { box.textContent = 'Nothing synced yet.'; return; }
        box.className = '';
        box.innerHTML = '<div style="overflow-x:auto;"><table class="qb-log"><thead><tr><th>When</th><th>What</th><th>Result</th><th>Details</th><th>By</th></tr></thead><tbody>'
          + rows.map(function (r) {
              var what = r.kind === 'invoice' ? 'Invoice ' + (r.invoice_number || ('#' + r.local_id)) : r.kind === 'payment' ? 'Payment #' + r.local_id : r.kind === 'customer' ? 'Family #' + r.local_id : r.kind;
              return '<tr><td style="white-space:nowrap;">' + esc(when(r.created_at)) + '</td><td style="white-space:nowrap;">' + esc(what) + '</td><td>' + pill(r.action)
                + '</td><td style="color:#334155;">' + esc(r.message) + '</td><td style="white-space:nowrap;color:#64748B;">' + esc(r.by) + '</td></tr>';
            }).join('') + '</tbody></table></div>';
      }).catch(function () { var box = $('qb-log'); if (box) box.textContent = 'Could not load the sync log.'; });
    }
    loadLog();
  }

  function reg() {
    if (!(window.KT && window.KT.Shell && window.KT.Shell.registerScreen)) { setTimeout(reg, 200); return; }
    ['agency_admin', 'platform_admin'].forEach(function (r) { window.KT.Shell.registerScreen(r + ':quickbooks', render); });
  }
  reg();
  window.KT = window.KT || {};
  window.KT.renderQuickbooks = render;
})(window);
