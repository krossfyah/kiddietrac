/* ═══════════════════════════════════════════════════════════════════
   KiddieTrac — REPORT A MESSAGE, BLOCK A PERSON (2026-11-03).

   Anthony: "Build Report + Block" - App Review asks every app with user-generated content for a way to report
   objectionable content and block abusive users (Guideline 1.2). One ⋯ on a message someone else sent opens:
     🚩 Report this message  -> a reason, an optional note, "also block" -> POST /me/content-reports
     ⛔ Block <first name>    -> their messages are hidden from you and stop notifying you -> POST /me/blocks
     ✅ Unblock <first name>  -> DELETE /me/blocks/{id}
   Server side: ContentReportController + App\Support\UserBlocks (emergency messages always come through, the agency's
   admins are told, and they review reports in Audit log -> Chat conversations -> Reported messages).

   A message screen only has to draw the button: KT.reportBlock.btnHtml(o) or KT.reportBlock.btnEl(o), with
   o = {kind:'family'|'staff', conv, mid, uid, name, blocked}. One click handler here serves every screen. A screen
   that shows a thread sets KT.reportBlock.onChange to redraw it after a block or unblock.
   ═══════════════════════════════════════════════════════════════════ */
(function (w) {
  'use strict';
  var KT = w.KT || (w.KT = {});
  if (KT.reportBlock) return;

  var REASONS = [
    ['offensive', 'Inappropriate or offensive'],
    ['harassment', 'Harassment or bullying'],
    ['spam', 'Spam or unwanted'],
    ['safety', 'A safety concern'],
    ['other', 'Something else'],
  ];

  function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }
  function first(n) { return (String(n || '').trim().split(/\s+/)[0]) || 'this person'; }
  function toast(icon, title, body, bad) {
    if (KT.toast) KT.toast(icon, title, body || '', bad ? '#DC2626' : undefined);
    else if (w.ktToast) w.ktToast(icon, title);
  }
  function said(e, fallback) { return (e && e.message) || fallback; }
  function changed() { if (typeof KT.reportBlock.onChange === 'function') { try { KT.reportBlock.onChange(); } catch (e) {} } }
  function modal(o) { return (KT.Shell && KT.Shell.Modal) ? KT.Shell.Modal.open(o) : null; }

  var BTN_CSS = 'background:none;border:none;color:inherit;opacity:.7;font-size:15px;font-weight:800;line-height:1;cursor:pointer;padding:0 3px;min-height:0;min-width:0;';

  function attrs(o) {
    return ' data-rb-kind="' + esc(o.kind) + '" data-rb-conv="' + esc(o.conv) + '" data-rb-mid="' + esc(o.mid)
      + '" data-rb-uid="' + esc(o.uid) + '" data-rb-name="' + esc(o.name) + '" data-rb-blocked="' + (o.blocked ? '1' : '0') + '"';
  }
  function btnHtml(o) {
    return '<button type="button" class="kt-rb-more" data-kt-btn-free="1"' + attrs(o) + ' title="Report or block" aria-label="Report or block '
      + esc(first(o.name)) + '" style="' + BTN_CSS + '">⋯</button>';
  }
  function btnEl(o) {
    var t = document.createElement('span');
    t.innerHTML = btnHtml(o);
    return t.firstChild;
  }
  function fromBtn(b) {
    return { kind: b.getAttribute('data-rb-kind'), conv: +b.getAttribute('data-rb-conv'), mid: +b.getAttribute('data-rb-mid'),
      uid: +b.getAttribute('data-rb-uid'), name: b.getAttribute('data-rb-name') || '', blocked: b.getAttribute('data-rb-blocked') === '1' };
  }

  /* A row in the first popup: big enough to tap on a phone, one line of what it does. */
  function option(icon, title, sub, color) {
    var b = document.createElement('button');
    b.type = 'button';
    b.setAttribute('data-kt-btn-free', '1');
    b.style.cssText = 'display:flex;align-items:flex-start;gap:12px;width:100%;text-align:left;background:#fff;border:1px solid #E2E8F0;'
      + 'border-radius:12px;padding:12px 14px;margin:0 0 10px;cursor:pointer;font:inherit;min-height:0;';
    b.innerHTML = '<span style="font-size:20px;line-height:1.2;">' + icon + '</span><span style="min-width:0;"><span style="display:block;font-weight:800;font-size:15px;color:'
      + color + ';">' + esc(title) + '</span><span style="display:block;font-size:13px;color:#64748B;margin-top:2px;line-height:1.4;">' + esc(sub) + '</span></span>';
    return b;
  }

  function open(o) {
    var who = first(o.name);
    var body = document.createElement('div');
    var rep = option('🚩', 'Report this message', 'Tell the centre’s administrators and KiddieTrac. They will review it.', '#0F172A');
    rep.addEventListener('click', function () { report(o); });
    body.appendChild(rep);
    if (o.blocked) {
      var ub = option('✅', 'Unblock ' + who, 'See ' + who + '’s messages again and get their notifications.', '#047857');
      ub.addEventListener('click', function () { unblock(o); });
      body.appendChild(ub);
    } else {
      var bl = option('⛔', 'Block ' + who, 'Hide ' + who + '’s messages from you and stop their notifications. Emergency messages still come through.', '#B91C1C');
      bl.addEventListener('click', function () { block(o); });
      body.appendChild(bl);
    }
    modal({ title: 'Message from ' + (o.name || who), body: body, actions: [{ label: 'Cancel' }] });
  }

  function report(o) {
    var who = first(o.name);
    var body = document.createElement('div');
    body.innerHTML = '<div style="font-size:13.5px;color:#334155;margin-bottom:10px;">Why are you reporting this message from <b>' + esc(o.name || who) + '</b>?</div>'
      + REASONS.map(function (r, i) {
          return '<label style="display:flex;align-items:center;gap:10px;padding:9px 4px;border-bottom:1px solid #F1F5F9;font-size:14.5px;cursor:pointer;">'
            + '<input type="radio" name="kt-rb-reason" value="' + r[0] + '"' + (i === 0 ? ' checked' : '') + ' style="width:18px;height:18px;margin:0;"> ' + esc(r[1]) + '</label>';
        }).join('')
      + '<label style="display:block;font-size:12.5px;font-weight:700;color:#475569;margin:12px 0 4px;">Anything else they should know? <span style="font-weight:500;color:#94A3B8;">(optional)</span></label>'
      + '<textarea id="kt-rb-note" rows="3" maxlength="1000" style="width:100%;box-sizing:border-box;border:1px solid #CBD5E1;border-radius:8px;padding:8px;font:inherit;font-size:14px;resize:vertical;"></textarea>'
      + (o.blocked ? '' : '<label style="display:flex;align-items:center;gap:10px;margin-top:10px;font-size:14px;cursor:pointer;"><input type="checkbox" id="kt-rb-also" style="width:18px;height:18px;margin:0;"> Also block ' + esc(who) + '</label>')
      + '<div id="kt-rb-msg" style="min-height:18px;margin-top:8px;font-size:13px;color:#B91C1C;"></div>';
    modal({ title: '🚩 Report a message', body: body, actions: [
      { label: 'Cancel' },
      { label: 'Send report', primary: true, busyLabel: 'Sending…', handler: function () {
          var pick = body.querySelector('input[name="kt-rb-reason"]:checked');
          var also = body.querySelector('#kt-rb-also');
          var block = !!(also && also.checked);
          return KT.Api.post('/me/content-reports', {
            kind: o.kind, conversation_id: o.conv, message_id: o.mid,
            reason: pick ? pick.value : 'other', note: (body.querySelector('#kt-rb-note').value || '').trim(), block: block,
          }).then(function () {
            toast('🚩', 'Report sent', 'The centre’s administrators and KiddieTrac have been told.' + (block ? ' ' + who + ' is blocked.' : ''));
            if (block) changed();
          }).catch(function (e) {
            body.querySelector('#kt-rb-msg').textContent = said(e, 'The report could not be sent. Please try again.');
            return false;
          });
        } },
    ] });
  }

  function block(o) {
    var who = first(o.name);
    var body = document.createElement('div');
    body.innerHTML = '<p style="margin:0 0 10px;font-size:14.5px;color:#0F172A;line-height:1.5;">Block <b>' + esc(o.name || who) + '</b>?</p>'
      + '<ul style="margin:0 0 6px;padding-left:18px;font-size:13.5px;color:#475569;line-height:1.6;">'
      + '<li>Their messages are hidden from you, and they no longer notify you.</li>'
      + '<li>Emergency messages still come through.</li>'
      + '<li>The centre’s administrators are told. ' + esc(who) + ' is not.</li>'
      + '<li>You can unblock them any time from ⋯ on one of their messages.</li></ul>'
      + '<div id="kt-rb-msg" style="min-height:18px;font-size:13px;color:#B91C1C;"></div>';
    modal({ title: '⛔ Block ' + who, body: body, actions: [
      { label: 'Cancel' },
      { label: 'Block', primary: true, busyLabel: 'Blocking…', handler: function () {
          return KT.Api.post('/me/blocks', { user_id: o.uid, kind: o.kind, conversation_id: o.conv }).then(function () {
            toast('⛔', who + ' is blocked', 'Their messages are hidden from you.');
            changed();
          }).catch(function (e) {
            body.querySelector('#kt-rb-msg').textContent = said(e, 'Could not block. Please try again.');
            return false;
          });
        } },
    ] });
  }

  function unblock(o) {
    var who = first(o.name);
    KT.Api.delete('/me/blocks/' + o.uid).then(function () {
      var root = document.getElementById('modalRoot'); if (root) root.innerHTML = '';
      document.documentElement.style.overflow = '';
      toast('✅', who + ' is unblocked', 'You will see their messages again.');
      changed();
    }).catch(function (e) { toast('⚠️', 'Could not unblock', said(e, ''), true); });
  }

  // One handler for every screen. Capture phase, so a bubble's own click (open photo, react) never runs instead.
  document.addEventListener('click', function (e) {
    var b = e.target && e.target.closest && e.target.closest('.kt-rb-more');
    if (!b) return;
    e.preventDefault(); e.stopPropagation();
    open(fromBtn(b));
  }, true);

  KT.reportBlock = { btnHtml: btnHtml, btnEl: btnEl, open: open, onChange: null };
})(window);
