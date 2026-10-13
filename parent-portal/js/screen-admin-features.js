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

      <div class="kt-ff-panel">
        <div class="kt-ff-head">
          <div>
            <h3>Feature overrides</h3>
            <p><b>Auto</b> follows the plan · <b class="on">On</b> forces it on · <b class="off">Off</b> hides its screens from everyone at this agency and refuses its API.</p>
          </div>
          <div class="kt-ff-sum" id="kt-ff-sum"></div>
        </div>
        ${groupOrder.map((g, gi) => `
          <section class="kt-ff-group" style="--c:${GROUP_COLOURS[gi % GROUP_COLOURS.length][0]};--t:${GROUP_COLOURS[gi % GROUP_COLOURS.length][1]};">
            <div class="kt-ff-gh"><span class="kt-ff-gname">${esc(g)}</span><span class="kt-ff-gcount">${byGroup[g].length}</span></div>
            <div class="kt-ff-grid">
              ${byGroup[g].map(f => featureRow(f, flags[f.code])).join('')}
            </div>
          </section>`).join('')}
      </div>

      <div style="display:flex;gap:10px;justify-content:flex-end;">
        <button id="kt-cancel" style="padding:10px 18px;background:#E5E7EB;color:#374151;border:none;border-radius:8px;font-weight:600;cursor:pointer;">Cancel</button>
        <button id="kt-save"   style="padding:10px 24px;background:#081C41;color:white;border:none;border-radius:8px;font-weight:700;cursor:pointer;">Save changes</button>
      </div>
      <div id="kt-save-msg" style="margin-top:10px;text-align:right;font-size:13px;"></div>
    `;

    // Auto / On / Off switch: drives the (hidden) select the save reads, and recolours the card.
    function refreshSummary() {
      var n = { auto: 0, on: 0, off: 0 };
      container.querySelectorAll('.kt-ff-card select').forEach(function (sel) { n[sel.value] = (n[sel.value] || 0) + 1; });
      var el = $('#kt-ff-sum', container);
      if (el) el.innerHTML = '<span class="s-on">' + n.on + ' forced on</span><span class="s-off">' + n.off + ' forced off</span><span class="s-auto">' + n.auto + ' auto</span>';
    }
    container.querySelectorAll('.kt-ff-seg button').forEach(function (b) {
      b.addEventListener('click', function () {
        var card = b.closest('.kt-ff-card'), sel = card.querySelector('select');
        sel.value = b.getAttribute('data-v');
        card.setAttribute('data-state', sel.value);
        card.querySelectorAll('.kt-ff-seg button').forEach(function (x) { x.setAttribute('aria-pressed', x === b ? 'true' : 'false'); });
        refreshSummary();
      });
    });
    refreshSummary();

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

  /* COLOUR, SO IT CAN BE READ (2026-10-13, Anthony: "the boxes for each feature should be
     colourized as this page is all white right now and hard to read"). Each group has its
     own colour — header, card tint and accent edge — and a card's STATE shows at a glance:
     forced off turns rose, forced on turns green, auto keeps the group's colour. The
     Auto/On/Off switch drives the same select the save has always read. */
  var GROUP_COLOURS = [
    ['#0E7C90', '#ECF8FA'], ['#7C3AED', '#F4F0FE'], ['#D97706', '#FEF7EC'], ['#2563EB', '#EEF4FE'],
    ['#DB2777', '#FDF0F6'], ['#059669', '#ECFAF4'], ['#4F46E5', '#F0F0FE'], ['#EA580C', '#FEF2EB'],
    ['#0891B2', '#EBF8FB'], ['#9333EA', '#F7EFFE'], ['#65A30D', '#F3F9EA'], ['#E11D48', '#FEF0F2'],
  ];
  function ffCss() {
    if (document.getElementById('kt-ff-css')) return;
    var st = document.createElement('style');
    st.id = 'kt-ff-css';
    st.textContent = [
      '.kt-ff-panel{background:#fff;border-radius:16px;padding:18px;box-shadow:0 1px 4px rgba(0,0,0,.05);margin-bottom:18px;}',
      '.kt-ff-head{display:flex;justify-content:space-between;align-items:flex-start;gap:14px;flex-wrap:wrap;margin-bottom:4px;}',
      '.kt-ff-head h3{margin:0 0 4px;font-size:15px;color:#0F172A;}',
      '.kt-ff-head p{margin:0;color:#64748B;font-size:12px;max-width:640px;}',
      '.kt-ff-head b{color:#334155;}.kt-ff-head b.on{color:#15803D;}.kt-ff-head b.off{color:#BE123C;}',
      '.kt-ff-sum{display:flex;gap:6px;flex-wrap:wrap;}',
      '.kt-ff-sum span{font-size:11.5px;font-weight:800;border-radius:999px;padding:4px 10px;}',
      '.kt-ff-sum .s-on{background:#DCFCE7;color:#166534;}.kt-ff-sum .s-off{background:#FFE4E6;color:#9F1239;}.kt-ff-sum .s-auto{background:#F1F5F9;color:#475569;}',
      '.kt-ff-group{margin-top:16px;}',
      '.kt-ff-gh{display:flex;align-items:center;gap:8px;margin:0 0 8px;}',
      '.kt-ff-gname{display:inline-flex;align-items:center;background:var(--c);color:#fff;font-size:11px;font-weight:800;letter-spacing:.08em;text-transform:uppercase;border-radius:8px;padding:4px 10px;}',
      '.kt-ff-gcount{font-size:11.5px;font-weight:800;color:var(--c);background:var(--t);border-radius:999px;padding:2px 8px;}',
      '.kt-ff-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(260px,1fr));gap:10px;}',
      '.kt-ff-card{position:relative;background:var(--t);border:1px solid color-mix(in srgb,var(--c) 22%,#fff);border-left:4px solid var(--c);border-radius:12px;padding:11px 12px 10px;transition:background .15s,border-color .15s;}',
      '.kt-ff-card[data-state="on"]{background:#F0FDF4;border-color:#BBF7D0;border-left-color:#16A34A;}',
      '.kt-ff-card[data-state="off"]{background:#FFF1F2;border-color:#FECDD3;border-left-color:#E11D48;}',
      '.kt-ff-name{font-weight:800;font-size:13.5px;color:#0F172A;}',
      '.kt-ff-card[data-state="off"] .kt-ff-name{color:#9F1239;}',
      '.kt-ff-meta{font-size:11px;color:#64748B;margin-top:2px;}',
      '.kt-ff-meta code{font:600 10.5px ui-monospace,Consolas,monospace;background:rgba(255,255,255,.75);border:1px solid rgba(15,23,42,.08);border-radius:5px;padding:0 5px;color:#334155;}',
      '.kt-ff-plan{display:inline-block;margin-left:4px;font-size:10.5px;font-weight:800;color:var(--c);}',
      '.kt-ff-hides{display:flex;flex-wrap:wrap;gap:4px;margin-top:7px;}',
      '.kt-ff-hides span{font-size:10.5px;font-weight:600;color:#475569;background:rgba(255,255,255,.8);border:1px solid rgba(15,23,42,.08);border-radius:6px;padding:1px 6px;}',
      '.kt-ff-hides em{font-size:11px;color:#94A3B8;font-style:normal;}',
      '.kt-ff-seg{display:flex;margin-top:9px;background:rgba(255,255,255,.85);border:1px solid rgba(15,23,42,.1);border-radius:9px;padding:2px;gap:2px;}',
      '.kt-ff-seg button{flex:1;height:28px !important;min-height:0 !important;padding:0 6px !important;border:0;border-radius:7px;background:transparent;color:#475569;font-family:inherit;font-size:12px !important;font-weight:700;line-height:1 !important;cursor:pointer;}',
      '.kt-ff-seg button:hover{background:rgba(15,23,42,.05);}',
      '.kt-ff-seg button[data-v="auto"][aria-pressed="true"]{background:var(--c);color:#fff;}',
      '.kt-ff-seg button[data-v="on"][aria-pressed="true"]{background:#16A34A;color:#fff;}',
      '.kt-ff-seg button[data-v="off"][aria-pressed="true"]{background:#E11D48;color:#fff;}',
      '.kt-ff-card select{display:none;}',
    ].join('\n');
    document.head.appendChild(st);
  }

  function featureRow(feature, currentFlag) {
    ffCss();
    let initial = 'auto';
    if (currentFlag === true)  initial = 'on';
    if (currentFlag === false) initial = 'off';
    const seg = (v, label) => `<button type="button" data-v="${v}" aria-pressed="${initial === v ? 'true' : 'false'}">${label}</button>`;
    return `<div class="kt-ff-card" data-state="${initial}">
      <div class="kt-ff-name">${esc(feature.name || feature.code)}</div>
      <div class="kt-ff-meta"><code>${esc(feature.code)}</code>${feature.plan_min ? '<span class="kt-ff-plan">plan ≥ ' + esc(feature.plan_min) + '</span>' : ''}</div>
      <div class="kt-ff-hides">${(feature.hashes && feature.hashes.length)
        ? feature.hashes.map(h => '<span>' + esc(h) + '</span>').join('')
        : '<em>No menu item — gates the API only.</em>'}</div>
      <div class="kt-ff-seg" role="group" aria-label="${esc(feature.name || feature.code)}">${seg('auto', 'Auto')}${seg('on', '✓ On')}${seg('off', '✗ Off')}</div>
      <select id="kt-flag-${esc(feature.code)}" aria-hidden="true" tabindex="-1">
        <option value="auto" ${initial=='auto'?'selected':''}>Auto (plan default)</option>
        <option value="on"   ${initial=='on'?'selected':''}>✓ Force on</option>
        <option value="off"  ${initial=='off'?'selected':''}>✗ Force off</option>
      </select>
    </div>`;
  }

  window.KT = window.KT || {};
  window.KT.AdminFeatures = { render: render };
})(window);
