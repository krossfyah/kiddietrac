/* ═══════════════════════════════════════════════════════════════════
   KiddieTrac — Server health (2026-10-22). Super admin only.

   Anthony: "build it now and have it ready and add it under the platform overview
   section". Reads GET /platform/server-health (App\Support\ServerHealth): the machine
   (CPU, memory, disk), the database, background jobs, the scheduler, what people felt
   in the last 24 hours (errors, slow and failed requests), certificates and backups,
   plus a 5-minute history. Refreshes itself every 30 seconds while open.
   Registered agency_admin/platform_admin:server-health; the API route is
   role:platform_admin, so anyone else gets a plain message.
   ═══════════════════════════════════════════════════════════════════ */
(function (window) {
  'use strict';
  var KT = window.KT || {};
  var Shell = KT.Shell, Api = KT.Api;
  if (!Shell || !Api) return;

  var hours = 24, timer = null;

  function esc(s) { return String(s == null ? '' : s).replace(/[&<>"]/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]; }); }
  function bytes(n) {
    if (n == null) return '—';
    var u = ['B', 'KB', 'MB', 'GB', 'TB'], i = 0; n = Number(n);
    while (n >= 1024 && i < u.length - 1) { n /= 1024; i++; }
    return (n >= 100 || i === 0 ? Math.round(n) : n.toFixed(1)) + ' ' + u[i];
  }
  function dur(s) {
    if (s == null) return '—';
    s = Number(s);
    if (s < 90) return Math.round(s) + ' sec';
    if (s < 5400) return Math.round(s / 60) + ' min';
    if (s < 172800) return Math.round(s / 3600) + ' h';
    return Math.round(s / 86400) + ' days';
  }
  function num(n, d) { return n == null ? '—' : Number(n).toLocaleString(undefined, { maximumFractionDigits: d == null ? 0 : d }); }
  function tone(pct, amber, red) { return pct == null ? '#94A3B8' : pct >= red ? '#DC2626' : pct >= amber ? '#D97706' : '#16A34A'; }

  function bar(pct, color) {
    var w = pct == null ? 0 : Math.max(2, Math.min(100, pct));
    return '<div style="height:7px;background:#EEF2F7;border-radius:99px;overflow:hidden;margin-top:8px;"><div style="height:100%;width:' + w + '%;background:' + color + ';border-radius:99px;"></div></div>';
  }
  function card(title, big, sub, pct, color) {
    return '<div class="sh-card"><div class="sh-k">' + esc(title) + '</div>'
      + '<div class="sh-v" style="color:' + (color || '#0F172A') + ';">' + big + '</div>'
      + (pct !== undefined ? bar(pct, color || '#1F6080') : '')
      + (sub ? '<div class="sh-s">' + sub + '</div>' : '') + '</div>';
  }

  /* A small line chart from the 5-minute samples. */
  function spark(rows, key, label, unit, color, max) {
    var pts = rows.map(function (r) { return r[key] == null ? null : Number(r[key]); });
    var vals = pts.filter(function (v) { return v != null; });
    var W = 300, H = 64;
    if (vals.length < 2) {
      return '<div class="sh-chart"><div class="sh-k">' + esc(label) + '</div><div class="sh-empty">Collecting — the history fills in every 5 minutes.</div></div>';
    }
    var top = max || Math.max.apply(null, vals) * 1.15 || 1;
    var n = pts.length, d = '', started = false;
    pts.forEach(function (v, i) {
      if (v == null) { started = false; return; }
      var x = (i / (n - 1)) * W, y = H - (Math.min(v, top) / top) * (H - 4) - 2;
      d += (started ? 'L' : 'M') + x.toFixed(1) + ' ' + y.toFixed(1) + ' ';
      started = true;
    });
    var last = vals[vals.length - 1], peak = Math.max.apply(null, vals), avg = vals.reduce(function (a, b) { return a + b; }, 0) / vals.length;
    return '<div class="sh-chart"><div style="display:flex;justify-content:space-between;align-items:baseline;gap:8px;">'
      + '<div class="sh-k">' + esc(label) + '</div><div style="font-size:13px;font-weight:800;color:' + color + ';">' + num(last, 1) + unit + '</div></div>'
      + '<svg viewBox="0 0 ' + W + ' ' + H + '" preserveAspectRatio="none" style="width:100%;height:64px;display:block;margin-top:6px;">'
      + '<path d="' + d + '" fill="none" stroke="' + color + '" stroke-width="2" vector-effect="non-scaling-stroke" stroke-linejoin="round"/></svg>'
      + '<div class="sh-s">Average ' + num(avg, 1) + unit + ' · peak ' + num(peak, 1) + unit + '</div></div>';
  }

  var CSS = ''
    + '.sh-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(210px,1fr));gap:12px}'
    /* Even rows at every width: 8 status cards as 4×2, 6 history charts as 3×2. */
    + '.sh-grid.sh-4{grid-template-columns:repeat(4,minmax(0,1fr))}.sh-grid.sh-3{grid-template-columns:repeat(3,minmax(0,1fr))}'
    + '@media (max-width:1100px){.sh-grid.sh-4,.sh-grid.sh-3{grid-template-columns:repeat(2,minmax(0,1fr))}}'
    + '@media (max-width:600px){.sh-grid.sh-4,.sh-grid.sh-3{grid-template-columns:minmax(0,1fr)}}'
    + '.sh-card,.sh-chart,.sh-panel{background:#fff;border:1px solid #E5EAF0;border-radius:14px;padding:14px 16px;box-sizing:border-box;min-width:0}'
    + '.sh-k{font-size:11.5px;font-weight:800;letter-spacing:.04em;text-transform:uppercase;color:#64748B}'
    + '.sh-v{font-size:24px;font-weight:900;margin-top:4px;line-height:1.15}'
    + '.sh-s{font-size:12.5px;color:#64748B;margin-top:6px;line-height:1.4}'
    + '.sh-empty{font-size:12.5px;color:#94A3B8;padding:18px 0}'
    + '.sh-h{font-size:15px;font-weight:800;color:#0F172A;margin:22px 0 10px}'
    + '.sh-t{width:100%;border-collapse:collapse;font-size:13px}'
    + '.sh-t th{text-align:left;font-size:11.5px;color:#64748B;font-weight:700;padding:7px 8px;border-bottom:1px solid #E5EAF0}'
    + '.sh-t td{padding:8px;border-bottom:1px solid #F1F5F9;vertical-align:top}'
    + '.sh-alert{display:flex;gap:10px;align-items:flex-start;padding:10px 14px;border-radius:12px;margin-bottom:8px;font-size:13.5px;font-weight:600;line-height:1.45}'
    + '.sh-bar{display:flex;flex-wrap:wrap;gap:8px 14px;align-items:center;justify-content:space-between;margin-bottom:14px}'
    + '.sh-bar select,.sh-bar button{height:32px;border:1px solid #CBD5E1;border-radius:8px;background:#fff;font-size:13px;padding:0 10px;cursor:pointer;white-space:nowrap;display:inline-flex;align-items:center;gap:4px}'
    + '@media (min-width:641px){.sh-ctl{flex-wrap:nowrap!important;flex:0 0 auto}}'
    + '@media (max-width:640px){.sh-v{font-size:20px}}';


  /* ── Cloudways: the new server, through Cloudways' own API (2026-10-22) ──
     Anthony: "did you wire up the cloudways as this is more important that godaddy".
     Until DNS moves, this page runs on GoDaddy, so the new server is read through the
     Cloudways API v2 with a read-only access token (stored encrypted on the server,
     never sent back). Fetched separately so the rest of the page never waits on it,
     and kept between the 30-second refreshes (Cloudways is re-read every 5 minutes). */
  var cw = { html: '', duration: '1 Day', at: 0, busy: false };

  function cwSpark(g) {
    var pts = g.points || [];
    var W = 300, H = 64;
    if (pts.length < 2) {
      return '<div class="sh-chart"><div class="sh-k">' + esc(g.label) + '</div><div class="sh-empty">No readings from Cloudways for this period.</div></div>';
    }
    var t0 = pts[0][0], t1 = pts[pts.length - 1][0], vals = pts.map(function (p) { return p[1]; });
    var u = g.unit || '';
    var top = (u === '%' ? 100 : Math.max.apply(null, vals) * 1.15) || 1, d = '';
    pts.forEach(function (p, i) {
      var x = ((p[0] - t0) / Math.max(1, t1 - t0)) * W, y = H - (p[1] / top) * (H - 4) - 2;
      d += (i ? 'L' : 'M') + x.toFixed(1) + ' ' + y.toFixed(1) + ' ';
    });
    var last = vals[vals.length - 1], peak = Math.max.apply(null, vals), avg = vals.reduce(function (a, b) { return a + b; }, 0) / vals.length;
    return '<div class="sh-chart"><div style="display:flex;justify-content:space-between;align-items:baseline;gap:8px;">'
      + '<div class="sh-k">' + esc(g.label) + '</div><div style="font-size:13px;font-weight:800;color:#0E7490;">' + num(last, 1) + esc(u) + '</div></div>'
      + '<svg viewBox="0 0 ' + W + ' ' + H + '" preserveAspectRatio="none" style="width:100%;height:64px;display:block;margin-top:6px;">'
      + '<path d="' + d + '" fill="none" stroke="#0E7490" stroke-width="2" vector-effect="non-scaling-stroke" stroke-linejoin="round"/></svg>'
      + '<div class="sh-s">Average ' + num(avg, 1) + esc(u) + ' · peak ' + num(peak, 1) + esc(u) + '</div></div>';
  }

  function cwConnectForm(msg) {
    return '<div class="sh-panel">'
      + '<div style="font-size:13.5px;color:#334155;line-height:1.55;">Connect your Cloudways account to see the new server here — its processor, memory, disk and bandwidth graphs — before and after the move. '
      + 'In Cloudways open <a href="https://platform.cloudways.com/api" target="_blank" rel="noopener" style="color:#1F6080;font-weight:800;">API Integration</a> (top-right profile menu → <b>API Integration</b>; only the account’s primary owner sees it), then under <b>Access Token Details</b> click <b>Create Access Token</b>: name it e.g. “KiddieTrac monitoring”, Scope <b>Read-Only Access</b>, choose an expiry, and copy the token (Cloudways shows it once). Paste it below. It is checked with Cloudways, stored encrypted, and never shown again.</div>'
      + '<div style="display:flex;gap:8px;flex-wrap:wrap;margin-top:12px;">'
      + '<input id="cw-token" type="password" autocomplete="off" placeholder="Cloudways access token" style="flex:1 1 280px;min-width:0;height:38px;border:1px solid #CBD5E1;border-radius:10px;padding:0 12px;font-size:14px;">'
      + '<button type="button" id="cw-connect" data-kt-iconized="1" style="height:38px;padding:0 16px;border:0;border-radius:10px;background:#1F6080;color:#fff;font-weight:800;font-size:13.5px;cursor:pointer;">Connect Cloudways</button></div>'
      + '<div id="cw-msg" style="font-size:13px;font-weight:600;margin-top:8px;color:#B91C1C;">' + esc(msg || '') + '</div></div>';
  }

  function cwRender(c) {
    if (!c || !c.connected) return cwConnectForm();
    var picker = (c.servers && c.servers.length > 1)
      ? '<select id="cw-server" style="height:32px;border:1px solid #CBD5E1;border-radius:8px;font-size:13px;padding:0 8px;">'
        + c.servers.map(function (s) { return '<option value="' + esc(s.id) + '"' + (c.server && c.server.id === s.id ? ' selected' : '') + '>' + esc((s.label || s.id) + ' · ' + s.ip) + '</option>'; }).join('') + '</select>' : '';
    if (!c.ok) {
      return '<div class="sh-alert" style="background:#FFFBEB;color:#92400E;border:1px solid #FDE68A;">🟠<div>' + esc(c.problem || 'Cloudways could not be read just now.') + '</div></div>'
        + (picker ? '<div style="margin:6px 0 10px;">' + picker + '</div>' : '')
        + cwConnectForm();
    }
    var s = c.server || {};
    var head = '<div class="sh-bar"><div style="font-size:13px;color:#475569;line-height:1.5;"><b style="color:#0F172A;">' + esc(s.label || 'Cloudways server') + '</b> · ' + esc(s.ip)
      + (s.cloud ? ' · ' + esc(s.cloud.toUpperCase()) : '') + (s.region ? ' ' + esc(s.region) : '') + (s.size ? ' · ' + esc(s.size) : '')
      + (s.status ? ' · <b style="color:' + (/run|on|active/i.test(s.status) ? '#16A34A' : '#D97706') + ';">' + esc(s.status) + '</b>' : '')
      + (s.apps && s.apps.length ? '<br><span style="color:#94A3B8;">Apps: ' + s.apps.map(function (a) { return esc(a.label || a.app); }).join(', ') + '</span>' : '')
      + '</div><div class="sh-ctl" style="display:flex;gap:8px;flex-wrap:wrap;">' + picker
      + '<select id="cw-duration">' + (c.durations || []).map(function (x) { return '<option' + (x === cw.duration ? ' selected' : '') + '>' + esc(x) + '</option>'; }).join('') + '</select>'
      + '<button type="button" id="cw-reload" data-kt-iconized="1">↻ Cloudways</button>'
      + '<button type="button" id="cw-disconnect" data-kt-iconized="1" style="color:#B91C1C;">Disconnect</button></div></div>';
    var sum = function (title, rows) {
      if (!rows || !rows.length) return '';
      return '<div class="kt-card"><div class="sh-k" style="margin-bottom:6px;">' + esc(title) + '</div><table><tbody>'
        + rows.slice(0, 12).map(function (r) { return '<tr><td>' + esc(String(r[0]).replace(/_/g, ' ')) + '</td><td style="text-align:right;font-weight:700;">' + (r[2] === 'MB' ? bytes(Number(r[1]) * 1048576) : esc(r[1])) + '</td></tr>'; }).join('') + '</tbody></table></div>';
    };
    var graphs = (c.graphs || []).length
      ? '<div class="sh-grid sh-3">' + c.graphs.map(cwSpark).join('') + '</div>'
      : '<div class="sh-s">Cloudways listed no graphs for this server' + (c.targets && c.targets.length ? '' : ' (no graph types were returned)') + '.</div>';
    var sums = sum('Bandwidth', c.bandwidth) + sum('Disk use by app', c.disk_per_app);
    return head + graphs + (sums ? '<div class="sh-grid" style="margin-top:12px;">' + sums + '</div>' : '')
      + '<div class="sh-s" style="margin-top:6px;">From Cloudways · ' + (c.fetched_at ? new Date(c.fetched_at).toLocaleTimeString([], { hour: 'numeric', minute: '2-digit' }) : '') + ' · re-read every 5 minutes</div>';
  }

  function cwWire(main) {
    var box = main.querySelector('#sh-cw');
    if (!box) return;
    var b;
    if ((b = box.querySelector('#cw-connect'))) b.onclick = async function () {
      var tok = (box.querySelector('#cw-token').value || '').trim(), msg = box.querySelector('#cw-msg');
      if (tok.length < 20) { msg.textContent = 'Paste the whole access token from Cloudways.'; return; }
      if (cw.busy) return; cw.busy = true; msg.style.color = '#334155'; msg.textContent = 'Checking with Cloudways…';
      try { var c = await Api.put('/platform/server-health/cloudways', { token: tok }); cw.html = cwRender(c); cw.at = Date.now(); renderPane(main); }
      catch (e) { msg.style.color = '#B91C1C'; msg.textContent = e.message; }
      cw.busy = false;
    };
    if ((b = box.querySelector('#cw-duration'))) b.onchange = function () { cw.duration = this.value; cwLoad(main, true); };
    if ((b = box.querySelector('#cw-reload'))) b.onclick = function () { cwLoad(main, true); };
    if ((b = box.querySelector('#cw-server'))) b.onchange = async function () {
      try { var c = await Api.put('/platform/server-health/cloudways', { server_id: this.value }); cw.html = cwRender(c); cw.at = Date.now(); renderPane(main); } catch (e) {}
    };
    if ((b = box.querySelector('#cw-disconnect'))) b.onclick = async function () {
      if (KT.confirm && !(await KT.confirm('Disconnect Cloudways? The access token is deleted from KiddieTrac. You can connect again any time.'))) return;
      try { await Api.request('/platform/server-health/cloudways', { method: 'DELETE' }); } catch (e) {}
      cw.html = cwRender({ connected: false }); cw.at = Date.now(); renderPane(main);
    };
  }

  async function cwLoad(main, fresh) {
    if (cw.busy) return;
    cw.busy = true;
    var box = main.querySelector('#sh-cw');
    if (box && fresh) box.insertAdjacentHTML('afterbegin', '<div class="sh-s" data-cw-loading>Reading Cloudways…</div>');
    try {
      var c = await Api.get('/platform/server-health/cloudways?duration=' + encodeURIComponent(cw.duration) + (fresh ? '&fresh=1' : ''));
      cw.html = cwRender(c);
    } catch (e) {
      cw.html = '<div class="sh-alert" style="background:#FFFBEB;color:#92400E;border:1px solid #FDE68A;">🟠<div>' + esc(e.message) + '</div></div>';
    }
    cw.at = Date.now(); cw.busy = false;
    var b2 = main.querySelector('#sh-cw');
    if (b2) { b2.innerHTML = cw.html; cwWire(main); }
  }

  /* SUBTABS, one per platform (Anthony, 2026-10-22: "subtab the two platforms cloudways
     and godaddy to make this cleaner"). Cloudways first and by default — it is where the
     site is going. Per CONVENTIONS.md the strip is drawn once per visit and a tab click
     re-renders only #sh-pane, never the screen (so the banner stays put). */
  var tab = 'cloudways', localLabel = '🖥️ GoDaddy · live site';
  try { tab = sessionStorage.getItem('kt_sh_tab') || 'cloudways'; } catch (e) {}

  function paintTabs(main) {
    var bar = main.querySelector('#sh-tabs');
    if (!bar) return;
    bar.setAttribute('role', 'tablist');   // the portal's standard tab band (kt-tab-band.js)
    bar.innerHTML = [['cloudways', '☁️ Cloudways · new server'], ['local', localLabel]].map(function (t) {
      var on = tab === t[0];
      return '<button type="button" role="tab" aria-selected="' + (on ? 'true' : 'false') + '" data-sh-tab="' + t[0] + '" data-kt-iconized="1" style="background:none;border:0;border-bottom:2px solid '
        + (on ? '#1F6FB2' : 'transparent') + ';padding:9px 13px;font-size:13.5px;font-weight:700;color:' + (on ? '#0F172A' : '#64748B')
        + ';cursor:pointer;border-radius:8px 8px 0 0;white-space:nowrap;">' + esc(t[1]) + '</button>';
    }).join('');
    bar.querySelectorAll('[data-sh-tab]').forEach(function (b) {
      b.onclick = function () {
        tab = b.getAttribute('data-sh-tab');
        try { sessionStorage.setItem('kt_sh_tab', tab); } catch (e) {}
        paintTabs(main);
        renderPane(main);
      };
    });
  }

  function renderPane(main) {
    if (timer) { clearTimeout(timer); timer = null; }
    var pane = main.querySelector('#sh-pane');
    if (!pane) return;
    if (tab === 'cloudways') {
      pane.innerHTML = '<div id="sh-cw">' + (cw.html || '<div class="sh-s">Reading Cloudways…</div>') + '</div>';
      cwWire(main);
      if (!cw.at || Date.now() - cw.at > 300000) cwLoad(main, false);
      timer = setTimeout(function tick() {
        if (!main.isConnected || tab !== 'cloudways') return;
        if (document.hidden) { timer = setTimeout(tick, 60000); return; }
        cwLoad(main, false); timer = setTimeout(tick, 300000);
      }, 300000);
      return;
    }
    renderLocal(main);
  }

  function render(main) {
    if (timer) { clearTimeout(timer); timer = null; }
    main.setAttribute('data-kt-no-autorefresh', '1');
    main.setAttribute('data-kt-self-live', '1');
    if (!document.getElementById('sh-css')) { var st = document.createElement('style'); st.id = 'sh-css'; st.textContent = CSS; document.head.appendChild(st); }
    /* The house section box (CONVENTIONS.md "A section's content box is .kt-section"):
       the same width and gutter as every other screen; no private max-width. */
    main.setAttribute('data-kt-pretty', '1');
    if (!main.querySelector('#sh-root')) {
      main.innerHTML = '<div id="sh-root" class="kt-section">'
        + '<div id="sh-tabs" class="kt-subtabs" style="display:flex;gap:6px;flex-wrap:wrap;border-bottom:1px solid #E2E8F0;margin:0 0 14px;padding:0 0 2px;"></div>'
        + '<div id="sh-pane"></div></div>';
    }
    paintTabs(main);
    renderPane(main);
  }

  /* The server this API runs on (GoDaddy until the move). */
  async function renderLocal(main) {
    var root = main.querySelector('#sh-pane');
    if (!root) return;
    if (!root.querySelector('.sh-grid')) root.innerHTML = '<div class="sh-s">Reading the server…</div>';
    var d;
    try { d = await Api.get('/platform/server-health?hours=' + hours); }
    catch (e) {
      if (!root.isConnected) return;
      root.innerHTML = (e && e.status === 403)
        ? '<div style="background:#FEF3C7;border:1px solid #FDE68A;color:#92400E;border-radius:12px;padding:18px;max-width:640px;">This page is available to <b>platform administrators</b> only.</div>'
        : '<div style="background:#FEF2F2;border:1px solid #FECACA;color:#991B1B;border-radius:12px;padding:18px;max-width:640px;">' + esc((e && e.message) || 'The server could not be read just now.') + '</div>';
      return;
    }
    if (!root.isConnected || tab !== 'local') return;

    var h = d.host || {}, cpu = d.cpu || {}, mem = d.memory || {}, disk = d.disk || {}, db = d.database || {}, q = d.queue || {}, sch = d.scheduler || {}, rq = d.requests || {}, bk = d.backups || {}, php = d.php || {};
    var alerts = d.alerts || [];
    var hist = d.history || [];
    var at = new Date(d.generated_at);

    var newLabel = h.shared ? '🖥️ GoDaddy · live site' : '🖥️ This server';
    if (newLabel !== localLabel) { localLabel = newLabel; paintTabs(main); }
    var html = '<div class="sh-bar"><div style="font-size:13px;color:#475569;line-height:1.5;">'
      + '<b style="color:#0F172A;">' + esc(h.provider || 'Server') + '</b> · ' + esc(h.name || '')
      + (h.cores ? ' · ' + h.cores + ' CPU cores' : '') + (mem.total && !h.shared ? ' · ' + bytes(mem.total) + ' memory' : '')
      + (h.uptime_seconds ? ' · up ' + dur(h.uptime_seconds) : '') + ' · PHP ' + esc(php.version || '')
      + '<br><span style="color:#94A3B8;">Updated ' + at.toLocaleTimeString([], { hour: 'numeric', minute: '2-digit', second: '2-digit' }) + ' · refreshes every 30 seconds</span></div>'
      + '<div style="display:flex;gap:8px;"><select id="sh-hours">'
      + [[6, 'Last 6 hours'], [24, 'Last 24 hours'], [72, 'Last 3 days'], [168, 'Last 7 days'], [336, 'Last 14 days']].map(function (o) { return '<option value="' + o[0] + '"' + (o[0] === hours ? ' selected' : '') + '>' + o[1] + '</option>'; }).join('')
      + '</select><button type="button" id="sh-refresh" data-kt-iconized="1">↻ Refresh</button></div></div>';

    if (h.shared) {
      html += '<div class="sh-alert" style="background:#EFF6FF;color:#1E3A8A;border:1px solid #BFDBFE;">ℹ️<div>This is <b>shared hosting</b>: processor, memory and load figures describe the whole shared machine (' + (h.cores || '?') + ' cores, many customers), not KiddieTrac\'s share of it. The database, background jobs, errors, certificates and backups are ours. On a dedicated server every figure is KiddieTrac\'s own.</div></div>';
    }
    html += alerts.length
      ? alerts.map(function (a) {
          var red = a.level === 'red';
          return '<div class="sh-alert" style="background:' + (red ? '#FEF2F2' : '#FFFBEB') + ';color:' + (red ? '#991B1B' : '#92400E') + ';border:1px solid ' + (red ? '#FECACA' : '#FDE68A') + ';">' + (red ? '🔴' : '🟠') + '<div>' + esc(a.text) + '</div></div>';
        }).join('')
      : '<div class="sh-alert" style="background:#F0FDF4;color:#166534;border:1px solid #BBF7D0;">✅<div>Everything looks healthy.</div></div>';

    var cpuC = tone(cpu.used_pct, 70, 90), memC = tone(h.shared ? null : mem.used_pct, 80, 90), diskC = tone(disk.used_pct, 80, 90);
    var dbC = !db.ok ? '#DC2626' : (db.ping_ms > 20 ? '#D97706' : '#16A34A');
    var qC = (q.oldest_seconds || 0) > 900 ? '#DC2626' : (q.oldest_seconds || 0) > 300 ? '#D97706' : '#16A34A';
    html += '<div class="sh-grid sh-4">'
      + card('Processor', cpu.used_pct == null ? '—' : num(cpu.used_pct, 1) + '%', 'Load ' + num(cpu.load_1, 2) + ' / ' + num(cpu.load_5, 2) + ' / ' + num(cpu.load_15, 2) + (cpu.load_per_core != null ? ' · ' + num(cpu.load_per_core, 2) + ' per core' : ''), cpu.used_pct, cpuC)
      + card('Memory', mem.used_pct == null ? '—' : num(mem.used_pct, 1) + '%', mem.total ? bytes(mem.used) + ' of ' + bytes(mem.total) + (mem.swap_used ? ' · swap ' + bytes(mem.swap_used) : '') : '', mem.used_pct, memC)
      + card('Disk', disk.used_pct == null ? '—' : num(disk.used_pct, 1) + '%', bytes(disk.free) + ' free of ' + bytes(disk.total) + '<br>Uploads ' + bytes(disk.uploads) + ' · backups ' + bytes(disk.backups) + ' · logs ' + bytes(disk.logs), disk.used_pct, diskC)
      + card('Database', db.ok ? num(db.ping_ms, 2) + ' ms' : 'Not answering', db.ok ? num(db.connections) + ' of ' + num(db.max_connections) + ' connections · ' + num(db.queries_per_second, 1) + ' queries/sec · ' + bytes(db.size) : '', undefined, dbC)
      + card('Background jobs', num(q.pending) + ' waiting', 'Oldest waited ' + dur(q.oldest_seconds) + ' · ' + num(q.failed_24h) + ' failed today' + (q.by_queue && Object.keys(q.by_queue).length ? '<br>' + Object.keys(q.by_queue).map(function (k) { return esc(k) + ' ' + q.by_queue[k]; }).join(' · ') : ''), undefined, qC)
      + card('Scheduled jobs', sch.running ? 'Running' : 'Not running', sch.seconds_since != null ? 'Last check-in ' + dur(sch.seconds_since) + ' ago' : 'No check-in recorded yet', undefined, sch.running ? '#16A34A' : '#DC2626')
      + card('Newest backup', bk.newest_hours == null ? 'None' : dur(bk.newest_hours * 3600) + ' ago', bk.count ? bytes(bk.newest_size) + (bk.encrypted ? ' · encrypted' : ' · NOT encrypted') + ' · ' + bk.count + ' kept' : 'No backup file on this server', undefined, bk.newest_hours == null || bk.newest_hours > 30 ? '#DC2626' : '#16A34A')
      + card('Last 24 hours', num(rq.server_errors) + ' errors', num(rq.slow) + ' slow requests · ' + num(rq.failed_loads) + ' never arrived · ' + num(rq.rate_limited) + ' rate-limited', undefined, (rq.server_errors || 0) >= 20 ? '#D97706' : '#0F172A')
      + '</div>';

    html += '<div class="sh-h">History</div><div class="sh-grid sh-3">'
      + spark(hist, 'cpu_pct', 'Processor', '%', '#1F6080', 100)
      + spark(hist, 'mem_pct', 'Memory', '%', '#7C3AED', 100)
      + spark(hist, 'db_ms', 'Database response', ' ms', '#0E7490')
      + spark(hist, 'queue_pending', 'Jobs waiting', '', '#D97706')
      + spark(hist, 'load_1', 'Load (1 min)', '', '#475569')
      + spark(hist, 'disk_pct', 'Disk', '%', '#16A34A', 100)
      + '</div>';

    /* CONNECTIONS (2026-10-29): how fast the portal was on the devices people used it from -
       kt-net-quality.js measures each session's API calls and reports once (App\Support\NetQuality). */
    var nq = d.network;
    if (nq) {
      var slowPct = nq.sessions ? Math.round(100 * nq.slow / nq.sessions) : null;
      var nqC = slowPct == null ? '#94A3B8' : slowPct >= 25 ? '#DC2626' : slowPct >= 10 ? '#D97706' : '#16A34A';
      var msTxt = function (v) { return v == null ? '—' : (v >= 1000 ? num(v / 1000, 1) + ' s' : num(v) + ' ms'); };
      html += '<div class="sh-h">Connections (devices, last ' + (nq.hours >= 48 ? Math.round(nq.hours / 24) + ' days' : nq.hours + ' hours') + ')</div><div class="sh-grid sh-4">'
        + card('Sessions measured', num(nq.sessions), 'One report per visit to the portal', undefined, '#0F172A')
        + card('On a slow connection', slowPct == null ? '—' : slowPct + '%', num(nq.slow) + ' of ' + num(nq.sessions) + ' sessions (typical wait over 2 s, or 2G/3G)', slowPct, nqC)
        + card('Typical wait per request', msTxt(nq.median_ms), 'Slowest tenth of sessions: ' + msTxt(nq.p90_ms), undefined, (nq.median_ms || 0) > 2000 ? '#DC2626' : (nq.median_ms || 0) > 800 ? '#D97706' : '#16A34A')
        + card('Requests that failed', num(nq.failures), 'Dropped or cut off on the device', undefined, (nq.failures || 0) > 20 ? '#D97706' : '#0F172A')
        + '</div>';
      if (nq.worst_agencies && nq.worst_agencies.length) {
        html += '<div class="kt-card" style="margin-top:10px;"><table><thead><tr><th>Agency</th><th style="text-align:right;">Sessions</th><th style="text-align:right;">Slow</th><th style="text-align:right;">Typical wait</th></tr></thead><tbody>'
          + nq.worst_agencies.map(function (a) { return '<tr><td style="font-weight:700;">' + esc(a.agency) + '</td><td style="text-align:right;">' + num(a.sessions) + '</td><td style="text-align:right;">' + num(a.slow) + '</td><td style="text-align:right;">' + msTxt(a.median_ms) + '</td></tr>'; }).join('')
          + '</tbody></table></div>';
      }
    }

    /* SEARCH WORDS (2026-11-09): the words on each screen that the global search finds, learned as people use the
       portal (App\Support\SearchKeywords). Red when no browser has reported a screen for 48 hours: learning stopped. */
    var sk = d.search_keys;
    if (sk) {
      var skRole = { agency_admin: 'Agency admin', centre_director: 'Centre director', educator: 'Educator', guardian: 'Parent', home_visitor: 'Home visitor', sales_rep: 'Sales', reseller_admin: 'Reseller', platform_admin: 'Platform', auditor: 'Auditor' };
      var skH = sk.hours_since_report, skC = skH == null || skH >= 48 ? '#DC2626' : skH >= 24 ? '#D97706' : '#16A34A';
      var wk = sk.week || {}, rej = (+wk.rej_rule || 0) + (+wk.rej_source || 0) + (+wk.rej_name || 0);
      html += '<div class="sh-h">Search words</div><div class="sh-grid sh-4">'
        + card('Words the search knows', num(sk.live_total), (sk.by_role || []).map(function (r) { return esc(skRole[r.role] || r.role) + ' ' + num(r.phrases); }).join(' · '), undefined, '#0F172A')
        + card('Last screen reported', skH == null ? 'Never' : skH < 1 ? 'Just now' : num(skH) + ' h ago', 'Each browser reports a screen once a day', undefined, skC)
        + card('Learned this week', num(sk.learned_7d), num(wk.reports || 0) + ' screen reports · ' + num(sk.retired_30d) + ' retired in 30 days', undefined, '#0F172A')
        + card('Turned down this week', num(rej), 'Not in the portal\'s code ' + num(wk.rej_source || 0) + ' · a real name ' + num(wk.rej_name || 0) + ' · too short, dates, numbers ' + num(wk.rej_rule || 0), undefined, '#0F172A')
        + '</div>';
      var skRows = (sk.new_screens_7d || []).map(function (r) { return [r, 'New screen, words learned']; })
        .concat((sk.screens_without_words || []).map(function (r) { return [r, 'Visited, no words yet']; }));
      if (skRows.length) {
        html += '<div class="kt-card" style="margin-top:10px;"><table><thead><tr><th>Screen</th><th>Who</th><th>This week</th></tr></thead><tbody>'
          + skRows.map(function (x) { return '<tr><td style="font-family:ui-monospace,monospace;font-size:12px;">#' + esc(x[0].hash) + '</td><td>' + esc(skRole[x[0].role] || x[0].role) + '</td><td>' + esc(x[1]) + '</td></tr>'; }).join('')
          + '</tbody></table></div>';
      }
    }

    if (rq.top && rq.top.length) {
      var KIND = { 'error.server': 'Server error', 'perf.slow_request': 'Slow', 'error.client_request': 'Never arrived' };
      html += '<div class="sh-h">Where problems happened (24 h)</div><div class="kt-card"><table><thead><tr><th>What</th><th>Screen / request</th><th style="text-align:right;">Times</th></tr></thead><tbody>'
        + rq.top.map(function (r) { return '<tr><td>' + esc(KIND[r.kind] || r.kind) + '</td><td style="font-family:ui-monospace,monospace;font-size:12px;word-break:break-all;">' + esc(r.path) + '</td><td style="text-align:right;font-weight:700;">' + num(r.count) + '</td></tr>'; }).join('')
        + '</tbody></table></div>';
    }

    /* Leak guard (2026-11-27): canary route probe (hourly slice + nightly full), data check, live sentinel. */
    var lg = d.leak_guard || {};
    if (!lg.error) {
      var lgRow = function (label, r, what) {
        if (!r) return '<tr><td style="font-weight:700;">' + esc(label) + '</td><td colspan="3" style="color:#94A3B8;">Not run yet</td></tr>';
        var bad = r.findings > 0, col = bad ? '#DC2626' : (r.blind ? '#D97706' : '#16A34A');
        return '<tr><td style="font-weight:700;">' + esc(label) + '</td><td>' + esc(new Date(String(r.at).replace(' ', 'T') + 'Z').toLocaleString()) + (r.mode ? ' · ' + esc(r.mode) : '') + '</td>'
          + '<td>' + num(r.routes) + ' ' + what + (r.calls ? ' · ' + num(r.calls) + ' calls' : '') + (r.secs != null ? ' · ' + num(r.secs) + ' s' : '') + '</td>'
          + '<td style="font-weight:800;color:' + col + ';">' + (bad ? r.findings + ' finding(s)' : (r.blind ? 'Blind: ' + r.blind + ' role(s)' : 'Clean')) + '</td></tr>';
      };
      var lgLines = [].concat((lg.probe && lg.probe.lines) || [], (lg.integrity && lg.integrity.lines) || [], lg.sentinel_lines || []);
      html += '<div class="sh-h">Leak guard</div><div class="kt-card"><table data-kt-no-filter><thead><tr><th>Check</th><th>Last run</th><th>Checked</th><th>Result</th></tr></thead><tbody>'
        + lgRow('Route probe (latest)', lg.probe, 'routes') + lgRow('Route probe (full, nightly)', lg.probe_full, 'routes') + lgRow('Data check', lg.integrity, 'checks')
        + '<tr><td style="font-weight:700;">Live sentinel</td><td>Every response (sampled)</td><td>Last 7 days</td><td style="font-weight:800;color:' + (lg.sentinel_7d ? '#DC2626' : '#16A34A') + ';">' + (lg.sentinel_7d ? lg.sentinel_7d + ' hit(s)' : 'Clean') + '</td></tr>'
        + '</tbody></table>'
        + (lgLines.length ? '<div class="sh-s" style="margin-top:8px;font-size:12px;color:#991B1B;line-height:1.6;">' + lgLines.slice(0, 20).map(esc).join('<br>') + '</div>' : '')
        + '</div>';
    }

    html += '<div class="sh-h">Certificates</div><div class="kt-card"><table><thead><tr><th>Address</th><th>Expires</th><th>Days left</th><th>Issuer</th></tr></thead><tbody>'
      + (d.certificates || []).map(function (c) {
          var col = c.days_left == null || c.matches === false ? '#DC2626' : c.days_left < 14 ? '#DC2626' : c.days_left < 30 ? '#D97706' : '#16A34A';
          return '<tr><td style="font-weight:700;">' + esc(c.host) + '</td><td>' + esc(c.expires || '—') + '</td><td style="font-weight:800;color:' + col + ';">' + (c.days_left == null ? 'Unreadable' : c.days_left + (c.matches === false ? ' (wrong name)' : '')) + '</td><td>' + esc(c.issuer || '—') + '</td></tr>';
        }).join('') + '</tbody></table></div>';

    html += '<div class="sh-h">Database</div><div class="sh-grid">'
      + '<div class="kt-card" style="grid-column:span 2;min-width:0;"><div class="sh-k" style="margin-bottom:6px;">Largest tables</div><table><thead><tr><th>Table</th><th style="text-align:right;">Rows</th><th style="text-align:right;">Size</th></tr></thead><tbody>'
      + (db.largest || []).map(function (t) { return '<tr><td style="font-family:ui-monospace,monospace;font-size:12px;">' + esc(t.table) + '</td><td style="text-align:right;">' + num(t.rows) + '</td><td style="text-align:right;">' + bytes(t.bytes) + '</td></tr>'; }).join('')
      + '</tbody></table></div>'
      + '<div class="sh-panel"><div class="sh-k">Details</div><div class="sh-s" style="font-size:13px;color:#334155;line-height:1.7;">'
      + esc(db.version || '') + '<br>' + num(db.tables) + ' tables · ' + bytes(db.size) + '<br>Busiest moment: ' + num(db.max_used_connections) + ' connections<br>Slow queries since start: ' + num(db.slow_queries) + '<br>Up ' + dur(db.uptime_seconds) + ' · time zone ' + esc(db.time_zone || '') + '</div></div>'
      + '<div class="sh-panel"><div class="sh-k">PHP</div><div class="sh-s" style="font-size:13px;color:#334155;line-height:1.7;">PHP ' + esc(php.version || '') + ' · Laravel ' + esc(php.laravel || '') + '<br>Memory limit ' + esc(php.memory_limit || '') + ' · uploads up to ' + esc(php.upload_max || '')
      + (php.opcache ? '<br>Code cache ' + (php.opcache.enabled ? 'on' : 'off') + (php.opcache.hit_rate != null ? ' · ' + num(php.opcache.hit_rate, 1) + '% hits' : '') + (php.opcache.memory_used_pct != null ? ' · ' + num(php.opcache.memory_used_pct, 0) + '% full' : '') : '')
      + '</div></div></div>';

    root.innerHTML = html;
    root.querySelector('#sh-refresh').onclick = function () { renderLocal(main); };
    root.querySelector('#sh-hours').onchange = function () { hours = parseInt(this.value, 10) || 24; renderLocal(main); };
    if (timer) clearTimeout(timer);
    timer = setTimeout(function tick() {
      if (!main.isConnected || !root.isConnected || tab !== 'local') return;
      if (document.hidden) { timer = setTimeout(tick, 30000); return; }   // no polling in a background tab
      renderLocal(main);
    }, 30000);
  }

  ['agency_admin', 'platform_admin'].forEach(function (r) { Shell.registerScreen(r + ':server-health', render); });
})(window);
