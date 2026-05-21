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
  let pollTimer = null;
  let openThreadId = null;
  let myRole = 'guardian';

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
      container.innerHTML = `
        <div class="kt-chat-header" style="padding:16px;border-bottom:1px solid #E5E7EB;background:white;display:flex;justify-content:space-between;align-items:center;">
          <h2 style="font-size:20px;margin:0;">💬 Messages</h2>
          ${newChatBtnHtml}
        </div>
        <div class="kt-chat-list">
          ${convs.map(c => `
            <div class="kt-chat-row" data-cid="${c.id}" style="padding:14px 16px;border-bottom:1px solid #F3F4F6;cursor:pointer;display:flex;gap:12px;align-items:flex-start;background:white;">
              <div style="width:42px;height:42px;border-radius:50%;background:linear-gradient(135deg,#1F6080,#8EC73C);color:white;display:flex;align-items:center;justify-content:center;font-weight:700;flex-shrink:0;">
                ${escapeHtml((myRole === 'guardian' ? c.centre_name : c.family_name).charAt(0))}
              </div>
              <div style="flex:1;min-width:0;">
                <div style="display:flex;justify-content:space-between;gap:8px;">
                  <div style="font-weight:${c.unread_count > 0 ? '700' : '600'};font-size:15px;color:#111827;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">
                    ${escapeHtml(myRole === 'guardian' ? c.centre_name : c.family_name)}
                    ${c.child_name ? ` <span style="color:#6B7280;font-weight:400;">· ${escapeHtml(c.child_name)}</span>` : ''}
                  </div>
                  <div style="font-size:12px;color:#9CA3AF;flex-shrink:0;">${formatTime(c.last_message_at)}</div>
                </div>
                <div style="display:flex;justify-content:space-between;gap:8px;margin-top:2px;">
                  <div style="font-size:14px;color:#6B7280;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;font-weight:${c.unread_count > 0 ? '600' : '400'};">
                    ${c.last_sender_id == myId ? '<span style="color:#9CA3AF;">You:</span> ' : ''}
                    ${escapeHtml(c.preview || '(no messages yet)')}
                  </div>
                  ${c.unread_count > 0 ? `<div style="background:#1F6080;color:white;font-size:11px;font-weight:700;padding:2px 8px;border-radius:10px;min-width:20px;text-align:center;flex-shrink:0;">${c.unread_count}</div>` : ''}
                </div>
              </div>
            </div>
          `).join('')}
        </div>
      `;
      $$('.kt-chat-row', container).forEach(row => {
        row.addEventListener('click', () => {
          openThread(parseInt(row.dataset.cid, 10), container);
        });
      });
      // v22p15.1: hook the New chat button (only present for providers)
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
        <div class="kt-thread-compose" style="padding:12px;border-top:1px solid #E5E7EB;background:white;display:flex;gap:8px;flex-shrink:0;align-items:center;">
          <input class="kt-attach-input" type="file" accept="image/jpeg,image/jpg,image/png,image/webp,image/gif" style="display:none;" />
          <button class="kt-attach-btn" type="button" title="Attach an image" style="background:transparent;border:none;color:#6B7280;cursor:pointer;font-size:22px;padding:4px 8px;">📎</button>
          <input class="kt-compose-input" type="text" placeholder="Type a message…" style="flex:1;padding:12px 14px;border:1px solid #D1D5DB;border-radius:24px;font-size:15px;font-family:inherit;" />
          <button class="kt-send-btn" style="background:#1F6080;color:white;border:none;padding:0 18px;border-radius:24px;font-weight:700;cursor:pointer;font-size:15px;">Send</button>
        </div>
      </div>
    `;
    const body = $('.kt-thread-body', container);
    body.scrollTop = body.scrollHeight;

    $('.kt-back', container).addEventListener('click', () => {
      openThreadId = null;
      renderList(container);
    });

    const input = $('.kt-compose-input', container);
    const send = $('.kt-send-btn', container);
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
      // Non-image fallback (future: pdf, etc.)
      return `<div style="margin:6px 0;"><a href="${escapeHtml(a.url)}" target="_blank" rel="noopener" style="color:${mine ? 'white' : '#1F6080'};text-decoration:underline;font-size:13px;">📎 ${escapeHtml(a.name || 'attachment')}</a></div>`;
    }).join('');
    return `
      <div style="display:flex;justify-content:${mine ? 'flex-end' : 'flex-start'};">
        <div style="max-width:75%;padding:10px 14px;border-radius:18px;background:${mine ? '#1F6080' : 'white'};color:${mine ? 'white' : '#111827'};box-shadow:0 1px 2px rgba(0,0,0,0.05);">
          ${!mine ? `<div style="font-size:11px;font-weight:700;color:#6B7280;margin-bottom:2px;">${escapeHtml(m.sender_name)}</div>` : ''}
          ${attachmentsHtml}
          ${m.body ? `<div style="font-size:15px;line-height:1.4;white-space:pre-wrap;word-wrap:break-word;">${escapeHtml(m.body)}</div>` : ''}
          <div style="font-size:10px;opacity:0.7;margin-top:4px;text-align:${mine ? 'right' : 'left'};">${formatTime(m.created_at)}${mine ? readReceipt(m) : ''}</div>
        </div>
      </div>
    `;
  }

  /* ─── Unread badge polling ─────────────────────────────────── */
  async function refreshUnreadBadge() {
    if (!token()) return;
    try {
      const data = await api('GET', '/chats/unread-count');
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
