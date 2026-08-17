/* ═══════════════════════════════════════════════════════════════════
   KiddieTrac — Team Messages (#38): staff-to-staff 1:1 direct messaging,
   separate from the family/parent chat. Colleagues within an agency can
   message each other. Thread list → thread view (bubbles) + compose, plus a
   "New message" colleague picker. Polls the open thread for new replies.
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

  var pollTimer = null;
  function stopPoll() { if (pollTimer) { clearInterval(pollTimer); pollTimer = null; } }

  async function render(container) {
    stopPoll();
    container.innerHTML = '<div class="kt-hero"><h1 style="margin:0;">💬 Team messages</h1>'
      + '<div class="kt-hero-sub">Private 1:1 messages with your colleagues.</div>'
      + '<div class="kt-hero-actions"><button class="kt-hero-btn primary" id="tc-new">✏️ New message</button></div></div>'
      + '<div id="tc-body" style="max-width:820px;margin:14px auto 0;"><div style="color:#94A3B8;padding:24px;text-align:center;">Loading…</div></div>';
    container.querySelector('#tc-new').addEventListener('click', function () { openContacts(container); });
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
        + avatar(t.name, t.photo_url, 44)
        + '<div style="flex:1;min-width:0;"><div style="display:flex;justify-content:space-between;gap:8px;">'
        + '<span style="font-weight:700;color:#0F172A;">' + esc(t.name) + '</span>'
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
    var ROLE_ORDER = { 'Admin': 0, 'Director': 1, 'Home visitor': 2, 'Educator': 3, 'Auditor': 4, 'Staff': 5 };
    function groupedRows(list) {
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
      try { var fresh = await Api.get('/provider/team-threads/' + threadId); paintMessages(container, fresh.messages || []); } catch (e) {}
    }, 6000);
  }

  function threadView(container, data, threadId, sendFn) {
    var body = container.querySelector('#tc-body');
    body.innerHTML = '<div style="display:flex;align-items:center;gap:10px;margin-bottom:10px;">'
      + '<button id="tc-back" style="background:#fff;border:1px solid #D1D5DB;border-radius:8px;padding:7px 12px;font-weight:600;cursor:pointer;">← Back</button>'
      + '<div style="font-weight:800;font-size:16px;color:#0F172A;">' + esc(data.name || 'Colleague') + '</div></div>'
      + '<div id="tc-msgs" style="border:1px solid #E7EDF3;border-radius:12px;background:#F8FAFC;padding:12px;height:52vh;overflow-y:auto;"></div>'
      + '<div style="display:flex;gap:8px;margin-top:10px;">'
      + '<input id="tc-input" placeholder="Type a message…" style="flex:1;padding:12px 14px;border:1px solid #D1D5DB;border-radius:24px;font-size:15px;">'
      + '<button id="tc-send" style="background:#1F6080;color:#fff;border:0;border-radius:24px;padding:0 20px;font-weight:700;cursor:pointer;">Send</button></div>';
    paintMessages(container, data.messages || []);
    body.querySelector('#tc-back').addEventListener('click', function () { renderList(container); });
    var input = body.querySelector('#tc-input'), btn = body.querySelector('#tc-send');
    async function doSend() {
      var text = input.value.trim();
      if (! text) return;
      input.value = ''; btn.disabled = true;
      try {
        var tid = await sendFn(text);
        if (threadId === null && tid) { openThread(container, tid, data.name); }   // new thread now exists
        else { var fresh = await Api.get('/provider/team-threads/' + (threadId || tid)); paintMessages(container, fresh.messages || []); }
      } catch (e) { if (KT.Dom && KT.Dom.toast) KT.Dom.toast(e.message || 'Send failed', 'error'); input.value = text; }
      btn.disabled = false; input.focus();
    }
    btn.addEventListener('click', doSend);
    input.addEventListener('keydown', function (e) { if (e.key === 'Enter') { e.preventDefault(); doSend(); } });
    setTimeout(function () { input.focus(); }, 60);
  }

  function paintMessages(container, msgs) {
    var wrap = container.querySelector('#tc-msgs');
    if (! wrap) return;
    if (! msgs.length) { wrap.innerHTML = '<div style="color:#94A3B8;text-align:center;padding:20px;font-size:13px;">No messages yet — say hello 👋</div>'; return; }
    wrap.innerHTML = msgs.map(function (m) {
      var mine = m.mine;
      return '<div style="display:flex;justify-content:' + (mine ? 'flex-end' : 'flex-start') + ';margin-bottom:8px;">'
        + '<div style="max-width:74%;padding:9px 13px;border-radius:16px;font-size:14px;line-height:1.45;'
        + (mine ? 'background:#1F6080;color:#fff;border-bottom-right-radius:5px;' : 'background:#fff;color:#0F172A;border:1px solid #E7EDF3;border-bottom-left-radius:5px;') + '">'
        + esc(m.body)
        + '<div style="font-size:10px;opacity:.7;margin-top:3px;text-align:right;">' + esc(rel(m.at)) + '</div>'
        + '</div></div>';
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
