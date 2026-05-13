/* ═══════════════════════════════════════════════════════════════════
   KIDDIETRAC v7 — Forgot Password handler
   Activates the "Forgot?" link on the login page.
   ═══════════════════════════════════════════════════════════════════ */

(function () {
  'use strict';

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

  function showForgotModal() {
    // Pre-fill with the email already in the login form
    const loginEmail = document.querySelector('input[type=email], input[name=email]');
    const prefillEmail = loginEmail ? loginEmail.value : '';

    // Build modal
    const overlay = document.createElement('div');
    overlay.style.cssText = `
      position: fixed; inset: 0; background: rgba(0,0,0,0.5); z-index: 9999;
      display: flex; align-items: center; justify-content: center; padding: 20px;
    `;
    overlay.addEventListener('click', (e) => { if (e.target === overlay) overlay.remove(); });

    const card = document.createElement('div');
    card.style.cssText = `
      background: white; max-width: 420px; width: 100%; padding: 32px;
      border-radius: 16px; box-shadow: 0 10px 40px rgba(0,0,0,0.2);
    `;

    card.innerHTML = `
      <h2 style="margin: 0 0 8px; font-size: 22px; color: #1F2937;">Forgot your password?</h2>
      <p style="color: #6B7280; margin-bottom: 20px; font-size: 14px;">Enter your email and we'll send you a reset link.</p>

      <label for="forgot-email" style="display: block; font-weight: 600; margin-bottom: 6px; font-size: 14px;">Email</label>
      <input id="forgot-email" type="email" required
             style="width: 100%; padding: 12px; border: 1px solid #D1D5DB; border-radius: 8px; font-size: 15px; box-sizing: border-box; margin-bottom: 16px;"
             value="${prefillEmail.replace(/"/g, '&quot;')}">

      <div id="forgot-status" style="margin-bottom: 12px; font-size: 14px;"></div>

      <button id="forgot-submit" type="button"
              style="width: 100%; background: #1F6080; color: white; border: none; padding: 12px; border-radius: 8px; font-weight: 700; font-size: 15px; cursor: pointer;">
        Send reset link
      </button>
      <button id="forgot-cancel" type="button"
              style="width: 100%; background: none; color: #6B7280; border: none; padding: 12px; font-size: 14px; cursor: pointer; margin-top: 4px;">
        Cancel
      </button>
    `;

    overlay.appendChild(card);
    document.body.appendChild(overlay);

    const emailInput = card.querySelector('#forgot-email');
    const submitBtn = card.querySelector('#forgot-submit');
    const cancelBtn = card.querySelector('#forgot-cancel');
    const status = card.querySelector('#forgot-status');

    setTimeout(() => emailInput.focus(), 50);

    cancelBtn.addEventListener('click', () => overlay.remove());

    submitBtn.addEventListener('click', async () => {
      const email = emailInput.value.trim();
      if (!email) {
        status.style.color = '#DC2626';
        status.textContent = 'Please enter your email.';
        return;
      }

      submitBtn.disabled = true;
      submitBtn.textContent = 'Sending...';
      status.style.color = '#6B7280';
      status.textContent = '';

      try {
        const res = await fetch('https://api.kiddietrac.com/api/v1/auth/forgot', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
          body: JSON.stringify({ email }),
        });
        const body = await res.json().catch(() => ({}));

        // Always show success — we don't leak whether the email exists
        status.style.color = '#16A34A';
        status.innerHTML = '✓ ' + (body.message || 'If that email exists in our system, you\'ll receive a reset link shortly. Check your spam folder if you don\'t see it within 5 minutes.');
        submitBtn.textContent = 'Sent';

        setTimeout(() => overlay.remove(), 6000);
      } catch (e) {
        status.style.color = '#DC2626';
        status.textContent = 'Network error. Please try again.';
        submitBtn.disabled = false;
        submitBtn.textContent = 'Send reset link';
      }
    });

    emailInput.addEventListener('keydown', (e) => {
      if (e.key === 'Enter') submitBtn.click();
      if (e.key === 'Escape') overlay.remove();
    });
  }
})();
