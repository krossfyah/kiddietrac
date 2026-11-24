/* ═══════════════════════════════════════════════════════════════════
   RESELLER HUB (2026-10-21)
   The Reseller role's own screens: the overview of every agency and centre the
   reseller brought to the platform (#reseller). Later phases add onboarding, agency
   admins, billing & commissions and the support desk here. Data comes from
   /reseller/* (ResellerController), scoped server-side to agencies.reseller_id.
   ═══════════════════════════════════════════════════════════════════ */
(function (window) {
  'use strict';
  var KT = window.KT || {};
  var Shell = KT.Shell, Api = KT.Api;
  if (!Shell || !Shell.registerScreen || !Api) { return; }

  function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }
  function money(cents, cur) {
    try { return new Intl.NumberFormat('en-CA', { style: 'currency', currency: cur || 'CAD', maximumFractionDigits: 0 }).format((cents || 0) / 100); }
    catch (e) { return '$' + Math.round((cents || 0) / 100); }
  }
  function ago(iso) {
    if (!iso) return 'never';
    var t = new Date(String(iso).replace(' ', 'T') + (/Z|[+-]\d\d:?\d\d$/.test(iso) ? '' : 'Z')).getTime();
    var m = Math.round((Date.now() - t) / 60000);
    if (!(m >= 0)) return '—';
    if (m < 60) return m + ' min ago';
    if (m < 1440) return Math.round(m / 60) + ' h ago';
    return Math.round(m / 1440) + ' d ago';
  }
  function isPlatform() {
    try { var u = (KT.Auth && KT.Auth.user()) || JSON.parse(sessionStorage.getItem('kt_user') || '{}'); return (u.roles || []).indexOf('platform_admin') !== -1; } catch (e) { return false; }
  }

  var CSS = '<style id="rh-css">'
    + '.rh-wrap{}'
    + '.rh-tiles{display:grid;grid-template-columns:repeat(auto-fill,minmax(150px,1fr));gap:12px;margin:0 0 16px}'
    + '.rh-tile{background:#fff;border:1px solid #E2E8F0;border-radius:14px;padding:12px 14px}'
    + '.rh-tile b{display:block;font-size:22px;color:#0F172A;line-height:1.2}.rh-tile span{font-size:12px;color:#64748B;font-weight:600}'
    + '.rh-bar{display:flex;flex-wrap:wrap;gap:8px;align-items:center;margin:0 0 12px}'
    + '.rh-in{height:34px;padding:0 10px;border:1px solid #CBD5E1;border-radius:9px;font:inherit;font-size:13.5px;background:#fff}'
    + '.rh-card{background:#fff;border:1px solid #E2E8F0;border-radius:14px;padding:14px 16px;margin-bottom:10px}'
    + '.rh-head{display:flex;flex-wrap:wrap;align-items:center;gap:10px}'
    + '.rh-name{font-weight:800;font-size:16px;color:#0F172A;flex:1;min-width:180px}'
    + '.rh-kpis{display:grid;grid-template-columns:repeat(auto-fill,minmax(118px,1fr));gap:8px;margin-top:10px}'
    + '.rh-kpi{background:#F8FAFC;border-radius:10px;padding:8px 10px}.rh-kpi b{display:block;font-size:15px;color:#0F172A}.rh-kpi span{font-size:11.5px;color:#64748B}'
    + '.rh-flag{display:inline-block;font-size:11.5px;font-weight:700;border-radius:999px;padding:2px 9px;margin:6px 6px 0 0}'
    + '.rh-flag.bad{background:#FEE2E2;color:#991B1B}.rh-flag.warn{background:#FEF3C7;color:#92400E}.rh-flag.info{background:#E0F2FE;color:#075985}'
    + '.rh-form{display:grid;grid-template-columns:repeat(auto-fill,minmax(240px,1fr));gap:10px}.rh-form .rh-in{width:100%;box-sizing:border-box}'
    + '.rh-field{display:flex;flex-direction:column;gap:4px;font-size:12px;font-weight:600;color:#475569}.rh-field .rh-in{width:100%;box-sizing:border-box}'
    + '.rh-sec{display:block;font-size:14px;color:#0F172A;margin:14px 0 8px}.rh-sec:first-child{margin-top:0}'
    + '.rh-centres{margin-top:10px;overflow-x:auto}.rh-centres table{width:100%;border-collapse:collapse;font-size:13px}'
    + '.rh-centres th{text-align:left;color:#64748B;font-size:11px;text-transform:uppercase;letter-spacing:.4px;padding:6px 8px;border-bottom:1px solid #E2E8F0}'
    + '.rh-centres td{padding:7px 8px;border-bottom:1px solid #F1F5F9}'
    + '</style>';

  async function openAgency(a) {
    try {
      await Api.post('/auth/active-agency', { agency_id: a.id });
      sessionStorage.setItem('kt_active_agency_id', String(a.id));
      sessionStorage.setItem('kt_active_agency_name', a.name);
      window.location.hash = 'dashboard';
      window.location.reload();
    } catch (e) {
      if (KT.toast) KT.toast('⚠️', 'Could not open that agency', (e && e.message) || '', '#B91C1C');
    }
  }

  function renderOverview(main) {
    main.innerHTML = CSS + '<div class="rh-wrap kt-section" data-rh>Loading…</div>';
    return load(main.querySelector('[data-rh]'));
  }

  async function load(host) {
    host.innerHTML = 'Loading…';
    var rid = null;
    try { rid = sessionStorage.getItem('kt_rh_reseller'); } catch (e) {}
    var d;
    try { d = await Api.get('/reseller/overview' + (isPlatform() && rid ? '?reseller_id=' + encodeURIComponent(rid) : '')); }
    catch (e) {
      host.innerHTML = '<div class="rh-card">' + esc((e && e.message) || 'Could not load your agencies.') + '</div>';
      return;
    }
    if (!host.isConnected) return;
    var t = d.totals || {};
    var list = d.agencies || [];
    var cur = (list[0] && list[0].plan && list[0].plan.currency) || 'CAD';
    var tiles = [
      [t.agencies, 'Agencies'], [t.centres, 'Centres'],
      [(t.enrolled || 0) + (t.capacity ? ' / ' + t.capacity : ''), 'Enrolled / capacity'],
      [(t.present || 0) + (t.attendance_pct != null ? ' · ' + t.attendance_pct + '%' : ''), 'Here today'],
      [t.staff, 'Staff on the floor'], [t.families, 'Families'], [t.open_tickets, 'Open tickets'],
      [money(t.mrr_cents, cur), 'Plan billing / month'], [t.flags, 'Need attention'],
    ];
    host.innerHTML = ''
      + '<div class="rh-bar"><b style="font-size:15px;color:#0F172A;">🤝 ' + esc(d.reseller.name) + '</b>'
      + (d.resellers ? '<span style="font-size:13px;color:#64748B;margin-left:8px;">Viewing reseller</span><select class="rh-in" data-rsel>'
        + d.resellers.map(function (r) { return '<option value="' + r.id + '"' + (r.id === d.reseller.id ? ' selected' : '') + '>' + esc(r.name) + '</option>'; }).join('') + '</select>' : '')
      + '</div>'
      + '<div class="rh-tiles">' + tiles.map(function (x) { return '<div class="rh-tile"><b>' + esc(x[0] == null ? '—' : x[0]) + '</b><span>' + esc(x[1]) + '</span></div>'; }).join('') + '</div>'
      + '<div class="rh-bar"><a class="kt-btn kt-btn-primary kt-btn-sm" data-kt-iconized="1" href="#reseller-onboard" style="text-decoration:none;">+ Onboard an agency</a></div>'
      + '<div class="rh-bar"><input class="rh-in" data-q type="search" placeholder="Search agencies or centres…" style="flex:1;min-width:220px;">'
      + '<select class="rh-in" data-f><option value="all">All agencies</option><option value="flag">Needs attention</option><option value="ok">Healthy</option></select>'
      + '<select class="rh-in" data-s><option value="name">Sort: name</option><option value="enrolled">Sort: enrolled</option><option value="attendance">Sort: here today %</option><option value="activity">Sort: last activity</option><option value="tickets">Sort: open tickets</option></select></div>'
      + '<div data-list></div>';
    var listEl = host.querySelector('[data-list]');
    var open = {};
    function paint() {
      var q = host.querySelector('[data-q]').value.trim().toLowerCase();
      var f = host.querySelector('[data-f]').value, s = host.querySelector('[data-s]').value;
      var rows = list.filter(function (a) {
        var bad = a.flags.some(function (x) { return x.level !== 'info'; });
        if (f === 'flag' && !bad) return false;
        if (f === 'ok' && bad) return false;
        if (!q) return true;
        return (a.name + ' ' + a.centres.map(function (c) { return c.name + ' ' + (c.city || ''); }).join(' ')).toLowerCase().indexOf(q) !== -1;
      }).sort(function (x, y) {
        if (s === 'enrolled') return y.enrolled - x.enrolled;
        if (s === 'attendance') return (y.attendance_pct || 0) - (x.attendance_pct || 0);
        if (s === 'activity') return String(y.last_activity || '').localeCompare(String(x.last_activity || ''));
        if (s === 'tickets') return y.open_tickets - x.open_tickets;
        return x.name.localeCompare(y.name);
      });
      listEl.innerHTML = rows.map(function (a) {
        var k = [
          [a.centre_count, 'Centres'], [a.enrolled + (a.capacity ? ' / ' + a.capacity : ''), 'Enrolled / capacity'],
          [a.present + (a.attendance_pct != null ? ' · ' + a.attendance_pct + '%' : ''), 'Here today'], [a.staff, 'Staff on floor'],
          [a.families, 'Families'], [a.open_tickets, 'Open tickets'], [ago(a.last_activity), 'Last activity'],
          [(a.plan.code || 'No plan') + (a.plan.amount_cents ? ' · ' + money(a.plan.amount_cents, a.plan.currency) + '/' + (a.plan.interval === 'year' ? 'yr' : 'mo') : ''), 'Plan'],
          [(a.plan.billing_status || '—') + (a.plan.overdue_cents ? ' · ' + money(a.plan.overdue_cents, a.plan.currency) + ' overdue' : ''), 'Billing'],
        ];
        return '<div class="rh-card"><div class="rh-head"><div class="rh-name">🏢 ' + esc(a.name) + '</div>'
          + '<button type="button" class="kt-btn kt-btn-secondary kt-btn-sm" data-kt-iconized="1" data-c="' + a.id + '">' + (open[a.id] ? 'Hide centres' : 'Centres (' + a.centre_count + ')') + '</button>'
          + '<button type="button" class="kt-btn kt-btn-secondary kt-btn-sm" data-kt-iconized="1" data-adm="' + a.id + '">Admins</button>'
          + '<button type="button" class="kt-btn kt-btn-primary kt-btn-sm" data-kt-iconized="1" data-o="' + a.id + '">Open agency →</button></div>'
          + (a.flags.length ? '<div>' + a.flags.map(function (x) { return '<span class="rh-flag ' + x.level + '">' + esc(x.text) + '</span>'; }).join('') + '</div>' : '')
          + '<div class="rh-kpis">' + k.map(function (x) { return '<div class="rh-kpi"><b>' + esc(x[0]) + '</b><span>' + esc(x[1]) + '</span></div>'; }).join('') + '</div>'
          + (open[a.id] ? '<div class="rh-centres"><table><thead><tr><th>Centre</th><th>City</th><th>Enrolled</th><th>Capacity</th><th>Here today</th><th>Staff on floor</th><th>Families</th></tr></thead><tbody>'
            + (a.centres.map(function (c) {
              return '<tr><td><b>' + esc(c.name) + '</b></td><td>' + esc(c.city || '—') + '</td><td>' + c.enrolled + '</td><td>' + (c.capacity || '—') + '</td><td>' + c.present
                + (c.attendance_pct != null ? ' (' + c.attendance_pct + '%)' : '') + '</td><td>' + c.staff + '</td><td>' + c.families + '</td></tr>';
            }).join('') || '<tr><td colspan="7" style="color:#64748B;">No centres yet.</td></tr>') + '</tbody></table></div>' : '')
          + '</div>';
      }).join('') || '<div class="rh-card" style="color:#64748B;">' + (list.length ? 'No agencies match.' : 'No agencies yet. Onboard your first agency to see it here.') + '</div>';
      listEl.querySelectorAll('[data-c]').forEach(function (b) { b.onclick = function () { open[b.dataset.c] = !open[b.dataset.c]; paint(); }; });
      listEl.querySelectorAll('[data-o]').forEach(function (b) { b.onclick = function () { openAgency(list.filter(function (a) { return String(a.id) === b.dataset.o; })[0]); }; });
      listEl.querySelectorAll('[data-adm]').forEach(function (b) { b.onclick = function () { openAdmins(list.filter(function (a) { return String(a.id) === b.dataset.adm; })[0]); }; });
    }
    ['[data-q]', '[data-f]', '[data-s]'].forEach(function (sel) { host.querySelector(sel).addEventListener('input', paint); });
    var rs = host.querySelector('[data-rsel]');
    if (rs) rs.onchange = function () { try { sessionStorage.setItem('kt_rh_reseller', rs.value); } catch (e) {} load(host); };
    paint();
  }

  /* ── Agency admins (phase C) ─────────────────────────────────────────────────
     The agency's OWN admins: invite another, turn one off or back on, or email a fresh
     set-password link. A reseller's own access is not listed here. */
  function openAdmins(a) {
    if (!a) return;
    var o = document.createElement('div');
    o.className = 'rh-overlay';
    o.setAttribute('style', 'position:fixed;inset:0;background:rgba(15,23,42,.45);z-index:9000;display:flex;align-items:flex-start;justify-content:center;padding:6vh 12px;overflow:auto;');
    o.innerHTML = CSS + '<div class="rh-card" style="width:100%;max-width:720px;margin:0;"><div class="rh-head"><div class="rh-name">👥 Admins of ' + esc(a.name) + '</div>'
      + '<button type="button" class="kt-btn kt-btn-secondary kt-btn-sm" data-kt-iconized="1" data-x aria-label="Close">✕</button></div><div data-body style="margin-top:10px;">Loading…</div>'
      + '<form data-inv style="margin-top:14px;border-top:1px solid #E2E8F0;padding-top:12px;"><b class="rh-sec">Invite another admin</b>'
      + '<div class="rh-form">'
      + '<input class="rh-in" name="first_name" placeholder="First name" required maxlength="80">'
      + '<input class="rh-in" name="last_name" placeholder="Last name" required maxlength="80">'
      + '<input class="rh-in" name="email" type="email" placeholder="Email" required maxlength="180">'
      + '<input class="rh-in" name="phone" type="tel" placeholder="Phone (optional)" maxlength="40"></div>'
      + '<div style="margin-top:10px;"><button type="submit" class="kt-btn kt-btn-primary kt-btn-sm" data-kt-iconized="1">Send invite</button></div></form></div>';
    document.body.appendChild(o);
    var onKey = function (e) { if (e.key === 'Escape') close(); };
    var close = function () { o.remove(); document.removeEventListener('keydown', onKey); };
    document.addEventListener('keydown', onKey);
    o.querySelector('[data-x]').onclick = close;
    var body = o.querySelector('[data-body]');
    var base = '/reseller/agencies/' + a.id + '/admins';
    async function refresh() {
      var d;
      try { d = await Api.get(base); } catch (e) { body.textContent = (e && e.message) || 'Could not load the admins.'; return; }
      var rows = d.admins || [];
      body.innerHTML = rows.length ? '<div class="rh-centres"><table><thead><tr><th>Name</th><th>Email</th><th>Status</th><th>Last sign-in</th><th></th></tr></thead><tbody>'
        + rows.map(function (u) {
          var st = !u.active ? 'Turned off' : (u.status === 'invited' ? 'Invited' : 'Active') + (u.two_factor ? ' · 2FA' : '');
          var acts = u.active
            ? '<button type="button" class="kt-btn kt-btn-secondary kt-btn-sm" data-kt-iconized="1" data-act="reset" data-u="' + u.id + '">Send new link</button> '
              + '<button type="button" class="kt-btn kt-btn-secondary kt-btn-sm" data-kt-iconized="1" data-act="deactivate" data-u="' + u.id + '">Turn off</button>'
            : '<button type="button" class="kt-btn kt-btn-secondary kt-btn-sm" data-kt-iconized="1" data-act="reactivate" data-u="' + u.id + '">Turn on</button>';
          return '<tr><td><b>' + esc(u.name) + '</b></td><td>' + esc(u.email) + '</td><td>' + esc(st) + '</td><td>' + esc(u.last_login_at ? ago(u.last_login_at) : 'never') + '</td><td style="white-space:nowrap;">' + acts + '</td></tr>';
        }).join('') + '</tbody></table></div>'
        : '<div style="color:#64748B;">This agency has no admin of its own yet. Invite one below.</div>';
      body.querySelectorAll('[data-act]').forEach(function (b) {
        b.onclick = async function () {
          var u = rows.filter(function (x) { return String(x.id) === b.dataset.u; })[0] || {};
          var act = b.dataset.act;
          if (act === 'deactivate' && KT.confirm) {
            var yes = await KT.confirm({ title: 'Turn off ' + (u.name || 'this admin') + '?', description: 'They will no longer be able to manage ' + a.name + '. You can turn them back on later.', okLabel: 'Turn off', tone: 'danger' });
            if (!yes) return;
          }
          b.disabled = true;
          try {
            var r = await Api.post(base + '/' + u.id + '/' + act, {});
            if (KT.toast) KT.toast('✅', r.message || 'Done', '', '#16A34A');
          } catch (e) { if (KT.toast) KT.toast('⚠️', 'Could not do that', (e && e.message) || '', '#DC2626'); }
          refresh();
        };
      });
    }
    o.querySelector('[data-inv]').onsubmit = async function (ev) {
      ev.preventDefault();
      var f = ev.target, btn = f.querySelector('[type=submit]');
      var data = {}; Array.prototype.forEach.call(f.elements, function (el) { if (el.name) data[el.name] = el.value.trim(); });
      btn.disabled = true;
      try {
        var r = await Api.post(base, data);
        if (KT.toast) KT.toast('✅', r.message || 'Invite sent', '', '#16A34A');
        f.reset();
        refresh();
      } catch (e) { if (KT.toast) KT.toast('⚠️', 'Could not send the invite', (e && e.message) || '', '#DC2626'); }
      btn.disabled = false;
    };
    refresh();
  }

  /* ── Onboard an agency (phase C) ────────────────────────────────────────────
     The platform's own provisioning, under this reseller: the agency, its first centre
     and its first admin, who is emailed an invite. The plan's price is the list price. */
  function renderOnboard(main) {
    var rid = null; try { rid = sessionStorage.getItem('kt_rh_reseller'); } catch (e) {}
    var f = function (name, label, type, extra) {
      return '<label class="rh-field"><span>' + label + '</span><input class="rh-in" name="' + name + '" type="' + (type || 'text') + '" ' + (extra || 'required') + '></label>';
    };
    var sel = function (name, label, opts) {
      return '<label class="rh-field"><span>' + label + '</span><select class="rh-in" name="' + name + '" required>'
        + opts.map(function (x) { return '<option value="' + x[0] + '">' + esc(x[1]) + '</option>'; }).join('') + '</select></label>';
    };
    main.innerHTML = CSS + '<div class="rh-wrap kt-section"><form class="rh-card" data-onb style="max-width:none;width:100%;box-sizing:border-box;">'
      + '<b class="rh-sec">The agency</b><div class="rh-form">'
      + f('name', 'Agency name', 'text', 'required maxlength="180"') + f('contact_email', 'Contact email', 'email', 'required maxlength="180"')
      + f('contact_phone', 'Contact phone', 'tel', 'required maxlength="40"')
      + sel('plan_code', 'Plan', [['starter', 'Starter'], ['growth', 'Growth'], ['enterprise', 'Enterprise']])
      + sel('timezone', 'Time zone', [['America/Toronto', 'Eastern'], ['America/Winnipeg', 'Central'], ['America/Edmonton', 'Mountain'], ['America/Vancouver', 'Pacific'], ['America/Halifax', 'Atlantic'], ['America/St_Johns', 'Newfoundland']])
      + sel('default_locale', 'Language', [['en', 'English'], ['fr', 'French'], ['es', 'Spanish']])
      + '</div><b class="rh-sec">Address</b><div class="rh-form">'
      + f('address_line1', 'Street address', 'text', 'required maxlength="191"') + f('address_line2', 'Unit / suite', 'text', 'maxlength="191"')
      + f('city', 'City', 'text', 'required maxlength="120"') + f('province', 'Province / state', 'text', 'required maxlength="120"')
      + f('postal_code', 'Postal code', 'text', 'required maxlength="20"')
      + sel('country', 'Country', [['CA', 'Canada'], ['US', 'United States'], ['GB', 'United Kingdom'], ['AU', 'Australia'], ['NZ', 'New Zealand'], ['IE', 'Ireland']])
      + '</div><b class="rh-sec">Their first admin</b><div class="rh-form">'
      + f('admin_first_name', 'First name', 'text', 'required maxlength="80"') + f('admin_last_name', 'Last name', 'text', 'required maxlength="80"')
      + f('admin_email', 'Email', 'email', 'required maxlength="180"') + f('admin_phone', 'Phone', 'tel', 'required maxlength="40"')
      + '</div><p style="font-size:12.5px;color:#64748B;margin:12px 0 0;">The agency carries your brand. Its admin is emailed an invite to set a password, and you can manage it straight away from All my agencies.</p>'
      + '<div style="margin-top:12px;display:flex;gap:8px;flex-wrap:wrap;"><button type="submit" class="kt-btn kt-btn-primary" data-kt-iconized="1">Add agency</button>'
      + '<a class="kt-btn kt-btn-secondary" data-kt-iconized="1" href="#reseller" style="text-decoration:none;">Back to my agencies</a></div></form></div>';
    var form = main.querySelector('[data-onb]');
    form.onsubmit = async function (ev) {
      ev.preventDefault();
      var btn = form.querySelector('[type=submit]');
      var data = {}; Array.prototype.forEach.call(form.elements, function (el) { if (el.name && el.value.trim() !== '') data[el.name] = el.value.trim(); });
      if (isPlatform() && rid) data.reseller_id = Number(rid);
      btn.disabled = true; btn.textContent = 'Adding…';
      try {
        var r = await Api.post('/reseller/agencies', data);
        if (KT.toast) KT.toast('✅', 'Agency added', r.message || '', '#16A34A');
        window.location.hash = 'reseller';
      } catch (e) {
        if (KT.toast) KT.toast('⚠️', 'Could not add the agency', (e && e.message) || '', '#DC2626');
        btn.disabled = false; btn.textContent = 'Add agency';
      }
    };
  }

  /* ── Billing & commissions (phase D) ───────────────────────────────────────
     A month at a time: what the platform billed each agency, what was collected, what
     is still owed, and the commission on it (terms set by the platform). CSV for the
     accountant. Renders into its own body so a month change never redraws the banner. */
  function renderBilling(main) {
    main.innerHTML = CSS + '<div class="rh-wrap kt-section" data-rh>Loading…</div>';
    return loadBilling(main.querySelector('[data-rh]'), null);
  }

  async function loadBilling(host, month) {
    var rid = null; try { rid = sessionStorage.getItem('kt_rh_reseller'); } catch (e) {}
    var qs = [];
    if (month) qs.push('month=' + encodeURIComponent(month));
    if (isPlatform() && rid) qs.push('reseller_id=' + encodeURIComponent(rid));
    var d;
    try { d = await Api.get('/reseller/statement' + (qs.length ? '?' + qs.join('&') : '')); }
    catch (e) { host.innerHTML = '<div class="rh-card">' + esc((e && e.message) || 'Could not load the statement.') + '</div>'; return; }
    if (!host.isConnected) return;
    var t = d.totals || {}, list = d.agencies || [];
    var cur = (list[0] && list[0].currency) || 'CAD';
    var m2 = function (c, k) { return money(c, k || cur).replace(/\.00$/, ''); };
    var cents = function (c, k) {
      try { return new Intl.NumberFormat('en-CA', { style: 'currency', currency: k || cur }).format((c || 0) / 100); } catch (e) { return '$' + ((c || 0) / 100).toFixed(2); }
    };
    var label = function (ym) { var p = ym.split('-'); return new Date(Date.UTC(+p[0], +p[1] - 1, 15)).toLocaleDateString('en-CA', { month: 'long', year: 'numeric', timeZone: 'UTC' }); };
    var pct = d.reseller.commission_pct, flat = d.reseller.commission_flat_cents;
    var terms = (pct || flat)
      ? 'Your commission: ' + (pct ? (+pct).toString() + '% of the money collected before tax' : '') + (pct && flat ? ' + ' : '') + (flat ? cents(flat) + ' for each agency that paid that month' : '') + '. Set by the platform.'
      : 'No commission terms are set for this reseller yet.';
    host.innerHTML = '<div class="rh-bar"><b style="font-size:15px;color:#0F172A;">💳 ' + esc(d.reseller.name) + '</b>'
      + (d.resellers ? '<span style="font-size:13px;color:#64748B;margin-left:8px;">Viewing reseller</span><select class="rh-in" data-rsel>'
        + d.resellers.map(function (r) { return '<option value="' + r.id + '"' + (r.id === d.reseller.id ? ' selected' : '') + '>' + esc(r.name) + '</option>'; }).join('') + '</select>' : '')
      + '<span style="flex:1"></span><select class="rh-in" data-m>' + (d.months || []).map(function (ym) { return '<option value="' + ym + '"' + (ym === d.month ? ' selected' : '') + '>' + esc(label(ym)) + '</option>'; }).join('') + '</select>'
      + '<button type="button" class="kt-btn kt-btn-secondary kt-btn-sm" data-kt-iconized="1" data-csv>Download statement (CSV)</button></div>'
      + '<div class="rh-tiles">' + [[m2(t.billed_cents), 'Billed this month'], [m2(t.collected_cents), 'Collected this month'], [m2(t.outstanding_cents), 'Still owed (all months)'],
        [cents(t.commission_cents), 'Your commission'], [t.agencies_paid + ' / ' + list.length, 'Agencies that paid']].map(function (x) {
        return '<div class="rh-tile"><b>' + esc(x[0]) + '</b><span>' + esc(x[1]) + '</span></div>'; }).join('') + '</div>'
      + '<p style="font-size:12.5px;color:#64748B;margin:0 0 12px;">' + esc(terms) + '</p>'
      + '<div class="rh-card"><div class="rh-centres" style="margin-top:0;"><table><thead><tr><th>Agency</th><th>Plan</th><th>Billed</th><th>Collected</th><th>Before tax</th><th>Still owed</th><th>Commission</th></tr></thead><tbody>'
      + (list.map(function (a) {
        var plan = (a.plan.code || '—') + (a.plan.amount_cents ? ' · ' + m2(a.plan.amount_cents, a.currency) + '/' + (a.plan.interval === 'year' ? 'yr' : 'mo') : '');
        return '<tr><td><b>' + esc(a.name) + '</b></td>'
          + '<td>' + esc(plan) + '</td><td>' + cents(a.billed_cents, a.currency) + '</td><td>' + cents(a.collected_cents, a.currency) + '</td><td>' + cents(a.collected_pretax_cents, a.currency) + '</td>'
          + '<td>' + cents(a.outstanding_cents, a.currency) + '</td><td><b>' + cents(a.commission_cents, a.currency) + '</b></td></tr>';
      }).join('') || '<tr><td colspan="7" style="color:#64748B;">No agencies yet.</td></tr>')
      + '</tbody></table></div></div>'
      + (list.some(function (a) { return a.invoices.length; }) ? '<div class="rh-card"><b class="rh-sec">Invoices behind these numbers</b>' + list.filter(function (a) { return a.invoices.length; }).map(function (a) {
        return '<div style="margin-top:8px;"><b style="font-size:13px;">' + esc(a.name) + '</b>' + a.invoices.map(function (l) {
          return '<div style="display:flex;flex-wrap:wrap;gap:12px;font-size:12.5px;padding:3px 0;"><span style="font-weight:700;">' + esc(l.number) + '</span><span>' + esc(l.period) + '</span><span>' + esc(l.status) + '</span><span>'
            + cents(l.amount_cents, l.currency) + '</span><span style="color:#64748B;">' + esc(l.counts === 'collected' ? 'paid ' + String(l.paid_at || '').slice(0, 10) : (l.counts === 'billed' ? 'billed this month' : 'owed from before')) + '</span></div>';
        }).join('') + '</div>';
      }).join('') + '</div>' : '');
    host.querySelector('[data-m]').onchange = function (e) { loadBilling(host, e.target.value); };
    var rs = host.querySelector('[data-rsel]');
    if (rs) rs.onchange = function () { try { sessionStorage.setItem('kt_rh_reseller', rs.value); } catch (e) {} loadBilling(host, d.month); };
    host.querySelector('[data-csv]').onclick = async function () {
      var tok = sessionStorage.getItem('kt_token') || localStorage.getItem('kt_token'), h = { Authorization: 'Bearer ' + tok }, aid = sessionStorage.getItem('kt_active_agency_id');
      if (aid && aid !== 'all') { h['X-Active-Agency-Id'] = aid; }
      var url = (KT.API_BASE || 'https://api.kiddietrac.com/api/v1') + '/reseller/statement?format=csv&month=' + encodeURIComponent(d.month)
        + (d.resellers ? '&reseller_id=' + d.reseller.id : '');
      try {
        var res = await fetch(url, { headers: h });
        if (!res.ok) throw new Error('HTTP ' + res.status);
        var a = document.createElement('a'); a.href = URL.createObjectURL(await res.blob()); a.download = 'statement-' + d.month + '.csv';
        document.body.appendChild(a); a.click(); a.remove();
      } catch (e) { if (KT.toast) KT.toast('⚠️', 'Could not download the statement', (e && e.message) || '', '#DC2626'); }
    };
  }

  /* ── Support desk (phase E) ─────────────────────────────────────────────────
     Every ticket from the reseller's agencies in one list. Agency tickets are read and
     answered here (the request names the ticket's own agency) and can be escalated to
     the platform team; tickets that are already with the platform team show their status
     only - the server enforces that too. */
  function agencyCall(method, path, body, agencyId) {
    var tok = sessionStorage.getItem('kt_token') || localStorage.getItem('kt_token');
    var h = { Authorization: 'Bearer ' + tok, Accept: 'application/json' };
    if (body) h['Content-Type'] = 'application/json';
    if (agencyId) h['X-Active-Agency-Id'] = String(agencyId);
    return fetch((KT.API_BASE || 'https://api.kiddietrac.com/api/v1') + path, { method: method, headers: h, body: body ? JSON.stringify(body) : undefined })
      .then(function (r) { return r.json().catch(function () { return {}; }).then(function (j) { if (!r.ok) throw new Error(j.message || ('HTTP ' + r.status)); return j; }); });
  }
  var ST = { open: 'Open', awaiting_user: 'Waiting on them', resolved: 'Resolved', closed: 'Closed' };

  function renderSupport(main) {
    main.innerHTML = CSS + '<div class="rh-wrap kt-section" data-rh>Loading…</div>';
    return loadSupport(main.querySelector('[data-rh]'), 'open');
  }

  async function loadSupport(host, status) {
    var rid = null; try { rid = sessionStorage.getItem('kt_rh_reseller'); } catch (e) {}
    var d;
    try { d = await Api.get('/reseller/tickets?status=' + status + (isPlatform() && rid ? '&reseller_id=' + encodeURIComponent(rid) : '')); }
    catch (e) { host.innerHTML = '<div class="rh-card">' + esc((e && e.message) || 'Could not load tickets.') + '</div>'; return; }
    if (!host.isConnected) return;
    var list = d.tickets || [];
    host.innerHTML = '<div class="rh-bar"><b style="font-size:15px;color:#0F172A;">🎧 ' + esc(d.reseller.name) + '</b><span style="flex:1"></span>'
      + '<select class="rh-in" data-st><option value="open"' + (status === 'open' ? ' selected' : '') + '>Open</option><option value="closed"' + (status === 'closed' ? ' selected' : '') + '>Resolved & closed</option><option value="all"' + (status === 'all' ? ' selected' : '') + '>All</option></select>'
      + '<select class="rh-in" data-ag><option value="">All agencies</option>' + (d.agencies || []).map(function (a) { return '<option value="' + a.id + '">' + esc(a.name) + '</option>'; }).join('') + '</select></div>'
      + '<p style="font-size:12.5px;color:#64748B;margin:0 0 12px;">Agency tickets are yours to answer. App problems, questions and ideas go to the platform team — you can follow their status here.</p>'
      + '<div data-list></div>';
    var listEl = host.querySelector('[data-list]');
    function paint() {
      var ag = host.querySelector('[data-ag]').value;
      var rows = list.filter(function (t) { return !ag || String(t.agency_id) === ag; });
      listEl.innerHTML = rows.length ? '<div class="rh-card"><div class="rh-centres" style="margin-top:0;"><table><thead><tr><th>#</th><th>Subject</th><th>Agency</th><th>Status</th><th>Priority</th><th>Updated</th></tr></thead><tbody>'
        + rows.map(function (t) {
          return '<tr data-t="' + t.id + '" style="cursor:pointer;"><td>' + t.id + '</td><td><b>' + esc(t.subject) + '</b>'
            + (t.with_platform ? ' <span class="rh-flag info">With the platform team</span>' : '') + (t.escalated ? ' <span class="rh-flag warn">Escalated</span>' : '')
            + '<div style="font-size:12px;color:#64748B;">' + esc(t.raised_by || '') + '</div></td><td>' + esc(t.agency) + '</td><td>' + esc(ST[t.status] || t.status) + '</td><td>' + esc(t.priority || '') + '</td><td>' + esc(ago(t.updated_at)) + '</td></tr>';
        }).join('') + '</tbody></table></div></div>'
        : '<div class="rh-card" style="color:#64748B;">No tickets here.</div>';
      listEl.querySelectorAll('[data-t]').forEach(function (tr) {
        tr.onclick = function () { openTicket(list.filter(function (t) { return String(t.id) === tr.dataset.t; })[0], function () { loadSupport(host, status); }); };
      });
    }
    host.querySelector('[data-st]').onchange = function (e) { loadSupport(host, e.target.value); };
    host.querySelector('[data-ag]').onchange = paint;
    paint();
  }

  async function openTicket(t, done) {
    if (!t) return;
    var o = document.createElement('div');
    o.className = 'rh-overlay';
    o.setAttribute('style', 'position:fixed;inset:0;background:rgba(15,23,42,.45);z-index:9000;display:flex;align-items:flex-start;justify-content:center;padding:6vh 12px;overflow:auto;');
    o.innerHTML = CSS + '<div class="rh-card" style="width:100%;max-width:760px;margin:0;"><div class="rh-head"><div class="rh-name">🎫 #' + t.id + ' ' + esc(t.subject) + '</div>'
      + '<button type="button" class="kt-btn kt-btn-secondary kt-btn-sm" data-kt-iconized="1" data-x aria-label="Close">✕</button></div>'
      + '<div style="font-size:12.5px;color:#64748B;margin-top:4px;">' + esc(t.agency) + ' · ' + esc(ST[t.status] || t.status) + '</div><div data-b style="margin-top:12px;">Loading…</div></div>';
    document.body.appendChild(o);
    var close = function () { o.remove(); document.removeEventListener('keydown', onKey); if (done) done(); };
    var onKey = function (e) { if (e.key === 'Escape') close(); };
    document.addEventListener('keydown', onKey);
    o.querySelector('[data-x]').onclick = close;
    var b = o.querySelector('[data-b]');
    var d;
    try { d = await agencyCall('GET', '/tickets/' + t.id, null, t.agency_id); }
    catch (e) { b.textContent = (e && e.message) || 'Could not open the ticket.'; return; }
    if (d.status_only) {
      b.innerHTML = '<div style="background:#F0F9FF;border-radius:10px;padding:12px 14px;font-size:13.5px;color:#075985;">' + esc(d.message || 'This ticket is with the platform team.') + '</div>'
        + '<div style="margin-top:10px;font-size:13px;">Status: <b>' + esc(ST[d.ticket.status] || d.ticket.status) + '</b>' + (d.ticket.resolved_at ? ' · resolved ' + esc(ago(d.ticket.resolved_at)) : '') + '</div>';
      return;
    }
    var tk = d.ticket || {};
    var body = '<div style="white-space:pre-wrap;font-size:13.5px;color:#334155;background:#F8FAFC;border-radius:10px;padding:10px 12px;">' + esc(tk.body || '') + '</div>'
      + (d.messages || []).map(function (m) {
        return '<div style="margin-top:10px;padding:10px 12px;border-radius:10px;background:' + (m.is_staff ? '#EEF2FF' : '#F8FAFC') + ';"><div style="font-size:12px;color:#64748B;font-weight:700;">' + esc(m.author_name || '') + ' · ' + esc(ago(m.created_at)) + '</div>'
          + '<div style="white-space:pre-wrap;font-size:13.5px;margin-top:4px;">' + esc(m.body) + '</div></div>';
      }).join('')
      + '<textarea class="rh-in" data-reply rows="3" placeholder="Write a reply…" style="width:100%;box-sizing:border-box;height:auto;padding:8px 10px;margin-top:12px;"></textarea>'
      + '<div style="display:flex;flex-wrap:wrap;gap:8px;margin-top:8px;align-items:center;">'
      + '<button type="button" class="kt-btn kt-btn-primary kt-btn-sm" data-kt-iconized="1" data-send>Send reply</button>'
      + '<select class="rh-in" data-status>' + Object.keys(ST).map(function (k) { return '<option value="' + k + '"' + (k === tk.status ? ' selected' : '') + '>' + ST[k] + '</option>'; }).join('') + '</select>'
      + '<button type="button" class="kt-btn kt-btn-secondary kt-btn-sm" data-kt-iconized="1" data-setst>Update status</button>'
      + '<span style="flex:1"></span><button type="button" class="kt-btn kt-btn-secondary kt-btn-sm" data-kt-iconized="1" data-esc>Escalate to the platform team</button></div>'
      + '<div data-m style="font-size:12.5px;margin-top:8px;min-height:16px;"></div>';
    b.innerHTML = body;
    var msg = b.querySelector('[data-m]');
    var run = async function (fn, okText) {
      msg.style.color = '#64748B'; msg.textContent = 'Working…';
      try { var r = await fn(); msg.style.color = '#15803D'; msg.textContent = (r && r.message) || okText; }
      catch (e) { msg.style.color = '#B91C1C'; msg.textContent = (e && e.message) || 'Could not do that.'; }
    };
    b.querySelector('[data-send]').onclick = function () {
      var v = b.querySelector('[data-reply]').value.trim(); if (!v) return;
      run(function () { return agencyCall('POST', '/tickets/' + t.id + '/reply', { body: v }, t.agency_id); }, 'Reply sent.').then(function () { b.querySelector('[data-reply]').value = ''; });
    };
    b.querySelector('[data-setst]').onclick = function () {
      run(function () { return agencyCall('PATCH', '/tickets/' + t.id + '/status', { status: b.querySelector('[data-status]').value }, t.agency_id); }, 'Status updated.');
    };
    b.querySelector('[data-esc]').onclick = async function () {
      var note = KT.prompt ? await KT.prompt({ title: 'Escalate to the platform team', label: 'What should they know? (optional)', okLabel: 'Escalate' }) : '';
      if (note === null || note === false) return;
      run(function () { return agencyCall('POST', '/tickets/' + t.id + '/escalate', { note: note || '' }, t.agency_id); }, 'Escalated.');
    };
  }

  // A reseller lands here; once it is inside one of its agencies its primary role is agency_admin.
  ['reseller_admin', 'agency_admin', 'platform_admin'].forEach(function (role) {
    Shell.registerScreen(role + ':reseller', renderOverview);
    Shell.registerScreen(role + ':reseller-onboard', renderOnboard);
    Shell.registerScreen(role + ':reseller-billing', renderBilling);
    Shell.registerScreen(role + ':reseller-support', renderSupport);
  });
  Shell.registerScreen('reseller_admin:home', renderOverview);
  KT.ResellerHub = { renderOverview: renderOverview };

  /* PLATFORM PAGES ARE NOT A RESELLER'S (2026-10-21). Opened by a link they answered a
     bare "Forbidden"; a Reseller is taken back to its own home with a word of why.
     The API still refuses them either way - this is only the way back. */
  var PLATFORM_ONLY = /^(platform-|resellers$|admin-mrr|admin-features|sales|prospect-ndas|mail-settings|maintenance|usage-spend|digest-status|marketing-site|phone-voicemail|security-alerts)/;
  function resellerOnly() {
    try { var r = (JSON.parse(sessionStorage.getItem('kt_user') || '{}').roles) || []; return r.indexOf('reseller_admin') !== -1 && r.indexOf('platform_admin') === -1; } catch (e) { return false; }
  }
  function keepOffPlatformPages() {
    if (!resellerOnly()) return;
    var h = (window.location.hash || '').replace(/^#/, '').split(/[?\/]/)[0];
    if (!PLATFORM_ONLY.test(h)) return;
    window.location.replace('#reseller');
    setTimeout(function () { if (KT.toast) KT.toast('🔒', 'That page is for the platform team', 'Here are your agencies instead.', '#475569'); }, 400);
  }
  window.addEventListener('hashchange', keepOffPlatformPages);
  keepOffPlatformPages();
})(window);
