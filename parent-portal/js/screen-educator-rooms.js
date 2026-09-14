/* ═══════════════════════════════════════════════════════════════════
   KIDDIETRAC — Room assignments (matrix)

   educator_rooms decides whose children a member of staff sees, and it drives whether
   attendance and sign-out figures are attributed to a person or fall back to the whole
   centre. This is the one screen that fills it in.

   REDESIGNED 2026-09-14, on two instructions from Anthony: make it easier, and let ANY
   educator be assigned to ANY room.

   What changed and why:

   1. ANY EDUCATOR, ANY ROOM. The old screen offered a person only the rooms at their own
      centre, on the reasoning that a wider list invites a mis-click. In practice staff are
      lent between sites constantly and the restriction simply made the real assignment
      impossible — and it was never a security boundary anyway: the save endpoint has
      always validated against the whole AGENCY, so the limit existed purely in this file.
      Every room in the agency is now offered to everyone. The mis-click risk is handled by
      LABELLING rather than hiding: a person's own centres are shaded and marked, so
      reaching outside them is visibly deliberate.

   2. A MATRIX, NOT A ROW OF CHIPS. People down, rooms across, grouped under their centre.
      One click per assignment, and — the part the old layout could not do at all — an
      unstaffed room or an unassigned person is obvious without reading anything.

   3. Built from CSS GRID, not a <table>. Four separate global sweeps claim tables inside
      #appMain: kt-table-filter (search/sort), kt-row-actions (a ⋮ column), kt-table-export
      (an export bar) and kt-mobile-tables (restacks every row into a card at ≤600px, which
      would destroy a matrix). Each has its own opt-out attribute and I would have had to
      remember all four, today and every time one of them grows. A grid is not a table, so
      none of them apply, and the sticky header and sticky name column are far easier to
      hold together in grid than in a table that is being restacked underneath me.

   4. A phone gets a different renderer entirely — a matrix nine columns wide is unusable
      at 400px. Separate function, per the house rule; see kiddietrac-mobile-desktop-renderers.
   ═══════════════════════════════════════════════════════════════════ */
(function (window) {
  'use strict';
  var KT = window.KT;
  if (!KT || !KT.Shell || !KT.Shell.registerScreen) { return; }
  var Api = KT.Api;

  var SCREEN_BUILD = 'er-matrix-2026-09-14';

  function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"]/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c];
    });
  }

  var state = {
    rooms: [],
    educators: [],
    dirty: {},          // user_id -> true, for people actually changed
    search: '',
    onlyUnassigned: false,
    phone: false,
    open: {},           // phone view: which person's panel is expanded

    /* READ-ONLY UNTIL YOU ASK TO EDIT. Room assignments decide who can see which
       children, and the matrix puts a hundred of those decisions one stray click
       apart. Opening the screen is not consent to change it. */
    editing: false,
    snapshot: null,     // room_ids per person as they were when Edit was pressed
  };

  /* A matrix is unreadable long before a phone is; 820 rather than the usual 600. */
  function isPhone() { return window.innerWidth <= 820; }

  // ── reading a person ────────────────────────────────────────────────────────
  function centresOf(e) {
    if (e.centre_ids && e.centre_ids.length) { return e.centre_ids; }
    return e.centre_id ? [e.centre_id] : [];
  }

  /* An EMPTY centre list means an agency-level posting (a home visitor with no centre),
     not "belongs nowhere" — so nothing is shaded rather than everything. */
  function isOwnCentre(e, room) {
    var c = centresOf(e);
    return c.length ? c.indexOf(room.centre_id) !== -1 : false;
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

  // ── grouping rooms by centre, in the order the API sent them ────────────────
  function groupedRooms() {
    var groups = [];
    var index = {};
    state.rooms.forEach(function (r) {
      var key = String(r.centre_id);
      if (!index[key]) {
        index[key] = { centre_id: r.centre_id, centre_name: r.centre_name || ('Centre ' + r.centre_id), rooms: [] };
        groups.push(index[key]);
      }
      index[key].rooms.push(r);
    });
    return groups;
  }

  function visibleEducators() {
    var q = state.search.trim().toLowerCase();
    return state.educators.filter(function (e) {
      if (state.onlyUnassigned && e.room_ids.length) { return false; }
      if (!q) { return true; }
      var hay = [e.name, e.email, roleLabel(e.role), (e.centre_names || []).join(' ')].join(' ').toLowerCase();
      if (hay.indexOf(q) !== -1) { return true; }
      // Searching a ROOM name finds the people working in it.
      return state.rooms.some(function (r) {
        return has(e, r.id) && String(r.name || '').toLowerCase().indexOf(q) !== -1;
      });
    });
  }

  function staffedCount(roomId) {
    return state.educators.filter(function (e) { return has(e, roomId); }).length;
  }

  // ── styles ──────────────────────────────────────────────────────────────────
  function injectStyle() {
    if (document.getElementById('kt-er-style')) { return; }
    var s = document.createElement('style');
    s.id = 'kt-er-style';
    s.textContent = [
      '.er-wrap{padding:24px;max-width:1800px;margin:0 auto;}',
      '.er-bar{display:flex;flex-wrap:wrap;align-items:center;gap:10px;margin:0 0 14px;}',
      '.er-search{flex:1 1 260px;min-width:0;height:34px;border:1.5px solid #E2E8F0;border-radius:9px;',
      '  padding:0 12px;font-size:13.5px;color:#0F172A;background:#fff;}',
      '.er-search:focus{outline:none;border-color:#159FB4;box-shadow:0 0 0 3px rgba(21,159,180,.13);}',
      /* Qualified with .er-wrap and the element name: a bare .er-toggle lost its border
         to a portal-wide button reset and rendered as plain text with no affordance. */
      '.er-wrap button.er-toggle{display:inline-flex;align-items:center;gap:7px;height:34px;padding:0 12px;',
      '  border-radius:9px;border:1.5px solid #E2E8F0;background:#fff;font-size:13px;font-weight:700;',
      '  color:#475569;cursor:pointer;line-height:1;}',
      '.er-wrap button.er-toggle:hover{border-color:#CBD5E1;background:#F8FAFC;}',
      '.er-wrap button.er-toggle[aria-pressed="true"]{border-color:#159FB4;background:#ECFEFF;color:#0E7490;}',
      '.er-note{font-size:12.5px;color:#64748B;}',

      /* The scroller. overflow-x carries a wide matrix; the sticky bits are pinned
         against THIS box, so it must be the scroll parent, not the page. */
      '.er-scroll{overflow:auto;max-height:calc(100vh - 330px);min-height:220px;border:1px solid #E7EDF3;',
      '  border-radius:14px;background:#fff;box-shadow:0 1px 4px rgba(15,23,42,.05);}',

      '.er-grid{display:grid;min-width:max-content;font-size:13px;}',

      /* Header: a centre band, then a room band. Both stick to the top. */
      '.er-h-centre{position:sticky;top:0;z-index:3;background:#F1F5F9;border-bottom:1px solid #E2E8F0;',
      '  padding:8px 10px;font-size:11px;font-weight:800;letter-spacing:.04em;text-transform:uppercase;',
      '  color:#475569;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;border-left:2px solid #E2E8F0;}',
      /* top is set from a MEASUREMENT after paint (--er-h1), not guessed: the centre band's
         height moves with font size, browser zoom and the user's own text settings, and a
         guessed offset shows as a sliver of scrolled content between the two bands. */
      '.er-h-room{position:sticky;top:var(--er-h1,32px);z-index:3;background:#fff;border-bottom:2px solid #E2E8F0;',
      '  padding:7px 8px 8px;text-align:center;cursor:pointer;user-select:none;}',
      '.er-h-room:hover{background:#F8FAFC;}',
      '.er-h-room.locked{cursor:default;}',
      '.er-h-room.locked:hover{background:#fff;}',
      /* Read-only must stay READABLE. The browser dims a disabled checkbox to the point
         where a ticked box and an empty one are hard to tell apart at a glance, which is
         exactly the thing this screen exists to show. */
      '.er-grid input.er-box:disabled,.er-opt input.er-box:disabled{opacity:1;cursor:default;}',
      '.er-editing{display:flex;align-items:center;gap:9px;background:#ECFEFF;border:1px solid #A5E8F0;',
      '  color:#0E7490;border-radius:10px;padding:9px 13px;font-size:13px;font-weight:700;margin:0 0 12px;}',

      /* THE `hidden` ATTRIBUTE DOES NOT HIDE THINGS HERE. It is only a UA-stylesheet
         rule, so ANY author rule that sets display beats it — and several do
         (.kt-btn, and .kt-act-icon which the icon sweep adds). The first build of this
         screen showed Edit, Cancel AND Save at once, with the "Editing" banner up while
         the hint underneath still said read-only. A namespaced class at higher
         specificity hides them for real, with no !important. */
      '.er-wrap .er-hide{display:none;}',

      /* Read-only cells stay full-colour. Using the `disabled` attribute was the obvious
         way to lock them, but a disabled checkbox is greyed by the browser itself —
         accent-color goes with it — so a ticked room and an empty one became nearly
         indistinguishable. That is the one thing this screen exists to show. Locked
         instead: no pointer, out of the tab order, and the change handler refuses. */
      '.er-grid.locked input.er-box,.er-card-body.locked input.er-box{pointer-events:none;}',
      '.er-grid.locked .er-cell,.er-card-body.locked .er-opt label{cursor:default;}',
      '.er-h-room .n{display:block;font-weight:800;color:#0F172A;font-size:12.5px;line-height:1.25;}',
      '.er-h-room .c{display:block;font-size:11px;font-weight:700;color:#94A3B8;margin-top:2px;}',
      '.er-h-room.unstaffed .c{color:#B45309;}',

      /* The corner and the name column both stick, so a person stays readable at any
         horizontal scroll position. The corner needs the higher z-index of the two. */
      '.er-corner{position:sticky;left:0;top:0;z-index:5;background:#F1F5F9;border-bottom:1px solid #E2E8F0;',
      '  border-right:2px solid #E2E8F0;padding:8px 12px;font-size:11px;font-weight:800;',
      '  letter-spacing:.04em;text-transform:uppercase;color:#475569;}',
      '.er-corner2{position:sticky;left:0;top:var(--er-h1,32px);z-index:5;background:#fff;border-bottom:2px solid #E2E8F0;',
      '  border-right:2px solid #E2E8F0;padding:7px 12px 8px;}',

      '.er-name{position:sticky;left:0;z-index:2;background:#fff;border-right:2px solid #E2E8F0;',
      '  border-bottom:1px solid #F1F5F9;padding:9px 12px;min-width:250px;max-width:320px;}',
      '.er-name .nm{font-weight:800;color:#0F172A;font-size:13.5px;}',
      '.er-name .sub{font-size:11.5px;color:#94A3B8;margin-top:1px;}',
      '.er-name .cnt{font-size:11.5px;font-weight:800;color:#0E7490;}',
      '.er-name .cnt.zero{color:#B45309;}',

      '.er-cell{border-bottom:1px solid #F1F5F9;border-left:2px solid transparent;',
      '  display:flex;align-items:center;justify-content:center;padding:4px 0;}',
      '.er-cell.grp{border-left-color:#E2E8F0;}',
      '.er-cell.own{background:#F0FDFA;}',
      '.er-row:hover .er-cell{background:#F8FAFC;}',
      '.er-row:hover .er-cell.own{background:#E6FFFB;}',
      '.er-row:hover .er-name{background:#F8FAFC;}',

      /* The control itself is a real checkbox — keyboard, screen readers and the
         browser's own hit-testing all come free.

         Its APPEARANCE is deliberately not defined here. A portal-wide sweep
         (kt-switches.js + kt-consistency-polish.css) makes every checkbox an on/off
         toggle switch by default: 48x28px, which is right for a settings row and wrong
         for a matrix cell — nine of them per row turned the grid into a wall of pills.
         Both the sweep and the stylesheet honour the same escape hatch, and the
         containers below carry it (data-kt-noswitch), which restores the house SQUARE
         checkbox: 18px, accent #1F6080, no flicker on first paint. Using the house
         square is better than inventing a third checkbox style. */
      '.er-box{cursor:pointer;margin:0;}',

      '.er-rowbtn{border:none;background:none;color:#94A3B8;font-size:11px;font-weight:800;',
      '  cursor:pointer;padding:0 4px;}',
      '.er-rowbtn:hover{color:#159FB4;text-decoration:underline;}',

      /* Save bar. Sticks to the bottom so it is reachable from anywhere in a long list. */
      '.er-savebar{position:sticky;bottom:0;display:flex;align-items:center;justify-content:flex-end;',
      '  gap:14px;margin-top:14px;padding:12px 4px;background:linear-gradient(to top,#F6F8FA 70%,rgba(246,248,250,0));}',

      /* Phone: one panel per person. */
      '.er-card{background:#fff;border:1px solid #E7EDF3;border-radius:14px;margin:0 0 10px;',
      '  box-shadow:0 1px 4px rgba(15,23,42,.05);overflow:hidden;}',
      '.er-card-head{display:flex;align-items:center;gap:10px;padding:13px 14px;cursor:pointer;}',
      '.er-card-head .nm{font-weight:800;color:#0F172A;font-size:14.5px;}',
      '.er-card-head .sub{font-size:11.5px;color:#94A3B8;margin-top:2px;}',
      '.er-pill{margin-left:auto;flex:0 0 auto;font-size:11.5px;font-weight:800;color:#0E7490;',
      '  background:#ECFEFF;border-radius:999px;padding:3px 10px;}',
      '.er-pill.zero{color:#B45309;background:#FFF7ED;}',
      '.er-card-body{border-top:1px solid #F1F5F9;padding:10px 14px 14px;}',
      '.er-cgroup{font-size:11px;font-weight:800;letter-spacing:.04em;text-transform:uppercase;',
      '  color:#64748B;margin:12px 0 5px;display:flex;align-items:center;gap:7px;}',
      '.er-cgroup .own{color:#0E7490;background:#ECFEFF;border-radius:999px;padding:1px 8px;font-size:10px;}',
      '.er-opt{display:flex;align-items:center;gap:10px;padding:8px 2px;border-bottom:1px solid #F8FAFC;}',
      '.er-opt label{font-size:13.5px;color:#334155;font-weight:600;flex:1;cursor:pointer;padding:6px 0;}',
      // A thumb is not a mouse pointer. The label is tappable too, via for/id.
      '.er-opt .er-box{width:22px;height:22px;min-width:22px;flex:0 0 auto;}',
      '',
    ].join('\n');
    document.head.appendChild(s);
  }

  // ── desktop: the matrix ─────────────────────────────────────────────────────
  function matrixHtml() {
    var groups = groupedRooms();
    var eds = visibleEducators();

    if (!state.rooms.length) {
      return '<div class="kt-card" style="color:#64748B;padding:22px;">'
        + 'This agency has no rooms yet. Add rooms to a centre first — there is nothing to assign to.</div>';
    }

    /* One column per room, plus the sticky name column. max-content on the grid keeps
       every column at its natural width and lets the scroller carry the overflow. */
    var cols = '260px ' + state.rooms.map(function () { return '112px'; }).join(' ');

    var html = '<div class="er-scroll" data-kt-scroll data-kt-noswitch><div class="er-grid'
      + (state.editing ? '' : ' locked') + '" style="grid-template-columns:' + cols + ';">';

    // Header band 1 — centre names, each spanning its own rooms.
    html += '<div class="er-corner">Educator</div>';
    groups.forEach(function (g) {
      html += '<div class="er-h-centre" style="grid-column:span ' + g.rooms.length + ';" title="' + esc(g.centre_name) + '">'
        + esc(g.centre_name) + '</div>';
    });

    // Header band 2 — room names, clickable to staff or clear the whole column.
    html += '<div class="er-corner2"><span class="er-note">' + eds.length
      + (eds.length === 1 ? ' person' : ' people') + '</span></div>';
    groups.forEach(function (g) {
      g.rooms.forEach(function (r, i) {
        var n = staffedCount(r.id);
        html += '<div class="er-h-room' + (n ? '' : ' unstaffed') + (i === 0 ? ' grp' : '')
          + (state.editing ? '' : ' locked') + '"'
          + (state.editing ? ' data-er-col="' + r.id + '"' : '')
          + ' title="' + esc(r.name) + ' — ' + esc(g.centre_name)
          + (state.editing ? '\nClick to assign or clear everyone shown' : '') + '">'
          + '<span class="n">' + esc(r.name) + '</span>'
          + '<span class="c">' + (n ? n + ' staff' : 'none') + '</span></div>';
      });
    });

    if (!eds.length) {
      html += '</div></div><div class="kt-card" style="color:#64748B;padding:18px;margin-top:12px;">'
        + 'Nobody matches that search.</div>';
      return html;
    }

    // Body.
    eds.forEach(function (e) {
      var n = e.room_ids.length;
      html += '<div class="er-name er-row-name" data-er-rowname="' + e.id + '">'
        + '<div class="nm">' + esc(e.name) + '</div>'
        + '<div class="sub">' + esc(roleLabel(e.role)) + ' · ' + esc(centreLabel(e)) + '</div>'
        + '<div style="margin-top:3px;display:flex;align-items:center;gap:8px;">'
        + '<span class="cnt' + (n ? '' : ' zero') + '">' + (n ? n + (n === 1 ? ' room' : ' rooms') : 'No rooms') + '</span>'
        + (state.editing
            ? '<button type="button" class="er-rowbtn" data-kt-iconized="1" data-er-row-all="' + e.id + '">All</button>'
              + '<button type="button" class="er-rowbtn" data-kt-iconized="1" data-er-row-none="' + e.id + '">None</button>'
            : '')
        + '</div></div>';

      groups.forEach(function (g) {
        g.rooms.forEach(function (r, i) {
          var own = isOwnCentre(e, r);
          html += '<div class="er-cell' + (own ? ' own' : '') + (i === 0 ? ' grp' : '') + '">'
            + '<input type="checkbox" class="er-box" data-er-user="' + e.id + '" data-er-room="' + r.id + '"'
            + (has(e, r.id) ? ' checked' : '') + (state.editing ? '' : ' tabindex="-1"')
            + ' aria-label="' + esc(e.name) + ' — ' + esc(r.name) + ', ' + esc(g.centre_name) + '"'
            + ' title="' + esc(r.name) + ' · ' + esc(g.centre_name) + (own ? ' · their own centre' : '') + '"></div>';
        });
      });
    });

    html += '</div></div>';
    return html;
  }

  // ── phone: one expandable panel per person ──────────────────────────────────
  function phoneHtml() {
    var groups = groupedRooms();
    var eds = visibleEducators();

    if (!state.rooms.length) {
      return '<div class="kt-card" style="color:#64748B;padding:22px;">'
        + 'This agency has no rooms yet.</div>';
    }
    if (!eds.length) {
      return '<div class="kt-card" style="color:#64748B;padding:18px;">Nobody matches that search.</div>';
    }

    return eds.map(function (e) {
      var n = e.room_ids.length;
      var open = !!state.open[e.id];

      var body = '';
      if (open) {
        /* Their own centres first — the common case stays one tap away even though every
           room in the agency is on the list. */
        var ordered = groups.slice().sort(function (a, b) {
          var ao = centresOf(e).indexOf(a.centre_id) !== -1 ? 0 : 1;
          var bo = centresOf(e).indexOf(b.centre_id) !== -1 ? 0 : 1;
          return ao - bo;
        });

        body = '<div class="er-card-body' + (state.editing ? '' : ' locked') + '" data-kt-noswitch>'
          + (state.editing
              ? '<div style="display:flex;gap:8px;margin:0 0 4px;">'
                + '<button type="button" class="er-toggle" data-kt-iconized="1" data-er-row-all="' + e.id + '">Select all</button>'
                + '<button type="button" class="er-toggle" data-kt-iconized="1" data-er-row-none="' + e.id + '">Clear</button>'
                + '</div>'
              : '')
          + ordered.map(function (g) {
            var own = centresOf(e).indexOf(g.centre_id) !== -1;
            return '<div class="er-cgroup">' + esc(g.centre_name)
              + (own ? '<span class="own">their centre</span>' : '') + '</div>'
              + g.rooms.map(function (r) {
                var id = 'er-' + e.id + '-' + r.id;
                return '<div class="er-opt">'
                  + '<input type="checkbox" class="er-box" id="' + id + '" data-er-user="' + e.id + '"'
                  + ' data-er-room="' + r.id + '"' + (has(e, r.id) ? ' checked' : '')
                  + (state.editing ? '' : ' tabindex="-1"') + '>'
                  + '<label for="' + id + '">' + esc(r.name) + '</label></div>';
              }).join('');
          }).join('')
          + '</div>';
      }

      return '<div class="er-card">'
        + '<div class="er-card-head" data-er-open="' + e.id + '">'
        + '<div style="min-width:0;"><div class="nm">' + esc(e.name) + '</div>'
        + '<div class="sub">' + esc(roleLabel(e.role)) + ' · ' + esc(centreLabel(e)) + '</div></div>'
        + '<span class="er-pill' + (n ? '' : ' zero') + '">' + (n ? n + (n === 1 ? ' room' : ' rooms') : 'No rooms') + '</span>'
        + '<span style="color:#CBD5E1;font-size:15px;">' + (open ? '▴' : '▾') + '</span>'
        + '</div>' + body + '</div>';
    }).join('');
  }

  // ── painting ────────────────────────────────────────────────────────────────
  /* A repaint that does not move the page.

     #appMain is the portal's one scroller and .er-scroll is the matrix's own; replacing
     the body's innerHTML resets both to the top, which is why ticking a box near the
     bottom threw the view back to the first row. Anything that must repaint restores
     both afterwards, in the same frame, so nothing is drawn at the wrong offset. */
  function keepScroll(main, fn) {
    var sc = main.querySelector('.er-scroll');
    var top = sc ? sc.scrollTop : 0;
    var left = sc ? sc.scrollLeft : 0;
    var app = document.getElementById('appMain');
    var appTop = app ? app.scrollTop : 0;
    var pageTop = window.pageYOffset || 0;

    fn();

    var sc2 = main.querySelector('.er-scroll');
    if (sc2) { sc2.scrollTop = top; sc2.scrollLeft = left; }
    if (app) { app.scrollTop = appTop; }
    if (pageTop) { window.scrollTo(0, pageTop); }
  }

  /* Ticking a box changes three numbers and nothing else. Rewriting the whole grid to
     show that was what made the screen jump; these update the numbers where they sit,
     so no node is replaced and no scroll position is lost. */
  function refreshCounts(main) {
    var host = main.querySelector('#er-body');
    if (!host) { return; }

    host.querySelectorAll('[data-er-rowname]').forEach(function (cell) {
      var e = edOf(cell.getAttribute('data-er-rowname'));
      var cnt = cell.querySelector('.cnt');
      if (!e || !cnt) { return; }
      var n = e.room_ids.length;
      cnt.textContent = n ? n + (n === 1 ? ' room' : ' rooms') : 'No rooms';
      cnt.classList.toggle('zero', n === 0);
    });

    host.querySelectorAll('[data-er-col]').forEach(function (head) {
      var n = staffedCount(parseInt(head.getAttribute('data-er-col'), 10));
      var c = head.querySelector('.c');
      if (c) { c.textContent = n ? n + ' staff' : 'none'; }
      head.classList.toggle('unstaffed', n === 0);
    });

    host.querySelectorAll('.er-pill').forEach(function (pill) {
      var head = pill.closest('[data-er-open]');
      if (!head) { return; }
      var e = edOf(head.getAttribute('data-er-open'));
      if (!e) { return; }
      var n = e.room_ids.length;
      pill.textContent = n ? n + (n === 1 ? ' room' : ' rooms') : 'No rooms';
      pill.classList.toggle('zero', n === 0);
    });
  }

  /* Every checkbox re-read from state. Cheap at this size, and it means a bulk action
     never has to rebuild the grid to show its effect. */
  function syncBoxes(main) {
    var host = main.querySelector('#er-body');
    if (!host) { return; }
    host.querySelectorAll('input.er-box[data-er-room]').forEach(function (cb) {
      var e = edOf(cb.getAttribute('data-er-user'));
      if (!e) { return; }
      var on = has(e, parseInt(cb.getAttribute('data-er-room'), 10));
      if (cb.checked !== on) { cb.checked = on; }
    });
  }

  function applyInPlace(main) {
    syncBoxes(main);
    refreshCounts(main);
    refreshSaveBar(main);
  }

  function paint(main) {
    var host = main.querySelector('#er-body');
    if (!host) { return; }
    state.phone = isPhone();
    host.innerHTML = state.phone ? phoneHtml() : matrixHtml();

    /* Pin the room band exactly under the centre band, from what the browser actually
       laid out. Guessing the offset leaves either a gap that scrolled rows show through
       or an overlap that eats the first row of names. */
    var band = host.querySelector('.er-h-centre');
    var grid = host.querySelector('.er-grid');
    if (band && grid) {
      grid.style.setProperty('--er-h1', band.getBoundingClientRect().height + 'px');
    }

    refreshSaveBar(main);
  }

  /* Hiding a .kt-btn takes an !important, because giving it one is what the portal did:
     `[data-kt-pretty] .kt-btn { display: inline-flex !important; }`. No amount of
     specificity beats that, which is why the first two attempts — the `hidden`
     attribute, then a namespaced class — both left Cancel and Save on screen in
     read-only mode. An inline declaration carrying the same priority does win, and it
     is scoped to the three elements that need it rather than adding another
     !important to the stylesheet for everything else to fight later.

     Showing removes the property outright so the button goes back to whatever the
     stylesheet says, rather than being pinned to a display this file guessed. */
  function show(el, on) {
    if (!el) { return; }
    if (on) { el.style.removeProperty('display'); }
    else { el.style.setProperty('display', 'none', 'important'); }
  }

  function refreshSaveBar(main) {
    var n = Object.keys(state.dirty).length;
    var save = main.querySelector('#er-save');
    var edit = main.querySelector('#er-edit');
    var cancel = main.querySelector('#er-cancel');
    var status = main.querySelector('#er-status');
    var banner = main.querySelector('#er-editing');
    if (!save || !status) { return; }

    var hint = main.querySelector('#er-hint');
    if (hint) {
      hint.textContent = state.editing
        ? 'Anyone can be assigned to any room. Their own centre is shaded.'
        : 'Read-only. Press Edit assignments to make changes.';
    }

    show(edit, !state.editing);
    show(save, state.editing);
    show(cancel, state.editing);
    if (banner) { show(banner, state.editing); }

    save.disabled = n === 0;
    if (!status.getAttribute('data-sticky-msg')) {
      status.style.color = '#64748B';
      status.textContent = state.editing
        ? (n ? n + (n === 1 ? ' person' : ' people') + ' changed — not saved yet' : 'No changes yet')
        : '';
    }
  }

  /* Cancel has to put back exactly what was on screen when Edit was pressed, so the
     snapshot is taken then — a copy per person, not a reference, or editing the live
     array would quietly edit the snapshot too. */
  function snapshot() {
    var snap = {};
    state.educators.forEach(function (e) { snap[e.id] = e.room_ids.slice(); });
    return snap;
  }

  function restore(snap) {
    if (!snap) { return; }
    state.educators.forEach(function (e) {
      if (snap[e.id]) { e.room_ids = snap[e.id].slice(); }
    });
  }

  // ── mutation ────────────────────────────────────────────────────────────────
  function edOf(uid) {
    return state.educators.filter(function (e) { return String(e.id) === String(uid); })[0];
  }

  function setRoom(e, roomId, on) {
    var i = e.room_ids.indexOf(roomId);
    if (on && i === -1) { e.room_ids.push(roomId); }
    if (!on && i !== -1) { e.room_ids.splice(i, 1); }
    state.dirty[e.id] = true;
  }

  function clearStickyMsg(main) {
    var status = main.querySelector('#er-status');
    if (status) { status.removeAttribute('data-sticky-msg'); }
  }

  // ── wiring, by delegation so a repaint never loses its handlers ─────────────
  function wire(main) {
    var body = main.querySelector('#er-body');

    body.addEventListener('change', function (ev) {
      var cb = ev.target.closest ? ev.target.closest('.er-box') : null;
      if (!cb || !cb.hasAttribute('data-er-room')) { return; }
      var e = edOf(cb.getAttribute('data-er-user'));
      if (!e) { return; }

      var rid = parseInt(cb.getAttribute('data-er-room'), 10);
      if (!state.editing) {
        // Reached by keyboard or a script, not by the mouse. Put the box back.
        cb.checked = has(e, rid);
        return;
      }

      setRoom(e, rid, cb.checked);
      clearStickyMsg(main);
      // In place: the box has already drawn itself, only the counts are stale.
      refreshCounts(main);
      refreshSaveBar(main);
    });

    body.addEventListener('click', function (ev) {
      var t = ev.target;
      var el;

      // Expand / collapse a person on the phone layout.
      el = t.closest && t.closest('[data-er-open]');
      if (el) {
        var oid = el.getAttribute('data-er-open');
        state.open[oid] = !state.open[oid];
        keepScroll(main, function () { paint(main); });
        return;
      }

      // Whole row.
      el = t.closest && t.closest('[data-er-row-all]');
      if (el) {
        var ea = edOf(el.getAttribute('data-er-row-all'));
        if (ea) { state.rooms.forEach(function (r) { setRoom(ea, r.id, true); }); }
        clearStickyMsg(main); applyInPlace(main);
        return;
      }
      el = t.closest && t.closest('[data-er-row-none]');
      if (el) {
        var en = edOf(el.getAttribute('data-er-row-none'));
        if (en) { state.rooms.forEach(function (r) { setRoom(en, r.id, false); }); }
        clearStickyMsg(main); applyInPlace(main);
        return;
      }

      /* Whole column. Applies to the people CURRENTLY SHOWN, not the whole agency —
         with a search active that is the only reading that isn't a nasty surprise.
         Nothing is written until Save, so this needs no confirmation. */
      el = t.closest && t.closest('[data-er-col]');
      if (el) {
        var rid = parseInt(el.getAttribute('data-er-col'), 10);
        var shown = visibleEducators();
        var everyone = shown.length && shown.every(function (e) { return has(e, rid); });
        shown.forEach(function (e) { setRoom(e, rid, !everyone); });
        clearStickyMsg(main); applyInPlace(main);
      }
    });

    var search = main.querySelector('#er-search');
    search.addEventListener('input', function () {
      state.search = search.value;
      keepScroll(main, function () { paint(main); });
      // Repainting replaces nothing above the body, so focus survives on its own.
    });

    var un = main.querySelector('#er-unassigned');
    un.addEventListener('click', function () {
      state.onlyUnassigned = !state.onlyUnassigned;
      un.setAttribute('aria-pressed', state.onlyUnassigned ? 'true' : 'false');
      keepScroll(main, function () { paint(main); });
    });

    main.querySelector('#er-edit').onclick = function () {
      state.editing = true;
      state.snapshot = snapshot();
      state.dirty = {};
      clearStickyMsg(main);
      keepScroll(main, function () { paint(main); });
    };

    main.querySelector('#er-cancel').onclick = function () {
      restore(state.snapshot);
      state.snapshot = null;
      state.editing = false;
      state.dirty = {};
      clearStickyMsg(main);
      keepScroll(main, function () { paint(main); });
    };

    main.querySelector('#er-save').onclick = async function () {
      var save = main.querySelector('#er-save');
      var status = main.querySelector('#er-status');

      // Only the people actually changed — sending everybody would rewrite rows nobody
      // touched and lose who assigned them, and when.
      var payload = Object.keys(state.dirty).map(function (uid) {
        var e = edOf(uid);
        return e ? { user_id: e.id, room_ids: e.room_ids } : null;
      }).filter(Boolean);

      save.disabled = true;
      save.textContent = 'Saving…';
      try {
        var r = await Api.post('/provider/educator-rooms', { assignments: payload });
        state.dirty = {};
        state.editing = false;      // saved — back to read-only
        state.snapshot = null;
        save.textContent = 'Save changes';
        status.setAttribute('data-sticky-msg', '1');
        status.style.color = '#1E8E60';
        status.textContent = '✓ Saved ' + r.changed + (r.changed === 1 ? ' person' : ' people');
        await load(main);
      } catch (err) {
        save.disabled = false;
        save.textContent = 'Save changes';
        status.setAttribute('data-sticky-msg', '1');
        status.style.color = '#BE4038';
        status.textContent = (err && err.message) || 'Could not save';
      }
    };

    /* Crossing the phone/desktop boundary swaps renderer. Only repaint when the answer
       actually changes, so dragging a window edge does not thrash.

       #appMain is reused by every screen, so this listener outlives us — it must confirm
       our own body is still on the page before touching anything, or it repaints a
       detached node while the user is somewhere else entirely
       (see kiddietrac-sweep-detached-container). */
    if (!main.__erResize) {
      main.__erResize = function () {
        if (!main.isConnected) { return; }
        var mine = main.querySelector('#er-body');
        if (!mine) { return; }
        if (isPhone() !== state.phone) { paint(main); }
      };
      window.addEventListener('resize', main.__erResize);
    }
  }

  // ── load ────────────────────────────────────────────────────────────────────
  async function load(main) {
    var host = main.querySelector('#er-body');
    var res;
    try {
      res = await Api.get('/provider/educator-rooms');
    } catch (e) {
      host.innerHTML = '<div class="kt-card" style="color:#B91C1C;">Could not load: ' + esc(e.message || 'error') + '</div>';
      return;
    }

    state.rooms = res.rooms || [];
    state.educators = res.educators || [];
    state.dirty = {};

    if (!state.educators.length) {
      host.innerHTML = '<div class="kt-card" style="color:#64748B;padding:22px;">No educators in this agency yet.</div>';
      return;
    }

    var unassigned = state.educators.filter(function (e) { return !e.room_ids.length; }).length;
    var unstaffed = state.rooms.filter(function (r) { return !staffedCount(r.id); }).length;
    var summary = main.querySelector('#er-summary');
    if (summary) {
      var bits = [];
      if (unassigned) { bits.push(unassigned + ' of ' + state.educators.length + ' have no room'); }
      if (unstaffed) { bits.push(unstaffed + (unstaffed === 1 ? ' room has' : ' rooms have') + ' nobody'); }
      summary.innerHTML = bits.length
        ? '<div class="kt-card" style="background:#FFF7ED;border-color:#FED7AA;color:#9A3412;margin:0 0 14px;padding:11px 14px;font-size:13px;">'
          + '<strong>' + esc(bits.join(' · ')) + '.</strong> '
          + 'Screens that work room-by-room fall back to the whole centre until this is filled in.</div>'
        : '';
    }

    paint(main);
  }

  async function render(main) {
    injectStyle();
    main.setAttribute('data-kt-pretty', '1');
    main.innerHTML = '<div class="er-wrap">'
      + '<div class="kt-page-hero"><h2>🚪 Room assignments</h2>'
      + '<p>Which rooms each person works in. This decides whose children they see, and lets '
      + 'attendance and sign-out figures be attributed to a person rather than a whole centre. '
      + 'Anyone can be assigned to any room — their own centre is shaded.</p></div>'
      + '<div id="er-summary"></div>'
      + '<div class="er-bar">'
      + '<input id="er-search" class="er-search" type="search" placeholder="Search people, centres or rooms…" autocomplete="off">'
      + '<button type="button" id="er-unassigned" class="er-toggle" data-kt-iconized="1" aria-pressed="false">'
      + 'Only people with no room</button>'
      + '<span class="er-note" id="er-hint">Read-only. Press Edit assignments to make changes.</span>'
      + '</div>'
      + '<div class="er-editing" id="er-editing" style="display:none !important;">'
      + '<span>✏️ Editing</span>'
      + '<span style="font-weight:600;">Tick a room to assign it. Click a room heading to '
      + 'assign or clear everyone shown. Nothing is saved until you press Save.</span>'
      + '</div>'
      + '<div id="er-body">Loading…</div>'
      + '<div class="er-savebar">'
      + '<span id="er-status" style="font-size:13px;color:#64748B;"></span>'
      /* data-kt-iconized tells kt-icon-buttons.js this button is already handled.
         Without it, "✏️ Edit assignments" was replaced by a bare ✏️ glyph — the one
         control that explains how the screen works, reduced to an unlabelled icon. */
      + '<button type="button" id="er-edit" class="kt-btn kt-btn-primary" data-kt-iconized="1">'
      + '✏️ Edit assignments</button>'
      + '<button type="button" id="er-cancel" class="kt-btn" data-kt-iconized="1"'
      + ' style="display:none !important;">Cancel</button>'
      + '<button type="button" id="er-save" class="kt-btn kt-btn-primary" data-kt-iconized="1" disabled'
      + ' style="display:none !important;">Save changes</button>'
      + '</div>'
      + '<div style="text-align:right;font-size:10px;color:#CBD5E1;margin-top:6px;">' + SCREEN_BUILD + '</div>'
      + '</div>';

    wire(main);
    await load(main);
  }

  KT.EducatorRooms = { render: render, build: SCREEN_BUILD };
  ['agency_admin', 'centre_director', 'platform_admin'].forEach(function (r) {
    KT.Shell.registerScreen(r + ':educator-rooms', render);
  });
})(window);
