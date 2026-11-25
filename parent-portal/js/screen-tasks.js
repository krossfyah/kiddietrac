/* ===================================================================
   KiddieTrac — Tasks.

   Agency admins & centre directors assign tasks to educators; the
   educator sees their open tasks (a tile + a list) and marks them
   in-progress / done. One screen file, two audiences:
     • educator:my-tasks        → renderMyTasks   (my assigned work)
     • <reviewer>:tasks         → renderManageTasks (assign + track)
   =================================================================== */
(function (window) {
  'use strict';
  var KT = window.KT;
  if (!KT || !KT.Shell || !KT.Shell.registerScreen) return;
  var Api = KT.Api;
  var Shell = KT.Shell;

  function esc(s) {
    return String(s == null ? '' : s)
      .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;');
  }
  function toast(msg, ok) {
    try { if (KT.toast) return KT.toast(msg, ok === false ? 'error' : 'success'); } catch (e) {}
    try { if (KT.Toasts && KT.Toasts.show) return KT.Toasts.show(msg); } catch (e) {}
  }
  function fmtDate(d) {
    if (!d) return '';
    try {
      var dt = new Date((d + '').replace(' ', 'T'));
      if (isNaN(dt)) return d;
      return dt.toLocaleDateString(undefined, { month: 'short', day: 'numeric', year: 'numeric' });
    } catch (e) { return d; }
  }
  var PRIO = {
    high:   { label: 'High',   bg: '#FEE2E2', fg: '#991B1B' },
    normal: { label: 'Normal', bg: '#E0E7FF', fg: '#3730A3' },
    low:    { label: 'Low',    bg: '#F1F5F9', fg: '#475569' },
  };
  var STATUS = {
    open:        { label: 'Open',        bg: '#FEF3C7', fg: '#92400E' },
    in_progress: { label: 'In progress', bg: '#DBEAFE', fg: '#1E40AF' },
    done:        { label: 'Done',        bg: '#DCFCE7', fg: '#166534' },
  };
  function chip(map, key) {
    var c = map[key] || { label: key, bg: '#F1F5F9', fg: '#475569' };
    return '<span style="display:inline-block;padding:2px 9px;border-radius:999px;font-size:11.5px;font-weight:800;'
      + 'background:' + c.bg + ';color:' + c.fg + ';">' + esc(c.label) + '</span>';
  }
  function overdue(t) {
    if (!t.due_date || t.status === 'done') return false;
    try { return new Date((t.due_date + '').replace(' ', 'T')) < new Date(new Date().toDateString()); }
    catch (e) { return false; }
  }

  // ── Educator: My tasks ───────────────────────────────────────────────
  function renderMyTasks(main) {
    var agencyName = '';
    try { var _u = JSON.parse(sessionStorage.getItem('kt_user') || '{}'); agencyName = _u.agency_name || _u.centre_name || ''; } catch (e) {}
    var _sub = agencyName ? ('Tasks assigned to you by ' + esc(agencyName) + '.') : 'Tasks assigned to you by your childcare agency.';
    main.innerHTML = '<div class="kt-tasks-wrap" style="max-width:820px;margin:0 auto;padding:6px 4px 40px;">'
      + '<h1 style="font-size:22px;font-weight:900;color:#0F172A;margin:4px 2px 2px;">My tasks</h1>'
      + '<div style="color:#64748B;font-size:13.5px;margin:0 2px 16px;">' + _sub + '</div>'
      + '<div id="kt-mytasks-body"><div style="padding:40px;text-align:center;color:#64748B;">Loading…</div></div>'
      + '</div>';
    var body = main.querySelector('#kt-mytasks-body');

    Api.get('/tasks/mine').then(function (d) {
      var tasks = (d && d.tasks) || [];
      if (!tasks.length) {
        body.innerHTML = '<div style="padding:48px 20px;text-align:center;color:#64748B;">'
          + '<div style="font-size:40px;margin-bottom:8px;">🎉</div>'
          + '<div style="font-weight:800;color:#475569;">No tasks right now</div>'
          + '<div style="font-size:13px;margin-top:4px;">You are all caught up.</div></div>';
        return;
      }
      body.innerHTML = tasks.map(myTaskCard).join('');
      wireMyTasks(body);
    }).catch(function (e) {
      body.innerHTML = '<div style="padding:30px;text-align:center;color:#B91C1C;">Could not load tasks. ' + esc(e && e.message || '') + '</div>';
    });
  }

  function myTaskCard(t) {
    var od = overdue(t);
    var meta = [];
    if (t.due_date) meta.push('<span style="' + (od ? 'color:#B91C1C;font-weight:800;' : '') + '">📅 Due ' + esc(fmtDate(t.due_date)) + (od ? ' · overdue' : '') + '</span>');
    // Each part its own element: bare text runs merge into ONE flex item, so the gap
    // never applied and the card read "Sunny Meadows ChildcareFrom Dana Visitor".
    if (t.centre_name) meta.push('<span>🏫 ' + esc(t.centre_name) + '</span>');
    if (t.assigned_by_name) meta.push('<span>From ' + esc(t.assigned_by_name) + '</span>');
    return '<div class="kt-task-card" data-id="' + t.id + '" style="background:#fff;border:1px solid #E2E8F0;'
      + (od ? '--kt-edge:#DC2626;' : '--kt-edge:' + (STATUS[t.status] ? STATUS[t.status].fg : '#94A3B8') + ';')
      + 'border-radius:14px;padding:14px 16px;margin-bottom:12px;box-shadow:0 1px 2px rgba(15,23,42,.05);">'
      + '<div style="display:flex;gap:10px;align-items:flex-start;justify-content:space-between;">'
        + '<div style="font-weight:800;font-size:15.5px;color:#0F172A;line-height:1.3;flex:1;' + (t.status === 'done' ? 'text-decoration:line-through;color:#64748B;' : '') + '">' + esc(t.title) + '</div>'
        + '<div style="display:flex;gap:6px;flex-shrink:0;">' + chip(PRIO, t.priority) + chip(STATUS, t.status) + '</div>'
      + '</div>'
      + (t.description ? '<div style="color:#475569;font-size:13.5px;margin-top:6px;white-space:pre-wrap;">' + esc(t.description) + '</div>' : '')
      + (meta.length ? '<div style="color:#64748B;font-size:12px;margin-top:8px;display:flex;gap:12px;flex-wrap:wrap;">' + meta.join('') + '</div>' : '')
      + '<div style="margin-top:12px;display:flex;gap:8px;flex-wrap:wrap;">'
        + (t.status !== 'in_progress' && t.status !== 'done' ? btn('start', '▶ Start', '#1E40AF', '#DBEAFE') : '')
        + (t.status !== 'done' ? btn('done', '✓ Mark done', '#166534', '#DCFCE7') : btn('reopen', '↺ Reopen', '#475569', '#F1F5F9'))
      + '</div>'
      + '</div>';
  }
  function btn(action, label, fg, bg) {
    return '<button type="button" data-action="' + action + '" style="border:none;border-radius:9px;padding:8px 14px;'
      + 'font-size:13px;font-weight:800;cursor:pointer;background:' + bg + ';color:' + fg + ';">' + label + '</button>';
  }

  function wireMyTasks(body) {
    body.querySelectorAll('.kt-task-card button[data-action]').forEach(function (b) {
      b.addEventListener('click', function () {
        var card = b.closest('.kt-task-card');
        var id = card && card.getAttribute('data-id');
        var action = b.getAttribute('data-action');
        var status = action === 'start' ? 'in_progress' : action === 'done' ? 'done' : 'open';
        body.querySelectorAll('.kt-task-card[data-id="' + id + '"] button').forEach(function (x) { x.disabled = true; });
        Api.patch('/tasks/' + id + '/status', { status: status }).then(function () {
          toast(status === 'done' ? 'Task completed' : 'Task updated');
          refreshTileBadge();
          renderMyTasks(document.getElementById('appMain'));
        }).catch(function (e) {
          toast(e && e.message || 'Could not update task', false);
          body.querySelectorAll('.kt-task-card[data-id="' + id + '"] button').forEach(function (x) { x.disabled = false; });
        });
      });
    });
  }

  // ── Reviewer: assign + track ─────────────────────────────────────────
  function renderManageTasks(main) {
    main.innerHTML = '<div style="max-width:1000px;margin:0 auto;padding:6px 4px 40px;">'
      + '<div style="display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;margin:4px 2px 4px;">'
        + '<h1 style="font-size:22px;font-weight:900;color:#0F172A;margin:0;">Tasks</h1>'
        + '<button id="kt-task-new" class="kt-icon-tip" title="Assign task" data-kttip="Assign task" aria-label="Assign task" type="button" style="background:#0EA5A6;color:#fff;border:none;border-radius:10px;width:40px;height:40px;padding:0;font-weight:800;font-size:18px;line-height:1;cursor:pointer;display:inline-flex;align-items:center;justify-content:center;">➕</button>'
      + '</div>'
      + '<div style="color:#64748B;font-size:13.5px;margin:0 2px 16px;">Assign work to educators and track it to completion.</div>'
      + '<div id="kt-tasks-counts" style="display:flex;gap:10px;flex-wrap:wrap;margin-bottom:14px;"></div>'
      + '<div id="kt-tasks-form"></div>'
      + '<div id="kt-tasks-table"><div style="padding:40px;text-align:center;color:#64748B;">Loading…</div></div>'
      + '</div>';
    main.querySelector('#kt-task-new').addEventListener('click', function () { toggleForm(main); });
    loadManage(main);
  }

  function loadManage(main) {
    var tbl = main.querySelector('#kt-tasks-table');
    Api.get('/tasks').then(function (d) {
      var tasks = (d && d.tasks) || [];
      var c = (d && d.counts) || {};
      main.querySelector('#kt-tasks-counts').innerHTML =
        countPill('Open', c.open || 0, '#92400E', '#FEF3C7')
        + countPill('In progress', c.in_progress || 0, '#1E40AF', '#DBEAFE')
        + countPill('Done', c.done || 0, '#166534', '#DCFCE7');
      if (!tasks.length) {
        tbl.innerHTML = '<div style="padding:44px 20px;text-align:center;color:#64748B;">'
          + '<div style="font-size:38px;margin-bottom:6px;">🗂️</div>'
          + '<div style="font-weight:800;color:#475569;">No tasks yet</div>'
          + '<div style="font-size:13px;margin-top:4px;">Use “Assign task” to give an educator something to do.</div></div>';
        return;
      }
      tbl.innerHTML = manageTable(tasks);
      wireManage(main, tasks);
    }).catch(function (e) {
      tbl.innerHTML = '<div style="padding:30px;text-align:center;color:#B91C1C;">Could not load tasks. ' + esc(e && e.message || '') + '</div>';
    });
  }
  function countPill(label, n, fg, bg) {
    return '<div style="background:' + bg + ';color:' + fg + ';border-radius:12px;padding:8px 14px;font-weight:800;font-size:13px;">'
      + '<span style="font-size:18px;">' + n + '</span> ' + esc(label) + '</div>';
  }

  function manageTable(tasks) {
    var rows = tasks.map(function (t) {
      var od = overdue(t);
      return '<tr data-id="' + t.id + '" style="border-top:1px solid #EEF2F7;">'
        + '<td style="padding:11px 10px;vertical-align:top;">'
          + '<div style="font-weight:800;color:#0F172A;' + (t.status === 'done' ? 'text-decoration:line-through;color:#64748B;' : '') + '">' + esc(t.title) + '</div>'
          + (t.description ? '<div style="color:#64748B;font-size:12.5px;margin-top:3px;max-width:360px;white-space:pre-wrap;">' + esc(t.description) + '</div>' : '')
          + reminderLine(t)
        + '</td>'
        // Private to-dos (2026-11-25, My day panel "Me"): only their owner ever gets them in this list.
        + '<td style="padding:11px 10px;vertical-align:top;color:#334155;font-size:13px;">' + (t.is_private ? 'Me' + '<span style="display:inline-block;font-size:10.5px;font-weight:800;padding:1px 7px;border-radius:6px;background:#EDE9FE;color:#5B21B6;margin-left:4px;" data-kttip="Only you can see this to-do">Private</span>' : esc(t.assigned_to_name || '—')) + (t.centre_name ? '<div style="color:#64748B;font-size:11.5px;">' + esc(t.centre_name) + '</div>' : '') + '</td>'
        + '<td style="padding:11px 10px;vertical-align:top;">' + chip(PRIO, t.priority) + '</td>'
        // Date created (2026-11-04, Anthony: "tasks section in the table add a date created column"), in the agency's time zone.
        + '<td style="padding:11px 10px;vertical-align:top;font-size:12.5px;color:#475569;white-space:nowrap;"' + (t.created_local ? ' data-kttip="' + esc(fmtDate(t.created_local) + ' · ' + String(t.created_local).slice(11, 16)) + '"' : '') + '>' + (t.created_local ? esc(fmtDate(t.created_local)) : '—') + '</td>'
        + '<td style="padding:11px 10px;vertical-align:top;font-size:12.5px;' + (od ? 'color:#B91C1C;font-weight:800;' : 'color:#475569;') + '">' + (t.due_date ? esc(fmtDate(t.due_date)) + (od ? ' ⚠' : '') : '—') + '</td>'
        + '<td style="padding:11px 10px;vertical-align:top;">' + chip(STATUS, t.status) + '</td>'
        + '<td style="padding:11px 10px;vertical-align:top;white-space:nowrap;text-align:right;">'
          + (t.is_private && t.status !== 'done' ? '<button type="button" data-act="done" class="kt-act-icon kt-act-edit kt-icon-tip" data-kttip="Mark done" aria-label="Mark done">✅</button>' : '')
          + '<button type="button" data-act="view" class="kt-act-icon kt-act-info kt-icon-tip" data-kttip="View" aria-label="View">👁️</button>'
          + '<button type="button" data-act="edit" class="kt-act-icon kt-act-edit kt-icon-tip" data-kttip="Edit" aria-label="Edit">✏️</button>'
          + '<button type="button" data-act="del" class="kt-act-icon kt-act-danger kt-icon-tip" data-kttip="Delete" aria-label="Delete">🗑️</button>'
        + '</td>'
      + '</tr>';
    }).join('');
    return '<div style="background:#fff;border:1px solid #E2E8F0;border-radius:14px;overflow:hidden;">'
      + '<div style="overflow-x:auto;"><table style="width:100%;border-collapse:collapse;min-width:720px;">'
      + '<thead><tr style="background:#F8FAFC;text-align:left;font-size:11.5px;letter-spacing:.4px;color:#64748B;text-transform:uppercase;">'
        + '<th style="padding:10px;">Task</th><th style="padding:10px;">Assigned to</th><th style="padding:10px;">Priority</th>'
        + '<th style="padding:10px;">Created</th><th style="padding:10px;">Due</th><th style="padding:10px;">Status</th><th style="padding:10px;"></th>'
      + '</tr></thead><tbody>' + rows + '</tbody></table></div></div>';
  }

  function wireManage(main, tasks) {
    var byId = {};
    (tasks || []).forEach(function (t) { byId[String(t.id)] = t; });
    main.querySelectorAll('#kt-tasks-table tr[data-id]').forEach(function (tr) {
      var id = tr.getAttribute('data-id');
      var t = byId[id];
      var del = tr.querySelector('button[data-act="del"]');
      if (del) del.addEventListener('click', async function () {
        if (!await KT.confirm('Delete this task? This cannot be undone.')) return;
        del.disabled = true;
        Api.delete('/tasks/' + id).then(function () { toast('Task deleted'); loadManage(main); })
          .catch(function (e) { toast(e && e.message || 'Could not delete', false); del.disabled = false; });
      });
      var done = tr.querySelector('button[data-act="done"]');
      if (done) done.addEventListener('click', function () {
        done.disabled = true;
        Api.patch('/tasks/' + id + '/status', { status: 'done' }).then(function () { toast('Task done'); try { window.dispatchEvent(new CustomEvent('kt:tasks-changed')); } catch (e2) {} loadManage(main); })
          .catch(function (e) { toast(e && e.message || 'Could not update the task.', false); done.disabled = false; });
      });
      var view = tr.querySelector('button[data-act="view"]');
      if (view) view.addEventListener('click', function () { openTaskEditor(main, t, true); });
      var edit = tr.querySelector('button[data-act="edit"]');
      if (edit) edit.addEventListener('click', function () { openTaskEditor(main, t, false); });
    });
    // Real <table>, but it renders async (no hashchange) — nudge the kebab sweep.
    if (window.KT && typeof KT.sweepRowActions === 'function') setTimeout(KT.sweepRowActions, 0);
  }

  function optList(vals, sel) {
    return vals.map(function (v) { return '<option value="' + v + '"' + (v === sel ? ' selected' : '') + '>' + v.replace('_', ' ') + '</option>'; }).join('');
  }

  // View (read-only) or Edit a task. Edit PATCHes /tasks/{id} (backend supports it).
  /* THE REMINDER BLOCK (2026-10-27). p = id prefix ('tf' assign, 'te' edit), t = the task (edit) or null. */
  var CH = [['app', '📱 App'], ['email', '✉️ Email'], ['sms', '💬 Text'], ['voice', '📞 Voice call']];
  function reminderBlock(p, t, dis) {
    var at = t && t.remind_at_local ? t.remind_at_local : '';
    var chans = t ? (t.remind_channels || []) : ['app'];
    if (!chans.length) chans = ['app'];
    var sent = t && t.reminded_at ? '<div style="margin-top:8px;font-size:12.5px;color:#047857;">✓ Reminder sent'
      + (t.remind_result ? ': ' + Object.keys(t.remind_result).map(function (k) { var v = String(t.remind_result[k] || ''); return esc((CH.filter(function (c) { return c[0] === k; })[0] || [k, k])[1].replace(/^\S+\s/, '')) + ' ' + (v === 'sent' ? '✓' : '— ' + esc(v.replace(/^skipped: /, ''))); }).join(' · ') : '')
      + '. Change the time to send it again.</div>' : '';
    return '<div class="full" style="border:1px solid #E2E8F0;border-radius:12px;padding:12px 14px;background:#F8FAFC;">'
      + '<div style="font-size:12px;font-weight:800;color:#475569;margin-bottom:8px;">⏰ Reminder</div>'
      + '<div class="kt-task-grid">'
        + field('When', '<select id="' + p + '-rwhen" ' + dis + ' style="' + inp() + '">'
          + '<option value="none"' + (at ? '' : ' selected') + '>No reminder</option>'
          + '<option value="before">The day before it is due, 8:00 AM</option>'
          + '<option value="due">The morning it is due, 8:00 AM</option>'
          + '<option value="custom"' + (at ? ' selected' : '') + '>Pick a date and time…</option></select>')
        + '<div id="' + p + '-ratwrap"' + (at ? '' : ' style="display:none"') + '>' + field('Date and time', '<input id="' + p + '-rat" type="datetime-local" value="' + esc(at) + '" ' + dis + ' style="' + inp() + '">') + '</div>'
      + '</div>'
      + '<div id="' + p + '-rch" style="display:' + (at ? 'flex' : 'none') + ';flex-wrap:wrap;gap:8px 14px;margin-top:10px;">'
        + CH.map(function (c) { return '<label style="display:inline-flex;align-items:center;gap:6px;font-size:13.5px;color:#334155;cursor:pointer;"><input type="checkbox" value="' + c[0] + '"' + (chans.indexOf(c[0]) >= 0 ? ' checked' : '') + ' ' + dis + '> ' + c[1] + '</label>'; }).join('')
      + '</div>'
      + '<div style="margin-top:6px;font-size:11.5px;color:#64748B;">Texts and calls follow each person\'s own settings and consent. Times are in the agency\'s time zone.</div>'
      + sent
      + '</div>';
  }
  function wireReminder(root, p) {
    var w = root.querySelector('#' + p + '-rwhen'); if (!w) return;
    var sync = function () {
      root.querySelector('#' + p + '-ratwrap').style.display = w.value === 'custom' ? '' : 'none';
      root.querySelector('#' + p + '-rch').style.display = w.value === 'none' ? 'none' : 'flex';
    };
    w.addEventListener('change', sync);
  }
  // { remind_at_local, remind_channels } from the block, or an error message.
  function readReminder(root, p, due) {
    var w = root.querySelector('#' + p + '-rwhen'); if (!w) return {};
    if (w.value === 'none') return { remind_at_local: null, remind_channels: [] };
    var at = '';
    if (w.value === 'custom') at = root.querySelector('#' + p + '-rat').value || '';
    else {
      if (!due) return { error: 'Set a due date, or pick a date and time for the reminder' };
      var d = new Date(due + 'T12:00:00'); if (w.value === 'before') d.setDate(d.getDate() - 1);
      at = d.getFullYear() + '-' + ('0' + (d.getMonth() + 1)).slice(-2) + '-' + ('0' + d.getDate()).slice(-2) + 'T08:00';
    }
    if (!at) return { error: 'Pick the date and time for the reminder' };
    var ch = [].map.call(root.querySelectorAll('#' + p + '-rch input:checked'), function (i) { return i.value; });
    if (!ch.length) return { error: 'Choose how to send the reminder' };
    return { remind_at_local: at, remind_channels: ch };
  }
  function reminderLine(t) {
    if (t.reminded_at) return '<div style="margin-top:4px;font-size:11.5px;color:#047857;">✓ Reminder sent</div>';
    if (!t.remind_at_local) return '';
    var d = new Date(t.remind_at_local);
    var when = isNaN(d) ? t.remind_at_local.replace('T', ' ') : d.toLocaleDateString(undefined, { month: 'short', day: 'numeric' }) + ', ' + d.toLocaleTimeString(undefined, { hour: 'numeric', minute: '2-digit' });
    var how = (t.remind_channels || []).map(function (k) { return ({ app: 'App', email: 'Email', sms: 'Text', voice: 'Voice' })[k] || k; }).join(', ');
    return '<div style="margin-top:4px;font-size:11.5px;color:#0369A1;">⏰ ' + esc(when) + (how ? ' · ' + esc(how) : '') + '</div>';
  }

  /* One popup for the task dialogs: title row with ✕, the body, the buttons. Closes on ✕, Cancel,
     a tap outside or Escape. Returns { ov, close }. */
  function taskPopup(title, bodyHtml, actionsHtml) {
    if (!document.getElementById('kt-task-pop-css')) {
      var st = document.createElement('style'); st.id = 'kt-task-pop-css';
      st.textContent = '.kt-task-pop .kt-task-grid{display:grid;grid-template-columns:1fr 1fr;gap:12px;}'
        + '.kt-task-pop .kt-task-grid > .full{grid-column:1 / -1;}'
        + '@media (max-width:560px){.kt-task-pop .kt-task-grid{grid-template-columns:1fr;}.kt-task-pop .kt-task-acts button{flex:1 1 0;}}';
      document.head.appendChild(st);
    }
    var ov = document.createElement('div');
    ov.className = 'kt-task-pop';
    ov.setAttribute('role', 'dialog'); ov.setAttribute('aria-modal', 'true');
    ov.style.cssText = 'position:fixed;inset:0;background:rgba(15,23,42,.5);display:flex;align-items:center;justify-content:center;z-index:9999;padding:16px;';
    ov.innerHTML = '<div style="background:#fff;border-radius:14px;max-width:560px;width:100%;padding:22px;max-height:90vh;overflow:auto;box-sizing:border-box;">'
      + '<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:14px;">'
        + '<div style="font-weight:800;font-size:18px;color:#0F172A;">' + esc(title) + '</div>'
        + '<button type="button" data-x aria-label="Close" style="background:none;border:none;font-size:22px;cursor:pointer;color:#94A3B8;line-height:1;">×</button>'
      + '</div>' + bodyHtml
      + '<div class="kt-task-acts" style="display:flex;justify-content:flex-end;gap:8px;margin-top:16px;">' + actionsHtml + '</div>'
      + '</div>';
    document.body.appendChild(ov);
    var close = function () { document.removeEventListener('keydown', onKey, true); if (ov.parentNode) ov.parentNode.removeChild(ov); };
    var onKey = function (e) { if (e.key === 'Escape') close(); };
    document.addEventListener('keydown', onKey, true);
    ov.addEventListener('click', function (e) { if (e.target === ov) close(); });
    ov.querySelectorAll('[data-x]').forEach(function (b) { b.addEventListener('click', close); });
    return { ov: ov, close: close };
  }
  var BTN_GHOST = 'background:#F1F5F9;color:#475569;border:none;border-radius:10px;padding:10px 18px;font-weight:800;cursor:pointer;';
  var BTN_MAIN = 'background:#0EA5A6;color:#fff;border:none;border-radius:10px;padding:10px 18px;font-weight:800;cursor:pointer;';

  function openTaskEditor(main, t, readOnly) {
    if (!t) return;
    var dis = readOnly ? 'disabled' : '';
    var pop = taskPopup(readOnly ? 'Task details' : 'Edit task',
      '<div class="kt-task-grid">'
        + '<div class="full">' + field('Title', '<input id="te-title" type="text" maxlength="200" value="' + esc(t.title || '') + '" ' + dis + ' style="' + inp() + '">') + '</div>'
        + '<div class="full">' + field('Details', '<textarea id="te-desc" rows="3" maxlength="4000" ' + dis + ' style="' + inp() + 'resize:vertical;">' + esc(t.description || '') + '</textarea>') + '</div>'
        + field('Priority', '<select id="te-prio" ' + dis + ' style="' + inp() + '">' + optList(['low', 'normal', 'high'], t.priority) + '</select>')
        + field('Due date', '<input id="te-due" type="date" value="' + esc(String(t.due_date || '').slice(0, 10)) + '" ' + dis + ' style="' + inp() + '">')
        + field('Status', '<select id="te-status" ' + dis + ' style="' + inp() + '">' + optList(['open', 'in_progress', 'done'], t.status) + '</select>')
        + (t.is_private ? '<div style="font-size:12.5px;color:#5B21B6;background:#F5F3FF;border-radius:10px;padding:8px 10px;margin:4px 0 10px;">🔒 Private to-do: only you can see it, and it never sends anything.</div>'
          : (t.assigned_to_name ? field('Assigned to', '<input type="text" value="' + esc(t.assigned_to_name) + '" disabled style="' + inp() + '">') : '')
            + reminderBlock('te', t, dis))
      + '</div>',
      readOnly ? '<button type="button" data-x style="' + BTN_GHOST + '">Close</button>'
        : '<button type="button" data-x style="' + BTN_GHOST + '">Cancel</button><button type="button" id="te-save" style="' + BTN_MAIN + '">Save changes</button>');
    var ov = pop.ov, close = pop.close;
    wireReminder(ov, 'te');
    var save = ov.querySelector('#te-save');
    if (save) save.addEventListener('click', function () {
      var payload = {
        title: (ov.querySelector('#te-title').value || '').trim(),
        description: (ov.querySelector('#te-desc').value || '').trim(),
        priority: ov.querySelector('#te-prio').value,
        status: ov.querySelector('#te-status').value,
        due_date: ov.querySelector('#te-due').value || null,
      };
      if (!payload.title) { toast('Title is required', false); return; }
      if (!t.is_private) {   // a private to-do has no reminder block
        var rem = readReminder(ov, 'te', payload.due_date);
        if (rem.error) { toast(rem.error, false); return; }
        payload.remind_at_local = rem.remind_at_local; payload.remind_channels = rem.remind_channels;
      }
      save.disabled = true;
      Api.patch('/tasks/' + t.id, payload).then(function () { toast('Task updated'); close(); loadManage(main); })
        .catch(function (e) { toast(e && e.message || 'Could not save', false); save.disabled = false; });
    });
  }

  function toggleForm(main) {
    var pop = taskPopup('Assign a task',
      '<div class="kt-task-grid">'
        + field('Assign to', '<select id="tf-assignee" style="' + inp() + '"><option value="">Loading…</option></select>')
        + field('Due date', '<input id="tf-due" type="date" style="' + inp() + '">')
        + '<div class="full">' + field('Title', '<input id="tf-title" type="text" maxlength="200" placeholder="e.g. Update the allergy board" style="' + inp() + '">') + '</div>'
        + '<div class="full">' + field('Details (optional)', '<textarea id="tf-desc" rows="3" maxlength="4000" style="' + inp() + 'resize:vertical;"></textarea>') + '</div>'
        + field('Priority', '<select id="tf-prio" style="' + inp() + '"><option value="normal">Normal</option><option value="high">High</option><option value="low">Low</option></select>')
        + '<div class="full" style="border:1px solid #E2E8F0;border-radius:12px;padding:12px 14px;background:#F8FAFC;">'
          + '<div style="font-size:12px;font-weight:800;color:#475569;margin-bottom:8px;">📣 Let them know now</div>'
          + '<div id="tf-notify" style="display:flex;flex-wrap:wrap;gap:8px 14px;">'
            + CH.map(function (c) { return '<label style="display:inline-flex;align-items:center;gap:6px;font-size:13.5px;color:#334155;cursor:pointer;"><input type="checkbox" value="' + c[0] + '"' + (c[0] === 'app' ? ' checked' : '') + '> ' + c[1] + '</label>'; }).join('')
          + '</div>'
          + '<div style="margin-top:6px;font-size:11.5px;color:#64748B;">Sent the moment you assign it. Leave all off to assign quietly.</div>'
        + '</div>'
        + reminderBlock('tf', null, '')
      + '</div>',
      '<button type="button" data-x style="' + BTN_GHOST + '">Cancel</button><button id="tf-save" type="button" style="' + BTN_MAIN + '">Assign task</button>');
    var host = pop.ov;
    wireReminder(host, 'tf');

    var sel = host.querySelector('#tf-assignee');
    Api.get('/tasks/assignees').then(function (d) {
      var a = (d && d.assignees) || [];
      // Group the dropdown by role so parents/home-visitors/educators are easy to find.
      var groups = { educator: [], home_visitor: [], guardian: [] };
      a.forEach(function (x) { (groups[x.role] || (groups[x.role] = [])).push(x); });
      var order = [['educator', 'Educators'], ['home_visitor', 'Home visitors'], ['guardian', 'Parents']];
      var html = '<option value="">Select a person…</option>';
      order.forEach(function (g) {
        var list = groups[g[0]] || [];
        if (!list.length) return;
        html += '<optgroup label="' + esc(g[1]) + '">' + list.map(function (x) {
          return '<option value="' + x.id + '">' + esc(x.name) + (x.centre_name ? ' — ' + esc(x.centre_name) : '') + '</option>';
        }).join('') + '</optgroup>';
      });
      sel.innerHTML = a.length ? html : '<option value="">No assignable people in this agency</option>';
    }).catch(function () { sel.innerHTML = '<option value="">Could not load people</option>'; });

    host.querySelector('#tf-save').addEventListener('click', function () {
      var payload = {
        assigned_to: parseInt(sel.value, 10),
        title: (host.querySelector('#tf-title').value || '').trim(),
        description: (host.querySelector('#tf-desc').value || '').trim() || null,
        priority: host.querySelector('#tf-prio').value,
        due_date: host.querySelector('#tf-due').value || null,
      };
      if (!payload.assigned_to) { toast('Pick who the task is for', false); return; }
      if (!payload.title) { toast('Give the task a title', false); return; }
      var rem = readReminder(host, 'tf', payload.due_date);
      if (rem.error) { toast(rem.error, false); return; }
      if (rem.remind_at_local) { payload.remind_at_local = rem.remind_at_local; payload.remind_channels = rem.remind_channels; }
      payload.notify_channels = [].map.call(host.querySelectorAll('#tf-notify input:checked'), function (i) { return i.value; });
      var save = host.querySelector('#tf-save'); save.disabled = true; save.textContent = 'Assigning…';
      Api.post('/tasks', payload).then(function (res) {
        var n = res && res.notified, names = { app: 'app', email: 'email', sms: 'text', voice: 'call' };
        var sent = n ? Object.keys(n).filter(function (k) { return n[k] === 'sent'; }).map(function (k) { return names[k]; }) : [];
        var miss = n ? Object.keys(n).filter(function (k) { return n[k] !== 'sent'; }).map(function (k) { return names[k]; }) : [];
        toast('Task assigned' + (sent.length ? ' · sent by ' + sent.join(', ') : '') + (miss.length ? ' · not sent by ' + miss.join(', ') + ' (their settings or consent)' : ''), !miss.length);
        pop.close();
        loadManage(main);
      }).catch(function (e) {
        toast(e && e.message || 'Could not assign task', false);
        save.disabled = false; save.textContent = 'Assign task';
      });
    });
  }
  function field(label, control) {
    return '<label style="display:block;"><span style="display:block;font-size:12px;font-weight:800;color:#475569;margin-bottom:4px;">' + esc(label) + '</span>' + control + '</label>';
  }
  function inp() {
    return 'width:100%;box-sizing:border-box;border:1.5px solid #E2E8F0;border-radius:9px;padding:9px 11px;font-size:14px;color:#0F172A;background:#fff;';
  }

  // ── Educator home tile: live "open tasks" badge ──────────────────────
  // The launcher renders a #my-tasks tile; decorate it with a count so the
  // educator sees pending work at a glance.
  function refreshTileBadge() {
    var tile = document.querySelector('.kt-tile[href="#my-tasks"]');
    if (!tile) return;
    Api.get('/tasks/mine').then(function (d) {
      var n = (d && d.open_count) || 0;
      if (KT.tileBadge) KT.tileBadge(tile, n);   // the one tile badge (kt-badges.js)
    }).catch(function () {});
  }
  // The prominent "open tasks" summary is now a KPI stat card in the role-widget
  // strip (WidgetsController for educator/parent; screen-home-visitor.js for the
  // home visitor), sitting next to "Enrolled at centre" etc. — so the standalone
  // banner was removed. This clears any banner a stale cache may still show.
  function removeLegacyBanner() {
    var b = document.getElementById('kt-open-tasks-card');
    if (b) b.remove();
  }

  function maybeBadge() {
    removeLegacyBanner();
    // Any role whose launcher shows a My-tasks tile gets the open-count tile badge.
    if (document.querySelector('.kt-tile[href="#my-tasks"]')) { refreshTileBadge(); }
  }
  window.addEventListener('hashchange', function () { setTimeout(maybeBadge, 500); });
  setInterval(maybeBadge, 4000);
  setTimeout(maybeBadge, 900);

  // ── Registrations ────────────────────────────────────────────────────
  // Anyone who can be assigned a task gets the "My tasks" screen.
  ['educator', 'home_visitor', 'guardian'].forEach(function (r) {
    Shell.registerScreen(r + ':my-tasks', renderMyTasks);
  });
  ['agency_admin', 'centre_director', 'platform_admin'].forEach(function (r) {
    Shell.registerScreen(r + ':tasks', renderManageTasks);
    // Reviewers can also have tasks assigned to them / view their own.
    Shell.registerScreen(r + ':my-tasks', renderMyTasks);
  });

  KT.Tasks = { refreshTileBadge: refreshTileBadge };
})(window);
