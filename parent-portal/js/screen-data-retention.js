/* ═══════════════════════════════════════════════════════════════════
   KiddieTrac — Data Retention & Compliance settings (2026-07-08).
   Agency admins control how long each type of data is kept, consent/privacy
   details, and the data-protection contact. Persists per-agency via
   GET/POST /admin/compliance-settings (agencies.settings JSON → "compliance").
   Registered agency_admin / platform_admin : data-retention.
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

  var INP = 'width:120px;box-sizing:border-box;padding:9px 11px;border:1px solid #D6DEE7;border-radius:9px;font-size:14px;';
  var WIDE = 'width:100%;max-width:440px;box-sizing:border-box;padding:9px 11px;border:1px solid #D6DEE7;border-radius:9px;font-size:14px;';

  function card(title, sub, inner) {
    return '<div style="background:#fff;border:1px solid #E7EBF0;border-radius:14px;padding:20px 22px;margin-bottom:16px;">'
      + '<div style="font-weight:800;font-size:15.5px;color:#0D1B2A;">' + esc(title) + '</div>'
      + (sub ? '<div style="font-size:13px;color:#64748B;margin:3px 0 14px;">' + esc(sub) + '</div>' : '<div style="height:12px;"></div>')
      + inner + '</div>';
  }
  function numField(key, label, unit, val) {
    return '<div style="display:flex;align-items:center;justify-content:space-between;gap:16px;padding:10px 0;border-bottom:1px solid #F1F5F9;">'
      + '<label for="c_' + key + '" style="font-size:14px;color:#334155;font-weight:600;">' + esc(label) + '</label>'
      + '<div style="display:flex;align-items:center;gap:8px;white-space:nowrap;">'
      + '<input id="c_' + key + '" type="number" min="1" value="' + esc(val) + '" style="' + INP + 'width:90px;text-align:right;">'
      + '<span style="font-size:13px;color:#64748B;width:56px;">' + esc(unit) + '</span></div></div>';
  }
  function toggle(key, label, hint, on) {
    return '<label style="display:flex;align-items:flex-start;gap:12px;padding:10px 0;cursor:pointer;">'
      + '<input id="c_' + key + '" type="checkbox" ' + (on ? 'checked' : '') + ' style="margin-top:3px;width:17px;height:17px;flex-shrink:0;">'
      + '<span><span style="font-size:14px;color:#334155;font-weight:600;">' + esc(label) + '</span>'
      + (hint ? '<span style="display:block;font-size:12.5px;color:#64748B;margin-top:2px;">' + esc(hint) + '</span>' : '') + '</span></label>';
  }

  async function render(main) {
    main.setAttribute('data-kt-pretty', '1');
    main.innerHTML = '<div style="padding:14px 24px;max-width:840px;"><div class="kt-page-hero"><h2>🗄️ Data retention &amp; compliance</h2><p>Loading…</p></div></div>';
    var data = await Api.get('/admin/compliance-settings').catch(function (e) { return { __err: e && e.message }; });
    if (data.__err) {
      main.innerHTML = '<div style="padding:14px 24px;"><div class="kt-page-hero"><h2>🗄️ Data retention &amp; compliance</h2></div>'
        + '<div style="background:#FEF3C7;border:1px solid #FDE68A;color:#92400E;border-radius:12px;padding:18px;max-width:640px;">This page is available to <b>agency administrators</b>.</div></div>';
      return;
    }
    var c = data.compliance || {};

    main.innerHTML = '<div style="padding:14px 24px;max-width:840px;">'
      + '<div class="kt-page-hero"><h2>🗄️ Data retention &amp; compliance</h2><p>Control how long ' + esc(data.agency_name || 'your agency') + ' keeps each type of data, and how you handle privacy and consent. These settings record your agency’s data-retention policy.</p></div>'

      + card('Retention periods', 'How long records are kept before they become eligible for removal. Childcare regulations often set minimums — check your jurisdiction.',
          numField('child_record_years', 'Child & enrolment records (after departure)', 'years', c.child_record_years)
          + numField('document_years', 'Uploaded documents', 'years', c.document_years)
          + numField('daily_log_months', 'Attendance & daily logs', 'months', c.daily_log_months)
          + numField('message_months', 'Parent–educator messages (chat)', 'months', c.message_months)
          + numField('announcement_months', 'Announcements & news', 'months', c.announcement_months)
          + numField('suspended_family_months', 'Suspended families', 'months (0 = never)', c.suspended_family_months)
          + numField('audit_log_months', 'Security & audit trail', 'months', c.audit_log_months))

      + card('Automatic enforcement', 'Off by default. When on, records past their retention period are handled automatically by a nightly review. Deletion is permanent — anonymising keeps aggregate stats while removing identifying details.',
          toggle('auto_enforce', 'Automatically enforce retention', 'Applies the periods above every night.', c.auto_enforce)
          + '<div style="display:flex;align-items:center;gap:12px;padding:10px 0 2px;"><label for="c_enforce_mode" style="font-size:14px;color:#334155;font-weight:600;">When enforcing, records are</label>'
          + '<select id="c_enforce_mode" style="' + INP + 'width:auto;">'
          + '<option value="anonymize"' + (c.enforce_mode !== 'delete' ? ' selected' : '') + '>Anonymised</option>'
          + '<option value="delete"' + (c.enforce_mode === 'delete' ? ' selected' : '') + '>Permanently deleted</option></select></div>')

      + card('Privacy & consent', '',
          toggle('require_consent', 'Require parent consent at enrolment', 'Parents must acknowledge your privacy terms before a child is enrolled.', c.require_consent)
          + '<div style="padding:10px 0;"><label for="c_privacy_policy_url" style="display:block;font-size:13px;font-weight:700;color:#475569;margin-bottom:5px;">Privacy policy URL</label>'
          + '<input id="c_privacy_policy_url" type="url" placeholder="https://…" value="' + esc(c.privacy_policy_url || '') + '" style="' + WIDE + '"></div>'
          + '<div style="padding:2px 0;"><label for="c_data_contact_email" style="display:block;font-size:13px;font-weight:700;color:#475569;margin-bottom:5px;">Data-protection contact email</label>'
          + '<input id="c_data_contact_email" type="email" placeholder="privacy@youragency.com" value="' + esc(c.data_contact_email || '') + '" style="' + WIDE + '">'
          + '<div style="font-size:12.5px;color:#64748B;margin-top:5px;">Where parents send data-access or deletion requests.</div></div>')

      + card('Notes', 'Internal notes on your retention/compliance obligations (visible to your admins only).',
          '<textarea id="c_notes" rows="3" style="width:100%;box-sizing:border-box;padding:10px 12px;border:1px solid #D6DEE7;border-radius:9px;font-size:14px;font-family:inherit;resize:vertical;">' + esc(c.notes || '') + '</textarea>')

      + '<div style="display:flex;align-items:center;gap:14px;justify-content:flex-end;">'
      + '<span id="c-status" style="font-size:13px;color:#1E8E60;"></span>'
      + '<button id="c-save" style="font-size:14px;font-weight:700;padding:11px 22px;border:0;border-radius:10px;background:linear-gradient(135deg,#0FA3B1,#1F6FB2);color:#fff;cursor:pointer;">Save changes</button></div>'
      + '</div>';

    var btn = main.querySelector('#c-save');
    btn.onclick = function () {
      var v = function (id) { var el = main.querySelector('#c_' + id); return el ? el.value : null; };
      var chk = function (id) { var el = main.querySelector('#c_' + id); return el ? el.checked : false; };
      var body = {
        child_record_years: +v('child_record_years'), document_years: +v('document_years'),
        daily_log_months: +v('daily_log_months'), message_months: +v('message_months'),
        announcement_months: +v('announcement_months'),
        suspended_family_months: +v('suspended_family_months'),
        audit_log_months: +v('audit_log_months'),
        auto_enforce: chk('auto_enforce'), enforce_mode: v('enforce_mode'),
        require_consent: chk('require_consent'),
        privacy_policy_url: v('privacy_policy_url') || '', data_contact_email: v('data_contact_email') || '',
        notes: v('notes') || ''
      };
      var st = main.querySelector('#c-status');
      btn.disabled = true; btn.textContent = 'Saving…'; st.textContent = '';
      Api.post('/admin/compliance-settings', body).then(function () {
        btn.disabled = false; btn.textContent = 'Save changes';
        st.style.color = '#1E8E60'; st.textContent = '✓ Saved';
        setTimeout(function () { st.textContent = ''; }, 2600);
        if (KT.Dom && KT.Dom.toast) KT.Dom.toast('Compliance settings saved', 'success');
      }).catch(function (e) {
        btn.disabled = false; btn.textContent = 'Save changes';
        st.style.color = '#BE4038'; st.textContent = 'Save failed: ' + ((e && e.message) || 'error');
      });
    };
  }

  ['agency_admin', 'platform_admin'].forEach(function (role) {
    Shell.registerScreen(role + ':data-retention', render);
  });
})(window);
