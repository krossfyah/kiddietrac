/* ═══════════════════════════════════════════════════════════════════
   KIDDIETRAC v13 — Announcements (provider compose + parent inbox)
   ═══════════════════════════════════════════════════════════════════ */
(function (window) {
  'use strict';

  function token() { return sessionStorage.getItem('kt_token') || localStorage.getItem('kt_token'); }
  function apiBase() { return (window.KT && window.KT.API_BASE) || 'https://api.kiddietrac.com/api/v1'; }
  function getUser() { try { return JSON.parse(sessionStorage.getItem('kt_user') || localStorage.getItem('kt_user') || '{}'); } catch (e) { return {}; } }
  function getRole() { const u = getUser(); return u.primary_role || (u.roles && u.roles[0]) || 'guest'; }

  async function api(method, path, body) {
    const opts = { method, headers: { 'Authorization': 'Bearer ' + token(), 'Accept': 'application/json' } };
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

  async function renderProvider(container) {
    container.innerHTML = '<div style="padding:32px;text-align:center;color:#6B7280;">Loading…</div>';
    let data;
    try { data = await api('GET', '/provider/announcements'); }
    catch (e) {
      container.innerHTML = '<div style="padding:24px;color:#DC2626;">Could not load: ' + esc(e.message) + '</div>';
      return;
    }
    const items = data.announcements || [];

    container.innerHTML = `
      <div style="padding:24px;max-width:1800px;">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:20px;flex-wrap:wrap;gap:12px;">
          <div>
            <h2 style="font-size:24px;margin:0;">📢 Announcements</h2>
            <p style="color:#6B7280;font-size:14px;margin:4px 0 0;">Broadcast to your families</p>
          </div>
          <button id="kt-new-ann" style="background:#1F6080;color:white;border:none;padding:12px 22px;border-radius:10px;font-weight:700;cursor:pointer;">+ New Announcement</button>
        </div>
        ${items.length === 0
          ? `<div style="text-align:center;padding:48px;background:white;border-radius:14px;color:#6B7280;">
              <div style="font-size:48px;margin-bottom:12px;">📭</div>
              No announcements sent yet.
            </div>`
          : `<div style="display:grid;gap:12px;">
              ${items.map(a => annCard(a)).join('')}
            </div>`
        }
        <div id="kt-mount"></div>
      </div>
    `;
    $('#kt-new-ann', container).addEventListener('click', () => openComposer(container));
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
        <div style="font-size:14px;color:#374151;white-space:pre-wrap;line-height:1.5;">${esc(a.body)}</div>
        <div style="font-size:12px;color:#9CA3AF;margin-top:8px;">— ${esc(a.sender)}</div>
      </div>
    `;
  }

  async function openComposer(container) {
    // Load centres so the user can pick scope
    let centres = [];
    try { const r = await api('GET', '/admin/centres'); centres = r.centres || []; } catch (e) {}

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
              <div style="font-size:11px;color:#9CA3AF;margin-top:2px;">All families with children at this centre will receive it.</div>
            </div>
            <input name="title" required placeholder="Title *" maxlength="200" style="${inp()}">
            <textarea name="body" required placeholder="Body (parents will see this)" rows="6" maxlength="5000" style="${inp()};font-family:inherit;"></textarea>
            <div style="display:flex;gap:16px;font-size:14px;">
              <label style="display:flex;align-items:center;gap:6px;"><input type="checkbox" name="send_email" checked> 📧 Send email</label>
              <label style="display:flex;align-items:center;gap:6px;"><input type="checkbox" name="send_push" checked> 🔔 In-app notification</label>
            </div>
            <details style="font-size:13px;">
              <summary style="cursor:pointer;color:#6B7280;">Schedule for later (optional)</summary>
              <input name="scheduled_at" type="datetime-local" style="${inp()};margin-top:8px;">
            </details>
            <div id="kt-status" style="min-height:20px;font-size:14px;"></div>
            <div style="display:flex;justify-content:flex-end;gap:8px;">
              <button type="button" id="kt-cancel" style="background:#F3F4F6;color:#374151;border:none;padding:10px 18px;border-radius:8px;font-weight:600;cursor:pointer;">Cancel</button>
              <button type="submit" id="kt-send" style="background:#1F6080;color:white;border:none;padding:10px 22px;border-radius:8px;font-weight:700;cursor:pointer;">Send</button>
            </div>
          </form>
        </div>
      </div>
    `;
    const overlay = $('.kt-modal-overlay', mount);
    const close = () => mount.innerHTML = '';
    overlay.addEventListener('click', (e) => { if (e.target === overlay) close(); });
    $('#kt-cancel', mount).addEventListener('click', close);

    $('#kt-ann-form', mount).addEventListener('submit', async (e) => {
      e.preventDefault();
      const f = e.target;
      const scopeRaw = f.querySelector('[name="scope_id"]').value;
      const [scope_type, scope_id] = scopeRaw.split(':');
      const data = {
        scope_type,
        scope_id: parseInt(scope_id, 10),
        title: f.querySelector('[name="title"]').value,
        body: f.querySelector('[name="body"]').value,
        send_email: f.querySelector('[name="send_email"]').checked,
        send_push: f.querySelector('[name="send_push"]').checked,
      };
      const sched = f.querySelector('[name="scheduled_at"]').value;
      if (sched) data.scheduled_at = sched;

      try {
        const res = await api('POST', '/provider/announcements', data);
        $('#kt-status', mount).style.color = '#16A34A';
        $('#kt-status', mount).textContent = '✓ Sent to ' + (res.delivered_to || 0) + ' recipient(s)';
        setTimeout(() => { close(); renderProvider(container); }, 1200);
      } catch (e) {
        $('#kt-status', mount).style.color = '#DC2626';
        $('#kt-status', mount).textContent = '✗ ' + e.message;
      }
    });
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
          : `<div style="display:grid;gap:12px;">
              ${items.map(a => `
                <div style="background:white;border-radius:12px;padding:18px 20px;box-shadow:0 1px 4px rgba(0,0,0,.04);">
                  <div style="display:flex;justify-content:space-between;margin-bottom:6px;">
                    <div style="font-weight:700;font-size:16px;">${esc(a.title)}</div>
                    <div style="font-size:12px;color:#9CA3AF;">${esc(fmtDate(a.sent_at))}</div>
                  </div>
                  <div style="font-size:14px;color:#374151;white-space:pre-wrap;line-height:1.5;">${esc(a.body)}</div>
                </div>
              `).join('')}
            </div>`
        }
      </div>
    `;
  }

  function inp() { return 'width:100%;padding:10px 12px;border:1px solid #D1D5DB;border-radius:8px;font-size:14px;'; }

  function render(container) {
    const role = getRole();
    if (role === 'guardian') renderParent(container);
    else renderProvider(container);
  }

  window.KT = window.KT || {};
  window.KT.Announcements = { render };
})(window);
