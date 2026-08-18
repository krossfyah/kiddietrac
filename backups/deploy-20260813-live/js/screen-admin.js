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

    // Resolve which section we're on from the hash BEFORE drawing anything. Deep-links are
    // "admin-<tab>" (each is now its own sidebar item); the bare "#admin" is the legacy hub.
    const hash = (window.location.hash || '').replace('#', '');
    const isDeepLink = hash.startsWith('admin-') || hash.startsWith('admin/');
    if (hash.startsWith('admin-')) state.activeTab = hash.replace('admin-', '');
    else if (hash.startsWith('admin/')) state.activeTab = hash.replace('admin/', '');

    // Header — a section-specific title when arriving via a sidebar deep-link (a clean
    // standalone view), else the legacy hub title.
    const SECTION = {
      centres:  ['Centres / Rooms', 'Manage your centres and their rooms.'],
      users:    ['User management', 'Manage staff and admin user accounts.'],
      families: ['Families', 'Manage enrolled families and their children.'],
      branding: ['Branding', 'Customise your portal logo and colours.'],
      billing:  ['Billing', 'Manage your subscription and payment settings.'],
    };
    const sec = SECTION[state.activeTab] || ['Admin', 'Manage centres, users, families, and branding'];
    const header = Dom.el('div', { style: 'margin-bottom: 24px;' });
    header.appendChild(Dom.el('h1', { style: 'font-size: 28px; font-weight: 800; margin: 0;' }, isDeepLink ? sec[0] : 'Admin'));
    header.appendChild(Dom.el('div', { style: 'color: var(--ink-500); margin-top: 4px;' }, isDeepLink ? sec[1] : 'Manage centres, users, families, and branding'));
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
    // Only the bare "#admin" hub shows the tab strip. Each admin-<tab> deep-link is its own
    // sidebar item now, so drawing all 5 tabs on top of e.g. "User management" was the
    // reported redundancy — suppress it on deep-links so each shows just its own content.
    if (!isDeepLink) wrap.appendChild(tabs);

    // Tab content container
    const content = Dom.el('div', { id: 'admin-tab-content' });
    wrap.appendChild(content);

    // Route to tab renderer (active tab was already resolved from the hash above).
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
  // Colour-coded on/off pill for a centre's / room's email delivery switch.
  function emailBadgeEl(on) {
    return Dom.el('span', {
      style: 'display:inline-flex;align-items:center;gap:4px;font-size:11px;font-weight:700;white-space:nowrap;padding:2px 9px;border-radius:20px;'
        + (on ? 'color:#15803D;background:#DCFCE7;' : 'color:#B45309;background:#FEF3C7;'),
    }, on ? '✉️ Email on' : '🔕 Email off');
  }
  // Coloured count chip so the numbers pop at a glance (the "colour coding").
  function countPillEl(value, color, bg) {
    return Dom.el('span', {
      style: 'display:inline-block;min-width:22px;text-align:center;font-weight:800;font-size:13px;'
        + 'color:' + color + ';background:' + bg + ';padding:2px 10px;border-radius:20px;',
    }, String(value));
  }
  function capacityColour(pct) {
    pct = Number(pct || 0);
    if (pct >= 95) return ['#B91C1C', '#FEF2F2'];
    if (pct >= 80) return ['#B45309', '#FFFBEB'];
    return ['#15803D', '#F0FDF4'];
  }

  // Provider (centre) avatar fallback when there's no logo: an adult emoji face
  // (sex guessed from the provider name), not a letter — matches the platform.
  function provFallback(el, name) {
    el.textContent = (window.KT && KT.emojiFor) ? KT.emojiFor(KT.guessSex(name || ''), false) : '🧑';
    try { var w = parseInt(el.style.width, 10) || 32; el.style.fontSize = Math.round(w * 0.62) + 'px'; } catch (e) {}
  }

  // Fill a provider/centre avatar element: prefer the provider's PHOTO (a face →
  // object-fit:cover) matched by email, then the centre LOGO (branding → contain),
  // then an emoji fallback. This is why Chearstine's uploaded photo now shows on
  // Providers & rooms even though her centre has no logo.
  function provAvatarInto(el, c) {
    // NOTE: provider_photo_url IS a /storage/avatars/ path on purpose, so it must
    // NOT be run through isUsableLogoUrl() (that filter deliberately rejects avatar
    // paths so a user photo can't masquerade as a centre LOGO). Just require a URL.
    if (c.provider_photo_url) {
      var ph = Dom.el('img', { src: avatarSrc(c.provider_photo_url), alt: c.name || '', style: 'width:100%;height:100%;object-fit:cover;' });
      ph.addEventListener('error', function () { ph.remove(); provAvatarLogo(el, c); });
      el.appendChild(ph);
    } else {
      provAvatarLogo(el, c);
    }
  }
  function provAvatarLogo(el, c) {
    if (isUsableLogoUrl(c.logo_url)) {
      var img = Dom.el('img', { src: avatarSrc(c.logo_url), alt: c.name || '', style: 'width:100%;height:100%;object-fit:contain;background:white;' });
      img.addEventListener('error', function () { img.remove(); provFallback(el, c.name); });
      el.appendChild(img);
    } else {
      provFallback(el, c.name);
    }
  }

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

    // Country & compliance used to render here, on the list of providers. It is an
    // AGENCY-level setting - country, currency, regulatory framework - so it now
    // lives on the Agency overview and in Branding & settings, via KT.renderCountryCard.

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

    var view = localStorage.getItem('kt_view_centres') || localStorage.getItem('kt_view_pref') || 'table';
    if (view === 'cards') {
      content.appendChild(renderCentresCards(data.centres, content));
      maybeAutoOpenCentre(data.centres, content);
      return;
    }

    // Table
    const table = Dom.el('table', { style: 'width: 100%; background: white; border-radius: 12px; overflow: hidden; border-collapse: collapse; box-shadow: 0 1px 3px rgba(0,0,0,0.04);' });
    const thead = Dom.el('thead', { style: 'background: var(--ink-50, #F9FAFB);' });
    const headRow = Dom.el('tr', {});
    ['Name', 'City', 'Status', 'Enrolled', 'Capacity %', 'Families', 'Staff', 'Email', ''].forEach(h => {
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
      provAvatarInto(miniLogo, c);
      nameWrap.appendChild(miniLogo);
      var nameStack = Dom.el('div');
      nameStack.appendChild(Dom.el('div', {}, c.name));
      if (c.tagline) nameStack.appendChild(Dom.el('div', { style: 'font-size:11px;color:var(--ink-500);font-weight:500;margin-top:2px;' }, c.tagline));
      nameWrap.appendChild(nameStack);
      nameCell.appendChild(nameWrap);
      row.appendChild(nameCell);
      row.appendChild(Dom.el('td', { style: 'padding: 14px 16px; color: var(--ink-500);' }, c.city || '—'));
      row.appendChild(Dom.el('td', { style: 'padding: 14px 16px;' }, statusBadge(c.status)));
      const enrolledCell = Dom.el('td', { style: 'padding: 14px 16px;' });
      enrolledCell.appendChild(countPillEl(c.enrolled_count || 0, '#1D4ED8', '#EFF6FF'));
      if (c.license_capacity) enrolledCell.appendChild(Dom.el('span', { style: 'color:var(--ink-500);font-size:12px;margin-left:6px;' }, '/ ' + c.license_capacity));
      row.appendChild(enrolledCell);
      const capCol = capacityColour(c.capacity_pct);
      const capCell = Dom.el('td', { style: 'padding: 14px 16px;' });
      capCell.appendChild(countPillEl((c.capacity_pct || 0) + '%', capCol[0], capCol[1]));
      row.appendChild(capCell);
      const famCell = Dom.el('td', { style: 'padding: 14px 16px;' });
      famCell.appendChild(countPillEl(c.family_count || 0, '#334155', '#F1F5F9'));
      row.appendChild(famCell);
      const staffCell = Dom.el('td', { style: 'padding: 14px 16px;' });
      staffCell.appendChild((c.staff_count || 0) === 0 ? countPillEl(0, '#B91C1C', '#FEF2F2') : countPillEl(c.staff_count, '#15803D', '#F0FDF4'));
      row.appendChild(staffCell);
      const emailCell = Dom.el('td', { style: 'padding: 14px 16px;' });
      emailCell.appendChild(emailBadgeEl(c.email_enabled !== false));
      row.appendChild(emailCell);
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
      provAvatarInto(logo, c);
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
      strip.appendChild(emailBadgeEl(c.email_enabled !== false));
      card.appendChild(strip);

      // stats grid — colour-coded tiles for at-a-glance scanning.
      var stats = Dom.el('div', { style: 'display:grid;grid-template-columns:repeat(3, 1fr);gap:0;border-top:1px solid var(--ink-100,#E5E7EB);' });
      function statCell(label, value, color, bg) {
        var cell = Dom.el('div', { style: 'padding:10px 8px;text-align:center;background:' + bg + ';' });
        cell.appendChild(Dom.el('div', { style: 'font-size:18px;font-weight:800;color:' + color + ';' }, String(value)));
        cell.appendChild(Dom.el('div', { style: 'font-size:10px;color:var(--ink-500);text-transform:uppercase;letter-spacing:0.5px;margin-top:2px;' }, label));
        return cell;
      }
      var staffZero = (c.staff_count || 0) === 0;
      stats.appendChild(statCell('Enrolled', (c.enrolled_count || 0) + ' / ' + (c.license_capacity || 0), '#1D4ED8', '#EFF6FF'));
      stats.appendChild(statCell('Families', c.family_count || 0, '#334155', '#F8FAFC'));
      stats.appendChild(statCell('Staff', c.staff_count || 0, staffZero ? '#B91C1C' : '#15803D', staffZero ? '#FEF2F2' : '#F0FDF4'));
      card.appendChild(stats);

      card.addEventListener('click', function () { showCentreModal(c, content); });
      grid.appendChild(card);
    });
    return grid;
  }

  function showCentreModal(centre, content, onSaved) {
    const isEdit = !!centre;
    const body = Dom.el('div', {});
    const form = Dom.el('form', {});

    const fields = [
      { key: 'name', label: 'Centre name', required: true },
      { key: 'license_number', label: 'License number' },
      { key: 'license_capacity', label: 'Maximum children enrolled (capacity)', type: 'number' },
      { key: 'open_time', label: 'Opening time', type: 'time' },
      { key: 'close_time', label: 'Closing time', type: 'time' },
      { key: 'address_line1', label: 'Address' },
      { key: 'city', label: 'City' },
      { key: 'province', label: 'Province', default: 'ON' },
      { key: 'postal_code', label: 'Postal code' },
      { key: 'phone', label: 'Phone' },
      { key: 'email', label: 'Email', type: 'email' },
    ];

    const inputs = {};
    const edited = {};   // last value typed per field, immune to a re-render
    fields.forEach(f => {
      const wrap = Dom.el('div', { style: 'margin-bottom: 12px;' });
      wrap.appendChild(Dom.el('label', { style: 'display: block; font-size: 13px; font-weight: 600; margin-bottom: 4px;' }, f.label + (f.required ? ' *' : '')));
      const input = Dom.el('input', {
        type: f.type || 'text',
        style: 'width: 100%; padding: 8px 12px; border: 1px solid var(--ink-300); border-radius: 6px; font-size: 14px; box-sizing: border-box;',
      });
      var rawVal = centre ? (centre[f.key] || '') : (f.default || '');
      if (f.type === 'time' && rawVal) rawVal = String(rawVal).slice(0, 5); // HH:MM:SS → HH:MM
      input.value = rawVal;
      if (f.required) input.required = true;
      inputs[f.key] = input;
      // Remember it as it is TYPED. Reading .value only when Save is pressed loses
      // the edit if anything re-renders or replaces the input in between — which is
      // how a capacity change could be sent back as the value it already had.
      input.addEventListener('input', function () { edited[f.key] = input.value; });
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

    // Open days — which days the centre operates. Drives which day columns the
    // weekly menu shows (staff editor + parent view). Defaults to Mon–Fri.
    const openWrap = Dom.el('div', { style: 'margin: 14px 0;' });
    openWrap.appendChild(Dom.el('label', { style: 'display:block;font-size:13px;font-weight:600;margin-bottom:6px;' }, 'Open days'));
    const dayDefs = [[1, 'Mon'], [2, 'Tue'], [3, 'Wed'], [4, 'Thu'], [5, 'Fri'], [6, 'Sat'], [7, 'Sun']];
    const initialDays = (centre && Array.isArray(centre.open_days) && centre.open_days.length) ? centre.open_days.slice() : [1, 2, 3, 4, 5];
    const selectedDays = new Set(initialDays);
    const chipRow = Dom.el('div', { style: 'display:flex;flex-wrap:wrap;gap:6px;' });
    const paintChip = (btn, on) => { btn.style.cssText = 'padding:6px 13px;border-radius:20px;font-size:13px;font-weight:700;cursor:pointer;border:1.5px solid ' + (on ? '#1F6080' : '#D1D5DB') + ';background:' + (on ? '#1F6080' : '#fff') + ';color:' + (on ? '#fff' : '#4B5563') + ';'; };
    dayDefs.forEach(function (pair) {
      const btn = Dom.el('button', { type: 'button' }, pair[1]);
      paintChip(btn, selectedDays.has(pair[0]));
      btn.addEventListener('click', function () {
        if (selectedDays.has(pair[0])) selectedDays.delete(pair[0]); else selectedDays.add(pair[0]);
        paintChip(btn, selectedDays.has(pair[0]));
      });
      chipRow.appendChild(btn);
    });
    openWrap.appendChild(chipRow);
    openWrap.appendChild(Dom.el('div', { style: 'font-size:11.5px;color:#6B7280;margin-top:5px;' }, 'Only these days appear on the weekly menu.'));
    form.appendChild(openWrap);

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

      // Provider bio — required. Sent to parents in the welcome email when a
      // family is assigned to this provider, so they know who's caring for their
      // child. A short, warm first-person introduction works best.
      const bioWrap = Dom.el('div', { style: 'margin-bottom:12px;' });
      bioWrap.appendChild(Dom.el('label', { style: 'display:block;font-size:13px;font-weight:600;margin-bottom:4px;' }, 'Provider bio'));
      const bioIn = Dom.el('textarea', { placeholder: "Hi! I'm … I've cared for children for … years. I believe every child grows best with …", style: 'width:100%;min-height:88px;padding:9px 12px;border:1px solid var(--ink-300);border-radius:6px;font-size:14px;box-sizing:border-box;font-family:inherit;resize:vertical;' });
      bioIn.value = centre.provider_bio || '';
      inputs.provider_bio = bioIn;
      bioWrap.appendChild(bioIn);
      bioWrap.appendChild(Dom.el('div', { style: 'font-size:11.5px;color:#64748B;margin-top:5px;line-height:1.5;' }, 'Emailed to parents when a family joins this provider, so they feel confident about who is caring for their child. Strongly recommended — and once added it cannot be removed.'));
      form.appendChild(bioWrap);
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
        rotBtn.addEventListener('click', async function () {
          if (kioskState.token && !await KT.confirm('Rotate the kiosk token? The current URL will stop working immediately — any tablet using the old link will need the new one.')) return;
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
          var qrBox = Dom.el('div', { style: 'width:96px;height:96px;flex-shrink:0;display:flex;align-items:center;justify-content:center;color:#64748B;font-size:11px;' }, 'QR…');
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
        try { await Api.delete('/admin/centres/' + centre.id); if (typeof onSaved === 'function') { await onSaved(); } else { await renderCentresTab(content); } Shell.Modal.close(); if (window.KT.Dom && window.KT.Dom.toast) window.KT.Dom.toast('Centre archived', 'success'); }
        catch (e) { dzMsg.textContent = 'Could not archive: ' + (e.message || 'error'); dzMsg.style.color = '#DC2626'; }
      });
      delBtn.addEventListener('click', async () => {
        if (!(window.KT && window.KT.confirm) || !await window.KT.confirm({ title: 'Permanently delete “' + centre.name + '”?', description: 'This cannot be undone. Archive instead if you might need it back.', tone: 'danger' })) return;
        try { await Api.delete('/admin/centres/' + centre.id + '/permanent'); if (typeof onSaved === 'function') { await onSaved(); } else { await renderCentresTab(content); } Shell.Modal.close(); if (window.KT.Dom && window.KT.Dom.toast) window.KT.Dom.toast('Centre permanently deleted', 'success'); }
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
              // The typed value wins over whatever the DOM currently holds.
              else data[k] = (k in edited) ? edited[k] : el.value;
            });
            // A refusal has to be impossible to miss. This status line sits at the
            // bottom of a long form: edit a field near the top, press Save, and the
            // reason renders below the fold — so the button looks broken, and the
            // save leaves no trace anywhere because it never left the browser.
            const refuse = (message, field) => {
              status.style.color = '#DC2626';
              status.textContent = message;
              _toast('⚠️', 'Not saved', message, '#DC2626');
              const el = field && inputs[field];
              if (el) {
                el.style.borderColor = '#DC2626';
                el.style.background = '#FEF2F2';
                const clear = function () {
                  el.style.borderColor = 'var(--ink-300)';
                  el.style.background = '';
                  el.removeEventListener('input', clear);
                };
                el.addEventListener('input', clear);
                try { el.scrollIntoView({ block: 'center', behavior: 'smooth' }); } catch (_) {}
                try { el.focus(); } catch (_) {}
              } else {
                try { status.scrollIntoView({ block: 'center', behavior: 'smooth' }); } catch (_) {}
              }
            };
            if (!data.name) {
              refuse('Name is required.', 'name');
              return;
            }
            // Only REMOVING an existing bio is refused. Requiring one before any
            // other field can be saved blocked ordinary edits — a capacity change on
            // a provider that never had a bio simply would not save.
            const bioExisted = !!(centre && String(centre.provider_bio || '').trim());
            const bioNowEmpty = inputs.provider_bio && (!data.provider_bio || !data.provider_bio.trim());
            if (bioExisted && bioNowEmpty) {
              refuse('The provider bio cannot be removed — families are sent it when they join.', 'provider_bio');
              return;
            }
            if (data.license_capacity) data.license_capacity = parseInt(data.license_capacity, 10);
            data.open_days = Array.from(selectedDays).sort(function (a, b) { return a - b; });

            status.style.color = '#1F6080';
            status.textContent = 'Saving...';
            try {
              if (isEdit) {
                await Api.patch('/admin/centres/' + centre.id, data);
              } else {
                await Api.post('/admin/centres', data);
              }
              // Reload — either the caller's custom refresh (e.g. the agency
              // overview, which opens this modal in place) or the Centres tab.
              // Refresh and close FIRST. The save has already succeeded; nothing
              // below may stand between the user and seeing their change.
              if (typeof onSaved === 'function') { await onSaved(); }
              else { await renderCentresTab(content); }
              Shell.Modal.close();

              // Then confirm it really landed. Advisory only — a check that blocks
              // the refresh is worse than no check at all, which is precisely what
              // the first version of this did.
              if (isEdit) {
                Api.get('/admin/centres').then(function (after) {
                  var list = (after && (after.data || after.centres || after)) || [];
                  var rec = (Array.isArray(list) ? list : []).filter(function (c) {
                    return String(c.id) === String(centre.id);
                  })[0];
                  if (!rec) return;
                  // Compare the way the two sides actually express a value: a time
                  // input sends "07:00" and MySQL returns "07:00:00"; a number may
                  // arrive as a string. Raw string equality called every one of
                  // those a failure.
                  var norm = function (v) {
                    if (v === null || v === undefined) return '';
                    if (typeof v === 'boolean') return v ? '1' : '0';
                    var t = String(v).trim();
                    if (/^\d{2}:\d{2}(:\d{2})?$/.test(t)) return t.slice(0, 5);   // time → HH:MM
                    if (t !== '' && !isNaN(Number(t))) return String(Number(t));      // "6" === 6
                    return t;
                  };
                  var missed = Object.keys(data).filter(function (k) {
                    if (!(k in rec) || k === 'open_days') return false;
                    var sent = norm(data[k]);
                    if (sent === '') return false;                 // nothing asked of it
                    return sent !== norm(rec[k]);
                  });
                  if (missed.length) {
                    _toast('⚠️', 'Some changes did not save', missed.join(', ') + ' — please try again.', '#B45309');
                  }
                }).catch(function () { /* advisory only */ });
              }
              // Saved either way — but say what is still missing, since a family
              // assigned to this provider gets no introduction without it.
              if (bioNowEmpty) {
                _toast('✍️', 'Saved — bio still needed',
                  'Families joining this provider will not receive an introduction until a bio is added.', '#B45309');
              }
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
  // Collapsible advisory listing accounts that look like duplicates (shared email,
  // or the same name across different emails). Read-only — helps admins spot messes
  // like one person holding several logins; each member links to Manage.
  function buildDuplicateCard(groups, allUsers, content) {
    const _e = (s) => String(s == null ? '' : s).replace(/[&<>"]/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]));
    const card = Dom.el('div', { style: 'background:#FFFBEB;border:1px solid #FDE68A;border-radius:12px;margin-bottom:16px;overflow:hidden;' });
    const head = Dom.el('button', { type: 'button', style: 'width:100%;display:flex;align-items:center;gap:10px;background:none;border:0;padding:11px 14px;cursor:pointer;font-family:inherit;text-align:left;' });
    head.innerHTML = '<span style="font-size:16px;">⚠️</span><span style="flex:1;font-weight:800;color:#92400E;font-size:13.5px;">' + groups.length + ' possible duplicate ' + (groups.length === 1 ? 'account group' : 'account groups') + '</span><span id="kt-dup-caret" style="color:#B45309;font-weight:800;">▸</span>';
    const bodyWrap = Dom.el('div', { style: 'display:none;padding:0 14px 12px;' });
    head.addEventListener('click', function () {
      const open = bodyWrap.style.display !== 'none';
      bodyWrap.style.display = open ? 'none' : 'block';
      const caret = head.querySelector('#kt-dup-caret'); if (caret) caret.textContent = open ? '▸' : '▾';
    });
    card.appendChild(head); card.appendChild(bodyWrap);

    const roleLbl = { platform_admin: 'Super admin', agency_admin: 'Admin', centre_director: 'Director', educator: 'Educator', home_visitor: 'Home visitor', guardian: 'Parent', sales_rep: 'Sales', auditor: 'Auditor' };
    groups.forEach(function (g) {
      const gWrap = Dom.el('div', { style: 'border-top:1px solid #FDE68A;padding:10px 0 4px;' });
      const badge = g.type === 'email' ? 'Shares an email' : 'Same name';
      gWrap.appendChild(Dom.el('div', { style: 'font-size:11.5px;font-weight:800;color:#B45309;margin-bottom:7px;' },
        badge + ' · ' + g.key));
      g.members.forEach(function (m) {
        // Clickable → open that account's Manage panel (found in the loaded list) so
        // the report is actionable, not a dead end. Falls back to non-clickable if
        // the account isn't in the current (active) list.
        const full = (allUsers || []).filter(function (u) { return u.id === m.id; })[0];
        const clickable = !!(full && content);
        const row = Dom.el('div', { style: 'display:flex;align-items:center;gap:10px;padding:6px 4px;border-radius:8px;' + (clickable ? 'cursor:pointer;' : '') });
        if (clickable) {
          row.title = 'Open ' + (m.name || 'this account') + '’s Manage panel';
          row.addEventListener('mouseenter', function () { row.style.background = '#FEF3C7'; });
          row.addEventListener('mouseleave', function () { row.style.background = 'transparent'; });
          row.addEventListener('click', function () { try { showUserModal(full, content); } catch (e) {} });
        }
        const av = Dom.el('div', { style: 'width:30px;height:30px;border-radius:50%;flex-shrink:0;overflow:hidden;background:#E2E8F0;display:flex;align-items:center;justify-content:center;font-size:12px;font-weight:800;color:#475569;' });
        if (m.photo_url) { const im = Dom.el('img', { src: avatarSrc(m.photo_url), style: 'width:100%;height:100%;object-fit:cover;' }); im.addEventListener('error', function () { im.remove(); av.textContent = (m.name || '?')[0]; }); av.appendChild(im); }
        else av.textContent = (m.name || '?')[0].toUpperCase();
        const roles = (m.roles || []).map(function (r) { return roleLbl[r] || r; }).join(', ');
        // Last sign-in tells an admin which of the duplicates is live vs a stray:
        // "never signed in" is a strong hint the account can be removed.
        let seen, seenColor;
        if (!m.last_login_at) { seen = 'never signed in'; seenColor = '#B45309'; }
        else {
          const dt = new Date(String(m.last_login_at).replace(' ', 'T') + (/[zZ]|[+-]\d\d:?\d\d$/.test(String(m.last_login_at)) ? '' : 'Z'));
          const days = Math.floor((Date.now() - dt.getTime()) / 86400000);
          seen = 'last in ' + (days <= 0 ? 'today' : days === 1 ? 'yesterday' : days < 30 ? days + 'd ago' : dt.toLocaleDateString());
          seenColor = days > 60 ? '#B45309' : '#64748B';
        }
        const info = Dom.el('div', { style: 'flex:1;min-width:0;' });
        info.innerHTML = '<div style="font-size:13px;font-weight:700;color:#0D1B2A;">' + _e(m.name || '(no name)') + (m.username ? ' <span style="font-weight:500;color:#64748B;">@' + _e(m.username) + '</span>' : '') + '</div>'
          + '<div style="font-size:11.5px;color:#64748B;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">' + _e(m.email || '') + (roles ? ' · ' + _e(roles) : '') + ' · <span style="color:' + seenColor + ';font-weight:600;">' + _e(seen) + '</span></div>';
        row.appendChild(av); row.appendChild(info);
        gWrap.appendChild(row);
      });
      bodyWrap.appendChild(gWrap);
    });
    const tip = Dom.el('div', { style: 'font-size:11px;color:#92400E;margin-top:8px;padding-top:8px;border-top:1px dashed #FDE68A;' },
      'Click an account to open its Manage panel. Shared-email accounts are sometimes intentional (one person, several roles) — deactivate the extra only if it’s truly a duplicate.');
    bodyWrap.appendChild(tip);
    return card;
  }

  async function renderUsersTab(content, opts) {
    opts = opts || {};
    const showDeactivated = !!opts.deactivated;
    Dom.clear(content);
    content.appendChild(loading('Loading users...'));

    let data;
    let presenceSet = new Set();
    try {
      data = await Api.get('/admin/users' + (showDeactivated ? '?deactivated=1' : ''));
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
      showDeactivated ? '🗄 Deactivated users' : '👥 User management',
      showDeactivated
        ? (data.users.length + ' deactivated account' + (data.users.length === 1 ? '' : 's') + '. Reactivate one to restore access and its previous roles.')
        : (data.users.length + ' active user' + (data.users.length === 1 ? '' : 's') + '. Invite admins, directors, educators, or parents — each gets their own role-tailored portal.'),
      'bear'
    ));

    // Possible-duplicate-accounts advisory (best-effort; only on the active list).
    if (!showDeactivated) {
      const dupHolder = Dom.el('div');
      content.appendChild(dupHolder);
      Api.get('/admin/duplicate-users').then(function (r) {
        const groups = (r && r.groups) || [];
        if (groups.length) dupHolder.appendChild(buildDuplicateCard(groups, data.users || [], content));
      }).catch(function () {});
    }

    const bar = Dom.el('div', { style: 'display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px; gap: 10px; flex-wrap: wrap;' });
    bar.appendChild(Dom.el('div', { style: 'color: var(--ink-500); font-size: 13px; flex: 1;' }, 'All accounts in your agency'));

    // v22p45: CSV download
    const csvBtn = Dom.el('button', { class: 'kt-act-icon kt-act-ok kt-icon-tip kt-legacy-export', title: 'Download CSV', 'data-kttip': 'Download CSV', 'aria-label': 'Download CSV' }, '📄');
    csvBtn.addEventListener('click', () => downloadCsv('/admin/users', 'users.csv', csvBtn));
    bar.appendChild(csvBtn);

    // Deactivated toggle + Invite share ONE pill style — identical to what
    // applyAddStyle (kt-add-btn-std) applies to the Invite button, so the two are
    // pixel-consistent (auto height, matching padding/line-height).
    var _barPill = 'background:#fff;color:#475569;border:1px solid #CBD5E1;padding:8px 14px;border-radius:8px;font-size:12px;font-weight:600;cursor:pointer;line-height:1.1;display:inline-flex;align-items:center;box-sizing:border-box;';
    // Toggle between active accounts and the deactivated (soft-deleted) ones.
    const toggleBtn = Dom.el('button', { style: _barPill }, showDeactivated ? '← Active users' : '🗄 Deactivated');
    toggleBtn.addEventListener('click', () => renderUsersTab(content, { deactivated: !showDeactivated }));
    bar.appendChild(toggleBtn);

    if (!showDeactivated) {
      const addBtn = Dom.el('button', { class: 'kt-add-btn-std', style: _barPill }, '+ Invite user');
      addBtn.addEventListener('click', () => showInviteModal(content));
      bar.appendChild(addBtn);
    }
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
    if (!showDeactivated) content.appendChild(bulkBar);

    function refreshBulkBar() {
      const n = selectedIds.size;
      bulkBar.style.display = n > 0 ? 'flex' : 'none';
      bulkCount.textContent = n + ' selected';
    }

    async function bulkRun(label, fn) {
      const ids = Array.from(selectedIds);
      if (!ids.length) return;
      if (!await KT.confirm(label + ' for ' + ids.length + ' user' + (ids.length === 1 ? '' : 's') + '?')) return;
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
      content.appendChild(emptyMsg(showDeactivated ? 'No deactivated users. Everyone in your agency is active.' : 'No users yet.'));
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

    ['Name', 'Username', 'Email', 'Roles', 'Status', 'Last login', ''].forEach(h => {
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
      avatarWrap.appendChild(avatarCircle(u, 32, { user: true }));
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
      // Username column (mono, muted em-dash when none)
      row.appendChild(Dom.el('td', { style: 'padding: 14px 16px; color: ' + (u.username ? 'var(--ink-700,#334155)' : 'var(--ink-300,#CBD5E1)') + '; font-size: 13px; font-family: ui-monospace,Menlo,monospace;' }, u.username || '—'));
      row.appendChild(Dom.el('td', { style: 'padding: 14px 16px; color: var(--ink-500); font-size: 13px;' }, u.email));

      const rolesCell = Dom.el('td', { style: 'padding: 14px 16px;' });
      u.roles.forEach(r => {
        rolesCell.appendChild(Dom.el('span', {
          style: 'display: inline-block; background: var(--ink-100, #F3F4F6); padding: 3px 8px; border-radius: 12px; font-size: 11px; font-weight: 600; margin-right: 4px;',
        }, r.replace(/_/g, ' ')));
      });
      row.appendChild(rolesCell);

      row.appendChild(Dom.el('td', { style: 'padding: 14px 16px;' }, statusBadge(u.status)));
      row.appendChild(Dom.el('td', { style: 'padding: 14px 16px; color: var(--ink-500); font-size: 13px; white-space: nowrap;' }, fmtLoginStamp(u.last_login_at)));

      let actionEl;
      if (showDeactivated) {
        const reBtn = Dom.el('button', { style: 'background: #16A34A; color: #fff; border: 0; padding: 6px 14px; border-radius: 6px; cursor: pointer; font-size: 13px; font-weight: 600;' }, '♻ Reactivate');
        reBtn.addEventListener('click', async () => {
          if (!await KT.confirm('Reactivate ' + (u.name || u.email) + '? They will regain access and their previous roles.')) return;
          reBtn.disabled = true; reBtn.textContent = 'Reactivating…';
          try {
            await Api.post('/admin/users/' + u.id + '/reactivate', {});
            await renderUsersTab(content, { deactivated: true });
          } catch (e) {
            reBtn.disabled = false; reBtn.textContent = '♻ Reactivate';
            alert('Could not reactivate: ' + (e && e.message ? e.message : 'error'));
          }
        });
        actionEl = reBtn;
      } else {
        // Icon actions to keep the row clean: View (read-only) + Manage (edit).
        var iconBtn = function (glyph, label, onClick) {
          var b = Dom.el('button', { title: label, 'aria-label': label, style: 'background: transparent; border: 1px solid var(--ink-200,#E2E8F0); width:32px; height:32px; border-radius: 8px; cursor: pointer; font-size: 15px; line-height:1; display:inline-flex; align-items:center; justify-content:center;' }, glyph);
          b.addEventListener('mouseenter', function () { b.style.background = 'var(--ink-50,#F8FAFC)'; });
          b.addEventListener('mouseleave', function () { b.style.background = 'transparent'; });
          b.addEventListener('click', onClick);
          return b;
        };
        actionEl = Dom.el('div', { style: 'display:inline-flex; gap:6px; justify-content:flex-end;' }, [
          iconBtn('ℹ️', 'View details', function () { showUserView(u, content); }),
          iconBtn('⚙️', 'Manage', function () { showUserModal(u, content); }),
          iconBtn('✉️', 'Resend welcome invite', function () { rowResendWelcome(u); }),
          iconBtn('🔑', 'Reset password', function () { rowResetPassword(u); }),
        ]);
      }
      row.appendChild(Dom.el('td', { style: 'padding: 14px 16px; text-align: right;' }, actionEl));

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
      username: { label: 'Username (optional — lets one email have several accounts)' },
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

    // Live username-availability check (unique across active accounts).
    var uOK = true;
    var uHint = Dom.el('div', { style: 'font-size: 12px; margin: -6px 0 12px; min-height: 15px;' });
    inputs.username.parentNode.appendChild(uHint);
    var uTimer = null;
    inputs.username.addEventListener('input', function () {
      var v = inputs.username.value.trim();
      clearTimeout(uTimer);
      if (!v) { uHint.textContent = ''; uOK = true; return; }
      if (!/^[A-Za-z0-9._-]{3,50}$/.test(v)) { uHint.style.color = '#DC2626'; uHint.textContent = '3–50 characters: letters, numbers, . _ -'; uOK = false; return; }
      uHint.style.color = '#64748B'; uHint.textContent = 'Checking availability…'; uOK = false;
      uTimer = setTimeout(function () {
        Api.get('/admin/username-available?username=' + encodeURIComponent(v)).then(function (r) {
          if (inputs.username.value.trim() !== v) return; // stale
          if (r && r.available) { uHint.style.color = '#059669'; uHint.textContent = '✓ “' + v + '” is available'; uOK = true; }
          else { uHint.style.color = '#DC2626'; uHint.textContent = '✗ “' + v + '” is already taken — choose another'; uOK = false; }
        }).catch(function () { uHint.textContent = ''; uOK = true; });
      }, 350);
    });

    // Role select
    const roleWrap = Dom.el('div', { style: 'margin-bottom: 12px;' });
    roleWrap.appendChild(Dom.el('label', { style: 'display: block; font-size: 13px; font-weight: 600; margin-bottom: 4px;' }, 'Role *'));
    const roleSelect = Dom.el('select', { style: 'width: 100%; padding: 8px 12px; border: 1px solid var(--ink-300); border-radius: 6px; font-size: 14px;' });
    // v22p23: platform_admin only listed when caller is platform_admin.
    var inviteRoleOpts = [
      { v: 'centre_director', l: 'Centre director' },
      { v: 'educator', l: 'Educator' },
      { v: 'guardian', l: '👪 Parent / guardian' },
      { v: 'home_visitor', l: 'Home visitor' },
      { v: 'sales_rep', l: '💼 Sales rep' },
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

    // Parents/guardians aren't stand-alone accounts — they're registered together
    // with their child in Families (guardians link to a family, not a centre). When
    // "Parent / guardian" is picked we route to the Add Family wizard instead of
    // creating an orphan login that would see no children. This hint + the toggling
    // below make that switch obvious.
    const parentHint = Dom.el('div', { style: 'display:none; background:#EFF6FF; border-left:3px solid #3B82F6; padding:10px 12px; font-size:13px; color:#1E3A8A; border-radius:4px; margin-bottom:12px;' },
      '👪 Parents are added together with their child. Continue to open Add Family, where you can enter the child, guardians, billing split, and send their invite.');
    body.appendChild(parentHint);

    const note = Dom.el('div', { style: 'background: #ECFDF5; border-left: 3px solid #10B981; padding: 10px 12px; font-size: 13px; color: #065F46; border-radius: 4px; margin-top: 12px;' },
      '✉ An invite email with a secure “set your password” link will be sent to this address automatically.');
    body.appendChild(note);

    // Toggle the form for the Parent path: hide the centre picker + invite note,
    // show the routing hint, and (below) the primary button becomes "Continue to
    // Add Family". Restores everything for staff roles.
    var syncRoleUI = function () {
      var isParent = roleSelect.value === 'guardian';
      parentHint.style.display = isParent ? 'block' : 'none';
      centreWrap.style.display = isParent ? 'none' : '';
      note.style.display       = isParent ? 'none' : '';
      // Relabel the modal's primary button by matching its current text — robust to
      // the footer's exact markup.
      var m = body.closest('.modal');
      if (m) {
        m.querySelectorAll('button').forEach(function (b) {
          var t = (b.textContent || '').trim();
          if (t === 'Create user' || t === 'Continue to Add Family →') {
            b.textContent = isParent ? 'Continue to Add Family →' : 'Create user';
          }
        });
      }
    };
    roleSelect.addEventListener('change', function () { syncRoleUI(); setTimeout(syncRoleUI, 0); });

    const status = Dom.el('div', { style: 'min-height: 20px; color: #DC2626; font-size: 13px; margin: 8px 0;' });
    body.appendChild(status);

    // Duplicate check: warn (and require confirmation) if this email/name already
    // matches an existing user, so we don't create accidental duplicate accounts.
    const _e = (s) => String(s == null ? '' : s).replace(/[&<>"]/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]));
    const dupWarn = Dom.el('div', { style: 'display:none;background:#FFFBEB;border:1px solid #FDE68A;border-radius:9px;padding:10px 12px;margin:2px 0 8px;font-size:12.5px;color:#92400E;' });
    body.insertBefore(dupWarn, status);
    let dupHasMatches = false, dupConfirm = false;
    const runDupCheck = () => {
      const email = (inputs.email && inputs.email.value || '').trim();
      const nm = ((inputs.first_name && inputs.first_name.value || '') + ' ' + (inputs.last_name && inputs.last_name.value || '')).trim();
      if (!email && nm.length < 3) { dupWarn.style.display = 'none'; dupHasMatches = false; return; }
      const qs = email ? 'type=user&email=' + encodeURIComponent(email) : 'type=user&name=' + encodeURIComponent(nm);
      Api.get('/admin/duplicate-check?' + qs).then((r) => {
        const m = (r && r.matches) || [];
        if (!m.length) { dupWarn.style.display = 'none'; dupHasMatches = false; return; }
        dupHasMatches = true; dupConfirm = false;
        dupWarn.style.display = 'block';
        const anyDeact = m.some((x) => x.deactivated);
        dupWarn.innerHTML = '<div style="font-weight:800;margin-bottom:5px;">⚠ Possible existing ' + (m.length > 1 ? 'records' : 'record') + '</div>'
          + m.map((x) => '<div>• ' + _e(x.label) + ' <span style="color:#B45309;">(' + _e(x.detail) + (x.deactivated ? ' · <strong>deactivated</strong>' : '') + ')</span></div>').join('')
          + (anyDeact ? '<div style="margin-top:6px;color:#92400E;">This person already has a <strong>deactivated</strong> account — reactivate it from User management instead of creating a new one.</div>' : '')
          + '<label style="display:flex;gap:7px;align-items:center;margin-top:8px;font-weight:700;cursor:pointer;"><input type="checkbox" id="kt-dup-ok"> I’ve checked — this is a different person</label>';
        const cb = dupWarn.querySelector('#kt-dup-ok');
        if (cb) cb.addEventListener('change', () => { dupConfirm = cb.checked; });
      }).catch(() => {});
    };
    if (inputs.email) inputs.email.addEventListener('blur', runDupCheck);
    if (inputs.last_name) inputs.last_name.addEventListener('blur', runDupCheck);

    Shell.Modal.open({
      title: 'Invite user',
      body: body,
      actions: [{
        label: 'Create user',
        primary: true,
        onClick: async () => {
          const data = {};
          ['first_name', 'last_name', 'email', 'phone', 'role'].forEach(k => { data[k] = inputs[k].value; });
          // Block until a likely-duplicate is confirmed as a genuinely new person.
          if (dupHasMatches && !dupConfirm) {
            status.style.color = '#B45309';
            status.textContent = 'This looks like an existing record — tick the confirmation box above, or use the existing account.';
            return;
          }
          // Parent / guardian → this isn't a stand-alone account. Route to the Add
          // Family wizard (the flag makes the Families tab auto-open it) rather than
          // POST /admin/users, which doesn't accept guardian and would orphan them.
          if (data.role === 'guardian') {
            try { sessionStorage.setItem('kt_open_add_family', '1'); } catch (e) {}
            Shell.Modal.close();
            // Go to the Families tab; the flag makes it auto-open Add Family. If we're
            // somehow already there, bounce the hash so hashchange still fires.
            if (window.location.hash === '#admin-families') { window.location.hash = ''; setTimeout(function () { window.location.hash = 'admin-families'; }, 0); }
            else { window.location.hash = 'admin-families'; }
            return;
          }
          if (inputs.username && inputs.username.value.trim()) data.username = inputs.username.value.trim();
          if (data.username && !uOK) { status.style.color = '#DC2626'; status.textContent = 'That username is taken — please choose an available one.'; return; }
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

  // Read-only user details (the "View" eye icon). Kept separate from Manage so
  // most look-ups don't open the full editing surface.
  function showUserView(u, content) {
    var fmtDT = function (d) { if (!d) return '—'; try { return new Date(d).toLocaleString(); } catch (e) { return d; } };
    var rowEl = function (label, val, mono) {
      return Dom.el('div', { style: 'display:grid;grid-template-columns:130px 1fr;gap:6px 14px;padding:9px 0;border-top:1px solid var(--ink-100,#EEF2F6);' }, [
        Dom.el('div', { style: 'font-size:12.5px;color:var(--ink-500,#64748B);font-weight:600;' }, label),
        Dom.el('div', { style: 'font-size:13.5px;color:var(--ink-800,#0f172a);' + (mono ? 'font-family:ui-monospace,Menlo,monospace;' : '') }, val || '—'),
      ]);
    };
    var body = Dom.el('div', {});
    var head = Dom.el('div', { style: 'display:flex;align-items:center;gap:14px;margin-bottom:6px;' });
    head.appendChild(avatarCircle(u, 52, { user: true }));
    head.appendChild(Dom.el('div', {}, [
      Dom.el('div', { style: 'font-size:18px;font-weight:800;color:#0f172a;' }, u.name || u.email),
      Dom.el('div', { style: 'margin-top:4px;' }, statusBadge(u.status)),
    ]));
    body.appendChild(head);
    // What the list row already knows — shown immediately so the dialog is never
    // empty while the full record is on its way.
    body.appendChild(rowEl('Username', u.username || '—', true));
    body.appendChild(rowEl('Email', u.email));
    body.appendChild(rowEl('Phone', u.phone));
    body.appendChild(rowEl('Roles', (u.roles || []).map(function (r) { return r.replace(/_/g, ' '); }).join(', ') || '—'));
    body.appendChild(rowEl('Last login', fmtDT(u.last_login_at)));
    body.appendChild(rowEl('Onboarded', u.onboarded_at ? fmtDT(u.onboarded_at) : 'Not yet'));
    body.appendChild(rowEl('Created', fmtDT(u.created_at)));

    // Then EVERYTHING they entered. The list payload carries a handful of account
    // fields; the record endpoint carries the bio, address, date of birth,
    // emergency contacts and every role-specific onboarding answer. Rendering the
    // record itself means a question added to onboarding later appears here without
    // anyone remembering to add a line to this file.
    var loading = Dom.el('div', { style: 'padding:10px 0;font-size:12.5px;color:#94A3B8;' }, 'Loading the rest of the record…');
    body.appendChild(loading);
    Api.get('/admin/users/' + u.id + '/profile').then(function (d) {
        if (loading.parentNode) loading.parentNode.removeChild(loading);
        var rec = (d && d.record) || {};
        // Skip what is already above, and the name shown in the header.
        var shown = { 'Username': 1, 'Email': 1, 'Phone': 1, 'Role': 1, 'Roles': 1, 'Status': 1,
                      'Last login': 1, 'Onboarded': 1, 'Created': 1, 'First name': 1, 'Last name': 1 };
        var keys = Object.keys(rec).filter(function (k) { return !shown[k]; });
        if (!keys.length) {
            body.appendChild(Dom.el('div', { style: 'padding:8px 0;font-size:12.5px;color:#94A3B8;' },
                'Nothing further was captured for this user.'));
            return;
        }
        keys.forEach(function (label) {
            var value = String(rec[label]);
            // A bio is a paragraph, not a table cell — give it room to breathe.
            if (value.length > 90) {
                var wrap = Dom.el('div', { style: 'padding:10px 0;border-top:1px solid #F1F5F9;' });
                wrap.appendChild(Dom.el('div', { style: 'font-size:11px;font-weight:700;color:#94A3B8;letter-spacing:.4px;text-transform:uppercase;margin-bottom:4px;' }, label));
                wrap.appendChild(Dom.el('div', { style: 'font-size:13.5px;color:#0F172A;line-height:1.55;white-space:pre-wrap;' }, value));
                body.appendChild(wrap);
            } else {
                body.appendChild(rowEl(label, value));
            }
        });
    }).catch(function () {
        if (loading.parentNode) loading.parentNode.removeChild(loading);
        body.appendChild(Dom.el('div', { style: 'padding:8px 0;font-size:12.5px;color:#B45309;' },
            'Could not load the full record — open Manage for the complete profile.'));
    });

    Shell.Modal.open({
      title: 'User details',
      body: body,
      actions: [
        { label: 'Manage', primary: true, onClick: function () { Shell.Modal.close(); setTimeout(function () { showUserModal(u, content); }, 60); } },
        { label: 'Close', onClick: function () { Shell.Modal.close(); } },
      ],
    });
  }

  function _toast(icon, title, body, color) {
    if (window.KT && KT.toast) KT.toast(icon, title, body, color);
    else window.alert(title + (body ? ' — ' + body : ''));
  }
  async function _confirm(msg) {
    return (window.KT && KT.confirm) ? await KT.confirm(msg) : window.confirm(msg);
  }
  // Per-row account actions (also available inside the Manage modal).
  async function rowResendWelcome(u) {
    if (!await _confirm('Resend the welcome invite to ' + (u.email || u.name) + '? A new temporary password will be generated.')) return;
    try {
      var r = await Api.post('/admin/users/' + u.id + '/resend-welcome', {});
      _toast(r && r.email_sent ? '✅' : '⚠️', r && r.email_sent ? 'Welcome email sent' : 'Email failed',
        r && r.email_sent ? (u.email || '') : ('Share temp password: ' + ((r && r.temp_password) || '')),
        r && r.email_sent ? '#16A34A' : '#B45309');
    } catch (e) { _toast('⚠️', 'Resend failed', e && e.message ? e.message : 'error', '#DC2626'); }
  }
  async function rowResetPassword(u) {
    if (!await _confirm("Reset " + (u.name || u.email) + "'s password? A new temporary password will be emailed to " + (u.email || 'them') + '.')) return;
    try {
      var r = await Api.post('/admin/users/' + u.id + '/reset-password', { send_email: true });
      _toast(r && r.email_sent ? '✅' : '⚠️', r && r.email_sent ? 'Password reset & emailed' : 'Reset done — email failed',
        r && r.email_sent ? (u.email || '') : ('Share manually: ' + ((r && r.temp_password) || '')),
        r && r.email_sent ? '#16A34A' : '#B45309');
    } catch (e) { _toast('⚠️', 'Reset failed', e && e.message ? e.message : 'error', '#DC2626'); }
  }

  function showUserModal(user, content) {
    const root = Dom.el('div', {});
    // v23: two-tab layout so the record isn't one long scroll. "Details" holds
    // profile/status/roles/notes/etc.; "Files" holds documents (incl. signed NDA).
    const tabBar = Dom.el('div', { style: 'display:flex;gap:8px;margin-bottom:16px;border-bottom:1px solid var(--ink-100,#E5E7EB);' });
    const paneDetails = Dom.el('div', {});
    const paneFiles = Dom.el('div', { style: 'display:none;' });
    const _tabs = [];
    function mkTab(label, pane) {
      const b = Dom.el('button', { type: 'button', style: 'appearance:none;background:none;border:0;border-bottom:2px solid transparent;padding:8px 4px;margin-bottom:-1px;font-size:13.5px;font-weight:700;color:#6B7280;cursor:pointer;' }, label);
      b.addEventListener('click', function () {
        _tabs.forEach(function (t) { t.btn.style.color = '#6B7280'; t.btn.style.borderBottomColor = 'transparent'; t.pane.style.display = 'none'; });
        b.style.color = '#1F6080'; b.style.borderBottomColor = '#1F6080'; pane.style.display = '';
      });
      _tabs.push({ btn: b, pane: pane });
      tabBar.appendChild(b);
      return b;
    }
    const _detailsTab = mkTab('Details', paneDetails);
    mkTab('📎 Files & documents', paneFiles);
    root.appendChild(tabBar);
    root.appendChild(paneDetails);
    root.appendChild(paneFiles);
    _detailsTab.style.color = '#1F6080'; _detailsTab.style.borderBottomColor = '#1F6080';
    // Existing sections all append to `body` → point it at the Details pane so the
    // rest of this function is unchanged; only the Files card targets paneFiles.
    const body = paneDetails;

    // v22p3.2: avatar row at the top — current avatar + "Change avatar" file picker
    const avatarRow = Dom.el('div', { style: 'display:flex;align-items:center;gap:14px;margin-bottom:18px;padding-bottom:18px;border-bottom:1px solid var(--ink-100,#E5E7EB);' });
    let currentAvatar = avatarCircle(user, 64, { user: true });
    avatarRow.appendChild(currentAvatar);
    const avatarSide = Dom.el('div', { style: 'flex:1;' });
    avatarSide.appendChild(Dom.el('div', { style: 'font-weight:700;font-size:15px;' }, user.name));
    avatarSide.appendChild(Dom.el('div', { style: 'font-size:13px;color:var(--ink-500);margin-bottom:8px;' }, user.email));
    const fileInput = Dom.el('input', { type: 'file', accept: 'image/jpeg,image/png,image/webp', style: 'display:none;' });
    const changeBtn = Dom.el('button', { type: 'button', style: 'padding:6px 12px;background:white;color:#1F6080;border:1.5px solid #1F6080;border-radius:6px;font-size:12px;font-weight:600;cursor:pointer;' }, 'Change avatar');
    const avatarMsg = Dom.el('span', { style: 'font-size:12px;color:var(--ink-500);margin-left:8px;' });
    changeBtn.addEventListener('click', () => fileInput.click());
    fileInput.addEventListener('change', () => {
      const file = fileInput.files[0];
      if (!file) return;
      if (file.size > 20 * 1024 * 1024) { avatarMsg.textContent = 'Image too large (max 20 MB)'; avatarMsg.style.color = '#DC2626'; return; }
      // Reposition/zoom to fill the circle, then upload the cropped high-res result.
      if (window.KT && KT.AvatarCropper) {
        KT.AvatarCropper.open(file, (blob) => { fileInput.value = ''; if (blob) doAvatarUpload(blob); });
      } else {
        doAvatarUpload(file);
      }
    });
    async function doAvatarUpload(blob) {
      changeBtn.disabled = true; changeBtn.textContent = 'Uploading...';
      try {
        const fd = new FormData(); fd.append('avatar', blob, 'avatar.jpg');
        const r = await Api.postForm('/admin/users/' + user.id + '/avatar', fd);
        user.photo_url = r.photo_url;
        const fresh = avatarCircle(user, 64, { user: true });
        currentAvatar.replaceWith(fresh); currentAvatar = fresh;
        avatarMsg.textContent = '✓ Updated'; avatarMsg.style.color = '#16A34A';
      } catch (e) {
        avatarMsg.textContent = 'Failed: ' + (e.message || 'error');
        avatarMsg.style.color = '#DC2626';
      } finally {
        changeBtn.disabled = false; changeBtn.textContent = 'Change avatar';
      }
    }
    avatarSide.appendChild(changeBtn);
    avatarSide.appendChild(fileInput);
    avatarSide.appendChild(avatarMsg);
    avatarRow.appendChild(avatarSide);
    body.appendChild(avatarRow);

    // ── At a glance ──────────────────────────────────────────────────────
    // A read-only summary so an admin sees the whole person immediately — role,
    // status, contact, full address, DOB, emergency contact — without scrolling
    // through the editable form below. Filled from /admin/users/{id}/profile.
    var glance = Dom.el('div', { style: 'margin-bottom:18px;padding:16px 18px;background:#fff;border:1px solid #E5E7EB;border-radius:10px;box-shadow:0 1px 3px rgba(0,0,0,.04);' });
    glance.appendChild(Dom.el('div', { style: 'font-size:11px;font-weight:800;color:#6B7280;letter-spacing:1px;text-transform:uppercase;margin-bottom:12px;' }, 'At a glance'));
    var glanceGrid = Dom.el('div', { style: 'display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:12px 26px;' });
    glance.appendChild(glanceGrid);
    body.appendChild(glance);
    function glanceItem(label, valueNode) {
      var it = Dom.el('div', {});
      it.appendChild(Dom.el('div', { style: 'font-size:11px;font-weight:700;color:#94A3B8;letter-spacing:.4px;text-transform:uppercase;margin-bottom:3px;' }, label));
      if (typeof valueNode === 'string' || valueNode == null) {
        it.appendChild(Dom.el('div', { style: 'font-size:14px;font-weight:600;color:#0F172A;word-break:break-word;' }, (valueNode == null || valueNode === '') ? '—' : valueNode));
      } else {
        var w = Dom.el('div', { style: 'font-size:14px;font-weight:600;color:#0F172A;' }); w.appendChild(valueNode); it.appendChild(w);
      }
      return it;
    }
    // The fields worth reading first; everything else the person entered follows
    // in the order the server built it. Anything not listed here still shows —
    // this is a preference, not a whitelist, which is what went wrong before.
    var GLANCE_FIRST = ['Role', 'Username', 'Email', 'Phone', 'Address', 'Date of birth',
      'Emergency contact', 'Last login', 'Bio'];
    // Long prose (a provider's bio) gets the full width instead of a narrow column.
    function isLongValue(v) { return String(v).length > 90; }

    function fillGlance(ed, record, u) {
      ed = ed || {}; record = record || {};
      Dom.clear(glanceGrid);
      var fullName = [u.first_name, u.last_name].filter(Boolean).join(' ') || u.name || '—';
      var roles = (u.roles && u.roles.length) ? u.roles.map(function (r) { return String(r).replace(/_/g, ' '); }).join(', ') : (record['Role'] || '—');

      // Identity first — name, status and role are the questions an admin opens
      // this modal to answer.
      glanceGrid.appendChild(glanceItem('Full name', fullName));
      glanceGrid.appendChild(glanceItem('Status', statusBadge(u.status || 'active')));
      glanceGrid.appendChild(glanceItem('Role', roles));

      // First name / Last name are already shown as Full name above, and Status
      // and Role are rendered specially — everything else in the record is fair
      // game, whether or not this file knew about it when it was written.
      var skip = { 'First name': 1, 'Last name': 1, 'Status': 1, 'Role': 1 };
      var keys = Object.keys(record).filter(function (k) { return !skip[k]; });
      keys.sort(function (a, b) {
        var ia = GLANCE_FIRST.indexOf(a), ib = GLANCE_FIRST.indexOf(b);
        if (ia === -1 && ib === -1) return 0;      // both extra: keep server order
        if (ia === -1) return 1;
        if (ib === -1) return -1;
        return ia - ib;
      });

      // Fall back to the editable form's values for the few fields the record
      // omits when they were never filled in, so the shape of the view is stable.
      if (keys.indexOf('Username') === -1 && (ed.username || u.username)) {
        glanceGrid.appendChild(glanceItem('Username', ed.username || u.username));
      }
      keys.forEach(function (label) {
        var value = record[label];
        var item = glanceItem(label, String(value));
        if (isLongValue(value)) {
          item.style.gridColumn = '1 / -1';
          var v = item.lastChild;
          if (v) { v.style.fontWeight = '500'; v.style.lineHeight = '1.5'; v.style.whiteSpace = 'pre-wrap'; }
        }
        glanceGrid.appendChild(item);
      });
      if (!keys.length) {
        glanceGrid.appendChild(glanceItem('Email', u.email || '—'));
        glanceGrid.appendChild(glanceItem('Last login', u.last_login_at ? new Date(u.last_login_at).toLocaleString() : 'Never'));
      }
    }
    // Seed immediately with what we already have; the profile fetch enriches it.
    fillGlance({ phone: user.phone, username: user.username }, {}, user);

    // v23 (2026-07-20) — Files & documents filed against this user. The signed
    // Terms/Privacy/NDA lands here automatically (category 'agreement'); admins
    // can attach contracts, certificates, ID, etc. Same list the user sees on
    // mobile under Forms → My documents (/auth/me/documents).
    (function () {
      var uid = user.id;
      var card = Dom.el('div', { style: 'background:#F9FAFB;border-radius:10px;padding:14px;margin-bottom:14px;' });
      card.appendChild(Dom.el('div', { style: 'font-size:11px;font-weight:800;color:#6B7280;letter-spacing:1px;text-transform:uppercase;margin-bottom:2px;' }, '📎 Files & documents'));
      card.appendChild(Dom.el('div', { style: 'font-size:12px;color:#64748B;margin-bottom:10px;' }, 'The signed Terms, Privacy & NDA is filed here automatically. Attach contracts, certificates, ID and more.'));
      var list = Dom.el('div', {});
      card.appendChild(list);

      var catLabels = { agreement: 'NDA / Agreement', file: 'File', contract: 'Contract', certificate: 'Certificate', id: 'ID document', other: 'Other' };
      // Files are public (unguessable /storage paths) — open the direct URL. On
      // the Capacitor APK target=_blank does nothing, so use the in-app Browser
      // plugin when present; else a new tab; else same-window navigation.
      function openDoc(d) {
        var url = avatarSrc(d.file_url);
        if (!url) return;
        // View it in the portal's document panel. Handing it to the browser sent
        // APK users out to an external browser, session and all.
        if (window.KT && KT.viewDocument) {
          KT.viewDocument(url, { title: d.title || d.file_name || 'Document', label: d.category || 'Document' });
          return;
        }
        if (window.KT && KT.openDocumentExternally) { KT.openDocumentExternally(url); return; }
        try { window.location.href = url; } catch (e) {}
      }
      function fmtSize(n) { n = Number(n || 0); if (n < 1024) return n + ' B'; if (n < 1048576) return Math.round(n / 1024) + ' KB'; return (n / 1048576).toFixed(1) + ' MB'; }
      function docIcon(d) {
        if (d.category === 'agreement') return '🔏';
        var t = (d.file_type || '') + ' ' + (d.file_url || '');
        if (/pdf/i.test(t)) return '📄';
        if (/(png|jpe?g|webp|gif|image)/i.test(t)) return '🖼️';
        if (/(doc|word)/i.test(t)) return '📃';
        if (/(xls|sheet|excel|csv)/i.test(t)) return '📊';
        return '📎';
      }
      function paint(docs) {
        Dom.clear(list);
        if (!docs.length) { list.appendChild(Dom.el('div', { style: 'font-size:13px;color:#64748B;padding:8px 0;' }, 'No files yet.')); return; }
        docs.forEach(function (d) {
          var row = Dom.el('div', { style: 'display:flex;align-items:center;gap:10px;padding:9px 10px;background:#fff;border:1px solid #E5E7EB;border-radius:8px;margin-bottom:6px;' });
          row.appendChild(Dom.el('span', { style: 'font-size:20px;flex:0 0 auto;' }, docIcon(d)));
          var mid = Dom.el('div', { style: 'flex:1;min-width:0;' });
          mid.appendChild(Dom.el('div', { style: 'font-weight:700;font-size:13.5px;color:#111827;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;' }, d.title || 'Document'));
          var bits = [catLabels[d.category] || d.category || 'File'];
          if (d.file_size) bits.push(fmtSize(d.file_size));
          if (d.signed_at) bits.push('signed ' + String(d.signed_at).slice(0, 10));
          else if (d.created_at) bits.push(String(d.created_at).slice(0, 10));
          mid.appendChild(Dom.el('div', { style: 'font-size:11.5px;color:#64748B;margin-top:1px;' }, bits.join(' · ')));
          row.appendChild(mid);
          var openBtn = Dom.el('button', { type: 'button', style: 'flex:0 0 auto;font-size:12px;font-weight:700;color:#1F6080;background:#fff;cursor:pointer;padding:5px 10px;border:1px solid #1F6080;border-radius:6px;' }, 'Open');
          openBtn.addEventListener('click', function () { openDoc(d); });
          row.appendChild(openBtn);
          if (d.category === 'agreement') {
            row.appendChild(Dom.el('span', { title: 'Legal record — cannot be deleted', style: 'flex:0 0 auto;font-size:13px;color:#64748B;padding:4px 6px;' }, '🔒'));
          } else {
            var del = Dom.el('button', { type: 'button', class: 'kt-act-icon kt-act-danger kt-icon-tip', title: 'Remove', 'data-kttip': 'Remove', 'aria-label': 'Remove', style: 'flex:0 0 auto;' }, '🗑');
            del.addEventListener('click', async function () {
              if (!await KT.confirm('Remove "' + (d.title || 'this file') + '"?')) return;
              del.disabled = true;
              try { await Api.delete('/admin/users/' + uid + '/documents/' + d.id); load(); }
              catch (e) { del.disabled = false; if (Dom.toast) Dom.toast(e.message || 'Delete failed', 'error'); }
            });
            row.appendChild(del);
          }
          list.appendChild(row);
        });
      }
      function load() {
        Dom.clear(list); list.appendChild(Dom.el('div', { style: 'font-size:13px;color:#64748B;padding:8px 0;' }, 'Loading…'));
        Api.get('/admin/users/' + uid + '/documents')
          .then(function (r) { paint((r && r.documents) || []); })
          .catch(function () { Dom.clear(list); list.appendChild(Dom.el('div', { style: 'font-size:13px;color:#B91C1C;' }, 'Could not load files.')); });
      }

      var up = Dom.el('div', { style: 'display:flex;flex-wrap:wrap;gap:8px;align-items:center;margin-top:10px;padding-top:10px;border-top:1px dashed #E5E7EB;' });
      var titleIn = Dom.el('input', { type: 'text', placeholder: 'Title (optional)', style: 'flex:1;min-width:120px;padding:6px 10px;border:1px solid #D1D5DB;border-radius:6px;font-size:13px;' });
      var catSel = Dom.el('select', { style: 'padding:6px 10px;border:1px solid #D1D5DB;border-radius:6px;font-size:13px;background:#fff;' });
      [['file', 'File'], ['contract', 'Contract'], ['certificate', 'Certificate'], ['id', 'ID document'], ['other', 'Other']].forEach(function (o) { catSel.appendChild(Dom.el('option', { value: o[0] }, o[1])); });
      var fileIn = Dom.el('input', { type: 'file', accept: '.pdf,.jpg,.jpeg,.png,.webp,.doc,.docx,.xls,.xlsx', style: 'display:none;' });
      var upBtn = Dom.el('button', { type: 'button', style: 'padding:7px 14px;background:#1F6080;color:#fff;border:0;border-radius:6px;font-size:12.5px;font-weight:700;cursor:pointer;' }, '＋ Attach file');
      var upMsg = Dom.el('span', { style: 'font-size:12px;color:#6B7280;' });
      upBtn.addEventListener('click', function () { fileIn.click(); });
      fileIn.addEventListener('change', async function () {
        var f = fileIn.files[0]; if (!f) return;
        if (f.size > 10 * 1024 * 1024) { upMsg.style.color = '#B91C1C'; upMsg.textContent = 'Max 10 MB'; return; }
        upBtn.disabled = true; upMsg.style.color = '#6B7280'; upMsg.textContent = 'Uploading…';
        try {
          var fd = new FormData();
          fd.append('file', f);
          if (titleIn.value.trim()) fd.append('title', titleIn.value.trim());
          fd.append('category', catSel.value);
          await Api.postForm('/admin/users/' + uid + '/documents', fd);
          titleIn.value = ''; upMsg.style.color = '#16A34A'; upMsg.textContent = '✓ Attached'; load();
        } catch (e) {
          upMsg.style.color = '#B91C1C'; upMsg.textContent = e.message || 'Upload failed';
        } finally {
          upBtn.disabled = false; fileIn.value = '';
        }
      });
      up.appendChild(titleIn); up.appendChild(catSel); up.appendChild(upBtn); up.appendChild(fileIn); up.appendChild(upMsg);
      card.appendChild(up);

      paneFiles.appendChild(card);
      load();
    })();
    // v22 (2026-06) — account status / deactivate, profile extras & timestamped notes
    (function () {
      var uid = user.id;
      var statusBox = Dom.el('div', { style: 'margin-bottom:18px;padding:14px 16px;background:#F9FAFB;border-radius:10px;border:1px solid #E5E7EB;' });
      statusBox.appendChild(Dom.el('div', { style: 'font-size:11px;font-weight:800;color:#6B7280;letter-spacing:1px;text-transform:uppercase;margin-bottom:8px;' }, 'Account status'));
      var statusRow = Dom.el('div', { style: 'display:flex;align-items:center;gap:12px;' });
      var statusLabel = Dom.el('span', { style: 'font-size:13px;font-weight:700;text-transform:capitalize;' }, (user.status === 'not_invited' ? 'Not invited' : (user.status || 'active')));
      var toggleBtn = Dom.el('button', { style: 'padding:7px 14px;border-radius:7px;border:none;cursor:pointer;font-size:13px;font-weight:700;color:#fff;background:' + (user.status === 'deactivated' ? '#16A34A' : '#DC2626') + ';' }, user.status === 'deactivated' ? 'Reactivate' : 'Deactivate');
      toggleBtn.addEventListener('click', async function () {
        var to = user.status === 'deactivated' ? 'active' : 'deactivated';
        if (to === 'deactivated' && !await KT.confirm('Deactivate ' + (user.name || 'this user') + '? They lose access until reactivated.')) return;
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
      var _pfSt = 'width:100%;box-sizing:border-box;padding:8px 10px;border:1px solid #D1D5DB;border-radius:7px;font-size:13px;margin-bottom:8px;font-family:inherit;';
      function _inp(ph) { return Dom.el('input', { placeholder: ph, style: _pfSt }); }
      function _sub(t) { return Dom.el('div', { style: 'font-size:12px;color:#6B7280;margin:4px 0 3px;font-weight:600;' }, t); }
      function _grid(cols) { return Dom.el('div', { style: 'display:grid;grid-template-columns:repeat(' + cols + ',1fr);gap:8px;' }); }
      // Phones
      var iPhone = _inp('Mobile phone'); var iDirect = _inp('Direct phone'); var iHome = _inp('Home phone');
      // Structured address (writes back to the Full record).
      var iL1 = _inp('Address line 1'); var iL2 = _inp('Address line 2 (optional)');
      var iCity = _inp('City'); var iProv = _inp('Province'); var iPostal = _inp('Postal code');
      var iDob = Dom.el('input', { type: 'date', style: _pfSt });
      var iEcN = _inp('Emergency contact name'); var iEcP = _inp('Emergency contact phone'); var iEcR = _inp('Relationship (e.g. spouse)');

      pf.appendChild(_sub('Phone numbers'));
      var pg = _grid(3); pg.appendChild(iPhone); pg.appendChild(iDirect); pg.appendChild(iHome); pf.appendChild(pg);
      pf.appendChild(_sub('Address'));
      pf.appendChild(iL1); pf.appendChild(iL2);
      var ag = _grid(3); ag.appendChild(iCity); ag.appendChild(iProv); ag.appendChild(iPostal); pf.appendChild(ag);
      pf.appendChild(_sub('Date of birth')); pf.appendChild(iDob);
      pf.appendChild(_sub('Emergency contact')); pf.appendChild(iEcN);
      var eg = _grid(2); eg.appendChild(iEcP); eg.appendChild(iEcR); pf.appendChild(eg);

      var pfSave = Dom.el('button', { style: 'padding:8px 16px;background:#1F6080;color:#fff;border:none;border-radius:7px;cursor:pointer;font-size:13px;font-weight:700;margin-top:4px;' }, 'Save profile');
      var pfMsg = Dom.el('span', { style: 'font-size:12px;font-weight:700;margin-left:10px;' });
      pfSave.addEventListener('click', function () {
        pfSave.disabled = true; pfMsg.textContent = 'Saving...'; pfMsg.style.color = '#6B7280';
        Api.put('/admin/users/' + uid + '/profile', {
          phone: iPhone.value, direct_phone: iDirect.value, home_phone: iHome.value,
          address_line1: iL1.value, address_line2: iL2.value, city: iCity.value, province: iProv.value, postal_code: iPostal.value,
          date_of_birth: iDob.value || null,
          emergency_contact_name: iEcN.value, emergency_contact_phone: iEcP.value, emergency_contact_relation: iEcR.value
        })
          .then(function () { pfMsg.textContent = '✓ Saved'; pfMsg.style.color = '#16A34A'; pfSave.disabled = false; Api.get('/admin/users/' + uid + '/profile').then(function (d) { fillGlance(d.editable, d.record, user); }).catch(function () {}); })
          .catch(function (e) { pfMsg.textContent = '✕ ' + (e && e.message ? e.message : 'Failed'); pfMsg.style.color = '#DC2626'; pfSave.disabled = false; });
      });
      pf.appendChild(pfSave); pf.appendChild(pfMsg); body.appendChild(pf);

      // The "Full record" table that used to sit here is gone: "At a glance"
      // above now renders the same complete record map, so keeping both showed
      // every field twice.

      // This user's background-check records (managed on the Background checks screen).
      (function () {
        var bc = Dom.el('div', { style: 'margin-bottom:18px;padding:14px 16px;background:#F9FAFB;border-radius:10px;border:1px solid #E5E7EB;' });
        bc.appendChild(Dom.el('div', { style: 'font-size:11px;font-weight:800;color:#6B7280;letter-spacing:1px;text-transform:uppercase;margin-bottom:10px;' }, '🛡️ Background checks'));
        var bcBody = Dom.el('div', { style: 'font-size:13px;color:#64748B;' }, 'Loading…');
        bc.appendChild(bcBody); body.appendChild(bc);
        Api.get('/admin/background-checks').then(function (d) {
          var rows = (d.data || d || []).filter(function (r) { return String(r.user_id) === String(user.id); });
          if (!rows.length) { bcBody.textContent = 'No background checks on file.'; return; }
          bcBody.innerHTML = ''; bcBody.style.color = '';
          var apiHost = ((window.KT && KT.API_BASE) || 'https://api.kiddietrac.com/api/v1').replace(/\/api\/v1\/?$/, '');
          rows.forEach(function (r) {
            var col = r.status_bucket === 'expired' ? '#B91C1C' : r.status_bucket === 'expiring' ? '#D97706' : '#047857';
            var line = Dom.el('div', { style: 'display:flex;justify-content:space-between;align-items:center;gap:10px;padding:7px 0;border-bottom:1px solid #EEF2F5;font-size:13px;' });
            var left = Dom.el('div', {});
            left.appendChild(Dom.el('div', { style: 'font-weight:700;text-transform:uppercase;' }, r.check_type || ''));
            left.appendChild(Dom.el('div', { style: 'font-size:12px;color:#64748B;' }, 'Expires ' + (r.expires_at || '—') + (r.reference ? ' · ' + r.reference : '')));
            line.appendChild(left);
            var right = Dom.el('div', { style: 'display:flex;align-items:center;gap:8px;' });
            right.appendChild(Dom.el('span', { style: 'color:' + col + ';font-weight:700;' }, r.status_bucket || ''));
            if (r.document_url) {
              right.appendChild(Dom.el('a', { href: (/^https?:/.test(r.document_url) ? r.document_url : apiHost + r.document_url), target: '_blank', rel: 'noopener', title: 'Open document', style: 'color:#1D4ED8;font-weight:600;text-decoration:none;font-size:15px;' }, '📄'));
            }
            line.appendChild(right); bcBody.appendChild(line);
          });
        }).catch(function () { bcBody.textContent = 'Could not load background checks.'; });
      })();

      var nb = Dom.el('div', { style: 'margin-bottom:18px;padding:14px 16px;background:#F9FAFB;border-radius:10px;border:1px solid #E5E7EB;' });
      nb.appendChild(Dom.el('div', { style: 'font-size:11px;font-weight:800;color:#6B7280;letter-spacing:1px;text-transform:uppercase;margin-bottom:10px;' }, 'Notes'));
      var noteList = Dom.el('div', { style: 'margin-bottom:10px;' }); nb.appendChild(noteList);
      var noteInput = Dom.el('textarea', { placeholder: 'Add a note (timestamped)...', style: 'width:100%;box-sizing:border-box;padding:8px 10px;border:1px solid #D1D5DB;border-radius:7px;font-size:13px;font-family:inherit;resize:vertical;min-height:54px;margin-bottom:8px;' });
      var noteBtn = Dom.el('button', { style: 'padding:8px 16px;background:#1F6080;color:#fff;border:none;border-radius:7px;cursor:pointer;font-size:13px;font-weight:700;' }, 'Add note');
      function renderNotes(notes) {
        noteList.innerHTML = '';
        if (!notes || !notes.length) { noteList.appendChild(Dom.el('div', { style: 'font-size:13px;color:#64748B;' }, 'No notes yet.')); return; }
        notes.forEach(function (n) {
          var d = Dom.el('div', { style: 'padding:8px 0;border-bottom:1px solid #EEF2F5;' });
          d.appendChild(Dom.el('div', { style: 'font-size:13px;color:#0a1e2c;white-space:pre-wrap;' }, n.note));
          d.appendChild(Dom.el('div', { style: 'font-size:11px;color:#64748B;margin-top:2px;' }, (n.created_by_name || 'Admin') + ' · ' + (n.created_at ? new Date(n.created_at).toLocaleString() : '')));
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
        var ed = d.editable || {};
        iPhone.value = ed.phone || ''; iDirect.value = ed.direct_phone || ''; iHome.value = ed.home_phone || '';
        iL1.value = ed.address_line1 || ''; iL2.value = ed.address_line2 || '';
        iCity.value = ed.city || ''; iProv.value = ed.province || ''; iPostal.value = ed.postal_code || '';
        iDob.value = ed.date_of_birth ? String(ed.date_of_birth).slice(0, 10) : '';
        iEcN.value = ed.emergency_contact_name || ''; iEcP.value = ed.emergency_contact_phone || ''; iEcR.value = ed.emergency_contact_relation || '';
        try { fillGlance(ed, d.record, user); } catch (e) {}
        renderNotes(d.notes);
      }).catch(function () { renderNotes([]); });
    })();

    // ── Pay rate (drives the staff member's payslips) ───────────────────
    (function () {
      var uid2 = user.id;
      var roles = user.roles || [];
      var defType = (roles.indexOf && roles.indexOf('home_visitor') > -1 && roles.indexOf('educator') < 0) ? 'per_visit' : 'hourly';
      var sec = Dom.el('div', { style: 'margin-bottom:18px;padding:14px 16px;background:#F9FAFB;border-radius:10px;border:1px solid #E5E7EB;' });
      sec.appendChild(Dom.el('div', { style: 'font-size:11px;font-weight:800;color:#6B7280;letter-spacing:1px;text-transform:uppercase;margin-bottom:10px;' }, '💵 Pay rate'));
      var row = Dom.el('div', { style: 'display:flex;gap:10px;align-items:flex-end;flex-wrap:wrap;' });
      var rateWrap = Dom.el('div', {});
      rateWrap.appendChild(Dom.el('label', { style: 'display:block;font-size:12px;color:#374151;font-weight:600;margin-bottom:4px;' }, 'Rate ($)'));
      var rate = Dom.el('input', { type: 'number', min: '0', step: '0.01', placeholder: '0.00', style: 'width:120px;padding:8px 10px;border:1px solid #D1D5DB;border-radius:8px;font-size:14px;' });
      if (user.pay_rate != null) rate.value = user.pay_rate;
      rateWrap.appendChild(rate);
      var typeWrap = Dom.el('div', {});
      typeWrap.appendChild(Dom.el('label', { style: 'display:block;font-size:12px;color:#374151;font-weight:600;margin-bottom:4px;' }, 'Type'));
      var type = Dom.el('select', { style: 'padding:8px 10px;border:1px solid #D1D5DB;border-radius:8px;font-size:14px;background:#fff;' });
      [['hourly', 'Hourly (× hours)'], ['per_visit', 'Per visit (× visits)'], ['salary', 'Salary / flat (per period)']].forEach(function (t) { type.appendChild(Dom.el('option', { value: t[0] }, t[1])); });
      type.value = user.pay_type || defType;
      typeWrap.appendChild(type);
      var save = Dom.el('button', { type: 'button', style: 'background:#1F6080;color:#fff;border:0;border-radius:8px;padding:9px 16px;font-size:13px;font-weight:700;cursor:pointer;' }, 'Save rate');
      var msg = Dom.el('span', { style: 'font-size:12.5px;color:#16A34A;margin-left:4px;' });
      save.addEventListener('click', function () {
        save.disabled = true; msg.style.color = '#6B7280'; msg.textContent = 'Saving…';
        Api.patch('/admin/users/' + uid2 + '/pay', { pay_rate: rate.value === '' ? null : parseFloat(rate.value), pay_type: type.value })
          .then(function () { msg.style.color = '#16A34A'; msg.textContent = '✓ Saved'; })
          .catch(function (e) { msg.style.color = '#DC2626'; msg.textContent = (e && e.message) || 'Failed'; })
          .then(function () { save.disabled = false; });
      });
      row.appendChild(rateWrap); row.appendChild(typeWrap); row.appendChild(save); row.appendChild(msg);
      sec.appendChild(row);
      sec.appendChild(Dom.el('div', { style: 'font-size:11.5px;color:#64748B;margin-top:8px;' }, 'Set for educators, directors, admins or contractors. Hourly (× hours worked), per-visit (× visits logged), or salary/flat (fixed amount each pay period). Drives their payslips.'));
      body.appendChild(sec);
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
      roomBody.appendChild(Dom.el('div', { style: 'color:#64748B;font-size:13px;' }, 'Loading…'));
      roomSection.appendChild(roomBody);
      body.appendChild(roomSection);

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
        roomBody.appendChild(Dom.el('div', { style: 'color:#64748B;font-size:13px;' },
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
      clockBody.appendChild(Dom.el('div', { style: 'color:#64748B;font-size:13px;' }, 'Loading…'));
      clockSection.appendChild(clockBody);
      body.appendChild(clockSection);

      Api.get('/admin/users/' + user.id + '/punches').then(function (d) {
        Dom.clear(clockBody);
        const rows = d.punches || [];
        if (!rows.length) {
          clockBody.appendChild(Dom.el('div', { style: 'color:#64748B;font-size:13px;' }, 'No clock records for this user.'));
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
            Dom.el('div', { style: 'font-size:11.5px;color:#64748B;' },
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
        clockBody.appendChild(Dom.el('div', { style: 'color:#64748B;font-size:13px;' }, 'Could not load clock records.'));
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
      educator: 'Educator', guardian: 'Parent / guardian', home_visitor: 'Home visitor', sales_rep: 'Sales rep',
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
      { v: 'home_visitor', l: 'Home visitor' },
      { v: 'sales_rep', l: '💼 Sales rep' },
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
      email: user.email || '',
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
      if (!await KT.confirm('Reset ' + user.name + "'s password? A new temporary password will be emailed to " + user.email + '.')) return;
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
      if (!await KT.confirm('Reopen the onboarding wizard for ' + user.name + '? They will be prompted to complete their profile on their next sign-in.')) return;
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
      if (!await KT.confirm('Resend the welcome invite to ' + user.email + '? A new temporary password will be generated.')) return;
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
      const c1 = await KT.confirm('Delete ' + user.name + ' (' + user.email + ')?\n\nThey will be unable to sign in. Their family/child records stay intact for audit.');
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
      body: root,
      actions: [{
        label: 'Save changes',
        primary: true,
        onClick: async () => {
          const data = {};
          Object.keys(inputs).forEach(k => { var val = inputs[k].value; data[k] = (typeof val === 'string') ? val.trim() : val; });
          if (data.email === '') delete data.email; // never blank out the login email
          if (data.email && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(data.email)) {
            status.style.color = '#DC2626'; status.textContent = 'Enter a valid email address.'; return;
          }
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
    const famCsvBtn = Dom.el('button', { class: 'kt-act-icon kt-act-ok kt-icon-tip kt-legacy-export', title: 'Download CSV', 'data-kttip': 'Download CSV', 'aria-label': 'Download CSV' }, '📄');
    famCsvBtn.addEventListener('click', () => downloadCsv('/admin/families', 'families.csv', famCsvBtn));
    bar.appendChild(famCsvBtn);

    const addBtn = Dom.el('button', { style: btnPrimary() }, '+ Add family');
    addBtn.addEventListener('click', () => showFamilyWizard(centresData.centres, content));
    bar.appendChild(addBtn);

    // v22p26: card/table view toggle remembered per-list in localStorage.
    const toggle = viewToggle('kt_view_families', function () { renderFamiliesTab(content); });
    bar.insertBefore(toggle, famCsvBtn);
    content.appendChild(bar);

    // Arrived here from "Invite user → Parent / guardian": open Add Family straight
    // away (one-shot flag). Deferred so the list finishes painting behind the wizard.
    try {
      if (sessionStorage.getItem('kt_open_add_family') === '1') {
        sessionStorage.removeItem('kt_open_add_family');
        setTimeout(function () { showFamilyWizard(centresData.centres, content); }, 120);
      }
    } catch (e) {}

    if (data.families.length === 0) {
      content.appendChild(emptyMsg(
        'Click + Add family to register the first one. You can attach children, set the billing split, and invite guardians from the card afterward.',
        { title: 'No families enrolled yet', illustration: 'emptyFamilies' }
      ));
      return;
    }

    var view = localStorage.getItem('kt_view_families') || localStorage.getItem('kt_view_pref') || 'cards';
    if (view === 'table') {
      content.appendChild(renderFamiliesTable(data.families, centresData.centres, content));
      return;
    }

    const grid = Dom.el('div', { 'data-kt-list': '1', style: 'display: grid; grid-template-columns: repeat(auto-fill, minmax(360px, 1fr)); gap: 16px;' });
    data.families.forEach(f => {
      const card = Dom.el('div', { style: 'background: white; padding: 18px; border-radius: 12px; box-shadow: 0 1px 3px rgba(0,0,0,0.04); cursor: pointer; position: relative;' });

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
      if (f.suspended) {
        card.appendChild(Dom.el('div', { style: 'margin-top: 10px; display:inline-block; background:#FEF3C7; color:#92400E; font-weight:700; font-size:11px; padding:3px 9px; border-radius:20px;' }, '⏸ Suspended'));
      }

      // Action bar as the card's LAST child → ⋮ kebab via kt-row-actions.
      const famActBar = familyActions(f, centresData.centres, content);
      famActBar.style.marginTop = '12px';
      card.appendChild(famActBar);

      card.addEventListener('click', () => showFamilyDetail(f.id));
      grid.appendChild(card);
    });
    content.appendChild(grid);
    if (window.KT && typeof KT.sweepRowActions === 'function') setTimeout(KT.sweepRowActions, 0);
  }

  // v22p26: small toggle for cards/table view, persisted in localStorage.
  // v22p30: third arg `defaultView` lets a list pick which view to use when the
  // user has never set a preference. Families + Children default to 'cards';
  // Centres defaults to 'table' (more columns to compare at a glance).
  function viewToggle(storageKey, onChange, defaultView) {
    // Per-section choice wins; otherwise fall back to the user's GLOBAL
    // card/table preference (kt_view_pref) so a choice made in one section
    // carries to the others they haven't explicitly set.
    var current = localStorage.getItem(storageKey) || localStorage.getItem('kt_view_pref') || defaultView || 'cards';
    var wrap = Dom.el('div', { style: 'display:inline-flex;background:#F3F4F6;border-radius:8px;padding:2px;margin-right:8px;' });
    function btn(view, label, icon) {
      var b = Dom.el('button', {
        type: 'button',
        style: 'background:' + (current === view ? 'white' : 'transparent') + ';color:' + (current === view ? '#1F6080' : '#6B7280') + ';border:none;padding:6px 10px;border-radius:6px;font-size:12px;font-weight:600;cursor:pointer;display:flex;align-items:center;gap:4px;' + (current === view ? 'box-shadow:0 1px 2px rgba(0,0,0,.08);' : ''),
      }, icon + ' ' + label);
      b.addEventListener('click', function () {
        if (current === view) return;
        localStorage.setItem(storageKey, view);
        localStorage.setItem('kt_view_pref', view); // remember globally too
        if (onChange) onChange(view);
      });
      return b;
    }
    wrap.appendChild(btn('cards', 'Cards', '▦'));
    wrap.appendChild(btn('table', 'Table', '☰'));
    return wrap;
  }

  // Shared per-row family action bar → collapses into the standard ⋮ kebab
  // (kt-row-actions). View / Edit / Suspend|Reactivate / Inactivate.
  //  • Suspend (temporary): blocks the family's guardian logins (user.status).
  //  • Reactivate: restores them (shown instead of Suspend when f.suspended).
  //  • Inactivate (permanent): archives the family record (soft-delete).
  function familyActions(f, centres, content) {
    var bar = Dom.el('div', { style: 'display:flex;gap:6px;justify-content:flex-end;flex-shrink:0;' });
    var mk = function (icon, cls, tip, handler) {
      var b = Dom.el('button', { type: 'button', class: 'kt-act-icon ' + cls + ' kt-icon-tip', 'data-kttip': tip, 'aria-label': tip }, icon);
      b.addEventListener('click', function (e) { e.stopPropagation(); handler(); });
      return b;
    };
    bar.appendChild(mk('👁️', 'kt-act-info', 'View', function () { showFamilyDetail(f.id); }));
    bar.appendChild(mk('✏️', 'kt-act-edit', 'Edit', function () { showFamilyModal(f, centres, content); }));
    bar.appendChild(mk('✉️', 'kt-act-teal', 'Send welcome email', async function () {
      if (window.KT && KT.confirm && !await KT.confirm('Send the provider welcome / bio email to this family’s guardians? (Agency admin, director and educator are CC’d.)')) return;
      try { var _r = await Api.post('/admin/families/' + f.id + '/provider-welcome', {}); if (window.KT.Dom && KT.Dom.toast) KT.Dom.toast('Welcome email sent to ' + (_r.recipients || 0) + ' guardian(s)', 'success'); }
      catch (e) { if (window.KT.Dom && KT.Dom.toast) KT.Dom.toast('Could not send: ' + (e.message || 'error'), 'error'); else alert('Could not send: ' + (e.message || 'error')); }
    }));
    if (f.suspended) {
      bar.appendChild(mk('▶️', 'kt-act-teal', 'Reactivate', async function () {
        if (!await KT.confirm('Reactivate this family? Their guardian logins will be restored.')) return;
        try { await Api.post('/admin/families/' + f.id + '/reactivate', {}); await renderFamiliesTab(content); }
        catch (e) { alert('Could not reactivate: ' + (e.message || 'error')); }
      }));
    } else {
      bar.appendChild(mk('⏸️', 'kt-act-info', 'Suspend', async function () {
        if (!await KT.confirm('Suspend this family? Their guardian logins are blocked until you reactivate. Enrollment is kept.')) return;
        try { await Api.post('/admin/families/' + f.id + '/suspend', {}); await renderFamiliesTab(content); }
        catch (e) { alert('Could not suspend: ' + (e.message || 'error')); }
      }));
    }
    bar.appendChild(mk('🗑️', 'kt-act-danger', 'Inactivate', async function () {
      if (!await KT.confirm('Inactivate this family permanently? The record is archived (children + history preserved).')) return;
      try { await Api.delete('/admin/families/' + f.id); await renderFamiliesTab(content); }
      catch (e) { alert('Could not inactivate: ' + (e.message || 'error')); }
    }));
    return bar;
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
      if (!await KT.confirm('Delete ' + ids.length + ' family record(s)? Children + audit history are preserved; only the family-level row is removed.')) return;
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
      if (f.suspended) famWrap.appendChild(Dom.el('span', { style: 'background:#FEF3C7;color:#92400E;font-weight:700;font-size:10px;padding:2px 7px;border-radius:20px;' }, '⏸ Suspended'));
      famCell.appendChild(famWrap);
      tr.appendChild(famCell);
      tr.appendChild(Dom.el('td', { style: 'padding:11px 14px;color:#6B7280;font-size:13px;' }, f.centre_name || '—'));
      tr.appendChild(Dom.el('td', { style: 'padding:11px 14px;font-size:13px;' }, '👶 ' + f.child_count));
      tr.appendChild(Dom.el('td', { style: 'padding:11px 14px;font-size:13px;' }, '👤 ' + f.guardian_count));
      tr.appendChild(Dom.el('td', { style: 'padding:11px 14px;font-size:13px;' + (f.outstanding_balance > 0 ? 'color:#DC2626;font-weight:600;' : 'color:#6B7280;') },
        f.outstanding_balance > 0 ? ('$' + f.outstanding_balance.toFixed(2)) : '—'));

      var actionsTd = Dom.el('td', { style: 'padding:11px 14px;text-align:right;white-space:nowrap;' });
      actionsTd.appendChild(familyActions(f, centres, content));
      tr.appendChild(actionsTd);
      tbody.appendChild(tr);
    });
    table.appendChild(tbody);
    wrap.appendChild(table);
    if (window.KT && typeof KT.sweepRowActions === 'function') setTimeout(KT.sweepRowActions, 0);
    return wrap;
  }

  // v22p11: shared add/edit modal for families. centres = array of {id, name} for the picker.
  // v22p36: multi-step "new family" wizard (Family -> Guardians -> Children -> Review).
  // Creates the family plus nested guardians (each becomes an invited guardian
  // login) and children in a single POST /admin/families call (DB transaction).
  function showFamilyWizard(centres, content) {
    centres = centres || [];
    var _dupEsc = function (s) { return String(s == null ? '' : s).replace(/[&<>"]/g, function (c) { return ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' })[c]; }); };
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

    // Mandatory child-photo uploader — uploads immediately to /admin/child-photo
    // and stores the returned URL on the child (attached on createFamily).
    function _wTok() { return sessionStorage.getItem('kt_token') || localStorage.getItem('kt_token'); }
    function _wBase() { return (window.KT && window.KT.API_BASE) || 'https://api.kiddietrac.com/api/v1'; }
    function _wAbs(u) { if (!u) return ''; if (/^https?:\/\//.test(u)) return u; return _wBase().replace(/\/api\/v1\/?$/, '') + (u.charAt(0) === '/' ? u : '/' + u); }
    function childPhotoWidget(c) {
      var box = Dom.el('div', { style: 'display:flex;align-items:center;gap:14px;margin-bottom:12px;padding:10px;background:#F8FAFC;border:1px solid #E2E8F0;border-radius:10px;' });
      var preview = Dom.el('div', { style: 'width:56px;height:56px;border-radius:12px;flex-shrink:0;background:#E2E8F0 no-repeat center/cover;display:flex;align-items:center;justify-content:center;font-size:24px;color:#94A3B8;overflow:hidden;' }, c.photo_url ? '' : '📷');
      if (c.photo_url) preview.style.backgroundImage = 'url(' + _wAbs(c.photo_url) + ')';
      var right = Dom.el('div', { style: 'flex:1;min-width:0;' });
      right.appendChild(Dom.el('div', { style: 'font-size:12.5px;font-weight:700;color:#1F6080;' }, 'Child photo *'));
      var msg = Dom.el('div', { style: 'font-size:11.5px;margin-top:2px;color:' + (c.photo_url ? '#16A34A' : '#64748B') + ';' }, c.photo_url ? '✓ Photo added' : 'Required — upload or take a photo');
      right.appendChild(msg);
      var file = Dom.el('input', { type: 'file', accept: 'image/*', capture: 'environment', style: 'display:none;' });
      var btn = Dom.el('button', { type: 'button', style: 'background:#1F6080;color:#fff;border:none;border-radius:8px;padding:8px 14px;font-size:12.5px;font-weight:700;cursor:pointer;flex-shrink:0;' }, c.photo_url ? 'Change' : '📷 Upload');
      btn.addEventListener('click', function () { file.click(); });
      file.addEventListener('change', function () {
        var f = file.files && file.files[0]; if (!f) return;
        if (f.size > 8 * 1024 * 1024) { msg.style.color = '#DC2626'; msg.textContent = 'Image too large (max 8 MB)'; return; }
        msg.style.color = '#64748B'; msg.textContent = 'Uploading…'; btn.disabled = true;
        var fd = new FormData(); fd.append('photo', f);
        fetch(_wBase() + '/admin/child-photo', { method: 'POST', headers: { 'Authorization': 'Bearer ' + _wTok() }, body: fd })
          .then(function (r) { return r.json(); })
          .then(function (d) {
            if (d && d.photo_url) {
              c.photo_url = d.photo_url;
              preview.textContent = ''; preview.style.backgroundImage = 'url(' + _wAbs(d.photo_url) + ')';
              msg.style.color = '#16A34A'; msg.textContent = '✓ Photo added'; btn.textContent = 'Change';
            } else { msg.style.color = '#DC2626'; msg.textContent = (d && d.message) || 'Upload failed — try again'; }
            btn.disabled = false;
          })
          .catch(function () { msg.style.color = '#DC2626'; msg.textContent = 'Upload failed — try again'; btn.disabled = false; });
      });
      box.appendChild(preview); box.appendChild(right); box.appendChild(btn); box.appendChild(file);
      return box;
    }
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
    // Phone as separate area code + number (stored combined so the backend is unchanged).
    function _pdig(s) { return (s == null ? '' : String(s)).replace(/[^0-9]/g, ''); }
    function _psplit(v) { var d = _pdig(v); if (d.length === 11 && d[0] === '1') d = d.slice(1); if (d.length >= 10) return { a: d.slice(0, 3), n: d.slice(3, 10) }; if (d.length > 3) return { a: d.slice(0, 3), n: d.slice(3) }; return { a: '', n: v || '' }; }
    function _pcombine(a, n) { a = _pdig(a); var nd = _pdig(n); if (!a && !nd) return ''; if (a && nd) return '(' + a + ') ' + (nd.length >= 7 ? nd.slice(0, 3) + '-' + nd.slice(3, 7) : nd); return (a ? '(' + a + ') ' : '') + (n || ''); }
    function bindPhone(obj, key) {
      var p = _psplit(obj[key]);
      var box = Dom.el('div', { style: 'display:grid;grid-template-columns:78px 1fr;gap:8px;' });
      var area = Dom.el('input', { type: 'text', maxlength: '3', placeholder: 'Area', style: inStyle + 'text-align:center;' }); area.value = p.a;
      var num = Dom.el('input', { type: 'tel', placeholder: 'Phone number', style: inStyle }); num.value = p.n;
      function upd() { obj[key] = _pcombine(area.value, num.value); }
      area.addEventListener('input', upd); num.addEventListener('input', upd);
      box.appendChild(area); box.appendChild(num);
      return box;
    }
    var RELATIONSHIP_OPTS = ['Mother', 'Father', 'Guardian', 'Grandmother', 'Grandfather', 'Grandparent', 'Aunt', 'Uncle', 'Sibling', 'Stepparent', 'Family friend', 'Neighbour', 'Nanny / caregiver', 'Other'].map(function (r) { return { value: r, label: r }; });

    function renderStepper() {
      stepper.innerHTML = '';
      STEPS.forEach(function (s, i) {
        var cur = i === step, done = i < step;
        stepper.appendChild(Dom.el('div', {
          style: 'flex:1;text-align:center;font-size:12px;font-weight:700;padding:8px 6px;border-radius:8px;'
            + (cur ? 'background:#1F6080;color:white;' : done ? 'background:#DCEAF1;color:#1F6080;' : 'background:#F1F5F9;color:#64748B;'),
        }, (i + 1) + '. ' + s));
      });
    }

    function renderFamilyStep() {
      bodyEl.innerHTML = '';
      bodyEl.appendChild(wrap('Centre *', bindSelect(state.family, 'centre_id', centres.map(function (c) { return { value: c.id, label: c.name }; }))));
      var famNameInp = bindInput(state.family, 'family_name', { placeholder: 'e.g. The Patel family' });
      bodyEl.appendChild(wrap('Family name *', famNameInp));
      // Duplicate check: warn if a family with this name already exists.
      var famDup = Dom.el('div', { style: 'display:none;background:#FFFBEB;border:1px solid #FDE68A;border-radius:9px;padding:9px 12px;margin:-4px 0 10px;font-size:12.5px;color:#92400E;' });
      bodyEl.appendChild(famDup);
      famNameInp.addEventListener('blur', function () {
        var nm = (state.family.family_name || '').trim();
        if (nm.length < 3) { famDup.style.display = 'none'; state._famDup = false; return; }
        Api.get('/admin/duplicate-check?type=family&name=' + encodeURIComponent(nm)).then(function (r) {
          var m = (r && r.matches) || [];
          if (!m.length) { famDup.style.display = 'none'; state._famDup = false; return; }
          state._famDup = true; state._famDupOk = false;
          famDup.style.display = 'block';
          famDup.innerHTML = '<div style="font-weight:800;margin-bottom:4px;">⚠ A family with a similar name already exists</div>'
            + m.map(function (x) { return '<div>• ' + _dupEsc(x.label) + ' <span style="color:#B45309;">(' + _dupEsc(x.detail) + ')</span></div>'; }).join('')
            + '<label style="display:flex;gap:7px;align-items:center;margin-top:7px;font-weight:700;cursor:pointer;"><input type="checkbox" id="kt-famdup-ok"> This is a different family</label>';
          var cb = famDup.querySelector('#kt-famdup-ok');
          if (cb) cb.addEventListener('change', function () { state._famDupOk = cb.checked; });
        }).catch(function () {});
      });
      var r1 = fieldRow('1fr 1fr');
      r1.appendChild(wrap('Primary phone', bindPhone(state.family, 'primary_phone')));
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
        r2.appendChild(wrap('Phone', bindPhone(g, 'phone')));
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
      bodyEl.appendChild(Dom.el('p', { style: 'font-size:13px;color:#64748B;margin:0 0 10px;' }, 'Add the children in this family. Health, room and other details can be edited after creating.'));
      // Why the photo is mandatory — reassure parents on confidentiality/security.
      var blurb = Dom.el('div', { style: 'background:#F0F7FB;border:1px solid #D6E6F0;border-left:4px solid #1F6080;border-radius:10px;padding:12px 14px;margin:0 0 16px;font-size:12.5px;color:#334155;line-height:1.55;' });
      blurb.innerHTML = '<strong style="color:#1F6080;">📷 A recent photo is required for each child.</strong><br>'
        + 'Educators use it to confirm they have the right child at drop-off, pickup, headcounts and in an emergency — it’s a core safety check. '
        + 'The photo is <strong>private and encrypted</strong>: it’s only ever visible to your child’s assigned educators and centre administrators, is never shared publicly or with other families, and is removed when the child leaves the centre.';
      bodyEl.appendChild(blurb);
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
        card.appendChild(childPhotoWidget(c));
        var r1 = fieldRow('1fr 1fr');
        var cFirst = bindInput(c, 'first_name');
        var cLast = bindInput(c, 'last_name');
        r1.appendChild(wrap('First name *', cFirst));
        r1.appendChild(wrap('Last name *', cLast));
        card.appendChild(r1);
        // Per-child duplicate check — prevents entering a child who already exists
        // at this agency (the Weston-Boyd-twice scenario) a second time by hand.
        var cDup = Dom.el('div', { style: 'display:none;background:#FFFBEB;border:1px solid #FDE68A;border-radius:9px;padding:9px 12px;margin:-4px 0 10px;font-size:12.5px;color:#92400E;' });
        card.appendChild(cDup);
        (function (child, warnEl) {
          var check = function () {
            var nm = ((child.first_name || '') + ' ' + (child.last_name || '')).trim();
            if (nm.length < 3) { warnEl.style.display = 'none'; child._dup = false; return; }
            Api.get('/admin/duplicate-check?type=child&name=' + encodeURIComponent(nm)).then(function (r) {
              var mm = (r && r.matches) || [];
              if (!mm.length) { warnEl.style.display = 'none'; child._dup = false; return; }
              child._dup = true; child._dupOk = false;
              warnEl.style.display = 'block';
              warnEl.innerHTML = '<div style="font-weight:800;margin-bottom:4px;">⚠ A child with this name already exists</div>'
                + mm.map(function (x) { return '<div>• ' + _dupEsc(x.label) + ' <span style="color:#B45309;">(' + _dupEsc(x.detail) + ')</span></div>'; }).join('')
                + '<label style="display:flex;gap:7px;align-items:center;margin-top:7px;font-weight:700;cursor:pointer;"><input type="checkbox" class="kt-cdup-ok"> This is a different child</label>';
              var cb = warnEl.querySelector('.kt-cdup-ok');
              if (cb) cb.addEventListener('change', function () { child._dupOk = cb.checked; });
            }).catch(function () {});
          };
          cFirst.addEventListener('blur', check);
          cLast.addEventListener('blur', check);
        })(c, cDup);
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
        r1.appendChild(wrap('First name *', bindInput(e, 'first_name')));
        r1.appendChild(wrap('Last name *', bindInput(e, 'last_name')));
        card.appendChild(r1);
        card.appendChild(wrap('Relationship', bindSelect(e, 'relationship', RELATIONSHIP_OPTS)));
        var r2 = fieldRow('1fr 1fr');
        r2.appendChild(wrap('Phone', bindPhone(e, 'phone')));
        r2.appendChild(wrap('Alternate phone', bindPhone(e, 'alt_phone')));
        card.appendChild(r2);
        var pickWrap = Dom.el('div', { style: 'display:flex;align-items:center;gap:8px;' });
        var pick = Dom.el('input', { type: 'checkbox' }); pick.checked = !!e.can_pickup;
        pick.addEventListener('change', function () { e.can_pickup = pick.checked; });
        pickWrap.appendChild(pick); pickWrap.appendChild(Dom.el('label', { style: 'font-size:13px;' }, 'Authorized for pickup'));
        card.appendChild(pickWrap);
        bodyEl.appendChild(card);
      });
      var add = Dom.el('button', { type: 'button', style: 'background:#EFF6FB;border:1px dashed #1F6080;color:#1F6080;border-radius:8px;padding:10px;width:100%;font-weight:600;cursor:pointer;font-size:13px;' }, '+ Add emergency contact');
      add.addEventListener('click', function () { state.emergency.push({ first_name: '', last_name: '', relationship: 'Grandparent', phone: '', alt_phone: '', can_pickup: false }); renderEmergencyStep(); });
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
      var _addr = [state.family.address_line1, [state.family.city, state.family.province, state.family.postal_code].filter(Boolean).join(' ')].filter(Boolean).join(', ');
      if (_addr) fam.appendChild(row('Address', _addr));
      bodyEl.appendChild(fam);
      var gd = Dom.el('div', { style: 'border:1px solid #E2E8F0;border-radius:10px;padding:14px;margin-bottom:12px;' });
      gd.appendChild(Dom.el('strong', { style: 'font-size:13px;color:#1F6080;display:block;margin-bottom:6px;' }, '🧑‍🤝‍🧑 Guardians (' + state.guardians.length + ')'));
      state.guardians.forEach(function (g, i) { gd.appendChild(row((i === 0 ? '★ ' : '') + (g.first_name + ' ' + g.last_name).trim(), g.relationship + '  ·  ' + g.email)); });
      bodyEl.appendChild(gd);
      var ch = Dom.el('div', { style: 'border:1px solid #E2E8F0;border-radius:10px;padding:14px;margin-bottom:12px;' });
      ch.appendChild(Dom.el('strong', { style: 'font-size:13px;color:#1F6080;display:block;margin-bottom:6px;' }, '🧒 Children (' + state.children.length + ')'));
      state.children.forEach(function (c) { ch.appendChild(row((c.first_name + ' ' + c.last_name).trim(), (c.date_of_birth || '?') + '  ·  ' + c.enrollment_status)); });
      bodyEl.appendChild(ch);
      // Emergency contacts — previously omitted from the review.
      var ec = (state.emergency || []).filter(function (e) { return ((e.first_name || '') + (e.last_name || '')).trim(); });
      if (ec.length) {
        var em = Dom.el('div', { style: 'border:1px solid #E2E8F0;border-radius:10px;padding:14px;' });
        em.appendChild(Dom.el('strong', { style: 'font-size:13px;color:#1F6080;display:block;margin-bottom:6px;' }, '☎ Emergency contacts (' + ec.length + ')'));
        ec.forEach(function (e) {
          var name = ((e.first_name || '') + ' ' + (e.last_name || '')).trim();
          var meta = [e.relationship, e.phone, (e.can_pickup ? 'can pick up' : null)].filter(Boolean).join('  ·  ');
          em.appendChild(row(name, meta));
        });
        bodyEl.appendChild(em);
      }
    }

    function validateStep() {
      status.style.color = '#DC2626';
      if (step === 0) {
        if (!state.family.centre_id) { status.textContent = 'Please choose a centre.'; return false; }
        if (!state.family.family_name.trim()) { status.textContent = 'Family name is required.'; return false; }
        if (state._famDup && !state._famDupOk) { status.style.color = '#B45309'; status.textContent = 'This family name looks like an existing record — tick “This is a different family”, or open the existing family instead.'; return false; }
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
          if (!c.photo_url) { status.textContent = 'Child ' + (j + 1) + ': a photo is required — please upload or take one.'; return false; }
          if (c._dup && !c._dupOk) { status.style.color = '#B45309'; status.textContent = 'Child ' + (j + 1) + ' looks like an existing child — tick “This is a different child”, or open the existing record instead.'; return false; }
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
        emergency_contacts: (state.emergency || []).filter(function (e) { return ((e.first_name || '') + (e.last_name || '')).trim(); }).map(function (e) {
          return { name: ((e.first_name || '') + ' ' + (e.last_name || '')).trim(), relationship: (e.relationship || '').trim() || null, phone: (e.phone || '').trim() || null, alt_phone: (e.alt_phone || '').trim() || null, can_pickup: !!e.can_pickup };
        }),
      };
      // Circular progress + a short settle delay so the portal has time to update.
      if (!document.getElementById('kt-fam-spin-kf')) { var _st = document.createElement('style'); _st.id = 'kt-fam-spin-kf'; _st.textContent = '@keyframes kt-spin{to{transform:rotate(360deg)}}'; document.head.appendChild(_st); }
      status.style.color = '#1F6080';
      status.innerHTML = '<span style="display:inline-flex;align-items:center;gap:10px;"><span style="width:18px;height:18px;border:3px solid #CBD5E1;border-top-color:#1F6080;border-radius:50%;display:inline-block;animation:kt-spin .7s linear infinite;"></span> Creating family &amp; sending guardian invites…</span>';
      footer.innerHTML = '';
      try {
        var res = await Api.post('/admin/families', payload);
        status.innerHTML = '<span style="display:inline-flex;align-items:center;gap:10px;color:#16A34A;"><span style="width:18px;height:18px;border:3px solid #BBF7D0;border-top-color:#16A34A;border-radius:50%;display:inline-block;animation:kt-spin .7s linear infinite;"></span> Family created — finishing up…</span>';
        await new Promise(function (r) { setTimeout(r, 1000); });
        if (Dom.toast) Dom.toast('Family created — ' + (res.guardians || 0) + ' guardian(s), ' + (res.children || 0) + ' child(ren). Invites sent.', 'success');
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
    return showFamilyEditTabs(family, centres, content);
  }

  // Full tabbed family editor: Family · Guardians · Children · Emergency.
  function showFamilyEditTabs(family, centres, content) {
    centres = centres || [];
    content = content || document.getElementById('appMain');
    const EREL = ['Mother', 'Father', 'Guardian', 'Grandmother', 'Grandfather', 'Grandparent', 'Aunt', 'Uncle', 'Sibling', 'Family friend', 'Neighbour', 'Other'];
    function _dig(s) { return (s == null ? '' : String(s)).replace(/[^0-9]/g, ''); }
    function _psplit(v) { var d = _dig(v); if (d.length === 11 && d[0] === '1') d = d.slice(1); if (d.length >= 10) return { a: d.slice(0, 3), n: d.slice(3, 10) }; if (d.length > 3) return { a: d.slice(0, 3), n: d.slice(3) }; return { a: '', n: v || '' }; }
    function _pcomb(a, n) { a = _dig(a); var nd = _dig(n); if (!a && !nd) return ''; if (a && nd) return '(' + a + ') ' + (nd.length >= 7 ? nd.slice(0, 3) + '-' + nd.slice(3, 7) : nd); return (a ? '(' + a + ') ' : '') + (n || ''); }
    var IN = 'width:100%;padding:8px 11px;border:1px solid #D1D5DB;border-radius:7px;font-size:14px;box-sizing:border-box;';
    function inp(val, ph, type) { var e = Dom.el('input', { type: type || 'text', style: IN }); if (ph) e.placeholder = ph; e.value = val == null ? '' : val; return e; }
    function selEl(val, opts) { var s = Dom.el('select', { style: IN + 'background:#fff;' }); opts.forEach(function (o) { var op = Dom.el('option', { value: o.value }, o.label); if (String(val) === String(o.value)) op.selected = true; s.appendChild(op); }); return s; }
    function fwrap(l, e) { var d = Dom.el('div', { style: 'margin-bottom:12px;' }); d.appendChild(Dom.el('label', { style: 'display:block;font-size:12.5px;font-weight:600;color:#334155;margin-bottom:4px;' }, l)); d.appendChild(e); return d; }
    function grid(cols) { return Dom.el('div', { style: 'display:grid;grid-template-columns:' + cols + ';gap:12px;' }); }
    function phoneField(label, val) { var p = _psplit(val); var box = Dom.el('div', { style: 'display:grid;grid-template-columns:74px 1fr;gap:8px;' }); var a = inp(p.a, 'Area'); a.maxLength = 3; a.style.textAlign = 'center'; var n = inp(p.n, 'Phone number', 'tel'); box.appendChild(a); box.appendChild(n); return { wrap: fwrap(label, box), get: function () { return _pcomb(a.value, n.value); } }; }

    var root = Dom.el('div', {});
    var tabBar = Dom.el('div', { style: 'display:flex;gap:4px;border-bottom:1px solid #E5E7EB;margin-bottom:14px;' });
    var pane = Dom.el('div', {});
    var msg = Dom.el('div', { style: 'min-height:18px;font-size:13px;margin-top:10px;' });
    root.appendChild(tabBar); root.appendChild(pane); root.appendChild(msg);
    function setMsg(t, ok) { msg.style.color = ok ? '#16A34A' : '#DC2626'; msg.textContent = t; }
    function refreshList() { try { renderFamiliesTab(content); } catch (e) {} }

    var TABS = ['Family', 'Guardians', 'Children', 'Emergency'];
    var active = 'Family', DATA = null, tabBtns = {};
    TABS.forEach(function (t) {
      var b = Dom.el('button', { type: 'button', style: 'appearance:none;background:none;border:0;border-bottom:2px solid transparent;padding:8px 12px;margin-bottom:-1px;font-size:13.5px;font-weight:700;color:#64748B;cursor:pointer;' }, t);
      b.addEventListener('click', function () { active = t; paintTabs(); renderTab(); });
      tabBtns[t] = b; tabBar.appendChild(b);
    });
    function paintTabs() { TABS.forEach(function (t) { var on = t === active; tabBtns[t].style.color = on ? '#1F6080' : '#64748B'; tabBtns[t].style.borderBottomColor = on ? '#1F6080' : 'transparent'; }); }

    function renderFamily() {
      pane.innerHTML = ''; var f = DATA.family;
      var iName = inp(f.family_name, 'Family name');
      var copts = (centres || []).map(function (c) { return { value: c.id, label: c.name }; });
      if (!copts.some(function (o) { return String(o.value) === String(f.centre_id); })) copts.unshift({ value: f.centre_id, label: '(current centre)' });
      var iCentre = selEl(f.centre_id, copts);
      var ph = phoneField('Primary phone', f.primary_phone);
      var iEmail = inp(f.primary_email, 'name@example.com', 'email');
      var iAddr = inp(f.address_line1, 'Street address');
      var iCity = inp(f.city, 'City'), iProv = inp(f.province, 'Province'), iPost = inp(f.postal_code, 'Postal code');
      var iBill = selEl(f.billing_split, [{ value: 'single', label: 'Single payer' }, { value: 'split_50_50', label: 'Split 50 / 50' }, { value: 'custom', label: 'Custom split' }]);
      var iNotes = Dom.el('textarea', { style: IN + 'min-height:60px;font-family:inherit;' }); iNotes.value = f.notes || '';
      pane.appendChild(fwrap('Family name *', iName));
      pane.appendChild(fwrap('Centre', iCentre));
      var g1 = grid('1fr 1fr'); g1.appendChild(ph.wrap); g1.appendChild(fwrap('Primary email', iEmail)); pane.appendChild(g1);
      pane.appendChild(fwrap('Street address', iAddr));
      var g2 = grid('2fr 1fr 1fr'); g2.appendChild(fwrap('City', iCity)); g2.appendChild(fwrap('Province', iProv)); g2.appendChild(fwrap('Postal code', iPost)); pane.appendChild(g2);
      pane.appendChild(fwrap('Billing split', iBill));
      pane.appendChild(fwrap('Internal notes', iNotes));
      var save = Dom.el('button', { style: 'padding:9px 18px;background:#1F6080;color:#fff;border:none;border-radius:8px;font-weight:700;cursor:pointer;' }, 'Save family');
      save.addEventListener('click', async function () {
        if (!iName.value.trim()) { setMsg('Family name is required.'); return; }
        save.disabled = true; setMsg('Saving…', true);
        try {
          await Api.patch('/admin/families/' + f.id, { family_name: iName.value.trim(), centre_id: parseInt(iCentre.value, 10), primary_phone: ph.get() || null, primary_email: iEmail.value.trim() || null, address_line1: iAddr.value.trim() || null, city: iCity.value.trim() || null, province: iProv.value.trim() || null, postal_code: iPost.value.trim() || null, billing_split: iBill.value, notes: iNotes.value.trim() || null });
          setMsg('✓ Family saved', true); refreshList();
        } catch (e) { setMsg((e.message || 'Save failed') + (e.errors ? ' — ' + Object.values(e.errors).flat().join(', ') : '')); }
        save.disabled = false;
      });
      pane.appendChild(save);
    }

    function renderGuardians() {
      pane.innerHTML = '';
      (DATA.guardians || []).forEach(function (g) {
        var card = Dom.el('div', { style: 'border:1px solid #E5E7EB;border-radius:10px;padding:14px;margin-bottom:12px;' });
        card.appendChild(Dom.el('div', { style: 'font-weight:700;color:#1F6080;font-size:13px;margin-bottom:10px;' }, ((g.first_name || '') + ' ' + (g.last_name || '')).trim() + (g.is_primary ? ' ★ primary' : '')));
        var iF = inp(g.first_name, 'First name'), iL = inp(g.last_name, 'Last name');
        var ph = phoneField('Phone', g.phone);
        var iR = selEl(g.relationship, [{ value: 'mother', label: 'Mother' }, { value: 'father', label: 'Father' }, { value: 'guardian', label: 'Guardian' }, { value: 'grandparent', label: 'Grandparent' }, { value: 'foster', label: 'Foster' }, { value: 'other', label: 'Other' }]);
        var pickWrap = Dom.el('div', { style: 'display:flex;align-items:center;gap:8px;margin-top:26px;' }); var pick = Dom.el('input', { type: 'checkbox' }); pick.checked = g.can_pickup !== false; pickWrap.appendChild(pick); pickWrap.appendChild(Dom.el('label', { style: 'font-size:13px;' }, 'Authorized for pickup'));
        var g1 = grid('1fr 1fr'); g1.appendChild(fwrap('First name', iF)); g1.appendChild(fwrap('Last name', iL)); card.appendChild(g1);
        var g2 = grid('1fr 1fr'); g2.appendChild(ph.wrap); g2.appendChild(fwrap('Relationship', iR)); card.appendChild(g2);
        card.appendChild(pickWrap);
        card.appendChild(Dom.el('div', { style: 'font-size:12px;color:#94A3B8;margin-top:8px;' }, 'Login email: ' + (g.email || '—')));
        var save = Dom.el('button', { style: 'margin-top:10px;padding:8px 14px;background:#1F6080;color:#fff;border:none;border-radius:7px;font-weight:700;cursor:pointer;font-size:13px;' }, 'Save guardian');
        save.addEventListener('click', async function () {
          save.disabled = true; setMsg('Saving…', true);
          try { await Api.patch('/admin/guardians/' + g.id, { first_name: iF.value.trim(), last_name: iL.value.trim(), phone: ph.get() || null, relationship: iR.value, can_pickup: pick.checked }); setMsg('✓ Guardian saved', true); refreshList(); }
          catch (e) { setMsg(e.message || 'Save failed'); }
          save.disabled = false;
        });
        card.appendChild(save); pane.appendChild(card);
      });
      if (!(DATA.guardians || []).length) pane.appendChild(Dom.el('div', { style: 'color:#64748B;' }, 'No guardians.'));
    }

    function renderChildren() {
      pane.innerHTML = '';
      pane.appendChild(Dom.el('div', { style: 'font-size:12.5px;color:#64748B;margin-bottom:12px;' }, 'Open a child’s record to edit health, enrolment and details.'));
      (DATA.children || []).forEach(function (c) {
        var row = Dom.el('div', { style: 'border:1px solid #E5E7EB;border-radius:10px;padding:12px 14px;margin-bottom:10px;display:flex;justify-content:space-between;align-items:center;gap:10px;' });
        var info = Dom.el('div', {});
        info.appendChild(Dom.el('div', { style: 'font-weight:700;' }, (c.preferred_name || ((c.first_name || '') + ' ' + (c.last_name || ''))).trim()));
        var bits = [c.date_of_birth ? 'Born ' + c.date_of_birth : null, c.gender, c.enrollment_status].filter(Boolean).join(' · ');
        info.appendChild(Dom.el('div', { style: 'font-size:12px;color:#64748B;' }, bits));
        if (c.allergies) info.appendChild(Dom.el('div', { style: 'font-size:11.5px;color:#B45309;margin-top:2px;' }, '⚠️ ' + c.allergies));
        row.appendChild(info);
        var open = Dom.el('button', { style: 'padding:7px 12px;background:#fff;color:#1F6080;border:1px solid #1F6080;border-radius:7px;font-weight:600;font-size:12px;cursor:pointer;white-space:nowrap;' }, 'Open record →');
        open.addEventListener('click', function () { Shell.Modal.close(); location.hash = '#child-detail?id=' + c.id; });
        row.appendChild(open); pane.appendChild(row);
      });
      if (!(DATA.children || []).length) pane.appendChild(Dom.el('div', { style: 'color:#64748B;' }, 'No children.'));
    }

    function ecCard(e) {
      var card = Dom.el('div', { style: 'border:1px solid #E5E7EB;border-radius:10px;padding:14px;margin-bottom:12px;' });
      var iN = inp(e.name, 'Full name'); var iR = selEl(e.relationship || '', [{ value: '', label: 'Relationship…' }].concat(EREL.map(function (r) { return { value: r, label: r }; })));
      var ph = phoneField('Phone', e.phone); var pha = phoneField('Alternate phone', e.alt_phone);
      var pickWrap = Dom.el('div', { style: 'display:flex;align-items:center;gap:8px;' }); var pick = Dom.el('input', { type: 'checkbox' }); pick.checked = !!e.can_pickup; pickWrap.appendChild(pick); pickWrap.appendChild(Dom.el('label', { style: 'font-size:13px;' }, 'Authorized for pickup'));
      var g1 = grid('1fr 1fr'); g1.appendChild(fwrap('Full name', iN)); g1.appendChild(fwrap('Relationship', iR)); card.appendChild(g1);
      var g2 = grid('1fr 1fr'); g2.appendChild(ph.wrap); g2.appendChild(pha.wrap); card.appendChild(g2);
      card.appendChild(pickWrap);
      var bar = Dom.el('div', { style: 'display:flex;gap:8px;margin-top:10px;' });
      var save = Dom.el('button', { style: 'padding:8px 14px;background:#1F6080;color:#fff;border:none;border-radius:7px;font-weight:700;cursor:pointer;font-size:13px;' }, 'Save');
      save.addEventListener('click', async function () {
        if (!iN.value.trim()) { setMsg('Name is required.'); return; }
        save.disabled = true; setMsg('Saving…', true);
        var payload = { name: iN.value.trim(), relationship: iR.value || null, phone: ph.get() || null, alt_phone: pha.get() || null, can_pickup: pick.checked };
        try {
          if (e.__new) { var r = await Api.post('/admin/families/' + DATA.family.id + '/emergency-contacts', payload); e.__new = false; e.id = r.id; }
          else { await Api.patch('/admin/emergency-contacts/' + e.id, payload); }
          setMsg('✓ Emergency contact saved', true); refreshList();
        } catch (err) { setMsg(err.message || 'Save failed'); }
        save.disabled = false;
      });
      bar.appendChild(save);
      if (!e.__new) { var del = Dom.el('button', { style: 'padding:8px 14px;background:#FEE2E2;color:#B91C1C;border:none;border-radius:7px;font-weight:700;cursor:pointer;font-size:13px;' }, 'Delete'); del.addEventListener('click', async function () { if (KT.confirm && !await KT.confirm('Delete this emergency contact?')) return; try { await Api.delete('/admin/emergency-contacts/' + e.id); card.remove(); setMsg('✓ Deleted', true); refreshList(); } catch (err) { setMsg(err.message || 'Delete failed'); } }); bar.appendChild(del); }
      card.appendChild(bar);
      return card;
    }
    function renderEmergency() {
      pane.innerHTML = '';
      (DATA.emergency_contacts || []).forEach(function (e) { pane.appendChild(ecCard(e)); });
      var add = Dom.el('button', { style: 'background:#EFF6FB;border:1px dashed #1F6080;color:#1F6080;border-radius:8px;padding:10px;width:100%;font-weight:600;cursor:pointer;font-size:13px;' }, '+ Add emergency contact');
      add.addEventListener('click', function () { pane.insertBefore(ecCard({ __new: true, name: '', relationship: '', phone: '', alt_phone: '', can_pickup: false }), add); });
      pane.appendChild(add);
    }

    function renderTab() {
      if (!DATA) return;
      if (active === 'Family') renderFamily();
      else if (active === 'Guardians') renderGuardians();
      else if (active === 'Children') renderChildren();
      else renderEmergency();
    }

    Shell.Modal.open({ title: 'Edit family — ' + family.family_name, body: root, large: true });
    paintTabs();
    pane.appendChild(loading('Loading family…'));
    Api.get('/admin/families/' + family.id).then(function (data) { DATA = data; renderTab(); }).catch(function (e) { pane.innerHTML = ''; setMsg('Could not load: ' + (e.message || 'error')); });
    return;

    // ── legacy single-form edit (unreachable; kept to avoid a large diff) ──
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

      var _f = data.family || {};
      var _hd = Dom.el('div', { style: 'display:flex;justify-content:space-between;align-items:flex-start;gap:12px;margin-bottom:6px;' });
      _hd.appendChild(Dom.el('h3', { style: 'font-size: 19px; margin: 0;' }, _f.family_name));
      var _edit = Dom.el('button', { style: 'padding:8px 14px;background:#1F6080;color:#fff;border:none;border-radius:8px;font-weight:700;font-size:13px;cursor:pointer;flex:0 0 auto;' }, '✏️ Edit family');
      _edit.addEventListener('click', async function () {
        var _cs = [];
        try { var _r = await Api.get('/admin/centres'); _cs = _r.centres || []; } catch (e) {}
        Shell.Modal.close();
        setTimeout(function () { showFamilyModal(_f, _cs, document.getElementById('appMain')); }, 80);
      });
      // Manual (re)send of the provider welcome/bio email to this family's
      // guardians (CCs the agency admin, director and educator).
      var _resend = Dom.el('button', { style: 'padding:8px 14px;background:#fff;color:#1F6080;border:1.5px solid #1F6080;border-radius:8px;font-weight:700;font-size:13px;cursor:pointer;flex:0 0 auto;' }, '✉️ Send welcome');
      _resend.addEventListener('click', async function () {
        var go = !(window.KT && KT.confirm) || await KT.confirm({ title: 'Send provider welcome email?', description: 'Emails the warm provider introduction to this family’s guardians, with the agency admin, director and educator on CC.' });
        if (!go) return;
        _resend.disabled = true; _resend.textContent = 'Sending…';
        try {
          var rr = await Api.post('/admin/families/' + familyId + '/provider-welcome', {});
          if (window.KT.Dom && KT.Dom.toast) KT.Dom.toast('Welcome email sent to ' + (rr.recipients || 0) + ' guardian(s)', 'success');
          _resend.textContent = '✓ Sent';
        } catch (e) {
          if (window.KT.Dom && KT.Dom.toast) KT.Dom.toast('Could not send: ' + (e.message || 'error'), 'error');
          _resend.disabled = false; _resend.textContent = '✉️ Send welcome';
        }
      });
      var _btns = Dom.el('div', { style: 'display:flex;gap:8px;flex-shrink:0;' });
      _btns.appendChild(_resend); _btns.appendChild(_edit);
      _hd.appendChild(_btns);
      body.appendChild(_hd);
      function _kv(k, v) { var d = Dom.el('div', { style: 'display:flex;justify-content:space-between;gap:14px;padding:4px 0;font-size:13.5px;' }); d.appendChild(Dom.el('span', { style: 'color:var(--ink-500);flex-shrink:0;' }, k)); d.appendChild(Dom.el('span', { style: 'font-weight:600;color:#0F172A;text-align:right;word-break:break-word;' }, (v == null || v === '') ? '—' : String(v))); return d; }
      var _famCard = Dom.el('div', { style: 'border:1px solid #E5E7EB;border-radius:10px;padding:12px 14px;margin:8px 0 6px;' });
      _famCard.appendChild(_kv('Email', _f.primary_email));
      _famCard.appendChild(_kv('Phone', _f.primary_phone));
      var _addr = [_f.address_line1, _f.address_line2, [_f.city, _f.province, _f.postal_code].filter(Boolean).join(' ')].filter(Boolean).join(', ');
      _famCard.appendChild(_kv('Address', _addr));
      _famCard.appendChild(_kv('Billing', ({ single: 'Single payer', split_50_50: 'Split 50 / 50', custom: 'Custom split' })[_f.billing_split] || _f.billing_split));
      if (_f.status) _famCard.appendChild(_kv('Status', _f.status));
      if (_f.notes) _famCard.appendChild(_kv('Notes', _f.notes));
      body.appendChild(_famCard);

      body.appendChild(Dom.el('h4', { style: 'font-size: 14px; font-weight: 700; margin: 16px 0 8px; letter-spacing: 0.5px; color: var(--ink-700);' }, 'CHILDREN'));
      if (data.children.length === 0) {
        body.appendChild(Dom.el('div', { style: 'color: var(--ink-500); font-size: 13px;' }, 'No children'));
      } else {
        data.children.forEach(c => {
          const row = Dom.el('div', { style: 'padding: 10px; background: var(--ink-50); border-radius: 6px; margin-bottom: 6px; display:flex; align-items:center; gap:10px;' });
          // v22p83: child avatar (photo or initials)
          const cName = c.preferred_name || (c.first_name + ' ' + c.last_name);
          row.appendChild(Dom.el('span', { html: KT.avatar(c.first_name + ' ' + c.last_name, { size: 34, photoUrl: c.photo_url }) }));
          const cInfo = Dom.el('div', { style: 'min-width:0;flex:1;' });
          cInfo.appendChild(Dom.el('div', { style: 'font-weight: 600;' }, cName));
          cInfo.appendChild(Dom.el('div', { style: 'font-size: 13px; color: var(--ink-500);' }, (c.date_of_birth ? 'Born ' + c.date_of_birth + ' · ' : '') + (c.gender ? c.gender + ' · ' : '') + c.enrollment_status));
          var _extra = [];
          if (c.allergies) _extra.push('⚠️ Allergies: ' + c.allergies);
          if (c.dietary_restrictions) _extra.push('🍽️ Dietary: ' + c.dietary_restrictions);
          if (c.medical_notes) _extra.push('💊 Medical: ' + c.medical_notes);
          if (c.doctor_name || c.doctor_phone) _extra.push('🩺 Doctor: ' + [c.doctor_name, c.doctor_phone].filter(Boolean).join(' · '));
          if (c.school) _extra.push('🏫 School: ' + c.school);
          if (_extra.length) cInfo.appendChild(Dom.el('div', { style: 'font-size:12px;color:#475569;margin-top:4px;line-height:1.5;' }, _extra.join('  ·  ')));
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
        info.appendChild(Dom.el('div', { style: 'font-weight: 600;' }, g.first_name + ' ' + g.last_name + (g.is_primary ? ' ★ primary' : '')));
        info.appendChild(Dom.el('div', { style: 'font-size: 13px; color: var(--ink-500);' }, [g.relationship, g.email, g.phone].filter(Boolean).join(' · ')));
        info.appendChild(Dom.el('div', { style: 'font-size: 11px; color: #94A3B8;margin-top:1px;' }, (g.can_pickup ? '✓ authorized for pickup' : '✗ not for pickup') + (g.status ? ' · ' + g.status : '')));
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

      // Emergency contacts — previously not shown in the family view at all.
      var _ecs = data.emergency_contacts || [];
      body.appendChild(Dom.el('h4', { style: 'font-size: 14px; font-weight: 700; margin: 16px 0 8px; letter-spacing: 0.5px; color: var(--ink-700);' }, 'EMERGENCY CONTACTS'));
      if (!_ecs.length) {
        body.appendChild(Dom.el('div', { style: 'color: var(--ink-500); font-size: 13px;' }, 'None on file.'));
      } else {
        _ecs.forEach(function (e) {
          var er = Dom.el('div', { style: 'padding:10px;background:var(--ink-50);border-radius:6px;margin-bottom:6px;' });
          er.appendChild(Dom.el('div', { style: 'font-weight:600;' }, e.name + (e.can_pickup ? '  ·  ✓ pickup' : '')));
          er.appendChild(Dom.el('div', { style: 'font-size:13px;color:var(--ink-500);' }, [e.relationship, e.phone, e.alt_phone].filter(Boolean).join('  ·  ') || '—'));
          body.appendChild(er);
        });
      }

      Shell.Modal.open({ title: 'Family — ' + (data.family.family_name || ''), body: body, large: true });
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

    // Email delivery (white-label): send from the agency's own M365 / Google.
    renderEmailDeliveryCard(content);

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

    // ── Payslip appearance ───────────────────────────────────────────────
    // Agency-wide wording printed on every staff payslip PDF. It used to live at the
    // bottom of the Payroll REPORT screen, which is a date-range report, not a place
    // anyone looks for settings. It is document branding - the logo, agency name and
    // address are already added automatically - so it belongs on this tab.
    (function () {
      var wrap = Dom.el('div', { style: 'margin-top:28px;background:#fff;border:1px solid #E5E7EB;border-radius:12px;padding:20px;max-width:720px;' });
      wrap.innerHTML =
        '<h3 style="margin:0 0 4px;color:#1F6080;font-size:16px;">\uD83E\uDDFE Payslip appearance</h3>'
        + '<p style="margin:0 0 16px;color:#64748B;font-size:13px;line-height:1.5;">Wording that appears on every staff payslip PDF for your agency. Your agency <strong>logo, name, and address</strong> are added automatically, along with the employee\'s details and a standard confidentiality notice.</p>'
        + '<label style="display:block;font-size:12px;font-weight:700;color:#374151;margin-bottom:5px;">Message to staff <span style="color:#94A3B8;font-weight:500;">(optional \u2014 appears in the body)</span></label>'
        + '<textarea id="ps-note" rows="3" placeholder="e.g. Thank you for your hard work this pay period. Please contact the office with any questions about your pay." style="width:100%;box-sizing:border-box;padding:10px 12px;border:1px solid #E5E7EB;border-radius:8px;font-size:13.5px;font-family:inherit;resize:vertical;color:#0D1B2A;"></textarea>'
        + '<label style="display:block;font-size:12px;font-weight:700;color:#374151;margin:14px 0 5px;">Confidentiality / private notice <span style="color:#94A3B8;font-weight:500;">(optional)</span></label>'
        + '<textarea id="ps-conf" rows="2" style="width:100%;box-sizing:border-box;padding:10px 12px;border:1px solid #E5E7EB;border-radius:8px;font-size:13.5px;font-family:inherit;resize:vertical;color:#0D1B2A;"></textarea>'
        + '<div style="font-size:11.5px;color:#94A3B8;margin-top:4px;">Leave blank to use the standard &ldquo;Private &amp; Confidential&rdquo; notice.</div>'
        + '<div style="margin-top:16px;display:flex;align-items:center;gap:12px;flex-wrap:wrap;">'
        +   '<button id="ps-save" style="background:#1F6080;color:#fff;border:0;padding:10px 20px;border-radius:8px;cursor:pointer;font-weight:700;font-size:13.5px;">Save wording</button>'
        +   '<span id="ps-status" style="font-size:13px;"></span>'
        + '</div>';
      content.appendChild(wrap);

      var noteEl = wrap.querySelector('#ps-note'), confEl = wrap.querySelector('#ps-conf'),
          saveBtn = wrap.querySelector('#ps-save'), statusEl = wrap.querySelector('#ps-status');
      Api.get('/agency/payslip-settings').then(function (s) {
        if (!s) return;
        noteEl.value = s.note || '';
        confEl.value = s.confidential || '';
        if (s.confidential_default) confEl.placeholder = s.confidential_default;
      }).catch(function () {});
      saveBtn.onclick = function () {
        saveBtn.disabled = true; saveBtn.textContent = 'Saving\u2026'; statusEl.textContent = '';
        Api.post('/agency/payslip-settings', { note: noteEl.value, confidential: confEl.value })
          .then(function () { statusEl.style.color = '#059669'; statusEl.textContent = '\u2713 Saved \u2014 applies to payslip downloads from now on.'; })
          .catch(function (e) { statusEl.style.color = '#DC2626'; statusEl.textContent = (e && e.message) || 'Could not save.'; })
          .then(function () { saveBtn.disabled = false; saveBtn.textContent = 'Save wording'; });
      };
    })();
  }

  // ════════════════════════════════════════════════════════════════
  //   EMAIL DELIVERY (white-label) — send from the agency's OWN M365/Google
  // ════════════════════════════════════════════════════════════════
  // By default an agency's mail is sent by KiddieTrac. A white-label agency can
  // instead send from their OWN Microsoft 365 (Graph) or Google/Gmail (SMTP).
  // Scoped by the API to the caller's agency (a platform_admin manages whichever
  // agency they're switched into). Secrets are write-only — never sent back.
  async function renderEmailDeliveryCard(content) {
    var card = Dom.el('div', { 'data-kt-noautofill': '1', style: 'background:white;padding:24px;border-radius:12px;box-shadow:0 1px 3px rgba(0,0,0,0.04);margin-top:24px;' });
    var head = Dom.el('div', { style: 'display:flex;align-items:center;gap:10px;margin-bottom:4px;' });
    head.appendChild(Dom.el('h3', { style: 'margin:0;font-size:18px;' }, '✉️ Email delivery'));
    var badge = Dom.el('span', { style: 'display:none;font-size:11px;font-weight:800;letter-spacing:.5px;text-transform:uppercase;color:#065F46;background:#D1FAE5;padding:3px 9px;border-radius:99px;' }, 'Own email active');
    head.appendChild(badge);
    card.appendChild(head);
    card.appendChild(Dom.el('div', { style: 'color:var(--ink-500);font-size:13px;margin-bottom:16px;max-width:640px;' },
      'By default your emails are sent securely by KiddieTrac. If your agency has its own Microsoft 365 or Google Workspace, you can send from your own email address instead — recipients see mail from you, and it counts toward your own domain reputation.'));
    var loadingEl = loading('Loading…'); card.appendChild(loadingEl); content.appendChild(card);

    var res;
    try { res = await Api.get('/admin/agency-mail'); }
    catch (e) { loadingEl.replaceWith(errorBox('Could not load email settings: ' + (e.message || 'error'))); return; }
    loadingEl.remove();
    var c = res.config || {};
    if (res.active) badge.style.display = 'inline-block';

    function field(label, opts) {
      opts = opts || {};
      var wrap = Dom.el('div', { style: 'margin-bottom:12px;' + (opts.width || '') });
      wrap.appendChild(Dom.el('label', { style: 'display:block;font-size:13px;font-weight:600;margin-bottom:4px;' }, label));
      var input = Dom.el('input', { type: opts.type || 'text', placeholder: opts.placeholder || '',
        style: 'width:100%;padding:8px 12px;border:1px solid var(--ink-300);border-radius:6px;font-size:14px;box-sizing:border-box;' });
      if (opts.value != null) input.value = opts.value;
      if (opts.autocomplete) input.autocomplete = opts.autocomplete;
      wrap.appendChild(input);
      if (opts.hint) wrap.appendChild(Dom.el('div', { style: 'font-size:11.5px;color:var(--ink-500);margin-top:4px;' }, opts.hint));
      wrap._input = input;
      return wrap;
    }

    // Provider selector (segmented).
    var provider = c.provider || 'default';
    var seg = Dom.el('div', { style: 'display:inline-flex;background:var(--ink-50);border:1px solid var(--ink-200);border-radius:10px;padding:3px;gap:3px;margin-bottom:16px;flex-wrap:wrap;' });
    var opts = [['default', 'KiddieTrac default'], ['graph', 'Our Microsoft 365'], ['google', 'Our Google / Gmail']];
    var segBtns = {};
    opts.forEach(function (o) {
      var b = Dom.el('button', { type: 'button', style: 'border:none;background:transparent;padding:7px 14px;border-radius:8px;font-size:13px;font-weight:700;cursor:pointer;color:var(--ink-600);transition:all .12s;' }, o[1]);
      b.addEventListener('click', function () { provider = o[0]; paint(); });
      segBtns[o[0]] = b;
      seg.appendChild(b);
    });
    card.appendChild(seg);

    // From identity (shared by both providers).
    var fromRow = Dom.el('div', { style: 'display:grid;grid-template-columns:1fr 1fr;gap:12px;' });
    var fFrom = field('Send from address', { type: 'email', value: c.from, placeholder: 'noreply@youragency.com' });
    var fFromName = field('Send from name', { value: c.from_name, placeholder: 'Your Agency Name' });
    fromRow.appendChild(fFrom); fromRow.appendChild(fFromName);

    // Microsoft 365 (Graph) fields.
    var gWrap = Dom.el('div', {});
    var gTenant = field('Directory (tenant) ID', { value: (c.graph || {}).tenant, placeholder: 'xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx' });
    var gClient = field('Application (client) ID', { value: (c.graph || {}).client_id, placeholder: 'xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx' });
    var gSecret = field('Client secret', { type: 'password', autocomplete: 'new-password',
      placeholder: (c.graph || {}).secret_set ? '•••••••• (unchanged — type to replace)' : 'Client secret value',
      hint: 'From your Azure app registration → Certificates & secrets. The app needs the Mail.Send application permission (admin-consented).' });
    gWrap.appendChild(gTenant); gWrap.appendChild(gClient); gWrap.appendChild(gSecret);

    // Google / Gmail (SMTP) fields.
    var goWrap = Dom.el('div', {});
    var goUser = field('SMTP username (email)', { type: 'email', value: (c.google || {}).username, placeholder: 'you@youragency.com' });
    var goPass = field('App password', { type: 'password', autocomplete: 'new-password',
      placeholder: (c.google || {}).password_set ? '•••••••• (unchanged — type to replace)' : '16-character app password',
      hint: 'Google Account → Security → 2-Step Verification → App passwords. Not your normal login password.' });
    var goHostRow = Dom.el('div', { style: 'display:grid;grid-template-columns:2fr 1fr;gap:12px;' });
    var goHost = field('SMTP host', { value: (c.google || {}).host || 'smtp.gmail.com', placeholder: 'smtp.gmail.com' });
    var goPort = field('Port', { type: 'number', value: (c.google || {}).port || 587, placeholder: '587' });
    goHostRow.appendChild(goHost); goHostRow.appendChild(goPort);
    goWrap.appendChild(goUser); goWrap.appendChild(goPass); goWrap.appendChild(goHostRow);

    card.appendChild(fromRow);
    card.appendChild(gWrap);
    card.appendChild(goWrap);

    var status = Dom.el('div', { style: 'min-height:20px;font-size:13px;margin:6px 0;' });
    card.appendChild(status);

    var actions = Dom.el('div', { style: 'display:flex;gap:10px;align-items:center;flex-wrap:wrap;margin-top:8px;' });
    var saveBtn = Dom.el('button', { style: btnPrimary() }, 'Save email settings');
    actions.appendChild(saveBtn);
    // Inline test.
    var testInput = Dom.el('input', { type: 'email', placeholder: 'you@example.com',
      style: 'padding:8px 12px;border:1px solid var(--ink-300);border-radius:6px;font-size:14px;width:200px;' });
    var testBtn = Dom.el('button', { style: 'padding:9px 16px;border:1px solid var(--ink-300);background:white;border-radius:8px;font-size:13px;font-weight:700;cursor:pointer;' }, 'Send test');
    var testWrap = Dom.el('div', { style: 'display:flex;gap:8px;align-items:center;flex-wrap:wrap;' });
    testWrap.appendChild(testInput); testWrap.appendChild(testBtn);
    actions.appendChild(testWrap);
    card.appendChild(actions);
    var testStatus = Dom.el('div', { style: 'min-height:18px;font-size:12.5px;margin-top:6px;' });
    card.appendChild(testStatus);

    function paint() {
      opts.forEach(function (o) {
        var on = provider === o[0];
        segBtns[o[0]].style.background = on ? 'white' : 'transparent';
        segBtns[o[0]].style.color = on ? 'var(--brand-600, #1F6080)' : 'var(--ink-600)';
        segBtns[o[0]].style.boxShadow = on ? '0 1px 3px rgba(0,0,0,.12)' : 'none';
      });
      var isDefault = provider === 'default';
      fromRow.style.display = isDefault ? 'none' : 'grid';
      gWrap.style.display = provider === 'graph' ? 'block' : 'none';
      goWrap.style.display = provider === 'google' ? 'block' : 'none';
      testWrap.style.opacity = isDefault ? '.4' : '1';
      testBtn.disabled = isDefault; testInput.disabled = isDefault;
    }
    paint();

    saveBtn.addEventListener('click', async function () {
      var payload = {
        provider: provider,
        from: fFrom._input.value.trim(),
        from_name: fFromName._input.value.trim(),
        graph_tenant: gTenant._input.value.trim(),
        graph_client_id: gClient._input.value.trim(),
        google_username: goUser._input.value.trim(),
        google_host: goHost._input.value.trim(),
        google_port: goPort._input.value
      };
      // Only send a secret/password when the operator actually typed one.
      if (gSecret._input.value) payload.graph_client_secret = gSecret._input.value;
      if (goPass._input.value) payload.google_password = goPass._input.value;

      saveBtn.disabled = true;
      status.style.color = '#1F6080'; status.textContent = 'Saving…';
      try {
        var out = await Api.put('/admin/agency-mail', payload);
        status.style.color = '#16A34A';
        status.textContent = provider === 'default'
          ? '✓ Saved. This agency now uses the KiddieTrac default email.'
          : '✓ Saved. Emails for this agency will now send from your own ' + (provider === 'graph' ? 'Microsoft 365' : 'Google') + '. Send a test to confirm.';
        badge.style.display = out.active ? 'inline-block' : 'none';
        gSecret._input.value = ''; goPass._input.value = '';
        if ((out.config || {}).graph && out.config.graph.secret_set) gSecret._input.placeholder = '•••••••• (unchanged — type to replace)';
        if ((out.config || {}).google && out.config.google.password_set) goPass._input.placeholder = '•••••••• (unchanged — type to replace)';
      } catch (e) {
        status.style.color = '#DC2626';
        status.textContent = 'Save failed: ' + (e.message || 'error');
      }
      saveBtn.disabled = false;
    });

    testBtn.addEventListener('click', async function () {
      var to = testInput.value.trim();
      if (!to) { testStatus.style.color = '#DC2626'; testStatus.textContent = 'Enter an address to send the test to.'; return; }
      testBtn.disabled = true;
      testStatus.style.color = '#1F6080'; testStatus.textContent = 'Sending test…';
      try {
        var r = await Api.post('/admin/agency-mail/test', { to: to });
        if (r.ok) { testStatus.style.color = '#16A34A'; testStatus.textContent = '✓ Test sent from ' + r.from + ' via your ' + (r.via === 'graph' ? 'Microsoft 365' : 'Google') + '. Check the inbox.'; }
        else { testStatus.style.color = '#DC2626'; testStatus.textContent = '✗ ' + (r.message || 'Test failed'); }
      } catch (e) {
        testStatus.style.color = '#DC2626';
        testStatus.textContent = 'Test failed: ' + (e.message || 'error');
      }
      testBtn.disabled = false;
    });
  }

  // ════════════════════════════════════════════════════════════════
  //   BILLING TAB (Stripe Connect)
  // ════════════════════════════════════════════════════════════════
  // v22p83: Country & compliance card — pick the agency's country to apply its
  // currency/locale and surface the childcare, privacy, PCI and tax frameworks.
  // Rendered on the Centres tab (the Branding tab's #admin-branding hash is taken
  // by the reseller branding screen). Self-contained: appends its own card.
  // Exposed so the Agency overview and Branding & settings can render the SAME
  // card instead of each keeping a copy that drifts.
  KT.renderCountryCard = function (container) { return renderCountryCard(container); };

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
      panel.appendChild(Dom.el('div', { style: 'font-size:11.5px;color:#64748B;margin-top:6px;' }, 'These frameworks are indicative defaults to guide setup — your agency remains responsible for verifying local legal compliance.'));
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
        if (!await KT.confirm('Cancel your subscription? This will suspend the account at the end of the billing period.')) return;
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
  // opts.user marks this as a PERSON, which lets the avatar lightbox show their
  // role and link through to their record. Deliberately opt-in: this helper is
  // also called for families, whose id is a family id, not a user id.
  // Open a person's record from anywhere in the portal — used by the avatar
  // lightbox's "View profile". There is no single-user endpoint, so this reads
  // the list the Users screen already uses and hands the row to the same modal,
  // which keeps one implementation of "the user record" rather than two.
  KT.openUserRecord = function (userId) {
    if (!userId) return Promise.resolve(false);
    return Api.get('/admin/users').then(function (data) {
      var rows = (data && (data.data || data.users || data)) || [];
      var u = (rows.length ? rows : []).filter(function (r) { return String(r.id) === String(userId); })[0];
      if (!u) { try { KT.Shell.navigate('admin-users'); } catch (e) {} return false; }
      showUserModal(u, Dom.el('div', {}));
      return true;
    }).catch(function () {
      try { KT.Shell.navigate('admin-users'); } catch (e) {}
      return false;
    });
  };

  function avatarCircle(u, size, opts) {
    size = size || 36;
    opts = opts || {};
    const attrs = {
      style: 'flex-shrink:0;width:' + size + 'px;height:' + size + 'px;border-radius:50%;overflow:hidden;background:' + avatarColor(u) + ';color:white;display:flex;align-items:center;justify-content:center;font-weight:700;font-size:' + Math.round(size * 0.4) + 'px;letter-spacing:0.3px;box-shadow:0 1px 3px rgba(0,0,0,0.1);',
    };
    const personName = (u && (u.name || [u.first_name, u.last_name].filter(Boolean).join(' '))) || '';
    if (personName) attrs['data-kt-name'] = personName;
    if (opts.user && u && u.id) {
      attrs['data-kt-user-id'] = String(u.id);
      const r = (u.roles && u.roles.length) ? u.roles.join(', ') : (u.role || '');
      if (r) attrs['data-kt-role'] = String(r);
    }
    const wrap = Dom.el('div', attrs);
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

  // Last-login stamp: date + time, in the agency's timezone. Uses KT.Fmt.parse so
  // zone-less UTC strings (what DB::table hands back) aren't misread as local time.
  function fmtLoginStamp(v) {
    if (!v) return 'Never';
    try {
      var d = (window.KT && KT.Fmt && KT.Fmt.parse) ? KT.Fmt.parse(v) : new Date(v);
      if (!d || isNaN(d.getTime())) return 'Never';
      var z = (window.KT && KT.tz) ? KT.tz() : null;
      var o = { year: 'numeric', month: 'short', day: 'numeric', hour: 'numeric', minute: '2-digit', hour12: true };
      return d.toLocaleString('en-CA', z ? Object.assign({ timeZone: z }, o) : o);
    } catch (e) { return 'Never'; }
  }

  function statusBadge(status) {
    const colors = {
      active: ['#DCFCE7', '#166534'],
      onboarding: ['#FEF3C7', '#92400E'],
      paused: ['#FEE2E2', '#991B1B'],
      closed: ['#F3F4F6', '#374151'],
      invited: ['#DBEAFE', '#1E40AF'],
      // Imported but never sent an invite — distinct from "invited" so admins can
      // see at a glance who still needs to be invited (amber, like a to-do).
      not_invited: ['#FEF3C7', '#92400E'],
      suspended: ['#FEE2E2', '#991B1B'],
      deactivated: ['#F3F4F6', '#374151'],
    };
    const labels = { not_invited: 'Not invited' };
    const c = colors[status] || ['#F3F4F6', '#374151'];
    return Dom.el('span', {
      style: 'display: inline-block; background: ' + c[0] + '; color: ' + c[1] + '; padding: 3px 10px; border-radius: 12px; font-size: 11px; font-weight: 700; letter-spacing: 0.5px; text-transform: uppercase;',
    }, labels[status] || status);
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
  // Exposed so the agency overview can open the centre edit modal IN PLACE
  // (no teleport to the Administration › Centres tab). Pass an onSaved refresh.
  window.KT.showCentreModal = showCentreModal;
  // Clicking your own name in the top bar opens your account record (view + edit
  // details/roles) instead of re-running the onboarding wizard. Self-edit is
  // allowed by the backend even for a platform-level superadmin.
  window.KT.openMyAccount = function () {
    var u = null;
    try { u = JSON.parse(sessionStorage.getItem('kt_user') || '{}'); } catch (e) {}
    if (!u || !u.id) { location.hash = '#onboarding'; return; }
    var content = document.getElementById('appMain') || document.body;
    try { showUserModal(u, content); } catch (e) { location.hash = '#onboarding'; }
  };

  Shell.registerScreen('agency_admin:admin', renderAdmin);
  // v22p2.3: register deep-link hashes per tab so nav entries can land on a specific tab.
  ['centres', 'users', 'families', 'branding', 'billing'].forEach(function (tab) {
    Shell.registerScreen('agency_admin:admin-' + tab, function (main, ctx) {
      state.activeTab = tab;
      return renderAdmin(main, ctx);
    });
  });
})(window);
