/* ═══════════════════════════════════════════════════════════════════
   KIDDIETRAC v22p44 — Parent-side forms renderer
   Hash: #parent-forms
   Lists published forms whose audience matches the guardian, renders
   the schema, submits to POST /forms/{id}/submit. Honours
   visible_if conditional rules and validates required fields client-
   side before send.
   ═══════════════════════════════════════════════════════════════════ */
(function (window) {
  'use strict';
  if (!window.KT) return;
  var KT = window.KT;
  var Api = KT.Api;
  var Dom = KT.Dom;
  var Shell = KT.Shell;

  function esc(s) { return s == null ? '' : String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;'); }

  function render(container) {
    Dom.clear(container);
    var wrap = Dom.el('div', { style: 'padding:24px;max-width:880px;margin:0 auto;' });
    container.appendChild(wrap);

    var hero = Dom.el('div', { class: 'kt-hero', style: 'background:linear-gradient(135deg,#7C3AED 0%,#1F6080 60%,#16637A 100%);' });
    hero.innerHTML = '<div class="kt-hero-greet">📝 FORMS</div><h1>Forms to fill in</h1><div class="kt-hero-sub">Permission slips, surveys, and updates from your centre. Fill in once and we keep your response on file.</div>';
    wrap.appendChild(hero);

    var listWrap = Dom.el('div', { style: 'margin-top:18px;' });
    wrap.appendChild(listWrap);
    listWrap.appendChild(Dom.el('div', { style: 'padding:30px;text-align:center;color:#9CA3AF;' }, 'Loading…'));

    Api.get('/forms').then(function (data) {
      Dom.clear(listWrap);
      if (!data.forms || !data.forms.length) {
        listWrap.appendChild(Dom.el('div', { style: 'background:white;border-radius:12px;padding:32px;text-align:center;color:#6B7280;box-shadow:0 1px 3px rgba(0,0,0,.04);' }, 'No forms waiting for you right now.'));
        return;
      }
      data.forms.forEach(function (f) { listWrap.appendChild(renderCard(f, container)); });
    }).catch(function (e) {
      Dom.clear(listWrap);
      listWrap.appendChild(Dom.el('div', { style: 'padding:24px;color:#DC2626;background:#FEE2E2;border-radius:10px;' }, 'Could not load forms: ' + (e.message || 'error')));
    });
  }

  function renderCard(form, container) {
    var card = Dom.el('div', { style: 'background:white;border-radius:14px;padding:18px 22px;box-shadow:0 1px 3px rgba(0,0,0,.05);margin-bottom:12px;cursor:pointer;border-left:4px solid ' + (form.already_submitted ? '#16A34A' : '#7C3AED') + ';display:flex;align-items:center;gap:14px;' });
    card.addEventListener('mouseenter', function () { card.style.transform = 'translateY(-1px)'; card.style.boxShadow = '0 4px 12px rgba(0,0,0,.07)'; });
    card.addEventListener('mouseleave', function () { card.style.transform = ''; card.style.boxShadow = '0 1px 3px rgba(0,0,0,.05)'; });
    card.addEventListener('click', function () { openSubmitModal(form, container); });

    card.appendChild(Dom.el('div', { style: 'font-size:32px;flex-shrink:0;' }, form.already_submitted ? '✅' : '📝'));
    var body = Dom.el('div', { style: 'flex:1;min-width:0;' });
    body.appendChild(Dom.el('div', { style: 'font-weight:700;font-size:16px;color:#111827;' }, form.title));
    if (form.description) body.appendChild(Dom.el('div', { style: 'color:#6B7280;font-size:13px;margin-top:4px;' }, form.description));
    var meta = Dom.el('div', { style: 'font-size:11px;color:#9CA3AF;margin-top:6px;' });
    var fields = 0; try { fields = (JSON.parse(form.schema_json || '[]') || []).length; } catch (e) {}
    meta.textContent = fields + ' question' + (fields === 1 ? '' : 's') + (form.already_submitted ? ' · You\'ve already submitted this one' : ' · Tap to fill in');
    body.appendChild(meta);
    card.appendChild(body);

    if (form.already_submitted) {
      card.appendChild(Dom.el('span', { style: 'background:#DCFCE7;color:#166534;font-size:10px;font-weight:700;letter-spacing:0.5px;padding:3px 10px;border-radius:999px;' }, 'COMPLETE'));
    } else {
      card.appendChild(Dom.el('div', { style: 'color:#7C3AED;font-size:14px;' }, '→'));
    }
    return card;
  }

  function openSubmitModal(form, container) {
    var schema = []; try { schema = JSON.parse(form.schema_json || '[]') || []; } catch (e) {}
    if (!schema.length) { alert('This form has no questions.'); return; }

    var responses = {};
    schema.forEach(function (f) { responses[f.id] = f.type === 'checkbox' ? false : ''; });

    var overlay = Dom.el('div', { style: 'position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:1000;display:flex;align-items:flex-start;justify-content:center;padding:24px;overflow-y:auto;' });
    var modal = Dom.el('div', { style: 'background:white;border-radius:14px;max-width:640px;width:100%;box-shadow:0 12px 36px rgba(0,0,0,.25);overflow:hidden;margin-top:40px;' });
    overlay.appendChild(modal);

    var header = Dom.el('div', { style: 'padding:20px 24px;border-bottom:1px solid #E5E7EB;background:linear-gradient(135deg,#7C3AED 0%,#1F6080 100%);color:white;' });
    header.innerHTML = '<div style="display:flex;align-items:center;justify-content:space-between;">' +
      '<div><h2 style="margin:0;font-size:18px;font-weight:700;">' + esc(form.title) + '</h2>' +
      (form.description ? '<div style="margin-top:4px;font-size:13px;opacity:.85;">' + esc(form.description) + '</div>' : '') +
      '</div></div>';
    var x = Dom.el('button', { style: 'position:absolute;top:18px;right:22px;background:transparent;border:none;font-size:22px;color:white;cursor:pointer;opacity:.85;' }, '×');
    x.addEventListener('click', function () { if (!isDirty(responses) || confirm('Discard your changes?')) overlay.remove(); });
    header.style.position = 'relative';
    header.appendChild(x);
    modal.appendChild(header);

    var body = Dom.el('div', { style: 'padding:24px;max-height:calc(100vh - 220px);overflow-y:auto;' });
    modal.appendChild(body);

    // Render each field; re-render visibility on every input change
    var fieldNodes = {};
    schema.forEach(function (f) {
      var wrap = renderField(f, responses, function () { reevaluateVisibility(); });
      fieldNodes[f.id] = wrap;
      body.appendChild(wrap);
    });

    function reevaluateVisibility() {
      schema.forEach(function (f) {
        var visible = isVisible(f, responses);
        var node = fieldNodes[f.id];
        if (node) node.style.display = visible ? '' : 'none';
      });
    }
    reevaluateVisibility();

    var actions = Dom.el('div', { style: 'padding:16px 24px;border-top:1px solid #E5E7EB;display:flex;justify-content:space-between;align-items:center;background:#FAFBFC;' });
    var helpText = Dom.el('div', { style: 'font-size:12px;color:#6B7280;' }, '* required fields');
    actions.appendChild(helpText);
    var submit = Dom.el('button', { style: 'background:#7C3AED;color:white;border:none;padding:10px 22px;border-radius:10px;font-weight:700;cursor:pointer;font-size:14px;' }, 'Submit');
    actions.appendChild(submit);
    modal.appendChild(actions);

    submit.addEventListener('click', function () {
      var missing = [];
      schema.forEach(function (f) {
        if (!f.required) return;
        if (!isVisible(f, responses)) return;
        var v = responses[f.id];
        if (v === null || v === undefined || v === '' || (Array.isArray(v) && !v.length) || v === false) {
          missing.push(f.label || f.id);
        }
      });
      if (missing.length) {
        alert('Please fill in: ' + missing.join(', '));
        return;
      }
      // Strip values from hidden fields so we don't store stale data
      var clean = {};
      schema.forEach(function (f) {
        if (isVisible(f, responses)) clean[f.id] = responses[f.id];
      });
      submit.disabled = true; submit.textContent = 'Submitting…';
      Api.post('/forms/' + form.id + '/submit', { response: clean })
        .then(function () {
          overlay.remove();
          alert('Thanks! Your response is saved.');
          render(container);
        })
        .catch(function (e) {
          alert('Submit failed: ' + (e.message || 'error'));
          submit.disabled = false; submit.textContent = 'Submit';
        });
    });

    document.body.appendChild(overlay);
  }

  function renderField(f, responses, onChange) {
    var wrap = Dom.el('div', { style: 'margin-bottom:18px;' });
    var lbl = Dom.el('label', { style: 'display:block;font-size:13px;font-weight:700;color:#0F172A;margin-bottom:6px;' });
    lbl.appendChild(Dom.el('span', {}, f.label || f.id));
    if (f.required) lbl.appendChild(Dom.el('span', { style: 'color:#DC2626;margin-left:4px;' }, '*'));
    wrap.appendChild(lbl);

    if (f.type === 'text' || f.type === 'email' || f.type === 'number' || f.type === 'date') {
      var input = Dom.el('input', { type: f.type, placeholder: f.placeholder || '', style: inputStyle() });
      input.value = responses[f.id] || '';
      input.addEventListener('input', function () { responses[f.id] = input.value; onChange(); });
      wrap.appendChild(input);
    } else if (f.type === 'textarea') {
      var ta = Dom.el('textarea', { placeholder: f.placeholder || '', style: inputStyle() + 'min-height:90px;resize:vertical;font-family:inherit;' });
      ta.value = responses[f.id] || '';
      ta.addEventListener('input', function () { responses[f.id] = ta.value; onChange(); });
      wrap.appendChild(ta);
    } else if (f.type === 'select') {
      var sel = Dom.el('select', { style: inputStyle() });
      sel.appendChild(Dom.el('option', { value: '' }, '— select —'));
      (f.options || []).forEach(function (o) {
        var opt = Dom.el('option', { value: o }, o);
        if (responses[f.id] === o) opt.selected = true;
        sel.appendChild(opt);
      });
      sel.addEventListener('change', function () { responses[f.id] = sel.value; onChange(); });
      wrap.appendChild(sel);
    } else if (f.type === 'checkbox') {
      var cbWrap = Dom.el('label', { style: 'display:flex;align-items:center;gap:10px;padding:10px 12px;background:#F8FAFC;border:1px solid #E5E7EB;border-radius:8px;cursor:pointer;font-size:14px;color:#0F172A;' });
      var cb = Dom.el('input', { type: 'checkbox' });
      if (responses[f.id] === true) cb.checked = true;
      cb.addEventListener('change', function () { responses[f.id] = cb.checked; onChange(); });
      cbWrap.appendChild(cb);
      cbWrap.appendChild(Dom.el('span', {}, f.placeholder || 'Yes'));
      wrap.appendChild(cbWrap);
    } else if (f.type === 'radio') {
      (f.options || []).forEach(function (o) {
        var optWrap = Dom.el('label', { style: 'display:flex;align-items:center;gap:10px;padding:8px 12px;border:1px solid #E5E7EB;border-radius:8px;cursor:pointer;font-size:14px;color:#0F172A;margin-bottom:6px;background:white;' });
        var r = Dom.el('input', { type: 'radio', name: 'r-' + f.id, value: o });
        if (responses[f.id] === o) r.checked = true;
        r.addEventListener('change', function () { responses[f.id] = o; onChange(); });
        optWrap.appendChild(r);
        optWrap.appendChild(Dom.el('span', {}, o));
        wrap.appendChild(optWrap);
      });
    }

    if (f.help) {
      wrap.appendChild(Dom.el('div', { style: 'font-size:12px;color:#6B7280;margin-top:6px;' }, f.help));
    }
    return wrap;
  }

  // Evaluate visible_if rules. A field shows when its rule is satisfied,
  // or always when no rule is present. Supports both `equals` (string match)
  // and `in` (one-of array) operators.
  function isVisible(field, responses) {
    var rule = field.visible_if;
    if (!rule || !rule.field_id) return true;
    var v = responses[rule.field_id];
    if (rule.equals !== undefined) return String(v) === String(rule.equals);
    if (Array.isArray(rule.in)) return rule.in.map(String).indexOf(String(v)) !== -1;
    if (rule.equals_truthy) return !!v;
    return true;
  }

  function isDirty(responses) {
    return Object.keys(responses).some(function (k) {
      var v = responses[k];
      return v !== '' && v !== false && v !== null && v !== undefined && !(Array.isArray(v) && !v.length);
    });
  }

  function inputStyle() { return 'width:100%;padding:10px 12px;border:1px solid #D1D5DB;border-radius:8px;font-size:14px;box-sizing:border-box;color:#0F172A;background:white;'; }

  if (Shell && Shell.registerScreen) {
    Shell.registerScreen('guardian:forms', render);
    Shell.registerScreen('guardian:parent-forms', render);
  }
  KT.ParentForms = { render: render };
})(window);
