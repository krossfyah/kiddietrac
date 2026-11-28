/* ============================================================
   KIDDIETRAC — Roles & permissions (#admin-roles, agency_admin only)
   Backend: /api/v1/admin/{roles, permission-keys, permission-shadow}

   CUSTOM ROLES (2026-11-28, Anthony: "custom roles yes build it out"). A custom role is the agency's own job title
   BASED ON a built-in role (Agency admin, Centre director, Educator, Home visitor, Auditor). It keeps some or all of
   what that base can do and is ENFORCED for whoever holds it: the screens of what it turns off leave the menu and
   the API refuses them (App\Support\CustomRoles). Who and which centres it covers always comes from the base role.
   Built-in roles are managed by KiddieTrac and behave exactly as before.

   House patterns: one table (kt-card, plain table, the last cell's buttons become the ⋮ via kt-row-actions),
   View / Edit / Preview in the house popup (Shell.Modal), the platform-only Permission check as a subtab.
   ============================================================ */
(function (window) {
  'use strict';
  if (!window.KT) return;
  var KT = window.KT;
  var Api = KT.Api;
  var Dom = KT.Dom;
  var Shell = KT.Shell;

  function btn(label, styles, onClick) {
    var b = Dom.el('button', {
      type: 'button',
      style: 'border:none;border-radius:8px;padding:0 12px;height:30px;font-size:13px;font-weight:600;cursor:pointer;' + (styles || ''),
    }, label);
    if (onClick) b.addEventListener('click', onClick);
    return b;
  }
  function primary() { return 'background:#1F6080;color:white;'; }
  function ghost()   { return 'background:white;color:#374151;border:1px solid #D1D5DB!important;'; }

  function toastErr(what, e) {
    var msg = what + ': ' + ((e && e.message) || 'error');
    if (Dom.toast) Dom.toast(msg, 'error'); else if (window.toast) window.toast('⚠️', what, (e && e.message) || '', '#DC2626');
  }
  function toastOk(msg) { if (Dom.toast) Dom.toast(msg, 'success'); }

  function fmtWhen(s) {
    if (!s) return '—';
    try { return KT.fmtDateTime ? KT.fmtDateTime(s) : String(s).slice(0, 16) + ' UTC'; } catch (e) { return String(s).slice(0, 16); }
  }

  /* PERMISSION CHECK — platform owner only. Watch-only disagreements for built-in roles, and what custom roles were
     refused. */
  function shadowPanel(container) {
    var card = Dom.el('div', { style: 'background:white;border-radius:14px;padding:16px 20px;box-shadow:0 1px 3px rgba(0,0,0,.06);margin:0 0 20px;' });
    card.appendChild(Dom.el('div', { style: 'color:#6B7280;font-size:13px;' }, 'Loading the permission check…'));
    Api.get('/admin/permission-shadow').then(function (d) {
      Dom.clear(card);
      var head = Dom.el('div', { style: 'display:flex;flex-wrap:wrap;justify-content:space-between;align-items:center;gap:10px;' });
      head.appendChild(Dom.el('div', {}, [
        Dom.el('div', { style: 'font-size:15px;font-weight:700;color:#0F172A;' }, '🔎 Permission check'),
        Dom.el('div', { style: 'font-size:12.5px;color:#64748B;margin-top:2px;' },
          'Built-in roles: ' + (d.mode === 'shadow' ? 'watched' : 'not watched') + ', never blocked. Custom roles: enforced (refusals listed below).'),
      ]));
      var next = d.mode === 'shadow' ? 'off' : 'shadow';
      var tog = btn(d.mode === 'shadow' ? 'Turn off watching' : 'Turn on watching', ghost(), async function () {
        tog.disabled = true;
        try { await Api.post('/admin/permission-shadow/mode', { mode: next }); renderRoles(container); }
        catch (e) { tog.disabled = false; toastErr('Could not switch', e); }
      });
      head.appendChild(tog);
      card.appendChild(head);

      var groups = d.groups || [];
      if (!groups.length) {
        card.appendChild(Dom.el('div', { style: 'margin-top:10px;font-size:13px;color:#047857;' }, 'Nothing recorded: the matrix matches what people can really do, and no custom role was refused.'));
      }
      [['enforced', 'Refused to custom roles', '#334155'],
       ['deny', 'Built-in: matrix would block — must be zero before enforcing built-in roles', '#B91C1C'],
       ['allow', 'Built-in: matrix wider than today’s checks — harmless while those checks stay', '#B45309']].forEach(function (kind) {
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
        var t = Dom.el('table', { 'data-kt-no-filter': '1', 'data-kt-no-bulk': '1', style: 'width:100%;border-collapse:collapse;margin-top:6px;font-size:12.5px;' });
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
    }).catch(function (e) {
      Dom.clear(card);
      card.appendChild(Dom.el('div', { style: 'color:#DC2626;font-size:13px;' }, 'Could not load the permission check: ' + (e.message || 'error')));
    });
    return card;
  }

  /* WHAT THIS PERSON WILL SEE: the base role's menu with the screens the kept set leaves out removed (and the screens
     the agency's plan leaves out, which nobody sees). Platform and reseller sections never belong to a custom role. */
  var NOT_CUSTOM = /^(reseller|resellers|sales)(-|$)/;
  function menuFor(base, kept, meta) {
    var off = {};
    var keep = {};
    (kept || []).forEach(function (k) { keep[k] = true; });
    (meta.offered[base] || []).forEach(function (k) { if (!keep[k]) off[k] = true; });
    var planHidden = {};
    try { (JSON.parse(sessionStorage.getItem('kt_user') || '{}').hidden_screens || []).forEach(function (h) { planHidden[h] = true; }); } catch (e) {}
    var secs = [];
    try { secs = (Shell.navItemsForRole && Shell.navItemsForRole(base)) || []; } catch (e) { secs = []; }
    var out = [];
    secs.forEach(function (sec) {
      if (!sec || !Array.isArray(sec.items)) return;
      var shown = [], gone = [];
      sec.items.forEach(function (it) {
        if (!it || NOT_CUSTOM.test(String(it.hash)) || planHidden[it.hash]) return;
        var key = meta.screens[it.hash];
        if (key && off[key]) gone.push(it); else shown.push(it);
      });
      if (shown.length || gone.length) out.push({ label: sec.label, shown: shown, gone: gone });
    });
    return out;
  }
  function menuPreview(base, kept, meta) {
    var box = Dom.el('div', { style: 'font-size:13px;' });
    var secs = menuFor(base, kept, meta);
    if (!secs.length) {
      box.appendChild(Dom.el('div', { style: 'color:#64748B;' }, 'The menu preview is not available here.'));
      return box;
    }
    var goneN = 0;
    secs.forEach(function (s) { goneN += s.gone.length; });
    box.appendChild(Dom.el('div', { style: 'color:#475569;margin:0 0 8px;' }, goneN
      ? goneN + ' menu item' + (goneN === 1 ? '' : 's') + ' hidden (struck through) compared with a ' + (meta.base_labels[base] || base) + '.'
      : 'The same menu as a ' + (meta.base_labels[base] || base) + '.'));
    secs.forEach(function (s) {
      box.appendChild(Dom.el('div', { style: 'font-size:11px;font-weight:800;color:#6B7280;letter-spacing:.6px;text-transform:uppercase;margin:10px 0 4px;' }, s.label || 'Menu'));
      var ul = Dom.el('div', { style: 'display:flex;flex-wrap:wrap;gap:6px;' });
      s.shown.forEach(function (it) {
        ul.appendChild(Dom.el('span', { style: 'display:inline-flex;align-items:center;gap:5px;padding:3px 9px;border-radius:999px;background:#F1F5F9;color:#0F172A;' }, (it.icon ? it.icon + ' ' : '') + it.label));
      });
      s.gone.forEach(function (it) {
        ul.appendChild(Dom.el('span', { style: 'display:inline-flex;align-items:center;gap:5px;padding:3px 9px;border-radius:999px;background:#FEF2F2;color:#991B1B;text-decoration:line-through;' }, (it.icon ? it.icon + ' ' : '') + it.label));
      });
      box.appendChild(ul);
    });
    return box;
  }

  /* The permission groups for a base: each offered key as a checkbox (or a tick, read-only). */
  function permissionGroups(base, kept, meta, readOnly, onChange) {
    var wrap = Dom.el('div');
    var keep = {};
    (kept || []).forEach(function (k) { keep[k] = true; });
    var offered = {};
    (meta.offered[base] || []).forEach(function (k) { offered[k] = true; });
    var boxes = {};
    Object.keys(meta.groups).forEach(function (gname) {
      var keys = (meta.groups[gname] || []).filter(function (k) { return offered[k]; });
      if (!keys.length) return;
      var sec = Dom.el('div', { style: 'margin-bottom:14px;' });
      var gh = Dom.el('div', { style: 'display:flex;justify-content:space-between;align-items:center;font-size:12.5px;font-weight:700;color:#111827;margin-bottom:4px;padding:4px 8px;background:#F3F4F6;border-radius:6px;' });
      gh.appendChild(Dom.el('span', {}, gname));
      if (!readOnly) {
        var all = Dom.el('button', { type: 'button', style: 'border:none;background:none;font-size:11.5px;color:#1F6080;font-weight:700;cursor:pointer;padding:0;' }, 'All on / off');
        all.addEventListener('click', function () {
          var on = keys.every(function (k) { return boxes[k].checked; });
          keys.forEach(function (k) { boxes[k].checked = !on; });
          if (onChange) onChange();
        });
        gh.appendChild(all);
      }
      sec.appendChild(gh);
      var grid = Dom.el('div', { style: 'display:grid;grid-template-columns:repeat(auto-fill,minmax(260px,1fr));gap:2px 14px;padding:2px 4px;' });
      keys.forEach(function (k) {
        var lab = Dom.el('label', { style: 'display:flex;align-items:flex-start;gap:8px;padding:4px;font-size:13px;color:#374151;border-radius:4px;' + (readOnly ? '' : 'cursor:pointer;') });
        var cb = Dom.el('input', { type: 'checkbox', style: 'margin-top:3px;' });
        cb.checked = !!keep[k];
        if (readOnly) cb.disabled = true;
        cb.addEventListener('change', function () { if (onChange) onChange(); });
        boxes[k] = cb;
        lab.appendChild(cb);
        lab.appendChild(Dom.el('span', { 'data-kttip': k }, meta.catalog[k] || k));
        grid.appendChild(lab);
      });
      sec.appendChild(grid);
      wrap.appendChild(sec);
    });
    return { el: wrap, kept: function () { return Object.keys(boxes).filter(function (k) { return boxes[k].checked; }); } };
  }

  function renderRoles(container) {
    Dom.clear(container);
    var wrap = Dom.el('div', { style: 'padding:24px;' });
    container.appendChild(wrap);
    wrap.appendChild(Dom.el('div', { style: 'padding:40px;text-align:center;color:#6B7280;' }, 'Loading…'));

    Promise.all([Api.get('/admin/roles'), Api.get('/admin/permission-keys')]).then(function (results) {
      var d = results[0] || {};
      var roles = d.roles || [];
      var meta = {
        catalog: (results[1] && results[1].catalog) || {},
        groups: d.groups || (results[1] && results[1].groups) || {},
        offered: d.offered || {},
        screens: d.screens || {},
        base_labels: d.base_labels || {},
        bases: d.base_roles || [],
      };
      var isPlatform = !!d.is_platform;
      var canEditBuiltin = !!d.can_edit_builtin;
      Dom.clear(wrap);
      wrap.appendChild(Dom.el('h1', { style: 'font-size:24px;margin:0 0 6px;' }, '🛡 Roles & permissions'));
      wrap.appendChild(Dom.el('p', { style: 'color:#6B7280;font-size:14px;margin:0 0 16px;line-height:1.5;' },
        'Built-in roles are managed by KiddieTrac. A custom role is your own job title based on one of them: it covers the same people and centres as its base and can only turn things off. Whatever it turns off leaves the person’s menu and is refused. Give it to someone in User management.'));

      var host = Dom.el('div');
      if (isPlatform) {
        var TABS = [{ key: 'roles', label: '🛡 Roles' }, { key: 'check', label: '🔎 Permission check' }];
        var want = ((location.hash.match(/[?&]tab=([a-z]+)/) || [])[1]) === 'check' ? 'check' : 'roles';
        var bar = Dom.el('div', { role: 'tablist', style: 'display:flex;gap:6px;flex-wrap:wrap;border-bottom:1px solid #E2E8F0;margin:0 0 16px;' });
        var rolesPane = null, checkPane = null;
        var show = function (key) {
          TABS.forEach(function (t) {
            var on = t.key === key;
            t.el.setAttribute('aria-selected', on ? 'true' : 'false');
            t.el.style.background = on ? '#fff' : 'transparent';
            t.el.style.color = on ? '#0C6070' : '#475569';
            t.el.style.borderColor = on ? '#E2E8F0' : 'transparent';
            t.el.style.boxShadow = on ? 'inset 0 3px 0 #0C6070' : 'none';
          });
          Dom.clear(host);
          if (key === 'check') { checkPane = checkPane || shadowPanel(container); host.appendChild(checkPane); }
          else { rolesPane = rolesPane || rolesTable(); host.appendChild(rolesPane); }
          try {
            var h = location.hash.replace(/([?&])tab=[a-z]+&?/, '$1').replace(/[?&]$/, '');
            history.replaceState(null, '', h + (h.indexOf('?') >= 0 ? '&' : '?') + 'tab=' + key);
          } catch (e) {}
        };
        TABS.forEach(function (t) {
          t.el = Dom.el('button', { type: 'button', role: 'tab', 'data-kt-inpage': '1', 'data-kt-iconized': '1',
            style: 'display:inline-flex;align-items:center;gap:7px;height:34px;padding:0 14px;border:1px solid transparent;border-bottom:none;border-radius:10px 10px 0 0;background:transparent;font:inherit;font-size:13.5px;font-weight:700;cursor:pointer;margin-bottom:-1px;' }, t.label);
          t.el.addEventListener('click', function () { show(t.key); });
          bar.appendChild(t.el);
        });
        wrap.appendChild(bar);
        wrap.appendChild(host);
        show(want);
      } else {
        wrap.appendChild(rolesTable());
      }

      function rolesTable() {
        var pane = Dom.el('div');
        var tools = Dom.el('div', { style: 'display:flex;justify-content:flex-end;gap:8px;margin:0 0 10px;' });
        tools.appendChild(btn('＋ New custom role', primary(), function () { editRole(null, null); }));
        pane.appendChild(tools);
        var card = Dom.el('div', { class: 'kt-card' });
        var table = Dom.el('table');
        var trh = Dom.el('tr');
        ['Role', 'Type', 'Based on', 'Permissions', 'People', ''].forEach(function (h) { trh.appendChild(Dom.el('th', {}, h)); });
        table.appendChild(Dom.el('thead', {}, [trh]));
        var tb = Dom.el('tbody');
        roles.forEach(function (r) {
          var tr = Dom.el('tr');
          tr.appendChild(Dom.el('td', {}, [
            Dom.el('div', { style: 'font-weight:600;' }, r.name),
            r.description ? Dom.el('div', { style: 'font-size:12px;color:#64748B;' }, r.description) : null,
          ].filter(Boolean)));
          tr.appendChild(Dom.el('td', {}, r.is_system ? 'Built-in' : 'Custom'));
          tr.appendChild(Dom.el('td', {}, r.is_system ? '—' : (meta.base_labels[r.base_role] || r.base_role || '—')));
          var permText;
          if (r.is_system) permText = (r.reaches || r.permissions || []).length + ' permissions';
          else {
            var off = (r.turned_off || []).length;
            permText = off ? off + ' turned off' : 'Everything its base has';
          }
          tr.appendChild(Dom.el('td', {}, permText));
          tr.appendChild(Dom.el('td', {}, r.is_system ? '—' : String(r.holders || 0)));
          var act = Dom.el('td');
          if (r.is_system) {
            act.appendChild(btn('👁 View', ghost(), function () { viewBuiltin(r); }));
            if (canEditBuiltin) act.appendChild(btn('✏️ Edit built-in', ghost(), function () { editBuiltin(r); }));
            if (r.can_be_base) act.appendChild(btn('⧉ Copy as custom role', ghost(), function () { duplicate(r); }));
          } else {
            act.appendChild(btn('✏️ Edit', ghost(), function () { editRole(r, null); }));
            act.appendChild(btn('👁 Preview menu', ghost(), function () { previewRole(r); }));
            act.appendChild(btn('⧉ Duplicate', ghost(), function () { duplicate(r); }));
            act.appendChild(btn('🗑 Delete', ghost(), function () { removeRole(r); }));
          }
          tr.appendChild(act);
          tb.appendChild(tr);
        });
        table.appendChild(tb);
        card.appendChild(table);
        pane.appendChild(card);
        return pane;
      }

      function viewBuiltin(r) {
        var base = r.key;
        var body = Dom.el('div');
        body.appendChild(Dom.el('p', { style: 'margin:0 0 12px;font-size:13px;color:#475569;' }, r.description || ''));
        if (meta.offered[base]) {
          body.appendChild(permissionGroups(base, r.reaches || r.permissions, meta, true).el);
        } else {
          (r.permissions || []).forEach(function (k) { body.appendChild(Dom.el('div', { style: 'font-size:13px;' }, '✓ ' + (meta.catalog[k] || k))); });
        }
        Shell.Modal.open({
          title: '🛡 ' + r.name, body: body, large: true,
          actions: r.can_be_base ? [{ label: 'Close' }, { label: 'Copy as custom role', primary: true, handler: function () { duplicate(r); } }] : [{ label: 'Close' }],
        });
      }

      function editBuiltin(r) {
        // Platform only: tune a built-in row (descriptive for built-in roles).
        var body = Dom.el('div');
        var pg = permissionGroups(r.key, r.permissions, { catalog: meta.catalog, groups: meta.groups, offered: (function () { var o = {}; o[r.key] = Object.keys(meta.catalog); return o; })() }, false);
        body.appendChild(Dom.el('p', { style: 'margin:0 0 10px;font-size:12.5px;color:#B45309;' }, 'Platform only. A built-in role’s list describes it for this agency; it does not change what people on it can do.'));
        body.appendChild(pg.el);
        Shell.Modal.open({ title: '✏️ ' + r.name + ' (built-in)', body: body, large: true, actions: [{ label: 'Cancel' },
          { label: 'Save', primary: true, handler: async function () { await Api.patch('/admin/roles/' + r.id, { permissions: pg.kept() }); toastOk('Role saved'); renderRoles(container); } }] });
      }

      async function duplicate(r) {
        var name = await KT.prompt({
          title: r.is_system ? 'New custom role based on ' + r.name : 'Duplicate ' + r.name,
          description: r.is_system ? 'It starts with everything a ' + r.name + ' has. Turn off what this job should not do.' : 'The copy starts with the same permissions.',
          okLabel: 'Create',
          fields: [{ key: 'value', label: 'Role name', placeholder: 'e.g. Lead educator', value: r.is_system ? '' : 'Copy of ' + r.name }],
        });
        if (!name || !String(name).trim()) return;
        try {
          var res = await Api.post('/admin/roles/' + r.id + '/duplicate', { name: String(name).trim() });
          toastOk('Role created');
          renderRoles(container);
          if (res && res.role) setTimeout(function () { editRole(res.role, null); }, 50);
        } catch (e) { toastErr('Could not create', e); }
      }

      async function removeRole(r) {
        if ((r.holders || 0) > 0) {
          toastErr('Could not delete', { message: (r.holders === 1 ? '1 person holds' : r.holders + ' people hold') + ' this role. Give them another role in User management first.' });
          return;
        }
        if (!await KT.confirm('Delete the "' + r.name + '" role? Nobody holds it, so nobody loses access.')) return;
        try { await Api.delete('/admin/roles/' + r.id); toastOk('Role deleted'); renderRoles(container); }
        catch (e) { toastErr('Could not delete', e); }
      }

      function previewRole(r) {
        Shell.Modal.open({ title: '👁 What a ' + r.name + ' sees', body: menuPreview(r.base_role, r.permissions, meta), large: true,
          actions: [{ label: 'Close' }, { label: 'Edit role', primary: true, handler: function () { editRole(r, null); } }] });
      }

      /* Create (r = null) or edit a custom role, in the house popup: name, based on, description, the permission
         groups of that base, and a live preview of the menu. */
      function editRole(r) {
        var isNew = !r;
        var base = r ? r.base_role : (meta.bases[2] || 'educator');
        var kept = r ? r.permissions.slice() : (meta.offered[base] || []).slice();
        var body = Dom.el('div', { style: 'display:grid;grid-template-columns:' + (window.innerWidth <= 900 ? 'minmax(0,1fr)' : 'minmax(0,1.5fr) minmax(0,1fr)') + ';gap:18px;align-items:start;' });
        var left = Dom.el('div', { style: 'min-width:0;' });
        var right = Dom.el('div', { style: 'min-width:0;background:#F8FAFC;border:1px solid #E2E8F0;border-radius:10px;padding:12px 14px;' });
        body.appendChild(left); body.appendChild(right);

        var field = 'width:100%;box-sizing:border-box;height:32px;padding:0 10px;border:1px solid #D1D5DB;border-radius:6px;font-size:13px;';
        var nameIn = Dom.el('input', { type: 'text', maxlength: '120', placeholder: 'e.g. Lead educator', style: field });
        nameIn.value = r ? r.name : '';
        var baseSel = Dom.el('select', { style: field });
        meta.bases.forEach(function (b) {
          var o = Dom.el('option', { value: b }, meta.base_labels[b] || b);
          if (b === base) o.selected = true;
          baseSel.appendChild(o);
        });
        var held = r ? (r.holders || 0) : 0;
        if (held > 0) baseSel.disabled = true;
        var descIn = Dom.el('textarea', { rows: '2', maxlength: '500', placeholder: 'What this job does (optional)', style: 'width:100%;box-sizing:border-box;min-height:52px;padding:6px 10px;border:1px solid #D1D5DB;border-radius:6px;font-size:13px;font-family:inherit;resize:vertical;' });
        descIn.value = r ? (r.description || '') : '';
        function lab(t, el, note) {
          return Dom.el('label', { style: 'display:block;margin:0 0 10px;font-size:12.5px;font-weight:600;color:#374151;' },
            [Dom.el('div', { style: 'margin-bottom:4px;' }, t), el].concat(note ? [Dom.el('div', { style: 'font-weight:400;font-size:11.5px;color:#64748B;margin-top:3px;' }, note)] : []));
        }
        left.appendChild(lab('Name', nameIn));
        left.appendChild(lab('Based on', baseSel, held > 0
          ? held + ' ' + (held === 1 ? 'person holds' : 'people hold') + ' this role, so its base stays. Make a new role for another base.'
          : 'Who and which centres it covers comes from this role: Agency admin and Auditor cover the whole agency, the others the centres each person is placed at.'));
        left.appendChild(lab('Description', descIn));
        var countEl = Dom.el('div', { style: 'font-size:12.5px;color:#475569;margin:4px 0 10px;' });
        left.appendChild(Dom.el('div', { style: 'font-size:11px;font-weight:800;color:#6B7280;letter-spacing:.6px;text-transform:uppercase;margin:6px 0 4px;' }, 'What this role can do'));
        left.appendChild(countEl);
        var groupsHost = Dom.el('div');
        left.appendChild(groupsHost);

        right.appendChild(Dom.el('div', { style: 'font-size:11px;font-weight:800;color:#6B7280;letter-spacing:.6px;text-transform:uppercase;margin:0 0 6px;' }, 'What this person will see'));
        var prevHost = Dom.el('div');
        right.appendChild(prevHost);

        var pg = null;
        function refresh() {
          var k = pg ? pg.kept() : kept;
          var total = (meta.offered[base] || []).length;
          var off = total - k.length;
          countEl.textContent = off ? off + ' of ' + total + ' turned off. Unticked = refused and hidden from the menu.' : 'Everything a ' + (meta.base_labels[base] || base) + ' can do. Untick what this job should not do.';
          Dom.clear(prevHost);
          prevHost.appendChild(menuPreview(base, k, meta));
        }
        function drawGroups() {
          Dom.clear(groupsHost);
          pg = permissionGroups(base, kept, meta, false, refresh);
          groupsHost.appendChild(pg.el);
          refresh();
        }
        baseSel.addEventListener('change', function () {
          base = baseSel.value;
          kept = (meta.offered[base] || []).slice();   // a new base starts with everything it has
          drawGroups();
        });
        drawGroups();

        Shell.Modal.open({
          title: isNew ? '＋ New custom role' : '✏️ ' + r.name,
          body: body, large: true,
          actions: [{ label: 'Cancel' }, {
            label: isNew ? 'Create role' : 'Save changes', primary: true, busyLabel: 'Saving…',
            handler: async function () {
              var name = String(nameIn.value || '').trim();
              if (!name) { toastErr('Could not save', { message: 'Give the role a name.' }); try { nameIn.focus(); } catch (e) {} return false; }
              var payload = { name: name, description: descIn.value || '', permissions: pg.kept(), base_role: base };
              if (!payload.permissions.length) { toastErr('Could not save', { message: 'Keep at least one permission ticked.' }); return false; }
              if (isNew) await Api.post('/admin/roles', payload);
              else await Api.patch('/admin/roles/' + r.id, payload);
              toastOk(isNew ? 'Role created' : 'Role saved');
              renderRoles(container);
            },
          }],
        });
      }
    }).catch(function (e) {
      Dom.clear(wrap);
      wrap.appendChild(Dom.el('div', { style: 'padding:24px;color:#DC2626;' }, 'Could not load roles: ' + ((e && e.message) || 'error')));
    });
  }

  if (Shell && Shell.registerScreen) {
    Shell.registerScreen('agency_admin:admin-roles', renderRoles);
  }
  KT.RolesScreen = { render: renderRoles };
})(window);
