/* ═══════════════════════════════════════════════════════════════════
   KiddieTrac — Team Messages (#38): staff-to-staff messaging, separate from
   the family/parent chat. Colleagues within an agency can message each other.
   Thread list → thread view (bubbles) + compose, plus a "New message"
   colleague picker. Polls the open thread for new replies.

   GROUPS (2026-08-30): a conversation can hold any number of colleagues. Start
   one with "New group", or add people to an existing thread from its header —
   adding a third person to a 1:1 turns it into a group. Anyone added can read
   the whole history, which the add dialog states before you confirm.
   ═══════════════════════════════════════════════════════════════════ */
(function (window) {
  'use strict';
  var KT = (window.KT = window.KT || {});
  var Shell = KT.Shell, Api = KT.Api, d = document;
  function esc(s) { return String(s == null ? '' : s).replace(/[&<>"]/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]; }); }
  function rel(v) { try { return (KT.Fmt && KT.Fmt.relative) ? KT.Fmt.relative(v) : ''; } catch (e) { return ''; } }
  function avatar(name, photo, size) {
    size = size || 40;
    if (photo) return '<img src="' + esc(photo) + '" alt="" style="width:' + size + 'px;height:' + size + 'px;border-radius:50%;object-fit:cover;flex-shrink:0;background:#EEF2F7;">';
    var init = (String(name || '?').trim().charAt(0) || '?').toUpperCase();
    var col = (KT.cardColour ? KT.cardColour(name || '') : '#1F6080');
    return '<div style="width:' + size + 'px;height:' + size + 'px;border-radius:50%;background:' + col + ';color:#fff;font-weight:700;font-size:' + Math.round(size / 2.4) + 'px;display:flex;align-items:center;justify-content:center;flex-shrink:0;">' + esc(init) + '</div>';
  }

  /* A group has no single face, so it gets the two most recent members overlapped —
     enough to recognise the room at a glance without pretending it is one person. */
  function groupAvatar(members, size) {
    size = size || 44;
    var m = (members || []).slice(0, 2);
    if (! m.length) return avatar('Group', null, size);
    var small = Math.round(size * 0.68);
    var html = '<div style="position:relative;width:' + size + 'px;height:' + size + 'px;flex-shrink:0;">';
    html += '<div style="position:absolute;top:0;left:0;">' + avatar(m[0].name, m[0].photo, small) + '</div>';
    if (m[1]) {
      html += '<div style="position:absolute;bottom:0;right:0;border-radius:50%;box-shadow:0 0 0 2px #fff;">'
        + avatar(m[1].name, m[1].photo, small) + '</div>';
    }
    return html + '</div>';
  }

  var pollTimer = null;
  function stopPoll() { if (pollTimer) { clearInterval(pollTimer); pollTimer = null; } }

  async function render(container) {
    stopPoll();
    container.innerHTML = '<div class="kt-hero"><h1 style="margin:0;">💬 Team messages</h1>'
      + '<div class="kt-hero-sub">Private messages with your colleagues, one to one or as a group.</div>'
      + '<div class="kt-hero-actions"><button class="kt-hero-btn primary" id="tc-new">✏️ New message</button>'
      + '<button class="kt-hero-btn" id="tc-newgrp">👥 New group</button></div></div>'
      + '<div id="tc-body" style="max-width:820px;margin:14px auto 0;"><div style="color:#94A3B8;padding:24px;text-align:center;">Loading…</div></div>';
    container.querySelector('#tc-new').addEventListener('click', function () { openContacts(container); });
    container.querySelector('#tc-newgrp').addEventListener('click', function () { openGroupBuilder(container); });
    await renderList(container);
  }

  async function renderList(container) {
    stopPoll();
    var body = container.querySelector('#tc-body');
    var data;
    try { data = await Api.get('/provider/team-threads'); }
    catch (e) { body.innerHTML = '<div style="color:#B91C1C;padding:20px;">Could not load: ' + esc(e.message) + '</div>'; return; }
    var threads = (data && data.threads) || [];
    if (! threads.length) {
      body.innerHTML = '<div style="text-align:center;padding:40px 20px;color:#64748B;">'
        + '<div style="font-size:34px;">💬</div><div style="margin-top:8px;font-weight:600;">No conversations yet</div>'
        + '<div style="font-size:13px;margin-top:4px;">Click <strong>New message</strong> to start a chat with a colleague.</div></div>';
      return;
    }
    body.innerHTML = threads.map(function (t) {
      return '<div class="tc-row" data-id="' + t.id + '" data-name="' + esc(t.name) + '" style="display:flex;align-items:center;gap:12px;padding:12px 14px;border:1px solid #E7EDF3;border-radius:12px;margin-bottom:8px;cursor:pointer;background:#fff;">'
        + (t.is_group ? groupAvatar(t.members, 44) : avatar(t.name, t.photo_url, 44))
        + '<div style="flex:1;min-width:0;"><div style="display:flex;justify-content:space-between;gap:8px;">'
        + '<span style="font-weight:700;color:#0F172A;">' + esc(t.name)
        + (t.is_group ? '<span style="font-weight:600;color:#64748B;font-size:12px;"> · ' + (t.member_count || 0) + ' members</span>' : '')
        + '</span>'
        + '<span style="font-size:11px;color:#94A3B8;white-space:nowrap;">' + esc(rel(t.at)) + '</span></div>'
        + '<div style="font-size:13px;color:#64748B;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">' + esc(t.preview || '') + '</div></div>'
        + (t.unread ? '<span style="background:#EF4444;color:#fff;font-size:11px;font-weight:700;min-width:20px;height:20px;border-radius:10px;display:flex;align-items:center;justify-content:center;padding:0 5px;">' + t.unread + '</span>' : '')
        + '</div>';
    }).join('');
    body.querySelectorAll('.tc-row').forEach(function (row) {
      row.addEventListener('click', function () { openThread(container, row.getAttribute('data-id'), row.getAttribute('data-name')); });
    });
  }

  async function openContacts(container) {
    var ov = overlay();
    var box = ov.querySelector('.tc-modal');
    box.innerHTML = '<h3 style="margin:0 0 12px;font-size:18px;">✏️ New message</h3><div style="color:#64748B;font-size:13px;">Loading colleagues…</div>';
    var data;
    try { data = await Api.get('/provider/team-contacts'); }
    catch (e) { box.innerHTML = '<div style="color:#B91C1C;">Could not load: ' + esc(e.message) + '</div>'; return; }
    var contacts = (data && data.contacts) || [];
    box.innerHTML = '<h3 style="margin:0 0 4px;font-size:18px;">✏️ New message</h3>'
      + '<div style="color:#64748B;font-size:12.5px;margin-bottom:10px;">Pick a colleague to message.</div>'
      + '<input id="tc-search" placeholder="Search…" style="width:100%;box-sizing:border-box;padding:9px 12px;border:1px solid #D1D5DB;border-radius:9px;font-size:14px;margin-bottom:10px;">'
      + '<div id="tc-clist" style="max-height:52vh;overflow:auto;">' + groupedRows(contacts) + '</div>';
    /* Seniority first, then alphabetical. Sorted purely by name, the two or three people
       somebody actually needs — the director, the admin — sat scattered among twenty
       educators, so "I can't see the directors" was true in practice even though they were
       all in the list. */
    // Inside the function, not a var read from an outer scope: groupedRows is a hoisted
    // FUNCTION declaration, so it ran before `var ROLE_ORDER = {...}` had been assigned
    // and the sort comparator crashed on undefined['Parent'] — support ticket #11.
    function groupedRows(list) {
      var ROLE_ORDER = { 'Admin': 0, 'Director': 1, 'Home visitor': 2, 'Educator': 3, 'Auditor': 4, 'Parent': 5, 'Staff': 6 };
      var sorted = list.slice().sort(function (a, b) {
        var ra = ROLE_ORDER[a.role] === undefined ? 9 : ROLE_ORDER[a.role];
        var rb = ROLE_ORDER[b.role] === undefined ? 9 : ROLE_ORDER[b.role];
        if (ra !== rb) { return ra - rb; }
        return String(a.name || '').localeCompare(String(b.name || ''));
      });
      var out = '', lastRole = null;
      sorted.forEach(function (c) {
        var role = c.role || 'Staff';
        if (role !== lastRole) {
          // A heading per role, so the list reads as "who are the directors" rather than
          // as one long column of names to scan.
          out += '<div class="tc-h" style="font-size:11px;font-weight:800;letter-spacing:.06em;text-transform:uppercase;'
            + 'color:#94A3B8;padding:10px 8px 4px;">' + esc(role === 'Admin' ? 'Admins' : role + 's') + '</div>';
          lastRole = role;
        }
        out += cRow(c);
      });
      return out;
    }

    function cRow(c) {
      var role = c.role || 'Staff';
      return '<div class="tc-c" data-id="' + c.id + '" data-name="' + esc(c.name) + '" data-role="' + esc(role) + '" style="display:flex;align-items:center;gap:11px;padding:9px 8px;border-radius:9px;cursor:pointer;">'
        + avatar(c.name, c.photo_url, 38)
        // The role in brackets beside the name, not only as a subtitle — at a glance you
        // can tell who is who without reading a second line in grey.
        + '<div style="flex:1;min-width:0;"><div style="font-weight:600;color:#0F172A;">' + esc(c.name)
        + ' <span style="font-weight:500;color:#64748B;">(' + esc(role) + ')</span></div></div></div>';
    }
    box.querySelectorAll('.tc-c').forEach(function (el) {
      el.addEventListener('mouseover', function () { el.style.background = '#F1F5F9'; });
      el.addEventListener('mouseout', function () { el.style.background = ''; });
      el.addEventListener('click', function () { ov.remove(); openCompose(container, el.getAttribute('data-id'), el.getAttribute('data-name')); });
    });
    box.querySelector('#tc-search').addEventListener('input', function (e) {
      var q = e.target.value.toLowerCase();
      // Search the role too — "director" should find the directors, which is how somebody
      // looks for a person whose name they do not remember.
      box.querySelectorAll('.tc-c').forEach(function (el) {
        var hay = (el.getAttribute('data-name') + ' ' + (el.getAttribute('data-role') || '')).toLowerCase();
        el.style.display = hay.indexOf(q) > -1 ? '' : 'none';
      });
      // Hide a role heading whose people have all been filtered out.
      box.querySelectorAll('.tc-h').forEach(function (h) {
        var any = false, n = h.nextElementSibling;
        while (n && n.classList.contains('tc-c')) { if (n.style.display !== 'none') { any = true; break; } n = n.nextElementSibling; }
        h.style.display = any ? '' : 'none';
      });
    });
  }

  /**
   * Pick several colleagues at once. Shared by "New group" and "Add people", which
   * differ only in what they do with the selection and which names they exclude —
   * two near-identical pickers would drift the moment either was touched.
   *
   * onPick(ids, names) is called with the chosen colleagues.
   */
  async function pickColleagues(opts) {
    var ov = overlay();
    var box = ov.querySelector('.tc-modal');
    box.innerHTML = '<h3 style="margin:0 0 12px;font-size:18px;">' + esc(opts.title) + '</h3>'
      + '<div style="color:#64748B;font-size:13px;">Loading colleagues…</div>';
    var data;
    try { data = await Api.get('/provider/team-contacts'); }
    catch (e) { box.innerHTML = '<div style="color:#B91C1C;">Could not load: ' + esc(e.message) + '</div>'; return; }

    var exclude = opts.exclude || [];
    var contacts = ((data && data.contacts) || []).filter(function (c) {
      return exclude.indexOf(parseInt(c.id, 10)) === -1;
    });
    if (! contacts.length) {
      box.innerHTML = '<h3 style="margin:0 0 10px;font-size:18px;">' + esc(opts.title) + '</h3>'
        + '<div style="color:#64748B;font-size:13.5px;">Everyone you can message is already in this conversation.</div>'
        + '<div style="text-align:right;margin-top:14px;"><button id="tc-x" style="background:#F1F5F9;border:1px solid #D1D5DB;border-radius:9px;padding:9px 16px;font-weight:600;cursor:pointer;">Close</button></div>';
      box.querySelector('#tc-x').addEventListener('click', function () { ov.remove(); });
      return;
    }

    box.innerHTML = '<h3 style="margin:0 0 4px;font-size:18px;">' + esc(opts.title) + '</h3>'
      + '<div style="color:#64748B;font-size:12.5px;margin-bottom:10px;">' + esc(opts.sub || '') + '</div>'
      + (opts.needName
          ? '<input id="tc-gname" maxlength="80" placeholder="Group name (e.g. Toddler Room Team)" style="width:100%;box-sizing:border-box;padding:10px 12px;border:1px solid #D1D5DB;border-radius:9px;font-size:14px;margin-bottom:8px;">'
          : '')
      + '<input id="tc-search" placeholder="Search colleagues…" style="width:100%;box-sizing:border-box;padding:9px 12px;border:1px solid #D1D5DB;border-radius:9px;font-size:14px;margin-bottom:8px;">'
      + '<div id="tc-clist" style="max-height:44vh;overflow:auto;border:1px solid #EEF2F7;border-radius:10px;padding:4px;"></div>'
      + '<div id="tc-picked" style="font-size:12.5px;color:#64748B;margin-top:9px;">No one selected yet.</div>'
      + (opts.historyNote
          ? '<div style="background:#EFF6FF;border:1px solid #BFDBFE;color:#1E3A8A;border-radius:9px;padding:8px 11px;font-size:12.5px;margin-top:9px;">'
            + '📖 Anyone you add can read the whole conversation, including everything said before they joined.</div>'
          : '')
      + '<div style="display:flex;justify-content:flex-end;gap:8px;margin-top:14px;">'
      + '<button id="tc-cancel" style="background:#F1F5F9;border:1px solid #D1D5DB;border-radius:9px;padding:9px 16px;font-weight:600;cursor:pointer;">Cancel</button>'
      + '<button id="tc-ok" disabled style="background:#1F6080;color:#fff;border:0;border-radius:9px;padding:9px 18px;font-weight:700;cursor:pointer;opacity:.5;">' + esc(opts.okLabel || 'Add') + '</button></div>';

    var ROLE_ORDER = { 'Admin': 0, 'Director': 1, 'Home visitor': 2, 'Educator': 3, 'Auditor': 4, 'Parent': 5, 'Staff': 6 };
    var sorted = contacts.slice().sort(function (a, b) {
      var ra = ROLE_ORDER[a.role] === undefined ? 9 : ROLE_ORDER[a.role];
      var rb = ROLE_ORDER[b.role] === undefined ? 9 : ROLE_ORDER[b.role];
      if (ra !== rb) return ra - rb;
      return String(a.name || '').localeCompare(String(b.name || ''));
    });
    box.querySelector('#tc-clist').innerHTML = sorted.map(function (c) {
      return '<label class="tc-p" data-name="' + esc(c.name) + '" data-role="' + esc(c.role || 'Staff') + '" '
        + 'style="display:flex;align-items:center;gap:10px;padding:8px;border-radius:8px;cursor:pointer;">'
        + '<input type="checkbox" value="' + c.id + '" data-name="' + esc(c.name) + '" style="width:17px;height:17px;flex-shrink:0;cursor:pointer;">'
        + avatar(c.name, c.photo_url, 34)
        + '<span style="flex:1;min-width:0;font-weight:600;color:#0F172A;">' + esc(c.name)
        + ' <span style="font-weight:500;color:#64748B;">(' + esc(c.role || 'Staff') + ')</span></span></label>';
    }).join('');

    var okBtn = box.querySelector('#tc-ok'), picked = box.querySelector('#tc-picked');
    var nameEl = box.querySelector('#tc-gname');
    function chosen() {
      return [].slice.call(box.querySelectorAll('#tc-clist input:checked'));
    }
    function refresh() {
      var c = chosen();
      var enough = c.length >= (opts.min || 1) && (! opts.needName || (nameEl && nameEl.value.trim() !== ''));
      okBtn.disabled = ! enough;
      okBtn.style.opacity = enough ? '1' : '.5';
      picked.textContent = c.length
        ? c.length + ' selected: ' + c.map(function (i) { return i.getAttribute('data-name'); }).join(', ')
        : (opts.min > 1 ? 'Pick at least ' + opts.min + ' colleagues.' : 'No one selected yet.');
    }
    box.querySelector('#tc-clist').addEventListener('change', refresh);
    if (nameEl) nameEl.addEventListener('input', refresh);
    box.querySelector('#tc-search').addEventListener('input', function (e) {
      var q = e.target.value.toLowerCase();
      box.querySelectorAll('.tc-p').forEach(function (el) {
        var hay = (el.getAttribute('data-name') + ' ' + el.getAttribute('data-role')).toLowerCase();
        el.style.display = hay.indexOf(q) > -1 ? '' : 'none';
      });
    });
    box.querySelector('#tc-cancel').addEventListener('click', function () { ov.remove(); });
    okBtn.addEventListener('click', async function () {
      var c = chosen();
      okBtn.disabled = true; okBtn.textContent = 'Working…';
      try {
        await opts.onPick(c.map(function (i) { return parseInt(i.value, 10); }),
                          c.map(function (i) { return i.getAttribute('data-name'); }),
                          nameEl ? nameEl.value.trim() : null);
        ov.remove();
      } catch (e) {
        okBtn.disabled = false; okBtn.textContent = opts.okLabel || 'Add';
        if (KT.Dom && KT.Dom.toast) KT.Dom.toast(e.message || 'Could not do that', 'error');
      }
    });
    refresh();
    setTimeout(function () { (nameEl || box.querySelector('#tc-search')).focus(); }, 60);
  }

  function openGroupBuilder(container) {
    pickColleagues({
      title: '👥 New group', sub: 'Name the group and pick who is in it.',
      needName: true, min: 2, okLabel: 'Create group',
      onPick: async function (ids, names, title) {
        var r = await Api.post('/provider/team-threads/group', { user_ids: ids, title: title });
        if (r && r.thread_id) openThread(container, r.thread_id, title);
      }
    });
  }

  function openAddPeople(container, threadId, existingIds) {
    pickColleagues({
      title: '➕ Add people', sub: 'They will join this conversation.',
      exclude: existingIds, min: 1, okLabel: 'Add', historyNote: true,
      onPick: async function (ids) {
        await Api.post('/provider/team-threads/' + threadId + '/participants', { user_ids: ids });
        openThread(container, threadId, null);
      }
    });
  }

  // Compose a brand-new thread: first send creates it, then we open it.
  function openCompose(container, recipientId, recipientName) {
    threadView(container, { name: recipientName, messages: [] }, null, async function (text) {
      var r = await Api.post('/provider/team-threads/start', { recipient_user_id: parseInt(recipientId, 10), body: text });
      return r && r.thread_id;
    });
  }

  async function openThread(container, threadId, name) {
    stopPoll();
    var body = container.querySelector('#tc-body');
    body.innerHTML = '<div style="color:#94A3B8;padding:24px;text-align:center;">Loading…</div>';
    var data;
    try { data = await Api.get('/provider/team-threads/' + threadId); }
    catch (e) { body.innerHTML = '<div style="color:#B91C1C;padding:20px;">Could not load: ' + esc(e.message) + '</div>'; return; }
    threadView(container, data, threadId, async function (text) {
      await Api.post('/provider/team-threads/' + threadId + '/send', { body: text });
      return threadId;
    });
    // Poll for new messages while this thread is open.
    pollTimer = setInterval(async function () {
      if (! d.body.contains(body)) { stopPoll(); return; }
      try { var fresh = await Api.get('/provider/team-threads/' + threadId); paintMessages(container, fresh.messages || [], fresh.is_group); } catch (e) {}
    }, 6000);
  }

  function threadView(container, data, threadId, sendFn) {
    var body = container.querySelector('#tc-body');
    var people = data.participants || [];
    var others = people.filter(function (p) { return ! p.me; });
    body.innerHTML = '<div style="display:flex;align-items:center;gap:10px;margin-bottom:4px;">'
      + '<button id="tc-back" style="background:#fff;border:1px solid #D1D5DB;border-radius:8px;padding:7px 12px;font-weight:600;cursor:pointer;">← Back</button>'
      /* Bounded, not flex:1. #appMain is deliberately full-width portal-wide
         (kt-desktop-fluid), so flex:1 pushed the Add button ~1800px away from the
         title it belongs to on a wide screen. */
      + '<div style="flex:0 1 auto;min-width:0;max-width:520px;"><div style="font-weight:800;font-size:16px;color:#0F172A;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">'
      + (data.is_group ? '👥 ' : '') + esc(data.name || 'Colleague') + '</div>'
      /* Who is in the room, spelled out. In a group "who can see this" must never be
         something you have to work out from the bubbles. */
      + (people.length ? '<div style="font-size:12px;color:#64748B;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">'
          + (data.is_group ? esc(String(data.member_count || people.length)) + ' members · ' : '')
          + esc(others.map(function (p) { return p.name; }).join(', ') || 'just you') + '</div>' : '')
      + '</div>'
      + (threadId ? '<button id="tc-add" title="Add people to this conversation" style="background:#fff;border:1px solid #D1D5DB;border-radius:8px;padding:7px 12px;font-weight:700;cursor:pointer;white-space:nowrap;">＋ Add people</button>' : '')
      + '<div style="flex:1;"></div>'
      + '</div>'
      + '<div id="tc-msgs" style="border:1px solid #E7EDF3;border-radius:12px;background:#F8FAFC;padding:12px;height:52vh;overflow-y:auto;"></div>'
      + '<div style="display:flex;gap:8px;margin-top:10px;">'
      + '<input id="tc-input" placeholder="Type a message…" style="flex:1;padding:12px 14px;border:1px solid #D1D5DB;border-radius:24px;font-size:15px;">'
      + '<button id="tc-send" style="background:#1F6080;color:#fff;border:0;border-radius:24px;padding:0 20px;font-weight:700;cursor:pointer;">Send</button></div>';
    paintMessages(container, data.messages || [], data.is_group);
    body.querySelector('#tc-back').addEventListener('click', function () { renderList(container); });
    var addBtn = body.querySelector('#tc-add');
    if (addBtn) {
      addBtn.addEventListener('click', function () {
        openAddPeople(container, threadId, people.map(function (p) { return p.id; }));
      });
    }
    var input = body.querySelector('#tc-input'), btn = body.querySelector('#tc-send');
    async function doSend() {
      var text = input.value.trim();
      if (! text) return;
      input.value = ''; btn.disabled = true;
      try {
        var tid = await sendFn(text);
        if (threadId === null && tid) { openThread(container, tid, data.name); }   // new thread now exists
        else { var fresh = await Api.get('/provider/team-threads/' + (threadId || tid)); paintMessages(container, fresh.messages || [], fresh.is_group); }
      } catch (e) { if (KT.Dom && KT.Dom.toast) KT.Dom.toast(e.message || 'Send failed', 'error'); input.value = text; }
      btn.disabled = false; input.focus();
    }
    btn.addEventListener('click', doSend);
    input.addEventListener('keydown', function (e) { if (e.key === 'Enter') { e.preventDefault(); doSend(); } });
    setTimeout(function () { input.focus(); }, 60);
  }

  function paintMessages(container, msgs, isGroup) {
    var wrap = container.querySelector('#tc-msgs');
    if (! wrap) return;
    if (! msgs.length) { wrap.innerHTML = '<div style="color:#94A3B8;text-align:center;padding:20px;font-size:13px;">No messages yet — say hello 👋</div>'; return; }
    var lastSender = null;
    wrap.innerHTML = msgs.map(function (m) {
      /* "Sarah added Marcus" is the conversation telling you what happened, not somebody
         speaking — a centred pill, never a bubble on either side. */
      if (m.system) {
        lastSender = null;
        return '<div style="text-align:center;margin:10px 0;">'
          + '<span style="display:inline-block;background:#EEF2F7;color:#64748B;font-size:11.5px;font-weight:600;'
          + 'border-radius:20px;padding:4px 12px;">' + esc(m.body) + '</span></div>';
      }
      var mine = m.mine;
      /* In a group, an unattributed bubble is unreadable — you cannot tell three
         colleagues apart. Shown once per run of messages from the same person rather
         than on every bubble, which is noise. */
      var showName = isGroup && ! mine && m.sender !== lastSender;
      lastSender = mine ? null : m.sender;
      return '<div style="display:flex;justify-content:' + (mine ? 'flex-end' : 'flex-start') + ';margin-bottom:8px;">'
        + '<div style="max-width:74%;">'
        + (showName ? '<div style="font-size:11px;font-weight:700;color:#64748B;margin:0 0 2px 12px;">' + esc(m.sender) + '</div>' : '')
        + '<div style="padding:9px 13px;border-radius:16px;font-size:14px;line-height:1.45;'
        + (mine ? 'background:#1F6080;color:#fff;border-bottom-right-radius:5px;' : 'background:#fff;color:#0F172A;border:1px solid #E7EDF3;border-bottom-left-radius:5px;') + '">'
        + esc(m.body)
        + '<div style="font-size:10px;opacity:.7;margin-top:3px;text-align:right;">' + esc(rel(m.at)) + '</div>'
        + '</div></div></div>';
    }).join('');
    wrap.scrollTop = wrap.scrollHeight;
  }

  function overlay() {
    var ov = d.createElement('div');
    ov.style.cssText = 'position:fixed;inset:0;background:rgba(15,23,42,.55);z-index:99999;display:flex;align-items:center;justify-content:center;padding:24px;';
    var box = d.createElement('div');
    box.className = 'tc-modal';
    box.style.cssText = 'background:#fff;border-radius:14px;padding:22px;max-width:460px;width:100%;box-shadow:0 20px 50px rgba(0,0,0,.3);';
    ov.appendChild(box);
    ov.addEventListener('click', function (e) { if (e.target === ov) ov.remove(); });
    d.body.appendChild(ov);
    return ov;
  }

  // Keep the "Team messages" unread badge current across the app — both the admin/
  // director sidebar item (via Shell.setBadge) and the educator/home-visitor top-nav
  // button (#kt-pc-team-badge). Staff only; pure parents never poll the staff route.
  var STAFF = ['agency_admin', 'platform_admin', 'centre_director', 'educator', 'home_visitor', 'auditor'];
  function refreshTeamBadge() {
    var roles;
    try { roles = (JSON.parse(sessionStorage.getItem('kt_user') || '{}').roles) || []; } catch (e) { return; }
    if (! roles.some(function (r) { return STAFF.indexOf(r) > -1; })) return;
    Api.get('/provider/team-threads/unread-count').then(function (dd) {
      var n = (dd && dd.unread) || 0;
      if (KT.Shell && KT.Shell.setBadge) KT.Shell.setBadge('team_unread', n);
      var el = document.getElementById('kt-pc-team-badge');
      if (el) { if (n > 0) { el.textContent = n > 99 ? '99+' : n; el.hidden = false; el.style.display = ''; } else { el.hidden = true; el.style.display = 'none'; } }
    }).catch(function () {});
  }
  setTimeout(refreshTeamBadge, 1800);
  setInterval(refreshTeamBadge, 30000);
  KT.refreshTeamBadge = refreshTeamBadge;

  if (Shell && Shell.registerScreen) {
    ['agency_admin', 'platform_admin', 'centre_director', 'educator', 'home_visitor', 'auditor'].forEach(function (r) {
      Shell.registerScreen(r + ':team-messages', render);
    });
  }
  KT.TeamChatScreen = { render: render };
})(window);
