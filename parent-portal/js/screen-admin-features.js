/* ═══════════════════════════════════════════════════════════════════
   KIDDIETRAC v15 — Feature Flags admin (platform_admin only)
   ─────────────────────────────────────────────────────────────────
   GET  /admin/features/catalog        → features + plans
   GET  /admin/agencies/{id}/features  → current flags for an agency
   PATCH /admin/agencies/{id}/features → write flags / plan / status
   Lets a platform admin pick an agency, set its plan, toggle individual
   feature flags, and change billing status.
   ═══════════════════════════════════════════════════════════════════ */
(function (window) {
  'use strict';

  function token() { return sessionStorage.getItem('kt_token') || localStorage.getItem('kt_token'); }
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
  function money(cents, ccy) {
    const n = (cents || 0) / 100;
    return n.toLocaleString('en-CA', { style: 'currency', currency: ccy || 'CAD', maximumFractionDigits: 0 });
  }

  let catalog = null;   // { features:[...], plans:[...] }
  let agencies = [];

  async function loadCatalog() {
    if (catalog) return catalog;
    catalog = await api('GET', '/admin/features/catalog');
    return catalog;
  }
  async function loadAgencies() {
    try {
      const r = await api('GET', '/admin/mrr/agencies?limit=200');
      agencies = r.agencies || [];
    } catch (e) {
      // Fall back: ask for a single agency by ID
      agencies = [];
    }
    return agencies;
  }

  async function render(container) {
    container.innerHTML = '<div style="padding:32px;text-align:center;color:#6B7280;">Loading feature flags…</div>';

    let cat, ags;
    try {
      cat = await loadCatalog();
      ags = await loadAgencies();
    } catch (e) {
      container.innerHTML = '<div style="padding:24px;color:#DC2626;">' + esc(e.message) + '</div>';
      return;
    }

    // Parse #admin-features/{id} from hash if present
    const hashParts = (window.location.hash.replace('#', '').split('/'));
    /* THE AGENCY YOU ARE STANDING IN, not the first one in the list.
       `ags[0]` is alphabetical, which means a super admin who has switched into Test
       Agency opened this screen looking at iLearn's flags — and the Save button under
       them writes to whatever is selected. Editing a live tenant's entitlements because
       a picker defaulted is not a mistake worth leaving available. An explicit
       #admin-features/<id> still wins. (2026-09-17) */
    let activeId = 0;
    try { activeId = parseInt(sessionStorage.getItem('kt_active_agency_id') || '', 10) || 0; } catch (e) {}
    const activeIsListed = activeId && ags.some(a => Number(a.id) === activeId);
    const preselectId = hashParts[1]
      ? parseInt(hashParts[1], 10)
      : (activeIsListed ? activeId : (ags[0] && ags[0].id));

    container.innerHTML = `
      <div style="padding:24px;max-width:1800px;">
        <h2 style="font-size:24px;margin:0 0 4px;">⚙️ Feature Flags & Plans</h2>
        <p style="color:#6B7280;font-size:13px;margin:0 0 18px;">Per-agency overrides. Plan sets the default tier; flags override individual features. Default behaviour when a flag is unset = allow.</p>

        <div style="background:white;border-radius:14px;padding:18px;box-shadow:0 1px 4px rgba(0,0,0,.05);margin-bottom:18px;">
          <label style="font-size:12px;font-weight:700;color:#6B7280;text-transform:uppercase;letter-spacing:1px;">Agency</label>
          <select id="kt-agency" style="width:100%;padding:10px 12px;border:1px solid #D1D5DB;border-radius:8px;font-size:14px;margin-top:6px;">
            ${ags.length === 0 ? '<option value="">No agencies found</option>' : ags.map(a => `<option value="${a.id}" ${a.id == preselectId ? 'selected' : ''}>${esc(a.name || ('Agency #' + a.id))}</option>`).join('')}
          </select>
        </div>

        <div id="kt-flags-body"></div>
      </div>
    `;

    if (preselectId) await loadAndRenderAgency(container, preselectId);

    $('#kt-agency', container).addEventListener('change', async (e) => {
      const id = parseInt(e.target.value, 10);
      if (id) await loadAndRenderAgency(container, id);
    });
  }

  async function loadAndRenderAgency(container, agencyId) {
    const body = $('#kt-flags-body', container);
    body.innerHTML = '<div style="padding:18px;color:#6B7280;">Loading agency settings…</div>';
    let data;
    try { data = await api('GET', '/admin/agencies/' + agencyId + '/features'); }
    catch (e) { body.innerHTML = '<div style="padding:18px;color:#DC2626;">' + esc(e.message) + '</div>'; return; }

    const flags = data.feature_flags || {};
    // v21.1: API returns plans/features as objects keyed by code, not arrays. Normalize.
    const plansRaw = catalog.plans || {};
    const plans = Array.isArray(plansRaw)
      ? plansRaw
      : Object.keys(plansRaw).map(code => ({ code, name: plansRaw[code].label || code, monthly_cents: plansRaw[code].monthly_cents || 0 }));
    const featuresRaw = catalog.features || {};
    const features = Array.isArray(featuresRaw)
      ? featuresRaw
      : Object.keys(featuresRaw).map(code => ({
        code,
        name: featuresRaw[code].label || code,
        plan_min: featuresRaw[code].plan_min,
        group: featuresRaw[code].group || 'Other',
        hashes: featuresRaw[code].hashes || [],
      }));

    /* GROUPED, AND SAYING WHAT EACH ONE ACTUALLY TURNS OFF.

       Fourteen flags fitted in one grid; the catalog now covers the whole product and a
       flat wall of forty switches is unreadable. The screen list under each one is the
       point as much as the label — "Daily care" means nothing until you can see that
       switching it off takes Lesson plans, Curriculum and HDLH gaps out of the menu.
       Both come straight from the catalog, so a feature added there appears here with
       no change to this file. (2026-09-17) */
    const byGroup = {};
    const groupOrder = [];
    features.forEach(function (f) {
      if (!byGroup[f.group]) { byGroup[f.group] = []; groupOrder.push(f.group); }
      byGroup[f.group].push(f);
    });

    body.innerHTML = `
      <div style="background:white;border-radius:14px;padding:18px;box-shadow:0 1px 4px rgba(0,0,0,.05);margin-bottom:18px;">
        <h3 style="margin:0 0 12px;font-size:15px;">Plan & billing</h3>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px;">
          <div>
            <label style="font-size:12px;font-weight:700;color:#6B7280;">Plan</label>
            <select id="kt-plan" style="width:100%;padding:10px 12px;border:1px solid #D1D5DB;border-radius:8px;font-size:14px;margin-top:6px;">
              ${plans.map(p => `<option value="${esc(p.code)}" ${p.code == data.plan_code ? 'selected' : ''}>${esc(p.name)} — ${money(p.monthly_cents, 'CAD')}/mo</option>`).join('')}
            </select>
          </div>
          <div>
            <label style="font-size:12px;font-weight:700;color:#6B7280;">Billing status</label>
            <select id="kt-status" style="width:100%;padding:10px 12px;border:1px solid #D1D5DB;border-radius:8px;font-size:14px;margin-top:6px;">
              ${['trial','active','past_due','cancelled'].map(s => `<option value="${s}" ${s == data.billing_status ? 'selected' : ''}>${s}</option>`).join('')}
            </select>
          </div>
        </div>
      </div>

      <div style="background:white;border-radius:14px;padding:18px;box-shadow:0 1px 4px rgba(0,0,0,.05);margin-bottom:18px;">
        <h3 style="margin:0 0 4px;font-size:15px;">Feature overrides</h3>
        <p style="margin:0 0 12px;color:#6B7280;font-size:12px;">Auto = follow plan default · On = force enabled · Off = force disabled. Switching a feature off hides its screens from everyone at this agency and refuses its API.</p>
        ${groupOrder.map(g => `
          <div style="margin:0 0 6px;">
            <div style="font-size:11px;font-weight:800;letter-spacing:1px;text-transform:uppercase;color:#1F6080;margin:14px 0 8px;">${esc(g)}</div>
            <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(280px,1fr));gap:10px;">
              ${byGroup[g].map(f => featureRow(f, flags[f.code])).join('')}
            </div>
          </div>`).join('')}
      </div>

      <div style="display:flex;gap:10px;justify-content:flex-end;">
        <button id="kt-cancel" style="padding:10px 18px;background:#E5E7EB;color:#374151;border:none;border-radius:8px;font-weight:600;cursor:pointer;">Cancel</button>
        <button id="kt-save"   style="padding:10px 24px;background:#081C41;color:white;border:none;border-radius:8px;font-weight:700;cursor:pointer;">Save changes</button>
      </div>
      <div id="kt-save-msg" style="margin-top:10px;text-align:right;font-size:13px;"></div>
    `;

    $('#kt-cancel', container).addEventListener('click', () => loadAndRenderAgency(container, agencyId));
    $('#kt-save',   container).addEventListener('click', async () => {
      const newFlags = {};
      features.forEach(f => {
        const v = $('#kt-flag-' + f.code, container).value;
        if (v === 'on')  newFlags[f.code] = true;
        if (v === 'off') newFlags[f.code] = false;
        // 'auto' → omit key
      });
      const payload = {
        plan_code: $('#kt-plan',   container).value,
        billing_status: $('#kt-status', container).value,
        feature_flags: newFlags,
      };
      const msg = $('#kt-save-msg', container);
      msg.textContent = 'Saving…'; msg.style.color = '#6B7280';
      try {
        await api('PATCH', '/admin/agencies/' + agencyId + '/features', payload);
        msg.textContent = '✓ Saved'; msg.style.color = '#16A34A';
        setTimeout(() => loadAndRenderAgency(container, agencyId), 600);
      } catch (e) {
        msg.textContent = '✗ ' + e.message; msg.style.color = '#DC2626';
      }
    });
  }

  function featureRow(feature, currentFlag) {
    let initial = 'auto';
    if (currentFlag === true)  initial = 'on';
    if (currentFlag === false) initial = 'off';
    return `<div style="border:1px solid #E5E7EB;border-radius:10px;padding:12px;">
      <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:8px;">
        <div style="flex:1;min-width:0;">
          <div style="font-weight:700;font-size:13px;color:#111827;">${esc(feature.name || feature.code)}</div>
          <div style="font-size:11px;color:#64748B;margin-top:2px;">code: ${esc(feature.code)}${feature.plan_min ? ' · plan ≥ ' + esc(feature.plan_min) : ''}</div>
          ${(feature.hashes && feature.hashes.length)
            ? `<div style="font-size:11px;color:#94A3B8;margin-top:4px;line-height:1.5;">Hides: ${feature.hashes.map(esc).join(', ')}</div>`
            : '<div style="font-size:11px;color:#94A3B8;margin-top:4px;">No menu item — gates the API only.</div>'}
        </div>
      </div>
      <select id="kt-flag-${esc(feature.code)}" style="width:100%;margin-top:8px;padding:6px 8px;border:1px solid #D1D5DB;border-radius:6px;font-size:13px;background:white;">
        <option value="auto" ${initial=='auto'?'selected':''}>Auto (plan default)</option>
        <option value="on"   ${initial=='on'?'selected':''}>✓ Force on</option>
        <option value="off"  ${initial=='off'?'selected':''}>✗ Force off</option>
      </select>
    </div>`;
  }

  window.KT = window.KT || {};
  window.KT.AdminFeatures = { render: render };
})(window);
