/* ============================================================
   KIDDIETRAC v22p7.3 — MFA (TOTP) settings screen
   Hash: #mfa — registered for centre_director + agency_admin
   States: loading → disabled / setup_in_progress / enabled
   Backend: /api/v1/auth/mfa/{status,setup,confirm,disable}
   ============================================================ */
(function (window) {
  'use strict';
  if (!window.KT) return;
  var KT = window.KT;
  var Api = KT.Api;
  var Dom = KT.Dom;
  var Shell = KT.Shell;

  function esc(s) {
    return s == null ? '' : String(s)
      .replace(/&/g, '&amp;').replace(/</g, '&lt;')
      .replace(/>/g, '&gt;').replace(/"/g, '&quot;');
  }

  function btn(label, styles, onClick) {
    var b = Dom.el('button', {
      style: 'border:none;border-radius:8px;padding:10px 16px;font-size:14px;font-weight:600;cursor:pointer;' + (styles || ''),
    }, label);
    if (onClick) b.addEventListener('click', onClick);
    return b;
  }

  function primary() { return 'background:#1F6080;color:white;'; }
  function danger()  { return 'background:#DC2626;color:white;'; }
  function ghost()   { return 'background:white;color:#374151;border:1px solid #D1D5DB!important;'; }

  function card(title, kids) {
    var c = Dom.el('div', { style: 'background:white;border-radius:14px;padding:24px;box-shadow:0 1px 3px rgba(0,0,0,.06);margin-bottom:16px;' });
    if (title) c.appendChild(Dom.el('h3', { style: 'margin:0 0 12px;font-size:18px;' }, title));
    (kids || []).forEach(function (k) { c.appendChild(k); });
    return c;
  }

  function statusBadge(enabled) {
    return Dom.el('span', {
      style: 'display:inline-block;padding:4px 12px;border-radius:999px;font-size:11px;font-weight:700;letter-spacing:0.5px;background:'
        + (enabled ? '#16A34A' : '#9CA3AF')
        + ';color:white;',
    }, enabled ? 'MFA ENABLED' : 'MFA DISABLED');
  }

  function copyToClipboard(text) {
    if (navigator.clipboard && navigator.clipboard.writeText) {
      navigator.clipboard.writeText(text).then(function () {
        if (Dom.toast) Dom.toast('Copied', 'success');
      });
    } else {
      // Fallback: hidden textarea
      var ta = document.createElement('textarea');
      ta.value = text;
      document.body.appendChild(ta);
      ta.select();
      try { document.execCommand('copy'); if (Dom.toast) Dom.toast('Copied', 'success'); }
      catch (e) { /* noop */ }
      ta.remove();
    }
  }

  function copyableRow(label, value, mono) {
    var row = Dom.el('div', { style: 'display:grid;grid-template-columns:140px 1fr auto;gap:12px;align-items:center;padding:8px 0;border-bottom:1px solid #F3F4F6;' });
    row.appendChild(Dom.el('div', { style: 'font-size:13px;color:#6B7280;font-weight:600;' }, label));
    var v = Dom.el('div', {
      style: 'font-size:13px;color:#111827;' + (mono ? 'font-family:ui-monospace,monospace;word-break:break-all;' : ''),
    }, value);
    row.appendChild(v);
    row.appendChild(btn('Copy', ghost(), function () { copyToClipboard(value); }));
    return row;
  }

  function renderMfa(container) {
    Dom.clear(container);
    var wrap = Dom.el('div', { style: 'padding:24px;max-width:900px;margin:0 auto;' });
    container.appendChild(wrap);

    wrap.appendChild(Dom.el('h1', {
      style: 'font-size:24px;margin:0 0 16px;',
    }, '🔐 Two-factor authentication'));

    var loading = Dom.el('div', {
      style: 'padding:40px;text-align:center;color:#6B7280;',
    }, 'Loading…');
    wrap.appendChild(loading);

    Api.get('/auth/mfa/status').then(function (status) {
      Dom.clear(wrap);
      wrap.appendChild(Dom.el('h1', { style: 'font-size:24px;margin:0 0 16px;' }, '🔐 Two-factor authentication'));
      wrap.appendChild(card(null, [
        statusBadge(status.enabled),
        Dom.el('p', {
          style: 'margin:12px 0 0;font-size:14px;color:#374151;line-height:1.5;',
        }, status.enabled
          ? 'You\'re using time-based codes (TOTP) at sign-in. Even if your password is leaked, an attacker cannot access your account without your authenticator app.'
          : 'Add a second factor — a 6-digit code from your authenticator app — to make your account significantly harder to compromise. Strongly recommended for agency admins and centre directors.'),
      ]));

      if (status.enabled) {
        renderEnabledState(wrap);
      } else {
        renderDisabledState(wrap);
      }
    }).catch(function (e) {
      Dom.clear(wrap);
      wrap.appendChild(Dom.el('div', { style: 'padding:24px;color:#DC2626;' }, 'Could not load MFA status: ' + e.message));
    });
  }

  function renderDisabledState(wrap) {
    var c = card('Get started', [
      Dom.el('p', { style: 'font-size:14px;color:#374151;line-height:1.5;margin:0 0 16px;' },
        'You\'ll need an authenticator app on your phone — Google Authenticator, Authy, 1Password, or Bitwarden all work.'),
    ]);
    var enableBtn = btn('🛡 Set up MFA', primary(), function () {
      enableBtn.disabled = true;
      enableBtn.textContent = 'Generating…';
      Api.post('/auth/mfa/setup', {}).then(function (data) {
        c.remove();
        renderSetupInProgress(wrap, data);
      }).catch(function (e) {
        enableBtn.disabled = false;
        enableBtn.textContent = '🛡 Set up MFA';
        alert('Setup failed: ' + e.message);
      });
    });
    c.appendChild(enableBtn);
    wrap.appendChild(c);
  }

  function renderSetupInProgress(wrap, data) {
    var c1 = card('Step 1 — Add the secret to your app', [
      Dom.el('p', { style: 'font-size:14px;margin:0 0 12px;color:#374151;' },
        'In your authenticator app, choose "Add account" → "Enter setup key" and paste the secret below. (QR code rendering coming soon.)'),
      copyableRow('Secret', data.secret, true),
      copyableRow('OTPAuth URI', data.otpauth_uri, true),
      Dom.el('p', { style: 'font-size:12px;color:#6B7280;margin:8px 0 0;' },
        'On a phone, you can also tap the OTPAuth URI link to launch your authenticator app directly.'),
    ]);
    wrap.appendChild(c1);

    var codesBlock = Dom.el('div', { style: 'background:#FEF3C7;border-radius:10px;padding:14px 18px;margin:8px 0;' });
    codesBlock.appendChild(Dom.el('p', { style: 'margin:0 0 8px;font-size:13px;color:#92400E;font-weight:600;' },
      '⚠️ Save these recovery codes NOW — they are shown only once.'));
    var codesGrid = Dom.el('div', { style: 'display:grid;grid-template-columns:repeat(2,1fr);gap:6px;font-family:ui-monospace,monospace;font-size:13px;color:#7F1D1D;margin-bottom:10px;' });
    (data.recovery_codes || []).forEach(function (rc) {
      codesGrid.appendChild(Dom.el('div', { style: 'padding:4px 8px;background:white;border-radius:4px;' }, rc));
    });
    codesBlock.appendChild(codesGrid);
    codesBlock.appendChild(btn('📋 Copy all codes', ghost(), function () {
      copyToClipboard((data.recovery_codes || []).join('\n'));
    }));

    var c2 = card('Step 2 — Save your recovery codes', [
      Dom.el('p', { style: 'font-size:14px;margin:0 0 12px;color:#374151;' },
        'If you lose access to your authenticator app, each recovery code lets you sign in once. Save them somewhere safe — a password manager is ideal.'),
      codesBlock,
    ]);
    wrap.appendChild(c2);

    var codeInput = Dom.el('input', {
      type: 'text', inputmode: 'numeric', maxlength: '6',
      placeholder: '000000',
      style: 'width:160px;padding:10px;border:1px solid #D1D5DB;border-radius:8px;font-size:18px;text-align:center;font-family:ui-monospace,monospace;letter-spacing:4px;',
    });
    var confirmBtn = btn('Confirm and turn on', primary(), function () {
      var code = (codeInput.value || '').trim();
      if (!/^\d{6}$/.test(code)) { alert('Enter the 6-digit code from your app.'); return; }
      confirmBtn.disabled = true;
      confirmBtn.textContent = 'Verifying…';
      Api.post('/auth/mfa/confirm', { code: code }).then(function () {
        if (Dom.toast) Dom.toast('✓ MFA enabled', 'success');
        renderMfa(wrap.parentNode); // re-fetch + re-render
      }).catch(function (e) {
        confirmBtn.disabled = false;
        confirmBtn.textContent = 'Confirm and turn on';
        alert('Verification failed: ' + (e.message || 'Code did not match. Make sure your phone time is synced.'));
      });
    });
    var c3 = card('Step 3 — Confirm', [
      Dom.el('p', { style: 'font-size:14px;margin:0 0 12px;color:#374151;' },
        'Enter the current 6-digit code from your authenticator app to finish setup.'),
      Dom.el('div', { style: 'display:flex;gap:10px;align-items:center;' }, [codeInput, confirmBtn]),
    ]);
    wrap.appendChild(c3);
  }

  function renderEnabledState(wrap) {
    var codeInput = Dom.el('input', {
      type: 'text', inputmode: 'numeric', maxlength: '14',
      placeholder: '000000 or recovery code',
      style: 'width:240px;padding:10px;border:1px solid #D1D5DB;border-radius:8px;font-size:14px;font-family:ui-monospace,monospace;',
    });
    var disableBtn = btn('🛑 Disable MFA', danger(), function () {
      var code = (codeInput.value || '').trim().toUpperCase();
      if (!code) { alert('Enter your current code or a recovery code to disable.'); return; }
      if (!confirm('Disable MFA? Your account will be protected by password only.')) return;
      disableBtn.disabled = true;
      disableBtn.textContent = 'Disabling…';
      Api.post('/auth/mfa/disable', { code: code }).then(function () {
        if (Dom.toast) Dom.toast('MFA disabled', 'success');
        renderMfa(wrap.parentNode);
      }).catch(function (e) {
        disableBtn.disabled = false;
        disableBtn.textContent = '🛑 Disable MFA';
        alert('Could not disable: ' + (e.message || 'Code did not match.'));
      });
    });

    wrap.appendChild(card('Disable MFA', [
      Dom.el('p', { style: 'font-size:14px;color:#374151;margin:0 0 12px;' },
        'To turn off two-factor sign-in, enter your current code or one of your recovery codes.'),
      Dom.el('div', { style: 'display:flex;gap:10px;align-items:center;' }, [codeInput, disableBtn]),
    ]));
  }

  if (Shell && Shell.registerScreen) {
    Shell.registerScreen('agency_admin:mfa', renderMfa);
    Shell.registerScreen('centre_director:mfa', renderMfa);
    Shell.registerScreen('educator:mfa', renderMfa);
    Shell.registerScreen('guardian:mfa', renderMfa);
  }

  KT.MfaScreen = { render: renderMfa };
})(window);
