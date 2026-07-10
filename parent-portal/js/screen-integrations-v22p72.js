/* ═══════════════════════════════════════════════════════════════════
   KIDDIETRAC v22p72 — Admin integrations + settings screens
   • QuickBooks (Intuit) — connect / status / disconnect / bulk sync
   • Email settings (per agency) — from name/address/encryption + test send
   Registered for agency_admin + platform_admin.
   ═══════════════════════════════════════════════════════════════════ */
(function (window) {
  'use strict';

  // Lazy Api (resolve at call time so load order can't break the export)
  function api() {
    var a = window.KT && window.KT.Api;
    if (!a) throw new Error('KT.Api not loaded');
    return a;
  }
  function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c]; }); }
  function toast(msg, kind) { if (window.KT && window.KT.toast) window.KT.toast(msg, kind || 'info'); else alert(msg); }

  /* ───────────── QuickBooks (Intuit) ───────────── */
  async function renderQuickbooks(main) {
    main.setAttribute('data-kt-pretty', '1');
    main.innerHTML = '<div style="padding:24px;max-width:1800px;margin:0 auto;">Loading QuickBooks…</div>';
    var status, cfg;
    try { status = await api().get('/qbo/status'); }
    catch (e) { status = { connected: false, configured: false, error: e.message }; }
    try { cfg = await api().get('/admin/qbo-config'); }
    catch (e) { cfg = { client_id: '', environment: 'production', has_secret: false, redirect_uri: '', configured: false }; }

    var inner = '<div style="padding:24px;max-width:1800px;margin:0 auto;">'
      + '<div class="kt-page-hero"><h2>📒 QuickBooks Online (Intuit)</h2>'
      + '<p>Connect your agency\'s QuickBooks Online to sync invoices. Configure your own Intuit app credentials below.</p></div>';

    // ── Connection state card ──
    if (status.connected) {
      inner += card(
        '<div style="display:flex;align-items:center;gap:14px;margin-bottom:8px;">'
        + '<span class="kt-pill kt-pill-success" style="font-size:13px;">✓ Connected</span>'
        + '<span style="color:#64748B;font-size:13px;">' + esc(cfg.environment || 'production') + '</span>'
        + (status.expires_at ? '<span style="color:#64748B;font-size:13px;">Token valid until ' + esc(status.expires_at) + '</span>' : '')
        + '</div>'
        + '<p style="color:#475569;">Your KiddieTrac invoices can be pushed to QuickBooks Online.</p>'
        + '<div style="display:flex;gap:10px;margin-top:16px;flex-wrap:wrap;">'
        + '<button id="qbo-bulk" class="kt-btn kt-btn-primary">⇪ Sync all unsynced invoices</button>'
        + '<button id="qbo-disc" class="kt-btn" style="background:#FEE2E2;color:#B91C1C;">Disconnect</button>'
        + '</div>'
        + '<div id="qbo-out" style="margin-top:14px;"></div>'
      );
    } else if (cfg.configured) {
      inner += card(
        '<div style="text-align:center;padding:18px;">'
        + '<div style="font-size:48px;">📒</div>'
        + '<h3 style="margin:10px 0 6px;">Ready to connect</h3>'
        + '<p style="color:#64748B;max-width:520px;margin:0 auto 16px;">Your Intuit app credentials are set. Click below to authorise KiddieTrac against your QuickBooks company.</p>'
        + '<button id="qbo-connect" class="kt-btn kt-btn-primary" style="font-size:15px;padding:12px 28px;">Connect to QuickBooks →</button>'
        + '</div>'
      );
    }

    // ── Per-agency credentials config card (always shown) ──
    inner += card(
      '<div class="kt-card-header"><h3 class="kt-card-title">⚙️ Intuit app credentials</h3></div>'
      + '<p style="color:#64748B;font-size:13px;margin:0 0 14px;">Create an app at <a href="https://developer.intuit.com" target="_blank" rel="noopener" style="color:#1F6080;">developer.intuit.com</a>, then paste its keys here. '
      + 'Set the redirect URI below in your Intuit app.</p>'
      + (cfg.using_platform_fallback ? '<div style="background:#FEF3C7;color:#92400E;padding:10px 12px;border-radius:8px;font-size:13px;margin-bottom:12px;">Currently using the platform\'s shared Intuit app. Enter your own credentials below to use your agency\'s app.</div>' : '')
      + field('Client ID', 'qc-id', cfg.client_id || '', 'ABxxxxxxxxxxxxxxxxxxxx')
      + field('Client Secret', 'qc-secret', '', cfg.has_secret ? '•••••••• (saved — leave blank to keep)' : 'Enter client secret', 'password')
      + '<label style="display:block;font-size:13px;font-weight:600;margin:14px 0 4px;">Environment</label>'
      + '<select id="qc-env" style="width:100%;padding:10px;border:1.5px solid #E2E8F0;border-radius:8px;">'
      + '<option value="production"' + ((cfg.environment || 'production') === 'production' ? ' selected' : '') + '>Production</option>'
      + '<option value="sandbox"' + (cfg.environment === 'sandbox' ? ' selected' : '') + '>Sandbox (testing)</option>'
      + '</select>'
      + '<label style="display:block;font-size:13px;font-weight:600;margin:14px 0 4px;">Redirect URI (paste this into your Intuit app)</label>'
      + '<input readonly value="' + esc(cfg.redirect_uri || '') + '" onclick="this.select()" style="width:100%;padding:10px;border:1.5px solid #E2E8F0;border-radius:8px;background:#F8FAFC;box-sizing:border-box;font-family:monospace;font-size:12px;">'
      + '<div style="display:flex;gap:10px;margin-top:16px;flex-wrap:wrap;">'
      + '<button id="qc-save" class="kt-btn kt-btn-primary">Save credentials</button>'
      + (cfg.client_id ? '<button id="qc-clear" class="kt-btn" style="background:#FEE2E2;color:#B91C1C;">Clear credentials</button>' : '')
      + '</div>'
      + '<div id="qc-out" style="margin-top:12px;"></div>'
    );

    inner += '</div>';
    main.innerHTML = inner;

    // Save credentials
    var saveBtn = document.getElementById('qc-save');
    if (saveBtn) saveBtn.onclick = async function () {
      var body = {
        client_id: document.getElementById('qc-id').value.trim(),
        environment: document.getElementById('qc-env').value
      };
      var secret = document.getElementById('qc-secret').value.trim();
      if (secret) body.client_secret = secret;
      saveBtn.disabled = true; saveBtn.textContent = 'Saving…';
      try { await patch('/admin/qbo-config', body); toast('QuickBooks credentials saved.', 'success'); renderQuickbooks(main); }
      catch (e) { toast(e.message || 'Save failed', 'error'); saveBtn.disabled = false; saveBtn.textContent = 'Save credentials'; }
    };
    var clearBtn = document.getElementById('qc-clear');
    if (clearBtn) clearBtn.onclick = async function () {
      if (!confirm('Clear your Intuit credentials and disconnect QuickBooks?')) return;
      try { await del('/admin/qbo-config'); toast('Credentials cleared.', 'success'); renderQuickbooks(main); }
      catch (e) { toast(e.message || 'Failed', 'error'); }
    };

    var connectBtn = document.getElementById('qbo-connect');
    if (connectBtn) connectBtn.onclick = async function () {
      connectBtn.disabled = true; connectBtn.textContent = 'Opening Intuit…';
      try {
        var r = await api().get('/qbo/connect');
        if (r.authorize_url) window.location.href = r.authorize_url;
        else { toast('Could not start QuickBooks connect.', 'error'); connectBtn.disabled = false; connectBtn.textContent = 'Connect to QuickBooks →'; }
      } catch (e) { toast(e.message || 'Connect failed', 'error'); connectBtn.disabled = false; connectBtn.textContent = 'Connect to QuickBooks →'; }
    };

    var discBtn = document.getElementById('qbo-disc');
    if (discBtn) discBtn.onclick = async function () {
      if (!confirm('Disconnect QuickBooks? Invoices will stop syncing.')) return;
      try { await api().post('/qbo/disconnect', {}); toast('Disconnected.', 'success'); renderQuickbooks(main); }
      catch (e) { toast(e.message || 'Disconnect failed', 'error'); }
    };

    var bulkBtn = document.getElementById('qbo-bulk');
    if (bulkBtn) bulkBtn.onclick = async function () {
      bulkBtn.disabled = true; bulkBtn.textContent = 'Syncing…';
      var out = document.getElementById('qbo-out');
      try {
        var r = await api().post('/qbo/sync/invoices/bulk', {});
        out.innerHTML = '<div style="background:#DCFCE7;color:#15803D;padding:12px;border-radius:9px;">✓ Synced ' + (r.synced != null ? r.synced : '') + ' invoice(s).' + (r.failed ? ' ' + r.failed + ' failed.' : '') + '</div>';
      } catch (e) {
        out.innerHTML = '<div style="background:#FEE2E2;color:#B91C1C;padding:12px;border-radius:9px;">' + esc(e.message || 'Sync failed') + '</div>';
      }
      bulkBtn.disabled = false; bulkBtn.textContent = '⇪ Sync all unsynced invoices';
    };
  }

  /* ───────────── Email settings (per agency) ───────────── */
  async function renderEmailSettings(main) {
    main.setAttribute('data-kt-pretty', '1');
    main.innerHTML = '<div style="padding:24px;max-width:1800px;margin:0 auto;">Loading email settings…</div>';
    var s;
    try { s = await api().get('/admin/email-settings'); }
    catch (e) {
      main.innerHTML = '<div style="padding:24px;"><div class="kt-card" style="color:#B91C1C;">Could not load email settings: ' + esc(e.message) + '</div></div>';
      return;
    }

    var enc = s.email_smtp_encryption || 'tls';
    main.innerHTML = '<div style="padding:24px;max-width:1800px;margin:0 auto;">'
      + '<div class="kt-page-hero"><h2>✉️ Email settings</h2>'
      + '<p>How outgoing emails appear to families for <strong>' + esc(s.agency_name) + '</strong>.</p></div>'
      + '<div class="kt-card" style="max-width:680px;">'
      + field('From name', 'es-name', s.email_from_name || '', 'e.g. ' + esc(s.agency_name) + ' Childcare')
      + field('From address', 'es-addr', s.email_from_address || '', s.default_from || 'noreply@youragency.com', 'email')
      + '<label style="display:block;font-size:13px;font-weight:600;margin:14px 0 4px;">Encryption</label>'
      + '<select id="es-enc" style="width:100%;padding:10px;border:1.5px solid #E2E8F0;border-radius:8px;">'
      + '<option value="tls"' + (enc === 'tls' ? ' selected' : '') + '>TLS (recommended)</option>'
      + '<option value="ssl"' + (enc === 'ssl' ? ' selected' : '') + '>SSL</option>'
      + '<option value="none"' + (enc === 'none' ? ' selected' : '') + '>None</option>'
      + '</select>'
      + '<p style="color:#94A3B8;font-size:12px;margin:10px 0 0;">If left blank, the platform default sender (' + esc(s.default_from || 'system default') + ') is used.</p>'
      + '<div style="display:flex;gap:10px;margin-top:18px;flex-wrap:wrap;">'
      + '<button id="es-save" class="kt-btn kt-btn-primary">Save settings</button>'
      + '<button id="es-test" class="kt-btn" style="background:#F1F5F9;color:#1F2937;">Send test email to me</button>'
      + '</div>'
      + '<div id="es-out" style="margin-top:14px;"></div>'
      + '</div>'
      // ── Outbound SMTP — send via your own provider ──
      + '<div class="kt-card" style="max-width:680px;margin-top:18px;">'
      + '<div class="kt-card-header"><h3 class="kt-card-title">📤 Outbound email (SMTP)</h3></div>'
      + '<p style="color:#64748B;font-size:13px;margin:0 0 12px;">Send the portal’s emails through your own <strong>Google</strong>, <strong>Microsoft 365</strong>, or any SMTP provider — better deliverability than the shared default.</p>'
      + '<label style="display:block;font-size:13px;font-weight:600;margin:0 0 4px;">Sending method</label>'
      + '<select id="es-mode" style="width:100%;padding:10px;border:1.5px solid #E2E8F0;border-radius:8px;margin-bottom:12px;">'
      + '<option value="default"' + (s.mode !== 'smtp' ? ' selected' : '') + '>Platform default</option>'
      + '<option value="smtp"' + (s.mode === 'smtp' ? ' selected' : '') + '>My own SMTP server</option>'
      + '</select>'
      + '<div id="es-smtp-fields" style="' + (s.mode === 'smtp' ? '' : 'display:none;') + '">'
      + '<div style="display:flex;gap:8px;margin-bottom:6px;flex-wrap:wrap;">'
      + '<button type="button" class="kt-btn es-preset" data-p="gmail" style="background:#F1F5F9;color:#1F2937;">Gmail</button>'
      + '<button type="button" class="kt-btn es-preset" data-p="microsoft" style="background:#F1F5F9;color:#1F2937;">Microsoft 365</button>'
      + '<button type="button" class="kt-btn es-preset" data-p="custom" style="background:#F1F5F9;color:#1F2937;">Custom</button>'
      + '</div>'
      + field('SMTP host', 'es-host', s.smtp_host || '', 'smtp.gmail.com')
      + '<div style="display:flex;gap:10px;align-items:flex-end;">'
      + '<div style="flex:1;">' + field('Port', 'es-port', s.smtp_port || 587, '587') + '</div>'
      + '<div style="flex:1;"><label style="display:block;font-size:13px;font-weight:600;margin:14px 0 4px;">Encryption</label>'
      + '<select id="es-smtp-enc" style="width:100%;padding:10px;border:1.5px solid #E2E8F0;border-radius:8px;">'
      + ['tls', 'ssl', 'none'].map(function (o) { return '<option value="' + o + '"' + ((s.smtp_encryption || 'tls') === o ? ' selected' : '') + '>' + o.toUpperCase() + '</option>'; }).join('')
      + '</select></div></div>'
      + field('Username', 'es-user', s.smtp_username || '', 'you@gmail.com')
      + field('Password' + (s.has_smtp_password ? ' (leave blank to keep)' : ''), 'es-pass', '', s.has_smtp_password ? '••••••••' : 'app password', 'password')
      + '<p style="color:#94A3B8;font-size:12px;margin:8px 0 0;">Gmail &amp; Microsoft with 2-factor on need an <strong>app password</strong>, not your normal password.</p>'
      + '</div>'
      + '</div>'
      // ── Microsoft 365 mailbox (Graph) — powers the in-portal Email client ──
      + '<div class="kt-card" style="max-width:680px;margin-top:18px;">'
      + '<div class="kt-card-header"><h3 class="kt-card-title">📬 Mailbox — Microsoft 365 (email client)</h3></div>'
      + '<p style="color:#64748B;font-size:13px;margin:0 0 12px;">Connects the in-portal <strong>Email</strong> client to your Microsoft 365 mailboxes. Create an <strong>Azure AD app registration</strong> with Microsoft Graph Mail permissions, then paste its details here.</p>'
      + field('Directory (tenant) ID', 'es-gt', s.graph_tenant_id || '', '00000000-0000-0000-0000-000000000000')
      + field('Application (client) ID', 'es-gc', s.graph_client_id || '', '00000000-0000-0000-0000-000000000000')
      + field('Client secret' + (s.has_graph_secret ? ' (leave blank to keep)' : ''), 'es-gs', '', s.has_graph_secret ? '••••••••' : 'secret value', 'password')
      + '<p style="color:#94A3B8;font-size:12px;margin:8px 0 0;">Azure Portal → App registrations → Certificates &amp; secrets. Needs Graph <em>Mail.Read</em> / <em>Mail.Send</em>.</p>'
      + '</div>'
      + '</div>';


    // SMTP: show/hide fields by mode + provider presets
    var modeSel = document.getElementById('es-mode');
    var smtpFields = document.getElementById('es-smtp-fields');
    if (modeSel && smtpFields) modeSel.onchange = function () { smtpFields.style.display = modeSel.value === 'smtp' ? '' : 'none'; };
    [].forEach.call(document.querySelectorAll('.es-preset'), function (b) {
      b.onclick = function () {
        var p = b.getAttribute('data-p'), h = document.getElementById('es-host'), pt = document.getElementById('es-port'), en = document.getElementById('es-smtp-enc');
        if (p === 'gmail') { h.value = 'smtp.gmail.com'; pt.value = '587'; en.value = 'tls'; }
        else if (p === 'microsoft') { h.value = 'smtp.office365.com'; pt.value = '587'; en.value = 'tls'; }
        else { h.value = ''; pt.value = '587'; en.value = 'tls'; }
      };
    });

    document.getElementById('es-save').onclick = async function () {
      var v = function (id) { var el = document.getElementById(id); return el ? el.value.trim() : ''; };
      var body = {
        email_from_name: v('es-name') || null,
        email_from_address: v('es-addr') || null,
        email_smtp_encryption: document.getElementById('es-enc').value,
        mode: document.getElementById('es-mode').value,
        smtp_host: v('es-host'),
        smtp_port: parseInt(v('es-port'), 10) || 587,
        smtp_encryption: document.getElementById('es-smtp-enc').value,
        smtp_username: v('es-user'),
        graph_tenant_id: v('es-gt'),
        graph_client_id: v('es-gc')
      };
      var pw = document.getElementById('es-pass').value; if (pw) body.smtp_password = pw;
      var gs = document.getElementById('es-gs').value; if (gs) body.graph_client_secret = gs;
      try { await patch('/admin/email-settings', body); toast('Email settings saved.', 'success'); }
      catch (e) { toast(e.message || 'Save failed', 'error'); }
    };
    document.getElementById('es-test').onclick = async function () {
      var out = document.getElementById('es-out');
      out.innerHTML = '<span style="color:#64748B;">Sending…</span>';
      try {
        var r = await api().post('/admin/email-settings/test', {});
        out.innerHTML = '<div style="background:#DCFCE7;color:#15803D;padding:12px;border-radius:9px;">✓ Test sent to ' + esc(r.sent_to || 'you') + '. Check your inbox.</div>';
      } catch (e) {
        out.innerHTML = '<div style="background:#FEE2E2;color:#B91C1C;padding:12px;border-radius:9px;">' + esc(e.message || 'Send failed') + '</div>';
      }
    };
  }

  // PATCH/DELETE helpers (Api.request shape varies across builds)
  function authHeaders() {
    var headers = { 'Content-Type': 'application/json', 'Accept': 'application/json', 'Authorization': 'Bearer ' + sessionStorage.getItem('kt_token') };
    var aid = sessionStorage.getItem('kt_active_agency_id'); if (aid) headers['X-Active-Agency-Id'] = aid;
    return headers;
  }
  function apiBaseUrl() { return (window.KT && window.KT.API_BASE) || 'https://api.kiddietrac.com/api/v1'; }
  async function reqJson(method, path, body) {
    var opt = { method: method, headers: authHeaders() };
    if (body !== undefined) opt.body = JSON.stringify(body);
    var res = await fetch(apiBaseUrl() + path, opt);
    if (!res.ok) { var t = await res.text(); throw new Error((function () { try { return JSON.parse(t).message; } catch (e) { return 'HTTP ' + res.status; } })()); }
    var txt = await res.text(); return txt ? JSON.parse(txt) : {};
  }
  async function patch(path, body) { return reqJson('PATCH', path, body); }
  async function del(path) { return reqJson('DELETE', path); }

  function card(html) { return '<div class="kt-card">' + html + '</div>'; }
  function field(label, id, val, ph, type) {
    return '<label style="display:block;font-size:13px;font-weight:600;margin:14px 0 4px;">' + esc(label) + '</label>'
      + '<input id="' + id + '" type="' + (type || 'text') + '" value="' + esc(val) + '" placeholder="' + esc(ph || '') + '" '
      + 'style="width:100%;padding:10px;border:1.5px solid #E2E8F0;border-radius:8px;box-sizing:border-box;">';
  }

  // Register screens
  function reg() {
    if (!(window.KT && window.KT.Shell && window.KT.Shell.registerScreen)) { setTimeout(reg, 200); return; }
    ['agency_admin', 'platform_admin', 'centre_director'].forEach(function (r) {
      window.KT.Shell.registerScreen(r + ':quickbooks', renderQuickbooks);
      window.KT.Shell.registerScreen(r + ':email-settings', renderEmailSettings);
    });
  }
  reg();

  window.KT = window.KT || {};
  window.KT.renderQuickbooks = renderQuickbooks;
  window.KT.renderEmailSettings = renderEmailSettings;
})(window);
