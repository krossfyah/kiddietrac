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
  var AUD = [['guardian', 'Parents', '👪'], ['educator', 'Educators', '🎓'], ['home_visitor', 'Home visitors', '🏡']];
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
    body.innerHTML =
      '<div class="kt-card" style="background:#fff;border:1px solid #E7EBF0;border-radius:16px;padding:22px 24px;margin-bottom:18px;box-shadow:0 1px 4px rgba(15,23,42,.05);">'
      + '<div style="font-weight:800;font-size:15px;margin:0 0 4px;color:#0F172A;">⬆️ Upload a new form</div>'
      + '<div style="font-size:12.5px;color:#94A3B8;margin-bottom:18px;">Add a PDF, choose who signs it, then assign.</div>'
      + '<div style="display:flex;flex-direction:column;gap:16px;">'
      // Title
      + '<div><label for="fm-title" style="' + LBL + '">Form title</label>'
      + '<input id="fm-title" placeholder="e.g. Consent to photograph" style="' + FIELD + '"></div>'
      // Description
      + '<div><label for="fm-desc" style="' + LBL + '">Description <span style="color:#CBD5E1;font-weight:600;text-transform:none;letter-spacing:0;">(optional)</span></label>'
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
    // ── people picker ────────────────────────────────────────────────────────
    var chosenPeople = {};                       // id -> label
    var peopleLoaded = false;
    var rToggle = body.querySelector('#fm-rtoggle');
    var rPicker = body.querySelector('#fm-rpicker');
    var rList = body.querySelector('#fm-rlist');
    var rSearch = body.querySelector('#fm-rsearch');
    var rCount = body.querySelector('#fm-rcount');
    var ROLE_LABEL = { guardian: 'Parent', educator: 'Educator', home_visitor: 'Home visitor',
                       centre_director: 'Director', agency_admin: 'Admin', platform_admin: 'Super admin',
                       auditor: 'Auditor', sales_rep: 'Sales' };

    function syncCount() {
      var n = Object.keys(chosenPeople).length;
      rCount.textContent = n ? (n + ' selected') : 'none selected';
      rCount.style.background = n ? '#ECFDF5' : '#EFF6FF';
      rCount.style.color = n ? '#0F766E' : '#1E40AF';
      rCount.style.borderColor = n ? '#A7F3D0' : '#BFDBFE';
    }

    function paintPeople(rows) {
      var q = (rSearch.value || '').trim().toLowerCase();
      var shown = rows.filter(function (u) {
        if (!q) return true;
        return (u.__label + ' ' + (u.email || '')).toLowerCase().indexOf(q) !== -1;
      }).slice(0, 200);
      if (!shown.length) {
        rList.innerHTML = '<div style="padding:16px;text-align:center;color:#94A3B8;font-size:13px;">No one matches that.</div>';
        return;
      }
      rList.innerHTML = shown.map(function (u) {
        var on = !!chosenPeople[u.id];
        return '<label style="display:flex;align-items:center;gap:10px;padding:9px 11px;border-bottom:1px solid #F1F5F9;cursor:pointer;">'
          + '<input type="checkbox" data-uid="' + u.id + '" ' + (on ? 'checked' : '')
          + ' style="width:17px;height:17px;accent-color:#1F6FB2;flex:0 0 auto;">'
          + '<span style="min-width:0;"><span style="display:block;font-size:13.5px;font-weight:700;color:#0F172A;">' + esc(u.__label) + '</span>'
          + '<span style="display:block;font-size:11.5px;color:#64748B;">' + esc(u.__role) + (u.email ? ' · ' + esc(u.email) : '') + '</span></span></label>';
      }).join('');
      rList.querySelectorAll('input[data-uid]').forEach(function (cb) {
        cb.addEventListener('change', function () {
          var id = cb.getAttribute('data-uid');
          var rec = rows.filter(function (x) { return String(x.id) === String(id); })[0];
          if (cb.checked) chosenPeople[id] = rec ? rec.__label : id; else delete chosenPeople[id];
          syncCount();
        });
      });
    }

    rToggle.addEventListener('click', function () {
      var open = rPicker.style.display !== 'none';
      rPicker.style.display = open ? 'none' : 'block';
      rToggle.textContent = open ? 'Choose people' : 'Hide list';
      if (open || peopleLoaded) return;
      peopleLoaded = true;
      rList.innerHTML = '<div style="padding:16px;text-align:center;color:#94A3B8;font-size:13px;">Loading people…</div>';
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
        paintPeople(rows);
        rSearch.addEventListener('input', function () { paintPeople(rows); });
      }).catch(function (e) {
        rList.innerHTML = '<div style="padding:16px;color:#B91C1C;font-size:13px;">Could not load people: ' + esc(e.message || '') + '</div>';
      });
    });
    syncCount();

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
      var people = Object.keys(chosenPeople);
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
      ov.setAttribute('data-no-modal-guard', '1');
      ov.style.cssText = 'display:none;position:fixed;inset:0;z-index:2147479000;background:rgba(8,17,33,.62);'
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
      body.appendChild(ov);

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

  /** "12 Aug 2026 · 3:41 PM" — a form's provenance needs a real date, not "today". */
  function fmtStamp(ts) {
    if (!ts) return '';
    try {
      var d = new Date(String(ts).replace(' ', 'T'));
      if (isNaN(d.getTime())) return String(ts);
      return d.toLocaleDateString(undefined, { day: 'numeric', month: 'short', year: 'numeric' })
        + ' · ' + d.toLocaleTimeString(undefined, { hour: 'numeric', minute: '2-digit' });
    } catch (e) { return String(ts); }
  }

  /** Rename / re-describe a form without re-uploading the PDF. */
  function openEditDialog(id, title, desc, el) {
    var ov = document.createElement('div');
    ov.setAttribute('data-no-modal-guard', '1');
    ov.style.cssText = 'position:fixed;inset:0;z-index:2147479500;background:rgba(8,17,33,.62);display:flex;align-items:center;justify-content:center;padding:18px;';
    ov.innerHTML = '<div style="background:#fff;border-radius:16px;max-width:460px;width:100%;overflow:hidden;box-shadow:0 30px 80px -20px rgba(8,20,40,.6);">'
      + '<div style="background:#0B2545;color:#fff;padding:14px 18px;font-size:16px;font-weight:800;">Edit form details</div>'
      + '<div style="padding:18px;">'
      + '<div style="font-size:11px;font-weight:800;letter-spacing:.5px;color:#64748B;text-transform:uppercase;margin-bottom:6px;">Title</div>'
      + '<input id="fe-title" type="text" style="width:100%;box-sizing:border-box;padding:11px;border:1.5px solid #E2E8F0;border-radius:10px;font-size:15px;">'
      + '<div style="font-size:11px;font-weight:800;letter-spacing:.5px;color:#64748B;text-transform:uppercase;margin:14px 0 6px;">Description</div>'
      + '<textarea id="fe-desc" rows="3" style="width:100%;box-sizing:border-box;padding:11px;border:1.5px solid #E2E8F0;border-radius:10px;font-size:14px;font-family:inherit;resize:vertical;"></textarea>'
      + '<div id="fe-msg" style="font-size:12.5px;color:#B91C1C;min-height:16px;margin-top:8px;"></div>'
      + '<div style="display:flex;gap:10px;justify-content:flex-end;margin-top:8px;">'
      + '<button id="fe-cancel" type="button" data-kt-iconized="1" style="background:#fff;border:1.5px solid #CBD5E1;color:#1F6080;border-radius:10px;padding:9px 16px;font-weight:800;font-size:13px;cursor:pointer;">Cancel</button>'
      + '<button id="fe-save" type="button" data-kt-iconized="1" style="background:linear-gradient(135deg,#0FA3B1,#1F6FB2);color:#fff;border:0;border-radius:10px;padding:9px 18px;font-weight:800;font-size:13px;cursor:pointer;">Save changes</button>'
      + '</div></div></div>';
    document.body.appendChild(ov);
    ov.querySelector('#fe-title').value = title || '';
    ov.querySelector('#fe-desc').value = desc || '';
    function close() { ov.remove(); }
    ov.querySelector('#fe-cancel').addEventListener('click', close);
    ov.addEventListener('click', function (e) { if (e.target === ov) close(); });
    var save = ov.querySelector('#fe-save');
    save.addEventListener('click', function () {
      var t = ov.querySelector('#fe-title').value.trim();
      if (!t) { ov.querySelector('#fe-msg').textContent = 'A title is required.'; return; }
      save.disabled = true; save.textContent = 'Saving…';
      Api.patch('/admin/managed-forms/' + id, { title: t, description: ov.querySelector('#fe-desc').value.trim() })
        .then(function () { toast('✏️', 'Form updated', '', '#16A34A'); close(); loadList(el); })
        .catch(function (e) {
          save.disabled = false; save.textContent = 'Save changes';
          ov.querySelector('#fe-msg').textContent = (e && e.message) || 'Could not save.';
        });
    });
  }

  function loadList(el) {
    Api.get('/admin/managed-forms').then(function (d) {
      var forms = (d && d.forms) || [];
      if (!forms.length) { el.innerHTML = '<div style="padding:30px;text-align:center;color:#64748B;background:#F8FAFC;border-radius:12px;">No forms uploaded yet.</div>'; return; }
      el.innerHTML = '<table data-kt-no-kebab="1" style="width:100%;border-collapse:collapse;font-size:13px;background:#fff;border:1px solid #E5E7EB;border-radius:12px;overflow:hidden;">'
        + '<thead><tr style="background:#F9FAFB;">' + ['Form', 'Assigned to', 'Uploaded by', 'Signed', 'Status', ''].map(function (h) { return '<th style="text-align:left;padding:9px 14px;font-size:11px;color:#6B7280;text-transform:uppercase;">' + h + '</th>'; }).join('') + '</tr></thead><tbody>'
        + forms.map(function (f) {
          var auds = (f.audiences || []).map(function (a) { return '<span style="display:inline-block;background:#EFF6FB;color:#1F6080;border-radius:20px;padding:2px 9px;font-size:11px;font-weight:700;margin:1px 3px 1px 0;">' + esc(audLabel(a)) + '</span>'; }).join('');
          return '<tr style="border-top:1px solid #F3F4F6;">'
            + '<td style="padding:9px 14px;"><span class="fm-open" data-u="' + esc(fileUrl(f.file_url)) + '" style="color:#2563EB;font-weight:700;cursor:pointer;">' + esc(f.title) + '</span>' + (f.description ? '<div style="font-size:11.5px;color:#94A3B8;">' + esc(f.description) + '</div>' : '') + '</td>'
            + '<td style="padding:9px 14px;">' + (auds || '—')
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
      el.querySelectorAll('.fm-edit').forEach(function (b) {
        b.addEventListener('click', function () {
          openEditDialog(b.getAttribute('data-id'), b.getAttribute('data-t'), b.getAttribute('data-d'), el);
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
        + '<thead><tr style="background:#F9FAFB;">' + ['Form', 'Signed by', 'Date', ''].map(function (h) { return '<th style="text-align:left;padding:9px 14px;font-size:11px;color:#6B7280;text-transform:uppercase;">' + h + '</th>'; }).join('') + '</tr></thead><tbody>'
        + rows.map(function (r) {
          var who = (((r.first_name || '') + ' ' + (r.last_name || '')).trim()) || r.signer_name || r.email || '—';
          return '<tr style="border-top:1px solid #F3F4F6;">'
            + '<td style="padding:9px 14px;font-weight:600;color:#111827;">' + esc(r.form_title) + '</td>'
            + '<td style="padding:9px 14px;">' + esc(who) + (r.email ? '<div style="font-size:11px;color:#94A3B8;">' + esc(r.email) + '</div>' : '') + '</td>'
            + '<td style="padding:9px 14px;color:#374151;white-space:nowrap;">' + esc(fmtDate(r.signed_at)) + '</td>'
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
        menu.appendChild(item('👁', 'View signed record', false, function () { viewSignoff(r); }));
        menu.appendChild(item('⬇️', 'Download form (PDF)', false, function () { openUrl(fileUrl(r.file_url)); }));
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
      ov.style.cssText = 'position:fixed;inset:0;z-index:2147483001;background:rgba(8,20,36,.5);display:flex;align-items:center;justify-content:center;padding:20px;';
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
