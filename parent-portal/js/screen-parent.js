/* ═══════════════════════════════════════════════════════════════════
   KIDDIETRAC v4 — Parent Screen Module
   Real photos gallery · Messages thread · Billing widget · AI digest
   ═══════════════════════════════════════════════════════════════════ */

(function (window) {
  'use strict';

  const { Api, Fmt, Dom, Shell } = window.KT;
  const { emptyState } = Shell;

  let state = {
    children: [],
    selectedChildId: null,
    date: new Date().toISOString().split('T')[0],
  };

  const isMobile = () => window.matchMedia('(max-width: 600px)').matches;

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

  // Mark a notification category read so the bottom-nav badge clears (fire-and-forget).
  function markCategoryRead(category) {
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
  function sendParentMessage(childId, body, file) {
    var t; try { t = sessionStorage.getItem('kt_token') || localStorage.getItem('kt_token'); } catch (e) {}
    var base = (window.KT_CONFIG && window.KT_CONFIG.apiBase) || 'https://api.kiddietrac.com/api/v1';
    var fd = new FormData();
    fd.append('child_id', childId);
    if (body) fd.append('body', body);
    if (file) fd.append('attachment', file);
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
      // notes) — the mobile conversation list + thread, centred in a tidy column.
      wrap.appendChild(buildSubNav('messages'));
      const _mcol = Dom.el('div', { style: 'max-width: 760px; margin: 0 auto;' });
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
        Dom.el('span', { class: 'child-avatar', style: 'background: var(--brand-green); color: white; width: 28px; height: 28px; border-radius: 50%; display: inline-flex; align-items: center; justify-content: center; font-size: 12px; font-weight: 700; margin-right: 8px;' }, c.display_name?.[0]?.toUpperCase() || 'C'),
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

  function buildSubNav(active) {
    const nav = Dom.el('div', { style: 'display: flex; gap: 4px; margin-bottom: 24px; background: var(--ink-50); padding: 4px; border-radius: 12px; max-width: 480px;' });
    const tabs = [
      { id: 'today',    label: 'Today' },
      { id: 'photos',   label: 'Photos' },
      { id: 'messages', label: 'Messages' },
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
  function openPhotoLightbox(url, caption) {
    if (!url) return;
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
    dl.addEventListener('click', function () { downloadPhoto(url, caption); });
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

  // Save the image to the device. Fetch as a blob so the download attribute is honoured
  // even for cross-path URLs; fall back to opening the image in a new tab.
  function downloadPhoto(url, caption) {
    var name = ('kiddietrac-' + String(caption || 'photo').replace(/^\[Demo\] /, '').replace(/[^a-z0-9]+/gi, '-').slice(0, 40) || 'photo').replace(/-+$/, '') + '.jpg';
    fetch(url).then(function (r) { return r.blob(); }).then(function (b) {
      var u = URL.createObjectURL(b);
      var a = document.createElement('a');
      a.href = u; a.download = name;
      document.body.appendChild(a); a.click();
      setTimeout(function () { URL.revokeObjectURL(u); if (a.parentNode) a.parentNode.removeChild(a); }, 4000);
    }).catch(function () { window.open(url, '_blank', 'noopener'); });
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
    catch (e) { card.innerHTML = '<div style="padding:40px;text-align:center;color:#B91C1C;">Could not load the record.</div>'; return; }

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
      return '<div style="padding:16px 24px;"><div style="font-size:12px;font-weight:800;letter-spacing:.06em;text-transform:uppercase;color:#94A3B8;margin-bottom:6px;">' + esc(title) + '</div>' + inner + '</div>';
    }

    var guardians = (d.guardians || []).map(function (g) {
      return '<div style="padding:8px 0;border-bottom:1px solid #F1F5F9;">'
        + '<div style="font-weight:600;color:#0F172A;font-size:14px;">' + esc((g.first_name || '') + ' ' + (g.last_name || '')) + (g.is_primary ? ' <span style="color:#159FB4;font-weight:700;font-size:11px;">· PRIMARY</span>' : '') + '</div>'
        + '<div style="color:#64748B;font-size:12.5px;">' + esc(g.relationship || 'guardian') + (g.can_pickup ? ' · can pick up' : ' · not authorised for pickup') + '</div>'
        + (g.email ? '<div style="color:#94A3B8;font-size:12px;">' + esc(g.email) + '</div>' : '') + '</div>';
    }).join('');

    var health = [];
    if (d.medical_notes) health.push(row('Medical', d.medical_notes));
    if (d.dietary_notes) health.push(row('Dietary', d.dietary_notes));
    (d.health_flags || []).forEach(function (h) { health.push(row('Flag', h.label || h.name || h)); });
    var healthHtml = health.length ? health.join('') : '<div style="color:#94A3B8;font-size:13px;padding:6px 0;">None on file.</div>';

    var fam = d.family || {};
    var addr = [fam.address_line1, fam.address_line2, fam.city, fam.province, fam.postal_code].filter(Boolean).join(', ');

    card.innerHTML =
      '<div style="background:linear-gradient(135deg,#1F6080 0%,#2c7894 100%);color:#fff;padding:22px 24px;display:flex;align-items:center;gap:16px;position:relative;">'
      + (photo ? '<img src="' + esc(photo) + '" alt="" style="width:64px;height:64px;border-radius:50%;object-fit:cover;border:2px solid rgba(255,255,255,.5);">'
               : '<div style="width:64px;height:64px;border-radius:50%;background:rgba(255,255,255,.2);display:flex;align-items:center;justify-content:center;font-size:26px;font-weight:800;">' + esc((d.first_name || '?')[0]) + '</div>')
      + '<div><div style="font-size:22px;font-weight:800;">' + esc(d.full_name || d.display_name) + '</div>'
      + '<div style="opacity:.9;font-size:13px;">' + esc((d.age && d.age.human) || '') + (d.room && d.room.name ? ' · ' + esc(d.room.name) : '') + '</div></div>'
      + '<button type="button" id="kt-cr-close" aria-label="Close" style="position:absolute;top:14px;right:16px;background:rgba(255,255,255,.18);color:#fff;border:0;width:32px;height:32px;border-radius:50%;font-size:18px;cursor:pointer;line-height:1;">&times;</button>'
      + '<span style="position:absolute;bottom:12px;right:18px;background:rgba(255,255,255,.16);color:#fff;font-size:10px;font-weight:800;letter-spacing:.08em;padding:3px 9px;border-radius:20px;">VIEW ONLY</span></div>'
      + section('Child', row('Full name', d.full_name) + row('Date of birth', d.date_of_birth) + row('Age', d.age && d.age.human) + row('Gender', gender) + row('Status', d.is_at_centre ? 'At the centre' : 'At home'))
      + section('Enrolment', row('Room', d.room && d.room.name) + row('Centre', d.centre && d.centre.name) + row('Schedule', sched) + row('Start date', d.enrollment && d.enrollment.start_date) + row('CWELCC', d.enrollment && d.enrollment.cwelcc_eligible ? 'Eligible' : ''))
      + section('Guardians', guardians)
      + section('Health & dietary', healthHtml)
      + section('Family', row('Household', fam.family_name) + row('Address', addr) + row('Phone', fam.primary_phone) + row('Email', fam.primary_email))
      + '<div style="padding:14px 24px 22px;color:#94A3B8;font-size:12px;">Need a change? Contact your childcare centre — these records are maintained by the agency.</div>';

    var cl = card.querySelector('#kt-cr-close');
    if (cl) cl.addEventListener('click', function () { if (window.KT && KT.popOverlay) KT.popOverlay(ov); else dismiss(); });
  }

  async function renderTodayTab(wrap) {

    const child = state.children.find(c => c.id === state.selectedChildId);
    if (!child) return;

    // Header
    wrap.appendChild(Dom.el('div', { style: 'margin-bottom: 24px;' }, [
      Dom.el('div', { style: 'display: flex; align-items: center; gap: 12px; margin-bottom: 8px;' }, [
        Dom.el('h1', { style: 'margin: 0; font-size: 32px;' }, `Today with ${child.display_name}`),
        child.is_at_centre
          ? Dom.el('span', { style: 'background: var(--brand-green); color: white; padding: 4px 12px; border-radius: 20px; font-size: 12px; font-weight: 700; letter-spacing: 0.5px;' }, 'AT CENTRE')
          : Dom.el('span', { style: 'background: var(--ink-200); color: var(--ink-700); padding: 4px 12px; border-radius: 20px; font-size: 12px; font-weight: 700; letter-spacing: 0.5px;' }, 'AT HOME'),
      ]),
      Dom.el('p', { style: 'color: var(--ink-600); margin: 0;' },
        `${child.display_name} · ${child.age?.human || '—'} · ${child.room_name || 'No room assigned'}`
      ),
      Dom.el('button', {
        type: 'button',
        style: 'margin-top: 10px; background: #fff; color: #1F6080; border: 1px solid #cbd5e1; border-radius: 999px; padding: 7px 16px; font-size: 13px; font-weight: 700; cursor: pointer; display: inline-flex; align-items: center; gap: 6px;',
        onclick: () => openChildRecord(child.id),
      }, `📋 View ${child.display_name}'s record`),
    ]));

    const grid = Dom.el('div', { style: 'display: grid; grid-template-columns: 2fr 1fr; gap: 24px;' });
    wrap.appendChild(grid);
    const main = Dom.el('div'); grid.appendChild(main);
    const aside = Dom.el('div', { style: 'display: flex; flex-direction: column; gap: 16px;' }); grid.appendChild(aside);

    // AI digest card (top of main column)
    const digestCard = Dom.el('div', {
      style: 'background: linear-gradient(135deg, #1F6080 0%, #2c7894 100%); color: white; padding: 24px; border-radius: 16px; margin-bottom: 24px;',
    });
    main.appendChild(digestCard);
    digestCard.appendChild(Dom.el('div', { style: 'font-size: 12px; font-weight: 700; letter-spacing: 1px; color: #8EC73C; margin-bottom: 8px;' }, '✨ YOUR DAILY DIGEST'));
    const digestBody = Dom.el('div', { style: 'font-size: 15px; line-height: 1.6;' }, 'Loading...');
    digestCard.appendChild(digestBody);

    // Stats strip + timeline (load in parallel)
    const statsRow = Dom.el('div', { style: 'display: grid; grid-template-columns: repeat(4, 1fr); gap: 12px; margin-bottom: 24px;' });
    main.appendChild(statsRow);

    const timelineCard = Dom.el('div', { class: 'card' });
    main.appendChild(timelineCard);
    timelineCard.appendChild(Dom.el('h3', { style: 'margin: 0 0 4px 0;' }, 'Timeline'));
    timelineCard.appendChild(Dom.el('p', { style: 'color: var(--ink-600); margin: 0 0 16px 0; font-size: 13px;' }, 'Every moment logged by the educators'));
    const timelineBody = Dom.el('div'); timelineCard.appendChild(timelineBody);

    // Aside: this month's billing + recent observations
    const billingCard = Dom.el('div', { class: 'card' });
    aside.appendChild(billingCard);
    billingCard.appendChild(Dom.el('h3', { style: 'margin: 0 0 12px 0;' }, "This month's billing"));
    const billingBody = Dom.el('div'); billingCard.appendChild(billingBody);
    billingBody.appendChild(Dom.el('p', { style: 'color: var(--ink-600);' }, 'Loading...'));

    const obsCard = Dom.el('div', { class: 'card' });
    aside.appendChild(obsCard);
    obsCard.appendChild(Dom.el('h3', { style: 'margin: 0 0 12px 0;' }, 'Recent observations'));
    const obsBody = Dom.el('div'); obsCard.appendChild(obsBody);
    obsBody.appendChild(Dom.el('p', { style: 'color: var(--ink-600);' }, 'Loading...'));

    // Fire off all the parallel fetches
    Promise.all([
      Api.get(`/parent/children/${child.id}/timeline?date=${state.date}`),
      Api.get(`/parent/children/${child.id}/digest/${state.date}`),
      Api.get(`/parent/children/${child.id}/invoices?status=current`),
      Api.get(`/parent/children/${child.id}/observations`),
    ]).then(([timeline, digest, invoices, observations]) => {
      // Stats
      const meals = (timeline.events || []).filter(e => e.type === 'meal' || e.type === 'snack').length;
      const naps = (timeline.events || []).filter(e => e.type === 'nap_end').length;
      const diapers = (timeline.events || []).filter(e => e.type === 'diaper' || e.type === 'bathroom').length;
      const activities = (timeline.events || []).filter(e => e.type === 'activity').length;
      [['MEALS & SNACKS', meals, 'Logged today'], ['NAPS', naps, 'Completed'], ['DIAPER / BATHROOM', diapers, 'Total today'], ['ACTIVITIES', activities, 'Learning moments']].forEach(([label, n, sub]) => {
        statsRow.appendChild(Dom.el('div', { class: 'card stat-tile', style: 'padding: 16px;' }, [
          Dom.el('div', { style: 'font-size: 11px; font-weight: 700; color: var(--ink-500); letter-spacing: 1px; margin-bottom: 8px;' }, label),
          Dom.el('div', { style: 'font-size: 32px; font-weight: 800; color: var(--brand-blue); line-height: 1;' }, String(n)),
          Dom.el('div', { style: 'font-size: 12px; color: var(--ink-500); margin-top: 4px;' }, sub),
        ]));
      });

      // Timeline
      Dom.clear(timelineBody);
      if (!timeline.events || timeline.events.length === 0) {
        timelineBody.appendChild(Dom.el('p', { style: 'color: var(--ink-500); padding: 24px 0;' }, 'No events logged yet today. Check back soon!'));
      } else {
        timeline.events.forEach(ev => {
          const row = Dom.el('div', { style: 'display: flex; gap: 12px; padding: 12px 0; border-bottom: 1px solid var(--ink-100);' });
          row.appendChild(Dom.el('div', { style: 'background: var(--ink-50); border-radius: 50%; width: 40px; height: 40px; display: flex; align-items: center; justify-content: center; font-size: 18px; flex-shrink: 0;' }, eventIcon(ev.type)));
          const text = Dom.el('div', { style: 'flex: 1;' });
          text.appendChild(Dom.el('div', { style: 'font-weight: 600;' }, ev.display?.title || ev.type));
          if (ev.display?.detail) text.appendChild(Dom.el('div', { style: 'color: var(--ink-600); font-size: 13px;' }, ev.display.detail));
          row.appendChild(text);
          row.appendChild(Dom.el('div', { style: 'color: var(--ink-500); font-size: 13px; flex-shrink: 0;' }, ev.time_display));
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
      const inv = (invoices.invoices || [])[0];
      if (inv) {
        billingBody.appendChild(Dom.el('div', { style: 'font-size: 24px; font-weight: 800; color: var(--brand-blue);' }, '$' + (inv.balance_due ?? inv.total).toFixed(2)));
        billingBody.appendChild(Dom.el('div', { style: 'font-size: 13px; color: var(--ink-500); margin-bottom: 8px;' }, inv.is_estimate ? 'estimated for this month' : 'balance due'));
        if (!inv.is_estimate) {
          billingBody.appendChild(Dom.el('div', { style: 'font-size: 12px; color: var(--ink-600);' }, `Invoice ${inv.invoice_number} · due ${inv.due_date}`));
        }
        billingBody.appendChild(Dom.el('a', { href: '#billing', style: 'display: inline-block; margin-top: 12px; font-size: 13px; color: var(--brand-blue); font-weight: 600; text-decoration: none;' }, 'View billing →'));
      } else {
        billingBody.appendChild(Dom.el('p', { style: 'color: var(--ink-500); font-size: 13px;' }, 'No invoices yet.'));
      }

      // Observations
      Dom.clear(obsBody);
      const obs = observations.observations || [];
      if (obs.length === 0) {
        obsBody.appendChild(Dom.el('p', { style: 'color: var(--ink-500); font-size: 13px;' }, 'No observations recorded yet.'));
      } else {
        obs.slice(0, 3).forEach(o => {
          const item = Dom.el('div', { style: 'padding: 10px 0; border-bottom: 1px solid var(--ink-100);' });
          item.appendChild(Dom.el('div', { style: 'font-size: 11px; font-weight: 700; color: var(--brand-green); letter-spacing: 0.5px; margin-bottom: 4px;' }, o.domain_label));
          item.appendChild(Dom.el('div', { style: 'font-weight: 600; font-size: 14px; line-height: 1.3;' }, o.title.replace(/^\[Demo\] /, '')));
          item.appendChild(Dom.el('div', { style: 'color: var(--ink-500); font-size: 12px; margin-top: 4px;' }, o.date_display));
          obsBody.appendChild(item);
        });
      }
    }).catch(e => console.error('Today tab load failed:', e));
  }

  // ─── PHOTOS TAB ─────────────────────────────────────────────────

  async function renderPhotosTab(wrap) {
    wrap.appendChild(buildSubNav('photos'));
    const child = state.children.find(c => c.id === state.selectedChildId);
    if (!child) return;

    wrap.appendChild(Dom.el('h1', { style: 'margin: 0 0 24px;' }, `${child.display_name}'s Photos`));

    const gallery = Dom.el('div', { style: 'display: grid; grid-template-columns: repeat(auto-fill, minmax(220px, 1fr)); gap: 16px;' });
    wrap.appendChild(gallery);
    gallery.appendChild(Dom.el('p', { style: 'color: var(--ink-500); grid-column: 1 / -1;' }, 'Loading photos…'));

    try {
      const data = await Api.get(`/parent/children/${child.id}/photos`);
      Dom.clear(gallery);
      if (!data.photos || data.photos.length === 0) {
        gallery.appendChild(emptyState('📷', 'No photos yet', `When educators share photos of ${child.display_name}, they'll appear here.`));
        return;
      }
      data.photos.forEach(p => {
        const card = Dom.el('div', { class: 'card', style: 'padding: 0; overflow: hidden;' });
        const img = Dom.el('img', { src: p.url, alt: p.caption || 'Photo', style: 'width: 100%; aspect-ratio: 4/3; object-fit: cover; display: block; background: var(--ink-100); cursor: zoom-in;' });
        img.addEventListener('click', function () { openPhotoLightbox(p.url, p.caption); });
        card.appendChild(img);
        const body = Dom.el('div', { style: 'padding: 12px;' });
        if (p.caption) body.appendChild(Dom.el('div', { style: 'font-size: 14px; line-height: 1.4;' }, p.caption.replace(/^\[Demo\] /, '')));
        body.appendChild(Dom.el('div', { style: 'font-size: 12px; color: var(--ink-500); margin-top: 4px;' }, p.date_display));
        card.appendChild(body);
        gallery.appendChild(card);
      });
    } catch (e) {
      Dom.clear(gallery);
      gallery.appendChild(emptyState('⚠️', 'Could not load photos', e.message));
    }
  }

  // ─── MESSAGES TAB ───────────────────────────────────────────────

  async function renderMessagesTab(wrap) {
    wrap.appendChild(buildSubNav('messages'));
    wrap.appendChild(Dom.el('h1', { style: 'margin: 0 0 24px;' }, 'Messages'));

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
        thread.appendChild(emptyState('💬', 'No conversations', 'Messages with your educators will appear here.'));
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

          (msgData.messages || []).forEach(m => {
            const mine = m.is_mine;
            const bubble = Dom.el('div', {
              style: `max-width: 70%; padding: 10px 14px; border-radius: 16px; ${mine ? 'align-self: flex-end; background: #E1F3F6; color: #0D1B2A; border-bottom-right-radius: 4px;' : 'align-self: flex-start; background: var(--ink-100); color: var(--ink-900); border-bottom-left-radius: 4px;'}`,
            });
            if (!mine) {
              bubble.appendChild(Dom.el('div', { style: 'font-size: 11px; font-weight: 700; opacity: 0.8; margin-bottom: 4px;' }, m.sender_name));
            }
            bubble.appendChild(Dom.el('div', {}, m.body));
            bubble.appendChild(Dom.el('div', { style: 'font-size: 11px; opacity: 0.7; margin-top: 4px;' }, m.time_display));
            msgList.appendChild(bubble);
          });
          msgList.scrollTop = msgList.scrollHeight;

          // Compose box — send routes by child_id (find-or-create per child).
          const childId = convo.child_id || state.selectedChildId;
          const compose = Dom.el('form', { style: 'border-top: 1px solid var(--ink-200); padding: 12px; display: flex; gap: 8px;' });
          const input = Dom.el('input', { class: 'kt-compose-input', type: 'text', placeholder: childId ? 'Type a message…' : 'Read-only conversation', style: 'flex: 1; padding: 13px 16px; border: 1px solid var(--ink-300); border-radius: 24px; font-size: 16px;' });
          const sendBtn = Dom.el('button', { type: 'submit', style: 'background: #159FB4; color: white; border: none; padding: 0 20px; border-radius: 24px; font-weight: 600; cursor: pointer;' }, 'Send');
          if (!childId) { input.disabled = true; sendBtn.disabled = true; }
          compose.appendChild(input); compose.appendChild(sendBtn);
          compose.addEventListener('submit', async (ev) => {
            ev.preventDefault();
            if (!input.value.trim() || !childId) return;
            sendBtn.disabled = true;
            try {
              await Api.post('/parent/messages', { child_id: childId, body: input.value.trim() });
              input.value = '';
              loadThread(convo);
            } catch (e) {
              input.placeholder = 'Could not send — try again';
            } finally {
              sendBtn.disabled = false;
            }
          });
          thread.appendChild(compose);
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

  async function renderBillingTab(wrap) {
    wrap.appendChild(buildSubNav('billing'));
    const child = state.children.find(c => c.id === state.selectedChildId);
    if (!child) return;

    wrap.appendChild(Dom.el('h1', { style: 'margin: 0 0 24px;' }, 'Billing'));

    const container = Dom.el('div');
    wrap.appendChild(container);
    container.appendChild(Dom.el('p', { style: 'color: var(--ink-500);' }, 'Loading invoices…'));

    try {
      const data = await Api.get(`/parent/children/${child.id}/invoices`);
      Dom.clear(container);

      if (!data.invoices || data.invoices.length === 0) {
        container.appendChild(emptyState('📄', 'No invoices yet', 'When your monthly invoice is generated, it will appear here.'));
        return;
      }

      data.invoices.forEach(inv => {
        const card = Dom.el('div', { class: 'card', style: 'margin-bottom: 16px;' });
        const head = Dom.el('div', { style: 'display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 12px;' });
        head.appendChild(Dom.el('div', {}, [
          Dom.el('div', { style: 'font-weight: 700; font-size: 18px;' }, inv.invoice_number),
          Dom.el('div', { style: 'color: var(--ink-600); font-size: 13px;' }, `Issued ${inv.issue_date} · Due ${inv.due_date}`),
        ]));
        const statusColor = inv.status === 'paid' ? 'var(--brand-green)' : (inv.status === 'overdue' ? '#c0392b' : 'var(--ink-600)');
        head.appendChild(Dom.el('span', { style: `background: ${statusColor}; color: white; padding: 4px 12px; border-radius: 12px; font-size: 12px; font-weight: 700; text-transform: uppercase;` }, inv.status_label || inv.status));
        card.appendChild(head);

        const breakdown = Dom.el('div', { style: 'background: var(--ink-50); padding: 16px; border-radius: 8px; display: grid; grid-template-columns: 1fr auto; gap: 8px; font-size: 14px;' });
        breakdown.appendChild(Dom.el('div', { style: 'color: var(--ink-600);' }, 'Subtotal'));
        breakdown.appendChild(Dom.el('div', { style: 'text-align: right;' }, '$' + inv.subtotal.toFixed(2)));
        if (inv.subsidy_amount > 0) {
          breakdown.appendChild(Dom.el('div', { style: 'color: var(--ink-600);' }, 'CWELCC subsidy'));
          breakdown.appendChild(Dom.el('div', { style: 'text-align: right; color: var(--brand-green);' }, '−$' + inv.subsidy_amount.toFixed(2)));
        }
        breakdown.appendChild(Dom.el('div', { style: 'font-weight: 700; font-size: 16px; border-top: 1px solid var(--ink-200); padding-top: 8px;' }, 'Your portion'));
        breakdown.appendChild(Dom.el('div', { style: 'text-align: right; font-weight: 700; font-size: 16px; border-top: 1px solid var(--ink-200); padding-top: 8px; color: var(--brand-blue);' }, '$' + inv.total.toFixed(2)));
        card.appendChild(breakdown);

        if (inv.balance_due > 0) {
          card.appendChild(Dom.el('div', { style: 'background: #FEF3C7; border-left: 3px solid #F59E0B; padding: 12px; margin-top: 12px; font-size: 13px; border-radius: 4px;' },
            `Balance due: $${inv.balance_due.toFixed(2)} · Please pay by ${inv.due_date}. Contact your centre for payment instructions.`
          ));
        }
        container.appendChild(card);
      });
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

  // Horizontal, scrollable child selector (only when there's >1 child).
  function buildChildChips() {
    const bar = Dom.el('div', { style: 'display:flex;gap:8px;overflow-x:auto;padding:2px 0 10px;-webkit-overflow-scrolling:touch;scrollbar-width:none;' });
    state.children.forEach(c => {
      const active = c.id === state.selectedChildId;
      const chip = Dom.el('button', {
        style: `display:flex;align-items:center;gap:8px;flex:0 0 auto;padding:5px 15px 5px 5px;border-radius:24px;cursor:pointer;font-weight:700;font-size:14px;`
          + `border:1.5px solid ${active ? 'var(--brand-blue)' : 'var(--ink-200)'};background:${active ? 'var(--brand-blue)' : '#fff'};color:${active ? '#fff' : 'var(--ink-700)'};`,
      }, [
        Dom.el('span', { style: `width:30px;height:30px;border-radius:50%;display:inline-flex;align-items:center;justify-content:center;font-size:13px;font-weight:800;color:#fff;background:${active ? 'rgba(255,255,255,.25)' : 'var(--brand-green)'};` },
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
  function openAbsenceSheet(child, sourceBtn) {
    const ov = Dom.el('div', {
      style: 'position:fixed;inset:0;z-index:9650;background:rgba(8,17,33,.6);display:flex;align-items:flex-end;',
    });
    const sheet = Dom.el('div', {
      style: 'background:#fff;width:100%;border-radius:20px 20px 0 0;padding:18px 16px calc(env(safe-area-inset-bottom,0px) + 18px);',
    });
    ov.appendChild(sheet);

    sheet.innerHTML = `
      <div style="width:38px;height:4px;border-radius:99px;background:#E2E8F0;margin:0 auto 14px;"></div>
      <div style="font-weight:800;font-size:17px;color:#0F172A;">${child.display_name} isn't attending today</div>
      <div style="font-size:13px;color:#64748B;line-height:1.5;margin-top:4px;">
        Your centre will be told straight away, and we'll stop reminding you to sign in today.
      </div>
      <div style="font-size:11px;font-weight:800;letter-spacing:.5px;color:#64748B;text-transform:uppercase;margin:14px 0 7px;">Reason</div>
      <div id="kt-abs-reasons" style="display:flex;flex-wrap:wrap;gap:7px;"></div>
      <textarea id="kt-abs-note" placeholder="Anything the centre should know (optional)"
        style="width:100%;box-sizing:border-box;margin-top:12px;padding:11px;border:1.5px solid #E3EAF1;border-radius:10px;font-size:16px;min-height:64px;font-family:inherit;"></textarea>
      <div id="kt-abs-err" style="color:#B91C1C;font-size:12.5px;min-height:16px;margin-top:4px;"></div>
      <button id="kt-abs-send" style="width:100%;background:#159FB4;color:#fff;border:none;border-radius:12px;padding:14px;font-size:15px;font-weight:800;cursor:pointer;">Tell the centre</button>
      <button id="kt-abs-cancel" style="width:100%;background:none;border:none;color:#94A3B8;font-size:13px;font-weight:700;padding:11px;cursor:pointer;">Cancel</button>
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
        if (KT.toast) KT.toast('✅', 'Centre told', 'Your centre knows they are not coming in today.', '#16A34A');
      }).catch((e) => {
        btn.disabled = false; btn.textContent = 'Tell the centre';
        sheet.querySelector('#kt-abs-err').textContent = (e && e.message) || 'Could not send — please try again.';
      });
    });

    document.body.appendChild(ov);
  }

  async function renderTodayMobile(wrap) {
    const child = state.children.find(c => c.id === state.selectedChildId);
    if (!child) return;
    const atCentre = child.is_at_centre;

    // 1 · Child status card
    const status = Dom.el('div', { style: card('padding:15px;display:flex;align-items:center;gap:13px;margin-bottom:13px;') });
    status.appendChild(Dom.el('div', { style: 'width:52px;height:52px;border-radius:50%;background:var(--brand-green);color:#fff;display:flex;align-items:center;justify-content:center;font-size:22px;font-weight:800;flex-shrink:0;' },
      (child.display_name || 'C')[0].toUpperCase()));
    const info = Dom.el('div', { style: 'flex:1;min-width:0;' });
    info.appendChild(Dom.el('div', { style: 'font-size:19px;font-weight:800;color:var(--ink-900);line-height:1.15;' }, child.display_name));
    info.appendChild(Dom.el('div', { style: 'font-size:12.5px;color:var(--ink-500);margin-top:2px;' }, `${child.age?.human || '—'} · ${child.room_name || 'No room'}`));
    info.appendChild(Dom.el('div', { style: `display:inline-flex;align-items:center;gap:5px;margin-top:8px;padding:3px 11px;border-radius:20px;font-size:11px;font-weight:800;letter-spacing:.3px;${atCentre ? 'background:rgba(142,199,60,.16);color:#4d7c0f;' : 'background:var(--ink-100);color:var(--ink-600);'}` }, [
      Dom.el('span', { style: `width:7px;height:7px;border-radius:50%;background:${atCentre ? '#65a30d' : 'var(--ink-400)'};` }),
      atCentre ? 'At centre' : 'At home',
    ]));
    status.appendChild(info);
    wrap.appendChild(status);

    // "Not attending today" — the centre has to chase every empty chair, and today
    // that means a phone call at 9am. One tap here tells the room's educators, the
    // director and the agency admin, and stops the sign-in reminders for the day.
    if (!atCentre) {
      const absWrap = Dom.el('div', { style: 'margin:-4px 0 13px;' });
      const absBtn = Dom.el('button', {
        type: 'button',
        style: 'width:100%;background:#fff;border:1.5px solid #E2E8F0;color:#475569;border-radius:12px;'
          + 'padding:11px;font-size:13.5px;font-weight:800;cursor:pointer;',
      }, `🚫 ${child.display_name} isn't attending today`);
      absBtn.addEventListener('click', () => openAbsenceSheet(child, absBtn));
      absWrap.appendChild(absBtn);
      wrap.appendChild(absWrap);

      // If they already told us, say so instead of offering it again.
      cget(`/parent/absences?child_id=${child.id}`).then((r) => {
        const today = KT.agencyToday ? KT.agencyToday() : new Date().toISOString().slice(0, 10);
        const hit = (r.absences || []).find(a => String(a.absent_on).slice(0, 10) === today);
        if (!hit) return;
        absBtn.textContent = `✓ Reported absent today${hit.reason ? ' · ' + hit.reason : ''}`;
        absBtn.style.borderColor = '#159FB4';
        absBtn.style.color = '#0E7C90';
        absBtn.style.background = '#F0FBFD';
      }).catch(() => {});
    }

    // 2 · Daily digest (the emotional centrepiece)
    const digest = Dom.el('div', { style: 'background:linear-gradient(135deg,#1F6080,#2c7894);color:#fff;border-radius:18px;padding:18px;margin-bottom:14px;box-shadow:0 6px 18px -8px rgba(31,96,128,.55);' });
    digest.appendChild(Dom.el('div', { style: 'font-size:11px;font-weight:800;letter-spacing:1.2px;color:#a3d977;margin-bottom:7px;' }, "✨ TODAY'S STORY"));
    const digestBody = Dom.el('div', { style: 'font-size:14.5px;line-height:1.55;opacity:.95;' }, 'Loading…');
    digest.appendChild(digestBody);
    wrap.appendChild(digest);

    // 3 · At-a-glance stats
    const stats = Dom.el('div', { style: 'display:grid;grid-template-columns:repeat(4,1fr);gap:8px;margin-bottom:18px;' });
    wrap.appendChild(stats);

    // 4 · Timeline
    wrap.appendChild(Dom.el('div', { style: 'font-size:15px;font-weight:800;color:var(--ink-900);margin:0 2px 9px;' }, "Today's timeline"));
    const tlCard = Dom.el('div', { style: card('padding:4px 14px;margin-bottom:16px;') });
    const tlBody = Dom.el('div'); tlCard.appendChild(tlBody);
    tlBody.appendChild(Dom.el('div', { style: 'padding:20px 0;text-align:center;color:var(--ink-400);font-size:13px;' }, 'Loading…'));
    wrap.appendChild(tlCard);

    // 5 · Billing quick link
    const bill = Dom.el('a', { href: '#billing', style: card('display:flex;align-items:center;gap:12px;padding:14px;text-decoration:none;margin-bottom:8px;') });
    wrap.appendChild(bill);

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
      const ev = timeline.events || [];
      const count = (types) => ev.filter(e => types.includes(e.type)).length;

      // The tiles summarise the timeline directly below them, so tapping one
      // filters that timeline instead of being a dead number. Tapping the active
      // tile again clears the filter.
      let activeFilter = null;
      const TILES = [
        { icon: '🍽️', label: 'Meals',   types: ['meal', 'snack'] },
        { icon: '😴', label: 'Naps',    types: ['nap_end'] },
        { icon: '👶', label: 'Changes', types: ['diaper', 'bathroom'] },
        { icon: '✨', label: 'Play',    types: ['activity'] },
      ];
      const tileEls = [];
      TILES.forEach((t) => {
        const n = count(t.types);
        const el = Dom.el('button', {
          type: 'button',
          style: card('padding:11px 4px;text-align:center;border:1.5px solid transparent;cursor:pointer;width:100%;font:inherit;'),
        }, [
          Dom.el('div', { style: 'font-size:19px;line-height:1;' }, t.icon),
          Dom.el('div', { style: 'font-size:21px;font-weight:800;color:var(--brand-blue);margin-top:4px;line-height:1;' }, String(n)),
          Dom.el('div', { style: 'font-size:10.5px;color:var(--ink-500);margin-top:3px;font-weight:600;' }, t.label),
        ]);
        el.addEventListener('click', () => {
          if (!n) return;                       // nothing logged → nothing to show
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
          tlBody.appendChild(Dom.el('div', { style: 'padding:22px 0;text-align:center;color:var(--ink-400);font-size:13px;' },
            types ? 'Nothing logged for this yet today.' : 'No moments logged yet today.'));
          return;
        }
        rows.forEach((e, i) => {
          const row = Dom.el('div', { style: `display:flex;gap:12px;align-items:flex-start;padding:11px 0;${i < rows.length - 1 ? 'border-bottom:1px solid var(--ink-100);' : ''}` });
          row.appendChild(Dom.el('div', { style: 'width:38px;height:38px;border-radius:12px;background:var(--ink-50);display:flex;align-items:center;justify-content:center;font-size:18px;flex-shrink:0;' }, eventIcon(e.type)));
          const t = Dom.el('div', { style: 'flex:1;min-width:0;' });
          t.appendChild(Dom.el('div', { style: 'font-weight:700;font-size:14px;color:var(--ink-900);' }, e.display?.title || e.type));
          if (e.display?.detail) t.appendChild(Dom.el('div', { style: 'font-size:12.5px;color:var(--ink-500);margin-top:1px;' }, e.display.detail));
          row.appendChild(t);
          row.appendChild(Dom.el('div', { style: 'font-size:12px;color:var(--ink-400);flex-shrink:0;font-weight:600;' }, e.time_display));
          tlBody.appendChild(row);
        });
      }
      renderTimeline(null, null);

      const inv = (invoices.invoices || [])[0];
      Dom.clear(bill);
      bill.appendChild(Dom.el('div', { style: 'width:40px;height:40px;border-radius:12px;background:rgba(31,96,128,.1);display:flex;align-items:center;justify-content:center;font-size:19px;flex-shrink:0;' }, '💳'));
      const bt = Dom.el('div', { style: 'flex:1;' });
      if (inv) {
        bt.appendChild(Dom.el('div', { style: 'font-size:12px;color:var(--ink-500);font-weight:600;' }, inv.is_estimate ? 'Estimated this month' : 'Balance due'));
        bt.appendChild(Dom.el('div', { style: 'font-size:18px;font-weight:800;color:var(--ink-900);' }, '$' + (inv.balance_due ?? inv.total).toFixed(2)));
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
    wrap.appendChild(Dom.el('div', { style: 'font-size:18px;font-weight:800;color:var(--ink-900);margin:2px 2px 12px;' }, name ? `${name}'s Photos` : 'Photos'));

    const grid = Dom.el('div', { style: 'display:grid;grid-template-columns:1fr 1fr;gap:10px;' });
    wrap.appendChild(grid);
    grid.appendChild(Dom.el('div', { style: 'grid-column:1/-1;text-align:center;color:var(--ink-400);padding:20px;font-size:13px;' }, 'Loading photos…'));
    try {
      const data = await cget(`/parent/children/${child.id}/photos`);
      Dom.clear(grid);
      if (!data.photos || !data.photos.length) {
        grid.appendChild(Dom.el('div', { style: 'grid-column:1/-1;' }, [emptyState('📷', 'No photos yet', `Photos of ${child.display_name} will appear here.`)]));
        return;
      }
      data.photos.forEach(p => {
        const c = Dom.el('div', { style: 'border-radius:14px;overflow:hidden;' + card() });
        // Educators can share short video clips as well as photos — a first
        // wobble across the room doesn't survive as a still.
        if (p.media_type === 'video') {
          c.appendChild(Dom.el('video', {
            src: p.url, controls: true, preload: 'metadata', playsInline: true,
            style: 'width:100%;aspect-ratio:1;object-fit:cover;display:block;background:#0F172A;',
          }));
        } else {
          const mimg = Dom.el('img', { src: p.url, alt: p.caption || 'Photo', loading: 'lazy', style: 'width:100%;aspect-ratio:1;object-fit:cover;display:block;background:var(--ink-100);cursor:zoom-in;' });
          mimg.addEventListener('click', function () { openPhotoLightbox(p.url, p.caption); });
          c.appendChild(mimg);
        }
        if (p.caption || p.date_display) {
          const b = Dom.el('div', { style: 'padding:8px 10px;' });
          if (p.caption) b.appendChild(Dom.el('div', { style: 'font-size:12.5px;line-height:1.35;color:var(--ink-800);' }, p.caption.replace(/^\[Demo\] /, '')));
          if (p.date_display) b.appendChild(Dom.el('div', { style: 'font-size:11px;color:var(--ink-400);margin-top:3px;' }, p.date_display));
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
    cont.appendChild(Dom.el('div', { style: 'text-align:center;color:var(--ink-400);padding:20px;font-size:13px;' }, 'Loading…'));
    try {
      const data = await cget(`/parent/children/${child.id}/invoices`);
      Dom.clear(cont);
      if (!data.invoices || !data.invoices.length) { cont.appendChild(emptyState('📄', 'No invoices yet', 'Your monthly invoice will appear here.')); return; }
      data.invoices.forEach(inv => {
        const c = Dom.el('div', { style: card('padding:15px;margin-bottom:12px;cursor:pointer;') });
        c.addEventListener('click', () => openInvoiceDetail(inv, child));
        const head = Dom.el('div', { style: 'display:flex;justify-content:space-between;align-items:flex-start;gap:10px;margin-bottom:12px;' });
        head.appendChild(Dom.el('div', {}, [
          Dom.el('div', { style: 'font-weight:800;font-size:16px;color:var(--ink-900);' }, inv.invoice_number),
          Dom.el('div', { style: 'color:var(--ink-500);font-size:12px;margin-top:2px;' }, `Due ${inv.due_date}`),
        ]));
        const sc = inv.status === 'paid' ? 'var(--brand-green)' : (inv.status === 'overdue' ? '#c0392b' : 'var(--ink-500)');
        head.appendChild(Dom.el('span', { style: `background:${sc};color:#fff;padding:3px 11px;border-radius:12px;font-size:11px;font-weight:800;text-transform:uppercase;flex-shrink:0;` }, inv.status_label || inv.status));
        c.appendChild(head);
        const bd = Dom.el('div', { style: 'background:var(--ink-50);padding:13px;border-radius:12px;display:grid;grid-template-columns:1fr auto;gap:7px;font-size:13.5px;' });
        bd.appendChild(Dom.el('div', { style: 'color:var(--ink-600);' }, 'Subtotal'));
        bd.appendChild(Dom.el('div', { style: 'text-align:right;' }, '$' + inv.subtotal.toFixed(2)));
        if (inv.subsidy_amount > 0) {
          bd.appendChild(Dom.el('div', { style: 'color:var(--ink-600);' }, 'CWELCC subsidy'));
          bd.appendChild(Dom.el('div', { style: 'text-align:right;color:var(--brand-green);' }, '−$' + inv.subsidy_amount.toFixed(2)));
        }
        bd.appendChild(Dom.el('div', { style: 'font-weight:800;border-top:1px solid var(--ink-200);padding-top:7px;' }, 'Your portion'));
        bd.appendChild(Dom.el('div', { style: 'text-align:right;font-weight:800;border-top:1px solid var(--ink-200);padding-top:7px;color:var(--brand-blue);' }, '$' + inv.total.toFixed(2)));
        c.appendChild(bd);
        if (inv.balance_due > 0) {
          c.appendChild(Dom.el('div', { style: 'background:#FEF3C7;border-left:3px solid #F59E0B;padding:11px;margin-top:10px;font-size:12.5px;border-radius:6px;color:#92400e;' },
            `Balance $${inv.balance_due.toFixed(2)} · please pay by ${inv.due_date}.`));
        }
        c.appendChild(Dom.el('div', { style: 'display:flex;align-items:center;justify-content:flex-end;gap:4px;margin-top:11px;color:var(--brand-blue);font-weight:700;font-size:13px;' }, [
          Dom.el('span', {}, inv.balance_due > 0 ? 'View & pay' : 'View details'),
          Dom.el('span', { style: 'font-size:16px;' }, '›'),
        ]));
        cont.appendChild(c);
      });
    } catch (e) {
      Dom.clear(cont); cont.appendChild(emptyState('⚠️', 'Could not load billing', e.message));
    }
  }

  // Full-screen invoice detail + pay. Covers the bottom bar (z above it).
  function openInvoiceDetail(inv, child) {
    const appMain = document.getElementById('appMain');
    if (!appMain) return;
    const tw = Dom.el('div', { style: 'position:fixed;inset:0;z-index:9600;background:var(--ink-50,#F4F7FA);display:flex;flex-direction:column;animation:kt-screen-in .22s cubic-bezier(.22,.61,.36,1);' });

    const header = Dom.el('div', { style: 'display:flex;align-items:center;gap:8px;padding:calc(env(safe-area-inset-top,0px) + 10px) 12px 12px;border-bottom:1px solid var(--ink-100);background:#fff;flex-shrink:0;' });
    const back = Dom.el('button', { style: 'background:none;border:none;font-size:26px;color:var(--brand-blue);cursor:pointer;padding:0 6px;line-height:1;flex-shrink:0;' }, '‹');
    back.addEventListener('click', () => { if (window.KT && KT.popOverlay) KT.popOverlay(tw); else tw.remove(); });
    header.appendChild(back);
    header.appendChild(Dom.el('div', { style: 'font-weight:800;font-size:15px;color:var(--ink-900);' }, 'Invoice ' + (inv.invoice_number || '')));
    tw.appendChild(header);

    const body = Dom.el('div', { style: 'flex:1;overflow-y:auto;-webkit-overflow-scrolling:touch;padding:16px;' });
    tw.appendChild(body);

    const money = (n) => '$' + (Number(n) || 0).toFixed(2);
    const sc = inv.status === 'paid' ? 'var(--brand-green)' : (inv.status === 'overdue' ? '#c0392b' : 'var(--ink-500)');

    // Summary
    const sum = Dom.el('div', { style: card('padding:18px;margin-bottom:14px;text-align:center;') });
    sum.appendChild(Dom.el('span', { style: `display:inline-block;background:${sc};color:#fff;padding:3px 12px;border-radius:12px;font-size:11px;font-weight:800;text-transform:uppercase;margin-bottom:10px;` }, inv.status_label || inv.status));
    sum.appendChild(Dom.el('div', { style: 'font-size:34px;font-weight:800;color:var(--ink-900);line-height:1;' }, money(inv.balance_due > 0 ? inv.balance_due : inv.total)));
    sum.appendChild(Dom.el('div', { style: 'font-size:13px;color:var(--ink-500);margin-top:5px;' }, inv.balance_due > 0 ? `Balance due · by ${inv.due_date}` : 'Total'));
    if (child) sum.appendChild(Dom.el('div', { style: 'font-size:12.5px;color:var(--ink-400);margin-top:6px;' }, `${child.display_name} · ${inv.issue_date ? 'Issued ' + inv.issue_date : ''}`));
    body.appendChild(sum);

    // Breakdown
    const bd = Dom.el('div', { style: card('padding:16px;margin-bottom:14px;') });
    bd.appendChild(Dom.el('div', { style: 'font-weight:800;font-size:13px;color:var(--ink-700);margin-bottom:10px;text-transform:uppercase;letter-spacing:.4px;' }, 'Breakdown'));
    const rows = [['Subtotal', money(inv.subtotal)]];
    if (inv.subsidy_amount > 0) rows.push(['CWELCC subsidy', '−' + money(inv.subsidy_amount)]);
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

    // Pay
    if ((inv.balance_due || 0) > 0 && inv.status !== 'paid') {
      const pay = Dom.el('div', { style: card('padding:16px;') });
      pay.appendChild(Dom.el('div', { style: 'font-weight:800;font-size:15px;margin-bottom:12px;color:var(--ink-900);' }, 'Pay this invoice'));
      const status = Dom.el('div', { style: 'font-size:13px;margin:0 0 10px;min-height:16px;line-height:1.5;' });
      const payBtn = Dom.el('button', { type: 'button', style: 'width:100%;border:0;cursor:pointer;padding:15px;border-radius:13px;font-size:16px;font-weight:800;color:#fff;background:linear-gradient(135deg,#0FA3B1,#1F6FB2 60%,#2456A6);box-shadow:0 12px 24px -12px rgba(31,111,178,.55);' }, `Pay ${money(inv.balance_due)} by card`);
      payBtn.addEventListener('click', async () => {
        status.textContent = ''; payBtn.disabled = true; payBtn.textContent = 'Processing…';
        try {
          await Api.post(`/invoices/${inv.id}/charge`, {});
          status.style.color = '#16A34A'; status.textContent = '✓ Payment successful — thank you!';
          payBtn.style.display = 'none';
          bust(`/parent/children/${child.id}/invoices`); bust(`/parent/children/${child.id}/invoices?status=current`);
        } catch (e) {
          status.style.color = '#B45309';
          status.textContent = 'Card payments aren’t switched on for your centre yet. Please use e-transfer below, or ask your centre to enable online payments.';
          payBtn.disabled = false; payBtn.textContent = `Pay ${money(inv.balance_due)} by card`;
        }
      });
      pay.appendChild(payBtn);
      pay.appendChild(status);
      pay.appendChild(Dom.el('div', { style: 'margin-top:12px;padding:12px;background:var(--ink-50);border-radius:10px;font-size:12.5px;color:var(--ink-600);line-height:1.5;' },
        'Prefer e-Transfer? Send an Interac e-Transfer to your centre’s billing email and put your invoice number in the message. Your centre can confirm the exact address.'));
      body.appendChild(pay);
    } else if (inv.status === 'paid') {
      body.appendChild(Dom.el('div', { style: card('padding:18px;text-align:center;color:#16A34A;font-weight:800;font-size:15px;') }, '✓ Paid — thank you!'));
    }

    appMain.appendChild(tw);
    if (window.KT && KT.pushOverlay) KT.pushOverlay(tw);
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
    listWrap.appendChild(Dom.el('div', { style: 'text-align:center;color:var(--ink-400);padding:20px;font-size:13px;' }, 'Loading…'));

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
      row.appendChild(Dom.el('div', { style: 'width:44px;height:44px;border-radius:50%;color:#fff;display:flex;align-items:center;justify-content:center;font-size:17px;font-weight:800;flex-shrink:0;background:#159FB4;' },
        title[0].toUpperCase()));
      const mid = Dom.el('div', { style: 'flex:1;min-width:0;' });
      mid.appendChild(Dom.el('div', { style: 'display:flex;align-items:center;gap:8px;' }, [
        Dom.el('div', { style: 'font-weight:700;font-size:14.5px;color:var(--ink-900);flex:1;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;' }, title),
        Dom.el('div', { style: 'font-size:11px;color:var(--ink-400);flex-shrink:0;' }, c.last_message_display || ''),
      ]));
      if (subtitle) mid.appendChild(Dom.el('div', { style: 'font-size:12px;color:var(--ink-500);margin-top:1px;' }, subtitle));
      if (c.last_message_preview) {
        const prev = c.last_message_preview.length > 54 ? c.last_message_preview.slice(0, 54) + '…' : c.last_message_preview;
        mid.appendChild(Dom.el('div', { style: `font-size:12.5px;margin-top:3px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;${c.unread_count > 0 ? 'color:var(--ink-800);font-weight:600;' : 'color:var(--ink-400);'}` }, prev));
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
  function msgAvatar(name, photoUrl) {
    const holder = document.createElement('span');
    if (window.KT && KT.avatar) {
      holder.innerHTML = KT.avatar(name || '?', { size: 30, photoUrl: photoUrl ? absUrl(photoUrl) : null });
      return holder.firstElementChild || holder;
    }
    holder.textContent = (name || '?').charAt(0).toUpperCase();
    holder.style.cssText = 'width:30px;height:30px;border-radius:50%;background:#159FB4;color:#fff;display:inline-flex;align-items:center;justify-content:center;font-weight:700;font-size:13px;flex-shrink:0;';
    return holder;
  }
  function ktToast(icon, msg) {
    try { if (window.KT && KT.toast) return KT.toast(icon, msg, '', '#159FB4'); } catch (e) {}
    const t = Dom.el('div', { style: 'position:fixed;left:50%;bottom:90px;transform:translateX(-50%);z-index:14000;background:#0D1B2A;color:#fff;padding:11px 18px;border-radius:24px;font-size:14px;font-weight:600;box-shadow:0 8px 24px rgba(0,0,0,.3);max-width:88%;text-align:center;' }, (icon ? icon + ' ' : '') + msg);
    document.body.appendChild(t); setTimeout(() => t.remove(), 2600);
  }
  const KT_EMOJIS = ['😀','😄','😊','🥰','😍','😘','😉','🤗','🙂','😢','😭','😔','😴','🤒','🤧','🥳','🎉','👍','👏','🙏','💚','❤️','🔥','✨','⭐','🌟','👋','🤝','💪','🙌','👶','🍼','🧸','🎨','📸','🎵','☀️','🌈','🍎','🥪','💤','✅','❓','❗'];

  function openThreadMobile(convo) {
    const appMain = document.getElementById('appMain');
    if (!appMain) return;
    // Never stack threads — close any already-open one first.
    appMain.querySelectorAll('.kt-thread-compose').forEach(c => { const w = c.closest('div'); if (w && w.parentNode === appMain) w.remove(); });

    const childId = convo.child_id || state.selectedChildId;

    const tw = Dom.el('div', { style: 'animation:kt-screen-in .22s cubic-bezier(.22,.61,.36,1);' });

    const header = Dom.el('div', { class: 'kt-thread-header', style: 'display:flex;align-items:center;gap:8px;padding:12px 12px;border-bottom:1px solid var(--ink-100);background:#fff;' });
    const back = Dom.el('button', { type: 'button', style: 'background:none;border:none;font-size:26px;color:#159FB4;cursor:pointer;padding:0 6px;line-height:1;flex-shrink:0;' }, '‹');
    const closeThread = () => { try { if (pollTimer) clearInterval(pollTimer); } catch (e) {} if (window.KT && KT.popOverlay) KT.popOverlay(tw); else tw.remove(); };
    back.addEventListener('click', closeThread);
    header.appendChild(back);
    const convoTitle = convo.child_name || convo.subject || 'Conversation';
    const htxt = Dom.el('div', { style: 'flex:1;min-width:0;' });
    htxt.appendChild(Dom.el('div', { style: 'font-weight:800;font-size:15px;color:var(--ink-900);overflow:hidden;text-overflow:ellipsis;white-space:nowrap;' }, convoTitle));
    htxt.appendChild(Dom.el('div', { style: 'font-size:12px;color:var(--ink-500);' }, (convo.child_name && convo.subject) ? convo.subject : 'Messages'));
    header.appendChild(htxt);
    // Nudge — a gentle "please reply" ping to the centre team.
    const nudge = Dom.el('button', { type: 'button', title: 'Nudge the team', style: 'background:var(--ink-100);border:none;width:40px;height:40px;border-radius:50%;font-size:19px;cursor:pointer;flex-shrink:0;' }, '👋');
    if (!childId) nudge.disabled = true;
    nudge.addEventListener('click', async () => {
      nudge.disabled = true;
      try { await Api.post('/parent/messages/nudge', { child_id: childId }); ktToast('👋', 'Nudged the team — they’ll get a notification'); }
      catch (e) { ktToast('⚠️', e.message || 'Could not send the nudge'); }
      finally { setTimeout(() => { nudge.disabled = !childId; }, 4000); }
    });
    header.appendChild(nudge);
    // Desktop shows an explicit ✕ to close the floating chat panel (mobile uses the ‹ back).
    var closeX = Dom.el('button', { type: 'button', title: 'Close', class: 'kt-thread-close-desk', style: 'background:var(--ink-100);border:none;width:40px;height:40px;border-radius:50%;font-size:19px;cursor:pointer;flex-shrink:0;color:var(--ink-600);line-height:1;align-items:center;justify-content:center;' }, '✕');
    closeX.addEventListener('click', closeThread);
    header.appendChild(closeX);
    tw.appendChild(header);

    const body = Dom.el('div', { class: 'kt-thread-body', style: 'padding:14px;display:flex;flex-direction:column;gap:8px;background:var(--ink-50);' });
    body.appendChild(Dom.el('div', { style: 'text-align:center;color:var(--ink-400);font-size:13px;padding:20px;' }, 'Loading…'));
    tw.appendChild(body);

    // Emoji panel (hidden until toggled).
    const emojiPanel = Dom.el('div', { style: 'display:none;flex-wrap:wrap;gap:2px;padding:8px 10px;background:#fff;border-top:1px solid var(--ink-100);max-height:150px;overflow-y:auto;' });
    KT_EMOJIS.forEach(em => {
      const b = Dom.el('button', { type: 'button', style: 'background:none;border:none;font-size:22px;line-height:1;padding:6px;cursor:pointer;border-radius:8px;' }, em);
      b.addEventListener('click', () => { insertEmoji(em); });
      emojiPanel.appendChild(b);
    });
    tw.appendChild(emojiPanel);

    const compose = Dom.el('form', { class: 'kt-thread-compose', style: 'display:flex;gap:6px;align-items:center;padding:10px 10px;border-top:1px solid var(--ink-100);background:#fff;' });
    const fileInput = Dom.el('input', { type: 'file', accept: 'image/*', style: 'display:none;' });
    const emojiBtn = Dom.el('button', { type: 'button', title: 'Emoji', style: 'background:none;border:none;width:40px;height:44px;font-size:22px;cursor:pointer;flex-shrink:0;' }, '😊');
    emojiBtn.addEventListener('click', () => { emojiPanel.style.display = emojiPanel.style.display === 'none' ? 'flex' : 'none'; });
    const attach = Dom.el('button', { type: 'button', title: 'Add a photo', style: 'background:none;color:#159FB4;border:none;width:40px;height:44px;font-size:22px;cursor:pointer;flex-shrink:0;' }, '📷');
    attach.addEventListener('click', () => fileInput.click());
    const input = Dom.el('input', { class: 'kt-compose-input', type: 'text', placeholder: 'Message…', style: 'flex:1;min-width:0;padding:13px 15px;border:1px solid var(--ink-200);border-radius:24px;font-size:16px;min-height:46px;box-sizing:border-box;' });
    // One button that is Mic when empty, Send when there's text, Stop while recording.
    const action = Dom.el('button', { type: 'button', style: 'background:#159FB4;color:#fff;border:none;width:46px;height:46px;border-radius:50%;font-size:19px;cursor:pointer;flex-shrink:0;' }, '🎤');
    compose.appendChild(emojiBtn); compose.appendChild(attach); compose.appendChild(input); compose.appendChild(fileInput); compose.appendChild(action);
    tw.appendChild(compose);

    appMain.appendChild(tw);
    if (window.KT && KT.pushOverlay) KT.pushOverlay(tw, closeThread);

    function insertEmoji(em) {
      const s = input.selectionStart != null ? input.selectionStart : input.value.length;
      const e = input.selectionEnd != null ? input.selectionEnd : input.value.length;
      input.value = input.value.slice(0, s) + em + input.value.slice(e);
      input.focus(); const pos = s + em.length; try { input.setSelectionRange(pos, pos); } catch (x) {}
      updateAction();
    }

    let recorder = null, recStream = null, recChunks = [], recTimer = null;
    let editingId = null;               // when set, the composer edits an existing message
    const editBanner = Dom.el('div', { style: 'display:none;align-items:center;gap:8px;padding:7px 12px;background:#FEF3C7;border-top:1px solid #FDE68A;font-size:12.5px;color:#92400E;font-weight:600;' });
    const editBannerX = Dom.el('button', { type: 'button', style: 'margin-left:auto;background:none;border:none;color:#92400E;font-size:16px;cursor:pointer;font-weight:800;' }, '✕');
    editBanner.appendChild(Dom.el('span', {}, '✏️ Editing message'));
    editBanner.appendChild(editBannerX);
    tw.insertBefore(editBanner, compose);   // sits just above the composer
    function updateAction() {
      if (recorder && recorder.state === 'recording') { action.textContent = '⏹'; action.style.background = '#EF4444'; return; }
      if (editingId) { action.textContent = '✓'; action.style.background = '#159FB4'; return; }
      if (input.value.trim()) { action.textContent = '➤'; action.style.background = '#159FB4'; }
      else { action.textContent = '🎤'; action.style.background = '#159FB4'; }
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
      const sheet = Dom.el('div', { style: 'background:#fff;width:100%;border-radius:20px 20px 0 0;padding:8px 12px calc(env(safe-area-inset-bottom,0px) + 12px);' });
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
    async function startRec() {
      if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia || !window.MediaRecorder) { ktToast('⚠️', 'Voice messages aren’t supported on this device'); return; }
      try { recStream = await navigator.mediaDevices.getUserMedia({ audio: true }); }
      catch (e) { ktToast('🎤', 'Microphone permission is needed for voice messages'); return; }
      recChunks = [];
      try { recorder = new MediaRecorder(recStream); } catch (e) { recorder = new MediaRecorder(recStream, {}); }
      recorder.ondataavailable = (e) => { if (e.data && e.data.size) recChunks.push(e.data); };
      recorder.onstop = () => {
        try { recStream.getTracks().forEach(t => t.stop()); } catch (e) {}
        clearInterval(recTimer); recTimer = null;
        const type = (recorder && recorder.mimeType) || 'audio/webm';
        const blob = new Blob(recChunks, { type });
        input.placeholder = 'Message…';
        recorder = null; updateAction();
        // Do NOT auto-send — let the user review, then Send or Delete.
        if (blob.size > 800) voicePreview(blob, type);
      };
      recorder.start(); updateAction();
      const t0 = Date.now();
      recTimer = setInterval(() => { const s = Math.floor((Date.now() - t0) / 1000); input.placeholder = '● Recording ' + Math.floor(s / 60) + ':' + ('0' + (s % 60)).slice(-2) + ' — tap ⏹ to review'; }, 250);
    }
    function stopRec() { if (recorder && recorder.state === 'recording') recorder.stop(); }
    // Voice review bar: play it back, then Send or Delete (never auto-sends).
    function voicePreview(blob, type) {
      var url = URL.createObjectURL(blob);
      var bar = Dom.el('div', { class: 'kt-voice-preview', style: 'display:flex;align-items:center;gap:8px;padding:10px 12px;border-top:1px solid var(--ink-100);background:#fff;' });
      var del = Dom.el('button', { type: 'button', title: 'Delete', style: 'background:none;border:none;font-size:22px;cursor:pointer;flex-shrink:0;' }, '🗑');
      var au = Dom.el('audio', { controls: '', src: url, style: 'flex:1;min-width:0;height:40px;' });
      var snd = Dom.el('button', { type: 'button', style: 'background:#159FB4;color:#fff;border:none;border-radius:22px;padding:0 18px;height:44px;font-weight:800;cursor:pointer;flex-shrink:0;' }, 'Send');
      bar.appendChild(del); bar.appendChild(au); bar.appendChild(snd);
      compose.style.display = 'none';
      tw.insertBefore(bar, compose);
      function cleanup() { try { URL.revokeObjectURL(url); } catch (e) {} bar.remove(); compose.style.display = 'flex'; }
      del.addEventListener('click', cleanup);
      snd.addEventListener('click', function () { var f = new File([blob], 'voice.webm', { type: type }); cleanup(); doSend(f); });
    }
    action.addEventListener('click', () => {
      if (recorder && recorder.state === 'recording') { stopRec(); return; }
      if (input.value.trim()) { doSend(null); return; }
      startRec();
    });

    function renderMsgs(msgs) {
        Dom.clear(body);
        if (!msgs.length) { body.appendChild(Dom.el('div', { style: 'text-align:center;color:var(--ink-400);font-size:13px;padding:24px;' }, 'No messages yet — say hello 👋')); return; }
        msgs.forEach(m => {
          // System messages (e.g. a nudge) render as a centered pill in the history.
          if (m.is_system) {
            body.appendChild(Dom.el('div', { style: 'align-self:center;background:rgba(15,23,42,.06);color:var(--ink-600);font-size:12px;font-weight:600;padding:5px 12px;border-radius:14px;margin:2px 0;text-align:center;max-width:88%;' }, (m.body || '') + ' · ' + m.time_display));
            return;
          }
          const mine = m.is_mine;
          const row = Dom.el('div', { style: `display:flex;gap:6px;align-items:flex-end;max-width:100%;${mine ? 'flex-direction:row-reverse;' : ''}` });
          row.appendChild(msgAvatar(m.sender_name, m.sender_photo_url));
          const b = Dom.el('div', { style: `position:relative;max-width:74%;padding:9px 13px;border-radius:16px;font-size:14px;line-height:1.4;${mine ? 'background:#E1F3F6;color:#0D1B2A;border-bottom-right-radius:5px;' : 'background:#fff;color:var(--ink-900);border-bottom-left-radius:5px;box-shadow:0 1px 3px rgba(15,23,42,.08);'}` });
          if (!mine) b.appendChild(Dom.el('div', { style: 'font-size:11px;font-weight:800;color:#159FB4;margin-bottom:3px;' }, m.sender_name));
          if (m.deleted) {
            b.appendChild(Dom.el('div', { style: `font-style:italic;opacity:.8;` }, '🚫 Message deleted'));
          } else {
            if (m.body) b.appendChild(Dom.el('div', {}, m.body));
            (m.attachments || []).forEach(a => {
              if (!a || !a.url) return;
              if (a.type === 'audio') {
                const au = Dom.el('audio', { controls: '', preload: 'metadata', src: absUrl(a.url), style: 'margin-top:6px;width:210px;max-width:100%;height:40px;display:block;' });
                b.appendChild(au);
              } else {
                const img = Dom.el('img', { src: absUrl(a.url), alt: 'Photo', loading: 'lazy', style: 'max-width:210px;width:100%;border-radius:10px;margin-top:6px;display:block;cursor:pointer;' });
                img.addEventListener('click', () => window.open(absUrl(a.url), '_blank'));
                b.appendChild(img);
              }
            });
          }
          const foot = Dom.el('div', { style: `display:flex;align-items:center;gap:6px;font-size:10.5px;margin-top:3px;${mine ? 'color:rgba(13,27,42,.5);justify-content:flex-end;' : 'color:var(--ink-400);'}` });
          foot.appendChild(Dom.el('span', {}, m.time_display + (m.edited ? ' · edited' : '')));
          // Own, non-deleted messages get an edit/delete menu.
          if (mine && !m.deleted && (m.can_edit || m.can_delete)) {
            const menuBtn = Dom.el('button', { type: 'button', title: 'Options', style: 'background:none;border:none;color:inherit;opacity:.85;font-size:15px;line-height:1;cursor:pointer;padding:0 2px;' }, '⋯');
            menuBtn.addEventListener('click', (ev) => { ev.stopPropagation(); msgActions(m); });
            foot.appendChild(menuBtn);
          }
          b.appendChild(foot);
          row.appendChild(b);
          body.appendChild(row);
        });
    }
    // Signature of the message list — lets the poller tell when something changed.
    function sigOf(msgs) { var last = msgs[msgs.length - 1]; return msgs.length + '|' + (last ? (last.id + ':' + (last.edited ? 1 : 0) + ':' + (last.deleted ? 1 : 0)) : ''); }
    let lastSig = null, pollTimer = null, lastLen = 0;
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
    // Live updates: poll every few seconds so new messages appear with no refresh.
    // Keeps the user's scroll position unless they're already at the bottom.
    const poll = async () => {
      if (!document.body.contains(body)) { if (pollTimer) clearInterval(pollTimer); return; }
      if (editingId) return;
      try {
        const md = await Api.get('/parent/messages/' + convo.id);
        const msgs = md.messages || [];
        const s = sigOf(msgs);
        if (s === lastSig) return;
        // A genuinely new message from the other side → play the loud chime.
        const grew = msgs.length > lastLen;
        const last = msgs[msgs.length - 1];
        if (grew && last && !last.is_mine) { try { if (window.KT && KT.playPing) KT.playPing(); } catch (e) {} }
        lastLen = msgs.length;
        const atBottom = body.scrollTop + body.clientHeight >= body.scrollHeight - 60;
        const prevTop = body.scrollTop;
        lastSig = s;
        renderMsgs(msgs);
        body.scrollTop = atBottom ? body.scrollHeight : prevTop;
      } catch (e) {}
    };
    if (!childId) { input.disabled = true; input.placeholder = 'Read-only conversation'; action.disabled = true; attach.disabled = true; emojiBtn.disabled = true; }
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
      action.disabled = true; attach.disabled = true;
      const prevPh = input.placeholder;
      if (file) input.placeholder = (file.type || '').indexOf('audio') === 0 ? 'Sending voice note…' : 'Sending photo…';
      emojiPanel.style.display = 'none';
      try { await sendParentMessage(childId, input.value.trim(), file); bust('/parent/messages'); input.value = ''; input.placeholder = 'Message…'; updateAction(); await load(); }
      catch (e) { input.placeholder = 'Could not send — try again'; }
      finally { action.disabled = false; attach.disabled = false; }
    };
    fileInput.addEventListener('change', () => { if (fileInput.files && fileInput.files[0]) { doSend(fileInput.files[0]); fileInput.value = ''; } });
    compose.addEventListener('submit', (ev) => { ev.preventDefault(); if (input.value.trim()) doSend(null); });
    load().then(() => { pollTimer = setInterval(poll, 4000); });
  }

  // Full-screen "compose a new message" — pick a child, write, optionally attach a photo.
  function openComposeNew() {
    const appMain = document.getElementById('appMain');
    if (!appMain || !state.children.length) return;
    const chipStyle = (active) => `display:inline-flex;align-items:center;padding:7px 14px;border-radius:20px;font-size:13px;font-weight:700;cursor:pointer;border:1.5px solid ${active ? '#159FB4' : 'var(--ink-200)'};background:${active ? '#159FB4' : '#fff'};color:${active ? '#fff' : 'var(--ink-700)'};`;

    const tw = Dom.el('div', { style: 'position:fixed;inset:0;z-index:9600;background:#fff;display:flex;flex-direction:column;animation:kt-screen-in .22s cubic-bezier(.22,.61,.36,1);' });
    const header = Dom.el('div', { style: 'display:flex;align-items:center;gap:8px;padding:calc(env(safe-area-inset-top,0px) + 10px) 12px 12px;border-bottom:1px solid var(--ink-100);flex-shrink:0;' });
    const back = Dom.el('button', { style: 'background:none;border:none;font-size:26px;color:#159FB4;cursor:pointer;padding:0 6px;line-height:1;' }, '‹');
    back.addEventListener('click', () => { if (window.KT && KT.popOverlay) KT.popOverlay(tw); else tw.remove(); });
    header.appendChild(back);
    header.appendChild(Dom.el('div', { style: 'font-weight:800;font-size:15px;color:var(--ink-900);' }, 'New message'));
    tw.appendChild(header);

    const bodyWrap = Dom.el('div', { style: 'flex:1;overflow-y:auto;padding:16px;' });
    tw.appendChild(bodyWrap);

    let chosenChild = state.children.find(c => c.id === state.selectedChildId) || state.children[0];
    if (state.children.length > 1) {
      bodyWrap.appendChild(Dom.el('div', { style: 'font-size:12px;font-weight:800;color:var(--ink-500);text-transform:uppercase;letter-spacing:.4px;margin-bottom:8px;' }, 'About which child?'));
      const chips = Dom.el('div', { style: 'display:flex;flex-wrap:wrap;gap:8px;margin-bottom:18px;' });
      const paint = () => { [].forEach.call(chips.children, (b) => b.setAttribute('style', chipStyle(Number(b.dataset.id) === chosenChild.id))); };
      state.children.forEach(c => {
        const chip = Dom.el('button', { type: 'button', 'data-id': String(c.id), style: chipStyle(c.id === chosenChild.id) }, (c.first_name || c.display_name));
        chip.addEventListener('click', () => { chosenChild = c; paint(); });
        chips.appendChild(chip);
      });
      bodyWrap.appendChild(chips);
    }
    bodyWrap.appendChild(Dom.el('div', { style: 'font-size:12px;color:var(--ink-500);margin-bottom:8px;line-height:1.4;' }, "This goes to your child's room team at the centre."));

    const ta = Dom.el('textarea', { placeholder: 'Write your message…', style: 'width:100%;box-sizing:border-box;min-height:130px;border:1.5px solid var(--ink-200);border-radius:14px;padding:13px 15px;font-size:16px;font-family:inherit;resize:vertical;' });
    bodyWrap.appendChild(ta);

    let pickedFile = null;
    const preview = Dom.el('div', { style: 'margin-top:10px;' });
    const fileInput = Dom.el('input', { type: 'file', accept: 'image/*', style: 'display:none;' });
    const attachRow = Dom.el('button', { type: 'button', style: 'display:inline-flex;align-items:center;gap:8px;margin-top:12px;background:var(--ink-50);border:1px solid var(--ink-200);border-radius:12px;padding:10px 14px;font-size:14px;font-weight:600;color:var(--ink-700);cursor:pointer;' }, [Dom.el('span', {}, '📷'), Dom.el('span', {}, 'Add a photo')]);
    attachRow.addEventListener('click', () => fileInput.click());
    fileInput.addEventListener('change', () => {
      if (fileInput.files && fileInput.files[0]) {
        pickedFile = fileInput.files[0];
        Dom.clear(preview);
        const img = Dom.el('img', { style: 'max-width:170px;border-radius:10px;display:block;margin-top:4px;' });
        img.src = URL.createObjectURL(pickedFile);
        preview.appendChild(img);
        attachRow.lastChild.textContent = 'Change photo';
      }
    });
    bodyWrap.appendChild(attachRow);
    bodyWrap.appendChild(fileInput);
    bodyWrap.appendChild(preview);

    const status = Dom.el('div', { style: 'font-size:13px;color:#B45309;padding:0 16px;min-height:16px;' });
    tw.appendChild(status);
    const send = Dom.el('button', { type: 'button', style: 'margin:8px 16px calc(env(safe-area-inset-bottom,0px) + 14px);border:0;cursor:pointer;padding:15px;border-radius:14px;font-size:16px;font-weight:800;color:#fff;background:#159FB4;flex-shrink:0;' }, 'Send message');
    send.addEventListener('click', async () => {
      if (!ta.value.trim() && !pickedFile) { status.textContent = 'Write a message or add a photo first.'; return; }
      send.disabled = true; send.textContent = 'Sending…'; status.textContent = '';
      try {
        await sendParentMessage(chosenChild.id, ta.value.trim(), pickedFile);
        bust('/parent/messages');
        if (window.KT && KT.popOverlay) KT.popOverlay(tw); else tw.remove();
        if (location.hash === '#messages') Shell.renderScreen(); else location.hash = '#messages';
      } catch (e) {
        send.disabled = false; send.textContent = 'Send message';
        status.textContent = 'Could not send: ' + (e.message || 'please try again.');
      }
    });
    tw.appendChild(send);

    appMain.appendChild(tw);
    if (window.KT && KT.pushOverlay) KT.pushOverlay(tw);
    setTimeout(() => ta.focus(), 120);
  }

  function eventIcon(type) {
    return ({
      'meal': '🍽️', 'snack': '🍎', 'nap_start': '😴', 'nap_end': '🌅',
      'diaper': '👶', 'bathroom': '🚽', 'activity': '✨', 'mood': '😊',
      'note': '📝', 'milestone': '🌟', 'bottle': '🍼',
    })[type] || '•';
  }

  window.KT = window.KT || {};
  window.KT.renderParent = renderParent;
  Shell.registerScreen("guardian:dashboard", renderParent);
  Shell.registerScreen("guardian:today", renderParent);
  Shell.registerScreen("guardian:photos", renderParent);
  Shell.registerScreen("guardian:messages", renderParent);
  Shell.registerScreen("guardian:billing", renderParent);
})(window);
