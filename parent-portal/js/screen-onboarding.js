/* Kiddietrac v22p3.5 - Onboarding wizard
   Multi-step capture for new users (admin, director, educator, guardian).
   Auto-triggers on first dashboard load when user.onboarded_at is null.
   Re-runnable from the user management 'Reopen onboarding' link, so admins
   can hand the wizard back to a user whose profile needs more detail.
*/
(function (window) {
  'use strict';
  if (!window.KT || !window.KT.Shell) return;
  var Shell = window.KT.Shell;

  function token() { return sessionStorage.getItem('kt_token') || localStorage.getItem('kt_token'); }
  function apiBase() { return (window.KT && window.KT.API_BASE) || 'https://api.kiddietrac.com/api/v1'; }
  function getUser() { try { return JSON.parse(sessionStorage.getItem('kt_user') || '{}'); } catch (e) { return {}; } }
  function setUser(u) { sessionStorage.setItem('kt_user', JSON.stringify(u)); }
  function role() { var u = getUser(); return (u && (u.primary_role || (u.roles && u.roles[0]))) || ''; }
  function esc(s) { return s == null ? '' : String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;'); }

  async function api(method, path, body) {
    var opts = { method: method, headers: { 'Authorization': 'Bearer ' + token(), 'Accept': 'application/json' } };
    if (body !== undefined) { opts.headers['Content-Type'] = 'application/json'; opts.body = JSON.stringify(body); }
    var res = await fetch(apiBase() + path, opts);
    var json = await res.json().catch(function () { return {}; });
    if (!res.ok) {
      var msg = json.message || ('API ' + res.status);
      if (json.errors) {
        var first = Object.values(json.errors)[0];
        if (first && first[0]) msg = first[0];
      }
      throw new Error(msg);
    }
    return json;
  }

  // Agency-scoped calls (team invites, branding) need the active-agency header.
  async function agencyReq(method, path, body, agencyId) {
    var headers = { 'Authorization': 'Bearer ' + token(), 'Accept': 'application/json', 'Content-Type': 'application/json' };
    if (agencyId) headers['X-Active-Agency-Id'] = String(agencyId);
    var res = await fetch(apiBase() + path, { method: method, headers: headers, body: JSON.stringify(body || {}) });
    var json = await res.json().catch(function () { return {}; });
    if (!res.ok) {
      var m = json.message || ('API ' + res.status);
      if (json.errors) { var f = Object.values(json.errors)[0]; if (f && f[0]) m = f[0]; }
      throw new Error(m);
    }
    return json;
  }
  function agencyPost(path, body, agencyId) { return agencyReq('POST', path, body, agencyId); }
  function agencyPatch(path, body, agencyId) { return agencyReq('PATCH', path, body, agencyId); }

  // ─── Field config per role ───────────────────────────────────────────
  var COMMON_STEPS = [
    { id: 'profile', title: 'Your profile', subtitle: 'Confirm the basics so people know who they are messaging.' },
    { id: 'address', title: 'Address & emergency contact', subtitle: 'Where you are and who to reach in an emergency.' },
    { id: 'role',    title: '',           subtitle: '' }, // populated per-role
  ];

  // v22p83: countries offered at onboarding. Selecting one applies the agency's
  // currency, locale and compliance frameworks (see CurrencyController).
  var ONBOARD_COUNTRIES = [
    { code: 'CA', label: '🇨🇦 Canada' },
    { code: 'US', label: '🇺🇸 United States' },
    { code: 'GB', label: '🇬🇧 United Kingdom' },
    { code: 'AU', label: '🇦🇺 Australia' },
    { code: 'NZ', label: '🇳🇿 New Zealand' },
    { code: 'IE', label: '🇮🇪 Ireland' },
  ];

  function roleStepConfig(r) {
    if (r === 'agency_admin') {
      return {
        title: 'About your organization',
        subtitle: 'Quick details about your agency for billing and compliance.',
        fields: [
          { k: 'country',                      label: 'Country (sets currency & childcare/PCI/privacy compliance)', type: 'select', options: ONBOARD_COUNTRIES.map(function (c) { return c.label; }) },
          { k: 'business_name',                label: 'Business / legal entity name', type: 'text', placeholder: 'KiddieTrac Inc.' },
          { k: 'business_registration_number', label: 'Business / tax registration number',  type: 'text', placeholder: 'e.g. HST/CRA, EIN, VAT' },
          { k: 'billing_email',                label: 'Billing email',                 type: 'email' },
          { k: 'target_centre_count',          label: 'Number of centres you operate', type: 'number' },
        ],
      };
    }
    if (r === 'centre_director') {
      return {
        title: 'About your role',
        subtitle: 'Your credentials help families understand who is caring for their child.',
        fields: [
          { k: 'years_experience',     label: 'Years of experience',                       type: 'number' },
          { k: 'rece_number',          label: 'RECE registration number',                  type: 'text', placeholder: 'College of ECE Ontario #' },
          { k: 'first_aid_expiry',     label: 'First aid certification expiry',           type: 'date' },
          { k: 'supervisor_qualified', label: 'Supervisor-qualified under CCEYA?',         type: 'select', options: ['Yes', 'No'] },
          { k: 'languages_spoken',     label: 'Languages spoken (comma-separated)',        type: 'text', placeholder: 'English, French' },
        ],
      };
    }
    if (r === 'educator') {
      return {
        title: 'About your role',
        subtitle: 'Your credentials and specialties help with room assignments.',
        fields: [
          { k: 'rece_number',       label: 'RECE registration number',         type: 'text', placeholder: 'College of ECE Ontario #' },
          { k: 'first_aid_expiry',  label: 'First aid certification expiry',  type: 'date' },
          { k: 'cpr_expiry',        label: 'CPR certification expiry',         type: 'date' },
          { k: 'specialty',         label: 'Specialty room',                   type: 'select', options: ['Infant', 'Toddler', 'Preschool', 'Kindergarten', 'School-age', 'Mixed'] },
          { k: 'languages_spoken',  label: 'Languages spoken (comma-separated)', type: 'text', placeholder: 'English, French' },
        ],
      };
    }
    if (r === 'home_visitor') {
      return {
        title: 'About your role',
        subtitle: 'A few details about your home-visiting practice.',
        fields: [
          { k: 'credentials',      label: 'Credentials / certification',        type: 'text', placeholder: 'e.g. RECE, ECE diploma' },
          { k: 'first_aid_expiry', label: 'First aid certification expiry',      type: 'date' },
          { k: 'languages_spoken', label: 'Languages spoken (comma-separated)',  type: 'text', placeholder: 'English, French' },
          { k: 'coverage_area',    label: 'Coverage area / neighbourhoods',      type: 'text' },
          { k: 'caseload',         label: 'Approximate caseload (families)',     type: 'number' },
        ],
      };
    }
    if (r === 'sales_rep') {
      return {
        title: 'About you',
        subtitle: 'A few details for your sales profile.',
        fields: [
          { k: 'job_title',     label: 'Job title',               type: 'text', placeholder: 'Account Executive' },
          { k: 'work_phone',    label: 'Work phone',              type: 'tel' },
          { k: 'territory',     label: 'Territory / region',      type: 'text', placeholder: 'e.g. Ontario' },
          { k: 'calendar_link', label: 'Booking / calendar link', type: 'text', placeholder: 'https://calendly.com/' },
        ],
      };
    }
    // guardian
    return {
      title: 'About your family',
      subtitle: 'A few extra details so the centre can communicate with you properly.',
      fields: [
        { k: 'preferred_contact',  label: 'Preferred way to receive updates', type: 'select', options: ['Email', 'SMS', 'In-app push'] },
        { k: 'employer',           label: 'Employer (for emergency reach)',   type: 'text' },
        { k: 'work_phone',         label: 'Work phone',                       type: 'tel' },
        { k: 'authorized_pickups', label: 'Other people authorized to pick up (one per line)', type: 'textarea' },
        { k: 'photo_consent',      label: 'OK with classroom photos?',        type: 'select', options: ['Yes', 'No, please do not photograph my child'] },
      ],
    };
  }

  // ─── Wizard renderer ─────────────────────────────────────────────────
  // v22p91: agency admins get two extra steps after the role step — "Invite
  // your team" and (only if their plan includes it) "White-label branding".
  // So the step list is built dynamically; we fetch the agency's feature flags
  // first to decide whether the white-label step applies.
  function render(container, ctx) {
    var user = (ctx && ctx.user) || getUser();
    var r = role();
    container.innerHTML = '<div style="max-width:760px;margin:48px auto;padding:40px;text-align:center;color:#64748B;font-size:14px;">Loading your setup…</div>';
    (async function () {
      var whiteLabel = false;
      if (r === 'agency_admin' && user.agency_id) {
        try {
          var f = await api('GET', '/admin/agencies/' + user.agency_id + '/features');
          whiteLabel = !!(f && f.flags && f.flags.white_label);
        } catch (e) { /* default: no white-label step */ }
      }
      buildWizard(container, user, r, whiteLabel);
    })();
  }

  function buildWizard(container, user, r, whiteLabel) {
    var roleStep = roleStepConfig(r);
    COMMON_STEPS[2].title = roleStep.title;
    COMMON_STEPS[2].subtitle = roleStep.subtitle;

    var steps = COMMON_STEPS.slice();
    if (r === 'agency_admin') {
      steps.push({ id: 'team', title: 'Invite your team', subtitle: 'Add directors and educators (optional — you can do this anytime).' });
      if (whiteLabel) steps.push({ id: 'whitelabel', title: 'Make it yours', subtitle: 'Your plan includes white-label branding — add your logo and colours.' });
    }

    var state = {
      step: 0,
      steps: steps,
      data: {
        first_name:    user.first_name || '',
        last_name:     user.last_name || '',
        preferred_name: user.preferred_name || '',
        phone:         user.phone || '',
        photo_url:     user.photo_url || '',
        username:      user.username || '',
      },
      role_extras: (user.profile_extras && user.profile_extras.role_extras) || {},
      address: (function () {
        var pe = user.profile_extras || {};
        return {
          address_line1: pe.address_line1 || '',
          address_line2: pe.address_line2 || '',
          city: pe.city || '',
          province: pe.province || '',
          postal_code: pe.postal_code || '',
          emergency_contact_name: pe.emergency_contact_name || '',
          emergency_contact_phone: pe.emergency_contact_phone || '',
        };
      })(),
      team: [],
      whitelabel: { brand_logo_url: '', brand_primary_color: '#1F6080', brand_support_email: user.email || '', brand_privacy_url: '', brand_terms_url: '' },
      agencyId: user.agency_id,
    };

    container.innerHTML =
      '<div style="max-width:760px;margin:24px auto;padding:0 20px;">' +
        '<div style="background:linear-gradient(135deg,#081C41,#1F6080);color:white;border-radius:18px 18px 0 0;padding:28px 32px;">' +
          '<div style="font-size:11px;font-weight:800;letter-spacing:1.5px;text-transform:uppercase;opacity:0.85;margin-bottom:8px;">Welcome to Kiddietrac</div>' +
          '<h1 style="font-family:\'Baloo 2\',system-ui,sans-serif;font-size:30px;margin:0 0 4px;font-weight:800;letter-spacing:-0.5px;">Let\'s set up your account</h1>' +
          '<p style="margin:0;opacity:0.9;font-size:14px;">A few questions to personalize your experience. You can edit any of this later from your profile.</p>' +
        '</div>' +
        '<div id="kt-onboarding-card" style="background:white;border-radius:0 0 18px 18px;padding:32px;box-shadow:0 8px 24px rgba(15,23,42,0.08);">' +
          '<div id="kt-step-bar" style="display:flex;gap:6px;margin-bottom:24px;"></div>' +
          '<div id="kt-step-title" style="margin-bottom:18px;"></div>' +
          '<div id="kt-step-body"></div>' +
          '<div id="kt-step-msg" style="font-size:13px;min-height:20px;margin-top:12px;"></div>' +
          '<div style="display:flex;justify-content:space-between;align-items:center;margin-top:24px;padding-top:18px;border-top:1px solid #E5E7EB;">' +
            '<button id="kt-back" type="button" style="padding:10px 18px;background:white;color:#475569;border:1.5px solid #CBD5E1;border-radius:10px;font-weight:600;cursor:pointer;">Back</button>' +
            '<div style="font-size:12px;color:#64748B;">Step <span id="kt-step-n">1</span> of ' + steps.length + '</div>' +
            '<button id="kt-next" type="button" style="padding:10px 24px;background:linear-gradient(135deg,#081C41,#1F6080);color:white;border:none;border-radius:10px;font-weight:700;cursor:pointer;">Next</button>' +
          '</div>' +
        '</div>' +
      '</div>';

    function drawBar() {
      var bar = container.querySelector('#kt-step-bar');
      bar.innerHTML = '';
      for (var i = 0; i < steps.length; i++) {
        var el = document.createElement('div');
        el.style.cssText = 'flex:1;height:6px;border-radius:3px;background:' + (i <= state.step ? '#1F6080' : '#E2E8F0') + ';transition:background 200ms ease;';
        bar.appendChild(el);
      }
      container.querySelector('#kt-step-n').textContent = String(state.step + 1);
    }

    function drawTitle() {
      var s = steps[state.step];
      container.querySelector('#kt-step-title').innerHTML =
        '<div style="font-size:22px;font-weight:800;color:#0F172A;margin-bottom:4px;font-family:\'Baloo 2\',system-ui,sans-serif;">' + esc(s.title) + '</div>' +
        '<div style="font-size:14px;color:#64748B;">' + esc(s.subtitle) + '</div>';
    }

    function drawStep() {
      drawBar();
      drawTitle();
      var body = container.querySelector('#kt-step-body');
      body.innerHTML = '';
      var id = steps[state.step].id;
      if (id === 'profile') body.appendChild(renderProfileStep(state, container));
      else if (id === 'address') body.appendChild(renderAddressStep(state));
      else if (id === 'role') body.appendChild(renderRoleStep(state, roleStep));
      else if (id === 'team') body.appendChild(renderTeamStep(state));
      else if (id === 'whitelabel') body.appendChild(renderWhiteLabelStep(state, container));

      container.querySelector('#kt-back').style.visibility = state.step === 0 ? 'hidden' : 'visible';
      container.querySelector('#kt-next').textContent = state.step === steps.length - 1 ? 'Save and continue' : 'Next';
    }

    container.querySelector('#kt-back').addEventListener('click', function () {
      if (state.step > 0) { state.step--; drawStep(); }
    });
    container.querySelector('#kt-next').addEventListener('click', async function () {
      collectCurrentStep(state, container);
      // Validate the CURRENT step before moving on (previously validation only
      // ran at the very end, so users breezed past missing required fields).
      if (!validateStep(state, container)) return;
      if (state.step < steps.length - 1) {
        state.step++;
        drawStep();
      } else {
        await submit(state, container);
      }
    });

    drawStep();
  }

  function input(label, key, value, type, placeholder, options, required) {
    type = type || 'text';
    var req = required ? ' data-req="1"' : '';
    var html;
    if (type === 'textarea') {
      html = '<textarea data-k="' + esc(key) + '"' + req + ' rows="3" placeholder="' + esc(placeholder || '') + '" style="' + inputStyle() + 'font-family:inherit;resize:vertical;">' + esc(value || '') + '</textarea>';
    } else if (type === 'select') {
      html = '<select data-k="' + esc(key) + '"' + req + ' style="' + inputStyle() + 'background:white;">' +
        '<option value="">Select…</option>' +
        (options || []).map(function (o) {
          return '<option value="' + esc(o) + '"' + (value === o ? ' selected' : '') + '>' + esc(o) + '</option>';
        }).join('') +
      '</select>';
    } else {
      html = '<input data-k="' + esc(key) + '"' + req + ' type="' + esc(type) + '" placeholder="' + esc(placeholder || '') + '" value="' + esc(value || '') + '" style="' + inputStyle() + '">';
    }
    var wrap = document.createElement('div');
    wrap.style.cssText = 'margin-bottom:14px;';
    var star = required ? ' <span style="color:#DC2626;font-weight:800;" title="Required">*</span>' : '';
    wrap.innerHTML = '<label style="display:block;font-size:13px;font-weight:700;color:#334155;margin-bottom:6px;">' + esc(label) + star + '</label>' + html;
    return wrap;
  }
  function inputStyle() {
    return 'width:100%;padding:11px 14px;border:1.5px solid #E2E8F0;border-radius:10px;font-size:14px;box-sizing:border-box;';
  }

  function renderProfileStep(state, container) {
    var box = document.createElement('div');

    // Avatar uploader — uploads immediately to /auth/me/avatar.
    var av = document.createElement('div');
    av.style.cssText = 'display:flex;align-items:center;gap:16px;margin-bottom:18px;';
    var prevBg = state.data.photo_url ? "background:#F1F5F9 url('" + absUrlO(state.data.photo_url) + "') center/cover no-repeat;" : 'background:#F1F5F9;';
    av.innerHTML =
      '<div id="kt-av-prev" style="width:72px;height:72px;border-radius:50%;' + prevBg + 'display:flex;align-items:center;justify-content:center;color:#64748B;font-size:26px;flex-shrink:0;border:2px solid #E2E8F0;">' + (state.data.photo_url ? '' : '👤') + '</div>' +
      '<div><button type="button" id="kt-av-btn" style="background:white;color:#1F6080;border:1.5px solid #1F6080;padding:8px 14px;border-radius:9px;font-weight:600;cursor:pointer;font-size:13px;">Upload avatar</button>' +
        '<input id="kt-av-file" type="file" accept="image/png,image/jpeg,image/webp" style="display:none;">' +
        '<div id="kt-av-msg" style="font-size:11px;color:#64748B;margin-top:5px;">Required · JPG/PNG, max 2 MB</div></div>';
    var avLabel = document.createElement('div');
    avLabel.style.cssText = 'font-size:13px;font-weight:700;color:#334155;margin-bottom:6px;';
    avLabel.innerHTML = 'Profile photo <span style="color:#DC2626;font-weight:800;" title="Required">*</span>';
    box.appendChild(avLabel);
    box.appendChild(av);

    var row = document.createElement('div');
    row.style.cssText = 'display:grid;grid-template-columns:1fr 1fr;gap:12px;';
    row.appendChild(input('First name',    'first_name',     state.data.first_name, 'text', null, null, true));
    row.appendChild(input('Last name',     'last_name',      state.data.last_name,  'text', null, null, true));
    box.appendChild(row);
    box.appendChild(input('Preferred name (optional, what we call you)', 'preferred_name', state.data.preferred_name));
    box.appendChild(input('Phone', 'phone', state.data.phone, 'tel', null, null, true));
    // Optional username — lets one person keep several accounts under one email
    // and sign in to the right one. Letters, numbers, and . _ - only.
    box.appendChild(input('Username (optional — sign in with this if you share an email across accounts)', 'username', state.data.username, 'text'));

    setTimeout(function () {
      var fileEl = box.querySelector('#kt-av-file');
      var msg = box.querySelector('#kt-av-msg');
      box.querySelector('#kt-av-btn').addEventListener('click', function () { fileEl.click(); });
      fileEl.addEventListener('change', function () {
        var f = fileEl.files[0]; if (!f) return;
        if (f.size > 2 * 1024 * 1024) { msg.textContent = 'Max 2 MB'; msg.style.color = '#DC2626'; return; }
        msg.textContent = 'Uploading…'; msg.style.color = '#64748B';
        var fd = new FormData(); fd.append('avatar', f);
        fetch(apiBase() + '/auth/me/avatar', { method: 'POST', headers: { 'Authorization': 'Bearer ' + token() }, body: fd })
          .then(function (res) { return res.json().then(function (j) { if (!res.ok) throw new Error(j.message || ('HTTP ' + res.status)); return j; }); })
          .then(function (j) {
            state.data.photo_url = j.photo_url;
            var prev = box.querySelector('#kt-av-prev');
            prev.style.cssText = 'width:72px;height:72px;border-radius:50%;background:#F1F5F9 url(\'' + absUrlO(j.photo_url) + '\') center/cover no-repeat;border:2px solid #E2E8F0;';
            prev.textContent = '';
            msg.textContent = '✓ Looking good'; msg.style.color = '#16A34A';
            try { var u = getUser(); u.photo_url = j.photo_url; setUser(u); } catch (e) {}
          })
          .catch(function (e) { msg.textContent = 'Upload failed: ' + (e.message || 'error'); msg.style.color = '#DC2626'; });
      });
    }, 0);
    return box;
  }

  function absUrlO(p) {
    if (!p) return '';
    if (/^https?:\/\//i.test(p)) return p;
    return apiBase().replace(/\/api\/v1\/?$/, '') + p;
  }

  // ── Agency-admin: invite team (directors / educators) ──────────────
  function renderTeamStep(state) {
    var box = document.createElement('div');
    box.innerHTML = '<div id="kt-team-rows"></div>' +
      '<button type="button" id="kt-team-add" style="margin-top:6px;background:white;color:#1F6080;border:1.5px dashed #1F6080;border-radius:9px;padding:9px 14px;font-weight:600;cursor:pointer;font-size:13px;">+ Add a team member</button>' +
      '<div style="margin-top:16px;background:#F8FAFC;border:1px solid #E2E8F0;border-radius:10px;padding:12px 14px;font-size:12px;color:#64748B;">Each person gets an email invite to set their password. <strong>Parents</strong> are added per family later under <strong>Families</strong> (once you’ve added children). This step is optional — skip it and add people anytime.</div>';
    var rowsWrap = box.querySelector('#kt-team-rows');

    function addRow(pre) {
      pre = pre || {};
      var row = document.createElement('div');
      row.setAttribute('data-team-row', '1');
      row.style.cssText = 'display:grid;grid-template-columns:150px 1fr 1fr 1fr 28px;gap:8px;align-items:center;margin-bottom:8px;';
      row.innerHTML =
        '<select data-tk="role" style="' + inputStyle() + 'background:white;padding:9px 10px;">' +
          '<option value="centre_director"' + (pre.role === 'centre_director' ? ' selected' : '') + '>Centre director</option>' +
          '<option value="educator"' + (!pre.role || pre.role === 'educator' ? ' selected' : '') + '>Educator</option>' +
        '</select>' +
        '<input data-tk="first_name" placeholder="First name" value="' + esc(pre.first_name || '') + '" style="' + inputStyle() + 'padding:9px 10px;">' +
        '<input data-tk="last_name" placeholder="Last name" value="' + esc(pre.last_name || '') + '" style="' + inputStyle() + 'padding:9px 10px;">' +
        '<input data-tk="email" type="email" placeholder="email@agency.com" value="' + esc(pre.email || '') + '" style="' + inputStyle() + 'padding:9px 10px;">' +
        '<button type="button" data-tk-del style="background:white;border:1px solid #E2E8F0;border-radius:8px;color:#DC2626;cursor:pointer;height:38px;">×</button>';
      row.querySelector('[data-tk-del]').addEventListener('click', function () { row.remove(); });
      rowsWrap.appendChild(row);
    }

    (state.team && state.team.length ? state.team : [{}]).forEach(addRow);
    box.querySelector('#kt-team-add').addEventListener('click', function () { addRow(); });
    return box;
  }

  // ── Agency-admin: white-label branding (plan includes it) ──────────
  function renderWhiteLabelStep(state, container) {
    var box = document.createElement('div');
    var wl = state.whitelabel;
    var prevBg = wl.brand_logo_url ? "background:#F8FAFC url('" + absUrlO(wl.brand_logo_url) + "') center/contain no-repeat;" : 'background:#F8FAFC;';
    box.innerHTML =
      '<div style="display:flex;align-items:center;gap:14px;margin-bottom:16px;">' +
        '<div id="kt-wl-prev" style="width:120px;height:64px;border:1px solid #E2E8F0;border-radius:10px;' + prevBg + 'display:flex;align-items:center;justify-content:center;color:#64748B;font-size:22px;flex-shrink:0;">' + (wl.brand_logo_url ? '' : '🖼') + '</div>' +
        '<div><button type="button" id="kt-wl-btn" style="background:white;color:#1F6080;border:1.5px solid #1F6080;padding:8px 14px;border-radius:9px;font-weight:600;cursor:pointer;font-size:13px;">Upload your logo</button>' +
          '<input id="kt-wl-file" type="file" accept="image/png,image/jpeg,image/svg+xml,image/webp" style="display:none;">' +
          '<div id="kt-wl-msg" style="font-size:11px;color:#64748B;margin-top:5px;">PNG/SVG, max 2 MB</div></div>' +
      '</div>';
    box.appendChild(input('Primary colour (hex)', 'brand_primary_color', wl.brand_primary_color, 'text', '#1F6080'));
    box.appendChild(input('Support email', 'brand_support_email', wl.brand_support_email, 'email'));
    var r2 = document.createElement('div');
    r2.style.cssText = 'display:grid;grid-template-columns:1fr 1fr;gap:12px;';
    r2.appendChild(input('Privacy policy URL', 'brand_privacy_url', wl.brand_privacy_url, 'text', 'https://…/privacy'));
    r2.appendChild(input('Terms & conditions URL', 'brand_terms_url', wl.brand_terms_url, 'text', 'https://…/terms'));
    box.appendChild(r2);

    setTimeout(function () {
      var fileEl = box.querySelector('#kt-wl-file');
      var msg = box.querySelector('#kt-wl-msg');
      box.querySelector('#kt-wl-btn').addEventListener('click', function () { fileEl.click(); });
      fileEl.addEventListener('change', function () {
        var f = fileEl.files[0]; if (!f) return;
        if (f.size > 2 * 1024 * 1024) { msg.textContent = 'Max 2 MB'; msg.style.color = '#DC2626'; return; }
        msg.textContent = 'Uploading…'; msg.style.color = '#64748B';
        var fd = new FormData(); fd.append('image', f);
        fetch(apiBase() + '/marketing/images', { method: 'POST', headers: { 'Authorization': 'Bearer ' + token() }, body: fd })
          .then(function (res) { return res.json().then(function (j) { if (!res.ok) throw new Error(j.message || ('HTTP ' + res.status)); return j; }); })
          .then(function (j) {
            wl.brand_logo_url = j.url || '';
            var prev = box.querySelector('#kt-wl-prev');
            prev.style.cssText = 'width:120px;height:64px;border:1px solid #E2E8F0;border-radius:10px;background:#F8FAFC url(\'' + absUrlO(wl.brand_logo_url) + '\') center/contain no-repeat;';
            prev.textContent = '';
            msg.textContent = '✓ Uploaded'; msg.style.color = '#16A34A';
          })
          .catch(function (e) { msg.textContent = 'Upload failed: ' + (e.message || 'error'); msg.style.color = '#DC2626'; });
      });
    }, 0);
    return box;
  }
  function renderAddressStep(state) {
    var box = document.createElement('div');
    box.appendChild(input('Street address',  'address_line1', state.address.address_line1));
    box.appendChild(input('Apt / unit (optional)', 'address_line2', state.address.address_line2));
    // City full-width, then Province + Postal side by side — the old 3-across
    // row squished the postal field on phones (it truncated "L9V1C4").
    box.appendChild(input('City', 'city', state.address.city));
    var row = document.createElement('div');
    row.style.cssText = 'display:grid;grid-template-columns:1fr 1fr;gap:12px;';
    row.appendChild(input('Province', 'province', state.address.province, 'select', null,
      ['ON','QC','BC','AB','MB','SK','NS','NB','NL','PE','NT','NU','YT']));
    row.appendChild(input('Postal code', 'postal_code', state.address.postal_code, 'text', 'M5G 1Z4'));
    box.appendChild(row);
    var row2 = document.createElement('div');
    row2.style.cssText = 'display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-top:6px;';
    row2.appendChild(input('Emergency contact name', 'emergency_contact_name', state.address.emergency_contact_name));
    row2.appendChild(input('Emergency contact phone', 'emergency_contact_phone', state.address.emergency_contact_phone, 'tel'));
    box.appendChild(row2);

    // Additional emergency contacts (optional) — add as many as needed.
    var extraWrap = document.createElement('div');
    extraWrap.style.cssText = 'margin-top:10px;';
    box.appendChild(extraWrap);
    state.address.extra_contacts = Array.isArray(state.address.extra_contacts) ? state.address.extra_contacts : [];
    function addContactRow(preset) {
      var idx = extraWrap.querySelectorAll('.kt-ec-row').length;
      var r = document.createElement('div');
      r.className = 'kt-ec-row';
      r.style.cssText = 'display:grid;grid-template-columns:1fr 1fr auto;gap:8px;align-items:end;margin-top:8px;';
      r.innerHTML =
        '<div><label style="display:block;font-size:12.5px;font-weight:700;color:#334155;margin-bottom:5px;">Contact name</label>'
        + '<input data-ec="name" value="' + esc((preset && preset.name) || '') + '" style="' + inputStyle() + '"></div>'
        + '<div><label style="display:block;font-size:12.5px;font-weight:700;color:#334155;margin-bottom:5px;">Phone</label>'
        + '<input data-ec="phone" type="tel" value="' + esc((preset && preset.phone) || '') + '" style="' + inputStyle() + '"></div>'
        + '<button type="button" class="kt-ec-del" title="Remove" style="height:44px;width:40px;border:1px solid #E2E8F0;background:#fff;color:#B91C1C;border-radius:10px;font-size:16px;cursor:pointer;">✕</button>';
      r.querySelector('.kt-ec-del').addEventListener('click', function () { r.remove(); });
      extraWrap.appendChild(r);
    }
    (state.address.extra_contacts || []).forEach(function (c) { addContactRow(c); });
    var addBtn = document.createElement('button');
    addBtn.type = 'button';
    addBtn.textContent = '＋ Add another emergency contact';
    addBtn.style.cssText = 'margin-top:12px;background:#fff;border:1.5px dashed #CBD5E1;color:#1F6080;border-radius:10px;padding:11px;font-size:13.5px;font-weight:700;cursor:pointer;width:100%;';
    addBtn.addEventListener('click', function () { addContactRow(null); });
    box.appendChild(addBtn);
    return box;
  }
  function renderRoleStep(state, roleStep) {
    var box = document.createElement('div');
    roleStep.fields.forEach(function (f) {
      box.appendChild(input(f.label, f.k, state.role_extras[f.k], f.type, f.placeholder, f.options));
    });
    return box;
  }

  function collectCurrentStep(state, container) {
    var id = state.steps[state.step].id;
    var body = container.querySelector('#kt-step-body');
    body.querySelectorAll('[data-k]').forEach(function (el) {
      var k = el.dataset.k;
      var v = el.value;
      if (id === 'profile') state.data[k] = v;
      else if (id === 'address') state.address[k] = v;
      else if (id === 'role') state.role_extras[k] = v;
      else if (id === 'whitelabel') state.whitelabel[k] = v;
    });
    if (id === 'team') collectTeam(state, body);
    if (id === 'address') {
      var extras = [];
      body.querySelectorAll('.kt-ec-row').forEach(function (row) {
        var n = row.querySelector('[data-ec="name"]'); var p = row.querySelector('[data-ec="phone"]');
        var nm = n ? n.value.trim() : ''; var ph = p ? p.value.trim() : '';
        if (nm || ph) extras.push({ name: nm, phone: ph });
      });
      state.address.extra_contacts = extras;
    }
  }

  // Pull the team-invite rows out of the DOM into state.team.
  function collectTeam(state, body) {
    var rows = [];
    body.querySelectorAll('[data-team-row]').forEach(function (row) {
      var get = function (sel) { var el = row.querySelector(sel); return el ? el.value.trim() : ''; };
      var email = get('[data-tk="email"]');
      var first = get('[data-tk="first_name"]');
      if (!email && !first) return; // skip empty rows
      rows.push({
        role: get('[data-tk="role"]') || 'educator',
        first_name: first,
        last_name: get('[data-tk="last_name"]'),
        email: email,
      });
    });
    state.team = rows;
  }

  // Validate the CURRENT step's required fields; highlight + message if any are
  // missing. Returns true when the step is complete enough to advance.
  function validateStep(state, container) {
    var msg = container.querySelector('#kt-step-msg');
    var body = container.querySelector('#kt-step-body');
    var id = state.steps[state.step].id;
    var missing = [];
    var firstBad = null;
    body.querySelectorAll('[data-req]').forEach(function (el) {
      var v = (el.value || '').trim();
      var labelEl = el.parentNode ? el.parentNode.querySelector('label') : null;
      var label = labelEl ? labelEl.textContent.replace(/\*/g, '').trim() : 'A required field';
      if (!v) { missing.push(label); el.style.borderColor = '#DC2626'; if (!firstBad) firstBad = el; }
      else { el.style.borderColor = '#E2E8F0'; }
    });
    if (id === 'profile' && !state.data.photo_url) {
      missing.push('Profile photo');
      var avMsg = body.querySelector('#kt-av-msg');
      if (avMsg) { avMsg.textContent = 'Profile photo is required'; avMsg.style.color = '#DC2626'; }
    }
    if (missing.length) {
      msg.style.color = '#DC2626';
      msg.textContent = 'Please complete: ' + missing.join(', ') + '.';
      if (firstBad && firstBad.focus) try { firstBad.focus(); } catch (e) {}
      return false;
    }
    msg.textContent = '';
    return true;
  }

  async function submit(state, container) {
    var btn = container.querySelector('#kt-next');
    var msg = container.querySelector('#kt-step-msg');
    btn.disabled = true; btn.textContent = 'Saving…';
    msg.textContent = '';

    if (!state.data.first_name || !state.data.last_name) {
      msg.textContent = 'First and last name are required.'; msg.style.color = '#DC2626';
      btn.disabled = false; btn.textContent = 'Save and continue';
      state.step = 0;
      // Re-draw step 0 so user sees the missing fields
      var ev = new CustomEvent('redraw'); container.dispatchEvent(ev);
      return;
    }
    // A profile photo is mandatory (marked with a red asterisk in the form).
    if (!state.data.photo_url) {
      msg.textContent = 'A profile photo is required — tap “Upload avatar”.'; msg.style.color = '#DC2626';
      btn.disabled = false; btn.textContent = 'Save and continue';
      state.step = 0;
      var evp = new CustomEvent('redraw'); container.dispatchEvent(evp);
      return;
    }

    var payload = Object.assign({}, state.data, state.address, { role_extras: state.role_extras, complete: true });
    try {
      var fresh = await api('PATCH', '/auth/me/onboarding', payload);
      setUser(fresh);
      // v22p83: if an agency admin picked a country, apply its currency +
      // compliance to the agency. Best-effort — never block onboarding on it.
      if (state.role_extras && state.role_extras.country) {
        var picked = ONBOARD_COUNTRIES.filter(function (c) { return c.label === state.role_extras.country; })[0];
        if (picked) { try { await api('PATCH', '/admin/country', { country: picked.code }); } catch (e) { /* non-fatal */ } }
      }

      // v22p91 — agency-admin extras (best-effort; never block finishing).
      var notes = [];
      if (state.team && state.team.length) {
        var invited = 0;
        for (var i = 0; i < state.team.length; i++) {
          var m = state.team[i];
          if (!m.email || !m.first_name || !m.last_name) continue;
          try { await agencyPost('/admin/users', { role: m.role, first_name: m.first_name, last_name: m.last_name, email: m.email, send_invite: true }, state.agencyId); invited++; }
          catch (e) { /* skip this invite */ }
        }
        if (invited) notes.push(invited + ' invite' + (invited === 1 ? '' : 's') + ' sent');
      }
      // Branding lives behind the "Make it yours" step, which is ONLY shown to
      // agency admins — and the /features endpoint is admin-only (403 otherwise).
      // brand_support_email defaults to the user's own email for every role, so
      // without this role gate an educator/director/parent finishing onboarding
      // silently PATCHed the admin endpoint → a 403 in the audit log.
      var wl = state.whitelabel || {};
      if (role() === 'agency_admin' && state.agencyId && (wl.brand_logo_url || wl.brand_privacy_url || wl.brand_terms_url || wl.brand_support_email)) {
        try {
          await agencyPatch('/admin/agencies/' + state.agencyId + '/features', {
            brand_logo_url: wl.brand_logo_url || null,
            brand_primary_color: /^#[0-9a-fA-F]{6}$/.test(wl.brand_primary_color || '') ? wl.brand_primary_color : null,
            brand_support_email: wl.brand_support_email || null,
            brand_privacy_url: wl.brand_privacy_url || null,
            brand_terms_url: wl.brand_terms_url || null,
          }, state.agencyId);
          notes.push('branding saved');
        } catch (e) { /* non-fatal */ }
      }

      // Done! Redirect to the ROLE's own home screen — not a hardcoded
      // #dashboard (a home visitor has no dashboard screen, which produced a
      // "Required role: Guardian" 403 when it fell through to a guardian view).
      var landing = 'dashboard';
      try {
        var rr = role();
        if (window.KT && KT.Shell && typeof KT.Shell.homeHashForRole === 'function') landing = KT.Shell.homeHashForRole(rr);
        else landing = (rr === 'home_visitor') ? 'home' : ((rr === 'guardian' || rr === 'educator') ? 'today' : 'dashboard');
      } catch (e) {}
      msg.textContent = '✓ All set' + (notes.length ? ' — ' + notes.join(' · ') : '') + ' — taking you to your home…';
      msg.style.color = '#16A34A';
      setTimeout(function () { window.location.hash = '#' + landing; }, 800);
    } catch (e) {
      msg.textContent = 'Could not save: ' + e.message;
      msg.style.color = '#DC2626';
      btn.disabled = false; btn.textContent = 'Save and continue';
    }
  }

  // ─── Auto-trigger on first load ──────────────────────────────────────
  // Runs at IIFE eval time (pre-DOMContentLoaded). All `defer` scripts run
  // in source order before DOMContentLoaded fires, so by setting the hash
  // here we ensure Shell.startApp() reads '#onboarding' on its first render
  // pass and routes directly to the wizard. Previously this ran on a 400 ms
  // setTimeout after DOMContentLoaded, which raced the dashboard's async
  // /agency/dashboard fetch — the dashboard append resolved last and
  // overwrote the wizard.
  // ── Onboarding GATE ─────────────────────────────────────────────────
  // Until onboarding is complete the user must not reach any other screen —
  // not via the back button (browser OR Android hardware), a deep link, a
  // reload on another hash, or in-app nav. `onboarded_at` (set server-side ONLY
  // when the wizard finishes) is the single source of truth. Skipped entirely
  // for a super admin using "View as". Sign-out still works: it's a full-page
  // navigation to /index.html, not a hash change, so it isn't intercepted.
  function onboardingIncomplete() {
    try { if (sessionStorage.getItem('kt_impersonating') === '1') return false; } catch (e) {}
    var u = getUser();
    return !!(u && u.id && !u.onboarded_at);
  }
  function forceGate() {
    if (!token()) return;                 // not signed in → nothing to gate
    if (!onboardingIncomplete()) return;  // done (or impersonating) → free to navigate
    var hash = (window.location.hash || '').replace('#', '').split('?')[0];
    if (hash !== 'onboarding') window.location.hash = '#onboarding'; // bounce back
  }
  // Kept for back-compat (called eagerly + by the shell); now the full gate.
  function maybeAutoTrigger() { forceGate(); }

  // Register screen for every role
  if (Shell && Shell.registerScreen) {
    ['agency_admin', 'centre_director', 'educator', 'guardian', 'auditor', 'home_visitor', 'sales_rep'].forEach(function (r) {
      Shell.registerScreen(r + ':onboarding', render);
    });
  }
  window.KT = window.KT || {};
  window.KT.Onboarding = { render: render, maybeAutoTrigger: maybeAutoTrigger, forceGate: forceGate };

  // Install the navigation guard ONCE: every hash change while onboarding is
  // incomplete bounces straight back to #onboarding — this catches the browser
  // back button AND the Android hardware back button (both fire hashchange).
  if (!window.__ktOnbGuard) {
    window.__ktOnbGuard = true;
    window.addEventListener('hashchange', forceGate);
    try {
      var CapApp = window.Capacitor && Capacitor.Plugins && Capacitor.Plugins.App;
      if (CapApp && CapApp.addListener) {
        CapApp.addListener('backButton', function () { if (onboardingIncomplete()) window.location.hash = '#onboarding'; });
      }
    } catch (e) {}
  }

  // Eager check at script-eval time (before Shell.startApp runs) + once more
  // after the shell boots (covers a kt_user that loads a beat later).
  forceGate();
  setTimeout(forceGate, 1200);

  // The SERVER is the source of truth for onboarding — the cached kt_user can go
  // stale or partial (app resume, re-auth, an older cache that predates the
  // onboarded_at field), which was re-prompting already-onboarded users on
  // sign-in. Sync kt_user from /auth/me and, if the server says onboarded,
  // release any gate that fired off the stale cache.
  function syncOnboardingFromServer() {
    if (!token()) return;
    fetch(apiBase() + '/auth/me', { headers: { 'Authorization': 'Bearer ' + token(), 'Accept': 'application/json' } })
      .then(function (r) { return r.ok ? r.json() : null; })
      .then(function (fresh) {
        if (!fresh || !fresh.id) return;
        var u = getUser(); if (!u || !u.id) u = {};
        setUser(Object.assign({}, u, fresh));   // fresh (incl. onboarded_at) wins
        if (fresh.onboarded_at) {
          var h = (window.location.hash || '').replace('#', '').split('?')[0];
          if (h === 'onboarding') {              // stuck on the wizard but actually done → release
            window.location.hash = '';
            if (window.KT && KT.Shell && KT.Shell.renderScreen) { try { KT.Shell.renderScreen(); } catch (e) {} }
          }
        } else {
          forceGate();                            // genuinely not onboarded → keep gating
        }
      })
      .catch(function () { /* offline / transient — leave the eager gate as-is */ });
  }
  syncOnboardingFromServer();
})(window);
