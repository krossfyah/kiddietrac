/* ============================================================
   KIDDIETRAC v22p8.1 — Custom roles matrix editor
   Hash: #admin-roles — agency_admin only
   Backend: /api/v1/admin/{roles, permission-keys} (v22p8 Phase A)
   Permissions step 1 (2026-10-06): built-in roles are read-only here except for the
   platform owner; custom roles have "Based on" + "Covers" (one centre / whole agency,
   agency only when based on Agency admin); the platform owner also sees the watch-only
   permission check (GET /admin/permission-shadow, kill switch POST .../mode).
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
      tasks: 'Tasks', own_tasks: 'Tasks', late_pickups: 'Late pickups',
      home_visits: 'Home visits', forms: 'Documents', rsvp: 'Events & RSVPs',
      email_templates: 'Communications', help: 'Help & training',
      dashboard: 'Dashboard & directory', contacts: 'Dashboard & directory', ai: 'Dashboard & directory',
      messages: 'Communications', photos: 'Daily care', care_logs: 'Daily care', wellness: 'Daily care',
      awards: 'Daily care', menu: 'Daily care', report_cards: 'Observations & curriculum',
      immunizations: 'Medical', cacfp: 'Daily care', zones: 'Daily care',
      field_trips: 'Trips & transport', bus_routes: 'Trips & transport', safe_arrival: 'Attendance & kiosk',
      closures: 'Agency settings', integrations: 'Agency settings',
      payroll: 'Payroll & expenses', expenses: 'Payroll & expenses', fundraising: 'Invoicing & billing',
      marketing: 'Enrolment & marketing', waitlist: 'Enrolment & marketing',
    };
    Object.keys(catalog).forEach(function (key) {
      var prefix = key.split('.')[0];
      var groupName = displayNames[prefix] || prefix;
      if (!groups[groupName]) groups[groupName] = [];
      groups[groupName].push({ key: key, label: catalog[key] });
    });
    // Stable category order
    var order = ['Dashboard & directory', 'Centres', 'Families & children', 'Parent scope', 'Staff', 'Rooms',
      'Daily care', 'Attendance & kiosk', 'Incidents', 'Medical', 'Observations & curriculum',
      'Late pickups', 'Tasks', 'Home visits', 'Trips & transport', 'Documents', 'Communications',
      'Events & RSVPs', 'Enrolment & marketing', 'Invoicing & billing', 'Payroll & expenses',
      'Reports', 'Help & training', 'Agency settings'];
    var sorted = [];
    order.forEach(function (n) { if (groups[n]) sorted.push({ name: n, items: groups[n] }); });
    // Append any leftover groups (defensive — keys we didn't map)
    Object.keys(groups).forEach(function (n) {
      if (order.indexOf(n) === -1) sorted.push({ name: n, items: groups[n] });
    });
    return sorted;
  }

  function toastErr(what, e) {
    var msg = what + ': ' + ((e && e.message) || 'error');
    if (Dom.toast) Dom.toast(msg, 'error'); else if (window.toast) window.toast('⚠️', what, (e && e.message) || '', '#DC2626');
  }

  function fmtWhen(s) {
    if (!s) return '—';
    try { return KT.fmtDateTime ? KT.fmtDateTime(s) : String(s).slice(0, 16) + ' UTC'; } catch (e) { return String(s).slice(0, 16); }
  }

  /* PERMISSION CHECK (WATCH-ONLY) — platform owner only. Where the roles matrix and what
     really happened disagree, grouped by permission and role. Nothing is blocked. */
  function shadowPanel(container) {
    var card = Dom.el('div', { style: 'background:white;border-radius:14px;padding:16px 20px;box-shadow:0 1px 3px rgba(0,0,0,.06);margin:0 0 20px;' });
    card.appendChild(Dom.el('div', { style: 'color:#6B7280;font-size:13px;' }, 'Loading the permission check…'));
    Api.get('/admin/permission-shadow').then(function (d) {
      Dom.clear(card);
      var head = Dom.el('div', { style: 'display:flex;flex-wrap:wrap;justify-content:space-between;align-items:center;gap:10px;' });
      head.appendChild(Dom.el('div', {}, [
        Dom.el('div', { style: 'font-size:15px;font-weight:700;color:#0F172A;' }, '🔎 Permission check (watch-only)'),
        Dom.el('div', { style: 'font-size:12.5px;color:#64748B;margin-top:2px;' },
          (d.mode === 'shadow' ? 'Watching' : 'Off') + (d.ends_on ? ' · the ' + d.days + '-day window ends ' + d.ends_on : '') +
          ' · blocks nothing · first area to enforce after this: ' + (d.first_enforced_area || 'Settings & platform screens')),
      ]));
      var next = d.mode === 'shadow' ? 'off' : 'shadow';
      var tog = btn(d.mode === 'shadow' ? 'Turn off' : 'Turn on watching', ghost(), async function () {
        tog.disabled = true;
        try { await Api.post('/admin/permission-shadow/mode', { mode: next }); renderRoles(container); }
        catch (e) { tog.disabled = false; toastErr('Could not switch', e); }
      });
      tog.style.padding = '4px 12px'; tog.style.fontSize = '12px';
      head.appendChild(tog);
      card.appendChild(head);

      var groups = d.groups || [];
      if (!groups.length) {
        card.appendChild(Dom.el('div', { style: 'margin-top:10px;font-size:13px;color:#047857;' }, 'No disagreements recorded: the matrix matches what people can really do.'));
      }
      /* Two kinds, kept apart. "Would block" = the matrix says no but the person got in:
         enforcing would take access away, so these must be zero first. "Wider" = the matrix
         says yes but today's checks refused: harmless while those checks stay in place. */
      [['deny', 'Would block — must be zero before enforcing', '#B91C1C'],
       ['allow', 'Matrix wider than today’s checks — harmless while those checks stay', '#B45309']].forEach(function (kind) {
        var list = groups.filter(function (g) { return g.matrix_decision === kind[0] && !g.known; });
        var known = groups.filter(function (g) { return g.matrix_decision === kind[0] && g.known; });
        if (!groups.length) return;
        card.appendChild(Dom.el('div', { style: 'margin-top:14px;font-size:13px;font-weight:700;color:' + kind[2] + ';' },
          kind[1] + ' (' + list.length + ')'));
        if (known.length) {
          card.appendChild(Dom.el('div', { style: 'font-size:12px;color:#64748B;margin-top:2px;' },
            '+ ' + known.length + ' expected: permissions that cover screens some of these roles can’t open today (' +
            known.map(function (g) { return g.permission; }).filter(function (v, i, a) { return a.indexOf(v) === i; }).join(', ') + ').'));
        }
        if (!list.length) return;
        var t = Dom.el('table', { style: 'width:100%;border-collapse:collapse;margin-top:6px;font-size:12.5px;' });
        var hr = Dom.el('tr');
        ['Permission', 'Role(s)', 'People', 'Calls', 'First seen', 'Last seen', 'Where'].forEach(function (h) {
          hr.appendChild(Dom.el('th', { style: 'text-align:left;padding:6px 8px;color:#64748B;font-weight:600;border-bottom:1px solid #E2E8F0;' }, h));
        });
        t.appendChild(Dom.el('thead', {}, [hr]));
        var tb = Dom.el('tbody');
        list.forEach(function (g) {
          var tr = Dom.el('tr');
          [g.permission, String(g.roles || '').replace(/,/g, ', '), String(g.people), String(g.hits),
            fmtWhen(g.first_seen), fmtWhen(g.last_seen), (g.samples || []).join('\n')].forEach(function (c, ci) {
            tr.appendChild(Dom.el('td', { style: 'padding:6px 8px;border-bottom:1px solid #F1F5F9;vertical-align:top;' + (ci === 6 ? 'white-space:pre-line;font-family:ui-monospace,monospace;font-size:11px;color:#475569;' : '') }, c));
          });
          tb.appendChild(tr);
        });
        t.appendChild(tb);
        card.appendChild(Dom.el('div', { style: 'overflow-x:auto;' }, [t]));
      });
      var conf = Object.keys(d.conflicts || {});
      if (conf.length) {
        card.appendChild(Dom.el('div', { style: 'margin-top:10px;font-size:12px;color:#64748B;' },
          conf.length + ' permissions cover screens that different roles reach today (to split before enforcing): ' + conf.join(', ')));
      }
      if (d.auditor_target) {
        card.appendChild(Dom.el('div', { style: 'margin-top:6px;font-size:12px;color:#64748B;' },
          'Auditors — today: ' + (d.auditor_today || []).join(', ') + '. Planned for enforcement (read-only, everything except money): ' + d.auditor_target.join(', ') + '.'));
      }
    }).catch(function (e) {
      Dom.clear(card);
      card.appendChild(Dom.el('div', { style: 'color:#DC2626;font-size:13px;' }, 'Could not load the permission check: ' + (e.message || 'error')));
    });
    return card;
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
      var canEditBuiltin = !!results[0].can_edit_builtin;
      var isPlatform = !!results[0].is_platform;
      var baseNames = {};
      roles.forEach(function (r) { if (r.is_system) baseNames[r.key] = r.name; });
      Dom.clear(wrap);
      wrap.appendChild(Dom.el('h1', { style: 'font-size:24px;margin:0 0 6px;' }, '🛡 Roles & permissions'));
      wrap.appendChild(Dom.el('p', {
        style: 'color:#6B7280;font-size:14px;margin:0 0 20px;line-height:1.5;',
      }, canEditBuiltin
        ? 'Built-in roles show what each role can reach today. Changes to a built-in role apply to this agency. Custom roles cover one centre unless they are based on Agency admin. The permission check is watch-only for now: it compares this list with what really happens and blocks nothing.'
        : 'Built-in roles show what each role can do today and are managed by KiddieTrac. For a different set, create a custom role (it covers one centre unless it is based on Agency admin).'));
      // One column on a phone (2026-10-20): beside a 300px list the editor was ~100px wide.
      var grid = Dom.el('div', { style: 'display:grid;grid-template-columns:'
        + (window.innerWidth <= 768 ? 'minmax(0,1fr)' : '300px 1fr') + ';gap:20px;align-items:start;' });

      /* SUBTABS (2026-10-09: "roles and permissions and permissions check should be a
         subtab"). The role editor and the watch-only permission check each get the page
         to themselves; the tab is kept in the address (&tab=) for refresh and back. Only
         platform admins see the check, so only they get the tab bar. */
      var tabHost = null;
      if (isPlatform) {
        var TABS = [{ key: 'roles', label: '🛡 Roles & permissions' }, { key: 'check', label: '🔎 Permission check' }];
        var want = ((location.hash.match(/[?&]tab=([a-z]+)/) || [])[1]) || 'roles';
        if (want !== 'check') want = 'roles';
        var bar = Dom.el('div', { role: 'tablist', style: 'display:flex;gap:6px;flex-wrap:wrap;border-bottom:1px solid #E2E8F0;margin:0 0 16px;' });
        tabHost = Dom.el('div', {});
        var checkPane = null;
        var showTab = function (key) {
          TABS.forEach(function (t) {
            var on = t.key === key;
            t.el.setAttribute('aria-selected', on ? 'true' : 'false');
            t.el.style.background = on ? '#fff' : 'transparent';
            t.el.style.color = on ? '#0C6070' : '#475569';
            t.el.style.borderColor = on ? '#E2E8F0' : 'transparent';
            t.el.style.boxShadow = on ? 'inset 0 3px 0 #0C6070' : 'none';
          });
          Dom.clear(tabHost);
          if (key === 'check') { checkPane = checkPane || shadowPanel(container); tabHost.appendChild(checkPane); }
          else { tabHost.appendChild(grid); }
          try {
            var h = location.hash.replace(/([?&])tab=[a-z]+&?/, '$1').replace(/[?&]$/, '');
            history.replaceState(null, '', h + (h.indexOf('?') >= 0 ? '&' : '?') + 'tab=' + key);
          } catch (e) {}
        };
        TABS.forEach(function (t) {
          t.el = Dom.el('button', { type: 'button', role: 'tab', 'data-kt-inpage': '1', 'data-kt-iconized': '1',
            style: 'display:inline-flex;align-items:center;gap:7px;height:36px;padding:0 14px;border:1px solid transparent;border-bottom:none;border-radius:10px 10px 0 0;background:transparent;font:inherit;font-size:13.5px;font-weight:700;cursor:pointer;margin-bottom:-1px;' }, t.label);
          t.el.addEventListener('click', function () { showTab(t.key); });
          bar.appendChild(t.el);
        });
        wrap.appendChild(bar);
        wrap.appendChild(tabHost);
        setTimeout(function () { showTab(want); }, 0);
      }

      // Left column: role list
      var listCol = Dom.el('div', { style: 'background:white;border-radius:14px;padding:14px;box-shadow:0 1px 3px rgba(0,0,0,.06);position:sticky;top:20px;' });
      var listHeader = Dom.el('div', { style: 'display:flex;justify-content:space-between;align-items:center;margin-bottom:10px;' });
      listHeader.appendChild(Dom.el('h3', { style: 'margin:0;font-size:14px;font-weight:700;letter-spacing:0.5px;color:#6B7280;text-transform:uppercase;' }, 'Roles'));
      var newBtn = btn('+ New', primary(), function () { createCustom(null); });
      newBtn.style.padding = '4px 10px';
      newBtn.style.fontSize = '12px';
      listHeader.appendChild(newBtn);
      listCol.appendChild(listHeader);

      async function createCustom(base) {
        var name = await KT.prompt({
          title: base ? 'New role based on ' + (baseNames[base.key] || base.name) : 'New custom role',
          description: base ? 'Starts with the same permissions; change them after. It covers one centre unless it is based on Agency admin.' : 'For example Lead Educator or Bookkeeper. It covers one centre unless you base it on Agency admin.',
          placeholder: 'Role name', okLabel: 'Create',
        });
        if (!name || !String(name).trim()) return;
        Api.post('/admin/roles', { name: String(name).trim(), description: '', permissions: [], base_role: base ? base.key : null, scope: 'centre' })
          .then(function () { renderRoles(container); })
          .catch(function (e) { toastErr('Could not create', e); });
      }

      var listEl = Dom.el('div', { 'data-kt-list': '1' });
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
          (r.permissions || []).length + ' permissions' + (r.is_system ? '' : ' · ' + (r.scope === 'agency' ? 'whole agency' : 'one centre'))));
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
      if (!tabHost) wrap.appendChild(grid);

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

        var locked = role.is_system && !canEditBuiltin;
        var saveBtn = btn('Save changes', primary());
        if (locked) {
          descInput.readOnly = true;
          header.appendChild(btn('Copy as custom role', ghost(), function () { createCustom(role); }));
        } else {
          header.appendChild(saveBtn);
        }
        card.appendChild(header);
        if (locked) {
          card.appendChild(Dom.el('div', { style: 'margin-top:8px;padding:8px 12px;background:#F8FAFC;border:1px solid #E2E8F0;border-radius:8px;font-size:12.5px;color:#475569;' },
            'Built-in roles are managed by KiddieTrac and show what this role can do today. To give someone a different set, copy it as a custom role.'));
        }

        // Custom roles: what they start from and how far they reach (decision 2).
        var baseSel = null, scopeSel = null;
        if (!role.is_system) {
          var row = Dom.el('div', { style: 'display:flex;flex-wrap:wrap;gap:10px 18px;align-items:center;margin-top:10px;font-size:13px;color:#374151;' });
          baseSel = Dom.el('select', { style: 'height:30px;padding:0 8px;border:1px solid #D1D5DB;border-radius:6px;font-size:13px;' });
          baseSel.appendChild(Dom.el('option', { value: '' }, '— none —'));
          Object.keys(baseNames).forEach(function (k) {
            var o = Dom.el('option', { value: k }, baseNames[k]);
            if (role.base_role === k) o.selected = true;
            baseSel.appendChild(o);
          });
          scopeSel = Dom.el('select', { style: 'height:30px;padding:0 8px;border:1px solid #D1D5DB;border-radius:6px;font-size:13px;' });
          scopeSel.appendChild(Dom.el('option', { value: 'centre' }, 'One centre'));
          scopeSel.appendChild(Dom.el('option', { value: 'agency' }, 'Whole agency'));
          scopeSel.value = role.scope === 'agency' ? 'agency' : 'centre';
          var scopeNote = Dom.el('span', { style: 'font-size:11.5px;color:#6B7280;' });
          function syncScope() {
            var agencyOk = baseSel.value === 'agency_admin';
            scopeSel.options[1].disabled = !agencyOk;
            if (!agencyOk && scopeSel.value === 'agency') scopeSel.value = 'centre';
            scopeNote.textContent = agencyOk ? '' : 'Whole agency is only for roles based on Agency admin.';
          }
          baseSel.addEventListener('change', syncScope);
          syncScope();
          row.appendChild(Dom.el('label', { style: 'display:flex;gap:6px;align-items:center;' }, ['Based on', baseSel]));
          row.appendChild(Dom.el('label', { style: 'display:flex;gap:6px;align-items:center;' }, ['Covers', scopeSel]));
          row.appendChild(scopeNote);
          card.appendChild(row);
        }

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
          if (!locked) gHeader.appendChild(toggleAll);
          section.appendChild(gHeader);

          var grid2 = Dom.el('div', { style: 'display:grid;grid-template-columns:repeat(auto-fill,minmax(280px,1fr));gap:6px 16px;padding:6px 4px;' });
          g.items.forEach(function (p) {
            var label = Dom.el('label', {
              style: 'display:flex;align-items:flex-start;gap:8px;padding:4px;cursor:pointer;font-size:13px;color:#374151;border-radius:4px;',
            });
            var cb = Dom.el('input', { type: 'checkbox' });
            cb.checked = currentPerms.has(p.key);
            if (locked) { cb.disabled = true; label.style.cursor = 'default'; }
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
          footer.appendChild(btn('🗑 Delete role', danger(), async function () {
            if (!await KT.confirm('Delete the "' + role.name + '" role? Anyone currently assigned will need to be reassigned.')) return;
            Api.delete('/admin/roles/' + role.id).then(function () {
              if (Dom.toast) Dom.toast('Role archived', 'success');
              renderRoles(container);
            }).catch(function (e) { toastErr('Could not delete', e); });
          }));
        } else {
          footer.appendChild(Dom.el('span', {
            style: 'font-size:11px;color:#6B7280;',
          }, canEditBuiltin ? 'Built-in roles cannot be deleted, only their permissions tuned.' : 'Built-in roles cannot be changed or deleted.'));
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
          if (baseSel) { payload.base_role = baseSel.value || null; payload.scope = scopeSel.value; }
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
            toastErr('Could not save', e);
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
