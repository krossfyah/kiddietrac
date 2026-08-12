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
    var h = { 'Authorization': 'Bearer ' + sessionStorage.getItem('kt_token'), 'Accept': 'application/json' };
    var aa = sessionStorage.getItem('kt_active_agency_id'); if (aa) h['X-Active-Agency-Id'] = aa;
    if (body) h['Content-Type'] = 'application/json';
    return fetch(API() + path, { method: method || 'GET', headers: h, body: body ? JSON.stringify(body) : undefined })
      .then(function (r) { return r.json().then(function (j) { if (!r.ok) throw new Error(j.message || ('HTTP ' + r.status)); return j; }); });
  }
  function esc(s) { return String(s == null ? '' : s).replace(/[&<>"]/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]; }); }
  var inp = 'width:100%;box-sizing:border-box;padding:9px 11px;border:1px solid #E2E8F0;border-radius:9px;font-size:14px;margin-top:4px;';
  var lbl = 'font-size:12.5px;font-weight:700;color:#334155;display:block;margin-top:14px;';

  function render(container) {
    container.innerHTML = '<div style="padding:24px;max-width:760px;margin:0 auto;color:#0F172A;"><div style="color:#64748B;">Loading email settings…</div></div>';
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
      + '<div style="padding:24px;max-width:720px;margin:0 auto;color:#0F172A;">'
      + '<h2 style="margin:0 0 4px;font-size:21px;font-weight:800;">✉️ Email settings</h2>'
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

  if (Shell && Shell.registerScreen) {
    Shell.registerScreen('platform_admin:mail-settings', render);
  }
  KT.MailSettingsScreen = { render: render };
})(window);
