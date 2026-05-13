/* ═══════════════════════════════════════════════════════════════════
   KIDDIETRAC v5a — Director Self-Service Forms
   Add Room · Add Family · Add Child · Invite Parent · Invite Staff
   Loads AFTER screen-director.js; exposes modals via window.KT.*
   ═══════════════════════════════════════════════════════════════════ */

(function (window) {
  'use strict';

  const { Api, Dom, Shell } = window.KT;
  // v14: Modal-class adapter — wraps Shell.Modal.open to provide the class-style
  // API that screen-director-v5a was written against:
  //   const m = new Modal('Title'); m.body.appendChild(form); m.close();
  class Modal {
    constructor(title) {
      this._title = title;
      this._body = Dom.el('div');
      this.body = this._body;
      // Defer open until body is populated — caller appends to .body synchronously then we open on next tick
      const self = this;
      setTimeout(() => {
        if (!self._opened) {
          Shell.Modal.open({
            title: self._title,
            body: self._body,
            onClose: () => { self._opened = false; }
          });
          self._opened = true;
        }
      }, 0);
    }
    close() {
      // Shell.Modal.open closes by clearing #modalRoot; we trigger that
      const root = Dom.$('#modalRoot');
      if (root) Dom.clear(root);
      this._opened = false;
    }
  }

  // ──────────────────────────────────────────────────────────────
  // ADD ROOM
  // ──────────────────────────────────────────────────────────────
  function showAddRoomModal(onSuccess) {
    const modal = new Modal('Add a new room');
    const form = Dom.el('form');

    form.appendChild(field('Room name', 'name', { type: 'text', required: true, placeholder: 'e.g. "Sunshine Room"', maxlength: 120 }));

    const ageRow = Dom.el('div', { style: 'display: grid; grid-template-columns: 1fr 1fr; gap: 12px;' });
    ageRow.appendChild(selectField('Age group', 'age_group', [
      ['infant', 'Infant'], ['toddler', 'Toddler'], ['preschool', 'Preschool'], ['kindergarten', 'Kindergarten'], ['school_age', 'School Age']
    ], true));
    ageRow.appendChild(field('Capacity', 'capacity', { type: 'number', min: 1, max: 80, required: true, placeholder: '10' }));
    form.appendChild(ageRow);

    const monthRow = Dom.el('div', { style: 'display: grid; grid-template-columns: 1fr 1fr; gap: 12px;' });
    monthRow.appendChild(field('Min age (months)', 'age_min_months', { type: 'number', min: 0, max: 144, required: true, placeholder: '0' }));
    monthRow.appendChild(field('Max age (months)', 'age_max_months', { type: 'number', min: 0, max: 144, required: true, placeholder: '18' }));
    form.appendChild(monthRow);

    const ratioRow = Dom.el('div', { style: 'display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 12px;' });
    ratioRow.appendChild(field('Educators', 'ratio_educators', { type: 'number', min: 1, max: 10, value: 1 }));
    ratioRow.appendChild(field('Per N children', 'ratio_children', { type: 'number', min: 1, max: 40, required: true, placeholder: '3' }));
    ratioRow.appendChild(field('Color', 'color_hex', { type: 'color', value: '#8EC73C' }));
    form.appendChild(ratioRow);

    form.appendChild(Dom.el('p', { style: 'font-size: 12px; color: var(--ink-500); margin-top: 4px;' },
      'Ontario CCEYA ratios: infant 1:3, toddler 1:5, preschool 1:8, kindergarten 1:13'));

    const status = Dom.el('div', { style: 'margin-top: 12px; font-size: 13px;' });
    form.appendChild(status);

    form.appendChild(submitBtn('Create room', async (data) => {
      const r = await Api.post('/director/rooms', data);
      status.style.color = 'var(--brand-green)';
      status.textContent = '✓ Room "' + r.room.name + '" created';
      setTimeout(() => { modal.close(); onSuccess?.(); }, 1000);
    }, status));

    modal.body.appendChild(form);
    modal.show();
  }

  // ──────────────────────────────────────────────────────────────
  // ADD FAMILY
  // ──────────────────────────────────────────────────────────────
  function showAddFamilyModal(onSuccess) {
    const modal = new Modal('Add a new family');
    const form = Dom.el('form');

    form.appendChild(field('Family name', 'family_name', { type: 'text', required: true, placeholder: 'e.g. "The Smith Family"', maxlength: 160 }));

    const contactRow = Dom.el('div', { style: 'display: grid; grid-template-columns: 1fr 1fr; gap: 12px;' });
    contactRow.appendChild(field('Primary email', 'primary_email', { type: 'email', maxlength: 180 }));
    contactRow.appendChild(field('Primary phone', 'primary_phone', { type: 'tel', maxlength: 40 }));
    form.appendChild(contactRow);

    form.appendChild(field('Street address', 'address_line1', { type: 'text', maxlength: 200 }));

    const addrRow = Dom.el('div', { style: 'display: grid; grid-template-columns: 2fr 1fr; gap: 12px;' });
    addrRow.appendChild(field('City', 'city', { type: 'text', value: 'Caledon', maxlength: 80 }));
    addrRow.appendChild(field('Postal code', 'postal_code', { type: 'text', maxlength: 12, placeholder: 'L7C 1A1' }));
    form.appendChild(addrRow);

    form.appendChild(selectField('Preferred language', 'preferred_lang', [
      ['en-CA', 'English'], ['fr-CA', 'French'],
    ]));

    const status = Dom.el('div', { style: 'margin-top: 12px; font-size: 13px;' });
    form.appendChild(status);

    form.appendChild(submitBtn('Create family', async (data) => {
      const r = await Api.post('/director/families', data);
      status.style.color = 'var(--brand-green)';
      status.textContent = '✓ Family created (id: ' + r.family_id + '). Now you can add children and invite parents.';
      setTimeout(() => { modal.close(); onSuccess?.(r.family_id); }, 1500);
    }, status));

    modal.body.appendChild(form);
    modal.show();
  }

  // ──────────────────────────────────────────────────────────────
  // ADD CHILD (with enrollment in one go)
  // ──────────────────────────────────────────────────────────────
  async function showAddChildModal(onSuccess) {
    const modal = new Modal('Enroll a new child');
    modal.body.appendChild(Dom.el('p', { style: 'color: var(--ink-500); margin: 0 0 16px;' }, 'Loading families and rooms...'));

    // Load families and rooms in parallel
    let families = [], rooms = [];
    try {
      const [fRes, rRes] = await Promise.all([
        Api.get('/director/families'),
        Api.get('/director/rooms'),
      ]);
      families = fRes.data || [];
      rooms = rRes.rooms || [];
    } catch (e) {
      Dom.clear(modal.body);
      modal.body.appendChild(Dom.el('p', { style: 'color: #c0392b;' }, 'Could not load setup data: ' + e.message));
      return;
    }

    Dom.clear(modal.body);

    if (families.length === 0) {
      modal.body.appendChild(Dom.el('div', { style: 'background: #FEF3C7; padding: 16px; border-radius: 8px; border-left: 3px solid #F59E0B;' }, [
        Dom.el('strong', {}, 'Add a family first'),
        Dom.el('p', { style: 'margin: 4px 0 0;' }, 'A child must belong to a family. Close this and click "Add Family" first.'),
      ]));
      return;
    }
    if (rooms.length === 0) {
      modal.body.appendChild(Dom.el('div', { style: 'background: #FEF3C7; padding: 16px; border-radius: 8px; border-left: 3px solid #F59E0B;' }, [
        Dom.el('strong', {}, 'Add a room first'),
        Dom.el('p', { style: 'margin: 4px 0 0;' }, 'A child must be enrolled in a room. Close this and click "Add Room" first.'),
      ]));
      return;
    }

    const form = Dom.el('form');

    const nameRow = Dom.el('div', { style: 'display: grid; grid-template-columns: 1fr 1fr; gap: 12px;' });
    nameRow.appendChild(field('First name', 'first_name', { type: 'text', required: true, maxlength: 80 }));
    nameRow.appendChild(field('Last name', 'last_name', { type: 'text', required: true, maxlength: 80 }));
    form.appendChild(nameRow);

    form.appendChild(field('Preferred name (optional)', 'preferred_name', { type: 'text', maxlength: 80, placeholder: 'What they go by' }));

    const dobRow = Dom.el('div', { style: 'display: grid; grid-template-columns: 1fr 1fr; gap: 12px;' });
    dobRow.appendChild(field('Date of birth', 'date_of_birth', { type: 'date', required: true, max: new Date().toISOString().split('T')[0] }));
    dobRow.appendChild(selectField('Gender', 'gender', [
      ['prefer_not_to_say', 'Prefer not to say'],
      ['female', 'Female'], ['male', 'Male'],
      ['non_binary', 'Non-binary'], ['other', 'Other'],
    ]));
    form.appendChild(dobRow);

    form.appendChild(selectField('Family', 'family_id',
      families.map(f => [String(f.id), f.family_name]),
      true
    ));

    form.appendChild(selectField('Room', 'room_id',
      rooms.filter(r => r.active).map(r => [String(r.id), `${r.name} (${r.age_group})`]),
      true
    ));

    const enrollRow = Dom.el('div', { style: 'display: grid; grid-template-columns: 1fr 1fr; gap: 12px;' });
    enrollRow.appendChild(field('Start date', 'start_date', { type: 'date', required: true, value: new Date().toISOString().split('T')[0] }));
    enrollRow.appendChild(field('Monthly fee ($)', 'monthly_fee', { type: 'number', required: true, min: 0, step: 0.01, placeholder: '1450.00' }));
    form.appendChild(enrollRow);

    const cwelccWrap = Dom.el('label', { style: 'display: flex; align-items: center; gap: 8px; margin: 8px 0 16px; cursor: pointer;' });
    const cwelccInput = Dom.el('input', { type: 'checkbox', name: 'cwelcc_eligible', checked: true });
    cwelccWrap.appendChild(cwelccInput);
    cwelccWrap.appendChild(Dom.el('span', {}, 'CWELCC eligible (Ontario subsidy)'));
    form.appendChild(cwelccWrap);

    form.appendChild(Dom.el('label', { style: 'display: block; margin-bottom: 6px; font-weight: 600;' }, 'Medical notes (allergies, conditions)'));
    const medical = Dom.el('textarea', { name: 'medical_notes', style: 'width: 100%; min-height: 60px; padding: 10px; border: 1px solid var(--ink-300); border-radius: 8px; font-family: inherit; resize: vertical; margin-bottom: 12px;', placeholder: 'e.g. Severe peanut allergy. EpiPen in office.' });
    form.appendChild(medical);

    form.appendChild(Dom.el('label', { style: 'display: block; margin-bottom: 6px; font-weight: 600;' }, 'Dietary notes'));
    const dietary = Dom.el('textarea', { name: 'dietary_notes', style: 'width: 100%; min-height: 60px; padding: 10px; border: 1px solid var(--ink-300); border-radius: 8px; font-family: inherit; resize: vertical;', placeholder: 'e.g. Vegetarian, no dairy' });
    form.appendChild(dietary);

    const status = Dom.el('div', { style: 'margin-top: 12px; font-size: 13px;' });
    form.appendChild(status);

    form.appendChild(submitBtn('Enroll child', async (data) => {
      // Convert numeric strings to numbers, checkbox to bool
      data.family_id = parseInt(data.family_id, 10);
      data.room_id = parseInt(data.room_id, 10);
      data.monthly_fee = parseFloat(data.monthly_fee);
      data.cwelcc_eligible = cwelccInput.checked;
      data.medical_notes = medical.value || null;
      data.dietary_notes = dietary.value || null;

      const r = await Api.post('/director/enrollments', data);
      status.style.color = 'var(--brand-green)';
      status.textContent = '✓ ' + data.first_name + ' enrolled (child id: ' + r.child_id + ')';
      setTimeout(() => { modal.close(); onSuccess?.(r.child_id); }, 1500);
    }, status));

    modal.body.appendChild(form);
  }

  // ──────────────────────────────────────────────────────────────
  // INVITE PARENT
  // ──────────────────────────────────────────────────────────────
  async function showInviteParentModal(familyId, onSuccess) {
    const modal = new Modal('Invite parent');

    // If no familyId provided, let them pick a family first
    if (!familyId) {
      modal.body.appendChild(Dom.el('p', { style: 'color: var(--ink-500);' }, 'Loading families...'));
      try {
        const fRes = await Api.get('/director/families');
        const families = fRes.data || [];
        Dom.clear(modal.body);

        if (families.length === 0) {
          modal.body.appendChild(Dom.el('div', { style: 'background: #FEF3C7; padding: 16px; border-radius: 8px;' }, 'Create a family first before inviting parents.'));
          return;
        }

        modal.body.appendChild(Dom.el('label', { style: 'display: block; margin-bottom: 8px; font-weight: 600;' }, 'Select family'));
        const sel = Dom.el('select', { style: selectStyle() });
        sel.appendChild(Dom.el('option', { value: '' }, '— Choose a family —'));
        families.forEach(f => sel.appendChild(Dom.el('option', { value: f.id }, f.family_name)));
        modal.body.appendChild(sel);

        const continueBtn = Dom.el('button', {
          type: 'button',
          style: btnStyle('--brand-blue'),
        }, 'Continue');
        continueBtn.addEventListener('click', () => {
          if (!sel.value) return alert('Pick a family first.');
          modal.close();
          showInviteParentModal(parseInt(sel.value, 10), onSuccess);
        });
        modal.body.appendChild(continueBtn);
        return;
      } catch (e) {
        Dom.clear(modal.body);
        modal.body.appendChild(Dom.el('p', { style: 'color: #c0392b;' }, e.message));
        return;
      }
    }

    // Normal flow: family picked
    const form = Dom.el('form');

    const nameRow = Dom.el('div', { style: 'display: grid; grid-template-columns: 1fr 1fr; gap: 12px;' });
    nameRow.appendChild(field('First name', 'first_name', { type: 'text', required: true, maxlength: 80 }));
    nameRow.appendChild(field('Last name', 'last_name', { type: 'text', required: true, maxlength: 80 }));
    form.appendChild(nameRow);

    form.appendChild(field('Email', 'email', { type: 'email', required: true, maxlength: 180 }));

    form.appendChild(selectField('Relationship', 'relationship', [
      ['mother', 'Mother'], ['father', 'Father'], ['guardian', 'Guardian'],
      ['grandparent', 'Grandparent'], ['foster', 'Foster'], ['other', 'Other'],
    ], true));

    const checks = [
      ['is_primary', 'Primary contact', true],
      ['can_pickup', 'Can pick up the child', true],
      ['can_receive_billing', 'Receives billing emails', false],
      ['send_email', 'Send welcome email with login details', true],
    ];
    const cbs = {};
    checks.forEach(([name, label, defaultChecked]) => {
      const wrap = Dom.el('label', { style: 'display: flex; align-items: center; gap: 8px; margin: 6px 0; cursor: pointer;' });
      const cb = Dom.el('input', { type: 'checkbox', name, checked: defaultChecked });
      cbs[name] = cb;
      wrap.appendChild(cb);
      wrap.appendChild(Dom.el('span', {}, label));
      form.appendChild(wrap);
    });

    const status = Dom.el('div', { style: 'margin-top: 12px; font-size: 13px;' });
    form.appendChild(status);

    form.appendChild(submitBtn('Send invitation', async (data) => {
      data.is_primary = cbs.is_primary.checked;
      data.can_pickup = cbs.can_pickup.checked;
      data.can_receive_billing = cbs.can_receive_billing.checked;
      data.send_email = cbs.send_email.checked;

      const r = await Api.post('/director/families/' + familyId + '/invite', data);
      status.style.color = 'var(--brand-green)';
      let msg = '✓ ' + (r.email_sent ? 'Invitation email sent to ' + data.email : 'Parent added');
      if (r.temp_password && !r.email_sent) {
        msg += '. Temp password: ' + r.temp_password + ' (please share manually)';
      }
      status.textContent = msg;
      setTimeout(() => { modal.close(); onSuccess?.(); }, r.email_sent ? 1500 : 6000);
    }, status));

    modal.body.appendChild(form);
    modal.show();
  }

  // ──────────────────────────────────────────────────────────────
  // INVITE STAFF
  // ──────────────────────────────────────────────────────────────
  function showInviteStaffModal(onSuccess) {
    const modal = new Modal('Invite staff member');
    const form = Dom.el('form');

    const nameRow = Dom.el('div', { style: 'display: grid; grid-template-columns: 1fr 1fr; gap: 12px;' });
    nameRow.appendChild(field('First name', 'first_name', { type: 'text', required: true, maxlength: 80 }));
    nameRow.appendChild(field('Last name', 'last_name', { type: 'text', required: true, maxlength: 80 }));
    form.appendChild(nameRow);

    form.appendChild(field('Email', 'email', { type: 'email', required: true, maxlength: 180 }));

    form.appendChild(selectField('Role', 'role', [
      ['educator', 'Educator (tablet view)'],
      ['centre_director', 'Director (full admin)'],
    ], true));

    const sendEmailWrap = Dom.el('label', { style: 'display: flex; align-items: center; gap: 8px; margin: 12px 0; cursor: pointer;' });
    const sendEmail = Dom.el('input', { type: 'checkbox', name: 'send_email', checked: true });
    sendEmailWrap.appendChild(sendEmail);
    sendEmailWrap.appendChild(Dom.el('span', {}, 'Send welcome email with login details'));
    form.appendChild(sendEmailWrap);

    const status = Dom.el('div', { style: 'margin-top: 12px; font-size: 13px;' });
    form.appendChild(status);

    form.appendChild(submitBtn('Send invitation', async (data) => {
      data.send_email = sendEmail.checked;
      const r = await Api.post('/director/staff/invite', data);
      status.style.color = 'var(--brand-green)';
      let msg = '✓ ' + (r.email_sent ? 'Invitation email sent to ' + data.email : 'Staff added');
      if (r.temp_password && !r.email_sent) {
        msg += '. Temp password: ' + r.temp_password;
      }
      status.textContent = msg;
      setTimeout(() => { modal.close(); onSuccess?.(); }, r.email_sent ? 1500 : 6000);
    }, status));

    modal.body.appendChild(form);
    modal.show();
  }

  // ──────────────────────────────────────────────────────────────
  // Helpers
  // ──────────────────────────────────────────────────────────────

  function field(label, name, attrs) {
    const wrap = Dom.el('div', { style: 'margin-bottom: 12px;' });
    wrap.appendChild(Dom.el('label', { style: 'display: block; margin-bottom: 6px; font-weight: 600; font-size: 14px;' }, label));
    const input = Dom.el('input', { name, style: inputStyle(), ...attrs });
    wrap.appendChild(input);
    return wrap;
  }

  function selectField(label, name, options, required) {
    const wrap = Dom.el('div', { style: 'margin-bottom: 12px;' });
    wrap.appendChild(Dom.el('label', { style: 'display: block; margin-bottom: 6px; font-weight: 600; font-size: 14px;' }, label));
    const sel = Dom.el('select', { name, style: selectStyle(), required: !!required });
    if (!required) sel.appendChild(Dom.el('option', { value: '' }, '— Choose —'));
    options.forEach(([val, text]) => sel.appendChild(Dom.el('option', { value: val }, text)));
    wrap.appendChild(sel);
    return wrap;
  }

  function inputStyle() {
    return 'width: 100%; padding: 10px 12px; border: 1px solid var(--ink-300); border-radius: 8px; font-size: 14px; font-family: inherit; box-sizing: border-box;';
  }
  function selectStyle() {
    return inputStyle() + ' appearance: auto;';
  }
  function btnStyle(color) {
    return `background: var(${color}); color: white; border: none; padding: 12px 20px; border-radius: 8px; font-weight: 700; cursor: pointer; margin-top: 16px; width: 100%; font-size: 15px;`;
  }

  function submitBtn(label, handler, status) {
    const btn = Dom.el('button', { type: 'submit', style: btnStyle('--brand-blue') }, label);
    btn.addEventListener('click', async (ev) => {
      ev.preventDefault();
      const form = btn.closest('form');
      const fd = new FormData(form);
      const data = {};
      fd.forEach((v, k) => { if (v !== '') data[k] = v; });

      btn.disabled = true;
      btn.textContent = 'Saving…';
      status.style.color = 'var(--ink-600)';
      status.textContent = '';

      try {
        await handler(data);
      } catch (e) {
        status.style.color = '#c0392b';
        status.textContent = '✗ ' + (e.message || 'Save failed');
        btn.disabled = false;
        btn.textContent = label;
      }
    });
    return btn;
  }

  // ──────────────────────────────────────────────────────────────
  // Expose globally
  // ──────────────────────────────────────────────────────────────
  window.KT = window.KT || {};
  window.KT.showAddRoomModal = showAddRoomModal;
  window.KT.showAddFamilyModal = showAddFamilyModal;
  window.KT.showAddChildModal = showAddChildModal;
  window.KT.showInviteParentModal = showInviteParentModal;
  window.KT.showInviteStaffModal = showInviteStaffModal;

  // ──────────────────────────────────────────────────────────────
  // Inject floating action buttons into the director shell
  // Runs after DOM ready — looks for sidebar and appends "Add" buttons
  // ──────────────────────────────────────────────────────────────
  function injectQuickActions() {
    // Wait for sidebar to be present
    const tryInject = () => {
      const sidebar = document.querySelector('.kt-sidebar, .sidebar, [class*="sidebar"]');
      if (!sidebar || sidebar.querySelector('.kt-v5a-actions')) return false;

      const section = Dom.el('div', { class: 'kt-v5a-actions', style: 'padding: 16px; border-top: 1px solid var(--ink-200); margin-top: 16px;' });
      section.appendChild(Dom.el('div', { style: 'font-size: 11px; font-weight: 700; color: var(--ink-500); letter-spacing: 1px; margin-bottom: 8px;' }, 'QUICK ADD'));

      [
        ['🏫 New room', () => showAddRoomModal(() => window.location.reload())],
        ['👪 New family', () => showAddFamilyModal()],
        ['👶 New child', () => showAddChildModal(() => window.location.reload())],
        ['💌 Invite parent', () => showInviteParentModal(null)],
        ['👨‍🏫 Invite staff', () => showInviteStaffModal()],
      ].forEach(([label, handler]) => {
        const btn = Dom.el('button', {
          style: 'display: block; width: 100%; text-align: left; background: none; border: none; padding: 8px 12px; border-radius: 6px; font-size: 13px; color: var(--ink-700); cursor: pointer; margin-bottom: 2px;',
        }, label);
        btn.addEventListener('mouseenter', () => btn.style.background = 'var(--ink-50)');
        btn.addEventListener('mouseleave', () => btn.style.background = 'none');
        btn.addEventListener('click', handler);
        section.appendChild(btn);
      });

      // v22p1.1: insert ABOVE the .nav-user pill so QUICK ADD stays in view, instead of
      // falling off-screen at the very bottom of a tall sidebar. Anchor on navUser's actual
      // parent, since `sidebar` may have matched an outer wrapper (e.g. .app-shell--sidebar)
      // rather than the .app-sidebar aside itself.
      var navUser = document.querySelector('.nav-user');
      if (navUser && navUser.parentNode) {
        navUser.parentNode.insertBefore(section, navUser);
      } else {
        sidebar.appendChild(section);
      }
      return true;
    };

    // Try immediately, then poll for a few seconds
    if (!tryInject()) {
      let attempts = 0;
      const poll = setInterval(() => {
        if (tryInject() || ++attempts > 20) clearInterval(poll);
      }, 250);
    }
  }

  // v22p1.1: inject for both centre_director AND agency_admin (same sidebar layout)
  function isStaffWithSidebar() {
    var u = JSON.parse(sessionStorage.getItem('kt_user') || '{}');
    return u && (u.primary_role === 'centre_director' || u.primary_role === 'agency_admin');
  }

  document.addEventListener('DOMContentLoaded', () => {
    if (isStaffWithSidebar()) setTimeout(injectQuickActions, 500);
  });

  // Also re-inject on hash change (single-page nav)
  window.addEventListener('hashchange', () => {
    if (isStaffWithSidebar()) setTimeout(injectQuickActions, 200);
  });
})(window);
