/* ═══════════════════════════════════════════════════════════════════
   KiddieTrac — ONE "Resend invoice" popup (2026-10-31).

   Anthony: "the resend invoices should allow to cc admins/director as default in the popup or can untick".

   Every resend button (payment plans, account ledgers, iLearn invoices) opens this instead of a bare "Send to" box:
     - SEND TO: the family's billing guardians, each ticked, with their own share when the bill is split; an extra
       address can be typed for a contractor or a new email.
     - ALSO SEND A COPY TO: the agency's admins and the invoice centre's directors, ticked by default (the agency's
       reminder settings decide the defaults), each untickable. What a person unticks is remembered on this device,
       per agency, so they are not asked again. Copies are BLIND: the family never sees the office's addresses.
   Nobody is ever pre-filled who is not on the family or on the agency's staff (the server enforces the same).
   KT.resendInvoice({ kind: 'native'|'external', id, number, onDone })
   ═══════════════════════════════════════════════════════════════════ */
(function (w) {
  'use strict';
  var KT = w.KT || (w.KT = {});
  if (KT.resendInvoice) return;

  function el(tag, attrs, kids) {
    var e = document.createElement(tag);
    if (attrs) Object.keys(attrs).forEach(function (k) {
      if (k === 'style') e.style.cssText = attrs[k];
      else if (k === 'text') e.textContent = attrs[k];
      else e.setAttribute(k, attrs[k]);
    });
    (kids || []).forEach(function (c) { if (c != null) e.appendChild(typeof c === 'string' ? document.createTextNode(c) : c); });
    return e;
  }
  function api() { return KT.Api || w.Api; }
  function toast(m, kind) { try { var D = KT.Dom || w.Dom; if (D && D.toast) D.toast(m, kind || 'success'); } catch (e) {} }
  function money(n) { return '$' + Number(n || 0).toFixed(2).replace(/\B(?=(\d{3})+(?!\d))/g, ','); }
  function agencyKey() { var a = ''; try { a = sessionStorage.getItem('kt_active_agency_id') || ''; } catch (e) {} return 'kt_inv_cc_off:' + a; }
  function offList() { try { return JSON.parse(localStorage.getItem(agencyKey()) || '[]') || []; } catch (e) { return []; } }
  function saveOff(list) { try { localStorage.setItem(agencyKey(), JSON.stringify(list.slice(0, 80))); } catch (e) {} }

  var ROW = 'display:flex;align-items:center;gap:10px;padding:7px 10px;border:1px solid #E2E8F0;border-radius:10px;cursor:pointer;min-height:32px;box-sizing:border-box;';
  var HEAD = 'font-size:11px;font-weight:800;letter-spacing:.6px;text-transform:uppercase;color:#64748B;margin:4px 0 6px;';

  function line(cb, title, sub, tag) {
    return el('label', { style: ROW }, [cb,
      el('span', { style: 'flex:1;min-width:0;' }, [
        el('span', { style: 'display:block;font-size:13.5px;font-weight:700;color:#0F172A;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;', text: title }),
        sub ? el('span', { style: 'display:block;font-size:12px;color:#64748B;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;', text: sub }) : null]),
      tag ? el('span', { style: 'flex:none;font-size:11px;font-weight:700;color:#1F6080;background:#E0F2FE;border-radius:999px;padding:2px 8px;', text: tag }) : null]);
  }
  function box() { var c = el('input', { type: 'checkbox' }); c.style.cssText = 'width:18px;height:18px;margin:0;flex:none;accent-color:#1F6080;'; return c; }

  KT.resendInvoice = function (opts) {
    var kind = opts.kind === 'external' ? 'external' : 'native';
    return api().get('/admin/invoices/' + kind + '/' + opts.id + '/email-recipients').then(function (d) {
      var body = el('div', { 'data-kt-noband': '1', style: 'display:flex;flex-direction:column;gap:6px;' });
      var num = d.number || opts.number || 'this invoice';
      body.appendChild(el('div', { style: 'font-size:13px;color:#475569;margin-bottom:4px;' },
        [(d.family ? d.family + ' · ' : '') + 'Balance ' + money(d.balance) + '. The invoice is attached as a PDF.']));
      if (d.enabled === false) {
        body.appendChild(el('div', { style: 'font-size:12.5px;color:#92400E;background:#FEF3C7;border:1px solid #FDE68A;border-radius:9px;padding:8px 10px;', text: 'Email is switched off for this agency (Settings → "Send notifications and emails"), so nothing will be delivered.' }));
      }

      body.appendChild(el('div', { style: HEAD, text: 'Send to' }));
      var toBoxes = [];
      (d.guardians || []).forEach(function (g) {
        var cb = box(); cb.checked = true; toBoxes.push([cb, g.email]);
        var sub = g.email + (g.pct != null && g.pct < 99.99 ? ' · their share ' + money(g.balance) + ' (' + Math.round(g.pct) + '%)' : '');
        body.appendChild(line(cb, g.name || g.email, sub, null));
      });
      if (!(d.guardians || []).length) {
        body.appendChild(el('div', { style: 'font-size:12.5px;color:#64748B;', text: 'No guardian on this family has an email address on file. Type one below.' }));
      }
      var extra = el('input', { type: 'email', placeholder: 'Another address (optional)', autocomplete: 'off' });
      extra.style.cssText = 'height:32px;padding:0 10px;border:1px solid #CBD5E1;border-radius:8px;font-size:14px;width:100%;box-sizing:border-box;';
      body.appendChild(extra);

      var ccBoxes = [];
      if ((d.staff || []).length) {
        body.appendChild(el('div', { style: HEAD + 'margin-top:10px;', text: 'Also send a copy to' }));
        var off = offList();
        d.staff.forEach(function (s) {
          var cb = box(); cb.checked = !!s.default && off.indexOf(s.user_id) === -1; ccBoxes.push([cb, s]);
          body.appendChild(line(cb, s.name, s.email, s.role));
        });
        body.appendChild(el('div', { style: 'font-size:11.5px;color:#94A3B8;', text: 'Copies are hidden from the family. Your choices are remembered on this device.' }));
      }
      var msg = el('div', { style: 'font-size:12.5px;font-weight:700;color:#B91C1C;min-height:16px;' });
      body.appendChild(msg);

      // Phones: no keyboard popping up on open (the dialog focuses its first field).
      setTimeout(function () { try { var f = document.activeElement; if (f && f.tagName === 'INPUT' && f.type !== 'checkbox') f.blur(); } catch (e) {} }, 30);
      KT.Shell.Modal.open({
        title: '✉️ Resend ' + num,
        body: body,
        actions: [
          { label: 'Cancel' },
          { label: 'Send', primary: true, busyLabel: 'Sending…', handler: function () {
            msg.textContent = '';
            var to = toBoxes.filter(function (x) { return x[0].checked; }).map(function (x) { return x[1]; });
            var ex = (extra.value || '').trim();
            if (ex) {
              if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(ex)) { msg.textContent = 'That extra address does not look right.'; return false; }
              to.push(ex);
            }
            if (!to.length) { msg.textContent = 'Tick at least one person to send it to.'; return false; }
            var cc = ccBoxes.filter(function (x) { return x[0].checked; }).map(function (x) { return x[1].user_id; });
            // Remember who was unticked (defaults only) so the popup opens the same way next time.
            var off2 = offList().filter(function (id) { return !ccBoxes.some(function (x) { return x[1].user_id === id; }); });
            ccBoxes.forEach(function (x) { if (x[1].default && !x[0].checked) off2.push(x[1].user_id); });
            saveOff(off2);
            return api().post('/admin/invoices/' + kind + '/' + opts.id + '/email', { to_list: to, cc_users: cc }).then(function (r) {
              if (r && r.sent === false) { toast(r.reason || 'Not sent.', 'error'); return; }
              toast(num + ' sent to ' + to.length + (to.length === 1 ? ' person' : ' people') + (r && r.copies ? ' · copy to ' + r.copies + ' staff' : ''), 'success');
              if (opts.onDone) try { opts.onDone(r); } catch (e) {}
            });
          } },
        ],
      });
    });
  };

  /* ISSUE BY HAND = EMAIL THEIR COPY, TICKED (2026-10-31, Anthony chose "Ask on issue, ticked"). The Issue confirm shows
     "Email each guardian their copy" ticked, with the same Send to / Also send a copy to lists as the Resend popup.
     Untick to issue without emailing. The email goes through the same /email route as a resend, so a split family's
     guardians each get their own lettered copy, staff copies stay hidden and the agency mail switch still applies. */
  function recipients(id) { return api().get('/admin/invoices/native/' + id + '/email-recipients'); }
  function defaultCc(d) {
    var off = offList();
    return (d.staff || []).filter(function (s) { return s.default && off.indexOf(s.user_id) === -1; }).map(function (s) { return s.user_id; });
  }
  function sendEmail(id, to, cc) {
    return api().post('/admin/invoices/native/' + id + '/email', { to_list: to, cc_users: cc });
  }

  KT.issueInvoice = function (opts) {
    var num = opts.number || 'this invoice';
    return recipients(opts.id).catch(function () { return { guardians: [], staff: [] }; }).then(function (d) {
      var body = el('div', { 'data-kt-noband': '1', style: 'display:flex;flex-direction:column;gap:6px;' });
      body.appendChild(el('div', { style: 'font-size:13px;color:#475569;margin-bottom:4px;', text:
        (opts.scheduledText ? opts.scheduledText + ' ' : '') + 'Issuing makes it owed by the family straight away and it starts counting towards due and overdue reminders. The due date does not change.' }));
      var emailCb = box(); emailCb.checked = true;
      body.appendChild(line(emailCb, 'Email each guardian their copy', 'PDF attached · split bills: one copy each', null));
      if (d.enabled === false) {
        body.appendChild(el('div', { style: 'font-size:12.5px;color:#92400E;background:#FEF3C7;border:1px solid #FDE68A;border-radius:9px;padding:8px 10px;', text: 'Email is switched off for this agency, so nothing will be delivered.' }));
      }
      var lists = el('div', { style: 'display:flex;flex-direction:column;gap:6px;' });
      body.appendChild(lists);
      lists.appendChild(el('div', { style: HEAD + 'margin-top:6px;', text: 'Send to' }));
      var toBoxes = [];
      (d.guardians || []).forEach(function (g) {
        var cb = box(); cb.checked = !g.bounced; toBoxes.push([cb, g.email]);
        var sub = g.email + (g.pct != null && g.pct < 99.99 ? ' · their share ' + money(g.balance) + ' (' + Math.round(g.pct) + '%)' : '');
        lists.appendChild(line(cb, g.name || g.email, sub, g.bounced ? 'bounced' : null));
      });
      if (!(d.guardians || []).length) {
        lists.appendChild(el('div', { style: 'font-size:12.5px;color:#64748B;', text: 'No guardian on this family has an email address on file, so it will be issued without an email.' }));
      }
      var ccBoxes = [];
      if ((d.staff || []).length) {
        lists.appendChild(el('div', { style: HEAD + 'margin-top:10px;', text: 'Also send a copy to' }));
        var off = offList();
        d.staff.forEach(function (st) {
          var cb = box(); cb.checked = !!st.default && off.indexOf(st.user_id) === -1; ccBoxes.push([cb, st]);
          lists.appendChild(line(cb, st.name, st.email, st.role));
        });
        lists.appendChild(el('div', { style: 'font-size:11.5px;color:#94A3B8;', text: 'Copies are hidden from the family.' }));
      }
      emailCb.addEventListener('change', function () { lists.style.opacity = emailCb.checked ? '1' : '.45'; lists.style.pointerEvents = emailCb.checked ? '' : 'none'; });
      var msg = el('div', { style: 'font-size:12.5px;font-weight:700;color:#B91C1C;min-height:16px;' });
      body.appendChild(msg);
      setTimeout(function () { try { var f = document.activeElement; if (f && f.tagName === 'INPUT' && f.type !== 'checkbox') f.blur(); } catch (e) {} }, 30);
      return new Promise(function (resolve) {
        var settled = false;
        function done(v) { if (!settled) { settled = true; resolve(v); } }
        KT.Shell.Modal.open({
          title: '🧾 Issue ' + num + ' now?',
          body: body,
          onClose: function () { done(null); },
          actions: [
            { label: 'Cancel', handler: function () { done(null); } },
            { label: 'Issue now', primary: true, busyLabel: 'Issuing…', handler: function () {
              msg.textContent = '';
              var wantEmail = emailCb.checked;
              var to = toBoxes.filter(function (x) { return x[0].checked; }).map(function (x) { return x[1]; });
              var cc = ccBoxes.filter(function (x) { return x[0].checked; }).map(function (x) { return x[1].user_id; });
              if (wantEmail) {
                var off2 = offList().filter(function (id) { return !ccBoxes.some(function (x) { return x[1].user_id === id; }); });
                ccBoxes.forEach(function (x) { if (x[1].default && !x[0].checked) off2.push(x[1].user_id); });
                saveOff(off2);
              }
              return api().post('/director/invoices/' + opts.id + '/issue', {}).then(function (r) {
                // The server's message ends "Email it separately…", which is wrong once we email it here.
                var note = wantEmail && to.length ? num + ' issued' : ((r && r.message) || (num + ' issued'));
                if (!wantEmail || !to.length) { toast(note + (wantEmail ? ' · no email address on file' : ''), 'success'); done(r || {}); return; }
                return sendEmail(opts.id, to, cc).then(function (er) {
                  if (er && er.sent === false) { toast(note + ' · not emailed: ' + (er.reason || 'email is off'), 'error'); }
                  else { toast(note + ' · emailed to ' + to.length + (to.length === 1 ? ' person' : ' people') + (er && er.copies ? ' · copy to ' + er.copies + ' staff' : ''), 'success'); }
                  done(r || {});
                }, function (e) { toast(note + ' · but the email failed: ' + ((e && e.message) || 'try Resend'), 'error'); done(r || {}); });
              }, function (e) { msg.textContent = (e && e.message) || 'Could not issue that invoice.'; return false; });
            } },
          ],
        });
      });
    });
  };

  /* Bulk Issue: email every guardian on file their copy, with this person's remembered default staff copies. */
  KT.emailInvoiceDefault = function (id) {
    return recipients(id).then(function (d) {
      var to = (d.guardians || []).filter(function (g) { return !g.bounced; }).map(function (g) { return g.email; }).filter(Boolean);
      if (!to.length) return { sent: false, reason: 'no email on file' };
      return sendEmail(id, to, defaultCc(d));
    });
  };
})(window);
