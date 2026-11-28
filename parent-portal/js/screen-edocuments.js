/* Kiddietrac v22p2.2 - eDocuments
   Director: PDF template library + assignment + signature roster.
   Parent: inbox of pending documents with canvas-based e-signature.
*/
(function (window) {
  'use strict';
  if (!window.KT || !window.KT.Shell) return;
  var Shell = window.KT.Shell;
  var Modal = Shell.Modal;

  function token() { return sessionStorage.getItem('kt_token') || localStorage.getItem('kt_token'); }
  function apiBase() { return (window.KT && window.KT.API_BASE) || 'https://api.kiddietrac.com/api/v1'; }
  function getUser() { try { return JSON.parse(sessionStorage.getItem('kt_user') || '{}'); } catch (e) { return {}; } }
  function role() { var u = getUser(); return (u && (u.primary_role || (u.roles && u.roles[0]))) || ''; }

  async function api(method, path, body, isMultipart) {
    var opts = { method: method, headers: { 'Authorization': 'Bearer ' + token(), 'Accept': 'application/json' } };
    if (body !== undefined) {
      if (isMultipart) {
        opts.body = body;
      } else {
        opts.headers['Content-Type'] = 'application/json';
        opts.body = JSON.stringify(body);
      }
    }
    var res = await fetch(apiBase() + path, opts);
    var json = await res.json().catch(function () { return {}; });
    if (!res.ok) throw new Error(json.message || 'Something went wrong. Please try again.');
    return json;
  }

  function esc(s) { return s == null ? '' : String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;'); }
  function fmtDateTime(s) { if (!s) return '-'; try { return new Date(s).toLocaleString('en-CA', { dateStyle: 'medium', timeStyle: 'short' }); } catch (e) { return s; } }
  function fmtSize(b) {
    if (!b) return '0 KB';
    if (b < 1024) return b + ' B';
    if (b < 1024 * 1024) return (b / 1024).toFixed(0) + ' KB';
    return (b / 1024 / 1024).toFixed(1) + ' MB';
  }
  function downloadUrl(id, parent) {
    return apiBase() + (parent ? '/parent' : '/director') + '/edocuments/' + id + '/download';
  }

  // ============================================================
  //  DIRECTOR VIEW
  // ============================================================
  async function renderDirector(container) {
    container.innerHTML = '<div style="padding:32px;text-align:center;color:#6B7280;">Loading documents...</div>';
    var resp;
    try { resp = await api('GET', '/director/edocuments'); }
    catch (e) {
      container.innerHTML = '<div style="padding:24px;color:#DC2626;">Documents could not be loaded. Please refresh and try again.</div>';
      return;
    }
    var docs = resp.templates || [];
    CENTRES = resp.centres || [];

    container.innerHTML =
      '<div style="padding:24px;max-width:1800px;">' +
        '<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:14px;flex-wrap:wrap;gap:12px;">' +
          '<div>' +
            '<h2 style="font-size:24px;margin:0;">eDocuments</h2>' +
            '<p style="color:#6B7280;margin:4px 0 0;font-size:13px;max-width:680px;">Upload PDF templates (waivers, permission slips, intake forms). Parents see them in their inbox and sign electronically. Tracked per family.</p>' +
          '</div>' +
          '<button id="kt-new-doc" style="background:linear-gradient(135deg,#081C41,#1F6080);color:white;border:none;padding:11px 22px;border-radius:10px;font-weight:700;cursor:pointer;box-shadow:0 6px 14px rgba(31,96,128,0.25);">+ Upload template</button>' +
        '</div>' +
        (docs.length === 0
          ? '<div style="padding:32px;text-align:center;color:#6B7280;background:white;border-radius:14px;">No templates yet. Upload your first PDF to get parents signing electronically.</div>'
          : '<div data-kt-list="1" style="display:grid;gap:14px;">' + docs.map(docCard).join('') + '</div>') +
      '</div>';

    container.querySelector('#kt-new-doc').addEventListener('click', function () { openUploadModal(container); });
    container.querySelectorAll('[data-view-sigs]').forEach(function (b) {
      b.addEventListener('click', function () { openSignaturesModal(parseInt(b.dataset.viewSigs, 10)); });
    });
    container.querySelectorAll('[data-archive]').forEach(function (b) {
      b.addEventListener('click', function () { confirmArchive(parseInt(b.dataset.archive, 10), container); });
    });
    container.querySelectorAll('[data-download]').forEach(function (a) {
      a.addEventListener('click', function (e) {
        e.preventDefault();
        downloadWithAuth(parseInt(a.dataset.download, 10), false);
      });
    });
  }

  // The centres this person may upload for (from the list call). A centre picker shows when there is more than one.
  var CENTRES = [];
  function centreName(id) {
    for (var i = 0; i < CENTRES.length; i++) { if (String(CENTRES[i].id) === String(id)) return CENTRES[i].name; }
    return '';
  }

  function docCard(d) {
    var pct = d.families_total > 0 ? Math.round((d.families_signed / d.families_total) * 100) : 0;
    var pctColor = pct === 100 ? '#16A34A' : pct >= 50 ? '#F59E0B' : '#DC2626';
    return '<div style="background:white;border-radius:14px;padding:18px;box-shadow:0 1px 4px rgba(0,0,0,.05);">' +
      '<div style="display:flex;justify-content:space-between;align-items:flex-start;gap:14px;flex-wrap:wrap;">' +
        '<div style="flex:1;min-width:0;">' +
          '<div style="font-size:17px;font-weight:700;color:#111827;">' + esc(d.name) +
            (d.status === 'archived' ? ' <span style="padding:2px 8px;background:#9CA3AF;color:white;font-size:10px;border-radius:6px;font-weight:600;margin-left:6px;">ARCHIVED</span>' : '') +
            (d.required ? ' <span style="padding:2px 8px;background:#FEF3C7;color:#92400E;font-size:10px;border-radius:6px;font-weight:600;margin-left:6px;">REQUIRED</span>' : '') +
          '</div>' +
          (d.description ? '<div style="color:#6B7280;font-size:13px;margin-top:4px;">' + esc(d.description) + '</div>' : '') +
          '<div style="color:#64748B;font-size:12px;margin-top:8px;">' +
            esc(d.original_filename) + ' &middot; ' + fmtSize(d.size_bytes) + ' &middot; uploaded ' + fmtDateTime(d.created_at) +
            (d.uploaded_by_name ? ' by ' + esc(d.uploaded_by_name) : '') +
          '</div>' +
        '</div>' +
        '<div style="text-align:right;min-width:160px;">' +
          '<div style="font-size:24px;font-weight:800;color:' + pctColor + ';">' + d.families_signed + ' / ' + d.families_total + '</div>' +
          '<div style="font-size:11px;color:#6B7280;letter-spacing:0.5px;font-weight:600;text-transform:uppercase;">families signed</div>' +
        '</div>' +
      '</div>' +
      '<div style="margin-top:12px;display:flex;gap:8px;flex-wrap:wrap;">' +
        '<a href="#" data-download="' + d.id + '" style="padding:7px 14px;background:#E5E7EB;color:#374151;text-decoration:none;border-radius:8px;font-size:13px;font-weight:600;">View PDF</a>' +
        '<button data-view-sigs="' + d.id + '" style="padding:7px 14px;background:#1F6080;color:white;border:none;border-radius:8px;font-size:13px;font-weight:600;cursor:pointer;">View signatures</button>' +
        (d.status === 'active'
          ? '<button data-archive="' + d.id + '" style="padding:7px 14px;background:#FEF3C7;color:#92400E;border:none;border-radius:8px;font-size:13px;font-weight:600;cursor:pointer;">Archive</button>'
          : '') +
      '</div>' +
    '</div>';
  }

  // Open a fetched blob. Uses a programmatic <a download> click, NOT window.open:
  // (1) window.open AFTER an `await` loses the user-gesture, so mobile browsers
  //     popup-BLOCK it — which is why tapping a document "did nothing"; and
  // (2) in the Capacitor APK WebView window.open returns a stub and opens nothing.
  // An anchor click is exempt from the popup blocker and hands the file to the
  // browser / WebView download handler.
  function openBlob(blob, filename) {
    var url = URL.createObjectURL(blob);
    var a = document.createElement('a');
    a.href = url; a.download = filename || 'document.pdf'; a.rel = 'noopener'; a.style.display = 'none';
    document.body.appendChild(a);
    a.click();
    setTimeout(function () { try { document.body.removeChild(a); } catch (e) {} URL.revokeObjectURL(url); }, 60000);
  }
  // Mobile-safe document open: the Android WebView can't handle blob: / <a download>,
  // so fetch a short-lived SIGNED https url and open THAT plain link (renders inline
  // where the WebView supports it, otherwise the system takes over). Works on desktop
  // too (opens the PDF in a new tab).
  async function openDocSigned(id) {
    try {
      var r = await api('GET', '/parent/edocuments/' + id + '/link');
      var url = r && r.url;
      if (!url) throw new Error('No link returned');
      openExternalUrl(url);
    } catch (e) {
      if (window.KT && KT.toast) KT.toast('The document could not be opened. Please try again.', 'error');
    }
  }
  // Open an https URL so a PDF actually renders. The embedded Android WebView has
  // NO PDF viewer, so on the APK we hand the URL to a Chrome Custom Tab / the system
  // browser (real Chrome renders PDFs). Desktop + PWA fall back to a new tab.
  function openExternalUrl(url) {
    try {
      var cap = window.Capacitor;
      if (cap && cap.Plugins) {
        if (cap.Plugins.Browser && typeof cap.Plugins.Browser.open === 'function') {
          cap.Plugins.Browser.open({ url: url }); return;
        }
        if (cap.Plugins.App && typeof cap.Plugins.App.openUrl === 'function') {
          cap.Plugins.App.openUrl({ url: url }); return;
        }
      }
      // Cordova-style external target, harmless where unsupported.
      if (window.cordova && window.cordova.InAppBrowser) {
        window.cordova.InAppBrowser.open(url, '_system'); return;
      }
    } catch (e) { /* fall through to the anchor */ }
    var a = document.createElement('a');
    a.href = url; a.target = '_blank'; a.rel = 'noopener';
    document.body.appendChild(a); a.click();
    setTimeout(function () { try { a.remove(); } catch (e) {} }, 300);
  }
  async function downloadWithAuth(id, parent) {
    try {
      var res = await fetch(downloadUrl(id, parent), {
        headers: { 'Authorization': 'Bearer ' + token(), 'Accept': 'application/pdf' }
      });
      if (!res.ok) throw new Error('Download failed: HTTP ' + res.status);
      var blob = await res.blob();
      var cd = res.headers.get('Content-Disposition') || '';
      var m = cd.match(/filename\*?=(?:UTF-8'')?"?([^";]+)"?/i);
      var filename = (m && decodeURIComponent(m[1].replace(/"/g, ''))) || ('document-' + id + '.pdf');
      openBlob(blob, filename);
    } catch (e) {
      if (window.KT && KT.toast) KT.toast('The document could not be opened. Please try again.', 'error');
    }
  }

  function openUploadModal(container) {
    var body = document.createElement('div');
    body.innerHTML =
      '<div style="display:grid;gap:10px;">' +
        (CENTRES.length > 1
          ? '<label style="font-size:12px;font-weight:700;color:#6B7280;">Centre</label>' +
            '<select id="kt-centre" style="padding:10px 12px;border:1px solid #D1D5DB;border-radius:8px;font-size:14px;">' +
              '<option value="">Choose a centre…</option>' +
              CENTRES.map(function (c) { return '<option value="' + esc(c.id) + '">' + esc(c.name) + '</option>'; }).join('') +
            '</select>'
          : '') +
        '<label style="font-size:12px;font-weight:700;color:#6B7280;">Document name</label>' +
        '<input id="kt-name" placeholder="Photo release form 2026" style="padding:10px 12px;border:1px solid #D1D5DB;border-radius:8px;font-size:14px;">' +
        '<label style="font-size:12px;font-weight:700;color:#6B7280;">Description (optional)</label>' +
        '<textarea id="kt-desc" rows="2" placeholder="What this form is about" style="padding:10px 12px;border:1px solid #D1D5DB;border-radius:8px;font-size:14px;font-family:inherit;resize:vertical;"></textarea>' +
        '<label style="display:flex;align-items:center;gap:8px;font-size:13px;color:#374151;">' +
          '<input id="kt-required" type="checkbox" checked> Required (parents must sign before children attend)' +
        '</label>' +
        '<label style="font-size:12px;font-weight:700;color:#6B7280;margin-top:6px;">PDF file (max 10 MB)</label>' +
        '<input id="kt-file" type="file" accept="application/pdf" style="padding:8px;border:1px solid #D1D5DB;border-radius:8px;font-size:14px;background:white;">' +
        '<div id="kt-msg" style="font-size:13px;min-height:20px;"></div>' +
      '</div>';

    Modal.open({
      title: 'Upload PDF template',
      body: body,
      actions: [
        { label: 'Cancel', style: 'btn-secondary' },
        {
          label: 'Upload',
          style: 'btn-primary',
          handler: async function () {
            var name = body.querySelector('#kt-name').value.trim();
            var file = body.querySelector('#kt-file').files[0];
            var centrePick = body.querySelector('#kt-centre');
            var centreId = centrePick ? centrePick.value : (CENTRES[0] ? String(CENTRES[0].id) : '');
            if (centrePick && !centreId) { setMsg(body, 'Choose the centre this document is for', true); return false; }
            if (!name) { setMsg(body, 'Name is required', true); return false; }
            if (!file) { setMsg(body, 'Choose a PDF file', true); return false; }
            if (file.type !== 'application/pdf') { setMsg(body, 'File must be a PDF', true); return false; }
            if (file.size > 10 * 1024 * 1024) { setMsg(body, 'PDF is larger than 10 MB', true); return false; }
            var fd = new FormData();
            fd.append('name', name);
            fd.append('description', body.querySelector('#kt-desc').value.trim());
            fd.append('required', body.querySelector('#kt-required').checked ? '1' : '0');
            fd.append('audience', 'all_families');
            if (centreId) fd.append('centre_id', centreId);
            fd.append('pdf', file);
            try {
              await api('POST', '/director/edocuments', fd, true);
              renderDirector(container);
            } catch (e) {
              setMsg(body, (e && e.message) || 'The upload did not go through. Please try again.', true);
              return false;
            }
          },
        },
      ],
    });
  }
  function setMsg(body, msg, isErr) {
    var el = body.querySelector('#kt-msg');
    el.textContent = msg;
    el.style.color = isErr ? '#DC2626' : '#16A34A';
  }

  async function openSignaturesModal(id) {
    var resp;
    try { resp = await api('GET', '/director/edocuments/' + id + '/signatures'); }
    catch (e) { if (window.KT && KT.toast) KT.toast('The signatures could not be loaded. Please try again.', 'error'); return; }
    var sigs = resp.signatures || [];
    var missing = resp.missing_families || [];

    var body = document.createElement('div');
    body.style.maxHeight = '70vh';
    body.style.overflowY = 'auto';
    body.innerHTML =
      '<h3 style="margin:0 0 10px;font-size:15px;">Signed (' + sigs.length + ')</h3>' +
      (sigs.length === 0
        ? '<p style="color:#6B7280;font-size:13px;">No signatures yet.</p>'
        : '<table style="width:100%;border-collapse:collapse;font-size:13px;margin-bottom:18px;">' +
            '<thead><tr style="background:#F9FAFB;text-align:left;"><th style="padding:8px;">Family</th><th style="padding:8px;">Signed by</th><th style="padding:8px;">When</th></tr></thead><tbody>' +
            sigs.map(function (s) {
              return '<tr style="border-top:1px solid #E5E7EB;"><td style="padding:8px;">' + esc(s.family_name) + '</td><td style="padding:8px;">' + esc(s.signed_by_name) + ' <span style="color:#64748B;">(' + esc(s.signed_by_email || '') + ')</span></td><td style="padding:8px;color:#6B7280;">' + ((s.stamp && window.KT && KT.sigStampHtml) ? KT.sigStampHtml(s.stamp, 'font-size:12px;line-height:1.5;color:#64748B') : fmtDateTime(s.signed_at)) + '</td></tr>';
            }).join('') +
            '</tbody></table>') +
      '<h3 style="margin:14px 0 10px;font-size:15px;color:#DC2626;">Not yet signed (' + missing.length + ')</h3>' +
      (missing.length === 0
        ? '<p style="color:#16A34A;font-size:13px;">Everyone has signed.</p>'
        : '<ul style="margin:0;padding:0;list-style:none;">' +
            missing.map(function (m) {
              return '<li style="padding:6px 0;border-bottom:1px solid #F3F4F6;font-size:13px;"><b>' + esc(m.family_name) + '</b> <span style="color:#6B7280;">' + esc(m.primary_email || '') + '</span></li>';
            }).join('') +
            '</ul>');

    Modal.open({ title: 'Signatures: ' + resp.template.name, body: body, large: true, actions: [{ label: 'Close', style: 'btn-secondary' }] });
  }

  function confirmArchive(id, container) {
    Modal.confirm({
      title: 'Archive document',
      message: 'Archive this template? Parents will stop seeing it in their inbox. Existing signatures stay on record.',
      confirmLabel: 'Archive',
      destructive: true,
      onConfirm: async function () {
        await api('POST', '/director/edocuments/' + id + '/archive');
        renderDirector(container);
      },
    });
  }

  // ============================================================
  //  PARENT VIEW
  // ============================================================
  async function renderParent(container) {
    container.innerHTML = '<div style="padding:32px;text-align:center;color:#6B7280;">Loading documents...</div>';
    var resp;
    try { resp = await api('GET', '/parent/edocuments'); }
    catch (e) {
      container.innerHTML = '<div style="padding:24px;color:#DC2626;">Documents could not be loaded. Please refresh and try again.</div>';
      return;
    }
    var docs = resp.templates || [];
    var pending = docs.filter(function (d) { return !d.signed; });
    var signed = docs.filter(function (d) { return d.signed; });

    container.innerHTML =
      '<div style="padding:24px;max-width:780px;">' +
        '<h2 style="font-size:24px;margin:0 0 4px;">Documents to sign</h2>' +
        '<p style="color:#6B7280;margin:0 0 18px;font-size:13px;">Forms from your childcare centre. Required ones must be signed before your child attends.</p>' +
        (pending.length === 0 && signed.length === 0
          ? '<div style="padding:28px;text-align:center;color:#6B7280;background:white;border-radius:14px;">No documents from your centre yet.</div>'
          : '') +
        (pending.length > 0
          ? '<h3 style="margin:0 0 8px;font-size:14px;color:#DC2626;letter-spacing:0.5px;text-transform:uppercase;font-weight:800;">Awaiting your signature (' + pending.length + ')</h3>' +
            '<div data-kt-list="1" style="display:grid;gap:12px;margin-bottom:24px;">' +
              pending.map(parentCard).join('') +
            '</div>'
          : '') +
        (signed.length > 0
          ? '<h3 style="margin:0 0 8px;font-size:14px;color:#16A34A;letter-spacing:0.5px;text-transform:uppercase;font-weight:800;">Signed (' + signed.length + ')</h3>' +
            '<div data-kt-list="1" style="display:grid;gap:12px;">' +
              signed.map(parentCard).join('') +
            '</div>'
          : '') +
      '</div>';

    container.querySelectorAll('[data-sign]').forEach(function (b) {
      b.addEventListener('click', function () {
        var id = parseInt(b.dataset.sign, 10);
        var fid = parseInt(b.dataset.familyId, 10);
        var name = b.dataset.docName;
        openSignModal(id, fid, name, container);
      });
    });
    container.querySelectorAll('[data-parent-download]').forEach(function (a) {
      a.addEventListener('click', function (e) {
        e.preventDefault();
        openDocSigned(parseInt(a.dataset.parentDownload, 10));
      });
    });
  }

  function parentCard(d) {
    return '<div style="background:white;border-radius:14px;padding:16px 18px;box-shadow:0 1px 4px rgba(0,0,0,.05);' + (d.signed ? '' : '--kt-edge:#DC2626;') + '">' +
      '<div style="display:flex;justify-content:space-between;align-items:flex-start;gap:12px;">' +
        '<div style="flex:1;">' +
          '<div style="font-size:16px;font-weight:700;color:#111827;">' + esc(d.name) +
            (d.required ? ' <span style="padding:2px 8px;background:#FEF3C7;color:#92400E;font-size:10px;border-radius:6px;font-weight:600;margin-left:4px;">REQUIRED</span>' : '') +
          '</div>' +
          (d.description ? '<div style="color:#6B7280;font-size:13px;margin-top:4px;">' + esc(d.description) + '</div>' : '') +
          (d.signed
            ? '<div style="color:#16A34A;font-size:12px;margin-top:6px;font-weight:600;">Signed ' + fmtDateTime(d.signed_at) + (d.signed_by_me ? ' (by you)' : '') + '</div>'
            : '') +
        '</div>' +
      '</div>' +
      '<div style="display:flex;gap:8px;margin-top:12px;">' +
        '<a href="#" data-parent-download="' + d.id + '" style="padding:7px 14px;background:#E5E7EB;color:#374151;text-decoration:none;border-radius:8px;font-size:13px;font-weight:600;">View PDF</a>' +
        (d.signed
          ? ''
          : '<button data-sign="' + d.id + '" data-family-id="' + d.family_id + '" data-doc-name="' + esc(d.name) + '" style="padding:7px 18px;background:#16A34A;color:white;border:none;border-radius:8px;font-size:13px;font-weight:700;cursor:pointer;">Sign now</button>') +
      '</div>' +
    '</div>';
  }

  function openSignModal(id, familyId, docName, container) {
    var body = document.createElement('div');
    body.innerHTML =
      '<div style="font-size:14px;color:#374151;line-height:1.55;">' +
        '<p style="margin:0 0 12px;">By signing below, you confirm you\'ve read the document <b>' + esc(docName) + '</b> and agree to its terms.</p>' +
        '<p style="margin:0 0 14px;font-size:13px;color:#6B7280;">View the PDF first if you haven\'t. Your signature will be recorded with date, time, and IP for compliance.</p>' +
      '</div>' +
      '<label style="font-size:12px;font-weight:700;color:#6B7280;display:block;margin-bottom:6px;">Type your full name (legal acknowledgement)</label>' +
      '<input id="kt-typed" placeholder="Your full name" style="width:100%;padding:10px 12px;border:1px solid #D1D5DB;border-radius:8px;font-size:14px;margin-bottom:14px;box-sizing:border-box;">' +
      '<label style="font-size:12px;font-weight:700;color:#6B7280;display:block;margin-bottom:6px;">Draw your signature</label>' +
      '<div id="kt-sig"></div>' +
      '<div style="display:flex;justify-content:space-between;margin-top:8px;align-items:center;">' +
        '<button id="kt-sig-clear" type="button" style="padding:6px 12px;background:#E5E7EB;color:#374151;border:none;border-radius:6px;font-size:12px;cursor:pointer;">Clear</button>' +
        '<span style="font-size:11px;color:#64748B;">Use mouse or finger</span>' +
      '</div>' +
      '<div id="kt-msg" style="font-size:13px;min-height:20px;margin-top:10px;"></div>';

    // The shared pad (kt-signature-pad.js) since 2026-11-06: saved signatures included.
    var sig = KT.signaturePadInline(body.querySelector('#kt-sig'), { height: 200, context: 'edocument:' + id });
    body.querySelector('#kt-sig-clear').addEventListener('click', function () { sig.clear(); });

    Modal.open({
      title: 'Sign: ' + docName,
      body: body,
      large: true,
      actions: [
        { label: 'Cancel', style: 'btn-secondary' },
        {
          label: 'Sign and submit',
          style: 'btn-primary',
          handler: async function () {
            var typed = body.querySelector('#kt-typed').value.trim();
            if (!typed) { setMsg(body, 'Type your full name to acknowledge.', true); return false; }
            if (sig.isEmpty()) { setMsg(body, 'Draw your signature in the box above.', true); return false; }
            try {
              await api('POST', '/parent/edocuments/' + id + '/sign', {
                family_id: familyId,
                typed_name: typed,
                signature_data: sig.toDataURL(),
              });
              renderParent(container);
            } catch (e) {
              setMsg(body, 'Could not save: ' + e.message, true);
              return false;
            }
          },
        },
      ],
    });
  }


  // ============================================================
  //  Shell registration
  // ============================================================
  function render(container) {
    var r = role();
    // platform_admin too (2026-11-27): a platform admin inside an agency got "available to directors and parents".
    if (r === 'agency_admin' || r === 'centre_director' || r === 'platform_admin') return renderDirector(container);
    if (r === 'guardian') return renderParent(container);
    container.innerHTML = '<div style="padding:24px;color:#6B7280;">eDocuments are available to directors and parents.</div>';
  }

  if (Shell && Shell.registerScreen) {
    ['platform_admin', 'agency_admin', 'centre_director', 'guardian'].forEach(function (r) {
      Shell.registerScreen(r + ':edocuments', render);
    });
  }
  window.KT = window.KT || {};
  window.KT.EDocuments = { render: render };
})(window);
