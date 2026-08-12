/* ═══════════════════════════════════════════════════════════════════
   KiddieTrac — Waitlist (lightweight leads/enquiries).
   Tenant-neutral: some entries may be SYNCED from a connected external system
   (read-only here); entries added here can flow back to that system. No tenant
   is named in the UI. GET/POST/PATCH/DELETE /agency/waitlist. Admins + directors.
   ═══════════════════════════════════════════════════════════════════ */
(function (window) {
  'use strict';
  var KT = (window.KT = window.KT || {});
  var Shell = KT.Shell;
  var Api = KT.Api;

  function esc(s) { return String(s == null ? '' : s).replace(/[&<>"]/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]; }); }
  function fmtDate(s) {
    if (!s) return '';
    var d = new Date(String(s).replace(' ', 'T'));
    return isNaN(d.getTime()) ? esc(s) : d.toLocaleDateString('en-CA', { year: 'numeric', month: 'short', day: 'numeric' });
  }
  function originBadge(o) {
    var kt = o === 'kiddietrac';
    return '<span style="font-size:10px;font-weight:800;letter-spacing:.3px;padding:2px 8px;border-radius:999px;'
      + (kt ? 'background:#F1F5F9;color:#475569;' : 'background:#E0F2FE;color:#075985;') + '">'
      + (kt ? 'Added here' : 'Synced') + '</span>';
  }
  function prioBadge(p) {
    var m = { High: { b: '#FEE2E2', f: '#991B1B' }, Normal: { b: '#F1F5F9', f: '#475569' }, Low: { b: '#F1F5F9', f: '#94A3B8' } };
    var c = m[p] || m.Normal;
    return '<span style="font-size:10.5px;font-weight:800;padding:2px 8px;border-radius:999px;background:' + c.b + ';color:' + c.f + ';">' + esc(p || 'Normal') + '</span>';
  }
  function statusBadge(s) {
    var m = { Waiting: { b: '#F1F5F9', f: '#475569' }, Contacted: { b: '#E0F2FE', f: '#075985' }, Approved: { b: '#DCFCE7', f: '#166534' }, Enrolled: { b: '#D1FAE5', f: '#065F46' }, Declined: { b: '#FEE2E2', f: '#991B1B' } };
    var c = m[s] || m.Waiting;
    return '<span style="font-size:10.5px;font-weight:800;padding:2px 9px;border-radius:999px;background:' + c.b + ';color:' + c.f + ';">' + esc(s || 'Waiting') + '</span>';
  }

  function setStatus(container, id, status) {
    Api.patch('/agency/waitlist/' + id + '/status', { status: status }).then(function () {
      load(container);
      if (KT.toast) KT.toast(status === 'Approved' ? '✅' : (status === 'Declined' ? '🚫' : '↩'), status, 'Lead marked ' + status.toLowerCase() + '.', status === 'Declined' ? '#DC2626' : '#16A34A');
    }).catch(function (err) { if (KT.toast) KT.toast('⚠️', 'Error', (err && err.message) || 'Could not update.', '#DC2626'); });
  }
  function delEntry(container, id) {
    Api.delete('/agency/waitlist/' + id).then(function () { load(container); if (KT.toast) KT.toast('🗑', 'Deleted', 'Waitlist entry removed.', '#64748B'); }).catch(function () {});
  }
  function enrollLead(container, e) {
    if (!e) return;
    if (e.status === 'Enrolled') { if (KT.toast) KT.toast('ℹ️', 'Already enrolled', 'This lead is already enrolled.', '#64748B'); return; }
    var who = e.parent_name || 'this parent';
    if (!window.confirm('Enrol ' + who + '?\n\nThis creates a family' + (e.child_name ? ' and child (' + e.child_name + ')' : '') + ' in KiddieTrac and starts the enrolment process.')) return;
    Api.post('/agency/waitlist/' + e.id + '/enroll', {}).then(function (r) {
      load(container);
      if (KT.toast) KT.toast('🎓', 'Enrolled', 'Family created — enrolment started.', '#16A34A');
      if (r && r.family_id && window.confirm('Family created. Open the Families list now?')) { location.hash = '#families'; }
    }).catch(function (err) { if (KT.toast) KT.toast('⚠️', 'Could not enrol', (err && err.message) || 'Error.', '#DC2626'); });
  }
  function openReachOut(container, e) {
    if (!e) return;
    if (!e.email) { if (KT.toast) KT.toast('⚠️', 'No email', 'This lead has no email address on file.', '#DC2626'); return; }
    var ov = document.createElement('div');
    ov.style.cssText = 'position:fixed;inset:0;background:rgba(15,23,42,.55);z-index:10000;display:flex;align-items:flex-start;justify-content:center;padding:32px 18px;overflow:auto;';
    var m = document.createElement('div');
    m.style.cssText = 'background:#fff;border-radius:16px;max-width:520px;width:100%;box-shadow:0 24px 60px rgba(0,0,0,.35);';
    var firstName = (e.parent_name || '').split(' ')[0] || 'there';
    m.innerHTML = '<div style="padding:18px 22px;border-bottom:1px solid #E5E7EB;"><h3 style="margin:0;font-size:17px;">📣 Reach out to ' + esc(e.parent_name || 'lead') + '</h3>'
      + '<div style="font-size:12px;color:#64748B;margin-top:2px;">Sends a branded email to ' + esc(e.email) + ' and marks them Contacted.</div></div>'
      + '<div style="padding:18px 22px;">'
      + '<label style="display:block;font-size:12.5px;font-weight:700;color:#334155;margin-bottom:9px;">Subject<input id="ro-subject" type="text" placeholder="An update on your waitlist enquiry" style="width:100%;margin-top:4px;padding:9px 11px;border:1px solid #E2E8F0;border-radius:9px;font-size:14px;box-sizing:border-box;"></label>'
      + '<label style="display:block;font-size:12.5px;font-weight:700;color:#334155;">Message<textarea id="ro-msg" rows="6" placeholder="Hi ' + esc(firstName) + ',&#10;&#10;We wanted to give you an update on your spot on our waitlist…" style="width:100%;margin-top:4px;padding:9px 11px;border:1px solid #E2E8F0;border-radius:9px;font-size:14px;box-sizing:border-box;font-family:inherit;"></textarea></label>'
      + '<div id="ro-err" style="color:#DC2626;font-size:12.5px;min-height:16px;margin-top:6px;"></div>'
      + '<div style="display:flex;gap:10px;justify-content:flex-end;">'
      + '<button id="ro-cancel" style="padding:9px 16px;border:1px solid #E2E8F0;border-radius:9px;background:#fff;font-weight:700;cursor:pointer;">Cancel</button>'
      + '<button id="ro-send" class="kt-btn kt-btn-primary" style="padding:9px 18px;">Send update</button></div></div>';
    ov.appendChild(m); document.body.appendChild(ov);
    var close = function () { ov.remove(); };
    ov.addEventListener('click', function (ev) { if (ev.target === ov) close(); });
    m.querySelector('#ro-cancel').addEventListener('click', close);
    m.querySelector('#ro-send').addEventListener('click', function () {
      var msg = (m.querySelector('#ro-msg').value || '').trim();
      if (!msg) { m.querySelector('#ro-err').textContent = 'Please write a message.'; return; }
      var btn = m.querySelector('#ro-send'); btn.disabled = true; btn.textContent = 'Sending…';
      Api.post('/agency/waitlist/' + e.id + '/reach-out', { message: msg, subject: (m.querySelector('#ro-subject').value || '').trim() || null }).then(function () {
        close(); load(container); if (KT.toast) KT.toast('📣', 'Sent', 'Update emailed to the parent.', '#16A34A');
      }).catch(function (err) { btn.disabled = false; btn.textContent = 'Send update'; m.querySelector('#ro-err').textContent = (err && err.message) || 'Could not send.'; });
    });
  }
  function openKebab(container, e, btn) {
    if (!e) return;
    var old = document.getElementById('wl-kebab-menu'); if (old) old.remove();
    var menu = document.createElement('div'); menu.id = 'wl-kebab-menu';
    menu.style.cssText = 'position:fixed;z-index:10060;background:#fff;border:1px solid #E2E8F0;border-radius:11px;box-shadow:0 14px 34px rgba(15,23,42,.2);min-width:160px;overflow:hidden;font-size:13.5px;';
    var items = [
      { label: '✏️  Edit', fn: function () { openForm(container, e); } },
      { label: '✅  Approve', fn: function () { setStatus(container, e.id, 'Approved'); } },
      { label: '🚫  Decline', fn: function () { setStatus(container, e.id, 'Declined'); } },
      { label: '🗑  Delete', danger: true, fn: function () { if (window.confirm('Delete this waitlist entry?')) delEntry(container, e.id); } }
    ];
    menu.innerHTML = items.map(function (it, i) { return '<div class="wl-mi" data-i="' + i + '" style="padding:10px 14px;cursor:pointer;font-weight:600;color:' + (it.danger ? '#DC2626' : '#334155') + ';' + (i ? 'border-top:1px solid #F1F5F9;' : '') + '">' + it.label + '</div>'; }).join('');
    document.body.appendChild(menu);
    var r = btn.getBoundingClientRect();
    menu.style.top = Math.min(window.innerHeight - menu.offsetHeight - 8, r.bottom + 4) + 'px';
    menu.style.left = Math.max(8, r.right - menu.offsetWidth) + 'px';
    menu.querySelectorAll('.wl-mi').forEach(function (mi) {
      mi.addEventListener('mouseenter', function () { mi.style.background = '#F8FAFC'; });
      mi.addEventListener('mouseleave', function () { mi.style.background = ''; });
      mi.addEventListener('click', function () { var idx = +mi.getAttribute('data-i'); menu.remove(); items[idx].fn(); });
    });
    var closer = function () { if (menu.parentNode) menu.remove(); document.removeEventListener('click', closer); };
    setTimeout(function () { document.addEventListener('click', closer); }, 0);
  }

  function statTile(label, value, tint, ink) {
    return '<div style="background:' + tint + ';border:1px solid rgba(15,23,42,.06);border-radius:14px;padding:14px 16px;">'
      + '<div style="font-size:26px;font-weight:900;color:' + ink + ';line-height:1;">' + value + '</div>'
      + '<div style="font-size:10.5px;font-weight:800;letter-spacing:.5px;text-transform:uppercase;color:' + ink + ';opacity:.72;margin-top:5px;">' + esc(label) + '</div></div>';
  }

  function field(label, id, type, val, req) {
    return '<label style="display:block;font-size:12.5px;font-weight:700;color:#334155;margin-bottom:9px;">' + esc(label) + (req ? ' <span style="color:#DC2626;">*</span>' : '')
      + '<input id="' + id + '" type="' + (type || 'text') + '" value="' + esc(val || '') + '" style="width:100%;margin-top:4px;padding:9px 11px;border:1px solid #E2E8F0;border-radius:9px;font-size:14px;box-sizing:border-box;"></label>';
  }

  function openForm(container, existing) {
    var e = existing || {};
    var ov = document.createElement('div');
    ov.style.cssText = 'position:fixed;inset:0;background:rgba(15,23,42,.55);z-index:10000;display:flex;align-items:flex-start;justify-content:center;padding:32px 18px;overflow:auto;';
    var m = document.createElement('div');
    m.style.cssText = 'background:#fff;border-radius:16px;max-width:520px;width:100%;box-shadow:0 24px 60px rgba(0,0,0,.35);';
    m.innerHTML = '<div style="padding:18px 22px;border-bottom:1px solid #E5E7EB;"><h3 style="margin:0;font-size:17px;">' + (existing ? 'Edit waitlist entry' : 'Add to waitlist') + '</h3>'
      + '<div style="font-size:12px;color:#64748B;margin-top:2px;">New entries sync to a connected system automatically, if one is set up.</div></div>'
      + '<div style="padding:18px 22px;">'
      + field('Parent name', 'wl-parent', 'text', e.parent_name, true)
      + field('Child name', 'wl-child', 'text', e.child_name)
      + '<div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">' + field('Email', 'wl-email', 'email', e.email) + field('Phone', 'wl-phone', 'text', e.phone) + '</div>'
      + '<div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">' + field('Desired start', 'wl-start', 'date', e.desired_start)
      + '<label style="display:block;font-size:12.5px;font-weight:700;color:#334155;margin-bottom:9px;">Priority<select id="wl-prio" style="width:100%;margin-top:4px;padding:9px 11px;border:1px solid #E2E8F0;border-radius:9px;font-size:14px;box-sizing:border-box;background:#fff;">'
      + ['High', 'Normal', 'Low'].map(function (p) { return '<option ' + ((e.priority || 'Normal') === p ? 'selected' : '') + '>' + p + '</option>'; }).join('') + '</select></label></div>'
      + '<label style="display:block;font-size:12.5px;font-weight:700;color:#334155;">Notes<textarea id="wl-notes" rows="3" style="width:100%;margin-top:4px;padding:9px 11px;border:1px solid #E2E8F0;border-radius:9px;font-size:14px;box-sizing:border-box;font-family:inherit;">' + esc(e.notes || '') + '</textarea></label>'
      + '<div id="wl-err" style="color:#DC2626;font-size:12.5px;min-height:16px;margin-top:6px;"></div>'
      + '<div style="display:flex;gap:10px;justify-content:flex-end;margin-top:8px;">'
      + '<button id="wl-cancel" style="padding:9px 16px;border:1px solid #E2E8F0;border-radius:9px;background:#fff;font-weight:700;cursor:pointer;">Cancel</button>'
      + '<button id="wl-save" class="kt-btn kt-btn-primary" style="padding:9px 18px;">' + (existing ? 'Save' : 'Add') + '</button></div></div>';
    ov.appendChild(m); document.body.appendChild(ov);
    var close = function () { ov.remove(); };
    ov.addEventListener('click', function (ev) { if (ev.target === ov) close(); });
    m.querySelector('#wl-cancel').addEventListener('click', close);
    m.querySelector('#wl-save').addEventListener('click', function () {
      var body = {
        parent_name: (m.querySelector('#wl-parent').value || '').trim(),
        child_name: (m.querySelector('#wl-child').value || '').trim() || null,
        email: (m.querySelector('#wl-email').value || '').trim() || null,
        phone: (m.querySelector('#wl-phone').value || '').trim() || null,
        desired_start: m.querySelector('#wl-start').value || null,
        priority: m.querySelector('#wl-prio').value,
        notes: (m.querySelector('#wl-notes').value || '').trim() || null,
      };
      if (!body.parent_name) { m.querySelector('#wl-err').textContent = 'Parent name is required.'; return; }
      var btn = m.querySelector('#wl-save'); btn.disabled = true; btn.textContent = 'Saving…';
      var p = existing ? Api.patch('/agency/waitlist/' + existing.id, body) : Api.post('/agency/waitlist', body);
      p.then(function () { close(); load(container); if (KT.toast) KT.toast('✅', 'Saved', 'Waitlist entry saved.', '#16A34A'); })
        .catch(function (err) { btn.disabled = false; btn.textContent = existing ? 'Save' : 'Add'; m.querySelector('#wl-err').textContent = (err && err.message) || 'Could not save.'; });
    });
  }

  function load(container) {
    var body = container.querySelector('#wl-body');
    if (body) body.innerHTML = '<div style="padding:34px;text-align:center;color:#94A3B8;">Loading…</div>';
    Api.get('/agency/waitlist').then(function (d) {
      var entries = d.entries || [], s = d.stats || {};
      var statsRow = container.querySelector('#wl-stats');
      if (statsRow) statsRow.innerHTML =
        statTile('On waitlist', s.total || 0, '#EFF6FF', '#1D4ED8')
        + statTile('Approved', s.approved || 0, '#F0FDF4', '#15803D')
        + statTile('Declined', s.declined || 0, '#FEF2F2', '#B91C1C')
        + statTile('Added here', s.kt || 0, '#F1F5F9', '#475569');
      if (!entries.length) { body.innerHTML = '<div class="kt-card" style="text-align:center;color:#64748B;padding:40px;">No waitlist entries yet. Add one to get started.</div>'; return; }
      var th = 'text-align:left;padding:10px 12px;font-size:10.5px;font-weight:800;color:#64748B;text-transform:uppercase;letter-spacing:.5px;white-space:nowrap;';
      var td = 'padding:10px 12px;font-size:13px;color:#334155;border-top:1px solid #F1F5F9;vertical-align:middle;';
      body.innerHTML = '<div class="kt-card" style="padding:0;overflow:hidden;"><div style="overflow-x:auto;"><table style="width:100%;border-collapse:collapse;min-width:820px;">'
        + '<thead><tr style="background:#F8FAFC;">' + ['Parent', 'Child', 'Contact', 'Desired start', 'Priority', 'Status', 'Source', ''].map(function (h) { return '<th style="' + th + '">' + h + '</th>'; }).join('') + '</tr></thead><tbody>'
        + entries.map(function (e) {
          var contact = [e.email, e.phone].filter(Boolean).map(esc).join('<br>');
          return '<tr>'
            + '<td style="' + td + 'font-weight:700;color:#0F172A;">' + esc(e.parent_name || '—') + '</td>'
            + '<td style="' + td + '">' + (e.child_name ? esc(e.child_name) : '<span style="color:#94A3B8;">(no child yet)</span>') + '</td>'
            + '<td style="' + td + 'font-size:12px;color:#64748B;">' + (contact || '—') + '</td>'
            + '<td style="' + td + 'white-space:nowrap;">' + (fmtDate(e.desired_start) || '—') + '</td>'
            + '<td style="' + td + '">' + prioBadge(e.priority) + '</td>'
            + '<td style="' + td + '">' + statusBadge(e.status) + '</td>'
            + '<td style="' + td + '">' + originBadge(e.origin) + '</td>'
            + '<td style="' + td + 'white-space:nowrap;text-align:right;">'
              + '<button type="button" class="wl-act" data-id="' + e.id + '" data-act="edit" style="border:none;background:none;cursor:pointer;color:#334155;font-weight:600;font-size:12.5px;padding:3px 7px;">✏️ Edit</button>'
              + '<button type="button" class="wl-act" data-id="' + e.id + '" data-act="reachout" style="border:none;background:none;cursor:pointer;color:#1D4ED8;font-weight:600;font-size:12.5px;padding:3px 7px;">📣 Reach out</button>'
              + '<button type="button" class="wl-act" data-id="' + e.id + '" data-act="enroll" style="border:none;background:none;cursor:pointer;color:#065F46;font-weight:600;font-size:12.5px;padding:3px 7px;">🎓 Enrol parent</button>'
              + '<button type="button" class="wl-act" data-id="' + e.id + '" data-act="approve" style="border:none;background:none;cursor:pointer;color:#166534;font-weight:600;font-size:12.5px;padding:3px 7px;">✅ Approve</button>'
              + '<button type="button" class="wl-act" data-id="' + e.id + '" data-act="decline" style="border:none;background:none;cursor:pointer;color:#B45309;font-weight:600;font-size:12.5px;padding:3px 7px;">🚫 Decline</button>'
              + '<button type="button" class="wl-act" data-id="' + e.id + '" data-act="delete" style="border:none;background:none;cursor:pointer;color:#DC2626;font-weight:600;font-size:12.5px;padding:3px 7px;">🗑 Delete</button>'
            + '</td></tr>';
        }).join('') + '</tbody></table></div></div>';
      body.querySelectorAll('.wl-act').forEach(function (b) {
        b.addEventListener('click', function () {
          var e = entries.find(function (x) { return String(x.id) === b.getAttribute('data-id'); });
          if (!e) return;
          var act = b.getAttribute('data-act');
          if (act === 'edit') openForm(container, e);
          else if (act === 'reachout') openReachOut(container, e);
          else if (act === 'enroll') enrollLead(container, e);
          else if (act === 'approve') setStatus(container, e.id, 'Approved');
          else if (act === 'decline') setStatus(container, e.id, 'Declined');
          else if (act === 'delete') { if (window.confirm('Delete this waitlist entry?')) delEntry(container, e.id); }
        });
      });
    }).catch(function (e) { body.innerHTML = '<div class="kt-card" style="color:#DC2626;padding:24px;text-align:center;">Could not load: ' + esc(e.message || 'error') + '</div>'; });
  }

  function render(container) {
    container.setAttribute('data-kt-pretty', '1');
    container.innerHTML = '<div style="padding:24px;max-width:1200px;margin:0 auto;">'
      + '<div class="kt-page-hero" style="display:flex;justify-content:space-between;align-items:flex-start;gap:16px;flex-wrap:wrap;"><div><h2>⏳ Waitlist</h2><p>Track and manage waitlist leads and enquiries.</p></div>'
      + '<button id="wl-add" class="kt-btn kt-btn-primary" style="white-space:nowrap;">＋ Add to waitlist</button></div>'
      + '<div id="wl-stats" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:12px;margin-bottom:16px;"></div>'
      + '<div id="wl-body"></div></div>';
    container.querySelector('#wl-add').addEventListener('click', function () { openForm(container, null); });
    load(container);
  }

  if (Shell && Shell.registerScreen) {
    ['agency_admin', 'centre_director', 'platform_admin'].forEach(function (r) { Shell.registerScreen(r + ':synced-waitlist', render); });
  }
  KT.SyncedWaitlist = { render: render };
})(window);
