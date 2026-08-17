/* ═══════════════════════════════════════════════════════════════════
   KIDDIETRAC v13 — Waitlist screen
   ═══════════════════════════════════════════════════════════════════ */
(function (window) {
  'use strict';

  function token() { return sessionStorage.getItem('kt_token') || localStorage.getItem('kt_token'); }
  function apiBase() { return (window.KT && window.KT.API_BASE) || 'https://api.kiddietrac.com/api/v1'; }
  function getUser() {
    try { return JSON.parse(sessionStorage.getItem('kt_user') || localStorage.getItem('kt_user') || '{}'); }
    catch (e) { return {}; }
  }

  async function api(method, path, body) {
    const opts = { method, headers: { 'Authorization': 'Bearer ' + token(), 'Accept': 'application/json' } };
    if (body !== undefined) {
      opts.headers['Content-Type'] = 'application/json';
      opts.body = JSON.stringify(body);
    }
    const res = await fetch(apiBase() + path, opts);
    const json = await res.json().catch(() => ({}));
    if (!res.ok) throw new Error(json.message || ('API ' + res.status));
    return json;
  }

  function esc(s) { return s == null ? '' : String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;'); }
  function $(s, r) { return (r || document).querySelector(s); }
  function $$(s, r) { return (r || document).querySelectorAll(s); }

  let activeCentreId = null;

  async function loadCentres() {
    // v22p1.1: agency_admin can hit /admin/centres; centre_director must use /director/centres (else 403).
    const u = (window.KT && window.KT.Auth && window.KT.Auth.user()) || {};
    const role = (u && (u.primary_role || (u.roles && u.roles[0]))) || '';
    const url = role === 'agency_admin' ? '/admin/centres' : '/director/centres';
    try {
      const res = await api('GET', url);
      return res.centres || [];
    } catch (e) {
      return [];
    }
  }

  async function render(container) {
    container.innerHTML = '<div style="padding:32px;text-align:center;color:#6B7280;">Loading…</div>';

    const centres = await loadCentres();
    if (centres.length === 0) {
      container.innerHTML = '<div style="padding:32px;color:#DC2626;">Could not load your centres. Are you signed in as a director/admin?</div>';
      return;
    }
    activeCentreId = activeCentreId || centres[0].id;

    let data;
    try {
      data = await api('GET', '/director/waitlist?centre_id=' + activeCentreId);
    } catch (e) {
      container.innerHTML = '<div style="padding:32px;color:#DC2626;">Could not load waitlist: ' + esc(e.message) + '</div>';
      return;
    }

    container.innerHTML = `
      <div style="padding:24px;max-width:1800px;">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:20px;gap:12px;flex-wrap:wrap;">
          <div>
            <h2 style="font-size:24px;margin:0;">⏳ Waitlist</h2>
            <p style="color:#6B7280;font-size:14px;margin:4px 0 0;">${data.total_waitlisted} on waitlist</p>
          </div>
          <div style="display:flex;gap:8px;align-items:center;">
            <select id="kt-centre-pick" style="padding:10px 12px;border:1px solid #D1D5DB;border-radius:8px;font-size:14px;">
              ${centres.map(c => `<option value="${c.id}" ${c.id===activeCentreId?'selected':''}>${esc(c.name)}</option>`).join('')}
            </select>
            <button id="kt-add-waitlist" style="background:#1F6080;color:white;border:none;padding:10px 20px;border-radius:8px;font-weight:700;cursor:pointer;">+ Add</button>
          </div>
        </div>

        ${data.waitlist.length === 0
          ? `<div style="text-align:center;padding:48px;background:white;border-radius:14px;color:#6B7280;">
              <div style="font-size:48px;margin-bottom:12px;">📋</div>
              No one on the waitlist for this centre.
            </div>`
          : `<div data-kt-list="1" style="background:white;border-radius:14px;overflow:visible;box-shadow:0 2px 8px rgba(0,0,0,.06);">
              ${data.waitlist.map(w => waitlistRow(w)).join('')}
            </div>`
        }
        <div id="kt-modal-mount"></div>
      </div>
    `;

    $('#kt-centre-pick', container).addEventListener('change', (e) => {
      activeCentreId = parseInt(e.target.value, 10);
      render(container);
    });
    $('#kt-add-waitlist', container).addEventListener('click', () => openAddModal(container));
    $$('.kt-promote', container).forEach(b => b.addEventListener('click', () => openPromoteModal(container, parseInt(b.dataset.cid, 10), b.dataset.name)));
    $$('.kt-decline', container).forEach(b => b.addEventListener('click', () => declineChild(container, parseInt(b.dataset.cid, 10), b.dataset.name)));
    $$('.kt-remind', container).forEach(b => b.addEventListener('click', () => openRemindModal(container, parseInt(b.dataset.cid, 10), b.dataset.name, b.dataset.email)));

    // Collapse each row's action icons into the standard ⋮ kebab (desktop). This
    // list re-renders in place (centre switch / after an action) with no hashchange,
    // so nudge the row-actions sweep directly rather than waiting for its timer.
    if (window.KT && typeof KT.sweepRowActions === 'function') setTimeout(KT.sweepRowActions, 0);
  }

  // Confirmation modal (dimmed backdrop) with a note field, before sending a "still on the
  // waitlist" reminder email to the family.
  function openRemindModal(container, childId, childName, email) {
    const mount = $('#kt-modal-mount', container);
    const hasEmail = !!(email && String(email).trim());
    mount.innerHTML = `
      <div class="kt-modal-overlay" style="position:fixed;inset:0;background:rgba(0,0,0,.5);display:flex;align-items:center;justify-content:center;z-index:9999;padding:20px;">
        <div style="background:#fff;border-radius:14px;max-width:460px;width:100%;padding:24px;box-shadow:0 20px 50px -12px rgba(15,23,42,.4);">
          <h3 style="margin:0 0 4px;color:#0F172A;font-size:18px;">✉️ Send waitlist reminder</h3>
          <p style="margin:0 0 14px;color:#64748B;font-size:13px;">Let <strong>${esc(childName)}</strong>'s family know they're still on the waitlist${hasEmail ? ' — email to ' + esc(email) : ''}.</p>
          ${hasEmail ? '' : '<div style="background:#FEF2F2;color:#B91C1C;padding:8px 12px;border-radius:8px;font-size:12.5px;margin-bottom:12px;">This family has no email on file — add one on their record first.</div>'}
          <label style="display:block;font-size:13px;font-weight:600;margin-bottom:6px;">Add a note (optional)</label>
          <textarea id="kt-remind-note" rows="4" placeholder="e.g. A space may open soon — please reply to confirm you're still interested." style="width:100%;padding:10px;border:1px solid #E2E8F0;border-radius:8px;font-family:inherit;font-size:13.5px;box-sizing:border-box;"></textarea>
          <div id="kt-remind-status" style="font-size:12.5px;min-height:16px;margin-top:8px;"></div>
          <div style="display:flex;gap:8px;justify-content:flex-end;margin-top:14px;">
            <button id="kt-remind-cancel" style="background:#F1F5F9;color:#475569;border:none;border-radius:9px;padding:9px 16px;font-weight:700;cursor:pointer;">Cancel</button>
            <button id="kt-remind-send" ${hasEmail ? '' : 'disabled'} style="background:#1D4ED8;color:#fff;border:none;border-radius:9px;padding:9px 18px;font-weight:700;cursor:${hasEmail ? 'pointer' : 'not-allowed'};${hasEmail ? '' : 'opacity:.5;'}">Send reminder</button>
          </div>
        </div>
      </div>`;
    const overlay = $('.kt-modal-overlay', container);
    const close = () => { mount.innerHTML = ''; };
    overlay.addEventListener('click', (e) => { if (e.target === overlay) close(); });
    $('#kt-remind-cancel', container).addEventListener('click', close);
    const sendBtn = $('#kt-remind-send', container);
    if (sendBtn && hasEmail) {
      sendBtn.addEventListener('click', async () => {
        const note = ($('#kt-remind-note', container).value || '').trim();
        const status = $('#kt-remind-status', container);
        const done = (window.KT && KT.busy) ? KT.busy(sendBtn) : function () {};
        sendBtn.disabled = true;
        try {
          await api('POST', '/director/waitlist/' + childId + '/remind', { note: note });
          status.style.color = '#047857'; status.textContent = '✓ Reminder sent.';
          setTimeout(close, 1200);
        } catch (e) {
          status.style.color = '#B91C1C'; status.textContent = '✗ ' + (e.message || 'Could not send.');
          sendBtn.disabled = false;
        } finally { done(); }
      });
    }
  }

  // Whole days, expressed in months once it's been a while (no more "12.34 days").
  function formatWait(days) {
    const d = Math.round(Number(days) || 0);
    if (d < 45) return d + (d === 1 ? ' day' : ' days');
    const m = Math.floor(d / 30.44);
    const rem = d - Math.round(m * 30.44);
    return m + ' mo' + (rem > 0 ? ' ' + rem + 'd' : '') + ' (' + d + ' days)';
  }

  function waitlistRow(w) {
    const age = w.age_months != null ? Math.floor(w.age_months) + ' mo' : '—';
    const wait = w.days_waiting != null ? formatWait(w.days_waiting) : '—';
    return `
      <div style="padding:14px 18px;border-bottom:1px solid #F3F4F6;display:grid;grid-template-columns:40px 1fr auto;gap:14px;align-items:center;">
        <div style="background:#1F6080;color:white;border-radius:50%;width:36px;height:36px;display:flex;align-items:center;justify-content:center;font-weight:700;">${w.position}</div>
        <div>
          <div style="font-weight:700;font-size:15px;color:#111827;">${esc(w.child_name)}</div>
          <div style="font-size:13px;color:#6B7280;display:flex;gap:14px;flex-wrap:wrap;margin-top:2px;">
            <span>${esc(w.family_name)}</span>
            <span>${esc(age)}</span>
            <span>Waiting: ${esc(wait)}</span>
            ${w.preferred_room_age_group ? `<span>Wants: ${esc(w.preferred_room_age_group)}</span>` : ''}
            ${w.expected_start_date ? `<span>Target: ${esc(w.expected_start_date)}</span>` : ''}
          </div>
          ${w.notes ? `<div style="font-size:12px;color:#6B7280;margin-top:4px;font-style:italic;">${esc(w.notes)}</div>` : ''}
        </div>
        <div style="display:flex;gap:6px;">
          <button class="kt-promote kt-act-icon kt-act-ok kt-icon-tip" data-cid="${w.id}" data-name="${esc(w.child_name)}" title="Enroll" data-kttip="Enroll" aria-label="Enroll">✅</button>
          <button class="kt-decline kt-act-icon kt-act-danger kt-icon-tip" data-cid="${w.id}" data-name="${esc(w.child_name)}" title="Decline" data-kttip="Decline" aria-label="Decline">✖️</button>
          <button class="kt-remind kt-act-icon kt-act-info kt-icon-tip" data-cid="${w.id}" data-name="${esc(w.child_name)}" data-email="${esc(w.family_email || '')}" title="Send waitlist reminder" data-kttip="Send waitlist reminder" aria-label="Send waitlist reminder">✉️</button>
        </div>
      </div>
    `;
  }

  async function openPromoteModal(container, childId, childName) {
    let rooms = [];
    try {
      const r = await api('GET', '/provider/bootstrap');
      rooms = (r.rooms || r.centre?.rooms || []).filter(rm => !activeCentreId || rm.centre_id === activeCentreId || true);
    } catch (e) {
      // Fallback: fetch directly. Many existing endpoints have rooms info.
    }

    const mount = $('#kt-modal-mount', container);
    mount.innerHTML = `
      <div class="kt-modal-overlay" style="position:fixed;inset:0;background:rgba(0,0,0,.5);display:flex;align-items:center;justify-content:center;z-index:9999;padding:16px;">
        <div style="background:white;border-radius:14px;max-width:480px;width:100%;padding:24px;">
          <h2 style="font-size:20px;margin:0 0 4px;">Enroll ${esc(childName)}</h2>
          <p style="color:#6B7280;font-size:13px;margin:0 0 18px;">Assign to a room and set start date.</p>
          <form id="kt-promote-form" onsubmit="return false;" style="display:grid;gap:12px;">
            <div>
              <label style="font-size:13px;font-weight:600;">Room *</label>
              <select name="room_id" required style="${inp()}">
                <option value="">— Pick a room —</option>
                ${rooms.map(r => `<option value="${r.id}">${esc(r.name)} (${esc(r.age_group||'')})</option>`).join('')}
              </select>
              ${rooms.length === 0 ? '<div style="font-size:12px;color:#DC2626;margin-top:4px;">No rooms found. Add a room first via Quick Add → Add Room.</div>' : ''}
            </div>
            <div>
              <label style="font-size:13px;font-weight:600;">Start date *</label>
              <input type="date" name="start_date" required style="${inp()}" value="${new Date().toISOString().split('T')[0]}">
            </div>
            <div>
              <label style="font-size:13px;font-weight:600;">Monthly fee (CAD) *</label>
              <input type="number" name="monthly_fee" required step="0.01" min="0" style="${inp()}" placeholder="e.g. 1200">
            </div>
            <label style="display:flex;align-items:center;gap:8px;font-size:14px;">
              <input type="checkbox" name="cwelcc_eligible" checked> CWELCC eligible
            </label>
            <div id="kt-status" style="min-height:20px;font-size:14px;"></div>
            <div style="display:flex;justify-content:flex-end;gap:8px;">
              <button type="button" id="kt-cancel" style="background:#F3F4F6;color:#374151;border:none;padding:10px 18px;border-radius:8px;font-weight:600;cursor:pointer;">Cancel</button>
              <button type="submit" id="kt-submit" style="background:#16A34A;color:white;border:none;padding:10px 20px;border-radius:8px;font-weight:700;cursor:pointer;">Enroll</button>
            </div>
          </form>
        </div>
      </div>
    `;
    const overlay = $('.kt-modal-overlay', mount);
    const close = () => mount.innerHTML = '';
    overlay.addEventListener('click', (e) => { if (e.target === overlay) close(); });
    $('#kt-cancel', mount).addEventListener('click', close);

    $('#kt-promote-form', mount).addEventListener('submit', async (e) => {
      e.preventDefault();
      const data = {};
      new FormData(e.target).forEach((v, k) => data[k] = v);
      data.cwelcc_eligible = e.target.querySelector('[name="cwelcc_eligible"]').checked;
      data.monthly_fee = parseFloat(data.monthly_fee);
      data.room_id = parseInt(data.room_id, 10);
      try {
        await api('POST', '/director/waitlist/' + childId + '/promote', data);
        $('#kt-status', mount).style.color = '#16A34A';
        $('#kt-status', mount).textContent = '✓ Enrolled successfully';
        setTimeout(() => { close(); render(container); }, 1000);
      } catch (e) {
        $('#kt-status', mount).style.color = '#DC2626';
        $('#kt-status', mount).textContent = '✗ ' + e.message;
      }
    });
  }

  async function declineChild(container, childId, childName) {
    const reason = prompt('Decline ' + childName + '? Optional reason for the audit log:');
    if (reason === null) return;
    try {
      await api('POST', '/director/waitlist/' + childId + '/decline', { reason: reason || '' });
      render(container);
    } catch (e) { alert('Could not decline: ' + e.message); }
  }

  function openAddModal(container) {
    const mount = $('#kt-modal-mount', container);
    mount.innerHTML = `
      <div class="kt-modal-overlay" style="position:fixed;inset:0;background:rgba(0,0,0,.5);display:flex;align-items:center;justify-content:center;z-index:9999;padding:16px;">
        <div style="background:white;border-radius:14px;max-width:520px;width:100%;padding:24px;max-height:90vh;overflow-y:auto;">
          <h2 style="font-size:20px;margin:0 0 4px;">Add to waitlist</h2>
          <p style="color:#6B7280;font-size:13px;margin:0 0 18px;">For walk-in applications or phone inquiries.</p>
          <form id="kt-add-form" onsubmit="return false;" style="display:grid;gap:10px;">
            <h3 style="font-size:12px;text-transform:uppercase;letter-spacing:1px;color:#6B7280;margin:8px 0 0;">Family</h3>
            <input name="family_name" required placeholder="Family name *" style="${inp()}">
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;">
              <input name="primary_email" type="email" placeholder="Email" style="${inp()}">
              <input name="primary_phone" type="tel" placeholder="Phone" style="${inp()}">
            </div>
            <h3 style="font-size:12px;text-transform:uppercase;letter-spacing:1px;color:#6B7280;margin:8px 0 0;">Child</h3>
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;">
              <input name="child_first_name" required placeholder="First name *" style="${inp()}">
              <input name="child_last_name" required placeholder="Last name *" style="${inp()}">
            </div>
            <label style="font-size:13px;font-weight:600;">Date of birth *</label>
            <input name="date_of_birth" type="date" required style="${inp()}">
            <label style="font-size:13px;font-weight:600;">Expected start date</label>
            <input name="expected_start_date" type="date" style="${inp()}">
            <label style="font-size:13px;font-weight:600;">Preferred room</label>
            <select name="preferred_room_age_group" style="${inp()}">
              <option value="">— Any —</option>
              <option>infant</option><option>toddler</option><option>preschool</option><option>kindergarten</option><option>school_age</option>
            </select>
            <textarea name="notes" placeholder="Notes (optional)" rows="3" style="${inp()};font-family:inherit;"></textarea>
            <div id="kt-status" style="min-height:20px;font-size:14px;"></div>
            <div style="display:flex;justify-content:flex-end;gap:8px;">
              <button type="button" id="kt-cancel" style="background:#F3F4F6;color:#374151;border:none;padding:10px 18px;border-radius:8px;font-weight:600;cursor:pointer;">Cancel</button>
              <button type="submit" style="background:#1F6080;color:white;border:none;padding:10px 20px;border-radius:8px;font-weight:700;cursor:pointer;">Add</button>
            </div>
          </form>
        </div>
      </div>
    `;
    const overlay = $('.kt-modal-overlay', mount);
    const close = () => mount.innerHTML = '';
    overlay.addEventListener('click', (e) => { if (e.target === overlay) close(); });
    $('#kt-cancel', mount).addEventListener('click', close);

    $('#kt-add-form', mount).addEventListener('submit', async (e) => {
      e.preventDefault();
      const data = { centre_id: activeCentreId };
      new FormData(e.target).forEach((v, k) => { if (v !== '') data[k] = v; });
      try {
        await api('POST', '/director/waitlist', data);
        $('#kt-status', mount).style.color = '#16A34A';
        $('#kt-status', mount).textContent = '✓ Added';
        setTimeout(() => { close(); render(container); }, 800);
      } catch (e) {
        $('#kt-status', mount).style.color = '#DC2626';
        $('#kt-status', mount).textContent = '✗ ' + e.message;
      }
    });
  }

  function inp() { return 'width:100%;padding:10px 12px;border:1px solid #D1D5DB;border-radius:8px;font-size:14px;font-family:inherit;'; }

  window.KT = window.KT || {};
  window.KT.Waitlist = { render };
})(window);
