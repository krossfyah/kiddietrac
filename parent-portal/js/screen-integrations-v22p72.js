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
      + KT.secretField({ id: 'qc-secret', label: 'Client Secret', stored: !!cfg.has_secret,
          whatItIs: 'client secret', labelStyle: 'display:block;font-size:13px;font-weight:600;margin:14px 0 4px;', inputStyle: 'width:100%;padding:10px;border:1.5px solid #E2E8F0;border-radius:8px;box-sizing:border-box;' })
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
      /* Reminds an educator (and BCCs admins/directors) when daily moments are logged
         for a child who is not signed in. On by default — a safety check that ships
         off stays off. */
      + '<label style="display:flex;align-items:center;justify-content:space-between;gap:12px;padding:11px 0;border-top:1px solid #F1F5F9;">'
      + '<span><span style="font-weight:700;font-size:13.5px;color:#0F172A;">⏰ Attendance reminders</span>'
      + '<span style="display:block;font-size:12px;color:#64748B;margin-top:2px;">'
      + 'Email an educator when they log daily moments for a child who is not signed in. Admins and directors are BCC’d.</span></span>'
      + '<input type="checkbox" id="es-attendreminders" data-kt-switch="1"' + (s.attendance_reminders !== false ? ' checked' : '') + '></label>'
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

      /* Statutory holidays. Deliberately inside the closure card: what this switch does is
         write closures, and it is announced by the same nightly pass. */
      + '<div style="margin-top:16px;padding-top:14px;border-top:1px solid #E2E8F0;">'
      + '<label style="display:flex;align-items:center;justify-content:space-between;gap:16px;cursor:pointer;margin:0 0 4px;">'
      + '<span><span style="display:block;font-size:13.5px;font-weight:700;color:#0F172A;">Close automatically on statutory holidays</span>'
      + '<span style="display:block;font-size:12px;color:#64748B;margin-top:2px;">Adds each public holiday to every centre\u2019s calendar as a closure, so the schedule, autofill and attendance all treat it as a day off. A date you have already entered yourself is never overwritten.</span></span>'
      + '<input type="checkbox" id="es-stathol" data-kt-switch="1"' + (s.stat_holidays_enabled ? ' checked' : '') + '></label>'

      + '<div id="es-stathol-body" style="margin-top:12px;' + (s.stat_holidays_enabled ? '' : 'display:none;') + '">'
      + '<div style="font-size:13px;font-weight:700;color:#0F172A;margin:6px 0 2px;">Holiday calendar</div>'
      + '<select id="es-stathol-country" style="width:100%;max-width:340px;padding:9px 11px;border:1px solid #CBD5E1;border-radius:8px;font-size:13.5px;background:#fff;color:#0F172A;">'
      + '<option value="CA"' + (s.stat_holidays_country !== 'US' ? ' selected' : '') + '>Canada \u2014 Ontario public holidays</option>'
      + '<option value="US"' + (s.stat_holidays_country === 'US' ? ' selected' : '') + '>United States \u2014 federal holidays</option>'
      + '</select>'

      + (function () {
          /* Only Canada has genuinely optional days: the ESA does not oblige a childcare
             operator to close for these, and agencies differ. Offering them as tick-boxes
             is more honest than picking for them. */
          var avail = s.stat_holidays_optional_available || {};
          var keys = Object.keys(avail);
          if (!keys.length) { return ''; }
          var picked = s.stat_holidays_optional || [];
          return '<div style="font-size:13px;font-weight:700;color:#0F172A;margin:14px 0 2px;">Also close for</div>'
            + '<div style="font-size:12px;color:#64748B;margin-bottom:8px;">Not required by the Employment Standards Act \u2014 tick the ones your agency observes.</div>'
            + '<div id="es-stathol-opt" style="display:flex;gap:8px;flex-wrap:wrap;">'
            + keys.map(function (k) {
                var on = picked.indexOf(k) !== -1;
                return '<label style="display:inline-flex;align-items:center;gap:6px;border:1.5px solid '
                  + (on ? '#1F6080' : '#E2E8F0') + ';background:' + (on ? '#EFF6FF' : '#fff')
                  + ';border-radius:999px;padding:6px 13px;font-size:13px;font-weight:600;color:'
                  + (on ? '#1F6080' : '#475569') + ';cursor:pointer;">'
                  + '<input type="checkbox" data-stathol-opt="' + esc(k) + '"' + (on ? ' checked' : '')
                  + ' style="margin:0;">' + esc(avail[k]) + '</label>';
              }).join('')
            + '</div>';
        })()

      + '<div style="font-size:13px;font-weight:700;color:#0F172A;margin:14px 0 2px;">Tell everyone</div>'
      + '<div style="font-size:12px;color:#64748B;margin-bottom:8px;">Counted in <strong>working days at each centre</strong>, so the note lands on a day people are actually in — a Monday holiday is announced on the Friday, never over the weekend. Parents and educators get a note about the holiday, including what the day is for and who their child is with.</div>'
      + '<div id="es-statholdays" style="display:flex;gap:8px;flex-wrap:wrap;">'
      + (function () {
          var picked = String(s.stat_holidays_notice_days == null ? '1' : s.stat_holidays_notice_days)
            .split(',').map(function (d) { return parseInt(d, 10); });
          return [7, 3, 2, 1].map(function (d) {
            var on = picked.indexOf(d) !== -1;
            return '<label style="display:inline-flex;align-items:center;gap:6px;border:1.5px solid '
              + (on ? '#1F6080' : '#E2E8F0') + ';background:' + (on ? '#EFF6FF' : '#fff')
              + ';border-radius:999px;padding:6px 13px;font-size:13px;font-weight:600;color:'
              + (on ? '#1F6080' : '#475569') + ';cursor:pointer;">'
              + '<input type="checkbox" data-stathol-day="' + d + '"' + (on ? ' checked' : '')
              + ' style="margin:0;">' + (d === 1 ? 'Last working day' : d + ' working days') + '</label>';
          }).join('');
        })()
      + '</div>'
      + '<div id="es-stathol-out" style="font-size:12px;margin-top:10px;min-height:14px;"></div>'
      + '</div></div>'
      + '</div></div>'

      /* Missed-chat email timing. Lives with the other mail settings, where an agency
         admin can actually reach it — it used to sit behind the pencil icon on the
         platform Agencies screen, which the nav does not even offer to a platform admin
         (app-v2-shell only adds "Agencies" for NON platform admins), so the person most
         likely to change it could not find it. (Anthony, 2026-09-09) */
      + '<div class="kt-card" style="max-width:680px;margin-bottom:18px;">'
      +   '<div style="font-size:14px;font-weight:700;color:#0F172A;">&#9200; Unread chat messages</div>'
      +   '<div style="font-size:12.5px;color:#64748B;margin:3px 0 12px;line-height:1.5;">'
      +     'How long a chat message may sit <strong>unread</strong> before the recipient is emailed about it. '
      +     'Everything still waiting is gathered into <strong>one email per person</strong>, and nobody is told '
      +     'twice about the same message. Set it longer for an agency that finds email noisy.'
      +   '</div>'
      +   '<label style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;font-size:13px;color:#334155;">'
      +     '<span>Email after</span>'
      +     '<input type="number" id="es-chat-delay" min="1" max="1440" step="1" value="'
      +       esc(String(s.chat_email_delay_minutes || 5))
      +       '" style="width:96px;padding:9px 11px;border:1px solid #E2E8F0;border-radius:9px;font-size:14px;">'
      +     '<span>minutes</span>'
      +   '</label>'
      + '</div>'

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
      + KT.secretField({ id: 'es-pass', label: 'Password', stored: !!s.has_smtp_password,
          whatItIs: 'password', placeholder: s.has_smtp_password ? '' : 'app password',
          labelStyle: 'display:block;font-size:13px;font-weight:600;margin:14px 0 4px;', inputStyle: 'width:100%;padding:10px;border:1.5px solid #E2E8F0;border-radius:8px;box-sizing:border-box;' })
      + '<p style="color:#64748B;font-size:12px;margin:8px 0 0;">Gmail &amp; Microsoft with 2-factor on need an <strong>app password</strong>, not your normal password.</p>'
      + '</div>'
      + '</div>'
      // ── Microsoft 365 mailbox (Graph) — powers the in-portal Email client ──
      + '<div class="kt-card" style="max-width:680px;margin-top:18px;">'
      + '<div class="kt-card-header"><h3 class="kt-card-title">📬 Mailbox — Microsoft 365 (email client)</h3></div>'
      + '<p style="color:#64748B;font-size:13px;margin:0 0 12px;">Connects the in-portal <strong>Email</strong> client to your Microsoft 365 mailboxes. Create an <strong>Azure AD app registration</strong> with Microsoft Graph Mail permissions, then paste its details here.</p>'
      + field('Directory (tenant) ID', 'es-gt', s.graph_tenant_id || '', '00000000-0000-0000-0000-000000000000')
      + field('Application (client) ID', 'es-gc', s.graph_client_id || '', '00000000-0000-0000-0000-000000000000')
      + KT.secretField({ id: 'es-gs', label: 'Client secret', stored: !!s.has_graph_secret,
          whatItIs: 'client secret', labelStyle: 'display:block;font-size:13px;font-weight:600;margin:14px 0 4px;', inputStyle: 'width:100%;padding:10px;border:1.5px solid #E2E8F0;border-radius:8px;box-sizing:border-box;' })
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
        var arT = document.getElementById('es-attendreminders');
        await patch('/admin/email-settings', {
          mail_enabled: mailToggle.checked,
          attendance_reminders: arT ? arT.checked : undefined,
        });
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
    try { wireStatHolidays(document, patch); } catch (e) { /* card absent for this role */ }

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
      /* Sent only when it is a usable number, so a blank or nonsense box leaves the
         stored value alone rather than resetting the agency to the default. */
      var _cd = parseInt((document.getElementById('es-chat-delay') || {}).value, 10);
      if (_cd >= 1 && _cd <= 1440) { body.chat_email_delay_minutes = _cd; }
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
    { key: 'birthdays', label: 'Birthdays', icon: '🎂', birthdays: true },
    { key: 'immunization', label: 'Immunization', icon: '🩹', immunization: true },
    /* The Reseller "Email" screen, folded in here. requiresEl means the tab only
       appears when its container was actually rendered — platform admins only, since
       nobody else can load /platform/mail-settings. */
    { key: 'platform', label: 'Platform mail', icon: '🌐', id: 'es-platform-mail', requiresEl: true }
  ];

  /* Statutory holidays. Same save-on-change pattern as the closure reminders below.
     Turning this on writes closures, so the confirmation says how many were created —
     "saved" would not tell an admin whether anything actually happened. */
  function wireStatHolidays(root, patch) {
    var master = root.querySelector('#es-stathol');
    var body = root.querySelector('#es-stathol-body');
    var out = root.querySelector('#es-stathol-out');
    if (!master || !body) { return; }

    function say(msg, bad) {
      if (!out) { return; }
      out.style.color = bad ? '#BE4038' : '#1E8E60';
      out.textContent = msg;
      setTimeout(function () { if (out.textContent === msg) { out.textContent = ''; } }, 4000);
    }
    function paint(sel) {
      root.querySelectorAll(sel).forEach(function (c) {
        var on = c.checked, l = c.parentElement;
        l.style.borderColor = on ? '#1F6080' : '#E2E8F0';
        l.style.background = on ? '#EFF6FF' : '#fff';
        l.style.color = on ? '#1F6080' : '#475569';
      });
    }
    function picked(sel, attr) {
      return Array.prototype.slice.call(root.querySelectorAll(sel))
        .filter(function (c) { return c.checked; })
        .map(function (c) { return c.getAttribute(attr); });
    }

    master.addEventListener('change', function () {
      body.style.display = master.checked ? '' : 'none';
      patch('/admin/email-settings', { stat_holidays_enabled: master.checked })
        .then(function () {
          say(master.checked
            ? 'On \u2014 holidays will be added to every centre\u2019s calendar.'
            : 'Off \u2014 no new holiday closures will be created.');
        })
        .catch(function () { say('Could not save', true); });
    });

    var country = root.querySelector('#es-stathol-country');
    if (country) {
      country.addEventListener('change', function () {
        patch('/admin/email-settings', { stat_holidays_country: country.value })
          /* The optional list differs by country, so the card is re-rendered rather than
             left showing tick-boxes that no longer apply. */
          .then(function () { say('Saved \u2014 reopen this tab to see that calendar\u2019s options.'); })
          .catch(function () { say('Could not save', true); });
      });
    }

    root.querySelectorAll('[data-stathol-opt]').forEach(function (c) {
      c.addEventListener('change', function () {
        paint('[data-stathol-opt]');
        patch('/admin/email-settings', { stat_holidays_optional: picked('[data-stathol-opt]', 'data-stathol-opt') })
          .then(function () { say('Saved'); })
          .catch(function () { say('Could not save', true); });
      });
    });

    root.querySelectorAll('[data-stathol-day]').forEach(function (c) {
      c.addEventListener('change', function () {
        paint('[data-stathol-day]');
        var d = picked('[data-stathol-day]', 'data-stathol-day').join(',');
        patch('/admin/email-settings', { stat_holidays_notice_days: d || '1' })
          .then(function () { say(d ? 'Saved' : 'Kept the day-before notice \u2014 at least one is needed.'); })
          .catch(function () { say('Could not save', true); });
      });
    });
  }

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
    /* A tab whose container was never rendered must not appear as an empty pane. */
    var TABS = EMAIL_TABS.filter(function (t) {
      return !t.requiresEl || (main && main.querySelector('#' + t.id));
    });
    var root = main.firstElementChild;
    if (!root || root.getAttribute('data-kt-tabbed')) { return; }
    root.setAttribute('data-kt-tabbed', '1');
    var hero = root.querySelector('.kt-page-hero');

    var panes = {};
    TABS.forEach(function (t) {
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
      for (var i = 0; i < TABS.length; i++) {
        var t = TABS[i];
        if (t.id && child.id === t.id) { key = t.key; break; }
        if (t.match && t.match.test(child.textContent || '')) { key = t.key; break; }
      }
      panes[key].appendChild(child);
    });

    var bar = document.createElement('div');
    bar.classList.add('kt-subtabs');
    bar.style.cssText = 'display:flex;gap:6px;flex-wrap:wrap;border-bottom:1px solid #E2E8F0;margin:0 0 18px;padding:0 0 2px;';
    bar.innerHTML = TABS.map(function (t) {
      return '<button type="button" data-es-tab="' + t.key + '" style="background:none;border:0;border-bottom:2px solid transparent;'
        + 'padding:9px 13px;font-size:13.5px;font-weight:700;color:#64748B;cursor:pointer;border-radius:8px 8px 0 0;">'
        + t.icon + ' ' + t.label + '</button>';
    }).join('');

    if (hero && hero.nextSibling) { root.insertBefore(bar, hero.nextSibling); }
    else { root.appendChild(bar); }
    TABS.forEach(function (t) { root.appendChild(panes[t.key]); });

    var birthdaysLoaded = false;
    var immunizationLoaded = false;
    function show(key) {
      TABS.forEach(function (t) {
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
      // Same reasoning as birthdays: its own API call, and most visits here are
      // about something else.
      if (key === 'immunization' && !immunizationLoaded) {
        immunizationLoaded = true;
        if (window.KT && KT.ImmunizationSettings && KT.ImmunizationSettings.render) {
          KT.ImmunizationSettings.render(panes.immunization);
        } else {
          panes.immunization.innerHTML = '<div class="kt-card" style="max-width:680px;color:#64748B;">Immunization reminder settings could not be loaded.</div>';
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


  /* ───────────── SMS and voice settings (per agency) ─────────────
     The credentials used to live in .env: one Twilio account for the whole platform,
     and a shell session to change a number. An agency brings its own account and its
     own number, so it sets them here. Every secret is write-only — the API reports
     whether one is stored and never what it is, so this screen can never show one back,
     to an admin or to anyone reading over their shoulder.

     TWO CARRIERS since 2026-09-10. Twilio and Telnyx sit side by side; the agency says
     which one sends and whether the other catches a refusal. Telnyx also carries VOICE,
     which is the second tab, and its one API key covers both — which is why the key is
     asked for on the text tab and the voice tab only asks for what is voice-specific.

     ── HOW THIS IS LAID OUT, AND WHY ──
     Two levels of tab, because there are two different questions here:

        Text messages / Voice calls     — which CHANNEL you are setting up
          └ Twilio / Telnyx             — which CARRIER's credentials you are typing

     The carriers get a page each rather than two cards stacked down one long scroll:
     they have no fields in common, and reading past nine Twilio boxes to reach the
     Telnyx ones invites filling in the wrong set.

     The "which carrier sends" card stays ABOVE the carrier tabs, because it is a
     decision about the PAIR and belongs to neither of them. Separating the pages makes
     one new mistake possible — carefully filling in Telnyx and never actually switching
     to it — so the carrier that is currently sending is marked on its own tab.

     BOTH PANES ARE ALWAYS IN THE DOM; the hidden one is only display:none. Save reads
     every field on the screen regardless of which tab is showing, so setting up Telnyx
     cannot quietly discard a number typed on the Twilio page. */
  /* Bumped by hand whenever this screen changes shape. It is printed under the hero so
     "which version am I looking at" is answerable from the screen instead of from
     devtools — a stale cached copy is otherwise indistinguishable from a bug. */
  var SCREEN_BUILD = '2026-09-10 carrier-subtabs + test-log';

  async function renderSmsSettings(main) {
    main.setAttribute('data-kt-pretty', '1');
    main.innerHTML = '<div style="padding:24px;">Loading…</div>';

    var s;
    try { s = await api().get('/admin/sms-settings'); }
    catch (e) {
      main.innerHTML = '<div class="kt-card" style="margin:24px;padding:32px;text-align:center;color:#B45309;">'
        + 'Could not load SMS settings' + (e && e.message ? ' — ' + esc(e.message) : '') + '.</div>';
      return;
    }

    var t = s.telnyx || {};
    var fld = 'width:100%;box-sizing:border-box;height:34px;padding:0 11px;border:1px solid #E2E8F0;'
      + 'border-radius:9px;font:inherit;font-size:14px;';
    var lbl = 'display:block;font-size:11.5px;font-weight:800;color:#64748B;text-transform:uppercase;'
      + 'letter-spacing:.4px;margin-bottom:5px;';
    var hint = 'font-size:11.5px;color:#94A3B8;margin-top:4px;';
    var mono = 'display:block;background:#F8FAFC;border:1px solid #E2E8F0;border-radius:8px;padding:9px 11px;'
      + 'font-size:12.5px;color:#0F172A;word-break:break-all;';

    function chip(on, label) {
      return '<span style="display:inline-flex;align-items:center;gap:6px;padding:4px 10px;border-radius:999px;'
        + 'font-size:12px;font-weight:800;background:' + (on ? '#ECFDF5' : '#F1F5F9') + ';color:'
        + (on ? '#065F46' : '#64748B') + ';border:1px solid ' + (on ? '#A7F3D0' : '#E2E8F0') + ';">'
        + (on ? '✓' : '○') + ' ' + esc(label) + '</span>';
    }
    function note(kind, html) {
      var c = kind === 'ok' ? ['#ECFDF5', '#A7F3D0', '#065F46']
        : kind === 'bad' ? ['#FEE2E2', '#FECACA', '#991B1B'] : ['#FEF3C7', '#FDE68A', '#92400E'];
      return '<div style="background:' + c[0] + ';border:1px solid ' + c[1] + ';color:' + c[2] + ';border-radius:12px;'
        + 'padding:11px 14px;font-size:13px;font-weight:600;">' + html + '</div>';
    }

    /* Two switches decide whether anything sends, and they are not the same one. An
       admin who does not know that spends a long time wondering why nothing arrives. */
    var offNote = s.notifications_enabled ? ''
      : '<div style="margin-top:10px;">' + note('bad', 'This agency has all notifications switched off in '
        + 'Email settings, so nothing will send or ring even once a carrier is configured.') + '</div>';

    // Sends per carrier over the last 30 days — the first question after switching.
    var stats = '';
    try {
      var rb = s.recent_by_provider || {};
      var bits = Object.keys(rb).map(function (p) {
        var counts = rb[p] || {};
        return esc(p) + ' — ' + Object.keys(counts).map(function (k) {
          return counts[k] + ' ' + esc(k);
        }).join(', ');
      });
      if (bits.length) {
        stats = '<div style="' + hint + 'margin-top:12px;">Last 30 days: ' + bits.join(' · ') + '</div>';
      }
    } catch (e) { /* a decoration must never break the screen it decorates */ }

    function radio(id, value, checked, label, sub) {
      return '<label for="' + id + '" style="display:flex;gap:10px;align-items:flex-start;padding:10px 12px;'
        + 'border:1.5px solid ' + (checked ? '#1F6080' : '#E2E8F0') + ';border-radius:10px;cursor:pointer;'
        + 'background:' + (checked ? '#F0F7FA' : '#fff') + ';flex:1 1 220px;" data-prov-card="' + value + '">'
        + '<input type="radio" name="sms-provider" id="' + id + '" value="' + value + '"'
        + (checked ? ' checked' : '') + ' style="margin-top:2px;width:16px;height:16px;cursor:pointer;">'
        + '<span><span style="display:block;font-size:14px;font-weight:800;color:#0F172A;">' + label + '</span>'
        + '<span style="display:block;font-size:11.5px;color:#64748B;margin-top:2px;">' + sub + '</span></span></label>';
    }

    /* THE CARRIER TABS.
       These began as small underlined text between two cards, matching the treatment
       the Email settings strip uses. That was too quiet: sitting under a row of filled
       pill tabs and directly above a card full of fields, the row did not read as a
       control at all — it read as a caption, and the page looked like one long form
       with Twilio at the top. So they are a bordered segmented control with a label in
       front of them, deliberately louder than the tabs above rather than quieter.

       Kept visually distinct from the channel tabs above all the same: those are solid
       pills, these are a joined segment group inside a tray. Two rows of identical
       tabs stacked on each other read as one broken row. */
    function carrierTab(value, label, first, last) {
      var radius = first ? '9px 0 0 9px' : (last ? '0 9px 9px 0' : '0');
      return '<button type="button" data-cx-tab="' + value + '" style="appearance:none;'
        + 'background:#fff;border:1px solid #CBD5E1;border-radius:' + radius + ';'
        + (first ? '' : 'margin-left:-1px;')
        + 'padding:0 16px;height:34px;font:inherit;font-size:13.5px;font-weight:800;'
        + 'color:#475569;cursor:pointer;display:inline-flex;align-items:center;gap:8px;">'
        + esc(label)
        + '<span data-cx-sending="' + value + '" style="display:none;font-size:10px;font-weight:800;'
        + 'text-transform:uppercase;letter-spacing:.4px;background:#ECFDF5;color:#065F46;'
        + 'border:1px solid #A7F3D0;border-radius:999px;padding:2px 6px;">sending</span></button>';
    }

    main.innerHTML = ''
      + '<div style="padding:24px;max-width:880px;margin:0 auto;">'
      +   '<div class="kt-page-hero"><h2>📡 Carrier settings</h2>'
      +     '<p>The carriers this agency sends text messages and places announcement calls through.</p>'
      +     '<div style="margin-top:6px;font-size:11px;opacity:.6;">Screen build '
      +       esc(SCREEN_BUILD) + '</div></div>'

      /* ── channel tabs ── */
      +   '<div style="display:flex;gap:6px;margin-top:16px;flex-wrap:wrap;" id="sv-tabs">'
      +     '<button type="button" data-sv-tab="text" style="height:32px;padding:0 14px;border-radius:9px;'
      +       'border:1px solid #1F6080;background:#1F6080;color:#fff;font-weight:800;font-size:13px;cursor:pointer;">'
      +       'Text messages</button>'
      +     '<button type="button" data-sv-tab="voice" style="height:32px;padding:0 14px;border-radius:9px;'
      +       'border:1px solid #CBD5E1;background:#fff;color:#334155;font-weight:800;font-size:13px;cursor:pointer;">'
      +       'Voice calls</button>'
      +   '</div>'

      /* ══════════════════════ TEXT ══════════════════════ */
      +   '<div data-sv-pane="text">'

      +     '<div class="kt-card" style="margin-top:14px;">'
      +       '<div style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:14px;">'
      +         chip(s.twilio_ready, 'Twilio ' + (s.twilio_ready ? 'ready' : 'not set up'))
      +         chip(s.telnyx_ready, 'Telnyx ' + (s.telnyx_ready ? 'ready' : 'not set up'))
      +         chip(s.sms_enabled, 'Texting ' + (s.sms_enabled ? 'on' : 'off'))
      +       '</div>'
      +       '<div style="font-size:13px;font-weight:800;color:#0F172A;">Which carrier sends</div>'
      +       '<div style="' + hint + 'margin-bottom:10px;">Both can be set up at once. Only the one chosen here sends.</div>'
      +       '<div style="display:flex;gap:10px;flex-wrap:wrap;">'
      +         radio('sms-prov-twilio', 'twilio', s.provider !== 'telnyx', 'Twilio', 'The original carrier')
      +         radio('sms-prov-telnyx', 'telnyx', s.provider === 'telnyx', 'Telnyx', 'Also carries voice calls')
      +       '</div>'
      +       '<label style="display:flex;align-items:flex-start;gap:10px;margin-top:14px;font-size:13.5px;'
      +         'color:#0F172A;cursor:pointer;">'
      +         '<input type="checkbox" id="sms-failover"' + (s.failover ? ' checked' : '')
      +           ' style="width:18px;height:18px;cursor:pointer;margin-top:1px;">'
      +         '<span><b>If that carrier refuses, try the other one.</b>'
      +           '<span style="display:block;font-size:11.5px;color:#64748B;margin-top:2px;">'
      +           'Only ever engages when the other carrier is fully set up, and only on a refusal — a message '
      +           'already accepted is never sent twice.</span></span></label>'
      +       '<label style="display:flex;align-items:center;gap:10px;margin-top:14px;font-size:14px;font-weight:600;'
      +         'color:#0F172A;cursor:pointer;">'
      +         '<input type="checkbox" id="sms-enabled"' + (s.sms_enabled ? ' checked' : '')
      +           ' style="width:18px;height:18px;cursor:pointer;">'
      +         'Send text messages for this agency</label>'
      +       offNote
      +       stats
      +     '</div>'

      /* ── carrier tabs ── */
      +     '<div id="sms-carrier-tabs" style="display:flex;align-items:center;gap:12px;flex-wrap:wrap;'
      +       'margin:22px 0 0;padding:12px 14px;background:#F1F5F9;border:1px solid #E2E8F0;'
      +       'border-radius:12px;">'
      +       '<span style="font-size:11.5px;font-weight:800;color:#64748B;text-transform:uppercase;'
      +         'letter-spacing:.5px;">Set up carrier</span>'
      +       '<span style="display:inline-flex;">'
      +         carrierTab('twilio', 'Twilio', true, false)
      +         carrierTab('telnyx', 'Telnyx', false, true)
      +       '</span>'
      +     '</div>'

      /* ── Twilio ── */
      +     '<div data-cx-pane="twilio">'
      +       '<div class="kt-card" style="margin-top:14px;">'
      +         '<div style="' + hint + 'margin:0 0 12px;">Twilio Console → Account Info.</div>'
      +         '<div><label style="' + lbl + '">Account SID</label>'
      +           '<input id="sms-sid" style="' + fld + '" placeholder="AC…" value="' + esc(s.account_sid || '') + '">'
      +           '<div style="' + hint + '">Starts with <b>AC</b>. An OAuth client id (OQ…) is a different '
      +             'credential and will not work.</div></div>'
      +         '<div style="margin-top:14px;">'
      +           KT.secretField({ id: 'sms-token', label: 'Auth token',
                    stored: !!s.has_auth_token, whatItIs: 'auth token',
                    labelStyle: lbl, inputStyle: fld })
      +         '</div>'
      +         '<div style="margin-top:18px;padding-top:14px;border-top:1px solid #EDF2F7;">'
      +           '<div style="font-size:13px;font-weight:800;color:#0F172A;">API key <span style="font-weight:600;'
      +             'color:#64748B;">— recommended, and used in preference to the auth token above</span></div>'
      +           '<div style="' + hint + '">Console → Account → API keys &amp; tokens → <b>Create API key</b>. '
      +             'A key can be revoked on its own without resetting the whole account, and the secret is shown '
      +             'only once — copy it before closing the dialog.</div>'
      +           '<div style="margin-top:12px;"><label style="' + lbl + '">API key SID</label>'
      +             '<input id="sms-keysid" style="' + fld + '" placeholder="SK…" value="' + esc(s.api_key_sid || '') + '"></div>'
      +           '<div style="margin-top:12px;">'
      +             KT.secretField({ id: 'sms-keysecret', label: 'API key secret',
                      stored: !!s.has_api_key_secret, whatItIs: 'secret',
                      labelStyle: lbl, inputStyle: fld })
      +           '</div>'
      +         '</div>'
      +         '<div style="margin-top:14px;"><label style="' + lbl + '">Send from</label>'
      +           '<input id="sms-from" style="' + fld + '" placeholder="+16475550123" value="' + esc(s.from || '') + '">'
      +           '<div style="' + hint + '">The Twilio number in full international form, or a Messaging Service '
      +             'SID (MG…).</div></div>'
      +         '<div style="margin-top:14px;"><label style="' + lbl + '">Replies and STOP webhook</label>'
      +           '<code style="' + mono + '">' + esc(s.inbound_webhook || '') + '</code>'
      +           '<div style="' + hint + '">In Twilio, set the number\'s incoming-message webhook to this. Without '
      +             'it, somebody texting STOP is not recorded as opted out — which carriers treat as a violation.</div></div>'
      +       '</div>'
      +     '</div>'

      /* ── Telnyx ── */
      +     '<div data-cx-pane="telnyx" style="display:none;">'
      +       '<div class="kt-card" style="margin-top:14px;">'
      +         '<div style="' + hint + 'margin:0 0 12px;">Telnyx Mission Control → API Keys. The same key covers '
      +           'text messages and voice calls, so it is only asked for here — the Voice calls tab reads it '
      +           'from this page.</div>'
      +         KT.secretField({ id: 'tx-key', label: 'API key', stored: !!t.has_api_key,
                  whatItIs: 'API key',
                  placeholder: t.has_api_key ? '' : 'KEY…',
                  hint: (t.has_api_key
                    ? 'An API key is saved and encrypted. It is never shown again — leave this blank to keep it, and type here only to replace it. '
                    : 'Starts with <b>KEY</b>. Stored encrypted and never sent back to this screen. ')
                    + 'The <i>public</i> key below is a different credential.',
                  labelStyle: lbl, inputStyle: fld })
      +         '<div style="margin-top:14px;"><label style="' + lbl + '">Send from</label>'
      +           '<input id="tx-from" style="' + fld + '" placeholder="+16475550123" value="' + esc(t.sms_from || '') + '">'
      +           '<div style="' + hint + '">In full international form.</div></div>'
      +         '<div style="margin-top:14px;"><label style="' + lbl + '">Messaging profile id <span '
      +           'style="font-weight:600;text-transform:none;letter-spacing:0;">— optional</span></label>'
      +           '<input id="tx-profile" style="' + fld + '" placeholder="00000000-0000-0000-0000-000000000000" value="'
      +             esc(t.messaging_profile_id || '') + '">'
      +           '<div style="' + hint + '">Only needed for a number pool or an alphanumeric sender id. With one '
      +             'set, the number above may be left blank.</div></div>'
      +         '<div style="margin-top:18px;padding-top:14px;border-top:1px solid #EDF2F7;">'
      +           '<label style="' + lbl + '">Webhook public key</label>'
      +           '<input id="tx-pubkey" style="' + fld + '" placeholder="base64, 44 characters" value="'
      +             esc(t.public_key || '') + '">'
      +           '<div style="' + hint + '">Mission Control → Keys &amp; Credentials → <b>Public Key</b>. Every '
      +             'reply and call event is checked against this; without it they are all refused, which is '
      +             'deliberate — an unverified webhook would let anyone opt a number in or out.</div>'
      +           '<div style="margin-top:12px;"><label style="' + lbl + '">Inbound message webhook</label>'
      +             '<code style="' + mono + '">' + esc(s.telnyx_inbound_webhook || '') + '</code>'
      +             '<div style="' + hint + '">Set this on the messaging profile.</div></div>'
      +         '</div>'
      +       '</div>'
      +     '</div>'

      +     '<div style="display:flex;gap:8px;margin-top:16px;flex-wrap:wrap;align-items:center;">'
      +       '<button type="button" id="sms-save" style="height:34px;padding:0 18px;background:#1F6080;color:#fff;'
      +         'border:0;border-radius:9px;font-weight:800;font-size:13px;cursor:pointer;">Save</button>'
      +       '<button type="button" id="sms-test" data-kt-iconized="1" style="height:34px;padding:0 14px;'
      +         'background:#fff;color:#1F6080;border:1px solid #CBD5E1;border-radius:9px;font-weight:800;'
      +         'font-size:13px;cursor:pointer;">Test Twilio</button>'
      +       '<span id="sms-msg" style="font-size:13px;font-weight:700;"></span>'
      +     '</div>'
      +     '<div style="' + hint + 'margin-top:8px;">Save stores both carriers at once, whichever page you are on. '
      +       'Test checks the carrier whose page is open.</div>'
      /* THE TEST LOG. A test that only flashes a line of green text answers "is it
         working right now" and nothing else. Halfway through pasting four credentials
         the useful question is "what did it say the last three times, and has it ever
         passed" — so every attempt is kept server-side and drawn here. */
      +     '<div id="sms-testlog" style="margin-top:16px;"></div>'
      +   '</div>'

      /* ══════════════════════ VOICE ══════════════════════ */
      +   '<div data-sv-pane="voice" style="display:none;">'
      +     '<div class="kt-card" style="margin-top:14px;">'
      +       (s.voice_ready
        ? note('ok', 'Telnyx voice is configured' + (s.voice_enabled ? ' and switched on.' : ', but calls are switched OFF below.'))
        : note('warn', 'Not set up yet — voice needs the Telnyx API key on the Text messages tab, plus a '
            + 'Call Control connection and a caller number here.'))
      +       '<div style="' + hint + 'margin:12px 0 0;line-height:1.6;">'
      +         'An announcement call rings a parent and reads a short message out loud. It is for the notice you '
      +         'cannot assume anybody read — a closure, an evacuation, a lockdown, an illness at the centre. '
      +         'Anything outside that only reaches people who have agreed to be contacted, and a parent who has '
      +         'asked not to be telephoned is never called at all.</div>'
      +       '<div style="margin-top:16px;"><label style="' + lbl + '">Call Control connection id</label>'
      +         '<input id="vx-conn" style="' + fld + '" placeholder="e.g. 2891234567890123456" value="'
      +           esc(t.voice_connection_id || '') + '">'
      +         '<div style="' + hint + '">Mission Control → Voice → <b>Call Control</b> → your application. '
      +           'Set that application\'s webhook URL to the address at the bottom of this card.</div></div>'
      +       '<div style="margin-top:14px;"><label style="' + lbl + '">Call from</label>'
      +         '<input id="vx-from" style="' + fld + '" placeholder="+16475550123" value="' + esc(t.voice_from || '') + '">'
      +         '<div style="' + hint + '">The number parents will see. In full international form.</div></div>'
      +       '<div style="margin-top:14px;"><label style="' + lbl + '">Caller name <span style="font-weight:600;'
      +         'text-transform:none;letter-spacing:0;">— optional</span></label>'
      +         '<input id="vx-name" maxlength="128" style="' + fld + '" placeholder="Sunnyside Childcare" value="'
      +           esc(t.voice_caller_name || '') + '">'
      +         '<div style="' + hint + '">Shown on handsets that support caller ID name. Not every carrier passes it on.</div></div>'
      +       '<div style="display:flex;gap:12px;margin-top:14px;flex-wrap:wrap;">'
      +         '<div style="flex:1 1 240px;"><label style="' + lbl + '">Voice</label>'
      +           '<input id="vx-voice" style="' + fld + '" placeholder="female" value="' + esc(t.voice_voice || '') + '">'
      +           '<div style="' + hint + '"><b>female</b> or <b>male</b> for the basic voice. A name like '
      +             '<b>AWS.Polly.Joanna-Neural</b> sounds far more natural and is billed at the premium rate.</div></div>'
      +         '<div style="flex:1 1 160px;"><label style="' + lbl + '">Language</label>'
      +           '<input id="vx-lang" maxlength="12" style="' + fld + '" placeholder="en-US" value="'
      +             esc(t.voice_language || '') + '">'
      +           '<div style="' + hint + '">e.g. en-US, en-GB, fr-CA.</div></div>'
      +       '</div>'
      +       '<label style="display:flex;align-items:center;gap:10px;margin-top:18px;font-size:14px;font-weight:600;'
      +         'color:#0F172A;cursor:pointer;">'
      +         '<input type="checkbox" id="vx-enabled"' + (s.voice_enabled ? ' checked' : '')
      +           ' style="width:18px;height:18px;cursor:pointer;">'
      +         'Place announcement calls for this agency</label>'
      +       '<div style="' + hint + 'margin-left:28px;">Off until you turn it on. Saving credentials on its own '
      +         'never starts phones ringing.</div>'
      +       '<div style="margin-top:16px;"><label style="' + lbl + '">Call events webhook</label>'
      +         '<code style="' + mono + '">' + esc(s.telnyx_voice_webhook || '') + '</code>'
      +         '<div style="' + hint + '">Set this on the Call Control application. Without it a call connects '
      +           'and then sits in silence — the announcement is spoken in response to the "answered" event, '
      +           'because speaking any earlier plays it to a ringing handset nobody is holding.</div></div>'
      +       '<div style="display:flex;gap:8px;margin-top:18px;flex-wrap:wrap;align-items:center;">'
      +         '<button type="button" id="vx-save" style="height:34px;padding:0 18px;background:#1F6080;color:#fff;'
      +           'border:0;border-radius:9px;font-weight:800;font-size:13px;cursor:pointer;">Save</button>'
      +         '<button type="button" id="vx-test" data-kt-iconized="1" style="height:34px;padding:0 14px;'
      +           'background:#fff;color:#1F6080;border:1px solid #CBD5E1;border-radius:9px;font-weight:800;'
      +           'font-size:13px;cursor:pointer;">Call my own number</button>'
      +         '<span id="vx-msg" style="font-size:13px;font-weight:700;"></span>'
      +       '</div>'
      +       '<div style="' + hint + 'margin-top:8px;">The test rings the number on <b>your own</b> profile and '
      +         'nobody else\'s.</div>'
      +     '</div>'
      +   '</div>'
      + '</div>';

    // ── channel tabs ──
    var panes = {};
    main.querySelectorAll('[data-sv-pane]').forEach(function (p) { panes[p.getAttribute('data-sv-pane')] = p; });
    main.querySelectorAll('[data-sv-tab]').forEach(function (b) {
      b.addEventListener('click', function () {
        var key = b.getAttribute('data-sv-tab');
        main.querySelectorAll('[data-sv-tab]').forEach(function (o) {
          var on = o === b;
          o.style.background = on ? '#1F6080' : '#fff';
          o.style.color = on ? '#fff' : '#334155';
          o.style.borderColor = on ? '#1F6080' : '#CBD5E1';
        });
        Object.keys(panes).forEach(function (k) { panes[k].style.display = k === key ? '' : 'none'; });
      });
    });

    /* ── carrier tabs ──
       showCarrier() also repaints the "sending" badge and the Test button, so the page
       you are looking at, the carrier you would be testing, and the carrier that
       actually sends can never disagree on screen. */
    function selectedProvider() {
      var r = main.querySelector('input[name="sms-provider"]:checked');
      return r ? r.value : 'twilio';
    }

    var openCarrier = 'twilio';

    function showCarrier(which) {
      openCarrier = which;
      main.querySelectorAll('[data-cx-tab]').forEach(function (b) {
        var on = b.getAttribute('data-cx-tab') === which;
        b.style.background = on ? '#1F6080' : '#fff';
        b.style.color = on ? '#fff' : '#475569';
        b.style.borderColor = on ? '#1F6080' : '#CBD5E1';
        b.style.position = on ? 'relative' : '';   // keep the active border on top
      });
      main.querySelectorAll('[data-cx-pane]').forEach(function (p) {
        p.style.display = p.getAttribute('data-cx-pane') === which ? '' : 'none';
      });

      var sending = selectedProvider();
      main.querySelectorAll('[data-cx-sending]').forEach(function (pill) {
        pill.style.display = pill.getAttribute('data-cx-sending') === sending ? '' : 'none';
      });

      var test = document.getElementById('sms-test');
      if (test) { test.textContent = 'Test ' + (which === 'telnyx' ? 'Telnyx' : 'Twilio'); }
    }

    main.querySelectorAll('[data-cx-tab]').forEach(function (b) {
      b.addEventListener('click', function () { showCarrier(b.getAttribute('data-cx-tab')); });
    });

    /* Changing the carrier moves you to its page. Choosing Telnyx and then being left
       looking at the Twilio form is the one thing that would make two pages worse than
       one long one. */
    main.querySelectorAll('input[name="sms-provider"]').forEach(function (r) {
      r.addEventListener('change', function () {
        main.querySelectorAll('[data-prov-card]').forEach(function (c) {
          var on = c.getAttribute('data-prov-card') === r.value && r.checked;
          c.style.borderColor = on ? '#1F6080' : '#E2E8F0';
          c.style.background = on ? '#F0F7FA' : '#fff';
        });
        showCarrier(r.value);
      });
    });

    // Open on the carrier that is actually sending — the one you most likely came to see.
    showCarrier(s.provider === 'telnyx' ? 'telnyx' : 'twilio');

    function say(id, text, ok) {
      var m = document.getElementById(id);
      if (!m) { return; }
      m.textContent = text;
      m.style.color = ok ? '#047857' : '#B91C1C';
    }
    function val(id) { var e = document.getElementById(id); return e ? e.value.trim() : ''; }

    /* ONE SAVE FOR EVERY TAB. Every pane is always in the DOM — a hidden one is only
       display:none — so a save from any of them carries every field. Setting up Telnyx
       and losing the number typed on the Twilio page would be its own bug. */
    async function save(msgId) {
      var body = {
        account_sid: val('sms-sid'),
        from: val('sms-from'),
        api_key_sid: val('sms-keysid'),
        sms_enabled: document.getElementById('sms-enabled').checked,
        provider: selectedProvider(),
        failover: document.getElementById('sms-failover').checked,

        telnyx_public_key: val('tx-pubkey'),
        telnyx_sms_from: val('tx-from'),
        telnyx_messaging_profile_id: val('tx-profile'),
        telnyx_voice_connection_id: val('vx-conn'),
        telnyx_voice_from: val('vx-from'),
        telnyx_voice_caller_name: val('vx-name'),
        telnyx_voice_voice: val('vx-voice'),
        telnyx_voice_language: val('vx-lang'),
        voice_enabled: document.getElementById('vx-enabled').checked,
      };
      // Secrets are only sent when something was typed, so a blank box keeps the stored one.
      if (val('sms-token')) { body.auth_token = val('sms-token'); }
      if (val('sms-keysecret')) { body.api_key_secret = val('sms-keysecret'); }
      if (val('tx-key')) { body.telnyx_api_key = val('tx-key'); }

      say(msgId, '', true);
      try {
        await api().patch('/admin/sms-settings', body);
        toast('SMS and voice settings saved.', 'success');
        renderSmsSettings(main);
      } catch (e) {
        say(msgId, (e && e.message) || 'Could not save those settings.', false);
      }
    }

    document.getElementById('sms-save').addEventListener('click', function () {
      this.disabled = true; var b = this;
      save('sms-msg').then(function () { b.disabled = false; });
    });
    document.getElementById('vx-save').addEventListener('click', function () {
      this.disabled = true; var b = this;
      save('vx-msg').then(function () { b.disabled = false; });
    });

    /* Reads the carrier's account rather than sending a message: it proves the
       credentials are accepted without costing anything or needing a consenting
       recipient. It tests the carrier whose PAGE is open, which is the one whose boxes
       you were just typing in — testing the other one is exactly the sort of test that
       passes while sending fails. */
    /* One entry. The newest keeps its per-check breakdown open, because that is the one
       being read; older entries collapse to a single line so the panel stays a history
       rather than a wall. */
    function testEntry(t, expanded) {
      var ok = !!t.ok;
      var when = t.at ? (window.KT && KT.Fmt && KT.Fmt.time ? KT.Fmt.time(t.at) : String(t.at)) : '';
      var head = '<div style="display:flex;gap:9px;align-items:baseline;flex-wrap:wrap;">'
        + '<span style="font-weight:800;color:' + (ok ? '#047857' : '#B91C1C') + ';">'
        +   (ok ? '✓' : '✗') + '</span>'
        + '<span style="font-weight:800;text-transform:capitalize;">' + esc(t.provider || '') + '</span>'
        + '<span style="color:#334155;">' + esc(t.message || '') + '</span>'
        + '<span style="margin-left:auto;font-size:11.5px;color:#94A3B8;white-space:nowrap;">'
        +   esc(when) + (t.by ? ' · ' + esc(t.by) : '') + (t.ms ? ' · ' + t.ms + 'ms' : '') + '</span>'
        + '</div>';

      var body = '';
      if (expanded && t.checks && t.checks.length) {
        body = '<div style="margin:8px 0 0 22px;">' + t.checks.map(function (c) {
          return '<div style="display:flex;gap:8px;font-size:12.5px;margin:3px 0;">'
            + '<span style="color:' + (c.ok ? '#047857' : '#B45309') + ';font-weight:800;">'
            +   (c.ok ? '✓' : '○') + '</span>'
            + '<span style="font-weight:700;color:#334155;min-width:150px;">' + esc(c.label) + '</span>'
            + '<span style="color:#64748B;">' + esc(c.detail) + '</span></div>';
        }).join('') + '</div>';
      }

      return '<div style="padding:10px 0;border-bottom:1px solid #F1F5F9;">' + head + body + '</div>';
    }

    function renderTestLog(list) {
      var host = document.getElementById('sms-testlog');
      if (!host) { return; }
      list = list || [];

      if (!list.length) {
        host.innerHTML = '<div class="kt-card" style="color:#94A3B8;font-size:12.5px;padding:14px 16px;">'
          + 'No connection tests yet. Press <b>Test</b> above — it reads the carrier\'s account '
          + 'rather than sending anything, so it costs nothing and needs no consenting recipient.</div>';
        return;
      }

      host.innerHTML = '<div class="kt-card" style="padding:6px 16px 10px;">'
        + '<div style="font-size:11.5px;font-weight:800;color:#64748B;text-transform:uppercase;'
        +   'letter-spacing:.5px;padding:10px 0 2px;">Test log</div>'
        + list.map(function (t, i) { return testEntry(t, i === 0); }).join('')
        + '<div style="font-size:11px;color:#94A3B8;padding:8px 0 0;">Kept in the audit log, so it '
        +   'survives a reload and shows who ran each test.</div>'
        + '</div>';
    }

    renderTestLog(s.recent_tests);

    /* Reads the carrier's account rather than sending a message: it proves the
       credentials are accepted without costing anything or needing a consenting
       recipient. It tests the carrier whose PAGE is open, which is the one whose boxes
       you were just typing in — testing the other one is exactly the sort of test that
       passes while sending fails.

       The endpoint answers 200 even when the credentials are refused; `ok` carries the
       verdict. A 4xx would have thrown away the per-check breakdown, which is the part
       worth reading. */
    document.getElementById('sms-test').addEventListener('click', async function () {
      var btn = this;
      var which = openCarrier;
      btn.disabled = true; say('sms-msg', 'Checking ' + which + '…', true);
      try {
        var r = await api().post('/admin/sms-settings/test', { provider: which });
        say('sms-msg', (r && r.message) || '', !!(r && r.ok));
        renderTestLog((r && r.recent_tests) || []);
      } catch (e) {
        say('sms-msg', (e && e.message) || 'The test could not be run.', false);
      }
      btn.disabled = false;
    });

    document.getElementById('vx-test').addEventListener('click', async function () {
      var btn = this;
      var ok = window.KT && KT.confirm
        ? await KT.confirm('Ring your own number now with a short test announcement?')
        : window.confirm('Ring your own number now?');
      if (!ok) { return; }
      btn.disabled = true; say('vx-msg', 'Placing the call…', true);
      try {
        var r = await api().post('/admin/voice/test-call', {});
        say('vx-msg', (r && r.message) || 'Calling now.', true);
      } catch (e) {
        say('vx-msg', (e && e.message) || 'The call could not be placed.', false);
      }
      btn.disabled = false;
    });
  }

  // Register screens
  function reg() {
    if (!(window.KT && window.KT.Shell && window.KT.Shell.registerScreen)) { setTimeout(reg, 200); return; }
    ['agency_admin', 'platform_admin', 'centre_director'].forEach(function (r) {
      window.KT.Shell.registerScreen(r + ':quickbooks', renderQuickbooks);
      window.KT.Shell.registerScreen(r + ':sms-settings', renderSmsSettings);
      // Wrapped rather than folded into renderEmailSettings: the tabs are a layer over
      // whatever that function rendered, and keeping them separate means the render path
      // is unchanged if the tabs are ever dropped.
      window.KT.Shell.registerScreen(r + ':email-settings', async function (main, ctx) {
        await renderEmailSettings(main, ctx);
        /* Fold the Reseller "Email" screen in as a tab. Rendered BEFORE the tab
           layer, which relocates whatever it finds. Platform admins only — its
           endpoint is /platform/* and would 403 for anyone else. */
        try {
          var isPlat = false;
          try { isPlat = sessionStorage.getItem('kt_is_platform_admin') === '1'; } catch (e) {}
          if (!isPlat) {
            try {
              var u = JSON.parse(sessionStorage.getItem('kt_user') || localStorage.getItem('kt_user') || '{}');
              isPlat = !!(u.is_platform_admin || u.role_key === 'platform_admin' || u.role === 'platform_admin');
            } catch (e2) {}
          }
          if (isPlat && window.KT && KT.MailSettingsScreen && KT.MailSettingsScreen.render
              && !main.querySelector('#es-platform-mail')) {
            var pm = document.createElement('div');
            pm.id = 'es-platform-mail';
            /* LEFT-ALIGN IT LIKE EVERY OTHER TAB.
               screen-mail-settings.js is ALSO a standalone screen (#mail-settings), where
               a centred column is right and is what the rest of the portal does. Embedded
               here it kept that centring while every other pane is a plain left-aligned
               kt-card, so the content jumped to the middle of the page on this one tab.
               Fixed here rather than in that file, so the standalone screen is untouched.
               !important is unavoidable: the centring is an inline style on the child.
               The width is matched to the neighbouring panes (680px) so moving across the
               tabs does not resize the column either. */
            if (!document.getElementById('es-pm-align')) {
              var pmCss = document.createElement('style');
              pmCss.id = 'es-pm-align';
              pmCss.textContent = '#es-platform-mail > div{margin-left:0 !important;margin-right:0 !important;'
                + 'padding-left:0 !important;padding-right:0 !important;max-width:680px !important;}';
              document.head.appendChild(pmCss);
            }
            /* Into root, not main: applyEmailTabs relocates root.children, and
               root is main.firstElementChild. A sibling of root is invisible to it. */
            (main.firstElementChild || main).appendChild(pm);
            KT.MailSettingsScreen.render(pm);
          }
        } catch (e) { /* the rest of the screen must still tab */ }

        try { applyEmailTabs(main); } catch (e) { /* an un-tabbed screen still works */ }
      });
    });
  }
  reg();

  window.KT = window.KT || {};
  window.KT.renderQuickbooks = renderQuickbooks;
  window.KT.renderEmailSettings = renderEmailSettings;
})(window);
