/* ═══════════════════════════════════════════════════════════════════
   KIDDIETRAC v7 — Forgot Password handler
   Activates the "Forgot?" link on the login page.

   EMAIL, TEXT OR CALL, THEN A 6-DIGIT CODE (2026-10-15). Anthony: "if users forget
   password give them option to send text or voice call along side email verification
   as options and to enter in passcode that was sent (random 6 digit)". Step 1 takes
   the email, username or mobile number and where to send the code; step 2 takes the
   code, and a right code opens the normal reset page. The reply never says whether an
   account matched (POST /auth/forgot answers the same for everyone). The emailed link
   still works too.
   ═══════════════════════════════════════════════════════════════════ */

(function () {
  'use strict';

  const API = 'https://api.kiddietrac.com/api/v1';

  document.addEventListener('DOMContentLoaded', function () {
    // Find the "Forgot?" link — already present in index.html
    const forgotLink = document.querySelector('a[href="#forgot"], .forgot-link, [data-action="forgot"]');

    if (!forgotLink) {
      // If not found by selector, find by text
      const links = document.querySelectorAll('a');
      for (const l of links) {
        if (/forgot/i.test(l.textContent)) {
          attachHandler(l);
          break;
        }
      }
      return;
    }

    attachHandler(forgotLink);
  });

  function attachHandler(link) {
    link.addEventListener('click', function (ev) {
      ev.preventDefault();
      showForgotModal();
    });
  }

  async function post(path, body) {
    const init = {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
      body: JSON.stringify(body),
    };
    const url = API + path;
    const res = (window.KT && KT.net) ? await KT.net.fetch(url, init, { path: path.replace(/^\//, '') }) : await fetch(url, init);
    const json = await res.json().catch(() => ({}));
    return { ok: res.ok, status: res.status, body: json };
  }

  function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c]); }

  const INPUT = 'width:100%;padding:12px;border:1px solid #D1D5DB;border-radius:8px;font-size:16px;box-sizing:border-box;';
  const PRIMARY = 'width:100%;background:#1F6080;color:#fff;border:none;padding:12px;border-radius:8px;font-weight:700;font-size:15px;cursor:pointer;';
  const LINKBTN = 'background:none;border:none;color:#1F6080;font-size:14px;font-weight:600;cursor:pointer;padding:8px 4px;';

  function showForgotModal() {
    // Pre-fill with the email already in the login form
    const loginEmail = document.querySelector('#email, input[type=email], input[name=email]');
    const prefillEmail = loginEmail ? loginEmail.value : '';
    /* If the sign-in form has already demanded a username (needs_username — the address
       is shared), carry it across. That is precisely the case the reset has to get
       right, and making them type it twice is how they end up leaving it blank. */
    const loginUname = document.getElementById('loginUsername');
    const prefillUname = loginUname ? loginUname.value : '';

    const overlay = document.createElement('div');
    overlay.style.cssText = 'position:fixed;inset:0;background:rgba(0,0,0,0.5);z-index:9999;display:flex;align-items:center;justify-content:center;padding:20px;';
    /* Closes only on a click that STARTS and ENDS on the backdrop: a text selection
       dragged out of a field used to close the window mid-typing. */
    let downOnBackdrop = false;
    overlay.addEventListener('mousedown', (e) => { downOnBackdrop = e.target === overlay; });
    overlay.addEventListener('click', (e) => { if (e.target === overlay && downOnBackdrop) overlay.remove(); });

    const card = document.createElement('div');
    card.style.cssText = 'background:#fff;max-width:420px;width:100%;padding:28px;border-radius:16px;box-shadow:0 10px 40px rgba(0,0,0,0.2);max-height:calc(100vh - 40px);overflow-y:auto;box-sizing:border-box;';
    overlay.appendChild(card);
    document.body.appendChild(overlay);

    const state = { identifier: prefillEmail, username: prefillUname, channel: 'email' };

    function close() { overlay.remove(); }
    card.addEventListener('keydown', (e) => { if (e.key === 'Escape') close(); });

    // ── Step 1: who, and where to send the code ──────────────────────────────
    function stepRequest(note) {
      const ch = state.channel;
      const pill = (key, icon, label) => `<button type="button" data-ch="${key}" role="radio" aria-checked="${ch === key}"
          style="flex:1;min-width:0;padding:10px 6px;border-radius:10px;cursor:pointer;font-size:13px;font-weight:700;line-height:1.2;
          border:1.5px solid ${ch === key ? '#1F6080' : '#E2E8F0'};background:${ch === key ? '#EAF4F8' : '#fff'};color:${ch === key ? '#1F6080' : '#475569'};">
          <span style="display:block;font-size:18px;margin-bottom:3px;" aria-hidden="true">${icon}</span>${label}</button>`;
      card.innerHTML = `
        <h2 style="margin:0 0 6px;font-size:22px;color:#1F2937;">Forgot your password?</h2>
        <p style="color:#6B7280;margin:0 0 18px;font-size:14px;">We'll send you a 6-digit code to reset it.</p>

        <label for="forgot-email" style="display:block;font-weight:600;margin-bottom:6px;font-size:14px;">Email, username or mobile number</label>
        <input id="forgot-email" type="text" autocomplete="username" autocapitalize="off" autocorrect="off" spellcheck="false"
               style="${INPUT}margin-bottom:14px;" value="${esc(state.identifier)}">

        <div style="font-weight:600;margin-bottom:6px;font-size:14px;">Send my code by</div>
        <div role="radiogroup" aria-label="Send my code by" style="display:flex;gap:8px;margin-bottom:14px;">
          ${pill('email', '✉️', 'Email')}${pill('sms', '💬', 'Text')}${pill('voice', '📞', 'Call')}
        </div>
        <p id="forgot-chnote" style="color:#9CA3AF;font-size:12px;margin:-6px 0 14px;">${ch === 'email'
          ? 'The email also has a link you can click instead.'
          : 'Sent to the mobile number on your account' + (ch === 'voice' ? ' — we\'ll call and read the code out.' : '.')}</p>

        <!-- ONE ADDRESS CAN HOLD SEVERAL ACCOUNTS. Optional, and stays optional: somebody
             locked out of a password often does not know their username either. -->
        <details ${state.username ? 'open' : ''} style="margin-bottom:14px;">
          <summary style="font-size:13px;color:#1F6080;cursor:pointer;">More than one account on this email?</summary>
          <label for="forgot-username" style="display:block;font-weight:600;margin:10px 0 6px;font-size:14px;">Username <span style="font-weight:400;color:#9CA3AF;">— optional</span></label>
          <input id="forgot-username" type="text" autocomplete="off" autocapitalize="off" autocorrect="off" spellcheck="false"
                 placeholder="only if you have one" style="${INPUT}" value="${esc(state.username)}">
        </details>

        <div id="forgot-status" role="status" style="margin-bottom:12px;font-size:14px;color:#DC2626;">${note ? esc(note) : ''}</div>
        <button id="forgot-submit" type="button" style="${PRIMARY}">Send code</button>
        <div style="display:flex;justify-content:space-between;margin-top:6px;">
          <button id="forgot-have" type="button" style="${LINKBTN}">I already have a code</button>
          <button id="forgot-cancel" type="button" style="${LINKBTN}color:#6B7280;">Cancel</button>
        </div>`;

      const idIn = card.querySelector('#forgot-email');
      const unIn = card.querySelector('#forgot-username');
      const status = card.querySelector('#forgot-status');
      const submit = card.querySelector('#forgot-submit');
      const keep = () => { state.identifier = idIn.value.trim(); state.username = unIn.value.trim(); };

      card.querySelectorAll('[data-ch]').forEach((b) => b.addEventListener('click', () => {
        keep(); state.channel = b.getAttribute('data-ch'); stepRequest();
        card.querySelector('[data-ch="' + state.channel + '"]').focus();
      }));
      card.querySelector('#forgot-cancel').addEventListener('click', close);
      card.querySelector('#forgot-have').addEventListener('click', () => {
        keep();
        if (!state.identifier) { status.textContent = 'Enter the email, username or mobile number you asked about.'; idIn.focus(); return; }
        stepCode('');
      });
      setTimeout(() => idIn.focus(), 50);

      submit.addEventListener('click', async () => {
        keep();
        if (!state.identifier) { status.textContent = 'Please enter your email, username or mobile number.'; idIn.focus(); return; }
        submit.disabled = true; submit.textContent = 'Sending…'; status.textContent = '';
        try {
          const payload = { email: state.identifier, channel: state.channel };
          if (state.username) payload.username = state.username;
          const r = await post('/auth/forgot', payload);
          if (r.status === 429) { throw new Error('Too many requests — wait a minute and try again.'); }
          stepCode(r.body.message || 'If an account matches, we\'ve sent a 6-digit code.');
        } catch (e) {
          status.textContent = (e && e.message && !/fetch/i.test(e.message)) ? e.message
            : ((window.KT && KT.net) ? KT.net.message(e) : 'Network error. Please try again.');
          submit.disabled = false; submit.textContent = 'Send code';
        }
      });
      [idIn, unIn].forEach((el) => el.addEventListener('keydown', (e) => { if (e.key === 'Enter') submit.click(); }));
    }

    // ── Step 2: the code ─────────────────────────────────────────────────────
    function stepCode(sentMsg) {
      const where = { email: 'Check your email (and spam folder).', sms: 'Check your text messages.', voice: 'Answer the call — the code is read out twice.' }[state.channel];
      card.innerHTML = `
        <h2 style="margin:0 0 6px;font-size:22px;color:#1F2937;">Enter your code</h2>
        ${sentMsg ? `<p style="color:#166534;background:#F0FDF4;border-radius:8px;padding:10px 12px;margin:0 0 10px;font-size:13.5px;">✓ ${esc(sentMsg)}</p>` : ''}
        <p style="color:#6B7280;margin:0 0 16px;font-size:13.5px;">${esc(where)} The code works for 10 minutes.</p>

        <label for="forgot-code" style="display:block;font-weight:600;margin-bottom:6px;font-size:14px;">6-digit code</label>
        <input id="forgot-code" type="text" inputmode="numeric" autocomplete="one-time-code" maxlength="7" pattern="[0-9 ]*"
               style="${INPUT}font:700 26px/1.2 ui-monospace,Menlo,Consolas,monospace;letter-spacing:10px;text-align:center;margin-bottom:12px;" placeholder="••••••">

        <div id="forgot-status" role="status" style="margin-bottom:12px;font-size:14px;color:#DC2626;"></div>
        <div id="forgot-accounts"></div>
        <button id="forgot-verify" type="button" style="${PRIMARY}">Continue</button>
        <div style="display:flex;justify-content:space-between;align-items:center;margin-top:6px;flex-wrap:wrap;">
          <button id="forgot-resend" type="button" style="${LINKBTN}" disabled>Send a new code (30)</button>
          <button id="forgot-other" type="button" style="${LINKBTN}">Try another way</button>
        </div>`;

      const codeIn = card.querySelector('#forgot-code');
      const status = card.querySelector('#forgot-status');
      const verify = card.querySelector('#forgot-verify');
      const resend = card.querySelector('#forgot-resend');
      const accountsBox = card.querySelector('#forgot-accounts');
      setTimeout(() => codeIn.focus(), 50);

      // Resend waits 30s — the request endpoint is rate-limited, and a second code
      // replaces the first, so an impatient double-tap would kill the one on its way.
      let left = 30;
      const tick = setInterval(() => {
        left -= 1;
        if (!card.isConnected) { clearInterval(tick); return; }
        if (left <= 0) { clearInterval(tick); resend.disabled = false; resend.textContent = 'Send a new code'; }
        else resend.textContent = 'Send a new code (' + left + ')';
      }, 1000);
      resend.addEventListener('click', () => { clearInterval(tick); stepRequest(); card.querySelector('#forgot-submit').click(); });
      card.querySelector('#forgot-other').addEventListener('click', () => { clearInterval(tick); stepRequest(); });

      const go = (a) => {
        location.href = '/reset-password.html?token=' + encodeURIComponent(a.token) + (a.email ? '&email=' + encodeURIComponent(a.email) : '');
      };

      codeIn.addEventListener('input', () => {
        codeIn.value = codeIn.value.replace(/\D/g, '').slice(0, 6);
        if (codeIn.value.length === 6) verify.click();
      });
      codeIn.addEventListener('keydown', (e) => { if (e.key === 'Enter') verify.click(); });

      verify.addEventListener('click', async () => {
        const code = codeIn.value.replace(/\D/g, '');
        if (code.length !== 6) { status.textContent = 'The code is 6 digits.'; codeIn.focus(); return; }
        if (verify.disabled) return;
        verify.disabled = true; verify.textContent = 'Checking…'; status.textContent = '';
        try {
          const payload = { email: state.identifier, code };
          if (state.username) payload.username = state.username;
          const r = await post('/auth/forgot/verify', payload);
          if (r.status === 429) { throw new Error('Too many tries — wait a minute, then try again.'); }
          const list = (r.body && r.body.accounts) || [];
          if (!r.ok || !list.length) { throw new Error((r.body && r.body.message) || 'That code didn\'t work or has expired.'); }
          if (list.length === 1) { go(list[0]); return; }
          // One code, several accounts on that email/phone: pick the one to reset.
          verify.style.display = 'none';
          accountsBox.innerHTML = '<p style="font-size:14px;font-weight:600;margin:0 0 8px;">Which account do you want to reset?</p>'
            + list.map((a, i) => `<button type="button" data-i="${i}" style="display:block;width:100%;text-align:left;margin-bottom:8px;padding:11px 12px;border:1px solid #CBD5E1;border-radius:10px;background:#fff;cursor:pointer;font-size:14px;">
                <strong>${esc(a.name || a.label)}</strong>${a.label && a.label !== a.name ? ` <span style="color:#64748B;">· ${esc(a.label)}</span>` : ''}</button>`).join('');
          accountsBox.querySelectorAll('[data-i]').forEach((b) => b.addEventListener('click', () => go(list[+b.getAttribute('data-i')])));
        } catch (e) {
          status.textContent = (e && e.message && !/fetch/i.test(e.message)) ? e.message
            : ((window.KT && KT.net) ? KT.net.message(e) : 'Network error. Please try again.');
          verify.disabled = false; verify.textContent = 'Continue';
          codeIn.select();
        }
      });
    }

    stepRequest();
  }
})();
