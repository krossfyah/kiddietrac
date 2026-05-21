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
      + '</div></div>';

    document.getElementById('es-save').onclick = async function () {
      var body = {
        email_from_name: document.getElementById('es-name').value.trim() || null,
        email_from_address: document.getElementById('es-addr').value.trim() || null,
        email_smtp_encryption: document.getElementById('es-enc').value
      };
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
