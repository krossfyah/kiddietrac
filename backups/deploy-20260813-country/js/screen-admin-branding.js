/* ═══════════════════════════════════════════════════════════════════
   KIDDIETRAC v15 — White-label Branding (agency_admin)
   ─────────────────────────────────────────────────────────────────
   Lets an agency admin (or platform admin) edit their brand fields
   on the agencies table:
   - brand_logo_url
   - brand_primary_color
   - brand_support_email
   - brand_bank_info
   - powered_by_visible toggle
   Live preview the invoice via /invoices/preview-sample?agency_id=X.

   Persistence: uses PATCH /admin/agencies/{id}/features
   (FeatureFlagController@update accepts brand_* fields).
   ═══════════════════════════════════════════════════════════════════ */
(function (window) {
  'use strict';

  function token() { return sessionStorage.getItem('kt_token') || localStorage.getItem('kt_token'); }
  function getUser() { try { return JSON.parse(sessionStorage.getItem('kt_user') || localStorage.getItem('kt_user') || '{}'); } catch (e) { return {}; } }
  function apiBase() { return (window.KT && window.KT.API_BASE) || 'https://api.kiddietrac.com/api/v1'; }
  async function api(method, path, body) {
    const opts = { method, headers: { 'Authorization': 'Bearer ' + token(), 'Accept': 'application/json' } };
    if (body) { opts.headers['Content-Type'] = 'application/json'; opts.body = JSON.stringify(body); }
    const res = await fetch(apiBase() + path, opts);
    const json = await res.json().catch(() => ({}));
    if (!res.ok) throw new Error(json.message || ('API ' + res.status));
    return json;
  }
  function esc(s) { return s == null ? '' : String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;'); }
  function $(s, r) { return (r || document).querySelector(s); }

  async function render(container) {
    container.innerHTML = '<div style="padding:32px;text-align:center;color:#6B7280;">Loading branding…</div>';
    const user = getUser();
    // v22p87: use the ACTIVE agency (the one being viewed via the agency
    // switcher) rather than the user's home agency. A platform_admin viewing a
    // tenant has no user.agency_id, which produced "Agency not found".
    let agencyId = user.agency_id;
    try {
      const active = parseInt(sessionStorage.getItem('kt_active_agency_id'), 10);
      if (active) agencyId = active;
    } catch (e) { /* sessionStorage unavailable */ }
    if (!agencyId) {
      container.innerHTML = `
        <div style="padding:48px;max-width:600px;margin:0 auto;text-align:center;">
          <div style="background:#FEF3C7;border-left:4px solid #F59E0B;padding:20px;border-radius:8px;text-align:left;">
            <strong style="color:#92400E;">No agency on your account</strong>
            <div style="margin-top:6px;font-size:13px;color:#78350F;">Branding is configured per-agency. Sign in as an agency admin.</div>
          </div>
        </div>`;
      return;
    }

    let data;
    try { data = await api('GET', '/admin/agencies/' + agencyId + '/features'); }
    catch (e) { container.innerHTML = '<div style="padding:24px;color:#DC2626;">' + esc(e.message) + '</div>'; return; }

    const brand = data.branding || {};
    container.innerHTML = `
      <div style="padding:24px;max-width:1800px;">
        <h2 style="font-size:24px;margin:0 0 4px;">🎨 White-Label Branding</h2>
        <p style="color:#6B7280;font-size:13px;margin:0 0 20px;">Customize how invoices and parent-facing screens appear to your families.</p>

        <div style="display:grid;grid-template-columns:1fr 1fr;gap:20px;align-items:start;" id="kt-brand-grid">
          <div style="background:white;border-radius:14px;padding:18px;box-shadow:0 1px 4px rgba(0,0,0,.05);">
            <h3 style="margin:0 0 14px;font-size:15px;">Brand settings</h3>

            ${field('Logo URL', 'kt-logo-url', brand.brand_logo_url || '', 'https://yourdomain.com/logo.png', 'PNG or SVG, recommended height 60-80px.')}
            <div style="display:flex;align-items:center;gap:10px;margin:-6px 0 14px;">
              <input type="file" id="kt-logo-file" accept="image/png,image/jpeg,image/svg+xml,image/webp" style="display:none;">
              <button type="button" id="kt-logo-upload" style="padding:7px 14px;background:white;color:#1F6080;border:1.5px solid #1F6080;border-radius:7px;font-size:12px;font-weight:600;cursor:pointer;">⬆ Upload a logo file</button>
              <span id="kt-logo-upmsg" style="font-size:12px;color:#64748B;">…or paste a URL above</span>
            </div>
            ${colorField('Primary colour', 'kt-color', brand.brand_primary_color || '#3BBBBE')}
            ${field('Support email', 'kt-support', brand.brand_support_email || '', 'billing@yourdomain.com', 'Shown on invoices and parent emails.')}
            ${textareaField('Business address (shown on invoices)', 'kt-address', brand.brand_address || '', '123 Main St\\nToronto, ON  M5V 1A1')}
            ${textareaField('Bank info / payment details', 'kt-bank', brand.brand_bank_info || '', 'Bank: ...\nTransit: ...\nAccount: ...\nor e-Transfer: pay@yourdomain.com')}
            ${field('Privacy policy URL', 'kt-privacy', brand.brand_privacy_url || '', 'https://yourdomain.com/privacy', 'Linked from the "Powered by Kiddietrac" footer on campaigns.')}
            ${field('Terms & conditions URL', 'kt-terms', brand.brand_terms_url || '', 'https://yourdomain.com/terms', 'Linked from the campaign footer alongside your privacy policy.')}

            <div style="display:flex;align-items:center;gap:10px;padding:12px;background:#F9FAFB;border-radius:8px;margin-top:14px;">
              <input type="checkbox" id="kt-poweredby" ${brand.powered_by_visible == 0 ? '' : 'checked'} style="width:18px;height:18px;cursor:pointer;">
              <label for="kt-poweredby" style="font-size:13px;color:#374151;cursor:pointer;flex:1;">
                Show <em>"Powered by Kiddietrac"</em> on invoices
                <div style="font-size:11px;color:#64748B;margin-top:2px;">Uncheck to fully white-label. Plan must support this.</div>
              </label>
            </div>

            <div style="display:flex;gap:10px;margin-top:18px;">
              <button id="kt-preview-btn" style="padding:10px 18px;background:#E5E7EB;color:#374151;border:none;border-radius:8px;font-weight:600;cursor:pointer;">↻ Refresh preview</button>
              <button id="kt-save"         style="flex:1;padding:10px 24px;background:#081C41;color:white;border:none;border-radius:8px;font-weight:700;cursor:pointer;">Save changes</button>
            </div>
            <div id="kt-save-msg" style="margin-top:10px;font-size:13px;text-align:right;"></div>
          </div>

          <div style="background:white;border-radius:14px;padding:0;box-shadow:0 1px 4px rgba(0,0,0,.05);overflow:hidden;">
            <div style="padding:14px 18px;border-bottom:1px solid #E5E7EB;display:flex;justify-content:space-between;align-items:center;">
              <h3 style="margin:0;font-size:15px;">Live invoice preview</h3>
              <a href="#" id="kt-open-fullscreen" style="font-size:12px;color:#3BBBBE;text-decoration:none;font-weight:600;">↗ Open in new tab</a>
            </div>
            <iframe id="kt-preview-iframe" src="" style="width:100%;height:780px;border:0;background:#F9FAFB;"></iframe>
          </div>
        </div>
      </div>
    `;

    refreshPreview(container, agencyId);

    $('#kt-preview-btn',      container).addEventListener('click', () => refreshPreview(container, agencyId));
    $('#kt-save',             container).addEventListener('click', () => save(container, agencyId));

    // v22p88: logo file upload (in addition to the URL field).
    const logoFile = $('#kt-logo-file', container);
    const logoMsg = $('#kt-logo-upmsg', container);
    $('#kt-logo-upload', container).addEventListener('click', () => logoFile.click());
    logoFile.addEventListener('change', async () => {
      const file = logoFile.files[0];
      if (!file) return;
      if (file.size > 2 * 1024 * 1024) { logoMsg.textContent = 'Max 2 MB'; logoMsg.style.color = '#DC2626'; return; }
      logoMsg.textContent = 'Uploading…'; logoMsg.style.color = '#6B7280';
      try {
        const fd = new FormData();
        fd.append('logo', file);
        const tok = sessionStorage.getItem('kt_token');
        const headers = { 'Authorization': 'Bearer ' + tok };
        const active = sessionStorage.getItem('kt_active_agency_id');
        if (active) headers['X-Active-Agency-Id'] = active;
        const r = await fetch(apiBase() + '/admin/branding/logo', { method: 'POST', headers, body: fd });
        const j = await r.json().catch(() => ({}));
        if (!r.ok) throw new Error(j.message || ('HTTP ' + r.status));
        const url = j.url || j.logo_url || j.brand_logo_url;
        if (url) {
          $('#kt-logo-url', container).value = url;
          logoMsg.textContent = '✓ Uploaded — click Save to apply'; logoMsg.style.color = '#16A34A';
        } else {
          logoMsg.textContent = 'Uploaded but no URL returned'; logoMsg.style.color = '#DC2626';
        }
      } catch (e) {
        logoMsg.textContent = 'Upload failed: ' + (e.message || 'error'); logoMsg.style.color = '#DC2626';
      }
    });
    $('#kt-open-fullscreen',  container).addEventListener('click', async (e) => {
      e.preventDefault();
      try {
        const res = await fetch(apiBase() + '/invoices/preview-sample?agency_id=' + agencyId, {
          headers: { 'Authorization': 'Bearer ' + token() },
        });
        if (!res.ok) throw new Error('HTTP ' + res.status);
        const html = await res.text();
        const blob = new Blob([html], { type: 'text/html' });
        const url = URL.createObjectURL(blob);
        window.open(url, '_blank');
        // revoke after a delay to let the new tab finish loading
        setTimeout(() => URL.revokeObjectURL(url), 30000);
      } catch (err) {
        alert('Could not open preview: ' + (err.message || 'unknown'));
      }
    });
  }

  function field(label, id, value, placeholder, hint) {
    return `<div style="margin-bottom:12px;">
      <label style="font-size:12px;font-weight:700;color:#6B7280;">${esc(label)}</label>
      <input type="text" id="${id}" value="${esc(value)}" placeholder="${esc(placeholder)}" style="width:100%;padding:9px 11px;border:1px solid #D1D5DB;border-radius:6px;font-size:13px;margin-top:4px;">
      ${hint ? `<div style="font-size:11px;color:#64748B;margin-top:3px;">${esc(hint)}</div>` : ''}
    </div>`;
  }
  function textareaField(label, id, value, placeholder) {
    return `<div style="margin-bottom:12px;">
      <label style="font-size:12px;font-weight:700;color:#6B7280;">${esc(label)}</label>
      <textarea id="${id}" placeholder="${esc(placeholder)}" rows="4" style="width:100%;padding:9px 11px;border:1px solid #D1D5DB;border-radius:6px;font-size:13px;margin-top:4px;font-family:inherit;resize:vertical;">${esc(value)}</textarea>
    </div>`;
  }
  function colorField(label, id, value) {
    return `<div style="margin-bottom:12px;">
      <label style="font-size:12px;font-weight:700;color:#6B7280;">${esc(label)}</label>
      <div style="display:flex;gap:8px;align-items:center;margin-top:4px;">
        <input type="color" id="${id}-picker" value="${esc(value)}" style="width:50px;height:38px;padding:0;border:1px solid #D1D5DB;border-radius:6px;cursor:pointer;background:white;">
        <input type="text" id="${id}" value="${esc(value)}" style="flex:1;padding:9px 11px;border:1px solid #D1D5DB;border-radius:6px;font-size:13px;font-family:monospace;">
      </div>
    </div>`;
  }

  async function refreshPreview(container, agencyId) {
    const iframe = $('#kt-preview-iframe', container);
    iframe.srcdoc = '<div style="padding:40px;text-align:center;color:#64748B;font-family:sans-serif;">Loading preview…</div>';
    try {
      const res = await fetch(apiBase() + '/invoices/preview-sample?agency_id=' + agencyId + '&_=' + Date.now(), {
        headers: { 'Authorization': 'Bearer ' + token() },
      });
      if (!res.ok) throw new Error('HTTP ' + res.status);
      const html = await res.text();
      iframe.srcdoc = html;
    } catch (e) {
      iframe.srcdoc = '<div style="padding:40px;text-align:center;color:#DC2626;font-family:sans-serif;">Preview failed: ' + (e.message || 'unknown') + '</div>';
    }
  }

  async function save(container, agencyId) {
    const colorPicker = $('#kt-color-picker', container);
    const colorText   = $('#kt-color', container);
    // Sync picker → text
    colorPicker.addEventListener('input', () => colorText.value = colorPicker.value);
    const payload = {
      brand_logo_url:       $('#kt-logo-url', container).value.trim() || null,
      brand_primary_color:  colorText.value.trim() || null,
      brand_support_email:  $('#kt-support', container).value.trim() || null,
      brand_address:        $('#kt-address', container).value.trim() || null,
      brand_bank_info:      $('#kt-bank',    container).value.trim() || null,
      brand_privacy_url:    $('#kt-privacy', container).value.trim() || null,
      brand_terms_url:      $('#kt-terms',   container).value.trim() || null,
      powered_by_visible:   $('#kt-poweredby', container).checked ? 1 : 0,
    };
    const msg = $('#kt-save-msg', container);
    msg.textContent = 'Saving…'; msg.style.color = '#6B7280';
    try {
      await api('PATCH', '/admin/agencies/' + agencyId + '/features', payload);
      msg.textContent = '✓ Saved'; msg.style.color = '#16A34A';
      setTimeout(() => refreshPreview(container, agencyId), 400);
    } catch (e) {
      msg.textContent = '✗ ' + e.message; msg.style.color = '#DC2626';
    }
  }

  // Wire colour picker ↔ text input after each render (delegated)
  document.addEventListener('input', (e) => {
    if (e.target && e.target.id === 'kt-color-picker') {
      const tgt = document.getElementById('kt-color');
      if (tgt) tgt.value = e.target.value;
    } else if (e.target && e.target.id === 'kt-color') {
      const tgt = document.getElementById('kt-color-picker');
      if (tgt && /^#[0-9a-f]{6}$/i.test(e.target.value)) tgt.value = e.target.value;
    }
  });

  window.KT = window.KT || {};
  window.KT.AdminBranding = { render: render };
})(window);
