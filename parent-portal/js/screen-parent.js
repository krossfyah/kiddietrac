/* ═══════════════════════════════════════════════════════════════════
   KIDDIETRAC v4 — Parent Screen Module
   Real photos gallery · Messages thread · Billing widget · AI digest
   ═══════════════════════════════════════════════════════════════════ */

(function (window) {
  'use strict';

  const { Api, Fmt, Dom, Shell } = window.KT;

  // Open a specific invoice by id from anywhere in the app (e.g. the account
  // ledger's clickable rows). Finds it across the family's children and reuses
  // the same rich invoice detail sheet the Billing screen shows.
  /* STAFF ARE NOT GUARDIANS, AND THIS ONLY SPOKE GUARDIAN (2026-09-17).

     Every "View invoice" button in the portal comes here — the payment-schedules table,
     the billing screen, the account ledger. It walked `/parent/children` and then
     `/parent/children/{id}/invoices`, which resolve the caller's families through the
     `guardians` table. An admin or a director is nobody's guardian, so the first call
     answered "Forbidden. Required role: guardian", the catch fired, and they got
     "Could not open the invoice". Reported by Anthony on the Sanford-Saganek family,
     whose invoices are the first native ones in that agency.

     This is the same mistake that was found and fixed one function along, for the
     EXTERNAL invoice button, whose comment says it outright: "This always called the
     /parent/ one ... a guaranteed 403 for an admin or director." The native twin was
     left behind, so the bug survived in the path nobody had clicked yet.

     Staff get the staff route — `/director/invoices/{id}`, guarded by
     authorizeCentreAccess, which is the right question for them — and fall through to
     the parent path if it refuses. That fall-through matters for the people who are
     both: a director whose own child attends a centre they do not administer. */
  function ktIsStaff() {
    try {
      var u = JSON.parse(sessionStorage.getItem('kt_user') || localStorage.getItem('kt_user') || '{}');
      return Array.isArray(u.roles)
        && u.roles.some(function (r) {
          return ['agency_admin', 'centre_director', 'platform_admin'].indexOf(r) !== -1;
        });
    } catch (e) { return false; }
  }

  window.KT.openInvoiceById = async function (invoiceId) {
    invoiceId = parseInt(invoiceId, 10); if (!invoiceId) return;
    try {
      if (ktIsStaff()) {
        try {
          var sr = await Api.get('/director/invoices/' + invoiceId);
          if (sr && sr.invoice) {
            /* No child argument: this is the family's invoice, opened by somebody who
               is not in that family. The sheet omits the "child · issued" caption
               rather than inventing a child to name. */
            openInvoiceDetail(sr.invoice, null);
            return;
          }
        } catch (staffErr) {
          // Not their centre, or not staff after all — try the guardian path below.
        }
      }
      var kids = (state && state.children && state.children.length)
        ? state.children
        : ((await Api.get('/parent/children')).children || []);
      for (var i = 0; i < kids.length; i++) {
        var r = await Api.get('/parent/children/' + kids[i].id + '/invoices').catch(function () { return null; });
        var list = (r && r.invoices) || [];
        var hit = list.find(function (v) { return String(v.id) === String(invoiceId); });
        if (hit) { openInvoiceDetail(hit, kids[i]); return; }
      }
      if (window.KT && KT.toast) KT.toast('\u2139\uFE0F', 'Invoice unavailable', 'Open Billing to view it.', '#2C7BA0');
    } catch (e) {
      if (window.KT && KT.toast) KT.toast('\u26A0\uFE0F', 'Could not open the invoice', (e && e.message) || '', '#B91C1C');
    }
  };
  const { emptyState } = Shell;

  // The AGENCY's date, never the device's UTC date. toISOString() is UTC, so from
  // 8 PM Eastern onward `new Date().toISOString()` already reads tomorrow — the
  // parent's Today screen went blank every evening ("No events logged yet today")
  // while the day's events were still filed under the agency-local date.
  // KT.agencyToday() (kt-tz.js) resolves the agency timezone from /auth/me.
  const localToday = () => (window.KT && KT.agencyToday)
    ? KT.agencyToday()
    : ymd(new Date());
  // Format a Date as YYYY-MM-DD from its LOCAL parts. Never use toISOString() for
  // this: it converts to UTC first, which lands on the previous day for any timezone
  // ahead of UTC.
  function ymd(d) {
    const p = (n) => String(n).padStart(2, '0');
    return d.getFullYear() + '-' + p(d.getMonth() + 1) + '-' + p(d.getDate());
  }

  /* Build a Date for a YYYY-MM-DD day from its NUMERIC PARTS. Never parse the string.
     The line above used to claim `new Date('2026-08-11T00:00:00')` is local midnight.
     It is not — it is parsed as UTC, so in Toronto it lands at 8pm on the 10th, and
     screen-admin.js and screen-dashboard-widgets.js both carry comments saying so.
     That single wrong assumption produced BOTH day-navigator bugs, because the parse
     was UTC while getDate()/ymd() around it were local:
       back  — new Date('2026-09-08T00:00:00') is Sep 7 locally, minus 1 = Sep 6,
               so one press moved TWO days ("it skips days");
       fwd   — from Sep 6 the same parse gives Sep 5, plus 1 = Sep 6, the date it
               started on, so the screen re-rendered unchanged and forward looked dead
               ("it won't let me move forward once I'm in a past date").
     Numeric parts have no parsing rules to get wrong. (Anthony, 2026-09-08) */
  function parseYmd(s) {
    const p = String(s || '').split('-');
    if (p.length !== 3) return new Date();
    return new Date(+p[0], +p[1] - 1, +p[2]);   // local midnight, always
  }

  let state = {
    children: [],
    selectedChildId: null,
    date: localToday(),
  };

  // ≤768, NOT ≤600: the Android APK WebView reports an inflated ~601–768 width, so
  // at ≤600 the parent rendered the DESKTOP layout on a real phone (uncondensed
  // cards; didn't match ≤600 desktop-emulation). 768 matches the app's mobile CSS
  // breakpoint + the bottom bar. Everything mobile in this app is ≤768.
  const isMobile = () => window.matchMedia('(max-width: 768px)').matches;

  // ── Instant navigation: cache-first GET with background revalidate ──────
  // First visit to a section fetches (brief load); every later visit renders
  // from cache immediately (no "Loading…" flash) while a silent refresh keeps
  // the cache warm for next time. prefetch() warms the sibling sections the
  // moment Today loads, so even first taps into Photos/Messages/Billing are instant.
  const apiCache = {};
  function cget(path) {
    if (Object.prototype.hasOwnProperty.call(apiCache, path)) {
      Api.get(path).then(function (d) { apiCache[path] = d; }).catch(function () {});
      return Promise.resolve(apiCache[path]);
    }
    return Api.get(path).then(function (d) { apiCache[path] = d; return d; });
  }
  function prefetch(paths) {
    paths.forEach(function (p) {
      if (!Object.prototype.hasOwnProperty.call(apiCache, p)) {
        Api.get(p).then(function (d) { apiCache[p] = d; }).catch(function () {});
      }
    });
  }
  function bust(path) { delete apiCache[path]; }

  /* ✨ THIS WEEK (2026-10-28, the AI Bundle: "weekly child recap for parents"). An extra under the day's story -
     nothing about the card changes. Shown only when the agency has the bundle (/auth/me ai_bundle); the recap is
     written on the first tap (not on every visit) and kept 6 hours on the server, so it costs one AI call. */
  function weekRecapButton(card, child) {
    var u = {};
    try { u = JSON.parse(sessionStorage.getItem('kt_user') || localStorage.getItem('kt_user') || '{}') || {}; } catch (e) {}
    if (!u.ai_bundle || !child || !card) return;
    var wk = Dom.el('div', { style: 'margin-top:12px;' });
    var btn = Dom.el('button', { type: 'button', 'data-kt-iconized': '1', 'data-kt-btn-free': '1',
      style: 'background:rgba(255,255,255,.16);color:inherit;border:1px solid rgba(255,255,255,.4);border-radius:999px;padding:6px 14px;font-size:12.5px;font-weight:700;cursor:pointer;' }, '✨ This week');
    var out = Dom.el('div', { style: 'display:none;margin-top:10px;padding-top:10px;border-top:1px solid rgba(255,255,255,.25);white-space:pre-wrap;font-size:14px;line-height:1.55;' });
    var loaded = false;
    btn.addEventListener('click', function () {
      if (loaded && out.style.display === 'block') { out.style.display = 'none'; btn.textContent = '✨ This week'; return; }
      out.style.display = 'block';
      btn.textContent = 'Hide this week';
      if (loaded) return;
      btn.disabled = true;
      out.textContent = 'Putting ' + (child.display_name || 'the') + '’s week together…';
      Api.get('/ai/weekly-recap/' + child.id)
        .then(function (r) { out.textContent = (r && r.recap) || 'There is not enough from this week for a recap yet.'; loaded = true; })
        .catch(function (e) { out.textContent = (e && e.message) || 'The weekly recap is not available right now.'; })
        .then(function () { btn.disabled = false; });
    });
    wk.appendChild(btn);
    wk.appendChild(out);
    card.appendChild(wk);
  }

  // Mark a notification category read so the bottom-nav badge clears (fire-and-forget).
  /* Called from the render path, so it must be safe to call on EVERY render.
     It was not: each render POSTed, and a POST is a data change, so the screen
     re-rendered, which POSTed again. kt-live's IGNORE list is fixed too, but the
     real rule is the one that file states in its own comment — a screen must not
     write while it renders. Throttled per category so a re-render costs nothing,
     while a genuinely new notification arriving later still clears the badge.
     (Anthony, 2026-09-08) */
  var _readAt = {};
  var READ_THROTTLE_MS = 60000;
  function markCategoryRead(category) {
    var now = Date.now();
    if (_readAt[category] && (now - _readAt[category]) < READ_THROTTLE_MS) return;
    _readAt[category] = now;
    try { if (window.KT && KT.Api && KT.Api.post) KT.Api.post('/notifications/mark-read', { category: category }).catch(function () {}); } catch (e) {}
  }

  // Absolute URL for a stored file path (/storage/…) served off the API host.
  function absUrl(u) {
    if (!u) return u;
    if (/^https?:\/\//.test(u)) return u;
    var base = (window.KT_CONFIG && window.KT_CONFIG.apiBase) || 'https://api.kiddietrac.com/api/v1';
    var origin = base.replace(/\/api\/v1\/?$/, '');
    return origin + (u.charAt(0) === '/' ? u : '/' + u);
  }

  // Send a parent message with an optional image — multipart (KT.Api is JSON-only).
  function sendParentMessage(childId, body, file, emergency, to) {
    var t; try { t = sessionStorage.getItem('kt_token') || localStorage.getItem('kt_token'); } catch (e) {}
    var base = (window.KT_CONFIG && window.KT_CONFIG.apiBase) || 'https://api.kiddietrac.com/api/v1';
    var fd = new FormData();
    fd.append('child_id', childId);
    if (body) fd.append('body', body);
    if (file) fd.append('attachment', file);
    if (emergency) fd.append('emergency', '1');   // App\Support\EmergencyMessage
    if (to === 'office') fd.append('to', 'office');   // the centre office thread (App\Support\MessagingRules::OFFICE)
    var h = { 'Authorization': 'Bearer ' + t, 'Accept': 'application/json' };
    try { var aid = sessionStorage.getItem('kt_active_agency_id'); if (aid) h['X-Active-Agency-Id'] = aid; var va = sessionStorage.getItem('kt_view_as'); if (va) h['X-View-As-Role'] = va; } catch (e) {}
    return fetch(base + '/parent/messages', { method: 'POST', headers: h, body: fd }).then(function (r) {
      if (!r.ok) return r.json().catch(function () { return {}; }).then(function (j) { throw new Error(j.message || ('Error ' + r.status)); });
      return r.json();
    });
  }

  async function renderParent(main, ctx) {
    Dom.clear(main);

    // Load the parent's children once (needed by every tab / both layouts).
    if (state.children.length === 0) {
      try {
        const data = await Api.get('/parent/children');
        state.children = data.children || [];
        if (state.children.length > 0) state.selectedChildId = state.children[0].id;
      } catch (e) {
        main.appendChild(emptyState('⚠️', 'Could not load', e.message));
        return;
      }
    }

    if (state.children.length === 0) {
      main.appendChild(emptyState(
        '👋', 'Welcome to Kiddietrac',
        'Your childcare centre will link your account to your child once enrolled. Check back soon.'
      ));
      return;
    }

    // Guard against a stale selected id (e.g. child removed).
    if (!state.children.find(c => c.id === state.selectedChildId)) {
      state.selectedChildId = state.children[0].id;
    }

    // Phones get a purpose-built, single-column, native-app layout.
    if (isMobile()) { await renderParentMobile(main); return; }

    // ── Desktop (unchanged) ──
    const wrap = Dom.el('div', { style: 'max-width: 1800px; margin: 0 auto; padding: 24px;' });
    main.appendChild(wrap);
    wrap.appendChild(buildChildTabs());

    const hash = (window.location.hash || '#today').replace('#', '').split('?')[0];
    if (hash === 'photos') await renderPhotosTab(wrap);
    else if (hash === 'messages') {
      // Desktop parents get the same rich chat as mobile (emoji picker, photo, voice
      // notes). The Today/Photos/Billing sub-nav is gone here — it duplicated the
      // shell's own navigation and looked out of place — and the inbox now fills the
      // whole window (matching the educator/home-visitor Messages), while opening a
      // conversation still pops out the full-screen thread.
      const _mcol = Dom.el('div');
      wrap.appendChild(_mcol);
      await renderMessagesMobile(_mcol);
    }
    else if (hash === 'billing') await renderBillingTab(wrap);
    else await renderTodayTab(wrap);
  }

  function buildChildTabs() {
    if (state.children.length === 1) return Dom.el('div');

    const tabs = Dom.el('div', { style: 'display: flex; gap: 8px; margin-bottom: 24px; border-bottom: 1px solid var(--ink-200);' });

    state.children.forEach(c => {
      const active = c.id === state.selectedChildId;
      const tab = Dom.el('button', {
        class: 'child-tab' + (active ? ' active' : ''),
        style: `padding: 12px 20px; background: none; border: none; border-bottom: 3px solid ${active ? 'var(--brand-green)' : 'transparent'}; font-weight: ${active ? '700' : '500'}; color: ${active ? 'var(--brand-blue)' : 'var(--ink-600)'}; cursor: pointer; font-size: 15px;`,
      }, [
        (function () { var a = Dom.el('span', { class: 'child-avatar', style: 'margin-right:8px;display:inline-flex;flex-shrink:0;' }); if (window.KT && KT.avatar) { a.innerHTML = KT.avatar(c.display_name, { size: 28, photoUrl: c.photo_url ? absUrl(c.photo_url) : null }); } else { a.textContent = c.display_name && c.display_name[0] ? c.display_name[0].toUpperCase() : 'C'; a.style.cssText += 'background:var(--brand-green);color:#fff;width:28px;height:28px;border-radius:50%;align-items:center;justify-content:center;font-size:12px;font-weight:700;'; } return a; })(),
        c.display_name,
      ]);
      tab.addEventListener('click', () => {
        state.selectedChildId = c.id;
        Shell.renderScreen();
      });
      tabs.appendChild(tab);
    });

    return tabs;
  }

  // Browse the child's day across dates — history back through past days (never the future).
  function buildDateNav() {
    const todayStr = localToday();
    const isToday = state.date === todayStr;
    const dObj = parseYmd(state.date);
    const label = dObj.toLocaleDateString(undefined, { weekday: 'long', month: 'long', day: 'numeric' });
    const nav = Dom.el('div', { style: 'display:flex; align-items:center; gap:10px; margin:0 0 18px; flex-wrap:wrap;' });
    const rerender = () => { setTimeout(() => { try { Shell.renderScreen(); } catch (e) {} }, 0); };
    const shift = (days, ev) => {
      if (ev) { ev.preventDefault(); ev.stopPropagation(); }
      const d = parseYmd(state.date); d.setDate(d.getDate() + days);
      const ns = ymd(d);
      if (ns > todayStr) return;               // never go past today
      state.date = ns; rerender();
    };
    const prev = Dom.el('button', { type: 'button', title: 'Previous day', style: 'background:#fff;border:1px solid #cbd5e1;border-radius:9px;width:34px;height:34px;font-size:13px;line-height:1;cursor:pointer;color:#1F6080;flex-shrink:0;' }, '◀');
    prev.addEventListener('click', (e) => shift(-1, e));
    const next = Dom.el('button', { type: 'button', title: 'Next day', style: `background:#fff;border:1px solid #cbd5e1;border-radius:9px;width:34px;height:34px;font-size:13px;line-height:1;color:${isToday ? '#cbd5e1' : '#1F6080'};cursor:${isToday ? 'default' : 'pointer'};flex-shrink:0;` }, '▶');
    if (!isToday) next.addEventListener('click', (e) => shift(1, e));
    nav.appendChild(prev);
    nav.appendChild(Dom.el('div', { style: 'font-weight:700; font-size:15px; color:#0F172A;' }, isToday ? ('Today · ' + label) : label));
    nav.appendChild(next);
    if (!isToday) {
      const t = Dom.el('button', { type: 'button', style: 'background:#EEF2FF;border:0;border-radius:9px;padding:7px 14px;font-size:13px;font-weight:700;color:#4338CA;cursor:pointer;' }, 'Back to today');
      /* stopPropagation here was never enough: the shell's listener CAPTURES, so it
         ran first and cancelled this one. The attribute is what actually keeps it. */
      t.dataset.ktInpage = '1';
      t.addEventListener('click', (e) => { e.preventDefault(); e.stopPropagation(); state.date = todayStr; rerender(); });
      nav.appendChild(t);
    }
    return nav;
  }

  function buildSubNav(active) {
    const nav = Dom.el('div', { style: 'display: flex; gap: 4px; margin-bottom: 24px; background: var(--ink-50); padding: 4px; border-radius: 12px; max-width: 480px;' });
    const tabs = [
      { id: 'today',    label: 'Today' },
      { id: 'photos',   label: 'Photos' },
      { id: 'messages', label: 'Message Centre' },
      { id: 'billing',  label: 'Billing' },
    ];
    tabs.forEach(t => {
      const isActive = t.id === active;
      const btn = Dom.el('a', {
        href: '#' + t.id,
        style: `flex: 1; text-align: center; padding: 8px 12px; border-radius: 8px; text-decoration: none; font-size: 14px; font-weight: 600; ${isActive ? 'background: white; color: var(--brand-blue); box-shadow: 0 1px 3px rgba(0,0,0,0.08);' : 'color: var(--ink-600);'}`,
      }, t.label);
      nav.appendChild(btn);
    });
    return nav;
  }

  // ─── TODAY TAB ─────────────────────────────────────────────────

  // Tap a photo to see it full-size, with a Download button. Registered as an overlay so
  // the ← Back / Android back closes it first.
  /* With `list` (the gallery the photo came from) and `index`, ‹ › and swipe step
     through every photo and video in it (2026-10-01). */
  function galleryItems(photos) {
    return (photos || []).map(function (p) {
      return { url: p.url, type: p.media_type === 'video' ? 'video' : 'image', caption: p.caption || '',
               meta: p.date_display || '', poster: p.thumbnail_url || undefined };
    });
  }
  function openPhotoLightbox(url, caption, list, index) {
    if (!url) return;
    if (window.KT && KT.mediaViewer) {
      KT.mediaViewer.open(list && list.length ? list : [{ url: url, caption: caption }], list ? index : 0, {
        download: function (it, b) { downloadPhoto(it.url, it.caption, b); },
      });
      return;
    }
    var ov = document.createElement('div');
    ov.className = 'kt-photo-lightbox';
    ov.style.cssText = 'position:fixed;inset:0;z-index:2147482000;display:flex;flex-direction:column;'
      + 'align-items:center;justify-content:center;background:rgba(8,20,36,.88);padding:24px;'
      + 'animation:kt-fade-in .15s ease both;';

    var img = document.createElement('img');
    img.src = url;
    img.alt = caption || 'Photo';
    img.style.cssText = 'max-width:min(94vw,1100px);max-height:80vh;border-radius:12px;'
      + 'box-shadow:0 20px 60px rgba(0,0,0,.5);object-fit:contain;background:#0b1626;';
    ov.appendChild(img);

    if (caption) {
      var cap = document.createElement('div');
      cap.textContent = String(caption).replace(/^\[Demo\] /, '');
      cap.style.cssText = 'color:#E2E8F0;font-size:14px;margin-top:14px;text-align:center;max-width:90vw;';
      ov.appendChild(cap);
    }

    var bar = document.createElement('div');
    bar.style.cssText = 'display:flex;gap:10px;margin-top:18px;';
    var dl = document.createElement('button');
    dl.type = 'button';
    dl.textContent = '\u2b07\ufe0f  Download';
    dl.style.cssText = 'background:#159FB4;color:#fff;border:0;border-radius:24px;padding:10px 22px;font-weight:700;font-size:14px;cursor:pointer;';
    dl.addEventListener('click', function () { downloadPhoto(url, caption, dl); });
    var close = document.createElement('button');
    close.type = 'button';
    close.textContent = 'Close';
    close.style.cssText = 'background:rgba(255,255,255,.14);color:#fff;border:1px solid rgba(255,255,255,.3);border-radius:24px;padding:10px 22px;font-weight:700;font-size:14px;cursor:pointer;';
    var dismiss = function () { if (ov.parentNode) ov.parentNode.removeChild(ov); };
    close.addEventListener('click', function () { if (window.KT && KT.popOverlay) KT.popOverlay(ov); else dismiss(); });
    bar.appendChild(dl); bar.appendChild(close);
    ov.appendChild(bar);

    // Click the dim backdrop (not the image/buttons) to close.
    ov.addEventListener('click', function (e) { if (e.target === ov) { if (window.KT && KT.popOverlay) KT.popOverlay(ov); else dismiss(); } });

    document.body.appendChild(ov);
    if (window.KT && KT.pushOverlay) KT.pushOverlay(ov, dismiss);
  }

  // Save the image to the device.
  // A cross-origin photo (external CDN, or api.kiddietrac.com from app.*) can be
  // SHOWN by an <img> but NOT read by fetch() — CORS blocks it — so the old code
  // dropped to a no-op window.open in the Capacitor WebView ("download did
  // nothing"). Now: (1) same-origin / CORS-ok → Web Share the real file (Save to
  // Photos/Files); (2) otherwise on the app open it in a Chrome Custom Tab where a
  // long-press saves it; (3) desktop → classic blob download.
  function ktIsNativeApp() {
    try { var C = window.Capacitor; if (C && (C.isNativePlatform ? C.isNativePlatform() : C.isNative)) return true; } catch (e) {}
    return document.documentElement.classList.contains('kt-native');
  }
  function ktOpenImgExternal(url) {
    try {
      var C = window.Capacitor;
      if (C && C.Plugins && C.Plugins.Browser && C.Plugins.Browser.open) { C.Plugins.Browser.open({ url: url }); return true; }
    } catch (e) {}
    if (!ktIsNativeApp()) {
      try { var w = window.open(url, '_blank', 'noopener'); if (w) return true; } catch (e) {}
      try { window.location.href = url; return true; } catch (e) {}
    }
    return false;
  }
  function downloadPhoto(url, caption, btn) {
    var name = ('kiddietrac-' + String(caption || 'photo').replace(/^\[Demo\] /, '').replace(/[^a-z0-9]+/gi, '-').slice(0, 40) || 'photo').replace(/-+$/, '') + '.jpg';
    var native = ktIsNativeApp();
    var oldTxt = btn ? btn.textContent : null; if (btn) { btn.textContent = 'Preparing…'; btn.disabled = true; }
    var reset = function () { if (btn) { btn.textContent = oldTxt; btn.disabled = false; } };
    var toExternal = function () {
      reset();
      var ok = ktOpenImgExternal(url);
      try {
        if (window.KT && KT.toast) {
          if (ok && native) KT.toast('⬇️', 'Opened in browser', 'Press and hold the photo, then tap Save.', '#159FB4');
          else if (!ok) KT.toast('⚠️', 'Couldn’t open the photo', 'Please update the KiddieTrac app, then try again.', '#B45309');
        }
      } catch (e) {}
    };
    function handleBlob(b) {
      var type = b.type || 'image/jpeg';
      // 1) Web Share the actual file → native "Save image" / "Save to Files".
      try {
        if (navigator.canShare) {
          var file = new File([b], name, { type: type });
          if (navigator.canShare({ files: [file] })) {
            return navigator.share({ files: [file], title: name }).then(reset, function () { reset(); /* cancelled */ });
          }
        }
      } catch (e) { /* fall through */ }
      // 2) App without Web Share → open in a Custom Tab to save.
      if (native) { toExternal(); return; }
      // 3) Desktop / PWA: classic blob download.
      var u = URL.createObjectURL(b);
      var a = document.createElement('a');
      a.href = u; a.download = name;
      document.body.appendChild(a); a.click();
      setTimeout(function () { URL.revokeObjectURL(u); if (a.parentNode) a.parentNode.removeChild(a); }, 4000);
      reset();
    }
    // The photo lives on a cross-origin CDN/storage with no CORS headers, so the
    // direct fetch() below can't read the bytes. When that fails we route the URL
    // THROUGH the API (auth + CORS + Content-Disposition: attachment) — the exact
    // mechanism the payslip PDF uses — so the download works everywhere.
    function viaProxy() {
      var t; try { t = sessionStorage.getItem('kt_token') || localStorage.getItem('kt_token'); } catch (e) {}
      var base = (window.KT && KT.API_BASE) || 'https://api.kiddietrac.com/api/v1';
      var p = base + '/media/download?url=' + encodeURIComponent(url) + '&name=' + encodeURIComponent(name);
      return fetch(p, { headers: { Authorization: 'Bearer ' + (t || '') } }).then(function (r) { if (!r.ok) throw new Error('HTTP ' + r.status); return r.blob(); }).then(handleBlob);
    }
    fetch(url, { credentials: 'include' }).then(function (r) { if (!r.ok) throw new Error('HTTP ' + r.status); return r.blob(); }).then(handleBlob)
      .catch(function () { viaProxy().catch(function () { toExternal(); }); });
  }

  // Read-only child record. Parents can VIEW their child's full details (never edit them —
  // the agency owns those). Opened by clicking the child card on the Today screen.
  function esc(v) { return String(v == null ? '' : v).replace(/[&<>"]/g, function (c) { return ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' })[c]; }); }
  async function openChildRecord(childId) {
    if (!childId) return;
    var ov = document.createElement('div');
    ov.className = 'kt-child-record';
    ov.style.cssText = 'position:fixed;inset:0;z-index:2147482000;display:flex;align-items:flex-start;justify-content:center;'
      + 'overflow-y:auto;padding:32px 16px;background:rgba(8,20,36,.55);animation:kt-fade-in .15s ease both;';
    var card = document.createElement('div');
    card.style.cssText = 'width:100%;max-width:640px;background:#fff;border-radius:18px;box-shadow:0 24px 60px -12px rgba(0,0,0,.4);overflow:hidden;';
    card.innerHTML = '<div style="padding:40px;text-align:center;color:#64748B;">Loading record…</div>';
    ov.appendChild(card);
    var dismiss = function () { if (ov.parentNode) ov.parentNode.removeChild(ov); };
    ov.addEventListener('click', function (e) { if (e.target === ov) { if (window.KT && KT.popOverlay) KT.popOverlay(ov); else dismiss(); } });
    document.body.appendChild(ov);
    if (window.KT && KT.pushOverlay) KT.pushOverlay(ov, dismiss);

    var d;
    try { d = await Api.get('/parent/children/' + childId); }
    catch (e) {
      // A parent seeing only "Could not load the record" cannot tell a server fault
      // from a missing child, and has nothing to do about either.
      card.innerHTML = '<div style="padding:40px;text-align:center;color:#B91C1C;">Could not load the record.'
        + '<div style="font-size:12px;color:#64748B;margin-top:6px;">'
        + ((e && e.message) ? String(e.message).replace(/[<>]/g, '') : 'The server did not respond.')
        + '</div><button type="button" class="kt-retry" style="margin-top:12px;background:#1F6080;color:#fff;'
        + 'border:0;border-radius:8px;padding:8px 16px;font-weight:700;cursor:pointer;">Try again</button></div>';
      var rb = card.querySelector('.kt-retry');
      if (rb) { rb.onclick = function () { location.reload(); }; }
      return;
    }

    var apiHost = ((window.KT_CONFIG && window.KT_CONFIG.apiBase) || 'https://api.kiddietrac.com/api/v1').replace(/\/api\/v1\/?$/, '');
    var photo = d.photo_url ? (/^https?:/.test(d.photo_url) ? d.photo_url : apiHost + d.photo_url) : '';
    var gender = ({ male: 'Male', female: 'Female', non_binary: 'Non-binary', prefer_not_to_say: 'Prefer not to say' })[d.gender] || '—';
    var sched = '';
    try { var arr = JSON.parse((d.enrollment && d.enrollment.schedule) || '[]'); sched = arr.map(function (x) { return x.charAt(0).toUpperCase() + x.slice(1); }).join(', '); } catch (e) {}

    function row(label, value) {
      if (!value) return '';
      return '<div style="display:flex;justify-content:space-between;gap:16px;padding:9px 0;border-bottom:1px solid #F1F5F9;">'
        + '<span style="color:#64748B;font-size:13px;">' + esc(label) + '</span>'
        + '<span style="color:#0F172A;font-size:14px;font-weight:600;text-align:right;">' + esc(value) + '</span></div>';
    }
    function section(title, inner) {
      if (!inner) return '';
      return '<div style="padding:16px 24px;"><div style="font-size:12px;font-weight:800;letter-spacing:.06em;text-transform:uppercase;color:#64748B;margin-bottom:6px;">' + esc(title) + '</div>' + inner + '</div>';
    }

    var guardians = (d.guardians || []).map(function (g) {
      return '<div style="padding:8px 0;border-bottom:1px solid #F1F5F9;">'
        + '<div style="font-weight:600;color:#0F172A;font-size:14px;">' + esc((g.first_name || '') + ' ' + (g.last_name || '')) + (g.is_primary ? ' <span style="color:#159FB4;font-weight:700;font-size:11px;">· PRIMARY</span>' : '') + '</div>'
        + '<div style="color:#64748B;font-size:12.5px;">' + esc(g.relationship || 'guardian') + (g.can_pickup ? ' · can pick up' : ' · not authorised for pickup') + '</div>'
        + (g.email ? '<div style="color:#64748B;font-size:12px;">' + esc(g.email) + '</div>' : '')
        + (g.phone ? '<div style="color:#64748B;font-size:12px;">📞 ' + esc(g.phone) + '</div>' : '') + '</div>';
    }).join('');

    /* EVERYONE ON FILE (2026-10-13, Anthony: "in the childs record under the parent and
       educator view needs to show all guardians and emergency contacts"). The record only
       showed guardians; the people who may collect the child are the family's emergency
       contacts. */
    var ecs = (d.emergency_contacts || []).map(function (e) {
      var alt = e.alt_phone ? e.alt_phone + ' (' + (e.alt_phone_type || 'mobile') + (e.alt_phone_ext ? ' ext. ' + e.alt_phone_ext : '') + ')' : '';
      return '<div style="padding:8px 0;border-bottom:1px solid #F1F5F9;">'
        + '<div style="font-weight:600;color:#0F172A;font-size:14px;">' + esc(e.name || 'Contact') + (e.user_id ? ' <span style="color:#047857;font-weight:700;font-size:11px;">· PICK-UP LOGIN</span>' : '') + '</div>'
        + '<div style="color:#64748B;font-size:12.5px;">' + esc(e.relationship || 'contact') + (e.can_pickup ? ' · can pick up' : ' · emergency contact only') + '</div>'
        + ((e.phone || alt) ? '<div style="color:#64748B;font-size:12px;">📞 ' + esc([e.phone, alt].filter(Boolean).join('  ·  ')) + '</div>' : '')
        + (e.email ? '<div style="color:#64748B;font-size:12px;">' + esc(e.email) + '</div>' : '') + '</div>';
    }).join('') || '<div style="color:#64748B;font-size:13px;padding:6px 0;">None on file.</div>';
    ecs += '<a href="#family-contacts" class="kt-cr-manage" style="display:inline-block;margin-top:10px;font-size:13px;font-weight:700;color:#1F6080;text-decoration:none;">Add or change pick-ups →</a>';

    var health = [];
    if (d.medical_notes) health.push(row('Medical', d.medical_notes));
    if (d.dietary_notes) health.push(row('Dietary', d.dietary_notes));
    (d.health_flags || []).forEach(function (h) { health.push(row('Flag', h.label || h.name || h)); });
    var healthHtml = health.length ? health.join('') : '<div style="color:#64748B;font-size:13px;padding:6px 0;">None on file.</div>';

    var fam = d.family || {};
    var addr = [fam.address_line1, fam.address_line2, fam.city, fam.province, fam.postal_code].filter(Boolean).join(', ');

    card.innerHTML =
      '<div style="background:linear-gradient(135deg,#1F6080 0%,#2c7894 100%);color:#fff;padding:22px 24px;display:flex;align-items:center;gap:16px;position:relative;">'
      + (photo ? '<img decoding="sync" src="' + esc(photo) + '" alt="" style="width:64px;height:64px;border-radius:50%;object-fit:cover;border:2px solid rgba(255,255,255,.5);">'
               : '<div style="width:64px;height:64px;border-radius:50%;background:rgba(255,255,255,.2);display:flex;align-items:center;justify-content:center;font-size:26px;font-weight:800;">' + esc((d.first_name || '?')[0]) + '</div>')
      + '<div><div style="font-size:22px;font-weight:800;">' + esc(d.full_name || d.display_name) + '</div>'
      + '<div style="opacity:.9;font-size:13px;">' + esc((d.age && d.age.human) || '') + (d.room && d.room.name ? ' · ' + esc(d.room.name) : '') + '</div>'
      /* refA-2026-10-14: the child's reference ID */
      + ((d.ref_code && KT.refPillHtml) ? '<div style="margin-top:6px;">' + KT.refPillHtml(d.ref_code, { label: 'Child ID' }) + '</div>' : '')
      + '</div>'
      + '<button type="button" id="kt-cr-close" aria-label="Close" style="position:absolute;top:14px;right:16px;background:rgba(255,255,255,.18);color:#fff;border:0;width:32px;height:32px;border-radius:50%;font-size:18px;cursor:pointer;line-height:1;">&times;</button>'
      + '<span style="position:absolute;bottom:12px;right:18px;background:rgba(255,255,255,.16);color:#fff;font-size:10px;font-weight:800;letter-spacing:.08em;padding:3px 9px;border-radius:20px;">VIEW ONLY</span></div>'
      + section('Child', row('Full name', d.full_name) + row('Date of birth', d.date_of_birth) + row('Age', d.age && d.age.human) + row('Gender', gender) + row('Status', d.is_at_centre ? 'At Centre' : 'Not at Centre'))
      + section('Enrolment', row('Room', d.room && d.room.name) + row((KT.centreWord ? KT.centreWord(false) : 'Centre'), d.centre && d.centre.name) + row('Schedule', sched) + row('Start date', d.enrollment && d.enrollment.start_date) + row('CWELCC', d.enrollment && d.enrollment.cwelcc_eligible ? 'Eligible' : ''))
      + section('Guardians', guardians)
      + section('Pick-ups & emergency contacts', ecs)
      + section('Health & dietary', healthHtml)
      /* Filled in after the card paints — a slow fetch must not hold up the profile. */
      + section('Medications', '<div id="kt-pmeds"><div style="color:#64748B;font-size:13px;padding:6px 0;">Loading…</div></div>')
      + section('Family', ((fam.ref_code && KT.refPillHtml) ? '<div style="display:flex;justify-content:space-between;gap:16px;padding:9px 0;border-bottom:1px solid #F1F5F9;align-items:center;"><span style="color:#64748B;font-size:13px;">Family ID</span>' + KT.refPillHtml(fam.ref_code) + '</div>' : '') + row('Household', fam.family_name) + row('Address', addr) + row('Phone', fam.primary_phone) + row('Email', fam.primary_email))
      + '<div style="padding:14px 24px 22px;color:#64748B;font-size:12px;">Need a change? Contact your childcare centre — these records are maintained by the agency.</div>';

    var cl = card.querySelector('#kt-cr-close');
    if (cl) cl.addEventListener('click', function () { if (window.KT && KT.popOverlay) KT.popOverlay(ov); else dismiss(); });
    // Leaving for the pick-up list closes the record first.
    var mg = card.querySelector('.kt-cr-manage');
    if (mg) mg.addEventListener('click', function () { if (window.KT && KT.popOverlay) KT.popOverlay(ov); else dismiss(); });

    /* ── MEDICATIONS: what is on file, and a way to ask for a new one ──────────
       A parent ASKS; a director authorises. Nothing submitted here can be given to a
       child until the centre says so, which is why every new request shows as
       "Waiting for the centre to authorise". (Anthony, 2026-09-08) */
    (function () {
      var box = card.querySelector('#kt-pmeds');
      if (!box || !d || !d.id) { return; }

      var STATUS = {
        pending_auth: ['Waiting for the centre to authorise', '#92400E', '#FEF3C7'],
        active:       ['Active', '#166534', '#DCFCE7'],
        discontinued: ['Stopped', '#475569', '#F1F5F9'],
        expired:      ['Expired', '#475569', '#F1F5F9'],
      };

      function medRow(m) {
        var st = STATUS[m.status] || [m.status, '#475569', '#F1F5F9'];
        var detail = [m.dosage, m.frequency].filter(Boolean).join(' · ');
        return '<div style="padding:9px 0;border-bottom:1px solid #F1F5F9;">'
          + '<div style="display:flex;justify-content:space-between;gap:10px;align-items:flex-start;">'
          + '<span style="color:#0F172A;font-size:14px;font-weight:600;">' + esc(m.name || '') + (m.strength ? ' ' + esc(m.strength) : '') + '</span>'
          + '<span style="flex:0 0 auto;background:' + st[2] + ';color:' + st[1] + ';font-size:10.5px;font-weight:800;padding:2px 8px;border-radius:20px;">' + esc(st[0]) + '</span>'
          + '</div>'
          + (detail ? '<div style="color:#64748B;font-size:12.5px;margin-top:2px;">' + esc(detail) + '</div>' : '')
          + (m.reason ? '<div style="color:#94A3B8;font-size:12px;margin-top:1px;">For ' + esc(m.reason) + '</div>' : '')
          + '</div>';
      }

      function askBtn() {
        return '<button type="button" id="kt-pmed-add" style="margin-top:10px;background:#EFF6FF;'
          + 'border:1px solid #BFDBFE;border-radius:10px;padding:9px 14px;font:inherit;font-size:13px;'
          + 'font-weight:700;color:#1E40AF;cursor:pointer;">\uD83D\uDC8A Ask the centre to administer a medication</button>';
      }

      function paint(list, err) {
        if (err) {
          box.innerHTML = '<div style="color:#B91C1C;font-size:13px;padding:6px 0;">Could not load medications.</div>' + askBtn();
        } else if (!list.length) {
          box.innerHTML = '<div style="color:#64748B;font-size:13px;padding:6px 0;">None on file.</div>' + askBtn();
        } else {
          box.innerHTML = list.map(medRow).join('') + askBtn();
        }
        /* One form, and it lives on the Health screen (#medications) — the screen the
           parent's own "Health" nav item opens. This card used to render a second copy
           inline, which is exactly the duplicate-form trap that produced two different
           "add provider" screens. Link, do not clone. */
        var b = box.querySelector('#kt-pmed-add');
        if (b) { b.addEventListener('click', function () { window.location.hash = '#medications'; }); }
      }

      function field(label, id, attrs) {
        return '<label style="display:block;margin-top:9px;">'
          + '<span style="display:block;font-size:12px;font-weight:700;color:#475569;margin-bottom:3px;">' + esc(label) + '</span>'
          + '<input id="' + id + '" ' + (attrs || '') + ' style="width:100%;box-sizing:border-box;padding:9px 11px;'
          + 'border:1px solid #E2E8F0;border-radius:9px;font:inherit;font-size:14px;"></label>';
      }

      function form(list) {
        box.innerHTML =
          '<div style="background:#F8FAFC;border:1px solid #E2E8F0;border-radius:12px;padding:13px;">'
          + '<div style="font-size:12.5px;color:#475569;">The centre has to authorise a medication before an educator can give it. Fill this in and they will be notified.</div>'
          + field('Medication *', 'kt-pm-name', 'placeholder="e.g. Amoxicillin"')
          + field('Strength', 'kt-pm-strength', 'placeholder="e.g. 250 mg/5 ml"')
          + field('Dose *', 'kt-pm-dosage', 'placeholder="e.g. 5 ml"')
          + field('How often *', 'kt-pm-freq', 'placeholder="e.g. twice daily, after meals"')
          + field('Starts *', 'kt-pm-start', 'type="date" value="' + esc(localToday()) + '"')
          + field('Ends', 'kt-pm-end', 'type="date"')
          + field('Reason', 'kt-pm-reason', 'placeholder="e.g. ear infection"')
          + field('Prescribed by', 'kt-pm-doc', 'placeholder="Doctor\'s name, if prescribed"')
          + '<label style="display:block;margin-top:9px;"><span style="display:block;font-size:12px;font-weight:700;color:#475569;margin-bottom:3px;">Anything the educator should know</span>'
          + '<textarea id="kt-pm-notes" rows="2" style="width:100%;box-sizing:border-box;padding:9px 11px;border:1px solid #E2E8F0;border-radius:9px;font:inherit;font-size:14px;"></textarea></label>'
          + '<div id="kt-pm-err" style="color:#B91C1C;font-size:12.5px;margin-top:8px;"></div>'
          + '<div style="display:flex;gap:8px;margin-top:11px;">'
          + '<button type="button" id="kt-pm-save" style="flex:1;background:#159FB4;border:0;border-radius:10px;padding:11px;font:inherit;font-size:14px;font-weight:700;color:#fff;cursor:pointer;">Send request</button>'
          + '<button type="button" id="kt-pm-cancel" style="background:#fff;border:1px solid #E2E8F0;border-radius:10px;padding:11px 16px;font:inherit;font-size:14px;color:#475569;cursor:pointer;">Cancel</button>'
          + '</div></div>';

        box.querySelector('#kt-pm-cancel').addEventListener('click', function () { paint(list); });
        box.querySelector('#kt-pm-save').addEventListener('click', async function () {
          var g = function (id) { var e = box.querySelector('#' + id); return e ? e.value.trim() : ''; };
          var err = box.querySelector('#kt-pm-err');
          var payload = {
            name: g('kt-pm-name'), strength: g('kt-pm-strength'), dosage: g('kt-pm-dosage'),
            frequency: g('kt-pm-freq'), starts_on: g('kt-pm-start'), expires_on: g('kt-pm-end') || null,
            reason: g('kt-pm-reason'), prescribing_physician: g('kt-pm-doc'),
            special_instructions: g('kt-pm-notes'),
            is_prescription: !!g('kt-pm-doc'),
          };
          if (!payload.name || !payload.dosage || !payload.frequency || !payload.starts_on) {
            err.textContent = 'Medication, dose, how often and the start date are all needed.';
            return;
          }
          var btn = box.querySelector('#kt-pm-save');
          btn.disabled = true; btn.textContent = 'Sending…'; err.textContent = '';
          try {
            await Api.post('/parent/children/' + d.id + '/medications', payload);
            var fresh = [];
            try { var r2 = await Api.get('/parent/children/' + d.id + '/medications'); fresh = (r2 && (r2.medications || r2.data)) || []; } catch (e2) {}
            paint(fresh);
            if (window.KT && KT.Dom && KT.Dom.toast) { KT.Dom.toast('Request sent — the centre has been notified', 'success'); }
          } catch (e) {
            btn.disabled = false; btn.textContent = 'Send request';
            err.textContent = (e && e.message) || 'Could not send that request.';
          }
        });
      }

      Api.get('/parent/children/' + d.id + '/medications')
        .then(function (r) { paint((r && (r.medications || r.data)) || []); })
        .catch(function () { paint([], true); });
    }());
  }

  // ─── Walks + new-media helpers (shared by the desktop and mobile Today) ──────

  // /parent/walks returns the guardian's children's trips keyed by `child_name`
  // (no child_id in the payload), so match on the id when a future payload gains
  // one, else on the name. A parent with one child sees everything either way.
  function matchesChild(walk, child) {
    if (!walk || !child) return false;
    if (walk.child_id != null) return String(walk.child_id) === String(child.id);
    const want = String(child.display_name || '').trim().toLowerCase();
    const got = String(walk.child_name || '').trim().toLowerCase();
    if (!want || !got) return true;              // can't tell → don't hide it
    return got === want || got.startsWith(want) || want.startsWith(got.split(' ')[0]);
  }

  // "New" = shared since the parent last opened the gallery. The watermark is
  // per-child in localStorage; with no watermark yet (first ever visit) we treat
  // the last 3 days as new rather than claiming every historic photo is unseen.
  function mediaSeenKey(childId) { return 'kt_media_seen_' + childId; }
  function unseenMedia(photos, childId) {
    let since = 0;
    try { since = Date.parse(localStorage.getItem(mediaSeenKey(childId)) || '') || 0; } catch (e) { since = 0; }
    if (!since) since = Date.now() - 3 * 86400000;
    const fresh = (photos || []).filter(p => {
      const t = Date.parse(String(p.taken_at || '').replace(' ', 'T'));
      return !isNaN(t) && t > since;
    });
    const vids = fresh.filter(p => p.media_type === 'video').length;
    const pics = fresh.length - vids;
    let label;
    if (vids && pics) label = 'photos & videos';
    else if (vids) label = vids === 1 ? 'video' : 'videos';
    else label = pics === 1 ? 'photo' : 'photos';
    return { count: fresh.length, hasVideo: vids > 0, label };
  }
  function markMediaSeen(childId) {
    try { localStorage.setItem(mediaSeenKey(childId), new Date().toISOString()); } catch (e) {}
  }

  async function renderTodayTab(wrap) {

    const child = state.children.find(c => c.id === state.selectedChildId);
    if (!child) return;

    // Header — child's avatar + name/status + view-record
    const _todayAv = Dom.el('div', { style: 'flex-shrink:0;' });
    if (window.KT && KT.avatar) _todayAv.innerHTML = KT.avatar(child.display_name, { size: 60, photoUrl: child.photo_url ? absUrl(child.photo_url) : null });
    const _todayLeft = Dom.el('div', { style: 'display: flex; align-items: center; gap: 16px;' }, [
      // Running late, sick, not attending: the "+" on the photo (childActionsAvatar, 2026-11-06). Today only.
      state.date === localToday() ? childActionsAvatar(child, _todayAv) : _todayAv,
      Dom.el('div', { style: 'min-width: 0;' }, [
        Dom.el('div', { style: 'display: flex; align-items: center; gap: 12px; margin-bottom: 6px; flex-wrap: wrap;' }, [
          Dom.el('h1', { style: 'margin: 0; font-size: 32px;' }, `Today with ${child.display_name}`),
          child.is_at_centre
            ? Dom.el('span', { style: 'background: var(--brand-green); color: white; padding: 4px 12px; border-radius: 20px; font-size: 12px; font-weight: 700; letter-spacing: 0.5px;' }, 'AT CENTRE')
            : Dom.el('span', { style: 'background: var(--ink-200); color: var(--ink-700); padding: 4px 12px; border-radius: 20px; font-size: 12px; font-weight: 700; letter-spacing: 0.5px;' }, 'NOT AT CENTRE'),
        ]),
        Dom.el('p', { style: 'color: var(--ink-600); margin: 0;' },
          `${child.display_name} · ${child.age?.human || '—'} · ${child.room_name || 'No room assigned'}`
        ),
        Dom.el('button', {
          type: 'button',
          style: 'margin-top: 10px; background: #fff; color: #1F6080; border: 1px solid #cbd5e1; border-radius: 999px; padding: 7px 16px; font-size: 13px; font-weight: 700; cursor: pointer; display: inline-flex; align-items: center; gap: 6px;',
          onclick: () => openChildRecord(child.id),
        }, `📋 View ${child.display_name}'s record`),
      ]),
    ]);
    // Identity on the left, the date navigator on the right — fills the empty space
    // that used to sit beside "Today with …".
    wrap.appendChild(Dom.el('div', { style: 'display: flex; align-items: flex-start; justify-content: space-between; gap: 24px; flex-wrap: wrap; margin-bottom: 24px;' }, [
      _todayLeft, buildDateNav(),
    ]));
    // Supplies the centre has asked for (2026-10-23, kt-supplies.js).
    if (window.KT && KT.Supplies) { const _sup = Dom.el('div'); wrap.appendChild(_sup); KT.Supplies.parentCard(_sup); }
    // minmax(0,…) so a long timeline detail can't blow the column out; the aside is
    // given a real floor because it now carries the walk route map.
    const grid = Dom.el('div', { style: 'display: grid; grid-template-columns: minmax(0,1.55fr) minmax(330px,1fr); gap: 24px;' });
    wrap.appendChild(grid);
    const main = Dom.el('div'); grid.appendChild(main);
    const aside = Dom.el('div', { style: 'display: flex; flex-direction: column; gap: 16px;' }); grid.appendChild(aside);

    // AI digest card (top of main column)
    // The day's numbers lead (2026-11-06, as on a phone): the stats strip sits above the digest.
    const statsRow = Dom.el('div', { style: 'display: grid; grid-template-columns: repeat(auto-fit, minmax(140px, 1fr)); gap: 12px; margin-bottom: 24px;' });
    main.appendChild(statsRow);
    // Due now under the numbers (2026-11-06, as on a phone): this month's billing left the side column.
    const billingCard = Dom.el('div', { class: 'card', style: 'margin-bottom: 24px;' });
    main.appendChild(billingCard);
    const digestCard = Dom.el('div', {
      style: 'background: linear-gradient(135deg, #1F6080 0%, #2c7894 100%); color: white; padding: 24px; border-radius: 16px; margin-bottom: 24px;',
    });
    main.appendChild(digestCard);
    digestCard.appendChild(Dom.el('div', { style: 'font-size: 12px; font-weight: 700; letter-spacing: 1px; color: #8EC73C; margin-bottom: 8px;' }, '✨ YOUR DAILY DIGEST'));
    const digestBody = Dom.el('div', { style: 'font-size: 15px; line-height: 1.6;' }, 'Loading...');
    digestCard.appendChild(digestBody);
    weekRecapButton(digestCard, child);

    // Timeline (the stats strip is above the digest now)

    const timelineCard = Dom.el('div', { class: 'card' });
    main.appendChild(timelineCard);
    timelineCard.appendChild(Dom.el('h3', { style: 'margin:0 0 10px;background:linear-gradient(135deg,#EAF3FB,#F3F0FF);border:1px solid rgba(31,96,128,.10);padding:9px 13px;border-radius:10px;font-size:15px;color:#1F6080;' }, '🕒 Timeline'));
    timelineCard.appendChild(Dom.el('p', { style: 'color: var(--ink-600); margin: 0 0 16px 0; font-size: 13px;' }, 'Every moment logged by the educators'));
    // .kt-today-timeline flows the rows into TWO columns on a wide screen (see
    // kt-consistency-polish.css). A day's 10-15 short events rendered as one narrow
    // ribbon left most of the card empty and pushed everything else down the page.
    const timelineBody = Dom.el('div', { class: 'kt-today-timeline' }); timelineCard.appendChild(timelineBody);

    // Aside: recent observations (billing is under the stats now)
    billingCard.appendChild(Dom.el('h3', { style: 'margin: 0 0 12px 0;' }, "This month's billing"));
    const billingBody = Dom.el('div'); billingCard.appendChild(billingBody);
    billingBody.appendChild(Dom.el('p', { style: 'color: var(--ink-600);' }, 'Loading...'));

    const obsCard = Dom.el('div', { class: 'card' });
    aside.appendChild(obsCard);
    obsCard.appendChild(Dom.el('h3', { style: 'margin:0 0 12px;background:linear-gradient(135deg,#F3F0FF,#EAF3FB);border:1px solid rgba(124,58,237,.12);padding:9px 13px;border-radius:10px;font-size:15px;color:#6D28D9;' }, '👀 Recent observations'));
    const obsBody = Dom.el('div'); obsCard.appendChild(obsBody);
    obsBody.appendChild(Dom.el('p', { style: 'color: var(--ink-600);' }, 'Loading...'));

    // Walks & outings — parents asked to see the day's trips alongside the rest of
    // Today rather than only on the separate Walks screen.
    const walkCard = Dom.el('div', { class: 'card' });
    aside.appendChild(walkCard);
    walkCard.appendChild(Dom.el('h3', { style: 'margin:0 0 12px;background:linear-gradient(135deg,#ECFEFF,#F0FDF4);border:1px solid rgba(13,148,136,.14);padding:9px 13px;border-radius:10px;font-size:15px;color:#0F766E;' }, '🚶 Walk Watch'));
    const walkBody = Dom.el('div'); walkCard.appendChild(walkBody);
    walkBody.appendChild(Dom.el('p', { style: 'color: var(--ink-600);' }, 'Loading...'));

    // Fire off all the parallel fetches. The walk/photo calls are additive niceties,
    // so they resolve to an empty shape on failure — a walks outage must never take
    // the whole Today screen down with it.
    Promise.all([
      Api.get(`/parent/children/${child.id}/timeline?date=${state.date}`).catch(() => ({ events: [] })),
      Api.get(`/parent/children/${child.id}/digest/${state.date}`).catch(() => ({})),
      Api.get(`/parent/children/${child.id}/invoices?status=current`).catch(() => ({ invoices: [] })),
      /* 403s when an admin previews the parent role — they are not the child's
         guardian. That is correct, and it used to take the whole screen with it. */
      Api.get(`/parent/children/${child.id}/observations`).catch(() => ({ observations: [] })),
      Api.get('/parent/walks').catch(() => ({ walks: [] })),
      Api.get('/parent/active-walks').catch(() => ({ walks: [] })),
      Api.get(`/parent/children/${child.id}/photos`).catch(() => ({ photos: [] })),
    ]).catch(() => [{}, {}, {}, {}, { walks: [] }, { walks: [] }, { photos: [] }])
      .then(([timeline, digest, invoices, observations, walksRes, activeRes, mediaRes]) => {
      /* Reached even if everything above failed, so no card is left claiming to be
         loading. An empty card is honest; a permanent spinner is not. */
      // Stats
      const meals = (timeline.events || []).filter(e => e.type === 'meal' || e.type === 'snack').length;
      const naps = (timeline.events || []).filter(e => e.type === 'nap_end').length;
      const diapers = (timeline.events || []).filter(e => e.type === 'diaper' || e.type === 'bathroom').length;
      const activities = (timeline.events || []).filter(e => e.type === 'activity').length;
      // Days-enrolled tile (replaced the balance-due tile — teal, distinct from
      // the violet billing card). Days since the child's enrolment date.
      const _enr = child.enrolled_at || child.enrollment_start || child.enrolled_on || child.enrollment_date || null;
      const _enrMs = _enr ? new Date(String(_enr).replace(' ', 'T')).getTime() : NaN;
      const _daysEnr = isNaN(_enrMs) ? null : Math.max(0, Math.floor((Date.now() - _enrMs) / 86400000));
      const _enrSub = isNaN(_enrMs) ? 'enrolment date n/a' : ('since ' + new Date(_enrMs).toLocaleDateString(undefined, { month: 'short', day: 'numeric', year: 'numeric' }));
      [
        { label: 'MEALS & SNACKS', n: meals, sub: 'Logged today', icon: '🍎', c1: '#FDBA74', c2: '#F97316', tint: '#FFF7ED', ink: '#C2410C' },
        { label: 'NAPS', n: naps, sub: 'Completed', icon: '😴', c1: '#A5B4FC', c2: '#6366F1', tint: '#EEF2FF', ink: '#4338CA' },
        { label: 'DIAPER / BATHROOM', n: diapers, sub: 'Total today', icon: '💧', c1: '#7DD3FC', c2: '#0EA5E9', tint: '#ECFEFF', ink: '#0369A1' },
        { label: 'ACTIVITIES', n: activities, sub: 'Learning moments', icon: '🎨', c1: '#86EFAC', c2: '#16A34A', tint: '#F0FDF4', ink: '#15803D' },
        { label: 'DAYS ENROLLED', n: _daysEnr == null ? '—' : _daysEnr, sub: _enrSub, icon: '📆', c1: '#5EEAD4', c2: '#0D9488', tint: '#F0FDFA', ink: '#0F766E' },
      ].forEach((s) => {
        const tile = Dom.el('div', { style: 'position:relative;overflow:hidden;background:' + s.tint + ';border:1px solid rgba(15,23,42,.06);border-radius:16px;padding:15px 15px 14px;transition:transform .15s ease, box-shadow .15s ease;' });
        tile.addEventListener('mouseenter', () => { tile.style.transform = 'translateY(-3px)'; tile.style.boxShadow = '0 14px 24px -14px ' + s.c2; });
        tile.addEventListener('mouseleave', () => { tile.style.transform = ''; tile.style.boxShadow = ''; });
        tile.appendChild(Dom.el('div', { style: 'width:40px;height:40px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:20px;margin-bottom:11px;background:linear-gradient(135deg,' + s.c1 + ',' + s.c2 + ');box-shadow:0 6px 13px -6px ' + s.c2 + ';' }, s.icon));
        tile.appendChild(Dom.el('div', { style: 'font-size:34px;font-weight:900;line-height:1;color:' + s.ink + ';' }, String(s.n)));
        tile.appendChild(Dom.el('div', { style: 'font-size:10.5px;font-weight:800;letter-spacing:.6px;color:' + s.ink + ';opacity:.72;margin-top:9px;' }, s.label));
        tile.appendChild(Dom.el('div', { style: 'font-size:11.5px;color:var(--ink-500,#64748b);margin-top:2px;' }, s.sub));
        statsRow.appendChild(tile);
      });

      // Timeline (moments + sign-in/out times, chronological)
      Dom.clear(timelineBody);
      const _tlEntries = mergeTimelineChecks(timeline);
      // Opt into the shared bottom "N records" bar (kt-table-export), but not the
      // search/sort toolbar or per-row kebabs — this is a short chronological story,
      // not a dataset. Only marked when non-empty, or the bar would count the
      // empty-state paragraph as one record (same rule as screen-observations.js).
      if (_tlEntries.length) {
        timelineBody.setAttribute('data-kt-list', '1');
        timelineBody.setAttribute('data-kt-no-controls', '1');
        timelineBody.setAttribute('data-kt-no-kebab', '1');
      } else {
        timelineBody.removeAttribute('data-kt-list');
      }
      if (!_tlEntries.length) {
        timelineBody.appendChild(Dom.el('p', { style: 'color: var(--ink-500); padding: 24px 0;' }, 'No events logged yet today. Check back soon!'));
      } else {
        _tlEntries.forEach((ev, i) => {
          // Compact row: the old layout used a 40px icon bubble, 12px vertical
          // padding and a right-aligned time, which left a wide empty channel across
          // the middle of every row and made a normal 11-event day scroll forever.
          // Now: time on the LEFT as a fixed 62px gutter (so times line up and read
          // as a chronology), a 26px icon, and title + detail sharing one line where
          // the detail is short. Zebra striping is kept for scanability.
          const row = Dom.el('div', { style: 'display:flex; gap:9px; padding:6px 9px; border-radius:8px; align-items:baseline; background:' + (i % 2 ? 'var(--ink-50,#f8fafc)' : '#ffffff') + ';' });
          row.appendChild(Dom.el('div', { style: 'width:62px;flex-shrink:0;color:var(--ink-500);font-size:12px;font-variant-numeric:tabular-nums;' }, ev.time_display || ''));
          row.appendChild(Dom.el('div', { style: 'width:20px;flex-shrink:0;font-size:15px;line-height:1.25;' }, eventIcon(ev.type, ev)));
          const text = Dom.el('div', { style: 'flex:1;min-width:0;line-height:1.35;' });
          text.appendChild(Dom.el('span', { style: 'font-weight:600;font-size:13.5px;' }, ev.display?.title || ev.type));
          if (ev.display?.detail) {
            text.appendChild(Dom.el('span', { style: 'color:var(--ink-500);font-size:12.5px;margin-left:7px;' }, ev.display.detail));
          }
          row.appendChild(text);
          timelineBody.appendChild(row);
        });
      }

      // Digest
      Dom.clear(digestBody);
      if (digest.body) {
        digestBody.appendChild(Dom.el('div', { style: 'white-space: pre-wrap;' }, digest.body));
      } else {
        digestBody.appendChild(Dom.el('div', { style: 'opacity: 0.9;' }, digest.message || `${child.display_name}'s digest will be ready after 4 PM today.`));
      }

      // Billing
      Dom.clear(billingBody);
      /* Due now, from the server (2026-10-28): the newest invoice used to stand in for "balance due", and with a
         schedule issued in advance that was an instalment not due for months. */
      const _due = invoices.due_now;
      const inv = _due ? ((_due.total > 0.004 || (invoices.invoices || []).length) ? { balance_due: _due.total, invoice_number: _due.count + ' invoice' + (_due.count === 1 ? '' : 's'), due_date: 'now', _due: true } : null) : (invoices.invoices || [])[0];
      if (inv) {
        const amtBox = Dom.el('div', { style: 'background:linear-gradient(135deg,#EEF0FF,#F5EEFF);border:1px solid rgba(109,40,217,.12);border-radius:14px;padding:14px 16px;' });
        amtBox.appendChild(Dom.el('div', { style: 'font-size: 30px; font-weight: 900; color:#6D28D9; line-height:1;' }, '$' + (inv.balance_due ?? inv.total).toFixed(2)));
        amtBox.appendChild(Dom.el('div', { style: 'font-size: 12px; font-weight:700; color:#7C3AED; opacity:.8; margin-top:5px; text-transform:uppercase; letter-spacing:.5px;' }, inv.is_estimate ? 'estimated this month' : 'balance due'));
        if (inv._due) {
          amtBox.appendChild(Dom.el('div', { style: 'font-size: 12px; color: var(--ink-600); margin-top:6px;' }, _due.total > 0.004 ? (_due.count + ' invoice' + (_due.count === 1 ? '' : 's') + ' due now') : 'Nothing due right now'));
        } else if (!inv.is_estimate) {
          amtBox.appendChild(Dom.el('div', { style: 'font-size: 12px; color: var(--ink-600); margin-top:6px;' }, `Invoice ${inv.invoice_number} · due ${inv.due_date}`));
        }
        billingBody.appendChild(amtBox);
        billingBody.appendChild(Dom.el('a', { href: '#billing', style: 'display: inline-block; margin-top: 12px; font-size: 13px; color:#6D28D9; font-weight: 700; text-decoration: none;' }, 'View billing →'));
      } else {
        billingBody.appendChild(Dom.el('p', { style: 'color: var(--ink-500); font-size: 13px;' }, 'No invoices yet.'));
      }

      // Observations
      Dom.clear(obsBody);
      const obs = observations.observations || [];
      if (obs.length === 0) {
        obsBody.removeAttribute('data-kt-list');
        obsBody.appendChild(Dom.el('p', { style: 'color: var(--ink-500); font-size: 13px;' }, 'No observations recorded yet.'));
      } else {
        obsBody.setAttribute('data-kt-list', '1');
        obsBody.setAttribute('data-kt-no-controls', '1');
        obsBody.setAttribute('data-kt-no-kebab', '1');
        obs.slice(0, 3).forEach((o, i) => {
          // Zebra striping, matching the Timeline directly to its left — the flat
          // white rows made consecutive observations hard to tell apart.
          const item = Dom.el('div', { style: 'padding: 10px; border-radius: 8px; background: ' + (i % 2 ? 'var(--ink-50,#f8fafc)' : '#ffffff') + ';' });
          item.appendChild(Dom.el('div', { style: 'font-size: 11px; font-weight: 700; color: var(--brand-green); letter-spacing: 0.5px; margin-bottom: 4px;' }, o.domain_label));
          item.appendChild(Dom.el('div', { style: 'font-weight: 600; font-size: 14px; line-height: 1.3;' }, o.title.replace(/^\[Demo\] /, '')));
          item.appendChild(Dom.el('div', { style: 'color: var(--ink-500); font-size: 12px; margin-top: 4px;' }, o.date_display));
          obsBody.appendChild(item);
        });
      }

      // Walks & outings — a live trip wins the card; otherwise the most recent few.
      Dom.clear(walkBody);
      const _live = (activeRes && activeRes.walks || []).filter(w => matchesChild(w, child));
      const _past = (walksRes && walksRes.walks || []).filter(w => matchesChild(w, child));
      if (_live.length) {
        _live.forEach(w => {
          const box = Dom.el('div', { style: 'background:linear-gradient(135deg,#ECFEFF,#F0FDFA);border:1px solid rgba(13,148,136,.18);border-radius:12px;padding:12px 14px;margin-bottom:8px;' });
          box.appendChild(Dom.el('div', { style: 'display:flex;align-items:center;gap:7px;font-size:11px;font-weight:800;letter-spacing:.5px;color:#0F766E;' }, [
            Dom.el('span', { style: 'width:8px;height:8px;border-radius:50%;background:#0D9488;display:inline-block;' }), 'OUT NOW',
          ]));
          box.appendChild(Dom.el('div', { style: 'font-weight:700;font-size:14px;margin-top:5px;' }, w.title || 'Walk / outing'));
          if (w.destination) box.appendChild(Dom.el('div', { style: 'color:var(--ink-600);font-size:12.5px;margin-top:2px;' }, '📍 ' + w.destination));
          walkBody.appendChild(box);
        });
        // NB: the link goes on the CARD, not inside walkBody — walkBody is the
        // [data-kt-list] container, and the bottom "N records" bar counts its direct
        // children, so an anchor in there would inflate the count by one.
        walkCard.appendChild(Dom.el('a', { href: '#walks', style: 'display:inline-block;margin-top:6px;font-size:13px;color:#0F766E;font-weight:700;text-decoration:none;' }, 'Follow live →'));
      } else if (_past.length) {
        walkBody.setAttribute('data-kt-list', '1');
        walkBody.setAttribute('data-kt-no-controls', '1');
        walkBody.setAttribute('data-kt-no-kebab', '1');
        _past.slice(0, 3).forEach((w, i) => {
          const row = Dom.el('div', { style: 'padding:10px;border-radius:8px;background:' + (i % 2 ? 'var(--ink-50,#f8fafc)' : '#ffffff') + ';' });
          row.appendChild(Dom.el('div', { style: 'font-weight:600;font-size:13.5px;' }, w.title || 'Walk / outing'));
          const bits = [];
          if (w.destination) bits.push('📍 ' + w.destination);
          if (w.duration_min) bits.push(w.duration_min + ' min');
          if (w.steps_est) bits.push('👣 ' + w.steps_est);
          row.appendChild(Dom.el('div', { style: 'color:var(--ink-500);font-size:12px;margin-top:3px;' }, bits.join(' · ') || (w.trip_date || '')));
          // The most recent walk with GPS shows its there-and-back route inline, so
          // the map is on Today rather than two taps away on the Walks screen.
          if (i === 0 && w.has_location && window.KT && KT.WalkTracker && KT.WalkTracker.mountRouteMap) {
            const mapBox = Dom.el('div', { style: 'height:190px;border-radius:10px;overflow:hidden;background:#EEF2F7;margin-top:8px;' });
            row.appendChild(mapBox);
            KT.WalkTracker.mountRouteMap(mapBox, w.trip_id);
          }
          walkBody.appendChild(row);
        });
      } else {
        walkBody.removeAttribute('data-kt-list');
        walkBody.appendChild(Dom.el('p', { style: 'color: var(--ink-500); font-size: 13px;' }, 'No walks or outings recorded yet.'));
      }
      if (!_live.length) {
        walkCard.appendChild(Dom.el('a', { href: '#walks', style: 'display:inline-block;margin-top:10px;font-size:13px;color:#0F766E;font-weight:700;text-decoration:none;' }, 'View all walks →'));
      }
      // The three Today lists are filled asynchronously, after the shared DOM-settle
      // sweep has already run for this screen — ask for one more pass so the bottom
      // "N records" bars appear with the data instead of on the next 6s safety tick.
      if (window.KT && KT.sweepBus && KT.sweepBus.run) KT.sweepBus.run();
      else if (window.KT && KT.tableExport) KT.tableExport.sweep();

      // New photo/video since the parent last opened the gallery → surface a link
      // here so fresh media isn't missed on a screen they may not visit daily.
      const _newMedia = unseenMedia(mediaRes && mediaRes.photos || [], child.id);
      if (_newMedia.count) {
        const pill = Dom.el('a', {
          href: '#photos',
          style: 'display:flex;align-items:center;gap:10px;text-decoration:none;background:linear-gradient(135deg,#FFF7ED,#FEF2F2);border:1px solid rgba(249,115,22,.22);border-radius:12px;padding:11px 14px;margin-bottom:16px;',
        }, [
          Dom.el('span', { style: 'font-size:20px;' }, _newMedia.hasVideo ? '🎬' : '📸'),
          Dom.el('span', { style: 'font-weight:700;font-size:13.5px;color:#C2410C;' },
            `${_newMedia.count} new ${_newMedia.label} to see`),
          Dom.el('span', { style: 'margin-left:auto;font-size:13px;font-weight:700;color:#C2410C;' }, '→'),
        ]);
        main.insertBefore(pill, statsRow);
      }
    }).catch(e => console.error('Today tab load failed:', e));
  }

  // ─── PHOTOS TAB ─────────────────────────────────────────────────

  async function renderPhotosTab(wrap) {
    // (Sub-nav removed — it duplicated the shell's own navigation; see Messages.)
    const child = state.children.find(c => c.id === state.selectedChildId);
    if (!child) return;

    // Photos and video share one screen and one API — they were only ever split by
    // wording. The desktop gallery previously rendered every item as an <img>, so a
    // shared video clip showed as a broken image here while working on mobile.
    wrap.appendChild(Dom.el('h1', { style: 'margin: 0 0 24px;' }, `${child.display_name}'s Photos & video`));

    const gallery = Dom.el('div', { style: 'display: grid; grid-template-columns: repeat(auto-fill, minmax(220px, 1fr)); gap: 16px;' });
    wrap.appendChild(gallery);
    gallery.appendChild(Dom.el('p', { style: 'color: var(--ink-500); grid-column: 1 / -1;' }, 'Loading photos & video…'));

    try {
      const data = await Api.get(`/parent/children/${child.id}/photos`);
      Dom.clear(gallery);
      if (!data.photos || data.photos.length === 0) {
        gallery.removeAttribute('data-kt-list');
        // Across the whole grid, so it centres like every other empty screen (2026-10-13).
        gallery.appendChild(Dom.el('div', { style: 'grid-column:1/-1;' }, [emptyState('📷', 'Nothing shared yet', `When educators share photos or video of ${child.display_name}, they'll appear here.`)]));
        return;
      }
      gallery.setAttribute('data-kt-list', '1');
      gallery.setAttribute('data-kt-no-controls', '1');
      gallery.setAttribute('data-kt-no-kebab', '1');
      const seq = galleryItems(data.photos);
      data.photos.forEach((p, pi) => {
        const card = Dom.el('div', { class: 'card', style: 'padding: 0; overflow: hidden;' });
        if (p.media_type === 'video') {
          card.appendChild(Dom.el('video', {
            src: p.url, controls: true, preload: 'metadata', playsInline: true, poster: p.thumbnail_url || undefined,
            style: 'width: 100%; aspect-ratio: 4/3; object-fit: cover; display: block; background: #0F172A;',
          }));
        } else {
          // Tile shows the 480px thumbnail; the lightbox still opens p.url at full
          // quality. thumbnail_url used to BE the full-size path, so a grid of
          // tiles pulled megabytes each — one phone photo is ~5 MB against ~30 KB.
          const img = Dom.el('img', { src: p.thumbnail_url || p.url, alt: p.caption || 'Photo', loading: 'lazy', decoding: 'async', style: 'width: 100%; aspect-ratio: 4/3; object-fit: cover; display: block; background: var(--ink-100); cursor: zoom-in;' });
          img.addEventListener('click', function () { openPhotoLightbox(p.url, p.caption, seq, pi); });
          card.appendChild(img);
        }
        const body = Dom.el('div', { style: 'padding: 12px;' });
        if (p.caption) body.appendChild(Dom.el('div', { style: 'font-size: 14px; line-height: 1.4;' }, p.caption.replace(/^\[Demo\] /, '')));
        body.appendChild(Dom.el('div', { style: 'font-size: 12px; color: var(--ink-500); margin-top: 4px;' },
          (p.media_type === 'video' ? '🎬 ' : '') + (p.date_display || '')));
        card.appendChild(body);
        gallery.appendChild(card);
      });
      // Opening the gallery clears the "new media" pill on Today.
      markMediaSeen(child.id);
    } catch (e) {
      Dom.clear(gallery);
      gallery.removeAttribute('data-kt-list');
      gallery.appendChild(emptyState('⚠️', 'Could not load photos & video', e.message));
    }
  }

  // ─── MESSAGES TAB ───────────────────────────────────────────────

  async function renderMessagesTab(wrap) {
    wrap.appendChild(buildSubNav('messages'));

    /* Compose lived ONLY in renderMessagesMobile, so a parent on a computer had no way
       to start a message at all - the desktop screen listed existing threads and, if
       there were none, said "No conversations yet" and stopped. The full-screen composer
       is reused as-is: it is position:fixed and works on either layout. (2026-08-25) */
    const msgHead = Dom.el('div', {
      style: 'display:flex;align-items:center;justify-content:space-between;gap:16px;margin:0 0 24px;',
    });
    msgHead.appendChild(Dom.el('h1', { style: 'margin:0;' }, 'Messages'));
    const newMsgBtn = Dom.el('button', {
      type: 'button',
      style: 'display:inline-flex;align-items:center;gap:7px;background:#159FB4;color:#fff;border:none;'
        + 'border-radius:22px;padding:10px 18px;font-size:14px;font-weight:700;cursor:pointer;min-height:40px;',
    }, [Dom.el('span', { style: 'font-size:15px;' }, '\u270E'), 'New message']);
    newMsgBtn.addEventListener('click', () => openComposeNew());
    msgHead.appendChild(newMsgBtn);
    wrap.appendChild(msgHead);

    const container = Dom.el('div', { style: 'display: grid; grid-template-columns: 320px 1fr; gap: 24px; height: calc(100vh - 290px); min-height: 380px; max-height: calc(100vh - 290px);' });
    wrap.appendChild(container);

    const list = Dom.el('div', { class: 'card', style: 'padding: 8px; height: 100%; max-height: 100%; overflow-y: auto;' });  // fills the bounded area and scrolls, like a Gmail conversation list
    container.appendChild(list);
    list.appendChild(Dom.el('p', { style: 'padding: 12px; color: var(--ink-500);' }, 'Loading conversations…'));

    const thread = Dom.el('div', { class: 'card', style: 'display: flex; flex-direction: column; height: 100%; max-height: 100%; overflow: hidden;' });  // fills the bounded area; its message body scrolls internally
    container.appendChild(thread);

    try {
      const data = await Api.get('/parent/messages');
      Dom.clear(list);

      if (!data.conversations || data.conversations.length === 0) {
        list.appendChild(Dom.el('p', { style: 'padding: 16px; color: var(--ink-500);' }, 'No conversations yet.'));
        thread.appendChild(emptyState('\u{1F4AC}', 'No conversations',
          'Messages with your educators will appear here \u2014 or start one now.'));
        // A dead end otherwise: nothing here explained how to begin.
        const startBtn = Dom.el('button', {
          type: 'button',
          style: 'display:block;margin:0 auto;background:#159FB4;color:#fff;border:none;border-radius:22px;'
            + 'padding:11px 22px;font-size:14px;font-weight:700;cursor:pointer;min-height:42px;',
        }, 'Start a message');
        startBtn.addEventListener('click', () => openComposeNew());
        thread.appendChild(startBtn);
        return;
      }

      let selected = data.conversations[0];

      const renderList = () => {
        Dom.clear(list);
        data.conversations.forEach(c => {
          const isActive = c.id === selected.id;
          const title = c.child_name || c.subject || 'Conversation';
          const item = Dom.el('div', {
            style: `padding: 12px; border-radius: 8px; cursor: pointer; margin-bottom: 4px; ${isActive ? 'background: var(--ink-100);' : ''}`,
          });
          item.appendChild(Dom.el('div', { style: 'display: flex; align-items: center; gap: 8px; margin-bottom: 4px;' }, [
            Dom.el('div', { style: 'width: 10px; height: 10px; border-radius: 50%; background: #1F6080;' }),
            Dom.el('div', { style: 'font-weight: 600;' }, title),
            c.unread_count > 0
              ? Dom.el('span', { style: 'background: var(--brand-green); color: white; padding: 1px 8px; border-radius: 10px; font-size: 11px; font-weight: 700; margin-left: auto;' }, String(c.unread_count))
              : '',
          ]));
          if (c.child_name && c.subject) item.appendChild(Dom.el('div', { style: 'font-size: 13px; color: var(--ink-600);' }, c.subject));
          if (c.last_message_preview) {
            const preview = c.last_message_preview.length > 60 ? c.last_message_preview.slice(0, 60) + '…' : c.last_message_preview;
            item.appendChild(Dom.el('div', { style: 'font-size: 12px; color: var(--ink-500); margin-top: 4px;' }, preview));
          }
          item.addEventListener('click', () => {
            selected = c;
            renderList();
            loadThread(c);
          });
          list.appendChild(item);
        });
      };

      const loadThread = async (convo) => {
        Dom.clear(thread);
        thread.appendChild(Dom.el('p', { style: 'padding: 16px; color: var(--ink-500);' }, 'Loading messages…'));
        try {
          const msgData = await Api.get('/parent/messages/' + convo.id);
          Dom.clear(thread);

          const msgList = Dom.el('div', { style: 'flex: 1; overflow-y: auto; padding: 16px; display: flex; flex-direction: column; gap: 8px;' });
          thread.appendChild(msgList);

          /* Drawn by a function so a send can refresh the messages alone (2026-11-11): the whole thread, box
             included, used to be rebuilt after every send, which is what made the next message wait. */
          const drawMsgs = (list) => { Dom.clear(msgList); (list || []).forEach(m => {
            const mine = m.is_mine;
            const bubble = Dom.el('div', {
              style: `max-width: 70%; padding: 10px 14px; border-radius: 16px; ${mine ? 'align-self: flex-end; background: #E1F3F6; color: #0D1B2A; border-bottom-right-radius: 4px;' : 'align-self: flex-start; background: var(--ink-100); color: var(--ink-900); border-bottom-left-radius: 4px;'}`,
            });
            if (!mine) {
              bubble.appendChild(Dom.el('div', { style: 'font-size: 11px; font-weight: 700; opacity: 0.8; margin-bottom: 4px;' }, m.sender_name));
            }
            if (m.is_emergency && window.KT && KT.emergency) bubble.insertBefore(KT.emergency.tagEl(), bubble.firstChild);
            bubble.appendChild(Dom.el('div', {}, m.body));
            const tm = Dom.el('div', { style: 'font-size: 11px; opacity: 0.7; margin-top: 4px;' }, m.time_display);
            // ⋯ on a message from the centre: report it, or block the person (kt-report-block.js, 2026-11-03).
            if (!mine && !m.deleted && !m.is_system && m.sender_id && window.KT && KT.reportBlock) {
              tm.appendChild(KT.reportBlock.btnEl({ kind: 'family', conv: convo.id, mid: m.id, uid: m.sender_id, name: m.sender_name, blocked: m.blocked }));
            }
            bubble.appendChild(tm);
            msgList.appendChild(bubble);
          }); };
          drawMsgs(msgData.messages);
          msgList.scrollTop = msgList.scrollHeight;

          // Compose box — send routes by child_id (find-or-create per child).
          const childId = convo.child_id || state.selectedChildId;
          /* A photo is often the whole message - a rash, a lost jumper, a permission
             slip. The mobile thread has had a camera button for a while; the desktop one
             was text-only, and sendParentMessage() already accepts a file, so this was a
             missing control rather than missing capability. */
          const composeWrap = Dom.el('div', { style: 'border-top: 1px solid var(--ink-200);' });
          const attachBar = Dom.el('div', { style: 'display:none;padding:8px 12px 0;' });
          const compose = Dom.el('form', { class: 'kt-compose-row', style: 'padding: 10px 12px; display: flex; gap: 6px; align-items: center;' });
          // 👋 beside the ＋ (kt-compose-plus.js): the centre team's phones ring (App\\Support\\ChatNudge).
          if (childId) { compose.setAttribute('data-kt-nudge', '/parent/messages/nudge'); compose.setAttribute('data-kt-nudge-body', JSON.stringify({ child_id: childId })); }
          const fileInput = Dom.el('input', { class: 'kt-desk-attach', type: 'file', accept: 'image/*', multiple: 'multiple', style: 'display:none;' });
          const attachBtn = Dom.el('button', {
            type: 'button', title: 'Attach a photo', 'aria-label': 'Attach a photo',
            style: 'background:transparent;border:none;cursor:pointer;font-size:22px;padding:4px 6px;line-height:1;flex-shrink:0;',
          }, '\u{1F4F7}');
          const input = Dom.el('input', { class: 'kt-compose-input', type: 'text', placeholder: childId ? 'Type a message…' : 'Read-only conversation', style: 'flex: 1; padding: 13px 16px; border: 1px solid var(--ink-300); border-radius: 24px; font-size: 16px;' });
          // The ONE send button (kt-chat-composer.css): faded until there is something to send.
          const sendBtn = Dom.el('button', { type: 'submit', class: 'kt-send-one', title: 'Send', 'aria-label': 'Send' }, '➤');
          if (!childId) { input.disabled = true; sendBtn.disabled = true; attachBtn.disabled = true; }

          let deskFile = null, deskMore = [], deskChain = Promise.resolve(), deskPending = [];   // deskMore: 2nd+ photos picked together
          const syncSend = () => { if (childId) sendBtn.disabled = !input.value.trim() && !deskFile; };
          input.addEventListener('input', syncSend);
          const clearFile = () => {
            deskFile = null; deskMore = []; fileInput.value = '';
            Dom.clear(attachBar); attachBar.style.display = 'none';
            syncSend();
          };
          attachBtn.addEventListener('click', () => fileInput.click());
          // One chip drawer: a picked photo, and a photo put back after a send that failed.
          const setFile = (f) => {
            deskFile = f;
            Dom.clear(attachBar);
            const chip = Dom.el('div', {
              style: 'display:inline-flex;align-items:center;gap:8px;background:#F1F5F9;border:1px solid var(--ink-200);'
                + 'border-radius:10px;padding:6px 10px;font-size:12px;max-width:100%;',
            });
            const img = Dom.el('img', { style: 'width:34px;height:34px;object-fit:cover;border-radius:6px;display:block;' });
            img.src = URL.createObjectURL(deskFile);
            chip.appendChild(img);
            chip.appendChild(Dom.el('span', { style: 'overflow:hidden;text-overflow:ellipsis;white-space:nowrap;max-width:220px;' }, deskFile.name));
            const rm = Dom.el('button', { type: 'button', 'aria-label': 'Remove photo',
              style: 'background:none;border:none;color:#DC2626;cursor:pointer;font-size:14px;line-height:1;' }, '\u2715');
            rm.addEventListener('click', clearFile);
            chip.appendChild(rm);
            attachBar.appendChild(chip);
            attachBar.style.display = 'block';
            syncSend();
          };
          fileInput.addEventListener('change', () => {
            const all = Array.prototype.slice.call(fileInput.files || [], 0, 10);
            if (!all.length) return;
            setFile(all[0]);
            deskMore = all.slice(1);
            if (deskMore.length) {
              const nm = attachBar.querySelector('span');
              if (nm) nm.textContent = all[0].name + ' + ' + deskMore.length + ' more';
            }
          });

          // THE ONE COMPOSER ROW (.kt-compose-row, kt-chat-composer.css): tools, the box, the ➤ straight after it.
          compose.appendChild(fileInput);
          compose.appendChild(attachBtn);
          const deskEm = (window.KT && KT.emergency) ? KT.emergency.button() : null;
          if (deskEm) compose.appendChild(deskEm);
          compose.appendChild(input);
          compose.appendChild(sendBtn);
          /* SEND AS FAST AS YOU TYPE (2026-11-11, Anthony: "make this real time to send multiple messages quickly").
             The box and photo clear on tap, a faded bubble shows at once, and the next message can follow straight
             away: sends queue (deskChain) in the order tapped. When the last one is in, only the messages refresh. */
          const sendDesk = () => {
            // A photo on its own is a message; it does not need a caption.
            const text = input.value.trim(), file = deskFile;
            if ((!text && !file) || !childId) return;
            const em = !!(deskEm && KT.emergency.isOn(deskEm));
            if (deskEm) KT.emergency.reset(deskEm);
            input.value = '';
            clearFile();
            try { input.focus(); } catch (e0) {}
            const ghost = Dom.el('div', { 'data-kt-pending': '1', style: 'max-width: 70%; padding: 10px 14px; border-radius: 16px; align-self: flex-end; background: #E1F3F6; color: #0D1B2A; border-bottom-right-radius: 4px; opacity: .6;' },
              text || '📷 Photo · sending…');
            msgList.appendChild(ghost);
            msgList.scrollTop = msgList.scrollHeight;
            deskPending.push(ghost);
            sendBtn.setAttribute('aria-busy', 'true');
            deskChain = deskChain.then(async () => {
              try {
                await sendParentMessage(childId, text, file, em, convo.channel === 'office' ? 'office' : null);
                ghost.style.opacity = '';
              } catch (e) {
                if (ghost.parentNode) ghost.parentNode.removeChild(ghost);
                if (text) input.value = text + (input.value ? ' ' + input.value : '');
                if (file && !deskFile) setFile(file);
                input.placeholder = 'Could not send — try again';
                syncSend();
              }
              deskPending.splice(deskPending.indexOf(ghost), 1);
              if (deskPending.length) return;
              sendBtn.removeAttribute('aria-busy');
              bust('/parent/messages');
              try {
                const fresh = await Api.get('/parent/messages/' + convo.id);
                if (!msgList.isConnected) return;
                const atBottom = msgList.scrollTop + msgList.clientHeight >= msgList.scrollHeight - 80;
                drawMsgs(fresh.messages);
                deskPending.forEach(g => msgList.appendChild(g));   // tapped while it refreshed: still last
                if (atBottom) msgList.scrollTop = msgList.scrollHeight;
              } catch (e) {}
            });
          };
          /* SEVERAL PHOTOS AT ONCE (2026-11-13): the words go with the first, then one message per photo, queued in
             the order picked by the same deskChain. */
          compose.addEventListener('submit', (ev) => {
            ev.preventDefault();
            const more = deskMore.splice(0);
            sendDesk();
            more.forEach((mf) => { deskFile = mf; sendDesk(); });
          });
          syncSend();
          composeWrap.appendChild(attachBar);
          composeWrap.appendChild(compose);
          thread.appendChild(composeWrap);
        } catch (e) {
          Dom.clear(thread);
          thread.appendChild(emptyState('⚠️', 'Could not load', e.message));
        }
      };

      renderList();
      loadThread(selected);
    } catch (e) {
      Dom.clear(list);
      list.appendChild(emptyState('⚠️', 'Could not load', e.message));
    }
  }

  // ─── BILLING TAB ────────────────────────────────────────────────

  // iLearn (external) invoices — produced in iLearn, pushed into KiddieTrac
  // (external_invoices) and served by GET /parent/external-invoices. Read-only:
  // they're billed and paid in iLearn, so there's no in-app "pay" action. Rendered
  // alongside any native KiddieTrac invoices. Returns true if it rendered any.
  var _extMoney = (n) => '$' + (Number(n) || 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
  var _extStatusColor = (s) => s === 'paid' ? '#16a34a' : (s === 'overdue' ? '#c0392b' : (s === 'partial' ? '#B45309' : (s === 'scheduled' ? '#4F46E5' : '#1F6FB2')));

  /* An invoice that is not due yet reads "scheduled", not "open" — for a parent that is
     the difference between next month's fees and a demand for money. Derived at display
     time; see KT.invoiceStatus in kt-polish.js. */
  var _extStatusOf = (inv) => {
    if (!inv) return '';
    return (window.KT && KT.invoiceStatus)
      ? KT.invoiceStatus(inv.status, inv.due_at)
      : String(inv.status || '');
  };

  // Build + open a clean, printable invoice (Save-as-PDF via the browser).
  // iLearn doesn't produce a PDF file, so we render one from the invoice data.
  function printExternalInvoice(inv, agency) {
    var esc = function (s) { return String(s == null ? '' : s).replace(/[&<>"]/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]; }); };
    var u = (function () { try { return JSON.parse(sessionStorage.getItem('kt_user') || '{}'); } catch (e) { return {}; } })();
    var parentName = ((u.first_name || '') + ' ' + (u.last_name || '')).trim() || (u.name || '');
    var rows = (inv.items || []).map(function (it) {
      var qty = it.qty != null ? it.qty : 1, price = it.price != null ? it.price : null;
      var amount = it.amount != null ? it.amount : (it.total != null ? it.total : (price != null ? qty * price : 0));
      var note = (it.at || it.by) ? '<div class="sub">' + esc((it.at ? it.at + ' · ' : '') + (it.by ? 'by ' + it.by : '')) + '</div>' : '';
      return '<tr><td>' + esc(it.desc || it.description || 'Item') + note + '</td><td class="c">' + (price != null ? qty : '') + '</td><td class="r">' + (price != null ? _extMoney(price) : '') + '</td><td class="r">' + _extMoney(amount) + '</td></tr>';
    }).join('');
    var html = '<!doctype html><html><head><meta charset="utf-8"><title>Invoice ' + esc(inv.number || '') + '</title><style>'
      + '*{box-sizing:border-box}body{font-family:Arial,Helvetica,sans-serif;color:#1e293b;margin:32px;font-size:13px}'
      + '.hd{display:flex;justify-content:space-between;align-items:flex-start;border-bottom:2px solid #2C7BA0;padding-bottom:14px;margin-bottom:18px}'
      + '.ag{font-size:20px;font-weight:800;color:#2C7BA0}.meta{text-align:right;font-size:12px;color:#475569}'
      + 'h1{font-size:16px;margin:0 0 2px}table{width:100%;border-collapse:collapse;margin-top:6px}'
      + 'th,td{padding:8px 6px;border-bottom:1px solid #e2e8f0;text-align:left;vertical-align:top}th{font-size:11px;text-transform:uppercase;color:#64748b}'
      + '.r{text-align:right}.c{text-align:center}.sub{color:#64748B;font-size:11px;margin-top:2px}'
      + '.tot{margin-top:14px;width:100%;max-width:280px;margin-left:auto}.tot td{border:0;padding:4px 6px}.tot .g td{font-weight:800;font-size:15px;border-top:2px solid #cbd5e1;padding-top:8px}'
      + '.badge{display:inline-block;padding:3px 10px;border-radius:10px;font-size:11px;font-weight:800;text-transform:uppercase;color:#fff;background:' + _extStatusColor(_extStatusOf(inv)) + '}'
      + '@media print{body{margin:12mm}}</style></head><body>'
      + '<div class="hd"><div><div class="ag">' + esc(agency || 'Invoice') + '</div><div style="color:#64748b;font-size:12px;margin-top:2px">Childcare invoice</div></div>'
      + '<div class="meta"><h1>Invoice ' + esc(inv.number || '') + '</h1><div>Issued: ' + esc(inv.issued_at || '—') + '</div><div>Due: ' + esc(inv.due_at || '—') + '</div><div style="margin-top:6px"><span class="badge">' + esc(_extStatusOf(inv)) + '</span></div></div></div>'
      + (parentName ? '<div style="margin-bottom:12px"><div style="font-size:11px;text-transform:uppercase;color:#64748b">Bill to</div><div style="font-weight:700">' + esc(parentName) + '</div></div>' : '')
      + (inv.description ? '<div style="margin-bottom:8px;color:#475569">' + esc(inv.description) + '</div>' : '')
      + '<table><thead><tr><th>Description</th><th class="c">Qty</th><th class="r">Unit</th><th class="r">Amount</th></tr></thead><tbody>' + (rows || '<tr><td colspan="4" style="color:#64748B">No line items</td></tr>') + '</tbody></table>'
      + '<table class="tot"><tr><td>Total</td><td class="r">' + _extMoney(inv.total) + '</td></tr>'
      + (inv.amount_paid > 0 ? '<tr><td>Paid</td><td class="r">−' + _extMoney(inv.amount_paid) + '</td></tr>' : '')
      + '<tr class="g"><td>' + (inv.status === 'paid' ? 'Paid in full' : 'Balance due') + '</td><td class="r">' + _extMoney(inv.balance_due) + '</td></tr></table>'
      + '<div style="margin-top:26px;font-size:11px;color:#64748B">Billed by ' + esc(agency || 'your provider') + '. Please pay directly with them.</div>'
      + '<scr' + 'ipt>window.onload=function(){setTimeout(function(){window.print();},350);};</scr' + 'ipt></body></html>';
    // Keeps the portal alive; see KT.docWindow in kt-doc-viewer.js.
    var w = (window.KT && KT.docWindow)
      ? KT.docWindow({ title: 'Document', label: 'Document' })
      : window.open('', '_blank');
    if (!w) { if (KT.toast) KT.toast('⚠️', 'Pop-up blocked', 'Allow pop-ups to print or save the invoice.', '#B45309'); return; }
    w.document.open(); w.document.write(html); w.document.close();
  }

  // Detail view for one external (iLearn) invoice. opts: {stripeEnabled, onPay}.
  function openExternalInvoice(inv, agency, opts) {
    opts = opts || {};
    var ov = Dom.el('div', { style: 'position:fixed;inset:0;z-index:9600;background:rgba(8,17,33,.55);display:flex;align-items:center;justify-content:center;padding:16px;box-sizing:border-box;' });
    var panel = Dom.el('div', { style: 'background:#fff;width:100%;max-width:520px;max-height:90vh;overflow:auto;border-radius:18px;box-shadow:0 24px 60px -20px rgba(0,0,0,.6);margin:auto;' });
    var paid = inv.status === 'paid';
    var head = Dom.el('div', { style: 'background:linear-gradient(135deg,#4338CA 0%,#6D28D9 55%,#9333EA 120%);color:#fff;padding:18px 20px;position:sticky;top:0;' });
    head.appendChild(Dom.el('div', { style: 'display:flex;justify-content:space-between;align-items:flex-start;gap:10px;' }, [
      Dom.el('div', {}, [
        Dom.el('div', { style: 'font-size:11px;font-weight:800;letter-spacing:.6px;text-transform:uppercase;opacity:.85;' }, agency || 'Invoice'),
        Dom.el('div', { style: 'font-size:18px;font-weight:800;margin-top:2px;' }, inv.number || 'Invoice'),
      ]),
      Dom.el('button', { type: 'button', 'aria-label': 'Close', 'data-close': '1', style: 'background:rgba(255,255,255,.2);color:#fff;border:0;border-radius:50%;width:32px;height:32px;font-size:18px;cursor:pointer;flex:0 0 auto;' }, ['×']),
    ]));
    panel.appendChild(head);
    var body = Dom.el('div', { style: 'padding:18px 20px 26px;' });
    // status + dates
    body.appendChild(Dom.el('div', { style: 'display:flex;gap:8px;align-items:center;margin-bottom:14px;flex-wrap:wrap;' }, [
      Dom.el('span', { style: 'background:' + _extStatusColor(_extStatusOf(inv)) + ';color:#fff;padding:4px 12px;border-radius:12px;font-size:11px;font-weight:800;text-transform:uppercase;' }, _extStatusOf(inv)),
      inv.due_at ? Dom.el('span', { style: 'font-size:13px;color:var(--ink-600,#475569);' }, 'Due ' + inv.due_at) : Dom.el('span', {}),
      inv.issued_at ? Dom.el('span', { style: 'font-size:13px;color:var(--ink-500,#64748b);' }, '· Issued ' + inv.issued_at) : Dom.el('span', {}),
    ]));
    if (inv.description) body.appendChild(Dom.el('div', { style: 'font-size:14px;color:var(--ink-700,#334155);margin-bottom:14px;line-height:1.5;' }, inv.description));
    // line items — with qty × unit price and adjustment notes (date / by whom)
    if (inv.items && inv.items.length) {
      body.appendChild(Dom.el('div', { style: 'font-size:11px;font-weight:800;letter-spacing:.5px;text-transform:uppercase;color:var(--ink-500,#64748b);margin-bottom:6px;' }, 'Line items'));
      var li = Dom.el('div', { style: 'border:1px solid var(--ink-200,#e2e8f0);border-radius:12px;overflow:hidden;margin-bottom:14px;' });
      inv.items.forEach(function (it, i) {
        var qty = it.qty != null ? it.qty : 1;
        var price = it.price != null ? it.price : null;
        var amount = it.amount != null ? it.amount : (it.total != null ? it.total : (price != null ? qty * price : 0));
        var sub = [];
        if (price != null) sub.push(qty + ' × ' + _extMoney(price));
        if (it.adjustment) sub.push('adjustment');
        if (it.at) sub.push(String(it.at));
        if (it.by) sub.push('by ' + it.by);
        li.appendChild(Dom.el('div', { style: 'display:flex;justify-content:space-between;gap:12px;padding:11px 13px;font-size:13.5px;' + (i ? 'border-top:1px solid var(--ink-100,#f1f5f9);' : '') }, [
          Dom.el('div', { style: 'min-width:0;' }, [
            Dom.el('div', { style: 'color:var(--ink-700,#334155);' }, String(it.desc || it.description || it.label || 'Item')),
            sub.length ? Dom.el('div', { style: 'color:var(--ink-500,#64748b);font-size:11.5px;margin-top:2px;' }, sub.join(' · ')) : Dom.el('span', {}),
          ]),
          Dom.el('div', { style: 'font-weight:700;white-space:nowrap;' + (it.adjustment ? 'color:#B45309;' : '') }, _extMoney(amount)),
        ]));
      });
      body.appendChild(li);
    }
    // totals
    var tot = Dom.el('div', { style: 'background:var(--ink-50,#f8fafc);border-radius:12px;padding:14px;display:grid;grid-template-columns:1fr auto;gap:8px;font-size:14px;' });
    tot.appendChild(Dom.el('div', { style: 'color:var(--ink-600,#475569);' }, 'Invoice total'));
    tot.appendChild(Dom.el('div', { style: 'text-align:right;font-weight:700;' }, _extMoney(inv.total)));
    if (inv.amount_paid > 0) {
      tot.appendChild(Dom.el('div', { style: 'color:var(--ink-600,#475569);' }, 'Paid'));
      tot.appendChild(Dom.el('div', { style: 'text-align:right;color:#16a34a;font-weight:700;' }, '−' + _extMoney(inv.amount_paid)));
    }
    tot.appendChild(Dom.el('div', { style: 'font-weight:800;font-size:15px;border-top:1px solid var(--ink-200,#e2e8f0);padding-top:9px;' }, paid ? 'Paid in full' : 'Balance due'));
    tot.appendChild(Dom.el('div', { style: 'text-align:right;font-weight:900;font-size:20px;border-top:1px solid var(--ink-200,#e2e8f0);padding-top:9px;color:' + (paid ? '#16a34a' : '#1F6FB2') + ';' }, _extMoney(inv.balance_due)));
    body.appendChild(tot);
    opts = opts || {};
    var btnBase = 'width:100%;margin-top:12px;border-radius:12px;padding:13px;font-weight:800;font-size:14px;cursor:pointer;display:flex;align-items:center;justify-content:center;gap:8px;';
    // Pay by Interac e-Transfer (2026-10-28) — the agency's own address, this invoice's number.
    if (!paid && (inv.balance_due || 0) > 0 && _ktPayInfo && _ktPayInfo.interac) {
      var _ib = ktInteracButton(_ktPayInfo, [{ number: inv.number, amount: inv.balance_due, kind: 'ext', id: inv.id }], { block: true, label: 'Pay ' + _extMoney(inv.balance_due) + ' by Interac e-Transfer' });
      _ib.style.marginTop = '12px'; _ib.style.height = '46px';
      body.appendChild(_ib);
    }
    // Pay online (Stripe) — only when the agency has it enabled AND there's a balance.
    if (opts.stripeEnabled && !paid && (inv.balance_due || 0) > 0) {
      var payBtn = Dom.el('button', { type: 'button', style: btnBase + 'border:0;color:#fff;background:linear-gradient(135deg,#0FA3B1,#1F6FB2 60%,#2456A6);' }, ['Pay ' + _extMoney(inv.balance_due) + ' now']);
      payBtn.addEventListener('click', () => { if (typeof opts.onPay === 'function') opts.onPay(inv); });
      body.appendChild(payBtn);
    }
    /* The official invoice the billing system issued, fetched through our API rather
       than linked at directly. It used to be an <a> pointing straight at the signed
       source URL: that worked, and the parent did get the real document, but the link
       authenticates by itself and never expires, so it survived in browser histories
       and could be forwarded by anyone who came across it. Now the URL stays on the
       server and the request is checked against who is asking. */
    if (inv.has_document || inv.pdf_url) {
      var pdfBtn = Dom.el('button', { type: 'button', style: btnBase + 'border:0;color:#fff;background:#2C7BA0;' }, [
        Dom.el('span', { style: 'font-size:16px;' }, '📄'), Dom.el('span', {}, 'View / download official invoice'),
      ]);
      pdfBtn.addEventListener('click', function () { openOfficialInvoice(inv, pdfBtn); });
      body.appendChild(pdfBtn);
    }
    /* Fetched with the token, because the route is authenticated and a plain href
       cannot carry the header. Opened as a blob so the document arrives exactly as the
       billing system rendered it — its logo is a data: URI and its styles are inline,
       so nothing external has to resolve. */
    async function openOfficialInvoice(i, btn) {
      var label = btn.textContent;
      btn.disabled = true; btn.textContent = 'Opening…';
      try {
        var t; try { t = sessionStorage.getItem('kt_token') || localStorage.getItem('kt_token'); } catch (e) {}
        var base = (window.KT_CONFIG && window.KT_CONFIG.apiBase) || 'https://api.kiddietrac.com/api/v1';
        var h = { Authorization: 'Bearer ' + t };
        // Staff opening a family's invoice are authorised against their ACTIVE agency,
        // so the header has to travel or the check cannot be made.
        try { var aid = sessionStorage.getItem('kt_active_agency_id'); if (aid) h['X-Active-Agency-Id'] = aid; } catch (e) {}
        var r = await fetch(base + '/invoices/external/' + i.id + '/document', { headers: h });
        if (!r.ok) throw new Error('HTTP ' + r.status);
        var invBlob = await r.blob();
        if (window.KT && KT.openFile) { KT.openFile(invBlob, { title: 'Invoice', filename: 'invoice.pdf', label: 'Invoice' }); }
        else { var url = URL.createObjectURL(invBlob); window.open(url, '_blank'); setTimeout(function () { URL.revokeObjectURL(url); }, 60000); }
      } catch (e) {
        // Quick print below still works, so say what happened and leave them a way on.
        if (KT.Dom && KT.Dom.toast) KT.Dom.toast('Could not open the official invoice — use Quick print instead', 'error');
      }
      btn.disabled = false; btn.textContent = label;
    }

    // Local quick-print fallback (works even if the official link is unavailable).
    var printBtn = Dom.el('button', { type: 'button', style: btnBase + 'background:#fff;border:1.6px solid #cbd5e1;color:#475569;' }, [
      Dom.el('span', { style: 'font-size:16px;' }, '🖨'), Dom.el('span', {}, inv.pdf_url ? 'Quick print' : 'Print / Save as PDF'),
    ]);
    printBtn.addEventListener('click', () => printExternalInvoice(inv, agency));
    body.appendChild(printBtn);
    body.appendChild(Dom.el('div', { style: 'margin-top:12px;font-size:12px;color:var(--ink-500,#64748b);text-align:center;' }, 'Billed by ' + (agency || 'your provider') + (opts.stripeEnabled ? '' : ' · pay directly with them')));
    panel.appendChild(body);
    ov.appendChild(panel);
    document.body.appendChild(ov);
    var close = () => ov.remove();
    ov.addEventListener('click', (e) => { if (e.target === ov) close(); });
    panel.querySelector('[data-close]').addEventListener('click', close);
  }


  /* ═══ PAY BY INTERAC e-TRANSFER + "DUE NOW" (2026-10-28) ═══════════════════════════════════════════════════════
     Anthony: "a interac etransfer button should show up with method of payment and once clicked it shows the ilearn
     email address for the interac payment and references the invoice number and instructions on how to pay using
     interac etransfer (use the interac logo where you can)" and "the total due bar is at the bottom on this page for
     parents ... only show whats due now and the future dates/invoices can be hidden until they come up to due 2 weeks
     in advance".

     The address and the window come from the server (pay_info / due_now - App\Support\ParentBilling, set per agency
     in Billing setup). No address means no button: a parent is never shown a guessed address to send money to. The
     invoice number goes in the e-Transfer message because that is how the payment is matched to the invoice. */
  var _ktPayInfo = null;
  var INTERAC_LOGO = '/img/interac-logo.svg';
  var _m2 = function (n) { return '$' + (Number(n) || 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 }); };

  function ktCopy(text, btn) {
    var done = function () {
      var was = btn.textContent; btn.textContent = 'Copied ✓'; btn.style.color = '#166534';
      setTimeout(function () { btn.textContent = was; btn.style.color = ''; }, 1600);
    };
    try {
      if (navigator.clipboard && navigator.clipboard.writeText) { navigator.clipboard.writeText(text).then(done, function () {}); return; }
    } catch (e) {}
    try {
      var ta = document.createElement('textarea'); ta.value = text; ta.setAttribute('readonly', '');
      ta.style.cssText = 'position:fixed;left:-9999px;top:0;'; document.body.appendChild(ta); ta.select();
      document.execCommand('copy'); ta.remove(); done();
    } catch (e) {}
  }

  /* items: [{number, amount}] - the invoices being paid. */
  function ktOpenInterac(pay, items) {
    if (!pay || !pay.interac) { return; }
    items = (items || []).filter(function (i) { return Number(i.amount) > 0.004; });
    var total = items.reduce(function (s, i) { return s + Number(i.amount); }, 0);
    var memo = items.map(function (i) { return i.number; }).filter(Boolean).join(', ');
    var who = pay.interac.recipient || pay.agency_name || 'your centre';

    var ov = Dom.el('div', { class: 'kt-interac-sheet', style: 'position:fixed;inset:0;z-index:9700;background:rgba(8,17,33,.55);display:flex;align-items:center;justify-content:center;padding:16px;box-sizing:border-box;' });
    var panel = Dom.el('div', { role: 'dialog', 'aria-label': 'Pay by Interac e-Transfer', style: 'background:#fff;width:100%;max-width:460px;max-height:calc(100vh - 32px);overflow:auto;border-radius:18px;box-shadow:0 24px 60px -20px rgba(0,0,0,.6);' });
    var head = Dom.el('div', { style: 'display:flex;align-items:center;gap:12px;padding:14px 16px;background:linear-gradient(135deg,#0B2545,#13315C);color:#fff;position:sticky;top:0;z-index:1;' });
    head.appendChild(Dom.el('img', { src: INTERAC_LOGO, alt: 'Interac', width: '40', height: '40', style: 'width:40px;height:40px;border-radius:8px;flex:0 0 auto;background:#fff;' }));
    head.appendChild(Dom.el('div', { style: 'flex:1;min-width:0;' }, [
      Dom.el('div', { style: 'font-weight:800;font-size:16px;line-height:1.2;' }, 'Pay by Interac e-Transfer'),
      Dom.el('div', { style: 'font-size:12px;opacity:.8;margin-top:2px;' }, 'From your own online or mobile banking'),
    ]));
    var x = Dom.el('button', { type: 'button', 'aria-label': 'Close', style: 'background:rgba(255,255,255,.18);color:#fff;border:0;border-radius:50%;width:32px;height:32px;font-size:18px;cursor:pointer;flex:0 0 auto;' }, '×');
    head.appendChild(x);
    panel.appendChild(head);

    var body = Dom.el('div', { style: 'padding:16px;' });
    function field(label, value, copyText, strong, note) {
      // data-i18n-ctx: "Answer" here is the security answer, not the verb (kt-i18n context key interac::Answer).
      var row = Dom.el('div', { 'data-i18n-ctx': 'interac', style: 'border:1px solid #E2E8F0;border-radius:12px;padding:10px 12px;margin-bottom:10px;' + (strong ? 'background:#FFFBEB;border-color:#FCD34D;' : '') });
      var top = Dom.el('div', { style: 'display:flex;align-items:center;gap:10px;' });
      top.appendChild(Dom.el('div', { style: 'flex:1;min-width:0;' }, [
        Dom.el('div', { style: 'font-size:11px;font-weight:800;letter-spacing:.5px;text-transform:uppercase;color:#64748B;' }, label),
        Dom.el('div', { style: 'font-size:15px;font-weight:800;color:#0F172A;word-break:break-all;margin-top:2px;' }, value),
      ]));
      if (copyText) {
        var c = Dom.el('button', { type: 'button', style: 'flex:0 0 auto;height:30px;padding:0 12px;border-radius:8px;border:1px solid #CBD5E1;background:#fff;color:#1F6080;font-weight:700;font-size:12.5px;cursor:pointer;' }, 'Copy');
        c.addEventListener('click', function () { ktCopy(copyText, c); });
        top.appendChild(c);
      }
      row.appendChild(top);
      if (note) { row.appendChild(Dom.el('div', { style: 'font-size:12px;color:#92400E;margin-top:5px;line-height:1.45;' }, note)); }
      body.appendChild(row);
    }
    field('Send to (e-Transfer email)', pay.interac.email, pay.interac.email);
    field('Recipient name', who, null);
    field('Amount', _m2(total), total.toFixed(2));
    // Security question (2026-11-11, Payment providers -> Interac e-Transfer): only when the agency has no Autodeposit.
    if (pay.interac.question) {
      field('Security question', pay.interac.question, null);
      field('Answer', pay.interac.answer || '', pay.interac.answer || '');
    }
    if (memo) {
      field('Message (required)', memo, memo, true,
        'Type the invoice number' + (items.length > 1 ? 's' : '') + ' in the e-Transfer message. That is how ' + (pay.agency_name || 'your centre') + ' matches your payment to your invoice.');
    }
    if (items.length > 1) {
      var list = Dom.el('div', { style: 'font-size:12.5px;color:#475569;margin:-2px 2px 12px;line-height:1.6;' });
      items.forEach(function (i) { list.appendChild(Dom.el('div', { style: 'display:flex;justify-content:space-between;gap:8px;' }, [Dom.el('span', {}, i.number), Dom.el('span', { style: 'font-weight:700;' }, _m2(i.amount))])); });
      body.appendChild(list);
    }

    var steps = [
      'Sign in to your bank’s app or online banking.',
      'Choose Interac e-Transfer, then Send money.',
      'Add ' + who + ' as a recipient using ' + pay.interac.email + '.',
      'Enter ' + _m2(total) + (memo ? ' and type ' + memo + ' in the message' : '') + '.',
    ];
    if (pay.interac.question) { steps.push('When your bank asks, use the security question and answer above.'); }
    else if (pay.interac.autodeposit) { steps.push('Autodeposit is on, so your bank will not ask for a security question.'); }
    steps.push('Review and send.');
    var ol = Dom.el('ol', { style: 'margin:4px 0 12px;padding-left:20px;font-size:13.5px;color:#334155;line-height:1.6;' });
    steps.forEach(function (t) { ol.appendChild(Dom.el('li', {}, t)); });
    body.appendChild(Dom.el('div', { style: 'font-weight:800;font-size:13px;color:#0F172A;margin-top:4px;' }, 'How to pay'));
    body.appendChild(ol);
    // The agency's own instructions, as it wrote them.
    if (pay.interac.instructions) {
      body.appendChild(Dom.el('div', { style: 'border:1px solid #BFDBFE;background:#EFF6FF;border-radius:10px;padding:10px 12px;margin:0 0 10px;' }, [
        Dom.el('div', { style: 'font-size:11px;font-weight:800;letter-spacing:.5px;text-transform:uppercase;color:#1D4ED8;margin-bottom:3px;' }, 'From ' + (pay.agency_name || 'your centre')),
        Dom.el('div', { style: 'font-size:13.5px;color:#1E293B;line-height:1.5;white-space:pre-line;' }, pay.interac.instructions),
      ]));
    }
    body.appendChild(Dom.el('div', { style: 'font-size:12.5px;color:#475569;background:#F1F5F9;border-radius:10px;padding:10px 12px;line-height:1.5;' },
      'Your invoice shows as paid once ' + (pay.agency_name || 'your centre') + ' receives the e-Transfer, usually within one business day.'));
    ktInteracReport(pay, items, body);
    ktInteracRequest(pay, items, body);
    body.appendChild(Dom.el('div', { style: 'font-size:10.5px;color:#94A3B8;margin-top:12px;text-align:center;' }, 'Interac and Interac e-Transfer are registered trade-marks of Interac Corp.'));
    panel.appendChild(body);
    ov.appendChild(panel);

    function close() { document.removeEventListener('keydown', onKey); if (window.KT && KT.popOverlay) { KT.popOverlay(ov); } else { ov.remove(); } }
    function onKey(e) { if (e.key === 'Escape') { close(); } }
    document.addEventListener('keydown', onKey);
    x.addEventListener('click', close);
    ov.addEventListener('click', function (e) { if (e.target === ov) { close(); } });
    document.body.appendChild(ov);
    if (window.KT && KT.pushOverlay) { KT.pushOverlay(ov); }
  }

  /* ═══ "SEND ME AN e-TRANSFER REQUEST" (2026-10-30) ═══════════════════════════════════════════════════════════
     Anthony: "...a button that also allows them to pay by etransfer which can popup the etransfer instructions and or
     send a request to have the etransfer link sent to them by the service provider".

     Under the e-Transfer details: the family can instead ask the centre to send them an Interac e-Transfer REQUEST
     ("Request money") - their bank then shows a ready-made payment to accept. It goes to one of the parent's OWN
     contacts on file (never a typed address), one open request per invoice (EtransferRequestController). The centre
     sends it from its own bank and marks it sent; the parent is told to look for it; the payment is recorded when
     it arrives. On the website demo the bank is simulated on the demo phone. */
  var _ktEtr = null;   // { requests, contacts } - this family's, refreshed after every change
  var _ktEtrP = null;  // the load in flight, shared by every Interac button drawn at once
  function ktEtrLoad(force) {
    if (_ktEtr && !force) { return Promise.resolve(_ktEtr); }
    if (_ktEtrP && !force) { return _ktEtrP; }
    _ktEtrP = Api.get('/parent/etransfer-requests').then(function (r) { _ktEtr = r || { requests: [], contacts: [] }; _ktEtrP = null; return _ktEtr; },
      function (e) { _ktEtrP = null; throw e; });
    return _ktEtrP;
  }
  function ktEtrWhen(s) {
    var d = s ? new Date(String(s).replace(' ', 'T')) : null;
    return d && !isNaN(d) ? d.toLocaleDateString(undefined, { month: 'short', day: 'numeric' }) : '';
  }
  function ktEtrStatus(req, centre) {
    // The server labels times on the agency's clock ("Oct 30, 8:44 AM"); the date part is enough here.
    var at = String((req.status === 'sent' ? req.sent_label : req.requested_label) || '').replace(/,.*$/, '');
    if (req.status === 'sent') {
      return { icon: '📨', tone: '#065F46', bg: '#ECFDF5', br: '#A7F3D0', title: 'Request sent' + (at ? ' · ' + at : ''),
        text: centre + ' sent you an Interac e-Transfer request for ' + _m2(req.amount) + '. Check your bank app or ' + (req.contact_type === 'email' ? 'email (' + req.contact + ')' : 'texts (' + req.contact + ')') + ' and accept it.' };
    }
    return { icon: '⏳', tone: '#1E40AF', bg: '#EFF6FF', br: '#BFDBFE', title: 'Requested' + (at ? ' · ' + at : ''),
      text: centre + ' will send an Interac e-Transfer request for ' + _m2(req.amount) + ' to ' + req.contact + '. You will get a notification when it is on its way.' };
  }
  function ktEtrOpenFor(items) {
    var keys = (items || []).map(function (i) { return i.kind + ':' + i.id; });
    return ((_ktEtr && _ktEtr.requests) || []).filter(function (r) {
      return (r.status === 'requested' || r.status === 'sent') && (r.items || []).some(function (it) { return keys.indexOf(it.kind + ':' + it.id) !== -1; });
    })[0] || null;
  }
  /* ═══ "I'VE SENT IT" (2026-11-11) ═══════════════════════════════════════════════════════════════════════
     Anthony: "add ability to add reference number when parent processes a payment through interac etransfer and mark
     that invoice paid which will trigger an alert/email to admin/director to confirm/approve". He chose "pending until
     approved": the parent enters the bank's reference number and the amount sent (all or part); the invoice says
     "e-Transfer sent, waiting for the centre to confirm" and is paid once a person finds it in the bank
     (POST /parent/etransfer-reports; EtransferRequestController::report). Reports come back in the same list as the
     requests, with kind 'report'. */
  function ktEtrReportFor(items) {
    var keys = (items || []).map(function (i) { return i.kind + ':' + i.id; });
    var rows = ((_ktEtr && _ktEtr.requests) || []).filter(function (r) {
      return r.kind === 'report' && (r.items || []).some(function (it) { return keys.indexOf(it.kind + ':' + it.id) !== -1; });
    });   // newest first
    return { pending: rows.filter(function (r) { return r.status === 'reported'; })[0] || null, latest: rows[0] || null };
  }
  function ktInteracReport(pay, items, body) {
    items = (items || []).filter(function (i) { return i.id && (i.kind === 'inv' || i.kind === 'ext') && Number(i.amount) > 0.004; });
    if (!items.length) { return; }
    var centre = pay.agency_name || 'your centre';
    var total = items.reduce(function (s, i) { return s + Number(i.amount); }, 0);
    var box = Dom.el('div', { style: 'margin-top:14px;border-top:1px solid #E2E8F0;padding-top:14px;' });
    body.appendChild(box);
    var INP = 'width:100%;box-sizing:border-box;height:40px;padding:0 12px;border:1px solid #CBD5E1;border-radius:10px;font-size:15px;color:#0F172A;background:#fff;';
    function paint() {
      Dom.clear(box);
      var st = ktEtrReportFor(items);
      box.appendChild(Dom.el('div', { style: 'font-weight:800;font-size:13px;color:#0F172A;margin-bottom:4px;' }, 'Already sent it?'));
      if (st.pending) {
        var p = st.pending;
        box.appendChild(Dom.el('div', { style: 'border:1px solid #FCD34D;background:#FFFBEB;color:#92400E;border-radius:12px;padding:10px 12px;font-size:13px;line-height:1.5;' }, [
          Dom.el('div', { style: 'font-weight:800;' }, '⏳ Waiting for ' + centre + ' to confirm'),
          Dom.el('div', { style: 'margin-top:2px;' }, 'You sent ' + _m2(p.amount) + (p.reported_label ? ' on ' + String(p.reported_label).replace(/,.*$/, '') : '') + ' · reference ' + (p.reference || '')
            + '. Your invoice shows as paid once they find it in their bank.')]));
        var wd = Dom.el('button', { type: 'button', style: 'margin-top:8px;height:32px;padding:0 12px;border-radius:9px;border:1px solid #CBD5E1;background:#fff;color:#475569;font-weight:700;font-size:12.5px;cursor:pointer;' }, 'I made a mistake - withdraw this');
        wd.addEventListener('click', function () {
          wd.disabled = true;
          Api.post('/parent/etransfer-requests/' + p.id + '/cancel', {}).then(function () { return ktEtrLoad(true); }).then(paint)
            .catch(function (e) { wd.disabled = false; if (KT.toast) { KT.toast('⚠️', 'Could not withdraw it', (e && e.message) || '', '#B91C1C'); } });
        });
        box.appendChild(wd);
        return;
      }
      if (st.latest && st.latest.status === 'rejected') {
        box.appendChild(Dom.el('div', { style: 'border:1px solid #FECACA;background:#FEF2F2;color:#991B1B;border-radius:12px;padding:10px 12px;font-size:13px;line-height:1.5;margin-bottom:10px;' }, [
          Dom.el('div', { style: 'font-weight:800;' }, '⚠️ Not confirmed: reference ' + (st.latest.reference || '')),
          Dom.el('div', { style: 'margin-top:2px;' }, (st.latest.reject_reason || 'The centre could not find it.') + ' Check your bank, then send the right reference below.')]));
      }
      box.appendChild(Dom.el('div', { style: 'font-size:13px;color:#475569;line-height:1.5;margin-bottom:10px;' },
        'Once your bank shows the e-Transfer as sent, enter its reference number so ' + centre + ' can match it. Your invoice stays open until they confirm it.'));
      var ref = Dom.el('input', { type: 'text', maxlength: '40', autocomplete: 'off', autocapitalize: 'off', spellcheck: 'false', placeholder: 'e.g. C1AbCdEfGhIj', 'aria-label': 'e-Transfer reference number', style: INP });
      var amt = Dom.el('input', { type: 'number', min: '0.01', step: '0.01', max: total.toFixed(2), inputmode: 'decimal', 'aria-label': 'Amount you sent', style: INP });
      amt.value = total.toFixed(2);
      var lab = function (t) { return Dom.el('div', { style: 'font-size:11px;font-weight:800;letter-spacing:.5px;text-transform:uppercase;color:#64748B;margin:0 0 4px;' }, t); };
      box.appendChild(lab('Reference number'));
      box.appendChild(ref);
      box.appendChild(Dom.el('div', { style: 'font-size:12px;color:#64748B;margin:4px 0 10px;' }, 'It is on your bank’s confirmation screen and in the confirmation email.'));
      box.appendChild(lab('Amount you sent'));
      box.appendChild(amt);
      box.appendChild(Dom.el('div', { style: 'font-size:12px;color:#64748B;margin:4px 0 10px;' }, 'Paid part of it? Enter what you sent; the rest stays owing.'));
      var msg = Dom.el('div', { style: 'font-size:12.5px;color:#B91C1C;min-height:0;margin-bottom:6px;' });
      box.appendChild(msg);
      var go = Dom.el('button', { type: 'button', style: 'width:100%;height:44px;border-radius:11px;border:0;background:linear-gradient(135deg,#1B3A63,#2F8C86);color:#fff;font-weight:800;font-size:14px;cursor:pointer;' }, 'I’ve sent it - tell ' + centre);
      go.addEventListener('click', function () {
        var r = String(ref.value || '').replace(/\s+/g, '');
        var a = Math.round(Number(amt.value) * 100) / 100;
        msg.textContent = '';
        if (!/^[A-Za-z0-9-]{4,40}$/.test(r)) { msg.textContent = 'Enter the reference number exactly as your bank shows it.'; ref.focus(); return; }
        if (!(a >= 0.01) || a > Math.round(total * 100) / 100 + 0.004) { msg.textContent = 'Enter an amount up to ' + _m2(total) + '.'; amt.focus(); return; }
        go.disabled = true; go.textContent = 'Sending…';
        Api.post('/parent/etransfer-reports', { items: items.map(function (i) { return { kind: i.kind, id: i.id }; }), reference: r, amount: a })
          .then(function () { return ktEtrLoad(true); })
          .then(function () { paint(); if (KT.toast) { KT.toast('💸', 'Sent to ' + centre, 'They will confirm it once it shows in their bank.', '#1F6080'); } })
          .catch(function (e) {
            go.disabled = false; go.textContent = 'I’ve sent it - tell ' + centre;
            msg.textContent = (e && e.message) || 'Could not send it. Please try again.';
            ktEtrLoad(true).then(function () { if (ktEtrReportFor(items).pending) { paint(); } });
          });
      });
      box.appendChild(go);
    }
    box.appendChild(Dom.el('div', { style: 'font-size:12.5px;color:#94A3B8;' }, 'Loading…'));
    ktEtrLoad(true).then(paint, function () { Dom.clear(box); });
  }
  function ktInteracRequest(pay, items, body) {
    if (!pay || !pay.interac || !pay.interac.requests) { return; }
    items = (items || []).filter(function (i) { return i.id && (i.kind === 'inv' || i.kind === 'ext') && Number(i.amount) > 0.004; });
    if (!items.length) { return; }
    var centre = pay.agency_name || 'your centre';
    var total = items.reduce(function (s, i) { return s + Number(i.amount); }, 0);
    var box = Dom.el('div', { style: 'margin-top:14px;border-top:1px solid #E2E8F0;padding-top:14px;' });
    body.appendChild(box);
    function paint() {
      Dom.clear(box);
      var open = ktEtrOpenFor(items);
      box.appendChild(Dom.el('div', { style: 'font-weight:800;font-size:13px;color:#0F172A;margin-bottom:4px;' }, 'Or have ' + centre + ' request it'));
      if (open) {
        var st = ktEtrStatus(open, centre);
        box.appendChild(Dom.el('div', { style: 'border:1px solid ' + st.br + ';background:' + st.bg + ';color:' + st.tone + ';border-radius:12px;padding:10px 12px;font-size:13px;line-height:1.5;' }, [
          Dom.el('div', { style: 'font-weight:800;' }, st.icon + ' ' + st.title), Dom.el('div', { style: 'margin-top:2px;' }, st.text)]));
        var cx = Dom.el('button', { type: 'button', style: 'margin-top:8px;height:32px;padding:0 12px;border-radius:9px;border:1px solid #CBD5E1;background:#fff;color:#475569;font-weight:700;font-size:12.5px;cursor:pointer;' }, 'Cancel the request');
        cx.addEventListener('click', function () {
          cx.disabled = true;
          Api.post('/parent/etransfer-requests/' + open.id + '/cancel', {}).then(function () { return ktEtrLoad(true); }).then(paint)
            .catch(function (e) { cx.disabled = false; if (KT.toast) { KT.toast('⚠️', 'Could not cancel', (e && e.message) || '', '#B91C1C'); } });
        });
        box.appendChild(cx);
        return;
      }
      box.appendChild(Dom.el('div', { style: 'font-size:13px;color:#475569;line-height:1.5;margin-bottom:10px;' },
        'Prefer a ready-made payment in your bank app? ' + centre + ' can send you an Interac e-Transfer request for ' + _m2(total) + ' - you just review and accept it.'));
      var ask = Dom.el('button', { type: 'button', style: 'width:100%;height:44px;border-radius:11px;border:1.5px solid #1F6080;background:#fff;color:#1F6080;font-weight:800;font-size:14px;cursor:pointer;display:flex;align-items:center;justify-content:center;gap:8px;' },
        [Dom.el('span', {}, '📨'), Dom.el('span', {}, 'Ask for an e-Transfer request')]);
      ask.addEventListener('click', confirmStep);
      box.appendChild(ask);
    }
    function confirmStep() {
      Dom.clear(box);
      box.appendChild(Dom.el('div', { style: 'font-weight:800;font-size:13px;color:#0F172A;margin-bottom:6px;' }, 'Where should ' + centre + ' send the request?'));
      var contacts = (_ktEtr && _ktEtr.contacts) || [];
      if (!contacts.length) {
        box.appendChild(Dom.el('div', { style: 'font-size:13px;color:#92400E;background:#FFFBEB;border:1px solid #FCD34D;border-radius:10px;padding:10px 12px;' }, 'Add your email or mobile number in My settings first.'));
        return;
      }
      var pick = 0;
      var list = Dom.el('div', { role: 'radiogroup', style: 'display:flex;flex-direction:column;gap:6px;margin-bottom:10px;' });
      contacts.forEach(function (c, i) {
        var r = Dom.el('label', { style: 'display:flex;align-items:center;gap:10px;border:1px solid #E2E8F0;border-radius:10px;padding:9px 12px;cursor:pointer;font-size:13.5px;color:#0F172A;' });
        var inp = Dom.el('input', { type: 'radio', name: 'kt-etr-contact', value: String(i) });
        if (i === 0) { inp.checked = true; }
        inp.addEventListener('change', function () { pick = i; });
        r.appendChild(inp);
        r.appendChild(Dom.el('span', { style: 'flex:1;min-width:0;word-break:break-all;' }, (c.type === 'email' ? '✉️ ' : '📱 ') + c.label));
        list.appendChild(r);
      });
      box.appendChild(list);
      var sumBox = Dom.el('div', { style: 'font-size:12.5px;color:#475569;background:#F8FAFC;border-radius:10px;padding:9px 12px;margin-bottom:10px;line-height:1.55;' });
      items.forEach(function (i) { sumBox.appendChild(Dom.el('div', { style: 'display:flex;justify-content:space-between;gap:8px;' }, [Dom.el('span', {}, 'Invoice ' + (i.number || '')), Dom.el('span', { style: 'font-weight:700;color:#0F172A;' }, _m2(i.amount))])); });
      if (items.length > 1) { sumBox.appendChild(Dom.el('div', { style: 'display:flex;justify-content:space-between;gap:8px;border-top:1px solid #E2E8F0;margin-top:4px;padding-top:4px;font-weight:800;color:#0F172A;' }, [Dom.el('span', {}, 'Total'), Dom.el('span', {}, _m2(total))])); }
      box.appendChild(sumBox);
      var go = Dom.el('button', { type: 'button', style: 'width:100%;height:44px;border-radius:11px;border:0;background:linear-gradient(135deg,#1B3A63,#2F8C86);color:#fff;font-weight:800;font-size:14px;cursor:pointer;' }, 'Send my request to ' + centre);
      var back = Dom.el('button', { type: 'button', style: 'width:100%;height:36px;margin-top:6px;border-radius:10px;border:0;background:transparent;color:#64748B;font-weight:700;font-size:13px;cursor:pointer;' }, 'Back');
      back.addEventListener('click', paint);
      go.addEventListener('click', function () {
        go.disabled = true; go.textContent = 'Sending…';
        var c = contacts[pick];
        Api.post('/parent/etransfer-requests', { items: items.map(function (i) { return { kind: i.kind, id: i.id }; }), contact_type: c.type, contact: c.value })
          .then(function () { return ktEtrLoad(true); })
          .then(function () { paint(); if (KT.toast) { KT.toast('📨', 'Request sent to ' + centre, 'They will send the e-Transfer request to ' + c.label + '.', '#1F6080'); } })
          .catch(function (e) {
            go.disabled = false; go.textContent = 'Send my request to ' + centre;
            ktEtrLoad(true).then(paint);
            if (KT.toast) { KT.toast('⚠️', 'Could not ask for the request', (e && e.message) || 'Please try again.', '#B91C1C'); }
          });
      });
      box.appendChild(go); box.appendChild(back);
    }
    box.appendChild(Dom.el('div', { style: 'font-size:12.5px;color:#94A3B8;' }, 'Loading…'));
    ktEtrLoad(true).then(paint, function () { Dom.clear(box); });
  }
  /* The due-now hero: where the newest open request stands, so the family is not left wondering. */
  function ktEtrStatusLine(sum) {
    var slot = Dom.el('div', { style: 'position:relative;' });
    sum.appendChild(slot);
    ktEtrLoad(true).then(function (d) {
      var rows = (d && d.requests) || [];
      var rep = rows.filter(function (r) { return r.kind === 'report' && r.status === 'reported'; })[0];
      if (rep) {
        slot.appendChild(Dom.el('div', { style: 'font-size:12.5px;margin-top:10px;border-top:1px solid rgba(255,255,255,.2);padding-top:8px;line-height:1.45;' }, [
          Dom.el('b', {}, '⏳ e-Transfer sent: ' + _m2(rep.amount) + ' · ref ' + (rep.reference || '') + '. '),
          Dom.el('span', { style: 'opacity:.9;' }, 'Waiting for ' + ((_ktPayInfo && _ktPayInfo.agency_name) || 'your centre') + ' to confirm it; the invoice shows as paid once they do.')]));
        return;
      }
      var open = rows.filter(function (r) { return r.status === 'requested' || r.status === 'sent'; })[0];
      if (!open) { return; }
      var st = ktEtrStatus(open, (_ktPayInfo && _ktPayInfo.agency_name) || 'Your centre');
      slot.appendChild(Dom.el('div', { style: 'font-size:12.5px;margin-top:10px;border-top:1px solid rgba(255,255,255,.2);padding-top:8px;line-height:1.45;' }, [
        Dom.el('b', {}, st.icon + ' e-Transfer request: ' + st.title + '. '), Dom.el('span', { style: 'opacity:.9;' }, st.text)]));
    }, function () {});
  }

  function ktInteracButton(pay, items, opts) {
    opts = opts || {};
    var b = Dom.el('button', { type: 'button', style: (opts.block ? 'width:100%;justify-content:center;' : '') + 'display:inline-flex;align-items:center;gap:9px;height:40px;padding:0 14px 0 6px;border-radius:11px;border:1px solid ' + (opts.onDark ? 'rgba(255,255,255,.35)' : '#E2E8F0') + ';background:#fff;color:#0F172A;font-weight:800;font-size:14px;cursor:pointer;box-shadow:0 6px 16px -10px rgba(15,23,42,.5);' });
    b.appendChild(Dom.el('img', { src: INTERAC_LOGO, alt: '', width: '28', height: '28', style: 'width:28px;height:28px;border-radius:6px;' }));
    var bl = Dom.el('span', {}, opts.label || 'Pay by Interac e-Transfer');
    b.appendChild(bl);
    var withIds = (items || []).filter(function (i) { return i.id && (i.kind === 'inv' || i.kind === 'ext'); });
    if (withIds.length) {
      ktEtrLoad(false).then(function () { if (ktEtrReportFor(withIds).pending) { bl.textContent = 'e-Transfer sent · waiting to be confirmed'; } }, function () {});
    }
    b.addEventListener('click', function (e) { e.stopPropagation(); ktOpenInterac(pay, items); });
    return b;
  }

  /* ═══ PAYING ON THE WEBSITE DEMO (2026-10-30) ═══════════════════════════════════════════════════════════════════
     Anthony: "including the ability to pay invoices (simulate the full experience)". For a public demo agency the
     server sends pay_info.demo_pay (App\Support\WebDemoPayments) and every Pay button opens this sheet instead of a
     real checkout: choose a method, the card or bank sheet, processing, success, a receipt, the balance at zero.
     NO MONEY MOVES and NO CARD DATA LEAVES THE PHONE: the fields are prefilled with the published test number and are
     read-only, sit in no form, and only the invoice ids and the method's name are posted to /parent/demo-pay. */
  function ktDemoPay(invs, done) {
    var pay = (_ktPayInfo && _ktPayInfo.demo_pay) || null;
    if (!pay) { return; }
    invs = (invs || []).filter(function (i) { return Number(i.balance_due) > 0.004 && i.id; });
    if (!invs.length) { return; }
    var total = invs.reduce(function (s, i) { return s + Number(i.balance_due); }, 0);
    var methods = (pay.methods && pay.methods.length) ? pay.methods : [{ key: 'card', label: 'Credit or debit card' }];
    var sel = methods[0].key, busy = false, finished = false, errBox = null;
    var isCA = methods.some(function (m) { return m.key === 'interac'; });
    var u = {}; try { u = JSON.parse(sessionStorage.getItem('kt_user') || localStorage.getItem('kt_user') || '{}') || {}; } catch (e) {}
    var payer = ((u.first_name || '') + ' ' + (u.last_name || '')).trim() || 'Account holder';
    var agencyName = (_ktPayInfo && _ktPayInfo.agency_name) || 'your centre';
    var ICON = { card: '💳', ach: '🏦', pad: '🏦', interac: '⚡' };

    var ov = Dom.el('div', { class: 'kt-demopay-sheet', style: 'position:fixed;inset:0;z-index:9750;background:rgba(8,17,33,.58);display:flex;align-items:center;justify-content:center;padding:14px;box-sizing:border-box;' });
    var panel = Dom.el('div', { role: 'dialog', 'aria-label': 'Pay invoice', style: 'background:#fff;width:100%;max-width:440px;max-height:calc(100% - 24px);overflow:auto;border-radius:18px;box-shadow:0 24px 60px -20px rgba(0,0,0,.6);' });
    var head = Dom.el('div', { style: 'display:flex;align-items:center;gap:12px;padding:14px 16px;background:linear-gradient(135deg,#1B3A63 0%,#265B7A 50%,#2F8C86 100%);border-bottom:3px solid #2DD4BF;color:#fff;position:sticky;top:0;z-index:1;' });
    var title = Dom.el('div', { style: 'flex:1;min-width:0;' }, [
      Dom.el('div', { style: 'font-weight:800;font-size:16px;line-height:1.2;' }, 'Pay ' + _m2(total)),
      Dom.el('div', { style: 'font-size:12px;opacity:.85;margin-top:2px;' }, agencyName),
    ]);
    var x = Dom.el('button', { type: 'button', 'aria-label': 'Close', style: 'background:rgba(255,255,255,.18);color:#fff;border:0;border-radius:50%;width:32px;height:32px;min-height:0;padding:0;font-size:18px;cursor:pointer;flex:0 0 auto;' }, '×');
    head.appendChild(Dom.el('div', { style: 'width:38px;height:38px;border-radius:11px;background:rgba(255,255,255,.16);display:flex;align-items:center;justify-content:center;font-size:20px;flex:0 0 auto;' }, '💳'));
    head.appendChild(title); head.appendChild(x);
    panel.appendChild(head);
    var body = Dom.el('div', { style: 'padding:14px 16px 16px;' });
    panel.appendChild(body);
    ov.appendChild(panel);

    function lbl(t) { return Dom.el('div', { style: 'font-size:11px;font-weight:800;letter-spacing:.5px;text-transform:uppercase;color:#64748B;margin:0 0 4px;' }, t); }
    function ro(value, extra) {
      // Read-only on purpose: a visitor cannot type a real card here.
      return Dom.el('input', { type: 'text', value: value, readonly: 'readonly', tabindex: '-1', autocomplete: 'off', 'aria-readonly': 'true',
        style: 'width:100%;box-sizing:border-box;height:40px;border:1px solid #CBD5E1;border-radius:10px;padding:0 12px;font-size:15px;color:#0F172A;background:#F8FAFC;font-variant-numeric:tabular-nums;' + (extra || '') });
    }
    function row2(a, b) { return Dom.el('div', { style: 'display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-top:10px;' }, [a, b]); }
    function fld(label, input) { return Dom.el('div', { style: 'min-width:0;' }, [lbl(label), input]); }

    function form() {
      Dom.clear(body);
      body.appendChild(Dom.el('div', { style: 'display:flex;gap:9px;align-items:flex-start;background:#FFFBEB;border:1px solid #FCD34D;color:#92400E;border-radius:12px;padding:10px 12px;font-size:12.5px;line-height:1.45;margin-bottom:12px;' }, [
        Dom.el('span', { style: 'font-size:16px;line-height:1;' }, '🧪'),
        Dom.el('span', {}, 'Demo - no real payment is made. The card below is the published test card; never enter a real card here.'),
      ]));
      var list = Dom.el('div', { style: 'border:1px solid #E2E8F0;border-radius:12px;padding:8px 12px;margin-bottom:12px;' });
      invs.forEach(function (i) {
        list.appendChild(Dom.el('div', { style: 'display:flex;justify-content:space-between;gap:10px;font-size:13.5px;padding:4px 0;color:#334155;' }, [
          Dom.el('span', {}, 'Invoice ' + (i.invoice_number || i.number || '')), Dom.el('span', { style: 'font-weight:700;color:#0F172A;' }, _m2(i.balance_due))]));
      });
      list.appendChild(Dom.el('div', { style: 'display:flex;justify-content:space-between;gap:10px;font-size:15px;padding:7px 0 3px;border-top:1px solid #E2E8F0;margin-top:4px;font-weight:800;color:#0F172A;' }, [
        Dom.el('span', {}, 'Total'), Dom.el('span', {}, _m2(total))]));
      body.appendChild(list);

      body.appendChild(lbl('Pay with'));
      var opts = Dom.el('div', { role: 'radiogroup', style: 'display:grid;gap:8px;margin-bottom:12px;' });
      methods.forEach(function (m) {
        var on = m.key === sel;
        var b = Dom.el('button', { type: 'button', role: 'radio', 'aria-checked': on ? 'true' : 'false', 'data-kt-iconized': '1',
          style: 'display:flex;align-items:center;gap:10px;width:100%;text-align:left;height:46px;min-height:0;padding:0 12px;border-radius:12px;cursor:pointer;font-size:14.5px;font-weight:700;color:#0F172A;'
            + (on ? 'border:2px solid #1F6FB2;background:#EFF6FF;' : 'border:1px solid #CBD5E1;background:#fff;') }, [
          Dom.el('span', { style: 'font-size:18px;' }, ICON[m.key] || '💳'), Dom.el('span', { style: 'flex:1;' }, m.label),
          Dom.el('span', { style: 'width:18px;height:18px;border-radius:50%;box-sizing:border-box;' + (on ? 'border:5px solid #1F6FB2;' : 'border:2px solid #94A3B8;') })]);
        b.addEventListener('click', function () { sel = m.key; form(); });
        opts.appendChild(b);
      });
      body.appendChild(opts);

      var det = Dom.el('div', { style: 'border:1px solid #E2E8F0;border-radius:12px;padding:12px;margin-bottom:12px;background:#fff;' });
      if (sel === 'card') {
        det.appendChild(fld('Name on card', ro(payer)));
        var num = Dom.el('div', { style: 'position:relative;margin-top:10px;' }, [lbl('Card number'), ro('4242 4242 4242 4242', 'padding-right:58px;letter-spacing:.5px;'),
          Dom.el('span', { style: 'position:absolute;right:10px;bottom:10px;font-size:11px;font-weight:900;font-style:italic;color:#1A1F71;background:#fff;border:1px solid #CBD5E1;border-radius:5px;padding:2px 6px;' }, 'VISA')]);
        det.appendChild(num);
        det.appendChild(row2(fld('Expiry', ro('12 / 28')), fld('CVC', ro('123'))));
        det.appendChild(Dom.el('div', { style: 'margin-top:10px;' }, [fld(isCA ? 'Postal code' : 'ZIP code', ro(isCA ? 'M5V 2T6' : '90802'))]));
      } else if (sel === 'ach' || sel === 'pad') {
        det.appendChild(fld('Account holder', ro(payer)));
        det.appendChild(row2(fld(sel === 'pad' ? 'Transit / institution' : 'Routing number', ro(sel === 'pad' ? '12345 / 001' : '110000000')), fld('Account number', ro('•••• 6789'))));
        det.appendChild(Dom.el('div', { style: 'margin-top:10px;' }, [fld('Account type', ro('Checking'))]));
        det.appendChild(Dom.el('div', { style: 'font-size:12px;color:#64748B;margin-top:10px;line-height:1.45;' },
          'You authorize ' + agencyName + ' to debit this account for the amount above. Bank payments usually settle in 1-3 business days.'));
      } else {
        det.appendChild(Dom.el('div', { style: 'font-size:13.5px;color:#334155;line-height:1.5;' }, 'Send ' + _m2(total) + ' by Interac e-Transfer from your bank. In the demo, your bank confirms it straight away.'));
      }
      body.appendChild(det);
      var err = Dom.el('div', { style: 'font-size:13px;color:#B91C1C;min-height:0;margin:-4px 0 8px;line-height:1.45;' });
      errBox = err;
      body.appendChild(err);
      var go = Dom.el('button', { type: 'button', 'data-kt-iconized': '1', style: 'width:100%;border:0;cursor:pointer;height:50px;min-height:0;border-radius:13px;font-size:16px;font-weight:800;color:#fff;background:linear-gradient(135deg,#0FA3B1,#1F6FB2 60%,#2456A6);box-shadow:0 12px 24px -12px rgba(31,111,178,.55);' }, 'Pay ' + _m2(total));
      go.addEventListener('click', function () { submit(err); });
      body.appendChild(go);
      body.appendChild(Dom.el('div', { style: 'text-align:center;font-size:11.5px;color:#94A3B8;margin-top:9px;' }, '🔒 Encrypted payment · Demo mode'));
    }

    function submit(err) {
      if (busy) { return; }
      busy = true; err.textContent = '';
      Dom.clear(body);
      var spin = Dom.el('div', { style: 'width:46px;height:46px;border-radius:50%;border:4px solid #DBEAFE;border-top-color:#1F6FB2;margin:26px auto 14px;animation:ktDemoSpin .8s linear infinite;' });
      if (!document.getElementById('kt-demopay-css')) {
        var st = document.createElement('style'); st.id = 'kt-demopay-css';
        st.textContent = '@keyframes ktDemoSpin{to{transform:rotate(360deg)}}@keyframes ktDemoPop{0%{transform:scale(.4);opacity:0}70%{transform:scale(1.08);opacity:1}100%{transform:scale(1)}}';
        document.head.appendChild(st);
      }
      var msg = Dom.el('div', { style: 'text-align:center;font-weight:800;font-size:16px;color:#0F172A;' }, 'Processing payment…');
      var sub = Dom.el('div', { style: 'text-align:center;font-size:13px;color:#64748B;margin:6px 0 28px;' }, sel === 'card' ? 'Contacting your card issuer' : 'Contacting your bank');
      body.appendChild(spin); body.appendChild(msg); body.appendChild(sub);
      x.style.opacity = '.35'; x.style.pointerEvents = 'none';
      setTimeout(function () { sub.textContent = 'Confirming with ' + agencyName; }, 900);
      var wait = new Promise(function (r) { setTimeout(r, 1800); });
      // Only the invoice ids and the method's NAME - never a card or bank detail.
      var req = Api.post('/parent/demo-pay', { invoice_ids: invs.map(function (i) { return i.id; }), method: sel });
      Promise.all([req, wait]).then(function (res) { receipt(res[0]); }, function (e) {
        busy = false; x.style.opacity = ''; x.style.pointerEvents = ''; form();
        if (errBox) { errBox.textContent = (e && e.message) || 'The payment could not be completed. Please try again.'; }
      });
    }

    function receipt(r) {
      finished = true; busy = false; x.style.opacity = ''; x.style.pointerEvents = '';
      title.firstChild.textContent = 'Payment successful';
      Dom.clear(body);
      body.appendChild(Dom.el('div', { style: 'width:64px;height:64px;border-radius:50%;background:#16A34A;color:#fff;font-size:34px;font-weight:900;display:flex;align-items:center;justify-content:center;margin:10px auto 10px;animation:ktDemoPop .45s ease-out;box-shadow:0 12px 26px -12px rgba(22,163,74,.8);' }, '✓'));
      body.appendChild(Dom.el('div', { style: 'text-align:center;font-weight:900;font-size:24px;color:#0F172A;' }, _m2(r.total)));
      body.appendChild(Dom.el('div', { style: 'text-align:center;font-size:13.5px;color:#475569;margin:4px 0 14px;' }, 'Paid to ' + (r.agency_name || agencyName) + '. Thank you!'));
      // The receipt, as the email the family would get.
      var rc = Dom.el('div', { class: 'kt-demo-receipt', style: 'border:1px solid #E2E8F0;border-radius:14px;overflow:hidden;margin-bottom:12px;' });
      rc.appendChild(Dom.el('div', { style: 'background:#F8FAFC;border-bottom:1px solid #E2E8F0;padding:10px 12px;font-size:12px;color:#64748B;line-height:1.5;' }, [
        Dom.el('div', { style: 'font-weight:800;color:#0F172A;font-size:13.5px;' }, 'Receipt from ' + (r.agency_name || agencyName)),
        Dom.el('div', {}, (r.centre_name ? r.centre_name + ' · ' : '') + r.paid_at)]));
      var g = Dom.el('div', { style: 'padding:10px 12px;display:grid;grid-template-columns:auto 1fr;gap:6px 12px;font-size:13px;' });
      function kv(k, v, strong) { g.appendChild(Dom.el('div', { style: 'color:#64748B;' }, k)); g.appendChild(Dom.el('div', { style: 'text-align:right;color:#0F172A;' + (strong ? 'font-weight:800;' : 'font-weight:600;') }, v)); }
      kv('Receipt #', r.reference); g.lastChild.style.whiteSpace = 'nowrap'; g.lastChild.style.fontSize = '12px';
      kv('Paid by', r.payer || payer);
      kv('Paid with', r.method_label);
      (r.invoices || []).forEach(function (i) { kv('Invoice ' + i.number, _m2(i.amount)); });
      kv('Total paid', _m2(r.total), true);
      kv('Balance now', _m2(0), true);
      rc.appendChild(g);
      rc.appendChild(Dom.el('div', { style: 'border-top:1px dashed #E2E8F0;padding:8px 12px;font-size:11.5px;color:#94A3B8;' }, 'A copy of this receipt is in your notifications. Demo receipt - no money moved.'));
      body.appendChild(rc);
      var btns = Dom.el('div', { style: 'display:grid;grid-template-columns:1fr 1fr;gap:10px;' });
      var prt = Dom.el('button', { type: 'button', 'data-kt-iconized': '1', style: 'height:46px;min-height:0;border-radius:12px;border:1px solid #CBD5E1;background:#fff;color:#1F6080;font-weight:800;font-size:14px;cursor:pointer;' }, '🧾 Save receipt');
      prt.addEventListener('click', function () {
        var html = '<!doctype html><html><head><meta charset="utf-8"><title>Receipt ' + r.reference + '</title><style>body{font-family:system-ui,-apple-system,Segoe UI,Roboto,sans-serif;padding:24px;color:#0F172A}div{font-size:14px}</style></head><body>' + rc.outerHTML + '</body></html>';
        // One print path for phones, the apps and desktop (kt-doc-viewer.js, 2026-11-06).
        if (window.KT && KT.printDoc) { KT.printDoc({ html: html }, { title: 'Receipt ' + r.reference }); return; }
        try {
          var f = document.createElement('iframe'); f.style.cssText = 'position:fixed;width:0;height:0;border:0;left:-9999px;';
          document.body.appendChild(f);
          f.contentDocument.open();
          f.contentDocument.write(html);
          f.contentDocument.close();
          setTimeout(function () { try { f.contentWindow.focus(); f.contentWindow.print(); } catch (e) {} setTimeout(function () { f.remove(); }, 2000); }, 300);
        } catch (e) {}
      });
      var ok = Dom.el('button', { type: 'button', 'data-kt-iconized': '1', style: 'height:46px;min-height:0;border-radius:12px;border:0;background:linear-gradient(135deg,#0FA3B1,#1F6FB2);color:#fff;font-weight:800;font-size:14px;cursor:pointer;' }, 'Done');
      ok.addEventListener('click', close);
      btns.appendChild(prt); btns.appendChild(ok);
      body.appendChild(btns);
    }

    function close() {
      if (busy) { return; }
      document.removeEventListener('keydown', onKey);
      if (window.KT && KT.popOverlay) { KT.popOverlay(ov); } else { ov.remove(); }
      if (ov.parentNode) { ov.remove(); }
      if (finished) {
        Object.keys(apiCache).forEach(function (k) { if (/invoices|ledger|billing/.test(k)) { delete apiCache[k]; } });
        if (typeof done === 'function') { try { done(); } catch (e) {} }
        try { window.dispatchEvent(new HashChangeEvent('hashchange')); } catch (e) {}
      }
    }
    function onKey(e) { if (e.key === 'Escape') { close(); } }
    document.addEventListener('keydown', onKey);
    x.addEventListener('click', close);
    ov.addEventListener('click', function (e) { if (e.target === ov) { close(); } });
    form();
    document.body.appendChild(ov);
    if (window.KT && KT.pushOverlay) { KT.pushOverlay(ov); }
  }

  /* The invoices a demo "Pay now" covers: everything still owed in the list, oldest due first. */
  function ktDueInvoices(invoices) {
    return (invoices || []).filter(function (i) { return Number(i.balance_due) > 0.004 && i.status !== 'paid' && !i.is_estimate && i.id; })
      .sort(function (a, b) { return String(a.due_date || a.due_at || '9999').localeCompare(String(b.due_date || b.due_at || '9999')); });
  }

  /* The invoices a "pay" covers: everything still owed in the list the server sent (already the due-now window). */
  function ktDueItems(invoices) {
    return (invoices || []).filter(function (i) { return Number(i.balance_due) > 0.004 && i.status !== 'paid' && !i.is_estimate; })
      // Oldest due first: the overdue one is the one to name first in the message.
      .sort(function (a, b) { return String(a.due_date || a.due_at || '9999').localeCompare(String(b.due_date || b.due_at || '9999')); })
      .map(function (i) { return { number: i.invoice_number || i.number, amount: Number(i.balance_due), kind: (i.kind === 'ext' || i.external_source) ? 'ext' : 'inv', id: i.id }; });
  }

  /* TOTAL DUE, AT THE TOP. It used to come after every invoice card - the first thing a parent saw was a card per
     invoice and the total sat at the bottom of the page, under a second copy of the same list. */

  /* ═══ BILLING DASHBOARD + ACCOUNT LEDGER (2026-10-28) ═════════════════════════════════════════════════════════
     Anthony: "add some stats, graphs and anything else useful under billing and add an account ledger section for
     parents as well. Make this section look better than how it is currently".

     Under the due-now summary: four figures a family actually asks about (what they have paid this year, their last
     payment, what is due next, whether they pay on time), a 12-month billed-vs-paid chart, then two tabs - the
     invoices, and an ACCOUNT LEDGER: every charge and payment with a running balance, filterable by year, printable
     as a statement and downloadable as CSV. All of it from GET /parent/children/{id}/ledger, which follows the same
     due-now rules as the rest of the screen, so the ledger's balance is the figure at the top.

     Built from divs, not a <table>: the portal's table watchers (export bar, kebabs, card pager) decorate every table,
     and a family's statement is not a dataset to manage. */
  function ktD(d, opts) {
    var m = String(d || '').match(/^(\d{4})-(\d{2})-(\d{2})/);
    if (!m) { return d ? String(d) : '—'; }
    return new Date(+m[1], +m[2] - 1, +m[3]).toLocaleDateString(undefined, opts || { month: 'short', day: 'numeric', year: 'numeric' });
  }


  /* INVOICE ⋮ MENUS (2026-10-28, Anthony: "have the ability to see the invoice in a popup as well with the kebab
     feature"). Ledger lines, the iLearn invoice list and KiddieTrac's own invoice cards each get a ⋮ with View invoice
     (the same popup a row tap opens) and, while something is owed, Pay by Interac e-Transfer. Tables get it from
     kt-row-actions (plain buttons in the last cell, collapsed for us); everything else - cards and div rows, which
     nothing collapses - calls KT.openActionMenu on a .kt-ka-kebab anchor, per CONVENTIONS.md. */
  function ktOpenInvRef(ref) {
    if (!ref || !ref.id) { return; }
    if (ref.kind === 'ext') {
      openMergedExternalInvoice({ id: 'ext-' + ref.id, invoice_number: ref.number, status: '', total: 0, balance_due: ref.balance || 0 });
    } else if (window.KT && KT.openInvoiceById) {
      KT.openInvoiceById(ref.id);
    }
  }
  /* Anthony (2026-10-28): "when using the kebab to view the invoice it should go straight to the invoice, add other
     options in the kebab to download, print, email". View opens the invoice ITSELF - the PDF in the portal's document
     viewer (it has its own ✕) - not the summary popup a row tap shows. Email goes only to the signed-in parent's own
     address (the server takes it from their account; nothing here chooses a recipient). */
  function ktInvPdfUrl(ref) {
    var base = (window.KT && KT.API_BASE) || 'https://api.kiddietrac.com/api/v1';
    return ref.kind === 'ext' ? base + '/invoices/external/' + ref.id + '/pdf' : base + '/parent/invoices/' + ref.id + '/pdf';
  }
  function ktInvFile(ref) { return 'Invoice-' + String(ref.number || ref.id).replace(/[^A-Za-z0-9]+/g, '-') + '.pdf'; }
  function ktViewInvoice(ref, thenPrint) {
    if (!(window.KT && KT.openFile && KT.fetchFileBlob)) { ktOpenInvRef(ref); return; }
    var h = KT.openFile(KT.fetchFileBlob(ktInvPdfUrl(ref)).then(function (b) { return new Blob([b], { type: 'application/pdf' }); }),
      { title: 'Invoice ' + (ref.number || ''), label: 'Invoice', filename: ktInvFile(ref), mime: 'application/pdf' });
    if (thenPrint) {
      // The viewer prints once the pages are drawn - in the apps through their print service (2026-11-06).
      Promise.resolve(h).then(function (v) { if (v && v.print) { v.print(); } });
    }
  }
  function ktDownloadInvoice(ref) {
    if (!(window.KT && KT.fetchFileBlob)) { return; }
    KT.fetchFileBlob(ktInvPdfUrl(ref)).then(function (b) {
      var a = document.createElement('a');
      a.href = URL.createObjectURL(new Blob([b], { type: 'application/pdf' }));
      a.download = ktInvFile(ref);
      document.body.appendChild(a); a.click(); a.remove();
      setTimeout(function () { URL.revokeObjectURL(a.href); }, 60000);
    }).catch(function (e) { if (KT.toast) { KT.toast('⚠️', 'Could not download the invoice', (e && e.message) || 'Please try again.', '#B91C1C'); } });
  }
  function ktEmailInvoice(ref) {
    var url = ref.kind === 'ext' ? '/parent/external-invoices/' + ref.id + '/email' : '/parent/invoices/' + ref.id + '/email';
    if (KT.toast) { KT.toast('✉️', 'Sending…', 'Emailing you a copy of invoice ' + (ref.number || '') + '.', '#2C7BA0'); }
    Api.post(url, {}).then(function (r) {
      if (KT.toast) { KT.toast('✅', 'Invoice sent', 'A copy is on its way to ' + ((r && r.email) || 'your email') + '.', '#16A34A'); }
    }).catch(function (e) {
      if (KT.toast) { KT.toast('⚠️', 'Could not send the invoice', (e && e.message) || 'Please try again.', '#B91C1C'); }
    });
  }
  function ktInvActions(ref) {
    var acts = [];
    function item(label, fn) {
      var b = Dom.el('button', { type: 'button' }, label);
      b.addEventListener('click', function (e) { if (e) { e.stopPropagation(); } fn(); });
      acts.push(b);
    }
    item('👁 View invoice', function () { ktViewInvoice(ref, false); });
    item('⬇ Download invoice', function () { ktDownloadInvoice(ref); });   // not "⬇ Download PDF": kt-table-export strips that label
    item('🖨 Print', function () { ktViewInvoice(ref, true); });
    item('✉️ Email me a copy', function () { ktEmailInvoice(ref); });
    if (Number(ref.balance) > 0.004 && _ktPayInfo && _ktPayInfo.interac) {
      item('💳 Pay by Interac e-Transfer', function () { ktOpenInterac(_ktPayInfo, [{ number: ref.number, amount: Number(ref.balance), kind: ref.kind === 'ext' ? 'ext' : 'inv', id: ref.id }]); });
    }
    return acts;
  }
  function ktKebab(ref) {
    var k = Dom.el('button', { type: 'button', class: 'kt-ka-kebab', title: 'Invoice actions', 'aria-label': 'Invoice actions', 'aria-haspopup': 'menu' }, '⋮');
    k.addEventListener('click', function (e) {
      e.stopPropagation(); e.preventDefault();
      if (window.KT && KT.openActionMenu) { KT.openActionMenu(k, ktInvActions(ref)); } else { ktOpenInvRef(ref); }
    });
    return k;
  }

  function ktBillingDashboard(container, child) {
    var mobile = window.matchMedia('(max-width:700px)').matches;
    var dash = Dom.el('div', { class: 'kt-bill-dash' });
    container.appendChild(dash);
    var statsEl = Dom.el('div', { 'data-kt-nosechead': '1', style: 'display:grid;grid-template-columns:repeat(' + (mobile ? 2 : 4) + ',minmax(0,1fr));gap:' + (mobile ? 10 : 12) + 'px;margin-bottom:14px;' });
    for (var k = 0; k < 4; k++) {
      statsEl.appendChild(Dom.el('div', { style: 'height:' + (mobile ? 92 : 98) + 'px;border-radius:16px;background:linear-gradient(90deg,#F1F5F9,#E2E8F0,#F1F5F9);background-size:200% 100%;animation:kt-shimmer 1.2s linear infinite;' }));
    }
    dash.appendChild(statsEl);
    var chartEl = Dom.el('div', { 'data-kt-nosechead': '1', style: 'background:#fff;border:1px solid #E2E8F0;border-radius:16px;padding:14px 16px;margin-bottom:14px;min-height:60px;' });
    dash.appendChild(chartEl);

    // Tabs: Invoices | Account ledger.
    var tabsBar = Dom.el('div', { role: 'tablist', style: 'display:inline-flex;gap:4px;padding:4px;background:#EEF2F7;border-radius:12px;margin:2px 0 14px;' });
    var invPane = Dom.el('div', { role: 'tabpanel' });
    var ledPane = Dom.el('div', { role: 'tabpanel' });
    var saved = 'invoices';
    try { saved = sessionStorage.getItem('kt_bill_tab') || 'invoices'; } catch (e) {}
    var btns = {};
    function show(which) {
      invPane.hidden = which !== 'invoices'; ledPane.hidden = which !== 'ledger';
      Object.keys(btns).forEach(function (k) {
        var on = k === which; btns[k].setAttribute('aria-selected', on ? 'true' : 'false');
        btns[k].style.background = on ? '#fff' : 'transparent'; btns[k].style.color = on ? '#4338CA' : '#475569';
        btns[k].style.boxShadow = on ? '0 1px 4px rgba(15,23,42,.12)' : 'none';
      });
      try { sessionStorage.setItem('kt_bill_tab', which); } catch (e) {}
    }
    [['invoices', '🧾 Invoices'], ['ledger', '📒 Account ledger']].forEach(function (t) {
      var b = Dom.el('button', { type: 'button', role: 'tab', style: 'height:32px;padding:0 14px;border:0;border-radius:9px;font-weight:800;font-size:13px;cursor:pointer;font-family:inherit;' }, t[1]);
      b.addEventListener('click', function () { show(t[0]); });
      btns[t[0]] = b; tabsBar.appendChild(b);
    });
    dash.appendChild(tabsBar);
    dash.appendChild(invPane);
    dash.appendChild(ledPane);
    show(saved === 'ledger' ? 'ledger' : 'invoices');
    ledPane.appendChild(Dom.el('div', { style: 'text-align:center;color:#64748B;padding:24px;font-size:13px;' }, 'Loading your account…'));

    Api.get('/parent/children/' + child.id + '/ledger').then(function (L) {
      paintStats(statsEl, L, mobile);
      paintChart(chartEl, L, mobile);
      Dom.clear(ledPane);
      paintLedger(ledPane, L, mobile, child);
    }).catch(function () {
      statsEl.remove(); chartEl.remove();
      Dom.clear(ledPane);
      ledPane.appendChild(Dom.el('div', { style: 'text-align:center;color:#64748B;padding:24px;font-size:13px;' }, 'Your account ledger could not be loaded just now. Please try again shortly.'));
    });
    return invPane;
  }

  function paintStats(el, L, mobile) {
    Dom.clear(el);
    var s = L.stats || {};
    var lp = s.last_payment;
    var tiles = [
      { icon: '💰', label: 'Paid in ' + (s.year || new Date().getFullYear()), value: _m2(s.paid_this_year), sub: s.paid_all_time > s.paid_this_year ? _m2(s.paid_all_time) + ' all time' : 'Thank you!', c1: '#86EFAC', c2: '#16A34A', tint: '#F0FDF4', ink: '#15803D' },
      { icon: '🧾', label: 'Last payment', value: lp ? _m2(lp.amount) : '—', sub: lp ? ktD(lp.date, { month: 'short', day: 'numeric' }) + (lp.method ? ' · ' + String(lp.method).split(' · ')[0] : '') : 'None yet', c1: '#A5B4FC', c2: '#6366F1', tint: '#EEF2FF', ink: '#4338CA' },
      { icon: '📅', label: 'Next due', value: s.next_due ? ktD(s.next_due, { month: 'short', day: 'numeric' }) : 'Nothing due', sub: s.overdue_count ? s.overdue_count + ' overdue · ' + _m2(s.overdue_total) : (s.due_now ? _m2(s.due_now) + ' due now' : 'You are all caught up'), c1: s.overdue_count ? '#FCA5A5' : '#FDBA74', c2: s.overdue_count ? '#DC2626' : '#F97316', tint: s.overdue_count ? '#FEF2F2' : '#FFF7ED', ink: s.overdue_count ? '#B91C1C' : '#C2410C' },
      { icon: '⏱️', label: 'Paid on time', value: s.on_time_of ? (s.on_time_pct + '%') : '—', sub: s.on_time_of ? Math.round(s.on_time_pct * s.on_time_of / 100) + ' of ' + s.on_time_of + ' invoices by the due date' : 'Shows once an invoice is paid', c1: '#7DD3FC', c2: '#0EA5E9', tint: '#F0F9FF', ink: '#0369A1' },
    ];
    tiles.forEach(function (t) {
      var tile = Dom.el('div', { style: 'position:relative;overflow:hidden;background:' + t.tint + ';border:1px solid rgba(15,23,42,.06);border-radius:16px;padding:' + (mobile ? '12px' : '14px 15px') + ';min-width:0;' });
      var top = Dom.el('div', { style: 'display:flex;align-items:center;gap:8px;' });
      top.appendChild(Dom.el('span', { style: 'width:28px;height:28px;border-radius:50%;display:inline-flex;align-items:center;justify-content:center;font-size:14px;flex:0 0 auto;background:linear-gradient(135deg,' + t.c1 + ',' + t.c2 + ');box-shadow:0 5px 11px -6px ' + t.c2 + ';' }, t.icon));
      top.appendChild(Dom.el('span', { style: 'font-size:10.5px;font-weight:800;letter-spacing:.5px;text-transform:uppercase;color:' + t.ink + ';opacity:.8;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;' }, t.label));
      tile.appendChild(top);
      tile.appendChild(Dom.el('div', { style: 'font-size:' + (mobile ? 20 : 24) + 'px;font-weight:900;color:' + t.ink + ';margin-top:8px;line-height:1.1;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;' }, t.value));
      tile.appendChild(Dom.el('div', { style: 'font-size:11.5px;color:#64748B;margin-top:3px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;' }, t.sub));
      el.appendChild(tile);
    });
  }

  function paintChart(el, L, mobile) {
    Dom.clear(el);
    var months = L.months || [];
    var max = 0;
    months.forEach(function (m) { max = Math.max(max, m.billed, m.paid); });
    var head = Dom.el('div', { style: 'display:flex;align-items:center;justify-content:space-between;gap:10px;flex-wrap:wrap;margin-bottom:8px;' });
    head.appendChild(Dom.el('div', {}, [
      Dom.el('div', { style: 'font-weight:800;font-size:14px;color:#0F172A;' }, 'Your last 12 months'),
      Dom.el('div', { style: 'font-size:12px;color:#64748B;margin-top:1px;' }, (L.stats && L.stats.avg_monthly) ? 'About ' + _m2(L.stats.avg_monthly) + ' a month on average' : 'Billed and paid, month by month'),
    ]));
    var legend = Dom.el('div', { style: 'display:flex;gap:12px;font-size:12px;color:#475569;font-weight:600;' });
    [['#8B5CF6', 'Billed'], ['#22C55E', 'Paid']].forEach(function (l) {
      legend.appendChild(Dom.el('span', { style: 'display:inline-flex;align-items:center;gap:5px;' }, [Dom.el('span', { style: 'width:10px;height:10px;border-radius:3px;background:' + l[0] + ';' }), Dom.el('span', {}, l[1])]));
    });
    head.appendChild(legend);
    el.appendChild(head);
    if (!max) {
      el.appendChild(Dom.el('div', { style: 'font-size:13px;color:#64748B;padding:14px 0 6px;' }, 'Your billing history will build up here month by month.'));
      return;
    }
    var W = 600, H = mobile ? 150 : 150, padL = 6, padB = 2, padT = 10, n = months.length || 12;
    var gw = (W - padL * 2) / n, bw = Math.min(16, gw * 0.3);
    var y = function (v) { return padT + (H - padT - padB) * (1 - v / max); };
    var svg = '<svg viewBox="0 0 ' + W + ' ' + H + '" width="100%" height="' + H + '" preserveAspectRatio="none" role="img" aria-label="Billed and paid by month" style="display:block;overflow:visible">';
    [0.25, 0.5, 0.75, 1].forEach(function (f) { var yy = y(max * f); svg += '<line x1="0" x2="' + W + '" y1="' + yy + '" y2="' + yy + '" stroke="#EEF2F7" stroke-width="1"/>'; });
    svg += '<line x1="0" x2="' + W + '" y1="' + (H - padB) + '" y2="' + (H - padB) + '" stroke="#CBD5E1" stroke-width="1"/>';
    months.forEach(function (m, i) {
      var cx = padL + gw * i + gw / 2;
      var tip = m.label + ' ' + String(m.month).slice(0, 4) + ' — billed ' + _m2(m.billed) + ', paid ' + _m2(m.paid);
      svg += '<g><title>' + tip + '</title><rect x="' + (cx - gw / 2) + '" y="0" width="' + gw + '" height="' + H + '" fill="transparent"/>';
      if (m.billed > 0) { svg += '<rect x="' + (cx - bw - 1) + '" y="' + y(m.billed) + '" width="' + bw + '" height="' + (H - padB - y(m.billed)) + '" rx="3" fill="#8B5CF6"/>'; }
      if (m.paid > 0) { svg += '<rect x="' + (cx + 1) + '" y="' + y(m.paid) + '" width="' + bw + '" height="' + (H - padB - y(m.paid)) + '" rx="3" fill="#22C55E"/>'; }
      svg += '</g>';
    });
    svg += '</svg>';
    el.appendChild(Dom.el('div', { html: svg }));
    // Month labels as text, not SVG: the chart stretches to the card's width and would stretch the letters with it.
    var lab = Dom.el('div', { style: 'display:flex;padding:0 ' + (100 * padL / W) + '%;margin-top:5px;' });
    months.forEach(function (m) { lab.appendChild(Dom.el('div', { style: 'flex:1;text-align:center;font-size:11px;color:#64748B;font-weight:600;' }, mobile ? m.label.charAt(0) : m.label)); });
    el.appendChild(lab);
  }

  function paintLedger(pane, L, mobile, child) {
    var all = (L.entries || []).slice();
    var years = []; all.forEach(function (e) { var y = String(e.date || '').slice(0, 4); if (y && years.indexOf(y) === -1) { years.push(y); } });
    years.sort().reverse();
    var yearSel = 'all';

    var bar = Dom.el('div', { style: 'display:flex;align-items:center;gap:8px;flex-wrap:wrap;margin-bottom:10px;' });
    var chips = Dom.el('div', { 'data-kt-noband': '1', style: 'display:flex;gap:6px;flex-wrap:wrap;flex:1;min-width:0;' });
    bar.appendChild(chips);
    function btn(label, fn) {
      var b = Dom.el('button', { type: 'button', style: 'height:32px;padding:0 12px;border-radius:9px;border:1px solid #CBD5E1;background:#fff;color:#1F2937;font-weight:700;font-size:12.5px;cursor:pointer;display:inline-flex;align-items:center;gap:6px;font-family:inherit;' }, label);
      b.addEventListener('click', fn); return b;
    }
    bar.appendChild(btn('🖨 Statement', function () { ktPrintStatement(L, rowsFor(), yearSel, child); }));
    bar.appendChild(btn('⬇ Statement (CSV)', function () { ktLedgerCsv(rowsFor(), yearSel); }));
    pane.appendChild(bar);
    // A flex row, not a grid: the phone stylesheet folds 3-column grids into two, which left Balance on its own line.
    var sumEl = Dom.el('div', { 'data-kt-nosechead': '1', style: 'display:flex;gap:8px;margin-bottom:10px;' });
    pane.appendChild(sumEl);
    var list = Dom.el('div', { 'data-kt-nosechead': '1', style: 'background:#fff;border:1px solid #E2E8F0;border-radius:14px;overflow:hidden;' });
    pane.appendChild(list);
    pane.appendChild(Dom.el('div', { style: 'font-size:11.5px;color:#94A3B8;margin:8px 2px 14px;line-height:1.5;' },
      'Upcoming invoices are added to your account ' + (L.window_days || 14) + ' days before they are due. A payment shows once your centre has received it.'));
    try { ktReceiptsCard(pane, child); } catch (e) { /* optional */ }

    function rowsFor() { return all.filter(function (e) { return yearSel === 'all' || String(e.date || '').slice(0, 4) === yearSel; }); }
    function paintChips() {
      Dom.clear(chips);
      ['all'].concat(years).forEach(function (y) {
        var on = y === yearSel;
        var c = Dom.el('button', { type: 'button', style: 'height:30px;padding:0 12px;border-radius:999px;border:1px solid ' + (on ? '#6366F1' : '#E2E8F0') + ';background:' + (on ? '#EEF2FF' : '#fff') + ';color:' + (on ? '#4338CA' : '#475569') + ';font-weight:700;font-size:12.5px;cursor:pointer;font-family:inherit;' }, y === 'all' ? 'All' : y);
        c.addEventListener('click', function () { yearSel = y; paintChips(); paintRows(); });
        chips.appendChild(c);
      });
    }
    function sumBox(label, v, ink) {
      return Dom.el('div', { style: 'flex:1 1 0;background:#F8FAFC;border:1px solid #EEF2F7;border-radius:12px;padding:9px 11px;min-width:0;' }, [
        Dom.el('div', { style: 'font-size:10.5px;font-weight:800;letter-spacing:.4px;text-transform:uppercase;color:#64748B;' }, label),
        Dom.el('div', { style: 'font-size:' + (mobile ? 15 : 17) + 'px;font-weight:900;color:' + ink + ';margin-top:2px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;' }, v),
      ]);
    }
    function paintRows() {
      var rows = rowsFor();
      var ch = 0, cr = 0; rows.forEach(function (e) { ch += e.charge || 0; cr += e.credit || 0; });
      Dom.clear(sumEl);
      sumEl.appendChild(sumBox('Charges', _m2(ch), '#4338CA'));
      sumEl.appendChild(sumBox('Payments', _m2(cr), '#15803D'));
      sumEl.appendChild(sumBox('Balance', _m2(L.balance), L.balance > 0.004 ? '#B45309' : '#15803D'));
      Dom.clear(list);
      if (!rows.length) {
        list.appendChild(Dom.el('div', { style: 'padding:22px;text-align:center;color:#64748B;font-size:13px;' }, 'Nothing on your account for this period.'));
        return;
      }
      if (!mobile) {
        list.appendChild(Dom.el('div', { style: 'display:grid;grid-template-columns:110px 1fr 120px 120px 120px 36px;gap:10px;padding:10px 14px;background:#F8FAFC;border-bottom:1px solid #E2E8F0;font-size:11px;font-weight:800;letter-spacing:.4px;text-transform:uppercase;color:#64748B;' }, [
          Dom.el('div', {}, 'Date'), Dom.el('div', {}, 'Details'), Dom.el('div', { style: 'text-align:right;' }, 'Charges'), Dom.el('div', { style: 'text-align:right;' }, 'Payments'), Dom.el('div', { style: 'text-align:right;' }, 'Balance'), Dom.el('div', {}),
        ]));
      }
      rows.slice().reverse().forEach(function (e, i) {
        var pay = e.kind === 'payment', pend = e.kind === 'pending', ref = e.kind === 'refund';
        var icon = pay ? '✅' : (pend ? '⏳' : (ref ? '↩️' : '🧾'));
        var amtTxt = pay ? '−' + _m2(e.credit) : (pend ? _m2(e.pending_amount) : '+' + _m2(e.charge));
        var amtInk = pay ? '#15803D' : (pend ? '#94A3B8' : (ref ? '#B45309' : '#0F172A'));
        var sub = [e.detail, pend ? 'not counted until it clears' : null].filter(Boolean).join(' · ');
        var iref = e.inv_id ? { kind: e.inv_kind, id: e.inv_id, number: e.ref, balance: e.inv_balance } : null;
        if (mobile) {
          var r = Dom.el('div', { style: 'display:flex;gap:11px;align-items:flex-start;padding:11px 13px;' + (i ? 'border-top:1px solid #F1F5F9;' : '') + (pend ? 'opacity:.75;' : '') });
          r.appendChild(Dom.el('div', { style: 'width:32px;height:32px;border-radius:10px;background:' + (pay ? '#F0FDF4' : '#F5F3FF') + ';display:flex;align-items:center;justify-content:center;font-size:15px;flex:0 0 auto;' }, icon));
          r.appendChild(Dom.el('div', { style: 'flex:1;min-width:0;' }, [
            Dom.el('div', { style: 'font-weight:700;font-size:13.5px;color:#0F172A;' }, e.title),
            Dom.el('div', { style: 'font-size:11.5px;color:#64748B;margin-top:1px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;' }, ktD(e.date) + (sub ? ' · ' + sub : '')),
          ]));
          r.appendChild(Dom.el('div', { style: 'text-align:right;flex:0 0 auto;' }, [
            Dom.el('div', { style: 'font-weight:800;font-size:14px;color:' + amtInk + ';' }, amtTxt),
            Dom.el('div', { style: 'font-size:11px;color:#94A3B8;margin-top:1px;' }, 'bal ' + _m2(e.balance)),
          ]));
          if (iref) { var _k = ktKebab(iref); _k.style.alignSelf = 'center'; r.appendChild(_k); r.style.cursor = 'pointer'; r.addEventListener('click', function () { ktOpenInvRef(iref); }); }
          list.appendChild(r);
        } else {
          var g = Dom.el('div', { style: 'display:grid;grid-template-columns:110px 1fr 120px 120px 120px 36px;gap:10px;align-items:center;padding:10px 14px;font-size:13.5px;' + (i ? 'border-top:1px solid #F1F5F9;' : '') + (pend ? 'opacity:.7;' : '') + (i % 2 ? 'background:#FCFDFE;' : '') });
          g.appendChild(Dom.el('div', { style: 'color:#475569;' }, ktD(e.date)));
          g.appendChild(Dom.el('div', { style: 'min-width:0;display:flex;gap:9px;align-items:center;' }, [
            Dom.el('span', { style: 'font-size:15px;' }, icon),
            Dom.el('div', { style: 'min-width:0;' }, [
              Dom.el('div', { style: 'font-weight:700;color:#0F172A;' }, e.title),
              sub ? Dom.el('div', { style: 'font-size:12px;color:#64748B;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;' }, sub) : Dom.el('span', {}),
            ]),
          ]));
          g.appendChild(Dom.el('div', { style: 'text-align:right;font-weight:700;color:' + (ref ? '#B45309' : '#0F172A') + ';' }, (e.charge ? _m2(e.charge) : '')));
          g.appendChild(Dom.el('div', { style: 'text-align:right;font-weight:700;color:' + (pend ? '#94A3B8' : '#15803D') + ';' }, pay ? _m2(e.credit) : (pend ? _m2(e.pending_amount) + ' ⏳' : '')));
          g.appendChild(Dom.el('div', { style: 'text-align:right;font-weight:800;color:#334155;' }, _m2(e.balance)));
          g.appendChild(iref ? ktKebab(iref) : Dom.el('div', {}));
          if (iref) { g.style.cursor = 'pointer'; g.addEventListener('click', function () { ktOpenInvRef(iref); }); }
          list.appendChild(g);
        }
      });
    }
    paintChips(); paintRows();
  }

  function ktLedgerCsv(rows, year) {
    var q = function (v) { v = v == null ? '' : String(v); return /[",\n]/.test(v) ? '"' + v.replace(/"/g, '""') + '"' : v; };
    var out = [['Date', 'Type', 'Details', 'Reference', 'Charge', 'Payment', 'Balance'].join(',')];
    rows.forEach(function (e) {
      out.push([e.date, e.kind, e.title, e.detail || '', e.ref || '', e.charge ? e.charge.toFixed(2) : '', e.credit ? e.credit.toFixed(2) : '', (e.balance || 0).toFixed(2)].map(q).join(','));
    });
    var a = document.createElement('a');
    a.href = URL.createObjectURL(new Blob(['﻿' + out.join('\r\n')], { type: 'text/csv;charset=utf-8' }));
    a.download = 'account-statement' + (year && year !== 'all' ? '-' + year : '') + '.csv';
    document.body.appendChild(a); a.click(); a.remove();
    setTimeout(function () { URL.revokeObjectURL(a.href); }, 30000);
  }

  function ktPrintStatement(L, rows, year, child) {
    var esc = function (s) { return String(s == null ? '' : s).replace(/[&<>"]/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]; }); };
    var u = (function () { try { return JSON.parse(sessionStorage.getItem('kt_user') || '{}'); } catch (e) { return {}; } })();
    var who = ((u.first_name || '') + ' ' + (u.last_name || '')).trim() || (u.name || '');
    var ch = 0, cr = 0; rows.forEach(function (e) { ch += e.charge || 0; cr += e.credit || 0; });
    var body = rows.map(function (e) {
      return '<tr><td>' + esc(ktD(e.date)) + '</td><td>' + esc(e.title) + (e.detail ? '<div class="sub">' + esc(e.detail) + '</div>' : '') + '</td><td class="r">'
        + (e.charge ? esc(_m2(e.charge)) : '') + '</td><td class="r">' + (e.credit ? esc(_m2(e.credit)) : (e.kind === 'pending' ? esc(_m2(e.pending_amount)) + ' (pending)' : '')) + '</td><td class="r b">' + esc(_m2(e.balance)) + '</td></tr>';
    }).join('');
    var html = '<!doctype html><html><head><meta charset="utf-8"><title>Account statement</title><style>'
      + '*{box-sizing:border-box}body{font-family:Arial,Helvetica,sans-serif;color:#1e293b;margin:32px;font-size:12.5px}'
      + '.hd{display:flex;justify-content:space-between;align-items:flex-start;border-bottom:2px solid #4338CA;padding-bottom:12px;margin-bottom:16px}'
      + '.ag{font-size:20px;font-weight:800;color:#4338CA}.meta{text-align:right;color:#475569}h1{font-size:16px;margin:0 0 4px;color:#0f172a}'
      + '.sum{display:flex;gap:10px;margin:0 0 14px}.sum div{flex:1;border:1px solid #e2e8f0;border-radius:8px;padding:8px 10px}.sum b{display:block;font-size:15px;margin-top:2px}'
      + 'table{width:100%;border-collapse:collapse}th,td{padding:7px 6px;border-bottom:1px solid #e2e8f0;text-align:left;vertical-align:top}th{font-size:10.5px;text-transform:uppercase;color:#64748b}'
      + '.r{text-align:right;white-space:nowrap}.b{font-weight:700}.sub{color:#64748b;font-size:11px;margin-top:2px}@media print{body{margin:12mm}}'
      + '.logo{max-height:64px;max-width:200px;object-fit:contain;display:block;margin-bottom:8px}.adr{color:#475569;font-size:12px;line-height:1.5}'
      + '.parties{display:flex;gap:16px;margin:0 0 14px}.parties>div{flex:1;border:1px solid #e2e8f0;border-radius:8px;padding:10px 12px;line-height:1.5}'
      + '.lbl{font-size:10px;font-weight:800;letter-spacing:.6px;text-transform:uppercase;color:#64748b;margin-bottom:3px}'
      // A phone-width panel: the two boxes stack, and the page margin shrinks.
      + '@media screen and (max-width:600px){body{margin:16px}.parties{flex-direction:column}.parties>div{min-width:0}.hd{flex-direction:column;gap:10px}.meta{text-align:left}}</style></head><body>'
      /* THE STATEMENT'S HEADER (2026-10-28, Anthony: "the pdf should show the ilearn logo and full details of the
         agency and parent's full info"): the agency's logo, legal name, address and contacts; then who it is for. */
      + (function () {
          var st = L.statement || {}, ag = st.agency || {}, pa = st.parent || {};
          var line = function (v) { return v ? '<div>' + esc(v) + '</div>' : ''; };
          var agency = (ag.logo ? '<img class="logo" src="' + ag.logo + '" alt="">' : '')
            + '<div class="ag">' + esc(ag.name || L.agency_name || '') + '</div><div class="adr">'
            + (ag.address || []).map(line).join('') + line(ag.phone) + line(ag.email) + line(ag.website)
            + (ag.tax_registration ? line('Tax registration: ' + ag.tax_registration) : '') + '</div>';
          var meta = '<h1>Account statement</h1><div>' + (year && year !== 'all' ? 'Year ' + esc(year) : 'All activity') + '</div><div>As of ' + esc(ktD(L.today)) + '</div>'
            + (pa.reference ? '<div>Account ' + esc(pa.reference) + '</div>' : '');
          var forWho = '<div><div class="lbl">Statement for</div><b>' + esc(pa.name || who) + '</b>' + (pa.family ? '<div>' + esc(pa.family) + '</div>' : '')
            + (pa.address || []).map(line).join('') + line(pa.phone) + line(pa.email) + '</div>'
            + '<div><div class="lbl">Children</div>' + ((pa.children || []).length ? pa.children.map(line).join('') : line(child && child.display_name))
            + (pa.reference ? '<div style="margin-top:6px"><span class="lbl">Account no.</span> ' + esc(pa.reference) + '</div>' : '') + '</div>';
          return '<div class="hd"><div>' + agency + '</div><div class="meta">' + meta + '</div></div><div class="parties">' + forWho + '</div>';
        })()
      + '<div class="sum"><div>Charges<b>' + esc(_m2(ch)) + '</b></div><div>Payments<b>' + esc(_m2(cr)) + '</b></div><div>Balance owing today<b>' + esc(_m2(L.balance)) + '</b></div></div>'
      + '<table><thead><tr><th>Date</th><th>Details</th><th class="r">Charges</th><th class="r">Payments</th><th class="r">Balance</th></tr></thead><tbody>' + (body || '<tr><td colspan="5">Nothing on the account for this period.</td></tr>') + '</tbody></table>'
      + '<div style="margin-top:18px;font-size:11px;color:#64748b">Upcoming invoices are added ' + esc(L.window_days || 14) + ' days before they are due.</div>'
      /* No print-on-open: it opens in the portal's panel, which has its own Print button - a print dialog jumping up
         over a statement the parent has not seen yet was not asked for. */
      + '</body></html>';
    var w = (window.KT && KT.docWindow) ? KT.docWindow({ title: 'Account statement', label: 'Statement' }) : window.open('', '_blank');
    if (!w) { if (KT.toast) { KT.toast('⚠️', 'Pop-up blocked', 'Allow pop-ups to print your statement.', '#B45309'); } return; }
    w.document.open(); w.document.write(html); w.document.close();
  }

  function ktDueSummary(container, data) {
    var due = data && data.due_now;
    if (!due) { return; }
    var pay = data.pay_info || null;
    var sum = Dom.el('div', { style: 'position:relative;overflow:hidden;background:linear-gradient(135deg,#4338CA 0%,#6D28D9 55%,#9333EA 120%);color:#fff;border-radius:18px;padding:16px 18px;margin-bottom:14px;box-shadow:0 16px 34px -18px rgba(109,40,217,.7);' });
    sum.appendChild(Dom.el('div', { style: 'position:absolute;top:-70px;right:-50px;width:210px;height:210px;border-radius:50%;background:radial-gradient(circle,rgba(255,255,255,.16),transparent 62%);pointer-events:none;' }));
    sum.appendChild(Dom.el('div', { style: 'position:relative;font-size:11px;font-weight:800;letter-spacing:.6px;text-transform:uppercase;opacity:.88;' }, (pay && pay.agency_name) || 'Billing'));
    var row = Dom.el('div', { style: 'position:relative;display:flex;align-items:flex-end;justify-content:space-between;gap:12px;flex-wrap:wrap;margin-top:6px;' });
    var left = Dom.el('div', {});
    if (due.total > 0.004) {
      left.appendChild(Dom.el('div', { style: 'font-size:32px;font-weight:900;line-height:1;' }, _m2(due.total)));
      left.appendChild(Dom.el('div', { style: 'font-size:12.5px;opacity:.88;margin-top:5px;' }, 'due now' + (due.count ? ' · ' + due.count + ' invoice' + (due.count === 1 ? '' : 's') : '')));
    } else {
      left.appendChild(Dom.el('div', { style: 'font-size:22px;font-weight:900;line-height:1.1;' }, 'Nothing due right now'));
      left.appendChild(Dom.el('div', { style: 'font-size:12.5px;opacity:.88;margin-top:5px;' }, 'You are all paid up. Thank you!'));
    }
    row.appendChild(left);
    var items = ktDueItems(data.invoices);
    if (due.total > 0.004 && pay && pay.interac && items.length) {
      row.appendChild(ktInteracButton(pay, items, { onDark: true }));
    }
    if (due.total > 0.004 && pay && pay.demo_pay && ktDueInvoices(data.invoices).length) {
      var dp = Dom.el('button', { type: 'button', 'data-kt-iconized': '1', style: 'display:inline-flex;align-items:center;gap:8px;height:42px;min-height:0;padding:0 18px;border-radius:12px;border:0;background:#fff;color:#4338CA;font-weight:900;font-size:15px;cursor:pointer;box-shadow:0 8px 20px -10px rgba(15,23,42,.6);' }, '💳 Pay now');
      dp.addEventListener('click', function (e) { e.stopPropagation(); ktDemoPay(ktDueInvoices(data.invoices)); });
      row.appendChild(dp);
    }
    sum.appendChild(row);
    if (pay && pay.interac) { ktEtrStatusLine(sum); }
    if (due.upcoming_count) {
      sum.appendChild(Dom.el('div', { style: 'position:relative;font-size:12px;opacity:.85;margin-top:10px;border-top:1px solid rgba(255,255,255,.2);padding-top:8px;' },
        due.upcoming_count + ' upcoming invoice' + (due.upcoming_count === 1 ? '' : 's') + ' will show here ' + (due.window_days || 14) + ' days before ' + (due.upcoming_count === 1 ? 'it is' : 'they are') + ' due.'));
    }
    // Split billing (2026-10-30): the reader's side of the split, read-only ("Your share: 60%").
    if (data.my_split && data.my_split.text) {
      sum.appendChild(Dom.el('div', { style: 'position:relative;font-size:12.5px;margin-top:10px;border-top:1px solid rgba(255,255,255,.22);padding-top:8px;line-height:1.45;' },
        '💳 ' + (data.my_split.pct != null ? 'Your share: ' + data.my_split.pct + '% · ' : '') + data.my_split.text + ' The amount above is your share.'));
    }
    container.appendChild(sum);
  }

  // iLearn (external) invoices — summary + compact, clickable, paged list.
  function startExternalPayment(inv) {
    // Placeholder — only reached when the agency has Stripe enabled. The
    // charge + reconcile-to-source flow is wired when Stripe is configured.
    if (KT.toast) KT.toast('💳', 'Secure checkout', 'Opening secure payment for ' + _extMoney(inv.balance_due) + '…', '#1F6FB2');
  }

  async function appendExternalInvoices(container, xopts) {
    xopts = xopts || {};
    const state = { page: 1, sort: '', dir: 'asc', search: '' };
    let first;
    try { first = await Api.get('/parent/external-invoices?page=1&per_page=8'); }
    catch (e) { return false; }
    if (first && first.pay_info && !_ktPayInfo) { _ktPayInfo = first.pay_info; }
    if (!first || !first.invoices || (!first.invoices.length && !((first.meta || {}).total))) return false;

    const wrap = Dom.el('div', { style: 'margin-top:6px;' });
    container.appendChild(wrap);
    const isDesktop = window.matchMedia('(min-width:768px)').matches;

    function query() {
      let q = '/parent/external-invoices?page=' + state.page + '&per_page=8';
      if (state.sort) q += '&sort=' + encodeURIComponent(state.sort) + '&dir=' + state.dir;
      if (state.search) q += '&search=' + encodeURIComponent(state.search);
      return q;
    }
    function reload(scroll) {
      wrap.style.opacity = '.5';
      Api.get(query()).then((d) => { wrap.style.opacity = ''; paint(d); if (scroll) wrap.scrollIntoView({ block: 'nearest' }); }).catch(() => { wrap.style.opacity = ''; });
    }
    function setSort(key) {
      if (state.sort === key) state.dir = state.dir === 'asc' ? 'desc' : 'asc';
      else { state.sort = key; state.dir = (key === 'amount' || key === 'issued') ? 'desc' : 'asc'; }
      state.page = 1; reload(false);
    }

    function paint(d) {
      Dom.clear(wrap);
      const st = d.stats || {}, meta = d.meta || { page: 1, pages: 1, total: 0 };
      const agency = (d.invoices[0] && d.invoices[0].source_label) || 'Your provider';
      const detailOpts = { stripeEnabled: !!meta.stripe_enabled, onPay: startExternalPayment };

      // ── Summary: big total due + paid indicator ──
      // Deliberately an indigo→violet "statement" look, distinct from the teal
      // top/section banner so the two never read as the same block.
      // On the Billing tab the due-now summary is drawn at the TOP instead (ktDueSummary, 2026-10-28).
      const sum = xopts.noSummary ? null : Dom.el('div', { style: 'position:relative;overflow:hidden;background:linear-gradient(135deg,#4338CA 0%,#6D28D9 55%,#9333EA 120%);color:#fff;border-radius:18px;padding:18px 20px;margin-bottom:12px;box-shadow:0 16px 34px -18px rgba(109,40,217,.7);' });
      if (sum) {
      sum.appendChild(Dom.el('div', { style: 'position:absolute;top:-70px;right:-50px;width:210px;height:210px;border-radius:50%;background:radial-gradient(circle,rgba(255,255,255,.16),transparent 62%);pointer-events:none;' }));
      const sumTop = Dom.el('div', { style: 'display:flex;align-items:center;gap:8px;position:relative;' });
      sumTop.appendChild(Dom.el('span', { style: 'font-size:16px;' }, '💳'));
      sumTop.appendChild(Dom.el('div', { style: 'font-size:11px;font-weight:800;letter-spacing:.6px;text-transform:uppercase;opacity:.88;' }, agency));
      sum.appendChild(sumTop);
      const nums = Dom.el('div', { style: 'display:flex;align-items:flex-end;justify-content:space-between;gap:14px;margin-top:8px;flex-wrap:wrap;position:relative;' });
      nums.appendChild(Dom.el('div', {}, [
        Dom.el('div', { style: 'font-size:34px;font-weight:900;line-height:1;' }, _extMoney(st.open_total || 0)),
        Dom.el('div', { style: 'font-size:12px;opacity:.85;margin-top:5px;' }, 'total due' + ((st.open_count) ? ' · ' + st.open_count + ' open' : '')),
      ]));
      nums.appendChild(Dom.el('div', { style: 'text-align:right;background:rgba(255,255,255,.14);border-radius:12px;padding:8px 12px;' }, [
        Dom.el('div', { style: 'font-size:16px;font-weight:800;color:#86EFAC;line-height:1;' }, _extMoney(st.paid_total || 0)),
        Dom.el('div', { style: 'font-size:11px;opacity:.85;margin-top:3px;' }, 'paid' + ((st.paid_count) ? ' · ' + st.paid_count : '')),
      ]));
      sum.appendChild(nums);
      wrap.appendChild(sum);
      } else {
        wrap.appendChild(Dom.el('div', { style: 'font-size:12px;font-weight:800;letter-spacing:.5px;text-transform:uppercase;color:var(--ink-500,#64748b);margin:4px 2px 8px;' }, 'Invoices from ' + agency));
      }

      // ── Controls: search (both) + sort dropdown (mobile) ──
      const controls = Dom.el('div', { style: 'display:flex;gap:8px;margin-bottom:10px;flex-wrap:wrap;' });
      const search = Dom.el('input', { type: 'search', placeholder: 'Search invoices…', value: state.search, style: 'flex:1;min-width:150px;padding:9px 12px;border:1px solid var(--ink-200,#e2e8f0);border-radius:10px;font-size:14px;box-sizing:border-box;' });
      let t = null;
      search.addEventListener('input', () => { clearTimeout(t); t = setTimeout(() => { state.search = search.value.trim(); state.page = 1; reload(false); }, 300); });
      controls.appendChild(search);
      if (!isDesktop) {
        const sel = Dom.el('select', { style: 'padding:9px 10px;border:1px solid var(--ink-200,#e2e8f0);border-radius:10px;font-size:13px;background:#fff;' });
        [['', 'Sort: Due soonest'], ['due', 'Due date'], ['amount', 'Amount'], ['status', 'Status'], ['number', 'Invoice #'], ['issued', 'Issued date']].forEach((o) => { const op = Dom.el('option', { value: o[0] }, o[1]); if (o[0] === state.sort) op.selected = true; sel.appendChild(op); });
        sel.addEventListener('change', () => { state.sort = sel.value; state.dir = (state.sort === 'amount' || state.sort === 'issued') ? 'desc' : 'asc'; state.page = 1; reload(false); });
        controls.appendChild(sel);
      }
      wrap.appendChild(controls);

      if (!d.invoices.length) {
        wrap.appendChild(Dom.el('div', { style: 'text-align:center;color:var(--ink-500,#64748b);padding:26px;background:#fff;border:1px dashed #cbd5e1;border-radius:14px;' }, state.search ? 'No invoices match “' + state.search + '”.' : 'No invoices.'));
        setTimeout(() => { try { search.focus(); } catch (e) {} }, 0);
        return;
      }

      if (isDesktop) {
        // ── Sortable table (headers click to sort) ──
        const scroll = Dom.el('div', { style: 'overflow-x:auto;border:1px solid var(--ink-200,#e2e8f0);border-radius:14px;background:#fff;' });
        const table = Dom.el('table', { style: 'width:100%;border-collapse:collapse;font-size:13.5px;' });
        const cols = [['number', 'Invoice #', 'left'], ['issued', 'Issued', 'left'], ['due', 'Due', 'left'], ['amount', 'Balance', 'right'], ['status', 'Status', 'left']];
        const htr = Dom.el('tr');
        cols.forEach((c) => {
          const active = state.sort === c[0];
          const th = Dom.el('th', { style: 'text-align:' + c[2] + ';padding:11px 14px;font-size:11px;text-transform:uppercase;letter-spacing:.4px;color:' + (active ? '#1F6FB2' : '#64748b') + ';cursor:pointer;white-space:nowrap;border-bottom:1px solid #e2e8f0;background:#f8fafc;user-select:none;' }, c[1] + (active ? (state.dir === 'asc' ? ' ▲' : ' ▼') : ''));
          th.addEventListener('click', () => setSort(c[0]));
          htr.appendChild(th);
        });
        htr.appendChild(Dom.el('th', { style: 'width:18px;border-bottom:1px solid #e2e8f0;background:#f8fafc;' }));
        table.appendChild(Dom.el('thead', {}, [htr]));
        const tb = Dom.el('tbody');
        d.invoices.forEach((inv) => {
          const paid = inv.status === 'paid';
          const tr = Dom.el('tr', { style: 'cursor:pointer;border-bottom:1px solid #f1f5f9;' });
          tr.addEventListener('mouseenter', () => tr.style.background = '#f8fafc');
          tr.addEventListener('mouseleave', () => tr.style.background = '');
          tr.addEventListener('click', () => openExternalInvoice(inv, agency, detailOpts));
          const td = (txt, align, extra) => Dom.el('td', { style: 'padding:11px 14px;text-align:' + (align || 'left') + ';' + (extra || '') }, txt);
          tr.appendChild(td(inv.number || 'Invoice', 'left', 'font-weight:700;color:#0f172a;'));
          tr.appendChild(td(inv.issued_at || '—', 'left', 'color:#64748b;'));
          tr.appendChild(td(inv.due_at || '—', 'left', 'color:' + (inv.status === 'overdue' ? '#c0392b' : '#334155') + ';'));
          tr.appendChild(td(_extMoney(inv.balance_due), 'right', 'font-weight:800;color:' + (paid ? '#16a34a' : '#1F6FB2') + ';'));
          const stTd = Dom.el('td', { style: 'padding:11px 14px;' });
          stTd.appendChild(Dom.el('span', { style: 'background:' + _extStatusColor(_extStatusOf(inv)) + ';color:#fff;padding:3px 9px;border-radius:10px;font-size:10.5px;font-weight:800;text-transform:uppercase;' }, _extStatusOf(inv)));
          tr.appendChild(stTd);
          // Plain buttons in the last cell: kt-row-actions collapses them into the portal's ⋮ (CONVENTIONS.md).
          const actTd = Dom.el('td', { style: 'padding:8px 10px;text-align:center;white-space:nowrap;' });
          ktInvActions({ kind: 'ext', id: inv.id, number: inv.number, balance: inv.balance_due }).forEach((b) => actTd.appendChild(b));
          tr.appendChild(actTd);
          tb.appendChild(tr);
        });
        table.appendChild(tb); scroll.appendChild(table); wrap.appendChild(scroll);
      } else {
        // ── Mobile: compact clickable card rows ──
        const list = Dom.el('div', { style: 'border:1px solid var(--ink-200,#e2e8f0);border-radius:14px;overflow:hidden;background:#fff;' });
        d.invoices.forEach((inv, i) => {
          const paid = inv.status === 'paid';
          const row = Dom.el('div', { style: 'display:flex;align-items:center;gap:12px;padding:12px 14px;cursor:pointer;' + (i ? 'border-top:1px solid var(--ink-100,#f1f5f9);' : '') });
          row.addEventListener('click', () => openExternalInvoice(inv, agency, detailOpts));
          row.appendChild(Dom.el('span', { style: 'flex:0 0 auto;width:9px;height:9px;border-radius:50%;background:' + _extStatusColor(_extStatusOf(inv)) + ';' }));
          row.appendChild(Dom.el('div', { style: 'flex:1;min-width:0;' }, [
            Dom.el('div', { style: 'font-weight:800;font-size:14px;color:var(--ink-900,#0f172a);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;' }, inv.number || 'Invoice'),
            Dom.el('div', { style: 'font-size:12px;color:var(--ink-500,#64748b);margin-top:1px;' }, (inv.due_at ? 'Due ' + inv.due_at : (inv.issued_at || '')) + (paid ? ' · paid' : (inv.status === 'overdue' ? ' · overdue' : ''))),
          ]));
          row.appendChild(Dom.el('div', { style: 'flex:0 0 auto;text-align:right;' }, [
            Dom.el('div', { style: 'font-weight:900;font-size:15px;color:' + (paid ? '#16a34a' : '#1F6FB2') + ';' }, _extMoney(inv.balance_due)),
            Dom.el('div', { style: 'font-size:11px;color:var(--ink-500,#64748b);' }, paid ? 'paid' : 'due'),
          ]));
          row.appendChild(ktKebab({ kind: 'ext', id: inv.id, number: inv.number, balance: inv.balance_due }));
          list.appendChild(row);
        });
        wrap.appendChild(list);
      }

      // ── Pagination ──
      if ((meta.pages || 1) > 1) {
        // KT.pagerBar — the portal's one pager, not a bespoke Prev / Page x of y / Next.
        const pg = Dom.el('div', {});
        if (window.KT && KT.pagerBar) KT.pagerBar(pg, meta.page, meta.pages, (p) => { state.page = p; reload(true); });
        wrap.appendChild(pg);
      }
    }

    paint(first);
    return true;
  }


  /* What has actually happened to this invoice: instalments, and anything given
     back. Returns null when there is nothing to say, so a plain unpaid invoice is
     not padded with an empty box.

     Shared by both billing renderers on purpose — see the note on each. */
  function ktPaymentHistory(inv) {
    var pays = (inv.payments || []).filter(function (p) { return p.status !== 'failed'; });
    var refs = inv.refunds || [];
    if (!pays.length && !refs.length) { return null; }

    var wrap = Dom.el('div', {
      style: 'margin-top:12px;border-top:1px solid var(--ink-200,#E2E8F0);padding-top:10px;',
    });
    wrap.appendChild(Dom.el('div', {
      style: 'font-size:11.5px;font-weight:800;letter-spacing:.5px;text-transform:uppercase;'
        + 'color:var(--ink-500,#64748B);margin-bottom:7px;',
    }, pays.length > 1 ? 'Payments (' + pays.length + ')' : 'Payment history'));

    function money(n) { return '$' + (Number(n) || 0).toFixed(2); }
    function when(d) {
      if (!d) { return ''; }
      // Date-only strings must not be parsed as UTC, or they name the day before.
      var m = String(d).match(/^(\d{4})-(\d{2})-(\d{2})/);
      if (!m) { return String(d); }
      var dt = new Date(+m[1], +m[2] - 1, +m[3]);
      return dt.toLocaleDateString(undefined, { day: 'numeric', month: 'short', year: 'numeric' });
    }
    var LABEL = {
      interac: 'Interac e-Transfer', eft: 'Direct debit', card: 'Card',
      stripe_card: 'Card', stripe_ach: 'Bank transfer', cash: 'Cash',
      cheque: 'Cheque', manual: 'Recorded by your centre',
    };

    function line(icon, title, sub, amount, colour, faded) {
      var row = Dom.el('div', {
        style: 'display:flex;align-items:baseline;gap:8px;padding:4px 0;font-size:12.5px;'
          + (faded ? 'opacity:.65;' : ''),
      });
      row.appendChild(Dom.el('span', { style: 'flex:0 0 auto;' }, icon));
      var mid = Dom.el('div', { style: 'flex:1;min-width:0;' });
      mid.appendChild(Dom.el('div', { style: 'color:var(--ink-800,#1E293B);' }, title));
      if (sub) {
        mid.appendChild(Dom.el('div', { style: 'font-size:11px;color:var(--ink-500,#64748B);' }, sub));
      }
      row.appendChild(mid);
      row.appendChild(Dom.el('div', {
        style: 'font-weight:700;white-space:nowrap;color:' + colour + ';',
      }, amount));
      return row;
    }

    pays.forEach(function (p) {
      /* Sent, not received. An Interac request sitting in somebody's online banking
         is not money in the centre's account, and counting it as paid is how a
         family concludes they are square when they are not. */
      var settled = p.status === 'succeeded';
      wrap.appendChild(line(
        settled ? '✅' : '⏳',
        when(p.date) + ' · ' + (LABEL[p.method] || p.method || 'Payment'),
        settled ? null : 'Waiting for this to clear — not counted yet',
        money(p.amount),
        settled ? '#166534' : 'var(--ink-500,#64748B)',
        !settled
      ));
    });

    refs.forEach(function (r) {
      var sent = r.status === 'succeeded';
      wrap.appendChild(line(
        '↩',
        when(r.date) + ' · Refund' + (r.reason ? ' — ' + r.reason : ''),
        sent ? null : 'Approved — your centre will arrange the payment with you',
        '−' + money(r.amount),
        '#B45309',
        false
      ));
    });

    // The arithmetic, so nobody has to do it from the lines above.
    var paid = Number(inv.net_paid != null ? inv.net_paid : inv.amount_paid || 0);
    if (paid > 0.005 && Number(inv.balance_due || 0) > 0.005) {
      wrap.appendChild(Dom.el('div', {
        style: 'margin-top:7px;padding-top:7px;border-top:1px dashed var(--ink-200,#E2E8F0);'
          + 'font-size:12.5px;display:flex;justify-content:space-between;font-weight:700;',
      }, [
        Dom.el('span', { style: 'color:var(--ink-600,#475569);' }, 'Paid so far'),
        Dom.el('span', { style: 'color:#166534;' }, money(paid) + ' of ' + money(inv.total)),
      ]));
    }

    return wrap;
  }

  /* Paying by bank — Zūm Rails EFT and Interac e-Transfer.

     Built as one block used by BOTH billing renderers. They are separate functions by
     design (desktop and mobile lay out differently) but the payment rules are not a
     layout concern, and two copies of "how much may I pay against this invoice" is
     exactly the kind of thing that drifts.

     Cards ARE here now, via Zum Connect (2026-09-03). The old note said they had to
     stay on Stripe because "Zum's Canadian card API would take the card number
     through our own server" — true of their DIRECT card API, and exactly what
     Connect avoids: the card is typed into Zum's frame and we get back an id.
     Zum enabled card onboarding on our account on 2026-09-02; until then every
     attempt returned "Card Onboarding is not available".

     The manual bank form below stays. It is the path for a parent working from a
     cheque, and Connect is the one that also does cards, Interac and Visa Direct —
     dropping either takes away somebody's way to pay. */
  async function ktZumSection(container, invoices) {
    var st = null;
    try { st = await Api.get('/parent/zum/status'); } catch (e) { return; }
    if (!st || !st.configured) { return; }   // agency has not enabled it — say nothing

    /* Native invoices only: an integrated (iLearn) invoice is "ext-<n>" and is paid to that agency, not through
       this provider - pay() wants a KiddieTrac invoice id (2026-10-28). */
    var outstanding = (invoices || []).filter(function (i) {
      return Number(i.balance_due || i.balance || 0) > 0 && !i.external && !/^ext-/.test(String(i.id));
    });

    var box = Dom.el('div', {
      style: 'background:white;border:1px solid ' + (st.sandbox ? '#F59E0B' : '#E2E8F0')
        + ';border-radius:14px;padding:18px;margin:0 0 18px;',
    });

    /* Sandbox credentials behave exactly like real ones right up to the point where no
       money moves. Unlabelled, that is a parent believing an invoice is settled. */
    if (st.sandbox) {
      box.appendChild(Dom.el('div', {
        style: 'background:#FEF3C7;color:#92400E;border-radius:9px;padding:9px 12px;'
          + 'font-size:12.5px;font-weight:700;margin-bottom:12px;',
      }, '⚠ TEST MODE (staff only — families do not see this section while it is in test mode). '
       + 'This centre is set up against the payment provider’s sandbox: nothing here moves real money.'));
    }

    box.appendChild(Dom.el('div', {
      style: 'font-weight:800;font-size:15px;color:var(--ink-900);margin-bottom:2px;',
    }, 'Ways to pay'));
    box.appendChild(Dom.el('div', {
      style: 'color:var(--ink-500);font-size:12.5px;margin-bottom:12px;',
    }, 'Add a card or bank account, or pay by Interac e-Transfer.'));

    var msg = Dom.el('div', { style: 'font-size:12.5px;margin-top:10px;min-height:16px;' });

    function say(text, good) {
      msg.style.color = good ? '#166534' : '#B91C1C';
      msg.textContent = text;
    }

    /* ── add a method through Zum's own form ──────────────────────────────────
       The card never touches this portal: Connect collects it in Zum's frame and
       hands back an id, which the server verifies before attaching. */
    var connectWrap = Dom.el('div', { style: 'margin-bottom:12px;' });
    var connectBtn = Dom.el('button', {
      type: 'button',
      style: 'padding:9px 16px;border-radius:9px;border:0;background:#1F6080;color:#fff;'
        + 'font-size:13.5px;font-weight:700;cursor:pointer;',
    }, '💳 Add a card or bank account');
    connectBtn.addEventListener('click', async function () {
      if (!(window.KT && KT.zumConnect)) {
        say('The secure payment form is unavailable. Please reload and try again.', false);
        return;
      }
      connectBtn.disabled = true;
      var was = connectBtn.textContent;
      connectBtn.textContent = 'Opening…';
      try {
        var saved = await KT.zumConnect({
          onSaved: function (r) { say((r && r.message) || 'Your payment method is saved.', true); },
          onError: function (m) { say(m, false); },
        });
        if (saved) {
          // Re-read rather than assume: the server decides what is on file.
          try { st = await Api.get('/parent/zum/status'); } catch (e) {}
          paintCard();
          paintBank();
          paintPay();
        }
      } finally {
        connectBtn.disabled = false;
        connectBtn.textContent = was;
      }
    });
    connectWrap.appendChild(connectBtn);
    connectWrap.appendChild(Dom.el('div', {
      style: 'font-size:11.5px;color:var(--ink-500);margin-top:6px;line-height:1.5;',
    }, 'Card, bank account or Interac, entered on our payment provider\u2019s secure form. '
      + 'KiddieTrac never sees your card number.'));
    box.appendChild(connectWrap);

    /* ── the card on file ─────────────────────────────────────────────────────
       There was no card row at all before: a parent could save a card and pay with
       it while the screen still only said "No bank account saved yet". */
    var cardRow = Dom.el('div', { style: 'margin-bottom:10px;' });
    function paintCard() {
      Dom.clear(cardRow);
      if (!(st.on_file && st.on_file.card)) { return; }
      var row = Dom.el('div', {
        style: 'display:flex;align-items:center;gap:10px;font-size:13px;color:var(--ink-700);',
      });
      if (window.KT && KT.cardMark) {
        row.appendChild(Dom.el('span', { html: KT.cardMark(st.on_file.card_brand, { width: 34 }) }));
      }
      var name = (window.KT && KT.cardBrandName)
        ? KT.cardBrandName(st.on_file.card_brand) : 'Card';
      var kind = st.on_file.card_kind === 'debit' ? ' debit' : '';
      row.appendChild(Dom.el('span', {},
        name + kind + ' ending ' + (st.on_file.card_hint || '••••') + ' is on file.'));
      var swap = Dom.el('button', {
        type: 'button',
        style: 'background:none;border:0;padding:0;color:#1F6080;font-weight:700;'
             + 'font-size:13px;cursor:pointer;text-decoration:underline;',
      }, 'Use a different card');
      swap.addEventListener('click', function () { connectBtn.click(); });
      row.appendChild(swap);
      cardRow.appendChild(row);
    }
    box.appendChild(cardRow);

    // ── the account on file ───────────────────────────────────────────────────
    var bankRow = Dom.el('div', { style: 'margin-bottom:12px;font-size:13px;color:var(--ink-700);' });
    function paintBank() {
      Dom.clear(bankRow);
      if (st.on_file && st.on_file.bank) {
        bankRow.appendChild(Dom.el('span', {}, '🏦 Account ending ' + (st.on_file.bank_hint || '•••') + ' is on file. '));
      } else {
        bankRow.appendChild(Dom.el('span', {}, 'No bank account saved yet — needed for direct debit, but not for Interac. '));
      }
      var link = Dom.el('button', {
        type: 'button',
        style: 'background:none;border:0;padding:0;color:#1F6080;font-weight:700;font-size:13px;cursor:pointer;text-decoration:underline;',
      }, st.on_file && st.on_file.bank ? 'Replace it' : 'Or type it from a cheque');
      link.addEventListener('click', function () { form.hidden = !form.hidden; });
      bankRow.appendChild(link);
    }

    var form = Dom.el('div', {
      style: 'background:#F8FAFC;border-radius:10px;padding:14px;margin-bottom:12px;',
    });
    form.hidden = true;
    var inputStyle = 'width:100%;box-sizing:border-box;padding:9px 11px;border:1px solid #CBD5E1;'
      + 'border-radius:8px;font-size:14px;margin-top:4px;';
    form.innerHTML =
      '<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(130px,1fr));gap:10px;">' +
        '<label style="font-size:12px;font-weight:700;color:#475569;">Institution (3 digits)' +
          '<input id="kt-zum-inst" inputmode="numeric" maxlength="3" style="' + inputStyle + '"></label>' +
        '<label style="font-size:12px;font-weight:700;color:#475569;">Transit (5 digits)' +
          '<input id="kt-zum-transit" inputmode="numeric" maxlength="5" style="' + inputStyle + '"></label>' +
        '<label style="font-size:12px;font-weight:700;color:#475569;">Account number' +
          '<input id="kt-zum-acct" inputmode="numeric" maxlength="12" style="' + inputStyle + '"></label>' +
      '</div>' +
      '<div style="font-size:11.5px;color:#64748B;margin-top:8px;">' +
        'These are the three numbers along the bottom of a cheque. They are sent straight to ' +
        'our payment provider — KiddieTrac does not keep them.</div>';
    var saveBtn = Dom.el('button', {
      type: 'button',
      style: 'margin-top:10px;padding:8px 14px;border-radius:9px;border:0;background:#1F6080;'
        + 'color:white;font-size:13px;font-weight:700;cursor:pointer;',
    }, 'Save account');
    saveBtn.addEventListener('click', async function () {
      saveBtn.disabled = true;
      var was = saveBtn.textContent;
      saveBtn.textContent = 'Saving…';
      try {
        await Api.post('/parent/zum/bank-account', {
          institution_number: form.querySelector('#kt-zum-inst').value,
          transit_number: form.querySelector('#kt-zum-transit').value,
          account_number: form.querySelector('#kt-zum-acct').value,
        });
        st = await Api.get('/parent/zum/status');
        form.hidden = true;
        paintBank();
        paintPay();
        say('Your bank account is on file.', true);
      } catch (e) {
        say(e.message || 'That could not be saved.', false);
      } finally {
        saveBtn.disabled = false;
        saveBtn.textContent = was;
      }
    });
    form.appendChild(saveBtn);

    // ── paying ────────────────────────────────────────────────────────────────
    var payWrap = Dom.el('div');
    function paintPay() {
      Dom.clear(payWrap);
      if (!outstanding.length) {
        payWrap.appendChild(Dom.el('div', {
          style: 'font-size:13px;color:var(--ink-500);',
        }, 'Nothing outstanding to pay.'));
        return;
      }
      outstanding.forEach(function (inv) {
        var due = Number(inv.balance_due || inv.balance || 0);
        var row = Dom.el('div', {
          style: 'display:flex;align-items:center;gap:10px;flex-wrap:wrap;padding:9px 0;border-top:1px solid #F1F5F9;',
        });
        row.appendChild(Dom.el('div', { style: 'flex:1;min-width:150px;font-size:13px;' }, [
          Dom.el('div', { style: 'font-weight:700;color:var(--ink-900);' }, inv.invoice_number || ('Invoice ' + inv.id)),
          Dom.el('div', { style: 'color:#64748B;font-size:12px;' }, '$' + due.toFixed(2) + ' outstanding'),
        ]));

        /* Pay some of it. Defaults to the whole balance so the ordinary case is still
           one tap, but a family who can find part of it this week can say so — and
           paying part of an invoice is a great deal better than paying none of it,
           which is what "pay the full balance or nothing" left them with. */
        var amtWrap = Dom.el('label', {
          style: 'display:flex;align-items:center;gap:5px;font-size:12.5px;color:#475569;font-weight:600;',
        });
        amtWrap.appendChild(Dom.el('span', {}, '$'));
        var amt = Dom.el('input', {
          type: 'text',
          inputmode: 'decimal',
          'aria-label': 'Amount to pay',
          style: 'width:88px;padding:6px 8px;border:1px solid #CBD5E1;border-radius:7px;'
            + 'font-size:13px;text-align:right;font-family:inherit;',
        });
        amt.value = due.toFixed(2);
        amtWrap.appendChild(amt);
        row.appendChild(amtWrap);

        /* What they actually typed, clamped. Returns null when it is not a payable
           number so the caller can refuse with a reason instead of sending NaN. */
        function chosen() {
          var v = parseFloat(String(amt.value).replace(/[^0-9.]/g, ''));
          if (!isFinite(v) || v <= 0) { return null; }
          return Math.round(v * 100) / 100;
        }

        var amtNote = Dom.el('div', {
          style: 'width:100%;font-size:11.5px;color:#64748B;',
        });
        function paintNote() {
          var v = chosen();
          if (v === null) { amtNote.textContent = ''; return; }
          amtNote.textContent = v < due - 0.005
            ? 'Part payment \u2014 $' + (due - v).toFixed(2) + ' would still be outstanding.'
            : '';
        }
        amt.addEventListener('input', paintNote);
        paintNote();

        /* Keyed, not "Interac or else". The card button was labelled "Direct debit"
           the moment a third rail appeared. Server label is the fallback so a new
           rail needs no change here. */
        var SHORT = { card: 'Card', interac: 'Interac', eft: 'Direct debit' };
        var LONG = { card: 'card', interac: 'Interac e-Transfer', eft: 'direct debit' };
        var NEEDS = { card: 'Add a card first', eft: 'Save a bank account first' };

        (st.methods || []).forEach(function (m) {
          var b = Dom.el('button', {
            type: 'button',
            title: m.ready ? '' : (NEEDS[m.key] || 'Not available yet'),
            style: 'padding:7px 12px;border-radius:8px;font-size:12.5px;font-weight:700;cursor:'
              + (m.ready ? 'pointer' : 'not-allowed') + ';border:1px solid '
              + (m.ready ? '#1F6080' : '#CBD5E1') + ';background:white;color:'
              + (m.ready ? '#1F6080' : '#94A3B8') + ';',
          }, SHORT[m.key] || m.label || m.key);
          /* The button that spends the money shows which card it will spend. */
          if (m.key === 'card' && m.ready && window.KT && KT.cardMark
              && st.on_file && st.on_file.card_brand) {
            b.innerHTML = KT.cardMark(st.on_file.card_brand, { width: 24 })
              + '<span style="margin-left:6px;vertical-align:middle;">'
              + (SHORT[m.key] || 'Card') + '</span>';
            b.style.display = 'inline-flex';
            b.style.alignItems = 'center';
          }
          if (!m.ready) { b.disabled = true; }
          b.addEventListener('click', async function () {
            var pay = chosen();
            if (pay === null) {
              say('Enter an amount to pay.', false);
              try { amt.focus(); } catch (e) {}
              return;
            }
            /* Refused here as well as on the server. The server is the authority, but
               a round trip to be told the obvious is a worse way to learn it. */
            if (pay > due + 0.005) {
              say('That is more than the $' + due.toFixed(2) + ' outstanding.', false);
              try { amt.focus(); } catch (e) {}
              return;
            }
            /* Confirmed before it is sent. This is somebody's money and the request
               reaches their bank immediately — a mis-tap should not start one. The
               confirm names the amount ACTUALLY being sent, which is the whole point
               of letting it differ from the balance. */
            var rest = pay < due - 0.005
              ? ' This leaves $' + (due - pay).toFixed(2) + ' outstanding.'
              : '';
            if (!(await window.KT.confirm({
              title: 'Pay $' + pay.toFixed(2) + ' by ' + (LONG[m.key] || m.label || m.key) + '?',
              description: 'This is sent to your bank immediately.' + rest,
              okLabel: 'Pay $' + pay.toFixed(2),
            }))) { return; }
            b.disabled = true;
            var was = b.textContent;
            b.textContent = 'Sending…';
            try {
              var r = await Api.post('/parent/zum/pay', { invoice_id: inv.id, method: m.key, amount: pay });
              /* Deliberately not "Paid". Zum has accepted an instruction; the money
                 arrives later, and the invoice is only marked paid when it does. */
              say(r.message || 'Submitted.', true);
            } catch (e) {
              say(e.message || 'That could not be submitted.', false);
            } finally {
              b.disabled = !m.ready;
              b.textContent = was;
            }
          });
          row.appendChild(b);
        });
        row.appendChild(amtNote);
        payWrap.appendChild(row);
      });
    }

    paintCard();
    paintBank();
    paintPay();
    box.appendChild(bankRow);
    box.appendChild(form);
    box.appendChild(payWrap);
    box.appendChild(msg);
    container.appendChild(box);
  }

  async function renderBillingTab(wrap) {
    // (Sub-nav removed — the Today/Photos/Messages/Billing pill bar duplicated the
    // shell's own navigation and looked out of place; matches the Messages fix.)
    const child = state.children.find(c => c.id === state.selectedChildId);
    if (!child) return;

    wrap.appendChild(Dom.el('h1', { style: 'margin: 0 0 24px;' }, 'Billing'));

    const container = Dom.el('div');
    wrap.appendChild(container);
    container.appendChild(Dom.el('p', { style: 'color: var(--ink-500);' }, 'Loading invoices…'));

    try {
      const data = await Api.get(`/parent/children/${child.id}/invoices`);
      ktMyShare(data.invoices);
      _ktPayInfo = data.pay_info || _ktPayInfo;
      Dom.clear(container);

      // Above the list: what is due now, and how to pay it (2026-10-28).
      try { ktDueSummary(container, data); } catch (e) { /* never block the invoices */ }
      // Stats, chart, and the Invoices | Account ledger tabs; everything below goes in the Invoices tab.
      const outer = container;
      let invPane = outer;
      try { invPane = ktBillingDashboard(outer, child); } catch (e) { invPane = outer; }
      try { await ktZumSection(invPane, data.invoices || []); } catch (e) { /* never block the invoices */ }

      /* Integrated (iLearn) invoices are listed once, by appendExternalInvoices below. They also arrive merged
         into this list, and were drawn twice - a card each here, then again in the iLearn list (2026-10-28). */
      (data.invoices || []).filter(inv => !inv.external).forEach(inv => {
        const _payable = (inv.balance_due || 0) > 0 && inv.status !== 'paid';
        const card = Dom.el('div', { class: 'card', style: 'margin-bottom: 16px;' + (_payable ? ' cursor: pointer;' : '') });
        if (_payable) card.addEventListener('click', () => openInvoiceDetail(inv, child));
        const head = Dom.el('div', { style: 'display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 12px;' });
        head.appendChild(Dom.el('div', {}, [
          Dom.el('div', { style: 'font-weight: 700; font-size: 18px;' }, inv.invoice_number),
          Dom.el('div', { style: 'color: var(--ink-600); font-size: 13px;' }, `Issued ${inv.issue_date} · Due ${inv.due_date}`),
        ]));
        const statusColor = inv.status === 'paid' ? 'var(--brand-green)' : (inv.status === 'overdue' ? '#c0392b' : 'var(--ink-600)');
        head.appendChild(Dom.el('div', { style: 'display:flex;align-items:center;gap:8px;flex-shrink:0;' }, [Dom.el('span', { style: `background: ${statusColor}; color: white; padding: 4px 12px; border-radius: 12px; font-size: 12px; font-weight: 700; text-transform: uppercase;` }, inv.status_label || inv.status), ktKebab({ kind: 'native', id: inv.id, number: inv.invoice_number, balance: inv.balance_due })]));
        card.appendChild(head);

        const breakdown = Dom.el('div', { style: 'background: var(--ink-50); padding: 16px; border-radius: 8px; display: grid; grid-template-columns: 1fr auto; gap: 8px; font-size: 14px;' });
        breakdown.appendChild(Dom.el('div', { style: 'color: var(--ink-600);' }, 'Subtotal'));
        breakdown.appendChild(Dom.el('div', { style: 'text-align: right;' }, '$' + inv.subtotal.toFixed(2)));
        if (inv.subsidy_amount > 0) {
          breakdown.appendChild(Dom.el('div', { style: 'color: var(--ink-600);' }, 'Subsidy'));
          breakdown.appendChild(Dom.el('div', { style: 'text-align: right; color: var(--brand-green);' }, '−$' + inv.subsidy_amount.toFixed(2)));
        }
        breakdown.appendChild(Dom.el('div', { style: 'font-weight: 700; font-size: 16px; border-top: 1px solid var(--ink-200); padding-top: 8px;' }, 'Your portion'));
        breakdown.appendChild(Dom.el('div', { style: 'text-align: right; font-weight: 700; font-size: 16px; border-top: 1px solid var(--ink-200); padding-top: 8px; color: var(--brand-blue);' }, '$' + inv.total.toFixed(2)));
        card.appendChild(breakdown);

        // Instalments and refunds — see ktPaymentHistory. Shared with the mobile renderer.
        var _hist = ktPaymentHistory(inv);
        if (_hist) { card.appendChild(_hist); }

        if (inv.balance_due > 0) {
          const payRow = Dom.el('div', { style: 'display:flex; align-items:center; justify-content:space-between; gap:12px; flex-wrap:wrap; background: #FEF3C7; --kt-edge:#F59E0B; padding: 12px 14px; margin-top: 12px; font-size: 13px; border-radius: 4px;' });
          payRow.appendChild(Dom.el('div', {}, `Balance due: $${inv.balance_due.toFixed(2)} · due ${inv.due_date}`));
          const payBtn = Dom.el('button', { type: 'button', style: 'background: linear-gradient(135deg,#0FA3B1,#1F6FB2 60%,#2456A6); color:#fff; border:0; border-radius:10px; padding:9px 18px; font-weight:800; font-size:14px; cursor:pointer;' }, `Pay $${inv.balance_due.toFixed(2)}`);
          payBtn.addEventListener('click', (e) => { e.stopPropagation(); openInvoiceDetail(inv, child); });
          payRow.appendChild(payBtn);
          card.appendChild(payRow);
        }
        invPane.appendChild(card);
      });

      const hadExternal = await appendExternalInvoices(invPane, { noSummary: !!data.due_now });
      if (!(data.invoices || []).length && !hadExternal) {
        invPane.appendChild(emptyState('📄', 'No invoices yet', 'When your monthly invoice is generated, it will appear here.'));
      }
    } catch (e) {
      Dom.clear(container);
      container.appendChild(emptyState('⚠️', 'Could not load billing', e.message));
    }
  }

  /* ═══════════════════════════════════════════════════════════════════
     MOBILE (≤600px) — a clean, single-column, native-app parent experience.
     Navigation is the bottom bar (Home · Photos · Messages · Billing · Menu),
     so there's no in-screen sub-nav. Each screen shows one thing, well.
     ═══════════════════════════════════════════════════════════════════ */

  async function renderParentMobile(main) {
    const wrap = Dom.el('div', { style: 'padding: 2px 2px 6px;' });
    main.appendChild(wrap);

    if (state.children.length > 1) wrap.appendChild(buildChildChips());

    const hash = (window.location.hash || '#today').replace('#', '').split('?')[0];
    // Opening a section clears its unread notifications (so the nav badge stops nagging).
    if (hash === 'photos' || hash === 'billing' || hash === 'messages') markCategoryRead(hash === 'messages' ? 'messages' : hash);
    if (hash === 'photos') return renderPhotosMobile(wrap);
    if (hash === 'messages' || hash === 'chat') return renderMessagesMobile(wrap);
    if (hash === 'billing') return renderBillingMobile(wrap);
    return renderTodayMobile(wrap);
  }

  /* THE CHILD'S OWN PICTURE ON THE PHONE (2026-10-30, Anthony: "avatars and display pics in the emulator pop in and
     out - not consistent"). The phone's child chips, the Today status card and the Messages list drew a bare initial
     ("E") that kt-emoji-avatars swapped for an emoji AFTER the screen had painted - on every screen change the circle
     showed a letter, then a face - and they never used the child's photo, which the desktop and the child record do.
     Now the photo (or the child emoji, via KT.avatar) is in the markup from the first frame. Null when KT.avatar is not
     loaded yet; callers keep their old circle then. */
  function childAvatarEl(c, size) {
    if (!c || !(window.KT && KT.avatar)) return null;
    const holder = document.createElement('span');
    holder.innerHTML = KT.avatar(c.display_name || c.first_name || '', { size: size, photoUrl: c.photo_url ? absUrl(c.photo_url) : null, kind: 'child', sex: c.gender || c.sex || null });
    const el = holder.firstElementChild;
    if (el) el.style.flexShrink = '0';
    return el;
  }

  // Horizontal, scrollable child selector (only when there's >1 child).
  function buildChildChips() {
    const bar = Dom.el('div', { style: 'display:flex;gap:8px;overflow-x:auto;padding:2px 0 10px;-webkit-overflow-scrolling:touch;scrollbar-width:none;' });
    state.children.forEach(c => {
      const active = c.id === state.selectedChildId;
      const chip = Dom.el('button', {
        style: `display:flex;align-items:center;gap:8px;flex:0 0 auto;padding:5px 15px 5px 5px;border-radius:24px;cursor:pointer;font-weight:700;font-size:14px;`
          + `border:1.5px solid ${active ? 'var(--brand-blue)' : 'var(--ink-200)'};background:${active ? 'var(--brand-blue)' : '#fff'};color:${active ? '#fff' : 'var(--ink-700)'};`,
      }, [
        childAvatarEl(c, 30) || Dom.el('span', { style: `width:30px;height:30px;border-radius:50%;display:inline-flex;align-items:center;justify-content:center;font-size:13px;font-weight:800;color:#fff;background:${active ? 'rgba(255,255,255,.25)' : 'var(--brand-green)'};` },
          (c.display_name || 'C')[0].toUpperCase()),
        (c.first_name || c.display_name),
      ]);
      chip.addEventListener('click', () => { state.selectedChildId = c.id; Shell.renderScreen(); });
      bar.appendChild(chip);
    });
    return bar;
  }

  const card = (extra) => 'background:#fff;border-radius:16px;box-shadow:0 1px 6px rgba(15,23,42,.06);' + (extra || '');

  // ─── MOBILE · TODAY ────────────────────────────────────────────────
  // Report an absence: a reason, an optional note, and a confirmation — because
  // this tells the whole centre, and telling them the wrong day is worse than
  // not telling them at all.
  /* "+" ON THE CHILD (2026-11-06, Anthony: "make the child have a + for actions such as late, sick, not attending
     today"). One "+" on the child's photo replaces the full-width "Running late" and "isn't attending today"
     buttons down the page; the menu it opens holds all three. Today only - none of them means anything for another
     day. Sick is "not attending" with Unwell already chosen. */
  /* THE "+" IS ON THE CHILD'S PHOTO, as on the educator's roster (2026-11-06, Anthony: "the + has to be like how we
     added it to the educator on the child"): the same small teal badge bottom-right of the photo (screen-educator.js
     .kt-kr-plus), and tapping the photo opens the menu. avatarEl is the photo the card already draws; it is wrapped. */
  function childActionsAvatar(child, avatarEl) {
    /* TAPPING THE "+" DID NOTHING (2026-11-06, Anthony: "hitting the plus on the child icon does nothing and i have to
       hit other areas near by"). The badge hung 3px outside the button at the right and bottom - where a finger
       lands on a small badge - so those taps hit the card behind it. Now the button is the photo plus a 6px margin
       all round (padding, cancelled by a negative margin so the layout does not move), and the badge sits inside it.
       The photo's own name tooltip is dropped so the button is the one thing a tap or hover belongs to. */
    const b = Dom.el('button', {
      type: 'button', 'data-kt-iconized': '1', 'data-kt-btn-free': '1', 'data-kt-nospin': '1',
      'aria-label': (child.first_name || child.display_name) + ': running late, sick or not attending today',
      'data-kttip': 'Running late, sick or not attending',
      style: 'position:relative;flex-shrink:0;padding:6px;margin:-6px;border:none;background:none;cursor:pointer;border-radius:12px;line-height:0;'
        + 'touch-action:manipulation;-webkit-tap-highlight-color:transparent;',
    });
    [avatarEl].concat([].slice.call(avatarEl.querySelectorAll ? avatarEl.querySelectorAll('[title],[data-kttip]') : [])).forEach((el) => {
      el.removeAttribute('title'); el.removeAttribute('data-kttip');
    });
    avatarEl.style.pointerEvents = 'none';
    b.appendChild(avatarEl);
    b.appendChild(Dom.el('span', {
      'aria-hidden': 'true',
      style: 'position:absolute;right:3px;bottom:3px;width:24px;height:24px;border-radius:50%;border:2px solid #fff;background:#0F6E80;color:#fff;'
        + 'display:flex;align-items:center;justify-content:center;font:700 17px/1 system-ui,-apple-system,sans-serif;box-sizing:border-box;'
        + 'box-shadow:0 2px 6px rgba(15,23,42,.25);pointer-events:none;',
    }, '+'));
    b.addEventListener('click', (e) => { e.stopPropagation(); openChildActions(child, b); });
    return b;
  }
  /* The menu is the educator's floating card (2026-11-06, Anthony: "pressing the + should be the same type of popup
     like we did on the educator for the child (dimmed background etc..)"): KT.kidFloat (kt-kid-float.js) opens it
     beside the photo over a dimmed page, with the same head and round-icon rows. */
  function openChildActions(child, anchor) {
    const first = child.first_name || child.display_name;
    const atCentre = !!child.is_at_centre;
    const KF = window.KT && KT.kidFloat;
    const body = Dom.el('div', { 'data-kt-noband': '1' });
    const head = Dom.el('div', { class: 'kt-kr-pop-head' });
    const ring = Dom.el('span', { class: 'kt-kr-ring' });
    if (KF) ring.appendChild(KF.photo(child, 36));
    head.appendChild(Dom.el('span', { class: 'kt-kr-kid', 'data-st': atCentre ? 'in' : 'away', style: 'padding:0;cursor:default;' }, [ring]));
    head.appendChild(Dom.el('div', { style: 'min-width:0;' }, [
      Dom.el('b', {}, child.display_name || first),
      Dom.el('span', { class: 'sub' }, atCentre ? 'At ' + (KT.centreWord ? KT.centreWord(false, true) : 'centre') : 'At home'),
    ]));
    body.appendChild(head);
    const note = Dom.el('div', { class: 'kt-kr-note', style: 'display:none;' });
    body.appendChild(note);
    const tiles = Dom.el('div', { class: 'kt-kr-tiles', role: 'menu', 'aria-label': 'Today' });
    function tile(ico, label, tint, fn) {
      const t = Dom.el('button', { type: 'button', class: 'kt-kr-tile', role: 'menuitem', 'aria-label': label, 'data-kt-iconized': '1' }, [
        Dom.el('span', { class: 'ico', 'aria-hidden': 'true' }, ico),
        Dom.el('span', { class: 'lbl' }, label),
      ]);
      t.style.setProperty('--kt-kr-tint', tint);
      t.addEventListener('click', () => { if (KF) KF.close(); setTimeout(fn, 60); });
      tiles.appendChild(t);
    }
    tile('⏰', atCentre ? 'Late pickup' : 'Running late', '#EDE9FE', () => openRunningLate(child.id));
    if (!atCentre) {
      tile('🤒', 'Sick today', '#FCE7F3', () => openAbsenceSheet(child, null, 'sick'));
      tile('🚫', 'Not attending today', '#FEF3C7', () => openAbsenceSheet(child, null));
      // Already told the centre today? Say so above the rows.
      cget(`/parent/absences?child_id=${child.id}`).then((r) => {
        const today = KT.agencyToday ? KT.agencyToday() : new Date().toISOString().slice(0, 10);
        const hit = (r.absences || []).find(a => String(a.absent_on).slice(0, 10) === today);
        if (!hit || !note.isConnected) return;
        note.textContent = '✓ Reported away today' + (hit.reason ? ' · ' + hit.reason : '');
        note.style.display = '';
      }).catch(() => {});
    }
    body.appendChild(tiles);
    if (KF) { KF.open(body, anchor, child.display_name || first); return; }
    Shell.Modal.open({ title: first + ' today', body: body, actions: [{ label: 'Close', onClick: () => Shell.Modal.close() }] });
  }

  function openAbsenceSheet(child, sourceBtn, preReason) {
    const ov = Dom.el('div', {
      style: 'position:fixed;inset:0;z-index:9650;background:rgba(8,17,33,.6);display:flex;align-items:flex-end;',
    });
    const sheet = Dom.el('div', {
      style: 'background:#fff;width:100%;border-radius:20px 20px 0 0;padding:18px 16px calc(var(--kt-safe-bottom, env(safe-area-inset-bottom,0px)) + 18px);',
    });
    ov.appendChild(sheet);

    sheet.innerHTML = `
      <div style="width:38px;height:4px;border-radius:99px;background:#E2E8F0;margin:0 auto 14px;"></div>
      <div style="font-weight:800;font-size:17px;color:#0F172A;">${preReason === 'sick' ? child.display_name + ' is sick today' : child.display_name + " isn't attending today"}</div>
      <div style="font-size:13px;color:#64748B;line-height:1.5;margin-top:4px;">
        Your centre will be told straight away, and we'll stop reminding you to sign in today.
      </div>
      <div style="font-size:11px;font-weight:800;letter-spacing:.5px;color:#64748B;text-transform:uppercase;margin:14px 0 7px;">Reason</div>
      <div id="kt-abs-reasons" style="display:flex;flex-wrap:wrap;gap:7px;"></div>
      <textarea id="kt-abs-note" placeholder="Anything the centre should know (optional)"
        style="width:100%;box-sizing:border-box;margin-top:12px;padding:11px;border:1.5px solid #E3EAF1;border-radius:10px;font-size:16px;min-height:64px;font-family:inherit;"></textarea>
      <div id="kt-abs-err" style="color:#B91C1C;font-size:12.5px;min-height:16px;margin-top:4px;"></div>
      <button id="kt-abs-send" style="width:100%;background:#159FB4;color:#fff;border:none;border-radius:12px;padding:14px;font-size:15px;font-weight:800;cursor:pointer;">Tell the centre</button>
      <button id="kt-abs-cancel" style="width:100%;background:none;border:none;color:#64748B;font-size:13px;font-weight:700;padding:11px;cursor:pointer;">Cancel</button>
    `;

    let reason = null;
    const reasons = [['sick', '🤒 Unwell'], ['appointment', '🩺 Appointment'], ['holiday', '✈️ Holiday'], ['family', '👪 Family'], ['other', '• Other']];
    const rWrap = sheet.querySelector('#kt-abs-reasons');
    const chips = [];
    reasons.forEach(([key, label]) => {
      const c = Dom.el('button', {
        type: 'button',
        style: 'border:1.5px solid #E2E8F0;background:#fff;color:#0F172A;border-radius:999px;padding:9px 13px;font-size:13.5px;font-weight:700;cursor:pointer;',
      }, label);
      c.addEventListener('click', () => {
        reason = reason === key ? null : key;
        chips.forEach(({ el, k }) => {
          const on = k === reason;
          el.style.background = on ? '#159FB4' : '#fff';
          el.style.color = on ? '#fff' : '#0F172A';
          el.style.borderColor = on ? '#159FB4' : '#E2E8F0';
        });
      });
      chips.push({ el: c, k: key });
      rWrap.appendChild(c);
    });
    // Opened as "sick today" from the "+": Unwell is already chosen.
    if (preReason) { const pre = chips.find(x => x.k === preReason); if (pre) pre.el.click(); }

    const close = () => { if (ov.parentNode) ov.parentNode.removeChild(ov); };
    sheet.querySelector('#kt-abs-cancel').addEventListener('click', close);
    ov.addEventListener('click', (e) => { if (e.target === ov) close(); });
    if (KT.pushOverlay) KT.pushOverlay(ov, close);

    sheet.querySelector('#kt-abs-send').addEventListener('click', () => {
      const btn = sheet.querySelector('#kt-abs-send');
      btn.disabled = true; btn.textContent = 'Telling the centre…';
      Api.post('/parent/absences', {
        child_id: child.id,
        reason,
        note: (sheet.querySelector('#kt-abs-note').value || '').trim() || null,
      }).then(() => {
        close();
        bust(`/parent/absences?child_id=${child.id}`);
        if (sourceBtn) {
          sourceBtn.textContent = `✓ Reported absent today${reason ? ' · ' + reason : ''}`;
          sourceBtn.style.borderColor = '#159FB4';
          sourceBtn.style.color = '#0E7C90';
          sourceBtn.style.background = '#F0FBFD';
        }
        if (KT.toast) { var _w = KT.centreWord ? KT.centreWord(false, true) : 'centre'; KT.toast('✅', (KT.centreWord ? KT.centreWord(false) : 'Centre') + ' told', 'Your ' + _w + ' knows they are not coming in today.', '#16A34A'); }
      }).catch((e) => {
        btn.disabled = false; btn.textContent = 'Tell the centre';
        sheet.querySelector('#kt-abs-err').textContent = (e && e.message) || 'Could not send — please try again.';
      });
    });

    document.body.appendChild(ov);
  }

  /* PICKUP COUNTDOWN (2026-11-09, Anthony: "in the mobile app for parents add a set an alarm to pick up kids feature
     with a count down clock and use the checkin/clocking in time as the starting point and match the scheduled time to
     pick up child - allow user to add or decrease as optional"). A ring that fills from check-in to pickup (the time the
     centre expects: the child's pickup time, else closing, moved later by a late-pickup notice), -15 / +15 to move
     today's pickup for this parent only, and an alarm that rings the phone (parents:pickup-alarms pushes it, so it rings
     with the app closed). GET/POST /parent/pickup-plan; App\Support\PickupPlan. The card hides itself while the child
     is not signed in. */
  function pickupCountdownCard(child) {
    const box = Dom.el('div', { style: card('padding:14px 15px;margin-bottom:13px;display:none;') });
    let plan = null, skew = 0, timer = null, busy = false;
    const R = 34, C = 2 * Math.PI * R;
    const fmtLeft = (ms) => {
      const m = Math.max(0, Math.ceil(ms / 60000));
      const h = Math.floor(m / 60);
      return h ? h + 'h ' + String(m % 60).padStart(2, '0') + 'm' : m + ' min';
    };
    const chip = (label, aria, fn) => {
      const b = Dom.el('button', { type: 'button', 'aria-label': aria, 'data-kt-btn-free': '1', 'data-kt-nospin': '1',
        style: 'height:32px!important;min-height:0!important;padding:0 12px!important;border-radius:999px!important;border:1.5px solid #CBD5E1;background:#fff;color:#334155;font:inherit;font-size:12.5px;font-weight:800;line-height:1;cursor:pointer;' }, label);
      b.addEventListener('click', fn);
      return b;
    };
    async function save(patch) {
      if (busy) return;
      busy = true;
      try {
        const r = await Api.post('/parent/pickup-plan', Object.assign({ child_id: child.id }, patch));
        take(r);
      } catch (e) { ktToast('⚠️', (e && e.message) || 'Could not save. Try again.'); }
      busy = false;
    }
    function take(r) {
      plan = ((r && r.children) || [])[0] || null;
      skew = plan && plan.server_now ? Date.parse(plan.server_now) - Date.now() : 0;
      paint();
    }
    function paint() {
      Dom.clear(box);
      if (!plan || plan.status !== 'in' || !plan.pickup_at) { box.style.display = 'none'; return; }
      box.style.display = '';
      const now = Date.now() + skew;
      const end = Date.parse(plan.pickup_at);
      const start = plan.checked_in_at ? Date.parse(plan.checked_in_at) : null;
      const left = end - now;
      const late = left <= 0;
      const frac = start && end > start ? Math.min(1, Math.max(0, (now - start) / (end - start))) : (late ? 1 : 0);
      const col = late ? '#DC2626' : (left <= 15 * 60000 ? '#D97706' : '#0F6E80');

      box.appendChild(Dom.el('div', { style: 'font-size:11px;font-weight:800;letter-spacing:1px;color:#0F6E80;margin-bottom:9px;' }, '⏰ PICKUP COUNTDOWN'));
      const row = Dom.el('div', { style: 'display:flex;align-items:center;gap:14px;' });
      const ring = Dom.el('div', { style: 'position:relative;width:84px;height:84px;flex-shrink:0;' });
      ring.innerHTML = '<svg viewBox="0 0 84 84" width="84" height="84" aria-hidden="true" style="transform:rotate(-90deg)">'
        + '<circle cx="42" cy="42" r="' + R + '" fill="none" stroke="#E2E8F0" stroke-width="8"/>'
        + '<circle cx="42" cy="42" r="' + R + '" fill="none" stroke="' + col + '" stroke-width="8" stroke-linecap="round" stroke-dasharray="' + C.toFixed(1)
        + '" stroke-dashoffset="' + (C * (1 - frac)).toFixed(1) + '" style="transition:stroke-dashoffset .6s"/></svg>';
      ring.appendChild(Dom.el('div', { style: 'position:absolute;inset:0;display:flex;flex-direction:column;align-items:center;justify-content:center;line-height:1.1;text-align:center;' }, [
        Dom.el('b', { style: 'font-size:' + (late ? '13px' : '15px') + ';color:' + col + ';' }, late ? 'Pickup' : fmtLeft(left)),
        Dom.el('span', { style: 'font-size:10.5px;color:var(--ink-500);font-weight:700;' }, late ? 'time' : 'left'),
      ]));
      row.appendChild(ring);

      const txt = Dom.el('div', { style: 'flex:1;min-width:0;' });
      txt.appendChild(Dom.el('div', { style: 'font-size:16px;font-weight:800;color:var(--ink-900);' }, 'Pick up at ' + plan.pickup_label));
      const sub = [];
      if (plan.checked_in_label) sub.push('Checked in ' + plan.checked_in_label);
      if (plan.adjust_minutes) sub.push('usually ' + plan.usual_label);
      else if (plan.source === 'late') sub.push('your late pickup time');
      txt.appendChild(Dom.el('div', { style: 'font-size:12px;color:var(--ink-500);margin-top:2px;' }, sub.join(' · ')));
      if (late) {
        txt.appendChild(Dom.el('div', { style: 'font-size:12px;color:#DC2626;font-weight:700;margin-top:3px;' },
          (child.first_name || child.display_name) + "'s pickup time was " + fmtLeft(-left) + ' ago'));
      }
      const adj = Dom.el('div', { style: 'display:flex;gap:6px;flex-wrap:wrap;margin-top:8px;' });
      const step = (d) => {
        const n = Math.max(-plan.adjust_max, Math.min(plan.adjust_max, (plan.adjust_minutes || 0) + d));
        if (n !== plan.adjust_minutes) save({ adjust_minutes: n });
      };
      adj.appendChild(chip('−15 min', 'Pick up 15 minutes earlier', () => step(-15)));
      adj.appendChild(chip('+15 min', 'Pick up 15 minutes later', () => step(15)));
      if (plan.adjust_minutes) adj.appendChild(chip('Reset', 'Back to the usual pickup time', () => save({ adjust_minutes: 0 })));
      txt.appendChild(adj);
      row.appendChild(txt);
      box.appendChild(row);

      // The alarm: on/off and how long before. It rings the phone even with the app closed.
      const al = Dom.el('div', { style: 'display:flex;align-items:center;gap:8px;flex-wrap:wrap;margin-top:12px;padding-top:11px;border-top:1px solid var(--ink-100);' });
      const on = !!(plan.alarm && plan.alarm.on);
      const tog = Dom.el('button', { type: 'button', 'data-kt-btn-free': '1', 'data-kt-nospin': '1', 'aria-pressed': on ? 'true' : 'false',
        style: 'height:32px!important;min-height:0!important;padding:0 13px!important;border-radius:999px!important;font:inherit;font-size:13px;font-weight:800;line-height:1;cursor:pointer;'
          + (on ? 'background:#0F6E80;color:#fff;border:1.5px solid #0F6E80;' : 'background:#fff;color:#0F6E80;border:1.5px solid #0F6E80;') },
        on ? '🔔 Alarm on' : '🔕 Set an alarm');
      tog.addEventListener('click', () => save({ alarm_on: !on }));
      al.appendChild(tog);
      const sel = Dom.el('select', { 'aria-label': 'When the alarm rings',
        style: 'height:32px!important;min-height:0!important;width:auto!important;border:1.5px solid #CBD5E1;border-radius:8px;padding:0 8px!important;font:inherit;font-size:13px;background:#fff;color:#0F172A;' });
      (plan.leads || []).forEach((m) => {
        const o = Dom.el('option', { value: String(m) }, m === 0 ? 'At pickup time' : (m >= 60 ? (m / 60) + ' hour before' : m + ' min before'));
        if (m === plan.alarm.lead_minutes) o.selected = true;
        sel.appendChild(o);
      });
      sel.addEventListener('change', () => save({ lead_minutes: parseInt(sel.value, 10), alarm_on: true }));
      al.appendChild(sel);
      if (on) al.appendChild(Dom.el('span', { style: 'font-size:12px;color:var(--ink-500);' }, 'Rings ' + plan.alarm_at_label));
      box.appendChild(al);
    }
    // Redraw every 15 seconds while the card is on screen; stop once it is gone.
    timer = setInterval(() => { if (!box.isConnected) { clearInterval(timer); return; } if (!busy) paint(); }, 15000);
    Api.get('/parent/pickup-plan?child_id=' + encodeURIComponent(child.id)).then(take).catch(() => {});
    return box;
  }

  async function renderTodayMobile(wrap) {
    const child = state.children.find(c => c.id === state.selectedChildId);
    if (!child) return;
    const atCentre = child.is_at_centre;

    /* THE DAY'S NUMBERS FIRST (2026-11-06, Anthony: "take the cards from the top out of there and move the cards from
       the bottom to the top (meals, naps etc)"). The account strip above (status, balance, photos...) is gone for
       parents (role-widgets.js); meals, naps, changes and play lead the screen. Tapping one still filters the
       timeline further down. Filled in below with the timeline. One compact row: at the top they are a glance, not
       the screen, so it is a flex row (a 5-across grid is folded to 2 big columns on phones by kt-mobile-app.css). */
    const stats = Dom.el('div', { style: 'display:flex;gap:6px;margin-bottom:12px;' });
    wrap.appendChild(stats);
    /* DUE NOW UNDER THE NUMBERS (2026-11-06, Anthony: "move the due now below the stat cards"). It was the last card
       on the screen; filled in below with the timeline (the same invoices call). */
    const bill = Dom.el('a', { href: '#billing', style: card('display:flex;align-items:center;gap:12px;padding:12px 14px;text-decoration:none;margin-bottom:13px;') });
    wrap.appendChild(bill);

    // 1 · Child status card
    const status = Dom.el('div', { style: card('padding:15px;display:flex;align-items:center;gap:13px;margin-bottom:13px;') });
    const _av = childAvatarEl(child, 52) || Dom.el('div', { style: 'width:52px;height:52px;border-radius:50%;background:var(--brand-green);color:#fff;display:flex;align-items:center;justify-content:center;font-size:22px;font-weight:800;flex-shrink:0;' },
      (child.display_name || 'C')[0].toUpperCase());
    // Running late, sick, not attending: the "+" on the photo (childActionsAvatar). Today only.
    status.appendChild(state.date === localToday() ? childActionsAvatar(child, _av) : _av);
    const info = Dom.el('div', { style: 'flex:1;min-width:0;' });
    info.appendChild(Dom.el('div', { style: 'font-size:19px;font-weight:800;color:var(--ink-900);line-height:1.15;' }, child.display_name));
    info.appendChild(Dom.el('div', { style: 'font-size:12.5px;color:var(--ink-500);margin-top:2px;' }, `${child.age?.human || '—'} · ${child.room_name || 'No room'}`));
    info.appendChild(Dom.el('div', { style: `display:inline-flex;align-items:center;gap:5px;margin-top:8px;padding:3px 11px;border-radius:20px;font-size:11px;font-weight:800;letter-spacing:.3px;${atCentre ? 'background:rgba(142,199,60,.16);color:#4d7c0f;' : 'background:var(--ink-100);color:var(--ink-600);'}` }, [
      Dom.el('span', { style: `width:7px;height:7px;border-radius:50%;background:${atCentre ? '#65a30d' : 'var(--ink-400)'};` }),
      atCentre ? ('At ' + (KT.centreWord ? KT.centreWord(false, true) : 'centre')) : 'At home',
    ]));
    status.appendChild(info);
    wrap.appendChild(status);
    // Already reported away today: said on the card, beside the "+".
    if (!atCentre && state.date === localToday()) {
      cget(`/parent/absences?child_id=${child.id}`).then((r) => {
        const today = KT.agencyToday ? KT.agencyToday() : new Date().toISOString().slice(0, 10);
        const hit = (r.absences || []).find(a => String(a.absent_on).slice(0, 10) === today);
        if (!hit || !info.isConnected) return;
        info.appendChild(Dom.el('div', { style: 'margin-top:6px;font-size:12px;font-weight:700;color:#0E7C90;' }, '✓ Reported away today' + (hit.reason ? ' · ' + hit.reason : '')));
      }).catch(() => {});
    }
    // Pickup countdown + alarm: today, while the child is in (pickupCountdownCard).
    if (atCentre && state.date === localToday()) wrap.appendChild(pickupCountdownCard(child));
    if (window.KT && KT.Supplies) { const _sup = Dom.el('div'); wrap.appendChild(_sup); KT.Supplies.parentCard(_sup); }

    // Your team & centre — who's caring for your child, and where.
    if (child.centre_name || (child.educators && child.educators.length)) {
      const team = Dom.el('div', { style: card('padding:13px 15px;margin-bottom:13px;') });
      if (child.centre_name) {
        const cRow = Dom.el('div', { style: 'display:flex;align-items:center;gap:9px;' });
        cRow.appendChild(Dom.el('span', { style: 'font-size:18px;flex-shrink:0;' }, '\uD83C\uDFEB'));
        const cTxt = Dom.el('div', { style: 'flex:1;min-width:0;' });
        cTxt.appendChild(Dom.el('div', { style: 'font-size:14px;font-weight:800;color:var(--ink-900);line-height:1.2;' }, child.centre_name));
        const sub = [child.agency_name, child.room_name].filter(Boolean).join(' \u00B7 ');
        if (sub) cTxt.appendChild(Dom.el('div', { style: 'font-size:11.5px;color:var(--ink-500);margin-top:1px;' }, sub));
        if (child.centre_address) {
          const addr = Dom.el('div', { style: 'font-size:11.5px;color:var(--ink-500);margin-top:3px;display:flex;align-items:flex-start;gap:4px;' });
          addr.appendChild(Dom.el('span', { style: 'flex-shrink:0;' }, '\uD83D\uDCCD'));
          addr.appendChild(Dom.el('span', {}, child.centre_address));
          cTxt.appendChild(addr);
        }
        if (child.centre_phone) {
          const ph = Dom.el('a', { href: 'tel:' + String(child.centre_phone).replace(/[^0-9+]/g, ''), style: 'font-size:11.5px;color:var(--brand-600,#0d9488);font-weight:700;margin-top:2px;display:inline-flex;align-items:center;gap:4px;text-decoration:none;' });
          ph.appendChild(Dom.el('span', {}, '\uD83D\uDCDE'));
          ph.appendChild(Dom.el('span', {}, child.centre_phone));
          cTxt.appendChild(ph);
        }
        cRow.appendChild(cTxt);
        team.appendChild(cRow);
      }
      if (child.educators && child.educators.length) {
        const eRow = Dom.el('div', { style: 'display:flex;align-items:center;gap:9px;' + (child.centre_name ? 'margin-top:11px;padding-top:11px;border-top:1px solid var(--ink-100);' : '') });
        eRow.appendChild(Dom.el('span', { style: 'font-size:18px;flex-shrink:0;' }, '\uD83E\uDDD1\u200D\uD83C\uDFEB'));
        const eTxt = Dom.el('div', { style: 'flex:1;min-width:0;' });
        eTxt.appendChild(Dom.el('div', { style: 'font-size:10.5px;font-weight:800;letter-spacing:.5px;text-transform:uppercase;color:var(--ink-500);' }, child.educators.length === 1 ? 'Your educator' : 'Your educators'));
        eTxt.appendChild(Dom.el('div', { style: 'font-size:13.5px;font-weight:700;color:var(--ink-900);margin-top:1px;' }, child.educators.map(function (e) { return e.name; }).filter(Boolean).join(', ')));
        eRow.appendChild(eTxt);
        team.appendChild(eRow);
      }
      wrap.appendChild(team);
    }

    wrap.appendChild(buildDateNav());

    // Running late, sick and not attending live behind the "+" on the child's photo (childActionsAvatar, 2026-11-06).

    // 2 · Daily digest (the emotional centrepiece)
    const digest = Dom.el('div', { style: 'background:linear-gradient(135deg,#1F6080,#2c7894);color:#fff;border-radius:18px;padding:18px;margin-bottom:14px;box-shadow:0 6px 18px -8px rgba(31,96,128,.55);' });
    digest.appendChild(Dom.el('div', { style: 'font-size:11px;font-weight:800;letter-spacing:1.2px;color:#a3d977;margin-bottom:7px;' }, "✨ TODAY'S STORY"));
    const digestBody = Dom.el('div', { style: 'font-size:14.5px;line-height:1.55;opacity:.95;' }, 'Loading…');
    digest.appendChild(digestBody);
    weekRecapButton(digest, child);
    wrap.appendChild(digest);

    // 3 · At-a-glance stats: drawn at the top of the screen now (see "THE DAY'S NUMBERS FIRST").

    // 4 · Timeline — tinted title header to pop.
    wrap.appendChild(Dom.el('div', { style: 'font-size:14px;font-weight:800;color:#1F6080;margin:0 0 10px;background:linear-gradient(135deg,#EAF3FB,#F3F0FF);border:1px solid rgba(31,96,128,.10);padding:9px 13px;border-radius:10px;' }, "🕒 Today's timeline"));
    const tlCard = Dom.el('div', { style: card('padding:4px 14px;margin-bottom:16px;') });
    const tlBody = Dom.el('div'); tlCard.appendChild(tlBody);
    tlBody.appendChild(Dom.el('div', { style: 'padding:20px 0;text-align:center;color:var(--ink-500);font-size:13px;' }, 'Loading…'));
    wrap.appendChild(tlCard);

    // 4b · On the menu today — today's meals from the centre's published weekly
    // menu, with a tap-through to the full week (#menu).
    const menuCard = Dom.el('a', { href: '#menu', style: card('display:block;padding:14px;text-decoration:none;margin-bottom:16px;') });
    menuCard.appendChild(Dom.el('div', { style: 'font-size:11px;font-weight:800;letter-spacing:1px;color:#0FA3B1;margin-bottom:8px;' }, '🍽️ ON THE MENU TODAY'));
    const menuBody = Dom.el('div', { style: 'font-size:13px;color:var(--ink-500);' }, 'Loading…');
    menuCard.appendChild(menuBody);
    wrap.appendChild(menuCard);
    (async () => {
      try {
        const d = new Date(); const dow = (d.getDay() + 6) % 7; d.setDate(d.getDate() - dow);
        const wk = new Date(d.getTime() - d.getTimezoneOffset() * 60000).toISOString().slice(0, 10);
        const r = await cget(`/parent/menu?week_start=${wk}`);
        const todayDow = ((new Date().getDay() + 6) % 7) + 1;
        const openDays = (r && r.open_days) || [1, 2, 3, 4, 5];
        const items = ((r && r.items) || []).filter(it => it.day_of_week === todayDow);
        Dom.clear(menuBody);
        if (openDays.indexOf(todayDow) === -1) {
          menuBody.appendChild(Dom.el('div', {}, (KT.centreWord ? KT.centreWord(false) : 'Centre') + ' closed today · tap to see the week →')); return;
        }
        if (!items.length) {
          menuBody.appendChild(Dom.el('div', {}, (r && r.centre) ? "Today's menu isn't posted yet · tap to see the week →" : 'Tap to view the weekly menu →')); return;
        }
        const mealMeta = { breakfast: ['🍳', 'Breakfast'], morning_snack: ['🍎', 'Morning snack'], lunch: ['🍽️', 'Lunch'], afternoon_snack: ['🧃', 'Afternoon snack'], dinner: ['🍲', 'Dinner'] };
        const map = {}; items.forEach(it => { map[it.meal_type] = it; });
        ['breakfast', 'morning_snack', 'lunch', 'afternoon_snack', 'dinner'].forEach(mt => {
          const it = map[mt]; if (!it) return;
          const meta = mealMeta[mt] || ['•', mt];
          const row = Dom.el('div', { style: 'display:flex;gap:9px;align-items:' + (it.image_url ? 'center' : 'baseline') + ';padding:5px 0;border-top:1px solid var(--ink-100);' });
          row.appendChild(it.image_url
            ? Dom.el('img', { src: it.image_url, alt: it.name || '', loading: 'lazy', style: 'width:40px;height:30px;object-fit:cover;border-radius:7px;flex-shrink:0;background:#F1F5F9;' })
            : Dom.el('span', { style: 'font-size:15px;flex-shrink:0;' }, meta[0]));
          const txt = Dom.el('div', { style: 'flex:1;min-width:0;' });
          txt.appendChild(Dom.el('span', { style: 'font-size:13.5px;color:var(--ink-900);font-weight:600;' }, it.name));
          if (it.allergens) txt.appendChild(Dom.el('span', { style: 'margin-left:6px;font-size:10.5px;color:#92400E;background:#FEF3C7;padding:1px 6px;border-radius:7px;' }, '⚠ ' + it.allergens));
          row.appendChild(txt);
          menuBody.appendChild(row);
        });
        menuBody.appendChild(Dom.el('div', { style: 'margin-top:8px;font-size:12px;color:#0FA3B1;font-weight:700;' }, 'View the full week →'));
      } catch (e) { Dom.clear(menuBody); menuBody.appendChild(Dom.el('div', {}, 'Tap to view the weekly menu →')); }
    })();

    // 5 · Billing: drawn under the numbers at the top now (see "DUE NOW UNDER THE NUMBERS").

    // Warm the sibling sections so tapping Photos/Messages/Billing is instant.
    prefetch([
      `/parent/children/${child.id}/photos`,
      `/parent/messages`,
      `/parent/children/${child.id}/invoices`,
    ]);

    Promise.all([
      cget(`/parent/children/${child.id}/timeline?date=${state.date}`),
      cget(`/parent/children/${child.id}/digest/${state.date}`),
      cget(`/parent/children/${child.id}/invoices?status=current`),
    ]).then(([timeline, dg, invoices]) => {
      const ev = mergeTimelineChecks(timeline);
      const count = (types) => ev.filter(e => types.includes(e.type)).length;

      // The tiles summarise the timeline directly below them, so tapping one
      // filters that timeline instead of being a dead number. Tapping the active
      // tile again clears the filter.
      let activeFilter = null;
      const _enrM = child.enrolled_at || child.enrollment_start || child.enrolled_on || child.enrollment_date || null;
      const _enrMms = _enrM ? new Date(String(_enrM).replace(' ', 'T')).getTime() : NaN;
      const _daysEnrM = isNaN(_enrMms) ? '—' : Math.max(0, Math.floor((Date.now() - _enrMms) / 86400000));
      const TILES = [
        { icon: '🍽️', label: 'Meals',   types: ['meal', 'snack'],      c1: '#FDBA74', c2: '#F97316', tint: '#FFF7ED', ink: '#C2410C' },
        { icon: '😴', label: 'Naps',    types: ['nap_end'],            c1: '#A5B4FC', c2: '#6366F1', tint: '#EEF2FF', ink: '#4338CA' },
        { icon: '👶', label: 'Changes', types: ['diaper', 'bathroom'], c1: '#7DD3FC', c2: '#0EA5E9', tint: '#ECFEFF', ink: '#0369A1' },
        { icon: '✨', label: 'Play',    types: ['activity'],           c1: '#86EFAC', c2: '#16A34A', tint: '#F0FDF4', ink: '#15803D' },
        { icon: '📆', label: 'Days', tip: 'Days enrolled', noFilter: true, value: _daysEnrM, c1: '#5EEAD4', c2: '#0D9488', tint: '#F0FDFA', ink: '#0F766E' },
      ];
      const tileEls = [];
      TILES.forEach((t) => {
        const n = t.noFilter ? t.value : count(t.types);
        const el = Dom.el('button', {
          type: 'button', 'data-kt-iconized': '1', 'data-kttip': t.tip || t.label,
          style: 'position:relative;overflow:hidden;flex:1 1 0;min-width:0;background:' + t.tint + ';border:1.5px solid transparent;border-radius:13px;padding:7px 2px 6px;text-align:center;cursor:pointer;font:inherit;',
        }, [
          Dom.el('div', { style: 'width:26px;height:26px;margin:0 auto 4px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:13px;background:linear-gradient(135deg,' + t.c1 + ',' + t.c2 + ');box-shadow:0 4px 9px -5px ' + t.c2 + ';' }, t.icon),
          Dom.el('div', { style: 'font-size:16px;font-weight:900;line-height:1;white-space:nowrap;color:' + t.ink + ';' }, String(n)),
          Dom.el('div', { style: 'font-size:8.5px;font-weight:800;letter-spacing:0;text-transform:uppercase;white-space:nowrap;overflow:hidden;text-overflow:clip;color:' + t.ink + ';opacity:.72;margin-top:3px;' }, t.label),
        ]);
        el.addEventListener('click', () => {
          if (t.noFilter || !n) return;         // "days enrolled" isn't a filter; nothing logged → nothing to show
          activeFilter = (activeFilter === t.label) ? null : t.label;
          paintTiles();
          renderTimeline(activeFilter ? t.types : null, activeFilter ? t.label : null);
          tlCard.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
        });
        tileEls.push({ el, tile: t, n });
        stats.appendChild(el);
      });
      function paintTiles() {
        tileEls.forEach(({ el, tile, n }) => {
          const on = activeFilter === tile.label;
          el.style.borderColor = on ? '#159FB4' : 'transparent';
          el.style.opacity = (!n && activeFilter) ? '.55' : '1';
        });
      }

      Dom.clear(digestBody);
      if (dg.body) digestBody.appendChild(Dom.el('div', { style: 'white-space:pre-wrap;' }, dg.body));
      else digestBody.appendChild(Dom.el('div', {}, dg.message || `${child.display_name}'s story will be ready this afternoon.`));

      function renderTimeline(types, label) {
        const rows = types ? ev.filter(e => types.includes(e.type)) : ev;
        Dom.clear(tlBody);
        if (label) {
          const chip = Dom.el('div', { style: 'display:flex;align-items:center;justify-content:space-between;padding:9px 0 5px;' }, [
            Dom.el('div', { style: 'font-size:12px;font-weight:800;color:#159FB4;' }, `Showing ${label.toLowerCase()} · ${rows.length}`),
            Dom.el('button', { type: 'button', style: 'background:none;border:none;color:var(--ink-500);font-size:12px;font-weight:700;cursor:pointer;padding:2px 4px;' }, 'Show all'),
          ]);
          chip.querySelector('button').addEventListener('click', () => { activeFilter = null; paintTiles(); renderTimeline(null, null); });
          tlBody.appendChild(chip);
        }
        if (!rows.length) {
          tlBody.appendChild(Dom.el('div', { style: 'padding:22px 0;text-align:center;color:var(--ink-500);font-size:13px;' },
            types ? 'Nothing logged for this yet today.' : 'No moments logged yet today.'));
          return;
        }
        rows.forEach((e, i) => {
          const row = Dom.el('div', { style: 'display:flex;gap:12px;align-items:flex-start;padding:10px 10px;border-radius:10px;background:' + (i % 2 ? 'var(--ink-50,#f8fafc)' : '#ffffff') + ';' });
          row.appendChild(Dom.el('div', { style: 'width:38px;height:38px;border-radius:12px;background:#fff;border:1px solid var(--ink-100,#f1f5f9);display:flex;align-items:center;justify-content:center;font-size:18px;flex-shrink:0;' }, eventIcon(e.type, e)));
          const t = Dom.el('div', { style: 'flex:1;min-width:0;' });
          t.appendChild(Dom.el('div', { style: 'font-weight:700;font-size:14px;color:var(--ink-900);' }, e.display?.title || e.type));
          if (e.display?.detail) t.appendChild(Dom.el('div', { style: 'font-size:12.5px;color:var(--ink-500);margin-top:1px;' }, e.display.detail));
          row.appendChild(t);
          row.appendChild(Dom.el('div', { style: 'font-size:12px;color:var(--ink-500);flex-shrink:0;font-weight:600;' }, e.time_display));
          tlBody.appendChild(row);
        });
      }
      renderTimeline(null, null);

      // Due now, from the server (2026-10-28) - see the desktop Today card.
      const _due = invoices.due_now;
      const inv = _due ? ((_due.total > 0.004 || (invoices.invoices || []).length) ? { balance_due: _due.total } : null) : (invoices.invoices || [])[0];
      Dom.clear(bill);
      bill.style.background = '#F5F3FF';
      bill.style.border = '1px solid rgba(124,58,237,.16)';
      bill.appendChild(Dom.el('div', { style: 'width:40px;height:40px;border-radius:50%;background:linear-gradient(135deg,#C4B5FD,#7C3AED);color:#fff;display:flex;align-items:center;justify-content:center;font-size:19px;flex-shrink:0;box-shadow:0 5px 11px -5px #7C3AED;' }, '💳'));
      const bt = Dom.el('div', { style: 'flex:1;' });
      if (inv) {
        bt.appendChild(Dom.el('div', { style: 'font-size:11px;color:#7C3AED;font-weight:800;text-transform:uppercase;letter-spacing:.4px;opacity:.85;' }, inv.is_estimate ? 'Estimated this month' : (_due ? 'Due now' : 'Balance due')));
        bt.appendChild(Dom.el('div', { style: 'font-size:20px;font-weight:900;color:#6D28D9;' }, '$' + (inv.balance_due ?? inv.total).toFixed(2)));
      } else {
        bt.appendChild(Dom.el('div', { style: 'font-size:14px;font-weight:700;color:var(--ink-900);' }, 'Billing'));
        bt.appendChild(Dom.el('div', { style: 'font-size:12px;color:var(--ink-500);' }, 'No invoices yet'));
      }
      bill.appendChild(bt);
      bill.appendChild(Dom.el('div', { style: 'color:var(--ink-300);font-size:22px;' }, '›'));
    }).catch(e => console.error('Today (mobile) failed:', e));
  }

  // ─── MOBILE · PHOTOS ───────────────────────────────────────────────
  async function renderPhotosMobile(wrap) {
    const child = state.children.find(c => c.id === state.selectedChildId);
    const name = child ? (child.first_name || child.display_name) : '';
    wrap.appendChild(Dom.el('div', { style: 'font-size:18px;font-weight:800;color:var(--ink-900);margin:2px 2px 12px;' }, name ? `${name}'s Photos & video` : 'Photos & video'));

    const grid = Dom.el('div', { style: 'display:grid;grid-template-columns:1fr 1fr;gap:10px;' });
    wrap.appendChild(grid);
    grid.appendChild(Dom.el('div', { style: 'grid-column:1/-1;text-align:center;color:var(--ink-500);padding:20px;font-size:13px;' }, 'Loading photos & video…'));
    try {
      const data = await cget(`/parent/children/${child.id}/photos`);
      Dom.clear(grid);
      if (!data.photos || !data.photos.length) {
        grid.appendChild(Dom.el('div', { style: 'grid-column:1/-1;' }, [emptyState('📷', 'Nothing shared yet', `Photos and video of ${child.display_name} will appear here.`)]));
        return;
      }
      markMediaSeen(child.id);
      const seq = galleryItems(data.photos);
      data.photos.forEach((p, pi) => {
        const c = Dom.el('div', { style: 'border-radius:14px;overflow:hidden;' + card() });
        // Educators can share short video clips as well as photos — a first
        // wobble across the room doesn't survive as a still.
        if (p.media_type === 'video') {
          c.appendChild(Dom.el('video', {
            src: p.url, controls: true, preload: 'metadata', playsInline: true, poster: p.thumbnail_url || undefined,
            style: 'width:100%;aspect-ratio:1;object-fit:cover;display:block;background:#0F172A;',
          }));
        } else {
          // Mobile tile — same thumbnail rule (the parent SPA renders mobile and
          // desktop through different functions, so both need it).
          const mimg = Dom.el('img', { src: p.thumbnail_url || p.url, alt: p.caption || 'Photo', loading: 'lazy', decoding: 'async', style: 'width:100%;aspect-ratio:1;object-fit:cover;display:block;background:var(--ink-100);cursor:zoom-in;' });
          mimg.addEventListener('click', function () { openPhotoLightbox(p.url, p.caption, seq, pi); });
          c.appendChild(mimg);
        }
        if (p.caption || p.date_display) {
          const b = Dom.el('div', { style: 'padding:8px 10px;' });
          if (p.caption) b.appendChild(Dom.el('div', { style: 'font-size:12.5px;line-height:1.35;color:var(--ink-800);' }, p.caption.replace(/^\[Demo\] /, '')));
          if (p.date_display) b.appendChild(Dom.el('div', { style: 'font-size:11px;color:var(--ink-500);margin-top:3px;' }, p.date_display));
          c.appendChild(b);
        }
        grid.appendChild(c);
      });
    } catch (e) {
      Dom.clear(grid);
      grid.appendChild(Dom.el('div', { style: 'grid-column:1/-1;' }, [emptyState('⚠️', 'Could not load photos', e.message)]));
    }
  }

  // ─── MOBILE · BILLING ──────────────────────────────────────────────
  async function renderBillingMobile(wrap) {
    const child = state.children.find(c => c.id === state.selectedChildId);
    wrap.appendChild(Dom.el('div', { style: 'font-size:18px;font-weight:800;color:var(--ink-900);margin:2px 2px 12px;' }, 'Billing'));
    const cont = Dom.el('div'); wrap.appendChild(cont);
    cont.appendChild(Dom.el('div', { style: 'text-align:center;color:var(--ink-500);padding:20px;font-size:13px;' }, 'Loading…'));
    try {
      const data = await cget(`/parent/children/${child.id}/invoices`);
      ktMyShare(data.invoices);
      _ktPayInfo = data.pay_info || _ktPayInfo;
      Dom.clear(cont);
      // What is due now + how to pay, first (2026-10-28) - see the desktop renderer.
      try { ktDueSummary(cont, data); } catch (e) { /* never block the invoices */ }
      let invPane = cont;
      try { invPane = ktBillingDashboard(cont, child); } catch (e) { invPane = cont; }
      try { await ktZumSection(invPane, data.invoices || []); } catch (e) { /* never block the invoices */ }
      (data.invoices || []).filter(inv => !inv.external).forEach(inv => {
        const c = Dom.el('div', { style: card('padding:15px;margin-bottom:12px;cursor:pointer;') });
        c.addEventListener('click', () => openInvoiceDetail(inv, child));
        const head = Dom.el('div', { style: 'display:flex;justify-content:space-between;align-items:flex-start;gap:10px;margin-bottom:12px;' });
        head.appendChild(Dom.el('div', {}, [
          Dom.el('div', { style: 'font-weight:800;font-size:16px;color:var(--ink-900);' }, inv.invoice_number),
          Dom.el('div', { style: 'color:var(--ink-500);font-size:12px;margin-top:2px;' }, `Due ${inv.due_date}`),
        ]));
        const sc = inv.status === 'paid' ? 'var(--brand-green)' : (inv.status === 'overdue' ? '#c0392b' : 'var(--ink-500)');
        head.appendChild(Dom.el('div', { style: 'display:flex;align-items:center;gap:8px;flex-shrink:0;' }, [Dom.el('span', { style: `background:${sc};color:#fff;padding:3px 11px;border-radius:12px;font-size:11px;font-weight:800;text-transform:uppercase;flex-shrink:0;` }, inv.status_label || inv.status), ktKebab({ kind: 'native', id: inv.id, number: inv.invoice_number, balance: inv.balance_due })]));
        c.appendChild(head);
        const bd = Dom.el('div', { style: 'background:var(--ink-50);padding:13px;border-radius:12px;display:grid;grid-template-columns:1fr auto;gap:7px;font-size:13.5px;' });
        bd.appendChild(Dom.el('div', { style: 'color:var(--ink-600);' }, 'Subtotal'));
        bd.appendChild(Dom.el('div', { style: 'text-align:right;' }, '$' + inv.subtotal.toFixed(2)));
        if (inv.subsidy_amount > 0) {
          bd.appendChild(Dom.el('div', { style: 'color:var(--ink-600);' }, 'Subsidy'));
          bd.appendChild(Dom.el('div', { style: 'text-align:right;color:var(--brand-green);' }, '−$' + inv.subsidy_amount.toFixed(2)));
        }
        bd.appendChild(Dom.el('div', { style: 'font-weight:800;border-top:1px solid var(--ink-200);padding-top:7px;' }, 'Your portion'));
        bd.appendChild(Dom.el('div', { style: 'text-align:right;font-weight:800;border-top:1px solid var(--ink-200);padding-top:7px;color:var(--brand-blue);' }, '$' + inv.total.toFixed(2)));
        c.appendChild(bd);

        // The SAME block as the desktop renderer — these two lay out differently, but
        // what a payment history says is not a layout decision.
        var _h = ktPaymentHistory(inv);
        if (_h) { c.appendChild(_h); }
        if (inv.balance_due > 0) {
          c.appendChild(Dom.el('div', { style: 'background:#FEF3C7;--kt-edge:#F59E0B;padding:11px;margin-top:10px;font-size:12.5px;border-radius:6px;color:#92400e;' },
            `Balance $${inv.balance_due.toFixed(2)} · please pay by ${inv.due_date}.`));
        }
        c.appendChild(Dom.el('div', { style: 'display:flex;align-items:center;justify-content:flex-end;gap:4px;margin-top:11px;color:var(--brand-blue);font-weight:700;font-size:13px;' }, [
          Dom.el('span', {}, inv.balance_due > 0 ? 'View & pay' : 'View details'),
          Dom.el('span', { style: 'font-size:16px;' }, '›'),
        ]));
        invPane.appendChild(c);
      });
      const hadExternal = await appendExternalInvoices(invPane, { noSummary: !!data.due_now });
      if (!(data.invoices || []).length && !hadExternal) {
        invPane.appendChild(emptyState('📄', 'No invoices yet', 'Your invoices will appear here.'));
      }
    } catch (e) {
      Dom.clear(cont); cont.appendChild(emptyState('⚠️', 'Could not load billing', e.message));
    }
  }

  // Full-screen invoice detail + pay. Covers the bottom bar (z above it).
  /* SPLIT BILLING (2026-09-29). When the family splits the bill, a parent owes THEIR
     share: every view below reads balance_due, so for a split invoice it becomes this
     parent's own balance, and the family figure is kept alongside for the detail note. */
  function ktMyShare(invoices) {
    (invoices || []).forEach(function (inv) {
      var s = inv && inv.my_share;
      if (!s || inv._shareApplied) { return; }
      inv._shareApplied = true;
      inv.family_balance_due = inv.balance_due;
      inv.family_total = inv.total;
      inv.balance_due = Number(s.balance) || 0;
    });
    return invoices;
  }

  /* CHILDCARE EXPENSE RECEIPTS (2026-09-29). The annual receipt existed on the server
     (PdfController::t4a) and nothing in the portal linked to it. A parent in a split
     family gets their own receipt (the server decides whose). */
  function ktReceiptsCard(container, child) {
    var famId = child && (child.family_id || (child.family && child.family.id));
    if (!famId) { return; }
    var y = new Date().getFullYear();
    var card = Dom.el('div', { style: 'background:#fff;border:1px solid var(--ink-100,#E2E8F0);border-radius:14px;padding:12px 14px;margin:0 0 14px;display:flex;gap:10px;align-items:center;flex-wrap:wrap;' });
    card.appendChild(Dom.el('div', { style: 'flex:1;min-width:180px;' }, [
      Dom.el('div', { style: 'font-weight:800;color:var(--ink-900);font-size:14px;' }, '🧾 Childcare expense receipts'),
      Dom.el('div', { style: 'font-size:12.5px;color:var(--ink-500);' }, 'For your income tax return: what you paid each year.'),
    ]));
    [y, y - 1].forEach(function (yr) {
      var b = Dom.el('button', { type: 'button', style: 'border:1px solid #CBD5E1;background:#fff;border-radius:9px;padding:7px 12px;font-weight:700;font-size:13px;cursor:pointer;' }, String(yr));
      b.addEventListener('click', async function () {
        b.disabled = true;
        try {
          var tok = sessionStorage.getItem('kt_token') || localStorage.getItem('kt_token');
          var res = await fetch(((window.KT && KT.API_BASE) || 'https://api.kiddietrac.com/api/v1') + '/families/' + famId + '/t4a/' + yr, { headers: { Authorization: 'Bearer ' + tok } });
          if (!res.ok) { throw new Error('Could not get the receipt (' + res.status + ')'); }
          var a = document.createElement('a'); a.href = URL.createObjectURL(await res.blob()); a.download = 'Childcare-Receipt-' + yr + '.pdf';
          document.body.appendChild(a); a.click(); a.remove();
        } catch (e) { if (KT.toast) { KT.toast('⚠️', 'Receipt', e.message || 'Could not download', '#B91C1C'); } }
        b.disabled = false;
      });
      card.appendChild(b);
    });
    container.appendChild(card);
  }

  /* AN iLEARN INVOICE IS NOT A KIDDIETRAC INVOICE (2026-10-01).

     /parent/children/{id}/invoices merges the family's integrated-billing invoices into
     the list with an id of "ext-<n>". Tapping one opened THIS sheet, which asked
     /parent/invoices/ext-227/document for the document — a route for native invoices
     that wants a number — and the server answered with a 500 (Marthiena, 2026-10-01).
     Its Pay button would have started a card payment for a native invoice that does not
     exist. The external invoice has its own sheet, document route and payment path. */
  async function openMergedExternalInvoice(inv) {
    const extId = Number(String(inv.id).replace(/^ext-/, ''));
    let rec = {
      id: extId, number: inv.invoice_number, status: inv.status,
      total: inv.total, balance_due: inv.balance_due,
      amount_paid: Math.max(0, (Number(inv.total) || 0) - (Number(inv.balance_due) || 0)),
      due_at: inv.due_date, issued_at: inv.issue_date,
      has_document: !!inv.has_document, source_label: inv.source,
    };
    let opts = {};
    try {
      const d = await Api.get('/parent/external-invoices?page=1&per_page=20&search=' + encodeURIComponent(inv.invoice_number || ''));
      const hit = d && (d.invoices || []).find(function (x) { return Number(x.id) === extId; });
      if (hit) { rec = hit; }
      opts = { stripeEnabled: !!(d && d.meta && d.meta.stripe_enabled), onPay: startExternalPayment };
    } catch (e) { /* the row we already have is enough to show it */ }
    openExternalInvoice(rec, rec.source_label || inv.source || 'Your provider', opts);
  }

  function openInvoiceDetail(inv, child) {
    if (inv && (inv.external || /^ext-/.test(String(inv.id)))) { openMergedExternalInvoice(inv); return; }
    const appMain = document.getElementById('appMain');
    if (!appMain) return;
    /* A POP-UP, NOT A PAGE (2026-10-28, Anthony: "under payment schedules and viewing invoices should be a pop-up").
       On anything wider than a phone the invoice opens as a dialog over the screen it was opened from - dimmed
       backdrop, click outside or Escape to close - instead of a sheet replacing the whole window. Phones keep the
       full-screen sheet, which is what a phone dialog is. */
    const asPopup = window.matchMedia('(min-width:701px)').matches;
    const tw = Dom.el('div', { class: 'kt-invoice-sheet', style: asPopup
      ? 'position:relative;width:min(780px,100%);height:min(92vh,1000px);border-radius:16px;overflow:hidden;background:var(--ink-50,#F4F7FA);display:flex;flex-direction:column;box-shadow:0 30px 80px -20px rgba(8,20,40,.6);animation:kt-screen-in .22s cubic-bezier(.22,.61,.36,1);'
      : 'position:fixed;inset:0;z-index:9600;background:var(--ink-50,#F4F7FA);display:flex;flex-direction:column;animation:kt-screen-in .22s cubic-bezier(.22,.61,.36,1);' });
    const invScrim = asPopup ? Dom.el('div', { class: 'kt-invoice-pop', style: 'position:fixed;inset:0;z-index:9600;background:rgba(8,17,33,.55);display:flex;align-items:center;justify-content:center;padding:16px;box-sizing:border-box;' }) : null;
    if (invScrim) { invScrim.appendChild(tw); invScrim.addEventListener('click', (e) => { if (e.target === invScrim) { shutSheet(); } }); }
    function shutSheet() { const el = invScrim || tw; if (window.KT && KT.popOverlay) { KT.popOverlay(el); } else { el.remove(); } if (invScrim && invScrim.parentNode) { invScrim.remove(); } }

    const header = Dom.el('div', { style: 'display:flex;align-items:center;gap:8px;padding:calc(var(--kt-safe-top, env(safe-area-inset-top,0px)) + 10px) 12px 12px;border-bottom:0;background:linear-gradient(135deg,#1B3A63 0%,#265B7A 50%,#2F8C86 100%);color:#fff;flex-shrink:0;' });   // the portal's popup header (2026-10-28)
    const back = Dom.el('button', { style: 'background:none;border:none;font-size:26px;color:#fff;cursor:pointer;padding:0 6px;line-height:1;flex-shrink:0;' }, '‹');
    back.addEventListener('click', () => { shutSheet(); });
    header.appendChild(back);
    header.appendChild(Dom.el('div', { style: 'font-weight:800;font-size:15px;color:#fff;flex:1;min-width:0;' }, 'Invoice ' + (inv.invoice_number || '')));

    /* A CLOSE CONTROL PEOPLE RECOGNISE (2026-09-17).

       Anthony: "view invoice under payment schedules doesnt have a close X to close the
       popup." The only way out was the '‹' on the left, which reads as "back" in a
       phone flow and as nothing at all in a full-screen overlay on a desktop — where the
       eye goes to the top RIGHT for a close. The chevron stays for the phone; this is the
       one a desktop reader looks for.

       Escape closes it too. It is a modal overlay, and every other dialog in the portal
       answers Escape; this one silently did not. */
    const shut = () => {
      document.removeEventListener('keydown', onEsc);
      shutSheet();
    };
    function onEsc(e) { if (e.key === 'Escape') { shut(); } }
    document.addEventListener('keydown', onEsc);
    back.addEventListener('click', () => document.removeEventListener('keydown', onEsc));

    const xBtn = Dom.el('button', {
      title: 'Close', 'aria-label': 'Close',
      style: 'border:none;background:rgba(255,255,255,.18);color:#fff;'
        + 'width:32px;height:32px;border-radius:50%;cursor:pointer;font-size:18px;line-height:1;'
        + 'flex-shrink:0;display:flex;align-items:center;justify-content:center;',
    }, '✕');
    xBtn.addEventListener('click', shut);
    header.appendChild(xBtn);
    tw.appendChild(header);

    /* The phone's own bar (2026-11-06, Anthony: "the bottom is cut off make this better - full sweep"): on a phone this
       sheet runs to the bottom of the screen, under the system navigation bar, and the body's 16px left the last lines
       of "Pay this invoice" beneath it. The safe-area inset is added to the bottom padding so the end scrolls clear. */
    const body = Dom.el('div', { style: 'flex:1;overflow-y:auto;-webkit-overflow-scrolling:touch;padding:16px 16px calc(var(--kt-safe-bottom, env(safe-area-inset-bottom,0px)) + 28px);' });
    tw.appendChild(body);
    if (inv.my_share) {
      const s = inv.my_share, m = function (v) { return '$' + Number(v || 0).toFixed(2); };
      body.appendChild(Dom.el('div', { style: 'background:#EFF6FF;border:1px solid #BFDBFE;color:#1E3A8A;border-radius:12px;padding:10px 12px;margin-bottom:12px;font-size:13.5px;line-height:1.5;' },
        'Your family splits this bill. Your share is ' + s.pct + '%: ' + m(s.share) + ' of ' + m(inv.family_total != null ? inv.family_total : inv.total)
        + '. You have paid ' + m(s.paid) + ' and owe ' + m(s.balance) + '.'));
    }

    const money = (n) => '$' + (Number(n) || 0).toFixed(2);
    const sc = inv.status === 'paid' ? 'var(--brand-green)' : (inv.status === 'overdue' ? '#c0392b' : 'var(--ink-500)');

    // Summary
    const sum = Dom.el('div', { style: card('padding:18px;margin-bottom:14px;text-align:center;') });
    const sumPill = Dom.el('span', { style: `display:inline-block;background:${sc};color:#fff;padding:3px 12px;border-radius:12px;font-size:11px;font-weight:800;text-transform:uppercase;margin-bottom:10px;` }, inv.status_label || inv.status);
    const sumAmt = Dom.el('div', { style: 'font-size:34px;font-weight:800;color:var(--ink-900);line-height:1;' }, money(inv.balance_due > 0 ? inv.balance_due : inv.total));
    const sumSub = Dom.el('div', { style: 'font-size:13px;color:var(--ink-500);margin-top:5px;' }, inv.balance_due > 0 ? `Balance due · by ${inv.due_date}` : 'Total');
    sum.appendChild(sumPill); sum.appendChild(sumAmt); sum.appendChild(sumSub);
    if (child) sum.appendChild(Dom.el('div', { style: 'font-size:12.5px;color:var(--ink-500);margin-top:6px;' }, `${child.display_name} · ${inv.issue_date ? 'Issued ' + inv.issue_date : ''}`));
    body.appendChild(sum);

    /* THE ACTUAL INVOICE, not a second drawing of the same numbers (2026-09-17).

       This sheet summarised an invoice — status, total, a breakdown — while the real
       document lived only in an email attachment. Anthony, from Accounting: "view
       invoice ... its not showing the actual invoice for the sanford family — this needs
       to show up with the template being used and also on the parent view should show
       the same as well."

       So the document itself is rendered here, in the agency's chosen template, from the
       same InvoiceDocument the emailed PDF comes from. One sheet serves both audiences,
       so a parent and the office are now looking at the same piece of paper.

       Fetched rather than framed by URL because the endpoints need the bearer token.
       Staff read /invoices/{id}/document (centre-scoped); a guardian reads their own
       through /parent/…, and staff fall back to it for their own family's invoice. */
    let reloadDoc = function () {};
    (function () {
      const docCard = Dom.el('div', { style: card('padding:0;margin-bottom:14px;overflow:hidden;') });
      const frame = Dom.el('iframe', {
        style: 'width:100%;height:520px;border:0;display:block;background:#fff;',
        title: 'Invoice ' + (inv.invoice_number || ''),
        'data-kt-keep-height': '1',
      });
      /* THE WHOLE PAGE, NOT A WINDOW ONTO IT (2026-11-04, iPhone recording): the frame takes the document's own
         height, and a document wider than the frame (the template is drawn for paper) is scaled down to fit. */
      frame.addEventListener('load', function () {
        try {
          const d = frame.contentDocument; if (!d || !d.body) return;
          d.documentElement.style.overflow = 'hidden';
          const fw = frame.clientWidth, dw = Math.max(d.documentElement.scrollWidth, d.body.scrollWidth);
          const z = dw > fw + 2 ? Math.max(0.3, fw / dw) : 1;
          d.body.style.zoom = z === 1 ? '' : String(z);
          frame.style.height = Math.ceil(d.documentElement.scrollHeight + 2) + 'px';   // read after the zoom: the scaled height
        } catch (e) {}
      });
      docCard.appendChild(frame);
      body.appendChild(docCard);

      const base = (window.KT && KT.API_BASE) || 'https://api.kiddietrac.com/api/v1';
      const tok = (function () {
        try { return sessionStorage.getItem('kt_token') || localStorage.getItem('kt_token'); } catch (e) { return null; }
      })();
      const paths = ktIsStaff()
        ? ['/invoices/' + inv.id + '/document', '/parent/invoices/' + inv.id + '/document']
        : ['/parent/invoices/' + inv.id + '/document'];

      reloadDoc = function () { load(); };
      async function load() {
        for (const p of paths) {
          try {
            const r = await fetch(base + p, { headers: { Authorization: 'Bearer ' + tok } });
            if (!r.ok) { continue; }
            frame.srcdoc = await r.text();
            return;
          } catch (e) { /* try the next one */ }
        }
        /* No document is not an error worth a red box — the figures above are still
           correct and the invoice may simply be too new to render. */
        docCard.remove();
      }
      load();
    })();

    // Breakdown
    const bd = Dom.el('div', { style: card('padding:16px;margin-bottom:14px;') });
    bd.appendChild(Dom.el('div', { style: 'font-weight:800;font-size:13px;color:var(--ink-700);margin-bottom:10px;text-transform:uppercase;letter-spacing:.4px;' }, 'Breakdown'));
    const rows = [['Subtotal', money(inv.subtotal)]];
    if (inv.subsidy_amount > 0) rows.push(['Subsidy', '−' + money(inv.subsidy_amount)]);
    if (inv.discount_amount > 0) rows.push(['Discount', '−' + money(inv.discount_amount)]);
    rows.push(['Total', money(inv.total)]);
    if (inv.amount_paid > 0) rows.push(['Paid', '−' + money(inv.amount_paid)]);
    const grid = Dom.el('div', { style: 'display:grid;grid-template-columns:1fr auto;gap:8px;font-size:14px;' });
    rows.forEach(([k, v], i) => {
      const last = k === 'Total';
      grid.appendChild(Dom.el('div', { style: `color:var(--ink-600);${last ? 'font-weight:800;color:var(--ink-900);border-top:1px solid var(--ink-200);padding-top:8px;' : ''}` }, k));
      grid.appendChild(Dom.el('div', { style: `text-align:right;${last ? 'font-weight:800;color:var(--brand-blue);border-top:1px solid var(--ink-200);padding-top:8px;' : ''}${k === 'CWELCC subsidy' || k === 'Discount' || k === 'Paid' ? 'color:var(--brand-green);' : ''}` }, v));
    });
    bd.appendChild(grid);
    body.appendChild(bd);

    /* Pay — for the person who owes it, not for the office.

       This sheet is the parent's, and staff reach it now too (see openInvoiceById). The
       copy underneath addresses the reader as the payer — "Send an Interac e-Transfer to
       your centre's billing" — and the button starts a card payment. An admin opening a
       family's invoice to look at it must not be handed a way to pay it on their own
       card by mistake; recording a payment they HAVE received is a different action, on
       the billing screen, with an audit trail. (2026-09-17) */
    /* PAID MEANS PAID ON THE WHOLE SHEET (2026-11-04, iPhone recording: after Apple Pay the line under the button said
       "Payment successful" while the top still read SCHEDULED / $120.50 balance due and the document UNPAID). */
    function showPaid() {
      sumPill.textContent = 'Paid'; sumPill.style.background = 'var(--brand-green,#16A34A)';
      sumAmt.textContent = money(inv.total);
      sumSub.textContent = 'Paid in full - thank you!';
      setTimeout(function () { reloadDoc(); }, 800);   // the document again, now that the server has the payment
    }
    if ((inv.balance_due || 0) > 0 && inv.status !== 'paid' && !ktIsStaff()) {
      const pay = Dom.el('div', { style: card('padding:16px;') });
      pay.appendChild(Dom.el('div', { style: 'font-weight:800;font-size:15px;margin-bottom:12px;color:var(--ink-900);' }, 'Pay this invoice'));
      const status = Dom.el('div', { style: 'font-size:13px;margin:0 0 10px;min-height:16px;line-height:1.5;' });
      const payBtn = Dom.el('button', { type: 'button', style: 'width:100%;border:0;cursor:pointer;padding:15px;border-radius:13px;font-size:16px;font-weight:800;color:#fff;background:linear-gradient(135deg,#0FA3B1,#1F6FB2 60%,#2456A6);box-shadow:0 12px 24px -12px rgba(31,111,178,.55);' }, `Pay ${money(inv.balance_due)} by card`);
      if (_ktPayInfo && _ktPayInfo.demo_pay) { payBtn.textContent = `Pay ${money(inv.balance_due)}`; }
      payBtn.addEventListener('click', async () => {
        if (_ktPayInfo && _ktPayInfo.demo_pay) { ktDemoPay([inv], function () { shutSheet(); }); return; }
        status.textContent = ''; payBtn.disabled = true; payBtn.textContent = 'Processing…';
        try {
          await Api.post(`/invoices/${inv.id}/charge`, {});
          status.style.color = '#16A34A'; status.textContent = '✓ Payment successful — thank you!';
          payBtn.style.display = 'none';
          bust(`/parent/children/${child.id}/invoices`); bust(`/parent/children/${child.id}/invoices?status=current`);
          showPaid();
        } catch (e) {
          status.style.color = '#B45309';
          status.textContent = 'Card payments aren’t switched on for your centre yet. Please use e-transfer below, or ask your centre to enable online payments.';
          payBtn.disabled = false; payBtn.textContent = `Pay ${money(inv.balance_due)} by card`;
        }
      });
      const walletSlot = Dom.el('div');
      pay.appendChild(walletSlot);
      /* NO CARD BUTTON FOR PARENTS (2026-11-04, Anthony: "turn off the card payments and don't let that show"). It
         called /invoices/{id}/charge, which only staff may use, so a parent always got "Card payments aren't switched
         on". A parent pays with their phone's wallet (above) or e-Transfer (below). The web demo keeps its button. */
      if (_ktPayInfo && _ktPayInfo.demo_pay) pay.appendChild(payBtn);
      pay.appendChild(status);
      /* Apple Pay / Google Pay (2026-11-03): whatever this invoice's agency can take - the agency's Stripe
         (browser or the app's native sheet) and Helcim (card + Google Pay). See kt-wallet-pay.js. */
      if (!(_ktPayInfo && _ktPayInfo.demo_pay) && window.KT && KT.walletPay) {
        KT.walletPay.mount(walletSlot, inv, {
          cardBtn: payBtn,
          say: function (t, ok) { status.style.color = ok ? '#16A34A' : '#B45309'; status.textContent = t || ''; },
          onPaid: function () {
            status.style.color = '#16A34A'; status.textContent = '✓ Payment successful — thank you!';
            payBtn.style.display = 'none'; walletSlot.style.display = 'none';
            bust(`/parent/children/${child.id}/invoices`); bust(`/parent/children/${child.id}/invoices?status=current`);
            showPaid();
          },
        });
      }
      if (_ktPayInfo && _ktPayInfo.demo_pay) {
        pay.appendChild(Dom.el('div', { style: 'margin-top:10px;font-size:12px;color:var(--ink-500);text-align:center;' }, 'Card or bank account · Demo - no real payment is made'));
      } else if (_ktPayInfo && _ktPayInfo.interac) {
        const _ib = ktInteracButton(_ktPayInfo, [{ number: inv.invoice_number, amount: inv.balance_due }], { block: true, label: 'Pay by Interac e-Transfer instead' });
        _ib.style.marginTop = '12px'; _ib.style.height = '46px';
        pay.appendChild(_ib);
      } else {
        pay.appendChild(Dom.el('div', { style: 'margin-top:12px;padding:12px;background:var(--ink-50);border-radius:10px;font-size:12.5px;color:var(--ink-600);line-height:1.5;' },
          'Prefer e-Transfer? Send an Interac e-Transfer to your centre’s billing email and put your invoice number in the message. Your centre can confirm the exact address.'));
      }
      body.appendChild(pay);
    } else if (inv.status === 'paid') {
      body.appendChild(Dom.el('div', { style: card('padding:18px;text-align:center;color:#16A34A;font-weight:800;font-size:15px;') }, '✓ Paid — thank you!'));
    }

    appMain.appendChild(invScrim || tw);
    if (window.KT && KT.pushOverlay) KT.pushOverlay(invScrim || tw);
  }

  // ─── MOBILE · MESSAGES (list → full-screen thread) ─────────────────
  async function renderMessagesMobile(wrap) {
    const hrow = Dom.el('div', { style: 'display:flex;align-items:center;justify-content:space-between;margin:2px 2px 4px;' });
    hrow.appendChild(Dom.el('div', { style: 'font-size:18px;font-weight:800;color:var(--ink-900);' }, 'Messages'));
    const newBtn = Dom.el('button', { type: 'button', style: 'display:flex;align-items:center;gap:5px;background:#159FB4;color:#fff;border:none;border-radius:20px;padding:7px 13px;font-size:13px;font-weight:700;cursor:pointer;' }, [Dom.el('span', { style: 'font-size:14px;' }, '✎'), 'New']);
    newBtn.addEventListener('click', () => openComposeNew());
    hrow.appendChild(newBtn);
    wrap.appendChild(hrow);
    wrap.appendChild(Dom.el('div', { style: 'display:flex;gap:7px;align-items:flex-start;background:rgba(31,96,128,.07);border-radius:12px;padding:9px 12px;margin:0 0 12px;font-size:12px;color:#41708a;line-height:1.4;' }, [
      Dom.el('span', {}, 'ℹ️'),
      Dom.el('span', {}, "Messages go to your child's room team at the centre — your educators and centre director all see and can reply."),
    ]));
    const listWrap = Dom.el('div'); wrap.appendChild(listWrap);
    listWrap.appendChild(Dom.el('div', { style: 'text-align:center;color:var(--ink-500);padding:20px;font-size:13px;' }, 'Loading…'));

    let data;
    try { data = await cget('/parent/messages'); }
    catch (e) { Dom.clear(listWrap); listWrap.appendChild(emptyState('⚠️', 'Could not load', e.message)); return; }

    Dom.clear(listWrap);
    if (!data.conversations || !data.conversations.length) {
      listWrap.appendChild(emptyState('💬', 'No conversations', 'Messages with your educators will appear here.'));
      return;
    }
    data.conversations.forEach(c => {
      const title = c.child_name || c.subject || 'Conversation';
      const subtitle = (c.child_name && c.subject) ? c.subject : '';
      const row = Dom.el('button', { style: 'width:100%;text-align:left;display:flex;align-items:center;gap:12px;border:none;cursor:pointer;padding:13px;margin-bottom:9px;' + card() });
      const _kid = (state.children || []).find(k => (c.child_id && Number(k.id) === Number(c.child_id)) || (!c.child_id && c.child_name && (k.display_name === c.child_name || k.first_name === c.child_name)));
      row.appendChild((_kid && childAvatarEl(_kid, 44)) || Dom.el('div', { style: 'width:44px;height:44px;border-radius:50%;color:#fff;display:flex;align-items:center;justify-content:center;font-size:17px;font-weight:800;flex-shrink:0;background:#159FB4;' },
        title[0].toUpperCase()));
      const mid = Dom.el('div', { style: 'flex:1;min-width:0;' });
      mid.appendChild(Dom.el('div', { style: 'display:flex;align-items:center;gap:8px;' }, [
        Dom.el('div', { style: 'font-weight:700;font-size:14.5px;color:var(--ink-900);flex:1;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;' }, title),
        Dom.el('div', { style: 'font-size:11px;color:var(--ink-500);flex-shrink:0;' }, c.last_message_display || ''),
      ]));
      if (subtitle) mid.appendChild(Dom.el('div', { style: 'font-size:12px;color:var(--ink-500);margin-top:1px;' }, subtitle));
      if (c.last_message_preview) {
        const prev = c.last_message_preview.length > 54 ? c.last_message_preview.slice(0, 54) + '…' : c.last_message_preview;
        mid.appendChild(Dom.el('div', { style: `font-size:12.5px;margin-top:3px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;${c.unread_count > 0 ? 'color:var(--ink-800);font-weight:600;' : 'color:var(--ink-500);'}` }, prev));
      }
      row.appendChild(mid);
      if (c.unread_count > 0) row.appendChild(Dom.el('div', { style: 'min-width:20px;height:20px;padding:0 6px;border-radius:10px;background:var(--brand-green);color:#fff;font-size:11px;font-weight:800;display:flex;align-items:center;justify-content:center;flex-shrink:0;' }, String(c.unread_count)));
      row.addEventListener('click', () => openThreadMobile(c));
      listWrap.appendChild(row);
    });
  }

  // Full-screen conversation thread. Uses the .kt-thread-* class names so the
  // mobile stylesheet's full-height flex treatment (and nav-hide) apply.
  // A small avatar element for a message sender (photo or coloured initials).
  function msgAvatar(name, photoUrl, color) {
    const holder = document.createElement('span');
    // A real photo when we have one; otherwise a coloured initial using the
    // SAME per-sender colour as the name label + bubble border (see senderColor).
    if (photoUrl && window.KT && KT.avatar) {
      holder.innerHTML = KT.avatar(name || '?', { size: 30, photoUrl: absUrl(photoUrl) });
      return holder.firstElementChild || holder;
    }
    holder.textContent = (name || '?').charAt(0).toUpperCase();
    holder.style.cssText = 'width:30px;height:30px;border-radius:50%;background:' + (color || '#159FB4') + ';color:#fff;display:inline-flex;align-items:center;justify-content:center;font-weight:800;font-size:13px;flex-shrink:0;';
    return holder;
  }
  // Deterministic colour per participant name — same person, same colour. Kept
  // byte-identical to screen-chat.js so a person looks the same in both chats.
  function senderColor(s) {
    var pal = ['#1F6FB2', '#0FA3B1', '#E0699A', '#7C3AED', '#F59E0B', '#10B981', '#EF6C4D', '#0891B2', '#DB2777', '#4F8A3D'];
    s = String(s || ''); var h = 0;
    for (var i = 0; i < s.length; i++) h = (h * 31 + s.charCodeAt(i)) >>> 0;
    return pal[h % pal.length];
  }
  function ktToast(icon, msg) {
    try { if (window.KT && KT.toast) return KT.toast(icon, msg, '', '#159FB4'); } catch (e) {}
    const t = Dom.el('div', { style: 'position:fixed;left:50%;bottom:90px;transform:translateX(-50%);z-index:14000;background:#0D1B2A;color:#fff;padding:11px 18px;border-radius:24px;font-size:14px;font-weight:600;box-shadow:0 8px 24px rgba(0,0,0,.3);max-width:88%;text-align:center;' }, (icon ? icon + ' ' : '') + msg);
    document.body.appendChild(t); setTimeout(() => t.remove(), 2600);
  }
  const KT_EMOJIS = ['😀','😄','😊','🥰','😍','😘','😉','🤗','🙂','😢','😭','😔','😴','🤒','🤧','🥳','🎉','👍','👏','🙏','💚','❤️','🔥','✨','⭐','🌟','👋','🤝','💪','🙌','👶','🍼','🧸','🎨','📸','🎵','☀️','🌈','🍎','🥪','💤','✅','❓','❗'];

  const QUICK_REACTS = ['👍','❤️','😂','😮','😢','🙏'];
  /* One to three emoji and nothing else - shown big (same rule as screen-chat.js emojiOnly, 2026-11-05). new RegExp so
     an old WebView without \p{} just skips it. */
  let EMO_RE = null;
  try { EMO_RE = [new RegExp('^(?:\\p{Extended_Pictographic}|\\p{Emoji_Component}|\\u200d|\\ufe0f|\\s)+$', 'u'), new RegExp('\\p{Extended_Pictographic}', 'gu')]; } catch (e) {}
  function emojiOnly(t) {
    t = String(t || '').trim();
    if (!EMO_RE || !t || t.length > 40 || /[0-9A-Za-z#*]/.test(t) || !EMO_RE[0].test(t)) return false;
    let n;
    try { n = Array.from(new Intl.Segmenter().segment(t.replace(/\s+/g, ''))).length; }
    catch (e) { n = (t.match(EMO_RE[1]) || []).length; }
    return n >= 1 && n <= 3;
  }
  /* A TAP OUTSIDE CLOSES IT (2026-11-05, Anthony: "clicking outside of the emoji picker should close the emoji
     picker"). The parent emoji panel never closed on a tap outside, and the reaction picker closed on a bubbling
     'click' that the message buttons stop. A capture-phase pointerdown runs before any handler can stop it - the same
     rule as screen-chat.js closeOnOutsideTap. keep: what a tap inside does not close (the popover, its button).
     close() returning true = one-shot: the listener goes. It also goes once the popover has left the page. */
  function closeOnOutsideTap(keep, close, isOpen) {
    function h(ev) {
      if (!keep[0] || !keep[0].isConnected) { document.removeEventListener('pointerdown', h, true); return; }
      if (isOpen && !isOpen()) return;
      for (var i = 0; i < keep.length; i++) if (keep[i] && keep[i].contains(ev.target)) return;
      if (close() === true) document.removeEventListener('pointerdown', h, true);
    }
    document.addEventListener('pointerdown', h, true);
  }

  function openThreadMobile(convo) {
    const appMain = document.getElementById('appMain');
    if (!appMain) return;
    // Never stack threads — close any already-open one first.
    appMain.querySelectorAll('.kt-thread-compose').forEach(c => { const w = c.closest('div'); if (w && w.parentNode === appMain) w.remove(); });

    const childId = convo.child_id || state.selectedChildId;

    const tw = Dom.el('div', { style: 'animation:kt-screen-in .22s cubic-bezier(.22,.61,.36,1);' });

    // Tinted header — matches the educator chat's tinted title bar so the two
    // chats look like one product.
    const header = Dom.el('div', { class: 'kt-thread-header', style: 'display:flex;align-items:center;gap:8px;padding:12px 12px;border-bottom:1px solid rgba(31,96,128,.12);background:linear-gradient(135deg,#EAF3FB,#F3F0FF);' });
    const back = Dom.el('button', { type: 'button', style: 'background:none;border:none;font-size:26px;color:#159FB4;cursor:pointer;padding:0 6px;line-height:1;flex-shrink:0;' }, '‹');
    const closeThread = () => {
      try { if (pollTimer) clearInterval(pollTimer); } catch (e) {}
      if (tw.parentNode && tw.parentNode.classList && tw.parentNode.classList.contains('kt-cd-body')) { KT.ChatDock.close(); return; }
      if (window.KT && KT.popOverlay) KT.popOverlay(tw); else tw.remove();
    };
    back.addEventListener('click', closeThread);
    header.appendChild(back);
    const convoTitle = convo.child_name || convo.subject || 'Conversation';
    const htxt = Dom.el('div', { style: 'flex:1;min-width:0;' });
    htxt.appendChild(Dom.el('div', { style: 'font-weight:800;font-size:15px;color:var(--ink-900);overflow:hidden;text-overflow:ellipsis;white-space:nowrap;' }, convoTitle));
    htxt.appendChild(Dom.el('div', { style: 'font-size:12px;color:var(--ink-500);' }, (convo.child_name && convo.subject) ? convo.subject : 'Messages'));
    header.appendChild(htxt);
    // Desktop shows an explicit ✕ to close the floating chat panel (mobile uses the ‹ back).
    var closeX = Dom.el('button', { type: 'button', title: 'Close', class: 'kt-thread-close-desk', style: 'background:var(--ink-100);border:none;width:40px;height:40px;border-radius:50%;font-size:19px;cursor:pointer;flex-shrink:0;color:var(--ink-600);line-height:1;align-items:center;justify-content:center;' }, '✕');
    closeX.addEventListener('click', closeThread);
    header.appendChild(closeX);
    tw.appendChild(header);

    const body = Dom.el('div', { class: 'kt-thread-body', style: 'padding:14px;display:flex;flex-direction:column;gap:8px;background:var(--ink-50);' });
    body.appendChild(Dom.el('div', { style: 'text-align:center;color:var(--ink-500);font-size:13px;padding:20px;' }, 'Loading…'));
    tw.appendChild(body);

    // Emoji panel (hidden until toggled).
    // .kt-emoji-panel.kt-emoji-bar: the one emoji grid (kt-chat-composer.css), full width here, under the thread.
    const emojiPanel = Dom.el('div', { class: 'kt-emoji-panel kt-emoji-bar', style: 'display:none;flex-wrap:wrap;gap:2px;padding:8px 10px;background:#fff;border-top:1px solid var(--ink-100);max-height:150px;overflow-y:auto;' });
    KT_EMOJIS.forEach(em => {
      const b = Dom.el('button', { type: 'button', style: 'background:none;border:none;font-size:22px;line-height:1;padding:6px;cursor:pointer;border-radius:8px;' }, em);
      b.addEventListener('click', () => { insertEmoji(em); });
      emojiPanel.appendChild(b);
    });
    tw.appendChild(emojiPanel);

    const compose = Dom.el('form', { class: 'kt-thread-compose kt-compose-row', style: 'display:flex;gap:6px;align-items:center;padding:10px 12px;border-top:1px solid var(--ink-100);background:#fff;' });
    // 👋 beside the ＋ (kt-compose-plus.js), where the header button used to be.
    if (childId) { compose.setAttribute('data-kt-nudge', '/parent/messages/nudge'); compose.setAttribute('data-kt-nudge-body', JSON.stringify({ child_id: childId })); }
    const fileInput = Dom.el('input', { type: 'file', accept: 'image/*', multiple: 'multiple', style: 'display:none;' });
    const emojiBtn = Dom.el('button', { type: 'button', title: 'Emoji', style: 'background:none;border:none;width:40px;height:44px;font-size:22px;cursor:pointer;flex-shrink:0;' }, '😊');
    emojiBtn.addEventListener('click', () => { emojiPanel.style.display = emojiPanel.style.display === 'none' ? 'flex' : 'none'; });
    closeOnOutsideTap([emojiPanel, emojiBtn], () => { emojiPanel.style.display = 'none'; }, () => emojiPanel.style.display !== 'none');
    const attach = Dom.el('button', { type: 'button', title: 'Add a photo', style: 'background:none;color:#159FB4;border:none;width:40px;height:44px;font-size:22px;cursor:pointer;flex-shrink:0;' }, '📷');
    attach.addEventListener('click', () => fileInput.click());
    const input = Dom.el('input', { class: 'kt-compose-input', type: 'text', placeholder: 'Message…', style: 'flex:1;min-width:0;padding:13px 15px;border:1px solid var(--ink-200);border-radius:24px;font-size:16px;min-height:46px;box-sizing:border-box;' });
    // One button that is Mic when empty, Send when there's text, Stop while recording.
    const action = Dom.el('button', { type: 'button', class: 'kt-send-one', title: 'Send', 'aria-label': 'Send' }, '🎤');
    // THE ONE COMPOSER ROW (.kt-compose-row, kt-chat-composer.css): tools, the box, the ➤ straight after it.
    compose.appendChild(emojiBtn); compose.appendChild(attach); compose.appendChild(fileInput);
    // 🚨 Emergency toggle (kt-emergency.js): rings on the centre's phones.
    const mobEm = (window.KT && KT.emergency) ? KT.emergency.button() : null;
    if (mobEm) compose.appendChild(mobEm);
    compose.appendChild(input);
    compose.appendChild(action);
    tw.appendChild(compose);

    // Desktop: host the thread in the persistent, minimisable chat dock (survives
    // navigation, flashes on new messages). Phones keep the full-screen takeover.
    if (KT.ChatDock && KT.ChatDock.enabled()) {
      KT.ChatDock.contentEl().appendChild(tw);
      KT.ChatDock.show(convo.child_name || convo.subject || 'Messages', function () { try { if (pollTimer) clearInterval(pollTimer); } catch (e) {} });
    } else {
      appMain.appendChild(tw);
      if (window.KT && KT.pushOverlay) KT.pushOverlay(tw, closeThread);
    }

    function insertEmoji(em) {
      const s = input.selectionStart != null ? input.selectionStart : input.value.length;
      const e = input.selectionEnd != null ? input.selectionEnd : input.value.length;
      input.value = input.value.slice(0, s) + em + input.value.slice(e);
      input.focus(); const pos = s + em.length; try { input.setSelectionRange(pos, pos); } catch (x) {}
      updateAction();
    }

    let editingId = null;               // when set, the composer edits an existing message
    const editBanner = Dom.el('div', { style: 'display:none;align-items:center;gap:8px;padding:7px 12px;background:#FEF3C7;border-top:1px solid #FDE68A;font-size:12.5px;color:#92400E;font-weight:600;' });
    const editBannerX = Dom.el('button', { type: 'button', style: 'margin-left:auto;background:none;border:none;color:#92400E;font-size:16px;cursor:pointer;font-weight:800;' }, '✕');
    editBanner.appendChild(Dom.el('span', {}, '✏️ Editing message'));
    editBanner.appendChild(editBannerX);
    tw.insertBefore(editBanner, compose);   // sits just above the composer
    function updateAction() {
      if (editingId) { action.textContent = '✓'; action.style.background = '#159FB4'; return; }
      if (input.value.trim()) { action.textContent = '➤'; action.style.background = '#159FB4'; action.style.paddingLeft = '2px'; return; }
      action.textContent = '🎤'; action.style.background = '#159FB4'; action.style.paddingLeft = '0';
    }
    input.addEventListener('input', updateAction);
    editBannerX.addEventListener('click', () => cancelEdit());
    function startEdit(m) {
      editingId = m.id; input.value = m.body || ''; editBanner.style.display = 'flex';
      input.focus(); updateAction();
    }
    function cancelEdit() { editingId = null; input.value = ''; editBanner.style.display = 'none'; updateAction(); }
    // Action sheet for a message you sent: Edit (text only) / Delete.
    function msgActions(m) {
      const ov = Dom.el('div', { style: 'position:fixed;inset:0;z-index:14500;background:rgba(8,17,33,.5);display:flex;align-items:flex-end;' });
      const sheet = Dom.el('div', { style: 'background:#fff;width:100%;border-radius:20px 20px 0 0;padding:8px 12px calc(var(--kt-safe-bottom, env(safe-area-inset-bottom,0px)) + 12px);' });
      const mk = (icon, label, color, fn) => { const btn = Dom.el('button', { type: 'button', style: `display:flex;align-items:center;gap:12px;width:100%;background:none;border:none;padding:15px 12px;font-size:16px;font-weight:600;color:${color};cursor:pointer;text-align:left;border-bottom:1px solid var(--ink-100);` }, [Dom.el('span', { style: 'font-size:20px;' }, icon), label]); btn.addEventListener('click', () => { ov.remove(); fn(); }); return btn; };
      if (m.can_edit) sheet.appendChild(mk('✏️', 'Edit', 'var(--ink-900)', () => startEdit(m)));
      if (m.can_delete) sheet.appendChild(mk('🗑', 'Delete', '#DC2626', () => delMsg(m)));
      const cancel = mk('✕', 'Cancel', 'var(--ink-500)', () => {}); cancel.style.borderBottom = 'none';
      sheet.appendChild(cancel);
      ov.appendChild(sheet); ov.addEventListener('click', (e) => { if (e.target === ov) ov.remove(); });
      document.body.appendChild(ov);
    }
    async function delMsg(m) {
      try { await Api.delete('/parent/messages/' + m.id); bust('/parent/messages'); await load(); ktToast('🗑', 'Message deleted'); }
      catch (e) { ktToast('⚠️', (e && e.message) || 'Could not delete'); }
    }
    /* ── Voice notes ──────────────────────────────────────────────────────
       KT.recordVoiceNote (kt-voice-recorder.js) is the one recorder for the whole
       portal. This screen already refused to auto-send and offered a play-back
       bar, which was the right instinct — what it could not do was show that the
       microphone was hearing anything, so a muted headset looked exactly like a
       working one until the note arrived silent. The shared recorder adds the
       live level meter and the timer, and keeps the rule this screen already
       had: nothing is sent until the person has heard it. */
    async function recordVoice() {
      if (!(window.KT && KT.recordVoiceNote)) { ktToast('⚠️', 'Voice messages aren’t supported on this device'); return; }
      action.disabled = true;
      try {
        const file = await KT.recordVoiceNote({ anchor: compose, acceptLabel: 'Send' });
        if (file) doSend(file);
      } finally {
        action.disabled = false;
        updateAction();
      }
    }

    action.addEventListener('click', () => {
      if (input.value.trim()) { doSend(null); return; }
      recordVoice();
    });

    // -- Emoji reactions: work on ANY message, incl. the other person's. --
    var currentMsgs = [];
    async function doReact(m, emoji) {
      try {
        const res = await Api.post('/parent/messages/' + convo.id + '/react/' + m.id, { emoji: emoji });
        m.reactions = (res && res.reactions) || [];
        const atBottom = body.scrollTop + body.clientHeight >= body.scrollHeight - 60;
        const prevTop = body.scrollTop;
        renderMsgs(currentMsgs);
        body.scrollTop = atBottom ? body.scrollHeight : prevTop;
      } catch (e) { ktToast('⚠️', (e && e.message) || 'Could not react'); }
    }
    function closeReactPicker() { document.querySelectorAll('.kt-react-pop').forEach((p) => p.remove()); }
    function showReactPicker(anchor, m) {
      closeReactPicker();
      const pop = Dom.el('div', { class: 'kt-react-pop', style: 'position:fixed;z-index:15000;display:flex;gap:2px;background:#fff;border:1px solid rgba(0,0,0,.12);border-radius:22px;padding:4px 6px;box-shadow:0 8px 24px rgba(0,0,0,.18);' });
      QUICK_REACTS.forEach((em) => {
        const eb = Dom.el('button', { type: 'button', style: 'background:none;border:none;font-size:22px;line-height:1;padding:4px;cursor:pointer;border-radius:50%;' }, em);
        eb.addEventListener('click', (ev) => { ev.stopPropagation(); closeReactPicker(); doReact(m, em); });
        pop.appendChild(eb);
      });
      document.body.appendChild(pop);
      const r = anchor.getBoundingClientRect();
      let top = r.top - 46; if (top < 8) top = r.bottom + 6;
      const pw = pop.offsetWidth || 220;
      let left = r.left - 20;
      if (left + pw > window.innerWidth - 8) left = window.innerWidth - 8 - pw;
      if (left < 8) left = 8;
      pop.style.top = top + 'px'; pop.style.left = left + 'px';
      closeOnOutsideTap([pop, anchor], () => { pop.remove(); return true; });
    }

    /* Sends still on their way (2026-11-11): faded bubbles that stay last whenever the list redraws (load, poll,
       a reaction), so a message typed straight after another never vanishes while the first one posts. */
    var mobPending = [];
    function renderMsgs(msgs) {
      renderMsgsAll(msgs);
      mobPending.forEach(g => body.appendChild(g));
    }
    function renderMsgsAll(msgs) {
        currentMsgs = msgs;
        Dom.clear(body);
        if (!msgs.length) { body.appendChild(Dom.el('div', { style: 'text-align:center;color:var(--ink-500);font-size:13px;padding:24px;' }, 'No messages yet — say hello 👋')); return; }
        msgs.forEach(m => {
          // System messages (e.g. a nudge) render as a centered pill in the history.
          if (m.is_system) {
            body.appendChild(Dom.el('div', { style: 'align-self:center;background:rgba(15,23,42,.06);color:var(--ink-600);font-size:12px;font-weight:600;padding:5px 12px;border-radius:14px;margin:2px 0;text-align:center;max-width:88%;' }, (m.body || '') + ' · ' + m.time_display));
            return;
          }
          const mine = m.is_mine;
          const col = senderColor(m.sender_name);
          const row = Dom.el('div', { style: `display:flex;gap:6px;align-items:flex-end;max-width:100%;${mine ? 'flex-direction:row-reverse;' : ''}` });
          if (!mine) row.appendChild(msgAvatar(m.sender_name, m.sender_photo_url, col));
          // Outgoing = teal brand tint (right); incoming = violet brand tint (left).
          // Two distinct filled bubbles, like a real chat.
          const b = Dom.el('div', { style: `position:relative;max-width:74%;padding:9px 13px;border-radius:16px;font-size:14px;line-height:1.4;${mine ? 'background:#DCF1F4;color:#0D1B2A;border-bottom-right-radius:5px;' : 'background:#EFEAFB;color:#1E1B34;border-bottom-left-radius:5px;'}` });
          if (!mine) b.appendChild(Dom.el('div', { style: 'font-size:11px;font-weight:800;color:' + col + ';margin-bottom:3px;' }, m.sender_name));
          if (m.is_emergency && !m.deleted && window.KT && KT.emergency) b.insertBefore(KT.emergency.tagEl(), b.firstChild);
          if (m.deleted) {
            b.appendChild(Dom.el('div', { style: `font-style:italic;opacity:.8;` }, '🚫 Message deleted'));
          } else {
            // A lone 😀 shows big, in a bubble with no fill (kt-chat-composer.css .kt-emo-big / .kt-emo-bubble).
            const emo = !(m.attachments && m.attachments.length) && emojiOnly(m.body);
            if (emo) b.classList.add('kt-emo-bubble');
            if (m.body) b.appendChild(Dom.el('div', emo ? { class: 'kt-emo-big' } : {}, emo ? String(m.body).trim() : m.body));
            (m.attachments || []).forEach(a => {
              if (!a || !a.url) return;
              if (a.type === 'audio') {
                const au = Dom.el('audio', { controls: '', preload: 'metadata', src: absUrl(a.url), style: 'margin-top:6px;width:210px;max-width:100%;height:40px;display:block;' });
                b.appendChild(au);
              } else {
                const img = Dom.el('img', { src: absUrl(a.url), alt: 'Photo', loading: 'lazy', 'data-kt-thread-photo': '1', style: 'max-width:210px;width:100%;border-radius:10px;margin-top:6px;display:block;cursor:pointer;' });
                /* In the app, not a new tab — the APK's WebView opens no tab, so this did
                   nothing on a phone. Every photo in the conversation is one sequence. */
                img.addEventListener('click', () => {
                  if (window.KT && KT.mediaViewer) {
                    KT.mediaViewer.fromElements(body, 'img[data-kt-thread-photo]', img, {
                      download: function (it, btn) { downloadPhoto(it.url, '', btn); },
                    });
                  } else { window.open(absUrl(a.url), '_blank'); }
                });
                b.appendChild(img);
              }
            });
          }
          const foot = Dom.el('div', { style: `display:flex;align-items:center;gap:6px;font-size:10.5px;margin-top:3px;${mine ? 'color:rgba(13,27,42,.5);justify-content:flex-end;' : 'color:var(--ink-500);'}` });
          foot.appendChild(Dom.el('span', {}, m.time_display + (m.edited ? ' · edited' : '')));
          // Any non-deleted message can be reacted to — including the OTHER
          // person's (previously you could only act on your own messages).
          if (!m.deleted && !m.is_system) {
            /* The same quiet "+" every other chat thread uses (kt-chat-reply.js reactBtn, 2026-11-27): the faint ☺ at
               70% was hard to see and read as "send a smiley". */
            let reactBtn = null;
            if (window.KT && KT.chatReply && KT.chatReply.reactBtn) {
              const tpl = document.createElement('template');
              tpl.innerHTML = KT.chatReply.reactBtn(m.id, 'kt-pm-react');
              reactBtn = tpl.content.firstElementChild;
            }
            if (!reactBtn) reactBtn = Dom.el('button', { type: 'button', title: 'React', style: 'background:none;border:none;color:inherit;opacity:.7;font-size:14px;line-height:1;cursor:pointer;padding:0 2px;' }, '☺');
            reactBtn.addEventListener('click', (ev) => { ev.stopPropagation(); showReactPicker(reactBtn, m); });
            foot.appendChild(reactBtn);
          }
          // ⋯ on a message from the centre: report it, or block the person (kt-report-block.js, 2026-11-03).
          if (!mine && !m.deleted && !m.is_system && m.sender_id && window.KT && KT.reportBlock) {
            foot.appendChild(KT.reportBlock.btnEl({ kind: 'family', conv: convo.id, mid: m.id, uid: m.sender_id, name: m.sender_name, blocked: m.blocked }));
          }
          // Own, non-deleted messages get an edit/delete menu.
          if (mine && !m.deleted && (m.can_edit || m.can_delete)) {
            const menuBtn = Dom.el('button', { type: 'button', title: 'Options', style: 'background:none;border:none;color:inherit;opacity:.85;font-size:15px;line-height:1;cursor:pointer;padding:0 2px;' }, '⋯');
            menuBtn.addEventListener('click', (ev) => { ev.stopPropagation(); msgActions(m); });
            foot.appendChild(menuBtn);
          }
          b.appendChild(foot);
          // Existing reactions as tappable chips (tap your own to remove it).
          if (m.reactions && m.reactions.length) {
            const rx = Dom.el('div', { style: 'display:flex;flex-wrap:wrap;gap:4px;margin-top:5px;' });
            m.reactions.forEach((rr) => {
              const chip = Dom.el('button', { type: 'button', style: 'display:inline-flex;align-items:center;gap:3px;border:1px solid ' + (rr.mine ? '#159FB4' : 'rgba(0,0,0,.12)') + ';background:' + (rr.mine ? '#DCF1F4' : '#fff') + ';border-radius:12px;padding:1px 7px;font-size:12px;cursor:pointer;line-height:1.6;' }, rr.emoji + (rr.count > 1 ? ' ' + rr.count : ''));
              chip.addEventListener('click', (ev) => { ev.stopPropagation(); doReact(m, rr.emoji); });
              rx.appendChild(chip);
            });
            b.appendChild(rx);
          }
          row.appendChild(b);
          body.appendChild(row);
        });
    }
    // Signature of the message list — lets the poller tell when something changed.
    function sigOf(msgs) { var last = msgs[msgs.length - 1]; return msgs.length + '|' + (last ? (last.id + ':' + (last.edited ? 1 : 0) + ':' + (last.deleted ? 1 : 0)) : ''); }
    let lastSig = null, pollTimer = null, lastLen = 0;
    // ── Live "… is typing" indicator (driven by md.typing_users from the poll). ──
    var typingEl = null;
    function setTyping(names) {
      if (!names || !names.length) { if (typingEl && typingEl.parentNode) typingEl.parentNode.removeChild(typingEl); typingEl = null; return; }
      var label = names.length === 1 ? (names[0].split(' ')[0] + ' is typing') : 'Several people are typing';
      if (!document.getElementById('kt-typing-kf')) {
        var st = document.createElement('style'); st.id = 'kt-typing-kf';
        st.textContent = '@keyframes kt-typedot{0%,60%,100%{opacity:.25;transform:translateY(0)}30%{opacity:1;transform:translateY(-3px)}}';
        document.head.appendChild(st);
      }
      if (!typingEl) {
        typingEl = Dom.el('div', { style: 'display:flex;align-items:flex-end;gap:6px;max-width:100%;margin-top:2px;' });
        var bub = Dom.el('div', { style: 'background:#EFEAFB;border-radius:16px;border-bottom-left-radius:5px;padding:10px 14px;display:inline-flex;align-items:center;gap:9px;' });
        var dots = Dom.el('div', { style: 'display:inline-flex;gap:4px;' });
        for (var i = 0; i < 3; i++) dots.appendChild(Dom.el('span', { style: 'width:6px;height:6px;border-radius:50%;background:#7C3AED;display:inline-block;animation:kt-typedot 1.2s infinite;animation-delay:' + (i * 0.2) + 's;' }));
        bub.appendChild(dots);
        bub.appendChild(Dom.el('span', { class: 'kt-typing-lbl', style: 'font-size:12px;font-weight:700;color:#6D28D9;' }, label));
        typingEl.appendChild(bub);
      } else {
        var l = typingEl.querySelector('.kt-typing-lbl'); if (l) l.textContent = label;
      }
      // renderMsgs clears `body`, so always re-append the indicator as the last row.
      if (typingEl.parentNode !== body) body.appendChild(typingEl);
      else body.appendChild(typingEl);
      var atBottom = body.scrollTop + body.clientHeight >= body.scrollHeight - 80;
      if (atBottom) body.scrollTop = body.scrollHeight;
    }
    // Throttled "I'm typing" ping to the server while composing.
    var lastTypingPing = 0;
    input.addEventListener('input', function () {
      if (!childId) return;
      var now = Date.now();
      if (now - lastTypingPing < 2500) return;
      lastTypingPing = now;
      Api.post('/parent/messages/' + convo.id + '/typing', {}).catch(function () {});
    });
    const load = async () => {
      try {
        const md = await Api.get('/parent/messages/' + convo.id);
        const msgs = md.messages || [];
        lastSig = sigOf(msgs); lastLen = msgs.length;
        renderMsgs(msgs);
        body.scrollTop = body.scrollHeight;
      } catch (e) {
        Dom.clear(body);
        body.appendChild(Dom.el('div', { style: 'color:var(--ink-500);padding:20px;text-align:center;font-size:13px;' }, 'Could not load: ' + e.message));
      }
    };
    if (window.KT && KT.reportBlock) KT.reportBlock.onChange = load;   // Report + Block: redraw after a block or unblock
    // Live updates: poll every few seconds so new messages appear with no refresh.
    // Keeps the user's scroll position unless they're already at the bottom.
    const poll = async () => {
      if (!document.body.contains(body)) { if (pollTimer) clearInterval(pollTimer); return; }
      if (editingId) return;
      try {
        const md = await Api.get('/parent/messages/' + convo.id);
        const msgs = md.messages || [];
        const s = sigOf(msgs);
        if (s !== lastSig) {
          // A genuinely new message from the other side → play the loud chime.
          const grew = msgs.length > lastLen;
          const last = msgs[msgs.length - 1];
          if (grew && last && !last.is_mine) {
            try { if (window.KT && KT.playPing) KT.playPing(); } catch (e) {}
            try { if (KT.ChatDock) KT.ChatDock.flashIncoming(); } catch (e) {}   // flash the dock if minimised
          }
          lastLen = msgs.length;
          const atBottom = body.scrollTop + body.clientHeight >= body.scrollHeight - 60;
          const prevTop = body.scrollTop;
          lastSig = s;
          renderMsgs(msgs);
          body.scrollTop = atBottom ? body.scrollHeight : prevTop;
        }
        // Always refresh the typing indicator (independent of message changes).
        setTyping(md.typing_users || []);
      } catch (e) {}
    };
    if (!childId) { input.disabled = true; input.placeholder = 'Read-only conversation'; action.disabled = true; attach.disabled = true; emojiBtn.disabled = true; }
    let mobChain = Promise.resolve();
    const doSend = async (file) => {
      // Editing an existing message (text only) → PATCH.
      if (editingId && !file) {
        if (!input.value.trim()) return;
        action.disabled = true;
        try { await Api.patch('/parent/messages/' + editingId, { body: input.value.trim() }); bust('/parent/messages'); cancelEdit(); await load(); }
        catch (e) { ktToast('⚠️', (e && e.message) || 'Could not save the edit'); }
        finally { action.disabled = false; }
        return;
      }
      if ((!input.value.trim() && !file) || !childId) return;
      /* SEND AS FAST AS YOU TYPE (2026-11-11, Anthony: "make this real time to send multiple messages quickly").
         The box used to lock until the server answered and then the whole list reloaded. Now it clears on tap,
         a faded bubble goes in at once and the keyboard stays up; sends queue (mobChain) in the order tapped.
         A failed one takes its bubble away and puts its words back. One reload when the last one is in. */
      const text = input.value.trim();
      const em = !!(mobEm && KT.emergency.isOn(mobEm));
      if (mobEm) KT.emergency.reset(mobEm);
      input.value = ''; input.placeholder = 'Message…'; updateAction();
      emojiPanel.style.display = 'none';
      if (!file) { try { input.focus(); } catch (e0) {} }
      const isVoice = file && (file.type || '').indexOf('audio') === 0;
      const ghost = Dom.el('div', { 'data-kt-pending': '1', style: 'align-self:flex-end;margin-left:auto;max-width:78%;background:#DCF1F4;color:#0D1B2A;padding:9px 13px;border-radius:16px;border-bottom-right-radius:4px;font-size:15px;line-height:1.35;white-space:pre-wrap;word-break:break-word;opacity:.6;' },
        text || (isVoice ? '🎤 Voice note · sending…' : '📷 Photo · sending…'));
      mobPending.push(ghost);
      body.appendChild(ghost);
      body.scrollTop = body.scrollHeight;
      action.setAttribute('aria-busy', 'true');
      mobChain = mobChain.then(async () => {
        try {
          await sendParentMessage(childId, text, file, em, convo.channel === 'office' ? 'office' : null);
          ghost.style.opacity = '';
          mobPending.splice(mobPending.indexOf(ghost), 1);
        } catch (e) {
          mobPending.splice(mobPending.indexOf(ghost), 1);
          if (ghost.parentNode) ghost.parentNode.removeChild(ghost);
          if (text) input.value = text + (input.value ? ' ' + input.value : '');
          input.placeholder = 'Could not send — try again';
          updateAction();
          ktToast('⚠️', (e && e.message) || 'Not sent — try again');
        }
        if (mobPending.length) return;
        action.removeAttribute('aria-busy');
        bust('/parent/messages');
        await load();
      });
    };
    // Several photos picked at once: one message each, queued in order (mobChain); the words go with the first.
    fileInput.addEventListener('change', () => {
      const all = Array.prototype.slice.call(fileInput.files || [], 0, 10);
      fileInput.value = '';
      all.forEach((f) => { doSend(f); });
    });
    // Paste a screenshot / image straight into the chat (Ctrl/⌘-V) → sends it.
    input.addEventListener('paste', (ev) => {
      const items = (ev.clipboardData && ev.clipboardData.items) || [];
      for (let i = 0; i < items.length; i++) {
        if (items[i].kind === 'file' && /^image\//.test(items[i].type)) {
          const blob = items[i].getAsFile();
          if (!blob) continue;
          ev.preventDefault();
          const ext = (blob.type.split('/')[1] || 'png').replace('jpeg', 'jpg');
          doSend(new File([blob], 'pasted-' + Date.now() + '.' + ext, { type: blob.type }));
          return;
        }
      }
    });
    compose.addEventListener('submit', (ev) => { ev.preventDefault(); if (input.value.trim()) doSend(null); });
    load().then(() => { pollTimer = setInterval(poll, 10000); });
  }

  // Full-screen "compose a new message" — pick a child, write, optionally attach a photo.
  function openComposeNew() {
    const appMain = document.getElementById('appMain');
    if (!appMain || !state.children.length) return;
    const chipStyle = (active) => `display:inline-flex;align-items:center;padding:7px 14px;border-radius:20px;font-size:13px;font-weight:700;cursor:pointer;border:1.5px solid ${active ? 'var(--kt-brand-accent,#159FB4)' : 'var(--ink-200)'};background:${active ? 'var(--kt-brand-accent,#159FB4)' : '#fff'};color:${active ? '#fff' : 'var(--ink-700)'};`;

    /* A POPUP ON EVERY SCREEN (2026-11-06, Anthony: "look at message centre under parent view and make this look
       better as a popup instead ... when created a new message"). On a phone this was a white full-screen takeover
       with a bare ‹ back, and the popup guard wrapped "About which child?" into a squashed header band. Now phone and
       desktop get the same centred card over a dimmed screen: the standard navy-to-teal header with ✕ inside it,
       one scroll region, and Emergency + Send pinned at the foot. (The desktop dialog dates from 2026-09-08.) */
    const close = () => { if (window.KT && KT.popOverlay) KT.popOverlay(tw); else tw.remove(); };
    const tw = Dom.el('div', { 'data-kt-scroll': '1', style: 'position:fixed;inset:0;z-index:9600;background:rgba(15,23,42,.5);display:flex;align-items:center;'
      + 'justify-content:center;padding:calc(var(--kt-safe-top, env(safe-area-inset-top,0px)) + 16px) 12px calc(var(--kt-safe-bottom, env(safe-area-inset-bottom,0px)) + 16px);'
      + 'box-sizing:border-box;animation:kt-screen-in .22s cubic-bezier(.22,.61,.36,1);' });
    const panel = Dom.el('div', { role: 'dialog', 'aria-modal': 'true', 'aria-label': 'New message', 'data-kt-scroll': '1',
      style: 'width:min(560px,100%);max-height:100%;background:#fff;border-radius:16px;'
        + 'box-shadow:0 24px 60px -20px rgba(15,23,42,.5);display:flex;flex-direction:column;overflow:hidden;' });
    tw.appendChild(panel);
    // Clicking the dimmed area closes, the way every other dialog here behaves.
    tw.addEventListener('click', (e) => { if (e.target === tw) close(); });
    const header = Dom.el('div', { class: 'kt-dlg-head', style: 'display:flex;align-items:center;gap:10px;padding:13px 14px 15px 16px;flex-shrink:0;'
      + 'background:linear-gradient(135deg,#1B3A63 0%,#265B7A 50%,#2F8C86 100%);box-shadow:inset 0 -3px 0 #7FD3CC;color:#fff;' });
    header.appendChild(Dom.el('div', { style: 'flex:1;min-width:0;font-weight:800;font-size:16px;color:#fff;' }, '✉️ New message'));
    const back = Dom.el('button', { type: 'button', 'aria-label': 'Close', 'data-kt-iconized': '1',
      style: 'background:rgba(255,255,255,.14);border:none;width:30px;height:30px;border-radius:8px;font-size:16px;color:#fff;cursor:pointer;line-height:1;flex-shrink:0;' }, '✕');
    back.addEventListener('click', close);
    header.appendChild(back);
    panel.appendChild(header);

    const bodyWrap = Dom.el('div', { 'data-kt-scroll': '1', style: 'flex:1;min-height:0;overflow-y:auto;padding:16px;' });
    panel.appendChild(bodyWrap);

    let chosenChild = state.children.find(c => c.id === state.selectedChildId) || state.children[0];
    /* WHO IT GOES TO (2026-11-06, Anthony: "allow parent to message its educator(s) or agency (everyone at agency
       admin/directors - would get the messages)"). Educators = the thread about the chosen child, as before. Centre
       office = one family thread that only the centre's directors and the agency's admins see (to='office',
       App\Support\MessagingRules::OFFICE on the server). */
    let to = 'room';
    bodyWrap.appendChild(Dom.el('label', { style: 'display:block;font-size:12.5px;font-weight:700;color:var(--ink-700);margin-bottom:7px;' }, 'Send to'));
    const toRow = Dom.el('div', { role: 'radiogroup', 'aria-label': 'Send to', 'data-kt-noband': '1', style: 'display:flex;gap:8px;margin-bottom:6px;' });
    const toHint = Dom.el('div', { style: 'font-size:12.5px;color:var(--ink-500);margin-bottom:16px;line-height:1.4;' });
    const TO_OPTS = [
      { k: 'room', ico: '👩‍🏫', lbl: 'Educators' },
      { k: 'office', ico: '🏢', lbl: 'Centre office' },
    ];
    let childBlock = null;
    const paintTo = () => {
      [].forEach.call(toRow.children, (b) => {
        const on = b.dataset.to === to;
        b.setAttribute('aria-checked', on ? 'true' : 'false');
        b.setAttribute('style', 'flex:1;display:inline-flex;align-items:center;justify-content:center;gap:6px;height:36px;border-radius:10px;'
          + 'font:inherit;font-size:13.5px;font-weight:700;cursor:pointer;'
          + (on ? 'border:1.5px solid var(--kt-brand-accent,#159FB4);background:var(--kt-brand-accent,#159FB4);color:#fff;' : 'border:1.5px solid var(--ink-200);background:#fff;color:var(--ink-700);'));
      });
      toHint.textContent = to === 'office'
        ? "Goes to the centre's director and office team. Your child's educators won't see it."
        : "Goes to your child's room team at the centre.";
      if (childBlock) childBlock.style.display = to === 'office' ? 'none' : '';
    };
    TO_OPTS.forEach((o) => {
      const b = Dom.el('button', { type: 'button', role: 'radio', 'data-to': o.k, 'data-kt-iconized': '1' }, [Dom.el('span', { 'aria-hidden': 'true' }, o.ico), Dom.el('span', {}, o.lbl)]);
      b.addEventListener('click', () => { to = o.k; paintTo(); });
      toRow.appendChild(b);
    });
    bodyWrap.appendChild(toRow);
    bodyWrap.appendChild(toHint);
    childBlock = Dom.el('div');
    bodyWrap.appendChild(childBlock);
    paintTo();
    if (state.children.length > 1) {
      childBlock.appendChild(Dom.el('label', { style: 'display:block;font-size:12.5px;font-weight:700;color:var(--ink-700);margin-bottom:7px;' }, 'About which child?'));
      const chips = Dom.el('div', { 'data-kt-noband': '1', style: 'display:flex;flex-wrap:wrap;gap:8px;margin-bottom:18px;' });
      const paint = () => { [].forEach.call(chips.children, (b) => b.setAttribute('style', chipStyle(Number(b.dataset.id) === chosenChild.id))); };
      state.children.forEach(c => {
        const chip = Dom.el('button', { type: 'button', 'data-kt-iconized': '1', 'data-id': String(c.id), style: chipStyle(c.id === chosenChild.id) }, (c.first_name || c.display_name));
        chip.addEventListener('click', () => { chosenChild = c; paint(); });
        chips.appendChild(chip);
      });
      childBlock.appendChild(chips);
    }

    // display:block — a textarea is inline-block by default, which is why the
    // "Add a photo" button sat alongside it instead of underneath.
    const ta = Dom.el('textarea', { placeholder: 'Write your message…', style: 'display:block;width:100%;box-sizing:border-box;min-height:130px;border:1.5px solid var(--ink-200);border-radius:14px;padding:13px 15px;font-size:16px;font-family:inherit;resize:vertical;' });
    /* The global "tight and clean" control rule caps input/select/textarea heights with
       !important, which squashed the message box to 43px. That height is right for a
       form field and wrong for the box somebody writes a paragraph in. An inline
       !important is the one thing that outranks a stylesheet !important. */
    ta.rows = 5;
    ta.style.setProperty('min-height', '140px', 'important');
    ta.style.setProperty('height', 'auto', 'important');
    bodyWrap.appendChild(ta);

    /* EMOJI + VOICE NOTE (2026-11-06, Anthony: "add emoji picker, voice note to the guardian chat - new message").
       The same emoji grid and the same recorder (KT.recordVoiceNote) as an open conversation. One attachment per
       message, as before: a photo or a voice note, and picking one replaces the other. */
    let pickedFile = null;
    const preview = Dom.el('div', { style: 'margin-top:10px;' });
    const fileInput = Dom.el('input', { type: 'file', accept: 'image/*', multiple: 'multiple', style: 'display:none;' });
    let pickedMore = [];   // 2nd+ photos picked together; each follows as its own message (2026-11-13)
    const TOOL = 'display:inline-flex;align-items:center;gap:6px;background:var(--ink-50);border:1px solid var(--ink-200);border-radius:9px;'
      + 'height:32px;padding:0 11px;font-size:13px;font-weight:700;color:var(--ink-700);cursor:pointer;font-family:inherit;';
    const tools = Dom.el('div', { style: 'display:flex;flex-wrap:wrap;gap:8px;margin-top:10px;' });
    const emojiBtn = Dom.el('button', { type: 'button', 'data-kt-iconized': '1', 'aria-expanded': 'false', style: TOOL }, [Dom.el('span', {}, '😊'), Dom.el('span', {}, 'Emoji')]);
    const attachRow = Dom.el('button', { type: 'button', 'data-kt-iconized': '1', style: TOOL }, [Dom.el('span', {}, '📷'), Dom.el('span', {}, 'Photo')]);
    const voiceBtn = Dom.el('button', { type: 'button', 'data-kt-iconized': '1', style: TOOL }, [Dom.el('span', {}, '🎤'), Dom.el('span', {}, 'Voice note')]);
    tools.appendChild(emojiBtn); tools.appendChild(attachRow); tools.appendChild(voiceBtn);
    // .kt-emoji-panel.kt-emoji-bar: the one emoji grid (kt-chat-composer.css), as in an open conversation.
    const emojiPanel = Dom.el('div', { class: 'kt-emoji-panel kt-emoji-bar', style: 'display:none;flex-wrap:wrap;gap:2px;margin-top:8px;padding:6px;'
      + 'background:#fff;border:1px solid var(--ink-200);border-radius:10px;max-height:150px;overflow-y:auto;' });
    KT_EMOJIS.forEach(em => {
      const eb = Dom.el('button', { type: 'button', 'data-kt-iconized': '1', 'aria-label': em, style: 'background:none;border:none;font-size:22px;line-height:1;padding:6px;cursor:pointer;border-radius:8px;' }, em);
      eb.addEventListener('click', () => {
        const a = ta.selectionStart == null ? ta.value.length : ta.selectionStart;
        const z = ta.selectionEnd == null ? a : ta.selectionEnd;
        ta.value = ta.value.slice(0, a) + em + ta.value.slice(z);
        ta.selectionStart = ta.selectionEnd = a + em.length;
        ta.focus();
      });
      emojiPanel.appendChild(eb);
    });
    const showEmoji = (on) => { emojiPanel.style.display = on ? 'flex' : 'none'; emojiBtn.setAttribute('aria-expanded', on ? 'true' : 'false'); };
    emojiBtn.addEventListener('click', () => showEmoji(emojiPanel.style.display === 'none'));
    closeOnOutsideTap([emojiPanel, emojiBtn], () => showEmoji(false), () => emojiPanel.style.display !== 'none');

    const resetTools = () => { attachRow.lastChild.textContent = 'Photo'; voiceBtn.lastChild.textContent = 'Voice note'; };
    const showPicked = () => {
      Dom.clear(preview);
      resetTools();
      if (!pickedFile) return;
      const isAudio = (pickedFile.type || '').indexOf('audio') === 0;
      const row = Dom.el('div', { style: 'display:flex;align-items:center;gap:8px;' });
      if (isAudio) {
        row.appendChild(Dom.el('audio', { controls: '', preload: 'metadata', src: URL.createObjectURL(pickedFile), style: 'width:230px;max-width:100%;height:40px;' }));
        voiceBtn.lastChild.textContent = 'Record again';
      } else {
        const img = Dom.el('img', { style: 'max-width:170px;border-radius:10px;display:block;' });
        img.src = URL.createObjectURL(pickedFile);
        row.appendChild(img);
        pickedMore.forEach((mf) => {
          const mi = Dom.el('img', { style: 'max-width:80px;max-height:80px;object-fit:cover;border-radius:10px;display:block;' });
          mi.src = URL.createObjectURL(mf);
          row.appendChild(mi);
        });
        row.style.flexWrap = 'wrap';
        attachRow.lastChild.textContent = pickedMore.length ? 'Change photos' : 'Change photo';
      }
      const rm = Dom.el('button', { type: 'button', 'data-kt-iconized': '1', 'aria-label': isAudio ? 'Remove voice note' : 'Remove photo', 'data-kttip': 'Remove',
        style: 'flex-shrink:0;width:30px;height:30px;border-radius:8px;border:1px solid var(--ink-200);background:#fff;color:var(--ink-500);cursor:pointer;font-size:14px;' }, '\u2715');
      rm.addEventListener('click', () => { pickedFile = null; pickedMore = []; showPicked(); });
      row.appendChild(rm);
      preview.appendChild(row);
    };
    attachRow.addEventListener('click', () => fileInput.click());
    fileInput.addEventListener('change', () => {
      const all = Array.prototype.slice.call(fileInput.files || [], 0, 10);
      if (all.length) { pickedFile = all[0]; pickedMore = all.slice(1); fileInput.value = ''; showPicked(); }
    });
    voiceBtn.addEventListener('click', async () => {
      if (!(window.KT && KT.recordVoiceNote)) { ktToast('⚠️', 'Voice messages aren’t supported on this device'); return; }
      showEmoji(false);
      voiceBtn.disabled = true;
      try {
        // The recorder stands in for the tools row while it is up, then gives it back.
        const file = await KT.recordVoiceNote({ anchor: tools, acceptLabel: 'Use' });
        if (file) { pickedFile = file; pickedMore = []; showPicked(); }
      } finally { voiceBtn.disabled = false; }
    });
    bodyWrap.appendChild(tools);
    bodyWrap.appendChild(emojiPanel);
    bodyWrap.appendChild(fileInput);
    bodyWrap.appendChild(preview);

    // The foot: what went wrong, the Emergency switch and Send, always in view under the scroll region.
    const foot = Dom.el('div', { style: 'flex-shrink:0;border-top:1px solid var(--ink-100);padding:10px 16px 14px;display:flex;flex-direction:column;gap:8px;background:#fff;' });
    const status = Dom.el('div', { style: 'font-size:13px;color:#B45309;min-height:0;' });
    foot.appendChild(status);
    const send = Dom.el('button', { type: 'button', 'data-kt-iconized': '1', style: 'border:0;cursor:pointer;height:40px;border-radius:10px;font-size:15px;font-weight:800;color:#fff;background:#159FB4;' }, 'Send message');
    send.addEventListener('click', async () => {
      if (!ta.value.trim() && !pickedFile) { status.textContent = 'Write a message, or add a photo or voice note first.'; return; }
      send.disabled = true; send.textContent = 'Sending…'; status.textContent = '';
      try {
        await sendParentMessage(chosenChild.id, ta.value.trim(), pickedFile, !!(newEm && KT.emergency.isOn(newEm)), to);
        // The other photos picked with it, one message each. The first message is in, so a failure here only says so.
        let lostMore = 0;
        for (const mf of pickedMore) {
          try { await sendParentMessage(chosenChild.id, '', mf, false, to); } catch (e2) { lostMore++; }
        }
        if (lostMore) ktToast('⚠️', 'Message sent, but ' + lostMore + ' photo' + (lostMore === 1 ? '' : 's') + ' did not go. Open the conversation to try again.');
        bust('/parent/messages');
        close();
        if (location.hash === '#messages') Shell.renderScreen(); else location.hash = '#messages';
      } catch (e) {
        send.disabled = false; send.textContent = 'Send message';
        status.textContent = 'Could not send: ' + (e.message || 'please try again.');
      }
    });
    const newEm = (window.KT && KT.emergency) ? KT.emergency.button({ wide: true }) : null;
    const row = Dom.el('div', { style: 'display:flex;align-items:center;gap:10px;' });
    if (newEm) { newEm.style.margin = '0'; newEm.style.flexShrink = '0'; row.appendChild(newEm); }
    send.style.flex = '1';
    row.appendChild(send);
    foot.appendChild(row);
    panel.appendChild(foot);

    appMain.appendChild(tw);
    if (window.KT && KT.pushOverlay) KT.pushOverlay(tw);
    setTimeout(() => ta.focus(), 120);
  }

  // `ev` is optional: a photo/video is filed as a `note` event carrying
  // payload.kind === 'media', so it gets a camera/film icon instead of the
  // generic 📝 (the backend title deliberately carries no emoji of its own).
  function eventIcon(type, ev) {
    var pl = ev && ev.payload;
    if (type === 'note' && pl && pl.kind === 'media') {
      return pl.media_type === 'video' ? '🎬' : '📸';
    }
    return ({
      'meal': '🍽️', 'snack': '🍎', 'nap_start': '😴', 'nap_end': '🌅',
      'diaper': '👶', 'bathroom': '🚽', 'activity': '✨', 'mood': '😊', 'outdoor': '🌳',
      'note': '📝', 'milestone': '🌟', 'bottle': '🍼',
      'sunscreen': '☀️', 'medication': '💊', 'medicine': '💊', 'clothing': '👕',
      'check_in': '✅', 'check_out': '👋',
    })[type] || '•';
  }

  // The timeline endpoint returns sign-in / sign-out records SEPARATELY in
  // `checks` (the actual check-in & check-out times). Parents were only shown a
  // live "AT CENTRE / AT HOME" badge, never the times — so merge the checks into
  // the timeline as first-class entries, sorted chronologically with the rest.
  function mergeTimelineChecks(timeline) {
    var events = (timeline && timeline.events) || [];
    var checks = ((timeline && timeline.checks) || []).map(function (c) {
      return {
        type: c.type,
        display: { title: c.type === 'check_out' ? 'Signed out' : 'Signed in', detail: c.by || '' },
        time_display: c.time_display,
        occurred_at: c.occurred_at,
      };
    });
    return events.concat(checks).sort(function (a, b) {
      return String(a.occurred_at || '').localeCompare(String(b.occurred_at || ''));
    });
  }

  /* ─── RUNNING LATE (2026-11-06) ─────────────────────────────────────
     Anthony: "the late function should apply for parents dropping and picking up late to supress the safe arrival
     alerts and notify the educator about the situation". A parent says the child will be dropped off (arrival) or
     picked up (departure) by a time today. Until then Safe Arrival / the end-of-day check asks and alerts nobody, and
     the centre's staff are told (who: the agency's Safe Arrival settings). GET/POST/DELETE /parent/running-late. */
  const lateEsc = (v) => String(v == null ? '' : v).replace(/[&<>"']/g, (ch) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[ch]));
  const lateHm = (t) => { const h = +t.slice(0, 2); return ((h % 12) || 12) + ':' + t.slice(3) + (h < 12 ? ' AM' : ' PM'); };
  async function openRunningLate(childId, onDone) {
    let info = null;
    try { info = await Api.get('/parent/running-late'); } catch (e) { ktToast('⚠️', e.message || 'Could not load. Try again.'); return; }
    const c = (info.children || []).find(x => String(x.child_id) === String(childId));
    const first = c ? c.first_name : 'Your child';
    if (!c || (!c.can_arrival && !c.can_departure)) {
      const why = !c || c.status === 'none' ? ' is not expected at the centre today.'
        : c.status === 'absent' ? ' is reported absent today.' : ' has already gone home today.';
      ktToast('⏰', first + why);
      return;
    }
    let kind = c.can_arrival && !c.late.arrival ? 'arrival' : (c.can_departure ? 'departure' : 'arrival');
    if (c.status === 'in') kind = 'departure';
    const body = document.createElement('div');
    const BTN = 'flex:1;height:32px;border-radius:8px;font-weight:800;font-size:13px;cursor:pointer;font-family:inherit;';
    const LBL = 'display:block;font-size:12px;font-weight:700;color:#374151;margin:12px 0 4px;';
    const FLD = 'width:100%;box-sizing:border-box;height:32px;padding:0 9px;border:1px solid #D1D5DB;border-radius:8px;font-size:14px;background:#fff;';
    let h = null;
    const dflt = () => {
      const t = new Date(Date.now() + 30 * 60000); t.setMinutes(Math.ceil(t.getMinutes() / 5) * 5, 0, 0);
      return String(t.getHours()).padStart(2, '0') + ':' + String(t.getMinutes()).padStart(2, '0');
    };
    const paint = () => {
      const had = c.late[kind];
      const pickup = kind === 'departure';
      body.innerHTML = (c.can_arrival && c.can_departure
          ? '<div style="display:flex;gap:6px;margin-bottom:4px;" role="group" aria-label="Drop-off or pickup">'
            + ['arrival', 'departure'].map(k => '<button type="button" data-kind="' + k + '" data-kt-iconized="1" aria-pressed="' + (k === kind) + '" style="' + BTN
              + (k === kind ? 'border:0;background:#1F6080;color:#fff;' : 'border:1px solid #CBD5E1;background:#fff;color:#334155;') + '">'
              + (k === 'arrival' ? '🚗 Drop-off' : '🏠 Pickup') + '</button>').join('') + '</div>'
          : '')
        + '<div style="font-size:13.5px;color:#475569;line-height:1.55;margin-top:6px;">' + (pickup
          ? 'Tell the centre you\'ll pick ' + lateEsc(first) + ' up later today. Nobody is alerted before the time you give.'
          : 'Tell the centre ' + lateEsc(first) + ' will be in later today. You won\'t be asked where ' + lateEsc(first) + ' is before the time you give.')
        + ' ' + lateEsc(first) + '\'s educators are told.</div>'
        + (had ? '<div style="margin-top:10px;padding:9px 11px;border-radius:10px;background:#EEF2FF;color:#3730A3;font-size:13px;font-weight:700;">'
          + (pickup ? 'Pickup by ' : 'Arriving by ') + lateEsc(had.by) + (had.note ? ' — ' + lateEsc(had.note) : '') + '</div>' : '')
        + '<label style="' + LBL + '" for="pl-late-time">' + (pickup ? 'I\'ll pick up by' : 'We\'ll be there by') + '</label>'
        + '<input type="time" id="pl-late-time" step="300" value="' + lateEsc(had ? had.by_hm : dflt()) + '" style="' + FLD + 'width:150px;">'
        + '<label style="' + LBL + '" for="pl-late-note">Note for the centre (optional)</label>'
        + '<input type="text" id="pl-late-note" maxlength="500" style="' + FLD + '" placeholder="' + (pickup ? 'e.g. Stuck in traffic' : 'e.g. Doctor\'s appointment') + '" value="' + lateEsc(had && had.note ? had.note : '') + '">'
        + '<div data-err role="alert" style="color:#B91C1C;font-size:13px;font-weight:700;min-height:18px;margin-top:8px;"></div>';
      body.querySelectorAll('[data-kind]').forEach(b => b.addEventListener('click', () => { kind = b.getAttribute('data-kind'); open(); }));
    };
    const err = () => body.querySelector('[data-err]');
    const finish = (r, msg) => {
      ktToast('⏰', msg);
      if (onDone) { try { onDone(r); } catch (e) {} }
    };
    const open = () => {
      paint();
      const had = c.late[kind];
      const actions = [{ label: 'Cancel' }];
      if (had) {
        actions.push({ label: 'We\'re on time', busyLabel: 'Saving…', handler: async () => {
          let r;
          try { r = await Api.delete('/parent/running-late/' + c.child_id + '/' + kind); } catch (e) { err().textContent = e.message; return false; }
          finish(r, 'Thanks. The centre has been told ' + first + ' is on time.');
        } });
      }
      actions.push({ label: had ? 'Save new time' : 'Tell the centre', primary: true, busyLabel: 'Sending…', handler: async () => {
        const t = body.querySelector('#pl-late-time').value;
        if (!/^\d{2}:\d{2}$/.test(t)) { err().textContent = 'Enter a time.'; return false; }
        const note = body.querySelector('#pl-late-note').value.trim();
        let r;
        try { r = await Api.post('/parent/running-late', { child_id: c.child_id, kind: kind, time: t, note: note || null }); }
        catch (e) { err().textContent = e.message; return false; }
        finish(r, 'The centre knows ' + (kind === 'departure' ? 'you\'ll pick ' + first + ' up by ' : first + ' will be there by ') + lateHm(t) + '.');
      } });
      h = Shell.Modal.open({ title: '⏰ Running late: ' + first, body: body, actions: actions });
      // The suggested time is ready to send (kt-dirty-save would hold a prefilled form as "no changes").
      const save = h && h.backdrop && h.backdrop.querySelector('.modal-footer .btn-primary');
      if (save) save.setAttribute('data-kt-always-save', '1');
    };
    open();
  }
  window.KT = window.KT || {};
  window.KT.parentRunningLate = openRunningLate;

  /* guardian:running-late - each child, where they are, and a button (the home tile opens this). */
  async function renderRunningLate(main) {
    Dom.clear(main);
    const wrap = Dom.el('div', { style: 'padding:16px;' });
    main.appendChild(wrap);
    wrap.appendChild(Dom.el('h1', { style: 'margin:0 0 4px;font-size:22px;' }, '⏰ Running late'));
    wrap.appendChild(Dom.el('p', { style: 'margin:0 0 14px;color:var(--ink-600);font-size:13.5px;line-height:1.5;' },
      'Running late for drop-off or pickup today? Tell the centre here. Nobody chases you before the time you give, and your child\'s educators know.'));
    const list = Dom.el('div', { style: 'display:grid;grid-template-columns:repeat(auto-fill,minmax(280px,1fr));gap:12px;' });
    wrap.appendChild(list);
    const paint = (info) => {
      Dom.clear(list);
      const kids = (info && info.children) || [];
      if (!kids.length) { list.appendChild(Dom.el('div', { style: 'color:var(--ink-500);font-size:13.5px;' }, 'No children on your account.')); return; }
      kids.forEach((c) => {
        const where = c.status === 'in' ? 'At the centre' : c.status === 'expected' ? 'Expected today' + (c.expected_in ? ' · usually in by ' + c.expected_in : '')
          : c.status === 'absent' ? 'Reported absent today' : c.status === 'out' ? 'Gone home today' : 'Not expected today';
        const row = Dom.el('div', { style: card('padding:14px;') });
        row.appendChild(Dom.el('div', { style: 'font-weight:800;font-size:15px;color:var(--ink-900);' }, c.name));
        row.appendChild(Dom.el('div', { style: 'font-size:12.5px;color:var(--ink-500);margin-top:2px;' }, where + (c.centre_name ? ' · ' + c.centre_name : '')));
        ['arrival', 'departure'].forEach((k) => {
          const l = c.late[k];
          if (l) row.appendChild(Dom.el('div', { style: 'margin-top:8px;padding:7px 10px;border-radius:9px;background:#EEF2FF;color:#3730A3;font-size:12.5px;font-weight:700;' },
            (k === 'departure' ? 'Pickup by ' : 'Arriving by ') + l.by + (l.note ? ' — ' + l.note : '')));
        });
        const can = c.can_arrival || c.can_departure;
        const b = Dom.el('button', {
          type: 'button', 'data-kt-iconized': '1',
          style: 'margin-top:10px;width:100%;height:32px;border-radius:8px;font-weight:800;font-size:13px;cursor:pointer;font-family:inherit;'
            + (can ? 'border:0;background:#1F6080;color:#fff;' : 'border:1px solid #E2E8F0;background:#F8FAFC;color:#94A3B8;'),
        }, c.late.arrival || c.late.departure ? 'Change or take back' : (c.status === 'in' ? 'Late for pickup' : 'Running late'));
        b.addEventListener('click', () => openRunningLate(c.child_id, (r) => paint(r)));
        row.appendChild(b);
        list.appendChild(row);
      });
    };
    list.appendChild(Dom.el('div', { style: 'color:var(--ink-500);font-size:13px;' }, 'Loading…'));
    try { paint(await Api.get('/parent/running-late')); }
    catch (e) { Dom.clear(list); list.appendChild(Dom.el('div', { style: 'color:#B91C1C;font-size:13.5px;' }, e.message || 'Could not load. Try again.')); }
    /* CALL IN INSTEAD (2026-11-24, App\Support\ParentLine): the KiddieTrac number, which phones it knows, and a phone PIN
       for calling from a landline or a phone that can't get texts. */
    const callBox = Dom.el('div', { style: card('padding:14px;margin-top:14px;') });
    wrap.appendChild(callBox);
    const paintCall = (p) => {
      if (!p || !p.enabled) { callBox.remove(); return; }
      const B = 'height:32px;padding:0 12px;border-radius:8px;font-weight:800;font-size:13px;cursor:pointer;font-family:inherit;';
      callBox.innerHTML = '<div style="font-weight:800;font-size:15px;color:var(--ink-900);">📞 Call in instead</div>'
        + '<div style="font-size:13px;color:var(--ink-600);line-height:1.55;margin-top:4px;">Call <a href="tel:' + lateEsc(p.number.replace(/-/g, '')) + '" style="color:#1F6080;font-weight:800;">' + lateEsc(p.number) + '</a>'
        + (p.phones.length ? ' from ' + p.phones.map(lateEsc).join(' or ') : '') + ' to report an absence, or a late drop-off or pickup. To make sure it\'s really you, we text a code to your phone during the call.</div>'
        + (p.phones.length ? '' : '<div style="margin-top:8px;padding:8px 10px;border-radius:9px;background:#FEF3C7;color:#92400E;font-size:12.5px;font-weight:700;">Add your phone number to your profile first. The line knows you by the number you call from.</div>')
        + '<div style="margin-top:10px;font-size:13px;color:var(--ink-700);">' + (p.has_pin
          ? '🔒 Phone PIN set' + (p.pin_set ? ' ' + lateEsc(p.pin_set) : '') + '. You can enter it instead of the texted code.'
          : 'Calling from a landline, or a phone that can\'t get texts? Set a 4-digit phone PIN to use instead of the code.') + '</div>'
        + '<div style="display:flex;gap:8px;flex-wrap:wrap;margin-top:8px;">'
        + '<button type="button" data-pin-set data-kt-iconized="1" style="' + B + 'border:0;background:#1F6080;color:#fff;">' + (p.has_pin ? 'Change phone PIN' : 'Set a phone PIN') + '</button>'
        + (p.has_pin ? '<button type="button" data-pin-del data-kt-iconized="1" style="' + B + 'border:1px solid #CBD5E1;background:#fff;color:#334155;">Remove PIN</button>' : '')
        + '</div>';
      callBox.querySelector('[data-pin-set]').addEventListener('click', () => {
        const body = document.createElement('div');
        const F = 'width:140px;box-sizing:border-box;height:32px;padding:0 9px;border:1px solid #D1D5DB;border-radius:8px;font-size:16px;letter-spacing:4px;background:#fff;';
        body.innerHTML = '<div style="font-size:13.5px;color:#475569;line-height:1.55;">Pick 4 digits that only you know. Not a repeated digit, a run like 1234, a year, or the end of your phone number.</div>'
          + '<label style="display:block;font-size:12px;font-weight:700;color:#374151;margin:12px 0 4px;" for="pp-1">New PIN</label><input id="pp-1" type="password" inputmode="numeric" autocomplete="new-password" maxlength="4" style="' + F + '">'
          + '<label style="display:block;font-size:12px;font-weight:700;color:#374151;margin:12px 0 4px;" for="pp-2">Type it again</label><input id="pp-2" type="password" inputmode="numeric" autocomplete="new-password" maxlength="4" style="' + F + '">'
          + '<div data-err role="alert" style="color:#B91C1C;font-size:13px;font-weight:700;min-height:18px;margin-top:8px;"></div>';
        Shell.Modal.open({ title: '🔒 Phone PIN', body: body, actions: [{ label: 'Cancel' }, { label: 'Save PIN', primary: true, busyLabel: 'Saving…', handler: async () => {
          const a = body.querySelector('#pp-1').value.trim(), b = body.querySelector('#pp-2').value.trim();
          const e = body.querySelector('[data-err]');
          if (!/^\d{4}$/.test(a)) { e.textContent = 'Use exactly 4 digits.'; return false; }
          if (a !== b) { e.textContent = 'The two PINs don\'t match.'; return false; }
          let r; try { r = await Api.put('/parent/phone-line/pin', { pin: a }); } catch (x) { e.textContent = x.message; return false; }
          ktToast('🔒', 'Phone PIN saved.'); paintCall(r);
        } }] });
      });
      const del = callBox.querySelector('[data-pin-del]');
      if (del) del.addEventListener('click', async () => {
        try { paintCall(await Api.delete('/parent/phone-line/pin')); ktToast('🔒', 'Phone PIN removed. The texted code still works.'); } catch (x) { ktToast('⚠️', x.message); }
      });
    };
    try { paintCall(await Api.get('/parent/phone-line')); } catch (e) { callBox.remove(); }
  }

  window.KT = window.KT || {};
  window.KT.renderParent = renderParent;
  // The same sheet, for the Interac e-Transfer tab's "Preview what families see" (screen-payment-providers.js).
  window.KT.openInteracSheet = ktOpenInterac;
  Shell.registerScreen("guardian:dashboard", renderParent);
  Shell.registerScreen("guardian:today", renderParent);
  Shell.registerScreen("guardian:photos", renderParent);
  Shell.registerScreen("guardian:messages", renderParent);
  Shell.registerScreen("guardian:billing", renderParent);
  Shell.registerScreen("guardian:running-late", renderRunningLate);
})(window);
