/* Kiddietrac v22p1 - Immunizations
   Per-child immunization records aligned with Ontario CCEYA daycare requirements.
*/
(function (window) {
  'use strict';
  if (!window.KT || !window.KT.Shell) { return; }
  var Shell = window.KT.Shell;
  var Modal = Shell.Modal;

  function token() { return sessionStorage.getItem('kt_token') || localStorage.getItem('kt_token'); }
  function apiBase() { return (window.KT && window.KT.API_BASE) || 'https://api.kiddietrac.com/api/v1'; }
  function getUser() { try { return JSON.parse(sessionStorage.getItem('kt_user') || '{}'); } catch (e) { return {}; } }
  function role() { var u = getUser(); return (u && (u.primary_role || (u.roles && u.roles[0]))) || ''; }

  async function api(method, path, body) {
    var opts = { method: method, headers: { 'Authorization': 'Bearer ' + token(), 'Accept': 'application/json' } };
    if (body !== undefined) { opts.headers['Content-Type'] = 'application/json'; opts.body = JSON.stringify(body); }
    var res = await fetch(apiBase() + path, opts);
    var json = await res.json().catch(function () { return {}; });
    if (!res.ok) throw new Error(json.message || ('API ' + res.status));
    return json;
  }
  function esc(s) { return s == null ? '' : String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;'); }

  /* Uploads go up as multipart, so they cannot use api() above — that one sets a
     JSON content type, and setting any Content-Type by hand on a FormData body
     strips the multipart boundary the server needs to parse it. */
  async function apiUpload(path, formData) {
    var res = await fetch(apiBase() + path, {
      method: 'POST',
      headers: { 'Authorization': 'Bearer ' + token(), 'Accept': 'application/json' },
      body: formData,
    });
    var json = await res.json().catch(function () { return {}; });
    if (!res.ok) { throw new Error(json.message || ('Upload failed (' + res.status + ')')); }
    return json;
  }

  function fmtSize(n) {
    n = Number(n) || 0;
    if (n < 1024) { return n + ' B'; }
    if (n < 1024 * 1024) { return Math.round(n / 1024) + ' KB'; }
    return (n / (1024 * 1024)).toFixed(1) + ' MB';
  }

  /* Date AND time, in the agency's timezone via KT.Fmt when it is available. A
     record that says only "2 Sept" is no use for working out whether it arrived
     before or after the conversation about it. */
  function fmtStamp(s) {
    if (!s) { return '—'; }
    try {
      if (window.KT && KT.Fmt && KT.Fmt.dateTime) { return KT.Fmt.dateTime(s); }
    } catch (e) {}
    try { return new Date(s).toLocaleString('en-CA'); } catch (e) { return String(s); }
  }
  function fmtDate(s) { if (!s) return '-'; try { return new Date(s).toLocaleDateString('en-CA'); } catch (e) { return s; } }

  var COMMON_VACCINES = [
    'DTaP', 'Tdap', 'Hib', 'IPV (Polio)', 'MMR', 'Varicella', 'Rotavirus',
    'Pneumococcal', 'Meningococcal', 'Hepatitis A', 'Hepatitis B', 'Influenza', 'COVID-19'
  ];

  /* The banner every other admin screen has. This one opened straight onto a plain
     <h2> and a table, which is why an empty table read as "the section is broken"
     rather than "there is nothing filed yet". */
  function immHero(subtitle) {
    return '<div class="kt-page-hero" style="background:linear-gradient(135deg,#0F172A 0%,#1F6080 60%,#16637A 100%);' +
      'color:white;border-radius:16px;padding:22px 26px;margin-bottom:18px;">' +
      '<div style="font-size:11px;font-weight:800;letter-spacing:1.4px;opacity:.85;">💉 IMMUNIZATION</div>' +
      '<h2 style="font-size:24px;margin:6px 0 4px;color:white;">Immunizations</h2>' +
      '<div style="opacity:.9;font-size:13px;">' + subtitle + '</div>' +
    '</div>';
  }

  /* One definition of the tab bar for all four views. They were being written out
     three times with the active state hand-set in each, which is how a tab ends up
     looking selected on a screen it did not open. */
  /* ONE SECTION FOR IMMUNISATIONS.

     "Immunizations" held the roster, the dose table and the records families have sent in;
     "Immunization due" held the age schedule and the children measured against it. Two nav
     items, one subject — and the answer to any real question ("is Mylah overdue, and what
     is she measured against?") was split across both.

     The two views from #immun-schedule are hosted here as tabs. They still live in
     screen-v22p58.js, rendered through KT.V22p58.renderImmunSchedule with `embed`, so
     there is one implementation of the schedule editor rather than a copy that drifts.
     (Anthony, 2026-09-10) */
  /* FOUR TABS, NOT SIX.

     "Children" and "Due at age" read the same endpoint and differed only in whether they
     hid the children who were fine — so one of them was always the wrong tab to be on.
     "Due at age" absorbed the other's columns and totals and now lists the whole roster.

     "All records" is gone as well: a dose-by-dose dump of every child at once answered no
     question anybody actually arrives with, and for an agency that keeps immunisation
     records on paper it was simply empty — which reads as missing data rather than as a
     table nobody needed. "Overdue only" keeps the same view where it is useful.
     (Anthony, 2026-09-10) */
  var IMM_TABS = [
    ['due', 'Due at age'],
    ['overdue', 'Overdue only'],
    ['records', 'Records received'],
    ['schedule', 'Schedule defaults'],
  ];
  /* "UPLOAD IT FOR THEM" IS THE THOUGHT AN ADMIN HAS WHILE LOOKING AT AN OVERDUE ROW —
     so the button belongs on the tabs where those rows are, not only on the records tab
     you reach by knowing to go there. Same markup and same handler in all three places;
     the dialog behind it is the same one the child's own Immunization tab opens. */
  /* data-kt-iconized="1" IS THE OPT-OUT, not data-kt-no-icon.

     kt-icon-buttons walks every button under #appMain and its only "leave this alone"
     check is `if (b.dataset.ktIconized) continue;` — the same attribute it stamps on
     everything it has finished with. data-kt-no-icon is read by nobody, which is why
     this button shipped as a bare 📤 glyph, 38px square, in the far-right corner: the
     engine matched "↑ Upload record" to an icon and replaced the label with it. The
     panel's own uploader survives only because its "＋ Upload a record" hits the add-pill
     branch, which keeps the label — luck, not intent.

     Labelled "Upload a record" to match that one, and long enough to read as an action
     rather than decoration. (Anthony, 2026-09-15: "not positioned correctly where a user
     can see it and use it".) */
  function immUploadBtn() {
    return '<button class="kt-imm-upload" data-kt-iconized="1" style="background:#1F6080;color:white;'
      + 'border:none;padding:11px 20px;border-radius:10px;font-weight:700;cursor:pointer;'
      + 'white-space:nowrap;">↑ Upload a record</button>';
  }
  function wireImmUpload(container) {
    container.querySelectorAll('.kt-imm-upload').forEach(function (b) {
      b.addEventListener('click', function () { openStaffUpload(container); });
    });
  }

  /* THE ACTION SITS WITH THE TABS, on one line.

     It used to be its own right-aligned strip between the hero and the tab bar — and
     ensureTopbar() inserts the top bar right there too, so the button ended up stranded
     in the gap between the greeting and the tabs, hard against the right edge of a
     1800px container. Nobody looks there. On the tab row it is where the eye already is,
     it is on every tab, and it wraps under the tabs on a narrow screen instead of
     falling off the side. */
  function immTabBar(active, actionsHtml) {
    return '<div style="display:flex;align-items:center;justify-content:space-between;'
      + 'gap:12px;flex-wrap:wrap;margin-bottom:18px;">'
      + '<div style="display:flex;flex-wrap:wrap;gap:8px;">'
      + IMM_TABS.map(function (t) {
          var on = t[0] === active;
          return '<button id="kt-tab-' + t[0] + '" class="kt-tab' + (on ? ' active' : '') + '" style="padding:8px 16px;' +
            'border:1px solid ' + (on ? '#1F6080' : '#D1D5DB') + ';border-radius:8px;background:' +
            (on ? '#1F6080' : 'white') + ';color:' + (on ? 'white' : '#374151') + ';font-weight:600;cursor:pointer;">' +
            t[1] + '</button>';
        }).join('')
      + '</div>'
      + '<div style="display:flex;gap:8px;flex-wrap:wrap;">' + (actionsHtml || '') + '</div>'
      + '</div>';
  }
  function wireImmTabs(container) {
    var go = {
      overdue: function () { renderDirector(container, { overdue: true }); },
      records: function () { renderRecords(container); },
      due: function () { renderEmbedded(container, 'due'); },
      schedule: function () { renderEmbedded(container, 'defaults'); },
    };
    IMM_TABS.forEach(function (t) {
      var b = container.querySelector('#kt-tab-' + t[0]);
      if (b) { b.addEventListener('click', go[t[0]]); }
    });
  }

  /* The two views that used to be their own screen, hosted under this one's tabs.

     The hero and the tab bar are ours; everything below them is rendered by
     screen-v22p58.js into a host div, so the schedule editor (add / view / edit / delete)
     is the same code wherever it is opened. Degrades to a plain message rather than an
     empty panel if that file has not loaded — a blank area reads as broken data. */
  /* ONE CHILD, FROM THE ROSTER.

     "Upload it for them" is the request an admin has while looking at an overdue row, and
     until now it meant leaving for the child record and finding the attachments tab. Same
     panel the family sees, so the two are never looking at different lists. */
  function openChildImmun(childId, name) {
    var ov = document.createElement('div');
    ov.className = 'kt-scrim';
    ov.setAttribute('data-no-modal-guard', '1');
    ov.style.cssText = 'position:fixed;inset:0;z-index:2147479000;display:flex;align-items:flex-start;'
      + 'justify-content:center;padding:20px;overflow-y:auto;background:rgba(8,20,40,.55);';
    ov.innerHTML =
      '<div style="background:#fff;border-radius:16px;max-width:780px;width:100%;margin:auto;overflow:hidden;'
      + 'box-shadow:0 30px 80px -20px rgba(8,20,40,.6);">'
      + '<div style="padding:18px 22px;border-bottom:1px solid #EEF2F7;display:flex;align-items:center;gap:12px;">'
      +   '<div><div style="font-size:17px;font-weight:800;color:#0F172A;">💉 ' + esc(name || 'Child') + '</div>'
      +   '<div style="font-size:12.5px;color:#64748B;margin-top:2px;">What is due, and the records on file.</div></div>'
      +   '<button class="modal-close" type="button" aria-label="Close" data-kt-iconized="1" style="margin-left:auto;background:#F1F5F9;border:0;border-radius:9px;width:34px;height:34px;font-size:17px;cursor:pointer;color:#475569;">✕</button>'
      + '</div>'
      + '<div id="ip-host" style="padding:18px 22px;max-height:min(72vh,780px);overflow-y:auto;" data-kt-scroll="1"></div>'
      + '</div>';
    /* BELOW THE SHELL MODAL LAYER, ON PURPOSE.
       This overlay is hand-rolled rather than a Shell modal, and it was sitting at
       z-index 2147479000 while KT.Shell.Modal renders its dialogs at 100000. So
       "Upload a record", opened FROM this overlay, appeared 21,000x lower than the
       thing that opened it and rendered BEHIND it: the dialog was there and working,
       it just could not be seen, so filing a record looked like nothing had happened
       and the only visible way out was this overlay's own X. Same family as the
       confirm-under-the-gates bug. It still has to clear the app chrome, which is what
       the huge number was for - 99000 does that and stays under the dialogs. */
    ov.style.zIndex = '99000';
    document.body.appendChild(ov);
    ov.querySelector('.modal-close').addEventListener('click', function () { ov.remove(); });

    var host = ov.querySelector('#ip-host');
    if (KT.immunPanel) {
      KT.immunPanel(host, { id: childId, first_name: name }, { scope: 'director', canUpload: true });
    } else {
      host.innerHTML = '<div style="color:#B91C1C;font-size:13px;">This view could not load. Please reload the page.</div>';
    }
  }
  KT.openChildImmun = openChildImmun;

  async function renderEmbedded(container, pane) {
    container.innerHTML = immHero(pane === 'defaults'
        ? 'What every child is measured against. Changing a row re-computes every child’s status.'
        : 'Children with a dose overdue or due soon, worked out from each date of birth.')
      /* Not on Schedule defaults: that tab is the agency's rulebook, and filing one
         child's card against it is not a thing anybody means to do from there. */
      + immTabBar(pane === 'defaults' ? 'schedule' : 'due',
          pane === 'defaults' ? '' : immUploadBtn())
      + '<div id="imm-embed"><div style="padding:26px;text-align:center;color:#94A3B8;">Loading…</div></div>';
    wireImmTabs(container);
    wireImmUpload(container);

    var host = container.querySelector('#imm-embed');
    var fn = window.KT && KT.V22p58 && KT.V22p58.renderImmunSchedule;
    if (typeof fn !== 'function') {
      host.innerHTML = '<div style="padding:24px;color:#B91C1C;">That view could not be loaded. Please reload the page.</div>';
      return;
    }
    try {
      await fn(host, { embed: true, pane: pane });
    } catch (e) {
      host.innerHTML = '<div style="padding:24px;color:#B91C1C;">Could not load: '
        + String((e && e.message) || e) + '</div>';
    }
  }

  /* Every enrolled child and where they stand — computed from date of birth against
     the agency's schedule, so it is complete whether or not anybody has typed a
     vaccine in. This is the view people mean by "the immunization section": the
     roster with a status against each name. It lived only on the separate
     "Immunization due" screen, so opening "Immunizations" showed the records table
     instead — empty for any agency that records immunizations on paper, which reads
     as missing data rather than an empty table. */

  // ---------------------------------------------------------------
  // Director / agency_admin
  // ---------------------------------------------------------------
  async function renderDirector(container, opts) {
    opts = opts || {};
    container.innerHTML = '<div style="padding:32px;text-align:center;color:#6B7280;">Loading immunization records...</div>';
    var url = '/director/immunizations' + (opts.overdue ? '?overdue=1' : '');
    var resp;
    try { resp = await api('GET', url); }
    catch (e) {
      container.innerHTML = '<div style="padding:24px;color:#DC2626;">Could not load: ' + esc(e.message) + '</div>';
      return;
    }
    var rows = resp.immunizations || [];

    container.innerHTML =
      '<div style="padding:24px;max-width:1800px;">' +
        immHero('Vaccines recorded against each child, dose by dose.') +
        immTabBar(opts.overdue ? 'overdue' : 'all',
          immUploadBtn() +
          '<button id="kt-new-imm" data-kt-iconized="1" style="background:#fff;color:#1F6080;' +
            'border:1px solid #1F6080;padding:11px 20px;border-radius:10px;font-weight:700;' +
            'cursor:pointer;white-space:nowrap;">+ Add a dose</button>') +
        (rows.length === 0
          /* An empty table with nothing but "No records yet." is what made this look
             like lost data. Say what this tab holds and where the roster is. */
          ? '<div style="padding:32px;text-align:center;color:#6B7280;background:white;border-radius:14px;">' +
              (opts.overdue
                ? 'No overdue doses have been recorded.'
                : 'No individual doses have been typed in for this agency yet.<br>' +
                  '<span style="font-size:13px;">Every enrolled child and their status is on the ' +
                  '<strong>Children</strong> tab — that is worked out from each date of birth, ' +
                  'so it is complete whether or not doses have been entered here.</span>') +
            '</div>'
          : '<div style="background:white;border-radius:14px;overflow:hidden;">' +
              '<table style="width:100%;border-collapse:collapse;font-size:14px;">' +
                '<thead><tr style="background:#F9FAFB;text-align:left;">' +
                  '<th style="padding:12px;">Child</th><th style="padding:12px;">Vaccine</th><th style="padding:12px;">Dose</th><th style="padding:12px;">Given</th><th style="padding:12px;">Next due</th><th style="padding:12px;">Status</th><th style="padding:12px;"></th>' +
                '</tr></thead><tbody>' +
                rows.map(immRow).join('') +
                '</tbody></table>' +
            '</div>') +
      '</div>';

    container.querySelector('#kt-new-imm').addEventListener('click', function () { openNewImmModal(container); });
    wireImmTabs(container);
    wireImmUpload(container);
    container.querySelectorAll('[data-edit]').forEach(function (btn) {
      btn.addEventListener('click', function () { openEditImmModal(parseInt(btn.dataset.edit, 10), container); });
    });
    container.querySelectorAll('[data-delete]').forEach(function (btn) {
      btn.addEventListener('click', function () { confirmDelete(parseInt(btn.dataset.delete, 10), container); });
    });
  }

  function immRow(r) {
    var status = '<span style="color:#16A34A;font-weight:600;">Current</span>';
    if (r.exempt) status = '<span style="color:#64748B;font-weight:600;">Exempt</span>';
    else if (r.next_due_on && new Date(r.next_due_on) < new Date()) status = '<span style="color:#DC2626;font-weight:700;">OVERDUE</span>';
    else if (r.next_due_on) status = '<span style="color:#F59E0B;font-weight:600;">Due ' + fmtDate(r.next_due_on) + '</span>';
    return '<tr style="border-top:1px solid #E5E7EB;">' +
      '<td style="padding:12px;font-weight:600;">' + esc(r.child_name) + '</td>' +
      '<td style="padding:12px;">' + esc(r.vaccine) + '</td>' +
      '<td style="padding:12px;color:#6B7280;">' + esc(r.dose_label || '-') + '</td>' +
      '<td style="padding:12px;">' + fmtDate(r.administered_on) + '</td>' +
      '<td style="padding:12px;">' + fmtDate(r.next_due_on) + '</td>' +
      '<td style="padding:12px;">' + status + '</td>' +
      '<td style="padding:12px;text-align:right;">' +
        '<button data-edit="' + r.id + '" style="padding:5px 10px;background:#E5E7EB;color:#374151;border:none;border-radius:6px;font-size:12px;cursor:pointer;margin-right:4px;">Edit</button>' +
        '<button data-delete="' + r.id + '" style="padding:5px 10px;background:#FEE2E2;color:#991B1B;border:none;border-radius:6px;font-size:12px;cursor:pointer;">Delete</button>' +
      '</td>' +
    '</tr>';
  }

  async function openNewImmModal(container, prefill) {
    prefill = prefill || {};
    var children = [];
    try {
      var en = await api('GET', '/director/enrollments');
      children = (en.enrollments || []).filter(function (e) { return e.status === 'enrolled' || e.status === 'active'; });
    } catch (e) {}

    var body = document.createElement('div');
    body.innerHTML =
      '<div style="display:grid;gap:10px;">' +
        '<label style="font-size:12px;font-weight:700;color:#6B7280;">Child</label>' +
        '<select id="kt-child" style="padding:10px 12px;border:1px solid #D1D5DB;border-radius:8px;font-size:14px;">' +
          children.map(function (c) { return '<option value="' + c.child_id + '"' + (prefill.child_id == c.child_id ? ' selected' : '') + '>' + esc((c.first_name || '') + ' ' + (c.last_name || '')) + '</option>'; }).join('') +
        '</select>' +
        '<label style="font-size:12px;font-weight:700;color:#6B7280;">Vaccine</label>' +
        '<select id="kt-vaccine" style="padding:10px 12px;border:1px solid #D1D5DB;border-radius:8px;font-size:14px;">' +
          COMMON_VACCINES.map(function (v) { return '<option' + (prefill.vaccine === v ? ' selected' : '') + '>' + esc(v) + '</option>'; }).join('') +
          '<option value="__other__">Other (specify)</option>' +
        '</select>' +
        '<input id="kt-vaccine-other" placeholder="Specify vaccine name" style="padding:10px 12px;border:1px solid #D1D5DB;border-radius:8px;font-size:14px;display:none;">' +
        '<div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;">' +
          '<div><label style="font-size:12px;font-weight:700;color:#6B7280;">Dose label</label><input id="kt-dose" placeholder="1st, 2nd, booster" value="' + esc(prefill.dose_label || '') + '" style="width:100%;padding:10px 12px;border:1px solid #D1D5DB;border-radius:8px;font-size:14px;"></div>' +
          '<div><label style="font-size:12px;font-weight:700;color:#6B7280;">Date administered</label><input type="date" id="kt-admin" value="' + esc(prefill.administered_on || '') + '" style="width:100%;padding:10px 12px;border:1px solid #D1D5DB;border-radius:8px;font-size:14px;"></div>' +
        '</div>' +
        '<div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;">' +
          '<div><label style="font-size:12px;font-weight:700;color:#6B7280;">Next due (optional)</label><input type="date" id="kt-next" value="' + esc(prefill.next_due_on || '') + '" style="width:100%;padding:10px 12px;border:1px solid #D1D5DB;border-radius:8px;font-size:14px;"></div>' +
          '<div><label style="font-size:12px;font-weight:700;color:#6B7280;">Lot number</label><input id="kt-lot" value="' + esc(prefill.lot_number || '') + '" style="width:100%;padding:10px 12px;border:1px solid #D1D5DB;border-radius:8px;font-size:14px;"></div>' +
        '</div>' +
        '<label style="font-size:12px;font-weight:700;color:#6B7280;">Clinic / administered by</label>' +
        '<input id="kt-clinic" placeholder="Family doctor or clinic name" value="' + esc(prefill.clinic_name || '') + '" style="padding:10px 12px;border:1px solid #D1D5DB;border-radius:8px;font-size:14px;">' +
        '<label style="display:flex;align-items:center;gap:6px;font-size:13px;margin-top:6px;"><input type="checkbox" id="kt-exempt"' + (prefill.exempt ? ' checked' : '') + '> Medical or religious exemption</label>' +
        '<input id="kt-exempt-reason" placeholder="Exemption reason (if applicable)" value="' + esc(prefill.exemption_reason || '') + '" style="padding:10px 12px;border:1px solid #D1D5DB;border-radius:8px;font-size:14px;">' +
        '<div id="kt-msg" style="font-size:13px;min-height:20px;"></div>' +
      '</div>';

    body.querySelector('#kt-vaccine').addEventListener('change', function (e) {
      body.querySelector('#kt-vaccine-other').style.display = e.target.value === '__other__' ? '' : 'none';
    });

    Modal.open({
      title: prefill.id ? 'Edit immunization record' : 'Add immunization record',
      body: body,
      actions: [
        { label: 'Cancel', style: 'btn-secondary' },
        {
          label: 'Save',
          style: 'btn-primary',
          handler: async function () {
            var vac = body.querySelector('#kt-vaccine').value;
            if (vac === '__other__') vac = body.querySelector('#kt-vaccine-other').value.trim();
            if (!vac) { body.querySelector('#kt-msg').textContent = 'Vaccine name required'; body.querySelector('#kt-msg').style.color = '#DC2626'; return false; }
            var payload = {
              child_id: parseInt(body.querySelector('#kt-child').value, 10),
              vaccine: vac,
              dose_label: body.querySelector('#kt-dose').value.trim() || null,
              administered_on: body.querySelector('#kt-admin').value || null,
              next_due_on: body.querySelector('#kt-next').value || null,
              lot_number: body.querySelector('#kt-lot').value.trim() || null,
              clinic_name: body.querySelector('#kt-clinic').value.trim() || null,
              exempt: body.querySelector('#kt-exempt').checked,
              exemption_reason: body.querySelector('#kt-exempt-reason').value.trim() || null,
            };
            try {
              if (prefill.id) await api('PATCH', '/director/immunizations/' + prefill.id, payload);
              else await api('POST', '/director/immunizations', payload);
              renderDirector(container);
            } catch (e) {
              body.querySelector('#kt-msg').textContent = 'Error: ' + e.message;
              body.querySelector('#kt-msg').style.color = '#DC2626';
              return false;
            }
          },
        },
      ],
    });
  }

  async function openEditImmModal(id, container) {
    var resp;
    try { resp = await api('GET', '/director/immunizations?child_id=0'); } catch (e) {}
    // We don't have a single-record endpoint; fetch the list and find it.
    var all;
    try { all = await api('GET', '/director/immunizations'); } catch (e) { return; }
    var rec = (all.immunizations || []).find(function (r) { return r.id === id; });
    if (!rec) return;
    openNewImmModal(container, rec);
  }

  function confirmDelete(id, container) {
    Modal.confirm({
      title: 'Delete immunization record',
      message: 'Permanently delete this immunization record? This cannot be undone.',
      confirmLabel: 'Delete',
      destructive: true,
      onConfirm: async function () {
        await api('DELETE', '/director/immunizations/' + id);
        renderDirector(container);
      },
    });
  }

  // ---------------------------------------------------------------
  // Parent view
  // ---------------------------------------------------------------
  /* One child's card: what the centre has recorded, plus what this parent has sent
     in and a way to send more.

     The vaccine table above is entered by staff and is read-only here. The part
     below is the parent's own: until now there was no way for a family to hand a
     record over inside the portal at all, so it arrived by email or on paper at
     drop-off, which is how a record ends up on nobody's file. */

  /* Fetch with the bearer token and hand the browser a blob, rather than linking at
     /storage directly: the file is behind an access check, and the mobile WebView
     cannot always open a raw storage link. */

  /* The documents side of immunizations: the cards and printouts that have actually
     been handed in, newest first, saying who filed each one and exactly when.

     Separate from the vaccine table because it answers a different question. That
     table is what the centre has recorded; this is what arrived, and from whom —
     the thing an admin is looking at when they ask whether a family has sent
     anything in yet. */
  async function renderRecords(container) {
    container.innerHTML = '<div style="padding:32px;text-align:center;color:#6B7280;">Loading records…</div>';
    var d;
    try { d = await api('GET', '/admin/immunization-records'); }
    catch (e) {
      container.innerHTML = '<div style="padding:24px;color:#DC2626;">Could not load: ' + esc(e.message) + '</div>';
      return;
    }
    var rows = (d && d.records) || [];
    var fromParents = rows.filter(function (r) { return r.uploaded_by_parent; }).length;

    container.innerHTML =
      '<div style="padding:24px;max-width:1800px;">' +
        immHero('Records handed in by families and filed by staff.') +
        immTabBar('records', immUploadBtn()) +
        '<div style="display:flex;gap:10px;flex-wrap:wrap;margin-bottom:14px;font-size:13px;">' +
          '<span style="padding:6px 12px;border-radius:20px;background:#E0F2FE;color:#075985;font-weight:700;">' +
            fromParents + ' from parents</span>' +
          '<span style="padding:6px 12px;border-radius:20px;background:#F1F5F9;color:#475569;font-weight:700;">' +
            (rows.length - fromParents) + ' added by staff</span>' +
          '<span style="padding:6px 12px;border-radius:20px;background:' + (d.outstanding ? '#FEF3C7;color:#92400E' : '#DCFCE7;color:#166534') + ';font-weight:700;">' +
            (d.outstanding || 0) + ' still outstanding</span>' +
        '</div>' +
        (rows.length === 0
          ? '<div style="padding:32px;text-align:center;color:#6B7280;background:white;border-radius:14px;">Nothing has been handed in yet.</div>'
          : '<div style="background:white;border-radius:14px;overflow:hidden;"><div style="overflow-x:auto;">' +
              '<table style="width:100%;border-collapse:collapse;font-size:14px;min-width:720px;">' +
                '<thead><tr style="background:#F9FAFB;text-align:left;">' +
                  '<th style="padding:12px;">Child</th><th style="padding:12px;">File</th>' +
                  '<th style="padding:12px;">Sent in by</th><th style="padding:12px;">Date &amp; time</th>' +
                  '<th style="padding:12px;"></th>' +
                '</tr></thead><tbody>' +
                rows.map(function (r) {
                  return '<tr style="border-top:1px solid #E5E7EB;">' +
                    '<td style="padding:12px;font-weight:600;">' + esc(r.child_name || '—') + '</td>' +
                    '<td style="padding:12px;">' + esc(r.title || 'Immunization record') +
                      '<div style="color:#94A3B8;font-size:12px;">' + esc(fmtSize(r.file_size)) + '</div></td>' +
                    '<td style="padding:12px;">' + esc(r.uploaded_by || '—') +
                      '<div><span style="display:inline-block;margin-top:3px;padding:2px 8px;border-radius:20px;font-size:11px;font-weight:700;background:' +
                      (r.uploaded_by_parent ? '#E0F2FE;color:#075985' : '#F1F5F9;color:#475569') + ';">' +
                      (r.uploaded_by_parent ? 'Parent' : 'Staff') + '</span></div></td>' +
                    '<td style="padding:12px;white-space:nowrap;">' + esc(fmtStamp(r.uploaded_at)) + '</td>' +
                    '<td style="padding:12px;text-align:right;"><button type="button" data-open="' + r.id +
                      '" data-child="' + r.child_id + '" data-print="' + esc(r.print_url || '') + '" ' +
                      'style="padding:6px 12px;border-radius:8px;border:1px solid #CBD5E1;' +
                      'background:white;font-size:12.5px;font-weight:600;cursor:pointer;">View</button></td>' +
                  '</tr>';
                }).join('') +
              '</tbody></table></div></div>') +
      '</div>';

    wireImmTabs(container);
    wireImmUpload(container);
    container.querySelectorAll('[data-open]').forEach(function (b) {
      b.addEventListener('click', function () {
        openStaffRecord(b.getAttribute('data-child'), b.getAttribute('data-open'),
          b.getAttribute('data-print') || null);
      });
    });
  }

  /* Filing a record on a child's behalf. A parent uploading is the common path, but
     records still arrive at the door on paper and in the office inbox, and until now
     the only way to get one onto a child's file was the generic documents tab, where
     it would not be categorised as an immunization record and so would not count.

     THE SAME WIZARD THE CHILD'S OWN TAB OPENS (KT.immunFileRecord), with a child picker
     on the front because "upload it for them" is the thought an admin has while looking
     at an overdue row. This screen used to have its own smaller dialog — child and file,
     nothing else — so a record filed from here landed on the child's record and left the
     very list it was filed from unchanged, which is the same complaint in miniature:
     something was uploaded and nothing appeared to happen. (Anthony, 2026-09-15) */
  async function openStaffUpload(container) {
    /* THE ROSTER IS THE ONE THIS SCREEN IS ALREADY SHOWING.

       This asked /director/enrollments, which is CENTRE-scoped — and a platform admin
       holds no centre, so viewing an agency returned an empty list and the button did
       nothing at all. /immunization/due-report is the endpoint the "Due at age" tab is
       built from: agency-scoped, every enrolled child, the same names on screen behind
       the dialog. Enrollments stays as the fallback for a role the report refuses.
       (2026-09-15) */
    var roster = [];
    try {
      var rep = await api('GET', '/immunization/due-report');
      roster = (rep.data || []).map(function (r) {
        return { id: r.child_id, name: (r.child_name || '').trim() || ('Child #' + r.child_id) };
      });
    } catch (e) {}
    if (!roster.length) {
      try {
        var en = await api('GET', '/director/enrollments');
        roster = (en.enrollments || [])
          .filter(function (e) { return e.status === 'enrolled' || e.status === 'active'; })
          .map(function (c) {
            return {
              id: c.child_id,
              name: ((c.first_name || '') + ' ' + (c.last_name || '')).trim() || ('Child #' + c.child_id),
            };
          });
      } catch (e2) {}
    }

    if (!roster.length) {
      /* Never alert() — it blocks the whole renderer, and a dead button with no
         explanation is what this was. Say it where the person is looking. */
      if (window.KT && KT.toast) {
        KT.toast('💉', 'No children to file against',
          'No enrolled children were returned for this agency.', '#B45309');
      }
      return;
    }
    roster.sort(function (a, b) { return a.name.localeCompare(b.name); });

    var opened = window.KT && KT.immunFileRecord && KT.immunFileRecord({
      scope: 'director',
      children: roster,
      onFiled: function () { renderRecords(container); },
    });
    if (opened) { return; }

    /* Fallback: the panel file is not loaded. File the document alone rather than
       leaving the button dead — a record on file is still better than one in an inbox,
       and the doses can be recorded from the child's tab. */
    var body = document.createElement('div');
    body.innerHTML =
      '<div style="display:grid;gap:10px;">' +
        '<label style="font-size:12px;font-weight:700;color:#6B7280;">Child</label>' +
        '<select id="kt-imm-child" style="padding:10px 12px;border:1px solid #D1D5DB;border-radius:8px;font-size:14px;">' +
          roster.map(function (c) {
            return '<option value="' + c.id + '">' + esc(c.name) + '</option>';
          }).join('') +
        '</select>' +
        '<label style="font-size:12px;font-weight:700;color:#6B7280;">File</label>' +
        '<input id="kt-imm-file" type="file" accept=".pdf,.jpg,.jpeg,.png,.webp,.heic,image/*,application/pdf" ' +
          'style="padding:9px;border:1px solid #D1D5DB;border-radius:8px;font-size:13px;">' +
        '<span style="font-size:12px;color:#6B7280;">PDF or photo, up to 10 MB.</span>' +
        '<div id="kt-imm-out" style="font-size:13px;min-height:16px;"></div>' +
      '</div>';

    Modal.open({
      title: 'Upload an immunization record',
      body: body,
      actions: [
        { label: 'Cancel', style: 'btn-secondary' },
        { label: 'Upload', style: 'btn-primary', handler: async function () {
          var out = body.querySelector('#kt-imm-out');
          var sel = body.querySelector('#kt-imm-child');
          var f = body.querySelector('#kt-imm-file').files[0];
          if (!f) { out.style.color = '#DC2626'; out.textContent = 'Choose a file first.'; return false; }
          if (f.size > 10 * 1024 * 1024) {
            out.style.color = '#DC2626';
            out.textContent = 'That file is ' + fmtSize(f.size) + ' — the limit is 10 MB.';
            return false;
          }
          out.style.color = '#64748B';
          out.textContent = 'Uploading…';
          try {
            var fd = new FormData();
            fd.append('file', f);
            fd.append('title', f.name);
            await apiUpload('/director/children/' + sel.value + '/immunization-records', fd);
            renderRecords(container);
            return true;
          } catch (e) {
            out.style.color = '#DC2626';
            out.textContent = e.message || 'Upload failed.';
            return false;
          }
        } },
      ],
    });
  }

  /* Staff read through /director/, parents through /parent/ — same controller and the
     same access check, different route prefix. */
  async function openStaffRecord(childId, docId, printUrl) {
    try {
      var res = await fetch(apiBase() + '/director/children/' + childId + '/immunization-records/' + docId + '/download',
        { headers: { 'Authorization': 'Bearer ' + token() } });
      if (!res.ok) { throw new Error('Could not open that file (' + res.status + ')'); }
      var blob = await res.blob();
      /* The portal's own panel, not a new tab — in the APK a new tab is an EXTERNAL
         browser that loses the session. Same viewer the child's Immunization tab uses. */
      if (!(window.KT && KT.viewBlob && KT.viewBlob(blob, {
        title: 'Immunization record',
        label: 'Immunization record',
        filename: 'immunization-record',
        externalPrint: printUrl ? function () { return printUrl; } : null,
      }))) {
        var url = URL.createObjectURL(blob);
        window.open(url, '_blank');
        setTimeout(function () { URL.revokeObjectURL(url); }, 60000);
      }
    } catch (e) {
      alert(e.message);
    }
  }

  async function renderParent(container) {
    container.innerHTML = '<div style="padding:32px;text-align:center;color:#6B7280;">Loading...</div>';
    var children;
    try { children = await api('GET', '/parent/children'); }
    catch (e) { container.innerHTML = '<div style="padding:24px;color:#DC2626;">Could not load: ' + esc(e.message) + '</div>'; return; }
    var kids = (children && children.children) || [];

    container.innerHTML = '';
    var wrap = document.createElement('div');
    wrap.style.cssText = 'padding:24px;max-width:1800px;';
    wrap.innerHTML = '<h2 style="font-size:24px;margin:0 0 4px;">Immunizations</h2>'
      + '<p style="color:#6B7280;margin:0 0 18px;font-size:13px;">'
      + 'What is still needed, what the centre has on file, and a place to send a record in. '
      + 'Your child\'s educator and the centre are told as soon as one arrives.</p>';

    if (kids.length === 0) {
      wrap.innerHTML += '<p style="color:#6B7280;">No children on record.</p>';
      container.appendChild(wrap);
      return;
    }

    /* A CARD PER CHILD, LED BY WHAT IS OUTSTANDING.

       This screen used to show only the doses the centre had already typed in — which for
       an agency that keeps immunisation records on paper is an empty table, right after
       an email telling the family their records are missing. The panel works the due list
       out from the child's date of birth against the schedule, so it is complete whether
       or not anybody has typed anything, and puts the upload button beside it.
       (Anthony, 2026-09-10) */
    kids.forEach(function (k) {
      var card = document.createElement('div');
      card.style.cssText = 'background:#fff;border-radius:14px;padding:18px;margin-bottom:14px;';
      var head = document.createElement('h3');
      head.style.cssText = 'margin:0 0 12px;font-size:17px;';
      head.textContent = ((k.preferred_name || k.first_name || '') + ' ' + (k.last_name || '')).trim();
      card.appendChild(head);

      var body = document.createElement('div');
      card.appendChild(body);
      wrap.appendChild(card);

      if (KT.immunPanel) {
        KT.immunPanel(body, k, { scope: 'parent', canUpload: true });
      } else {
        body.innerHTML = '<div style="color:#B91C1C;font-size:13px;">This view could not load. Please reload the page.</div>';
      }
    });
    container.appendChild(wrap);
  }

  function render(container) {
    var r = role();
    /* Opens on the children, not the records table. The roster with a status against
       each name is what people come to this screen for; the dose-by-dose table is the
       detail behind it, and for an agency that keeps paper records it is empty. */
    if (r === 'agency_admin' || r === 'centre_director') return renderEmbedded(container, 'due');
    if (r === 'guardian') return renderParent(container);
    container.innerHTML = '<div style="padding:24px;color:#6B7280;">Immunizations are managed by the centre director. Ask your director for the latest list.</div>';
  }

  if (Shell && Shell.registerScreen) {
    ['agency_admin', 'centre_director', 'educator', 'guardian'].forEach(function (r) {
      Shell.registerScreen(r + ':immunizations', render);
    });
    /* #immun-schedule was its own screen and is now a tab of this one. Anything still
       linking to it — a bookmark, the phone launcher, an old email — lands on the tab it
       used to be rather than on a dead hash. */
    ['agency_admin', 'centre_director', 'platform_admin'].forEach(function (r) {
      Shell.registerScreen(r + ':immun-schedule', function (container) {
        renderEmbedded(container, 'due');
      });
    });
  }
  window.KT = window.KT || {};
  window.KT.Immunizations = { render: render };
})(window);
