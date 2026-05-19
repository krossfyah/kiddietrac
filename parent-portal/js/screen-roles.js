/* ============================================================
   KIDDIETRAC v22p8.1 — Custom roles matrix editor
   Hash: #admin-roles — agency_admin only
   Backend: /api/v1/admin/{roles, permission-keys} (v22p8 Phase A)
   ============================================================ */
(function (window) {
  'use strict';
  if (!window.KT) return;
  var KT = window.KT;
  var Api = KT.Api;
  var Dom = KT.Dom;
  var Shell = KT.Shell;

  function esc(s) {
    return s == null ? '' : String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;')
      .replace(/>/g, '&gt;').replace(/"/g, '&quot;');
  }

  function btn(label, styles, onClick) {
    var b = Dom.el('button', {
      type: 'button',
      style: 'border:none;border-radius:8px;padding:8px 14px;font-size:13px;font-weight:600;cursor:pointer;' + (styles || ''),
    }, label);
    if (onClick) b.addEventListener('click', onClick);
    return b;
  }
  function primary() { return 'background:#1F6080;color:white;'; }
  function ghost()   { return 'background:white;color:#374151;border:1px solid #D1D5DB!important;'; }
  function danger()  { return 'background:transparent;color:#DC2626;border:1px solid #FCA5A5!important;'; }

  // Group catalog permission keys by category (the prefix before the first dot).
  // 'own_*' keys collapse into a "Parent scope" category for readability.
  function categorize(catalog) {
    var groups = {};
    var displayNames = {
      centres: 'Centres', families: 'Families & children', children: 'Families & children',
      own_children: 'Parent scope', staff: 'Staff', rooms: 'Rooms',
      invoices: 'Invoicing & billing', own_invoices: 'Parent scope',
      reports: 'Reports', incidents: 'Incidents', medications: 'Medical',
      observations: 'Observations & curriculum', lesson_plans: 'Observations & curriculum',
      attendance: 'Attendance & kiosk', kiosk: 'Attendance & kiosk',
      edocuments: 'Documents', announcements: 'Communications',
      settings: 'Agency settings', roles: 'Agency settings',
      mfa: 'Agency settings', audit: 'Agency settings',
    };
    Object.keys(catalog).forEach(function (key) {
      var prefix = key.split('.')[0];
      var groupName = displayNames[prefix] || prefix;
      if (!groups[groupName]) groups[groupName] = [];
      groups[groupName].push({ key: key, label: catalog[key] });
    });
    // Stable category order
    var order = ['Centres', 'Families & children', 'Parent scope', 'Staff', 'Rooms',
      'Invoicing & billing', 'Reports', 'Incidents', 'Medical',
      'Observations & curriculum', 'Attendance & kiosk', 'Documents',
      'Communications', 'Agency settings'];
    var sorted = [];
    order.forEach(function (n) { if (groups[n]) sorted.push({ name: n, items: groups[n] }); });
    // Append any leftover groups (defensive — keys we didn't map)
    Object.keys(groups).forEach(function (n) {
      if (order.indexOf(n) === -1) sorted.push({ name: n, items: groups[n] });
    });
    return sorted;
  }

  function renderRoles(container) {
    Dom.clear(container);
    var wrap = Dom.el('div', { style: 'padding:24px;max-width:1800px;margin:0 auto;' });
    container.appendChild(wrap);

    wrap.appendChild(Dom.el('h1', { style: 'font-size:24px;margin:0 0 6px;' }, '🛡 Roles & permissions'));
    wrap.appendChild(Dom.el('p', {
      style: 'color:#6B7280;font-size:14px;margin:0 0 20px;line-height:1.5;',
    }, 'Configure what each role can do in your agency. System roles (agency_admin, centre_director, etc.) can have their permissions tuned but not renamed or deleted. Create custom roles to model your real-world staff structure — e.g. Lead Educator, Floater, Centre Manager. Phase A: these permissions are recorded but not yet enforced; the v22p8 Phase B rollout will switch existing role checks to consult this matrix.'));

    var loading = Dom.el('div', { style: 'padding:40px;text-align:center;color:#6B7280;' }, 'Loading…');
    wrap.appendChild(loading);

    Promise.all([
      Api.get('/admin/roles'),
      Api.get('/admin/permission-keys'),
    ]).then(function (results) {
      var roles = results[0].roles || [];
      var catalog = results[1].catalog || {};
      Dom.clear(wrap);
      wrap.appendChild(Dom.el('h1', { style: 'font-size:24px;margin:0 0 6px;' }, '🛡 Roles & permissions'));
      wrap.appendChild(Dom.el('p', {
        style: 'color:#6B7280;font-size:14px;margin:0 0 20px;line-height:1.5;',
      }, 'Configure what each role can do in your agency. System roles can be tuned but not renamed or deleted. Custom roles can be created and modified freely. Phase A: stored only — Phase B will activate the checks.'));

      var grid = Dom.el('div', { style: 'display:grid;grid-template-columns:300px 1fr;gap:20px;align-items:start;' });

      // Left column: role list
      var listCol = Dom.el('div', { style: 'background:white;border-radius:14px;padding:14px;box-shadow:0 1px 3px rgba(0,0,0,.06);position:sticky;top:20px;' });
      var listHeader = Dom.el('div', { style: 'display:flex;justify-content:space-between;align-items:center;margin-bottom:10px;' });
      listHeader.appendChild(Dom.el('h3', { style: 'margin:0;font-size:14px;font-weight:700;letter-spacing:0.5px;color:#6B7280;text-transform:uppercase;' }, 'Roles'));
      var newBtn = btn('+ New', primary(), function () {
        var name = window.prompt('Custom role name? (e.g. "Lead Educator")');
        if (!name || !name.trim()) return;
        Api.post('/admin/roles', { name: name.trim(), description: '', permissions: [] })
          .then(function (r) { renderRoles(container); /* re-fetch + re-render, will land on new role */ })
          .catch(function (e) { alert('Could not create: ' + (e.message || 'error')); });
      });
      newBtn.style.padding = '4px 10px';
      newBtn.style.fontSize = '12px';
      listHeader.appendChild(newBtn);
      listCol.appendChild(listHeader);

      var listEl = Dom.el('div');
      roles.forEach(function (r) {
        var item = Dom.el('div', {
          style: 'padding:10px 12px;border-radius:8px;cursor:pointer;margin-bottom:4px;border:2px solid transparent;',
        });
        item.appendChild(Dom.el('div', {
          style: 'font-weight:600;font-size:13px;display:flex;justify-content:space-between;align-items:center;gap:6px;',
        }, [
          Dom.el('span', {}, r.name),
          r.is_system
            ? Dom.el('span', { style: 'font-size:9px;background:#E5E7EB;color:#6B7280;padding:1px 5px;border-radius:4px;letter-spacing:0.5px;' }, 'SYSTEM')
            : Dom.el('span', { style: 'font-size:9px;background:#DBEAFE;color:#1F6080;padding:1px 5px;border-radius:4px;letter-spacing:0.5px;' }, 'CUSTOM'),
        ]));
        item.appendChild(Dom.el('div', { style: 'font-size:11px;color:#6B7280;margin-top:2px;' },
          (r.permissions || []).length + ' permissions'));
        item.addEventListener('click', function () { selectRole(r); });
        item.addEventListener('mouseenter', function () { if (!item.classList.contains('selected')) item.style.background = '#F9FAFB'; });
        item.addEventListener('mouseleave', function () { if (!item.classList.contains('selected')) item.style.background = ''; });
        item._role = r;
        listEl.appendChild(item);
      });
      listCol.appendChild(listEl);
      grid.appendChild(listCol);

      // Right column: detail panel (populated by selectRole)
      var detailCol = Dom.el('div');
      grid.appendChild(detailCol);
      wrap.appendChild(grid);

      function selectRole(role) {
        // Highlight selected in the list
        listEl.querySelectorAll('div').forEach(function (n) {
          n.classList.remove('selected');
          if (n._role) { n.style.background = ''; n.style.borderColor = 'transparent'; }
        });
        var found = Array.prototype.filter.call(listEl.children, function (n) { return n._role && n._role.id === role.id; })[0];
        if (found) {
          found.classList.add('selected');
          found.style.background = '#EFF6FF';
          found.style.borderColor = '#1F6080';
        }

        Dom.clear(detailCol);
        var card = Dom.el('div', { style: 'background:white;border-radius:14px;padding:20px 24px;box-shadow:0 1px 3px rgba(0,0,0,.06);' });

        // Header: name (editable iff non-system) + description
        var header = Dom.el('div', { style: 'display:flex;justify-content:space-between;align-items:flex-start;gap:12px;margin-bottom:6px;' });
        var titleArea = Dom.el('div', { style: 'flex:1;min-width:0;' });
        if (role.is_system) {
          titleArea.appendChild(Dom.el('h2', { style: 'margin:0;font-size:20px;' }, role.name));
          titleArea.appendChild(Dom.el('div', { style: 'font-size:11px;color:#6B7280;margin-top:4px;font-family:ui-monospace,monospace;' },
            'system key: ' + role.key + ' — name cannot be changed'));
        } else {
          var nameInput = Dom.el('input', {
            type: 'text', value: role.name,
            style: 'font-size:20px;font-weight:700;padding:4px 8px;border:1px solid transparent;border-radius:6px;width:100%;box-sizing:border-box;',
          });
          nameInput.addEventListener('focus', function () { nameInput.style.borderColor = '#D1D5DB'; });
          nameInput.addEventListener('blur', function () { nameInput.style.borderColor = 'transparent'; });
          titleArea.appendChild(nameInput);
          titleArea._nameInput = nameInput;
        }
        var descInput = Dom.el('textarea', {
          rows: '2',
          placeholder: 'Optional description',
          style: 'width:100%;font-size:13px;color:#6B7280;padding:6px 8px;border:1px solid transparent;border-radius:6px;margin-top:6px;font-family:inherit;resize:vertical;box-sizing:border-box;',
        });
        descInput.value = role.description || '';
        descInput.addEventListener('focus', function () { descInput.style.borderColor = '#D1D5DB'; });
        descInput.addEventListener('blur', function () { descInput.style.borderColor = 'transparent'; });
        titleArea.appendChild(descInput);
        header.appendChild(titleArea);

        var saveBtn = btn('Save changes', primary());
        header.appendChild(saveBtn);
        card.appendChild(header);

        card.appendChild(Dom.el('hr', { style: 'border:none;border-top:1px solid #E5E7EB;margin:16px 0;' }));

        // Permissions matrix
        card.appendChild(Dom.el('h3', { style: 'margin:0 0 12px;font-size:13px;font-weight:700;color:#6B7280;letter-spacing:0.5px;text-transform:uppercase;' }, 'Permissions'));

        var currentPerms = new Set(role.permissions || []);
        var groups = categorize(catalog);
        var checkboxes = {};

        groups.forEach(function (g) {
          var section = Dom.el('div', { style: 'margin-bottom:18px;' });
          var gHeader = Dom.el('div', {
            style: 'display:flex;justify-content:space-between;align-items:center;font-size:12px;font-weight:700;color:#111827;margin-bottom:6px;padding:4px 8px;background:#F3F4F6;border-radius:6px;',
          });
          gHeader.appendChild(Dom.el('span', {}, g.name));
          var toggleAll = Dom.el('a', {
            href: '#', style: 'font-size:11px;color:#1F6080;text-decoration:none;font-weight:600;',
          }, 'Toggle all');
          gHeader.appendChild(toggleAll);
          section.appendChild(gHeader);

          var grid2 = Dom.el('div', { style: 'display:grid;grid-template-columns:repeat(auto-fill,minmax(280px,1fr));gap:6px 16px;padding:6px 4px;' });
          g.items.forEach(function (p) {
            var label = Dom.el('label', {
              style: 'display:flex;align-items:flex-start;gap:8px;padding:4px;cursor:pointer;font-size:13px;color:#374151;border-radius:4px;',
            });
            var cb = Dom.el('input', { type: 'checkbox' });
            cb.checked = currentPerms.has(p.key);
            cb.style.marginTop = '3px';
            checkboxes[p.key] = cb;
            label.appendChild(cb);
            var text = Dom.el('div');
            text.appendChild(Dom.el('div', { style: 'font-weight:600;' }, p.key));
            text.appendChild(Dom.el('div', { style: 'font-size:11px;color:#6B7280;line-height:1.3;' }, p.label));
            label.appendChild(text);
            label.addEventListener('mouseenter', function () { label.style.background = '#F9FAFB'; });
            label.addEventListener('mouseleave', function () { label.style.background = ''; });
            grid2.appendChild(label);
          });
          section.appendChild(grid2);

          toggleAll.addEventListener('click', function (ev) {
            ev.preventDefault();
            var allChecked = g.items.every(function (p) { return checkboxes[p.key].checked; });
            g.items.forEach(function (p) { checkboxes[p.key].checked = !allChecked; });
          });
          card.appendChild(section);
        });

        // Delete (custom only) + save handlers
        var footer = Dom.el('div', { style: 'display:flex;justify-content:space-between;align-items:center;margin-top:18px;padding-top:14px;border-top:1px solid #E5E7EB;' });
        if (!role.is_system) {
          footer.appendChild(btn('🗑 Delete role', danger(), function () {
            if (!window.confirm('Delete the "' + role.name + '" role? Anyone currently assigned will need to be reassigned.')) return;
            Api.delete('/admin/roles/' + role.id).then(function () {
              if (Dom.toast) Dom.toast('Role archived', 'success');
              renderRoles(container);
            }).catch(function (e) { alert('Could not delete: ' + (e.message || 'error')); });
          }));
        } else {
          footer.appendChild(Dom.el('span', {
            style: 'font-size:11px;color:#6B7280;',
          }, 'System roles cannot be deleted, only their permissions tuned.'));
        }
        var permCount = Dom.el('span', { style: 'font-size:12px;color:#6B7280;' });
        function updateCount() {
          var n = 0;
          Object.keys(checkboxes).forEach(function (k) { if (checkboxes[k].checked) n++; });
          permCount.textContent = n + ' / ' + Object.keys(checkboxes).length + ' permissions selected';
        }
        Object.keys(checkboxes).forEach(function (k) {
          checkboxes[k].addEventListener('change', updateCount);
        });
        updateCount();
        footer.appendChild(permCount);
        card.appendChild(footer);

        detailCol.appendChild(card);

        saveBtn.addEventListener('click', function () {
          var perms = [];
          Object.keys(checkboxes).forEach(function (k) {
            if (checkboxes[k].checked) perms.push(k);
          });
          var payload = { description: descInput.value, permissions: perms };
          if (!role.is_system && titleArea._nameInput) {
            payload.name = titleArea._nameInput.value.trim() || role.name;
          }
          saveBtn.disabled = true;
          saveBtn.textContent = 'Saving…';
          Api.patch('/admin/roles/' + role.id, payload).then(function (resp) {
            if (Dom.toast) Dom.toast('Role saved', 'success');
            saveBtn.disabled = false;
            saveBtn.textContent = 'Save changes';
            // Refresh the role data (the row will reflect the new perm count)
            renderRoles(container);
          }).catch(function (e) {
            saveBtn.disabled = false;
            saveBtn.textContent = 'Save changes';
            alert('Could not save: ' + (e.message || 'error'));
          });
        });
      }

      // Auto-select agency_admin role on first load (most common entry)
      var initial = roles.find(function (r) { return r.key === 'agency_admin'; }) || roles[0];
      if (initial) selectRole(initial);
    }).catch(function (e) {
      Dom.clear(wrap);
      wrap.appendChild(Dom.el('div', { style: 'padding:24px;color:#DC2626;' }, 'Could not load roles: ' + e.message));
    });
  }

  if (Shell && Shell.registerScreen) {
    Shell.registerScreen('agency_admin:admin-roles', renderRoles);
  }
  KT.RolesScreen = { render: renderRoles };
})(window);
