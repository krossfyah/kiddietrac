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
  let adminRoomsCache = null;   // all providers' rooms (admins/directors span the whole agency)

  // Admins/directors are NOT tied to one room — "log a moment" should reach any
  // provider in the agency, not just the one centre /provider/bootstrap returns.
  function isAgencyScope() {
    try {
      const u = JSON.parse(sessionStorage.getItem('kt_user') || '{}');
      const roles = u.roles || (u.primary_role ? [u.primary_role] : []);
      return roles.some(r => r === 'agency_admin' || r === 'platform_admin' || r === 'centre_director');
    } catch (e) { return false; }
  }

  // ──────────────────────────────────────────────────────────────
  async function renderEducatorScreen(main, ctx) {
    Dom.clear(main);

    const shell = Dom.el('div', { class: 'educator-shell' });
    // Mark "this screen brings its own banner" BEFORE any await. The screen then
    // fetches /provider/bootstrap, and during that gap the shell's auto-hero
    // __ensure (timers + observer) would otherwise see no opt-out on the (already
    // in-DOM) shell and inject a "✨ Today" hero that nothing later removes. Setting
    // the attribute synchronously here, before the first await, closes that race.
    shell.setAttribute('data-kt-no-autohero', '1');
    main.appendChild(shell);

    try {
      let rooms = [];
      let centreLabel = '';
      let providerMode = false;

      if (isAgencyScope()) {
        // Whole-agency picker: every provider's room, labelled by provider.
        if (!adminRoomsCache) {
          const cres = await Api.get('/admin/centres').catch(() => ({ centres: [] }));
          adminRoomsCache = [];
          (cres.centres || []).forEach(c => {
            (c.rooms || []).forEach(r => adminRoomsCache.push({ id: r.id, name: r.name, centre_id: c.id, centre_name: c.name }));
          });
        }
        rooms = adminRoomsCache;
        centreLabel = 'All providers';
        providerMode = true;
        // Fall back to the assigned centre if the agency has no rooms yet.
        if (!rooms.length) {
          if (!bootstrapCache) bootstrapCache = await Api.get('/provider/bootstrap');
          rooms = bootstrapCache.rooms || [];
          centreLabel = bootstrapCache.centre?.name || 'My classroom';
          providerMode = false;
        }
      } else {
        if (!bootstrapCache) bootstrapCache = await Api.get('/provider/bootstrap');
        rooms = bootstrapCache.rooms || [];
        centreLabel = bootstrapCache.centre?.name || 'My classroom';
      }

      if (rooms.length === 0) {
        shell.appendChild(emptyState('🏫', 'No rooms assigned',
          'Speak to your director — no rooms are set up at this centre yet.'));
        return;
      }

      // Default to first room
      if (!currentRoomId || !rooms.find(r => r.id === currentRoomId)) {
        currentRoomId = rooms[0].id;
      }

      // "Today at a glance" brief FIRST — the day's headline + chips (Attendance,
      // Lesson plan, Meals logged …) sit at the very top where the old hero was.
      const briefBox = Dom.el('div', { id: 'kt-day-brief' });
      shell.appendChild(briefBox);
      renderDayBrief(briefBox);

      // Room topbar (centre name + room selector + ratio) — now BELOW the brief.
      const topbar = buildTopbar(rooms, centreLabel, providerMode);
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

  // Label a room for the agency-wide picker: home providers each have one
  // "Main room", so the provider name is the meaningful label; only append the
  // room name when a provider has more than the single default room.
  function roomLabel(room, providerMode) {
    if (!providerMode) return room.name;
    const isDefault = /^main room$/i.test(room.name || '');
    return isDefault ? (room.centre_name || room.name) : ((room.centre_name ? room.centre_name + ' · ' : '') + room.name);
  }

  function _briefEsc(s) { return String(s == null ? '' : s).replace(/[&<>"]/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]; }); }

  // Start-of-day brief: fetch the aggregated summary and render clickable chips.
  // Silent on failure — it's a nice-to-have that must never block the roster.
  async function renderDayBrief(container) {
    try {
      const b = await Api.get('/provider/day-brief');
      const items = (b && b.items) || [];
      if (!items.length) { container.innerHTML = ''; return; }
      const TONE = { good: ['#15803D', '#F0FDF4', '#BBF7D0'], warn: ['#B45309', '#FFFBEB', '#FDE68A'], info: ['#334155', '#F8FAFC', '#E2E8F0'] };
      const chips = items.map(function (it) {
        const t = TONE[it.tone] || TONE.info;
        return '<button class="kt-brief-chip"' + (it.hash ? ' data-hash="' + _briefEsc(it.hash) + '"' : '') +
          ' style="text-align:left;border:1px solid ' + t[2] + ';background:' + t[1] + ';border-radius:12px;padding:10px 12px;cursor:' + (it.hash ? 'pointer' : 'default') + ';display:flex;gap:10px;align-items:flex-start;">' +
          '<span style="font-size:20px;line-height:1;flex-shrink:0;">' + (it.icon || '•') + '</span>' +
          '<span style="min-width:0;">' +
          '<span style="display:block;font-size:10.5px;font-weight:800;text-transform:uppercase;letter-spacing:.5px;color:' + t[0] + ';opacity:.7;">' + _briefEsc(it.label) + '</span>' +
          '<span style="display:block;font-size:13.5px;font-weight:800;color:' + t[0] + ';line-height:1.25;">' + _briefEsc(it.value) + '</span>' +
          (it.detail ? '<span style="display:block;font-size:11.5px;color:' + t[0] + ';opacity:.72;margin-top:1px;">' + _briefEsc(it.detail) + '</span>' : '') +
          '</span></button>';
      }).join('');
      container.innerHTML =
        '<div style="background:linear-gradient(135deg,#1F6080,#2c7894);border-radius:16px;padding:15px 18px;margin:2px 0 14px;color:#fff;box-shadow:0 2px 10px rgba(31,96,128,.18);">' +
          '<div style="font-size:11px;font-weight:800;letter-spacing:1.2px;text-transform:uppercase;opacity:.82;">☀️ Today at a glance</div>' +
          '<div style="font-size:18px;font-weight:800;margin-top:3px;">' + _briefEsc(b.headline || '') + '</div>' +
        '</div>' +
        '<div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(190px,1fr));gap:10px;margin-bottom:18px;">' + chips + '</div>';
      container.querySelectorAll('.kt-brief-chip[data-hash]').forEach(function (btn) {
        btn.addEventListener('click', function () { const h = btn.getAttribute('data-hash'); if (h) location.hash = h; });
      });
    } catch (e) { container.innerHTML = ''; }
  }

  function buildTopbar(rooms, centreLabel, providerMode) {
    const topbar = Dom.el('div', { class: 'educator-topbar' });

    topbar.appendChild(Dom.el('h2', {}, centreLabel || 'My classroom'));

    // A dropdown scales to many providers; pills stay for a handful of rooms in
    // one centre (the classic educator view).
    if (providerMode || rooms.length > 5) {
      const wrap = Dom.el('div', { class: 'room-selector', style: 'display:flex;align-items:center;gap:8px;' });
      wrap.appendChild(Dom.el('label', { style: 'font-size:13px;font-weight:700;color:var(--kt-text-muted,#64748B);' },
        providerMode ? 'Provider' : 'Room'));
      const sel = Dom.el('select', {
        style: 'padding:8px 12px;border:1px solid var(--kt-border,#D1D5DB);border-radius:8px;font-size:14px;background:#fff;font-weight:600;min-width:200px;max-width:340px;',
        onChange: async (e) => {
          currentRoomId = parseInt(e.target.value, 10);
          await renderEducatorScreen(Dom.$('#appMain'), {});
        },
      });
      // Sort by the visible label so the provider list reads alphabetically.
      rooms.slice().sort((a, b) => roomLabel(a, providerMode).localeCompare(roomLabel(b, providerMode))).forEach(room => {
        const opt = Dom.el('option', { value: String(room.id) }, roomLabel(room, providerMode));
        if (room.id === currentRoomId) opt.selected = true;
        sel.appendChild(opt);
      });
      wrap.appendChild(sel);
      topbar.appendChild(wrap);
    } else {
      const selector = Dom.el('div', { class: 'room-selector' });
      rooms.forEach(room => {
        const pill = Dom.el('button', {
          class: 'room-pill' + (room.id === currentRoomId ? ' active' : ''),
          onClick: async () => {
            currentRoomId = room.id;
            await renderEducatorScreen(Dom.$('#appMain'), {});
          },
        }, room.name);
        selector.appendChild(pill);
      });
      topbar.appendChild(selector);
    }

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
      const ratio = await Api.get(`/provider/rooms/${currentRoomId}/ratio?t=${Date.now()}`);
      Dom.clear(container);

      const kids = ratio.children_present || 0;
      const have = ratio.educators_present || 0;
      const need = ratio.required_educators || 0;
      const cap = ratio.capacity || 0;
      const overCap = !!ratio.over_capacity;
      const overBy = ratio.over_capacity_by || 0;
      const atCap = !!ratio.at_capacity;
      const short = Math.max(0, need - have);
      // Two independent limits: the educator:child RATIO and the room's licensed
      // CAPACITY (headcount). Either one being exceeded is a red alert.
      const ratioBreached = short > 0 || ['over', 'breach', 'danger'].indexOf(ratio.status) !== -1;
      const breached = ratioBreached || overCap;
      const tight = !breached && (ratio.status === 'warning' || ratio.status === 'tight' || atCap);

      const label = ratioBreached ? 'RATIO BREACH' : overCap ? 'OVER CAPACITY' : tight ? 'AT THE LIMIT' : 'RATIO OK';
      const theme = breached
        ? { bg: '#FEF2F2', border: '#DC2626', text: '#991B1B', chipBg: '#DC2626', icon: ratioBreached ? '🚨' : '⛔' }
        : tight
          ? { bg: '#FFFBEB', border: '#F59E0B', text: '#92400E', chipBg: '#F59E0B', icon: '⚠️' }
          : { bg: '#F0FDF4', border: '#16A34A', text: '#166534', chipBg: '#16A34A', icon: '✅' };

      const headline = ratioBreached
        ? `${short} more educator${short === 1 ? '' : 's'} needed`
        : overCap
          ? `${overBy} child${overBy === 1 ? '' : 'ren'} over room capacity`
          : `${kids} ${kids === 1 ? 'child' : 'children'} · ${have}/${need || 1} educator${(need || 1) === 1 ? '' : 's'}`;

      // Always surface BOTH limits so an educator sees at a glance which is at risk.
      const pct = cap > 0 ? Math.round((kids / cap) * 100) : 0;
      const sub = `Ratio ${ratio.ratio_target || (have + ':' + kids)}`
        + (cap > 0 ? ` · Capacity ${kids}/${cap} (${pct}%)` : ` · ${kids} in room`);

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
      }, label));
      txt.appendChild(Dom.el('div', {
        style: `font-size:15px;font-weight:800;color:${theme.text};line-height:1.2;margin-top:1px;`,
      }, headline));
      txt.appendChild(Dom.el('div', {
        style: `font-size:11.5px;color:${theme.text};opacity:.85;margin-top:2px;`,
      }, sub));
      bar.appendChild(txt);

      // Chip shows children-in-room over the licensed capacity (the headcount cap).
      bar.appendChild(Dom.el('div', {
        style: `background:${theme.chipBg};color:#fff;border-radius:9px;padding:6px 10px;`
          + `font-size:15px;font-weight:800;flex-shrink:0;font-feature-settings:"tnum";`,
      }, cap > 0 ? `${kids}/${cap}` : `${have}:${kids}`));

      container.appendChild(bar);
    } catch (e) {
      // silent — a missing ratio shouldn't blank the roster
    }
  }

  // Cache-first, revalidate in the background — the same trick that makes the
  // parent app feel instant. Navigating to a room used to blank the roster and
  // spin, so the screen-transition animation played over an empty box and the
  // real content popped in afterwards: that's the "educator screens don't
  // transition smoothly" everyone can feel but nobody can point at.
  const rosterCache = {};

  // Cache-buster: a check-in/out is immediately followed by a roster re-fetch, and a
  // browser-cached GET here would return the PRE-check-in roster — the child looks "not
  // checked in" even though the write succeeded, so the educator taps again and the backend
  // 422s ("already checked in"). The `?t=` forces a fresh read every time.
  function rosterUrl() {
    return `/provider/rooms/${currentRoomId}/roster?t=${Date.now()}`;
  }

  async function renderRoster(container) {
    const cached = rosterCache[currentRoomId];
    if (cached) {
      paintRoster(container, cached);
      // Refresh quietly; if nothing changed the user never sees a flicker.
      Api.get(rosterUrl())
        .then((fresh) => {
          rosterCache[currentRoomId] = fresh;
          if (document.body.contains(container)) paintRoster(container, fresh);
        })
        .catch(() => {});
      return;
    }

    container.innerHTML = '<div class="loading-state"><div class="spinner"></div></div>';
    try {
      const data = await Api.get(rosterUrl());
      rosterCache[currentRoomId] = data;
      paintRoster(container, data);
    } catch (e) {
      Dom.clear(container);
      container.appendChild(emptyState('⚠️', 'Could not load the roster', e.message));
    }
  }

  function paintRoster(container, data) {
    try {
      Dom.clear(container);

      // data-kt-no-kebab: keep the per-child action buttons (Check in / Check out /
      // Log activity) inline on desktop instead of collapsing them into a ⋮ kebab.
      const grid = Dom.el('div', { class: 'educator-roster', 'data-kt-list': '1', 'data-kt-no-kebab': '1' });

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
    // Presence drives the card colour so the room reads at a glance: green = here
    // now, amber = signed out (went home), grey = not in yet. The avatar keeps the
    // per-child identity colour so a child stays recognisable.
    const _st = child.status || (child.is_at_centre ? 'in' : 'away');
    // Colourize the WHOLE card by presence so the room reads at a glance:
    // green = here now, amber = signed out / gone home, white = not in yet.
    // [ accent (left bar + text), full wash, all-round border ]
    const _TH = ({
      in:   ['#15803D', 'rgba(22,163,74,.15)',  'rgba(22,163,74,.40)'],
      out:  ['#B45309', 'rgba(245,158,11,.17)', 'rgba(245,158,11,.44)'],
      away: ['#94A3B8', '#ffffff',              'rgba(148,163,184,.28)'],
    })[_st] || ['#94A3B8', '#ffffff', 'rgba(148,163,184,.28)'];
    card.classList.add('child-status-' + _st);
    card.style.background = _TH[1];
    card.style.border = '1px solid ' + _TH[2];
    card.style.borderLeft = '7px solid ' + _TH[0];

    // Header: avatar + name. Photo when present; otherwise ALWAYS a boy/girl
    // silhouette — known gender, else a deterministic guess (self-corrects when
    // the child's record gets a real sex). Never initials.
    const sex = (window.KT && KT.normSex)
      ? (KT.normSex(child.gender) || (KT.guessSex ? KT.guessSex(child.display_name || child.first_name || '') : 'male'))
      : '';
    const avatar = Dom.el('div', {
      class: 'avatar',
      style: { background: colour, color: 'white', width: '40px', height: '40px', fontSize: '14px', flexShrink: 0 },
    }, '');
    if (child.photo_url) {
      const url = ktAbsUrl(child.photo_url);
      avatar.style.backgroundImage = 'url(' + url + ')';
      avatar.style.backgroundSize = 'cover';
      avatar.style.backgroundPosition = 'center';
    } else {
      // Emoji child face (boy/girl), never initials.
      avatar.style.fontSize = '24px';
      avatar.textContent = (window.KT && KT.emojiFor) ? KT.emojiFor(sex, true) : '🧒';
    }
    // Prominent presence pill — the at-a-glance "is this child here?" signal, with
    // the time of the last check-in / check-out. Colour-matched to the card edge.
    const PILL = {
      in:   ['#15803D', 'rgba(22,163,74,.14)',  '#16A34A', '✓ IN',    child.arrived_at || ''],
      out:  ['#B45309', 'rgba(245,158,11,.16)', '#F59E0B', '← OUT',   child.departed_at || ''],
      away: ['#475569', 'rgba(148,163,184,.18)', '#94A3B8', 'NOT IN', ''],
    };
    const _p = PILL[_st] || PILL.away;
    const statusPill = Dom.el('div', {
      class: 'child-card-pill',
      style: 'display:inline-flex;align-items:center;gap:6px;padding:6px 11px;border-radius:999px;background:' + _p[1] + ';color:' + _p[0] + ';font-weight:800;font-size:11.5px;letter-spacing:.3px;white-space:nowrap;',
    },
      Dom.el('span', { style: 'width:8px;height:8px;border-radius:50%;background:' + _p[2] + ';box-shadow:0 0 0 3px ' + _p[1] + ';flex:0 0 auto;' }),
      Dom.el('span', {}, _p[3] + (_p[4] ? ('  ·  ' + _p[4]) : '')));

    // Header: avatar + name/age. The narrow roster cards can't fit a pill on this
    // row too — it overlapped the child's name — so the pill gets its own row below.
    // The name ellipsis-truncates instead of wrapping into the avatar.
    card.appendChild(Dom.el('div', { class: 'child-card-header', style: 'display:flex;align-items:center;gap:11px;' },
      avatar,
      Dom.el('div', { style: 'flex:1 1 auto;min-width:0;' },
        Dom.el('div', { class: 'child-card-name', style: 'white-space:nowrap;overflow:hidden;text-overflow:ellipsis;' }, child.display_name),
        Dom.el('div', { class: 'child-card-age' }, child.age_human),
      ),
    ));
    // Presence pill on its own row — prominent and never overlapping the name.
    card.appendChild(Dom.el('div', { style: 'margin-top:9px;' }, statusPill));

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

    // Last-activity detail for children who are here (the pill already carries the
    // arrival time, so this is just the most recent logged moment).
    if (_st === 'in' && child.last_event) {
      card.appendChild(Dom.el('div', { class: 'child-card-last' },
        `Last: ${child.last_event.summary} · ${child.last_event.time_display}`));
    }

    // Action buttons — big and colour-coded so check-in / check-out is unmissable:
    // green to sign a child IN, amber to sign them OUT.
    const actions = Dom.el('div', { class: 'child-card-actions', style: 'display:flex;gap:8px;margin-top:10px;' });
    const bigBtn = 'padding:11px 12px;border-radius:12px;font-size:14px;font-weight:800;border:none;cursor:pointer;display:inline-flex;align-items:center;justify-content:center;gap:6px;line-height:1;';
    if (child.is_at_centre) {
      actions.appendChild(Dom.el('button', {
        style: bigBtn + 'flex:1 1 40%;background:#EEF2F6;color:#0f172a;',
        onClick: (e) => { e.stopPropagation(); openQuickLog(child); },
      }, '➕ Log'));
      actions.appendChild(Dom.el('button', {
        style: bigBtn + 'flex:1 1 60%;background:linear-gradient(135deg,#F59E0B,#D97706);color:#fff;box-shadow:0 6px 14px -7px rgba(217,119,6,.7);',
        onClick: (e) => { e.stopPropagation(); confirmCheckOut(child, card); },
      }, '← Check out'));
    } else {
      actions.appendChild(Dom.el('button', {
        style: bigBtn + 'flex:1 1 auto;background:linear-gradient(135deg,#16A34A,#15803D);color:#fff;box-shadow:0 6px 14px -7px rgba(21,128,61,.7);',
        onClick: (e) => { e.stopPropagation(); doCheckIn(child, card); },
      }, '✓ Check in'));
    }
    card.appendChild(actions);

    return card;
  }

  // ─── Actions ─────────────────────────────────────────────────
  // Signing a child in tells their parents and starts an attendance record that
  // gets audited — it deserves the same "are you sure" as signing them out. It's
  // also the mistake that's most annoying to undo when you tap the wrong card.
  function doCheckIn(child, cardEl) {
    // An educator must be clocked in before checking children in/out.
    if (window.KT && KT.requireClockedIn && !KT.requireClockedIn()) return;
    Modal.confirm({
      title: `Check ${child.display_name} in?`,
      message: `The proper way to sign ${child.display_name} in is for their parent to scan the centre QR from their own KiddieTrac app at drop-off — it keeps attendance accurate and clearly records who signed them in. This button is only a temporary backup for the rare times a parent genuinely can't (dead phone, forgotten device); please don't make it the routine. Continue? ${child.display_name} will be marked as here and their parents notified.`,
      confirmLabel: 'Yes, check in',
      onConfirm: async () => {
        if (!currentRoomId) { Dom.toast('Pick a room first', 'error'); return; }
        // Dimmed spinner over the card while the sign-in records — so a tap never feels
        // like nothing happened, and a double-tap can't fire a second request.
        const done = (window.KT && KT.busy) ? KT.busy(cardEl) : function () {};
        try {
          await Api.post('/provider/check-in', {
            child_id: child.id,
            room_id: currentRoomId,
          });
          // Flip the status OPTIMISTICALLY so the card updates immediately instead of
          // depending on the re-fetch (the "sometimes doesn't change the status" fix).
          // `child` is the same object held in rosterCache, so the cache updates too;
          // renderRoster repaints from it instantly, then reconciles quietly in the bg.
          child.is_at_centre = true;
          try { child.arrived_at = child.arrived_at || new Date().toLocaleTimeString([], { hour: 'numeric', minute: '2-digit' }); } catch (e) {}
          Dom.toast(`${child.display_name} checked in ✓`, 'success');
          await renderRoster(Dom.$('#educatorRoster'));
          const ri = Dom.$('#ratioIndicator'); if (ri) refreshRatio(ri);   // also update the "present" stat banner
        } catch (e) {
          Dom.toast(e.message || 'Could not check in', 'error');
        } finally {
          done();
        }
      },
    });
  }

  // The roster is cached (so screens don't blank and spin on every navigation) —
  // after a check-in or check-out that cache is a lie, so drop it.
  function bustRoster() {
    if (currentRoomId != null) delete rosterCache[currentRoomId];
  }

  function confirmCheckOut(child, cardEl) {
    // An educator must be clocked in before checking children in/out.
    if (window.KT && KT.requireClockedIn && !KT.requireClockedIn()) return;
    Modal.confirm({
      title: `Check ${child.display_name} out?`,
      message: `The proper way to sign ${child.display_name} out is for the parent to scan out from their own KiddieTrac app at pickup — that's the accurate record. Use this button only as a temporary backup when they can't. Confirm pickup? ${child.display_name} will be marked as gone for today.`,
      confirmLabel: 'Yes, check out',
      onConfirm: async () => {
        if (!currentRoomId) { Dom.toast('Pick a room first', 'error'); return; }
        const done = (window.KT && KT.busy) ? KT.busy(cardEl) : function () {};
        try {
          await Api.post('/provider/check-out', {
            child_id: child.id,
            room_id: currentRoomId,
          });
          child.is_at_centre = false;   // optimistic — see doCheckIn
          Dom.toast(`${child.display_name} checked out ✓`, 'success');
          await renderRoster(Dom.$('#educatorRoster'));
          const ri = Dom.$('#ratioIndicator'); if (ri) refreshRatio(ri);   // also update the "present" stat banner
        } catch (e) {
          Dom.toast(e.message || 'Could not check out', 'error');
        } finally {
          done();
        }
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
      { icon: '😴', label: 'Nap',      type: 'nap' },
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
          // Snack + Nap have dedicated quick flows (time-of-day buttons / sleep +
          // wake times); everything else uses the generic per-type form.
          if (ql.type === 'snack') { await openSnackQuick(child); return; }
          if (ql.type === 'nap') { await openNapQuick(child); return; }
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

  // Build an ISO timestamp for a "HH:MM" wall-clock time TODAY, in the browser's
  // local zone (educator is on-site, so local = the child's time). toISOString()
  // then carries the correct UTC instant to the server.
  function isoForTimeToday(hhmm) {
    if (!hhmm) return null;
    const m = /^(\d{1,2}):(\d{2})$/.exec(hhmm.trim());
    if (!m) return null;
    const now = new Date();
    now.setHours(parseInt(m[1], 10), parseInt(m[2], 10), 0, 0);
    return now.toISOString();
  }
  function nowHHMM() {
    const d = new Date();
    return String(d.getHours()).padStart(2, '0') + ':' + String(d.getMinutes()).padStart(2, '0');
  }

  // Snack — three time-of-day quick buttons. One tap logs the snack immediately.
  async function openSnackQuick(child) {
    const body = Dom.el('div', {});
    body.appendChild(Dom.el('p', { style: 'color: var(--kt-text-muted); margin-bottom: 14px;' },
      `Which snack did ${child.display_name} just have?`));
    const grid = Dom.el('div', { class: 'quick-log-grid' });
    let activeModal = null;
    const snacks = [
      { icon: '🌅', label: 'Morning snack', meal: 'Morning snack' },
      { icon: '☀️', label: 'Afternoon snack', meal: 'Afternoon snack' },
      { icon: '🌆', label: 'Evening snack', meal: 'Evening snack' },
    ];
    snacks.forEach(s => {
      grid.appendChild(Dom.el('button', {
        class: 'quick-log-btn',
        onClick: async () => {
          try {
            await Api.post('/provider/events', {
              child_id: child.id,
              room_id: currentRoomId,
              event_type: 'snack',
              payload: { meal: s.meal },
            });
            Dom.toast(`${s.label} logged ✓`, 'success');
            if (activeModal) activeModal.close();
            await renderRoster(Dom.$('#educatorRoster'));
          } catch (e) {
            Dom.toast(e.message || 'Could not log snack', 'error');
          }
        },
      }, Dom.el('div', { class: 'quick-icon' }, s.icon), s.label));
    });
    body.appendChild(grid);
    activeModal = Modal.open({ title: `🍎  Snack — ${child.display_name}`, body, actions: [{ label: 'Cancel' }] });
  }

  // Nap — one form capturing when the child fell asleep and woke up. Posts
  // nap_start / nap_end at those times so the daily digest pairs them into a
  // total-sleep figure. Either time may be left blank (still napping / already up).
  async function openNapQuick(child) {
    const body = Dom.el('div', { class: 'form-grid' });
    body.appendChild(Dom.el('p', { class: 'full-width', style: 'color: var(--kt-text-muted); margin:0 0 4px;' },
      `Record ${child.display_name}'s nap. Leave "woke up" blank if they're still asleep.`));
    const slept = textInput('Fell asleep', nowHHMM(), { type: 'time' });
    const woke = textInput('Woke up', '', { type: 'time' });
    body.appendChild(slept.row);
    body.appendChild(woke.row);
    Modal.open({
      title: `😴  Nap — ${child.display_name}`,
      body,
      actions: [
        { label: 'Cancel' },
        {
          label: 'Save',
          style: 'btn-primary',
          handler: async () => {
            const sIso = isoForTimeToday(slept.input.value);
            const wIso = isoForTimeToday(woke.input.value);
            if (!sIso && !wIso) { Dom.toast('Enter at least one time', 'error'); return; }
            if (sIso && wIso && new Date(wIso) < new Date(sIso)) { Dom.toast('Woke-up time is before fell-asleep time', 'error'); return; }
            try {
              if (sIso) {
                await Api.post('/provider/events', { child_id: child.id, room_id: currentRoomId, event_type: 'nap_start', occurred_at: sIso, payload: {} });
              }
              if (wIso) {
                await Api.post('/provider/events', { child_id: child.id, room_id: currentRoomId, event_type: 'nap_end', occurred_at: wIso, payload: {} });
              }
              Dom.toast('Nap logged ✓', 'success');
              await renderRoster(Dom.$('#educatorRoster'));
            } catch (e) {
              Dom.toast((e && e.message) || 'Could not log the nap — please try again', 'error');
              return false; // keep the modal open so the entry isn't lost
            }
          },
        },
      ],
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

    // WHEN did it happen — defaults to now, but an educator catching up between
    // moments can set the real time so the timeline stays honest. Every event type
    // gets this (Snack and Nap have their own time flows).
    const when = textInput('Time it happened', nowHHMM(), { type: 'time' });
    when.row.classList.add('full-width');
    body.appendChild(when.row);

    Modal.open({
      title: `${quickLog.icon}  ${quickLog.label} — ${child.display_name}`,
      body,
      actions: [
        { label: 'Cancel' },
        {
          label: 'Save',
          style: 'btn-primary',
          handler: async () => {
            try {
              await Api.post('/provider/events', {
                child_id: child.id,
                room_id: currentRoomId,
                event_type: quickLog.type,
                occurred_at: isoForTimeToday(when.input.value),
                payload: payloadBuilder(),
              });
              Dom.toast(`${quickLog.label} logged ✓`, 'success');
              await renderRoster(Dom.$('#educatorRoster'));
            } catch (e) {
              Dom.toast((e && e.message) || 'Could not log — please try again', 'error');
              return false; // keep the modal open so the entry isn't lost
            }
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
