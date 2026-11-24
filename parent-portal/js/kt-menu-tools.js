/* ═══════════════════════════════════════════════════════════════════
   Weekly menu tools (2026-11-03) - mounted by screen-v22p53.js renderMenu.

   - Copy last week: fills this week's grid from the week before (photos kept); you
     review it and press Save menu, like every other fill.
   - Save as template / Apply a template: reusable menu weeks per agency
     (MenuTemplateController). Apply goes to one or several centres, only ones you work
     in (the server checks every one), as DRAFTS; a published week is never overwritten.
   - Canada's Food Guide check: a friendly per-day ✓ / ⚠ summary with suggestions.
     Guidance only, never a compliance certificate, and never shown to US agencies
     (they keep CACFP as it is). It reads the dish names on screen, so it follows edits.
   ═══════════════════════════════════════════════════════════════════ */
(function (w) {
  'use strict';
  var KT = (w.KT = w.KT || {});
  var MEALS = ['breakfast', 'morning_snack', 'lunch', 'afternoon_snack', 'dinner'];
  var MEAL_L = { breakfast: 'Breakfast', morning_snack: 'Morning snack', lunch: 'Lunch', afternoon_snack: 'Afternoon snack', dinner: 'Dinner' };
  var DAY_L = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];

  function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }
  function toast(i, t, m, c) { try { if (KT.toast) KT.toast(i, t, m || '', c || '#1F6080'); } catch (e) {} }
  function el(html) { var d = document.createElement('div'); d.innerHTML = html.trim(); return d.firstChild; }
  function modal(o) { return (KT.Shell && KT.Shell.Modal) ? KT.Shell.Modal.open(o) : null; }
  function ymd(d) { return d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0'); }
  function addDays(s, n) { var p = s.split('-'); return ymd(new Date(+p[0], +p[1] - 1, +p[2] + n)); }
  function fmtWeek(s) { var p = String(s).split('-'); return new Date(+p[0], +p[1] - 1, +p[2]).toLocaleDateString(undefined, { month: 'short', day: 'numeric', year: 'numeric' }); }

  // ───────────────────────── Canada's Food Guide check ─────────────────────────
  /* Keyword rules over dish names. Words are matched whole (with simple plurals), accents
     folded, English plus a few common French words. Deliberately forgiving: it nudges. */
  function norm(s) { return String(s || '').toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, '').replace(/[^a-z0-9 ]+/g, ' '); }
  function rx(words) { return new RegExp('\\b(' + words.join('|') + ')(e?s)?\\b'); }
  var VEG_FRUIT = rx(['vegetable', 'veggie', 'veg', 'salad', 'carrot', 'broccoli', 'cauliflower', 'pea', 'green bean', 'corn', 'spinach', 'kale', 'lettuce', 'cucumber',
    'tomato', 'pepper', 'zucchini', 'squash', 'pumpkin', 'sweet potato', 'potato', 'yam', 'beet', 'celery', 'cabbage', 'coleslaw', 'mushroom', 'onion', 'edamame', 'asparagus',
    'avocado', 'guacamole', 'fruit', 'apple', 'applesauce', 'banana', 'orange', 'clementine', 'mandarin', 'pear', 'peach', 'plum', 'grape', 'berry', 'berrie', 'strawberry', 'strawberrie',
    'blueberry', 'blueberrie', 'raspberry', 'raspberrie', 'melon', 'watermelon', 'cantaloupe', 'honeydew', 'kiwi', 'mango', 'pineapple', 'cherry', 'cherrie', 'apricot', 'raisin', 'date',
    'stir fry', 'ratatouille', 'minestrone', 'vegetable soup', 'legume', 'legumes', 'pomme', 'banane', 'carotte', 'fraise']);
  var WHOLE_GRAIN = rx(['whole grain', 'whole wheat', 'wholewheat', 'whole meal', 'wholemeal', 'multigrain', 'brown rice', 'wild rice', 'oat', 'oatmeal', 'oats', 'porridge', 'gruau',
    'barley', 'quinoa', 'bulgur', 'buckwheat', 'millet', 'rye', 'pumpernickel', 'bran', 'whole grain pasta', 'whole wheat pasta', 'whole wheat pita', 'whole wheat tortilla', 'corn tortilla', 'farro', 'spelt', 'granola', 'muesli', 'shredded wheat', 'ble entier']);
  var PROTEIN = rx(['chicken', 'turkey', 'beef', 'pork', 'ham', 'lamb', 'meatball', 'meat', 'fish', 'salmon', 'tuna', 'cod', 'haddock', 'tilapia', 'sardine', 'shrimp', 'egg', 'omelette', 'omelet',
    'frittata', 'bean', 'lentil', 'chickpea', 'hummus', 'houmous', 'dal', 'dahl', 'tofu', 'tempeh', 'edamame', 'yogurt', 'yoghurt', 'cheese', 'cottage cheese', 'milk', 'kefir', 'chili', 'chilli',
    'seed butter', 'sunflower seed', 'pumpkin seed', 'soy', 'peanut butter', 'nut butter', 'poulet', 'oeuf', 'fromage', 'lait', 'poisson', 'haricot', 'lentille', 'pois chiche']);
  var WATER = /\bwater\b|\beau\b/;
  var SUGARY_DRINK = rx(['juice', 'jus', 'pop', 'soda', 'soft drink', 'cola', 'lemonade', 'iced tea', 'fruit drink', 'fruit punch', 'punch', 'sports drink', 'energy drink',
    'chocolate milk', 'hot chocolate', 'flavoured milk', 'flavored milk', 'milkshake', 'slushie']);
  var HIGH_SUGAR = rx(['candy', 'candie', 'chocolate bar', 'cake', 'cupcake', 'cookie', 'donut', 'doughnut', 'brownie', 'pastry', 'pastrie', 'frosting', 'icing', 'ice cream', 'popsicle',
    'pudding', 'jello', 'gummy', 'gummie', 'gummies', 'fruit snack', 'fruit gummies', 'marshmallow', 'pop tart', 'toaster pastry', 'syrup', 'sugary cereal', 'frosted', 'sprinkle',
    'danish', 'cinnamon roll', 'sweet roll', 'chocolate chip', 'nutella', 'caramel', 'toffee', 'fudge', 'sundae']);

  function check(cells, days) {
    /* cells: {"<dow>-<meal>": "dish name"}  ->  [{dow, label, ok, items:[{ok, text}], flags:[...] }] */
    return days.map(function (dow) {
      var all = MEALS.map(function (m) { return { m: m, n: norm(cells[dow + '-' + m]) }; }).filter(function (x) { return x.n.trim(); });
      var lunch = norm(cells[dow + '-lunch']);
      var any = function (re) { return all.some(function (x) { return re.test(x.n); }); };
      var res = { dow: dow, label: DAY_L[dow - 1] || ('Day ' + dow), empty: !all.length, items: [], flags: [] };
      if (res.empty) return res;
      res.items.push(lunch.trim() ? (VEG_FRUIT.test(lunch)
        ? { ok: true, text: 'Vegetables or fruit at lunch' }
        : { ok: false, text: 'Add a vegetable or fruit to lunch', tip: 'e.g. a side of cucumber, peas or orange slices — half the plate is the Food Guide\'s picture.' })
        : { ok: false, text: 'No lunch on the menu', tip: 'Plan a lunch with a vegetable or fruit, a whole grain and a protein food.' });
      res.items.push(any(WHOLE_GRAIN) ? { ok: true, text: 'A whole-grain food' }
        : { ok: false, text: 'Include a whole-grain food', tip: 'e.g. whole wheat bread or pasta, brown rice, oatmeal, barley or quinoa.' });
      res.items.push(any(PROTEIN) ? { ok: true, text: 'A protein food' }
        : { ok: false, text: 'Include a protein food', tip: 'e.g. beans, lentils, eggs, fish, chicken, tofu, yogurt or cheese (check allergy lists).' });
      res.items.push(any(WATER) ? { ok: true, text: 'Water as the drink' }
        : { ok: false, text: 'Show water as the drink', tip: 'Add "water" to a meal (e.g. "Chicken wrap, water"): water is the Food Guide\'s drink of choice.' });
      all.forEach(function (x) {
        var raw = cells[dow + '-' + x.m];
        if (SUGARY_DRINK.test(x.n) && !/\b(no|unsweetened|plain)\b/.test(x.n)) res.flags.push({ text: '"' + raw + '" (' + MEAL_L[x.m].toLowerCase() + ') looks like a sugary drink', tip: 'Offer water, or plain unsweetened milk, instead of juice or sweetened drinks.' });
        else if (HIGH_SUGAR.test(x.n)) res.flags.push({ text: '"' + raw + '" (' + MEAL_L[x.m].toLowerCase() + ') may be high in sugar', tip: 'Fine now and then; fruit, plain yogurt or whole-grain crackers make an everyday swap.' });
      });
      res.ok = res.items.every(function (i) { return i.ok; }) && !res.flags.length;
      return res;
    });
  }

  function guideHtml(rows) {
    var real = rows.filter(function (r) { return !r.empty; });
    if (!real.length) return '';
    var good = real.filter(function (r) { return r.ok; }).length;
    return '<div class="kmt-fg" style="margin-top:14px;border:1px solid #E2E8F0;border-radius:12px;background:#fff;padding:12px 14px;">'
      + '<div style="display:flex;flex-wrap:wrap;align-items:baseline;gap:6px 12px;margin:0 0 8px;"><div style="font-weight:800;font-size:14px;color:#0D1B2A;">🍎 Canada\'s Food Guide check</div>'
      + '<div style="font-size:12px;color:#64748B;">' + good + ' of ' + real.length + ' days look balanced. Friendly guidance based on Canada\'s Food Guide — not a compliance check or certification.</div></div>'
      + '<div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(min(100%,180px),1fr));gap:8px;">'
      + real.map(function (r) {
          var warn = r.items.filter(function (i) { return !i.ok; }).length + r.flags.length;
          return '<details style="border:1px solid ' + (r.ok ? '#BBF7D0' : '#FDE68A') + ';background:' + (r.ok ? '#F0FDF4' : '#FFFBEB') + ';border-radius:10px;padding:7px 9px;">'
            + '<summary style="cursor:pointer;font-size:13px;font-weight:800;color:' + (r.ok ? '#166534' : '#92400E') + ';">' + (r.ok ? '✓ ' : '⚠ ') + esc(r.label)
            + '<span style="font-weight:600;font-size:11.5px;margin-left:6px;">' + (r.ok ? 'balanced' : warn + ' suggestion' + (warn === 1 ? '' : 's')) + '</span></summary>'
            + '<ul style="margin:6px 0 0;padding-left:0;list-style:none;font-size:12px;color:#334155;">'
            + r.items.map(function (i) { return '<li style="margin:0 0 4px;">' + (i.ok ? '✓ ' : '⚠ ') + esc(i.text) + (i.tip ? '<div style="color:#64748B;font-size:11.5px;margin-left:14px;">' + esc(i.tip) + '</div>' : '') + '</li>'; }).join('')
            + r.flags.map(function (f) { return '<li style="margin:0 0 4px;color:#92400E;">⚠ ' + esc(f.text) + '<div style="color:#64748B;font-size:11.5px;margin-left:14px;">' + esc(f.tip) + '</div></li>'; }).join('')
            + '</ul></details>';
        }).join('') + '</div></div>';
  }

  // ───────────────────────── mount ─────────────────────────
  /* ctx: { cid, weekStart, centres, openDays, isEditing(), setEditing(on), cells(), fillCell(dow, meal, item), reload() } */
  function mount(main, ctx) {
    var bar = main.querySelector('#mp-tools'), fg = main.querySelector('#mp-fg');
    if (!bar) return null;
    var info = { templates: [], centres: [], food_guide: false };
    var B = 'height:32px;box-sizing:border-box;padding:0 12px;border:1px solid #CBD5E1;border-radius:8px;background:#fff;color:#1F6080;font:inherit;font-size:12.5px;font-weight:700;cursor:pointer;display:inline-flex;align-items:center;gap:6px;white-space:nowrap;';
    bar.innerHTML = '<div style="display:flex;flex-wrap:wrap;gap:8px;margin-top:8px;">'
      + '<button type="button" data-kt-inpage data-kt-iconized="1" id="mt-copy" style="' + B + '" title="Fill this week with last week\'s dishes, to review and save">⧉ Copy last week</button>'
      + '<button type="button" data-kt-inpage data-kt-iconized="1" id="mt-save" data-kt-always-save="1" style="' + B + '" title="Keep this saved week to reuse later">💾 Save as template</button>'
      + '<button type="button" data-kt-inpage data-kt-iconized="1" id="mt-apply" style="' + B + '" title="Use a saved menu week for this or other centres">📋 Apply a template…</button></div>';

    function refresh() {
      if (!fg) return;
      fg.innerHTML = info.food_guide ? guideHtml(check(ctx.cells(), ctx.openDays)) : '';
    }
    KT.Api.get('/operations/menu/templates').then(function (r) {
      info = r || info; refresh();
    }).catch(function () { refresh(); });

    bar.querySelector('#mt-copy').onclick = async function () {
      var btn = this;
      var prevWeek = addDays(ctx.weekStart, -7);
      var r;
      btn.disabled = true;
      try { r = await KT.Api.get('/operations/menu?centre_id=' + ctx.cid + '&week_start=' + prevWeek); } catch (e) { r = null; }
      btn.disabled = false;
      var items = (r && r.items) || [];
      if (!items.length) { toast('ℹ️', 'Nothing to copy', 'The week of ' + fmtWeek(prevWeek) + ' has no saved dishes.'); return; }
      var cells = ctx.cells(), filled = Object.keys(cells).filter(function (k) { return cells[k]; }).length;
      var go = function () {
        if (!ctx.isEditing()) ctx.setEditing(true);
        // Every slot follows last week: a slot it left empty is emptied here too.
        ctx.openDays.forEach(function (d) { MEALS.forEach(function (m) { ctx.fillCell(d, m, null); }); });
        items.forEach(function (it) { if (ctx.openDays.indexOf(+it.day_of_week) !== -1) ctx.fillCell(+it.day_of_week, it.meal_type, it); });
        refresh();
        var msg = main.querySelector('#mp-msg');
        if (msg) { msg.style.color = '#047857'; msg.textContent = 'Copied ' + items.length + ' dishes from the week of ' + fmtWeek(prevWeek) + '. Review them, then press Save menu.'; }
      };
      if (!filled) { go(); return; }
      modal({ title: 'Replace this week\'s dishes?', body: '<p style="margin:0;font-size:14px;color:#334155;line-height:1.5;">This week already has ' + filled + ' dish' + (filled === 1 ? '' : 'es') + '. Copying replaces them with the ' + items.length + ' from the week of ' + esc(fmtWeek(prevWeek)) + '. Nothing is saved until you press Save menu.</p>',
        actions: [{ label: 'Cancel' }, { label: 'Replace with last week', primary: true, handler: function () { setTimeout(go, 0); return true; } }] });
    };

    bar.querySelector('#mt-save').onclick = function () {
      if (ctx.isEditing()) { toast('💾', 'Save the menu first', 'Press Save menu, then save the week as a template.'); return; }
      var cells = ctx.cells();
      if (!Object.keys(cells).some(function (k) { return cells[k]; })) { toast('ℹ️', 'Nothing to save', 'This week has no dishes yet.'); return; }
      var box = el('<div><label for="mt-name" style="display:block;font-size:12px;font-weight:700;color:#475569;margin:0 0 4px;">Template name</label>'
        + '<input id="mt-name" maxlength="80" placeholder="e.g. Fall week A" style="width:100%;box-sizing:border-box;height:32px;padding:0 10px;border:1px solid #CBD5E1;border-radius:8px;font:inherit;font-size:13px;">'
        + '<p style="font-size:12px;color:#64748B;margin:8px 0 0;">Saves this week\'s saved dishes, allergens and food photos for your whole agency to reuse.</p>'
        + '<div id="mt-err" style="color:#B91C1C;font-size:12.5px;margin-top:6px;min-height:16px;"></div></div>');
      modal({ title: '💾 Save this week as a template', body: box, actions: [{ label: 'Cancel' }, { label: 'Save template', primary: true, handler: async function () {
        var name = box.querySelector('#mt-name').value.trim(), err = box.querySelector('#mt-err');
        if (!name) { err.textContent = 'Give the template a name.'; return false; }
        try {
          var r = await KT.Api.post('/operations/menu/templates', { centre_id: ctx.cid, week_start: ctx.weekStart, name: name });
          toast('💾', 'Template saved', name + ' · ' + r.item_count + ' dishes');
          KT.Api.get('/operations/menu/templates').then(function (x) { info = x || info; }).catch(function () {});
          return true;
        } catch (e) { err.textContent = (e && e.message) || 'Could not save it.'; return false; }
      } }] });
      setTimeout(function () { var n = box.querySelector('#mt-name'); if (n && w.innerWidth > 768) n.focus(); }, 50);
    };

    bar.querySelector('#mt-apply').onclick = async function () {
      try { info = await KT.Api.get('/operations/menu/templates'); } catch (e) {}
      var tpls = info.templates || [];
      if (!tpls.length) { toast('ℹ️', 'No templates yet', 'Open a saved week and press "Save as template" to make one.'); return; }
      var centres = info.centres || [];
      var chosen = tpls[0].id;
      var box = el('<div style="font-size:13px;color:#334155;">'
        + '<div style="font-size:12px;font-weight:700;color:#475569;margin:0 0 6px;">Template</div><div id="mt-list" role="radiogroup" style="display:flex;flex-direction:column;gap:6px;"></div>'
        + '<label for="mt-week" style="display:block;font-size:12px;font-weight:700;color:#475569;margin:12px 0 4px;">Week of (Monday)</label>'
        + '<input id="mt-week" type="date" value="' + esc(ctx.weekStart) + '" style="height:32px;box-sizing:border-box;padding:0 10px;border:1px solid #CBD5E1;border-radius:8px;font:inherit;font-size:13px;">'
        + '<div style="font-size:12px;font-weight:700;color:#475569;margin:12px 0 4px;">' + (centres.length > 1 ? 'Centres (only the ones you work in)' : 'Centre') + '</div>'
        + '<div id="mt-centres" data-kt-noswitch style="display:flex;flex-wrap:wrap;gap:6px 14px;">' + centres.map(function (c) {
            return '<label style="display:inline-flex;align-items:center;gap:6px;font-weight:600;cursor:pointer;"><input type="checkbox" value="' + c.id + '"' + (+c.id === +ctx.cid ? ' checked' : '') + ' style="width:16px;height:16px;"> ' + esc(c.name) + '</label>';
          }).join('') + '</div>'
        + '<label data-kt-noswitch style="display:flex;align-items:flex-start;gap:7px;margin-top:12px;font-size:12.5px;cursor:pointer;"><input type="checkbox" id="mt-replace" style="width:16px;height:16px;margin-top:1px;"> Replace draft menus that already have dishes (published weeks are never changed)</label>'
        + '<p style="font-size:12px;color:#64748B;margin:10px 0 0;">The week is saved as a <strong>draft</strong> at each centre. Families see it once you publish it.</p>'
        + '<div id="mt-err" style="color:#B91C1C;font-size:12.5px;margin-top:6px;min-height:16px;"></div></div>');
      var paintList = function () {
        box.querySelector('#mt-list').innerHTML = tpls.map(function (t) {
          return '<div style="display:flex;align-items:center;gap:8px;border:1px solid ' + (t.id === chosen ? '#2F8C86' : '#E2E8F0') + ';background:' + (t.id === chosen ? '#F0FDFA' : '#fff') + ';border-radius:10px;padding:7px 9px;">'
            + '<button type="button" data-kt-inpage role="radio" aria-checked="' + (t.id === chosen) + '" data-t="' + t.id + '" style="flex:1;min-width:0;text-align:left;background:none;border:0;padding:0;font:inherit;cursor:pointer;color:inherit;">'
            + '<div style="font-weight:800;color:#0D1B2A;">' + (t.id === chosen ? '● ' : '○ ') + esc(t.name) + '</div>'
            + '<div style="font-size:11.5px;color:#64748B;">' + t.item_count + ' dishes' + (t.created_by ? ' · saved by ' + esc(t.created_by) : '') + (t.use_count ? ' · used ' + t.use_count + '×' : '') + '</div></button>'
            + (t.thumbs || []).slice(0, 3).map(function (u) { return '<img src="' + esc(u) + '" alt="" style="width:36px;height:27px;object-fit:cover;border-radius:5px;flex:0 0 auto;">'; }).join('')
            + (t.can_delete ? '<button type="button" data-kt-inpage data-del="' + t.id + '" title="Delete this template" aria-label="Delete ' + esc(t.name) + '" style="flex:0 0 auto;height:28px;width:28px;border:1px solid #FCA5A5;color:#B91C1C;background:#fff;border-radius:7px;cursor:pointer;">🗑</button>' : '')
            + '</div>';
        }).join('');
        box.querySelectorAll('[data-t]').forEach(function (b) { b.onclick = function () { chosen = +b.getAttribute('data-t'); paintList(); }; });
        box.querySelectorAll('[data-del]').forEach(function (b) {
          b.onclick = async function () {
            var id = +b.getAttribute('data-del'), t = tpls.filter(function (x) { return x.id === id; })[0];
            if (!w.confirm('Delete the template "' + (t ? t.name : '') + '"? Menus already made from it stay as they are.')) return;
            try { await KT.Api.delete('/operations/menu/templates/' + id); tpls = tpls.filter(function (x) { return x.id !== id; }); if (chosen === id && tpls[0]) chosen = tpls[0].id; paintList(); toast('🗑', 'Template deleted', t ? t.name : ''); }
            catch (e) { box.querySelector('#mt-err').textContent = (e && e.message) || 'Could not delete it.'; }
          };
        });
      };
      paintList();
      modal({ title: '📋 Apply a menu template', body: box, actions: [{ label: 'Cancel' }, { label: 'Apply', primary: true, busyLabel: 'Applying…', handler: async function () {
        var err = box.querySelector('#mt-err'); err.textContent = '';
        var ids = Array.prototype.map.call(box.querySelectorAll('#mt-centres input:checked'), function (c) { return +c.value; });
        if (!tpls.length) { err.textContent = 'There are no templates left.'; return false; }
        if (!ids.length) { err.textContent = 'Choose at least one centre.'; return false; }
        var wk = box.querySelector('#mt-week').value || ctx.weekStart;
        try {
          var r = await KT.Api.post('/operations/menu/templates/' + chosen + '/apply', { centre_ids: ids, week_start: wk, replace: box.querySelector('#mt-replace').checked });
          var a = r.applied || [], s = r.skipped || [];
          setTimeout(function () {
            modal({ title: '✓ Template applied', body: '<div style="font-size:13.5px;color:#334155;">'
              + (a.length ? '<p style="margin:0 0 6px;">Saved as a <strong>draft</strong> for the week of ' + esc(fmtWeek(r.week_start)) + ':</p><ul style="margin:0 0 8px;padding-left:18px;">' + a.map(function (x) { return '<li>' + esc(x.name) + '</li>'; }).join('') + '</ul>' : '<p style="margin:0 0 8px;">No menus were changed.</p>')
              + (s.length ? '<p style="margin:0 0 6px;color:#92400E;">Left as they were:</p><ul style="margin:0;padding-left:18px;color:#92400E;">' + s.map(function (x) { return '<li>' + esc(x.name) + ': ' + esc(x.reason) + '</li>'; }).join('') + '</ul>' : '')
              + '</div>', actions: [{ label: 'OK', primary: true }] });
            if (a.some(function (x) { return +x.centre_id === +ctx.cid; }) && r.week_start === ctx.weekStart) ctx.reload();
          }, 30);
          return true;
        } catch (e) { err.textContent = (e && e.message) || 'Could not apply it.'; return false; }
      } }] });
    };

    return { refresh: refresh };
  }

  KT.MenuTools = { mount: mount, check: check };
})(window);
