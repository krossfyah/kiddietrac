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
    main.innerHTML = '<div class="kt-tasks-wrap" style="max-width:820px;margin:0 auto;padding:6px 4px 40px;">'
      + '<h1 style="font-size:22px;font-weight:900;color:#0F172A;margin:4px 2px 2px;">My tasks</h1>'
      + '<div style="color:#64748B;font-size:13.5px;margin:0 2px 16px;">Work assigned to you by your director or administrator.</div>'
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
    if (t.centre_name) meta.push('🏫 ' + esc(t.centre_name));
    if (t.assigned_by_name) meta.push('From ' + esc(t.assigned_by_name));
    return '<div class="kt-task-card" data-id="' + t.id + '" style="background:#fff;border:1px solid #E2E8F0;'
      + (od ? 'border-left:4px solid #DC2626;' : 'border-left:4px solid ' + (STATUS[t.status] ? STATUS[t.status].fg : '#94A3B8') + ';')
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
        + '</td>'
        + '<td style="padding:11px 10px;vertical-align:top;color:#334155;font-size:13px;">' + esc(t.assigned_to_name || '—') + (t.centre_name ? '<div style="color:#64748B;font-size:11.5px;">' + esc(t.centre_name) + '</div>' : '') + '</td>'
        + '<td style="padding:11px 10px;vertical-align:top;">' + chip(PRIO, t.priority) + '</td>'
        + '<td style="padding:11px 10px;vertical-align:top;font-size:12.5px;' + (od ? 'color:#B91C1C;font-weight:800;' : 'color:#475569;') + '">' + (t.due_date ? esc(fmtDate(t.due_date)) + (od ? ' ⚠' : '') : '—') + '</td>'
        + '<td style="padding:11px 10px;vertical-align:top;">' + chip(STATUS, t.status) + '</td>'
        + '<td style="padding:11px 10px;vertical-align:top;white-space:nowrap;">'
          + '<button type="button" data-act="del" title="Delete task" style="border:none;background:none;cursor:pointer;font-size:15px;color:#B91C1C;padding:4px;">🗑</button>'
        + '</td>'
      + '</tr>';
    }).join('');
    return '<div style="background:#fff;border:1px solid #E2E8F0;border-radius:14px;overflow:hidden;">'
      + '<div style="overflow-x:auto;"><table style="width:100%;border-collapse:collapse;min-width:640px;">'
      + '<thead><tr style="background:#F8FAFC;text-align:left;font-size:11.5px;letter-spacing:.4px;color:#64748B;text-transform:uppercase;">'
        + '<th style="padding:10px;">Task</th><th style="padding:10px;">Assigned to</th><th style="padding:10px;">Priority</th>'
        + '<th style="padding:10px;">Due</th><th style="padding:10px;">Status</th><th style="padding:10px;"></th>'
      + '</tr></thead><tbody>' + rows + '</tbody></table></div></div>';
  }

  function wireManage(main, tasks) {
    main.querySelectorAll('#kt-tasks-table tr[data-id] button[data-act="del"]').forEach(function (b) {
      b.addEventListener('click', async function () {
        var id = b.closest('tr').getAttribute('data-id');
        if (!await KT.confirm('Delete this task? This cannot be undone.')) return;
        b.disabled = true;
        Api.delete('/tasks/' + id).then(function () { toast('Task deleted'); loadManage(main); })
          .catch(function (e) { toast(e && e.message || 'Could not delete', false); b.disabled = false; });
      });
    });
  }

  function toggleForm(main) {
    var host = main.querySelector('#kt-tasks-form');
    if (host.dataset.open === '1') { host.innerHTML = ''; host.dataset.open = '0'; return; }
    host.dataset.open = '1';
    host.innerHTML = '<div style="background:#fff;border:1px solid #E2E8F0;border-radius:14px;padding:16px;margin-bottom:16px;">'
      + '<div style="font-weight:800;color:#0F172A;margin-bottom:12px;">New task</div>'
      + '<div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">'
        + field('Assign to', '<select id="tf-assignee" style="' + inp() + '"><option value="">Loading…</option></select>')
        + field('Due date', '<input id="tf-due" type="date" style="' + inp() + '">')
        + '<div style="grid-column:1/-1;">' + field('Title', '<input id="tf-title" type="text" maxlength="200" placeholder="e.g. Update the allergy board" style="' + inp() + '">') + '</div>'
        + '<div style="grid-column:1/-1;">' + field('Details (optional)', '<textarea id="tf-desc" rows="3" maxlength="4000" style="' + inp() + 'resize:vertical;"></textarea>') + '</div>'
        + field('Priority', '<select id="tf-prio" style="' + inp() + '"><option value="normal">Normal</option><option value="high">High</option><option value="low">Low</option></select>')
      + '</div>'
      + '<div style="display:flex;gap:8px;margin-top:14px;">'
        + '<button id="tf-save" type="button" style="background:#0EA5A6;color:#fff;border:none;border-radius:10px;padding:10px 18px;font-weight:800;cursor:pointer;">Assign task</button>'
        + '<button id="tf-cancel" type="button" style="background:#F1F5F9;color:#475569;border:none;border-radius:10px;padding:10px 18px;font-weight:800;cursor:pointer;">Cancel</button>'
      + '</div></div>';

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

    host.querySelector('#tf-cancel').addEventListener('click', function () { host.innerHTML = ''; host.dataset.open = '0'; });
    host.querySelector('#tf-save').addEventListener('click', function () {
      var payload = {
        assigned_to: parseInt(sel.value, 10),
        title: (host.querySelector('#tf-title').value || '').trim(),
        description: (host.querySelector('#tf-desc').value || '').trim() || null,
        priority: host.querySelector('#tf-prio').value,
        due_date: host.querySelector('#tf-due').value || null,
      };
      if (!payload.assigned_to) { toast('Pick an educator', false); return; }
      if (!payload.title) { toast('Give the task a title', false); return; }
      var save = host.querySelector('#tf-save'); save.disabled = true; save.textContent = 'Assigning…';
      Api.post('/tasks', payload).then(function () {
        toast('Task assigned');
        host.innerHTML = ''; host.dataset.open = '0';
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
      var icon = tile.querySelector('.kt-tile-icon');
      if (!icon) return;
      var old = icon.querySelector('.kt-task-badge');
      if (old) old.remove();
      if (n > 0) {
        icon.style.position = 'relative';
        var b = document.createElement('span');
        b.className = 'kt-task-badge';
        b.textContent = n > 99 ? '99+' : n;
        b.style.cssText = 'position:absolute;top:-4px;right:-4px;min-width:20px;height:20px;padding:0 5px;'
          + 'background:#DC2626;color:#fff;border-radius:999px;font-size:11px;font-weight:900;line-height:20px;'
          + 'text-align:center;box-shadow:0 0 0 2px #fff;box-sizing:border-box;';
        icon.appendChild(b);
      }
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
