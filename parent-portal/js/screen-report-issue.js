/* ═══════════════════════════════════════════════════════════════════
   KiddieTrac — Report an issue (parent view).

   2026-10-13, Anthony: "add a report an issue section for parents to report an issue to
   staff and add drop down selections to choose from and notes to be added - full sweep".

   The parent chooses the child (or the family in general), a category, a reason from that
   category's list, and writes what happened. Below the form: everything they have reported,
   its status, and every reply from the centre, with a box to add more.

   API: GET /parent/issues/options, GET /parent/issues, POST /parent/issues,
        POST /parent/issues/{id}/messages (ParentIssueController).
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
  var FLD = 'width:100%;box-sizing:border-box;height:36px;padding:0 10px;border:1px solid var(--kt-border,#CBD5E1);border-radius:8px;font-size:14px;background:var(--kt-surface,#fff);color:var(--kt-text,#0F172A);';
  var LBL = 'display:block;font-size:12.5px;font-weight:700;color:var(--kt-text-muted,#475569);margin:12px 0 4px;';

  function chip(st, label) {
    var c = CHIP[st] || CHIP.closed;
    return '<span style="background:' + c[0] + ';color:' + c[1] + ';font-size:11.5px;font-weight:800;padding:2px 9px;border-radius:999px;white-space:nowrap;">' + esc(label) + '</span>';
  }

  var lastMain = null;
  async function render(main) {
    lastMain = main;
    main.innerHTML = '<div id="ri-root" style="padding:20px 16px 90px;max-width:820px;margin:0 auto;">'
      + '<h1 style="font-family:var(--kt-font-display);font-size:23px;margin:0 0 4px;">Report an issue</h1>'
      + '<p style="color:var(--kt-text-muted);font-size:14px;line-height:1.6;margin:0 0 16px;">Tell the centre about anything that needs looking into. The director is told straight away, and you\'ll see their replies here and by email. For an emergency, call the centre.</p>'
      + '<div id="ri-form" style="color:var(--kt-text-muted);font-size:13.5px;">Loading…</div>'
      + '<h2 style="font-size:16px;margin:26px 0 8px;">Your reports</h2><div id="ri-list"></div></div>';
    var opts, mine;
    try {
      var r = await Promise.all([api().get('/parent/issues/options'), api().get('/parent/issues')]);
      opts = r[0]; mine = r[1];
    } catch (e) {
      var f = main.querySelector('#ri-form');
      if (f) f.innerHTML = '<div style="color:#B91C1C;">Could not load this page. Please try again.</div>';
      return;
    }
    if (!main.isConnected) return;
    drawForm(main, opts);
    drawList(main, (mine && mine.issues) || []);
  }

  function drawForm(main, opts) {
    var host = main.querySelector('#ri-form');
    var kids = opts.children || [], fams = opts.families || [], cats = opts.categories || [];
    var aboutOpts = kids.map(function (c) { return '<option value="c' + c.id + '">' + esc(c.name) + '</option>'; }).join('')
      + fams.map(function (f) { return '<option value="f' + f.id + '">' + esc(fams.length > 1 ? 'The ' + (f.name || 'family') + ' in general' : 'My family in general') + '</option>'; }).join('');
    host.innerHTML = '<div style="background:var(--kt-surface,#fff);border:1px solid var(--kt-border,#E5E7EB);border-radius:14px;padding:6px 16px 16px;">'
      + '<div style="display:flex;flex-wrap:wrap;gap:0 14px;">'
      + '<div style="flex:1 1 260px;min-width:0;"><label style="' + LBL + '" for="ri-about">Who is it about?</label><select id="ri-about" style="' + FLD + '">' + aboutOpts + '</select></div>'
      + '<div style="flex:1 1 260px;min-width:0;"><label style="' + LBL + '" for="ri-cat">What is it about?</label><select id="ri-cat" style="' + FLD + '"><option value="">Choose a category…</option>'
      +   cats.map(function (c) { return '<option value="' + esc(c.key) + '">' + esc(c.label) + '</option>'; }).join('') + '</select></div>'
      + '</div>'
      + '<div id="ri-sub-wrap" style="display:none;"><label style="' + LBL + '" for="ri-sub">Reason</label><select id="ri-sub" style="' + FLD + '"></select></div>'
      + '<label style="' + LBL + '" for="ri-notes">What happened? <span style="color:#DC2626;">*</span></label>'
      + '<textarea id="ri-notes" maxlength="4000" rows="4" style="' + FLD + 'height:auto;min-height:96px;padding:8px 10px;resize:vertical;font-family:inherit;line-height:1.45;" placeholder="Include the day, the time and anything that helps the centre look into it."></textarea>'
      + '<div id="ri-err" style="color:#B91C1C;font-size:13px;min-height:18px;margin-top:6px;"></div>'
      + '<div style="display:flex;justify-content:flex-end;"><button type="button" id="ri-send" style="height:34px;padding:0 18px;border-radius:8px;border:0;background:var(--kt-primary,#1F6080);color:#fff;font-weight:800;font-size:14px;cursor:pointer;">Send to the centre</button></div>'
      + '</div>';
    if (!kids.length && !fams.length) {
      host.innerHTML = '<div style="color:var(--kt-text-muted);font-size:13.5px;">No family is linked to your account yet.</div>';
      return;
    }
    var cat = host.querySelector('#ri-cat'), subWrap = host.querySelector('#ri-sub-wrap'), sub = host.querySelector('#ri-sub');
    cat.onchange = function () {
      var c = cats.filter(function (x) { return x.key === cat.value; })[0];
      if (c && c.reasons && c.reasons.length) {
        sub.innerHTML = '<option value="">Choose a reason…</option>' + c.reasons.map(function (r) { return '<option>' + esc(r) + '</option>'; }).join('');
        subWrap.style.display = '';
      } else { sub.innerHTML = ''; subWrap.style.display = 'none'; }
    };
    host.querySelector('#ri-send').onclick = async function () {
      var err = host.querySelector('#ri-err'); err.textContent = '';
      var about = host.querySelector('#ri-about').value || '';
      var notes = host.querySelector('#ri-notes').value.trim();
      if (!cat.value) { err.textContent = 'Choose what the issue is about.'; cat.focus(); return; }
      if (subWrap.style.display !== 'none' && !sub.value) { err.textContent = 'Choose a reason from the list.'; sub.focus(); return; }
      if (notes.length < 10) { err.textContent = 'Please describe what happened (at least 10 characters).'; host.querySelector('#ri-notes').focus(); return; }
      var body = { category: cat.value, subcategory: sub.value || null, notes: notes };
      if (about.charAt(0) === 'c') body.child_id = parseInt(about.slice(1), 10); else body.family_id = parseInt(about.slice(1), 10);
      var b = this; b.disabled = true; b.textContent = 'Sending…';
      try {
        await api().post('/parent/issues', body);
        toast('Sent — the centre has been told', 'success');
        render(main);
      } catch (e) { b.disabled = false; b.textContent = 'Send to the centre'; err.textContent = (e && e.message) || 'Could not send. Please try again.'; }
    };
  }

  function drawList(main, issues) {
    var host = main.querySelector('#ri-list');
    if (!issues.length) {
      host.innerHTML = '<div style="color:var(--kt-text-muted);font-size:13.5px;">You haven\'t reported anything yet.</div>';
      return;
    }
    host.innerHTML = issues.map(function (i) {
      var thread = (i.messages || []).map(function (m) {
        var mine = m.side === 'parent';
        return '<div style="margin-top:8px;padding:8px 11px;border-radius:10px;font-size:13.5px;line-height:1.45;white-space:pre-wrap;'
          + (mine ? 'background:#F1F5F9;' : 'background:#EFF6FF;border-left:3px solid #1F6080;') + '">'
          + '<div style="font-size:11.5px;font-weight:800;color:#475569;margin-bottom:2px;">' + esc(m.who) + ' · ' + esc(m.at) + '</div>' + esc(m.body) + '</div>';
      }).join('');
      return '<div data-issue="' + i.id + '" style="background:var(--kt-surface,#fff);border:1px solid var(--kt-border,#E5E7EB);border-radius:12px;padding:12px 14px;margin-bottom:10px;">'
        + '<div style="display:flex;justify-content:space-between;gap:8px;align-items:flex-start;flex-wrap:wrap;">'
        + '<div style="font-weight:800;font-size:14px;">' + esc(i.category_label) + (i.subcategory ? ' · <span style="font-weight:600;">' + esc(i.subcategory) + '</span>' : '') + '</div>'
        + chip(i.status, i.status_label) + '</div>'
        + '<div style="font-size:12px;color:var(--kt-text-muted);margin-top:2px;">' + (i.child ? esc(i.child) + ' · ' : '') + 'Reported ' + esc(i.created) + '</div>'
        + '<div style="font-size:13.5px;margin-top:8px;line-height:1.5;white-space:pre-wrap;">' + esc(i.notes) + '</div>'
        + thread
        + '<div style="display:flex;gap:6px;margin-top:10px;align-items:flex-end;">'
        + '<textarea data-more="' + i.id + '" rows="1" maxlength="4000" style="' + FLD + 'height:auto;min-height:32px;padding:6px 10px;resize:vertical;font-family:inherit;" placeholder="' + (i.status === 'resolved' || i.status === 'closed' ? 'Not sorted? Add more and it reopens…' : 'Add more information…') + '"></textarea>'
        + '<button type="button" data-send="' + i.id + '" style="height:32px;padding:0 12px;border-radius:8px;border:1px solid var(--kt-primary,#1F6080);background:#fff;color:var(--kt-primary,#1F6080);font-weight:800;font-size:12.5px;cursor:pointer;flex-shrink:0;">Send</button></div>'
        + '</div>';
    }).join('');
    host.querySelectorAll('[data-send]').forEach(function (b) {
      b.onclick = async function () {
        var id = b.getAttribute('data-send');
        var ta = host.querySelector('[data-more="' + id + '"]');
        var v = (ta.value || '').trim();
        if (v.length < 2) { ta.focus(); return; }
        b.disabled = true;
        try { await api().post('/parent/issues/' + id + '/messages', { body: v }); toast('Added to your report', 'success'); if (lastMain && lastMain.isConnected) render(lastMain); }
        catch (e) { b.disabled = false; toast((e && e.message) || 'Could not send', 'error'); }
      };
    });
  }

  KT.ReportIssue = { render: render };
  (function reg(n) {
    var S = (window.KT && window.KT.Shell) || window.Shell;
    if (!S || !S.registerScreen) { if ((n || 0) < 100) setTimeout(function () { reg((n || 0) + 1); }, 100); return; }
    S.registerScreen('guardian:report-issue', render);
  })(0);
})(window);
