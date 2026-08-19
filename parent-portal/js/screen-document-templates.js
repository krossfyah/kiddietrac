/* ═══════════════════════════════════════════════════════════════════
   KIDDIETRAC — Document templates (payslips, invoices)

   The store, renderer and Blade importer already existed; this is the front door.
   Follows the house table pattern: data-kt-pretty on the container, a plain <table>
   inside .kt-card, and no inline widths — so it picks up sorting, filtering, the export
   bar and the row count from the shared modules like every other table.
   ═══════════════════════════════════════════════════════════════════ */
(function (window) {
  'use strict';
  var KT = window.KT;
  if (!KT || !KT.Shell || !KT.Shell.registerScreen) { return; }
  var Api = KT.Api;

  function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"]/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c];
    });
  }

  function fmtStamp(ts) {
    if (!ts) { return '—'; }
    try {
      var tz = (KT.agencyTz && KT.agencyTz()) || undefined;
      var s = String(ts).replace(' ', 'T');
      if (!/[Zz]|[+-]\d\d:?\d\d$/.test(s)) { s += 'Z'; }
      return new Date(s).toLocaleString('en-CA',
        { month: 'short', day: 'numeric', year: 'numeric', hour: 'numeric', minute: '2-digit', timeZone: tz });
    } catch (e) { return String(ts).slice(0, 16); }
  }

  var state = { kinds: [], rows: [] };

  async function render(main) {
    main.setAttribute('data-kt-pretty', '1');
    main.innerHTML = '<div style="padding:24px;max-width:1800px;margin:0 auto;">'
      + '<div class="kt-page-hero"><h2>📄 Document templates</h2>'
      + '<p>How payslips and invoices are laid out when they are printed or emailed.</p>'
      + '<div class="kt-hero-actions">'
      + '<button class="kt-btn kt-btn-ghost" id="dt-import">⤵ Import a template</button></div></div>'
      + '<div id="dt-body">Loading…</div></div>';

    main.querySelector('#dt-import').onclick = function () { openImport(main); };
    await load(main);
  }

  async function load(main) {
    var host = main.querySelector('#dt-body');
    var res;
    try {
      res = await Api.get('/provider/document-templates');
    } catch (e) {
      host.innerHTML = '<div class="kt-card" style="color:#B91C1C;">Could not load templates: ' + esc(e.message || 'error') + '</div>';
      return;
    }
    state.kinds = res.kinds || [];
    state.rows = res.data || [];

    if (!state.rows.length) {
      host.innerHTML = '<div class="kt-card" style="color:#64748B;padding:22px;">'
        + '<strong>No templates yet.</strong><div style="margin-top:4px;">'
        + 'Payslips and invoices use the built-in layout until you add one. '
        + 'Import an existing template to start from something you already use.</div></div>';
      return;
    }

    host.innerHTML = '<div class="kt-card"><table><thead><tr>'
      + '<th>Name</th><th>Document</th><th>Scope</th><th>Source</th><th>Status</th><th>Updated</th><th></th>'
      + '</tr></thead><tbody>'
      + state.rows.map(function (r) {
          var kind = (state.kinds.filter(function (k) { return k.key === r.kind; })[0] || {}).label || r.kind;
          // A platform default is shared; an agency copy belongs to this agency alone.
          var scope = r.agency_id ? 'This agency' : 'Platform default';
          var status = r.is_active
            ? '<span class="kt-pill kt-pill-ok">In use</span>'
            : '<span class="kt-pill">Not in use</span>';
          var acts = (r.is_active ? '' :
              '<button class="kt-act-icon kt-icon-tip" data-dt-use="' + r.id + '" title="Use this one" data-kttip="Use this one">✅</button>')
            + '<button class="kt-act-icon kt-icon-tip" data-dt-view="' + r.id + '" title="Preview" data-kttip="Preview">👁</button>'
            + '<button class="kt-act-icon kt-icon-tip" data-dt-edit="' + r.id + '" title="Edit" data-kttip="Edit">✏️</button>'
            + (r.is_active || !r.agency_id ? '' :
              '<button class="kt-act-icon kt-act-danger kt-icon-tip" data-dt-del="' + r.id + '" title="Delete" data-kttip="Delete">🗑️</button>');
          return '<tr>'
            + '<td><strong>' + esc(r.name) + '</strong>'
            + (r.import_notes ? '<div style="font-size:11.5px;color:#B45309;">imported with notes</div>' : '')
            + '</td>'
            + '<td>' + esc(kind) + '</td><td>' + esc(scope) + '</td>'
            + '<td>' + esc(r.source || '—') + '</td><td>' + status + '</td>'
            + '<td>' + esc(fmtStamp(r.updated_at)) + '</td>'
            + '<td>' + acts + '</td></tr>';
        }).join('')
      + '</tbody></table></div>';

    wire(main);
  }

  function wire(main) {
    function on(sel, fn, confirmMsg) {
      main.querySelectorAll(sel).forEach(function (b) {
        b.onclick = async function () {
          if (confirmMsg && KT.confirm && !(await KT.confirm(confirmMsg))) { return; }
          b.disabled = true;
          try { await fn(b); } catch (e) {
            if (KT.Dom && KT.Dom.toast) { KT.Dom.toast(e.message || 'That did not work', 'error'); }
            b.disabled = false;
          }
        };
      });
    }

    on('[data-dt-use]', async function (b) {
      await Api.post('/provider/document-templates/' + b.getAttribute('data-dt-use') + '/activate', {});
      if (KT.Dom && KT.Dom.toast) { KT.Dom.toast('Now using that template', 'success'); }
      await load(main);
    }, 'Use this template for every document of that kind from now on?');

    on('[data-dt-del]', async function (b) {
      await Api.del('/provider/document-templates/' + b.getAttribute('data-dt-del'));
      await load(main);
    }, 'Delete this template? Documents already issued keep their own record.');

    on('[data-dt-view]', async function (b) {
      var row = rowById(b.getAttribute('data-dt-view'));
      var full = await Api.get('/provider/document-templates/' + row.id);
      openPreview(row.name, full.data.kind, full.data.body);
      b.disabled = false;
    });

    on('[data-dt-edit]', async function (b) {
      var row = rowById(b.getAttribute('data-dt-edit'));
      var full = await Api.get('/provider/document-templates/' + row.id);
      openEditor(main, full.data);
      b.disabled = false;
    });
  }

  function rowById(id) {
    return state.rows.filter(function (r) { return String(r.id) === String(id); })[0];
  }

  function modal(title, innerHtml, width) {
    var m = document.createElement('div');
    m.style.cssText = 'position:fixed;inset:0;background:rgba(15,23,42,.55);z-index:99999;display:flex;align-items:center;justify-content:center;padding:20px;';
    m.innerHTML = '<div style="background:#fff;border-radius:14px;max-width:' + (width || 720) + 'px;width:100%;max-height:90vh;overflow:auto;padding:24px;">'
      + '<h3 style="margin:0 0 14px;color:#0F172A;">' + esc(title) + '</h3>' + innerHtml + '</div>';
    document.body.appendChild(m);
    m.addEventListener('click', function (e) { if (e.target === m) { m.remove(); } });
    return m;
  }

  async function openPreview(name, kind, body) {
    var m = modal('Preview — ' + name, '<div id="dt-prev" style="border:1px solid #E2E8F0;border-radius:10px;padding:18px;background:#fff;">Rendering…</div>'
      + '<div style="text-align:right;margin-top:14px;"><button id="dt-prev-close" class="kt-btn kt-btn-ghost">Close</button></div>', 820);
    m.querySelector('#dt-prev-close').onclick = function () { m.remove(); };
    try {
      var r = await Api.post('/provider/document-templates/preview', { kind: kind, body: body });
      // Sample values, so the layout can be judged without hunting for a real payslip.
      m.querySelector('#dt-prev').innerHTML = r.html || '<em>Nothing rendered.</em>';
    } catch (e) {
      m.querySelector('#dt-prev').innerHTML = '<span style="color:#B91C1C;">Could not render: ' + esc(e.message || 'error') + '</span>';
    }
  }

  function openEditor(main, tpl) {
    var vars = ((state.kinds.filter(function (k) { return k.key === tpl.kind; })[0] || {}).vars) || [];
    var m = modal('Edit — ' + tpl.name,
      '<label style="display:block;font-size:13px;font-weight:700;color:#475569;margin:0 0 4px;">Name</label>'
      + '<input id="dt-name" value="' + esc(tpl.name) + '" style="width:100%;padding:9px 11px;border:1px solid #D6DEE7;border-radius:9px;font-size:14px;box-sizing:border-box;">'
      + '<label style="display:block;font-size:13px;font-weight:700;color:#475569;margin:14px 0 4px;">Layout</label>'
      + '<textarea id="dt-body-edit" rows="16" spellcheck="false" style="width:100%;font-family:ui-monospace,Menlo,Consolas,monospace;font-size:12.5px;padding:11px;border:1px solid #D6DEE7;border-radius:9px;box-sizing:border-box;">' + esc(tpl.body) + '</textarea>'
      + '<div style="font-size:12px;color:#64748B;margin-top:8px;">Fields you can use — click to insert:</div>'
      + '<div id="dt-vars" style="display:flex;flex-wrap:wrap;gap:5px;margin-top:6px;">'
      + vars.map(function (v) {
          return '<button type="button" data-var="' + v + '" style="border:1px solid #E2E8F0;background:#F8FAFC;border-radius:999px;padding:3px 9px;font-size:11.5px;font-family:ui-monospace,monospace;cursor:pointer;">' + esc(v) + '</button>';
        }).join('')
      + '</div>'
      + (tpl.import_notes ? '<div style="background:#FFF7ED;border:1px solid #FED7AA;color:#9A3412;border-radius:10px;padding:11px 13px;font-size:12.5px;margin-top:12px;white-space:pre-wrap;">' + esc(tpl.import_notes) + '</div>' : '')
      + '<div style="display:flex;gap:8px;justify-content:flex-end;margin-top:16px;">'
      + '<button id="dt-cancel" class="kt-btn kt-btn-ghost">Cancel</button>'
      + '<button id="dt-preview" class="kt-btn kt-btn-ghost">Preview</button>'
      + '<button id="dt-save" class="kt-btn kt-btn-primary">Save</button></div>', 880);

    var ta = m.querySelector('#dt-body-edit');
    m.querySelectorAll('[data-var]').forEach(function (b) {
      b.onclick = function () {
        // Inserted at the cursor, so a field can be dropped into the right cell.
        var tag = '{{ ' + b.getAttribute('data-var') + ' }}';
        var s = ta.selectionStart || 0;
        ta.value = ta.value.slice(0, s) + tag + ta.value.slice(ta.selectionEnd || s);
        ta.focus();
        ta.selectionStart = ta.selectionEnd = s + tag.length;
      };
    });
    m.querySelector('#dt-cancel').onclick = function () { m.remove(); };
    m.querySelector('#dt-preview').onclick = function () { openPreview(tpl.name, tpl.kind, ta.value); };
    m.querySelector('#dt-save').onclick = async function () {
      var btn = m.querySelector('#dt-save');
      btn.disabled = true; btn.textContent = 'Saving…';
      try {
        await Api.post('/provider/document-templates', {
          id: tpl.id, kind: tpl.kind, name: m.querySelector('#dt-name').value.trim() || tpl.name,
          body: ta.value,
        });
        m.remove();
        await load(main);
      } catch (e) {
        btn.disabled = false; btn.textContent = 'Save';
        if (KT.Dom && KT.Dom.toast) { KT.Dom.toast(e.message || 'Could not save', 'error'); }
      }
    };
  }

  function openImport(main) {
    var kindOpts = (state.kinds.length ? state.kinds : [{ key: 'payslip', label: 'Payslip' }])
      .map(function (k) { return '<option value="' + k.key + '">' + esc(k.label) + '</option>'; }).join('');

    var m = modal('Import a template',
      '<div style="font-size:13px;color:#64748B;margin:0 0 12px;line-height:1.55;">'
      + 'Paste an existing template — a Blade file, or plain HTML. It is <strong>translated</strong>, never run: '
      + 'anything that cannot be expressed safely is removed and listed for you.</div>'
      + '<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(190px,1fr));gap:12px;align-items:start;">'
      + '<div><label style="display:block;font-size:13px;font-weight:700;color:#475569;margin:0 0 4px;">Document</label>'
      + '<select id="dt-imp-kind" style="width:100%;padding:9px 11px;border:1px solid #D6DEE7;border-radius:9px;font-size:14px;box-sizing:border-box;">' + kindOpts + '</select></div>'
      + '<div><label style="display:block;font-size:13px;font-weight:700;color:#475569;margin:0 0 4px;">Call it</label>'
      + '<input id="dt-imp-name" placeholder="e.g. iLearn payslip" style="width:100%;padding:9px 11px;border:1px solid #D6DEE7;border-radius:9px;font-size:14px;box-sizing:border-box;"></div>'
      + '</div>'
      + '<label style="display:block;font-size:13px;font-weight:700;color:#475569;margin:14px 0 4px;">Template</label>'
      + '<textarea id="dt-imp-body" rows="12" spellcheck="false" placeholder="Paste the template here…" style="width:100%;font-family:ui-monospace,Menlo,Consolas,monospace;font-size:12.5px;padding:11px;border:1px solid #D6DEE7;border-radius:9px;box-sizing:border-box;"></textarea>'
      + '<div id="dt-imp-out" style="margin-top:12px;"></div>'
      + '<div style="display:flex;gap:8px;justify-content:flex-end;margin-top:14px;">'
      + '<button id="dt-imp-cancel" class="kt-btn kt-btn-ghost">Cancel</button>'
      + '<button id="dt-imp-go" class="kt-btn kt-btn-primary">Translate</button></div>', 880);

    m.querySelector('#dt-imp-cancel').onclick = function () { m.remove(); };

    m.querySelector('#dt-imp-go').onclick = async function () {
      var kind = m.querySelector('#dt-imp-kind').value;
      var body = m.querySelector('#dt-imp-body').value;
      var out = m.querySelector('#dt-imp-out');
      if (!body.trim()) { out.innerHTML = '<span style="color:#B91C1C;font-size:13px;">Paste a template first.</span>'; return; }
      out.innerHTML = '<span style="color:#64748B;font-size:13px;">Translating…</span>';
      try {
        var r = await Api.post('/provider/document-templates/import', { kind: kind, body: body });
        var notes = (r.notes || []);
        out.innerHTML = '<div style="background:#ECFDF5;border:1px solid #A7F3D0;color:#065F46;border-radius:10px;padding:11px 13px;font-size:13px;">'
          + '✓ Translated. Nothing executable was carried across.</div>'
          + (notes.length
              ? '<div style="background:#FFF7ED;border:1px solid #FED7AA;color:#9A3412;border-radius:10px;padding:11px 13px;font-size:12.5px;margin-top:8px;">'
                + '<strong>' + notes.length + ' thing' + (notes.length === 1 ? '' : 's') + ' could not be carried over:</strong>'
                + '<ul style="margin:6px 0 0 16px;padding:0;">' + notes.map(function (n) { return '<li>' + esc(n) + '</li>'; }).join('') + '</ul></div>'
              : '')
          + '<div style="display:flex;gap:8px;justify-content:flex-end;margin-top:12px;">'
          + '<button id="dt-imp-prev" class="kt-btn kt-btn-ghost">Preview it</button>'
          + '<button id="dt-imp-save" class="kt-btn kt-btn-primary">Save template</button></div>';

        out.querySelector('#dt-imp-prev').onclick = function () {
          openPreview(m.querySelector('#dt-imp-name').value || 'Imported template', kind, r.body);
        };
        out.querySelector('#dt-imp-save').onclick = async function () {
          try {
            await Api.post('/provider/document-templates', {
              kind: kind,
              name: (m.querySelector('#dt-imp-name').value || 'Imported template').trim(),
              body: r.body,
            });
            m.remove();
            await load(main);
            if (KT.Dom && KT.Dom.toast) { KT.Dom.toast('Template saved. Use it when you are ready.', 'success'); }
          } catch (e) {
            if (KT.Dom && KT.Dom.toast) { KT.Dom.toast(e.message || 'Could not save', 'error'); }
          }
        };
      } catch (e) {
        out.innerHTML = '<span style="color:#B91C1C;font-size:13px;">Could not translate: ' + esc(e.message || 'error') + '</span>';
      }
    };
  }

  KT.DocumentTemplates = { render: render };
  ['agency_admin', 'platform_admin'].forEach(function (r) {
    KT.Shell.registerScreen(r + ':document-templates', render);
  });
})(window);
