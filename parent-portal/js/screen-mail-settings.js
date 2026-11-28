/* ═══════════════════════════════════════════════════════════════════
   KiddieTrac — Email Settings (platform_admin / superadmin only).
   Switch outbound mail between local sendmail and Microsoft Graph, manage the
   Graph credentials, watch the secret's expiry, and send a live test.
   Backed by /platform/mail-settings (GET/PUT) + /platform/mail-settings/test.
   ═══════════════════════════════════════════════════════════════════ */
(function (window) {
  'use strict';
  var KT = (window.KT = window.KT || {});
  var Shell = KT.Shell;
  var API = function () { return (KT.API_BASE) || 'https://api.kiddietrac.com/api/v1'; };

  function mapi(path, method, body) {
    /* Both stores, like every other screen. sessionStorage alone left this card
       unauthenticated when it renders as a tab inside Email settings. */
    var _tok = null;
    try { _tok = sessionStorage.getItem('kt_token') || localStorage.getItem('kt_token'); } catch (e) {}
    var h = { 'Authorization': 'Bearer ' + _tok, 'Accept': 'application/json' };
    var aa = sessionStorage.getItem('kt_active_agency_id'); if (aa) h['X-Active-Agency-Id'] = aa;
    if (body) h['Content-Type'] = 'application/json';
    return fetch(API() + path, { method: method || 'GET', headers: h, body: body ? JSON.stringify(body) : undefined })
      .then(function (r) { return r.json().then(function (j) { if (!r.ok) throw new Error(j.message || ('HTTP ' + r.status)); return j; }); });
  }
  function esc(s) { return String(s == null ? '' : s).replace(/[&<>"]/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]; }); }
  var inp = 'width:100%;box-sizing:border-box;padding:9px 11px;border:1px solid #E2E8F0;border-radius:9px;font-size:14px;margin-top:4px;';
  var lbl = 'font-size:12.5px;font-weight:700;color:#334155;display:block;margin-top:14px;';

  function render(container) {
    container.innerHTML = '<div style="padding:24px;color:#0F172A;"><div style="color:#64748B;">Loading email settings…</div></div>';
    mapi('/platform/mail-settings').then(function (d) { paint(container, d); })
      .catch(function (e) { container.innerHTML = '<div style="padding:24px;color:#B91C1C;">Could not load: ' + esc(e.message) + '</div>'; });
  }

  function paint(container, d) {
    var g = d.graph || {};
    var expWarn = '';
    if (g.secret_days_left != null) {
      var dl = g.secret_days_left;
      var col = dl < 0 ? '#B91C1C' : (dl <= 30 ? '#B45309' : '#047857');
      expWarn = '<div style="margin-top:6px;font-size:12.5px;font-weight:700;color:' + col + ';">'
        + (dl < 0 ? '⚠ Secret EXPIRED ' + (-dl) + ' day(s) ago — email is falling back to sendmail.' : ('Secret expires in ' + dl + ' day(s)' + (dl <= 30 ? ' — rotate it soon.' : '.')))
        + '</div>';
    }
    var lt = null; try { lt = d.last_test ? JSON.parse(d.last_test) : null; } catch (e) {}

    // A reusable field block: label on top, input, optional hint underneath —
    // consistent vertical rhythm instead of ad-hoc label margins.
    function field(label, inputHtml, hint) {
      return '<div class="ms-field"><span class="ms-label">' + label + '</span>' + inputHtml
        + (hint ? '<span class="ms-hint">' + hint + '</span>' : '') + '</div>';
    }
    var selMethod = '<select id="ms-mailer" class="ms-input">'
      + '<option value="graph"' + (d.mailer === 'graph' || d.mailer === 'failover' ? ' selected' : '') + '>Microsoft Graph (recommended — with sendmail fallback)</option>'
      + '<option value="sendmail"' + (d.mailer === 'sendmail' ? ' selected' : '') + '>Local sendmail only</option>'
      + '</select>';

    container.innerHTML =
      '<style id="ms-css">'
      + '.ms-input{width:100%;box-sizing:border-box;padding:10px 12px;border:1px solid #DCE3EC;border-radius:10px;font-size:13.5px;background:#fff;color:#0F172A;font-family:inherit;transition:border-color .15s,box-shadow .15s;}'
      + '.ms-input:hover{border-color:#C3CEDC;}'
      + '.ms-input:focus{border-color:#1F6FB2;box-shadow:0 0 0 3px rgba(31,111,178,.13);outline:none;}'
      + '.ms-input::placeholder{color:#AEB9C7;}'
      + '.ms-field{display:flex;flex-direction:column;gap:6px;margin-bottom:15px;}'
      + '.ms-label{font-size:11.5px;font-weight:700;color:#475569;letter-spacing:.2px;}'
      + '.ms-hint{font-size:11px;color:#94A3B8;line-height:1.4;}'
      + '.ms-grid2{display:grid;grid-template-columns:1fr 1fr;gap:0 16px;}'
      + '@media(max-width:560px){.ms-grid2{grid-template-columns:1fr;}}'
      + '.ms-sec{font-weight:800;font-size:11px;letter-spacing:.8px;text-transform:uppercase;color:#64748B;margin:4px 0 14px;}'
      + '.ms-card{background:#fff;border:1px solid #E7EBF0;border-radius:16px;padding:22px 24px;box-shadow:0 1px 4px rgba(15,23,42,.05);}'
      + '</style>'
      + '<div style="padding:24px;color:#0F172A;">'
      + '<h2 style="margin:0 0 4px;font-size:21px;font-weight:800;">📬 Mail settings</h2>'
      + '<div style="color:#64748B;font-size:13px;margin-bottom:18px;line-height:1.55;">How KiddieTrac sends outbound email. Microsoft Graph gives inbox delivery (SPF/DKIM/DMARC) + speed; sendmail is the automatic fallback so email never goes down.</div>'

      + '<div class="ms-card">'
      + field('Send method', selMethod)
      + '<div class="ms-grid2">'
      +   field('From address', '<input id="ms-from" class="ms-input" value="' + esc(d.from || '') + '" placeholder="noreply@kiddietrac.com">')
      +   field('From name', '<input id="ms-fromname" class="ms-input" value="' + esc(d.from_name || '') + '" placeholder="KiddieTrac">')
      + '</div>'

      + '<div style="margin-top:6px;padding-top:18px;border-top:1px solid #EEF2F6;">'
      + '<div class="ms-sec">🔐 Microsoft Graph credentials</div>'
      + '<div class="ms-grid2">'
      +   field('Directory (tenant) ID', '<input id="ms-tenant" class="ms-input" value="' + esc(g.tenant || '') + '" placeholder="00000000-0000-0000-0000-000000000000">')
      +   field('Application (client) ID', '<input id="ms-clientid" class="ms-input" value="' + esc(g.client_id || '') + '" placeholder="00000000-0000-0000-0000-000000000000">')
      + '</div>'
      + field('Graph sender mailbox', '<input id="ms-graphfrom" class="ms-input" value="' + esc(g.from || '') + '" placeholder="noreply@kiddietrac.com">')
      + '<div class="ms-grid2">'
      +   field('Client secret', '<input id="ms-secret" type="password" autocomplete="new-password" class="ms-input" placeholder="' + (g.secret_set ? '•••••••• (unchanged)' : 'paste the secret Value') + '">', g.secret_set ? 'Set — leave blank to keep the current secret.' : 'Not set yet.')
      +   field('Secret expires on', '<input id="ms-exp" type="date" class="ms-input" value="' + esc(g.secret_expires_at ? String(g.secret_expires_at).slice(0, 10) : '') + '">', 'From Azure — so we can warn you before it lapses.')
      + '</div>'
      + expWarn
      + '</div>'

      + '<div style="margin-top:14px;padding-top:16px;border-top:1px solid #EEF2F6;display:flex;gap:12px;align-items:center;flex-wrap:wrap;">'
      + '<button id="ms-save" style="background:linear-gradient(135deg,#0FA3B1,#1F6FB2 60%,#2456A6);color:#fff;border:0;border-radius:10px;padding:11px 24px;font-weight:800;font-size:13.5px;cursor:pointer;box-shadow:0 2px 8px rgba(31,111,178,.25);">Save settings</button>'
      + '<span id="ms-saveout" style="font-size:13px;font-weight:700;"></span>'
      + '</div>'
      + '</div>'

      + microsoftCard(d.microsoft || {}, field)
      + googleCard(d.google_gmail || {}, field)

      // Test panel
      + '<div class="ms-card" style="margin-top:16px;background:#F9FBFC;">'
      + '<div style="font-weight:800;font-size:13.5px;margin-bottom:4px;">📤 Send a test</div>'
      + '<div style="font-size:12px;color:#94A3B8;margin-bottom:12px;">Confirm delivery end-to-end and see which mailer was used.</div>'
      + '<div style="display:flex;gap:10px;flex-wrap:wrap;align-items:center;">'
      + '<input id="ms-testto" class="ms-input" value="' + esc((lt && lt.to) || '') + '" placeholder="you@example.com" style="max-width:300px;">'
      + '<button id="ms-test" style="background:#fff;border:1.5px solid #CBD5E1;border-radius:10px;padding:10px 18px;font-weight:700;font-size:13px;cursor:pointer;white-space:nowrap;">Send test →</button>'
      + '</div>'
      + '<div id="ms-testout" style="margin-top:12px;font-size:13px;"></div>'
      + (lt ? '<div style="margin-top:8px;font-size:11.5px;color:#94A3B8;">Last test: ' + esc(lt.at || '') + ' → ' + (lt.sent ? 'sent via ' + esc(lt.mailer || '') : 'failed') + ' · Graph: ' + esc(lt.graph || '') + '</div>' : '')
      + '</div>'
      + '</div>';

    document.getElementById('ms-save').onclick = function () {
      var out = document.getElementById('ms-saveout'); out.style.color = '#64748B'; out.textContent = 'Saving…';
      var body = {
        mailer: document.getElementById('ms-mailer').value,
        from: document.getElementById('ms-from').value.trim() || null,
        from_name: document.getElementById('ms-fromname').value.trim() || null,
        graph_tenant: document.getElementById('ms-tenant').value.trim() || null,
        graph_client_id: document.getElementById('ms-clientid').value.trim() || null,
        graph_from: document.getElementById('ms-graphfrom').value.trim() || null,
        graph_secret_expires_at: document.getElementById('ms-exp').value || null
      };
      var sec = document.getElementById('ms-secret').value;
      if (sec) body.graph_client_secret = sec;
      mapi('/platform/mail-settings', 'PUT', body).then(function () {
        out.style.color = '#047857'; out.textContent = '✓ Saved.';
        setTimeout(function () { render(container); }, 900);
      }).catch(function (e) { out.style.color = '#B91C1C'; out.textContent = '✗ ' + e.message; });
    };

    wireMicrosoft(container);
    wireGoogle(container);

    document.getElementById('ms-test').onclick = function () {
      var to = document.getElementById('ms-testto').value.trim();
      var out = document.getElementById('ms-testout');
      if (!to) { out.style.color = '#B91C1C'; out.textContent = 'Enter an address to test.'; return; }
      out.style.color = '#64748B'; out.textContent = 'Sending…';
      mapi('/platform/mail-settings/test', 'POST', { to: to }).then(function (r) {
        var ok = r.sent;
        out.style.color = ok ? '#047857' : '#B91C1C';
        out.innerHTML = (ok ? '✓ Sent via <b>' + esc(r.via) + '</b>. ' : '✗ Send failed. ')
          + 'Graph credentials: <b>' + esc(r.graph_credentials) + '</b>.' + (r.error ? ' <span style="color:#B91C1C;">' + esc(r.error) + '</span>' : '');
      }).catch(function (e) { out.style.color = '#B91C1C'; out.textContent = '✗ ' + e.message; });
    };
  }

  /* GMAIL SIGN-IN FOR RSVP SENDERS (2026-10-05). The Google OAuth client behind
     "Connect Gmail" on Event RSVPs → Sender accounts. The host blocks outbound SMTP, so a
     sender is a Gmail account signed in through Google, and Google needs this client to
     let anyone sign in. Anthony: "make sure settings can be saved in the settings through
     the gui" — it was in the server's .env, where nobody could see or change it. */
  /* MICROSOFT 365 SIGN-IN (2026-10-26, Anthony: "configurable ... and not hard coded in the env"). The Microsoft app
     behind "Sign in with Microsoft" when connecting a mailbox (Email, Sales email) and on the login page. */
  function microsoftCard(ms, field) {
    var lc = null; try { lc = ms.last_check ? JSON.parse(ms.last_check) : null; } catch (e) {}
    var status = ms.source === 'none' ? '<span style="color:#B45309;font-weight:700;">Not set up: connecting a Microsoft 365 mailbox will not work yet.</span>'
      : ms.source === 'env' ? '<span style="color:#B45309;font-weight:700;">Using the server file (.env), which cannot be checked from here. Save the app below to manage it here.</span>'
      : (lc ? '<span style="color:' + (lc.ok ? '#047857' : '#B91C1C') + ';font-weight:700;">' + (lc.ok ? '✓ ' : '✗ ') + esc(lc.result) + '</span><span style="color:#94A3B8;"> · checked ' + esc(lc.at || '') + '</span>' : '');
    return '<div class="ms-card" style="margin-top:16px;">'
      + '<div class="ms-sec" style="margin-bottom:6px;">🟦 Microsoft 365 sign-in (mailboxes and “Sign in with Microsoft”)</div>'
      + '<div style="font-size:12.5px;color:#64748B;line-height:1.5;margin-bottom:14px;">The Microsoft app people sign in through when they connect a mailbox in <b>Email</b> or <b>Sales email</b>, and for <b>Sign in with Microsoft</b> on the login page. '
      + 'From Microsoft Entra → App registrations → your app. It needs the delegated permissions <b>Mail.ReadWrite</b>, <b>Mail.ReadWrite.Shared</b>, <b>offline_access</b> and <b>User.Read</b>.</div>'
      + field('Application (client) ID', '<input id="mso-id" class="ms-input" value="' + esc(ms.client_id || '') + '" placeholder="00000000-0000-0000-0000-000000000000" autocomplete="off" spellcheck="false">', 'On the app’s Overview page. Not the secret, and not the Object ID.')
      + field('Client secret value', '<input id="mso-secret" type="password" autocomplete="new-password" class="ms-input" placeholder="' + (ms.secret_set ? '•••••••• (unchanged)' : 'Paste the secret Value') + '">',
          ms.secret_set ? 'Set — leave blank to keep the current secret.' : 'Certificates & secrets → the secret’s Value (not its Secret ID).')
      /* No Tenant box (2026-11-05): a directory ID typed here locked every other organisation out of connecting its
         mailbox. Sign-in now always accepts any Microsoft account. */
      + '<div style="font-size:12.5px;color:#475569;line-height:1.5;margin:-2px 0 12px;">Anyone can connect: any work, school or personal Microsoft account (Microsoft 365, Outlook.com, Hotmail) signs in with this one app. Nobody connecting a mailbox needs an ID or a secret.</div>'
      + field('Redirect URI (add under the app’s Authentication → Web)',
          '<div style="display:flex;gap:8px;"><input id="mso-redirect" class="ms-input" readonly value="' + esc(ms.redirect_uri || '') + '" style="background:#F8FAFC;font-family:ui-monospace,Menlo,monospace;font-size:12.5px;">'
          + '<button id="mso-copy" type="button" style="background:#fff;border:1.5px solid #CBD5E1;border-radius:10px;padding:0 14px;font-weight:700;font-size:12.5px;cursor:pointer;white-space:nowrap;">Copy</button></div>')
      + '<div style="font-size:12.5px;margin:-4px 0 12px;">' + status + '</div>'
      + '<div style="padding-top:14px;border-top:1px solid #EEF2F6;display:flex;gap:10px;align-items:center;flex-wrap:wrap;">'
      + '<button id="mso-save" type="button" data-kt-always-save="1" style="background:linear-gradient(135deg,#0FA3B1,#1F6FB2 60%,#2456A6);color:#fff;border:0;border-radius:10px;padding:9px 20px;font-weight:800;font-size:13px;cursor:pointer;">Save Microsoft settings</button>'
      + (ms.source === 'settings' ? '<button id="mso-check" type="button" style="background:#fff;border:1.5px solid #CBD5E1;border-radius:10px;padding:8px 16px;font-weight:700;font-size:13px;cursor:pointer;">Check with Microsoft</button>'
          + '<button id="mso-clear" type="button" style="background:none;border:0;color:#B91C1C;font-weight:700;font-size:12.5px;cursor:pointer;margin-left:auto;">Remove</button>' : '')
      + '<span id="mso-out" style="font-size:13px;font-weight:700;"></span>'
      + '</div></div>';
  }

  function wireMicrosoft(container) {
    var out = document.getElementById('mso-out');
    if (!out) return;
    function say(ok, t) { out.style.color = ok == null ? '#64748B' : (ok ? '#047857' : '#B91C1C'); out.textContent = t; }
    function result(r) {
      var c = r && r.check;
      if (c) { say(c.ok, (c.ok ? '✓ ' : '✗ ') + c.result); } else { say(true, '✓ Saved.'); }
      setTimeout(function () { render(container); }, c && !c.ok ? 6000 : 1600);
    }
    document.getElementById('mso-copy').onclick = function () {
      var i = document.getElementById('mso-redirect');
      try { navigator.clipboard.writeText(i.value).then(function () { say(true, '✓ Copied.'); }); }
      catch (e) { i.select(); try { document.execCommand('copy'); say(true, '✓ Copied.'); } catch (_e) {} }
    };
    document.getElementById('mso-save').onclick = function () {
      var body = { client_id: document.getElementById('mso-id').value.trim() };
      var sec = document.getElementById('mso-secret').value.trim();
      if (sec) body.client_secret = sec;
      say(null, 'Saving and checking with Microsoft…');
      mapi('/platform/mail-settings/microsoft', 'PUT', body).then(result).catch(function (e) { say(false, '✗ ' + e.message); });
    };
    var chk = document.getElementById('mso-check');
    if (chk) chk.onclick = function () {
      say(null, 'Checking…');
      mapi('/platform/mail-settings/microsoft/check', 'POST', {}).then(result).catch(function (e) { say(false, '✗ ' + e.message); });
    };
    var clr = document.getElementById('mso-clear');
    if (clr) clr.onclick = function () {
      var go = (window.KT && KT.confirm)
        ? KT.confirm({ title: 'Remove the Microsoft sign-in app?', description: 'Mailbox sign-in and “Sign in with Microsoft” go back to the server file (.env). Mailboxes already connected keep working until their sign-in needs renewing.', okLabel: 'Remove' })
        : Promise.resolve(false);
      go.then(function (yes) {
        if (!yes) return;
        mapi('/platform/mail-settings/microsoft', 'PUT', { clear: true }).then(function () { say(true, '✓ Removed.'); setTimeout(function () { render(container); }, 900); })
          .catch(function (e) { say(false, '✗ ' + e.message); });
      });
    };
  }

  function googleCard(gg, field) {
    var lc = null; try { lc = gg.last_check ? JSON.parse(gg.last_check) : null; } catch (e) {}
    var status = !gg.client_id
      ? '<span style="color:#B45309;font-weight:700;">Not set up — "Connect Gmail" will not work yet.</span>'
      : (lc ? '<span style="color:' + (lc.ok ? '#047857' : '#B91C1C') + ';font-weight:700;">' + (lc.ok ? '✓ ' : '✗ ') + esc(lc.result) + '</span>'
            + '<span style="color:#94A3B8;"> · checked ' + esc(lc.at || '') + '</span>' : '');
    return '<div class="ms-card" style="margin-top:16px;">'
      + '<div class="ms-sec" style="margin-bottom:6px;">📧 Gmail sign-in for RSVP senders</div>'
      + '<div style="font-size:12.5px;color:#64748B;line-height:1.5;margin-bottom:14px;">The Google client that lets an agency connect a Gmail account under '
      + '<b>Event RSVPs → Sender accounts</b>. From Google Cloud Console → Google Auth Platform → Clients.</div>'
      + field('Client ID', '<input id="gg-id" class="ms-input" value="' + esc(gg.client_id || '') + '" placeholder="123456789-abc….apps.googleusercontent.com" autocomplete="off" spellcheck="false">')
      + field('Client secret', '<input id="gg-secret" type="password" autocomplete="new-password" class="ms-input" placeholder="' + (gg.secret_set ? '•••••••• (unchanged)' : 'GOCSPX-…') + '">',
          gg.secret_set ? 'Set — leave blank to keep the current secret.' : 'Not set yet.')
      + field('Authorized redirect URI (paste this into the Google client)',
          '<div style="display:flex;gap:8px;"><input id="gg-redirect" class="ms-input" readonly value="' + esc(gg.redirect_uri || '') + '" style="background:#F8FAFC;font-family:ui-monospace,Menlo,monospace;font-size:12.5px;">'
          + '<button id="gg-copy" type="button" style="background:#fff;border:1.5px solid #CBD5E1;border-radius:10px;padding:0 14px;font-weight:700;font-size:12.5px;cursor:pointer;white-space:nowrap;">Copy</button></div>')
      + '<div id="gg-status" style="font-size:12.5px;margin:-4px 0 12px;">' + status + '</div>'
      + '<div style="padding-top:14px;border-top:1px solid #EEF2F6;display:flex;gap:10px;align-items:center;flex-wrap:wrap;">'
      + '<button id="gg-save" type="button" style="background:linear-gradient(135deg,#0FA3B1,#1F6FB2 60%,#2456A6);color:#fff;border:0;border-radius:10px;padding:9px 20px;font-weight:800;font-size:13px;cursor:pointer;">Save Gmail settings</button>'
      + (gg.client_id ? '<button id="gg-check" type="button" style="background:#fff;border:1.5px solid #CBD5E1;border-radius:10px;padding:8px 16px;font-weight:700;font-size:13px;cursor:pointer;">Check with Google</button>'
          + '<button id="gg-clear" type="button" style="background:none;border:0;color:#B91C1C;font-weight:700;font-size:12.5px;cursor:pointer;margin-left:auto;">Remove</button>' : '')
      + '<span id="gg-out" style="font-size:13px;font-weight:700;"></span>'
      + '</div>'
      + '</div>';
  }

  function wireGoogle(container) {
    var out = document.getElementById('gg-out');
    function say(ok, t) { out.style.color = ok == null ? '#64748B' : (ok ? '#047857' : '#B91C1C'); out.textContent = t; }
    function result(r) {
      var c = r && r.check;
      if (c) { say(c.ok, (c.ok ? '✓ ' : '✗ ') + c.result); } else { say(true, '✓ Saved.'); }
      setTimeout(function () { render(container); }, c && !c.ok ? 4000 : 1400);
    }
    document.getElementById('gg-copy').onclick = function () {
      var i = document.getElementById('gg-redirect');
      try { navigator.clipboard.writeText(i.value).then(function () { say(true, '✓ Copied.'); }); }
      catch (e) { i.select(); try { document.execCommand('copy'); say(true, '✓ Copied.'); } catch (_e) {} }
    };
    document.getElementById('gg-save').onclick = function () {
      var body = { client_id: document.getElementById('gg-id').value.trim() };
      var sec = document.getElementById('gg-secret').value.trim();
      if (sec) body.client_secret = sec;
      say(null, 'Saving and checking with Google…');
      mapi('/platform/mail-settings/google', 'PUT', body).then(result)
        .catch(function (e) { say(false, '✗ ' + e.message); });
    };
    var chk = document.getElementById('gg-check');
    if (chk) chk.onclick = function () {
      say(null, 'Checking…');
      mapi('/platform/mail-settings/google/check', 'POST', {}).then(result)
        .catch(function (e) { say(false, '✗ ' + e.message); });
    };
    var clr = document.getElementById('gg-clear');
    if (clr) clr.onclick = function () {
      var go = (window.KT && KT.confirm)
        ? KT.confirm({ title: 'Remove the Gmail sign-in client?', description: 'Agencies will not be able to connect a Gmail sender until a client is saved again. Gmail senders already connected stop sending.', okLabel: 'Remove' })
        : Promise.resolve(false);
      go.then(function (yes) {
        if (!yes) return;
        mapi('/platform/mail-settings/google', 'PUT', { clear: true }).then(function () { say(true, '✓ Removed.'); setTimeout(function () { render(container); }, 900); })
          .catch(function (e) { say(false, '✗ ' + e.message); });
      });
    };
  }

  if (Shell && Shell.registerScreen) {
    Shell.registerScreen('platform_admin:mail-settings', render);
  }
  KT.MailSettingsScreen = { render: render };
})(window);
