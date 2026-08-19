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

  // Best-effort device fingerprint for analytics (which device families onboard).
  // { type: 'Android' | 'Apple iPhone' | 'Apple iPad' | 'Desktop / laptop' | 'Other',
  //   detail: native-app flag + trimmed user-agent }. Never throws.
  function detectDevice() {
    var type = 'Other', native = '';
    try {
      var ua = navigator.userAgent || '';
      var C = window.Capacitor;
      if (C && typeof C.getPlatform === 'function') {
        var p = C.getPlatform();               // 'ios' | 'android' | 'web'
        if (p === 'android') { type = 'Android'; native = 'KiddieTrac Android app · '; }
        else if (p === 'ios') { type = /iPad/i.test(ua) ? 'Apple iPad' : 'Apple iPhone'; native = 'KiddieTrac iOS app · '; }
      }
      if (type === 'Other') {                   // browser (no native shell)
        if (/Android/i.test(ua)) type = 'Android';
        else if (/iPhone/i.test(ua)) type = 'Apple iPhone';
        else if (/iPad/i.test(ua) || (/Macintosh/i.test(ua) && navigator.maxTouchPoints > 1)) type = 'Apple iPad';
        else if (/Windows|Macintosh|Linux|CrOS/i.test(ua)) type = 'Desktop / laptop';
      }
      return { type: type, detail: (native + ua).slice(0, 160) };
    } catch (e) { return { type: type, detail: native }; }
  }

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

  function removeOnbOverlay() {
    var el = document.getElementById('kt-onboarding-overlay');
    if (el && el.parentNode) el.parentNode.removeChild(el);
  }

  function buildWizard(container, user, r, whiteLabel) {
    var roleStep = roleStepConfig(r);
    COMMON_STEPS[2].title = roleStep.title;
    COMMON_STEPS[2].subtitle = roleStep.subtitle;

    // Render onboarding as a DIMMED MODAL POPOUT over the portal — the portal
    // must not be visible/usable behind the setup flow. We mount a fixed,
    // full-viewport overlay on <body> (position:fixed is reliable there, immune
    // to any transformed portal ancestor) and reassign `container` to it so all
    // the existing #kt-* queries + submit/validate keep working unchanged.
    try { container.innerHTML = ''; } catch (e) {}
    removeOnbOverlay();
    var overlay = document.createElement('div');
    overlay.id = 'kt-onboarding-overlay';
    overlay.style.cssText = 'position:fixed;inset:0;z-index:5000;background:rgba(8,15,30,0.74);'
      + '-webkit-backdrop-filter:blur(4px);backdrop-filter:blur(4px);overflow-y:auto;overflow-x:hidden;'
      + 'display:flex;align-items:flex-start;justify-content:center;padding:24px 14px;';
    document.body.appendChild(overlay);
    container = overlay;

    var steps = COMMON_STEPS.slice();
    if (r === 'guardian') {
      steps.push({
        id: 'children',
        title: 'About your children',
        subtitle: 'A photo so staff recognise them, and what the room needs to know to keep them safe.',
      });
    }
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
        sex:           user.sex || '',
        ethnicity:     (user.profile_extras && user.profile_extras.ethnicity) || '',
        preferred_name: user.preferred_name || '',
        phone:         user.phone || '',
        direct_phone:  (user.profile_extras && user.profile_extras.direct_phone) || '',
        home_phone:    (user.profile_extras && user.profile_extras.home_phone) || '',
        photo_url:     user.photo_url || '',
        username:      user.username || '',
        provider_bio:  user.provider_bio || '',
        // Preferred language → users.locale. Seed only if it's one of the offered
        // codes; the en-CA default won't match, so new users must pick one.
        locale:        (['en', 'fr', 'es', 'hi'].indexOf(user.locale) !== -1 ? user.locale : ''),
      },
      isProvider: !!user.is_provider,
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
          emergency_contact_email: pe.emergency_contact_email || '',
          emergency_contact_relation: pe.emergency_contact_relation || '',
        };
      })(),
      team: [],
      whitelabel: { brand_logo_url: '', brand_primary_color: '#1F6080', brand_support_email: user.email || '', brand_privacy_url: '', brand_terms_url: '' },
      agencyId: user.agency_id,
    };

    container.innerHTML =
      '<div style="max-width:640px;width:100%;margin:8px auto;">' +
        '<div style="background:linear-gradient(135deg,#081C41,#1F6080);color:white;border-radius:18px 18px 0 0;padding:28px 32px;">' +
          '<div style="font-size:11px;font-weight:800;letter-spacing:1.5px;text-transform:uppercase;opacity:0.85;margin-bottom:8px;">Welcome to Kiddietrac</div>' +
          '<h1 style="font-family:\'Baloo 2\',system-ui,sans-serif;font-size:28px;margin:0 0 4px;font-weight:800;letter-spacing:-0.5px;">Let\'s set up your account</h1>' +
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
      // Colourized progress: each completed step lights up in its own hue so the
      // bar reads as a cheerful multi-colour progression, not one flat colour.
      var palette = ['#EF4444', '#F59E0B', '#EAB308', '#10B981', '#06B6D4', '#3B82F6', '#8B5CF6', '#EC4899', '#F97316', '#14B8A6'];
      for (var i = 0; i < steps.length; i++) {
        var el = document.createElement('div');
        var done = i <= state.step;
        var col = done ? palette[i % palette.length] : '#E2E8F0';
        el.style.cssText = 'flex:1;height:7px;border-radius:4px;background:' + col + ';transition:background 220ms ease;'
          + (done ? 'box-shadow:0 1px 4px ' + col + '66;' : '');
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
      else if (id === 'children') body.appendChild(renderChildrenStep(state));
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
    return 'width:100%;padding:11px 14px;border:1.5px solid #E2E8F0;border-radius:10px;font-size:14px;box-sizing:border-box;background:#fff;';
  }

  // ── Phone as separate area code + number ─────────────────────────────
  function digits(s) { return (s == null ? '' : String(s)).replace(/[^0-9]/g, ''); }
  function fmtLocal(d) { d = digits(d); if (d.length >= 7) return d.slice(0, 3) + '-' + d.slice(3, 7); if (d.length > 3) return d.slice(0, 3) + '-' + d.slice(3); return d; }
  function splitPhone(v) {
    var d = digits(v);
    if (d.length === 11 && d[0] === '1') d = d.slice(1); // strip country code
    if (d.length >= 10) return { area: d.slice(0, 3), num: fmtLocal(d.slice(3, 10)) };
    if (d.length > 3)  return { area: d.slice(0, 3), num: fmtLocal(d.slice(3)) };
    return { area: '', num: (v || '') };
  }
  function combinePhone(area, num) {
    var a = digits(area), n = digits(num);
    if (!a && !n) return '';
    if (a && n) return '(' + a + ') ' + (n.length >= 7 ? n.slice(0, 3) + '-' + n.slice(3, 7) : n);
    return (a ? '(' + a + ') ' : '') + (num || '');
  }
  function phoneInput(label, key, value, required) {
    var p = splitPhone(value);
    var req = required ? ' data-req="1"' : '';
    var star = required ? ' <span style="color:#DC2626;font-weight:800;" title="Required">*</span>' : '';
    var wrap = document.createElement('div');
    wrap.style.cssText = 'margin-bottom:14px;';
    wrap.innerHTML =
      '<label style="display:block;font-size:13px;font-weight:700;color:#334155;margin-bottom:6px;">' + esc(label) + star + '</label>'
      + '<div style="display:grid;grid-template-columns:88px 1fr;gap:8px;">'
      +   '<input data-parea="' + esc(key) + '" inputmode="numeric" maxlength="3" placeholder="Area" value="' + esc(p.area) + '" style="' + inputStyle() + 'text-align:center;letter-spacing:1px;">'
      +   '<input data-pnum="' + esc(key) + '"' + req + ' inputmode="tel" placeholder="Phone number" value="' + esc(p.num) + '" style="' + inputStyle() + '">'
      + '</div>';
    return wrap;
  }

  // ── Camera capture (take a photo instead of uploading a file) ────────
  // Uses getUserMedia for a live capture on desktop AND mobile; if the browser
  // can't grant camera access it falls back to a native capture file input
  // (which opens the device camera on phones). Result is a Blob passed to `cb`.
  function openCamera(cb) {
    var nav = window.navigator || {};
    if (!(nav.mediaDevices && nav.mediaDevices.getUserMedia)) {
      // Fallback: native camera via a capture-enabled file input.
      var fi = document.createElement('input');
      fi.type = 'file'; fi.accept = 'image/*'; fi.setAttribute('capture', 'user'); fi.style.display = 'none';
      fi.addEventListener('change', function () { var f = fi.files && fi.files[0]; if (f) cb(f); });
      document.body.appendChild(fi); fi.click();
      setTimeout(function () { if (fi.parentNode) fi.parentNode.removeChild(fi); }, 60000);
      return;
    }
    var ov = document.createElement('div');
    ov.style.cssText = 'position:fixed;inset:0;z-index:6000;background:rgba(6,12,24,0.92);display:flex;flex-direction:column;align-items:center;justify-content:center;gap:16px;padding:20px;';
    ov.innerHTML =
      '<div style="color:#E2E8F0;font-size:14px;font-weight:700;">Center your face, then capture</div>'
      + '<video autoplay playsinline muted style="width:min(88vw,420px);aspect-ratio:1/1;object-fit:cover;border-radius:16px;background:#000;transform:scaleX(-1);"></video>'
      + '<div style="display:flex;gap:12px;">'
      +   '<button data-cam="shot" style="padding:12px 22px;background:#22D3EE;color:#062B36;border:none;border-radius:10px;font-weight:800;cursor:pointer;">📸 Capture</button>'
      +   '<button data-cam="cancel" style="padding:12px 18px;background:transparent;color:#CBD5E1;border:1.5px solid #475569;border-radius:10px;font-weight:700;cursor:pointer;">Cancel</button>'
      + '</div>';
    document.body.appendChild(ov);
    var video = ov.querySelector('video');
    var stream = null;
    function stop() { try { if (stream) stream.getTracks().forEach(function (t) { t.stop(); }); } catch (e) {} if (ov.parentNode) ov.parentNode.removeChild(ov); }
    nav.mediaDevices.getUserMedia({ video: { facingMode: 'user', width: { ideal: 1280 }, height: { ideal: 1280 } }, audio: false })
      .then(function (s) { stream = s; video.srcObject = s; })
      .catch(function () { stop(); alert('Could not access the camera. You can upload a photo instead.'); });
    ov.querySelector('[data-cam="cancel"]').addEventListener('click', stop);
    ov.querySelector('[data-cam="shot"]').addEventListener('click', function () {
      var vw = video.videoWidth || 720, vh = video.videoHeight || 720;
      var side = Math.min(vw, vh);
      var cv = document.createElement('canvas'); cv.width = side; cv.height = side;
      var cx = cv.getContext('2d');
      // mirror horizontally to match the on-screen preview, center-crop to square
      cx.translate(side, 0); cx.scale(-1, 1);
      cx.drawImage(video, (vw - side) / 2, (vh - side) / 2, side, side, 0, 0, side, side);
      cv.toBlob(function (blob) { stop(); if (blob) cb(blob); }, 'image/jpeg', 0.92);
    });
  }

  function renderProfileStep(state, container) {
    var box = document.createElement('div');

    // Avatar uploader — uploads immediately to /auth/me/avatar.
    var av = document.createElement('div');
    av.style.cssText = 'display:flex;align-items:center;gap:16px;margin-bottom:18px;';
    var prevBg = state.data.photo_url ? "background:#F1F5F9 url('" + absUrlO(state.data.photo_url) + "') center/cover no-repeat;" : 'background:#F1F5F9;';
    av.innerHTML =
      '<div id="kt-av-prev" style="width:72px;height:72px;border-radius:50%;' + prevBg + 'display:flex;align-items:center;justify-content:center;color:#64748B;font-size:26px;flex-shrink:0;border:2px solid #E2E8F0;">' + (state.data.photo_url ? '' : '👤') + '</div>' +
      '<div><div style="display:flex;gap:8px;flex-wrap:wrap;">' +
        '<button type="button" id="kt-av-btn" style="background:white;color:#1F6080;border:1.5px solid #1F6080;padding:8px 14px;border-radius:9px;font-weight:600;cursor:pointer;font-size:13px;">Upload photo</button>' +
        '<button type="button" id="kt-av-cam" style="background:white;color:#1F6080;border:1.5px solid #1F6080;padding:8px 14px;border-radius:9px;font-weight:600;cursor:pointer;font-size:13px;">📷 Take photo</button>' +
        '</div>' +
        '<input id="kt-av-file" type="file" accept="image/png,image/jpeg,image/webp" style="display:none;">' +
        '<div id="kt-av-msg" style="font-size:11px;color:#64748B;margin-top:5px;">Required · upload one or take a photo now</div></div>';
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
    // Sex — mandatory. Drives the default avatar when no photo is uploaded.
    box.appendChild(input('Sex', 'sex', state.data.sex, 'select', null, ['Male', 'Female'], true));
    // Preferred language — mandatory. Sets the app locale (users.locale) so the
    // portal + emails speak the user's language from the first sign-in.
    var LANGS_ONB = [['en', 'English'], ['fr', 'Français'], ['es', 'Español'], ['hi', 'हिन्दी']];
    var langWrap = document.createElement('div');
    langWrap.style.cssText = 'margin-bottom:14px;';
    langWrap.innerHTML = '<label style="display:block;font-size:13px;font-weight:700;color:#334155;margin-bottom:6px;">Preferred language <span style="color:#DC2626;font-weight:800;" title="Required">*</span></label>'
      + '<select data-k="locale" data-req="1" style="' + inputStyle() + 'background:white;">'
      + '<option value="">Select…</option>'
      + LANGS_ONB.map(function (l) { return '<option value="' + l[0] + '"' + (state.data.locale === l[0] ? ' selected' : '') + '>' + l[1] + '</option>'; }).join('')
      + '</select>';
    box.appendChild(langWrap);
    // Race / ethnicity — optional + self-reported (analytics only). Lands in
    // state.data.ethnicity → profile_extras on submit.
    box.appendChild(input('Race / ethnicity (optional)', 'ethnicity', state.data.ethnicity, 'select', null,
      ['White', 'Black', 'South Asian', 'Chinese', 'Filipino', 'Latin American', 'Arab',
       'Southeast Asian', 'West Asian', 'Korean', 'Japanese',
       'Indigenous (First Nations, Métis, Inuit)', 'Mixed / multiple', 'Other', 'Prefer not to say'], false));
    box.appendChild(input('Preferred name (optional, what we call you)', 'preferred_name', state.data.preferred_name));
    box.appendChild(phoneInput('Mobile phone', 'phone', state.data.phone, true));
    var phRow = document.createElement('div');
    phRow.style.cssText = 'display:grid;grid-template-columns:1fr 1fr;gap:12px;';
    phRow.appendChild(phoneInput('Direct phone (optional)', 'direct_phone', state.data.direct_phone));
    phRow.appendChild(phoneInput('Home phone (optional)', 'home_phone', state.data.home_phone));
    box.appendChild(phRow);
    // Optional username — lets one person keep several accounts under one email
    // and sign in to the right one. Letters, numbers, and . _ - only.
    box.appendChild(input('Username (optional — sign in with this if you share an email across accounts)', 'username', state.data.username, 'text'));

    setTimeout(function () {
      var fileEl = box.querySelector('#kt-av-file');
      var msg = box.querySelector('#kt-av-msg');
      // Run any chosen/captured image through the cropper (reposition/zoom to fill
      // the circle) then upload the cropped result.
      function handleImage(f) {
        if (!f) return;
        if (f.size && f.size > 20 * 1024 * 1024) { msg.textContent = 'Image too large (max 20 MB)'; msg.style.color = '#DC2626'; return; }
        if (window.KT && KT.AvatarCropper) {
          KT.AvatarCropper.open(f, function (blob) { fileEl.value = ''; if (blob) uploadAvatar(blob); });
        } else {
          uploadAvatar(f);
        }
      }
      box.querySelector('#kt-av-btn').addEventListener('click', function () { fileEl.click(); });
      var camBtn = box.querySelector('#kt-av-cam');
      if (camBtn) camBtn.addEventListener('click', function () { openCamera(handleImage); });
      fileEl.addEventListener('change', function () { handleImage(fileEl.files[0]); });
      function uploadAvatar(blob) {
        msg.textContent = 'Uploading…'; msg.style.color = '#64748B';
        var fd = new FormData(); fd.append('avatar', blob, 'avatar.jpg');
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
      }
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
    // Primary colour — pick from the colour WHEEL or type a hex; the two stay in
    // sync. The wheel input carries data-k so it's the value that gets collected.
    var colorRow = document.createElement('div');
    colorRow.style.cssText = 'margin-bottom:14px;';
    colorRow.innerHTML = '<label style="display:block;font-size:13px;font-weight:700;color:#334155;margin-bottom:6px;">Primary colour</label>'
      + '<div style="display:flex;align-items:center;gap:10px;">'
      + '<input id="kt-wl-wheel" data-k="brand_primary_color" type="color" value="' + esc(wl.brand_primary_color || '#1F6080') + '" title="Pick from the colour wheel" style="width:54px;height:42px;border:1.5px solid #E2E8F0;border-radius:10px;padding:3px;cursor:pointer;background:#fff;">'
      + '<input id="kt-wl-hex" type="text" value="' + esc(wl.brand_primary_color || '#1F6080') + '" placeholder="#1F6080" maxlength="7" style="' + inputStyle() + 'max-width:140px;font-family:monospace;text-transform:lowercase;">'
      + '<span id="kt-wl-swatch" style="width:30px;height:30px;border-radius:50%;border:1px solid #E2E8F0;flex-shrink:0;background:' + esc(wl.brand_primary_color || '#1F6080') + ';"></span>'
      + '</div>';
    box.appendChild(colorRow);
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

      // Keep the colour wheel, hex box and swatch in sync.
      var wheel = box.querySelector('#kt-wl-wheel');
      var hex = box.querySelector('#kt-wl-hex');
      var sw = box.querySelector('#kt-wl-swatch');
      if (wheel && hex && sw) {
        wheel.addEventListener('input', function () { hex.value = wheel.value.toLowerCase(); sw.style.background = wheel.value; });
        hex.addEventListener('input', function () {
          var v = hex.value.trim(); if (v && v[0] !== '#') v = '#' + v;
          if (/^#[0-9a-fA-F]{6}$/.test(v)) { wheel.value = v; sw.style.background = v; }
        });
      }
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

    // Emergency contact — a titled card. Must be someone OTHER than the user
    // (validated on Next and again server-side).
    var ec = document.createElement('div');
    ec.style.cssText = 'margin-top:14px;padding:16px 16px 4px;border:1px solid #E2E8F0;border-radius:12px;background:#F8FAFC;';
    var ecHead = document.createElement('div');
    ecHead.innerHTML = '<div style="font-size:12px;font-weight:800;color:#334155;letter-spacing:.4px;text-transform:uppercase;margin-bottom:3px;">Emergency contact</div>'
      + '<div style="font-size:12px;color:#64748B;margin-bottom:12px;">Someone <strong>other than yourself</strong> we can reach in an emergency.</div>';
    ec.appendChild(ecHead);
    ec.appendChild(input('Full name', 'emergency_contact_name', state.address.emergency_contact_name));
    var ecRow = document.createElement('div');
    ecRow.style.cssText = 'display:grid;grid-template-columns:1fr 1fr;gap:12px;';
    ecRow.appendChild(input('Relationship', 'emergency_contact_relation', state.address.emergency_contact_relation, 'text', 'e.g. spouse, parent'));
    ecRow.appendChild(input('Email', 'emergency_contact_email', state.address.emergency_contact_email, 'email', 'name@example.com'));
    ec.appendChild(ecRow);
    ec.appendChild(phoneInput('Phone', 'emergency_contact_phone', state.address.emergency_contact_phone));
    box.appendChild(ec);

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
        + '<button type="button" class="kt-ec-del" title="Remove" style="height:44px;width:40px;border:1px solid #E2E8F0;background:#fff;color:#B91C1C;border-radius:10px;font-size:16px;cursor:pointer;">✕</button>'
        + '<div style="grid-column:1 / -1;"><label style="display:block;font-size:12.5px;font-weight:700;color:#334155;margin-bottom:5px;">Email (optional)</label>'
        + '<input data-ec="email" type="email" value="' + esc((preset && preset.email) || '') + '" placeholder="name@example.com" style="' + inputStyle() + '"></div>';
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
    // Home-daycare providers must introduce themselves to families. This bio
    // shows on the family's provider card + the provider welcome email, so it's
    // mandatory (min ~a sentence or two) and leads the role step.
    if (state.isProvider) {
      var bioWrap = document.createElement('div');
      bioWrap.style.cssText = 'margin-bottom:18px;padding:16px;border:1px solid #CFE8EE;border-radius:12px;background:linear-gradient(135deg,#F2FBFD,#F8FAFC);';
      bioWrap.innerHTML =
        '<div style="font-size:13px;font-weight:800;color:#0F172A;margin-bottom:3px;">Your bio <span style="color:#DC2626;">*</span></div>'
        + '<div style="font-size:12px;color:#64748B;margin-bottom:10px;">Introduce yourself to the families in your care — your experience, your approach, what makes your home special. This appears on each family’s provider card and their welcome email.</div>'
        + '<textarea data-bio="1" rows="5" placeholder="e.g. Hi, I’m Chearstine! I’ve run a licensed home daycare for 8 years and love nature walks, music, and cozy story time. Your little one will feel right at home…" style="' + inputStyle() + 'font-family:inherit;resize:vertical;">' + esc(state.data.provider_bio || '') + '</textarea>'
        + '<div id="kt-bio-count" style="font-size:11px;color:#94A3B8;margin-top:5px;text-align:right;"></div>';
      box.appendChild(bioWrap);
      setTimeout(function () {
        var ta = bioWrap.querySelector('[data-bio]');
        var ct = bioWrap.querySelector('#kt-bio-count');
        function upd() { var n = (ta.value || '').trim().length; ct.textContent = n + ' characters' + (n < 40 ? ' · at least 40' : ' ✓'); ct.style.color = n < 40 ? '#B45309' : '#16A34A'; }
        ta.addEventListener('input', upd); upd();
      }, 0);
    }
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
    // Split phone fields (area code + number) → combine into the single stored value.
    body.querySelectorAll('[data-pnum]').forEach(function (numEl) {
      var key = numEl.getAttribute('data-pnum');
      var areaEl = body.querySelector('[data-parea="' + key + '"]');
      var combined = combinePhone(areaEl ? areaEl.value : '', numEl.value);
      if (id === 'profile') state.data[key] = combined;
      else if (id === 'address') state.address[key] = combined;
    });
    // Provider bio lives on the role step (its own attribute so it doesn't land
    // in role_extras) → store on state.data.provider_bio.
    if (id === 'role') {
      var bioEl = body.querySelector('[data-bio]');
      if (bioEl) state.data.provider_bio = bioEl.value;
    }
    if (id === 'team') collectTeam(state, body);
    if (id === 'children') collectChildren(state, body);
    if (id === 'address') {
      var extras = [];
      body.querySelectorAll('.kt-ec-row').forEach(function (row) {
        var n = row.querySelector('[data-ec="name"]'); var p = row.querySelector('[data-ec="phone"]'); var em = row.querySelector('[data-ec="email"]');
        var nm = n ? n.value.trim() : ''; var ph = p ? p.value.trim() : ''; var mail = em ? em.value.trim() : '';
        if (nm || ph || mail) extras.push({ name: nm, phone: ph, email: mail });
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

  /* ── Children (guardian) ─────────────────────────────────────────────
     One card per child: a photo, what they are allergic to, and which of the
     agency's expected immunisations they have had. Saved per child when the step
     is left, so a parent who gets three children in never loses the first two.

     The vaccines offered come from the agency's own schedule rather than a free
     text box, so what a parent enters is something the immunisation report can
     measure rather than prose somebody has to read. */
  function renderChildrenStep(state) {
    var wrap = document.createElement('div');
    wrap.innerHTML = '<div style="color:#64748B;font-size:13px;padding:8px 0;">Loading your children…</div>';

    state.kids = state.kids || { list: [], schedule: [] };

    api('GET', '/parent/onboarding/children').then(function (res) {
      state.kids.list = (res && res.children) || [];
      state.kids.schedule = (res && res.schedule) || [];
      paint();
    }).catch(function () {
      wrap.innerHTML = '<div style="color:#64748B;font-size:13px;padding:8px 0;">'
        + 'We could not load your children just now. You can add these details later '
        + 'from your child\'s profile.</div>';
    });

    function paint() {
      if (!state.kids.list.length) {
        wrap.innerHTML = '<div style="color:#64748B;font-size:13px;padding:8px 0;">'
          + 'No children are linked to your family yet. Your centre will add them, '
          + 'and you can fill this in from their profile afterwards.</div>';
        return;
      }
      wrap.innerHTML = '';
      state.kids.list.forEach(function (kid, i) { wrap.appendChild(card(kid, i)); });
    }

    function card(kid, idx) {
      var box = document.createElement('div');
      box.style.cssText = 'border:1px solid #E2E8F0;border-radius:14px;padding:14px;margin-bottom:14px;background:#fff;';

      var photo = kid.photo_url
        ? '<img src="' + kid.photo_url + '" alt="" style="width:64px;height:64px;border-radius:50%;object-fit:cover;">'
        : '<div style="width:64px;height:64px;border-radius:50%;background:#F1F5F9;display:flex;align-items:center;'
          + 'justify-content:center;font-size:26px;color:#94A3B8;">🧒</div>';

      box.innerHTML =
        '<div style="display:flex;gap:14px;align-items:center;">'
        +   '<div data-kid-photo>' + photo + '</div>'
        +   '<div style="min-width:0;flex:1;">'
        +     '<div style="font-weight:800;font-size:15px;color:#0F172A;">' + esc(kid.name) + '</div>'
        +     '<button type="button" data-kid-upload style="margin-top:6px;background:#1F6080;color:#fff;border:0;'
        +       'border-radius:8px;padding:7px 14px;font-size:12.5px;font-weight:700;cursor:pointer;">'
        +       (kid.photo_url ? 'Change photo' : 'Add photo') + '</button>'
        +     '<input type="file" data-kid-file accept="image/png,image/jpeg,image/webp" style="display:none;">'
        +     '<div data-kid-msg style="font-size:11.5px;margin-top:5px;color:'
        +       (kid.photo_url ? '#1E8E60' : '#DC2626') + ';">'
        +       (kid.photo_url ? 'Photo added' : 'Required — staff use this to recognise them') + '</div>'
        +   '</div>'
        + '</div>'
        + '<label style="display:block;font-size:12.5px;font-weight:700;color:#334155;margin:14px 0 4px;">Allergies</label>'
        + '<input data-kid-allergies value="' + esc((kid.allergies || []).join(', ')) + '"'
        +   ' placeholder="e.g. Peanuts, Dairy — separate with commas"'
        +   ' style="width:100%;box-sizing:border-box;padding:9px 11px;border:1px solid #E2E8F0;border-radius:9px;font-size:13px;">'
        + '<label style="display:block;font-size:12.5px;font-weight:700;color:#334155;margin:12px 0 4px;">Dietary needs</label>'
        + '<input data-kid-diet value="' + esc((kid.dietary_restrictions || []).join(', ')) + '"'
        +   ' placeholder="e.g. Vegetarian, No pork"'
        +   ' style="width:100%;box-sizing:border-box;padding:9px 11px;border:1px solid #E2E8F0;border-radius:9px;font-size:13px;">'
        + '<label style="display:block;font-size:12.5px;font-weight:700;color:#334155;margin:12px 0 4px;">Anything else the room should know</label>'
        + '<textarea data-kid-notes rows="2" placeholder="Medication, a condition to watch for, how they settle…"'
        +   ' style="width:100%;box-sizing:border-box;padding:9px 11px;border:1px solid #E2E8F0;border-radius:9px;font-size:13px;">'
        +   esc(kid.medical_notes || '') + '</textarea>'
        + shotsHtml(kid);

      // Photo upload, straight away — so a parent who abandons the wizard has still
      // given the room a face to match to the child.
      var btn = box.querySelector('[data-kid-upload]');
      var file = box.querySelector('[data-kid-file]');
      var note = box.querySelector('[data-kid-msg]');
      btn.addEventListener('click', function () { file.click(); });
      file.addEventListener('change', function () {
        var f = file.files && file.files[0];
        if (!f) { return; }
        note.style.color = '#64748B';
        note.textContent = 'Uploading…';
        var fd = new FormData();
        fd.append('photo', f, f.name || 'photo.jpg');
        fetch(apiBase() + '/parent/onboarding/children/' + kid.id + '/photo', {
          method: 'POST',
          headers: { Authorization: 'Bearer ' + token() },
          body: fd,
        }).then(function (r) { return r.ok ? r.json() : r.json().then(function (j) { throw new Error(j.message || 'Upload failed'); }); })
          .then(function (j) {
            kid.photo_url = j.photo_url;
            state.kids.list[idx] = kid;
            note.style.color = '#1E8E60';
            note.textContent = 'Photo added';
            var holder = box.querySelector('[data-kid-photo]');
            if (holder) {
              holder.innerHTML = '<img src="' + kid.photo_url + '" alt="" style="width:64px;height:64px;'
                + 'border-radius:50%;object-fit:cover;">';
            }
            btn.textContent = 'Change photo';
          })
          .catch(function (e) {
            note.style.color = '#DC2626';
            note.textContent = (e && e.message) || 'Could not upload that photo';
          });
      });

      return box;
    }

    function shotsHtml(kid) {
      var sched = state.kids.schedule || [];
      if (!sched.length) { return ''; }
      var had = {};
      (kid.immunizations || []).forEach(function (s) {
        had[(s.vaccine || '') + '|' + (s.dose_label || '')] = s.administered_on || '';
      });
      var rows = sched.map(function (v, i) {
        var key = (v.vaccine || '') + '|' + (v.dose_label || '');
        var on = had[key] || '';
        return '<div style="display:flex;align-items:center;gap:8px;padding:5px 0;">'
          + '<div style="flex:1;min-width:0;font-size:12.5px;color:#334155;">' + esc(v.vaccine)
          +   (v.dose_label ? ' <span style="color:#94A3B8;">' + esc(v.dose_label) + '</span>' : '') + '</div>'
          + '<input type="date" data-shot="' + esc(key) + '" value="' + esc(on) + '"'
          +   ' style="padding:5px 8px;border:1px solid #E2E8F0;border-radius:8px;font-size:12px;">'
          + '</div>';
      }).join('');
      return '<div style="margin-top:14px;border-top:1px solid #F1F5F9;padding-top:10px;">'
        + '<div style="font-size:12.5px;font-weight:700;color:#334155;">Immunisations</div>'
        + '<div style="font-size:11.5px;color:#64748B;margin-bottom:6px;">'
        + 'Add the date for any your child has had. Leave the rest blank — you can fill them in later.</div>'
        + rows + '</div>';
    }

    return wrap;
  }

  /* Save every child card. Called when the children step is left. */
  function collectChildren(state, body) {
    var kids = (state.kids && state.kids.list) || [];
    var cards = body.querySelectorAll('[data-kid-allergies]');
    var list = [];

    cards.forEach(function (allergyEl, i) {
      var kid = kids[i];
      if (!kid) { return; }
      var card = allergyEl.closest('div');
      while (card && !card.querySelector('[data-kid-upload]')) { card = card.parentElement; }
      if (!card) { return; }

      var split = function (v) {
        return String(v || '').split(',').map(function (x) { return x.trim(); })
          .filter(function (x) { return x !== ''; });
      };
      var shots = [];
      card.querySelectorAll('[data-shot]').forEach(function (d) {
        if (!d.value) { return; }
        var parts = d.getAttribute('data-shot').split('|');
        shots.push({ vaccine: parts[0], dose_label: parts[1] || null, administered_on: d.value });
      });

      list.push({
        id: kid.id,
        allergies: split(allergyEl.value),
        dietary_restrictions: split((card.querySelector('[data-kid-diet]') || {}).value),
        medical_notes: ((card.querySelector('[data-kid-notes]') || {}).value || '').trim() || null,
        immunizations: shots,
      });
    });

    // Saved per child rather than in one request: a parent with three children who
    // loses connection halfway keeps the two that got through.
    list.forEach(function (payload) {
      api('POST', '/parent/onboarding/children/' + payload.id, payload).catch(function () {});
    });
  }
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
    // Every child needs a photo before this step will advance. Same rule as the
    // adult's own profile photo above, and for the same reason: a roster of grey
    // silhouettes helps nobody recognise a child at the door.
    if (id === 'children') {
      var kids = (state.kids && state.kids.list) || [];
      var noPhoto = kids.filter(function (k) { return !k.photo_url; });
      if (noPhoto.length) {
        msg.style.color = '#DC2626';
        msg.textContent = noPhoto.length === 1
          ? 'Please add a photo of ' + noPhoto[0].name + '.'
          : 'Please add a photo of each child: ' + noPhoto.map(function (k) { return k.name; }).join(', ') + '.';
        return false;
      }
    }
    if (id === 'profile' && !state.data.photo_url) {
      missing.push('Profile photo');
      var avMsg = body.querySelector('#kt-av-msg');
      if (avMsg) { avMsg.textContent = 'Profile photo is required'; avMsg.style.color = '#DC2626'; }
    }
    // Providers must write a real bio (≥ 40 chars) on the role step.
    if (id === 'role' && state.isProvider) {
      var bioEl2 = body.querySelector('[data-bio]');
      var bioVal = bioEl2 ? (bioEl2.value || '').trim() : '';
      if (bioVal.length < 40) {
        if (bioEl2) { bioEl2.style.borderColor = '#DC2626'; if (!firstBad) firstBad = bioEl2; }
        msg.style.color = '#DC2626';
        msg.textContent = bioVal.length === 0
          ? 'Please write a short bio so families know who will be caring for their child.'
          : 'Your bio is a little short — add a sentence or two more (at least 40 characters).';
        if (firstBad && firstBad.focus) try { firstBad.focus(); } catch (e) {}
        return false;
      }
    }
    if (missing.length) {
      msg.style.color = '#DC2626';
      msg.textContent = 'Please complete: ' + missing.join(', ') + '.';
      if (firstBad && firstBad.focus) try { firstBad.focus(); } catch (e) {}
      return false;
    }
    // A person can't be their own emergency contact — compare the entered email,
    // name or phone against the signed-in user's own details.
    if (id === 'address') {
      var u = getUser();
      var ownEmail = (u.email || '').trim().toLowerCase();
      var ownName = ((u.first_name || '') + ' ' + (u.last_name || '')).trim().toLowerCase().replace(/\s+/g, ' ');
      var ownPhone = digits(u.phone);
      var ecEmailEl = body.querySelector('[data-k="emergency_contact_email"]');
      var ecNameEl = body.querySelector('[data-k="emergency_contact_name"]');
      var ecEmail = ecEmailEl ? ecEmailEl.value.trim().toLowerCase() : '';
      var ecName = ecNameEl ? ecNameEl.value.trim().toLowerCase().replace(/\s+/g, ' ') : '';
      var areaEl = body.querySelector('[data-parea="emergency_contact_phone"]');
      var numEl = body.querySelector('[data-pnum="emergency_contact_phone"]');
      var ecPhone = digits((areaEl ? areaEl.value : '') + (numEl ? numEl.value : ''));
      var clashes = (ecEmail && ecEmail === ownEmail)
        || (ecName && ownName && ecName === ownName)
        || (ecPhone && ownPhone && ecPhone.length >= 10 && ecPhone === ownPhone);
      if (clashes) {
        msg.style.color = '#DC2626';
        msg.textContent = 'Your emergency contact can’t be yourself — please enter someone else who can be reached in an emergency.';
        var bad = ecEmailEl || ecNameEl; if (bad && bad.focus) try { bad.focus(); } catch (e) {}
        return false;
      }
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
    if (!state.data.sex) {
      msg.textContent = 'Please select your sex.'; msg.style.color = '#DC2626';
      btn.disabled = false; btn.textContent = 'Save and continue';
      state.step = 0;
      var evsx = new CustomEvent('redraw'); container.dispatchEvent(evsx);
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

    // Auto-detect the device the user is onboarding on (analytics only — no UI).
    // Prefers Capacitor's native platform (the APK) then falls back to UA sniffing.
    var dev = detectDevice();
    var payload = Object.assign({}, state.data, state.address, {
      role_extras: state.role_extras,
      device_type: dev.type,
      device_detail: dev.detail,
      complete: true,
    });
    try {
      var fresh = await api('PATCH', '/auth/me/onboarding', payload);
      setUser(fresh);
      // Apply the chosen language immediately (kt-i18n reads localStorage first),
      // mirroring the Settings language picker, so the portal loads translated.
      try { if (state.data.locale) localStorage.setItem('kt_locale', state.data.locale); } catch (e) {}
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
      setTimeout(function () { removeOnbOverlay(); window.location.hash = '#' + landing; }, 800);
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
            removeOnbOverlay();
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
