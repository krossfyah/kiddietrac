/* ═══════════════════════════════════════════════════════════════════
   KIDDIETRAC v13 — Autopay (parent billing settings)
   Loads Stripe.js dynamically; only used on the autopay screen.
   ═══════════════════════════════════════════════════════════════════ */
(function (window) {
  'use strict';

  function token() { return sessionStorage.getItem('kt_token') || localStorage.getItem('kt_token'); }
  function apiBase() { return (window.KT && window.KT.API_BASE) || 'https://api.kiddietrac.com/api/v1'; }

  async function api(method, path, body) {
    const opts = { method, headers: { 'Authorization': 'Bearer ' + token(), 'Accept': 'application/json' } };
    if (body !== undefined) { opts.headers['Content-Type'] = 'application/json'; opts.body = JSON.stringify(body); }
    const res = await fetch(apiBase() + path, opts);
    const json = await res.json().catch(() => ({}));
    if (!res.ok) throw new Error(json.message || ('API ' + res.status));
    return json;
  }

  function esc(s) { return s == null ? '' : String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;'); }
  function $(s, r) { return (r || document).querySelector(s); }

  async function loadStripeJs() {
    if (window.Stripe) return window.Stripe;
    await new Promise((resolve, reject) => {
      const s = document.createElement('script');
      s.src = 'https://js.stripe.com/v3/';
      s.onload = resolve;
      s.onerror = reject;
      document.head.appendChild(s);
    });
    return window.Stripe;
  }

  async function render(container) {
    container.innerHTML = '<div style="padding:32px;text-align:center;color:#6B7280;">Loading…</div>';
    let data;
    try { data = await api('GET', '/parent/billing/autopay-status'); }
    catch (e) {
      container.innerHTML = '<div style="padding:24px;color:#DC2626;">Could not load: ' + esc(e.message) + '</div>';
      return;
    }

    const families = data.families || [];
    container.innerHTML = `
      <div style="padding:24px;max-width:760px;">
        <h2 style="font-size:24px;margin:0 0 4px;">💳 Autopay</h2>
        <p style="color:#6B7280;font-size:14px;margin:0 0 20px;">Save a card so your monthly invoices are charged automatically. No more reminders.</p>

        ${families.length === 0
          ? '<div style="padding:48px;background:white;border-radius:14px;text-align:center;color:#6B7280;">No families found.</div>'
          : `<div data-kt-list="1" style="display:grid;gap:14px;">
              ${families.map(f => familyCard(f)).join('')}
            </div>`
        }
        <div id="kt-mount"></div>
      </div>
    `;

    container.querySelectorAll('.kt-ap-switch').forEach(b => b.addEventListener('click', () => {
      if (b.dataset.on === '1') disable(container, parseInt(b.dataset.fid, 10), b.dataset.fname);
      else openCardModal(container, parseInt(b.dataset.fid, 10), b.dataset.fname);
    }));
  }

  function familyCard(f) {
    return `
      <div style="background:white;border-radius:14px;padding:20px;box-shadow:0 2px 6px rgba(0,0,0,.05);">
        <div style="display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap;">
          <div>
            <div style="font-weight:700;font-size:16px;color:#111827;">${esc(f.family_name)}</div>
            ${f.autopay_enabled
              ? `<div style="font-size:13px;color:#16A34A;margin-top:4px;">✓ Autopay on · ${esc(f.card_brand || 'card')} ending in ${esc(f.card_last4 || '••••')}</div>`
              : '<div style="font-size:13px;color:#6B7280;margin-top:4px;">Autopay off · monthly invoice must be paid manually</div>'
            }
          </div>
          <button class="kt-ap-switch" data-fid="${f.family_id}" data-fname="${esc(f.family_name)}" data-on="${f.autopay_enabled ? '1' : '0'}" role="switch" aria-checked="${f.autopay_enabled ? 'true' : 'false'}" aria-label="Autopay for ${esc(f.family_name)}" style="-webkit-appearance:none;appearance:none;position:relative;flex:0 0 auto;width:48px;height:28px;min-height:0;max-height:28px;box-sizing:border-box;border-radius:999px;border:none;background:${f.autopay_enabled ? '#159FB4' : '#CBD5E1'};cursor:pointer;padding:0;font:inherit;transition:background .18s ease;">
            <span style="position:absolute;top:3px;left:3px;width:22px;height:22px;border-radius:50%;background:#fff;box-shadow:0 1px 3px rgba(0,0,0,.28);transition:transform .18s ease;transform:${f.autopay_enabled ? 'translateX(20px)' : 'translateX(0)'};"></span>
          </button>
        </div>
      </div>
    `;
  }

  async function disable(container, familyId, familyName) {
    if (!await KT.confirm('Turn off autopay for ' + familyName + '? You\'ll go back to paying invoices manually.')) return;
    try {
      await api('POST', '/parent/billing/disable-autopay', { family_id: familyId });
      render(container);
    } catch (e) { alert('Could not disable: ' + e.message); }
  }

  async function openCardModal(container, familyId, familyName) {
    const mount = $('#kt-mount', container);
    mount.innerHTML = `
      <div class="kt-modal-overlay" style="position:fixed;inset:0;background:rgba(0,0,0,.5);display:flex;align-items:center;justify-content:center;z-index:9999;padding:16px;">
        <div style="background:white;border-radius:14px;max-width:480px;width:100%;padding:24px;">
          <h2 style="font-size:20px;margin:0 0 4px;">Enable autopay for ${esc(familyName)}</h2>
          <p style="color:#6B7280;font-size:13px;margin:0 0 18px;">Your card is saved securely with Stripe. You can turn this off any time.</p>
          <div id="kt-card-element" style="padding:12px;border:1px solid #D1D5DB;border-radius:8px;background:white;"></div>
          <div id="kt-card-errors" style="color:#DC2626;font-size:13px;min-height:18px;margin-top:8px;"></div>
          <div id="kt-status" style="min-height:20px;font-size:14px;margin-top:8px;"></div>
          <div style="display:flex;justify-content:flex-end;gap:8px;margin-top:16px;">
            <button type="button" id="kt-cancel" style="background:#F3F4F6;color:#374151;border:none;padding:10px 18px;border-radius:8px;font-weight:600;cursor:pointer;">Cancel</button>
            <button type="button" id="kt-save-card" style="background:#1F6080;color:white;border:none;padding:10px 22px;border-radius:8px;font-weight:700;cursor:pointer;">Save card</button>
          </div>
          <p style="font-size:12px;color:#64748B;text-align:center;margin-top:16px;">🔒 Powered by Stripe · Your card details never touch our servers.</p>
        </div>
      </div>
    `;
    const overlay = $('.kt-modal-overlay', mount);
    const close = () => mount.innerHTML = '';
    overlay.addEventListener('click', (e) => { if (e.target === overlay) close(); });
    $('#kt-cancel', mount).addEventListener('click', close);

    $('#kt-status', mount).textContent = 'Connecting to payment processor…';
    let intent;
    try {
      intent = await api('POST', '/parent/billing/setup-intent', { family_id: familyId });
    } catch (e) {
      // The backend 500s when Stripe isn't configured (empty STRIPE_KEY/SECRET).
      // Show parents a calm, actionable message instead of a raw error.
      $('#kt-status', mount).style.color = '#B45309';
      $('#kt-status', mount).innerHTML = 'Online card payments aren’t switched on for your centre yet. '
        + 'Please contact your centre — they can enable this in KiddieTrac. '
        + '<span style="color:#64748B;">(You can still pay your invoices by e-transfer or the method your centre uses.)</span>';
      return;
    }
    $('#kt-status', mount).textContent = '';

    if (!intent.publishable_key) {
      $('#kt-status', mount).style.color = '#DC2626';
      $('#kt-status', mount).textContent = 'Stripe is not configured on this centre yet. Ask your director to set up online payments.';
      return;
    }

    let Stripe;
    try { Stripe = await loadStripeJs(); }
    catch (e) {
      $('#kt-status', mount).style.color = '#DC2626';
      $('#kt-status', mount).textContent = 'Could not load Stripe.js';
      return;
    }

    const stripe = Stripe(intent.publishable_key, { stripeAccount: intent.connect_account_id });
    const elements = stripe.elements();
    const card = elements.create('card', { hidePostalCode: false });
    card.mount('#kt-card-element');
    card.on('change', (e) => {
      $('#kt-card-errors', mount).textContent = e.error ? e.error.message : '';
    });

    $('#kt-save-card', mount).addEventListener('click', async () => {
      $('#kt-save-card', mount).disabled = true;
      $('#kt-status', mount).style.color = '#6B7280';
      $('#kt-status', mount).textContent = 'Saving card…';

      const result = await stripe.confirmCardSetup(intent.client_secret, {
        payment_method: { card: card },
      });

      if (result.error) {
        $('#kt-status', mount).style.color = '#DC2626';
        $('#kt-status', mount).textContent = '✗ ' + result.error.message;
        $('#kt-save-card', mount).disabled = false;
        return;
      }

      try {
        await api('POST', '/parent/billing/confirm-autopay', {
          family_id: familyId,
          payment_method_id: result.setupIntent.payment_method,
        });
        $('#kt-status', mount).style.color = '#16A34A';
        $('#kt-status', mount).textContent = '✓ Autopay enabled';
        setTimeout(() => { close(); render(container); }, 1200);
      } catch (e) {
        $('#kt-status', mount).style.color = '#DC2626';
        $('#kt-status', mount).textContent = '✗ ' + e.message;
        $('#kt-save-card', mount).disabled = false;
      }
    });
  }

  window.KT = window.KT || {};
  window.KT.Autopay = { render };
})(window);
