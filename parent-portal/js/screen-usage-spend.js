/* ═══════════════════════════════════════════════════════════════════════
   Reseller → Usage & spend (#usage-spend) — 2026-10-20

   Anthony: "add logic if SMS, TEXT or AI exceeds threshold $ per agency to notify
   superadmin (configurable in reseller panel)" — then "add to weekly summary email".

   Each agency's texts, phone calls and AI cost for the last week (or 30 days), the rates
   those costs are worked out with, and the weekly thresholds. Every Monday at 07:15 the
   superadmins get the Platform weekly summary (platform:usage-summary) with the agencies
   over a threshold at the top. Agencies never see this.
   ═══════════════════════════════════════════════════════════════════════ */
(function (window) {
  'use strict';
  var KT = window.KT;
  if (!KT || !KT.Shell || !KT.Shell.registerScreen) { return; }
  var Api = KT.Api;

  function esc(s) { return String(s == null ? '' : s).replace(/[&<>"]/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]; }); }
  function money(v) { return '$' + (Number(v) || 0).toFixed(2); }
  function num(v) { return (Number(v) || 0).toLocaleString('en-CA'); }
  function toast(msg, kind) { try { if (KT.Dom && KT.Dom.toast) KT.Dom.toast(msg, kind || 'info'); } catch (e) {} }

  // Text inputs with a decimal keypad: number inputs' spinners ate the width and cut the value off.
  var INPUT = 'height:30px;padding:3px 8px;border:1px solid #CBD5E1;border-radius:7px;font-size:13px;width:104px;min-width:104px;box-sizing:border-box;text-align:right;';
  var SMALL = 'height:28px;padding:2px 6px;border:1px solid #E2E8F0;border-radius:6px;font-size:12.5px;width:70px;min-width:70px;box-sizing:border-box;text-align:right;';
  var RATE_LABELS = [
    ['sms_segment', 'Text, per segment'], ['voice_minute', 'Call, per minute'],
    ['ai_input_mtok', 'AI input, per 1M tokens (unlisted models)'], ['ai_output_mtok', 'AI output, per 1M tokens (unlisted models)'],
  ];
  /* AI is priced per model since 2026-11-26 (App\Support\AiPricing: Haiku, Sonnet, Opus, Fable at Anthropic's list prices).
     These two AI rates now only price a model that is not on that list yet. */
  var days = 7;

  async function render(main) {
    main.setAttribute('data-kt-pretty', '1');
    main.innerHTML = '<div style="padding:14px 24px;max-width:1180px;"><div id="us-ov" style="margin-bottom:18px;"></div><div id="us-body" style="color:#64748B;">Loading…</div></div>';
    var body = main.querySelector('#us-body');
    loadOverage(main.querySelector('#us-ov'), ovMonth);
    var d;
    try { d = await Api.get('/platform/usage-spend?days=' + days); }
    catch (e) { body.innerHTML = '<div class="kt-card">' + esc((e && e.message) || 'Could not load') + '</div>'; return; }
    draw(main, body, d);
  }

  function draw(main, body, d) {
    var cats = d.categories || { sms: 'Texts', voice: 'Phone calls', ai: 'AI' };
    var weekly = d.days === 7;
    var over = (d.agencies || []).filter(function (a) { return weekly && a.over && a.over.length; });

    var h = '<div style="display:flex;justify-content:space-between;align-items:center;gap:10px;flex-wrap:wrap;margin-bottom:12px;">'
      + '<div style="font-size:13.5px;color:#475569;max-width:720px;">What each agency\'s texts, phone calls and AI cost, worked out with the rates below. '
      + 'Every Monday at 7:15 the superadmins are emailed the <b>Platform weekly summary</b>, with any agency over its weekly threshold at the top. Agencies never see this.</div>'
      + '<div style="display:flex;gap:8px;align-items:center;">'
      + '<select id="us-days" data-kt-nofit="1" style="height:30px;border:1px solid #CBD5E1;border-radius:7px;font-size:13px;padding:2px 8px;">'
      + '<option value="7"' + (d.days === 7 ? ' selected' : '') + '>Last 7 days</option><option value="30"' + (d.days === 30 ? ' selected' : '') + '>Last 30 days</option></select>'
      + '<button type="button" id="us-test" class="kt-btn">✉️ Email me this week\'s summary</button></div></div>';

    if (weekly) {
      h += over.length
        ? '<div style="background:#FEF2F2;border:1px solid #FECACA;color:#991B1B;border-radius:12px;padding:10px 14px;margin-bottom:12px;font-size:13.5px;"><b>⚠️ ' + over.length + ' agenc' + (over.length === 1 ? 'y is' : 'ies are') + ' over a threshold this week:</b> '
          + over.map(function (a) { return esc(a.name) + ' (' + a.over.map(function (c) { return esc(cats[c]); }).join(', ') + ')'; }).join(' · ') + '</div>'
        : '<div style="background:#ECFDF5;border:1px solid #A7F3D0;color:#065F46;border-radius:12px;padding:10px 14px;margin-bottom:12px;font-size:13.5px;">✅ No agency is over a threshold for the last 7 days.</div>';
    } else {
      h += '<div style="background:#F8FAFC;border:1px solid #E2E8F0;color:#475569;border-radius:12px;padding:10px 14px;margin-bottom:12px;font-size:13px;">Thresholds are weekly, so over-threshold flags show on the 7-day view only.</div>';
    }

    // Rates + default thresholds
    h += '<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(320px,1fr));gap:12px;margin-bottom:14px;">'
      + '<div class="kt-card" style="padding:14px 16px;"><div style="font-weight:800;color:#0F172A;margin-bottom:4px;">💲 Rates</div>'
      + '<div style="font-size:12.5px;color:#64748B;margin-bottom:10px;">What one unit costs you. Set these to your carrier and AI prices (or to what you charge).</div>'
      + RATE_LABELS.map(function (r) {
        return '<label style="display:flex;justify-content:space-between;align-items:center;gap:10px;padding:4px 0;font-size:13px;color:#334155;">' + esc(r[1])
          + '<span>$ <input type="text" inputmode="decimal" data-kt-nofit="1" data-rate="' + r[0] + '" value="' + esc(d.rates[r[0]]) + '" style="' + INPUT + '"></span></label>';
      }).join('') + '</div>'
      + '<div class="kt-card" style="padding:14px 16px;"><div style="font-weight:800;color:#0F172A;margin-bottom:4px;">🚨 Default weekly thresholds</div>'
      + '<div style="font-size:12.5px;color:#64748B;margin-bottom:10px;">Applies to every agency unless it has its own below. 0 = never flag.</div>'
      + Object.keys(cats).map(function (c) {
        return '<label style="display:flex;justify-content:space-between;align-items:center;gap:10px;padding:4px 0;font-size:13px;color:#334155;">' + esc(cats[c]) + ' per week'
          + '<span>$ <input type="text" inputmode="decimal" data-kt-nofit="1" data-def="' + c + '" value="' + esc(d.defaults[c]) + '" style="' + INPUT + '"></span></label>';
      }).join('') + '</div></div>';

    // Per-agency table
    var th = function (t, right) { return '<th style="text-align:' + (right ? 'right' : 'left') + ';padding:9px 10px;font-size:11px;font-weight:800;color:#64748B;text-transform:uppercase;letter-spacing:.4px;">' + t + '</th>'; };
    h += '<div style="overflow-x:auto;background:#fff;border:1px solid #E7EDF3;border-radius:14px;">'
      + '<table data-kt-filtered="1" data-kt-rows-key="agencies" style="width:100%;border-collapse:collapse;font-size:13px;min-width:900px;"><thead><tr style="background:#F8FAFC;">'
      + th('Agency') + th('Texts', 1) + th('Calls', 1) + th('AI', 1) + th('Total', 1) + th('Own weekly thresholds ($)<div style="display:flex;justify-content:flex-end;gap:6px;margin-top:3px;font-weight:700;text-transform:none;letter-spacing:0;">' + Object.keys(cats).map(function (c) { return '<span style="width:70px;text-align:center;">' + esc(cats[c]) + '</span>'; }).join('') + '</div>', 1) + '</tr></thead><tbody>'
      + (d.agencies || []).map(function (a) {
        var cell = function (c, detail) {
          var red = weekly && a.over && a.over.indexOf(c) !== -1;
          return '<td style="text-align:right;padding:8px 10px;white-space:nowrap;' + (red ? 'color:#B91C1C;font-weight:800;' : '') + '">' + money(a.cost[c])
            + '<div style="font-size:11px;color:#94A3B8;font-weight:500;">' + detail + '</div></td>';
        };
        var custom = a.custom || {};
        var inputs = a.agency_id ? Object.keys(cats).map(function (c) {
          return '<input type="text" inputmode="decimal" data-kt-nofit="1" title="' + esc(cats[c]) + '" aria-label="' + esc(cats[c]) + ' threshold for ' + esc(a.name) + '" data-ag="' + a.agency_id + '" data-cat="' + c + '" value="' + (custom[c] != null ? esc(custom[c]) : '') + '" placeholder="' + esc(d.defaults[c]) + '" style="' + SMALL + '">';
        }).join(' ') : '<span style="font-size:12px;color:#94A3B8;">Uses the defaults</span>';
        return '<tr style="border-top:1px solid #F1F5F9;">'
          + '<td style="padding:8px 10px;font-weight:700;color:#0F172A;">' + esc(a.name) + '</td>'
          + cell('sms', num(a.texts) + ' texts · ' + num(a.segments) + ' seg')
          + cell('voice', num(a.calls) + ' calls · ' + num(a.minutes) + ' min')
          + cell('ai', num(a.ai_requests) + ' req · ' + num(Math.round((a.ai_input + a.ai_output) / 1000)) + 'k tok')
          + '<td style="text-align:right;padding:8px 10px;font-weight:800;">' + money(a.total) + '</td>'
          + '<td style="text-align:right;padding:8px 10px;white-space:nowrap;">' + inputs + '</td></tr>';
      }).join('') + '</tbody></table></div>'
      + '<div style="font-size:11.5px;color:#94A3B8;margin-top:6px;">Leave a box blank to use the default. 0 = never flag this agency for that category.</div>'
      + '<div style="display:flex;justify-content:flex-end;gap:8px;margin-top:12px;"><span id="us-msg" style="align-self:center;font-size:12.5px;"></span>'
      + '<button type="button" id="us-save" class="kt-btn kt-btn-primary">Save rates & thresholds</button></div>';

    body.innerHTML = h;

    body.querySelector('#us-days').addEventListener('change', function (e) { days = parseInt(e.target.value, 10) || 7; render(main); });
    body.querySelector('#us-save').addEventListener('click', async function (e) {
      var btn = e.currentTarget, msg = body.querySelector('#us-msg');
      var payload = { rates: {}, defaults: {}, agencies: {} };
      body.querySelectorAll('[data-rate]').forEach(function (i) { payload.rates[i.getAttribute('data-rate')] = i.value; });
      body.querySelectorAll('[data-def]').forEach(function (i) { payload.defaults[i.getAttribute('data-def')] = i.value; });
      body.querySelectorAll('[data-ag]').forEach(function (i) {
        var id = i.getAttribute('data-ag');
        (payload.agencies[id] = payload.agencies[id] || {})[i.getAttribute('data-cat')] = i.value.trim();
      });
      btn.disabled = true; msg.style.color = '#64748B'; msg.textContent = 'Saving…';
      try {
        var nd = await Api.put('/platform/usage-settings?days=' + days, payload);
        toast('Rates and thresholds saved', 'success');
        draw(main, body, nd);
      } catch (err) { btn.disabled = false; msg.style.color = '#B91C1C'; msg.textContent = (err && err.message) || 'Could not save'; }
    });
    body.querySelector('#us-test').addEventListener('click', async function (e) {
      var btn = e.currentTarget; btn.disabled = true;
      try { var r = await Api.post('/platform/usage-summary/test', {}); toast(r.message || 'Done', 'success'); }
      catch (err) { toast((err && err.message) || 'Could not send', 'error'); }
      btn.disabled = false;
    });
  }

  /* ── TEXT & CALL ALLOWANCE - SMS INCREMENTAL (2026-10-28) ───────────────────────────────────────────────
     Anthony: "monitor the number of texts and voice messages per agency and bill based on a threshold that exceeds
     - an add-on for SMS Incremental - $15.00 ... configurable ... defaults as 1,000 for text and voice". His
     choices: $15 per extra block the size of the allowance, per calendar month, every text and call in and out,
     and HE approves each one - "Add to next invoice" puts a one-off line on the agency's next KiddieTrac invoice;
     nothing is charged without that click. Server: App\Support\MessagingOverage. */
  var ovMonth = '';
  function monthLabel(m) { try { return new Date(+m.slice(0, 4), +m.slice(5, 7) - 1, 1).toLocaleDateString('en-CA', { month: 'long', year: 'numeric' }); } catch (e) { return m; } }
  function shiftMonth(m, by) { var d = new Date(+m.slice(0, 4), +m.slice(5, 7) - 1 + by, 1); return d.getFullYear() + '-' + ('0' + (d.getMonth() + 1)).slice(-2); }
  async function loadOverage(host, month) {
    if (!host) { return; }
    host.innerHTML = '<div class="kt-card" style="padding:14px 16px;color:#64748B;">Loading the text &amp; call allowance…</div>';
    try { drawOverage(host, await Api.get('/platform/messaging-overage' + (month ? '?month=' + month : ''))); }
    catch (e) { host.innerHTML = '<div class="kt-card" style="padding:14px 16px;color:#B91C1C;">' + esc((e && e.message) || 'Could not load the allowance') + '</div>'; }
  }
  function drawOverage(host, d) {
    ovMonth = d.month;
    var p = d.product || {}, unit = (p.unit_cents || 0) / 100, def = (d.settings || {}).default || {};
    var over = (d.rows || []).filter(function (r) { return r.blocks > 0 && !(r.charge); });
    var th = function (t, right) { return '<th style="text-align:' + (right ? 'right' : 'left') + ';padding:9px 10px;font-size:11px;font-weight:800;color:#64748B;text-transform:uppercase;letter-spacing:.4px;white-space:nowrap;">' + t + '</th>'; };
    var used = function (n, t, blocks) {
      var pct = t ? Math.min(100, Math.round(n / t * 100)) : 0;
      var col = blocks ? '#DC2626' : (pct >= 80 ? '#D97706' : '#0F9D6B');
      return '<td style="padding:8px 10px;min-width:150px;"><div style="display:flex;justify-content:space-between;gap:6px;font-size:12.5px;">'
        + '<b style="color:' + (blocks ? '#B91C1C' : '#0F172A') + ';">' + num(n) + '</b><span style="color:#94A3B8;">of ' + num(t) + '</span></div>'
        + '<div style="height:6px;background:#F1F5F9;border-radius:999px;margin-top:4px;overflow:hidden;"><div style="height:100%;width:' + pct + '%;background:' + col + ';border-radius:999px;"></div></div>'
        + (blocks ? '<div style="font-size:11px;color:#B91C1C;font-weight:700;margin-top:2px;">' + blocks + ' block' + (blocks === 1 ? '' : 's') + ' over</div>' : '') + '</td>';
    };
    var billing = function (b) {
      if (b.status === 'active' && b.next_invoice_at) { return '<span style="color:#475569;">Next invoice ' + esc(String(b.next_invoice_at).slice(0, 10)) + '</span>'; }
      return '<span style="color:#B45309;" title="Billing is not on for this agency, so an approved line waits until it is.">Not billed yet (' + esc(b.status || 'off') + ')</span>';
    };
    var action = function (r) {
      var c = r.charge;
      if (c && c.status === 'added') {
        return '<span style="color:#065F46;font-weight:800;">✓ ' + money(c.amount_cents / 100) + (c.on_invoice ? ' invoiced' : ' on next invoice') + '</span>'
          + (c.on_invoice ? '' : ' <button type="button" class="kt-btn" data-ov-undo="' + c.id + '" style="margin-left:6px;">Undo</button>');
      }
      if (c && c.status === 'waived') {
        return '<span style="color:#64748B;font-weight:700;">Waived</span> <button type="button" class="kt-btn" data-ov-undo="' + c.id + '" style="margin-left:6px;">Undo</button>';
      }
      if (!r.blocks) { return '<span style="color:#94A3B8;">Within allowance</span>'; }
      return '<button type="button" class="kt-btn kt-btn-primary" data-ov-charge="' + r.agency_id + '" data-ov-amt="' + (r.amount_cents / 100) + '" data-ov-name="' + esc(r.name) + '" data-ov-blocks="' + r.blocks + '">Add ' + money(r.amount_cents / 100) + ' to next invoice</button>'
        + ' <button type="button" class="kt-btn" data-ov-waive="' + r.agency_id + '" style="margin-left:4px;">Waive</button>';
    };
    var custom = function (r) {
      var c = r.custom || {};
      return '<input type="text" inputmode="numeric" data-kt-nofit="1" data-ov-ag="' + r.agency_id + '" data-ov-k="texts" value="' + (c.texts != null ? esc(c.texts) : '') + '" placeholder="' + esc(def.texts) + '" aria-label="Text allowance for ' + esc(r.name) + '" style="' + SMALL + 'width:78px;min-width:78px;"> '
        + '<input type="text" inputmode="numeric" data-kt-nofit="1" data-ov-ag="' + r.agency_id + '" data-ov-k="calls" value="' + (c.calls != null ? esc(c.calls) : '') + '" placeholder="' + esc(def.calls) + '" aria-label="Call allowance for ' + esc(r.name) + '" style="' + SMALL + 'width:78px;min-width:78px;">';
    };
    var h = '<div class="kt-card" style="padding:14px 16px;">'
      + '<div style="display:flex;justify-content:space-between;align-items:flex-start;gap:12px;flex-wrap:wrap;margin-bottom:10px;">'
      +   '<div style="max-width:700px;"><div style="font-weight:800;color:#0F172A;font-size:15px;">📶 Text &amp; call allowance · ' + esc(p.name || 'SMS Incremental') + ' <span style="font-size:11.5px;font-weight:700;color:#64748B;">' + esc(p.part_number || '') + '</span></div>'
      +   '<div style="font-size:12.5px;color:#64748B;margin-top:3px;">Every text and call that went through, in and out, per calendar month. Over the allowance, each extra block the size of the allowance is <b>' + money(unit) + '</b> '
      +   '(1,000 allowed and 2,400 used = 2 blocks). Nothing is charged until you press <b>Add to next invoice</b>.</div></div>'
      +   '<div style="display:flex;align-items:center;gap:6px;">'
      +     '<button type="button" class="kt-btn" data-ov-month="-1" aria-label="Previous month">‹</button>'
      +     '<b style="font-size:13.5px;min-width:120px;text-align:center;">' + esc(monthLabel(d.month)) + '</b>'
      +     '<button type="button" class="kt-btn" data-ov-month="1" aria-label="Next month">›</button></div></div>'
      + (over.length
        ? '<div style="background:#FEF2F2;border:1px solid #FECACA;color:#991B1B;border-radius:10px;padding:9px 12px;margin-bottom:10px;font-size:13px;"><b>' + over.length + ' agenc' + (over.length === 1 ? 'y is' : 'ies are') + ' over the allowance in ' + esc(monthLabel(d.month)) + ':</b> '
          + over.map(function (r) { return esc(r.name) + ' (' + money(r.amount_cents / 100) + ')'; }).join(' · ') + '</div>'
        : '<div style="background:#ECFDF5;border:1px solid #A7F3D0;color:#065F46;border-radius:10px;padding:9px 12px;margin-bottom:10px;font-size:13px;">✅ No agency is waiting on an overage decision for ' + esc(monthLabel(d.month)) + '.</div>')
      + '<div style="display:flex;gap:16px;flex-wrap:wrap;align-items:center;margin-bottom:10px;font-size:13px;color:#334155;">'
      +   '<label>Default texts / month <input type="text" inputmode="numeric" data-kt-nofit="1" data-ov-def="texts" value="' + esc(def.texts) + '" style="' + INPUT + '"></label>'
      +   '<label>Default calls / month <input type="text" inputmode="numeric" data-kt-nofit="1" data-ov-def="calls" value="' + esc(def.calls) + '" style="' + INPUT + '"></label></div>'
      + '<div style="overflow-x:auto;border:1px solid #E7EDF3;border-radius:12px;">'
      + '<table data-kt-filtered="1" data-kt-bulk="off" style="width:100%;border-collapse:collapse;font-size:13px;min-width:960px;"><thead><tr style="background:#F8FAFC;">'
      + th('Agency') + th('Texts') + th('Calls') + th('Billing') + th('Charge') + th('Own allowance (texts · calls)', 1) + '</tr></thead><tbody>'
      + (d.rows || []).map(function (r) {
        return '<tr style="border-top:1px solid #F1F5F9;">'
          + '<td style="padding:8px 10px;font-weight:700;color:#0F172A;">' + esc(r.name) + '</td>'
          + used(r.texts, r.text_threshold, r.text_blocks) + used(r.calls, r.call_threshold, r.call_blocks)
          + '<td style="padding:8px 10px;font-size:12.5px;white-space:nowrap;">' + billing(r.billing || {}) + '</td>'
          + '<td style="padding:8px 10px;white-space:nowrap;">' + action(r) + '</td>'
          + '<td style="padding:8px 10px;text-align:right;white-space:nowrap;">' + custom(r) + '</td></tr>';
      }).join('') + '</tbody></table></div>'
      + '<div style="display:flex;justify-content:space-between;align-items:center;gap:8px;margin-top:10px;flex-wrap:wrap;">'
      +   '<span style="font-size:11.5px;color:#94A3B8;">Blank = the default allowance. The price is the ' + esc(p.name || 'SMS Incremental') + ' add-on in Plans &amp; pricing.</span>'
      +   '<span><span id="ov-msg" style="font-size:12.5px;margin-right:8px;"></span><button type="button" class="kt-btn kt-btn-primary" id="ov-save">Save allowances</button></span></div>'
      + '</div>';
    host.innerHTML = h;

    var refresh = function (nd) { drawOverage(host, nd); };
    var fail = function (err) { toast((err && err.message) || 'That did not go through', 'error'); };
    host.querySelectorAll('[data-ov-month]').forEach(function (b) {
      b.addEventListener('click', function () { loadOverage(host, shiftMonth(ovMonth, +b.getAttribute('data-ov-month'))); });
    });
    host.querySelector('#ov-save').addEventListener('click', async function (e) {
      var btn = e.currentTarget, msg = host.querySelector('#ov-msg');
      var payload = { 'default': {}, agencies: {} };
      host.querySelectorAll('[data-ov-def]').forEach(function (i) { payload['default'][i.getAttribute('data-ov-def')] = i.value.trim(); });
      host.querySelectorAll('[data-ov-ag]').forEach(function (i) {
        var id = i.getAttribute('data-ov-ag');
        (payload.agencies[id] = payload.agencies[id] || {})[i.getAttribute('data-ov-k')] = i.value.trim();
      });
      btn.disabled = true; msg.style.color = '#64748B'; msg.textContent = 'Saving…';
      try {
        await Api.put('/platform/messaging-overage/settings', payload);
        toast('Allowances saved', 'success');
        loadOverage(host, ovMonth);
      } catch (err) { btn.disabled = false; msg.style.color = '#B91C1C'; msg.textContent = (err && err.message) || 'Could not save'; }
    });
    host.querySelectorAll('[data-ov-charge]').forEach(function (b) {
      b.addEventListener('click', async function () {
        var amt = money(+b.getAttribute('data-ov-amt')), name = b.getAttribute('data-ov-name'), blocks = b.getAttribute('data-ov-blocks');
        var ok = KT.confirm ? await KT.confirm({ title: 'Add ' + amt + ' to ' + name + '\'s next invoice?',
          description: blocks + ' block' + (blocks === '1' ? '' : 's') + ' of ' + (p.name || 'SMS Incremental') + ' for ' + monthLabel(ovMonth) + ', at ' + money(unit) + ' each. It goes on their next KiddieTrac invoice; you can undo it until that invoice is raised.',
          okLabel: 'Add to next invoice' }) : true;
        if (!ok) { return; }
        b.disabled = true;
        try { refresh(await Api.post('/platform/messaging-overage/charge', { agency_id: +b.getAttribute('data-ov-charge'), month: ovMonth })); toast(amt + ' added to the next invoice', 'success'); }
        catch (err) { b.disabled = false; fail(err); }
      });
    });
    host.querySelectorAll('[data-ov-waive]').forEach(function (b) {
      b.addEventListener('click', async function () {
        b.disabled = true;
        try { refresh(await Api.post('/platform/messaging-overage/waive', { agency_id: +b.getAttribute('data-ov-waive'), month: ovMonth })); toast('Waived for ' + monthLabel(ovMonth), 'success'); }
        catch (err) { b.disabled = false; fail(err); }
      });
    });
    host.querySelectorAll('[data-ov-undo]').forEach(function (b) {
      b.addEventListener('click', async function () {
        b.disabled = true;
        try { refresh(await Api.delete('/platform/messaging-overage/' + b.getAttribute('data-ov-undo'))); toast('Taken back', 'success'); }
        catch (err) { b.disabled = false; fail(err); }
      });
    });
  }

  KT.Shell.registerScreen('platform_admin:usage-spend', render);
})(window);
