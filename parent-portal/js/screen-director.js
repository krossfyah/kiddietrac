/* ═══════════════════════════════════════════════════════════════════
   KIDDIETRAC v2 — Director Screen Module
   Registered as: agency_admin:* and centre_director:*
   ═══════════════════════════════════════════════════════════════════ */

(function (window) {
  'use strict';

  const { Api, Fmt, Dom, Shell } = window.KT;
  const { Modal, navigate, emptyState, statusTagFor } = Shell;

  // ──────────────────────────────────────────────────────────────
  // ROOT — every director hash routes through here so we render the
  // sidebar consistently
  // ──────────────────────────────────────────────────────────────
  async function renderDirectorRoot(main, ctx, contentRenderer) {
    Dom.clear(main);

    const shell = Dom.el('div', { class: 'director-shell' });

    // Sidebar
    shell.appendChild(buildSidebar(ctx));

    // Content area
    const content = Dom.el('div', { class: 'director-content' });
    shell.appendChild(content);
    main.appendChild(shell);

    // Render the actual content
    await contentRenderer(content, ctx);
  }

  function buildSidebar(ctx) {
    const current = (window.location.hash || '#dashboard').replace('#', '').split('?')[0];

    const sidebar = Dom.el('aside', { class: 'director-sidebar' });

    const sections = [
      { label: 'Manage', items: [
        { hash: 'dashboard', icon: '📊', label: 'Dashboard' },
        { hash: 'children',  icon: '👶', label: 'Children' },
        { hash: 'families',  icon: '👪', label: 'Families' },
        { hash: 'staff',     icon: '👩‍🏫', label: 'Staff' },
      ]},
      { label: 'Today', items: [
        { hash: 'today',     icon: '🟢', label: 'Live roster' },
        { hash: 'compliance', icon: '✅', label: 'Compliance' },
      ]},
    ];

    sections.forEach(section => {
      sidebar.appendChild(Dom.el('div', { class: 'sidebar-label' }, section.label));
      section.items.forEach(item => {
        const btn = Dom.el('button', {
          class: 'sidebar-link' + (current === item.hash ? ' active' : ''),
          onClick: () => navigate(item.hash),
        },
          Dom.el('span', { class: 'sidebar-icon' }, item.icon),
          item.label
        );
        sidebar.appendChild(btn);
      });
    });

    return sidebar;
  }

  // ──────────────────────────────────────────────────────────────
  // DASHBOARD
  // ──────────────────────────────────────────────────────────────
  async function renderDashboard(content, ctx) {
    content.innerHTML = '<div class="loading-state"><div class="spinner"></div></div>';

    try {
      const data = await Api.get('/director/dashboard');
      Dom.clear(content);

      // v22p12.1: hero card with greeting + centre name + clouds illustration.
      let firstName = '';
      try {
        var stored = JSON.parse(sessionStorage.getItem('kt_user') || '{}');
        firstName = (stored.first_name || stored.name || '').split(' ')[0];
      } catch (e) { /* noop */ }
      var greet = (window.KT && window.KT.greetingForNow)
        ? window.KT.greetingForNow(firstName)
        : 'Welcome' + (firstName ? ', ' + firstName : '');
      var clouds = (window.KT && window.KT.Illustrations && window.KT.Illustrations.cloudsAndStars)
        ? window.KT.Illustrations.cloudsAndStars() : '';
      var heroSubBits = [];
      if (data.centre?.address) heroSubBits.push(data.centre.address);
      if (data.centre?.license_number) heroSubBits.push('License ' + data.centre.license_number);
      var hero = Dom.el('div', { class: 'kt-hero' });
      hero.innerHTML =
        '<div class="kt-hero-greet">' + greet.replace(/[<>&]/g, function(c){return {'<':'&lt;','>':'&gt;','&':'&amp;'}[c];}) + ' 👋</div>' +
        '<h1>' + (data.centre?.name || 'Dashboard').replace(/[<>&]/g, function(c){return {'<':'&lt;','>':'&gt;','&':'&amp;'}[c];}) + '</h1>' +
        (heroSubBits.length ? '<div class="kt-hero-sub">' + heroSubBits.join(' · ').replace(/[<>&]/g, function(c){return {'<':'&lt;','>':'&gt;','&':'&amp;'}[c];}) + '</div>' : '') +
        '<div class="kt-hero-svg">' + clouds + '</div>';
      content.appendChild(hero);

      // Stats tiles
      const stats = data.stats;
      const tiles = Dom.el('div', { class: 'director-stats' });

      tiles.appendChild(statTile({
        label: 'Enrolled', value: stats.total_enrolled,
        detail: `${stats.utilization_pct}% of ${stats.capacity} capacity`,
        flavor: stats.utilization_pct > 90 ? 'warn' : 'good',
      }));
      tiles.appendChild(statTile({
        label: 'Here right now', value: stats.present_now,
        detail: 'children checked in',
        flavor: 'good',
      }));
      tiles.appendChild(statTile({
        label: 'Staff on floor', value: stats.staff_on_floor,
        detail: 'educators clocked in',
        flavor: stats.staff_on_floor === 0 ? 'warn' : 'good',
      }));
      tiles.appendChild(statTile({
        label: 'Receivables', value: Fmt.money(stats.receivables),
        detail: 'outstanding invoices',
        flavor: stats.receivables > 0 ? 'warn' : 'good',
      }));
      if (stats.incidents_to_review > 0) {
        tiles.appendChild(statTile({
          label: 'Needs review', value: stats.incidents_to_review,
          detail: 'incident' + (stats.incidents_to_review === 1 ? '' : 's'),
          flavor: 'bad',
        }));
      }
      if (stats.expiring_certifications > 0) {
        tiles.appendChild(statTile({
          label: 'Cert expiring', value: stats.expiring_certifications,
          detail: 'within 60 days',
          flavor: 'warn',
        }));
      }
      content.appendChild(tiles);

      // Room grid
      content.appendChild(Dom.el('div', { class: 'section-header' },
        Dom.el('h2', { style: 'font-size: 20px;' }, 'Rooms')
      ));

      const roomGrid = Dom.el('div', { class: 'room-grid' });
      if (data.rooms.length === 0) {
        roomGrid.appendChild(emptyState('🏫', 'No rooms set up', 'Add rooms to start enrolling children.'));
      } else {
        data.rooms.forEach(room => roomGrid.appendChild(roomTile(room)));
      }
      content.appendChild(roomGrid);

    } catch (e) {
      Dom.clear(content);
      content.appendChild(emptyState('⚠️', 'Could not load dashboard', e.message));
    }
  }

  function statTile({ label, value, detail, flavor }) {
    return Dom.el('div', { class: 'stat-tile stat-' + (flavor || 'good') },
      Dom.el('div', { class: 'stat-tile-label' }, label),
      Dom.el('div', { class: 'stat-tile-value' }, String(value)),
      Dom.el('div', { class: 'stat-tile-detail' }, detail),
    );
  }

  function roomTile(room) {
    const status = statusTagFor(room.status);

    const tile = Dom.el('div', {
      class: 'room-tile',
      onClick: () => navigate('children?room=' + room.room_id),
    });

    tile.appendChild(Dom.el('div', { class: 'room-tile-header' },
      Dom.el('div', { class: 'room-tile-name' },
        Dom.el('span', { class: 'room-color-dot', style: { background: room.color_hex || '#1F6080' } }),
        room.room_name,
      ),
      Dom.el('span', { class: 'ratio-status ' + room.status }, status.text),
    ));

    tile.appendChild(Dom.el('div', { class: 'room-tile-stats' },
      Dom.el('div', { class: 'room-tile-stat' },
        Dom.el('strong', {}, String(room.children_present)),
        'children present',
      ),
      Dom.el('div', { class: 'room-tile-stat' },
        Dom.el('strong', {}, `${room.educators_present} / ${room.required_educators || 0}`),
        'educators (needed)',
      ),
    ));

    tile.appendChild(Dom.el('div', {
      style: 'font-size: 12px; color: var(--kt-text-muted);'
    }, `Capacity: ${room.utilization_pct}% · Ratio ${room.ratio_target}`));

    return tile;
  }

  // ──────────────────────────────────────────────────────────────
  // CHILDREN
  // ──────────────────────────────────────────────────────────────
  async function renderChildren(content, ctx) {
    content.innerHTML = '<div class="loading-state"><div class="spinner"></div></div>';

    try {
      const data = await Api.get('/director/enrollments', ctx.params);
      Dom.clear(content);

      content.appendChild(Dom.el('div', { class: 'section-header' },
        Dom.el('h2', {}, 'Children'),
        Dom.el('button', {
          class: 'btn btn-primary',
          onClick: () => openEnrollModal(() => Shell.renderScreen()),
        }, '+ Enroll a child'),
      ));

      content.appendChild(buildFilterBar(ctx, () => Shell.renderScreen()));

      if (data.children.length === 0) {
        content.appendChild(emptyState('👶', 'No children yet',
          'Click "Enroll a child" to add the first one.'));
        return;
      }

      const table = Dom.el('table', { class: 'data-table' });
      table.appendChild(Dom.el('thead', {},
        Dom.el('tr', {},
          Dom.el('th', {}, 'Child'),
          Dom.el('th', {}, 'Age'),
          Dom.el('th', {}, 'Room'),
          Dom.el('th', {}, 'Family'),
          Dom.el('th', {}, 'Monthly fee'),
          Dom.el('th', {}, 'Status'),
        )
      ));

      const tbody = Dom.el('tbody', {});
      data.children.forEach(c => {
        const row = Dom.el('tr', {
          style: 'cursor: pointer;',
          onClick: () => navigate(`child-detail?id=${c.id}`),
        });

        row.appendChild(Dom.el('td', {},
          Dom.el('div', { class: 'cell-with-avatar' },
            avatarCircle(c.display_name, c.room_color),
            Dom.el('div', {},
              Dom.el('div', { style: 'font-weight: 600;' }, c.full_name),
              Dom.el('div', { style: 'font-size: 12px; color: var(--kt-text-muted);' },
                c.preferred_name && c.preferred_name !== c.first_name ? `"${c.preferred_name}"` : ''),
            ),
          ),
        ));
        row.appendChild(Dom.el('td', {}, c.age?.human || '—'));
        row.appendChild(Dom.el('td', {}, c.room_name || '—'));
        row.appendChild(Dom.el('td', {}, (c.family_name || '').replace(/^\[Demo\] /, '')));
        row.appendChild(Dom.el('td', {}, c.monthly_fee ? Fmt.money(c.monthly_fee) : '—'));
        row.appendChild(Dom.el('td', {},
          c.is_at_centre
            ? Dom.el('span', { class: 'tag tag-success' }, 'AT CENTRE')
            : Dom.el('span', { class: 'tag tag-info' }, c.enrollment_status?.toUpperCase() || 'ENROLLED')
        ));

        tbody.appendChild(row);
      });
      table.appendChild(tbody);

      content.appendChild(table);

    } catch (e) {
      Dom.clear(content);
      content.appendChild(emptyState('⚠️', 'Could not load children', e.message));
    }
  }

  function buildFilterBar(ctx, refresh) {
    const wrap = Dom.el('div', { class: 'filter-bar' });
    const search = Dom.el('input', {
      class: 'search-input',
      placeholder: 'Search by name or family…',
      value: ctx.params.search || '',
    });
    let timer = null;
    search.addEventListener('input', () => {
      clearTimeout(timer);
      timer = setTimeout(() => {
        const params = { ...ctx.params, search: search.value || undefined };
        const qs = Object.entries(params).filter(([_, v]) => v != null && v !== '').map(([k, v]) => `${k}=${encodeURIComponent(v)}`).join('&');
        window.location.hash = '#children' + (qs ? '?' + qs : '');
      }, 300);
    });
    wrap.appendChild(search);
    return wrap;
  }

  // ─── Enroll modal ─────────────────────────────────────────────
  async function openEnrollModal(onSuccess) {
    // Load families and rooms first
    let families = [], rooms = [];
    try {
      const [famRes, dashRes] = await Promise.all([
        Api.get('/director/families'),
        Api.get('/director/dashboard'),
      ]);
      families = famRes.data || [];
      rooms = dashRes.rooms || [];
    } catch (e) {
      Dom.toast('Could not load enrollment data: ' + e.message, 'error');
      return;
    }

    const form = Dom.el('div', { class: 'form-grid' });

    const fields = {};
    fields.first_name = textInput('First name', '', { required: true });
    fields.last_name = textInput('Last name', '', { required: true });
    fields.preferred_name = textInput('Preferred name (optional)', '');
    fields.date_of_birth = textInput('Date of birth', '', { type: 'date', required: true });
    fields.gender = selectInput('Gender', [
      ['prefer_not_to_say', 'Prefer not to say'],
      ['female', 'Female'],
      ['male', 'Male'],
      ['non_binary', 'Non-binary'],
      ['other', 'Other'],
    ]);
    fields.family_id = selectInput('Family', [
      ['', '— Select family —'],
      ...families.map(f => [f.id, (f.family_name || '').replace(/^\[Demo\] /, '')]),
    ], { required: true });
    fields.room_id = selectInput('Room', [
      ['', '— Select room —'],
      ...rooms.map(r => [r.room_id, r.room_name]),
    ], { required: true });
    fields.monthly_fee = textInput('Monthly fee (gross)', '1500', { type: 'number', required: true });
    fields.start_date = textInput('Enrollment start', new Date().toISOString().split('T')[0], { type: 'date', required: true });

    form.appendChild(fields.first_name.row);
    form.appendChild(fields.last_name.row);
    form.appendChild(fields.preferred_name.row);
    form.appendChild(fields.date_of_birth.row);
    form.appendChild(fields.gender.row);
    form.appendChild(fields.family_id.row);
    form.appendChild(fields.room_id.row);
    form.appendChild(fields.monthly_fee.row);
    fields.start_date.row.classList.add('full-width');
    form.appendChild(fields.start_date.row);

    Modal.open({
      title: 'Enroll a child',
      body: form,
      actions: [
        { label: 'Cancel', style: 'btn-secondary' },
        {
          label: 'Enroll',
          style: 'btn-primary',
          handler: async () => {
            const payload = {};
            for (const [k, f] of Object.entries(fields)) {
              const v = f.input.value;
              if (v !== '' && v != null) payload[k] = v;
            }
            payload.cwelcc_eligible = true;
            await Api.post('/director/enrollments', payload);
            Dom.toast('Child enrolled successfully!', 'success');
            if (onSuccess) onSuccess();
          },
        },
      ],
    });
  }

  // ──────────────────────────────────────────────────────────────
  // FAMILIES
  // ──────────────────────────────────────────────────────────────
  async function renderFamilies(content, ctx) {
    content.innerHTML = '<div class="loading-state"><div class="spinner"></div></div>';

    try {
      const data = await Api.get('/director/families');
      Dom.clear(content);

      content.appendChild(Dom.el('div', { class: 'section-header' },
        Dom.el('h2', {}, 'Families'),
        Dom.el('button', {
          class: 'btn btn-primary',
          onClick: () => openAddFamilyModal(() => Shell.renderScreen()),
        }, '+ Add family'),
      ));

      const families = data.data || data.families || [];

      if (families.length === 0) {
        content.appendChild(emptyState('👪', 'No families yet',
          'Add your first family to get started.'));
        return;
      }

      const table = Dom.el('table', { class: 'data-table' });
      table.appendChild(Dom.el('thead', {},
        Dom.el('tr', {},
          Dom.el('th', {}, 'Family'),
          Dom.el('th', {}, 'Primary contact'),
          Dom.el('th', {}, 'Children'),
          Dom.el('th', {}, ''),
        )
      ));

      const tbody = Dom.el('tbody', {});
      families.forEach(f => {
        const row = Dom.el('tr', {});
        row.appendChild(Dom.el('td', {},
          Dom.el('div', { style: 'font-weight: 600;' }, (f.family_name || '').replace(/^\[Demo\] /, '')),
        ));
        row.appendChild(Dom.el('td', {}, f.primary_email || '—'));
        row.appendChild(Dom.el('td', {}, String(f.children_count || 0)));
        row.appendChild(Dom.el('td', { style: 'text-align: right;' },
          Dom.el('button', {
            class: 'btn btn-secondary btn-sm',
            onClick: () => openInviteParentModal(f.id, f.family_name, () => Shell.renderScreen()),
          }, 'Invite parent'),
        ));
        tbody.appendChild(row);
      });
      table.appendChild(tbody);
      content.appendChild(table);

    } catch (e) {
      Dom.clear(content);
      content.appendChild(emptyState('⚠️', 'Could not load families', e.message));
    }
  }

  function openAddFamilyModal(onSuccess) {
    const fields = {};
    fields.family_name = textInput('Family name (e.g. "The Smith Family")', '', { required: true });
    fields.primary_email = textInput('Primary email', '', { type: 'email' });
    fields.primary_phone = textInput('Primary phone', '');
    fields.address_line1 = textInput('Address', '');
    fields.city = textInput('City', '');
    fields.postal_code = textInput('Postal code', '');

    const form = Dom.el('div', { class: 'form-grid' });
    fields.family_name.row.classList.add('full-width');
    form.appendChild(fields.family_name.row);
    form.appendChild(fields.primary_email.row);
    form.appendChild(fields.primary_phone.row);
    fields.address_line1.row.classList.add('full-width');
    form.appendChild(fields.address_line1.row);
    form.appendChild(fields.city.row);
    form.appendChild(fields.postal_code.row);

    Modal.open({
      title: 'Add a family',
      body: form,
      actions: [
        { label: 'Cancel' },
        {
          label: 'Add family',
          style: 'btn-primary',
          handler: async () => {
            const payload = {};
            for (const [k, f] of Object.entries(fields)) {
              if (f.input.value) payload[k] = f.input.value;
            }
            await Api.post('/director/families', payload);
            Dom.toast('Family added', 'success');
            if (onSuccess) onSuccess();
          },
        },
      ],
    });
  }

  function openInviteParentModal(familyId, familyName, onSuccess) {
    const fields = {};
    fields.email = textInput('Email', '', { type: 'email', required: true });
    fields.first_name = textInput('First name', '', { required: true });
    fields.last_name = textInput('Last name', '', { required: true });
    fields.relationship = selectInput('Relationship', [
      ['mother', 'Mother'],
      ['father', 'Father'],
      ['guardian', 'Guardian'],
      ['grandparent', 'Grandparent'],
      ['foster', 'Foster parent'],
      ['other', 'Other'],
    ]);

    const form = Dom.el('div', { class: 'form-grid' });
    form.appendChild(fields.first_name.row);
    form.appendChild(fields.last_name.row);
    fields.email.row.classList.add('full-width');
    form.appendChild(fields.email.row);
    fields.relationship.row.classList.add('full-width');
    form.appendChild(fields.relationship.row);

    Modal.open({
      title: `Invite parent to ${(familyName || '').replace(/^\[Demo\] /, '')}`,
      body: form,
      actions: [
        { label: 'Cancel' },
        {
          label: 'Send invite',
          style: 'btn-primary',
          handler: async () => {
            const payload = {
              email: fields.email.input.value,
              first_name: fields.first_name.input.value,
              last_name: fields.last_name.input.value,
              relationship: fields.relationship.input.value,
              is_primary: true,
              can_pickup: true,
              can_receive_billing: true,
            };
            await Api.post(`/director/families/${familyId}/invite`, payload);
            Dom.toast('Invitation sent', 'success');
            if (onSuccess) onSuccess();
          },
        },
      ],
    });
  }

  // ──────────────────────────────────────────────────────────────
  // STAFF
  // ──────────────────────────────────────────────────────────────
  async function renderStaff(content, ctx) {
    content.innerHTML = '<div class="loading-state"><div class="spinner"></div></div>';

    try {
      const data = await Api.get('/director/staff');
      Dom.clear(content);

      content.appendChild(Dom.el('div', { class: 'section-header' },
        Dom.el('h2', {}, 'Staff'),
        Dom.el('button', {
          class: 'btn btn-primary',
          onClick: () => Dom.toast('Staff invitation coming soon', 'info'),
        }, '+ Invite staff'),
      ));

      const staff = data.staff || [];

      if (staff.length === 0) {
        content.appendChild(emptyState('👩‍🏫', 'No staff yet',
          'Invite educators and directors to your centre.'));
        return;
      }

      const table = Dom.el('table', { class: 'data-table' });
      table.appendChild(Dom.el('thead', {},
        Dom.el('tr', {},
          Dom.el('th', {}, 'Name'),
          Dom.el('th', {}, 'Email'),
          Dom.el('th', {}, 'Role'),
          Dom.el('th', {}, 'Certifications'),
          Dom.el('th', {}, 'Status'),
        )
      ));

      const tbody = Dom.el('tbody', {});
      staff.forEach(s => {
        const row = Dom.el('tr', {});
        row.appendChild(Dom.el('td', {},
          Dom.el('div', { class: 'cell-with-avatar' },
            avatarCircle(s.first_name + ' ' + s.last_name),
            Dom.el('div', { style: 'font-weight: 600;' }, s.first_name + ' ' + s.last_name),
          ),
        ));
        row.appendChild(Dom.el('td', {}, s.email));
        row.appendChild(Dom.el('td', {}, formatRole(s.role)));
        row.appendChild(Dom.el('td', {},
          (s.certifications || []).map(c => c.cert_type.replace('_', ' ')).join(', ') || '—'
        ));
        row.appendChild(Dom.el('td', {},
          s.status === 'active'
            ? Dom.el('span', { class: 'tag tag-success' }, 'ACTIVE')
            : Dom.el('span', { class: 'tag tag-warn' }, (s.status || 'INVITED').toUpperCase())
        ));
        tbody.appendChild(row);
      });
      table.appendChild(tbody);
      content.appendChild(table);

    } catch (e) {
      Dom.clear(content);
      content.appendChild(emptyState('⚠️', 'Could not load staff', e.message));
    }
  }

  // ──────────────────────────────────────────────────────────────
  // TODAY (live roster — same as educator view, shown to directors)
  // ──────────────────────────────────────────────────────────────
  async function renderTodayDirector(content, ctx) {
    // Director version of today: shows live roster across all rooms
    content.innerHTML = '<div class="loading-state"><div class="spinner"></div></div>';

    try {
      const data = await Api.get('/director/dashboard');
      Dom.clear(content);

      content.appendChild(Dom.el('div', { class: 'section-header' },
        Dom.el('h2', {}, 'Today\'s roster'),
        Dom.el('p', { style: 'color: var(--kt-text-muted);' },
          new Date().toLocaleDateString('en-CA', { weekday: 'long', month: 'long', day: 'numeric' })
        ),
      ));

      for (const room of data.rooms || []) {
        try {
          const roomData = await Api.get(`/provider/rooms/${room.room_id}/roster`);
          content.appendChild(renderRoomCardForDirector(room, roomData.roster || []));
        } catch (e) {
          // skip room on error
        }
      }
    } catch (e) {
      Dom.clear(content);
      content.appendChild(emptyState('⚠️', 'Could not load roster', e.message));
    }
  }

  function renderRoomCardForDirector(room, roster) {
    const wrap = Dom.el('div', { class: 'card', style: 'margin-bottom: 16px;' });

    wrap.appendChild(Dom.el('div', { class: 'section-header', style: 'margin-bottom: 12px;' },
      Dom.el('div', { style: 'display: flex; align-items: center; gap: 10px;' },
        Dom.el('span', { class: 'room-color-dot', style: { background: room.color_hex || '#1F6080' } }),
        Dom.el('h2', { style: 'font-size: 18px;' }, room.room_name),
        Dom.el('span', { class: 'ratio-status ' + room.status }, statusTagFor(room.status).text),
      ),
      Dom.el('span', { style: 'font-size: 13px; color: var(--kt-text-muted);' },
        `${room.children_present} children · ${room.educators_present} educators`)
    ));

    if (roster.length === 0) {
      wrap.appendChild(Dom.el('p', { style: 'color: var(--kt-text-muted); padding: 20px;' },
        'No children enrolled in this room.'));
      return wrap;
    }

    const grid = Dom.el('div', { style: 'display: grid; grid-template-columns: repeat(auto-fill, minmax(180px, 1fr)); gap: 10px;' });

    roster.forEach(child => {
      const card = Dom.el('div', {
        class: 'child-card' + (child.is_at_centre ? ' at-centre' : ''),
        style: 'padding: 10px;',
      });
      card.appendChild(Dom.el('div', { class: 'child-card-header' },
        avatarCircle(child.display_name, room.color_hex),
        Dom.el('div', {},
          Dom.el('div', { class: 'child-card-name', style: 'font-size: 14px;' }, child.display_name),
          Dom.el('div', { class: 'child-card-age' }, child.age_human),
        ),
      ));
      if (child.is_at_centre) {
        card.appendChild(Dom.el('div', { class: 'child-card-status' },
          Dom.el('span', { class: 'status-dot' }),
          `Here since ${child.arrived_at || ''}`));
      } else {
        card.appendChild(Dom.el('div', { class: 'child-card-status' },
          Dom.el('span', { class: 'status-dot', style: 'background: var(--kt-text-faint);' }),
          'Not in yet',
        ));
      }
      grid.appendChild(card);
    });

    wrap.appendChild(grid);
    return wrap;
  }

  // ──────────────────────────────────────────────────────────────
  // COMPLIANCE
  // ──────────────────────────────────────────────────────────────
  async function renderCompliance(content, ctx) {
    content.innerHTML = '<div class="loading-state"><div class="spinner"></div></div>';

    try {
      const data = await Api.get('/director/compliance/overview');
      Dom.clear(content);

      content.appendChild(Dom.el('div', { class: 'section-header' },
        Dom.el('h2', {}, 'Compliance overview'),
      ));

      // Big status card
      content.appendChild(Dom.el('div', {
        class: 'card',
        style: 'margin-bottom: 20px; border-left: 4px solid ' + (data.all_rooms_compliant ? 'var(--kt-success)' : 'var(--kt-danger)'),
      },
        Dom.el('h2', { style: 'font-family: var(--kt-font-display); font-size: 22px;' },
          data.all_rooms_compliant ? '✅ All rooms compliant' : '⚠️ Compliance issues'),
        Dom.el('p', { style: 'color: var(--kt-text-muted); margin-top: 6px;' },
          data.all_rooms_compliant
            ? 'All rooms meet Ontario CCEYA educator-to-child ratios.'
            : 'One or more rooms are not meeting required ratios. Review below.'),
      ));

      // Rooms detail
      const roomsCard = Dom.el('div', { class: 'card' });
      roomsCard.appendChild(Dom.el('h2', { style: 'font-size: 18px; margin-bottom: 12px;' }, 'Room ratios'));

      const roomTable = Dom.el('table', { class: 'data-table' });
      roomTable.appendChild(Dom.el('thead', {},
        Dom.el('tr', {},
          Dom.el('th', {}, 'Room'),
          Dom.el('th', {}, 'Children'),
          Dom.el('th', {}, 'Educators'),
          Dom.el('th', {}, 'Required'),
          Dom.el('th', {}, 'Status'),
        )
      ));
      const rtbody = Dom.el('tbody', {});
      (data.rooms || []).forEach(r => {
        const row = Dom.el('tr', {});
        row.appendChild(Dom.el('td', { style: 'font-weight: 600;' }, r.room_name));
        row.appendChild(Dom.el('td', {}, String(r.children_present)));
        row.appendChild(Dom.el('td', {}, String(r.educators_present)));
        row.appendChild(Dom.el('td', {}, String(r.required_educators)));
        row.appendChild(Dom.el('td', {},
          Dom.el('span', { class: 'ratio-status ' + r.status }, statusTagFor(r.status).text)
        ));
        rtbody.appendChild(row);
      });
      roomTable.appendChild(rtbody);
      roomsCard.appendChild(roomTable);
      content.appendChild(roomsCard);

      // Staff cert summary
      const certs = data.staff_certifications;
      content.appendChild(Dom.el('div', { class: 'card', style: 'margin-top: 20px;' },
        Dom.el('h2', { style: 'font-size: 18px; margin-bottom: 12px;' }, 'Staff certifications'),
        Dom.el('div', { class: 'director-stats' },
          statTile({ label: 'Total active', value: certs.total, detail: 'certifications on file', flavor: 'good' }),
          statTile({ label: 'Expiring in 30d', value: certs.expiring_30d, detail: 'need renewal soon', flavor: certs.expiring_30d > 0 ? 'warn' : 'good' }),
          statTile({ label: 'Expired', value: certs.expired, detail: 'must renew immediately', flavor: certs.expired > 0 ? 'bad' : 'good' }),
        ),
      ));

    } catch (e) {
      Dom.clear(content);
      content.appendChild(emptyState('⚠️', 'Could not load compliance', e.message));
    }
  }

  // ──────────────────────────────────────────────────────────────
  // Form helpers
  // ──────────────────────────────────────────────────────────────
  function textInput(label, defaultValue = '', opts = {}) {
    const row = Dom.el('div', { class: 'form-row' });
    row.appendChild(Dom.el('label', {}, label));
    const input = Dom.el('input', {
      type: opts.type || 'text',
      value: defaultValue,
      placeholder: opts.placeholder || '',
      required: opts.required ? 'required' : undefined,
    });
    row.appendChild(input);
    return { row, input };
  }

  function selectInput(label, options, opts = {}) {
    const row = Dom.el('div', { class: 'form-row' });
    row.appendChild(Dom.el('label', {}, label));
    const select = Dom.el('select', {
      required: opts.required ? 'required' : undefined,
      style: 'font-family: inherit; font-size: 15px; padding: 12px 14px; border: 1.5px solid var(--kt-border); border-radius: 8px; background: white;',
    });
    options.forEach(([value, label]) => {
      select.appendChild(Dom.el('option', { value }, label));
    });
    row.appendChild(select);
    return { row, input: select };
  }

  function avatarCircle(name, color) {
    const initials = (name || '?').split(/\s+/).slice(0, 2).map(p => p[0] || '').join('').toUpperCase();
    return Dom.el('div', {
      class: 'avatar sm',
      style: { background: color || 'var(--kt-blue)', color: 'white', fontSize: '12px', flexShrink: 0 },
    }, initials);
  }

  function formatRole(role) {
    return {
      'agency_admin': 'Agency admin',
      'centre_director': 'Centre director',
      'educator': 'Educator',
      'guardian': 'Parent / guardian',
    }[role] || role;
  }

  // ──────────────────────────────────────────────────────────────
  // Register routes — both agency_admin and centre_director use these
  // ──────────────────────────────────────────────────────────────
  function registerForRole(role) {
    Shell.registerScreen(`${role}:dashboard`, (main, ctx) => renderDirectorRoot(main, ctx, renderDashboard));
    Shell.registerScreen(`${role}:children`, (main, ctx) => renderDirectorRoot(main, ctx, renderChildren));
    Shell.registerScreen(`${role}:families`, (main, ctx) => renderDirectorRoot(main, ctx, renderFamilies));
    Shell.registerScreen(`${role}:staff`, (main, ctx) => renderDirectorRoot(main, ctx, renderStaff));
    Shell.registerScreen(`${role}:today`, (main, ctx) => renderDirectorRoot(main, ctx, renderTodayDirector));
    Shell.registerScreen(`${role}:compliance`, (main, ctx) => renderDirectorRoot(main, ctx, renderCompliance));
  }
  // registerForRole('agency_admin'); // handled by screen-agency-admin.js
  registerForRole('centre_director');

})(window);
