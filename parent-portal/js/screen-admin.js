/* ═══════════════════════════════════════════════════════════════════
   KIDDIETRAC v9 — Admin Panel (Agency Admin)
   Tabs: Centres · Users · Families · Branding · Billing
   ═══════════════════════════════════════════════════════════════════ */
(function (window) {
  'use strict';
  const { Api, Dom, Shell } = window.KT;

  // State per tab to keep loaded data
  const state = {
    activeTab: 'centres',
    centres: null,
    users: null,
    families: null,
    branding: null,
    billing: null,
  };

  async function renderAdmin(main, ctx) {
    Dom.clear(main);
    const wrap = Dom.el('div', { style: 'max-width: 1280px; margin: 0 auto; padding: 24px;' });
    main.appendChild(wrap);

    // Header
    const header = Dom.el('div', { style: 'margin-bottom: 24px;' });
    header.appendChild(Dom.el('h1', { style: 'font-size: 28px; font-weight: 800; margin: 0;' }, 'Admin'));
    header.appendChild(Dom.el('div', { style: 'color: var(--ink-500); margin-top: 4px;' }, 'Manage centres, users, families, and branding'));
    wrap.appendChild(header);

    // Tab strip
    const tabs = Dom.el('div', { style: 'display: flex; gap: 4px; margin-bottom: 24px; border-bottom: 1px solid var(--ink-200, #E5E7EB); overflow-x: auto;' });

    function tabBtn(key, label) {
      const isActive = state.activeTab === key;
      const btn = Dom.el('button', {
        style: 'padding: 12px 18px; border: none; background: transparent; cursor: pointer; font-size: 14px; font-weight: 600; ' +
               (isActive
                 ? 'color: var(--brand-blue, #1F6080); border-bottom: 2px solid var(--brand-blue, #1F6080); margin-bottom: -1px;'
                 : 'color: var(--ink-500);'),
      }, label);
      btn.addEventListener('click', () => {
        // v22p2.3: use admin-<tab> hash format so the v17 shell can route directly
        // (admin/<tab> is not a registered key and was falling back to dashboard).
        state.activeTab = key;
        if (window.location.hash !== '#admin-' + key) {
          window.location.hash = 'admin-' + key;
        } else {
          renderAdmin(main, ctx);
        }
      });
      return btn;
    }

    tabs.appendChild(tabBtn('centres', '🏫 Centres'));
    tabs.appendChild(tabBtn('users', '👥 Users'));
    tabs.appendChild(tabBtn('families', '👪 Families'));
    tabs.appendChild(tabBtn('branding', '🎨 Branding'));
    tabs.appendChild(tabBtn('billing', '💳 Billing'));
    wrap.appendChild(tabs);

    // Tab content container
    const content = Dom.el('div', { id: 'admin-tab-content' });
    wrap.appendChild(content);

    // Route to tab renderer. Honour both legacy "admin/<tab>" and v22p2.3 "admin-<tab>".
    const hash = (window.location.hash || '').replace('#', '');
    if (hash.startsWith('admin-')) {
      state.activeTab = hash.replace('admin-', '');
    } else if (hash.startsWith('admin/')) {
      state.activeTab = hash.replace('admin/', '');
    }

    switch (state.activeTab) {
      case 'centres': await renderCentresTab(content); break;
      case 'users': await renderUsersTab(content); break;
      case 'families': await renderFamiliesTab(content); break;
      case 'branding': await renderBrandingTab(content); break;
      case 'billing': await renderBillingTab(content); break;
      default: state.activeTab = 'centres'; await renderCentresTab(content);
    }
  }

  // ════════════════════════════════════════════════════════════════
  //   CENTRES TAB
  // ════════════════════════════════════════════════════════════════
  async function renderCentresTab(content) {
    Dom.clear(content);
    content.appendChild(loading('Loading centres...'));

    let data;
    try {
      data = await Api.get('/admin/centres');
    } catch (e) {
      Dom.clear(content);
      content.appendChild(errorBox('Could not load centres: ' + (e.message || 'error')));
      return;
    }

    Dom.clear(content);

    // Action bar
    const bar = Dom.el('div', { style: 'display: flex; justify-content: space-between; margin-bottom: 16px;' });
    bar.appendChild(Dom.el('div', { style: 'color: var(--ink-500); font-size: 14px;' }, data.centres.length + ' centre' + (data.centres.length === 1 ? '' : 's')));
    const addBtn = Dom.el('button', { style: btnPrimary() }, '+ Add centre');
    addBtn.addEventListener('click', () => showCentreModal(null, content));
    bar.appendChild(addBtn);
    content.appendChild(bar);

    if (data.centres.length === 0) {
      content.appendChild(emptyMsg('No centres yet. Click + Add centre to create your first one.'));
      return;
    }

    // Table
    const table = Dom.el('table', { style: 'width: 100%; background: white; border-radius: 12px; overflow: hidden; border-collapse: collapse; box-shadow: 0 1px 3px rgba(0,0,0,0.04);' });
    const thead = Dom.el('thead', { style: 'background: var(--ink-50, #F9FAFB);' });
    const headRow = Dom.el('tr', {});
    ['Name', 'City', 'Status', 'Enrolled', 'Capacity %', 'Families', 'Staff', ''].forEach(h => {
      headRow.appendChild(Dom.el('th', { style: 'text-align: left; padding: 12px 16px; font-size: 12px; font-weight: 700; color: var(--ink-500); text-transform: uppercase; letter-spacing: 0.5px;' }, h));
    });
    thead.appendChild(headRow);
    table.appendChild(thead);

    const tbody = Dom.el('tbody', {});
    data.centres.forEach(c => {
      // v22p3.4: tint the row's left edge with the centre's brand colour, render
      // the logo (or initial) inline with the name.
      const accent = c.brand_color || '#1F6080';
      const row = Dom.el('tr', { style: 'border-top: 1px solid var(--ink-100, #E5E7EB);box-shadow:inset 4px 0 0 ' + accent + ';' });
      const nameCell = Dom.el('td', { style: 'padding: 14px 16px; font-weight: 600;' });
      const nameWrap = Dom.el('div', { style: 'display:flex;align-items:center;gap:10px;' });
      // smaller 32px logo for the row
      var miniLogo = Dom.el('div', {
        style: 'flex-shrink:0;width:32px;height:32px;border-radius:7px;overflow:hidden;background:' + accent + ';color:white;display:flex;align-items:center;justify-content:center;font-weight:800;font-size:14px;',
      });
      if (c.logo_url) {
        var miniImg = Dom.el('img', { src: avatarSrc(c.logo_url), alt: c.name || '', style: 'width:100%;height:100%;object-fit:contain;background:white;' });
        miniImg.addEventListener('error', function () { miniImg.remove(); miniLogo.textContent = (c.name || '?').charAt(0).toUpperCase(); });
        miniLogo.appendChild(miniImg);
      } else {
        miniLogo.textContent = (c.name || '?').charAt(0).toUpperCase();
      }
      nameWrap.appendChild(miniLogo);
      var nameStack = Dom.el('div');
      nameStack.appendChild(Dom.el('div', {}, c.name));
      if (c.tagline) nameStack.appendChild(Dom.el('div', { style: 'font-size:11px;color:var(--ink-500);font-weight:500;margin-top:2px;' }, c.tagline));
      nameWrap.appendChild(nameStack);
      nameCell.appendChild(nameWrap);
      row.appendChild(nameCell);
      row.appendChild(Dom.el('td', { style: 'padding: 14px 16px; color: var(--ink-500);' }, c.city || '—'));
      row.appendChild(Dom.el('td', { style: 'padding: 14px 16px;' }, statusBadge(c.status)));
      row.appendChild(Dom.el('td', { style: 'padding: 14px 16px;' }, c.enrolled_count + ' / ' + c.license_capacity));
      row.appendChild(Dom.el('td', { style: 'padding: 14px 16px;' }, c.capacity_pct + '%'));
      row.appendChild(Dom.el('td', { style: 'padding: 14px 16px;' }, String(c.family_count)));
      row.appendChild(Dom.el('td', { style: 'padding: 14px 16px;' }, String(c.staff_count)));
      const editBtn = Dom.el('button', { style: 'background: transparent; border: 1px solid var(--ink-300); padding: 6px 12px; border-radius: 6px; cursor: pointer; font-size: 13px;' }, 'Edit');
      editBtn.addEventListener('click', () => showCentreModal(c, content));
      row.appendChild(Dom.el('td', { style: 'padding: 14px 16px; text-align: right;' }, editBtn));
      tbody.appendChild(row);
    });
    table.appendChild(tbody);
    content.appendChild(table);
  }

  function showCentreModal(centre, content) {
    const isEdit = !!centre;
    const body = Dom.el('div', {});
    const form = Dom.el('form', {});

    const fields = [
      { key: 'name', label: 'Centre name', required: true },
      { key: 'license_number', label: 'License number' },
      { key: 'license_capacity', label: 'License capacity', type: 'number' },
      { key: 'address_line1', label: 'Address' },
      { key: 'city', label: 'City' },
      { key: 'province', label: 'Province', default: 'ON' },
      { key: 'postal_code', label: 'Postal code' },
      { key: 'phone', label: 'Phone' },
      { key: 'email', label: 'Email', type: 'email' },
    ];

    const inputs = {};
    fields.forEach(f => {
      const wrap = Dom.el('div', { style: 'margin-bottom: 12px;' });
      wrap.appendChild(Dom.el('label', { style: 'display: block; font-size: 13px; font-weight: 600; margin-bottom: 4px;' }, f.label + (f.required ? ' *' : '')));
      const input = Dom.el('input', {
        type: f.type || 'text',
        style: 'width: 100%; padding: 8px 12px; border: 1px solid var(--ink-300); border-radius: 6px; font-size: 14px; box-sizing: border-box;',
      });
      input.value = centre ? (centre[f.key] || '') : (f.default || '');
      if (f.required) input.required = true;
      inputs[f.key] = input;
      wrap.appendChild(input);
      form.appendChild(wrap);
    });

    // CWELCC checkbox
    const cwellWrap = Dom.el('div', { style: 'margin: 12px 0; display: flex; align-items: center; gap: 8px;' });
    const cwellInput = Dom.el('input', { type: 'checkbox' });
    cwellInput.checked = centre ? !!centre.cwelcc_enrolled : false;
    inputs.cwelcc_enrolled = cwellInput;
    cwellWrap.appendChild(cwellInput);
    cwellWrap.appendChild(Dom.el('label', { style: 'font-size: 14px;' }, 'CWELCC enrolled (Ontario subsidy)'));
    form.appendChild(cwellWrap);

    // v22p3.4: branding section (logo + colours + tagline). Only shown on edit
    // since the centre needs to exist before we can attach an image.
    if (isEdit) {
      form.appendChild(Dom.el('div', { style: 'font-size:11px;font-weight:800;color:var(--ink-500);letter-spacing:1px;text-transform:uppercase;margin:18px 0 6px;padding-top:14px;border-top:1px solid var(--ink-100,#E5E7EB);' }, 'Branding'));

      // Logo upload
      const logoWrap = Dom.el('div', { style: 'display:flex;align-items:center;gap:14px;margin-bottom:12px;' });
      let logoEl = renderCentreLogoPreview(centre);
      const fileIn = Dom.el('input', { type: 'file', accept: 'image/jpeg,image/png,image/webp,image/svg+xml', style: 'display:none;' });
      const upBtn = Dom.el('button', { type: 'button', style: 'padding:6px 12px;background:white;color:#1F6080;border:1.5px solid #1F6080;border-radius:6px;font-size:12px;font-weight:600;cursor:pointer;' }, centre.logo_url ? 'Change logo' : 'Upload logo');
      const upMsg = Dom.el('span', { style: 'font-size:12px;color:var(--ink-500);margin-left:8px;' });
      upBtn.addEventListener('click', () => fileIn.click());
      fileIn.addEventListener('change', async () => {
        const file = fileIn.files[0]; if (!file) return;
        if (file.size > 2 * 1024 * 1024) { upMsg.textContent = 'Max 2 MB'; upMsg.style.color = '#DC2626'; return; }
        upBtn.disabled = true; upBtn.textContent = 'Uploading...';
        try {
          const fd = new FormData(); fd.append('logo', file);
          const r = await Api.postForm('/admin/centres/' + centre.id + '/logo', fd);
          centre.logo_url = r.logo_url;
          const fresh = renderCentreLogoPreview(centre);
          logoEl.replaceWith(fresh); logoEl = fresh;
          upMsg.textContent = '✓ Logo updated'; upMsg.style.color = '#16A34A';
          upBtn.textContent = 'Change logo';
        } catch (e) {
          upMsg.textContent = 'Failed: ' + (e.message || 'error'); upMsg.style.color = '#DC2626';
        } finally {
          upBtn.disabled = false;
        }
      });
      logoWrap.appendChild(logoEl);
      const upSide = Dom.el('div'); upSide.appendChild(upBtn); upSide.appendChild(fileIn); upSide.appendChild(upMsg);
      logoWrap.appendChild(upSide);
      form.appendChild(logoWrap);

      // Brand + accent colour
      const colourRow = Dom.el('div', { style: 'display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-bottom:12px;' });
      [
        { k: 'brand_color',  label: 'Primary colour', val: centre.brand_color  || '#1F6080' },
        { k: 'accent_color', label: 'Accent colour',  val: centre.accent_color || '#8EC73C' },
      ].forEach(spec => {
        const w = Dom.el('div');
        w.appendChild(Dom.el('label', { style: 'display:block;font-size:13px;font-weight:600;margin-bottom:4px;' }, spec.label));
        const colourInput = Dom.el('input', { type: 'color', value: spec.val, style: 'width:100%;height:36px;border:1px solid var(--ink-300);border-radius:6px;cursor:pointer;padding:2px;' });
        inputs[spec.k] = colourInput;
        w.appendChild(colourInput);
        colourRow.appendChild(w);
      });
      form.appendChild(colourRow);

      // Tagline
      const tagWrap = Dom.el('div', { style: 'margin-bottom:12px;' });
      tagWrap.appendChild(Dom.el('label', { style: 'display:block;font-size:13px;font-weight:600;margin-bottom:4px;' }, 'Tagline (shown on the centre card)'));
      const tagIn = Dom.el('input', { type: 'text', placeholder: 'Where little curious minds grow', style: 'width:100%;padding:8px 12px;border:1px solid var(--ink-300);border-radius:6px;font-size:14px;box-sizing:border-box;' });
      tagIn.value = centre.tagline || '';
      inputs.tagline = tagIn;
      tagWrap.appendChild(tagIn);
      form.appendChild(tagWrap);
    }

    if (isEdit) {
      const statusWrap = Dom.el('div', { style: 'margin: 12px 0;' });
      statusWrap.appendChild(Dom.el('label', { style: 'display: block; font-size: 13px; font-weight: 600; margin-bottom: 4px;' }, 'Status'));
      const select = Dom.el('select', { style: 'width: 100%; padding: 8px 12px; border: 1px solid var(--ink-300); border-radius: 6px; font-size: 14px;' });
      ['onboarding', 'active', 'paused', 'closed'].forEach(s => {
        const opt = Dom.el('option', { value: s }, s);
        if (centre.status === s) opt.selected = true;
        select.appendChild(opt);
      });
      inputs.status = select;
      statusWrap.appendChild(select);
      form.appendChild(statusWrap);
    }

    const status = Dom.el('div', { style: 'min-height: 20px; color: #DC2626; font-size: 13px; margin: 8px 0;' });
    form.appendChild(status);

    body.appendChild(form);

    Shell.Modal.open({
      title: isEdit ? 'Edit ' + centre.name : 'New centre',
      body: body,
      actions: [
        {
          label: isEdit ? 'Save changes' : 'Create centre',
          primary: true,
          onClick: async () => {
            const data = {};
            Object.keys(inputs).forEach(k => {
              const el = inputs[k];
              if (el.type === 'checkbox') data[k] = el.checked;
              else data[k] = el.value;
            });
            if (!data.name) {
              status.textContent = 'Name is required';
              return;
            }
            if (data.license_capacity) data.license_capacity = parseInt(data.license_capacity, 10);

            status.style.color = '#1F6080';
            status.textContent = 'Saving...';
            try {
              if (isEdit) {
                await Api.patch('/admin/centres/' + centre.id, data);
              } else {
                await Api.post('/admin/centres', data);
              }
              // Reload
              await renderCentresTab(content);
              Shell.Modal.close();
            } catch (e) {
              status.style.color = '#DC2626';
              status.textContent = 'Save failed: ' + (e.message || 'error');
            }
          },
        },
      ],
    });
  }

  // ════════════════════════════════════════════════════════════════
  //   USERS TAB
  // ════════════════════════════════════════════════════════════════
  async function renderUsersTab(content) {
    Dom.clear(content);
    content.appendChild(loading('Loading users...'));

    let data;
    try {
      data = await Api.get('/admin/users');
    } catch (e) {
      Dom.clear(content);
      content.appendChild(errorBox('Could not load users: ' + (e.message || 'error')));
      return;
    }

    Dom.clear(content);

    const bar = Dom.el('div', { style: 'display: flex; justify-content: space-between; margin-bottom: 16px;' });
    bar.appendChild(Dom.el('div', { style: 'color: var(--ink-500); font-size: 14px;' }, data.users.length + ' user' + (data.users.length === 1 ? '' : 's')));
    const addBtn = Dom.el('button', { style: btnPrimary() }, '+ Invite user');
    addBtn.addEventListener('click', () => showInviteModal(content));
    bar.appendChild(addBtn);
    content.appendChild(bar);

    if (data.users.length === 0) {
      content.appendChild(emptyMsg('No users yet.'));
      return;
    }

    const table = Dom.el('table', { style: 'width: 100%; background: white; border-radius: 12px; overflow: hidden; border-collapse: collapse; box-shadow: 0 1px 3px rgba(0,0,0,0.04);' });
    const thead = Dom.el('thead', { style: 'background: var(--ink-50, #F9FAFB);' });
    const headRow = Dom.el('tr', {});
    ['Name', 'Email', 'Roles', 'Status', 'Last login', ''].forEach(h => {
      headRow.appendChild(Dom.el('th', { style: 'text-align: left; padding: 12px 16px; font-size: 12px; font-weight: 700; color: var(--ink-500); text-transform: uppercase; letter-spacing: 0.5px;' }, h));
    });
    thead.appendChild(headRow);
    table.appendChild(thead);

    const tbody = Dom.el('tbody', {});
    data.users.forEach(u => {
      const row = Dom.el('tr', { style: 'border-top: 1px solid var(--ink-100, #E5E7EB);' });
      // v22p3.2: name cell now includes a 32px avatar circle (image or initials)
      const nameCell = Dom.el('td', { style: 'padding: 14px 16px; font-weight: 600;' });
      const nameWrap = Dom.el('div', { style: 'display:flex;align-items:center;gap:10px;' });
      nameWrap.appendChild(avatarCircle(u, 32));
      nameWrap.appendChild(Dom.el('span', {}, u.name));
      nameCell.appendChild(nameWrap);
      row.appendChild(nameCell);
      row.appendChild(Dom.el('td', { style: 'padding: 14px 16px; color: var(--ink-500); font-size: 13px;' }, u.email));

      const rolesCell = Dom.el('td', { style: 'padding: 14px 16px;' });
      u.roles.forEach(r => {
        rolesCell.appendChild(Dom.el('span', {
          style: 'display: inline-block; background: var(--ink-100, #F3F4F6); padding: 3px 8px; border-radius: 12px; font-size: 11px; font-weight: 600; margin-right: 4px;',
        }, r.replace(/_/g, ' ')));
      });
      row.appendChild(rolesCell);

      row.appendChild(Dom.el('td', { style: 'padding: 14px 16px;' }, statusBadge(u.status)));
      row.appendChild(Dom.el('td', { style: 'padding: 14px 16px; color: var(--ink-500); font-size: 13px;' }, u.last_login_at ? new Date(u.last_login_at).toLocaleDateString() : 'Never'));

      const editBtn = Dom.el('button', { style: 'background: transparent; border: 1px solid var(--ink-300); padding: 6px 12px; border-radius: 6px; cursor: pointer; font-size: 13px;' }, 'Manage');
      editBtn.addEventListener('click', () => showUserModal(u, content));
      row.appendChild(Dom.el('td', { style: 'padding: 14px 16px; text-align: right;' }, editBtn));

      tbody.appendChild(row);
    });
    table.appendChild(tbody);
    content.appendChild(table);
  }

  function showInviteModal(content) {
    const body = Dom.el('div', {});

    const fields = {
      first_name: { label: 'First name', required: true },
      last_name: { label: 'Last name', required: true },
      email: { label: 'Email', type: 'email', required: true },
      phone: { label: 'Phone' },
    };

    const inputs = {};
    Object.keys(fields).forEach(k => {
      const f = fields[k];
      const wrap = Dom.el('div', { style: 'margin-bottom: 12px;' });
      wrap.appendChild(Dom.el('label', { style: 'display: block; font-size: 13px; font-weight: 600; margin-bottom: 4px;' }, f.label + (f.required ? ' *' : '')));
      const input = Dom.el('input', {
        type: f.type || 'text',
        style: 'width: 100%; padding: 8px 12px; border: 1px solid var(--ink-300); border-radius: 6px; font-size: 14px; box-sizing: border-box;',
      });
      inputs[k] = input;
      wrap.appendChild(input);
      body.appendChild(wrap);
    });

    // Role select
    const roleWrap = Dom.el('div', { style: 'margin-bottom: 12px;' });
    roleWrap.appendChild(Dom.el('label', { style: 'display: block; font-size: 13px; font-weight: 600; margin-bottom: 4px;' }, 'Role *'));
    const roleSelect = Dom.el('select', { style: 'width: 100%; padding: 8px 12px; border: 1px solid var(--ink-300); border-radius: 6px; font-size: 14px;' });
    [
      { v: 'centre_director', l: 'Centre director' },
      { v: 'educator', l: 'Educator' },
      { v: 'agency_admin', l: 'Agency admin' },
      { v: 'auditor', l: 'Auditor (read-only)' },
    ].forEach(r => {
      const opt = Dom.el('option', { value: r.v }, r.l);
      roleSelect.appendChild(opt);
    });
    inputs.role = roleSelect;
    roleWrap.appendChild(roleSelect);
    body.appendChild(roleWrap);

    // Centre select (for non-agency-admin roles)
    const centreWrap = Dom.el('div', { style: 'margin-bottom: 12px;' });
    centreWrap.appendChild(Dom.el('label', { style: 'display: block; font-size: 13px; font-weight: 600; margin-bottom: 4px;' }, 'Centre'));
    const centreSelect = Dom.el('select', { style: 'width: 100%; padding: 8px 12px; border: 1px solid var(--ink-300); border-radius: 6px; font-size: 14px;' });
    centreSelect.appendChild(Dom.el('option', { value: '' }, 'No centre / agency-wide'));
    if (state.centres) {
      state.centres.centres.forEach(c => {
        centreSelect.appendChild(Dom.el('option', { value: String(c.id) }, c.name));
      });
    }
    inputs.centre_id = centreSelect;
    centreWrap.appendChild(centreSelect);
    body.appendChild(centreWrap);

    const note = Dom.el('div', { style: 'background: #FFFBEB; border-left: 3px solid #F59E0B; padding: 10px 12px; font-size: 13px; color: #92400E; border-radius: 4px; margin-top: 12px;' },
      'The user will be created with no password. Send them the login URL and ask them to click "Forgot password" to set their own.');
    body.appendChild(note);

    const status = Dom.el('div', { style: 'min-height: 20px; color: #DC2626; font-size: 13px; margin: 8px 0;' });
    body.appendChild(status);

    Shell.Modal.open({
      title: 'Invite user',
      body: body,
      actions: [{
        label: 'Create user',
        primary: true,
        onClick: async () => {
          const data = {};
          ['first_name', 'last_name', 'email', 'phone', 'role'].forEach(k => { data[k] = inputs[k].value; });
          if (inputs.centre_id.value) data.centre_id = parseInt(inputs.centre_id.value, 10);
          if (!data.first_name || !data.last_name || !data.email) {
            status.textContent = 'Name and email are required';
            return;
          }
          status.style.color = '#1F6080';
          status.textContent = 'Creating...';
          try {
            await Api.post('/admin/users', data);
            await renderUsersTab(content);
            Shell.Modal.close();
          } catch (e) {
            status.style.color = '#DC2626';
            status.textContent = 'Create failed: ' + (e.message || 'error');
          }
        },
      }],
    });
  }

  function showUserModal(user, content) {
    const body = Dom.el('div', {});

    // v22p3.2: avatar row at the top — current avatar + "Change avatar" file picker
    const avatarRow = Dom.el('div', { style: 'display:flex;align-items:center;gap:14px;margin-bottom:18px;padding-bottom:18px;border-bottom:1px solid var(--ink-100,#E5E7EB);' });
    let currentAvatar = avatarCircle(user, 64);
    avatarRow.appendChild(currentAvatar);
    const avatarSide = Dom.el('div', { style: 'flex:1;' });
    avatarSide.appendChild(Dom.el('div', { style: 'font-weight:700;font-size:15px;' }, user.name));
    avatarSide.appendChild(Dom.el('div', { style: 'font-size:13px;color:var(--ink-500);margin-bottom:8px;' }, user.email));
    const fileInput = Dom.el('input', { type: 'file', accept: 'image/jpeg,image/png,image/webp', style: 'display:none;' });
    const changeBtn = Dom.el('button', { type: 'button', style: 'padding:6px 12px;background:white;color:#1F6080;border:1.5px solid #1F6080;border-radius:6px;font-size:12px;font-weight:600;cursor:pointer;' }, 'Change avatar');
    const avatarMsg = Dom.el('span', { style: 'font-size:12px;color:var(--ink-500);margin-left:8px;' });
    changeBtn.addEventListener('click', () => fileInput.click());
    fileInput.addEventListener('change', async () => {
      const file = fileInput.files[0];
      if (!file) return;
      if (file.size > 2 * 1024 * 1024) { avatarMsg.textContent = 'Max 2 MB'; avatarMsg.style.color = '#DC2626'; return; }
      changeBtn.disabled = true; changeBtn.textContent = 'Uploading...';
      try {
        const fd = new FormData(); fd.append('avatar', file);
        const r = await Api.postForm('/admin/users/' + user.id + '/avatar', fd);
        user.photo_url = r.photo_url;
        const fresh = avatarCircle(user, 64);
        currentAvatar.replaceWith(fresh); currentAvatar = fresh;
        avatarMsg.textContent = '✓ Updated'; avatarMsg.style.color = '#16A34A';
      } catch (e) {
        avatarMsg.textContent = 'Failed: ' + (e.message || 'error');
        avatarMsg.style.color = '#DC2626';
      } finally {
        changeBtn.disabled = false; changeBtn.textContent = 'Change avatar';
      }
    });
    avatarSide.appendChild(changeBtn);
    avatarSide.appendChild(fileInput);
    avatarSide.appendChild(avatarMsg);
    avatarRow.appendChild(avatarSide);
    body.appendChild(avatarRow);

    body.appendChild(Dom.el('div', { style: 'margin-bottom: 16px;' }, 'Roles: ' + user.roles.join(', ')));

    // v22p3.4: surface onboarding-wizard role_extras (RECE #, first aid expiry,
    // specialty, etc.) inline so admins can verify educator credentials.
    if (user.profile_extras && user.profile_extras.role_extras) {
      const re = user.profile_extras.role_extras;
      const keys = Object.keys(re).filter(k => re[k] !== null && re[k] !== '' && re[k] !== undefined);
      if (keys.length) {
        const credBox = Dom.el('div', { style: 'background:#F1F5F9;border-left:3px solid #1F6080;padding:10px 14px;border-radius:6px;font-size:13px;margin-bottom:14px;' });
        credBox.appendChild(Dom.el('div', { style: 'font-size:11px;font-weight:800;color:#475569;letter-spacing:0.8px;text-transform:uppercase;margin-bottom:6px;' }, 'From onboarding'));
        const grid = Dom.el('div', { style: 'display:grid;grid-template-columns:auto 1fr;gap:4px 14px;' });
        keys.forEach(k => {
          grid.appendChild(Dom.el('div', { style: 'color:#64748B;font-weight:600;' }, k.replace(/_/g, ' ') + ':'));
          grid.appendChild(Dom.el('div', { style: 'color:#0F172A;' }, String(re[k])));
        });
        credBox.appendChild(grid);
        body.appendChild(credBox);
      }
    }

    const fields = {
      first_name: user.first_name,
      last_name: user.last_name,
      phone: user.phone || '',
    };
    const inputs = {};
    Object.keys(fields).forEach(k => {
      const wrap = Dom.el('div', { style: 'margin-bottom: 12px;' });
      wrap.appendChild(Dom.el('label', { style: 'display: block; font-size: 13px; font-weight: 600; margin-bottom: 4px;' }, k.replace(/_/g, ' ')));
      const input = Dom.el('input', {
        type: 'text',
        style: 'width: 100%; padding: 8px 12px; border: 1px solid var(--ink-300); border-radius: 6px; font-size: 14px; box-sizing: border-box;',
      });
      input.value = fields[k];
      inputs[k] = input;
      wrap.appendChild(input);
      body.appendChild(wrap);
    });

    // Status select
    const statusWrap = Dom.el('div', { style: 'margin-bottom: 12px;' });
    statusWrap.appendChild(Dom.el('label', { style: 'display: block; font-size: 13px; font-weight: 600; margin-bottom: 4px;' }, 'Account status'));
    const select = Dom.el('select', { style: 'width: 100%; padding: 8px 12px; border: 1px solid var(--ink-300); border-radius: 6px; font-size: 14px;' });
    ['active', 'invited', 'suspended', 'deactivated'].forEach(s => {
      const opt = Dom.el('option', { value: s }, s);
      if (user.status === s) opt.selected = true;
      select.appendChild(opt);
    });
    inputs.status = select;
    statusWrap.appendChild(select);
    body.appendChild(statusWrap);

    const status = Dom.el('div', { style: 'min-height: 20px; color: #DC2626; font-size: 13px; margin: 8px 0;' });
    body.appendChild(status);

    // v22p1.2: Lifecycle actions — reset password, resend welcome, delete
    const danger = Dom.el('div', { style: 'margin-top: 18px; padding-top: 16px; border-top: 1px solid var(--ink-100, #E5E7EB);' });
    danger.appendChild(Dom.el('div', { style: 'font-size: 11px; font-weight: 800; color: var(--ink-500); letter-spacing: 1px; text-transform: uppercase; margin-bottom: 8px;' }, 'Account actions'));

    const actionRow = Dom.el('div', { style: 'display: flex; gap: 8px; flex-wrap: wrap;' });

    const resetBtn = Dom.el('button', {
      style: 'padding: 8px 14px; background: white; color: #1F6080; border: 1.5px solid #1F6080; border-radius: 8px; font-size: 13px; font-weight: 600; cursor: pointer;',
    }, '🔑 Reset password');
    resetBtn.addEventListener('click', async () => {
      if (!confirm('Reset ' + user.name + "'s password? A new temporary password will be emailed to " + user.email + '.')) return;
      resetBtn.disabled = true; resetBtn.textContent = 'Resetting...';
      try {
        const r = await Api.post('/admin/users/' + user.id + '/reset-password', { send_email: true });
        status.style.color = '#16A34A';
        status.textContent = r.email_sent
          ? '✓ Password reset and emailed.'
          : ('✓ Password reset. Email failed — share manually: ' + r.temp_password);
      } catch (e) {
        status.style.color = '#DC2626';
        status.textContent = 'Reset failed: ' + (e.message || 'error');
      } finally {
        resetBtn.disabled = false; resetBtn.textContent = '🔑 Reset password';
      }
    });
    actionRow.appendChild(resetBtn);

    // v22p3.5: reopen onboarding wizard
    const reopenBtn = Dom.el('button', {
      style: 'padding: 8px 14px; background: white; color: #1F6080; border: 1.5px solid #1F6080; border-radius: 8px; font-size: 13px; font-weight: 600; cursor: pointer;',
    }, '🪄 Reopen onboarding');
    reopenBtn.addEventListener('click', async () => {
      if (!confirm('Reopen the onboarding wizard for ' + user.name + '? They will be prompted to complete their profile on their next sign-in.')) return;
      reopenBtn.disabled = true; reopenBtn.textContent = 'Reopening...';
      try {
        await Api.post('/admin/users/' + user.id + '/reopen-onboarding', {});
        status.style.color = '#16A34A';
        status.textContent = '✓ Wizard reopened. User will see it on next sign-in.';
      } catch (e) {
        status.style.color = '#DC2626';
        status.textContent = 'Failed: ' + (e.message || 'error');
      } finally {
        reopenBtn.disabled = false; reopenBtn.textContent = '🪄 Reopen onboarding';
      }
    });
    actionRow.appendChild(reopenBtn);

    const resendBtn = Dom.el('button', {
      style: 'padding: 8px 14px; background: white; color: #1F6080; border: 1.5px solid #1F6080; border-radius: 8px; font-size: 13px; font-weight: 600; cursor: pointer;',
    }, '✉ Resend welcome');
    resendBtn.addEventListener('click', async () => {
      if (!confirm('Resend the welcome invite to ' + user.email + '? A new temporary password will be generated.')) return;
      resendBtn.disabled = true; resendBtn.textContent = 'Sending...';
      try {
        const r = await Api.post('/admin/users/' + user.id + '/resend-welcome', {});
        status.style.color = '#16A34A';
        status.textContent = r.email_sent
          ? '✓ Welcome email sent.'
          : ('Email failed — share temp password manually: ' + r.temp_password);
      } catch (e) {
        status.style.color = '#DC2626';
        status.textContent = 'Resend failed: ' + (e.message || 'error');
      } finally {
        resendBtn.disabled = false; resendBtn.textContent = '✉ Resend welcome';
      }
    });
    actionRow.appendChild(resendBtn);

    const deleteBtn = Dom.el('button', {
      style: 'padding: 8px 14px; background: white; color: #B91C1C; border: 1.5px solid #FCA5A5; border-radius: 8px; font-size: 13px; font-weight: 600; cursor: pointer; margin-left: auto;',
    }, '🗑 Delete user');
    deleteBtn.addEventListener('click', async () => {
      const c1 = confirm('Delete ' + user.name + ' (' + user.email + ')?\n\nThey will be unable to sign in. Their family/child records stay intact for audit.');
      if (!c1) return;
      const c2 = prompt('Type "delete" to confirm:');
      if (c2 !== 'delete') return;
      deleteBtn.disabled = true; deleteBtn.textContent = 'Deleting...';
      try {
        await Api.delete('/admin/users/' + user.id);
        await renderUsersTab(content);
        Shell.Modal.close();
      } catch (e) {
        status.style.color = '#DC2626';
        status.textContent = 'Delete failed: ' + (e.message || 'error');
        deleteBtn.disabled = false; deleteBtn.textContent = '🗑 Delete user';
      }
    });
    actionRow.appendChild(deleteBtn);

    danger.appendChild(actionRow);
    body.appendChild(danger);

    Shell.Modal.open({
      title: 'Manage ' + user.name,
      body: body,
      actions: [{
        label: 'Save changes',
        primary: true,
        onClick: async () => {
          const data = {};
          Object.keys(inputs).forEach(k => { data[k] = inputs[k].value; });
          status.style.color = '#1F6080';
          status.textContent = 'Saving...';
          try {
            await Api.patch('/admin/users/' + user.id, data);
            await renderUsersTab(content);
            Shell.Modal.close();
          } catch (e) {
            status.style.color = '#DC2626';
            status.textContent = 'Save failed: ' + (e.message || 'error');
          }
        },
      }],
    });
  }

  // ════════════════════════════════════════════════════════════════
  //   FAMILIES TAB
  // ════════════════════════════════════════════════════════════════
  async function renderFamiliesTab(content) {
    Dom.clear(content);
    content.appendChild(loading('Loading families...'));

    let data;
    try {
      data = await Api.get('/admin/families');
    } catch (e) {
      Dom.clear(content);
      content.appendChild(errorBox('Could not load families: ' + (e.message || 'error')));
      return;
    }

    Dom.clear(content);

    content.appendChild(Dom.el('div', { style: 'color: var(--ink-500); font-size: 14px; margin-bottom: 16px;' },
      data.families.length + ' famil' + (data.families.length === 1 ? 'y' : 'ies')));

    if (data.families.length === 0) {
      content.appendChild(emptyMsg('No families enrolled yet.'));
      return;
    }

    const grid = Dom.el('div', { style: 'display: grid; grid-template-columns: repeat(auto-fill, minmax(360px, 1fr)); gap: 16px;' });
    data.families.forEach(f => {
      const card = Dom.el('div', { style: 'background: white; padding: 18px; border-radius: 12px; box-shadow: 0 1px 3px rgba(0,0,0,0.04); cursor: pointer;' });
      card.appendChild(Dom.el('div', { style: 'font-size: 17px; font-weight: 700; margin-bottom: 4px;' }, f.family_name));
      card.appendChild(Dom.el('div', { style: 'color: var(--ink-500); font-size: 13px; margin-bottom: 12px;' }, f.centre_name || '—'));

      const stats = Dom.el('div', { style: 'display: flex; gap: 16px; font-size: 13px; color: var(--ink-700);' });
      stats.appendChild(Dom.el('span', {}, '👶 ' + f.child_count + ' children'));
      stats.appendChild(Dom.el('span', {}, '👤 ' + f.guardian_count + ' guardians'));
      card.appendChild(stats);

      if (f.outstanding_balance > 0) {
        card.appendChild(Dom.el('div', { style: 'margin-top: 10px; color: #DC2626; font-weight: 600; font-size: 13px;' },
          '⚠ $' + f.outstanding_balance.toFixed(2) + ' outstanding'));
      }

      card.addEventListener('click', () => showFamilyDetail(f.id));
      grid.appendChild(card);
    });
    content.appendChild(grid);
  }

  async function showFamilyDetail(familyId) {
    Shell.Modal.open({
      title: 'Family details',
      body: loading('Loading...'),
      large: true,
    });
    try {
      const data = await Api.get('/admin/families/' + familyId);
      const body = Dom.el('div', {});

      body.appendChild(Dom.el('h3', { style: 'font-size: 18px; margin: 0 0 12px;' }, data.family.family_name));
      body.appendChild(Dom.el('div', { style: 'color: var(--ink-500); margin-bottom: 4px;' }, '📧 ' + (data.family.primary_email || '—')));
      body.appendChild(Dom.el('div', { style: 'color: var(--ink-500); margin-bottom: 16px;' }, '📞 ' + (data.family.primary_phone || '—')));

      body.appendChild(Dom.el('h4', { style: 'font-size: 14px; font-weight: 700; margin: 16px 0 8px; letter-spacing: 0.5px; color: var(--ink-700);' }, 'CHILDREN'));
      if (data.children.length === 0) {
        body.appendChild(Dom.el('div', { style: 'color: var(--ink-500); font-size: 13px;' }, 'No children'));
      } else {
        data.children.forEach(c => {
          const row = Dom.el('div', { style: 'padding: 10px; background: var(--ink-50); border-radius: 6px; margin-bottom: 6px;' });
          row.appendChild(Dom.el('div', { style: 'font-weight: 600;' }, c.preferred_name || c.first_name + ' ' + c.last_name));
          row.appendChild(Dom.el('div', { style: 'font-size: 13px; color: var(--ink-500);' }, 'Born ' + c.date_of_birth + ' · ' + c.enrollment_status));
          body.appendChild(row);
        });
      }

      body.appendChild(Dom.el('h4', { style: 'font-size: 14px; font-weight: 700; margin: 16px 0 8px; letter-spacing: 0.5px; color: var(--ink-700);' }, 'GUARDIANS'));
      data.guardians.forEach(g => {
        const row = Dom.el('div', { style: 'padding: 10px; background: var(--ink-50); border-radius: 6px; margin-bottom: 6px;' });
        row.appendChild(Dom.el('div', { style: 'font-weight: 600;' }, g.first_name + ' ' + g.last_name + (g.is_primary ? ' (primary)' : '')));
        row.appendChild(Dom.el('div', { style: 'font-size: 13px; color: var(--ink-500);' }, g.email + ' · ' + g.relationship));
        body.appendChild(row);
      });

      Shell.Modal.open({ title: data.family.family_name, body: body, large: true });
    } catch (e) {
      Shell.Modal.open({ title: 'Error', body: Dom.el('div', {}, e.message || 'Could not load family') });
    }
  }

  // ════════════════════════════════════════════════════════════════
  //   BRANDING TAB
  // ════════════════════════════════════════════════════════════════
  async function renderBrandingTab(content) {
    Dom.clear(content);
    content.appendChild(loading('Loading branding...'));

    let data;
    try {
      data = await Api.get('/branding');
    } catch (e) {
      Dom.clear(content);
      content.appendChild(errorBox('Could not load branding: ' + (e.message || 'error')));
      return;
    }

    Dom.clear(content);
    const b = data.branding;

    const grid = Dom.el('div', { style: 'display: grid; grid-template-columns: 1fr 380px; gap: 24px;' });

    // ─── Left column: form
    const form = Dom.el('div', { style: 'background: white; padding: 24px; border-radius: 12px; box-shadow: 0 1px 3px rgba(0,0,0,0.04);' });
    form.appendChild(Dom.el('h3', { style: 'margin: 0 0 16px; font-size: 18px;' }, 'White-label settings'));

    const fields = [
      { key: 'product_name', label: 'Product name', value: b.product_name },
      { key: 'tagline', label: 'Tagline', value: b.tagline },
      { key: 'login_subtitle', label: 'Login page subtitle', value: b.login_subtitle, textarea: true },
      { key: 'support_email', label: 'Support email', type: 'email', value: b.support_email },
      { key: 'email_from_name', label: 'Email "from" name', value: b.email_from_name },
    ];

    const inputs = {};
    fields.forEach(f => {
      const wrap = Dom.el('div', { style: 'margin-bottom: 14px;' });
      wrap.appendChild(Dom.el('label', { style: 'display: block; font-size: 13px; font-weight: 600; margin-bottom: 4px;' }, f.label));
      const input = f.textarea
        ? Dom.el('textarea', { style: 'width: 100%; padding: 8px 12px; border: 1px solid var(--ink-300); border-radius: 6px; font-size: 14px; min-height: 60px; box-sizing: border-box; font-family: inherit;' })
        : Dom.el('input', { type: f.type || 'text', style: 'width: 100%; padding: 8px 12px; border: 1px solid var(--ink-300); border-radius: 6px; font-size: 14px; box-sizing: border-box;' });
      input.value = f.value || '';
      input.addEventListener('input', () => updatePreview());
      inputs[f.key] = input;
      wrap.appendChild(input);
      form.appendChild(wrap);
    });

    // Colors
    const colorsWrap = Dom.el('div', { style: 'display: grid; grid-template-columns: 1fr 1fr; gap: 12px; margin-bottom: 14px;' });
    ['primary_color', 'accent_color'].forEach(k => {
      const colW = Dom.el('div', {});
      colW.appendChild(Dom.el('label', { style: 'display: block; font-size: 13px; font-weight: 600; margin-bottom: 4px;' }, k.replace('_', ' ')));
      const ci = Dom.el('input', { type: 'color', style: 'width: 100%; height: 40px; border: 1px solid var(--ink-300); border-radius: 6px; cursor: pointer;' });
      ci.value = b[k];
      ci.addEventListener('input', () => updatePreview());
      inputs[k] = ci;
      colW.appendChild(ci);
      colorsWrap.appendChild(colW);
    });
    form.appendChild(colorsWrap);

    // Logo upload
    const logoWrap = Dom.el('div', { style: 'margin-bottom: 14px;' });
    logoWrap.appendChild(Dom.el('label', { style: 'display: block; font-size: 13px; font-weight: 600; margin-bottom: 4px;' }, 'Logo (PNG/SVG, max 2MB)'));
    const fileInput = Dom.el('input', { type: 'file', accept: 'image/png,image/jpeg,image/svg+xml' });
    fileInput.style = 'width: 100%; padding: 8px; border: 1px dashed var(--ink-300); border-radius: 6px;';
    const logoStatus = Dom.el('div', { style: 'font-size: 12px; margin-top: 4px;' });
    fileInput.addEventListener('change', async () => {
      if (!fileInput.files[0]) return;
      logoStatus.style.color = '#1F6080';
      logoStatus.textContent = 'Uploading...';
      const fd = new FormData();
      fd.append('logo', fileInput.files[0]);
      fd.append('kind', 'logo');
      try {
        const res = await Api.postForm('/admin/branding/logo', fd);
        logoStatus.style.color = '#16A34A';
        logoStatus.textContent = '✓ Uploaded';
        b.logo_url = res.url;
        updatePreview();
      } catch (e) {
        logoStatus.style.color = '#DC2626';
        logoStatus.textContent = 'Upload failed: ' + (e.message || 'error');
      }
    });
    logoWrap.appendChild(fileInput);
    logoWrap.appendChild(logoStatus);
    form.appendChild(logoWrap);

    const saveStatus = Dom.el('div', { style: 'min-height: 20px; font-size: 13px; margin: 8px 0;' });
    form.appendChild(saveStatus);

    const saveBtn = Dom.el('button', { style: btnPrimary() }, 'Save branding');
    saveBtn.addEventListener('click', async () => {
      const payload = {};
      Object.keys(inputs).forEach(k => { payload[k] = inputs[k].value; });
      saveStatus.style.color = '#1F6080';
      saveStatus.textContent = 'Saving...';
      try {
        await Api.put('/admin/branding', payload);
        saveStatus.style.color = '#16A34A';
        saveStatus.textContent = '✓ Saved. Refresh the page to see brand applied across screens.';
        // Apply immediately
        if (window.KT.applyBranding) window.KT.applyBranding(payload);
      } catch (e) {
        saveStatus.style.color = '#DC2626';
        saveStatus.textContent = 'Save failed: ' + (e.message || 'error');
      }
    });
    form.appendChild(saveBtn);
    grid.appendChild(form);

    // ─── Right column: live preview
    const preview = Dom.el('div', { style: 'background: white; padding: 24px; border-radius: 12px; box-shadow: 0 1px 3px rgba(0,0,0,0.04); position: sticky; top: 20px; align-self: start;' });
    preview.appendChild(Dom.el('h3', { style: 'margin: 0 0 16px; font-size: 14px; color: var(--ink-500); letter-spacing: 1px;' }, 'LIVE PREVIEW'));
    const previewBox = Dom.el('div', { id: 'branding-preview-box' });
    preview.appendChild(previewBox);
    grid.appendChild(preview);

    content.appendChild(grid);

    function updatePreview() {
      Dom.clear(previewBox);
      const primary = inputs.primary_color.value;
      const accent = inputs.accent_color.value;

      const loginPreview = Dom.el('div', { style: 'background: linear-gradient(135deg, ' + primary + ' 0%, ' + accent + ' 100%); padding: 24px; border-radius: 8px; color: white; margin-bottom: 16px;' });
      if (b.logo_url) {
        loginPreview.appendChild(Dom.el('img', { src: b.logo_url, style: 'max-height: 40px; margin-bottom: 12px;' }));
      }
      loginPreview.appendChild(Dom.el('div', { style: 'font-size: 20px; font-weight: 800;' }, inputs.product_name.value || 'Kiddietrac'));
      loginPreview.appendChild(Dom.el('div', { style: 'font-size: 13px; opacity: 0.9; margin-top: 4px;' }, inputs.tagline.value || ''));
      previewBox.appendChild(loginPreview);

      previewBox.appendChild(Dom.el('div', { style: 'font-size: 13px; color: var(--ink-500); margin-bottom: 6px;' }, 'Login subtitle:'));
      previewBox.appendChild(Dom.el('div', { style: 'font-size: 14px; padding: 12px; background: var(--ink-50); border-radius: 6px; margin-bottom: 12px;' }, inputs.login_subtitle.value || '—'));

      const btn = Dom.el('div', { style: 'background: ' + primary + '; color: white; padding: 10px 16px; border-radius: 8px; display: inline-block; font-weight: 700; font-size: 14px;' }, 'Sample button');
      previewBox.appendChild(btn);
    }
    updatePreview();
  }

  // ════════════════════════════════════════════════════════════════
  //   BILLING TAB (Stripe Connect)
  // ════════════════════════════════════════════════════════════════
  async function renderBillingTab(content) {
    Dom.clear(content);
    content.appendChild(loading('Loading billing...'));

    let data;
    try {
      data = await Api.get('/admin/billing/status');
    } catch (e) {
      Dom.clear(content);
      content.appendChild(errorBox('Could not load billing: ' + (e.message || 'error')));
      return;
    }

    Dom.clear(content);

    if (!data.configured) {
      const card = Dom.el('div', { style: 'background: #FFFBEB; border: 1px solid #FCD34D; padding: 20px; border-radius: 12px;' });
      card.appendChild(Dom.el('h3', { style: 'margin: 0 0 8px; color: #92400E;' }, 'Stripe not configured'));
      card.appendChild(Dom.el('div', { style: 'color: #92400E; font-size: 14px;' },
        'To enable reseller billing, add the following to your .env file and restart:'));
      const code = Dom.el('pre', { style: 'background: #1F2937; color: #F9FAFB; padding: 12px; border-radius: 6px; margin-top: 12px; font-size: 12px; overflow-x: auto;' },
        'STRIPE_SECRET_KEY=sk_live_...\nSTRIPE_PLATFORM_FEE_PCT=10\nSTRIPE_MONTHLY_PRICE_ID=price_...\nSTRIPE_WEBHOOK_SECRET=whsec_...\nSTRIPE_CONNECT_RETURN_URL=https://app.kiddietrac.com/dashboard.html#admin/billing\nSTRIPE_CONNECT_REFRESH_URL=https://app.kiddietrac.com/dashboard.html#admin/billing');
      card.appendChild(code);
      content.appendChild(card);
      return;
    }

    // Billing summary card
    const summary = Dom.el('div', { style: 'background: white; padding: 24px; border-radius: 12px; box-shadow: 0 1px 3px rgba(0,0,0,0.04); margin-bottom: 16px;' });
    summary.appendChild(Dom.el('h3', { style: 'margin: 0 0 16px; font-size: 18px;' }, 'Subscription'));

    const grid = Dom.el('div', { style: 'display: grid; grid-template-columns: repeat(3, 1fr); gap: 16px; margin-bottom: 16px;' });
    grid.appendChild(billingStat('Billing status', data.billing_status));
    grid.appendChild(billingStat('Connect status', data.connect_status));
    grid.appendChild(billingStat('Platform fee', data.platform_fee_pct + '%'));
    summary.appendChild(grid);

    // Action buttons depending on state
    const actions = Dom.el('div', { style: 'display: flex; gap: 8px; flex-wrap: wrap; margin-top: 12px;' });

    if (data.connect_status === 'not_connected' || !data.connect_id) {
      const connectBtn = Dom.el('button', { style: btnPrimary() }, 'Connect Stripe account');
      connectBtn.addEventListener('click', async () => {
        connectBtn.disabled = true;
        connectBtn.textContent = 'Creating link...';
        try {
          const res = await Api.post('/admin/billing/connect', {});
          window.location.href = res.onboarding_url;
        } catch (e) {
          alert('Connect failed: ' + (e.message || 'error'));
          connectBtn.disabled = false;
          connectBtn.textContent = 'Connect Stripe account';
        }
      });
      actions.appendChild(connectBtn);
    } else if (!data.subscription_id) {
      const subBtn = Dom.el('button', { style: btnPrimary() }, 'Activate subscription');
      subBtn.addEventListener('click', async () => {
        subBtn.disabled = true;
        subBtn.textContent = 'Activating...';
        try {
          await Api.post('/admin/billing/subscribe', {});
          await renderBillingTab(content);
        } catch (e) {
          alert('Subscribe failed: ' + (e.message || 'error'));
          subBtn.disabled = false;
          subBtn.textContent = 'Activate subscription';
        }
      });
      actions.appendChild(subBtn);
    } else {
      summary.appendChild(Dom.el('div', { style: 'padding: 12px; background: #ECFDF5; color: #065F46; border-radius: 6px; font-size: 14px;' },
        '✓ Subscription active (ID: ' + data.subscription_id + ', status: ' + data.subscription_status + ')'));

      const cancelBtn = Dom.el('button', { style: btnSecondary() }, 'Cancel subscription');
      cancelBtn.addEventListener('click', async () => {
        if (!confirm('Cancel your subscription? This will suspend the account at the end of the billing period.')) return;
        try {
          await Api.post('/admin/billing/cancel', {});
          await renderBillingTab(content);
        } catch (e) {
          alert('Cancel failed: ' + (e.message || 'error'));
        }
      });
      actions.appendChild(cancelBtn);
    }

    summary.appendChild(actions);
    content.appendChild(summary);

    // Info card
    const info = Dom.el('div', { style: 'background: white; padding: 20px; border-radius: 12px; box-shadow: 0 1px 3px rgba(0,0,0,0.04); font-size: 14px; line-height: 1.6;' });
    info.appendChild(Dom.el('h4', { style: 'margin: 0 0 8px; font-size: 14px; color: var(--ink-700); text-transform: uppercase; letter-spacing: 0.5px;' }, 'How reseller billing works'));
    info.appendChild(Dom.el('div', { style: 'color: var(--ink-700);' },
      'Each agency connects their own Stripe account (Connect Express). The platform charges a fixed monthly subscription with a ' + data.platform_fee_pct + '% application fee. The agency receives the rest. All payments flow through Stripe — Kiddietrac never touches funds directly.'));
    content.appendChild(info);
  }

  // ════════════════════════════════════════════════════════════════
  //   HELPERS
  // ════════════════════════════════════════════════════════════════
  function loading(text) {
    return Dom.el('div', { style: 'padding: 40px; text-align: center; color: var(--ink-500);' }, text);
  }
  function errorBox(text) {
    return Dom.el('div', { style: 'padding: 24px; background: #FEF2F2; color: #991B1B; border-radius: 8px;' }, text);
  }
  function emptyMsg(text) {
    return Dom.el('div', { style: 'padding: 40px; text-align: center; color: var(--ink-500); background: white; border-radius: 12px;' }, text);
  }
  function btnPrimary() {
    return 'background: var(--brand-blue, #1F6080); color: white; border: none; padding: 10px 16px; border-radius: 8px; font-weight: 700; cursor: pointer; font-size: 14px;';
  }
  function btnSecondary() {
    return 'background: transparent; color: #DC2626; border: 1px solid #DC2626; padding: 10px 16px; border-radius: 8px; font-weight: 600; cursor: pointer; font-size: 14px;';
  }
  // v22p3.4: 64px centre logo preview (image if logo_url set, else placeholder)
  function renderCentreLogoPreview(centre) {
    var wrap = Dom.el('div', {
      style: 'flex-shrink:0;width:64px;height:64px;border-radius:12px;overflow:hidden;background:' + (centre && centre.brand_color || '#E5E7EB') + ';color:white;display:flex;align-items:center;justify-content:center;font-weight:800;font-size:24px;box-shadow:0 1px 3px rgba(0,0,0,0.1);',
    });
    if (centre && centre.logo_url) {
      var img = Dom.el('img', {
        src: avatarSrc(centre.logo_url),
        alt: centre.name || '',
        style: 'width:100%;height:100%;object-fit:contain;background:white;display:block;',
      });
      img.addEventListener('error', function () { img.remove(); wrap.textContent = (centre.name || '?').charAt(0).toUpperCase(); });
      wrap.appendChild(img);
    } else {
      wrap.textContent = (centre && centre.name ? centre.name.charAt(0).toUpperCase() : '?');
    }
    return wrap;
  }

  // v22p3.2: avatar circle (image if photo_url set, else colored initials)
  function avatarCircle(u, size) {
    size = size || 36;
    const wrap = Dom.el('div', {
      style: 'flex-shrink:0;width:' + size + 'px;height:' + size + 'px;border-radius:50%;overflow:hidden;background:' + avatarColor(u) + ';color:white;display:flex;align-items:center;justify-content:center;font-weight:700;font-size:' + Math.round(size * 0.4) + 'px;letter-spacing:0.3px;box-shadow:0 1px 3px rgba(0,0,0,0.1);',
    });
    if (u && u.photo_url) {
      const img = Dom.el('img', {
        src: avatarSrc(u.photo_url),
        alt: u.name || '',
        style: 'width:100%;height:100%;object-fit:cover;display:block;',
      });
      img.addEventListener('error', () => {
        img.remove();
        wrap.textContent = avatarInitials(u);
      });
      wrap.appendChild(img);
    } else {
      wrap.textContent = avatarInitials(u);
    }
    return wrap;
  }
  function avatarInitials(u) {
    const n = (u && (u.name || ((u.first_name || '') + ' ' + (u.last_name || '')))) || '';
    const parts = n.trim().split(/\s+/).slice(0, 2);
    return parts.map(p => p.charAt(0).toUpperCase()).join('') || '?';
  }
  function avatarColor(u) {
    const palette = ['#1F6080', '#8EC73C', '#F59E0B', '#DC2626', '#7C3AED', '#0891B2', '#16A34A', '#DB2777'];
    const id = (u && u.id) || 0;
    return palette[Math.abs(id) % palette.length];
  }
  function avatarSrc(photoUrl) {
    if (!photoUrl) return '';
    // photo_url is stored as '/storage/avatars/{uuid}.jpg' relative to api host.
    // The app.kiddietrac.com host doesn't have /storage, so prefix with api host.
    if (/^https?:\/\//i.test(photoUrl)) return photoUrl;
    const base = (window.KT && window.KT.API_BASE) || 'https://api.kiddietrac.com/api/v1';
    const apiHost = base.replace(/\/api\/v1\/?$/, '');
    return apiHost + photoUrl;
  }

  function statusBadge(status) {
    const colors = {
      active: ['#DCFCE7', '#166534'],
      onboarding: ['#FEF3C7', '#92400E'],
      paused: ['#FEE2E2', '#991B1B'],
      closed: ['#F3F4F6', '#374151'],
      invited: ['#DBEAFE', '#1E40AF'],
      suspended: ['#FEE2E2', '#991B1B'],
      deactivated: ['#F3F4F6', '#374151'],
    };
    const c = colors[status] || ['#F3F4F6', '#374151'];
    return Dom.el('span', {
      style: 'display: inline-block; background: ' + c[0] + '; color: ' + c[1] + '; padding: 3px 10px; border-radius: 12px; font-size: 11px; font-weight: 700; letter-spacing: 0.5px; text-transform: uppercase;',
    }, status);
  }
  function billingStat(label, value) {
    const w = Dom.el('div', { style: 'background: var(--ink-50, #F9FAFB); padding: 14px; border-radius: 8px;' });
    w.appendChild(Dom.el('div', { style: 'font-size: 11px; font-weight: 700; color: var(--ink-500); letter-spacing: 0.5px; margin-bottom: 4px;' }, label));
    w.appendChild(Dom.el('div', { style: 'font-size: 16px; font-weight: 700;' }, value || '—'));
    return w;
  }

  // Expose
  window.KT = window.KT || {};
  window.KT.renderAdmin = renderAdmin;

  Shell.registerScreen('agency_admin:admin', renderAdmin);
  // v22p2.3: register deep-link hashes per tab so nav entries can land on a specific tab.
  ['centres', 'users', 'families', 'branding', 'billing'].forEach(function (tab) {
    Shell.registerScreen('agency_admin:admin-' + tab, function (main, ctx) {
      state.activeTab = tab;
      return renderAdmin(main, ctx);
    });
  });
})(window);
