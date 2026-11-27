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

  /* TODAY OPENS ON WHAT IT LAST SHOWED (2026-11-03, Anthony's iPhone recording: opening Today blanked the screen,
     the banner went and the cards popped in one by one). Every tile waited for its own request before it drew
     anything. Now each tile paints at once from its last answer in this tab and refreshes quietly; with no answer
     yet the banner still draws in the first frame and the score card keeps its place. Memory only, keyed on the
     signed-in token, the active agency and the view-as role (and the room where the data is per room), 10 minutes
     at most - nothing crosses a person, an agency or a preview. */
  const MEMO = new Map();
  let lastHeaderLabel = '';
  function memoScope() {
    try { return [String(sessionStorage.getItem('kt_token') || '').slice(-16), sessionStorage.getItem('kt_active_agency_id') || '', sessionStorage.getItem('kt_view_as') || ''].join('|'); }
    catch (e) { return '?'; }
  }
  function memoGet(key) { const e = MEMO.get(memoScope() + '|' + key); return (e && Date.now() - e.at < 600000) ? e.d : null; }
  function memoPut(key, d) { MEMO.set(memoScope() + '|' + key, { at: Date.now(), d: d }); while (MEMO.size > 40) MEMO.delete(MEMO.keys().next().value); }
  function same(a, b) { try { return JSON.stringify(a) === JSON.stringify(b); } catch (e) { return false; } }

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
    // The "Today at a glance" banner is the page's header: drawn now, before any request, from what is known.
    const briefBox = Dom.el('div', { id: 'kt-day-brief' });
    shell.appendChild(briefBox);
    paintDayBrief(briefBox, { label: lastHeaderLabel }, null, memoGet('brief'));

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

      /* LABEL BY PROVIDER WHENEVER THE LIST ACTUALLY SPANS CENTRES.

         roomLabel() only prefixes the centre in providerMode, and providerMode was set
         solely by isAgencyScope() — an admin looking at the whole agency. An educator
         holding rooms in several centres was therefore given a selector of rooms named
         only by room. In a home childcare agency every provider's home is its own
         centre with one room, and they are all called "Main Room": nine identical
         entries and no way to tell which provider you were about to open.

         Decide it from the DATA rather than from who is asking, so any list that
         crosses centres explains itself. A single-centre educator is untouched — one
         centre_id, so this never fires. */
      if (!providerMode) {
        var _centreIds = {};
        (rooms || []).forEach(function (r) { if (r && r.centre_id != null) _centreIds[r.centre_id] = 1; });
        if (Object.keys(_centreIds).length > 1) {
          providerMode = true;
          centreLabel = 'My providers';
        }
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

      /* ONE HEADER, NOT TWO (2026-10-09, Anthony: "where it shows natasha name combine
         this to where it shows you're all set - have a great day Natasha and move the ratio
         to where it shows light day bar"). The provider's name (and the room/provider
         picker, when there is more than one) now sits in the "Today at a glance" banner
         beside the greeting, and the live ratio card sits at the right of the day-score
         card. The separate row that repeated the name in a pill is gone. */
      const header = buildHeaderBits(rooms, centreLabel, providerMode);
      const ratioWrap = Dom.el('div', { id: 'ratioIndicator', style: 'margin-left:auto;flex:0 0 auto;' });

      // "Today at a glance" brief FIRST — the day's headline + chips (Attendance,
      // Lesson plan, Meals logged …) sit at the very top where the old hero was.
      renderDayBrief(briefBox, header, ratioWrap);   // the ratio is a card among the day's chips (2026-10-23)

      /* SECTION HEADERS (2026-11-06, Anthony: "add a header for Children ... above that add Score card as a header -
         just to separate things to make it easier to read"). The portal's own section header (kt-tile-sectionhead,
         as "Quick access" on the parent home). Score card hides with its card on a day that is not scored. */
      const scoreHead = sectionHead('📊', 'Score card');
      scoreHead.hidden = true;
      shell.appendChild(scoreHead);

      // How the day is going — the same score the evening email sends, but live.
      const scoreBox = Dom.el('div', { id: 'kt-day-score' });
      shell.appendChild(scoreBox);
      Promise.resolve(renderDayScore(scoreBox, null)).then(function () { scoreHead.hidden = !scoreBox.firstElementChild; }, function () {});
      refreshRatio(ratioWrap);

      /* The daily forms. Submitting one used to leave no trace anywhere an educator
         could see — the signoff went into the database and no screen mentioned it — so
         there was no way to tell what was still owed without opening each form. */
      const formsBox = Dom.el('div', { id: 'kt-forms-today' });
      shell.appendChild(formsBox);
      renderFormsToday(formsBox);

      /* HEADCOUNT (2026-10-13; Ontario O. Reg. 137/15 s.11 as amended by O. Reg. 197/26):
         a name-to-face count of the children signed in to this room, one tap away above
         the roster. The checklist lives in screen-headcounts.js (KT.Headcount). */
      shell.appendChild(sectionHead('🧒', 'Children'));
      if (window.KT && KT.Headcount && KT.Headcount.todayBar) {
        try { shell.appendChild(KT.Headcount.todayBar(() => currentRoomId)); } catch (_) {}
      }

      // (The room-wide 🧺 Request supplies button was removed 2026-10-23 at Anthony's request: each
      // child's card has its own 🧺 Supplies, and the daily log's tile covers several children.)

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

  function sectionHead(icon, text) {
    return Dom.el('div', { class: 'kt-tile-sectionhead kt-ed-sect', role: 'heading', 'aria-level': '2',
      style: 'margin:18px 4px 10px;font-size:12.5px;color:#475569;' }, [
      Dom.el('span', { 'aria-hidden': 'true', style: 'font-size:15px;letter-spacing:0;' }, icon), text]);
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
  /* The day score, drawn as an arc.
     Deliberately quiet when there is nothing to say: an educator who has just walked in
     does not need a 0 out of 100 in red, and a day the centre does not run should not be
     scored at all. In both cases the card simply is not there. */
  /* THIS MONTH'S POINTS beside today's score (2026-10-23, Anthony: "for educators in today next
     to the graph that shows today add the points for the educators how many they have for the
     month based on performance"). The same numbers as My points (EducatorPoints); a tap opens
     them. Hidden when points are off for the agency or the person does not earn them. */
  function paintPoints(slot) {
    if (!slot) return;
    // On a phone the two rings sit centred across the card (2026-10-23: "the ring is not centred").
    if (!document.getElementById('kt-ring-pair-css')) {
      const st = document.createElement('style'); st.id = 'kt-ring-pair-css';
      st.textContent = '@media (max-width:768px){.kt-ring-pair{flex:1 1 100% !important;justify-content:center !important;}}';
      document.head.appendChild(st);
    }
    (memoGet('points') ? Promise.resolve(memoGet('points')) : Api.get('/educator-points/me?agency=1').then(function (x) { memoPut('points', x); return x; })).then(function (d) {
      if (!d || !d.enabled || !d.me || !document.body.contains(slot)) { slot.remove(); return; }
      const total = Number(d.me.total) || 0;
      const board = d.leaderboard || null, mine = board ? board.filter(function (r) { return r.me; })[0] : null;
      let monthName = '';
      try { monthName = new Date(String(d.month) + '-01T12:00:00').toLocaleDateString(undefined, { month: 'long' }); } catch (e) {}
      /* A RING, NOT A CARD (2026-10-23, Anthony: "dont make the points a card but into a star or
         ring like visual"). Gold, the same size as Today's ring beside it. It fills by how close
         you are to the top earner at your centre (full when you are #1); with no leaderboard,
         it fills as the month goes by. The number counts up like Today's. */
      // The whole agency (2026-10-23): fill against the agency's top earner, rank across every centre.
      const ar = d.agency_rank || null;
      const top = ar ? (Number(ar.top) || 0) : (board && board.length ? Math.max.apply(null, board.map(function (r) { return Number(r.points) || 0; })) : 0);
      let frac;
      if (top > 0) frac = Math.min(1, total / top);
      else { const n = new Date(); frac = n.getDate() / new Date(n.getFullYear(), n.getMonth() + 1, 0).getDate(); if (!total) frac = 0; }
      const R2 = 52, C2 = 2 * Math.PI * R2;
      const sub = (ar && ar.of > 1) ? ('#' + ar.rank + ' of ' + ar.of) : ((mine && board.length > 1) ? ('#' + mine.rank + ' of ' + board.length) : '');
      slot.innerHTML = '<a href="#my-points" title="Your points in ' + (monthName || 'this month') + (sub ? ' — ' + sub + ' in the agency' : '') + '. Tap for what earned them." style="display:block;text-decoration:none;">'
        + '<span style="position:relative;width:120px;height:120px;display:block;">'
        + '<svg width="120" height="120" viewBox="0 0 120 120" style="transform:rotate(-90deg);display:block;">'
        // Violet (2026-10-23, Anthony: "make the points ring a different colour").
        + '<defs><linearGradient id="kt-pts-g" x1="0" y1="0" x2="1" y2="1"><stop offset="0" stop-color="#A78BFA"/><stop offset="1" stop-color="#6D28D9"/></linearGradient></defs>'
        + '<circle cx="60" cy="60" r="' + R2 + '" fill="none" stroke="#EDE9FE" stroke-width="11"></circle>'
        + '<circle cx="60" cy="60" r="' + R2 + '" fill="none" stroke="url(#kt-pts-g)" stroke-width="11" stroke-linecap="round" stroke-dasharray="' + C2.toFixed(1) + '" stroke-dashoffset="' + C2.toFixed(1) + '" style="transition:stroke-dashoffset 1.1s cubic-bezier(.22,1,.36,1);" data-parc></circle>'
        + '</svg>'
        + '<span style="position:absolute;inset:0;display:flex;flex-direction:column;align-items:center;justify-content:center;">'
        // Everything inside the ring, so it is the same size as Today's and the two line up.
        + '<span style="font-size:13px;line-height:1;">⭐</span>'
        + '<span style="font-size:26px;font-weight:800;color:#6D28D9;line-height:1.05;" data-pnum>0</span>'
        + '<span style="font-size:10px;color:#94A3B8;font-weight:700;letter-spacing:.08em;">POINTS</span>'
        + (sub ? '<span style="font-size:10.5px;color:#7C3AED;font-weight:800;margin-top:1px;white-space:nowrap;">' + sub + '</span>' : '')
        + '</span></span>'
        + '</a>';
      const parc = slot.querySelector('[data-parc]'), pnum = slot.querySelector('[data-pnum]');
      requestAnimationFrame(function () {
        if (parc) parc.style.strokeDashoffset = (C2 * (1 - frac)).toFixed(1);
        const t0 = Date.now(), dur = 1100;
        (function step() { const p = Math.min(1, (Date.now() - t0) / dur); if (pnum) pnum.textContent = Math.round(total * (1 - Math.pow(1 - p, 3))).toLocaleString(); if (p < 1) requestAnimationFrame(step); })();
      });
    }).catch(function () { slot.remove(); });
  }

  async function renderDayScore(container, ratioWrap) {
    // No score card today (not an operating day, or it failed): the ratio must still show.
    function ratioAlone() {
      container.innerHTML = '';
      if (ratioWrap) {
        const row = Dom.el('div', { style: 'display:flex;justify-content:flex-end;align-items:center;gap:12px;flex-wrap:wrap;margin:0 0 14px;' });
        const ps = Dom.el('div', {}); row.appendChild(ps); paintPoints(ps);
        row.appendChild(ratioWrap);
        container.appendChild(row);
      }
    }
    const c0 = memoGet('score');
    if (c0) paintScore(c0, true);
    // No answer yet: the card's place is kept (an empty card the ring's height), so nothing jumps when it lands.
    else container.innerHTML = '<div data-kt-skel style="background:#fff;border:1px solid #E7EBF0;border-radius:16px;min-height:152px;margin:0 0 14px;"></div>';
    let d0;
    try { d0 = await Api.get('/educator/day-score'); memoPut('score', d0); }
    catch (e) { if (!c0) ratioAlone(); return; }      // never break Today for a tile
    if (!document.body.contains(container) || (c0 && same(c0, d0))) return;
    paintScore(d0, false);

    function paintScore(d, still) {
    if (!d || d.operating_day === false) { ratioAlone(); return; }

    const score = Math.max(0, Math.min(100, parseInt(d.score, 10) || 0));
    const colour = d.colour || '#7C3AED';
    const st = d.stats || {};
    const hours = Math.floor((st.minutes || 0) / 60);
    const mins = (st.minutes || 0) % 60;

    // r=52 in a 120 box: the circumference is what the dash offset animates against.
    const R = 52, C = 2 * Math.PI * R;
    const esc = (v) => String(v == null ? '' : v).replace(/[&<>"]/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]));

    const chip = (icon, n, label) =>
      '<div style="display:flex;align-items:center;gap:7px;background:#F8FAFC;border:1px solid #EEF2F7;' +
      'border-radius:10px;padding:8px 11px;">' +
      '<span style="font-size:15px;">' + icon + '</span>' +
      '<span style="font-size:13px;color:#0F172A;font-weight:800;">' + esc(n) + '</span>' +
      '<span style="font-size:12px;color:#64748B;">' + esc(label) + '</span></div>';

    container.innerHTML =
      '<div style="background:#fff;border:1px solid #E7EBF0;border-radius:16px;padding:16px 18px;margin:0 0 14px;' +
        'display:flex;align-items:center;gap:18px;flex-wrap:wrap;">' +
        // The ring and this month's points side by side (2026-10-23: "next to the graph").
        // One line even on a phone: the tile gives way, never wraps under the ring.
        '<div class="kt-ring-pair" style="display:flex;align-items:center;gap:16px;flex:0 0 auto;min-width:0;max-width:100%;flex-wrap:nowrap;">' +
        '<div style="position:relative;width:120px;height:120px;flex:0 0 auto;">' +
          '<svg width="120" height="120" viewBox="0 0 120 120" style="transform:rotate(-90deg);">' +
            '<circle cx="60" cy="60" r="' + R + '" fill="none" stroke="#EEF2F7" stroke-width="11"></circle>' +
            '<circle cx="60" cy="60" r="' + R + '" fill="none" stroke="' + esc(colour) + '" stroke-width="11" ' +
              'stroke-linecap="round" stroke-dasharray="' + C.toFixed(1) + '" ' +
              'stroke-dashoffset="' + C.toFixed(1) + '" ' +
              'style="transition:stroke-dashoffset 1.1s cubic-bezier(.22,1,.36,1);" data-arc></circle>' +
          '</svg>' +
          '<div style="position:absolute;inset:0;display:flex;flex-direction:column;align-items:center;justify-content:center;">' +
            '<div style="font-size:30px;font-weight:800;color:' + esc(colour) + ';line-height:1;" data-num>0</div>' +
            '<div style="font-size:10px;color:#94A3B8;font-weight:700;letter-spacing:.08em;margin-top:2px;">TODAY</div>' +
          '</div>' +
        '</div>' +
        '<div data-points-slot style="flex:0 1 auto;min-width:0;"></div>' +
        '</div>' +
        '<div style="flex:1;min-width:190px;">' +
          '<div style="font-size:17px;font-weight:800;color:#0F172A;">' + esc(d.label || '') + '</div>' +
          '<div style="font-size:13px;color:#64748B;line-height:1.5;margin:3px 0 11px;">' + esc(d.blurb || '') + '</div>' +
          '<div style="display:flex;gap:8px;flex-wrap:wrap;">' +
            chip('📝', st.moments || 0, (st.moments === 1 ? 'moment' : 'moments')) +
            chip('🧒', st.children || 0, (st.children === 1 ? 'child' : 'children')) +
            chip('🔭', st.observations || 0, (st.observations === 1 ? 'observation' : 'observations')) +
            (st.minutes ? chip('⏱', (hours ? hours + 'h ' : '') + mins + 'm', 'on the floor') : '') +
          '</div>' +
        '</div>' +
        '<div data-ratio-slot style="margin-left:auto;flex:0 0 auto;"></div>' +
      '</div>';
    paintPoints(container.querySelector('[data-points-slot]'));
    if (ratioWrap) {
      const slot = container.querySelector('[data-ratio-slot]');
      if (slot) { slot.replaceWith(ratioWrap); } else { container.appendChild(ratioWrap); }
    }

    // Animated from empty on the next frame, so the arc is seen to fill rather than
    // appearing already full — the movement is what makes it read as today's, not a
    // static badge.
    const arc = container.querySelector('[data-arc]');
    const num = container.querySelector('[data-num]');
    // Repainted from the last answer: the finished ring, not the fill again.
    if (still) { if (arc) { arc.style.transition = 'none'; arc.style.strokeDashoffset = (C * (1 - score / 100)).toFixed(1); } if (num) num.textContent = score; return; }
    requestAnimationFrame(function () {
      if (arc) { arc.style.strokeDashoffset = (C * (1 - score / 100)).toFixed(1); }
      if (!num) { return; }
      // Counts up over the same beat as the arc.
      const t0 = Date.now(), dur = 1100;
      (function step() {
        const p = Math.min(1, (Date.now() - t0) / dur);
        num.textContent = Math.round(score * (1 - Math.pow(1 - p, 3)));
        if (p < 1) { requestAnimationFrame(step); }
      })();
    });
    }
  }

  /**
   * "Forms today" — what this educator is expected to submit, and what they have.
   *
   * The state that matters is STARTED vs SUBMITTED. Opening a form saves a draft
   * signoff, and only a signature counts; on the day this was built, 4 of 10 entries
   * across the agency were drafts nobody had finished. Showing a draft as done would
   * have told several educators they were finished when they were not, which is worse
   * than showing nothing.
   */
  async function renderFormsToday(container) {
    const c0 = memoGet('forms');
    if (c0) paintForms(c0);
    let d0;
    try { d0 = await Api.get('/educator/forms-today'); memoPut('forms', d0); }
    catch (e) { if (!c0) container.innerHTML = ''; return; }   // never break Today for a tile
    if (!document.body.contains(container) || (c0 && same(c0, d0))) return;
    paintForms(d0);

    function paintForms(d) {
    if (!d || !d.expected) { container.innerHTML = ''; return; }

    const esc = (v) => String(v == null ? '' : v).replace(/[&<>"]/g, (c) =>
      ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]));

    const done = d.submitted || 0;
    const total = d.expected || 0;
    const allDone = done === total;

    /* Amber while something is outstanding, green when finished. Deliberately not red:
       a form still to be filled in during the day is normal, not a failure. */
    const accent = allDone ? '#16A34A' : (d.drafts ? '#D97706' : '#64748B');

    const pip = (f) => {
      const c = f.state === 'submitted' ? '#16A34A' : (f.state === 'draft' ? '#F59E0B' : '#CBD5E1');
      return '<span title="' + esc(f.title) + ' — ' + f.state.replace('_', ' ') + '" style="width:9px;height:9px;'
        + 'border-radius:50%;background:' + c + ';display:inline-block;"></span>';
    };

    const row = (f) => {
      const mark = f.state === 'submitted' ? '✓' : (f.state === 'draft' ? '◌' : '○');
      const col = f.state === 'submitted' ? '#166534' : (f.state === 'draft' ? '#92400E' : '#64748B');
      const note = f.state === 'submitted' ? esc(f.at)
        : (f.state === 'draft' ? 'started ' + esc(f.at) + ' — not submitted' : '');
      return '<div style="display:flex;align-items:center;gap:9px;padding:6px 0;border-top:1px solid #F1F5F9;">'
        + '<span style="color:' + col + ';font-size:14px;width:14px;text-align:center;">' + mark + '</span>'
        + '<span style="flex:1;min-width:0;font-size:13px;color:#0F172A;overflow:hidden;text-overflow:ellipsis;'
        + 'white-space:nowrap;">' + esc(f.title)
        // A one-time form shows until it is signed, then leaves (formDayItem, 2026-11-27).
        + '</span>' + (f.reusable === false ? '<span data-kttip="One-time form: once signed it leaves this list" style="flex:0 0 auto;font-size:10px;font-weight:800;color:#6D28D9;background:#F3F0FF;border-radius:999px;padding:1px 7px;white-space:nowrap;">one-time</span>' : '')
        + '<span style="font-size:11.5px;color:' + col + ';font-variant-numeric:tabular-nums;">' + note + '</span>'
        + '</div>';
    };

    container.innerHTML =
      '<div style="background:#fff;border:1px solid #E8EDF3;border-radius:14px;padding:14px 16px;margin:0 0 14px;'
        + 'box-shadow:0 1px 3px rgba(0,0,0,.04);">'
      + '<div style="display:flex;align-items:center;gap:10px;">'
        + '<div style="flex:1;min-width:0;">'
          + '<div style="font-size:12px;font-weight:800;letter-spacing:.04em;text-transform:uppercase;color:#64748B;">Forms today</div>'
          + '<div style="font-size:17px;font-weight:800;color:' + accent + ';margin-top:2px;">'
            + done + ' of ' + total + ' submitted'
            + (d.drafts ? '<span style="font-size:12.5px;font-weight:600;color:#92400E;"> · '
                + d.drafts + ' started</span>' : '')
          + '</div>'
        + '</div>'
        + '<div style="display:flex;gap:5px;align-items:center;">' + (d.forms || []).map(pip).join('') + '</div>'
      + '</div>'
      + '<div style="margin-top:9px;">' + (d.forms || []).map(row).join('') + '</div>'
      + '<div style="margin-top:10px;text-align:right;">'
        + '<a href="#my-forms" style="font-size:12.5px;color:#1F6080;font-weight:700;text-decoration:none;">'
        + (allDone ? 'View forms' : 'Open forms') + ' →</a>'
      + '</div>'
      + '</div>';
    }
  }

  async function renderDayBrief(container, header, ratioWrap) {
    if (header && header.label) lastHeaderLabel = header.label;
    const c0 = memoGet('brief');
    paintDayBrief(container, header, ratioWrap, c0);    // the banner now carries the name and the picker
    let b = null;
    try { b = await Api.get('/provider/day-brief'); memoPut('brief', b); } catch (e) { return; }
    if (!document.body.contains(container) || (c0 && same(c0, b))) return;
    paintDayBrief(container, header, ratioWrap, b);
  }

  function paintDayBrief(container, header, ratioWrap, b) {
    try {
      const items = (b && b.items) || [];
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
      const name = (header && header.label) || '';
      /* THE SAME LIVE BANNER AS EVERY OTHER SCREEN (2026-11-09, Anthony: "hero under today does not have the animated
         image and shimmer"). This brief is drawn by hand, so the banner normalizer never decorated it: no
         .kt-banner-fx shimmer, no floating art. It carries both itself now - the art is this screen's own icon
         (one icon per screen), behind the words and the provider picker. */
      let briefArt = '';
      try { briefArt = (window.KT && KT.Shell && KT.Shell.screenIcon) ? (KT.Shell.screenIcon() || '') : ''; } catch (e) { briefArt = ''; }
      if (!briefArt || /</.test(briefArt)) briefArt = '☀️';
      container.innerHTML =
        '<div class="kt-banner-fx" data-kt-brief-banner style="position:relative;overflow:hidden;background:linear-gradient(135deg,#1F6080,#2c7894);border-radius:16px;padding:15px 18px;margin:2px 0 14px;color:#fff;box-shadow:0 2px 10px rgba(31,96,128,.18);'
          + 'display:flex;align-items:center;gap:14px;flex-wrap:wrap;">' +
          '<span class="kt-hero-emoji" aria-hidden="true">' + _briefEsc(briefArt) + '</span>' +
          '<div style="flex:1;min-width:220px;position:relative;z-index:1;">' +
            '<div style="font-size:11px;font-weight:800;letter-spacing:1.2px;text-transform:uppercase;opacity:.82;">☀️ Today at a glance</div>' +
            (name ? '<div style="font-size:21px;font-weight:800;margin-top:3px;line-height:1.2;">' + _briefEsc(name) + '</div>' : '') +
            ((b && b.headline) ? '<div style="font-size:' + (name ? '14.5px;font-weight:700;opacity:.92;margin-top:2px' : '18px;font-weight:800;margin-top:3px') + ';">' + _briefEsc(b.headline) + '</div>' : '') +
          '</div>' +
          '<div data-picker-slot style="flex:0 0 auto;position:relative;z-index:1;"></div>' +
        '</div>' +
        /* THE RATIO IS ONE OF THE CARDS (2026-10-23, Anthony: "the ratio under today should be a card
           next to the news card in that section"). It sat at the right of the day-score card. */
        '';
      /* THE CARDS SIT OUTSIDE THE BANNER (2026-10-23). #kt-day-brief is the page's banner, and
         the clock strip always goes directly under the banner; with the cards inside it, the
         strip landed below every card. Now: banner, clock strip, cards (as on Home). */
      let cards = document.getElementById('kt-day-cards');
      if (cards && cards.previousElementSibling !== container && cards.parentElement !== container.parentElement) { cards.remove(); cards = null; }
      if (!cards) { cards = document.createElement('div'); cards.id = 'kt-day-cards'; }
      if (!document.getElementById('kt-day-cards-css')) {
        const st = document.createElement('style'); st.id = 'kt-day-cards-css';
        st.textContent = '#kt-day-cards [data-ratio-card]{grid-column:span 2;}@media (max-width:340px){#kt-day-cards [data-ratio-card]{grid-column:auto;}}';
        document.head.appendChild(st);
      }
      cards.innerHTML = (items.length || ratioWrap) ? '<div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(190px,1fr));gap:10px;margin-bottom:18px;">' + chips
          + (ratioWrap ? '<div data-ratio-card style="min-width:0;display:flex;"></div>' : '') + '</div>' : '';
      // After the clock strip when it is already there, else straight after the banner.
      const pill = container.parentElement && container.parentElement.querySelector(':scope > #kt-clockpill');
      const after = (pill && pill.previousElementSibling === container) ? pill : container;
      after.insertAdjacentElement('afterend', cards);
      if (ratioWrap) {
        const rc = cards.querySelector('[data-ratio-card]');
        ratioWrap.style.cssText = 'min-width:0;flex:1 1 auto;display:flex;';
        if (rc) rc.appendChild(ratioWrap);
      }
      if (header && header.picker) {
        const slot = container.querySelector('[data-picker-slot]');
        if (slot) { slot.appendChild(header.picker); }
      }
      cards.querySelectorAll('.kt-brief-chip[data-hash]').forEach(function (btn) {
        btn.addEventListener('click', function () { const h = btn.getAttribute('data-hash'); if (h) location.hash = h; });
      });
    } catch (e) { container.innerHTML = ''; if (ratioWrap) container.appendChild(ratioWrap); }
  }

  function buildHeaderBits(rooms, centreLabel, providerMode) {
    let picker = null;
    if (providerMode || rooms.length > 5) {
      if (rooms.length > 1) {
        picker = Dom.el('div', { class: 'room-selector', style: 'display:flex;align-items:center;gap:8px;' });
        picker.appendChild(Dom.el('label', { style: 'font-size:12px;font-weight:800;color:#E0F2F7;' }, providerMode ? 'Provider' : 'Room'));
        const sel = Dom.el('select', {
          style: 'height:32px;padding:0 10px;border:1px solid rgba(255,255,255,.45);border-radius:8px;font-size:13.5px;background:#fff;color:#0F172A;font-weight:600;min-width:180px;max-width:300px;',
          onChange: async (e) => {
            currentRoomId = parseInt(e.target.value, 10);
            await renderEducatorScreen(Dom.$('#appMain'), {});
          },
        });
        rooms.slice().sort((a, b) => roomLabel(a, providerMode).localeCompare(roomLabel(b, providerMode))).forEach(room => {
          const opt = Dom.el('option', { value: String(room.id) }, roomLabel(room, providerMode));
          if (room.id === currentRoomId) opt.selected = true;
          sel.appendChild(opt);
        });
        picker.appendChild(sel);
      }
    } else if (rooms.length > 1) {
      picker = Dom.el('div', { class: 'room-selector', style: 'display:flex;gap:6px;flex-wrap:wrap;' });
      rooms.forEach(room => {
        const on = room.id === currentRoomId;
        picker.appendChild(Dom.el('button', {
          type: 'button',
          style: 'height:30px;padding:0 12px;border-radius:999px;font-size:13px;font-weight:700;cursor:pointer;'
            + (on ? 'background:#fff;color:#1F6080;border:1px solid #fff;' : 'background:rgba(255,255,255,.14);color:#fff;border:1px solid rgba(255,255,255,.45);'),
          onClick: async () => {
            currentRoomId = room.id;
            await renderEducatorScreen(Dom.$('#appMain'), {});
          },
        }, room.name));
      });
    }
    // The name shown in the banner: the provider/room being looked at, else the centre.
    const cur = rooms.find(r => r.id === currentRoomId);
    const label = providerMode && cur ? roomLabel(cur, providerMode) : (centreLabel || (cur && cur.name) || 'My classroom');
    return { label: label, picker: picker };
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
    const key = 'ratio|' + currentRoomId, c0 = memoGet(key);
    if (c0) paintRatio(container, c0);
    try {
      const fresh = await Api.get(`/provider/rooms/${currentRoomId}/ratio?t=${Date.now()}`);
      memoPut(key, fresh);
      if (c0 && same(c0, fresh)) return;
      paintRatio(container, fresh);
    } catch (e) { /* silent - a missing ratio shouldn't blank the roster */ }
  }

  function paintRatio(container, ratio) {
    try {
      Dom.clear(container);

      const kids = ratio.children_present || 0;
      const have = ratio.educators_present || 0;
      const need = ratio.required_educators || 0;
      const cap = ratio.capacity || 0;
      const overCap = !!ratio.over_capacity;
      const overBy = ratio.over_capacity_by || 0;
      const atCap = !!ratio.at_capacity;
      // Ontario centres may count a supervisor at half (O. Reg. 137/15 s.8(5)) — 0.5 short is one person.
      const short = Math.ceil(Math.max(0, need - have));
      // Ontario: one adult where two are required (s.8(6)), no RECE in the group (s.54).
      const issues = Array.isArray(ratio.compliance_issues) ? ratio.compliance_issues : [];
      // Two independent limits: the educator:child RATIO and the room's licensed
      // CAPACITY (headcount). Either one being exceeded is a red alert.
      const ratioBreached = short > 0 || ['over', 'breach', 'danger'].indexOf(ratio.status) !== -1;
      // v2 (2026-10-09): over the group-size or home limit is a breach too.
      const overGroup = !!ratio.over_group || ratio.status === 'over_group';
      const breached = ratioBreached || overCap || overGroup;
      const attention = !breached && issues.length > 0;
      const tight = !breached && (attention || ratio.status === 'warning' || ratio.status === 'tight' || atCap);
      const issueText = attention ? ((ratio.warnings || []).find((w) => /adult|RECE/.test(w)) || 'Staffing needs attention') : '';

      const label = ratioBreached ? 'RATIO BREACH' : overCap ? 'OVER CAPACITY' : overGroup ? (ratio.home ? 'OVER HOME LIMIT' : 'OVER GROUP SIZE') : attention ? 'CHECK STAFFING' : tight ? 'AT THE LIMIT' : 'RATIO OK';
      const theme = breached
        ? { bg: '#FEF2F2', border: '#DC2626', text: '#991B1B', chipBg: '#DC2626', icon: ratioBreached ? '🚨' : '⛔' }
        : tight
          ? { bg: '#FFFBEB', border: '#F59E0B', text: '#92400E', chipBg: '#F59E0B', icon: '⚠️' }
          : { bg: '#F0FDF4', border: '#16A34A', text: '#166534', chipBg: '#16A34A', icon: '✅' };

      const headline = ratioBreached
        ? `${short} more educator${short === 1 ? '' : 's'} needed`
        : overCap
          ? `${overBy} child${overBy === 1 ? '' : 'ren'} over room capacity`
          : overGroup
            ? ((ratio.warnings && ratio.warnings[0]) || 'Over the group size limit')
          : attention
            ? issueText
          /* Was `${have}/${need || 1} educator` - "2/1 educator" with nobody in the room, because a
             need of 0 was shown as 1 and "have/need" read as a fraction. Say each number plainly
             (2026-11-03). Children = signed in to this room; educators = clocked in for it. */
          : `${kids} ${kids === 1 ? 'child' : 'children'} here · ${have} educator${have === 1 ? '' : 's'} on`
            + (need > 0 ? ` · ${need} needed` : '');

      // Always surface BOTH limits so an educator sees at a glance which is at risk.
      const pct = cap > 0 ? Math.round((kids / cap) * 100) : 0;
      const sub = `Ratio ${ratio.ratio_target || (have + ':' + kids)}`
        + (cap > 0 ? ` · Capacity ${kids}/${cap} (${pct}%)` : ` · ${kids} in room`);

      const bar = Dom.el('div', {
        class: 'kt-ratio-bar' + (breached ? ' breached' : ''),
        style: `display:flex;align-items:center;gap:10px;width:100%;box-sizing:border-box;`
          + `background:${theme.bg};border:2px solid ${theme.border};--kt-edge:${theme.border};`
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
      /* RATIO ENGINE v2 (2026-10-09): why this many — the jurisdiction's rule when one applies
         ("Youngest child: Under 12 months → 1:5 (Ohio)") — and anything else the rules found
         (group size, home limits, a second adult on site, nap-time relief). One line each. */
      const notes = [];
      if (ratio.reason) notes.push(ratio.reason);
      (ratio.warnings || []).forEach((w) => { if ((!overGroup || w !== ratio.warnings[0]) && w !== issueText) notes.push('⚠️ ' + w); });
      if (ratio.home && ratio.home.own_children_note) notes.push(ratio.home.own_children_note);
      if (ratio.nap && ratio.nap.note) notes.push('😴 ' + ratio.nap.note);
      notes.slice(0, 4).forEach((n) => txt.appendChild(Dom.el('div', {
        style: `font-size:11px;color:${theme.text};opacity:.8;margin-top:2px;line-height:1.35;`,
      }, n)));
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
        /* WHICH KIND of empty. A room with nine children in it Monday to Friday is not
           "no children enrolled" on a Saturday — it is closed today, and telling an
           educator otherwise reads as the app having lost their room. The server sends
           `enrolled_total` (ignoring the day filter) and the day's name so the two can
           be told apart.

           The day name is NEVER computed here: new Date().getDay() names the device's
           day, not the agency's, and after 8pm they are not even the same date. If the
           API did not say, the wording stays vague rather than becoming wrong. */
        var dayName = data.day && data.day.label;
        var enrolledElsewhere = Number(data.enrolled_total) > 0;

        if (enrolledElsewhere && dayName) {
          grid.appendChild(emptyState('🗓️', 'No children scheduled for ' + dayName,
            data.enrolled_total + ' child' + (data.enrolled_total === 1 ? '' : 'ren')
            + ' enrolled in this room, none booked in today.'));
        } else if (enrolledElsewhere) {
          grid.appendChild(emptyState('🗓️', 'No children scheduled today',
            data.enrolled_total + ' child' + (data.enrolled_total === 1 ? '' : 'ren')
            + ' enrolled in this room, none booked in today.'));
        } else {
          grid.appendChild(emptyState('👶', 'No children enrolled',
            'Children enrolled in this room will appear here.'));
        }
        container.appendChild(grid);
        return;
      }

      /* One action for a whole room. Sunscreen before going outside, the afternoon
         snack, everyone down for a nap - these are things you do to the ROOM, and
         logging them one child at a time was the most repetitive part of the day. */
      /* The "Log for several children" button lived here. Removed 2026-08-26 at
         Anthony's request: bulk logging belongs to the Daily log (#care-log),
         where "All children" already covers it. Two routes to the same action,
         on two different tables, is how the roster quick-log and the care log
         drifted apart in the first place. openBulkLog() is left in place for the
         roster path that still calls it. */

      paintCircles(container, data);
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
    /* Reported absent today (2026-10-05): a parent's absence used to leave this card
       reading NOT IN with Check in first. It now says ABSENT, who reported it and why,
       and Check in drops to a small secondary button for the child who turns up anyway. */
    const _abs = (_st === 'away' && child.absence) ? child.absence : null;
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
      in:   ['#15803D', 'rgba(22,163,74,.14)',  '#16A34A', '✓ AT CENTRE', child.arrived_at || ''],
      out:  ['#B45309', 'rgba(245,158,11,.16)', '#F59E0B', '← NOT AT CENTRE', child.departed_at || ''],
      away: ['#475569', 'rgba(148,163,184,.18)', '#94A3B8', 'NOT ARRIVED', ''],
      absent: ['#9A3412', 'rgba(251,146,60,.18)', '#F97316', '🏠 ABSENT', ''],
    };
    // Late drop-off (2026-11-06): the family said they'll be in later; Safe Arrival waits until then.
    PILL.late = ['#3730A3', 'rgba(99,102,241,.14)', '#6366F1', '⏰ LATE DROP-OFF', child.running_late ? 'by ' + child.running_late.by : ''];
    const _p = _abs ? PILL.absent : (_st === 'away' && child.running_late ? PILL.late : (PILL[_st] || PILL.away));
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
    if (_abs) {
      const why = String(_abs.reason || '').replace(/_/g, ' ');
      const who = _abs.by_parent ? 'reported by parent' : ('reported by ' + (_abs.by_name || 'staff'));
      card.appendChild(Dom.el('div', { class: 'child-card-absence', style: 'margin-top:7px;font-size:12.5px;color:#9A3412;line-height:1.4;' },
        'Absent · ' + who + (why ? ' — ' + why.charAt(0).toUpperCase() + why.slice(1) : '') + (_abs.note ? ': “' + _abs.note + '”' : '')));
    }

    // Late drop-off / late pickup notice, and who it came from (staff or a parent).
    const _lateRow = (_st === 'away' && !_abs) ? child.running_late : (_st === 'in' ? child.late_pickup : null);
    if (_lateRow) {
      card.appendChild(Dom.el('div', { class: 'child-card-late', style: 'margin-top:7px;font-size:12.5px;color:#3730A3;line-height:1.4;font-weight:600;' },
        (_st === 'in' ? '⏰ Late pickup · by ' : '⏰ Late drop-off · by ') + _lateRow.by + (_lateRow.note ? ': “' + _lateRow.note + '”' : '')
        + ' — no Safe Arrival alerts before then'));
    }

    // Photo permission (2026-11-06)
    if (PHOTO_MARK[child.photo_consent]) {
      card.appendChild(Dom.el('div', { class: 'child-card-photo', style: 'margin-top:6px;font-size:12px;color:' + (child.photo_consent === 'none' ? '#B91C1C' : '#92400E') + ';' },
        PHOTO_MARK[child.photo_consent][0] + ' ' + (child.photo_consent === 'none' ? 'No photos' : 'Family-only photos')));
    }

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
    /* flex-wrap, because on a narrow card two buttons on one line do not fit and the
       second one was being CUT OFF at the card's edge rather than moving down (Anthony,
       2026-08-30: "the absent button looks to be cut off"). */
    const actions = Dom.el('div', { class: 'child-card-actions', style: 'display:flex;flex-wrap:wrap;gap:8px;margin-top:10px;' });
    /* min-width:0 is the other half. A flex item defaults to min-width:auto, i.e. "never
       narrower than my own content", so a button with flex:0 0 auto refuses to shrink and
       simply overflows. Put here, on the shared primitive, so every button on this card
       can shrink — not just the one that was reported. Same trap as the grid rule in
       kt-layout: the default min sizing is what breaks narrow layouts. */
    const bigBtn = 'padding:11px 12px;border-radius:12px;font-size:14px;font-weight:800;border:none;cursor:pointer;display:inline-flex;align-items:center;justify-content:center;gap:6px;line-height:1;min-width:0;';
    if (child.is_at_centre) {
      actions.appendChild(Dom.el('button', {
        style: bigBtn + 'flex:1 1 40%;background:#EEF2F6;color:#0f172a;',
        onClick: (e) => { e.stopPropagation(); openQuickLog(child); },
      }, '➕ Log'));
      actions.appendChild(Dom.el('button', {
        style: bigBtn + 'flex:1 1 60%;background:linear-gradient(135deg,#F59E0B,#D97706);color:#fff;box-shadow:0 6px 14px -7px rgba(217,119,6,.7);',
        onClick: (e) => { e.stopPropagation(); confirmCheckOut(child, card); },
      }, '← Check out'));
      actions.appendChild(Dom.el('button', {
        style: bigBtn + 'flex:1 1 auto;padding:8px 12px;font-size:13px;background:#EEF2FF;color:#3730A3;border:1.5px solid #C7D2FE;',
        title: (child.display_name || 'This child') + '\u2019s family will pick up late today: the end-of-day alerts wait for that time',
        onClick: (e) => { e.stopPropagation(); openLate(child, 'departure'); },
      }, child.late_pickup ? '⏰ Pickup · ' + child.late_pickup.by : '⏰ Late pickup'));
    } else if (_abs) {
      // Absent today: no primary button, and no second "Absent" (it already is).
      actions.appendChild(Dom.el('button', {
        style: bigBtn + 'flex:0 1 auto;padding:8px 12px;font-size:13px;background:#fff;color:#15803D;border:1.5px solid rgba(22,163,74,.35);',
        title: (child.display_name || 'This child') + ' was reported absent. Check them in only if they arrived after all.',
        onClick: (e) => { e.stopPropagation(); doCheckIn(child, card); },
      }, '✓ Check in anyway'));
    } else {
      actions.appendChild(Dom.el('button', {
        style: bigBtn + 'flex:1 1 auto;background:linear-gradient(135deg,#16A34A,#15803D);color:#fff;box-shadow:0 6px 14px -7px rgba(21,128,61,.7);',
        onClick: (e) => { e.stopPropagation(); doCheckIn(child, card); },
      }, '✓ Check in'));
      // Mark absent — the other half of "who is coming in today", and until now
      // something only a parent could record even though the educator is usually
      // told first. Same endpoint the parent uses; the API already allows staff of
      // the child's centre.
      actions.appendChild(Dom.el('button', {
        // 1 1 auto, not 0 0 auto: it may shrink (and wrap) rather than run off the card.
        style: bigBtn + 'flex:1 1 auto;background:#fff;color:#B45309;border:1.5px solid #FDE68A;',
        title: 'Report ' + (child.display_name || 'this child') + ' absent today',
        onClick: (e) => { e.stopPropagation(); markAbsent(child, card); },
      }, '🏠 Absent'));
      // Late drop-off (2026-11-06, Anthony: "there should be a Late drop off on the child to allow educators to
      // select this to reduce the safe arrival checking"). Not arrived yet today only.
      if (_st === 'away') {
        actions.appendChild(Dom.el('button', {
          style: bigBtn + 'flex:1 1 100%;background:#EEF2FF;color:#3730A3;border:1.5px solid #C7D2FE;',
          title: (child.display_name || 'This child') + '\u2019s family will drop off late today: Safe Arrival waits for that time',
          onClick: (e) => { e.stopPropagation(); openLate(child, 'arrival'); },
        }, child.running_late ? '⏰ Late drop-off · ' + child.running_late.by : '⏰ Late drop-off'));
      }
    }
    // Supplies from home (2026-10-23): what is outstanding, and asking for more.
    card.appendChild(Dom.el('div', { 'data-kt-sup-child': String(child.id) }));
    if (window.KT && KT.Supplies) {
      actions.appendChild(Dom.el('button', {
        style: bigBtn + 'flex:0 1 auto;padding:8px 11px;font-size:13px;background:#fff;color:#0E7490;border:1.5px solid #A5F3FC;',
        title: 'Ask ' + (child.display_name || 'this child') + '\u2019s family to bring supplies',
        onClick: (e) => { e.stopPropagation(); KT.Supplies.request([{ id: child.id, name: child.display_name }], () => { const g = card.closest('.educator-roster'); if (g) KT.Supplies.decorate(g, currentRoomId); }); },
      }, '🧺 Supplies'));
    }
    card.appendChild(actions);

    return card;
  }


  /* ═══ TODAY AS A RING OF FACES (2026-10-31, Anthony: "can we just have the kids display pic in a cool circular
     form and when you click it it pops up quick actions to check in, request supplies, log and check out - the
     circle should keep the time clocked in and turn glowing green").
     One circle per child: a soft breathing green glow while they are here (with the check-in time and how long),
     a calm slate ring once picked up, amber when reported absent, grey when not in yet. A tap opens the quick
     actions, which call the SAME check-in / check-out / log / supplies flows the old cards used. */
  let kidFilter = (function () { try { return sessionStorage.getItem('kt_today_filter') || 'all'; } catch (e) { return 'all'; } })();

  /* Late drop-off / late pickup from the card list (the ring of faces has the same thing as a tile). */
  function openLate(c, kind) {
    if (!(window.KT && KT.runningLate)) return;
    const first = c.display_name || c.first_name || 'This child';
    KT.runningLate({ child_id: c.id, name: [first, c.last_name].filter(Boolean).join(' '), first_name: first,
      late: kind === 'departure' ? c.late_pickup : c.running_late },
      { kind: kind, onDone: function () { if (KT.Shell && KT.Shell.renderScreen) KT.Shell.renderScreen(); } });
  }
  function kidStatus(c) {
    if (c.is_at_centre) return 'in';
    if (c.status === 'in') return 'out';              // just checked out: the cached status lags a moment
    if (c.absence && c.status !== 'out') return 'absent';
    return c.status === 'out' ? 'out' : 'away';
  }
  function kidShort(c) {
    const first = c.display_name || c.first_name || 'Child';
    const li = (c.last_name || '').trim().charAt(0);
    return first + (li ? ' ' + li + '.' : '');
  }
  function sinceText(iso) {
    if (!iso) return '';
    const t = Date.parse(iso);
    if (!isFinite(t)) return '';
    const m = Math.max(0, Math.round((Date.now() - t) / 60000));
    return m < 60 ? m + 'm' : Math.floor(m / 60) + 'h' + (m % 60 ? ' ' + (m % 60) + 'm' : '');
  }
  function kidPhoto(c, px) {
    const sex = (window.KT && KT.normSex) ? (KT.normSex(c.gender) || (KT.guessSex ? KT.guessSex(c.display_name || c.first_name || '') : 'male')) : '';
    const emoji = (window.KT && KT.emojiFor) ? KT.emojiFor(sex, true) : '🧒';
    const ph = Dom.el('span', { class: 'kt-kr-ph', 'aria-hidden': 'true', 'data-kt-noface': '1', style: 'font-size:' + Math.round(px * 0.5) + 'px;' }, emoji);
    if (c.photo_url) {
      const img = Dom.el('img', { src: ktAbsUrl(c.photo_url), alt: '', loading: 'lazy', decoding: 'async', draggable: 'false' });
      img.addEventListener('error', function () { img.remove(); });
      ph.appendChild(img);
    }
    return ph;
  }

  function ensureCircleCss() {
    if (document.getElementById('kt-kr-css')) return;
    const st = document.createElement('style'); st.id = 'kt-kr-css';
    st.textContent = ''
      + '.kt-kr{margin:4px 0 8px;}'
      + '.kt-kr-top{display:flex;align-items:center;gap:14px;background:#fff;border:1px solid #E6EBF1;border-radius:16px;padding:12px 14px;margin-bottom:10px;}'
      + '.kt-kr-top svg{flex:0 0 auto;display:block;}'
      + '.kt-kr-sum b{display:block;font-size:17px;font-weight:800;color:#0F172A;line-height:1.2;}'
      + '.kt-kr-sum span{display:block;font-size:12.5px;color:#64748B;margin-top:2px;line-height:1.35;}'
      + '.kt-kr-tabs{display:flex;gap:4px;margin:0 0 12px;}'
      + '.kt-kr-tabs>button{border:0;background:transparent;font-size:13px;font-weight:700;color:#475569;padding:7px 12px;border-radius:9px;cursor:pointer;white-space:nowrap;}'
      + '.kt-kr-tabs>button .kt-kr-n{margin-left:5px;font-variant-numeric:tabular-nums;opacity:.75;}'
      + '.kt-kr-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(112px,1fr));gap:18px 10px;padding:6px 2px 4px;}'
      + '.kt-kr-kid{appearance:none;border:0;background:transparent;padding:6px 2px;margin:0;cursor:pointer;display:flex;flex-direction:column;align-items:center;gap:7px;min-width:0;border-radius:16px;font:inherit;color:inherit;-webkit-tap-highlight-color:transparent;}'
      + '.kt-kr-kid:focus-visible{outline:3px solid #2F8C86;outline-offset:2px;}'
      + '.kt-kr-kid:active .kt-kr-ring{transform:scale(.96);}'
      + '.kt-kr-ring{position:relative;width:84px;height:84px;border-radius:50%;padding:3px;background:#CBD5E1;transition:transform .15s ease;flex:0 0 auto;}'
      + '.kt-kr-ph{position:relative;display:flex;align-items:center;justify-content:center;width:100%;height:100%;border-radius:50%;overflow:hidden;background:#F1F5F9;border:3px solid #fff;box-sizing:border-box;line-height:1;}'
      + '.kt-kr-ph img{position:absolute;inset:0;width:100%;height:100%;object-fit:cover;}'
      + '.kt-kr-kid[data-st="away"] .kt-kr-ph{filter:grayscale(.55) opacity(.82);}'
      + '.kt-kr-kid[data-st="in"] .kt-kr-ring{background:linear-gradient(135deg,#4ADE80,#16A34A);}'
      + '.kt-kr-kid[data-st="in"] .kt-kr-ring::before{content:"";position:absolute;inset:-4px;border-radius:50%;box-shadow:0 0 0 2px rgba(34,197,94,.35),0 0 18px 5px rgba(34,197,94,.55);opacity:.55;animation:ktKrGlow 2.8s ease-in-out infinite;pointer-events:none;will-change:opacity;}'
      + '@keyframes ktKrGlow{0%,100%{opacity:.35}50%{opacity:1}}'
      + '@media (prefers-reduced-motion:reduce){.kt-kr-kid[data-st="in"] .kt-kr-ring::before{animation:none;opacity:.8;}}'
      + '.kt-kr-kid[data-st="out"] .kt-kr-ring{background:linear-gradient(135deg,#94A3B8,#64748B);}'
      + '.kt-kr-kid[data-st="absent"] .kt-kr-ring{background:linear-gradient(135deg,#FBBF24,#F59E0B);}'
      + '.kt-kr-dot{position:absolute;top:1px;right:1px;min-width:20px;height:20px;border-radius:999px;border:2px solid #fff;display:flex;align-items:center;justify-content:center;font-size:11px;font-weight:800;line-height:1;box-sizing:border-box;}'
      + '.kt-kr-dot.alert{background:#DC2626;color:#fff;}'
      + '.kt-kr-dot.sup{top:auto;bottom:1px;right:auto;left:1px;background:#FEF3C7;}'
      + '.kt-kr-name{font-size:13.5px;font-weight:800;color:#0F172A;max-width:100%;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}'
      + '.kt-kr-pill{display:inline-flex;align-items:center;justify-content:center;gap:4px;font-size:11px;font-weight:800;line-height:1.2;padding:3px 8px;border-radius:999px;white-space:normal;text-align:center;max-width:100%;box-sizing:border-box;}'
      + '.kt-kr-kid[data-st="in"] .kt-kr-pill{background:#DCFCE7;color:#166534;}'
      + '.kt-kr-kid[data-st="out"] .kt-kr-pill{background:#E2E8F0;color:#334155;}'
      + '.kt-kr-kid[data-st="absent"] .kt-kr-pill{background:#FEF3C7;color:#92400E;}'
      + '.kt-kr-kid[data-st="away"] .kt-kr-pill{background:#F1F5F9;color:#64748B;}'
      + '.kt-kr-since{font-size:11px;font-weight:700;color:#15803D;margin-top:-3px;font-variant-numeric:tabular-nums;white-space:nowrap;}'
      + '.kt-kr-kid[data-st="out"] .kt-kr-since{color:#64748B;}'
      /* The "+" on every child (2026-11-04): tells an educator the circle opens more. Bottom-right; the supplies 🧺 sits
         bottom-left. */
      + '.kt-kr-plus{position:absolute;right:0;bottom:0;width:24px;height:24px;border-radius:50%;border:2px solid #fff;background:#0F6E80;color:#fff;display:flex;align-items:center;justify-content:center;font:700 17px/1 system-ui,-apple-system,sans-serif;box-sizing:border-box;box-shadow:0 2px 6px rgba(15,23,42,.25);pointer-events:none;transition:transform .15s ease;}'
      + '.kt-kr-kid:hover .kt-kr-plus{transform:scale(1.12);}'
      + '.kt-kr-empty{grid-column:1/-1;text-align:center;color:#64748B;font-size:13.5px;padding:22px 8px;}'
      + '.kt-kr-pop-head{display:flex;align-items:center;gap:14px;margin:0 0 14px;}'
      + '.kt-kr-pop-head .kt-kr-ring{width:72px;height:72px;}'
      + '.kt-kr-pop-head b{display:block;font-size:18px;font-weight:800;color:#0F172A;line-height:1.2;}'
      + '.kt-kr-pop-head span.sub{display:block;font-size:13px;color:#64748B;margin-top:3px;}'
      /* FLOATING SHORTCUTS (2026-11-04): the card opens beside the child tapped, like the chat's + shortcuts -
         a compact head, then round tiles (tinted circle + a short name), four across. */
      + '.kt-kr-float{position:fixed;z-index:2147483500;width:218px;max-width:calc(100vw - 16px);max-height:calc(100vh - 16px);overflow-y:auto;box-sizing:border-box;background:rgba(15,23,42,.58);-webkit-backdrop-filter:blur(20px) saturate(1.6);backdrop-filter:blur(20px) saturate(1.6);border:1px solid rgba(255,255,255,.16);border-radius:20px;box-shadow:0 20px 48px rgba(0,0,0,.35),inset 0 1px 0 rgba(255,255,255,.12);color:#fff;padding:10px 6px 6px;animation:kt-kr-float-in .16s cubic-bezier(.2,.9,.3,1.2);}'
      + '@supports not ((backdrop-filter:blur(1px)) or (-webkit-backdrop-filter:blur(1px))){.kt-kr-float{background:rgba(15,23,42,.92);}}'
      + '.kt-kr-scrim{position:fixed;inset:0;z-index:2147483499;background:rgba(15,23,42,.32);animation:kt-kr-scrim-in .16s ease-out;}'
      + '@keyframes kt-kr-scrim-in{from{opacity:0;}to{opacity:1;}}'
      + '.kt-kr-float .kt-kr-pop-head b{color:#fff;}'
      + '@keyframes kt-kr-float-in{from{opacity:0;transform:translateY(6px) scale(.94);}to{opacity:1;transform:none;}}'
      + '.kt-kr-float .kt-kr-pop-head{gap:8px;margin:0 4px 6px;padding-right:28px;position:relative;}'
      + '.kt-kr-float .kt-kr-pop-head .kt-kr-ring{width:36px;height:36px;}'
      + '.kt-kr-float .kt-kr-pop-head b{font-size:14px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}'
      + '.kt-kr-pm{display:inline-block;margin-top:3px;padding:1px 7px;border-radius:999px;font-size:11px;font-weight:800;line-height:1.5;white-space:nowrap;}'
      + '.kt-kr-pm.family{background:rgba(253,230,138,.95);color:#78350F;}'
      + '.kt-kr-pm.none{background:rgba(254,202,202,.95);color:#7F1D1D;}'
      + '.kt-kr-float .kt-kr-pop-head span.sub{font-size:12px;margin-top:1px;}'
      + '.kt-kr-float .kt-kr-x{position:absolute;top:-2px;right:-2px;width:26px;height:26px;border-radius:50%;border:0;background:rgba(255,255,255,.14);color:#fff;font-size:16px;line-height:1;cursor:pointer;display:inline-flex;align-items:center;justify-content:center;}'
      + '.kt-kr-float .kt-kr-flags{margin:0 4px 8px;}'
      + '.kt-kr-float .kt-kr-last{margin:0 4px 8px;padding:6px 9px;font-size:12px;background:rgba(248,250,252,.7);}'
      + '.kt-kr-float .kt-kr-tiles{display:flex;flex-direction:column;gap:2px;}'
      + '.kt-kr-float .kt-kr-tile{display:grid;grid-template-columns:32px minmax(0,1fr);column-gap:10px;align-items:center;justify-items:start;text-align:left;border:0;background:none;border-radius:10px;padding:4px 6px;min-height:0;font-size:13.5px;font-weight:700;color:#fff;transition:background .12s ease;}'
      + '.kt-kr-float .kt-kr-tile:hover,.kt-kr-float .kt-kr-tile:focus-visible{background:rgba(255,255,255,.12);outline:none;}'
      + '.kt-kr-float .kt-kr-tile .ico{width:32px;height:32px;border-radius:50%;display:inline-flex;align-items:center;justify-content:center;font-size:16px;background:var(--kt-kr-tint,#F1F5F9);transition:transform .12s ease;}'
      + '.kt-kr-float .kt-kr-tile:hover .ico,.kt-kr-float .kt-kr-tile:focus-visible .ico{transform:scale(1.06);}'
      + '.kt-kr-float .kt-kr-tile.go,.kt-kr-float .kt-kr-tile.out{background:none;color:#fff;}'
      + '.kt-kr-float .kt-kr-tile.go:hover,.kt-kr-float .kt-kr-tile.out:hover{background:rgba(255,255,255,.12);}'
      + '.kt-kr-float .kt-kr-tile.go .ico{background:linear-gradient(135deg,#16A34A,#15803D);color:#fff;}'
      + '.kt-kr-float .kt-kr-tile.out .ico{background:linear-gradient(135deg,#F59E0B,#D97706);color:#fff;}'
      + '.kt-kr-float .kt-kr-tile .lbl{line-height:1.2;max-width:100%;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;}'
      + '.kt-kr-float .kt-kr-tile small{display:block;align-self:start;font-size:11.5px;font-weight:600;color:#64748B;line-height:1.25;max-width:100%;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;}'
      + '.kt-kr-float .kt-kr-tile.go small,.kt-kr-float .kt-kr-tile.out small{color:#64748B;}'
      + '.kt-kr-float .kt-kr-tile[disabled]{opacity:.4;}'
      + '.kt-kr-float [data-kt-sup-child]:not(:empty){margin:8px 2px 0;padding:8px;border-radius:12px;background:rgba(255,255,255,.94);color:#0F172A;}'
      + '.kt-kr-tiles{display:grid;grid-template-columns:1fr 1fr;gap:10px;}'
      + '.kt-kr-tile{appearance:none;border:1.5px solid #E2E8F0;background:#fff;border-radius:14px;padding:14px 10px;min-height:84px;display:flex;flex-direction:column;align-items:center;justify-content:center;gap:6px;cursor:pointer;font:inherit;font-size:14px;font-weight:800;color:#0F172A;text-align:center;}'
      + '.kt-kr-tile .ico{font-size:24px;line-height:1;}'
      + '.kt-kr-tile small{font-size:11.5px;font-weight:600;color:#64748B;}'
      + '.kt-kr-tile:focus-visible{outline:3px solid #2F8C86;outline-offset:2px;}'
      + '.kt-kr-tile.go{background:linear-gradient(135deg,#16A34A,#15803D);border-color:transparent;color:#fff;}'
      + '.kt-kr-tile.go small{color:rgba(255,255,255,.85);}'
      + '.kt-kr-tile.out{background:linear-gradient(135deg,#F59E0B,#D97706);border-color:transparent;color:#fff;}'
      + '.kt-kr-tile.out small{color:rgba(255,255,255,.88);}'
      + '.kt-kr-tile[disabled]{opacity:.5;cursor:default;}'
      + '.kt-kr-flags{display:flex;flex-wrap:wrap;gap:6px;margin:-4px 0 12px;}'
      + '.kt-kr-last{font-size:12.5px;color:#475569;background:#F8FAFC;border-radius:10px;padding:8px 10px;margin:0 0 12px;}'
      + '@media (max-width:600px){'
      + '.kt-kr-grid{grid-template-columns:repeat(auto-fill,minmax(92px,1fr));gap:14px 6px;}'
      + '.kt-kr-ring{width:72px;height:72px;}'
      + '.kt-kr-name{font-size:12.5px;}'
      + '.kt-kr-tabs{overflow-x:auto;flex-wrap:nowrap;}'
      + '}';
    document.head.appendChild(st);
  }

  /* PHOTO PERMISSION ON THE ROSTER (2026-11-06, App\Support\MediaConsent): the roster carries photo_consent
     (none | family | group). 🚫 = the family asked that they not be photographed; 👪 = family-only photos, keep them
     out of group photos. Nothing for group. Shown before the camera comes out, not only in the tag picker. */
  const PHOTO_MARK = {
    none: ['🚫', 'No photos: their family asked that they not be photographed'],
    family: ['👪', 'Family-only photos: keep them out of group photos other families see'],
  };
  function photoMark(c) {
    const m = PHOTO_MARK[c && c.photo_consent];
    return m ? Dom.el('span', { class: 'kt-kr-photo', 'data-kttip': m[1], 'aria-label': m[1], style: 'margin-left:3px;font-size:.9em;' }, m[0]) : null;
  }

  // "· 2h" after the arrival time, "2h here" when there is no time to follow.
  function sinceLine(since, sep) { const t = sinceText(since); return t ? (sep ? ' · ' + t : t + ' here') : ''; }
  function circleFor(c) {
    const st = kidStatus(c);
    const name = kidShort(c);
    let pillTxt, aria;
    if (st === 'in') { pillTxt = 'At Centre'; aria = name + ', at Centre' + (c.arrived_at ? ' at ' + c.arrived_at : ''); }
    else if (st === 'out') { pillTxt = 'Not at Centre'; aria = name + ', not at Centre, left' + (c.departed_at ? ' at ' + c.departed_at : ''); }
    else if (st === 'absent') { pillTxt = 'Absent'; aria = name + ', reported absent'; }
    else if (c.running_late) { pillTxt = 'Late drop-off'; aria = name + ', late drop-off, expected by ' + c.running_late.by; }
    else { pillTxt = 'Not arrived'; aria = name + ', not arrived yet'; }
    const b = Dom.el('button', { type: 'button', class: 'kt-kr-kid', 'data-kt-no-tips': '1', 'data-kt-iconized': '1', 'data-kt-btn-free': '1', 'data-st': st, 'data-kid': String(c.id), 'aria-label': aria + '. Open quick actions', title: (c.display_name || name) + ' — tap for quick actions' });
    const ring = Dom.el('span', { class: 'kt-kr-ring' });
    ring.appendChild(kidPhoto(c, 84));
    if (c.urgent_flags && c.urgent_flags.length) {
      ring.appendChild(Dom.el('span', { class: 'kt-kr-dot alert', title: c.urgent_flags.map(function (f) { return f.category; }).join(', '), 'aria-hidden': 'true' }, '!'));
    }
    ring.appendChild(Dom.el('span', { class: 'kt-kr-plus', 'aria-hidden': 'true' }, '+'));
    b.appendChild(ring);
    const nameEl = Dom.el('span', { class: 'kt-kr-name' }, name);
    const pm = photoMark(c); if (pm) nameEl.appendChild(pm);
    b.appendChild(nameEl);
    const pill = Dom.el('span', { class: 'kt-kr-pill' }, pillTxt);
    b.appendChild(pill);
    // The time (and how long they have been here) on its own small line, so the pill never truncates in a 4-up phone grid.
    if (st === 'in') {
      const ln = Dom.el('span', { class: 'kt-kr-since' }, c.arrived_at || '');
      if (c.in_since) ln.appendChild(Dom.el('span', { 'data-kr-since': c.in_since, 'data-kr-sep': c.arrived_at ? '1' : '' }, sinceLine(c.in_since, !!c.arrived_at)));
      if (ln.textContent) b.appendChild(ln);
    } else if (st === 'out' && c.departed_at) {
      b.appendChild(Dom.el('span', { class: 'kt-kr-since' }, 'Left ' + c.departed_at));
    } else if (st === 'away' && c.running_late) {
      b.appendChild(Dom.el('span', { class: 'kt-kr-since' }, 'By ' + c.running_late.by));
    }
    b.addEventListener('click', function () { openKidActions(c, b); });
    return b;
  }

  function paintCircles(container, data) {
    ensureCircleCss();
    const kids = data.roster || [];
    const counts = { all: kids.length, in: 0, away: 0, out: 0, absent: 0 };
    kids.forEach(function (c) { counts[kidStatus(c)]++; });
    const wrap = Dom.el('div', { class: 'kt-kr' });   // no data-kt-noband: the filter row must become the standard tab band

    // Summary: "8 of 12 in" with a progress ring.
    const R = 22, C = 2 * Math.PI * R, frac = kids.length ? counts.in / kids.length : 0;
    const top = Dom.el('div', { class: 'kt-kr-top' });
    top.innerHTML = '<svg width="56" height="56" viewBox="0 0 56 56" aria-hidden="true" style="transform:rotate(-90deg)">'
      + '<circle cx="28" cy="28" r="' + R + '" fill="none" stroke="#E2E8F0" stroke-width="7"></circle>'
      + '<circle cx="28" cy="28" r="' + R + '" fill="none" stroke="#16A34A" stroke-width="7" stroke-linecap="round" stroke-dasharray="' + C.toFixed(1) + '" stroke-dashoffset="' + (C * (1 - frac)).toFixed(1) + '" style="transition:stroke-dashoffset .8s cubic-bezier(.22,1,.36,1)"></circle></svg>';
    const bits = [];
    if (counts.out) bits.push(counts.out + ' picked up');
    if (counts.absent) bits.push(counts.absent + ' absent');
    if (counts.away) bits.push(counts.away + ' not arrived yet');
    /* Name the room (2026-11-03): this sat under a centre-wide "at the centre" tile and beside
       Home's room count, and nothing said which children "of 2" were. Children enrolled here
       but not booked today are named too, so "of 1" can be squared with Home's "2". */
    const notBooked = Math.max(0, (Number(data.enrolled_total) || 0) - kids.length);
    if (notBooked) bits.push(notBooked + ' not booked today');
    const roomName = (data.room && data.room.name) || '';
    const sum = Dom.el('div', { class: 'kt-kr-sum' });
    sum.appendChild(Dom.el('b', {}, counts.in + ' of ' + kids.length + ' at Centre'));
    const sub = bits.length ? bits.join(' · ') : (counts.in === kids.length && kids.length ? 'Everyone is here' : 'Tap a child for quick actions');
    sum.appendChild(Dom.el('span', {}, roomName ? roomName + ' · ' + sub : sub));
    top.appendChild(sum);
    wrap.appendChild(top);

    // Filters: the portal's standard sub-tab band.
    const TABS = [['all', 'All', counts.all], ['in', 'At Centre', counts.in], ['away', 'Not arrived', counts.away + counts.absent], ['out', 'Not at Centre', counts.out]];
    if (!TABS.some(function (t) { return t[0] === kidFilter; })) kidFilter = 'all';
    const tabs = Dom.el('div', { class: 'kt-kr-tabs', role: 'tablist', 'aria-label': 'Show children' });
    const grid = Dom.el('div', { class: 'kt-kr-grid', role: 'list' });
    function paintGrid() {
      Dom.clear(grid);
      const shown = kids.filter(function (c) {
        const s = kidStatus(c);
        return kidFilter === 'all' || s === kidFilter || (kidFilter === 'away' && s === 'absent');
      });
      if (!shown.length) { grid.appendChild(Dom.el('div', { class: 'kt-kr-empty' }, 'No children here.')); return; }
      shown.forEach(function (c) { const cell = Dom.el('div', { role: 'listitem', style: 'min-width:0;display:flex;justify-content:center;' }); cell.appendChild(circleFor(c)); grid.appendChild(cell); });
      paintSupplyDots(grid);
    }
    TABS.forEach(function (t) {
      const on = t[0] === kidFilter;
      const tb = Dom.el('button', { type: 'button', role: 'tab', 'data-kt-iconized': '1', 'aria-selected': on ? 'true' : 'false', class: on ? 'active' : '', 'data-tab': t[0] }, t[1]);
      tb.appendChild(Dom.el('span', { class: 'kt-kr-n' }, String(t[2])));
      tb.addEventListener('click', function () {
        kidFilter = t[0];
        try { sessionStorage.setItem('kt_today_filter', kidFilter); } catch (e) {}
        [].forEach.call(tabs.children, function (x) { const yes = x === tb; x.setAttribute('aria-selected', yes ? 'true' : 'false'); x.classList.toggle('active', yes); });
        paintGrid();
      });
      tabs.appendChild(tb);
    });
    wrap.appendChild(tabs);
    wrap.appendChild(grid);
    paintGrid();
    container.appendChild(wrap);

    // "In · 8:42 AM · 2h 10m" keeps counting while the screen is open.
    if (container.__krTick) clearInterval(container.__krTick);
    container.__krTick = setInterval(function () {
      if (!document.body.contains(container)) { clearInterval(container.__krTick); return; }
      container.querySelectorAll('[data-kr-since]').forEach(function (n) { n.textContent = sinceLine(n.getAttribute('data-kr-since'), n.getAttribute('data-kr-sep') === '1'); });
    }, 60000);
  }

  // A small 🧺 on a circle when that family has been asked to bring something.
  let supplyCache = { room: null, at: 0, by: {} };
  function paintSupplyDots(grid) {
    if (!(window.KT && KT.Supplies) || !currentRoomId) return;
    const apply = function () {
      grid.querySelectorAll('.kt-kr-kid').forEach(function (b) {
        const n = supplyCache.by[b.getAttribute('data-kid')] || 0;
        const old = b.querySelector('.kt-kr-dot.sup'); if (old) old.remove();
        if (n) b.querySelector('.kt-kr-ring').appendChild(Dom.el('span', { class: 'kt-kr-dot sup', title: n + ' supplies request' + (n === 1 ? '' : 's') + ' open', 'aria-hidden': 'true' }, '🧺'));
      });
    };
    if (supplyCache.room === currentRoomId && Date.now() - supplyCache.at < 60000) { apply(); return; }
    Api.get('/provider/supplies?room_id=' + encodeURIComponent(currentRoomId)).then(function (r) {
      const by = {};
      ((r && r.requests) || []).forEach(function (q) { if (['open', 'coming', 'cant'].indexOf(q.status) !== -1) by[q.child_id] = (by[q.child_id] || 0) + 1; });
      supplyCache = { room: currentRoomId, at: Date.now(), by: by };
      if (document.body.contains(grid)) apply();
    }).catch(function () {});
  }

  function openKidActions(c, circleEl) {
    const st = kidStatus(c);
    const body = Dom.el('div', { 'data-kt-noband': '1' });
    const head = Dom.el('div', { class: 'kt-kr-pop-head' });
    const fake = Dom.el('span', { class: 'kt-kr-kid', 'data-st': st, style: 'padding:0;cursor:default;' });
    const ring = Dom.el('span', { class: 'kt-kr-ring' }); ring.appendChild(kidPhoto(c, 72)); fake.appendChild(ring);
    head.appendChild(fake);
    const txt = Dom.el('div', { style: 'min-width:0;' });
    txt.appendChild(Dom.el('b', {}, [c.display_name || c.first_name, c.last_name].filter(Boolean).join(' ')));
    /* Short and readable on the dark card (2026-11-06, Anthony: "the orange writing is very hard to read ... reduce
       the text"): a light pill, the full sentence in its tooltip. */
    if (PHOTO_MARK[c.photo_consent]) {
      txt.appendChild(Dom.el('span', { class: 'kt-kr-pm ' + c.photo_consent, 'data-kttip': PHOTO_MARK[c.photo_consent][1], 'aria-label': PHOTO_MARK[c.photo_consent][1] },
        PHOTO_MARK[c.photo_consent][0] + ' ' + (c.photo_consent === 'none' ? 'No photos' : 'Family-only photos')));
    }
    head.appendChild(txt);
    body.appendChild(head);

    if (c.urgent_flags && c.urgent_flags.length) {
      const fl = Dom.el('div', { class: 'kt-kr-flags' });
      c.urgent_flags.forEach(function (f) { fl.appendChild(Dom.el('span', { class: 'health-flag severity-' + f.severity, title: f.category }, f.short_label)); });
      body.appendChild(fl);
    }

    const tiles = Dom.el('div', { class: 'kt-kr-tiles', role: 'menu', 'aria-label': 'Shortcuts' });
    const TINT = ['#EDE9FE', '#FCE7F3', '#FEF3C7', '#DBEAFE', '#E0F2FE', '#DCFCE7'];
    function tile(cls, ico, label, small, fn, disabled) {
      const t = Dom.el('button', { type: 'button', class: 'kt-kr-tile ' + cls, role: 'menuitem', 'aria-label': label + (small ? ' — ' + small : ''), 'data-kt-iconized': '1' });
      if (!cls) t.style.setProperty('--kt-kr-tint', TINT[tiles.children.length % TINT.length]);
      t.appendChild(Dom.el('span', { class: 'ico', 'aria-hidden': 'true' }, ico));
      t.appendChild(Dom.el('span', { class: 'lbl' }, label));
      if (disabled) t.disabled = true;
      t.addEventListener('click', function () { closeFloat(); setTimeout(fn, 60); });
      tiles.appendChild(t);
    }
    if (st === 'in') tile('out', '⏹', 'Check out', 'Pickup', function () { confirmCheckOut(c, circleEl); });
    else if (st === 'absent') tile('go', '✅', 'Check in', 'Arrived after all', function () { doCheckIn(c, circleEl); });
    else tile('go', '✅', 'Check in', st === 'out' ? 'Back again' : 'Arrival', function () { doCheckIn(c, circleEl); });
    tile('', '📝', 'Log moment', st === 'in' ? 'Meal, nap, photo…' : 'When they are here', function () { openQuickLog(c); }, st !== 'in');
    // The observation form with this child already chosen (screen-observations reads ?child=).
    tile('', '👀', 'Observation', 'Learning & skills', function () { if (window.KT && window.KT.openObservation) window.KT.openObservation({ childId: c.id }); else location.hash = '#observation-new?child=' + c.id; });   // a popup (2026-11-09)
    if (window.KT && KT.Supplies) {
      tile('', '🧺', 'Supplies', 'Ask the family', function () {
        KT.Supplies.request([{ id: c.id, name: c.display_name }], function () { supplyCache.at = 0; const g = document.querySelector('#educatorRoster .kt-kr-grid'); if (g) paintSupplyDots(g); });
      });
    }
    /* RUNNING LATE (2026-11-06): the family said they will be in later. Safe Arrival (screen-safe-arrival.js,
       KT.runningLate) waits for that time before asking or alerting anyone. */
    /* Always on the card, so people know where it is (2026-11-06, Anthony: "i dont see the running late when tapping
       the child"). It is for a child who has not arrived yet; on anyone else a tap says why instead (a greyed tile
       could not say why on a phone). */
    /* A child who is here: the same tile logs a LATE PICKUP (2026-11-06, Anthony: "the late function should apply for
       parents dropping and picking up late"); the end-of-day check waits for that time. */
    const pickup = st === 'in';
    const lateWhy = st === 'out' ? ' has already gone home today.' : st === 'absent' ? ' is reported absent today. Check them in if they arrive after all.' : '';
    const lateNow = pickup ? c.late_pickup : c.running_late;
    tile('', '⏰', lateNow ? (pickup ? 'Pickup · ' : 'Drop-off · ') + lateNow.by : (pickup ? 'Late pickup' : 'Late drop-off'),
      pickup ? 'Picked up later today' : 'Dropped off later today', function () {
      const first = c.display_name || c.first_name || 'This child';
      if (lateWhy) { if (KT.toast) KT.toast('⏰', 'Late drop-off', first + lateWhy, '#4338CA'); return; }
      KT.runningLate({ child_id: c.id, name: [first, c.last_name].filter(Boolean).join(' '), first_name: first, late: lateNow },
        { kind: pickup ? 'departure' : 'arrival', onDone: function () { if (KT.Shell && KT.Shell.renderScreen) KT.Shell.renderScreen(); } });
    });
    if (lateWhy) tiles.lastElementChild.style.opacity = '.6';
    if (st === 'away') tile('', '🏠', 'Mark absent', 'Not coming today', function () { markAbsent(c, circleEl); });
    else tile('', '👤', 'Profile', 'Record & contacts', function () {
      location.hash = (isAgencyScope() ? '#child-detail?id=' : '#child-record?id=') + c.id;
    });
    body.appendChild(tiles);

    // What the family has already been asked to bring, with Received / Cancel.
    const sup = Dom.el('div', { 'data-kt-sup-child': String(c.id) });
    body.appendChild(sup);
    if (window.KT && KT.Supplies && currentRoomId) KT.Supplies.decorate(body, currentRoomId);

    openFloat(body, circleEl, [c.display_name || c.first_name, c.last_name].filter(Boolean).join(' '));
  }

  /* The floating shortcuts card: beside (below, else above) the child tapped, kept inside the window; closes on ✕,
     a tap outside, Escape, scrolling the page or a tile. One open at a time. */
  /* One card for both screens (2026-11-06): kt-kid-float.js holds this same card, and the parent's Today "+" opens it
     too. The copy below stays only as the fallback if that file is missing. */
  let _float = null;
  function closeFloat() {
    if (window.KT && KT.kidFloat) KT.kidFloat.close();
    if (!_float) return;
    const f = _float; _float = null;
    f.el.remove();
    if (f.scrim) f.scrim.remove();
    document.removeEventListener('pointerdown', f.outside, true);
    document.removeEventListener('keydown', f.key, true);
    window.removeEventListener('resize', closeFloat);
    window.removeEventListener('scroll', f.scroll, true);
    if (f.anchor && f.anchor.isConnected && f.focusBack) { try { f.anchor.focus({ preventScroll: true }); } catch (e) {} }
  }
  function openFloat(body, anchor, name) {
    if (window.KT && KT.kidFloat) { KT.kidFloat.open(body, anchor, name); return; }
    closeFloat();
    const el = Dom.el('div', { class: 'kt-kr-float', role: 'dialog', 'aria-label': 'Shortcuts for ' + name, 'data-no-modal-guard': '1', 'data-kt-nochrome': '1', 'data-kt-no-tips': '1' });   // short tile names, no glossary "?"
    const head = body.querySelector('.kt-kr-pop-head');
    const x = Dom.el('button', { type: 'button', class: 'kt-kr-x', 'aria-label': 'Close', 'data-kt-iconized': '1' }, '✕');
    x.addEventListener('click', function () { closeFloat(); });
    if (head) head.appendChild(x); else el.appendChild(x);
    el.appendChild(body);
    const scrim = Dom.el('div', { class: 'kt-kr-scrim', 'aria-hidden': 'true' });
    document.body.appendChild(scrim);
    document.body.appendChild(el);
    const r = anchor && anchor.getBoundingClientRect ? anchor.getBoundingClientRect() : { left: innerWidth / 2, right: innerWidth / 2, top: innerHeight / 2, bottom: innerHeight / 2, width: 0 };
    const w = el.offsetWidth, h = el.offsetHeight, vw = window.innerWidth, vh = window.innerHeight;
    let left = r.left + r.width / 2 - w / 2;
    left = Math.min(Math.max(8, left), vw - w - 8);
    let top = r.bottom + 8;
    if (top + h > vh - 8) top = r.top - h - 8;
    if (top < 8) top = Math.max(8, vh - h - 8);
    el.style.left = Math.round(left) + 'px';
    el.style.top = Math.round(top) + 'px';
    el.style.transformOrigin = (top < r.top ? 'bottom' : 'top') + ' center';
    const st = { el: el, scrim: scrim, anchor: anchor, focusBack: false };
    st.outside = function (e) { if (!el.contains(e.target)) { if (e.target === scrim) { e.preventDefault(); e.stopPropagation(); } closeFloat(); } };
    st.key = function (e) {
      if (e.key === 'Escape') { e.preventDefault(); st.focusBack = true; closeFloat(); return; }
      const items = Array.prototype.slice.call(el.querySelectorAll('.kt-kr-tile:not([disabled])'));
      const i = items.indexOf(document.activeElement);
      if (i === -1) return;
      if (e.key === 'ArrowRight' || e.key === 'ArrowDown') { e.preventDefault(); (items[i + 1] || items[0]).focus(); }
      else if (e.key === 'ArrowLeft' || e.key === 'ArrowUp') { e.preventDefault(); (items[i - 1] || items[items.length - 1]).focus(); }
    };
    // The page scrolling away from the child closes it; scrolling inside the card does not.
    st.scroll = function (e) { if (!el.contains(e.target)) closeFloat(); };
    _float = st;
    setTimeout(function () {
      if (_float !== st) return;
      document.addEventListener('pointerdown', st.outside, true);
      document.addEventListener('keydown', st.key, true);
      window.addEventListener('resize', closeFloat);
      window.addEventListener('scroll', st.scroll, true);
    }, 0);
    if (!(window.matchMedia && matchMedia('(pointer: coarse)').matches)) {
      const first = el.querySelector('.kt-kr-tile:not([disabled])');
      if (first) first.focus({ preventScroll: true });
    }
  }

  // ─── Actions ─────────────────────────────────────────────────
  // Signing a child in tells their parents and starts an attendance record that
  // gets audited — it deserves the same "are you sure" as signing them out. It's
  // also the mistake that's most annoying to undo when you tap the wrong card.
  /**
   * Report a child absent for today. Reasons match what a parent can choose, so the
   * two routes into the same record stay consistent.
   */
  function markAbsent(child, cardEl) {
    const REASONS = [
      ['sick', '🤒 Unwell'],
      // 'holiday', not 'vacation': the API accepts sick|appointment|holiday|family|other
      // and rejected 'vacation' with a 422, so this button never once worked.
      ['holiday', '🏖 Holiday'],
      ['appointment', '🩺 Appointment'],
      ['other', '📝 Other'],
    ];
    const body = Dom.el('div', {});
    body.appendChild(Dom.el('div', { style: 'font-size:13.5px;color:#475569;margin-bottom:14px;line-height:1.55;' },
      'Record ' + (child.display_name || 'this child') + ' as absent today. The office sees it straight away, and it '
      + 'stops the "not signed in yet" reminder going to their family.'));
    const grid = Dom.el('div', { style: 'display:grid;grid-template-columns:1fr 1fr;gap:8px;margin-bottom:14px;' });
    let chosen = 'sick';
    const btns = [];
    REASONS.forEach(function (r) {
      const b = Dom.el('button', {
        type: 'button',
        style: 'padding:11px 10px;border-radius:10px;border:1.5px solid #E2E8F0;background:#fff;font-size:13.5px;font-weight:700;color:#0F172A;cursor:pointer;',
      }, r[1]);
      b.addEventListener('click', function () {
        chosen = r[0];
        btns.forEach(function (x) { x.style.borderColor = '#E2E8F0'; x.style.background = '#fff'; });
        b.style.borderColor = '#1F6FB2'; b.style.background = '#EFF6FF';
      });
      btns.push(b); grid.appendChild(b);
    });
    btns[0].style.borderColor = '#1F6FB2'; btns[0].style.background = '#EFF6FF';
    body.appendChild(grid);
    const note = Dom.el('input', {
      type: 'text', maxlength: '200', placeholder: 'Anything to add (optional)',
      style: 'width:100%;padding:10px 12px;border:1.5px solid #E2E8F0;border-radius:10px;font-size:14px;box-sizing:border-box;',
    });
    body.appendChild(note);
    const msg = Dom.el('div', { style: 'font-size:12.5px;font-weight:700;margin-top:10px;' });
    body.appendChild(msg);

    // Declared locally. Assigning to a bare activeModal here threw
    // 'ReferenceError: activeModal is not defined' under 'use strict': the two
    // let activeModal declarations in this file sit in OTHER functions, so this
    // assignment had no binding and the Mark absent button crashed on tap.
    var absentModal = Modal.open({
      title: '🏠  Mark absent — ' + (child.display_name || 'child'),
      body: body,
      actions: [
        { label: 'Cancel' },
        {
          label: 'Mark absent',
          primary: true,
          onClick: async () => {
            msg.style.color = '#64748B'; msg.textContent = 'Saving…';
            try {
              // The agency's day, not the device's — a late-evening tap must not
              // record tomorrow's absence.
              const today = (window.KT && KT.agencyToday) ? KT.agencyToday() : new Date().toISOString().slice(0, 10);
              await Api.post('/parent/absences', {
                child_id: child.id, reason: chosen, note: note.value.trim(), date: today,
              });
              if (KT.toast) KT.toast('🏠', 'Marked absent', (child.display_name || 'Child') + ' — today', '#B45309');
              Modal.close();
              if (typeof refreshRoster === 'function') { try { refreshRoster(); } catch (e) {} }
              else if (KT.Shell && KT.Shell.renderScreen) KT.Shell.renderScreen();
            } catch (e) {
              msg.style.color = '#B91C1C';
              msg.textContent = (e && e.message) || 'Could not save that.';
            }
          },
        },
      ],
    });
  }

  /* Checking a child in or out here is the backup for a parent who cannot use One-Tap: a centred yellow ⚠ warning
     (KT.warnManualCheck, kt-polish.js) asks first (2026-11-06). The old wording stays as the fallback. */
  function askManualCheck(child, going, run) {
    if (window.KT && typeof KT.warnManualCheck === 'function') {
      KT.warnManualCheck({ name: child.display_name, going: going, child_id: child.id }).then(function (ok) { if (ok) run(); });
      return;
    }
    Modal.confirm({
      title: going === 'in' ? `Check ${child.display_name} in?` : `Check ${child.display_name} out?`,
      message: going === 'in'
        ? `Parents should check in with One-Tap (the centre’s QR code). Use this only if they can’t. ${child.display_name} will be marked at the centre and the family notified.`
        : `Parents should check out with One-Tap (the centre’s QR code). Use this only if they can’t. ${child.display_name} will be marked as picked up.`,
      confirmLabel: going === 'in' ? 'Yes, check in' : 'Yes, check out',
      onConfirm: run,
    });
  }

  function doCheckIn(child, cardEl) {
    // An educator must be clocked in before checking children in/out.
    if (window.KT && KT.requireClockedIn && !KT.requireClockedIn()) return;
    askManualCheck(child, 'in', async () => {
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
        child.status = 'in'; child.absence = null; child.in_since = new Date().toISOString();
        try { child.arrived_at = new Date().toLocaleTimeString([], { hour: 'numeric', minute: '2-digit' }); } catch (e) {}
        Dom.toast(`${child.display_name} checked in ✓`, 'success');
        await renderRoster(Dom.$('#educatorRoster'));
        const ri = Dom.$('#ratioIndicator'); if (ri) refreshRatio(ri);   // also update the "present" stat banner
      } catch (e) {
        Dom.toast(e.message || 'Could not check in', 'error');
      } finally {
        done();
      }
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
    askManualCheck(child, 'out', async () => {
      if (!currentRoomId) { Dom.toast('Pick a room first', 'error'); return; }
      const done = (window.KT && KT.busy) ? KT.busy(cardEl) : function () {};
      try {
        await Api.post('/provider/check-out', {
          child_id: child.id,
          room_id: currentRoomId,
        });
        child.is_at_centre = false;   // optimistic — see doCheckIn
        child.status = 'out'; child.in_since = null;
        try { child.departed_at = new Date().toLocaleTimeString([], { hour: 'numeric', minute: '2-digit' }); } catch (e) {}
        Dom.toast(`${child.display_name} checked out ✓`, 'success');
        await renderRoster(Dom.$('#educatorRoster'));
        const ri = Dom.$('#ratioIndicator'); if (ri) refreshRatio(ri);   // also update the "present" stat banner
      } catch (e) {
        Dom.toast(e.message || 'Could not check out', 'error');
      } finally {
        done();
      }
    });
  }

  /**
   * Log ONE thing for several children at once.
   *
   * Deliberately a separate flow rather than a mode bolted onto openQuickLog: the
   * per-child dialog is a fast path ("what did Ava just do?") and adding a picker to it
   * would slow the common case down to serve the rarer one.
   *
   * Children present are ticked by default, because that is nearly always the answer -
   * but every one is unticked-able, since "everyone had sunscreen" is rarely quite true.
   */
  function openBulkLog(roster) {
    const present = (roster || []).filter(c => c.is_at_centre);
    const pool = present.length ? present : (roster || []);
    if (!pool.length) {
      Dom.toast('No children in this room to log for', 'error');
      return;
    }

    const TYPES = [
      { icon: '\u{1F37D}\u{FE0F}', label: 'Meal',      type: 'meal' },
      { icon: '\u{1F34E}', label: 'Snack',     type: 'snack' },
      { icon: '\u{1F37C}', label: 'Bottle',    type: 'bottle' },
      { icon: '\u{1F9F4}', label: 'Sunscreen', type: 'sunscreen' },
      { icon: '\u{1F60A}', label: 'Mood',      type: 'mood' },
      { icon: '\u{1F634}', label: 'Nap down',  type: 'nap_start' },
      { icon: '\u{2600}\u{FE0F}', label: 'Nap woke',  type: 'nap_end' },
    ];

    let chosen = 'sunscreen';
    const body = Dom.el('div', {});

    // ── what happened ──────────────────────────────────────────────────────
    body.appendChild(Dom.el('div', {
      style: 'font-size:12px;font-weight:800;color:#64748B;text-transform:uppercase;letter-spacing:.06em;margin:0 0 8px;',
    }, 'What happened'));

    const typeWrap = Dom.el('div', { style: 'display:flex;flex-wrap:wrap;gap:8px;margin-bottom:18px;' });
    const typeBtns = {};
    const paintTypes = () => {
      Object.keys(typeBtns).forEach(t => {
        const on = (t === chosen);
        typeBtns[t].style.background = on ? '#E4EEF2' : '#fff';
        typeBtns[t].style.borderColor = on ? '#1F6080' : '#E2E8F0';
        typeBtns[t].style.color = on ? '#1F6080' : '#334155';
        typeBtns[t].style.fontWeight = on ? '800' : '600';
      });
      paintForm();
    };
    TYPES.forEach(t => {
      const b = Dom.el('button', {
        type: 'button',
        style: 'border:1.5px solid #E2E8F0;background:#fff;border-radius:10px;padding:9px 13px;'
          + 'font-size:14px;cursor:pointer;min-height:40px;display:inline-flex;align-items:center;gap:7px;',
        onClick: () => { chosen = t.type; paintTypes(); },
      }, t.icon + ' ' + t.label);
      typeBtns[t.type] = b;
      typeWrap.appendChild(b);
    });
    body.appendChild(typeWrap);

    // ── who ────────────────────────────────────────────────────────────────
    const whoHead = Dom.el('div', {
      style: 'display:flex;align-items:center;justify-content:space-between;margin:0 0 8px;',
    });
    whoHead.appendChild(Dom.el('div', {
      style: 'font-size:12px;font-weight:800;color:#64748B;text-transform:uppercase;letter-spacing:.06em;',
    }, 'Who'));
    const allBtn = Dom.el('button', {
      type: 'button',
      style: 'background:none;border:none;color:#1F6080;font-weight:700;font-size:13px;cursor:pointer;padding:4px 6px;',
    }, 'Select all');
    whoHead.appendChild(allBtn);
    body.appendChild(whoHead);

    const kidWrap = Dom.el('div', {
      style: 'display:flex;flex-direction:column;gap:2px;max-height:220px;overflow-y:auto;'
        + 'border:1px solid #E2E8F0;border-radius:10px;padding:6px;margin-bottom:16px;',
    });
    const boxes = [];
    pool.forEach(c => {
      const row = Dom.el('label', {
        style: 'display:flex;align-items:center;gap:10px;padding:8px 8px;border-radius:8px;cursor:pointer;font-size:14.5px;',
      });
      const cb = Dom.el('input', { type: 'checkbox', style: 'width:18px;height:18px;flex-shrink:0;cursor:pointer;' });
      cb.checked = true;
      cb.value = String(c.id);
      boxes.push(cb);
      row.appendChild(cb);
      row.appendChild(Dom.el('span', {}, c.display_name || c.first_name || ('Child ' + c.id)));
      if (!c.is_at_centre) {
        row.appendChild(Dom.el('span', {
          style: 'margin-left:auto;font-size:11px;color:#B45309;background:#FEF3C7;border-radius:6px;padding:1px 6px;',
        }, 'not in'));
      }
      kidWrap.appendChild(row);
    });
    body.appendChild(kidWrap);
    allBtn.addEventListener('click', () => {
      const turnOn = boxes.some(b => !b.checked);
      boxes.forEach(b => { b.checked = turnOn; });
      allBtn.textContent = turnOn ? 'Select none' : 'Select all';
    });

    // ── details, which depend on the type ──────────────────────────────────
    const formWrap = Dom.el('div', { class: 'form-grid' });
    body.appendChild(formWrap);
    let buildPayload = () => ({});

    function paintForm() {
      Dom.clear(formWrap);
      buildPayload = () => ({});

      if (chosen === 'meal' || chosen === 'snack') {
        const amount = selectInput('How much?', [
          ['all', 'All of it'], ['most', 'Most of it'], ['half', 'About half'],
          ['little', 'A little'], ['none', 'Did not eat'],
        ]);
        const items = textInput('Items (comma separated)', '', { placeholder: 'apple, crackers' });
        items.row.classList.add('full-width');
        formWrap.appendChild(amount.row);
        formWrap.appendChild(items.row);
        buildPayload = () => ({
          amount: amount.input.value,
          items: items.input.value.split(',').map(s => s.trim()).filter(Boolean),
        });
      } else if (chosen === 'bottle') {
        const amt = textInput('Amount', '', { placeholder: 'e.g. 120 ml' });
        amt.row.classList.add('full-width');
        formWrap.appendChild(amt.row);
        buildPayload = () => ({ amount: amt.input.value });
      } else if (chosen === 'mood') {
        const mood = selectInput('How are they?', [
          ['happy', '\u{1F60A} Happy'], ['calm', '\u{1F60C} Calm'], ['tired', '\u{1F634} Tired'],
          ['upset', '\u{1F622} Upset'], ['unwell', '\u{1F912} Unwell'],
        ]);
        mood.row.classList.add('full-width');
        formWrap.appendChild(mood.row);
        buildPayload = () => ({ score: mood.input.value });
      }
      // sunscreen / nap_start / nap_end carry no extra fields - the time and the note
      // below are the whole record.

      const when = textInput('Time it happened', nowHHMM(), { type: 'time' });
      formWrap.appendChild(when.row);
      const note = textInput('Note (optional)', '', { placeholder: 'Anything worth saying' });
      note.row.classList.add('full-width');
      formWrap.appendChild(note.row);
      formWrap._when = when;
      formWrap._note = note;
    }

    function nowHHMM() {
      const d = new Date();
      return String(d.getHours()).padStart(2, '0') + ':' + String(d.getMinutes()).padStart(2, '0');
    }

    paintTypes();

    Modal.open({
      title: 'Log for several children',
      body,
      actions: [
        { label: 'Cancel' },
        {
          label: 'Save',
          primary: true,
          busyLabel: 'Logging…',
          /* Modal.open calls this with NO arguments and reads the RETURN value:
             `false` keeps the dialog open (and restores the button), anything else
             closes it. There is no done() callback - getting that wrong would have
             closed the dialog on a validation error and thrown the entry away. */
          handler: async () => {
            const ids = boxes.filter(b => b.checked).map(b => parseInt(b.value, 10));
            if (!ids.length) { Dom.toast('Pick at least one child', 'error'); return false; }

            // "HH:MM" today, in the browser's zone, sent as ISO - the same shape the
            // single-child log sends, so both land on the same instant.
            let occurredAt = null;
            try {
              const v = formWrap._when && formWrap._when.input.value;
              if (v) {
                const d = new Date();
                const [hh, mm] = v.split(':');
                d.setHours(parseInt(hh, 10), parseInt(mm, 10), 0, 0);
                occurredAt = d.toISOString();
              }
            } catch (e) {}

            try {
              const res = await Api.post('/provider/events/bulk', {
                child_ids: ids,
                room_id: currentRoomId,
                event_type: chosen,
                payload: buildPayload(),
                occurred_at: occurredAt,
                notes: (formWrap._note && formWrap._note.input.value) || null,
              });
              const refused = (res && res.refused) || [];
              const base = (res && res.message) || 'Logged';
              Dom.toast(base + (refused.length ? ' · ' + refused.length + ' skipped' : ''), 'success');
              await renderRoster(Dom.$('#educatorRoster'));
              return true;                       // closes the dialog
            } catch (e) {
              Dom.toast(e.message || 'Could not save', 'error');
              return false;                      // keeps it open so the entry is not lost
            }
          },
        },
      ],
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
        // Cream applied (2026-10-23): on top of the type, not one of them.
        fields.cream = Dom.el('label', { class: 'form-row full-width', style: 'display:flex;align-items:center;gap:9px;font-weight:700;cursor:pointer;' },
          Dom.el('input', { type: 'checkbox', style: 'width:18px;height:18px;' }), Dom.el('span', {}, '🧴 Cream applied'));
        payloadBuilder = () => Object.assign({ type: fields.type.input.value }, fields.cream.querySelector('input').checked ? { cream: true } : {});
        fields.type.row.classList.add('full-width');
        body.appendChild(fields.type.row);
        body.appendChild(fields.cream);
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

  /* THE FIRST VISIT TOO (2026-11-03). An educator's Home has a Today tile, and the first tap of the visit still
     waited on six requests. Once Home has been quiet for a few seconds, Today's answers are fetched once into the
     same memory (an educator who is not an admin; visible tab only), so even the first visit opens drawn. */
  (function warmToday() {
    let done = false;
    function educator() {
      try {
        const va = sessionStorage.getItem('kt_view_as') || '';
        if (va) return va === 'educator';
        const u = JSON.parse(sessionStorage.getItem('kt_user') || '{}');
        return (u.roles || []).indexOf('educator') !== -1 && !isAgencyScope();
      } catch (e) { return false; }
    }
    async function warm() {
      if (done || document.hidden || !educator() || !sessionStorage.getItem('kt_token')) return;
      if (!/^#?(home)?$/.test(location.hash || '')) return;
      done = true;
      try {
        if (!bootstrapCache) bootstrapCache = await Api.get('/provider/bootstrap');
        const rooms = (bootstrapCache && bootstrapCache.rooms) || [];
        const rid = (currentRoomId && rooms.find(r => r.id === currentRoomId)) ? currentRoomId : (rooms[0] && rooms[0].id);
        const jobs = [['brief', '/provider/day-brief'], ['score', '/educator/day-score'], ['forms', '/educator/forms-today']];
        if (rid) jobs.push(['ratio|' + rid, `/provider/rooms/${rid}/ratio?t=${Date.now()}`]);
        jobs.forEach(function (j) { if (!memoGet(j[0])) Api.get(j[1]).then(function (d) { memoPut(j[0], d); }, function () {}); });
        if (rid && !rosterCache[rid]) Api.get(`/provider/rooms/${rid}/roster?t=${Date.now()}`).then(function (d) { rosterCache[rid] = d; }, function () {});
      } catch (e) { done = false; }
    }
    function arm() { setTimeout(warm, 5000); }
    // Fetched later by Home's idle preload (screen-role-home.js): Home has already been quiet - warm almost at once.
    if (document.readyState === 'complete') setTimeout(warm, 600); else window.addEventListener('load', arm);
    window.addEventListener('hashchange', function () { if (!done) arm(); });
  })();

  // Directors can also visit /today which shows this same view
  // (already wired in screen-director.js via renderTodayDirector)

})(window);
