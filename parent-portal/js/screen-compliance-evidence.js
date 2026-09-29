/* ═══════════════════════════════════════════════════════════════════
   KIDDIETRAC — Settings → Compliance evidence  (platform admin)

   SOC 2 Type I readiness. The report comes from an independent CPA firm; this screen is
   what they ask for first: the written policies and control matrix, and live evidence
   that the controls exist and run (access review, security events, backups, jobs, code
   changes). Quarterly access reviews are signed off here. Every export is audited.

   Backend: ComplianceEvidenceController (/platform/compliance-evidence*).
   ═══════════════════════════════════════════════════════════════════ */
(function (window) {
  'use strict';
  var KT = window.KT;

  function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }
  function toast(m, t) { if (KT.toast) { KT.toast(m, t || 'info'); } }
  async function download(path, filename) {
    var tok = sessionStorage.getItem('kt_token') || localStorage.getItem('kt_token');
    var r = await fetch((KT.API_BASE || 'https://api.kiddietrac.com/api/v1') + path, { headers: { Authorization: 'Bearer ' + tok } });
    if (!r.ok) { toast('Download failed (' + r.status + ')', 'error'); return; }
    var blob = await r.blob(), a = document.createElement('a');
    a.href = URL.createObjectURL(blob); a.download = filename; document.body.appendChild(a); a.click(); a.remove();
  }
  function tile(label, value, tone, hint) {
    var c = { good: '#166534', warn: '#B45309', bad: '#B91C1C' }[tone] || '#0F172A';
    return '<div style="background:#fff;border:1px solid #E2E8F0;border-radius:12px;padding:12px 14px">'
      + '<div style="font-size:12px;color:#64748B;font-weight:700">' + esc(label) + '</div>'
      + '<div style="font-size:20px;font-weight:800;color:' + c + ';margin-top:2px">' + esc(value) + '</div>'
      + (hint ? '<div style="font-size:11.5px;color:#64748B;margin-top:2px">' + esc(hint) + '</div>' : '') + '</div>';
  }

  async function render(main) {
    main.setAttribute('data-kt-pretty', '1');
    main.innerHTML = '<div style="padding:14px 24px;max-width:1200px;">'
      + '<div class="kt-page-hero"><h2>🛡️ Compliance evidence</h2><p>SOC 2 readiness: the policies an auditor reads, and live evidence that the controls run. '
      + 'The SOC 2 report itself is issued by an independent CPA firm.</p></div><div id="ce-body">Loading…</div></div>';
    var body = main.querySelector('#ce-body'), s;
    try { s = await KT.Api.get('/platform/compliance-evidence'); }
    catch (e) { body.innerHTML = '<div class="kt-card" style="color:#B91C1C">Could not load: ' + esc(e.message || e) + '</div>'; return; }

    var m = s.mfa, a30 = s.auth_30d || {}, b = s.backups, rv = s.access_reviews;
    var mfaPct = m.admin_rows ? Math.round(m.admin_with_mfa / m.admin_rows * 100) : 0;
    var tls = function (t) { return t ? t.days_left + ' days' : 'unknown'; };
    var tlsTone = function (t) { return !t ? 'warn' : (t.days_left < 14 ? 'bad' : (t.days_left < 30 ? 'warn' : 'good')); };
    body.innerHTML =
      '<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(190px,1fr));gap:10px;margin-bottom:14px">'
      + tile('Admins with two-factor', m.admin_with_mfa + ' of ' + m.admin_rows + ' (' + mfaPct + '%)', mfaPct === 100 ? 'good' : 'bad', m.admin_with_passkey + ' also use a passkey')
      + tile('All users with two-factor', m.users_with_mfa + ' of ' + m.users, 'warn')
      + tile('Admins never signed in', s.admins_never_signed_in, s.admins_never_signed_in ? 'warn' : 'good', 'Remove unused access in the review')
      + tile('Sign-ins, last 30 days', (a30.login || 0) + ' ok · ' + (a30.login_failed || 0) + ' failed', '', (a30.mfa_failed || 0) + ' two-factor failures')
      + tile('Latest backup', b.latest || 'none', b.last_error ? 'bad' : (b.latest ? 'good' : 'bad'), b.on_disk + ' kept · same host, no off-site copy')
      + tile('TLS certificate (app / api)', tls(s.tls.app) + ' / ' + tls(s.tls.api), tlsTone(s.tls.app) === 'good' && tlsTone(s.tls.api) === 'good' ? 'good' : 'warn', 'Auto-renewed daily')
      + tile('Audit log', Number(s.audit_log.rows).toLocaleString() + ' events', 'good', 'since ' + String(s.audit_log.since || '').slice(0, 10))
      + tile('Access review', rv.last ? String(rv.last.reviewed_at).slice(0, 10) : 'never', rv.due ? 'bad' : 'good', rv.due ? 'Due now (quarterly)' : 'by ' + rv.last.reviewer)
      + '</div>'

      + '<div class="kt-card"><h3 style="margin:0 0 6px;font-size:16px">Evidence exports</h3><p style="margin:0 0 10px;font-size:13px;color:#64748B">Live data, generated on download. Each download is recorded in the audit log.</p>'
      + '<div style="display:flex;gap:8px;flex-wrap:wrap">'
      + '<button class="kt-btn" data-dl="/platform/compliance-evidence/access-review.csv" data-fn="access-review.csv">👥 Access review (CSV)</button>'
      + '<button class="kt-btn" data-dl="/platform/compliance-evidence/security-events.csv?days=90" data-fn="security-events-90d.csv">🔐 Security events, 90 days (CSV)</button>'
      + '<button class="kt-btn" data-dl="/platform/compliance-evidence/backups.csv" data-fn="backups.csv">🗄️ Backups (CSV)</button>'
      + '<button class="kt-btn" data-dl="/platform/compliance-evidence/schedule.txt" data-fn="scheduled-jobs.txt">⏱️ Scheduled jobs</button>'
      + '<button class="kt-btn" data-dl="/platform/compliance-evidence/changes.txt?days=90" data-fn="code-changes-90d.txt">🧩 Code changes, 90 days</button>'
      + '</div></div>'

      + '<div class="kt-card"><h3 style="margin:0 0 6px;font-size:16px">Policies and control matrix</h3>'
      + '<p style="margin:0 0 10px;font-size:13px;color:#64748B">Version 1.0, drafted 2026-09-29, pending management approval. Start with the README, the control matrix and the remediation plan.</p>'
      + '<table><thead><tr><th>Document</th><th>Updated</th><th></th></tr></thead><tbody>'
      + (s.documents || []).map(function (d) {
        return '<tr><td>' + esc(d.title) + '</td><td>' + esc(d.updated) + '</td><td><button class="kt-btn" style="padding:4px 10px;font-size:12px" data-dl="/platform/compliance-evidence/documents/' + encodeURIComponent(d.file) + '" data-fn="KiddieTrac-SOC2-' + esc(d.file) + '">Download</button></td></tr>';
      }).join('') + '</tbody></table></div>'

      + '<div class="kt-card"><h3 style="margin:0 0 6px;font-size:16px">Quarterly access review</h3>'
      + '<p style="margin:0 0 10px;font-size:13px;color:#64748B">Download the access review CSV, check every admin account is still needed and has two-factor, fix what isn’t, then sign off here. The sign-off keeps a snapshot of every admin account as it was.</p>'
      + '<div id="ce-reviews" style="margin-bottom:10px"></div>'
      + '<label style="display:block;font-size:12.5px;font-weight:700;margin-bottom:4px">What did you find?</label><textarea id="ce-find" rows="3" style="width:100%;padding:8px 10px;border:1px solid #CBD5E1;border-radius:8px;font-family:inherit" placeholder="e.g. 7 admin accounts reviewed; 2 have never signed in; 3 without two-factor."></textarea>'
      + '<label style="display:block;font-size:12.5px;font-weight:700;margin:8px 0 4px">What did you change?</label><textarea id="ce-act" rows="2" style="width:100%;padding:8px 10px;border:1px solid #CBD5E1;border-radius:8px;font-family:inherit" placeholder="e.g. Deactivated 2 unused admin roles; asked 3 admins to turn on two-factor."></textarea>'
      + '<label style="display:flex;gap:8px;align-items:center;font-size:13px;margin-top:8px"><input type="checkbox" id="ce-conf" style="width:auto"> I reviewed every admin-level account listed in the access review.</label>'
      + '<div style="margin-top:10px"><button class="kt-btn kt-btn-primary" id="ce-sign">✍️ Sign off access review</button></div></div>';

    body.querySelectorAll('[data-dl]').forEach(function (btn) { btn.onclick = function () { download(btn.getAttribute('data-dl'), btn.getAttribute('data-fn')); }; });
    loadReviews(body);
    body.querySelector('#ce-sign').onclick = async function () {
      var f = body.querySelector('#ce-find').value.trim();
      if (!f) { toast('Write down what you found first.', 'error'); return; }
      if (!body.querySelector('#ce-conf').checked) { toast('Tick the box to confirm you reviewed every account.', 'error'); return; }
      try {
        await KT.Api.post('/platform/compliance-evidence/access-reviews', { findings: f, actions_taken: body.querySelector('#ce-act').value.trim() || null, confirm: true });
        toast('Access review signed off', 'success'); render(main);
      } catch (e) { toast(e.message || 'Could not save', 'error'); }
    };
  }
  async function loadReviews(body) {
    var host = body.querySelector('#ce-reviews');
    try {
      var r = await KT.Api.get('/platform/compliance-evidence/access-reviews');
      var list = r.reviews || [];
      host.innerHTML = list.length ? '<table><thead><tr><th>Date</th><th>Reviewer</th><th>Accounts</th><th>Findings</th><th>Changes</th></tr></thead><tbody>'
        + list.map(function (x) { return '<tr><td>' + esc(String(x.reviewed_at).slice(0, 10)) + '</td><td>' + esc(x.reviewer_name) + '</td><td>' + esc(x.admin_count) + '</td><td>' + esc(x.findings) + '</td><td>' + esc(x.actions_taken || '—') + '</td></tr>'; }).join('')
        + '</tbody></table>' : '<div style="font-size:13px;color:#B45309;font-weight:700">No access review has been recorded yet.</div>';
    } catch (e) { host.textContent = ''; }
  }

  function reg(n) {
    if (!window.KT || !KT.Shell || !KT.Shell.registerScreen) { if ((n || 0) < 100) { setTimeout(function () { reg((n || 0) + 1); }, 100); } return; }
    KT = window.KT;
    KT.Shell.registerScreen('platform_admin:compliance-evidence', render);
  }
  reg(0);
})(window);
