/* ═══════════════════════════════════════════════════════════════════
   KIDDIETRAC v22p43 — Custom forms admin
   Hash: #admin-forms
   Agency admins + directors build forms via a small field-by-field UI.
   Each form has a status (draft / published / archived), an audience,
   and a schema_json (ordered list of fields).
   ═══════════════════════════════════════════════════════════════════ */
(function (window) {
  'use strict';
  if (!window.KT) return;
  var KT = window.KT;
  var Api = KT.Api;
  var Dom = KT.Dom;
  var Shell = KT.Shell;

  var FIELD_TYPES = [
    ['text',     'Short text'],
    ['textarea', 'Long text'],
    ['email',    'Email'],
    ['number',   'Number'],
    ['date',     'Date'],
    ['select',   'Dropdown'],
    ['checkbox', 'Checkbox'],
    ['radio',    'Multiple choice'],
    ['signature', '✍️ Signature'],
    ['payment',   '💳 Payment'],
  ];

  function uid() { return Math.random().toString(36).slice(2, 9); }
  function esc(s) { return s == null ? '' : String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;'); }
  function fmtDate(d) { if (!d) return '—'; try { return new Date(d).toLocaleString('en-CA', { dateStyle: 'medium', timeStyle: 'short' }); } catch (e) { return d; } }

  // ── List view ─────────────────────────────────────────────────────
  function render(container) {
    Dom.clear(container);
    var wrap = Dom.el('div', { style: 'padding:24px;max-width:1800px;margin:0 auto;' });
    container.appendChild(wrap);

    var hero = Dom.el('div', { class: 'kt-hero', style: 'background:linear-gradient(135deg,#7C3AED 0%,#1F6080 60%,#16637A 100%);' });
    hero.innerHTML = '<div class="kt-hero-greet">📝 FORMS</div><h1>Custom forms</h1><div class="kt-hero-sub">Build registration, consent, survey and feedback forms. Families fill them in from their parent portal; responses appear here.</div>';
    wrap.appendChild(hero);

    var bar = Dom.el('div', { style: 'display:flex;justify-content:space-between;align-items:center;gap:12px;margin:18px 0;flex-wrap:wrap;' });
    // v22p86: search box
    var searchInput = Dom.el('input', {
      type: 'search', placeholder: '🔍  Search forms…',
      style: 'flex:1;min-width:220px;max-width:360px;padding:9px 12px;border:1px solid #D1D5DB;border-radius:9px;font-size:14px;box-sizing:border-box;',
    });
    bar.appendChild(searchInput);
    var newBtn = Dom.el('button', { style: btnPrimary() }, '+ New form');
    newBtn.addEventListener('click', function () { openBuilder(null, container); });
    bar.appendChild(newBtn);
    wrap.appendChild(bar);

    var listWrap = Dom.el('div', { style: 'background:white;border-radius:12px;box-shadow:0 1px 3px rgba(0,0,0,.04);overflow:hidden;' });
    wrap.appendChild(listWrap);
    listWrap.appendChild(Dom.el('div', { style: 'padding:40px;text-align:center;color:#9CA3AF;' }, 'Loading…'));

    // v22p86: client-side search + prev/next pagination over the agency's forms.
    var allForms = [], query = '', page = 0;
    var PER = 8;
    function paint() {
      Dom.clear(listWrap);
      if (!allForms.length) {
        listWrap.appendChild(Dom.el('div', { style: 'padding:48px;text-align:center;color:#6B7280;' }, 'No forms yet. Click + New form to build one.'));
        return;
      }
      var filtered = query
        ? allForms.filter(function (f) { return (f.title || '').toLowerCase().indexOf(query) !== -1; })
        : allForms;
      if (!filtered.length) {
        listWrap.appendChild(Dom.el('div', { style: 'padding:40px;text-align:center;color:#6B7280;' }, 'No forms match “' + esc(query) + '”.'));
        return;
      }
      var pages = Math.ceil(filtered.length / PER);
      if (page >= pages) page = pages - 1;
      if (page < 0) page = 0;
      filtered.slice(page * PER, page * PER + PER).forEach(function (f) { listWrap.appendChild(renderRow(f, container, refresh)); });
      if (pages > 1) listWrap.appendChild(renderPager(page, pages, filtered.length, function (p) { page = p; paint(); }));
    }
    function refresh() { render(container); }
    var _searchTimer = null;
    searchInput.addEventListener('input', function () {
      clearTimeout(_searchTimer);
      _searchTimer = setTimeout(function () { query = searchInput.value.trim().toLowerCase(); page = 0; paint(); }, 120);
    });

    Api.get('/admin/forms').then(function (data) {
      allForms = (data.forms || []);
      paint();
    }).catch(function (e) {
      Dom.clear(listWrap);
      listWrap.appendChild(Dom.el('div', { style: 'padding:24px;color:#DC2626;' }, 'Could not load: ' + (e.message || 'error')));
    });
  }

  function renderPager(page, pages, total, go) {
    var pager = Dom.el('div', { style: 'display:flex;align-items:center;justify-content:space-between;gap:10px;padding:12px 18px;border-top:1px solid #F3F4F6;background:#FAFBFC;' });
    function navBtn(label, target, disabled) {
      var b = Dom.el('button', {
        style: 'background:white;border:1px solid #D1D5DB;color:' + (disabled ? '#9CA3AF' : '#1F6080') + ';padding:6px 14px;border-radius:8px;font-size:13px;font-weight:600;cursor:' + (disabled ? 'default' : 'pointer') + ';',
      }, label);
      if (disabled) b.disabled = true; else b.addEventListener('click', function () { go(target); });
      return b;
    }
    pager.appendChild(navBtn('‹ Prev', page - 1, page === 0));
    pager.appendChild(Dom.el('div', { style: 'font-size:13px;color:#6B7280;' }, 'Page ' + (page + 1) + ' of ' + pages + ' · ' + total + ' form' + (total === 1 ? '' : 's')));
    pager.appendChild(navBtn('Next ›', page + 1, page >= pages - 1));
    return pager;
  }

  function renderRow(f, container, refresh) {
    var row = Dom.el('div', { style: 'display:flex;align-items:center;gap:12px;padding:14px 18px;border-bottom:1px solid #F3F4F6;cursor:pointer;' });
    row.addEventListener('mouseenter', function () { row.style.background = '#FAFBFC'; });
    row.addEventListener('mouseleave', function () { row.style.background = 'white'; });

    row.appendChild(Dom.el('div', { style: 'width:44px;height:44px;border-radius:10px;background:#F3F4F6;display:flex;align-items:center;justify-content:center;font-size:22px;flex-shrink:0;' }, '📝'));

    var body = Dom.el('div', { style: 'flex:1;min-width:0;' });
    body.appendChild(Dom.el('div', { style: 'font-weight:700;font-size:15px;color:#111827;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;' }, f.title));
    var fields = 0; try { fields = (JSON.parse(f.schema_json || '[]') || []).length; } catch (e) {}
    body.appendChild(Dom.el('div', { style: 'font-size:12px;color:#6B7280;' }, fields + ' field' + (fields === 1 ? '' : 's') + ' · ' + (f.audience || '').replace('_', ' ') + ' · updated ' + fmtDate(f.updated_at)));
    row.appendChild(body);

    function iconBtn(label, title, handler) {
      var b = Dom.el('button', { title: title, style: 'background:white;color:#374151;border:1px solid #D1D5DB;padding:6px 10px;border-radius:8px;font-size:13px;cursor:pointer;flex-shrink:0;' }, label);
      b.addEventListener('click', function (e) { e.stopPropagation(); handler(); });
      b.addEventListener('mouseenter', function () { b.style.background = '#F1F5F9'; });
      b.addEventListener('mouseleave', function () { b.style.background = 'white'; });
      return b;
    }
    // v22p86: preview + email-to-parents
    row.appendChild(iconBtn('👁 Preview', 'Preview as a parent sees it', function () { openPreview(f); }));
    row.appendChild(iconBtn('✉ Email', 'Email this form to parents', function () { emailForm(f); }));

    var respBtn = Dom.el('button', {
      style: 'background:white;color:#1F6080;border:1px solid #1F6080;padding:6px 12px;border-radius:8px;font-size:12px;font-weight:600;cursor:pointer;flex-shrink:0;',
    }, (f.response_count || 0) + ' response' + (f.response_count === 1 ? '' : 's'));
    respBtn.addEventListener('click', function (e) { e.stopPropagation(); openResponses(f, container); });
    row.appendChild(respBtn);

    row.appendChild(statusPill(f.status));
    row.addEventListener('click', function () { openBuilder(f, container); });
    return row;
  }

  // ── White-label branding (cached) ─────────────────────────────────
  var _branding = null, _brandingPromise = null;
  function getBranding() {
    if (_branding) return Promise.resolve(_branding);
    if (_brandingPromise) return _brandingPromise;
    _brandingPromise = Api.get('/branding').then(function (d) { _branding = d.branding || {}; return _branding; })
      .catch(function () { _branding = {}; return _branding; });
    return _brandingPromise;
  }

  // ── Preview ───────────────────────────────────────────────────────
  function openPreview(f) {
    var overlay = Dom.el('div', { style: 'position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:1000;display:flex;align-items:flex-start;justify-content:center;padding:32px 16px;overflow:auto;' });
    var sheet = Dom.el('div', { style: 'background:#F8FAFC;border-radius:16px;max-width:560px;width:100%;box-shadow:0 16px 48px rgba(0,0,0,.3);overflow:hidden;position:relative;' });
    overlay.appendChild(sheet);
    overlay.addEventListener('click', function (e) { if (e.target === overlay) overlay.remove(); });
    sheet.appendChild(Dom.el('div', { style: 'padding:30px;text-align:center;color:#9CA3AF;' }, 'Loading preview…'));
    document.body.appendChild(overlay);
    getBranding().then(function (b) {
      Dom.clear(sheet);
      sheet.appendChild(buildBrandedForm(f, b, true));
      var x = Dom.el('button', { title: 'Close', style: 'position:absolute;top:14px;right:16px;background:rgba(255,255,255,.25);color:white;border:none;width:30px;height:30px;border-radius:8px;font-size:20px;line-height:1;cursor:pointer;z-index:5;' }, '×');
      x.addEventListener('click', function () { overlay.remove(); });
      sheet.appendChild(x);
    });
  }

  // Render a form the way a parent sees it, styled with the agency's brand
  // when the white-label add-on is active (powered_by_visible === false).
  function buildBrandedForm(f, b, isPreview) {
    var whiteLabel = b && b.powered_by_visible === false;
    var primary = (b && b.primary_color) || '#1F6080';
    var logo = b && b.logo_url;
    var orgName = (b && b.product_name) || 'KiddieTrac';
    var root = Dom.el('div', {});

    var head = Dom.el('div', { style: 'background:' + primary + ';color:white;padding:22px 26px;' });
    if (whiteLabel && logo) head.appendChild(Dom.el('img', { src: logo, style: 'max-height:42px;margin-bottom:10px;display:block;background:white;border-radius:6px;padding:3px 6px;' }));
    else if (whiteLabel) head.appendChild(Dom.el('div', { style: 'font-weight:800;font-size:18px;margin-bottom:6px;' }, orgName));
    head.appendChild(Dom.el('div', { style: 'font-size:20px;font-weight:800;' }, f.title || 'Form'));
    if (f.description) head.appendChild(Dom.el('div', { style: 'font-size:13px;opacity:.92;margin-top:4px;' }, f.description));
    root.appendChild(head);

    var bodyEl = Dom.el('div', { style: 'padding:22px 26px;' });
    var schema = []; try { schema = JSON.parse(f.schema_json || '[]') || []; } catch (e) {}
    schema.forEach(function (fld) { bodyEl.appendChild(previewField(fld)); });
    var submit = Dom.el('button', { style: 'background:' + primary + ';color:white;border:none;padding:12px 22px;border-radius:10px;font-weight:700;font-size:15px;cursor:default;width:100%;margin-top:8px;opacity:.95;' }, 'Submit');
    bodyEl.appendChild(submit);
    root.appendChild(bodyEl);

    var foot = Dom.el('div', { style: 'padding:12px 26px 20px;text-align:center;font-size:11px;color:#9CA3AF;' });
    if (whiteLabel) {
      var bits = [];
      if (b.product_name) bits.push(b.product_name);
      if (b.support_email) bits.push(b.support_email);
      foot.textContent = bits.join(' · ');
    } else {
      foot.textContent = 'Powered by KiddieTrac';
    }
    root.appendChild(foot);
    return root;
  }

  function previewField(fld) {
    var wrap = Dom.el('div', { style: 'margin-bottom:16px;' });
    wrap.appendChild(Dom.el('div', { style: 'font-size:13px;font-weight:700;color:#0F172A;margin-bottom:6px;' }, (fld.label || fld.id) + (fld.required ? ' *' : '')));
    var ph = 'width:100%;padding:10px 12px;border:1px solid #D1D5DB;border-radius:8px;font-size:14px;box-sizing:border-box;background:white;color:#9CA3AF;';
    if (fld.type === 'textarea') wrap.appendChild(Dom.el('div', { style: ph + 'min-height:64px;' }, fld.placeholder || ''));
    else if (fld.type === 'select' || fld.type === 'radio') {
      (fld.options || []).forEach(function (o) {
        var r = Dom.el('div', { style: 'display:flex;align-items:center;gap:8px;padding:7px 10px;border:1px solid #E5E7EB;border-radius:8px;margin-bottom:5px;font-size:13px;color:#374151;' });
        r.appendChild(Dom.el('span', { style: 'width:14px;height:14px;border:1px solid #9CA3AF;border-radius:' + (fld.type === 'radio' ? '50%' : '4px') + ';display:inline-block;' }));
        r.appendChild(Dom.el('span', {}, o));
        wrap.appendChild(r);
      });
    } else if (fld.type === 'checkbox') {
      wrap.appendChild(Dom.el('div', { style: 'display:flex;align-items:center;gap:8px;font-size:13px;color:#374151;' }, [Dom.el('span', { style: 'width:16px;height:16px;border:1px solid #9CA3AF;border-radius:4px;display:inline-block;' }), Dom.el('span', {}, fld.placeholder || 'Yes')]));
    } else if (fld.type === 'signature') {
      wrap.appendChild(Dom.el('div', { style: 'border:1px solid #D1D5DB;border-radius:8px;height:120px;background:#FFFEF8;display:flex;align-items:center;justify-content:center;color:#CBD5E1;font-size:13px;' }, '✍️ signature pad'));
    } else if (fld.type === 'payment') {
      wrap.appendChild(Dom.el('div', { style: 'border:1px solid #BFE3CF;background:#F0FBF4;border-radius:10px;padding:12px 14px;color:#166534;font-weight:700;' }, '💳 Payment · $' + (Number(fld.amount || 0)).toFixed(2)));
    } else {
      wrap.appendChild(Dom.el('div', { style: ph }, fld.placeholder || ''));
    }
    return wrap;
  }

  // ── Email to parents ──────────────────────────────────────────────
  function emailForm(f) {
    if (f.status !== 'published') {
      alert('Publish this form first — only published forms can be emailed to parents.');
      return;
    }
    if (!confirm('Email “' + f.title + '” to all parents in its audience (' + (f.audience || '').replace('_', ' ') + ')?')) return;
    Api.post('/admin/forms/' + f.id + '/email', {}).then(function (r) {
      if (Dom.toast) Dom.toast('Form emailed to ' + (r.sent || 0) + ' parent' + (r.sent === 1 ? '' : 's') + '.', 'success');
      else alert('Form emailed to ' + (r.sent || 0) + ' parents.');
    }).catch(function (e) {
      alert('Could not send: ' + (e.message || 'error'));
    });
  }

  function statusPill(s) {
    var map = {
      draft:     { bg: '#F3F4F6', fg: '#6B7280', t: 'DRAFT' },
      published: { bg: '#DCFCE7', fg: '#166534', t: 'LIVE' },
      archived:  { bg: '#FEE2E2', fg: '#991B1B', t: 'ARCHIVED' },
    };
    var m = map[s] || { bg: '#F3F4F6', fg: '#6B7280', t: (s || '').toUpperCase() };
    return Dom.el('span', { style: 'padding:3px 10px;border-radius:999px;background:' + m.bg + ';color:' + m.fg + ';font-size:10px;font-weight:700;letter-spacing:0.5px;flex-shrink:0;' }, m.t);
  }

  // ── Builder ───────────────────────────────────────────────────────
  function openBuilder(existing, container) {
    var schema = [];
    try { schema = existing ? (JSON.parse(existing.schema_json || '[]') || []) : []; } catch (e) {}
    if (!schema.length) schema = [{ id: uid(), type: 'text', label: 'Question 1', required: false }];

    var overlay = Dom.el('div', { style: 'position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:1000;display:flex;align-items:center;justify-content:center;padding:24px;overflow:auto;' });
    var modal = Dom.el('div', { style: 'background:white;border-radius:14px;max-width:900px;width:100%;max-height:calc(100vh - 48px);overflow-y:auto;box-shadow:0 12px 36px rgba(0,0,0,.25);' });
    overlay.appendChild(modal);

    var header = Dom.el('div', { style: 'padding:18px 24px;border-bottom:1px solid #E5E7EB;display:flex;align-items:center;justify-content:space-between;position:sticky;top:0;background:white;z-index:2;' });
    header.appendChild(Dom.el('h2', { style: 'margin:0;font-size:18px;' }, existing ? 'Edit form' : 'New form'));
    var x = Dom.el('button', { style: 'background:transparent;border:none;font-size:22px;color:#6B7280;cursor:pointer;' }, '×');
    x.addEventListener('click', function () { overlay.remove(); });
    header.appendChild(x);
    modal.appendChild(header);

    var body = Dom.el('div', { style: 'padding:20px 24px;' });
    modal.appendChild(body);

    // Header inputs
    body.appendChild(label('Form title'));
    var titleIn = Dom.el('input', { type: 'text', value: existing ? existing.title : '', placeholder: 'Field-trip permission, Annual update form…', style: inputStyle() });
    body.appendChild(titleIn);

    body.appendChild(label('Description (shown to parents above the form)'));
    var descIn = Dom.el('textarea', { placeholder: 'Tell respondents what this form is for…', style: inputStyle() + 'min-height:60px;font-family:inherit;resize:vertical;' });
    descIn.value = existing ? (existing.description || '') : '';
    body.appendChild(descIn);

    var row2 = Dom.el('div', { style: 'display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:14px;' });
    var audWrap = Dom.el('div');
    audWrap.appendChild(label('Audience'));
    var audSel = Dom.el('select', { style: inputStyle() });
    [['all_families','All families'],['active_families','Currently enrolled'],['waitlist','Waitlist'],['prospects','Prospects'],['staff','Staff only']].forEach(function (o) {
      var opt = Dom.el('option', { value: o[0] }, o[1]);
      if (existing && existing.audience === o[0]) opt.selected = true;
      audSel.appendChild(opt);
    });
    audWrap.appendChild(audSel);
    row2.appendChild(audWrap);

    var statusWrap = Dom.el('div');
    statusWrap.appendChild(label('Status'));
    var statusSel = Dom.el('select', { style: inputStyle() });
    [['draft','Draft (only you see it)'],['published','Published (live for audience)'],['archived','Archived (hidden, kept for records)']].forEach(function (o) {
      var opt = Dom.el('option', { value: o[0] }, o[1]);
      if (existing && existing.status === o[0]) opt.selected = true;
      statusWrap.appendChild(opt);
    });
    statusWrap.appendChild(statusSel);
    row2.appendChild(statusWrap);
    body.appendChild(row2);

    // Fields builder
    body.appendChild(Dom.el('div', { style: 'font-size:13px;font-weight:700;color:#374151;letter-spacing:.5px;text-transform:uppercase;margin:18px 0 10px;' }, 'Fields'));
    var fieldsWrap = Dom.el('div');
    body.appendChild(fieldsWrap);

    function paintFields() {
      Dom.clear(fieldsWrap);
      schema.forEach(function (f, idx) { fieldsWrap.appendChild(renderFieldEditor(f, idx, schema, paintFields)); });
    }
    paintFields();

    var addRow = Dom.el('div', { style: 'display:flex;gap:8px;margin-top:8px;flex-wrap:wrap;' });
    FIELD_TYPES.forEach(function (ft) {
      var b = Dom.el('button', { type: 'button', style: 'background:white;border:1px dashed #D1D5DB;color:#374151;padding:6px 12px;border-radius:8px;font-size:12px;cursor:pointer;' }, '+ ' + ft[1]);
      b.addEventListener('click', function () {
        var newF = { id: uid(), type: ft[0], label: ft[1] + ' question', required: false };
        if (ft[0] === 'select' || ft[0] === 'radio') newF.options = ['Option 1', 'Option 2'];
        if (ft[0] === 'payment') { newF.amount = 0; newF.label = 'Payment'; newF.required = true; }
        if (ft[0] === 'signature') { newF.label = 'Signature'; }
        schema.push(newF);
        paintFields();
      });
      addRow.appendChild(b);
    });
    body.appendChild(addRow);

    // Actions
    var actions = Dom.el('div', { style: 'display:flex;justify-content:space-between;gap:10px;margin-top:22px;padding-top:18px;border-top:1px solid #E5E7EB;' });
    var leftAct = Dom.el('div');
    if (existing) {
      var del = Dom.el('button', { style: btnDanger() }, 'Delete');
      del.addEventListener('click', function () {
        if (!confirm('Delete this form? Existing responses are preserved.')) return;
        Api.delete('/admin/forms/' + existing.id).then(function () { overlay.remove(); render(container); });
      });
      leftAct.appendChild(del);
    }
    actions.appendChild(leftAct);
    var save = Dom.el('button', { style: btnPrimary() }, existing ? 'Save changes' : 'Create form');
    actions.appendChild(save);
    body.appendChild(actions);

    save.addEventListener('click', function () {
      if (!titleIn.value.trim()) { alert('Title is required.'); return; }
      if (!schema.length) { alert('Add at least one field.'); return; }
      // Strip empty options on select/radio fields and check ids unique
      var seen = {};
      for (var i = 0; i < schema.length; i++) {
        var f = schema[i];
        if (!f.id || seen[f.id]) { f.id = uid(); }
        seen[f.id] = true;
        if (f.type === 'select' || f.type === 'radio') {
          f.options = (f.options || []).map(function (o) { return String(o).trim(); }).filter(Boolean);
          if (f.options.length < 2) { alert('Field "' + (f.label || f.id) + '" needs at least 2 options.'); return; }
        }
      }
      var payload = {
        title: titleIn.value.trim(),
        description: descIn.value.trim() || null,
        schema: schema,
        audience: audSel.value,
        status: statusSel.value,
      };
      save.disabled = true; save.textContent = 'Saving…';
      var p = existing ? Api.patch('/admin/forms/' + existing.id, payload) : Api.post('/admin/forms', payload);
      p.then(function () { overlay.remove(); render(container); })
        .catch(function (e) { alert('Save failed: ' + e.message); save.disabled = false; save.textContent = existing ? 'Save changes' : 'Create form'; });
    });

    document.body.appendChild(overlay);
  }

  function renderFieldEditor(field, idx, schema, refresh) {
    var card = Dom.el('div', { style: 'background:#FAFBFC;border:1px solid #E5E7EB;border-radius:10px;padding:12px 14px;margin-bottom:10px;' });
    var head = Dom.el('div', { style: 'display:flex;align-items:center;gap:8px;margin-bottom:8px;' });
    head.appendChild(Dom.el('div', { style: 'font-size:11px;font-weight:700;color:#6B7280;letter-spacing:1px;text-transform:uppercase;flex:1;' }, '#' + (idx + 1) + ' · ' + (field.type || 'text')));

    function moveBtn(label, dir) {
      var b = Dom.el('button', { type: 'button', style: 'background:white;border:1px solid #E5E7EB;border-radius:6px;width:28px;height:28px;cursor:pointer;font-size:12px;color:#6B7280;' }, label);
      b.addEventListener('click', function () {
        var j = idx + dir;
        if (j < 0 || j >= schema.length) return;
        var tmp = schema[idx]; schema[idx] = schema[j]; schema[j] = tmp;
        refresh();
      });
      return b;
    }
    head.appendChild(moveBtn('↑', -1));
    head.appendChild(moveBtn('↓', +1));
    var rm = Dom.el('button', { type: 'button', style: 'background:white;border:1px solid #FCA5A5;color:#DC2626;border-radius:6px;width:28px;height:28px;cursor:pointer;font-size:14px;' }, '×');
    rm.addEventListener('click', function () { schema.splice(idx, 1); refresh(); });
    head.appendChild(rm);
    card.appendChild(head);

    var topRow = Dom.el('div', { style: 'display:grid;grid-template-columns:1fr 160px;gap:10px;' });
    var labelIn = Dom.el('input', { type: 'text', value: field.label || '', placeholder: 'Field label (visible to respondent)', style: inputStyle() });
    labelIn.addEventListener('input', function () { field.label = labelIn.value; });
    topRow.appendChild(labelIn);
    var typeSel = Dom.el('select', { style: inputStyle() });
    FIELD_TYPES.forEach(function (ft) {
      var o = Dom.el('option', { value: ft[0] }, ft[1]);
      if (ft[0] === field.type) o.selected = true;
      typeSel.appendChild(o);
    });
    typeSel.addEventListener('change', function () {
      field.type = typeSel.value;
      if ((field.type === 'select' || field.type === 'radio') && (!field.options || !field.options.length)) {
        field.options = ['Option 1', 'Option 2'];
      }
      refresh();
    });
    topRow.appendChild(typeSel);
    card.appendChild(topRow);

    var midRow = Dom.el('div', { style: 'display:grid;grid-template-columns:1fr 1fr auto;gap:10px;align-items:end;margin-top:8px;' });
    var phWrap = Dom.el('div');
    phWrap.appendChild(label('Placeholder (optional)'));
    var phIn = Dom.el('input', { type: 'text', value: field.placeholder || '', style: inputStyle() });
    phIn.addEventListener('input', function () { field.placeholder = phIn.value; });
    phWrap.appendChild(phIn);
    midRow.appendChild(phWrap);
    var helpWrap = Dom.el('div');
    helpWrap.appendChild(label('Help text (optional)'));
    var helpIn = Dom.el('input', { type: 'text', value: field.help || '', style: inputStyle() });
    helpIn.addEventListener('input', function () { field.help = helpIn.value; });
    helpWrap.appendChild(helpIn);
    midRow.appendChild(helpWrap);
    var reqWrap = Dom.el('label', { style: 'display:flex;align-items:center;gap:6px;font-size:13px;color:#374151;cursor:pointer;padding-bottom:6px;' });
    var reqIn = Dom.el('input', { type: 'checkbox' });
    if (field.required) reqIn.checked = true;
    reqIn.addEventListener('change', function () { field.required = reqIn.checked; });
    reqWrap.appendChild(reqIn);
    reqWrap.appendChild(Dom.el('span', {}, 'Required'));
    midRow.appendChild(reqWrap);
    card.appendChild(midRow);

    if (field.type === 'select' || field.type === 'radio') {
      var optWrap = Dom.el('div', { style: 'margin-top:10px;' });
      optWrap.appendChild(label('Options (one per line)'));
      var optIn = Dom.el('textarea', { style: inputStyle() + 'min-height:80px;font-family:inherit;' });
      optIn.value = (field.options || []).join('\n');
      optIn.addEventListener('input', function () { field.options = optIn.value.split(/\r?\n/).map(function (s) { return s.trim(); }).filter(Boolean); });
      optWrap.appendChild(optIn);
      card.appendChild(optWrap);
    }

    if (field.type === 'payment') {
      var payWrap = Dom.el('div', { style: 'margin-top:10px;' });
      payWrap.appendChild(label('Amount to charge ($)'));
      var amtIn = Dom.el('input', { type: 'number', step: '0.01', min: '0', value: (field.amount != null ? field.amount : ''), placeholder: '0.00', style: inputStyle() });
      amtIn.addEventListener('input', function () { field.amount = parseFloat(amtIn.value) || 0; });
      payWrap.appendChild(amtIn);
      payWrap.appendChild(Dom.el('div', { style: 'font-size:12px;color:#6B7280;margin-top:4px;' }, 'On submit, this charge is recorded against the family’s account.'));
      card.appendChild(payWrap);
    }
    if (field.type === 'signature') {
      card.appendChild(Dom.el('div', { style: 'margin-top:8px;font-size:12px;color:#6B7280;' }, 'Respondents draw their signature on a pad; it’s saved with the response.'));
    }

    // v22p44: conditional visibility — show this field only when another
    // field equals a specific value (or has a non-empty value).
    var visWrap = Dom.el('div', { style: 'margin-top:10px;padding:10px 12px;background:#FFFBEB;border:1px dashed #FCD34D;border-radius:8px;' });
    var visToggle = Dom.el('label', { style: 'display:flex;align-items:center;gap:6px;font-size:12px;color:#92400E;cursor:pointer;font-weight:600;' });
    var visEnabled = Dom.el('input', { type: 'checkbox' });
    if (field.visible_if && field.visible_if.field_id) visEnabled.checked = true;
    visToggle.appendChild(visEnabled);
    visToggle.appendChild(Dom.el('span', {}, '🔀 Show only when another field has a specific value'));
    visWrap.appendChild(visToggle);

    var visBody = Dom.el('div', { style: 'margin-top:8px;display:' + (visEnabled.checked ? 'grid' : 'none') + ';grid-template-columns:1fr 1fr;gap:8px;' });
    // Field id picker — only fields BEFORE this one are valid (no forward refs)
    var depSel = Dom.el('select', { style: inputStyle() });
    depSel.appendChild(Dom.el('option', { value: '' }, '— pick a field —'));
    for (var i = 0; i < idx; i++) {
      var other = schema[i];
      var opt = Dom.el('option', { value: other.id }, '#' + (i + 1) + ' · ' + (other.label || other.id));
      if (field.visible_if && field.visible_if.field_id === other.id) opt.selected = true;
      depSel.appendChild(opt);
    }
    depSel.addEventListener('change', function () {
      field.visible_if = field.visible_if || {};
      field.visible_if.field_id = depSel.value;
    });
    visBody.appendChild(depSel);

    var equalsIn = Dom.el('input', { type: 'text', placeholder: 'equals (leave blank = any non-empty)', style: inputStyle() });
    if (field.visible_if && field.visible_if.equals !== undefined) equalsIn.value = field.visible_if.equals;
    equalsIn.addEventListener('input', function () {
      field.visible_if = field.visible_if || {};
      if (equalsIn.value === '') {
        delete field.visible_if.equals;
        field.visible_if.equals_truthy = true;
      } else {
        field.visible_if.equals = equalsIn.value;
        delete field.visible_if.equals_truthy;
      }
    });
    visBody.appendChild(equalsIn);
    visWrap.appendChild(visBody);

    visEnabled.addEventListener('change', function () {
      visBody.style.display = visEnabled.checked ? 'grid' : 'none';
      if (!visEnabled.checked) {
        delete field.visible_if;
      } else if (!field.visible_if) {
        field.visible_if = { field_id: '' };
      }
    });
    if (idx > 0) card.appendChild(visWrap);

    return card;
  }

  // ── Responses ─────────────────────────────────────────────────────
  function openResponses(form, container) {
    var overlay = Dom.el('div', { style: 'position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:1000;display:flex;align-items:center;justify-content:center;padding:24px;overflow:auto;' });
    var modal = Dom.el('div', { style: 'background:white;border-radius:14px;max-width:920px;width:100%;max-height:calc(100vh - 48px);overflow-y:auto;box-shadow:0 12px 36px rgba(0,0,0,.25);' });
    overlay.appendChild(modal);

    var header = Dom.el('div', { style: 'padding:18px 24px;border-bottom:1px solid #E5E7EB;display:flex;align-items:center;justify-content:space-between;position:sticky;top:0;background:white;z-index:2;gap:10px;' });
    header.appendChild(Dom.el('h2', { style: 'margin:0;font-size:18px;flex:1;' }, 'Responses · ' + form.title));

    // v22p44: CSV download. Anchor href pulls the token from sessionStorage
    // through a tiny redirect helper so the browser handles the download as
    // a regular GET (the route accepts ?format=csv).
    var csvBtn = Dom.el('button', {
      style: 'background:#16A34A;color:white;border:none;padding:6px 14px;border-radius:8px;font-size:12px;font-weight:700;cursor:pointer;flex-shrink:0;',
    }, '⤓ Download CSV');
    csvBtn.addEventListener('click', function () {
      var apiBase = (window.KT && window.KT.API_BASE) || 'https://api.kiddietrac.com/api/v1';
      var token = sessionStorage.getItem('kt_token');
      var activeAgencyId = sessionStorage.getItem('kt_active_agency_id') || '';
      var headers = { 'Authorization': 'Bearer ' + token, 'Accept': 'text/csv' };
      if (activeAgencyId) headers['X-Active-Agency-Id'] = activeAgencyId;
      csvBtn.disabled = true; csvBtn.textContent = 'Preparing…';
      fetch(apiBase + '/admin/forms/' + form.id + '/responses?format=csv', { headers: headers })
        .then(function (r) { if (!r.ok) throw new Error('HTTP ' + r.status); return r.blob(); })
        .then(function (blob) {
          var url = URL.createObjectURL(blob);
          var a = document.createElement('a');
          a.href = url;
          a.download = (form.title || 'form').replace(/[^a-z0-9\-]+/gi, '-').toLowerCase() + '-responses.csv';
          document.body.appendChild(a); a.click();
          setTimeout(function () { URL.revokeObjectURL(url); a.remove(); }, 500);
        })
        .catch(function (e) { alert('CSV failed: ' + e.message); })
        .finally(function () { csvBtn.disabled = false; csvBtn.textContent = '⤓ Download CSV'; });
    });
    header.appendChild(csvBtn);

    var x = Dom.el('button', { style: 'background:transparent;border:none;font-size:22px;color:#6B7280;cursor:pointer;' }, '×');
    x.addEventListener('click', function () { overlay.remove(); });
    header.appendChild(x);
    modal.appendChild(header);

    var bodyEl = Dom.el('div', { style: 'padding:20px 24px;' });
    bodyEl.appendChild(Dom.el('div', { style: 'padding:24px;text-align:center;color:#9CA3AF;' }, 'Loading…'));
    modal.appendChild(bodyEl);

    Api.get('/admin/forms/' + form.id + '/responses').then(function (data) {
      Dom.clear(bodyEl);
      if (!data.responses || !data.responses.length) {
        bodyEl.appendChild(Dom.el('div', { style: 'padding:40px;text-align:center;color:#6B7280;' }, 'No responses yet.'));
        return;
      }
      var schema = []; try { schema = JSON.parse(form.schema_json || '[]'); } catch (e) {}
      var idToLabel = {}, idToType = {};
      schema.forEach(function (f) { idToLabel[f.id] = f.label; idToType[f.id] = f.type; });

      data.responses.forEach(function (r) {
        var card = Dom.el('div', { style: 'background:#FAFBFC;border:1px solid #E5E7EB;border-radius:10px;padding:14px 16px;margin-bottom:10px;' });
        card.appendChild(Dom.el('div', { style: 'display:flex;justify-content:space-between;font-size:12px;color:#6B7280;margin-bottom:8px;' },
          (r.submitter_name || 'unknown') + ' · ' + fmtDate(r.submitted_at)));
        var resp = {}; try { resp = JSON.parse(r.response_json || '{}') || {}; } catch (e) {}
        Object.keys(resp).forEach(function (k) {
          var v = resp[k]; if (Array.isArray(v)) v = v.join(', ');
          var line = Dom.el('div', { style: 'display:grid;grid-template-columns:200px 1fr;gap:10px;font-size:13px;padding:4px 0;' });
          line.appendChild(Dom.el('div', { style: 'color:#6B7280;font-weight:600;' }, idToLabel[k] || k));
          var valCell = Dom.el('div', { style: 'color:#111827;word-break:break-word;' });
          // v22p85: render signatures as images and payments as money.
          if (typeof v === 'string' && v.indexOf('data:image') === 0) {
            valCell.appendChild(Dom.el('img', { src: v, style: 'max-width:240px;max-height:110px;border:1px solid #E5E7EB;border-radius:6px;background:white;padding:2px;' }));
          } else if (idToType[k] === 'payment' && v) {
            var amt = Number(v) || 0;
            valCell.textContent = '$' + amt.toFixed(2) + ' authorised';
            valCell.style.color = '#15803D'; valCell.style.fontWeight = '700';
          } else {
            valCell.textContent = (v == null || v === '') ? '—' : String(v);
          }
          line.appendChild(valCell);
          card.appendChild(line);
        });
        bodyEl.appendChild(card);
      });
    }).catch(function (e) {
      Dom.clear(bodyEl);
      bodyEl.appendChild(Dom.el('div', { style: 'color:#DC2626;padding:24px;' }, 'Failed: ' + e.message));
    });

    document.body.appendChild(overlay);
  }

  // ── Helpers ───────────────────────────────────────────────────────
  function label(t) { return Dom.el('label', { style: 'display:block;font-size:12px;font-weight:700;color:#374151;margin-bottom:5px;letter-spacing:.3px;' }, t); }
  function inputStyle() { return 'width:100%;padding:8px 12px;border:1px solid #D1D5DB;border-radius:8px;font-size:13px;box-sizing:border-box;margin-bottom:12px;'; }
  function btnPrimary() { return 'background:#1F6080;color:white;border:none;padding:9px 18px;border-radius:8px;font-weight:700;cursor:pointer;font-size:14px;'; }
  function btnDanger()  { return 'background:white;color:#DC2626;border:1px solid #DC2626;padding:9px 18px;border-radius:8px;font-weight:700;cursor:pointer;font-size:14px;'; }

  if (Shell && Shell.registerScreen) {
    Shell.registerScreen('agency_admin:admin-forms', render);
    Shell.registerScreen('centre_director:admin-forms', render);
    Shell.registerScreen('platform_admin:admin-forms', render);
  }
  KT.Forms = { render: render };
})(window);
