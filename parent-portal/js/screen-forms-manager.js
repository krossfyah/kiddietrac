/* ═══════════════════════════════════════════════════════════════════
   Forms Manager (admin) — upload a fillable PDF, assign it to roles, and
   track e-sign completions. Two tabs: Library (upload + manage) and
   Completed (a table of sign-offs with a ⋮ kebab: view / download / email).
   Backend: /admin/managed-forms* + /admin/managed-forms/signoffs.
   ═══════════════════════════════════════════════════════════════════ */
(function () {
  'use strict';
  var KT = window.KT || (window.KT = {});
  var Api = KT.Api;
  function esc(s) { return String(s == null ? '' : s).replace(/[&<>"]/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]; }); }
  var API_HOST = ((KT.API_BASE || 'https://api.kiddietrac.com/api/v1')).replace(/\/api\/v1\/?$/, '');
  var AUD = [['guardian', 'Parents', '👪'], ['educator', 'Educators', '🎓'],
             ['home_visitor', 'Home visitors', '🏡'], ['centre_director', 'Directors', '🏫']];
  var audLabel = function (a) { var m = { guardian: 'Parents', educator: 'Educators', home_visitor: 'Home visitors', centre_director: 'Directors' }; return m[a] || a; };
  function fileUrl(u) { return u ? (/^https?:/.test(u) ? u : API_HOST + u) : ''; }
  function openUrl(u) {
    try { if (window.Capacitor && Capacitor.Plugins && Capacitor.Plugins.Browser) { Capacitor.Plugins.Browser.open({ url: u }); return; } } catch (e) {}
    window.open(u, '_blank');
  }
  function fmtDate(s) { try { return KT.Fmt ? KT.Fmt.date(s) : new Date(String(s).replace(' ', 'T') + 'Z').toLocaleString(); } catch (e) { return s || ''; } }
  function toast(icon, t, m, c) { if (KT.toast) KT.toast(icon, t, m, c || '#16A34A'); }

  function render(container) {
    container.innerHTML =
      '<div style="padding:0 24px 24px;max-width:1080px;margin:0 auto;color:#0F172A;">'
      + '<div style="display:flex;gap:8px;margin:16px 0 16px;">'
      + '<button class="fm-tab" data-t="library" type="button" style="border:1px solid #E2E8F0;border-radius:9px;padding:8px 16px;font-size:13.5px;font-weight:700;cursor:pointer;">📚 Library</button>'
      + '<button class="fm-tab" data-t="completed" type="button" style="border:1px solid #E2E8F0;border-radius:9px;padding:8px 16px;font-size:13.5px;font-weight:700;cursor:pointer;">✅ Completed</button>'
      + '</div><div id="fm-body"></div></div>';
    var body = container.querySelector('#fm-body');
    var tabs = container.querySelectorAll('.fm-tab');
    function activate(t) {
      tabs.forEach(function (b) { var on = b.getAttribute('data-t') === t; b.style.background = on ? '#1F6080' : '#fff'; b.style.color = on ? '#fff' : '#334155'; });
      if (t === 'completed') renderCompleted(body); else renderLibrary(body);
    }
    tabs.forEach(function (b) { b.addEventListener('click', function () { activate(b.getAttribute('data-t')); }); });
    activate('library');
  }

  /* ───────── LIBRARY: upload + list ───────── */
  var FIELD = 'width:100%;padding:10px 12px;border:1.5px solid #E2E8F0;border-radius:10px;font-size:14px;box-sizing:border-box;background:#fff;color:#0F172A;font-family:inherit;';
  var LBL = 'display:block;font-size:12px;font-weight:800;color:#475569;text-transform:uppercase;letter-spacing:.3px;margin:0 0 6px;';
  function renderLibrary(body) {
    // The dialog now lives in <body>, so a re-render cannot dispose of it implicitly.
    var _stale = document.getElementById('fm-upload-ov');
    if (_stale && _stale.parentNode) _stale.parentNode.removeChild(_stale);

    body.innerHTML =
      '<div class="kt-card" style="background:#fff;border:1px solid #E7EBF0;border-radius:16px;padding:22px 24px;margin-bottom:18px;box-shadow:0 1px 4px rgba(15,23,42,.05);">'
      + '<div style="font-weight:800;font-size:15px;margin:0 0 4px;color:#0F172A;">⬆️ Upload a new form</div>'
      + '<div style="font-size:12.5px;color:#94A3B8;margin-bottom:18px;">Add a PDF, choose who signs it, then assign.</div>'
      + '<div style="display:flex;flex-direction:column;gap:16px;">'
      // Title
      + '<div><label for="fm-title" style="' + LBL + '">Form title</label>'
      + '<input id="fm-title" placeholder="e.g. Consent to photograph" style="' + FIELD + '"></div>'
      // Description
      + '<div><label for="fm-desc" style="' + LBL + '">Description</label>'
      + '<textarea id="fm-desc" placeholder="A short note about this form" rows="2" style="' + FIELD + 'resize:vertical;min-height:52px;"></textarea></div>'
      // Audience chips
      + '<div><label style="' + LBL + '">Who must sign it?</label>'
      + '<div style="display:flex;gap:10px;flex-wrap:wrap;">'
      + AUD.map(function (a) {
          return '<label class="fm-audchip" style="display:inline-flex;align-items:center;gap:8px;font-size:13.5px;font-weight:600;color:#334155;cursor:pointer;border:1.5px solid #E2E8F0;border-radius:999px;padding:8px 14px;user-select:none;transition:all .12s;">'
            + '<input type="checkbox" class="fm-aud" value="' + a[0] + '" style="accent-color:#1F6FB2;width:16px;height:16px;margin:0;">'
            + '<span>' + a[2] + ' ' + a[1] + '</span></label>';
        }).join('')
      + '</div></div>'
      // File
      + '<div><label style="' + LBL + '">PDF file</label>'
      + '<label id="fm-drop" for="fm-file" style="display:flex;align-items:center;gap:12px;border:1.5px dashed #CBD5E1;border-radius:10px;padding:14px 16px;cursor:pointer;background:#F8FAFC;transition:all .12s;">'
      + '<span style="font-size:22px;line-height:1;">📄</span>'
      + '<span id="fm-fname" style="font-size:13.5px;color:#64748B;font-weight:600;">Choose a PDF…</span>'
      + '<span style="margin-left:auto;font-size:12px;font-weight:800;color:#1F6FB2;border:1.5px solid #BFDBFE;background:#EFF6FF;border-radius:8px;padding:6px 12px;">Browse</span>'
      + '</label>'
      + '<input id="fm-file" type="file" accept="application/pdf" style="position:absolute;width:1px;height:1px;opacity:0;overflow:hidden;"></div>'
      // Fill-and-sign toggle — PER FORM, not a global behaviour. Only makes sense
      // for a PDF that was authored with real form fields; a read-and-sign notice
      // should stay read-and-sign.
      // Named recipients. Role audiences reach EVERY parent or educator; often the
      // real need is narrower — this consent for these three families. Both work
      // together: a form reaches you if your role matches OR you are named here.
      + '<div style="border:1.5px solid #E2E8F0;border-radius:12px;padding:13px 15px;margin-bottom:16px;">'
      + '<div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;">'
      + '<span style="font-weight:800;font-size:13.5px;color:#0F172A;">Or send to specific people</span>'
      + '<span id="fm-rcount" style="font-size:12px;font-weight:800;color:#1E40AF;background:#EFF6FF;border:1px solid #BFDBFE;border-radius:999px;padding:2px 10px;">none selected</span>'
      + '<button id="fm-rtoggle" type="button" data-kt-iconized="1" style="margin-left:auto;background:#fff;border:1.5px solid #CBD5E1;color:#1F6080;border-radius:9px;padding:7px 14px;font-size:12.5px;font-weight:800;cursor:pointer;">Choose people</button>'
      + '</div>'
      + '<div style="font-size:12.5px;color:#64748B;line-height:1.5;margin-top:4px;">Leave empty to use the audiences above. Pick people to send it only to them.</div>'
      + '<div id="fm-rpicker" style="display:none;margin-top:11px;">'
      + '<input id="fm-rsearch" type="text" placeholder="Search by name or email…" style="width:100%;box-sizing:border-box;padding:9px 11px;border:1.5px solid #E2E8F0;border-radius:9px;font-size:13.5px;margin-bottom:8px;">'
      + '<div id="fm-rlist" style="max-height:230px;overflow-y:auto;border:1px solid #EEF2F7;border-radius:9px;"></div>'
      + '</div></div>'
      + '<label id="fm-fillable-wrap" style="display:flex;gap:11px;align-items:flex-start;border:1.5px solid #E2E8F0;border-radius:12px;padding:13px 15px;margin-bottom:16px;cursor:pointer;">'
      + '<input id="fm-fillable" type="checkbox" style="width:18px;height:18px;flex:0 0 auto;margin-top:1px;accent-color:#1F6FB2;">'
      + '<span><span style="display:block;font-weight:800;font-size:13.5px;color:#0F172A;">Let recipients fill this form in</span>'
      + '<span style="display:block;font-size:12.5px;color:#64748B;line-height:1.5;margin-top:2px;">'
      + 'Tick this if the PDF has fillable fields. Recipients get the form on screen with typing fields and sign it in place, on desktop or the app. '
      + 'Leave it off for read-and-sign notices.</span></span></label>'
      // Reuse: the same sheet completed over and over (per child, per week) rather
      // than signed once and finished.
      + '<label id="fm-reusable-wrap" style="display:flex;gap:11px;align-items:flex-start;border:1.5px solid #E2E8F0;border-radius:12px;padding:13px 15px;margin-bottom:16px;cursor:pointer;">'
      + '<input id="fm-reusable" type="checkbox" style="width:18px;height:18px;flex:0 0 auto;margin-top:1px;accent-color:#1F6FB2;">'
      + '<span><span style="display:block;font-weight:800;font-size:13.5px;color:#0F172A;">Allow this form to be reused</span>'
      + '<span style="display:block;font-size:12.5px;color:#64748B;line-height:1.5;margin-top:2px;">'
      + 'Keeps the form available after it is submitted, so staff can complete it again — once per child, or week after week. '
      + 'Each submission is kept as its own record.</span></span></label>'
      // Optional: where a completed copy goes. Often a compliance inbox or a
      // director, so a signed form does not only live in the Completed tab.
      + '<div><label for="fm-notify" style="' + LBL + '">Email completed forms to <span style="color:#CBD5E1;font-weight:600;text-transform:none;letter-spacing:0;">(optional)</span></label>'
      + '<input id="fm-notify" type="email" placeholder="e.g. compliance@youragency.com" style="' + FIELD + '">'
      + '<div style="font-size:12.5px;color:#64748B;line-height:1.5;margin-top:5px;">Each time someone signs this form, the completed PDF is emailed here. Leave blank to send nothing.</div></div>'
      // Action row
      + '<div style="display:flex;align-items:center;gap:14px;border-top:1px solid #F1F5F9;padding-top:16px;">'
      + '<button id="fm-upload" type="button" data-kt-iconized="1" style="background:linear-gradient(135deg,#0FA3B1,#1F6FB2 60%,#2456A6);color:#fff;border:0;border-radius:10px;padding:11px 24px;font-weight:800;font-size:13.5px;cursor:pointer;">Upload form</button>'
      + '<span id="fm-upout" style="font-size:13px;font-weight:700;"></span></div>'
      + '</div></div>'
      + '<div id="fm-list"><div style="padding:26px;text-align:center;color:#94A3B8;">Loading…</div></div>';

    // Chip active-state highlight + filename echo + dropzone accent.
    body.querySelectorAll('.fm-audchip').forEach(function (chip) {
      var cb = chip.querySelector('input');
      function sync() {
        chip.style.borderColor = cb.checked ? '#1F6FB2' : '#E2E8F0';
        chip.style.background = cb.checked ? '#EFF6FF' : '#fff';
        chip.style.color = cb.checked ? '#1E40AF' : '#334155';
      }
      cb.addEventListener('change', sync); sync();
    });
    [['#fm-fillable', '#fm-fillable-wrap'], ['#fm-reusable', '#fm-reusable-wrap']].forEach(function (pair) {
      var cb = body.querySelector(pair[0]), wrap = body.querySelector(pair[1]);
      if (!cb || !wrap) return;
      var sync = function () {
        wrap.style.borderColor = cb.checked ? '#1F6FB2' : '#E2E8F0';
        wrap.style.background = cb.checked ? '#EFF6FF' : '#fff';
      };
      cb.addEventListener('change', sync); sync();
    });
    // People picker, shared with the Edit dialog (see attachPeoplePicker). It used
    // to be inline here, which is how the two dialogs drifted apart.
    var picker = attachPeoplePicker(body, {
      toggle: '#fm-rtoggle', panel: '#fm-rpicker', list: '#fm-rlist',
      search: '#fm-rsearch', count: '#fm-rcount',
    }, []);
    var fileIn = body.querySelector('#fm-file'), drop = body.querySelector('#fm-drop');
    fileIn.addEventListener('change', function () {
      var f = fileIn.files[0];
      body.querySelector('#fm-fname').textContent = f ? f.name : 'Choose a PDF…';
      body.querySelector('#fm-fname').style.color = f ? '#0F172A' : '#64748B';
      drop.style.borderColor = f ? '#1F6FB2' : '#CBD5E1';
      drop.style.background = f ? '#EFF6FF' : '#F8FAFC';
    });

    body.querySelector('#fm-upload').onclick = function () {
      var out = body.querySelector('#fm-upout');
      var title = body.querySelector('#fm-title').value.trim();
      var desc = body.querySelector('#fm-desc').value.trim();
      var auds = [].slice.call(body.querySelectorAll('.fm-aud:checked')).map(function (c) { return c.value; });
      var file = body.querySelector('#fm-file').files[0];
      if (!title) { out.style.color = '#B91C1C'; out.textContent = 'Add a title.'; return; }
      // A title alone does not say what the form is for, and the Completed and
      // Library tables both show the description now.
      if (!desc) { out.style.color = '#B91C1C'; out.textContent = 'Add a description.'; return; }
      var people = picker.ids();
      if (!auds.length && !people.length) {
        out.style.color = '#B91C1C';
        out.textContent = 'Pick an audience, or choose specific people.';
        return;
      }
      if (!file) { out.style.color = '#B91C1C'; out.textContent = 'Choose a PDF.'; return; }
      out.style.color = '#64748B'; out.textContent = 'Uploading…';
      var fd = new FormData();
      fd.append('title', title); fd.append('description', desc);
      fd.append('fillable', body.querySelector('#fm-fillable').checked ? '1' : '0');
      fd.append('reusable', body.querySelector('#fm-reusable').checked ? '1' : '0');
      var notify = (body.querySelector('#fm-notify').value || '').trim();
      if (notify) fd.append('notify_email', notify);
      if (people.length) fd.append('recipient_ids', JSON.stringify(people.map(Number)));
      auds.forEach(function (a) { fd.append('audiences[]', a); });
      fd.append('file', file);
      Api.post('/admin/managed-forms', fd).then(function () {
        out.style.color = '#047857'; out.textContent = '✓ Uploaded.';
        try { body.dispatchEvent(new CustomEvent('kt-fm-uploaded')); } catch (e) {}
        toast('🗂️', 'Form uploaded', '"' + title + '" is now assigned.', '#16A34A');
        renderLibrary(body);
      }).catch(function (e) {
        // Laravel answers a 422 with {message, errors:{field:[...]}}. The summary
        // message only names the first failure ("The title field is required.
        // (and 2 more errors)"), which told the user nothing about the other two.
        // List every field error instead.
        out.style.color = '#B91C1C';
        var errs = e && e.data && e.data.errors;
        if (errs && typeof errs === 'object') {
          var lines = [];
          Object.keys(errs).forEach(function (k) {
            var msgs = Array.isArray(errs[k]) ? errs[k] : [errs[k]];
            msgs.forEach(function (m) { lines.push('• ' + m); });
          });
          out.innerHTML = '';
          out.appendChild(document.createTextNode('✗ Could not upload:'));
          var ul = document.createElement('div');
          ul.style.cssText = 'margin-top:4px;white-space:pre-line;';
          ul.textContent = lines.join(String.fromCharCode(10));
          out.appendChild(ul);
        } else {
          out.textContent = '✗ ' + ((e && e.message) || 'Upload failed');
        }
      });
    };

    // ── Upload panel -> dialog ───────────────────────────────────────────────
    // The panel is built and wired exactly as before, then MOVED into a dialog.
    // Moving a node keeps its listeners, so none of the upload wiring above needs
    // to know it now lives in a modal — and the screen leads with the library
    // instead of a tall form pushing existing forms below the fold.
    (function () {
      var panel = body.firstElementChild;                     // the upload card
      var list = body.querySelector('#fm-list');
      if (!panel || !list || panel === list) return;

      var bar = document.createElement('div');
      bar.style.cssText = 'display:flex;align-items:center;gap:12px;flex-wrap:wrap;margin-bottom:16px;';
      bar.innerHTML = '<div style="min-width:0;">'
        + '<div style="font-size:17px;font-weight:800;color:#0F172A;">Forms library</div>'
        + '<div style="font-size:13px;color:#64748B;margin-top:2px;">Upload a PDF and assign it to roles or to specific people.</div></div>';
      var openBtn = document.createElement('button');
      openBtn.type = 'button';
      openBtn.setAttribute('data-kt-iconized', '1');
      openBtn.textContent = '+ Upload a form';
      openBtn.style.cssText = 'margin-left:auto;background:linear-gradient(135deg,#0FA3B1,#1F6FB2 60%,#2456A6);'
        + 'color:#fff;border:0;border-radius:10px;padding:11px 20px;font-weight:800;font-size:13.5px;cursor:pointer;';
      bar.appendChild(openBtn);
      body.insertBefore(bar, list);

      // The overlay lives INSIDE the screen container, so a re-render after a
      // successful upload disposes of it automatically.
      var ov = document.createElement('div');
      ov.id = 'fm-upload-ov';
      ov.className = 'kt-scrim';
      ov.setAttribute('data-no-modal-guard', '1');
      ov.style.cssText = 'display:none;position:fixed;inset:0;z-index:2147479000;'
        + 'align-items:flex-start;justify-content:center;padding:20px;overflow-y:auto;';
      var card = document.createElement('div');
      card.style.cssText = 'background:#fff;border-radius:16px;max-width:640px;width:100%;margin:auto;'
        + 'box-shadow:0 30px 80px -20px rgba(8,20,40,.6);overflow:hidden;';
      var head = document.createElement('div');
      head.style.cssText = 'background:#0B2545;color:#fff;padding:14px 18px;display:flex;align-items:center;gap:12px;';
      head.innerHTML = '<div style="flex:1;min-width:0;">'
        + '<div style="font-size:10.5px;font-weight:800;letter-spacing:1.2px;opacity:.75;">FORMS MANAGER</div>'
        + '<div style="font-size:17px;font-weight:800;margin-top:2px;">Upload a form</div></div>';
      var closeBtn = document.createElement('button');
      closeBtn.type = 'button';
      closeBtn.setAttribute('aria-label', 'Close');
      closeBtn.setAttribute('data-kt-iconized', '1');
      closeBtn.textContent = '✕';
      closeBtn.style.cssText = 'background:rgba(255,255,255,.14);color:#fff;border:0;border-radius:9px;'
        + 'width:34px;height:34px;font-size:17px;line-height:1;cursor:pointer;flex:0 0 auto;';
      head.appendChild(closeBtn);
      var scroller = document.createElement('div');
      scroller.style.cssText = 'padding:16px;max-height:calc(100vh - 150px);overflow-y:auto;';

      panel.parentNode.removeChild(panel);                    // move, listeners intact
      panel.style.margin = '0';
      panel.style.border = '0';
      panel.style.boxShadow = 'none';
      panel.style.padding = '0';
      scroller.appendChild(panel);
      card.appendChild(head); card.appendChild(scroller); ov.appendChild(card);
      // PORTAL to <body>. Inside #fm-body the sidebar and top bar painted over it
      // regardless of z-index, so the scrim missed them and the dialog slid under the
      // top bar. The other two dialogs already attach here.
      document.body.appendChild(ov);

      var prevOverflow = '';
      function open() {
        prevOverflow = document.body.style.overflow;
        document.body.style.overflow = 'hidden';
        ov.style.display = 'flex';
      }
      function close() { document.body.style.overflow = prevOverflow; ov.style.display = 'none'; }
      openBtn.addEventListener('click', open);
      closeBtn.addEventListener('click', close);
      ov.addEventListener('click', function (e) { if (e.target === ov) close(); });
      document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && ov.style.display === 'flex') close();
      });
      // A successful upload re-renders the library; make sure the page can scroll
      // again even though this overlay is about to be discarded with it.
      body.addEventListener('kt-fm-uploaded', close);
    })();

    loadList(body.querySelector('#fm-list'));
  }

  /**
   * A timestamp in the AGENCY's timezone — never UTC, never the viewer's device.
   * This used to call toLocaleDateString/toLocaleTimeString straight on the server
   * string, which renders in whatever zone the viewer happens to be in, so an
   * evening upload could show the wrong DAY entirely. kt-tz.js owns the agency zone
   * (from /auth/me) and is the single source of truth for this everywhere.
   */
  function fmtStamp(ts) {
    if (!ts) return '';
    try {
      // Server datetimes are UTC and carry no zone marker, so they must be told
      // they are UTC before being rendered in the agency zone. Formatted in ONE
      // Intl call against KT.tz() — composing KT.fmtDate + KT.fmtTime produced
      // "Aug 12, 2026 05:10 a.m. · 1:10 a.m.", i.e. the UTC time and the agency
      // time side by side.
      var iso = String(ts).trim().replace(' ', 'T');
      if (!/[Zz]|[+-]\d{2}:?\d{2}$/.test(iso)) iso += 'Z';
      var d = new Date(iso);
      if (isNaN(d.getTime())) return String(ts);
      var zone = (KT.tz && KT.tz()) || undefined;
      return new Intl.DateTimeFormat(undefined, {
        timeZone: zone, day: 'numeric', month: 'short', year: 'numeric',
        hour: 'numeric', minute: '2-digit',
      }).format(d);
    } catch (e) { return String(ts); }
  }

  /** Rename / re-describe a form without re-uploading the PDF. */
  /**
   * Wires up a "send to specific people" picker inside `scope`.
   *
   * Both the upload dialog and the Edit dialog need this, and it started life inline
   * in the upload dialog only — which is why editing a form could not show, let alone
   * change, who had been named. `preselected` is a list of user ids to start ticked,
   * so Edit opens showing the current selection.
   *
   * Returns { ids() } — the chosen ids as numbers.
   */
  function attachPeoplePicker(scope, sel, preselected) {
    var chosen = {};                              // id -> label
    var loaded = false;
    var toggle = scope.querySelector(sel.toggle);
    var panel  = scope.querySelector(sel.panel);
    var list   = scope.querySelector(sel.list);
    var search = scope.querySelector(sel.search);
    var count  = scope.querySelector(sel.count);
    var ROLE_LABEL = { guardian: 'Parent', educator: 'Educator', home_visitor: 'Home visitor',
                       centre_director: 'Director', agency_admin: 'Admin', platform_admin: 'Super admin',
                       auditor: 'Auditor', sales_rep: 'Sales' };

    (preselected || []).forEach(function (id) { chosen[String(id)] = String(id); });

    function syncCount() {
      var n = Object.keys(chosen).length;
      count.textContent = n ? (n + ' selected') : 'none selected';
      count.style.background = n ? '#ECFDF5' : '#EFF6FF';
      count.style.color = n ? '#0F766E' : '#1E40AF';
      count.style.borderColor = n ? '#A7F3D0' : '#BFDBFE';
    }

    function paint(rows) {
      var q = (search.value || '').trim().toLowerCase();
      var shown = rows.filter(function (u) {
        if (!q) return true;
        return (u.__label + ' ' + (u.email || '')).toLowerCase().indexOf(q) !== -1;
      }).slice(0, 200);
      if (!shown.length) {
        list.innerHTML = '<div style="padding:16px;text-align:center;color:#94A3B8;font-size:13px;">No one matches that.</div>';
        return;
      }
      list.innerHTML = shown.map(function (u) {
        var on = !!chosen[String(u.id)];
        return '<label style="display:flex;align-items:center;gap:10px;padding:9px 11px;border-bottom:1px solid #F1F5F9;cursor:pointer;">'
          + '<input type="checkbox" data-uid="' + u.id + '" ' + (on ? 'checked' : '')
          + ' style="width:17px;height:17px;accent-color:#1F6FB2;flex:0 0 auto;">'
          + '<span style="min-width:0;"><span style="display:block;font-size:13.5px;font-weight:700;color:#0F172A;">' + esc(u.__label) + '</span>'
          + '<span style="display:block;font-size:11.5px;color:#64748B;">' + esc(u.__role) + (u.email ? ' · ' + esc(u.email) : '') + '</span></span></label>';
      }).join('');
      list.querySelectorAll('input[data-uid]').forEach(function (cb) {
        cb.addEventListener('change', function () {
          var id = cb.getAttribute('data-uid');
          var rec = rows.filter(function (x) { return String(x.id) === String(id); })[0];
          if (cb.checked) chosen[id] = rec ? rec.__label : id; else delete chosen[id];
          syncCount();
        });
      });
    }

    function load() {
      loaded = true;
      list.innerHTML = '<div style="padding:16px;text-align:center;color:#94A3B8;font-size:13px;">Loading people…</div>';
      Api.get('/admin/users').then(function (d) {
        var rows = (d && (d.users || d.data || d)) || [];
        if (!Array.isArray(rows)) rows = [];
        rows.forEach(function (u) {
          u.__label = ((u.first_name || '') + ' ' + (u.last_name || '')).trim() || u.name || u.email || ('User ' + u.id);
          var r = u.roles || u.primary_role || [];
          if (typeof r === 'string') r = [r];
          u.__role = (r || []).map(function (x) { return ROLE_LABEL[x] || x; }).join(', ') || 'Member';
        });
        rows.sort(function (a, b) { return a.__label.localeCompare(b.__label); });
        paint(rows);
        search.addEventListener('input', function () { paint(rows); });
      }).catch(function (e) {
        list.innerHTML = '<div style="padding:16px;color:#B91C1C;font-size:13px;">Could not load people: ' + esc(e.message || '') + '</div>';
      });
    }

    toggle.addEventListener('click', function () {
      var open = panel.style.display !== 'none';
      panel.style.display = open ? 'none' : 'block';
      toggle.textContent = open ? 'Choose people' : 'Hide list';
      if (! open && ! loaded) load();
    });

    syncCount();
    return { ids: function () { return Object.keys(chosen).map(Number); } };
  }

  /** The "send to specific people" block, shared markup for both dialogs. */
  function peopleBlockHtml(prefix, hint) {
    return '<div style="border:1.5px solid #E2E8F0;border-radius:12px;padding:13px 15px;margin-bottom:16px;">'
      + '<div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;">'
      + '<span style="font-weight:800;font-size:13.5px;color:#0F172A;">Or send to specific people</span>'
      + '<span id="' + prefix + '-rcount" style="font-size:12px;font-weight:800;color:#1E40AF;background:#EFF6FF;border:1px solid #BFDBFE;border-radius:999px;padding:2px 10px;">none selected</span>'
      + '<button id="' + prefix + '-rtoggle" type="button" data-kt-iconized="1" style="margin-left:auto;background:#fff;border:1.5px solid #CBD5E1;color:#1F6080;border-radius:9px;padding:7px 14px;font-size:12.5px;font-weight:800;cursor:pointer;">Choose people</button>'
      + '</div>'
      + '<div style="font-size:12.5px;color:#64748B;line-height:1.5;margin-top:4px;">' + hint + '</div>'
      + '<div id="' + prefix + '-rpicker" style="display:none;margin-top:11px;">'
      + '<input id="' + prefix + '-rsearch" type="text" placeholder="Search by name or email…" style="width:100%;box-sizing:border-box;padding:9px 11px;border:1.5px solid #E2E8F0;border-radius:9px;font-size:13.5px;margin-bottom:8px;">'
      + '<div id="' + prefix + '-rlist" style="max-height:230px;overflow-y:auto;border:1px solid #EEF2F7;border-radius:9px;"></div>'
      + '</div></div>';
  }

  /** One checkbox card, used for the fillable / reusable toggles in both dialogs. */
  function toggleCardHtml(id, title, blurb, checked) {
    return '<label id="' + id + '-wrap" style="display:flex;gap:11px;align-items:flex-start;border:1.5px solid '
      + (checked ? '#1F6FB2' : '#E2E8F0') + ';border-radius:12px;padding:13px 15px;margin-bottom:12px;cursor:pointer;">'
      + '<input id="' + id + '" type="checkbox" ' + (checked ? 'checked' : '')
      + ' style="width:18px;height:18px;flex:0 0 auto;margin-top:1px;accent-color:#1F6FB2;">'
      + '<span><span style="display:block;font-weight:800;font-size:13.5px;color:#0F172A;">' + title + '</span>'
      + '<span style="display:block;font-size:12.5px;color:#64748B;line-height:1.5;margin-top:2px;">' + blurb + '</span></span></label>';
  }

  /**
   * Edit EVERYTHING that was chosen at upload — not just the title and description.
   * Audiences, the fill-in and reuse toggles and the named people were upload-only,
   * so a wrong choice meant deleting the form and re-uploading the PDF to change a
   * boolean. Takes the whole form record so every control opens pre-populated.
   */
  /**
   * Show a PDF in a popup over the portal instead of handing it to the browser.
   *
   * openUrl() opens a new tab (and in the APK, an external browser), which loses the
   * session and drops the user out of the app to find their way back. This keeps the
   * document inside the page, with an explicit "Open in new tab" escape hatch for
   * anyone who prefers the browser's own viewer.
   */
  function openPdfPopup(url, title) {
    if (!url) return;
    var ov = document.createElement('div');
    ov.setAttribute('data-no-modal-guard', '1');
    ov.className = 'kt-scrim';
    ov.style.cssText = 'position:fixed;inset:0;z-index:2147481200;'
      + 'display:flex;align-items:center;justify-content:center;padding:18px;';
    ov.innerHTML =
      '<div style="background:#F6F9FC;border-radius:16px;width:100%;max-width:960px;height:min(92vh,1100px);'
      + 'display:flex;flex-direction:column;overflow:hidden;box-shadow:0 30px 80px -20px rgba(8,20,40,.6);">'
      + '<div style="background:#0B2545;color:#fff;padding:13px 16px;display:flex;align-items:center;gap:12px;flex:0 0 auto;">'
      +   '<div style="min-width:0;flex:1;">'
      +     '<div style="font-size:10.5px;font-weight:800;letter-spacing:1.2px;opacity:.75;">FORM</div>'
      +     '<div style="font-size:15.5px;font-weight:800;margin-top:2px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">' + esc(title || 'Form') + '</div>'
      +   '</div>'
      +   '<button id="fv-new" type="button" data-kt-iconized="1" style="background:rgba(255,255,255,.14);color:#fff;border:0;border-radius:9px;padding:7px 12px;font-size:12.5px;font-weight:800;cursor:pointer;white-space:nowrap;">Open in new tab</button>'
      +   '<button id="fv-close" type="button" aria-label="Close" style="background:rgba(255,255,255,.14);color:#fff;border:0;border-radius:9px;width:34px;height:34px;font-size:17px;line-height:1;cursor:pointer;flex:0 0 auto;">\u2715</button>'
      + '</div>'
      + '<iframe src="' + esc(url) + '" title="' + esc(title || 'Form') + '" style="flex:1;width:100%;border:0;background:#fff;"></iframe>'
      + '</div>';
    document.body.appendChild(ov);
    function close() { if (ov.parentNode) ov.parentNode.removeChild(ov); }
    ov.querySelector('#fv-close').addEventListener('click', close);
    ov.querySelector('#fv-new').addEventListener('click', function () { openUrl(url); });
    ov.addEventListener('click', function (e) { if (e.target === ov) close(); });
  }

  function openEditDialog(form, el) {
    var auds = form.audiences || [];
    var ov = document.createElement('div');
    ov.setAttribute('data-no-modal-guard', '1');
    ov.className = 'kt-scrim';
    ov.style.cssText = 'position:fixed;inset:0;z-index:2147479500;display:flex;align-items:flex-start;justify-content:center;padding:18px;overflow-y:auto;';
    ov.innerHTML = '<div style="background:#fff;border-radius:16px;max-width:520px;width:100%;overflow:hidden;box-shadow:0 30px 80px -20px rgba(8,20,40,.6);margin:auto;">'
      + '<div style="background:#0B2545;color:#fff;padding:14px 18px;font-size:16px;font-weight:800;">Edit form</div>'
      + '<div style="padding:18px;">'
      + '<div style="font-size:11px;font-weight:800;letter-spacing:.5px;color:#64748B;text-transform:uppercase;margin-bottom:6px;">Title</div>'
      + '<input id="fe-title" type="text" style="width:100%;box-sizing:border-box;padding:11px;border:1.5px solid #E2E8F0;border-radius:10px;font-size:15px;">'
      + '<div style="font-size:11px;font-weight:800;letter-spacing:.5px;color:#64748B;text-transform:uppercase;margin:14px 0 6px;">Description</div>'
      + '<textarea id="fe-desc" rows="3" style="width:100%;box-sizing:border-box;padding:11px;border:1.5px solid #E2E8F0;border-radius:10px;font-size:14px;font-family:inherit;resize:vertical;"></textarea>'
      + '<div style="font-size:11px;font-weight:800;letter-spacing:.5px;color:#64748B;text-transform:uppercase;margin:14px 0 6px;">Who signs it</div>'
      + '<div style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:14px;">'
        + AUD.map(function (a) {
            var on = auds.indexOf(a[0]) !== -1;
            return '<label style="display:inline-flex;align-items:center;gap:7px;border:1.5px solid ' + (on ? '#1F6FB2' : '#E2E8F0')
              + ';border-radius:999px;padding:7px 13px;font-size:13px;font-weight:700;color:#0F172A;cursor:pointer;background:' + (on ? '#EFF6FF' : '#fff') + ';">'
              + '<input type="checkbox" class="fe-aud" value="' + a[0] + '" ' + (on ? 'checked' : '')
              + ' style="accent-color:#1F6FB2;width:16px;height:16px;margin:0;">'
              + '<span>' + a[2] + ' ' + a[1] + '</span></label>';
          }).join('')
      + '</div>'
      + peopleBlockHtml('fe', 'Leave empty to use the audiences above. Pick people to send it only to them.')
      + toggleCardHtml('fe-fillable', 'Let recipients fill this form in',
          'Turn on for a PDF with real form fields. A read-and-sign notice should stay read-and-sign.', !!form.fillable)
      + toggleCardHtml('fe-reusable', 'Reusable form',
          'Stays on the list after signing, so it can be filled again for the next child or week.', !!form.reusable)
      + '<div style="font-size:11px;font-weight:800;letter-spacing:.5px;color:#64748B;text-transform:uppercase;margin:4px 0 6px;">Email completed forms to (optional)</div>'
      + '<input id="fe-notify" type="email" placeholder="e.g. compliance@youragency.com" style="width:100%;box-sizing:border-box;padding:11px;border:1.5px solid #E2E8F0;border-radius:10px;font-size:14px;margin-bottom:6px;">'
      + '<div style="font-size:12.5px;color:#64748B;line-height:1.5;margin-bottom:14px;">Each signature emails the completed PDF here. Clear it to stop sending.</div>'
      + '<div id="fe-msg" style="font-size:12.5px;color:#B91C1C;min-height:16px;margin-top:2px;"></div>'
      + '<div style="display:flex;gap:10px;justify-content:flex-end;margin-top:8px;">'
      + '<button id="fe-cancel" type="button" data-kt-iconized="1" style="background:#fff;border:1.5px solid #CBD5E1;color:#1F6080;border-radius:10px;padding:9px 16px;font-weight:800;font-size:13px;cursor:pointer;">Cancel</button>'
      + '<button id="fe-save" type="button" data-kt-iconized="1" style="background:linear-gradient(135deg,#0FA3B1,#1F6FB2);color:#fff;border:0;border-radius:10px;padding:9px 18px;font-weight:800;font-size:13px;cursor:pointer;">Save changes</button>'
      + '</div></div></div>';
    document.body.appendChild(ov);
    ov.querySelector('#fe-title').value = form.title || '';
    ov.querySelector('#fe-desc').value = form.description || '';
    ov.querySelector('#fe-notify').value = form.notify_email || '';

    // Same live border feedback the upload dialog gives its toggles.
    ['fe-fillable', 'fe-reusable'].forEach(function (id) {
      var cb = ov.querySelector('#' + id), wrap = ov.querySelector('#' + id + '-wrap');
      cb.addEventListener('change', function () { wrap.style.borderColor = cb.checked ? '#1F6FB2' : '#E2E8F0'; });
    });
    ov.querySelectorAll('.fe-aud').forEach(function (cb) {
      cb.addEventListener('change', function () {
        var lab = cb.closest('label');
        lab.style.borderColor = cb.checked ? '#1F6FB2' : '#E2E8F0';
        lab.style.background = cb.checked ? '#EFF6FF' : '#fff';
      });
    });

    var picker = attachPeoplePicker(ov, {
      toggle: '#fe-rtoggle', panel: '#fe-rpicker', list: '#fe-rlist',
      search: '#fe-rsearch', count: '#fe-rcount',
    }, form.recipient_ids || []);

    function close() { ov.remove(); }
    ov.querySelector('#fe-cancel').addEventListener('click', close);
    ov.addEventListener('click', function (e) { if (e.target === ov) close(); });

    var save = ov.querySelector('#fe-save');
    save.addEventListener('click', function () {
      var msg = ov.querySelector('#fe-msg');
      var t = ov.querySelector('#fe-title').value.trim();
      if (!t) { msg.textContent = 'A title is required.'; return; }
      if (!ov.querySelector('#fe-desc').value.trim()) { msg.textContent = 'A description is required.'; return; }
      var chosenAuds = [].slice.call(ov.querySelectorAll('.fe-aud:checked')).map(function (c) { return c.value; });
      // Preserve any audience this dialog has no chip for, rather than silently
      // deleting it: the server accepts roles the UI may not list yet, and a save
      // must never quietly narrow who a form reaches.
      var chipValues = AUD.map(function (a) { return a[0]; });
      (auds || []).forEach(function (a) {
        if (chipValues.indexOf(a) === -1 && chosenAuds.indexOf(a) === -1) chosenAuds.push(a);
      });
      var people = picker.ids();
      if (!chosenAuds.length && !people.length) {
        msg.textContent = 'Pick an audience, or choose specific people — otherwise nobody can sign it.';
        return;
      }
      save.disabled = true; save.textContent = 'Saving…';
      Api.patch('/admin/managed-forms/' + form.id, {
        title: t,
        description: ov.querySelector('#fe-desc').value.trim(),
        audiences: chosenAuds,
        recipient_ids: people,
        fillable: ov.querySelector('#fe-fillable').checked,
        reusable: ov.querySelector('#fe-reusable').checked,
        notify_email: ov.querySelector('#fe-notify').value.trim(),
      })
        .then(function () { toast('✏️', 'Form updated', '', '#16A34A'); close(); loadList(el); })
        .catch(function (e) {
          save.disabled = false; save.textContent = 'Save changes';
          msg.textContent = (e && e.message) || 'Could not save.';
        });
    });
  }

  function loadList(el) {
    Api.get('/admin/managed-forms').then(function (d) {
      var forms = (d && d.forms) || [];
      if (!forms.length) { el.innerHTML = '<div style="padding:30px;text-align:center;color:#64748B;background:#F8FAFC;border-radius:12px;">No forms uploaded yet.</div>'; return; }
      el.innerHTML = '<table style="width:100%;border-collapse:collapse;font-size:13px;background:#fff;border:1px solid #E5E7EB;border-radius:12px;overflow:hidden;">'
        + '<thead><tr style="background:#F9FAFB;">' + ['Form', 'Description', 'Assigned to', 'Email copy', 'Uploaded by', 'Signed', 'Status', ''].map(function (h) { return '<th style="text-align:left;padding:9px 14px;font-size:11px;color:#6B7280;text-transform:uppercase;">' + h + '</th>'; }).join('') + '</tr></thead><tbody>'
        + forms.map(function (f) {
          var auds = (f.audiences || []).map(function (a) { return '<span style="display:inline-block;background:#EFF6FB;color:#1F6080;border-radius:20px;padding:2px 9px;font-size:11px;font-weight:700;margin:1px 3px 1px 0;">' + esc(audLabel(a)) + '</span>'; }).join('');
          return '<tr style="border-top:1px solid #F3F4F6;">'
            + '<td style="padding:9px 14px;"><span class="fm-open" data-u="' + esc(fileUrl(f.file_url)) + '" style="color:#2563EB;font-weight:700;cursor:pointer;">' + esc(f.title) + '</span></td>'
            // Description gets its own sortable column. As a grey sub-line under the
            // title it was easy to miss entirely, and it could not be sorted or scanned.
            + '<td style="padding:9px 14px;color:#475569;max-width:320px;">' + (f.description ? esc(f.description) : '<span style="color:#CBD5E1;">—</span>') + '</td>'
            + '<td style="padding:9px 14px;">' + (auds || '—')
            // Whether a completed copy is emailed on, and where to. Without this the
            // only way to know was to open Edit on every form one at a time.
            + '<td style="padding:9px 14px;white-space:nowrap;">' + (f.notify_email
                ? '<span style="font-size:11px;font-weight:800;color:#0F766E;background:#ECFDF5;border:1px solid #A7F3D0;border-radius:999px;padding:2px 9px;">On</span>'
                  + '<div style="font-size:11px;color:#94A3B8;margin-top:3px;">' + esc(f.notify_email) + '</div>'
                : '<span style="font-size:11px;font-weight:800;color:#64748B;background:#F1F5F9;border:1px solid #E2E8F0;border-radius:999px;padding:2px 9px;">Off</span>') + '</td>'
            + (f.named_count ? '<span style="display:inline-block;background:#FFF7ED;color:#C2410C;border-radius:20px;padding:2px 9px;font-size:11px;font-weight:800;margin-left:4px;">+' + f.named_count + ' named</span>' : '')
            + '</td>'
            + '<td style="padding:9px 14px;color:#475569;white-space:nowrap;">'
            + esc(f.uploaded_by || '—')
            + '<div style="font-size:11.5px;color:#94A3B8;">' + esc(fmtStamp(f.created_at)) + '</div></td>'
            + '<td style="padding:9px 14px;font-weight:700;color:#0F172A;">' + (f.signoff_count || 0) + '</td>'
            + '<td style="padding:9px 14px;">' + (f.active ? '<span style="color:#16A34A;font-weight:700;">● Active</span>' : '<span style="color:#94A3B8;">Off</span>') + '</td>'
            + '<td style="padding:9px 8px;text-align:right;white-space:nowrap;">'
            + '<button class="fm-edit kt-act-icon" data-id="' + f.id + '" data-t="' + esc(f.title) + '" data-d="' + esc(f.description || '') + '" title="Edit details" style="border:1px solid #BFDBFE;background:#EFF6FF;color:#1E40AF;">✏️</button>'
            + '<button class="fm-toggle kt-act-icon" data-id="' + f.id + '" data-active="' + (f.active ? 1 : 0) + '" title="' + (f.active ? 'Deactivate' : 'Activate') + '" style="border:1px solid #E5E7EB;background:#fff;border-radius:8px;padding:5px 9px;cursor:pointer;font-size:13px;">' + (f.active ? '⏸' : '▶️') + '</button> '
            + '<button class="fm-del kt-act-icon" data-id="' + f.id + '" data-t="' + esc(f.title) + '" title="Delete" style="border:1px solid #FECACA;background:#FEF2F2;color:#B91C1C;border-radius:8px;padding:5px 9px;cursor:pointer;font-size:13px;">🗑️</button>'
            + '</td></tr>';
        }).join('') + '</tbody></table>';
      el.querySelectorAll('.fm-open').forEach(function (b) { b.addEventListener('click', function () { openUrl(b.getAttribute('data-u')); }); });
      // Pass the loaded record, not scraped data- attributes: the dialog now needs
      // audiences, both toggles and the named recipients as well.
      var byId = {};
      forms.forEach(function (f) { byId[String(f.id)] = f; });
      el.querySelectorAll('.fm-edit').forEach(function (b) {
        b.addEventListener('click', function () {
          var rec = byId[String(b.getAttribute('data-id'))];
          if (rec) openEditDialog(rec, el);
        });
      });
      el.querySelectorAll('.fm-toggle').forEach(function (b) { b.addEventListener('click', function () {
        var on = b.getAttribute('data-active') === '1';
        Api.patch('/admin/managed-forms/' + b.getAttribute('data-id'), { active: !on }).then(function () { loadList(el); }).catch(function (e) { toast('⚠️', 'Failed', e.message || '', '#B91C1C'); });
      }); });
      el.querySelectorAll('.fm-del').forEach(function (b) { b.addEventListener('click', function () {
        Promise.resolve(KT.confirm ? KT.confirm('Delete "' + b.getAttribute('data-t') + '"? Its sign-offs are removed too.') : confirm('Delete?')).then(function (ok) {
          if (!ok) return;
          Api.delete('/admin/managed-forms/' + b.getAttribute('data-id')).then(function () { toast('🗑️', 'Deleted', '', '#B91C1C'); loadList(el); }).catch(function (e) { toast('⚠️', 'Failed', e.message || '', '#B91C1C'); });
        });
      }); });
    }).catch(function (e) { el.innerHTML = '<div style="padding:24px;color:#B91C1C;">Could not load: ' + esc(e.message || '') + '</div>'; });
  }

  /* ───────── COMPLETED: sign-offs table + kebab ───────── */
  function renderCompleted(body) {
    body.innerHTML = '<div id="fm-comp"><div style="padding:26px;text-align:center;color:#94A3B8;">Loading…</div></div>';
    var el = body.querySelector('#fm-comp');
    Api.get('/admin/managed-forms/signoffs').then(function (d) {
      var rows = (d && d.signoffs) || [];
      if (!rows.length) { el.innerHTML = '<div style="padding:30px;text-align:center;color:#64748B;background:#F8FAFC;border-radius:12px;">No forms have been signed yet.</div>'; return; }
      el.innerHTML = '<table data-kt-no-kebab="1" style="width:100%;border-collapse:collapse;font-size:13px;background:#fff;border:1px solid #E5E7EB;border-radius:12px;overflow:hidden;">'
        + '<thead><tr style="background:#F9FAFB;">' + ['Form', 'Description', 'Signed by', 'Signed (agency time)', 'Copy emailed', ''].map(function (h) { return '<th style="text-align:left;padding:9px 14px;font-size:11px;color:#6B7280;text-transform:uppercase;">' + h + '</th>'; }).join('') + '</tr></thead><tbody>'
        + rows.map(function (r) {
          var who = (((r.first_name || '') + ' ' + (r.last_name || '')).trim()) || r.signer_name || r.email || '—';
          // The description is what the form is FOR — a list of titles like "test 8"
          // says nothing on its own. It is mandatory at upload, so it is always there.
          var desc = (r.form_description || '').trim();
          return '<tr style="border-top:1px solid #F3F4F6;">'
            + '<td style="padding:9px 14px;font-weight:600;color:#111827;">' + esc(r.form_title) + '</td>'
            + '<td style="padding:9px 14px;color:#475569;max-width:320px;">' + (desc ? esc(desc) : '<span style="color:#CBD5E1;">—</span>') + '</td>'
            + '<td style="padding:9px 14px;">' + esc(who) + (r.email ? '<div style="font-size:11px;color:#94A3B8;">' + esc(r.email) + '</div>' : '') + '</td>'
            + '<td style="padding:9px 14px;color:#374151;white-space:nowrap;">' + esc(fmtStamp(r.signed_at)) + '</td>'
            // Did the completed copy actually reach the address on the form? Three
            // distinct states, because "no tick" alone cannot tell an admin whether
            // sending was off or simply had not happened.
            + '<td style="padding:9px 14px;white-space:nowrap;">' + (
                r.notified_at
                  ? '<span style="color:#16A34A;font-weight:700;">✓ ' + esc(fmtStamp(r.notified_at)) + '</span>'
                    + (r.notified_to ? '<div style="font-size:11px;color:#94A3B8;">' + esc(r.notified_to) + '</div>' : '')
                  : (r.form_notify_email
                      ? '<span style="font-size:11px;font-weight:800;color:#B45309;background:#FEF3C7;border:1px solid #FDE68A;border-radius:999px;padding:2px 9px;">Not sent</span>'
                      : '<span style="font-size:11px;color:#94A3B8;">Not set up</span>')
              ) + '</td>'
            + '<td style="padding:9px 8px;text-align:right;">' + kebab(r) + '</td></tr>';
        }).join('') + '</tbody></table>';
      wireKebabs(el, rows);
    }).catch(function (e) { el.innerHTML = '<div style="padding:24px;color:#B91C1C;">Could not load: ' + esc(e.message || '') + '</div>'; });
  }

  function kebab(r) {
    return '<button class="fm-kebab" data-id="' + r.id + '" data-fid="' + r.managed_form_id + '" style="width:32px;height:32px;border:1px solid #E5E7EB;background:#fff;border-radius:8px;cursor:pointer;font-size:17px;color:#475569;" title="Actions" data-kt-iconized="1">⋮</button>';
  }

  function wireKebabs(el, rows) {
    var byId = {}; rows.forEach(function (r) { byId[r.id] = r; });
    el.querySelectorAll('.fm-kebab').forEach(function (btn) {
      btn.addEventListener('click', function (e) {
        e.stopPropagation();
        var r = byId[btn.getAttribute('data-id')];
        var menu = document.createElement('div');
        menu.style.cssText = 'position:fixed;z-index:2147483000;background:#fff;border:1px solid #E5E7EB;border-radius:12px;box-shadow:0 12px 34px rgba(15,23,42,.18);padding:6px 0;min-width:190px;';
        function item(icon, label, danger, fn) {
          var mi = document.createElement('button'); mi.type = 'button';
          mi.style.cssText = 'display:flex;align-items:center;gap:10px;width:100%;text-align:left;background:none;border:none;padding:10px 15px;font-size:13.5px;cursor:pointer;color:' + (danger ? '#B91C1C' : '#111827') + ';font-family:inherit;white-space:nowrap;';
          mi.innerHTML = '<span style="width:18px;text-align:center;">' + icon + '</span><span>' + label + '</span>';
          mi.onmouseenter = function () { mi.style.background = '#F1F5F9'; }; mi.onmouseleave = function () { mi.style.background = 'none'; };
          mi.onclick = function (ev) { ev.stopPropagation(); close(); fn(); };
          return mi;
        }
        // The signature-only view is gone: the signature is embedded in the document
        // itself (with the signer's name and date), so a separate "here is the
        // squiggle" screen shows less than the PDF does.
        // Download the COMPLETED copy — r.file_url is the blank original, which is
        // why this opened an empty form. Fall back to the original only when no
        // completed copy exists, and say so rather than pretending.
        menu.appendChild(item('\uD83D\uDC41', 'View form', false, function () {
          openPdfPopup(fileUrl(r.filled_file_url || r.file_url), r.form_title || 'Form');
        }));
        // Send the completed copy to the address configured ON THE FORM: for
        // submissions signed before that address was set, and to re-send one that
        // still shows as "Not sent".
        if (r.form_notify_email) {
          menu.appendChild(item('\uD83D\uDCE4', (r.notified_at ? 'Email the copy again' : 'Email the copy') + ' to ' + r.form_notify_email, false, function () {
            Api.post('/admin/managed-forms/signoffs/' + r.id + '/email', {})
              .then(function (d) { toast('\u2709', 'Sent', (d && d.message) || 'Copy emailed.', '#16A34A'); renderCompleted(el.parentNode || el); })
              .catch(function (e) { toast('\u26A0', 'Could not send', (e && e.message) || '', '#B91C1C'); });
          }));
        }
        menu.appendChild(item('⬇️', r.filled_file_url ? 'Download completed form' : 'Download blank form (not completed)', false, function () {
          openUrl(fileUrl(r.filled_file_url || r.file_url));
        }));
        menu.appendChild(item('✉️', 'Email the signer', false, function () {
          var to = r.email || ''; var subj = encodeURIComponent('Re: ' + (r.form_title || 'signed form'));
          window.location.href = 'mailto:' + to + '?subject=' + subj;
        }));
        document.body.appendChild(menu);
        var rect = btn.getBoundingClientRect();
        var mw = menu.offsetWidth || 190, mh = menu.offsetHeight || 150;
        menu.style.left = Math.max(8, Math.min(rect.right - mw, innerWidth - mw - 8)) + 'px';
        menu.style.top = (rect.bottom + 6 + mh > innerHeight - 8 ? Math.max(8, rect.top - mh - 6) : rect.bottom + 6) + 'px';
        function close() { if (menu.parentNode) menu.remove(); document.removeEventListener('click', onDoc, true); }
        function onDoc(ev) { if (!menu.contains(ev.target) && ev.target !== btn) close(); }
        setTimeout(function () { document.addEventListener('click', onDoc, true); }, 0);
      });
    });
  }

  function viewSignoff(r) {
    Api.get('/admin/managed-forms/' + r.managed_form_id + '/signoff/' + r.id).then(function (d) {
      var s = d && d.signoff; if (!s) return;
      var who = (((s.first_name || '') + ' ' + (s.last_name || '')).trim()) || s.signer_name || s.email || '';
      var ov = document.createElement('div');
      ov.className = 'kt-scrim';
      ov.setAttribute('data-no-modal-guard', '1');
      ov.style.cssText = 'position:fixed;inset:0;z-index:2147483001;display:flex;align-items:center;justify-content:center;padding:20px;';
      ov.innerHTML = '<div style="background:#fff;border-radius:16px;max-width:460px;width:100%;overflow:hidden;box-shadow:0 24px 60px rgba(0,0,0,.35);">'
        + '<div style="padding:18px 20px;border-bottom:1px solid #EEF2F6;"><div style="font-size:16px;font-weight:800;">' + esc(s.form_title) + '</div><div style="font-size:12.5px;color:#64748B;margin-top:2px;">Signed by ' + esc(who) + ' · ' + esc(fmtDate(s.signed_at)) + '</div></div>'
        + '<div style="padding:20px;">'
        + '<div style="font-size:11.5px;font-weight:800;color:#64748B;text-transform:uppercase;letter-spacing:.5px;margin-bottom:8px;">Signature</div>'
        + (s.signature ? '<img src="' + esc(s.signature) + '" alt="signature" style="max-width:100%;border:1px solid #E5E7EB;border-radius:10px;background:#fff;">' : '<div style="color:#94A3B8;">No signature on file.</div>')
        + '<div style="display:flex;gap:10px;margin-top:16px;">'
        + '<button id="fm-vform" style="flex:1;background:#1F6080;color:#fff;border:0;border-radius:10px;padding:11px;font-weight:800;font-size:13px;cursor:pointer;">Open the form (PDF)</button>'
        + '<button id="fm-vclose" style="flex:0 0 auto;background:#F1F5F9;color:#334155;border:0;border-radius:10px;padding:11px 18px;font-weight:700;font-size:13px;cursor:pointer;">Close</button>'
        + '</div></div></div>';
      document.body.appendChild(ov);
      ov.querySelector('#fm-vform').onclick = function () { openUrl(fileUrl(s.file_url)); };
      ov.querySelector('#fm-vclose').onclick = function () { ov.remove(); };
      ov.addEventListener('click', function (e) { if (e.target === ov) ov.remove(); });
    }).catch(function (e) { toast('⚠️', 'Could not load', e.message || '', '#B91C1C'); });
  }

  try {
    ['platform_admin', 'agency_admin', 'centre_director'].forEach(function (r) {
      if (KT.Shell && KT.Shell.registerScreen) KT.Shell.registerScreen(r + ':forms-manager', render);
    });
  } catch (e) {}
  KT.renderFormsManager = render;
})();
