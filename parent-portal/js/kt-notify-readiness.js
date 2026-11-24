/* ═══════════════════════════════════════════════════════════════════
   KiddieTrac — STAFF NOTIFICATIONS READINESS (2026-10-31).

   Who on the team can actually receive app notifications (App\Support\StaffNotifyRequirement::readiness):
   ✓ the app on their phone is registered, ⚠ only a browser, ⚠ nothing at all - with the phone model, when it
   was last active, and how the last notification we tried to send them went. Filters, export (it is a plain
   table, so the portal's export bar attaches) and, for agency admins, "Send setup instructions" (email + bell),
   which always asks first and lists exactly who would get it.
   Mounted on Agency overview and in Settings → Agency notifications → Required for staff.
   ═══════════════════════════════════════════════════════════════════ */
(function () {
  'use strict';
  if (window.KT && KT.notifyReadiness) return;
  window.KT = window.KT || {};

  function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }
  var ROLE = { educator: 'Educator', centre_director: 'Director', home_visitor: 'Home visitor', agency_admin: 'Agency admin' };
  var ST = {
    ready: ['✓ Phone app on', '#166534', '#DCFCE7'],
    app_off: ['⚠ App on, notifications off', '#9A3412', '#FFEDD5'],   // the app is on the phone, notifications not allowed (2026-11-02)
    web_only: ['⚠ Browser only', '#92400E', '#FEF3C7'],
    none: ['⚠ Not set up', '#991B1B', '#FEE2E2'],
  };
  var OUT = { sent: 'delivered', no_device: 'nowhere to send', failed: 'failed' };

  function ago(iso) {
    if (!iso) return '—';
    var t = new Date(String(iso).replace(' ', 'T') + (/[zZ]|[+-]\d\d:?\d\d$/.test(iso) ? '' : 'Z')).getTime();
    if (!t) return '—';
    var m = Math.round((Date.now() - t) / 60000);
    if (m < 2) return 'just now';
    if (m < 60) return m + ' min ago';
    var h = Math.round(m / 60); if (h < 36) return h + 'h ago';
    var d = Math.round(h / 24); return d + ' day' + (d === 1 ? '' : 's') + ' ago';
  }

  /* App version, phone, OS and what the device lets the app do (2026-10-31, Anthony: "add a column to show what version
     of the app they are on, type of phone, android version as well" + "the type of permissions set to use the KiddieTrac
     app"). Labels come from the server (App\\Support\\DeviceLabel); permissions from kt-device-perms.js reports. */
  var PERM = [['notifications', '🔔', 'Notifications'], ['location', '📍', 'Location'], ['camera', '📷', 'Camera'],
    ['microphone', '🎙️', 'Microphone'], ['photos', '🖼️', 'Photos'], ['biometric_enrolled', '🔐', 'Biometric unlock']];
  var PV = {
    granted: ['allowed', '#166534', '#DCFCE7'], yes: ['on', '#166534', '#DCFCE7'],
    limited: ['limited', '#92400E', '#FEF3C7'],
    denied: ['denied', '#991B1B', '#FEE2E2'], no: ['off', '#475569', '#F1F5F9'],
    prompt: ['not asked', '#475569', '#F1F5F9'], unknown: ['unknown', '#64748B', '#F8FAFC'], unavailable: ['not on this device', '#64748B', '#F8FAFC'],
  };
  function permChips(dev) {
    if (!dev) return '<span class="kt-nr-sub">—</span>';
    var pm = dev.perms;
    if (!pm) return '<span class="kt-nr-sub">not reported yet</span>';
    return '<div class="kt-nr-perms">' + PERM.map(function (k) {
      var v = pm[k[0]];
      if (!v) return '';
      var lab = (PV[v] || PV.unknown)[0];
      if (k[0] === 'location' && v === 'granted' && pm.location_bg === 'granted') lab = 'always';
      else if (k[0] === 'location' && v === 'granted') lab = 'while using';
      var c = (k[0] === 'location' && v === 'granted' && pm.location_bg !== 'granted') ? PV.limited : (PV[v] || PV.unknown);
      return '<span class="kt-nr-perm" title="' + esc(k[2] + ': ' + lab) + '" style="color:' + c[1] + ';background:' + c[2] + ';">' + k[1] + ' ' + esc(k[2]) + ': ' + esc(lab) + '</span>';
    }).join('') + '</div>' + (dev.perms_checked_at ? '<div class="kt-nr-sub">checked ' + esc(ago(dev.perms_checked_at)) + '</div>' : '');
  }
  function devWord(dv) {
    return dv.platform === 'web' ? 'Browser' : (dv.platform === 'ios' ? 'iPhone app' : 'Android app');
  }
  function allDevices(list) {
    return list.map(function (dv) {
      return '<div class="kt-nr-dev"><b>' + esc(dv.model || devWord(dv)) + '</b> · ' + esc(dv.os || '—') + ' · ' + esc(dv.app_version || devWord(dv))
        + ' <span class="kt-nr-sub">· active ' + esc(ago(dv.last_active_at)) + '</span></div>';
    }).join('');
  }

  function css() {
    if (document.getElementById('kt-nr-css')) return;
    var st = document.createElement('style'); st.id = 'kt-nr-css';
    st.textContent = '.kt-nr-sum{display:flex;flex-wrap:wrap;gap:8px;margin:0 0 10px;}'
      + '.kt-nr-chip{display:inline-flex;align-items:center;gap:6px;padding:5px 11px;border-radius:999px;font-size:12.5px;font-weight:800;}'
      + '.kt-nr-pill{display:inline-block;padding:2px 9px;border-radius:999px;font-size:11.5px;font-weight:800;white-space:nowrap;}'
      + '.kt-nr-tbl{width:100%;border-collapse:collapse;font-size:13px;}'
      + '.kt-nr-tbl th{text-align:left;font-size:11px;font-weight:800;letter-spacing:.04em;text-transform:uppercase;color:#64748B;padding:8px 10px;border-bottom:1px solid #E2E8F0;white-space:nowrap;}'
      + '.kt-nr-tbl td{padding:8px 10px;border-bottom:1px solid #F1F5F9;vertical-align:top;color:#0F172A;}'
      + '.kt-nr-sub{font-size:11.5px;color:#64748B;}'
      + '.kt-nr-req{font-size:10.5px;font-weight:800;color:#0E7C90;margin-left:6px;}'
      + '.kt-nr-perms{display:flex;flex-wrap:wrap;gap:4px;max-width:340px;}'
      + '.kt-nr-perm{display:inline-block;padding:2px 7px;border-radius:999px;font-size:11px;font-weight:700;white-space:nowrap;}'
      + '.kt-nr-upd{display:inline-block;margin-left:6px;padding:1px 7px;border-radius:999px;font-size:10.5px;font-weight:800;color:#92400E;background:#FEF3C7;white-space:nowrap;}'
      + '#appMain .kt-nr-more,.kt-nr-more{border:0!important;background:transparent!important;color:#0E7C90;font-weight:800;font-size:11.5px;cursor:pointer;padding:2px 0!important;min-height:0!important;height:auto!important;box-shadow:none!important;}'
      + '.kt-nr-devs{display:none;margin-top:4px;font-size:12px;line-height:1.5;}'
      + '.kt-nr-devs.on{display:block;}'
      + '@media (max-width:760px){.kt-nr-tbl thead{display:none}.kt-nr-tbl,.kt-nr-tbl tbody,.kt-nr-tbl tr,.kt-nr-tbl td{display:block;width:100%;}'
      + '.kt-nr-tbl tr{border:1px solid #E2E8F0;border-radius:12px;padding:6px 4px;margin:0 0 8px;background:#fff;}'
      + '.kt-nr-tbl td{border:0;padding:4px 10px;}.kt-nr-tbl td[data-label]:before{content:attr(data-label);display:block;font-size:10.5px;font-weight:800;letter-spacing:.04em;text-transform:uppercase;color:#64748B;}'
      + '.kt-nr-perms{max-width:none;}}';
    document.head.appendChild(st);
  }

  async function mount(host, opts) {
    opts = opts || {};
    css();
    host.innerHTML = '<div style="padding:12px 16px;color:#64748B;font-size:13px;">Loading…</div>';
    var d;
    try { d = await KT.Api.get('/admin/notify-readiness'); } catch (e) { if (opts.hideOnError) { host.closest('[data-kt-nr-section]') && (host.closest('[data-kt-nr-section]').style.display = 'none'); } else { host.innerHTML = '<div style="padding:12px 16px;color:#B91C1C;">' + esc((e && e.message) || 'Could not load') + '</div>'; } return; }
    var people = d.people || [], c = d.counts || {}, s = d.settings || {};
    var filter = 'all';

    function rows() {
      return people.filter(function (p) { return filter === 'all' || p.status === filter; });
    }
    function paint() {
      var list = rows();
      var req = s.required ? '<div style="font-size:12.5px;color:#334155;margin:0 0 8px;">Required for: <b>' + esc((s.roles || []).map(function (r) { return ROLE[r] || r; }).join(', ') || 'nobody') + '</b>'
          + (c.required_missing ? ' · <b style="color:#991B1B;">' + c.required_missing + '</b> still need to turn them on — they see a step in the app until they do.' : ' · everyone required is set up.') + '</div>'
        : '<div style="font-size:12.5px;color:#64748B;margin:0 0 8px;">Not required yet — turn it on in Settings → Agency notifications → Required for staff.</div>';
      host.innerHTML = req
        + '<div class="kt-nr-sum">'
        + '<span class="kt-nr-chip" style="color:#166534;background:#DCFCE7;">✓ ' + (c.ready || 0) + ' phone app on</span>'
        + (c.app_off ? '<span class="kt-nr-chip" style="color:#9A3412;background:#FFEDD5;">⚠ ' + c.app_off + ' app on, notifications off</span>' : '')
        + '<span class="kt-nr-chip" style="color:#92400E;background:#FEF3C7;">⚠ ' + (c.web_only || 0) + ' browser only</span>'
        + '<span class="kt-nr-chip" style="color:#991B1B;background:#FEE2E2;">⚠ ' + (c.none || 0) + ' not set up</span></div>'
        + '<div role="tablist" aria-label="Filter" style="display:flex;gap:4px;margin:0 0 8px;">'
        + [['all', 'All', c.total], ['ready', 'Phone app on', c.ready], ['app_off', 'Notifications off', c.app_off], ['web_only', 'Browser only', c.web_only], ['none', 'Not set up', c.none]].filter(function (f) { return f[0] !== 'app_off' || f[2]; }).map(function (f) {
            return '<button type="button" role="tab" data-nr-f="' + f[0] + '" aria-selected="' + (filter === f[0]) + '"' + (filter === f[0] ? ' class="active"' : '') + '>' + f[1] + ' ' + (f[2] || 0) + '</button>';
          }).join('')
        + '</div>'
        + '<div style="overflow-x:auto;"><table class="kt-nr-tbl" data-kt-export-name="staff-notifications"><thead><tr>'
        + '<th>Staff</th><th>Role</th><th>Centre</th><th>Notifications</th><th>App version</th><th>Phone</th><th>OS</th><th>Permissions</th><th>Last notification</th><th>Last seen</th></tr></thead><tbody>'
        + (list.length ? list.map(function (p) {
            var st = ST[p.status] || ST.none;
            var ph = p.devices && p.devices.phone;
            var alls = (p.devices && p.devices.all) || [];
            var ap = p.devices && p.devices.app;   // the app on a phone, push address or not (device_presence, 2026-11-02)
            var dv = ph || ap || alls[0] || null;   // the phone app first; else their newest browser
            var nv = dv && dv.perms && dv.perms.notifications;
            var why = p.status === 'app_off' ? (nv === 'denied' ? 'turned off on the phone — Settings → KiddieTrac → Notifications' : 'not allowed yet — the app asks when opened') : '';
            var lp = p.last_push;
            /* A miss from BEFORE the phone was set up is history, not a fault (2026-11-02, Natasha allowed notifications at
               18:25 UTC; her row still led with the 17:50 "nowhere to send" and read as broken). Said so, and greyed. */
            var lpBefore = !!(lp && lp.outcome !== 'sent' && p.status === 'ready' && dv && (dv.perms_checked_at || dv.last_active_at)
              && String(lp.at).slice(0, 19) < String(dv.perms_checked_at || dv.last_active_at).slice(0, 19));
            return '<tr><td><b>' + esc(p.name) + '</b>' + (p.required ? '<span class="kt-nr-req">REQUIRED</span>' : '') + '<div class="kt-nr-sub">' + esc(p.email) + '</div></td>'
              + '<td data-label="Role">' + esc((p.roles || []).map(function (r) { return ROLE[r] || r; }).join(', ')) + '</td>'
              + '<td data-label="Centre">' + esc((p.centres || []).join(', ') || '—') + '</td>'
              + '<td data-label="Notifications"><span class="kt-nr-pill" style="color:' + st[1] + ';background:' + st[2] + ';">' + st[0] + '</span>' + (why ? '<div class="kt-nr-sub">' + esc(why) + '</div>' : '') + '</td>'
              + '<td data-label="App version">' + (dv ? esc(dv.app_version || 'Version not reported') + (dv.update_available ? '<span class="kt-nr-upd">Update available</span>' : '')
                  + '<div class="kt-nr-sub">' + esc(devWord(dv)) + ' · active ' + esc(ago(dv.last_active_at)) + '</div>'
                  : (p.devices && p.devices.ever_phone ? '<span class="kt-nr-sub">app registration expired</span>' : '<span class="kt-nr-sub">no app registered</span>')) + '</td>'
              + '<td data-label="Phone">' + (dv ? esc(dv.model || '—') : '—')
                  + (alls.length > 1 ? '<div><button type="button" class="kt-nr-more" data-nr-devs="' + p.user_id + '" title="' + esc(alls.map(function (x) { return (x.model || devWord(x)) + ' · ' + (x.os || '') + ' · ' + (x.app_version || devWord(x)); }).join('\n')) + '">+' + (alls.length - 1) + ' more device' + (alls.length > 2 ? 's' : '') + '</button>'
                      + '<div class="kt-nr-devs" data-nr-devlist="' + p.user_id + '">' + allDevices(alls) + '</div></div>' : '') + '</td>'
              + '<td data-label="OS">' + (dv ? esc(dv.os || '—') : '—') + '</td>'
              + '<td data-label="Permissions">' + permChips(dv) + '</td>'
              + '<td data-label="Last notification">' + (lp ? (lpBefore ? '<span class="kt-nr-sub">' + esc(OUT[lp.outcome] || lp.outcome) + ' · before this phone was set up</span>' : esc(OUT[lp.outcome] || lp.outcome)) + '<div class="kt-nr-sub">' + esc(ago(lp.at)) + (lp.title ? ' · ' + esc(lp.title) : '') + '</div>' : '<span class="kt-nr-sub">none in 30 days</span>') + '</td>'
              + '<td data-label="Last seen">' + esc(ago(p.last_seen)) + '</td></tr>';
          }).join('') : '<tr><td colspan="10" style="color:#64748B;">Nobody in this view.</td></tr>')
        + '</tbody></table></div>'
        + (d.can_send && people.some(function (p) { return p.status !== 'ready'; })
          ? '<div style="margin-top:10px;"><button type="button" data-nr-send class="kt-btn kt-btn-secondary">✉️ Send setup instructions…</button></div>' : '');
    }
    paint();
    host.addEventListener('click', function (e) {
      var dm = e.target.closest('[data-nr-devs]');
      if (dm) { var dl = host.querySelector('[data-nr-devlist="' + dm.getAttribute('data-nr-devs') + '"]'); if (dl) dl.classList.toggle('on'); return; }
      var f = e.target.closest('[data-nr-f]');
      if (f) { filter = f.getAttribute('data-nr-f'); paint(); return; }
      if (!e.target.closest('[data-nr-send]')) return;
      var who = people.filter(function (p) { return p.status !== 'ready' && (p.required || !s.required); });
      if (!who.length) return;
      var listHtml = who.map(function (p) { return '• ' + esc(p.name) + ' — ' + esc((ST[p.status] || ST.none)[0].replace(/^⚠ /, '')); }).join('<br>');
      var go = function () {
        KT.Api.post('/admin/notify-readiness/instructions', { user_ids: who.map(function (p) { return p.user_id; }), confirm: true })
          .then(function (r) { if (KT.toast) KT.toast('Setup instructions sent: ' + (r.emails || 0) + ' email(s), ' + (r.notified || 0) + ' in-app.', 'success'); })
          .catch(function (er) { if (KT.toast) KT.toast((er && er.message) || 'Could not send', 'error'); });
      };
      if (KT.confirm) {
        var ex = document.createElement('div');
        ex.style.cssText = 'font-size:13px;color:#0F172A;line-height:1.6;max-height:240px;overflow:auto;margin-top:6px;';
        ex.innerHTML = listHtml;
        KT.confirm({ title: 'Send setup instructions?', description: 'Each person gets an email with the Android and iPhone steps, plus a notification in the app:', extra: ex, okLabel: 'Send to ' + who.length })
          .then(function (ok) { if (ok) go(); });
      }
    });
  }

  KT.notifyReadiness = { mount: mount };
})();
