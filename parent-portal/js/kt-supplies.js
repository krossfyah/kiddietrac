/* ═══════════════════════════════════════════════════════════════════
   KiddieTrac — Supplies from home (2026-10-23)

   Anthony: "if an educator needs to request supplies from parent how can we wire this up
   to allow this to happen easily for each child?" — push + email + task + announcement;
   the agency's list (Diapering, Clothing, Outdoor & seasonal, Food & sleep) plus "Other"
   for anything; educators and admins send; it shows at drop-off on both sides.

   KT.Supplies.request(children, done)   the request dialog (one child or several)
   KT.Supplies.decorate(root, roomId)    fills [data-kt-sup-child] slots on roster cards
   KT.Supplies.parentCard(host)          the parent's "Please bring" card
   KT.Supplies.checkinAsk(host, ids)     "Did you bring…?" after One-Tap check-in
   Server: App\Support\Supplies, SupplyController.
   ═══════════════════════════════════════════════════════════════════ */
(function (w) {
  'use strict';
  var KT = w.KT = w.KT || {};
  if (KT.Supplies) return;

  var C = { ink: '#0F172A', sub: '#64748B', line: '#E2E8F0', brand: '#0E7490', soft: '#ECFEFF' };
  var STATUS = {
    open: ['Asked', '#92400E', '#FEF3C7'],
    coming: ['Will bring', '#1D4ED8', '#DBEAFE'],
    cant: ['Can’t bring', '#B91C1C', '#FEE2E2'],
    dropped: ['Dropped off', '#047857', '#D1FAE5'],
    received: ['Received', '#047857', '#D1FAE5'],
  };

  function api() { return KT.Api; }
  function el(tag, attrs, kids) {
    var e = document.createElement(tag);
    Object.keys(attrs || {}).forEach(function (k) {
      if (k === 'style') e.style.cssText = attrs[k];
      else if (k.slice(0, 2) === 'on') e.addEventListener(k.slice(2), attrs[k]);
      else e.setAttribute(k, attrs[k]);
    });
    [].concat(kids || []).forEach(function (c) { if (c == null) return; e.appendChild(typeof c === 'string' ? document.createTextNode(c) : c); });
    return e;
  }
  function toast(msg, kind) { if (KT.Dom && KT.Dom.toast) KT.Dom.toast(msg, kind || 'success'); }
  function itemsText(items) { return (items || []).map(function (i) { return i.label + (i.note ? ' (' + i.note + ')' : ''); }).join(', '); }
  function day(d) {
    if (!d) return '';
    try { return new Date(String(d).slice(0, 10) + 'T12:00:00').toLocaleDateString(undefined, { weekday: 'short', month: 'short', day: 'numeric' }); } catch (e) { return d; }
  }
  function today() { return (KT.agencyToday ? KT.agencyToday() : new Date().toISOString().slice(0, 10)); }
  function addDays(iso, n) { var d = new Date(iso + 'T12:00:00'); d.setDate(d.getDate() + n); return d.toISOString().slice(0, 10); }
  function pill(status) {
    var s = STATUS[status] || STATUS.open;
    return el('span', { style: 'display:inline-block;font-size:11px;font-weight:800;padding:2px 8px;border-radius:999px;color:' + s[1] + ';background:' + s[2] + ';white-space:nowrap;' }, s[0]);
  }
  (function css() {
    if (document.getElementById('kt-sup-css')) return;
    var st = document.createElement('style'); st.id = 'kt-sup-css';
    st.textContent = '.kt-sup-chip{display:inline-flex !important;align-items:center;gap:5px;box-sizing:border-box;height:auto !important;min-height:32px !important;'
      + 'padding:6px 13px !important;border:1.5px solid #E2E8F0 !important;background:#fff !important;color:#0F172A !important;border-radius:999px !important;'
      + 'font-size:13px !important;font-weight:700 !important;line-height:1.2 !important;white-space:nowrap;max-width:100%;cursor:pointer;box-shadow:none !important;transition:background .12s,border-color .12s}'
      + '.kt-sup-chip:hover{border-color:#94A3B8 !important}'
      + '.kt-sup-chip.on{background:#E0F7FA !important;border-color:#0E7490 !important;color:#0B5566 !important}'
      + '.kt-sup-chip.on::before{content:"\\2713";font-weight:900;margin-right:1px}'
      + '.kt-sup-sec{display:flex;flex-direction:column;gap:6px}'
      + '.kt-sup-grp{font-size:12px;font-weight:700;color:#334155;margin:6px 0 0}'
      + '.kt-sup-row{display:grid !important;grid-template-columns:minmax(0,38%) minmax(0,1fr) 28px;align-items:center;gap:8px;background:#F8FAFC;border:1px solid #E2E8F0;border-radius:10px;padding:5px 4px 5px 10px}'
      + '.kt-sup-row.free{grid-template-columns:minmax(0,1fr) minmax(0,1fr) 28px}'
      + '.kt-sup-row input{width:100%;box-sizing:border-box}'
      + '.kt-sup-quick{display:flex;gap:6px}'
      + '.kt-sup-quick>.kt-sup-chip{flex:1 1 0;justify-content:center;min-width:0}'
      + '.kt-sup-quick>.kt-sup-chip.on::before{content:none}'
      + '@media (max-width:600px){.kt-sup-chip{min-height:30px !important;padding:5px 11px !important;font-size:12.5px !important}'
      + '.kt-sup-sec{gap:5px}.kt-sup-grp{font-size:11.5px}}'
      /* A ticked chip carries aria-pressed, and kt-consistency-polish's switch guard (body button[aria-pressed]{padding:0
         !important;min-height:0 !important}) outranked .kt-sup-chip: ticked chips lost their padding and the text ran into
         the border. These outrank it, for the chips only. */
      + 'body .kt-sup-chip.kt-sup-chip[class]{padding:6px 13px !important;min-height:32px !important}'
      + 'body .kt-sup-chip.kt-sup-chip.sm[class]{padding:4px 11px !important;min-height:28px !important}'
      + '@media (max-width:600px){body .kt-sup-chip.kt-sup-chip[class]{padding:5px 11px !important;min-height:30px !important}'
      + 'body .kt-sup-chip.kt-sup-chip.sm[class]{padding:4px 10px !important;min-height:30px !important}}'
      + '.kt-sup-chip.dash{border-style:dashed !important;color:#0E7490 !important}'
      + '[data-kt-sup] button:not(.kt-sup-chip){height:auto !important;min-height:30px !important;padding:6px 11px !important;line-height:1.2 !important;white-space:nowrap}'
      + '.kt-sup-chip.sm{min-height:28px !important;padding:4px 11px !important;font-size:12.5px !important}';
    (document.head || document.documentElement).appendChild(st);
  })();
  var CHIP = '';
  function chip(label, extra) { var b = el('button', { type: 'button', class: 'kt-sup-chip' + (extra ? ' ' + extra : '') }, label); return b; }
  function paintChip(b, on) { b.classList.toggle('on', !!on); b.setAttribute('aria-pressed', on ? 'true' : 'false'); }

  /* ── The request dialog ─────────────────────────────────────────── */
  function request(children, done) {
    children = (children || []).filter(function (c) { return c && c.id; });
    if (!children.length) return;
    api().get('/provider/supplies/catalogue').then(function (cat) { openDialog(children, cat || {}, done); })
      .catch(function (e) { toast((e && e.message) || 'Could not load the supply list', 'error'); });
  }

  function openDialog(children, cat, done) {
    // data-kt-noband: kt-tab-band.js would restyle the chip rows as sub-tab bands; data-kt-nosechead: and the small capital
    // labels ("NEEDED BY") as section-heading bands.
    var body = el('div', { 'data-kt-noband': '1', 'data-kt-nosechead': '1', style: 'display:flex;flex-direction:column;gap:14px;' });
    var picked = {};                                  // child id -> true
    var LABEL = 'font-size:11px;font-weight:800;letter-spacing:.5px;color:' + C.sub + ';text-transform:uppercase;margin-bottom:6px;';

    if (children.length === 1) {
      picked[children[0].id] = true;
      body.appendChild(el('div', { style: 'font-size:14px;color:' + C.ink + ';' }, ['For ', el('strong', {}, children[0].name || 'this child'), '. Their family gets a notification, an email, a task, and a reminder the evening before.']));
    } else {
      var kidsBox = el('div', {});
      var head = el('div', { style: 'display:flex;align-items:center;justify-content:space-between;' }, [el('div', { style: LABEL }, 'Children')]);
      var all = el('button', { type: 'button', style: 'border:0;background:none;color:' + C.brand + ';font-weight:700;font-size:12.5px;cursor:pointer;' }, 'Select all');
      head.appendChild(all); kidsBox.appendChild(head);
      var kidChips = el('div', { style: 'display:flex;flex-wrap:wrap;gap:6px;' });
      var kidEls = [];
      children.forEach(function (c) {
        var b = chip(c.name || ('Child ' + c.id));
        b.addEventListener('click', function () { picked[c.id] = !picked[c.id]; paintChip(b, picked[c.id]); });
        kidEls.push([b, c]); kidChips.appendChild(b);
      });
      all.addEventListener('click', function () { var on = kidEls.some(function (x) { return !picked[x[1].id]; }); kidEls.forEach(function (x) { picked[x[1].id] = on; paintChip(x[0], on); }); all.textContent = on ? 'Clear' : 'Select all'; });
      kidsBox.appendChild(kidChips);
      body.appendChild(kidsBox);
    }

    // What: the agency's list, then "Other".
    var chosen = [];                                  // [{label, noteIn, row}]
    var chosenBox = el('div', { style: 'display:flex;flex-direction:column;gap:6px;' });
    var listCount = el('div', { style: LABEL + 'margin-bottom:0;' }, 'Your list');
    var chosenWrap = el('div', { class: 'kt-sup-sec', style: 'display:none;' }, [listCount, chosenBox]);
    function countList() { chosenWrap.style.display = chosen.length ? '' : 'none'; listCount.textContent = 'Your list (' + chosen.length + ')'; }
    function addRow(label, chip, free) {
      var labelIn = free ? el('input', { type: 'text', maxlength: '60', placeholder: 'What do you need?', style: 'flex:1 1 0;min-width:0;height:34px;padding:0 9px;border:1.5px solid ' + C.brand + ';border-radius:8px;font-size:16px;' }) : null;
      var noteIn = el('input', { type: 'text', maxlength: '120', placeholder: free ? 'Details (optional)' : 'Size / amount', style: 'flex:1 1 0;min-width:0;height:34px;padding:0 9px;border:1px solid #CBD5E1;border-radius:8px;font-size:16px;' });
      var rm = el('button', { type: 'button', 'aria-label': 'Remove', 'data-kt-nobreathe': '1', style: 'flex:0 0 auto;border:0;background:none;color:' + C.sub + ';font-size:20px;cursor:pointer;padding:0 4px !important;min-height:0 !important;width:28px;' }, '×');
      var row = el('div', { class: 'kt-sup-row' + (free ? ' free' : '') },
        [free ? labelIn : el('strong', { title: label, style: 'min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;font-size:13.5px;color:' + C.ink + ';' }, label), noteIn, rm]);
      var entry = { label: label, labelIn: labelIn, noteIn: noteIn, row: row, chip: chip };
      rm.addEventListener('click', function () { chosen.splice(chosen.indexOf(entry), 1); row.remove(); if (chip) paintChip(chip, false); countList(); });
      chosen.push(entry); chosenBox.appendChild(row); countList();
      return entry;
    }
    var listBox = el('div', {});
    var listHead = el('div', { style: 'display:flex;align-items:center;justify-content:space-between;' }, [el('div', { style: LABEL }, 'What do they need?')]);
    if (cat.can_edit) {
      listHead.appendChild(el('button', { type: 'button', style: 'border:0;background:none;color:' + C.brand + ';font-weight:700;font-size:12.5px;cursor:pointer;',
        onclick: function () { editList(cat, function (groups) { cat.groups = groups; openDialog(children, cat, done); }); } }, '✎ Edit list'));
    }
    listBox.appendChild(listHead);
    (cat.groups || []).forEach(function (g) {
      listBox.appendChild(el('div', { class: 'kt-sup-grp', style: 'margin-bottom:4px;' }, g.name));
      var row = el('div', { style: 'display:flex;flex-wrap:wrap;gap:6px;' });
      (g.items || []).forEach(function (it) {
        var b = chip(it);
        b.addEventListener('click', function () {
          var have = chosen.filter(function (x) { return x.chip === b; })[0];
          if (have) { chosen.splice(chosen.indexOf(have), 1); have.row.remove(); paintChip(b, false); countList(); }
          else { addRow(it, b, false); paintChip(b, true); }
        });
        row.appendChild(b);
      });
      listBox.appendChild(row);
    });
    var other = chip('＋ Other', 'dash'); other.style.marginTop = '10px';
    other.addEventListener('click', function () { addRow('', null, true); });
    listBox.appendChild(other);
    body.appendChild(listBox);
    body.appendChild(chosenWrap);

    // When + a note.
    var dateIn = el('input', { type: 'date', min: today(), 'aria-label': 'Needed by', style: 'width:100%;box-sizing:border-box;height:34px;padding:0 10px;border:1px solid #CBD5E1;border-radius:8px;font-size:14px;' });
    var quick = el('div', { class: 'kt-sup-quick' }), qbs = [];
    function paintQuick() { qbs.forEach(function (x) { paintChip(x[0], dateIn.value && dateIn.value === addDays(today(), x[1])); }); }
    [['Tomorrow', 1], ['In 2 days', 2], ['Next week', 7]].forEach(function (q) {
      var qb = chip(q[0], 'sm'); qb.addEventListener('click', function () { dateIn.value = addDays(today(), q[1]); paintQuick(); }); qbs.push([qb, q[1]]); quick.appendChild(qb);
    });
    dateIn.addEventListener('change', paintQuick); dateIn.addEventListener('input', paintQuick);
    body.appendChild(el('div', { class: 'kt-sup-sec' }, [el('div', { style: LABEL + 'margin-bottom:0;' }, 'Needed by (optional)'), quick, dateIn]));
    var noteIn = el('textarea', { rows: '2', maxlength: '1000', placeholder: 'A note for the family (optional)', style: 'width:100%;box-sizing:border-box;padding:8px 10px;border:1px solid #CBD5E1;border-radius:8px;font:inherit;font-size:14px;resize:vertical;' });
    body.appendChild(noteIn);
    var msg = el('div', { style: 'font-size:12.5px;font-weight:700;color:#B91C1C;min-height:16px;' });
    body.appendChild(msg);

    setTimeout(function () { try { var a = document.activeElement; if (a && /^(INPUT|TEXTAREA|SELECT)$/.test(a.tagName)) a.blur(); } catch (e) {} }, 30);
    KT.Shell.Modal.open({
      title: '🧺 Request supplies',
      body: body,
      actions: [
        { label: 'Cancel' },
        { label: 'Send request', primary: true, busyLabel: 'Sending…', handler: function () {
          msg.textContent = '';
          var ids = Object.keys(picked).filter(function (k) { return picked[k]; }).map(Number);
          if (!ids.length) { msg.textContent = 'Pick at least one child.'; return false; }
          var items = chosen.map(function (x) { return { label: x.labelIn ? x.labelIn.value.trim() : x.label, note: x.noteIn.value.trim() || null }; })
            .filter(function (x) { return x.label; });
          if (!items.length) { msg.textContent = 'Pick what they need, or add it under Other.'; return false; }
          return api().post('/provider/supplies', { child_ids: ids, items: items, note: noteIn.value.trim() || null, needed_by: dateIn.value || null })
            .then(function () {
              toast('🧺 Request sent to ' + (ids.length === 1 ? 'the family' : ids.length + ' families'));
              if (done) try { done(); } catch (e) {}
            });
        } },
      ],
    });
  }

  /* ── Edit the agency's list (agency admins) ─────────────────────── */
  function editList(cat, then) {
    var body = el('div', { 'data-kt-noband': '1', style: 'display:flex;flex-direction:column;gap:10px;' });
    body.appendChild(el('div', { style: 'font-size:13px;color:' + C.sub + ';' }, 'One item per line. Empty a group to remove it. Educators can still write anything under Other.'));
    var rows = [];
    function addGroup(g) {
      var name = el('input', { type: 'text', maxlength: '40', value: g.name || '', placeholder: 'Group name', style: 'height:32px;padding:0 9px;border:1px solid #CBD5E1;border-radius:8px;font-size:14px;font-weight:700;' });
      var items = el('textarea', { rows: '3', style: 'width:100%;box-sizing:border-box;padding:7px 9px;border:1px solid #CBD5E1;border-radius:8px;font:inherit;font-size:13.5px;resize:vertical;' });
      items.value = (g.items || []).join('\n');
      var box = el('div', { style: 'display:flex;flex-direction:column;gap:5px;border:1px solid ' + C.line + ';border-radius:10px;padding:8px;' }, [name, items]);
      rows.push([name, items]); body.insertBefore(box, addBtn);
    }
    var addBtn = chip('＋ Add group', 'dash'); addBtn.style.alignSelf = 'flex-start'; addBtn.addEventListener('click', function () { addGroup({}); });
    body.appendChild(addBtn);
    (cat.groups || []).forEach(addGroup);
    var reset = false;
    setTimeout(function () { try { var a = document.activeElement; if (a && /^(INPUT|TEXTAREA|SELECT)$/.test(a.tagName)) a.blur(); } catch (e) {} }, 30);
    KT.Shell.Modal.open({
      title: '✎ Supply list',
      body: body,
      actions: [
        { label: 'Reset to default', handler: function () { reset = true; return save([]); } },
        { label: 'Cancel', handler: function () { setTimeout(function () { then(cat.groups); }, 0); } },
        { label: 'Save list', primary: true, handler: function () {
          return save(rows.map(function (r) { return { name: r[0].value.trim(), items: r[1].value.split('\n').map(function (s) { return s.trim(); }).filter(Boolean) }; })
            .filter(function (g) { return g.name && g.items.length; }));
        } },
      ],
    });
    function save(groups) {
      return api().put('/provider/supplies/catalogue', { groups: groups }).then(function (r) {
        toast(reset ? 'Supply list reset' : 'Supply list saved');
        setTimeout(function () { then((r && r.groups) || groups); }, 0);
      });
    }
  }

  /* ── Roster cards: what is outstanding, and Received ────────────── */
  function decorate(root, roomId) {
    if (!root || !roomId) return;
    api().get('/provider/supplies?room_id=' + encodeURIComponent(roomId)).then(function (r) {
      var by = {};
      ((r && r.requests) || []).forEach(function (q) { (by[q.child_id] = by[q.child_id] || []).push(q); });
      root.querySelectorAll('[data-kt-sup-child]').forEach(function (slot) {
        slot.innerHTML = '';
        (by[slot.getAttribute('data-kt-sup-child')] || []).forEach(function (q) { slot.appendChild(staffLine(q, function () { decorate(root, roomId); })); });
      });
    }).catch(function () {});
  }

  function staffLine(q, refresh) {
    var line = el('div', { 'data-kt-noband': '1', 'data-kt-sup': '1', style: 'margin-top:7px;padding:6px 8px;border-radius:9px;background:#FFFBEB;border:1px solid #FDE68A;font-size:12.5px;color:#78350F;display:flex;flex-wrap:wrap;align-items:center;gap:6px;' });
    line.appendChild(el('span', { style: 'flex:1 1 140px;min-width:0;' }, ['🧺 ', el('strong', {}, itemsText(q.items)), q.needed_by ? ' · by ' + day(q.needed_by) : '']));
    line.appendChild(pill(q.status));
    if (q.response_note) line.appendChild(el('span', { style: 'flex-basis:100%;font-size:12px;color:#92400E;' }, '“' + q.response_note + '”'));
    var got = el('button', { type: 'button', style: 'border:0;border-radius:7px;background:#047857;color:#fff;font-weight:800;font-size:12px;padding:5px 9px;cursor:pointer;' }, '✓ Received');
    got.addEventListener('click', function (e) {
      e.stopPropagation(); got.disabled = true; got.textContent = 'Saving…';
      api().post('/provider/supplies/' + q.id + '/received', {}).then(function () { toast('Marked received'); refresh(); })
        .catch(function (er) { got.disabled = false; got.textContent = '✓ Received'; toast((er && er.message) || 'Could not save', 'error'); });
    });
    var x = el('button', { type: 'button', title: 'Cancel this request', 'aria-label': 'Cancel this request', style: 'border:0;background:none;color:#92400E;font-size:15px;cursor:pointer;padding:0 3px;' }, '×');
    x.addEventListener('click', function (e) {
      e.stopPropagation();
      if (x.getAttribute('data-sure') !== '1') { x.setAttribute('data-sure', '1'); x.textContent = 'Cancel request?'; x.style.fontSize = '11.5px'; x.style.fontWeight = '800'; setTimeout(function () { x.removeAttribute('data-sure'); x.textContent = '×'; x.style.fontSize = '15px'; }, 3000); return; }
      api().post('/provider/supplies/' + q.id + '/cancel', {}).then(function () { toast('Request cancelled'); refresh(); }).catch(function (er) { toast((er && er.message) || 'Could not cancel', 'error'); });
    });
    line.appendChild(got); line.appendChild(x);
    return line;
  }

  /* ── The parent's card ──────────────────────────────────────────── */
  function parentCard(host) {
    if (!host) return;
    api().get('/parent/supplies').then(function (r) {
      var open = ((r && r.requests) || []).filter(function (q) { return ['open', 'coming', 'cant'].indexOf(q.status) !== -1; });
      host.innerHTML = '';
      if (!open.length) return;
      var card = el('div', { 'data-kt-noband': '1', 'data-kt-sup': '1', style: 'background:#FFFBEB;border:1px solid #FCD34D;border-radius:14px;padding:12px 14px;margin:0 0 14px;' });
      card.appendChild(el('div', { style: 'font-weight:800;font-size:15px;color:#78350F;margin-bottom:6px;' }, '🧺 Please bring'));
      open.forEach(function (q) { card.appendChild(parentLine(q, function () { parentCard(host); })); });
      host.appendChild(card);
    }).catch(function () {});
  }

  function parentLine(q, refresh) {
    var row = el('div', { style: 'border-top:1px solid #FDE68A;padding:8px 0 4px;' });
    row.appendChild(el('div', { style: 'font-size:14px;color:' + C.ink + ';line-height:1.4;' }, [el('strong', {}, (q.child_name || 'Your child') + ': '), itemsText(q.items)]));
    var meta = [q.needed_by ? 'Needed by ' + day(q.needed_by) : null, q.requested_by_name ? 'from ' + q.requested_by_name : null].filter(Boolean).join(' · ');
    if (meta || q.note) row.appendChild(el('div', { style: 'font-size:12.5px;color:#92400E;margin-top:2px;' }, [meta, q.note ? (meta ? ' — ' : '') + q.note : ''].join('')));
    if (q.status !== 'open') row.appendChild(el('div', { style: 'margin-top:4px;' }, [pill(q.status)]));
    var btns = el('div', { style: 'display:flex;flex-wrap:wrap;gap:6px;margin-top:7px;' });
    var B = 'border-radius:9px;font-weight:800;font-size:13px;padding:7px 11px;cursor:pointer;';
    var note = el('input', { type: 'text', maxlength: '500', placeholder: 'Anything to tell them? (optional)', style: 'display:none;flex:1 1 100%;height:32px;padding:0 9px;border:1px solid #FCA5A5;border-radius:8px;font-size:13.5px;' });
    function send(resp, b) {
      b.disabled = true;
      api().post('/parent/supplies/respond', { ids: [q.id], response: resp, note: note.value.trim() || null })
        .then(function () { toast(resp === 'dropped' ? 'Thanks — marked as dropped off' : resp === 'coming' ? 'Thanks — they know it’s coming' : 'Thanks — they’ll be in touch'); refresh(); })
        .catch(function (e) { b.disabled = false; toast((e && e.message) || 'Could not send', 'error'); });
    }
    if (q.status !== 'coming') btns.appendChild(el('button', { type: 'button', style: B + 'border:1.5px solid #93C5FD;background:#fff;color:#1D4ED8;', onclick: function () { send('coming', this); } }, '👍 Will bring it'));
    btns.appendChild(el('button', { type: 'button', style: B + 'border:0;background:#047857;color:#fff;', onclick: function () { send('dropped', this); } }, '✓ Dropped it off'));
    var cant = el('button', { type: 'button', style: B + 'border:1.5px solid #FCA5A5;background:#fff;color:#B91C1C;' }, 'Can’t');
    cant.addEventListener('click', function () {
      if (note.style.display === 'none') { note.style.display = 'block'; cant.textContent = 'Send “can’t”'; return; }
      send('cant', cant);
    });
    if (q.status !== 'cant') btns.appendChild(cant);
    btns.appendChild(note);
    row.appendChild(btns);
    return row;
  }

  /* ── One-Tap check-in: "Did you bring…?" ────────────────────────── */
  function checkinAsk(host, childIds) {
    if (!host || !childIds || !childIds.length) return;
    var want = childIds.map(String);
    api().get('/parent/supplies').then(function (r) {
      var open = ((r && r.requests) || []).filter(function (q) { return want.indexOf(String(q.child_id)) !== -1 && ['open', 'coming', 'cant'].indexOf(q.status) !== -1; });
      if (!open.length) return;
      var box = el('div', { 'data-kt-noband': '1', 'data-kt-sup': '1', style: 'background:#fff;color:' + C.ink + ';border-radius:16px;padding:14px 16px;max-width:320px;width:100%;margin-top:14px;text-align:left;box-sizing:border-box;' });
      box.appendChild(el('div', { style: 'font-weight:800;font-size:16px;margin-bottom:6px;' }, '🧺 Did you bring…?'));
      open.forEach(function (q) { box.appendChild(el('div', { style: 'font-size:14px;line-height:1.4;margin:3px 0;' }, [el('strong', {}, (q.child_name || '') + ': '), itemsText(q.items)])); });
      var row = el('div', { style: 'display:flex;gap:8px;margin-top:10px;' });
      var yes = el('button', { type: 'button', style: 'flex:1;border:0;border-radius:11px;background:#047857;color:#fff;font-weight:800;font-size:14px;padding:11px;cursor:pointer;' }, '✓ Yes, dropped off');
      var no = el('button', { type: 'button', style: 'flex:1;border:1.5px solid #CBD5E1;border-radius:11px;background:#fff;color:#334155;font-weight:800;font-size:14px;padding:11px;cursor:pointer;' }, 'Not today');
      yes.addEventListener('click', function () {
        yes.disabled = true;
        api().post('/parent/supplies/respond', { ids: open.map(function (q) { return q.id; }), response: 'dropped' })
          .then(function () { box.innerHTML = ''; box.appendChild(el('div', { style: 'font-weight:800;font-size:15px;color:#047857;' }, '✓ Thanks — the educator will check it in.')); })
          .catch(function (e) { yes.disabled = false; toast((e && e.message) || 'Could not send', 'error'); });
      });
      no.addEventListener('click', function () { box.remove(); });
      row.appendChild(yes); row.appendChild(no); box.appendChild(row);
      host.appendChild(box);
    }).catch(function () {});
  }

  KT.Supplies = { request: request, decorate: decorate, parentCard: parentCard, checkinAsk: checkinAsk };
})(window);
