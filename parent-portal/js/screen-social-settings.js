/* ═══════════════════════════════════════════════════════════════════
   KiddieTrac — Sign-in methods settings (2026-07-08)
   Platform-admin page to configure Google / Microsoft / Facebook OAuth by
   entering each app's Client ID + Secret. Writes ONLY those whitelisted .env
   keys server-side; secrets are write-only (never sent back). Includes the
   redirect URI to register and step-by-step setup instructions per provider.
   Registered for agency_admin:social-settings (platform_admin runs the
   agency_admin shell); the API route is role:platform_admin so non-platform
   admins get a 403 handled gracefully.
   ═══════════════════════════════════════════════════════════════════ */
(function (window) {
  'use strict';
  var KT = window.KT || {};
  var Shell = KT.Shell, Api = KT.Api;
  if (!Shell || !Api) return;

  function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"]/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c];
    });
  }

  var META = {
    google:    { name: 'Google',    icon: '🟦', color: '#4285F4' },
    microsoft: { name: 'Microsoft', icon: '🟧', color: '#2F6FED' },
    facebook:  { name: 'Facebook',  icon: '🔵', color: '#1877F2' }
  };

  function steps(provider, redirect) {
    var r = esc(redirect);
    var S = {
      google: [
        'Open the <b>Google Cloud Console</b> → <i>APIs &amp; Services → Credentials</i> (create or pick a project).',
        'Configure the <b>OAuth consent screen</b> (User type: External; add an app name + support email).',
        'Click <b>Create credentials → OAuth client ID</b>, Application type: <b>Web application</b>.',
        'Under <b>Authorized redirect URIs</b> add exactly:<br><code>' + r + '</code>',
        'Create it, then copy the <b>Client ID</b> and <b>Client secret</b> into the fields above and Save.'
      ],
      microsoft: [
        'Open the <b>Azure Portal</b> → <i>Microsoft Entra ID → App registrations → New registration</i>.',
        'Name it; for <b>Supported account types</b> choose "Accounts in any organizational directory and personal Microsoft accounts" (keeps tenant = <code>common</code>).',
        'Set <b>Redirect URI</b> (platform: Web) to exactly:<br><code>' + r + '</code>',
        'After it is created, copy the <b>Application (client) ID</b>.',
        'Go to <b>Certificates &amp; secrets → New client secret</b>, then copy the secret’s <b>Value</b> (not the Secret ID).',
        'Paste the Client ID + Secret above and Save. Leave Tenant as <code>common</code> unless you want to restrict to one org.'
      ],
      facebook: [
        'Open <b>Meta for Developers</b> → <i>My Apps → Create App</i> (type: Consumer or Business).',
        'Add the <b>Facebook Login</b> product to the app.',
        'In <b>Facebook Login → Settings</b>, add to <b>Valid OAuth Redirect URIs</b>:<br><code>' + r + '</code>',
        'In <b>Settings → Basic</b>, copy the <b>App ID</b> and <b>App Secret</b> into the fields above and Save.',
        'To allow all users (not just app admins/testers) you must complete Meta <b>App Review</b> and switch the app to <b>Live</b>.'
      ]
    };
    return (S[provider] || []).map(function (x, i) {
      return '<li style="margin-bottom:7px;">' + x + '</li>';
    }).join('');
  }

  function providerCard(key, cfg) {
    var m = META[key] || { name: key, icon: '•', color: '#1F6080' };
    var status = cfg.configured
      ? '<span style="background:#DCFCE7;color:#166534;font-weight:700;font-size:11px;padding:2px 10px;border-radius:20px;">● Enabled</span>'
      : '<span style="background:#F1F5F9;color:#64748B;font-weight:700;font-size:11px;padding:2px 10px;border-radius:20px;">Not set up</span>';
    var inp = 'width:100%;box-sizing:border-box;padding:8px 11px;border:1px solid #D6DEE7;border-radius:8px;font-size:13px;margin-top:4px;';
    return '' +
      '<div class="kt-social-card" data-provider="' + key + '" style="background:#fff;border:1px solid #E7EBF0;border-radius:14px;padding:18px 20px;margin-bottom:16px;">' +
        '<div style="display:flex;align-items:center;justify-content:space-between;gap:12px;margin-bottom:12px;">' +
          '<div style="display:flex;align-items:center;gap:10px;"><span style="font-size:22px;">' + m.icon + '</span>' +
          '<span style="font-weight:800;font-size:16px;color:#0D1B2A;">' + esc(m.name) + '</span></div>' + status +
        '</div>' +
        '<div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">' +
          '<label style="font-size:12px;font-weight:700;color:#475569;">Client ID' +
            '<input class="ss-client-id" value="' + esc(cfg.client_id || '') + '" placeholder="paste Client ID" style="' + inp + '"></label>' +
          '<label style="font-size:12px;font-weight:700;color:#475569;">Client Secret' +
            '<input class="ss-secret" type="password" autocomplete="new-password" placeholder="' + (cfg.has_secret ? '•••••••• (saved — leave blank to keep)' : 'paste Client Secret') + '" style="' + inp + '"></label>' +
          (key === 'microsoft'
            ? '<label style="font-size:12px;font-weight:700;color:#475569;">Tenant<input class="ss-tenant" value="' + esc(cfg.tenant || 'common') + '" placeholder="common" style="' + inp + '"></label>'
            : '') +
        '</div>' +
        '<div style="display:flex;gap:8px;justify-content:flex-end;margin-top:14px;flex-wrap:wrap;">' +
          (cfg.configured ? '<button class="ss-clear" style="font-size:12.5px;font-weight:600;padding:8px 14px;border-radius:8px;cursor:pointer;border:1px solid #FECACA;background:#FEF2F2;color:#B91C1C;">Disable</button>' : '') +
          '<button class="ss-save" style="font-size:12.5px;font-weight:600;padding:8px 16px;border-radius:8px;cursor:pointer;border:0;background:' + m.color + ';color:#fff;">Save ' + esc(m.name) + '</button>' +
        '</div>' +
        '<details style="margin-top:12px;"><summary style="cursor:pointer;font-size:13px;font-weight:600;color:#1F6080;">How to create the ' + esc(m.name) + ' app</summary>' +
          '<ol style="margin:10px 0 2px;padding-left:20px;font-size:12.5px;color:#334155;line-height:1.45;">' + steps(key, cfg.redirect) + '</ol></details>' +
      '</div>';
  }

  async function render(main) {
    main.setAttribute('data-kt-pretty', '1');
    main.innerHTML = '<div style="padding:14px 24px;"><div class="kt-page-hero"><h2>🔐 Sign-in methods</h2><p>Loading…</p></div></div>';
    var data = await Api.get('/platform/social-config').catch(function (e) { return { __err: e && e.message }; });
    if (data.__err) {
      main.innerHTML = '<div style="padding:14px 24px;"><div class="kt-page-hero"><h2>🔐 Sign-in methods</h2></div>' +
        '<div style="background:#FEF3C7;border:1px solid #FDE68A;color:#92400E;border-radius:12px;padding:18px;max-width:640px;">This page is available to <b>platform administrators</b> only.</div></div>';
      return;
    }
    var p = data.providers || {};
    var anyRedirect = (p.google && p.google.redirect) || '';

    main.innerHTML =
      '<div style="padding:14px 24px;max-width:920px;">' +
        '<div class="kt-page-hero"><h2>🔐 Sign-in methods</h2><p>Let staff and families sign in with Google, Microsoft or Facebook. Each stays hidden on the login page until you enter its credentials. Social sign-in only ever <b>links to an existing invited account</b> by verified email — it never creates new accounts.</p></div>' +
        '<div style="background:#EFF6FF;border:1px solid #BFDBFE;border-radius:12px;padding:14px 16px;margin-bottom:18px;font-size:13px;color:#1E3A5F;">' +
          '<div style="font-weight:700;margin-bottom:4px;">Redirect / callback URL</div>' +
          'When creating each provider app, register this exact URL (swap <code>{provider}</code> for google / microsoft / facebook):' +
          '<div style="display:flex;gap:8px;align-items:center;margin-top:8px;">' +
            '<code id="ss-redirect" style="flex:1;background:#fff;border:1px solid #DBEAFE;border-radius:8px;padding:8px 10px;font-size:12.5px;overflow:auto;">' + esc(anyRedirect.replace(/\/google\//, '/{provider}/')) + '</code>' +
            '<button id="ss-copy" style="font-size:12.5px;font-weight:600;padding:8px 14px;border-radius:8px;cursor:pointer;border:1px solid #BFDBFE;background:#fff;color:#1D4ED8;">Copy</button>' +
          '</div>' +
        '</div>' +
        ['google', 'microsoft', 'facebook'].map(function (k) { return providerCard(k, p[k] || {}); }).join('') +
      '</div>';

    var copyBtn = document.getElementById('ss-copy');
    if (copyBtn) copyBtn.onclick = function () {
      var t = (document.getElementById('ss-redirect') || {}).textContent || '';
      try { navigator.clipboard.writeText(t); copyBtn.textContent = 'Copied ✓'; setTimeout(function () { copyBtn.textContent = 'Copy'; }, 1500); } catch (e) {}
    };

    main.querySelectorAll('.kt-social-card').forEach(function (card) {
      var provider = card.getAttribute('data-provider');
      var save = function (clear) {
        var body = { provider: provider };
        if (clear) { body.clear = true; }
        else {
          body.client_id = (card.querySelector('.ss-client-id') || {}).value || '';
          var sec = (card.querySelector('.ss-secret') || {}).value || '';
          if (sec) body.client_secret = sec;
          var tn = card.querySelector('.ss-tenant'); if (tn) body.tenant = tn.value || 'common';
        }
        var btn = card.querySelector(clear ? '.ss-clear' : '.ss-save');
        var lbl = btn.textContent; btn.textContent = 'Saving…'; btn.disabled = true;
        Api.post('/platform/social-config', body).then(function () {
          if (KT.Dom && KT.Dom.toast) KT.Dom.toast((clear ? 'Disabled ' : 'Saved ') + provider, 'success');
          render(main);
        }).catch(function (e) {
          if (KT.Dom && KT.Dom.toast) KT.Dom.toast('Save failed: ' + (e.message || 'error'), 'error');
          btn.textContent = lbl; btn.disabled = false;
        });
      };
      var sv = card.querySelector('.ss-save'); if (sv) sv.onclick = function () { save(false); };
      var cl = card.querySelector('.ss-clear'); if (cl) cl.onclick = async function () { if (await KT.confirm('Disable ' + provider + ' sign-in? Its credentials will be cleared.')) save(true); };
    });
  }

  ['agency_admin', 'platform_admin'].forEach(function (role) {
    Shell.registerScreen(role + ':social-settings', render);
  });
})(window);
