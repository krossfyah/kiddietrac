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
    const wrap = Dom.el('div', { style: 'max-width: 1800px; margin: 0 auto; padding: 24px;' });
    main.appendChild(wrap);

    // Header
    const header = Dom.el('div', { style: 'margin-bottom: 24px;' });
    header.appendChild(Dom.el('h1', { style: 'font-size: 28px; font-weight: 800; margin: 0;' }, 'Admin'));
    header.appendChild(Dom.el('div', { style: 'color: var(--ink-500); margin-top: 4px;' }, 'Manage centres, users, families, and branding'));
    wrap.appendChild(header);

    // Tab strip
    const tabs = Dom.el('div', { style: 'display: flex; gap: 4px; margin-bottom: 24px; border-bottom: 1px solid var(--ink-200, #E5E7EB); overflow-x: auto; overflow-y: hidden;' });

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

    tabs.appendChild(tabBtn('centres', '🏫 Centres / Rooms'));
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

    // v22p12.1: tab hero
    content.appendChild(tabHero(
      '🏫 Centres',
      data.centres.length + ' centre' + (data.centres.length === 1 ? '' : 's') + ' in your agency. Click any row to edit branding, kiosk, or status.',
      'cloudsAndStars'
    ));

    // v22p83: agency-wide Country & compliance setup (currency + regulations)
    await renderCountryCard(content);

    // Action bar
    const bar = Dom.el('div', { style: 'display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px;' });
    bar.appendChild(Dom.el('div', { style: 'color: var(--ink-500); font-size: 13px;' }, 'All your locations'));
    const addBtn = Dom.el('button', { style: btnPrimary() }, '+ Add centre');
    addBtn.addEventListener('click', () => showCentreModal(null, content));

    // v22p30: card/table view toggle, mirrors Families + Children. Default 'table'
    // for centres because the column-dense data (capacity %, enrolled, families,
    // staff) reads better in a grid.
    const toggle = viewToggle('kt_view_centres', function () { renderCentresTab(content); }, 'table');
    bar.appendChild(toggle);
    bar.appendChild(addBtn);
    content.appendChild(bar);

    if (data.centres.length === 0) {
      content.appendChild(emptyMsg(
        'Click + Add centre to create your first location. You can configure rooms, staff, and the parent-facing kiosk from there.',
        { title: 'No centres yet', illustration: 'emptyFamilies' }
      ));
      return;
    }

    var view = localStorage.getItem('kt_view_centres') || 'table';
    if (view === 'cards') {
      content.appendChild(renderCentresCards(data.centres, content));
      maybeAutoOpenCentre(data.centres, content);
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
      if (isUsableLogoUrl(c.logo_url)) {
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

    maybeAutoOpenCentre(data.centres, content);
  }

  // v22p4.1 (extracted v22p30): if the agency overview routed here with
  // kt_admin_open_centre set, auto-open the centre's edit modal. Consume + clear
  // the flag so it only fires once. Lifted into a helper so both the table and
  // the new card view can call it.
  function maybeAutoOpenCentre(centres, content) {
    var autoOpen = sessionStorage.getItem('kt_admin_open_centre');
    if (!autoOpen) return;
    sessionStorage.removeItem('kt_admin_open_centre');
    var target = centres.find(function (c) { return String(c.id) === String(autoOpen); });
    if (target) setTimeout(function () { showCentreModal(target, content); }, 50);
  }

  // v22p30: card-grid alternative to the table. Same data, larger touch targets,
  // brand colour as the card's left rail. Best on tablets and for at-a-glance
  // visual scanning when you have fewer than ~12 centres.
  function renderCentresCards(centres, content) {
    var grid = Dom.el('div', { style: 'display:grid;grid-template-columns:repeat(auto-fill, minmax(320px, 1fr));gap:16px;' });
    centres.forEach(function (c) {
      var accent = c.brand_color || '#1F6080';
      var card = Dom.el('div', { style: 'background:white;border-radius:12px;box-shadow:0 1px 3px rgba(0,0,0,0.05);overflow:hidden;cursor:pointer;border-left:6px solid ' + accent + ';position:relative;' });

      var editBtn = Dom.el('button', {
        style: 'position:absolute;top:10px;right:10px;background:transparent;border:1px solid var(--ink-300);padding:4px 10px;border-radius:6px;cursor:pointer;font-size:12px;color:var(--ink-700);z-index:2;',
      }, 'Edit');
      editBtn.addEventListener('click', function (e) { e.stopPropagation(); showCentreModal(c, content); });
      card.appendChild(editBtn);

      // header — logo + name + city
      var head = Dom.el('div', { style: 'display:flex;align-items:center;gap:12px;padding:16px 16px 10px;padding-right:70px;' });
      var logo = Dom.el('div', {
        style: 'flex-shrink:0;width:44px;height:44px;border-radius:10px;overflow:hidden;background:' + accent + ';color:white;display:flex;align-items:center;justify-content:center;font-weight:800;font-size:18px;',
      });
      if (isUsableLogoUrl(c.logo_url)) {
        var img = Dom.el('img', { src: avatarSrc(c.logo_url), alt: c.name || '', style: 'width:100%;height:100%;object-fit:contain;background:white;' });
        img.addEventListener('error', function () { img.remove(); logo.textContent = (c.name || '?').charAt(0).toUpperCase(); });
        logo.appendChild(img);
      } else {
        logo.textContent = (c.name || '?').charAt(0).toUpperCase();
      }
      head.appendChild(logo);
      var stack = Dom.el('div');
      stack.appendChild(Dom.el('div', { style: 'font-size:16px;font-weight:700;line-height:1.2;' }, c.name));
      stack.appendChild(Dom.el('div', { style: 'font-size:12px;color:var(--ink-500);margin-top:3px;' }, c.city || '—'));
      head.appendChild(stack);
      card.appendChild(head);

      // status + capacity strip
      var strip = Dom.el('div', { style: 'display:flex;align-items:center;gap:10px;padding:0 16px 12px;flex-wrap:wrap;' });
      strip.appendChild(statusBadge(c.status));
      var pct = Number(c.capacity_pct || 0);
      var pctColor = pct >= 95 ? '#DC2626' : (pct >= 80 ? '#F59E0B' : '#16A34A');
      strip.appendChild(Dom.el('span', { style: 'font-size:12px;font-weight:700;color:' + pctColor + ';' }, pct + '% full'));
      card.appendChild(strip);

      // stats grid
      var stats = Dom.el('div', { style: 'display:grid;grid-template-columns:repeat(3, 1fr);gap:0;border-top:1px solid var(--ink-100,#E5E7EB);background:#FAFBFC;' });
      function statCell(label, value) {
        var cell = Dom.el('div', { style: 'padding:10px 8px;text-align:center;' });
        cell.appendChild(Dom.el('div', { style: 'font-size:18px;font-weight:800;color:var(--ink-700);' }, String(value)));
        cell.appendChild(Dom.el('div', { style: 'font-size:10px;color:var(--ink-500);text-transform:uppercase;letter-spacing:0.5px;margin-top:2px;' }, label));
        return cell;
      }
      stats.appendChild(statCell('Enrolled', (c.enrolled_count || 0) + ' / ' + (c.license_capacity || 0)));
      stats.appendChild(statCell('Families', c.family_count || 0));
      stats.appendChild(statCell('Staff', c.staff_count || 0));
      card.appendChild(stats);

      card.addEventListener('click', function () { showCentreModal(c, content); });
      grid.appendChild(card);
    });
    return grid;
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

    // v22p5.1: kiosk mode section — visible only when editing an existing centre.
    if (isEdit) {
      form.appendChild(Dom.el('div', {
        style: 'font-size:11px;font-weight:800;color:var(--ink-500);letter-spacing:1px;text-transform:uppercase;margin:18px 0 6px;padding-top:14px;border-top:1px solid var(--ink-100,#E5E7EB);',
      }, '🛡 Kiosk mode'));

      const kioskState = { enabled: !!centre.kiosk_enabled, token: centre.kiosk_token || '' };

      const kioskUrlFor = function (token) {
        return token ? (window.location.origin + '/kiosk.html?token=' + token) : '';
      };

      // Toggle row
      const togRow = Dom.el('div', { style: 'display:flex;align-items:center;gap:10px;margin-bottom:10px;' });
      const togIn = Dom.el('input', { type: 'checkbox' });
      togIn.checked = kioskState.enabled;
      const togLabel = Dom.el('label', { style: 'font-size:13px;color:var(--ink-700,#374151);user-select:none;cursor:pointer;' });
      togLabel.textContent = 'Enable kiosk for this centre';
      const togWrap = Dom.el('span', { style: 'display:flex;align-items:center;gap:6px;cursor:pointer;' });
      togWrap.appendChild(togIn);
      togWrap.appendChild(togLabel);
      togRow.appendChild(togWrap);
      const togMsg = Dom.el('span', { style: 'font-size:12px;color:var(--ink-500);' });
      togRow.appendChild(togMsg);
      form.appendChild(togRow);

      // URL + actions row (rebuilt on every state change)
      const kioskActions = Dom.el('div');
      form.appendChild(kioskActions);

      const renderKioskActions = function () {
        Dom.clear(kioskActions);
        if (!kioskState.enabled) {
          kioskActions.appendChild(Dom.el('p', {
            style: 'font-size:12px;color:var(--ink-500);margin:4px 0 12px;',
          }, 'When enabled, parents can sign children in or out via a tablet at the centre door using their 4-6 digit PIN.'));
          return;
        }
        const urlVal = kioskUrlFor(kioskState.token);
        const urlInput = Dom.el('input', {
          type: 'text', readonly: 'readonly', value: urlVal,
          placeholder: 'No token — click Rotate to generate one',
          style: 'flex:1;padding:7px 9px;border:1px solid #D1D5DB;border-radius:6px;font-size:12px;font-family:ui-monospace,monospace;background:#F9FAFB;',
        });
        const urlRow = Dom.el('div', { style: 'display:flex;gap:6px;margin-bottom:8px;' });
        urlRow.appendChild(urlInput);
        const copyBtn = Dom.el('button', {
          type: 'button',
          style: 'padding:7px 12px;background:white;color:#1F6080;border:1.5px solid #1F6080;border-radius:6px;font-size:12px;font-weight:600;cursor:pointer;',
        }, 'Copy URL');
        copyBtn.addEventListener('click', function () {
          if (!urlVal) return;
          if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(urlVal).then(function () { copyBtn.textContent = '✓ Copied'; setTimeout(function () { copyBtn.textContent = 'Copy URL'; }, 1500); });
          } else {
            urlInput.select(); document.execCommand('copy');
            copyBtn.textContent = '✓ Copied'; setTimeout(function () { copyBtn.textContent = 'Copy URL'; }, 1500);
          }
        });
        urlRow.appendChild(copyBtn);
        const rotBtn = Dom.el('button', {
          type: 'button',
          style: 'padding:7px 12px;background:#FEF3C7;color:#92400E;border:1.5px solid #FCD34D;border-radius:6px;font-size:12px;font-weight:600;cursor:pointer;',
        }, kioskState.token ? '↻ Rotate token' : 'Generate token');
        rotBtn.addEventListener('click', function () {
          if (kioskState.token && !window.confirm('Rotate the kiosk token? The current URL will stop working immediately — any tablet using the old link will need the new one.')) return;
          rotBtn.disabled = true; rotBtn.textContent = 'Rotating…';
          Api.post('/director/centres/' + centre.id + '/kiosk-token', {}).then(function (r) {
            kioskState.token = r.kiosk_token;
            renderKioskActions();
            if (Dom.toast) Dom.toast('Kiosk token rotated', 'success');
          }).catch(function (e) {
            rotBtn.disabled = false;
            rotBtn.textContent = '↻ Rotate token';
            alert('Could not rotate: ' + (e.message || 'server error'));
          });
        });
        urlRow.appendChild(rotBtn);
        kioskActions.appendChild(urlRow);

        // v22p85: QR check-in — scannable code + printable poster for the centre door.
        if (kioskState.token && window.KT && KT.qrImg) {
          var qrSteps = '<strong>1.</strong> Scan this code with your phone camera.<br>'
            + '<strong>2.</strong> Tap your child’s name.<br>'
            + '<strong>3.</strong> Enter your 4–6 digit PIN to sign in or out.';
          var qrRow = Dom.el('div', { style: 'display:flex;align-items:center;gap:14px;margin:6px 0 10px;padding:12px;border:1px solid var(--ink-100,#E5E7EB);border-radius:10px;background:#FAFCFD;' });
          var qrBox = Dom.el('div', { style: 'width:96px;height:96px;flex-shrink:0;display:flex;align-items:center;justify-content:center;color:#94A3B8;font-size:11px;' }, 'QR…');
          qrRow.appendChild(qrBox);
          var qrSide = Dom.el('div', { style: 'flex:1;min-width:0;' });
          qrSide.appendChild(Dom.el('div', { style: 'font-weight:700;font-size:13px;margin-bottom:2px;' }, '📲 QR check-in'));
          qrSide.appendChild(Dom.el('div', { style: 'font-size:12px;color:var(--ink-500);margin-bottom:8px;line-height:1.4;' }, 'Print and post this at the door — families scan it to sign children in or out, no tablet needed.'));
          var printBtn = Dom.el('button', {
            type: 'button',
            style: 'padding:7px 14px;background:#1F6080;color:white;border:none;border-radius:6px;font-size:12px;font-weight:600;cursor:pointer;',
          }, '🖨️ Print QR poster');
          printBtn.addEventListener('click', function () {
            KT.printQRPoster({
              title: centre.name || 'Centre check-in',
              subtitle: 'Scan to sign your child in or out',
              url: kioskUrlFor(kioskState.token),
              steps: qrSteps,
              footer: 'Powered by KiddieTrac',
            }).catch(function (e) { alert(e.message || 'Could not open the QR poster.'); });
          });
          qrSide.appendChild(printBtn);
          qrRow.appendChild(qrSide);
          kioskActions.appendChild(qrRow);
          // Render the inline preview thumbnail.
          KT.qrImg(kioskUrlFor(kioskState.token), { size: 96, cell: 4, margin: 2 })
            .then(function (img) { Dom.clear(qrBox); qrBox.appendChild(img); })
            .catch(function () { qrBox.textContent = 'QR unavailable'; });
        }

        kioskActions.appendChild(Dom.el('p', {
          style: 'font-size:11px;color:var(--ink-500);margin:4px 0 12px;line-height:1.4;',
        }, 'Open this URL on the tablet at your centre entrance, or print the QR poster above. Parents tap their child + enter their PIN to sign in or out. Set per-guardian PINs from the family detail (or via API).'));
      };

      togIn.addEventListener('change', function () {
        const desired = togIn.checked;
        togIn.disabled = true;
        togMsg.textContent = desired ? 'enabling…' : 'disabling…';
        Api.post('/director/centres/' + centre.id + '/kiosk-toggle', { enabled: desired }).then(function () {
          kioskState.enabled = desired;
          togMsg.textContent = '';
          togIn.disabled = false;
          // Auto-rotate to mint a token if enabling and none exists yet
          if (desired && !kioskState.token) {
            Api.post('/director/centres/' + centre.id + '/kiosk-token', {}).then(function (r) {
              kioskState.token = r.kiosk_token;
              renderKioskActions();
            });
          } else {
            renderKioskActions();
          }
        }).catch(function (e) {
          togIn.checked = !desired;
          togMsg.textContent = 'failed';
          togIn.disabled = false;
          alert('Could not toggle kiosk: ' + (e.message || 'server error'));
        });
      });

      renderKioskActions();
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

    // Danger zone — archive / permanently delete this centre (edit only).
    if (isEdit) {
      form.appendChild(Dom.el('div', { style: 'font-size:11px;font-weight:800;color:#B91C1C;letter-spacing:1px;text-transform:uppercase;margin:18px 0 8px;padding-top:14px;border-top:1px solid var(--ink-100,#E5E7EB);' }, '⚠ Danger zone'));
      const dz = Dom.el('div', { style: 'display:flex;gap:10px;flex-wrap:wrap;align-items:center;' });
      const dzMsg = Dom.el('span', { style: 'font-size:12px;color:var(--ink-500);' });
      const archiveBtn = Dom.el('button', { type: 'button', style: 'padding:8px 14px;background:#FFF7ED;color:#9A3412;border:1px solid #FED7AA;border-radius:8px;font-size:13px;font-weight:700;cursor:pointer;' }, 'Archive centre');
      const delBtn = Dom.el('button', { type: 'button', style: 'padding:8px 14px;background:#FEF2F2;color:#B91C1C;border:1px solid #FECACA;border-radius:8px;font-size:13px;font-weight:700;cursor:pointer;' }, 'Delete permanently');
      archiveBtn.addEventListener('click', async () => {
        if (!(window.KT && window.KT.confirm) || !await window.KT.confirm({ title: 'Archive “' + centre.name + '”?', description: 'It will be hidden from active centres but can be restored anytime from the Agency overview.' })) return;
        try { await Api.delete('/admin/centres/' + centre.id); await renderCentresTab(content); Shell.Modal.close(); if (window.KT.Dom && window.KT.Dom.toast) window.KT.Dom.toast('Centre archived', 'success'); }
        catch (e) { dzMsg.textContent = 'Could not archive: ' + (e.message || 'error'); dzMsg.style.color = '#DC2626'; }
      });
      delBtn.addEventListener('click', async () => {
        if (!(window.KT && window.KT.confirm) || !await window.KT.confirm({ title: 'Permanently delete “' + centre.name + '”?', description: 'This cannot be undone. Archive instead if you might need it back.', tone: 'danger' })) return;
        try { await Api.delete('/admin/centres/' + centre.id + '/permanent'); await renderCentresTab(content); Shell.Modal.close(); if (window.KT.Dom && window.KT.Dom.toast) window.KT.Dom.toast('Centre permanently deleted', 'success'); }
        catch (e) { dzMsg.textContent = (e.message || 'Could not delete'); dzMsg.style.color = '#DC2626'; }
      });
      dz.appendChild(archiveBtn); dz.appendChild(delBtn); dz.appendChild(dzMsg);
      form.appendChild(dz);
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
    let presenceSet = new Set();
    try {
      data = await Api.get('/admin/users');
      // v22p42: presence is a best-effort enrichment — if it 403s for a non-admin
      // we still render the user list without dots.
      try {
        const pres = await Api.get('/admin/presence');
        (pres.online || []).forEach(o => presenceSet.add(o.user_id));
      } catch (_) {}
    } catch (e) {
      Dom.clear(content);
      content.appendChild(errorBox('Could not load users: ' + (e.message || 'error')));
      return;
    }

    Dom.clear(content);

    // v22p12.1: tab hero
    content.appendChild(tabHero(
      '👥 User management',
      data.users.length + ' active user' + (data.users.length === 1 ? '' : 's') + '. Invite admins, directors, educators, or parents — each gets their own role-tailored portal.',
      'bear'
    ));

    const bar = Dom.el('div', { style: 'display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px; gap: 10px; flex-wrap: wrap;' });
    bar.appendChild(Dom.el('div', { style: 'color: var(--ink-500); font-size: 13px; flex: 1;' }, 'All accounts in your agency'));

    // v22p45: CSV download
    const csvBtn = Dom.el('button', { style: 'background: white; color: #16A34A; border: 1px solid #16A34A; padding: 8px 14px; border-radius: 8px; font-size: 12px; font-weight: 600; cursor: pointer;' }, '⤓ CSV');
    csvBtn.addEventListener('click', () => downloadCsv('/admin/users', 'users.csv', csvBtn));
    bar.appendChild(csvBtn);

    const addBtn = Dom.el('button', { style: btnPrimary() }, '+ Invite user');
    addBtn.addEventListener('click', () => showInviteModal(content));
    bar.appendChild(addBtn);
    content.appendChild(bar);

    // v22p45: bulk-action bar (hidden until a checkbox is ticked)
    const selectedIds = new Set();
    const bulkBar = Dom.el('div', { style: 'display: none; align-items: center; gap: 10px; background: #EFF6FF; border: 1px solid #BFDBFE; border-radius: 10px; padding: 10px 14px; margin-bottom: 16px;' });
    const bulkCount = Dom.el('div', { style: 'flex: 1; font-size: 13px; color: #1E40AF; font-weight: 600;' }, '0 selected');
    bulkBar.appendChild(bulkCount);
    const bulkResend = Dom.el('button', { style: 'background: white; color: #1E40AF; border: 1px solid #BFDBFE; padding: 6px 12px; border-radius: 8px; font-size: 12px; font-weight: 600; cursor: pointer;' }, 'Resend welcome');
    const bulkDelete = Dom.el('button', { style: 'background: white; color: #DC2626; border: 1px solid #FCA5A5; padding: 6px 12px; border-radius: 8px; font-size: 12px; font-weight: 600; cursor: pointer;' }, 'Delete');
    bulkBar.appendChild(bulkResend);
    bulkBar.appendChild(bulkDelete);
    content.appendChild(bulkBar);

    function refreshBulkBar() {
      const n = selectedIds.size;
      bulkBar.style.display = n > 0 ? 'flex' : 'none';
      bulkCount.textContent = n + ' selected';
    }

    async function bulkRun(label, fn) {
      const ids = Array.from(selectedIds);
      if (!ids.length) return;
      if (!confirm(label + ' for ' + ids.length + ' user' + (ids.length === 1 ? '' : 's') + '?')) return;
      bulkResend.disabled = true; bulkDelete.disabled = true;
      let ok = 0, fail = 0;
      for (const id of ids) { try { await fn(id); ok++; } catch (e) { fail++; } }
      bulkResend.disabled = false; bulkDelete.disabled = false;
      alert(label + ' done: ' + ok + ' succeeded, ' + fail + ' failed.');
      selectedIds.clear();
      await renderUsersTab(content);
    }
    bulkResend.addEventListener('click', () => bulkRun('Resend welcome', (id) => Api.post('/admin/users/' + id + '/resend-welcome', {})));
    bulkDelete.addEventListener('click', () => bulkRun('Delete', (id) => Api.delete('/admin/users/' + id)));

    if (data.users.length === 0) {
      content.appendChild(emptyMsg('No users yet.'));
      return;
    }

    const table = Dom.el('table', { style: 'width: 100%; background: white; border-radius: 12px; overflow: hidden; border-collapse: collapse; box-shadow: 0 1px 3px rgba(0,0,0,0.04);' });
    const thead = Dom.el('thead', { style: 'background: var(--ink-50, #F9FAFB);' });
    const headRow = Dom.el('tr', {});

    // v22p45: select-all checkbox
    const headCheck = Dom.el('th', { style: 'padding: 12px 8px 12px 16px; width: 32px;' });
    const selectAll = Dom.el('input', { type: 'checkbox', style: 'cursor: pointer; width: 16px; height: 16px;', title: 'Select all on this page' });
    headCheck.appendChild(selectAll);
    headRow.appendChild(headCheck);

    ['Name', 'Email', 'Roles', 'Status', 'Last login', ''].forEach(h => {
      headRow.appendChild(Dom.el('th', { style: 'text-align: left; padding: 12px 16px; font-size: 12px; font-weight: 700; color: var(--ink-500); text-transform: uppercase; letter-spacing: 0.5px;' }, h));
    });
    thead.appendChild(headRow);
    table.appendChild(thead);

    const tbody = Dom.el('tbody', {});

    // v22p45: track per-row checkboxes so select-all can flip them all
    const rowCheckboxes = [];
    selectAll.addEventListener('change', () => {
      rowCheckboxes.forEach(({ cb, id }) => {
        cb.checked = selectAll.checked;
        if (selectAll.checked) selectedIds.add(id); else selectedIds.delete(id);
      });
      refreshBulkBar();
    });

    data.users.forEach(u => {
      const row = Dom.el('tr', { style: 'border-top: 1px solid var(--ink-100, #E5E7EB);' });

      // v22p45: per-row checkbox (skip the caller's own user so admins can't
      // accidentally delete themselves in a bulk op)
      const checkCell = Dom.el('td', { style: 'padding: 14px 8px 14px 16px;' });
      const cb = Dom.el('input', { type: 'checkbox', style: 'cursor: pointer; width: 16px; height: 16px;' });
      const me = (function () { try { return (JSON.parse(sessionStorage.getItem('kt_user') || '{}')).id; } catch (e) { return null; } })();
      if (u.id === me) { cb.disabled = true; cb.title = "Can't bulk-act on yourself"; }
      cb.addEventListener('click', (e) => e.stopPropagation());
      cb.addEventListener('change', () => {
        if (cb.checked) selectedIds.add(u.id); else selectedIds.delete(u.id);
        refreshBulkBar();
      });
      checkCell.appendChild(cb);
      row.appendChild(checkCell);
      rowCheckboxes.push({ cb, id: u.id });
      // v22p3.2: name cell now includes a 32px avatar circle (image or initials)
      const nameCell = Dom.el('td', { style: 'padding: 14px 16px; font-weight: 600;' });
      const nameWrap = Dom.el('div', { style: 'display:flex;align-items:center;gap:10px;' });
      // v22p42: avatar with a presence indicator dot (online in last 5 min)
      const avatarWrap = Dom.el('div', { style: 'position:relative;display:inline-block;' });
      avatarWrap.appendChild(avatarCircle(u, 32));
      if (presenceSet.has(u.id)) {
        avatarWrap.appendChild(Dom.el('span', {
          title: 'Online now',
          style: 'position:absolute;bottom:-2px;right:-2px;width:11px;height:11px;border-radius:50%;background:#16A34A;border:2px solid white;box-shadow:0 0 0 1px rgba(0,0,0,.05);',
        }));
      }
      nameWrap.appendChild(avatarWrap);
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
    // v22p23: platform_admin only listed when caller is platform_admin.
    var inviteRoleOpts = [
      { v: 'centre_director', l: 'Centre director' },
      { v: 'educator', l: 'Educator' },
      { v: 'agency_admin', l: 'Agency admin' },
      { v: 'auditor', l: 'Auditor (read-only)' },
    ];
    if (sessionStorage.getItem('kt_is_platform_admin') === '1') {
      inviteRoleOpts.push({ v: 'platform_admin', l: '🌐 Platform admin (cross-agency)' });
    }
    inviteRoleOpts.forEach(r => {
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
    // Load centres for the picker (state.centres was never populated, so the
    // dropdown only ever had "No centre" — educators/directors could not be
    // assigned a centre). Fetch fresh; the global fetch shim adds the agency header.
    Api.get('/admin/centres').then(function (r) {
      (r && r.centres || []).forEach(function (c) {
        centreSelect.appendChild(Dom.el('option', { value: String(c.id) }, c.name));
      });
    }).catch(function () {});
    inputs.centre_id = centreSelect;
    centreWrap.appendChild(centreSelect);
    body.appendChild(centreWrap);

    const note = Dom.el('div', { style: 'background: #ECFDF5; border-left: 3px solid #10B981; padding: 10px 12px; font-size: 13px; color: #065F46; border-radius: 4px; margin-top: 12px;' },
      '✉ An invite email with a secure “set your password” link will be sent to this address automatically.');
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
          // Always request the set-password invite email. Without this flag the
          // backend created the user but emailed nothing — invited users never
          // received anything.
          data.send_invite = true;
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
    // v22 (2026-06) — account status / deactivate, profile extras & timestamped notes
    (function () {
      var uid = user.id;
      var statusBox = Dom.el('div', { style: 'margin-bottom:18px;padding:14px 16px;background:#F9FAFB;border-radius:10px;border:1px solid #E5E7EB;' });
      statusBox.appendChild(Dom.el('div', { style: 'font-size:11px;font-weight:800;color:#6B7280;letter-spacing:1px;text-transform:uppercase;margin-bottom:8px;' }, 'Account status'));
      var statusRow = Dom.el('div', { style: 'display:flex;align-items:center;gap:12px;' });
      var statusLabel = Dom.el('span', { style: 'font-size:13px;font-weight:700;text-transform:capitalize;' }, (user.status || 'active'));
      var toggleBtn = Dom.el('button', { style: 'padding:7px 14px;border-radius:7px;border:none;cursor:pointer;font-size:13px;font-weight:700;color:#fff;background:' + (user.status === 'deactivated' ? '#16A34A' : '#DC2626') + ';' }, user.status === 'deactivated' ? 'Reactivate' : 'Deactivate');
      toggleBtn.addEventListener('click', function () {
        var to = user.status === 'deactivated' ? 'active' : 'deactivated';
        if (to === 'deactivated' && !confirm('Deactivate ' + (user.name || 'this user') + '? They lose access until reactivated.')) return;
        toggleBtn.disabled = true;
        Api.patch('/admin/users/' + uid, { status: to }).then(function () {
          user.status = to; statusLabel.textContent = to;
          toggleBtn.textContent = to === 'deactivated' ? 'Reactivate' : 'Deactivate';
          toggleBtn.style.background = to === 'deactivated' ? '#16A34A' : '#DC2626';
          toggleBtn.disabled = false;
        }).catch(function () { toggleBtn.disabled = false; alert('Failed to update status'); });
      });
      statusRow.appendChild(statusLabel); statusRow.appendChild(toggleBtn);
      statusBox.appendChild(statusRow); body.appendChild(statusBox);

      var pf = Dom.el('div', { style: 'margin-bottom:18px;padding:14px 16px;background:#F9FAFB;border-radius:10px;border:1px solid #E5E7EB;' });
      pf.appendChild(Dom.el('div', { style: 'font-size:11px;font-weight:800;color:#6B7280;letter-spacing:1px;text-transform:uppercase;margin-bottom:10px;' }, 'Profile & emergency contact'));
      function _inp(ph) { return Dom.el('input', { placeholder: ph, style: 'width:100%;box-sizing:border-box;padding:8px 10px;border:1px solid #D1D5DB;border-radius:7px;font-size:13px;margin-bottom:8px;font-family:inherit;' }); }
      var iAddr = _inp('Street, city, province'); var iDob = Dom.el('input', { type: 'date', style: 'width:100%;box-sizing:border-box;padding:8px 10px;border:1px solid #D1D5DB;border-radius:7px;font-size:13px;margin-bottom:8px;font-family:inherit;' });
      var iEcN = _inp('Emergency contact name'); var iEcP = _inp('Emergency contact phone'); var iEcR = _inp('Relationship (e.g. spouse)');
      pf.appendChild(Dom.el('div', { style: 'font-size:12px;color:#6B7280;margin-bottom:3px;' }, 'Address')); pf.appendChild(iAddr);
      pf.appendChild(Dom.el('div', { style: 'font-size:12px;color:#6B7280;margin-bottom:3px;' }, 'Date of birth')); pf.appendChild(iDob);
      pf.appendChild(Dom.el('div', { style: 'font-size:12px;color:#6B7280;margin:4px 0 3px;' }, 'Emergency contact')); pf.appendChild(iEcN); pf.appendChild(iEcP); pf.appendChild(iEcR);
      var pfSave = Dom.el('button', { style: 'padding:8px 16px;background:#1F6080;color:#fff;border:none;border-radius:7px;cursor:pointer;font-size:13px;font-weight:700;' }, 'Save profile');
      var pfMsg = Dom.el('span', { style: 'font-size:12px;font-weight:700;margin-left:10px;' });
      pfSave.addEventListener('click', function () {
        pfSave.disabled = true; pfMsg.textContent = 'Saving...'; pfMsg.style.color = '#6B7280';
        Api.put('/admin/users/' + uid + '/profile', { address: iAddr.value, date_of_birth: iDob.value || null, emergency_contact_name: iEcN.value, emergency_contact_phone: iEcP.value, emergency_contact_relation: iEcR.value })
          .then(function () { pfMsg.textContent = '✓ Saved'; pfMsg.style.color = '#16A34A'; pfSave.disabled = false; })
          .catch(function (e) { pfMsg.textContent = '✕ ' + (e && e.message ? e.message : 'Failed'); pfMsg.style.color = '#DC2626'; pfSave.disabled = false; });
      });
      pf.appendChild(pfSave); pf.appendChild(pfMsg); body.appendChild(pf);

      var nb = Dom.el('div', { style: 'margin-bottom:18px;padding:14px 16px;background:#F9FAFB;border-radius:10px;border:1px solid #E5E7EB;' });
      nb.appendChild(Dom.el('div', { style: 'font-size:11px;font-weight:800;color:#6B7280;letter-spacing:1px;text-transform:uppercase;margin-bottom:10px;' }, 'Notes'));
      var noteList = Dom.el('div', { style: 'margin-bottom:10px;' }); nb.appendChild(noteList);
      var noteInput = Dom.el('textarea', { placeholder: 'Add a note (timestamped)...', style: 'width:100%;box-sizing:border-box;padding:8px 10px;border:1px solid #D1D5DB;border-radius:7px;font-size:13px;font-family:inherit;resize:vertical;min-height:54px;margin-bottom:8px;' });
      var noteBtn = Dom.el('button', { style: 'padding:8px 16px;background:#1F6080;color:#fff;border:none;border-radius:7px;cursor:pointer;font-size:13px;font-weight:700;' }, 'Add note');
      function renderNotes(notes) {
        noteList.innerHTML = '';
        if (!notes || !notes.length) { noteList.appendChild(Dom.el('div', { style: 'font-size:13px;color:#9CA3AF;' }, 'No notes yet.')); return; }
        notes.forEach(function (n) {
          var d = Dom.el('div', { style: 'padding:8px 0;border-bottom:1px solid #EEF2F5;' });
          d.appendChild(Dom.el('div', { style: 'font-size:13px;color:#0a1e2c;white-space:pre-wrap;' }, n.note));
          d.appendChild(Dom.el('div', { style: 'font-size:11px;color:#9CA3AF;margin-top:2px;' }, (n.created_by_name || 'Admin') + ' · ' + (n.created_at ? new Date(n.created_at).toLocaleString() : '')));
          noteList.appendChild(d);
        });
      }
      noteBtn.addEventListener('click', function () {
        var v = noteInput.value.trim(); if (!v) return; noteBtn.disabled = true;
        Api.post('/admin/users/' + uid + '/notes', { note: v })
          .then(function () { noteInput.value = ''; noteBtn.disabled = false; return Api.get('/admin/users/' + uid + '/profile'); })
          .then(function (d) { renderNotes(d.notes); }).catch(function () { noteBtn.disabled = false; alert('Failed to add note'); });
      });
      nb.appendChild(noteInput); nb.appendChild(noteBtn); body.appendChild(nb);

      Api.get('/admin/users/' + uid + '/profile').then(function (d) {
        if (d.profile) { iAddr.value = d.profile.address || ''; iDob.value = d.profile.date_of_birth || ''; iEcN.value = d.profile.emergency_contact_name || ''; iEcP.value = d.profile.emergency_contact_phone || ''; iEcR.value = d.profile.emergency_contact_relation || ''; }
        renderNotes(d.notes);
      }).catch(function () { renderNotes([]); });
    })();


    // ── Rooms this educator may see ─────────────────────────────────────
    // An educator sees ONLY the rooms assigned here. With none assigned they see
    // their whole centre — which is the old behaviour, and is why this control
    // spells out what is actually happening rather than showing empty checkboxes.
    (function () {
      const roomSection = Dom.el('div', {
        style: 'margin-bottom:18px;padding:14px 16px;background:#F9FAFB;border-radius:10px;border:1px solid #E5E7EB;',
      });
      roomSection.appendChild(Dom.el('div', {
        style: 'font-size:11px;font-weight:800;color:#6B7280;letter-spacing:1px;text-transform:uppercase;margin-bottom:8px;',
      }, 'Rooms this educator can see'));
      const roomBody = Dom.el('div', {});
      roomBody.appendChild(Dom.el('div', { style: 'color:#9CA3AF;font-size:13px;' }, 'Loading…'));
      roomSection.appendChild(roomBody);
      content.appendChild(roomSection);

      Api.get('/admin/users/' + user.id + '/rooms').then(function (d) {
        Dom.clear(roomBody);

        const note = Dom.el('div', { style: 'font-size:12.5px;color:#6B7280;margin-bottom:10px;line-height:1.5;' }, d.note || '');
        roomBody.appendChild(note);

        const selected = {};
        (d.assigned_room_ids || []).forEach(function (id) { selected[id] = true; });

        const grid = Dom.el('div', { style: 'display:flex;flex-wrap:wrap;gap:7px;' });
        (d.rooms || []).forEach(function (room) {
          const chip = Dom.el('button', {
            type: 'button',
            style: 'border-radius:999px;padding:8px 13px;font-size:13px;font-weight:700;cursor:pointer;border:1.5px solid;',
          }, room.name);
          const paint = function () {
            const on = !!selected[room.id];
            chip.style.background = on ? '#159FB4' : '#fff';
            chip.style.color = on ? '#fff' : '#374151';
            chip.style.borderColor = on ? '#159FB4' : '#E5E7EB';
          };
          chip.addEventListener('click', function () { selected[room.id] = !selected[room.id]; paint(); });
          paint();
          grid.appendChild(chip);
        });
        roomBody.appendChild(grid);

        const status = Dom.el('div', { style: 'font-size:12.5px;min-height:16px;margin-top:8px;' });
        const save = Dom.el('button', {
          type: 'button',
          style: 'background:#1F6080;color:#fff;border:none;padding:9px 16px;border-radius:8px;font-size:13px;font-weight:700;cursor:pointer;margin-top:8px;',
        }, 'Save rooms');
        save.addEventListener('click', function () {
          const ids = Object.keys(selected).filter(function (k) { return selected[k]; }).map(Number);
          save.disabled = true; save.textContent = 'Saving…';
          Api.put('/admin/users/' + user.id + '/rooms', { room_ids: ids })
            .then(function () {
              save.disabled = false; save.textContent = 'Save rooms';
              status.style.color = '#16A34A';
              status.textContent = ids.length
                ? '✓ Saved. They now see only these ' + ids.length + ' room(s).'
                : '✓ Saved. With no rooms selected they see every room at their centre.';
            })
            .catch(function (e) {
              save.disabled = false; save.textContent = 'Save rooms';
              status.style.color = '#B91C1C';
              status.textContent = (e && e.message) || 'Could not save.';
            });
        });
        roomBody.appendChild(save);
        roomBody.appendChild(status);
      }).catch(function () {
        Dom.clear(roomBody);
        roomBody.appendChild(Dom.el('div', { style: 'color:#9CA3AF;font-size:13px;' },
          'Room assignment applies to educators at a centre.'));
      });
    })();

    // ── Clock in / out history ──────────────────────────────────────────
    // The educator's punches, on their record, so a director can answer "when
    // did they actually work?" without exporting a payroll report.
    (function () {
      const clockSection = Dom.el('div', {
        style: 'margin-bottom:18px;padding:14px 16px;background:#F9FAFB;border-radius:10px;border:1px solid #E5E7EB;',
      });
      clockSection.appendChild(Dom.el('div', {
        style: 'font-size:11px;font-weight:800;color:#6B7280;letter-spacing:1px;text-transform:uppercase;margin-bottom:8px;',
      }, 'Clock in / out history'));
      const clockBody = Dom.el('div', {});
      clockBody.appendChild(Dom.el('div', { style: 'color:#9CA3AF;font-size:13px;' }, 'Loading…'));
      clockSection.appendChild(clockBody);
      content.appendChild(clockSection);

      Api.get('/admin/users/' + user.id + '/punches').then(function (d) {
        Dom.clear(clockBody);
        const rows = d.punches || [];
        if (!rows.length) {
          clockBody.appendChild(Dom.el('div', { style: 'color:#9CA3AF;font-size:13px;' }, 'No clock records for this user.'));
          return;
        }
        clockBody.appendChild(Dom.el('div', { style: 'font-size:12.5px;color:#6B7280;margin-bottom:8px;' },
          'Total on record: ' + (d.total_hours || 0) + ' hours across ' + rows.length + ' shift(s).'));

        const list = Dom.el('div', {});
        rows.slice(0, 20).forEach(function (p) {
          const open = !p.punched_out_at;
          const row = Dom.el('div', {
            style: 'display:flex;justify-content:space-between;align-items:center;padding:8px 0;border-bottom:1px solid #F3F4F6;font-size:13px;',
          });
          row.appendChild(Dom.el('div', {}, [
            Dom.el('div', { style: 'font-weight:700;color:#111827;' }, p.day || ''),
            Dom.el('div', { style: 'font-size:11.5px;color:#9CA3AF;' },
              (p.in_time || '—') + ' – ' + (open ? 'still clocked in' : (p.out_time || '—'))),
          ]));
          row.appendChild(Dom.el('div', {
            style: 'font-weight:800;color:' + (open ? '#B45309' : '#0E7C90') + ';',
          }, open ? 'open' : (p.hours + 'h')));
          list.appendChild(row);
        });
        clockBody.appendChild(list);
      }).catch(function () {
        Dom.clear(clockBody);
        clockBody.appendChild(Dom.el('div', { style: 'color:#9CA3AF;font-size:13px;' }, 'Could not load clock records.'));
      });
    })();

    // v22p23: role section — show current roles as pills + a "Change role" form.
    const roleSection = Dom.el('div', {
      style: 'margin-bottom: 18px; padding: 14px 16px; background: #F9FAFB; border-radius: 10px; border: 1px solid #E5E7EB;',
    });
    roleSection.appendChild(Dom.el('div', {
      style: 'font-size: 11px; font-weight: 800; color: #6B7280; letter-spacing: 1px; text-transform: uppercase; margin-bottom: 8px;',
    }, 'Roles'));
    const pillsWrap = Dom.el('div', { style: 'display: flex; flex-wrap: wrap; gap: 6px; margin-bottom: 12px;' });
    var roleLabels = {
      agency_admin: 'Agency admin', centre_director: 'Centre director',
      educator: 'Educator', guardian: 'Parent / guardian',
      auditor: 'Auditor', platform_admin: '🌐 Platform admin',
    };
    (user.roles || []).forEach(function (r) {
      var bg = r === 'platform_admin' ? '#7C3AED' : (r === 'agency_admin' ? '#1F6080' : '#374151');
      pillsWrap.appendChild(Dom.el('span', {
        style: 'padding: 3px 10px; border-radius: 999px; background: ' + bg + '; color: white; font-size: 11px; font-weight: 700; letter-spacing: 0.5px;',
      }, (roleLabels[r] || r).toUpperCase()));
    });
    if (!user.roles || user.roles.length === 0) {
      pillsWrap.appendChild(Dom.el('span', { style: 'font-size: 12px; color: #6B7280;' }, 'No roles assigned'));
    }
    roleSection.appendChild(pillsWrap);

    // Change-role form
    const changeBox = Dom.el('div', { style: 'display: flex; gap: 8px; align-items: center; flex-wrap: wrap;' });
    changeBox.appendChild(Dom.el('span', { style: 'font-size: 12px; color: #6B7280; font-weight: 600;' }, 'Add / set role:'));
    const newRoleSelect = Dom.el('select', {
      style: 'padding: 6px 10px; border: 1px solid #D1D5DB; border-radius: 6px; font-size: 13px; background: white;',
    });
    var changeRoleOpts = [
      { v: 'centre_director', l: 'Centre director' },
      { v: 'educator', l: 'Educator' },
      { v: 'agency_admin', l: 'Agency admin' },
      { v: 'auditor', l: 'Auditor' },
    ];
    if (sessionStorage.getItem('kt_is_platform_admin') === '1') {
      changeRoleOpts.push({ v: 'platform_admin', l: '🌐 Platform admin' });
    }
    changeRoleOpts.forEach(function (r) {
      newRoleSelect.appendChild(Dom.el('option', { value: r.v }, r.l));
    });
    changeBox.appendChild(newRoleSelect);

    // Optional centre picker for non-admin roles
    const newCentreSelect = Dom.el('select', {
      style: 'padding: 6px 10px; border: 1px solid #D1D5DB; border-radius: 6px; font-size: 13px; background: white;',
    });
    newCentreSelect.appendChild(Dom.el('option', { value: '' }, 'No centre'));
    Api.get('/admin/centres').then(function (r) {
      (r && r.centres || []).forEach(function (c) {
        newCentreSelect.appendChild(Dom.el('option', { value: String(c.id) }, c.name));
      });
    }).catch(function () {});
    changeBox.appendChild(newCentreSelect);

    const applyRoleBtn = Dom.el('button', {
      type: 'button',
      style: 'background: #1F6080; color: white; border: none; padding: 7px 14px; border-radius: 6px; font-size: 12px; font-weight: 600; cursor: pointer;',
    }, 'Apply');
    const roleStatus = Dom.el('span', { style: 'font-size: 12px; color: #6B7280; margin-left: 8px;' });
    applyRoleBtn.addEventListener('click', async function () {
      var payload = { role: newRoleSelect.value };
      if (newCentreSelect.value) payload.centre_id = parseInt(newCentreSelect.value, 10);
      applyRoleBtn.disabled = true;
      applyRoleBtn.textContent = 'Saving…';
      roleStatus.style.color = '#1F6080';
      roleStatus.textContent = '';
      try {
        await Api.post('/admin/users/' + user.id + '/role', payload);
        roleStatus.style.color = '#16A34A';
        roleStatus.textContent = '✓ Saved — close + reopen to refresh';
        // Refresh user list in background
        renderUsersTab(content);
      } catch (e) {
        roleStatus.style.color = '#DC2626';
        roleStatus.textContent = e.message || 'Failed';
      } finally {
        applyRoleBtn.disabled = false;
        applyRoleBtn.textContent = 'Apply';
      }
    });
    changeBox.appendChild(applyRoleBtn);
    changeBox.appendChild(roleStatus);
    roleSection.appendChild(changeBox);

    roleSection.appendChild(Dom.el('div', {
      style: 'font-size: 11px; color: #6B7280; margin-top: 8px; line-height: 1.4;',
    }, 'Adding a role is additive — existing roles stay active. To revoke, contact support.'));

    body.appendChild(roleSection);

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

    let data, centresData;
    try {
      [data, centresData] = await Promise.all([
        Api.get('/admin/families'),
        Api.get('/admin/centres'),
      ]);
    } catch (e) {
      Dom.clear(content);
      content.appendChild(errorBox('Could not load families: ' + (e.message || 'error')));
      return;
    }

    Dom.clear(content);

    // v22p12.1: tab hero
    content.appendChild(tabHero(
      '👪 Families',
      data.families.length + ' famil' + (data.families.length === 1 ? 'y' : 'ies') + ' across your centres. Click any card to see children, guardians, and balances.',
      'familyGroup'
    ));

    // v22p11: action bar with count on the left + Add button on the right
    const bar = Dom.el('div', { style: 'display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px; gap: 10px; flex-wrap: wrap;' });
    bar.appendChild(Dom.el('div', { style: 'color: var(--ink-500); font-size: 13px; flex: 1;' }, 'All enrolled families'));

    // v22p45: CSV download
    const famCsvBtn = Dom.el('button', { style: 'background: white; color: #16A34A; border: 1px solid #16A34A; padding: 8px 14px; border-radius: 8px; font-size: 12px; font-weight: 600; cursor: pointer;' }, '⤓ CSV');
    famCsvBtn.addEventListener('click', () => downloadCsv('/admin/families', 'families.csv', famCsvBtn));
    bar.appendChild(famCsvBtn);

    const addBtn = Dom.el('button', { style: btnPrimary() }, '+ Add family');
    addBtn.addEventListener('click', () => showFamilyWizard(centresData.centres, content));
    bar.appendChild(addBtn);

    // v22p26: card/table view toggle remembered per-list in localStorage.
    const toggle = viewToggle('kt_view_families', function () { renderFamiliesTab(content); });
    bar.insertBefore(toggle, famCsvBtn);
    content.appendChild(bar);

    if (data.families.length === 0) {
      content.appendChild(emptyMsg(
        'Click + Add family to register the first one. You can attach children, set the billing split, and invite guardians from the card afterward.',
        { title: 'No families enrolled yet', illustration: 'emptyFamilies' }
      ));
      return;
    }

    var view = localStorage.getItem('kt_view_families') || 'cards';
    if (view === 'table') {
      content.appendChild(renderFamiliesTable(data.families, centresData.centres, content));
      return;
    }

    const grid = Dom.el('div', { style: 'display: grid; grid-template-columns: repeat(auto-fill, minmax(360px, 1fr)); gap: 16px;' });
    data.families.forEach(f => {
      const card = Dom.el('div', { style: 'background: white; padding: 18px; border-radius: 12px; box-shadow: 0 1px 3px rgba(0,0,0,0.04); cursor: pointer; position: relative;' });

      // v22p11: Edit button in top-right corner, stops card-click propagation.
      const editBtn = Dom.el('button', {
        style: 'position: absolute; top: 10px; right: 10px; background: transparent; border: 1px solid var(--ink-300); padding: 4px 10px; border-radius: 6px; cursor: pointer; font-size: 12px; color: var(--ink-700);',
      }, 'Edit');
      editBtn.addEventListener('click', (e) => {
        e.stopPropagation();
        showFamilyModal(f, centresData.centres, content);
      });
      card.appendChild(editBtn);

      // v22p83: avatar header (coloured initials circle) + name/centre
      var famHead = Dom.el('div', { style: 'display:flex;align-items:center;gap:12px;margin-bottom:12px;padding-right:60px;' });
      famHead.appendChild(avatarCircle({ id: f.id, name: f.family_name }, 44));
      var famText = Dom.el('div', { style: 'min-width:0;' });
      famText.appendChild(Dom.el('div', { style: 'font-size: 17px; font-weight: 700; margin-bottom: 2px;' }, f.family_name));
      famText.appendChild(Dom.el('div', { style: 'color: var(--ink-500); font-size: 13px;' }, f.centre_name || '—'));
      famHead.appendChild(famText);
      card.appendChild(famHead);

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

  // v22p26: small toggle for cards/table view, persisted in localStorage.
  // v22p30: third arg `defaultView` lets a list pick which view to use when the
  // user has never set a preference. Families + Children default to 'cards';
  // Centres defaults to 'table' (more columns to compare at a glance).
  function viewToggle(storageKey, onChange, defaultView) {
    var current = localStorage.getItem(storageKey) || defaultView || 'cards';
    var wrap = Dom.el('div', { style: 'display:inline-flex;background:#F3F4F6;border-radius:8px;padding:2px;margin-right:8px;' });
    function btn(view, label, icon) {
      var b = Dom.el('button', {
        type: 'button',
        style: 'background:' + (current === view ? 'white' : 'transparent') + ';color:' + (current === view ? '#1F6080' : '#6B7280') + ';border:none;padding:6px 10px;border-radius:6px;font-size:12px;font-weight:600;cursor:pointer;display:flex;align-items:center;gap:4px;' + (current === view ? 'box-shadow:0 1px 2px rgba(0,0,0,.08);' : ''),
      }, icon + ' ' + label);
      b.addEventListener('click', function () {
        if (current === view) return;
        localStorage.setItem(storageKey, view);
        if (onChange) onChange(view);
      });
      return b;
    }
    wrap.appendChild(btn('cards', 'Cards', '▦'));
    wrap.appendChild(btn('table', 'Table', '☰'));
    return wrap;
  }

  // v22p26: families table view.
  function renderFamiliesTable(families, centres, content) {
    // v22p46: bulk delete via multi-select. Same UX as the Users tab.
    var wrap = Dom.el('div');
    var selectedIds = new Set();
    var bulkBar = Dom.el('div', { style: 'display:none;align-items:center;gap:10px;background:#FEF3C7;border:1px solid #FCD34D;border-radius:10px;padding:10px 14px;margin-bottom:12px;' });
    var bulkCount = Dom.el('div', { style: 'flex:1;font-size:13px;color:#92400E;font-weight:600;' }, '0 selected');
    var bulkDelete = Dom.el('button', { style: 'background:white;color:#DC2626;border:1px solid #FCA5A5;padding:6px 12px;border-radius:8px;font-size:12px;font-weight:600;cursor:pointer;' }, 'Delete');
    bulkBar.appendChild(bulkCount);
    bulkBar.appendChild(bulkDelete);
    wrap.appendChild(bulkBar);

    var rowCbs = [];
    function refreshBulkBar() {
      bulkBar.style.display = selectedIds.size > 0 ? 'flex' : 'none';
      bulkCount.textContent = selectedIds.size + ' selected';
    }
    bulkDelete.addEventListener('click', async function () {
      var ids = Array.from(selectedIds);
      if (!ids.length) return;
      if (!confirm('Delete ' + ids.length + ' family record(s)? Children + audit history are preserved; only the family-level row is removed.')) return;
      bulkDelete.disabled = true;
      var ok = 0, fail = 0;
      for (const id of ids) { try { await Api.delete('/admin/families/' + id); ok++; } catch (e) { fail++; } }
      bulkDelete.disabled = false;
      alert('Deleted ' + ok + ' family record(s), ' + fail + ' failed.');
      selectedIds.clear();
      await renderFamiliesTab(content);
    });

    var table = Dom.el('table', { style: 'width:100%;background:white;border-radius:12px;overflow:hidden;border-collapse:collapse;box-shadow:0 1px 3px rgba(0,0,0,.04);' });
    var thead = Dom.el('thead', { style: 'background:#F9FAFB;' });
    var headRow = Dom.el('tr');

    // v22p46: select-all checkbox
    var headCheck = Dom.el('th', { style: 'padding:11px 8px 11px 14px;width:32px;' });
    var selectAll = Dom.el('input', { type: 'checkbox', style: 'cursor:pointer;width:16px;height:16px;', title: 'Select all on this page' });
    selectAll.addEventListener('change', function () {
      rowCbs.forEach(function (rc) {
        rc.cb.checked = selectAll.checked;
        if (selectAll.checked) selectedIds.add(rc.id); else selectedIds.delete(rc.id);
      });
      refreshBulkBar();
    });
    headCheck.appendChild(selectAll);
    headRow.appendChild(headCheck);

    ['Family', 'Centre', 'Children', 'Guardians', 'Outstanding', ''].forEach(function (h) {
      headRow.appendChild(Dom.el('th', {
        style: 'text-align:left;padding:11px 14px;font-size:11px;font-weight:700;color:#6B7280;text-transform:uppercase;letter-spacing:0.5px;',
      }, h));
    });
    thead.appendChild(headRow);
    table.appendChild(thead);

    var tbody = Dom.el('tbody');
    families.forEach(function (f) {
      var tr = Dom.el('tr', { style: 'border-top:1px solid #E5E7EB;cursor:pointer;' });

      // v22p46: per-row checkbox
      var ckTd = Dom.el('td', { style: 'padding:11px 8px 11px 14px;' });
      var cb = Dom.el('input', { type: 'checkbox', style: 'cursor:pointer;width:16px;height:16px;' });
      cb.addEventListener('click', function (e) { e.stopPropagation(); });
      cb.addEventListener('change', function () {
        if (cb.checked) selectedIds.add(f.id); else selectedIds.delete(f.id);
        refreshBulkBar();
      });
      ckTd.appendChild(cb);
      tr.appendChild(ckTd);
      rowCbs.push({ cb: cb, id: f.id });

      tr.addEventListener('click', function () { showFamilyDetail(f.id); });

      // v22p83: avatar + clickable name, matching the Users tab. Families have no
      // photo so this renders a deterministic coloured initials circle.
      var famCell = Dom.el('td', { style: 'padding:11px 14px;font-weight:600;' });
      var famWrap = Dom.el('div', { style: 'display:flex;align-items:center;gap:10px;' });
      famWrap.appendChild(avatarCircle({ id: f.id, name: f.family_name }, 32));
      famWrap.appendChild(Dom.el('span', { style: 'color:#1F6080;' }, f.family_name));
      famCell.appendChild(famWrap);
      tr.appendChild(famCell);
      tr.appendChild(Dom.el('td', { style: 'padding:11px 14px;color:#6B7280;font-size:13px;' }, f.centre_name || '—'));
      tr.appendChild(Dom.el('td', { style: 'padding:11px 14px;font-size:13px;' }, '👶 ' + f.child_count));
      tr.appendChild(Dom.el('td', { style: 'padding:11px 14px;font-size:13px;' }, '👤 ' + f.guardian_count));
      tr.appendChild(Dom.el('td', { style: 'padding:11px 14px;font-size:13px;' + (f.outstanding_balance > 0 ? 'color:#DC2626;font-weight:600;' : 'color:#6B7280;') },
        f.outstanding_balance > 0 ? ('$' + f.outstanding_balance.toFixed(2)) : '—'));

      var actionsTd = Dom.el('td', { style: 'padding:11px 14px;text-align:right;white-space:nowrap;' });
      var editBtn = Dom.el('button', {
        type: 'button',
        style: 'background:transparent;border:1px solid var(--ink-300);padding:5px 10px;border-radius:6px;cursor:pointer;font-size:12px;color:var(--ink-700);',
      }, 'Edit');
      editBtn.addEventListener('click', function (ev) {
        ev.stopPropagation();
        showFamilyModal(f, centres, content);
      });
      actionsTd.appendChild(editBtn);
      tr.appendChild(actionsTd);
      tbody.appendChild(tr);
    });
    table.appendChild(tbody);
    wrap.appendChild(table);
    return wrap;
  }

  // v22p11: shared add/edit modal for families. centres = array of {id, name} for the picker.
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
      children: [{ first_name: '', last_name: '', preferred_name: '', date_of_birth: '', gender: 'prefer_not_to_say', enrollment_status: 'enrolled', allergies: '', dietary_restrictions: '', medical_notes: '', doctor_name: '', doctor_phone: '', school: '' }],
      emergency: [],
    };
    var STEPS = ['Family', 'Guardians', 'Children', 'Emergency', 'Review'];
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
        var r4 = fieldRow('1fr 1fr');
        r4.appendChild(wrap('Allergies (comma-separated)', bindInput(c, 'allergies', { placeholder: 'e.g. Peanuts, Dairy' })));
        r4.appendChild(wrap('Dietary restrictions', bindInput(c, 'dietary_restrictions', { placeholder: 'e.g. Vegetarian, Halal' })));
        card.appendChild(r4);
        var r5 = fieldRow('1fr 1fr');
        r5.appendChild(wrap('School attending', bindInput(c, 'school', { placeholder: 'e.g. Bright Start Kindergarten' })));
        r5.appendChild(wrap('Family doctor', bindInput(c, 'doctor_name')));
        card.appendChild(r5);
        var r6 = fieldRow('1fr 1fr');
        r6.appendChild(wrap('Doctor phone', bindInput(c, 'doctor_phone')));
        r6.appendChild(Dom.el('div', {}));
        card.appendChild(r6);
        var med = Dom.el('textarea', { style: inStyle + 'min-height:52px;font-family:inherit;' });
        med.value = c.medical_notes || ''; med.addEventListener('input', function () { c.medical_notes = med.value; });
        card.appendChild(wrap('Medical notes (medications, conditions)', med));
        bodyEl.appendChild(card);
      });
      var add = Dom.el('button', { type: 'button', style: 'background:#EFF6FB;border:1px dashed #1F6080;color:#1F6080;border-radius:8px;padding:10px;width:100%;font-weight:600;cursor:pointer;font-size:13px;' }, '+ Add another child');
      add.addEventListener('click', function () { state.children.push({ first_name: '', last_name: '', preferred_name: '', date_of_birth: '', gender: 'prefer_not_to_say', enrollment_status: 'enrolled', allergies: '', dietary_restrictions: '', medical_notes: '', doctor_name: '', doctor_phone: '', school: '' }); renderChildrenStep(); });
      bodyEl.appendChild(add);
    }

    function renderEmergencyStep() {
      bodyEl.innerHTML = '';
      bodyEl.appendChild(Dom.el('p', { style: 'font-size:13px;color:#64748B;margin:0 0 14px;' }, 'Emergency contacts (optional) — people to call if a guardian can’t be reached. Tick anyone authorized to pick up the child.'));
      state.emergency.forEach(function (e, idx) {
        var card = Dom.el('div', { style: 'border:1px solid #E2E8F0;border-radius:10px;padding:14px;margin-bottom:12px;background:#FBFDFE;' });
        var head = Dom.el('div', { style: 'display:flex;justify-content:space-between;align-items:center;margin-bottom:10px;' });
        head.appendChild(Dom.el('strong', { style: 'font-size:13px;color:#1F6080;' }, '☎ Emergency contact ' + (idx + 1)));
        var rm = Dom.el('button', { type: 'button', style: 'background:none;border:none;color:#DC2626;font-size:13px;cursor:pointer;' }, '✕ Remove');
        rm.addEventListener('click', function () { state.emergency.splice(idx, 1); renderEmergencyStep(); });
        head.appendChild(rm); card.appendChild(head);
        var r1 = fieldRow('1fr 1fr');
        r1.appendChild(wrap('Full name *', bindInput(e, 'name')));
        r1.appendChild(wrap('Relationship', bindInput(e, 'relationship', { placeholder: 'e.g. Grandmother' })));
        card.appendChild(r1);
        var r2 = fieldRow('1fr 1fr');
        r2.appendChild(wrap('Phone', bindInput(e, 'phone')));
        r2.appendChild(wrap('Alternate phone', bindInput(e, 'alt_phone')));
        card.appendChild(r2);
        var pickWrap = Dom.el('div', { style: 'display:flex;align-items:center;gap:8px;' });
        var pick = Dom.el('input', { type: 'checkbox' }); pick.checked = !!e.can_pickup;
        pick.addEventListener('change', function () { e.can_pickup = pick.checked; });
        pickWrap.appendChild(pick); pickWrap.appendChild(Dom.el('label', { style: 'font-size:13px;' }, 'Authorized for pickup'));
        card.appendChild(pickWrap);
        bodyEl.appendChild(card);
      });
      var add = Dom.el('button', { type: 'button', style: 'background:#EFF6FB;border:1px dashed #1F6080;color:#1F6080;border-radius:8px;padding:10px;width:100%;font-weight:600;cursor:pointer;font-size:13px;' }, '+ Add emergency contact');
      add.addEventListener('click', function () { state.emergency.push({ name: '', relationship: '', phone: '', alt_phone: '', can_pickup: false }); renderEmergencyStep(); });
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
      else if (step === 3) renderEmergencyStep();
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
          return { first_name: c.first_name.trim(), last_name: c.last_name.trim(), preferred_name: c.preferred_name.trim() || null, date_of_birth: c.date_of_birth, gender: c.gender, enrollment_status: c.enrollment_status,
            allergies: (c.allergies || '').trim() || null, dietary_restrictions: (c.dietary_restrictions || '').trim() || null, medical_notes: (c.medical_notes || '').trim() || null, doctor_name: (c.doctor_name || '').trim() || null, doctor_phone: (c.doctor_phone || '').trim() || null, school: (c.school || '').trim() || null };
        }),
        emergency_contacts: (state.emergency || []).filter(function (e) { return (e.name || '').trim(); }).map(function (e) {
          return { name: e.name.trim(), relationship: (e.relationship || '').trim() || null, phone: (e.phone || '').trim() || null, alt_phone: (e.alt_phone || '').trim() || null, can_pickup: !!e.can_pickup };
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

  function showFamilyModal(family, centres, content) {
    const isEdit = !!family;
    if (!isEdit) { return showFamilyWizard(centres, content); }
    const inputs = {};
    const form = Dom.el('form', {});

    function field(key, label, options) {
      options = options || {};
      const wrap = Dom.el('div', { style: 'margin-bottom: 14px;' });
      wrap.appendChild(Dom.el('label', { style: 'display: block; font-size: 13px; font-weight: 600; margin-bottom: 4px;' }, label));
      let input;
      if (options.select) {
        input = Dom.el('select', { style: 'width: 100%; padding: 8px 12px; border: 1px solid var(--ink-300); border-radius: 6px; font-size: 14px; background: white;' });
        options.select.forEach(opt => {
          const o = Dom.el('option', { value: opt.value }, opt.label);
          if (family && String(family[key]) === String(opt.value)) o.selected = true;
          else if (!family && opt.value === options.default) o.selected = true;
          input.appendChild(o);
        });
      } else if (options.textarea) {
        input = Dom.el('textarea', { style: 'width: 100%; padding: 8px 12px; border: 1px solid var(--ink-300); border-radius: 6px; font-size: 14px; min-height: 60px; font-family: inherit; box-sizing: border-box;' });
        input.value = family ? (family[key] || '') : '';
      } else {
        input = Dom.el('input', {
          type: options.type || 'text',
          style: 'width: 100%; padding: 8px 12px; border: 1px solid var(--ink-300); border-radius: 6px; font-size: 14px; box-sizing: border-box;',
        });
        if (options.placeholder) input.placeholder = options.placeholder;
        input.value = family ? (family[key] || '') : '';
      }
      inputs[key] = input;
      wrap.appendChild(input);
      form.appendChild(wrap);
    }

    // Required fields
    field('centre_id', 'Centre *', {
      select: (centres || []).map(c => ({ value: c.id, label: c.name })),
      default: centres && centres[0] ? centres[0].id : null,
    });
    field('family_name', 'Family name *', { placeholder: 'e.g. The Patel family' });

    // Two-column row: phone + email
    const row1 = Dom.el('div', { style: 'display: grid; grid-template-columns: 1fr 1fr; gap: 12px;' });
    [['primary_phone', 'Primary phone'], ['primary_email', 'Primary email', 'email']].forEach(([key, label, type]) => {
      const cell = Dom.el('div');
      cell.appendChild(Dom.el('label', { style: 'display: block; font-size: 13px; font-weight: 600; margin-bottom: 4px;' }, label));
      const input = Dom.el('input', {
        type: type || 'text',
        style: 'width: 100%; padding: 8px 12px; border: 1px solid var(--ink-300); border-radius: 6px; font-size: 14px; box-sizing: border-box;',
      });
      input.value = family ? (family[key] || '') : '';
      inputs[key] = input;
      cell.appendChild(input);
      row1.appendChild(cell);
    });
    form.appendChild(row1);
    form.appendChild(Dom.el('div', { style: 'margin-bottom: 14px;' }));

    field('address_line1', 'Street address');
    const row2 = Dom.el('div', { style: 'display: grid; grid-template-columns: 2fr 1fr 1fr; gap: 12px;' });
    [['city', 'City'], ['province', 'Province'], ['postal_code', 'Postal code']].forEach(([key, label]) => {
      const cell = Dom.el('div');
      cell.appendChild(Dom.el('label', { style: 'display: block; font-size: 13px; font-weight: 600; margin-bottom: 4px;' }, label));
      const input = Dom.el('input', {
        type: 'text',
        style: 'width: 100%; padding: 8px 12px; border: 1px solid var(--ink-300); border-radius: 6px; font-size: 14px; box-sizing: border-box;',
      });
      input.value = family ? (family[key] || '') : '';
      inputs[key] = input;
      cell.appendChild(input);
      row2.appendChild(cell);
    });
    form.appendChild(row2);
    form.appendChild(Dom.el('div', { style: 'margin-bottom: 14px;' }));

    field('billing_split', 'Billing split', {
      select: [
        { value: 'single', label: 'Single payer' },
        { value: 'split_50_50', label: 'Split 50 / 50 between guardians' },
        { value: 'custom', label: 'Custom split' },
      ],
      default: 'single',
    });
    field('notes', 'Internal notes', { textarea: true, placeholder: 'Anything billing or office staff should know (not visible to parents).' });

    const status = Dom.el('div', { style: 'min-height: 20px; color: #DC2626; font-size: 13px; margin: 8px 0;' });
    form.appendChild(status);

    Shell.Modal.open({
      title: isEdit ? 'Edit ' + family.family_name : 'New family',
      body: form,
      large: true,
      actions: [{
        label: isEdit ? 'Save changes' : 'Create family',
        primary: true,
        onClick: async () => {
          status.style.color = '#DC2626';
          status.textContent = '';
          if (!inputs.family_name.value.trim()) { status.textContent = 'Family name is required.'; return; }
          if (!inputs.centre_id.value) { status.textContent = 'Centre is required.'; return; }
          const payload = {};
          Object.keys(inputs).forEach(k => {
            let v = inputs[k].value;
            if (k === 'centre_id') v = parseInt(v, 10);
            else if (typeof v === 'string') v = v.trim();
            payload[k] = v === '' ? null : v;
          });
          status.style.color = '#1F6080';
          status.textContent = isEdit ? 'Saving…' : 'Creating…';
          try {
            if (isEdit) {
              await Api.patch('/admin/families/' + family.id, payload);
            } else {
              await Api.post('/admin/families', payload);
            }
            if (Dom.toast) Dom.toast(isEdit ? 'Family updated' : 'Family created', 'success');
            await renderFamiliesTab(content);
            Shell.Modal.close();
          } catch (e) {
            status.style.color = '#DC2626';
            status.textContent = (e.message || 'Save failed')
              + (e.errors ? ' — ' + Object.values(e.errors).flat().join(', ') : '');
          }
        },
      }],
    });
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
          const row = Dom.el('div', { style: 'padding: 10px; background: var(--ink-50); border-radius: 6px; margin-bottom: 6px; display:flex; align-items:center; gap:10px;' });
          // v22p83: child avatar (photo or initials)
          const cName = c.preferred_name || (c.first_name + ' ' + c.last_name);
          row.appendChild(Dom.el('span', { html: KT.avatar(c.first_name + ' ' + c.last_name, { size: 34, photoUrl: c.photo_url }) }));
          const cInfo = Dom.el('div', { style: 'min-width:0;' });
          cInfo.appendChild(Dom.el('div', { style: 'font-weight: 600;' }, cName));
          cInfo.appendChild(Dom.el('div', { style: 'font-size: 13px; color: var(--ink-500);' }, (c.date_of_birth ? 'Born ' + c.date_of_birth + ' · ' : '') + c.enrollment_status));
          row.appendChild(cInfo);
          body.appendChild(row);
        });
      }

      body.appendChild(Dom.el('h4', { style: 'font-size: 14px; font-weight: 700; margin: 16px 0 8px; letter-spacing: 0.5px; color: var(--ink-700);' }, 'GUARDIANS'));
      data.guardians.forEach(g => {
        const row = Dom.el('div', { style: 'padding: 10px; background: var(--ink-50); border-radius: 6px; margin-bottom: 6px; display: flex; align-items: center; gap: 10px;' });
        // v22p83: guardian avatar (photo or initials)
        row.appendChild(Dom.el('span', { html: KT.avatar(g.first_name + ' ' + g.last_name, { size: 34, photoUrl: g.photo_url }) }));
        const info = Dom.el('div', { style: 'flex: 1; min-width: 0;' });
        info.appendChild(Dom.el('div', { style: 'font-weight: 600;' }, g.first_name + ' ' + g.last_name + (g.is_primary ? ' (primary)' : '')));
        info.appendChild(Dom.el('div', { style: 'font-size: 13px; color: var(--ink-500);' }, g.email + ' · ' + g.relationship));
        row.appendChild(info);
        // v22p5.2: per-guardian kiosk PIN setter (gates parent sign-in on the kiosk)
        if (g.can_pickup && g.id) {
          const pinBtn = Dom.el('button', {
            type: 'button',
            style: 'padding: 6px 10px; background: white; color: #1F6080; border: 1px solid #1F6080; border-radius: 6px; font-size: 12px; font-weight: 600; cursor: pointer; white-space: nowrap;',
          }, '🔐 Set kiosk PIN');
          pinBtn.addEventListener('click', function () {
            const pin = window.prompt('Enter a 4-6 digit kiosk PIN for ' + g.first_name + ' ' + g.last_name + '.\n\nThis PIN lets them sign children in/out at the centre kiosk. Share it with the guardian out-of-band (e.g. text, in person).');
            if (pin == null) return; // cancelled
            if (!/^\d{4,6}$/.test(pin)) { alert('PIN must be 4–6 digits.'); return; }
            pinBtn.disabled = true;
            pinBtn.textContent = 'Saving…';
            Api.post('/director/guardians/' + g.id + '/kiosk-pin', { pin: pin }).then(function () {
              pinBtn.textContent = '✓ PIN set';
              setTimeout(function () { pinBtn.disabled = false; pinBtn.textContent = '🔐 Update PIN'; }, 1500);
              if (Dom.toast) Dom.toast('Kiosk PIN updated for ' + g.first_name, 'success');
            }).catch(function (e) {
              pinBtn.disabled = false;
              pinBtn.textContent = '🔐 Set kiosk PIN';
              alert('Could not save PIN: ' + (e.message || 'server error'));
            });
          });
          row.appendChild(pinBtn);
        }
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
  // v22p83: Country & compliance card — pick the agency's country to apply its
  // currency/locale and surface the childcare, privacy, PCI and tax frameworks.
  // Rendered on the Centres tab (the Branding tab's #admin-branding hash is taken
  // by the reseller branding screen). Self-contained: appends its own card.
  async function renderCountryCard(content) {
    var card = Dom.el('div', { style: 'background:white;padding:24px;border-radius:12px;box-shadow:0 1px 3px rgba(0,0,0,0.04);margin-bottom:24px;' });
    card.appendChild(Dom.el('h3', { style: 'margin:0 0 4px;font-size:18px;' }, '🌍 Country & compliance'));
    card.appendChild(Dom.el('div', { style: 'color:var(--ink-500);font-size:13px;margin-bottom:16px;' }, 'Pick the country this agency operates in. KiddieTrac applies the matching currency and surfaces the childcare, privacy, payment-card and tax regulations that apply.'));
    var loadingEl = loading('Loading…'); card.appendChild(loadingEl); content.appendChild(card);
    var info;
    try { info = await Api.get('/admin/country'); }
    catch (e) { loadingEl.replaceWith(errorBox('Could not load country settings: ' + (e.message || 'error'))); return; }
    loadingEl.remove();

    var topRow = Dom.el('div', { style: 'display:flex;gap:14px;align-items:flex-end;flex-wrap:wrap;margin-bottom:14px;' });
    var selWrap = Dom.el('div', { style: 'flex:1;min-width:220px;' });
    selWrap.appendChild(Dom.el('label', { style: 'display:block;font-size:13px;font-weight:600;margin-bottom:4px;' }, 'Country'));
    var sel = Dom.el('select', { style: 'width:100%;padding:9px 12px;border:1px solid var(--ink-300);border-radius:8px;font-size:14px;' });
    sel.appendChild(Dom.el('option', { value: '' }, '— Select a country —'));
    info.supported.forEach(function (c) {
      var o = Dom.el('option', { value: c.code }, c.flag + '  ' + c.name + '  (' + c.currency + ')');
      if (info.country === c.code) o.selected = true;
      sel.appendChild(o);
    });
    selWrap.appendChild(sel); topRow.appendChild(selWrap);
    var applyBtn = Dom.el('button', { style: btnPrimary() }, 'Apply');
    topRow.appendChild(applyBtn);
    card.appendChild(topRow);

    var msg = Dom.el('div', { style: 'min-height:18px;font-size:13px;margin-bottom:10px;' });
    card.appendChild(msg);
    var panel = Dom.el('div', {});
    card.appendChild(panel);

    function renderPack(pack, currency, locale) {
      Dom.clear(panel);
      if (!pack) { panel.appendChild(Dom.el('div', { style: 'color:var(--ink-500);font-size:13px;' }, 'No country selected yet.')); return; }
      var meta = Dom.el('div', { style: 'display:flex;gap:18px;flex-wrap:wrap;margin-bottom:14px;font-size:13px;' });
      meta.appendChild(Dom.el('div', {}, Dom.el('span', { style: 'color:var(--ink-500);' }, 'Currency: '), Dom.el('strong', {}, currency || pack.currency)));
      meta.appendChild(Dom.el('div', {}, Dom.el('span', { style: 'color:var(--ink-500);' }, 'Locale: '), Dom.el('strong', {}, locale || pack.locale)));
      panel.appendChild(meta);
      var catColors = { Childcare: '#1F6080', Privacy: '#7C3AED', Payments: '#16A34A', Tax: '#B45309' };
      pack.compliance.forEach(function (f) {
        var item = Dom.el('div', { style: 'display:flex;gap:12px;padding:12px;border:1px solid var(--ink-100,#E5E7EB);border-radius:10px;margin-bottom:8px;' });
        var tag = Dom.el('span', { style: 'flex-shrink:0;align-self:flex-start;background:' + (catColors[f.cat] || '#64748B') + ';color:white;font-size:11px;font-weight:700;padding:3px 8px;border-radius:999px;' }, f.cat);
        var txt = Dom.el('div', {});
        txt.appendChild(Dom.el('div', { style: 'font-weight:700;font-size:14px;' }, f.label));
        txt.appendChild(Dom.el('div', { style: 'font-size:12.5px;color:var(--ink-500);margin-top:2px;' }, f.desc));
        item.appendChild(tag); item.appendChild(txt);
        panel.appendChild(item);
      });
      panel.appendChild(Dom.el('div', { style: 'font-size:11.5px;color:#94A3B8;margin-top:6px;' }, 'These frameworks are indicative defaults to guide setup — your agency remains responsible for verifying local legal compliance.'));
    }
    renderPack(info.pack, info.currency, info.locale);

    applyBtn.addEventListener('click', async function () {
      if (!sel.value) { msg.style.color = '#DC2626'; msg.textContent = 'Pick a country first.'; return; }
      applyBtn.disabled = true; msg.style.color = '#1F6080'; msg.textContent = 'Applying…';
      try {
        var r = await Api.patch('/admin/country', { country: sel.value });
        msg.style.color = '#16A34A'; msg.textContent = '✓ Applied ' + r.country + ' — currency set to ' + r.currency + '.';
        renderPack(r.pack, r.currency, r.locale);
        if (Dom.toast) Dom.toast('Country compliance applied: ' + r.currency, 'success');
      } catch (e) {
        msg.style.color = '#DC2626'; msg.textContent = 'Failed: ' + (e.message || 'error');
      } finally { applyBtn.disabled = false; }
    });
  }

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

  // v22p45: bearer-token CSV downloader. Pulls via fetch so we can attach
  // the auth header + X-Active-Agency-Id, then triggers an anchor click
  // for the blob download.
  function downloadCsv(path, filename, btn) {
    const apiBase = (window.KT && window.KT.API_BASE) || 'https://api.kiddietrac.com/api/v1';
    const token = sessionStorage.getItem('kt_token');
    const activeAgencyId = sessionStorage.getItem('kt_active_agency_id') || '';
    const headers = { 'Authorization': 'Bearer ' + token, 'Accept': 'text/csv' };
    if (activeAgencyId) headers['X-Active-Agency-Id'] = activeAgencyId;
    const original = btn ? btn.textContent : '';
    if (btn) { btn.disabled = true; btn.textContent = 'Preparing…'; }
    const sep = path.includes('?') ? '&' : '?';
    fetch(apiBase + path + sep + 'format=csv', { headers })
      .then(r => { if (!r.ok) throw new Error('HTTP ' + r.status); return r.blob(); })
      .then(blob => {
        const url = URL.createObjectURL(blob);
        const a = document.createElement('a');
        a.href = url;
        a.download = filename;
        document.body.appendChild(a); a.click();
        setTimeout(() => { URL.revokeObjectURL(url); a.remove(); }, 500);
      })
      .catch(e => alert('CSV failed: ' + e.message))
      .finally(() => { if (btn) { btn.disabled = false; btn.textContent = original; } });
  }
  function errorBox(text) {
    return Dom.el('div', { style: 'padding: 24px; background: #FEF2F2; color: #991B1B; border-radius: 8px;' }, text);
  }
  function emptyMsg(text, opts) {
    // v22p12.1: optional illustration on empty states.
    opts = opts || {};
    var wrap = Dom.el('div', { class: 'kt-empty', style: 'background: white; border-radius: 14px;' });
    if (opts.illustration && window.KT && window.KT.Illustrations && window.KT.Illustrations[opts.illustration]) {
      var svgHolder = Dom.el('div', { class: 'kt-empty-svg' });
      svgHolder.innerHTML = window.KT.Illustrations[opts.illustration]();
      wrap.appendChild(svgHolder);
    }
    if (opts.title) wrap.appendChild(Dom.el('h3', {}, opts.title));
    wrap.appendChild(Dom.el('p', {}, text));
    return wrap;
  }

  // v22p12.1: shared tab hero — gradient strip with title + subtitle.
  // Pass illustration: name of a KT.Illustrations function (optional).
  function tabHero(title, subtitle, illustration) {
    var wrap = Dom.el('div', { class: 'kt-hero', style: 'padding: 24px 28px; margin-bottom: 20px;' });
    wrap.appendChild(Dom.el('h1', { style: 'font-size: 24px; margin: 0 0 4px;' }, title));
    if (subtitle) wrap.appendChild(Dom.el('div', { class: 'kt-hero-sub', style: 'font-size: 14px;' }, subtitle));
    if (illustration && window.KT && window.KT.Illustrations && window.KT.Illustrations[illustration]) {
      var svgHolder = Dom.el('div', { class: 'kt-hero-svg', style: 'width: 180px; height: 140px; right: 16px; bottom: -4px;' });
      svgHolder.innerHTML = window.KT.Illustrations[illustration]();
      wrap.appendChild(svgHolder);
    }
    return wrap;
  }
  function btnPrimary() {
    return 'background: var(--brand-blue, #1F6080); color: white; border: none; padding: 10px 16px; border-radius: 8px; font-weight: 700; cursor: pointer; font-size: 14px;';
  }
  function btnSecondary() {
    return 'background: transparent; color: #DC2626; border: 1px solid #DC2626; padding: 10px 16px; border-radius: 8px; font-weight: 600; cursor: pointer; font-size: 14px;';
  }
  // v22p48: hard guard against rendering a user-avatar URL where a centre
  // logo is expected. If centre.logo_url ever picks up a /storage/avatars/
  // path (data contamination, browser cache, etc.) we discard it and fall
  // back to the placeholder initial rather than showing the wrong identity.
  function isUsableLogoUrl(url) {
    if (!url) return false;
    return !/\/storage\/avatars\//i.test(String(url));
  }

  // v22p3.4: 64px centre logo preview (image if logo_url set, else placeholder)
  function renderCentreLogoPreview(centre) {
    var wrap = Dom.el('div', {
      style: 'flex-shrink:0;width:64px;height:64px;border-radius:12px;overflow:hidden;background:' + (centre && centre.brand_color || '#E5E7EB') + ';color:white;display:flex;align-items:center;justify-content:center;font-weight:800;font-size:24px;box-shadow:0 1px 3px rgba(0,0,0,0.1);',
    });
    if (centre && isUsableLogoUrl(centre.logo_url)) {
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
