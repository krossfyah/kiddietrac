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
  function money(v) { if (v == null || v === '') return '—'; var n = Number(v); return '$' + n.toLocaleString(undefined, { minimumFractionDigits: 0, maximumFractionDigits: 2 }); }
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
    return el('button', { type: 'button', class: 'btn', style: (K[kind] || K.light) + ';border-radius:9px;padding:9px 15px;font-weight:700;cursor:pointer;font-size:13.5px;line-height:1', onclick: on }, [label]);
  }
  function field(label, node) {
    return el('div', { style: 'margin-bottom:0' }, [
      el('label', { style: 'display:block;font-size:12px;font-weight:700;color:var(--kt-muted,#5a7080);margin-bottom:5px' }, [label]),
      node,
    ]);
  }
  function input(attrs) { return el('input', Object.assign({ class: 'input', style: 'width:100%;padding:9px 11px;border:1px solid var(--kt-border,#d9e1ea);border-radius:9px;font-size:14px;box-sizing:border-box' }, attrs || {})); }
  function textarea(attrs) { return el('textarea', Object.assign({ class: 'input', style: 'width:100%;padding:9px 11px;border:1px solid var(--kt-border,#d9e1ea);border-radius:9px;font-size:14px;box-sizing:border-box;min-height:76px;resize:vertical' }, attrs || {})); }
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
      var tbl = el('table', { style: 'width:100%;border-collapse:collapse;background:#fff;border:1px solid #e6ebf1;border-radius:12px;overflow:hidden' });
      tbl.appendChild(el('thead', {}, [el('tr', { style: 'background:#f4f6f9;text-align:left' }, ['Company / contact', 'Stage', 'Value', 'Owner', 'Follow-up'].map(function (h) { return el('th', { style: 'padding:10px 12px;font-size:12px;color:#5a7080' }, [h]); }))]));
      var tb = el('tbody');
      data.leads.forEach(function (l) {
        tb.appendChild(el('tr', { style: 'border-top:1px solid #eef1f5;cursor:pointer', onclick: function () { go('sales-lead?id=' + l.id); } }, [
          el('td', { style: 'padding:10px 12px' }, [el('div', { style: 'font-weight:700' }, [l.company || l.name || '—']), l.company && l.name ? el('div', { style: 'font-size:12px;color:#64748B' }, [l.name]) : null]),
          el('td', { style: 'padding:10px 12px' }, [stageChip(l.stage)]),
          el('td', { style: 'padding:10px 12px;font-weight:700;color:#047857' }, [money(l.value)]),
          el('td', { style: 'padding:10px 12px;font-size:13px' }, [l.owner_name || '—']),
          el('td', { style: 'padding:10px 12px;font-size:13px;color:' + (l.follow_up_date && l.follow_up_date <= todayISO() ? '#C2410C' : '#64748B') }, [l.follow_up_date ? fmtDate(l.follow_up_date) : '—']),
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

    var body = el('div');

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

    var grid = el('div', { style: 'display:grid;grid-template-columns:repeat(auto-fit,minmax(320px,1fr));gap:14px;align-items:start' });
    // LEFT: editable details + activities
    var left = el('div');
    var e = {};
    left.appendChild(card([
      el('div', { style: 'font-weight:800;margin-bottom:12px' }, ['Details']),
      el('div', { style: 'display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:12px 14px;align-items:start' }, [
        field('Contact', e.name = input({ value: lead.name || '' })),
        field('Job title', e.title = input({ value: lead.title || '' })),
        field('Company', e.company = input({ value: lead.company || '' })),
        field('Website', e.website = input({ value: lead.website || '' })),
        field('Email', e.email = input({ value: lead.email || '' })),
        field('Phone', e.phone = input({ value: lead.phone || '' })),
        field('Street address', e.address = input({ value: lead.address || '' })),
        field('City', e.city = input({ value: lead.city || '' })),
        field('Province / state', e.province = input({ value: lead.province || '' })),
        field('Postal / ZIP', e.postal_code = input({ value: lead.postal_code || '' })),
        field('Country', e.country = input({ value: lead.country || '' })),
        field('Owner name', e.owner_name = input({ value: lead.owner_name || '', placeholder: 'Business owner' })),
        field('Owner title', e.owner_title = input({ value: lead.owner_title || '' })),
        field('Owner email', e.owner_email = input({ value: lead.owner_email || '' })),
        field('Owner phone', e.owner_phone = input({ value: lead.owner_phone || '' })),
        field('Using today', e.current_solution = input({ value: lead.current_solution || '', placeholder: 'HiMama, paper…' })),
        field('# children', e.num_children = input({ type: 'number', min: '0', value: lead.num_children == null ? '' : lead.num_children })),
        field('# locations', e.num_locations = input({ type: 'number', min: '0', value: lead.num_locations == null ? '' : lead.num_locations })),
        field('Lead source', e.source = selectEl(SOURCES, lead.source || '')),
        field('Owner', e.owner_id = selectEl(ownerOpts(), lead.owner_id || '')),
        field('Est. value ($)', e.value = input({ type: 'number', step: '0.01', value: lead.value == null ? '' : lead.value })),
        field('Expected close', e.expected_close = input({ type: 'date', value: lead.expected_close || '' })),
        field('Next follow-up', e.follow_up_date = input({ type: 'date', value: lead.follow_up_date || '' })),
      ]),
      el('div', { style: 'margin-top:12px' }, [field('Notes', e.notes = textarea({ value: lead.notes || '' }))]),
      el('div', { style: 'display:flex;gap:8px;margin-top:12px' }, [
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

    // Activity + follow-up composer
    var acWrap = card([el('div', { style: 'font-weight:800;margin-bottom:12px' }, ['Activity & history'])], '');
    var typeSel = selectEl([{ v: 'note', l: '📝 Note' }, { v: 'call', l: '📞 Call' }, { v: 'email', l: '📧 Email' }, { v: 'meeting', l: '🤝 Meeting' }, { v: 'followup', l: '⏰ Follow-up task' }], 'note', { style: 'width:100%;padding:9px 11px;border:1px solid var(--kt-border,#d9e1ea);border-radius:9px;font-size:14px;box-sizing:border-box' });
    var bodyIn = textarea({ placeholder: 'Log a note, or describe the follow-up…' });
    var dueIn = input({ type: 'date' }); dueIn.style.display = 'none';
    typeSel.addEventListener('change', function () { dueIn.style.display = typeSel.value === 'followup' ? 'block' : 'none'; });
    acWrap.appendChild(el('div', { style: 'display:grid;grid-template-columns:minmax(150px,180px) 1fr;gap:8px;margin-bottom:8px' }, [typeSel, dueIn]));
    acWrap.appendChild(bodyIn);
    acWrap.appendChild(el('div', { style: 'margin-top:8px' }, [btn('Add', 'primary', async function (ev) {
      var b = { type: typeSel.value, body: bodyIn.value.trim() };
      if (typeSel.value === 'followup') { if (!dueIn.value) { toast('⚠️', 'Pick a due date', '', '#DC2626'); return; } b.due_date = dueIn.value; }
      if (!b.body && typeSel.value !== 'followup') { toast('⚠️', 'Write something', '', '#DC2626'); return; }
      ev.target.disabled = true;
      try { await Api.post('/sales/leads/' + id + '/activities', b); toast('✅', 'Added'); renderDetail(container, ctx); } catch (er) { toast('⚠️', 'Failed', er.message || '', '#DC2626'); ev.target.disabled = false; }
    })]));
    var timeline = el('div', { style: 'margin-top:14px;display:flex;flex-direction:column;gap:8px' });
    var ACT_ICON = { note: '📝', call: '📞', email: '📧', meeting: '🤝', followup: '⏰', stage: '↗️', edit: '✏️' };
    var ACT_LABEL = { note: 'Note', call: 'Call', email: 'Email', meeting: 'Meeting', followup: 'Follow-up', stage: 'Stage change', edit: 'Field change' };
    (lead.activities || []).forEach(function (a) {
      var isFu = a.type === 'followup';
      var accent = isFu && !a.done ? '#F59E0B' : (a.type === 'edit' ? '#8B5CF6' : '#e6ebf1');
      timeline.appendChild(el('div', { style: 'border-left:2px solid ' + accent + ';padding:2px 0 2px 10px' }, [
        el('div', { style: 'font-size:12px;color:#64748B' }, [
          (ACT_ICON[a.type] || '•') + ' ',
          el('span', { style: 'font-weight:700;color:#334155' }, [ACT_LABEL[a.type] || (a.type.charAt(0).toUpperCase() + a.type.slice(1))]),
          a.user_name ? ' · ' + a.user_name : '', a.created_at ? ' · ' + fmtDate((a.created_at || '').slice(0, 10)) : '',
          isFu && a.due_date ? el('span', { style: 'margin-left:6px;font-weight:700;color:' + (a.done ? '#15803D' : '#C2410C') }, [a.done ? '✓ done' : ('due ' + fmtDate(a.due_date))]) : '',
          isFu && !a.done ? el('a', { href: '#', style: 'margin-left:8px;color:' + ACCENT + ';font-weight:700', onclick: async function (ev2) { ev2.preventDefault(); try { await Api.patch('/sales/activities/' + a.id, { done: true }); toast('✅', 'Done'); renderDetail(container, ctx); } catch (er) {} } }, ['mark done']) : '',
        ]),
        activityBodyNode(a),
      ]));
    });
    if (!(lead.activities || []).length) timeline.appendChild(el('div', { style: 'color:#94A3B8;font-size:13px' }, ['No activity yet.']));
    acWrap.appendChild(timeline);
    left.appendChild(acWrap);
    grid.appendChild(left);

    // RIGHT: quotes
    grid.appendChild(renderQuotesPanel(id, lead, container, ctx));
    body.appendChild(grid);
    container.appendChild(wrap([body], 1320));
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
    var ov = el('div', { style: 'position:fixed;inset:0;z-index:10000;background:rgba(13,27,42,.55);display:flex;align-items:flex-start;justify-content:center;padding:30px 16px;overflow:auto', onclick: function (ev) { if (ev.target === ov) ov.remove(); } });
    var items = quote && quote.items ? quote.items.map(function (i) { return { description: i.description, qty: i.qty, unit_price: i.unit_price, product_id: i.product_id }; }) : [];
    var box = el('div', { style: 'background:#fff;border-radius:16px;max-width:640px;width:100%;padding:22px' });
    var titleIn = input({ value: quote ? (quote.title || '') : 'KiddieTrac Proposal', placeholder: 'Proposal title' });
    var discIn = input({ type: 'number', step: '0.01', value: quote ? (quote.discount || 0) : 0 });
    var validIn = input({ type: 'date', value: quote ? (quote.valid_until || '') : '' });
    var notesIn = textarea({ value: quote ? (quote.notes || '') : '' });
    var itemsHost = el('div', { style: 'display:flex;flex-direction:column;gap:6px;margin:6px 0' });
    var totalEl = el('div', { style: 'text-align:right;font-weight:800;font-size:18px;color:#047857;margin-top:8px' }, ['$0.00']);
    function recalc() { var s = 0; items.forEach(function (i) { s += (Number(i.qty || 0) * Number(i.unit_price || 0)); }); var t = s - Number(discIn.value || 0); totalEl.textContent = money(t) + (Number(discIn.value || 0) ? '  (−' + money(discIn.value) + ')' : ''); }
    function drawItems() {
      clear(itemsHost);
      items.forEach(function (it, idx) {
        var d = input({ value: it.description || '', placeholder: 'Line item', style: 'flex:1;min-width:0;padding:7px 9px;border:1px solid #d9e1ea;border-radius:8px;font-size:14px;box-sizing:border-box' });
        var q = input({ type: 'number', step: '0.01', value: it.qty == null ? 1 : it.qty, style: 'width:60px;flex:0 0 60px;padding:7px;border:1px solid #d9e1ea;border-radius:8px;font-size:14px;box-sizing:border-box' });
        var p = input({ type: 'number', step: '0.01', value: it.unit_price == null ? 0 : it.unit_price, style: 'width:90px;flex:0 0 90px;padding:7px;border:1px solid #d9e1ea;border-radius:8px;font-size:14px;box-sizing:border-box' });
        d.addEventListener('input', function () { it.description = d.value; }); q.addEventListener('input', function () { it.qty = q.value; recalc(); }); p.addEventListener('input', function () { it.unit_price = p.value; recalc(); });
        itemsHost.appendChild(el('div', { style: 'display:flex;gap:6px;align-items:center' }, [d, q, p, el('button', { style: 'border:0;background:#FEE2E2;color:#B91C1C;border-radius:8px;padding:6px 9px;cursor:pointer;flex:0 0 auto', onclick: function () { items.splice(idx, 1); drawItems(); recalc(); } }, ['×'])]));
      });
    }
    drawItems(); recalc(); discIn.addEventListener('input', recalc);
    var planSel = selectEl([{ v: '', l: '+ Add preset plan…' }].concat(products.map(function (p) { return { v: p.id, l: p.name + ' — ' + money(p.price) + '/' + p.unit }; })), '');
    planSel.addEventListener('change', function () { var p = products.filter(function (x) { return String(x.id) === planSel.value; })[0]; if (p) { items.push({ description: p.name + (p.description ? ' — ' + p.description : ''), qty: 1, unit_price: Number(p.price), product_id: p.id }); drawItems(); recalc(); planSel.value = ''; } });
    box.appendChild(el('div', { style: 'display:flex;justify-content:space-between;align-items:center;margin-bottom:12px' }, [el('div', { style: 'font-size:18px;font-weight:800' }, [quote ? ('Proposal ' + (quote.number || '')) : 'New proposal']), el('button', { style: 'border:0;background:none;font-size:22px;cursor:pointer;color:#64748B', onclick: function () { ov.remove(); } }, ['×'])]));
    box.appendChild(field('Title', titleIn));
    box.appendChild(el('div', { style: 'display:flex;gap:8px;align-items:center;margin:14px 0 6px' }, [el('div', { style: 'flex:1;font-size:12px;font-weight:700;color:#5a7080' }, ['Line items']), el('div', { style: 'width:60px;font-size:11px;color:#64748B;text-align:center' }, ['Qty']), el('div', { style: 'width:90px;font-size:11px;color:#64748B;text-align:center' }, ['Price']), el('div', { style: 'width:34px' })]));
    box.appendChild(itemsHost);
    box.appendChild(el('div', { style: 'display:flex;gap:8px;margin-bottom:14px' }, [el('div', { style: 'flex:1;min-width:0' }, [planSel]), btn('+ Blank line', 'light', function () { items.push({ description: '', qty: 1, unit_price: 0 }); drawItems(); })]));
    box.appendChild(el('div', { style: 'display:grid;grid-template-columns:1fr 1fr;gap:14px' }, [field('Discount ($)', discIn), field('Valid until', validIn)]));
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
        var body = { title: titleIn.value, discount: discIn.value || 0, valid_until: validIn.value || null, notes: notesIn.value, items: items.filter(function (i) { return (i.description || '').trim(); }) };
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
  async function renderPlans(container) {
    clear(container);
    container.appendChild(hero('Plans & pricing', 'Preset products used in proposals. Edit prices to match your offer.', '💲'));
    var host = el('div'); container.appendChild(host); // full width
    async function load() {
      clear(host);
      var products = await Api.get('/sales/products');
      var grid = el('div', { style: 'display:grid;grid-template-columns:repeat(auto-fill,minmax(340px,1fr));gap:14px;align-items:start' });
      products.forEach(function (p) { grid.appendChild(planCard(p, load)); });
      host.appendChild(grid);
      host.appendChild(el('div', { style: 'margin-top:16px' }, [btn('➕ Add plan', 'primary', function () { grid.insertBefore(planCard({ name: '', description: '', price: 0, unit: 'month', active: true }, load, true), grid.firstChild); })]));
    }
    // Each plan is its own card: name + price + unit on top, then a FULL-WIDTH
    // multi-line description (so long descriptions are never cut off), then actions.
    function planCard(p, reload, isNew) {
      var n = input({ value: p.name || '', placeholder: 'Plan name', style: 'flex:1 1 auto;min-width:0;padding:9px 10px;border:1px solid #d9e1ea;border-radius:8px;font-size:14px;font-weight:700;box-sizing:border-box' });
      var pr = input({ type: 'number', step: '0.01', value: p.price || 0, style: 'flex:0 0 110px;width:110px;padding:9px 10px;border:1px solid #d9e1ea;border-radius:8px;font-size:14px;box-sizing:border-box' });
      var u = selectEl([{ v: 'month', l: '/month' }, { v: 'year', l: '/year' }, { v: 'one-time', l: 'one-time' }], p.unit || 'month', { style: 'flex:0 0 118px;width:118px;padding:9px 10px;border:1px solid #d9e1ea;border-radius:8px;font-size:14px;box-sizing:border-box' });
      var d = textarea({ value: p.description || '', placeholder: 'Description — shown on proposals. Full text is always visible here.', style: 'width:100%;padding:9px 11px;border:1px solid var(--kt-border,#d9e1ea);border-radius:9px;font-size:14px;box-sizing:border-box;min-height:70px;resize:vertical' });
      return card([
        el('div', { style: 'display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin-bottom:11px' }, [n, pr, u]),
        field('Description', d),
        el('div', { style: 'display:flex;gap:8px;margin-top:12px;justify-content:flex-end;align-items:center' }, [
          p.id ? el('button', { title: 'Delete plan', style: 'border:0;background:#FEE2E2;color:#B91C1C;border-radius:8px;padding:8px 11px;cursor:pointer;font-size:14px', onclick: async function () { if (!confirm('Delete plan?')) return; try { await Api.delete('/sales/products/' + p.id); reload(); } catch (er) {} } }, ['🗑 Delete']) : el('div', { style: 'flex:1' }),
          btn('Save', 'primary', async function (ev) { ev.target.disabled = true; try { var body = { name: n.value, description: d.value, price: pr.value || 0, unit: u.value, active: true }; if (p.id) await Api.patch('/sales/products/' + p.id, body); else await Api.post('/sales/products', body); toast('✅', 'Saved'); reload(); } catch (er) { toast('⚠️', 'Failed', er.message || '', '#DC2626'); ev.target.disabled = false; } }),
        ]),
      ], 'padding:15px 16px');
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
  function setSeen(id) { try { if (id > seenId()) localStorage.setItem('kt_sales_chat_seen', String(id)); } catch (e) {} paintChatBadge(0); }
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
            (a.mine || salesAuthed()) ? el('button', { title: 'Delete', style: 'border:0;background:none;color:#94A3B8;cursor:pointer;font-size:15px;flex:0 0 auto', onclick: async function () { if (!confirm('Delete this announcement?')) return; try { await Api.delete('/sales/announcements/' + a.id); load(); } catch (e) { toast('⚠️', 'Failed', e.message || '', '#DC2626'); } } }, ['🗑']) : null,
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
  function salesTiles() {
    var tiles = [
      { hash: 'sales', icon: '📊', label: 'Pipeline' }, { hash: 'sales-leads', icon: '🎯', label: 'Leads' },
      { hash: 'sales-followups', icon: '⏰', label: 'Follow-ups' }, { hash: 'sales-plans', icon: '💲', label: 'Plans & pricing' },
      { hash: 'sales-demo', icon: '🚀', label: 'Launch demo' },
      { hash: 'notifications', icon: '🔔', label: 'Inbox' }, { hash: 'help', icon: '📖', label: 'Help' },
    ];
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
    // NOTE: sales Announcements + the standalone "Team chat" section were retired per
    // product direction — sales messaging now lives in the shared portal chat dock
    // (top-bar 💬), restricted to the sales team + superadmins. sales-news is no longer
    // registered so #sales-news resolves to nothing.
  });
  // Sales-rep home = the analytics dashboard (overrides the generic tile launcher,
  // which is registered earlier in screen-role-home.js — this file loads after it).
  KT.Shell.registerScreen('sales_rep:home', guarded(renderSalesHome));
})(window);
