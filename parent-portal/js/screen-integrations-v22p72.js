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
      // Wrapped so the browser / password managers can't autofill the empty
      // credential fields with the signed-in user's saved email + password
      // (which made this section look pre-filled though nothing was entered).
      + '<div data-kt-noautofill="1">'
      + field('Client ID', 'qc-id', cfg.client_id || '', 'ABxxxxxxxxxxxxxxxxxxxx')
      + field('Client Secret', 'qc-secret', '', cfg.has_secret ? '•••••••• (saved — leave blank to keep)' : 'Enter client secret', 'password')
      + '</div>'
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
      if (!await KT.confirm('Clear your Intuit credentials and disconnect QuickBooks?')) return;
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
      if (!await KT.confirm('Disconnect QuickBooks? Invoices will stop syncing.')) return;
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
      // ── Master mail/notifications switch (agency admin + super user) ──
      + '<div class="kt-card" style="max-width:680px;margin-bottom:18px;">'
      + '<label style="display:flex;align-items:center;justify-content:space-between;gap:16px;cursor:pointer;margin:0;">'
      + '<span><span style="display:block;font-size:14px;font-weight:700;color:#0F172A;">📬 Mail &amp; notifications</span>'
      + '<span style="display:block;font-size:12.5px;color:#64748B;margin-top:3px;">Master switch for every outgoing email and notification for this agency. Turn it <strong>OFF</strong> and nothing goes out to families or staff.</span></span>'
      + '<input type="checkbox" id="es-mailenabled" data-kt-switch="1"' + (s.mail_enabled !== false ? ' checked' : '') + '></label>'
      + '<div id="es-mailenabled-out" style="font-size:12px;margin-top:8px;min-height:14px;"></div>'
      + '</div>'
      // ── Onboarding-reminder daily email (configurable) ──
      + '<div class="kt-card" style="max-width:680px;margin-bottom:18px;">'
      + '<label style="display:flex;align-items:center;justify-content:space-between;gap:16px;cursor:pointer;margin:0;">'
      + '<span><span style="display:block;font-size:14px;font-weight:700;color:#0F172A;">📨 Onboarding reminders</span>'
      + '<span style="display:block;font-size:12.5px;color:#64748B;margin-top:3px;">A daily branded email to users who were <strong>invited but haven’t finished setting up</strong> their account — sent until they do.</span></span>'
      + '<input type="checkbox" id="es-onboardremind" data-kt-switch="1"' + (s.onboarding_reminders_enabled !== false ? ' checked' : '') + '></label>'
      + '<div style="display:flex;align-items:center;gap:10px;margin-top:12px;flex-wrap:wrap;">'
      + '<label for="es-onboardhour" style="font-size:12.5px;color:#475569;font-weight:600;">Send each day at</label>'
      + '<select id="es-onboardhour" style="padding:8px 10px;border:1.5px solid #E2E8F0;border-radius:8px;font-size:13px;">'
      + (function(){var o='';var cur=(s.onboarding_reminder_hour==null?7:s.onboarding_reminder_hour);for(var h=0;h<24;h++){var ap=h<12?'AM':'PM';var hh=h%12;if(hh===0)hh=12;o+='<option value="'+h+'"'+(cur===h?' selected':'')+'>'+hh+':00 '+ap+'</option>';}return o;})()
      + '</select><span style="font-size:11.5px;color:#94A3B8;">agency local time</span>'
      + '</div>'
      + '<div id="es-onboard-out" style="font-size:12px;margin-top:8px;min-height:14px;"></div>'
      + '</div>'
      // ── QR check-in nudge (manual-check-in reminder) ──
      + '<div class="kt-card" style="max-width:680px;margin-bottom:18px;">'
      + '<label style="display:flex;align-items:center;justify-content:space-between;gap:16px;cursor:pointer;margin:0;">'
      + '<span><span style="display:block;font-size:14px;font-weight:700;color:#0F172A;">📷 QR check-in nudges</span>'
      + '<span style="display:block;font-size:12.5px;color:#64748B;margin-top:3px;">A friendly weekly email to parents whose child was checked in/out <strong>manually more than twice in a week</strong>, encouraging the QR barcode — personalised with their &amp; their children’s names, and CC’d to you.</span></span>'
      + '<input type="checkbox" id="es-qrnudge" data-kt-switch="1"' + (s.manual_checkin_reminders_enabled !== false ? ' checked' : '') + '></label>'
      + '<div id="es-qrnudge-out" style="font-size:12px;margin-top:8px;min-height:14px;"></div>'
      + '</div>'
      // ── Closure notices: the immediate one, and the countdown ──
      + '<div class="kt-card" id="es-closures" style="max-width:680px;margin-bottom:18px;">'
      + '<label style="display:flex;align-items:center;justify-content:space-between;gap:16px;cursor:pointer;margin:0;">'
      + '<span><span style="display:block;font-size:14px;font-weight:700;color:#0F172A;">🗓 Closure reminders</span>'
      + '<span style="display:block;font-size:12.5px;color:#64748B;margin-top:3px;">Emails to <strong>parents and educators</strong> about an upcoming closure or holiday, with admins and directors BCC’d. Turn this off and no closure email of any kind is sent.</span></span>'
      + '<input type="checkbox" id="es-closerem" data-kt-switch="1"' + (s.closure_reminders_enabled !== false ? ' checked' : '') + '></label>'

      + '<div id="es-close-body" style="margin-top:14px;padding-top:14px;border-top:1px solid #E2E8F0;">'
      + '<label style="display:flex;align-items:center;justify-content:space-between;gap:16px;cursor:pointer;margin:0 0 12px;">'
      + '<span><span style="display:block;font-size:13.5px;font-weight:700;color:#0F172A;">Send as soon as it is added</span>'
      + '<span style="display:block;font-size:12px;color:#64748B;margin-top:2px;">Announces the closure the moment it is entered on the calendar. Leave off if you draft a year of dates at once and would rather only the countdown went out.</span></span>'
      + '<input type="checkbox" id="es-closenow" data-kt-switch="1"' + (s.closure_reminder_immediate !== false ? ' checked' : '') + '></label>'

      + '<div style="font-size:13px;font-weight:700;color:#0F172A;margin:14px 0 2px;">Then remind again</div>'
      + '<div style="font-size:12px;color:#64748B;margin-bottom:8px;">Counted in whole days before the first day of the closure, in your agency’s timezone. Each one is sent once.</div>'
      + '<div id="es-closedays" style="display:flex;gap:8px;flex-wrap:wrap;">'
      + (function () {
          var picked = String(s.closure_reminder_days == null ? '5,3,1' : s.closure_reminder_days)
            .split(',').map(function (d) { return parseInt(d, 10); });
          return [14, 7, 5, 3, 2, 1].map(function (d) {
            var on = picked.indexOf(d) !== -1;
            return '<label style="display:inline-flex;align-items:center;gap:6px;border:1.5px solid '
              + (on ? '#1F6080' : '#E2E8F0') + ';background:' + (on ? '#EFF6FF' : '#fff')
              + ';border-radius:999px;padding:6px 13px;font-size:13px;font-weight:600;color:'
              + (on ? '#1F6080' : '#475569') + ';cursor:pointer;">'
              + '<input type="checkbox" data-close-day="' + d + '"' + (on ? ' checked' : '')
              + ' style="margin:0;">' + (d === 1 ? 'Day before' : d + ' days') + '</label>';
          }).join('');
        })()
      + '</div>'
      + '<div id="es-closerem-out" style="font-size:12px;margin-top:10px;min-height:14px;"></div>'
      + '</div></div>'

      // ── Per-centre / per-room delivery control (pre-boarding switchboard) ──
      + '<div id="es-delivery" style="max-width:680px;margin-bottom:18px;"></div>'
      + '<div class="kt-card" style="max-width:680px;">'
      + field('From name', 'es-name', s.email_from_name || '', 'e.g. ' + esc(s.agency_name) + ' Childcare')
      + field('From address', 'es-addr', s.email_from_address || '', s.default_from || 'noreply@youragency.com', 'email')
      + '<label style="display:block;font-size:13px;font-weight:600;margin:14px 0 4px;">Encryption</label>'
      + '<select id="es-enc" style="width:100%;padding:10px;border:1.5px solid #E2E8F0;border-radius:8px;">'
      + '<option value="tls"' + (enc === 'tls' ? ' selected' : '') + '>TLS (recommended)</option>'
      + '<option value="ssl"' + (enc === 'ssl' ? ' selected' : '') + '>SSL</option>'
      + '<option value="none"' + (enc === 'none' ? ' selected' : '') + '>None</option>'
      + '</select>'
      + '<p style="color:#64748B;font-size:12px;margin:10px 0 0;">If left blank, the platform default sender (' + esc(s.default_from || 'system default') + ') is used.</p>'
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
      + '<p style="color:#64748B;font-size:12px;margin:8px 0 0;">Gmail &amp; Microsoft with 2-factor on need an <strong>app password</strong>, not your normal password.</p>'
      + '</div>'
      + '</div>'
      // ── Microsoft 365 mailbox (Graph) — powers the in-portal Email client ──
      + '<div class="kt-card" style="max-width:680px;margin-top:18px;">'
      + '<div class="kt-card-header"><h3 class="kt-card-title">📬 Mailbox — Microsoft 365 (email client)</h3></div>'
      + '<p style="color:#64748B;font-size:13px;margin:0 0 12px;">Connects the in-portal <strong>Email</strong> client to your Microsoft 365 mailboxes. Create an <strong>Azure AD app registration</strong> with Microsoft Graph Mail permissions, then paste its details here.</p>'
      + field('Directory (tenant) ID', 'es-gt', s.graph_tenant_id || '', '00000000-0000-0000-0000-000000000000')
      + field('Application (client) ID', 'es-gc', s.graph_client_id || '', '00000000-0000-0000-0000-000000000000')
      + field('Client secret' + (s.has_graph_secret ? ' (leave blank to keep)' : ''), 'es-gs', '', s.has_graph_secret ? '••••••••' : 'secret value', 'password')
      + '<p style="color:#64748B;font-size:12px;margin:8px 0 0;">Azure Portal → App registrations → Certificates &amp; secrets. Needs Graph <em>Mail.Read</em> / <em>Mail.Send</em>.</p>'
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

    // Per-centre / per-room delivery switchboard (async).
    loadDelivery();

    // Master mail switch — saves immediately on toggle (reverts on failure).
    var mailToggle = document.getElementById('es-mailenabled');
    if (mailToggle) mailToggle.onchange = async function () {
      var out = document.getElementById('es-mailenabled-out');
      mailToggle.disabled = true;
      try {
        await patch('/admin/email-settings', { mail_enabled: mailToggle.checked });
        if (out) { out.style.color = '#047857'; out.textContent = mailToggle.checked ? '✓ Mail enabled for this agency.' : '✓ Mail disabled — nothing will be sent.'; }
        if (window.KT && KT.toast) KT.toast(mailToggle.checked ? '📬' : '🔕', 'Mail ' + (mailToggle.checked ? 'enabled' : 'disabled'), 'Saved for this agency.', mailToggle.checked ? '#16A34A' : '#B45309');
      } catch (e) {
        mailToggle.checked = !mailToggle.checked;
        if (out) { out.style.color = '#B91C1C'; out.textContent = '✗ ' + (e.message || 'Could not save.'); }
      } finally { mailToggle.disabled = false; }
    };

    // Onboarding reminders — enabled toggle + send-hour, both save immediately.
    var obRemind = document.getElementById('es-onboardremind');
    var obHour = document.getElementById('es-onboardhour');
    var obOut = document.getElementById('es-onboard-out');
    var saveOb = async function (body, okMsg) {
      try {
        await patch('/admin/email-settings', body);
        if (obOut) { obOut.style.color = '#047857'; obOut.textContent = okMsg; }
      } catch (e) {
        if (obOut) { obOut.style.color = '#B91C1C'; obOut.textContent = '✗ ' + (e.message || 'Could not save.'); }
        throw e;
      }
    };
    if (obRemind) obRemind.onchange = async function () {
      obRemind.disabled = true;
      try { await saveOb({ onboarding_reminders_enabled: obRemind.checked }, obRemind.checked ? '✓ Onboarding reminders ON.' : '✓ Onboarding reminders OFF.'); }
      catch (e) { obRemind.checked = !obRemind.checked; }
      finally { obRemind.disabled = false; }
    };
    if (obHour) obHour.onchange = function () {
      saveOb({ onboarding_reminder_hour: parseInt(obHour.value, 10) }, '✓ Reminder time saved (' + obHour.options[obHour.selectedIndex].text + ').').catch(function () {});
    };

    // QR check-in nudge toggle — saves immediately.
    // Closure reminders live on their own tab but are wired here with the rest.
    try { wireClosureReminders(document, patch); } catch (e) { /* card absent for this role */ }

    var qrNudge = document.getElementById('es-qrnudge');
    var qrOut = document.getElementById('es-qrnudge-out');
    if (qrNudge) qrNudge.onchange = async function () {
      qrNudge.disabled = true;
      try {
        await patch('/admin/email-settings', { manual_checkin_reminders_enabled: qrNudge.checked });
        if (qrOut) { qrOut.style.color = '#047857'; qrOut.textContent = qrNudge.checked ? '✓ QR check-in nudges ON.' : '✓ QR check-in nudges OFF.'; }
      } catch (e) {
        qrNudge.checked = !qrNudge.checked;
        if (qrOut) { qrOut.style.color = '#B91C1C'; qrOut.textContent = '✗ ' + (e.message || 'Could not save.'); }
      } finally { qrNudge.disabled = false; }
    };

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

  /* ───────────── Centre / room email delivery switchboard ───────────── */
  async function loadDelivery() {
    var host = document.getElementById('es-delivery');
    if (!host) return;
    host.innerHTML = '<div class="kt-card" style="color:#64748B;">Loading centres…</div>';
    var d;
    try { d = await api().get('/admin/email-delivery'); }
    catch (e) { host.innerHTML = '<div class="kt-card" style="color:#B91C1C;">Could not load centres: ' + esc(e.message) + '</div>'; return; }

    var centres = d.centres || [];
    var sw = function (on, attr, id) {
      return '<input type="checkbox" data-kt-switch="1" ' + attr + '="' + id + '"' + (on ? ' checked' : '') + '>';
    };
    var rowsHtml = centres.length ? centres.map(function (c) {
      var roomsHtml = (c.rooms || []).map(function (rm) {
        return '<div style="display:flex;align-items:center;justify-content:space-between;gap:12px;padding:9px 12px 9px 34px;border-top:1px solid #F1F5F9;' + (c.email_enabled ? '' : 'opacity:.5;') + '">'
          + '<span style="font-size:13px;color:#334155;">🚪 ' + esc(rm.name) + (rm.active ? '' : ' <span style="color:#94A3B8;font-size:11px;">(inactive)</span>') + '</span>'
          + sw(rm.email_enabled, 'data-room', rm.id)
          + '</div>';
      }).join('');
      return '<div style="border:1.5px solid #E2E8F0;border-radius:12px;margin-bottom:10px;overflow:hidden;">'
        + '<div style="display:flex;align-items:center;justify-content:space-between;gap:12px;padding:12px;background:#F8FAFC;">'
        + '<span style="font-size:14px;font-weight:700;color:#0F172A;">🏫 ' + esc(c.name) + '</span>'
        + sw(c.email_enabled, 'data-centre', c.id)
        + '</div>'
        + (roomsHtml || '<div style="padding:9px 12px 9px 34px;color:#94A3B8;font-size:12px;">No rooms yet.</div>')
        + '</div>';
    }).join('') : '<div style="color:#64748B;font-size:13px;">No centres yet.</div>';

    host.innerHTML = '<div class="kt-card" style="max-width:680px;">'
      + '<div class="kt-card-header"><h3 class="kt-card-title">🎛️ Centre &amp; room email delivery</h3></div>'
      + '<p style="color:#64748B;font-size:12.5px;margin:0 0 12px;">Switch email on only for the centres and rooms that are live. A switched-<strong>off</strong> centre or room holds back every email to its educators and the parents of its children — ideal while pre-boarding a new agency. Rooms follow their centre: a room can’t send while its centre is off.</p>'
      + (d.master_enabled ? '' : '<div style="background:#FEF3C7;color:#92400E;padding:9px 11px;border-radius:8px;font-size:12.5px;margin-bottom:12px;">⚠️ The agency master switch above is <strong>OFF</strong>, so nothing sends regardless of these switches. Turn it on to use per-centre control.</div>')
      + rowsHtml
      + '<div id="ed-out" style="font-size:12px;margin-top:8px;min-height:14px;"></div>'
      + '</div>';

    host.querySelectorAll('[data-centre],[data-room]').forEach(function (cb) {
      cb.addEventListener('change', async function () {
        var isRoom = cb.hasAttribute('data-room');
        var id = cb.getAttribute(isRoom ? 'data-room' : 'data-centre');
        var out = document.getElementById('ed-out');
        cb.disabled = true;
        try {
          await patch('/admin/email-delivery/' + (isRoom ? 'room' : 'centre') + '/' + id, { enabled: cb.checked });
          if (window.KT && KT.toast) KT.toast(cb.checked ? '📬' : '🔕', 'Email ' + (cb.checked ? 'on' : 'off'), 'Saved for this agency.', cb.checked ? '#16A34A' : '#B45309');
          if (!isRoom) loadDelivery(); // re-render so the centre's rooms dim / undim
        } catch (e) {
          cb.checked = !cb.checked;
          if (out) { out.style.color = '#B91C1C'; out.textContent = '✗ ' + (e.message || 'Could not save.'); }
        } finally { cb.disabled = false; }
      });
    });
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


  /* ── Email settings sub-tabs ─────────────────────────────────────────────
     Built by relocating the rendered cards, not by rewriting the string that
     produces them: that string carries its own event wiring, and splitting it
     would put every handler on this screen at risk for a visual change.
     Unmatched cards fall through to General, so a card added later still shows. */
  var EMAIL_TABS = [
    { key: 'general',   label: 'General',   icon: '📬', match: /mail\s*&?(amp;)?\s*notifications|master switch/i },
    { key: 'outbound',  label: 'Outbound',  icon: '📤', match: /outbound email|smtp/i },
    { key: 'mailbox',   label: 'Mailbox',   icon: '📥', match: /mailbox|microsoft 365/i },
    { key: 'delivery',  label: 'Delivery',  icon: '🎛️', id: 'es-delivery' },
    { key: 'closures',  label: 'Closures',  icon: '🗓', id: 'es-closures' },
    { key: 'birthdays', label: 'Birthdays', icon: '🎂', birthdays: true }
  ];

  /* Closure reminders. Saved on change rather than behind a Save button, like the other
     switches on this screen — and the day pills re-render their own state so the ring
     matches what was actually stored. */
  function wireClosureReminders(root, patch) {
    var master = root.querySelector('#es-closerem');
    var now = root.querySelector('#es-closenow');
    var body = root.querySelector('#es-close-body');
    var out = root.querySelector('#es-closerem-out');
    if (!master || !body) { return; }

    function say(msg, bad) {
      if (!out) { return; }
      out.style.color = bad ? '#BE4038' : '#1E8E60';
      out.textContent = msg;
      setTimeout(function () { if (out.textContent === msg) { out.textContent = ''; } }, 2600);
    }
    function syncEnabled() {
      // The countdown cannot mean anything while the whole feature is off.
      body.style.opacity = master.checked ? '1' : '.45';
      body.style.pointerEvents = master.checked ? '' : 'none';
    }
    syncEnabled();

    function days() {
      return Array.prototype.slice.call(root.querySelectorAll('[data-close-day]'))
        .filter(function (c) { return c.checked; })
        .map(function (c) { return parseInt(c.getAttribute('data-close-day'), 10); })
        .sort(function (a, b) { return b - a; });
    }
    function paintPills() {
      root.querySelectorAll('[data-close-day]').forEach(function (c) {
        var on = c.checked, l = c.parentElement;
        l.style.borderColor = on ? '#1F6080' : '#E2E8F0';
        l.style.background = on ? '#EFF6FF' : '#fff';
        l.style.color = on ? '#1F6080' : '#475569';
      });
    }

    master.addEventListener('change', function () {
      syncEnabled();
      patch('/admin/email-settings', { closure_reminders_enabled: master.checked })
        .then(function () { say(master.checked ? '\u2713 Closure reminders on.' : '\u2713 Closure reminders off.'); })
        .catch(function (e) { say((e && e.message) || 'Could not save', true); });
    });

    if (now) {
      now.addEventListener('change', function () {
        patch('/admin/email-settings', { closure_reminder_immediate: now.checked })
          .then(function () { say(now.checked ? '\u2713 Will send when added.' : '\u2713 Countdown only.'); })
          .catch(function (e) { say((e && e.message) || 'Could not save', true); });
      });
    }

    root.querySelectorAll('[data-close-day]').forEach(function (c) {
      c.addEventListener('change', function () {
        paintPills();
        var d = days();
        patch('/admin/email-settings', { closure_reminder_days: d.join(',') })
          .then(function () {
            say(d.length ? '\u2713 Reminding at ' + d.join(', ') + ' days.' : '\u2713 No countdown reminders.');
          })
          .catch(function (e) { say((e && e.message) || 'Could not save', true); });
      });
    });
  }

  function applyEmailTabs(main) {
    var root = main.firstElementChild;
    if (!root || root.getAttribute('data-kt-tabbed')) { return; }
    root.setAttribute('data-kt-tabbed', '1');
    var hero = root.querySelector('.kt-page-hero');

    var panes = {};
    EMAIL_TABS.forEach(function (t) {
      var p = document.createElement('div');
      p.setAttribute('data-es-pane', t.key);
      p.style.display = 'none';
      panes[t.key] = p;
    });

    // Relocate. The delivery card is filled in asynchronously, so it is matched on its
    // placeholder id — by text it would still be empty at this point and fall through.
    Array.prototype.slice.call(root.children).forEach(function (child) {
      if (child === hero) { return; }
      var key = 'general';
      for (var i = 0; i < EMAIL_TABS.length; i++) {
        var t = EMAIL_TABS[i];
        if (t.id && child.id === t.id) { key = t.key; break; }
        if (t.match && t.match.test(child.textContent || '')) { key = t.key; break; }
      }
      panes[key].appendChild(child);
    });

    var bar = document.createElement('div');
    bar.style.cssText = 'display:flex;gap:6px;flex-wrap:wrap;border-bottom:1px solid #E2E8F0;margin:0 0 18px;padding:0 0 2px;';
    bar.innerHTML = EMAIL_TABS.map(function (t) {
      return '<button type="button" data-es-tab="' + t.key + '" style="background:none;border:0;border-bottom:2px solid transparent;'
        + 'padding:9px 13px;font-size:13.5px;font-weight:700;color:#64748B;cursor:pointer;border-radius:8px 8px 0 0;">'
        + t.icon + ' ' + t.label + '</button>';
    }).join('');

    if (hero && hero.nextSibling) { root.insertBefore(bar, hero.nextSibling); }
    else { root.appendChild(bar); }
    EMAIL_TABS.forEach(function (t) { root.appendChild(panes[t.key]); });

    var birthdaysLoaded = false;
    function show(key) {
      EMAIL_TABS.forEach(function (t) {
        panes[t.key].style.display = (t.key === key) ? '' : 'none';
        var b = bar.querySelector('[data-es-tab="' + t.key + '"]');
        if (b) {
          b.style.color = (t.key === key) ? '#0F172A' : '#64748B';
          b.style.borderBottomColor = (t.key === key) ? '#1F6FB2' : 'transparent';
        }
      });
      try { sessionStorage.setItem('kt_es_tab', key); } catch (e) {}
      // Loaded on first view rather than up front: it is its own API call, and most
      // visits to this screen are not about birthdays.
      if (key === 'birthdays' && !birthdaysLoaded) {
        birthdaysLoaded = true;
        if (window.KT && KT.BirthdaySettings && KT.BirthdaySettings.render) {
          KT.BirthdaySettings.render(panes.birthdays);
        } else {
          panes.birthdays.innerHTML = '<div class="kt-card" style="max-width:680px;color:#64748B;">Birthday settings could not be loaded.</div>';
        }
      }
    }

    bar.querySelectorAll('[data-es-tab]').forEach(function (b) {
      b.addEventListener('click', function () { show(b.getAttribute('data-es-tab')); });
    });

    var want = 'general';
    try { want = sessionStorage.getItem('kt_es_tab') || 'general'; } catch (e) {}
    if (!panes[want]) { want = 'general'; }
    show(want);
  }

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
      // Wrapped rather than folded into renderEmailSettings: the tabs are a layer over
      // whatever that function rendered, and keeping them separate means the render path
      // is unchanged if the tabs are ever dropped.
      window.KT.Shell.registerScreen(r + ':email-settings', async function (main, ctx) {
        await renderEmailSettings(main, ctx);
        try { applyEmailTabs(main); } catch (e) { /* an un-tabbed screen still works */ }
      });
    });
  }
  reg();

  window.KT = window.KT || {};
  window.KT.renderQuickbooks = renderQuickbooks;
  window.KT.renderEmailSettings = renderEmailSettings;
})(window);
