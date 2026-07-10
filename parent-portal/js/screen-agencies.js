/* ═══════════════════════════════════════════════════════════════════
   KIDDIETRAC v12-big — Agencies screen (platform admin)
   - List all agencies (or just yours, depending on role)
   - "Create new agency" form
   - Edit subdomain, custom_domain, plan, status
   ═══════════════════════════════════════════════════════════════════ */
(function (window) {
  'use strict';

  function $(sel, root = document) { return root.querySelector(sel); }
  function $$(sel, root = document) { return root.querySelectorAll(sel); }

  function token() { return sessionStorage.getItem('kt_token') || localStorage.getItem('kt_token'); }
  function apiBase() { return (window.KT && window.KT.API_BASE) || 'https://api.kiddietrac.com/api/v1'; }

  async function api(method, path, body) {
    const opts = {
      method: method,
      headers: { 'Authorization': 'Bearer ' + token(), 'Accept': 'application/json' },
    };
    if (body !== undefined) {
      opts.headers['Content-Type'] = 'application/json';
      opts.body = JSON.stringify(body);
    }
    const res = await fetch(apiBase() + path, opts);
    const json = await res.json().catch(() => ({}));
    if (!res.ok) throw new Error(json.message || 'API ' + res.status);
    return json;
  }

  function esc(s) {
    return s == null ? '' : String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
  }

  async function render(container) {
    container.innerHTML = '<div style="padding:32px;text-align:center;color:#6B7280;">Loading agencies…</div>';
    let data;
    try { data = await api('GET', '/admin/agencies'); }
    catch (e) {
      container.innerHTML = `<div style="padding:24px;color:#DC2626;">Could not load: ${esc(e.message)}</div>`;
      return;
    }

    const agencies = data.agencies || [];
    const isPlatform = !!data.is_platform_admin;

    container.innerHTML = `
      <div style="padding:24px;max-width:1800px;">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:24px;flex-wrap:wrap;gap:12px;">
          <div>
            <h2 style="font-size:24px;margin:0;">🏢 Agencies</h2>
            <p style="color:#6B7280;margin:4px 0 0;font-size:14px;">
              ${isPlatform ? 'Platform-wide view of all tenants.' : 'Your agency.'}
            </p>
          </div>
          ${isPlatform ? '<button id="kt-new-agency" style="background:#1F6080;color:white;border:none;padding:12px 22px;border-radius:10px;font-weight:700;cursor:pointer;">+ New Agency</button>' : ''}
        </div>

        <div id="kt-agency-list" data-kt-list="1" style="display:grid;gap:16px;">
          ${agencies.map(a => agencyCard(a)).join('')}
        </div>

        ${agencies.length === 0 ? '<div style="text-align:center;padding:48px;color:#6B7280;">No agencies yet.</div>' : ''}

        <div id="kt-agency-modal-mount"></div>
      </div>
    `;

    const newBtn = $('#kt-new-agency', container);
    if (newBtn) newBtn.addEventListener('click', () => openCreateModal(container));

    $$('.kt-edit-agency', container).forEach(b => b.addEventListener('click', () => openEditModal(container, parseInt(b.dataset.aid, 10))));
  }

  function agencyCard(a) {
    const statusColor = a.billing_status === 'active' ? '#16A34A'
                     : a.billing_status === 'trial'   ? '#0EA5E9'
                     : a.billing_status === 'past_due' ? '#F59E0B'
                     : '#DC2626';
    const trialEnds = a.trial_ends_at ? new Date(a.trial_ends_at).toLocaleDateString() : '';
    const url = a.custom_domain ? 'https://' + a.custom_domain
              : a.subdomain ? 'https://' + a.subdomain + '.kiddietrac.com'
              : 'https://app.kiddietrac.com';
    return `
      <div style="background:white;border-radius:14px;padding:20px;box-shadow:0 2px 8px rgba(0,0,0,.06);display:grid;grid-template-columns:1fr auto;gap:16px;align-items:center;">
        <div>
          <div style="display:flex;align-items:center;gap:10px;margin-bottom:6px;">
            <div style="font-size:18px;font-weight:700;color:#111827;">${esc(a.name)}</div>
            <span style="background:${statusColor}22;color:${statusColor};padding:2px 8px;border-radius:10px;font-size:11px;font-weight:700;text-transform:uppercase;">${esc(a.billing_status)}</span>
          </div>
          <div style="font-size:14px;color:#6B7280;display:flex;flex-wrap:wrap;gap:12px;">
            <span>🌐 <a href="${esc(url)}" target="_blank" style="color:#1F6080;">${esc(url.replace(/^https?:\/\//, ''))}</a></span>
            <span>📦 Plan: ${esc(a.plan)}</span>
            <span>🏫 ${a.centre_count} centres</span>
            <span>👥 ${a.user_count} users</span>
            ${trialEnds ? `<span>⏱ Trial ends ${esc(trialEnds)}</span>` : ''}
          </div>
        </div>
        <button class="kt-edit-agency" data-aid="${a.id}" style="background:#F3F4F6;color:#374151;border:none;padding:10px 16px;border-radius:8px;font-weight:600;cursor:pointer;">Manage</button>
      </div>
    `;
  }

  function openCreateModal(container) {
    const mount = $('#kt-agency-modal-mount', container);
    mount.innerHTML = `
      <div class="kt-modal-overlay" style="position:fixed;inset:0;background:rgba(0,0,0,.5);display:flex;align-items:center;justify-content:center;z-index:9999;padding:16px;">
        <div style="background:white;border-radius:16px;max-width:640px;width:100%;max-height:90vh;overflow-y:auto;">
          <div style="padding:24px 24px 0;">
            <h2 style="font-size:22px;margin:0 0 4px;">Create new agency</h2>
            <p style="color:#6B7280;font-size:14px;margin:0 0 20px;">Provisions a new tenant + first centre + admin invite.</p>
          </div>
          <form id="kt-agency-form" onsubmit="return false;" style="padding:0 24px 24px;display:grid;gap:14px;">
            <section style="border:1px solid #E5E7EB;border-radius:10px;padding:16px;">
              <h3 style="font-size:14px;text-transform:uppercase;letter-spacing:1px;color:#6B7280;margin:0 0 10px;">Agency</h3>
              <label style="font-size:13px;font-weight:600;">Name *</label>
              <input name="agency_name" required style="${inputStyle()}">
              <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;">
                <div>
                  <label style="font-size:13px;font-weight:600;">Subdomain</label>
                  <input name="subdomain" placeholder="acme" style="${inputStyle()}">
                  <div style="font-size:11px;color:#9CA3AF;">acme.kiddietrac.com</div>
                </div>
                <div>
                  <label style="font-size:13px;font-weight:600;">Custom domain</label>
                  <input name="custom_domain" placeholder="childcare.acme.com" style="${inputStyle()}">
                  <div style="font-size:11px;color:#9CA3AF;">requires DNS setup</div>
                </div>
              </div>
              <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;">
                <div>
                  <label style="font-size:13px;font-weight:600;">Plan *</label>
                  <select name="plan" required style="${inputStyle()}">
                    <option value="starter">Starter — $49/mo (≤12 kids)</option>
                    <option value="centre" selected>Centre — $149/mo (≤80 kids)</option>
                    <option value="agency">Agency — $349/mo (unlimited)</option>
                  </select>
                </div>
                <div>
                  <label style="font-size:13px;font-weight:600;">Trial (days)</label>
                  <input name="trial_days" type="number" min="1" max="90" value="14" style="${inputStyle()}">
                </div>
              </div>
              <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;">
                <div>
                  <label style="font-size:13px;font-weight:600;">Primary color</label>
                  <input name="primary_color" type="color" value="#1F6080" style="${inputStyle()};height:42px;">
                </div>
                <div>
                  <label style="font-size:13px;font-weight:600;">Accent color</label>
                  <input name="accent_color" type="color" value="#8EC73C" style="${inputStyle()};height:42px;">
                </div>
              </div>
            </section>
            <section style="border:1px solid #E5E7EB;border-radius:10px;padding:16px;">
              <h3 style="font-size:14px;text-transform:uppercase;letter-spacing:1px;color:#6B7280;margin:0 0 10px;">First centre</h3>
              <label style="font-size:13px;font-weight:600;">Centre name *</label>
              <input name="centre_name" required style="${inputStyle()}">
              <div style="display:grid;grid-template-columns:2fr 1fr;gap:10px;">
                <div><label style="font-size:13px;font-weight:600;">City *</label><input name="centre_city" required style="${inputStyle()}"></div>
                <div><label style="font-size:13px;font-weight:600;">Licensed capacity *</label><input name="centre_capacity" type="number" min="1" max="500" value="64" required style="${inputStyle()}"></div>
              </div>
            </section>
            <section style="border:1px solid #E5E7EB;border-radius:10px;padding:16px;">
              <h3 style="font-size:14px;text-transform:uppercase;letter-spacing:1px;color:#6B7280;margin:0 0 10px;">Admin user (will be emailed an invite)</h3>
              <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;">
                <div><label style="font-size:13px;font-weight:600;">First name *</label><input name="admin_first_name" required style="${inputStyle()}"></div>
                <div><label style="font-size:13px;font-weight:600;">Last name *</label><input name="admin_last_name" required style="${inputStyle()}"></div>
              </div>
              <label style="font-size:13px;font-weight:600;">Email *</label>
              <input name="admin_email" type="email" required style="${inputStyle()}">
              <label style="font-size:13px;font-weight:600;">Phone</label>
              <input name="admin_phone" type="tel" style="${inputStyle()}">
            </section>
            <div id="kt-agency-status" style="min-height:20px;font-size:14px;"></div>
            <div style="display:flex;justify-content:flex-end;gap:8px;">
              <button type="button" id="kt-cancel" style="background:#F3F4F6;color:#374151;border:none;padding:12px 22px;border-radius:10px;font-weight:600;cursor:pointer;">Cancel</button>
              <button type="submit" id="kt-create" style="background:#1F6080;color:white;border:none;padding:12px 22px;border-radius:10px;font-weight:700;cursor:pointer;">Provision Agency</button>
            </div>
          </form>
        </div>
      </div>
    `;
    const overlay = $('.kt-modal-overlay', mount);
    const form = $('#kt-agency-form', mount);
    const status = $('#kt-agency-status', mount);
    const cancelBtn = $('#kt-cancel', mount);
    const createBtn = $('#kt-create', mount);

    const close = () => { mount.innerHTML = ''; };
    cancelBtn.addEventListener('click', close);
    overlay.addEventListener('click', (e) => { if (e.target === overlay) close(); });

    form.addEventListener('submit', async (e) => {
      e.preventDefault();
      const data = {};
      new FormData(form).forEach((v, k) => data[k] = v);
      if (data.centre_capacity) data.centre_capacity = parseInt(data.centre_capacity, 10);
      if (data.trial_days) data.trial_days = parseInt(data.trial_days, 10);
      // Strip empty optional fields
      ['subdomain', 'custom_domain', 'admin_phone'].forEach(k => { if (!data[k]) delete data[k]; });

      createBtn.disabled = true;
      createBtn.textContent = 'Provisioning…';
      status.style.color = '#6B7280';
      status.textContent = '';

      try {
        const result = await api('POST', '/admin/agencies', data);
        status.style.color = '#16A34A';
        status.textContent = '✓ Agency created. Invite email sent to ' + data.admin_email;
        setTimeout(() => { close(); render(container); }, 1500);
      } catch (e) {
        status.style.color = '#DC2626';
        status.textContent = '✗ ' + e.message;
        createBtn.disabled = false;
        createBtn.textContent = 'Provision Agency';
      }
    });
  }

  async function openEditModal(container, agencyId) {
    const data = await api('GET', '/admin/agencies');
    const a = (data.agencies || []).find(x => x.id === agencyId);
    if (!a) return alert('Agency not found');

    const mount = $('#kt-agency-modal-mount', container);
    mount.innerHTML = `
      <div class="kt-modal-overlay" style="position:fixed;inset:0;background:rgba(0,0,0,.5);display:flex;align-items:center;justify-content:center;z-index:9999;padding:16px;">
        <div style="background:white;border-radius:16px;max-width:520px;width:100%;padding:24px;">
          <h2 style="font-size:22px;margin:0 0 16px;">Manage: ${esc(a.name)}</h2>
          <form id="kt-edit-form" onsubmit="return false;" style="display:grid;gap:12px;">
            <div><label style="font-size:13px;font-weight:600;">Name</label><input name="name" value="${esc(a.name)}" style="${inputStyle()}"></div>
            <div><label style="font-size:13px;font-weight:600;">Subdomain</label><input name="subdomain" value="${esc(a.subdomain || '')}" style="${inputStyle()}"></div>
            <div><label style="font-size:13px;font-weight:600;">Custom domain</label><input name="custom_domain" value="${esc(a.custom_domain || '')}" style="${inputStyle()}"></div>
            <div><label style="font-size:13px;font-weight:600;">Contact email</label><input name="contact_email" type="email" value="${esc(a.contact_email || '')}" style="${inputStyle()}"></div>
            <div><label style="font-size:13px;font-weight:600;">Plan</label>
              <select name="plan" style="${inputStyle()}">
                <option value="starter" ${a.plan==='starter'?'selected':''}>Starter</option>
                <option value="centre"  ${a.plan==='centre' ?'selected':''}>Centre</option>
                <option value="agency"  ${a.plan==='agency' ?'selected':''}>Agency</option>
              </select>
            </div>
            <div><label style="font-size:13px;font-weight:600;">Billing status</label>
              <select name="billing_status" style="${inputStyle()}">
                ${['trial','active','past_due','suspended'].map(s=>`<option value="${s}" ${a.billing_status===s?'selected':''}>${s}</option>`).join('')}
              </select>
            </div>
            <div id="kt-edit-status" style="min-height:20px;font-size:14px;"></div>
            <div style="display:flex;justify-content:space-between;gap:8px;">
              ${a.id !== 1 ? '<button type="button" id="kt-delete" style="background:#FEE2E2;color:#991B1B;border:none;padding:12px 20px;border-radius:10px;font-weight:600;cursor:pointer;">Delete</button>' : '<div></div>'}
              <div style="display:flex;gap:8px;">
                <button type="button" id="kt-cancel" style="background:#F3F4F6;color:#374151;border:none;padding:12px 22px;border-radius:10px;font-weight:600;cursor:pointer;">Cancel</button>
                <button type="submit" id="kt-save" style="background:#1F6080;color:white;border:none;padding:12px 22px;border-radius:10px;font-weight:700;cursor:pointer;">Save</button>
              </div>
            </div>
          </form>
        </div>
      </div>
    `;
    const overlay = $('.kt-modal-overlay', mount);
    const form = $('#kt-edit-form', mount);
    const close = () => { mount.innerHTML = ''; };
    $('#kt-cancel', mount).addEventListener('click', close);
    overlay.addEventListener('click', (e) => { if (e.target === overlay) close(); });

    const delBtn = $('#kt-delete', mount);
    if (delBtn) {
      delBtn.addEventListener('click', async () => {
        if (!confirm('Permanently soft-delete ' + a.name + '? Their data is preserved but the agency is suspended.')) return;
        try { await api('DELETE', '/admin/agencies/' + a.id); close(); render(container); }
        catch (e) { alert('Delete failed: ' + e.message); }
      });
    }

    form.addEventListener('submit', async (e) => {
      e.preventDefault();
      const data = {};
      new FormData(form).forEach((v, k) => { if (v !== '') data[k] = v; });
      try {
        await api('PATCH', '/admin/agencies/' + a.id, data);
        $('#kt-edit-status', mount).style.color = '#16A34A';
        $('#kt-edit-status', mount).textContent = '✓ Saved';
        setTimeout(() => { close(); render(container); }, 800);
      } catch (e) {
        $('#kt-edit-status', mount).style.color = '#DC2626';
        $('#kt-edit-status', mount).textContent = '✗ ' + e.message;
      }
    });
  }

  function inputStyle() {
    return 'width:100%;padding:10px 12px;border:1px solid #D1D5DB;border-radius:8px;font-size:14px;font-family:inherit;margin-bottom:8px;';
  }

  window.KT = window.KT || {};
  window.KT.Agencies = { render: render };
})(window);
