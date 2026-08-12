/* KiddieTrac — child withdrawal from care (2026-07-31)
   Parent requests a withdrawal (guardian:withdraw); director/admin reviews and
   approves/declines (<role>:withdrawals). Backend: /parent/withdrawals + /director/withdrawals. */
(function (window) {
  'use strict';
  var KT = window.KT; if (!KT || !KT.Shell || !KT.Api || !KT.Dom) return;
  var Api = KT.Api, Dom = KT.Dom, Shell = KT.Shell;

  function esc(s) { return String(s == null ? '' : s).replace(/[&<>"]/g, function (c) { return ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' })[c]; }); }
  function fmtDate(d) { if (!d) return '—'; try { return new Date(String(d).replace(' ', 'T')).toLocaleDateString('en-CA', { year: 'numeric', month: 'short', day: 'numeric' }); } catch (e) { return d; } }
  function toast(icon, msg, color) { try { if (KT.toast) return KT.toast(icon, msg, '', color || '#159FB4'); } catch (e) {} alert(msg); }
  function statusPill(st) {
    var m = st === 'approved' ? { bg: '#DCFCE7', fg: '#15803D', t: 'Approved' }
      : st === 'denied' ? { bg: '#FEE2E2', fg: '#B91C1C', t: 'Declined' }
      : st === 'applied' ? { bg: '#E0E7FF', fg: '#3730A3', t: 'Completed' }
      : { bg: '#FEF3C7', fg: '#B45309', t: 'Pending review' };
    var s = document.createElement('span');
    s.style.cssText = 'flex:0 0 auto;font-size:11.5px;font-weight:800;padding:4px 11px;border-radius:20px;background:' + m.bg + ';color:' + m.fg + ';white-space:nowrap;';
    s.textContent = m.t; return s;
  }
  var INP = 'width:100%;box-sizing:border-box;padding:11px 12px;font-size:16px;border:1.5px solid #E3EAF1;border-radius:10px;background:#fff;color:#0D1B2A;';
  var LBL = 'display:block;font-size:12.5px;font-weight:700;color:#334155;margin:12px 0 5px;';

  // ─── Parent: request a withdrawal ───────────────────────────────────
  async function renderWithdraw(main) {
    Dom.clear(main);
    var wrap = Dom.el('div', { style: 'padding:18px 14px;max-width:720px;margin:0 auto;' });
    main.appendChild(wrap);
    var hero = Dom.el('div', { class: 'kt-hero', style: 'background:linear-gradient(135deg,#0E7C90,#7C3AED);' });
    hero.innerHTML = '<h1>🚸 Withdraw from care</h1><div class="kt-hero-sub">Request to end your child’s enrolment. The centre will review and confirm.</div>';
    wrap.appendChild(hero);
    var body = Dom.el('div', { style: 'margin-top:14px;' }); wrap.appendChild(body);
    body.appendChild(Dom.el('div', { style: 'color:#64748B;padding:16px;' }, 'Loading…'));

    var kids = [], reqs = [];
    try { var kr = await Api.get('/parent/children'); kids = kr.children || kr.data || []; } catch (e) {}
    try { var rr = await Api.get('/parent/withdrawals'); reqs = rr.data || []; } catch (e) {}
    Dom.clear(body);

    if (reqs.length) {
      var rc = Dom.el('div', { style: 'margin-bottom:16px;' });
      rc.appendChild(Dom.el('div', { style: 'font-size:12px;font-weight:800;letter-spacing:.5px;text-transform:uppercase;color:#64748B;margin:0 2px 10px;' }, 'Your requests'));
      reqs.forEach(function (r) {
        var card = Dom.el('div', { style: 'background:#fff;border:1px solid #EDF1F6;border-radius:14px;padding:13px 14px;margin-bottom:9px;box-shadow:0 1px 4px rgba(15,23,42,.05);' });
        var head = Dom.el('div', { style: 'display:flex;align-items:center;gap:8px;' });
        head.appendChild(Dom.el('div', { style: 'flex:1;min-width:0;font-weight:800;font-size:15px;color:#0F172A;' }, r.child_name));
        head.appendChild(statusPill(r.applied_at ? 'applied' : r.status));
        card.appendChild(head);
        card.appendChild(Dom.el('div', { style: 'font-size:12.5px;color:#475569;margin-top:6px;' }, 'Last day: ' + fmtDate(r.last_day) + (r.effective_date && r.status === 'approved' ? ' · effective ' + fmtDate(r.effective_date) : '')));
        if (r.reason) card.appendChild(Dom.el('div', { style: 'font-size:12px;color:#94A3B8;margin-top:3px;' }, r.reason));
        if (r.admin_note) card.appendChild(Dom.el('div', { style: 'font-size:12px;color:#B45309;margin-top:6px;background:#FEF3C7;padding:7px 10px;border-radius:8px;' }, 'Note from centre: ' + r.admin_note));
        rc.appendChild(card);
      });
      body.appendChild(rc);
    }

    var openIds = {}; reqs.forEach(function (r) { if (!r.applied_at && (r.status === 'pending' || r.status === 'approved')) openIds[r.child_id] = 1; });
    var available = kids.filter(function (k) { return !openIds[k.id]; });
    if (!available.length) {
      body.appendChild(Dom.el('div', { style: 'background:#fff;border:1px dashed #CBD5E1;border-radius:14px;padding:24px;text-align:center;color:#64748B;' }, kids.length ? 'You already have an open request on file.' : 'No children on file.'));
      return;
    }

    var fc = Dom.el('div', { style: 'background:#fff;border:1px solid #EDF1F6;border-radius:16px;padding:16px;box-shadow:0 2px 10px -4px rgba(15,23,42,.12);' });
    fc.appendChild(Dom.el('div', { style: 'font-size:15px;font-weight:800;color:#0F172A;margin-bottom:6px;' }, 'New withdrawal request'));
    var l1 = Dom.el('label', { style: LBL }, 'Child'); l1.style.marginTop = '6px'; fc.appendChild(l1);
    var sel = Dom.el('select', { style: INP });
    available.forEach(function (k) { sel.appendChild(Dom.el('option', { value: k.id }, k.display_name || ((k.first_name || '') + ' ' + (k.last_name || '')).trim())); });
    fc.appendChild(sel);
    fc.appendChild(Dom.el('label', { style: LBL }, 'Last day of care'));
    var dt = Dom.el('input', { type: 'date', style: INP }); fc.appendChild(dt);
    fc.appendChild(Dom.el('label', { style: LBL }, 'Reason (optional)'));
    var ta = Dom.el('textarea', { rows: '3', placeholder: 'Anything you’d like the centre to know…', style: INP + 'resize:vertical;' }); fc.appendChild(ta);
    var msg = Dom.el('div', { style: 'font-size:12.5px;min-height:16px;margin-top:8px;' });
    // Size comes from .kt-actionbtn (compact on desktop, full-width 44px on phones);
    // only the brand fill stays inline.
    var btn = Dom.el('button', { type: 'button', class: 'kt-actionbtn', style: 'margin-top:10px;background:linear-gradient(135deg,#0E7C90,#1F6080);color:#fff;border:0;cursor:pointer;' }, 'Submit request');
    btn.addEventListener('click', async function () {
      if (!dt.value) { msg.style.color = '#B45309'; msg.textContent = 'Please pick a last day.'; return; }
      btn.disabled = true; btn.textContent = 'Submitting…';
      try {
        await Api.post('/parent/withdrawals', { child_id: parseInt(sel.value, 10), last_day: dt.value, reason: ta.value.trim() || null });
        toast('📤', 'Request submitted', '#0E7C90');
        renderWithdraw(main);
      } catch (e) { btn.disabled = false; btn.textContent = 'Submit request'; msg.style.color = '#B91C1C'; msg.textContent = (e && e.message) || 'Could not submit — please try again.'; }
    });
    fc.appendChild(msg); fc.appendChild(btn);
    body.appendChild(fc);
  }

  // ─── Director/admin: review withdrawals ─────────────────────────────
  async function renderWithdrawals(main) {
    Dom.clear(main);
    var wrap = Dom.el('div', { style: 'padding:18px 14px;max-width:900px;margin:0 auto;' });
    main.appendChild(wrap);
    var h = Dom.el('div', { style: 'margin:2px 2px 12px;' });
    h.innerHTML = '<h2 style="margin:0;font-size:20px;color:#0F172A;">🚸 Withdrawal requests</h2><div style="font-size:12.5px;color:#64748B;margin-top:2px;">Review parents’ requests to end enrolment.</div>';
    wrap.appendChild(h);
    var countEl = Dom.el('div', { style: 'font-size:12px;color:#64748B;margin:0 2px 10px;font-weight:600;' }, '');
    wrap.appendChild(countEl);
    var list = Dom.el('div', { 'data-kt-list': '1' }); wrap.appendChild(list);
    list.appendChild(Dom.el('div', { style: 'color:#64748B;padding:16px;' }, 'Loading…'));
    var rows = [];
    try { var r = await Api.get('/director/withdrawals'); rows = r.data || []; }
    catch (e) { Dom.clear(list); list.appendChild(Dom.el('div', { style: 'color:#B91C1C;padding:16px;' }, 'Could not load requests.')); return; }
    Dom.clear(list);
    if (!rows.length) { countEl.textContent = ''; list.appendChild(Dom.el('div', { style: 'background:#fff;border:1px dashed #CBD5E1;border-radius:14px;padding:34px 16px;text-align:center;color:#64748B;' }, 'No withdrawal requests.')); return; }
    countEl.textContent = 'Showing ' + rows.length + ' request' + (rows.length === 1 ? '' : 's');
    rows.forEach(function (r) { list.appendChild(wdCard(r, main)); });
  }

  function wdCard(r, main) {
    var pending = r.status === 'pending';
    var card = Dom.el('div', { style: 'background:#fff;border:1px solid #EDF1F6;border-left:5px solid ' + (pending ? '#F59E0B' : (r.status === 'approved' ? '#16A34A' : '#94A3B8')) + ';border-radius:15px;padding:14px;margin-bottom:11px;box-shadow:0 2px 8px -3px rgba(15,23,42,.12);' });
    var head = Dom.el('div', { style: 'display:flex;align-items:center;gap:8px;' });
    var mid = Dom.el('div', { style: 'flex:1;min-width:0;' });
    mid.appendChild(Dom.el('div', { style: 'font-weight:800;font-size:15.5px;color:#0F172A;' }, r.child_name));
    mid.appendChild(Dom.el('div', { style: 'font-size:12px;color:#64748B;margin-top:1px;' }, (r.family_name || '') + ' · by ' + (r.requested_by || '—')));
    head.appendChild(mid);
    head.appendChild(statusPill(r.applied_at ? 'applied' : r.status));
    card.appendChild(head);
    card.appendChild(Dom.el('div', { style: 'font-size:13px;color:#334155;margin-top:10px;padding-top:10px;border-top:1px solid #F1F5F9;' }, '📅 Requested last day: ' + fmtDate(r.last_day) + (r.effective_date ? ' · effective ' + fmtDate(r.effective_date) : '')));
    if (r.reason) card.appendChild(Dom.el('div', { style: 'font-size:12.5px;color:#64748B;margin-top:6px;line-height:1.45;' }, 'Reason: ' + r.reason));
    if (r.admin_note) card.appendChild(Dom.el('div', { style: 'font-size:12px;color:#B45309;margin-top:6px;background:#FEF3C7;padding:7px 10px;border-radius:8px;' }, 'Your note: ' + r.admin_note));
    if (pending) {
      card.appendChild(Dom.el('div', { style: 'font-size:11.5px;font-weight:700;color:#64748B;margin-top:11px;' }, 'Effective date (when access is removed)'));
      var eff = Dom.el('input', { type: 'date', value: String(r.last_day || '').slice(0, 10), style: 'margin-top:5px;width:100%;box-sizing:border-box;padding:10px;font-size:16px;border:1.5px solid #E3EAF1;border-radius:10px;' });
      var note = Dom.el('input', { type: 'text', placeholder: 'Optional note to the parent…', style: 'margin-top:8px;width:100%;box-sizing:border-box;padding:10px;font-size:16px;border:1.5px solid #E3EAF1;border-radius:10px;' });
      card.appendChild(eff); card.appendChild(note);
      var actions = Dom.el('div', { style: 'display:flex;gap:8px;margin-top:12px;' });
      var ap = Dom.el('button', { type: 'button', style: 'flex:1;background:#16A34A;color:#fff;border:0;padding:11px;border-radius:11px;font-size:14px;font-weight:800;cursor:pointer;min-height:0;' }, 'Approve');
      var dn = Dom.el('button', { type: 'button', style: 'flex:1;background:#fff;color:#B91C1C;border:1.5px solid #FCA5A5;padding:11px;border-radius:11px;font-size:14px;font-weight:800;cursor:pointer;min-height:0;' }, 'Decline');
      var decide = async function (status) {
        ap.disabled = dn.disabled = true;
        try {
          await Api.post('/director/withdrawals/' + r.id + '/decide', { status: status, note: note.value.trim() || null, effective_date: eff.value || null });
          toast(status === 'approved' ? '✅' : '✋', status === 'approved' ? 'Approved' : 'Declined', status === 'approved' ? '#16A34A' : '#B91C1C');
          renderWithdrawals(main);
        } catch (e) { ap.disabled = dn.disabled = false; toast('⚠️', (e && e.message) || 'Could not save', '#B91C1C'); }
      };
      ap.addEventListener('click', async function () {
        if (window.KT && KT.confirm) { if (!await KT.confirm('Approve this withdrawal? On the effective date the child is set inactive and educators lose access to their record.')) return; }
        decide('approved');
      });
      dn.addEventListener('click', function () { decide('denied'); });
      actions.appendChild(ap); actions.appendChild(dn);
      card.appendChild(actions);
    }
    return card;
  }

  Shell.registerScreen('guardian:withdraw', renderWithdraw);
  ['centre_director', 'agency_admin', 'platform_admin'].forEach(function (role) { Shell.registerScreen(role + ':withdrawals', renderWithdrawals); });
  window.KT.Withdrawals = { renderWithdraw: renderWithdraw, renderWithdrawals: renderWithdrawals };
})(window);
