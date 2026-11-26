/* ═══════════════════════════════════════════════════════════════════════
   Platform → AI usage (#ai-usage) — 2026-10-28

   Anthony: "configure [my Claude API key] in the portal where if i need to change it its accessible" and "wire up
   something in the platform view to see my claude usage and credits available etc - with full stats".

   The Claude connection (key — only its last 4 ever come back — and model; a change is tested before it is kept),
   the account's live state (last success / last error: "out of credit"), the estimated credit left (top-ups
   recorded here minus what KiddieTrac spent since: Anthropic does not give the balance to an API key), and every
   AI call KiddieTrac made: today / 7 days / 30 days / this month, a 30-day chart, by agency, feature and model.
   Server: App\Support\AiConfig, PlatformAiController. Platform admins only.

   BREAKDOWN SUB-TABS + BY USER (2026-11-26, Anthony: "for the AI usage report under the breakdown substab add another
   substab to clean up the data presented in that main screen and add by user as well to see which users are using AI").
   Breakdown is now its own date range, four KPI tiles and ONE table at a time in the standard band: By feature, By
   agency, By model, By user (/platform/ai/breakdown). Each is a plain #appMain table, so it gets the portal's search,
   sortable headers (numbers carry data-kt-sort keys), pager, CSV/Excel/PDF bar and phone cards. A person's row opens
   their recent calls (/platform/ai/users/{id}/calls): what, when, tokens and cost - no prompt or answer is ever kept.
   Every cost is priced per model on the server (App\Support\AiPricing): Haiku, Sonnet for pictures, Opus, Fable.
   ═══════════════════════════════════════════════════════════════════════ */
(function (window) {
  'use strict';
  var KT = window.KT;
  if (!KT || !KT.Shell || !KT.Shell.registerScreen) { return; }
  var Api = KT.Api;

  function esc(s) { return String(s == null ? '' : s).replace(/[&<>"]/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]; }); }
  function money(v, dp) { return '$' + (Number(v) || 0).toFixed(dp == null ? 2 : dp); }
  function num(v) { return (Number(v) || 0).toLocaleString('en-CA'); }
  function toks(v) { v = Number(v) || 0; return v >= 1e6 ? (v / 1e6).toFixed(2) + 'M' : v >= 1e3 ? (v / 1e3).toFixed(1) + 'k' : String(v); }
  function when(iso) { if (!iso) return '—'; try { return new Date(iso).toLocaleString('en-CA', { dateStyle: 'medium', timeStyle: 'short', timeZone: 'America/Toronto' }); } catch (e) { return iso; } }
  function toast(m, k) { try { if (KT.Dom && KT.Dom.toast) KT.Dom.toast(m, k || 'info'); } catch (e) {} }
  /* SUB-TABS (2026-11-04, Anthony: "AI usage section add subtabs to make things easier to read on that page"). One
     section at a time, in the standard band (role=tablist, so kt-tab-band.js gives phones the arrows). Every section
     is drawn and only hidden, so a Save that redraws the page comes back on the same tab. */
  var TABS = [['overview', '📊 Overview'], ['breakdown', '🧩 Breakdown'], ['credits', '💳 Credits'], ['connection', '🤖 Connection'], ['off', '🚫 Switched off']];
  var TAB = 'overview';
  try { TAB = sessionStorage.getItem('kt_aiu_tab') || TAB; } catch (e) {}
  var FIELD = 'height:32px;border:1px solid #CBD5E1;border-radius:8px;padding:0 10px;font-size:13px;box-sizing:border-box;';

  async function render(main) {
    main.setAttribute('data-kt-pretty', '1');
    main.innerHTML = '<div class="kt-aiu" style="padding:14px 24px;"><div id="aiu-body" style="color:var(--aiu-mute);">Loading…</div></div>';
    var body = main.querySelector('#aiu-body');
    try { draw(body, await Api.get('/platform/ai')); }
    catch (e) { body.innerHTML = '<div class="kt-card" style="padding:14px;color:#B91C1C;">' + esc((e && e.message) || 'Could not load') + '</div>'; }
  }

  /* ── Styles: tokens on .kt-aiu. The portal's dark theme (html.kt-dark / data-theme=dark) swaps them. ── */
  (function () {
    if (document.getElementById('kt-aiu-css')) return;
    var light = '--aiu-panel:#fff;--aiu-line:#E7EDF3;--aiu-soft:#F5F3FF;--aiu-ink:#0F172A;--aiu-mute:#64748B;--aiu-faint:#94A3B8;--aiu-pill:#EDE9FE;--aiu-pill-ink:#5B21B6;';
    var dark = '--aiu-panel:#111C2B;--aiu-line:#24344A;--aiu-soft:#1E2540;--aiu-ink:#E2E8F0;--aiu-mute:#94A3B8;--aiu-faint:#7C8DA6;--aiu-pill:#2E2458;--aiu-pill-ink:#DDD6FE;';
    var st = document.createElement('style'); st.id = 'kt-aiu-css';
    st.textContent = '.kt-aiu{' + light + '}html.kt-dark .kt-aiu,html[data-theme="dark"] .kt-aiu{' + dark + '}'
      + '.kt-aiu-kpis{display:grid;grid-template-columns:repeat(auto-fit,minmax(170px,1fr));gap:10px;margin:12px 0;}'
      + '.kt-aiu-kpi{background:var(--aiu-panel);border:1px solid var(--aiu-line);border-radius:14px;padding:10px 14px;min-width:0;}'
      + '.kt-aiu-kpi b{display:block;font-size:11px;font-weight:800;color:var(--aiu-mute);text-transform:uppercase;letter-spacing:.5px;}'
      + '.kt-aiu-kpi strong{display:block;font-size:21px;font-weight:900;color:var(--aiu-ink);margin-top:2px;}'
      + '.kt-aiu-kpi span{display:block;font-size:12px;color:var(--aiu-mute);}'
      + '.kt-aiu-range{display:flex;gap:8px;flex-wrap:wrap;align-items:flex-end;}'
      + '.kt-aiu-range label{font-size:12px;font-weight:700;color:var(--aiu-mute);display:flex;flex-direction:column;gap:3px;}'
      + '.kt-aiu-t{width:100%;border-collapse:collapse;font-size:12.5px;color:var(--aiu-ink);}'
      + '.kt-aiu-t td{vertical-align:top;}'
      + '.kt-aiu-t .r{text-align:right;white-space:nowrap;}'
      + '.kt-aiu-t tr[data-aiu-user]{cursor:pointer;}'
      + '.kt-aiu-t tr[data-aiu-user]:hover td{background:var(--aiu-soft);}'
      + '.kt-aiu-who{font-weight:700;color:var(--aiu-ink);text-decoration:none;}'
      + '.kt-aiu-who:hover,.kt-aiu-who:focus-visible{text-decoration:underline;}'
      + '.kt-aiu-src{font-family:ui-monospace,Menlo,Consolas,monospace;font-size:11px;color:var(--aiu-faint);word-break:break-all;}'
      + '.kt-aiu-sys{display:inline-block;border-radius:999px;padding:1px 8px;font-size:11px;font-weight:800;background:var(--aiu-pill);color:var(--aiu-pill-ink);}'
      + '.kt-aiu-note{font-size:11.5px;color:var(--aiu-faint);margin-top:10px;line-height:1.5;}'
      + '.kt-aiu-call{display:grid;grid-template-columns:minmax(0,1.6fr) minmax(0,1.1fr) auto auto;gap:3px 12px;padding:7px 2px;border-top:1px solid var(--aiu-line);align-items:baseline;color:var(--aiu-ink);font-size:12.5px;}'
      + '.kt-aiu-call .m{color:var(--aiu-mute);font-size:11.5px;}'
      + '@media (min-width:601px){.kt-aiu-feats{min-width:150px;}}'
      + '@media (max-width:600px){.kt-aiu-call{grid-template-columns:minmax(0,1fr) auto;}}';
    (document.head || document.documentElement).appendChild(st);
  })();

  /* ── Breakdown: range, KPI tiles, sub-tabs ── */
  var BD_TABS = [['feature', '🧩 By feature'], ['agency', '🏢 By agency'], ['model', '🧠 By model'], ['user', '👤 By user']];
  var BD = 'feature';
  try { BD = sessionStorage.getItem('kt_aiu_bd') || BD; } catch (e) {}
  if (!BD_TABS.some(function (x) { return x[0] === BD; })) BD = 'feature';
  var RANGES = [['7d', 'Last 7 days'], ['30d', 'Last 30 days'], ['month', 'This month'], ['lastmonth', 'Last month'], ['90d', 'Last 90 days'], ['custom', 'Custom dates']];
  var RANGE = { preset: '30d', from: '', to: '' };
  try { var savedRange = JSON.parse(sessionStorage.getItem('kt_aiu_range') || 'null'); if (savedRange && savedRange.preset) RANGE = savedRange; } catch (e) {}
  var BD_CACHE = null;   // { key: 'from|to', data }

  function torontoToday() { return new Date().toLocaleDateString('en-CA', { timeZone: 'America/Toronto' }); }
  function addDays(ymd, n) { var d = new Date(ymd + 'T12:00:00Z'); d.setUTCDate(d.getUTCDate() + n); return d.toISOString().slice(0, 10); }
  function rangeDates() {
    var td = torontoToday(), p = RANGE.preset;
    if (p === '7d') return [addDays(td, -6), td];
    if (p === '90d') return [addDays(td, -89), td];
    if (p === 'month') return [td.slice(0, 8) + '01', td];
    if (p === 'lastmonth') { var end = addDays(td.slice(0, 8) + '01', -1); return [end.slice(0, 8) + '01', end]; }
    if (p === 'custom' && RANGE.from && RANGE.to) return [RANGE.from, RANGE.to];
    return [addDays(td, -29), td];
  }
  function dayLabel(ymd) { try { return new Date(ymd + 'T12:00:00Z').toLocaleDateString('en-CA', { month: 'short', day: 'numeric', year: 'numeric', timeZone: 'UTC' }); } catch (e) { return ymd; } }
  // Sort keys: whole numbers, zero-padded, so the shared header sort (localeCompare) orders money and tokens right.
  function sk(n) { var s = String(Math.max(0, Math.round((Number(n) || 0) * 1e6))); while (s.length < 18) s = '0' + s; return s; }
  function shortWhen(iso) { if (!iso) return '—'; try { return new Date(iso).toLocaleString('en-CA', { month: 'short', day: 'numeric', hour: 'numeric', minute: '2-digit', timeZone: 'America/Toronto' }); } catch (e) { return iso; } }
  function kpi(label, big, small) { return '<div class="kt-aiu-kpi"><b>' + label + '</b><strong>' + big + '</strong><span>' + (small || '') + '</span></div>'; }

  function drawBreakdown(box, force) {
    if (!box) return;
    if (TAB !== 'breakdown' && !force) { box.setAttribute('data-aiu-lazy', '1'); return; }
    box.removeAttribute('data-aiu-lazy');
    var r = rangeDates(), key = r[0] + '|' + r[1];
    box.innerHTML = '<div class="kt-aiu-range">'
      + '<label>Period<select data-aiu-preset data-kt-nofit="1" style="' + FIELD + 'min-width:150px;">' + RANGES.map(function (x) {
          return '<option value="' + x[0] + '"' + (x[0] === RANGE.preset ? ' selected' : '') + '>' + x[1] + '</option>'; }).join('') + '</select></label>'
      + '<label>From<input type="date" data-aiu-from data-kt-nofit="1" value="' + r[0] + '" max="' + torontoToday() + '" style="' + FIELD + '"></label>'
      + '<label>To<input type="date" data-aiu-to data-kt-nofit="1" value="' + r[1] + '" max="' + torontoToday() + '" style="' + FIELD + '"></label></div>'
      + '<div data-aiu-bd-body style="color:var(--aiu-mute);padding:12px 0;">Loading…</div>';
    var sel = box.querySelector('[data-aiu-preset]'), f = box.querySelector('[data-aiu-from]'), t = box.querySelector('[data-aiu-to]');
    var again = function () { try { sessionStorage.setItem('kt_aiu_range', JSON.stringify(RANGE)); } catch (e) {} drawBreakdown(box, true); };
    sel.addEventListener('change', function () { RANGE = { preset: sel.value, from: f.value, to: t.value }; again(); });
    var dates = function () {
      if (!f.value || !t.value) return;
      var a = f.value <= t.value ? f.value : t.value, b = f.value <= t.value ? t.value : f.value;
      RANGE = { preset: 'custom', from: a, to: b }; again();
    };
    f.addEventListener('change', dates); t.addEventListener('change', dates);
    if (BD_CACHE && BD_CACHE.key === key) { paintBreakdown(box, BD_CACHE.data); return; }
    Api.get('/platform/ai/breakdown?from=' + r[0] + '&to=' + r[1]).then(function (d) {
      BD_CACHE = { key: key, data: d };
      if (box.isConnected && rangeDates().join('|') === key) paintBreakdown(box, d);
    }).catch(function (e) {
      var b = box.querySelector('[data-aiu-bd-body]');
      if (b) b.innerHTML = '<div class="kt-card" style="padding:12px 14px;color:#B91C1C;">' + esc((e && e.message) || 'Could not load the breakdown') + '</div>';
    });
  }

  function paintBreakdown(box, d) {
    var k = d.kpi || {}, body = box.querySelector('[data-aiu-bd-body]');
    if (!body) return;
    var period = dayLabel(d.from) + ' – ' + dayLabel(d.to);
    body.removeAttribute('style');
    body.innerHTML = '<div class="kt-aiu-kpis">'
      + kpi('Estimated cost', money(k.cost), esc(period))
      + kpi('AI requests', num(k.requests), num(k.system_requests) + ' automatic (System)')
      + kpi('Tokens', toks(k.input) + ' <span style="display:inline;font-size:13px;font-weight:700;">in</span>', toks(k.output) + ' out')
      + kpi('People using AI', num(k.users), 'signed-in people who made a call')
      + '</div>'
      + '<div role="tablist" aria-label="Breakdown views" style="display:flex;gap:6px;flex-wrap:wrap;margin-bottom:10px;">'
      + BD_TABS.map(function (x) { return '<button type="button" role="tab" data-aiu-bd="' + x[0] + '" aria-selected="' + (x[0] === BD) + '" class="kt-btn' + (x[0] === BD ? ' kt-btn-primary' : '') + '">' + x[1] + '</button>'; }).join('')
      + '</div>'
      + BD_TABS.map(function (x) { return '<div data-aiu-bdpane="' + x[0] + '"' + (x[0] === BD ? '' : ' hidden') + '>' + bdTable(x[0], d) + '</div>'; }).join('')
      + '<div class="kt-aiu-note">Estimated with each model\'s own price per million tokens, input / output: '
      + (d.prices || []).map(function (p) { return esc(p.model) + ' ' + money(p.in) + ' / ' + money(p.out); }).join(' · ')
      + '. A Haiku prompt over ' + num((d.long_prompt || 100000) / 1000) + 'K tokens is charged at its higher rate. A model not on this list uses the flat AI rate in <a href="#usage-spend">Usage &amp; spend</a>. '
      + 'Calls with no signed-in person (the nightly digests and summaries, the website chatbot) are “System”; a background job counts for the person who started it. Prompts and answers are never stored.</div>';
    [].forEach.call(body.querySelectorAll('[data-aiu-bd]'), function (b) {
      b.addEventListener('click', function () {
        BD = b.getAttribute('data-aiu-bd');
        try { sessionStorage.setItem('kt_aiu_bd', BD); } catch (e) {}
        [].forEach.call(body.querySelectorAll('[data-aiu-bd]'), function (x) { var on = x === b; x.setAttribute('aria-selected', String(on)); x.classList.toggle('kt-btn-primary', on); });
        [].forEach.call(body.querySelectorAll('[data-aiu-bdpane]'), function (p) { p.hidden = p.getAttribute('data-aiu-bdpane') !== BD; });
      });
    });
    body.addEventListener('click', function (e) {
      var hit = e.target && e.target.closest && e.target.closest('[data-aiu-user]');
      if (!hit || !body.contains(hit)) return;
      e.preventDefault();
      openUser(hit.getAttribute('data-aiu-user'), d);
    });
  }

  function bdTable(kind, d) {
    var rows = d['by_' + kind] || [], total = Number((d.kpi || {}).cost) || 0;
    if (!rows.length) return '<div class="kt-card" style="padding:14px;color:var(--aiu-faint);">No AI calls in this period.</div>';
    var th = function (label, right) { return '<th' + (right ? ' style="text-align:right;"' : '') + '>' + label + '</th>'; };
    var n = function (text, key, bold) { return '<td class="r" data-kt-sort="' + sk(key) + '"' + (bold ? ' style="font-weight:800;"' : '') + '>' + text + '</td>'; };
    var last = function (iso) { return '<td data-kt-sort="' + esc(iso || '') + '" style="white-space:nowrap;">' + esc(shortWhen(iso)) + '</td>'; };
    var share = function (c) { var v = total > 0 ? c / total * 100 : 0; return n((v >= 10 ? v.toFixed(0) : v.toFixed(1)) + '%', v); };
    var money4 = function (c) { return money(c, c > 0 && c < 0.01 ? 4 : 2); };
    var nums = function (r) { return n(num(r.requests), r.requests) + n(toks(r.input), r.input) + n(toks(r.output), r.output) + n(money4(r.cost), r.cost, 1) + share(r.cost); };
    var numHead = th('Requests', 1) + th('Tokens in', 1) + th('Tokens out', 1) + th('Est. cost', 1) + th('Share', 1);
    var head, body;
    if (kind === 'user') {
      // Nine columns, no Share: tokens in / out share one cell so Top features keeps room to read.
      head = th('Name') + th('Role') + th('Agency') + th('Centre') + th('Calls', 1) + th('Tokens in / out', 1) + th('Est. cost', 1) + th('Last used') + th('Top features');
      body = rows.map(function (u) {
        var feats = (u.top_features || []).map(function (f) { return '<div>' + esc(f.label) + ' <span style="color:var(--aiu-faint);">· ' + num(f.requests) + '</span></div>'; }).join('');
        return '<tr data-aiu-user="' + esc(u.key) + '">'
          + '<td data-kt-sort="' + esc((u.system ? '' : 'a') + String(u.name || '').toLowerCase()) + '"><a href="#ai-usage" class="kt-aiu-who" data-aiu-user="' + esc(u.key) + '">' + esc(u.name) + '</a>'
          +   (u.removed ? ' <span style="color:var(--aiu-faint);font-size:11px;">(removed)</span>' : '') + '</td>'
          + '<td>' + (u.system ? '<span class="kt-aiu-sys">Automatic</span>' : esc(u.role || '—')) + '</td>'
          + '<td>' + esc(u.agency || '—') + (u.agency_count > 1 ? ' <span style="color:var(--aiu-faint);">+' + (u.agency_count - 1) + '</span>' : '') + '</td>'
          + '<td>' + esc(u.centre || '—') + '</td>'
          + n(num(u.requests), u.requests) + n(toks(u.input) + ' / ' + toks(u.output), u.input + u.output) + n(money4(u.cost), u.cost, 1)
          + last(u.last_at) + '<td class="kt-aiu-feats" style="font-size:12px;line-height:1.45;">' + (feats || '—') + '</td></tr>';
      }).join('');
    } else if (kind === 'feature') {
      head = th('Feature') + th('Source') + numHead + th('Last used');
      body = rows.map(function (r) {
        return '<tr><td style="font-weight:700;">' + esc(r.label) + '</td><td class="kt-aiu-src">' + esc(r.key) + '</td>' + nums(r) + last(r.last_at) + '</tr>';
      }).join('');
    } else if (kind === 'model') {
      head = th('Model') + th('Price per 1M tokens (in / out)') + numHead + th('Last used');
      body = rows.map(function (r) {
        var p = r.rates || {};
        var price = money(p.in) + ' / ' + money(p.out) + (p.long_in !== p.in ? ' <span style="color:var(--aiu-faint);">(' + money(p.long_in) + ' / ' + money(p.long_out) + ' over 100K)</span>' : '')
          + (r.priced === false ? ' <span style="color:#B45309;">flat rate: not on the price list</span>' : '');
        return '<tr><td style="font-weight:700;">' + esc(r.label) + '</td><td>' + price + '</td>' + nums(r) + last(r.last_at) + '</tr>';
      }).join('');
    } else {
      head = th('Agency') + numHead + th('Last used');
      body = rows.map(function (r) { return '<tr><td style="font-weight:700;">' + esc(r.label) + '</td>' + nums(r) + last(r.last_at) + '</tr>'; }).join('');
    }
    var title = { user: 'AI usage by person', feature: 'AI usage by feature', agency: 'AI usage by agency', model: 'AI usage by model' }[kind] + ', ' + d.from + ' to ' + d.to;
    return '<div class="kt-card" style="padding:8px 10px;" data-kt-export-brand="platform"><table class="kt-aiu-t" data-kt-bulk="off" data-kt-export-title="' + esc(title) + '"><thead><tr>' + head + '</tr></thead><tbody>' + body + '</tbody></table></div>';
  }

  /* One person's recent calls. ai_usage keeps what asked, when, the model and the token counts - never a prompt or an
     answer - so that is all there is to show. */
  function openUser(id, d) {
    var u = (d.by_user || []).filter(function (x) { return String(x.key) === String(id); })[0] || { name: 'AI calls' };
    var wrap = document.createElement('div');
    wrap.className = 'kt-aiu';
    wrap.innerHTML = '<div style="font-size:12.5px;color:var(--aiu-mute);">' + [u.system ? 'Automatic: no signed-in person' : u.role, u.agency, u.centre].filter(Boolean).map(esc).join(' · ') + '</div>'
      + '<div class="kt-aiu-kpis">' + kpi('Est. cost', money(u.cost, u.cost > 0 && u.cost < 0.01 ? 4 : 2), esc(dayLabel(d.from) + ' – ' + dayLabel(d.to)))
      + kpi('Requests', num(u.requests), 'last ' + esc(when(u.last_at))) + kpi('Tokens', toks(u.input) + ' <span style="display:inline;font-size:13px;font-weight:700;">in</span>', toks(u.output) + ' out') + '</div>'
      + '<div data-aiu-calls style="color:var(--aiu-mute);font-size:12.5px;">Loading…</div>'
      + '<div class="kt-aiu-note">Only what asked, when, the model, its size and its cost are kept. The prompt and the answer are never stored.</div>';
    KT.Shell.Modal.open({ title: '🤖 ' + (u.name || 'AI calls'), body: wrap, actions: [{ label: 'Close' }], large: true });
    Api.get('/platform/ai/users/' + encodeURIComponent(id) + '/calls?from=' + d.from + '&to=' + d.to).then(function (r) {
      var box = wrap.querySelector('[data-aiu-calls]');
      if (!box) return;
      if (!r.calls || !r.calls.length) { box.textContent = 'No AI calls in this period.'; return; }
      box.innerHTML = '<div style="font-weight:800;color:var(--aiu-ink);margin-bottom:4px;">Recent calls'
        + (r.total > r.shown ? ' <span style="font-weight:600;color:var(--aiu-faint);font-size:12px;">· the latest ' + num(r.shown) + ' of ' + num(r.total) + '</span>' : '') + '</div>'
        + r.calls.map(function (c) {
          return '<div class="kt-aiu-call"><div><b>' + esc(c.label) + '</b><div class="m">' + esc(c.model) + (c.agency ? ' · ' + esc(c.agency) : '') + '</div></div>'
            + '<div class="m">' + esc(when(c.at)) + '</div>'
            + '<div class="m" style="text-align:right;white-space:nowrap;">' + toks(c.input) + ' in · ' + toks(c.output) + ' out</div>'
            + '<div style="text-align:right;font-weight:800;white-space:nowrap;">' + money(c.cost, 4) + '</div></div>';
        }).join('');
    }).catch(function (e) {
      var box = wrap.querySelector('[data-aiu-calls]');
      if (box) box.innerHTML = '<span style="color:#B91C1C;">' + esc((e && e.message) || 'Could not load the calls') + '</span>';
    });
  }

  function status(c) {
    var err = c.last_error, ok = c.last_ok;
    var errNewer = err && (!ok || new Date(err.at) > new Date(ok));
    if (c.source === 'none') return ['#B91C1C', '#FEF2F2', '⛔ No key set'];
    if (errNewer && /credit/i.test(err.message || '')) return ['#B91C1C', '#FEF2F2', '⛔ Out of credit'];
    if (errNewer) return ['#B45309', '#FFFBEB', '⚠️ ' + (err.message || 'Last call failed')];
    if (ok) return ['#065F46', '#ECFDF5', '✅ Connected'];
    return ['#475569', '#F1F5F9', 'Not used yet'];
  }

  function draw(body, d) {
    var c = d.connection || {}, t = d.totals || {}, cr = d.credit;
    var st = status(c);
    var tile = function (label, x) {
      x = x || {};
      return '<div style="background:var(--aiu-panel);border:1px solid var(--aiu-line);border-radius:14px;padding:12px 14px;">'
        + '<div style="font-size:11px;font-weight:800;color:var(--aiu-mute);text-transform:uppercase;letter-spacing:.5px;">' + label + '</div>'
        + '<div style="font-size:22px;font-weight:900;color:var(--aiu-ink);margin-top:2px;">' + money(x.cost) + '</div>'
        + '<div style="font-size:12px;color:var(--aiu-mute);">' + num(x.requests) + ' requests · ' + toks(x.input) + ' in · ' + toks(x.output) + ' out</div></div>';
    };
    var max = Math.max.apply(null, (d.daily || []).map(function (x) { return x.cost; }).concat([0.0001]));
    var chart = '<div style="display:flex;align-items:flex-end;gap:3px;height:120px;border-bottom:1px solid #E2E8F0;padding:0 2px;">'
      + (d.daily || []).map(function (x) {
        var h = x.cost > 0 ? Math.max(3, Math.round(x.cost / max * 100)) : (x.requests ? 2 : 0);
        return '<div title="' + esc(x.date) + ': ' + money(x.cost, 4) + ' · ' + num(x.requests) + ' requests" style="flex:1 1 0;min-width:0;height:100%;display:flex;align-items:flex-end;">'
          + '<div style="width:100%;height:' + h + '%;border-radius:3px 3px 0 0;background:linear-gradient(180deg,#A78BFA,#7C3AED);"></div></div>';
      }).join('') + '</div>'
      + '<div style="display:flex;justify-content:space-between;font-size:10.5px;color:#94A3B8;margin-top:3px;"><span>' + esc(((d.daily || [])[0] || {}).date || '') + '</span><span>today</span></div>';
    var models = d.models || {};

    var pane = function (key) { return '<div data-aiu-pane="' + key + '"' + (key === TAB ? '' : ' hidden') + '>'; };
    var h = '<div role="tablist" aria-label="AI usage sections" style="display:flex;gap:6px;flex-wrap:wrap;margin-bottom:14px;">'
      + TABS.map(function (t) { return '<button type="button" role="tab" data-aiu-tab="' + t[0] + '" aria-selected="' + (t[0] === TAB) + '" class="kt-btn' + (t[0] === TAB ? ' kt-btn-primary' : '') + '">' + t[1] + '</button>'; }).join('')
      + '</div>'
      // ── Overview: the state at a glance, the totals and the chart ──
      + pane('overview')
      + '<div style="display:flex;gap:10px;flex-wrap:wrap;align-items:center;margin-bottom:12px;">'
      +   '<span style="background:' + st[1] + ';color:' + st[0] + ';border-radius:999px;padding:5px 12px;font-weight:800;font-size:12.5px;">' + esc(st[2]) + '</span>'
      +   '<span style="font-size:12.5px;color:#64748B;">model <b>' + esc(c.model) + '</b>'
      +   (cr ? ' · about <b style="color:' + (cr.left <= 0 ? '#B91C1C' : cr.left < 5 ? '#B45309' : '#065F46') + ';">' + money(cr.left) + '</b> credit left' + (cr.days_left != null ? ' (~' + num(cr.days_left) + ' days)' : '') : '')
      +   ' · ' + num((d.agencies || []).filter(function (a) { return a.ai_off; }).length) + ' agencies switched off</span></div>'
      + '<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:10px;margin-bottom:14px;">'
      +   tile('Today', t.today) + tile('Last 7 days', t['7d']) + tile('Last 30 days', t['30d']) + tile('This month', t.month) + '</div>'
      + '<div class="kt-card" style="padding:12px 14px;margin-bottom:14px;"><div style="font-weight:800;color:#0F172A;margin-bottom:8px;">📈 Estimated cost per day <span style="font-weight:600;color:#94A3B8;font-size:12px;">· last 30 days</span></div>' + chart + '</div>'
      + '</div>'
      // ── Connection ──
      + pane('connection')
      + '<div class="kt-card" style="padding:14px 16px;margin-bottom:14px;">'
      + '<div style="display:flex;justify-content:space-between;align-items:flex-start;gap:12px;flex-wrap:wrap;">'
      +   '<div><div style="font-weight:800;color:#0F172A;font-size:15px;">🤖 Claude connection</div>'
      +   '<div style="font-size:12.5px;color:#64748B;margin-top:3px;">Key ' + (c.key_last4 ? '<b>…' + esc(c.key_last4) + '</b>' : '<b>not set</b>')
      +   ' · ' + (c.source === 'portal' ? 'set here' : c.source === 'env' ? 'from the server .env' : 'none') + ' · model <b>' + esc(c.model) + '</b></div>'
      +   '<div style="font-size:12px;color:#94A3B8;margin-top:3px;">Last answer: ' + esc(when(c.last_ok)) + (c.last_error ? ' · last error: ' + esc(when(c.last_error.at)) + ' — ' + esc(c.last_error.message) : '') + '</div></div>'
      +   '<span style="background:' + st[1] + ';color:' + st[0] + ';border-radius:999px;padding:5px 12px;font-weight:800;font-size:12.5px;white-space:nowrap;">' + esc(st[2]) + '</span></div>'
      + '<div style="display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end;margin-top:12px;">'
      +   '<label style="font-size:12px;font-weight:700;color:#475569;display:flex;flex-direction:column;gap:3px;">Model'
      +     '<select id="aiu-model" data-kt-nofit="1" style="' + FIELD + 'min-width:300px;">' + Object.keys(models).map(function (m) {
              return '<option value="' + esc(m) + '"' + (m === c.model ? ' selected' : '') + '>' + esc(models[m]) + '</option>'; }).join('') + '</select></label>'
      +   '<label style="font-size:12px;font-weight:700;color:#475569;display:flex;flex-direction:column;gap:3px;flex:1 1 260px;">New API key <span style="font-weight:500;color:#94A3B8;">(leave blank to keep …' + esc(c.key_last4 || '') + ')</span>'
      +     '<input id="aiu-key" type="password" autocomplete="off" data-kt-nofit="1" placeholder="sk-ant-…" style="' + FIELD + 'width:100%;"></label>'
      +   '<button type="button" id="aiu-save" class="kt-btn kt-btn-primary" data-kt-always-save="1">Save</button>'
      +   '<button type="button" id="aiu-test" class="kt-btn">Test connection</button></div>'
      + '<div style="font-size:11.5px;color:#94A3B8;margin-top:6px;">A new key or model is tried with Anthropic before it is saved. The key is stored encrypted and is never shown again — only its last 4 characters.</div>'
      + '</div></div>'
      + pane('off')
      // ── AI switched off per agency (2026-10-28: "turn off the AI for all test agency's so it doesnt eat up our credits") ──
      + '<div class="kt-card" style="padding:14px 16px;margin-bottom:14px;">'
      + '<div style="font-weight:800;color:#0F172A;font-size:15px;">🚫 AI switched off for</div>'
      + '<div style="font-size:12.5px;color:#64748B;margin:3px 0 8px;">Ticked agencies use no Claude credits: no AI Bundle features, and Help “Ask AI”, the chatbot and card scanning fall back as when AI is unavailable. Use it for test and demo agencies.</div>'
      + '<div style="display:flex;flex-wrap:wrap;gap:8px 18px;">' + (d.agencies || []).map(function (a) {
          return '<label style="display:inline-flex;align-items:center;gap:7px;font-size:13px;color:#0F172A;cursor:pointer;">'
            + '<input type="checkbox" data-aiu-off="' + a.id + '"' + (a.ai_off ? ' checked' : '') + ' style="width:16px;height:16px;">'
            + esc(a.name) + (a.status ? ' <span style="color:#94A3B8;font-size:11.5px;">(' + esc(a.status) + ')</span>' : '') + '</label>';
        }).join('') + '</div>'
      + '<div style="display:flex;justify-content:flex-end;margin-top:8px;"><button type="button" id="aiu-off-save" class="kt-btn">Save</button></div>'
      + '</div></div>'
      // ── Credits ──
      + pane('credits')
      + '<div class="kt-card" style="padding:14px 16px;margin-bottom:14px;">'
      + '<div style="display:flex;justify-content:space-between;gap:12px;flex-wrap:wrap;align-items:flex-start;">'
      +   '<div><div style="font-weight:800;color:#0F172A;font-size:15px;">💳 Credits</div>'
      +   '<div style="font-size:12.5px;color:#64748B;margin-top:3px;max-width:640px;">Anthropic does not share the prepaid balance with an API key, so this is an <b>estimate</b>: the top-ups you record below, minus what KiddieTrac has spent since. '
      +   'Check the exact figure in the <a href="https://console.anthropic.com/settings/billing" target="_blank" rel="noopener">Anthropic console → Plans &amp; Billing</a>.</div></div>'
      +   (cr ? '<div style="text-align:right;"><div style="font-size:26px;font-weight:900;color:' + (cr.left <= 0 ? '#B91C1C' : cr.left < 5 ? '#B45309' : '#065F46') + ';">' + money(cr.left) + '</div>'
              + '<div style="font-size:12px;color:#64748B;">estimated left' + (cr.days_left != null ? ' · about <b>' + num(cr.days_left) + ' days</b> at this week\'s pace' : '') + '</div>'
              + '<div style="font-size:11.5px;color:#94A3B8;">' + money(cr.added) + ' added since ' + esc(cr.since) + ' · ' + money(cr.spent) + ' spent · ' + money(cr.per_day, 4) + '/day</div></div>'
            : '<div style="font-size:12.5px;color:#94A3B8;max-width:260px;text-align:right;">Record a top-up below to see an estimated balance.</div>')
      + '</div>'
      + ((d.topups || []).length ? '<div style="margin-top:10px;border-top:1px solid #F1F5F9;">' + d.topups.map(function (x) {
          return '<div style="display:flex;justify-content:space-between;align-items:center;gap:8px;padding:6px 0;border-bottom:1px solid #F8FAFC;font-size:13px;">'
            + '<span><b>' + money(x.amount) + '</b> · ' + esc(x.date) + (x.note ? ' · <span style="color:#64748B;">' + esc(x.note) + '</span>' : '') + '</span>'
            + '<button type="button" class="kt-btn" data-aiu-rm="' + esc(x.id) + '" aria-label="Remove this top-up">Remove</button></div>';
        }).join('') + '</div>' : '')
      + '<div style="display:flex;gap:8px;flex-wrap:wrap;align-items:flex-end;margin-top:10px;">'
      +   '<label style="font-size:12px;font-weight:700;color:#475569;display:flex;flex-direction:column;gap:3px;">Credits added ($)<input id="aiu-amt" type="text" inputmode="decimal" data-kt-nofit="1" style="' + FIELD + 'width:120px;"></label>'
      +   '<label style="font-size:12px;font-weight:700;color:#475569;display:flex;flex-direction:column;gap:3px;">On<input id="aiu-date" type="date" data-kt-nofit="1" value="' + new Date().toISOString().slice(0, 10) + '" style="' + FIELD + '"></label>'
      +   '<label style="font-size:12px;font-weight:700;color:#475569;display:flex;flex-direction:column;gap:3px;flex:1 1 180px;">Note<input id="aiu-note" type="text" data-kt-nofit="1" placeholder="optional" style="' + FIELD + 'width:100%;"></label>'
      +   '<button type="button" id="aiu-add" class="kt-btn">+ Record top-up</button></div>'
      + '</div></div>'
      // ── Breakdown: its own range, KPI tiles and one table at a time (drawBreakdown) ──
      + pane('breakdown') + '<div id="aiu-bd"></div></div>';
    body.innerHTML = h;
    drawBreakdown(body.querySelector('#aiu-bd'));
    [].forEach.call(body.querySelectorAll('[data-aiu-tab]'), function (b) {
      b.addEventListener('click', function () {
        TAB = b.getAttribute('data-aiu-tab');
        try { sessionStorage.setItem('kt_aiu_tab', TAB); } catch (e) {}
        [].forEach.call(body.querySelectorAll('[data-aiu-tab]'), function (x) {
          var on = x === b; x.setAttribute('aria-selected', String(on)); x.classList.toggle('kt-btn-primary', on);
        });
        [].forEach.call(body.querySelectorAll('[data-aiu-pane]'), function (p) { p.hidden = p.getAttribute('data-aiu-pane') !== TAB; });
        var bd = body.querySelector('#aiu-bd');
        if (TAB === 'breakdown' && bd && bd.hasAttribute('data-aiu-lazy')) drawBreakdown(bd);
      });
    });

    body.querySelector('#aiu-save').addEventListener('click', async function (e) {
      var btn = e.currentTarget, key = body.querySelector('#aiu-key').value.trim(), model = body.querySelector('#aiu-model').value;
      var payload = {};
      if (key) payload.key = key;
      if (model && model !== c.model) payload.model = model;
      if (!payload.key && !payload.model) { toast('Nothing to change', 'info'); return; }
      btn.disabled = true; btn.textContent = 'Testing…';
      try { draw(body, await Api.put('/platform/ai/settings', payload)); toast('Saved and tested', 'success'); }
      catch (err) { btn.disabled = false; btn.textContent = 'Save'; toast((err && err.message) || 'Not saved', 'error'); }
    });
    body.querySelector('#aiu-test').addEventListener('click', async function (e) {
      var btn = e.currentTarget; btn.disabled = true; btn.textContent = 'Testing…';
      try { var r = await Api.post('/platform/ai/test', {}); toast(r.message || 'Connected', 'success'); if (r.overview) draw(body, r.overview); }
      catch (err) { btn.disabled = false; btn.textContent = 'Test connection'; toast((err && err.message) || 'Not connected', 'error'); Api.get('/platform/ai').then(function (x) { draw(body, x); }).catch(function () {}); }
    });
    body.querySelector('#aiu-off-save').addEventListener('click', async function (e) {
      var btn = e.currentTarget, ids = [];
      [].forEach.call(body.querySelectorAll('[data-aiu-off]'), function (i) { if (i.checked) ids.push(+i.getAttribute('data-aiu-off')); });
      btn.disabled = true;
      try { draw(body, await Api.put('/platform/ai/off', { agency_ids: ids })); toast('Saved', 'success'); }
      catch (err) { btn.disabled = false; toast((err && err.message) || 'Could not save', 'error'); }
    });
    body.querySelector('#aiu-add').addEventListener('click', async function (e) {
      var btn = e.currentTarget, amt = body.querySelector('#aiu-amt').value.trim(), date = body.querySelector('#aiu-date').value, note = body.querySelector('#aiu-note').value.trim();
      if (!(Number(amt) > 0)) { toast('Enter the amount of credits added', 'error'); return; }
      btn.disabled = true;
      try { draw(body, await Api.post('/platform/ai/topups', { amount: Number(amt), date: date, note: note })); toast('Top-up recorded', 'success'); }
      catch (err) { btn.disabled = false; toast((err && err.message) || 'Could not record it', 'error'); }
    });
    [].forEach.call(body.querySelectorAll('[data-aiu-rm]'), function (b) {
      b.addEventListener('click', async function () {
        b.disabled = true;
        try { draw(body, await Api.delete('/platform/ai/topups/' + b.getAttribute('data-aiu-rm'))); }
        catch (err) { b.disabled = false; toast((err && err.message) || 'Could not remove it', 'error'); }
      });
    });
  }

  KT.Shell.registerScreen('platform_admin:ai-usage', render);
})(window);
