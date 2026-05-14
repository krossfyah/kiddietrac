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

  // ─── Field config per role ───────────────────────────────────────────
  var COMMON_STEPS = [
    { id: 'profile', title: 'Your profile', subtitle: 'Confirm the basics so people know who they are messaging.' },
    { id: 'address', title: 'Address & emergency contact', subtitle: 'Where you are and who to reach in an emergency.' },
    { id: 'role',    title: '',           subtitle: '' }, // populated per-role
  ];

  function roleStepConfig(r) {
    if (r === 'agency_admin') {
      return {
        title: 'About your organization',
        subtitle: 'Quick details about your agency for billing and compliance.',
        fields: [
          { k: 'business_name',                label: 'Business / legal entity name', type: 'text', placeholder: 'KiddieTrac Inc.' },
          { k: 'business_registration_number', label: 'Business number (HST / CRA)',  type: 'text', placeholder: '123456789RT0001' },
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
  function render(container, ctx) {
    var user = (ctx && ctx.user) || getUser();
    var r = role();
    var roleStep = roleStepConfig(r);
    COMMON_STEPS[2].title = roleStep.title;
    COMMON_STEPS[2].subtitle = roleStep.subtitle;

    var state = {
      step: 0,
      data: {
        first_name:    user.first_name || '',
        last_name:     user.last_name || '',
        preferred_name: user.preferred_name || '',
        phone:         user.phone || '',
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
            '<div style="font-size:12px;color:#94A3B8;">Step <span id="kt-step-n">1</span> of ' + COMMON_STEPS.length + '</div>' +
            '<button id="kt-next" type="button" style="padding:10px 24px;background:linear-gradient(135deg,#081C41,#1F6080);color:white;border:none;border-radius:10px;font-weight:700;cursor:pointer;">Next</button>' +
          '</div>' +
        '</div>' +
      '</div>';

    function drawBar() {
      var bar = container.querySelector('#kt-step-bar');
      bar.innerHTML = '';
      for (var i = 0; i < COMMON_STEPS.length; i++) {
        var el = document.createElement('div');
        el.style.cssText = 'flex:1;height:6px;border-radius:3px;background:' + (i <= state.step ? '#1F6080' : '#E2E8F0') + ';transition:background 200ms ease;';
        bar.appendChild(el);
      }
      container.querySelector('#kt-step-n').textContent = String(state.step + 1);
    }

    function drawTitle() {
      var s = COMMON_STEPS[state.step];
      container.querySelector('#kt-step-title').innerHTML =
        '<div style="font-size:22px;font-weight:800;color:#0F172A;margin-bottom:4px;font-family:\'Baloo 2\',system-ui,sans-serif;">' + esc(s.title) + '</div>' +
        '<div style="font-size:14px;color:#64748B;">' + esc(s.subtitle) + '</div>';
    }

    function drawStep() {
      drawBar();
      drawTitle();
      var body = container.querySelector('#kt-step-body');
      body.innerHTML = '';
      if (state.step === 0) body.appendChild(renderProfileStep(state));
      else if (state.step === 1) body.appendChild(renderAddressStep(state));
      else if (state.step === 2) body.appendChild(renderRoleStep(state, roleStep));

      container.querySelector('#kt-back').style.visibility = state.step === 0 ? 'hidden' : 'visible';
      container.querySelector('#kt-next').textContent = state.step === COMMON_STEPS.length - 1 ? 'Save and continue' : 'Next';
    }

    container.querySelector('#kt-back').addEventListener('click', function () {
      if (state.step > 0) { state.step--; drawStep(); }
    });
    container.querySelector('#kt-next').addEventListener('click', async function () {
      collectCurrentStep(state, container);
      if (state.step < COMMON_STEPS.length - 1) {
        state.step++;
        drawStep();
      } else {
        await submit(state, container);
      }
    });

    drawStep();
  }

  function input(label, key, value, type, placeholder, options) {
    type = type || 'text';
    var html;
    if (type === 'textarea') {
      html = '<textarea data-k="' + esc(key) + '" rows="3" placeholder="' + esc(placeholder || '') + '" style="' + inputStyle() + 'font-family:inherit;resize:vertical;">' + esc(value || '') + '</textarea>';
    } else if (type === 'select') {
      html = '<select data-k="' + esc(key) + '" style="' + inputStyle() + 'background:white;">' +
        '<option value="">Select…</option>' +
        (options || []).map(function (o) {
          return '<option value="' + esc(o) + '"' + (value === o ? ' selected' : '') + '>' + esc(o) + '</option>';
        }).join('') +
      '</select>';
    } else {
      html = '<input data-k="' + esc(key) + '" type="' + esc(type) + '" placeholder="' + esc(placeholder || '') + '" value="' + esc(value || '') + '" style="' + inputStyle() + '">';
    }
    var wrap = document.createElement('div');
    wrap.style.cssText = 'margin-bottom:14px;';
    wrap.innerHTML = '<label style="display:block;font-size:13px;font-weight:700;color:#334155;margin-bottom:6px;">' + esc(label) + '</label>' + html;
    return wrap;
  }
  function inputStyle() {
    return 'width:100%;padding:11px 14px;border:1.5px solid #E2E8F0;border-radius:10px;font-size:14px;box-sizing:border-box;';
  }

  function renderProfileStep(state) {
    var box = document.createElement('div');
    var row = document.createElement('div');
    row.style.cssText = 'display:grid;grid-template-columns:1fr 1fr;gap:12px;';
    row.appendChild(input('First name',    'first_name',     state.data.first_name));
    row.appendChild(input('Last name',     'last_name',      state.data.last_name));
    box.appendChild(row);
    box.appendChild(input('Preferred name (optional, what we call you)', 'preferred_name', state.data.preferred_name));
    box.appendChild(input('Phone (optional)', 'phone', state.data.phone, 'tel'));
    return box;
  }
  function renderAddressStep(state) {
    var box = document.createElement('div');
    box.appendChild(input('Street address',  'address_line1', state.address.address_line1));
    box.appendChild(input('Apt / unit (optional)', 'address_line2', state.address.address_line2));
    var row = document.createElement('div');
    row.style.cssText = 'display:grid;grid-template-columns:2fr 1fr 1fr;gap:12px;';
    row.appendChild(input('City', 'city', state.address.city));
    row.appendChild(input('Province', 'province', state.address.province, 'select', null,
      ['ON','QC','BC','AB','MB','SK','NS','NB','NL','PE','NT','NU','YT']));
    row.appendChild(input('Postal code', 'postal_code', state.address.postal_code, 'text', 'M5G 1Z4'));
    box.appendChild(row);
    var row2 = document.createElement('div');
    row2.style.cssText = 'display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-top:6px;';
    row2.appendChild(input('Emergency contact name', 'emergency_contact_name', state.address.emergency_contact_name));
    row2.appendChild(input('Emergency contact phone', 'emergency_contact_phone', state.address.emergency_contact_phone, 'tel'));
    box.appendChild(row2);
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
    var body = container.querySelector('#kt-step-body');
    body.querySelectorAll('[data-k]').forEach(function (el) {
      var k = el.dataset.k;
      var v = el.value;
      if (state.step === 0) state.data[k] = v;
      else if (state.step === 1) state.address[k] = v;
      else if (state.step === 2) state.role_extras[k] = v;
    });
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

    var payload = Object.assign({}, state.data, state.address, { role_extras: state.role_extras, complete: true });
    try {
      var fresh = await api('PATCH', '/auth/me/onboarding', payload);
      setUser(fresh);
      // Done! Redirect to the role's dashboard. Hashchange will trigger the
      // shell to render the proper screen.
      msg.textContent = '✓ All set — taking you to your dashboard…';
      msg.style.color = '#16A34A';
      setTimeout(function () { window.location.hash = '#dashboard'; }, 600);
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
  function maybeAutoTrigger() {
    var u = getUser();
    if (!u || !u.id) return;
    if (u.onboarded_at) return;
    var hash = (window.location.hash || '').replace('#', '').split('?')[0];
    // Allow the auto-trigger only when the user hasn't deep-linked anywhere
    // specific (empty hash or default dashboard).
    if (hash && hash !== 'dashboard') return;
    window.location.hash = '#onboarding';
  }

  // Register screen for every role
  if (Shell && Shell.registerScreen) {
    ['agency_admin', 'centre_director', 'educator', 'guardian', 'auditor'].forEach(function (r) {
      Shell.registerScreen(r + ':onboarding', render);
    });
  }
  window.KT = window.KT || {};
  window.KT.Onboarding = { render: render, maybeAutoTrigger: maybeAutoTrigger };

  // Eager check at script-eval time (before Shell.startApp runs).
  maybeAutoTrigger();
})(window);
