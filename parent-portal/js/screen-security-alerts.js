/* ═══════════════════════════════════════════════════════════════════
   KiddieTrac — Security alerts screen (2026-07-08, SOC 2 CC7 monitoring).
   Platform-admin view of the security_alerts table (brute force, MFA
   hammering, credential stuffing) written by the security:alerts cron.
   Registered agency_admin/platform_admin:security-alerts; the API route is
   role:platform_admin, so non-platform admins get a graceful message.
   ═══════════════════════════════════════════════════════════════════ */
(function (window) {
  'use strict';
  var KT = window.KT || {};
  var Shell = KT.Shell, Api = KT.Api;
  if (!Shell || !Api) return;

  function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"]/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c];
    });
  }
  function when(iso) {
    try {
      var d = new Date(String(iso).replace(' ', 'T') + (/[zZ]|[+-]\d\d:?\d\d$/.test(String(iso)) ? '' : 'Z'));
      var mins = Math.round((Date.now() - d.getTime()) / 60000);
      if (mins < 1) return 'just now';
      if (mins < 60) return mins + 'm ago';
      if (mins < 1440) return Math.round(mins / 60) + 'h ago';
      return d.toLocaleDateString();
    } catch (e) { return esc(iso); }
  }
  var TYPES = {
    brute_force_ip:     { label: 'Brute force (IP)',      icon: '🌐' },
    mfa_hammering:      { label: 'MFA hammering',         icon: '🔑' },
    credential_stuffing:{ label: 'Credential stuffing',   icon: '🎯' }
  };
  function sevPill(sev) {
    var map = { high: ['#FBE9E6', '#BE4038', '#F2C9C3'], medium: ['#FBF1DC', '#AE7015', '#F0DCA8'], low: ['#E7F4EC', '#1E8E60', '#BEE6CE'] };
    var c = map[sev] || map.high;
    return '<span style="font:700 10.5px/1 ui-monospace,monospace;text-transform:uppercase;letter-spacing:.05em;padding:4px 9px;border-radius:100px;background:' + c[0] + ';color:' + c[1] + ';border:1px solid ' + c[2] + ';">' + esc(sev || 'high') + '</span>';
  }

  async function render(main) {
    main.setAttribute('data-kt-pretty', '1');
    main.innerHTML = '<div style="padding:14px 24px;max-width:1100px;"><div class="kt-page-hero"><h2>🛡️ Security alerts</h2><p>Loading…</p></div></div>';
    var data = await Api.get('/platform/security-alerts').catch(function (e) { return { __err: e && e.message }; });
    if (data.__err) {
      main.innerHTML = '<div style="padding:14px 24px;"><div class="kt-page-hero"><h2>🛡️ Security alerts</h2></div>'
        + '<div style="background:#FEF3C7;border:1px solid #FDE68A;color:#92400E;border-radius:12px;padding:18px;max-width:640px;">This page is available to <b>platform administrators</b> only.</div></div>';
      return;
    }
    var alerts = data.alerts || [];
    var rowsHtml = alerts.length ? alerts.map(function (a) {
      var t = TYPES[a.type] || { label: a.type, icon: '⚠️' };
      return '<tr style="' + (a.resolved ? 'opacity:.55;' : '') + '">'
        + '<td style="padding:12px 14px;"><span style="font-size:16px;margin-right:8px;">' + t.icon + '</span><b style="font-size:13.5px;">' + esc(t.label) + '</b></td>'
        + '<td style="padding:12px 14px;">' + sevPill(a.severity) + '</td>'
        + '<td style="padding:12px 14px;font:12.5px ui-monospace,monospace;color:#475569;">' + esc(a.subject || '') + '</td>'
        + '<td style="padding:12px 14px;font-size:13px;color:#334155;">' + esc(a.details || '') + '</td>'
        + '<td style="padding:12px 14px;font-size:12.5px;color:#64748B;white-space:nowrap;">' + when(a.created_at) + '</td>'
        + '<td style="padding:12px 14px;text-align:right;white-space:nowrap;">'
        + (a.resolved
            ? '<span style="font:700 11px/1 ui-monospace,monospace;color:#1E8E60;">✓ Resolved</span>'
            : '<button class="ktsa-resolve" data-id="' + a.id + '" style="font-size:12px;font-weight:600;padding:6px 12px;border-radius:8px;border:1px solid #D6DEE7;background:#F3F6F9;color:#1E293B;cursor:pointer;">Mark resolved</button>')
        + '</td></tr>';
    }).join('') : '<tr><td colspan="6" style="padding:34px;text-align:center;color:#64748B;">✅ No security alerts — no anomalous authentication activity detected.</td></tr>';

    main.innerHTML = '<div style="padding:14px 24px;max-width:1100px;">'
      + '<div class="kt-page-hero"><h2>🛡️ Security alerts</h2><p>Anomalous authentication activity flagged automatically every 15 minutes from the audit log — brute-force attempts, MFA hammering, and credential stuffing.</p></div>'
      + '<div style="display:flex;gap:14px;margin:0 0 18px;">'
      +   '<div style="background:#fff;border:1px solid #E7EBF0;border-left:4px solid ' + (data.open ? '#BE4038' : '#1E8E60') + ';border-radius:12px;padding:14px 18px;min-width:140px;"><div style="font:600 30px/1 ui-monospace,monospace;color:' + (data.open ? '#BE4038' : '#1E8E60') + ';">' + data.open + '</div><div style="font-size:12.5px;color:#64748B;margin-top:6px;">Open alerts</div></div>'
      +   '<div style="background:#fff;border:1px solid #E7EBF0;border-radius:12px;padding:14px 18px;min-width:140px;"><div style="font:600 30px/1 ui-monospace,monospace;color:#0F2540;">' + data.total + '</div><div style="font-size:12.5px;color:#64748B;margin-top:6px;">Total recorded</div></div>'
      + '</div>'
      + '<div style="background:#fff;border:1px solid #E7EBF0;border-radius:14px;overflow:hidden;">'
      +   '<table style="width:100%;border-collapse:separate;border-spacing:0;">'
      +     '<thead><tr style="background:#F3F6F9;text-align:left;">'
      +       ['Type', 'Severity', 'Subject', 'Detail', 'When', ''].map(function (h) { return '<th style="padding:11px 14px;font:700 11px/1 ui-monospace,monospace;letter-spacing:.06em;text-transform:uppercase;color:#64748B;">' + h + '</th>'; }).join('')
      +     '</tr></thead>'
      +     '<tbody>' + rowsHtml + '</tbody>'
      +   '</table>'
      + '</div></div>';

    main.querySelectorAll('.ktsa-resolve').forEach(function (b) {
      b.onclick = function () {
        b.disabled = true; b.textContent = '…';
        Api.post('/platform/security-alerts/' + b.getAttribute('data-id') + '/resolve', {})
          .then(function () { render(main); })
          .catch(function () { b.disabled = false; b.textContent = 'Mark resolved'; });
      };
    });
  }

  ['agency_admin', 'platform_admin'].forEach(function (role) {
    Shell.registerScreen(role + ':security-alerts', render);
  });
})(window);
