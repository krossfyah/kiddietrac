/* ═══════════════════════════════════════════════════════════════════
   KiddieTrac — Sales CRM (sales_rep role + superadmin via View-as).
   Platform sales pipeline for selling KiddieTrac to prospective agencies.
   Screens: pipeline (kanban), leads list, new lead, lead detail (activities,
   follow-ups, quotes), follow-ups, plans editor, launch-demo. Backend: /sales/*.

   Banners reuse the shell's CANONICAL hero markup (.kt-hero.kt-hero-auto.
   kt-banner-fx + h1/.kt-hero-sub/.kt-hero-emoji) so they inherit the brand
   gradient, shimmer, alignment and floating emoji — identical to every other
   screen — and survive the self-triggered re-renders in the lead detail.
   Forms are laid out in a nested, width-constrained 2-column grid so they clear
   the portal's global rules (#appMain input caps at 480px; a direct #appMain
   child with an inline max-width is flattened to 100%).
   ═══════════════════════════════════════════════════════════════════ */
(function (window) {
  'use strict';
  var KT = window.KT;
  if (!KT || !KT.Shell || !KT.Shell.registerScreen) return;
  var Api = KT.Api;
  var ACCENT = '#1F6080'; // KiddieTrac brand teal (matches the hero gradient)

  var STAGES = [
    { key: 'new',         label: 'New',           color: '#64748B' },
    { key: 'contacted',   label: 'Contacted',     color: '#0EA5E9' },
    { key: 'qualified',   label: 'Qualified',     color: '#8B5CF6' },
    { key: 'proposal',    label: 'Proposal Sent', color: '#F59E0B' },
    { key: 'negotiation', label: 'Negotiation',   color: '#EC4899' },
    { key: 'won',         label: 'Won',           color: '#10B981' },
    { key: 'lost',        label: 'Lost',          color: '#94A3B8' },
  ];
  var STAGE_LABEL = {}; var STAGE_COLOR = {};
  STAGES.forEach(function (s) { STAGE_LABEL[s.key] = s.label; STAGE_COLOR[s.key] = s.color; });

  var SOURCES = [
    { v: '', l: '— Lead source —' }, { v: 'website', l: 'Website' }, { v: 'referral', l: 'Referral' },
    { v: 'cold', l: 'Cold outreach' }, { v: 'marketing', l: 'Marketing site' }, { v: 'event', l: 'Event / conference' },
    { v: 'social', l: 'Social media' }, { v: 'partner', l: 'Partner' }, { v: 'other', l: 'Other' },
  ];

  // Injected once: the kanban board must FIT the content column (no page-wide
  // horizontal scroll). On desktop the 7 stage columns share the width equally
  // (minmax(0,1fr) keeps min-content at 0 so #appMain — which has min-width:auto —
  // never grows past the viewport). Below 900px it becomes a swipeable strip.
  function ensureSalesCss() {
    if (document.getElementById('kt-sales-css')) return;
    var s = document.createElement('style');
    s.id = 'kt-sales-css';
    s.textContent =
      '.kt-sales-board{display:grid;grid-template-columns:repeat(7,minmax(0,1fr));gap:10px;align-items:start;}' +
      '.kt-sales-col{min-width:0;background:var(--kt-bg,#f4f6f9);border-radius:14px;padding:9px 8px;}' +
      '.kt-sales-col-head{display:flex;align-items:center;justify-content:space-between;gap:4px;margin-bottom:8px;padding:2px 4px;}' +
      '.kt-sales-col-head .t{font-weight:800;font-size:12px;line-height:1.15;min-width:0;overflow-wrap:anywhere;}' +
      '.kt-sales-leadcard{min-width:0;overflow-wrap:anywhere;}' +
      '@media (max-width:900px){.kt-sales-board{grid-template-columns:none;grid-auto-flow:column;grid-auto-columns:minmax(78vw,1fr);overflow-x:auto;padding-bottom:10px;scroll-snap-type:x mandatory;}.kt-sales-col{scroll-snap-align:start;}}';
    document.head.appendChild(s);
  }

  function el(tag, attrs, kids) {
    var e = document.createElement(tag);
    if (attrs) Object.keys(attrs).forEach(function (k) {
      if (k === 'style') e.style.cssText = attrs[k];
      else if (k === 'html') e.innerHTML = attrs[k];
      else if (k.slice(0, 2) === 'on' && typeof attrs[k] === 'function') e.addEventListener(k.slice(2), attrs[k]);
      // Set 'value' as a PROPERTY, not an attribute — textareas ignore the value
      // attribute (their value is text content), so this is the only way the
      // initial value shows for a <textarea> (plan descriptions, notes, etc.).
      else if (k === 'value') e.value = attrs[k];
      else e.setAttribute(k, attrs[k]);
    });
    (kids || []).forEach(function (c) { if (c != null) e.appendChild(typeof c === 'string' ? document.createTextNode(c) : c); });
    return e;
  }
  function esc(s) { return String(s == null ? '' : s).replace(/[&<>"]/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]; }); }
  function clear(n) { while (n.firstChild) n.removeChild(n.firstChild); }
  function toast(i, t, m, c) { try { KT.toast && KT.toast(i, t, m || '', c || ACCENT); } catch (e) {} }
  function go(hash) { window.location.hash = '#' + hash; }
  // A discount line is negative: "−$19.35", never "$-19.35" (2026-10-14).
  function money(v) { if (v == null || v === '') return '—'; var n = Number(v); return (n < 0 ? '−$' : '$') + Math.abs(n).toLocaleString(undefined, { minimumFractionDigits: 0, maximumFractionDigits: 2 }); }
  /* An instant from the server (UTC, "YYYY-MM-DD HH:MM:SS") shown with its time in the
     AGENCY's zone — "Oct 9, 2026, 8:57 AM". (Anthony, 2026-10-09: "when adding notes to
     leads add the timestamp to the notes as well".) */
  function fmtStamp(raw) {
    if (!raw) { return ''; }
    var iso = String(raw).replace(' ', 'T');
    if (!/(Z|[+-]\d{2}:?\d{2})$/.test(iso)) { iso += 'Z'; }
    var dt = new Date(iso);
    if (isNaN(dt.getTime())) { return String(raw); }
    var o = { year: 'numeric', month: 'short', day: 'numeric', hour: 'numeric', minute: '2-digit' };
    try { var z = (window.KT && KT.tz) ? KT.tz() : null; if (z) { o.timeZone = z; } } catch (e) {}
    try { return dt.toLocaleString('en-CA', o); } catch (e) { delete o.timeZone; return dt.toLocaleString('en-CA', o); }
  }
  function fmtDate(s) {
    if (!s) return '';
    // A date-only value never goes through Date(): it renders a day early. Anything
    // carrying a time still does, because that IS an instant.
    if (String(s).length <= 10 && window.KT && KT.dayLabel) { return KT.dayLabel(s); }
    try { return new Date(s).toLocaleDateString(undefined, { month: 'short', day: 'numeric', year: 'numeric' }); } catch (e) { return s; } }
  function todayISO() { return new Date().toISOString().slice(0, 10); }
  function fmtTime(s) { if (!s) return ''; try { var d = new Date(s.replace(' ', 'T')); return d.toLocaleString(undefined, { month: 'short', day: 'numeric', hour: 'numeric', minute: '2-digit' }); } catch (e) { return s; } }
  function me() { try { return JSON.parse(sessionStorage.getItem('kt_user') || '{}'); } catch (e) { return {}; } }
  function salesAuthed() { var r = (me().roles) || []; return r.indexOf('sales_rep') > -1 || r.indexOf('platform_admin') > -1; }
  /* SUPERADMIN, for showing the library at all.
     Read from the cached user because this only decides whether to DRAW something —
     the server decides whether to answer, and it re-reads the live role table. A stale
     cached role here shows a tile that then refuses; it cannot hand anything over. */
  function isSuperAdmin() {
    try {
      var u = me() || {};
      if (u.is_platform_admin) { return true; }
      if (String(u.role_key || u.role || '') === 'platform_admin') { return true; }
      return (u.roles || []).indexOf('platform_admin') !== -1;
    } catch (e) { return false; }
  }

  function guarded(fn) {
    return function (c, ctx) {
      if (!salesAuthed()) { c.innerHTML = ''; c.appendChild(el('div', { style: 'padding:48px 20px;text-align:center;color:#64748B' }, ['🔒 The sales workspace is for the sales team and superadmins.'])); return; }
      return fn(c, ctx);
    };
  }
  function tok() { return sessionStorage.getItem('kt_token') || localStorage.getItem('kt_token'); }
  function apiBase() { return (KT.API_BASE) || 'https://api.kiddietrac.com/api/v1'; }
  function downloadQuotePdf(id) {
    fetch(apiBase() + '/sales/quotes/' + id + '/pdf', { headers: { Authorization: 'Bearer ' + tok() } })
      .then(function (r) { if (!r.ok) throw new Error('HTTP ' + r.status); return r.blob(); })
      .then(function (b) { var u = URL.createObjectURL(b); var a = document.createElement('a'); a.href = u; a.download = 'proposal-' + id + '.pdf'; document.body.appendChild(a); a.click(); a.remove(); setTimeout(function () { URL.revokeObjectURL(u); }, 1500); })
      .catch(function (e) { toast('⚠️', 'PDF failed', e.message || '', '#DC2626'); });
  }

  // ── Banner: the shell's canonical hero component. No inline styling — it inherits
  //    the brand gradient, shimmer, left-aligned text and floating emoji watermark,
  //    so it matches every other screen and re-renders cleanly. Buttons never go
  //    inside the banner (see toolbar()).
  function hero(title, sub, icon) {
    var b = el('div', { class: 'kt-hero kt-hero-auto kt-banner-fx' }, [el('h1', {}, [title])]);
    if (sub) b.appendChild(el('div', { class: 'kt-hero-sub' }, [sub]));
    b.appendChild(el('div', { class: 'kt-hero-emoji', 'aria-hidden': 'true' }, [icon || '✨']));
    return b;
  }
  // A left-aligned action toolbar that sits beneath the banner (mirrors the shell's
  // .kt-page-actions), but self-styled so the primary CTA keeps its emphasis.
  function toolbar(actions) {
    var a = (actions || []).filter(Boolean);
    if (!a.length) return null;
    return el('div', { style: 'display:flex;gap:8px;flex-wrap:wrap;margin:0 0 16px' }, a);
  }
  function btn(label, kind, on) {
    var K = {
      primary: 'background:' + ACCENT + ';color:#fff;border:1px solid ' + ACCENT,
      light:   'background:#fff;color:' + ACCENT + ';border:1px solid #CBD5E1',
      ghost:   'background:#F1F5F9;color:#334155;border:1px solid #E2E8F0',
      danger:  'background:#FEE2E2;color:#B91C1C;border:1px solid #FCA5A5',
    };
    /* ONE SIZE (2026-10-09: "make sure buttons are same size (full sweep)"). Padding alone
       let an emoji in the label make one button taller than its neighbour ("Add" beside
       "📎 Attach …"); a fixed height and a centred row keep every button the same. */
    var b = el('button', { type: 'button', class: 'btn kt-sales-btn', style: (K[kind] || K.light) + ';border-radius:9px;padding:0 15px;font-weight:700;cursor:pointer;font-size:13.5px;line-height:1;'
      + 'height:34px;min-height:34px;display:inline-flex;align-items:center;justify-content:center;gap:6px;white-space:nowrap;box-sizing:border-box;vertical-align:middle', onclick: on }, [label]);
    return b;
  }
  /* The small actions on an activity entry (Edit, mark done, Show transcript, Play,
     Download, Remove): one compact size, so a row of them lines up. */
  function chip(label, on, tone) {
    var c = tone === 'danger' ? 'color:#B91C1C;border-color:#FCA5A5;background:#FFF5F5' : 'color:' + ACCENT + ';border-color:#CBD5E1;background:#fff';
    return el('button', { type: 'button', 'data-kt-inpage': '1', 'data-kt-iconized': '1', class: 'kt-sales-chip',
      style: c + ';border:1px solid;border-radius:999px;height:26px;min-height:26px;padding:0 10px;font:inherit;font-size:12px;font-weight:700;line-height:1;'
        + 'display:inline-flex;align-items:center;gap:5px;white-space:nowrap;cursor:pointer;box-sizing:border-box;vertical-align:middle',
      onclick: function (ev) { ev.preventDefault(); ev.stopPropagation(); on(ev); } }, [label]);
  }
  function field(label, node) {
    return el('div', { style: 'margin-bottom:0' }, [
      el('label', { style: 'display:block;font-size:12px;font-weight:700;color:var(--kt-muted,#5a7080);margin-bottom:5px' }, [label]),
      node,
    ]);
  }
  function input(attrs) { return el('input', Object.assign({ class: 'input', style: 'width:100%;padding:9px 11px;border:1px solid var(--kt-border,#d9e1ea);border-radius:9px;font-size:14px;box-sizing:border-box' }, attrs || {})); }
  function textarea(attrs) { return el('textarea', Object.assign({ class: 'input', style: 'width:100%;padding:9px 11px;border:1px solid var(--kt-border,#d9e1ea);border-radius:9px;font-size:14px;box-sizing:border-box;min-height:76px;resize:vertical' }, attrs || {})); }
  /* A lead's stored source may not be one of SOURCES: the website form writes
     "marketing-site", the chat "website-chat", older leads "manual" or "Cold call". The
     edit dropdown then showed blank and saving any field sent source = null, which the
     server refused (ticket #116). Match case-insensitively, and otherwise add the stored
     value as its own option so it is kept. */
  var SOURCE_NAMES = { 'marketing-site': 'Marketing site form', 'website-chat': 'Website chat', manual: 'Added manually' };
  function sourceValue(v) {
    if (!v) { return ''; }
    var hit = SOURCES.filter(function (o) { return o.v && o.v.toLowerCase() === String(v).toLowerCase(); })[0];
    return hit ? hit.v : v;
  }
  function sourceOptions(v) {
    var sv = sourceValue(v);
    if (!sv || SOURCES.some(function (o) { return o.v === sv; })) { return SOURCES; }
    var label = SOURCE_NAMES[sv] || String(sv).replace(/[-_]+/g, ' ').replace(/^./, function (c) { return c.toUpperCase(); });
    return SOURCES.concat([{ v: sv, l: label }]);
  }

  function selectEl(opts, val, attrs) {
    var s = el('select', Object.assign({ class: 'input select', style: 'width:100%;padding:9px 11px;border:1px solid var(--kt-border,#d9e1ea);border-radius:9px;font-size:14px;box-sizing:border-box' }, attrs || {}));
    opts.forEach(function (o) { var op = el('option', { value: o.v }, [o.l]); if (String(o.v) === String(val)) op.selected = true; s.appendChild(op); });
    return s;
  }
  function stageChip(k) { return el('span', { style: 'display:inline-block;padding:2px 9px;border-radius:999px;font-size:11px;font-weight:800;color:#fff;background:' + (STAGE_COLOR[k] || '#94A3B8') }, [STAGE_LABEL[k] || k]); }
  function card(kids, extra) { return el('div', { style: 'background:var(--kt-card,#fff);border:1px solid var(--kt-border,#e6ebf1);border-radius:16px;padding:18px 20px;box-shadow:0 1px 3px rgba(15,23,42,.05);' + (extra || '') }, kids); }

  // Centred, width-constrained wrapper. Nests ONE level deep so the portal's global
  // `#appMain > div[style*="max-width"] { max-width:100% !important }` polish rule
  // (direct children only) does not flatten it.
  function wrap(kids, maxW) {
    return el('div', {}, [el('div', { style: 'max-width:' + (maxW || 880) + 'px;margin:0 auto' }, kids)]);
  }
  // A tidy 2-column form grid. Columns stay under the 480px input cap, so every input
  // fills its cell and all fields line up. Full-width fields (textareas, action rows)
  // opt in with gridColumn '1 / -1' via fieldWide()/formActions().
  function formGrid(kids) {
    return el('div', { style: 'display:grid;grid-template-columns:repeat(auto-fit,minmax(240px,1fr));gap:14px 18px;align-items:start' }, kids);
  }
  function fieldWide(label, node) { var f = field(label, node); f.style.gridColumn = '1 / -1'; return f; }
  function formActions(kids) { var d = el('div', { style: 'display:flex;gap:8px;margin-top:2px' }, kids); d.style.gridColumn = '1 / -1'; return d; }
  // A titled section rendered as its own soft panel, with a responsive field grid —
  // lays a wide form out across the full width in tidy, clearly grouped cards.
  function formSection(title, icon, kids) {
    return el('div', { style: 'background:#FAFBFD;border:1px solid #EAF0F5;border-radius:14px;padding:15px 17px 17px;margin-bottom:14px' }, [
      el('div', { style: 'font-size:11.5px;font-weight:800;letter-spacing:.5px;text-transform:uppercase;color:#0C6070;margin:0 0 13px;display:flex;align-items:center;gap:6px' }, [(icon || '') + ' ' + title]),
      el('div', { style: 'display:grid;grid-template-columns:repeat(auto-fit,minmax(210px,1fr));gap:14px 18px;align-items:start' }, kids),
    ]);
  }
  // Free-text address autocomplete via Photon (Komoot's OSM geocoder — free, no key,
  // CORS-enabled). Suggests as you type and fills city / province / postal / country.
  function attachAddressAutocomplete(inp, fields) {
    var box = null, timer = null, seq = 0;
    inp.setAttribute('autocomplete', 'off');
    function closeBox() { if (box) { box.remove(); box = null; } }
    function line1(p) { return [p.housenumber, p.street || p.name].filter(Boolean).join(' '); }
    function pick(p) {
      inp.value = line1(p) || (p.name || inp.value);
      if (fields.city && p.city) fields.city.value = p.city;
      if (fields.province && p.state) fields.province.value = p.state;
      if (fields.postal_code && p.postcode) fields.postal_code.value = p.postcode;
      if (fields.country && p.country) fields.country.value = p.country;
      closeBox();
    }
    function show(feats) {
      closeBox();
      feats = (feats || []).filter(function (f) { return line1(f.properties || {}) || (f.properties || {}).name; });
      if (!feats.length) return;
      box = el('div', { style: 'position:absolute;z-index:9500;left:0;right:0;top:100%;margin-top:3px;background:#fff;border:1px solid #d9e1ea;border-radius:10px;box-shadow:0 12px 32px rgba(15,23,42,.16);max-height:262px;overflow:auto' });
      feats.slice(0, 6).forEach(function (f) {
        var p = f.properties || {};
        box.appendChild(el('div', { style: 'padding:9px 12px;cursor:pointer;border-bottom:1px solid #f1f5f9', onmouseover: function () { this.style.background = '#F1F5F9'; }, onmouseout: function () { this.style.background = ''; }, onmousedown: function (ev) { ev.preventDefault(); pick(p); } }, [
          el('div', { style: 'font-weight:600;font-size:13px;color:#0D1B2A' }, [line1(p) || p.name || '']),
          el('div', { style: 'font-size:11.5px;color:#64748B;margin-top:1px' }, [[p.city, p.state, p.postcode, p.country].filter(Boolean).join(', ')]),
        ]));
      });
      var host = inp.parentElement; host.style.position = 'relative'; host.appendChild(box);
    }
    inp.addEventListener('input', function () {
      var q = inp.value.trim(); clearTimeout(timer);
      if (q.length < 3) { closeBox(); return; }
      timer = setTimeout(function () {
        var rid = ++seq;
        fetch('https://photon.komoot.io/api/?q=' + encodeURIComponent(q) + '&limit=6&lang=en')
          .then(function (r) { return r.json(); })
          .then(function (d) { if (rid === seq) show(d.features || []); })
          .catch(function () {});
      }, 280);
    });
    inp.addEventListener('blur', function () { setTimeout(closeBox, 200); });
  }

  var META = null; // {owners, products, stats}
  function ownerOpts() { return [{ v: '', l: 'Unassigned' }].concat((META && META.owners || []).map(function (o) { return { v: o.id, l: o.name }; })); }

  // ─────────────────────────────── Pipeline (kanban) ───────────────────────────────
  async function renderPipeline(container) {
    clear(container);
    ensureSalesCss();
    container.appendChild(hero('Sales pipeline', 'Track prospects from first contact to won.', '💼'));
    container.appendChild(toolbar([
      btn('➕ New lead', 'primary', function () { go('sales-new'); }),
    ]));
    var loading = el('div', { style: 'padding:30px;text-align:center;color:#64748B' }, ['Loading pipeline…']);
    container.appendChild(loading);
    var data;
    try { data = await Api.get('/sales/leads'); } catch (e) { loading.remove(); container.appendChild(card([el('div', { style: 'color:#DC2626' }, ['Could not load leads: ' + (e.message || e)])])); return; }
    META = { owners: data.owners || [], products: data.products || [], stats: data.stats || {} };
    loading.remove();

    var st = data.stats || {};
    var statRow = el('div', { style: 'display:grid;grid-template-columns:repeat(auto-fit,minmax(130px,1fr));gap:12px;margin-bottom:12px' }, [
      statCard('🎯', 'Open leads', st.open || 0, '#EEF2FF', '#4338CA'),
      statCard('💰', 'Pipeline value', money(st.pipeline_value || 0), '#ECFDF5', '#047857'),
      statCard('🏆', 'Won', st.won || 0, '#F0FDF4', '#15803D'),
      statCard('⏰', 'Follow-ups due', st.followups_due || 0, '#FFF7ED', '#C2410C'),
      statCard('🙋', 'My open', st.my_open || 0, '#FDF2F8', '#BE185D'),
    ]);
    container.appendChild(statRow);

    // Win/loss analytics — conversion at a glance.
    var closed = (st.won || 0) + (st.lost || 0);
    var analytics = el('div', { style: 'display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:12px;margin-bottom:18px' }, [
      winCard('Win rate', (st.win_rate || 0) + '%', (st.won || 0) + ' won · ' + (st.lost || 0) + ' lost', st.win_rate || 0),
      statCard('📈', 'Won revenue', money(st.won_value || 0), '#ECFEFF', '#0E7490'),
      statCard('🧾', 'Avg won deal', money(st.avg_won || 0), '#F5F3FF', '#6D28D9'),
      statCard('❌', 'Lost', st.lost || 0, '#FEF2F2', '#B91C1C'),
      statCard('📊', 'Closed deals', closed, '#F8FAFC', '#475569'),
    ]);
    container.appendChild(analytics);

    var board = el('div', { class: 'kt-sales-board' });
    var byStage = {}; STAGES.forEach(function (s) { byStage[s.key] = []; });
    (data.leads || []).forEach(function (l) { (byStage[l.stage] || (byStage[l.stage] = [])).push(l); });
    STAGES.forEach(function (s) {
      var leads = byStage[s.key] || [];
      var col = el('div', { class: 'kt-sales-col' }, [
        el('div', { class: 'kt-sales-col-head' }, [
          el('span', { class: 't', style: 'color:' + s.color }, [s.label]),
          el('span', { style: 'font-size:12px;font-weight:700;color:#64748B;flex:0 0 auto' }, [String(leads.length)]),
        ]),
      ]);
      if (!leads.length) col.appendChild(el('div', { style: 'padding:14px;text-align:center;color:#94A3B8;font-size:12px' }, ['—']));
      leads.forEach(function (l) { col.appendChild(leadCard(l)); });
      board.appendChild(col);
    });
    container.appendChild(board);
  }
  function statCard(icon, label, val, tint, ink) {
    return el('div', { style: 'background:' + tint + ';border:1px solid ' + ink + '22;border-radius:14px;padding:13px 14px' }, [
      el('div', { style: 'font-size:16px' }, [icon]),
      el('div', { style: 'font-size:20px;font-weight:800;color:' + ink + ';line-height:1.1;margin-top:5px' }, [String(val)]),
      el('div', { style: 'font-size:11.5px;color:' + ink + ';opacity:.85;font-weight:600;margin-top:2px' }, [label]),
    ]);
  }
  function winCard(label, val, sub, pct) {
    var p = Math.max(0, Math.min(100, Number(pct) || 0));
    var ink = p >= 50 ? '#15803D' : (p > 0 ? '#B45309' : '#64748B');
    return el('div', { style: 'background:#F0FDF4;border:1px solid ' + ink + '22;border-radius:14px;padding:13px 14px' }, [
      el('div', { style: 'font-size:16px' }, ['🎉']),
      el('div', { style: 'font-size:20px;font-weight:800;color:' + ink + ';line-height:1.1;margin-top:5px' }, [String(val)]),
      el('div', { style: 'font-size:11.5px;color:' + ink + ';opacity:.85;font-weight:600;margin-top:2px' }, [label]),
      el('div', { style: 'height:5px;background:#DCFCE7;border-radius:999px;margin-top:8px;overflow:hidden' }, [
        el('div', { style: 'height:100%;width:' + p + '%;background:' + ink + ';border-radius:999px' }),
      ]),
      el('div', { style: 'font-size:10.5px;color:#64748B;margin-top:4px' }, [sub || '']),
    ]);
  }
  function leadCard(l) {
    var due = l.follow_up_date && l.follow_up_date <= todayISO();
    var c = el('div', { class: 'kt-sales-leadcard', style: 'background:var(--kt-card,#fff);border:1px solid var(--kt-border,#e6ebf1);border-left:3px solid ' + (STAGE_COLOR[l.stage] || '#94A3B8') + ';border-radius:11px;padding:10px 11px;margin-bottom:8px;cursor:pointer;box-shadow:0 1px 2px rgba(15,23,42,.05)', onclick: function () { go('sales-lead?id=' + l.id); } }, [
      el('div', { style: 'font-weight:800;font-size:13.5px;color:var(--kt-ink,#0D1B2A)' }, [l.company || l.name || 'Lead #' + l.id]),
      l.company && l.name ? el('div', { style: 'font-size:12px;color:#64748B' }, [l.name]) : null,
      el('div', { style: 'display:flex;gap:8px;align-items:center;margin-top:6px;flex-wrap:wrap' }, [
        l.value != null ? el('span', { style: 'font-size:12px;font-weight:700;color:#047857' }, [money(l.value)]) : null,
        l.owner_name ? el('span', { style: 'font-size:11px;color:#64748B' }, ['· ' + l.owner_name]) : null,
        due ? el('span', { style: 'font-size:11px;font-weight:700;color:#C2410C;background:#FFF7ED;border-radius:999px;padding:1px 7px' }, ['⏰ ' + fmtDate(l.follow_up_date)]) : null,
      ]),
    ]);
    return c;
  }

  // ─────────────────────────────── Leads list ───────────────────────────────
  async function renderList(container) {
    clear(container);
    container.appendChild(hero('Leads', 'Every prospect in the pipeline.', '🎯'));
    container.appendChild(toolbar([
      btn('➕ New lead', 'primary', function () { go('sales-new'); }),
    ]));
    var search = input({ type: 'search', placeholder: '🔍 Search name, company, email…', style: 'width:100%;max-width:340px;padding:9px 11px;border:1px solid #d9e1ea;border-radius:9px;font-size:14px;box-sizing:border-box' });
    container.appendChild(el('div', { style: 'margin-bottom:12px' }, [search]));
    var host = el('div'); container.appendChild(host);
    async function load() {
      clear(host); host.appendChild(el('div', { style: 'padding:20px;color:#64748B' }, ['Loading…']));
      var data = await Api.get('/sales/leads', search.value ? { q: search.value } : {});
      META = { owners: data.owners || [], products: data.products || [], stats: data.stats || {} };
      clear(host);
      if (!data.leads || !data.leads.length) { host.appendChild(card([el('div', { style: 'text-align:center;color:#64748B;padding:20px' }, ['No leads yet. ', el('a', { href: '#sales-new', style: 'color:' + ACCENT + ';font-weight:700' }, ['Add one →'])])])); return; }
      /* data-kt-filtered opts this table OUT of the GLOBAL auto-search that
         kt-table-filter.js attaches to every #appMain table. Without it the screen
         showed TWO search boxes doing the same job (Anthony, 2026-08-31: "two filters
         that looks to be doing the same job") — and the two do not even agree: the
         box above searches the SERVER across name, company and email, including
         fields this table does not show, while the global one hides rows already on
         screen. The server search is the better answer, so the global one goes. Same
         fix as Announcements, which had the identical clash. */
      var tbl = el('table', { 'data-kt-filtered': '1', style: 'width:100%;border-collapse:collapse;background:#fff;border:1px solid #e6ebf1;border-radius:12px;overflow:hidden' });
      tbl.appendChild(el('thead', {}, [el('tr', { style: 'background:#f4f6f9;text-align:left' }, ['Company / contact', 'Stage', 'Value', 'Owner', 'Follow-up', ''].map(function (h) { return el('th', { style: 'padding:10px 12px;font-size:12px;color:#5a7080' }, [h]); }))]));
      var tb = el('tbody');
      data.leads.forEach(function (l) {
        tb.appendChild(el('tr', { style: 'border-top:1px solid #eef1f5;cursor:pointer', onclick: function () { go('sales-lead?id=' + l.id); } }, [
          el('td', { style: 'padding:10px 12px' }, [el('div', { style: 'font-weight:700' }, [l.company || l.name || '—']), l.company && l.name ? el('div', { style: 'font-size:12px;color:#64748B' }, [l.name]) : null]),
          el('td', { style: 'padding:10px 12px' }, [stageChip(l.stage)]),
          el('td', { style: 'padding:10px 12px;font-weight:700;color:#047857' }, [money(l.value)]),
          el('td', { style: 'padding:10px 12px;font-size:13px' }, [l.owner_name || '—']),
          el('td', { style: 'padding:10px 12px;font-size:13px;color:' + (l.follow_up_date && l.follow_up_date <= todayISO() ? '#C2410C' : '#64748B') }, [l.follow_up_date ? fmtDate(l.follow_up_date) : '—']),
          /* ROW ACTIONS (Anthony, 2026-10-06: "leads section add kebab to view, edit,
             delete"). Plain labelled buttons in the last cell; kt-row-actions.js turns
             them into the portal's ⋮ menu (phones keep them as buttons). stopPropagation
             so an action does not also fire the row's open-on-click. */
          el('td', { style: 'padding:6px 12px;text-align:center;white-space:nowrap' }, [
            el('button', { type: 'button', title: 'View', onclick: function (ev) { ev.stopPropagation(); go('sales-lead?id=' + l.id); } }, ['👁 View']),
            el('button', { type: 'button', title: 'Edit', onclick: function (ev) { ev.stopPropagation(); go('sales-lead?id=' + l.id + '&edit=1'); } }, ['✏️ Edit']),
            el('button', { type: 'button', title: 'Delete', onclick: async function (ev) {
              ev.stopPropagation();
              var label = l.company || l.name || ('Lead #' + l.id);
              var ok = (window.KT && KT.confirm)
                ? await KT.confirm({ title: 'Delete this lead?', description: '“' + label + '” will be removed from the pipeline, with its activity and proposals.', okLabel: 'Delete', tone: 'danger' })
                : false;
              if (!ok) return;
              try { await Api.delete('/sales/leads/' + l.id); toast('🗑', 'Deleted', label); load(); }
              catch (er) { toast('⚠️', 'Could not delete', er.message || '', '#DC2626'); }
            } }, ['🗑 Delete']),
          ]),
        ]));
      });
      tbl.appendChild(tb); host.appendChild(el('div', { style: 'overflow-x:auto' }, [tbl]));
    }
    var t; search.addEventListener('input', function () { clearTimeout(t); t = setTimeout(load, 300); });
    load();
  }

  // ─────────────────────────────── New lead ───────────────────────────────
  async function renderNew(container) {
    clear(container);
    if (!META) { try { var d = await Api.get('/sales/leads'); META = { owners: d.owners || [], products: d.products || [] }; } catch (e) { META = { owners: [] }; } }
    container.appendChild(hero('New lead', 'Add a prospect to the pipeline.', '➕'));
    container.appendChild(toolbar([btn('← Pipeline', 'light', function () { go('sales'); })]));
    var f = {};
    var myId = (function () { try { return (JSON.parse(sessionStorage.getItem('kt_user') || '{}').id) || ''; } catch (e) { return ''; } })();
    var inner = el('div', {}, [
      formSection('Primary contact', '👤', [
        field('Contact name *', f.name = input({ placeholder: 'Jane Doe' })),
        field('Job title', f.title = input({ placeholder: 'Director / Manager' })),
        field('Email', f.email = input({ type: 'email', placeholder: 'jane@example.com' })),
        field('Phone', f.phone = input({ placeholder: '(555) 123-4567' })),
      ]),
      formSection('Business owner / decision maker', '🧑‍💼', [
        field('Owner name', f.owner_name = input({ placeholder: 'Owner / principal' })),
        field('Owner title', f.owner_title = input({ placeholder: 'Owner, CEO…' })),
        field('Owner email', f.owner_email = input({ type: 'email', placeholder: 'owner@example.com' })),
        field('Owner phone', f.owner_phone = input({ placeholder: '(555) 987-6543' })),
      ]),
      formSection('Business', '🏢', [
        field('Company / childcare', f.company = input({ placeholder: 'Sunshine Daycare' })),
        field('Website', f.website = input({ placeholder: 'sunshinedaycare.com' })),
        fieldWide('Street address', f.address = input({ placeholder: 'Start typing an address…' })),
        field('City', f.city = input({ placeholder: 'Toronto' })),
        field('Province / state', f.province = input({ placeholder: 'ON' })),
        field('Postal / ZIP', f.postal_code = input({ placeholder: 'M5V 2T6' })),
        field('Country', f.country = input({ placeholder: 'Canada' })),
      ]),
      formSection('What they use today', '🧩', [
        field('Current software / solution', f.current_solution = input({ placeholder: 'e.g. HiMama, spreadsheets, paper' })),
        field('# children', f.num_children = input({ type: 'number', min: '0', placeholder: '0' })),
        field('# locations / centres', f.num_locations = input({ type: 'number', min: '0', placeholder: '1' })),
        field('Lead source', f.source = selectEl(SOURCES, '')),
      ]),
      formSection('Pipeline', '📊', [
        field('Stage', f.stage = selectEl(STAGES.map(function (s) { return { v: s.key, l: s.label }; }), 'new')),
        field('Owner (rep)', f.owner_id = selectEl(ownerOpts(), myId)),
        field('Est. value ($)', f.value = input({ type: 'number', step: '0.01', min: '0', placeholder: '0.00' })),
        field('Expected close', f.expected_close = input({ type: 'date' })),
        field('Next follow-up', f.follow_up_date = input({ type: 'date' })),
      ]),
      el('div', { style: 'margin-top:14px' }, [field('Notes', f.notes = textarea({ placeholder: 'What are they looking for? Pain points, timeline, decision makers…', style: 'width:100%;padding:9px 11px;border:1px solid var(--kt-border,#d9e1ea);border-radius:9px;font-size:14px;box-sizing:border-box;min-height:96px;resize:vertical' }))]),
      el('div', { style: 'display:flex;gap:8px;margin-top:16px' }, [
        btn('Create lead', 'primary', async function (ev) {
          if (!f.name.value.trim()) { toast('⚠️', 'Name required', '', '#DC2626'); return; }
          ev.target.disabled = true; ev.target.textContent = 'Saving…';
          try {
            var body = {
              name: f.name.value.trim(), title: f.title.value.trim(), company: f.company.value.trim(),
              email: f.email.value.trim(), phone: f.phone.value.trim(), website: f.website.value.trim(),
              address: f.address.value.trim(), city: f.city.value.trim(), province: f.province.value.trim(),
              postal_code: f.postal_code.value.trim(), country: f.country.value.trim(),
              current_solution: f.current_solution.value.trim(),
              owner_name: f.owner_name.value.trim(), owner_title: f.owner_title.value.trim(),
              owner_email: f.owner_email.value.trim(), owner_phone: f.owner_phone.value.trim(),
              num_children: f.num_children.value || null, num_locations: f.num_locations.value || null,
              source: f.source.value || null, stage: f.stage.value, owner_id: f.owner_id.value || null,
              value: f.value.value || null, expected_close: f.expected_close.value || null,
              follow_up_date: f.follow_up_date.value || null, notes: f.notes.value.trim(),
            };
            var lead = await Api.post('/sales/leads', body);
            toast('✅', 'Lead created', 'Superadmin + sales team notified.');
            go('sales-lead?id=' + lead.id);
          } catch (e) { toast('⚠️', 'Save failed', e.message || '', '#DC2626'); ev.target.disabled = false; ev.target.textContent = 'Create lead'; }
        }),
        btn('Cancel', 'ghost', function () { go('sales'); }),
      ]),
    ]);
    // Full-width card (no narrow wrap); wrapped in a real <form> so kt-icon-buttons.js
    // skips the buttons and the teal primary "Create lead" is preserved.
    var formEl = el('form', { onsubmit: function (ev) { ev.preventDefault(); }, style: 'max-width:none !important;width:100%' }, [inner]);
    container.appendChild(formEl);
    // Type-ahead address suggestions → auto-fill city / province / postal / country.
    attachAddressAutocomplete(f.address, { city: f.city, province: f.province, postal_code: f.postal_code, country: f.country });
  }

  // Render an activity's body — multi-line "Field: old → new" change logs (type 'edit'
  // or quote edits) become a small list with bold field names; everything else is plain.
  function activityBodyNode(a) {
    if (!a.body) return null;
    var lines = String(a.body).split('\n');
    if (lines.length === 1 && a.type !== 'edit') {
      return el('div', { style: 'font-size:13px;color:#0D1B2A;margin-top:2px' }, [a.body]);
    }
    if (a.type !== 'edit') {
      /* Prose of more than one line — a meeting's notes or its transcript — reads as text,
         and anything long folds after 12 lines with Show more. */
      var LIMIT = 12, longOne = lines.length > LIMIT;
      var txt = el('div', { style: 'margin-top:3px;font-size:13px;line-height:1.55;color:#0D1B2A;white-space:pre-wrap;overflow-wrap:anywhere' },
        [longOne ? lines.slice(0, LIMIT).join('\n') : a.body]);
      if (!longOne) return txt;
      var wrapBox = el('div', {}, [txt]);
      var shown = false;
      var more = chip('Show more (' + (lines.length - LIMIT) + ' more lines)', function () {
        shown = !shown;
        txt.textContent = shown ? a.body : lines.slice(0, LIMIT).join('\n');
        more.textContent = shown ? 'Show less' : 'Show more (' + (lines.length - LIMIT) + ' more lines)';
      });
      wrapBox.appendChild(el('div', { style: 'margin-top:6px' }, [more]));
      return wrapBox;
    }
    var box = el('div', { style: 'margin-top:3px;display:flex;flex-direction:column;gap:1px' });
    lines.forEach(function (ln) {
      var idx = ln.indexOf(': ');
      if (idx > 0 && ln.indexOf(' → ') > -1) {
        box.appendChild(el('div', { style: 'font-size:12.5px;color:#0D1B2A' }, [
          el('span', { style: 'font-weight:700;color:#334155' }, [ln.slice(0, idx)]), ' ',
          ln.slice(idx + 2),
        ]));
      } else {
        box.appendChild(el('div', { style: 'font-size:13px;color:#0D1B2A' + (lines.length > 1 ? ';font-weight:700' : '') }, [ln]));
      }
    });
    return box;
  }

  /* ── Files on an activity: Teams transcripts and recordings (2026-10-09) ──────── */
  var ACT_FILE_ACCEPT = '.vtt,.docx,.txt,.srt,.mp4,.mov,.m4v,.webm,.mkv,.m4a,.mp3,.wav,.aac,.ogg,.pdf,.pptx,.xlsx,.csv,.png,.jpg,.jpeg';
  var CHUNK = 8 * 1024 * 1024;   // under the host's 64 MB request limit with room to spare
  /* The portal caps textareas (width and height, with !important) for ordinary forms; a
     meeting note is not an ordinary form field. Full card width, a tall start, and the
     user can still drag it taller. */
  function bigBox(t, h) {
    t.style.setProperty('width', '100%', 'important');
    t.style.setProperty('max-width', 'none', 'important');
    t.style.setProperty('min-height', h + 'px', 'important');
    t.style.setProperty('max-height', 'none', 'important');
    t.style.setProperty('resize', 'vertical', 'important');
    t.style.setProperty('box-sizing', 'border-box', 'important');
  }
  function fmtBytes(n) { n = Number(n || 0); return n >= 1073741824 ? (n / 1073741824).toFixed(2) + ' GB' : n >= 1048576 ? (n / 1048576).toFixed(1) + ' MB' : Math.max(1, Math.round(n / 1024)) + ' KB'; }
  function fileIcon(name) {
    var e = String(name || '').split('.').pop().toLowerCase();
    if (/^(vtt|srt|txt|docx)$/.test(e)) return '📝';
    if (/^(mp4|mov|m4v|webm|mkv)$/.test(e)) return '🎬';
    if (/^(m4a|mp3|wav|aac|ogg)$/.test(e)) return '🎧';
    return '📄';
  }
  /* Raw-body chunks (not FormData), so kt-upload-progress does not open a popup per piece;
     one progress job covers the whole file. Each piece is retried twice on a network error. */
  async function uploadActivityFiles(activityId, files) {
    var base = (window.KT && KT.API_BASE) || 'https://api.kiddietrac.com/api/v1';
    var token = sessionStorage.getItem('kt_token') || localStorage.getItem('kt_token');
    var agency = ''; try { agency = sessionStorage.getItem('kt_active_agency_id') || ''; } catch (e) {}
    var UP = window.KT && KT.uploadProgress;
    for (var fi = 0; fi < files.length; fi++) {
      var f = files[fi];
      var total = Math.max(1, Math.ceil(f.size / CHUNK));
      var uid = (Date.now().toString(36) + Math.random().toString(36).slice(2, 10)).slice(0, 24);
      var job = UP ? UP.start(f.name, f.size) : null;
      try {
        for (var i = 0; i < total; i++) {
          var blob = f.slice(i * CHUNK, Math.min(f.size, (i + 1) * CHUNK));
          var qs = 'upload_id=' + uid + '&index=' + i + '&total=' + total + '&size=' + f.size + '&name=' + encodeURIComponent(f.name);
          var res = null, lastErr = null;
          for (var attempt = 0; attempt < 3 && !res; attempt++) {
            try {
              res = await fetch(base + '/sales/activities/' + activityId + '/upload?' + qs, {
                method: 'POST', body: blob,
                headers: { 'Authorization': 'Bearer ' + token, 'X-Active-Agency-Id': agency, 'Content-Type': 'application/octet-stream', 'Accept': 'application/json' },
              });
            } catch (e) { lastErr = e; res = null; }
          }
          if (!res) { throw new Error('Network error uploading ' + f.name + (lastErr && lastErr.message ? ' (' + lastErr.message + ')' : '')); }
          if (!res.ok) {
            var msg = 'HTTP ' + res.status; try { msg = (await res.json()).message || msg; } catch (e) {}
            throw new Error(f.name + ': ' + msg);
          }
          if (UP && job) { UP.set(job, Math.min(f.size, (i + 1) * CHUNK), f.size); }
        }
        if (UP && job) { UP.done(job, true); }
      } catch (e) { if (UP && job) { UP.done(job, false); } throw e; }
    }
  }
  /* THE LEAD'S DOCUMENTS (2026-10-09, Anthony: "make sure its also attached to the lead
     record and filed away in documents once signed. add preview to the kebab"). One place
     for everything filed on this lead: its NDAs (status, preview, the signed copy) and every
     file attached to its activities (transcripts, recordings, the signed NDA PDF). */
  function leadFiles(lead) {
    var out = [];
    (lead.activities || []).forEach(function (a) { (a.files || []).forEach(function (f) { out.push({ f: f, a: a }); }); });
    return out;
  }
  function renderLeadDocuments(id, lead) {
    var box = el('div', { style: 'display:flex;flex-direction:column;gap:14px' });
    var ndaBox = el('div', {}, [el('div', { style: 'font-weight:800;margin-bottom:6px' }, ['🤝 NDAs']), el('div', { style: 'color:#94A3B8;font-size:13px' }, ['Loading…'])]);
    box.appendChild(ndaBox);
    Api.get('/admin/prospect-ndas').then(function (d) {
      var list = (d.ndas || []).filter(function (n) { return +n.lead_id === +id; });
      clear(ndaBox);
      ndaBox.appendChild(el('div', { style: 'display:flex;align-items:center;gap:8px;margin-bottom:6px' }, [
        el('div', { style: 'font-weight:800' }, ['🤝 NDAs']),
        el('a', { href: '#prospect-ndas', style: 'margin-left:auto;font-size:13px;font-weight:700;color:#1F6080;text-decoration:none' }, ['Send an NDA →'])]));
      if (!list.length) { ndaBox.appendChild(el('div', { style: 'color:#94A3B8;font-size:13px' }, ['No NDA sent for this lead yet.'])); return; }
      list.forEach(function (n) {
        var tone = n.status === 'signed' ? ['#ECFDF5', '#047857', '✅ Signed'] : n.status === 'expired' ? ['#FFFBEB', '#B45309', 'Link expired'] : n.status === 'cancelled' ? ['#F1F5F9', '#64748B', 'Cancelled'] : ['#EFF6FF', '#1D4ED8', 'Waiting for signature'];
        var row = el('div', { style: 'border:1px solid #E2E8F0;border-radius:10px;padding:10px 12px;background:#FAFBFD;display:flex;align-items:center;gap:10px;flex-wrap:wrap' }, [
          el('div', { style: 'flex:1;min-width:220px' }, [
            el('div', { style: 'font-weight:700;color:#0F172A' }, ['KiddieTrac NDA — ' + (n.business_name || n.company || n.name)]),
            el('div', { style: 'font-size:12.5px;color:#64748B' }, [(n.signer_name ? 'Signed by ' + n.signer_name + (n.role_title ? ', ' + n.role_title : '') : 'Sent to ' + n.name + ' <' + n.email + '>')
              + (n.business_address ? ' · ' + String(n.business_address).replace(/\s+/g, ' ') : '')])]),
          el('span', { style: 'padding:2px 9px;border-radius:999px;font-size:12px;font-weight:700;background:' + tone[0] + ';color:' + tone[1] }, [tone[2]]),
        ]);
        row.appendChild(chip(n.status === 'signed' ? '📄 View signed PDF' : '👁 Preview NDA', function () {
          if (KT.openNdaPdf) KT.openNdaPdf(n, n.status === 'signed' ? 'pdf' : 'preview'); else location.hash = '#prospect-ndas';
        }));
        ndaBox.appendChild(row);
      });
    }).catch(function () { clear(ndaBox); ndaBox.appendChild(el('div', { style: 'color:#94A3B8;font-size:13px' }, ['NDAs could not be loaded.'])); });

    var files = leadFiles(lead);
    var fileBox = el('div', {}, [el('div', { style: 'font-weight:800;margin-bottom:6px' }, ['📎 Files on this lead'])]);
    if (!files.length) fileBox.appendChild(el('div', { style: 'color:#94A3B8;font-size:13px' }, ['No files yet. Files attached to an activity (transcripts, recordings, signed NDAs) are listed here.']));
    files.forEach(function (x) {
      var f = x.f;
      var row = el('div', { style: 'border:1px solid #E2E8F0;border-radius:10px;padding:8px 10px;background:#FAFBFD;display:flex;align-items:center;gap:8px;flex-wrap:wrap;font-size:13px' }, [
        el('span', { style: 'font-weight:700;color:#0F172A' }, [fileIcon(f.name) + ' ' + (f.kind === 'transcript' ? 'Transcript' : f.kind === 'video' ? 'Recording' : f.kind === 'audio' ? 'Audio' : f.kind === 'document' ? 'Document' : 'File')]),
        el('span', { style: 'color:#475569;overflow-wrap:anywhere' }, [f.name]),
        el('span', { style: 'color:#94A3B8' }, [fmtBytes(f.size)]),
      ]);
      if (f.url) {
        row.appendChild(chip('👁 View', function () { if (window.KT && KT.openFile) { KT.openFile(f.url, { title: f.name, filename: f.name, label: 'Lead file' }); } else { window.open(f.url, '_blank', 'noopener'); } }));
        row.appendChild(chip('⬇️ Download', function () {
          var a2 = document.createElement('a'); a2.href = f.url; a2.target = '_blank'; a2.rel = 'noopener'; a2.download = f.name;
          document.body.appendChild(a2); a2.click(); a2.remove();
        }));
      }
      fileBox.appendChild(row);
    });
    box.appendChild(fileBox);
    return box;
  }

  function activityFilesNode(a, refresh) {
    var files = a.files || [];
    if (!files.length) return null;
    var box = el('div', { style: 'margin-top:8px;display:flex;flex-direction:column;gap:6px' });
    files.forEach(function (f) {
      var row = el('div', { style: 'border:1px solid #E2E8F0;border-radius:10px;padding:8px 10px;background:#FAFBFD' });
      var head = el('div', { style: 'display:flex;align-items:center;gap:8px;flex-wrap:wrap;font-size:13px' }, [
        el('span', { style: 'font-weight:700;color:#0F172A' }, [fileIcon(f.name) + ' ' + (f.kind === 'transcript' ? 'Transcript' : f.kind === 'video' ? 'Recording' : f.kind === 'audio' ? 'Audio' : f.kind === 'document' ? 'Document' : 'File')]),
        el('span', { style: 'color:#475569;overflow-wrap:anywhere' }, [f.name]),
        el('span', { style: 'color:#94A3B8' }, [fmtBytes(f.size)]),
      ]);
      row.appendChild(head);
      var extra = el('div', {});
      var open = false;
      function toggle(build) { return function () { open = !open; clear(extra); if (open) { extra.appendChild(build()); } }; }
      if (f.kind === 'transcript' && f.transcript) {
        head.appendChild(chip('Show transcript', toggle(function () {
          return el('div', { style: 'margin-top:8px;max-height:420px;overflow:auto;white-space:pre-wrap;font-size:13px;line-height:1.55;color:#1E293B;background:#fff;border:1px solid #E2E8F0;border-radius:8px;padding:10px 12px' }, [f.transcript]);
        })));
      }
      if (f.kind === 'video' || f.kind === 'audio') {
        head.appendChild(chip('▶ Play', toggle(function () {
          return el(f.kind === 'video' ? 'video' : 'audio', { controls: 'controls', preload: 'metadata', src: f.url, style: 'margin-top:8px;width:100%;max-width:880px;border-radius:8px;background:#000' });
        })));
      }
      if (f.url) {
        head.appendChild(chip('⬇️ Download', function () {
          var a2 = document.createElement('a'); a2.href = f.url; a2.target = '_blank'; a2.rel = 'noopener'; a2.download = f.name;
          document.body.appendChild(a2); a2.click(); a2.remove();
        }));
      }
      if (a.can_edit) {
        var rm = chip('Remove', async function () {
          var ok = (window.KT && KT.confirm) ? await KT.confirm({ title: 'Remove this file?', description: '“' + f.name + '” will be deleted from this entry.', okLabel: 'Remove', tone: 'danger' }) : false;
          if (!ok) return;
          try { await Api.delete('/sales/activity-files/' + f.id); toast('🗑', 'Removed', f.name); refresh(); }
          catch (er) { toast('⚠️', 'Could not remove', er.message || '', '#DC2626'); }
        }, 'danger');
        rm.style.marginLeft = 'auto';
        head.appendChild(rm);
      }
      row.appendChild(extra);
      box.appendChild(row);
    });
    return box;
  }

  // ─────────────────────────────── Lead detail ───────────────────────────────
  async function renderDetail(container, ctx) {
    clear(container);
    var id = (ctx && ctx.params && ctx.params.id) || (window.location.hash.split('id=')[1] || '').split('&')[0];
    if (!id) { go('sales'); return; }
    container.appendChild(el('div', { style: 'padding:20px;color:#64748B' }, ['Loading lead…']));
    var lead;
    try { lead = await Api.get('/sales/leads/' + id); } catch (e) { clear(container); container.appendChild(hero('Lead', '', '🏢')); container.appendChild(card([el('div', { style: 'color:#DC2626' }, ['Lead not found.'])])); return; }
    if (!META) { try { var d = await Api.get('/sales/leads'); META = { owners: d.owners || [], products: d.products || [] }; } catch (e) { META = { owners: [], products: [] }; } }
    clear(container);

    container.appendChild(hero(lead.company || lead.name || ('Lead #' + id), (lead.company && lead.name ? lead.name + ' · ' : '') + (lead.email || 'Lead details, activity and proposals.'), '🏢'));
    container.appendChild(toolbar([btn('← Pipeline', 'light', function () { go('sales'); })]));

    var body = el('div', { class: 'kt-lead-page' });

    // Stage mover
    var stageSel = selectEl(STAGES.map(function (s) { return { v: s.key, l: s.label }; }), lead.stage, { style: 'width:auto;min-width:170px;padding:9px 11px;border:1px solid var(--kt-border,#d9e1ea);border-radius:9px;font-size:14px' });
    stageSel.addEventListener('change', async function () {
      try { await Api.patch('/sales/leads/' + id, { stage: stageSel.value }); lead.stage = stageSel.value; toast('✅', 'Stage updated', STAGE_LABEL[stageSel.value]); renderDetail(container, ctx); } catch (e) { toast('⚠️', 'Failed', e.message || '', '#DC2626'); }
    });
    body.appendChild(card([
      el('div', { style: 'display:flex;gap:14px;align-items:center;flex-wrap:wrap' }, [
        el('div', {}, [el('div', { style: 'font-size:11px;color:#5a7080;font-weight:700;margin-bottom:3px' }, ['STAGE']), stageSel]),
        el('div', { style: 'flex:1' }),
        el('div', { style: 'text-align:right' }, [el('div', { style: 'font-size:11px;color:#5a7080;font-weight:700' }, ['EST. VALUE']), el('div', { style: 'font-size:20px;font-weight:800;color:#047857' }, [money(lead.value)])]),
      ]),
    ], 'margin-bottom:14px'));

    /* THE LEAD PAGE FILLS THE SCREEN (2026-10-09: "make this look better and fill the
       screen to utilise the space"). Two columns on a wide screen — the record on the
       left in labelled sections, the conversation (activity, proposals) on the right —
       and one column on a narrow one. No width cap: the page uses what it is given. */
    ensureLeadCss();
    var grid = el('div', { class: 'kt-lead-grid' });
    var left = el('div', { class: 'kt-lead-main' });
    var e = {};
    left.appendChild(card([
      el('div', { style: 'display:flex;align-items:center;gap:10px;margin-bottom:14px' }, [
        el('div', { style: 'font-weight:800;font-size:15px;flex:1' }, ['Lead record']),
        el('div', { style: 'font-size:12px;color:#94A3B8' }, [lead.created_at ? 'Added ' + fmtStamp(lead.created_at) : '']),
      ]),
      el('div', { class: 'kt-lead-sections' }, [
        formSection('Contact', '👤', [
          field('Contact', e.name = input({ value: lead.name || '' })),
          field('Job title', e.title = input({ value: lead.title || '' })),
          field('Email', e.email = input({ value: lead.email || '' })),
          field('Phone', e.phone = input({ value: lead.phone || '' })),
        ]),
        formSection('Company', '🏢', [
          field('Company', e.company = input({ value: lead.company || '' })),
          field('Website', e.website = input({ value: lead.website || '' })),
          field('Using today', e.current_solution = input({ value: lead.current_solution || '', placeholder: 'HiMama, paper…' })),
          field('# children', e.num_children = input({ type: 'number', min: '0', value: lead.num_children == null ? '' : lead.num_children })),
          field('# locations', e.num_locations = input({ type: 'number', min: '0', value: lead.num_locations == null ? '' : lead.num_locations })),
        ]),
        formSection('Deal', '💼', [
          field('Lead source', e.source = selectEl(sourceOptions(lead.source), sourceValue(lead.source))),
          field('Owner', e.owner_id = selectEl(ownerOpts(), lead.owner_id || '')),
          field('Est. value ($)', e.value = input({ type: 'number', step: '0.01', value: lead.value == null ? '' : lead.value })),
          field('Expected close', e.expected_close = input({ type: 'date', value: lead.expected_close || '' })),
          field('Next follow-up', e.follow_up_date = input({ type: 'date', value: lead.follow_up_date || '' })),
        ]),
        formSection('Business owner', '🧑‍💼', [
          field('Owner name', e.owner_name = input({ value: lead.owner_name || '', placeholder: 'Business owner' })),
          field('Owner title', e.owner_title = input({ value: lead.owner_title || '' })),
          field('Owner email', e.owner_email = input({ value: lead.owner_email || '' })),
          field('Owner phone', e.owner_phone = input({ value: lead.owner_phone || '' })),
        ]),
        el('div', { class: 'kt-lead-wide' }, [formSection('Address', '📍', [
          field('Street address', e.address = input({ value: lead.address || '' })),
          field('City', e.city = input({ value: lead.city || '' })),
          field('Province / state', e.province = input({ value: lead.province || '' })),
          field('Postal / ZIP', e.postal_code = input({ value: lead.postal_code || '' })),
          field('Country', e.country = input({ value: lead.country || '' })),
        ])]),
        el('div', { class: 'kt-lead-wide' }, [formSection('Notes', '🗒️', [fieldWide('About this lead', e.notes = textarea({ value: lead.notes || '', rows: 4 }))])]),
      ]),
      el('div', { style: 'display:flex;gap:8px;margin-top:4px' }, [
        btn('Save changes', 'primary', async function (ev) {
          ev.target.disabled = true;
          try {
            await Api.patch('/sales/leads/' + id, {
              name: e.name.value, title: e.title.value, company: e.company.value, website: e.website.value,
              email: e.email.value, phone: e.phone.value, address: e.address.value, city: e.city.value,
              province: e.province.value, postal_code: e.postal_code.value, country: e.country.value,
              current_solution: e.current_solution.value, num_children: e.num_children.value || null,
              num_locations: e.num_locations.value || null, source: e.source.value || null,
              owner_name: e.owner_name.value, owner_title: e.owner_title.value,
              owner_email: e.owner_email.value, owner_phone: e.owner_phone.value,
              owner_id: e.owner_id.value || null, value: e.value.value || null,
              expected_close: e.expected_close.value || null, follow_up_date: e.follow_up_date.value || null, notes: e.notes.value,
            });
            toast('✅', 'Saved'); renderDetail(container, ctx);
          } catch (er) { toast('⚠️', 'Failed', er.message || '', '#DC2626'); ev.target.disabled = false; }
        }),
        btn('🗑 Delete', 'danger', async function () {
          // KT.confirm, like the rest of the portal — and it names the lead, so a
          // mis-click is caught before it becomes an audit entry rather than after.
          var ok = (window.KT && KT.confirm)
            ? await KT.confirm({ title: 'Delete this lead?',
                description: (lead && (lead.company || lead.name)) ? ('“' + (lead.company || lead.name) + '” will be removed from the pipeline.') : 'It will be removed from the pipeline.',
                tone: 'danger' })
            : confirm('Delete this lead?');
          if (!ok) return; try { await Api.delete('/sales/leads/' + id); toast('🗑', 'Deleted'); go('sales'); } catch (er) { toast('⚠️', 'Failed', er.message || '', '#DC2626'); } }),
      ]),
    ], 'margin-bottom:14px'));
    attachAddressAutocomplete(e.address, { city: e.city, province: e.province, postal_code: e.postal_code, country: e.country });
    // Opened with "Edit" from the leads list: go straight to the details form.
    if (/[?&]edit=1\b/.test(window.location.hash)) {
      setTimeout(function () {
        try { e.company.scrollIntoView({ behavior: 'smooth', block: 'center' }); e.company.focus({ preventScroll: true }); } catch (er) {}
      }, 150);
    }

    // Activity + follow-up composer
    var acWrap = card([el('div', { style: 'font-weight:800;margin-bottom:12px' }, ['Activity & history'])], '');
    var typeSel = selectEl([{ v: 'note', l: '📝 Note' }, { v: 'call', l: '📞 Call' }, { v: 'email', l: '📧 Email' }, { v: 'meeting', l: '🤝 Meeting' }, { v: 'followup', l: '⏰ Follow-up task' }], 'note', { style: 'width:100%;padding:9px 11px;border:1px solid var(--kt-border,#d9e1ea);border-radius:9px;font-size:14px;box-sizing:border-box' });
    /* A BIGGER BOX (2026-10-09: "make the window to add text bigger as well"): meeting
       notes run to paragraphs. */
    var bodyIn = textarea({ placeholder: 'Log a note, or describe the follow-up… (saved with the date and time). Attach a Teams transcript and what was discussed is added to the entry for you.', rows: 8 });
    bigBox(bodyIn, 220);
    /* TEAMS TRANSCRIPTS AND RECORDINGS (2026-10-09: "add ability to upload transcript from MS
       teams meetings and video to activity and history"). Chosen here, uploaded in pieces
       after the entry is saved; a transcript is read into text on the server. */
    var filesIn = el('input', { type: 'file', multiple: 'multiple', accept: ACT_FILE_ACCEPT, style: 'display:none' });
    var filesList = el('div', { style: 'font-size:12.5px;color:#475569;display:flex;flex-direction:column;gap:2px' });
    var pickBtn = btn('📎 Attach transcript / recording', 'light', function () { filesIn.click(); });
    filesIn.addEventListener('change', function () {
      clear(filesList);
      Array.prototype.forEach.call(filesIn.files || [], function (f) {
        filesList.appendChild(el('div', {}, [fileIcon(f.name) + ' ' + f.name + ' · ' + fmtBytes(f.size)]));
      });
      // A transcript or recording is a meeting, unless they already chose otherwise.
      if ((filesIn.files || []).length && typeSel.value === 'note') { typeSel.value = 'meeting'; }
    });
    var dueIn = input({ type: 'date' }); dueIn.style.display = 'none';
    typeSel.addEventListener('change', function () { dueIn.style.display = typeSel.value === 'followup' ? 'block' : 'none'; });
    acWrap.appendChild(el('div', { style: 'display:grid;grid-template-columns:minmax(150px,180px) 1fr;gap:8px;margin-bottom:8px' }, [typeSel, dueIn]));
    acWrap.appendChild(bodyIn);
    acWrap.appendChild(filesIn);
    acWrap.appendChild(el('div', { style: 'display:flex;gap:10px;align-items:flex-start;flex-wrap:wrap;margin-top:8px' }, [
      btn('Add', 'primary', async function (ev) {
        var picked = Array.prototype.slice.call(filesIn.files || []);
        var b = { type: typeSel.value, body: bodyIn.value.trim() };
        if (typeSel.value === 'followup') { if (!dueIn.value) { toast('⚠️', 'Pick a due date', '', '#DC2626'); return; } b.due_date = dueIn.value; }
        if (!b.body && typeSel.value !== 'followup' && !picked.length) { toast('⚠️', 'Write something', 'Or attach a transcript or recording.', '#DC2626'); return; }
        if (!b.body && picked.length) { b.body = (typeSel.value === 'meeting' ? 'Meeting' : 'Files') + ': ' + picked.map(function (f) { return f.name; }).join(', '); }
        ev.target.disabled = true;
        try {
          if (picked.length) { b.defer_notify = true; }
          var act = await Api.post('/sales/leads/' + id + '/activities', b);
          if (picked.length) {
            /* A transcript is written into the entry as it lands; THEN the team is told, so
               the email carries what was discussed. Told even if an upload failed. */
            try { await uploadActivityFiles(act.id, picked); }
            finally { try { await Api.post('/sales/activities/' + act.id + '/notify', {}); } catch (e) {} }
          }
          toast('✅', 'Added', picked.length ? picked.length + ' file(s) attached' : '');
          renderDetail(container, ctx);
        } catch (er) { toast('⚠️', 'Failed', er.message || '', '#DC2626'); ev.target.disabled = false; renderDetail(container, ctx); }
      }),
      pickBtn,
      filesList,
    ]));
    var timeline = el('div', { style: 'margin-top:14px;display:flex;flex-direction:column;gap:8px' });
    var ACT_ICON = { note: '📝', call: '📞', email: '📧', meeting: '🤝', followup: '⏰', stage: '↗️', edit: '✏️' };
    var ACT_LABEL = { note: 'Note', call: 'Call', email: 'Email', meeting: 'Meeting', followup: 'Follow-up', stage: 'Stage change', edit: 'Field change' };
    (lead.activities || []).forEach(function (a) {
      var isFu = a.type === 'followup';
      var accent = isFu && !a.done ? '#F59E0B' : (a.type === 'edit' ? '#8B5CF6' : '#e6ebf1');
      /* EDIT WHAT YOU WROTE (2026-10-09: "allow me to edit notes that I have added to
         leads to correct anything"). Offered on the author's own entries (and to platform
         admins) — the server decides (can_edit) and checks again on save. The text is
         corrected in place; the entry keeps its original time and says it was edited. */
      var bodyHost = el('div', {}, [activityBodyNode(a)]);
      var editLink = a.can_edit ? chip('✏️ Edit', function () {
        if (bodyHost.getAttribute('data-editing')) { return; }
        bodyHost.setAttribute('data-editing', '1');
        var ta = textarea({ value: a.body || '', rows: 8 });
        ta.style.marginTop = '6px'; bigBox(ta, 200);
        /* THE KIND OF ENTRY TOO (2026-10-09: "need to be able to edit the type of action as
           well"). The five a person logs; a follow-up brings its due date with it. */
        var typeSel = selectEl([{ v: 'note', l: '📝 Note' }, { v: 'call', l: '📞 Call' }, { v: 'email', l: '📧 Email' }, { v: 'meeting', l: '🤝 Meeting' }, { v: 'followup', l: '⏰ Follow-up task' }],
          a.type, { style: 'width:auto;min-width:170px;padding:7px 10px;border:1px solid var(--kt-border,#d9e1ea);border-radius:9px;font-size:13.5px' });
        var due = input({ type: 'date', value: a.due_date || '' });
        var dueWrap = el('div', { style: 'max-width:220px' }, [field('Due date', due)]);
        function syncDue() { dueWrap.style.display = typeSel.value === 'followup' ? '' : 'none'; }
        typeSel.addEventListener('change', syncDue);
        var saveB = btn('Save', 'primary', async function (ev4) {
          var t = typeSel.value;
          var b = { body: ta.value.trim() };
          if (t !== a.type) { b.type = t; }
          if (!b.body && t !== 'followup') { toast('⚠️', 'Write something', '', '#DC2626'); return; }
          if (t === 'followup') {
            if (!due.value) { toast('⚠️', 'Pick a due date', 'A follow-up is a task with a date.', '#DC2626'); return; }
            b.due_date = due.value;
          }
          ev4.target.disabled = true;
          try {
            await Api.patch('/sales/activities/' + a.id, b);
            var more = Array.prototype.slice.call(moreIn.files || []);
            if (more.length) { await uploadActivityFiles(a.id, more); }
            toast('✅', 'Updated'); renderDetail(container, ctx);
          }
          catch (er) { toast('⚠️', 'Could not save', er.message || '', '#DC2626'); ev4.target.disabled = false; }
        });
        var cancelB = btn('Cancel', 'light', function () { bodyHost.removeAttribute('data-editing'); clear(bodyHost); bodyHost.appendChild(activityBodyNode(a)); });
        var moreIn = el('input', { type: 'file', multiple: 'multiple', accept: ACT_FILE_ACCEPT, style: 'display:none' });
        var moreList = el('span', { style: 'font-size:12.5px;color:#475569' });
        moreIn.addEventListener('change', function () { moreList.textContent = Array.prototype.map.call(moreIn.files || [], function (f) { return f.name; }).join(', '); });
        var moreB = btn('📎 Attach files', 'light', function () { moreIn.click(); });
        clear(bodyHost);
        bodyHost.appendChild(el('div', { style: 'display:flex;gap:10px;align-items:flex-end;flex-wrap:wrap;margin-top:6px' }, [
          el('div', {}, [el('div', { style: 'font-size:11px;font-weight:700;color:#5a7080;margin-bottom:3px' }, ['TYPE']), typeSel]),
          dueWrap,
        ]));
        syncDue();
        bodyHost.appendChild(ta);
        bodyHost.appendChild(moreIn);
        bodyHost.appendChild(el('div', { style: 'display:flex;gap:8px;margin-top:8px;align-items:center;flex-wrap:wrap' }, [saveB, cancelB, moreB, moreList]));
        ta.focus();
      }) : '';
      timeline.appendChild(el('div', { style: 'border-left:2px solid ' + accent + ';padding:2px 0 2px 10px' }, [
        /* WHEN · WHO · WHAT (2026-10-09: "make the date/timestamp then user name the first
           thing followed by activity"). */
        el('div', { style: 'font-size:12px;color:#64748B;display:flex;flex-wrap:wrap;align-items:baseline;gap:0 6px' }, [
          a.created_at ? el('span', { style: 'color:#334155;font-weight:700;white-space:nowrap', title: 'Logged ' + fmtStamp(a.created_at) }, ['🕒 ' + fmtStamp(a.created_at)]) : '',
          el('span', { style: 'color:#334155;font-weight:600' }, ['· ' + (a.user_name || 'Website / system')]),
          el('span', { style: 'color:#64748B' }, ['· ' + (ACT_ICON[a.type] || '•') + ' ' + (ACT_LABEL[a.type] || (a.type.charAt(0).toUpperCase() + a.type.slice(1)))]),
          isFu && a.due_date ? el('span', { style: 'margin-left:6px;font-weight:700;color:' + (a.done ? '#15803D' : '#C2410C') }, [a.done ? '✓ done' : ('due ' + fmtDate(a.due_date))]) : '',
          isFu && !a.done ? chip('✓ Mark done', async function () { try { await Api.patch('/sales/activities/' + a.id, { done: true }); toast('✅', 'Done'); renderDetail(container, ctx); } catch (er) {} }) : '',
          a.edited_at ? el('span', { style: 'margin-left:6px;color:#94A3B8;font-style:italic', title: 'Edited ' + fmtStamp(a.edited_at) }, ['· edited ' + fmtStamp(a.edited_at)]) : '',
          editLink,
        ]),
        bodyHost,
        activityFilesNode(a, function () { renderDetail(container, ctx); }),
      ]));
    });
    if (!(lead.activities || []).length) timeline.appendChild(el('div', { style: 'color:#94A3B8;font-size:13px' }, ['No activity yet.']));
    acWrap.appendChild(timeline);

    /* SUBTABS (2026-10-09: "to clean this up better with leads add subtabs to the lead to
       view activity and history, proposals etc"). One section at a time, full width. The
       tab is kept in the address (&tab=) so a refresh, a save or the back button returns
       to it, and remembered for the next lead opened in this session. */
    var TABS = [
      { key: 'details', label: '📋 Details', node: left },
      { key: 'activity', label: '🕒 Activity & history', count: (lead.activities || []).length, node: acWrap },
      { key: 'proposals', label: '📄 Proposals', count: (lead.quotes || []).length, node: renderQuotesPanel(id, lead, container, ctx) },
      { key: 'documents', label: '📁 Documents', count: leadFiles(lead).length || null, node: renderLeadDocuments(id, lead) },
    ];
    var want = ((window.location.hash.match(/[?&]tab=([a-z]+)/) || [])[1])
      || (function () { try { return sessionStorage.getItem('kt_lead_tab') || ''; } catch (e) { return ''; } })();
    if (!TABS.some(function (t) { return t.key === want; })) { want = 'details'; }
    var bar = el('div', { class: 'kt-lead-tabs', role: 'tablist' });
    var panel = el('div', { class: 'kt-lead-panel' });
    function show(key) {
      TABS.forEach(function (t) {
        var on = t.key === key;
        t.btn.setAttribute('aria-selected', on ? 'true' : 'false');
        t.btn.classList.toggle('on', on);
      });
      clear(panel);
      panel.appendChild(TABS.filter(function (t) { return t.key === key; })[0].node);
      try { sessionStorage.setItem('kt_lead_tab', key); } catch (e) {}
      try {
        var h = window.location.hash.replace(/([?&])tab=[a-z]+&?/, '$1').replace(/[?&]$/, '');
        history.replaceState(null, '', h + (h.indexOf('?') >= 0 ? '&' : '?') + 'tab=' + key);
      } catch (e) {}
    }
    TABS.forEach(function (t) {
      t.btn = el('button', { type: 'button', role: 'tab', class: 'kt-lead-tab', 'data-kt-iconized': '1', 'data-kt-inpage': '1',
        onclick: function () { show(t.key); } }, [t.label, t.count ? el('span', { class: 'kt-lead-count' }, [String(t.count)]) : '']);
      bar.appendChild(t.btn);
    });
    body.appendChild(bar);
    body.appendChild(panel);
    show(want);
    // Opened with "Edit" from the leads list: the details tab, cursor in the first field.
    if (/[?&]edit=1\b/.test(window.location.hash)) { show('details'); }
    container.appendChild(el('div', { class: 'kt-lead-page' }, [body]));
  }

  function ensureLeadCss() {
    if (document.getElementById('kt-lead-css')) { return; }
    var st = document.createElement('style'); st.id = 'kt-lead-css';
    st.textContent =
      '.kt-lead-page{width:100%;max-width:none;padding:0 4px;box-sizing:border-box}' +
      '.kt-lead-grid{display:grid;grid-template-columns:minmax(0,1.65fr) minmax(340px,1fr);gap:16px;align-items:start}' +
      '.kt-lead-side{position:sticky;top:12px;min-width:0}' +
      '.kt-lead-main{min-width:0}' +
      '.kt-lead-sections{display:grid;grid-template-columns:repeat(auto-fill,minmax(400px,1fr));gap:0 14px;align-items:start}' +
      '.kt-lead-tabs{display:flex;gap:6px;flex-wrap:wrap;margin:0 0 12px;border-bottom:1px solid #E2E8F0;padding-bottom:0}' +
      '.kt-lead-tab{display:inline-flex;align-items:center;gap:7px;height:36px;padding:0 14px;border:1px solid transparent;border-bottom:none;border-radius:10px 10px 0 0;background:transparent;color:#475569;font:inherit;font-size:13.5px;font-weight:700;cursor:pointer;margin-bottom:-1px}' +
      '.kt-lead-tab:hover{background:#F1F5F9}' +
      '.kt-lead-tab.on{background:#fff;color:#0C6070;border-color:#E2E8F0;box-shadow:inset 0 3px 0 #0C6070}' +
      '.kt-lead-count{display:inline-block;min-width:20px;padding:1px 7px;border-radius:999px;background:#E2E8F0;color:#334155;font-size:11.5px;text-align:center}' +
      '.kt-lead-tab.on .kt-lead-count{background:#0C6070;color:#fff}' +
      '.kt-lead-panel{min-width:0}' +
      '.kt-lead-sections .kt-lead-wide{grid-column:1 / -1}' +
      '.kt-lead-sections input,.kt-lead-sections select,.kt-lead-sections textarea{max-width:none !important;width:100%}' +
      '@media (max-width:900px){.kt-lead-sections{grid-template-columns:minmax(0,1fr)}.kt-lead-tab{flex:1 1 auto;justify-content:center;padding:0 8px}}' +
      '@media (max-width:980px){.kt-lead-grid{grid-template-columns:minmax(0,1fr)}.kt-lead-side{position:static}}';
    document.head.appendChild(st);
  }

  function renderQuotesPanel(id, lead, container, ctx) {
    var panel = card([el('div', { style: 'display:flex;justify-content:space-between;align-items:center;margin-bottom:12px' }, [
      el('div', { style: 'font-weight:800' }, ['Proposals']),
      btn('+ New', 'light', function () { openQuoteBuilder(id, null, container, ctx); }),
    ])]);
    var list = el('div', { style: 'display:flex;flex-direction:column;gap:8px' });
    (lead.quotes || []).forEach(function (q) {
      list.appendChild(el('div', { style: 'border:1px solid #e6ebf1;border-radius:10px;padding:10px 12px;cursor:pointer', onclick: function () { openQuoteBuilder(id, q, container, ctx); } }, [
        el('div', { style: 'display:flex;justify-content:space-between;align-items:center' }, [
          el('div', { style: 'font-weight:700;font-size:13px' }, [q.number || 'Proposal', ' ', el('span', { style: 'font-size:11px;font-weight:700;color:#fff;background:' + ({ draft: '#94A3B8', sent: '#0EA5E9', accepted: '#10B981', declined: '#EF4444' }[q.status] || '#94A3B8') + ';border-radius:999px;padding:1px 8px;margin-left:4px' }, [q.status])]),
          el('div', { style: 'font-weight:800;color:#047857' }, [money(q.total)]),
        ]),
        q.title ? el('div', { style: 'font-size:12px;color:#64748B;margin-top:2px' }, [q.title]) : null,
      ]));
    });
    if (!(lead.quotes || []).length) list.appendChild(el('div', { style: 'color:#94A3B8;font-size:13px' }, ['No proposals yet.']));
    panel.appendChild(list);
    return panel;
  }

  function openQuoteBuilder(leadId, quote, container, ctx) {
    var products = (META && META.products) || [];
    var byId = {}; products.forEach(function (p) { byId[String(p.id)] = p; });
    var ov = el('div', { style: 'position:fixed;inset:0;z-index:10000;background:rgba(13,27,42,.55);display:flex;align-items:flex-start;justify-content:center;padding:30px 16px;overflow:auto', onclick: function (ev) { if (ev.target === ov) ov.remove(); } });
    // A line from a discount plan remembers its rule, so a percentage follows the other lines.
    function discRule(productId) { var p = byId[String(productId)]; return p && p.kind === 'discount' ? { type: p.discount_type, value: Number(p.price || 0), applies: p.applies_to || 'all' } : null; }
    var items = quote && quote.items ? quote.items.map(function (i) { return { description: i.description, qty: i.qty, unit_price: i.unit_price, product_id: i.product_id, _disc: discRule(i.product_id) }; }) : [];
    var box = el('div', { style: 'background:#fff;border-radius:16px;max-width:640px;width:100%;padding:22px' });
    var titleIn = input({ value: quote ? (quote.title || '') : 'KiddieTrac Proposal', placeholder: 'Proposal title' });
    var discIn = input({ type: 'number', step: '0.01', value: quote ? (quote.discount || 0) : 0 });
    var validIn = input({ type: 'date', value: quote ? (quote.valid_until || '') : '' });
    var notesIn = textarea({ value: quote ? (quote.notes || '') : '' });
    var itemsHost = el('div', { style: 'display:flex;flex-direction:column;gap:6px;margin:6px 0' });
    var totalEl = el('div', { style: 'text-align:right;font-weight:800;font-size:18px;color:#047857;margin-top:8px' }, ['$0.00']);
    function unitOf(it) { var p = byId[String(it.product_id)]; return p ? p.unit : null; }
    // What a discount line takes off, from the lines it applies to (never more than they come to).
    function discountAmount(it) {
      var r = it._disc; if (!r) return 0;
      var base = 0;
      items.forEach(function (o) {
        if (o === it || o._disc) return;
        var u = unitOf(o);
        var ok = r.applies === 'all' || (r.applies === 'recurring' && (u === 'month' || u === 'year')) || (r.applies === 'one-time' && u === 'one-time');
        if (ok) base += Number(o.qty || 0) * Number(o.unit_price || 0);
      });
      var off = r.type === 'percent' ? base * r.value / 100 : r.value;
      return Math.round(Math.min(Math.max(off, 0), Math.max(base, 0)) * 100) / 100;
    }
    function recalc() {
      items.forEach(function (it) {
        if (!it._disc) return;
        it.qty = 1; it.unit_price = -discountAmount(it);
        if (it._pIn) it._pIn.value = it.unit_price.toFixed(2);
      });
      var s = 0; items.forEach(function (i) { s += (Number(i.qty || 0) * Number(i.unit_price || 0)); });
      var t = s - Number(discIn.value || 0);
      totalEl.textContent = money(t) + (Number(discIn.value || 0) ? '  (−' + money(discIn.value) + ')' : '');
    }
    function drawItems() {
      clear(itemsHost);
      items.forEach(function (it, idx) {
        var isDisc = !!it._disc;
        var d = input({ value: it.description || '', placeholder: 'Line item', style: 'flex:1;min-width:0;padding:7px 9px;border:1px solid #d9e1ea;border-radius:8px;font-size:14px;box-sizing:border-box' + (isDisc ? ';color:#047857;font-weight:700' : '') });
        var q = input({ type: 'number', step: '0.01', value: it.qty == null ? 1 : it.qty, style: 'width:60px;flex:0 0 60px;padding:7px;border:1px solid #d9e1ea;border-radius:8px;font-size:14px;box-sizing:border-box' });
        var p = input({ type: 'number', step: '0.01', value: it.unit_price == null ? 0 : it.unit_price, style: 'width:90px;flex:0 0 90px;padding:7px;border:1px solid #d9e1ea;border-radius:8px;font-size:14px;box-sizing:border-box' + (isDisc ? ';color:#047857;background:#F0FDF4' : '') });
        if (isDisc) { q.disabled = true; p.readOnly = true; p.title = 'Worked out from the discount plan'; it._pIn = p; }
        d.addEventListener('input', function () { it.description = d.value; }); q.addEventListener('input', function () { it.qty = q.value; recalc(); }); p.addEventListener('input', function () { it.unit_price = p.value; recalc(); });
        itemsHost.appendChild(el('div', { style: 'display:flex;gap:6px;align-items:center' }, [d, q, p, el('button', { style: 'border:0;background:#FEE2E2;color:#B91C1C;border-radius:8px;padding:6px 9px;cursor:pointer;flex:0 0 auto', onclick: function () { items.splice(idx, 1); drawItems(); recalc(); } }, ['×'])]));
      });
    }
    drawItems(); recalc(); discIn.addEventListener('input', recalc);
    var planOpts = products.filter(function (p) { return p.kind !== 'discount'; }).map(function (p) { return { v: p.id, l: p.name + ' — ' + money(p.price) + '/' + p.unit }; });
    var discOpts = products.filter(function (p) { return p.kind === 'discount'; }).map(function (p) { return { v: p.id, l: '🏷️ ' + p.name + ' — ' + discountLabel(p) }; });
    var planSel = selectEl([{ v: '', l: '+ Add preset plan or discount…' }].concat(planOpts, discOpts), '');
    planSel.addEventListener('change', function () {
      var p = byId[String(planSel.value)];
      if (p) {
        if (p.kind === 'discount') {
          items.push({ description: p.name + ' (' + discountLabel(p) + ')' + (p.description ? ' — ' + p.description : ''), qty: 1, unit_price: 0, product_id: p.id, _disc: discRule(p.id) });
        } else {
          items.push({ description: p.name + (p.description ? ' — ' + p.description : ''), qty: 1, unit_price: Number(p.price), product_id: p.id });
        }
        drawItems(); recalc(); planSel.value = '';
      }
    });
    box.appendChild(el('div', { style: 'display:flex;justify-content:space-between;align-items:center;margin-bottom:12px' }, [el('div', { style: 'font-size:18px;font-weight:800' }, [quote ? ('Proposal ' + (quote.number || '')) : 'New proposal']), el('button', { style: 'border:0;background:none;font-size:22px;cursor:pointer;color:#64748B', onclick: function () { ov.remove(); } }, ['×'])]));
    box.appendChild(field('Title', titleIn));
    box.appendChild(el('div', { style: 'display:flex;gap:8px;align-items:center;margin:14px 0 6px' }, [el('div', { style: 'flex:1;font-size:12px;font-weight:700;color:#5a7080' }, ['Line items']), el('div', { style: 'width:60px;font-size:11px;color:#64748B;text-align:center' }, ['Qty']), el('div', { style: 'width:90px;font-size:11px;color:#64748B;text-align:center' }, ['Price']), el('div', { style: 'width:34px' })]));
    box.appendChild(itemsHost);
    box.appendChild(el('div', { style: 'display:flex;gap:8px;margin-bottom:14px' }, [el('div', { style: 'flex:1;min-width:0' }, [planSel]), btn('+ Blank line', 'light', function () { items.push({ description: '', qty: 1, unit_price: 0 }); drawItems(); })]));
    box.appendChild(el('div', { style: 'display:grid;grid-template-columns:1fr 1fr;gap:14px' }, [field('Extra discount ($)', discIn), field('Valid until', validIn)]));
    box.appendChild(el('div', { style: 'margin-top:14px' }, [field('Notes', notesIn)]));
    box.appendChild(totalEl);
    var statusSel = quote ? selectEl([{ v: 'draft', l: 'Draft' }, { v: 'sent', l: 'Sent' }, { v: 'accepted', l: 'Accepted' }, { v: 'declined', l: 'Declined' }], quote.status, { style: 'width:auto;padding:7px 10px;border:1px solid #d9e1ea;border-radius:8px;font-size:13px' }) : null;
    box.appendChild(el('div', { style: 'display:flex;gap:8px;justify-content:flex-end;margin-top:16px;align-items:center;flex-wrap:wrap' }, [
      quote ? el('div', { style: 'margin-right:auto;display:flex;align-items:center;gap:6px' }, [el('span', { style: 'font-size:12px;color:#64748B' }, ['Status']), statusSel]) : null,
      quote ? btn('⬇ PDF', 'light', function () { downloadQuotePdf(quote.id); }) : null,
      quote && leadId ? btn('📧 Email', 'light', async function (ev) { ev.target.disabled = true; try { await Api.post('/sales/quotes/' + quote.id + '/send', {}); toast('📧', 'Proposal emailed', 'Sent to the prospect.'); } catch (e) { toast('⚠️', 'Email failed', e.message || '', '#DC2626'); ev.target.disabled = false; } }) : null,
      btn('Cancel', 'ghost', function () { ov.remove(); }),
      btn(quote ? 'Save proposal' : 'Create proposal', 'primary', async function (ev) {
        ev.target.disabled = true;
        recalc();
        var lines = items.filter(function (i) { return (i.description || '').trim(); }).map(function (i) {
          return { description: i.description, qty: i.qty, unit_price: i.unit_price, product_id: i.product_id || null };
        });
        var body = { title: titleIn.value, discount: discIn.value || 0, valid_until: validIn.value || null, notes: notesIn.value, items: lines };
        if (quote) body.status = statusSel.value;
        try {
          if (quote) await Api.patch('/sales/quotes/' + quote.id, body); else await Api.post('/sales/leads/' + leadId + '/quotes', body);
          toast('✅', 'Proposal saved'); ov.remove(); renderDetail(container, ctx);
        } catch (er) { toast('⚠️', 'Failed', er.message || '', '#DC2626'); ev.target.disabled = false; }
      }),
    ]));
    ov.appendChild(box); document.body.appendChild(ov);
  }

  // ─────────────────────────────── Follow-ups ───────────────────────────────
  async function renderFollowups(container) {
    clear(container);
    container.appendChild(hero('Follow-ups', 'Open follow-up tasks across your leads.', '⏰'));
    var body = el('div');
    var data = await Api.get('/sales/leads');
    META = { owners: data.owners || [], products: data.products || [] };
    // Show leads with a due follow_up_date (fetching every lead's activities would be too heavy).
    var due = (data.leads || []).filter(function (l) { return l.follow_up_date && l.status === 'open'; }).sort(function (a, b) { return (a.follow_up_date || '') < (b.follow_up_date || '') ? -1 : 1; });
    if (!due.length) { container.appendChild(wrap([card([el('div', { style: 'text-align:center;color:#64748B;padding:20px' }, ['🎉 No follow-ups scheduled.'])])], 820)); return; }
    var list = el('div', { style: 'display:flex;flex-direction:column;gap:8px' });
    due.forEach(function (l) {
      var overdue = l.follow_up_date < todayISO();
      list.appendChild(card([el('div', { style: 'display:flex;justify-content:space-between;align-items:center;cursor:pointer;gap:12px', onclick: function () { go('sales-lead?id=' + l.id); } }, [
        el('div', {}, [el('div', { style: 'font-weight:700' }, [l.company || l.name]), el('div', { style: 'font-size:12px;color:#64748B;margin-top:3px' }, [stageChip(l.stage), l.owner_name ? '  · ' + l.owner_name : ''])]),
        el('div', { style: 'text-align:right' }, [el('div', { style: 'font-weight:700;color:' + (overdue ? '#C2410C' : '#334155') }, [(overdue ? '⏰ overdue · ' : '') + fmtDate(l.follow_up_date)])]),
      ])], 'padding:14px 18px'));
    });
    body.appendChild(list);
    container.appendChild(wrap([body], 820));
  }

  // ─────────────────────────────── Plans / pricing ───────────────────────────────
  /* DISCOUNT PLANS (2026-10-14, Anthony: "plans and pricing we need ability to add a plan that can
     discount"). A preset is now a priced PLAN or a DISCOUNT — % off or a fixed $ off — that can
     apply to the whole proposal, only its recurring (monthly/yearly) items, or only its one-time
     items. In a proposal a discount is its own minus line, recalculated as the other lines change. */
  var APPLIES = [{ v: 'all', l: 'the whole proposal' }, { v: 'recurring', l: 'recurring items (monthly / yearly)' }, { v: 'one-time', l: 'one-time items' }];
  function discountLabel(p) {
    var v = Number(p.price || 0);
    var what = p.discount_type === 'percent' ? (v % 1 ? v.toFixed(2) : String(v)) + '% off' : money(v) + ' off';
    var on = p.applies_to === 'recurring' ? ' recurring' : (p.applies_to === 'one-time' ? ' one-time' : '');
    return what + on;
  }
  async function renderPlans(container) {
    clear(container);
    container.appendChild(hero('Plans & pricing', 'Preset plans and discounts used in proposals. Edit prices to match your offer.', '💲'));
    var host = el('div'); container.appendChild(host); // full width
    var FLD = 'padding:9px 10px;border:1px solid #d9e1ea;border-radius:8px;font-size:14px;box-sizing:border-box';
    async function load() {
      clear(host);
      var products = await Api.get('/sales/products');
      var plans = products.filter(function (p) { return p.kind !== 'discount'; });
      var discounts = products.filter(function (p) { return p.kind === 'discount'; });
      var grid = el('div', { style: 'display:grid;grid-template-columns:repeat(auto-fill,minmax(340px,1fr));gap:14px;align-items:start' });
      plans.forEach(function (p) { grid.appendChild(planCard(p, load)); });
      host.appendChild(grid);
      host.appendChild(el('div', { style: 'margin-top:16px' }, [btn('➕ Add plan', 'primary', function () { grid.insertBefore(planCard({ name: '', description: '', price: 0, unit: 'month', active: true }, load, true), grid.firstChild); })]));

      host.appendChild(el('div', { style: 'margin:26px 0 4px;font-size:16px;font-weight:800;color:#0D1B2A' }, ['🏷️ Discounts']));
      host.appendChild(el('div', { style: 'margin:0 0 12px;font-size:13px;color:#64748B' }, ['Add one to a proposal from the same “Add preset” menu. It shows as its own line with a minus, and a percentage updates as you change the other lines.']));
      var dgrid = el('div', { style: 'display:grid;grid-template-columns:repeat(auto-fill,minmax(340px,1fr));gap:14px;align-items:start' });
      discounts.forEach(function (p) { dgrid.appendChild(discountCard(p, load)); });
      if (!discounts.length) dgrid.appendChild(el('div', { style: 'color:#94A3B8;font-size:13px' }, ['No discounts yet.']));
      host.appendChild(dgrid);
      host.appendChild(el('div', { style: 'margin-top:16px' }, [btn('➕ Add discount', 'primary', function () {
        if (!discounts.length) clear(dgrid);
        dgrid.insertBefore(discountCard({ name: '', description: '', price: 10, kind: 'discount', discount_type: 'percent', applies_to: 'all', active: true }, load, true), dgrid.firstChild);
      })]));
    }
    function actions(p, reload, bodyFn) {
      return el('div', { style: 'display:flex;gap:8px;margin-top:12px;justify-content:flex-end;align-items:center' }, [
        p.id ? el('button', { title: 'Delete', style: 'border:0;background:#FEE2E2;color:#B91C1C;border-radius:8px;padding:8px 11px;cursor:pointer;font-size:14px', onclick: async function () { if (!(await window.KT.confirm({ title: 'Delete “' + (p.name || 'this') + '”?', description: 'It is removed for good. Proposals that already use it keep their lines.', okLabel: 'Delete' }))) return; try { await Api.delete('/sales/products/' + p.id); reload(); } catch (er) {} } }, ['🗑 Delete']) : el('div', { style: 'flex:1' }),
        btn('Save', 'primary', async function (ev) {
          var body = bodyFn(); if (!body) return;
          ev.target.disabled = true;
          try { if (p.id) await Api.patch('/sales/products/' + p.id, body); else await Api.post('/sales/products', body); toast('✅', 'Saved'); reload(); }
          catch (er) { toast('⚠️', 'Failed', er.message || '', '#DC2626'); ev.target.disabled = false; }
        }),
      ]);
    }
    // Each plan is its own card: name + price + unit on top, then a FULL-WIDTH
    // multi-line description (so long descriptions are never cut off), then actions.
    function planCard(p, reload, isNew) {
      var n = input({ value: p.name || '', placeholder: 'Plan name', style: 'flex:1 1 auto;min-width:0;font-weight:700;' + FLD });
      var pr = input({ type: 'number', step: '0.01', value: p.price || 0, style: 'flex:0 0 110px;width:110px;' + FLD });
      var u = selectEl([{ v: 'month', l: '/month' }, { v: 'year', l: '/year' }, { v: 'one-time', l: 'one-time' }], p.unit || 'month', { style: 'flex:0 0 118px;width:118px;' + FLD });
      var d = textarea({ value: p.description || '', placeholder: 'Description — shown on proposals. Full text is always visible here.', style: 'width:100%;padding:9px 11px;border:1px solid var(--kt-border,#d9e1ea);border-radius:9px;font-size:14px;box-sizing:border-box;min-height:70px;resize:vertical' });
      return card([
        el('div', { style: 'display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin-bottom:11px' }, [n, pr, u]),
        field('Description', d),
        actions(p, reload, function () { return { name: n.value, description: d.value, price: pr.value || 0, unit: u.value, active: true, kind: 'plan' }; }),
      ], 'padding:15px 16px');
    }
    function discountCard(p, reload, isNew) {
      var n = input({ value: p.name || '', placeholder: 'Discount name, e.g. Non-profit discount', style: 'flex:1 1 auto;min-width:0;font-weight:700;' + FLD });
      var t = selectEl([{ v: 'percent', l: '% off' }, { v: 'amount', l: '$ off' }], p.discount_type || 'percent', { style: 'flex:0 0 96px;width:96px;' + FLD });
      var v = input({ type: 'number', step: '0.01', min: '0', value: p.price == null ? 10 : p.price, style: 'flex:0 0 100px;width:100px;' + FLD });
      var a = selectEl(APPLIES, p.applies_to || 'all', { style: 'width:100%;' + FLD });
      var d = textarea({ value: p.description || '', placeholder: 'Optional note shown on the proposal line, e.g. “For registered non-profits”.', style: 'width:100%;padding:9px 11px;border:1px solid var(--kt-border,#d9e1ea);border-radius:9px;font-size:14px;box-sizing:border-box;min-height:54px;resize:vertical' });
      var preview = el('div', { style: 'font-size:12.5px;color:#047857;font-weight:700;margin-top:8px' });
      function upd() { preview.textContent = 'Shows as: ' + (n.value || 'Discount') + ' (' + discountLabel({ price: v.value, discount_type: t.value, applies_to: a.value }) + ')'; }
      [n, t, v, a].forEach(function (x) { x.addEventListener('input', upd); x.addEventListener('change', upd); }); upd();
      return card([
        el('div', { style: 'display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin-bottom:11px' }, [n, v, t]),
        field('Applies to', a),
        field('Description', d),
        preview,
        actions(p, reload, function () {
          var val = Number(v.value || 0);
          if (!(n.value || '').trim()) { toast('⚠️', 'Give the discount a name', '', '#DC2626'); return null; }
          if (val <= 0) { toast('⚠️', 'Enter how much it takes off', '', '#DC2626'); return null; }
          if (t.value === 'percent' && val > 100) { toast('⚠️', 'A percentage can be at most 100', '', '#DC2626'); return null; }
          return { name: n.value, description: d.value, price: val, kind: 'discount', discount_type: t.value, applies_to: a.value, active: true };
        }),
      ], 'padding:15px 16px;border-left:4px solid #10B981');
    }
    load();
  }

  // ─────────────────────────────── Launch demo ───────────────────────────────
  async function renderDemo(container) {
    clear(container);
    container.appendChild(hero('Launch demo', 'Open the KiddieTrac Test Agency to walk a prospect through the product.', '🚀'));
    container.appendChild(wrap([card([
      el('p', { style: 'color:#334155;margin:0 0 14px;line-height:1.5' }, ['This opens the live demo environment (Test Agency) in a new tab, signed in as a demo admin so you can show attendance, billing, the parent app and more. No real family data.']),
      btn('🚀 Open demo in a new tab', 'primary', async function (ev) {
        ev.target.disabled = true; ev.target.textContent = 'Preparing demo…';
        try {
          var r = await Api.post('/sales/demo-token', {});
          var url = (window.location.origin || 'https://app.kiddietrac.com') + '/dashboard.html#__demo=' + encodeURIComponent(r.token);
          window.open(url, '_blank');
          toast('🚀', 'Demo opening', 'A new tab is launching the Test Agency.');
        } catch (e) { toast('⚠️', 'Could not start demo', e.message || '', '#DC2626'); }
        ev.target.disabled = false; ev.target.textContent = '🚀 Open demo in a new tab';
      }),
    ])], 640));
  }

  // ─────────────────────────────── Team chat (sales + superadmin only) ───────────────────────────────
  // Renders into either the full-screen #sales-chat OR the shared pop-out ChatDock
  // (KT.ChatDock — the same floating window every other role uses), plus a top-bar
  // 💬 icon with an unread indicator that flashes the dock on new messages.
  function seenId() { try { return parseInt(localStorage.getItem('kt_sales_chat_seen') || '0', 10) || 0; } catch (e) { return 0; } }
  /* Account-wide — see kt-markers.js. A numeric id, so the comparison is numeric on both
     sides: "10" is not less than "9" here, whatever string ordering would say. */
  function setSeen(id) {
    if (window.KT && KT.markers) { KT.markers.set('kt_sales_chat_seen', String(id)); paintChatBadge(0); return; }
    try { if (id > seenId()) { localStorage.setItem('kt_sales_chat_seen', String(id)); } } catch (e) {}
    paintChatBadge(0);
  }
  function paintChatBadge(n) {
    var b = document.getElementById('kt-sales-chat-badge'); if (!b) return;
    if (n > 0) { b.textContent = n > 99 ? '99+' : String(n); b.hidden = false; b.style.display = ''; }
    else { b.hidden = true; b.style.display = 'none'; }
  }
  function renderChatThread(intoEl, docked) {
    intoEl.innerHTML = '';
    var wrapper = el('div', { style: 'display:flex;flex-direction:column;min-height:0;' + (docked ? 'height:100%' : 'height:min(62vh,560px)') });
    var listBox = el('div', { style: 'flex:1 1 auto;min-height:0;overflow-y:auto;display:flex;flex-direction:column;gap:9px;background:#fff;padding:' + (docked ? '12px 12px 4px' : '14px') + ';' + (docked ? '' : 'border:1px solid #e6ebf1;border-radius:14px;') }, [el('div', { style: 'text-align:center;color:#94A3B8;padding:20px' }, ['Loading…'])]);
    var inputEl = input({ placeholder: 'Message the sales team…', style: 'flex:1;min-width:0;max-width:none !important;padding:10px 12px;border:1px solid #d9e1ea;border-radius:10px;font-size:14px;box-sizing:border-box' });
    var composer = el('form', { onsubmit: function (ev) { ev.preventDefault(); send(); }, style: 'flex:0 0 auto;display:flex;gap:8px;padding:' + (docked ? '10px 12px 12px' : '10px 0 0') }, [inputEl, btn('Send', 'primary', function () { send(); })]);
    wrapper.appendChild(listBox); wrapper.appendChild(composer);
    intoEl.appendChild(wrapper);
    var lastId = 0;
    function bubble(m) {
      return el('div', { style: 'display:flex;flex-direction:column;align-items:' + (m.mine ? 'flex-end' : 'flex-start') }, [
        el('div', { style: 'font-size:10.5px;color:#94A3B8;margin:0 5px 2px' }, [(m.mine ? 'You' : m.author) + ' · ' + fmtTime(m.at)]),
        el('div', { style: 'max-width:80%;padding:8px 12px;border-radius:14px;font-size:14px;line-height:1.35;word-break:break-word;' + (m.mine ? 'background:' + ACCENT + ';color:#fff;border-bottom-right-radius:5px' : 'background:#F1F5F9;color:#0D1B2A;border-bottom-left-radius:5px') }, [m.body]),
      ]);
    }
    function append(msgs) { msgs.forEach(function (m) { listBox.appendChild(bubble(m)); lastId = Math.max(lastId, m.id); }); listBox.scrollTop = listBox.scrollHeight; if (lastId) setSeen(lastId); }
    async function load() {
      try {
        var d = await Api.get('/sales/messages');
        clear(listBox);
        if (!d.messages.length) listBox.appendChild(el('div', { style: 'text-align:center;color:#94A3B8;padding:20px' }, ['No messages yet. Say hi 👋']));
        else append(d.messages);
      } catch (e) { clear(listBox); listBox.appendChild(el('div', { style: 'color:#DC2626' }, ['Could not load messages.'])); }
    }
    async function refresh() { if (!lastId) return; try { var d = await Api.get('/sales/messages', { since: lastId }); if (d.messages.length) append(d.messages); } catch (e) {} }
    async function send() { var t = inputEl.value.trim(); if (!t) return; inputEl.value = ''; try { var m = await Api.post('/sales/messages', { body: t }); append([m]); } catch (e) { toast('⚠️', 'Send failed', e.message || '', '#DC2626'); inputEl.value = t; } }
    load();
    var poll = setInterval(refresh, 5000);
    return function stop() { clearInterval(poll); };
  }
  async function renderChat(container) {
    clear(container);
    container.appendChild(hero('Messages', 'Your portal chat — the sales team and superadmins only.', '💬'));
    var wrapEl = el('div', {}); container.appendChild(wrap([wrapEl], 920));
    var stop = renderChatThread(wrapEl, false);
    var onLeave = function () { if (window.location.hash.indexOf('sales-chat') === -1) { stop(); window.removeEventListener('hashchange', onLeave); } };
    window.addEventListener('hashchange', onLeave);
  }
  var _dockStop = null;
  function openChatDock() {
    if (!salesAuthed()) return;
    if (KT.ChatDock && KT.ChatDock.enabled && KT.ChatDock.enabled()) {
      if (_dockStop) { try { _dockStop(); } catch (e) {} _dockStop = null; }
      _dockStop = renderChatThread(KT.ChatDock.contentEl(), true);
      KT.ChatDock.show('💬 Messages', function () { if (_dockStop) { _dockStop(); _dockStop = null; } });
    } else { go('sales-chat'); }   // phone / no dock → full screen
  }
  async function pollChatUnread() {
    if (!salesAuthed()) return;
    try {
      var since = seenId();
      var d = await Api.get('/sales/messages', since ? { since: since } : {});
      var msgs = d.messages || [];
      var maxId = msgs.reduce(function (a, m) { return Math.max(a, m.id); }, since);
      if (since === 0) { setSeen(maxId); return; }   // first run → treat history as read
      var unread = msgs.filter(function (m) { return !m.mine; }).length;
      paintChatBadge(unread);
      if (unread && KT.ChatDock && KT.ChatDock.isMinimized && KT.ChatDock.isMinimized()) KT.ChatDock.flashIncoming();
    } catch (e) {}
  }
  KT.SalesChat = { open: openChatDock, poll: pollChatUnread };
  if (salesAuthed()) { setTimeout(pollChatUnread, 2500); setInterval(pollChatUnread, 20000); }

  // ─────────────────────────────── Announcements (company / sales only) ───────────────────────────────
  async function renderNews(container) {
    clear(container);
    container.appendChild(hero('Announcements', 'Company & sales-team news only — nothing to do with agencies or centres.', '📣'));
    var host = el('div', {}); container.appendChild(wrap([host], 920));
    var tI, bI, pinI;
    var composer = card([
      el('div', { style: 'font-weight:800;margin-bottom:10px' }, ['📢 Post to the sales team']),
      field('Title', tI = input({ placeholder: 'e.g. New pricing effective Aug 1' })),
      el('div', { style: 'margin-top:10px' }, [field('Message', bI = textarea({ placeholder: 'Share company or sales news with the team…' }))]),
      el('div', { style: 'display:flex;gap:12px;align-items:center;margin-top:12px' }, [
        el('label', { style: 'display:flex;align-items:center;gap:6px;font-size:13px;color:#475569;cursor:pointer' }, [pinI = el('input', { type: 'checkbox' }), '📌 Pin to top']),
        el('div', { style: 'flex:1' }),
        btn('Post', 'primary', async function (ev) {
          if (!tI.value.trim() || !bI.value.trim()) { toast('⚠️', 'Title and message required', '', '#DC2626'); return; }
          ev.target.disabled = true;
          try { await Api.post('/sales/announcements', { title: tI.value.trim(), body: bI.value.trim(), pinned: pinI.checked }); toast('✅', 'Posted'); load(); } catch (e) { toast('⚠️', 'Failed', e.message || '', '#DC2626'); ev.target.disabled = false; }
        }),
      ]),
    ], 'margin-bottom:14px');
    host.appendChild(composer);
    var listHost = el('div'); host.appendChild(listHost);
    async function load() {
      tI.value = ''; bI.value = ''; if (pinI) pinI.checked = false;
      clear(listHost); listHost.appendChild(el('div', { style: 'padding:16px;color:#64748B' }, ['Loading…']));
      var d;
      try { d = await Api.get('/sales/announcements'); } catch (e) { clear(listHost); listHost.appendChild(card([el('div', { style: 'color:#DC2626' }, ['Could not load.'])])); return; }
      clear(listHost);
      if (!d.announcements.length) { listHost.appendChild(card([el('div', { style: 'text-align:center;color:#64748B;padding:20px' }, ['No announcements yet.'])])); return; }
      d.announcements.forEach(function (a) {
        listHost.appendChild(card([
          el('div', { style: 'display:flex;justify-content:space-between;align-items:flex-start;gap:10px' }, [
            el('div', { style: 'font-weight:800;font-size:15px' }, [(a.pinned ? '📌 ' : '') + a.title]),
            (a.mine || salesAuthed()) ? el('button', { title: 'Delete', style: 'border:0;background:none;color:#94A3B8;cursor:pointer;font-size:15px;flex:0 0 auto', onclick: async function () { if (!(await window.KT.confirm({ title: 'Delete this announcement?', description: 'It is removed for everyone on the sales team.', okLabel: 'Delete' }))) return; try { await Api.delete('/sales/announcements/' + a.id); load(); } catch (e) { toast('⚠️', 'Failed', e.message || '', '#DC2626'); } } }, ['🗑']) : null,
          ]),
          el('div', { style: 'font-size:11.5px;color:#94A3B8;margin:3px 0 8px' }, [a.author + ' · ' + fmtTime(a.at)]),
          el('div', { style: 'font-size:14px;color:#334155;line-height:1.5;white-space:pre-wrap' }, [a.body]),
        ], 'margin-bottom:10px'));
      });
    }
    load();
  }

  // ─────────────────────────────── Home dashboard (charts + analytics) ───────────────────────────────
  function statCardsRow(st) {
    return el('div', { style: 'display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:12px;margin-bottom:14px' }, [
      statCard('🎯', 'Open leads', st.open || 0, '#EEF2FF', '#4338CA'),
      statCard('💰', 'Pipeline value', money(st.pipeline_value || 0), '#ECFDF5', '#047857'),
      statCard('🏆', 'Won', st.won || 0, '#F0FDF4', '#15803D'),
      statCard('📈', 'Won revenue', money(st.won_value || 0), '#ECFEFF', '#0E7490'),
      statCard('⏰', 'Follow-ups due', st.followups_due || 0, '#FFF7ED', '#C2410C'),
      statCard('🙋', 'My open', st.my_open || 0, '#FDF2F8', '#BE185D'),
    ]);
  }
  function funnelChart(leads) {
    var by = {}; STAGES.forEach(function (s) { by[s.key] = { count: 0, value: 0 }; });
    (leads || []).forEach(function (l) { if (by[l.stage]) { by[l.stage].count++; by[l.stage].value += Number(l.value || 0); } });
    var maxC = 1; STAGES.forEach(function (s) { maxC = Math.max(maxC, by[s.key].count); });
    var rows = STAGES.map(function (s) {
      var c = by[s.key];
      var pct = c.count ? Math.max(6, Math.round(c.count / maxC * 100)) : 0;
      return el('div', { style: 'display:flex;align-items:center;gap:10px;margin-bottom:9px' }, [
        el('div', { style: 'width:112px;flex:0 0 112px;font-size:12px;font-weight:700;color:' + s.color }, [s.label]),
        el('div', { style: 'flex:1;min-width:0;background:#F1F5F9;border-radius:8px;height:26px;overflow:hidden' }, [
          el('div', { style: 'height:100%;width:' + pct + '%;background:' + s.color + ';border-radius:8px' }),
        ]),
        el('div', { style: 'width:132px;flex:0 0 132px;text-align:right;font-size:12px;white-space:nowrap' }, [
          el('span', { style: 'font-weight:800' }, [String(c.count)]), el('span', { style: 'color:#94A3B8' }, [' · ']), el('span', { style: 'color:#047857;font-weight:700' }, [money(c.value)]),
        ]),
      ]);
    });
    return card([el('div', { style: 'font-weight:800;margin-bottom:12px' }, ['📊 Pipeline by stage'])].concat(rows));
  }
  function legendRow(color, label, val) {
    return el('div', { style: 'display:flex;align-items:center;gap:8px;margin-bottom:9px' }, [
      el('span', { style: 'width:11px;height:11px;border-radius:3px;background:' + color + ';flex:0 0 auto' }),
      el('span', { style: 'flex:1;font-size:13px;color:#475569' }, [label]),
      el('span', { style: 'font-weight:800;font-size:13.5px;color:#0D1B2A' }, [String(val)]),
    ]);
  }
  function winDonut(st) {
    var won = st.won || 0, lost = st.lost || 0, total = won + lost;
    var pct = total ? won / total : 0;
    var r = 54, circ = 2 * Math.PI * r, dash = (circ * pct).toFixed(1);
    var svg = '<svg width="140" height="140" viewBox="0 0 140 140" aria-hidden="true">'
      + '<circle cx="70" cy="70" r="' + r + '" fill="none" stroke="#F1F5F9" stroke-width="15"/>'
      + '<circle cx="70" cy="70" r="' + r + '" fill="none" stroke="#10B981" stroke-width="15" stroke-linecap="round" stroke-dasharray="' + dash + ' ' + circ.toFixed(1) + '" transform="rotate(-90 70 70)"/>'
      + '<text x="70" y="66" text-anchor="middle" font-size="28" font-weight="800" fill="#0D1B2A">' + (st.win_rate || 0) + '%</text>'
      + '<text x="70" y="87" text-anchor="middle" font-size="11" fill="#64748B">win rate</text></svg>';
    return card([
      el('div', { style: 'font-weight:800;margin-bottom:10px' }, ['🏆 Won vs lost']),
      el('div', { style: 'display:flex;align-items:center;gap:18px;flex-wrap:wrap' }, [
        el('div', { html: svg, style: 'flex:0 0 auto' }),
        el('div', { style: 'flex:1;min-width:130px' }, [
          legendRow('#10B981', 'Won', won),
          legendRow('#EF4444', 'Lost', lost),
          legendRow('#0E7490', 'Won revenue', money(st.won_value || 0)),
          legendRow('#6D28D9', 'Avg won deal', money(st.avg_won || 0)),
        ]),
      ]),
    ]);
  }
  /**
   * THE FILES REPOSITORY — superadmin only.
   *
   * Price sheets, comparison decks, contract templates. Folders down the side, the shelf
   * in the middle, and the search and sorting come from the platform: the table carries no
   * `data-kt-no-filter`, so kt-table-filter gives it a live filter box and sortable headers
   * the same way it does every other table in the portal. Writing a second search box here
   * would have been a second thing to keep working.
   */
  async function renderLibrary(container) {
    clear(container); ensureSalesCss();

    if (!isSuperAdmin()) {
      container.appendChild(el('div', { style: 'padding:48px 20px;text-align:center;color:#64748B' },
        ['🔒 The files repository is for superadmins.']));
      return;
    }

    container.appendChild(hero('Files repository',
      'Price sheets, decks and templates for the sales team. Superadmins only.', '📁'));

    var host = el('div', {});
    container.appendChild(host);
    var state = { folderId: null, folders: [], docs: [], categories: [] };
    busy();

    function busy() {
      clear(host);
      host.appendChild(el('div', { style: 'padding:30px;text-align:center;color:#94A3B8;font-size:13px' },
        ['Loading the repository…']));
    }

    function btnCss(bg, fg, br) {
      return 'padding:6px 12px;border-radius:8px;border:1px solid ' + br + ';background:' + bg
        + ';color:' + fg + ';font-size:12.5px;font-weight:700;cursor:pointer';
    }

    /* A REAL FILE ICON, the shape a desktop uses: a white sheet with a folded corner and
       a coloured tab carrying the extension. Emoji were close enough to read as decoration
       and not close enough to scan — three of the eight rendered as the same beige page on
       Windows, so a deck and a spreadsheet looked identical in the column meant to tell
       them apart.

       Drawn as inline SVG rather than fetched: the artifact CSP blocks outside images, an
       icon font is another thing to load before the shelf can be read, and a sheet of paper
       is eleven elements. Colours are the ones people already associate with the formats. */
    var FILE_COLOURS = {
      PDF: '#D93025', Word: '#2B579A', Spreadsheet: '#217346', Presentation: '#D24726',
      Image: '#7C3AED', Video: '#0EA5E9', Archive: '#B45309', Text: '#475569',
    };

    function iconFor(d, px) {
      var size = px || 34;
      var kind = String(d.kind || '');
      var colour = FILE_COLOURS[kind] || '#94A3B8';
      var ext = String(d.extension || '').toUpperCase().slice(0, 4);

      var ns = 'http://www.w3.org/2000/svg';
      var svg = document.createElementNS(ns, 'svg');
      svg.setAttribute('viewBox', '0 0 32 40');
      svg.setAttribute('width', String(size));
      svg.setAttribute('height', String(Math.round(size * 40 / 32)));
      svg.setAttribute('aria-hidden', 'true');
      svg.style.cssText = 'flex:none;display:block;';

      function node(name, attrs) {
        var n = document.createElementNS(ns, name);
        Object.keys(attrs).forEach(function (k) { n.setAttribute(k, attrs[k]); });
        return n;
      }

      // the sheet, with the top-right corner cut away
      svg.appendChild(node('path', {
        d: 'M4 1.5h14.5L28 11v27.5H4z', fill: '#fff', stroke: '#CBD5E1', 'stroke-width': '1.4',
        'stroke-linejoin': 'round',
      }));
      // the fold
      svg.appendChild(node('path', {
        d: 'M18.5 1.5V11H28', fill: '#EEF2F7', stroke: '#CBD5E1', 'stroke-width': '1.4',
        'stroke-linejoin': 'round',
      }));
      // the coloured tab across the lower half
      svg.appendChild(node('rect', {
        x: '2', y: '21', width: '26', height: '13', rx: '2.5', fill: colour,
      }));
      if (ext) {
        var label = node('text', {
          x: '15', y: '30.4', 'text-anchor': 'middle', fill: '#fff',
          'font-size': ext.length > 3 ? '7' : '8.4',
          'font-weight': '800', 'font-family': 'system-ui,-apple-system,Segoe UI,Roboto,sans-serif',
          'letter-spacing': '.2',
        });
        label.textContent = ext;
        svg.appendChild(label);
      }
      return svg;
    }
    function sizeOf(d) {
      var b = Number(d.file_size || 0);
      if (!b) { return ''; }
      return b >= 1048576 ? (b / 1048576).toFixed(1) + ' MB' : Math.max(1, Math.round(b / 1024)) + ' KB';
    }
    /* Date AND time. "Added 2026-09-23" cannot tell two versions of the same price sheet
       apart when both landed the same morning, which is exactly when it matters. */
    function stampOf(d) {
      var raw = String(d.uploaded_at || '');
      if (!raw) { return { date: '—', time: '', sort: '' }; }
      var iso = raw.replace(' ', 'T');
      if (!/(Z|[+-]\d{2}:?\d{2})$/.test(iso)) { iso += 'Z'; }   // server stamps are UTC
      var dt = new Date(iso);
      if (isNaN(dt.getTime())) { return { date: raw.slice(0, 10), time: raw.slice(11, 16), sort: raw }; }
      return {
        date: dt.toLocaleDateString(undefined, { year: 'numeric', month: 'short', day: 'numeric' }),
        time: dt.toLocaleTimeString(undefined, { hour: 'numeric', minute: '2-digit' }),
        sort: String(dt.getTime()),
      };
    }

    async function reload() {
      busy();
      var data;
      try { data = await Api.get('/sales/library'); }
      catch (e) {
        clear(host);
        host.appendChild(card([el('div', { style: 'color:#DC2626' }, ['Could not load: ' + (e.message || e)])]));
        return;
      }
      state.folders = data.folders || [];
      state.docs = data.documents || [];
      state.categories = data.categories || [];
      paint();
    }

    function paint() {
      clear(host);

      var shown = state.folderId === null
        ? state.docs
        : state.docs.filter(function (d) { return Number(d.folder_id) === Number(state.folderId); });

      // ── bar ────────────────────────────────────────────────────────────
      var addBtn = el('button', { type: 'button', style: btnCss('#0C6070', '#fff', '#0C6070') }, ['＋ Add a file']);
      addBtn.addEventListener('click', openUploadDialog);
      var newFolder = el('button', { type: 'button', style: 'margin-right:8px;' + btnCss('#fff', '#0F172A', '#CBD5E1') }, ['📁 New folder']);
      newFolder.addEventListener('click', createFolder);
      host.appendChild(el('div', { style: 'display:flex;align-items:center;gap:10px;flex-wrap:wrap;margin-bottom:12px' }, [
        el('div', { style: 'font-weight:800;color:#0F172A;font-size:14px' },
          [shown.length + ' file' + (shown.length === 1 ? '' : 's')
            + (state.folderId === null ? '' : ' in this folder')]),
        el('div', { style: 'flex:1' }, []),
        newFolder, addBtn,
      ]));

      // ── layout: folders | shelf ────────────────────────────────────────
      var grid = el('div', { style: 'display:grid;grid-template-columns:minmax(0,200px) minmax(0,1fr);gap:16px;align-items:start' }, []);
      grid.appendChild(folderRail());

      var right = el('div', { style: 'min-width:0' }, []);
      grid.appendChild(right);
      host.appendChild(grid);

      if (!shown.length) {
        right.appendChild(el('div', {
          style: 'background:#fff;border:1px dashed #CBD5E1;border-radius:12px;padding:40px 20px;'
            + 'text-align:center;color:#64748B;font-size:13.5px',
        }, [state.folderId === null
              ? 'Nothing here yet. Add the first price sheet or deck.'
              : 'This folder is empty. Add a file to it, or pick All files.']));
        return;
      }

      /* No data-kt-no-filter: the platform's own filter box and sortable headers attach
         to this table, so every column sorts and the search is the one people already
         know from every other table. data-kt-sort on a cell tells it what to sort BY —
         raw bytes and a timestamp, not "1.2 MB" and "Sep 23", which sort alphabetically. */
      var tbl = el('table', { style: 'width:100%;border-collapse:collapse;background:#fff;font-size:13.5px' });
      function th(label) {
        return el('th', {
          style: 'padding:10px 14px;font-size:11px;font-weight:800;color:#64748B;text-transform:uppercase;'
            + 'letter-spacing:.4px;border-bottom:1px solid #E2E8F0;white-space:nowrap;text-align:left',
        }, [label]);
      }
      tbl.appendChild(el('thead', {}, [el('tr', { style: 'background:#F8FAFC' }, [
        th('File'), th('Type'), th('Folder'), th('Category'), th('Size'), th('Added'), th(''),
      ])]));

      var tb = el('tbody', {});
      shown.forEach(function (d) {
        var st = stampOf(d);
        var open = el('button', { type: 'button', 'data-kt-iconized': '1', style: btnCss('#fff', '#0F172A', '#CBD5E1') }, ['View']);
        open.addEventListener('click', function (e) { e.stopPropagation(); openDoc(d); });
        /* Each carries its own glyph: kt-row-actions picks a fallback icon from the
           label, and "Move to folder" hits its view/open rule and would come out as a
           second eye beside the real View. */
        /* DOWNLOAD (2026-10-09: "file repository - there is no option to download using the
           kebab"). The server route existed; the menu never offered it. */
        var dl = el('button', { type: 'button', 'data-kt-iconized': '1', style: 'margin-left:6px;' + btnCss('#fff', '#0F172A', '#CBD5E1') }, ['⬇️ Download']);
        dl.addEventListener('click', function (e) { e.stopPropagation(); downloadDoc(d, dl); });
        var move = el('button', { type: 'button', 'data-kt-iconized': '1', style: 'margin-left:6px;' + btnCss('#fff', '#0F172A', '#CBD5E1') }, ['📁 Move to folder']);
        move.addEventListener('click', function (e) { e.stopPropagation(); moveDoc(d); });

        var unfile = null;
        if (d.folder_id) {
          unfile = el('button', { type: 'button', 'data-kt-iconized': '1', style: 'margin-left:6px;' + btnCss('#fff', '#0F172A', '#CBD5E1') }, ['↩ Remove from folder']);
          unfile.addEventListener('click', function (e) { e.stopPropagation(); setFolder(d, null); });
        }

        var del = el('button', { type: 'button', 'data-kt-iconized': '1', style: 'margin-left:6px;' + btnCss('#fff', '#B91C1C', '#FCA5A5') }, ['🗑 Remove']);
        del.addEventListener('click', function (e) { e.stopPropagation(); removeDoc(d); });

        function td(kids, extra, sortKey) {
          var a = { style: 'padding:12px 14px;vertical-align:top;' + (extra || '') };
          if (sortKey !== undefined) { a['data-kt-sort'] = String(sortKey); }
          return el('td', a, kids);
        }

        var nameCell = [el('div', { style: 'display:flex;gap:10px;align-items:flex-start' }, [
          iconFor(d, 30),
          el('div', { style: 'min-width:0' }, [
            // The real name, extension and all.
            el('div', { style: 'font-weight:700;color:#0F172A;word-break:break-word' }, [d.file_name || d.title || 'Document']),
            (d.title && d.file_name && d.title !== d.file_name)
              ? el('div', { style: 'color:#64748B;font-size:12px;margin-top:2px' }, [d.title])
              : el('span', {}, []),
            d.notes
              ? el('div', { style: 'color:#94A3B8;font-size:12px;margin-top:3px;line-height:1.45;white-space:pre-wrap' }, [d.notes])
              : el('span', {}, []),
          ]),
        ])];

        tb.appendChild(el('tr', { style: 'border-bottom:1px solid #F1F5F9' }, [
          td(nameCell, '', String(d.file_name || d.title || '').toLowerCase()),
          /* The EXTENSION first: that is what somebody scanning this column is looking
             for, with the human word beneath it. */
          td([el('div', { style: 'display:flex;flex-direction:column;gap:3px;align-items:flex-start' }, [
            el('span', {
              style: 'display:inline-block;background:' + (FILE_COLOURS[String(d.kind || '')] || '#94A3B8')
                + ';color:#fff;border-radius:6px;padding:2px 8px;font-size:11px;font-weight:800;'
                + 'letter-spacing:.6px;white-space:nowrap',
            }, [d.extension ? String(d.extension).toUpperCase() : '\u2014']),
            el('span', { style: 'color:#64748B;font-size:11.5px;white-space:nowrap' }, [d.kind || 'File']),
          ])], 'white-space:nowrap', String(d.extension || d.kind || '')),
          td([d.folder
                ? el('span', { style: 'color:#334155' }, ['📁 ' + d.folder])
                : el('span', { style: 'color:#CBD5E1' }, ['—'])], 'white-space:nowrap', String(d.folder || '')),
          td([d.category
                ? el('span', {
                    style: 'display:inline-block;background:#EFF6FF;color:#1D4ED8;border:1px solid #BFDBFE;'
                      + 'border-radius:999px;padding:2px 10px;font-size:12px;font-weight:700;white-space:nowrap',
                  }, [d.category])
                : el('span', { style: 'color:#CBD5E1' }, ['—'])], 'white-space:nowrap', String(d.category || '')),
          // Sorted on raw bytes, shown as KB/MB.
          td([el('span', { style: 'color:#334155' }, [sizeOf(d) || '—'])], 'white-space:nowrap',
             String(Number(d.file_size || 0)).padStart(12, '0')),
          td([el('div', { style: 'color:#334155' }, [st.date + (st.time ? ' · ' + st.time : '')]),
              el('div', { style: 'color:#94A3B8;font-size:11.5px;margin-top:2px' }, [d.uploaded_by || ''])],
             'white-space:nowrap', st.sort),
          td(unfile ? [open, dl, move, unfile, del] : [open, dl, move, del], 'text-align:right;white-space:nowrap'),
        ]));
      });
      tbl.appendChild(tb);
      right.appendChild(el('div', {
        style: 'background:#fff;border:1px solid #e6ebf1;border-radius:12px;overflow:hidden',
      }, [el('div', { style: 'overflow-x:auto' }, [tbl])]));

      // The table was built after the screen's render returned, so both sweeps have run.
      if (window.KT && KT.enhanceTables) { setTimeout(KT.enhanceTables, 0); }
      if (window.KT && KT.sweepRowActions) { setTimeout(KT.sweepRowActions, 0); }
    }

    /**
     * THE FOLDER RAIL.
     *
     * Every row is the SAME four-column grid — icon, name, count, delete — so the counts
     * line up under each other and the crosses sit in one straight edge. It was a flex row
     * holding a width:100% button with the cross as a SIBLING: the button claimed the whole
     * width, the cross was squeezed by whatever was left, and "All files" (which has no
     * cross at all) ended at a different edge from every folder under it. Three different
     * reasons for the same crooked column.
     *
     * The empty fourth cell on All files is load-bearing: drop it and that row has three
     * columns while the others have four, which is the misalignment coming straight back.
     *
     * The row is a div rather than a button because a button cannot contain another button,
     * and the delete has to be its own control to carry its own label and its own click.
     * It takes the keyboard handling a button would have given it.
     */
    function folderRail() {
      var rail = el('div', { style: 'background:#fff;border:1px solid #e6ebf1;border-radius:12px;padding:6px;min-width:0' }, []);

      function row(icon, label, count, active, onClick, onDelete) {
        var r = el('div', {
          role: 'button',
          tabindex: '0',
          title: label,
          style: 'display:grid;grid-template-columns:18px minmax(0,1fr) auto 22px;align-items:center;'
            + 'column-gap:8px;padding:7px 8px;border-radius:9px;cursor:pointer;font-size:13px;'
            + 'font-weight:700;line-height:1.3;'
            + (active ? 'background:#E0F2FE;color:#075985;' : 'color:#334155;'),
        }, []);

        r.appendChild(el('span', { style: 'font-size:14px;line-height:1;text-align:center' }, [icon]));
        r.appendChild(el('span', {
          style: 'min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap',
        }, [label]));
        // Tabular figures so 1 and 11 occupy the same width and the column stays straight.
        r.appendChild(el('span', {
          style: 'color:#94A3B8;font-weight:600;font-variant-numeric:tabular-nums;font-size:12.5px',
        }, [String(count)]));

        if (onDelete) {
          var x = el('button', {
            type: 'button',
            title: 'Delete folder',
            'aria-label': 'Delete folder',
            style: 'border:0;background:transparent;color:#94A3B8;cursor:pointer;font-size:12px;'
              + 'line-height:1;padding:3px;border-radius:5px;width:22px;height:22px;',
          }, ['✕']);
          x.addEventListener('click', function (e) { e.stopPropagation(); onDelete(); });
          x.addEventListener('mouseenter', function () { x.style.background = '#FEE2E2'; x.style.color = '#B91C1C'; });
          x.addEventListener('mouseleave', function () { x.style.background = 'transparent'; x.style.color = '#94A3B8'; });
          r.appendChild(x);
        } else {
          r.appendChild(el('span', {}, []));      // keeps the grid four columns wide
        }

        r.addEventListener('click', onClick);
        r.addEventListener('keydown', function (e) {
          if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); onClick(); }
        });
        if (!active) {
          r.addEventListener('mouseenter', function () { r.style.background = '#F8FAFC'; });
          r.addEventListener('mouseleave', function () { r.style.background = 'transparent'; });
        }
        return r;
      }

      rail.appendChild(row('🗂', 'All files', state.docs.length, state.folderId === null,
        function () { state.folderId = null; paint(); }, null));

      if (!state.folders.length) {
        rail.appendChild(el('div', {
          style: 'color:#94A3B8;font-size:12px;padding:8px 10px;line-height:1.5',
        }, ['No folders yet. Use New folder to group price sheets, decks or contracts.']));
      }

      state.folders.forEach(function (f) {
        /* The icon is its OWN cell, not part of the label — glued to the front of the text
           it pushed every folder name one glyph right of "All files". */
        rail.appendChild(row('📁', f.name, f.count, Number(state.folderId) === Number(f.id),
          function () { state.folderId = f.id; paint(); },
          function () { deleteFolder(f); }));
      });
      return rail;
    }

    async function createFolder() {
      var name = null;
      try {
        if (KT.prompt) {
          // One field resolves a STRING, and the field's key is `key`.
          name = await KT.prompt({ title: 'New folder', okLabel: 'Create',
            fields: [{ key: 'name', label: 'Folder name', placeholder: 'e.g. Contracts' }] });
        }
      } catch (e) { name = null; }
      if (!name || !String(name).trim()) { return; }
      try {
        var res = await KT.Api.post('/sales/library/folders', { name: String(name).trim() });
        toast('📁', 'Folder', (res && res.message) || 'Created.');
        reload();
      } catch (e) { toast('⚠️', 'Could not create', e.message || '', '#DC2626'); }
    }

    async function deleteFolder(f) {
      var ok = true;
      try {
        if (KT.confirm) {
          ok = await KT.confirm({
            title: 'Delete the folder “' + f.name + '”?',
            description: f.count
              ? ('The ' + f.count + ' file' + (f.count === 1 ? '' : 's') + ' inside move back to All files. Nothing is deleted.')
              : 'The folder is empty.',
            okLabel: 'Delete folder',
          });
        }
      } catch (e) { ok = false; }
      if (!ok) { return; }
      try {
        var res = await Api.del('/sales/library/folders/' + f.id);
        toast('🗑', 'Folder deleted', (res && res.message) || '');
        if (Number(state.folderId) === Number(f.id)) { state.folderId = null; }
        reload();
      } catch (e) { toast('⚠️', 'Could not delete', e.message || '', '#DC2626'); }
    }

    /* Signed, bearer-free and short-lived, so it can be handed to the real browser —
       the packaged app cannot open a /storage path and cannot carry the session out. */
    /* Signed-in fetch of /sales/library/{id}/download, saved under the file's own name.
       The anchor+blob save is the pattern kt-doc-viewer's native net hands to the Android
       app's downloader, so it works in the APK too. */
    async function downloadDoc(d, button) {
      var name = String(d.file_name || d.title || ('file-' + d.id)).replace(/[\\/:*?"<>|]+/g, '-');
      if (d.extension && !new RegExp('\\.' + d.extension + '$', 'i').test(name)) { name += '.' + String(d.extension).toLowerCase(); }
      var base = (window.KT && KT.API_BASE) || 'https://api.kiddietrac.com/api/v1';
      var token = sessionStorage.getItem('kt_token') || localStorage.getItem('kt_token');
      var agency = ''; try { agency = sessionStorage.getItem('kt_active_agency_id') || ''; } catch (e) {}
      if (button) { button.disabled = true; }
      try {
        var res = await fetch(base + '/sales/library/' + d.id + '/download', {
          headers: { 'Authorization': 'Bearer ' + token, 'X-Active-Agency-Id': agency },
        });
        if (!res.ok) { throw new Error(res.status === 404 ? 'The file is missing from storage.' : 'HTTP ' + res.status); }
        var blob = await res.blob();
        var u = URL.createObjectURL(blob);
        var a = document.createElement('a'); a.href = u; a.download = name;
        document.body.appendChild(a); a.click(); a.remove();
        setTimeout(function () { URL.revokeObjectURL(u); }, 4000);
        toast('⬇️', 'Downloading', name);
      } catch (e) {
        toast('⚠️', 'Could not download', e.message || '', '#DC2626');
      } finally { if (button) { button.disabled = false; } }
    }

    function openDoc(d) {
      if (!d.open_url) { toast('⚠️', 'Unavailable', 'That file has no link.', '#DC2626'); return; }
      /* In the portal's viewer, not a new window (2026-10-09, Anthony: "when viewing file in
         file repository it should be a popup and not a new window"). */
      if (window.KT && KT.openFile) { KT.openFile(d.open_url, { title: d.title || d.file_name, filename: d.file_name || d.title, label: 'Files repository', mime: d.mime_type || d.mime || '' }); return; }
      window.open(d.open_url, '_blank', 'noopener');
    }

    /* ONE writer for both directions: the server takes folder_id and null means the
       root, so "remove from folder" is "move" with nothing chosen. */
    async function setFolder(d, folderId) {
      try {
        var res = await KT.Api.patch('/sales/library/' + d.id, { folder_id: folderId });
        toast('📁', 'Moved', (res && res.message) || '');
        reload();
      } catch (e) { toast('⚠️', 'Could not move', e.message || '', '#DC2626'); }
    }

    function moveDoc(d) {
      var M = window.KT && KT.Shell && KT.Shell.Modal;
      if (!M) { return; }
      if (!state.folders.length) {
        toast('📁', 'No folders yet', 'Use New folder first, then move files into it.');
        return;
      }
      var box = document.createElement('div');
      box.innerHTML =
        '<div style="font-size:13.5px;color:#475569;margin-bottom:10px;">Where should '
        + '<strong>' + esc(d.file_name || d.title || 'this file') + '</strong> live?</div>'
        + '<select class="mv-folder" style="width:100%;box-sizing:border-box;padding:9px 11px;'
        +   'border:1px solid #CBD5E1;border-radius:9px;font-size:13.5px;background:#fff;">'
        +   '<option value="">All files (no folder)</option>'
        +   state.folders.map(function (f) {
              return '<option value="' + esc(String(f.id)) + '"'
                + (Number(d.folder_id) === Number(f.id) ? ' selected' : '') + '>'
                + esc(f.name) + '</option>';
            }).join('')
        + '</select>';
      M.open({
        title: '📁 Move file',
        body: box,
        actions: [
          { label: 'Cancel' },
          {
            label: 'Move',
            primary: true,
            busyLabel: 'Moving…',
            handler: function () {
              var v = box.querySelector('.mv-folder').value;
              return setFolder(d, v ? Number(v) : null);
            },
          },
        ],
      });
    }

    async function removeDoc(d) {
      var ok = true;
      try {
        if (KT.confirm) {
          ok = await KT.confirm({
            title: 'Remove this file?',
            description: '“' + (d.file_name || d.title || 'This file') + '” stops being listed. The file itself is kept.',
            okLabel: 'Remove',
          });
        }
      } catch (e) { ok = false; }
      if (!ok) { return; }
      try {
        await Api.del('/sales/library/' + d.id);
        toast('🗑', 'Removed', d.file_name || d.title || '');
        reload();
      } catch (e) { toast('⚠️', 'Could not remove', e.message || '', '#DC2626'); }
    }

    /**
     * ADD A FILE, in a dialog. The progress bar is not this screen's job — every upload
     * in the portal draws one, from kt-upload-progress.js.
     */
    function openUploadDialog() {
      var M = window.KT && KT.Shell && KT.Shell.Modal;
      if (!M) { toast('⚠️', 'Unavailable', 'This dialog could not open.', '#DC2626'); return; }

      var INPUT = 'width:100%;box-sizing:border-box;padding:9px 11px;border:1px solid #CBD5E1;'
        + 'border-radius:9px;font-size:13.5px;background:#fff;';
      function field(label, hint, controlHtml) {
        return '<label style="display:block">'
          + '<span style="display:block;font-size:12px;font-weight:800;color:#334155;'
          +   'text-transform:uppercase;letter-spacing:.4px;margin-bottom:5px;">' + label + '</span>'
          + controlHtml
          + (hint ? '<span style="display:block;color:#94A3B8;font-size:11.5px;margin-top:4px;">' + hint + '</span>' : '')
          + '</label>';
      }
      var folderOpts = '<option value="">All files (no folder)</option>'
        + state.folders.map(function (f) {
            return '<option value="' + esc(String(f.id)) + '"'
              + (Number(state.folderId) === Number(f.id) ? ' selected' : '') + '>' + esc(f.name) + '</option>';
          }).join('');

      var form = document.createElement('div');
      form.innerHTML =
        field('Files', 'PDF, deck, sheet or image — up to 25 MB each. Pick several at once with Ctrl / Shift (⌘ on a Mac).',
              '<input type="file" multiple class="sl-file" style="' + INPUT + 'padding:8px 10px;">')
        + '<div class="sl-picked" style="display:none;margin-top:8px;border:1px solid #E2E8F0;border-radius:9px;'
        +   'background:#FAFBFD;max-height:150px;overflow:auto;font-size:12.5px;"></div>'
        + '<div style="height:14px"></div>'
        + '<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(190px,1fr));gap:14px;">'
        +   field('Folder', 'Where it lives', '<select class="sl-folder" style="' + INPUT + '">' + folderOpts + '</select>')
        +   field('Title', 'Left blank, the file name is used',
                  '<input class="sl-title" placeholder="e.g. Price sheet 2026" style="' + INPUT + '">')
        +   field('Category', 'A label — e.g. Pricing, Decks',
                  '<select class="sl-cat-sel" style="' + INPUT + '">'
                  +   '<option value="">No category</option>'
                  +   state.categories.map(function (c) {
                        return '<option value="' + esc(c) + '">' + esc(c) + '</option>';
                      }).join('')
                  +   '<option value="__new">＋ New category…</option>'
                  + '</select>'
                  + '<input class="sl-cat-new" placeholder="New category name" '
                  +   'style="' + INPUT + 'margin-top:8px;display:none;">')
        + '</div>'
        + '<div style="height:14px"></div>'
        + field('Notes', 'What it is for, and who it is aimed at',
                '<textarea class="sl-notes" rows="3" style="' + INPUT + 'resize:vertical;"></textarea>')
        + '<div class="sl-err" style="color:#B91C1C;font-size:12.5px;margin-top:10px;"></div>';

      /* THE FILE NAME CARRIES THE NAMING CONVENTION, so lift it into Title rather than
         make somebody retype it. Only while Title is untouched: once it has been edited by
         hand, choosing a different file must not throw that away. The extension is left
         off, because it is already shown beside the name and a title reading
         "x.pdf.pdf" is the kind of thing nobody notices until it is everywhere. */
      /* SEVERAL FILES AT ONCE (2026-10-09, Anthony: "for file repository add ability to
         select multiple files for upload"). With more than one chosen, each keeps its own
         name as its title (one typed title cannot fit them all), while folder, category and
         notes apply to every file. They go up one after another; any that fail stay in the
         list with the reason, so "Add" again retries just those. */
      var titleEl = form.querySelector('.sl-title');
      var titleTouched = false;
      var pending = [];          // [{ file, error }]
      var pickedBox = form.querySelector('.sl-picked');
      function baseName(n) { return String(n || '').replace(/\.[a-z0-9]{1,8}$/i, ''); }
      function drawPicked() {
        pickedBox.innerHTML = '';
        pickedBox.style.display = pending.length ? '' : 'none';
        pending.forEach(function (p, i) {
          var big = p.file.size > 25 * 1024 * 1024;
          var row = document.createElement('div');
          row.style.cssText = 'display:flex;align-items:center;gap:8px;padding:5px 10px;border-top:' + (i ? '1px solid #EEF2F6' : '0');
          row.innerHTML = '<span style="flex:1;min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;color:#0F172A;">'
            + esc(fileIcon(p.file.name) + ' ' + p.file.name) + '</span>'
            + '<span style="color:' + (big ? '#B91C1C' : '#94A3B8') + ';">' + esc(big ? 'over 25 MB' : fmtBytes(p.file.size)) + '</span>'
            + (p.error ? '<span style="color:#B91C1C;max-width:45%;">' + esc(p.error) + '</span>' : '')
            + '<button type="button" data-rm="' + i + '" aria-label="Remove" style="border:0;background:none;color:#94A3B8;cursor:pointer;font-size:15px;line-height:1;padding:0 2px;">×</button>';
          pickedBox.appendChild(row);
        });
        pickedBox.querySelectorAll('[data-rm]').forEach(function (b) {
          b.addEventListener('click', function () { pending.splice(+b.getAttribute('data-rm'), 1); drawPicked(); });
        });
        var many = pending.length > 1;
        titleEl.disabled = many;
        titleEl.placeholder = many ? 'Each file keeps its own name' : 'e.g. Price sheet 2026';
        if (many) { titleEl.value = ''; }
        else if (pending.length === 1 && !titleTouched) { titleEl.value = baseName(pending[0].file.name); }
      }
      titleEl.addEventListener('input', function () { titleTouched = true; });
      form.querySelector('.sl-file').addEventListener('change', function (ev) {
        /* A second pick ADDS to the list (so files from two folders can go up together);
           the same file chosen twice is kept once. */
        Array.prototype.forEach.call(ev.target.files || [], function (f) {
          var dup = pending.some(function (p) { return p.file.name === f.name && p.file.size === f.size && p.file.lastModified === f.lastModified; });
          if (!dup) { pending.push({ file: f, error: '' }); }
        });
        ev.target.value = '';
        drawPicked();
      });

      /* "New category" reveals the free-text box; everything else picks an existing one. */
      var catSel = form.querySelector('.sl-cat-sel');
      var catNew = form.querySelector('.sl-cat-new');
      catSel.addEventListener('change', function () {
        var isNew = catSel.value === '__new';
        catNew.style.display = isNew ? '' : 'none';
        if (isNew) { catNew.focus(); }
      });

      M.open({
        title: '📁 Add a file to the repository',
        body: form,
        large: true,
        actions: [
          { label: 'Cancel' },
          {
            label: 'Add to repository',
            primary: true,
            busyLabel: 'Uploading…',
            handler: function () {
              var err = form.querySelector('.sl-err');
              err.textContent = '';
              if (!pending.length) { err.textContent = 'Choose a file.'; return false; }
              var tooBig = pending.filter(function (p) { return p.file.size > 25 * 1024 * 1024; });
              if (tooBig.length) {
                err.textContent = (tooBig.length === 1 ? tooBig[0].file.name + ' is' : tooBig.length + ' files are')
                  + ' larger than 25 MB — remove ' + (tooBig.length === 1 ? 'it' : 'them') + ' with ×.';
                return false;
              }
              var catSel = form.querySelector('.sl-cat-sel');
              var category = catSel.value === '__new' ? (form.querySelector('.sl-cat-new').value || '') : (catSel.value || '');
              var notes = form.querySelector('.sl-notes').value || '';
              var folderId = form.querySelector('.sl-folder').value || '';
              var single = pending.length === 1;

              return (async function () {
                var done = 0, failed = [];
                for (var i = 0; i < pending.length; i++) {
                  var p = pending[i];
                  if (!single) { err.style.color = '#475569'; err.textContent = 'Uploading ' + (i + 1) + ' of ' + pending.length + ' — ' + p.file.name; }
                  var fd = new FormData();
                  fd.append('file', p.file);
                  fd.append('title', single ? (titleEl.value || '') : '');
                  fd.append('category', category);
                  fd.append('notes', notes);
                  fd.append('folder_id', folderId);
                  try { await KT.Api.postForm('/sales/library', fd); done++; }
                  catch (e) { p.error = (e && e.message) || 'Could not upload'; failed.push(p); }
                }
                err.style.color = '#B91C1C';
                if (done) { reload(); }
                if (!failed.length) {
                  toast('📁', done === 1 ? 'Added' : done + ' files added', single ? pending[0].file.name : '');
                  return;
                }
                pending = failed;
                drawPicked();
                err.textContent = (done ? done + ' added. ' : '') + failed.length + (failed.length === 1 ? ' file' : ' files')
                  + ' could not be uploaded — shown above. Add again to retry.';
                return false;
              })();
            },
          },
        ],
      });
    }

    reload();
  }

  function salesTiles() {
    var tiles = [
      { hash: 'sales', icon: '📊', label: 'Pipeline' }, { hash: 'sales-webchat', icon: '💬', label: 'Website chat' },
      { hash: 'sales-leads', icon: '🎯', label: 'Leads' },
      { hash: 'sales-followups', icon: '⏰', label: 'Follow-ups' }, { hash: 'sales-plans', icon: '💲', label: 'Plans & pricing' },
      { hash: 'sales-demo', icon: '🚀', label: 'Launch demo' },
      { hash: 'notifications', icon: '🔔', label: 'Inbox' }, { hash: 'help', icon: '📖', label: 'Help' },
    ];
    /* Superadmin only. A sales rep never sees the tile, and never gets the 403
       that would follow if they did. */
    if (isSuperAdmin()) {
      tiles.splice(5, 0, { hash: 'sales-library', icon: '📁', label: 'Files repository' });
    }
    var grid = el('div', { class: 'kt-tile-grid' }, tiles.map(function (t) {
      return el('a', { class: 'kt-tile', href: '#' + t.hash }, [el('span', { class: 'kt-tile-icon', 'aria-hidden': 'true' }, [t.icon]), el('span', { class: 'kt-tile-label' }, [t.label])]);
    }));
    return el('div', { style: 'margin-top:18px' }, [el('div', { style: 'font-size:12px;font-weight:800;letter-spacing:.5px;text-transform:uppercase;color:#0C6070;margin:6px 2px 12px' }, ['Quick access']), grid]);
  }
  async function renderSalesHome(container) {
    clear(container); ensureSalesCss();
    var u = me();
    var greet = (KT.greetingForNow ? KT.greetingForNow((u.first_name || '')) : ('Welcome' + (u.first_name ? ', ' + u.first_name : '')));
    container.appendChild(hero(greet, 'Your sales command centre — pipeline, wins and what needs attention.', '📊'));
    var loading = el('div', { style: 'padding:26px;text-align:center;color:#64748B' }, ['Loading your numbers…']);
    container.appendChild(loading);
    var data;
    try { data = await Api.get('/sales/leads'); }
    catch (e) { loading.remove(); container.appendChild(card([el('div', { style: 'color:#DC2626' }, ['Could not load: ' + (e.message || e)])])); container.appendChild(salesTiles()); return; }
    META = { owners: data.owners || [], products: data.products || [], stats: data.stats || {} };
    loading.remove();
    var st = data.stats || {}, leads = data.leads || [];
    container.appendChild(statCardsRow(st));
    container.appendChild(el('div', { style: 'display:grid;grid-template-columns:repeat(auto-fit,minmax(320px,1fr));gap:14px;align-items:start' }, [funnelChart(leads), winDonut(st)]));
    container.appendChild(salesTiles());
  }

  // ─────────────────────────────── Website chat (live) ───────────────────────────────
  /* Visitors on kiddietrac.com, answered by a person. The page polls
     /marketing-site/chat/poll for what is said here; this screen polls
     /sales/web-chats for what they say. Maya (the scripted assistant on the page)
     covers when nobody is available or nobody picks up, and stops the moment
     somebody here replies or presses Join. Backend: WebChatController. */
  function ensureWebChatCss() {
    if (document.getElementById('kt-webchat-css')) return;
    var s = document.createElement('style');
    s.id = 'kt-webchat-css';
    s.textContent =
      '.kt-wc{display:grid;grid-template-columns:minmax(0,320px) minmax(0,1fr);gap:14px;align-items:stretch;min-height:0}' +
      '.kt-wc-list,.kt-wc-thread{background:var(--kt-card,#fff);border:1px solid var(--kt-border,#e6ebf1);border-radius:16px;min-width:0;display:flex;flex-direction:column;overflow:hidden}' +
      '.kt-wc-list{height:min(70vh,640px)}.kt-wc-thread{height:min(70vh,640px)}' +
      '.kt-wc-rows{flex:1 1 auto;overflow-y:auto;min-height:0}' +
      '.kt-wc-row{display:flex;gap:10px;align-items:flex-start;padding:11px 13px;border-bottom:1px solid #F1F5F9;cursor:pointer;min-width:0}' +
      '.kt-wc-row:hover{background:#F8FAFC}.kt-wc-row.on{background:#EEF6F9}' +
      '.kt-wc-av{flex:0 0 32px;width:32px;height:32px;border-radius:50%;background:#E2EEF3;color:#1F6080;font-weight:800;font-size:13px;display:flex;align-items:center;justify-content:center;position:relative}' +
      '.kt-wc-av i{position:absolute;right:-1px;bottom:-1px;width:10px;height:10px;border-radius:50%;background:#22C55E;border:2px solid #fff}' +
      '.kt-wc-name{font-weight:800;font-size:13.5px;color:#0F172A;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}' +
      '.kt-wc-prev{font-size:12.5px;color:#64748B;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;margin-top:2px}' +
      '.kt-wc-chip{display:inline-block;padding:1px 7px;border-radius:999px;font-size:10.5px;font-weight:800;margin-right:4px}' +
      '.kt-wc-msgs{flex:1 1 auto;overflow-y:auto;min-height:0;padding:14px;display:flex;flex-direction:column;gap:8px;background:#FBFCFD}' +
      '.kt-wc-m{display:flex;flex-direction:column;max-width:78%}.kt-wc-m.l{align-self:flex-start;align-items:flex-start}.kt-wc-m.r{align-self:flex-end;align-items:flex-end}' +
      '.kt-wc-who{font-size:10.5px;color:#94A3B8;margin:0 5px 2px;font-weight:700}' +
      '.kt-wc-b{padding:8px 12px;border-radius:14px;font-size:14px;line-height:1.4;white-space:pre-wrap;overflow-wrap:anywhere}' +
      '.kt-wc-sys{align-self:center;font-size:11.5px;color:#64748B;background:#F1F5F9;border-radius:999px;padding:3px 11px;text-align:center;max-width:90%}' +
      '.kt-wc-typing{font-size:12px;color:#64748B;padding:0 16px 6px;min-height:18px}' +
      '.kt-wc-comp{display:flex;gap:8px;padding:10px 12px;border-top:1px solid #EEF2F6;align-items:flex-end}' +
      '.kt-wc-comp textarea{flex:1;min-width:0;max-width:none !important;resize:none;height:40px;max-height:120px;padding:9px 11px;border:1px solid #d9e1ea;border-radius:10px;font:inherit;font-size:14px;box-sizing:border-box}' +
      '.kt-wc-head{display:flex;gap:10px;align-items:center;padding:11px 14px;border-bottom:1px solid #EEF2F6;flex-wrap:wrap;min-width:0}' +
      '.kt-wc-head .btn{padding:6px 11px !important;font-size:12.5px !important}' +
      '.kt-wc-sw{display:inline-flex;align-items:center;gap:8px;font-size:13px;font-weight:700;color:#334155;cursor:pointer;user-select:none}' +
      '.kt-wc-back{display:none}' +
      '@media (max-width:820px){.kt-wc{grid-template-columns:minmax(0,1fr)}.kt-wc.has-thread .kt-wc-list{display:none}.kt-wc:not(.has-thread) .kt-wc-thread{display:none}.kt-wc-back{display:inline-flex}.kt-wc-list,.kt-wc-thread{height:calc(100dvh - 250px);min-height:360px}.kt-wc-m{max-width:88%}}' +
      '.kt-wc-pop{position:fixed;right:18px;bottom:18px;z-index:2147480000;background:#fff;border:1px solid #E2E8F0;border-left:4px solid #1F6080;border-radius:12px;box-shadow:0 10px 30px rgba(15,23,42,.18);padding:11px 14px;max-width:320px;cursor:pointer;font-size:13px;color:#0F172A}' +
      '.kt-wc-pop b{display:block;font-size:13.5px;margin-bottom:2px}.kt-wc-pop span{color:#64748B;display:block;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}';
    document.head.appendChild(s);
  }
  function wcStamp(ts) {
    if (!ts) return '';
    if (window.KT && KT.fmtDateTime) return KT.fmtDateTime(ts);
    try { return new Date(ts).toLocaleString(undefined, { month: 'short', day: 'numeric', hour: 'numeric', minute: '2-digit' }); } catch (e) { return ''; }
  }
  function wcClock(ts) {
    if (!ts) return '';
    if (window.KT && KT.fmtTime) return KT.fmtTime(ts);
    try { return new Date(ts).toLocaleTimeString(undefined, { hour: 'numeric', minute: '2-digit' }); } catch (e) { return ''; }
  }
  function wcInitial(c) { var n = (c.name || c.email || '?').trim(); return n.charAt(0).toUpperCase(); }

  // ── The heartbeat: keeps this person "online" for the website, paints the nav
  //    badge, and pops a notice when a new visitor line arrives. Runs on every
  //    screen for sales users, not only on the inbox. ──
  var WC = { available: true, online: 0, unread: 0, seen: {}, first: true, listeners: [] };
  var _wcAudio = null;
  document.addEventListener('pointerdown', function () {
    if (_wcAudio) return;
    try { _wcAudio = new (window.AudioContext || window.webkitAudioContext)(); } catch (e) {}
  }, { once: true, capture: true });
  function wcChime() {
    try {
      if (!_wcAudio) return;
      var t = _wcAudio.currentTime;
      [880, 1175].forEach(function (f, i) {
        var o = _wcAudio.createOscillator(), g = _wcAudio.createGain();
        o.frequency.value = f; o.type = 'sine';
        g.gain.setValueAtTime(0.0001, t + i * 0.16); g.gain.exponentialRampToValueAtTime(0.18, t + i * 0.16 + 0.02);
        g.gain.exponentialRampToValueAtTime(0.0001, t + i * 0.16 + 0.3);
        o.connect(g); g.connect(_wcAudio.destination); o.start(t + i * 0.16); o.stop(t + i * 0.16 + 0.32);
      });
    } catch (e) {}
  }
  function wcPaintBadge() {
    var links = document.querySelectorAll('a.nav-link[data-hash="sales-webchat"], .kt-tile[href="#sales-webchat"]');
    Array.prototype.forEach.call(links, function (a) {
      var b = a.querySelector('.kt-wc-badge');
      if (!WC.unread) { if (b) b.remove(); return; }
      if (!b) { b = el('span', { class: 'kt-wc-badge', style: 'margin-left:auto;min-width:18px;height:18px;padding:0 5px;border-radius:999px;background:#DC2626;color:#fff;font-size:11px;font-weight:800;display:inline-flex;align-items:center;justify-content:center;line-height:1' }); a.appendChild(b); }
      b.textContent = WC.unread > 99 ? '99+' : String(WC.unread);
    });
  }
  function wcPop(w) {
    ensureWebChatCss();
    var old = document.querySelector('.kt-wc-pop'); if (old) old.remove();
    var p = el('div', { class: 'kt-wc-pop', role: 'status' }, [
      el('b', {}, [(w.wants_human ? '🙋 ' : '💬 ') + w.name + (w.wants_human ? ' wants to talk to a person' : ' — website chat')]),
      el('span', {}, [w.preview || '']),
    ]);
    p.addEventListener('click', function () { p.remove(); go('sales-webchat?id=' + w.id); });
    document.body.appendChild(p);
    setTimeout(function () { if (p.parentNode) p.remove(); }, 14000);
  }
  var _wcBusy = false;
  async function wcBeat(setAvail) {
    if (!salesAuthed() || _wcBusy) return;
    _wcBusy = true;
    try {
      var d = await Api.post('/sales/web-chats/presence', setAvail === undefined ? {} : { available: !!setAvail });
      WC.available = !!d.available; WC.online = d.online || 0; WC.unread = d.unread || 0;
      var onInbox = /^#sales-webchat/.test(window.location.hash || '');
      (d.waiting || []).forEach(function (w) {
        var key = w.id + ':' + w.latest;
        if (WC.seen[key]) return;
        WC.seen[key] = 1;
        // The first beat after sign-in reports what is already waiting once, as one
        // notice; after that, each new line gets its own.
        if (!WC.first && !onInbox) { wcPop(w); wcChime(); }
        else if (!WC.first && onInbox) { wcChime(); }
      });
      if (WC.first && (d.waiting || []).length && !onInbox) {
        var w0 = d.waiting[0];
        wcPop({ id: w0.id, name: d.waiting.length > 1 ? d.waiting.length + ' website chats' : w0.name, preview: d.waiting.length > 1 ? 'Visitors are waiting for a reply.' : w0.preview, wants_human: w0.wants_human });
      }
      WC.first = false;
      wcPaintBadge();
      WC.listeners.forEach(function (fn) { try { fn(WC); } catch (e) {} });
    } catch (e) {}
    _wcBusy = false;
  }
  function wcLoop() {
    wcBeat();
    // Hidden tabs are throttled by the browser anyway; asking less often there keeps
    // the agent counted as online without spending requests on a tab nobody watches.
    setTimeout(wcLoop, document.hidden ? 30000 : 10000);
  }
  if (salesAuthed()) { setTimeout(wcLoop, 3000); }
  window.addEventListener('hashchange', function () { setTimeout(wcPaintBadge, 300); });

  /* kt-icon-buttons turns short labels it recognises ("Open", "Add as lead") into bare
     icons; here the words are the point, so these buttons are marked as already done. */
  function wbtn(label, kind, on) { var b = btn(label, kind, on); b.dataset.ktIconized = '1'; return b; }
  async function renderWebChat(container) {
    clear(container); ensureWebChatCss();
    container.appendChild(hero('Website chat', 'Visitors on kiddietrac.com, live. When nobody here is available, Maya — the virtual assistant — answers and says so; replying or pressing Join hands the chat to you.', '💬'));

    var params = new URLSearchParams(((window.location.hash || '').split('?')[1]) || '');
    var state = { filter: 'open', chats: [], openId: parseInt(params.get('id') || '0', 10) || 0, lastId: 0, chat: null, dead: false };

    // ── toolbar: availability + filter ──
    var sw = el('input', { type: 'checkbox', 'data-kt-switch': '1' });
    sw.checked = WC.available;
    sw.addEventListener('change', function () { wcBeat(sw.checked); });
    var onlineTxt = el('span', { style: 'font-size:12.5px;color:#64748B' });
    function paintOnline() {
      sw.checked = WC.available;
      onlineTxt.textContent = WC.online ? ('● ' + WC.online + ' team member' + (WC.online === 1 ? '' : 's') + ' online for the website') : 'Nobody online — Maya is answering';
      onlineTxt.style.color = WC.online ? '#15803D' : '#B45309';
    }
    paintOnline();
    WC.listeners.push(paintOnline);
    var fOpen = wbtn('Open', 'primary', function () { setFilter('open'); });
    var fClosed = wbtn('Closed', 'ghost', function () { setFilter('closed'); });
    function setFilter(f) {
      state.filter = f;
      // Set the three properties directly: cssText comes back normalised ("background: rgb(…)"),
      // so a string replace on it would silently match nothing.
      [[fOpen, 'open'], [fClosed, 'closed']].forEach(function (p) {
        var on = f === p[1];
        p[0].style.background = on ? ACCENT : '#F1F5F9';
        p[0].style.color = on ? '#fff' : '#334155';
        p[0].style.border = '1px solid ' + (on ? ACCENT : '#E2E8F0');
      });
      state.openId = 0; state.threadFor = 0; grid.classList.remove('has-thread');
      loadList();
    }
    container.appendChild(el('div', { style: 'display:flex;gap:12px;align-items:center;flex-wrap:wrap;margin:0 0 14px' }, [
      el('label', { class: 'kt-wc-sw' }, [sw, 'Available for website chat']),
      onlineTxt,
      el('div', { style: 'flex:1' }),
      el('div', { class: 'kt-segmented', style: 'display:inline-flex;gap:6px' }, [fOpen, fClosed]),
    ]));

    // ── layout ──
    var rows = el('div', { class: 'kt-wc-rows' });
    var list = el('div', { class: 'kt-wc-list' }, [rows]);
    var thread = el('div', { class: 'kt-wc-thread' });
    // Polls itself in place: opt out of the shell's timed and write-triggered re-renders,
    // which would rebuild the screen under a half-typed reply (kt-auto-refresh, kt-live).
    var grid = el('div', { class: 'kt-wc', 'data-kt-self-live': '1', 'data-kt-no-autorefresh': '1', 'data-kt-no-tips': '1' }, [list, thread]);
    container.appendChild(grid);

    function alive() { return !state.dead && document.body.contains(grid); }

    function paintList() {
      clear(rows);
      if (!state.chats.length) {
        rows.appendChild(el('div', { style: 'padding:26px 16px;text-align:center;color:#94A3B8;font-size:13.5px' }, [state.filter === 'open' ? 'No open website chats. New ones appear here the moment a visitor writes.' : 'No closed chats yet.']));
        return;
      }
      state.chats.forEach(function (c) {
        var by = c.preview_by === 'bot' ? 'Maya: ' : (c.preview_by === 'agent' ? (c.mine ? 'You: ' : (c.agent_name || 'Team') + ': ') : (c.preview_by === 'system' ? '' : ''));
        var chips = [];
        if (c.wants_human && !c.agent_id && c.status === 'open') chips.push(el('span', { class: 'kt-wc-chip', style: 'background:#FEF3C7;color:#92400E' }, ['Wants a person']));
        if (c.agent_id) chips.push(el('span', { class: 'kt-wc-chip', style: 'background:#DCFCE7;color:#166534' }, [c.mine ? 'You' : c.agent_name]));
        else if (c.status === 'open') chips.push(el('span', { class: 'kt-wc-chip', style: 'background:#EDE9FE;color:#5B21B6' }, ['With Maya']));
        if (c.lead_id) chips.push(el('span', { class: 'kt-wc-chip', style: 'background:#E0F2FE;color:#075985' }, ['Lead']));
        var av = el('div', { class: 'kt-wc-av', title: c.present ? 'On the website now' : '' }, [wcInitial(c)]);
        if (c.present) av.appendChild(el('i'));
        var row = el('div', { class: 'kt-wc-row' + (c.id === state.openId ? ' on' : '') }, [
          av,
          el('div', { style: 'flex:1;min-width:0' }, [
            el('div', { style: 'display:flex;gap:6px;align-items:baseline;min-width:0' }, [
              el('div', { class: 'kt-wc-name', style: 'flex:1;min-width:0' + (c.unread ? '' : ';font-weight:700') }, [c.name || c.email || 'Visitor']),
              el('div', { style: 'font-size:11px;color:#94A3B8;white-space:nowrap' }, [wcStamp(c.at)]),
            ]),
            el('div', { class: 'kt-wc-prev', style: c.typing ? 'color:#0E7490;font-style:italic' : (c.unread ? 'color:#0F172A;font-weight:700' : '') }, [c.typing ? 'typing…' : by + (c.preview || '')]),
            chips.length ? el('div', { style: 'margin-top:4px' }, chips) : null,
          ]),
          c.unread ? el('span', { style: 'flex:0 0 9px;width:9px;height:9px;border-radius:50%;background:#DC2626;margin-top:6px' }) : null,
        ]);
        row.addEventListener('click', function () { openChat(c.id); });
        rows.appendChild(row);
      });
    }
    async function loadList() {
      if (!alive()) return;
      try {
        var d = await Api.get('/sales/web-chats', { status: state.filter });
        state.chats = d.chats || [];
        paintList();
        if (!state.openId && state.chats.length && window.innerWidth > 820) openChat(state.chats[0].id);
        if (!state.openId && !state.chats.length) paintEmptyThread();
      } catch (e) {
        clear(rows); rows.appendChild(el('div', { style: 'padding:16px;color:#DC2626' }, ['Could not load chats: ' + (e.message || e)]));
      }
    }
    function paintEmptyThread() {
      clear(thread);
      thread.appendChild(el('div', { style: 'margin:auto;text-align:center;color:#94A3B8;padding:30px;font-size:13.5px' }, ['Pick a conversation on the left.']));
    }

    // ── the open conversation ──
    var msgsEl, typingEl, ta, headEl;
    function bubble(m) {
      if (m.sender === 'system') return el('div', { class: 'kt-wc-sys' }, [m.body + ' · ' + wcClock(m.at)]);
      var mine = m.sender === 'agent' || m.sender === 'bot';
      var who = m.sender === 'visitor' ? (state.chat && (state.chat.name || state.chat.email) || 'Visitor')
        : m.sender === 'bot' ? 'Maya · virtual assistant' : (m.author || 'Team');
      var bg = m.sender === 'visitor' ? 'background:#fff;border:1px solid #E2E8F0;color:#0F172A;border-bottom-left-radius:5px'
        : m.sender === 'bot' ? 'background:#F3F0FF;border:1px solid #E4DCFF;color:#3B2F6B;border-bottom-right-radius:5px'
        : 'background:' + ACCENT + ';color:#fff;border-bottom-right-radius:5px';
      var body = m.body;
      if (m.sender === 'visitor' && body.indexOf('[chat started]') === 0) {
        return el('div', { class: 'kt-wc-sys' }, ['Chat started' + ' · ' + wcClock(m.at)]);
      }
      return el('div', { class: 'kt-wc-m ' + (mine ? 'r' : 'l') }, [
        el('div', { class: 'kt-wc-who' }, [who + ' · ' + wcClock(m.at)]),
        el('div', { class: 'kt-wc-b', style: bg }, [body.replace(/\*\*([^*]+)\*\*/g, '$1')]),
      ]);
    }
    function appendMsgs(ms) {
      if (!ms || !ms.length) return;
      var nearBottom = msgsEl.scrollHeight - msgsEl.scrollTop - msgsEl.clientHeight < 80;
      ms.forEach(function (m) { if (m.id > state.lastId) { msgsEl.appendChild(bubble(m)); state.lastId = m.id; } });
      if (nearBottom || state._forceBottom) { msgsEl.scrollTop = msgsEl.scrollHeight; state._forceBottom = false; }
    }
    function paintHead() {
      var c = state.chat; if (!c || !headEl) return;
      clear(headEl);
      var back = wbtn('‹ Chats', 'ghost', function () { state.openId = 0; state.threadFor = 0; grid.classList.remove('has-thread'); paintList(); });
      back.classList.add('kt-wc-back');
      var status = c.status !== 'open' ? 'Closed' : (c.present ? '● On the website now' : 'Left the page');
      var acts = [];
      if (c.status === 'open' && !c.mine) acts.push(wbtn(c.agent_id ? 'Take over' : 'Join', 'primary', async function () { await Api.post('/sales/web-chats/' + c.id + '/join', {}); refreshThread(); loadList(); ta && ta.focus(); }));
      if (c.status === 'open' && c.mine) acts.push(wbtn('Hand back to Maya', 'ghost', async function () { await Api.post('/sales/web-chats/' + c.id + '/release', {}); refreshThread(); loadList(); }));
      if (c.lead) acts.push(wbtn('🎯 Open lead', 'light', function () { go('sales-lead?id=' + c.lead.id); }));
      else acts.push(wbtn('🎯 Add as lead', 'light', async function (ev) {
        ev.target.disabled = true;
        try { var r = await Api.post('/sales/web-chats/' + c.id + '/lead', {}); toast('🎯', r.created ? 'Lead created' : 'Added to the existing lead', 'The transcript is on the lead as a note.'); refreshThread(); loadList(); }
        catch (e) { toast('⚠️', 'Could not add lead', e.message || '', '#DC2626'); ev.target.disabled = false; }
      }));
      if (c.status === 'open') acts.push(wbtn('Close', 'danger', async function () { await Api.post('/sales/web-chats/' + c.id + '/close', {}); refreshThread(); loadList(); }));
      headEl.appendChild(back);
      headEl.appendChild(el('div', { style: 'flex:1;min-width:160px' }, [
        el('div', { style: 'font-weight:800;font-size:15px;color:#0F172A;overflow-wrap:anywhere' }, [c.name || c.email || 'Visitor']),
        el('div', { style: 'font-size:12px;color:#64748B;overflow-wrap:anywhere' }, [
          c.email ? el('a', { href: 'mailto:' + c.email, style: 'color:#0E7490' }, [c.email]) : null,
          (c.email ? ' · ' : '') + status + (c.agent_name ? ' · with ' + (c.mine ? 'you' : c.agent_name) : (c.status === 'open' ? ' · Maya is answering' : '')),
        ]),
      ]));
      acts.forEach(function (a) { headEl.appendChild(a); });
      if (ta) {
        ta.disabled = false;
        ta.placeholder = c.status !== 'open' ? 'Closed — writing here reopens it for the visitor if they are still on the page' : (c.mine ? 'Reply to ' + (c.name || 'the visitor') + '…' : 'Reply — sending takes the chat from Maya');
      }
    }
    function openChat(id) {
      state.openId = id; state.threadFor = id; state.lastId = 0; state.chat = null; state._forceBottom = true;
      grid.classList.add('has-thread');
      try { history.replaceState(null, '', '#sales-webchat?id=' + id); } catch (e) {}
      Array.prototype.forEach.call(rows.children, function (r) { r.classList.remove('on'); });
      clear(thread);
      headEl = el('div', { class: 'kt-wc-head' });
      msgsEl = el('div', { class: 'kt-wc-msgs' });
      typingEl = el('div', { class: 'kt-wc-typing' });
      ta = el('textarea', { placeholder: 'Reply…', rows: '1' });
      var lastTypingSent = 0;
      ta.addEventListener('input', function () {
        ta.style.height = '40px'; ta.style.height = Math.min(120, ta.scrollHeight) + 'px';
        var now = Date.now();
        if (ta.value.trim() && now - lastTypingSent > 3000 && state.chat && state.chat.status === 'open') {
          lastTypingSent = now;
          Api.post('/sales/web-chats/' + id + '/typing', {}).catch(function () {});
        }
      });
      ta.addEventListener('keydown', function (e) { if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); send(); } });
      var sendBtn = wbtn('Send', 'primary', function () { send(); });
      async function send() {
        var t = ta.value.trim(); if (!t) return;
        ta.value = ''; ta.style.height = '40px'; sendBtn.disabled = true;
        try {
          var r = await Api.post('/sales/web-chats/' + id + '/reply', { body: t });
          state._forceBottom = true;
          await refreshThread();
          if (r && r.message) appendMsgs([r.message]);
          loadList();
        } catch (e) { ta.value = t; toast('⚠️', 'Not sent', e.message || '', '#DC2626'); }
        sendBtn.disabled = false; ta.focus();
      }
      thread.appendChild(headEl);
      thread.appendChild(msgsEl);
      thread.appendChild(typingEl);
      thread.appendChild(el('div', { class: 'kt-wc-comp' }, [ta, sendBtn]));
      refreshThread();
      paintList();
      // On a phone the thread replaces the list below the banner: bring it up so the
      // reply box clears the bottom nav instead of sitting under it.
      if (window.innerWidth <= 820 && window.KT && KT.scroll && KT.scroll.toEl) {
        try { KT.scroll.toEl(grid, 8); } catch (e) {}
        // Arriving by link (a push, the email), the shell's own settle puts the page back at
        // the top after this runs; try once more, unless they have scrolled themselves.
        var openedAt = Date.now();
        setTimeout(function () {
          if (alive() && (KT.scroll.lastInput() || 0) < openedAt) { try { KT.scroll.toEl(grid, 8); } catch (e) {} }
        }, 1200);
      }
    }
    var _thrBusy = false;
    async function refreshThread() {
      if (!state.openId || !alive() || _thrBusy) return;
      _thrBusy = true;
      var id = state.openId;
      try {
        var d = await Api.get('/sales/web-chats/' + id, { after: state.lastId });
        if (id !== state.openId) { _thrBusy = false; return; }
        var prev = state.chat;
        state.chat = d.chat;
        appendMsgs(d.messages || []);
        if (!prev || prev.status !== d.chat.status || prev.agent_id !== d.chat.agent_id || prev.present !== d.chat.present || !!prev.lead !== !!d.chat.lead) paintHead();
        typingEl.textContent = d.chat.typing ? (d.chat.name || 'The visitor') + ' is typing…' : '';
      } catch (e) {
        if (!state.chat) { clear(thread); thread.appendChild(el('div', { style: 'padding:16px;color:#DC2626' }, ['Could not open this chat: ' + (e.message || e)])); }
      }
      _thrBusy = false;
    }

    // ── polling, stopped when the screen goes ──
    var tThread = setInterval(function () { if (!alive()) return stop(); refreshThread(); }, 2500);
    var tList = setInterval(function () { if (!alive()) return stop(); loadList(); }, 6000);
    function stop() {
      state.dead = true; clearInterval(tThread); clearInterval(tList);
      var i = WC.listeners.indexOf(paintOnline); if (i > -1) WC.listeners.splice(i, 1);
      window.removeEventListener('hashchange', onLeave);
    }
    var onLeave = function () { if ((window.location.hash || '').indexOf('sales-webchat') === -1) stop(); };
    window.addEventListener('hashchange', onLeave);

    paintEmptyThread();
    await loadList();
    if (state.openId && state.threadFor !== state.openId) openChat(state.openId);
    wcBeat();
  }

  // ─────────────────────────────── register ───────────────────────────────
  // sales_rep + superadmin (platform_admin resolves to the agency_admin shell, so
  // register there too; guarded() blocks any non-sales/non-platform agency_admin).
  ['sales_rep', 'platform_admin', 'agency_admin'].forEach(function (role) {
    KT.Shell.registerScreen(role + ':sales', guarded(renderPipeline));
    KT.Shell.registerScreen(role + ':sales-leads', guarded(renderList));
    KT.Shell.registerScreen(role + ':sales-new', guarded(renderNew));
    KT.Shell.registerScreen(role + ':sales-lead', guarded(renderDetail));
    KT.Shell.registerScreen(role + ':sales-followups', guarded(renderFollowups));
    KT.Shell.registerScreen(role + ':sales-plans', guarded(renderPlans));
    KT.Shell.registerScreen(role + ':sales-demo', guarded(renderDemo));
    KT.Shell.registerScreen(role + ':sales-chat', guarded(renderChat));
    KT.Shell.registerScreen(role + ':sales-webchat', guarded(renderWebChat));
    /* Registered for every sales-capable role, and renderLibrary refuses anyone
       who is not a superadmin. Registering it only for platform_admin would look
       tighter and be worse: platform_admin resolves to the agency_admin shell, so
       the screen would simply not exist for the very people it is for. */
    KT.Shell.registerScreen(role + ':sales-library', guarded(renderLibrary));
    // NOTE: sales Announcements + the standalone "Team chat" section were retired per
    // product direction — sales messaging now lives in the shared portal chat dock
    // (top-bar 💬), restricted to the sales team + superadmins. sales-news is no longer
    // registered so #sales-news resolves to nothing.
  });
  // Sales-rep home = the analytics dashboard (overrides the generic tile launcher,
  // which is registered earlier in screen-role-home.js — this file loads after it).
  KT.Shell.registerScreen('sales_rep:home', guarded(renderSalesHome));
})(window);
