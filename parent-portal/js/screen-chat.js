/* ═══════════════════════════════════════════════════════════════════
   KIDDIETRAC v12-big — Chat screen (parent + provider)
   - List of conversations with unread badges
   - Thread view with bubbles (mine = right, theirs = left)
   - Polling every 15 sec for new messages
   - Auto-mark-read when opening a thread
   - Compose box at the bottom
   ═══════════════════════════════════════════════════════════════════ */
(function (window) {
  'use strict';

  const POLL_INTERVAL_MS = 15000;
  const THREAD_POLL_MS = 4000;     // faster poll while a thread is open (realtime feel)
  let pollTimer = null;
  let threadPollTimer = null;
  let openThreadId = null;
  let myRole = 'guardian';
  let lastUnread = null;
  let lastThreadMsgId = 0;

  // Loud, bright two-note "ding-dong" chime (WebAudio) + vibration to grab
  // attention on a new message — much louder + fuller than the old single beep.
  function playPing() {
    try {
      var Ctx = window.AudioContext || window.webkitAudioContext;
      if (Ctx) {
        var ctx = playPing._ctx || (playPing._ctx = new Ctx());
        if (ctx.state === 'suspended') { ctx.resume(); }
        var master = ctx.createGain(); master.gain.value = 0.95; master.connect(ctx.destination);
        var tone = function (freq, start, dur, peak) {
          var o = ctx.createOscillator(), g = ctx.createGain();
          o.type = 'triangle'; o.frequency.value = freq;
          var t = ctx.currentTime + start;
          g.gain.setValueAtTime(0.0001, t);
          g.gain.exponentialRampToValueAtTime(peak, t + 0.015);
          g.gain.exponentialRampToValueAtTime(0.0001, t + dur);
          o.connect(g); g.connect(master);
          o.start(t); o.stop(t + dur + 0.03);
        };
        // E6+E5 then B5+B4 — a doorbell-like chime, played twice as loud as before.
        tone(1318.5, 0.00, 0.36, 0.75); tone(659.3, 0.00, 0.36, 0.4);
        tone(987.8, 0.19, 0.52, 0.75);  tone(493.9, 0.19, 0.52, 0.4);
      }
    } catch (e) {}
    try { if (navigator.vibrate) navigator.vibrate([90, 50, 130]); } catch (e) {}
  }
  // Exposed so other screens (e.g. the parent chat) can play the same loud chime.
  try { (window.KT = window.KT || {}).playPing = playPing; } catch (e) {}

  function $(sel, root = document) { return root.querySelector(sel); }
  function $$(sel, root = document) { return root.querySelectorAll(sel); }

  function getUser() {
    try { return JSON.parse(sessionStorage.getItem('kt_user') || localStorage.getItem('kt_user') || '{}'); }
    catch (e) { return {}; }
  }
  function token() { return sessionStorage.getItem('kt_token') || localStorage.getItem('kt_token'); }
  function apiBase() { return (window.KT && window.KT.API_BASE) || 'https://api.kiddietrac.com/api/v1'; }

  async function api(method, path, body) {
    const opts = {
      method: method,
      headers: { 'Authorization': 'Bearer ' + token(), 'Accept': 'application/json' },
    };
    if (body !== undefined) {
      opts.headers['Content-Type'] = 'application/json';
      opts.body = JSON.stringify(body);
    }
    const res = await fetch(apiBase() + path, opts);
    if (!res.ok) {
      const errText = await res.text();
      throw new Error('API ' + res.status + ': ' + errText.substring(0, 200));
    }
    return res.json();
  }

  function endpointBase() {
    return myRole === 'guardian' ? '/parent/chats' : '/provider/chats';
  }

  function escapeHtml(s) {
    if (s == null) return '';
    return String(s)
      .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
  }

  function formatTime(iso) {
    if (!iso) return '';
    const d = new Date(iso.replace(' ', 'T') + 'Z');
    const now = new Date();
    const today = now.toDateString() === d.toDateString();
    if (today) return d.toLocaleTimeString([], { hour: 'numeric', minute: '2-digit' });
    return d.toLocaleDateString([], { month: 'short', day: 'numeric' });
  }
  // Date AND time together, for the inbox Date column.
  function formatDateTime(iso) {
    if (!iso) return '';
    const d = new Date(iso.replace(' ', 'T') + 'Z');
    const today = new Date().toDateString() === d.toDateString();
    const day = today ? 'Today' : d.toLocaleDateString([], { month: 'short', day: 'numeric' });
    const time = d.toLocaleTimeString([], { hour: 'numeric', minute: '2-digit' });
    return day + ', ' + time;
  }

  /* ─── List view ─────────────────────────────────────────────── */
  async function renderList(container) {
    container.innerHTML = '<div class="kt-chat-loading" style="text-align:center;padding:32px;color:#6B7280;">Loading conversations…</div>';
    try {
      const data = await api('GET', endpointBase());
      const convs = data.conversations || [];
      const isProvider = myRole !== 'guardian';
      const newChatBtnHtml = isProvider
        ? `<button id="kt-new-chat-btn" style="background:#1F6080;color:white;border:none;padding:8px 14px;border-radius:8px;font-size:13px;font-weight:600;cursor:pointer;">+ New chat</button>`
        : '';
      // v22p16: notifications-enable button for any role that hasn't subscribed yet.
      const notifBtnHtml = `<button id="kt-notif-btn" style="background:white;color:#1F6080;border:1px solid #1F6080;padding:7px 12px;border-radius:8px;font-size:12px;font-weight:600;cursor:pointer;display:none;" title="Get an OS notification when a new message arrives, even when this tab is closed.">🔔 Enable notifications</button>`;
      if (convs.length === 0) {
        container.innerHTML = `
          <div class="kt-chat-header" style="padding:16px;border-bottom:1px solid #E5E7EB;background:white;display:flex;justify-content:space-between;align-items:center;">
            <h2 style="font-size:20px;margin:0;">💬 Messages</h2>
            <div style="display:flex;gap:8px;align-items:center;">${notifBtnHtml}${newChatBtnHtml}</div>
          </div>
          <div style="text-align:center;padding:48px 16px;color:#6B7280;">
            <div style="font-size:48px;margin-bottom:12px;">💬</div>
            <p>No conversations yet.</p>
            ${isProvider ? '<p style="font-size:14px;margin-top:8px;">Click <strong>+ New chat</strong> above to start a thread with a family.</p>' : '<p style="font-size:14px;margin-top:8px;">Your centre will reach out about your child\'s day.</p>'}
          </div>`;
        var b = $('#kt-new-chat-btn', container);
        if (b) b.addEventListener('click', function () { openNewChatModal(container); });
        wireNotifBtn(container);
        return;
      }
      const myId = getUser().id;
      const nameOf = (c) => (myRole === 'guardian' ? c.centre_name : c.family_name) || 'Conversation';
      const state = { sort: 'date', dir: -1, q: '' };
      const th = (key, label, extra) => `<th data-sort="${key}" class="kt-msg-th" style="text-align:${extra && extra.right ? 'right' : 'left'};padding:10px 14px;font-size:12px;color:#6B7280;font-weight:600;cursor:pointer;user-select:none;${extra && extra.w ? 'width:' + extra.w + ';' : ''}">${label} <span class="kt-ar" style="color:#94A3B8;"></span></th>`;
      container.innerHTML = `
        <div style="display:flex;flex-direction:column;">
          <div class="kt-chat-header" style="padding:14px 16px;border-bottom:1px solid #E5E7EB;background:white;display:flex;justify-content:space-between;align-items:center;gap:10px;flex-wrap:wrap;">
            <h2 style="font-size:20px;margin:0;">💬 Messages</h2>
            <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
              <input id="kt-msg-filter" type="search" placeholder="🔍 Filter…" style="padding:8px 11px;border:1px solid #D1D5DB;border-radius:8px;font-size:13px;min-width:180px;">
              ${notifBtnHtml}${newChatBtnHtml}
            </div>
          </div>
          <div style="max-height:calc(100vh - 150px);overflow-y:auto;background:#fff;">
            <table style="width:100%;border-collapse:collapse;font-size:14px;table-layout:fixed;">
              <thead>
                <tr style="position:sticky;top:0;z-index:1;background:#F9FAFB;box-shadow:inset 0 -1px 0 #E5E7EB;">
                  ${th('name', 'From', { w: '250px' })}
                  ${th('preview', 'Message')}
                  ${th('date', 'Date', { w: '150px', right: true })}
                </tr>
              </thead>
              <tbody id="kt-msg-tbody"></tbody>
            </table>
          </div>
        </div>
      `;
      const tbody = $('#kt-msg-tbody', container);
      const sortVal = (c) => {
        if (state.sort === 'name') return nameOf(c).toLowerCase();
        if (state.sort === 'preview') return (c.preview || '').toLowerCase();
        return new Date(String(c.last_message_at || '').replace(' ', 'T')).getTime() || 0;
      };
      const rowHtml = (c) => {
        const nm = nameOf(c), unread = c.unread_count > 0;
        return `<tr class="kt-msg-row" data-cid="${c.id}" style="border-top:1px solid #F1F3F5;cursor:pointer;">
          <td style="padding:10px 14px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">
            <span style="display:inline-flex;align-items:center;gap:9px;max-width:100%;">
              <span style="width:30px;height:30px;border-radius:50%;background:linear-gradient(135deg,#1F6080,#8EC73C);color:#fff;display:inline-flex;align-items:center;justify-content:center;font-weight:700;font-size:13px;flex-shrink:0;">${escapeHtml(nm.charAt(0))}</span>
              <span style="font-weight:${unread ? '800' : '600'};color:#111827;overflow:hidden;text-overflow:ellipsis;">${escapeHtml(nm)}${c.child_name ? ` <span style="color:#9CA3AF;font-weight:400;">· ${escapeHtml(c.child_name)}</span>` : ''}</span>
            </span>
          </td>
          <td style="padding:10px 14px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;color:#6B7280;font-weight:${unread ? '600' : '400'};">
            ${c.last_sender_id == myId ? '<span style="color:#9CA3AF;">You: </span>' : ''}${escapeHtml(c.preview || '(no messages yet)')}
            ${unread ? `<span style="background:#1F6080;color:#fff;font-size:11px;font-weight:700;padding:1px 7px;border-radius:10px;margin-left:6px;">${c.unread_count}</span>` : ''}
          </td>
          <td style="padding:10px 14px;text-align:right;color:#9CA3AF;white-space:nowrap;font-weight:${unread ? '700' : '400'};">${formatDateTime(c.last_message_at)}</td>
        </tr>`;
      };
      const paint = () => {
        const q = state.q.trim().toLowerCase();
        let list = convs.slice();
        if (q) list = list.filter(c => (nameOf(c) + ' ' + (c.child_name || '') + ' ' + (c.preview || '')).toLowerCase().indexOf(q) !== -1);
        list.sort((a, b) => { const va = sortVal(a), vb = sortVal(b); return (va < vb ? -1 : va > vb ? 1 : 0) * state.dir; });
        tbody.innerHTML = list.length ? list.map(rowHtml).join('') : '<tr><td colspan="3" style="padding:26px;text-align:center;color:#9CA3AF;">No matching conversations.</td></tr>';
        let z = 0;
        tbody.querySelectorAll('.kt-msg-row').forEach(row => {
          const base = (z++ % 2) ? '#F7F9FB' : '#FFFFFF';
          row.dataset.base = base; row.style.background = base;
          row.addEventListener('mouseenter', () => { row.style.background = '#EEF4F7'; });
          row.addEventListener('mouseleave', () => { row.style.background = row.dataset.base; });
          row.addEventListener('click', () => openThread(parseInt(row.dataset.cid, 10), container));
        });
        container.querySelectorAll('.kt-msg-th').forEach(h => { const ar = h.querySelector('.kt-ar'); if (ar) ar.textContent = (h.getAttribute('data-sort') === state.sort) ? (state.dir < 0 ? '▾' : '▴') : ''; });
      };
      container.querySelectorAll('.kt-msg-th').forEach(h => h.addEventListener('click', () => {
        const k = h.getAttribute('data-sort');
        if (state.sort === k) state.dir = -state.dir; else { state.sort = k; state.dir = (k === 'date') ? -1 : 1; }
        paint();
      }));
      const fi = $('#kt-msg-filter', container);
      if (fi) fi.addEventListener('input', () => { state.q = fi.value || ''; paint(); });
      paint();
      var newBtn = $('#kt-new-chat-btn', container);
      if (newBtn) newBtn.addEventListener('click', function () { openNewChatModal(container); });
      wireNotifBtn(container);
    } catch (e) {
      container.innerHTML = '<div style="padding:24px;color:#DC2626;">Could not load conversations: ' + escapeHtml(e.message) + '</div>';
    }
  }

  // v22p16/17.1: notification status indicator — ALWAYS visible, showing the
  // current state so the user is never in the dark. Three states:
  //   unsubscribed → 🔔 "Enable notifications" (clickable)
  //   subscribed   → ✓ "Notifications on" (clickable to disable)
  //   denied       → 🚫 "Notifications blocked" (read-only with hint)
  //   unsupported  → hidden entirely (browser too old)
  async function wireNotifBtn(container) {
    var btn = $('#kt-notif-btn', container);
    if (!btn) return;
    if (!('serviceWorker' in navigator) || !('PushManager' in window) || typeof Notification === 'undefined') {
      return; // browser doesn't support push at all — hide silently
    }

    // Determine current state
    var status = 'unknown';
    try {
      status = (window.KT && KT.Push && KT.Push.status) ? await KT.Push.status() : 'unknown';
    } catch (e) { status = 'unknown'; }

    var apply = function (state) {
      btn.style.display = 'inline-flex';
      btn.disabled = false;
      if (state === 'subscribed') {
        btn.textContent = '✓ Notifications on';
        btn.title = 'OS notifications are enabled. Click to disable.';
        btn.style.background = '#ECFDF5';
        btn.style.color = '#065F46';
        btn.style.borderColor = '#16A34A';
      } else if (state === 'denied') {
        btn.textContent = '🚫 Notifications blocked';
        btn.title = 'Your browser blocks notifications for this site. Click the lock icon in the address bar to allow them.';
        btn.style.background = '#FEF2F2';
        btn.style.color = '#991B1B';
        btn.style.borderColor = '#FCA5A5';
        btn.disabled = true;
      } else if (state === 'not_configured') {
        btn.textContent = 'Push not configured';
        btn.style.background = '#F3F4F6';
        btn.style.color = '#6B7280';
        btn.disabled = true;
      } else {
        btn.textContent = '🔔 Enable notifications';
        btn.title = 'Get an OS notification when a new message arrives, even when this tab is closed.';
        btn.style.background = 'white';
        btn.style.color = '#1F6080';
        btn.style.borderColor = '#1F6080';
      }
    };

    if (Notification.permission === 'denied') {
      apply('denied');
      return;
    }
    apply(status);

    btn.addEventListener('click', async function () {
      if (btn.disabled) return;
      var current = btn.textContent;
      try {
        // If already subscribed, treat click as Disable
        var s = await KT.Push.status();
        if (s === 'subscribed') {
          if (!window.confirm('Turn off browser notifications? You will only see new messages when the dashboard tab is open.')) return;
          btn.disabled = true; btn.textContent = '⏳ …';
          await KT.Push.unsubscribe();
          apply('unsubscribed');
          if (KT.Dom && KT.Dom.toast) KT.Dom.toast('Notifications turned off', 'success');
          return;
        }
        // Otherwise subscribe
        btn.disabled = true; btn.textContent = '⏳ Asking…';
        var r = await KT.Push.subscribe(true);
        if (r.status === 'subscribed')        apply('subscribed');
        else if (r.status === 'denied')       apply('denied');
        else if (r.status === 'not_configured') apply('not_configured');
        else {
          btn.disabled = false; btn.textContent = current;
          alert('Could not enable notifications: ' + (r.detail || r.status));
        }
      } catch (e) {
        btn.disabled = false; btn.textContent = current;
        alert('Could not enable notifications: ' + e.message);
      }
    });
  }

  // v22p15.1: provider-side "Start a new chat" modal.
  // Loads families the caller has access to, lets them pick one, write a subject
  // + first message, and POSTs to /provider/chats/start. On success, opens the
  // resulting thread directly.
  async function openNewChatModal(container) {
    var overlay = document.createElement('div');
    overlay.style.cssText = 'position:fixed;inset:0;background:rgba(15,23,42,.55);z-index:9999;display:flex;align-items:center;justify-content:center;padding:24px;';
    var modal = document.createElement('div');
    modal.style.cssText = 'background:white;border-radius:14px;padding:24px;max-width:520px;width:100%;box-shadow:0 20px 50px rgba(0,0,0,.3);';
    modal.innerHTML =
      '<h3 style="margin:0 0 12px;font-size:18px;">💬 Start a new conversation</h3>' +
      '<div id="kt-new-chat-form-body" style="color:#6B7280;font-size:14px;">Loading families…</div>';
    overlay.appendChild(modal);
    document.body.appendChild(overlay);
    overlay.addEventListener('click', function (e) { if (e.target === overlay) overlay.remove(); });

    // Pick the right families endpoint based on role.
    var famPath = (myRole === 'agency_admin') ? '/admin/families'
                : (myRole === 'centre_director' || myRole === 'educator') ? '/director/families'
                : null;
    if (! famPath) {
      modal.querySelector('#kt-new-chat-form-body').innerHTML = '<div style="color:#DC2626;">Not available for your role.</div>';
      return;
    }

    var families = [];
    try {
      var fdata = await api('GET', famPath);
      // /admin/families uses 'data' key, /director/families uses 'data' or 'families'
      families = fdata.data || fdata.families || [];
    } catch (e) {
      modal.querySelector('#kt-new-chat-form-body').innerHTML = '<div style="color:#DC2626;">Could not load families: ' + escapeHtml(e.message) + '</div>';
      return;
    }
    if (! families.length) {
      modal.querySelector('#kt-new-chat-form-body').innerHTML = '<div style="color:#6B7280;">No families on file yet. Once a family is enrolled, you can message them here.</div>';
      return;
    }

    modal.querySelector('#kt-new-chat-form-body').innerHTML =
      '<div style="margin-bottom:14px;">' +
        '<label style="display:block;font-size:13px;font-weight:600;color:#374151;margin-bottom:4px;">Family</label>' +
        '<select id="kt-nc-family" style="width:100%;padding:8px 10px;border:1px solid #D1D5DB;border-radius:6px;font-size:14px;background:white;">' +
          families.map(function (f) {
            return '<option value="' + f.id + '">' + escapeHtml(f.family_name || ('Family #' + f.id)) + '</option>';
          }).join('') +
        '</select>' +
      '</div>' +
      '<div style="margin-bottom:14px;">' +
        '<label style="display:block;font-size:13px;font-weight:600;color:#374151;margin-bottom:4px;">Subject (optional)</label>' +
        '<input id="kt-nc-subject" type="text" placeholder="e.g. Pickup change today" style="width:100%;padding:8px 10px;border:1px solid #D1D5DB;border-radius:6px;font-size:14px;box-sizing:border-box;">' +
      '</div>' +
      '<div style="margin-bottom:14px;">' +
        '<label style="display:block;font-size:13px;font-weight:600;color:#374151;margin-bottom:4px;">Message</label>' +
        '<textarea id="kt-nc-body" rows="4" placeholder="Type your message…" style="width:100%;padding:8px 10px;border:1px solid #D1D5DB;border-radius:6px;font-size:14px;font-family:inherit;box-sizing:border-box;resize:vertical;"></textarea>' +
      '</div>' +
      '<div id="kt-nc-err" style="color:#DC2626;font-size:13px;margin-bottom:8px;min-height:18px;"></div>' +
      '<div style="display:flex;justify-content:flex-end;gap:8px;">' +
        '<button id="kt-nc-cancel" style="background:white;color:#374151;border:1px solid #D1D5DB;padding:9px 16px;border-radius:8px;font-size:13px;font-weight:600;cursor:pointer;">Cancel</button>' +
        '<button id="kt-nc-send" style="background:#1F6080;color:white;border:none;padding:9px 16px;border-radius:8px;font-size:13px;font-weight:600;cursor:pointer;">Send</button>' +
      '</div>';

    modal.querySelector('#kt-nc-cancel').addEventListener('click', function () { overlay.remove(); });
    modal.querySelector('#kt-nc-send').addEventListener('click', async function () {
      var fid = parseInt(modal.querySelector('#kt-nc-family').value, 10);
      var subj = modal.querySelector('#kt-nc-subject').value.trim();
      var body = modal.querySelector('#kt-nc-body').value.trim();
      var errBox = modal.querySelector('#kt-nc-err');
      errBox.textContent = '';
      if (!body) { errBox.textContent = 'Please type a message.'; return; }
      var sendBtn = modal.querySelector('#kt-nc-send');
      sendBtn.disabled = true; sendBtn.textContent = 'Sending…';
      try {
        var resp = await api('POST', '/provider/chats/start', { family_id: fid, subject: subj || null, body: body });
        overlay.remove();
        if (resp && resp.conversation_id) {
          openThread(resp.conversation_id, container);
        } else {
          renderList(container);
        }
      } catch (e) {
        sendBtn.disabled = false; sendBtn.textContent = 'Send';
        errBox.textContent = e.message || 'Could not send. Please try again.';
      }
    });

    setTimeout(function () { modal.querySelector('#kt-nc-body').focus(); }, 40);
  }

  /* ─── Thread view ───────────────────────────────────────────── */
  async function openThread(cid, container) {
    openThreadId = cid;
    container.innerHTML = '<div style="text-align:center;padding:32px;color:#6B7280;">Loading…</div>';
    try {
      const data = await api('GET', endpointBase() + '/' + cid);
      renderThread(data, container);
      // Refresh badge after opening (now zero unread)
      if (window.KT && window.KT.refreshUnreadBadge) window.KT.refreshUnreadBadge();
    } catch (e) {
      container.innerHTML = '<div style="padding:24px;color:#DC2626;">Could not load thread: ' + escapeHtml(e.message) + '</div>';
    }
  }

  function renderThread(data, container) {
    const c = data.conversation || {};
    const messages = data.messages || [];
    const headerLabel = myRole === 'guardian' ? c.centre_name : c.family_name;
    const subLabel = c.child_name ? '· ' + c.child_name : '';

    container.innerHTML = `
      <div style="display:flex;flex-direction:column;height:calc(100vh - 110px);max-height:calc(100vh - 110px);background:#F9FAFB;">
        <div class="kt-thread-header" style="padding:14px 16px;border-bottom:1px solid #E5E7EB;background:white;display:flex;align-items:center;gap:12px;flex-shrink:0;">
          <button class="kt-back" style="background:transparent;border:none;font-size:22px;cursor:pointer;color:#1F6080;padding:4px 8px;">←</button>
          <div style="flex:1;">
            <div style="font-weight:700;font-size:16px;">${escapeHtml(headerLabel)} ${escapeHtml(subLabel)}</div>
            ${c.subject ? `<div style="font-size:13px;color:#6B7280;">${escapeHtml(c.subject)}</div>` : ''}
          </div>
        </div>
        <div class="kt-thread-body" style="flex:1;overflow-y:auto;padding:16px;display:flex;flex-direction:column;gap:8px;">
          ${messages.map(m => bubble(m)).join('')}
        </div>
        <div class="kt-attach-preview" style="display:none;padding:8px 12px;border-top:1px solid #E5E7EB;background:#F9FAFB;flex-shrink:0;">
          <div style="display:inline-flex;align-items:center;gap:8px;padding:6px 10px;background:white;border:1px solid #E5E7EB;border-radius:10px;font-size:12px;">
            <span class="kt-attach-name" style="max-width:200px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"></span>
            <button class="kt-attach-remove" style="background:none;border:none;color:#DC2626;cursor:pointer;font-size:14px;line-height:1;">✕</button>
          </div>
        </div>
        <div class="kt-thread-compose" style="padding:10px;border-top:1px solid #E5E7EB;background:white;display:flex;gap:4px;flex-shrink:0;align-items:center;position:relative;">
          <input class="kt-attach-input" type="file" accept="image/jpeg,image/jpg,image/png,image/webp,image/gif" style="display:none;" />
          <button class="kt-emoji-btn" type="button" title="Emoji" style="background:transparent;border:none;cursor:pointer;font-size:22px;padding:4px 6px;">😊</button>
          <button class="kt-attach-btn" type="button" title="Attach a photo or video" style="background:transparent;border:none;color:#6B7280;cursor:pointer;font-size:22px;padding:4px 6px;">📷</button>
          <input class="kt-compose-input" type="text" placeholder="Type a message…" style="flex:1;min-width:0;padding:12px 14px;border:1px solid #D1D5DB;border-radius:24px;font-size:15px;font-family:inherit;" />
          <!-- One action button, exactly like the parent app: 🎤 while the box is
               empty, ➤ as soon as you type. The old wide "Send" button sat there
               permanently and pushed the text box down to nothing. -->
          <button class="kt-mic-btn" type="button" title="Record a voice note" style="background:transparent;border:none;cursor:pointer;font-size:22px;padding:4px 6px;">🎤</button>
          <button class="kt-send-btn" title="Send" style="display:none;background:#159FB4;color:white;border:none;width:44px;height:44px;border-radius:50%;font-weight:700;cursor:pointer;font-size:17px;line-height:1;">➤</button>
          <div class="kt-emoji-panel" style="display:none;position:absolute;bottom:58px;left:8px;background:white;border:1px solid #E5E7EB;border-radius:12px;box-shadow:0 8px 24px rgba(0,0,0,.16);padding:8px;width:280px;max-height:180px;overflow-y:auto;z-index:40;font-size:23px;line-height:1.5;"></div>
        </div>
      </div>
    `;
    const body = $('.kt-thread-body', container);
    body.scrollTop = body.scrollHeight;

    function leaveThread() {
      openThreadId = null;
      if (threadPollTimer) { clearInterval(threadPollTimer); threadPollTimer = null; }
      renderList(container);
    }

    // Register the open thread as a "back overlay" so the Android hardware /
    // gesture back button (and the ← button) returns to the conversation LIST
    // instead of exiting Messages to the previous screen. The thread root leaves
    // the DOM when renderList runs, so kt-back auto-prunes this entry.
    try {
      var threadRoot = container.firstElementChild;
      if (window.KT && KT.pushOverlay && threadRoot) KT.pushOverlay(threadRoot, leaveThread);
    } catch (e) {}

    $('.kt-back', container).addEventListener('click', leaveThread);

    const input = $('.kt-compose-input', container);
    const send = $('.kt-send-btn', container);

    // Mic when there's nothing to send, send arrow the moment there is.
    const micToggle = $('.kt-mic-btn', container);
    const paintAction = () => {
      const typing = !!(input.value || '').trim();
      // setProperty with 'important': the mobile stylesheet sizes these buttons
      // with `display: inline-flex !important`, which out-ranks a plain inline
      // style — so a bare style.display = 'none' silently did nothing and both
      // buttons showed at once.
      if (send) send.style.setProperty('display', typing ? 'inline-flex' : 'none', 'important');
      if (micToggle) micToggle.style.setProperty('display', typing ? 'none' : 'inline-flex', 'important');
    };
    input.addEventListener('input', paintAction);
    paintAction();
    const attachBtn = $('.kt-attach-btn', container);
    const attachInput = $('.kt-attach-input', container);
    const attachPreview = $('.kt-attach-preview', container);
    const attachName = $('.kt-attach-name', container);
    const attachRemove = $('.kt-attach-remove', container);
    let pendingFile = null;

    function setPending(file) {
      pendingFile = file;
      if (file) {
        attachName.textContent = '📎 ' + file.name + ' (' + Math.round(file.size / 1024) + ' KB)';
        attachPreview.style.display = 'block';
      } else {
        attachPreview.style.display = 'none';
        attachName.textContent = '';
        attachInput.value = '';
      }
    }
    attachBtn.addEventListener('click', () => attachInput.click());
    attachInput.addEventListener('change', () => {
      const f = attachInput.files && attachInput.files[0];
      if (!f) return;
      if (f.size > 5 * 1024 * 1024) { alert('Image must be under 5 MB.'); attachInput.value = ''; return; }
      if (!/^image\/(jpeg|png|webp|gif)$/.test(f.type)) { alert('Only JPG, PNG, WEBP, or GIF.'); attachInput.value = ''; return; }
      setPending(f);
    });
    attachRemove.addEventListener('click', () => setPending(null));

    const doSend = async () => {
      const body = input.value.trim();
      if (!body && !pendingFile) return;
      send.disabled = true;
      input.disabled = true;
      attachBtn.disabled = true;
      try {
        let msg;
        if (pendingFile) {
          // v22p17: multipart submission when an attachment is attached
          const fd = new FormData();
          fd.append('body', body);
          fd.append('attachment', pendingFile);
          const res = await fetch(apiBase() + endpointBase() + '/' + c.id + '/send', {
            method: 'POST',
            headers: { 'Authorization': 'Bearer ' + token(), 'Accept': 'application/json' },
            body: fd,
          });
          if (!res.ok) {
            const err = await res.json().catch(() => ({}));
            throw new Error(err.message || ('Upload failed (HTTP ' + res.status + ')'));
          }
          msg = await res.json();
        } else {
          msg = await api('POST', endpointBase() + '/' + c.id + '/send', { body: body });
        }
        input.value = '';
        setPending(null);
        // Append optimistically; re-fetch on next poll for read receipts etc
        const bodyEl = $('.kt-thread-body', container);
        bodyEl.insertAdjacentHTML('beforeend', bubble({
          id: msg.id,
          body: msg.body,
          attachments: msg.attachments || [],
          sender_id: msg.sender_id,
          sender_name: getUser().first_name || 'You',
          is_me: true,
          created_at: msg.created_at,
        }));
        bodyEl.scrollTop = bodyEl.scrollHeight;
        if (msg && msg.id) lastThreadMsgId = Math.max(lastThreadMsgId, msg.id); // don't let the poll re-append
      } catch (e) {
        alert('Could not send: ' + e.message);
      } finally {
        send.disabled = false;
        input.disabled = false;
        attachBtn.disabled = false;
        input.focus();
      }
    };
    send.addEventListener('click', doSend);
    input.addEventListener('keypress', (e) => { if (e.key === 'Enter') doSend(); });

    // ── Emoji picker ──────────────────────────────────────────
    const emojiBtn = $('.kt-emoji-btn', container);
    const emojiPanel = $('.kt-emoji-panel', container);
    if (emojiBtn && emojiPanel) {
      const EMOJIS = ('😀 😁 😂 🤣 😊 😍 😘 😉 😎 🥰 🤗 🤔 👍 👎 👏 🙏 💪 🎉 ❤️ 🧡 💛 💚 💙 💜 🔥 ⭐ ✨ 🌈 ☀️ 🌙 😢 😭 😴 🤒 🤕 🤧 😷 👶 🍼 🧸 🎈 🚌 🏫 📚 ✏️ 🍎 🥪 🧃 👌 🙌').split(' ');
      emojiPanel.innerHTML = EMOJIS.map(e => '<span class="kt-emoji" role="button" style="cursor:pointer;display:inline-block;padding:2px 4px;">' + e + '</span>').join('');
      emojiBtn.addEventListener('click', (ev) => { ev.stopPropagation(); emojiPanel.style.display = (emojiPanel.style.display === 'none' ? 'block' : 'none'); });
      emojiPanel.addEventListener('click', (ev) => { const s = ev.target.closest('.kt-emoji'); if (!s) return; input.value += s.textContent; input.focus(); });
      document.addEventListener('click', (ev) => { if (emojiPanel.style.display === 'block' && !ev.target.closest('.kt-emoji-panel') && !ev.target.closest('.kt-emoji-btn')) emojiPanel.style.display = 'none'; });
    }

    // ── Voice note recording (MediaRecorder → uploaded as an audio attachment) ──
    const micBtn = $('.kt-mic-btn', container);
    let mediaRec = null, recChunks = [], recStream = null, recording = false;
    if (micBtn) {
      micBtn.addEventListener('click', async () => {
        if (recording) { try { mediaRec && mediaRec.stop(); } catch (e) {} return; }
        if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia || !window.MediaRecorder) {
          alert('Voice recording is not supported on this device/browser.'); return;
        }
        try { recStream = await navigator.mediaDevices.getUserMedia({ audio: true }); }
        catch (e) { alert('Microphone permission was denied.'); return; }
        recChunks = [];
        const pref = (window.MediaRecorder.isTypeSupported && MediaRecorder.isTypeSupported('audio/webm')) ? 'audio/webm'
                   : ((window.MediaRecorder.isTypeSupported && MediaRecorder.isTypeSupported('audio/mp4')) ? 'audio/mp4' : '');
        try { mediaRec = pref ? new MediaRecorder(recStream, { mimeType: pref }) : new MediaRecorder(recStream); }
        catch (e) { mediaRec = new MediaRecorder(recStream); }
        mediaRec.ondataavailable = (ev) => { if (ev.data && ev.data.size) recChunks.push(ev.data); };
        mediaRec.onstop = () => {
          recording = false; micBtn.textContent = '🎤'; micBtn.style.color = ''; micBtn.title = 'Record a voice note';
          try { recStream.getTracks().forEach(t => t.stop()); } catch (e) {}
          const type = (recChunks[0] && recChunks[0].type) || pref || 'audio/webm';
          const blob = new Blob(recChunks, { type });
          if (blob.size > 800) {
            const ext = type.indexOf('mp4') >= 0 ? 'm4a' : 'webm';
            setPending(new File([blob], 'voice-' + Date.now() + '.' + ext, { type }));
            doSend(); // voice notes auto-send
          }
        };
        mediaRec.start();
        recording = true; micBtn.textContent = '⏹'; micBtn.style.color = '#DC2626'; micBtn.title = 'Tap to stop & send';
      });
    }

    // ── Realtime: poll the OPEN thread, append new messages, alert on incoming ──
    lastThreadMsgId = messages.length ? Math.max.apply(null, messages.map(m => m.id || 0)) : 0;
    if (threadPollTimer) { clearInterval(threadPollTimer); threadPollTimer = null; }
    threadPollTimer = setInterval(async () => {
      if (openThreadId !== c.id) { clearInterval(threadPollTimer); threadPollTimer = null; return; }
      try {
        const fresh = await api('GET', endpointBase() + '/' + c.id);
        const msgs = (fresh && fresh.messages) || [];
        const bodyEl = $('.kt-thread-body', container);
        if (!bodyEl) return;
        let added = false, gotIncoming = false;
        msgs.forEach(m => {
          if ((m.id || 0) > lastThreadMsgId) {
            bodyEl.insertAdjacentHTML('beforeend', bubble(m));
            lastThreadMsgId = m.id; added = true;
            if (!m.is_me) gotIncoming = true;
          }
        });
        if (added) bodyEl.scrollTop = bodyEl.scrollHeight;
        if (gotIncoming) { playPing(); if (window.KT && window.KT.refreshUnreadBadge) window.KT.refreshUnreadBadge(); }
      } catch (e) {}
    }, THREAD_POLL_MS);

    input.focus();
  }

  // v22p74: read receipt — ✓ delivered, ✓✓ (blue) read
  function readReceipt(m) {
    if (m.pending) return ' <span style="opacity:.6;">⌁</span>';
    if (m.read_at) return ' <span title="Seen ' + escapeHtml(formatTime(m.read_at)) + '" style="color:#7DD3FC;font-weight:700;">✓✓</span>';
    return ' <span title="Delivered" style="opacity:.85;">✓</span>';
  }

  function bubble(m) {
    const mine = m.is_me;
    const attachments = Array.isArray(m.attachments) ? m.attachments : [];
    const attachmentsHtml = attachments.map(a => {
      if (a.mime && a.mime.indexOf('image/') === 0) {
        // Image attachment — render inline as a click-to-zoom thumbnail
        return `<div style="margin:6px 0;"><a href="${escapeHtml(a.url)}" target="_blank" rel="noopener" style="display:block;"><img src="${escapeHtml(a.url)}" alt="${escapeHtml(a.name || 'image')}" style="max-width:100%;max-height:280px;border-radius:10px;display:block;background:rgba(0,0,0,.04);"></a></div>`;
      }
      if (a.mime && a.mime.indexOf('audio/') === 0) {
        // Voice note — inline audio player.
        return `<div style="margin:6px 0;">🎤 <audio controls preload="none" src="${escapeHtml(a.url)}" style="max-width:230px;height:38px;vertical-align:middle;"></audio></div>`;
      }
      // Non-image fallback (future: pdf, etc.)
      return `<div style="margin:6px 0;"><a href="${escapeHtml(a.url)}" target="_blank" rel="noopener" style="color:${mine ? '#0E7C90' : '#1F6080'};text-decoration:underline;font-size:13px;">📎 ${escapeHtml(a.name || 'attachment')}</a></div>`;
    }).join('');
    // Same bubbles as the parent app: a pale teal tint for your own messages with
    // dark text, not solid brand-blue with white text. The two chats sat side by
    // side looking like different products, and the solid fill also inherited the
    // agency's brand colour — which on one agency is neon lime.
    return `
      <div style="display:flex;justify-content:${mine ? 'flex-end' : 'flex-start'};">
        <div style="max-width:78%;padding:10px 13px;border-radius:16px;background:${mine ? '#E1F3F6' : '#fff'};color:#0D1B2A;box-shadow:0 1px 2px rgba(15,23,42,.06);">
          ${!mine ? `<div style="font-size:11px;font-weight:700;color:#64748B;margin-bottom:2px;">${escapeHtml(m.sender_name)}</div>` : ''}
          ${attachmentsHtml}
          ${m.body ? `<div style="font-size:15px;line-height:1.45;white-space:pre-wrap;word-wrap:break-word;">${escapeHtml(m.body)}</div>` : ''}
          <div style="font-size:10.5px;color:rgba(13,27,42,.5);margin-top:4px;text-align:${mine ? 'right' : 'left'};">${formatTime(m.created_at)}${mine ? readReceipt(m) : ''}</div>
        </div>
      </div>
    `;
  }

  /* ─── Unread badge polling ─────────────────────────────────── */
  async function refreshUnreadBadge() {
    if (!token()) return;
    try {
      const data = await api('GET', '/chats/unread-count');
      // Sound + vibrate alert when a new unread arrives (not on first load).
      if (lastUnread !== null && typeof data.unread === 'number' && data.unread > lastUnread) {
        playPing();
      }
      lastUnread = (typeof data.unread === 'number') ? data.unread : lastUnread;
      const badge = $('#kt-chat-nav-badge');
      if (badge) {
        if (data.unread > 0) {
          badge.textContent = data.unread;
          badge.style.display = 'inline-block';
        } else {
          badge.style.display = 'none';
        }
      }
    } catch (e) {
      // Ignore — endpoint may not exist yet on older deploys
    }
  }

  function startPolling() {
    if (pollTimer) return;
    refreshUnreadBadge();
    pollTimer = setInterval(refreshUnreadBadge, POLL_INTERVAL_MS);
  }
  function stopPolling() {
    if (pollTimer) { clearInterval(pollTimer); pollTimer = null; }
    if (threadPollTimer) { clearInterval(threadPollTimer); threadPollTimer = null; }
  }

  /* ─── Public mount API ─────────────────────────────────────── */
  function mount(container, options) {
    options = options || {};
    myRole = options.role || (getUser().primary_role || 'guardian');
    openThreadId = null;
    renderList(container);
  }

  // Expose
  window.KT = window.KT || {};
  window.KT.Chat = { mount: mount, refreshUnreadBadge: refreshUnreadBadge };
  window.KT.refreshUnreadBadge = refreshUnreadBadge;

  // Auto-start polling once user is loaded
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', startPolling);
  } else {
    startPolling();
  }
  window.addEventListener('storage', (e) => { if (e.key === 'kt_token') startPolling(); });
})(window);
