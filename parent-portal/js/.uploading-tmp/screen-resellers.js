/* ═══════════════════════════════════════════════════════════════════
   RESELLERS (2026-10-21)
   A reseller owns several agencies and sets one brand for all of them (logos, colours,
   app name, support email, boot screen, favicon, hide-KiddieTrac). Platform admins
   manage resellers under Reseller › Resellers; a reseller admin gets its own Branding
   and Agencies screens. The brand is resolved server-side by App\Support\Brand.
   ═══════════════════════════════════════════════════════════════════ */
(function (window) {
  'use strict';
  var Shell = window.KT && window.KT.Shell;
  var Api = window.KT && window.KT.Api;
  if (!Shell || !Shell.registerScreen || !Api) { return; }

  function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }
  function apiHost() { return ((window.KT && window.KT.API_BASE) || 'https://api.kiddietrac.com/api/v1').replace(/\/api\/v1\/?$/, ''); }
  function abs(u) { return !u ? '' : (/^\/storage\//.test(u) ? apiHost() + u : u); }
  function toast(msg, bad) { if (window.KT && KT.toast) { KT.toast(bad ? '⚠️' : '✅', bad ? 'Not saved' : 'Saved', msg, bad ? '#B91C1C' : '#15803D'); } }

  var KT_DEFAULT = {
    app_name: 'KiddieTrac', primary_color: '#1F6080', accent_color: '#13B7CC', splash_bg: '#E7F3FB',
    logo_square_url: 'https://app.kiddietrac.com/icon-512.png', logo_wordmark_url: 'https://app.kiddietrac.com/logo-wordmark@2x.png',
    splash_logo_url: 'https://app.kiddietrac.com/logo-wordmark@2x.png', favicon_url: 'https://app.kiddietrac.com/favicon-32.png',
    support_email: 'info@kiddietrac.com',
  };
  var CSS = '<style id="rs-css">'
    + '.rs-wrap{padding:22px;max-width:1500px;margin:0 auto}'
    + '.rs-grid{display:grid;grid-template-columns:minmax(0,1fr) 380px;gap:20px;align-items:start}'
    + '@media(max-width:1000px){.rs-grid{grid-template-columns:1fr}}'
    + '.rs-card{background:#fff;border:1px solid #E2E8F0;border-radius:14px;padding:18px}'
    + '.rs-card h3{margin:0 0 4px;font-size:16px}.rs-sub{color:#64748B;font-size:12.5px;margin:0 0 12px}'
    + '.rs-row{display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:12px;margin-bottom:12px}'
    + '.rs-lbl{display:block;font-size:12.5px;font-weight:700;color:#334155;margin-bottom:4px}'
    + '.rs-in{width:100%;height:34px;padding:0 10px;border:1px solid #CBD5E1;border-radius:8px;font:inherit;font-size:13.5px;box-sizing:border-box}'
    + '.rs-color{display:flex;gap:6px;align-items:center}.rs-color input[type=color]{width:40px;height:34px;border:1px solid #CBD5E1;border-radius:8px;padding:2px;background:#fff}'
    + '.rs-up{display:flex;gap:10px;align-items:center;border:1px dashed #CBD5E1;border-radius:10px;padding:8px}'
    + '.rs-up img{width:56px;height:56px;object-fit:contain;border-radius:8px;background:#F1F5F9}'
    + '.rs-up .rs-wide{width:120px}'
    + '.rs-tabs{display:inline-flex;border:1px solid #CBD5E1;border-radius:9px;overflow:hidden;margin:0 0 14px}'
    + '.rs-tabs button{border:0;padding:7px 14px;font:inherit;font-size:13px;font-weight:700;background:#fff;color:#475569;cursor:pointer}'
    + '.rs-tabs button.on{background:#1F6080;color:#fff}'
    + '.rs-phone{width:300px;margin:0 auto;border:10px solid #0F172A;border-radius:34px;overflow:hidden;background:#fff;box-shadow:0 18px 40px -20px rgba(15,23,42,.6)}'
    + '.rs-pv-label{font-size:11px;font-weight:800;letter-spacing:.5px;color:#94A3B8;text-transform:uppercase;margin:14px 0 6px;text-align:center}'
    + '</style>';

  /* ── Brand editor + live preview (shared by both audiences) ────────────────── */
  function brandEditor(host, rs, opts) {
    opts = opts || {};
    var b = Object.assign({}, rs.brand || {});
    var val = function (k) { return b[k] || ''; };
    var eff = function (k) { return b[k] || KT_DEFAULT[k]; };
    var up = function (kind, label, wide, note) {
      var k = kind + '_url';
      return '<div><span class="rs-lbl">' + esc(label) + '</span><div class="rs-up">'
        + '<img data-img="' + k + '" class="' + (wide ? 'rs-wide' : '') + '" alt="" src="' + esc(abs(eff(k))) + '">'
        + '<div style="flex:1;min-width:0;"><div style="font-size:11.5px;color:#64748B;margin-bottom:5px;">' + esc(note) + '</div>'
        + '<label class="kt-btn kt-btn-secondary kt-btn-sm" style="cursor:pointer;">Upload<input type="file" data-up="' + kind + '" accept="image/png,image/jpeg,image/webp,image/svg+xml,.ico" style="display:none;"></label>'
        + (b[k] ? ' <button type="button" class="kt-btn kt-btn-secondary kt-btn-sm" data-clear="' + k + '">Use default</button>' : '')
        + '</div></div></div>';
    };
    var color = function (k, label) {
      return '<div><span class="rs-lbl">' + esc(label) + '</span><div class="rs-color">'
        + '<input type="color" data-c="' + k + '" value="' + esc(eff(k)) + '">'
        + '<input class="rs-in" data-k="' + k + '" value="' + esc(val(k)) + '" placeholder="' + esc(KT_DEFAULT[k]) + '" maxlength="7"></div></div>';
    };
    host.innerHTML = '<div class="rs-grid"><div>'
      + '<div class="rs-card" style="margin-bottom:14px;"><h3>Name & contact</h3><p class="rs-sub">What people see instead of “KiddieTrac”.</p>'
      + '<div class="rs-row"><div><span class="rs-lbl">App & product name</span><input class="rs-in" data-k="app_name" maxlength="60" value="' + esc(val('app_name')) + '" placeholder="e.g. Little Steps"></div>'
      + '<div><span class="rs-lbl">Support email</span><input class="rs-in" data-k="support_email" type="email" value="' + esc(val('support_email')) + '" placeholder="help@yourcompany.com"></div></div></div>'
      + '<div class="rs-card" style="margin-bottom:14px;"><h3>Logos</h3><p class="rs-sub">PNG, SVG or WebP, up to 4 MB. A transparent background works best.</p>'
      + '<div class="rs-row">' + up('logo_square', 'Square logo (app icon, notifications)', false, 'At least 512×512.')
      + up('logo_wordmark', 'Wordmark (top bar, emails, sign-in)', true, 'Wide logo with the name, about 4:1.') + '</div>'
      + '<div class="rs-row">' + up('splash_logo', 'Boot screen logo', true, 'Shown while the app starts.')
      + up('favicon', 'Favicon (browser tab)', false, '32×32 or larger, square.') + '</div></div>'
      + '<div class="rs-card" style="margin-bottom:14px;"><h3>Colours</h3><p class="rs-sub">Buttons, highlights and the boot screen.</p>'
      + '<div class="rs-row">' + color('primary_color', 'Primary') + color('accent_color', 'Accent') + color('splash_bg', 'Boot screen background') + '</div></div>'
      + '<div class="rs-card" style="margin-bottom:14px;"><h3>Licence</h3>'
      + '<label style="display:flex;gap:8px;align-items:flex-start;font-size:13.5px;margin-bottom:10px;"><input type="checkbox" data-flag="hide_kiddietrac"' + (rs.hide_kiddietrac ? ' checked' : '') + (opts.platform ? '' : ' disabled') + ' style="margin-top:3px;">'
      + '<span><b>Hide KiddieTrac everywhere</b><br><span style="color:#64748B;font-size:12.5px;">Every agency under this reseller sees your name and logos only — screens, emails, texts, documents and notifications.'
      + (opts.platform ? '' : ' Set by KiddieTrac as part of your licence.') + '</span></span></label>'
      + '<label style="display:flex;gap:8px;align-items:flex-start;font-size:13.5px;"><input type="checkbox" data-flag="allow_agency_override"' + (rs.allow_agency_override ? ' checked' : '') + ' style="margin-top:3px;">'
      + '<span><b>Let my agencies use their own logo and colours</b><br><span style="color:#64748B;font-size:12.5px;">Otherwise every agency shows this brand.</span></span></label></div>'
      + '<div style="display:flex;gap:8px;justify-content:flex-end;"><span data-msg style="flex:1;font-size:13px;color:#64748B;align-self:center;"></span>'
      + '<button type="button" class="kt-btn kt-btn-primary" data-save>Save brand</button></div>'
      + '</div><div class="rs-card" style="position:sticky;top:12px;"><h3 style="text-align:center;">Live preview</h3><div data-preview></div></div></div>';

    var preview = host.querySelector('[data-preview]');
    function paintPreview() {
      var name = eff('app_name'), p = eff('primary_color'), a = eff('accent_color'), sb = eff('splash_bg');
      preview.innerHTML = ''
        + '<div class="rs-pv-label">Boot screen</div><div class="rs-phone"><div style="height:200px;background:' + esc(sb) + ';display:flex;flex-direction:column;align-items:center;justify-content:center;gap:12px;">'
        + '<img alt="" src="' + esc(abs(eff('splash_logo_url'))) + '" style="max-width:180px;max-height:70px;object-fit:contain;">'
        + '<div style="width:26px;height:26px;border-radius:50%;border:3px solid ' + esc(p) + '33;border-top-color:' + esc(p) + ';"></div></div></div>'
        + '<div class="rs-pv-label">In the app</div><div class="rs-phone"><div style="padding:10px 12px;border-bottom:1px solid #E2E8F0;display:flex;align-items:center;gap:8px;">'
        + '<img alt="" src="' + esc(abs(eff('logo_wordmark_url'))) + '" style="height:26px;max-width:150px;object-fit:contain;"></div>'
        + '<div style="padding:12px;"><div style="background:linear-gradient(135deg,' + esc(p) + ',' + esc(a) + ');color:#fff;border-radius:12px;padding:12px;font-weight:800;font-size:14px;">Good morning 👋</div>'
        + '<div style="display:flex;gap:6px;margin-top:10px;"><span style="background:' + esc(p) + ';color:#fff;border-radius:8px;padding:6px 10px;font-size:12px;font-weight:700;">Check in</span>'
        + '<span style="border:1.5px solid ' + esc(a) + ';color:' + esc(p) + ';border-radius:8px;padding:5px 10px;font-size:12px;font-weight:700;">Messages</span></div></div></div>'
        + '<div class="rs-pv-label">Notification</div><div style="width:300px;margin:0 auto;background:#F8FAFC;border:1px solid #E2E8F0;border-radius:14px;padding:10px;display:flex;gap:10px;align-items:center;">'
        + '<img alt="" src="' + esc(abs(eff('logo_square_url'))) + '" style="width:34px;height:34px;border-radius:8px;object-fit:contain;background:#fff;">'
        + '<div style="min-width:0;"><div style="font-size:11px;color:#64748B;">' + esc(name) + ' · now</div><div style="font-size:13px;font-weight:700;">Mila was signed in at 8:02</div></div></div>'
        + '<div class="rs-pv-label">Browser tab</div><div style="width:300px;margin:0 auto;display:flex;align-items:center;gap:6px;background:#E2E8F0;border-radius:8px 8px 0 0;padding:6px 10px;font-size:12px;">'
        + '<img alt="" src="' + esc(abs(eff('favicon_url'))) + '" style="width:16px;height:16px;object-fit:contain;">' + esc(name) + '</div>';
    }
    paintPreview();

    host.querySelectorAll('[data-k]').forEach(function (el) {
      el.addEventListener('input', function () {
        b[el.dataset.k] = el.value.trim();
        var c = host.querySelector('[data-c="' + el.dataset.k + '"]');
        if (c && /^#[0-9A-Fa-f]{6}$/.test(el.value.trim())) { c.value = el.value.trim(); }
        paintPreview();
      });
    });
    host.querySelectorAll('[data-c]').forEach(function (el) {
      el.addEventListener('input', function () {
        b[el.dataset.c] = el.value;
        host.querySelector('[data-k="' + el.dataset.c + '"]').value = el.value;
        paintPreview();
      });
    });
    host.querySelectorAll('[data-clear]').forEach(function (btn) {
      btn.onclick = function () { b[btn.dataset.clear] = ''; host.querySelector('[data-img="' + btn.dataset.clear + '"]').src = abs(KT_DEFAULT[btn.dataset.clear]); btn.remove(); paintPreview(); };
    });
    host.querySelectorAll('[data-up]').forEach(function (input) {
      input.onchange = async function () {
        var f = input.files && input.files[0];
        if (!f) { return; }
        var fd = new FormData();
        fd.append('kind', input.dataset.up);
        fd.append('file', f, f.name);
        if (opts.platform) { fd.append('reseller_id', rs.id); }
        try {
          var r = await Api.post('/reseller/brand/upload', fd);
          b[input.dataset.up + '_url'] = r.url;
          host.querySelector('[data-img="' + input.dataset.up + '_url"]').src = abs(r.url);
          paintPreview();
          toast('Logo uploaded.');
        } catch (e) { toast((e && e.message) || 'Could not upload that file.', true); }
        input.value = '';
      };
    });
    host.querySelector('[data-save]').onclick = async function () {
      var btn = this, msg = host.querySelector('[data-msg]');
      btn.disabled = true; msg.textContent = 'Saving…';
      var body = { brand: b, allow_agency_override: host.querySelector('[data-flag="allow_agency_override"]').checked };
      if (opts.platform) { body.reseller_id = rs.id; body.hide_kiddietrac = host.querySelector('[data-flag="hide_kiddietrac"]').checked; }
      try {
        var r = await Api.put('/reseller/brand' + (opts.platform ? '?reseller_id=' + rs.id : ''), body);
        msg.textContent = '✓ Saved. Agencies see the new brand the next time they open a page.';
        msg.style.color = '#15803D';
        if (opts.onSaved) { opts.onSaved(r.reseller); }
      } catch (e) { msg.textContent = (e && e.message) || 'Could not save.'; msg.style.color = '#B91C1C'; }
      btn.disabled = false;
    };
  }

  function agencyList(rs) {
    var list = rs.agencies || [];
    return list.length
      ? '<div style="display:grid;gap:6px;">' + list.map(function (a) { return '<div style="padding:9px 12px;border:1px solid #E2E8F0;border-radius:9px;font-weight:600;">🏢 ' + esc(a.name) + '</div>'; }).join('') + '</div>'
      : '<div style="color:#64748B;">No agencies yet. KiddieTrac assigns agencies to your account.</div>';
  }

  /* ── Reseller admin screens ────────────────────────────────────────────────── */
  /* One banner per screen, named like its nav item (2026-10-21). Both reseller screens
     used to share one banner titled with the reseller's name ("🎨 Demo Reseller"), so the
     Agencies page read as the Branding page. The name stays, in the subtitle. */
  function ownHero(tab, r) {
    var agencies = r ? (r.agencies || []).length : null;
    var who = r ? esc(r.name) + ' · ' : '';
    return tab === 'agencies'
      ? '<div class="kt-page-hero"><h2>🏢 Agencies</h2><p>' + who + 'The agencies that carry your brand'
        + (agencies === null ? '' : ' (' + agencies + ')') + '.</p></div>'
      : '<div class="kt-page-hero"><h2>🎨 Branding</h2><p>' + who + 'The logos, colours and name your agencies show.</p></div>';
  }

  async function renderOwn(main, tab) {
    main.innerHTML = CSS + '<div class="rs-wrap">' + ownHero(tab, null) + '<div class="rs-card" style="margin-top:14px;">Loading…</div></div>';
    var r;
    try { r = (await Api.get('/reseller/brand')).reseller; } catch (e) {
      main.innerHTML = CSS + '<div class="rs-wrap">' + ownHero(tab, null) + '<div class="rs-card" style="margin-top:14px;">' + esc((e && e.message) || 'Could not load your reseller.') + '</div></div>';
      return;
    }
    if (!main.isConnected) { return; }
    main.innerHTML = CSS + '<div class="rs-wrap">' + ownHero(tab, r) + '<div data-body style="margin-top:14px;"></div></div>';
    var body = main.querySelector('[data-body]');
    if (tab === 'agencies') { body.innerHTML = '<div class="rs-card"><h3>Your agencies</h3><p class="rs-sub">They show your brand.</p>' + agencyList(r) + '</div>'; return; }
    brandEditor(body, r, {});
  }

  /* ── The reseller's own app: settings, checklist, build kit (phase 5) ─────── */
  async function appPane(pane, r) {
    pane.innerHTML = '<div class="rs-card">Loading…</div>';
    let d;
    try { d = await Api.get('/platform/resellers/' + r.id + '/app'); } catch (e) {
      pane.innerHTML = '<div class="rs-card">' + esc((e && e.message) || 'Could not load.') + '</div>'; return;
    }
    const a = d.app || {}, k = d.kit || {};
    const st = { draft: 'Not started', kit_downloaded: 'Kit downloaded', built: 'Built', published: 'Published' };
    pane.innerHTML = '<div class="rs-grid"><div>'
      + '<div class="rs-card" style="margin-bottom:14px;"><h3>Their own app</h3><p class="rs-sub">A separate Android app with ' + esc(k.name) + '’s name and icon on the phone’s home screen. '
      + 'People can also use the standard app: it switches to this brand after they sign in.</p>'
      + '<div class="rs-row"><div><span class="rs-lbl">Package id</span><input class="rs-in" data-a="application_id" value="' + esc(a.application_id || '') + '" placeholder="com.' + esc(String(r.slug).replace(/[^a-z0-9]/g, '')) + '.app"></div>'
      + '<div><span class="rs-lbl">Status</span><select class="rs-in" data-a="status">' + Object.keys(st).map(function (x) { return '<option value="' + x + '"' + (a.status === x ? ' selected' : '') + '>' + st[x] + '</option>'; }).join('') + '</select></div></div>'
      + '<div class="rs-row"><div><span class="rs-lbl">Version number (optional)</span><input class="rs-in" data-a="version_code" type="number" min="1" value="' + esc(a.version_code || '') + '" placeholder="Same as the standard app"></div>'
      + '<div><span class="rs-lbl">Version name (optional)</span><input class="rs-in" data-a="version_name" value="' + esc(a.version_name || '') + '" placeholder="e.g. 1.0.0"></div></div>'
      + '<div class="rs-row"><div><span class="rs-lbl">Google Play listing</span><input class="rs-in" data-a="play_listing_url" value="' + esc(a.play_listing_url || '') + '" placeholder="https://play.google.com/store/apps/details?id=…"></div>'
      + '<div style="display:flex;align-items:flex-end;"><label style="display:flex;gap:8px;align-items:center;font-size:13.5px;"><input type="checkbox" data-a="firebase_ready"' + (a.firebase_ready ? ' checked' : '') + '> Firebase file is in place</label></div></div>'
      + '<div style="display:flex;gap:8px;justify-content:flex-end;flex-wrap:wrap;"><span data-am style="flex:1;font-size:13px;align-self:center;"></span>'
      + '<button type="button" class="kt-btn kt-btn-secondary" data-asave>Save</button>'
      + '<button type="button" class="kt-btn kt-btn-primary" data-kit>⬇ Download build kit</button></div></div>'
      + '<div class="rs-card"><h3>Checklist</h3><p class="rs-sub">What a branded app needs. The build itself is one command on the build computer.</p>'
      + (d.checklist || []).map(function (c) { return '<div style="display:flex;gap:9px;padding:6px 0;font-size:13.5px;border-bottom:1px solid #F1F5F9;"><span>' + (c.done ? '✅' : '⬜') + '</span><span>' + esc(c.text) + '</span></div>'; }).join('')
      + '</div></div>'
      + '<div class="rs-card"><h3>On the phone</h3><div style="display:flex;flex-direction:column;align-items:center;gap:8px;padding:10px 0;">'
      + '<img alt="" src="' + esc(abs(k.icon_url)) + '" style="width:72px;height:72px;border-radius:18px;object-fit:contain;background:#fff;box-shadow:0 6px 18px -8px rgba(15,23,42,.5);">'
      + '<div style="font-size:13px;font-weight:700;">' + esc(k.name) + '</div>'
      + '<div style="font-size:12px;color:#64748B;">Opens signed-in screens for <b>' + esc(k.tenant) + '</b></div></div>'
      + '<div style="font-size:12px;color:#64748B;">The icon is the Brand tab’s square logo; the boot screen uses its colours.</div></div></div>';
    const msg = pane.querySelector('[data-am]');
    const collect = function () {
      const out = {};
      pane.querySelectorAll('[data-a]').forEach(function (el) {
        const key = el.dataset.a;
        if (el.type === 'checkbox') { out[key] = el.checked; }
        else if (el.type === 'number') { out[key] = el.value ? +el.value : null; }
        else { out[key] = el.value.trim() || null; }
      });
      return out;
    };
    pane.querySelector('[data-asave]').onclick = async function () {
      try { await Api.put('/platform/resellers/' + r.id + '/app', collect()); toast('App settings saved.'); appPane(pane, r); }
      catch (e) { msg.textContent = (e && e.message) || 'Could not save.'; msg.style.color = '#B91C1C'; }
    };
    pane.querySelector('[data-kit]').onclick = async function () {
      try {
        await Api.put('/platform/resellers/' + r.id + '/app', collect());
        const kit = await Api.post('/platform/resellers/' + r.id + '/app/kit', {});
        const url = URL.createObjectURL(new Blob([JSON.stringify(kit, null, 2)], { type: 'application/json' }));
        const link = document.createElement('a'); link.href = url; link.download = 'reseller-' + r.slug + '.json';
        document.body.appendChild(link); link.click(); link.remove();
        setTimeout(function () { URL.revokeObjectURL(url); }, 4000);
        toast('Build kit downloaded. Run: node tools/build-from-kit.mjs reseller-' + r.slug + '.json');
        appPane(pane, r);
      } catch (e) { msg.textContent = (e && e.message) || 'Could not make the kit.'; msg.style.color = '#B91C1C'; }
    };
  }

  /* ── Platform admin: Resellers ─────────────────────────────────────────────── */
  async function renderPlatform(main) {
    main.innerHTML = CSS + '<div class="rs-wrap">Loading…</div>';
    var data;
    try { data = await Api.get('/platform/resellers'); } catch (e) {
      main.innerHTML = CSS + '<div class="rs-wrap"><div class="rs-card">' + esc((e && e.message) || 'Could not load resellers.') + '</div></div>';
      return;
    }
    if (!main.isConnected) { return; }
    var sel = null;
    try { sel = +sessionStorage.getItem('kt_rs_sel') || null; } catch (e) {}
    var list = data.resellers || [];
    if (!list.some(function (r) { return r.id === sel; })) { sel = list.length ? list[0].id : null; }
    var tab = 'brand';
    main.innerHTML = CSS + '<div class="rs-wrap"><div class="kt-page-hero"><h2>🤝 Resellers</h2><p>Partners who sell under their own brand. Each owns its agencies and sets one brand for them.</p>'
      + '<div class="kt-hero-actions"><button class="kt-btn kt-btn-ghost" data-new>+ New reseller</button></div></div>'
      + '<div style="display:grid;grid-template-columns:240px minmax(0,1fr);gap:16px;margin-top:14px;" class="rs-split">'
      + '<div class="rs-card" style="padding:10px;" data-list></div><div data-detail></div></div></div>'
      + '<style>@media(max-width:900px){.rs-split{grid-template-columns:1fr !important}}</style>';
    var listEl = main.querySelector('[data-list]'), det = main.querySelector('[data-detail]');
    function paintList() {
      listEl.innerHTML = list.length ? list.map(function (r) {
        return '<button type="button" data-id="' + r.id + '" style="display:block;width:100%;text-align:left;border:0;border-radius:9px;padding:9px 10px;margin-bottom:4px;cursor:pointer;font:inherit;background:' + (r.id === sel ? '#E0F2FE' : 'transparent') + ';">'
          + '<div style="font-weight:800;">' + esc(r.name) + '</div><div style="font-size:12px;color:#64748B;">' + (r.agencies || []).length + ' agencies · ' + esc(r.status) + '</div></button>';
      }).join('') : '<div style="padding:10px;color:#64748B;font-size:13px;">No resellers yet.</div>';
      listEl.querySelectorAll('[data-id]').forEach(function (b) { b.onclick = function () { sel = +b.dataset.id; try { sessionStorage.setItem('kt_rs_sel', sel); } catch (e) {} paintList(); paintDetail(); }; });
    }
    function cur() { return list.filter(function (r) { return r.id === sel; })[0]; }
    function replace(r) { list = list.map(function (x) { return x.id === r.id ? r : x; }); paintList(); paintDetail(); }
    function paintDetail() {
      var r = cur();
      if (!r) { det.innerHTML = '<div class="rs-card" style="color:#64748B;">Create a reseller to start.</div>'; return; }
      det.innerHTML = '<div class="rs-tabs">' + [['brand', 'Brand'], ['agencies', 'Agencies'], ['admins', 'People'], ['app', 'App']].map(function (t) {
        return '<button type="button" data-t="' + t[0] + '" class="' + (t[0] === tab ? 'on' : '') + '">' + t[1] + '</button>'; }).join('') + '</div>'
        + '<span style="margin-left:10px;font-size:12.5px;color:#64748B;">App build code: <b>' + esc(r.slug) + '</b></span><div data-pane></div>';
      det.querySelectorAll('[data-t]').forEach(function (b) { b.onclick = function () { tab = b.dataset.t; paintDetail(); }; });
      var pane = det.querySelector('[data-pane]');
      if (tab === 'brand') { brandEditor(pane, r, { platform: true, onSaved: function (n) { n.admins = r.admins; replace(n); } }); return; }
      if (tab === 'app') { appPane(pane, r); return; }
      if (tab === 'agencies') {
        var all = data.all_agencies || [];
        pane.innerHTML = '<div class="rs-card"><h3>Agencies</h3><p class="rs-sub">Ticked agencies belong to ' + esc(r.name) + ' and show its brand. An agency can belong to one reseller.</p>'
          + '<div style="display:grid;gap:4px;max-height:460px;overflow:auto;">' + all.map(function (a) {
            var mine = +a.reseller_id === r.id, other = a.reseller_id && !mine;
            var owner = other ? (list.filter(function (x) { return x.id === +a.reseller_id; })[0] || {}).name : '';
            return '<label style="display:flex;gap:8px;align-items:center;padding:7px 9px;border-radius:8px;' + (other ? 'opacity:.55;' : '') + '"><input type="checkbox" value="' + a.id + '"' + (mine ? ' checked' : '') + (other ? ' disabled' : '') + '>'
              + '<span style="font-weight:600;">' + esc(a.name) + '</span>' + (other ? '<span style="font-size:12px;color:#64748B;">— with ' + esc(owner) + '</span>' : '') + '</label>';
          }).join('') + '</div><div style="text-align:right;margin-top:10px;"><button class="kt-btn kt-btn-primary" type="button" data-sa>Save agencies</button></div></div>';
        pane.querySelector('[data-sa]').onclick = async function () {
          var ids = Array.prototype.map.call(pane.querySelectorAll('input[type=checkbox]:checked:not(:disabled)'), function (c) { return +c.value; });
          try {
            var res = await Api.put('/platform/resellers/' + r.id + '/agencies', { agency_ids: ids });
            all.forEach(function (a) { if (ids.indexOf(a.id) !== -1) { a.reseller_id = r.id; } else if (+a.reseller_id === r.id) { a.reseller_id = null; } });
            replace(res.reseller); toast('Agencies updated.');
          } catch (e) { toast((e && e.message) || 'Could not save.', true); }
        };
        return;
      }
      pane.innerHTML = '<div class="rs-card"><h3>People who manage ' + esc(r.name) + '</h3><p class="rs-sub">They can change this brand and see the names of its agencies — nothing inside the agencies.</p>'
        + ((r.admins || []).map(function (u) { return '<div style="display:flex;align-items:center;gap:10px;padding:8px 0;border-bottom:1px solid #F1F5F9;"><div style="flex:1;"><b>' + esc((u.first_name || '') + ' ' + (u.last_name || '')) + '</b> <span style="color:#64748B;font-size:12.5px;">' + esc(u.email) + '</span></div>'
          + '<button type="button" class="kt-btn kt-btn-secondary kt-btn-sm" data-rm="' + u.id + '">Remove</button></div>'; }).join('') || '<div style="color:#64748B;margin-bottom:8px;">Nobody yet.</div>')
        + '<div style="display:flex;gap:8px;margin-top:12px;"><input class="rs-in" data-em type="email" placeholder="Email of an existing account"><button class="kt-btn kt-btn-primary" type="button" data-add>Add</button></div></div>';
      pane.querySelector('[data-add]').onclick = async function () {
        var em = pane.querySelector('[data-em]').value.trim();
        if (!em) { return; }
        try { replace((await Api.post('/platform/resellers/' + r.id + '/admins', { email: em })).reseller); toast('Added.'); }
        catch (e) { toast((e && e.message) || 'Could not add.', true); }
      };
      pane.querySelectorAll('[data-rm]').forEach(function (b) {
        b.onclick = async function () {
          try { replace((await Api.delete('/platform/resellers/' + r.id + '/admins/' + b.dataset.rm)).reseller); toast('Removed.'); }
          catch (e) { toast((e && e.message) || 'Could not remove.', true); }
        };
      });
    }
    main.querySelector('[data-new]').onclick = async function () {
      var name = window.KT && KT.prompt ? await KT.prompt({ title: 'New reseller', label: 'Company name', okLabel: 'Create' }) : window.prompt('Reseller name');
      if (!name) { return; }
      try { var res = await Api.post('/platform/resellers', { name: name }); res.reseller.admins = []; list.push(res.reseller); sel = res.reseller.id; tab = 'brand'; paintList(); paintDetail(); }
      catch (e) { toast((e && e.message) || 'Could not create.', true); }
    };
    paintList(); paintDetail();
  }

  Shell.registerScreen('reseller_admin:home', function (main) { return renderOwn(main, 'brand'); });
  Shell.registerScreen('reseller_admin:reseller-brand', function (main) { return renderOwn(main, 'brand'); });
  Shell.registerScreen('reseller_admin:reseller-agencies', function (main) { return renderOwn(main, 'agencies'); });
  ['agency_admin', 'platform_admin'].forEach(function (role) { Shell.registerScreen(role + ':resellers', renderPlatform); });
})(window);
