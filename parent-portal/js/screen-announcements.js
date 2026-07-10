/* ═══════════════════════════════════════════════════════════════════
   KIDDIETRAC v13 — Announcements (provider compose + parent inbox)
   ═══════════════════════════════════════════════════════════════════ */
(function (window) {
  'use strict';

  function token() { return sessionStorage.getItem('kt_token') || localStorage.getItem('kt_token'); }
  function apiBase() { return (window.KT && window.KT.API_BASE) || 'https://api.kiddietrac.com/api/v1'; }
  function getUser() { try { return JSON.parse(sessionStorage.getItem('kt_user') || localStorage.getItem('kt_user') || '{}'); } catch (e) { return {}; } }
  function getRole() { try { const v = sessionStorage.getItem('kt_view_as'); if (v) return v; } catch (e) {} const u = getUser(); return u.primary_role || (u.roles && u.roles[0]) || 'guest'; }

  async function api(method, path, body) {
    const opts = { method, headers: { 'Authorization': 'Bearer ' + token(), 'Accept': 'application/json' } };
    // Scope every call to the active agency. Without this, a platform_admin gets
    // 403 on /admin/* — which left the composer's centre dropdown empty and made
    // "Send" silently do nothing. Match the rest of the app's header + storage key.
    var aid = sessionStorage.getItem('kt_active_agency_id') || localStorage.getItem('kt_active_agency_id');
    if (aid) opts.headers['X-Active-Agency-Id'] = aid;
    if (body !== undefined) { opts.headers['Content-Type'] = 'application/json'; opts.body = JSON.stringify(body); }
    const res = await fetch(apiBase() + path, opts);
    const json = await res.json().catch(() => ({}));
    if (!res.ok) throw new Error(json.message || ('API ' + res.status));
    return json;
  }

  function esc(s) { return s == null ? '' : String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;'); }
  function $(s, r) { return (r || document).querySelector(s); }
  function $$(s, r) { return (r || document).querySelectorAll(s); }

  function fmtDate(iso) {
    if (!iso) return '';
    const d = new Date(iso.replace(' ', 'T') + 'Z');
    const today = new Date().toDateString() === d.toDateString();
    return today ? d.toLocaleTimeString([], { hour: 'numeric', minute: '2-digit' }) : d.toLocaleDateString([], { month: 'short', day: 'numeric', year: 'numeric' });
  }
  // Date AND time together, for the table Date column.
  function fmtDateTime(iso) {
    if (!iso) return '';
    const d = new Date(iso.replace(' ', 'T') + 'Z');
    const today = new Date().toDateString() === d.toDateString();
    const day = today ? 'Today' : d.toLocaleDateString([], { month: 'short', day: 'numeric' });
    const time = d.toLocaleTimeString([], { hour: 'numeric', minute: '2-digit' });
    return day + ', ' + time;
  }

  async function renderProvider(container) {
    container.innerHTML = '<div style="padding:32px;text-align:center;color:#6B7280;">Loading…</div>';
    let data;
    try { data = await api('GET', '/provider/announcements'); }
    catch (e) {
      container.innerHTML = '<div style="padding:24px;color:#DC2626;">Could not load: ' + esc(e.message) + '</div>';
      return;
    }
    const items = data.announcements || [];

    const state = { sort: 'date', dir: -1, q: '' };
    const th = (key, label, extra) => `<th data-sort="${key}" class="kt-ann-th" style="text-align:${extra && extra.right ? 'right' : 'left'};padding:10px 14px;font-size:12px;color:#6B7280;font-weight:600;cursor:pointer;user-select:none;${extra && extra.w ? 'width:' + extra.w + ';' : ''}">${label} <span class="kt-ar" style="color:#94A3B8;"></span></th>`;

    container.innerHTML = `
      <div style="display:flex;flex-direction:column;height:100%;min-height:0;padding:20px 24px 0;box-sizing:border-box;">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px;flex-wrap:wrap;gap:12px;flex:0 0 auto;">
          <div>
            <h2 style="font-size:22px;margin:0;">📢 Announcements</h2>
            <p style="color:#6B7280;font-size:13px;margin:3px 0 0;">${items.length} sent · tap a row to read it</p>
          </div>
          <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;">
            <input id="kt-ann-filter" type="search" placeholder="🔍 Filter…" style="padding:9px 12px;border:1px solid #D1D5DB;border-radius:9px;font-size:13px;min-width:190px;">
            <button id="kt-new-ann" style="background:#1F6080;color:white;border:none;padding:11px 20px;border-radius:10px;font-weight:700;cursor:pointer;">+ New Announcement</button>
          </div>
        </div>
        ${items.length === 0
          ? `<div style="text-align:center;padding:48px;background:white;border-radius:14px;color:#6B7280;">
              <div style="font-size:48px;margin-bottom:12px;">📭</div>
              No announcements sent yet.
            </div>`
          : `<div style="flex:1 1 auto;min-height:0;max-height:calc(100vh - 210px);overflow-y:auto;background:#fff;border:1px solid #E5E7EB;border-radius:12px;">
              <table style="width:100%;border-collapse:collapse;font-size:14px;table-layout:fixed;">
                <thead>
                  <tr style="position:sticky;top:0;z-index:1;background:#F9FAFB;box-shadow:inset 0 -1px 0 #E5E7EB;">
                    ${th('scope', 'Sent to', { w: '180px' })}
                    ${th('title', 'Announcement')}
                    ${th('channels', 'Channels', { w: '90px' })}
                    ${th('date', 'Date', { w: '150px', right: true })}
                  </tr>
                </thead>
                <tbody id="kt-ann-tbody"></tbody>
              </table>
            </div>`
        }
        <div id="kt-mount"></div>
      </div>
    `;
    $('#kt-new-ann', container).addEventListener('click', () => openComposer(container));

    if (items.length) {
      const tbody = $('#kt-ann-tbody', container);
      const sortVal = (a) => {
        if (state.sort === 'scope') return (a.centre_name || a.scope_type || '').toLowerCase();
        if (state.sort === 'title') return (a.title || '').toLowerCase();
        if (state.sort === 'channels') return (a.send_email ? 1 : 0) + (a.send_push ? 2 : 0);
        return new Date(String(a.sent_at || a.created_at || '').replace(' ', 'T')).getTime() || 0;
      };
      const paint = () => {
        const q = state.q.trim().toLowerCase();
        let list = items.map((a, i) => ({ a, i }));
        if (q) list = list.filter(({ a }) => ((a.title || '') + ' ' + (a.centre_name || a.scope_type || '') + ' ' + plainPreview(a.body, 400)).toLowerCase().indexOf(q) !== -1);
        list.sort((x, y) => { const va = sortVal(x.a), vb = sortVal(y.a); return (va < vb ? -1 : va > vb ? 1 : 0) * state.dir; });
        tbody.innerHTML = list.length ? list.map(({ a, i }) => annRow(a, i)).join('') : '<tr><td colspan="4" style="padding:26px;text-align:center;color:#9CA3AF;">No matching announcements.</td></tr>';
        // Zebra striping + hover + click-to-expand, re-applied after each sort/filter.
        let z = 0;
        tbody.querySelectorAll('.kt-ann-row').forEach((row) => {
          const base = (z++ % 2) ? '#F7F9FB' : '#FFFFFF';
          row.dataset.base = base; row.style.background = base;
          row.addEventListener('mouseenter', () => { if (!row.dataset.open) row.style.background = '#EEF4F7'; });
          row.addEventListener('mouseleave', () => { if (!row.dataset.open) row.style.background = row.dataset.base; });
          row.addEventListener('click', () => {
            const idx = row.getAttribute('data-idx');
            const det = tbody.querySelector('.kt-ann-detail[data-idx="' + idx + '"]');
            const open = det && det.style.display !== 'none';
            if (det) det.style.display = open ? 'none' : 'table-row';
            row.dataset.open = open ? '' : '1';
            row.style.background = open ? row.dataset.base : '#E4EEF3';
          });
        });
        container.querySelectorAll('.kt-ann-th').forEach((h) => {
          const ar = h.querySelector('.kt-ar'); if (!ar) return;
          ar.textContent = (h.getAttribute('data-sort') === state.sort) ? (state.dir < 0 ? '▾' : '▴') : '';
        });
      };
      container.querySelectorAll('.kt-ann-th').forEach((h) => {
        h.addEventListener('click', () => {
          const k = h.getAttribute('data-sort');
          if (state.sort === k) state.dir = -state.dir; else { state.sort = k; state.dir = (k === 'date') ? -1 : 1; }
          paint();
        });
      });
      const fi = $('#kt-ann-filter', container);
      if (fi) fi.addEventListener('input', () => { state.q = fi.value || ''; paint(); });
      paint();
    }
  }

  function plainPreview(html, n) {
    var tmp = document.createElement('div');
    tmp.innerHTML = String(html == null ? '' : html);
    var t = (tmp.textContent || '').replace(/\s+/g, ' ').trim();
    n = n || 90;
    return t.length > n ? t.slice(0, n) + '…' : t;
  }

  function annRow(a, i) {
    var scope = esc(a.centre_name || a.scope_type);
    var chips = [a.send_email ? '📧' : '', a.send_push ? '🔔' : '', (a.scheduled_at && !a.sent_at) ? '⏱' : ''].filter(Boolean).join(' ');
    return `
      <tr class="kt-ann-row" data-idx="${i}" style="border-top:1px solid #F1F3F5;cursor:pointer;">
        <td style="padding:11px 14px;color:#374151;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">📍 ${scope}</td>
        <td style="padding:11px 14px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">
          <span style="font-weight:600;color:#111827;">${esc(a.title)}</span>
          <span style="color:#6B7280;"> — ${esc(plainPreview(a.body))}</span>
        </td>
        <td style="padding:11px 14px;color:#6B7280;white-space:nowrap;">${chips || '—'}</td>
        <td style="padding:11px 14px;text-align:right;color:#9CA3AF;white-space:nowrap;">${esc(fmtDateTime(a.sent_at || a.created_at))}</td>
      </tr>
      <tr class="kt-ann-detail" data-idx="${i}" style="display:none;background:#FAFCFD;">
        <td colspan="4" style="padding:4px 18px 18px;">
          <div style="font-size:12px;color:#9CA3AF;margin:0 0 8px;">— ${esc(a.sender)} · ${esc(a.scope_type)}${a.centre_name ? ' · ' + esc(a.centre_name) : ''}${a.scheduled_at && !a.sent_at ? ' · ⏱ scheduled' : ''}</div>
          <div style="font-size:14px;color:#374151;line-height:1.6;" class="kt-ann-body">${sanitizeHtml(a.body)}</div>
        </td>
      </tr>`;
  }

  function annCard(a) {
    return `
      <div style="background:white;border-radius:12px;padding:18px 20px;box-shadow:0 1px 4px rgba(0,0,0,.04);">
        <div style="display:flex;justify-content:space-between;gap:12px;margin-bottom:6px;">
          <div style="font-weight:700;font-size:16px;color:#111827;">${esc(a.title)}</div>
          <div style="font-size:12px;color:#9CA3AF;flex-shrink:0;">${esc(fmtDate(a.sent_at || a.created_at))}</div>
        </div>
        <div style="font-size:14px;color:#6B7280;display:flex;gap:10px;margin-bottom:8px;">
          <span>📍 ${esc(a.scope_type)} ${a.centre_name ? '· ' + esc(a.centre_name) : ''}</span>
          ${a.send_email ? '<span>📧 email</span>' : ''}
          ${a.send_push ? '<span>🔔 push</span>' : ''}
          ${a.scheduled_at && !a.sent_at ? '<span style="color:#F59E0B;">⏱ scheduled</span>' : ''}
        </div>
        <div style="font-size:14px;color:#374151;line-height:1.5;" class="kt-ann-body">${sanitizeHtml(a.body)}</div>
        <div style="font-size:12px;color:#9CA3AF;margin-top:8px;">— ${esc(a.sender)}</div>
      </div>
    `;
  }

  async function openComposer(container) {
    // Load centres so the user can pick scope
    let centres = [];
    try { const r = await api('GET', '/admin/centres'); centres = r.centres || []; } catch (e) {}

    // If no centres came back, the account can't broadcast (wrong role, or
    // active-agency not set). Surface it instead of showing an empty dropdown.
    const noCentres = centres.length === 0;
    const mount = $('#kt-mount', container);
    mount.innerHTML = `
      <div class="kt-modal-overlay" style="position:fixed;inset:0;background:rgba(0,0,0,.5);display:flex;align-items:center;justify-content:center;z-index:9999;padding:16px;">
        <div style="background:white;border-radius:14px;max-width:560px;width:100%;padding:24px;max-height:90vh;overflow-y:auto;">
          <h2 style="font-size:20px;margin:0 0 4px;">New Announcement</h2>
          <p style="color:#6B7280;font-size:13px;margin:0 0 18px;">Sends to all families in the selected scope.</p>
          <form id="kt-ann-form" onsubmit="return false;" style="display:grid;gap:12px;">
            <div>
              <label style="font-size:13px;font-weight:600;">Send to *</label>
              <select name="scope_id" required style="${inp()}">
                <option value="">— Pick scope —</option>
                ${centres.map(c => `<option value="centre:${c.id}">📍 Centre: ${esc(c.name)}</option>`).join('')}
              </select>
              ${noCentres
                ? `<div style="font-size:12px;color:#B91C1C;margin-top:4px;">⚠ No centres available for this account. Announcements can only be sent by a centre director or agency admin — check you're signed in with that role.</div>`
                : `<div style="font-size:11px;color:#9CA3AF;margin-top:2px;">All families with children at this centre will receive it.</div>`}
            </div>
            <input name="title" required placeholder="Title *" maxlength="200" style="${inp()}">
            <div>
              <div id="kt-rte-tb" style="display:flex;gap:3px;border:1px solid #D1D5DB;border-bottom:none;border-radius:8px 8px 0 0;padding:6px;background:#F9FAFB;flex-wrap:wrap;">
                <button type="button" data-cmd="bold"   style="${rteBtn()};font-weight:800;">B</button>
                <button type="button" data-cmd="italic" style="${rteBtn()};font-style:italic;">I</button>
                <button type="button" data-cmd="underline" style="${rteBtn()};text-decoration:underline;">U</button>
                <button type="button" data-cmd="insertUnorderedList" style="${rteBtn()};">• List</button>
                <button type="button" data-cmd="insertOrderedList" style="${rteBtn()};">1. List</button>
                <button type="button" data-cmd="createLink" style="${rteBtn()};">🔗 Link</button>
              </div>
              <div id="kt-rte" contenteditable="true" data-ph="Body (parents will see this)" style="border:1px solid #D1D5DB;border-radius:0 0 8px 8px;min-height:120px;max-height:280px;overflow:auto;padding:10px 12px;font-size:14px;line-height:1.5;color:#111827;outline:none;background:white;"></div>
            </div>
            <div style="display:flex;gap:16px;font-size:14px;flex-wrap:wrap;">
              <label style="display:flex;align-items:center;gap:6px;"><input type="checkbox" name="send_email" checked> 📧 Send email</label>
              <label style="display:flex;align-items:center;gap:6px;"><input type="checkbox" name="send_sms"> 📱 Send SMS</label>
              <label style="display:flex;align-items:center;gap:6px;"><input type="checkbox" name="send_push" checked> 🔔 In-app notification</label>
            </div>
            <details style="font-size:13px;">
              <summary style="cursor:pointer;color:#6B7280;">Schedule for later (optional)</summary>
              <input name="scheduled_at" type="datetime-local" style="${inp()};margin-top:8px;">
            </details>
            <div id="kt-status" style="min-height:20px;font-size:14px;"></div>
            <div style="display:flex;justify-content:flex-end;gap:8px;">
              <button type="button" id="kt-cancel" style="background:#F3F4F6;color:#374151;border:none;padding:10px 18px;border-radius:8px;font-weight:600;cursor:pointer;">Cancel</button>
              <button type="button" id="kt-send" style="background:#1F6080;color:white;border:none;padding:10px 22px;border-radius:8px;font-weight:700;cursor:pointer;">Send</button>
            </div>
          </form>
        </div>
      </div>
    `;
    const overlay = $('.kt-modal-overlay', mount);
    const close = () => mount.innerHTML = '';
    overlay.addEventListener('click', (e) => { if (e.target === overlay) close(); });
    $('#kt-cancel', mount).addEventListener('click', close);

    // Rich-text editor wiring
    const rte = $('#kt-rte', mount);
    $('#kt-rte-tb', mount).querySelectorAll('button[data-cmd]').forEach((b) => {
      b.addEventListener('mousedown', (e) => e.preventDefault()); // keep selection/focus
      b.addEventListener('click', () => {
        const cmd = b.getAttribute('data-cmd');
        rte.focus();
        if (cmd === 'createLink') {
          const url = window.prompt('Link URL (https://…)');
          if (url) document.execCommand('createLink', false, url);
        } else {
          document.execCommand(cmd, false, null);
        }
      });
    });

    const formEl = $('#kt-ann-form', mount);
    async function doSend() {
      const f = formEl;
      const statusEl = $('#kt-status', mount);
      const sendBtn = $('#kt-send', mount);
      const scopeRaw = f.querySelector('[name="scope_id"]').value;
      if (!scopeRaw) {
        statusEl.style.color = '#DC2626';
        statusEl.textContent = '✗ Choose who to send to first';
        return;
      }
      const [scope_type, scope_id] = scopeRaw.split(':');
      const titleVal = (f.querySelector('[name="title"]').value || '').trim();
      if (!titleVal) {
        statusEl.style.color = '#DC2626';
        statusEl.textContent = '✗ Title is required';
        return;
      }
      const rteEl = f.querySelector('#kt-rte');
      const bodyHtml = (rteEl.innerHTML || '').trim();
      if (!bodyHtml || !rteEl.textContent.trim()) {
        statusEl.style.color = '#DC2626';
        statusEl.textContent = '✗ Body is required';
        return;
      }
      const data = {
        scope_type,
        scope_id: parseInt(scope_id, 10),
        title: titleVal,
        body: bodyHtml,
        send_email: f.querySelector('[name="send_email"]').checked,
        send_sms: f.querySelector('[name="send_sms"]').checked,
        send_push: f.querySelector('[name="send_push"]').checked,
      };
      const sched = f.querySelector('[name="scheduled_at"]').value;
      if (sched) data.scheduled_at = sched;

      const orig = sendBtn ? sendBtn.textContent : 'Send';
      if (sendBtn) { sendBtn.disabled = true; sendBtn.textContent = 'Sending…'; }
      statusEl.style.color = '#6B7280';
      statusEl.textContent = 'Sending…';
      try {
        const res = await api('POST', '/provider/announcements', data);
        statusEl.style.color = '#16A34A';
        statusEl.textContent = res.scheduled ? '✓ Scheduled' : '✓ Sent to ' + (res.delivered_to || 0) + ' recipient(s)';
        setTimeout(() => { close(); renderProvider(container); }, 1200);
      } catch (err) {
        if (sendBtn) { sendBtn.disabled = false; sendBtn.textContent = orig; }
        statusEl.style.color = '#DC2626';
        statusEl.textContent = '✗ ' + err.message;
      }
    }
    // Bind the send action to the button CLICK directly. Relying on implicit
    // form submission (type=submit) was silently dropped inside the app shell —
    // clicking Send fired no submit event at all — so "Send" did nothing with no
    // error. The form 'submit' handler stays as an Enter-key fallback.
    $('#kt-send', mount).addEventListener('click', (e) => { e.preventDefault(); doSend(); });
    formEl.addEventListener('submit', (e) => { e.preventDefault(); doSend(); });
  }

  async function renderParent(container) {
    container.innerHTML = '<div style="padding:32px;text-align:center;color:#6B7280;">Loading…</div>';
    let data;
    try { data = await api('GET', '/parent/announcements'); }
    catch (e) {
      container.innerHTML = '<div style="padding:24px;color:#DC2626;">Could not load: ' + esc(e.message) + '</div>';
      return;
    }
    const items = data.announcements || [];

    container.innerHTML = `
      <div style="padding:24px;max-width:1800px;">
        <h2 style="font-size:24px;margin:0 0 16px;">📢 Announcements</h2>
        ${items.length === 0
          ? `<div style="text-align:center;padding:48px;background:white;border-radius:14px;color:#6B7280;">
              <div style="font-size:48px;margin-bottom:12px;">📭</div>
              Nothing here yet — when your centre sends an announcement, it'll appear here.
            </div>`
          : `<div data-kt-list="1" style="display:grid;gap:12px;">
              ${items.map(a => `
                <div style="background:white;border-radius:12px;padding:18px 20px;box-shadow:0 1px 4px rgba(0,0,0,.04);">
                  <div style="display:flex;justify-content:space-between;margin-bottom:6px;">
                    <div style="font-weight:700;font-size:16px;">${esc(a.title)}</div>
                    <div style="font-size:12px;color:#9CA3AF;">${esc(fmtDate(a.sent_at))}</div>
                  </div>
                  <div style="font-size:14px;color:#374151;line-height:1.5;" class="kt-ann-body">${sanitizeHtml(a.body)}</div>
                </div>
              `).join('')}
            </div>`
        }
      </div>
    `;
  }

  function inp() { return 'width:100%;padding:10px 12px;border:1px solid #D1D5DB;border-radius:8px;font-size:14px;'; }
  function rteBtn() { return 'background:white;border:1px solid #D1D5DB;border-radius:6px;padding:4px 9px;font-size:13px;cursor:pointer;color:#374151;min-width:30px;'; }

  // Allow only a safe subset of HTML from the rich-text editor / stored body.
  function sanitizeHtml(html) {
    var tmp = document.createElement('div');
    tmp.innerHTML = String(html == null ? '' : html);
    tmp.querySelectorAll('script,style,iframe,object,embed,link,meta,form,input').forEach(function (n) { n.remove(); });
    tmp.querySelectorAll('*').forEach(function (el) {
      Array.prototype.slice.call(el.attributes).forEach(function (at) {
        var n = at.name.toLowerCase();
        if (n.indexOf('on') === 0) el.removeAttribute(at.name);
        if ((n === 'href' || n === 'src') && /^\s*javascript:/i.test(at.value)) el.removeAttribute(at.name);
      });
    });
    tmp.querySelectorAll('a').forEach(function (a) { a.setAttribute('target', '_blank'); a.setAttribute('rel', 'noopener noreferrer'); });
    return tmp.innerHTML;
  }

  (function injectRteCss() {
    if (document.getElementById('kt-rte-css')) return;
    var s = document.createElement('style');
    s.id = 'kt-rte-css';
    s.textContent = '#kt-rte:empty:before{content:attr(data-ph);color:#9CA3AF;pointer-events:none;}'
      + '#kt-rte-tb button:hover{background:#EFF2F6;}#kt-rte a{color:#1F6080;}';
    document.head.appendChild(s);
  })();

  function render(container) {
    const role = getRole();
    if (role === 'guardian') renderParent(container);
    else renderProvider(container);
  }

  window.KT = window.KT || {};
  window.KT.Announcements = { render };
})(window);
