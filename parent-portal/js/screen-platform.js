/* ============================================================
   KIDDIETRAC v22p22 — Platform-admin screens
   Hash: #platform-overview, #platform-agencies
   Registered for agency_admin (which platform_admin shares as
   primary_role via existing assignments) — server gates via
   role:platform_admin middleware on the actual API.
   ============================================================ */
(function (window) {
  'use strict';
  if (!window.KT) return;
  var KT = window.KT;
  var Api = KT.Api;
  var Dom = KT.Dom;
  var Shell = KT.Shell;

  function esc(s) {
    return s == null ? '' : String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;')
      .replace(/>/g, '&gt;').replace(/"/g, '&quot;');
  }
  function fmtMoney(cents) {
    return '$' + (cents / 100).toLocaleString('en-CA', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
  }
  function fmtDate(d) {
    if (!d) return '—';
    try { return new Date(d).toLocaleDateString('en-CA', { year: 'numeric', month: 'short', day: 'numeric' }); }
    catch (e) { return d; }
  }
  function statusPill(s) {
    var map = {
      active:    { bg: '#16A34A', fg: 'white',   t: 'ACTIVE' },
      trial:     { bg: '#FEF3C7', fg: '#92400E', t: 'TRIAL' },
      past_due:  { bg: '#FED7AA', fg: '#9A3412', t: 'PAST DUE' },
      suspended: { bg: '#FEE2E2', fg: '#991B1B', t: 'SUSPENDED' },
    };
    var m = map[s] || { bg: '#F3F4F6', fg: '#6B7280', t: (s || '').toUpperCase() };
    return '<span style="padding:3px 10px;border-radius:999px;background:' + m.bg + ';color:' + m.fg + ';font-size:10px;font-weight:700;letter-spacing:0.5px;">' + m.t + '</span>';
  }

  // ── Platform Overview ───────────────────────────────────────────
  function renderOverview(container) {
    Dom.clear(container);
    var wrap = Dom.el('div', { style: 'padding:24px;max-width:1800px;margin:0 auto;' });
    container.appendChild(wrap);

    var hero = Dom.el('div', { class: 'kt-hero', style: 'background:linear-gradient(135deg,#7C3AED 0%,#2C8AAC 60%,#16637A 100%);' });
    hero.innerHTML =
      '<div class="kt-hero-greet">🌐 PLATFORM ADMIN</div>' +
      '<h1>Cross-agency overview</h1>' +
      '<div class="kt-hero-sub">Every centre, every family, every dollar — across the entire platform.</div>';
    wrap.appendChild(hero);

    var loading = Dom.el('div', { style: 'padding:40px;text-align:center;color:#6B7280;' }, 'Loading…');
    wrap.appendChild(loading);

    Api.get('/platform/overview').then(function (data) {
      Dom.clear(wrap);
      wrap.appendChild(hero);

      var t = data.totals || {};
      var r = data.recent_30d || {};

      // KPI grid
      var kpis = [
        { label: 'Agencies', value: t.agencies, hint: 'active', accent: '#7C3AED' },
        { label: 'Centres',  value: t.centres,  hint: 'across all', accent: '#1F6080' },
        { label: 'Children', value: t.children, hint: 'enrolled', accent: '#8EC73C' },
        { label: 'Families', value: t.families, hint: 'on platform', accent: '#FF8A65' },
        { label: 'Staff',    value: t.staff,    hint: 'active users', accent: '#2C8AAC' },
        { label: 'MRR',      value: fmtMoney(t.mrr_cents || 0), hint: 'monthly recurring', accent: '#16A34A' },
      ];
      var grid = Dom.el('div', { style: 'display:grid;grid-template-columns:repeat(auto-fill,minmax(220px,1fr));gap:14px;margin:18px 0;' });
      kpis.forEach(function (k) {
        var c = Dom.el('div', { class: 'kt-lift', style: 'background:white;border-radius:14px;padding:18px;box-shadow:0 1px 3px rgba(0,0,0,.06);border-left:4px solid ' + k.accent + ';' });
        c.innerHTML =
          '<div style="font-size:11px;font-weight:700;color:#6B7280;letter-spacing:1px;text-transform:uppercase;">' + k.label + '</div>' +
          '<div style="font-size:30px;font-weight:800;color:#111827;margin:4px 0 2px;">' + esc(String(k.value)) + '</div>' +
          '<div style="font-size:12px;color:#6B7280;">' + k.hint + '</div>';
        grid.appendChild(c);
      });
      wrap.appendChild(grid);

      // Recent 30d card
      var recent = Dom.el('div', { style: 'background:white;border-radius:14px;padding:20px 24px;box-shadow:0 1px 3px rgba(0,0,0,.06);' });
      recent.innerHTML =
        '<h3 style="margin:0 0 12px;font-size:14px;color:#6B7280;letter-spacing:0.5px;text-transform:uppercase;">Recent 30 days</h3>' +
        '<div style="display:grid;grid-template-columns:repeat(3,1fr);gap:24px;">' +
          '<div><div style="font-size:13px;color:#6B7280;">New agencies</div><div style="font-size:24px;font-weight:800;color:#16A34A;">+' + (r.new_agencies || 0) + '</div></div>' +
          '<div><div style="font-size:13px;color:#6B7280;">Cancellations</div><div style="font-size:24px;font-weight:800;color:#DC2626;">−' + (r.cancelled_agencies || 0) + '</div></div>' +
          '<div><div style="font-size:13px;color:#6B7280;">Net change</div><div style="font-size:24px;font-weight:800;color:#1F6080;">' + (r.net >= 0 ? '+' : '') + (r.net || 0) + '</div></div>' +
        '</div>';
      wrap.appendChild(recent);
    }).catch(function (e) {
      Dom.clear(wrap);
      wrap.appendChild(hero);
      wrap.appendChild(Dom.el('div', { style: 'padding:24px;color:#DC2626;' }, 'Could not load: ' + e.message));
    });
  }

  // ── Platform Agencies ──────────────────────────────────────────
  function renderAgencies(container) {
    Dom.clear(container);
    var wrap = Dom.el('div', { style: 'padding:24px;max-width:1800px;margin:0 auto;' });
    container.appendChild(wrap);

    var hero = Dom.el('div', { class: 'kt-hero', style: 'background:linear-gradient(135deg,#7C3AED 0%,#2C8AAC 60%,#16637A 100%);' });
    hero.innerHTML =
      '<div class="kt-hero-greet">🌐 PLATFORM ADMIN</div>' +
      '<h1>All agencies</h1>' +
      '<div class="kt-hero-sub">Every customer agency on Kiddietrac. Click to set as the active context, or use the platform actions to suspend / resume.</div>';
    wrap.appendChild(hero);

    var bar = Dom.el('div', { style: 'display:flex;justify-content:space-between;align-items:center;margin:16px 0;' });
    bar.appendChild(Dom.el('div', { style: 'color:#6B7280;font-size:13px;' }, 'All tenants'));
    var addBtn = Dom.el('button', { style: 'background:#7C3AED;color:white;border:none;padding:10px 16px;border-radius:8px;font-weight:700;cursor:pointer;font-size:14px;' }, '+ Create agency');
    addBtn.addEventListener('click', function () { showCreateAgencyModal(container); });
    bar.appendChild(addBtn);
    wrap.appendChild(bar);

    var loading = Dom.el('div', { style: 'padding:40px;text-align:center;color:#6B7280;' }, 'Loading…');
    wrap.appendChild(loading);

    Api.get('/platform/agencies').then(function (data) {
      loading.remove();
      var table = Dom.el('table', { style: 'width:100%;background:white;border-radius:12px;overflow:hidden;border-collapse:collapse;box-shadow:0 1px 3px rgba(0,0,0,.04);' });
      table.innerHTML =
        '<thead style="background:#F9FAFB;"><tr>' +
          ['Name', 'Status', 'Plan', 'Centres', 'Families', 'Children', 'Created', 'Actions'].map(function (h) {
            return '<th style="text-align:left;padding:12px 16px;font-size:11px;font-weight:700;color:#6B7280;text-transform:uppercase;letter-spacing:0.5px;">' + h + '</th>';
          }).join('') +
        '</tr></thead>';
      var tbody = Dom.el('tbody');
      (data.agencies || []).forEach(function (a) {
        var tr = Dom.el('tr', { style: 'border-top:1px solid #E5E7EB;' });
        var planLabel = a.plan_code ? (esc(a.plan_code) + ' (' + fmtMoney(a.plan_amount_cents || 0) + ')') : '—';
        tr.innerHTML =
          '<td style="padding:14px 16px;font-weight:600;">' + esc(a.name) + '</td>' +
          '<td style="padding:14px 16px;">' + statusPill(a.billing_status) + '</td>' +
          '<td style="padding:14px 16px;font-size:13px;color:#6B7280;">' + planLabel + '</td>' +
          '<td style="padding:14px 16px;">' + a.centre_count + '</td>' +
          '<td style="padding:14px 16px;">' + a.family_count + '</td>' +
          '<td style="padding:14px 16px;">' + a.child_count + '</td>' +
          '<td style="padding:14px 16px;font-size:13px;color:#6B7280;">' + fmtDate(a.created_at) + '</td>';
        var actionsTd = Dom.el('td', { style: 'padding:14px 16px;text-align:right;white-space:nowrap;' });
        var switchBtn = Dom.el('button', {
          style: 'background:transparent;color:#1F6080;border:1px solid #1F6080;padding:5px 10px;border-radius:6px;font-size:12px;font-weight:600;cursor:pointer;margin-right:4px;',
        }, 'Open');
        switchBtn.addEventListener('click', async function () {
          await Api.post('/auth/active-agency', { agency_id: a.id });
          sessionStorage.setItem('kt_active_agency_id', String(a.id));
          sessionStorage.setItem('kt_active_agency_name', a.name);
          window.location.hash = '#dashboard';
          window.location.reload();
        });
        actionsTd.appendChild(switchBtn);
        // v22p24: edit branding / plan / white-label
        var editBtn = Dom.el('button', {
          style: 'background:transparent;color:#7C3AED;border:1px solid #7C3AED;padding:5px 10px;border-radius:6px;font-size:12px;font-weight:600;cursor:pointer;margin-right:4px;',
        }, 'Edit');
        editBtn.addEventListener('click', function () {
          showAgencyModal(a, container);
        });
        actionsTd.appendChild(editBtn);
        var toggleBtn = Dom.el('button', {
          style: 'background:transparent;border:1px solid ' + (a.billing_status === 'suspended' ? '#16A34A' : '#FCA5A5') + ';color:' + (a.billing_status === 'suspended' ? '#16A34A' : '#DC2626') + ';padding:5px 10px;border-radius:6px;font-size:12px;font-weight:600;cursor:pointer;',
        }, a.billing_status === 'suspended' ? 'Resume' : 'Suspend');
        toggleBtn.addEventListener('click', async function () {
          var op = a.billing_status === 'suspended' ? 'resume' : 'suspend';
          if (op === 'suspend' && !window.confirm('Suspend ' + a.name + '? Users will lose dashboard access immediately.')) return;
          await Api.post('/platform/agencies/' + a.id + '/' + op, {});
          renderAgencies(container);
        });
        actionsTd.appendChild(toggleBtn);
        tr.appendChild(actionsTd);
        tbody.appendChild(tr);
      });
      table.appendChild(tbody);
      wrap.appendChild(table);
    }).catch(function (e) {
      loading.remove();
      wrap.appendChild(Dom.el('div', { style: 'padding:24px;color:#DC2626;' }, 'Could not load: ' + e.message));
    });
  }

  // v22p24: shared modal for both Create and Edit agency.
  // existing = null for create, or the agency row for edit (pre-fills inputs).
  function showAgencyModal(existing, container) {
    var isEdit = !!existing;
    var overlay = Dom.el('div', { style: 'position:fixed;inset:0;background:rgba(15,23,42,.55);z-index:9999;display:flex;align-items:flex-start;justify-content:center;padding:24px;overflow:auto;' });
    var modal = Dom.el('div', { style: 'background:white;border-radius:14px;padding:24px;max-width:580px;width:100%;box-shadow:0 20px 50px rgba(0,0,0,.3);' });
    function v(key, fallback) {
      if (! existing) return fallback || '';
      return (existing[key] === null || existing[key] === undefined) ? (fallback || '') : existing[key];
    }
    var whiteLabelChecked = isEdit && existing.powered_by_visible === 0;
    var primaryColor = v('brand_primary_color', '#1F6080');
    modal.innerHTML =
      '<h3 style="margin:0 0 14px;font-size:18px;">' + (isEdit ? '✏️ Edit agency' : '🌐 Create new customer agency') + '</h3>' +
      (isEdit ? '' : '<p style="font-size:13px;color:#6B7280;margin:0 0 16px;">Provisions a brand-new tenant on the platform. Starts on a 30-day trial. You can invite the first agency_admin afterwards via the User management tab once you switch into the new agency.</p>') +
      '<div style="margin-bottom:12px;"><label style="display:block;font-size:13px;font-weight:600;margin-bottom:4px;">Agency name *</label><input id="ag-name" type="text" placeholder="e.g. Tiny Steps Daycare" value="' + esc(v('name')) + '" style="width:100%;padding:8px 12px;border:1px solid #D1D5DB;border-radius:6px;font-size:14px;box-sizing:border-box;"></div>' +
      '<div style="margin-bottom:12px;"><label style="display:block;font-size:13px;font-weight:600;margin-bottom:4px;">Contact email</label><input id="ag-email" type="email" value="' + esc(v('contact_email')) + '" style="width:100%;padding:8px 12px;border:1px solid #D1D5DB;border-radius:6px;font-size:14px;box-sizing:border-box;"></div>' +
      '<div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:14px;">' +
        '<div><label style="display:block;font-size:13px;font-weight:600;margin-bottom:4px;">Plan code</label><input id="ag-plan" type="text" placeholder="starter, growth, enterprise" value="' + esc(v('plan_code')) + '" style="width:100%;padding:8px 12px;border:1px solid #D1D5DB;border-radius:6px;font-size:14px;box-sizing:border-box;"></div>' +
        '<div><label style="display:block;font-size:13px;font-weight:600;margin-bottom:4px;">Monthly CAD</label><input id="ag-amount" type="number" min="0" step="1" placeholder="149" value="' + (existing && existing.plan_amount_cents ? Math.round(existing.plan_amount_cents / 100) : '') + '" style="width:100%;padding:8px 12px;border:1px solid #D1D5DB;border-radius:6px;font-size:14px;box-sizing:border-box;"></div>' +
      '</div>' +
      // ── White-label section ────────────────────────────────────────
      '<div style="border-top:1px solid #E5E7EB;padding-top:14px;margin-bottom:12px;">' +
        '<div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:6px;">' +
          '<div><div style="font-size:14px;font-weight:700;">🎨 White-label branding</div>' +
            '<div style="font-size:11px;color:#6B7280;">Chargeable add-on. When enabled, the agency shows its own logo + colours and the "Powered by Kiddietrac" footer is hidden. Price baked into the monthly amount above (suggest +$50/mo).</div>' +
          '</div>' +
          '<label style="display:inline-flex;align-items:center;gap:6px;font-size:13px;font-weight:600;cursor:pointer;white-space:nowrap;">' +
            '<input id="ag-wl" type="checkbox" ' + (whiteLabelChecked ? 'checked' : '') + '> Enable' +
          '</label>' +
        '</div>' +
        '<div id="ag-wl-fields" style="margin-top:10px;' + (whiteLabelChecked ? '' : 'opacity:0.4;') + '">' +
          '<div style="margin-bottom:10px;"><label style="display:block;font-size:12px;font-weight:600;margin-bottom:3px;">Logo URL (PNG/SVG, max 200×60)</label>' +
            '<input id="ag-logo" type="text" placeholder="https://customer.com/logo.png" value="' + esc(v('brand_logo_url')) + '" style="width:100%;padding:7px 10px;border:1px solid #D1D5DB;border-radius:6px;font-size:13px;box-sizing:border-box;font-family:ui-monospace,monospace;"></div>' +
          '<div style="display:grid;grid-template-columns:120px 1fr;gap:10px;margin-bottom:10px;">' +
            '<div><label style="display:block;font-size:12px;font-weight:600;margin-bottom:3px;">Primary colour</label><input id="ag-color" type="color" value="' + esc(primaryColor) + '" style="width:100%;height:36px;border:1px solid #D1D5DB;border-radius:6px;padding:2px;cursor:pointer;"></div>' +
            '<div><label style="display:block;font-size:12px;font-weight:600;margin-bottom:3px;">Support email</label><input id="ag-support" type="email" placeholder="support@customer.com" value="' + esc(v('brand_support_email')) + '" style="width:100%;padding:7px 10px;border:1px solid #D1D5DB;border-radius:6px;font-size:13px;box-sizing:border-box;"></div>' +
          '</div>' +
        '</div>' +
      '</div>' +
      '<div id="ag-err" style="color:#DC2626;font-size:13px;min-height:18px;margin-bottom:8px;"></div>' +
      '<div style="display:flex;justify-content:flex-end;gap:8px;">' +
        '<button id="ag-cancel" style="background:white;color:#374151;border:1px solid #D1D5DB;padding:9px 16px;border-radius:8px;font-size:13px;font-weight:600;cursor:pointer;">Cancel</button>' +
        '<button id="ag-save" style="background:#7C3AED;color:white;border:none;padding:9px 16px;border-radius:8px;font-size:13px;font-weight:600;cursor:pointer;">' + (isEdit ? 'Save changes' : 'Create agency') + '</button>' +
      '</div>';
    overlay.appendChild(modal);
    document.body.appendChild(overlay);
    overlay.addEventListener('click', function (e) { if (e.target === overlay) overlay.remove(); });
    modal.querySelector('#ag-cancel').addEventListener('click', function () { overlay.remove(); });

    // White-label toggle dims the section when off (for UX clarity).
    var wlBox = modal.querySelector('#ag-wl');
    var wlFields = modal.querySelector('#ag-wl-fields');
    wlBox.addEventListener('change', function () {
      wlFields.style.opacity = wlBox.checked ? '1' : '0.4';
    });

    modal.querySelector('#ag-save').addEventListener('click', async function () {
      var payload = {
        name: modal.querySelector('#ag-name').value.trim(),
        contact_email: modal.querySelector('#ag-email').value.trim() || null,
        plan_code: modal.querySelector('#ag-plan').value.trim() || null,
        plan_amount_cents: (function () { var n = parseInt(modal.querySelector('#ag-amount').value, 10); return isNaN(n) ? 0 : n * 100; })(),
        white_label_enabled: wlBox.checked,
        brand_logo_url: modal.querySelector('#ag-logo').value.trim() || null,
        brand_primary_color: modal.querySelector('#ag-color').value || null,
        brand_support_email: modal.querySelector('#ag-support').value.trim() || null,
      };
      var errBox = modal.querySelector('#ag-err');
      errBox.textContent = '';
      if (! payload.name) { errBox.textContent = 'Agency name is required.'; return; }
      var saveBtn = modal.querySelector('#ag-save');
      saveBtn.disabled = true;
      saveBtn.textContent = isEdit ? 'Saving…' : 'Creating…';
      try {
        if (isEdit) {
          await Api.patch('/platform/agencies/' + existing.id, payload);
        } else {
          await Api.post('/platform/agencies', payload);
        }
        overlay.remove();
        renderAgencies(container);
      } catch (e) {
        saveBtn.disabled = false;
        saveBtn.textContent = isEdit ? 'Save changes' : 'Create agency';
        errBox.textContent = e.message || 'Could not save.';
      }
    });
  }

  // Backwards-compatible name still used by existing render path.
  function showCreateAgencyModal(container) {
    return showAgencyModal(null, container);
  }

  if (Shell && Shell.registerScreen) {
    // Register for every role that has sidebar access — server gates by middleware.
    ['agency_admin', 'centre_director', 'platform_admin'].forEach(function (r) {
      Shell.registerScreen(r + ':platform-overview', renderOverview);
      Shell.registerScreen(r + ':platform-agencies', renderAgencies);
    });
  }
  KT.PlatformScreens = { overview: renderOverview, agencies: renderAgencies };
})(window);
