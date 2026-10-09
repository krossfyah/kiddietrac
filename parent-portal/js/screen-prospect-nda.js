/* ═══════════════════════════════════════════════════════════════════════
   Prospect NDAs (#prospect-ndas) — 2026-10-09

   Anthony: "wire something up in the portal to send our NDA manually to a prospect that is
   not a customer today maybe add this in forms manager as an option". Platform admins and
   sales reps send our v2.0 NDA to a name + email; the prospect signs from a private link,
   adding their business legal name, full legal business address and role. The signed PDF
   goes to them and sales@kiddietrac.com, and is logged on the lead when one is chosen.
   Reached from Forms Manager (button) and the Sales menu.
   ═══════════════════════════════════════════════════════════════════════ */
(function (window) {
  'use strict';
  var KT = window.KT;
  if (!KT || !KT.Shell || !KT.Shell.registerScreen) { return; }
  var Api = KT.Api;

  function esc(s) { return String(s == null ? '' : s).replace(/[&<>"]/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]; }); }
  function when(v) { if (!v) return '—'; try { return new Date(String(v).replace(' ', 'T') + (String(v).indexOf('Z') === -1 && String(v).indexOf('+') === -1 ? 'Z' : '')).toLocaleString('en-CA', { timeZone: 'America/Toronto', month: 'short', day: 'numeric', year: 'numeric', hour: 'numeric', minute: '2-digit' }); } catch (e) { return v; } }
  var PILL = {
    sent: ['#EFF6FF', '#1D4ED8', 'Sent'], signed: ['#ECFDF5', '#047857', '✅ Signed'],
    expired: ['#FFFBEB', '#B45309', 'Link expired'], cancelled: ['#F1F5F9', '#64748B', 'Cancelled'],
  };

  /* Open an NDA PDF in the portal's viewer: the signed copy, or a preview of what was sent. */
  async function openNdaPdf(r, kind) {
    try {
      var res = await fetch((KT.API_BASE || 'https://api.kiddietrac.com/api/v1') + '/admin/prospect-ndas/' + r.id + '/' + kind,
        { headers: { Authorization: 'Bearer ' + (sessionStorage.getItem('kt_token') || localStorage.getItem('kt_token') || '') } });
      if (!res.ok) throw new Error('HTTP ' + res.status);
      var blob = await res.blob();
      var title = (kind === 'preview' && r.status !== 'signed' ? 'NDA preview — ' : 'Signed NDA — ') + (r.business_name || r.company || r.name);
      if (KT.viewBlob) KT.viewBlob(blob, { title: title, label: kind === 'preview' ? 'NDA preview' : 'Signed NDA' });
      else window.open(URL.createObjectURL(blob), '_blank');
    } catch (e) { if (KT.Dom && KT.Dom.toast) KT.Dom.toast('Could not open the NDA', 'error'); }
  }
  KT.openNdaPdf = openNdaPdf;

  async function render(main) {
    main.setAttribute('data-kt-pretty', '1');
    main.innerHTML = '<div style="padding:14px 24px;max-width:1100px;"><div id="pn-body" style="color:#64748B;">Loading…</div></div>';
    var body = main.querySelector('#pn-body');
    var data;
    try { data = await Api.get('/admin/prospect-ndas'); }
    catch (e) { body.innerHTML = '<div class="kt-card">' + esc((e && e.message) || 'Could not load') + '</div>'; return; }

    var rows = data.ndas || [];
    body.innerHTML = '<div style="display:flex;justify-content:space-between;align-items:center;gap:10px;flex-wrap:wrap;margin-bottom:14px;">'
      + '<div style="font-size:13.5px;color:#475569;max-width:680px;">Send our NDA (version ' + esc(data.version) + ') to a prospect who is not a customer yet. '
      + 'They sign from a private link and add their business legal name, full legal address and role. The signed copy goes to them and sales@kiddietrac.com.</div>'
      + '<button type="button" id="pn-new" class="kt-btn kt-btn-primary">🤝 Send NDA to a prospect</button></div>'
      + (rows.length ? '<div style="overflow-x:auto;background:#fff;border:1px solid #E7EDF3;border-radius:14px;"><table data-kt-paginate="25" style="width:100%;border-collapse:collapse;font-size:13.5px;min-width:760px;">'
        + '<thead><tr style="background:#F8FAFC;">' + ['Prospect', 'Business', 'Status', 'Sent', 'Signed', ''].map(function (h) { return '<th style="text-align:left;padding:10px 14px;font-size:11px;font-weight:800;color:#64748B;text-transform:uppercase;letter-spacing:.4px;">' + h + '</th>'; }).join('') + '</tr></thead><tbody>'
        + rows.map(function (r, i) {
          var p = PILL[r.status] || PILL.sent;
          return '<tr style="border-top:1px solid #F1F5F9;">'
            + '<td style="padding:10px 14px;"><div style="font-weight:700;">' + esc(r.name) + '</div><div style="font-size:12px;color:#64748B;">' + esc(r.email) + (r.lead ? ' · lead: ' + esc(r.lead) : '') + '</div></td>'
            + '<td style="padding:10px 14px;">' + (r.business_name ? '<div style="font-weight:600;">' + esc(r.business_name) + '</div><div style="font-size:12px;color:#64748B;">' + esc(r.signer_name || '') + (r.role_title ? ', ' + esc(r.role_title) : '') + '</div>' : '<span style="color:#94A3B8;">' + esc(r.company || '—') + '</span>') + '</td>'
            + '<td style="padding:10px 14px;white-space:nowrap;"><span style="padding:2px 9px;border-radius:999px;font-size:12px;font-weight:700;background:' + p[0] + ';color:' + p[1] + ';">' + p[2] + '</span>' + (r.status === 'sent' && r.opened_at ? '<div style="font-size:11.5px;color:#64748B;margin-top:3px;">Opened ' + esc(when(r.opened_at)) + '</div>' : '') + '</td>'
            + '<td style="padding:10px 14px;white-space:nowrap;font-size:12.5px;color:#475569;">' + esc(when(r.sent_at)) + (r.sent_by ? '<div style="color:#94A3B8;">by ' + esc(r.sent_by) + '</div>' : '') + '</td>'
            + '<td style="padding:10px 14px;white-space:nowrap;font-size:12.5px;color:#475569;">' + esc(when(r.signed_at)) + '</td>'
            + '<td style="padding:10px 14px;text-align:right;white-space:nowrap;">'
            + (r.has_pdf ? '<button type="button" class="kt-btn" data-pdf="' + i + '" data-kttip="View signed PDF">📄 View signed PDF</button>'
                : '<button type="button" class="kt-btn" data-preview="' + i + '" data-kttip="Preview NDA">👁 Preview NDA</button>')
            + (r.status !== 'signed' && r.status !== 'cancelled' ? '<button type="button" class="kt-btn" data-resend="' + i + '" data-kttip="Resend link">📤 Resend link</button><button type="button" class="kt-btn" data-cancel="' + i + '" data-kttip="Cancel">✖ Cancel</button>' : '')
            + (r.status === 'expired' ? '<button type="button" class="kt-btn" data-resend="' + i + '" data-kttip="Send a new link">📤 Send a new link</button>' : '')
            + '</td></tr>';
        }).join('') + '</tbody></table></div>'
        : '<div class="kt-card" style="text-align:center;color:#64748B;padding:30px;">No NDAs sent yet.</div>');

    body.querySelector('#pn-new').addEventListener('click', function () { openSend(data.leads || [], function () { render(main); }); });
    body.querySelectorAll('[data-pdf]').forEach(function (b) {
      b.addEventListener('click', function () { openNdaPdf(rows[+b.getAttribute('data-pdf')], 'pdf'); });
    });
    body.querySelectorAll('[data-preview]').forEach(function (b) {
      b.addEventListener('click', function () { openNdaPdf(rows[+b.getAttribute('data-preview')], 'preview'); });
    });
    body.querySelectorAll('[data-resend]').forEach(function (b) {
      b.addEventListener('click', function () {
        var r = rows[+b.getAttribute('data-resend')];
        b.disabled = true;
        Api.post('/admin/prospect-ndas/' + r.id + '/resend', {}).then(function (x) {
          if (KT.Dom && KT.Dom.toast) KT.Dom.toast(x.email === 'sent' ? 'New link sent to ' + r.email : 'Link created; email: ' + x.email, x.email === 'sent' ? 'success' : 'error');
          render(main);
        }).catch(function (e) { b.disabled = false; if (KT.Dom && KT.Dom.toast) KT.Dom.toast((e && e.message) || 'Could not resend', 'error'); });
      });
    });
    body.querySelectorAll('[data-cancel]').forEach(function (b) {
      b.addEventListener('click', async function () {
        var r = rows[+b.getAttribute('data-cancel')];
        var ok = !(KT.confirm) || await KT.confirm({ title: 'Cancel the NDA to ' + r.name + '?', description: 'Their link stops working. You can send a new one later.', okLabel: 'Cancel NDA' });
        if (!ok) return;
        Api.delete('/admin/prospect-ndas/' + r.id).then(function () { render(main); })
          .catch(function (e) { if (KT.Dom && KT.Dom.toast) KT.Dom.toast((e && e.message) || 'Could not cancel', 'error'); });
      });
    });
  }

  function openSend(leads, onDone) {
    var F = 'width:100%;box-sizing:border-box;padding:10px 12px;border:1.5px solid #E2E8F0;border-radius:10px;font-size:14px;font-family:inherit;';
    var L = 'display:block;font-size:11px;font-weight:800;color:#64748B;text-transform:uppercase;letter-spacing:.4px;margin:12px 0 5px;';
    var box = document.createElement('div');
    box.innerHTML = '<label style="' + L + 'margin-top:0;">Link to a sales lead (optional)</label>'
      + '<select id="pn-lead" style="' + F + 'background:#fff;"><option value="">— No lead —</option>'
      + leads.map(function (l) { return '<option value="' + l.id + '">' + esc((l.company || '') + (l.company && l.name ? ' — ' : '') + (l.name || '')) + '</option>'; }).join('') + '</select>'
      + '<label style="' + L + '">Prospect’s name *</label><input id="pn-name" style="' + F + '">'
      + '<label style="' + L + '">Email *</label><input id="pn-email" type="email" style="' + F + '">'
      + '<label style="' + L + '">Company (optional — they confirm the legal name)</label><input id="pn-co" style="' + F + '">'
      + '<label style="' + L + '">Personal note (optional)</label><textarea id="pn-note" rows="3" style="' + F + '"></textarea>'
      + '<div id="pn-msg" style="font-size:13px;margin-top:10px;min-height:18px;"></div>';
    var byId = {}; leads.forEach(function (l) { byId[l.id] = l; });
    box.querySelector('#pn-lead').addEventListener('change', function () {
      var l = byId[this.value]; if (!l) return;
      if (!box.querySelector('#pn-name').value) box.querySelector('#pn-name').value = l.name || '';
      if (!box.querySelector('#pn-email').value) box.querySelector('#pn-email').value = l.email || '';
      if (!box.querySelector('#pn-co').value) box.querySelector('#pn-co').value = l.company || '';
    });
    KT.Shell.Modal.open({
      title: '🤝 Send our NDA to a prospect',
      body: box,
      actions: [{ label: 'Cancel' }, {
        label: 'Send NDA', primary: true, busyLabel: 'Sending…',
        onClick: async function () {
          var msg = box.querySelector('#pn-msg');
          var name = box.querySelector('#pn-name').value.trim(), email = box.querySelector('#pn-email').value.trim();
          if (!name || !/^[^@\s]+@[^@\s]+\.[^@\s]+$/.test(email)) { msg.style.color = '#B91C1C'; msg.textContent = 'A name and a valid email are needed.'; return false; }
          try {
            var r = await Api.post('/admin/prospect-ndas', { name: name, email: email, company: box.querySelector('#pn-co').value.trim() || null,
              lead_id: +box.querySelector('#pn-lead').value || null, note: box.querySelector('#pn-note').value.trim() || null });
            if (KT.Dom && KT.Dom.toast) KT.Dom.toast(r.message || 'NDA sent', r.email === 'sent' ? 'success' : 'error');
            onDone();
          } catch (e) { msg.style.color = '#B91C1C'; msg.textContent = (e && e.message) || 'Could not send'; return false; }
        },
      }],
    });
  }

  KT.openProspectNdaSend = function () { location.hash = '#prospect-ndas'; };
  ['platform_admin', 'sales_rep'].forEach(function (r) { KT.Shell.registerScreen(r + ':prospect-ndas', render); });
})(window);
