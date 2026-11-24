/* ═══════════════════════════════════════════════════════════════════
   KiddieTrac — the family's BILLING SPLIT panel (2026-10-30).

   Anthony: "add configuration within families to setup the split payment settings". Families → a family → Edit →
   "Billing split" tab. Staff choose who pays what:
     One payer · Shared evenly · Percentage each · Fixed amount + the rest · By charge type (tuition incl. the subsidy
     co-pay / fees & extras) · By child
   from a date, with live checks, a preview of the latest invoice split per guardian, how each guardian pays, and the
   history of every change. Server: /admin/families/{id}/billing-split (+ /preview), App\Support\BillingSplit.

   KT.billingSplitPanel(container, familyId, { onSaved(fn) }) - renders into container.
   ═══════════════════════════════════════════════════════════════════ */
(function (w) {
  'use strict';
  var KT = w.KT || (w.KT = {});
  if (KT.billingSplitPanel) return;

  var MODES = [
    ['single', 'One payer', 'One guardian pays the whole bill.'],
    ['even', 'Shared evenly', 'Every invoice is split equally between the guardians you tick.'],
    ['percent', 'Percentage each', 'Each guardian pays a set percentage of every invoice (must total 100%).'],
    ['fixed', 'Fixed amount + the rest', 'A guardian pays a fixed amount per invoice; another pays whatever is left.'],
    ['by_type', 'By charge type', 'One guardian pays tuition (including the subsidy co-pay); another pays fees & extras - late fees, trips, supplies, registration.'],
    ['by_child', 'By child', 'Each child\'s charges go to the guardian you choose.'],
  ];
  var IN = 'height:32px;padding:0 9px;border:1px solid #CBD5E1;border-radius:7px;font-size:13px;background:#fff;box-sizing:border-box;';

  function css() {
    if (document.getElementById('kt-bsplit-css')) return;
    var s = document.createElement('style'); s.id = 'kt-bsplit-css';
    s.textContent = ''
      + '.kt-bs{font-size:13.5px;color:#0F172A;}'
      + '.kt-bs-card{border:1px solid #E2E8F0;border-radius:12px;background:#fff;margin:0 0 12px;overflow:hidden;}'
      + '.kt-bs-h{padding:8px 12px;background:linear-gradient(90deg,#E8F1FB 0%,#F5F9FD 100%);border-bottom:1px solid #E2E8F0;font-size:12.5px;font-weight:800;letter-spacing:.5px;text-transform:uppercase;color:#12305A;display:flex;align-items:center;gap:8px;}'
      + '.kt-bs-b{padding:10px 12px;}'
      + '.kt-bs-row{display:flex;align-items:center;gap:10px;flex-wrap:wrap;padding:4px 0;}'
      + '.kt-bs-row>.kt-bs-l{flex:1 1 180px;min-width:0;}'
      + '.kt-bs-muted{color:#64748B;font-size:12.5px;line-height:1.45;}'
      + '.kt-bs-modes{display:grid;grid-template-columns:repeat(auto-fill,minmax(190px,1fr));gap:6px;}'
      + '.kt-bs-mode{display:flex;gap:8px;align-items:flex-start;border:1px solid #E2E8F0;border-radius:10px;padding:8px 10px;cursor:pointer;background:#fff;text-align:left;min-height:0;}'
      + '.kt-bs-mode.on{border-color:#7FB3DA;background:#F0F7FD;box-shadow:0 0 0 1px #7FB3DA inset;}'
      + '.kt-bs-mode b{display:block;font-size:13px;color:#12305A;}'
      + '.kt-bs-mode span{font-size:11.5px;color:#64748B;line-height:1.35;}'
      + '.kt-bs-pill{display:inline-flex;align-items:center;gap:4px;font-size:11.5px;font-weight:700;padding:2px 8px;border-radius:999px;background:#EEF2F7;color:#334155;}'
      + '.kt-bs-pill.ok{background:#DCFCE7;color:#166534;}.kt-bs-pill.warn{background:#FEF3C7;color:#92400E;}'
      + '.kt-bs-msg{min-height:18px;font-size:13px;font-weight:600;}'
      + '.kt-bs-t{width:100%;border-collapse:collapse;font-size:13px;}'
      + '.kt-bs-t td,.kt-bs-t th{padding:6px 8px;border-top:1px solid #F1F5F9;text-align:left;vertical-align:top;}'
      + '.kt-bs-t th{font-size:11px;text-transform:uppercase;letter-spacing:.4px;color:#64748B;border-top:0;}'
      + '.kt-bs-t td.n,.kt-bs-t th.n{text-align:right;font-variant-numeric:tabular-nums;white-space:nowrap;}'
      + '.kt-bs-btn{height:32px;padding:0 14px;border-radius:8px;font-weight:700;font-size:13px;cursor:pointer;border:1px solid transparent;background:#1F6080;color:#fff;}'
      + '.kt-bs-btn:disabled{opacity:.55;cursor:default;}'
      + '@media (max-width:640px){.kt-bs-modes{grid-template-columns:1fr 1fr;}.kt-bs-mode span{display:none;}}';
    document.head.appendChild(s);
  }

  function el(tag, attrs, kids) {
    var e = document.createElement(tag);
    Object.keys(attrs || {}).forEach(function (k) {
      if (k === 'style') e.style.cssText = attrs[k];
      else if (k === 'text') e.textContent = attrs[k];
      else if (k === 'html') e.innerHTML = attrs[k];
      else e.setAttribute(k, attrs[k]);
    });
    (kids || []).forEach(function (c) { if (c != null) e.appendChild(typeof c === 'string' ? document.createTextNode(c) : c); });
    return e;
  }
  function money(v) { return '$' + (Number(v) || 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 }); }
  function card(title, extra) { var c = el('div', { class: 'kt-bs-card', 'data-kt-nosechead': '1' }); var h = el('div', { class: 'kt-bs-h' }, [title]); if (extra) h.appendChild(extra); var b = el('div', { class: 'kt-bs-b' }); c.appendChild(h); c.appendChild(b); return { el: c, body: b }; }
  function sel(options, value) {
    var s = el('select', { style: IN + 'min-width:160px;' });
    options.forEach(function (o) { var op = el('option', { value: String(o[0]) }, [o[1]]); if (String(o[0]) === String(value)) op.selected = true; s.appendChild(op); });
    return s;
  }
  function num(value, step, width) { var i = el('input', { type: 'number', step: step || '0.01', min: '0', inputmode: 'decimal', style: IN + 'width:' + (width || 96) + 'px;text-align:right;' }); i.value = value == null ? '' : value; return i; }

  KT._billingSplitCss = css;
  KT.billingSplitPanel = function (container, familyId, opts) {
    opts = opts || {};
    css();
    var Api = KT.Api;
    var root = el('div', { class: 'kt-bs' });
    container.innerHTML = ''; container.appendChild(root);
    root.appendChild(el('div', { class: 'kt-bs-muted', text: 'Loading…' }));
    var D = null, cfg = null, timer = null;

    function guardianOpts() { return (D.guardians || []).map(function (g) { return [g.id, g.name + (g.relationship ? ' (' + g.relationship + ')' : '')]; }); }
    function gName(id) { var g = (D.guardians || []).filter(function (x) { return x.id === Number(id); })[0]; return g ? g.name : 'Guardian'; }

    function load() {
      return Api.get('/admin/families/' + familyId + '/billing-split').then(function (d) {
        D = d; cfg = JSON.parse(JSON.stringify(d.config || { mode: 'single' })); paint();
      }).catch(function (e) { root.innerHTML = ''; root.appendChild(el('div', { style: 'color:#B91C1C;', text: 'Could not load the billing split: ' + (e.message || 'error') })); });
    }

    /* Fill in sensible starting values when the mode changes, from what is set now. */
    function seed(mode) {
      var gs = (D.guardians || []).map(function (g) { return g.id; });
      var payer = cfg.payer || (cfg.rest) || gs[0];
      var two = gs.slice(0, 2);
      var c = { mode: mode };
      if (mode === 'single') c.payer = payer || gs[0];
      if (mode === 'even') c.guardians = (cfg.guardians && cfg.guardians.length > 1) ? cfg.guardians.slice() : gs.slice();
      if (mode === 'percent') {
        var base = (cfg.shares && cfg.shares.length > 1) ? cfg.shares : null;
        c.shares = base ? base.map(function (s) { return { guardian_id: s.guardian_id, pct: s.pct }; })
          : gs.map(function (g, i) { var even = Math.floor(10000 / gs.length) / 100; return { guardian_id: g, pct: i === gs.length - 1 ? Math.round((100 - even * (gs.length - 1)) * 100) / 100 : even }; });
      }
      if (mode === 'fixed') { c.fixed = [{ guardian_id: two[0], amount: '' }]; c.rest = two[1] || two[0]; }
      if (mode === 'by_type') { c.tuition = two[0]; c.fees = two[1] || two[0]; c.shares = gs.map(function (g, i) { return { guardian_id: g, pct: i < 2 ? 50 : 0 }; }); }
      if (mode === 'by_child') { c.children = {}; (D.children || []).forEach(function (k, i) { c.children[k.id] = gs[i % gs.length]; }); c.default = gs[0]; }
      return c;
    }

    /* Client-side check (the server checks again on save). */
    function check() {
      var gs = (D.guardians || []).length;
      if (gs < 2 && cfg.mode !== 'single') return 'Add a second guardian (Guardians tab) to split the bill.';
      if (cfg.mode === 'single') return cfg.payer ? null : 'Choose who pays.';
      if (cfg.mode === 'even') return (cfg.guardians || []).length >= 2 ? null : 'Tick at least two guardians.';
      if (cfg.mode === 'percent') {
        var t = (cfg.shares || []).reduce(function (a, s) { return a + (Number(s.pct) || 0); }, 0);
        t = Math.round(t * 100) / 100;
        return Math.abs(t - 100) < 0.01 ? null : 'The percentages add up to ' + t + '% - they must add up to 100%.';
      }
      if (cfg.mode === 'fixed') {
        var any = (cfg.fixed || []).some(function (f) { return Number(f.amount) > 0; });
        if (!any) return 'Enter the fixed amount.';
        if ((cfg.fixed || []).some(function (f) { return Number(f.guardian_id) === Number(cfg.rest); })) return 'The guardian paying the rest cannot also pay a fixed amount.';
        var latest = D.preview && D.preview.invoice ? D.preview.invoice.total : null;
        var fixedSum = (cfg.fixed || []).reduce(function (a, f) { return a + (Number(f.amount) || 0); }, 0);
        if (latest != null && fixedSum > latest) return 'Note: the fixed amount ($' + fixedSum.toFixed(2) + ') is more than the latest invoice (' + money(latest) + '); on such an invoice the fixed payer pays it all.';
        return null;
      }
      if (cfg.mode === 'by_type') {
        if (String(cfg.tuition) === String(cfg.fees) && cfg.tuition !== 'split') return 'Choose different guardians for tuition and for fees & extras (or One payer).';
        if (cfg.tuition === 'split' || cfg.fees === 'split') {
          var t2 = (cfg.shares || []).reduce(function (a, s) { return a + (Number(s.pct) || 0); }, 0);
          if (Math.abs(t2 - 100) > 0.01) return 'The split percentages add up to ' + (Math.round(t2 * 100) / 100) + '% - they must add up to 100%.';
        }
        return null;
      }
      if (cfg.mode === 'by_child') {
        var vals = Object.keys(cfg.children || {}).map(function (k) { return String(cfg.children[k]); }).concat([String(cfg.default)]);
        return vals.filter(function (v, i, a) { return a.indexOf(v) === i; }).length >= 2 ? null : 'Give at least one child a different payer (or choose One payer).';
      }
      return null;
    }

    function paint() {
      root.innerHTML = '';
      // ── In force now
      var now = card('In force now', el('span', { class: 'kt-bs-pill' + (D.config && D.config.mode !== 'single' ? ' ok' : ''), text: D.label || 'One payer' }));
      now.body.appendChild(el('div', { text: D.text || '' }));
      if (D.upcoming) now.body.appendChild(el('div', { class: 'kt-bs-pill warn', style: 'margin-top:6px;', text: 'From ' + D.upcoming.effective_from + ': ' + D.upcoming.text }));
      now.body.appendChild(el('div', { class: 'kt-bs-muted', style: 'margin-top:6px;', text: 'Invoices already issued keep the split that applied when they were issued. Each guardian sees and pays only their own share and gets their own tax receipt.' }));
      root.appendChild(now.el);

      // ── Change it
      var edit = card('Set up the split');
      var modes = el('div', { class: 'kt-bs-modes', role: 'radiogroup', 'aria-label': 'How the bill is split' });
      MODES.forEach(function (m) {
        var b = el('button', { type: 'button', role: 'radio', 'aria-checked': cfg.mode === m[0] ? 'true' : 'false', 'data-kt-iconized': '1', 'data-kt-btn-free': '1', class: 'kt-bs-mode' + (cfg.mode === m[0] ? ' on' : '') }, [el('div', {}, [el('b', { text: m[1] }), el('span', { text: m[2] })])]);
        if (m[0] === 'by_child' && (D.children || []).length < 1) { b.disabled = true; b.title = 'This family has no children on file.'; }
        b.addEventListener('click', function () { if (cfg.mode === m[0]) return; cfg = seed(m[0]); paint(); });
        modes.appendChild(b);
      });
      edit.body.appendChild(modes);
      var form = el('div', { style: 'margin-top:10px;' });
      edit.body.appendChild(form);
      var gopts = guardianOpts();

      function row(label, control, after) { var r = el('div', { class: 'kt-bs-row' }, [el('div', { class: 'kt-bs-l', text: label }), control]); if (after) r.appendChild(after); form.appendChild(r); return r; }
      if (cfg.mode === 'single') {
        var s1 = sel(gopts, cfg.payer); s1.addEventListener('change', function () { cfg.payer = Number(s1.value); changed(); });
        row('Who pays the whole bill', s1);
      } else if (cfg.mode === 'even') {
        (D.guardians || []).forEach(function (g) {
          var cb = el('input', { type: 'checkbox', style: 'width:18px;height:18px;' });
          cb.checked = (cfg.guardians || []).indexOf(g.id) !== -1;
          cb.addEventListener('change', function () { var a = (cfg.guardians || []).filter(function (x) { return x !== g.id; }); if (cb.checked) a.push(g.id); cfg.guardians = a; changed(); });
          row(g.name + (g.relationship ? ' (' + g.relationship + ')' : ''), cb);
        });
      } else if (cfg.mode === 'percent' || (cfg.mode === 'by_type' && (cfg.tuition === 'split' || cfg.fees === 'split'))) {
        if (cfg.mode === 'by_type') typeRows();
        var total = el('span', { class: 'kt-bs-pill' });
        (D.guardians || []).forEach(function (g) {
          var cur = (cfg.shares || []).filter(function (s) { return s.guardian_id === g.id; })[0];
          var i = num(cur ? cur.pct : 0, '0.01', 84);
          i.addEventListener('input', function () {
            var a = (cfg.shares || []).filter(function (s) { return s.guardian_id !== g.id; });
            a.push({ guardian_id: g.id, pct: Number(i.value) || 0 }); cfg.shares = a; sum(); changed();
          });
          row(g.name + (g.relationship ? ' (' + g.relationship + ')' : ''), i, el('span', { class: 'kt-bs-muted', text: '%' }));
        });
        var tr = el('div', { class: 'kt-bs-row' }, [el('div', { class: 'kt-bs-l kt-bs-muted', text: cfg.mode === 'by_type' ? 'Split percentages' : 'Total' }), total]);
        form.appendChild(tr);
        function sum() { var t = (cfg.shares || []).reduce(function (a, s) { return a + (Number(s.pct) || 0); }, 0); t = Math.round(t * 100) / 100; total.textContent = t + '%'; total.className = 'kt-bs-pill ' + (Math.abs(t - 100) < 0.01 ? 'ok' : 'warn'); }
        sum();
      } else if (cfg.mode === 'fixed') {
        (D.guardians || []).forEach(function (g) {
          if (Number(cfg.rest) === g.id) return;
          var cur = (cfg.fixed || []).filter(function (f) { return f.guardian_id === g.id; })[0];
          var i = num(cur ? cur.amount : '', '0.01', 110);
          i.placeholder = '0.00';
          i.addEventListener('input', function () {
            var a = (cfg.fixed || []).filter(function (f) { return f.guardian_id !== g.id; });
            if (Number(i.value) > 0) a.push({ guardian_id: g.id, amount: Number(i.value) });
            cfg.fixed = a; changed();
          });
          row(g.name + ' pays per invoice', i, el('span', { class: 'kt-bs-muted', text: '$' }));
        });
        var sr = sel(gopts, cfg.rest);
        sr.addEventListener('change', function () { cfg.rest = Number(sr.value); cfg.fixed = (cfg.fixed || []).filter(function (f) { return f.guardian_id !== cfg.rest; }); paint(); });
        row('Pays the rest of each invoice', sr);
      } else if (cfg.mode === 'by_type') {
        typeRows();
      } else if (cfg.mode === 'by_child') {
        (D.children || []).forEach(function (k) {
          var s = sel(gopts, (cfg.children || {})[k.id] || cfg.default);
          s.addEventListener('change', function () { cfg.children = cfg.children || {}; cfg.children[k.id] = Number(s.value); changed(); });
          row(k.first_name + (k.enrollment_status && k.enrollment_status !== 'active' && k.enrollment_status !== 'enrolled' ? ' (' + k.enrollment_status + ')' : '') + '’s charges', s);
        });
        var sd = sel(gopts, cfg.default);
        sd.addEventListener('change', function () { cfg.default = Number(sd.value); changed(); });
        row('Charges not for one child', sd);
      }
      function typeRows() {
        var o = gopts.concat([['split', 'Split by percentage']]);
        var st = sel(o, cfg.tuition), sf = sel(o, cfg.fees);
        st.addEventListener('change', function () { cfg.tuition = st.value === 'split' ? 'split' : Number(st.value); paint(); });
        sf.addEventListener('change', function () { cfg.fees = sf.value === 'split' ? 'split' : Number(sf.value); paint(); });
        row('Tuition (incl. the subsidy co-pay)', st);
        row('Fees & extras (late fees, trips, supplies, registration)', sf);
      }

      // effective date + save
      var dt = el('input', { type: 'date', style: IN + 'width:160px;' }); dt.value = D.today;
      var dr = el('div', { class: 'kt-bs-row', style: 'margin-top:6px;border-top:1px solid #F1F5F9;padding-top:10px;' }, [el('div', { class: 'kt-bs-l' }, [el('div', { text: 'Applies to invoices issued from' }), el('div', { class: 'kt-bs-muted', text: 'Earlier invoices that are already issued keep their split.' })]), dt]);
      form.appendChild(dr);
      var msg = el('div', { class: 'kt-bs-msg', 'aria-live': 'polite' });
      var save = el('button', { type: 'button', class: 'kt-bs-btn', 'data-kt-btn-free': '1' }, ['Save split']);
      form.appendChild(el('div', { class: 'kt-bs-row', style: 'justify-content:flex-end;' }, [msg, save]));
      root.appendChild(edit.el);

      // ── Preview
      var prev = card('Preview · latest invoice');
      var prevBody = el('div', { class: 'kt-bs-muted', text: 'Working it out…' });
      prev.body.appendChild(prevBody);
      root.appendChild(prev.el);

      // ── Guardians and how they pay
      var gc = card('Guardians and how they pay');
      var gt = el('table', { class: 'kt-bs-t', 'data-kt-no-filter': '1' });
      gt.appendChild(el('tr', {}, [el('th', { text: 'Guardian' }), el('th', { text: 'Billing emails' }), el('th', { text: 'Pays with' })]));
      (D.guardians || []).forEach(function (g) {
        var how = (g.methods || []).slice();
        if (g.last_payment) how.push('Last paid ' + money(g.last_payment.amount) + ' by ' + g.last_payment.method + ' on ' + String(g.last_payment.paid_at || '').slice(0, 10));
        if (!how.length) how.push(g.signed_in ? 'No payment yet' : 'Not signed in yet - pays once invited');
        gt.appendChild(el('tr', {}, [
          el('td', {}, [el('b', { text: g.name }), g.relationship ? el('div', { class: 'kt-bs-muted', text: g.relationship + (g.is_primary ? ' · primary' : '') }) : null]),
          el('td', {}, [el('span', { class: 'kt-bs-pill' + (g.can_receive_billing ? ' ok' : ''), text: g.can_receive_billing ? 'Yes' : 'No' })]),
          el('td', { class: 'kt-bs-muted', text: how.join(' · ') }),
        ]));
      });
      gc.body.appendChild(gt);
      if (D.family_card) gc.body.appendChild(el('div', { class: 'kt-bs-muted', text: D.family_card }));
      gc.body.appendChild(el('div', { class: 'kt-bs-muted', style: 'margin-top:6px;', text: 'A payment made by the family card or recorded without a payer goes first to whoever still owes on that invoice.' }));
      root.appendChild(gc.el);

      // ── History
      var hc = card('History');
      if (!(D.history || []).length) hc.body.appendChild(el('div', { class: 'kt-bs-muted', text: 'No changes yet. Older invoices follow the family’s earlier setting.' }));
      else {
        var ht = el('table', { class: 'kt-bs-t', 'data-kt-no-filter': '1' });
        ht.appendChild(el('tr', {}, [el('th', { text: 'From' }), el('th', { text: 'Split' }), el('th', { text: 'Changed by' })]));
        D.history.forEach(function (h) {
          ht.appendChild(el('tr', {}, [el('td', { style: 'white-space:nowrap;', text: h.effective_from }), el('td', {}, [el('b', { text: h.label }), el('div', { class: 'kt-bs-muted', text: h.text })]),
            el('td', { class: 'kt-bs-muted', text: (h.by || '—') + ' · ' + String(h.created_at || '').slice(0, 16) })]));
        });
        hc.body.appendChild(ht);
      }
      root.appendChild(hc.el);

      function showPreview(p, err) {
        prevBody.innerHTML = ''; prevBody.className = '';
        if (err) prevBody.appendChild(el('div', { class: 'kt-bs-pill warn', style: 'margin-bottom:6px;', text: err }));
        if (!p) { prevBody.appendChild(el('div', { class: 'kt-bs-muted', text: 'No invoice yet - the split applies from the first one.' })); return; }
        prevBody.appendChild(el('div', { class: 'kt-bs-muted', style: 'margin-bottom:6px;', text: 'Invoice ' + (p.invoice.number || '#' + p.invoice.id) + ' · ' + money(p.invoice.total) + (p.invoice.issued_at ? ' · issued ' + String(p.invoice.issued_at).slice(0, 10) : '') }));
        var t = el('table', { class: 'kt-bs-t', 'data-kt-no-filter': '1' });
        t.appendChild(el('tr', {}, [el('th', { text: 'Guardian' }), el('th', { class: 'n', text: 'Pays' }), el('th', { class: 'n', text: 'Share' })]));
        (p.shares || []).forEach(function (s) {
          var pct = p.invoice.total ? Math.round(s.share / p.invoice.total * 1000) / 10 : 0;
          t.appendChild(el('tr', {}, [el('td', { text: s.name }), el('td', { class: 'n', text: money(s.share) }), el('td', { class: 'n kt-bs-muted', text: pct + '%' })]));
        });
        prevBody.appendChild(t);
      }

      function changed() {
        var err = check();
        msg.textContent = err || ''; msg.style.color = err ? '#B45309' : '#166534';
        save.disabled = !!err && !/^Note:/.test(err);
        clearTimeout(timer);
        timer = setTimeout(function () {
          Api.post('/admin/families/' + familyId + '/billing-split/preview', { config: cfg }).then(function (r) { showPreview(r.preview, r.error && !err ? r.error : null); })
            .catch(function () { showPreview(D.preview, null); });
        }, 250);
      }
      save.addEventListener('click', function () {
        var err = check();
        if (err && !/^Note:/.test(err)) { msg.textContent = err; return; }
        save.disabled = true; msg.style.color = '#475569'; msg.textContent = 'Saving…';
        Api.put('/admin/families/' + familyId + '/billing-split', { config: cfg, effective_from: dt.value || D.today }).then(function (d) {
          D = d; cfg = JSON.parse(JSON.stringify(d.config || { mode: 'single' })); paint();
          var m = root.querySelector('.kt-bs-msg'); if (m) { m.style.color = '#166534'; m.textContent = '✓ Billing split saved'; }
          if (typeof opts.onSaved === 'function') { try { opts.onSaved(d); } catch (e) {} }
        }).catch(function (e) { save.disabled = false; msg.style.color = '#B91C1C'; msg.textContent = e.message || 'Could not save the split'; });
      });
      changed();
    }

    load();
    return { reload: load };
  };
})(window);

/* ═══ Each guardian's copy of a split invoice (2026-10-30) ═══
   KT.splitCopies(invoiceId) - a popup listing INV-1234-A, -B... with each guardian's share, paid and balance, the last
   emails, and View PDF / Email buttons (to the guardian's own address, or another you type). Server: /invoices/{id}/split-copies. */
(function (w) {
  'use strict';
  var KT = w.KT || (w.KT = {});
  if (KT.splitCopies) return;
  function money(v) { return '$' + (Number(v) || 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 }); }
  function esc(v) { return String(v == null ? '' : v).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }
  function headers() {
    var h = {}; try { var t = sessionStorage.getItem('kt_token') || localStorage.getItem('kt_token'); if (t) h.Authorization = 'Bearer ' + t; var ag = sessionStorage.getItem('kt_active_agency_id'); if (ag) h['X-Active-Agency-Id'] = ag; } catch (e) {}
    return h;
  }
  KT.splitCopies = function (invoiceId) {
    var Api = KT.Api, body = document.createElement('div');
    if (KT._billingSplitCss) KT._billingSplitCss();
    body.className = 'kt-bs';
    body.innerHTML = '<div class="kt-bs-muted">Loading…</div>';
    var open = (KT.Shell && KT.Shell.Modal && KT.Shell.Modal.open) ? KT.Shell.Modal.open : null;
    if (open) open({ title: '🧾 Guardian copies', body: body, large: true });
    function load() {
      Api.get('/invoices/' + invoiceId + '/split-copies').then(function (d) {
        var cs = d.copies || [];
        if (!cs.length) { body.innerHTML = '<div class="kt-bs-muted">This invoice is not split between guardians.</div>'; return; }
        var html = '<div style="overflow-x:auto;"><div class="kt-bs-muted" style="margin-bottom:10px;">Family invoice <b>' + esc(d.invoice.number) + '</b> · ' + money(d.invoice.total)
          + '. Each guardian receives their own copy for their share.</div><table class="kt-bs-t" data-kt-no-filter="1"><tr><th>Copy</th><th>Guardian</th><th class="n">Share</th><th class="n">Paid</th><th class="n">Balance</th><th>Emailed</th><th></th></tr>';
        cs.forEach(function (c) {
          var last = (c.sends || [])[0];
          html += '<tr><td style="white-space:nowrap;"><b>' + esc(c.number) + '</b></td><td>' + esc(c.name) + '<div class="kt-bs-muted">' + esc(c.email || 'no email') + '</div></td>'
            + '<td class="n">' + money(c.share) + '</td><td class="n">' + money(c.paid) + '</td><td class="n"><b>' + money(c.balance) + '</b></td>'
            + '<td class="kt-bs-muted" title="' + (last ? esc(last.to) : '') + '">' + (last ? esc(String(last.at || '').slice(0, 16)) : 'Not yet') + '</td>'
            + '<td style="white-space:nowrap;text-align:right;"><button type="button" class="kt-bs-btn" data-kt-btn-free="1" data-pdf="' + c.guardian_id + '" data-num="' + esc(c.number) + '" style="background:#fff;color:#1F6080;border-color:#9EC2E3;">View PDF</button> '
            + '<button type="button" class="kt-bs-btn" data-kt-btn-free="1" data-send="' + c.guardian_id + '" data-email="' + esc(c.email || '') + '">Email</button></td></tr>';
        });
        body.innerHTML = html + '</table></div><div class="kt-bs-msg" style="margin-top:8px;" aria-live="polite"></div>';
        var msg = body.querySelector('.kt-bs-msg');
        body.querySelectorAll('[data-pdf]').forEach(function (b) {
          b.addEventListener('click', function () {
            var base = (KT.API_BASE) || 'https://api.kiddietrac.com/api/v1';
            b.disabled = true;
            fetch(base + '/invoices/' + invoiceId + '/split-copies/' + b.getAttribute('data-pdf') + '/pdf', { headers: headers() }).then(function (r) {
              if (!r.ok) throw new Error('That copy could not be rendered.'); return r.blob();
            }).then(function (blob) {
              var u = URL.createObjectURL(blob);
              if (KT.openFile) KT.openFile(u, { title: b.getAttribute('data-num'), filename: b.getAttribute('data-num') + '.pdf', mime: 'application/pdf' });
              else { var a = document.createElement('a'); a.href = u; a.download = b.getAttribute('data-num') + '.pdf'; document.body.appendChild(a); a.click(); a.remove(); }
            }).catch(function (e) { msg.style.color = '#B91C1C'; msg.textContent = e.message; }).then(function () { b.disabled = false; });
          });
        });
        body.querySelectorAll('[data-send]').forEach(function (b) {
          b.addEventListener('click', function () {
            var def = b.getAttribute('data-email') || '';
            var go = function (to) {
              if (!to) return;
              b.disabled = true; msg.style.color = '#475569'; msg.textContent = 'Sending…';
              Api.post('/invoices/' + invoiceId + '/split-copies/' + b.getAttribute('data-send') + '/send', { to: to }).then(function (r) {
                msg.style.color = '#166534'; msg.textContent = '✓ ' + r.number + ' emailed to ' + r.to; load();
              }).catch(function (e) { msg.style.color = '#B91C1C'; msg.textContent = (e && e.message) || 'Could not send'; b.disabled = false; });
            };
            if (KT.prompt) KT.prompt({ title: 'Email this copy', okLabel: 'Send', fields: [{ key: 'to', label: 'Send to', value: def, placeholder: 'name@example.com' }] }).then(function (v) { go(typeof v === 'string' ? v.trim() : (v && v.to ? String(v.to).trim() : '')); });
            else go(def);
          });
        });
      }).catch(function (e) { body.innerHTML = '<div style="color:#B91C1C;">' + esc((e && e.message) || 'Could not load the copies.') + '</div>'; });
    }
    load();
  };
})(window);
