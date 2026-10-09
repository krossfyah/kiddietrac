/* ═══════════════════════════════════════════════════════════════════
   KIDDIETRAC — Settings → Xero (#xero), 2026-10-09
   Anthony: "build out xero integration to kiddietrac and add configuration front end to
   settings like you did for quickbooks".

   Three steps on one page, each unlocking the next:
     1. Your Xero app — client ID + secret from developer.xero.com, and the redirect URI
        to paste there.
     2. Connect — Xero's consent screen in a popup; pick the organisation if several.
     3. Accounts & sync — sales / subsidy / bank accounts and tax rate read live from the
        organisation, how invoices arrive (approved or draft), from which date, payments,
        automatic sync — then Sync now and the sync log.
   Agency admins and platform admins (the API enforces the same).
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

  var CSS = 'kt-xero-css';
  function ensureCss() {
    if (document.getElementById(CSS)) return;
    var s = document.createElement('style'); s.id = CSS;
    s.textContent = ''
      + '.xr-wrap{padding:20px 24px;max-width:1100px;margin:0 auto;}'
      + '.xr-card{background:#fff;border:1px solid #E2E8F0;border-radius:12px;padding:16px 18px;margin-top:14px;}'
      + '.xr-card.off{opacity:.55;pointer-events:none;}'
      + '.xr-h{display:flex;align-items:center;gap:10px;margin:0 0 10px;font-size:15px;font-weight:800;color:#0F172A;}'
      + '.xr-n{width:24px;height:24px;border-radius:50%;background:#13B5EA;color:#fff;font-size:12.5px;display:inline-flex;align-items:center;justify-content:center;flex:none;}'
      + '.xr-n.done{background:#16A34A;}'
      + '.xr-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(230px,1fr));gap:12px;}'
      + '.xr-l{display:block;font-size:11.5px;font-weight:800;color:#334155;text-transform:uppercase;letter-spacing:.4px;margin-bottom:4px;}'
      + '.xr-i{width:100%;box-sizing:border-box;height:32px;padding:0 10px;border:1px solid #CBD5E1;border-radius:8px;font-size:13px;background:#fff;}'
      + '.xr-hint{color:#64748B;font-size:12px;margin-top:4px;line-height:1.45;}'
      + '.xr-row{display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin-top:12px;}'
      + '.xr-b{height:30px;padding:0 12px;border-radius:8px;border:1px solid #CBD5E1;background:#fff;font-weight:700;font-size:13px;cursor:pointer;color:#0F172A;}'
      + '.xr-b.p{background:#13B5EA;border-color:#13B5EA;color:#fff;}'
      + '.xr-b.d{background:#FEF2F2;border-color:#FECACA;color:#B91C1C;}'
      + '.xr-b:disabled{opacity:.6;cursor:default;}'
      + '.xr-pill{display:inline-block;padding:2px 9px;border-radius:999px;font-size:12px;font-weight:700;}'
      + '.xr-code{font-family:ui-monospace,Consolas,monospace;font-size:12.5px;background:#F1F5F9;border:1px solid #E2E8F0;border-radius:6px;padding:5px 8px;word-break:break-all;}'
      + '.xr-tog{display:flex;gap:8px;align-items:flex-start;font-size:13px;color:#0F172A;margin-top:8px;}'
      + '.xr-log{width:100%;border-collapse:collapse;font-size:12.5px;}'
      + '.xr-log td,.xr-log th{padding:6px 8px;border-top:1px solid #EEF2F6;text-align:left;vertical-align:top;}'
      + '.xr-log th{background:#F8FAFC;color:#475569;font-size:11px;text-transform:uppercase;letter-spacing:.4px;border-top:0;}'
      + '.xr-stat{display:flex;gap:18px;flex-wrap:wrap;font-size:13px;color:#334155;margin-top:4px;}'
      + '.xr-stat b{font-size:16px;color:#0F172A;}';
    document.head.appendChild(s);
  }

  var TONE = { created: ['#ECFDF5', '#047857'], updated: ['#EFF6FF', '#1D4ED8'], unchanged: ['#F1F5F9', '#475569'], skipped: ['#FFFBEB', '#B45309'],
    failed: ['#FEF2F2', '#B91C1C'], voided: ['#F5F3FF', '#6D28D9'], deleted: ['#F5F3FF', '#6D28D9'] };
  function pill(text, tone) { var t = TONE[tone] || TONE.unchanged; return '<span class="xr-pill" data-kt-no-tone style="background:' + t[0] + ';color:' + t[1] + ';">' + esc(text) + '</span>'; }

  async function render(main) {
    ensureCss();
    main.innerHTML = '<div class="xr-wrap" style="color:#64748B;">Loading Xero settings…</div>';
    var c;
    try { c = await api().get('/admin/xero-config'); }
    catch (e) { main.innerHTML = '<div class="xr-wrap" style="color:#B91C1C;">' + esc(e.message || 'Could not load Xero settings.') + '</div>'; return; }

    var step1 = c.configured, step2 = c.connected, needsOrg = c.authorised && !c.tenant_id && (c.tenants || []).length > 1;
    var h = '<div class="xr-wrap">'
      + '<div class="kt-hero" style="background:linear-gradient(135deg,#06324A 0%,#0B6E99 55%,#13B5EA 100%);">'
      +   '<div class="kt-hero-greet">📗 SETTINGS</div><h1>Xero</h1>'
      +   '<div class="kt-hero-sub">Send your KiddieTrac invoices, voids and payments to your Xero organisation. '
      +   'Families become Xero contacts, invoices keep their KiddieTrac numbers, and syncing again updates them instead of making copies.</div></div>';

    /* ── 1. app credentials ── */
    h += '<div class="xr-card"><div class="xr-h"><span class="xr-n' + (step1 ? ' done' : '') + '">' + (step1 ? '✓' : '1') + '</span>Your Xero app</div>'
      + '<div class="xr-hint" style="margin:-4px 0 10px;">In <a href="https://developer.xero.com/app/manage" target="_blank" rel="noopener">developer.xero.com → My Apps</a>, '
      + 'create a <b>Web app</b>, paste the redirect URI below into it, then copy its <b>Client ID</b> and generate a <b>Client secret</b> here.</div>'
      + '<div class="xr-l">Redirect URI</div><div style="display:flex;gap:8px;align-items:center;"><div class="xr-code" id="xr-ruri" style="flex:1;">' + esc(c.redirect_uri) + '</div>'
      + '<button type="button" class="xr-b" id="xr-copy">Copy</button></div>'
      + '<div class="xr-grid" style="margin-top:12px;">'
      +   '<div><label class="xr-l" for="xr-cid">Client ID</label><input class="xr-i" id="xr-cid" autocomplete="off" spellcheck="false" value="' + esc(c.client_id) + '"></div>'
      +   '<div><label class="xr-l" for="xr-sec">Client secret</label><input class="xr-i" id="xr-sec" type="password" autocomplete="new-password" placeholder="' + (c.has_secret ? '•••••••• saved — leave blank to keep' : 'Paste the secret') + '"></div>'
      +   '<div><label class="xr-l" for="xr-scope">Xero app type</label><select class="xr-i" id="xr-scope">'
      +     '<option value="granular"' + (c.scope_mode !== 'classic' ? ' selected' : '') + '>Created March 2026 or later</option>'
      +     '<option value="classic"' + (c.scope_mode === 'classic' ? ' selected' : '') + '>Created before March 2026</option></select>'
      +     '<div class="xr-hint">Xero gives newer apps different permissions. If Xero says "invalid scope", switch this and connect again.</div></div>'
      + '</div>'
      + '<div class="xr-row"><button type="button" class="xr-b p" id="xr-save1">Save app details</button>'
      + (c.client_id || c.has_secret ? '<button type="button" class="xr-b d" id="xr-clear">Clear & disconnect</button>' : '')
      + '<span id="xr-msg1" class="xr-hint" style="margin:0;"></span></div></div>';

    /* ── 2. connect ── */
    h += '<div class="xr-card' + (step1 ? '' : ' off') + '"><div class="xr-h"><span class="xr-n' + (step2 ? ' done' : '') + '">' + (step2 ? '✓' : '2') + '</span>Connect your organisation</div>';
    if (step2) {
      h += '<div style="font-size:13.5px;">Connected to <b>' + esc(c.tenant_name) + '</b>'
        + (c.connected_by ? ' by ' + esc(c.connected_by) : '') + (c.connected_at ? ' · ' + esc(when(c.connected_at)) : '') + '</div>'
        + '<div class="xr-row"><button type="button" class="xr-b" id="xr-connect">Reconnect</button>'
        + ((c.tenants || []).length > 1 ? '<button type="button" class="xr-b" id="xr-switch">Switch organisation</button>' : '')
        + '<button type="button" class="xr-b d" id="xr-disc">Disconnect</button></div>';
    } else if (needsOrg) {
      h += '<div style="font-size:13.5px;margin-bottom:8px;">You gave KiddieTrac access to several organisations. Choose the one to send invoices to:</div>'
        + '<div class="xr-row" style="margin-top:0;"><select class="xr-i" id="xr-org" style="max-width:360px;">'
        + (c.tenants || []).map(function (t) { return '<option value="' + esc(t.id) + '">' + esc(t.name) + '</option>'; }).join('')
        + '</select><button type="button" class="xr-b p" id="xr-org-save">Use this organisation</button></div>';
    } else {
      h += '<div class="xr-hint" style="margin:0 0 4px;">Opens Xero in a new window. Sign in, choose your organisation and press <b>Allow access</b>.</div>'
        + '<div class="xr-row"><button type="button" class="xr-b p" id="xr-connect">Connect to Xero →</button></div>';
    }
    if (c.last_error && !step2) h += '<div class="xr-hint" style="color:#B91C1C;">' + esc(c.last_error) + '</div>';
    h += '</div>';

    /* ── 3. accounts & sync ── */
    h += '<div class="xr-card' + (step2 ? '' : ' off') + '" id="xr-map"><div class="xr-h"><span class="xr-n' + (step2 && c.revenue_account_code ? ' done' : '') + '">'
      + (step2 && c.revenue_account_code ? '✓' : '3') + '</span>Accounts & sync</div>'
      + (step2 ? '<div id="xr-opts" class="xr-hint">Reading accounts from Xero…</div>' : '<div class="xr-hint">Connect first.</div>')
      + '</div>';

    if (step2) {
      h += '<div class="xr-card"><div class="xr-h">🔄 Sync</div>'
        + '<div class="xr-stat"><span><b>' + c.pending + '</b> waiting to sync</span><span><b>' + c.linked.invoices + '</b> invoices in Xero</span>'
        + '<span><b>' + c.linked.payments + '</b> payments</span><span><b>' + c.linked.contacts + '</b> families as contacts</span></div>'
        + '<div class="xr-hint">Last sync: ' + esc(when(c.last_sync_at)) + (c.auto_sync ? ' · automatic sync every 30 minutes is on' : ' · automatic sync is off') + '</div>'
        + (c.last_error ? '<div class="xr-hint" style="color:#B91C1C;">⚠️ ' + esc(c.last_error) + '</div>' : '')
        + '<div class="xr-row"><button type="button" class="xr-b p" id="xr-sync"' + (c.revenue_account_code ? '' : ' disabled title="Choose the sales account first"') + '>Sync now</button>'
        + '<span id="xr-sync-out" class="xr-hint" style="margin:0;"></span></div></div>'
        + '<div class="xr-card"><div class="xr-h">📜 Sync log</div><div id="xr-log" class="xr-hint">Loading…</div></div>';
    }
    h += '</div>';
    main.innerHTML = h;

    var $ = function (id) { return document.getElementById(id); };
    function busy(btn, on, label) { if (!btn) return; btn.disabled = on; if (label) btn.textContent = label; }

    $('xr-copy').onclick = function () {
      try { navigator.clipboard.writeText(c.redirect_uri); toast('Redirect URI copied', 'success'); }
      catch (e) { var r = document.createRange(); r.selectNodeContents($('xr-ruri')); var s = window.getSelection(); s.removeAllRanges(); s.addRange(r); }
    };

    $('xr-save1').onclick = async function () {
      var b = this; busy(b, true, 'Saving…');
      var body = { client_id: $('xr-cid').value.trim(), scope_mode: $('xr-scope').value };
      if ($('xr-sec').value.trim()) body.client_secret = $('xr-sec').value.trim();
      if (c.connected && body.client_id !== c.client_id && !(await KT.confirm({ title: 'Change the Xero app?', description: 'A different app cannot use the current connection, so Xero will be disconnected and you will connect again.', okLabel: 'Change app' }))) { busy(b, false, 'Save app details'); return; }
      try { await api().patch('/admin/xero-config', body); toast('Xero app details saved', 'success'); render(main); }
      catch (e) { $('xr-msg1').style.color = '#B91C1C'; $('xr-msg1').textContent = e.message || 'Could not save.'; busy(b, false, 'Save app details'); }
    };
    if ($('xr-clear')) $('xr-clear').onclick = async function () {
      if (!(await KT.confirm({ title: 'Clear Xero?', description: 'This disconnects Xero and removes your app\'s client ID and secret. Invoices already in Xero stay there.', okLabel: 'Clear & disconnect' }))) return;
      try { await api().delete('/admin/xero-config'); toast('Xero cleared', 'success'); render(main); } catch (e) { toast(e.message || 'Could not clear', 'error'); }
    };

    function startConnect() {
      var b = $('xr-connect'); var label = b.textContent; busy(b, true, 'Opening Xero…');
      // Open the window synchronously (popup blockers), then point it at Xero.
      var w = window.open('about:blank', 'kt-xero', 'width=620,height=760');
      api().get('/xero/connect').then(function (r) {
        if (w && !w.closed) w.location.href = r.authorize_url; else window.location.href = r.authorize_url;
        busy(b, true, 'Waiting for Xero…');
        var done = false;
        function finish() { if (done) return; done = true; window.removeEventListener('message', onMsg); clearInterval(poll); render(main); }
        function onMsg(ev) { if (ev && ev.data && ev.data.kt === 'xero') finish(); }
        window.addEventListener('message', onMsg);
        var poll = setInterval(function () { if (!w || w.closed) finish(); }, 800);
      }).catch(function (e) { if (w) w.close(); toast(e.message || 'Could not start the Xero connection', 'error'); busy(b, false, label); });
    }
    if ($('xr-connect')) $('xr-connect').onclick = startConnect;
    if ($('xr-org-save')) $('xr-org-save').onclick = async function () {
      try { await api().post('/xero/tenant', { tenant_id: $('xr-org').value }); toast('Organisation chosen', 'success'); render(main); } catch (e) { toast(e.message || 'Could not save', 'error'); }
    };
    if ($('xr-switch')) $('xr-switch').onclick = function () {
      // An inline picker: the organisations this connection was granted, other than the current one.
      var row = this.parentNode;
      if (document.getElementById('xr-org2')) return;
      var names = (c.tenants || []).filter(function (t) { return t.id !== c.tenant_id; });
      var wrap = document.createElement('div'); wrap.className = 'xr-row';
      wrap.innerHTML = '<select class="xr-i" id="xr-org2" style="max-width:360px;">' + names.map(function (t) { return '<option value="' + esc(t.id) + '">' + esc(t.name) + '</option>'; }).join('')
        + '</select><button type="button" class="xr-b p" id="xr-org2-save">Switch</button>'
        + '<span class="xr-hint" style="margin:0;">Invoices already sent stay in the current organisation.</span>';
      row.parentNode.insertBefore(wrap, row.nextSibling);
      document.getElementById('xr-org2-save').onclick = async function () {
        try { await api().post('/xero/tenant', { tenant_id: document.getElementById('xr-org2').value }); toast('Organisation switched', 'success'); render(main); }
        catch (e) { toast(e.message || 'Could not switch', 'error'); }
      };
    };
    if ($('xr-disc')) $('xr-disc').onclick = async function () {
      if (!(await KT.confirm({ title: 'Disconnect Xero?', description: 'Invoices stop syncing. Everything already in Xero stays there, and reconnecting picks up where it left off.', okLabel: 'Disconnect' }))) return;
      try { await api().post('/xero/disconnect', {}); toast('Xero disconnected', 'success'); render(main); } catch (e) { toast(e.message || 'Could not disconnect', 'error'); }
    };

    if (!step2) return;

    /* accounts: read live from the organisation */
    api().get('/xero/options').then(function (o) {
      var box = $('xr-opts'); if (!box) return;
      function sel(id, list, cur, blank, fmt) {
        var has = false;
        var opts = (blank ? '<option value="">' + esc(blank) + '</option>' : '') + list.map(function (a) {
          var v = fmt.v(a); if (String(v) === String(cur || '')) has = true;
          return '<option value="' + esc(v) + '"' + (String(v) === String(cur || '') ? ' selected' : '') + '>' + esc(fmt.t(a)) + '</option>';
        }).join('');
        if (cur && !has) opts += '<option value="' + esc(cur) + '" selected>' + esc(cur) + ' (no longer in Xero)</option>';
        return '<select class="xr-i" id="' + id + '">' + opts + '</select>';
      }
      var acct = { v: function (a) { return a.code; }, t: function (a) { return a.code + ' — ' + a.name; } };
      var rate = { v: function (t) { return t.type; }, t: function (t) { return t.name + (t.rate != null ? ' (' + t.rate + '%)' : ''); } };
      box.className = '';
      box.innerHTML = (o.organisation && o.organisation.name ? '<div class="xr-hint" style="margin:-4px 0 10px;">' + esc(o.organisation.name)
          + (o.organisation.currency ? ' · ' + esc(o.organisation.currency) : '') + '</div>' : '')
        + '<div class="xr-grid">'
        + '<div><label class="xr-l" for="xr-rev">Sales account for tuition *</label>' + sel('xr-rev', o.revenue, c.revenue_account_code, 'Choose…', acct)
        +   '<div class="xr-hint">Where invoice lines are booked.</div></div>'
        + '<div><label class="xr-l" for="xr-sub">Account for subsidies</label>' + sel('xr-sub', o.revenue, c.subsidy_account_code, 'Same as tuition', acct)
        +   '<div class="xr-hint">Subsidy (e.g. CWELCC) goes in as a negative line.</div></div>'
        + '<div><label class="xr-l" for="xr-bank">Bank account for payments</label>' + sel('xr-bank', o.bank, c.payment_account_code, 'Don\'t record payments yet', acct)
        +   '<div class="xr-hint">Card and bank payments taken in KiddieTrac are recorded here.</div></div>'
        + '<div><label class="xr-l" for="xr-tax">Tax rate (only for invoices that charge tax)</label>' + sel('xr-tax', o.tax_rates, c.tax_type, 'None — our invoices are tax-exempt', rate)
        +   '<div class="xr-hint">Child care is usually exempt. An invoice that charges tax waits until a rate is chosen.</div></div>'
        + '<div><label class="xr-l" for="xr-st">Invoices arrive in Xero as</label><select class="xr-i" id="xr-st">'
        +   '<option value="AUTHORISED"' + (c.invoice_status !== 'DRAFT' ? ' selected' : '') + '>Approved (ready for payments)</option>'
        +   '<option value="DRAFT"' + (c.invoice_status === 'DRAFT' ? ' selected' : '') + '>Draft (you approve them in Xero)</option></select></div>'
        + '<div><label class="xr-l" for="xr-from">Only invoices issued on or after</label><input class="xr-i" type="date" id="xr-from" value="' + esc(c.sync_from || '') + '">'
        +   '<div class="xr-hint">Leave blank to send every issued invoice. Set it to your Xero start date so old invoices are not duplicated.</div></div>'
        + '</div>'
        + '<label class="xr-tog"><input type="checkbox" id="xr-pay"' + (c.sync_payments ? ' checked' : '') + '> <span>Record payments and refunds against the invoices in Xero</span></label>'
        + '<label class="xr-tog"><input type="checkbox" id="xr-auto"' + (c.auto_sync ? ' checked' : '') + '> <span>Sync automatically every 30 minutes'
        +   '<span class="xr-hint" style="display:block;margin:0;">Off: nothing goes to Xero until you press Sync now.</span></span></label>'
        + '<div class="xr-row"><button type="button" class="xr-b p" id="xr-save3">Save accounts & sync settings</button><span id="xr-msg3" class="xr-hint" style="margin:0;"></span></div>';
      $('xr-save3').onclick = async function () {
        var b = this;
        if (!$('xr-rev').value) { $('xr-msg3').style.color = '#B91C1C'; $('xr-msg3').textContent = 'Choose the sales account for tuition.'; return; }
        busy(b, true, 'Saving…');
        try {
          await api().patch('/admin/xero-config', {
            revenue_account_code: $('xr-rev').value, subsidy_account_code: $('xr-sub').value, payment_account_code: $('xr-bank').value,
            tax_type: $('xr-tax').value, invoice_status: $('xr-st').value, sync_from: $('xr-from').value || null,
            sync_payments: $('xr-pay').checked, auto_sync: $('xr-auto').checked,
          });
          toast('Xero settings saved', 'success'); render(main);
        } catch (e) { $('xr-msg3').style.color = '#B91C1C'; $('xr-msg3').textContent = e.message || 'Could not save.'; busy(b, false, 'Save accounts & sync settings'); }
      };
    }).catch(function (e) {
      var box = $('xr-opts'); if (box) { box.style.color = '#B91C1C'; box.textContent = (e && e.message) || 'Could not read your accounts from Xero.'; }
    });

    $('xr-sync').onclick = async function () {
      var b = this, out = $('xr-sync-out'); busy(b, true, 'Syncing…'); out.style.color = '#64748B'; out.textContent = 'Sending to Xero…';
      try {
        var r = await api().post('/xero/sync', {});
        var parts = [];
        ['created', 'updated', 'voided', 'unchanged', 'skipped', 'failed'].forEach(function (k) { if (r[k]) parts.push(r[k] + ' ' + k); });
        out.style.color = r.failed ? '#B91C1C' : '#047857';
        out.textContent = (parts.length ? parts.join(', ') : 'Nothing to send') + (r.remaining ? ' — ' + r.remaining + ' more waiting, press Sync now again' : '') + (r.error ? ' — ' + r.error : '');
        loadLog();
      } catch (e) { out.style.color = '#B91C1C'; out.textContent = e.message || 'Sync failed.'; }
      busy(b, false, 'Sync now');
    };

    function loadLog() {
      api().get('/xero/log', { limit: 60 }).then(function (d) {
        var rows = d.log || [], box = $('xr-log'); if (!box) return;
        if (!rows.length) { box.textContent = 'Nothing synced yet.'; return; }
        box.className = '';
        box.innerHTML = '<div style="overflow-x:auto;"><table class="xr-log"><thead><tr><th>When</th><th>What</th><th>Result</th><th>Details</th><th>By</th></tr></thead><tbody>'
          + rows.map(function (r) {
              var what = r.kind === 'invoice' ? 'Invoice ' + (r.invoice_number || ('#' + r.local_id)) : r.kind === 'payment' ? 'Payment #' + r.local_id : r.kind === 'contact' ? 'Family #' + r.local_id : r.kind;
              return '<tr><td style="white-space:nowrap;">' + esc(when(r.created_at)) + '</td><td style="white-space:nowrap;">' + esc(what) + '</td><td>' + pill(r.action, r.action)
                + '</td><td style="color:#334155;">' + esc(r.message) + '</td><td style="white-space:nowrap;color:#64748B;">' + esc(r.by) + '</td></tr>';
            }).join('') + '</tbody></table></div>';
      }).catch(function () { var box = $('xr-log'); if (box) box.textContent = 'Could not load the sync log.'; });
    }
    loadLog();
  }

  function reg() {
    if (!(window.KT && window.KT.Shell && window.KT.Shell.registerScreen)) { setTimeout(reg, 200); return; }
    ['agency_admin', 'platform_admin'].forEach(function (r) { window.KT.Shell.registerScreen(r + ':xero', render); });
  }
  reg();
  window.KT = window.KT || {};
  window.KT.renderXero = render;
})(window);
