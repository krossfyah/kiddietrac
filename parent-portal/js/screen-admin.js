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

    // Header — the legacy hub title only. The per-section titles that used to live here
    // are gone with the header they fed; each section's own banner carries its name now.
    /* Only the legacy "#admin" hub gets this plain header. Every admin-<tab> deep-link
       is its own sidebar item whose CONTENT draws a branded banner, so rendering a title
       here too opened those pages with the name twice — grey text on top, the real banner
       below it — and pushed the banner off the top of the page. (Anthony, 2026-08-30:
       "the banner is not at the top and there is other text at the top which is redundant
       to the banner".) The shell also strips a heading that exactly repeats the banner
       (app-v2-shell normaliseBanners/tidyOwnBanner), but that cannot catch "Centres /
       Rooms" above a banner titled "Centres" — near-duplicates have to stop at source. */
    if (! isDeepLink) {
      const header = Dom.el('div', { style: 'margin-bottom: 24px;' });
      header.appendChild(Dom.el('h1', { style: 'font-size: 28px; font-weight: 800; margin: 0;' }, 'Admin'));
      header.appendChild(Dom.el('div', { style: 'color: var(--ink-500); margin-top: 4px;' }, 'Manage centres, users, families, and branding'));
      wrap.appendChild(header);
    }

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

  /* The provider actions, as plain buttons for a row's action cell.

     They were a "Danger zone" at the bottom of the centre EDIT form, which meant
     closing a provider required opening the record and scrolling past every field
     to reach it. These are actions ON a provider, not properties of one.

     Deliberately plain buttons with word labels: kt-row-actions.js collapses the
     last cell into the ⋮ kebab and forwards a real click back here, and inside that
     menu the button's TEXT is the menu item — so a bare glyph would render a blank
     row, and "Archive"/"Delete" earn the red destructive styling for free. */
  /* A true read-only view of a provider: no inputs, no Save, nothing to change.
     Reading a record should not mean opening the form that can overwrite it.

     Renders from the row the list already has, so it opens without a request. */
  function showCentreView(c, content) {
    var body = Dom.el('div', {});

    var INK = 'var(--ink-500,#64748B)';
    var dash = '—';
    function val(v) {
      if (v === null || v === undefined || String(v).trim() === '') return dash;
      return String(v);
    }

    /* A heading for each group. Grouping matters more here than in the form: the
       form has an order you tab through, a view has an order you SCAN. */
    function section(title) {
      body.appendChild(Dom.el('div', {
        style: 'font-size:11px;font-weight:800;color:' + INK + ';letter-spacing:.7px;'
          + 'text-transform:uppercase;margin:18px 0 6px;padding-top:10px;'
          + 'border-top:1px solid var(--ink-100,#E5E7EB);',
      }, title));
    }

    /* Two columns on a desktop dialog, one on a phone — the label column is fixed
       so the values line up and can be read down rather than hunted for. */
    var grid = null;
    function startGrid() {
      grid = Dom.el('div', { style: 'display:grid;grid-template-columns:minmax(120px,190px) 1fr;gap:7px 16px;align-items:start;' });
      body.appendChild(grid);
    }
    function row(label, value, opts) {
      if (!grid) startGrid();
      grid.appendChild(Dom.el('div', {
        style: 'font-size:12.5px;color:' + INK + ';font-weight:600;padding-top:1px;',
      }, label));
      var style = 'font-size:13.5px;color:var(--ink-900,#0F172A);word-break:break-word;';
      if (opts && opts.muted) style += 'color:' + INK + ';';
      grid.appendChild(Dom.el('div', { style: style }, val(value)));
    }
    function endGrid() { grid = null; }

    // ── header: who this is ──────────────────────────────────────────────
    var head = Dom.el('div', { style: 'display:flex;align-items:center;gap:13px;margin-bottom:4px;' });
    var accent = (window.KT && KT.providerBand) ? KT.providerBand(c) : (c.brand_color || '#1F6080');
    var logo = Dom.el('div', {
      style: 'flex-shrink:0;width:52px;height:52px;border-radius:11px;overflow:hidden;background:' + accent
        + ';color:#fff;display:flex;align-items:center;justify-content:center;font-weight:800;font-size:20px;',
    });
    try { provAvatarInto(logo, c); } catch (e) {}
    head.appendChild(logo);
    var stack = Dom.el('div', { style: 'min-width:0;' });
    stack.appendChild(Dom.el('div', { style: 'font-size:17px;font-weight:800;line-height:1.2;' }, c.name || dash));
    if (c.tagline) stack.appendChild(Dom.el('div', { style: 'font-size:12.5px;color:' + INK + ';margin-top:2px;' }, c.tagline));
    head.appendChild(stack);
    body.appendChild(head);

    var badges = Dom.el('div', { style: 'display:flex;gap:8px;flex-wrap:wrap;margin:10px 0 2px;' });
    try { badges.appendChild(statusBadge(c.status)); } catch (e) {}
    try { badges.appendChild(emailBadgeEl(c.email_enabled !== false)); } catch (e) {}
    if (c.cwelcc_enrolled) {
      badges.appendChild(Dom.el('span', {
        style: 'font-size:11px;font-weight:700;padding:3px 9px;border-radius:999px;background:#EFF6FF;color:#1D4ED8;',
      }, 'CWELCC enrolled'));
    }
    body.appendChild(badges);

    // ── at a glance ──────────────────────────────────────────────────────
    section('At a glance');
    startGrid();
    var cap = c.license_capacity ? (val(c.enrolled_count || 0) + ' of ' + c.license_capacity
      + '  (' + (c.capacity_pct || 0) + '%)') : val(c.enrolled_count || 0);
    row('Children enrolled', cap);
    if (c.max_concurrent_children) {
      row('At one time', val(c.children_present || 0) + ' of ' + c.max_concurrent_children);
    }
    row('Families', val(c.family_count || 0));
    row('Staff', val(c.staff_count || 0));
    endGrid();

    // ── the provider ─────────────────────────────────────────────────────
    section('Provider');
    startGrid();
    var owner = [c.supervisor_first_name, c.supervisor_last_name].filter(Boolean).join(' ');
    row('Owner / supervisor', owner);
    row('Licence number', c.license_number);
    row('Phone', (window.KT && KT.Phone) ? KT.Phone.format(c.phone) || c.phone : c.phone);
    row('Email', c.email);
    endGrid();

    // ── address ──────────────────────────────────────────────────────────
    section('Address');
    startGrid();
    row('Street', c.address_line1);
    if (c.address_line2) row('Unit / suite', c.address_line2);
    row('City', c.city);
    row('Province / State', c.province);
    row('Postal / ZIP', c.postal_code);
    row('Country', c.country);
    endGrid();

    // ── hours ────────────────────────────────────────────────────────────
    section('Hours');
    startGrid();
    var hrs = (c.open_time && c.close_time)
      ? (fmtTime(c.open_time) + ' – ' + fmtTime(c.close_time))
      : (c.open_time ? fmtTime(c.open_time) + ' onwards' : '');
    row('Opens – closes', hrs);
    row('Open days', daysLabel(c.open_days));
    endGrid();

    // ── rooms, when there are any ────────────────────────────────────────
    if (c.rooms && c.rooms.length) {
      section('Rooms');
      startGrid();
      c.rooms.forEach(function (r) {
        var detail = [r.age_group, r.capacity ? ('capacity ' + r.capacity) : null].filter(Boolean).join(' · ');
        row(r.name || dash, detail || dash);
      });
      endGrid();
    }

    if (c.provider_bio) {
      section('About');
      body.appendChild(Dom.el('div', {
        style: 'font-size:13.5px;line-height:1.55;white-space:pre-wrap;color:var(--ink-800,#1E293B);',
      }, c.provider_bio));
    }

    Shell.Modal.open({
      title: c.name,
      body: body,
      /* One button, and it only closes. No Save, and deliberately no Edit either:
         a view that can turn into a form is not a view, and Edit is one item away
         in the same kebab this was opened from. */
      actions: [{ label: 'Close', primary: true }],
    });
  }

  /* 07:00:00 -> 7:00 AM. The stored value is a wall-clock time, never converted. */
  function fmtTime(t) {
    var m = String(t || '').match(/^(\d{1,2}):(\d{2})/);
    if (!m) return String(t || '');
    var h = parseInt(m[1], 10);
    var ap = h >= 12 ? 'PM' : 'AM';
    var h12 = h % 12; if (h12 === 0) h12 = 12;
    return h12 + ':' + m[2] + ' ' + ap;
  }

  /* ISO weekdays (1 = Monday) as the short names the rest of the portal uses. */
  function daysLabel(days) {
    if (!days || !days.length) return '';
    var NAMES = { 1: 'Mon', 2: 'Tue', 3: 'Wed', 4: 'Thu', 5: 'Fri', 6: 'Sat', 7: 'Sun' };
    return days.slice().sort(function (a, b) { return a - b; })
      .map(function (d) { return NAMES[d] || d; }).join(', ');
  }

  function centreActionButtons(c, content) {
    var PLAIN = 'background:transparent;border:1px solid var(--ink-300);padding:6px 12px;'
      + 'border-radius:6px;cursor:pointer;font-size:13px;';
    function mk(label) { return Dom.el('button', { type: 'button', style: PLAIN }, label); }

    function toast(msg, kind) {
      if (window.KT && window.KT.Dom && window.KT.Dom.toast) window.KT.Dom.toast(msg, kind);
    }

    /* View first: reading a provider is the common case, and it should not mean
       opening the form that can overwrite it. */
    var viewBtn = mk('View');
    viewBtn.addEventListener('click', function (e) { e.stopPropagation(); showCentreView(c, content); });

    var editBtn = mk('Edit');
    editBtn.addEventListener('click', function (e) { e.stopPropagation(); showCentreModal(c, content); });

    /* The one way a provider leaves: decide where every family goes, clock the staff
       out, email the parents, then archive. It is the whole job, not a first step. */
    var offboardBtn = mk('\ud83d\udeaa Close this provider\u2026');
    offboardBtn.addEventListener('click', function (e) { e.stopPropagation(); openCentreOffboard(c, content); });

    /* Archive is the LAST STEP of "Close this provider", not a button beside it:
       that flow transfers or withdraws every family and clocks staff out first, and
       the archive endpoint refuses while children are still enrolled. Permanent
       delete lives on Agency overview -> Archived centres, on records that have
       already been archived. */
    return [viewBtn, editBtn, offboardBtn];
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
    content.appendChild(archiveSwitch(content, 'centres', renderCentresTab, 'active'));

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
    /* Position-assigned colours, same as the cards view -- see providerBand(). */
    buildProviderBands(data.centres);
    data.centres.forEach(c => {
      /* NO ROW BAND.

         This used to draw the centre's brand colour down the row's left edge
         (box-shadow:inset 4px 0 0). It was the only table in the portal that did --
         every other list renders a plain row -- and it encoded nothing anyway: it
         read `c.brand_color || '#1F6080'` while 10 of the 12 providers still carry
         the seeded default, so ten rows drew an identical navy stripe and two drew
         an arbitrary one. A band that marks everything marks nothing.

         The colour still appears where it can actually be told apart: the avatar
         tile in the Name column, via providerBand() -- the same helper the cards
         view and the detail dialog use, so one provider is one colour everywhere. */
      const accent = providerBand(c);
      const row = Dom.el('tr', { style: 'border-top: 1px solid var(--ink-100, #E5E7EB);' });
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
      /* Edit plus the three provider actions. kt-row-actions collapses this last
         cell into the \u22ee kebab on desktop; phones keep them as plain buttons. */
      const actCell = Dom.el('td', { style: 'padding: 14px 16px; text-align: right; white-space: nowrap;' });
      centreActionButtons(c, content).forEach(function (b) { b.style.marginLeft = '6px'; actCell.appendChild(b); });
      row.appendChild(actCell);
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
  /* A stable colour per provider. Keyed by id, not by position in the list, so a
     provider keeps the same colour when the list is sorted, filtered or added to —
     colour is only a shortcut if it means the same thing every time you look. */
  var PROVIDER_BANDS = [
    '#1F6080', '#B45309', '#166534', '#7C3AED', '#BE123C',
    '#0F766E', '#A16207', '#4338CA', '#9D174D', '#065F46',
    '#C2410C', '#1D4ED8',
  ];
  /* id -> palette slot, built once per render from the whole list. */
  var _bandBy = {};
  function buildProviderBands(list) {
    _bandBy = {};
    (list || []).slice()
      .sort(function (a, b) { return (a.id || 0) - (b.id || 0); })
      .forEach(function (c, i) {
        _bandBy[String(c.id)] = PROVIDER_BANDS[i % PROVIDER_BANDS.length];
      });
  }

  function providerBand(c) {
    /* '#1f6080' is the seeded default every centre carries, not a chosen colour —
       honouring it gave nine identical navy cards. Only a brand_color that DIFFERS
       from the default counts as a real preference. */
    var SEEDED_DEFAULT = '#1f6080';
    var chosen = (c && c.brand_color) ? String(c.brand_color).trim().toLowerCase() : '';
    if (chosen && chosen !== SEEDED_DEFAULT) { return c.brand_color; }
    var key = (c && c.id != null) ? String(c.id) : '';
    /* Position-assigned, so no two providers share a colour while there are fewer
       of them than palette entries. Hashing collided at nine. */
    if (key && _bandBy[key]) { return _bandBy[key]; }
    key = key || String((c && c.name) || '');
    var h = 0;
    for (var i = 0; i < key.length; i++) { h = (h * 31 + key.charCodeAt(i)) >>> 0; }
    return PROVIDER_BANDS[h % PROVIDER_BANDS.length];
  }

  /* Published so every screen showing providers uses the SAME colour for the same
     person. A colour is only a shortcut if it means the same thing everywhere;
     two screens each deriving their own would be worse than one flat colour. */
  window.KT = window.KT || {};
  window.KT.providerBand = function (centre, allCentres) {
    if (allCentres) { buildProviderBands(allCentres); }
    return providerBand(centre);
  };

  function renderCentresCards(centres, content) {
    buildProviderBands(centres);
    /* data-kt-list is what gets a NON-table list the same \u22ee kebab from
       kt-row-actions. data-kt-no-controls comes with it because that attribute also
       grants a search + A\u2013Z bar from kt-list-controls, and this screen already
       has its own toolbar directly above. */
    var grid = Dom.el('div', {
      'data-kt-list': '1',
      'data-kt-no-controls': '1',
      style: 'display:grid;grid-template-columns:repeat(auto-fill, minmax(320px, 1fr));gap:16px;',
    });
    centres.forEach(function (c) {
      /* Was a flat #1F6080 for everyone, since no provider has a brand_color set —
         nine identical navy cards. */
      var accent = providerBand(c);
      var card = Dom.el('div', { style: 'background:white;border-radius:12px;box-shadow:0 1px 3px rgba(0,0,0,0.05);overflow:hidden;cursor:pointer;border-left:6px solid ' + accent + ';position:relative;' });

      // Edit moved into the action bar at the foot of the card, with the rest.

      // header — logo + name + city
      // The right padding was reserving space for the floating Edit button, now gone.
      var head = Dom.el('div', { style: 'display:flex;align-items:center;gap:12px;padding:16px 16px 10px;' });
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
      /* Present now vs how many may be here at once — the pair that answers
         "is this provider compliant right now". Enrolment may lawfully exceed the
         concurrent limit, so the old enrolled/licence pair flagged compliant
         providers as over. Falls back to the enrolment view when no limit is set,
         rather than showing a total with nothing to compare it against. */
      var _lim = c.max_concurrent_children;
      var _in = c.children_present || 0;
      if (_lim) {
        var _over = _in > _lim, _at = _in === _lim;
        stats.appendChild(statCell('In now', _in + ' / ' + _lim,
          _over ? '#B91C1C' : (_at ? '#B45309' : '#1D4ED8'),
          _over ? '#FEE2E2' : (_at ? '#FEF3C7' : '#EFF6FF')));
      } else {
        stats.appendChild(statCell('Enrolled', (c.enrolled_count || 0) + ' / ' + (c.license_capacity || 0), '#1D4ED8', '#EFF6FF'));
      }
      stats.appendChild(statCell('Families', c.family_count || 0, '#334155', '#F8FAFC'));
      stats.appendChild(statCell('Staff', c.staff_count || 0, staffZero ? '#B91C1C' : '#15803D', staffZero ? '#FEF2F2' : '#F0FDF4'));
      card.appendChild(stats);

      /* The card's LAST element child, containing nothing but buttons — that is the
         exact shape kt-row-actions looks for on a [data-kt-list] card, and what it
         collapses into the \u22ee. Anything else here (a stat, a note) and it would
         correctly refuse to treat the row as actions. */
      var actions = Dom.el('div', {
        style: 'display:flex;gap:6px;flex-wrap:wrap;justify-content:flex-end;padding:10px 16px;'
          + 'border-top:1px solid var(--ink-100,#E5E7EB);',
      });
      centreActionButtons(c, content).forEach(function (b) { actions.appendChild(b); });
      card.appendChild(actions);

      card.addEventListener('click', function () { showCentreModal(c, content); });
      grid.appendChild(card);
    });
    return grid;
  }

  function showCentreModal(centre, content, onSaved) {
    const isEdit = !!centre;
    const body = Dom.el('div', {});
    const form = Dom.el('form', {});

    /* Required only when creating. An existing centre may predate these fields, and
       demanding them before somebody can fix a phone number would block the wrong person
       from doing the right thing. */
    const req = !isEdit;

    const fields = [
      { key: 'name', label: 'Centre name', required: true },
      // Inherited from the duplicate "Add centre" form on Agency overview, now retired.
      { key: 'supervisor_first_name', label: 'Owner / supervisor first name', required: req },
      { key: 'supervisor_last_name', label: 'Owner / supervisor last name', required: req },
      { key: 'license_number', label: 'License number' },
      { key: 'license_capacity', label: 'Maximum children enrolled (capacity)', type: 'number' },
      /* Deliberately the next line down: the contrast with the enrolment cap above
         is the whole point. Enrolment may exceed this; attendance may not. */
      { key: 'max_concurrent_children', label: 'Maximum children at one time', type: 'number' },
      { key: 'address_line1', label: 'Address', required: req },
      { key: 'address_line2', label: 'Address line 2 (unit, suite, floor)' },
      { key: 'city', label: 'City', required: req },
      { key: 'province', label: 'Province / State', default: 'ON', required: req },
      { key: 'postal_code', label: 'Postal / ZIP code', required: req },
      /* Was hardcoded to 'CA' server-side, so a US agency's centres were all created as
         Canadian — which decides the statutory-holiday calendar and the currency. */
      { key: 'country', label: 'Country', default: 'Canada', required: req },
      { key: 'phone', label: 'Phone' },
      { key: 'email', label: 'Email', type: 'email' },
    ];

    const inputs = {};
    const edited = {};   // last value typed per field, immune to a re-render

    /* Address lookup. Fills the address fields from one chosen result so a postcode is
       not typed from memory — it is the field most often wrong and the one the holiday
       calendar, invoices and the provider map all trust.

       Photon: open data, no key, no per-request cost, and already used elsewhere here.
       Advisory only — every field stays editable and a provider saves fine without it,
       because a rural address a geocoder does not know is still a real address. */
    const lookupWrap = Dom.el('div', { style: 'margin-bottom:14px;' });
    lookupWrap.appendChild(Dom.el('label', {
      style: 'display:block;font-size:13px;font-weight:600;margin-bottom:4px;',
    }, 'Find the address'));
    const lookupInput = Dom.el('input', {
      type: 'text',
      placeholder: 'Start typing an address\u2026',
      style: 'width:100%;padding:8px 12px;border:1px solid var(--ink-300);border-radius:6px;font-size:14px;box-sizing:border-box;',
    });
    const lookupList = Dom.el('div', {
      style: 'border:1px solid var(--ink-200,#E2E8F0);border-top:none;border-radius:0 0 6px 6px;display:none;max-height:190px;overflow:auto;background:#fff;',
    });
    const lookupHint = Dom.el('div', {
      style: 'font-size:11.5px;color:var(--ink-500,#64748B);margin-top:4px;',
    }, 'Optional \u2014 it just fills the fields below. You can always type them yourself.');
    lookupWrap.appendChild(lookupInput);
    lookupWrap.appendChild(lookupList);
    lookupWrap.appendChild(lookupHint);
    form.appendChild(lookupWrap);

    (function () {
      var timer = null;
      var lastQuery = '';

      function hide() { lookupList.style.display = 'none'; lookupList.innerHTML = ''; }

      function choose(p) {
        // Photon splits the street from the number; the form wants one line.
        var line1 = [p.housenumber, p.street || p.name].filter(Boolean).join(' ');
        var set = {
          address_line1: line1 || p.name || '',
          city: p.city || p.town || p.village || p.county || '',
          province: p.state || '',
          postal_code: p.postcode || '',
          country: p.country || '',
        };
        Object.keys(set).forEach(function (k) {
          if (!inputs[k] || !set[k]) return;
          inputs[k].value = set[k];
          edited[k] = set[k];          // the form reads `edited` on save
        });
        hide();
        lookupHint.textContent = 'Filled from the lookup \u2014 check it and correct anything that is wrong.';
      }

      lookupInput.addEventListener('input', function () {
        var q = lookupInput.value.trim();
        if (timer) { clearTimeout(timer); }
        if (q.length < 4) { hide(); return; }
        // Typed slowly enough to be a search, not a keystroke-per-request.
        timer = setTimeout(async function () {
          if (q === lastQuery) return;
          lastQuery = q;
          try {
            var r = await fetch('https://photon.komoot.io/api/?limit=5&q=' + encodeURIComponent(q));
            var j = await r.json();
            var feats = (j && j.features) || [];
            if (!feats.length) { hide(); return; }
            lookupList.innerHTML = '';
            feats.forEach(function (f) {
              var p = f.properties || {};
              var line = [
                [p.housenumber, p.street || p.name].filter(Boolean).join(' '),
                p.city || p.town || p.village,
                p.state,
                p.postcode,
                p.country,
              ].filter(Boolean).join(', ');
              var row = Dom.el('div', {
                style: 'padding:8px 11px;font-size:13px;cursor:pointer;border-top:1px solid var(--ink-100,#F1F5F9);',
              }, line);
              row.addEventListener('mouseenter', function () { row.style.background = '#F1F5F9'; });
              row.addEventListener('mouseleave', function () { row.style.background = ''; });
              row.addEventListener('click', function () { choose(p); });
              lookupList.appendChild(row);
            });
            lookupList.style.display = 'block';
          } catch (e) {
            // The lookup is a convenience; losing it must not stop the form working.
            hide();
            lookupHint.textContent = 'Address lookup is unavailable \u2014 type the address below.';
          }
        }, 350);
      });

      lookupInput.addEventListener('blur', function () { setTimeout(hide, 180); });
    })();

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
    /* Opening and closing sit WITH the days rather than up in the address block: which
       days you operate and between what hours is one idea, and both drive the same
       downstream rules -- late-pickup detection, clock in/out and attendance. */
    const hoursRow = Dom.el('div', { style: 'display:flex;flex-wrap:wrap;gap:12px;margin:0 0 10px;' });
    ['open_time', 'close_time'].forEach(function (key, n) {
      const cell = Dom.el('div', { style: 'flex:0 0 auto;' });
      cell.appendChild(Dom.el('label', { style: 'display:block;font-size:12px;color:#475569;margin-bottom:3px;' },
        n === 0 ? 'Opens' : 'Closes'));
      const inp = Dom.el('input', { type: 'time',
        style: 'height:32px;padding:0 9px;border:1px solid var(--ink-300);border-radius:6px;font-size:14px;' });
      inp.value = (centre && centre[key]) ? String(centre[key]).slice(0, 5) : '';
      inputs[key] = inp;
      cell.appendChild(inp);
      hoursRow.appendChild(cell);
    });
    openWrap.appendChild(hoursRow);
    openWrap.appendChild(Dom.el('div', { style: 'font-size:11.5px;color:#64748B;margin:0 0 12px;line-height:1.5;' },
      'Closing time is what a late pickup is measured against, and it drives clock in/out reporting. '
      + 'Leave blank if this provider has no fixed closing time -- nothing can then be counted as late.'));
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

      // Email delivery for this centre/provider. The switchboard on
      // Settings → Email settings covers every centre and room at once; this is
      // the same switch on the record itself, because that is where you look when
      // you want to turn email on for THIS provider.
      const emailWrap = Dom.el('div', { style: 'margin:14px 0;padding:12px 14px;border:1px solid var(--ink-100,#E5E7EB);border-radius:10px;background:#F8FAFC;' });
      const emailRow = Dom.el('div', { style: 'display:flex;align-items:center;gap:10px;' });
      const emailIn = Dom.el('input', { type: 'checkbox' });
      emailIn.checked = centre.email_enabled !== false;
      emailRow.appendChild(emailIn);
      emailRow.appendChild(Dom.el('label', { style: 'font-size:14px;font-weight:600;' }, 'Send emails for this ' + (KT.centreWord ? KT.centreWord(false, true) : 'centre')));
      emailWrap.appendChild(emailRow);
      emailWrap.appendChild(Dom.el('div', { style: 'font-size:11.5px;color:#64748B;margin-top:6px;line-height:1.5;' },
        'Off holds back every email to its educators and to the parents of its children — useful while setting one up. Rooms follow their centre: a room cannot send while this is off. The full switchboard, including rooms, is under Settings → Email settings.'));
      const emailMsg = Dom.el('span', { style: 'font-size:12px;font-weight:700;margin-left:8px;' });
      emailRow.appendChild(emailMsg);
      // Saved immediately: it is a switch, not part of the form, and it writes to
      // the same endpoint the switchboard uses rather than a second code path.
      emailIn.addEventListener('change', async () => {
        emailMsg.style.color = '#6B7280'; emailMsg.textContent = 'Saving…';
        try {
          await Api.patch('/admin/email-delivery/centre/' + centre.id, { enabled: emailIn.checked });
          centre.email_enabled = emailIn.checked;
          emailMsg.style.color = '#16A34A';
          emailMsg.textContent = emailIn.checked ? '✓ Email on' : '✓ Email off';
        } catch (e) {
          emailIn.checked = !emailIn.checked;               // put the switch back
          emailMsg.style.color = '#DC2626';
          emailMsg.textContent = (e && e.message) || 'Could not save';
        }
      });
      form.appendChild(emailWrap);

      // Provider bio — required. Sent to parents in the welcome email when a
      // family is assigned to this provider, so they know who's caring for their
      // child. A short, warm first-person introduction works best.
      const bioWrap = Dom.el('div', { style: 'margin-bottom:12px;' });
      bioWrap.appendChild(Dom.el('label', { style: 'display:block;font-size:13px;font-weight:600;margin-bottom:4px;' }, 'Provider bio'));
      const bioIn = Dom.el('textarea', { placeholder: "Hi! I'm … I've cared for children for … years. I believe every child grows best with …", style: 'width:100%;min-height:190px;padding:10px 12px;line-height:1.55;border:1px solid var(--ink-300);border-radius:6px;font-size:14px;box-sizing:border-box;font-family:inherit;resize:vertical;' });
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

    /* The Danger zone that stood here — Close provider / Archive centre / Delete
       permanently — moved to the provider's row, where kt-row-actions turns it into
       the ⋮ kebab. Reaching them meant opening the record and scrolling past every
       field to the bottom of the form, which is a long way to go to close a provider. */

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
              /* false keeps the modal open — Shell.Modal closes on anything else. Without
                 it a refusal threw away everything typed, so fixing one field meant
                 filling the whole provider in again. */
              return false;
            };
            if (!data.name) {
              return refuse('Name is required.', 'name');
            }
            /* Everything marked * must be present when CREATING. Refused one at a time,
               naming and focusing the field, because a list of eight complaints is read
               as a wall and fixed as a guess. */
            if (!isEdit) {
              const mustHave = fields.filter(f => f.required && f.key !== 'name');
              for (const f of mustHave) {
                if (!String(data[f.key] || '').trim()) {
                  return refuse(f.label + ' is required.', f.key);
                }
              }
            }
            // Only REMOVING an existing bio is refused. Requiring one before any
            // other field can be saved blocked ordinary edits — a capacity change on
            // a provider that never had a bio simply would not save.
            const bioExisted = !!(centre && String(centre.provider_bio || '').trim());
            const bioNowEmpty = inputs.provider_bio && (!data.provider_bio || !data.provider_bio.trim());
            if (bioExisted && bioNowEmpty) {
              return refuse('The provider bio cannot be removed — families are sent it when they join.', 'provider_bio');
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

    ['Name', 'Username', 'Email', 'Roles', 'Status', 'Onboarding', 'Last seen', ''].forEach(h => {
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

      row.appendChild(Dom.el('td', { style: 'padding: 14px 16px;' }, statusBadge(u, inviteTip(u))));
      row.appendChild(userOnboardingCell(u));
      row.appendChild(Dom.el('td', { style: 'padding: 14px 16px; color: var(--ink-500); font-size: 13px; white-space: nowrap;' }, fmtLoginStamp(u.last_seen_at || u.last_login_at)));

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
          // The moment you need this is usually while scanning the list —
          // somebody says they never received the email.
          iconBtn('🔗', 'Manual sign-in link', function () { rowManualSignIn(u); }),
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
        /* A check that did not RUN is not a check that came back clean. This used to
           read `(r && r.matches) || []`, so a failed request produced an empty list,
           hid the warning and set dupHasMatches = false — telling the admin it was safe
           to create a person who already exists. Same shape as the clock bar reading a
           failed poll as "not clocked in" (2026-08-25). */
        if (!r || !Array.isArray(r.matches)) { throw new Error('dup-check-unavailable'); }
        const m = r.matches;
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
      }).catch(() => {
        // Say so, plainly. Note it does NOT clear dupHasMatches: if an earlier check
        // did find something, that finding stands until a later check succeeds.
        dupWarn.style.display = 'block';
        dupWarn.innerHTML = '<div style="font-weight:800;margin-bottom:4px;">\u26a0 Could not check for existing records</div>'
            + '<div>The duplicate check did not run \u2014 please confirm this is not already in KiddieTrac before saving.</div>';
      });
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
          /* Every role except these is scoped to ONE centre — without it the
             role_assignment has no scope and the user would not even appear in the
             list. The server enforces this and says so plainly; catching it here means
             the person is told before they submit, next to the field they need to
             fix. */
          // Must match the server's list exactly, or the form blocks a choice the
          // API would have accepted — which is what happened when "agency-wide" was
          // offered for a home visitor and then refused.
          var NO_CENTRE_NEEDED = ['agency_admin', 'platform_admin', 'sales_rep', 'home_visitor'];
          if (NO_CENTRE_NEEDED.indexOf(data.role) === -1 && !data.centre_id) {
            status.style.color = '#DC2626';
            status.textContent = 'Please choose a centre — ' + (data.role || 'this role') + ' must belong to one.';
            try {
              inputs.centre_id.style.borderColor = '#DC2626';
              inputs.centre_id.focus();
              inputs.centre_id.scrollIntoView({ block: 'center', behavior: 'smooth' });
            } catch (e) {}
            try { _toast('⚠️', 'Pick a centre', 'This role must belong to one centre.', '#DC2626'); } catch (e) {}
            return;
          }
          try { inputs.centre_id.style.borderColor = ''; } catch (e) {}
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
            /* The API's message is the useful part — it names the actual problem.
               Shown inline AND as a toast, because inline text in a scrolled modal is
               easy to miss, which is exactly how a clear message went unread. */
            var why = (e && e.message) ? e.message : 'Something went wrong — please try again.';
            status.style.color = '#DC2626';
            status.textContent = why;
            try { _toast('⚠️', 'Could not create the user', why, '#DC2626'); } catch (e) {}
            try { status.scrollIntoView({ block: 'center', behavior: 'smooth' }); } catch (e2) {}
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
      Dom.el('div', { style: 'margin-top:4px;' }, statusBadge(u)),
    ]));
    body.appendChild(head);
    // What the list row already knows — shown immediately so the dialog is never
    // empty while the full record is on its way.
    body.appendChild(rowEl('Username', u.username || '—', true));
    body.appendChild(rowEl('Email', u.email));
    body.appendChild(rowEl('Phone', u.phone));
    body.appendChild(rowEl('Roles', (u.roles || []).map(function (r) { return r.replace(/_/g, ' '); }).join(', ') || '—'));
    body.appendChild(rowEl('Last login', fmtDT(u.last_login_at)));
    /* A different fact, worth having beside it: last_login_at is the last
       authentication; this is when they were last actually in the portal. */
    body.appendChild(rowEl('Last seen', fmtDT(u.last_seen_at)));
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
  /* Credentials an admin can read out or paste into a message.
     Shown as a dialog that STAYS until dismissed — this used to be a toast that
     vanished after six seconds, which is unusable for something you must copy.
     Offered regardless of whether the email "sent": email_sent only means the mailer
     accepted it, and suppression happens afterwards in the listener. */
  function showSignInDetails(u, tempPassword, emailSent) {
    var esc = function (v) {
      return String(v == null ? '' : v)
        .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
    };
    var portal = 'https://app.kiddietrac.com';
    var who = u.username ? (u.email + '  (username: ' + u.username + ')') : (u.email || '');
    var pw = tempPassword || '(not returned)';
    var body = Dom.el('div', {});
    body.innerHTML =
        '<div style="font-size:13px;color:#0F172A;line-height:1.8;">'
      + '<div><span style="color:#64748B;">Portal</span><br><strong>' + portal + '</strong></div>'
      + '<div style="margin-top:8px;"><span style="color:#64748B;">Sign in as</span><br><strong>' + esc(who) + '</strong></div>'
      + '<div style="margin-top:8px;"><span style="color:#64748B;">Temporary password</span><br>'
      + '<strong style="font-family:ui-monospace,Menlo,monospace;font-size:16px;letter-spacing:.5px;">'
      + esc(pw) + '</strong></div></div>'
      + '<div style="font-size:12px;color:#475569;margin-top:12px;padding-top:10px;border-top:1px solid #E2E8F0;">'
      + (emailSent
          ? 'The welcome email was accepted for delivery. Check the email log to confirm it was not suppressed.'
          : 'The email did not send. Share these details directly.')
      + '</div>';
    var copy = Dom.el('button', { class: 'kt-btn kt-btn-secondary kt-btn-sm',
      'data-kt-iconized': '1', style: 'margin-top:12px;' }, 'Copy details');
    copy.onclick = function () {
      var NL = String.fromCharCode(10);
      var text = 'KiddieTrac sign-in' + NL + portal + NL + 'Sign in as: ' + who
        + NL + 'Temporary password: ' + pw;
      try {
        navigator.clipboard.writeText(text);
        copy.textContent = 'Copied';
        setTimeout(function () { copy.textContent = 'Copy details'; }, 2000);
      } catch (e) { copy.textContent = 'Select and copy manually'; }
    };
    body.appendChild(copy);
    Shell.Modal.open({ title: 'Sign-in details for ' + (u.name || u.email || 'this user'), body: body, actions: [] });
  }

  async function rowManualSignIn(u) {
    if (!await _confirm('Generate a temporary password for ' + (u.name || u.email)
        + '? Their current password will stop working.')) { return; }
    try {
      var r = await Api.post('/admin/users/' + u.id + '/resend-welcome', {});
      showSignInDetails(u, r && r.temp_password, !!(r && r.email_sent));
    } catch (e) {
      _toast('⚠️', 'Could not generate a link', (e && e.message) || 'error', '#DC2626');
    }
  }

  /* The prompt has to say what will ACTUALLY happen, which now depends on whether
     the account was ever claimed. It used to promise a new temporary password for
     everyone — and for someone already signed in that meant their own password
     stopped working, silently, at the moment an admin tried to help them. */
  function _claimed(u) { return ['invited', 'not_invited'].indexOf(String(u.status)) === -1; }

  async function rowResendWelcome(u) {
    var who = u.email || u.name;
    var msg = _claimed(u)
      ? (who + ' has already signed in, so this sends a password-reset link. '
         + 'Their current password keeps working until they choose a new one. Continue?')
      : ('Resend the welcome invite to ' + who + '? A new temporary password will be generated.');
    if (!await _confirm(msg)) return;
    try {
      var r = await Api.post('/admin/users/' + u.id + '/resend-welcome', {});
      // Show what the server actually did — the two modes are different enough
      // that a generic "sent" would leave the admin guessing.
      var ok = r && r.email_sent;
      _toast(ok ? '✅' : '⚠️',
        ok ? (r.mode === 'reset_link' ? 'Reset link sent' : 'Welcome email sent') : 'Email failed',
        (r && r.message) || (u.email || ''),
        ok ? '#16A34A' : '#B45309');
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
    /* One bar for everything. The record was a single long scroll — a pay rate sat
       below a full punch history — so the heavy sections get their own panes here
       rather than a second strip nested inside Details. */
    const paneStaff = Dom.el('div', { style: 'display:none;' });
    const paneChecks = Dom.el('div', { style: 'display:none;' });
    const paneClock = Dom.el('div', { style: 'display:none;' });
    const paneData = Dom.el('div', { style: 'display:none;' });

    // Marked so the button normaliser below leaves the tabs alone — without this it
    // applied min-height + inline-flex to them and they grew to 65px.
    try { tabBar.setAttribute('data-kt-tabbar', '1'); } catch (e) {}
    const _detailsTab = mkTab('Details', paneDetails);
    mkTab('📎 Files & documents', paneFiles);
    mkTab('💵 Pay & rooms', paneStaff);
    mkTab('🛡️ Background checks', paneChecks);
    mkTab('🕓 Clock in / out', paneClock);
    mkTab('🗄️ Data & retention', paneData);
    root.appendChild(tabBar);
    root.appendChild(paneDetails);
    root.appendChild(paneFiles);
    root.appendChild(paneStaff);
    root.appendChild(paneChecks);
    root.appendChild(paneClock);
    root.appendChild(paneData);
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
      glanceGrid.appendChild(glanceItem('Status', statusBadge(u)));
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

      // ── Data & retention ────────────────────────────────────────────────
      // What is held about this person and when it is destroyed. This is the view
      // to open when someone asks "what do you have about me?" — previously that
      // answer existed only as an API nothing called.
      (function () {
        var uid = user.id;
        var card = Dom.el('div', { style: 'margin-bottom:18px;padding:14px 16px;background:#F8FAFC;border-radius:10px;border:1px solid #E5E7EB;' });
        card.appendChild(Dom.el('div', { style: 'font-size:11px;font-weight:800;color:#6B7280;letter-spacing:1px;text-transform:uppercase;margin-bottom:2px;' }, '🗄️ Data & retention'));
        card.appendChild(Dom.el('div', { style: 'font-size:12px;color:#64748B;margin-bottom:10px;' },
          'Everything held about this person, why it is kept, and when it is destroyed.'));
        var mapBody = Dom.el('div', { style: 'font-size:13px;color:#64748B;' }, 'Loading…');
        card.appendChild(mapBody);
        paneData.appendChild(card);

        Api.get('/admin/users/' + uid + '/data-map').then(function (d) {
          Dom.clear(mapBody);
          var cats = (d && d.categories) || [];
          if (!cats.length) {
            mapBody.appendChild(Dom.el('div', {}, 'Nothing recorded against this person yet.'));
            return;
          }
          var tbl = Dom.el('table', { 'data-kt-filtered': '1', style: 'width:100%;border-collapse:collapse;font-size:13px;' });
          cats.forEach(function (c) {
            var tr = Dom.el('tr', {});
            var left = Dom.el('td', { style: 'padding:8px 12px 8px 0;vertical-align:top;' });
            left.appendChild(Dom.el('div', { style: 'font-weight:700;color:#0F172A;' },
              c.label + ' · ' + c.count));
            left.appendChild(Dom.el('div', { style: 'color:#64748B;font-size:12px;line-height:1.5;margin-top:2px;' }, c.basis || ''));
            if (c.where) left.appendChild(Dom.el('div', { style: 'color:#94A3B8;font-size:11px;margin-top:2px;' }, c.where));
            tr.appendChild(left);
            var right = Dom.el('td', { style: 'padding:8px 0;vertical-align:top;text-align:right;white-space:nowrap;' });
            if (c.destroy_on) {
              right.appendChild(Dom.el('div', { style: 'font-weight:700;color:#B45309;font-size:12.5px;' }, 'Destroy ' + c.destroy_on));
              if (c.auto_purged) right.appendChild(Dom.el('div', { style: 'color:#16A34A;font-size:11px;margin-top:2px;' }, 'automatic'));
            } else {
              // No date is a real answer, not a gap: the agency has not set a period
              // for this category and the system will not guess one.
              right.appendChild(Dom.el('div', { style: 'color:#94A3B8;font-size:11.5px;' }, 'no date set'));
            }
            tr.appendChild(right);
            tbl.appendChild(tr);
          });
          mapBody.appendChild(tbl);
          if (d.note) {
            mapBody.appendChild(Dom.el('div', { style: 'margin-top:10px;font-size:11.5px;color:#94A3B8;line-height:1.5;' }, d.note));
          }
          var p = (d && d.policy) || {};
          mapBody.appendChild(Dom.el('div', { style: 'margin-top:8px;font-size:11.5px;font-weight:700;color:' + (p.purge_enabled ? '#16A34A' : '#B45309') + ';' },
            p.purge_enabled ? 'Automatic purge is ON for this agency.' : 'Automatic purge is OFF — nothing is deleted automatically.'));
        }).catch(function (e) {
          Dom.clear(mapBody);
          mapBody.appendChild(Dom.el('div', { style: 'color:#B45309;' },
            'Could not load the data map' + (e && e.message ? ' — ' + e.message : '') + '.'));
        });
      })();

      // This user's background-check records (managed on the Background checks screen).
      (function () {
        var bc = Dom.el('div', { style: 'margin-bottom:18px;padding:14px 16px;background:#F9FAFB;border-radius:10px;border:1px solid #E5E7EB;' });
        bc.appendChild(Dom.el('div', { style: 'font-size:11px;font-weight:800;color:#6B7280;letter-spacing:1px;text-transform:uppercase;margin-bottom:10px;' }, '🛡️ Background checks'));
        var bcBody = Dom.el('div', { style: 'font-size:13px;color:#64748B;' }, 'Loading…');
        bc.appendChild(bcBody); paneChecks.appendChild(bc);
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
      paneStaff.appendChild(sec);
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
      paneStaff.appendChild(roomSection);

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
      paneClock.appendChild(clockSection);


      // Named so the editor below can re-run it after a correction; it was an
      // anonymous one-shot call.
      function loadPunches() {
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
          const right = Dom.el('div', { style: 'display:flex;align-items:center;gap:10px;' });
          right.appendChild(Dom.el('div', {
            style: 'font-weight:800;color:' + (open ? '#B45309' : '#0E7C90') + ';',
          }, open ? 'open' : (p.hours + 'h')));

          // Correcting a punch had no control anywhere, which is why some have been
          // open for a month. Compact, and only opens on request — this edits payroll.
          const editBtn = Dom.el('button', {
            style: 'background:#F1F5F9;border:1px solid #E2E8F0;border-radius:8px;height:28px;padding:0 10px;'
                 + 'font-size:12px;font-weight:700;color:#475569;cursor:pointer;',
          }, 'Edit');
          right.appendChild(editBtn);
          row.appendChild(right);
          list.appendChild(row);

          const editor = Dom.el('div', { style: 'display:none;padding:10px 0 14px;border-bottom:1px solid #F3F4F6;' });
          list.appendChild(editor);

          editBtn.addEventListener('click', function () {
            if (editor.style.display === 'block') { editor.style.display = 'none'; editBtn.textContent = 'Edit'; return; }
            editor.style.display = 'block';
            editBtn.textContent = 'Cancel';
            Dom.clear(editor);

            const mkIn = function (label, value) {
              const wrap = Dom.el('div', { style: 'display:flex;flex-direction:column;gap:3px;' });
              wrap.appendChild(Dom.el('label', { style: 'font-size:11px;font-weight:700;color:#64748B;' }, label));
              const i = Dom.el('input', { type: 'datetime-local', value: value || '',
                style: 'height:30px;padding:0 8px;border:1px solid #E2E8F0;border-radius:8px;font-size:12.5px;' });
              wrap.appendChild(i);
              return { wrap: wrap, input: i };
            };
            const fIn = mkIn('Clock in', p.in_local);
            const fOut = mkIn('Clock out', p.out_local);
            const fWhy = Dom.el('input', { type: 'text', placeholder: 'Reason (optional)',
              style: 'height:30px;padding:0 8px;border:1px solid #E2E8F0;border-radius:8px;font-size:12.5px;flex:1;min-width:150px;' });
            const save = Dom.el('button', {
              style: 'height:30px;padding:0 14px;background:#1F6080;color:#fff;border:none;border-radius:8px;'
                   + 'font-size:12.5px;font-weight:800;cursor:pointer;',
            }, 'Save');
            const msg = Dom.el('div', { style: 'font-size:12px;color:#B45309;margin-top:6px;' });

            const bar = Dom.el('div', { style: 'display:flex;gap:10px;align-items:flex-end;flex-wrap:wrap;' });
            bar.appendChild(fIn.wrap); bar.appendChild(fOut.wrap);
            bar.appendChild(Dom.el('div', { style: 'display:flex;flex-direction:column;gap:3px;flex:1;min-width:150px;' }, [
              Dom.el('label', { style: 'font-size:11px;font-weight:700;color:#64748B;' }, 'Reason'), fWhy,
            ]));
            bar.appendChild(save);
            editor.appendChild(bar);
            editor.appendChild(Dom.el('div', { style: 'font-size:11px;color:#94A3B8;margin-top:6px;' },
              'Times are in the centre\'s timezone. The change is recorded against your name.'));
            editor.appendChild(msg);

            save.addEventListener('click', async function () {
              msg.style.color = '#64748B'; msg.textContent = 'Saving…';
              save.disabled = true;
              try {
                const r = await Api.patch('/admin/users/' + user.id + '/punches/' + p.id, {
                  punched_in_at: fIn.input.value || null,
                  punched_out_at: fOut.input.value || null,
                  reason: fWhy.value.trim() || null,
                });
                msg.style.color = '#0E7C90';
                msg.textContent = 'Saved — ' + (r.punch && r.punch.hours !== null ? r.punch.hours + ' hours' : 'still open') + '.';
                if (window.KT && KT.Dom && KT.Dom.toast) KT.Dom.toast('Time punch corrected', 'success');
                loadPunches();
              } catch (e) {
                msg.style.color = '#DC2626';
                msg.textContent = (e && e.message) || 'Could not save.';
                save.disabled = false;
              }
            });
          });
        });
        clockBody.appendChild(list);
      }).catch(function () {
        Dom.clear(clockBody);
        clockBody.appendChild(Dom.el('div', { style: 'color:#64748B;font-size:13px;' }, 'Could not load clock records.'));
      });
      }
      loadPunches();
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

    const actionRow = Dom.el('div', { style: 'display:flex;gap:8px;flex-wrap:wrap;align-items:stretch;' });
    /* Every button in this row was styled by hand — different padding, border widths
       and font sizes — so they sat at different heights and never lined up. One pass
       over the row's own children normalises them, and keeps doing so for any button
       added here later. Colours are left alone: only the box is standardised. */
    actionRow.ktNormalise = function () {
      Array.prototype.forEach.call(actionRow.querySelectorAll('button'), function (b) {
        b.style.padding = '8px 14px';
        b.style.borderRadius = '8px';
        b.style.borderWidth = '1.5px';
        b.style.borderStyle = 'solid';
        b.style.fontSize = '13px';
        b.style.fontWeight = '700';
        b.style.lineHeight = '1.2';
        b.style.minHeight = '38px';
        b.style.display = 'inline-flex';
        b.style.alignItems = 'center';
        b.style.justifyContent = 'center';
        b.style.whiteSpace = 'nowrap';
        b.style.cursor = 'pointer';
      });
    };

    const resetBtn = Dom.el('button', { class: 'kt-btn kt-btn-secondary' }, '🔑 Reset password');
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

    /* Hand over credentials directly when email cannot be relied on. email_sent only
       means the mailer accepted the message — suppression happens later in the
       listener, so a "sent" invite can still never arrive. This path does not depend
       on delivery at all. */
    const linkBtn = Dom.el('button', { class: 'kt-btn kt-btn-secondary', 'data-kt-iconized': '1' }, 'Manual sign-in link');
    linkBtn.addEventListener('click', async () => {
      if (!await KT.confirm('Generate a temporary password for ' + user.name + '? Their current password will stop working.')) { return; }
      linkBtn.disabled = true;
      const restore = 'Manual sign-in link';
      linkBtn.textContent = 'Generating...';
      try {
        const r = await Api.post('/admin/users/' + user.id + '/resend-welcome', {});
        const portal = 'https://app.kiddietrac.com';
        const who = user.username ? (user.email + '  (username: ' + user.username + ')') : user.email;
        const pw = r.temp_password || '(not returned)';
        // Local — this file has no shared escaper, and both values below are
        // interpolated into innerHTML.
        const esc = function (v) {
          return String(v == null ? '' : v)
            .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
        };
        const box = Dom.el('div', { style:
          'margin-top:12px;padding:14px 16px;background:#F0F9FF;border:1px solid #BAE6FD;border-radius:10px;' });
        box.innerHTML =
            '<div style="font-size:11px;font-weight:800;color:#0C4A6E;letter-spacing:.06em;margin-bottom:8px;">SIGN-IN DETAILS</div>'
          + '<div style="font-size:13px;color:#0F172A;line-height:1.8;">'
          + '<div><span style="color:#64748B;">Portal</span><br><strong>' + portal + '</strong></div>'
          + '<div style="margin-top:8px;"><span style="color:#64748B;">Sign in as</span><br><strong>' + esc(who) + '</strong></div>'
          + '<div style="margin-top:8px;"><span style="color:#64748B;">Temporary password</span><br>'
          + '<strong style="font-family:ui-monospace,Menlo,monospace;font-size:15px;letter-spacing:.5px;">'
          + esc(pw) + '</strong></div></div>'
          + '<div style="font-size:12px;color:#475569;margin-top:10px;">'
          + (r.email_sent
              ? 'The welcome email was accepted for delivery. Check the email log to confirm it was not suppressed.'
              : 'The email did not send. Share these details directly.')
          + '</div>';
        const copy = Dom.el('button', { class: 'kt-btn kt-btn-secondary kt-btn-sm',
          'data-kt-iconized': '1', style: 'margin-top:10px;' }, 'Copy details');
        copy.onclick = function () {
          const text = 'KiddieTrac sign-in' + String.fromCharCode(10) + portal
            + String.fromCharCode(10) + 'Sign in as: ' + who
            + String.fromCharCode(10) + 'Temporary password: ' + pw;
          try {
            navigator.clipboard.writeText(text);
            copy.textContent = 'Copied';
            setTimeout(function () { copy.textContent = 'Copy details'; }, 2000);
          } catch (e) { copy.textContent = 'Select and copy manually'; }
        };
        box.appendChild(copy);
        const prev = actionRow.parentElement.querySelector('[data-kt-signin-box]');
        if (prev) { prev.remove(); }
        box.setAttribute('data-kt-signin-box', '1');
        actionRow.parentElement.appendChild(box);
      } catch (e) {
        status.style.color = '#DC2626';
        status.textContent = 'Could not generate a link: ' + ((e && e.message) || 'error');
      } finally {
        linkBtn.disabled = false;
        linkBtn.textContent = restore;
      }
    });
    actionRow.appendChild(linkBtn);

    // v22p3.5: reopen onboarding wizard
    const reopenBtn = Dom.el('button', { class: 'kt-btn kt-btn-secondary' }, '🪄 Reopen onboarding');
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

    const resendBtn = Dom.el('button', { class: 'kt-btn kt-btn-secondary' }, '✉ Resend welcome');
    resendBtn.addEventListener('click', async () => {
      var _alreadyIn = ['invited', 'not_invited'].indexOf(String(user.status)) === -1;
      if (!await KT.confirm(_alreadyIn
        ? (user.email + ' has already signed in, so this sends a password-reset link — '
           + 'their current password keeps working until they choose a new one. Continue?')
        : ('Resend the welcome invite to ' + user.email + '? A new temporary password will be generated.'))) return;
      resendBtn.disabled = true; resendBtn.textContent = 'Sending...';
      try {
        const r = await Api.post('/admin/users/' + user.id + '/resend-welcome', {});
        status.style.color = '#16A34A';
        status.textContent = r.email_sent
          ? ('✓ ' + (r.message || 'Sent.'))
          : (r.temp_password
              ? ('Email failed — share temp password manually: ' + r.temp_password)
              : ('Email failed — ' + (r.message || 'nothing was changed.')));
      } catch (e) {
        status.style.color = '#DC2626';
        status.textContent = 'Resend failed: ' + (e.message || 'error');
      } finally {
        resendBtn.disabled = false; resendBtn.textContent = '✉ Resend welcome';
      }
    });
    actionRow.appendChild(resendBtn);

    /* Off-boarding proper, sitting before the blunt Delete. Closing the account is
       only part of somebody leaving — the rooms have to go to a person, and the shift
       they never clocked out of has to be closed. (Anthony, 2026-08-26)

       STAFF ONLY. A parent is not off-boarded, they are DE-ENROLLED with their family:
       the unit that leaves is the family, and that flow handles the children, the
       balance and the goodbye email. Offering "off-board" on a guardian would be a
       second, wrong route out of the same situation. */
    const STAFF_ROLES_FOR_OFFBOARD = ['educator', 'centre_director', 'agency_admin',
                                      'home_visitor', 'auditor', 'sales_rep', 'platform_admin'];
    const _userRoles = user.roles || (user.role ? [user.role] : []);
    const _isStaff = _userRoles.some(function (r) { return STAFF_ROLES_FOR_OFFBOARD.indexOf(r) !== -1; });
    if (_isStaff) {
      const offboardBtn = Dom.el('button', { class: 'kt-btn' }, '👋 Off-board…');
      offboardBtn.addEventListener('click', function () { openStaffOffboard(user, content); });
      actionRow.appendChild(offboardBtn);
    }

    const deleteBtn = Dom.el('button', { class: 'kt-btn kt-btn-danger' }, '🗑 Delete user');
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
    try { actionRow.ktNormalise(); } catch (e) {}

    /* One box for every secondary button in this record. Measured live, they ran
       27/31/35/38/45px tall with fonts from 11 to 15px — each row consistent with
       itself and with nothing else, which is what looked out of place. The primary
       CTA is skipped on purpose: it should stand out. Runs after the record is
       built, and again shortly after, so buttons added by a late fetch are caught. */
    (function () {
      function tidy() {
        var root = document.querySelector('.kt-modal, [role="dialog"]');
        if (!root) { return; }
        Array.prototype.forEach.call(root.querySelectorAll('button'), function (b) {
          var label = (b.textContent || '').trim();
          if (/save changes/i.test(label)) { return; }        // the primary CTA
          if (!label || label.length > 34) { return; }         // icon-only / prose
          if (b.closest('.kt-urec-tabs, [data-kt-tabbar]')) {
            // Belt and braces: strip anything a previous pass may have set on a tab.
            b.style.minHeight = ''; b.style.display = ''; b.style.alignItems = '';
            b.style.justifyContent = ''; b.style.borderRadius = '';
            return;
          }
          var r = b.getBoundingClientRect();
          if (!r.height) { return; }                           // hidden pane
          if (b.classList.contains('kt-btn')) { return; }   // house class owns its own box
          b.style.minHeight = '36px';
          b.style.padding = '8px 14px';
          b.style.fontSize = '13px';
          b.style.lineHeight = '1.2';
          b.style.borderRadius = '8px';
          b.style.display = 'inline-flex';
          b.style.alignItems = 'center';
          b.style.justifyContent = 'center';
        });
      }
      try { tidy(); } catch (e) {}
      [400, 1200].forEach(function (d) { setTimeout(function () { try { tidy(); } catch (e) {} }, d); });
    })();

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
  /**
   * ARCHIVED \u2014 everyone who has left, and the retention clock on each record.
   *
   * De-enrolling is deliberately not a delete: licensed child care records have to
   * outlive the family that generated them. Until now nothing in the portal could see
   * one, which meant "it's gone" and "it's retained but invisible" looked identical
   * from the outside \u2014 the worst possible answer to a parent asking whether their
   * child's file still exists. (2026-08-25)
   */
  /* One fetch, reused by every section's Archived view. */
  var _archCache = null;
  async function archivedData(force) {
    if (!force && _archCache) return _archCache;
    _archCache = await Api.get('/admin/archived');
    return _archCache;
  }

  /**
   * The Active / Archived switch that every section carries.
   *
   * Anthony (2026-08-25): archived records belong INSIDE the section they left, not in
   * one global graveyard screen \u2014 somebody looking for a departed family looks in
   * Families. The count is filled in after paint so a section never waits on it.
   */
  function archiveSwitch(content, key, renderActive, showing) {
    var wrap = Dom.el('div', { style: 'display:inline-flex;gap:0;border:1px solid var(--ink-200,#E5E7EB);border-radius:9px;overflow:hidden;margin-bottom:14px;' });
    function seg(label, on, go) {
      var b = Dom.el('button', {
        type: 'button',
        style: 'padding:7px 14px;border:0;cursor:pointer;font-size:13px;font-weight:700;font-family:inherit;'
          + (on ? 'background:var(--brand-blue,#1F6080);color:#fff;' : 'background:#fff;color:var(--ink-600,#475569);'),
      }, label);
      if (!on) b.addEventListener('click', go);
      return b;
    }
    wrap.appendChild(seg('Active', showing !== 'archived', function () { renderActive(content); }));
    var archBtn = seg('Archived', showing === 'archived', function () { renderArchivedInto(content, key, renderActive); });
    wrap.appendChild(archBtn);
    // Fill the count in afterwards; the section must not block on it.
    archivedData().then(function (d) {
      var n = (d.counts || {})[key];
      if (typeof n === 'number') archBtn.textContent = 'Archived (' + n + ')';
    }).catch(function () { /* no count is fine; the tab still works */ });
    return wrap;
  }

  async function renderArchivedInto(content, key, renderActive) {
    Dom.clear(content);
    content.appendChild(archiveSwitch(content, key, renderActive, 'archived'));
    var body = Dom.el('div', {});
    content.appendChild(body);
    body.appendChild(Dom.el('div', { style: 'padding:24px;color:var(--ink-500);font-size:14px;' }, 'Loading\u2026'));

    var d;
    try { d = await archivedData(true); }
    catch (e) {
      Dom.clear(body);
      body.appendChild(Dom.el('div', { style: 'padding:20px;color:#B91C1C;font-size:14px;' },
        'Could not load archived records: ' + (e.message || 'error')));
      return;
    }

    Dom.clear(body);
    var ret = d.retention || {};
    var counts = d.counts || {};

    // The retention promise, stated in the agency's own numbers.
    var banner = Dom.el('div', {
      style: 'background:#F0F9FF;border:1px solid #BAE6FD;border-radius:12px;padding:14px 16px;margin-bottom:18px;font-size:13.5px;color:#075985;line-height:1.6;',
    });
    banner.appendChild(Dom.el('div', { style: 'font-weight:800;margin-bottom:4px;' }, '\ud83d\udd12 Records are kept, not deleted'));
    banner.appendChild(Dom.el('div', {},
      'Child and family records are retained for ' + (ret.child_record_years || 7) + ' years after a child leaves; '
      + 'staff and document records for ' + (ret.document_years || 7) + ' years. '
      + (ret.auto_enforce
          ? ('Automatic clean-up is ON (' + (ret.enforce_mode || 'anonymise') + ') once a record passes its date.')
          : 'Automatic clean-up is OFF, so nothing is removed until you turn it on in Settings \u2192 Data retention.')));
    body.appendChild(banner);

    var GROUPS = [
      { key: 'families', label: '\ud83d\udc6a Families', cols: ['Family', 'Provider', 'Children', 'Left', 'Kept until'] },
      { key: 'children', label: '\ud83d\udc76 Children', cols: ['Child', 'Family', 'Provider', 'State', 'Left', 'Kept until'] },
      { key: 'staff', label: '\ud83d\udc64 Staff & educators', cols: ['Name', 'Role', 'State', 'Left', 'Kept until'] },
      { key: 'centres', label: '\ud83c\udfeb Providers', cols: ['Provider', 'Archived', 'Kept until'] },
    ];

    // This section shows ITS OWN group only — no cross-section chips.
    var active = GROUPS.find(function (g) { return g.key === key; }) || GROUPS[0];
    var pane = Dom.el('div', {});

    /* Full date INCLUDING the year, plus the time when there is one (Anthony,
       2026-08-25). Two different kinds of value arrive here and must not be treated
       the same:
         - deleted_at is a TIMESTAMP -> an instant, rendered in the AGENCY timezone.
         - withdrawn_at / retained_until are DATE-ONLY -> a calendar day. Every form
           of new Date('2026-08-11') parses as UTC midnight, so converting it lands on
           the day before in any western zone. KT.dayLabel formats from the string
           parts, so nothing shifts. */
    function _archStamp(v) {
      if (!v) return '—';
      var str = String(v).trim();
      var dateOnly = /^[0-9]{4}-[0-9]{2}-[0-9]{2}$/.test(str);
      try {
        if (dateOnly) {
          return (window.KT && KT.dayLabel) ? KT.dayLabel(str) : str;
        }
        if (window.KT && KT.fmtDate && KT.fmtTime) {
          var d = KT.fmtDate(str), t = KT.fmtTime(str);
          return t ? (d + ', ' + t) : d;
        }
      } catch (e) {}
      return str.replace('T', ' ').slice(0, 16);
    }
    var fmtDate = _archStamp;

    function cell(text, extra) {
      return Dom.el('td', { style: 'padding:11px 14px;font-size:13px;color:var(--ink-700,#334155);' + (extra || '') }, text);
    }

    function paint() {
      Dom.clear(pane);
      var rows = d[active.key] || [];
      if (!rows.length) {
        pane.appendChild(Dom.el('div', {
          style: 'padding:26px;text-align:center;color:var(--ink-500,#64748B);font-size:14px;background:#fff;border:1px solid #EEF0F3;border-radius:12px;',
        }, 'Nobody in this group has left.'));
        return;
      }

      var table = Dom.el('table', { style: 'width:100%;border-collapse:collapse;background:#fff;border:1px solid #EEF0F3;border-radius:12px;overflow:hidden;' });
      var thead = Dom.el('thead');
      var hr = Dom.el('tr', { style: 'background:var(--ink-50,#F8FAFC);' });
      active.cols.forEach(function (c) {
        hr.appendChild(Dom.el('th', {
          style: 'text-align:left;padding:11px 14px;font-size:11px;font-weight:700;color:#6B7280;text-transform:uppercase;letter-spacing:0.5px;',
        }, c));
      });
      thead.appendChild(hr); table.appendChild(thead);

      /* An archived record you cannot open is barely a record. Admins and directors
         keep full read access for the whole retention period. (Anthony, 2026-08-25) */
      function archivedOpen(g, r) {
        if (g === 'families') return function () { showFamilyDetail(r.id, true); };
        if (g === 'children') return function () { window.location.hash = 'child-detail?id=' + r.id + '&archived=1'; };
        if (g === 'staff') return function () {
          if (typeof showUserModal === 'function') showUserModal({ id: r.id, first_name: (r.name || '').split(' ')[0], last_name: (r.name || '').split(' ').slice(1).join(' '), email: r.email, status: r.state === 'removed' ? 'deactivated' : 'deactivated' }, null, document.getElementById('appMain'));
          else window.location.hash = 'admin-users';
        };
        return null;
      }

      var tb = Dom.el('tbody');
      rows.forEach(function (r) {
        var tr = Dom.el('tr', { style: 'border-top:1px solid #E5E7EB;' });
        var _go = archivedOpen(active.key, r);
        if (_go) {
          tr.style.cursor = 'pointer';
          tr.title = 'Open this archived record';
          tr.addEventListener('click', _go);
        }
        if (active.key === 'families') {
          tr.appendChild(cell(r.name || '\u2014', 'font-weight:600;'));
          tr.appendChild(cell(r.centre_name || '\u2014'));
          tr.appendChild(cell(String(r.children == null ? '\u2014' : r.children)));
        } else if (active.key === 'children') {
          tr.appendChild(cell(r.name || '\u2014', 'font-weight:600;'));
          tr.appendChild(cell(r.family_name || '\u2014'));
          tr.appendChild(cell(r.centre_name || '\u2014'));
          tr.appendChild(cell(r.state === 'removed' ? 'Removed' : 'Withdrawn',
            r.state === 'removed' ? 'color:#B91C1C;font-weight:600;' : ''));
        } else if (active.key === 'staff') {
          var nm = Dom.el('td', { style: 'padding:11px 14px;font-size:13px;' });
          nm.appendChild(Dom.el('div', { style: 'font-weight:600;color:var(--ink-900,#0F172A);' }, r.name || '\u2014'));
          if (r.email) nm.appendChild(Dom.el('div', { style: 'font-size:11.5px;color:#94A3B8;' }, r.email));
          tr.appendChild(nm);
          tr.appendChild(cell((r.role || '').replace(/_/g, ' ')));
          tr.appendChild(cell(r.state === 'removed' ? 'Removed' : 'Closed',
            r.state === 'removed' ? 'color:#B91C1C;font-weight:600;' : ''));
        } else {
          tr.appendChild(cell(r.name || '\u2014', 'font-weight:600;'));
        }

        // "Left" — flagged when the date is inferred rather than recorded.
        var left = Dom.el('td', { style: 'padding:11px 14px;font-size:13px;color:var(--ink-700,#334155);white-space:nowrap;' });
        left.appendChild(Dom.el('span', {}, fmtDate(r.departed_at)));
        if (r.departed_at_is_estimate) {
          left.appendChild(Dom.el('span', {
            title: 'No exact closure date was recorded, so this is the last time the account changed.',
            style: 'margin-left:6px;font-size:10.5px;font-weight:700;color:#B45309;background:#FEF3C7;border-radius:5px;padding:1px 5px;',
          }, 'EST.'));
        }
        tr.appendChild(left);

        tr.appendChild(cell(fmtDate(r.retained_until), 'white-space:nowrap;font-weight:600;'));
        tb.appendChild(tr);
      });
      table.appendChild(tb);

      var scroll = Dom.el('div', { style: 'overflow-x:auto;' });
      scroll.appendChild(table);
      pane.appendChild(scroll);
      pane.appendChild(Dom.el('div', { style: 'margin-top:10px;font-size:12px;color:var(--ink-500,#64748B);' },
        rows.length + ' record' + (rows.length === 1 ? '' : 's')));
    }

    body.appendChild(pane);
    paint();
  }

  // Shared with the other section screens (children live in their own file).
  KT.Archive = { renderInto: renderArchivedInto, switchEl: archiveSwitch, data: archivedData };

  /**
   * Off-board a member of staff: hand over their rooms, tidy what they left open, then
   * close the account.
   *
   * Deliberately not one click. `DELETE /admin/users/{id}` already closed accounts
   * cleanly, but it left the rooms with nobody on them and any forgotten clock-in still
   * open — measured: 2 of 7 already-closed staff still carried one, quietly distorting
   * hours and ratio history. Preview first, then confirm.
   */
  async function openStaffOffboard(user, content) {
    const body = Dom.el('div', {});
    body.appendChild(Dom.el('div', { style: 'padding:14px;color:var(--ink-500);font-size:13px;' }, 'Loading the plan…'));

    let plan = null;
    const reassign = Dom.el('select', { style: 'padding:9px 12px;border:1px solid var(--ink-300);border-radius:8px;font-size:14px;font-family:inherit;min-width:220px;' });
    const closePunches = Dom.el('input', { type: 'checkbox', style: 'width:17px;height:17px;' });
    const moveTasks = Dom.el('input', { type: 'checkbox', style: 'width:17px;height:17px;' });
    const cancelShifts = Dom.el('input', { type: 'checkbox', style: 'width:17px;height:17px;' });
    const sendNotice = Dom.el('input', { type: 'checkbox', style: 'width:17px;height:17px;' });
    const lastDay = Dom.el('input', { type: 'date', style: 'padding:9px 12px;border:1px solid var(--ink-300);border-radius:8px;font-size:14px;font-family:inherit;' });
    const status = Dom.el('div', { style: 'margin-top:12px;font-size:13px;min-height:20px;line-height:1.5;' });

    async function run(confirm) {
      status.style.color = 'var(--ink-600)';
      status.textContent = confirm ? 'Closing…' : 'Checking…';
      try {
        const res = await Api.post('/admin/users/' + user.id + '/offboard', {
          last_day: lastDay.value || undefined,
          reassign_to: reassign.value ? Number(reassign.value) : undefined,
          close_punches: closePunches.checked,
          move_tasks: moveTasks.checked,
          cancel_shifts: cancelShifts.checked,
          send_notice: sendNotice.checked,
          confirm: !!confirm,
        });
        if (!confirm) {
          status.style.color = 'var(--ink-700)';
          status.textContent = res.will_reassign_rooms + ' room(s) handed over, '
            + res.will_close_punches + ' open shift(s) closed, '
            + res.will_move_tasks + ' task(s) moved, '
            + res.will_cancel_shifts + ' upcoming shift(s) dealt with'
            + (res.will_send_notice ? ', goodbye email sent' : '')
            + (res.unpaid_hours ? ', ' + res.unpaid_hours + 'h unpaid' : '')
            + (res.rooms_left_uncovered ? '. ⚠ ' + res.rooms_left_uncovered + ' room(s) left with nobody' : '')
            + '. Nothing has changed yet.';
          return false;
        }
        const errs = (res.report && res.report.errors) || [];
        if (window.KT && KT.Dom && KT.Dom.toast) {
          KT.Dom.toast(errs.length ? (errs.length + ' problem(s)') : (user.name + ' off-boarded'),
            errs.length ? 'error' : 'success');
        }
        if (errs.length) {
          status.style.color = '#B91C1C';
          status.textContent = errs.map(function (e) { return e.stage + ': ' + e.message; }).join('; ');
          return false;
        }
        await renderUsersTab(content);
        return true;
      } catch (e) {
        status.style.color = '#DC2626';
        status.textContent = (e && e.message) || 'Could not complete this.';
        return false;
      }
    }

    Shell.Modal.open({
      title: 'Off-board ' + (user.name || 'this person'),
      body: body,
      large: true,
      actions: [
        { label: 'Preview', onClick: async () => { await run(false); return false; } },
        {
          label: 'Off-board',
          primary: true,
          onClick: async () => {
            const ok = await KT.confirm({
              title: 'Off-board ' + (user.name || 'this person') + '?',
              description: 'Their sign-in ends immediately and their rooms are released. '
                + 'The record is kept — you will find them under Show deactivated.',
              tone: 'danger',
              okLabel: 'Off-board',
            });
            if (!ok) return false;
            const done = await run(true);
            if (!done) return false;
          },
        },
      ],
    });

    try {
      plan = await Api.get('/admin/users/' + user.id + '/offboard-plan');
    } catch (e) {
      Dom.clear(body);
      body.appendChild(Dom.el('div', { style: 'padding:14px;color:#DC2626;font-size:13px;' },
        'Could not load the plan: ' + (e.message || 'error')));
      return;
    }

    Dom.clear(body);
    const sum = plan.summary || {};

    const head = Dom.el('div', {
      style: 'background:var(--ink-50);border-radius:10px;padding:12px 14px;margin-bottom:14px;font-size:13.5px;color:var(--ink-700);line-height:1.6;',
    });
    head.appendChild(Dom.el('div', {}, (plan.user && plan.user.email) || ''));
    head.appendChild(Dom.el('div', {},
      sum.rooms + ' room(s) · ' + sum.open_punches + ' unclosed shift(s) · '
      + sum.open_tasks + ' open task(s) · ' + (sum.future_shifts || 0) + ' upcoming shift(s) · '
      + sum.documents + ' document(s) on file'));
    if (sum.unpaid_hours) {
      head.appendChild(Dom.el('div', { style: 'color:#075985;font-weight:700;margin-top:4px;' },
        sum.unpaid_hours + ' hours worked since their last payslip'
        + (sum.unpaid_since ? ' (' + String(sum.unpaid_since).slice(0, 10) + ')' : '')));
    }
    if (sum.rooms_they_alone_cover) {
      head.appendChild(Dom.el('div', { style: 'color:#B45309;font-weight:700;margin-top:4px;' },
        '⚠ ' + sum.rooms_they_alone_cover + ' room(s) have no other educator — hand them over.'));
    }
    body.appendChild(head);

    (plan.rooms || []).forEach(function (r) {
      body.appendChild(Dom.el('div', {
        style: 'display:flex;justify-content:space-between;gap:10px;padding:7px 0;border-bottom:1px solid var(--ink-100);font-size:13px;',
      },
        Dom.el('span', {}, r.name + (r.centre_name ? ' · ' + r.centre_name : '')),
        Dom.el('span', { style: r.other_educators ? 'color:var(--ink-500);' : 'color:#B45309;font-weight:700;' },
          r.other_educators ? (r.other_educators + ' other educator(s)') : 'sole cover')));
    });

    const opts = Dom.el('div', { style: 'margin-top:16px;display:flex;flex-direction:column;gap:10px;' });

    reassign.appendChild(Dom.el('option', { value: '' }, 'Nobody — just release the rooms'));
    (plan.candidates || []).forEach(function (c) {
      reassign.appendChild(Dom.el('option', { value: String(c.id) }, c.name + ' (' + c.role + ')'));
    });
    const rRow = Dom.el('label', { style: 'display:flex;align-items:center;gap:10px;font-size:13.5px;color:var(--ink-700);flex-wrap:wrap;' });
    rRow.appendChild(Dom.el('span', { style: 'font-weight:700;' }, 'Hand their rooms to'));
    rRow.appendChild(reassign);
    opts.appendChild(rRow);

    const dRow = Dom.el('label', { style: 'display:flex;align-items:center;gap:10px;font-size:13.5px;color:var(--ink-700);' });
    dRow.appendChild(Dom.el('span', { style: 'font-weight:700;' }, 'Last working day'));
    dRow.appendChild(lastDay);
    opts.appendChild(dRow);

    if (sum.open_punches) {
      const pRow = Dom.el('label', { style: 'display:flex;align-items:flex-start;gap:10px;font-size:13.5px;color:var(--ink-700);cursor:pointer;' });
      closePunches.checked = true;
      pRow.appendChild(closePunches);
      const pt = Dom.el('span', {});
      pt.appendChild(Dom.el('span', { style: 'font-weight:600;' }, 'Close their ' + sum.open_punches + ' unclosed shift(s)'));
      pt.appendChild(Dom.el('span', { style: 'display:block;font-size:12px;color:var(--ink-500);' },
        'Closed 8 hours after the clock-in, not now — stamping an old shift with today’s time would invent hours nobody worked.'));
      pRow.appendChild(pt);
      opts.appendChild(pRow);
    }

    const mkOpt = function (cb, label, hint, on) {
      cb.checked = !!on;
      const l = Dom.el('label', { style: 'display:flex;align-items:flex-start;gap:10px;font-size:13.5px;color:var(--ink-700);cursor:pointer;' });
      l.appendChild(cb);
      const t = Dom.el('span', {});
      t.appendChild(Dom.el('span', { style: 'font-weight:600;' }, label));
      t.appendChild(Dom.el('span', { style: 'display:block;font-size:12px;color:var(--ink-500);' }, hint));
      l.appendChild(t);
      return l;
    };
    if (sum.open_tasks) {
      opts.appendChild(mkOpt(moveTasks, 'Move their ' + sum.open_tasks + ' open task(s)',
        'To whoever takes their rooms — or back to the pool, unassigned, if you pick nobody.', true));
    }
    if (sum.future_shifts) {
      opts.appendChild(mkOpt(cancelShifts, 'Deal with their ' + sum.future_shifts + ' upcoming shift(s)',
        'Reassigned if you picked someone; otherwise cancelled — never silently deleted.', true));
    }
    opts.appendChild(mkOpt(sendNotice, 'Email them a goodbye',
      'Their last day, what happens to their records, and any hours not yet on a payslip. Sent before access ends.', true));

    body.appendChild(opts);
    body.appendChild(status);
  }

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
    content.appendChild(archiveSwitch(content, 'families', renderFamiliesTab, 'active'));

    /* Status is a different question from Active|Archived — that pair asks whether the
       RECORD is live or history, this asks whether the family is currently in care. A
       suspended family used to sit in the list indistinguishable from an enrolled one
       except for a small pill, under a line that read "All enrolled families".
       Defaults to Enrolled; remembered for the session so the list does not reset itself
       every time you come back from a family. (2026-08-27) */
    var FAM_STATUS_KEY = 'kt_fam_status';
    var famStatus = 'enrolled';
    try {
      var _fs = sessionStorage.getItem(FAM_STATUS_KEY);
      if (_fs === '' || _fs === 'enrolled' || _fs === 'suspended') { famStatus = _fs; }
    } catch (e) {}

    var _allFamilies = data.families || [];
    data = Object.assign({}, data, {
      families: _allFamilies.filter(function (f) {
        if (famStatus === 'enrolled') { return !f.suspended; }
        if (famStatus === 'suspended') { return !!f.suspended; }
        return true;
      }),
    });
    var _suspendedCount = _allFamilies.filter(function (f) { return !!f.suspended; }).length;

    // v22p12.1: tab hero
    content.appendChild(tabHero(
      '👪 Families',
      data.families.length + ' famil' + (data.families.length === 1 ? 'y' : 'ies')
        + (famStatus === 'enrolled' ? ' enrolled' : (famStatus === 'suspended' ? ' suspended' : ''))
        + ' across your centres. Click any card to see children, guardians, and balances.',
      'familyGroup'
    ));

    // v22p11: action bar with count on the left + Add button on the right
    const bar = Dom.el('div', { style: 'display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px; gap: 10px; flex-wrap: wrap;' });

    var famStatusSel = Dom.el('select', {
      style: 'padding:6px 10px;border:1px solid #D1D5DB;border-radius:7px;font-size:13px;'
           + 'background:#fff;flex:0 0 auto;',
      title: 'Filter by status',
    });
    [
      ['enrolled',  'Enrolled'],
      ['suspended', 'Suspended' + (_suspendedCount ? ' (' + _suspendedCount + ')' : '')],
      ['',          'All statuses'],
    ].forEach(function (o) {
      var opt = Dom.el('option', { value: o[0] }, o[1]);
      if (famStatus === o[0]) { opt.selected = true; }
      famStatusSel.appendChild(opt);
    });
    famStatusSel.addEventListener('change', function () {
      try { sessionStorage.setItem(FAM_STATUS_KEY, famStatusSel.value); } catch (e) {}
      renderFamiliesTab(content);
    });
    bar.appendChild(famStatusSel);

    bar.appendChild(Dom.el('div', { style: 'color: var(--ink-500); font-size: 13px; flex: 1;' },
      famStatus === 'enrolled' ? 'Families currently in care'
        : (famStatus === 'suspended' ? 'Suspended — access paused, enrolment kept'
                                     : 'Enrolled and suspended families')));

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
      /* Distinguish "you have no families" from "none match this filter" — telling an
         agency with 33 families to add their first one is nonsense, and hides the fact
         that a filter is on. */
      content.appendChild(_allFamilies.length
        ? emptyMsg('Nothing matches the ' + (famStatus === 'suspended' ? 'Suspended' : 'Enrolled')
            + ' filter. Choose All statuses to see the other '
            + _allFamilies.length + '.',
            { title: 'No families with this status', illustration: 'emptyFamilies' })
        : emptyMsg(
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

      // Same answer the table column gives, on the card layout.
      var onbWrap = Dom.el('div', { style: 'margin-top:10px;' });
      onbWrap.appendChild(familyOnboardingBadge(f));
      card.appendChild(onbWrap);

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
  /**
   * Move a whole family to another provider.
   *
   * The destination list comes from the server with live occupancy, so a provider who is
   * full is shown as full and cannot be chosen — rather than letting the admin pick one
   * and meet a 422 after they have already told the family. Their current provider is
   * listed too, greyed, so the starting point is never ambiguous.
   */
  async function openFamilyTransfer(f, content) {
    const body = Dom.el('div', {});
    body.appendChild(Dom.el('p', { style: 'margin:0 0 14px;color:var(--ink-600);font-size:14px;line-height:1.5;' },
      'Everyone in ' + (f.family_name || 'this family') + ' moves together, and their records move with them. '
      + 'Their parents are emailed and the educators at both ends are told.'));

    const listWrap = Dom.el('div', { style: 'max-height:270px;overflow-y:auto;border:1px solid var(--ink-200);border-radius:10px;padding:6px;margin-bottom:14px;' });
    listWrap.appendChild(Dom.el('div', { style: 'padding:14px;color:var(--ink-500);font-size:13px;' }, 'Loading providers\u2026'));
    body.appendChild(listWrap);

    const dateRow = Dom.el('div', { style: 'display:flex;align-items:center;gap:10px;margin-bottom:10px;' });
    dateRow.appendChild(Dom.el('label', { style: 'font-size:13px;font-weight:700;color:var(--ink-600);' }, 'Effective'));
    const today = new Date();
    const iso = today.getFullYear() + '-' + String(today.getMonth() + 1).padStart(2, '0') + '-' + String(today.getDate()).padStart(2, '0');
    const dateInput = Dom.el('input', { type: 'date', value: iso,
      style: 'padding:9px 12px;border:1px solid var(--ink-300);border-radius:8px;font-size:14px;font-family:inherit;' });
    dateRow.appendChild(dateInput);
    body.appendChild(dateRow);

    const reason = Dom.el('input', { type: 'text', placeholder: 'Reason (optional) \u2014 included in the letter to parents',
      style: 'width:100%;box-sizing:border-box;padding:10px 12px;border:1px solid var(--ink-300);border-radius:8px;font-size:14px;font-family:inherit;' });
    body.appendChild(reason);

    const status = Dom.el('div', { style: 'margin-top:10px;font-size:13px;min-height:18px;' });
    body.appendChild(status);

    let chosenRoom = null;
    Shell.Modal.open({
      title: 'Move ' + (f.family_name || 'family') + ' to another provider',
      body: body,
      actions: [
        {
          label: 'Move family',
          primary: true,
          onClick: async () => {
            if (!chosenRoom) { status.style.color = '#B45309'; status.textContent = 'Pick a provider first.'; return false; }
            status.style.color = 'var(--ink-600)'; status.textContent = 'Moving\u2026';
            try {
              const res = await Api.post('/admin/families/' + f.id + '/transfer', {
                to_room_id: chosenRoom,
                effective_date: dateInput.value,
                reason: reason.value.trim() || null,
              });
              if (window.KT && KT.Dom && KT.Dom.toast) KT.Dom.toast(res.message || 'Family moved', 'success');
              await renderFamiliesTab(content);
            } catch (e) {
              status.style.color = '#DC2626';
              status.textContent = e.message || 'Could not move this family.';
              return false;          // keep the dialog open so the entry is not lost
            }
          },
        },
      ],
    });

    // Destinations load after the dialog is up, so it never feels stuck.
    try {
      const t = await Api.get('/admin/families/' + f.id + '/transfer-targets');
      const rows = (t && t.data) || [];
      Dom.clear(listWrap);
      if (!rows.length) {
        listWrap.appendChild(Dom.el('div', { style: 'padding:14px;color:var(--ink-500);font-size:13px;' },
          t.message || 'No other providers are available in this agency.'));
        return;
      }
      if ((t.siblings || 0) > 0) {
        body.insertBefore(Dom.el('div', {
          style: 'background:#EFF6FF;border:1px solid #BFDBFE;border-radius:9px;padding:9px 12px;margin:0 0 12px;font-size:13px;color:#1E40AF;',
        }, (t.party_size || 1) + ' children move together.'), listWrap);
      }
      rows.forEach((d) => {
        const usable = d.can_take_party && !d.is_current;
        const row = Dom.el('label', {
          style: 'display:flex;align-items:center;gap:10px;padding:9px 10px;border-radius:8px;font-size:14px;'
            + (usable ? 'cursor:pointer;' : 'opacity:.55;cursor:not-allowed;'),
        });
        const radio = Dom.el('input', { type: 'radio', name: 'kt-xfer-room', style: 'width:16px;height:16px;flex-shrink:0;' });
        if (!usable) radio.disabled = true;
        radio.addEventListener('change', () => { chosenRoom = d.room_id; status.textContent = ''; });
        row.appendChild(radio);
        const label = Dom.el('div', { style: 'min-width:0;flex:1;' });
        label.appendChild(Dom.el('div', { style: 'font-weight:600;color:var(--ink-900);' }, d.centre_name));
        label.appendChild(Dom.el('div', { style: 'font-size:12px;color:var(--ink-500);' },
          d.is_current ? 'Their provider now'
            : (d.capacity ? (d.places_left + ' of ' + d.capacity + ' places free') : 'No capacity set')));
        row.appendChild(label);
        if (!d.is_current && !d.can_take_party) {
          row.appendChild(Dom.el('span', { style: 'font-size:10.5px;font-weight:800;color:#B91C1C;background:#FEE2E2;border-radius:5px;padding:2px 6px;' }, 'FULL'));
        }
        listWrap.appendChild(row);
      });
    } catch (e) {
      Dom.clear(listWrap);
      listWrap.appendChild(Dom.el('div', { style: 'padding:14px;color:#DC2626;font-size:13px;' },
        'Could not load providers: ' + (e.message || 'error')));
    }
  }

  /**
   * Close a provider down: decide every family's destination, then execute.
   *
   * Deliberately refuses to be a one-click action. The server will not accept a plan with
   * a family left undecided, and this screen mirrors that — an undecided family is a child
   * stranded at a closed provider, which is exactly the state the archive guard exists to
   * prevent. Preview first, then confirm: the operations are irreversible.
   */
  async function openCentreOffboard(centre, content) {
    const body = Dom.el('div', {});
    body.appendChild(Dom.el('div', { style: 'padding:14px;color:var(--ink-500);font-size:13px;' }, 'Loading the plan\u2026'));

    let plan = null;
    const picks = {};                 // family_id -> 'withdraw' | room id
    const dateInput = Dom.el('input', { type: 'date',
      style: 'padding:9px 12px;border:1px solid var(--ink-300);border-radius:8px;font-size:14px;font-family:inherit;' });
    const closeStaff = Dom.el('input', { type: 'checkbox', style: 'width:17px;height:17px;' });
    const doArchive = Dom.el('input', { type: 'checkbox', style: 'width:17px;height:17px;' });
    doArchive.checked = true;
    const status = Dom.el('div', { style: 'margin-top:12px;font-size:13px;min-height:20px;line-height:1.5;' });

    const modal = Shell.Modal.open({
      title: 'Close ' + centre.name,
      body: body,
      large: true,
      actions: [
        {
          label: 'Preview',
          onClick: async () => { await run(false); return false; },   // never closes
        },
        {
          label: 'Close provider',
          primary: true,
          onClick: async () => {
            const ok = await KT.confirm({
              title: 'Close ' + centre.name + '?',
              description: 'Transfers and withdrawals are applied and parents are emailed. This cannot be undone.',
              tone: 'danger',
            });
            if (!ok) return false;
            const done = await run(true);
            if (!done) return false;
            await renderCentresTab(content);
          },
        },
      ],
    });

    async function run(confirm) {
      const decisions = [];
      let missing = 0;
      (plan ? plan.families : []).forEach((f) => {
        const v = picks[f.family_id];
        if (!v) { missing++; return; }
        decisions.push(v === 'withdraw'
          ? { family_id: f.family_id, action: 'withdraw' }
          : { family_id: f.family_id, action: 'transfer', to_room_id: Number(v) });
      });
      if (missing) {
        status.style.color = '#B45309';
        status.textContent = missing + ' family(ies) still need a decision.';
        return false;
      }
      if (!dateInput.value) {
        status.style.color = '#B45309'; status.textContent = 'Pick the last operating day.'; return false;
      }
      status.style.color = 'var(--ink-600)';
      status.textContent = confirm ? 'Closing\u2026' : 'Checking\u2026';
      try {
        const res = await Api.post('/admin/centres/' + centre.id + '/offboard', {
          last_day: dateInput.value,
          decisions: decisions,
          close_staff: closeStaff.checked,
          archive: doArchive.checked,
          confirm: !!confirm,
        });
        if (!confirm) {
          status.style.color = 'var(--ink-700)';
          status.textContent = 'Ready: ' + res.transfers + ' transfer(s), ' + res.withdrawals
            + ' withdrawal(s)' + (res.will_close_staff ? ', staff accounts closed' : '')
            + (res.will_archive ? ', then archived' : '') + '. Nothing has changed yet.';
          return false;
        }
        const errs = (res.report && res.report.errors) || [];
        if (window.KT && KT.Dom && KT.Dom.toast) {
          KT.Dom.toast(errs.length ? (errs.length + ' problem(s) — see details') : (centre.name + ' closed'),
            errs.length ? 'error' : 'success');
        }
        status.style.color = errs.length ? '#B91C1C' : '#15803D';
        status.textContent = (res.timing || '') + ' ' + (res.archive_message || '')
          + (errs.length ? ('  Problems: ' + errs.map(e => (e.stage + ': ' + e.message)).join('; ')) : '');
        return !errs.length;
      } catch (e) {
        status.style.color = '#DC2626';
        status.textContent = e.message || 'Could not complete this.';
        return false;
      }
    }

    try {
      plan = await Api.get('/admin/centres/' + centre.id + '/offboard-plan');
    } catch (e) {
      Dom.clear(body);
      body.appendChild(Dom.el('div', { style: 'padding:14px;color:#DC2626;font-size:13px;' },
        'Could not load the plan: ' + (e.message || 'error')));
      return;
    }

    Dom.clear(body);
    const sum = plan.summary || {};
    body.appendChild(Dom.el('div', {
      style: 'background:var(--ink-50);border-radius:10px;padding:12px 14px;margin-bottom:14px;font-size:13.5px;color:var(--ink-700);line-height:1.6;',
    }, sum.children_to_place + ' child(ren) across ' + sum.families_affected + ' family(ies) need a destination. '
       + sum.places_available_elsewhere + ' place(s) free elsewhere in the agency'
       + (sum.enough_room_in_agency ? '.' : ' \u2014 not enough for everyone.')
       + (sum.staff_to_close ? ('  ' + sum.staff_to_close + ' staff account(s) here.') : '')));

    if (!(plan.families || []).length) {
      body.appendChild(Dom.el('div', { style: 'font-size:13.5px;color:var(--ink-600);margin-bottom:12px;' },
        'No children are enrolled here, so this provider can simply be archived.'));
    }

    (plan.families || []).forEach((f) => {
      const row = Dom.el('div', {
        style: 'display:flex;align-items:center;gap:12px;padding:9px 0;border-bottom:1px solid var(--ink-100);',
      });
      const who = Dom.el('div', { style: 'flex:1;min-width:0;' });
      who.appendChild(Dom.el('div', { style: 'font-weight:600;color:var(--ink-900);' }, f.family_name || 'Family'));
      who.appendChild(Dom.el('div', { style: 'font-size:12px;color:var(--ink-500);' },
        (f.children || []).map(c => c.name).join(', ')));
      row.appendChild(who);

      const sel = Dom.el('select', {
        style: 'padding:8px 10px;border:1px solid var(--ink-300);border-radius:8px;font-size:13.5px;font-family:inherit;max-width:260px;',
      });
      sel.appendChild(Dom.el('option', { value: '' }, 'Choose\u2026'));
      sel.appendChild(Dom.el('option', { value: 'withdraw' }, 'Withdraw \u2014 leaving care'));
      (plan.destinations || []).forEach((d) => {
        const need = (f.children || []).length;
        const fits = d.capacity === 0 || d.places_left >= need;
        const o = Dom.el('option', { value: String(d.room_id) },
          'Move to ' + d.centre_name + (fits ? ' (' + d.places_left + ' free)' : ' \u2014 full'));
        if (!fits) o.disabled = true;
        sel.appendChild(o);
      });
      sel.addEventListener('change', () => { picks[f.family_id] = sel.value; status.textContent = ''; });
      row.appendChild(sel);
      body.appendChild(row);
    });

    const opts = Dom.el('div', { style: 'margin-top:16px;display:flex;flex-direction:column;gap:10px;' });
    const dRow = Dom.el('label', { style: 'display:flex;align-items:center;gap:10px;font-size:13.5px;color:var(--ink-700);' });
    dRow.appendChild(Dom.el('span', { style: 'font-weight:700;' }, 'Last operating day'));
    dRow.appendChild(dateInput);
    opts.appendChild(dRow);
    const mk = (cb, label, hint) => {
      const l = Dom.el('label', { style: 'display:flex;align-items:flex-start;gap:10px;font-size:13.5px;color:var(--ink-700);cursor:pointer;' });
      l.appendChild(cb);
      const t = Dom.el('span', {});
      t.appendChild(Dom.el('span', { style: 'font-weight:600;' }, label));
      t.appendChild(Dom.el('span', { style: 'display:block;font-size:12px;color:var(--ink-500);' }, hint));
      l.appendChild(t);
      return l;
    };
    opts.appendChild(mk(closeStaff, 'Close their staff accounts',
      'Sign-in is refused, tokens revoked and room assignments cleared. Your own account is never closed.'));
    opts.appendChild(mk(doArchive, 'Archive the centre afterwards',
      'Only happens once no child is enrolled here.'));
    body.appendChild(opts);
    body.appendChild(status);
  }

  /**
   * De-enrolment confirmation, with the outstanding balance in front of the admin.
   *
   * Closing a family removes the portal their invoices were visible in, so the last
   * moment anyone can act on a debt is this dialog. Anthony's call (2026-08-25) was
   * to WARN, not block: a disputed invoice must never trap a record. But the figure
   * has to be seen and ticked, and that acknowledgement is written to the audit log.
   *
   * Resolves to false (cancelled) or true (go ahead, acknowledged).
   */
  async function confirmDeEnrol(f) {
    var owing = null;
    try { owing = await Api.get('/admin/families/' + f.id + '/outstanding'); }
    catch (e) { owing = null; }   // a failed lookup must not read as "owes nothing"

    /* A short title and a readable body, not one run-on paragraph. KT.confirm
       escapes its text and renders the description with white-space:pre-line, so
       line breaks here survive; they did not before, and this dialog was the wall
       of text that proved it. (2026-08-26) */
    var lines = [
      '\u2022 Their children are marked WITHDRAWN as of today',
      '\u2022 Guardian accounts are closed \u2014 they can no longer sign in',
      '\u2022 Each guardian is emailed a goodbye notice with your retention policy',
      // Drafted, NOT sent — the wording must not let anyone think the family receives
      // one automatically on the way out.
      '\u2022 A leaving report card is DRAFTED for each child, waiting in Report cards',
      '',
      'Care records, attendance and history are preserved.',
      'The email cannot be unsent.',
    ];

    if (owing === null) {
      lines.push('', '\u26a0 Their balance could not be checked just now,',
                     'so this may leave money owed.');
    } else if ((owing.total || 0) > 0) {
      lines.push('', 'OUTSTANDING: $' + Number(owing.total).toFixed(2)
        + ' across ' + owing.count + ' invoice' + (owing.count === 1 ? '' : 's'));
      (owing.invoices || []).slice(0, 4).forEach(function (i) {
        lines.push('   ' + i.number + '  due ' + (i.due_at || '\u2014')
          + '  \u2014 $' + Number(i.balance_due).toFixed(2));
      });
      if ((owing.invoices || []).length > 4) {
        lines.push('   \u2026and ' + ((owing.invoices || []).length - 4) + ' more');
      }
      lines.push('', 'The goodbye email will itemise this and request payment.');
    }

    /* Ask WHEN before asking whether. A family gives notice — "their last day is the
       30th" — and the old dialog could only do it today, so an admin either closed them
       early or had to remember to come back on the day. A future date schedules it and
       leaves their access alone until then. */
    /* The AGENCY's date. toISOString() is UTC, so in Toronto this read as tomorrow from
       8pm every evening — which defaulted the picker to tomorrow and then described a
       same-day de-enrolment to the admin as scheduled. */
    var today = (window.KT && KT.agencyToday) ? KT.agencyToday()
      : (function (d) {
          return d.getFullYear() + '-' + ('0' + (d.getMonth() + 1)).slice(-2)
            + '-' + ('0' + d.getDate()).slice(-2);
        })(new Date());
    var dateBox = Dom.el('div', {});
    dateBox.appendChild(Dom.el('div', {
      style: 'font-size:13px;color:#334155;margin-bottom:6px;',
    }, 'What is their last day?'));
    /* No `min`. Paperwork follows departures: somebody notices on the Wednesday that a
       family stopped coming on the Friday, and the last day is what rosters, capacity,
       CACFP claims and the final invoice are counted against. Blocking the past forced
       the admin to record a date they knew was wrong. (2026-08-27) */
    var lastDayIn = Dom.el('input', {
      type: 'date', value: today,
      style: 'width:100%;box-sizing:border-box;padding:9px 11px;border:1.5px solid #CBD5E1;'
           + 'border-radius:8px;font-size:14px;',
    });
    dateBox.appendChild(lastDayIn);
    var dateNote = Dom.el('div', {
      style: 'font-size:12.5px;color:#64748B;margin-top:6px;line-height:1.5;',
    }, 'Today — they are closed straight away.');
    dateBox.appendChild(dateNote);
    /* A 'YYYY-MM-DD' as a LOCAL date.
       kt-tz-global.js makes Date parse a zone-less string as UTC, so
       new Date('2026-08-21T00:00:00') is 8pm on the 20th in Toronto and every
       toLocaleDateString on it names the day before. Numeric parts are local by
       construction. Differences between two such dates were always right, which is how
       this hid: the day count read correctly while the date beside it did not. */
    function ymdToLocalDate(v) {
      var p = String(v || '').split('-');
      return new Date(Number(p[0]), Number(p[1]) - 1, Number(p[2]));
    }

    /* Three outcomes, and the admin should know which one they are choosing BEFORE they
       press the red button. A last day in the past is a BACKDATED departure: it closes
       them now, but the records read as though they left on the day they actually did. */
    function describeLastDay() {
      var v = lastDayIn.value;
      if (v && v > today) {
        dateNote.style.color = '#166534';
        dateNote.textContent = 'Scheduled. They keep their portal access, daily updates '
          + 'and invoices until that day, and the de-enrolment completes by itself the '
          + 'morning after.';
        return;
      }
      if (v && v < today) {
        var d = ymdToLocalDate(v);
        var back = Math.round((ymdToLocalDate(today) - d) / 86400000);
        dateNote.style.color = '#B45309';
        dateNote.textContent = 'Backdated ' + back + ' day' + (back === 1 ? '' : 's') + '. '
          + 'Their access closes now, and the records show they left on '
          + d.toLocaleDateString(undefined, { weekday: 'long', day: 'numeric', month: 'long' })
          + ' — so rosters, capacity and their final invoice count to that day, not today.';
        return;
      }
      dateNote.style.color = '#64748B';
      dateNote.textContent = 'Today — they are closed straight away.';
    }
    lastDayIn.addEventListener('change', describeLastDay);
    lastDayIn.addEventListener('input', describeLastDay);

    /* WHY they are leaving. Mirrors AdminController::DEPARTURE_REASONS — change both.
       The note box is always shown, not just under "Other": a picked reason is a category
       and the sentence beside it is what a person actually needs six months later. */
    var DEENROL_REASONS = [
      ['moved_away',       'Moved out of the area'],
      ['started_school',   'Child started school'],
      ['schedule_change',  'Change in care needs or schedule'],
      ['work_change',      "Change in the parent's work or income"],
      ['other_provider',   'Moved to another provider'],
      ['cost',             'Cost of care'],
      ['non_payment',      'Unpaid fees'],
      ['family_request',   'Family request — no reason given'],
      ['agency_initiated', 'Agency decision'],
      ['other',            'Other — describe below']
    ];

    dateBox.appendChild(Dom.el('div', {
      style: 'font-size:13px;color:#334155;margin:14px 0 6px;',
    }, 'Why are they leaving?'));

    var reasonSel = Dom.el('select', {
      style: 'width:100%;box-sizing:border-box;padding:9px 11px;border:1.5px solid #CBD5E1;'
           + 'border-radius:8px;font-size:14px;background:#fff;',
    });
    reasonSel.appendChild(Dom.el('option', { value: '' }, 'Choose a reason…'));
    DEENROL_REASONS.forEach(function (r) {
      reasonSel.appendChild(Dom.el('option', { value: r[0] }, r[1]));
    });
    dateBox.appendChild(reasonSel);

    var reasonNote = Dom.el('input', {
      type: 'text', maxlength: '300',
      placeholder: 'Add a note (optional) — this is what people read later',
      style: 'width:100%;box-sizing:border-box;padding:9px 11px;border:1.5px solid #CBD5E1;'
           + 'border-radius:8px;font-size:14px;margin-top:7px;',
    });
    dateBox.appendChild(reasonNote);

    var reasonWarn = Dom.el('div', {
      style: 'font-size:12.5px;color:#B91C1C;margin-top:6px;min-height:0;display:none;',
    }, '');
    dateBox.appendChild(reasonWarn);

    function reasonOk() {
      // "Other" is a category, not an answer — it needs the sentence.
      if (!reasonSel.value) { return 'Pick a reason before continuing.'; }
      if (reasonSel.value === 'other' && !reasonNote.value.trim()) {
        return 'Describe the reason in the note box.';
      }
      return '';
    }
    function clearWarn() { reasonWarn.style.display = 'none'; }
    reasonSel.addEventListener('change', function () {
      clearWarn();
      if (reasonSel.value === 'other') { try { reasonNote.focus(); } catch (e) {} }
    });
    reasonNote.addEventListener('input', clearWarn);

    /* Loops rather than failing: an admin who forgot the reason should be handed the
       dialog back with the message on it, not made to start again from the kebab. */
    while (true) {
      var ok = await KT.confirm({
        title: 'De-enrol ' + (f.family_name || 'this family') + '?',
        description: lines.join('\n'),
        extra: dateBox,
        tone: 'danger',
        okLabel: 'De-enrol',
      });
      if (!ok) return false;
      var why = reasonOk();
      if (!why) break;
      reasonWarn.textContent = why;
      reasonWarn.style.display = 'block';
    }
    var deEnrolLastDay = lastDayIn.value || today;

    if (owing && (owing.total || 0) > 0) {
      var ack = await KT.confirm({
        title: '$' + Number(owing.total).toFixed(2) + ' is still owed',
        description: (f.family_name || 'This family') + ' has an unpaid balance.\n\n'
          + 'Continuing records that you have seen it, and sends the family an '
          + 'itemised demand for payment.',
        tone: 'warning',
        okLabel: 'I have seen it \u2014 continue',
      });
      if (!ack) return false;
    }
    // The caller needs the date, so this returns it rather than a bare true.
    return {
      last_day: deEnrolLastDay,
      reason_code: reasonSel.value,
      reason: reasonNote.value.trim(),
    };
  }

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
        if (!await KT.confirm('Reactivate this family?\n' + '  - Guardian logins are restored' + '\n  - Their children reappear on educator rosters' + '\n  - Notifications and daily emails resume' + '\n  - Each guardian is emailed to say access is back')) return;
        try { await Api.post('/admin/families/' + f.id + '/reactivate', {}); await renderFamiliesTab(content); }
        catch (e) { alert('Could not reactivate: ' + (e.message || 'error')); }
      }));
    } else {
      bar.appendChild(mk('⏸️', 'kt-act-info', 'Suspend', async function () {
        if (!await KT.confirm('Suspend this family?\n' + '  - Guardian logins are blocked until you reactivate' + '\n  - Their children are hidden from educator rosters' + '\n  - All notifications and daily emails stop' + '\n  - Each guardian is emailed to explain the pause' + '\n  - The retention clock starts from today' + '\n\nEnrolment is kept, and this is reversible.')) return;
        try { await Api.post('/admin/families/' + f.id + '/suspend', {}); await renderFamiliesTab(content); }
        catch (e) { alert('Could not suspend: ' + (e.message || 'error')); }
      }));
    }
    /* Moving a family to another provider. Sits with the other family-level actions
       because that is what a transfer IS - a family belongs to one provider and siblings
       cannot be split, so "move this child" is never the real operation. (2026-08-25) */
    if (!f.suspended) {
      bar.appendChild(mk('\u{1F501}', 'kt-act-teal', 'Move to another provider', function () {
        openFamilyTransfer(f, content);
      }));
    }
    bar.appendChild(mk('🗑️', 'kt-act-danger', 'De-enrol', async function () {
      /* Keep what the dialog returned - it carries the chosen last day. Testing the call
         for truthiness alone left `ok` unbound and every de-enrol died on a
         ReferenceError before it reached the API. (2026-08-27) */
      var deEnrol = await confirmDeEnrol(f);
      if (!deEnrol) return;
      try {
        var res = await Api.delete('/admin/families/' + f.id
          + '?acknowledged_balance=1&last_day=' + encodeURIComponent(deEnrol.last_day)
          + '&reason_code=' + encodeURIComponent(deEnrol.reason_code || '')
          + '&reason=' + encodeURIComponent(deEnrol.reason || ''));
        if (Dom.toast) {
          /* Name the report cards in the confirmation: they are the one part of a
             de-enrolment that still needs somebody to go and do something afterwards. */
          var _rc = (res && res.report_cards_drafted) || 0;
          Dom.toast(res && res.message
              ? (res.message + (_rc ? ' · ' + _rc + ' leaving report' + (_rc === 1 ? '' : 's') + ' drafted' : ''))
              : 'Family de-enrolled',
                    res && res.scheduled ? 'info' : 'success');
        }
        await renderFamiliesTab(content);
      }
      catch (e) { alert('Could not inactivate: ' + (e.message || 'error')); }
    }));
    return bar;
  }

  // v22p26: families table view.

  /* When a family joined, split into date and time so the column stays narrow.

     Rendered in the AGENCY timezone, not the device one. The stored value has no
     zone marker, so it is pinned to UTC before conversion — read as local it lands
     hours out, and near midnight on the wrong day entirely. */
  function _famEnrolled(ts) {
    if (!ts) { return { date: '—', time: '' }; }
    try {
      var tz = (window.KT && KT.agencyTz && KT.agencyTz()) || undefined;
      var iso = String(ts).replace(' ', 'T');
      var d = new Date(iso + (/[Zz]|[+-]\d\d:?\d\d$/.test(iso) ? '' : 'Z'));
      if (isNaN(d.getTime())) { return { date: String(ts).slice(0, 10), time: '' }; }
      return {
        date: d.toLocaleDateString(undefined, { year: 'numeric', month: 'short', day: 'numeric', timeZone: tz }),
        time: d.toLocaleTimeString(undefined, { hour: 'numeric', minute: '2-digit', timeZone: tz }),
      };
    } catch (e) {
      return { date: String(ts).slice(0, 10), time: '' };
    }
  }
  function renderFamiliesTable(families, centres, content) {
    // v22p46: bulk delete via multi-select. Same UX as the Users tab.
    var wrap = Dom.el('div');
    var selectedIds = new Set();
    var bulkBar = Dom.el('div', { style: 'display:none;align-items:center;gap:10px;background:#FEF3C7;border:1px solid #FCD34D;border-radius:10px;padding:10px 14px;margin-bottom:12px;' });
    var bulkCount = Dom.el('div', { style: 'flex:1;font-size:13px;color:#92400E;font-weight:600;' }, '0 selected');
    var bulkDelete = Dom.el('button', { style: 'background:white;color:#DC2626;border:1px solid #FCA5A5;padding:6px 12px;border-radius:8px;font-size:12px;font-weight:600;cursor:pointer;' }, 'De-enrol');
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
      // Spelled out at length on purpose: this is a checkbox that sends one
      // irreversible goodbye email per selected family, and it used to promise that
      // nothing beyond a row was touched.
      if (!await KT.confirm('De-enrol ' + ids.length + ' famil' + (ids.length === 1 ? 'y' : 'ies') + '?\n\nFor EACH one:' + '\n  - Their children are marked WITHDRAWN as of today' + '\n  - Guardian accounts are closed' + '\n  - A goodbye email is sent to every guardian' + '\n  - A leaving report card is drafted for each child' + '\n\nThat is up to ' + ids.length + ' email(s) which cannot be unsent. Care records and history are preserved.')) return;
      bulkDelete.disabled = true;
      var ok = 0, fail = 0;
      for (const id of ids) { try { await Api.delete('/admin/families/' + id + '?acknowledged_balance=1'); ok++; } catch (e) { fail++; } }
      bulkDelete.disabled = false;
      alert('De-enrolled ' + ok + ' famil' + (ok === 1 ? 'y' : 'ies') + ', ' + fail + ' failed.');
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

    ['Family', 'Centre', 'Children', 'Guardians', 'Onboarding', 'Outstanding', 'Enrolled', ''].forEach(function (h) {
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
      var onbTd = Dom.el('td', { style: 'padding:11px 14px;' });
      onbTd.appendChild(familyOnboardingBadge(f));
      tr.appendChild(onbTd);
      tr.appendChild(Dom.el('td', { style: 'padding:11px 14px;font-size:13px;' + (f.outstanding_balance > 0 ? 'color:#DC2626;font-weight:600;' : 'color:#6B7280;') },
        f.outstanding_balance > 0 ? ('$' + f.outstanding_balance.toFixed(2)) : '—'));

      // When the family joined, date and time, in the agency's timezone.
      var enrolledTd = Dom.el('td', { style: 'padding:11px 14px;font-size:13px;color:#334155;white-space:nowrap;' });
      var stamp = _famEnrolled(f.created_at);
      enrolledTd.appendChild(Dom.el('div', {}, stamp.date));
      if (stamp.time) {
        enrolledTd.appendChild(Dom.el('div', { style: 'font-size:11.5px;color:#94A3B8;' }, stamp.time));
      }
      tr.appendChild(enrolledTd);

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
      children: [{ first_name: '', last_name: '', preferred_name: '', date_of_birth: '', gender: 'prefer_not_to_say', enrollment_status: 'enrolled', allergies: '', dietary_restrictions: '', medical_notes: '', doctor_name: '', doctor_phone: '', school: '', expected_dropoff_time: '', expected_pickup_time: '' }],
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
      right.appendChild(Dom.el('div', { style: 'font-size:12.5px;font-weight:700;color:#1F6080;' }, 'Child photo'));
      var msg = Dom.el('div', { style: 'font-size:11.5px;margin-top:2px;color:' + (c.photo_url ? '#16A34A' : '#64748B') + ';' }, c.photo_url ? '✓ Photo added' : 'Optional — the family can add it later');
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
    function _pcombine(a, n) { a = _pdig(a); var nd = _pdig(n); if (!a && !nd) return n ? String(n) : ''; if (a && nd) return '(' + a + ') ' + (nd.length >= 7 ? nd.slice(0, 3) + '-' + nd.slice(3, 7) : nd); return (a ? '(' + a + ') ' : '') + (n || ''); }
    /* One field, formatted by KT.Phone as you type. Still writes obj[key] on every
       keystroke, so the wizard's step state behaves exactly as before. */
    function bindPhone(obj, key) {
      var num = Dom.el('input', { type: 'tel', placeholder: '(416) 555-0199', style: inStyle });
      num.value = KT.Phone ? KT.Phone.format(obj[key]) : (obj[key] || '');
      if (KT.Phone) KT.Phone.attach(num);
      num.addEventListener('input', function () { obj[key] = num.value.trim(); });
      return num;
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
          // See the user check above: a failed request must not read as "no duplicates".
          if (!r || !Array.isArray(r.matches)) { throw new Error('dup-check-unavailable'); }
          var m = r.matches;
          if (!m.length) { famDup.style.display = 'none'; state._famDup = false; return; }
          state._famDup = true; state._famDupOk = false;
          famDup.style.display = 'block';
          famDup.innerHTML = '<div style="font-weight:800;margin-bottom:4px;">⚠ A family with a similar name already exists</div>'
            + m.map(function (x) { return '<div>• ' + _dupEsc(x.label) + ' <span style="color:#B45309;">(' + _dupEsc(x.detail) + ')</span></div>'; }).join('')
            + '<label style="display:flex;gap:7px;align-items:center;margin-top:7px;font-weight:700;cursor:pointer;"><input type="checkbox" id="kt-famdup-ok"> This is a different family</label>';
          var cb = famDup.querySelector('#kt-famdup-ok');
          if (cb) cb.addEventListener('change', function () { state._famDupOk = cb.checked; });
        }).catch(function () {
          famDup.style.display = 'block';
          famDup.innerHTML = '<div style="font-weight:800;margin-bottom:4px;">\u26a0 Could not check for existing records</div>'
            + '<div>The duplicate check did not run \u2014 please confirm this is not already in KiddieTrac before saving.</div>';
        });
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
      blurb.innerHTML = '<strong style="color:#1F6080;">📷 A photo helps, but you can add it later.</strong><br>'
        + 'Educators use it to confirm they have the right child at drop-off, pickup, headcounts and in an emergency, so it is worth having — but you do not need one to add the family now. '
        + '<strong>The family is asked for it during their own onboarding</strong>, when they have one to hand.<br>'
        + 'The photo is <strong>private</strong>: only the child’s assigned educators and centre administrators ever see it, it is never shared publicly or with other families, and it is removed when the child leaves.';
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
              // See the user check above: a failed request must not read as "no duplicates".
              if (!r || !Array.isArray(r.matches)) { throw new Error('dup-check-unavailable'); }
              var mm = r.matches;
              if (!mm.length) { warnEl.style.display = 'none'; child._dup = false; return; }
              child._dup = true; child._dupOk = false;
              warnEl.style.display = 'block';
              warnEl.innerHTML = '<div style="font-weight:800;margin-bottom:4px;">⚠ A child with this name already exists</div>'
                + mm.map(function (x) { return '<div>• ' + _dupEsc(x.label) + ' <span style="color:#B45309;">(' + _dupEsc(x.detail) + ')</span></div>'; }).join('')
                + '<label style="display:flex;gap:7px;align-items:center;margin-top:7px;font-weight:700;cursor:pointer;"><input type="checkbox" class="kt-cdup-ok"> This is a different child</label>';
              var cb = warnEl.querySelector('.kt-cdup-ok');
              if (cb) cb.addEventListener('change', function () { child._dupOk = cb.checked; });
            }).catch(function () {
              warnEl.style.display = 'block';
              warnEl.innerHTML = '<div style="font-weight:800;margin-bottom:4px;">\u26a0 Could not check for existing records</div>'
            + '<div>The duplicate check did not run \u2014 please confirm this is not already in KiddieTrac before saving.</div>';
            });
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

        /* The usual hours. Optional — plenty of families settle these later — but asked
           here, because the alternative is editing each child afterwards and every child
           on the system currently reads "times not set". */
        var r7 = Dom.el('div', { style: 'display:grid;grid-template-columns:1fr 1fr;gap:10px;' });
        r7.appendChild(wrap('Usual drop-off', bindInput(c, 'expected_dropoff_time', { type: 'time' })));
        r7.appendChild(wrap('Usual pick-up', bindInput(c, 'expected_pickup_time', { type: 'time' })));
        card.appendChild(r7);
        r6.appendChild(Dom.el('div', {}));
        card.appendChild(r6);
        var med = Dom.el('textarea', { style: inStyle + 'min-height:52px;font-family:inherit;' });
        med.value = c.medical_notes || ''; med.addEventListener('input', function () { c.medical_notes = med.value; });
        card.appendChild(wrap('Medical notes (medications, conditions)', med));
        bodyEl.appendChild(card);
      });
      var add = Dom.el('button', { type: 'button', style: 'background:#EFF6FB;border:1px dashed #1F6080;color:#1F6080;border-radius:8px;padding:10px;width:100%;font-weight:600;cursor:pointer;font-size:13px;' }, '+ Add another child');
      add.addEventListener('click', function () { state.children.push({ first_name: '', last_name: '', preferred_name: '', date_of_birth: '', gender: 'prefer_not_to_say', enrollment_status: 'enrolled', allergies: '', dietary_restrictions: '', medical_notes: '', doctor_name: '', doctor_phone: '', school: '', expected_dropoff_time: '', expected_pickup_time: '' }); renderChildrenStep(); });
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
          /* No longer a gate. The person filling this in is working from an enrolment
             form and rarely has a photo of the child; the family is asked for it during
             their own onboarding, where they actually have one. */
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
            allergies: (c.allergies || '').trim() || null, dietary_restrictions: (c.dietary_restrictions || '').trim() || null, medical_notes: (c.medical_notes || '').trim() || null, doctor_name: (c.doctor_name || '').trim() || null, doctor_phone: (c.doctor_phone || '').trim() || null, school: (c.school || '').trim() || null,
            expected_dropoff_time: (c.expected_dropoff_time || '').trim() || null,
            expected_pickup_time: (c.expected_pickup_time || '').trim() || null };
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
        /* Say what actually happened, not what we hoped would happen.
           This used to end with a flat "Invites sent." regardless — but the API returns
           how many actually went, and it is often zero: every guardian may already have
           an account, or the agency may be under mail suppression. Telling an admin that
           invites were sent when none were is how a parent ends up never hearing from us
           and nobody knowing. (Anthony, 2026-08-26) */
        await renderFamiliesTab(content);
        Shell.Modal.close();
        ktFamilyCreatedSummary(res);
      } catch (e) {
        /* This household is already on file, de-enrolled. Don't just say no — the whole
           reason duplicates got created is that saying no was all anyone could do.
           Offer the restore, which is now a real route. (2026-08-30) */
        var _d = e.data || {};
        if (_d.code === 'family_deenrolled_exists' && _d.family_id) {
          status.style.color = '#9A3412';
          status.innerHTML = '';
          status.appendChild(Dom.el('div', { style: 'margin-bottom:9px;line-height:1.55;' }, e.message));
          var _rb = Dom.el('button', {
            type: 'button',
            style: 'background:#0F766E;color:#fff;border:0;border-radius:8px;'
                 + 'padding:7px 14px;font-size:13px;font-weight:600;cursor:pointer;',
          }, '\u21a9\ufe0f Bring that family back instead');
          _rb.addEventListener('click', async function () {
            _rb.disabled = true; _rb.textContent = 'Restoring…';
            try {
              await Api.post('/admin/families/' + _d.family_id + '/restore', {});
              Shell.Modal.close();
              await renderFamiliesTab(content);
              if (window.KT.Dom && KT.Dom.toast) { KT.Dom.toast('Family restored with their full history', 'success'); }
              showFamilyDetail(_d.family_id, false);
            } catch (e2) {
              _rb.disabled = false; _rb.textContent = '\u21a9\ufe0f Bring that family back instead';
              if (window.KT.Dom && KT.Dom.toast) { KT.Dom.toast('Could not restore: ' + (e2.message || 'error'), 'error'); }
            }
          });
          status.appendChild(_rb);
          renderFooter();
          return;
        }
        status.style.color = '#DC2626';
        status.textContent = (e.message || 'Could not create family') + (e.errors ? ' — ' + Object.values(e.errors).flat().join(', ') : '');
        renderFooter();
      }
    }

    Shell.Modal.open({ title: 'New family', body: root, large: true, actions: [] });
    render();
  }

  /**
   * What the admin needs to know the moment a family is created.
   *
   * A toast is right when everything worked and nothing is owed. It is the wrong shape
   * for "this family exists but cannot use the portal yet", which is a task, not a
   * notification — so anything still outstanding gets a dialog that has to be dismissed
   * deliberately, listing the specific thing and who it concerns.
   */
  function ktFamilyCreatedSummary(res) {
    res = res || {};
    var guardians = res.guardians || 0;
    var children = res.children || 0;
    var invited = res.invited || 0;
    var unplaced = res.unplaced || [];

    var todo = [];
    if (!invited && guardians) {
      todo.push('No welcome email went out. ' + (guardians === 1 ? 'The guardian' : 'The guardians')
        + ' cannot sign in until one is sent — either they already had an account, or this '
        + 'agency is currently under mail suppression. Open the family and use Send welcome.');
    }
    if (unplaced.length) {
      todo.push(unplaced.join(' and ') + (unplaced.length === 1 ? ' has' : ' have')
        + ' no room yet, so ' + (unplaced.length === 1 ? 'they will' : 'they will')
        + ' not appear in any educator\'s list. Assign a room on the child record.');
    }

    if (!todo.length) {
      if (Dom.toast) {
        Dom.toast('Family created — ' + guardians + ' guardian(s), ' + children + ' child(ren), '
          + invited + ' welcome email(s) sent.', 'success');
      }
      return;
    }

    var body = document.createElement('div');
    body.innerHTML =
      '<p style="margin:0 0 14px;font-size:14.5px;line-height:1.6;color:#0F172A;">'
        + 'Family created with <strong>' + guardians + '</strong> guardian(s) and <strong>'
        + children + '</strong> child(ren). Before they can use KiddieTrac:</p>'
      + '<ul style="margin:0;padding-left:20px;">'
        + todo.map(function (t) {
            return '<li style="font-size:13.5px;line-height:1.65;color:#7C2D12;margin-bottom:9px;">'
              + String(t).replace(/&/g, '&amp;').replace(/</g, '&lt;') + '</li>';
          }).join('')
      + '</ul>';

    Shell.Modal.open({
      title: 'Family created — still to do',
      body: body,
      actions: [{ label: 'Got it', primary: true, onClick: function () { Shell.Modal.close(); } }],
    });
  }

  function showFamilyModal(family, centres, content) {
    const isEdit = !!family;
    if (!isEdit) { return showFamilyWizard(centres, content); }
    return showFamilyEditTabs(family, centres, content);
  }

  // Full tabbed family editor: Family · Guardians · Children · Emergency.
  /** The "add" affordance each edit tab was missing. Reuses the detail view's dialogs. */
  function editTabAddBtn(label, onClick) {
    var b = Dom.el('button', {
      type: 'button',
      style: 'margin:0 0 12px;padding:7px 13px;background:#fff;border:1px solid #1F6080;'
           + 'color:#1F6080;border-radius:7px;font-size:13px;font-weight:700;cursor:pointer;',
    }, label);
    b.addEventListener('click', onClick);
    return b;
  }

  function showFamilyEditTabs(family, centres, content) {
    centres = centres || [];
    content = content || document.getElementById('appMain');
    const EREL = ['Mother', 'Father', 'Guardian', 'Grandmother', 'Grandfather', 'Grandparent', 'Aunt', 'Uncle', 'Sibling', 'Family friend', 'Neighbour', 'Other'];
    function _dig(s) { return (s == null ? '' : String(s)).replace(/[^0-9]/g, ''); }
    function _psplit(v) { var d = _dig(v); if (d.length === 11 && d[0] === '1') d = d.slice(1); if (d.length >= 10) return { a: d.slice(0, 3), n: d.slice(3, 10) }; if (d.length > 3) return { a: d.slice(0, 3), n: d.slice(3) }; return { a: '', n: v || '' }; }
    function _pcomb(a, n) { a = _dig(a); var nd = _dig(n); if (!a && !nd) return n ? String(n) : ''; if (a && nd) return '(' + a + ') ' + (nd.length >= 7 ? nd.slice(0, 3) + '-' + nd.slice(3, 7) : nd); return (a ? '(' + a + ') ' : '') + (n || ''); }
    var IN = 'width:100%;padding:8px 11px;border:1px solid #D1D5DB;border-radius:7px;font-size:14px;box-sizing:border-box;';
    function inp(val, ph, type) { var e = Dom.el('input', { type: type || 'text', style: IN }); if (ph) e.placeholder = ph; e.value = val == null ? '' : val; return e; }
    function selEl(val, opts) { var s = Dom.el('select', { style: IN + 'background:#fff;' }); opts.forEach(function (o) { var op = Dom.el('option', { value: o.value }, o.label); if (String(val) === String(o.value)) op.selected = true; s.appendChild(op); }); return s; }
    function fwrap(l, e) { var d = Dom.el('div', { style: 'margin-bottom:12px;' }); d.appendChild(Dom.el('label', { style: 'display:block;font-size:12.5px;font-weight:600;color:#334155;margin-bottom:4px;' }, l)); d.appendChild(e); return d; }
    function grid(cols) { return Dom.el('div', { style: 'display:grid;grid-template-columns:' + cols + ';gap:12px;' }); }
    /* One field, formatted as you type by KT.Phone — the separate "Area" box was the
       last place in the portal still splitting a phone across two inputs. Same
       { wrap, get } contract, so every caller is untouched. */
    function phoneField(label, val) {
      var n = inp(KT.Phone ? KT.Phone.format(val) : (val || ''), '(416) 555-0199', 'tel');
      if (KT.Phone) KT.Phone.attach(n);
      return { wrap: fwrap(label, n), get: function () { return n.value.trim(); } };
    }

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
      pane.appendChild(editTabAddBtn('＋ Add guardian', function () {
        showAddGuardian(f, function () { Shell.Modal.close(); showFamilyEditTabs(family, centres, content); });
      }));
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
      pane.appendChild(editTabAddBtn('＋ Add child', function () {
        showAddChildToFamily(f, function () { Shell.Modal.close(); showFamilyEditTabs(family, centres, content); });
      }));
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
      pane.appendChild(editTabAddBtn('＋ Add contact', function () {
        showAddEmergencyContact(f, function () { Shell.Modal.close(); showFamilyEditTabs(family, centres, content); });
      }));
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

  /** A section heading with an optional action on the right. */
  function famSectionHead(label, actionLabel, onAction) {
    var h = Dom.el('div', {
      style: 'display:flex;align-items:center;gap:10px;margin:18px 0 8px;',
    });
    h.appendChild(Dom.el('h4', {
      style: 'flex:1;font-size:14px;font-weight:700;margin:0;letter-spacing:0.5px;color:var(--ink-700);',
    }, label));
    if (actionLabel && onAction) {
      var b = Dom.el('button', {
        type: 'button',
        style: 'background:#fff;border:1px solid #1F6080;color:#1F6080;border-radius:7px;'
             + 'padding:5px 11px;font-size:12.5px;font-weight:700;cursor:pointer;',
      }, actionLabel);
      b.addEventListener('click', onAction);
      h.appendChild(b);
    }
    return h;
  }

  /** A labelled field for the small dialogs below. */
  function famField(label, el) {
    var w = Dom.el('label', { style: 'display:block;margin-bottom:10px;' });
    w.appendChild(Dom.el('div', {
      style: 'font-size:12px;font-weight:700;color:var(--ink-500);margin-bottom:4px;',
    }, label));
    w.appendChild(el);
    return w;
  }
  function famInput(attrs) {
    return Dom.el('input', Object.assign({
      style: 'width:100%;box-sizing:border-box;padding:8px 10px;border:1.5px solid #CBD5E1;'
           + 'border-radius:8px;font-size:13.5px;',
    }, attrs || {}));
  }

  /**
   * Add another child to a family that already exists.
   *
   * The endpoint has been there all along (POST /director/enrollments takes a family_id);
   * nothing on the family record ever called it, and the Users screen sent people to
   * "Add Family" — which is no help when the family is already there.
   */
  async function showAddChildToFamily(family, onDone) {
    var body = Dom.el('div', {});
    body.appendChild(Dom.el('p', {
      style: 'margin:0 0 14px;font-size:13.5px;color:var(--ink-500);',
    }, 'Adding a child to the ' + (family.family_name || '') + ' family. They inherit the '
      + 'family\'s guardians and billing.'));

    var first = famInput({ placeholder: 'First name' });
    var last = famInput({ placeholder: 'Last name', value: family.family_name || '' });
    var dob = famInput({ type: 'date' });
    var start = famInput({ type: 'date', value: (new Date()).toISOString().slice(0, 10) });
    var fee = famInput({ type: 'number', min: '0', step: '0.01', value: '0' });
    var room = Dom.el('select', {
      style: 'width:100%;box-sizing:border-box;padding:8px 10px;border:1.5px solid #CBD5E1;'
           + 'border-radius:8px;font-size:13.5px;background:#fff;',
    });

    // Rooms at the family's own centre first — that is nearly always the answer.
    try {
      var rr = await Api.get('/director/rooms').catch(function () { return { rooms: [] }; });
      var rooms = rr.rooms || rr.data || [];
      rooms.forEach(function (r) {
        room.appendChild(Dom.el('option', { value: r.id }, (r.centre_name ? r.centre_name + ' · ' : '') + r.name));
      });
    } catch (e) { /* the field just stays empty and the save will say so */ }

    body.appendChild(famField('First name', first));
    body.appendChild(famField('Last name', last));
    body.appendChild(famField('Date of birth', dob));
    body.appendChild(famField('Provider / room', room));
    body.appendChild(famField('Start date', start));
    body.appendChild(famField('Monthly fee', fee));
    var msg = Dom.el('div', { style: 'font-size:12.5px;color:#B91C1C;min-height:16px;' });
    body.appendChild(msg);

    Shell.Modal.open({
      title: 'Add child',
      body: body,
      actions: [{
        label: 'Add child', primary: true,
        onClick: async function () {
          msg.textContent = '';
          if (!first.value.trim() || !last.value.trim() || !dob.value) {
            msg.textContent = 'First name, last name and date of birth are needed.'; return;
          }
          if (!room.value) { msg.textContent = 'Choose a provider or room.'; return; }
          try {
            await Api.post('/director/enrollments', {
              first_name: first.value.trim(),
              last_name: last.value.trim(),
              date_of_birth: dob.value,
              family_id: family.id,
              room_id: parseInt(room.value, 10),
              start_date: start.value,
              monthly_fee: parseFloat(fee.value || '0'),
            });
            if (Dom.toast) { Dom.toast(first.value.trim() + ' added to the family', 'success'); }
            onDone && onDone();
          } catch (e) {
            msg.textContent = (e.message || 'Could not add the child')
              + (e.errors ? ' — ' + Object.values(e.errors).flat().join(', ') : '');
          }
        },
      }],
    });
  }

  /** Add a guardian to an existing family, and optionally send their invite. */
  function showAddGuardian(family, onDone) {
    var body = Dom.el('div', {});
    var first = famInput({ placeholder: 'First name' });
    var last = famInput({ placeholder: 'Last name' });
    var email = famInput({ type: 'email', placeholder: 'name@example.com' });
    var phone = famInput({ placeholder: 'Phone' });
    var rel = Dom.el('select', {
      style: 'width:100%;box-sizing:border-box;padding:8px 10px;border:1.5px solid #CBD5E1;'
           + 'border-radius:8px;font-size:13.5px;background:#fff;',
    });
    [['mother', 'Mother'], ['father', 'Father'], ['guardian', 'Guardian'],
     ['grandparent', 'Grandparent'], ['foster', 'Foster'], ['other', 'Other']]
      .forEach(function (o) { rel.appendChild(Dom.el('option', { value: o[0] }, o[1])); });

    var pickup = Dom.el('input', { type: 'checkbox', checked: true });
    var invite = Dom.el('input', { type: 'checkbox', checked: true });

    body.appendChild(famField('First name', first));
    body.appendChild(famField('Last name', last));
    body.appendChild(famField('Email', email));
    body.appendChild(famField('Phone', phone));
    body.appendChild(famField('Relationship', rel));
    var cb = Dom.el('div', { style: 'display:flex;flex-direction:column;gap:7px;margin:6px 0 10px;font-size:13.5px;' });
    var l1 = Dom.el('label', { style: 'display:flex;gap:8px;align-items:center;cursor:pointer;' });
    l1.appendChild(pickup); l1.appendChild(Dom.el('span', {}, 'May collect the children'));
    var l2 = Dom.el('label', { style: 'display:flex;gap:8px;align-items:center;cursor:pointer;' });
    l2.appendChild(invite); l2.appendChild(Dom.el('span', {}, 'Send them a welcome email now'));
    cb.appendChild(l1); cb.appendChild(l2);
    body.appendChild(cb);
    var msg = Dom.el('div', { style: 'font-size:12.5px;color:#B91C1C;min-height:16px;' });
    body.appendChild(msg);

    Shell.Modal.open({
      title: 'Add guardian',
      body: body,
      actions: [{
        label: 'Add guardian', primary: true,
        onClick: async function () {
          msg.textContent = '';
          if (!first.value.trim() || !last.value.trim() || !email.value.trim()) {
            msg.textContent = 'First name, last name and email are needed.'; return;
          }
          try {
            await Api.post('/director/families/' + family.id + '/invite', {
              email: email.value.trim(),
              first_name: first.value.trim(),
              last_name: last.value.trim(),
              relationship: rel.value,
              is_primary: false,
              can_pickup: pickup.checked,
              can_receive_billing: false,
              send_email: invite.checked,
            });
            if (Dom.toast) { Dom.toast(first.value.trim() + ' added as a guardian', 'success'); }
            onDone && onDone();
          } catch (e) {
            msg.textContent = (e.message || 'Could not add the guardian')
              + (e.errors ? ' — ' + Object.values(e.errors).flat().join(', ') : '');
          }
        },
      }],
    });
  }

  /** Emergency contacts could only be set while creating the family. Now they can be added. */
  function showAddEmergencyContact(family, onDone) {
    var body = Dom.el('div', {});
    body.appendChild(Dom.el('p', {
      style: 'margin:0 0 14px;font-size:13.5px;color:var(--ink-500);',
    }, 'Someone to ring when the guardians cannot be reached. This appears on the child\'s emergency card.'));
    var name = famInput({ placeholder: 'Full name' });
    var rel = famInput({ placeholder: 'Relationship (e.g. Grandmother)' });
    var phone = famInput({ placeholder: 'Phone' });
    var alt = famInput({ placeholder: 'Alternate phone (optional)' });
    var notes = famInput({ placeholder: 'Notes (optional)' });
    var pickup = Dom.el('input', { type: 'checkbox' });

    body.appendChild(famField('Name', name));
    body.appendChild(famField('Relationship', rel));
    body.appendChild(famField('Phone', phone));
    body.appendChild(famField('Alternate phone', alt));
    body.appendChild(famField('Notes', notes));
    var l = Dom.el('label', { style: 'display:flex;gap:8px;align-items:center;cursor:pointer;font-size:13.5px;margin-bottom:10px;' });
    l.appendChild(pickup); l.appendChild(Dom.el('span', {}, 'May collect the children'));
    body.appendChild(l);
    var msg = Dom.el('div', { style: 'font-size:12.5px;color:#B91C1C;min-height:16px;' });
    body.appendChild(msg);

    Shell.Modal.open({
      title: 'Add emergency contact',
      body: body,
      actions: [{
        label: 'Add contact', primary: true,
        onClick: async function () {
          msg.textContent = '';
          if (!name.value.trim()) { msg.textContent = 'A name is needed.'; return; }
          try {
            await Api.post('/admin/families/' + family.id + '/emergency-contacts', {
              name: name.value.trim(),
              relationship: rel.value.trim() || null,
              phone: phone.value.trim() || null,
              alt_phone: alt.value.trim() || null,
              notes: notes.value.trim() || null,
              can_pickup: pickup.checked,
            });
            if (Dom.toast) { Dom.toast('Emergency contact added', 'success'); }
            onDone && onDone();
          } catch (e) {
            msg.textContent = (e.message || 'Could not add the contact')
              + (e.errors ? ' — ' + Object.values(e.errors).flat().join(', ') : '');
          }
        },
      }],
    });
  }

  /**
   * The notes thread: who wrote it, and when.
   *
   * Append-only. A note is a record of what somebody knew at a point in time, and it
   * stops being that the moment anyone can rewrite it — so corrections are made by
   * adding another note, and only the author can remove their own within the hour.
   */
  async function renderFamilyNotes(host, familyId) {
    host.innerHTML = '<div style="font-size:13px;color:var(--ink-500);">Loading notes…</div>';

    var d;
    try { d = await Api.get('/admin/families/' + familyId + '/notes'); }
    catch (e) {
      host.innerHTML = '<div style="font-size:13px;color:#B45309;">Could not load notes.</div>';
      return;
    }

    var esc = function (v) {
      return String(v == null ? '' : v).replace(/[&<>"]/g, function (c) {
        return ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' })[c];
      });
    };
    var when = function (ts) {
      try {
        var dt = (window.KT && KT.Fmt && KT.Fmt.parse) ? KT.Fmt.parse(ts) : new Date(String(ts).replace(' ', 'T') + 'Z');
        return dt.toLocaleString('en-CA', { year: 'numeric', month: 'short', day: 'numeric',
                                            hour: 'numeric', minute: '2-digit' });
      } catch (e) { return String(ts || ''); }
    };

    var notes = d.notes || [];
    var list = notes.length
      ? notes.map(function (n) {
          return '<div style="background:' + (n.pinned ? '#FFFBEB' : 'var(--ink-50)')
            + ';border:1px solid ' + (n.pinned ? '#FDE68A' : 'transparent')
            + ';border-radius:8px;padding:10px 12px;margin-bottom:7px;">'
            + '<div style="font-size:13.5px;color:#0F172A;line-height:1.5;white-space:pre-wrap;">'
              + esc(n.body) + '</div>'
            + '<div style="font-size:11.5px;color:var(--ink-500);margin-top:6px;">'
              + (n.pinned ? '📌 ' : '') + esc(n.author) + ' · ' + esc(when(n.created_at)) + '</div>'
            + '</div>';
        }).join('')
      : '<div style="font-size:13px;color:var(--ink-500);margin-bottom:8px;">No notes yet.</div>';

    host.innerHTML = list
      + '<textarea id="fn-body" rows="2" maxlength="4000" placeholder="Add a note — it is saved with your name and the time."'
      + ' style="width:100%;box-sizing:border-box;padding:8px 10px;border:1.5px solid #CBD5E1;'
      + 'border-radius:8px;font-size:13.5px;font-family:inherit;resize:vertical;"></textarea>'
      + '<div style="display:flex;align-items:center;gap:10px;margin-top:7px;">'
        + '<button id="fn-add" class="kt-btn kt-btn-primary kt-btn-sm">Add note</button>'
        + '<label style="display:flex;gap:6px;align-items:center;font-size:12.5px;color:var(--ink-500);cursor:pointer;">'
          + '<input type="checkbox" id="fn-pin"> Pin to the top</label>'
        + '<span id="fn-msg" style="font-size:12.5px;color:#B91C1C;"></span>'
      + '</div>';

    host.querySelector('#fn-add').addEventListener('click', async function () {
      var box = host.querySelector('#fn-body');
      var text = String(box.value || '').trim();
      var m = host.querySelector('#fn-msg');
      if (!text) { m.textContent = 'Write something first.'; return; }
      this.disabled = true;
      try {
        await Api.post('/admin/families/' + familyId + '/notes', {
          body: text, pinned: host.querySelector('#fn-pin').checked,
        });
        renderFamilyNotes(host, familyId);
      } catch (e) {
        this.disabled = false;
        m.textContent = e.message || 'Could not save the note';
      }
    });
  }

  /**
   * Who has had this family's children, and when.
   *
   * Grouped by child rather than listed flat: a family reads this to follow one child's
   * path, and interleaving two children by date makes both harder to follow. The current
   * placement is marked, because "where are they now" is the question most often asked
   * of a history.
   */
  async function renderProviderHistory(host, familyId) {
    host.innerHTML = '<div style="font-size:13px;color:var(--ink-500);">Loading…</div>';

    var d;
    try { d = await Api.get('/admin/families/' + familyId + '/provider-history'); }
    catch (e) {
      host.innerHTML = '<div style="font-size:13px;color:#B45309;">Could not load provider history.</div>';
      return;
    }

    var rows = (d && d.history) || [];
    if (!rows.length) {
      host.innerHTML = '<div style="font-size:13px;color:var(--ink-500);">No provider history yet.</div>';
      return;
    }

    var esc = function (v) {
      return String(v == null ? '' : v).replace(/[&<>"]/g, function (c) {
        return ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' })[c];
      });
    };
    var day = function (v) {
      return (window.KT && KT.dayLabel) ? KT.dayLabel(v) : String(v || '');
    };

    var byChild = {};
    rows.forEach(function (r) { (byChild[r.child_name] = byChild[r.child_name] || []).push(r); });

    host.innerHTML = Object.keys(byChild).map(function (name) {
      var lines = byChild[name].map(function (r) {
        return '<div style="display:flex;gap:10px;align-items:baseline;padding:6px 0;'
          + (r.current ? 'border-left:3px solid #1F6080;padding-left:9px;margin-left:-9px;' : '') + '">'
          + '<span style="flex:1;min-width:0;font-size:13.5px;color:#0F172A;'
            + (r.current ? 'font-weight:700;' : '') + '">' + esc(r.provider || '—')
            + (r.current ? ' <span style="font-size:11px;font-weight:800;color:#1F6080;">CURRENT</span>' : '')
            + (r.days ? '<span style="font-weight:400;color:#64748B;"> · ' + esc(r.days.join(', ')) + '</span>' : '')
          + '</span>'
          + '<span style="font-size:12px;color:var(--ink-500);white-space:nowrap;">'
            + esc(day(r.start_date)) + ' → ' + (r.end_date ? esc(day(r.end_date)) : 'present')
            + (r.changed_by ? ' · ' + esc(r.changed_by) : '')
          + '</span></div>';
      }).join('');

      return '<div style="margin-bottom:12px;">'
        + '<div style="font-size:12px;font-weight:800;letter-spacing:.03em;color:var(--ink-500);'
          + 'text-transform:uppercase;margin-bottom:2px;">' + esc(name) + '</div>'
        + lines + '</div>';
    }).join('');
  }

  /* Which family the open edit session belongs to, not a bare boolean.
     A flat flag stayed on when you closed one family and opened the next, so the second
     record arrived already editable — the same trap the child record hit. Keyed by id,
     opening a different family is read-only again by construction. (2026-08-27) */
  var famEditFor = null;

  /* Incidents across every child in a family.
     Fetched after the modal is up and allowed to fail quietly: a director who
     cannot read incidents, or a slow query, should still get the family record. */
  function renderFamilyIncidents(host, familyId, archived) {
    Dom.clear(host);
    host.appendChild(Dom.el('div', {
      style: 'font-size:13px;color:var(--ink-500);',
    }, 'Loading…'));

    Api.get('/director/incidents?family_id=' + encodeURIComponent(familyId) + '&per_page=100')
      .then(function (res) {
        var rows = (res && (res.data || res.incidents)) || [];
        Dom.clear(host);
        if (!rows.length) {
          host.appendChild(Dom.el('div', {
            style: 'font-size:13px;color:var(--ink-500);',
          }, 'No incidents recorded for this family.'));
          return;
        }

        /* A count worth reading at a glance: three minor incidents across three
           children look like nothing on three separate screens. */
        var openN = 0, seriousN = 0;
        rows.forEach(function (i) {
          if (String(i.status || '') !== 'closed') { openN++; }
          if (i.is_serious_occurrence) { seriousN++; }
        });
        var bits = [rows.length + (rows.length === 1 ? ' incident' : ' incidents')];
        if (openN) { bits.push(openN + ' still open'); }
        if (seriousN) { bits.push(seriousN + ' serious occurrence' + (seriousN === 1 ? '' : 's')); }
        host.appendChild(Dom.el('div', {
          style: 'font-size:12.5px;color:' + (seriousN ? '#B3261E' : 'var(--ink-500)')
               + ';margin-bottom:8px;font-weight:' + (seriousN ? '700' : '400') + ';',
        }, bits.join('  ·  ')));

        var SEV = { low: '#15803D', medium: '#B45309', high: '#B3261E' };
        rows.forEach(function (inc) {
          /* occurred_at is a WALL CLOCK time typed by an educator -- printed as
             stored. Handing it to Date() lets kt-tz-global read it as UTC and
             shift it, which is how 08:15 once became 04:15 on a record of when a
             child was hurt. */
          var when = String(inc.occurred_at || '').replace('T', ' ').slice(0, 16);
          var kid = [inc.child && inc.child.first_name, inc.child && inc.child.last_name]
            .filter(Boolean).join(' ');
          var who = [inc.recorded_by && inc.recorded_by.first_name, inc.recorded_by && inc.recorded_by.last_name]
            .filter(Boolean).join(' ');

          var row = Dom.el('div', {
            style: 'display:flex;gap:12px;align-items:baseline;padding:9px 0;'
                 + 'border-top:1px solid var(--ink-100, #F1F5F9);cursor:pointer;',
          });
          row.appendChild(Dom.el('div', {
            style: 'flex:0 0 106px;font-size:12.5px;color:var(--ink-500);white-space:nowrap;',
          }, when || '—'));

          var mid = Dom.el('div', { style: 'flex:1;min-width:0;' });
          mid.appendChild(Dom.el('div', { style: 'font-weight:700;color:var(--ink-900, #0F172A);' },
            (kid ? kid + ' · ' : '')
            + String(inc.incident_type || '').replace(/_/g, ' ').replace(/\b\w/g, function (c) { return c.toUpperCase(); })
            + (inc.is_serious_occurrence ? ' · Serious occurrence' : '')));
          if (who || inc.location) {
            mid.appendChild(Dom.el('div', { style: 'font-size:12px;color:var(--ink-500);margin-top:1px;' },
              [inc.location, who ? 'recorded by ' + who : ''].filter(Boolean).join('  ·  ')));
          }
          row.appendChild(mid);

          row.appendChild(Dom.el('div', {
            style: 'flex:0 0 auto;font-size:11px;font-weight:800;text-transform:uppercase;'
                 + 'letter-spacing:.4px;color:' + (SEV[inc.severity] || 'var(--ink-500)') + ';',
          }, String(inc.severity || '')));
          row.appendChild(Dom.el('div', {
            style: 'flex:0 0 auto;font-size:11px;color:var(--ink-500);text-transform:uppercase;letter-spacing:.4px;',
          }, String(inc.status || '').replace(/_/g, ' ')));

          // view-only -- a record screen shows history, it does not act on it.
          row.addEventListener('click', function () {
            if (window.KT && KT.openIncidentDialog) {
              Shell.Modal.close();
              setTimeout(function () {
                KT.openIncidentDialog(inc.id, null, true, function () {
                  setTimeout(function () { showFamilyDetail(familyId, archived); }, 60);
                });
              }, 60);
              return;
            }
            Shell.Modal.close();
            window.location.hash = '#incident-detail?id=' + inc.id + '&view=1';
          });
          host.appendChild(row);
        });
      })
      .catch(function () {
        // Never let this take the family record down with it.
        Dom.clear(host);
        host.appendChild(Dom.el('div', {
          style: 'font-size:13px;color:var(--ink-500);',
        }, 'Incidents could not be loaded.'));
      });
  }

  /* Every filed document across this family's children, newest first.
     One request per child — a family has a handful, and there is no endpoint that
     takes a family. Fails quietly: a document list is not worth the record. */
  function renderFamilyDocuments(host, children) {
    Dom.clear(host);
    var kids = (children || []).filter(function (c) { return c && c.id; });
    if (!kids.length) {
      host.appendChild(Dom.el('div', {
        style: 'font-size:13px;color:var(--ink-500);',
      }, 'No children on this family, so no documents.'));
      return;
    }

    host.appendChild(Dom.el('div', { style: 'font-size:13px;color:var(--ink-500);' }, 'Loading…'));

    Promise.all(kids.map(function (c) {
      return Api.get('/director/children/' + c.id + '/documents')
        .then(function (r) {
          return ((r && r.documents) || []).map(function (d) { d.__child = c; return d; });
        })
        .catch(function () { return []; });   // one child's failure is not the family's
    })).then(function (lists) {
      var docs = [].concat.apply([], lists);
      docs.sort(function (a, b) { return String(b.created_at || '').localeCompare(String(a.created_at || '')); });

      Dom.clear(host);
      if (!docs.length) {
        host.appendChild(Dom.el('div', {
          style: 'font-size:13px;color:var(--ink-500);',
        }, 'No documents filed for this family.'));
        return;
      }

      var ICON = { incident_report: '🩹', agreement: '✍️', medical: '💊' };
      docs.forEach(function (d) {
        var kid = [d.__child.first_name, d.__child.last_name].filter(Boolean).join(' ');
        var row = Dom.el('div', {
          style: 'display:flex;gap:11px;align-items:center;padding:9px 0;'
               + 'border-top:1px solid var(--ink-100, #F1F5F9);',
        });
        row.appendChild(Dom.el('div', { style: 'flex:0 0 auto;font-size:17px;' },
          ICON[d.category] || '📄'));

        var mid = Dom.el('div', { style: 'flex:1;min-width:0;' });
        mid.appendChild(Dom.el('div', {
          style: 'font-weight:600;color:var(--ink-900, #0F172A);font-size:13.5px;',
        }, d.title || 'Document'));
        mid.appendChild(Dom.el('div', {
          style: 'font-size:12px;color:var(--ink-500);margin-top:1px;',
        }, [kid, String(d.created_at || '').slice(0, 10)].filter(Boolean).join('  ·  ')));
        row.appendChild(mid);

        /* file_url arrives already signed — SignProtectedMedia rewrites protected
           /storage paths on the way out, so this link works and expires. */
        if (d.file_url) {
          var a = Dom.el('a', {
            href: d.file_url, target: '_blank', rel: 'noopener',
            style: 'flex:0 0 auto;font-size:12.5px;font-weight:700;color:#1F6080;text-decoration:none;',
          }, 'Open');
          row.appendChild(a);
        }
        host.appendChild(row);
      });
    });
  }

  async function showFamilyDetail(familyId, archived) {
    Shell.Modal.open({
      title: archived ? 'Family details (archived)' : 'Family details',
      body: loading('Loading...'),
      large: true,
    });
    try {
      /* A de-enrolled family is retained, not deleted, so the record is still
         readable — but only when asked for explicitly, so no ordinary screen
         starts showing departed families by accident. (2026-08-25) */
      const data = await Api.get('/admin/families/' + familyId + (archived ? '?archived=1' : ''));
      const body = Dom.el('div', {});

      /* ESC-SCOPE: the departure block below builds raw HTML and calls esc(), but this
         function never had one — every archived family with departure details opened as
         an "esc is not defined" error modal instead of a record. (2026-08-30) */
      var esc = function (v) {
        return String(v == null ? '' : v).replace(/[&<>"]/g, function (c) {
          return ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' })[c];
        });
      };

      /* A de-enrolled family is history and can never be edited, whatever the toggle
         says — the banner already promises the reader as much. */
      var _canEdit = !data.is_archived && famEditFor === familyId;

      var _f = data.family || {};
      if (data.is_archived) {
        body.appendChild(Dom.el('div', {
          style: 'background:#FFF7ED;border:1px solid #FED7AA;border-radius:10px;padding:11px 14px;margin-bottom:12px;font-size:13px;color:#9A3412;line-height:1.55;',
        }, '\ud83d\uddc4\ufe0f This family has been de-enrolled'
           + (data.departed_at ? ' (' + String(data.departed_at).slice(0, 10) + ')' : '')
           + '. The record is kept for your retention period and is read-only history.'));

        /* RESTORE-UI: the way back.

           There was none — nothing in the system cleared families.deleted_at — so a
           returning family had to be typed in again from scratch, splitting the child's
           attendance, logs, invoices and documents across two records. Two iLearn
           families are in exactly that state. (2026-08-30) */
        var _restoreWrap = Dom.el('div', { style: 'margin:-4px 0 14px;' });
        var _restoreBtn = Dom.el('button', {
          type: 'button', class: 'btn',
          style: 'background:#0F766E;color:#fff;border:0;border-radius:8px;'
               + 'padding:7px 14px;font-size:13px;font-weight:600;cursor:pointer;',
        }, '\u21a9\ufe0f Bring this family back');
        _restoreBtn.addEventListener('click', async function () {
          if (window.KT && KT.confirm && !await KT.confirm(
            'Bring this family back?\n'
            + '  - The family record becomes active again\n'
            + '  - Their children return to enrolled, in the rooms they left\n'
            + '  - Guardian logins are restored\n\n'
            + 'Their whole history comes with them. Check room placement and billing '
            + 'afterwards.')) { return; }
          _restoreBtn.disabled = true;
          _restoreBtn.textContent = 'Restoring…';
          try {
            var _rr = await Api.post('/admin/families/' + familyId + '/restore', {});
            if (window.KT.Dom && KT.Dom.toast) {
              KT.Dom.toast('Family restored — ' + (_rr.children_restored || 0)
                + ' child(ren) re-enrolled', 'success');
            }
            Shell.Modal.close();
            // Reopen as an ACTIVE family, not the archived view it was.
            showFamilyDetail(familyId, false);
          } catch (e) {
            _restoreBtn.disabled = false;
            _restoreBtn.textContent = '\u21a9\ufe0f Bring this family back';
            if (window.KT.Dom && KT.Dom.toast) { KT.Dom.toast('Could not restore: ' + (e.message || 'error'), 'error'); }
            else { alert('Could not restore: ' + (e.message || 'error')); }
          }
        });
        _restoreWrap.appendChild(_restoreBtn);
        body.appendChild(_restoreWrap);

        /* Who, when and why — on the record itself. It was only ever in the audit log,
           which you have to know to go and look at. (2026-08-27) */
        var _dep = data.departure;
        if (_dep) {
          var _fact = function (k, v, tone) {
            if (!v) { return ''; }
            return '<div style="display:flex;gap:8px;padding:2px 0;">'
              + '<span style="color:#9A3412;flex:0 0 88px;">' + esc(k) + '</span>'
              + '<span style="color:' + (tone || '#7C2D12') + ';font-weight:600;">'
              + esc(v) + '</span></div>';
          };
          var _when = '';
          if (_dep.recorded_at && window.KT && KT.fmtDateTime) {
            try { _when = KT.fmtDateTime(_dep.recorded_at); } catch (e) {}
          }
          var _lastDay = _dep.last_day && window.KT && KT.dayLabel
            ? KT.dayLabel(_dep.last_day) : (_dep.last_day || '');
          var _html = _fact('Last day', _lastDay)
            + _fact('Reason', _dep.reason || 'Not recorded',
                    _dep.reason ? '#7C2D12' : '#B45309')
            + _fact('De-enrolled by', _dep.by_name || 'Not recorded',
                    _dep.by_name ? '#7C2D12' : '#B45309')
            + _fact('Recorded', _when);
          if (_html) {
            body.appendChild(Dom.el('div', {
              class: 'kt-departure-facts',
              style: 'background:#FFF7ED;border:1px solid #FED7AA;border-top:none;'
                   + 'border-radius:0 0 10px 10px;margin:-12px 0 12px;padding:10px 14px 12px;'
                   + 'font-size:13px;line-height:1.6;',
              html: _html,
            }));
          }
        }
      }
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

      /* Archived: no actions at all. Sending a welcome to closed accounts, or offering to
         edit history, are both meaningless — and showing the buttons implied otherwise. */
      if (!data.is_archived) {
        var _toggle = Dom.el('button', {
          style: 'padding:8px 14px;border-radius:8px;font-weight:700;font-size:13px;'
               + 'cursor:pointer;flex:0 0 auto;border:'
               + (_canEdit ? 'none;background:#166534;color:#fff;'
                           : '1.5px solid #1F6080;background:#fff;color:#1F6080;'),
        }, _canEdit ? '\u2713 Done editing' : '\u270f\ufe0f Edit');
        _toggle.addEventListener('click', function () {
          famEditFor = _canEdit ? null : familyId;
          Shell.Modal.close();
          setTimeout(function () { showFamilyDetail(familyId, archived); }, 60);
        });

        if (_canEdit) {
          // The field-level form for the family's own details. Only offered once they
          // have said they are editing, so View stays a single, quiet screen.
          _edit.textContent = '\u270f\ufe0f Edit details\u2026';
          _btns.appendChild(_edit);
        } else {
          _btns.appendChild(_resend);
        }
        _btns.appendChild(_toggle);
      }

      _hd.appendChild(_btns);
      body.appendChild(_hd);

      if (_canEdit) {
        body.appendChild(Dom.el('div', {
          style: 'background:#F0FDF4;border:1px solid #BBF7D0;border-radius:8px;'
               + 'padding:8px 12px;margin:0 0 10px;font-size:12.5px;color:#166534;',
        }, 'Editing \u2014 you can add children, guardians and contacts, set kiosk PINs, '
           + 'and remove guardians. Press Done when you have finished.'));
      }
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

      /* Where they actually are. Useful when arranging a home visit or working out whose
         route a child is on, and it costs nothing when the address is missing — the map
         simply does not render rather than showing an empty grey box. */
      if (_addr) {
        var _mapHost = Dom.el('div', { style: 'margin:10px 0 4px;' });
        body.appendChild(_mapHost);
        try {
          if (window.KT && KT.MiniMap) {
            KT.MiniMap.render(_mapHost, _addr, { label: _f.family_name || 'Family', height: 190 });
          }
        } catch (e) { /* a map is never worth breaking the record for */ }
      }

      body.appendChild(famSectionHead('CHILDREN', _canEdit ? '＋ Add child' : null, _canEdit ? function () {
        showAddChildToFamily(data.family, function () { Shell.Modal.close(); showFamilyDetail(familyId, archived); });
      } : null));
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

      body.appendChild(famSectionHead('GUARDIANS', _canEdit ? '＋ Add guardian' : null, _canEdit ? function () {
        showAddGuardian(data.family, function () { Shell.Modal.close(); showFamilyDetail(familyId, archived); });
      } : null));
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
        if (_canEdit && g.can_pickup && g.id) {
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
        /* Unlinking, not deleting: the same person may be a guardian of another family
           or staff here, so their ACCOUNT is never touched. The API refuses to remove a
           family's last guardian. */
        if (_canEdit) {
        var _rm = Dom.el('button', {
          type: 'button', title: 'Remove from this family',
          style: 'flex:0 0 auto;background:none;border:1px solid #FECACA;color:#B91C1C;'
               + 'border-radius:6px;padding:4px 9px;font-size:12px;font-weight:700;cursor:pointer;',
        }, 'Remove');
        _rm.addEventListener('click', async function () {
          var who = ((g.first_name || '') + ' ' + (g.last_name || '')).trim();
          var ok = !(window.KT && KT.confirm) || await KT.confirm({
            title: 'Remove ' + who + ' from this family?',
            description: 'They stop being a guardian here and lose access to these children. '
              + 'Their account stays — this does not delete the person.',
            okLabel: 'Remove',
          });
          if (!ok) { return; }
          _rm.disabled = true;
          try {
            await Api.delete('/admin/families/' + familyId + '/guardians/' + g.id);
            if (Dom.toast) { Dom.toast(who + ' removed from this family', 'success'); }
            Shell.Modal.close();
            showFamilyDetail(familyId, archived);
          } catch (err) {
            _rm.disabled = false;
            if (Dom.toast) { Dom.toast(err.message || 'Could not remove', 'error'); }
          }
        });
        row.appendChild(_rm);
        }   /* /_canEdit — unlinking a parent is not a thing a "view" should offer */

        body.appendChild(row);
      });

      // Emergency contacts — previously not shown in the family view at all.
      var _ecs = data.emergency_contacts || [];
      body.appendChild(famSectionHead('EMERGENCY CONTACTS', _canEdit ? '＋ Add contact' : null, _canEdit ? function () {
        showAddEmergencyContact(data.family, function () { Shell.Modal.close(); showFamilyDetail(familyId, archived); });
      } : null));
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

      /* Every incident across this family's children, newest first. Read-only --
         a row opens the incident in view mode, where the actions live. */
      body.appendChild(famSectionHead('INCIDENTS', null, null));
      var _incHost = Dom.el('div', {});
      body.appendChild(_incHost);
      renderFamilyIncidents(_incHost, familyId, data.is_archived);

      /* Filed documents, gathered from this family's children. Read from the
         child records rather than duplicated onto the family, so there is one
         copy of each file and nothing to keep in step. */
      body.appendChild(famSectionHead('DOCUMENTS', null, null));
      var _docHost = Dom.el('div', {});
      body.appendChild(_docHost);
      renderFamilyDocuments(_docHost, (data.children || []).map(function (c) { return c; }));

      /* Notes, with who wrote them and when. `families.notes` is still shown above as a
         single field, but anything written from here is attributed and kept. */
      body.appendChild(famSectionHead('PROVIDER HISTORY', null, null));
      var _phHost = Dom.el('div', {});
      body.appendChild(_phHost);
      renderProviderHistory(_phHost, familyId);

      body.appendChild(famSectionHead('NOTES', null, null));
      var _notesHost = Dom.el('div', {});
      body.appendChild(_notesHost);
      renderFamilyNotes(_notesHost, familyId);

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

  /** Human sentence describing what happened to someone's invite.
      Returns '' when there is nothing useful to say, so the badge stays plain. */
  /* Onboarding + welcome-email state as one small badge.

     Two questions get asked about every new account and neither had an answer on
     the list: did this person actually finish onboarding, and did the welcome
     email reach them? Status answers neither — an account reads "active" from the
     moment it is claimed, finished wizard or not, which is exactly how someone can
     sit at "active" for a week having never completed it. The hover carries the
     delivery detail so the badge itself can stay short. */
  function onbBadge(label, bg, fg, tip) {
    return Dom.el('span', {
      title: tip || '',
      style: 'display:inline-block;padding:3px 9px;border-radius:20px;font-size:11px;'
        + 'font-weight:700;white-space:nowrap;background:' + bg + ';color:' + fg + ';'
        + (tip ? 'cursor:help;' : ''),
    }, label);
  }

  function onbWhen(v) {
    if (!v) { return ''; }
    try {
      if (window.KT && KT.Fmt && KT.Fmt.dateTime) { return KT.Fmt.dateTime(v); }
    } catch (e) {}
    return String(v).replace('T', ' ').slice(0, 16);
  }

  /** One user's onboarding state, as a table cell. */
  function userOnboardingCell(u) {
    var td = Dom.el('td', { style: 'padding: 14px 16px;' });
    var i = (u && u.invite) || {};
    var tip = inviteTip(u);
    if (u && u.onboarded_at) {
      td.appendChild(onbBadge('Onboarded', '#DCFCE7', '#166534',
        'Finished onboarding ' + onbWhen(u.onboarded_at) + (tip ? '\n\n' + tip : '')));
      return td;
    }
    if (i.state === 'blocked') {
      td.appendChild(onbBadge('Email blocked', '#FEE2E2', '#991B1B', tip));
    } else if (i.state === 'never_sent') {
      td.appendChild(onbBadge('No invite sent', '#F1F5F9', '#64748B', tip));
    } else if (i.state === 'opened') {
      td.appendChild(onbBadge('Invite opened', '#FEF3C7', '#92400E', tip));
    } else {
      td.appendChild(onbBadge('Not onboarded', '#E0F2FE', '#075985', tip));
    }
    return td;
  }

  /** A family's onboarding state — counted across its guardians. */
  function familyOnboardingBadge(f) {
    var o = (f && f.onboarding) || null;
    if (!o || !o.guardians) {
      return onbBadge('No guardians', '#F1F5F9', '#64748B', 'This family has nobody to invite yet.');
    }
    var tip = o.onboarded + ' of ' + o.guardians + ' guardian'
      + (o.guardians === 1 ? '' : 's') + ' finished onboarding.';
    if (o.welcome_issue === 'suppressed') { tip += '\nThe welcome email was blocked before it was delivered.'; }
    else if (o.welcome_issue === 'failed') { tip += '\nThe welcome email failed to send.'; }
    else if (o.welcome_issue === 'never_sent') { tip += '\nNo welcome email has been sent to this family.'; }
    else if (o.welcome_at) { tip += '\nWelcome email sent ' + onbWhen(o.welcome_at) + '.'; }

    if (o.onboarded >= o.guardians) {
      return onbBadge('Onboarded', '#DCFCE7', '#166534', tip);
    }
    if (o.welcome_issue === 'suppressed' || o.welcome_issue === 'failed') {
      return onbBadge(o.welcome_issue === 'failed' ? 'Email failed' : 'Email blocked', '#FEE2E2', '#991B1B', tip);
    }
    if (o.welcome_issue === 'never_sent') {
      return onbBadge('No welcome sent', '#F1F5F9', '#64748B', tip);
    }
    return onbBadge(o.onboarded + ' of ' + o.guardians + ' onboarded', '#FEF3C7', '#92400E', tip);
  }

  function inviteTip(u) {
    var i = u && u.invite;
    if (!i) { return ''; }
    var when = function (v) {
      if (!v) { return ''; }
      try {
        if (window.KT && KT.Fmt && KT.Fmt.dateTime) { return KT.Fmt.dateTime(v); }
      } catch (e) {}
      return String(v).replace('T', ' ').slice(0, 16);
    };

    if (i.state === 'never_sent') { return 'No invite has ever been sent to this address.'; }
    if (i.state === 'blocked') {
      return 'Invite was blocked before delivery (' + when(i.sent_at) + ').\nIt never left the system.';
    }

    var out = 'Invite sent ' + when(i.sent_at)
      + (i.count > 1 ? '  \u00B7  ' + i.count + ' sent in total' : '');
    if (i.state === 'opened') {
      out += '\nOpened ' + when(i.opened_at) + (i.opens > 1 ? '  \u00B7  ' + i.opens + ' times' : '');
    } else {
      /* Never claim it went unread. Open tracking is a pixel and most mail apps block
         images, so the large majority of real opens are never recorded. */
      out += '\nNo open recorded \u2014 but most mail apps block the tracking'
           + '\npixel, so this is not proof it went unread.';
    }
    return out;
  }

  /** The badge, with a hover explanation attached. */
  function badgeWithTip(colours, label, tip) {
    var el = Dom.el('span', {
      style: 'display:inline-block;background:' + colours[0] + ';color:' + colours[1]
        + ';padding:3px 10px;border-radius:12px;font-size:11px;font-weight:700;'
        + 'text-transform:capitalize;cursor:help;border-bottom:1px dotted currentColor;',
    }, label);
    el.setAttribute('title', tip);
    return el;
  }

  function statusBadge(status, tip) {
    /* Accepts a status string OR a whole user row. Handing it the row lets the badge say
       WHY an account is closed: 'deactivated' reads the same for an admin switching
       someone off and for a family that left in August, and those need different
       follow-up. Centre lists still pass a bare string and are unaffected. */
    var u = null;
    if (status && typeof status === 'object') { u = status; status = u.status || 'active'; }
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
    var c = colors[status] || ['#F3F4F6', '#374151'];
    var label = labels[status] || status;

    /* A departure outranks the generic word. Rose rather than grey, because a de-boarded
       account is a record of something that happened rather than a switch someone can
       flip back -- restoring it means restoring the family. */
    if (u && u.departed_on && (status === 'deactivated' || status === 'suspended')) {
      label = 'De-boarded';
      c = ['#FFE4E6', '#9F1239'];
      /* Date-only strings must be split into numeric parts. new Date('2026-08-30') is
         parsed as UTC and, west of Greenwich, prints the day before. */
      var d = String(u.departed_on).slice(0, 10).split('-');
      var when = (d.length === 3 && d[0].length === 4)
        ? new Date(+d[0], +d[1] - 1, +d[2]).toLocaleDateString(undefined, { day: 'numeric', month: 'short', year: 'numeric' })
        : String(u.departed_on).slice(0, 10);
      tip = (u.departed_from ? ('The ' + u.departed_from + ' family') : 'Their family')
        + ' left on ' + when + '. The account was closed with the de-boarding.';
    }

    if (tip) { return badgeWithTip(c, label, tip); }
    return Dom.el('span', {
      style: 'display: inline-block; background: ' + c[0] + '; color: ' + c[1] + '; padding: 3px 10px; border-radius: 12px; font-size: 11px; font-weight: 700; letter-spacing: 0.5px; text-transform: uppercase;',
    }, label);
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
