  // v22p36: multi-step "new family" wizard (Family -> Guardians -> Children -> Review).
  // Creates the family plus nested guardians (each becomes an invited guardian
  // login) and children in a single POST /admin/families call (DB transaction).
  function showFamilyWizard(centres, content) {
    centres = centres || [];
    var inStyle = 'width:100%;padding:9px 12px;border:1px solid var(--ink-300);border-radius:8px;font-size:14px;box-sizing:border-box;';
    var labStyle = 'display:block;font-size:13px;font-weight:600;margin-bottom:4px;color:#1E293B;';
    var state = {
      family: {
        centre_id: centres[0] ? centres[0].id : null,
        family_name: '', primary_phone: '', primary_email: '',
        address_line1: '', city: '', province: '', postal_code: '',
        billing_split: 'single', notes: '',
      },
      guardians: [{ email: '', first_name: '', last_name: '', phone: '', relationship: 'mother', is_primary: true, can_pickup: true }],
      children: [{ first_name: '', last_name: '', preferred_name: '', date_of_birth: '', gender: 'prefer_not_to_say', enrollment_status: 'enrolled' }],
    };
    var STEPS = ['Family', 'Guardians', 'Children', 'Review'];
    var step = 0;

    var root = Dom.el('div', {});
    var stepper = Dom.el('div', { style: 'display:flex;gap:8px;margin-bottom:18px;' });
    var bodyEl = Dom.el('div', {});
    var status = Dom.el('div', { style: 'min-height:18px;color:#DC2626;font-size:13px;margin:10px 0 4px;' });
    var footer = Dom.el('div', { style: 'display:flex;justify-content:space-between;align-items:center;gap:10px;margin-top:8px;border-top:1px solid #E2E8F0;padding-top:14px;' });
    root.appendChild(stepper); root.appendChild(bodyEl); root.appendChild(status); root.appendChild(footer);

    function lab(t) { return Dom.el('label', { style: labStyle }, t); }
    function wrap(label, input) { var d = Dom.el('div', { style: 'margin-bottom:14px;' }); d.appendChild(lab(label)); d.appendChild(input); return d; }
    function fieldRow(cols) { return Dom.el('div', { style: 'display:grid;grid-template-columns:' + cols + ';gap:12px;margin-bottom:14px;' }); }
    function bindInput(obj, key, opts) {
      opts = opts || {};
      var input = Dom.el('input', { type: opts.type || 'text', style: inStyle });
      if (opts.placeholder) input.placeholder = opts.placeholder;
      input.value = obj[key] == null ? '' : obj[key];
      input.addEventListener('input', function () { obj[key] = input.value; });
      return input;
    }
    function bindSelect(obj, key, options) {
      var s = Dom.el('select', { style: inStyle + 'background:white;' });
      options.forEach(function (o) {
        var op = Dom.el('option', { value: o.value }, o.label);
        if (String(obj[key]) === String(o.value)) op.selected = true;
        s.appendChild(op);
      });
      s.addEventListener('change', function () { obj[key] = s.value; });
      return s;
    }

    function renderStepper() {
      stepper.innerHTML = '';
      STEPS.forEach(function (s, i) {
        var cur = i === step, done = i < step;
        stepper.appendChild(Dom.el('div', {
          style: 'flex:1;text-align:center;font-size:12px;font-weight:700;padding:8px 6px;border-radius:8px;'
            + (cur ? 'background:#1F6080;color:white;' : done ? 'background:#DCEAF1;color:#1F6080;' : 'background:#F1F5F9;color:#94A3B8;'),
        }, (i + 1) + '. ' + s));
      });
    }

    function renderFamilyStep() {
      bodyEl.innerHTML = '';
      bodyEl.appendChild(wrap('Centre *', bindSelect(state.family, 'centre_id', centres.map(function (c) { return { value: c.id, label: c.name }; }))));
      bodyEl.appendChild(wrap('Family name *', bindInput(state.family, 'family_name', { placeholder: 'e.g. The Patel family' })));
      var r1 = fieldRow('1fr 1fr');
      r1.appendChild(wrap('Primary phone', bindInput(state.family, 'primary_phone')));
      r1.appendChild(wrap('Primary email', bindInput(state.family, 'primary_email', { type: 'email' })));
      bodyEl.appendChild(r1);
      bodyEl.appendChild(wrap('Street address', bindInput(state.family, 'address_line1')));
      var r2 = fieldRow('2fr 1fr 1fr');
      r2.appendChild(wrap('City', bindInput(state.family, 'city')));
      r2.appendChild(wrap('Province', bindInput(state.family, 'province')));
      r2.appendChild(wrap('Postal code', bindInput(state.family, 'postal_code')));
      bodyEl.appendChild(r2);
      bodyEl.appendChild(wrap('Billing split', bindSelect(state.family, 'billing_split', [
        { value: 'single', label: 'Single payer' },
        { value: 'split_50_50', label: 'Split 50 / 50 between guardians' },
        { value: 'custom', label: 'Custom split' },
      ])));
      var notes = Dom.el('textarea', { style: inStyle + 'min-height:60px;font-family:inherit;' });
      notes.value = state.family.notes || '';
      notes.addEventListener('input', function () { state.family.notes = notes.value; });
      bodyEl.appendChild(wrap('Internal notes', notes));
    }

    function renderGuardiansStep() {
      bodyEl.innerHTML = '';
      bodyEl.appendChild(Dom.el('p', { style: 'font-size:13px;color:#64748B;margin:0 0 14px;' }, 'Add one or more parents / guardians. The first is the primary contact and billing payer. Each gets an invited login they can activate later.'));
      state.guardians.forEach(function (g, idx) {
        var card = Dom.el('div', { style: 'border:1px solid #E2E8F0;border-radius:10px;padding:14px;margin-bottom:12px;background:#FBFDFE;' });
        var head = Dom.el('div', { style: 'display:flex;justify-content:space-between;align-items:center;margin-bottom:10px;' });
        head.appendChild(Dom.el('strong', { style: 'font-size:13px;color:#1F6080;' }, idx === 0 ? '★ Primary guardian' : 'Guardian ' + (idx + 1)));
        if (state.guardians.length > 1) {
          var rm = Dom.el('button', { type: 'button', style: 'background:none;border:none;color:#DC2626;font-size:13px;cursor:pointer;' }, '✕ Remove');
          rm.addEventListener('click', function () { state.guardians.splice(idx, 1); renderGuardiansStep(); });
          head.appendChild(rm);
        }
        card.appendChild(head);
        var r1 = fieldRow('1fr 1fr');
        r1.appendChild(wrap('First name *', bindInput(g, 'first_name')));
        r1.appendChild(wrap('Last name *', bindInput(g, 'last_name')));
        card.appendChild(r1);
        var r2 = fieldRow('1fr 1fr');
        r2.appendChild(wrap('Email *', bindInput(g, 'email', { type: 'email' })));
        r2.appendChild(wrap('Phone', bindInput(g, 'phone')));
        card.appendChild(r2);
        var r3 = fieldRow('1fr 1fr');
        r3.appendChild(wrap('Relationship', bindSelect(g, 'relationship', [
          { value: 'mother', label: 'Mother' }, { value: 'father', label: 'Father' },
          { value: 'guardian', label: 'Guardian' }, { value: 'grandparent', label: 'Grandparent' },
          { value: 'foster', label: 'Foster' }, { value: 'other', label: 'Other' },
        ])));
        var pickWrap = Dom.el('div', { style: 'display:flex;align-items:center;gap:8px;margin-top:26px;' });
        var pick = Dom.el('input', { type: 'checkbox' }); pick.checked = g.can_pickup !== false;
        pick.addEventListener('change', function () { g.can_pickup = pick.checked; });
        pickWrap.appendChild(pick); pickWrap.appendChild(Dom.el('label', { style: 'font-size:13px;' }, 'Authorized for pickup'));
        r3.appendChild(pickWrap);
        card.appendChild(r3);
        bodyEl.appendChild(card);
      });
      var add = Dom.el('button', { type: 'button', style: 'background:#EFF6FB;border:1px dashed #1F6080;color:#1F6080;border-radius:8px;padding:10px;width:100%;font-weight:600;cursor:pointer;font-size:13px;' }, '+ Add another guardian');
      add.addEventListener('click', function () { state.guardians.push({ email: '', first_name: '', last_name: '', phone: '', relationship: 'guardian', is_primary: false, can_pickup: true }); renderGuardiansStep(); });
      bodyEl.appendChild(add);
    }

    function renderChildrenStep() {
      bodyEl.innerHTML = '';
      bodyEl.appendChild(Dom.el('p', { style: 'font-size:13px;color:#64748B;margin:0 0 14px;' }, 'Add the children in this family. Health, room and other details can be edited after creating.'));
      state.children.forEach(function (c, idx) {
        var card = Dom.el('div', { style: 'border:1px solid #E2E8F0;border-radius:10px;padding:14px;margin-bottom:12px;background:#FBFDFE;' });
        var head = Dom.el('div', { style: 'display:flex;justify-content:space-between;align-items:center;margin-bottom:10px;' });
        head.appendChild(Dom.el('strong', { style: 'font-size:13px;color:#1F6080;' }, 'Child ' + (idx + 1)));
        if (state.children.length > 1) {
          var rm = Dom.el('button', { type: 'button', style: 'background:none;border:none;color:#DC2626;font-size:13px;cursor:pointer;' }, '✕ Remove');
          rm.addEventListener('click', function () { state.children.splice(idx, 1); renderChildrenStep(); });
          head.appendChild(rm);
        }
        card.appendChild(head);
        var r1 = fieldRow('1fr 1fr');
        r1.appendChild(wrap('First name *', bindInput(c, 'first_name')));
        r1.appendChild(wrap('Last name *', bindInput(c, 'last_name')));
        card.appendChild(r1);
        var r2 = fieldRow('1fr 1fr');
        r2.appendChild(wrap('Preferred name', bindInput(c, 'preferred_name')));
        r2.appendChild(wrap('Date of birth *', bindInput(c, 'date_of_birth', { type: 'date' })));
        card.appendChild(r2);
        var r3 = fieldRow('1fr 1fr');
        r3.appendChild(wrap('Gender', bindSelect(c, 'gender', [
          { value: 'female', label: 'Female' }, { value: 'male', label: 'Male' },
          { value: 'non_binary', label: 'Non-binary' }, { value: 'prefer_not_to_say', label: 'Prefer not to say' },
          { value: 'other', label: 'Other' },
        ])));
        r3.appendChild(wrap('Enrollment', bindSelect(c, 'enrollment_status', [
          { value: 'enrolled', label: 'Enrolled' }, { value: 'waitlist', label: 'Waitlist' },
        ])));
        card.appendChild(r3);
        bodyEl.appendChild(card);
      });
      var add = Dom.el('button', { type: 'button', style: 'background:#EFF6FB;border:1px dashed #1F6080;color:#1F6080;border-radius:8px;padding:10px;width:100%;font-weight:600;cursor:pointer;font-size:13px;' }, '+ Add another child');
      add.addEventListener('click', function () { state.children.push({ first_name: '', last_name: '', preferred_name: '', date_of_birth: '', gender: 'prefer_not_to_say', enrollment_status: 'enrolled' }); renderChildrenStep(); });
      bodyEl.appendChild(add);
    }

    function renderReviewStep() {
      bodyEl.innerHTML = '';
      function row(k, v) {
        var d = Dom.el('div', { style: 'display:flex;justify-content:space-between;gap:14px;padding:5px 0;border-bottom:1px solid #F1F5F9;font-size:13px;' });
        d.appendChild(Dom.el('span', { style: 'color:#64748B;flex-shrink:0;' }, k));
        d.appendChild(Dom.el('span', { style: 'font-weight:600;color:#0F172A;text-align:right;' }, v || '—'));
        return d;
      }
      var centreObj = centres.filter(function (c) { return String(c.id) === String(state.family.centre_id); })[0];
      var fam = Dom.el('div', { style: 'border:1px solid #E2E8F0;border-radius:10px;padding:14px;margin-bottom:12px;' });
      fam.appendChild(Dom.el('strong', { style: 'font-size:13px;color:#1F6080;display:block;margin-bottom:6px;' }, '👪 Family'));
      fam.appendChild(row('Name', state.family.family_name));
      fam.appendChild(row('Centre', centreObj ? centreObj.name : '—'));
      fam.appendChild(row('Contact', [state.family.primary_phone, state.family.primary_email].filter(Boolean).join('  ·  ')));
      bodyEl.appendChild(fam);
      var gd = Dom.el('div', { style: 'border:1px solid #E2E8F0;border-radius:10px;padding:14px;margin-bottom:12px;' });
      gd.appendChild(Dom.el('strong', { style: 'font-size:13px;color:#1F6080;display:block;margin-bottom:6px;' }, '🧑‍🤝‍🧑 Guardians (' + state.guardians.length + ')'));
      state.guardians.forEach(function (g, i) { gd.appendChild(row((i === 0 ? '★ ' : '') + (g.first_name + ' ' + g.last_name).trim(), g.relationship + '  ·  ' + g.email)); });
      bodyEl.appendChild(gd);
      var ch = Dom.el('div', { style: 'border:1px solid #E2E8F0;border-radius:10px;padding:14px;' });
      ch.appendChild(Dom.el('strong', { style: 'font-size:13px;color:#1F6080;display:block;margin-bottom:6px;' }, '🧒 Children (' + state.children.length + ')'));
      state.children.forEach(function (c) { ch.appendChild(row((c.first_name + ' ' + c.last_name).trim(), (c.date_of_birth || '?') + '  ·  ' + c.enrollment_status)); });
      bodyEl.appendChild(ch);
    }

    function validateStep() {
      status.style.color = '#DC2626';
      if (step === 0) {
        if (!state.family.centre_id) { status.textContent = 'Please choose a centre.'; return false; }
        if (!state.family.family_name.trim()) { status.textContent = 'Family name is required.'; return false; }
      } else if (step === 1) {
        for (var i = 0; i < state.guardians.length; i++) {
          var g = state.guardians[i];
          if (!g.first_name.trim() || !g.last_name.trim()) { status.textContent = 'Guardian ' + (i + 1) + ': first and last name are required.'; return false; }
          if (!g.email.trim() || g.email.indexOf('@') < 1) { status.textContent = 'Guardian ' + (i + 1) + ': a valid email is required.'; return false; }
        }
        var emails = state.guardians.map(function (x) { return x.email.trim().toLowerCase(); });
        for (var a = 0; a < emails.length; a++) { for (var b = a + 1; b < emails.length; b++) { if (emails[a] === emails[b]) { status.textContent = 'Each guardian needs a unique email address.'; return false; } } }
      } else if (step === 2) {
        for (var j = 0; j < state.children.length; j++) {
          var c = state.children[j];
          if (!c.first_name.trim() || !c.last_name.trim()) { status.textContent = 'Child ' + (j + 1) + ': first and last name are required.'; return false; }
          if (!c.date_of_birth) { status.textContent = 'Child ' + (j + 1) + ': date of birth is required.'; return false; }
        }
      }
      status.textContent = '';
      return true;
    }

    function render() {
      renderStepper();
      if (step === 0) renderFamilyStep();
      else if (step === 1) renderGuardiansStep();
      else if (step === 2) renderChildrenStep();
      else renderReviewStep();
      renderFooter();
    }

    function renderFooter() {
      footer.innerHTML = '';
      var left = Dom.el('div', {});
      if (step > 0) {
        var back = Dom.el('button', { type: 'button', style: 'background:#F1F5F9;border:1px solid #CBD5E1;color:#334155;border-radius:8px;padding:9px 18px;font-weight:600;cursor:pointer;' }, '← Back');
        back.addEventListener('click', function () { step--; status.textContent = ''; render(); });
        left.appendChild(back);
      }
      footer.appendChild(left);
      var right = Dom.el('div', {});
      if (step < STEPS.length - 1) {
        var next = Dom.el('button', { type: 'button', style: 'background:#1F6080;border:none;color:white;border-radius:8px;padding:9px 22px;font-weight:700;cursor:pointer;' }, 'Next →');
        next.addEventListener('click', function () { if (validateStep()) { step++; render(); } });
        right.appendChild(next);
      } else {
        var create = Dom.el('button', { type: 'button', style: 'background:#16A34A;border:none;color:white;border-radius:8px;padding:9px 22px;font-weight:700;cursor:pointer;' }, '✓ Create family');
        create.addEventListener('click', submit);
        right.appendChild(create);
      }
      footer.appendChild(right);
    }

    async function submit() {
      state.guardians.forEach(function (g, i) { g.is_primary = (i === 0); });
      var payload = {
        family_name: state.family.family_name.trim(),
        centre_id: parseInt(state.family.centre_id, 10),
        primary_email: state.family.primary_email.trim() || null,
        primary_phone: state.family.primary_phone.trim() || null,
        address_line1: state.family.address_line1.trim() || null,
        city: state.family.city.trim() || null,
        province: state.family.province.trim() || null,
        postal_code: state.family.postal_code.trim() || null,
        billing_split: state.family.billing_split,
        notes: state.family.notes.trim() || null,
        guardians: state.guardians.map(function (g) {
          return { email: g.email.trim(), first_name: g.first_name.trim(), last_name: g.last_name.trim(), phone: g.phone.trim() || null, relationship: g.relationship, is_primary: !!g.is_primary, can_pickup: g.can_pickup !== false };
        }),
        children: state.children.map(function (c) {
          return { first_name: c.first_name.trim(), last_name: c.last_name.trim(), preferred_name: c.preferred_name.trim() || null, date_of_birth: c.date_of_birth, gender: c.gender, enrollment_status: c.enrollment_status };
        }),
      };
      status.style.color = '#1F6080';
      status.textContent = 'Creating family…';
      footer.innerHTML = '';
      try {
        var res = await Api.post('/admin/families', payload);
        if (Dom.toast) Dom.toast('Family created — ' + (res.guardians || 0) + ' guardian(s), ' + (res.children || 0) + ' child(ren)', 'success');
        await renderFamiliesTab(content);
        Shell.Modal.close();
      } catch (e) {
        status.style.color = '#DC2626';
        status.textContent = (e.message || 'Could not create family') + (e.errors ? ' — ' + Object.values(e.errors).flat().join(', ') : '');
        renderFooter();
      }
    }

    Shell.Modal.open({ title: 'New family', body: root, large: true, actions: [] });
    render();
  }

