/* ═══════════════════════════════════════════════════════════════════
   KIDDIETRAC v2 — Educator Screen
   Tablet-optimized UI with check-in/out, quick logging, ratio counter
   ═══════════════════════════════════════════════════════════════════ */

(function (window) {
  'use strict';

  const { Api, Fmt, Dom, Shell } = window.KT;
  const { Modal, emptyState, statusTagFor } = Shell;

  let currentRoomId = null;
  let bootstrapCache = null;

  // ──────────────────────────────────────────────────────────────
  async function renderEducatorScreen(main, ctx) {
    Dom.clear(main);

    const shell = Dom.el('div', { class: 'educator-shell' });
    main.appendChild(shell);

    try {
      if (!bootstrapCache) {
        bootstrapCache = await Api.get('/provider/bootstrap');
      }

      const rooms = bootstrapCache.rooms || [];
      if (rooms.length === 0) {
        shell.appendChild(emptyState('🏫', 'No rooms assigned',
          'Speak to your director — no rooms are set up at this centre yet.'));
        return;
      }

      // Default to first room
      if (!currentRoomId || !rooms.find(r => r.id === currentRoomId)) {
        currentRoomId = rooms[0].id;
      }

      // Topbar
      const topbar = buildTopbar(rooms, bootstrapCache);
      shell.appendChild(topbar);

      // Roster
      const rosterContainer = Dom.el('div', { id: 'educatorRoster' });
      shell.appendChild(rosterContainer);
      await renderRoster(rosterContainer);

      // Auto-refresh roster every 30s
      const interval = setInterval(async () => {
        if (!document.body.contains(rosterContainer)) {
          clearInterval(interval);
          return;
        }
        try { await renderRoster(rosterContainer); } catch (_) {}
      }, 30000);
    } catch (e) {
      Dom.clear(shell);
      shell.appendChild(emptyState('⚠️', 'Could not load', e.message));
    }
  }

  function buildTopbar(rooms, bootstrap) {
    const topbar = Dom.el('div', { class: 'educator-topbar' });

    topbar.appendChild(Dom.el('h2', {}, bootstrap.centre?.name || 'My classroom'));

    const selector = Dom.el('div', { class: 'room-selector' });
    rooms.forEach(room => {
      const pill = Dom.el('button', {
        class: 'room-pill' + (room.id === currentRoomId ? ' active' : ''),
        onClick: async () => {
          currentRoomId = room.id;
          // Re-render whole screen so topbar pills update
          await renderEducatorScreen(Dom.$('#appMain'), {});
        },
      }, room.name);
      selector.appendChild(pill);
    });
    topbar.appendChild(selector);

    // Ratio indicator (live)
    const ratioWrap = Dom.el('div', { id: 'ratioIndicator', style: 'margin-left: auto;' });
    topbar.appendChild(ratioWrap);
    refreshRatio(ratioWrap);

    return topbar;
  }

  // The ratio bar is the one thing on this screen that can mean "you are out of
  // compliance right now" — as a small grey pill it read like decoration. It's a
  // full-width, colour-coded banner: green when staffed, amber when it's tight,
  // red when the room is over ratio.
  async function refreshRatio(container) {
    try {
      const ratio = await Api.get(`/provider/rooms/${currentRoomId}/ratio`);
      Dom.clear(container);

      const kids = ratio.children_present || 0;
      const have = ratio.educators_present || 0;
      const need = ratio.required_educators || 0;
      const short = Math.max(0, need - have);
      const breached = short > 0 || ratio.status === 'over' || ratio.status === 'breach' || ratio.status === 'danger';
      const tight = !breached && (ratio.status === 'warning' || (need > 0 && have === need && kids > 0));

      const theme = breached
        ? { bg: '#FEF2F2', border: '#DC2626', text: '#991B1B', chipBg: '#DC2626', icon: '🚨', label: 'RATIO BREACH' }
        : tight
          ? { bg: '#FFFBEB', border: '#F59E0B', text: '#92400E', chipBg: '#F59E0B', icon: '⚠️', label: 'AT THE LIMIT' }
          : { bg: '#F0FDF4', border: '#16A34A', text: '#166534', chipBg: '#16A34A', icon: '✅', label: 'RATIO OK' };

      const headline = breached
        ? `${short} more educator${short === 1 ? '' : 's'} needed`
        : `${kids} ${kids === 1 ? 'child' : 'children'} · ${have}/${need} educators`;

      const bar = Dom.el('div', {
        class: 'kt-ratio-bar' + (breached ? ' breached' : ''),
        style: `display:flex;align-items:center;gap:10px;width:100%;box-sizing:border-box;`
          + `background:${theme.bg};border:2px solid ${theme.border};border-left:7px solid ${theme.border};`
          + `border-radius:12px;padding:11px 13px;`,
      });
      bar.appendChild(Dom.el('div', { style: 'font-size:22px;line-height:1;' }, theme.icon));

      const txt = Dom.el('div', { style: 'flex:1;min-width:0;' });
      txt.appendChild(Dom.el('div', {
        style: `font-size:11px;font-weight:800;letter-spacing:1px;color:${theme.text};`,
      }, theme.label));
      txt.appendChild(Dom.el('div', {
        style: `font-size:15px;font-weight:800;color:${theme.text};line-height:1.2;margin-top:1px;`,
      }, headline));
      if (breached) {
        txt.appendChild(Dom.el('div', {
          style: `font-size:11.5px;color:${theme.text};opacity:.85;margin-top:2px;`,
        }, `${kids} ${kids === 1 ? 'child' : 'children'} in the room with ${have} educator${have === 1 ? '' : 's'}.`));
      }
      bar.appendChild(txt);

      bar.appendChild(Dom.el('div', {
        style: `background:${theme.chipBg};color:#fff;border-radius:9px;padding:6px 10px;`
          + `font-size:15px;font-weight:800;flex-shrink:0;font-feature-settings:"tnum";`,
      }, `${have}:${kids}`));

      container.appendChild(bar);
    } catch (e) {
      // silent — a missing ratio shouldn't blank the roster
    }
  }

  async function renderRoster(container) {
    container.innerHTML = '<div class="loading-state"><div class="spinner"></div></div>';

    try {
      const data = await Api.get(`/provider/rooms/${currentRoomId}/roster`);
      Dom.clear(container);

      const grid = Dom.el('div', { class: 'educator-roster', 'data-kt-list': '1' });

      if (!data.roster || data.roster.length === 0) {
        grid.appendChild(emptyState('👶', 'No children enrolled',
          'Children enrolled in this room will appear here.'));
        container.appendChild(grid);
        return;
      }

      data.roster.forEach(child => {
        grid.appendChild(buildChildCard(child));
      });

      container.appendChild(grid);
    } catch (e) {
      Dom.clear(container);
      container.appendChild(emptyState('⚠️', 'Could not load roster', e.message));
    }
  }

  // Photo URLs come back either absolute (an external avatar service) or as a
  // /storage/... path on the API host.
  function ktAbsUrl(u) {
    if (!u) return '';
    if (/^https?:\/\//.test(u)) return u;
    const base = (window.KT_CONFIG && window.KT_CONFIG.apiBase) || 'https://api.kiddietrac.com/api/v1';
    return base.replace(/\/api\/v1\/?$/, '') + (u.charAt(0) === '/' ? u : '/' + u);
  }

  // A deterministic colour per child — the roster was a wall of identical white
  // cards, which is hard to scan when you're looking for one child. Same name →
  // same colour every time, so it becomes a recognisable cue.
  const CARD_COLOURS = [
    '#7C3AED', '#E91E8C', '#0EA5E9', '#10B981', '#F59E0B',
    '#EF4444', '#8B5CF6', '#0F9D6B', '#DB2777', '#0891B2',
  ];
  function childColour(child) {
    const nm = ((child.first_name || '') + (child.last_name || '') + (child.display_name || '')).trim();
    let h = 0;
    for (let i = 0; i < nm.length; i++) h = (h * 31 + nm.charCodeAt(i)) >>> 0;
    return CARD_COLOURS[h % CARD_COLOURS.length];
  }

  function buildChildCard(child) {
    const card = Dom.el('div', {
      class: 'child-card' + (child.is_at_centre ? ' at-centre' : ''),
    });
    // Tint, not paint: a colour bar down the edge plus the faintest wash, so the
    // card stays readable and "at centre" still reads as the stronger signal.
    const colour = childColour(child);
    card.style.borderLeft = '6px solid ' + colour;
    card.style.background = 'linear-gradient(100deg, ' + colour + '0F 0%, #ffffff 42%)';

    // Header: avatar + name. The roster carries photo_url, but this only ever
    // drew initials — so a child with a photo (Aria) still showed "AH".
    const avatar = Dom.el('div', {
      class: 'avatar',
      style: { background: colour, color: 'white', width: '40px', height: '40px', fontSize: '14px', flexShrink: 0 },
    }, child.photo_url ? '' : (child.initials || (child.display_name || '?').substring(0, 2).toUpperCase()));
    if (child.photo_url) {
      const url = ktAbsUrl(child.photo_url);
      avatar.style.backgroundImage = 'url(' + url + ')';
      avatar.style.backgroundSize = 'cover';
      avatar.style.backgroundPosition = 'center';
    }
    card.appendChild(Dom.el('div', { class: 'child-card-header' },
      avatar,
      Dom.el('div', {},
        Dom.el('div', { class: 'child-card-name' }, child.display_name),
        Dom.el('div', { class: 'child-card-age' }, child.age_human),
      ),
    ));

    // Health flags
    if (child.urgent_flags && child.urgent_flags.length > 0) {
      const flagsDiv = Dom.el('div', { class: 'child-card-flags' });
      child.urgent_flags.forEach(flag => {
        flagsDiv.appendChild(Dom.el('span', {
          class: 'health-flag severity-' + flag.severity,
          title: flag.category,
        }, flag.short_label));
      });
      card.appendChild(flagsDiv);
    }

    // Status
    if (child.is_at_centre) {
      card.appendChild(Dom.el('div', { class: 'child-card-status' },
        Dom.el('span', { class: 'status-dot' }),
        `Here since ${child.arrived_at || ''}`));
      if (child.last_event) {
        card.appendChild(Dom.el('div', { class: 'child-card-last' },
          `Last: ${child.last_event.summary} · ${child.last_event.time_display}`));
      }
    } else {
      card.appendChild(Dom.el('div', { class: 'child-card-status' },
        Dom.el('span', { class: 'status-dot', style: 'background: var(--kt-text-faint);' }),
        'Not checked in'));
    }

    // Action buttons
    const actions = Dom.el('div', { class: 'child-card-actions' });
    if (child.is_at_centre) {
      actions.appendChild(Dom.el('button', {
        class: 'btn btn-primary btn-sm',
        onClick: (e) => { e.stopPropagation(); openQuickLog(child); },
      }, 'Log activity'));
      actions.appendChild(Dom.el('button', {
        class: 'btn btn-secondary btn-sm',
        onClick: (e) => { e.stopPropagation(); confirmCheckOut(child); },
      }, 'Check out'));
    } else {
      actions.appendChild(Dom.el('button', {
        class: 'btn btn-primary btn-sm',
        style: 'flex: 2;',
        onClick: (e) => { e.stopPropagation(); doCheckIn(child); },
      }, 'Check in'));
    }
    card.appendChild(actions);

    return card;
  }

  // ─── Actions ─────────────────────────────────────────────────
  async function doCheckIn(child) {
    try {
      await Api.post('/provider/check-in', {
        child_id: child.id,
        room_id: currentRoomId,
      });
      Dom.toast(`${child.display_name} checked in ✓`, 'success');
      await renderRoster(Dom.$('#educatorRoster'));
    } catch (e) {
      Dom.toast(e.message || 'Could not check in', 'error');
    }
  }

  function confirmCheckOut(child) {
    Modal.confirm({
      title: `Check ${child.display_name} out?`,
      message: `Confirm pickup. ${child.display_name} will be marked as gone for today.`,
      confirmLabel: 'Yes, check out',
      onConfirm: async () => {
        await Api.post('/provider/check-out', {
          child_id: child.id,
          room_id: currentRoomId,
        });
        Dom.toast(`${child.display_name} checked out ✓`, 'success');
        await renderRoster(Dom.$('#educatorRoster'));
      },
    });
  }

  function openQuickLog(child) {
    const body = Dom.el('div', {});
    body.appendChild(Dom.el('p', { style: 'color: var(--kt-text-muted); margin-bottom: 16px;' },
      'What did ' + child.display_name + ' just do?'));

    const grid = Dom.el('div', { class: 'quick-log-grid' });

    const quickLogs = [
      { icon: '🍽️', label: 'Meal',     type: 'meal' },
      { icon: '🍎', label: 'Snack',    type: 'snack' },
      { icon: '😴', label: 'Nap start', type: 'nap_start' },
      { icon: '🌅', label: 'Nap end',   type: 'nap_end' },
      { icon: '💧', label: 'Diaper',   type: 'diaper' },
      { icon: '✨', label: 'Activity', type: 'activity' },
      { icon: '😊', label: 'Mood',     type: 'mood' },
      { icon: '📝', label: 'Note',     type: 'note' },
    ];

    let activeModal = null;

    quickLogs.forEach(ql => {
      const btn = Dom.el('button', {
        class: 'quick-log-btn',
        onClick: async () => {
          if (activeModal) activeModal.close();
          await openSpecificLog(child, ql);
        },
      },
        Dom.el('div', { class: 'quick-icon' }, ql.icon),
        ql.label,
      );
      grid.appendChild(btn);
    });

    body.appendChild(grid);

    activeModal = Modal.open({
      title: `Log activity — ${child.display_name}`,
      body,
      actions: [{ label: 'Cancel' }],
    });
  }

  async function openSpecificLog(child, quickLog) {
    const body = Dom.el('div', { class: 'form-grid' });

    const fields = {};
    let payloadBuilder = () => ({});

    switch (quickLog.type) {
      case 'meal':
      case 'snack':
        fields.meal = selectInput('Meal', [
          ['breakfast', 'Breakfast'],
          ['morning_snack', 'Morning snack'],
          ['lunch', 'Lunch'],
          ['afternoon_snack', 'Afternoon snack'],
          ['dinner', 'Dinner'],
        ]);
        fields.items = textInput('Items eaten (comma separated)', '', { placeholder: 'chicken, rice, carrots' });
        fields.amount = selectInput('How much?', [
          ['all', 'All of it'],
          ['most', 'Most of it'],
          ['half', 'About half'],
          ['little', 'A little'],
          ['none', 'Did not eat'],
        ]);
        payloadBuilder = () => ({
          meal: fields.meal.input.value,
          items: fields.items.input.value.split(',').map(s => s.trim()).filter(Boolean),
          amount: fields.amount.input.value,
        });
        body.appendChild(fields.meal.row);
        body.appendChild(fields.amount.row);
        fields.items.row.classList.add('full-width');
        body.appendChild(fields.items.row);
        break;

      case 'diaper':
        fields.type = selectInput('Type', [
          ['wet', 'Wet'],
          ['bm', 'BM'],
          ['both', 'Both'],
          ['dry', 'Dry (changed anyway)'],
        ]);
        payloadBuilder = () => ({ type: fields.type.input.value });
        fields.type.row.classList.add('full-width');
        body.appendChild(fields.type.row);
        break;

      case 'mood':
        fields.score = selectInput('How is their mood?', [
          ['happy', '😊 Happy'],
          ['calm', '😌 Calm'],
          ['tired', '😴 Tired'],
          ['upset', '😢 Upset'],
          ['energetic', '⚡ Energetic'],
        ]);
        payloadBuilder = () => ({ score: fields.score.input.value });
        fields.score.row.classList.add('full-width');
        body.appendChild(fields.score.row);
        break;

      case 'activity':
        fields.name = textInput('Activity name', '', { placeholder: 'Block tower, story time, outdoor play' });
        fields.domain = selectInput('Learning area', [
          ['social_emotional', 'Social & emotional'],
          ['physical', 'Physical / motor'],
          ['language_literacy', 'Language & literacy'],
          ['cognitive', 'Cognitive'],
          ['creative_arts', 'Creative arts'],
          ['outdoor', 'Outdoor play'],
        ]);
        fields.duration_min = textInput('Duration (minutes)', '15', { type: 'number' });
        payloadBuilder = () => ({
          name: fields.name.input.value,
          domain: fields.domain.input.value,
          duration_min: parseInt(fields.duration_min.input.value || '0'),
        });
        fields.name.row.classList.add('full-width');
        body.appendChild(fields.name.row);
        body.appendChild(fields.domain.row);
        body.appendChild(fields.duration_min.row);
        break;

      case 'note':
        fields.note = textInput('Note', '', { placeholder: 'What happened?' });
        payloadBuilder = () => ({ note: fields.note.input.value });
        fields.note.row.classList.add('full-width');
        body.appendChild(fields.note.row);
        break;

      // nap_start, nap_end — no fields, just log
      default:
        body.appendChild(Dom.el('p', {}, `Log ${quickLog.label} for ${child.display_name}?`));
    }

    Modal.open({
      title: `${quickLog.icon}  ${quickLog.label} — ${child.display_name}`,
      body,
      actions: [
        { label: 'Cancel' },
        {
          label: 'Save',
          style: 'btn-primary',
          handler: async () => {
            await Api.post('/provider/events', {
              child_id: child.id,
              room_id: currentRoomId,
              event_type: quickLog.type,
              payload: payloadBuilder(),
            });
            Dom.toast(`${quickLog.label} logged ✓`, 'success');
            await renderRoster(Dom.$('#educatorRoster'));
          },
        },
      ],
    });
  }

  // ─── Form helpers (local copies) ──────────────────────────────
  function textInput(label, defaultValue = '', opts = {}) {
    const row = Dom.el('div', { class: 'form-row' });
    row.appendChild(Dom.el('label', {}, label));
    const input = Dom.el('input', {
      type: opts.type || 'text',
      value: defaultValue,
      placeholder: opts.placeholder || '',
    });
    row.appendChild(input);
    return { row, input };
  }

  function selectInput(label, options) {
    const row = Dom.el('div', { class: 'form-row' });
    row.appendChild(Dom.el('label', {}, label));
    const select = Dom.el('select', {
      style: 'font-family: inherit; font-size: 15px; padding: 12px 14px; border: 1.5px solid var(--kt-border); border-radius: 8px; background: white;',
    });
    options.forEach(([value, label]) => {
      select.appendChild(Dom.el('option', { value }, label));
    });
    row.appendChild(select);
    return { row, input: select };
  }

  // ─── Register ────────────────────────────────────────────────
  // All educator hashes route to this same screen
  ['today', 'dashboard'].forEach(hash => {
    Shell.registerScreen(`educator:${hash}`, renderEducatorScreen);
  });

  // Directors can also visit /today which shows this same view
  // (already wired in screen-director.js via renderTodayDirector)

})(window);
