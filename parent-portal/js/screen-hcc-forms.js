/* ═══════════════════════════════════════════════════════════════════
   KiddieTrac — Home-visitor inspection forms (2026-07-21).

   Two standardised forms, digitised field-for-field from the originals:
     • Monthly Home Visitor Monitoring & Inspection Report
     • Standard Home Visitor Checklist (quarterly, O. Reg. 137/15)

   A home visitor picks a form (#inspection-forms), fills it (#inspection-form
   ?type=KEY) and submits. On submit the completed form is emailed as a PDF to
   the agency's directors/admins and stored. Reviewers see every submission in
   a detailed table (#hcc-forms) and can open a read-only copy / download the
   PDF (#hcc-form-view?id=N).

   The form is built ENTIRELY from the server schema (single source of truth),
   so the on-screen form, the emailed PDF and the stored answers all match.
   ═══════════════════════════════════════════════════════════════════ */
(function (window) {
  'use strict';
  var KT = window.KT;
  if (!KT || !KT.Shell || !KT.Shell.registerScreen) return;
  var Api = KT.Api;

  function el(tag, attrs, kids) {
    var e = document.createElement(tag);
    if (attrs) Object.keys(attrs).forEach(function (k) {
      if (k === 'style') e.style.cssText = attrs[k];
      else if (k === 'html') e.innerHTML = attrs[k];
      else e.setAttribute(k, attrs[k]);
    });
    (kids || []).forEach(function (c) { if (c != null) e.appendChild(typeof c === 'string' ? document.createTextNode(c) : c); });
    return e;
  }
  function esc(s) { return String(s == null ? '' : s).replace(/[&<>"]/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]; }); }
  function clear(n) { while (n.firstChild) n.removeChild(n.firstChild); }
  function user() { try { return JSON.parse(sessionStorage.getItem('kt_user') || '{}'); } catch (e) { return {}; } }
  function tok() { return sessionStorage.getItem('kt_token') || localStorage.getItem('kt_token'); }
  function apiBase() { return (KT.API_BASE) || 'https://api.kiddietrac.com/api/v1'; }
  function activeAgency() { return sessionStorage.getItem('kt_active_agency_id') || localStorage.getItem('kt_active_agency_id') || ''; }
  function today() { var d = new Date(); return d.getFullYear() + '-' + ('0' + (d.getMonth() + 1)).slice(-2) + '-' + ('0' + d.getDate()).slice(-2); }
  function qp(name) { var m = (location.hash.split('?')[1] || '').match(new RegExp('(?:^|&)' + name + '=([^&]*)')); return m ? decodeURIComponent(m[1]) : ''; }
  // Date-only: formatted from its parts. Parsed, it renders a day early west of UTC.
  function fmtDate(d) { return (window.KT && KT.dayLabel) ? KT.dayLabel(d) : (d || ''); }

  // Wrap wide content so it scrolls horizontally on mobile/APK instead of overflowing the page.
  function scrollWrap(node, minWidth) {
    var s = el('div', { style: 'overflow-x:auto;-webkit-overflow-scrolling:touch;max-width:100%;' });
    if (minWidth) node.style.minWidth = minWidth;
    s.appendChild(node);
    return s;
  }

  var IN = 'width:100%;box-sizing:border-box;padding:7px 9px;border:1px solid #CBD5E1;border-radius:8px;font-size:13px;background:#fff;';
  var schemaCache = {};
  function getSchema(key) {
    if (schemaCache[key]) return Promise.resolve(schemaCache[key]);
    return Api.get('/inspection-forms/schema/' + key).then(function (d) { schemaCache[key] = d.schema; return d.schema; });
  }

  async function blobDownload(path, filename, btn) {
    var old = btn ? btn.textContent : null; if (btn) { btn.textContent = '…'; btn.disabled = true; }
    try {
      var h = { Authorization: 'Bearer ' + tok() };
      if (activeAgency()) h['X-Active-Agency-Id'] = activeAgency();
      var r = await fetch(apiBase() + path, { headers: h });
      if (!r.ok) throw new Error('HTTP ' + r.status);
      var blob = await r.blob(); var url = URL.createObjectURL(blob);
      var a = document.createElement('a'); a.href = url; a.download = filename;
      document.body.appendChild(a); a.click(); a.remove();
      setTimeout(function () { URL.revokeObjectURL(url); }, 1500);
    } catch (e) { if (KT.toast) KT.toast('⚠️', 'Download failed', e.message || '', '#DC2626'); }
    finally { if (btn) { btn.textContent = old; btn.disabled = false; } }
  }

  // ══ Fillable form builder ══════════════════════════════════════════
  function widthPct(w) { return w === 'full' ? '100%' : w === 'third' ? 'calc(33.33% - 8px)' : w === 'quarter' ? 'calc(25% - 9px)' : 'calc(50% - 6px)'; }

  function fieldInput(f) {
    if (f.type === 'select') {
      var opts = '<option value=""></option>' + (f.options || []).map(function (o) { return '<option value="' + esc(o) + '">' + esc(o) + '</option>'; }).join('');
      return el('select', { id: f.id, style: IN, html: opts });
    }
    var t = f.type === 'date' ? 'date' : (f.type === 'time' ? 'time' : 'text');
    return el('input', { id: f.id, type: t, style: IN });
  }

  function ynaRadio(name) {
    var wrap = el('div', { style: 'display:flex;gap:4px;justify-content:center;' });
    ['yes', 'no', 'na'].forEach(function (v, i) {
      var lbl = el('label', { style: 'font-size:11px;color:#475569;display:inline-flex;align-items:center;gap:2px;cursor:pointer;' }, [
        el('input', { type: 'radio', name: name, value: v, style: 'margin:0;' }),
        ['Yes', 'No', 'N/A'][i],
      ]);
      wrap.appendChild(lbl);
    });
    return wrap;
  }

  function buildChecklist(b) {
    var ministry = !!b.ministry;
    var t = el('table', { style: 'width:100%;border-collapse:collapse;margin:6px 0 10px;font-size:12.5px;' });
    var thead = el('tr', { style: 'background:#EEF2F6;' });
    if (ministry) {
      ['Q#', 'Reference', 'Risk', 'Description of Requirement'].forEach(function (h, i) {
        thead.appendChild(el('th', { style: 'padding:6px;border:1px solid #D5DCE3;font-size:11px;text-align:' + (i === 3 ? 'left' : 'center') + ';' + (i === 3 ? '' : 'white-space:nowrap;') }, [h]));
      });
    } else {
      thead.appendChild(el('th', { style: 'padding:6px;border:1px solid #D5DCE3;font-size:11px;text-align:left;' }, ['Item']));
    }
    ['Yes', 'No', 'N/A', 'Comments'].forEach(function (h) {
      thead.appendChild(el('th', { style: 'padding:6px;border:1px solid #D5DCE3;font-size:11px;' + (h === 'Comments' ? 'width:210px;' : 'width:38px;') }, [h]));
    });
    t.appendChild(thead);

    (b.items || []).forEach(function (it) {
      var descCell = el('td', { style: 'padding:7px;border:1px solid #D5DCE3;vertical-align:top;' });
      descCell.appendChild(el('div', {}, [it.desc]));
      if (it.bullets) { var ul = el('ul', { style: 'margin:4px 0 0 16px;' }); it.bullets.forEach(function (x) { ul.appendChild(el('li', {}, [x])); }); descCell.appendChild(ul); }
      if (it.note2) descCell.appendChild(el('div', { style: 'font-size:11px;color:#64748B;margin-top:4px;' }, [it.note2]));

      var commentCell = el('td', { style: 'padding:6px;border:1px solid #D5DCE3;vertical-align:top;' });
      commentCell.appendChild(el('textarea', { id: it._cid, rows: '2', style: IN + 'resize:vertical;font-size:12px;' }));
      if (it._pids) it.prompts.forEach(function (pl, pi) {
        commentCell.appendChild(el('div', { style: 'font-size:11px;color:#475569;margin-top:5px;' }, [pl]));
        commentCell.appendChild(el('input', { id: it._pids[pi], type: 'text', style: IN + 'font-size:12px;padding:4px 7px;' }));
      });

      var mkMeta = function () {
        var cells = [];
        cells.push(el('td', { style: 'padding:7px;border:1px solid #D5DCE3;vertical-align:top;font-weight:700;text-align:center;' }, [(it.n || '') + '.']));
        var refDiv = el('td', { style: 'padding:7px;border:1px solid #D5DCE3;vertical-align:top;font-size:11px;' }, [it.ref || '']);
        if (it.note) refDiv.appendChild(el('div', { style: 'font-size:10px;color:#64748B;font-style:italic;margin-top:3px;' }, [it.note]));
        cells.push(refDiv);
        cells.push(el('td', { style: 'padding:7px;border:1px solid #D5DCE3;vertical-align:top;font-size:11px;text-align:center;' }, [it.risk || '']));
        return cells;
      };

      if (it._sids && it._sids.length) {
        var n = it._sids.length;
        var tr0 = el('tr');
        if (ministry) mkMeta().forEach(function (c) { c.setAttribute('rowspan', ''); c.style.verticalAlign = 'top'; tr0.appendChild(c); });
        // description + first sub
        var d0 = descCell; d0.appendChild(el('div', { style: 'margin-top:6px;' }, ['- ' + it.subs[0]]));
        tr0.appendChild(d0);
        var yc = ynaRadio(it._sids[0]); tr0.appendChild(td(yc, 3, true));
        var cc = commentCell; cc.setAttribute('rowspan', String(n)); tr0.appendChild(cc);
        t.appendChild(tr0);
        // meta cells should span all sub rows
        if (ministry) { var metaCells = tr0.querySelectorAll('td'); for (var mi = 0; mi < 3; mi++) metaCells[mi].setAttribute('rowspan', String(n)); }
        for (var si = 1; si < n; si++) {
          var tr = el('tr');
          tr.appendChild(el('td', { style: 'padding:7px;border:1px solid #D5DCE3;vertical-align:top;' }, ['- ' + it.subs[si]]));
          tr.appendChild(td(ynaRadio(it._sids[si]), 3, true));
          t.appendChild(tr);
        }
      } else {
        var trS = el('tr');
        if (ministry) mkMeta().forEach(function (c) { trS.appendChild(c); });
        trS.appendChild(descCell);
        trS.appendChild(td(ynaRadio(it._vid), 3, true));
        trS.appendChild(commentCell);
        t.appendChild(trS);
      }
    });
    return scrollWrap(t, ministry ? '760px' : '560px');

    // one <td> wrapping a Y/N/A radio group, spanning the 3 answer columns visually via 3 cells
    function td(node) {
      // Return 3 narrow cells (Yes/No/N/A) — but our radio is a single group; render as one cell spanning 3 via colspan.
      var c = el('td', { colspan: '3', style: 'padding:7px;border:1px solid #D5DCE3;vertical-align:top;text-align:center;width:114px;' }, [node]);
      return c;
    }
  }

  function buildCheckgroup(b) {
    var wrap = el('div', {});
    if (b.intro) wrap.appendChild(el('div', { style: 'font-weight:700;font-size:13px;color:#334155;margin:8px 0 6px;' }, [b.intro]));
    var cols = b.cols || 2;
    var grid = el('div', { style: 'display:grid;grid-template-columns:repeat(' + cols + ',1fr);gap:4px 14px;' });
    (b.boxes || []).forEach(function (label, i) {
      grid.appendChild(el('label', { style: 'display:flex;align-items:flex-start;gap:7px;font-size:13px;color:#1f2937;cursor:pointer;padding:3px 0;' }, [
        el('input', { id: b._bids[i], type: 'checkbox', style: 'margin-top:2px;' }),
        el('span', {}, [label]),
      ]));
    });
    wrap.appendChild(grid);
    if (b._otherId) {
      wrap.appendChild(el('div', { style: 'display:flex;align-items:center;gap:8px;margin-top:8px;' }, [
        el('span', { style: 'font-size:13px;color:#334155;' }, ['Other:']),
        el('input', { id: b._otherId, type: 'text', style: IN + 'max-width:320px;' }),
      ]));
    }
    return wrap;
  }

  function buildTable(b) {
    var wrap = el('div', {});
    if (b.intro) wrap.appendChild(el('div', { style: 'font-size:13px;color:#334155;margin:6px 0;' }, [b.intro]));
    var t = el('table', { style: 'width:100%;border-collapse:collapse;margin:4px 0 8px;font-size:12.5px;' });
    var hr = el('tr', { style: 'background:#EEF2F6;' });
    b.columns.forEach(function (c) { hr.appendChild(el('th', { style: 'padding:6px;border:1px solid #D5DCE3;font-size:11px;' }, [c.label])); });
    t.appendChild(hr);
    if (b.note) { var nr = el('tr'); nr.appendChild(el('td', { colspan: String(b.columns.length), style: 'padding:5px 7px;border:1px solid #D5DCE3;font-size:10.5px;color:#64748B;background:#F8FAFC;' }, [b.note])); t.appendChild(nr); }
    for (var r = 0; r < (b.rows || 3); r++) {
      var tr = el('tr');
      b.columns.forEach(function (c) {
        var cell = el('td', { style: 'padding:3px;border:1px solid #D5DCE3;' });
        cell.appendChild(el('input', { id: b._id + '__' + r + '__' + c.id, type: 'text', style: 'width:100%;box-sizing:border-box;padding:5px 6px;border:none;font-size:12px;background:transparent;' }));
        tr.appendChild(cell);
      });
      t.appendChild(tr);
    }
    wrap.appendChild(scrollWrap(t, '520px'));
    if (b.comments) {
      wrap.appendChild(el('div', { style: 'font-weight:700;font-size:13px;color:#334155;margin:8px 0 4px;' }, [b.comments.label]));
      wrap.appendChild(el('textarea', { id: b.comments.id, rows: '3', style: IN + 'resize:vertical;' }));
    }
    return wrap;
  }

  function buildBlock(b) {
    switch (b.type) {
      case 'head':
        var g = el('div', { style: 'display:flex;flex-wrap:wrap;gap:12px;margin:6px 0 10px;' });
        b.fields.forEach(function (f) {
          g.appendChild(el('div', { style: 'flex:1 1 ' + widthPct(f.w) + ';min-width:150px;' }, [
            el('label', { style: 'display:block;font-size:12px;font-weight:700;color:#334155;margin-bottom:3px;' }, [f.label]),
            fieldInput(f),
          ]));
        });
        return g;
      case 'subhead': return el('div', { style: 'background:#E9ECEF;font-weight:700;font-size:13px;color:#334155;padding:6px 10px;margin:10px 0 6px;border-radius:6px;' }, [b.text]);
      case 'intro': return el('div', { style: 'font-size:13px;color:#475569;margin:6px 0;' }, [b.text]);
      case 'static': return el('div', { style: 'font-size:12.5px;color:#475569;background:#F8FAFC;border:1px solid #E2E8F0;border-radius:8px;padding:12px 14px;margin:6px 0;', html: b.html });
      case 'checklist': return buildChecklist(b);
      case 'checkgroup': return buildCheckgroup(b);
      case 'table': return buildTable(b);
      case 'textareas':
        var w = el('div', {});
        b.items.forEach(function (it) {
          w.appendChild(el('label', { style: 'display:block;font-size:13px;font-weight:700;color:#334155;margin:10px 0 4px;' }, [it.label]));
          w.appendChild(el('textarea', { id: it.id, rows: String(it.rows || 3), style: IN + 'resize:vertical;' }));
        });
        return w;
      case 'yn':
        var yw = el('div', {});
        b.items.forEach(function (it) {
          var row = el('div', { style: 'display:flex;align-items:center;gap:16px;margin:6px 0;' }, [el('div', { style: 'font-size:13px;color:#1f2937;flex:1;' }, [it.label])]);
          ['yes', 'no'].forEach(function (v) {
            row.appendChild(el('label', { style: 'font-size:13px;color:#475569;display:inline-flex;align-items:center;gap:4px;cursor:pointer;' }, [el('input', { type: 'radio', name: it.id, value: v }), v === 'yes' ? 'Yes' : 'No']));
          });
          yw.appendChild(row);
        });
        return yw;
      case 'sign':
        var sw = el('div', {});
        if (b.statements) { var st = el('div', { style: 'display:grid;grid-template-columns:1fr 1fr;gap:12px;margin:6px 0 12px;' }); b.statements.forEach(function (s) { st.appendChild(el('div', { style: 'font-size:12px;font-weight:700;color:#334155;background:#F8FAFC;border:1px solid #E2E8F0;border-radius:8px;padding:10px;' }, [s])); }); sw.appendChild(st); }
        b.columns.forEach(function (c) {
          sw.appendChild(el('div', { style: 'display:flex;align-items:center;gap:10px;margin:6px 0;max-width:520px;' }, [
            el('label', { style: 'font-size:13px;font-weight:700;color:#334155;min-width:210px;' }, [c.label + ':']),
            fieldInput(c),
          ]));
        });
        return sw;
    }
    return el('div');
  }

  function sectionBar(sec, brand) {
    if ((sec.bar || 'none') === 'none') return sec.title ? el('div', { style: 'font-size:14px;font-weight:800;color:#0f172a;margin:14px 0 6px;' }, [sec.title]) : null;
    if (sec.bar === 'cat') return el('div', { style: 'background:#D9EAD3;border:1px solid #B7CCA9;font-weight:800;font-size:13px;color:#274E13;padding:7px 11px;margin:16px 0 2px;border-radius:6px;' }, [sec.title]);
    if (sec.bar === 'catplain') return el('div', { style: 'font-size:14px;font-weight:800;color:#0f172a;border-bottom:2px solid #111;padding-bottom:4px;margin:18px 0 8px;' }, [sec.title]);
    return el('div', { style: 'background:#2B2F36;color:#fff;font-weight:800;font-size:13px;padding:8px 12px;margin:18px 0 8px;border-radius:8px;border-left:5px solid ' + (brand === 'ministry' ? '#111' : '#159FB4') + ';' }, [sec.title]);
  }

  // Read the DOM back into an answers object, keyed exactly like the schema ids.
  function collectAnswers(schema) {
    var ans = {};
    var radioVal = function (name) { var r = document.querySelector('input[name="' + name + '"]:checked'); return r ? r.value : ''; };
    var val = function (id) { var e = document.getElementById(id); return e ? e.value : ''; };
    schema.sections.forEach(function (sec) {
      sec.blocks.forEach(function (b) {
        if (b.type === 'head') b.fields.forEach(function (f) { ans[f.id] = val(f.id); });
        else if (b.type === 'checklist') b.items.forEach(function (it) {
          if (it._sids) it._sids.forEach(function (sid) { ans[sid] = radioVal(sid); });
          else if (it._vid) ans[it._vid] = radioVal(it._vid);
          if (it._cid) ans[it._cid] = val(it._cid);
          if (it._pids) it._pids.forEach(function (pid) { ans[pid] = val(pid); });
        });
        else if (b.type === 'checkgroup') { (b._bids || []).forEach(function (bid) { var e = document.getElementById(bid); ans[bid] = e && e.checked ? 1 : 0; }); if (b._otherId) ans[b._otherId] = val(b._otherId); }
        else if (b.type === 'textareas') b.items.forEach(function (it) { ans[it.id] = val(it.id); });
        else if (b.type === 'yn') b.items.forEach(function (it) { ans[it.id] = radioVal(it.id); });
        else if (b.type === 'sign') b.columns.forEach(function (c) { ans[c.id] = val(c.id); });
        else if (b.type === 'table') {
          var rows = [];
          for (var r = 0; r < (b.rows || 3); r++) {
            var row = {}, any = false;
            b.columns.forEach(function (c) { var v = val(b._id + '__' + r + '__' + c.id); row[c.id] = v; if (v) any = true; });
            if (any) rows.push(row);
          }
          ans[b._id] = rows;
          if (b.comments) ans[b.comments.id] = val(b.comments.id);
        }
      });
    });
    return ans;
  }

  // Populate the built form's inputs from a saved answers object (resume / edit).
  function applyAnswers(schema, ans) {
    if (!ans) return;
    var setRadio = function (name, v) { var r = document.querySelector('input[name="' + name + '"][value="' + v + '"]'); if (r) r.checked = true; };
    var setVal = function (id, v) { var e = document.getElementById(id); if (e && v != null && v !== '') e.value = v; };
    schema.sections.forEach(function (sec) {
      sec.blocks.forEach(function (b) {
        if (b.type === 'head') b.fields.forEach(function (f) { setVal(f.id, ans[f.id]); });
        else if (b.type === 'checklist') b.items.forEach(function (it) {
          if (it._sids) it._sids.forEach(function (sid) { if (ans[sid]) setRadio(sid, ans[sid]); });
          else if (it._vid && ans[it._vid]) setRadio(it._vid, ans[it._vid]);
          if (it._cid) setVal(it._cid, ans[it._cid]);
          if (it._pids) it._pids.forEach(function (pid) { setVal(pid, ans[pid]); });
        });
        else if (b.type === 'checkgroup') { (b._bids || []).forEach(function (bid) { var e = document.getElementById(bid); if (e) e.checked = !!ans[bid]; }); if (b._otherId) setVal(b._otherId, ans[b._otherId]); }
        else if (b.type === 'textareas') b.items.forEach(function (it) { setVal(it.id, ans[it.id]); });
        else if (b.type === 'yn') b.items.forEach(function (it) { if (ans[it.id]) setRadio(it.id, ans[it.id]); });
        else if (b.type === 'sign') b.columns.forEach(function (c) { setVal(c.id, ans[c.id]); });
        else if (b.type === 'table') {
          var rowsData = ans[b._id]; if (!Array.isArray(rowsData)) return;
          rowsData.forEach(function (row, r) { if (row) b.columns.forEach(function (c) { setVal(b._id + '__' + r + '__' + c.id, row[c.id]); }); });
          if (b.comments) setVal(b.comments.id, ans[b.comments.id]);
        }
      });
    });
  }

  // ── The fillable form screen (new / resume draft / edit submitted) ───
  async function renderFillable(container) {
    var editId = qp('id');
    var type = qp('type') || 'monthly_monitoring';
    container.innerHTML = '<div style="padding:24px;color:#64748B;">Loading form…</div>';
    var schema, centres = [], existing = null;
    try {
      if (editId) {
        var ex = await Api.get('/inspection-forms/' + editId);
        existing = ex; type = ex.form.form_type;
      }
      schema = await getSchema(type);
      try { var cd = await Api.get('/inspection-forms/centres'); centres = (cd && cd.centres) || []; } catch (e) {}
    } catch (e) { container.innerHTML = '<div style="padding:24px;color:#DC2626;">Could not load the form: ' + esc(e.message) + '</div>'; return; }

    var isDraft = existing && existing.form.status === 'draft';
    var isEditSubmitted = existing && existing.form.status === 'submitted';

    clear(container);
    var wrap = el('div', { style: 'max-width:900px;margin:0 auto;padding:16px 14px 120px;' });
    wrap.appendChild(el('div', { class: 'kt-hero', style: 'background:linear-gradient(135deg,#1F6080,#159FB4);color:#fff;border-radius:16px;padding:18px 22px;margin-bottom:8px;' }, [
      el('div', { style: 'font-size:12px;opacity:.85;letter-spacing:.5px;' }, [isEditSubmitted ? 'EDIT SUBMITTED FORM' : (isDraft ? 'CONTINUE DRAFT' : 'INSPECTION FORM')]),
      el('div', { style: 'font-size:19px;font-weight:800;' }, [schema.title + (schema.title2 ? ' ' + schema.title2 : '')]),
      el('div', { style: 'font-size:13px;opacity:.9;margin-top:3px;' }, [schema.confidential || '']),
    ]));
    wrap.appendChild(el('a', { href: '#inspection-forms', style: 'display:inline-block;font-size:13px;color:#1F6080;text-decoration:none;margin:0 0 12px;' }, ['← Back to forms']));

    if (isEditSubmitted) {
      wrap.appendChild(el('div', { style: 'background:#FEF9E7;border:1px solid #F6D97A;border-radius:10px;padding:11px 14px;margin-bottom:12px;font-size:13px;color:#92600A;' },
        ['You are editing a submitted form. Your changes are tracked — the original values and who changed them are kept in the audit trail.']));
    }

    var card = el('div', { style: 'background:#fff;border:1px solid #E7EDF3;border-radius:16px;padding:18px 18px 22px;' });
    var hdr = el('div', { style: 'display:flex;flex-wrap:wrap;gap:12px;padding:12px;background:#F8FAFC;border:1px solid #E2E8F0;border-radius:10px;margin-bottom:14px;' });
    var centreSel = el('select', { id: 'kt-hcc-centre', style: IN }, []);
    centreSel.appendChild(el('option', { value: '' }, ['— Select centre (optional) —']));
    centres.forEach(function (c) { centreSel.appendChild(el('option', { value: String(c.id) }, [c.name + (c.city ? ' · ' + c.city : '')])); });
    hdr.appendChild(el('div', { style: 'flex:1 1 calc(50% - 6px);min-width:180px;' }, [
      el('label', { style: 'display:block;font-size:12px;font-weight:700;color:#334155;margin-bottom:3px;' }, ['Centre']), centreSel,
    ]));
    var dateInp = el('input', { id: 'kt-hcc-visitdate', type: 'date', style: IN });
    dateInp.value = existing ? (String(existing.form.visit_date || '').slice(0, 10) || today()) : today();
    hdr.appendChild(el('div', { style: 'flex:1 1 calc(50% - 6px);min-width:180px;' }, [
      el('label', { style: 'display:block;font-size:12px;font-weight:700;color:#334155;margin-bottom:3px;' }, ['Date of visit *']), dateInp,
    ]));
    card.appendChild(hdr);

    var brand = schema.brand;
    schema.sections.forEach(function (sec) {
      var bar = sectionBar(sec, brand);
      if (bar) card.appendChild(bar);
      sec.blocks.forEach(function (b) { card.appendChild(buildBlock(b)); });
    });

    // Optional note (audit) when editing a submitted form
    var noteInp = null;
    if (isEditSubmitted) {
      card.appendChild(el('div', { style: 'font-size:13px;font-weight:700;color:#334155;margin:14px 0 4px;' }, ['Reason for edit (saved to the audit trail)']));
      noteInp = el('textarea', { id: 'kt-hcc-note', rows: '2', placeholder: 'e.g. Corrected the provider name and a checkbox.', style: IN + 'resize:vertical;' });
      card.appendChild(noteInp);
    }

    var msg = el('div', { style: 'font-size:13px;margin:14px 0 6px;min-height:18px;' });
    var actions = el('div', { style: 'display:flex;flex-wrap:wrap;gap:10px;justify-content:flex-end;margin-top:8px;' });
    var draftBtn = null;
    if (!isEditSubmitted) {
      draftBtn = el('button', { style: 'padding:11px 20px;border:1px solid #CBD5E1;border-radius:10px;background:#fff;color:#334155;font-size:14px;font-weight:700;cursor:pointer;' }, ['Save as draft']);
      actions.appendChild(draftBtn);
    }
    var submitBtn = el('button', { style: 'padding:11px 22px;border:none;border-radius:10px;background:#1F6080;color:#fff;font-size:14px;font-weight:700;cursor:pointer;' },
      [isEditSubmitted ? 'Save changes' : 'Submit form']);
    actions.appendChild(submitBtn);
    card.appendChild(msg); card.appendChild(actions);
    wrap.appendChild(card);
    container.appendChild(wrap);

    // Prefill from existing answers/centre
    if (existing) {
      if (existing.form.centre_id) centreSel.value = String(existing.form.centre_id);
      applyAnswers(schema, existing.answers || {});
    }

    function payloadFrom() {
      var answers = collectAnswers(schema);
      return {
        answers: answers,
        provider: (answers.provider_name || '').trim(),
        visitDate: dateInp.value,
        base: {
          form_type: type,
          centre_id: centreSel.value || null,
          visit_time_in: answers.time_in || answers.start_time_1 || null,
          visit_time_out: answers.time_out || answers.end_time_1 || null,
          quarter: answers.quarter || null,
        },
      };
    }

    async function save(status, btn) {
      var p = payloadFrom();
      // A draft can be saved with minimal data; a submit needs provider + date.
      if (status === 'submitted') {
        if (!p.provider) { msg.style.color = '#DC2626'; msg.textContent = 'Please enter the provider name in the form before submitting.'; return; }
        if (!p.visitDate) { msg.style.color = '#DC2626'; msg.textContent = 'Please set the date of visit.'; return; }
      }
      var body = Object.assign({}, p.base, {
        provider_name: p.provider || 'Draft',
        visit_date: p.visitDate || today(),
        status: status,
        answers: p.answers,
      });
      if (noteInp) body.note = noteInp.value || null;
      var oldTxt = btn.textContent; btn.disabled = true; if (draftBtn) draftBtn.disabled = true;
      btn.textContent = status === 'draft' ? 'Saving…' : (isEditSubmitted ? 'Saving…' : 'Submitting…');
      msg.style.color = '#64748B'; msg.textContent = '';
      try {
        if (existing) await Api.patch('/inspection-forms/' + existing.form.id, body);
        else await Api.post('/inspection-forms', body);
        if (KT.toast) {
          if (status === 'draft') KT.toast('💾', 'Draft saved', 'Come back any time to finish.', '#0891A6');
          else KT.toast('✅', isEditSubmitted ? 'Changes saved' : 'Form submitted', isEditSubmitted ? 'Audit trail updated.' : 'Sent to your director/admin.', '#059669');
        }
        location.hash = '#inspection-forms';
      } catch (e) {
        btn.disabled = false; if (draftBtn) draftBtn.disabled = false; btn.textContent = oldTxt;
        msg.style.color = '#DC2626'; msg.textContent = 'Could not save: ' + (e.message || 'error');
      }
    }
    submitBtn.addEventListener('click', function () { save('submitted', submitBtn); });
    if (draftBtn) draftBtn.addEventListener('click', function () { save('draft', draftBtn); });
  }

  // ── Home-visitor picker: choose a form + see own submissions ─────────
  async function renderPicker(container) {
    container.innerHTML = '<div style="padding:24px;color:#64748B;">Loading…</div>';
    var forms = [], mine = [], enabled = true;
    try { var s = await Api.get('/inspection-forms/schemas'); forms = (s && s.forms) || []; enabled = s ? s.enabled !== false : true; } catch (e) {}
    try { var m = await Api.get('/inspection-forms'); mine = (m && m.forms) || []; } catch (e) {}

    clear(container);
    var wrap = el('div', { style: 'max-width:840px;margin:0 auto;padding:16px 14px 100px;' });
    wrap.appendChild(el('div', { class: 'kt-hero', style: 'background:linear-gradient(135deg,#1F6080,#159FB4);color:#fff;border-radius:16px;padding:18px 22px;margin-bottom:16px;' }, [
      el('div', { style: 'font-size:19px;font-weight:800;' }, ['Inspection forms']),
      el('div', { style: 'font-size:13px;opacity:.9;margin-top:3px;' }, ['Complete a monitoring or quarterly inspection form. Save a draft to finish later; submitted forms go to your director/admin.']),
    ]));

    if (!enabled || !forms.length) {
      wrap.appendChild(el('div', { style: 'padding:26px;text-align:center;color:#64748B;background:#F8FAFC;border:1px dashed #CBD5E1;border-radius:12px;' },
        ['Inspection forms aren’t enabled for this agency.']));
      container.appendChild(wrap);
      return;
    }

    var meta = { monthly_monitoring: { icon: '📋', c: '#0FA3B1', sub: 'Monthly monitoring & inspection of a provider' }, quarterly_checklist: { icon: '✅', c: '#7C3AED', sub: 'Quarterly Ministry checklist (O. Reg. 137/15)' } };
    var grid = el('div', { style: 'display:grid;grid-template-columns:repeat(auto-fit,minmax(240px,1fr));gap:14px;margin-bottom:22px;' });
    forms.forEach(function (f) {
      var mm = meta[f.key] || { icon: '📝', c: '#1F6080', sub: '' };
      grid.appendChild(el('a', { href: '#inspection-form?type=' + f.key, style: 'display:block;text-decoration:none;background:#fff;border:1px solid #E7EDF3;border-radius:16px;padding:18px;box-shadow:0 1px 3px rgba(15,23,42,.05);' }, [
        el('div', { style: 'width:46px;height:46px;border-radius:12px;display:flex;align-items:center;justify-content:center;font-size:22px;background:' + mm.c + '1a;margin-bottom:10px;' }, [mm.icon]),
        el('div', { style: 'font-size:15px;font-weight:800;color:#0f172a;line-height:1.25;' }, [f.label]),
        el('div', { style: 'font-size:12.5px;color:#64748b;margin-top:5px;' }, [mm.sub]),
        el('div', { style: 'font-size:13px;font-weight:700;color:' + mm.c + ';margin-top:12px;' }, ['Start form →']),
      ]));
    });
    wrap.appendChild(grid);

    var drafts = mine.filter(function (r) { return r.status === 'draft'; });
    var done = mine.filter(function (r) { return r.status !== 'draft'; });
    if (drafts.length) {
      wrap.appendChild(el('h3', { style: 'font-size:15px;color:#0f172a;margin:6px 0 10px;' }, ['Drafts — continue where you left off (' + drafts.length + ')']));
      wrap.appendChild(submissionsTable(drafts, false));
    }
    wrap.appendChild(el('h3', { style: 'font-size:15px;color:#0f172a;margin:20px 0 10px;' }, ['Your submitted forms (' + done.length + ')']));
    wrap.appendChild(submissionsTable(done, false));
    if (done.length) wrap.appendChild(el('div', { style: 'font-size:12px;color:#64748B;margin:8px 2px 0;font-weight:600;' }, ['Showing ' + done.length + ' submitted form' + (done.length === 1 ? '' : 's')]));
    container.appendChild(wrap);
    wireTable(container);
  }

  // ── Reviewer list (agency admins / directors): detailed table ────────
  var SORT_ACCESSORS = {
    form_type: function (r) { return r.form_type || ''; },
    status: function (r) { return (r.status === 'draft' ? '0' : (r.edited ? '1' : '2')); },
    provider_name: function (r) { return (r.provider_name || '').toLowerCase(); },
    centre_name: function (r) { return (r.centre_name || '').toLowerCase(); },
    visit_date: function (r) { return r.visit_date || ''; },
    home_visitor: function (r) { return (r.home_visitor || '').toLowerCase(); },
    quarter: function (r) { return r.quarter || ''; },
  };

  async function renderList(container) {
    container.innerHTML = '<div style="padding:24px;color:#64748B;">Loading home-visitor forms…</div>';
    var all = [], enabled = true;
    try { var d = await Api.get('/inspection-forms'); all = (d && d.forms) || []; enabled = d ? d.enabled !== false : true; }
    catch (e) { container.innerHTML = '<div style="padding:24px;color:#DC2626;">Could not load: ' + esc(e.message) + '</div>'; return; }
    clear(container);
    var wrap = el('div', { style: 'max-width:1160px;margin:0 auto;padding:6px 16px 90px;' });
    if (!enabled) { wrap.appendChild(el('div', { style: 'padding:26px;text-align:center;color:#64748B;background:#F8FAFC;border:1px dashed #CBD5E1;border-radius:12px;' }, ['Home-visitor forms aren’t enabled for this agency.'])); container.appendChild(wrap); return; }

    var isDesktop = window.matchMedia && window.matchMedia('(min-width:701px)').matches;
    var state = { q: '', type: '', status: '', sortKey: 'visit_date', sortDir: 'desc' };

    // Desktop toolbar: search + type + status filters
    if (isDesktop) {
      var bar = el('div', { style: 'display:flex;flex-wrap:wrap;gap:10px;align-items:center;margin-bottom:12px;' });
      var search = el('input', { type: 'search', placeholder: '🔍 Filter by provider, centre or home visitor…', style: 'flex:1 1 260px;min-width:200px;padding:8px 11px;border:1px solid #CBD5E1;border-radius:9px;font-size:13px;' });
      var typeSel = el('select', { style: 'padding:8px 11px;border:1px solid #CBD5E1;border-radius:9px;font-size:13px;background:#fff;' }, [
        el('option', { value: '' }, ['All forms']), el('option', { value: 'monthly_monitoring' }, ['Monthly']), el('option', { value: 'quarterly_checklist' }, ['Quarterly']),
      ]);
      var statusSel = el('select', { style: 'padding:8px 11px;border:1px solid #CBD5E1;border-radius:9px;font-size:13px;background:#fff;' }, [
        el('option', { value: '' }, ['All statuses']), el('option', { value: 'draft' }, ['Draft']), el('option', { value: 'submitted' }, ['Submitted']), el('option', { value: 'edited' }, ['Edited']),
      ]);
      search.addEventListener('input', function () { state.q = search.value.toLowerCase(); rerender(); });
      typeSel.addEventListener('change', function () { state.type = typeSel.value; rerender(); });
      statusSel.addEventListener('change', function () { state.status = statusSel.value; rerender(); });
      bar.appendChild(search); bar.appendChild(typeSel); bar.appendChild(statusSel);
      wrap.appendChild(bar);
    }

    var tableHost = el('div', {});
    var statusBar = el('div', { style: 'margin-top:10px;padding:9px 14px;background:#F1F5F9;border:1px solid #E2E8F0;border-radius:10px;font-size:12.5px;color:#475569;display:flex;flex-wrap:wrap;gap:6px 18px;align-items:center;' });
    wrap.appendChild(tableHost);
    wrap.appendChild(statusBar);
    container.appendChild(wrap);

    function filtered() {
      var out = all.filter(function (r) {
        if (state.type && r.form_type !== state.type) return false;
        if (state.status === 'draft' && r.status !== 'draft') return false;
        if (state.status === 'submitted' && (r.status !== 'submitted' || r.edited)) return false;
        if (state.status === 'edited' && !r.edited) return false;
        if (state.q) { var hay = ((r.provider_name || '') + ' ' + (r.centre_name || '') + ' ' + (r.home_visitor || '') + ' ' + (r.form_label || '')).toLowerCase(); if (hay.indexOf(state.q) === -1) return false; }
        return true;
      });
      var acc = SORT_ACCESSORS[state.sortKey] || SORT_ACCESSORS.visit_date;
      out.sort(function (a, b) { var x = acc(a), y = acc(b); return (x < y ? -1 : x > y ? 1 : 0) * (state.sortDir === 'asc' ? 1 : -1); });
      return out;
    }
    function rerender() {
      var rows = filtered();
      clear(tableHost);
      tableHost.appendChild(submissionsTable(rows, true, { sortKey: state.sortKey, sortDir: state.sortDir, onSort: function (k) {
        if (state.sortKey === k) state.sortDir = state.sortDir === 'asc' ? 'desc' : 'asc'; else { state.sortKey = k; state.sortDir = 'asc'; }
        rerender();
      } }));
      var dr = all.filter(function (r) { return r.status === 'draft'; }).length;
      var ed = all.filter(function (r) { return r.edited; }).length;
      var sub = all.length - dr;
      clear(statusBar);
      statusBar.appendChild(el('span', {}, ['Showing ', el('strong', {}, [String(rows.length)]), ' of ' + all.length + ' form' + (all.length === 1 ? '' : 's')]));
      statusBar.appendChild(el('span', {}, ['📤 ' + sub + ' submitted']));
      statusBar.appendChild(el('span', {}, ['✎ ' + ed + ' edited']));
      statusBar.appendChild(el('span', {}, ['📝 ' + dr + ' draft' + (dr === 1 ? '' : 's')]));
      wireTable(tableHost);
    }
    rerender();
  }

  function statusBadge(r) {
    if (r.status === 'draft') return el('span', { style: 'display:inline-block;font-size:10.5px;font-weight:700;color:#92600A;background:#FEF3C7;border-radius:20px;padding:2px 8px;' }, ['Draft']);
    if (r.edited) return el('span', { style: 'display:inline-block;font-size:10.5px;font-weight:700;color:#1D4ED8;background:#DBEAFE;border-radius:20px;padding:2px 8px;', title: (r.edit_count || 1) + ' edit(s)' }, ['Edited']);
    return el('span', { style: 'display:inline-block;font-size:10.5px;font-weight:700;color:#047857;background:#D1FAE5;border-radius:20px;padding:2px 8px;' }, ['Submitted']);
  }

  function submissionsTable(rows, showVisitor, sort) {
    if (!rows.length) return el('div', { style: 'padding:26px;text-align:center;color:#64748B;background:#F8FAFC;border:1px dashed #CBD5E1;border-radius:12px;' }, ['No forms match.']);
    // Wrap for horizontal scroll on narrow (mobile/APK) screens.
    var scroller = el('div', { style: 'overflow-x:auto;-webkit-overflow-scrolling:touch;border:1px solid #E7EDF3;border-radius:12px;background:#fff;' });
    var t = el('table', { class: 'kt-table', style: 'width:100%;min-width:640px;border-collapse:collapse;font-size:13px;' });
    var heads = [['Form', 'form_type'], ['Status', 'status'], ['Provider visited', 'provider_name'], ['Centre', 'centre_name'], ['Date', 'visit_date'], ['Time', null]];
    if (showVisitor) heads.push(['Home visitor', 'home_visitor']);
    heads.push(['Quarter', 'quarter']); heads.push(['', null]);
    var hr = el('tr', { style: 'background:#F1F5F9;' });
    heads.forEach(function (h) {
      var label = h[0], key = h[1];
      var sortable = sort && sort.onSort && key;
      var arrow = (sortable && sort.sortKey === key) ? (sort.sortDir === 'asc' ? ' ▲' : ' ▼') : '';
      var th = el('th', { style: 'text-align:left;padding:10px 12px;font-size:11px;color:#64748B;text-transform:uppercase;letter-spacing:.4px;white-space:nowrap;' + (sortable ? 'cursor:pointer;user-select:none;' : '') }, [label + arrow]);
      if (sortable) th.addEventListener('click', function () { sort.onSort(key); });
      hr.appendChild(th);
    });
    t.appendChild(hr);
    rows.forEach(function (r) {
      var tint = r.form_type === 'quarterly_checklist' ? '#7C3AED' : '#0FA3B1';
      var isDraft = r.status === 'draft';
      var tr = el('tr', { style: 'border-top:1px solid #EEF2F6;' });
      tr.appendChild(el('td', { style: 'padding:10px 12px;' }, [
        el('span', { style: 'display:inline-block;font-size:11px;font-weight:700;color:#fff;background:' + tint + ';border-radius:20px;padding:3px 9px;' }, [r.form_type === 'quarterly_checklist' ? 'Quarterly' : 'Monthly']),
        el('div', { style: 'font-size:12px;color:#334155;margin-top:4px;max-width:210px;' }, [r.form_label]),
      ]));
      tr.appendChild(el('td', { style: 'padding:10px 12px;' }, [statusBadge(r)]));
      tr.appendChild(el('td', { style: 'padding:10px 12px;font-weight:700;color:#0f172a;' }, [r.provider_name || '—']));
      tr.appendChild(el('td', { style: 'padding:10px 12px;color:#475569;' }, [r.centre_name || '—']));
      tr.appendChild(el('td', { style: 'padding:10px 12px;white-space:nowrap;color:#475569;' }, [fmtDate(r.visit_date)]));
      tr.appendChild(el('td', { style: 'padding:10px 12px;white-space:nowrap;color:#475569;' }, [(r.visit_time_in || '') + (r.visit_time_out ? ' – ' + r.visit_time_out : '') || '—']));
      if (showVisitor) tr.appendChild(el('td', { style: 'padding:10px 12px;color:#475569;' }, [r.home_visitor || '—']));
      tr.appendChild(el('td', { style: 'padding:10px 12px;color:#475569;' }, [r.quarter ? 'Q' + r.quarter : '—']));
      var act = el('td', { style: 'padding:10px 12px;white-space:nowrap;text-align:right;' });
      if (isDraft) {
        act.appendChild(el('a', { href: '#inspection-form?id=' + r.id, class: 'kt-act-icon kt-act-teal kt-icon-tip', title: 'Continue draft', 'data-kttip': 'Continue draft', 'aria-label': 'Continue draft' }, ['✏️']));
      } else {
        act.appendChild(el('a', { href: '#hcc-form-view?id=' + r.id, class: 'kt-act-icon kt-act-info kt-icon-tip', title: 'View', 'data-kttip': 'View', 'aria-label': 'View' }, ['ℹ️']));
        act.appendChild(el('a', { href: '#inspection-form?id=' + r.id, class: 'kt-act-icon kt-act-edit kt-icon-tip', title: 'Edit / correct', 'data-kttip': 'Edit / correct', 'aria-label': 'Edit / correct' }, ['✏️']));
        act.appendChild(el('button', { 'data-id': String(r.id), 'data-type': r.form_type, 'data-date': r.visit_date, class: 'kt-hcc-pdf kt-act-icon kt-act-teal kt-icon-tip', title: 'Download PDF', 'data-kttip': 'Download PDF', 'aria-label': 'Download PDF' }, ['📄']));
      }
      tr.appendChild(act);
      t.appendChild(tr);
    });
    scroller.appendChild(t);
    return scroller;
  }

  function wireTable(container) {
    container.querySelectorAll('.kt-hcc-pdf').forEach(function (b) {
      b.addEventListener('click', function () {
        var id = b.getAttribute('data-id');
        blobDownload('/inspection-forms/' + id + '/pdf', b.getAttribute('data-type') + '-' + b.getAttribute('data-date') + '-' + id + '.pdf', b);
      });
    });
  }

  // ── Read-only detail (server-rendered) + PDF ─────────────────────────
  async function renderDetail(container) {
    var id = qp('id');
    container.innerHTML = '<div style="padding:24px;color:#64748B;">Loading…</div>';
    var d = {};
    try { d = await Api.get('/inspection-forms/' + id); } catch (e) {}
    var form = d.form || { form_type: 'form', form_label: 'Inspection form' };
    clear(container);
    var back = location.hash.indexOf('inspection') !== -1 ? '#inspection-forms' : '#hcc-forms';
    var wrap = el('div', { style: 'max-width:1000px;margin:0 auto;padding:16px 14px 90px;' });
    // Own kt-hero so the shell doesn't add a blank auto-banner (this hash isn't in the nav).
    wrap.appendChild(el('div', { class: 'kt-hero', style: 'background:linear-gradient(135deg,#1F6080,#159FB4);color:#fff;border-radius:14px;padding:14px 20px;margin-bottom:12px;' }, [
      el('div', { style: 'font-size:12px;opacity:.85;letter-spacing:.5px;' }, ['INSPECTION FORM']),
      el('div', { style: 'font-size:17px;font-weight:800;' }, [form.form_label + (form.provider_name ? ' — ' + form.provider_name : '')]),
    ]));
    var bar = el('div', { style: 'display:flex;align-items:center;justify-content:space-between;gap:10px;margin-bottom:12px;flex-wrap:wrap;' });
    bar.appendChild(el('a', { href: back, style: 'font-size:13px;color:#1F6080;text-decoration:none;' }, ['← Back']));
    var btns = el('div', { style: 'display:flex;gap:8px;flex-wrap:wrap;' });
    if (form.editable && form.status !== 'draft') {
      btns.appendChild(el('a', { href: '#inspection-form?id=' + id, style: 'padding:8px 16px;border:1px solid #7C3AED;border-radius:9px;background:#fff;color:#7C3AED;font-size:13px;font-weight:700;text-decoration:none;' }, ['✎ Edit / correct']));
    }
    var pdfBtn = el('button', { style: 'padding:8px 16px;border:none;border-radius:9px;background:#0891A6;color:#fff;font-size:13px;font-weight:700;cursor:pointer;' }, ['⬇ Download PDF']);
    pdfBtn.addEventListener('click', function () { blobDownload('/inspection-forms/' + id + '/pdf', (form.form_type || 'form') + '-' + id + '.pdf', pdfBtn); });
    btns.appendChild(pdfBtn);
    bar.appendChild(btns);
    wrap.appendChild(bar);

    // Audit trail (old → new) for edits made after submission.
    var history = (d && d.history) || [];
    if (history.length) {
      var panel = el('div', { style: 'background:#fff;border:1px solid #E7EDF3;border-radius:12px;padding:14px 16px;margin-bottom:12px;' });
      panel.appendChild(el('div', { style: 'font-size:14px;font-weight:800;color:#0f172a;margin-bottom:8px;' }, ['🕓 Edit history (' + history.length + ')']));
      history.slice().reverse().forEach(function (h) {
        var when = h.at ? new Date(h.at).toLocaleString() : '';
        var entry = el('div', { style: 'border-top:1px solid #EEF2F6;padding:9px 0;' });
        entry.appendChild(el('div', { style: 'font-size:12.5px;color:#334155;' }, [
          el('strong', {}, [h.by || 'Someone']), ' · ' + when + (h.note ? ' · “' + h.note + '”' : ''),
        ]));
        (h.changes || []).forEach(function (c) {
          entry.appendChild(el('div', { style: 'font-size:12px;color:#475569;margin-top:3px;padding-left:8px;' }, [
            el('span', { style: 'font-weight:700;color:#334155;' }, [c.field + ': ']),
            el('span', { style: 'color:#B91C1C;text-decoration:line-through;' }, [String(c.from || '—')]),
            ' → ',
            el('span', { style: 'color:#047857;font-weight:700;' }, [String(c.to || '—')]),
          ]));
        });
        if (!(h.changes || []).length) entry.appendChild(el('div', { style: 'font-size:12px;color:#64748B;margin-top:3px;padding-left:8px;' }, ['(note only — no field changes)']));
        panel.appendChild(entry);
      });
      wrap.appendChild(panel);
    }

    // Embed the real filled PDF (auth fetch → blob → iframe).
    var frame = el('iframe', { style: 'width:100%;height:82vh;border:1px solid #E7EDF3;border-radius:12px;background:#fff;' });
    wrap.appendChild(frame);
    container.appendChild(wrap);
    var h = { Authorization: 'Bearer ' + tok() };
    if (activeAgency()) h['X-Active-Agency-Id'] = activeAgency();
    fetch(apiBase() + '/inspection-forms/' + id + '/pdf', { headers: h })
      .then(function (r) { return r.blob(); })
      .then(function (b) { frame.src = URL.createObjectURL(b); })
      .catch(function () { frame.outerHTML = '<div style="padding:24px;color:#DC2626;">Could not load the PDF.</div>'; });
  }

  // ── Registrations ────────────────────────────────────────────────────
  KT.Shell.registerScreen('home_visitor:inspection-forms', renderPicker);
  KT.Shell.registerScreen('home_visitor:inspection-form', renderFillable);
  ['home_visitor', 'agency_admin', 'centre_director', 'platform_admin'].forEach(function (role) {
    KT.Shell.registerScreen(role + ':hcc-form-view', renderDetail);
  });
  ['agency_admin', 'centre_director', 'platform_admin'].forEach(function (role) {
    KT.Shell.registerScreen(role + ':hcc-forms', renderList);
  });
})(window);
