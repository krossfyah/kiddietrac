/* ═══════════════════════════════════════════════════════════════════
   KiddieTrac — Licences (2026-10-24)

   Anthony: "the plans and auditing the features based on the plans when i sell the service
   needs to have a licensing system with expiry/renewal and tied back to the billing as well
   ... add part#s to each plan and addon's ... for tracking and reporting".

   #licences (platform admin): every agency's plans and add-ons with part number, price, term,
     expiry, auto-renew and status; add / change / renew / cancel; sales by part number.
   #licence (agency admin): their own licence, read-only.
   Server: App\Support\Licences, LicenceController.
   ═══════════════════════════════════════════════════════════════════ */
(function (w) {
  'use strict';
  var KT = w.KT = w.KT || {};

  function el(tag, attrs, kids) {
    var e = document.createElement(tag);
    Object.keys(attrs || {}).forEach(function (k) {
      if (k === 'style') e.style.cssText = attrs[k];
      else if (k.slice(0, 2) === 'on' && typeof attrs[k] === 'function') e.addEventListener(k.slice(2), attrs[k]);
      else if (k === 'value') e.value = attrs[k];
      else e.setAttribute(k, attrs[k]);
    });
    [].concat(kids || []).forEach(function (c) { if (c != null) e.appendChild(typeof c === 'string' ? document.createTextNode(c) : c); });
    return e;
  }
  function money(v, cur) { var n = Number(v || 0); return '$' + n.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 }) + (cur && cur !== 'CAD' ? ' ' + cur : ''); }
  function day(d) { if (!d) return '—'; try { return new Date(String(d).slice(0, 10) + 'T12:00:00').toLocaleDateString(undefined, { month: 'short', day: 'numeric', year: 'numeric' }); } catch (e) { return d; } }
  function daysTo(d) { if (!d) return null; var t = (KT.agencyToday ? KT.agencyToday() : new Date().toISOString().slice(0, 10)); return Math.round((new Date(String(d).slice(0, 10) + 'T12:00:00') - new Date(t + 'T12:00:00')) / 86400000); }
  var IV = { monthly: '/month', quarterly: '/quarter', annual: '/year' };
  var ST = { active: ['Active', '#166534', '#DCFCE7'], grace: ['Grace', '#92400E', '#FEF3C7'], expired: ['Expired', '#991B1B', '#FEE2E2'], cancelled: ['Cancelled', '#475569', '#F1F5F9'] };
  function chip(st) { var s = ST[st] || ST.active; return el('span', { style: 'display:inline-block;font-size:11px;font-weight:800;padding:2px 9px;border-radius:999px;color:' + s[1] + ';background:' + s[2] }, [s[0]]); }
  function part(pn) { return el('span', { style: 'font-family:ui-monospace,Menlo,Consolas,monospace;font-size:11.5px;font-weight:800;color:#1F6080;background:#EAF3F6;border-radius:6px;padding:2px 7px;white-space:nowrap' }, [pn || '—']); }
  function hero(t, s, i) { return el('div', { class: 'kt-hero kt-hero-auto kt-banner-fx' }, [el('h1', {}, [(i ? i + ' ' : '') + t]), el('div', { class: 'kt-hero-sub' }, [s])]); }
  function toast(m, kind) { if (KT.Dom && KT.Dom.toast) KT.Dom.toast(m, kind || 'success'); }
  var CSS = '.lic-wrap{padding:16px 20px 28px;max-width:1800px;box-sizing:border-box}'
    + '.lic-filter{display:flex;flex-wrap:wrap;gap:10px;align-items:flex-end;margin:14px 0}'
    + '.lic-filter label{display:flex;flex-direction:column;gap:4px;font-size:11px;font-weight:800;letter-spacing:.5px;text-transform:uppercase;color:#64748B}'
    + '.lic-filter select{height:38px;padding:0 30px 0 10px;border:1px solid #CBD5E1;border-radius:9px;font-size:14px;background-color:#fff}'
    + '.lic-btn{height:38px;padding:0 14px;border-radius:9px;font-size:13.5px;font-weight:700;cursor:pointer;border:1px solid #CFE3EB;background:#fff;color:#1F6080;white-space:nowrap}'
    + '.lic-btn-p{background:linear-gradient(135deg,#1F6080,#2c7894);color:#fff;border:0}'
    + '.lic-card{background:#fff;border:1px solid #E6EBF1;border-radius:14px;padding:14px 16px;margin-bottom:14px}'
    + '.lic-tbl{width:100%;border-collapse:collapse;font-size:13.5px}.lic-tbl th{text-align:left;font-size:11px;letter-spacing:.5px;text-transform:uppercase;color:#64748B;padding:8px 10px;border-bottom:2px solid #E2E8F0}'
    + '.lic-tbl td{padding:9px 10px;border-bottom:1px solid #F1F5F9;vertical-align:top}'
    + '.lic-tiles{display:grid;grid-template-columns:repeat(auto-fill,minmax(170px,1fr));gap:10px;margin:12px 0}'
    + '.lic-tile{background:#fff;border:1px solid #E6EBF1;border-radius:12px;padding:12px}.lic-tile b{display:block;font-size:22px;color:#0F172A}.lic-tile span{font-size:12px;color:#64748B;font-weight:700}';
  var CSS2 = '.lic-h{font-size:12px;font-weight:800;letter-spacing:.6px;text-transform:uppercase;color:#475569;margin:22px 0 10px}'
    + '.lic3 .lic-band{position:relative;overflow:hidden;display:grid;grid-template-columns:minmax(0,1.1fr) minmax(0,1fr);gap:28px;align-items:center;padding:26px 28px;border-radius:22px;color:#fff;'
    + 'background:radial-gradient(60% 120% at 0% 0%,rgba(255,255,255,.35) 0%,rgba(255,255,255,0) 60%),radial-gradient(50% 90% at 100% 100%,rgba(244,114,182,.55) 0%,rgba(244,114,182,0) 70%),linear-gradient(115deg,#06B6D4 0%,#14B8A6 30%,#3B82F6 68%,#8B5CF6 100%);'
    + 'box-shadow:0 22px 44px -26px rgba(59,130,246,.75)}'
    + '.lic3 .lic-band::before{content:"";position:absolute;inset:0;background-image:radial-gradient(rgba(255,255,255,.22) 1px,transparent 1.2px);background-size:18px 18px;mask-image:linear-gradient(90deg,transparent 0%,#000 60%);-webkit-mask-image:linear-gradient(90deg,transparent 0%,#000 60%);pointer-events:none}'
    + '.lic3 .lic-band::after{content:"";position:absolute;width:260px;height:260px;right:-60px;top:-110px;border-radius:50%;background:rgba(255,255,255,.18);filter:blur(2px);pointer-events:none}'
    + '.lic3 .lic-band>div{position:relative;z-index:1}'
    + '.lic3 .lic-band-r{background:rgba(255,255,255,.16);border:1px solid rgba(255,255,255,.35);border-radius:16px;padding:16px 18px;backdrop-filter:blur(6px);-webkit-backdrop-filter:blur(6px)}'
    + '.lic3 .lic-plan{text-shadow:0 2px 10px rgba(15,23,42,.18)}'
    + '@media (max-width:900px){.lic3 .lic-band{grid-template-columns:1fr}}'
    + '.lic-eye{font-size:11px;font-weight:800;letter-spacing:1.6px;text-transform:uppercase;color:rgba(255,255,255,.9)}'
    + '.lic-plan{display:flex;align-items:center;gap:12px;flex-wrap:wrap;font-size:30px;font-weight:800;line-height:1.15;margin:6px 0 4px}'
    + '.lic-st{font-size:12px;font-weight:800;padding:3px 11px;border-radius:999px;background:rgba(255,255,255,.16);color:#fff;letter-spacing:.2px}'
    + '.lic-st-active{background:#22C55E}.lic-st-trial{background:#0EA5E9}.lic-st-grace,.lic-st-past_due{background:#F59E0B}.lic-st-expired,.lic-st-suspended,.lic-st-cancelled{background:#EF4444}'
    + '.lic-desc{font-size:14px;color:rgba(255,255,255,.82)}'
    + '.lic-pills{display:flex;gap:6px;flex-wrap:wrap;margin-top:12px}.lic-pill{font-size:12px;font-weight:700;padding:3px 10px;border-radius:999px;background:rgba(255,255,255,.14);border:1px solid rgba(255,255,255,.25)}'
    + '.lic-tl-h{display:flex;justify-content:space-between;gap:10px;flex-wrap:wrap;font-size:12.5px;color:rgba(255,255,255,.75);margin-bottom:8px}.lic-tl-h b{color:#fff;font-size:13.5px}'
    + '.lic-tl{position:relative;height:10px;border-radius:99px;background:rgba(255,255,255,.28)}'
    + '.lic-tl i{position:absolute;left:0;top:0;bottom:0;border-radius:99px;background:linear-gradient(90deg,#FFFFFF,#FDE68A)}'
    + '.lic-tl em{position:absolute;top:50%;width:18px;height:18px;margin:-9px 0 0 -9px;border-radius:50%;background:#fff;box-shadow:0 0 0 5px rgba(255,255,255,.35),0 4px 10px rgba(15,23,42,.25)}'
    + '.lic-tl-x{display:flex;justify-content:space-between;font-size:11.5px;color:rgba(255,255,255,.7);margin-top:6px}'
    + '.lic-facts3{display:grid;grid-template-columns:repeat(auto-fill,minmax(120px,1fr));gap:10px 16px;margin-top:16px;padding-top:14px;border-top:1px solid rgba(255,255,255,.3)}'
    + '.lic-facts3 span{display:block;font-size:10.5px;font-weight:800;letter-spacing:.6px;text-transform:uppercase;color:rgba(255,255,255,.82)}.lic-facts3 b{font-size:13.5px}'
    + '.lic-warn{margin-top:10px;padding:10px 14px;border-radius:12px;background:#FFFBEB;color:#92400E;font-weight:700;font-size:13.5px}'
    + '.lic-rings{display:flex;flex-wrap:wrap;gap:12px 36px;align-items:center;padding:4px 2px}'
    + '.lic-ring{display:flex;align-items:center;gap:14px;min-width:240px}.lic-ring b{display:block;font-size:15px;color:#0F172A}.lic-ring span{font-size:13px;color:#475569}'
    + '.lic-gauge{line-height:0}'
    + '.lic-roster{border-top:1px solid #E2E8F0}'
    + '.lic-person{display:flex;align-items:center;gap:12px;padding:10px 4px;border-bottom:1px solid #EEF2F6}'
    + '.lic-av{width:36px;height:36px;border-radius:50%;object-fit:cover;flex:0 0 auto;display:inline-flex;align-items:center;justify-content:center;background:linear-gradient(135deg,#1DB9C3,#1F6080);color:#fff;font-weight:800;font-size:13px}'
    + '.lic-pn{font-weight:800;color:#0F172A;font-size:14px}.lic-pe{font-size:12.5px;color:#64748B;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}'
    + '.lic-px{flex:1 1 200px;font-size:13px;color:#475569;min-width:0}'
    + '@media (max-width:700px){.lic-px{display:none}}'
    + '.lic-role{font-size:11.5px;font-weight:800;padding:3px 10px;border-radius:999px;white-space:nowrap}'
    + '.lic-role-owner{background:#FEF3C7;color:#92400E}.lic-role-admin{background:#E0F2FE;color:#075985}.lic-role-director{background:#EDE9FE;color:#5B21B6}'
    + '.lic-ribbon{display:grid;grid-template-columns:repeat(auto-fill,minmax(150px,1fr));gap:10px}'
    + '.lic-g{position:relative;overflow:hidden;border-radius:14px;padding:12px 14px 12px 14px;border:1px solid rgba(15,23,42,.06)}'
    + '.lic-g i{position:absolute;right:10px;top:8px;font-style:normal;font-size:22px;opacity:.55}'
    + '.lic-g b{display:block;font-size:26px;font-weight:800;color:#0F172A;line-height:1.1}.lic-g span{font-size:12.5px;font-weight:700;color:#475569}'
    + '.lic-g-sky{background:linear-gradient(135deg,#E0F2FE,#F0F9FF)}.lic-g-teal{background:linear-gradient(135deg,#CCFBF1,#F0FDFA)}'
    + '.lic-g-mint{background:linear-gradient(135deg,#DCFCE7,#F0FDF4)}.lic-g-amber{background:linear-gradient(135deg,#FEF3C7,#FFFBEB)}'
    + '.lic-g-peach{background:linear-gradient(135deg,#FFEDD5,#FFF7ED)}.lic-g-rose{background:linear-gradient(135deg,#FCE7F3,#FDF2F8)}'
    + '.lic-g-violet{background:linear-gradient(135deg,#EDE9FE,#F5F3FF)}.lic-g-indigo{background:linear-gradient(135deg,#E0E7FF,#EEF2FF)}'
    + '.lic-g-slate{background:linear-gradient(135deg,#E2E8F0,#F8FAFC)}.lic-g-lime{background:linear-gradient(135deg,#ECFCCB,#F7FEE7)}'
    + '.lic-bars{display:flex;flex-direction:column;gap:8px;max-width:720px}'
    + '.lic-bar{display:grid;grid-template-columns:150px 1fr 60px;gap:10px;align-items:center;font-size:13px;color:#334155}'
    + '.lic-bar>div{height:10px;border-radius:99px;background:#EEF2F6;overflow:hidden}.lic-bar i{display:block;height:100%;border-radius:99px;background:linear-gradient(90deg,#1DB9C3,#1F6080)}'
    + '.lic-bar b{text-align:right;color:#0F172A}'
    + '.lic-tbl{width:100%;border-collapse:collapse;font-size:13.5px}'
    + '.lic-tbl th{text-align:left;font-size:11px;letter-spacing:.5px;text-transform:uppercase;color:#64748B;padding:8px 10px;border-bottom:2px solid #E2E8F0}'
    + '.lic-tbl td{padding:10px;border-bottom:1px solid #EEF2F6;vertical-align:middle}'
    + '.lic-spec{columns:3 300px;column-gap:28px}'
    + '.lic-spec section{break-inside:avoid;margin:0 0 16px}'
    + '.lic-spec h4{display:flex;justify-content:space-between;margin:0 0 4px;padding-bottom:5px;border-bottom:2px solid #0A1E55;font-size:12px;letter-spacing:.6px;text-transform:uppercase;color:#0A1E55}'
    + '.lic-spec h4 em{font-style:normal;color:#64748B}'
    + '.lic-feat-row{display:flex;gap:8px;align-items:baseline;padding:4px 0;font-size:13.5px;color:#0F172A;border-bottom:1px dotted #E2E8F0}'
    + '.lic-feat-row.off{color:#94A3B8}.lic-tick{font-weight:900;color:#16A34A;width:14px}.lic-feat-row.off .lic-tick{color:#CBD5E1}'
    + '.lic-need{font-size:11px;font-weight:800;color:#7C3AED;white-space:nowrap}';
  function css() { if (!document.getElementById('kt-lic-css')) { var s = document.createElement('style'); s.id = 'kt-lic-css'; s.textContent = CSS + CSS2; document.head.appendChild(s); } }

  /* ── platform: every licence ─────────────────────────────────────── */
  var flt = { agency: '', status: '' };
  async function renderPlatform(main) {
    css();
    main.innerHTML = '';
    main.appendChild(hero('Licences', 'Plans and add-ons per agency: part number, term, expiry and renewal. Renewals bill through Platform → Accounting AR/AP when billing is on.', '🔑'));
    var wrap = el('div', { class: 'lic-wrap' }); main.appendChild(wrap);
    wrap.appendChild(el('div', { style: 'padding:20px;color:#64748B' }, ['Loading…']));
    var d;
    try { d = await KT.Api.get('/platform/licences' + (flt.agency ? '?agency_id=' + flt.agency : '')); }
    catch (e) { wrap.innerHTML = ''; wrap.appendChild(el('div', { class: 'lic-card', style: 'color:#B91C1C' }, [(e && e.message) || 'Could not load licences'])); return; }
    wrap.innerHTML = '';
    var rows = (d.licences || []).filter(function (l) { return !flt.status || l.status === flt.status || (flt.status === 'soon' && ['active', 'grace'].indexOf(l.status) > -1 && daysTo(l.expires_at) !== null && daysTo(l.expires_at) <= 30); });

    // tiles
    var live = (d.licences || []).filter(function (l) { return l.status === 'active' || l.status === 'grace'; });
    var mrr = (d.summary || []).reduce(function (n, s) { return n + (s.mrr_cents || 0); }, 0);
    var soon = live.filter(function (l) { var x = daysTo(l.expires_at); return x !== null && x <= 30; }).length;
    var grace = live.filter(function (l) { return l.status === 'grace'; }).length;
    wrap.appendChild(el('div', { class: 'lic-tiles' }, [
      el('div', { class: 'lic-tile' }, [el('b', {}, [String(live.length)]), el('span', {}, ['Live licences'])]),
      el('div', { class: 'lic-tile' }, [el('b', {}, [money(mrr / 100)]), el('span', {}, ['Monthly recurring'])]),
      el('div', { class: 'lic-tile' }, [el('b', {}, [String(soon)]), el('span', {}, ['Renew within 30 days'])]),
      el('div', { class: 'lic-tile', style: grace ? 'border-color:#FCD34D;background:#FFFBEB' : '' }, [el('b', {}, [String(grace)]), el('span', {}, ['In grace (needs attention)'])]),
    ]));

    // filters + add
    var agSel = el('select', {}, [el('option', { value: '' }, ['All agencies'])].concat((d.agencies || []).map(function (a) { var o = el('option', { value: String(a.id) }, [a.name]); if (String(a.id) === String(flt.agency)) o.selected = true; return o; })));
    var stSel = el('select', {}, [['', 'Any status'], ['active', 'Active'], ['grace', 'Grace'], ['soon', 'Renews within 30 days'], ['expired', 'Expired'], ['cancelled', 'Cancelled']].map(function (o) { var x = el('option', { value: o[0] }, [o[1]]); if (o[0] === flt.status) x.selected = true; return x; }));
    agSel.addEventListener('change', function () { flt.agency = agSel.value; renderPlatform(main); });
    stSel.addEventListener('change', function () { flt.status = stSel.value; renderPlatform(main); });
    wrap.appendChild(el('div', { class: 'lic-filter' }, [el('label', {}, ['Agency', agSel]), el('label', {}, ['Status', stSel]),
      el('span', { style: 'flex:1' }), el('button', { type: 'button', class: 'lic-btn lic-btn-p', 'data-kt-iconized': '1', onclick: function () { addDialog(d, main); } }, ['＋ Add licence'])]));

    // table
    var card = el('div', { class: 'lic-card', style: 'overflow-x:auto' });
    if (!rows.length) card.appendChild(el('div', { style: 'color:#64748B;padding:10px' }, ['No licences' + (flt.agency || flt.status ? ' match these filters.' : ' yet. Approving a proposal creates them, or add one here.')]));
    else {
      /* ONE LICENCE PER AGENCY (2026-10-25): the plan carries the term and every add-on follows it, so
         renewing, the term and cancelling are per agency; add-ons are added and removed one by one. */
      var byAg = {}, order = [];
      rows.forEach(function (l) { if (!byAg[l.agency_id]) { byAg[l.agency_id] = []; order.push(l.agency_id); } byAg[l.agency_id].push(l); });
      var tb = el('tbody');
      order.forEach(function (aid) {
        var ls = byAg[aid].slice().sort(function (a, b) { return (a.kind === 'plan' ? 0 : 1) - (b.kind === 'plan' ? 0 : 1) || (a.status === 'cancelled') - (b.status === 'cancelled'); });
        var plan = ls.filter(function (x) { return x.kind === 'plan' && x.status !== 'cancelled'; })[0];
        var liveN = ls.filter(function (x) { return x.status !== 'cancelled'; }).length;
        var total = ls.filter(function (x) { return x.status !== 'cancelled'; }).reduce(function (n, x) { return n + x.unit_price * x.qty; }, 0);
        var B = 'height:30px;padding:0 10px;font-size:12px';
        var dd0 = plan ? daysTo(plan.expires_at) : null;
        var termTxt = plan ? ((plan.status === 'grace' ? 'Grace until ' + day(plan.grace_until) : (plan.auto_renew ? 'Renews ' : 'Ends ') + day(plan.expires_at) + (dd0 !== null && dd0 >= 0 && dd0 <= 30 ? ' (' + dd0 + 'd)' : ''))
          + ' · billed ' + ({ monthly: 'monthly', quarterly: 'quarterly', semiannual: 'semi-annually', annual: 'yearly' }[plan.interval] || plan.interval) + (plan.term_months ? ' · ' + plan.term_months + '-month term' : '')) : 'No plan: add one to license this agency';
        tb.appendChild(el('tr', { style: 'background:#F1F7FB' }, [el('td', { colspan: '7', style: 'padding:10px 12px;border-top:2px solid #D7E6F0' }, [el('div', { style: 'display:flex;gap:10px;align-items:center;flex-wrap:wrap' }, [
          el('div', { style: 'flex:1 1 260px;min-width:0' }, [
            el('div', { style: 'display:inline-block;font-weight:800;font-size:14.5px;color:#4C1D95;padding:3px 12px;margin:0 0 3px -4px;border-radius:8px;background:linear-gradient(90deg,#EDE9FE 0%,#F5E8FF 45%,rgba(252,231,243,.55) 75%,rgba(252,231,243,0) 100%)' }, [(ls[0].agency_name || ('#' + aid))]),
            el('div', { style: 'font-size:12px;color:#475569' }, [termTxt + (plan ? ' · ' + liveN + ' line' + (liveN === 1 ? '' : 's') + ' · ' + money(total, plan.currency) + (IV[plan.interval] || '') : '')]),
          ]),
          plan ? chip(plan.status) : null,
          plan && (plan.source === 'trial' || plan.source === 'comp') ? el('span', { style: 'display:inline-block;font-size:11px;font-weight:800;padding:2px 9px;border-radius:999px;' + (plan.source === 'trial' ? 'color:#92400E;background:#FEF3C7' : 'color:#6D28D9;background:#EDE9FE') }, [plan.source === 'trial' ? 'Trial' : 'Complimentary']) : null,
          plan && plan.source === 'trial' ? el('button', { type: 'button', class: 'lic-btn lic-btn-p', 'data-kt-iconized': '1', style: B, onclick: async function () {
            if (!(await KT.confirm({ title: 'Convert this trial to a paid licence?', description: 'Every line takes its catalogue price (or the price you set with Price) and renews from its end date. Billing raises the invoices.', okLabel: 'Convert to paid' }))) return;
            try { await KT.Api.post('/platform/licences/' + plan.id + '/convert', { to: 'paid' }); toast('Converted to paid'); renderPlatform(main); } catch (e) { toast((e && e.message) || 'Could not convert', 'error'); }
          } }, ['Convert to paid']) : null,
          plan && plan.source !== 'trial' ? el('button', { type: 'button', class: 'lic-btn lic-btn-p', 'data-kt-iconized': '1', style: B, onclick: async function () {
            if (!(await KT.confirm({ title: 'Renew the whole licence?', description: 'Every line (' + liveN + ') renews together to one new end date, from ' + day(plan.expires_at) + '. No invoice is raised here; billing raises its own.', okLabel: 'Renew all' }))) return;
            try { var r = await KT.Api.post('/platform/licences/' + plan.id + '/renew', {}); toast('Renewed to ' + day(r.expires_at)); renderPlatform(main); } catch (e) { toast((e && e.message) || 'Could not renew', 'error'); }
          } }, ['Renew all']) : null,
          plan ? el('button', { type: 'button', class: 'lic-btn', 'data-kt-iconized': '1', style: B, onclick: function () { termDialog(plan, liveN, main); } }, ['Term & renewal']) : null,
          el('button', { type: 'button', class: 'lic-btn', 'data-kt-iconized': '1', style: B, onclick: function () { flt.addFor = aid; addDialog(d, main, aid, !!plan); } }, [plan ? '＋ Add add-on' : '＋ Add plan']),
          plan ? el('button', { type: 'button', class: 'lic-btn', 'data-kt-iconized': '1', style: B, onclick: async function () {
            if (!(await KT.confirm({ title: 'Cancel ' + (ls[0].agency_name || 'this agency') + '\'s licence?', description: 'The plan and every add-on are cancelled and their features switch off straight away - except invoicing and autopay, which run to the end of the period already paid. They stay listed as cancelled.', okLabel: 'Cancel licence', tone: 'danger' }))) return;
            try { var res = await KT.Api.patch('/platform/licences/' + plan.id, { status: 'cancelled' }); toast('Licence cancelled'); await offerReissue(res, 'the cancelled licence'); renderPlatform(main); } catch (e) { toast((e && e.message) || 'Could not cancel', 'error'); }
          } }, ['Cancel licence']) : null,
        ])])]));
        ls.forEach(function (l) {
        var dd = daysTo(l.expires_at);
        var when = l.status === 'grace' ? 'Grace until ' + day(l.grace_until) : (l.expires_at ? (l.auto_renew ? 'Renews ' : 'Ends ') + day(l.expires_at) + (dd !== null && dd >= 0 && dd <= 30 ? ' (' + dd + 'd)' : '') : '—');
        var acts = el('div', { style: 'display:flex;gap:6px;flex-wrap:wrap' }, [
          l.status !== 'cancelled' ? el('button', { type: 'button', class: 'lic-btn', 'data-kt-iconized': '1', style: 'height:30px;padding:0 10px;font-size:12px', onclick: function () { editDialog(l, main); } }, ['Price']) : null,
          l.kind === 'addon' && l.status !== 'cancelled' ? el('button', { type: 'button', class: 'lic-btn', 'data-kt-iconized': '1', style: 'height:30px;padding:0 10px;font-size:12px', onclick: async function () {
            if (!(await KT.confirm({ title: 'Remove ' + l.name + '?', description: 'Its features switch off for ' + (l.agency_name || 'the agency') + ' straight away. The rest of the licence is unchanged.', okLabel: 'Remove add-on', tone: 'danger' }))) return;
            try { var res = await KT.Api.patch('/platform/licences/' + l.id, { status: 'cancelled' }); toast(l.name + ' removed'); await offerReissue(res, l.name); renderPlatform(main); } catch (e) { toast((e && e.message) || 'Could not remove', 'error'); }
          } }, ['Remove']) : null,
        ]);
        tb.appendChild(el('tr', {}, [
          el('td', { style: 'color:#94A3B8;font-size:12px;padding-left:22px' }, [l.kind === 'plan' ? 'Plan' : 'Add-on']),
          el('td', {}, [part(l.part_number)]),
          el('td', {}, [el('div', { style: 'font-weight:700' }, [l.name]), el('div', { style: 'font-size:11.5px;color:#64748B' }, [l.kind === 'plan' ? 'Plan · ' + (l.plan_code || '') : 'Add-on' + (l.feature_keys && l.feature_keys.length ? ' · ' + l.feature_keys.join(', ') : '')])]),
          el('td', { style: 'white-space:nowrap' }, [money(l.unit_price * l.qty, l.currency) + (IV[l.interval] || '') + (l.qty !== 1 ? ' (' + l.qty + '×)' : '')]),
          el('td', { style: 'white-space:nowrap' }, [when, l.auto_renew ? null : el('div', { style: 'font-size:11px;color:#B45309' }, ['Not renewing'])]),
          el('td', {}, [chip(l.status)]),
          el('td', {}, [acts]),
        ]));
        });
      });
      card.appendChild(el('table', { class: 'lic-tbl', 'data-kt-no-filter': '1', 'data-kt-no-kebab': '1' }, [el('thead', {}, [el('tr', {}, ['', 'Part #', 'Item', 'Price', 'Term', 'Status', ''].map(function (h) { return el('th', {}, [h]); }))]), tb]));
    }
    wrap.appendChild(card);

    // sales by part number
    var sum = d.summary || [];
    var sc = el('div', { class: 'lic-card', style: 'overflow-x:auto' }, [el('div', { style: 'font-weight:800;font-size:15px;margin-bottom:8px' }, ['📊 Sales by part number'])]);
    if (!sum.length) sc.appendChild(el('div', { style: 'color:#64748B' }, ['Nothing licensed or invoiced by part number yet.']));
    else sc.appendChild(el('table', { class: 'lic-tbl', 'data-kt-no-filter': '1', 'data-kt-no-kebab': '1' }, [
      el('thead', {}, [el('tr', {}, ['Part #', 'Item', 'Live', 'Monthly recurring', 'Invoiced this year', 'Invoiced all time'].map(function (h) { return el('th', {}, [h]); }))]),
      el('tbody', {}, sum.map(function (s) { return el('tr', {}, [el('td', {}, [part(s.part_number)]), el('td', {}, [s.name || '']), el('td', {}, [String(s.live || 0)]), el('td', {}, [money((s.mrr_cents || 0) / 100)]), el('td', {}, [money((s.invoiced_ytd_cents || 0) / 100)]), el('td', {}, [money((s.invoiced_cents || 0) / 100)])]); })),
    ]));
    wrap.appendChild(sc);

    /* SUBTABS (2026-10-29, Anthony: "licenses section add subtab for sales by part#"). The sales
       table sat under the licence list on one long page; it is its own tab now. Remembered for the
       session in flt.tab. */
    var paneL = el('div'), paneS = el('div');
    [].slice.call(wrap.children).forEach(function (c) { (c === sc ? paneS : paneL).appendChild(c); });
    var TABS = [['licences', '🔑 Licences'], ['sales', '📊 Sales by part #']];
    var strip = el('div', { class: 'kt-subtabs lic-tabs', role: 'tablist', style: 'display:flex;gap:4px;flex-wrap:wrap;border-bottom:1px solid #E5E7EB;margin:0 0 14px;' });
    function show(k) {
      flt.tab = k;
      paneL.style.display = k === 'licences' ? '' : 'none';
      paneS.style.display = k === 'sales' ? '' : 'none';
      [].slice.call(strip.children).forEach(function (b) {
        var on = b.getAttribute('data-tab') === k;
        b.setAttribute('aria-selected', on ? 'true' : 'false');
        if (on) b.setAttribute('aria-current', 'true'); else b.removeAttribute('aria-current');
        b.style.color = on ? '#1F6080' : '#64748B';
        b.style.borderBottomColor = on ? '#1F6080' : 'transparent';
      });
    }
    TABS.forEach(function (t) {
      strip.appendChild(el('button', { type: 'button', role: 'tab', 'data-tab': t[0], 'data-kt-inpage': '1', 'data-kt-iconized': '1',
        style: 'background:none;border:0;border-bottom:2px solid transparent;padding:9px 14px;font-size:14px;font-weight:700;cursor:pointer;white-space:nowrap;margin-bottom:-1px;',
        onclick: function () { show(t[0]); } }, [t[1]]));
    });
    wrap.appendChild(strip); wrap.appendChild(paneL); wrap.appendChild(paneS);
    show(flt.tab === 'sales' ? 'sales' : 'licences');
  }

  function formRow(label, node) { return el('label', { style: 'display:flex;flex-direction:column;gap:4px;font-size:12px;font-weight:700;color:#475569;min-width:0' }, [label, node]); }
  var FLD = 'height:38px;padding:0 10px;border:1px solid #CBD5E1;border-radius:9px;font-size:14px;box-sizing:border-box;width:100%';

  function addDialog(d, main, forAgency, hasPlan) {
    /* THE BUNDLE (2026-10-25): an agency with a plan can only gain add-ons, which join the plan's term; an
       agency without one starts with a plan. A plan chosen for an agency that has one replaces it (same term). */
    var sel = forAgency ? String(forAgency) : String(flt.agency || '');
    var ag = el('select', { style: FLD }, [el('option', { value: '' }, ['Choose an agency…'])].concat((d.agencies || []).map(function (a) { var o = el('option', { value: String(a.id) }, [a.name]); if (String(a.id) === sel) o.selected = true; return o; })));
    var have = (d.licences || []).filter(function (l) { return String(l.agency_id) === sel && l.status !== 'cancelled'; }).map(function (l) { return String(l.product_id); });
    var prods = (d.products || []).filter(function (p) { return !forAgency || (hasPlan ? p.kind === 'addon' && have.indexOf(String(p.id)) === -1 : p.kind === 'plan'); });
    var pr = el('select', { style: FLD }, [el('option', { value: '' }, [forAgency ? (hasPlan ? 'Choose an add-on…' : 'Choose a plan…') : 'Choose a plan or add-on…'])].concat(prods.map(function (p) { return el('option', { value: String(p.id) }, [(p.part_number || '') + ' · ' + p.name + ' · $' + p.price + '/' + p.unit]); })));
    var price = el('input', { type: 'number', step: '0.01', min: '0', placeholder: 'Catalogue price', style: FLD });
    var iv = el('select', { style: FLD }, [['monthly', 'Monthly (standard)'], ['quarterly', 'Quarterly'], ['semiannual', 'Semi-annual'], ['annual', 'Yearly']].map(function (o) { return el('option', { value: o[0] }, [o[1]]); }));
    var start = el('input', { type: 'date', value: KT.agencyToday ? KT.agencyToday() : '', style: FLD });
    var renew = el('input', { type: 'checkbox', checked: 'checked', style: 'width:18px;height:18px' });
    var notes = el('input', { type: 'text', maxlength: '500', placeholder: 'Optional note', style: FLD });
    /* PAID, COMPLIMENTARY OR TRIAL (2026-10-25): a trial lasts as long as you set - days, weeks, months or years -
       costs nothing, does not renew and raises no invoice. */
    var type = el('select', { style: FLD }, [['paid', 'Paid'], ['comp', 'Complimentary (billed at list, zeroed)'], ['trial', 'Trial']].map(function (o) { return el('option', { value: o[0] }, [o[1]]); }));
    var tLen = el('input', { type: 'number', min: '1', max: '999', step: '1', value: '30', style: FLD + ';max-width:90px' });
    var tUnit = el('select', { style: FLD }, [['days', 'days'], ['weeks', 'weeks'], ['months', 'months'], ['years', 'years']].map(function (o) { return el('option', { value: o[0] }, [o[1]]); }));
    var trialRow = el('div', { style: 'display:none;gap:8px;align-items:flex-end' }, [formRow('Trial length', tLen), formRow('', tUnit)]);
    type.addEventListener('change', function () { trialRow.style.display = type.value === 'trial' ? 'flex' : 'none'; price.disabled = type.value !== 'paid'; if (type.value !== 'paid') price.value = ''; });
    pr.addEventListener('change', function () { var p = (d.products || []).filter(function (x) { return String(x.id) === pr.value; })[0]; if (p) { price.value = p.price; iv.value = p.unit === 'year' ? 'annual' : 'monthly'; } });
    var addon = !!(forAgency && hasPlan);
    var body = el('div', {}, [
      el('div', { style: 'display:grid;grid-template-columns:repeat(auto-fill,minmax(220px,1fr));gap:12px' }, addon
        ? [formRow('Agency', ag), formRow('Add-on', pr), formRow('Price per term', price), formRow('Note', notes)]
        : [formRow('Agency', ag), formRow('Plan or add-on', pr), formRow('Licence type', type), trialRow, formRow('Price per term', price), formRow('Billed', iv), formRow('Starts', start), formRow('Note', notes),
          el('label', { style: 'display:flex;gap:8px;align-items:center;font-size:13.5px;font-weight:700' }, [renew, 'Renews automatically'])]),
      el('div', { style: 'font-size:12px;color:#64748B;margin-top:10px' }, [addon ? 'The add-on joins this agency\'s licence and ends and renews with the plan.'
        : 'An add-on always joins the agency\'s plan term. A plan for an agency that already has one replaces it and keeps its end date.']),
    ]);
    if (addon) ag.disabled = true;
    KT.Shell.Modal.open({ title: addon ? '🔑 Add an add-on' : '🔑 Add a licence', body: body, actions: [{ label: 'Cancel' }, { label: addon ? 'Add add-on' : 'Add licence', primary: true, handler: function () {
      if (!ag.value || !pr.value) { toast('Choose the agency and the plan or add-on', 'error'); return false; }
      return KT.Api.post('/platform/licences', { agency_id: +ag.value, product_id: +pr.value, unit_price: price.value === '' ? null : Number(price.value), interval: iv.value, starts_at: start.value || null, auto_renew: renew.checked, notes: notes.value.trim() || null,
        licence_type: addon ? null : type.value, trial_length: type.value === 'trial' ? Number(tLen.value || 30) : null, trial_unit: type.value === 'trial' ? tUnit.value : null })
        .then(function () { toast('Licence added'); renderPlatform(main); });
    } }] });
  }

  function editDialog(l, main) {
    var exp = el('input', { type: 'date', value: l.expires_at ? String(l.expires_at).slice(0, 10) : '', style: FLD });
    var price = el('input', { type: 'number', step: '0.01', min: '0', value: l.unit_price, style: FLD });
    var qty = el('input', { type: 'number', step: '1', min: '1', value: l.qty, style: FLD });
    var iv = el('select', { style: FLD }, [['monthly', 'Monthly (standard)'], ['quarterly', 'Quarterly'], ['semiannual', 'Semi-annual'], ['annual', 'Yearly']].map(function (o) { var x = el('option', { value: o[0] }, [o[1]]); if (o[0] === l.interval) x.selected = true; return x; }));
    // Contract term in months (2026-10-25): 12 by default for yearly, longer when the contract says so.
    var term = el('input', { type: 'number', min: '1', max: '120', step: '1', placeholder: 'One billing period', value: l.term_months || '', style: FLD });
    var renew = el('input', { type: 'checkbox', style: 'width:18px;height:18px' }); renew.checked = !!l.auto_renew;
    var notes = el('input', { type: 'text', maxlength: '500', value: l.notes || '', style: FLD });
    var body = el('div', {}, [
      el('div', { style: 'display:flex;gap:8px;align-items:center;margin-bottom:12px' }, [part(l.part_number), el('strong', {}, [l.name]), el('span', { style: 'color:#64748B' }, ['· ' + (l.agency_name || '')]), chip(l.status)]),
      el('div', { style: 'display:grid;grid-template-columns:repeat(auto-fill,minmax(200px,1fr));gap:12px' }, [formRow('Price per term', price), formRow('Quantity', qty), formRow('Note', notes)]),
      el('div', { style: 'font-size:12px;color:#64748B;margin-top:10px' }, ['The end date, billing and renewal belong to the whole licence: use Term & renewal on the agency.']),
    ]);
    var acts = [{ label: 'Close' }];
    acts.push({ label: 'Save', primary: true, handler: function () {
      return KT.Api.patch('/platform/licences/' + l.id, { unit_price: Number(price.value || 0), qty: Number(qty.value || 1), notes: notes.value.trim() || null })
        .then(function () { toast('Saved'); renderPlatform(main); });
    } });
    KT.Shell.Modal.open({ title: '🔑 ' + l.name, body: body, actions: acts });
  }

  /* REISSUE (2026-10-25, Anthony: "if i remove a license I need the ability to reissue the invoice"): after a removal
     or cancellation, offer to reissue each open invoice that billed it - void it, raise the corrected copy. */
  async function offerReissue(res, what) {
    var open = (res && res.open_invoices) || [];
    for (var i = 0; i < open.length; i++) {
      var o = open[i];
      var ok = await KT.confirm({ title: 'Reissue invoice ' + o.number + '?', description: o.number + ' still bills ' + what + '. Reissuing voids it and raises a corrected copy for the same period (payments move across). You can email it from Accounting AR/AP.', okLabel: 'Reissue invoice' });
      if (!ok) continue;
      try { var r = await KT.Api.post('/platform/invoices/' + o.id + '/reissue', { reason: what + ' removed' }); toast('Reissued as ' + r.invoice.number + ' · ' + money(r.invoice.amount_cents / 100)); }
      catch (e) { toast((e && e.message) || 'Could not reissue', 'error'); }
    }
  }

  /* TERM & RENEWAL for the whole licence (2026-10-25): end date, billing interval, contract term and
     auto-renew, applied to the plan and every add-on together. */
  function termDialog(plan, n, main) {
    var exp = el('input', { type: 'date', value: plan.expires_at ? String(plan.expires_at).slice(0, 10) : '', style: FLD });
    var iv = el('select', { style: FLD }, [['monthly', 'Monthly (standard)'], ['quarterly', 'Quarterly'], ['semiannual', 'Semi-annual'], ['annual', 'Yearly']].map(function (o) { var x = el('option', { value: o[0] }, [o[1]]); if (o[0] === plan.interval) x.selected = true; return x; }));
    var term = el('input', { type: 'number', min: '1', max: '120', step: '1', placeholder: 'One billing period', value: plan.term_months || '', style: FLD });
    var renew = el('input', { type: 'checkbox', style: 'width:18px;height:18px' }); renew.checked = !!plan.auto_renew;
    var body = el('div', {}, [
      el('div', { style: 'font-size:13px;color:#475569;margin-bottom:12px' }, [(plan.agency_name || 'This agency') + ': the plan and every add-on (' + n + ' line' + (n === 1 ? '' : 's') + ') share this term.']),
      el('div', { style: 'display:grid;grid-template-columns:repeat(auto-fill,minmax(200px,1fr));gap:12px' }, [formRow('Ends / renews on', exp), formRow('Billed', iv), formRow('Contract term (months)', term),
        el('label', { style: 'display:flex;gap:8px;align-items:center;font-size:13.5px;font-weight:700' }, [renew, 'Renews automatically'])]),
      el('div', { style: 'font-size:12px;color:#64748B;margin-top:10px' }, ['A future end date re-opens a licence in grace or one that has ended. Features follow straight away.']),
    ]);
    KT.Shell.Modal.open({ title: '🔑 Term & renewal', body: body, actions: [{ label: 'Close' }, { label: 'Save for the whole licence', primary: true, handler: function () {
      return KT.Api.patch('/platform/licences/' + plan.id, { expires_at: exp.value || null, interval: iv.value, term_months: term.value ? Number(term.value) : null, auto_renew: renew.checked })
        .then(function () { toast('Term saved for all ' + n + ' lines'); renderPlatform(main); });
    } }] });
  }

  /* ── agency admin: their own licence, read-only ──────────────────── */
  /* ── #licence: YOUR LICENCE AT A GLANCE (2026-10-25, third pass) ─────────────────────
     Anthony: "show who is the agency owner and directors/admins also how many more children,
     and centres are allowed based on the license type. instead of using cards can you make
     this even cooler by using a different presentment of data". So: a licence band with the
     term as a timeline, capacity as rings, the team as a roster, the account as one ribbon,
     the licence lines as a table and the features as a spec sheet — no tiles. */
  var BILL = { trial: 'Free trial', active: 'Active', grace: 'Renewal due', past_due: 'Payment overdue', suspended: 'Suspended', cancelled: 'Cancelled', expired: 'Expired' };
  function cap(s) { return s ? s.charAt(0).toUpperCase() + s.slice(1).replace(/_/g, ' ') : ''; }
  function initials(n) { return String(n || '?').split(/\s+/).filter(Boolean).slice(0, 2).map(function (x) { return x.charAt(0).toUpperCase(); }).join('') || '?'; }

  async function renderMine(main) {
    css();
    main.innerHTML = '';
    main.appendChild(hero('Licence', 'Your plan, what it allows, who runs the account and everything on it today.', '🔑'));
    var wrap = el('div', { class: 'lic-wrap lic3' }); main.appendChild(wrap);
    wrap.appendChild(el('div', { style: 'padding:20px;color:#64748B' }, ['Loading…']));
    var d;
    try { d = await KT.Api.get('/admin/licence'); } catch (e) { wrap.innerHTML = ''; wrap.appendChild(el('div', { class: 'lic-card', style: 'color:#B91C1C' }, [(e && e.message) || 'Could not load your licence'])); return; }
    wrap.innerHTML = '';
    var s = d.summary || {};
    band(wrap, s, d);
    capacity(wrap, s);
    roster(wrap, s.people || {});
    ribbon(wrap, s);
    lines(wrap, d);
    await invoicesSection(wrap, main);
    enhancementCard(wrap, d.enhancements, function () { renderMine(main); });
    regionBanner(wrap, s.region, s.included || []);
    specSheet(wrap, s.features || []);
    wrap.appendChild(el('div', { style: 'font-size:12.5px;color:#64748B;margin:14px 2px 0' }, ['To change your plan or add-ons, contact support from Help.']));
  }

  /* The licence itself: plan, status, add-ons, and the current term drawn as a timeline. */
  function band(wrap, s, d) {
    var st = s.status || s.billing_status || '';
    var start = s.current_term_start || s.started_at, end = s.ends_at;
    var pct = null, left = daysTo(end);
    if (start && end) {
      var a = new Date(String(start).slice(0, 10) + 'T12:00:00'), b = new Date(String(end).slice(0, 10) + 'T12:00:00');
      var t = new Date((KT.agencyToday ? KT.agencyToday() : new Date().toISOString().slice(0, 10)) + 'T12:00:00');
      pct = Math.max(0, Math.min(100, Math.round(100 * (t - a) / Math.max(1, b - a))));
    }
    var facts = [['Licence started', day(s.started_at)], ['Customer since', day(s.customer_since)], ['Billing', cap(s.interval) || '—'], ['Next invoice', day(s.next_invoice_at)]];
    if (st === 'trial' && s.trial_ends_at) facts.unshift(['Trial ends', day(s.trial_ends_at)]);
    var b = el('div', { class: 'lic-band' }, [
      el('div', { class: 'lic-band-l' }, [
        el('div', { class: 'lic-eye' }, ['Your licence']),
        el('div', { class: 'lic-plan' }, [s.plan_name || 'Your plan', st ? el('span', { class: 'lic-st lic-st-' + st }, [BILL[st] || cap(st)]) : null]),
        s.plan_description ? el('div', { class: 'lic-desc' }, [s.plan_description]) : null,
        el('div', { class: 'lic-pills' }, (s.addons || []).map(function (n) { return el('span', { class: 'lic-pill' }, ['＋ ' + n + (/white/i.test(n) && !s.white_label ? ' · available, not switched on' : '')]); })
          .concat(s.white_label && !(s.addons || []).some(function (n) { return /white/i.test(n); }) ? [el('span', { class: 'lic-pill' }, ['Your own brand'])] : [])),
      ]),
      el('div', { class: 'lic-band-r' }, [
        el('div', { class: 'lic-tl-h' }, [el('span', {}, ['Current term']), el('b', {}, [end ? ((s.auto_renew === false ? 'Ends ' : 'Renews ') + day(end) + (left !== null && left >= 0 ? ' · in ' + left + ' day' + (left === 1 ? '' : 's') : '')) : 'No end date'])]),
        el('div', { class: 'lic-tl' }, pct === null ? [] : [el('i', { style: 'width:' + pct + '%' }), el('em', { style: 'left:' + pct + '%', title: 'Today' })]),
        el('div', { class: 'lic-tl-x' }, [el('span', {}, [day(start)]), el('span', {}, [day(end)])]),
        el('div', { class: 'lic-facts3' }, facts.map(function (f) { return el('div', {}, [el('span', {}, [f[0]]), el('b', {}, [f[1]])]); })),
      ]),
    ]);
    wrap.appendChild(b);
    if (s.grace_until) wrap.appendChild(el('div', { class: 'lic-warn' }, ['⚠️ Renew by ' + day(s.grace_until) + ' to keep every feature switched on.']));
  }

  /* Capacity as rings: used of allowed, and how much room is left. */
  function ring(used, max, label, sub, colour) {
    var R = 42, C = 2 * Math.PI * R, unlimited = max === null || max === undefined;
    var frac = unlimited ? 1 : (max ? Math.min(1, used / max) : 0);
    var over = !unlimited && used > max;
    var col = over ? '#DC2626' : (frac >= 0.9 && !unlimited ? '#D97706' : colour);
    var svg = '<svg viewBox="0 0 100 100" width="104" height="104" aria-hidden="true"><circle cx="50" cy="50" r="' + R + '" fill="none" stroke="#E8EEF3" stroke-width="10"/>'
      + '<circle cx="50" cy="50" r="' + R + '" fill="none" stroke="' + col + '" stroke-width="10" stroke-linecap="round" stroke-dasharray="' + (C * frac).toFixed(1) + ' ' + C.toFixed(1) + '" transform="rotate(-90 50 50)"' + (unlimited ? ' opacity=".35"' : '') + '/>'
      + '<text x="50" y="' + (unlimited ? 58 : 50) + '" text-anchor="middle" font-size="' + (unlimited ? 30 : 20) + '" font-weight="800" fill="#0F172A" font-family="inherit">' + (unlimited ? '∞' : used) + '</text>'
      + (unlimited ? '' : '<text x="50" y="66" text-anchor="middle" font-size="11" fill="#64748B" font-family="inherit">of ' + max + '</text>') + '</svg>';
    var g = el('div', { class: 'lic-gauge' }); g.innerHTML = svg;
    return el('div', { class: 'lic-ring' }, [g, el('div', {}, [el('b', {}, [label]), el('span', { style: over ? 'color:#B91C1C;font-weight:700' : '' }, [sub])])]);
  }
  function capacity(wrap, s) {
    var L = s.limits || {}, c = s.counts || {};
    var ce = L.centres || {}, ch = L.children || {};
    var more = function (x, word, plural) {
      if (x.max === null || x.max === undefined) return 'Unlimited on this plan · ' + x.used + ' now';
      if (x.over) return (x.used - x.max) + ' over the plan — time to move up a plan';
      return x.left ? x.left + ' more ' + (x.left === 1 ? word : plural) + ' allowed' : 'No more ' + plural + ' on this plan';
    };
    var row = el('div', { class: 'lic-rings' }, [
      ring(ce.used || 0, ce.max, 'Centres', more(ce, 'centre', 'centres'), '#1F6080'),
      ring(ch.used || 0, ch.max, 'Children enrolled', more(ch, 'child', 'children'), '#1DB9C3'),
    ]);
    if (c.licensed_capacity) row.appendChild(ring(ch.used || 0, c.licensed_capacity, 'Licensed spaces filled', Math.round(100 * (ch.used || 0) / c.licensed_capacity) + '% of your licensed capacity', '#7C3AED'));
    wrap.appendChild(el('div', { class: 'lic-h' }, ['What your plan allows']));
    wrap.appendChild(row);
  }

  /* The people who run the account. */
  function roster(wrap, p) {
    var rows = [];
    var seen = {};
    (p.admins || []).forEach(function (a) { seen[a.email] = 1; rows.push({ name: a.name, email: a.email, photo: a.photo_url, role: a.owner ? 'Owner' : 'Admin', extra: a.owner ? 'Agency owner · administrator' : 'Administrator' }); });
    if (p.owner && !seen[p.owner.email]) rows.unshift({ name: p.owner.name, email: p.owner.email, photo: p.owner.photo_url, role: 'Owner', extra: 'Agency owner' });
    (p.directors || []).forEach(function (x) { rows.push({ name: x.name, email: x.email, photo: x.photo_url, role: 'Director', extra: (x.centres || []).join(', ') || 'Centre director' }); });
    rows.sort(function (a, b) { var o = { Owner: 0, Admin: 1, Director: 2 }; return o[a.role] - o[b.role]; });
    wrap.appendChild(el('div', { class: 'lic-h' }, ['Who runs the account · ' + rows.length]));
    var list = el('div', { class: 'lic-roster' });
    if (!rows.length) list.appendChild(el('div', { style: 'padding:12px;color:#64748B' }, ['No administrators recorded.']));
    rows.forEach(function (r) {
      var av = r.photo ? el('img', { src: r.photo, alt: '', class: 'lic-av' }) : el('span', { class: 'lic-av' }, [initials(r.name)]);
      list.appendChild(el('div', { class: 'lic-person' }, [av,
        el('div', { style: 'min-width:0;flex:1' }, [el('div', { class: 'lic-pn' }, [r.name]), el('div', { class: 'lic-pe' }, [r.email || ''])]),
        el('div', { class: 'lic-px' }, [r.extra]),
        el('span', { class: 'lic-role lic-role-' + r.role.toLowerCase() }, [r.role === 'Owner' ? '👑 Owner' : r.role]),
      ]));
    });
    wrap.appendChild(list);
  }

  /* Everything on the account today, as one ribbon; this month underneath as bars. */
  function ribbon(wrap, s) {
    var c = s.counts || {}, u = s.usage_this_month || {};
    var items = [['🏫', 'Centres', c.centres, 'sky'], ['🚪', 'Rooms', c.rooms, 'teal'], ['🧒', 'Children', c.children, 'mint'], ['⏳', 'Waitlist', c.waitlist, 'amber'],
      ['🏡', 'Families', c.families, 'peach'], ['👪', 'Parents', c.parents, 'rose'], ['🧑‍🏫', 'Educators', c.educators, 'violet'], ['🧭', 'Directors', c.directors, 'indigo'],
      ['🛡️', 'Admins', c.admins, 'slate']];
    if (c.home_visitors) items.push(['🏠', 'Home visitors', c.home_visitors, 'lime']);
    wrap.appendChild(el('div', { class: 'lic-h' }, ['Your account today']));
    wrap.appendChild(el('div', { class: 'lic-ribbon' }, items.map(function (i) {
      return el('div', { class: 'lic-g lic-g-' + i[3] }, [el('i', {}, [i[0]]), el('b', {}, [Number(i[2] || 0).toLocaleString()]), el('span', {}, [i[1]])]);
    })));
    var month = [['📸 Photos shared', u.photos || 0], ['💬 Messages', u.messages || 0], ['📱 Texts sent', u.texts || 0], ['📞 Voice calls', u.calls || 0]];
    var top = Math.max.apply(null, month.map(function (m) { return m[1]; }).concat([1]));
    wrap.appendChild(el('div', { class: 'lic-h' }, ['This month']));
    wrap.appendChild(el('div', { class: 'lic-bars' }, month.map(function (m) {
      return el('div', { class: 'lic-bar' }, [el('span', {}, [m[0]]), el('div', {}, [el('i', { style: 'width:' + Math.max(2, Math.round(100 * m[1] / top)) + '%' })]), el('b', {}, [Number(m[1]).toLocaleString()])]);
    })));
  }

  /* The licence lines, as a table. */
  function lines(wrap, d) {
    var ls = d.licences || [];
    if (!ls.length) return;
    wrap.appendChild(el('div', { class: 'lic-h' }, ['Plan & add-ons']));
    var t = el('table', { class: 'lic-tbl', 'data-kt-no-filter': '1' }, [el('thead', {}, [el('tr', {}, ['Part #', 'Item', 'Term', 'Price', 'Renews / ends', 'Status'].map(function (h) { return el('th', {}, [h]); }))])]);
    var tb = el('tbody'); t.appendChild(tb);
    ls.forEach(function (l) {
      var dd = daysTo(l.expires_at), price = Number(l.unit_price || 0) * Number(l.qty || 1);
      tb.appendChild(el('tr', {}, [
        el('td', {}, [part(l.part_number)]),
        el('td', {}, [el('b', {}, [l.name]), el('div', { style: 'font-size:11.5px;color:#64748B' }, [l.kind === 'plan' ? 'Plan' : 'Add-on'])]),
        el('td', {}, [cap(l.interval)]),
        el('td', {}, [price ? money(price, l.currency) + (IV[l.interval] || '') : 'Complimentary']),
        el('td', {}, [l.status === 'grace' ? 'Renew by ' + day(l.grace_until) : ((l.auto_renew ? 'Renews ' : 'Ends ') + day(l.expires_at) + (dd !== null && dd >= 0 && dd <= 30 ? ' · ' + dd + 'd' : ''))]),
        el('td', {}, [chip(l.status)]),
      ]));
    });
    wrap.appendChild(el('div', { style: 'overflow-x:auto' }, [t]));
  }

  /* ── INVOICES & PAYMENTS (2026-10-25) ───────────────────────────────────────────
     Anthony: "Invoices for agency's should show up in the portal for admins only to see ...
     Wire up the fact that they can prepay invoices, prepay for the entire year, partial payment".
     This page is admin-only already; the API is too (AgencyBillingController). Pay early or in
     part (reported here, KiddieTrac confirms it and a receipt is emailed), prepay the year, and
     every invoice and receipt as a PDF. */
  function ktFile(path, title, filename) {
    var tok = '', ag = '';
    try { tok = sessionStorage.getItem('kt_token') || localStorage.getItem('kt_token') || ''; ag = sessionStorage.getItem('kt_active_agency_id') || ''; } catch (e) {}
    var base = (KT && KT.API_BASE) || 'https://api.kiddietrac.com/api/v1';
    var h = { Authorization: 'Bearer ' + tok, Accept: 'application/pdf' }; if (ag) h['X-Active-Agency-Id'] = ag;
    return fetch(base + path, { headers: h }).then(function (r) { if (!r.ok) throw new Error('That document could not be opened.'); return r.blob(); })
      .then(function (b) { if (KT.openFile) { KT.openFile(b, { title: title, filename: filename, label: title }); return; } var u = URL.createObjectURL(b); window.open(u, '_blank'); setTimeout(function () { URL.revokeObjectURL(u); }, 60000); })
      .catch(function (e) { toast((e && e.message) || 'That document could not be opened.', 'error'); });
  }
  async function invoicesSection(wrap, main) {
    var box = el('div'); wrap.appendChild(box);
    var d;
    try { d = await KT.Api.get('/admin/kt-invoices'); } catch (e) { return; }
    var cur = d.currency || 'CAD';
    var m = function (c) { return money((c || 0) / 100, cur); };
    var head = el('div', { class: 'lic-h', style: 'display:flex;align-items:center;justify-content:space-between;gap:10px;flex-wrap:wrap' }, [
      el('span', {}, ['Invoices & payments' + (d.customer_number ? ' · Customer # ' + d.customer_number : '') + (d.owing_cents ? ' · ' + m(d.owing_cents) + ' owing' : ' · nothing owing')]),
      d.can_prepay ? el('button', { type: 'button', class: 'lic-btn', style: 'height:32px;padding:0 14px;font-size:12.5px;text-transform:none;letter-spacing:0', onclick: function () { prepay(d, main); } },
        ['💳 Prepay the year' + (d.prepay_discount_pct ? ' · save ' + d.prepay_discount_pct + '%' : '')]) : null,
    ]);
    box.appendChild(head);
    if (!(d.invoices || []).length) { box.appendChild(el('div', { style: 'color:#64748B;font-size:13px;padding:6px 2px' }, ['No invoices yet.'])); return; }
    var t = el('table', { class: 'lic-tbl', 'data-kt-no-filter': '1' }, [el('thead', {}, [el('tr', {}, ['Invoice', 'Period', 'Amount', 'Paid', 'Balance', 'Due', 'Status', ''].map(function (h) { return el('th', {}, [h]); }))])]);
    var tb = el('tbody'); t.appendChild(tb);
    (d.invoices || []).forEach(function (i) {
      var st = i.status === 'void' ? ['Void', '#475569', '#F1F5F9'] : i.status === 'paid' ? ['Paid', '#166534', '#DCFCE7'] : i.overdue ? ['Overdue', '#991B1B', '#FEE2E2']
        : i.paid_cents > 0 ? ['Part paid', '#92400E', '#FEF3C7'] : ['Due', '#075985', '#E0F2FE'];
      var acts = el('div', { style: 'display:flex;gap:6px;justify-content:flex-end;flex-wrap:wrap' }, [
        el('button', { type: 'button', class: 'lic-btn', style: 'height:28px;padding:0 10px;font-size:12px', onclick: function () { ktFile('/admin/kt-invoices/' + i.id + '/pdf', 'Invoice ' + i.number, i.number + '.pdf'); } }, ['📄 PDF']),
        i.balance_cents > 0 && i.status !== 'void' ? el('button', { type: 'button', class: 'lic-btn', style: 'height:28px;padding:0 10px;font-size:12px', onclick: function () { report(i, d, main); } }, ['💳 I’ve paid']) : null,
      ]);
      tb.appendChild(el('tr', {}, [
        el('td', {}, [el('b', {}, [i.number]), i.prepay ? el('div', { style: 'font-size:11px;color:#7C3AED;font-weight:700' }, ['Prepayment']) : null]),
        el('td', {}, [day(i.period_start) + ' – ' + day(i.period_end)]),
        el('td', {}, [m(i.amount_cents)]),
        el('td', {}, [m(i.paid_cents), i.pending_cents ? el('div', { style: 'font-size:11px;color:#B45309' }, [m(i.pending_cents) + ' waiting to be confirmed']) : null]),
        el('td', {}, [el('b', {}, [m(i.balance_cents)])]),
        el('td', {}, [day(i.due_at)]),
        el('td', {}, [el('span', { style: 'display:inline-block;font-size:11px;font-weight:800;padding:2px 9px;border-radius:999px;color:' + st[1] + ';background:' + st[2] }, [st[0]])]),
        el('td', {}, [acts]),
      ]));
      (i.payments || []).filter(function (p) { return p.status === 'refund'; }).forEach(function (p) {
        tb.appendChild(el('tr', {}, [el('td', { colspan: '8', style: 'padding:2px 10px 8px;font-size:12px;color:#7C3AED' },
          [el('button', { type: 'button', style: 'border:1px solid #DDD6FE;background:#F5F3FF;color:#6D28D9;border-radius:999px;padding:2px 10px;font-size:11.5px;font-weight:700;cursor:pointer', onclick: function () { ktFile('/admin/kt-invoices/receipts/' + p.id, 'Refund ' + p.receipt_number, p.receipt_number + '.pdf'); } },
            ['↩️ Refund ' + (p.receipt_number || '') + ' · ' + m(p.amount_cents) + ' · ' + day(p.paid_on)])])]));
      });
      (i.payments || []).filter(function (p) { return p.status === 'returned'; }).forEach(function (p) {
        tb.appendChild(el('tr', {}, [el('td', { colspan: '8', style: 'padding:2px 10px 8px;font-size:12px;color:#B91C1C' },
          ['↩️ A payment of ' + m(p.amount_cents) + ' on ' + day(p.paid_on) + ' was returned unpaid' + (p.return_reason ? ' (' + p.return_reason + ')' : '') + ' and no longer counts.'])]));
      });
      var rec = (i.payments || []).filter(function (p) { return p.status === 'confirmed' && p.receipt_number; });
      if (rec.length) {
        tb.appendChild(el('tr', {}, [el('td', { colspan: '8', style: 'padding:2px 10px 10px;border-bottom:1px solid #EEF2F6' }, [el('div', { style: 'display:flex;gap:6px;flex-wrap:wrap;align-items:center;font-size:12px;color:#475569' },
          ['Receipts:'].concat(rec.map(function (p) {
            return el('button', { type: 'button', style: 'border:1px solid #BBF7D0;background:#F0FDF4;color:#166534;border-radius:999px;padding:2px 10px;font-size:11.5px;font-weight:700;cursor:pointer', onclick: function () { ktFile('/admin/kt-invoices/receipts/' + p.id, 'Receipt ' + p.receipt_number, p.receipt_number + '.pdf'); } },
              ['🧾 ' + p.receipt_number + ' · ' + m(p.amount_cents) + ' · ' + day(p.paid_on)]);
          })))])]));
      }
    });
    box.appendChild(el('div', { style: 'overflow-x:auto' }, [t]));
    box.appendChild(el('div', { style: 'display:grid;grid-template-columns:repeat(auto-fit,minmax(280px,1fr));gap:14px;margin-top:12px' }, [
      el('div', { style: '--kt-edge:#1DB9C3;background:#F3F9FC;border-radius:10px;padding:12px 14px;font-size:12.5px;color:#334155;white-space:pre-line' }, [el('b', { style: 'display:block;color:#0A1E55;margin-bottom:4px' }, ['How to pay']), (d.payment_instructions || '') + (d.surcharge_note ? '\n' + d.surcharge_note : '')]),
      el('div', { style: '--kt-edge:#0A1E55;background:#F8FAFC;border-radius:10px;padding:12px 14px;font-size:12px;color:#475569;white-space:pre-line' }, [el('b', { style: 'display:block;color:#0A1E55;margin-bottom:4px' }, ['Payment terms']), d.terms || '']),
    ]));
  }
  function report(i, d, main) {
    var cur = d.currency || 'CAD';
    var F = 'width:100%;box-sizing:border-box;padding:0 10px;border:1px solid #D9E1EA;border-radius:9px;font:inherit;font-size:14px';
    var amt = el('input', { type: 'number', step: '0.01', min: '0.01', value: ((i.balance_cents - i.pending_cents) / 100).toFixed(2), style: F });
    var meth = el('select', { style: F }, Object.keys(d.methods || {}).map(function (k) { return el('option', { value: k }, [d.methods[k]]); }));
    var ref = el('input', { type: 'text', maxlength: '120', placeholder: 'e.g. e-Transfer confirmation or cheque number', style: F });
    var when = el('input', { type: 'date', value: (KT.agencyToday ? KT.agencyToday() : new Date().toISOString().slice(0, 10)), style: F });
    var lab = function (t, n) { return el('label', { style: 'display:block;margin:0 0 10px' }, [el('div', { style: 'font-size:12px;font-weight:700;color:#475569;margin-bottom:4px' }, [t]), n]); };
    /* SURCHARGES (2026-10-25): a method that carries one shows the total to send to clear the balance. */
    var sumLine = el('div', { style: 'font-size:12.5px;color:#92400E;background:#FFFBEB;border:1px solid #FDE68A;border-radius:9px;padding:8px 10px;display:none' });
    var owe = i.balance_cents - i.pending_cents;
    function gross(method) {
      var sc = (d.surcharges || {})[method]; if (!sc || owe <= 0) return owe;
      var sur = Math.round(owe * (Number(sc.pct) || 0) / 100) + (Number(sc.flat_cents) || 0);
      return owe + sur + (d.surcharge_taxable ? Math.round(sur * (i.tax_rate_bps || 0) / 10000) : 0);
    }
    function paintSum() {
      var g = gross(meth.value);
      if (g > owe) { sumLine.style.display = ''; sumLine.textContent = (d.methods[meth.value] || meth.value) + ' payments carry a surcharge: send ' + money(g / 100, cur) + ' to clear ' + money(owe / 100, cur) + '.'; amt.value = (g / 100).toFixed(2); }
      else { sumLine.style.display = 'none'; amt.value = (owe / 100).toFixed(2); }
    }
    meth.addEventListener('change', paintSum);
    setTimeout(paintSum, 0);
    var body = el('div', {}, [
      el('div', { style: 'font-size:13px;color:#475569;margin:0 0 12px' }, ['Tell KiddieTrac about a payment on ' + i.number + '. Pay the whole balance or part of it — paying early is welcome. We confirm it, and your receipt is emailed.']),
      el('div', { style: 'display:grid;grid-template-columns:1fr 1fr;gap:10px' }, [lab('Amount (' + cur + ')', amt), lab('Paid on', when)]),
      lab('How you paid', meth), lab('Reference', ref),
      sumLine,
    ]);
    KT.Shell.Modal.open({ title: '💳 Report a payment', body: body, actions: [{ label: 'Cancel' }, { label: 'Send to KiddieTrac', primary: true, handler: function () {
      var v = Number(amt.value || 0);
      if (!(v > 0)) { toast('Enter the amount you paid', 'error'); return false; }
      return KT.Api.post('/admin/kt-invoices/' + i.id + '/report-payment', { amount: v, method: meth.value, reference: ref.value.trim() || null, paid_on: when.value || null })
        .then(function () { toast('Thank you — KiddieTrac will confirm your payment and email a receipt'); renderMine(main); });
    } }] });
  }
  function prepay(d, main) {
    KT.confirm({ title: 'Prepay the next 12 months?', okLabel: 'Create my prepayment invoice',
      description: 'We will raise one invoice for a full year of your plan and add-ons' + (d.prepay_discount_pct ? ', with ' + d.prepay_discount_pct + '% off for paying upfront' : '') + ', starting where your current term ends. Your licence is extended for the year, and the invoice is emailed to your billing contacts.' })
      .then(function (ok) {
        if (!ok) return;
        KT.Api.post('/admin/kt-invoices/prepay-year', {}).then(function (r) { toast('Prepayment invoice ' + r.invoice.number + ' created'); renderMine(main); })
          .catch(function (e) { toast((e && e.message) || 'Could not create the prepayment invoice', 'error'); });
      });
  }

  /* Every feature as a spec sheet: grouped, ticked when the plan includes it. */
  /* THE PLATFORM'S REGION + WHAT EVERY LICENCE INCLUDES (2026-10-25). Highlighted, above the features. */
  function regionBanner(wrap, r, inc) {
    if (r && r.label) {
      wrap.appendChild(el('div', { 'data-kt-keep-brand': '1', style: 'display:flex;align-items:center;gap:14px;flex-wrap:wrap;margin:18px 0 10px;padding:14px 18px;border-radius:14px;color:#fff;'
        + 'background:linear-gradient(135deg,#1DB9C3 0%,#2563EB 55%,#7C3AED 100%);box-shadow:0 10px 24px -16px rgba(37,99,235,.7)' }, [
        el('div', { style: 'font-size:30px;line-height:1' }, [r.flag || '🌐']),
        el('div', { style: 'flex:1 1 220px;min-width:0' }, [
          el('div', { style: 'font-size:11px;font-weight:800;letter-spacing:1.5px;opacity:.85;text-transform:uppercase' }, ['Your platform']),
          el('div', { style: 'font-size:19px;font-weight:800' }, [r.label]),
          r.estimated ? el('div', { style: 'font-size:11.5px;opacity:.85' }, ['Estimated from your time zone. Add your province or state under Settings for the exact region.']) : null,
        ]),
        el('div', { style: 'display:flex;gap:8px;flex-wrap:wrap' }, (r.facts || []).map(function (f) {
          return el('span', { style: 'background:rgba(255,255,255,.16);border:1px solid rgba(255,255,255,.4);border-radius:999px;padding:4px 11px;font-size:12px;font-weight:700;white-space:nowrap' }, [f.k + ': ' + f.v]);
        })),
      ]));
    }
    if (inc.length) {
      wrap.appendChild(el('div', { class: 'lic-h' }, ['Included with your licence']));
      wrap.appendChild(el('div', { style: 'display:grid;grid-template-columns:repeat(auto-fit,minmax(240px,1fr));gap:10px;margin-bottom:6px' }, inc.map(function (x) {
        var icon = { support: '🛠️', message_centre: '💬', one_tap: '📲' }[x.key] || '✓';
        return el('div', { style: 'display:flex;gap:10px;align-items:flex-start;border:1px solid ' + (x.on ? '#BFDBFE' : '#E2E8F0') + ';background:' + (x.on ? '#F5F9FF' : '#F8FAFC') + ';border-radius:12px;padding:11px 13px' }, [
          el('div', { style: 'font-size:20px;line-height:1.1' }, [icon]),
          el('div', { style: 'min-width:0' }, [
            el('div', { style: 'font-weight:800;color:#0F172A;font-size:13.5px' }, [x.label + (x.on ? '' : ' · off')]),
            el('div', { style: 'font-size:12px;color:#64748B;margin-top:2px' }, [x.detail]),
          ]),
        ]);
      })));
    }
  }

  function specSheet(wrap, feats) {
    if (!feats.length) return;
    var on = feats.filter(function (f) { return f.on; }).length;
    wrap.appendChild(el('div', { class: 'lic-h' }, ['Features · ' + on + ' of ' + feats.length + ' included']));
    var groups = {};
    feats.forEach(function (f) { (groups[f.group] = groups[f.group] || []).push(f); });
    var sheet = el('div', { class: 'lic-spec' });
    Object.keys(groups).forEach(function (g) {
      var gs = groups[g], gon = gs.filter(function (f) { return f.on; }).length;
      sheet.appendChild(el('section', {}, [el('h4', {}, [el('span', {}, [g]), el('em', {}, [gon + '/' + gs.length])])].concat(gs.map(function (f) {
        return el('div', { class: 'lic-feat-row' + (f.on ? '' : ' off') }, [el('span', { class: 'lic-tick' }, [f.on ? '✓' : '—']), el('span', { style: 'flex:1;min-width:0' }, [f.label]),
          f.on ? null : el('span', { class: 'lic-need' }, [f.plan_min_name === 'Add-on' ? 'Add-on' : f.plan_min_name + ' plan'])]);
      }))));
    });
    wrap.appendChild(sheet);
  }

  /* ENHANCEMENT REQUESTS (2026-10-24): the Agency plan includes 3 courtesy requests a year,
     reviewed by KiddieTrac. They go to HQ as a support ticket (request_type 'enhancement');
     the server counts and refuses, this only draws. Other plans see what the Agency plan adds. */
  function enhancementCard(wrap, en, reload) {
    if (!en) return;
    var box = el('div', { class: 'lic-card', style: 'margin-top:14px;--kt-edge:#1DB9C3;display:flex;flex-wrap:wrap;gap:12px;align-items:center' });
    if (!en.eligible) {
      box.appendChild(el('div', { style: 'flex:1 1 260px;min-width:0' }, [
        el('strong', { style: 'font-size:15px' }, ['✨ Enhancement requests']),
        el('div', { style: 'font-size:12.5px;color:#64748B;margin-top:3px' }, ['The Agency plan includes ' + en.allowance + ' courtesy enhancement requests a year, customized reports, AI tools, analytics and telephone support. To upgrade, contact support from Help.']),
      ]));
      wrap.appendChild(box); return;
    }
    var dots = el('span', { style: 'display:inline-flex;gap:4px;margin-left:6px;vertical-align:middle' });
    for (var i = 0; i < en.allowance; i++) dots.appendChild(el('span', { style: 'width:10px;height:10px;border-radius:50%;background:' + (i < en.used ? '#CBD5E1' : '#1DB9C3') }));
    box.appendChild(el('div', { style: 'flex:1 1 260px;min-width:0' }, [
      el('div', {}, [el('strong', { style: 'font-size:15px' }, ['✨ Enhancement requests']), dots]),
      el('div', { style: 'font-size:12.5px;color:#64748B;margin-top:3px' }, [en.left + ' of ' + en.allowance + ' left for ' + en.year + '. Ask for a change to KiddieTrac or a customized report; our team reviews each request and replies on the ticket.']),
    ]));
    var b = el('button', { type: 'button', class: 'btn', style: 'background:#1F6080;color:#fff;border:1px solid #1F6080;border-radius:9px;padding:0 14px;height:32px;font-weight:700;cursor:pointer;white-space:nowrap' + (en.left ? '' : ';opacity:.5;cursor:not-allowed') }, ['Request an enhancement']);
    if (en.left) b.addEventListener('click', function () { openEnhancement(en, reload); }); else b.disabled = true;
    box.appendChild(b);
    wrap.appendChild(box);
  }
  function openEnhancement(en, reload) {
    var F = 'width:100%;box-sizing:border-box;padding:8px 10px;border:1px solid #D9E1EA;border-radius:9px;font:inherit;font-size:14px';
    var subj = el('input', { type: 'text', maxlength: '200', placeholder: 'e.g. A monthly fees report by room', style: F });
    var what = el('textarea', { rows: '4', placeholder: 'What should it do? Where in KiddieTrac?', style: F + ';min-height:90px' });
    var who = el('textarea', { rows: '2', placeholder: 'Who uses it and how it helps your centres', style: F + ';min-height:56px' });
    var kind = el('select', { style: F }, [['change', 'A change to how KiddieTrac works'], ['report', 'A customized report'], ['new', 'Something new']].map(function (o) { return el('option', { value: o[0] }, [o[1]]); }));
    var lab = function (t, n) { return el('label', { style: 'display:block;margin:0 0 10px' }, [el('div', { style: 'font-size:12px;font-weight:700;color:#475569;margin-bottom:4px' }, [t]), n]); };
    var body = el('div', {}, [
      el('div', { style: 'font-size:12.5px;color:#64748B;margin:0 0 12px' }, ['This uses 1 of your ' + en.left + ' remaining requests for ' + en.year + '. KiddieTrac reviews each one and confirms what we can build.']),
      lab('Type', kind), lab('Short title *', subj), lab('Describe it *', what), lab('Who it helps', who),
    ]);
    KT.Shell.Modal.open({ title: '✨ Request an enhancement', body: body, actions: [
      { label: 'Cancel' },
      { label: 'Send request', primary: true, handler: function () {
        if (!subj.value.trim() || !what.value.trim()) { KT.toast && KT.toast('⚠️', 'Add a title and a description', '', '#DC2626'); return false; }
        KT.Api.post('/tickets', { request_type: 'enhancement', subject: subj.value.trim(), impact: 'question',
          details: [{ label: 'Type', value: kind.options[kind.selectedIndex].text }, { label: 'What it should do', value: what.value.trim() }, { label: 'Who it helps', value: who.value.trim() }],
          context: { screen: 'licence' } })
          .then(function () { KT.toast && KT.toast('✨', 'Request sent', 'KiddieTrac will review it and reply on the ticket.'); reload(); })
          .catch(function (e) { KT.toast && KT.toast('⚠️', 'Could not send', (e && e.message) || '', '#DC2626'); });
      } },
    ] });
  }

  (function register() {
    if (!(KT.Shell && KT.Shell.registerScreen)) { setTimeout(register, 300); return; }
    KT.Shell.registerScreen('platform_admin:licences', renderPlatform);
    KT.Shell.registerScreen('agency_admin:licence', renderMine);
    KT.Shell.registerScreen('platform_admin:licence', renderMine);
  })();
})(window);
