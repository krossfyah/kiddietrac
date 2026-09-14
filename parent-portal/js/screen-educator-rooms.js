/* ═══════════════════════════════════════════════════════════════════
   KIDDIETRAC — Educator room assignments (bulk)

   educator_rooms is empty across the platform, which is why several screens fall back to
   the centre and why the scorecard reports sign-outs per centre rather than per educator.
   There was no screen to fix it. This is that screen: everyone on one page, saved in one go.

   House table pattern — data-kt-pretty, plain table in .kt-card, no inline widths.
   ═══════════════════════════════════════════════════════════════════ */
(function (window) {
  'use strict';
  var KT = window.KT;
  if (!KT || !KT.Shell || !KT.Shell.registerScreen) { return; }
  var Api = KT.Api;

  function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"]/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c];
    });
  }

  var state = { rooms: [], educators: [], dirty: {} };

  async function render(main) {
    main.setAttribute('data-kt-pretty', '1');
    main.innerHTML = '<div style="padding:24px;max-width:1800px;margin:0 auto;">'
      + '<div class="kt-page-hero"><h2>🚪 Room assignments</h2>'
      + '<p>Which rooms each educator works in. This decides whose children they see, '
      + 'and lets attendance and sign-out figures be attributed to a person rather than a whole centre.</p></div>'
      + '<div id="er-body">Loading…</div></div>';
    await load(main);
  }

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

    host.innerHTML =
      (unassigned
        ? '<div class="kt-card" style="background:#FFF7ED;border-color:#FED7AA;color:#9A3412;margin-bottom:14px;">'
          + '<strong>' + unassigned + ' of ' + state.educators.length + ' have no room yet.</strong> '
          + 'Until they do, screens that work room-by-room fall back to showing the whole centre.</div>'
        : '')
      + '<div class="kt-card"><table><thead><tr>'
      + '<th>Educator</th><th>Role</th><th>Centre</th><th>Rooms</th>'
      + '</tr></thead><tbody>'
      + state.educators.map(rowFor).join('')
      + '</tbody></table></div>'
      + '<div style="display:flex;align-items:center;gap:14px;justify-content:flex-end;margin-top:14px;">'
      + '<span id="er-status" style="font-size:13px;color:#64748B;"></span>'
      + '<button id="er-save" class="kt-btn kt-btn-primary" disabled>Save changes</button></div>';

    wire(main);
  }

  function chipFor(e, r) {
    var on = e.room_ids.indexOf(r.id) !== -1;
    return '<label data-er-chip style="display:inline-flex;align-items:center;gap:6px;border:1.5px solid '
      + (on ? '#1F6080' : '#E2E8F0') + ';background:' + (on ? '#EFF6FF' : '#fff')
      + ';color:' + (on ? '#1F6080' : '#475569')
      + ';border-radius:999px;padding:4px 11px;font-size:12.5px;font-weight:600;cursor:pointer;margin:2px 4px 2px 0;">'
      + '<input type="checkbox" data-er-user="' + e.id + '" data-er-room="' + r.id + '"'
      + (on ? ' checked' : '') + ' style="margin:0;">'
      + esc(r.name) + '</label>';
  }

  function rowFor(e) {
    /* EVERY CENTRE THIS PERSON IS POSTED TO, NOT JUST THE FIRST (2026-09-14).

       This read e.centre_id, one centre, because that was all the API sent. Somebody
       working across nine centres was therefore offered the rooms of one, and ticking
       a room at any of the other eight was impossible — the checkbox was never drawn.
       That is the "all rooms do not appear" report.

       The original intent still holds and is kept: a person is offered the rooms of
       THEIR centres, not every room in the agency, so nobody gets attached to a site
       they have no business at by a mis-click. What changed is that "their centres"
       is now the real list. An empty list still means an agency-level posting with no
       centre, which correctly sees everything. */
    var centreIds = e.centre_ids && e.centre_ids.length
      ? e.centre_ids
      : (e.centre_id ? [e.centre_id] : []);

    var rooms = state.rooms.filter(function (r) {
      return centreIds.length ? centreIds.indexOf(r.centre_id) !== -1 : true;
    });

    var chips;
    if (!rooms.length) {
      chips = '<span style="color:#94A3B8;font-size:12.5px;">'
        + (centreIds.length > 1 ? 'No rooms at any of their centres yet.' : 'No rooms at this centre yet.')
        + '</span>';
    } else if (centreIds.length > 1) {
      /* Grouped under a heading per centre. Room names repeat across sites — nearly
         every centre here has a "Toddlers" — so a flat row would offer several
         identically-labelled checkboxes with no way to tell them apart. */
      var byCentre = {};
      var order = [];
      rooms.forEach(function (r) {
        var key = r.centre_name || ('Centre ' + r.centre_id);
        if (!byCentre[key]) { byCentre[key] = []; order.push(key); }
        byCentre[key].push(r);
      });
      chips = order.map(function (key) {
        return '<div style="margin:0 0 7px;">'
          + '<div style="font-size:11px;font-weight:800;color:#64748B;letter-spacing:.02em;margin:0 0 3px;">'
          + esc(key) + '</div>'
          + byCentre[key].map(function (r) { return chipFor(e, r); }).join('')
          + '</div>';
      }).join('');
    } else {
      chips = rooms.map(function (r) { return chipFor(e, r); }).join('');
    }

    var centreLabel = (e.centre_names && e.centre_names.length > 1)
      ? '<span title="' + esc(e.centre_names.join(', ')) + '" style="font-weight:700;color:#1F6080;">'
        + e.centre_names.length + ' centres</span>'
      : esc((e.centre_names && e.centre_names[0]) || e.centre_name || '—');

    var roleLabel = String(e.role || '').split('/').map(function (x) {
      x = x.trim();
      return x === 'home_visitor' ? 'Home visitor' : (x === 'educator' ? 'Educator' : x);
    }).filter(Boolean).join(' / ') || 'Educator';

    return '<tr>'
      + '<td><strong>' + esc(e.name) + '</strong><div style="font-size:11.5px;color:#94A3B8;">' + esc(e.email || '') + '</div></td>'
      + '<td>' + esc(roleLabel) + '</td>'
      + '<td>' + centreLabel + '</td>'
      + '<td>' + chips + '</td></tr>';
  }

  function wire(main) {
    var save = main.querySelector('#er-save');
    var status = main.querySelector('#er-status');

    main.querySelectorAll('[data-er-room]').forEach(function (cb) {
      cb.addEventListener('change', function () {
        var uid = parseInt(cb.getAttribute('data-er-user'), 10);
        var rid = parseInt(cb.getAttribute('data-er-room'), 10);
        var ed = state.educators.filter(function (e) { return e.id === uid; })[0];
        if (!ed) { return; }

        var i = ed.room_ids.indexOf(rid);
        if (cb.checked && i === -1) { ed.room_ids.push(rid); }
        if (!cb.checked && i !== -1) { ed.room_ids.splice(i, 1); }
        state.dirty[uid] = true;

        // Repaint the chip so the ring matches the checkbox.
        var lbl = cb.parentElement;
        lbl.style.borderColor = cb.checked ? '#1F6080' : '#E2E8F0';
        lbl.style.background = cb.checked ? '#EFF6FF' : '#fff';
        lbl.style.color = cb.checked ? '#1F6080' : '#475569';

        var n = Object.keys(state.dirty).length;
        save.disabled = n === 0;
        status.textContent = n ? n + (n === 1 ? ' person' : ' people') + ' changed' : '';
      });
    });

    save.onclick = async function () {
      // Only the people actually changed — sending everybody would rewrite rows that
      // nobody touched and lose who assigned them and when.
      var payload = Object.keys(state.dirty).map(function (uid) {
        var ed = state.educators.filter(function (e) { return String(e.id) === String(uid); })[0];
        return { user_id: ed.id, room_ids: ed.room_ids };
      });
      save.disabled = true; save.textContent = 'Saving…';
      try {
        var r = await Api.post('/provider/educator-rooms', { assignments: payload });
        status.style.color = '#1E8E60';
        status.textContent = '✓ Saved ' + r.changed + (r.changed === 1 ? ' person' : ' people');
        save.textContent = 'Save changes';
        await load(main);
      } catch (e) {
        save.disabled = false; save.textContent = 'Save changes';
        status.style.color = '#BE4038';
        status.textContent = (e && e.message) || 'Could not save';
      }
    };
  }

  KT.EducatorRooms = { render: render };
  ['agency_admin', 'centre_director', 'platform_admin'].forEach(function (r) {
    KT.Shell.registerScreen(r + ':educator-rooms', render);
  });
})(window);
