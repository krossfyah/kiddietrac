/* ═══════════════════════════════════════════════════════════════════
   KiddieTrac — Sales CRM (sales_rep role + superadmin via View-as).
   Platform sales pipeline for selling KiddieTrac to prospective agencies.
   Screens: pipeline (kanban), leads list, new lead, lead detail (activities,
   follow-ups, quotes), follow-ups, plans editor, launch-demo. Backend: /sales/*.
   ═══════════════════════════════════════════════════════════════════ */
(function (window) {
  'use strict';
  var KT = window.KT;
  if (!KT || !KT.Shell || !KT.Shell.registerScreen) return;
  var Api = KT.Api;

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

  function el(tag, attrs, kids) {
    var e = document.createElement(tag);
    if (attrs) Object.keys(attrs).forEach(function (k) {
      if (k === 'style') e.style.cssText = attrs[k];
      else if (k === 'html') e.innerHTML = attrs[k];
      else if (k.slice(0, 2) === 'on' && typeof attrs[k] === 'function') e.addEventListener(k.slice(2), attrs[k]);
      else e.setAttribute(k, attrs[k]);
    });
    (kids || []).forEach(function (c) { if (c != null) e.appendChild(typeof c === 'string' ? document.createTextNode(c) : c); });
    return e;
  }
  function esc(s) { return String(s == null ? '' : s).replace(/[&<>"]/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]; }); }
  function clear(n) { while (n.firstChild) n.removeChild(n.firstChild); }
  function toast(i, t, m, c) { try { KT.toast && KT.toast(i, t, m || '', c || '#7C3AED'); } catch (e) {} }
  function go(hash) { window.location.hash = '#' + hash; }
  function money(v) { if (v == null || v === '') return '—'; var n = Number(v); return '$' + n.toLocaleString(undefined, { minimumFractionDigits: 0, maximumFractionDigits: 2 }); }
  function fmtDate(s) { if (!s) return ''; try { return new Date(s + (s.length <= 10 ? 'T00:00:00' : '')).toLocaleDateString(undefined, { month: 'short', day: 'numeric', year: 'numeric' }); } catch (e) { return s; } }
  function todayISO() { return new Date().toISOString().slice(0, 10); }

  function hero(title, sub, actions) {
    return el('div', { class: 'kt-hero', style: 'background:linear-gradient(135deg,#5B2A86,#9C3FA6);color:#fff;border-radius:18px;padding:20px 24px;margin-bottom:16px;display:flex;align-items:center;gap:16px;flex-wrap:wrap' }, [
      el('div', { style: 'flex:1;min-width:200px' }, [
        el('div', { style: 'font-size:20px;font-weight:800' }, [title]),
        el('div', { style: 'font-size:13.5px;opacity:.92;margin-top:4px' }, [sub || '']),
      ]),
      actions ? el('div', { style: 'display:flex;gap:8px;flex-wrap:wrap' }, actions) : null,
    ]);
  }
  function btn(label, kind, on) {
    var bg = kind === 'primary' ? '#7C3AED' : (kind === 'ghost' ? 'rgba(255,255,255,.16)' : '#fff');
    var col = kind === 'primary' ? '#fff' : (kind === 'ghost' ? '#fff' : '#5B2A86');
    return el('button', { class: 'btn', style: 'background:' + bg + ';color:' + col + ';border:0;border-radius:9px;padding:9px 16px;font-weight:700;cursor:pointer;font-size:13.5px', onclick: on }, [label]);
  }
  function field(label, node) {
    return el('div', { style: 'margin-bottom:12px' }, [
      el('label', { style: 'display:block;font-size:12px;font-weight:700;color:var(--kt-muted,#5a7080);margin-bottom:4px' }, [label]),
      node,
    ]);
  }
  function input(attrs) { return el('input', Object.assign({ class: 'input', style: 'width:100%;padding:9px 11px;border:1px solid var(--kt-border,#d9e1ea);border-radius:9px;font-size:14px;box-sizing:border-box' }, attrs || {})); }
  function textarea(attrs) { return el('textarea', Object.assign({ class: 'input', style: 'width:100%;padding:9px 11px;border:1px solid var(--kt-border,#d9e1ea);border-radius:9px;font-size:14px;box-sizing:border-box;min-height:70px;resize:vertical' }, attrs || {})); }
  function selectEl(opts, val, attrs) {
    var s = el('select', Object.assign({ class: 'input select', style: 'width:100%;padding:9px 11px;border:1px solid var(--kt-border,#d9e1ea);border-radius:9px;font-size:14px;box-sizing:border-box' }, attrs || {}));
    opts.forEach(function (o) { var op = el('option', { value: o.v }, [o.l]); if (String(o.v) === String(val)) op.selected = true; s.appendChild(op); });
    return s;
  }
  function stageChip(k) { return el('span', { style: 'display:inline-block;padding:2px 9px;border-radius:999px;font-size:11px;font-weight:800;color:#fff;background:' + (STAGE_COLOR[k] || '#94A3B8') }, [STAGE_LABEL[k] || k]); }
  function card(kids, extra) { return el('div', { style: 'background:var(--kt-card,#fff);border:1px solid var(--kt-border,#e6ebf1);border-radius:16px;padding:16px 18px;box-shadow:0 1px 3px rgba(15,23,42,.05);' + (extra || '') }, kids); }

  var META = null; // {owners, products, stats}
  function ownerOpts() { return [{ v: '', l: 'Unassigned' }].concat((META && META.owners || []).map(function (o) { return { v: o.id, l: o.name }; })); }

  // ─────────────────────────────── Pipeline (kanban) ───────────────────────────────
  async function renderPipeline(container) {
    clear(container);
    container.appendChild(hero('💼 Sales pipeline', 'Track prospects from first contact to won.', [
      btn('➕ New lead', 'light', function () { go('sales-new'); }),
      btn('📋 List view', 'ghost', function () { go('sales-leads'); }),
    ]));
    var loading = el('div', { style: 'padding:30px;text-align:center;color:#64748B' }, ['Loading pipeline…']);
    container.appendChild(loading);
    var data;
    try { data = await Api.get('/sales/leads'); } catch (e) { clear(container); container.appendChild(hero('💼 Sales pipeline', '')); container.appendChild(card([el('div', { style: 'color:#DC2626' }, ['Could not load leads: ' + (e.message || e)])])); return; }
    META = { owners: data.owners || [], products: data.products || [], stats: data.stats || {} };
    loading.remove();

    var st = data.stats || {};
    var statRow = el('div', { style: 'display:grid;grid-template-columns:repeat(auto-fit,minmax(130px,1fr));gap:12px;margin-bottom:16px' }, [
      statCard('🎯', 'Open leads', st.open || 0, '#EEF2FF', '#4338CA'),
      statCard('💰', 'Pipeline value', money(st.pipeline_value || 0), '#ECFDF5', '#047857'),
      statCard('🏆', 'Won', st.won || 0, '#F0FDF4', '#15803D'),
      statCard('⏰', 'Follow-ups due', st.followups_due || 0, '#FFF7ED', '#C2410C'),
      statCard('🙋', 'My open', st.my_open || 0, '#FDF2F8', '#BE185D'),
    ]);
    container.appendChild(statRow);

    var board = el('div', { style: 'display:flex;gap:12px;overflow-x:auto;padding-bottom:12px;align-items:flex-start' });
    var byStage = {}; STAGES.forEach(function (s) { byStage[s.key] = []; });
    (data.leads || []).forEach(function (l) { (byStage[l.stage] || (byStage[l.stage] = [])).push(l); });
    STAGES.forEach(function (s) {
      var leads = byStage[s.key] || [];
      var col = el('div', { style: 'flex:0 0 260px;min-width:260px;background:var(--kt-bg,#f4f6f9);border-radius:14px;padding:10px' }, [
        el('div', { style: 'display:flex;align-items:center;justify-content:space-between;margin-bottom:8px;padding:2px 4px' }, [
          el('span', { style: 'font-weight:800;font-size:13px;color:' + s.color }, [s.label]),
          el('span', { style: 'font-size:12px;font-weight:700;color:#64748B' }, [String(leads.length)]),
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
  function leadCard(l) {
    var due = l.follow_up_date && l.follow_up_date <= todayISO();
    var c = el('div', { style: 'background:var(--kt-card,#fff);border:1px solid var(--kt-border,#e6ebf1);border-left:3px solid ' + (STAGE_COLOR[l.stage] || '#94A3B8') + ';border-radius:11px;padding:11px 12px;margin-bottom:9px;cursor:pointer;box-shadow:0 1px 2px rgba(15,23,42,.05)', onclick: function () { go('sales-lead?id=' + l.id); } }, [
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
    container.appendChild(hero('🎯 Leads', 'All leads in the pipeline.', [
      btn('➕ New lead', 'light', function () { go('sales-new'); }),
      btn('📊 Pipeline', 'ghost', function () { go('sales'); }),
    ]));
    var search = input({ type: 'search', placeholder: '🔍 Search name, company, email…', style: 'max-width:320px;padding:9px 11px;border:1px solid #d9e1ea;border-radius:9px' });
    container.appendChild(el('div', { style: 'margin-bottom:12px' }, [search]));
    var host = el('div'); container.appendChild(host);
    async function load() {
      clear(host); host.appendChild(el('div', { style: 'padding:20px;color:#64748B' }, ['Loading…']));
      var data = await Api.get('/sales/leads', search.value ? { q: search.value } : {});
      META = { owners: data.owners || [], products: data.products || [], stats: data.stats || {} };
      clear(host);
      if (!data.leads || !data.leads.length) { host.appendChild(card([el('div', { style: 'text-align:center;color:#64748B;padding:20px' }, ['No leads yet. ', el('a', { href: '#sales-new', style: 'color:#7C3AED;font-weight:700' }, ['Add one →'])])])); return; }
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
    container.appendChild(hero('➕ New lead', 'Add a prospect to the pipeline.'));
    var f = {};
    var form = card([
      field('Contact name *', f.name = input({ placeholder: 'Jane Doe' })),
      field('Company / childcare', f.company = input({ placeholder: 'Sunshine Daycare' })),
      el('div', { style: 'display:grid;grid-template-columns:1fr 1fr;gap:12px' }, [
        field('Email', f.email = input({ type: 'email', placeholder: 'jane@example.com' })),
        field('Phone', f.phone = input({ placeholder: '(555) 123-4567' })),
      ]),
      el('div', { style: 'display:grid;grid-template-columns:1fr 1fr 1fr;gap:12px' }, [
        field('Stage', f.stage = selectEl(STAGES.map(function (s) { return { v: s.key, l: s.label }; }), 'new')),
        field('Owner', f.owner_id = selectEl(ownerOpts(), (function () { try { return (JSON.parse(sessionStorage.getItem('kt_user') || '{}').id) || ''; } catch (e) { return ''; } })())),
        field('Est. value ($)', f.value = input({ type: 'number', step: '0.01', min: '0', placeholder: '0.00' })),
      ]),
      el('div', { style: 'display:grid;grid-template-columns:1fr 1fr;gap:12px' }, [
        field('Expected close', f.expected_close = input({ type: 'date' })),
        field('Next follow-up', f.follow_up_date = input({ type: 'date' })),
      ]),
      field('Notes', f.notes = textarea({ placeholder: 'What are they looking for?' })),
      el('div', { style: 'display:flex;gap:8px;margin-top:6px' }, [
        btn('Create lead', 'primary', async function (ev) {
          if (!f.name.value.trim()) { toast('⚠️', 'Name required', '', '#DC2626'); return; }
          ev.target.disabled = true; ev.target.textContent = 'Saving…';
          try {
            var body = { name: f.name.value.trim(), company: f.company.value.trim(), email: f.email.value.trim(), phone: f.phone.value.trim(), stage: f.stage.value, owner_id: f.owner_id.value || null, value: f.value.value || null, expected_close: f.expected_close.value || null, follow_up_date: f.follow_up_date.value || null, notes: f.notes.value.trim() };
            var lead = await Api.post('/sales/leads', body);
            toast('✅', 'Lead created', 'Superadmin + sales team notified.');
            go('sales-lead?id=' + lead.id);
          } catch (e) { toast('⚠️', 'Save failed', e.message || '', '#DC2626'); ev.target.disabled = false; ev.target.textContent = 'Create lead'; }
        }),
        btn('Cancel', 'light', function () { go('sales'); }),
      ]),
    ], 'max-width:640px');
    container.appendChild(form);
  }

  // ─────────────────────────────── Lead detail ───────────────────────────────
  async function renderDetail(container, ctx) {
    clear(container);
    var id = (ctx && ctx.params && ctx.params.id) || (window.location.hash.split('id=')[1] || '').split('&')[0];
    if (!id) { go('sales'); return; }
    container.appendChild(el('div', { style: 'padding:20px;color:#64748B' }, ['Loading lead…']));
    var lead;
    try { lead = await Api.get('/sales/leads/' + id); } catch (e) { clear(container); container.appendChild(card([el('div', { style: 'color:#DC2626' }, ['Lead not found.'])])); return; }
    if (!META) { try { var d = await Api.get('/sales/leads'); META = { owners: d.owners || [], products: d.products || [] }; } catch (e) { META = { owners: [], products: [] }; } }
    clear(container);

    container.appendChild(hero('💬 ' + (lead.company || lead.name), (lead.company && lead.name ? lead.name + ' · ' : '') + (lead.email || ''), [
      btn('← Pipeline', 'ghost', function () { go('sales'); }),
    ]));

    // Stage mover
    var stageSel = selectEl(STAGES.map(function (s) { return { v: s.key, l: s.label }; }), lead.stage);
    stageSel.addEventListener('change', async function () {
      try { await Api.patch('/sales/leads/' + id, { stage: stageSel.value }); lead.stage = stageSel.value; toast('✅', 'Stage updated', STAGE_LABEL[stageSel.value]); renderDetail(container, ctx); } catch (e) { toast('⚠️', 'Failed', e.message || '', '#DC2626'); }
    });
    container.appendChild(card([
      el('div', { style: 'display:flex;gap:14px;align-items:center;flex-wrap:wrap' }, [
        el('div', {}, [el('div', { style: 'font-size:11px;color:#5a7080;font-weight:700;margin-bottom:3px' }, ['STAGE']), stageSel]),
        el('div', { style: 'flex:1' }),
        el('div', { style: 'text-align:right' }, [el('div', { style: 'font-size:11px;color:#5a7080;font-weight:700' }, ['EST. VALUE']), el('div', { style: 'font-size:20px;font-weight:800;color:#047857' }, [money(lead.value)])]),
      ]),
    ], 'margin-bottom:14px'));

    var grid = el('div', { style: 'display:grid;grid-template-columns:1.1fr .9fr;gap:14px;align-items:start' });
    // LEFT: editable details + activities
    var left = el('div');
    var e = {};
    left.appendChild(card([
      el('div', { style: 'font-weight:800;margin-bottom:10px' }, ['Details']),
      el('div', { style: 'display:grid;grid-template-columns:1fr 1fr;gap:10px' }, [
        field('Contact', e.name = input({ value: lead.name || '' })),
        field('Company', e.company = input({ value: lead.company || '' })),
        field('Email', e.email = input({ value: lead.email || '' })),
        field('Phone', e.phone = input({ value: lead.phone || '' })),
        field('Owner', e.owner_id = selectEl(ownerOpts(), lead.owner_id || '')),
        field('Est. value ($)', e.value = input({ type: 'number', step: '0.01', value: lead.value == null ? '' : lead.value })),
        field('Expected close', e.expected_close = input({ type: 'date', value: lead.expected_close || '' })),
        field('Next follow-up', e.follow_up_date = input({ type: 'date', value: lead.follow_up_date || '' })),
      ]),
      field('Notes', e.notes = textarea({ value: lead.notes || '' })),
      el('div', { style: 'display:flex;gap:8px' }, [
        btn('Save changes', 'primary', async function (ev) {
          ev.target.disabled = true;
          try { await Api.patch('/sales/leads/' + id, { name: e.name.value, company: e.company.value, email: e.email.value, phone: e.phone.value, owner_id: e.owner_id.value || null, value: e.value.value || null, expected_close: e.expected_close.value || null, follow_up_date: e.follow_up_date.value || null, notes: e.notes.value }); toast('✅', 'Saved'); renderDetail(container, ctx); } catch (er) { toast('⚠️', 'Failed', er.message || '', '#DC2626'); ev.target.disabled = false; }
        }),
        btn('🗑 Delete', 'light', async function () { if (!confirm('Delete this lead?')) return; try { await Api.delete('/sales/leads/' + id); toast('🗑', 'Deleted'); go('sales'); } catch (er) { toast('⚠️', 'Failed', er.message || '', '#DC2626'); } }),
      ]),
    ], 'margin-bottom:14px'));

    // Activity + follow-up composer
    var acWrap = card([el('div', { style: 'font-weight:800;margin-bottom:10px' }, ['Activity & follow-ups'])], '');
    var typeSel = selectEl([{ v: 'note', l: '📝 Note' }, { v: 'call', l: '📞 Call' }, { v: 'email', l: '📧 Email' }, { v: 'meeting', l: '🤝 Meeting' }, { v: 'followup', l: '⏰ Follow-up task' }], 'note');
    var bodyIn = textarea({ placeholder: 'Log a note, or describe the follow-up…' });
    var dueIn = input({ type: 'date' }); dueIn.style.display = 'none';
    typeSel.addEventListener('change', function () { dueIn.style.display = typeSel.value === 'followup' ? 'block' : 'none'; });
    acWrap.appendChild(el('div', { style: 'display:grid;grid-template-columns:150px 1fr;gap:8px;margin-bottom:8px' }, [typeSel, dueIn]));
    acWrap.appendChild(bodyIn);
    acWrap.appendChild(el('div', { style: 'margin-top:8px' }, [btn('Add', 'primary', async function (ev) {
      var b = { type: typeSel.value, body: bodyIn.value.trim() };
      if (typeSel.value === 'followup') { if (!dueIn.value) { toast('⚠️', 'Pick a due date', '', '#DC2626'); return; } b.due_date = dueIn.value; }
      if (!b.body && typeSel.value !== 'followup') { toast('⚠️', 'Write something', '', '#DC2626'); return; }
      ev.target.disabled = true;
      try { await Api.post('/sales/leads/' + id + '/activities', b); toast('✅', 'Added'); renderDetail(container, ctx); } catch (er) { toast('⚠️', 'Failed', er.message || '', '#DC2626'); ev.target.disabled = false; }
    })]));
    var timeline = el('div', { style: 'margin-top:14px;display:flex;flex-direction:column;gap:8px' });
    (lead.activities || []).forEach(function (a) {
      var isFu = a.type === 'followup';
      timeline.appendChild(el('div', { style: 'border-left:2px solid ' + (isFu && !a.done ? '#F59E0B' : '#e6ebf1') + ';padding:2px 0 2px 10px' }, [
        el('div', { style: 'font-size:12px;color:#64748B' }, [
          ({ note: '📝', call: '📞', email: '📧', meeting: '🤝', followup: '⏰', stage: '↗️' }[a.type] || '•') + ' ',
          el('span', { style: 'font-weight:700;color:#334155' }, [a.type === 'stage' ? 'Stage' : a.type.charAt(0).toUpperCase() + a.type.slice(1)]),
          a.user_name ? ' · ' + a.user_name : '', a.created_at ? ' · ' + fmtDate((a.created_at || '').slice(0, 10)) : '',
          isFu && a.due_date ? el('span', { style: 'margin-left:6px;font-weight:700;color:' + (a.done ? '#15803D' : '#C2410C') }, [a.done ? '✓ done' : ('due ' + fmtDate(a.due_date))]) : '',
          isFu && !a.done ? el('a', { href: '#', style: 'margin-left:8px;color:#7C3AED;font-weight:700', onclick: async function (ev2) { ev2.preventDefault(); try { await Api.patch('/sales/activities/' + a.id, { done: true }); toast('✅', 'Done'); renderDetail(container, ctx); } catch (er) {} } }, ['mark done']) : '',
        ]),
        a.body ? el('div', { style: 'font-size:13px;color:#0D1B2A;margin-top:2px' }, [a.body]) : null,
      ]));
    });
    if (!(lead.activities || []).length) timeline.appendChild(el('div', { style: 'color:#94A3B8;font-size:13px' }, ['No activity yet.']));
    acWrap.appendChild(timeline);
    left.appendChild(acWrap);
    grid.appendChild(left);

    // RIGHT: quotes
    grid.appendChild(renderQuotesPanel(id, lead, container, ctx));
    container.appendChild(grid);
  }

  function renderQuotesPanel(id, lead, container, ctx) {
    var panel = card([el('div', { style: 'display:flex;justify-content:space-between;align-items:center;margin-bottom:10px' }, [
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
        var d = input({ value: it.description || '', placeholder: 'Line item', style: 'flex:1;padding:7px 9px;border:1px solid #d9e1ea;border-radius:8px' });
        var q = input({ type: 'number', step: '0.01', value: it.qty == null ? 1 : it.qty, style: 'width:60px;padding:7px;border:1px solid #d9e1ea;border-radius:8px' });
        var p = input({ type: 'number', step: '0.01', value: it.unit_price == null ? 0 : it.unit_price, style: 'width:90px;padding:7px;border:1px solid #d9e1ea;border-radius:8px' });
        d.addEventListener('input', function () { it.description = d.value; }); q.addEventListener('input', function () { it.qty = q.value; recalc(); }); p.addEventListener('input', function () { it.unit_price = p.value; recalc(); });
        itemsHost.appendChild(el('div', { style: 'display:flex;gap:6px;align-items:center' }, [d, q, p, el('button', { style: 'border:0;background:#FEE2E2;color:#B91C1C;border-radius:8px;padding:6px 9px;cursor:pointer', onclick: function () { items.splice(idx, 1); drawItems(); recalc(); } }, ['×'])]));
      });
    }
    drawItems(); recalc(); discIn.addEventListener('input', recalc);
    var planSel = selectEl([{ v: '', l: '+ Add preset plan…' }].concat(products.map(function (p) { return { v: p.id, l: p.name + ' — ' + money(p.price) + '/' + p.unit }; })), '');
    planSel.addEventListener('change', function () { var p = products.filter(function (x) { return String(x.id) === planSel.value; })[0]; if (p) { items.push({ description: p.name + (p.description ? ' — ' + p.description : ''), qty: 1, unit_price: Number(p.price), product_id: p.id }); drawItems(); recalc(); planSel.value = ''; } });
    box.appendChild(el('div', { style: 'display:flex;justify-content:space-between;align-items:center;margin-bottom:12px' }, [el('div', { style: 'font-size:18px;font-weight:800' }, [quote ? ('Proposal ' + (quote.number || '')) : 'New proposal']), el('button', { style: 'border:0;background:none;font-size:22px;cursor:pointer;color:#64748B', onclick: function () { ov.remove(); } }, ['×'])]));
    box.appendChild(field('Title', titleIn));
    box.appendChild(el('div', { style: 'display:flex;gap:8px;align-items:center;margin-bottom:6px' }, [el('div', { style: 'flex:1' }, ['Line items']), el('div', { style: 'width:60px;font-size:11px;color:#64748B;text-align:center' }, ['Qty']), el('div', { style: 'width:90px;font-size:11px;color:#64748B;text-align:center' }, ['Price']), el('div', { style: 'width:34px' })]));
    box.appendChild(itemsHost);
    box.appendChild(el('div', { style: 'display:flex;gap:8px;margin-bottom:10px' }, [el('div', { style: 'flex:1' }, [planSel]), btn('+ Blank line', 'light', function () { items.push({ description: '', qty: 1, unit_price: 0 }); drawItems(); })]));
    box.appendChild(el('div', { style: 'display:grid;grid-template-columns:1fr 1fr;gap:12px' }, [field('Discount ($)', discIn), field('Valid until', validIn)]));
    box.appendChild(field('Notes', notesIn));
    box.appendChild(totalEl);
    var statusSel = quote ? selectEl([{ v: 'draft', l: 'Draft' }, { v: 'sent', l: 'Sent' }, { v: 'accepted', l: 'Accepted' }, { v: 'declined', l: 'Declined' }], quote.status) : null;
    box.appendChild(el('div', { style: 'display:flex;gap:8px;justify-content:flex-end;margin-top:14px;align-items:center' }, [
      quote ? el('div', { style: 'margin-right:auto;display:flex;align-items:center;gap:6px' }, [el('span', { style: 'font-size:12px;color:#64748B' }, ['Status']), statusSel]) : null,
      btn('Cancel', 'light', function () { ov.remove(); }),
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
    container.appendChild(hero('⏰ Follow-ups', 'Open follow-up tasks across your leads.'));
    var data = await Api.get('/sales/leads');
    META = { owners: data.owners || [], products: data.products || [] };
    // gather open follow-ups by fetching each lead? Too heavy — instead show leads with a due follow_up_date.
    var due = (data.leads || []).filter(function (l) { return l.follow_up_date && l.status === 'open'; }).sort(function (a, b) { return (a.follow_up_date || '') < (b.follow_up_date || '') ? -1 : 1; });
    if (!due.length) { container.appendChild(card([el('div', { style: 'text-align:center;color:#64748B;padding:20px' }, ['🎉 No follow-ups scheduled.'])])); return; }
    var list = el('div', { style: 'display:flex;flex-direction:column;gap:8px' });
    due.forEach(function (l) {
      var overdue = l.follow_up_date < todayISO();
      list.appendChild(card([el('div', { style: 'display:flex;justify-content:space-between;align-items:center;cursor:pointer', onclick: function () { go('sales-lead?id=' + l.id); } }, [
        el('div', {}, [el('div', { style: 'font-weight:700' }, [l.company || l.name]), el('div', { style: 'font-size:12px;color:#64748B' }, [stageChip(l.stage), l.owner_name ? '  · ' + l.owner_name : ''])]),
        el('div', { style: 'text-align:right' }, [el('div', { style: 'font-weight:700;color:' + (overdue ? '#C2410C' : '#334155') }, [(overdue ? '⏰ overdue · ' : '') + fmtDate(l.follow_up_date)])]),
      ])], 'padding:12px 16px'));
    });
    container.appendChild(list);
  }

  // ─────────────────────────────── Plans / pricing ───────────────────────────────
  async function renderPlans(container) {
    clear(container);
    container.appendChild(hero('💲 Plans & pricing', 'Preset products used in proposals. Edit prices to match your offer.'));
    var host = el('div'); container.appendChild(host);
    async function load() {
      clear(host);
      var products = await Api.get('/sales/products');
      products.forEach(function (p) { host.appendChild(planRow(p, load)); });
      host.appendChild(el('div', { style: 'margin-top:10px' }, [btn('+ Add plan', 'primary', function () { host.insertBefore(planRow({ name: '', description: '', price: 0, unit: 'month', active: true }, load, true), host.firstChild); })]));
    }
    function planRow(p, reload, isNew) {
      var n = input({ value: p.name || '', placeholder: 'Plan name', style: 'flex:1.2;padding:8px;border:1px solid #d9e1ea;border-radius:8px' });
      var d = input({ value: p.description || '', placeholder: 'Description', style: 'flex:2;padding:8px;border:1px solid #d9e1ea;border-radius:8px' });
      var pr = input({ type: 'number', step: '0.01', value: p.price || 0, style: 'width:100px;padding:8px;border:1px solid #d9e1ea;border-radius:8px' });
      var u = selectEl([{ v: 'month', l: '/month' }, { v: 'year', l: '/year' }, { v: 'one-time', l: 'one-time' }], p.unit || 'month', { style: 'width:110px;padding:8px;border:1px solid #d9e1ea;border-radius:8px' });
      return card([el('div', { style: 'display:flex;gap:8px;align-items:center;flex-wrap:wrap' }, [n, d, pr, u,
        btn('Save', 'primary', async function (ev) { ev.target.disabled = true; try { var body = { name: n.value, description: d.value, price: pr.value || 0, unit: u.value, active: true }; if (p.id) await Api.patch('/sales/products/' + p.id, body); else await Api.post('/sales/products', body); toast('✅', 'Saved'); reload(); } catch (er) { toast('⚠️', 'Failed', er.message || '', '#DC2626'); ev.target.disabled = false; } }),
        p.id ? el('button', { style: 'border:0;background:#FEE2E2;color:#B91C1C;border-radius:8px;padding:8px 10px;cursor:pointer', onclick: async function () { if (!confirm('Delete plan?')) return; try { await Api.delete('/sales/products/' + p.id); reload(); } catch (er) {} } }, ['🗑']) : null,
      ])], 'padding:10px 12px;margin-bottom:8px');
    }
    load();
  }

  // ─────────────────────────────── Launch demo ───────────────────────────────
  async function renderDemo(container) {
    clear(container);
    container.appendChild(hero('🚀 Launch demo', 'Open the KiddieTrac Test Agency to walk a prospect through the product.'));
    container.appendChild(card([
      el('p', { style: 'color:#334155;margin:0 0 14px' }, ['This opens the live demo environment (Test Agency) in a new tab, signed in as a demo admin so you can show attendance, billing, the parent app and more. No real family data.']),
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
    ], 'max-width:560px'));
  }

  // ─────────────────────────────── register ───────────────────────────────
  ['sales_rep', 'platform_admin'].forEach(function (role) {
    KT.Shell.registerScreen(role + ':sales', renderPipeline);
    KT.Shell.registerScreen(role + ':sales-leads', renderList);
    KT.Shell.registerScreen(role + ':sales-new', renderNew);
    KT.Shell.registerScreen(role + ':sales-lead', renderDetail);
    KT.Shell.registerScreen(role + ':sales-followups', renderFollowups);
    KT.Shell.registerScreen(role + ':sales-plans', renderPlans);
    KT.Shell.registerScreen(role + ':sales-demo', renderDemo);
  });
})(window);
