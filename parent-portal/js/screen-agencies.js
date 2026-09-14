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


  // The master switch lives in the agency's settings JSON. Absent means ON — an
  // agency that has never touched it keeps working normally.
  /* Defaults OFF, unlike notifications: this one CREATES shifts, and a setting that
     rosters nine centres because nobody said otherwise is not a safe default. */
  function autofillOn(a) {
    try {
      var st = a.settings;
      if (typeof st === 'string') st = JSON.parse(st || '{}');
      return !!(st && st.schedule_autofill);
    } catch (e) { return false; }
  }

  function notificationsOn(a) {
    try {
      var st = a.settings;
      if (typeof st === 'string') st = JSON.parse(st || '{}');
      if (!st || typeof st !== 'object') return true;
      return st.notifications_enabled !== false;
    } catch (e) { return true; }
  }

  function esc(s) {
    return s == null ? '' : String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
  }

  // The agency's timezone drives EVERY time in the platform — sign-in/out, care
  // logs, reports, and when the end-of-day parent summary is bucketed and sent —
  // so it is a required step at onboarding, not a hidden default.
  var TIMEZONES = [
    ['America/St_Johns', "Newfoundland (St. John's)"],
    ['America/Halifax', 'Atlantic (Halifax)'],
    ['America/Toronto', 'Eastern (Toronto, Ottawa, Montreal)'],
    ['America/Winnipeg', 'Central (Winnipeg)'],
    ['America/Regina', 'Central, no DST (Regina)'],
    ['America/Edmonton', 'Mountain (Edmonton, Calgary)'],
    ['America/Vancouver', 'Pacific (Vancouver)'],
    ['America/New_York', 'US Eastern (New York)'],
    ['America/Chicago', 'US Central (Chicago)'],
    ['America/Denver', 'US Mountain (Denver)'],
    ['America/Phoenix', 'US Arizona, no DST (Phoenix)'],
    ['America/Los_Angeles', 'US Pacific (Los Angeles)'],
    ['Europe/London', 'UK (London)'],
    ['Europe/Dublin', 'Ireland (Dublin)'],
    ['Australia/Sydney', 'Australia (Sydney)'],
    ['Pacific/Auckland', 'New Zealand (Auckland)'],
    ['UTC', 'UTC'],
  ];
  function tzOptions(selected) {
    return TIMEZONES.map(function (t) {
      return '<option value="' + t[0] + '"' + (t[0] === selected ? ' selected' : '') + '>' + esc(t[1]) + '</option>';
    }).join('');
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
                  <div style="font-size:11px;color:#64748B;">acme.kiddietrac.com</div>
                </div>
                <div>
                  <label style="font-size:13px;font-weight:600;">Custom domain</label>
                  <input name="custom_domain" placeholder="childcare.acme.com" style="${inputStyle()}">
                  <div style="font-size:11px;color:#64748B;">requires DNS setup</div>
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
              <label style="font-size:13px;font-weight:600;">Timezone *</label>
              <select name="timezone" required style="${inputStyle()}">${tzOptions('America/Toronto')}</select>
              <div style="font-size:12px;color:#6B7280;margin-top:4px;">
                Every time in the platform — sign-in/out, logs, reports and the daily summary emails — is shown
                and bucketed in this timezone. Get this right at setup; changing it later re-dates nothing already recorded.
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

  /* One at a time. The ⚙️ button is small and on a phone it gets pressed repeatedly
     while the first request is still in flight — the crash report behind this had five
     presses inside four seconds. Each one used to start its own fetch and then race to
     write the same mount. */
  let editOpening = false;

  async function openEditModal(container, agencyId) {
    if (editOpening) { return; }
    editOpening = true;

    let data;
    try {
      data = await api('GET', '/admin/agencies');
    } finally {
      editOpening = false;
    }

    /* THE USER MAY HAVE LEFT WHILE THAT WAS IN FLIGHT.
       This awaits a network call and only then looks the mount up inside `container`.
       Navigate away in the meantime — six seconds passed in the report that produced
       this fix — and the shell has replaced #appMain, so `container` is a detached node,
       the lookup returns null, and `.innerHTML` throws as an unhandled rejection.

       isConnected is the check that matters rather than a null test alone: a detached
       container can still CONTAIN the mount, so the lookup succeeds and the modal is
       then written into a node that is no longer on the page — a dialog nobody can see
       and nobody can dismiss. See the same trap in kiddietrac-sweep-detached-container. */
    const mount = $('#kt-agency-modal-mount', container);
    if (!mount || !mount.isConnected) { return; }

    const a = (data.agencies || []).find(x => x.id === agencyId);
    if (!a) return alert('Agency not found');

    mount.innerHTML = `
      <div class="kt-modal-overlay" style="position:fixed;inset:0;background:rgba(0,0,0,.5);display:flex;align-items:center;justify-content:center;z-index:9999;padding:16px;">
        <div style="background:white;border-radius:16px;max-width:640px;width:100%;padding:24px;max-height:88vh;overflow:auto;">
          <h2 style="font-size:22px;margin:0 0 16px;">Manage: ${esc(a.name)}</h2>
          <form id="kt-edit-form" onsubmit="return false;" style="display:grid;gap:12px;">

            <div id="kt-ag-tabs" style="display:flex;gap:4px;border-bottom:1px solid #E5E7EB;margin:-4px 0 4px;">
              ${[['details','Details'],['address','Address'],['email','Email'],['plan','Plan & automation']]
                .map(([k,l],i) => `<button type="button" data-agtab="${k}" style="border:none;background:none;padding:9px 13px;font-size:13px;font-weight:700;cursor:pointer;border-bottom:2px solid ${i===0?'#1F6080':'transparent'};color:${i===0?'#1F6080':'#64748B'};">${l}</button>`).join('')}
            </div>

            <div data-agpane="details">
              <div><label style="font-size:13px;font-weight:600;">Name</label><input name="name" value="${esc(a.name)}" style="${inputStyle()}"></div>
              <div><label style="font-size:13px;font-weight:600;">Legal name <span style="font-weight:400;color:#94A3B8;">(if different from the trading name)</span></label><input name="legal_name" value="${esc(a.legal_name || '')}" style="${inputStyle()}"></div>
              <div><label style="font-size:13px;font-weight:600;">Subdomain</label><input name="subdomain" value="${esc(a.subdomain || '')}" style="${inputStyle()}"></div>
              <div><label style="font-size:13px;font-weight:600;">Custom domain</label><input name="custom_domain" value="${esc(a.custom_domain || '')}" style="${inputStyle()}"></div>
              <div><label style="font-size:13px;font-weight:600;">Website</label><input name="website" value="${esc(a.website || '')}" placeholder="https://" style="${inputStyle()}"></div>
              <div><label style="font-size:13px;font-weight:600;">Timezone</label><select name="timezone" style="${inputStyle()}">${tzOptions(a.timezone || 'America/Toronto')}</select>
                <div style="font-size:12px;color:#6B7280;margin:-4px 0 8px;">Every time this agency records or displays \u2014 sign-ins, rotas, reports \u2014 is in this zone.</div>
              </div>
            </div>

            <div data-agpane="address" hidden>
              <div><label style="font-size:13px;font-weight:600;">Address line 1</label><input name="address_line1" value="${esc(a.address_line1 || '')}" style="${inputStyle()}"></div>
              <div><label style="font-size:13px;font-weight:600;">Address line 2</label><input name="address_line2" value="${esc(a.address_line2 || '')}" placeholder="Unit, suite, floor" style="${inputStyle()}"></div>
              <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;">
                <div><label style="font-size:13px;font-weight:600;">City</label><input name="city" value="${esc(a.city || '')}" style="${inputStyle()}"></div>
                <div><label style="font-size:13px;font-weight:600;">Province / State</label><input name="province" value="${esc(a.province || '')}" style="${inputStyle()}"></div>
              </div>
              <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;">
                <div><label style="font-size:13px;font-weight:600;">Postal / ZIP code</label><input name="postal_code" value="${esc(a.postal_code || '')}" style="${inputStyle()}"></div>
                <div><label style="font-size:13px;font-weight:600;">Country</label><input name="country" value="${esc(a.country || '')}" style="${inputStyle()}"></div>
              </div>
              <div style="font-size:12px;color:#6B7280;">Printed on invoices and receipts, and used for the statutory holiday calendar.</div>
            </div>

            <div data-agpane="email" hidden>
              <div><label style="font-size:13px;font-weight:600;">Contact email</label><input name="contact_email" type="email" value="${esc(a.contact_email || '')}" style="${inputStyle()}">
                <div style="font-size:12px;color:#6B7280;margin:-4px 0 8px;">Where we write to the agency itself \u2014 not what families see.</div>
              </div>
              <div><label style="font-size:13px;font-weight:600;">Contact phone</label><input name="contact_phone" value="${esc(a.contact_phone || '')}" style="${inputStyle()}"></div>
              <div style="border-top:1px solid #F1F5F9;margin:6px 0 10px;"></div>
              <div style="font-size:12.5px;font-weight:700;color:#0F172A;margin-bottom:6px;">How their mail appears to families</div>
              <div><label style="font-size:13px;font-weight:600;">From name</label><input name="email_from_name" value="${esc(a.email_from_name || '')}" placeholder="${esc(a.name)}" style="${inputStyle()}"></div>
              <div><label style="font-size:13px;font-weight:600;">From address</label><input name="email_from_address" type="email" value="${esc(a.email_from_address || '')}" placeholder="noreply@youragency.com" style="${inputStyle()}"></div>
              <div style="font-size:12px;color:#6B7280;line-height:1.5;">
                Mail servers, suppression, closure notices and the template library live on the full
                <a href="#email-settings" style="color:#1F6080;font-weight:600;">Email settings</a> screen \u2014 kept there rather than copied here, so there is one place that decides how mail goes out.
              </div>
            </div>

            <div data-agpane="plan" hidden>
              <div style="border:1px solid #E5E7EB;border-radius:10px;padding:12px 14px;background:#F9FAFB;margin-bottom:10px;">
                <label style="display:flex;gap:10px;align-items:flex-start;cursor:pointer;">
                  <input type="checkbox" name="notifications_enabled" ${notificationsOn(a) ? 'checked' : ''}
                    style="width:19px;height:19px;margin-top:2px;flex:0 0 auto;accent-color:#159FB4;">
                  <span>
                    <span style="display:block;font-size:13.5px;font-weight:700;color:#111827;">Send notifications and emails</span>
                    <span style="display:block;font-size:12px;color:#6B7280;line-height:1.5;margin-top:2px;">
                      The master switch for this agency. Turn it OFF and <strong>nothing</strong> goes out to their
                      staff or families \u2014 no email, no text, no push, no in-app alert. Everything is still recorded;
                      it just isn't sent.
                    </span>
                  </span>
                </label>
              </div>
              <div style="border:1px solid #E5E7EB;border-radius:10px;padding:12px 14px;background:#F9FAFB;margin-bottom:10px;">
                <label style="display:flex;gap:10px;align-items:flex-start;cursor:pointer;">
                  <input type="checkbox" name="schedule_autofill" ${autofillOn(a) ? 'checked' : ''}
                    style="width:19px;height:19px;margin-top:2px;flex:0 0 auto;accent-color:#159FB4;">
                  <span>
                    <span style="display:block;font-size:13.5px;font-weight:700;color:#111827;">Fill staff schedules automatically</span>
                    <span style="display:block;font-size:12px;color:#6B7280;line-height:1.5;margin-top:2px;">
                      Each night at 04:30 this rosters <strong>every centre</strong> in the agency 28 days ahead, from each
                      centre's own opening hours and open days. Closure days are skipped, a day already rostered is never
                      overwritten, and <strong>a shift somebody deletes stays deleted</strong>.
                    </span>
                  </span>
                </label>
              </div>
              <div><label style="font-size:13px;font-weight:600;">Plan</label>
                <select name="plan" style="${inputStyle()}">
                  <option value="starter" ${a.plan==='starter'?'selected':''}>Starter</option>
                  <option value="centre"  ${a.plan==='centre' ?'selected':''}>Centre</option>
                  <option value="agency"  ${a.plan==='agency' ?'selected':''}>Agency</option>
                </select>
              </div>
              <div><label style="font-size:13px;font-weight:600;">Billing status</label>
                <select name="billing_status" style="${inputStyle()}">
                  ${['trial','active','past_due','suspended'].map(st=>`<option value="${st}" ${a.billing_status===st?'selected':''}>${st}</option>`).join('')}
                </select>
              </div>
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

    /* Every pane is in the DOM and simply hidden. Building a tab's fields only when it is
       opened would mean one Save posted whatever the user happened to look at, and quietly
       dropped the rest. */
    $$('[data-agtab]', mount).forEach((tab) => {
      tab.addEventListener('click', () => {
        const key = tab.getAttribute('data-agtab');
        $$('[data-agtab]', mount).forEach((t) => {
          const on = t === tab;
          t.style.borderBottomColor = on ? '#1F6080' : 'transparent';
          t.style.color = on ? '#1F6080' : '#64748B';
        });
        $$('[data-agpane]', mount).forEach((p) => {
          p.hidden = p.getAttribute('data-agpane') !== key;
        });
      });
    });
    $('#kt-cancel', mount).addEventListener('click', close);
    overlay.addEventListener('click', (e) => { if (e.target === overlay) close(); });

    const delBtn = $('#kt-delete', mount);
    if (delBtn) {
      delBtn.addEventListener('click', async () => {
        if (!await KT.confirm('Permanently soft-delete ' + a.name + '? Their data is preserved but the agency is suspended.')) return;
        try { await api('DELETE', '/admin/agencies/' + a.id); close(); render(container); }
        catch (e) { alert('Delete failed: ' + e.message); }
      });
    }

    form.addEventListener('submit', async (e) => {
      e.preventDefault();
      const data = {};
      new FormData(form).forEach((v, k) => { if (v !== '') data[k] = v; });

      // An UNCHECKED checkbox is absent from FormData entirely — so switching
      // notifications OFF would never have been sent, and the switch would look
      // like it silently refused to turn off. Read it from the element instead.
      const notifBox = form.querySelector('[name="notifications_enabled"]');
      if (notifBox) data.notifications_enabled = notifBox.checked;
      // Same reason — an unchecked box never reaches FormData, so it could be switched
      // on and never off.
      const autoBox = form.querySelector('[name="schedule_autofill"]');
      if (autoBox) data.schedule_autofill = autoBox.checked;


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
