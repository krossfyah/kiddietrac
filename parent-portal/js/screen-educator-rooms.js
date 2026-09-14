/* ═══════════════════════════════════════════════════════════════════
   KIDDIETRAC — Room assignments

   educator_rooms decides whose children a member of staff sees, and whether attendance
   and sign-out figures are attributed to a person or fall back to the whole centre.
   This is the one screen that fills it in.

   ONE PERSON AT A TIME (2026-09-14, Anthony: "use a drop down to select educator and
   show all rooms available to be assigned with an edit and saved view").

   This replaced a matrix — everybody down, every room across. The matrix was good at
   exactly one thing, showing gaps across the whole agency at a glance, and bad at what
   the screen is actually for: sitting down and sorting out one person's rooms. Nine
   columns of identically-named rooms is a lot of care to spend on one tick, and it only
   got worse as the agency grew. Pick the person, see their rooms, done.

   What carried over, because each was earned the hard way:

   - ANY EDUCATOR, ANY ROOM. Every room in the agency is offered to everyone. The
     original screen showed only the rooms at a person's own centre, which made the real
     assignment impossible for anyone working across sites — and it was never a boundary
     anyway, since the save endpoint has always validated against the whole agency. Own
     centres are marked and sorted first instead, so reaching outside them is visibly
     deliberate rather than impossible.

   - READ-ONLY UNTIL YOU PRESS EDIT. These decisions control who can see which children;
     opening the screen is not consent to change them. Cancel restores the snapshot
     taken when Edit was pressed, and switching person mid-edit asks first.

   - NOTHING JUMPS. Ticking a room updates the labels where they sit and replaces no
     nodes, so the page never scrolls back to the top mid-edit.

   - Portal-wide sweeps claim controls under #appMain, and three matter here:
     kt-switches.js turns every checkbox into a 48x28 toggle (data-kt-noswitch opts out
     and restores the house square); kt-icon-buttons.js replaces a short button label
     with a glyph (data-kt-iconized="1" opts out); and the `hidden` ATTRIBUTE does not
     hide a .kt-btn, because [data-kt-pretty] .kt-btn sets display with !important —
     see show() below.
   ═══════════════════════════════════════════════════════════════════ */
(function (window) {
  'use strict';
  var KT = window.KT;
  if (!KT || !KT.Shell || !KT.Shell.registerScreen) { return; }
  var Api = KT.Api;

  var SCREEN_BUILD = 'er-picker-2026-09-14';

  function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"]/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c];
    });
  }

  var state = {
    rooms: [],
    educators: [],
    selectedId: null,
    editing: false,
    snapshot: null,     // the selected person's room_ids as they were when Edit began
    dirty: false,
  };

  // ── reading a person ────────────────────────────────────────────────────────
  function centresOf(e) {
    if (e.centre_ids && e.centre_ids.length) { return e.centre_ids; }
    return e.centre_id ? [e.centre_id] : [];
  }

  function has(e, roomId) { return e.room_ids.indexOf(roomId) !== -1; }

  function roleLabel(role) {
    return String(role || '').split('/').map(function (x) {
      x = x.trim();
      if (x === 'home_visitor') { return 'Home visitor'; }
      if (x === 'educator') { return 'Educator'; }
      return x;
    }).filter(Boolean).join(' / ') || 'Educator';
  }

  function centreLabel(e) {
    var names = e.centre_names || [];
    if (names.length > 1) { return names.length + ' centres'; }
    return names[0] || e.centre_name || 'No centre';
  }

  function selected() {
    if (state.selectedId == null) { return null; }
    return state.educators.filter(function (e) {
      return String(e.id) === String(state.selectedId);
    })[0] || null;
  }

  function groupedRooms() {
    var groups = [];
    var index = {};
    state.rooms.forEach(function (r) {
      var key = String(r.centre_id);
      if (!index[key]) {
        index[key] = {
          centre_id: r.centre_id,
          centre_name: r.centre_name || ('Centre ' + r.centre_id),
          rooms: [],
        };
        groups.push(index[key]);
      }
      index[key].rooms.push(r);
    });
    return groups;
  }

  function staffedCount(roomId) {
    return state.educators.filter(function (e) { return has(e, roomId); }).length;
  }

  function roomById(id) {
    return state.rooms.filter(function (r) { return r.id === id; })[0] || null;
  }

  /* In a home-childcare agency the provider IS the centre and the room, so centre_name
     and the room name are the same string — iLearn's list read "Amna Ahsan / Amna Ahsan
     · preschool". Saying it twice is noise, so the centre is dropped from the sub-line
     when it only repeats the room's own name. Agencies with real room names are
     unaffected. */
  function sameName(r) {
    return String(r.centre_name || '').trim().toLowerCase()
      === String(r.name || '').trim().toLowerCase();
  }

  function othersLabel(r, mineIsOn) {
    var others = staffedCount(r.id) - (mineIsOn ? 1 : 0);
    var bits = [];
    if (r.centre_name && ! sameName(r)) { bits.push(r.centre_name); }
    if (r.age_group) { bits.push(r.age_group); }
    bits.push(others ? others + (others === 1 ? ' other person' : ' other people') : 'nobody else');

    return bits.join(' · ');
  }

  /* Hiding a .kt-btn takes an !important, because that is what the portal gave it:
     `[data-kt-pretty] .kt-btn { display: inline-flex !important; }`. No amount of
     specificity beats it — the `hidden` attribute and a namespaced class both lost,
     leaving Edit, Cancel and Save on screen at once. An inline declaration carrying the
     same priority wins, scoped to the few elements that need it rather than adding
     another !important to the stylesheet for everything else to fight. */
  function show(el, on) {
    if (!el) { return; }
    if (on) { el.style.removeProperty('display'); }
    else { el.style.setProperty('display', 'none', 'important'); }
  }

  // ── styles ──────────────────────────────────────────────────────────────────
  function injectStyle() {
    if (document.getElementById('kt-er-style')) { return; }
    var s = document.createElement('style');
    s.id = 'kt-er-style';
    s.textContent = [
      '.er-wrap{padding:24px;max-width:1080px;margin:0 auto;}',

      '.er-pick{background:#fff;border:1px solid #E7EDF3;border-radius:14px;padding:16px 18px;',
      '  box-shadow:0 1px 4px rgba(15,23,42,.05);margin:0 0 14px;}',
      '.er-pick label.cap{display:block;font-size:11.5px;font-weight:800;letter-spacing:.04em;',
      '  text-transform:uppercase;color:#64748B;margin:0 0 6px;}',
      '.er-wrap select.er-who{width:100%;max-width:560px;height:40px;border:1.5px solid #E2E8F0;',
      '  border-radius:10px;padding:0 12px;font-size:14px;font-weight:600;color:#0F172A;background:#fff;}',
      '.er-wrap select.er-who:focus{outline:none;border-color:#159FB4;box-shadow:0 0 0 3px rgba(21,159,180,.13);}',
      '.er-who-meta{margin-top:9px;font-size:12.5px;color:#64748B;}',
      '.er-who-meta b{color:#0F172A;}',

      '.er-summary{background:#fff;border:1px solid #E7EDF3;border-radius:14px;padding:15px 18px;',
      '  box-shadow:0 1px 4px rgba(15,23,42,.05);margin:0 0 14px;}',
      '.er-summary h3{margin:0 0 9px;font-size:12px;font-weight:800;letter-spacing:.04em;',
      '  text-transform:uppercase;color:#64748B;}',
      '.er-chip{display:inline-flex;align-items:center;gap:7px;background:#ECFEFF;color:#0E7490;',
      '  border:1px solid #A5E8F0;border-radius:999px;padding:5px 12px;font-size:12.5px;',
      '  font-weight:700;margin:0 6px 6px 0;}',
      '.er-chip .c{color:#64748B;font-weight:600;}',
      '.er-none{color:#B45309;background:#FFF7ED;border:1px solid #FED7AA;border-radius:9px;',
      '  padding:10px 13px;font-size:13px;font-weight:600;}',

      '.er-card{background:#fff;border:1px solid #E7EDF3;border-radius:14px;overflow:hidden;',
      '  box-shadow:0 1px 4px rgba(15,23,42,.05);}',
      '.er-cgroup{display:flex;align-items:center;gap:9px;background:#F8FAFC;',
      '  border-bottom:1px solid #EEF2F6;border-top:1px solid #EEF2F6;padding:9px 16px;',
      '  font-size:11.5px;font-weight:800;letter-spacing:.04em;text-transform:uppercase;color:#475569;}',
      '.er-card > .er-cgroup:first-child{border-top:none;}',
      '.er-cgroup .own{background:#ECFEFF;color:#0E7490;border-radius:999px;padding:2px 9px;',
      '  font-size:10px;letter-spacing:.02em;}',
      '.er-cgroup .bulk{margin-left:auto;display:flex;gap:6px;}',

      '.er-opt{display:flex;align-items:center;gap:13px;padding:11px 16px;border-bottom:1px solid #F5F8FA;}',
      '.er-opt:last-child{border-bottom:none;}',
      '.er-opt.on{background:#F6FEFF;}',
      '.er-opt label{flex:1;min-width:0;cursor:pointer;}',
      '.er-opt .nm{display:block;font-size:14px;font-weight:700;color:#0F172A;}',
      '.er-opt .sub{display:block;font-size:11.5px;color:#94A3B8;margin-top:1px;}',
      '.er-opt .er-box{width:20px;height:20px;min-width:20px;flex:0 0 auto;cursor:pointer;margin:0;}',

      /* Read-only rooms stay full-colour. `disabled` was the obvious way to lock them,
         but the browser greys a disabled checkbox itself, accent-color included, so an
         assigned room and an unassigned one became hard to tell apart — the one thing
         the saved view exists to show. Locked with pointer-events, tabindex -1 and a
         refusal in the change handler instead. */
      '.er-card.locked input.er-box{pointer-events:none;}',
      '.er-card.locked .er-opt label{cursor:default;}',

      '.er-wrap button.er-mini{border:1.5px solid #E2E8F0;background:#fff;border-radius:8px;',
      '  padding:3px 10px;font-size:11.5px;font-weight:700;color:#475569;cursor:pointer;line-height:1.6;}',
      '.er-wrap button.er-mini:hover{border-color:#159FB4;color:#0E7490;}',

      '.er-editing{display:flex;align-items:center;gap:9px;background:#ECFEFF;border:1px solid #A5E8F0;',
      '  color:#0E7490;border-radius:10px;padding:9px 13px;font-size:13px;font-weight:700;margin:0 0 12px;}',
      '.er-editing .m{font-weight:600;}',

      '.er-savebar{position:sticky;bottom:0;display:flex;align-items:center;justify-content:flex-end;',
      '  gap:14px;margin-top:14px;padding:12px 4px;',
      '  background:linear-gradient(to top,#F6F8FA 70%,rgba(246,248,250,0));}',
      '',
    ].join('\n');
    document.head.appendChild(s);
  }

  // ── the picker ──────────────────────────────────────────────────────────────
  function pickerHtml() {
    var opts = state.educators.map(function (e) {
      var n = e.room_ids.length;
      return '<option value="' + e.id + '"'
        + (String(e.id) === String(state.selectedId) ? ' selected' : '') + '>'
        + esc(e.name) + ' — ' + esc(roleLabel(e.role))
        + ' · ' + (n ? n + (n === 1 ? ' room' : ' rooms') : 'no rooms')
        + '</option>';
    }).join('');

    return '<div class="er-pick">'
      + '<label class="cap" for="er-who">Educator</label>'
      + '<select id="er-who" class="er-who">' + opts + '</select>'
      + '<div class="er-who-meta" id="er-who-meta"></div>'
      + '</div>';
  }

  function metaHtml(e) {
    if (!e) { return ''; }
    var n = e.room_ids.length;
    var count = n ? n + (n === 1 ? ' room' : ' rooms') : 'no rooms';

    /* One line, so it cannot re-wrap and shift the list below — which is why the live
       count lives here rather than on the chips. Said as "selected" while editing so it
       is never mistaken for what is saved. */
    return esc(roleLabel(e.role)) + ' · ' + esc(centreLabel(e))
      + (e.email ? ' · ' + esc(e.email) : '')
      + ' · <b>' + count + (state.editing ? ' selected' : ' assigned') + '</b>';
  }

  // ── the saved view: what this person has right now ──────────────────────────
  function summaryHtml(e) {
    if (!e) { return ''; }

    var mine = state.rooms.filter(function (r) { return has(e, r.id); });

    var body = mine.length
      ? mine.map(function (r) {
          return '<span class="er-chip">' + esc(r.name)
            + (sameName(r) ? '' : '<span class="c">' + esc(r.centre_name || '') + '</span>')
            + '</span>';
        }).join('')
      : '<div class="er-none">No rooms assigned. Until one is, screens that work '
        + 'room-by-room fall back to showing this person the whole centre.</div>';

    return '<div class="er-summary"><h3>Assigned rooms'
      + (state.editing ? ' <span style="font-weight:600;text-transform:none;letter-spacing:0;'
        + 'color:#94A3B8;">— as saved</span>' : '')
      + '</h3>' + body + '</div>';
  }

  // ── every room available to assign ──────────────────────────────────────────
  function roomsHtml(e) {
    if (!e) { return ''; }
    if (!state.rooms.length) {
      return '<div class="kt-card" style="color:#64748B;padding:22px;">'
        + 'This agency has no rooms yet. Add rooms to a centre first — there is nothing '
        + 'to assign to.</div>';
    }

    /* Their own centres first. Every room in the agency is on the list, but the ones
       they are most likely to want are not buried under eight other sites. */
    var groups = groupedRooms().slice().sort(function (a, b) {
      var ao = centresOf(e).indexOf(a.centre_id) !== -1 ? 0 : 1;
      var bo = centresOf(e).indexOf(b.centre_id) !== -1 ? 0 : 1;
      return ao - bo;
    });

    return '<div class="er-card' + (state.editing ? '' : ' locked') + '" data-kt-noswitch>'
      + groups.map(function (g) {
        var own = centresOf(e).indexOf(g.centre_id) !== -1;
        return '<div class="er-cgroup">'
          + '<span>' + esc(g.centre_name) + '</span>'
          + (own ? '<span class="own">their centre</span>' : '')
          + (state.editing
              ? '<span class="bulk">'
                + '<button type="button" class="er-mini" data-kt-iconized="1" data-er-all="'
                + g.centre_id + '">All</button>'
                + '<button type="button" class="er-mini" data-kt-iconized="1" data-er-none="'
                + g.centre_id + '">None</button>'
                + '</span>'
              : '')
          + '</div>'
          + g.rooms.map(function (r) {
            var on = has(e, r.id);
            var id = 'er-room-' + r.id;
            return '<div class="er-opt' + (on ? ' on' : '') + '" data-er-row="' + r.id + '">'
              + '<input type="checkbox" class="er-box" id="' + id + '" data-er-room="' + r.id + '"'
              + (on ? ' checked' : '') + (state.editing ? '' : ' tabindex="-1"') + '>'
              + '<label for="' + id + '">'
              + '<span class="nm">' + esc(r.name) + '</span>'
              + '<span class="sub">' + esc(othersLabel(r, on)) + '</span>'
              + '</label></div>';
          }).join('');
      }).join('')
      + '</div>';
  }

  // ── painting ────────────────────────────────────────────────────────────────
  /* A repaint that does not move the page. #appMain is the portal's one scroller;
     replacing the body's innerHTML resets it to the top, which is how ticking a room
     near the bottom threw the view back to the first one. */
  function keepScroll(main, fn) {
    var app = document.getElementById('appMain');
    var appTop = app ? app.scrollTop : 0;
    var pageTop = window.pageYOffset || 0;
    fn();
    if (app) { app.scrollTop = appTop; }
    if (pageTop) { window.scrollTo(0, pageTop); }
  }

  function paintBody(main) {
    var host = main.querySelector('#er-body');
    if (!host) { return; }
    var e = selected();
    host.innerHTML = summaryHtml(e) + roomsHtml(e);
    var meta = main.querySelector('#er-who-meta');
    if (meta) { meta.innerHTML = metaHtml(e); }
    refreshBar(main);
  }

  /* Ticking a room changes a few labels and nothing else, so these update in place and
     replace no nodes at all.

     The "Assigned rooms" chips are deliberately NOT rebuilt here. They sit above the
     list, and a chip added or removed can re-wrap the block to a different height —
     which shifts every room below it under the reader's cursor mid-edit. That is the
     jump this screen is supposed to have stopped, just arriving by a different route.
     They are also the SAVED view: showing pending edits there would make "assigned"
     mean two different things on one screen. They refresh when a person is chosen,
     when edit mode opens or closes, and after a save. */
  function refreshInPlace(main) {
    var e = selected();
    if (!e) { return; }

    main.querySelectorAll('[data-er-row]').forEach(function (row) {
      var rid = parseInt(row.getAttribute('data-er-row'), 10);
      var on = has(e, rid);
      row.classList.toggle('on', on);

      var cb = row.querySelector('input.er-box');
      if (cb && cb.checked !== on) { cb.checked = on; }

      var r = roomById(rid);
      var sub = row.querySelector('.sub');
      if (r && sub) { sub.textContent = othersLabel(r, on); }
    });

    var meta = main.querySelector('#er-who-meta');
    if (meta) { meta.innerHTML = metaHtml(e); }

    refreshBar(main);
  }

  function refreshBar(main) {
    var edit = main.querySelector('#er-edit');
    var save = main.querySelector('#er-save');
    var cancel = main.querySelector('#er-cancel');
    var status = main.querySelector('#er-status');
    var banner = main.querySelector('#er-editing');
    if (!edit || !save || !cancel) { return; }

    var e = selected();
    show(edit, !state.editing && !!e);
    show(save, state.editing);
    show(cancel, state.editing);
    show(banner, state.editing);

    save.disabled = !state.dirty;

    if (status && !status.getAttribute('data-sticky-msg')) {
      status.style.color = '#64748B';
      status.textContent = state.editing
        ? (state.dirty ? 'Unsaved changes' : 'No changes yet')
        : '';
    }
  }

  function clearSticky(main) {
    var status = main.querySelector('#er-status');
    if (status) { status.removeAttribute('data-sticky-msg'); }
  }

  // ── mutation ────────────────────────────────────────────────────────────────
  function setRoom(e, roomId, on) {
    var i = e.room_ids.indexOf(roomId);
    if (on && i === -1) { e.room_ids.push(roomId); }
    if (!on && i !== -1) { e.room_ids.splice(i, 1); }
    state.dirty = true;
  }

  /* Cancel restores what was on screen when Edit was pressed — a copy, not a reference,
     or editing the live array would quietly edit the snapshot too. */
  function takeSnapshot() {
    var e = selected();
    return e ? e.room_ids.slice() : null;
  }

  function restoreSnapshot() {
    var e = selected();
    if (e && state.snapshot) { e.room_ids = state.snapshot.slice(); }
  }

  function leaveEditMode() {
    state.editing = false;
    state.snapshot = null;
    state.dirty = false;
  }

  // ── wiring ──────────────────────────────────────────────────────────────────
  function wire(main) {
    var body = main.querySelector('#er-body');

    body.addEventListener('change', function (ev) {
      var cb = ev.target.closest ? ev.target.closest('input.er-box') : null;
      if (!cb) { return; }
      var e = selected();
      if (!e) { return; }

      var rid = parseInt(cb.getAttribute('data-er-room'), 10);
      if (!state.editing) {
        // Reached by keyboard or a script, not the mouse. Put the box back.
        cb.checked = has(e, rid);
        return;
      }

      setRoom(e, rid, cb.checked);
      clearSticky(main);
      refreshInPlace(main);
    });

    body.addEventListener('click', function (ev) {
      if (!state.editing) { return; }
      var e = selected();
      if (!e) { return; }

      var all = ev.target.closest && ev.target.closest('[data-er-all]');
      var none = ev.target.closest && ev.target.closest('[data-er-none]');
      if (!all && !none) { return; }

      var el = all || none;
      var cid = parseInt(el.getAttribute(all ? 'data-er-all' : 'data-er-none'), 10);
      state.rooms.forEach(function (r) {
        if (r.centre_id === cid) { setRoom(e, r.id, !!all); }
      });

      clearSticky(main);
      refreshInPlace(main);
    });

    main.querySelector('#er-edit').onclick = function () {
      state.editing = true;
      state.snapshot = takeSnapshot();
      state.dirty = false;
      clearSticky(main);
      keepScroll(main, function () { paintBody(main); });
    };

    main.querySelector('#er-cancel').onclick = function () {
      restoreSnapshot();
      leaveEditMode();
      clearSticky(main);
      keepScroll(main, function () { paintBody(main); });
    };

    main.querySelector('#er-save').onclick = async function () {
      var e = selected();
      if (!e) { return; }
      var save = main.querySelector('#er-save');
      var status = main.querySelector('#er-status');
      var name = e.name;

      save.disabled = true;
      save.textContent = 'Saving…';
      try {
        /* Only the person on screen. Sending everybody would rewrite rows nobody
           touched and lose who assigned them, and when. */
        await Api.post('/provider/educator-rooms', {
          assignments: [{ user_id: e.id, room_ids: e.room_ids }],
        });

        leaveEditMode();
        save.textContent = 'Save changes';
        status.setAttribute('data-sticky-msg', '1');
        status.style.color = '#1E8E60';
        status.textContent = '✓ Saved — ' + name;
        await load(main, { keepSelection: true });
      } catch (err) {
        save.disabled = false;
        save.textContent = 'Save changes';
        status.setAttribute('data-sticky-msg', '1');
        status.style.color = '#BE4038';
        status.textContent = (err && err.message) || 'Could not save';
      }
    };
  }

  /* Re-wired after every load, because the <select> is rebuilt to carry fresh room
     counts in its option labels. Bound directly rather than by delegation so a
     background repaint can never swap the element out from under an open dropdown —
     a bug shape this portal has had before. */
  function wirePicker(main) {
    var sel = main.querySelector('#er-who');
    if (!sel) { return; }

    function apply(id) {
      leaveEditMode();
      state.selectedId = id;
      clearSticky(main);
      paintBody(main);
    }

    sel.onchange = function () {
      var next = sel.value;
      var previous = state.selectedId;

      // Switching person mid-edit would silently drop the changes. Ask.
      if (!(state.editing && state.dirty)) { apply(next); return; }

      var who = (selected() || {}).name || 'this person';
      var msg = 'Discard the unsaved changes to ' + who + '?';
      var ask = (KT && KT.confirm) ? KT.confirm(msg) : Promise.resolve(window.confirm(msg));

      ask.then(function (ok) {
        if (!ok) { sel.value = previous; return; }
        restoreSnapshot();     // reads the PREVIOUS person, before selection moves
        apply(next);
      });
    };
  }

  // ── load ────────────────────────────────────────────────────────────────────
  async function load(main, opts) {
    opts = opts || {};
    var host = main.querySelector('#er-body');
    var res;
    try {
      res = await Api.get('/provider/educator-rooms');
    } catch (e) {
      host.innerHTML = '<div class="kt-card" style="color:#B91C1C;">Could not load: '
        + esc(e.message || 'error') + '</div>';
      return;
    }

    state.rooms = res.rooms || [];
    state.educators = res.educators || [];

    if (!state.educators.length) {
      main.querySelector('#er-picker').innerHTML = '';
      host.innerHTML = '<div class="kt-card" style="color:#64748B;padding:22px;">'
        + 'No educators in this agency yet.</div>';
      refreshBar(main);
      return;
    }

    // Keep the person on screen across a save; otherwise start on the first.
    if (!(opts.keepSelection && selected())) {
      state.selectedId = state.educators[0].id;
    }

    var unassigned = state.educators.filter(function (e) { return !e.room_ids.length; }).length;
    var unstaffed = state.rooms.filter(function (r) { return !staffedCount(r.id); }).length;
    var summary = main.querySelector('#er-summary');
    if (summary) {
      var bits = [];
      if (unassigned) { bits.push(unassigned + ' of ' + state.educators.length + ' have no room'); }
      if (unstaffed) { bits.push(unstaffed + (unstaffed === 1 ? ' room has' : ' rooms have') + ' nobody'); }
      summary.innerHTML = bits.length
        ? '<div class="kt-card" style="background:#FFF7ED;border-color:#FED7AA;color:#9A3412;'
          + 'margin:0 0 14px;padding:11px 14px;font-size:13px;">'
          + '<strong>' + esc(bits.join(' · ')) + '.</strong> '
          + 'Screens that work room-by-room fall back to the whole centre until this is '
          + 'filled in.</div>'
        : '';
    }

    main.querySelector('#er-picker').innerHTML = pickerHtml();
    wirePicker(main);
    paintBody(main);
  }

  async function render(main) {
    injectStyle();
    main.setAttribute('data-kt-pretty', '1');
    main.innerHTML = '<div class="er-wrap">'
      + '<div class="kt-page-hero"><h2>🚪 Room assignments</h2>'
      + '<p>Which rooms a person works in. This decides whose children they see, and lets '
      + 'attendance and sign-out figures be attributed to a person rather than a whole '
      + 'centre. Anyone can be assigned to any room — their own centre is marked.</p></div>'
      + '<div id="er-summary"></div>'
      + '<div id="er-picker"></div>'
      + '<div class="er-editing" id="er-editing" style="display:none !important;">'
      + '<span>✏️ Editing</span>'
      + '<span class="m">Tick a room to assign it. Nothing is saved until you press Save.</span>'
      + '</div>'
      + '<div id="er-body">Loading…</div>'
      + '<div class="er-savebar">'
      + '<span id="er-status" style="font-size:13px;color:#64748B;"></span>'
      + '<button type="button" id="er-edit" class="kt-btn kt-btn-primary" data-kt-iconized="1"'
      + ' style="display:none !important;">✏️ Edit assignments</button>'
      + '<button type="button" id="er-cancel" class="kt-btn" data-kt-iconized="1"'
      + ' style="display:none !important;">Cancel</button>'
      + '<button type="button" id="er-save" class="kt-btn kt-btn-primary" data-kt-iconized="1" disabled'
      + ' style="display:none !important;">Save changes</button>'
      + '</div>'
      + '<div style="text-align:right;font-size:10px;color:#CBD5E1;margin-top:6px;">'
      + SCREEN_BUILD + '</div>'
      + '</div>';

    wire(main);
    await load(main);
  }

  KT.EducatorRooms = { render: render, build: SCREEN_BUILD };
  ['agency_admin', 'centre_director', 'platform_admin'].forEach(function (r) {
    KT.Shell.registerScreen(r + ':educator-rooms', render);
  });
})(window);
