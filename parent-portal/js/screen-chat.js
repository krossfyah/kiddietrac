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
  const THREAD_POLL_MS = 10000;     // faster poll while a thread is open (realtime feel)
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
  // Make a photo path absolute against the API host (relative /storage/... paths
  // don't resolve against the SPA origin). Absolute URLs pass through untouched.
  function absPhotoUrl(u) {
    if (!u) return '';
    if (/^https?:\/\//i.test(u) || u.indexOf('data:') === 0) return u;
    var origin = apiBase().replace(/\/api\/v1\/?$/, '');
    return origin + (u.charAt(0) === '/' ? '' : '/') + u;
  }

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
  // A notification can deep-link straight to a thread: #chat?c=8. Without this,
  // tapping "new message from Anthony" dropped you on the conversation LIST and
  // you had to find the thread again — which is the one thing the tap was for.
  function deepLinkedConversationId() {
    var m = (window.location.hash || '').match(/[?&]c=(\d+)/);
    return m ? parseInt(m[1], 10) : null;
  }

  async function renderList(container) {
    var deepId = deepLinkedConversationId();
    if (deepId) {
      // Strip the parameter so a later re-render doesn't re-open it on top.
      try { history.replaceState(null, '', location.pathname + location.search + '#chat'); } catch (e) {}
      return openThread(deepId, container);   // openThread(cid, container)
    }

    container.innerHTML = '<div class="kt-chat-loading" style="text-align:center;padding:32px;color:#6B7280;">Loading conversations…</div>';
    try {
      // Families and colleagues in one list. Staff threads are a separate endpoint with
      // its own row shape, so they are normalised into the conversation shape the three
      // render paths below already understand. A failed staff fetch must not empty the
      // inbox — it degrades to families only.
      const both = await Promise.all([
        api('GET', endpointBase()),
        myRole === 'guardian' ? Promise.resolve(null)
          : api('GET', '/provider/team-threads').catch(function () { return null; }),
        // Archived is per person, so it travels with the list rather than being baked
        // into it. A failure here must not hide the inbox — it just shows everything.
        api('GET', '/provider/chat-archive').catch(function () { return null; }),
      ]);
      const archived = {
        family: ((both[2] && both[2].family) || []).map(String),
        staff: ((both[2] && both[2].staff) || []).map(String),
      };
      const isArchived = function (c) {
        return c.kind === 'staff'
          ? archived.staff.indexOf(String(c.id).replace('staff:', '')) !== -1
          : archived.family.indexOf(String(c.id)) !== -1;
      };
      const data = both[0] || {};
      const convs = (data.conversations || []).map(function (c) { c.kind = 'family'; return c; })
        .concat(((both[1] && both[1].threads) || []).map(function (t) {
          return {
            kind: 'staff',
            // Namespaced: conversation 7 and thread 7 are different things.
            id: 'staff:' + t.id,
            family_name: t.name,
            centre_name: t.name,
            photo_url: t.photo_url || '',
            preview: t.preview || '',
            last_message_at: t.at,
            unread_count: t.unread || 0,
          };
        }));
      const isProvider = myRole !== 'guardian';
      const newChatBtnHtml = isProvider
        ? `<button id="kt-new-chat-btn" style="background:#1F6080;color:white;border:none;padding:8px 14px;border-radius:8px;font-size:13px;font-weight:600;cursor:pointer;">+ New chat</button>`
        : '';
      // v22p16: notifications-enable button for any role that hasn't subscribed yet.
      const notifBtnHtml = `<button id="kt-notif-btn" style="background:white;color:#1F6080;border:1px solid #1F6080;padding:7px 12px;border-radius:8px;font-size:12px;font-weight:600;cursor:pointer;display:none;" title="Get an OS notification when a new message arrives, even when this tab is closed.">🔔 Enable notifications</button>`;
      if (convs.length === 0) {
        container.innerHTML = `
          <div class="kt-chat-header" style="padding:16px;border-bottom:1px solid #E5E7EB;background:white;display:flex;justify-content:space-between;align-items:center;">
            <h2 style="font-size:20px;margin:0;">💬 Messenger</h2>
            <div style="display:flex;gap:8px;align-items:center;">${notifBtnHtml}${newChatBtnHtml}</div>
          </div>
          <div style="text-align:center;padding:48px 16px;color:#6B7280;">
            <div style="font-size:48px;margin-bottom:12px;">💬</div>
            <p>No conversations yet.</p>
            ${isProvider ? '<p style="font-size:14px;margin-top:8px;">Click <strong>+ New chat</strong> above to message a family or a colleague.</p>' : '<p style="font-size:14px;margin-top:8px;">Your centre will reach out about your child\'s day.</p>'}
          </div>`;
        var b = $('#kt-new-chat-btn', container);
        if (b) b.addEventListener('click', function () { openNewChatModal(container); });
        wireNotifBtn(container);
        return;
      }
      const myId = getUser().id;
      const nameOf = (c) => (myRole === 'guardian' ? c.centre_name : c.family_name) || 'Conversation';
      // showArchived flips the list between the live inbox and the archived pile.
      const state = { sort: 'date', dir: -1, q: '', showArchived: false };
      const visible = () => convs.filter(c => isArchived(c) === state.showArchived);
      const archivedCount = () => convs.filter(isArchived).length;

      // A real photo when there is one, the coloured initial when there is not. The
      // thread view below has always done this; the list never did.
      const avatarFor = (nm, photo, size) => {
        const p = absPhotoUrl(photo);
        if (p && window.KT && KT.avatar) {
          return `<span style="flex-shrink:0;display:inline-flex;">${KT.avatar(nm || '?', { size: size, photoUrl: p })}</span>`;
        }
        return `<span style="width:${size}px;height:${size}px;border-radius:50%;background:${senderColor(nm)};color:#fff;display:inline-flex;align-items:center;justify-content:center;font-size:${Math.round(size * 0.38)}px;font-weight:800;flex-shrink:0;">${escapeHtml((String(nm || '?').charAt(0) || '?').toUpperCase())}</span>`;
      };

      const kebabBtn = (c) => `<button class="kt-conv-kebab" data-cid="${c.id}" type="button" title="More" aria-label="More actions" style="background:none;border:none;cursor:pointer;color:#64748B;font-size:17px;line-height:1;padding:5px 7px;border-radius:6px;">⋮</button>`;

      // MOBILE ONLY. The desktop table gets its kebab from kt-row-actions.js, which
      // collapses the last cell's buttons automatically — building one there produces a
      // kebab inside a kebab. The card list is not a table, so nothing collapses it and
      // this menu is the only way to reach these actions on a phone. See CONVENTIONS.md.
      function openKebab(anchor, c) {
        document.querySelectorAll('.kt-conv-menu').forEach(m => m.remove());
        const staff = c.kind === 'staff';
        const arch = isArchived(c);
        const menu = document.createElement('div');
        menu.className = 'kt-conv-menu';
        menu.style.cssText = 'position:absolute;z-index:12000;background:#fff;border:1px solid #E2E8F0;border-radius:10px;box-shadow:0 8px 26px rgba(15,23,42,.16);padding:5px;min-width:172px;';
        const item = (label, danger) => `<button class="kt-cm-item" data-act="${label.toLowerCase()}" style="display:block;width:100%;text-align:left;background:none;border:none;cursor:pointer;padding:9px 12px;border-radius:7px;font-size:13.5px;color:${danger ? '#C0453B' : '#0D1B2A'};">${label}</button>`;
        menu.innerHTML = item('Open')
          + item(arch ? 'Restore' : 'Archive')
          // No delete for staff threads: /provider/team-threads has no delete endpoint.
          + (staff ? '' : item('Delete', true));
        document.body.appendChild(menu);
        const r = anchor.getBoundingClientRect();
        menu.style.top = (window.scrollY + r.bottom + 6) + 'px';
        menu.style.left = (window.scrollX + Math.min(r.left, window.innerWidth - menu.offsetWidth - 12)) + 'px';

        menu.querySelectorAll('.kt-cm-item').forEach(b => {
          b.addEventListener('mouseenter', () => { b.style.background = '#F1F5F9'; });
          b.addEventListener('mouseleave', () => { b.style.background = 'none'; });
          b.addEventListener('click', async (e) => {
            e.stopPropagation();
            const act = b.getAttribute('data-act');
            menu.remove();
            if (act === 'open') { return openThread(c.id, container); }
            if (act === 'delete') { return deleteConv(c); }
            return setArchived(c, act === 'archive');
          });
        });
        setTimeout(() => {
          document.addEventListener('click', function away() { menu.remove(); document.removeEventListener('click', away); }, { once: true });
        }, 0);
      }

      async function setArchived(c, want) {
        const bucket = c.kind === 'staff' ? archived.staff : archived.family;
        const raw = String(c.id).replace('staff:', '');
        try {
          await api('POST', '/provider/chat-archive', { kind: c.kind === 'staff' ? 'staff' : 'family', id: parseInt(raw, 10), archived: want });
          if (want) { bucket.push(raw); } else { bucket.splice(bucket.indexOf(raw), 1); }
          repaint();
          if (window.KT && KT.toast) KT.toast(want ? '📥' : '↩️', want ? 'Archived' : 'Restored');
        } catch (err) {
          if (window.KT && KT.toast) KT.toast('⚠️', 'Could not update', (err && err.message) || '', '#DC2626');
        }
      }

      async function deleteConv(c) {
        if (!await KT.confirm('Delete this entire conversation? It is removed for everyone and can\'t be undone from the app.')) return;
        try {
          await api('DELETE', endpointBase() + '/' + c.id);
          const idx = convs.findIndex(x => String(x.id) === String(c.id));
          if (idx >= 0) convs.splice(idx, 1);
          repaint();
          if (window.KT && KT.toast) KT.toast('🗑', 'Conversation deleted');
        } catch (err) {
          if (window.KT && KT.toast) KT.toast('⚠️', 'Could not delete', (err && err.message) || '', '#DC2626');
        }
      }

      // Set by whichever render path runs below, so the menu can refresh the list
      // without caring which one is on screen.
      let repaint = function () {};

      // ── Mobile / APK: a card list matching the parent chat inbox ──────────
      // The desktop <table> below stacks its From/Message/Date columns into an
      // ugly label:value block on a phone; the parent inbox uses clean cards, so
      // the two chats looked like different products. Render cards ≤600px wide.
      if (window.innerWidth <= 600) {
        const sortValM = (c) => new Date(String(c.last_message_at || '').replace(' ', 'T')).getTime() || 0;
        container.innerHTML = `
          <div style="padding:12px 12px 4px;">
            <div style="display:flex;align-items:center;justify-content:space-between;gap:8px;margin-bottom:10px;">
              <h2 style="font-size:19px;font-weight:800;color:#0D1B2A;margin:0;">💬 Messenger</h2>
              <div style="display:flex;gap:6px;align-items:center;flex-wrap:wrap;justify-content:flex-end;">${notifBtnHtml}${newChatBtnHtml}</div>
            </div>
            <input id="kt-msg-filter" type="search" placeholder="🔍 Search messages…" style="width:100%;box-sizing:border-box;padding:10px 12px;border:1px solid #D1D5DB;border-radius:12px;font-size:14px;margin-bottom:10px;">
            <div id="kt-msg-cards"></div>
          </div>`;
        const cardsWrap = $('#kt-msg-cards', container);
        const cardHtml = (c) => {
          const nm = nameOf(c), unread = c.unread_count > 0;
          return `<div class="kt-msg-card" data-cid="${c.id}" style="width:100%;text-align:left;display:flex;align-items:center;gap:12px;border:1px solid #E7EBF0;background:#fff;cursor:pointer;padding:12px 13px;margin-bottom:9px;border-radius:14px;box-shadow:0 1px 3px rgba(15,23,42,.06);">
            ${avatarFor(nm, c.photo_url || c.child_photo_url, 44)}
            <span style="flex:1;min-width:0;">
              <span style="display:flex;align-items:center;gap:8px;">
                <span style="font-weight:${unread ? '800' : '700'};font-size:14.5px;color:#0D1B2A;flex:1;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">${escapeHtml(nm)}${c.child_name ? ` · <span style="color:#64748B;font-weight:600;">${escapeHtml(c.child_name)}</span>` : ''}</span>
                <span style="font-size:11px;color:#64748B;flex-shrink:0;">${formatDateTime(c.last_message_at)}</span>
              </span>
              <span style="display:flex;align-items:center;gap:6px;margin-top:3px;">
                <span style="flex:1;min-width:0;font-size:12.5px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;${unread ? 'color:#0D1B2A;font-weight:600;' : 'color:#64748B;'}">${c.last_sender_id == myId ? '<span style="color:#64748B;">You: </span>' : ''}${escapeHtml(c.preview || '(no messages yet)')}</span>
                ${unread ? `<span style="background:#8EC73C;color:#fff;font-size:11px;font-weight:800;min-width:20px;height:20px;padding:0 6px;border-radius:10px;display:flex;align-items:center;justify-content:center;flex-shrink:0;">${c.unread_count}</span>` : ''}
              </span>
            </span>
            ${kebabBtn(c)}
          </div>`;
        };
        const paintM = () => {
          const q = state.q.trim().toLowerCase();
          let listx = visible();
          if (q) listx = listx.filter(c => (nameOf(c) + ' ' + (c.child_name || '') + ' ' + (c.preview || '')).toLowerCase().indexOf(q) !== -1);
          listx.sort((a, b) => sortValM(b) - sortValM(a));
          const n = archivedCount();
          cardsWrap.innerHTML = (listx.length ? listx.map(cardHtml).join('')
            : `<div style="text-align:center;color:#64748B;padding:30px;font-size:13px;">${state.showArchived ? 'Nothing archived.' : 'No matching conversations.'}</div>`)
            + ((n || state.showArchived) ? `<button id="kt-arch-toggle" style="width:100%;margin-top:4px;background:none;border:none;color:#1F6080;font-size:13px;font-weight:700;cursor:pointer;padding:10px;">${state.showArchived ? '← Back to inbox' : '📥 Archived (' + n + ')'}</button>` : '');
          cardsWrap.querySelectorAll('.kt-msg-card').forEach(row => row.addEventListener('click', () => openThread(row.dataset.cid, container)));
          cardsWrap.querySelectorAll('.kt-conv-kebab').forEach(b => b.addEventListener('click', (e) => {
            e.stopPropagation();
            const c = convs.find(x => String(x.id) === String(b.getAttribute('data-cid')));
            if (c) openKebab(b, c);
          }));
          const at = cardsWrap.querySelector('#kt-arch-toggle');
          if (at) at.addEventListener('click', () => { state.showArchived = !state.showArchived; paintM(); });
        };
        repaint = paintM;
        const fiM = $('#kt-msg-filter', container);
        if (fiM) fiM.addEventListener('input', () => { state.q = fiM.value || ''; paintM(); });
        paintM();
        const nbM = $('#kt-new-chat-btn', container);
        if (nbM) nbM.addEventListener('click', () => openNewChatModal(container));
        wireNotifBtn(container);
        return;
      }

      const th = (key, label, extra) => `<th data-sort="${key}" class="kt-msg-th" style="text-align:${extra && extra.right ? 'right' : 'left'};padding:10px 14px;font-size:12px;color:#6B7280;font-weight:600;cursor:pointer;user-select:none;${extra && extra.w ? 'width:' + extra.w + ';' : ''}">${label} <span class="kt-ar" style="color:#64748B;"></span></th>`;
      container.innerHTML = `
        <div style="display:flex;flex-direction:column;">
          <div class="kt-chat-header" style="padding:14px 16px;border-bottom:1px solid #E5E7EB;background:white;display:flex;justify-content:space-between;align-items:center;gap:10px;flex-wrap:wrap;">
            <h2 style="font-size:20px;margin:0;">💬 Messenger</h2>
            <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
              <input id="kt-msg-filter" type="search" placeholder="🔍 Filter…" style="padding:8px 11px;border:1px solid #D1D5DB;border-radius:8px;font-size:13px;min-width:180px;">
              ${notifBtnHtml}${newChatBtnHtml}
            </div>
          </div>
          <div style="background:#fff;">
            <table data-kt-filtered="1" data-kt-noexport="1" style="width:100%;border-collapse:collapse;font-size:14px;table-layout:fixed;">
              <thead>
                <tr style="position:sticky;top:0;z-index:1;background:#F9FAFB;box-shadow:inset 0 -1px 0 #E5E7EB;">
                  ${th('name', 'From', { w: '250px' })}
                  ${th('preview', 'Message')}
                  ${th('date', 'Date', { w: '140px', right: true })}
                  <th style="width:46px;padding:10px 6px;"></th>
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
              ${avatarFor(nm, c.photo_url || c.child_photo_url, 30)}
              <span style="font-weight:${unread ? '800' : '600'};color:#111827;overflow:hidden;text-overflow:ellipsis;">${escapeHtml(nm)}${c.child_name ? ` <span style="color:#64748B;font-weight:400;">· ${escapeHtml(c.child_name)}</span>` : ''}</span>
            </span>
          </td>
          <td style="padding:10px 14px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;color:#6B7280;font-weight:${unread ? '600' : '400'};">
            ${c.last_sender_id == myId ? '<span style="color:#64748B;">You: </span>' : ''}${escapeHtml(c.preview || '(no messages yet)')}
            ${unread ? `<span style="background:#1F6080;color:#fff;font-size:11px;font-weight:700;padding:1px 7px;border-radius:10px;margin-left:6px;">${c.unread_count}</span>` : ''}
          </td>
          <td style="padding:10px 14px;text-align:right;color:#64748B;white-space:nowrap;font-weight:${unread ? '700' : '400'};">${formatDateTime(c.last_message_at)}</td>
          <td style="padding:10px 6px;text-align:center;white-space:nowrap;">
            <button class="kt-conv-act kt-conv-open" data-cid="${c.id}" type="button" title="Open">Open</button>
            <button class="kt-conv-act kt-conv-arch" data-cid="${c.id}" type="button" title="${isArchived(c) ? 'Restore' : 'Archive'}">${isArchived(c) ? 'Restore' : 'Archive'}</button>
            ${c.kind === 'staff' ? '' : `<button class="kt-conv-act kt-conv-del" data-cid="${c.id}" type="button" title="Delete">Delete</button>`}
          </td>
        </tr>`;
      };
      const paint = () => {
        const q = state.q.trim().toLowerCase();
        let list = visible();
        if (q) list = list.filter(c => (nameOf(c) + ' ' + (c.child_name || '') + ' ' + (c.preview || '')).toLowerCase().indexOf(q) !== -1);
        list.sort((a, b) => { const va = sortVal(a), vb = sortVal(b); return (va < vb ? -1 : va > vb ? 1 : 0) * state.dir; });
        const n = archivedCount();
        tbody.innerHTML = (list.length ? list.map(rowHtml).join('')
          : `<tr><td colspan="4" style="padding:26px;text-align:center;color:#64748B;">${state.showArchived ? 'Nothing archived.' : 'No matching conversations.'}</td></tr>`)
          + ((n || state.showArchived) ? `<tr><td colspan="4" style="padding:10px;text-align:center;"><button id="kt-arch-toggle" style="background:none;border:none;color:#1F6080;font-size:13px;font-weight:700;cursor:pointer;padding:6px 10px;">${state.showArchived ? '← Back to inbox' : '📥 Archived (' + n + ')'}</button></td></tr>` : '');
        let z = 0;
        tbody.querySelectorAll('.kt-msg-row').forEach(row => {
          const base = (z++ % 2) ? '#F7F9FB' : '#FFFFFF';
          row.dataset.base = base; row.style.background = base;
          row.addEventListener('mouseenter', () => { row.style.background = '#EEF4F7'; });
          row.addEventListener('mouseleave', () => { row.style.background = row.dataset.base; });
          row.addEventListener('click', () => openThread(row.dataset.cid, container));
        });
        // Wired straight onto the buttons. kt-row-actions.js forwards a real click
        // from its menu item to the hidden button, so these fire either way.
        const convOf = (b) => convs.find(x => String(x.id) === String(b.getAttribute('data-cid')));
        tbody.querySelectorAll('.kt-conv-open').forEach(b => b.addEventListener('click', (e) => {
          e.stopPropagation(); const c = convOf(b); if (c) openThread(c.id, container);
        }));
        tbody.querySelectorAll('.kt-conv-arch').forEach(b => b.addEventListener('click', (e) => {
          e.stopPropagation(); const c = convOf(b); if (c) setArchived(c, !isArchived(c));
        }));
        tbody.querySelectorAll('.kt-conv-del').forEach(b => b.addEventListener('click', (e) => {
          e.stopPropagation(); const c = convOf(b); if (c) deleteConv(c);
        }));
        const at = tbody.querySelector('#kt-arch-toggle');
        if (at) at.addEventListener('click', (e) => { e.stopPropagation(); state.showArchived = !state.showArchived; paint(); });
        container.querySelectorAll('.kt-msg-th').forEach(h => { const ar = h.querySelector('.kt-ar'); if (ar) ar.textContent = (h.getAttribute('data-sort') === state.sort) ? (state.dir < 0 ? '▾' : '▴') : ''; });
      };
      container.querySelectorAll('.kt-msg-th').forEach(h => h.addEventListener('click', () => {
        const k = h.getAttribute('data-sort');
        if (state.sort === k) state.dir = -state.dir; else { state.sort = k; state.dir = (k === 'date') ? -1 : 1; }
        paint();
      }));
      const fi = $('#kt-msg-filter', container);
      if (fi) fi.addEventListener('input', () => { state.q = fi.value || ''; paint(); });
      repaint = paint;
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
          if (!await KT.confirm('Turn off browser notifications? You will only see new messages when the dashboard tab is open.')) return;
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

    // Pick the right families endpoint based on role. platform_admin was missing and
    // fell through to "not available for your role".
    var famPath = (myRole === 'agency_admin' || myRole === 'platform_admin') ? '/admin/families'
                : (myRole === 'centre_director' || myRole === 'educator' || myRole === 'home_visitor') ? '/director/families'
                : null;

    // Colleagues too. This dialog only ever offered families, so a director or admin was
    // unreachable from Messages — the complaint that started this. Staff threads are a
    // different backend (keyed on a user, not a family), so both lists are loaded here and
    // the send routes to whichever the chosen row belongs to.
    var families = [], staff = [];
    var loaded = await Promise.all([
      famPath ? api('GET', famPath).catch(function () { return null; }) : Promise.resolve(null),
      api('GET', '/provider/team-contacts').catch(function () { return null; }),
    ]);
    if (loaded[0]) { families = loaded[0].data || loaded[0].families || []; }
    if (loaded[1]) { staff = loaded[1].contacts || []; }

    if (! families.length && ! staff.length) {
      modal.querySelector('#kt-new-chat-form-body').innerHTML = '<div style="color:#6B7280;">Nobody to message yet.</div>';
      return;
    }

    modal.querySelector('#kt-new-chat-form-body').innerHTML =
      '<div style="margin-bottom:14px;">' +
        '<label style="display:block;font-size:13px;font-weight:600;color:#374151;margin-bottom:4px;">To</label>' +
        '<select id="kt-nc-family" style="width:100%;padding:8px 10px;border:1px solid #D1D5DB;border-radius:6px;font-size:14px;background:white;">' +
          // Colleagues first, grouped by role, each labelled — a flat list mixing parents
          // and directors cannot be read at a glance. The value carries the KIND because
          // the two halves post to different endpoints.
          (function () {
            var order = ['Admin', 'Director', 'Home visitor', 'Educator', 'Auditor', 'Parent', 'Staff'];
            var byRole = {};
            staff.forEach(function (p) {
              var r = p.role || 'Staff';
              (byRole[r] = byRole[r] || []).push(p);
            });
            var groups = order.filter(function (r) { return byRole[r] && byRole[r].length; })
              .concat(Object.keys(byRole).filter(function (r) { return order.indexOf(r) === -1; }));
            return groups.map(function (role) {
              return '<optgroup label="' + escapeHtml(role === 'Admin' ? 'Admins' : role + 's') + '">' +
                byRole[role].sort(function (a, b) { return String(a.name).localeCompare(String(b.name)); })
                  .map(function (p) {
                    return '<option value="user:' + p.id + '">' + escapeHtml(p.name) + ' (' + escapeHtml(role) + ')</option>';
                  }).join('') + '</optgroup>';
            }).join('');
          })() +
          (families.length
            ? '<optgroup label="Families">' + families.map(function (f) {
                return '<option value="family:' + f.id + '">' + escapeHtml(f.family_name || ('Family #' + f.id)) + ' (Family)</option>';
              }).join('') + '</optgroup>'
            : '') +
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
      // Values are still prefixed "family:" so the picker can grow back to carrying
      // staff without the id meaning two different things.
      var picked = String(modal.querySelector('#kt-nc-family').value || '');
      var toStaff = picked.indexOf('user:') === 0;
      var fid = parseInt(picked.replace(/^(user|family):/, ''), 10);
      var subj = modal.querySelector('#kt-nc-subject').value.trim();
      var body = modal.querySelector('#kt-nc-body').value.trim();
      var errBox = modal.querySelector('#kt-nc-err');
      errBox.textContent = '';
      if (!body) { errBox.textContent = 'Please type a message.'; return; }
      var sendBtn = modal.querySelector('#kt-nc-send');
      sendBtn.disabled = true; sendBtn.textContent = 'Sending…';
      try {
        var resp = toStaff
          ? await api('POST', '/provider/team-threads/start', { recipient_user_id: fid, body: body })
          : await api('POST', '/provider/chats/start', { family_id: fid, subject: subj || null, body: body });
        overlay.remove();
        if (toStaff && resp && (resp.thread_id || resp.id)) {
          openThread('staff:' + (resp.thread_id || resp.id), container);
        } else if (!toStaff && resp && resp.conversation_id) {
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

  /* ─── Colleague thread ──────────────────────────────────────────
     Deliberately thinner than the family thread below: /provider/team-threads has no
     reactions, no attachments and no delete, so this offers none of them. An affordance
     that 404s is worse than an absent one. */
  var staffPoll = null;
  function stopStaffPoll() { if (staffPoll) { clearInterval(staffPoll); staffPoll = null; } }

  async function openStaffThread(tid, container) {
    openThreadId = 'staff:' + tid;
    threadListContainer = container;
    threadDocked = !!(KT.ChatDock && KT.ChatDock.enabled());
    var target = threadDocked ? KT.ChatDock.contentEl() : container;
    target.innerHTML = '<div style="text-align:center;padding:32px;color:#6B7280;">Loading…</div>';
    var cleanup = function () { stopStaffPoll(); closeThreadCleanup(); };
    if (threadDocked) {
      KT.ChatDock.show('Chat', cleanup);
      if (KT.ChatDock.rememberThread) { KT.ChatDock.rememberThread('staff:' + tid, 'Chat'); }
    }

    var lastCount = -1;

    function bubble(m) {
      var mine = !!m.mine;
      // Their photo beside their bubble, matching the family thread. Own messages get
      // none — you know who you are.
      var pic = '';
      if (!mine) {
        var url = absPhotoUrl(m.photo);
        pic = (url && window.KT && KT.avatar)
          ? '<span style="flex-shrink:0;align-self:flex-end;display:inline-flex;margin-right:8px;">' + KT.avatar(m.sender || '?', { size: 30, photoUrl: url }) + '</span>'
          : '<span style="flex-shrink:0;align-self:flex-end;margin-right:8px;width:30px;height:30px;border-radius:50%;background:' + senderColor(m.sender) + ';color:#fff;font-size:12px;font-weight:800;display:inline-flex;align-items:center;justify-content:center;">' + escapeHtml((String(m.sender || '?').charAt(0) || '?').toUpperCase()) + '</span>';
      }
      return '<div style="display:flex;align-items:flex-end;justify-content:' + (mine ? 'flex-end' : 'flex-start') + ';margin-bottom:10px;">' +
        pic +
        '<div style="max-width:78%;">' +
          (mine ? '' : '<div style="font-size:11px;color:#64748B;font-weight:700;margin:0 0 3px 4px;">' + escapeHtml(m.sender || '') + '</div>') +
          '<div style="background:' + (mine ? '#1F6080' : '#FFFFFF') + ';color:' + (mine ? '#FFFFFF' : '#0D1B2A') + ';' +
            'border:1px solid ' + (mine ? '#1F6080' : '#E7EBF0') + ';border-radius:14px;padding:9px 12px;font-size:14px;line-height:1.5;white-space:pre-wrap;word-break:break-word;">' +
            escapeHtml(m.body || '') +
            (function () {
              // A photo shows as a photo and a voice note as a player. The server has
              // already normalised a browser voice note's video/* container to audio/*,
              // so mime can be trusted here.
              var atts = m.attachments || [];
              return atts.map(function (a) {
                var url = absPhotoUrl(a.url);
                if (/^image\//.test(a.mime || '')) {
                  return '<div style="margin-top:6px;"><img src="' + escapeHtml(url) + '" alt="' + escapeHtml(a.name || 'Photo') + '" style="max-width:100%;border-radius:9px;display:block;cursor:zoom-in;"></div>';
                }
                if (/^audio\//.test(a.mime || '')) {
                  return '<div style="margin-top:6px;"><audio controls preload="none" src="' + escapeHtml(url) + '" style="width:210px;max-width:100%;"></audio></div>';
                }
                return '<div style="margin-top:6px;"><a href="' + escapeHtml(url) + '" target="_blank" rel="noopener" style="color:inherit;text-decoration:underline;">📎 ' + escapeHtml(a.name || 'Attachment') + '</a></div>';
              }).join('');
            })() + '</div>' +
          '<div style="font-size:10.5px;color:#94A3B8;margin:3px 4px 0;text-align:' + (mine ? 'right' : 'left') + ';">' + formatDateTime(m.at) + '</div>' +
        '</div></div>';
    }

    async function paint(force) {
      var data;
      try { data = await api('GET', '/provider/team-threads/' + tid); }
      catch (e) {
        stopStaffPoll();
        target.innerHTML = '<div style="padding:24px;color:#DC2626;">Could not load this conversation: ' + escapeHtml(e.message) + '</div>';
        return;
      }
      var msgs = data.messages || [];
      // Only repaint when something actually arrived — a blind repaint every few seconds
      // would wipe whatever the person is part-way through typing.
      if (!force && msgs.length === lastCount) { return; }
      var draft = '';
      var oldBox = target.querySelector('#kt-st-input');
      if (oldBox) { draft = oldBox.value; }
      lastCount = msgs.length;
      if (threadDocked) KT.ChatDock.show(data.name || 'Chat', cleanup);

      target.innerHTML =
        '<div style="display:flex;flex-direction:column;height:100%;min-height:0;background:#F7F9FB;">' +
          (threadDocked ? '' :
            '<div class="kt-thread-header" style="display:flex;align-items:center;gap:10px;padding:12px 14px;background:#fff;border-bottom:1px solid #E5E7EB;flex:0 0 auto;">' +
              '<button id="kt-st-back" style="background:none;border:none;font-size:24px;color:#1F6080;cursor:pointer;padding:0 4px;line-height:1;">‹</button>' +
              '<strong style="font-size:16px;color:#0D1B2A;">' + escapeHtml(data.name || 'Colleague') + '</strong>' +
            '</div>') +
          '<div id="kt-st-body" style="flex:1 1 auto;min-height:0;overflow-y:auto;padding:14px;">' +
            (msgs.length ? msgs.map(bubble).join('')
              : '<div style="text-align:center;color:#64748B;padding:26px;font-size:13px;">No messages yet. Say hello.</div>') +
          '</div>' +
          '<div style="flex:0 0 auto;position:relative;background:#fff;border-top:1px solid #E5E7EB;">' +
            '<div id="kt-st-pending" style="display:none;align-items:center;gap:8px;padding:8px 10px 0;font-size:12.5px;color:#475569;"></div>' +
            '<div id="kt-st-emoji" style="display:none;position:absolute;bottom:56px;left:8px;background:#fff;border:1px solid #E5E7EB;border-radius:12px;box-shadow:0 8px 24px rgba(0,0,0,.16);padding:8px;width:264px;max-height:170px;overflow-y:auto;z-index:40;font-size:22px;line-height:1.5;"></div>' +
            '<div style="display:flex;align-items:flex-end;gap:6px;padding:8px 10px 10px;">' +
              '<button id="kt-st-emoji-btn" type="button" title="Emoji" style="flex:0 0 auto;background:transparent;border:none;cursor:pointer;font-size:21px;padding:3px 5px;">😊</button>' +
              '<button id="kt-st-photo-btn" type="button" title="Send a photo" style="flex:0 0 auto;background:transparent;border:none;cursor:pointer;font-size:20px;padding:3px 5px;">📷</button>' +
              '<button id="kt-st-mic-btn" type="button" title="Record a voice note" style="flex:0 0 auto;background:transparent;border:none;cursor:pointer;font-size:20px;padding:3px 5px;">🎤</button>' +
              '<input id="kt-st-file" type="file" accept="image/*" style="display:none;">' +
              '<textarea id="kt-st-input" rows="1" placeholder="Type a message…" style="flex:1;min-width:0;padding:9px 11px;border:1px solid #D1D5DB;border-radius:10px;font-size:14px;font-family:inherit;resize:none;max-height:110px;box-sizing:border-box;"></textarea>' +
              '<button id="kt-st-send" style="flex:0 0 auto;background:#1F6080;color:#fff;border:none;padding:0 16px;height:38px;border-radius:10px;font-size:14px;font-weight:700;cursor:pointer;">Send</button>' +
            '</div>' +
          '</div>' +
        '</div>';

      var body = target.querySelector('#kt-st-body');
      if (body) { body.scrollTop = body.scrollHeight; }
      var input = target.querySelector('#kt-st-input');
      if (input) { input.value = draft; }

      var back = target.querySelector('#kt-st-back');
      if (back) { back.addEventListener('click', function () { stopStaffPoll(); closeThreadCleanup(); }); }

      // ── emoji ─────────────────────────────────────────────────────────
      var EMOJI = ['😀','😄','😊','🙂','😉','😍','🥳','😅','🤔','😴','🙌','👍','👏','🙏','💪','🤝',
                   '❤️','🔥','✨','⭐','🎉','☀️','🌈','✅','❗','❓','📌','📎','🕗','🍎','🧸','👶'];
      var emojiPanel = target.querySelector('#kt-st-emoji');
      var emojiBtn = target.querySelector('#kt-st-emoji-btn');
      emojiPanel.innerHTML = EMOJI.map(function (e) {
        return '<button type="button" data-em="' + e + '" style="background:none;border:none;font-size:21px;cursor:pointer;padding:2px 4px;border-radius:7px;line-height:1;">' + e + '</button>';
      }).join('');
      emojiBtn.addEventListener('click', function (e) {
        e.stopPropagation();
        emojiPanel.style.display = emojiPanel.style.display === 'none' ? 'block' : 'none';
      });
      emojiPanel.addEventListener('click', function (e) {
        var b = e.target.closest('[data-em]');
        if (!b) { return; }
        input.value = (input.value || '') + b.getAttribute('data-em');
        input.focus();
      });

      // ── the file waiting to go, photo or voice note ───────────────────────
      var pendingFile = null;
      var pendingBox = target.querySelector('#kt-st-pending');
      function setPending(f, label) {
        pendingFile = f;
        if (!f) { pendingBox.style.display = 'none'; pendingBox.innerHTML = ''; return; }
        pendingBox.style.display = 'flex';
        pendingBox.innerHTML = '<span>' + escapeHtml(label || f.name || 'Attachment') + '</span>'
          + '<button type="button" id="kt-st-clear" style="background:none;border:none;color:#C0453B;cursor:pointer;font-size:13px;font-weight:700;">Remove</button>';
        pendingBox.querySelector('#kt-st-clear').addEventListener('click', function () { setPending(null); });
      }

      var fileInput = target.querySelector('#kt-st-file');
      target.querySelector('#kt-st-photo-btn').addEventListener('click', function () { fileInput.click(); });
      fileInput.addEventListener('change', function () {
        if (fileInput.files && fileInput.files[0]) { setPending(fileInput.files[0], '📷 ' + fileInput.files[0].name); }
      });

      // ── voice notes ───────────────────────────────────────────────────────
      var micBtn = target.querySelector('#kt-st-mic-btn');
      var recorder = null, chunks = [];
      micBtn.addEventListener('click', async function () {
        if (recorder && recorder.state === 'recording') {
          recorder.stop();
          return;
        }
        if (!navigator.mediaDevices || !window.MediaRecorder) {
          if (window.KT && KT.toast) { KT.toast('⚠️', 'Recording is not available on this device'); }
          return;
        }
        try {
          var stream = await navigator.mediaDevices.getUserMedia({ audio: true });
          chunks = [];
          recorder = new MediaRecorder(stream);
          recorder.ondataavailable = function (e) { if (e.data && e.data.size) { chunks.push(e.data); } };
          recorder.onstop = function () {
            // Always release the microphone — leaving the track live keeps the
            // browser's recording indicator on long after the thread is closed.
            try { stream.getTracks().forEach(function (t) { t.stop(); }); } catch (e2) {}
            micBtn.textContent = '🎤';
            var blob = new Blob(chunks, { type: 'audio/webm' });
            if (blob.size) { setPending(new File([blob], 'voice-note.webm', { type: 'audio/webm' }), '🎤 Voice note'); }
          };
          recorder.start();
          micBtn.textContent = '⏹️';
        } catch (e) {
          if (window.KT && KT.toast) { KT.toast('⚠️', 'Microphone blocked', 'Allow microphone access to record.', '#DC2626'); }
        }
      });

      var sendBtn = target.querySelector('#kt-st-send');
      async function doSend() {
        var text = (input.value || '').trim();
        // An attachment on its own is a message — a photo of the rota needs no caption.
        if (!text && !pendingFile) { return; }
        sendBtn.disabled = true;
        try {
          if (pendingFile) {
            // Multipart, not api(): that helper sets a JSON content-type, which would
            // corrupt a FormData body.
            var fd = new FormData();
            fd.append('body', text);
            fd.append('attachment', pendingFile);
            var res = await fetch(apiBase() + '/provider/team-threads/' + tid + '/send', {
              method: 'POST',
              headers: { 'Authorization': 'Bearer ' + token(), 'Accept': 'application/json' },
              body: fd,
            });
            if (!res.ok) {
              var err = await res.json().catch(function () { return {}; });
              throw new Error(err.message || ('Upload failed (HTTP ' + res.status + ')'));
            }
          } else {
            await api('POST', '/provider/team-threads/' + tid + '/send', { body: text });
          }
          input.value = '';
          setPending(null);
          lastCount = -1;          // force the next paint to show it
          await paint(true);
        } catch (e) {
          sendBtn.disabled = false;
          if (window.KT && KT.toast) { KT.toast('⚠️', 'Could not send', (e && e.message) || '', '#DC2626'); }
        }
      }
      sendBtn.addEventListener('click', doSend);
      input.addEventListener('keydown', function (e) {
        if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); doSend(); }
      });
    }

    await paint(true);
    stopStaffPoll();
    staffPoll = setInterval(function () { paint(false); }, 6000);
  }

  /* ─── Thread view ───────────────────────────────────────────── */
  let threadDocked = false;      // desktop: the open thread lives in the floating dock
  let threadListContainer = null; // the #appMain list container, for closing back to it
  /* Re-opening after a reload: the dock remembers WHICH thread was open, and asks this
     screen to open it again for real. A detached container is passed because the dock is
     the render target on desktop — the container is only used by the non-docked path. */
  (function registerDockOpener() {
    if (!(window.KT && KT.ChatDock && KT.ChatDock.setOpener)) { return; }
    KT.ChatDock.setOpener(function (key) {
      var host = document.getElementById('appMain') || document.createElement('div');
      return openThread(key, host);
    });
  })();

  async function openThread(cid, container) {
    // "staff:12" goes to the colleague thread; anything else is a family conversation.
    if (String(cid).indexOf('staff:') === 0) {
      return openStaffThread(parseInt(String(cid).slice(6), 10), container);
    }
    openThreadId = cid;
    threadListContainer = container;
    threadDocked = !!(KT.ChatDock && KT.ChatDock.enabled());
    var target = threadDocked ? KT.ChatDock.contentEl() : container;
    target.innerHTML = '<div style="text-align:center;padding:32px;color:#6B7280;">Loading…</div>';
    if (threadDocked) {
      KT.ChatDock.show('Chat', closeThreadCleanup);
      if (KT.ChatDock.rememberThread) { KT.ChatDock.rememberThread(cid, 'Chat'); }
    }
    try {
      const data = await api('GET', endpointBase() + '/' + cid);
      renderThread(data, target);
      if (threadDocked) {
        var c0 = data.conversation || {};
        KT.ChatDock.show((myRole === 'guardian' ? c0.centre_name : c0.family_name) || 'Chat', closeThreadCleanup);
      }
      // Refresh badge after opening (now zero unread)
      if (window.KT && window.KT.refreshUnreadBadge) window.KT.refreshUnreadBadge();
    } catch (e) {
      target.innerHTML = '<div style="padding:24px;color:#DC2626;">Could not load thread: ' + escapeHtml(e.message) + '</div>';
    }
  }

  // Stop the open thread's poll + clear state. The dock's × runs this via onClose;
  // it must NOT re-close the dock (the dock is already closing) to avoid recursion.
  function closeThreadCleanup() {
    openThreadId = null;
    if (threadPollTimer) { clearInterval(threadPollTimer); threadPollTimer = null; }
    threadDocked = false;
  }

  function renderThread(data, container) {
    const c = data.conversation || {};
    const messages = data.messages || [];
    const headerLabel = myRole === 'guardian' ? c.centre_name : c.family_name;
    const subLabel = c.child_name ? '· ' + c.child_name : '';

    container.innerHTML = `
      <div style="display:flex;flex-direction:column;height:calc(100vh - 110px);max-height:calc(100vh - 110px);background:#F9FAFB;">
        <div class="kt-thread-header" style="padding:14px 16px;border-bottom:1px solid rgba(31,96,128,.12);background:linear-gradient(135deg,#EAF3FB,#F3F0FF);display:flex;align-items:center;gap:10px;flex-shrink:0;">
          <button class="kt-back" style="background:transparent;border:none;font-size:22px;cursor:pointer;color:#1F6080;padding:4px 4px;flex-shrink:0;">←</button>
          ${(window.KT && KT.avatar && c.child_name) ? `<span style="flex-shrink:0;display:inline-flex;">${KT.avatar(c.child_name, { size: 38, photoUrl: c.child_photo_url || '' })}</span>` : ''}
          <div style="flex:1;min-width:0;">
            <div style="font-weight:700;font-size:16px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">${escapeHtml(headerLabel)} ${escapeHtml(subLabel)}</div>
            ${c.subject ? `<div style="font-size:13px;color:#6B7280;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">${escapeHtml(c.subject)}</div>` : ''}
          </div>
          ${myRole !== 'guardian' ? `<button class="kt-nudge" type="button" title="Nudge the family for a reply" style="background:rgba(31,96,128,.12);border:none;width:40px;height:40px;border-radius:50%;font-size:19px;cursor:pointer;flex-shrink:0;line-height:1;">👋</button>` : ''}
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
          <!-- Mic sits with emoji + camera on the left (transparent, no teal); it's
               shown while the box is empty and swaps to the send arrow when typing. -->
          <button class="kt-mic-btn" type="button" title="Record a voice note" style="background:transparent;border:none;cursor:pointer;font-size:22px;padding:4px 6px;">🎤</button>
          <input class="kt-compose-input" type="text" placeholder="Type a message…" style="flex:1;min-width:0;padding:12px 14px;border:1px solid #D1D5DB;border-radius:24px;font-size:15px;font-family:inherit;" />
          <button class="kt-send-btn" title="Send" style="display:none;align-items:center;justify-content:center;background:#159FB4;color:white;border:none;width:44px;height:44px;border-radius:50%;font-weight:700;cursor:pointer;font-size:16px;line-height:1;padding-left:2px;">➤</button>
          <div class="kt-emoji-panel" style="display:none;position:absolute;bottom:58px;left:8px;background:white;border:1px solid #E5E7EB;border-radius:12px;box-shadow:0 8px 24px rgba(0,0,0,.16);padding:8px;width:280px;max-height:180px;overflow-y:auto;z-index:40;font-size:23px;line-height:1.5;"></div>
        </div>
      </div>
    `;
    const body = $('.kt-thread-body', container);
    body.scrollTop = body.scrollHeight;

    // Delete own messages — delegated so it also catches poll-appended bubbles.
    body.addEventListener('click', async function (e) {
      var db = e.target && e.target.closest && e.target.closest('.kt-msg-del');
      if (!db) return;
      var mid = db.getAttribute('data-del-mid');
      if (!mid || !await KT.confirm('Delete this message?')) return;
      db.disabled = true;
      api('DELETE', endpointBase() + '/' + c.id + '/messages/' + mid).then(function () {
        var row = body.querySelector('[data-mid="' + mid + '"]');
        if (row) row.outerHTML = '<div data-mid="' + mid + '" style="display:flex;justify-content:flex-end;gap:8px;align-items:flex-end;"><div style="max-width:78%;padding:9px 13px;border-radius:16px;background:#EEF1F5;color:#64748B;font-style:italic;font-size:13.5px;">🚫 Message deleted</div></div>';
      }).catch(function (err) {
        db.disabled = false;
        if (window.KT && KT.toast) KT.toast('⚠️', 'Could not delete', (err && err.message) || '', '#DC2626'); else alert('Could not delete the message.');
      });
    });

    // React to a message: tapping an existing chip toggles that reaction; 😊 opens
    // a quick picker. Both POST to the react endpoint and re-render the chips.
    var REACT_EMOJI = ['👍', '❤️', '😂', '😮', '😢', '🙏'];
    function applyReactions(mid, reactions) {
      var row = body.querySelector('[data-mid="' + mid + '"]');
      if (!row) return;
      var html = (reactions && reactions.length) ? reactions.map(function (rx) {
        return '<button type="button" class="kt-msg-react-chip" data-react-mid="' + mid + '" data-emoji="' + escapeHtml(rx.emoji) + '" style="border:1px solid ' + (rx.mine ? '#1F6080' : '#E2E8F0') + ';background:' + (rx.mine ? '#EFF6FF' : '#fff') + ';border-radius:12px;padding:1px 7px;font-size:12.5px;cursor:pointer;line-height:1.6;">' + escapeHtml(rx.emoji) + ' ' + rx.count + '</button>';
      }).join('') : '';
      var existing = row.querySelector('.kt-msg-reactions');
      if (existing) { existing.innerHTML = html; existing.style.display = html ? 'flex' : 'none'; return; }
      if (!html) return;
      var bubble = row.querySelector('div[style*="border-radius:16px"]');
      if (!bubble) return;
      var wrap = document.createElement('div');
      wrap.className = 'kt-msg-reactions';
      wrap.style.cssText = 'display:flex;flex-wrap:wrap;gap:4px;margin-top:5px;';
      wrap.innerHTML = html;
      bubble.appendChild(wrap);
    }
    function toggleReaction(mid, emoji) {
      api('POST', endpointBase() + '/' + c.id + '/messages/' + mid + '/react', { emoji: emoji })
        .then(function (r) { applyReactions(mid, r && r.reactions); })
        .catch(function (err) { if (window.KT && KT.toast) KT.toast('⚠️', 'Could not react', (err && err.message) || '', '#DC2626'); });
    }
    body.addEventListener('click', function (e) {
      if (!e.target || !e.target.closest) return;
      var chip = e.target.closest('.kt-msg-react-chip');
      if (chip) { toggleReaction(chip.getAttribute('data-react-mid'), chip.getAttribute('data-emoji')); return; }
      var rb = e.target.closest('.kt-msg-react');
      if (!rb) return;
      var openPop = document.querySelector('.kt-msg-react-pop'); if (openPop) openPop.remove();
      var mid = rb.getAttribute('data-react-mid');
      var pop = document.createElement('div');
      pop.className = 'kt-msg-react-pop';
      pop.style.cssText = 'position:fixed;background:#fff;border:1px solid #E5E7EB;border-radius:20px;box-shadow:0 6px 20px rgba(0,0,0,.16);padding:4px 6px;display:flex;gap:2px;z-index:100000;';
      pop.innerHTML = REACT_EMOJI.map(function (em) {
        return '<button type="button" class="kt-react-pick" data-emoji="' + em + '" style="background:none;border:none;font-size:20px;cursor:pointer;padding:2px 5px;border-radius:8px;line-height:1;">' + em + '</button>';
      }).join('');
      var rect = rb.getBoundingClientRect();
      pop.style.left = Math.max(6, Math.min(rect.left - 40, window.innerWidth - 230)) + 'px';
      pop.style.top = Math.max(6, rect.top - 46) + 'px';
      pop.querySelectorAll('.kt-react-pick').forEach(function (pb) {
        pb.addEventListener('click', function () { toggleReaction(mid, pb.getAttribute('data-emoji')); pop.remove(); });
      });
      document.body.appendChild(pop);
      setTimeout(function () {
        document.addEventListener('click', function h(ev) { if (!pop.contains(ev.target) && ev.target !== rb) { pop.remove(); document.removeEventListener('click', h); } });
      }, 0);
    });

    function leaveThread() {
      if (threadDocked && KT.ChatDock) { KT.ChatDock.close(); return; }  // close() → onClose → closeThreadCleanup
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

    // Nudge — one-tap "please check your messages" ping to the family (the
    // provider mirror of the parent's 👋 nudge). The family's guardians get an
    // urgent full-screen takeover notification, same as any chat message.
    const nudgeBtn = $('.kt-nudge', container);
    if (nudgeBtn) {
      nudgeBtn.addEventListener('click', async () => {
        nudgeBtn.disabled = true;
        try {
          await api('POST', endpointBase() + '/' + c.id + '/nudge');
          if (window.KT && KT.toast) KT.toast('👋', 'Nudge sent', 'The family will get a notification.', '#159FB4');
          // Show it in the open thread immediately.
          const bodyEl = $('.kt-thread-body', container);
          if (bodyEl) { bodyEl.scrollTop = bodyEl.scrollHeight; }
        } catch (e) {
          if (window.KT && KT.toast) KT.toast('⚠️', 'Could not send the nudge', (e && e.message) || '', '#DC2626');
          else alert('Could not send the nudge: ' + ((e && e.message) || ''));
        } finally {
          setTimeout(() => { nudgeBtn.disabled = false; }, 4000);
        }
      });
    }

    const input = $('.kt-compose-input', container);
    const send = $('.kt-send-btn', container);
    let pendingFile = null;   // staged image/voice attachment (declared early so paintAction can read it)

    // Mic when there's nothing to send, send arrow the moment there is.
    const micToggle = $('.kt-mic-btn', container);
    const paintAction = () => {
      // Show the send button when there's text OR a staged image/voice attachment
      // (a pasted screenshot with no caption still needs a way to send).
      const typing = !!(input.value || '').trim() || !!pendingFile;
      // setProperty with 'important': the mobile stylesheet sizes these buttons
      // with `display: inline-flex !important`, which out-ranks a plain inline
      // style — so a bare style.display = 'none' silently did nothing and both
      // buttons showed at once.
      if (send) send.style.setProperty('display', typing ? 'inline-flex' : 'none', 'important');
      if (micToggle) micToggle.style.setProperty('display', typing ? 'none' : 'inline-flex', 'important');
    };
    input.addEventListener('input', paintAction);
    paintAction();

    // Typing ping — tell the server we're typing, throttled to once / 2.5s so a
    // burst of keystrokes is a single request. The other side sees it via the
    // thread poll's typing_users.
    let lastTypingPing = 0;
    const pingTyping = () => {
      const now = Date.now();
      if ((now - lastTypingPing) < 2500) return;
      lastTypingPing = now;
      api('POST', endpointBase() + '/' + c.id + '/typing').catch(() => {});
    };
    input.addEventListener('input', () => { if ((input.value || '').trim()) pingTyping(); });
    const attachBtn = $('.kt-attach-btn', container);
    const attachInput = $('.kt-attach-input', container);
    const attachPreview = $('.kt-attach-preview', container);
    const attachName = $('.kt-attach-name', container);
    const attachRemove = $('.kt-attach-remove', container);

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
      paintAction();   // reveal/hide the send button when an attachment is staged/cleared
    }
    attachBtn.addEventListener('click', () => attachInput.click());
    attachInput.addEventListener('change', () => {
      const f = attachInput.files && attachInput.files[0];
      if (!f) return;
      if (f.size > 5 * 1024 * 1024) { alert('Image must be under 5 MB.'); attachInput.value = ''; return; }
      if (!/^image\/(jpeg|png|webp|gif)$/.test(f.type)) { alert('Only JPG, PNG, WEBP, or GIF.'); attachInput.value = ''; return; }
      setPending(f);
    });
    // Paste a screenshot / image straight into the compose box (Ctrl/⌘-V).
    input.addEventListener('paste', (e) => {
      const items = (e.clipboardData && e.clipboardData.items) || [];
      for (let i = 0; i < items.length; i++) {
        if (items[i].kind === 'file' && /^image\//.test(items[i].type)) {
          const blob = items[i].getAsFile();
          if (!blob) continue;
          if (blob.size > 5 * 1024 * 1024) { alert('Pasted image must be under 5 MB.'); return; }
          const ext = (blob.type.split('/')[1] || 'png').replace('jpeg', 'jpg');
          const file = new File([blob], 'pasted-' + Date.now() + '.' + ext, { type: blob.type });
          setPending(file);
          e.preventDefault();
          if (window.KT && KT.toast) KT.toast('📎', 'Image pasted', 'Press send to share it.', '#159FB4');
          return;
        }
      }
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
        if (gotIncoming) {
          playPing();
          if (window.KT && window.KT.refreshUnreadBadge) window.KT.refreshUnreadBadge();
          if (KT.ChatDock) KT.ChatDock.flashIncoming();   // flash the dock if minimised
        }
        setTyping(bodyEl, (fresh && fresh.typing_users) || []);
      } catch (e) {}
    }, THREAD_POLL_MS);

    input.focus();
  }

  // Typing indicator — a violet "other person" bubble with three animated dots,
  // pinned as the last row of the thread body. Matches the parent chat. names is
  // the server's typing_users (others only; the server excludes the caller).
  function setTyping(bodyEl, names) {
    if (!bodyEl) return;
    if (!document.getElementById('kt-chat-typing-style')) {
      const st = document.createElement('style');
      st.id = 'kt-chat-typing-style';
      st.textContent = '@keyframes kt-typedot{0%,60%,100%{opacity:.25;transform:translateY(0);}30%{opacity:1;transform:translateY(-3px);}}';
      document.head.appendChild(st);
    }
    let row = bodyEl.querySelector('.kt-typing-row');
    if (!names || !names.length) { if (row) row.remove(); return; }
    const label = names.length === 1 ? (names[0] + ' is typing') : 'Several people are typing';
    const dot = 'display:inline-block;width:6px;height:6px;border-radius:50%;background:#7C6BB0;';
    const inner =
      '<div style="display:flex;justify-content:flex-start;">' +
        '<div style="background:#EFEAFB;color:#1E1B34;border-radius:16px;border-bottom-left-radius:5px;padding:9px 13px;max-width:78%;">' +
          '<div style="font-size:11px;font-weight:800;color:#7C6BB0;margin-bottom:3px;">' + escapeHtml(label) + '</div>' +
          '<div style="display:flex;gap:4px;align-items:center;height:8px;">' +
            '<span style="' + dot + 'animation:kt-typedot 1.2s infinite;"></span>' +
            '<span style="' + dot + 'animation:kt-typedot 1.2s infinite .2s;"></span>' +
            '<span style="' + dot + 'animation:kt-typedot 1.2s infinite .4s;"></span>' +
          '</div>' +
        '</div>' +
      '</div>';
    if (!row) {
      row = document.createElement('div');
      row.className = 'kt-typing-row';
      row.style.margin = '4px 0';
      bodyEl.appendChild(row);
    } else {
      bodyEl.appendChild(row); // keep it last, below any freshly-appended messages
    }
    row.innerHTML = inner;
    bodyEl.scrollTop = bodyEl.scrollHeight;
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
      // Attachment URLs come back relative (/storage/...); resolve against the API
      // host so images render and voice notes actually PLAY inline (a relative src
      // 404s against the SPA origin, which is why voice notes didn't play here).
      const url = absPhotoUrl(a.url);
      // Detect the KIND by mime OR type OR file extension. Voice notes sent from the
      // PARENT app store {type:'audio', url} with NO mime — the old mime-only check
      // rendered them as a plain "📎 attachment" link instead of a playable audio
      // element (that's why voice notes "stopped showing" in the staff chat).
      var u = String(a.url || '');
      var isAudio = (a.mime && a.mime.indexOf('audio/') === 0) || a.type === 'audio' || /\.(webm|mp3|m4a|ogg|wav|aac)$/i.test(u);
      var isImage = (a.mime && a.mime.indexOf('image/') === 0) || a.type === 'image' || /\.(jpe?g|png|gif|webp|bmp|heic)$/i.test(u);
      if (isAudio) {
        // Voice note — inline audio player.
        return `<div style="margin:6px 0;">🎤 <audio controls preload="metadata" src="${escapeHtml(url)}" style="max-width:230px;height:38px;vertical-align:middle;"></audio></div>`;
      }
      if (isImage) {
        // Image attachment — render inline as a click-to-zoom thumbnail
        return `<div style="margin:6px 0;"><a href="${escapeHtml(url)}" target="_blank" rel="noopener" style="display:block;"><img src="${escapeHtml(url)}" alt="${escapeHtml(a.name || 'image')}" style="max-width:100%;max-height:280px;border-radius:10px;display:block;background:rgba(0,0,0,.04);"></a></div>`;
      }
      // Non-image fallback (future: pdf, etc.)
      return `<div style="margin:6px 0;"><a href="${escapeHtml(url)}" target="_blank" rel="noopener" style="color:${mine ? '#0E7C90' : '#1F6080'};text-decoration:underline;font-size:13px;">📎 ${escapeHtml(a.name || 'attachment')}</a></div>`;
    }).join('');
    // Same bubbles as the parent app: a pale teal tint for your own messages with
    // dark text, not solid brand-blue with white text. The two chats sat side by
    // side looking like different products, and the solid fill also inherited the
    // agency's brand colour — which on one agency is neon lime.
    // Per-sender colour + avatar so a multi-person thread is easy to follow.
    const col = senderColor(m.sender_name);
    const initial = escapeHtml((String(m.sender_name || '?').charAt(0) || '?').toUpperCase());
    // A real photo when the thread carries one (matches the parent chat), else a
    // coloured initial in the sender's colour.
    let avatar = '';
    if (!mine) {
      // Relative photo paths (/storage/...) resolve against the API host, not the
      // SPA host — the parent chat does the same via absUrl. Without this the photo
      // 404s and every message fell back to a coloured initial.
      const photo = absPhotoUrl(m.sender_photo_url);
      if (photo && window.KT && KT.avatar) {
        avatar = `<span style="flex-shrink:0;align-self:flex-end;display:inline-flex;">${KT.avatar(m.sender_name || '?', { size: 30, photoUrl: photo })}</span>`;
      } else {
        avatar = `<div style="width:30px;height:30px;border-radius:50%;background:${col};color:#fff;font-size:12px;font-weight:800;display:flex;align-items:center;justify-content:center;flex-shrink:0;align-self:flex-end;">${initial}</div>`;
      }
    }
    if (m.deleted) {
      // A removed message leaves a tombstone (same as the parent chat).
      return `
      <div data-mid="${m.id}" style="display:flex;justify-content:${mine ? 'flex-end' : 'flex-start'};gap:8px;align-items:flex-end;">
        ${avatar}
        <div style="max-width:78%;padding:9px 13px;border-radius:16px;background:#EEF1F5;color:#64748B;font-style:italic;font-size:13.5px;">🚫 Message deleted</div>
      </div>`;
    }
    // Own messages get a small delete control (wired via delegation on the thread body).
    const delBtn = (mine && m.can_delete)
      ? `<button type="button" class="kt-msg-del" data-del-mid="${m.id}" title="Delete message" style="background:none;border:none;cursor:pointer;color:rgba(13,27,42,.4);font-size:12.5px;line-height:1;padding:0 2px;margin-left:6px;">🗑</button>`
      : '';
    // Any message can be reacted to; 😊 opens a quick emoji picker.
    const reactBtn = `<button type="button" class="kt-msg-react" data-react-mid="${m.id}" title="React" aria-label="React" style="background:none;border:none;cursor:pointer;color:rgba(13,27,42,.4);font-size:12.5px;line-height:1;padding:0 2px;margin-left:6px;">😊</button>`;
    const reactionsHtml = (m.reactions && m.reactions.length)
      ? `<div class="kt-msg-reactions" style="display:flex;flex-wrap:wrap;gap:4px;margin-top:5px;">` + m.reactions.map(function (rx) {
          return `<button type="button" class="kt-msg-react-chip" data-react-mid="${m.id}" data-emoji="${escapeHtml(rx.emoji)}" style="border:1px solid ${rx.mine ? '#1F6080' : '#E2E8F0'};background:${rx.mine ? '#EFF6FF' : '#fff'};border-radius:12px;padding:1px 7px;font-size:12.5px;cursor:pointer;line-height:1.6;">${escapeHtml(rx.emoji)} ${rx.count}</button>`;
        }).join('') + `</div>`
      : '';
    return `
      <div data-mid="${m.id}" style="display:flex;justify-content:${mine ? 'flex-end' : 'flex-start'};gap:8px;align-items:flex-end;">
        ${avatar}
        <div style="max-width:78%;padding:10px 13px;border-radius:16px;background:${mine ? '#DCF1F4' : '#EFEAFB'};color:${mine ? '#0D1B2A' : '#1E1B34'};${mine ? 'border-bottom-right-radius:5px;' : 'border-bottom-left-radius:5px;'}">
          ${!mine ? `<div style="font-size:11px;font-weight:800;color:${col};margin-bottom:2px;">${escapeHtml(m.sender_name)}</div>` : ''}
          ${attachmentsHtml}
          ${m.body ? `<div style="font-size:15px;line-height:1.45;white-space:pre-wrap;word-wrap:break-word;">${escapeHtml(m.body)}</div>` : ''}
          <div style="font-size:10.5px;color:rgba(13,27,42,.5);margin-top:4px;text-align:${mine ? 'right' : 'left'};">${formatTime(m.created_at)}${mine ? readReceipt(m) : ''}${reactBtn}${delBtn}</div>
          ${reactionsHtml}
        </div>
      </div>
    `;
  }
  // Deterministic colour per participant name — same person, same colour.
  function senderColor(s) {
    var pal = ['#1F6FB2', '#0FA3B1', '#E0699A', '#7C3AED', '#F59E0B', '#10B981', '#EF6C4D', '#0891B2', '#DB2777', '#4F8A3D'];
    s = String(s || ''); var h = 0;
    for (var i = 0; i < s.length; i++) h = (h * 31 + s.charCodeAt(i)) >>> 0;
    return pal[h % pal.length];
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
