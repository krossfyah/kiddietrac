/* ═══════════════════════════════════════════════════════════════════
   KiddieTrac — Parent issues (staff view).

   2026-10-13: the other side of #report-issue. Directors and admins see their centres'
   reports; an educator sees reports about the children in their own rooms, never a concern
   about a staff member (the server enforces both). Open one to reply to the family, leave
   an internal note the family never sees, change the status or assign it.

   #parent-issues?id=N opens that report straight away (the bell links there).
   API: GET /parent-issues, GET /parent-issues/{id}, PATCH /parent-issues/{id},
        POST /parent-issues/{id}/messages (ParentIssueController).
   ═══════════════════════════════════════════════════════════════════ */
(function (window) {
  'use strict';
  var KT = window.KT || (window.KT = {});
  function api() { return window.Api || KT.Api; }
  function toast(m, kind) { var D = KT.Dom || window.Dom; if (D && D.toast) D.toast(m, kind); }
  function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }

  var CHIP = {
    open:        ['#FEF3C7', '#92400E'],
    in_progress: ['#E0F2FE', '#075985'],
    resolved:    ['#DCFCE7', '#166534'],
    closed:      ['#F1F5F9', '#475569'],
  };
  var SEL = 'height:30px;padding:0 8px;border:1px solid #CBD5E1;border-radius:8px;font-size:13px;background:#fff;color:#0F172A;max-width:100%;';
  var BTN = 'height:30px;padding:0 12px;border-radius:8px;font-weight:800;font-size:12.5px;cursor:pointer;white-space:nowrap;';
  var filt = { status: 'active', category: '', centre_id: '' };

  function chip(st, label) {
    var c = CHIP[st] || CHIP.closed;
    return '<span style="background:' + c[0] + ';color:' + c[1] + ';font-size:11.5px;font-weight:800;padding:2px 9px;border-radius:999px;white-space:nowrap;">' + esc(label) + '</span>';
  }
  function hashId() { var m = /[?&]id=(\d+)/.exec(window.location.hash || ''); return m ? m[1] : null; }

  async function render(main) {
    main.setAttribute('data-kt-no-autorefresh', '1');
    if (!main.querySelector('#pi-root')) main.innerHTML = '<div id="pi-root" style="padding:20px 16px 90px;max-width:1200px;margin:0 auto;"><div style="color:#64748B;">Loading parent issues…</div></div>';
    var root = main.querySelector('#pi-root');
    var qs = Object.keys(filt).filter(function (k) { return filt[k]; }).map(function (k) { return k + '=' + encodeURIComponent(filt[k]); }).join('&');
    var d;
    try { d = await api().get('/parent-issues' + (qs ? '?' + qs : '')); }
    catch (e) { if (root.isConnected) root.innerHTML = '<div style="color:#B91C1C;">' + esc((e && e.message) || 'Could not load parent issues.') + '</div>'; return; }
    if (!root.isConnected) return;
    var c = d.counts || {};
    var kpi = function (label, n, bg, fg) {
      return '<div style="flex:1;min-width:120px;background:' + bg + ';border-radius:12px;padding:10px 14px;"><div style="font-size:11px;font-weight:800;letter-spacing:.4px;text-transform:uppercase;color:' + fg + ';">' + label + '</div><div style="font-size:22px;font-weight:900;color:' + fg + ';">' + (n || 0) + '</div></div>';
    };
    root.innerHTML = '<p style="color:#475569;font-size:13.5px;margin:0 0 12px;line-height:1.5;">Issues parents have reported from their app. Reply to the family, add an internal note, change the status or assign it.'
      + (d.role === 'educator' ? ' You see reports about the children in your rooms.' : '') + '</p>'
      + '<div style="display:flex;gap:10px;flex-wrap:wrap;margin-bottom:12px;">'
      + kpi('Open', c.open, '#FFFBEB', '#92400E') + kpi('In progress', c.in_progress, '#F0F9FF', '#075985')
      + kpi('Resolved', c.resolved, '#F0FDF4', '#166534') + kpi('Closed', c.closed, '#F8FAFC', '#475569') + '</div>'
      + '<div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;margin-bottom:12px;">'
      + '<select data-f="status" style="' + SEL + '">'
      +   [['active', 'Open & in progress'], ['open', 'Open'], ['in_progress', 'In progress'], ['resolved', 'Resolved'], ['closed', 'Closed'], ['all', 'All']]
            .map(function (o) { return '<option value="' + o[0] + '"' + (filt.status === o[0] ? ' selected' : '') + '>' + o[1] + '</option>'; }).join('') + '</select>'
      + '<select data-f="category" style="' + SEL + '"><option value="">All categories</option>'
      +   (d.categories || []).map(function (o) { return '<option value="' + esc(o.key) + '"' + (filt.category === o.key ? ' selected' : '') + '>' + esc(o.label) + '</option>'; }).join('') + '</select>'
      + ((d.centres || []).length > 1 ? '<select data-f="centre_id" style="' + SEL + '"><option value="">All centres</option>'
      +   d.centres.map(function (o) { return '<option value="' + o.id + '"' + (String(filt.centre_id) === String(o.id) ? ' selected' : '') + '>' + esc(o.name) + '</option>'; }).join('') + '</select>' : '')
      + '<button type="button" data-refresh style="' + BTN + 'border:1px solid #CBD5E1;background:#fff;color:#1F6080;">↻ Refresh</button></div>'
      + '<div id="pi-list">' + ((d.issues || []).length ? d.issues.map(function (i) {
          return '<button type="button" data-open="' + i.id + '" style="display:block;width:100%;text-align:left;background:#fff;border:1px solid ' + (i.sensitive ? '#FCA5A5' : '#E5E7EB') + ';border-radius:12px;padding:11px 14px;margin-bottom:8px;cursor:pointer;font:inherit;color:inherit;">'
            + '<div style="display:flex;justify-content:space-between;gap:8px;flex-wrap:wrap;align-items:center;">'
            + '<div style="font-weight:800;font-size:14px;color:#0F172A;">' + (i.sensitive ? '⚠️ ' : '') + esc(i.category_label) + (i.subcategory ? ' · <span style="font-weight:600;">' + esc(i.subcategory) + '</span>' : '') + '</div>'
            + chip(i.status, i.status_label) + '</div>'
            + '<div style="font-size:12.5px;color:#475569;margin-top:3px;">' + esc(i.reporter) + ' · ' + esc(i.family) + (i.child ? ' · about ' + esc(i.child) : '')
            + ((d.centres || []).length > 1 ? ' · ' + esc(i.centre) : '') + ' · ' + esc(i.created)
            + (i.assigned_name ? ' · assigned to ' + esc(i.assigned_name) : '') + (i.replies ? ' · ' + i.replies + ' message' + (i.replies === 1 ? '' : 's') : '') + '</div>'
            + '<div style="font-size:13px;color:#334155;margin-top:5px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">' + esc(i.notes) + '</div></button>';
        }).join('') : '<div style="padding:14px 16px;background:#F0FDF4;border-radius:12px;color:#166534;font-weight:700;font-size:13.5px;">Nothing here. ✓</div>') + '</div>';

    root.querySelectorAll('[data-f]').forEach(function (s) { s.onchange = function () { filt[s.getAttribute('data-f')] = s.value; render(main); }; });
    root.querySelector('[data-refresh]').onclick = function () { render(main); };
    root.querySelectorAll('[data-open]').forEach(function (b) { b.onclick = function () { detail(main, b.getAttribute('data-open')); }; });
    var want = hashId();
    if (want && !document.querySelector('.pi-modal')) {
      detail(main, want);
      try { history.replaceState(null, '', '#parent-issues'); } catch (e) {}
    }
  }

  async function detail(main, id) {
    var d;
    try { d = await api().get('/parent-issues/' + id); }
    catch (e) { toast((e && e.message) || 'Could not open that report', 'error'); return; }
    var i = d.issue;
    var m = document.createElement('div');
    m.className = 'pi-modal';
    m.setAttribute('role', 'dialog');
    m.style.cssText = 'position:fixed;inset:0;background:rgba(15,23,42,.5);display:flex;align-items:center;justify-content:center;z-index:10000;padding:12px;';
    var thread = (d.messages || []).map(function (x) {
      var bg = x.internal ? 'background:#FFFBEB;border-left:3px solid #F59E0B;' : (x.side === 'parent' ? 'background:#F1F5F9;' : 'background:#EFF6FF;border-left:3px solid #1F6080;');
      return '<div style="margin-top:8px;padding:8px 11px;border-radius:10px;font-size:13.5px;line-height:1.45;white-space:pre-wrap;' + bg + '">'
        + '<div style="font-size:11.5px;font-weight:800;color:#475569;margin-bottom:2px;">' + esc(x.who) + (x.side === 'parent' ? ' (parent)' : '') + (x.internal ? ' · 🔒 internal note' : '') + ' · ' + esc(x.at) + '</div>' + esc(x.body) + '</div>';
    }).join('');
    m.innerHTML = '<div style="background:#fff;border-radius:14px;max-width:640px;width:100%;max-height:90vh;overflow:auto;padding:18px 20px;">'
      + '<div style="display:flex;justify-content:space-between;gap:10px;align-items:flex-start;">'
      + '<h3 style="margin:0;font-size:17px;color:#0F172A;">' + (i.sensitive ? '⚠️ ' : '') + esc(i.category_label) + (i.subcategory ? ' · ' + esc(i.subcategory) : '') + '</h3>'
      + '<button type="button" data-x aria-label="Close" style="border:0;background:none;font-size:20px;line-height:1;cursor:pointer;color:#64748B;">×</button></div>'
      + '<div style="font-size:12.5px;color:#475569;margin-top:4px;line-height:1.5;">' + esc(i.reporter) + ' · ' + esc(i.family) + (i.child ? ' · about <b>' + esc(i.child) + '</b>' : ' · about the family') + ' · ' + esc(i.centre) + '<br>Reported ' + esc(i.created)
      + (i.reporter_phone ? ' · <a href="tel:' + esc(i.reporter_phone) + '" style="color:#1F6080;font-weight:700;">📞 ' + esc(i.reporter_phone) + '</a>' : '')
      + (i.reporter_email ? ' · <a href="mailto:' + esc(i.reporter_email) + '" style="color:#1F6080;font-weight:700;">' + esc(i.reporter_email) + '</a>' : '') + '</div>'
      + (i.sensitive ? '<div style="margin-top:8px;font-size:12.5px;color:#991B1B;font-weight:700;">A concern about a staff member — shown to directors and admins only.</div>' : '')
      + '<div style="margin-top:10px;padding:10px 12px;border-radius:10px;background:#F8FAFC;font-size:14px;line-height:1.5;white-space:pre-wrap;">' + esc(i.notes) + '</div>'
      + thread
      + '<div style="display:flex;gap:10px;flex-wrap:wrap;margin-top:14px;align-items:flex-end;">'
      + '<label style="font-size:12px;font-weight:700;color:#374151;">Status<br><select data-status style="' + SEL + 'margin-top:4px;">'
      +   Object.keys(d.statuses).map(function (k) { return '<option value="' + k + '"' + (i.status === k ? ' selected' : '') + '>' + esc(d.statuses[k]) + '</option>'; }).join('') + '</select></label>'
      + (d.can_assign ? '<label style="font-size:12px;font-weight:700;color:#374151;">Assigned to<br><select data-assign style="' + SEL + 'margin-top:4px;"><option value="">Nobody</option>'
      +   (d.assignable || []).map(function (p) { return '<option value="' + p.id + '"' + (i.assigned_to === p.id ? ' selected' : '') + '>' + esc(p.name) + '</option>'; }).join('') + '</select></label>' : '')
      + '</div>'
      + '<label style="display:block;font-size:12px;font-weight:700;color:#374151;margin:12px 0 4px;">Reply</label>'
      + '<textarea data-reply rows="3" maxlength="4000" style="width:100%;box-sizing:border-box;padding:8px 10px;border:1px solid #CBD5E1;border-radius:8px;font-size:14px;font-family:inherit;resize:vertical;" placeholder="Write to the family…"></textarea>'
      + '<label style="display:flex;align-items:center;gap:8px;font-size:12.5px;color:#475569;margin-top:6px;"><input type="checkbox" data-internal> 🔒 Internal note — the family will not see it</label>'
      + '<div data-err style="color:#B91C1C;font-size:13px;min-height:18px;margin-top:6px;"></div>'
      + '<div style="display:flex;justify-content:flex-end;gap:8px;">'
      + '<button type="button" data-x style="' + BTN + 'border:0;background:#F1F5F9;color:#334155;">Close</button>'
      + '<button type="button" data-save style="' + BTN + 'border:0;background:#1F6080;color:#fff;">Save</button></div></div>';
    document.body.appendChild(m);
    var close = function () { m.remove(); };
    m.querySelectorAll('[data-x]').forEach(function (b) { b.onclick = close; });
    m.querySelector('[data-save]').onclick = async function () {
      var b = this, err = m.querySelector('[data-err]');
      err.textContent = '';
      var reply = m.querySelector('[data-reply]').value.trim();
      var internal = m.querySelector('[data-internal]').checked;
      var st = m.querySelector('[data-status]').value;
      var as = m.querySelector('[data-assign]');
      var patch = {};
      if (st !== i.status) patch.status = st;
      if (as && String(as.value || '') !== String(i.assigned_to || '')) patch.assigned_to = as.value ? parseInt(as.value, 10) : null;
      if (!reply && !Object.keys(patch).length) { close(); return; }
      b.disabled = true;
      try {
        // A reply WITH a status change goes as one update, so the family gets one email, not two.
        if (reply && !internal && Object.keys(patch).length) {
          patch.resolution_note = reply;
          await api().patch('/parent-issues/' + id, patch);
        } else {
          if (reply) await api().post('/parent-issues/' + id + '/messages', { body: reply, internal: internal });
          if (Object.keys(patch).length) await api().patch('/parent-issues/' + id, patch);
        }
        toast(reply && !internal ? 'Reply sent to the family' : 'Saved', 'success');
        close(); render(main);
      } catch (e) { b.disabled = false; err.textContent = (e && e.message) || 'Could not save.'; }
    };
  }

  KT.ParentIssues = { render: render };
  (function reg(n) {
    var S = (window.KT && window.KT.Shell) || window.Shell;
    if (!S || !S.registerScreen) { if ((n || 0) < 100) setTimeout(function () { reg((n || 0) + 1); }, 100); return; }
    ['agency_admin', 'centre_director', 'educator', 'platform_admin'].forEach(function (r) { S.registerScreen(r + ':parent-issues', render); });
  })(0);
})(window);
