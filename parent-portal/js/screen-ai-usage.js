/* ═══════════════════════════════════════════════════════════════════════
   Platform → AI usage (#ai-usage) — 2026-10-28

   Anthony: "configure [my Claude API key] in the portal where if i need to change it its accessible" and "wire up
   something in the platform view to see my claude usage and credits available etc - with full stats".

   The Claude connection (key — only its last 4 ever come back — and model; a change is tested before it is kept),
   the account's live state (last success / last error: "out of credit"), the estimated credit left (top-ups
   recorded here minus what KiddieTrac spent since: Anthropic does not give the balance to an API key), and every
   AI call KiddieTrac made: today / 7 days / 30 days / this month, a 30-day chart, by agency, feature and model.
   Server: App\Support\AiConfig, PlatformAiController. Platform admins only.
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
    main.innerHTML = '<div style="padding:14px 24px;"><div id="aiu-body" style="color:#64748B;">Loading…</div></div>';
    var body = main.querySelector('#aiu-body');
    try { draw(body, await Api.get('/platform/ai')); }
    catch (e) { body.innerHTML = '<div class="kt-card" style="padding:14px;color:#B91C1C;">' + esc((e && e.message) || 'Could not load') + '</div>'; }
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
      return '<div style="background:#fff;border:1px solid #E7EDF3;border-radius:14px;padding:12px 14px;">'
        + '<div style="font-size:11px;font-weight:800;color:#64748B;text-transform:uppercase;letter-spacing:.5px;">' + label + '</div>'
        + '<div style="font-size:22px;font-weight:900;color:#0F172A;margin-top:2px;">' + money(x.cost) + '</div>'
        + '<div style="font-size:12px;color:#64748B;">' + num(x.requests) + ' requests · ' + toks(x.input) + ' in · ' + toks(x.output) + ' out</div></div>';
    };
    var max = Math.max.apply(null, (d.daily || []).map(function (x) { return x.cost; }).concat([0.0001]));
    var chart = '<div style="display:flex;align-items:flex-end;gap:3px;height:120px;border-bottom:1px solid #E2E8F0;padding:0 2px;">'
      + (d.daily || []).map(function (x) {
        var h = x.cost > 0 ? Math.max(3, Math.round(x.cost / max * 100)) : (x.requests ? 2 : 0);
        return '<div title="' + esc(x.date) + ': ' + money(x.cost, 4) + ' · ' + num(x.requests) + ' requests" style="flex:1 1 0;min-width:0;height:100%;display:flex;align-items:flex-end;">'
          + '<div style="width:100%;height:' + h + '%;border-radius:3px 3px 0 0;background:linear-gradient(180deg,#A78BFA,#7C3AED);"></div></div>';
      }).join('') + '</div>'
      + '<div style="display:flex;justify-content:space-between;font-size:10.5px;color:#94A3B8;margin-top:3px;"><span>' + esc(((d.daily || [])[0] || {}).date || '') + '</span><span>today</span></div>';
    var table = function (title, rows) {
      return '<div class="kt-card" style="padding:12px 14px;"><div style="font-weight:800;color:#0F172A;margin-bottom:6px;">' + title + ' <span style="font-weight:600;color:#94A3B8;font-size:12px;">· last 30 days</span></div>'
        + (rows && rows.length ? '<table data-kt-filtered="1" data-kt-bulk="off" class="kt-report-doc" style="width:100%;border-collapse:collapse;font-size:12.5px;"><thead><tr style="color:#64748B;text-align:left;">'
          + '<th style="padding:5px 4px;">Name</th><th style="padding:5px 4px;text-align:right;">Requests</th><th style="padding:5px 4px;text-align:right;">Tokens in / out</th><th style="padding:5px 4px;text-align:right;">Est. cost</th></tr></thead><tbody>'
          + rows.map(function (r) {
            return '<tr style="border-top:1px solid #F1F5F9;"><td style="padding:6px 4px;font-weight:600;color:#0F172A;word-break:break-word;">' + esc(r.label) + '</td>'
              + '<td style="padding:6px 4px;text-align:right;">' + num(r.requests) + '</td><td style="padding:6px 4px;text-align:right;white-space:nowrap;">' + toks(r.input) + ' / ' + toks(r.output) + '</td>'
              + '<td style="padding:6px 4px;text-align:right;font-weight:700;">' + money(r.cost, 4) + '</td></tr>';
          }).join('') + '</tbody></table>'
          : '<div style="color:#94A3B8;font-size:12.5px;">No AI calls in the last 30 days.</div>') + '</div>';
    };
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
      // ── Breakdown: by agency, feature and model ──
      + pane('breakdown')
      + '<div style="display:grid;grid-template-columns:1fr;gap:12px;margin-bottom:10px;">'
      +   table('🏢 By agency', d.by_agency) + table('🧩 By feature', d.by_feature) + table('🧠 By model', d.by_model) + '</div>'
      + '<div style="font-size:11.5px;color:#94A3B8;">Costs are estimated with the AI rates in <a href="#usage-spend">Usage &amp; spend</a> (' + money((d.rates || {}).input_mtok) + ' / 1M input tokens, '
      +   money((d.rates || {}).output_mtok) + ' / 1M output). Every Claude call KiddieTrac makes is counted.</div></div>';
    body.innerHTML = h;
    [].forEach.call(body.querySelectorAll('[data-aiu-tab]'), function (b) {
      b.addEventListener('click', function () {
        TAB = b.getAttribute('data-aiu-tab');
        try { sessionStorage.setItem('kt_aiu_tab', TAB); } catch (e) {}
        [].forEach.call(body.querySelectorAll('[data-aiu-tab]'), function (x) {
          var on = x === b; x.setAttribute('aria-selected', String(on)); x.classList.toggle('kt-btn-primary', on);
        });
        [].forEach.call(body.querySelectorAll('[data-aiu-pane]'), function (p) { p.hidden = p.getAttribute('data-aiu-pane') !== TAB; });
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
