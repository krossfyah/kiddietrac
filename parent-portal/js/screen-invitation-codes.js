/* Kiddietrac v22p2.1 - Invitation Codes
   Director-managed invite codes for self-service parent (guardian) enrollment.
*/
(function (window) {
  'use strict';
  if (!window.KT || !window.KT.Shell) return;
  var Shell = window.KT.Shell;
  var Modal = Shell.Modal;

  function token() { return sessionStorage.getItem('kt_token') || localStorage.getItem('kt_token'); }
  function apiBase() { return (window.KT && window.KT.API_BASE) || 'https://api.kiddietrac.com/api/v1'; }

  async function api(method, path, body) {
    var opts = { method: method, headers: { 'Authorization': 'Bearer ' + token(), 'Accept': 'application/json' } };
    if (body !== undefined) { opts.headers['Content-Type'] = 'application/json'; opts.body = JSON.stringify(body); }
    var res = await fetch(apiBase() + path, opts);
    var json = await res.json().catch(function () { return {}; });
    if (!res.ok) throw new Error(json.message || ('API ' + res.status));
    return json;
  }
  function esc(s) { return s == null ? '' : String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;'); }
  function fmtDate(s) { if (!s) return '-'; try { return new Date(s).toLocaleString('en-CA', { dateStyle: 'medium' }); } catch (e) { return s; } }

  function enrollUrl(code) {
    return 'https://app.kiddietrac.com/enroll.html?code=' + encodeURIComponent(code);
  }

  async function render(container) {
    container.innerHTML = '<div style="padding:32px;text-align:center;color:#6B7280;">Loading invitation codes...</div>';
    var resp;
    try { resp = await api('GET', '/director/invitation-codes'); }
    catch (e) {
      container.innerHTML = '<div style="padding:24px;color:#DC2626;">Could not load: ' + esc(e.message) + '</div>';
      return;
    }
    var codes = resp.invitation_codes || [];

    container.innerHTML =
      '<div style="padding:24px;max-width:1100px;">' +
        '<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:14px;flex-wrap:wrap;gap:12px;">' +
          '<div>' +
            '<h2 style="font-size:24px;margin:0;">Parent invitation codes</h2>' +
            '<p style="color:#6B7280;margin:4px 0 0;font-size:13px;max-width:680px;">Share these codes with parents so they can self-enroll their child at <code>app.kiddietrac.com/enroll.html?code=XXX</code>. Each new child created via a code lands on your waitlist for review.</p>' +
          '</div>' +
          '<button id="kt-new-code" style="background:linear-gradient(135deg,#081C41,#1F6080);color:white;border:none;padding:11px 22px;border-radius:10px;font-weight:700;cursor:pointer;box-shadow:0 6px 14px rgba(31,96,128,0.25);">+ New code</button>' +
        '</div>' +
        (codes.length === 0
          ? '<div style="padding:32px;text-align:center;color:#6B7280;background:white;border-radius:14px;">No invitation codes yet. Click "+ New code" to generate one.</div>'
          : '<div style="background:white;border-radius:14px;overflow:hidden;box-shadow:0 1px 4px rgba(0,0,0,.05);">' +
              '<table style="width:100%;border-collapse:collapse;font-size:14px;">' +
                '<thead><tr style="background:#F9FAFB;text-align:left;">' +
                  '<th style="padding:12px;">Code</th>' +
                  '<th style="padding:12px;">Label</th>' +
                  '<th style="padding:12px;">Status</th>' +
                  '<th style="padding:12px;">Uses</th>' +
                  '<th style="padding:12px;">Expires</th>' +
                  '<th style="padding:12px;">Created by</th>' +
                  '<th style="padding:12px;"></th>' +
                '</tr></thead><tbody>' +
                codes.map(codeRow).join('') +
              '</tbody></table>' +
          '</div>') +
      '</div>';

    container.querySelector('#kt-new-code').addEventListener('click', function () { openNewCodeModal(container); });
    container.querySelectorAll('[data-copy]').forEach(function (b) {
      b.addEventListener('click', function () { copyEnrollLink(b.dataset.copy, b); });
    });
    container.querySelectorAll('[data-revoke]').forEach(function (b) {
      b.addEventListener('click', function () { confirmRevoke(parseInt(b.dataset.revoke, 10), container); });
    });
    container.querySelectorAll('[data-delete]').forEach(function (b) {
      b.addEventListener('click', function () { confirmDelete(parseInt(b.dataset.delete, 10), container); });
    });
  }

  function codeRow(c) {
    var statusColor = c.status === 'active'
      ? (c.is_usable ? '#16A34A' : '#F59E0B')
      : (c.status === 'expired' ? '#9CA3AF' : '#DC2626');
    var statusLabel = c.is_usable ? 'ACTIVE' : (c.status === 'active' ? 'AT CAP' : c.status.toUpperCase());

    return '<tr style="border-top:1px solid #E5E7EB;">' +
      '<td style="padding:12px;font-family:ui-monospace,monospace;font-size:14px;font-weight:700;color:#0F172A;">' + esc(c.code) + '</td>' +
      '<td style="padding:12px;color:#6B7280;">' + esc(c.label || '-') + '</td>' +
      '<td style="padding:12px;"><span style="padding:3px 9px;border-radius:6px;background:' + statusColor + ';color:white;font-size:10px;font-weight:700;letter-spacing:0.5px;">' + statusLabel + '</span></td>' +
      '<td style="padding:12px;">' + c.used_count + ' / ' + c.max_uses + '</td>' +
      '<td style="padding:12px;color:#6B7280;font-size:13px;">' + (c.expires_at ? fmtDate(c.expires_at) : 'No expiry') + '</td>' +
      '<td style="padding:12px;color:#6B7280;font-size:13px;">' + esc(c.created_by_name || '-') + '</td>' +
      '<td style="padding:12px;text-align:right;">' +
        (c.is_usable
          ? '<button data-copy="' + esc(c.code) + '" style="padding:5px 10px;background:#1F6080;color:white;border:none;border-radius:6px;font-size:12px;font-weight:600;cursor:pointer;margin-right:4px;">Copy link</button>'
          : '') +
        (c.status === 'active'
          ? '<button data-revoke="' + c.id + '" style="padding:5px 10px;background:#FEF3C7;color:#92400E;border:none;border-radius:6px;font-size:12px;cursor:pointer;margin-right:4px;">Revoke</button>'
          : '') +
        (c.used_count === 0
          ? '<button data-delete="' + c.id + '" style="padding:5px 10px;background:#FEE2E2;color:#991B1B;border:none;border-radius:6px;font-size:12px;cursor:pointer;">Delete</button>'
          : '') +
      '</td>' +
    '</tr>';
  }

  function copyEnrollLink(code, btn) {
    var link = enrollUrl(code);
    var original = btn.textContent;
    var done = function (ok) {
      btn.textContent = ok ? 'Copied!' : 'Copy failed';
      btn.style.background = ok ? '#16A34A' : '#DC2626';
      setTimeout(function () { btn.textContent = original; btn.style.background = '#1F6080'; }, 1200);
    };
    if (navigator.clipboard && navigator.clipboard.writeText) {
      navigator.clipboard.writeText(link).then(function () { done(true); }).catch(function () { done(false); });
    } else {
      try {
        var ta = document.createElement('textarea');
        ta.value = link; document.body.appendChild(ta); ta.select();
        document.execCommand('copy'); document.body.removeChild(ta);
        done(true);
      } catch (e) { done(false); }
    }
  }

  function openNewCodeModal(container) {
    var body = document.createElement('div');
    body.innerHTML =
      '<div style="display:grid;gap:10px;">' +
        '<label style="font-size:12px;font-weight:700;color:#6B7280;">Label (optional, e.g. "Acorn Room 2025-26")</label>' +
        '<input id="kt-label" placeholder="Acorn Room 2025-26" style="padding:10px 12px;border:1px solid #D1D5DB;border-radius:8px;font-size:14px;">' +
        '<label style="font-size:12px;font-weight:700;color:#6B7280;">How many parents can use this code?</label>' +
        '<input id="kt-max" type="number" value="1" min="1" max="200" style="padding:10px 12px;border:1px solid #D1D5DB;border-radius:8px;font-size:14px;">' +
        '<label style="font-size:12px;font-weight:700;color:#6B7280;">Expires (optional)</label>' +
        '<input id="kt-exp" type="date" style="padding:10px 12px;border:1px solid #D1D5DB;border-radius:8px;font-size:14px;">' +
        '<div id="kt-msg" style="font-size:13px;min-height:20px;"></div>' +
      '</div>';

    Modal.open({
      title: 'New invitation code',
      body: body,
      actions: [
        { label: 'Cancel', style: 'btn-secondary' },
        {
          label: 'Generate code',
          style: 'btn-primary',
          handler: async function () {
            var payload = {
              label:      body.querySelector('#kt-label').value.trim() || null,
              max_uses:   parseInt(body.querySelector('#kt-max').value, 10) || 1,
              expires_at: body.querySelector('#kt-exp').value
                ? (body.querySelector('#kt-exp').value + ' 23:59:59')
                : null,
            };
            try {
              var r = await api('POST', '/director/invitation-codes', payload);
              Shell.Modal.open({
                title: 'Code generated',
                body: (function () {
                  var d = document.createElement('div');
                  d.innerHTML =
                    '<p style="font-size:14px;color:#374151;">Share this link with the parent:</p>' +
                    '<div style="background:#F1F5F9;border:1px solid #CBD5E1;padding:14px;border-radius:10px;font-family:ui-monospace,monospace;font-size:14px;word-break:break-all;margin:10px 0;color:#0F172A;">' +
                      esc(enrollUrl(r.invitation_code.code)) +
                    '</div>' +
                    '<p style="font-size:13px;color:#6B7280;">Or share just the code: <b style="font-family:ui-monospace,monospace;color:#0F172A;font-size:15px;">' + esc(r.invitation_code.code) + '</b></p>' +
                    '<button id="kt-copy-now" style="margin-top:10px;padding:10px 18px;background:#1F6080;color:white;border:none;border-radius:8px;font-weight:600;cursor:pointer;">Copy link</button>';
                  setTimeout(function () {
                    var b = d.querySelector('#kt-copy-now');
                    if (b) b.addEventListener('click', function () { copyEnrollLink(r.invitation_code.code, b); });
                  }, 0);
                  return d;
                })(),
                actions: [{ label: 'Done', style: 'btn-primary' }],
              });
              render(container);
            } catch (e) {
              body.querySelector('#kt-msg').textContent = 'Error: ' + e.message;
              body.querySelector('#kt-msg').style.color = '#DC2626';
              return false;
            }
          },
        },
      ],
    });
  }

  function confirmRevoke(id, container) {
    Modal.confirm({
      title: 'Revoke invitation code',
      message: 'Revoke this code? Any parent currently filling out the form with this code will see "no longer valid" when they submit.',
      confirmLabel: 'Revoke',
      destructive: true,
      onConfirm: async function () {
        await api('POST', '/director/invitation-codes/' + id + '/revoke');
        render(container);
      },
    });
  }

  function confirmDelete(id, container) {
    Modal.confirm({
      title: 'Delete invitation code',
      message: 'Permanently delete this unused code? This cannot be undone.',
      confirmLabel: 'Delete',
      destructive: true,
      onConfirm: async function () {
        await api('DELETE', '/director/invitation-codes/' + id);
        render(container);
      },
    });
  }

  if (Shell && Shell.registerScreen) {
    ['agency_admin', 'centre_director'].forEach(function (r) {
      Shell.registerScreen(r + ':invitation-codes', render);
    });
  }
  window.KT = window.KT || {};
  window.KT.InvitationCodes = { render: render };
})(window);
