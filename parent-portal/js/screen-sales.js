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
  /* PROPOSAL CURRENCY + BILLING (2026-10-16, Anthony: "when creating proposal allow to choose
     currency type and if annual or monthly"). Prices are typed in the chosen currency; nothing is
     converted. Billing is 'month' | 'year': preset plans priced per month are x12 on an annual
     proposal (and yearly ones /12 on a monthly one), and switching it re-prices those lines. */
  var CURRENCIES = [['CAD', 'Canadian dollar'], ['USD', 'US dollar'], ['GBP', 'British pound'], ['EUR', 'Euro'], ['AUD', 'Australian dollar'], ['NZD', 'New Zealand dollar'], ['JMD', 'Jamaican dollar'], ['TTD', 'Trinidad and Tobago dollar'], ['BBD', 'Barbados dollar'], ['XCD', 'East Caribbean dollar'], ['INR', 'Indian rupee'], ['PHP', 'Philippine peso'], ['ZAR', 'South African rand'], ['MXN', 'Mexican peso']];
  var CUR_SYM = { GBP: '£', EUR: '€', INR: '₹', PHP: '₱', ZAR: 'R' };
  /* Est. annual revenue (2026-10-16): filled from the latest sent proposal (annual value, before
     tax), or typed by hand. Shown per year, with the currency when it is not CAD. */
  function perYear(l) {
    if (!l || l.value == null || l.value === '') return '—';
    var cur = l.value_currency || 'CAD';
    return (cur === 'CAD' ? money(l.value) : moneyIn(l.value, cur) + ' ' + cur) + '/yr';
  }
  function moneyIn(v, cur) { if (v == null || v === '') return '—'; var n = Number(v); return (n < 0 ? '−' : '') + (CUR_SYM[cur || 'CAD'] || '$') + Math.abs(n).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 }); }
  function periodShort(p) { return p === 'year' ? '/yr' : (p === 'month' ? '/mo' : ''); }
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
  function todayISO() { return ((window.KT && KT.agencyToday) ? KT.agencyToday() : new Date().toISOString().slice(0, 10)); }
  function addDaysISO(iso, n) { var t = new Date(iso + 'T12:00:00Z'); t.setUTCDate(t.getUTCDate() + n); return t.toISOString().slice(0, 10); }
  var CURRENT_LEAD = null;   // the lead on screen (the proposal Email action names its address)
  function fmtTime(s) { if (!s) return ''; try { var d = new Date(s.replace(' ', 'T')); return d.toLocaleString(undefined, { month: 'short', day: 'numeric', hour: 'numeric', minute: '2-digit' }); } catch (e) { return s; } }
  function me() { try { return JSON.parse(sessionStorage.getItem('kt_user') || '{}'); } catch (e) { return {}; } }
  // Previewing another role (View as) is that role: no sales chat or website-chat presence behind a parent's screens.
  function salesAuthed() { var r = window.ktRoles ? window.ktRoles(me()) : ((me().roles) || []); return r.indexOf('sales_rep') > -1 || r.indexOf('platform_admin') > -1; }
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

  KT.salesGuarded = function (fn) { return guarded(fn); };   // shared with screen-sales-prospects.js (Cold reach)
  function guarded(fn) {
    return function (c, ctx) {
      if (!salesAuthed()) { c.innerHTML = ''; c.appendChild(el('div', { style: 'padding:48px 20px;text-align:center;color:#64748B' }, ['🔒 The sales workspace is for the sales team and superadmins.'])); return; }
      return fn(c, ctx);
    };
  }
  function tok() { return sessionStorage.getItem('kt_token') || localStorage.getItem('kt_token'); }
  function apiBase() { return (KT.API_BASE) || 'https://api.kiddietrac.com/api/v1'; }
  function downloadQuotePdf(id, number) {
    fetch(apiBase() + '/sales/quotes/' + id + '/pdf', { headers: { Authorization: 'Bearer ' + tok() } })
      .then(function (r) { if (!r.ok) throw new Error('HTTP ' + r.status); return r.blob(); })
      .then(function (b) { var u = URL.createObjectURL(b); var a = document.createElement('a'); a.href = u; a.download = (number || ('proposal-' + id)) + '.pdf'; document.body.appendChild(a); a.click(); a.remove(); setTimeout(function () { URL.revokeObjectURL(u); }, 1500); })
      .catch(function (e) { toast('⚠️', 'PDF failed', e.message || '', '#DC2626'); });
  }
  // Print = KT.printDoc (2026-11-10): the same PDF as Download, signed copy included, through the one print path.
  function printQuotePdf(id, number) {
    fetch(apiBase() + '/sales/quotes/' + id + '/pdf', { headers: { Authorization: 'Bearer ' + tok() } })
      .then(function (r) { if (!r.ok) throw new Error('HTTP ' + r.status); return r.blob(); })
      .then(function (b) {
        if (!(window.KT && KT.printDoc)) throw new Error('Printing is not available here. Use Download instead.');
        return KT.printDoc({ blob: b, mime: 'application/pdf' }, { title: number || 'Proposal' });
      })
      .catch(function (e) { toast('⚠️', 'Could not print', e.message || '', '#DC2626'); });
  }
  // Today at head office, as the signed PDF prints it under each signature ("Nov 10, 2026").
  function hqToday() {
    try { return new Date().toLocaleDateString('en-US', { timeZone: 'America/Toronto', year: 'numeric', month: 'short', day: 'numeric' }); }
    catch (e) { return new Date().toDateString(); }
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
  /* A global rule zeroes textarea min-height, so every box here collapsed to one line (2026-10-16:
     "the notes section in the proposal the box is too small"). Re-assert the intended minimum
     with !important; a caller's own min-height wins over the 76px default. */
  function textarea(attrs) {
    var t = el('textarea', Object.assign({ class: 'input', style: 'width:100%;padding:9px 11px;border:1px solid var(--kt-border,#d9e1ea);border-radius:9px;font-size:14px;box-sizing:border-box;min-height:76px;resize:vertical' }, attrs || {}));
    // rows: 1 = a one-line box that grows itself; rows: N = about N lines; otherwise 76px.
    var rows = attrs && attrs.rows ? Number(attrs.rows) : 0;
    var mh = t.style.minHeight || (rows === 1 ? '' : rows > 1 ? (rows * 21 + 20) + 'px' : '76px');
    if (mh) t.style.setProperty('min-height', mh, 'important');
    if (!t.style.fontFamily) t.style.fontFamily = 'inherit';   // not the browser's monospace default
    return t;
  }
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
  // cols: how many columns the section's fields sit in (2 by default); fieldWide spans them all,
  // fieldSpan(label, node, n) spans n. Every field fills its column; one column on a phone (2026-10-27).
  function formSection(title, icon, kids, cols) {
    if (!document.getElementById('kt-fs-css')) {
      var st = document.createElement('style'); st.id = 'kt-fs-css';
      st.textContent = '.kt-fs-grid input:not([type=checkbox]):not([type=radio]),.kt-fs-grid select,.kt-fs-grid textarea,#appMain#appMain .kt-fs-grid input:not([type=checkbox]):not([type=radio]),#appMain#appMain .kt-fs-grid select,#appMain#appMain .kt-fs-grid textarea{width:100% !important;max-width:none !important;box-sizing:border-box;}'
        + '.kt-fs-grid > *{min-width:0;}'
        + '@media (max-width:600px){.kt-fs-grid{grid-template-columns:minmax(0,1fr) !important;}.kt-fs-grid > *{grid-column:1 / -1 !important;}}';
      document.head.appendChild(st);
    }
    return el('div', { 'data-kt-nofit': '1', 'data-kt-nogrid': '1', 'data-kt-nopack': '1', style: 'background:#FAFBFD;border:1px solid #EAF0F5;border-radius:14px;padding:15px 17px 17px;margin-bottom:14px' }, [
      el('div', { style: 'font-size:11.5px;font-weight:800;letter-spacing:.5px;text-transform:uppercase;color:#0C6070;margin:0 0 13px;display:flex;align-items:center;gap:6px' }, [(icon || '') + ' ' + title]),
      el('div', { class: 'kt-fs-grid', style: 'display:grid;grid-template-columns:repeat(' + (cols || 2) + ',minmax(0,1fr));gap:12px 16px;align-items:end' }, kids),
    ]);
  }
  function fieldSpan(label, node, n) { var f = field(label, node); f.style.gridColumn = 'span ' + n; return f; }
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
      btn('➕ New lead', 'primary', function () { openNewLead(); }),
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
      statCard('💰', 'Pipeline value / yr', money(st.pipeline_value || 0), '#ECFDF5', '#047857'),
      statCard('⚖️', 'Weighted forecast / yr', money(st.weighted_pipeline || 0), '#F0F9FF', '#0369A1'),
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
    var stageSum = {}; (data.stages || []).forEach(function (x) { stageSum[x.key] = x; });
    STAGES.forEach(function (s) {
      var leads = byStage[s.key] || [];
      var sum = stageSum[s.key] || {};
      var col = el('div', { class: 'kt-sales-col' }, [
        el('div', { class: 'kt-sales-col-head' }, [
          el('span', { class: 't', style: 'color:' + s.color }, [s.label]),
          el('span', { style: 'font-size:12px;font-weight:700;color:#64748B;flex:0 0 auto' }, [String(leads.length)]),
        ]),
        sum.value ? el('div', { style: 'font-size:11.5px;line-height:1.35;color:#64748B;margin:-2px 2px 8px', title: 'Total estimated annual revenue in this stage, and what it counts for at ' + (sum.odds || 0) + '% chance of closing' }, [
          el('div', { style: 'font-weight:800;color:#047857;white-space:nowrap' }, [money(sum.value) + '/yr']),
          el('div', { style: 'white-space:nowrap' }, [(sum.odds || 0) + '% → ', el('b', { style: 'color:#0369A1' }, [money(sum.weighted || 0)])]),
        ]) : null,
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
    var c = el('div', { class: 'kt-sales-leadcard', style: 'background:var(--kt-card,#fff);border:1px solid var(--kt-border,#e6ebf1);--kt-edge:' + (STAGE_COLOR[l.stage] || '#94A3B8') + ';border-radius:11px;padding:10px 11px;margin-bottom:8px;cursor:pointer;box-shadow:0 1px 2px rgba(15,23,42,.05)', onclick: function () { go('sales-lead?id=' + l.id); } }, [
      el('div', { style: 'font-weight:800;font-size:13.5px;color:var(--kt-ink,#0D1B2A)' }, [l.company || l.name || 'Lead #' + l.id]),
      l.company && l.name ? el('div', { style: 'font-size:12px;color:#64748B' }, [l.name]) : null,
      el('div', { style: 'display:flex;gap:8px;align-items:center;margin-top:6px;flex-wrap:wrap' }, [
        l.value != null ? el('span', { style: 'font-size:12px;font-weight:700;color:#047857', title: l.value_quote_id ? 'From the latest proposal' : 'Entered by hand' }, [perYear(l)]) : null,
        l.owner_name ? el('span', { style: 'font-size:11px;color:#64748B' }, ['· ' + l.owner_name]) : null,
        due ? el('span', { style: 'font-size:11px;font-weight:700;color:#C2410C;background:#FFF7ED;border-radius:999px;padding:1px 7px' }, ['⏰ ' + fmtDate(l.follow_up_date)]) : null,
      ]),
    ]);
    return c;
  }

  // ─────────────────────────────── Leads list ───────────────────────────────
  /* DUPLICATE LEADS (2026-10-23, Anthony: "under leads there may be duplicates can you add
     a merge button and detect duplicates and advise"). The server compares every lead
     (App\Support\SalesLeadMerge) and says why two look alike and which to keep. Merging moves
     activity, proposals, bookings, files, chats and NDAs onto the kept lead, fills its empty
     details, and archives the other; "Not the same" stops the suggestion for that pair. */
  function leadLabel(l) { return l.company || l.name || l.email || ('Lead #' + l.id); }

  async function mergeLeads(keep, from, done) {
    var ok = (window.KT && KT.confirm)
      ? await KT.confirm({ title: 'Merge these leads?', okLabel: 'Merge',
          description: '“' + leadLabel(from) + '” will be merged into “' + leadLabel(keep) + '”. Its activity, proposals, bookings and files move across, empty details are filled in, and it is archived. The kept lead’s own details are not overwritten.' })
      : false;
    if (!ok) return false;
    try {
      var r = await Api.post('/sales/leads/' + keep.id + '/merge', { from: from.id });
      toast('🔀', 'Merged', leadLabel(from) + ' → ' + leadLabel(keep));
      if (done) done(r);
      return true;
    } catch (e) { toast('⚠️', 'Could not merge', e.message || '', '#DC2626'); return false; }
  }

  async function renderDuplicates(host, reload) {
    clear(host);
    var ids = {};
    var d; try { d = await Api.get('/sales/leads/duplicates'); } catch (e) { return ids; }
    var groups = (d && d.groups) || [];
    if (!groups.length) return ids;
    groups.forEach(function (g) { g.leads.forEach(function (l) { ids[l.id] = true; }); });
    var open = groups.length <= 3;
    var body = el('div', { style: open ? '' : 'display:none' });
    var head = el('div', { style: 'display:flex;align-items:center;gap:10px;flex-wrap:wrap;cursor:pointer' }, [
      el('div', { style: 'font-weight:800;color:#92400E;font-size:14.5px' }, ['⚠️ ' + groups.length + ' possible duplicate' + (groups.length > 1 ? 's' : '') + ' found']),
      el('div', { style: 'font-size:12.5px;color:#92400E;opacity:.85' }, ['Review and merge so each prospect has one record.']),
      el('div', { style: 'margin-left:auto;font-size:12.5px;font-weight:700;color:#92400E' }, [open ? 'Hide' : 'Review']),
    ]);
    head.onclick = function () { var show = body.style.display === 'none'; body.style.display = show ? '' : 'none'; head.lastChild.textContent = show ? 'Hide' : 'Review'; };
    groups.forEach(function (g) {
      var keepId = g.keep_id;
      var cards = el('div', { style: 'display:grid;grid-template-columns:repeat(auto-fill,minmax(min(100%,300px),1fr));gap:10px;margin-top:10px' });
      function paint() {
        clear(cards);
        g.leads.forEach(function (l) {
          var on = l.id === keepId;
          var c = el('label', { style: 'display:block;cursor:pointer;background:#fff;border:2px solid ' + (on ? '#047857' : '#E2E8F0') + ';border-radius:12px;padding:10px 12px' }, [
            el('div', { style: 'display:flex;align-items:center;gap:8px' }, [
              el('input', { type: 'radio', name: 'dupkeep' + g.keep_id, checked: on ? 'checked' : null, onchange: function () { keepId = l.id; paint(); } }),
              el('div', { style: 'font-weight:800;font-size:14px;min-width:0;overflow-wrap:anywhere' }, [leadLabel(l)]),
              el('span', { style: 'margin-left:auto;font-size:11px;font-weight:800;color:' + (on ? '#047857' : '#64748B') }, [on ? 'KEEP' : 'MERGE IN']),
            ]),
            el('div', { style: 'font-size:12.5px;color:#475569;margin-top:6px;line-height:1.55;overflow-wrap:anywhere' }, [
              l.company && l.name ? el('div', {}, ['👤 ' + l.name]) : null,
              l.email ? el('div', {}, ['✉️ ' + l.email]) : null,
              l.phone ? el('div', {}, ['📞 ' + l.phone]) : null,
              el('div', {}, [stageChip(l.stage), ' ', el('span', { style: 'color:#64748B' }, [(l.source || '') + ' · added ' + fmtDate((l.created_at || '').slice(0, 10))])]),
              el('div', { style: 'color:#64748B' }, ['History: ' + l.history]),
              el('a', { href: '#sales-lead?id=' + l.id, onclick: function (ev) { ev.stopPropagation(); }, style: 'color:' + ACCENT + ';font-weight:700;font-size:12px' }, ['Open →']),
            ]),
          ]);
          cards.appendChild(c);
        });
      }
      paint();
      body.appendChild(el('div', { style: 'margin-top:12px;padding-top:12px;border-top:1px solid #FDE68A' }, [
        el('div', { style: 'display:flex;gap:8px;align-items:center;flex-wrap:wrap' }, [
          el('span', { style: 'font-size:10.5px;font-weight:800;padding:2px 8px;border-radius:999px;color:#fff;background:' + (g.confidence === 'high' ? '#B45309' : '#94A3B8') }, [g.confidence === 'high' ? 'VERY LIKELY' : 'POSSIBLE']),
          el('span', { style: 'font-size:12.5px;color:#78350F' }, [g.reasons.join(' · ')]),
        ]),
        el('div', { style: 'font-size:13px;color:#334155;margin-top:6px;line-height:1.55' }, ['💡 ' + g.advice]),
        cards,
        el('div', { style: 'display:flex;gap:8px;flex-wrap:wrap;margin-top:10px' }, [
          btn('🔀 Merge into the kept lead', 'primary', async function (ev) {
            var keep = g.leads.filter(function (l) { return l.id === keepId; })[0];
            var others = g.leads.filter(function (l) { return l.id !== keepId; });
            ev.target.disabled = true;
            for (var i = 0; i < others.length; i++) { if (!(await mergeLeads(keep, others[i]))) { ev.target.disabled = false; return; } }
            reload();
          }),
          btn('Not the same', '', async function () {
            try { await Api.post('/sales/leads/not-duplicates', { ids: g.leads.map(function (l) { return l.id; }) }); toast('👍', 'Noted', 'They will not be suggested again.'); reload(); }
            catch (e) { toast('⚠️', 'Failed', e.message || '', '#DC2626'); }
          }),
        ]),
      ]));
    });
    host.appendChild(el('div', { style: 'background:#FFFBEB;border:1px solid #FCD34D;border-radius:14px;padding:12px 14px;margin-bottom:12px' }, [head, body]));
    return ids;
  }

  /* Merge any lead into this one, picked by search — for duplicates the check did not catch. */
  function openMergePicker(keep, all, done) {
    var M = window.KT && KT.Shell && KT.Shell.Modal;
    if (!M) return;
    var box = el('div');
    var q = input({ type: 'search', placeholder: '🔍 Find the duplicate — name, company, email…', style: 'width:100%;box-sizing:border-box;padding:9px 11px;border:1px solid #CBD5E1;border-radius:9px;font-size:14px' });
    var list = el('div', { style: 'margin-top:10px;max-height:min(50vh,420px);overflow-y:auto;border:1px solid #E2E8F0;border-radius:10px' });
    var picked = null;
    function paint() {
      clear(list);
      var t = q.value.trim().toLowerCase();
      (all || []).filter(function (l) { return l.id !== keep.id && (!t || [l.name, l.company, l.email, l.phone].join(' ').toLowerCase().indexOf(t) >= 0); }).slice(0, 60).forEach(function (l) {
        var on = picked && picked.id === l.id;
        list.appendChild(el('div', { style: 'padding:9px 12px;border-top:1px solid #F1F5F9;cursor:pointer;background:' + (on ? '#ECFDF5' : '#fff'), onclick: function () { picked = l; paint(); } }, [
          el('div', { style: 'font-weight:700;font-size:13.5px' }, [(on ? '✓ ' : '') + leadLabel(l)]),
          el('div', { style: 'font-size:12px;color:#64748B;overflow-wrap:anywhere' }, [[l.company && l.name ? l.name : null, l.email, l.phone, STAGE_LABEL[l.stage] || l.stage].filter(Boolean).join(' · ')]),
        ]));
      });
      if (!list.firstChild) list.appendChild(el('div', { style: 'padding:14px;color:#64748B;font-size:13px' }, ['No other leads match.']));
    }
    q.addEventListener('input', paint);
    box.appendChild(el('div', { style: 'font-size:13.5px;color:#475569;margin-bottom:10px' }, ['Pick the duplicate to merge INTO “' + leadLabel(keep) + '”. Its activity, proposals, bookings and files move here, empty details are filled in, and it is archived.']));
    box.appendChild(q); box.appendChild(list); paint();
    M.open({ title: '🔀 Merge into ' + leadLabel(keep), body: box, actions: [{ label: 'Cancel' }, { label: 'Merge', style: 'btn-primary', busyLabel: 'Merging…', handler: async function () {
      if (!picked) { toast('👆', 'Pick a lead', 'Choose the duplicate from the list.'); return false; }
      var ok = await mergeLeads(keep, picked, done);
      return ok ? undefined : false;
    } }] });
  }

  async function renderList(container) {
    clear(container);
    container.appendChild(hero('Leads', 'Every prospect in the pipeline.', '🎯'));
    if (KT.salesLeadTabs) container.appendChild(KT.salesLeadTabs('leads'));   // Leads | Cold reach (2026-10-30)
    container.appendChild(toolbar([
      btn('➕ New lead', 'primary', function () { openNewLead(load); }),
    ]));
    var search = input({ type: 'search', placeholder: '🔍 Search name, company, email…', style: 'width:100%;max-width:340px;padding:9px 11px;border:1px solid #d9e1ea;border-radius:9px;font-size:14px;box-sizing:border-box' });
    var dupHost = el('div'); container.appendChild(dupHost);
    container.appendChild(el('div', { style: 'margin-bottom:12px' }, [search]));
    var host = el('div'); container.appendChild(host);
    var DUP_IDS = {};
    async function load() {
      clear(host); host.appendChild(el('div', { style: 'padding:20px;color:#64748B' }, ['Loading…']));
      DUP_IDS = await renderDuplicates(dupHost, load);
      var data = await Api.get('/sales/leads', search.value ? { q: search.value } : {});
      META = { owners: data.owners || [], products: data.products || [], stats: data.stats || {} };
      clear(host);
      if (!data.leads || !data.leads.length) { host.appendChild(card([el('div', { style: 'text-align:center;color:#64748B;padding:20px' }, ['No leads yet. ', el('a', { href: '#sales-leads', style: 'color:' + ACCENT + ';font-weight:700', onclick: function (ev) { ev.preventDefault(); openNewLead(load); } }, ['Add one →'])])])); return; }
      /* data-kt-filtered opts this table OUT of the GLOBAL auto-search that
         kt-table-filter.js attaches to every #appMain table. Without it the screen
         showed TWO search boxes doing the same job (Anthony, 2026-08-31: "two filters
         that looks to be doing the same job") — and the two do not even agree: the
         box above searches the SERVER across name, company and email, including
         fields this table does not show, while the global one hides rows already on
         screen. The server search is the better answer, so the global one goes. Same
         fix as Announcements, which had the identical clash. */
      var tbl = el('table', { 'data-kt-filtered': '1', style: 'width:100%;border-collapse:collapse;background:#fff;border:1px solid #e6ebf1;border-radius:12px;overflow:hidden' });
      tbl.appendChild(el('thead', {}, [el('tr', { style: 'background:#f4f6f9;text-align:left' }, ['Company / contact', 'Stage', 'Est. revenue / yr', 'Owner', 'Follow-up', 'Last updated', ''].map(function (h) { return el('th', { style: 'padding:10px 12px;font-size:12px;color:#5a7080' }, [h]); }))]));
      var tb = el('tbody');
      data.leads.forEach(function (l) {
        tb.appendChild(el('tr', { style: 'border-top:1px solid #eef1f5;cursor:pointer', onclick: function () { go('sales-lead?id=' + l.id); } }, [
          el('td', { style: 'padding:10px 12px' }, [el('div', { style: 'font-weight:700' }, [l.company || l.name || '—',
              DUP_IDS[l.id] ? el('span', { title: 'Looks like another lead — see Possible duplicates above', style: 'display:inline-block;margin-left:8px;padding:1px 8px;border-radius:999px;font-size:10.5px;font-weight:800;color:#92400E;background:#FEF3C7;vertical-align:1px' }, ['Possible duplicate']) : null]),
            l.company && l.name ? el('div', { style: 'font-size:12px;color:#64748B' }, [l.name]) : null]),
          el('td', { style: 'padding:10px 12px' }, [stageChip(l.stage)]),
          el('td', { style: 'padding:10px 12px;font-weight:700;color:#047857;white-space:nowrap' }, [perYear(l)]),
          el('td', { style: 'padding:10px 12px;font-size:13px' }, [l.owner_name || '—']),
          el('td', { style: 'padding:10px 12px;font-size:13px;color:' + (l.follow_up_date && l.follow_up_date <= todayISO() ? '#C2410C' : '#64748B') }, [l.follow_up_date ? fmtDate(l.follow_up_date) : '—']),
          // The later of an edit to the lead and its newest activity (a call, text, email, meeting).
          (function () { var u = [l.updated_at, l.last_activity_at].filter(Boolean).sort().pop();
            return el('td', { style: 'padding:10px 12px;font-size:13px;color:#64748B;white-space:nowrap', 'data-sort': u || '' }, [u ? fmtStamp(u) : '—']); })(),
          /* ROW ACTIONS (Anthony, 2026-10-06: "leads section add kebab to view, edit,
             delete"). Plain labelled buttons in the last cell; kt-row-actions.js turns
             them into the portal's ⋮ menu (phones keep them as buttons). stopPropagation
             so an action does not also fire the row's open-on-click. */
          el('td', { style: 'padding:6px 12px;text-align:center;white-space:nowrap' }, [
            el('button', { type: 'button', title: 'View', onclick: function (ev) { ev.stopPropagation(); go('sales-lead?id=' + l.id); } }, ['👁 View']),
            el('button', { type: 'button', title: 'Edit', onclick: function (ev) { ev.stopPropagation(); go('sales-lead?id=' + l.id + '&edit=1'); } }, ['✏️ Edit']),
            el('button', { type: 'button', title: 'Merge another lead into this one', onclick: function (ev) { ev.stopPropagation(); openMergePicker(l, data.leads, load); } }, ['🔀 Merge…']),
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
      tbl.appendChild(tb); host.appendChild(el('div', { class: 'kt-leads-wide', style: 'overflow-x:auto' }, [tbl]));
      // On a phone the table gives way to cards (leadCard); the table stays for wider screens.
      var cards = el('div', { class: 'kt-leads-cards' });
      data.leads.forEach(function (l) { cards.appendChild(leadCard(l, DUP_IDS[l.id])); });
      host.appendChild(cards);
    }
    ensureLeadCss();
    var t; search.addEventListener('input', function () { clearTimeout(t); t = setTimeout(load, 300); });
    load();
  }

  /* ONE LEAD AS A PHONE CARD (2026-11-11, Anthony: "mobile view for leads table/lead record is not easy to read").
     The table squeezed into six tiny label/value pairs per lead. A card says it in four lines: who and where they
     stand, who it is and whose it is, what it is worth with the follow-up (orange once due), and when it last moved;
     📞 and ✉️ call or write without opening the lead. Tap anywhere else to open it. */
  function leadCard(l, dup) {
    var today = todayISO(), due = l.follow_up_date && l.follow_up_date <= today;
    var stop = function (ev) { ev.stopPropagation(); };
    var quick = [];
    if (l.phone) quick.push(el('a', { href: 'tel:' + String(l.phone).replace(/[^\d+]/g, ''), class: 'kt-lc-q', title: 'Call ' + l.phone, 'aria-label': 'Call', onclick: stop }, ['📞']));
    if (l.email) quick.push(el('a', { href: 'mailto:' + l.email, class: 'kt-lc-q', title: 'Email ' + l.email, 'aria-label': 'Email', onclick: stop }, ['✉️']));
    var u = [l.updated_at, l.last_activity_at].filter(Boolean).sort().pop();
    var sub = [l.company && l.name ? l.name : '', l.owner_name ? '👤 ' + l.owner_name : ''].filter(Boolean).join('  ·  ');
    var open = function () { go('sales-lead?id=' + l.id); };
    return el('div', { class: 'kt-lc', role: 'link', tabindex: '0', onclick: open, onkeydown: function (ev) { if (ev.key === 'Enter') open(); } }, [
      el('div', { class: 'kt-lc-top' }, [el('div', { class: 'kt-lc-name' }, [l.company || l.name || '—']), stageChip(l.stage)]),
      sub ? el('div', { class: 'kt-lc-sub' }, [sub]) : null,
      dup ? el('div', { style: 'margin-top:5px' }, [el('span', { style: 'display:inline-block;padding:1px 8px;border-radius:999px;font-size:10.5px;font-weight:800;color:#92400E;background:#FEF3C7' }, ['Possible duplicate'])]) : null,
      el('div', { class: 'kt-lc-row' }, [
        el('span', { class: 'kt-lc-rev' + (l.value == null ? ' none' : '') }, [l.value == null ? 'No value yet' : perYear(l)]),
        l.follow_up_date ? el('span', { class: 'kt-lc-fu' + (due ? ' due' : '') }, ['⏰ ' + (l.follow_up_date === today ? 'Follow up today' : (due ? 'Overdue · ' : '') + fmtDate(l.follow_up_date))]) : null,
        el('span', { style: 'flex:1 1 auto' }),
      ].concat(quick)),
      u ? el('div', { class: 'kt-lc-upd' }, ['Updated ' + fmtStamp(u)]) : null,
    ]);
  }

  // ─────────────────────────────── New lead ───────────────────────────────
  async function renderNew(container) {
    await renderList(container);
    try { history.replaceState(null, '', location.pathname + location.search + '#sales-leads'); } catch (e) {}
    openNewLead();
  }
  async function openNewLead(onCreated) {
    var M = window.KT && KT.Shell && KT.Shell.Modal;
    if (!M) { go('sales-new'); return; }
    if (!META) { try { var d = await Api.get('/sales/leads'); META = { owners: d.owners || [], products: d.products || [] }; } catch (e) { META = { owners: [] }; } }
    var f = {};
    var myId = (function () { try { return (JSON.parse(sessionStorage.getItem('kt_user') || '{}').id) || ''; } catch (e) { return ''; } })();
    var inner = el('div', {}, [
      formSection('Primary contact', '👤', [
        field('Contact name *', f.name = input({ placeholder: 'Jane Doe' })),
        field('Job title', f.title = input({ placeholder: 'Director / Manager' })),
        field('Email', f.email = input({ type: 'email', placeholder: 'jane@example.com' })),
        field('Phone', f.phone = input({ type: 'tel', placeholder: '(555) 123-4567' })),
      ]),
      formSection('Business owner / decision maker', '🧑‍💼', [
        field('Owner name', f.owner_name = input({ placeholder: 'Owner / principal' })),
        field('Owner title', f.owner_title = input({ placeholder: 'Owner, CEO…' })),
        field('Owner email', f.owner_email = input({ type: 'email', placeholder: 'owner@example.com' })),
        field('Owner phone', f.owner_phone = input({ type: 'tel', placeholder: '(555) 987-6543' })),
      ]),
      formSection('Business', '🏢', [
        fieldSpan('Company / childcare', f.company = input({ placeholder: 'Sunshine Daycare' }), 2),
        fieldSpan('Website', f.website = input({ placeholder: 'sunshinedaycare.com' }), 2),
        fieldWide('Street address', f.address = input({ placeholder: 'Start typing an address…' })),
        field('City', f.city = input({ placeholder: 'Toronto' })),
        field('Province / state', f.province = input({ placeholder: 'ON' })),
        field('Postal / ZIP', f.postal_code = input({ placeholder: 'M5V 2T6' })),
        field('Country', f.country = input({ placeholder: 'Canada' })),
      ], 4),
      formSection('What they use today', '🧩', [
        fieldWide('Current software / solution', f.current_solution = input({ placeholder: 'e.g. HiMama, spreadsheets, paper' })),
        field('# children', f.num_children = input({ type: 'number', min: '0', placeholder: '0' })),
        field('# locations / centres', f.num_locations = input({ type: 'number', min: '0', placeholder: '1' })),
        field('Lead source', f.source = selectEl(SOURCES, '')),
      ], 3),
      formSection('Pipeline', '📊', [
        field('Stage', f.stage = selectEl(STAGES.map(function (s) { return { v: s.key, l: s.label }; }), 'new')),
        field('Owner (rep)', f.owner_id = selectEl(ownerOpts(), myId)),
        field('Est. annual revenue ($)', f.value = input({ type: 'number', step: '0.01', min: '0', placeholder: 'Filled in when a proposal is sent' })),
        field('Expected close', f.expected_close = input({ type: 'date' })),
        field('Next follow-up', f.follow_up_date = input({ type: 'date' })),
      ], 3),
      el('div', { style: 'margin-top:14px' }, [field('Notes', f.notes = textarea({ placeholder: 'What are they looking for? Pain points, timeline, decision makers…', style: 'width:100%;padding:9px 11px;border:1px solid var(--kt-border,#d9e1ea);border-radius:9px;font-size:14px;box-sizing:border-box;min-height:96px;resize:vertical' }))]),
    ]);
    var formEl = el('form', { onsubmit: function (ev) { ev.preventDefault(); }, style: 'max-width:none !important;width:100%' }, [inner]);
    M.open({ title: '➕ New lead', body: formEl, large: true, actions: [{ label: 'Cancel' }, { label: 'Create lead', style: 'btn-primary', busyLabel: 'Saving…', handler: async function () {
      if (!f.name.value.trim()) { toast('⚠️', 'Name required', 'Add the contact name.', '#DC2626'); return false; }
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
        if (onCreated) onCreated(lead);
        go('sales-lead?id=' + lead.id);
      } catch (e) { toast('⚠️', 'Save failed', e.message || '', '#DC2626'); return false; }
    } }] });
    // Type-ahead address suggestions → auto-fill city / province / postal / country.
    attachAddressAutocomplete(f.address, { city: f.city, province: f.province, postal_code: f.postal_code, country: f.country });
  }

  /* ── VIEW FULL EMAIL (2026-11-24, Anthony: "some of the logged history are truncated, can you add a button to view
        the entire message"). An email entry keeps Outlook's short preview; this opens the whole message from the Sales
        mailbox (read only, never marked read), in a sandboxed frame: its styles cannot leak in and its scripts cannot
        run. Attachments download as they do in Sales → Email. ── */
  function openFullEmail(a) {
    var M = window.KT && KT.Shell && KT.Shell.Modal;
    var box = el('div', { style: 'display:flex;flex-direction:column;gap:10px;width:min(920px,88vw)' }, [
      el('div', { style: 'padding:24px;color:#64748B;font-size:13px' }, ['Opening the email…'])]);
    M.open({ title: '📧 Full email', large: true, body: box, actions: [{ label: 'Close' }] });
    function people(list) { return (list || []).map(function (p) { return p.name ? p.name + ' <' + p.email + '>' : p.email; }).join(', '); }
    function row(label, val) {
      return val ? el('div', { style: 'display:grid;grid-template-columns:64px 1fr;gap:8px;font-size:13px;line-height:1.45' }, [
        el('span', { style: 'color:#64748B;font-weight:700' }, [label]), el('span', { style: 'color:#0D1B2A;overflow-wrap:anywhere' }, [val])]) : '';
    }
    (async function () {
      var where, msg, path;
      try {
        where = await Api.get('/sales/activities/' + a.id + '/email');
        path = '/sales/email/messages/m?account_id=' + where.account_id + '&mid=' + encodeURIComponent(where.uid);
        msg = await Api.get(path + '&peek=1');
      } catch (e) {
        clear(box);
        box.appendChild(el('div', { style: 'padding:18px;color:#B91C1C;font-size:13.5px' }, [(e && e.message) || 'Could not open this email.']));
        return;
      }
      clear(box);
      box.appendChild(el('div', { style: 'display:flex;flex-direction:column;gap:3px;padding:2px 2px 8px;border-bottom:1px solid #E2E8F0' }, [
        el('div', { style: 'font-size:15px;font-weight:800;color:#0D1B2A;margin-bottom:4px' }, [msg.subject || '(no subject)']),
        row('From', msg.from ? people([msg.from]) : ''), row('To', people(msg.to)), row('Cc', people(msg.cc)),
        row('Date', msg.date || (a.created_at ? fmtStamp(a.created_at) : '')),
      ]));
      var f = document.createElement('iframe');
      f.setAttribute('sandbox', 'allow-popups allow-popups-to-escape-sandbox');
      f.setAttribute('referrerpolicy', 'no-referrer');
      f.setAttribute('title', 'Email');
      f.style.cssText = 'width:100%;height:62vh;border:1px solid #E2E8F0;border-radius:10px;background:#fff';
      f.srcdoc = '<!doctype html><html><head><meta charset="utf-8"><base target="_blank"><style>html,body{margin:0;background:#fff}body{padding:14px 16px;font:14px/1.55 "Segoe UI",Calibri,Arial,sans-serif;color:#1F2937;overflow-wrap:anywhere}img{max-width:100%;height:auto}table{max-width:100%}pre{white-space:pre-wrap}blockquote{margin:0 0 0 6px;padding-left:10px;border-left:3px solid #D5DCE4}</style></head><body>' + (msg.html || '') + '</body></html>';
      box.appendChild(f);
      if ((msg.attachments || []).length) {
        box.appendChild(el('div', { style: 'display:flex;flex-wrap:wrap;gap:6px;align-items:center' },
          [el('span', { style: 'font-size:12px;color:#64748B;font-weight:700' }, ['📎 Attachments'])].concat(msg.attachments.map(function (t) {
            return chip('⬇ ' + t.name + (t.size ? ' · ' + fmtBytes(t.size) : ''), function () {
              var token = sessionStorage.getItem('kt_token') || localStorage.getItem('kt_token');
              var agency = ''; try { agency = sessionStorage.getItem('kt_active_agency_id') || ''; } catch (e) {}
              var base = (window.KT && KT.API_BASE) || 'https://api.kiddietrac.com/api/v1';
              var h = { 'Authorization': 'Bearer ' + token }; if (agency) h['X-Active-Agency-Id'] = agency;
              fetch(base + '/sales/email/messages/m/attachment?account_id=' + where.account_id + '&mid=' + encodeURIComponent(where.uid) + '&section=' + encodeURIComponent(t.section), { headers: h })
                .then(function (r) { if (!r.ok) throw new Error('HTTP ' + r.status); return r.blob(); })
                .then(function (blob) {
                  var u = URL.createObjectURL(blob), x = document.createElement('a'); x.href = u; x.download = t.name || 'attachment';
                  document.body.appendChild(x); x.click(); setTimeout(function () { URL.revokeObjectURL(u); x.remove(); }, 1500);
                }).catch(function () { toast('⚠️', 'Could not download', t.name || '', '#DC2626'); });
            });
          }))));
      }
    })();
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
      var LIMIT = 30, longOne = lines.length > LIMIT;   // was 12 (2026-10-23: "the window needs to be bigger")
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

  function openLarge(title, node) {
    var M = window.KT && KT.Shell && KT.Shell.Modal;
    if (!M) return;
    M.open({ title: title, large: true, body: el('div', { style: 'min-height:60vh' }, [node]), actions: [{ label: 'Close' }] });
    // Nearly the whole screen - that is the point of "large".
    var m = document.querySelector('#modalRoot .modal');
    if (m) { m.style.setProperty('width', 'min(1500px, 96vw)', 'important'); m.style.setProperty('max-width', '96vw', 'important'); m.style.setProperty('max-height', '94vh', 'important'); }
  }

  function activityFilesNode(a, refresh) {
    var files = a.files || [];
    if (!files.length) return null;
    var box = el('div', { style: 'margin-top:8px;display:flex;flex-direction:column;gap:6px' });
    files.forEach(function (f) {
      var row = el('div', { style: 'border:1px solid #E2E8F0;border-radius:10px;padding:8px 10px;background:#FAFBFD' });
      var head = el('div', { style: 'display:flex;align-items:center;gap:8px;flex-wrap:wrap;font-size:13px' }, [
        el('span', { style: 'font-weight:700;color:#0F172A' }, [fileIcon(f.name) + ' ' + (f.kind === 'transcript' ? 'Transcript' : f.kind === 'video' ? 'Recording' : (f.kind === 'audio' || f.kind === 'recording') ? (/^voicemail-/i.test(f.name || '') ? 'Voicemail' : 'Audio') : f.kind === 'document' ? 'Document' : 'File')]),
        el('span', { style: 'color:#475569;overflow-wrap:anywhere' }, [f.name]),
        el('span', { style: 'color:#94A3B8' }, [fmtBytes(f.size)]),
      ]);
      row.appendChild(head);
      var extra = el('div', {});
      var open = false;
      function toggle(build) { return function () { open = !open; clear(extra); if (open) { extra.appendChild(build()); } }; }
      /* BIGGER WINDOWS (2026-10-23, Anthony: "notes section under leads with voicemails or
         calls the window needs to be bigger"). The transcript takes most of the screen and can
         be dragged taller; "Open large" shows it (or the recording) in a full-size popup. */
      var isAudio = f.kind === 'audio' || f.kind === 'recording' || /\.(mp3|wav|m4a|ogg)$/i.test(f.name || '');
      if (f.kind === 'transcript' && f.transcript) {
        head.appendChild(chip('Show transcript', toggle(function () {
          return el('div', { style: 'margin-top:8px;height:min(70vh,820px);min-height:240px;resize:vertical;overflow:auto;white-space:pre-wrap;font-size:13.5px;line-height:1.6;color:#1E293B;background:#fff;border:1px solid #E2E8F0;border-radius:8px;padding:12px 14px' }, [f.transcript]);
        })));
        head.appendChild(chip('⤢ Open large', function () { openLarge(f.name || 'Transcript', el('div', { style: 'white-space:pre-wrap;font-size:14px;line-height:1.65;color:#1E293B' }, [f.transcript])); }));
      }
      if (f.kind === 'video' || isAudio) {
        var mediaEl = function (big) {
          return el(f.kind === 'video' ? 'video' : 'audio', { controls: 'controls', preload: 'metadata', src: f.url,
            style: 'margin-top:8px;width:100%;' + (f.kind === 'video' ? 'max-height:' + (big ? '78vh' : '70vh') + ';border-radius:8px;background:#000' : 'height:48px') });
        };
        head.appendChild(chip('▶ Play', toggle(function () { return mediaEl(false); })));
        if (f.kind === 'video') head.appendChild(chip('⤢ Open large', function () { openLarge(f.name || 'Recording', mediaEl(true)); }));
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
    CURRENT_LEAD = lead;
    if (!META) { try { var d = await Api.get('/sales/leads'); META = { owners: d.owners || [], products: d.products || [] }; } catch (e) { META = { owners: [], products: [] }; } }
    clear(container);

    container.appendChild(hero(lead.company || lead.name || ('Lead #' + id), (lead.company && lead.name ? lead.name + ' · ' : '') + (lead.email || 'Lead details, activity and proposals.'), '🏢'));
    container.appendChild(toolbar([btn('← Pipeline', 'light', function () { go('sales'); })]));

    var body = el('div', { class: 'kt-lead-page' });

    // Stage mover
    var stageSel = selectEl(STAGES.map(function (s) { return { v: s.key, l: s.label }; }), lead.stage, { style: 'width:auto;min-width:170px;padding:9px 11px;border:1px solid var(--kt-border,#d9e1ea);border-radius:9px;font-size:14px' });
    stageSel.addEventListener('change', async function () {
      try { await Api.patch('/sales/leads/' + id, { stage: stageSel.value }); lead.stage = stageSel.value; toast('✅', 'Stage updated', STAGE_LABEL[stageSel.value] + (stageSel.value === 'won' ? ' · customer created under Sales → Customers' : '')); renderDetail(container, ctx); } catch (e) { toast('⚠️', 'Failed', e.message || '', '#DC2626'); }
    });
    body.appendChild(card([
      el('div', { style: 'display:flex;gap:14px;align-items:center;flex-wrap:wrap' }, [
        el('div', {}, [el('div', { style: 'font-size:11px;color:#5a7080;font-weight:700;margin-bottom:3px' }, ['STAGE']), stageSel]),
        // Won -> a customer (Sales -> Customers, 2026-11-10).
        lead.customer ? el('a', { href: '#sales-customers?id=' + lead.customer.id, title: 'Open this customer', style: 'align-self:flex-end;display:inline-flex;gap:6px;align-items:center;padding:7px 11px;border-radius:999px;background:#D1FAE5;color:#065F46;font-weight:800;font-size:13px;text-decoration:none' },
          ['🏆 Customer ' + (lead.customer.number || ''), lead.customer.no_services ? el('span', { style: 'color:#B91C1C;font-weight:700' }, [' · no services yet']) : null]) : null,
        el('div', { style: 'flex:1' }),
        el('div', { style: 'text-align:right' }, [
          el('div', { style: 'font-size:11px;color:#5a7080;font-weight:700' }, ['EST. ANNUAL REVENUE']),
          el('div', { style: 'font-size:20px;font-weight:800;color:#047857' }, [perYear(lead)]),
          lead.value != null ? el('div', { style: 'font-size:11.5px;color:#64748B;margin-top:2px' }, [
            (lead.value_quote_id ? 'From proposal ' + (lead.value_quote_number || '') : 'Entered by hand') + ' · ' + (lead.odds || 0) + '% at this stage → ',
            el('b', { style: 'color:#0369A1' }, [money(lead.weighted_value || 0)]),
          ]) : el('div', { style: 'font-size:11.5px;color:#94A3B8;margin-top:2px' }, ['Fills in when a proposal is sent']),
        ]),
      ]),
      leadQuick(lead),
    ], 'margin-bottom:14px'));
    body.lastChild.classList.add('kt-lead-stage');

    /* THE LEAD PAGE FILLS THE SCREEN (2026-10-09: "make this look better and fill the
       screen to utilise the space"). Two columns on a wide screen — the record on the
       left in labelled sections, the conversation (activity, proposals) on the right —
       and one column on a narrow one. No width cap: the page uses what it is given. */
    ensureLeadCss();
    var grid = el('div', { class: 'kt-lead-grid' });
    var left = el('div', { class: 'kt-lead-main' });
    var e = {};
    var recCard = card([
      el('div', { style: 'display:flex;align-items:center;gap:10px;margin-bottom:14px' }, [
        el('div', { style: 'font-weight:800;font-size:15px;flex:1' }, ['Lead record']),
        el('div', { style: 'font-size:12px;color:#94A3B8' }, [lead.created_at ? 'Added ' + fmtStamp(lead.created_at) : '']),
      ]),
      el('div', { class: 'kt-lead-sections' }, [
        formSection('Contact', '👤', [
          field('Contact name', e.name = input({ value: lead.name || '' })),
          field('Job title', e.title = input({ value: lead.title || '' })),
          field('Email', e.email = input({ type: 'email', value: lead.email || '' })),
          field('Phone', e.phone = input({ type: 'tel', value: lead.phone || '' })),
        ]),
        formSection('Business owner', '🧑‍💼', [
          field('Owner name', e.owner_name = input({ value: lead.owner_name || '', placeholder: 'Business owner' })),
          field('Owner title', e.owner_title = input({ value: lead.owner_title || '' })),
          field('Owner email', e.owner_email = input({ type: 'email', value: lead.owner_email || '' })),
          field('Owner phone', e.owner_phone = input({ type: 'tel', value: lead.owner_phone || '' })),
        ]),
        formSection('Company', '🏢', [
          fieldWide('Company', e.company = input({ value: lead.company || '' })),
          fieldWide('Website', e.website = input({ value: lead.website || '' })),
          field('Using today', e.current_solution = input({ value: lead.current_solution || '', placeholder: 'HiMama, paper…' })),
          field('# children', e.num_children = input({ type: 'number', min: '0', value: lead.num_children == null ? '' : lead.num_children })),
          field('# locations', e.num_locations = input({ type: 'number', min: '0', value: lead.num_locations == null ? '' : lead.num_locations })),
        ], 3),
        formSection('Deal', '💼', [
          field('Lead source', e.source = selectEl(sourceOptions(lead.source), sourceValue(lead.source))),
          field('Owner', e.owner_id = selectEl(ownerOpts(), lead.owner_id || '')),
          field('Expected close', e.expected_close = input({ type: 'date', value: lead.expected_close || '' })),
          field('Next follow-up', e.follow_up_date = input({ type: 'date', value: lead.follow_up_date || '' })),
          fieldWide('Est. annual revenue ($)' + (lead.value_quote_id ? ' · from proposal ' + (lead.value_quote_number || '') : ''), e.value = input({ type: 'number', step: '0.01', value: lead.value == null ? '' : lead.value, title: 'Changing it by hand keeps your figure until the next proposal is sent' })),
        ]),
        el('div', { class: 'kt-lead-wide' }, [formSection('Address', '📍', [
          fieldWide('Street address', e.address = input({ value: lead.address || '' })),
          field('City', e.city = input({ value: lead.city || '' })),
          field('Province / state', e.province = input({ value: lead.province || '' })),
          field('Postal / ZIP', e.postal_code = input({ value: lead.postal_code || '' })),
          field('Country', e.country = input({ value: lead.country || '' })),
        ], 4)]),
        el('div', { class: 'kt-lead-wide' }, [formSection('Notes', '🗒️', [fieldWide('About this lead', e.notes = textarea({ value: lead.notes || '', rows: 8 }))])]),
      ]),
      el('div', { class: 'kt-lead-actions', style: 'display:flex;gap:8px;margin-top:4px;flex-wrap:wrap' }, [
        (function () { var b = btn('✏️ Edit', 'primary', function () { leadReadOnly(recCard, false); }); b.setAttribute('data-ro-only', '1'); b.dataset.ktIconized = '1'; return b; })(),   // keeps its words (kt-icon-buttons would make it a bare ✏️)
        (function () { var b = btn('Cancel', 'light', function () { renderDetail(container, ctx); }); b.setAttribute('data-edit-only', '1'); b.dataset.ktIconized = '1'; return b; })(),
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
        btn('🔀 Merge…', '', async function () {
          var d2; try { d2 = await Api.get('/sales/leads'); } catch (er) { toast('⚠️', 'Failed', er.message || '', '#DC2626'); return; }
          openMergePicker(lead, d2.leads || [], function () { renderDetail(container, ctx); });
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
    ], 'margin-bottom:14px');
    recCard.classList.add('kt-lead-rec');
    left.appendChild(recCard);
    /* READ ONLY UNTIL EDIT (2026-11-04, Anthony: "details table under leads make this read only unless you hit the
       edit button"). */
    [].forEach.call(recCard.querySelectorAll('button'), function (b) { if (/Save changes/.test(b.textContent)) b.setAttribute('data-edit-only', '1'); });
    // Before the read-only switch, so read view can fit the notes to their words and Edit gets the 200px back.
    bigBox(e.notes, 200);   // notes run to paragraphs (2026-10-27: "the notes window is too small")
    leadReadOnly(recCard, true);
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
    var ACT_ICON = { note: '📝', call: '📞', email: '📧', meeting: '🤝', followup: '⏰', stage: '↗️', edit: '✏️', text: '💬' };
    var ACT_LABEL = { note: 'Note', call: 'Call', email: 'Email', meeting: 'Meeting', followup: 'Follow-up', stage: 'Stage change', edit: 'Field change', text: 'Text' };
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
          a.full_email ? chip('📧 View full email', function () { openFullEmail(a); }) : '',
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

  /* Call, write, visit, find: straight from the top of the lead (2026-11-11, mobile sweep). Only what the lead has. */
  function leadQuick(lead) {
    var tel = function (p) { return 'tel:' + String(p).replace(/[^\d+]/g, ''); };
    var addr = [lead.address, lead.city, lead.province, lead.postal_code, lead.country].filter(function (v) { return v && String(v).trim(); }).join(', ');
    var web = lead.website ? (/^https?:/i.test(lead.website) ? lead.website : 'https://' + lead.website) : '';
    var chips = [
      lead.phone ? el('a', { href: tel(lead.phone) }, ['📞 Call' + (lead.name ? ' ' + String(lead.name).split(' ')[0] : '')]) : null,
      lead.email ? el('a', { href: 'mailto:' + lead.email }, ['✉️ Email']) : null,
      lead.owner_phone && String(lead.owner_phone).replace(/\D/g, '') !== String(lead.phone || '').replace(/\D/g, '') ? el('a', { href: tel(lead.owner_phone) }, ['📞 Owner']) : null,
      web ? el('a', { href: web, target: '_blank', rel: 'noopener' }, ['🌐 Website']) : null,
      addr ? el('a', { href: 'https://www.google.com/maps/search/?api=1&query=' + encodeURIComponent(addr), target: '_blank', rel: 'noopener' }, ['📍 Map']) : null,
    ].filter(Boolean);
    chips.forEach(function (c) { c.setAttribute('data-kt-iconized', '1'); });
    return chips.length ? el('div', { class: 'kt-lead-quick' }, chips) : null;
  }
  function leadReadOnly(card, on) {
    card.classList.toggle('kt-lead-ro', on);
    [].forEach.call(card.querySelectorAll('.kt-lead-sections input, .kt-lead-sections textarea'), function (i) { i.readOnly = on; });
    [].forEach.call(card.querySelectorAll('.kt-lead-sections select'), function (x) { x.disabled = on; });
    // Inline and !important: a global field rule outranks any stylesheet here.
    /* TIGHT ROWS AT EVERY WIDTH (2026-11-11, Anthony: "the lead record table needs to be tightened up"). Read view is
       label | value rows, no filled boxes; the global field height (38px) would double every row, so it goes inline. */
    var RO = { 'border-color': 'transparent', 'background': 'transparent', 'color': '#0F172A', '-webkit-text-fill-color': '#0F172A', 'opacity': '1', 'box-shadow': 'none', 'cursor': 'default', 'min-height': '0', 'padding': '0' };
    /* An empty field reads "—" (2026-11-11): read-only boxes with nothing in them looked like missing rows, and an
       empty date showed "yyyy-mm-dd". The field's own hint comes back for editing. */
    [].forEach.call(card.querySelectorAll('.kt-lead-sections input, .kt-lead-sections textarea'), function (f) {
      if (on) {
        if (f.dataset.ktPh == null) f.dataset.ktPh = f.getAttribute('placeholder') || '';
        if (f.type === 'date' && !f.value) { f.dataset.ktDate = '1'; f.type = 'text'; }
        f.setAttribute('placeholder', '—');
      } else if (f.dataset.ktPh != null) {
        if (f.dataset.ktDate) { f.type = 'date'; delete f.dataset.ktDate; }
        if (f.dataset.ktPh) f.setAttribute('placeholder', f.dataset.ktPh); else f.removeAttribute('placeholder');
        delete f.dataset.ktPh;
      }
    });
    [].forEach.call(card.querySelectorAll('.kt-lead-sections input, .kt-lead-sections select, .kt-lead-sections textarea'), function (f) {
      /* Editing gets the field's OWN look back (2026-11-11): removing the read-only colours also took the field's
         inline border colour and padding with them, so Edit showed borderless boxes. Its values are kept first. */
      var keys = Object.keys(RO).concat(['height']);
      if (on) {
        if (!f.__ktOwn) { f.__ktOwn = {}; keys.forEach(function (k) { f.__ktOwn[k] = [f.style.getPropertyValue(k), f.style.getPropertyPriority(k)]; }); }
        Object.keys(RO).forEach(function (k) { f.style.setProperty(k, RO[k], 'important'); });
        if (f.tagName !== 'TEXTAREA') f.style.setProperty('height', '24px', 'important');
        else {   // notes as long as they are: one row to measure from, not the 8 it opens with for writing
          f.__ktRows = f.rows; f.rows = 1;
          f.style.setProperty('height', 'auto', 'important'); f.style.setProperty('height', Math.max(24, f.scrollHeight + 2) + 'px', 'important');
        }
      } else {
        keys.forEach(function (k) { var o = f.__ktOwn && f.__ktOwn[k]; if (o && o[0]) f.style.setProperty(k, o[0], o[1]); else f.style.removeProperty(k); });
        f.__ktOwn = null;
        if (f.__ktRows) { f.rows = f.__ktRows; f.__ktRows = null; }
      }
    });
    [].forEach.call(card.querySelectorAll('[data-ro-only]'), function (b) { b.style.display = on ? '' : 'none'; });
    [].forEach.call(card.querySelectorAll('[data-edit-only]'), function (b) { b.style.display = on ? 'none' : ''; });
    if (!on) { var f = card.querySelector('.kt-lead-sections input'); if (f) { try { f.focus(); } catch (e) {} } }
  }
  function ensureLeadCss() {
    if (document.getElementById('kt-lead-css')) { return; }
    var st = document.createElement('style'); st.id = 'kt-lead-css';
    st.textContent =
      '.kt-lead-page{width:100%;max-width:none;padding:0 4px;box-sizing:border-box}' +
      '.kt-lead-grid{display:grid;grid-template-columns:minmax(0,1.65fr) minmax(340px,1fr);gap:16px;align-items:start}' +
      '.kt-lead-side{position:sticky;top:12px;min-width:0}' +
      '.kt-lead-main{min-width:0}' +
      '.kt-lead-sections{display:grid;grid-template-columns:repeat(auto-fill,minmax(560px,1fr));gap:0 14px;align-items:stretch}.kt-lead-sections > div{box-sizing:border-box}' +
      '.kt-lead-tabs{display:flex;gap:6px;flex-wrap:wrap;margin:0 0 12px;border-bottom:1px solid #E2E8F0;padding-bottom:0}' +
      '.kt-lead-tab{display:inline-flex;align-items:center;gap:7px;height:36px;padding:0 14px;border:1px solid transparent;border-bottom:none;border-radius:10px 10px 0 0;background:transparent;color:#475569;font:inherit;font-size:13.5px;font-weight:700;cursor:pointer;margin-bottom:-1px}' +
      '.kt-lead-tab:hover{background:#F1F5F9}' +
      '.kt-lead-tab.on{background:#fff;color:#0C6070;border-color:#E2E8F0;box-shadow:inset 0 3px 0 #0C6070}' +
      '.kt-lead-count{display:inline-block;min-width:20px;padding:1px 7px;border-radius:999px;background:#E2E8F0;color:#334155;font-size:11.5px;text-align:center}' +
      '.kt-lead-tab.on .kt-lead-count{background:#0C6070;color:#fff}' +
      '.kt-lead-panel{min-width:0}' +
      '.kt-lead-sections .kt-lead-wide{grid-column:1 / -1}' +
      '.kt-lead-sections input,.kt-lead-sections select,.kt-lead-sections textarea{max-width:none !important;width:100%}' +
      '.kt-lead-ro .kt-lead-sections input,.kt-lead-ro .kt-lead-sections select,.kt-lead-ro .kt-lead-sections textarea{border-color:transparent !important;background:#F8FAFC !important;color:#0F172A !important;opacity:1 !important;-webkit-text-fill-color:#0F172A;cursor:default;box-shadow:none !important;resize:none;appearance:none;-webkit-appearance:none}' +
      '.kt-lead-ro .kt-lead-sections input::-webkit-calendar-picker-indicator{display:none}' +
      '.kt-lead-ro .kt-lead-sections input::placeholder,.kt-lead-ro .kt-lead-sections textarea::placeholder{color:#CBD5E1;-webkit-text-fill-color:#CBD5E1}' +
      /* Phone sweep (2026-11-11): quick chips, lead cards, and a flat read view of the record. */
      '.kt-lead-quick{display:flex;gap:6px;flex-wrap:wrap;margin-top:12px}' +
      '.kt-lead-quick a{display:inline-flex;align-items:center;gap:5px;height:30px;padding:0 12px;border:1px solid #D9E1EA;border-radius:999px;background:#fff;color:#0C6070;font-weight:700;font-size:12.5px;text-decoration:none;white-space:nowrap}' +
      '.kt-leads-cards{display:none}' +
      '.kt-lead-ro .kt-lead-sections div[data-kt-nofit]{padding:10px 14px 6px !important;margin-bottom:10px !important}' +
      '.kt-lead-ro .kt-lead-sections div[data-kt-nofit] > div:first-child{margin:0 0 4px !important}' +
      '#appMain#appMain .kt-lead-ro .kt-fs-grid{column-gap:24px !important;row-gap:0 !important;align-items:start !important}' +
      '#appMain#appMain .kt-lead-ro .kt-fs-grid > div{display:grid !important;grid-template-columns:118px minmax(0,1fr) !important;align-items:center !important;column-gap:8px;padding:3px 0;border-bottom:1px solid #EEF2F6}' +
      '#appMain#appMain .kt-lead-ro .kt-fs-grid > div > label{margin:0 !important;font-size:12px !important;font-weight:600 !important;color:#64748B !important;line-height:1.25}' +
      '#appMain#appMain .kt-lead-ro .kt-fs-grid input,#appMain#appMain .kt-lead-ro .kt-fs-grid select{font-size:13.5px !important;font-weight:600;line-height:24px;text-overflow:ellipsis}' +
      '#appMain#appMain .kt-lead-ro .kt-fs-grid > div:has(textarea){grid-template-columns:minmax(0,1fr) !important;row-gap:2px;border-bottom:0}' +
      '#appMain#appMain .kt-lead-ro .kt-fs-grid textarea{font-size:13.5px !important;line-height:1.45}' +
      '.kt-lead-ro .kt-lead-sections{gap:0 10px !important}' +
      '#appMain#appMain .kt-lead-stage{padding:12px 16px !important}.kt-lead-stage .kt-lead-quick{margin-top:8px}' +
      '.kt-lead-rec > div:first-child{margin-bottom:8px !important}' +
      '.kt-lc{background:#fff;border:1px solid #E6EBF1;border-radius:14px;padding:11px 13px;box-shadow:0 1px 2px rgba(15,23,42,.04);cursor:pointer;min-width:0}' +
      '.kt-lc:active{background:#F8FAFC}' +
      '.kt-lc-top{display:flex;align-items:center;gap:8px;min-width:0}' +
      '.kt-lc-name{flex:1 1 auto;min-width:0;font-weight:800;font-size:15px;color:#0F172A;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}' +
      '.kt-lc-sub{font-size:12.5px;color:#64748B;margin-top:2px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}' +
      '.kt-lc-row{display:flex;align-items:center;gap:8px;margin-top:8px;flex-wrap:wrap;min-height:32px}' +
      '.kt-lc-rev{font-weight:800;color:#047857;font-size:14.5px}.kt-lc-rev.none{color:#94A3B8;font-weight:600;font-size:12.5px}' +
      '.kt-lc-fu{font-size:12px;font-weight:700;color:#475569;background:#F1F5F9;border-radius:999px;padding:3px 9px;white-space:nowrap}' +
      '.kt-lc-fu.due{color:#9A3412;background:#FFEDD5}' +
      '.kt-lc-q{display:inline-flex;align-items:center;justify-content:center;width:34px;height:34px;border-radius:50%;border:1px solid #E2E8F0;background:#fff;text-decoration:none;font-size:15px;flex:0 0 auto}' +
      '.kt-lc-upd{font-size:11.5px;color:#94A3B8;margin-top:4px}' +
      '@media (max-width:700px){' +
        '.kt-leads-wide{display:none !important}.kt-leads-cards{display:flex;flex-direction:column;gap:6px}' +
        '.kt-lc{padding:9px 11px;border-radius:12px}.kt-lc-name{font-size:14.5px}.kt-lc-sub{font-size:12px;margin-top:1px}' +
        '.kt-lc-row{margin-top:5px;min-height:30px;gap:6px}.kt-lc-q{width:30px;height:30px;font-size:14px}.kt-lc-upd{margin-top:2px;font-size:11px}' +
        '.kt-lead-page{padding:0}' +
        '#appMain#appMain .kt-lead-stage{padding:10px 12px !important;margin-bottom:8px !important;border-radius:12px !important}' +
        '.kt-lead-stage select{min-width:0 !important;padding:6px 9px !important;font-size:13.5px !important}' +
        '.kt-lead-stage .kt-lead-quick{margin-top:8px;gap:5px}.kt-lead-quick a{height:28px;padding:0 10px;font-size:12px}' +
        '#appMain#appMain .kt-lead-rec{padding:10px 10px 12px !important;margin-bottom:8px !important;border-radius:12px !important}' +
        '.kt-lead-rec > div:first-child{margin-bottom:4px !important}' +
        '#appMain .kt-lead-rec .kt-lead-sections div[data-kt-nofit]{background:transparent !important;border:0 !important;border-top:1px solid #EEF1F5 !important;border-radius:0 !important;padding:8px 0 2px !important;margin:0 !important}' +
        '.kt-lead-sections div[data-kt-nofit] > div:first-child{margin:0 0 2px !important;font-size:11px !important}' +
        '#appMain#appMain .kt-lead-rec .kt-fs-grid{gap:8px !important}' +
        '#appMain#appMain .kt-lead-ro .kt-fs-grid{gap:0 !important}' +
        '#appMain#appMain .kt-lead-ro .kt-fs-grid > div{display:grid !important;grid-template-columns:108px minmax(0,1fr) !important;align-items:center !important;column-gap:8px;row-gap:0;padding:4px 0;border-bottom:1px solid #F1F5F9}' +
        '#appMain#appMain .kt-lead-ro .kt-fs-grid > div:last-child{border-bottom:0}' +
        '#appMain#appMain .kt-lead-ro .kt-fs-grid > div > label{margin:0 !important;font-size:12px !important;font-weight:600 !important;color:#64748B !important;text-align:left !important;line-height:1.25}' +
        '#appMain#appMain .kt-lead-ro .kt-fs-grid input,#appMain#appMain .kt-lead-ro .kt-fs-grid select{padding:0 !important;min-height:0 !important;height:22px !important;font-size:13.5px !important;font-weight:600;line-height:22px;text-overflow:ellipsis}' +
        '#appMain#appMain .kt-lead-ro .kt-fs-grid > div:has(textarea){grid-template-columns:minmax(0,1fr) !important;row-gap:2px}' +
        '#appMain#appMain .kt-lead-ro .kt-fs-grid textarea{padding:0 !important;font-size:13.5px !important}' +
        '.kt-lead-rec .kt-lead-actions{margin-top:8px !important;gap:6px !important}.kt-lead-rec .kt-lead-actions .kt-btn,.kt-lead-rec .kt-lead-actions button{flex:1 1 auto}' +
      '}' +
      '@media (max-width:900px){.kt-lead-sections{grid-template-columns:minmax(0,1fr)}.kt-lead-tab{flex:1 1 auto;justify-content:center;padding:0 8px}}' +
      '@media (max-width:980px){.kt-lead-grid{grid-template-columns:minmax(0,1fr)}.kt-lead-side{position:static}}';
    document.head.appendChild(st);
  }

  /* ONLINE SIGNING (2026-10-16, Anthony: "can we make this where it can be signed virtually and
     countersigned by kiddietrac virtually to complete the proposal process"). The prospect signs
     at proposal.html?t=… (link in the proposal email, or ⋮ → Copy signing link); KiddieTrac
     countersigns here; the fully signed PDF is emailed, filed on the lead, and the lead is Won. */
  var QSTATUS_LABEL = { draft: 'Draft', sent: 'Sent', signed: '✍️ Signed — countersign', accepted: '✅ Signed by both', declined: 'Declined' };
  var QSTATUS_COLOR = { draft: '#94A3B8', sent: '#0EA5E9', signed: '#D97706', accepted: '#10B981', declined: '#EF4444' };
  function signLine(leadId, q, container, ctx) {
    var t = null, extra = null, st = 'font-size:12px;margin-top:6px;line-height:1.45;';
    if (q.status === 'signed' && q.client_signed_at) {
      t = el('div', { style: st + 'color:#92400E' }, ['Signed by ' + (q.client_signer_name || 'the customer') + (q.client_signer_title ? ' (' + q.client_signer_title + ')' : '') + ' · ' + fmtStamp(q.client_signed_at) + ' — waiting for KiddieTrac to countersign.']);
      extra = el('div', { style: 'margin-top:6px' }, [btn('✍️ Countersign', 'primary', function (ev) { ev.stopPropagation(); countersignQuote(q, container, ctx); })]);
    } else if (q.countersigned_at) {
      t = el('div', { style: st + 'color:#047857' }, ['Signed by ' + (q.client_signer_name || 'the customer') + ' · ' + fmtStamp(q.client_signed_at) + ' and countersigned by ' + (q.countersigner_name || 'KiddieTrac') + ' · ' + fmtStamp(q.countersigned_at)]);
    } else if (q.status === 'declined' && q.declined_at) {
      t = el('div', { style: st + 'color:#B91C1C' }, ['Declined online · ' + fmtStamp(q.declined_at) + (q.decline_reason ? ' — “' + q.decline_reason + '”' : '')]);
    } else if (q.sign_viewed_at) {
      t = el('div', { style: st + 'color:#047857' }, ['👁 Viewed online by the prospect · ' + fmtStamp(q.sign_viewed_at) + ' · not signed yet']);
    }
    /* RECEIVED / OPENED (2026-10-23, Anthony: "add something to track to see if they received
       and opened"). App\Support\ProposalTracking. */
    var trk = [];
    if (q.email_send_status === 'failed') trk.push(['⚠️ The last email did not send' + (q.email_send_error ? ': ' + q.email_send_error : ''), '#B91C1C']);
    else if (q.email_sent_at) trk.push(['📤 Emailed ' + fmtStamp(q.email_sent_at) + ' · accepted for delivery', '#64748B']);
    if (q.email_open_count) trk.push(['📖 Email opened ' + q.email_open_count + '× · first ' + fmtStamp(q.email_opened_at) + (q.email_open_count > 1 && q.email_last_opened_at ? ' · last ' + fmtStamp(q.email_last_opened_at) : ''), '#047857']);
    else if (q.email_sent_at && !q.client_signed_at) trk.push(['📭 Email not opened yet', '#94A3B8']);
    if (q.pdf_downloaded_at) trk.push(['⬇️ PDF downloaded from the sign page · ' + fmtStamp(q.pdf_downloaded_at), '#047857']);
    var track = trk.length ? el('div', { title: 'Opens are seen when the prospect\u2019s mail app shows images. Some block images (no open is reported) and Apple Mail can report an open on its own; CC/BCC copies count too.' },
      trk.map(function (x) { return el('div', { style: st + 'margin-top:3px;color:' + x[1] }, [x[0]]); })) : null;
    // Automatic follow-up (2026-10-23): when the next one goes, and how many have gone.
    var fu = null;
    if (q.followup_next_at && !q.client_signed_at) {
      fu = el('div', { style: st + 'color:#B45309' }, ['⏰ Automatic follow-up ' + fmtStamp(q.followup_next_at) + ' (' + ((q.followups_sent || 0) + 1) + ' of ' + (q.followup_max || 1) + ')'
        + (q.last_followup_at ? ' · last sent ' + fmtStamp(q.last_followup_at) : '')]);
    } else if (q.last_followup_at) {
      fu = el('div', { style: st + 'color:#64748B' }, ['⏰ Followed up automatically ' + (q.followups_sent || 1) + '× · last ' + fmtStamp(q.last_followup_at)]);
    }
    // Contract, approval and the customer it became (2026-10-23).
    var conv = [];
    if (q.contract_name) conv.push(el('div', { style: st + 'color:#047857' }, ['📎 Signed contract attached · ' + q.contract_name + (q.contract_uploaded_at ? ' · ' + fmtStamp(q.contract_uploaded_at) : '')]));
    if (q.approval_status === 'rejected') conv.push(el('div', { style: st + 'color:#B91C1C' }, ['✖ Not approved' + (q.rejected_at ? ' · ' + fmtStamp(q.rejected_at) : '') + (q.reject_reason ? ' — “' + q.reject_reason + '”' : '')]));
    if (q.converted_agency_id) {
      conv.push(el('div', { style: st + 'color:#047857;font-weight:700' }, ['🏢 Approved' + (q.approved_at ? ' ' + fmtStamp(q.approved_at) : '') + ' — now a customer: ' + (q.converted_agency_name || ('agency #' + q.converted_agency_id))
        + ' · billing not started' + (q.invite_sent_at ? ' · welcome invite sent ' + fmtStamp(q.invite_sent_at) : ' · welcome invite not sent yet')]));
      if (!q.invite_sent_at && isSuperAdmin()) conv.push(el('div', { style: 'margin-top:6px' }, [btn('📨 Send welcome invite', 'primary', function (ev) { ev.stopPropagation(); sendWelcomeInvite(q, container, ctx); })]));
    } else if (isSuperAdmin() && q.status !== 'draft' && q.status !== 'declined' && (q.contract_name || q.countersigned_at)) {
      conv.push(el('div', { style: 'margin-top:6px;display:flex;gap:6px;flex-wrap:wrap' }, [btn('✅ Approve & set up customer', 'primary', function (ev) { ev.stopPropagation(); approveQuote(q, container, ctx); })]));
    }
    var convBox = conv.length ? el('div', {}, conv) : null;
    return (t || fu || track || convBox) ? el('div', {}, [track, t, extra, fu, convBox]) : null;
  }
  /* ── APPROVE / REJECT, AND THE PROSPECT BECOMES A CUSTOMER (2026-10-23) ─────────────
     Anthony: "wire leads to have function to approve the proposals manually or reject and if
     approved move this prospect to a real customer ... a contract will need to be signed and
     uploaded/attached before anything can be moved forward". Server: SalesConversionController.
     The e-signed proposal (both sides) or an uploaded contract unlocks Approve; the customer is
     set up with the plan and price but not billed yet; the welcome invite waits for a click. */
  function attachContract(q, container, ctx) {
    var fi = el('input', { type: 'file', accept: '.pdf,.jpg,.jpeg,.png,.heic,.doc,.docx', style: 'display:none' });
    document.body.appendChild(fi);
    fi.addEventListener('change', async function () {
      var f = fi.files && fi.files[0]; fi.remove();
      if (!f) return;
      if (f.size > 15 * 1024 * 1024) { toast('⚠️', 'Too large', 'The contract must be under 15 MB.', '#DC2626'); return; }
      var fd = new FormData(); fd.append('file', f);
      toast('📎', 'Uploading the contract…', f.name);
      try { await Api.postForm('/sales/quotes/' + q.id + '/contract', fd); toast('📎', 'Contract attached', f.name); renderDetail(container, ctx); }
      catch (e) { toast('⚠️', 'Upload failed', e.message || '', '#DC2626'); }
    });
    fi.click();
  }
  function viewContract(q) {
    fetch(apiBase() + '/sales/quotes/' + q.id + '/contract', { headers: { Authorization: 'Bearer ' + tok() } })
      .then(function (r) { if (!r.ok) throw new Error('HTTP ' + r.status); return r.blob(); })
      .then(function (b) { var u = URL.createObjectURL(b); var a = document.createElement('a'); a.href = u; a.download = q.contract_name || 'contract.pdf'; document.body.appendChild(a); a.click(); a.remove(); setTimeout(function () { URL.revokeObjectURL(u); }, 1500); })
      .catch(function (e) { toast('⚠️', 'Could not open the contract', e.message || '', '#DC2626'); });
  }
  async function removeContract(q, container, ctx) {
    if (!(await window.KT.confirm({ title: 'Remove the contract?', description: (q.contract_name || 'The attached contract') + ' is removed from ' + (q.number || 'this proposal') + '.', okLabel: 'Remove' }))) return;
    try { await Api.delete('/sales/quotes/' + q.id + '/contract'); toast('🗑', 'Contract removed'); renderDetail(container, ctx); }
    catch (e) { toast('⚠️', 'Could not remove it', e.message || '', '#DC2626'); }
  }
  async function rejectQuote(q, container, ctx) {
    var r = await window.KT.prompt({ title: 'Don’t approve ' + (q.number || 'this proposal') + '?', okLabel: 'Don’t approve',
      description: 'It is marked declined and its automatic follow-ups stop. The lead stays open, so you can send a new proposal.',
      fields: [{ key: 'reason', label: 'Why (kept on the lead)', type: 'textarea', rows: 3, placeholder: 'e.g. pricing below our floor; needs a different plan' }] });
    if (!r) return;
    if (!String(r.reason || '').trim()) { toast('⚠️', 'Give a reason', 'It is kept on the lead’s history.', '#DC2626'); return; }
    try { await Api.post('/sales/quotes/' + q.id + '/reject', { reason: r.reason.trim() }); toast('✖', 'Not approved', q.number || ''); renderDetail(container, ctx); }
    catch (e) { toast('⚠️', 'Could not save', e.message || '', '#DC2626'); }
  }
  async function sendWelcomeInvite(q, container, ctx) {
    if (!(await window.KT.confirm({ title: 'Send the welcome invite?', description: (q.converted_agency_name || 'The new customer') + '’s admin gets the email to set their password and sign in.', okLabel: 'Send invite' }))) return;
    try { await Api.post('/sales/quotes/' + q.id + '/send-invite', {}); toast('📨', 'Welcome invite sent'); renderDetail(container, ctx); }
    catch (e) { toast('⚠️', 'Not sent', e.message || '', '#DC2626'); }
  }
  async function approveQuote(q, container, ctx) {
    var d;
    try { d = await Api.get('/sales/quotes/' + q.id + '/conversion'); } catch (e) { toast('⚠️', 'Could not load', e.message || '', '#DC2626'); return; }
    var ck = d.checks || {}, t = d.terms || {}, c = d.customer || {};
    var ov = el('div', { style: 'position:fixed;inset:0;z-index:10000;background:rgba(13,27,42,.55);display:flex;align-items:flex-start;justify-content:center;padding:24px 12px;overflow:auto', onclick: function (ev) { if (ev.target === ov) ov.remove(); } });
    var F = {};
    function fld(key, label, val, attrs) { F[key] = input(Object.assign({ value: val == null ? '' : String(val) }, attrs || {})); return field(label, F[key]); }
    var grid = function (kids) { return el('div', { 'data-kt-noband': '1', style: 'display:grid;grid-template-columns:repeat(auto-fill,minmax(200px,250px));gap:10px 14px;margin-bottom:12px' }, kids); };
    var head = function (s) { return el('div', { style: 'font-size:11.5px;font-weight:800;letter-spacing:.5px;text-transform:uppercase;color:#0C6070;margin:14px 0 8px' }, [s]); };
    var check = function (ok, text) { return el('div', { style: 'font-size:13.5px;margin:3px 0;color:' + (ok ? '#047857' : '#B91C1C') }, [(ok ? '✓ ' : '✗ ') + text]); };
    var box = el('div', { style: 'background:#fff;border-radius:16px;max-width:860px;width:100%;padding:18px 20px;box-sizing:border-box' });
    box.appendChild(el('div', { style: 'display:flex;justify-content:space-between;align-items:center;gap:8px' }, [
      el('div', { style: 'font-size:18px;font-weight:800' }, ['✅ Approve ' + (q.number || 'proposal') + ' and set up the customer']),
      el('button', { type: 'button', 'aria-label': 'Close', style: 'border:0;background:none;font-size:22px;cursor:pointer;color:#64748B', onclick: function () { ov.remove(); } }, ['×'])]));
    box.appendChild(head('Before anything moves'));
    box.appendChild(check(ck.signed_online, ck.signed_online ? 'Signed online by the customer and countersigned' : (ck.client_signed ? 'Signed online by the customer — not countersigned yet' : 'Not signed online')));
    box.appendChild(check(!!ck.contract, ck.contract ? 'Signed contract attached: ' + ck.contract.name : 'No signed contract attached'));
    if (!ck.contract_ok) {
      box.appendChild(el('div', { style: 'margin:8px 0 4px;padding:10px 12px;border-radius:10px;background:#FEF2F2;color:#991B1B;font-size:13.5px' }, ['Attach the signed contract (or have the proposal signed online by both sides) before approving.']));
      box.appendChild(el('div', { style: 'display:flex;gap:8px;margin-top:10px' }, [btn('📎 Attach signed contract', 'primary', function () { ov.remove(); attachContract(q, container, ctx); }), btn('Close', 'light', function () { ov.remove(); })]));
      document.body.appendChild(ov); ov.appendChild(box); return;
    }
    if (ck.already_converted) { box.appendChild(el('div', { style: 'margin-top:8px;color:#B91C1C' }, ['This prospect is already a customer.'])); document.body.appendChild(ov); ov.appendChild(box); return; }

    box.appendChild(head('Licence (from the proposal — adjust if needed)'));
    F.plan = selectEl([{ v: 'starter', l: 'Starter' }, { v: 'growth', l: 'Growth' }, { v: 'enterprise', l: 'Agency (enterprise)' }], t.plan_code);
    var cb = function (key, label, on) { F[key] = el('input', { type: 'checkbox', style: 'width:17px;height:17px' }); F[key].checked = !!on; return el('label', { style: 'display:flex;gap:7px;align-items:center;font-size:13.5px;font-weight:600;cursor:pointer' }, [F[key], label]); };
    box.appendChild(grid([field('Plan', F.plan)]));
    box.appendChild(el('div', { style: 'display:flex;flex-wrap:wrap;gap:16px;margin-bottom:6px' }, [cb('white', 'White-label (hide “powered by KiddieTrac”)', t.white_label), cb('sms', 'Texting on (SMS bundle)', t.sms), cb('reseller', 'Also set up as a Reseller', t.reseller)]));

    box.appendChild(head('Commercials — recorded, not billed yet'));
    F.interval = selectEl([{ v: 'monthly', l: 'Monthly' }, { v: 'annual', l: 'Yearly' }, { v: 'quarterly', l: 'Quarterly' }], t.billing_interval);
    F.currency = selectEl([{ v: 'CAD', l: 'CAD' }, { v: 'USD', l: 'USD' }], t.currency);
    box.appendChild(grid([fld('amount', 'Recurring price', t.plan_amount, { type: 'number', step: '0.01', min: '0' }), field('Every', F.interval), field('Currency', F.currency),
      fld('tax_bps', 'Tax (%)', ((t.tax_rate_bps || 0) / 100), { type: 'number', step: '0.01', min: '0' }), fld('tax_label', 'Tax name', t.tax_label || '')]));
    var notes = [];
    if (t.one_time && t.one_time.length) notes.push('One-time items (kept on the customer’s record, not invoiced): ' + t.one_time.map(function (o) { return o.description + ' ' + money(o.amount, t.currency); }).join(', '));
    if (t.discount) notes.push('The proposal’s discount of ' + money(t.discount, t.currency) + ' is already taken off the recurring price.');
    if (t.currency_note) notes.push(t.currency_note);
    notes.push('Billing starts when you switch it on in Platform → Invoices → Billing plans.');
    notes.forEach(function (n) { box.appendChild(el('div', { style: 'font-size:12.5px;color:#64748B;margin:2px 0' }, [n])); });

    box.appendChild(head('The customer'));
    box.appendChild(grid([fld('name', 'Agency / business name', c.name), fld('contact_email', 'Contact email', c.contact_email, { type: 'email' }), fld('contact_phone', 'Contact phone', c.contact_phone),
      fld('address_line1', 'Street address', c.address_line1), fld('city', 'City', c.city), fld('province', 'Province / state', c.province), fld('postal_code', 'Postal / ZIP', c.postal_code),
      fld('country', 'Country (2 letters)', c.country, { maxlength: '2' }), fld('timezone', 'Time zone', c.timezone)]));
    box.appendChild(head('Their first admin'));
    box.appendChild(grid([fld('admin_first_name', 'First name', c.admin_first_name), fld('admin_last_name', 'Last name', c.admin_last_name), fld('admin_email', 'Email', c.admin_email, { type: 'email' }), fld('admin_phone', 'Phone', c.admin_phone)]));
    if (c.admin_email_taken) box.appendChild(el('div', { style: 'font-size:12.5px;color:#B45309;margin:-4px 0 8px' }, ['That email already has a KiddieTrac account — use a different email for the new admin.']));
    box.appendChild(el('div', { style: 'font-size:12.5px;color:#64748B' }, ['Their welcome invite is not sent yet — use “Send welcome invite” on the proposal when you are ready.']));
    var msg = el('div', { style: 'font-size:13px;margin-top:10px;min-height:18px' });
    box.appendChild(msg);
    var go = btn('✅ Approve & set up customer', 'primary', async function () {
      var v = function (k) { return String(F[k].value || '').trim(); };
      var body = { plan_code: F.plan.value, plan_amount: parseFloat(v('amount') || '0'), billing_interval: F.interval.value, currency: F.currency.value,
        white_label: F.white.checked, sms: F.sms.checked, reseller: F.reseller.checked,
        tax_rate_bps: Math.round(parseFloat(v('tax_bps') || '0') * 100), tax_label: v('tax_label') || null,
        name: v('name'), contact_email: v('contact_email'), contact_phone: v('contact_phone'), address_line1: v('address_line1'), city: v('city'), province: v('province'),
        postal_code: v('postal_code'), country: v('country').toUpperCase(), timezone: v('timezone') || 'America/Toronto', default_locale: c.default_locale || 'en',
        admin_first_name: v('admin_first_name'), admin_last_name: v('admin_last_name'), admin_email: v('admin_email'), admin_phone: v('admin_phone') };
      go.disabled = true; msg.style.color = '#475569'; msg.textContent = 'Setting up the customer…';
      try {
        var r = await Api.post('/sales/quotes/' + q.id + '/approve', body);
        ov.remove();
        toast('🏢', 'Customer set up', body.name + (r.reseller ? ' · ' + r.reseller : ''));
        renderDetail(container, ctx);
      } catch (e) {
        go.disabled = false; msg.style.color = '#B91C1C';
        var errs = e && e.data && e.data.errors ? Object.keys(e.data.errors).map(function (k) { return e.data.errors[k][0]; }).join(' ') : '';
        msg.textContent = (e.message || 'Could not set up the customer.') + (errs && errs !== e.message ? ' ' + errs : '');
      }
    });
    box.appendChild(el('div', { style: 'display:flex;gap:8px;margin-top:8px;flex-wrap:wrap' }, [go, btn('Cancel', 'light', function () { ov.remove(); })]));
    ov.appendChild(box); document.body.appendChild(ov);
  }

  async function copySignLink(q) {
    try {
      var r = await Api.post('/sales/quotes/' + q.id + '/sign-link', {});
      var ok = false;
      try { await navigator.clipboard.writeText(r.url); ok = true; } catch (e) {}
      if (ok) toast('🔗', 'Signing link copied', 'Anyone with this link can sign for the prospect. Share it only with them.');
      else if (window.KT && KT.prompt) await KT.prompt({ title: 'Signing link', description: 'Copy this link and send it only to the prospect.', fields: [{ key: 'url', label: 'Link', value: r.url }], okLabel: 'Done' });
    } catch (e) { toast('⚠️', 'Could not get the link', e.message || '', '#DC2626'); }
  }
  function countersignQuote(q, container, ctx) {
    /* Shell.Modal since 2026-11-10 (popup rules: one header, the close button inside it, one scroll region). The date
       shown is today's date at head office, the one the signed PDF prints under the signature. */
    var pb = q.prepared_by || {};
    var nameIn = input({ value: pb.name || '', autocomplete: 'name' });
    var titleIn = input({ value: pb.title || 'KiddieTrac Sales' });
    var dateIn = input({ value: hqToday(), readonly: 'readonly', tabindex: '-1', 'aria-label': 'Date signed' }); dateIn.style.background = '#F8FAFC'; dateIn.style.color = '#475569';
    // The shared pad (kt-signature-pad.js) since 2026-11-06: a saved signature is one tap.
    var padHost = el('div', { 'aria-label': 'Draw your signature' });
    var pad = KT.signaturePadInline(padHost, { height: 150, context: 'sales_quote:' + q.id });
    var agree = el('input', { type: 'checkbox', style: 'width:18px;height:18px;flex:0 0 auto;margin-top:2px' });
    var msg = el('div', { style: 'font-size:13px;margin-top:8px;color:#B91C1C' });
    var body = el('div', {}, [
      el('div', { style: 'font-size:13px;color:#475569;line-height:1.5;margin-bottom:10px' }, ['Signed by ' + (q.client_signer_name || 'the customer') + (q.client_signer_title ? ', ' + q.client_signer_title : '') + (q.client_signer_org ? ' for ' + q.client_signer_org : '') + ' · ' + fmtStamp(q.client_signed_at) + ' — your signature completes the proposal: the fully signed PDF is emailed to the prospect (copying the sales contact), filed on the lead, and the lead moves to Won.']),
      el('div', { style: 'margin-bottom:10px' }, [btn('👁 View what they signed', 'light', function () { viewQuote(q.lead_id, q, container, ctx); })]),
      el('div', { style: 'display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:10px' }, [field('Print your name', nameIn), field('Title', titleIn), field('Date', dateIn)]),
      el('div', { style: 'font-size:12px;font-weight:700;color:#5a7080;margin:12px 0 6px' }, ['Signature']),
      padHost,
      el('button', { type: 'button', 'data-kt-inpage': '1', style: 'border:0;background:none;color:' + ACCENT + ';font:inherit;font-size:13px;font-weight:700;cursor:pointer;padding:0;margin-top:6px', onclick: function () { pad.clear(); } }, ['Clear signature']),
      el('label', { style: 'display:flex;gap:10px;align-items:flex-start;font-size:13.5px;color:#334155;margin-top:12px;line-height:1.45' }, [agree, el('span', {}, ['I countersign this proposal on behalf of KiddieTrac and I am authorised to do so.'])]),
      msg,
    ]);
    KT.Shell.Modal.open({
      title: '✍️ Countersign ' + (q.number || 'proposal'),
      body: body,
      actions: [
        { label: 'Cancel' },
        { label: '✍️ Countersign & send', primary: true, busyLabel: 'Countersigning…', handler: async function () {
          var miss = [];
          if (nameIn.value.trim().length < 2) miss.push('your name');
          if (pad.isEmpty()) miss.push('your signature');
          if (!agree.checked) miss.push('the checkbox');
          if (miss.length) { msg.style.color = '#B91C1C'; msg.textContent = 'Please add: ' + miss.join(', ') + '.'; return false; }
          msg.style.color = '#475569'; msg.textContent = 'Countersigning and sending the signed copy…';
          try {
            var r = await Api.post('/sales/quotes/' + q.id + '/countersign', { name: nameIn.value.trim(), title: titleIn.value.trim(), signature: pad.toDataURL(), agree: true });
            toast('✅', 'Proposal complete', 'Signed by both parties. Signed copy ' + (r.email || 'sent') + '.');
            renderDetail(container, ctx);
          } catch (e) { msg.style.color = '#B91C1C'; msg.textContent = e.message || 'Could not countersign.'; return false; }
        } },
      ],
    });
  }

  /* WHAT CHANGED + WHERE IT STANDS (2026-11-10, Anthony: "when editing and saving an existing proposal under the
     proposals subtab add the history on what changed and the current status of the edited proposal"). The card says
     when it last changed and whether the customer has that version; 🕘 History lists every change and event. */
  function quoteStateLine(q) {
    var link = el('button', { type: 'button', 'data-kt-inpage': '1', style: 'border:0;background:none;padding:0;font:inherit;font-size:12px;font-weight:700;color:' + ACCENT + ';cursor:pointer',
      onclick: function (ev) { ev.stopPropagation(); openQuoteHistory(q); } }, ['🕘 History']);
    return el('div', {}, [
      el('div', { style: 'display:flex;gap:10px;align-items:center;flex-wrap:wrap;font-size:12px;color:#64748B;margin-top:6px' }, [
        q.revised_at ? el('span', {}, ['✏️ Last changed ' + fmtStamp(q.revised_at) + (q.revision > 1 ? ' · revision ' + q.revision : '')]) : null, link]),
      q.changed_since_sent ? el('div', { style: 'font-size:12px;margin-top:4px;color:#B45309;font-weight:700' }, ['⚠️ Changed after it was sent: the customer has the earlier version. Email this one when it is ready.']) : null,
    ]);
  }
  var HIST_ICON = { edit: '✏️', email: '📧', followup: '🔁', note: '📝', stage: '🏁', created: '🆕' };
  function openQuoteHistory(q) {
    var body = el('div', { style: 'font-size:13.5px;color:#334155' }, [el('div', { style: 'color:#94A3B8' }, ['Loading…'])]);
    KT.Shell.Modal.open({ title: '🕘 History — ' + (q.number || 'proposal'), body: body, large: true, actions: [{ label: 'Close' }] });
    Api.get('/sales/quotes/' + q.id + '/history').then(function (r) {
      clear(body);
      var s = r.state || {};
      var fact = function (k, v) { return v ? el('div', { style: 'display:flex;gap:10px;padding:3px 0' }, [el('div', { style: 'flex:0 0 150px;color:#64748B' }, [k]), el('div', { style: 'flex:1 1 auto;font-weight:600' }, [v])]) : null; };
      body.appendChild(el('div', { style: 'border:1px solid #E2E8F0;border-radius:12px;padding:12px 14px;margin-bottom:14px;background:#F8FAFC' }, [
        el('div', { style: 'font-weight:800;margin-bottom:6px' }, ['Where it stands']),
        fact('Status', QSTATUS_LABEL[s.status] || s.status),
        fact('Number', (s.number || '') + (s.revision > 1 ? ' (revision ' + s.revision + ')' : '')),
        fact('Last changed', s.revised_at ? fmtStamp(s.revised_at) : ''),
        fact('Sent', s.email_sent_at ? 'Emailed ' + fmtStamp(s.email_sent_at) + (s.email_open_count ? ' · opened ' + s.email_open_count + (s.email_open_count === 1 ? ' time' : ' times') : ' · not opened yet') : (s.sent_at ? 'Marked sent ' + fmtStamp(s.sent_at) : 'Not sent yet')),
        fact('Signing link', s.sign_viewed_at ? 'Opened by the customer ' + fmtStamp(s.sign_viewed_at) : ''),
        fact('Customer signed', s.client_signed_at ? (s.client_signer_name || 'Customer') + ' · ' + fmtStamp(s.client_signed_at) : ''),
        fact('Countersigned', s.countersigned_at ? (s.countersigner_name || 'KiddieTrac') + ' · ' + fmtStamp(s.countersigned_at) : ''),
        fact('Declined', s.declined_at ? fmtStamp(s.declined_at) + (s.decline_reason ? ' · “' + s.decline_reason + '”' : '') : ''),
        fact('Valid until', s.valid_until || ''),
        s.changed_since_sent ? el('div', { style: 'margin-top:6px;color:#B45309;font-weight:700' }, ['⚠️ Changed after it was sent: the customer has the earlier version.']) : null,
      ]));
      body.appendChild(el('div', { style: 'font-weight:800;margin-bottom:6px' }, ['What happened']));
      (r.history || []).forEach(function (h) {
        var lines = String(h.body || '').split('\n');
        body.appendChild(el('div', { style: 'display:flex;gap:10px;padding:9px 0;border-top:1px solid #EEF2F6' }, [
          el('div', { style: 'flex:0 0 22px;font-size:15px;line-height:1.2' }, [HIST_ICON[h.type] || '•']),
          el('div', { style: 'flex:1 1 auto;min-width:0' }, [
            el('div', { style: 'font-size:12px;color:#64748B' }, [fmtStamp(h.at) + (h.by ? ' · ' + h.by : '')]),
            el('div', { style: 'font-weight:700;margin-top:1px;overflow-wrap:anywhere' }, [lines[0]]),
            lines.length > 1 ? el('ul', { style: 'margin:4px 0 0 18px;padding:0;color:#475569;overflow-wrap:anywhere' }, lines.slice(1).filter(function (x) { return x.trim(); }).map(function (x) { return el('li', { style: 'margin:2px 0' }, [x]); })) : null,
          ]),
        ]));
      });
    }).catch(function (e) { clear(body); body.appendChild(el('div', { style: 'color:#B91C1C' }, [e.message || 'Could not load the history.'])); });
  }

  function renderQuotesPanel(id, lead, container, ctx) {
    var panel = card([el('div', { style: 'display:flex;justify-content:space-between;align-items:center;margin-bottom:12px' }, [
      el('div', { style: 'font-weight:800' }, ['Proposals']),
      btn('+ New', 'light', function () { openQuoteBuilder(id, null, container, ctx); }),
    ])]);
    var list = el('div', { style: 'display:flex;flex-direction:column;gap:8px' });
    (lead.quotes || []).forEach(function (q) {
      list.appendChild(el('div', { style: 'border:1px solid #e6ebf1;border-radius:10px;padding:10px 12px;cursor:pointer', onclick: function () { viewQuote(id, q, container, ctx); } }, [
        el('div', { style: 'display:flex;justify-content:space-between;align-items:center;gap:8px' }, [
          el('div', { style: 'font-weight:700;font-size:13px' }, [q.number || 'Proposal', ' ', el('span', { style: 'font-size:11px;font-weight:700;color:#fff;background:' + (QSTATUS_COLOR[q.status] || '#94A3B8') + ';border-radius:999px;padding:1px 8px;margin-left:4px;white-space:nowrap' }, [QSTATUS_LABEL[q.status] || q.status])]),
          el('div', { style: 'margin-left:auto;font-weight:800;color:#047857;white-space:nowrap;text-align:right' }, [moneyIn(q.split && q.split.has_once ? q.split.recurring_total : q.total, q.currency), el('span', { style: 'font-size:11px;font-weight:700;color:#64748B' }, [' ' + (q.currency || 'CAD') + periodShort(q.billing_period)]),
            q.split && q.split.has_once ? el('div', { style: 'font-size:11px;font-weight:800;color:#B45309' }, ['+ ' + moneyIn(q.split.one_time_total, q.currency) + ' one-time']) : null]),
          quoteMenuButton(id, q, container, ctx),
        ]),
        q.title ? el('div', { style: 'font-size:12px;color:#64748B;margin-top:2px' }, [q.title]) : null,
        signLine(id, q, container, ctx),
        quoteStateLine(q),
      ]));
    });
    if (!(lead.quotes || []).length) list.appendChild(el('div', { style: 'color:#94A3B8;font-size:13px' }, ['No proposals yet.']));
    panel.appendChild(list);
    return panel;
  }

  /* PROPOSAL ACTIONS (2026-10-16, Anthony: "i need the ability to view the proposal - make a
     kebab to view, download, delete, email and edit"). The proposals tab is a CARD LIST, not a
     table, so kt-row-actions has nothing to collapse — this is the one hand-rolled ⋮ on the
     lead screen (CONVENTIONS.md: allowed where no table exists). The menu lives on <body>. */
  function quoteMenuButton(leadId, q, container, ctx) {
    return el('button', { type: 'button', title: 'Proposal actions', 'aria-label': 'Proposal actions', 'aria-haspopup': 'menu', 'data-kt-inpage': '1', 'data-kt-iconized': '1',
      style: 'flex:0 0 auto;width:30px;height:30px;min-height:30px;border:1px solid #E2E8F0;background:#fff;border-radius:8px;cursor:pointer;font-size:18px;line-height:1;color:#334155;display:inline-flex;align-items:center;justify-content:center;padding:0',
      onclick: function (ev) { ev.preventDefault(); ev.stopPropagation(); openQuoteMenu(ev.currentTarget, leadId, q, container, ctx); } }, ['⋮']);
  }
  function closeQuoteMenu() { var m = document.getElementById('kt-quote-menu'); if (m) m.remove(); document.removeEventListener('mousedown', quoteMenuOutside, true); }
  function quoteMenuOutside(ev) { var m = document.getElementById('kt-quote-menu'); if (m && !m.contains(ev.target)) closeQuoteMenu(); }
  function openQuoteMenu(anchor, leadId, q, container, ctx) {
    closeQuoteMenu();
    var item = function (label, fn, danger) {
      return el('button', { type: 'button', role: 'menuitem', style: 'display:flex;align-items:center;gap:8px;width:100%;text-align:left;border:0;background:none;padding:9px 14px;font:inherit;font-size:14px;cursor:pointer;color:' + (danger ? '#B91C1C' : '#0D1B2A'),
        onmouseenter: function (e) { e.currentTarget.style.background = danger ? '#FEF2F2' : '#F1F5F9'; }, onmouseleave: function (e) { e.currentTarget.style.background = 'none'; },
        onclick: function (e) { e.stopPropagation(); closeQuoteMenu(); fn(); } }, [label]);
    };
    var m = el('div', { id: 'kt-quote-menu', role: 'menu', style: 'position:fixed;z-index:10001;min-width:190px;background:#fff;border:1px solid #E2E8F0;border-radius:12px;box-shadow:0 12px 32px rgba(15,23,42,.18);padding:6px 0;overflow:hidden' }, [
      q.status === 'signed' ? item('✍️ Countersign', function () { countersignQuote(q, container, ctx); }) : null,
      item('👁 View', function () { viewQuote(leadId, q, container, ctx); }),
      item('🕘 History', function () { openQuoteHistory(q); }),
      // What the customer signed is fixed: no editing or re-sending once they have signed.
      q.client_signed_at ? null : item('✏️ Edit', function () { openQuoteBuilder(leadId, q, container, ctx); }),
      item(q.countersigned_at ? '⬇ Download signed PDF' : '⬇ Download PDF', function () { downloadQuotePdf(q.id, q.number); }),
      item(q.countersigned_at ? '🖨 Print signed copy' : '🖨 Print', function () { printQuotePdf(q.id, q.number); }),
      q.client_signed_at || q.status === 'declined' ? null : (q.status === 'draft'
        ? item('📧 Email to prospect', function () { emailQuote(q, container, ctx); })
        // Resend (2026-10-23): the same email again, with the last note, CC and BCC filled in.
        : item('↻ Resend proposal', function () { emailQuote(q, container, ctx, true); })),
      q.client_signed_at || q.status === 'declined' ? null : item('🔗 Copy signing link', function () { copySignLink(q); }),
      q.client_signed_at || q.status === 'declined' || q.status === 'draft' ? null : item('⏰ Automatic follow-up…', function () { quoteFollowup(q, container, ctx); }),
      // Approval + conversion (2026-10-23).
      el('div', { style: 'height:1px;background:#EEF2F6;margin:4px 0' }),
      q.status === 'draft' || q.converted_agency_id ? null : item(q.contract_name ? '📎 Replace signed contract' : '📎 Attach signed contract', function () { attachContract(q, container, ctx); }),
      q.contract_name ? item('📄 Open contract', function () { viewContract(q); }) : null,
      q.contract_name && !q.converted_agency_id ? item('🗑 Remove contract', function () { removeContract(q, container, ctx); }, true) : null,
      isSuperAdmin() && q.status !== 'draft' && q.status !== 'declined' && !q.converted_agency_id ? item('✅ Approve & set up customer', function () { approveQuote(q, container, ctx); }) : null,
      isSuperAdmin() && q.status !== 'declined' && !q.converted_agency_id ? item('✖ Don\u2019t approve', function () { rejectQuote(q, container, ctx); }, true) : null,
      isSuperAdmin() && q.converted_agency_id && !q.invite_sent_at ? item('📨 Send welcome invite', function () { sendWelcomeInvite(q, container, ctx); }) : null,
      el('div', { style: 'height:1px;background:#EEF2F6;margin:4px 0' }),
      item('🗑 Delete', function () { deleteQuote(q, container, ctx); }, true),
    ]);
    document.body.appendChild(m);
    var r = anchor.getBoundingClientRect(), mw = m.offsetWidth, mh = m.offsetHeight;
    var left = Math.max(8, Math.min(r.right - mw, window.innerWidth - mw - 8));
    var top = r.bottom + 4; if (top + mh > window.innerHeight - 8) top = Math.max(8, r.top - mh - 4);
    m.style.left = left + 'px'; m.style.top = top + 'px';
    setTimeout(function () { document.addEventListener('mousedown', quoteMenuOutside, true); }, 0);
    window.addEventListener('scroll', closeQuoteMenu, { once: true, capture: true });
  }
  async function emailQuote(q, container, ctx, resend) {
    var to = (CURRENT_LEAD && CURRENT_LEAD.email) || '';
    if (!to) { toast('⚠️', 'No email address', 'Add an email to this lead first.', '#DC2626'); return; }
    // CC / BCC (2026-10-16): optional, several addresses separated by commas.
    // What the last email carried comes back filled in (2026-10-23), so a resend keeps it.
    var cc = (q.email_cc || []).join(', '), bcc = (q.email_bcc || []).join(', '), note = q.email_note || '';
    var fuDays = String(q.followup_days || 0), fuMax = String(q.followup_max || 2);
    function split(v) { return String(v || '').split(/[\s,;]+/).map(function (x) { return x.trim(); }).filter(Boolean); }
    for (;;) {
      /* A NOTE AND AUTOMATIC FOLLOW-UP (2026-10-23, Anthony): your words go in the email under
         the greeting; if it is not signed, KiddieTrac follows up for you (ProposalFollowUp). */
      var r = await window.KT.prompt({ title: (resend ? 'Resend ' : 'Email ') + (q.number || 'this proposal'), okLabel: resend ? 'Resend email' : 'Send email',
        description: 'The PDF goes to ' + to + ', with a private “Review & sign online” button.' + (q.status === 'draft' ? ' The proposal will be marked Sent.' : '')
          + (resend ? ' Your last note, CC and BCC are filled in — change anything before sending.' : ''),
        fields: [
          { key: 'note', label: 'Your note in the email (optional)', value: note, type: 'textarea', rows: 5, placeholder: 'A personal word before the proposal: what you discussed, what is included, next steps…' },
          { key: 'fu', label: 'If it is not signed, follow up automatically', value: fuDays, type: 'select', options: [{ value: '0', label: 'No automatic follow-up' }, { value: '2', label: 'After 2 days' }, { value: '3', label: 'After 3 days' }, { value: '5', label: 'After 5 days' }, { value: '7', label: 'After a week' }, { value: '10', label: 'After 10 days' }, { value: '14', label: 'After 2 weeks' }] },
          { key: 'fumax', label: 'Up to', value: fuMax, type: 'select', options: [{ value: '1', label: 'Once' }, { value: '2', label: 'Twice (same gap again)' }, { value: '3', label: '3 times' }] },
          { key: 'cc', label: 'CC (optional)', value: cc, placeholder: 'name@example.com, other@example.com' },
          { key: 'bcc', label: 'BCC (optional)', value: bcc, placeholder: 'name@example.com' }] });
      if (!r) return;
      cc = r.cc || ''; bcc = r.bcc || ''; note = r.note || ''; fuDays = r.fu || '0'; fuMax = r.fumax || '2';
      var bad = split(cc).concat(split(bcc)).filter(function (x) { return !/^[^@\s]+@[^@\s]+\.[^@\s]+$/.test(x); });
      if (!bad.length) break;
      toast('⚠️', 'Check the addresses', 'Not an email address: ' + bad.join(', '), '#DC2626');
    }
    var copies = split(cc).concat(split(bcc));
    try {
      await Api.post('/sales/quotes/' + q.id + '/send', { cc: split(cc), bcc: split(bcc), note: note.trim() || null, followup_days: +fuDays || 0, followup_max: +fuMax || 2 });
      if (resend || q.status !== 'draft') { q.email_note = note.trim(); q.email_cc = split(cc); q.email_bcc = split(bcc); }
      toast('📧', resend ? 'Proposal resent' : 'Proposal emailed', 'Sent to ' + to + (copies.length ? ' and ' + copies.length + ' cop' + (copies.length === 1 ? 'y' : 'ies') : '') + '.' + (+fuDays ? ' Follow-up in ' + fuDays + ' days if not signed.' : ''));
      if (container) renderDetail(container, ctx);
    }
    catch (e) { toast('⚠️', 'Email failed', e.message || '', '#DC2626'); }
  }
  async function quoteFollowup(q, container, ctx) {
    var r = await window.KT.prompt({ title: 'Automatic follow-up · ' + (q.number || 'proposal'), okLabel: 'Save',
      description: 'If the proposal is not signed, KiddieTrac emails a short follow-up from you with the sign link and the PDF, on a weekday between 9 and 5 Eastern, and marks the lead as followed up. It stops when it is signed or declined.',
      fields: [
        { key: 'fu', label: 'Follow up', value: String(q.followup_days || 0), type: 'select', options: [{ value: '0', label: 'Off — stop automatic follow-ups' }, { value: '2', label: 'Every 2 days' }, { value: '3', label: 'Every 3 days' }, { value: '5', label: 'Every 5 days' }, { value: '7', label: 'Every week' }, { value: '10', label: 'Every 10 days' }, { value: '14', label: 'Every 2 weeks' }] },
        { key: 'fumax', label: 'Up to', value: String(q.followup_max || 2), type: 'select', options: [{ value: '1', label: 'Once' }, { value: '2', label: 'Twice' }, { value: '3', label: '3 times' }, { value: '4', label: '4 times' }, { value: '5', label: '5 times' }] }] });
    if (!r) return;
    try {
      var x = await Api.patch('/sales/quotes/' + q.id + '/followup', { days: +r.fu || 0, max: +r.fumax || 2 });
      toast('⏰', +r.fu ? 'Follow-up scheduled' : 'Follow-ups stopped', x.followup_next_at ? 'Next: ' + fmtStamp(x.followup_next_at) : '');
      if (container) renderDetail(container, ctx);
    } catch (e) { toast('⚠️', 'Could not save', e.message || '', '#DC2626'); }
  }
  async function deleteQuote(q, container, ctx) {
    if (!(await window.KT.confirm({ title: 'Delete ' + (q.number || 'this proposal') + '?', description: 'It is removed from this lead. The history keeps a note that it was deleted.', okLabel: 'Delete' }))) return;
    try { await Api.delete('/sales/quotes/' + q.id); toast('🗑', 'Proposal deleted'); renderDetail(container, ctx); }
    catch (e) { toast('⚠️', 'Delete failed', e.message || '', '#DC2626'); }
  }
  // View: the proposal exactly as the PDF prints it, in a sandboxed frame (no scripts).
  async function viewQuote(leadId, q, container, ctx) {
    var ov = el('div', { style: 'position:fixed;inset:0;z-index:10000;background:rgba(13,27,42,.55);display:flex;align-items:flex-start;justify-content:center;padding:24px 12px;overflow:auto', onclick: function (ev) { if (ev.target === ov) ov.remove(); } });
    var frame = el('iframe', { title: 'Proposal ' + (q.number || ''), sandbox: '', style: 'display:block;width:100%;height:min(72vh,900px);border:1px solid #E2E8F0;border-radius:10px;background:#fff' });
    var box = el('div', { style: 'background:#fff;border-radius:16px;max-width:860px;width:100%;padding:18px;box-sizing:border-box' }, [
      el('div', { style: 'display:flex;justify-content:space-between;align-items:center;margin-bottom:12px;gap:8px' }, [
        el('div', { style: 'font-size:18px;font-weight:800' }, ['Proposal ' + (q.number || '')]),
        el('button', { type: 'button', 'aria-label': 'Close', style: 'border:0;background:none;font-size:22px;cursor:pointer;color:#64748B', onclick: function () { ov.remove(); } }, ['×']),
      ]),
      frame,
      el('div', { style: 'display:flex;gap:8px;justify-content:flex-end;margin-top:14px;flex-wrap:wrap' }, [
        btn('⬇ Download PDF', 'light', function () { downloadQuotePdf(q.id, q.number); }),
        q.client_signed_at || q.status === 'declined' ? null : btn('📧 Email', 'light', function () { ov.remove(); emailQuote(q, container, ctx); }),
        q.client_signed_at ? null : btn('✏️ Edit', 'primary', function () { ov.remove(); openQuoteBuilder(leadId, q, container, ctx); }),
      ]),
    ]);
    ov.appendChild(box); document.body.appendChild(ov);
    // The preview HTML stacks its own layout on a narrow screen (the PDF keeps the page layout).
    try { var r = await Api.get('/sales/quotes/' + q.id + '/preview'); frame.srcdoc = r.html; }
    catch (e) { frame.srcdoc = '<p style="font-family:sans-serif;color:#B91C1C;padding:20px">Could not load the proposal.</p>'; }
  }

  function openQuoteBuilder(leadId, quote, container, ctx, defaults) {
    // A new proposal starts from the proposal defaults (terms, valid-for days, taxes).
    if (!quote && !defaults) {
      Api.get('/sales/proposal-defaults').then(function (d) { openQuoteBuilder(leadId, quote, container, ctx, d || {}); })
        .catch(function () { openQuoteBuilder(leadId, quote, container, ctx, {}); });
      return;
    }
    defaults = defaults || {};
    var products = (META && META.products) || [];
    var byId = {}; products.forEach(function (p) { byId[String(p.id)] = p; });
    var ov = el('div', { style: 'position:fixed;inset:0;z-index:10000;background:rgba(13,27,42,.55);display:flex;align-items:flex-start;justify-content:center;padding:30px 16px;overflow:auto', onclick: function (ev) { if (ev.target === ov) ov.remove(); } });
    // A line from a discount plan remembers its rule, so a percentage follows the other lines.
    function discRule(productId) { var p = byId[String(productId)]; return p && p.kind === 'discount' ? { type: p.discount_type, value: Number(p.price || 0), applies: p.applies_to || 'all' } : null; }
    /* EDITABLE RATE (2026-11-10, Anthony: "if I select the 10% discount, I can edit this during the proposal edit screen").
       The catalogue discount is the starting rate; the line keeps its own (sales_quote_items.discount_type/value). A line
       saved before this follows the catalogue, as it always did. */
    function lineRule(i) {
      var r = discRule(i.product_id);
      if (!i.discount_type) return r;
      return { type: i.discount_type, value: Number(i.discount_value || 0), applies: r ? r.applies : 'all' };
    }
    var items = quote && quote.items ? quote.items.map(function (i) { return { description: i.description, qty: i.qty, unit_price: i.unit_price, product_id: i.product_id, _disc: lineRule(i) }; }) : [];
    var box = el('div', { class: 'kt-qb', 'data-kt-nofit': '1', 'data-kt-nopack': '1', 'data-kt-nogrid': '1', style: 'background:#fff;border-radius:16px;max-width:820px;width:100%;padding:24px 26px;box-sizing:border-box' });
    if (!document.getElementById('kt-qb-css')) {
      var qbCss = document.createElement('style'); qbCss.id = 'kt-qb-css';
      qbCss.textContent = '.kt-qb input:not([type=checkbox]):not([type=radio]),.kt-qb select{min-height:36px;}'
        + '.kt-qb .kt-qb-party{display:grid;grid-template-columns:repeat(6,minmax(0,1fr));gap:8px 12px;}'
        + '.kt-qb .kt-qb-line{display:grid;grid-template-columns:minmax(0,1fr) 84px 124px 66px;gap:8px;align-items:start;}'
        + '.kt-qb .kt-qb-line input[type=number]{text-align:right;}'
        + '@media (max-width:640px){.kt-qb{padding:18px 16px !important;}.kt-qb .kt-qb-party{grid-template-columns:1fr;}.kt-qb .kt-qb-party > *{grid-column:1 / -1 !important;}'
        + '.kt-qb .kt-qb-line{grid-template-columns:minmax(0,1fr) minmax(0,1.4fr) 66px;gap:6px;padding-bottom:10px;border-bottom:1px solid #EEF2F6;}'
        + '.kt-qb .kt-qb-line > :first-child{grid-column:1 / -1;}'
        + '.kt-qb .kt-qb-line > *{padding-top:0 !important;}'
        + '.kt-qb .kt-qb-linehead{border-bottom:0;padding-bottom:0;}.kt-qb .kt-qb-linehead > :not(:first-child){display:none;}}';
      document.head.appendChild(qbCss);
    }
    var titleIn = input({ value: quote ? (quote.title || '') : 'KiddieTrac Proposal', placeholder: 'Proposal title', style: 'width:100%;padding:9px 11px;border:1px solid var(--kt-border,#d9e1ea);border-radius:9px;font-size:15px;font-weight:600;box-sizing:border-box' });
    titleIn.style.setProperty('max-width', 'none', 'important');
    var lastCur = null; try { lastCur = localStorage.getItem('kt_sales_currency'); } catch (e) {}
    var curNow = (quote && quote.currency) || lastCur || 'CAD';
    var curSel = selectEl(CURRENCIES.map(function (c) { return { v: c[0], l: c[0] + ' — ' + c[1] }; }), curNow);
    var period = (quote && quote.billing_period) || 'month';
    var perSel = selectEl([{ v: 'month', l: 'Monthly' }, { v: 'year', l: 'Annual' }], period);
    var discIn = input({ type: 'number', step: '0.01', value: quote ? (quote.discount || 0) : 0 });
    var validDays = Number(defaults.valid_days || 0);
    var validIn = input({ type: 'date', value: quote ? (quote.valid_until || '') : (validDays ? addDaysISO(todayISO(), validDays) : '') });
    var termsIn = textarea({ value: quote ? (quote.terms || '') : String(defaults.terms || '').replace(/\{days\}/g, String(validDays || 90)), style: 'width:100%;padding:9px 11px;border:1px solid var(--kt-border,#d9e1ea);border-radius:9px;font-size:13.5px;box-sizing:border-box;min-height:120px;resize:vertical' });
    termsIn.style.setProperty('min-height', '120px', 'important');   // a global rule zeroes textarea min-height
    // Terms & Conditions: the full standard text, printed on its own page of the proposal.
    var condIn = textarea({ value: quote ? (quote.conditions || '') : String(defaults.conditions || ''), style: 'width:100%;padding:9px 11px;border:1px solid var(--kt-border,#d9e1ea);border-radius:9px;font-size:12.5px;line-height:1.45;box-sizing:border-box;resize:vertical' });
    condIn.style.setProperty('min-height', '220px', 'important');
    var taxes = ((quote ? quote.taxes : defaults.taxes) || []).map(function (t) { return { label: t.label, rate: t.rate }; });
    var taxHost = el('div', { style: 'display:flex;flex-direction:column;gap:6px' });
    var notesIn = textarea({ value: quote ? (quote.notes || '') : '', placeholder: 'Anything to add for the customer — shown on the proposal under the prices.', style: 'width:100%;padding:9px 11px;border:1px solid var(--kt-border,#d9e1ea);border-radius:9px;font-size:14px;line-height:1.45;box-sizing:border-box;min-height:120px;resize:vertical' });
    var itemsHost = el('div', { style: 'display:flex;flex-direction:column;gap:6px;margin:6px 0' });
    var totalEl = el('div', { style: 'text-align:right;font-weight:800;font-size:18px;color:#047857;margin-top:8px' }, ['$0.00']);
    function unitOf(it) { var p = byId[String(it.product_id)]; return p ? p.unit : null; }
    // A recurring preset's price for this proposal's billing period.
    function priceFor(unit, price, per) { price = Number(price || 0); if (unit === 'month' && per === 'year') return Math.round(price * 12 * 100) / 100; if (unit === 'year' && per === 'month') return Math.round(price / 12 * 100) / 100; return price; }
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
        if (it._pIn && document.activeElement !== it._pIn) it._pIn.value = it.unit_price.toFixed(2);
      });
      var s = 0; items.forEach(function (i) { s += (Number(i.qty || 0) * Number(i.unit_price || 0)); });
      // One-time fees apart from the subscription (2026-10-27): a one-time catalogue item, or a
      // discount line that applies to one-time items. The proposal discount is off the subscription.
      var isOnceLine = function (i) { return i._disc ? i._disc.applies === 'one-time' : unitOf(i) === 'one-time'; };
      var onceSum = 0, hasOnce = false, recBase = 0, discAll = 0;
      items.forEach(function (i) {
        var amt = Number(i.qty || 0) * Number(i.unit_price || 0);
        if (i._disc && i._disc.applies !== 'one-time' && i._disc.applies !== 'recurring') { discAll += amt; return; }   // shared below
        if (isOnceLine(i)) { if (!i._disc) hasOnce = hasOnce || amt !== 0; onceSum += amt; } else { recBase += amt; }
      });
      // A whole-proposal discount is shared between the subscription and the one-time fees.
      if (discAll) { var b = Math.max(0, recBase) + Math.max(0, onceSum), sh = b > 0 ? Math.max(0, recBase) / b : 1; onceSum += Math.round(discAll * (1 - sh) * 100) / 100; }
      if (hasOnce && (period === 'month' || period === 'year')) {
        var cur2 = curSel.value, disc2 = Number(discIn.value || 0);
        var recSub = Math.round((s - onceSum) * 100) / 100, recNet = recSub - disc2, onceNet = onceSum;
        if (recNet < 0) { onceNet += recNet; recNet = 0; }
        var rows2 = [], rt = 0, ot = 0;
        var row2 = function (l, v, strong, col) { return el('div', { style: 'display:flex;justify-content:flex-end;gap:16px;' + (strong ? 'font-weight:800;font-size:18px;color:' + (col || '#047857') + ';margin-top:4px' : 'font-size:13px;color:#475569;font-weight:600') }, [el('span', {}, [l]), el('span', { style: 'min-width:110px;text-align:right' }, [v])]); };
        var live2 = taxes.filter(function (t) { return String(t.label || '').trim(); });
        rows2.push(row2((period === 'year' ? 'Annual' : 'Monthly') + ' subtotal', moneyIn(recSub, cur2)));
        if (disc2) rows2.push(row2('Discount', moneyIn(-disc2, cur2)));
        live2.forEach(function (t) { var a = Math.round(recNet * Number(t.rate || 0)) / 100; rt += a; rows2.push(row2(t.label + ' (' + Number(t.rate || 0) + '%)', moneyIn(a, cur2))); });
        rows2.push(row2('Total ' + cur2 + (period === 'year' ? ' per year' : ' per month'), moneyIn(recNet + rt, cur2), true));
        rows2.push(el('div', { style: 'height:8px' }));
        rows2.push(row2('One-time fees', moneyIn(onceNet, cur2)));
        live2.forEach(function (t) { var a = Math.round(onceNet * Number(t.rate || 0)) / 100; ot += a; rows2.push(row2(t.label + ' (' + Number(t.rate || 0) + '%)', moneyIn(a, cur2))); });
        rows2.push(row2('One-time total ' + cur2 + ' (due on acceptance)', moneyIn(onceNet + ot, cur2), true, '#B45309'));
        clear(totalEl); rows2.forEach(function (r) { totalEl.appendChild(r); });
        return;
      }
      var disc = Number(discIn.value || 0), cur = curSel.value;
      var taxable = Math.max(0, Math.round((s - disc) * 100) / 100), tsum = 0;
      var rows = [];
      var row = function (l, v, strong) { return el('div', { style: 'display:flex;justify-content:flex-end;gap:16px;' + (strong ? 'font-weight:800;font-size:18px;color:#047857;margin-top:4px' : 'font-size:13px;color:#475569;font-weight:600') }, [el('span', {}, [l]), el('span', { style: 'min-width:110px;text-align:right' }, [v])]); };
      var live = taxes.filter(function (t) { return String(t.label || '').trim(); });
      if (disc || live.length) rows.push(row('Subtotal', moneyIn(s, cur)));
      if (disc) rows.push(row('Discount', moneyIn(-disc, cur)));
      live.forEach(function (t) { var a = Math.round(taxable * Number(t.rate || 0)) / 100; tsum += a; rows.push(row(t.label + ' (' + Number(t.rate || 0) + '%)', moneyIn(a, cur))); });
      rows.push(row('Total ' + cur + (period === 'year' ? ' per year' : ' per month'), moneyIn(taxable + tsum, cur), true));
      clear(totalEl); rows.forEach(function (r) { totalEl.appendChild(r); });
    }
    /* MOVE A LINE UP OR DOWN (2026-11-07, Anthony: "ability to move line items from one position up or down"). The
       order here is the order saved (sales_quote_items.sort) and printed on the proposal. */
    function moveBtns(idx) {
      var b = function (dir, label, glyph) {
        var to = idx + dir, off = to < 0 || to >= items.length;
        var btn = el('button', { type: 'button', 'aria-label': label, 'data-kttip': label,
          style: 'border:1px solid #d9e1ea;background:#fff;color:#1F6080;border-radius:6px;width:26px;height:16px;padding:0;font-size:9px;line-height:14px;cursor:' + (off ? 'default;opacity:.35' : 'pointer'),
          onclick: function () { if (off) return; var t = items[idx]; items[idx] = items[to]; items[to] = t; drawItems(); recalc(); } }, [glyph]);
        btn.disabled = off;
        return btn;
      };
      return el('div', { style: 'display:flex;flex-direction:column;gap:2px;flex:0 0 auto' }, [b(-1, 'Move up', '\u25B2'), b(1, 'Move down', '\u25BC')]);
    }
    function drawItems() {
      clear(itemsHost);
      items.forEach(function (it, idx) {
        var isDisc = !!it._disc;
        /* AS LONG AS IT NEEDS TO BE (2026-11-07, Anthony: "the description of part#'s won't allow me to type unlimited").
           It stopped at 255 characters and Enter did nothing. Now no cap here (the server keeps 20,000 as a sanity
           limit) and Enter starts a new line, which the proposal PDF keeps. The box grows with the text. */
        var d = textarea({ value: it.description || '', placeholder: 'Line item', rows: 1, 'aria-label': 'Description', style: 'width:100%;min-width:0;padding:7px 9px;border:1px solid #d9e1ea;border-radius:8px;font-size:14px;line-height:1.35;box-sizing:border-box;resize:none;overflow:hidden;font-family:inherit' + (isDisc ? ';color:#047857;font-weight:700' : '') });
        d.style.setProperty('min-height', '0', 'important');
        var grow = function () { d.style.setProperty('height', 'auto', 'important'); d.style.setProperty('height', (d.scrollHeight + 2) + 'px', 'important'); };   // a global rule fixes textarea height
        d.addEventListener('input', grow); requestAnimationFrame(grow); setTimeout(grow, 60);
        // Re-fit when the width changes (rotating a phone, resizing the window): only on a width change, so it cannot loop.
        if (window.ResizeObserver) { var _w = 0; new ResizeObserver(function (en) { var w = Math.round(en[0].contentRect.width); if (w && w !== _w) { _w = w; grow(); } }).observe(d); }
        var qv = it.qty == null ? 1 : it.qty; if (qv !== '' && Number(qv) % 1 === 0) qv = String(Number(qv));   // 1, not 1.00
        var q = input({ type: 'number', step: '0.01', value: qv, 'aria-label': 'Quantity', style: 'width:100%;padding:7px 8px;border:1px solid #d9e1ea;border-radius:8px;font-size:14px;box-sizing:border-box' });
        var p = input({ type: 'number', step: '0.01', value: it.unit_price == null ? 0 : it.unit_price, 'aria-label': 'Price', style: 'width:100%;padding:7px 8px;border:1px solid #d9e1ea;border-radius:8px;font-size:14px;box-sizing:border-box' + (isDisc ? ';color:#047857;background:#F0FDF4' : '') });
        if (isDisc) { q.disabled = true; it._pIn = p; }
        d.addEventListener('input', function () { it.description = d.value; }); q.addEventListener('input', function () { it.qty = q.value; recalc(); });
        if (!isDisc) p.addEventListener('input', function () { it.unit_price = p.value; recalc(); });
        // The line's part number (2026-10-24), from the catalogue item it came from.
        var pn = it.product_id && byId[String(it.product_id)] ? byId[String(it.product_id)].part_number : (it.part_number || '');
        var pnTag = pn ? el('span', { title: 'Part number', style: 'flex:0 0 auto;align-self:center;font-size:10.5px;font-weight:800;color:#1F6080;background:#EAF3F6;border-radius:6px;padding:3px 6px;font-family:ui-monospace,Menlo,Consolas,monospace;white-space:nowrap' }, [pn]) : null;
        var onceTag = (!isDisc && unitOf(it) === 'one-time') ? el('span', { title: 'Charged once, not monthly', style: 'flex:0 0 auto;align-self:center;font-size:10px;font-weight:800;color:#B45309;background:#FEF3C7;border-radius:6px;padding:3px 6px;white-space:nowrap' }, ['one-time']) : null;
        var tags = (pnTag || onceTag) ? el('div', { style: 'display:flex;gap:6px;margin-bottom:4px' }, [pnTag, onceTag]) : null;
        // A discount line's rate, editable here: the amount works itself out, and the "(10% off)" in the text follows.
        var rateRow = null;
        if (isDisc) {
          var cat = byId[String(it.product_id)] || {};
          var labelFor = function (r) { return discountLabel({ discount_type: r.type, price: r.value, applies_to: r.applies }); };
          var rv = Number(it._disc.value || 0);
          var rIn = input({ type: 'number', step: '0.01', min: '0', value: rv % 1 ? rv.toFixed(2) : String(rv), 'aria-label': 'Discount rate' });
          rIn.style.cssText += ';width:96px;padding:6px 8px;font-size:13.5px;text-align:right';
          var tSel = selectEl([{ v: 'percent', l: '% off' }, { v: 'amount', l: '$ off' }], it._disc.type || 'percent');
          tSel.style.cssText += ';width:auto;padding:6px 8px;font-size:13.5px';
          /* EDIT A DISCOUNT LINE ANY WAY (2026-11-11, Anthony: "I need to be able to edit that discount line item to change
             the value and edit the description"). The rate (% or $ off) is labelled, the words are free text, and the
             amount box takes a dollar figure too: typing one makes it a fixed "$ off" for this proposal. The "(10% off)"
             in the words follows the rate only while the words still say the old one. */
          var setRule = function (type, v, fromAmount) {
            var before = labelFor(it._disc);
            v = Math.max(0, Number(v || 0));
            if (type === 'percent' && v > 100) { v = 100; rIn.value = '100'; }
            it._disc = { type: type, value: Math.round(v * 100) / 100, applies: it._disc.applies };
            if (fromAmount) { rIn.value = String(it._disc.value); tSel.value = 'amount'; }
            var after = labelFor(it._disc);
            if (before !== after && (it.description || '').indexOf('(' + before + ')') !== -1) {
              it.description = it.description.replace('(' + before + ')', '(' + after + ')');
              d.value = it.description; grow();
            }
            recalc();
          };
          var applyRate = function () { setRule(tSel.value, rIn.value, false); };
          rIn.addEventListener('input', applyRate); tSel.addEventListener('change', applyRate);
          p.addEventListener('input', function () { if (String(p.value).trim() !== '' && String(p.value).trim() !== '-') setRule('amount', Math.abs(Number(p.value)), true); });
          // Off the box: show what it really takes off (never more than the lines it applies to).
          p.addEventListener('blur', function () { p.value = Number(it.unit_price || 0).toFixed(2); });
          var catNote = cat.id && (cat.discount_type !== it._disc.type || Number(cat.price || 0) !== Number(it._disc.value)) ? 'Catalogue: ' + discountLabel(cat) : '';
          rateRow = el('div', { style: 'display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin-top:6px;font-size:12.5px;color:#047857;font-weight:700' },
            [el('span', { style: 'white-space:nowrap' }, ['🏷️ Discount rate']), rIn, tSel,
              el('span', { style: 'color:#64748B;font-weight:600' }, ['or type the amount off in the price box']),
              catNote ? el('span', { style: 'color:#94A3B8;font-weight:600' }, [catNote]) : null]);
          p.title = 'The amount this discount takes off: type a figure for a fixed $ off, or set the rate below';
          p.setAttribute('aria-label', 'Discount amount');
        }
        itemsHost.appendChild(el('div', { class: 'kt-qb-line' }, [el('div', { style: 'min-width:0' }, [tags, d, rateRow]), el('div', { style: tags ? 'padding-top:24px' : '' }, [q]), el('div', { style: tags ? 'padding-top:24px' : '' }, [p]),
          el('div', { style: 'display:flex;gap:4px;align-items:flex-start;' + (tags ? 'padding-top:24px' : '') }, [moveBtns(idx), el('button', { type: 'button', 'aria-label': 'Remove line', title: 'Remove line', style: 'border:0;background:#FEE2E2;color:#B91C1C;border-radius:8px;width:34px;height:34px;cursor:pointer', onclick: function () { items.splice(idx, 1); drawItems(); recalc(); } }, ['×'])])]));
      });
    }
    drawItems(); recalc(); discIn.addEventListener('input', recalc);
    var planOpts = products.filter(function (p) { return p.kind !== 'discount'; }).map(function (p) { return { v: p.id, l: (p.part_number ? p.part_number + ' · ' : '') + p.name + ' — ' + money(p.price) + '/' + p.unit }; });
    var discOpts = products.filter(function (p) { return p.kind === 'discount'; }).map(function (p) { return { v: p.id, l: '🏷️ ' + (p.part_number ? p.part_number + ' · ' : '') + p.name + ' — ' + discountLabel(p) }; });
    var planSel = selectEl([{ v: '', l: '+ Add preset plan or discount…' }].concat(planOpts, discOpts), '');
    planSel.addEventListener('change', function () {
      var p = byId[String(planSel.value)];
      if (p) {
        if (p.kind === 'discount') {
          items.push({ description: p.name + ' (' + discountLabel(p) + ')' + (p.description ? ' — ' + p.description : ''), qty: 1, unit_price: 0, product_id: p.id, _disc: discRule(p.id) });
        } else {
          items.push({ description: p.name + (p.description ? ' — ' + p.description : ''), qty: 1, unit_price: priceFor(p.unit, p.price, period), product_id: p.id });
        }
        drawItems(); recalc(); planSel.value = '';
      }
    });
    box.appendChild(el('div', { style: 'display:flex;justify-content:space-between;align-items:center;margin-bottom:12px' }, [el('div', { style: 'font-size:18px;font-weight:800' }, [quote ? ('Proposal ' + (quote.number || '')) : 'New proposal']), el('button', { style: 'border:0;background:none;font-size:22px;cursor:pointer;color:#64748B', onclick: function () { ov.remove(); } }, ['×'])]));
    box.appendChild(field('Title', titleIn));
    // Prepared for / by (2026-10-16): editable per proposal. "By" starts with the SALES email and
    // number from the proposal defaults, never the rep's own login email.
    var lead0 = CURRENT_LEAD && String(CURRENT_LEAD.id) === String(leadId) ? CURRENT_LEAD : {};
    /* JOB TITLE AND PHONE (2026-10-27, Anthony: "for leads and generating proposal its not pulling
       the job title and ph# into the new proposal"). Lead #53 came in from the website with the
       contact's role and phone in the OWNER fields (owner = same person: same name and email), and
       the proposal only read the contact fields. When the contact's are empty and the owner is the
       contact (or no separate owner is named), the owner's title / phone are used. A proposal
       already saved with them blank picks them up too; nothing typed is replaced. */
    var _same = function (a, b) { return String(a || '').trim().toLowerCase() === String(b || '').trim().toLowerCase(); };
    var ownerIsContact = !lead0.owner_name || _same(lead0.owner_name, lead0.name) || (lead0.owner_email && _same(lead0.owner_email, lead0.email));
    var leadTitle = lead0.title || (ownerIsContact ? lead0.owner_title : '') || '';
    var leadPhone = lead0.phone || (ownerIsContact ? lead0.owner_phone : '') || '';
    var pfSrc = (quote && quote.prepared_for) ? Object.assign({}, quote.prepared_for) : {
      company: lead0.company || '', name: lead0.name || '', title: leadTitle,
      address: lead0.address || '', city: lead0.city || '', province: lead0.province || '', postal_code: lead0.postal_code || '',
      email: lead0.email || '', phone: leadPhone,
    };
    /* An address saved as one line ("35 Dunkerron Ave, Wasaga Beach, Ontario L9Z 0A1"): the lead's own
       parts when it starts with the lead's street, otherwise split at the commas. */
    if (pfSrc.address && !pfSrc.city && !pfSrc.province && !pfSrc.postal_code) {
      var _a = String(pfSrc.address);
      if (lead0.address && _a.toLowerCase().indexOf(String(lead0.address).trim().toLowerCase()) === 0 && (lead0.city || lead0.province || lead0.postal_code)) {
        pfSrc.address = lead0.address; pfSrc.city = lead0.city || ''; pfSrc.province = lead0.province || ''; pfSrc.postal_code = lead0.postal_code || '';
      } else {
        var _parts = _a.split(',').map(function (x) { return x.trim(); }).filter(Boolean);
        if (_parts.length > 1) {
          var _last = _parts[_parts.length - 1], _pc = _last.match(/([A-Za-z]\d[A-Za-z]\s?\d[A-Za-z]\d|\d{5}(?:-\d{4})?)\s*$/);
          if (_pc) { pfSrc.postal_code = _pc[1].toUpperCase(); _last = _last.slice(0, _pc.index).trim(); if (_last) _parts[_parts.length - 1] = _last; else _parts.pop(); }
          pfSrc.address = /\d/.test(_parts[0]) ? _parts.shift() : '';
          if (_parts.length > 1) pfSrc.province = _parts.pop();
          pfSrc.city = _parts.join(', ');
        }
      }
    }
    if (!pfSrc.title && leadTitle) { pfSrc.title = leadTitle; }
    if (!pfSrc.phone && leadPhone) { pfSrc.phone = leadPhone; }
    var pbSrc = (quote && quote.prepared_by) || defaults.prepared_by || {};
    function partyBox(heading, src, spec) {
      var ins = {};
      // sp = [key, label, columns of 6]
      var grid = el('div', { class: 'kt-qb-party' });
      spec.forEach(function (sp) {
        ins[sp[0]] = input({ value: src[sp[0]] || '', type: sp[0] === 'email' ? 'email' : (sp[0] === 'phone' ? 'tel' : 'text'), 'aria-label': heading + ' ' + sp[1], style: 'width:100%;padding:7px 10px;border:1px solid var(--kt-border,#d9e1ea);border-radius:8px;font-size:14px;box-sizing:border-box;background:#fff' });
        grid.appendChild(el('label', { style: 'display:block;min-width:0;position:relative;grid-column:span ' + (sp[2] || 3) }, [el('div', { style: 'font-size:11.5px;font-weight:600;color:#64748B;margin-bottom:3px' }, [sp[1]]), ins[sp[0]]]));
      });
      return { ins: ins, node: el('div', { style: 'background:#F4F7FA;border:1px solid #E6EDF3;border-radius:12px;padding:12px 14px 14px;min-width:0' }, [el('div', { style: 'font-size:11px;letter-spacing:.06em;text-transform:uppercase;font-weight:800;color:#5a7080;margin-bottom:8px' }, [heading]), grid]) };
    }
    var pfBox = partyBox('Prepared for', pfSrc, [['company', 'Organisation', 6], ['name', 'Contact name', 3], ['title', 'Job title', 3], ['email', 'Email', 4], ['phone', 'Phone', 2],
      ['address', 'Street address', 6], ['city', 'City', 2], ['province', 'Province / state', 2], ['postal_code', 'Postal / ZIP code', 2]]);
    var pbBox = partyBox('Prepared by', pbSrc, [['name', 'Name', 3], ['title', 'Title / team', 3], ['email', 'Email', 4], ['phone', 'Phone', 2]]);
    try { attachAddressAutocomplete(pfBox.ins.address, { city: pfBox.ins.city, province: pfBox.ins.province, postal_code: pfBox.ins.postal_code }); } catch (e) {}
    function partyVal(b) { var o = {}; Object.keys(b.ins).forEach(function (k) { o[k] = b.ins[k].value.trim(); }); return o; }
    box.appendChild(el('div', { style: 'display:grid;grid-template-columns:1fr;gap:10px;margin-top:12px' }, [pfBox.node, pbBox.node]));
    curSel.addEventListener('change', function () { try { localStorage.setItem('kt_sales_currency', curSel.value); } catch (e) {} recalc(); });
    perSel.addEventListener('change', function () {
      var from = period, to = perSel.value; if (from === to) return;
      items.forEach(function (it) {
        if (it._disc) return;
        var u = unitOf(it); if (u !== 'month' && u !== 'year') return;
        var n = Number(it.unit_price || 0);
        it.unit_price = Math.round((to === 'year' ? n * 12 : n / 12) * 100) / 100;
      });
      period = to; drawItems(); recalc();
    });
    box.appendChild(el('div', { style: 'display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:14px;margin-top:12px' }, [field('Currency', curSel), field('Billing', perSel)]));
    box.appendChild(el('div', { style: 'font-size:12px;color:#64748B;margin-top:4px' }, ['Prices are in the currency you choose (nothing is converted). On an annual proposal, monthly plans are priced ×12.']));
    box.appendChild(el('div', { class: 'kt-qb-line kt-qb-linehead', style: 'margin:16px 0 6px;align-items:end' }, [el('div', { style: 'font-size:12px;font-weight:700;color:#5a7080' }, ['Line items']), el('div', { style: 'font-size:11px;font-weight:700;color:#64748B;text-align:right;padding-right:4px' }, ['Qty']), el('div', { style: 'font-size:11px;font-weight:700;color:#64748B;text-align:right;padding-right:4px' }, ['Price']), el('div')]));
    box.appendChild(itemsHost);
    box.appendChild(el('div', { style: 'display:flex;gap:8px;margin-bottom:14px' }, [el('div', { style: 'flex:1;min-width:0' }, [planSel]), btn('+ Blank line', 'light', function () { items.push({ description: '', qty: 1, unit_price: 0 }); drawItems(); })]));
    box.appendChild(el('div', { style: 'display:grid;grid-template-columns:1fr 1fr;gap:14px' }, [field('Extra discount (amount)', discIn), field('Valid until', validIn)]));
    function drawTaxes() {
      clear(taxHost);
      taxes.forEach(function (t, idx) {
        var l = input({ value: t.label || '', placeholder: 'Tax name (e.g. HST)', style: 'flex:1;min-width:0;padding:7px 9px;border:1px solid #d9e1ea;border-radius:8px;font-size:14px;box-sizing:border-box' });
        var r = input({ type: 'number', step: '0.001', min: '0', max: '100', value: t.rate == null ? '' : t.rate, placeholder: '%', style: 'width:90px;flex:0 0 90px;padding:7px;border:1px solid #d9e1ea;border-radius:8px;font-size:14px;box-sizing:border-box' });
        l.addEventListener('input', function () { t.label = l.value; recalc(); }); r.addEventListener('input', function () { t.rate = r.value; recalc(); });
        taxHost.appendChild(el('div', { style: 'display:flex;gap:6px;align-items:center' }, [l, r, el('span', { style: 'font-size:13px;color:#64748B' }, ['%']), el('button', { type: 'button', 'aria-label': 'Remove tax', style: 'border:0;background:#FEE2E2;color:#B91C1C;border-radius:8px;padding:6px 9px;cursor:pointer;flex:0 0 auto', onclick: function () { taxes.splice(idx, 1); drawTaxes(); recalc(); } }, ['×'])]));
      });
      if (!taxes.length) taxHost.appendChild(el('div', { style: 'font-size:12.5px;color:#94A3B8' }, ['No taxes on this proposal.']));
    }
    drawTaxes();
    box.appendChild(el('div', { style: 'margin-top:14px' }, [
      el('div', { style: 'display:flex;justify-content:space-between;align-items:center;margin-bottom:6px' }, [el('div', { style: 'font-size:12px;font-weight:700;color:#5a7080' }, ['Taxes (on the total after discount)']), btn('+ Add tax', 'light', function () { if (taxes.length >= 5) return; taxes.push({ label: '', rate: '' }); drawTaxes(); recalc(); })]),
      taxHost,
    ]));
    box.appendChild(el('div', { style: 'margin-top:14px' }, [field('Notes', notesIn)]));
    box.appendChild(el('div', { style: 'margin-top:14px' }, [field('Proposal terms', termsIn)]));
    var condWrap = el('div', { style: 'margin-top:8px;display:none' }, [field('Terms & conditions (printed on their own page)', condIn), el('div', { style: 'font-size:12px;color:#64748B;margin-top:4px' }, ['Start a line with ## for a heading, or - for a bullet. Leave empty to leave them out.'])]);
    var condToggle = el('button', { type: 'button', 'data-kt-inpage': '1', style: 'margin-top:10px;border:0;background:none;color:' + ACCENT + ';font:inherit;font-size:13px;font-weight:700;cursor:pointer;padding:0' }, ['▸ Terms & conditions (' + (condIn.value.trim() ? 'included' : 'none') + ')']);
    condToggle.addEventListener('click', function () { var open = condWrap.style.display === 'none'; condWrap.style.display = open ? '' : 'none'; condToggle.textContent = (open ? '▾' : '▸') + ' Terms & conditions (' + (condIn.value.trim() ? 'included' : 'none') + ')'; });
    box.appendChild(condToggle); box.appendChild(condWrap);
    box.appendChild(totalEl);
    recalc();
    var statusSel = quote ? selectEl([{ v: 'draft', l: 'Draft' }, { v: 'sent', l: 'Sent' }, { v: 'accepted', l: 'Accepted' }, { v: 'declined', l: 'Declined' }], quote.status, { style: 'width:auto;padding:7px 10px;border:1px solid #d9e1ea;border-radius:8px;font-size:13px' }) : null;
    box.appendChild(el('div', { style: 'display:flex;gap:8px;justify-content:flex-end;margin-top:16px;align-items:center;flex-wrap:wrap' }, [
      quote ? el('div', { style: 'margin-right:auto;display:flex;align-items:center;gap:6px' }, [el('span', { style: 'font-size:12px;color:#64748B' }, ['Status']), statusSel]) : null,
      quote ? btn('👁 View', 'light', function () { ov.remove(); viewQuote(leadId, quote, container, ctx); }) : null,
      quote ? btn('⬇ PDF', 'light', function () { downloadQuotePdf(quote.id, quote.number); }) : null,
      quote && leadId ? btn('📧 Email', 'light', function () { emailQuote(quote, null, null); }) : null,
      btn('Cancel', 'ghost', function () { ov.remove(); }),
      btn(quote ? 'Save proposal' : 'Create proposal', 'primary', async function (ev) {
        ev.target.disabled = true;
        recalc();
        var lines = items.filter(function (i) { return (i.description || '').trim(); }).map(function (i) {
          var ln = { description: i.description, qty: i.qty, unit_price: i.unit_price, product_id: i.product_id || null };
          if (i._disc) { ln.discount_type = i._disc.type; ln.discount_value = Number(i._disc.value || 0); }   // its own rate
          return ln;
        });
        var body = { title: titleIn.value, currency: curSel.value, billing_period: perSel.value, terms: termsIn.value, conditions: condIn.value,
          taxes: taxes.filter(function (t) { return String(t.label || '').trim(); }).map(function (t) { return { label: String(t.label).trim(), rate: Number(t.rate || 0) }; }),
          discount: discIn.value || 0, valid_until: validIn.value || null, notes: notesIn.value, items: lines,
          prepared_for: partyVal(pfBox), prepared_by: partyVal(pbBox) };
        if (quote) body.status = statusSel.value;
        try {
          var saved = quote ? await Api.patch('/sales/quotes/' + quote.id, body) : await Api.post('/sales/leads/' + leadId + '/quotes', body);
          // A change after it went out makes a new revision (KT-Q-000022-R2, 2026-11-07): say so.
          var newNo = quote && saved && saved.number && saved.number !== quote.number ? saved.number : '';
          // And where it stands now (2026-11-10): its status, and whether the customer still has an earlier version.
          var stNow = saved && saved.status ? (QSTATUS_LABEL[saved.status] || saved.status) : '';
          toast('✅', 'Proposal saved', (newNo ? 'Changed since it went out, so it is now ' + newNo + '. Email it to send the customer this version. ' : '') + (stNow ? 'Status: ' + stNow + '. ' : '') + (quote ? 'See ⋮ → History for what changed.' : ''));
          ov.remove(); renderDetail(container, ctx);
        } catch (er) { toast('⚠️', 'Failed', er.message || '', '#DC2626'); ev.target.disabled = false; }
      }),
    ]));
    ov.appendChild(box); document.body.appendChild(ov);
  }

  // ─────────────────────────────── Follow-ups ───────────────────────────────
  /* FOLLOW-UPS (2026-10-23, Anthony: "follow-ups under sales the pills shown are not full
     screen and create some graphics on average it takes to followup and other stats").
     The whole window (full-window rule), stat tiles, an 8-week set-vs-done chart, what is
     due over the next 14 days, each salesperson's numbers, then the open follow-ups as
     cards that flow into columns. Figures: GET /sales/followups/stats
     (SalesFollowupStatsController), Eastern Time. */
  async function renderFollowups(container) {
    clear(container);
    container.appendChild(hero('Follow-ups', 'Open follow-up tasks across your leads, and how quickly the team gets to them.', '⏰'));
    if (KT.salesLeadTabs) container.appendChild(KT.salesLeadTabs('followups'));   // a Leads sub-tab now (2026-11-04)
    var host = el('div', { style: 'width:100%' }, [el('div', { style: 'padding:20px;color:#64748B' }, ['Loading…'])]);
    container.appendChild(host);
    var d;
    try { d = await Api.get('/sales/followups/stats'); } catch (e) { clear(host); host.appendChild(card([el('div', { style: 'color:#DC2626' }, ['Could not load follow-ups: ' + (e.message || e)])])); return; }
    clear(host);
    var sm = d.summary || {};
    function hrs(h) { if (h === null || h === undefined) return '—'; if (h < 1) return Math.max(1, Math.round(h * 60)) + ' min'; if (h < 48) return (Math.round(h * 10) / 10) + ' h'; return (Math.round(h / 24 * 10) / 10) + ' days'; }
    function days(n) { return n === null || n === undefined ? '—' : (n < 1 ? Math.max(1, Math.round(n * 24)) + ' h' : n + (n === 1 ? ' day' : ' days')); }
    function tile(icon, label, value, sub, color) {
      return el('div', { style: 'background:#fff;border:1px solid #E6EBF1;border-radius:14px;padding:12px 14px;min-width:0;box-shadow:0 1px 2px rgba(15,23,42,.04)' }, [
        el('div', { style: 'font-size:12px;font-weight:700;color:#64748B;display:flex;gap:6px;align-items:center' }, [icon + ' ' + label]),
        el('div', { style: 'font-size:24px;font-weight:800;color:' + (color || '#0F172A') + ';margin-top:4px;line-height:1.1' }, [String(value)]),
        sub ? el('div', { style: 'font-size:11.5px;color:#94A3B8;margin-top:3px' }, [sub]) : null,
      ]);
    }
    host.appendChild(el('div', { style: 'display:grid;grid-template-columns:repeat(auto-fill,minmax(min(100%,180px),1fr));gap:10px;margin-bottom:14px' }, [
      tile('📌', 'Open', sm.open || 0, (sm.due_week || 0) + ' due this week'),
      tile('⏰', 'Overdue', sm.overdue || 0, sm.avg_overdue_days ? 'avg ' + days(sm.avg_overdue_days) + ' late' : 'nothing late', (sm.overdue ? '#C2410C' : '#047857')),
      tile('📅', 'Due today', sm.due_today || 0, ''),
      tile('⚡', 'First response', hrs(sm.avg_first_touch_hours), 'average from a new lead to first contact' + (sm.median_first_touch_hours !== null && sm.median_first_touch_hours !== undefined ? ' · median ' + hrs(sm.median_first_touch_hours) : '')),
      tile('⏱', 'Time to follow up', days(sm.avg_close_days), 'average from setting a follow-up to done'),
      tile('🎯', 'On time', sm.on_time_pct === null || sm.on_time_pct === undefined ? '—' : sm.on_time_pct + '%', 'done by their due date', sm.on_time_pct >= 80 ? '#047857' : (sm.on_time_pct === null || sm.on_time_pct === undefined ? '#0F172A' : '#B45309')),
      tile('✅', 'Done (30 days)', sm.done_30 || 0, ''),
      tile('🫥', 'Not contacted yet', sm.leads_untouched || 0, 'of ' + (sm.leads_measured || 0) + ' leads in 6 months', sm.leads_untouched ? '#B45309' : '#047857'),
    ]));

    // Small SVG bar charts, no library.
    function bars(title, rows, series, labelOf) {
      var W = 560, Hh = 170, pad = 26, n = rows.length, max = 1;
      rows.forEach(function (r) { series.forEach(function (sx) { max = Math.max(max, r[sx.key] || 0); }); });
      max = Math.max(2, Math.ceil(max / 2) * 2);   // an even scale, so the three grid labels are whole and distinct
      var bw = (W - pad * 2) / n, inner = Math.max(4, (bw - 6) / series.length);
      var svg = '<svg viewBox="0 0 ' + W + ' ' + (Hh + 30) + '" style="width:100%;height:auto;display:block" role="img" aria-label="' + esc(title) + '">';
      for (var g = 0; g <= 2; g++) { var gy = pad + (Hh - pad) * g / 2; svg += '<line x1="' + pad + '" x2="' + (W - pad) + '" y1="' + gy + '" y2="' + gy + '" stroke="#EEF2F6"/>' + '<text x="4" y="' + (gy + 4) + '" font-size="10" fill="#94A3B8">' + Math.round(max * (2 - g) / 2) + '</text>'; }
      rows.forEach(function (r, i) {
        series.forEach(function (sx, j) {
          var v = r[sx.key] || 0, h = (Hh - pad) * v / max, x = pad + i * bw + 3 + j * inner, y = Hh - h;
          svg += '<rect x="' + x + '" y="' + y + '" width="' + (inner - 2) + '" height="' + Math.max(0, h) + '" rx="3" fill="' + sx.color + '"><title>' + esc(sx.label + ': ' + v) + '</title></rect>';
          if (v) svg += '<text x="' + (x + (inner - 2) / 2) + '" y="' + (y - 3) + '" font-size="10" text-anchor="middle" fill="#475569">' + v + '</text>';
        });
        if (n <= 8 || i % 2 === 0) svg += '<text x="' + (pad + i * bw + bw / 2) + '" y="' + (Hh + 16) + '" font-size="10" text-anchor="middle" fill="#64748B">' + esc(labelOf(r)) + '</text>';
      });
      svg += '</svg>';
      var box = el('div', { style: 'background:#fff;border:1px solid #E6EBF1;border-radius:14px;padding:12px 14px;min-width:0' });
      box.innerHTML = '<div style="display:flex;justify-content:space-between;gap:8px;flex-wrap:wrap;align-items:center;margin-bottom:6px"><div style="font-weight:800;font-size:14px;color:#0F172A">' + esc(title) + '</div><div style="font-size:11.5px;color:#64748B">'
        + series.map(function (sx) { return '<span style="display:inline-block;width:9px;height:9px;border-radius:2px;background:' + sx.color + ';margin:0 4px 0 10px"></span>' + esc(sx.label); }).join('') + '</div></div>' + svg;
      return box;
    }
    var mon = function (iso) { var x = new Date(iso + 'T12:00:00'); return x.toLocaleDateString(undefined, { month: 'short', day: 'numeric' }); };
    host.appendChild(el('div', { style: 'display:grid;grid-template-columns:repeat(auto-fit,minmax(min(100%,420px),1fr));gap:12px;margin-bottom:14px' }, [
      bars('Follow-ups set vs done — last 8 weeks', d.weeks || [], [{ key: 'set', label: 'Set', color: '#93C5FD' }, { key: 'done', label: 'Done', color: '#047857' }], function (r) { return mon(r.week); }),
      bars('Due over the next 14 days', d.next || [], [{ key: 'due', label: 'Due (today includes overdue)', color: '#F59E0B' }], function (r) { var x = new Date(r.date + 'T12:00:00'); return x.toLocaleDateString(undefined, { weekday: 'short' }).slice(0, 2) + ' ' + x.getDate(); }),
    ]));

    // Per salesperson.
    if ((d.by_owner || []).length) {
      var t = el('table', { 'data-kt-filtered': '1', style: 'width:100%;border-collapse:collapse;background:#fff;border:1px solid #E6EBF1;border-radius:12px;overflow:hidden;margin-bottom:14px' });
      t.appendChild(el('thead', {}, [el('tr', { style: 'background:#F4F6F9;text-align:left' }, ['Salesperson', 'Open', 'Overdue', 'Done (30 days)', 'Time to follow up', 'First response'].map(function (h) { return el('th', { style: 'padding:9px 12px;font-size:12px;color:#5A7080' }, [h]); }))]));
      var tb = el('tbody');
      d.by_owner.forEach(function (o) {
        tb.appendChild(el('tr', { style: 'border-top:1px solid #EEF1F5' }, [
          el('td', { style: 'padding:9px 12px;font-weight:700' }, [o.name]), el('td', { style: 'padding:9px 12px' }, [String(o.open)]),
          el('td', { style: 'padding:9px 12px;color:' + (o.overdue ? '#C2410C' : '#334155') + ';font-weight:' + (o.overdue ? '700' : '400') }, [String(o.overdue)]),
          el('td', { style: 'padding:9px 12px' }, [String(o.done_30)]), el('td', { style: 'padding:9px 12px' }, [days(o.avg_close_days)]), el('td', { style: 'padding:9px 12px' }, [hrs(o.avg_first_touch_hours)]),
        ]));
      });
      t.appendChild(tb);
      host.appendChild(el('div', { style: 'overflow-x:auto' }, [t]));
    }

    // The open follow-ups, as cards that use the whole width.
    var items = d.items || [], today = d.today || todayISO();
    var view = 'all';
    var chips = el('div', { style: 'display:flex;gap:8px;flex-wrap:wrap;align-items:center;margin:4px 0 10px' });
    var grid = el('div', { style: 'display:grid;grid-template-columns:repeat(auto-fill,minmax(min(100%,340px),1fr));gap:10px' });
    host.appendChild(chips); host.appendChild(grid);
    var WEEK = (function () { var x = new Date(today + 'T12:00:00'); x.setDate(x.getDate() + 6); return x.getFullYear() + '-' + ('0' + (x.getMonth() + 1)).slice(-2) + '-' + ('0' + x.getDate()).slice(-2); })();
    var F = { all: function () { return true; }, overdue: function (i) { return i.days_overdue > 0; }, today: function (i) { return i.due === today; }, week: function (i) { return i.due && i.due >= today && i.due <= WEEK; }, later: function (i) { return !i.due || i.due > WEEK; } };
    function paint() {
      clear(chips);
      [['all', 'All'], ['overdue', '⏰ Overdue'], ['today', 'Today'], ['week', 'This week'], ['later', 'Later']].forEach(function (c) {
        var n = items.filter(F[c[0]]).length, on = view === c[0];
        chips.appendChild(el('button', { type: 'button', role: 'tab', 'data-tab': c[0], style: 'height:30px;padding:0 12px;border-radius:999px;border:1px solid ' + (on ? ACCENT : '#CBD5E1') + ';background:' + (on ? ACCENT : '#fff') + ';color:' + (on ? '#fff' : '#334155') + ';font-weight:700;font-size:12.5px;cursor:pointer;white-space:nowrap',
          onclick: function () { view = c[0]; paint(); } }, [c[1] + ' (' + n + ')']));
      });
      clear(grid);
      var rows = items.filter(F[view]);
      if (!rows.length) { grid.appendChild(card([el('div', { style: 'text-align:center;color:#64748B;padding:18px' }, ['🎉 Nothing here.'])])); return; }
      rows.forEach(function (i) {
        var late = i.days_overdue > 0;
        grid.appendChild(el('div', { style: 'background:#fff;border:1px solid ' + (late ? '#FED7AA' : '#E6EBF1') + ';--kt-edge:' + (late ? '#EA580C' : (i.due === today ? '#F59E0B' : ACCENT)) + ';border-radius:12px;padding:12px 14px;cursor:pointer;min-width:0', onclick: function () { go('sales-lead?id=' + i.lead_id); } }, [
          el('div', { style: 'display:flex;justify-content:space-between;gap:10px;align-items:flex-start' }, [
            el('div', { style: 'min-width:0' }, [
              el('div', { style: 'font-weight:800;font-size:14.5px;overflow-wrap:anywhere' }, [i.lead]),
              i.contact ? el('div', { style: 'font-size:12px;color:#64748B' }, [i.contact]) : null,
            ]),
            el('div', { style: 'text-align:right;white-space:nowrap;font-weight:700;font-size:12.5px;color:' + (late ? '#C2410C' : '#334155') }, [i.due ? (late ? '⏰ ' + i.days_overdue + 'd late' : (i.due === today ? 'Today' : fmtDate(i.due))) : 'No date']),
          ]),
          i.body ? el('div', { style: 'font-size:13px;color:#334155;margin-top:6px;line-height:1.45;overflow-wrap:anywhere' }, [i.body]) : null,
          el('div', { style: 'display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin-top:8px;font-size:12px;color:#64748B' }, [stageChip(i.stage), '👤 ' + i.owner, late ? el('span', {}, ['· due ' + fmtDate(i.due)]) : null]),
        ]));
      });
    }
    paint();
  }



  // ─────────────────────────────── Sales calendar ───────────────────────────────
  /* THE SALES CALENDAR (2026-10-23, Anthony: "a sales calendar under sales to show all of the
     bookings and whereabouts of sales staff including allowing sales staff to show their
     availability and book/block time off etc"). Website bookings, pipeline follow-ups and
     meetings, and each person's meetings, blocked time, time off and whereabouts - in
     Eastern Time, one colour per person. Working hours set here also decide which times the
     website's booking wizard offers. SalesCalendarController / App\Support\SalesCalendar. */
  var CAL_KIND = { meeting: ['🗓', 'Meeting'], block: ['⛔', 'Blocked'], time_off: ['🌴', 'Time off'], where: ['📍', 'Whereabouts'] };
  var WHERE_ICON = { office: '🏢', remote: '🏠', road: '🚗', client: '🤝', out: '🚫' };
  var DAYS = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
  function ymd(d) { return d.getFullYear() + '-' + ('0' + (d.getMonth() + 1)).slice(-2) + '-' + ('0' + d.getDate()).slice(-2); }
  function etParts(iso) {
    // Wall-clock parts in Eastern Time for an ISO instant.
    var f = new Intl.DateTimeFormat('en-CA', { timeZone: 'America/Toronto', year: 'numeric', month: '2-digit', day: '2-digit', hour: '2-digit', minute: '2-digit', hourCycle: 'h23' });
    var o = {}; f.formatToParts(new Date(iso)).forEach(function (p) { o[p.type] = p.value; });
    return { date: o.year + '-' + o.month + '-' + o.day, h: +o.hour, m: +o.minute, hm: o.hour + ':' + o.minute };
  }
  function fmtHm(hm) { var h = +hm.slice(0, 2), m = hm.slice(3, 5); return ((h % 12) || 12) + (m !== '00' ? ':' + m : '') + (h < 12 ? ' AM' : ' PM'); }
  function addDays(dstr, n) { var d = new Date(dstr + 'T12:00:00'); d.setDate(d.getDate() + n); return ymd(d); }
  function todayEt() { return etParts(new Date().toISOString()).date; }

  async function renderSalesCalendar(container) {
    clear(container);
    container.appendChild(hero('Calendar', 'Bookings, meetings, time off and where everyone is — Eastern Time.', '🗓'));
    var narrow = window.innerWidth < 760;
    var st = { view: narrow ? 'list' : 'week', anchor: todayEt(), data: null, hidden: {}, panel: null };
    try { var sv = JSON.parse(localStorage.getItem('kt_salescal') || '{}'); if (sv.view && !narrow) st.view = sv.view; if (sv.hidden) st.hidden = sv.hidden; } catch (e) {}
    var bar = el('div', { style: 'display:flex;gap:8px;flex-wrap:wrap;align-items:center;margin-bottom:10px' });
    var legend = el('div', { style: 'display:flex;gap:6px;flex-wrap:wrap;align-items:center;margin-bottom:10px' });
    var panel = el('div');
    /* THE ENTRY OPENS IN THE HOUSE POPUP (2026-11-26, Anthony: "we need to keep thing consistent throughout the
       portal" - full sweep after report cards). Viewing, adding or changing an entry and the working-hours form
       used to open as a card above the calendar. Every one of them still draws into `panel` and empties it when
       done, so the popup simply follows it: it opens when something is drawn there and closes when it is emptied
       (Save, Cancel, Delete), and the ✕ or Escape empties it. The calendar behind it never moves. */
    (function popupHost(p) {
      var h = null;
      new MutationObserver(function () {
        if (p.firstChild && !h && window.KT && KT.Shell && KT.Shell.Modal) {
          var head = p.querySelector('[style*="font-weight: 800"], [style*="font-weight:800"]');
          var title = (head && head.textContent.trim()) || 'Calendar';
          if (head && head.parentNode && head.parentNode.parentNode === p && head.textContent.trim() === title && head.children.length === 0) head.style.display = 'none';
          var mine = KT.Shell.Modal.open({ title: '📅 ' + title.slice(0, 80), body: p, large: true,
            onClose: function () { if (h === mine) { h = null; if (p.firstChild) clear(p); } } });
          h = mine;
        } else if (!p.firstChild && h) { var x = h; h = null; x.close(); }
      }).observe(p, { childList: true });
    })(panel);
    var body = el('div');
    // The whole window, not a centred column (Anthony, 2026-10-23).
    container.appendChild(el('div', { style: 'width:100%' }, [bar, legend, body]));
    function save() { try { localStorage.setItem('kt_salescal', JSON.stringify({ view: st.view, hidden: st.hidden })); } catch (e) {} }

    function range() {
      var a = new Date(st.anchor + 'T12:00:00');
      if (st.view === 'month') { var f = new Date(a.getFullYear(), a.getMonth(), 1), l = new Date(a.getFullYear(), a.getMonth() + 1, 0);
        var gs = new Date(f); gs.setDate(f.getDate() - ((f.getDay() + 6) % 7)); var ge = new Date(l); ge.setDate(l.getDate() + (6 - ((l.getDay() + 6) % 7)));
        return [ymd(gs), ymd(ge)]; }
      if (st.view === 'list') return [st.anchor, addDays(st.anchor, 13)];
      var ws = new Date(a); ws.setDate(a.getDate() - ((a.getDay() + 6) % 7)); return [ymd(ws), addDays(ymd(ws), 6)];
    }
    function staffOf(id) { return ((st.data && st.data.staff) || []).filter(function (s) { return s.id === id; })[0]; }
    function color(id) { var s = staffOf(id); return s ? s.color : '#64748B'; }
    function shown(id) { return !id || !st.hidden[id]; }

    function toolbar() {
      clear(bar);
      var r = range(), lbl;
      var dA = new Date(r[0] + 'T12:00:00'), dB = new Date(r[1] + 'T12:00:00');
      if (st.view === 'month') lbl = new Date(st.anchor + 'T12:00:00').toLocaleDateString(undefined, { month: 'long', year: 'numeric' });
      else lbl = dA.toLocaleDateString(undefined, { month: 'short', day: 'numeric' }) + ' – ' + dB.toLocaleDateString(undefined, { month: 'short', day: 'numeric', year: 'numeric' });
      var step = st.view === 'month' ? 0 : (st.view === 'list' ? 14 : 7);
      var nav = function (dir) { if (st.view === 'month') { var a = new Date(st.anchor + 'T12:00:00'); a.setMonth(a.getMonth() + dir, 1); st.anchor = ymd(a); } else st.anchor = addDays(st.anchor, dir * step); load(); };
      bar.appendChild(btn('‹', 'ghost', function () { nav(-1); }));
      bar.appendChild(btn('Today', 'ghost', function () { st.anchor = todayEt(); load(); }));
      bar.appendChild(btn('›', 'ghost', function () { nav(1); }));
      bar.appendChild(el('div', { style: 'font-weight:800;font-size:16px;color:#0F172A;margin:0 6px' }, [lbl]));
      bar.appendChild(el('div', { style: 'flex:1' }));
      ['week', 'month', 'list'].forEach(function (v) {
        bar.appendChild(el('button', { type: 'button', 'data-kt-noband': '1', style: 'height:32px;padding:0 12px;border-radius:999px;border:1px solid ' + (st.view === v ? ACCENT : '#CBD5E1') + ';background:' + (st.view === v ? ACCENT : '#fff') + ';color:' + (st.view === v ? '#fff' : '#334155') + ';font-weight:700;font-size:13px;cursor:pointer',
          onclick: function () { st.view = v; save(); load(); } }, [v === 'week' ? 'Week' : (v === 'month' ? 'Month' : 'List')]));
      });
      // Meetings with invitations and Teams, like Outlook (2026-10-25).
      bar.appendChild(btn('🤝 New meeting', 'primary', function () { if (KT.Meeting) KT.Meeting.compose({ scope: 'platform', date: st.view === 'week' ? (range()[0] > todayEt() ? range()[0] : todayEt()) : todayEt(), onSaved: load }); }));
      bar.appendChild(btn('＋ Time off / block', 'light', function () { editor(null, { date: st.view === 'week' ? range()[0] : todayEt(), kind: 'block' }); }));
      bar.appendChild(btn('🕘 Working hours', 'ghost', function () { hoursEditor(); }));
    }
    function legendBar() {
      clear(legend);
      ((st.data && st.data.staff) || []).forEach(function (s) {
        var on = !st.hidden[s.id];
        legend.appendChild(el('button', { type: 'button', title: on ? 'Hide ' + s.first_name : 'Show ' + s.first_name, style: 'display:inline-flex;align-items:center;gap:6px;height:28px;padding:0 10px;border-radius:999px;border:1px solid ' + (on ? s.color : '#CBD5E1') + ';background:' + (on ? s.color + '14' : '#fff') + ';color:' + (on ? '#0F172A' : '#94A3B8') + ';font-size:12.5px;font-weight:700;cursor:pointer',
          onclick: function () { if (on) st.hidden[s.id] = 1; else delete st.hidden[s.id]; save(); draw(); legendBar(); } }, [el('span', { style: 'width:10px;height:10px;border-radius:50%;background:' + s.color }), s.first_name]));
      });
      legend.appendChild(el('span', { style: 'font-size:12px;color:#64748B;margin-left:6px' }, ['📅 website booking · ⏳ awaiting decision · 🌴 time off · ⛔ blocked · 📍 whereabouts · ☎ follow-up']));
    }

    // Everything in the range as items: {date, all, start, end (minutes), label, color, dashed, src, raw}
    function items() {
      var d = st.data || {}, out = [];
      (d.events || []).forEach(function (e) {
        if (!shown(e.user_id)) return;
        var s = etParts(e.start), en = etParts(e.end), who = (staffOf(e.user_id) || {}).first_name || '';
        var label = e.kind === 'where' ? (WHERE_ICON[e.where] || '📍') + ' ' + who + ': ' + (e.where_label || 'Whereabouts') + (e.location ? ' · ' + e.location : '')
          : (CAL_KIND[e.kind] || ['•'])[0] + ' ' + (e.title || (CAL_KIND[e.kind] || ['', ''])[1]) + (who && e.kind !== 'meeting' ? ' · ' + who : '');
        if (e.all_day) { for (var dd = s.date; dd < en.date; dd = addDays(dd, 1)) out.push({ date: dd, all: true, label: label, color: color(e.user_id), src: 'event', raw: e }); }
        else out.push({ date: s.date, all: false, start: s.h * 60 + s.m, end: (en.date !== s.date ? 24 * 60 : en.h * 60 + en.m), label: label, sub: fmtHm(s.hm) + '–' + fmtHm(en.hm) + (who ? ' · ' + who : ''), color: color(e.user_id), src: 'event', raw: e });
      });
      (d.bookings || []).forEach(function (b) {
        if (b.user_id && !shown(b.user_id)) return;
        var s = etParts(b.start), en = etParts(b.end), who = b.user_id ? ((staffOf(b.user_id) || {}).first_name || '') : '';
        out.push({ date: s.date, all: false, start: s.h * 60 + s.m, end: en.h * 60 + en.m, label: (b.status === 'requested' ? '⏳ ' : '📅 ') + b.title,
          sub: fmtHm(s.hm) + '–' + fmtHm(en.hm) + (b.status === 'requested' ? ' · needs a decision' : (who ? ' · ' + who : '')), color: b.user_id ? color(b.user_id) : '#B45309', dashed: b.status === 'requested', src: 'booking', raw: b });
      });
      (st.meetings || []).forEach(function (m) {
        if (m.organizer && !shown(m.organizer.id)) return;
        var s = etParts(m.start), en = etParts(m.end), col = m.organizer ? color(m.organizer.id) : '#7C3AED';
        var label = '🤝 ' + m.title + (m.video === 'teams' ? ' · Teams' : '');
        var sub = (m.all_day ? 'All day' : fmtHm(s.hm) + '–' + fmtHm(en.hm)) + (m.attendees && m.attendees.length ? ' · ' + m.attendees.length + ' invited' : '');
        if (m.all_day) { for (var dd = s.date; dd < en.date; dd = addDays(dd, 1)) out.push({ date: dd, all: true, label: label, sub: sub, color: col, src: 'meeting', raw: m }); }
        else out.push({ date: s.date, all: false, start: s.h * 60 + s.m, end: (en.date !== s.date ? 24 * 60 : en.h * 60 + en.m), label: label, sub: sub, color: col, src: 'meeting', raw: m });
      });
      (d.activities || []).forEach(function (a) {
        if (a.user_id && !shown(a.user_id)) return;
        out.push({ date: a.date, all: true, label: (a.type === 'meeting' ? '🤝 ' : '☎ ') + a.title, color: a.user_id ? color(a.user_id) : '#475569', src: 'activity', raw: a, light: true });
      });
      return out;
    }

    function chipEl(it, compact) {
      var c = el('div', { title: it.label + (it.sub ? '\n' + it.sub : ''), style: 'font-size:' + (compact ? '11.5px' : '12px') + ';line-height:1.3;padding:3px 7px;border-radius:6px;margin-bottom:3px;cursor:pointer;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;'
        + (it.light ? 'background:#F1F5F9;color:#334155;--kt-edge:' + it.color : 'background:' + it.color + '1F;color:#0F172A;--kt-edge:' + it.color) + (it.dashed ? ';outline:1.5px dashed ' + it.color : ''),
        onclick: function (ev) { ev.stopPropagation(); open(it); } }, [it.label]);
      return c;
    }

    function drawWeek() {
      // Hour rows sized so the working day fills the window height.
      var r = range(), days = [], H0 = 7, H1 = 20, PX = Math.max(46, Math.floor((window.innerHeight - 330) / (H1 - H0)));
      for (var i = 0; i < 7; i++) days.push(addDays(r[0], i));
      var its = items(), today = todayEt();
      var grid = el('div', { style: 'display:grid;grid-template-columns:56px repeat(7,minmax(110px,1fr));border:1px solid #E2E8F0;border-radius:14px;overflow:hidden;background:#fff;min-width:860px;width:100%' });
      grid.appendChild(el('div', { style: 'background:#F8FAFC;border-bottom:1px solid #E2E8F0' }));
      days.forEach(function (d) { var dt = new Date(d + 'T12:00:00');
        grid.appendChild(el('div', { style: 'padding:8px;border-bottom:1px solid #E2E8F0;border-left:1px solid #F1F5F9;background:' + (d === today ? '#ECFEFF' : '#F8FAFC') + ';text-align:center' }, [
          el('div', { style: 'font-size:11px;font-weight:800;letter-spacing:.06em;color:#64748B;text-transform:uppercase' }, [DAYS[dt.getDay()]]),
          el('div', { style: 'font-size:18px;font-weight:800;color:' + (d === today ? ACCENT : '#0F172A') }, [String(dt.getDate())])])); });
      // all-day row
      grid.appendChild(el('div', { style: 'font-size:10.5px;color:#94A3B8;padding:6px 4px;border-bottom:1px solid #E2E8F0;text-align:right' }, ['all day']));
      days.forEach(function (d) { var cell = el('div', { style: 'padding:4px;border-bottom:1px solid #E2E8F0;border-left:1px solid #F1F5F9;min-height:30px;cursor:pointer', onclick: function () { editor(null, { date: d, all_day: true, kind: 'time_off' }); } });
        its.filter(function (x) { return x.all && x.date === d; }).forEach(function (x) { cell.appendChild(chipEl(x, true)); }); grid.appendChild(cell); });
      // hours
      var times = el('div', { style: 'position:relative;height:' + ((H1 - H0) * PX) + 'px' });
      for (var h = H0; h < H1; h++) times.appendChild(el('div', { style: 'position:absolute;top:' + ((h - H0) * PX - 6) + 'px;right:6px;font-size:10.5px;color:#94A3B8' }, [((h % 12) || 12) + (h < 12 ? ' AM' : ' PM')]));
      grid.appendChild(times);
      var visibleStaff = ((st.data && st.data.staff) || []).filter(function (s) { return shown(s.id); });
      days.forEach(function (d) {
        var dow = new Date(d + 'T12:00:00').getDay();
        var col = el('div', { style: 'position:relative;height:' + ((H1 - H0) * PX) + 'px;border-left:1px solid #F1F5F9;background:repeating-linear-gradient(to bottom,#fff 0,#fff ' + (PX - 1) + 'px,#F1F5F9 ' + (PX - 1) + 'px,#F1F5F9 ' + PX + 'px)' });
        // working hours: a soft band per visible person (side by side)
        visibleStaff.forEach(function (s, si) { (s.hours || []).filter(function (x) { return x.weekday === dow; }).forEach(function (x) {
          var a = (+x.start.slice(0, 2) * 60 + +x.start.slice(3)) - H0 * 60, b = (+x.end.slice(0, 2) * 60 + +x.end.slice(3)) - H0 * 60;
          col.appendChild(el('div', { title: s.first_name + ' works ' + fmtHm(x.start) + '–' + fmtHm(x.end), style: 'position:absolute;left:' + (si * 4) + 'px;width:3px;top:' + Math.max(0, a / 60 * PX) + 'px;height:' + Math.max(0, (b - a) / 60 * PX) + 'px;background:' + s.color + ';opacity:.45;border-radius:2px' }));
        }); });
        col.onclick = function (ev) { if (ev.target !== col) return; var y = ev.offsetY; var mins = Math.floor(y / PX * 2) * 30 + H0 * 60; var hm = ('0' + Math.floor(mins / 60)).slice(-2) + ':' + ('0' + (mins % 60)).slice(-2);
          var e2 = mins + 60; editor(null, { date: d, start_time: hm, end_time: ('0' + Math.floor(e2 / 60)).slice(-2) + ':' + ('0' + (e2 % 60)).slice(-2), kind: 'block' }); };
        var timed = its.filter(function (x) { return !x.all && x.date === d; }).sort(function (a, b) { return a.start - b.start; });
        // side-by-side lanes for overlaps
        var lanes = [];
        timed.forEach(function (x) { var l = 0; while (lanes[l] && lanes[l] > x.start) l++; lanes[l] = x.end; x.lane = l; });
        var nl = Math.max(1, lanes.length);
        timed.forEach(function (x) {
          var top = Math.max(0, (x.start - H0 * 60) / 60 * PX), ht = Math.max(20, (Math.min(x.end, H1 * 60) - Math.max(x.start, H0 * 60)) / 60 * PX - 2);
          var box = el('div', { title: x.label + '\n' + (x.sub || ''), style: 'position:absolute;top:' + top + 'px;height:' + ht + 'px;left:calc(' + (x.lane * 100 / nl) + '% + 14px);width:calc(' + (100 / nl) + '% - 16px);background:' + x.color + '22;--kt-edge:' + x.color + ';border-radius:6px;padding:3px 6px;font-size:11.5px;line-height:1.25;overflow:hidden;cursor:pointer;color:#0F172A' + (x.dashed ? ';outline:1.5px dashed ' + x.color : ''),
            onclick: function (ev) { ev.stopPropagation(); open(x); } }, [el('div', { style: 'font-weight:700;overflow:hidden;text-overflow:ellipsis;white-space:nowrap' }, [x.label]), el('div', { style: 'color:#475569;font-size:10.5px' }, [x.sub || ''])]);
          col.appendChild(box);
        });
        // now line
        if (d === today) { var n = etParts(new Date().toISOString()), nm = n.h * 60 + n.m; if (nm >= H0 * 60 && nm <= H1 * 60) col.appendChild(el('div', { style: 'position:absolute;left:0;right:0;top:' + ((nm - H0 * 60) / 60 * PX) + 'px;height:2px;background:#EF4444' })); }
        grid.appendChild(col);
      });
      body.appendChild(el('div', { style: 'overflow-x:auto' }, [grid]));
    }

    function drawMonth() {
      var r = range(), its = items(), today = todayEt(), month = new Date(st.anchor + 'T12:00:00').getMonth();
      var grid = el('div', { style: 'display:grid;grid-template-columns:repeat(7,minmax(0,1fr));border:1px solid #E2E8F0;border-radius:14px;overflow:hidden;background:#fff' });
      ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'].forEach(function (n) { grid.appendChild(el('div', { style: 'padding:6px 8px;font-size:11px;font-weight:800;color:#64748B;background:#F8FAFC;border-bottom:1px solid #E2E8F0;text-transform:uppercase' }, [n])); });
      for (var d = r[0]; d <= r[1]; d = addDays(d, 1)) {
        (function (d) {
          var dt = new Date(d + 'T12:00:00');
          var cell = el('div', { style: 'min-height:' + Math.max(104, Math.floor((window.innerHeight - 320) / 6)) + 'px;padding:5px;border-bottom:1px solid #F1F5F9;border-left:1px solid #F1F5F9;cursor:pointer;background:' + (dt.getMonth() !== month ? '#FAFAFA' : '#fff'), onclick: function () { editor(null, { date: d, all_day: true, kind: 'time_off' }); } });
          cell.appendChild(el('div', { style: 'font-size:12.5px;font-weight:800;margin-bottom:3px;color:' + (d === today ? ACCENT : (dt.getMonth() !== month ? '#CBD5E1' : '#0F172A')) }, [String(dt.getDate())]));
          var di = its.filter(function (x) { return x.date === d; }).sort(function (a, b) { return (a.all ? -1 : a.start) - (b.all ? -1 : b.start); });
          di.slice(0, 4).forEach(function (x) { cell.appendChild(chipEl(x, true)); });
          if (di.length > 4) cell.appendChild(el('div', { style: 'font-size:11px;color:#64748B;font-weight:700', onclick: function (ev) { ev.stopPropagation(); st.view = 'list'; st.anchor = d; load(); } }, ['+' + (di.length - 4) + ' more']));
          grid.appendChild(cell);
        })(d);
      }
      body.appendChild(grid);
    }

    function drawList() {
      var r = range(), its = items(), any = false;
      for (var d = r[0]; d <= r[1]; d = addDays(d, 1)) {
        var di = its.filter(function (x) { return x.date === d; }).sort(function (a, b) { return (a.all ? -1 : a.start) - (b.all ? -1 : b.start); });
        if (!di.length) continue; any = true;
        var dt = new Date(d + 'T12:00:00');
        var c = card([el('div', { style: 'font-weight:800;font-size:14px;color:' + (d === todayEt() ? ACCENT : '#0F172A') + ';margin-bottom:8px' }, [dt.toLocaleDateString(undefined, { weekday: 'long', month: 'long', day: 'numeric' })])], 'padding:12px 14px;margin-bottom:10px');
        di.forEach(function (x) {
          c.appendChild(el('div', { style: 'display:flex;gap:10px;align-items:flex-start;padding:7px 0;border-top:1px solid #F1F5F9;cursor:pointer', onclick: function () { open(x); } }, [
            el('div', { style: 'flex:0 0 74px;font-size:12px;color:#64748B;font-weight:700' }, [x.all ? 'All day' : (x.sub || '').split(' · ')[0]]),
            el('div', { style: 'flex:0 0 4px;align-self:stretch;border-radius:2px;background:' + x.color }),
            el('div', { style: 'min-width:0;flex:1' }, [el('div', { style: 'font-weight:700;font-size:13.5px;color:#0F172A' }, [x.label]), x.sub && !x.all ? el('div', { style: 'font-size:12px;color:#64748B' }, [x.sub.split(' · ').slice(1).join(' · ')]) : null]),
          ]));
        });
        body.appendChild(c);
      }
      if (!any) body.appendChild(card([el('div', { style: 'text-align:center;color:#64748B;padding:18px' }, ['Nothing in these two weeks.'])]));
    }

    function draw() { clear(body); if (st.view === 'week') drawWeek(); else if (st.view === 'month') drawMonth(); else drawList(); }

    function open(it) {
      if (it.src === 'meeting') { if (KT.Meeting) KT.Meeting.view(it.raw.id, { onChange: load }); return; }
      if (it.src === 'booking') { go('sales-bookings?id=' + it.raw.id); return; }
      if (it.src === 'activity') { go('sales-lead?id=' + it.raw.lead_id); return; }
      editor(it.raw);
    }

    function inp(type, val, extra) { return el('input', Object.assign({ type: type, value: val || '', style: 'width:100%;height:34px;border:1px solid #CBD5E1;border-radius:8px;padding:0 9px;font-size:13.5px;box-sizing:border-box;background:#fff' }, extra || {})); }
    function fld(label, node, w) { return el('div', { style: 'flex:1 1 ' + (w || 150) + 'px;min-width:0' }, [el('div', { style: 'font-size:11.5px;font-weight:700;color:#64748B;margin-bottom:3px' }, [label]), node]); }

    function editor(ev, pre) {
      clear(panel);
      var d = st.data || {}, me = d.me, isNew = !ev; pre = pre || {};
      if (ev && !ev.editable) { panel.appendChild(card([el('div', { style: 'font-weight:800' }, [(CAL_KIND[ev.kind] || ['•'])[0] + ' ' + (ev.title || ev.where_label || '')]), el('div', { style: 'font-size:13px;color:#64748B;margin-top:4px' }, ['Only ' + ((staffOf(ev.user_id) || {}).first_name || 'they') + ' or a platform admin can change this.']), btn('Close', 'ghost', function () { clear(panel); })], 'margin-bottom:12px')); return; }
      var s0 = ev ? etParts(ev.start) : null, e0 = ev ? etParts(ev.end) : null;
      var kind = el('select', { style: 'width:100%;height:34px;border:1px solid #CBD5E1;border-radius:8px;padding:0 6px;font-size:13.5px;background:#fff' },
        Object.keys(CAL_KIND).map(function (k) { var o = el('option', { value: k }, [CAL_KIND[k][0] + ' ' + CAL_KIND[k][1]]); if (k === (ev ? ev.kind : (pre.kind || 'meeting'))) o.selected = true; return o; }));
      var who = el('select', { style: 'width:100%;height:34px;border:1px solid #CBD5E1;border-radius:8px;padding:0 6px;font-size:13.5px;background:#fff' },
        (d.staff || []).filter(function (s) { return d.admin || s.id === me; }).map(function (s) { var o = el('option', { value: String(s.id) }, [s.name]); if (s.id === (ev ? ev.user_id : me)) o.selected = true; return o; }));
      var title = inp('text', ev ? ev.title : '', { placeholder: 'e.g. Demo with Little Steps, Dentist, Lunch' });
      var allDay = el('input', { type: 'checkbox' }); allDay.checked = ev ? ev.all_day : !!pre.all_day;
      var date = inp('date', ev ? s0.date : pre.date), endDate = inp('date', ev ? (ev.all_day ? addDays(e0.date, -1) : e0.date) : (pre.date || ''));
      var t1 = inp('time', ev && !ev.all_day ? s0.hm : (pre.start_time || '09:00'), { step: '900' }), t2 = inp('time', ev && !ev.all_day ? e0.hm : (pre.end_time || '10:00'), { step: '900' });
      var where = el('select', { style: 'width:100%;height:34px;border:1px solid #CBD5E1;border-radius:8px;padding:0 6px;font-size:13.5px;background:#fff' },
        Object.keys(d.where || {}).map(function (k) { var o = el('option', { value: k }, [(WHERE_ICON[k] || '') + ' ' + d.where[k]]); if (ev && ev.where === k) o.selected = true; return o; }));
      var loc = inp('text', ev ? ev.location : '', { placeholder: 'Where (optional)' });
      var notes = el('textarea', { rows: '2', placeholder: 'Notes (optional)', style: 'width:100%;border:1px solid #CBD5E1;border-radius:8px;padding:8px 10px;font-size:13.5px;box-sizing:border-box;font-family:inherit' }, []); notes.value = ev ? (ev.notes || '') : '';
      var msg = el('div', { style: 'font-size:12.5px;margin-top:6px' });
      var timeRow = el('div', { style: 'display:contents' }, [fld('Starts', t1, 110), fld('Ends', t2, 110)]);
      var whereRow = fld('Whereabouts', where, 200);
      var sync = function () { var k = kind.value; whereRow.style.display = k === 'where' ? '' : 'none'; title.parentNode.style.display = k === 'where' ? 'none' : '';
        var ad = allDay.checked || k === 'where'; timeRow.style.display = ad ? 'none' : 'contents'; allDay.parentNode.style.display = k === 'where' ? 'none' : 'flex'; };
      kind.onchange = sync; allDay.onchange = sync;
      var save2 = btn(isNew ? 'Add to calendar' : 'Save changes', 'primary', function () {
        var body2 = { kind: kind.value, user_id: Number(who.value) || null, title: title.value.trim() || null, date: date.value, end_date: endDate.value || date.value,
          all_day: allDay.checked || kind.value === 'where', start_time: t1.value, end_time: t2.value, where: kind.value === 'where' ? where.value : null, location: loc.value.trim() || null, notes: notes.value.trim() || null };
        if (!body2.date) { msg.style.color = '#B91C1C'; msg.textContent = 'Choose a date.'; return; }
        save2.disabled = true; msg.style.color = '#64748B'; msg.textContent = 'Saving…';
        (isNew ? Api.post('/sales/calendar/events', body2) : Api.patch('/sales/calendar/events/' + ev.id, body2)).then(function () { clear(panel); toast('✅', isNew ? 'Added' : 'Saved', ''); load(); })
          .catch(function (e) { save2.disabled = false; msg.style.color = '#B91C1C'; msg.textContent = e.message; });
      });
      /* Two rows on one grid (2026-10-25: "the fields are all over the place in a mess"):
         what / who / title, then the date, times and all-day, every column the same width. */
      var GRID = 'display:grid;grid-template-columns:repeat(auto-fill,minmax(150px,1fr));gap:10px;align-items:end';
      var row1 = el('div', { style: 'display:grid;grid-template-columns:minmax(150px,1fr) minmax(150px,1fr) minmax(220px,2fr);gap:10px;align-items:end', 'data-kt-nopack': '1' }, [fld('What', kind, 160), fld('Who', who, 160), fld('Title', title, 240)]);
      var row2 = el('div', { style: GRID + ';margin-top:10px', 'data-kt-nopack': '1' }, [fld('Date', date, 140), fld('Until (optional)', endDate, 140), timeRow,
        el('label', { style: 'display:flex;gap:6px;align-items:center;font-size:13px;font-weight:700;color:#334155;height:34px' }, [allDay, 'All day']), whereRow]);
      var acts = el('div', { style: 'display:flex;gap:8px;margin-top:10px;flex-wrap:wrap;align-items:center' }, [save2, btn('Cancel', 'ghost', function () { clear(panel); })]);
      if (!isNew) acts.appendChild(btn('Delete', 'danger', function () {
        Promise.resolve(window.KT && KT.confirm ? KT.confirm('Remove this from the calendar?') : true).then(function (ok) { if (!ok) return;
          Api.delete('/sales/calendar/events/' + ev.id).then(function () { clear(panel); toast('🗑', 'Removed', ''); load(); }).catch(function (e) { msg.style.color = '#B91C1C'; msg.textContent = e.message; }); });
      }));
      panel.appendChild(card([el('div', { style: 'font-weight:800;font-size:15px;margin-bottom:10px' }, [isNew ? 'Add to the calendar' : 'Change this entry']), row1, row2,
        el('div', { style: 'display:flex;gap:10px;flex-wrap:wrap;margin-top:8px' }, [fld('Location', loc, 200)]), el('div', { style: 'margin-top:8px' }, [notes]), acts, msg], 'margin-bottom:12px'));
      sync();
    }

    function hoursEditor() {
      clear(panel);
      var d = st.data || {}, me = d.me;
      var who = el('select', { style: 'height:34px;border:1px solid #CBD5E1;border-radius:8px;padding:0 6px;font-size:13.5px;background:#fff' },
        (d.staff || []).filter(function (s) { return d.admin || s.id === me; }).map(function (s) { var o = el('option', { value: String(s.id) }, [s.name]); if (s.id === me) o.selected = true; return o; }));
      var rowsBox = el('div');
      var msg = el('div', { style: 'font-size:12.5px;margin-top:6px' });
      function paint() {
        clear(rowsBox);
        var s = staffOf(Number(who.value)) || { hours: [] };
        [1, 2, 3, 4, 5, 6, 0].forEach(function (wd) {
          var h = (s.hours || []).filter(function (x) { return x.weekday === wd; })[0];
          var on = el('input', { type: 'checkbox', 'data-wd': String(wd) }); on.checked = !!h;
          var a = inp('time', h ? h.start : '09:00', { step: '900', 'data-a': String(wd) }), b = inp('time', h ? h.end : '17:00', { step: '900', 'data-b': String(wd) });
          a.style.width = b.style.width = '120px';
          rowsBox.appendChild(el('div', { style: 'display:flex;gap:10px;align-items:center;padding:5px 0;border-top:1px solid #F1F5F9' }, [
            el('label', { style: 'display:flex;gap:8px;align-items:center;flex:0 0 90px;font-weight:700;font-size:13.5px' }, [on, DAYS[wd]]), a, el('span', { style: 'color:#94A3B8' }, ['to']), b]));
        });
      }
      who.onchange = paint; paint();
      var save3 = btn('Save working hours', 'primary', function () {
        var hours = [];
        rowsBox.querySelectorAll('input[data-wd]').forEach(function (c) { if (!c.checked) return; var wd = c.getAttribute('data-wd');
          hours.push({ weekday: Number(wd), start: rowsBox.querySelector('[data-a="' + wd + '"]').value, end: rowsBox.querySelector('[data-b="' + wd + '"]').value }); });
        save3.disabled = true; msg.style.color = '#64748B'; msg.textContent = 'Saving…';
        Api.put('/sales/calendar/availability', { user_id: Number(who.value), hours: hours }).then(function () { clear(panel); toast('✅', 'Working hours saved', 'The website booking wizard now follows them.'); load(); })
          .catch(function (e) { save3.disabled = false; msg.style.color = '#B91C1C'; msg.textContent = e.message; });
      });
      panel.appendChild(card([el('div', { style: 'display:flex;gap:10px;align-items:center;flex-wrap:wrap;margin-bottom:6px' }, [el('div', { style: 'font-weight:800;font-size:15px' }, ['Working hours (Eastern)']), d.admin ? who : null]),
        el('div', { style: 'font-size:12.5px;color:#64748B;margin-bottom:8px;line-height:1.5' }, ['The website only offers meeting times when someone on the sales team is working and free. Time off, blocked time and meetings on this calendar are taken out automatically.']),
        rowsBox, el('div', { style: 'display:flex;gap:8px;margin-top:10px' }, [save3, btn('Cancel', 'ghost', function () { clear(panel); })]), msg], 'margin-bottom:12px'));
    }

    function load() {
      toolbar(); clear(body); body.appendChild(el('div', { style: 'color:#64748B;padding:20px;text-align:center' }, ['Loading…']));
      var r = range();
      Promise.all([Api.get('/sales/calendar?from=' + r[0] + '&to=' + r[1]), Api.get('/meetings?scope=platform&from=' + r[0] + '&to=' + r[1]).catch(function () { return { meetings: [] }; })])
        .then(function (both) { var d = both[0]; st.meetings = (both[1] && both[1].meetings) || []; st.data = d; legendBar(); draw(); })
        .catch(function (e) { clear(body); body.appendChild(card([el('div', { style: 'color:#B91C1C' }, [e.message])])); });
    }
    load();
  }

  // ─────────────────────────────── Bookings ───────────────────────────────
  /* WEBSITE MEETING REQUESTS (2026-10-23, Anthony: "i need the bookings to come to sales to
     accept, modify or decline before sending out the confirmation email on a booked meeting
     with a teams link and all of the details on what to expect"). The visitor has only been
     told "we received your request". Accept sends the full confirmation (Teams link, agenda,
     how to prepare, calendar invite); Modify confirms at a new time; Decline sends a courteous
     note with a link to choose another time. SalesBookingController / BookingMail. */
  var BK_STATUS = { requested: ['Needs a decision', '#B45309', '#FEF3C7'], booked: ['Confirmed', '#047857', '#D1FAE5'],
    declined: ['Declined', '#64748B', '#F1F5F9'], cancelled: ['Cancelled', '#64748B', '#F1F5F9'] };
  async function renderBookings(container) {
    clear(container);
    container.appendChild(hero('Bookings', 'Meeting requests from the website (nothing is confirmed to them until you accept), and the meetings on the sales@kiddietrac.com Outlook calendar.', '📅'));
    var want = (/[?&]id=(\d+)/.exec(location.hash) || [])[1];
    var state = { view: 'pending', data: null, seq: 0 };
    var top = el('div', { style: 'display:flex;gap:8px;flex-wrap:wrap;align-items:center;margin-bottom:12px' });
    var list = el('div', { style: 'display:grid;grid-template-columns:repeat(auto-fill,minmax(min(100%,460px),1fr));gap:12px;align-items:start' });
    var setBox = el('div');
    // The whole window; cards flow into columns on a wide screen (Anthony, 2026-10-23).
    container.appendChild(el('div', { style: 'width:100%' }, [top, setBox, list]));

    function tabs() {
      clear(top);
      var c = (state.data && state.data.counts) || {};
      /* THE VIEWS ARE THEIR OWN TAB STRIP (2026-11-24, Anthony: sub-tabs in Sales should scroll like Audit log's - full
         sweep). They shared one wrapping row with "New meeting", "Booking settings" and a spacer, so kt-tab-band (rightly)
         did not read the row as a tab strip and on a phone the four views stacked into four rows. Now the views are a
         tablist of their own (the standard band: one row, arrows, scrolls) and the actions sit beside it. */
      var strip = el('div', { role: 'tablist', 'aria-label': 'Bookings', style: 'display:flex;gap:6px;flex:1 1 auto;min-width:0;max-width:100%' });
      top.appendChild(strip);
      [['pending', 'Needs a decision' + (c.pending ? ' (' + c.pending + ')' : '')], ['upcoming', 'Upcoming' + (c.upcoming ? ' (' + c.upcoming + ')' : '')], ['past', 'Past & declined'], ['all', 'All']].forEach(function (t) {
        strip.appendChild(el('button', { type: 'button', role: 'tab', 'aria-selected': state.view === t[0] ? 'true' : 'false', 'data-tab': t[0], class: state.view === t[0] ? 'on' : '',
          style: 'height:32px;padding:0 12px;border-radius:999px;border:1px solid ' + (state.view === t[0] ? ACCENT : '#CBD5E1') + ';background:' + (state.view === t[0] ? ACCENT : '#fff') + ';color:' + (state.view === t[0] ? '#fff' : '#334155') + ';font-weight:700;font-size:13px;cursor:pointer',
          onclick: function () { state.view = t[0]; load(); } }, [t[1]]));
      });
      // A meeting of your own from Bookings, with Teams and invitations (2026-10-25).
      top.appendChild(btn('🤝 New meeting', 'primary', function () { if (KT.Meeting) KT.Meeting.compose({ scope: 'platform' }); }));
      top.appendChild(btn('⚙ Booking settings', 'ghost', function () { settings(); }));
    }
    function settings() {
      if (setBox.firstChild) { clear(setBox); return; }
      var d = state.data || {};
      var inp = el('input', { type: 'url', value: d.default_link || '', placeholder: 'https://teams.microsoft.com/l/meetup-join/…', style: 'width:100%;height:36px;border:1px solid #CBD5E1;border-radius:8px;padding:0 10px;font-size:13.5px;box-sizing:border-box' });
      var msg = el('span', { style: 'font-size:12.5px;margin-left:8px' });
      setBox.appendChild(card([
        el('div', { style: 'font-weight:800;font-size:15px;margin-bottom:6px' }, ['Teams links']),
        el('div', { style: 'font-size:13px;color:#475569;line-height:1.55;margin-bottom:10px', html: d.teams_auto
          ? '✅ A new Teams meeting is created on the <b>sales@kiddietrac.com</b> calendar for each accepted video meeting. The link below is only used if that fails.'
          : '⚠️ KiddieTrac cannot create Teams meetings yet: the Microsoft 365 app needs the <b>Calendars.ReadWrite</b> application permission (admin consent). Until then, paste a link when you accept, or save a standing Teams meeting link here and it is used for every meeting.' }),
        el('label', { style: 'font-size:12.5px;font-weight:700;color:#475569' }, ['Default Teams link']), inp,
        el('div', { style: 'margin-top:10px;display:flex;align-items:center' }, [btn('Save', 'primary', function () {
          msg.style.color = '#64748B'; msg.textContent = 'Saving…';
          Api.put('/sales/bookings/settings', { default_link: inp.value.trim() || null }).then(function (r) { d.default_link = r.default_link; msg.style.color = '#15803D'; msg.textContent = '✓ Saved'; })
            .catch(function (e) { msg.style.color = '#B91C1C'; msg.textContent = e.message; });
        }), msg]),
      ], 'margin-bottom:12px'));
    }
    function field(label, node) { return el('div', { style: 'flex:1 1 160px;min-width:0' }, [el('div', { style: 'font-size:11.5px;font-weight:700;color:#64748B;margin-bottom:3px' }, [label]), node]); }
    function inputStyle() { return 'width:100%;height:34px;border:1px solid #CBD5E1;border-radius:8px;padding:0 9px;font-size:13.5px;box-sizing:border-box;background:#fff'; }
    function panel(b, mode, host) {
      clear(host);
      var d = state.data || {};
      // A note on EVERY decision - confirm, change, decline, cancel (Anthony, 2026-10-23).
      var note = el('textarea', { rows: '3', placeholder: 'A personal note in the email to them (optional) — signed with your first name', style: 'width:100%;border:1px solid #CBD5E1;border-radius:8px;padding:8px 10px;font-size:13.5px;box-sizing:border-box;font-family:inherit' });
      var reason = mode === 'decline' ? el('input', { type: 'text', placeholder: 'Reason (optional, one line) — e.g. our team is fully booked that afternoon', style: inputStyle() }) : null;
      var msg = el('div', { style: 'font-size:12.5px;margin-top:6px' });
      var rows = [];
      var link = null, date = null, time = null, type = null, dur = null, notify = null, invitees = null;
      if (mode !== 'decline' && b.meet_via !== 'phone') {
        link = el('input', { type: 'url', value: b.teams_url || '', placeholder: d.teams_auto ? 'Leave blank: a Teams meeting is created automatically' : (d.default_link ? 'Leave blank to use the default Teams link' : 'Paste the Teams meeting link'), style: inputStyle() });
        rows.push(field('Teams link', link));
        // Others on the invite (Anthony, 2026-10-23): each gets an Outlook invite from sales@.
        invitees = el('input', { type: 'text', value: (b.invitees || []).join(', '), placeholder: 'Also invite (optional) — emails separated by commas', style: inputStyle() });
        rows.push(field('Also invite', invitees));
      }
      if (mode === 'modify') {
        date = el('input', { type: 'date', value: b.local_date, style: inputStyle() });
        time = el('input', { type: 'time', value: b.local_time, step: '300', style: inputStyle() });
        type = el('select', { style: inputStyle() }, (d.types || []).map(function (t) { var o = el('option', { value: t.id }, [t.name + ' (' + t.min + ' min)']); if (t.id === b.type) o.selected = true; return o; }));
        dur = el('input', { type: 'number', min: '15', max: '240', step: '5', value: String(b.duration_min), style: inputStyle() });
        type.onchange = function () { var t = (d.types || []).filter(function (x) { return x.id === type.value; })[0]; if (t) dur.value = String(t.min); };
        rows.unshift(field('Date (Eastern)', date), field('Time (Eastern)', time), field('Meeting', type), field('Minutes', dur));
      }
      if (mode === 'decline') {
        rows.push(field('Reason', reason));
        notify = el('input', { type: 'checkbox', checked: 'checked' });
        rows.push(el('label', { style: 'display:flex;gap:6px;align-items:center;font-size:13px;flex:1 1 100%' }, [notify, 'Email them (recommended)']));
      }
      var go2 = btn(mode === 'accept' ? '✓ Accept & send confirmation' : (mode === 'modify' ? '✓ Confirm the new time' : (b.status === 'booked' ? 'Cancel the meeting' : 'Decline the request')), mode === 'decline' ? 'danger' : 'primary', function () {
        var body = {}, path = '/sales/bookings/' + b.id + '/' + mode;
        if (link && link.value.trim()) body.teams_url = link.value.trim();
        if (invitees) body.invitees = invitees.value.trim();
        body.note = note.value.trim() || null;
        if (mode === 'decline') { body.reason = reason.value.trim() || null; body.notify = notify.checked; }
        if (mode === 'modify') { body.date = date.value; body.time = time.value; body.type = type.value; body.duration_min = Number(dur.value) || null; }
        go2.disabled = true; msg.style.color = '#64748B'; msg.textContent = 'Working…';
        Api.post(path, body).then(function (r) {
          toast(r.mail === false ? '⚠️' : '✅', mode === 'decline' ? (b.status === 'booked' ? 'Meeting cancelled' : 'Request declined') : 'Meeting confirmed',
            r.mail === false ? 'Saved, but the email to ' + b.email + ' failed — check the email log.' : (r.mail === null ? 'No email sent.' : 'Email sent to ' + b.email + '.'), r.mail === false ? '#B45309' : undefined);
          load();
        }).catch(function (e) { go2.disabled = false; msg.style.color = '#B91C1C'; msg.textContent = e.message; });
      });
      host.appendChild(el('div', { style: 'margin-top:12px;padding-top:12px;border-top:1px dashed #E2E8F0' }, [
        el('div', { style: 'display:flex;gap:10px;flex-wrap:wrap' }, rows), el('div', { style: 'margin-top:8px' }, [note]),
        el('div', { style: 'display:flex;gap:8px;margin-top:10px;align-items:center;flex-wrap:wrap' }, [go2, btn('Close', 'ghost', function () { clear(host); })]), msg]));
    }
    function cardFor(b) {
      var st = BK_STATUS[b.status] || [b.status, '#475569', '#F1F5F9'];
      var host = el('div');
      var acts = el('div', { style: 'display:flex;gap:6px;flex-wrap:wrap;margin-top:10px' });
      if (b.status === 'requested') {
        acts.appendChild(btn('✓ Accept', 'primary', function () { panel(b, 'accept', host); }));
        acts.appendChild(btn('✎ Modify', 'ghost', function () { panel(b, 'modify', host); }));
        acts.appendChild(btn('✕ Decline', 'ghost', function () { panel(b, 'decline', host); }));
      } else if (b.status === 'booked' && new Date(b.starts_at) > new Date()) {
        acts.appendChild(btn('✎ Change time', 'ghost', function () { panel(b, 'modify', host); }));
        acts.appendChild(btn('✕ Cancel meeting', 'ghost', function () { panel(b, 'decline', host); }));
        if (b.teams_url) acts.appendChild(el('a', { href: b.teams_url, target: '_blank', rel: 'noopener', style: 'align-self:center;font-size:13px;font-weight:700;color:' + ACCENT }, ['Open Teams link ↗']));
      }
      if (b.sales_lead_id) acts.appendChild(el('a', { href: '#sales-lead?id=' + b.sales_lead_id, style: 'align-self:center;font-size:13px;font-weight:700;color:#334155;margin-left:auto' }, ['Lead →']));
      var c = card([
        el('div', { style: 'display:flex;justify-content:space-between;gap:12px;flex-wrap:wrap' }, [
          el('div', { style: 'min-width:0' }, [
            el('div', { style: 'font-weight:800;font-size:16px;color:#0F172A' }, [b.name + (b.agency ? ' · ' + b.agency : '')]),
            el('div', { style: 'font-size:13px;color:#475569;margin-top:3px' }, [b.type_name + ' · ' + b.duration_min + ' min · ' + (b.meet_via === 'phone' ? '📞 phone call' : '🎥 Teams')]),
          ]),
          el('span', { style: 'align-self:flex-start;white-space:nowrap;font-size:11.5px;font-weight:800;border-radius:999px;padding:3px 10px;color:' + st[1] + ';background:' + st[2] }, [st[0]]),
        ]),
        el('div', { style: 'margin-top:10px;font-size:14.5px;font-weight:700;color:#0F172A' }, ['🗓 ' + b.when_et]),
        b.when_local ? el('div', { style: 'font-size:12.5px;color:#64748B' }, [b.when_local]) : null,
        b.requested_et ? el('div', { style: 'font-size:12.5px;color:#64748B' }, ['They asked for ' + b.requested_et]) : null,
        el('div', { style: 'font-size:13px;color:#334155;margin-top:8px;line-height:1.6' }, [
          el('a', { href: 'mailto:' + b.email, style: 'color:' + ACCENT }, [b.email]), b.phone ? '  ·  ' + b.phone : '', b.children ? '  ·  ' + b.children + ' children' : '']),
        b.notes ? el('div', { style: 'margin-top:8px;font-size:13px;color:#475569;background:#F8FAFC;border-radius:8px;padding:8px 10px;white-space:pre-wrap' }, ['“' + b.notes + '”']) : null,
        b.decline_reason ? el('div', { style: 'margin-top:6px;font-size:12.5px;color:#64748B' }, ['Reason given: ' + b.decline_reason]) : null,
        /* THEIR INTAKE FORM (2026-10-23): what they told us about their program. */
        b.intake ? (function () {
          var det = el('details', { style: 'margin-top:10px;border:1px solid #D1FAE5;background:#F0FDF4;border-radius:10px;padding:8px 12px' });
          det.appendChild(el('summary', { style: 'cursor:pointer;font-weight:800;font-size:13px;color:#047857' }, ['📋 Intake form received — view their answers']));
          var t = el('table', { style: 'margin-top:8px;border-collapse:collapse;font-size:13px;width:100%' });
          b.intake.rows.forEach(function (r) { t.appendChild(el('tr', {}, [el('td', { style: 'padding:4px 12px 4px 0;color:#64748B;vertical-align:top;white-space:nowrap' }, [r[0]]), el('td', { style: 'padding:4px 0;color:#0F172A;font-weight:600;white-space:pre-wrap' }, [String(r[1])])])); });
          det.appendChild(t); return det;
        })() : el('div', { style: 'margin-top:8px;font-size:12px;color:#94A3B8' }, ['📋 Intake form not filled in yet · ', el('a', { href: b.intake_url, target: '_blank', rel: 'noopener', style: 'color:' + ACCENT + ';font-weight:700' }, ['open their form ↗'])]),
        b.host && b.status !== 'requested' ? el('div', { style: 'margin-top:6px;font-size:12px;color:#94A3B8' }, [(b.status === 'booked' ? 'Confirmed by ' : 'Decided by ') + b.host + (b.confirm_mail === 'failed' ? ' · ⚠️ confirmation email failed' : '')]) : null,
        acts, host,
      ], b.status === 'requested' ? '--kt-edge:#F59E0B' : '');
      if (want && String(b.id) === String(want)) { c.style.boxShadow = '0 0 0 3px rgba(4,120,87,.35)'; setTimeout(function () { try { c.scrollIntoView({ block: 'center' }); } catch (e) {} }, 100); }
      return c;
    }
    /* OUTLOOK MEETINGS (2026-11-04, Anthony: "bookings section should also show meetings booked through outlook with
       sales@kiddietrac.com"). Everything else on the sales@ calendar, under the website cards: read-only, changed in Outlook. */
    function sectionHead(text, sub) {
      return el('div', { style: 'grid-column:1/-1;margin-top:6px' }, [el('div', { style: 'font-weight:800;font-size:14px;color:#0F172A' }, [text]),
        sub ? el('div', { style: 'font-size:12.5px;color:#64748B;margin-top:2px' }, [sub]) : null]);
    }
    function outlookCard(m) {
      var pill = m.cancelled ? ['Cancelled', '#64748B', '#F1F5F9'] : (m.ended ? ['Done', '#475569', '#F1F5F9'] : ['Outlook', '#1D4ED8', '#DBEAFE']);
      var acts = el('div', { style: 'display:flex;gap:10px;flex-wrap:wrap;margin-top:10px;align-items:center' });
      if (m.join_url && !m.ended && !m.cancelled) acts.appendChild(el('a', { href: m.join_url, target: '_blank', rel: 'noopener', style: 'font-size:13px;font-weight:700;color:' + ACCENT }, ['Join Teams ↗']));
      if (m.web_link) acts.appendChild(el('a', { href: m.web_link, target: '_blank', rel: 'noopener', style: 'font-size:13px;font-weight:700;color:#1D4ED8' }, ['Open in Outlook ↗']));
      if (m.lead) acts.appendChild(el('a', { href: '#sales-lead?id=' + m.lead.id, style: 'font-size:13px;font-weight:700;color:#334155;margin-left:auto' }, ['Lead' + (m.lead.name ? ': ' + m.lead.name : '') + ' →']));
      var people = (m.attendees || []).map(function (a, i) {
        return el('span', {}, [i ? '  ·  ' : '', el('a', { href: 'mailto:' + a.email, style: 'color:' + ACCENT }, [a.name && a.name.toLowerCase() !== a.email ? a.name : a.email])]);
      });
      return card([
        el('div', { style: 'display:flex;justify-content:space-between;gap:12px;flex-wrap:wrap' }, [
          el('div', { style: 'min-width:0' }, [
            el('div', { style: 'font-weight:800;font-size:16px;color:#0F172A' }, [m.subject]),
            el('div', { style: 'font-size:13px;color:#475569;margin-top:3px' }, [m.duration_min + ' min · ' + (m.join_url ? '🎥 Teams' : (m.location ? '📍 ' + m.location : '📆 Outlook')) + (m.organizer ? ' · set up by ' + m.organizer : '')]),
          ]),
          el('span', { style: 'align-self:flex-start;white-space:nowrap;font-size:11.5px;font-weight:800;border-radius:999px;padding:3px 10px;color:' + pill[1] + ';background:' + pill[2] }, [pill[0]]),
        ]),
        el('div', { style: 'margin-top:10px;font-size:14.5px;font-weight:700;color:#0F172A' }, ['🗓 ' + m.when_et]),
        people.length ? el('div', { style: 'font-size:13px;color:#334155;margin-top:8px;line-height:1.6' }, people) : el('div', { style: 'font-size:12.5px;color:#94A3B8;margin-top:8px' }, ['No one else invited']),
        acts,
      ], '--kt-edge:#3B82F6');
    }
    function loadOutlook(seq) {
      if (state.view === 'pending') return;
      var box = el('div', { style: 'grid-column:1/-1;color:#64748B;font-size:13px;padding:6px 2px' }, ['Loading the sales@ Outlook calendar…']);
      list.appendChild(box);
      Api.get('/sales/bookings/calendar?view=' + state.view).then(function (d) {
        if (seq !== state.seq) return;
        box.remove();
        var ms = d.meetings || [];
        list.appendChild(sectionHead('📆 Outlook meetings on ' + (d.mailbox || 'sales@kiddietrac.com') + (ms.length ? ' (' + ms.length + ')' : ''),
          d.readable === false ? '⚠️ The Outlook calendar could not be read just now. Try again in a minute.' : (ms.length ? 'Booked in Outlook. Change or cancel them there.' : 'None ' + (state.view === 'past' ? 'in the last 60 days.' : 'in the next 60 days.'))));
        ms.forEach(function (m) { list.appendChild(outlookCard(m)); });
      }).catch(function (e) { if (seq !== state.seq) return; box.style.color = '#B91C1C'; box.textContent = e.message; });
    }
    function load() {
      var seq = ++state.seq;
      clear(list); list.appendChild(el('div', { style: 'color:#64748B;padding:20px;text-align:center' }, ['Loading…']));
      Api.get('/sales/bookings?view=' + state.view).then(function (d) {
        if (seq !== state.seq) return;
        state.data = d; tabs(); clear(list);
        if (want && state.view === 'pending' && !(d.bookings || []).some(function (b) { return String(b.id) === String(want); })) { state.view = 'all'; return load(); }
        if (state.view !== 'pending') list.appendChild(sectionHead('🌐 Website bookings' + ((d.bookings || []).length ? ' (' + d.bookings.length + ')' : '')));
        if (!(d.bookings || []).length) list.appendChild(card([el('div', { style: 'text-align:center;color:#64748B;padding:18px' }, [state.view === 'pending' ? '🎉 No requests waiting.' : 'Nothing here.'])]));
        (d.bookings || []).forEach(function (b) { list.appendChild(cardFor(b)); });
        loadOutlook(seq);
      }).catch(function (e) { if (seq !== state.seq) return; clear(list); list.appendChild(card([el('div', { style: 'color:#B91C1C' }, [e.message])])); });
    }
    tabs(); load();
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
    /* SUB-TABS (2026-11-09, Anthony: "make subtabs for each section such as add-ons, one-time services, discounts and
       proposal terms etc to clean things up"). One section at a time instead of one long page. The tab is remembered for
       the session and can be linked: #sales-plans?tab=plan|addon|service|discount|terms. */
    var TABS = [['plan', '📦 Plans', 'The customer’s plan sets which features they get.'], ['addon', '🧩 Add-ons', 'Sold on top of a plan; each switches on the features you tick.'],
      ['service', '🛠️ One-time services', 'Setup, training, data import — billed once, no licence.'], ['discount', '🏷️ Discounts', ''], ['terms', '📄 Proposal terms', '']];
    var tab = (function () {
      var q = (location.hash.split('?')[1] || '').match(/(?:^|&)tab=([a-z]+)/), t = q ? q[1] : '';
      if (!t) { try { t = sessionStorage.getItem('kt_sales_plans_tab') || ''; } catch (e) {} }
      return TABS.some(function (x) { return x[0] === t; }) ? t : 'plan';
    })();
    var bar = el('div', { role: 'tablist', 'aria-label': 'Plans & pricing sections', style: 'display:flex;gap:6px;flex-wrap:wrap;margin:0 0 14px' });
    container.insertBefore(bar, host);
    var products = null;
    function drawBar() {
      clear(bar);
      TABS.forEach(function (t) {
        var n = products && t[0] !== 'terms' ? products.filter(function (p) { return (p.kind || 'plan') === t[0]; }).length : null;
        bar.appendChild(el('button', { type: 'button', role: 'tab', 'data-tab': t[0], 'aria-selected': tab === t[0] ? 'true' : 'false', class: tab === t[0] ? 'active' : '',
          style: 'height:32px;padding:0 12px;border-radius:999px;border:1px solid #CBD5E1;background:#fff;color:#334155;font-weight:700;font-size:13px;cursor:pointer',
          onclick: function () { if (tab === t[0]) return; tab = t[0]; try { sessionStorage.setItem('kt_sales_plans_tab', tab); } catch (e) {} drawBar(); draw(); } },
          [t[1] + (n ? ' (' + n + ')' : '')]));
      });
    }
    async function load() {
      products = await Api.get('/sales/products');
      drawBar();
      await draw();
    }
    async function draw() {
      var want = tab;
      clear(host);
      if (want === 'terms') {
        var dflCard = await proposalDefaultsCard();
        if (tab === want) host.appendChild(dflCard);   // a quicker click to another tab wins
        return;
      }
      var g = TABS.filter(function (t) { return t[0] === want; })[0];
      /* PLANS, ADD-ONS AND SERVICES, EACH WITH A PART NUMBER (2026-10-24, licensing). A plan sets the
         customer's feature level; an add-on switches features on; a service is one-time work.
         Part numbers are given by the server when an item is first saved and never change. */
      host.appendChild(el('div', { style: 'margin:0 0 12px;font-size:13px;color:#64748B;max-width:900px' }, [
        want === 'discount' ? 'Add one to a proposal from the same “Add preset” menu. It shows as its own line with a minus, and a percentage updates as you change the other lines.'
          : g[2] + ' Each item gets a part number when it is first saved (KT-PLN plans, KT-ADD add-ons, KT-SVC services, KT-DSC discounts); part numbers show on proposals, licences and invoices, and are what sales are reported by.']));
      var list = products.filter(function (p) { return (p.kind || 'plan') === want; });
      var grid = el('div', { style: 'display:grid;grid-template-columns:repeat(auto-fill,minmax(340px,1fr));gap:14px;align-items:start' });
      list.forEach(function (p) { grid.appendChild(want === 'discount' ? discountCard(p, load) : planCard(p, load)); });
      if (!list.length) grid.appendChild(el('div', { style: 'color:#94A3B8;font-size:13px' }, [want === 'discount' ? 'No discounts yet.' : 'None yet.']));
      var label = { plan: 'plan', addon: 'add-on', service: 'service', discount: 'discount' }[want];
      host.appendChild(el('div', { style: 'margin:0 0 12px' }, [btn('➕ Add ' + label, 'primary', function () {
        if (!grid.querySelector('input')) clear(grid);
        grid.insertBefore(want === 'discount'
          ? discountCard({ name: '', description: '', price: 10, kind: 'discount', discount_type: 'percent', applies_to: 'all', active: true }, load, true)
          : planCard({ name: '', description: '', price: 0, unit: want === 'service' ? 'one-time' : 'month', kind: want, active: true }, load, true), grid.firstChild);
      })]));
      host.appendChild(grid);
    }
    // Terms, valid-for days and taxes every NEW proposal starts with (each proposal keeps its own copy).
    async function proposalDefaultsCard() {
      var dfl = {}; try { dfl = await Api.get('/sales/proposal-defaults'); } catch (e) {}
      var days = input({ type: 'number', min: '1', max: '730', step: '1', value: dfl.valid_days || 90, style: 'width:110px;' + FLD });
      var terms = textarea({ value: dfl.terms || '', style: 'width:100%;min-height:150px;resize:vertical;' + FLD });
      terms.style.setProperty('min-height', '150px', 'important');
      var conds = textarea({ value: dfl.conditions || '', style: 'width:100%;resize:vertical;line-height:1.45;' + FLD });
      conds.style.setProperty('min-height', '320px', 'important');
      var sTitle = input({ value: dfl.sales_title || '', placeholder: 'KiddieTrac Sales', style: 'width:100%;' + FLD });
      var sEmail = input({ type: 'email', value: dfl.sales_email || '', placeholder: 'sales@kiddietrac.com', style: 'width:100%;' + FLD });
      var sPhone = input({ type: 'tel', value: dfl.sales_phone || '', placeholder: '1-855-400-9996', style: 'width:100%;' + FLD });
      var taxes = (dfl.taxes || []).map(function (t) { return { label: t.label, rate: t.rate }; });
      var taxHost = el('div', { style: 'display:flex;flex-direction:column;gap:6px' });
      function drawTaxes() {
        clear(taxHost);
        taxes.forEach(function (t, idx) {
          var l = input({ value: t.label || '', placeholder: 'Tax name (e.g. HST)', style: 'flex:1;min-width:0;' + FLD });
          var r = input({ type: 'number', step: '0.001', min: '0', max: '100', value: t.rate == null ? '' : t.rate, placeholder: '%', style: 'width:90px;flex:0 0 90px;' + FLD });
          l.addEventListener('input', function () { t.label = l.value; }); r.addEventListener('input', function () { t.rate = r.value; });
          taxHost.appendChild(el('div', { style: 'display:flex;gap:6px;align-items:center;max-width:460px' }, [l, r, el('span', { style: 'font-size:13px;color:#64748B' }, ['%']), el('button', { type: 'button', 'aria-label': 'Remove tax', style: 'border:0;background:#FEE2E2;color:#B91C1C;border-radius:8px;padding:6px 9px;cursor:pointer;flex:0 0 auto', onclick: function () { taxes.splice(idx, 1); drawTaxes(); } }, ['×'])]));
        });
        if (!taxes.length) taxHost.appendChild(el('div', { style: 'font-size:12.5px;color:#94A3B8' }, ['None — add one if most proposals carry the same tax.']));
      }
      drawTaxes();
      // Chance of closing per stage: the weighted forecast on the Pipeline uses these.
      var odds = dfl.stage_odds || {};
      var oddsIn = {};
      var oddsGrid = el('div', { style: 'display:grid;grid-template-columns:repeat(auto-fill,minmax(150px,1fr));gap:8px;margin-bottom:14px' },
        STAGES.map(function (st) {
          oddsIn[st.key] = input({ type: 'number', min: '0', max: '100', step: '1', value: odds[st.key] == null ? '' : odds[st.key], style: 'width:84px;flex:0 0 84px;' + FLD });
          return el('label', { style: 'display:flex;align-items:center;gap:6px;font-size:13px;color:#334155' }, [
            el('span', { style: 'flex:1;min-width:0;font-weight:700;color:' + (st.color || '#334155') }, [st.label]), oddsIn[st.key], '%']);
        }));
      // data-kt-nogrid: this card lays out its own rows ("valid for [90] days" is a sentence, not a stacked field).
      return el('div', { 'data-kt-nogrid': '1' }, [
        el('div', { style: 'margin:0 0 12px;font-size:13px;color:#64748B' }, ['Every new proposal starts with these. Each proposal can then change its own copy.']),
        card([
          el('div', { style: 'display:flex;align-items:center;gap:8px;flex-wrap:wrap;margin-bottom:12px' }, [el('span', { style: 'font-size:13px;font-weight:700;color:#334155' }, ['Proposals are valid for']), days, el('span', { style: 'font-size:13px;font-weight:700;color:#334155' }, ['days'])]),
          el('div', { style: 'font-size:12px;font-weight:700;color:#5a7080;margin-bottom:6px' }, ['"Prepared by" contact (shown under the rep\'s name; replies to the proposal email go here)']),
          el('div', { style: 'display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:10px;margin-bottom:14px' }, [field('Title / team', sTitle), field('Sales email', sEmail), field('Sales phone', sPhone)]),
          field('Proposal terms (short, under the prices)', terms),
          el('div', { style: 'font-size:12px;color:#64748B;margin:4px 0 14px' }, ['{days} is replaced with the number of days above.']),
          field('Terms & conditions (their own page, before the signatures)', conds),
          el('div', { style: 'font-size:12px;color:#64748B;margin:4px 0 14px' }, ['Start a line with ## for a heading, or - for a bullet. Proposals already made keep the wording they were created with.']),
          el('div', { style: 'display:flex;justify-content:space-between;align-items:center;margin-bottom:6px;max-width:460px' }, [el('div', { style: 'font-size:12px;font-weight:700;color:#5a7080' }, ['Default taxes']), btn('+ Add tax', 'light', function () { if (taxes.length >= 5) return; taxes.push({ label: '', rate: '' }); drawTaxes(); })]),
          taxHost,
          el('div', { style: 'font-size:12px;font-weight:700;color:#5a7080;margin:16px 0 4px' }, ['Chance of closing by stage']),
          el('div', { style: 'font-size:12px;color:#64748B;margin-bottom:8px' }, ['The Pipeline\'s weighted forecast multiplies each lead\'s estimated annual revenue by its stage\'s chance.']),
          oddsGrid,
          el('div', { style: 'display:flex;justify-content:flex-end;margin-top:14px' }, [btn('Save', 'primary', async function (ev) {
            ev.target.disabled = true;
            try {
              await Api.put('/sales/proposal-defaults', { valid_days: Number(days.value || 90), terms: terms.value, conditions: conds.value, sales_title: sTitle.value.trim(), sales_email: sEmail.value.trim(), sales_phone: sPhone.value.trim(), stage_odds: (function () { var o = {}; STAGES.forEach(function (st) { var v = oddsIn[st.key].value; if (v !== '') o[st.key] = Number(v); }); return o; })(), taxes: taxes.filter(function (t) { return String(t.label || '').trim(); }).map(function (t) { return { label: String(t.label).trim(), rate: Number(t.rate || 0) }; }) });
              toast('✅', 'Proposal defaults saved');
            } catch (er) { toast('⚠️', 'Failed', er.message || '', '#DC2626'); }
            ev.target.disabled = false;
          })]),
        ], 'max-width:900px'),
      ]);
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
    // Features an add-on can switch on (keys from FeatureFlagController::FEATURES; reseller is the reseller account).
    var ADDON_FEATURES = [['white_label', 'White-label'], ['sms', 'Texting (SMS)'], ['reseller', 'Reseller account'], ['payroll', 'Payroll'], ['autopay', 'Autopay'],
      ['expenses', 'Expenses'], ['accounting_sync', 'Accounting sync'], ['marketing', 'Marketing'], ['analytics', 'Analytics'], ['ai_tools', 'AI tools'],
      ['data_retention', 'Data retention'], ['multi_centre', 'Multi-centre'], ['mrr_dashboard', 'Revenue dashboard'], ['sales_crm', 'Sales CRM']];
    function partBadge(pn) {
      return pn ? el('span', { title: 'Part number', style: 'display:inline-block;font-size:11px;font-weight:800;letter-spacing:.3px;color:#1F6080;background:#EAF3F6;border:1px solid #CFE3EB;border-radius:6px;padding:2px 7px;font-family:ui-monospace,Menlo,Consolas,monospace' }, [pn])
        : el('span', { style: 'font-size:11.5px;color:#94A3B8' }, ['Part # given when saved']);
    }
    function planCard(p, reload, isNew) {
      var kind = p.kind === 'addon' || p.kind === 'service' ? p.kind : 'plan';
      var n = input({ value: p.name || '', placeholder: kind === 'addon' ? 'Add-on name' : kind === 'service' ? 'Service name' : 'Plan name', style: 'flex:1 1 auto;min-width:0;font-weight:700;' + FLD });
      var pr = input({ type: 'number', step: '0.01', value: p.price || 0, style: 'flex:0 0 110px;width:110px;' + FLD });
      var u = selectEl(kind === 'service' ? [{ v: 'one-time', l: 'one-time' }] : [{ v: 'month', l: '/month' }, { v: 'year', l: '/year' }], kind === 'service' ? 'one-time' : (p.unit === 'year' ? 'year' : 'month'), { style: 'flex:0 0 118px;width:118px;' + FLD });
      var d = textarea({ value: p.description || '', placeholder: 'Description — shown on proposals. Full text is always visible here.', style: 'width:100%;padding:9px 11px;border:1px solid var(--kt-border,#d9e1ea);border-radius:9px;font-size:14px;box-sizing:border-box;min-height:70px;resize:vertical' });
      var lvl = kind === 'plan' ? selectEl([{ v: '', l: 'Choose a feature level…' }, { v: 'starter', l: 'Starter features' }, { v: 'growth', l: 'Growth features' }, { v: 'enterprise', l: 'Agency (all features)' }], p.plan_code || '', { style: 'width:100%;' + FLD }) : null;
      var feats = {}; (function () { var f = p.feature_keys; if (typeof f === 'string') { try { f = JSON.parse(f); } catch (e) { f = []; } } (f || []).forEach(function (k) { feats[k] = true; }); })();
      var featBox = kind === 'addon' ? el('div', { 'data-kt-noband': '1', style: 'display:flex;flex-wrap:wrap;gap:6px' }, ADDON_FEATURES.map(function (f) {
        var b = el('button', { type: 'button', 'data-kt-iconized': '1', style: 'border:1.5px solid #E2E8F0;background:#fff;color:#0F172A;border-radius:999px;padding:5px 11px;font-size:12.5px;font-weight:700;cursor:pointer;min-height:0' }, [f[1]]);
        var paint = function () { var on = !!feats[f[0]]; b.style.background = on ? '#ECFEFF' : '#fff'; b.style.borderColor = on ? '#0E7490' : '#E2E8F0'; b.style.color = on ? '#0E7490' : '#0F172A'; b.textContent = (on ? '✓ ' : '') + f[1]; };
        b.addEventListener('click', function () { feats[f[0]] = !feats[f[0]]; paint(); }); paint();
        return b;
      })) : null;
      /* Centres the item includes (App\Support\CentreCap, 2026-11-26): the Reseller bundle's 5; each "Reseller extra
         centre" line adds one. Blank = this item sets no centre cap. */
      var centresIn = kind === 'addon' || kind === 'plan' ? input({ type: 'number', min: '0', step: '1', value: p.included_centres == null ? '' : p.included_centres, placeholder: 'No cap', style: 'width:140px;' + FLD }) : null;
      return card([
        el('div', { style: 'display:flex;justify-content:space-between;align-items:center;margin-bottom:9px' }, [partBadge(p.part_number), p.active === false ? el('span', { style: 'font-size:11px;color:#B91C1C;font-weight:700' }, ['Inactive']) : el('span')]),
        el('div', { style: 'display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin-bottom:11px' }, [n, pr, u]),
        lvl ? field('Feature level', lvl) : null,
        featBox ? field('Switches on', featBox) : null,
        centresIn ? field('Centres included (blank = no centre cap)', centresIn) : null,
        field('Description', d),
        actions(p, reload, function () {
          if (!(n.value || '').trim()) { toast('⚠️', 'Give it a name', '', '#DC2626'); return null; }
          if (lvl && !lvl.value) { toast('⚠️', 'Choose the feature level this plan gives', '', '#DC2626'); return null; }
          return { name: n.value, description: d.value, price: pr.value || 0, unit: u.value, active: true, kind: kind,
            plan_code: lvl ? lvl.value : null, feature_keys: featBox ? Object.keys(feats).filter(function (k) { return feats[k]; }) : null,
            included_centres: centresIn && centresIn.value !== '' ? Math.max(0, parseInt(centresIn.value, 10) || 0) : null };
        }),
      ], 'padding:15px 16px;--kt-edge:' + (kind === 'addon' ? '#7C3AED' : kind === 'service' ? '#F59E0B' : '#1F6080'));
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
        el('div', { style: 'margin-bottom:9px' }, [partBadge(p.part_number)]),
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
      ], 'padding:15px 16px;--kt-edge:#10B981');
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
      statCard('💰', 'Pipeline value / yr', money(st.pipeline_value || 0), '#ECFDF5', '#047857'),
      statCard('⚖️', 'Weighted forecast / yr', money(st.weighted_pipeline || 0), '#F0F9FF', '#0369A1'),
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

    if (!document.getElementById('kt-lib-css')) {
      var lcss = document.createElement('style'); lcss.id = 'kt-lib-css';
      lcss.textContent = '#appMain .kt-lib-frow>button{min-height:0!important;min-width:0!important;height:18px!important;width:22px!important;padding:0!important;display:inline-flex!important;align-items:center;justify-content:center}'
        + '.kt-lib-tabs-host{display:none!important}'
        + '@media (max-width:760px){'
        + '.kt-lib-grid{grid-template-columns:minmax(0,1fr)!important;gap:10px!important}'
        + '.kt-lib-rail{display:none!important}'
        + '.kt-lib-tabs-host{display:block!important}'
        + '.kt-lib-tabs>button>.kt-lib-n{margin-left:5px;font-weight:600;color:#64748B;font-variant-numeric:tabular-nums}'
        + '.kt-lib-bar{gap:8px!important;flex-wrap:nowrap!important}'
        + '.kt-lib-inf{display:none}'
        + '.kt-lib-bar>button{padding:6px 10px!important}'
        + '}';
      document.head.appendChild(lcss);
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
      host.appendChild(el('div', { class: 'kt-lib-bar', style: 'display:flex;align-items:center;gap:10px;flex-wrap:wrap;margin-bottom:12px' }, [
        el('div', { style: 'font-weight:800;color:#0F172A;font-size:14px' },
          [shown.length + ' file' + (shown.length === 1 ? '' : 's'),
            state.folderId === null ? null : el('span', { class: 'kt-lib-inf' }, [' in this folder'])]),
        el('div', { style: 'flex:1' }, []),
        newFolder, addBtn,
      ]));

      // ── layout: folders | shelf ────────────────────────────────────────
      var grid = el('div', { class: 'kt-lib-grid', style: 'display:grid;grid-template-columns:minmax(0,200px) minmax(0,1fr);gap:16px;align-items:start' }, []);
      grid.appendChild(folderRail());
      grid.appendChild(folderTabs());

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
      var rail = el('div', { class: 'kt-lib-rail', style: 'background:#fff;border:1px solid #e6ebf1;border-radius:12px;padding:6px;min-width:0' }, []);

      function row(icon, label, count, active, onClick, onDelete) {
        var r = el('div', {
          class: 'kt-lib-frow' + (active ? ' kt-on' : ''),
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
          class: 'kt-lib-note',
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

    /* The folders as sub-tabs, for phones (CSS shows this or the rail). Deleting a folder is a tab's job on the rail;
       here a tab cannot hold a second button, so the open folder offers "Delete folder" under the bar. */
    function folderTabs() {
      var wrap = el('div', { style: 'min-width:0;display:flex;flex-direction:column;gap:8px' }, []);
      var bar = el('div', { role: 'tablist', 'aria-label': 'Folders', class: 'kt-lib-tabs', style: 'display:flex;gap:4px' }, []);
      function tab(label, count, active, go) {
        var b = el('button', { type: 'button', role: 'tab', 'aria-selected': active ? 'true' : 'false', 'data-kt-iconized': '1', 'data-kt-btn-free': '1' },
          [label, el('span', { class: 'kt-lib-n' }, [String(count)])]);
        b.addEventListener('click', go);
        return b;
      }
      bar.appendChild(tab('🗂 All files', state.docs.length, state.folderId === null, function () { state.folderId = null; paint(); }));
      state.folders.forEach(function (f) {
        bar.appendChild(tab('📁 ' + f.name, f.count, Number(state.folderId) === Number(f.id), function () { state.folderId = f.id; paint(); }));
      });
      wrap.appendChild(bar);
      var open = state.folders.filter(function (f) { return Number(state.folderId) === Number(f.id); })[0];
      if (open) {
        var del = el('button', { type: 'button', 'data-kt-iconized': '1', 'data-kt-btn-free': '1',
          style: 'border:0;background:transparent;color:#B91C1C;font-size:12.5px;font-weight:700;cursor:pointer;padding:2px 4px;min-height:0' },
          ['🗑 Delete the “' + open.name + '” folder']);
        del.addEventListener('click', function () { deleteFolder(open); });
        wrap.appendChild(el('div', { class: 'kt-lib-tabdel', style: 'display:flex;justify-content:flex-end;margin-top:-4px' }, [del]));
      }
      return el('div', { class: 'kt-lib-tabs-host', style: 'min-width:0' }, [wrap]);
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
      { hash: 'sales-bookings', icon: '📅', label: 'Bookings' }, { hash: 'sales-calendar', icon: '🗓', label: 'Calendar' }, { hash: 'sales-plans', icon: '💲', label: 'Plans & pricing' },
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
      '.kt-wc.kt-wc-docked{grid-template-columns:minmax(0,1fr)}.kt-wc.kt-wc-docked .kt-wc-thread{display:none}' +
      '@media (max-width:820px){.kt-wc{grid-template-columns:minmax(0,1fr)}.kt-wc.has-thread .kt-wc-list{display:none}.kt-wc:not(.has-thread) .kt-wc-thread{display:none}.kt-wc-back{display:inline-flex}.kt-wc-list,.kt-wc-thread{height:calc(100dvh - 250px);min-height:360px}.kt-wc-m{max-width:88%}}' +
      '.kt-wc-pop{position:fixed;right:18px;bottom:18px;z-index:2147480000;background:#fff;border:1px solid #E2E8F0;--kt-edge:#1F6080;border-radius:12px;box-shadow:0 10px 30px rgba(15,23,42,.18);padding:11px 14px;max-width:320px;cursor:pointer;font-size:13px;color:#0F172A}' +
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
  /* A new website chat / visitor line alerts like a regular chat message (2026-10-30): KT.sounds' 'chat' tone with
     the person's own vibration pattern and switches, de-duplicated across polls and tabs. The local two-note chime is
     only the fallback when kt-sounds.js is not there. */
  function wcAlert(w) {
    try {
      if (window.KT && KT.sounds && KT.sounds.play) { KT.sounds.play('chat', { key: 'webchat:' + w.id + ':' + w.latest }); return; }
    } catch (e) {}
    wcChime();
  }
  function wcPaintBadge() {
    var links = document.querySelectorAll('a.nav-link[data-hash="sales-webchat"], .kt-tile[href="#sales-webchat"]');
    Array.prototype.forEach.call(links, function (a) {
      if (a.classList.contains('kt-tile')) { if (KT.tileBadge) KT.tileBadge(a, WC.unread); return; }   // the one tile badge
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
    p.addEventListener('click', function () { p.remove(); if (window.KT && KT.webChats && KT.webChats.open) KT.webChats.open(w.id); else go('sales-webchat?id=' + w.id); });
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
        if (!WC.first && !onInbox) { wcPop(w); wcAlert(w); }
        else if (!WC.first && onInbox) { wcAlert(w); }
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
    function docks() { return !!(window.KT && KT.ChatDock && KT.ChatDock.enabled && KT.ChatDock.enabled() && KT.webChats && KT.webChats.openInDock); }
    if (docks()) grid.classList.add('kt-wc-docked');
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
        // Not when chats open in the dock: arriving on the screen must not pop a conversation open by itself.
        if (!state.openId && state.chats.length && window.innerWidth > 820 && !docks()) openChat(state.chats[0].id);
        if (!state.openId && !state.chats.length) paintEmptyThread();
      } catch (e) {
        clear(rows); rows.appendChild(el('div', { style: 'padding:16px;color:#DC2626' }, ['Could not load chats: ' + (e.message || e)]));
      }
    }
    function paintEmptyThread() {
      clear(thread);
      thread.appendChild(el('div', { style: 'margin:auto;text-align:center;color:#94A3B8;padding:30px;font-size:13.5px' }, ['Pick a conversation on the left.']));
    }

    /* ── the open conversation (2026-10-30, Anthony: "web site chat on mobile and desktop should be the popout style
       like we have it with the regular"): the chat dock on a computer, this screen's own pane on a phone - and in both,
       the one website-chat thread (KT.webChats.mountThread in kt-webchat-strip.js) that Message Centre uses too. ── */
    var handle = null;
    function openChat(id) {
      state.openId = id; state.threadFor = id;
      if (docks()) {
        KT.webChats.openInDock(id);
        try { history.replaceState(null, '', '#sales-webchat'); } catch (e) {}
        paintList();
        return;
      }
      grid.classList.add('has-thread');
      try { history.replaceState(null, '', '#sales-webchat?id=' + id); } catch (e) {}
      if (handle) handle.stop();
      clear(thread);
      // phone: the regular thread's full-screen presentation; Back (and Android's back) returns to this list.
      var leave = function () { handle = null; clear(thread); state.openId = 0; state.threadFor = 0; grid.classList.remove('has-thread'); try { history.replaceState(null, '', '#sales-webchat'); } catch (e) {} paintList(); };
      handle = KT.webChats.mountThread(thread, id, { onBack: leave, onChange: function () { loadList(); }, phone: true });
      try { if (window.KT && KT.pushOverlay && handle && handle.root) { var h0 = handle; KT.pushOverlay(h0.root, function () { h0.stop(); leave(); }); } } catch (e) {}
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

    // ── polling, stopped when the screen goes ──
    var tList = setInterval(function () { if (!alive()) return stop(); loadList(); }, 6000);
    function stop() {
      state.dead = true; clearInterval(tList); if (handle) { handle.stop(); handle = null; }
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
    KT.Shell.registerScreen(role + ':sales-bookings', guarded(renderBookings));
    KT.Shell.registerScreen(role + ':sales-calendar', guarded(renderSalesCalendar));
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
