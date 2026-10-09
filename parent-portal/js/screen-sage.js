/* ═══════════════════════════════════════════════════════════════════
   KIDDIETRAC — Settings → Sage (#sage), 2026-10-09
   Anthony: "can you wire up sage integration as well" — both Sage products:
     ☁️ Sage Accounting (cloud): the same three steps as Xero / QuickBooks — your Sage app,
        connect (pick the business), ledger / bank / tax mapping — then Sync now + log.
     🖥️ Sage 50 (desktop): Sage 50 has no online API, so KiddieTrac makes the files Sage 50
        imports — a general journal (.TXT, only what is new since the last file) and a
        Customers CSV — with the account numbers to post to.
   Agency admins and platform admins.
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
  function money(n) { return '$' + Number(n || 0).toLocaleString('en-CA', { minimumFractionDigits: 2, maximumFractionDigits: 2 }); }

  /* A file from the API, saved by the browser (KT.Api parses JSON, so files go through fetch). */
  async function download(method, path, body, fallbackName) {
    var token = (window.KT && KT.Auth && KT.Auth.token && KT.Auth.token()) || sessionStorage.getItem('kt_token') || localStorage.getItem('kt_token') || '';
    var h = { Authorization: 'Bearer ' + token, Accept: 'text/plain, text/csv, application/json' };
    var ag = sessionStorage.getItem('kt_active_agency_id'); if (ag) h['X-Active-Agency-Id'] = ag;
    if (body) h['Content-Type'] = 'application/json';
    var res = await fetch((KT.API_BASE || 'https://api.kiddietrac.com/api/v1') + path, { method: method, headers: h, body: body ? JSON.stringify(body) : undefined });
    if (!res.ok) {
      var msg = 'HTTP ' + res.status; try { msg = (await res.json()).message || msg; } catch (e) {}
      throw new Error(msg);
    }
    var name = fallbackName, cd = res.headers.get('Content-Disposition') || '';
    var m = cd.match(/filename="([^"]+)"/); if (m) name = m[1];
    var blob = await res.blob();
    var a = document.createElement('a'); a.href = URL.createObjectURL(blob); a.download = name;
    document.body.appendChild(a); a.click(); setTimeout(function () { URL.revokeObjectURL(a.href); a.remove(); }, 800);
    var sum = null; try { sum = JSON.parse(res.headers.get('X-Export-Summary') || 'null'); } catch (e) {}
    return sum;
  }

  var ACC = '#00A65A';
  function ensureCss() {
    if (document.getElementById('kt-sage-css')) return;
    var s = document.createElement('style'); s.id = 'kt-sage-css';
    s.textContent = ''
      + '.sg-wrap{padding:20px 24px;max-width:1100px;margin:0 auto;}'
      + '.sg-tabs{display:flex;gap:4px;border-bottom:1px solid #E5E7EB;margin-top:16px;overflow-x:auto;white-space:nowrap;}'
      + '.sg-tab{background:none;border:0;border-bottom:3px solid transparent;color:#64748B;font-weight:700;font-size:14px;padding:9px 14px;cursor:pointer;margin-bottom:-1px;}'
      + '.sg-tab.on{color:' + ACC + ';border-bottom-color:' + ACC + ';}'
      + '.sg-card{background:#fff;border:1px solid #E2E8F0;border-radius:12px;padding:16px 18px;margin-top:14px;}'
      + '.sg-card.off{opacity:.55;pointer-events:none;}'
      + '.sg-h{display:flex;align-items:center;gap:10px;margin:0 0 10px;font-size:15px;font-weight:800;color:#0F172A;}'
      + '.sg-n{width:24px;height:24px;border-radius:50%;background:' + ACC + ';color:#fff;font-size:12.5px;display:inline-flex;align-items:center;justify-content:center;flex:none;}'
      + '.sg-n.done{background:#16A34A;}'
      + '.sg-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:12px;}'
      + '.sg-l{display:block;font-size:11.5px;font-weight:800;color:#334155;text-transform:uppercase;letter-spacing:.4px;margin-bottom:4px;}'
      + '.sg-i{width:100%;box-sizing:border-box;height:32px;padding:0 10px;border:1px solid #CBD5E1;border-radius:8px;font-size:13px;background:#fff;}'
      + '.sg-hint{color:#64748B;font-size:12px;margin-top:4px;line-height:1.45;}'
      + '.sg-row{display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin-top:12px;}'
      + '.sg-b{height:30px;padding:0 12px;border-radius:8px;border:1px solid #CBD5E1;background:#fff;font-weight:700;font-size:13px;cursor:pointer;color:#0F172A;}'
      + '.sg-b.p{background:' + ACC + ';border-color:' + ACC + ';color:#fff;}'
      + '.sg-b.d{background:#FEF2F2;border-color:#FECACA;color:#B91C1C;}'
      + '.sg-b:disabled{opacity:.6;cursor:default;}'
      + '.sg-pill{display:inline-block;padding:2px 9px;border-radius:999px;font-size:12px;font-weight:700;}'
      + '.sg-code{font-family:ui-monospace,Consolas,monospace;font-size:12.5px;background:#F1F5F9;border:1px solid #E2E8F0;border-radius:6px;padding:5px 8px;word-break:break-all;}'
      + '.sg-tog{display:flex;gap:8px;align-items:flex-start;font-size:13px;color:#0F172A;margin-top:8px;}'
      + '.sg-log{width:100%;border-collapse:collapse;font-size:12.5px;}'
      + '.sg-log td,.sg-log th{padding:6px 8px;border-top:1px solid #EEF2F6;text-align:left;vertical-align:top;}'
      + '.sg-log th{background:#F8FAFC;color:#475569;font-size:11px;text-transform:uppercase;letter-spacing:.4px;border-top:0;}'
      + '.sg-stat{display:flex;gap:18px;flex-wrap:wrap;font-size:13px;color:#334155;margin-top:4px;}'
      + '.sg-stat b{font-size:16px;color:#0F172A;}'
      + '.sg-steps{margin:6px 0 0 18px;padding:0;color:#334155;font-size:13px;line-height:1.6;}';
    document.head.appendChild(s);
  }
  var TONE = { created: ['#ECFDF5', '#047857'], updated: ['#EFF6FF', '#1D4ED8'], unchanged: ['#F1F5F9', '#475569'], skipped: ['#FFFBEB', '#B45309'],
    failed: ['#FEF2F2', '#B91C1C'], voided: ['#F5F3FF', '#6D28D9'], deleted: ['#F5F3FF', '#6D28D9'] };
  function pill(t) { var c = TONE[t] || TONE.unchanged; return '<span class="sg-pill" data-kt-no-tone style="background:' + c[0] + ';color:' + c[1] + ';">' + esc(t) + '</span>'; }

  var TAB_KEY = 'kt_sage_tab';
  async function render(main) {
    ensureCss();
    main.innerHTML = '<div class="sg-wrap" style="color:#64748B;">Loading Sage settings…</div>';
    var c;
    try { c = await api().get('/admin/sage-config'); }
    catch (e) { main.innerHTML = '<div class="sg-wrap" style="color:#B91C1C;">' + esc(e.message || 'Could not load Sage settings.') + '</div>'; return; }
    var tab = 'cloud'; try { tab = sessionStorage.getItem(TAB_KEY) || (c.connected ? 'cloud' : ((c.s50.receivable || c.s50.last_export) ? 'desktop' : 'cloud')); } catch (e) {}

    main.innerHTML = '<div class="sg-wrap">'
      + '<div class="kt-hero" style="background:linear-gradient(135deg,#00291A 0%,#00713D 55%,#00A65A 100%);">'
      +   '<div class="kt-hero-greet">📘 SETTINGS</div><h1>Sage</h1>'
      +   '<div class="kt-hero-sub">Using Sage Accounting online? Connect it and invoices, voids and payments sync like Xero and QuickBooks. '
      +   'Using Sage 50 on a computer? Set your account numbers and download the files Sage 50 imports.</div></div>'
      + '<div class="sg-tabs"><button type="button" class="sg-tab" data-t="cloud">☁️ Sage Accounting (cloud)</button>'
      + '<button type="button" class="sg-tab" data-t="desktop">🖥️ Sage 50 (desktop)</button></div>'
      + '<div id="sg-pane"></div></div>';
    var pane = document.getElementById('sg-pane');
    function show(t) {
      tab = t; try { sessionStorage.setItem(TAB_KEY, t); } catch (e) {}
      main.querySelectorAll('.sg-tab').forEach(function (b) { b.classList.toggle('on', b.getAttribute('data-t') === t); });
      if (t === 'desktop') renderDesktop(pane, c, main); else renderCloud(pane, c, main);
    }
    main.querySelectorAll('.sg-tab').forEach(function (b) { b.onclick = function () { show(b.getAttribute('data-t')); }; });
    show(tab === 'desktop' ? 'desktop' : 'cloud');
  }

  /* ───────────── Sage Accounting (cloud) ───────────── */
  function renderCloud(pane, c, main) {
    var step1 = c.configured, step2 = c.connected, needsBiz = c.authorised && !c.business_id && (c.businesses || []).length > 1;
    var h = '<div class="sg-card"><div class="sg-h"><span class="sg-n' + (step1 ? ' done' : '') + '">' + (step1 ? '✓' : '1') + '</span>Your Sage app</div>'
      + '<div class="sg-hint" style="margin:-4px 0 10px;">In <a href="https://developerselfservice.sageone.com/" target="_blank" rel="noopener">Sage Developer Self Service</a>, '
      + 'create an app, add the callback URL below, then copy its <b>Client ID</b> and <b>Client secret</b> here.</div>'
      + '<div class="sg-l">Callback URL</div><div style="display:flex;gap:8px;align-items:center;"><div class="sg-code" id="sg-ruri" style="flex:1;">' + esc(c.redirect_uri) + '</div>'
      + '<button type="button" class="sg-b" id="sg-copy">Copy</button></div>'
      + '<div class="sg-grid" style="margin-top:12px;">'
      +   '<div><label class="sg-l" for="sg-cid">Client ID</label><input class="sg-i" id="sg-cid" autocomplete="off" spellcheck="false" value="' + esc(c.client_id) + '"></div>'
      +   '<div><label class="sg-l" for="sg-sec">Client secret</label><input class="sg-i" id="sg-sec" type="password" autocomplete="new-password" placeholder="' + (c.has_secret ? '•••••••• saved — leave blank to keep' : 'Paste the secret') + '"></div>'
      + '</div>'
      + '<div class="sg-row"><button type="button" class="sg-b p" id="sg-save1">Save app details</button>'
      + (c.client_id || c.has_secret ? '<button type="button" class="sg-b d" id="sg-clear">Clear & disconnect</button>' : '')
      + '<span id="sg-msg1" class="sg-hint" style="margin:0;"></span></div></div>';

    h += '<div class="sg-card' + (step1 ? '' : ' off') + '"><div class="sg-h"><span class="sg-n' + (step2 ? ' done' : '') + '">' + (step2 ? '✓' : '2') + '</span>Connect your business</div>';
    if (step2) {
      h += '<div style="font-size:13.5px;">Connected to <b>' + esc(c.business_name) + '</b>' + (c.connected_by ? ' by ' + esc(c.connected_by) : '') + (c.connected_at ? ' · ' + esc(when(c.connected_at)) : '') + '</div>'
        + '<div class="sg-row"><button type="button" class="sg-b" id="sg-connect">Reconnect</button>'
        + ((c.businesses || []).length > 1 ? '<select class="sg-i" id="sg-biz2" style="max-width:280px;">' + c.businesses.map(function (b) { return '<option value="' + esc(b.id) + '"' + (b.id === c.business_id ? ' selected' : '') + '>' + esc(b.name) + '</option>'; }).join('') + '</select><button type="button" class="sg-b" id="sg-biz2-save">Switch business</button>' : '')
        + '<button type="button" class="sg-b d" id="sg-disc">Disconnect</button></div>';
    } else if (needsBiz) {
      h += '<div style="font-size:13.5px;margin-bottom:8px;">You gave KiddieTrac access to several businesses. Choose the one to send invoices to:</div>'
        + '<div class="sg-row" style="margin-top:0;"><select class="sg-i" id="sg-biz" style="max-width:360px;">'
        + c.businesses.map(function (b) { return '<option value="' + esc(b.id) + '">' + esc(b.name) + '</option>'; }).join('')
        + '</select><button type="button" class="sg-b p" id="sg-biz-save">Use this business</button></div>';
    } else {
      h += '<div class="sg-hint" style="margin:0 0 4px;">Opens Sage in a new window. Sign in and allow access.</div>'
        + '<div class="sg-row"><button type="button" class="sg-b p" id="sg-connect">Connect to Sage →</button></div>';
      if (c.last_error) h += '<div class="sg-hint" style="color:#B91C1C;">' + esc(c.last_error) + '</div>';
    }
    h += '</div>';
    h += '<div class="sg-card' + (step2 ? '' : ' off') + '"><div class="sg-h"><span class="sg-n' + (step2 && c.ledger_account_id ? ' done' : '') + '">' + (step2 && c.ledger_account_id ? '✓' : '3') + '</span>Accounts & sync</div>'
      + (step2 ? '<div id="sg-opts" class="sg-hint">Reading your accounts from Sage…</div>' : '<div class="sg-hint">Connect first.</div>') + '</div>';
    if (step2) {
      h += '<div class="sg-card"><div class="sg-h">🔄 Sync</div>'
        + '<div class="sg-stat"><span><b>' + c.pending + '</b> waiting to sync</span><span><b>' + c.linked.invoices + '</b> invoices in Sage</span>'
        + '<span><b>' + c.linked.payments + '</b> receipts</span><span><b>' + c.linked.contacts + '</b> families as customers</span></div>'
        + '<div class="sg-hint">Last sync: ' + esc(when(c.last_sync_at)) + (c.auto_sync ? ' · automatic sync every 30 minutes is on' : ' · automatic sync is off') + '</div>'
        + (c.last_error ? '<div class="sg-hint" style="color:#B91C1C;">⚠️ ' + esc(c.last_error) + '</div>' : '')
        + '<div class="sg-row"><button type="button" class="sg-b p" id="sg-sync"' + (c.ledger_account_id ? '' : ' disabled title="Choose the sales ledger account first"') + '>Sync now</button>'
        + '<span id="sg-sync-out" class="sg-hint" style="margin:0;"></span></div></div>'
        + '<div class="sg-card"><div class="sg-h">📜 Sync log</div><div id="sg-log" class="sg-hint">Loading…</div></div>';
    }
    pane.innerHTML = h;
    var $ = function (id) { return document.getElementById(id); };
    function busy(b, on, l) { if (!b) return; b.disabled = on; if (l) b.textContent = l; }

    $('sg-copy').onclick = function () { try { navigator.clipboard.writeText(c.redirect_uri); toast('Callback URL copied', 'success'); } catch (e) {} };
    $('sg-save1').onclick = async function () {
      var b = this; busy(b, true, 'Saving…');
      var body = { client_id: $('sg-cid').value.trim() };
      if ($('sg-sec').value.trim()) body.client_secret = $('sg-sec').value.trim();
      if (c.connected && (body.client_id !== c.client_id || body.client_secret)
          && !(await KT.confirm({ title: 'Change the Sage app?', description: 'A different app cannot use the current connection, so Sage will be disconnected and you will connect again.', okLabel: 'Change app' }))) { busy(b, false, 'Save app details'); return; }
      try { await api().patch('/admin/sage-config', body); toast('Sage app details saved', 'success'); render(main); }
      catch (e) { $('sg-msg1').style.color = '#B91C1C'; $('sg-msg1').textContent = e.message || 'Could not save.'; busy(b, false, 'Save app details'); }
    };
    if ($('sg-clear')) $('sg-clear').onclick = async function () {
      if (!(await KT.confirm({ title: 'Clear Sage Accounting?', description: 'This disconnects Sage and removes your app\'s client ID and secret. Invoices already in Sage stay there. Sage 50 settings are kept.', okLabel: 'Clear & disconnect' }))) return;
      try { await api().delete('/admin/sage-config'); toast('Sage cleared', 'success'); render(main); } catch (e) { toast(e.message || 'Could not clear', 'error'); }
    };
    if ($('sg-connect')) $('sg-connect').onclick = function () {
      var b = this, label = b.textContent; busy(b, true, 'Opening Sage…');
      var w = window.open('about:blank', 'kt-sage', 'width=620,height=760');
      api().get('/sage/connect').then(function (r) {
        if (w && !w.closed) w.location.href = r.authorize_url; else window.location.href = r.authorize_url;
        busy(b, true, 'Waiting for Sage…');
        var done = false;
        function finish() { if (done) return; done = true; window.removeEventListener('message', onMsg); clearInterval(poll); render(main); }
        function onMsg(ev) { if (ev && ev.data && ev.data.kt === 'sage') finish(); }
        window.addEventListener('message', onMsg);
        var poll = setInterval(function () { if (!w || w.closed) finish(); }, 800);
      }).catch(function (e) { if (w) w.close(); toast(e.message || 'Could not start the Sage connection', 'error'); busy(b, false, label); });
    };
    function pickBiz(id) { return api().post('/sage/business', { business_id: id }).then(function () { toast('Business chosen', 'success'); render(main); }).catch(function (e) { toast(e.message || 'Could not save', 'error'); }); }
    if ($('sg-biz-save')) $('sg-biz-save').onclick = function () { pickBiz($('sg-biz').value); };
    if ($('sg-biz2-save')) $('sg-biz2-save').onclick = function () { pickBiz($('sg-biz2').value); };
    if ($('sg-disc')) $('sg-disc').onclick = async function () {
      if (!(await KT.confirm({ title: 'Disconnect Sage?', description: 'Invoices stop syncing. Everything already in Sage stays there, and reconnecting picks up where it left off.', okLabel: 'Disconnect' }))) return;
      try { await api().post('/sage/disconnect', {}); toast('Sage disconnected', 'success'); render(main); } catch (e) { toast(e.message || 'Could not disconnect', 'error'); }
    };
    if (!step2) return;

    api().get('/sage/options').then(function (o) {
      var box = $('sg-opts'); if (!box) return;
      function sel(id, list, cur, blank) {
        var has = false;
        var opts = (blank ? '<option value="">' + esc(blank) + '</option>' : '') + list.map(function (a) {
          if (String(a.id) === String(cur || '')) has = true;
          return '<option value="' + esc(a.id) + '"' + (String(a.id) === String(cur || '') ? ' selected' : '') + '>' + esc(a.name) + '</option>';
        }).join('');
        if (cur && !has) opts += '<option value="' + esc(cur) + '" selected>(no longer in Sage)</option>';
        return '<select class="sg-i" id="' + id + '">' + opts + '</select>';
      }
      box.className = '';
      box.innerHTML = '<div class="sg-grid">'
        + '<div><label class="sg-l" for="sg-led">Sales ledger account for tuition *</label>' + sel('sg-led', o.ledgers, c.ledger_account_id, 'Choose…') + '</div>'
        + '<div><label class="sg-l" for="sg-sled">Ledger account for subsidies</label>' + sel('sg-sled', o.ledgers, c.subsidy_ledger_account_id, 'Same as tuition')
        +   '<div class="sg-hint">Subsidy (e.g. CWELCC) goes in as a negative line.</div></div>'
        + '<div><label class="sg-l" for="sg-bank">Bank account for payments</label>' + sel('sg-bank', o.banks, c.bank_account_id, 'Don\'t record payments yet') + '</div>'
        + '<div><label class="sg-l" for="sg-tex">Tax rate for tax-free lines</label>' + sel('sg-tex', o.tax_rates, c.tax_rate_exempt, 'Use the ledger account\'s default')
        +   '<div class="sg-hint">Child care is usually exempt.</div></div>'
        + '<div><label class="sg-l" for="sg-ttx">Tax rate for taxed lines</label>' + sel('sg-ttx', o.tax_rates, c.tax_rate_taxable, 'None — our invoices are tax-free')
        +   '<div class="sg-hint">Only for invoices that charge tax; such invoices wait until one is chosen.</div></div>'
        + '<div><label class="sg-l" for="sg-from">Only invoices issued on or after</label><input class="sg-i" type="date" id="sg-from" value="' + esc(c.sync_from || '') + '">'
        +   '<div class="sg-hint">Set it to your Sage start date so old invoices are not duplicated.</div></div>'
        + '</div>'
        + '<label class="sg-tog"><input type="checkbox" id="sg-pay"' + (c.sync_payments ? ' checked' : '') + '> <span>Record payments and refunds against the invoices in Sage</span></label>'
        + '<label class="sg-tog"><input type="checkbox" id="sg-auto"' + (c.auto_sync ? ' checked' : '') + '> <span>Sync automatically every 30 minutes'
        +   '<span class="sg-hint" style="display:block;margin:0;">Off: nothing goes to Sage until you press Sync now.</span></span></label>'
        + '<div class="sg-row"><button type="button" class="sg-b p" id="sg-save3">Save accounts & sync settings</button><span id="sg-msg3" class="sg-hint" style="margin:0;"></span></div>';
      $('sg-save3').onclick = async function () {
        var b = this;
        if (!$('sg-led').value) { $('sg-msg3').style.color = '#B91C1C'; $('sg-msg3').textContent = 'Choose the sales ledger account for tuition.'; return; }
        busy(b, true, 'Saving…');
        try {
          await api().patch('/admin/sage-config', { ledger_account_id: $('sg-led').value, subsidy_ledger_account_id: $('sg-sled').value, bank_account_id: $('sg-bank').value,
            tax_rate_exempt: $('sg-tex').value, tax_rate_taxable: $('sg-ttx').value, sync_from: $('sg-from').value || null,
            sync_payments: $('sg-pay').checked, auto_sync: $('sg-auto').checked });
          toast('Sage settings saved', 'success'); render(main);
        } catch (e) { $('sg-msg3').style.color = '#B91C1C'; $('sg-msg3').textContent = e.message || 'Could not save.'; busy(b, false, 'Save accounts & sync settings'); }
      };
    }).catch(function (e) { var box = $('sg-opts'); if (box) { box.style.color = '#B91C1C'; box.textContent = (e && e.message) || 'Could not read your accounts from Sage.'; } });

    $('sg-sync').onclick = async function () {
      var b = this, out = $('sg-sync-out'); busy(b, true, 'Syncing…'); out.style.color = '#64748B'; out.textContent = 'Sending to Sage…';
      try {
        var r = await api().post('/sage/sync', {});
        var parts = []; ['created', 'updated', 'voided', 'unchanged', 'skipped', 'failed'].forEach(function (k) { if (r[k]) parts.push(r[k] + ' ' + k); });
        out.style.color = r.failed ? '#B91C1C' : '#047857';
        out.textContent = (parts.length ? parts.join(', ') : 'Nothing to send') + (r.remaining ? ' — ' + r.remaining + ' more waiting, press Sync now again' : '') + (r.error ? ' — ' + r.error : '');
        loadLog();
      } catch (e) { out.style.color = '#B91C1C'; out.textContent = e.message || 'Sync failed.'; }
      busy(b, false, 'Sync now');
    };
    function loadLog() {
      api().get('/sage/log', { limit: 60 }).then(function (d) {
        var rows = d.log || [], box = $('sg-log'); if (!box) return;
        if (!rows.length) { box.textContent = 'Nothing synced yet.'; return; }
        box.className = '';
        box.innerHTML = '<div style="overflow-x:auto;"><table class="sg-log"><thead><tr><th>When</th><th>What</th><th>Result</th><th>Details</th><th>By</th></tr></thead><tbody>'
          + rows.map(function (r) {
              var what = r.kind === 'invoice' ? 'Invoice ' + (r.invoice_number || ('#' + r.local_id)) : r.kind === 'payment' ? 'Payment #' + r.local_id : r.kind === 'contact' ? 'Family #' + r.local_id : r.kind;
              return '<tr><td style="white-space:nowrap;">' + esc(when(r.created_at)) + '</td><td style="white-space:nowrap;">' + esc(what) + '</td><td>' + pill(r.action)
                + '</td><td style="color:#334155;">' + esc(r.message) + '</td><td style="white-space:nowrap;color:#64748B;">' + esc(r.by) + '</td></tr>';
            }).join('') + '</tbody></table></div>';
      }).catch(function () { var box = $('sg-log'); if (box) box.textContent = 'Could not load the sync log.'; });
    }
    loadLog();
  }

  /* ───────────── Sage 50 (desktop) ───────────── */
  function renderDesktop(pane, c, main) {
    var s = c.s50 || {};
    var ready = !!(s.receivable && s.revenue);
    function f(id, label, val, hint, req) {
      return '<div><label class="sg-l" for="s5-' + id + '">' + label + (req ? ' *' : '') + '</label>'
        + '<input class="sg-i" id="s5-' + id + '" inputmode="numeric" maxlength="8" placeholder="e.g. ' + esc(hint[0]) + '" value="' + esc(val || '') + '">'
        + '<div class="sg-hint">' + hint[1] + '</div></div>';
    }
    pane.innerHTML = ''
      + '<div class="sg-card"><div class="sg-h"><span class="sg-n' + (ready ? ' done' : '') + '">' + (ready ? '✓' : '1') + '</span>Your Sage 50 account numbers</div>'
      + '<div class="sg-hint" style="margin:-4px 0 10px;">Sage 50 has no online connection, so KiddieTrac makes the files it imports. '
      + 'Sage 50 won\'t import journal lines to its own <b>Accounts Receivable</b> account, so first add an asset account for family balances in Sage 50 '
      + '(Setup → Chart of Accounts), e.g. <b>1210 KiddieTrac family receivables</b>, and enter it below.</div>'
      + '<div class="sg-grid">'
      + f('receivable', 'Family receivable account', s.receivable, ['1210', 'What families owe. An asset account — not your linked Accounts Receivable.'], true)
      + f('revenue', 'Tuition revenue account', s.revenue, ['4100', 'Where tuition is booked.'], true)
      + f('bank', 'Bank account for payments', s.bank, ['1060', 'Where card and bank payments taken in KiddieTrac land. Needed to include payments.'])
      + f('subsidy', 'Subsidy account', s.subsidy, ['1220', 'Optional. The subsidy (e.g. CWELCC) receivable or contra account. Blank: tuition is booked net of subsidy.'])
      + f('discount', 'Discount account', s.discount, ['4190', 'Optional. Blank: tuition is booked net of discounts.'])
      + f('tax', 'Tax payable account', s.tax, ['2310', 'Only needed if your invoices charge tax.'])
      + '</div>'
      + '<div class="sg-row"><button type="button" class="sg-b p" id="s5-save">Save account numbers</button><span id="s5-msg" class="sg-hint" style="margin:0;"></span></div></div>'

      + '<div class="sg-card' + (ready ? '' : ' off') + '"><div class="sg-h"><span class="sg-n">2</span>Customers file (once, then for new families)</div>'
      + '<div class="sg-hint" style="margin:-4px 0 8px;">Your current families as Sage 50 customers.</div>'
      + '<ol class="sg-steps"><li>Download the file.</li><li>In Sage 50: <b>File → Import/Export → Import Records</b>, choose <b>Customers</b>, then the file.</li><li>Check the columns line up, and finish.</li></ol>'
      + '<div class="sg-row"><button type="button" class="sg-b" id="s5-cust">⬇️ Download customers (CSV)</button></div></div>'

      + '<div class="sg-card' + (ready ? '' : ' off') + '"><div class="sg-h"><span class="sg-n">3</span>Journal file — invoices and payments</div>'
      + '<div class="sg-hint" style="margin:-4px 0 8px;">Each file holds only what is new since the last one: issued invoices, payments, an adjusting entry for an invoice whose amounts changed, '
      + 'and reversing entries for invoices voided or payments refunded after they were exported. Every entry balances.</div>'
      + '<div class="sg-grid"><div><label class="sg-l" for="s5-from">From (optional)</label><input class="sg-i" type="date" id="s5-from"></div>'
      + '<div><label class="sg-l" for="s5-to">To (optional)</label><input class="sg-i" type="date" id="s5-to"></div></div>'
      + '<div class="sg-hint">Leave both blank to include everything not yet exported. Set From to your Sage 50 start date the first time.</div>'
      + '<div id="s5-prev" class="sg-hint" style="margin-top:10px;"></div>'
      + '<div class="sg-row"><button type="button" class="sg-b" id="s5-preview">Preview</button><button type="button" class="sg-b p" id="s5-export">⬇️ Download journal file</button></div>'
      + '<ol class="sg-steps" style="margin-top:10px;"><li>In Sage 50: <b>File → Import/Export → Import Transactions</b>.</li><li>Choose <b>Import general journal entries</b>, then the downloaded <b>.TXT</b> file.</li>'
      + '<li>Back up your company first, as Sage suggests. If Sage refuses the file, nothing is posted. Fix the account numbers and download the same file again from the list below.</li></ol></div>'

      + '<div class="sg-card"><div class="sg-h">📁 Files made</div><div id="s5-list" class="sg-hint">Loading…</div></div>';

    var $ = function (id) { return document.getElementById(id); };
    $('s5-save').onclick = async function () {
      var b = this; b.disabled = true;
      var body = {}; ['receivable', 'revenue', 'bank', 'subsidy', 'discount', 'tax'].forEach(function (k) { body['s50_' + k] = $('s5-' + k).value.trim() || null; });
      try { await api().patch('/admin/sage-config', body); toast('Sage 50 account numbers saved', 'success'); render(main); }
      catch (e) { $('s5-msg').style.color = '#B91C1C'; $('s5-msg').textContent = e.message || 'Could not save.'; b.disabled = false; }
    };
    $('s5-cust').onclick = async function () {
      var b = this; b.disabled = true;
      try { await download('GET', '/sage50/customers.csv', null, 'kiddietrac-sage50-customers.csv'); toast('Customers file downloaded', 'success'); }
      catch (e) { toast(e.message || 'Could not make the file', 'error'); }
      b.disabled = false;
    };
    function range() { return { from: $('s5-from').value || null, to: $('s5-to').value || null }; }
    function describe(p) {
      var parts = [];
      if (p.invoices) parts.push(p.invoices + ' invoice' + (p.invoices === 1 ? '' : 's'));
      if (p.payments) parts.push(p.payments + ' payment' + (p.payments === 1 ? '' : 's'));
      if (p.adjustments) parts.push(p.adjustments + ' adjustment' + (p.adjustments === 1 ? '' : 's'));
      if (p.reversals) parts.push(p.reversals + ' reversal' + (p.reversals === 1 ? '' : 's'));
      return (parts.length ? parts.join(', ') + ' — ' + p.entries + ' journal entries, debits ' + money(p.debits) : 'Nothing new to export.')
        + (p.warnings && p.warnings.length ? ' ⚠️ ' + p.warnings.join(' ') : '');
    }
    $('s5-preview').onclick = async function () {
      var q = range(), qs = [];
      if (q.from) qs.push('from=' + q.from); if (q.to) qs.push('to=' + q.to);
      $('s5-prev').style.color = '#64748B'; $('s5-prev').textContent = 'Checking…';
      try { var p = await api().get('/sage50/preview' + (qs.length ? '?' + qs.join('&') : '')); $('s5-prev').style.color = p.warnings && p.warnings.length ? '#B45309' : '#047857'; $('s5-prev').textContent = describe(p); }
      catch (e) { $('s5-prev').style.color = '#B91C1C'; $('s5-prev').textContent = e.message || 'Could not check.'; }
    };
    $('s5-export').onclick = async function () {
      var b = this; b.disabled = true;
      try {
        var sum = await download('POST', '/sage50/export', range(), 'kiddietrac-sage50-journal.txt');
        $('s5-prev').style.color = '#047857'; $('s5-prev').textContent = sum ? 'Downloaded: ' + describe(sum) : 'Downloaded.';
        loadList();
      } catch (e) { $('s5-prev').style.color = '#B91C1C'; $('s5-prev').textContent = e.message || 'Could not make the file.'; }
      b.disabled = false;
    };
    function loadList() {
      api().get('/sage50/exports').then(function (d) {
        var rows = d.exports || [], box = $('s5-list'); if (!box) return;
        if (!rows.length) { box.textContent = 'No journal files yet.'; return; }
        box.className = '';
        box.innerHTML = '<div style="overflow-x:auto;"><table class="sg-log"><thead><tr><th>#</th><th>Made</th><th>By</th><th>Entries</th><th>Debits</th><th></th></tr></thead><tbody>'
          + rows.map(function (r) {
              return '<tr><td>' + r.id + '</td><td style="white-space:nowrap;">' + esc(when(r.created_at)) + '</td><td>' + esc(((r.first_name || '') + ' ' + (r.last_name || '')).trim()) + '</td><td>'
                + r.entries + '</td><td>' + money(r.total_debits) + '</td><td><button type="button" class="sg-b" data-dl="' + r.id + '" style="height:26px;font-size:12px;">⬇️ Download again</button></td></tr>';
            }).join('') + '</tbody></table></div>';
        box.querySelectorAll('[data-dl]').forEach(function (b) {
          b.onclick = function () { download('GET', '/sage50/exports/' + b.getAttribute('data-dl'), null, 'kiddietrac-sage50-journal.txt').catch(function (e) { toast(e.message || 'Could not download', 'error'); }); };
        });
      }).catch(function () { var box = $('s5-list'); if (box) box.textContent = 'Could not load the list.'; });
    }
    loadList();
  }

  function reg() {
    if (!(window.KT && window.KT.Shell && window.KT.Shell.registerScreen)) { setTimeout(reg, 200); return; }
    ['agency_admin', 'platform_admin'].forEach(function (r) { window.KT.Shell.registerScreen(r + ':sage', render); });
  }
  reg();
  window.KT = window.KT || {};
  window.KT.renderSage = render;
})(window);
