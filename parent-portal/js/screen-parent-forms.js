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

  // ── Shared "My documents & agreements" section (v23, 2026-07-20) ──────
  // Any signed-in user's own filed documents — their signed Terms/Privacy/NDA
  // and any files an admin attached to their record. Surfaced here under Forms
  // (mobile/APK) and inside Settings for staff roles. Reused via KT.renderMyDocuments.
  function docHref(u) {
    if (!u) return '#';
    if (/^https?:\/\//i.test(u)) return u;
    var base = (window.KT && KT.API_BASE) || 'https://api.kiddietrac.com/api/v1';
    return base.replace(/\/api\/v1\/?$/, '') + u;
  }
  // Open a document. The files are public (unguessable paths on /storage), so we
  // open the direct URL. On the Capacitor APK a plain target=_blank does nothing —
  // use the in-app Browser plugin when present; else a new tab; else same-window.
  function openDocUrl(url) {
    if (!url) return;
    var C = window.Capacitor;
    var native = C && (C.isNativePlatform ? C.isNativePlatform() : C.isNative);
    var B = C && C.Plugins && C.Plugins.Browser;
    // On the APK, window.open returns a non-null stub but opens nothing (that's why
    // "Open" did nothing). Use the in-app Browser plugin if present, else navigate
    // the WebView to the file so its download handler fires.
    if (native && B && B.open) { try { B.open({ url: url }); return; } catch (e) {} }
    if (native) { try { window.location.href = url; } catch (e) {} return; }
    var w = null;
    try { w = window.open(url, '_blank', 'noopener'); } catch (e) {}
    if (!w) { try { window.location.href = url; } catch (e) {} }
  }
  function fmtSize(n) { n = Number(n || 0); if (n < 1024) return n + ' B'; if (n < 1048576) return Math.round(n / 1024) + ' KB'; return (n / 1048576).toFixed(1) + ' MB'; }
  function docIcon(d) {
    if (d.category === 'agreement') return '🔏';
    var t = (d.file_type || '') + ' ' + (d.file_url || '');
    if (/pdf/i.test(t)) return '📄';
    if (/(png|jpe?g|webp|gif|image)/i.test(t)) return '🖼️';
    if (/(doc|word)/i.test(t)) return '📃';
    if (/(xls|sheet|excel|csv)/i.test(t)) return '📊';
    return '📎';
  }
  function renderMyDocuments(container) {
    var catLabels = { agreement: 'Terms, Privacy & NDA', file: 'File', contract: 'Contract', certificate: 'Certificate', id: 'ID document', other: 'Other' };
    var box = Dom.el('div', { style: 'background:#fff;border-radius:14px;padding:16px 18px;box-shadow:0 1px 3px rgba(0,0,0,.05);margin-bottom:18px;' });
    box.appendChild(Dom.el('div', { style: 'font-size:15px;font-weight:800;color:#111827;' }, '📄 My documents & agreements'));
    box.appendChild(Dom.el('div', { style: 'font-size:12.5px;color:#6B7280;margin:3px 0 12px;' }, 'Your signed Terms, Privacy & NDA and any files on your record.'));
    var list = Dom.el('div', {});
    box.appendChild(list);
    list.appendChild(Dom.el('div', { style: 'color:#64748B;font-size:13px;padding:6px 0;' }, 'Loading…'));
    container.appendChild(box);

    Api.get('/auth/me/documents').then(function (r) {
      Dom.clear(list);
      var docs = (r && r.documents) || [];
      if (!docs.length) { list.appendChild(Dom.el('div', { style: 'color:#64748B;font-size:13px;padding:6px 0;' }, 'No documents on file yet.')); return; }
      list.appendChild(Dom.el('div', { style: 'font-size:12px;color:#64748B;font-weight:600;padding:0 0 8px;' }, 'Showing ' + docs.length + ' document' + (docs.length === 1 ? '' : 's')));
      docs.forEach(function (d) {
        var row = Dom.el('div', { style: 'display:flex;align-items:center;gap:11px;padding:10px 12px;border:1px solid #EEF2F7;border-radius:10px;margin-bottom:7px;' });
        row.appendChild(Dom.el('span', { style: 'font-size:22px;flex:0 0 auto;' }, docIcon(d)));
        var mid = Dom.el('div', { style: 'flex:1;min-width:0;' });
        mid.appendChild(Dom.el('div', { style: 'font-weight:700;font-size:14px;color:#111827;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;' }, d.title || 'Document'));
        var bits = [catLabels[d.category] || d.category || 'File'];
        if (d.file_size) bits.push(fmtSize(d.file_size));
        if (d.signed_at) bits.push('signed ' + String(d.signed_at).slice(0, 10));
        else if (d.created_at) bits.push(String(d.created_at).slice(0, 10));
        mid.appendChild(Dom.el('div', { style: 'font-size:11.5px;color:#64748B;margin-top:1px;' }, bits.join(' · ')));
        row.appendChild(mid);
        var openBtn = Dom.el('button', { type: 'button', style: 'flex:0 0 auto;font-size:12.5px;font-weight:700;color:#1F6080;background:#fff;cursor:pointer;padding:6px 13px;border:1px solid #1F6080;border-radius:8px;' }, 'Open');
        openBtn.addEventListener('click', function () { openDocUrl(docHref(d.file_url)); });
        row.appendChild(openBtn);
        list.appendChild(row);
      });
    }).catch(function () {
      Dom.clear(list);
      list.appendChild(Dom.el('div', { style: 'color:#B91C1C;font-size:13px;' }, 'Could not load your documents.'));
    });
  }
  KT.renderMyDocuments = renderMyDocuments;

  function render(container) {
    Dom.clear(container);
    var wrap = Dom.el('div', { style: 'padding:24px;max-width:880px;margin:0 auto;' });
    container.appendChild(wrap);

    var hero = Dom.el('div', { class: 'kt-hero', style: 'background:linear-gradient(135deg,#7C3AED 0%,#1F6080 60%,#16637A 100%);' });
    hero.innerHTML = '<div class="kt-hero-greet">📝 FORMS</div><h1>Forms to fill in</h1><div class="kt-hero-sub">Permission slips, surveys, and updates from your centre. Fill in once and we keep your response on file.</div>';
    wrap.appendChild(hero);

    // Your own filed documents (incl. signed NDA) live right here under Forms.
    var docsWrap = Dom.el('div', { style: 'margin-top:18px;' });
    wrap.appendChild(docsWrap);
    renderMyDocuments(docsWrap);

    var listWrap = Dom.el('div', { style: 'margin-top:18px;', 'data-kt-list': '1' });
    wrap.appendChild(listWrap);
    listWrap.appendChild(Dom.el('div', { style: 'padding:30px;text-align:center;color:#64748B;' }, 'Loading…'));

    Api.get('/forms').then(function (data) {
      Dom.clear(listWrap);
      if (!data.forms || !data.forms.length) {
        listWrap.appendChild(Dom.el('div', { style: 'background:white;border-radius:12px;padding:32px;text-align:center;color:#6B7280;box-shadow:0 1px 3px rgba(0,0,0,.04);' }, 'No forms waiting for you right now.'));
        return;
      }
      listWrap.appendChild(Dom.el('div', { style: 'font-size:12px;color:#64748B;font-weight:600;padding:2px 2px 8px;' }, 'Showing ' + data.forms.length + ' form' + (data.forms.length === 1 ? '' : 's')));
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
    var meta = Dom.el('div', { style: 'font-size:11px;color:#64748B;margin-top:6px;' });
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
    x.addEventListener('click', async function () { if (!isDirty(responses) || await KT.confirm('Discard your changes?')) overlay.remove(); });
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
    } else if (f.type === 'signature') {
      // v22p85: digital signature pad — draws to a canvas, stored as a PNG data URL.
      var sigBox = Dom.el('div', { style: 'border:1px solid #D1D5DB;border-radius:8px;overflow:hidden;background:#FFFEF8;' });
      var canvas = Dom.el('canvas', { style: 'display:block;width:100%;height:160px;touch-action:none;cursor:crosshair;' });
      sigBox.appendChild(canvas);
      wrap.appendChild(sigBox);
      var sigBar = Dom.el('div', { style: 'display:flex;justify-content:space-between;align-items:center;margin-top:6px;' });
      sigBar.appendChild(Dom.el('span', { style: 'font-size:12px;color:#64748B;' }, 'Sign above with your finger or mouse'));
      var clearBtn = Dom.el('button', { type: 'button', style: 'background:#F1F5F9;border:none;padding:5px 12px;border-radius:6px;font-size:12px;cursor:pointer;color:#374151;' }, 'Clear');
      sigBar.appendChild(clearBtn);
      wrap.appendChild(sigBar);
      setTimeout(function () {
        initSignaturePad(canvas,
          function (dataUrl) { responses[f.id] = dataUrl; onChange(); },
          clearBtn,
          function () { responses[f.id] = ''; onChange(); });
      }, 0);
    } else if (f.type === 'payment') {
      // v22p85: payment attachment — shows the amount the director attached to
      // this form. Submitting records the charge against the family's account.
      var amt = Number(f.amount || 0);
      var payCard = Dom.el('div', { style: 'border:1px solid #BFE3CF;background:#F0FBF4;border-radius:10px;padding:14px 16px;' });
      payCard.appendChild(Dom.el('div', { style: 'font-size:13px;color:#166534;font-weight:600;margin-bottom:4px;' }, '💳 Payment required'));
      payCard.appendChild(Dom.el('div', { style: 'font-size:24px;font-weight:800;color:#15803D;' }, fmtMoney(amt)));
      if (f.help) payCard.appendChild(Dom.el('div', { style: 'font-size:12px;color:#166534;margin-top:4px;' }, f.help));
      var ackWrap = Dom.el('label', { style: 'display:flex;align-items:center;gap:10px;margin-top:12px;font-size:13px;color:#0F172A;cursor:pointer;' });
      var ack = Dom.el('input', { type: 'checkbox' });
      ack.addEventListener('change', function () { responses[f.id] = ack.checked ? amt : ''; onChange(); });
      ackWrap.appendChild(ack);
      ackWrap.appendChild(Dom.el('span', {}, 'I authorise this ' + fmtMoney(amt) + ' charge to be added to my account.'));
      payCard.appendChild(ackWrap);
      wrap.appendChild(payCard);
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

  function fmtMoney(n) {
    n = Number(n) || 0;
    try { return new Intl.NumberFormat(undefined, { style: 'currency', currency: 'CAD' }).format(n); }
    catch (e) { return '$' + n.toFixed(2); }
  }

  // Canvas signature pad. Calls onDraw(dataUrl) when a stroke completes,
  // onClear() when the Clear button is pressed. Supports mouse + touch.
  function initSignaturePad(canvas, onDraw, clearBtn, onClear) {
    var rect = canvas.getBoundingClientRect();
    var ratio = window.devicePixelRatio || 1;
    canvas.width = Math.max(1, Math.round((rect.width || 300) * ratio));
    canvas.height = Math.max(1, Math.round((rect.height || 160) * ratio));
    var ctx = canvas.getContext('2d');
    ctx.scale(ratio, ratio);
    ctx.lineWidth = 2.2; ctx.lineCap = 'round'; ctx.lineJoin = 'round'; ctx.strokeStyle = '#111827';
    var drawing = false, last = null, dirty = false;
    function pos(e) {
      var r = canvas.getBoundingClientRect();
      var p = (e.touches && e.touches[0]) || e;
      return { x: p.clientX - r.left, y: p.clientY - r.top };
    }
    function start(e) { e.preventDefault(); drawing = true; last = pos(e); }
    function move(e) {
      if (!drawing) return;
      e.preventDefault();
      var p = pos(e);
      ctx.beginPath(); ctx.moveTo(last.x, last.y); ctx.lineTo(p.x, p.y); ctx.stroke();
      last = p; dirty = true;
    }
    function end() { if (!drawing) return; drawing = false; if (dirty) onDraw(canvas.toDataURL('image/png')); }
    canvas.addEventListener('mousedown', start);
    canvas.addEventListener('mousemove', move);
    window.addEventListener('mouseup', end);
    canvas.addEventListener('touchstart', start, { passive: false });
    canvas.addEventListener('touchmove', move, { passive: false });
    canvas.addEventListener('touchend', end);
    clearBtn.addEventListener('click', function () {
      ctx.clearRect(0, 0, canvas.width, canvas.height);
      dirty = false; onClear();
    });
  }

  if (Shell && Shell.registerScreen) {
    Shell.registerScreen('guardian:forms', render);
    Shell.registerScreen('guardian:parent-forms', render);
    // Educators get the same Forms view: their own filed documents (signed NDA,
    // Terms/Privacy) plus any published forms addressed to staff.
    Shell.registerScreen('educator:forms', render);
    // Home visitors too — their own documents + any published forms for them.
    Shell.registerScreen('home_visitor:forms', render);
  }
  KT.ParentForms = { render: render };
})(window);
