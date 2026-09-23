/* ═══════════════════════════════════════════════════════════════════
   KiddieTrac — one immunization panel for one child.

   WHAT IS OUTSTANDING, AND A PLACE TO PUT IT, IN THE SAME BREATH.

   The pieces have all existed for a while and never met. /immunization/child/{id}/status
   works out, from the child's date of birth against the agency's schedule, exactly which
   doses are overdue and which fall due soon — and nothing rendered it. The parent screen
   showed only what the centre had already typed in, which for an agency that keeps records
   on paper is nothing at all: a family was told "your records are missing" by email and
   then shown an empty table.

   So this panel is the answer to both halves at once: here is what we still need, and
   here is the button. It is deliberately ONE implementation used from three places —
   the parent's own screen, the child record, and the admin's immunization section — so
   a parent and a director are always looking at the same list. A second copy would be the
   point at which they start disagreeing about which dose is late.

   Public: KT.immunPanel(host, child, opts)
     child : { id, first_name, last_name, preferred_name }
     opts  : { scope: 'parent' | 'director', canUpload: bool, onChange: fn }

   `scope` picks the route pair, not the data — /parent/... and /director/... return the
   same shape and each enforces its own access. Nothing here decides who may see what.
   (Anthony, 2026-09-10)
   ═══════════════════════════════════════════════════════════════════ */
(function (window) {
  'use strict';
  var KT = (window.KT = window.KT || {});

  var TH = 'padding:9px 12px;font-size:11px;font-weight:800;color:#64748B;text-transform:uppercase;'
    + 'letter-spacing:.4px;white-space:nowrap;';
  var TD = 'padding:10px 12px;vertical-align:top;';
  var BTN = 'padding:6px 12px;border-radius:8px;border:1px solid #CBD5E1;background:#fff;'
    + 'font-size:12.5px;font-weight:700;cursor:pointer;color:#0F172A;';
  var BTN_PRIMARY = 'padding:6px 12px;border-radius:8px;border:1px solid #1F6FB2;background:#1F6FB2;'
    + 'font-size:12.5px;font-weight:700;cursor:pointer;color:#fff;';

  function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"]/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c];
    });
  }
  function api() { return KT.API_BASE || 'https://api.kiddietrac.com/api/v1'; }
  function token() {
    try { return sessionStorage.getItem('kt_token') || localStorage.getItem('kt_token') || ''; }
    catch (e) { return ''; }
  }
  function fmtSize(n) {
    n = Number(n) || 0;
    if (n < 1024) { return n + ' B'; }
    if (n < 1048576) { return Math.round(n / 1024) + ' KB'; }
    return (n / 1048576).toFixed(1) + ' MB';
  }
  /* Dates from this endpoint are DATE-ONLY strings. new Date('2026-09-15') parses as UTC
     and prints the 14th for anybody west of Greenwich, so the parts are read directly. */
  function fmtDay(iso) {
    if (!iso) { return '—'; }
    var p = String(iso).slice(0, 10).split('-');
    if (p.length !== 3) { return String(iso); }
    try {
      return new Date(+p[0], +p[1] - 1, +p[2])
        .toLocaleDateString('en-CA', { year: 'numeric', month: 'short', day: 'numeric' });
    } catch (e) { return String(iso); }
  }

  var STATE = {
    overdue:  ['Overdue',  '#991B1B', '#FEE2E2', '#FECACA'],
    due_soon: ['Due soon', '#92400E', '#FEF3C7', '#FDE68A'],
    done:     ['Recorded', '#166534', '#DCFCE7', '#BBF7D0'],
    exempt:   ['Exempt',   '#3730A3', '#E0E7FF', '#C7D2FE'],
    pending:  ['Not yet due', '#475569', '#F1F5F9', '#E2E8F0'],
  };
  function chip(st) {
    var v = STATE[st] || STATE.pending;
    return '<span style="font-size:11px;font-weight:800;color:' + v[1] + ';background:' + v[2]
      + ';border:1px solid ' + v[3] + ';border-radius:999px;padding:2px 9px;white-space:nowrap;">'
      + v[0] + '</span>';
  }

  function childName(c) {
    return ((c.preferred_name || c.first_name || '') + ' ' + (c.last_name || '')).trim() || 'This child';
  }

  /**
   * Render the panel into `host`.
   *
   * Each half loads independently and says so on its own if it fails. A status lookup that
   * errors must not take the upload button down with it — the whole point of the panel is
   * that a family can send the record in.
   */
  /* ───────── FILING A RECORD, AND SAYING WHAT IS ON IT ─────────

     Filing the card and reading it were two jobs on two screens, and that gap is where
     records sat for weeks while the child still showed overdue: somebody uploaded a PDF,
     the compliance list did not move, and nobody could tell whether it had been read or
     merely received.

     So it is one dialog: the document, the doses it accounts for, and a note for whatever
     the card does not say. The server writes all of it in one request, so a filed record
     can never exist without the decision that went with it.

     ONE dialog, two doors. The child's Immunization tab opens it for the child it is
     already showing; the Immunizations section opens it with a child picker, because
     "upload it for them" is the thought an admin has while looking at an overdue row.
     That section used to have its own smaller dialog which filed the document and asked
     nothing — so a record filed from there never moved the very list it was filed from.

     Parents keep the plain uploader. Deciding that a smudged line means "DTaP-IPV-Hib,
     2nd dose" is a clinical judgement and it is the centre's to make; the server enforces
     that too, this is only the half you can see. (Anthony, 2026-09-15)

     KT.immunFileRecord({ scope, child?, children?, status?, onFiled? })
       child    : { id, first_name, last_name, preferred_name } — fixed, no picker
       children : [{ id, name }] — offered in a picker when no child is fixed
       status   : a /immunization/child/{id}/status payload already in hand
       onFiled  : called after a successful filing
     Returns false when it cannot open, so a caller can fall back. */
  KT.immunFileRecord = function (opts) {
    opts = opts || {};
    var M = window.KT && KT.Shell && KT.Shell.Modal;
    if (!M) { return false; }

    var scope = opts.scope === 'parent' ? 'parent' : 'director';
    var child = opts.child || null;
    var roster = opts.children || [];
    if (!child && !roster.length) { return false; }

    var form = document.createElement('div');
    form.innerHTML =
      (child ? '' :
        '<label style="display:block;font-weight:700;font-size:13px;color:#0F172A;margin-bottom:6px;">'
        + 'Child <span style="color:#DC2626;">*</span></label>'
        + '<select class="fr-child" style="width:100%;box-sizing:border-box;padding:9px 11px;'
        +   'border:1px solid #CBD5E1;border-radius:9px;font-size:13px;margin-bottom:16px;">'
        +   roster.map(function (c) {
              return '<option value="' + esc(String(c.id)) + '">' + esc(c.name) + '</option>';
            }).join('')
        + '</select>')

      + '<label style="display:block;font-weight:700;font-size:13px;color:#0F172A;margin-bottom:6px;">'
      + 'The record <span style="color:#DC2626;">*</span></label>'
      + '<input type="file" class="fr-file" accept=".pdf,.jpg,.jpeg,.png,.webp,.heic" '
      +   'style="display:block;width:100%;font-size:13px;margin-bottom:4px;">'
      + '<div style="color:#64748B;font-size:12px;margin-bottom:16px;">'
      +   'PDF or a photo of the card, up to 10 MB.</div>'

      + '<label style="display:block;font-weight:700;font-size:13px;color:#0F172A;margin-bottom:6px;">'
      + (scope === 'parent' ? 'What does this record show?' : 'What does this record cover?') + '</label>'
      + '<div style="color:#64748B;font-size:12px;margin-bottom:8px;">'
      +   (scope === 'parent'
            /* Said plainly, because the difference is the whole safeguard: a parent is
               telling the centre what is on the page, not clearing their own child's
               compliance. Promising otherwise would be a lie the first time somebody
               checked whether their child still showed overdue. */
            ? 'Tick each immunisation you can see on the record. This helps '
              + 'the centre read it \u2014 they will confirm each one before it counts. '
              + 'A date is optional; leave it blank if the card is unclear.'
            : 'Tick each dose the record shows. A date is optional \u2014 leave it blank if the '
              + 'card is unclear, the dose still counts as recorded. Anything the card shows '
              + 'that is not on the list can be added underneath.') + '</div>'
      + '<div class="fr-doses" style="border:1px solid #E2E8F0;border-radius:10px;max-height:260px;'
      +   'overflow:auto;" data-kt-scroll="1"></div>'
      + '<div class="fr-donenote" style="color:#64748B;font-size:12px;margin:6px 0 0;"></div>'

      /* A CARD IS NOT LIMITED TO THE AGENCY'S SCHEDULE.

         The list above is immunization_schedule — what this agency measures children
         against. A real card routinely carries more: an influenza shot, a meningococcal
         or COVID dose, a travel vaccine, a catch-up dose beyond the schedule's last row.
         With only the ticklist, those were unrecordable here, so the person filing the
         card had to file it, leave, and go type them in somewhere else — which is the
         same split this wizard exists to close.

         The server already accepted any {vaccine, dose_label, administered_on}; it never
         checked the row against the schedule. This is only the half you can see.

         Not on the schedule means not part of the due/overdue computation, which is
         correct — an agency that does not require influenza should not start showing
         children as overdue for it. The dose is recorded, appears in the child's history
         and on the record it was read from. (Anthony, 2026-09-15) */
      + '<div class="fr-extras"></div>'
      + '<button type="button" class="fr-add" data-kt-iconized="1" '
      +   'style="margin-top:8px;background:#fff;color:#1F6080;border:1px dashed #94A3B8;'
      +   'border-radius:9px;padding:8px 14px;font-size:13px;font-weight:700;cursor:pointer;">'
      +   '+ Add a dose that is not listed</button>'
      + '<datalist id="fr-vaccines"></datalist>'

      + '<label style="display:block;font-weight:700;font-size:13px;color:#0F172A;margin:16px 0 6px;">'
      + 'Notes</label>'
      + '<textarea class="fr-notes" rows="3" maxlength="2000" '
      +   'placeholder="Anything the record does not say — a faded line, a dose the clinic is confirming…" '
      +   'style="width:100%;box-sizing:border-box;padding:9px 11px;border:1px solid #CBD5E1;'
      +   'border-radius:9px;font-size:13px;font-family:inherit;resize:vertical;"></textarea>'
      + '<div class="fr-err" style="color:#B91C1C;font-size:12.5px;margin-top:10px;"></div>';

    var listEl = form.querySelector('.fr-doses');
    var pickEl = form.querySelector('.fr-child');
    var noteEl = form.querySelector('.fr-donenote');
    var extraEl = form.querySelector('.fr-extras');
    var addEl = form.querySelector('.fr-add');
    var listId = 'fr-vaccines-' + Math.random().toString(36).slice(2, 9);
    form.querySelector('#fr-vaccines').id = listId;   // unique: two dialogs can coexist

    /* Suggest the vaccines this agency already names, so the common case is one click and
       the spelling matches the schedule's — a free-text "DTaP IPV Hib" would otherwise
       sit alongside "DTaP-IPV-Hib" as a different vaccine forever. Free text is still
       allowed; a datalist suggests, it does not restrict. */
    function fillVaccineList(items) {
      var seen = {}, out = [];
      (items || []).forEach(function (i) {
        var v = (i.vaccine || '').trim();
        if (v && !seen[v.toLowerCase()]) { seen[v.toLowerCase()] = 1; out.push(v); }
      });
      var dl = form.querySelector('#' + listId);
      if (dl) {
        dl.innerHTML = out.map(function (v) { return '<option value="' + esc(v) + '"></option>'; }).join('');
      }
    }

    function addExtraRow() {
      var row = document.createElement('div');
      row.className = 'fr-extra';
      row.style.cssText = 'display:flex;align-items:center;gap:8px;margin-top:8px;flex-wrap:wrap;';
      row.innerHTML =
        '<input type="text" class="fr-x-vac" list="' + listId + '" placeholder="Vaccine" '
        +   'style="flex:1 1 170px;min-width:0;padding:7px 10px;border:1px solid #CBD5E1;'
        +   'border-radius:8px;font-size:13px;">'
        + '<input type="text" class="fr-x-dose" placeholder="Dose (e.g. 1st dose)" maxlength="40" '
        +   'style="flex:1 1 140px;min-width:0;padding:7px 10px;border:1px solid #CBD5E1;'
        +   'border-radius:8px;font-size:13px;">'
        + '<input type="date" class="fr-x-date" '
        +   'style="flex:none;width:142px;padding:6px 8px;border:1px solid #CBD5E1;'
        +   'border-radius:8px;font-size:12.5px;">'
        + '<button type="button" class="fr-x-del" data-kt-iconized="1" aria-label="Remove this dose" '
        +   'title="Remove this dose" style="flex:none;background:#fff;border:1px solid #E2E8F0;'
        +   'border-radius:8px;width:32px;height:32px;cursor:pointer;color:#B91C1C;font-size:15px;">✕</button>';
      row.querySelector('.fr-x-del').addEventListener('click', function () { row.remove(); });
      extraEl.appendChild(row);
      try { row.querySelector('.fr-x-vac').focus(); } catch (e) {}
    }

    addEl.addEventListener('click', addExtraRow);

    function currentChildId() {
      return child ? Number(child.id) : Number(pickEl && pickEl.value) || 0;
    }

    function paintDoses(items) {
      items = items || [];
      listEl.innerHTML = '';
      noteEl.textContent = '';
      fillVaccineList(items);
      if (!items.length) {
        listEl.innerHTML = '<div style="padding:14px;color:#64748B;font-size:13px;">'
          + 'No immunisation schedule has been set up for this agency, so there is nothing '
          + 'to tick. The record can still be filed.</div>';
        return;
      }
      var done = 0;
      items.forEach(function (i, idx) {
        var already = (i.status === 'done' || i.status === 'exempt');
        if (already) { done++; }
        var row = document.createElement('label');
        row.style.cssText = 'display:flex;align-items:center;gap:10px;padding:8px 11px;'
          + 'border-top:' + (idx ? '1px solid #F1F5F9' : 'none') + ';font-size:13px;'
          + (already ? 'opacity:0.55;' : 'cursor:pointer;');
        row.innerHTML =
          '<input type="checkbox" class="fr-tick"' + (already ? ' checked disabled' : '') + ' '
          +   'data-vaccine="' + esc(i.vaccine) + '" data-dose="' + esc(i.dose_label || '') + '" '
          +   'style="width:17px;height:17px;flex:none;">'
          + '<span style="flex:1;min-width:0;">'
          +   '<span style="font-weight:600;color:#0F172A;">' + esc(i.vaccine) + '</span> '
          +   '<span style="color:#64748B;">' + esc(i.dose_label || '') + '</span>'
          +   (already ? '<span style="color:#166534;font-size:12px;"> · already on file</span>' : '')
          + '</span>'
          + '<input type="date" class="fr-date" disabled '
          +   'style="flex:none;width:142px;padding:4px 7px;border:1px solid #CBD5E1;'
          +   'border-radius:7px;font-size:12.5px;">';
        var tick = row.querySelector('.fr-tick');
        var date = row.querySelector('.fr-date');
        // The date box only means something once the dose is ticked; leaving it live
        // invites a date against a dose nobody claimed.
        if (!already) {
          tick.addEventListener('change', function () {
            date.disabled = !tick.checked;
            if (!tick.checked) { date.value = ''; }
          });
        }
        listEl.appendChild(row);
      });
      if (done) {
        noteEl.textContent = done + ' dose' + (done === 1 ? ' is' : 's are')
          + ' already on file and cannot be ticked again here.';
      }
    }

    function loadDoses() {
      var id = currentChildId();
      if (!id) { return; }
      listEl.innerHTML = '<div style="padding:14px;color:#94A3B8;font-size:13px;">'
        + 'Working out what is due…</div>';
      KT.Api.get('/immunization/child/' + id + '/status').then(function (d) {
        paintDoses((d && d.items) || []);
      }).catch(function (e) {
        /* The schedule failing must never block the filing — the document is the part
           that cannot wait, and the doses can be recorded afterwards. */
        listEl.innerHTML = '<div style="padding:14px;color:#9A3412;font-size:13px;">'
          + 'The dose list could not be loaded' + (e && e.message ? ' (' + esc(e.message) + ')' : '')
          + '. The record can still be filed.</div>';
        noteEl.textContent = '';
      });
    }

    if (pickEl) { pickEl.addEventListener('change', loadDoses); }
    if (opts.status && opts.status.items) { paintDoses(opts.status.items); } else { loadDoses(); }

    M.open({
      title: 'File an immunization record' + (child ? ' — ' + childName(child) : ''),
      body: form,
      large: true,
      actions: [
        { label: 'Cancel' },
        {
          label: 'File record',
          primary: true,
          busyLabel: 'Filing…',
          handler: function () {
            var err = form.querySelector('.fr-err');
            err.textContent = '';
            var id = currentChildId();
            if (!id) { err.textContent = 'Choose the child this record belongs to.'; return false; }
            var f = form.querySelector('.fr-file').files[0];
            if (!f) { err.textContent = 'Choose the record to file.'; return false; }
            if (f.size > 10 * 1024 * 1024) {
              err.textContent = 'That file is larger than 10 MB.'; return false;
            }

            var doses = [];
            var ticks = form.querySelectorAll('.fr-tick');
            for (var k = 0; k < ticks.length; k++) {
              var t = ticks[k];
              if (t.disabled || !t.checked) { continue; }
              doses.push({
                vaccine: t.getAttribute('data-vaccine'),
                dose_label: t.getAttribute('data-dose'),
                administered_on: (t.parentNode.querySelector('.fr-date') || {}).value || null,
              });
            }

            /* The typed-in ones. A row with no vaccine is somebody who clicked Add and
               changed their mind — dropped silently. A row with a date but no vaccine is
               a half-filled row, and saying so beats recording nothing without a word. */
            var extras = form.querySelectorAll('.fr-extra');
            for (var x = 0; x < extras.length; x++) {
              var vac = (extras[x].querySelector('.fr-x-vac').value || '').trim();
              var dl = (extras[x].querySelector('.fr-x-dose').value || '').trim();
              var dt = extras[x].querySelector('.fr-x-date').value || null;
              if (!vac) {
                if (dl || dt) {
                  err.textContent = 'One of the added doses has no vaccine name.';
                  return false;
                }
                continue;
              }
              doses.push({ vaccine: vac, dose_label: dl, administered_on: dt });
            }

            var fd = new FormData();
            fd.append('file', f);
            fd.append('title', 'Immunization record');
            fd.append('notes', form.querySelector('.fr-notes').value || '');
            /* THE ONLY LINE THAT DECIDES WHICH OF THE TWO THIS IS.

               Identical payload, different field: `doses` is the centre recording what it
               read, `covers` is the uploader saying what they see. The server refuses
               `doses` from a guardian regardless, so this is defence in depth rather than
               the defence itself — but sending the right field means a parent never meets
               that 403 in normal use. */
            if (doses.length) {
              fd.append(scope === 'parent' ? 'covers' : 'doses', JSON.stringify(doses));
            }

            return KT.Api.postForm('/' + scope + '/children/' + id + '/immunization-records', fd)
              .then(function (res) {
                // The caller re-reads rather than patching its own DOM: the server
                // decides what was actually recorded (a dose already on file is skipped).
                try { if (opts.onFiled) { opts.onFiled(res, id); } } catch (e) {}
                try {
                  if (window.Dom && Dom.toast) {
                    Dom.toast((res && res.message) || 'Record filed.', 'success');
                  }
                } catch (e) {}
              }).catch(function (e) {
                err.textContent = (e && e.message) || 'Could not file the record.';
                return false;
              });
          },
        },
      ],
    });
    return true;
  };

  /**
   * TRANSCRIBE A RECORD THAT ARRIVED BARE.
   *
   * Same ticklist as the uploader, against a document that is already on file, so
   * nobody has to re-upload the card just to attach what it says. Pre-ticked from
   * what the uploader claimed: their ticks are a reading to check, which is faster
   * than starting from a blank list, and every one still has to be confirmed here
   * before it becomes a dose.
   */
  KT.immunAddDetails = function (opts) {
    opts = opts || {};
    var child = opts.child;
    var r = opts.record;
    if (!child || !r) { return false; }
    var M = window.KT && KT.Shell && KT.Shell.Modal;
    if (!M) { return; }

    var claimed = {};
    (r.covers_claimed || []).forEach(function (c) {
      claimed[String(c.vaccine || '').toLowerCase() + '|' + String(c.dose_label || '').toLowerCase()] =
        c.administered_on || '';
    });

    var box = document.createElement('div');
    box.innerHTML =
      '<div style="font-size:13px;color:#475569;margin-bottom:10px;">'
      +   'Tick what this record shows. Each one becomes a recorded dose against '
      +   esc(childName(child)) + '.'
      + '</div>'
      + '<div class="dt-list" style="border:1px solid #E2E8F0;border-radius:10px;max-height:320px;overflow:auto;"></div>'
      + '<div class="dt-note" style="font-size:12px;color:#64748B;margin-top:8px;"></div>'
      + '<div class="dt-err" style="color:#B91C1C;font-size:12.5px;margin-top:8px;"></div>';

    var listEl2 = box.querySelector('.dt-list');
    var noteEl2 = box.querySelector('.dt-note');
    listEl2.innerHTML = '<div style="padding:14px;color:#94A3B8;font-size:13px;">Working out what is due\u2026</div>';

    KT.Api.get('/immunization/child/' + child.id + '/status').then(function (d) {
      var items = (d && d.items) || [];
      listEl2.innerHTML = '';
      if (!items.length) {
        listEl2.innerHTML = '<div style="padding:14px;color:#64748B;font-size:13px;">'
          + 'No immunisation schedule is set up for this agency, so there is nothing to tick.</div>';
        return;
      }
      var pre = 0;
      items.forEach(function (i, idx) {
        var already = (i.status === 'done' || i.status === 'exempt');
        var key = String(i.vaccine || '').toLowerCase() + '|' + String(i.dose_label || '').toLowerCase();
        var wasClaimed = Object.prototype.hasOwnProperty.call(claimed, key);
        if (wasClaimed && !already) { pre++; }
        var row = document.createElement('label');
        row.style.cssText = 'display:flex;align-items:center;gap:10px;padding:8px 11px;'
          + 'border-top:' + (idx ? '1px solid #F1F5F9' : 'none') + ';font-size:13px;'
          + (already ? 'opacity:0.55;' : 'cursor:pointer;');
        row.innerHTML =
          '<input type="checkbox" class="dt-tick"' + (already ? ' checked disabled' : (wasClaimed ? ' checked' : '')) + ' '
          +   'data-vaccine="' + esc(i.vaccine) + '" data-dose="' + esc(i.dose_label || '') + '" '
          +   'style="width:17px;height:17px;flex:none;">'
          + '<span style="flex:1;min-width:0;">'
          +   '<span style="font-weight:600;color:#0F172A;">' + esc(i.vaccine) + '</span> '
          +   '<span style="color:#64748B;">' + esc(i.dose_label || '') + '</span>'
          +   (already ? '<span style="color:#166534;font-size:12px;"> \u00b7 already on file</span>'
                       : (wasClaimed ? '<span style="color:#92400E;font-size:12px;"> \u00b7 uploader ticked this</span>' : ''))
          + '</span>'
          + '<input type="date" class="dt-date" ' + (already || !wasClaimed ? 'disabled ' : '')
          +   'value="' + esc(wasClaimed ? (claimed[key] || '') : '') + '" '
          +   'style="flex:none;width:142px;padding:4px 7px;border:1px solid #CBD5E1;'
          +   'border-radius:7px;font-size:12.5px;">';
        var tick = row.querySelector('.dt-tick');
        var date = row.querySelector('.dt-date');
        if (!already) {
          tick.addEventListener('change', function () {
            date.disabled = !tick.checked;
            if (!tick.checked) { date.value = ''; }
          });
        }
        listEl2.appendChild(row);
      });
      noteEl2.textContent = pre
        ? pre + ' pre-ticked from what the uploader said the card shows \u2014 check each one.'
        : 'Nothing was claimed on upload, so start from the card itself.';
    }).catch(function () {
      listEl2.innerHTML = '<div style="padding:14px;color:#9A3412;font-size:13px;">'
        + 'The schedule could not be loaded. Try again in a moment.</div>';
    });

    M.open({
      /* `body`, not `content`, and no `subtitle` — the dialog renders a string or a
         Node from `body` and ignores anything else, so a wrong key opens a titled
         dialog with two buttons and NOTHING between them. */
      title: 'Add the details — ' + (r.title || 'Immunization record'),
      body: box,
      large: true,
      actions: [
        { label: 'Cancel' },
        {
          label: 'Record doses',
          primary: true,
          busyLabel: 'Recording\u2026',
          handler: function () {
            var err = box.querySelector('.dt-err');
            err.textContent = '';
            var doses = [];
            var ticks = box.querySelectorAll('.dt-tick');
            for (var k = 0; k < ticks.length; k++) {
              var t = ticks[k];
              if (t.disabled || !t.checked) { continue; }
              doses.push({
                vaccine: t.getAttribute('data-vaccine'),
                dose_label: t.getAttribute('data-dose') || null,
                administered_on: (t.parentNode.querySelector('.dt-date') || {}).value || null,
              });
            }
            if (!doses.length) { err.textContent = 'Tick at least one dose, or cancel.'; return false; }

            return KT.Api.post('/director/children/' + child.id
                  + '/immunization-records/' + r.id + '/details', { doses: doses })
              .then(function (res) {
                try {
                  if (window.Dom && Dom.toast) { Dom.toast((res && res.message) || 'Doses recorded.', 'success'); }
                } catch (e) {}
                /* Re-read rather than patch: the server decides what was actually
                   written (a dose already on file is skipped, not duplicated). The
                   caller owns that refresh - this function is shared by the child's
                   panel and the agency-wide records table, which reload differently. */
                try { if (opts.onDone) { opts.onDone(res); } } catch (e) {}
              })
              .catch(function (e) {
                err.textContent = (e && e.message) || 'Could not record those doses.';
                return false;
              });
          },
        },
      ],
    });
    return true;
  };

  KT.immunPanel = function (host, child, opts) {
    opts = opts || {};
    var scope = opts.scope === 'director' ? 'director' : 'parent';
    var base = '/' + scope + '/children/' + child.id;
    var canUpload = opts.canUpload !== false;

    /* TWO SUB-TABS, because they answer two different questions.

       Stacked, the filed records sat under a fourteen-row schedule table and a collapsed
       "show the rest" — so the answer to "did the record I uploaded arrive?" was below
       the fold of the answer to "what is still outstanding?". Somebody who has just
       filed a card is looking for the first thing and lands on the second.

       role="tablist" is load-bearing as well as correct: kt-icon-buttons refuses to
       reach inside one, so these keep their labels instead of becoming glyphs.
       (Anthony, 2026-09-15) */
    host.innerHTML =
      '<div class="ip-tabs" role="tablist" style="display:flex;gap:4px;border-bottom:1px solid #E5E7EB;'
      +   'margin-bottom:16px;flex-wrap:wrap;"></div>'
      + '<div class="ip-due"><div style="padding:18px;color:#94A3B8;font-size:13px;">Working out what is due…</div></div>'
      + '<div class="ip-recs" style="margin-top:16px;"></div>';

    var tabsEl = host.querySelector('.ip-tabs');
    var dueEl = host.querySelector('.ip-due');
    var recEl = host.querySelector('.ip-recs');

    var PANES = [
      ['due', '💉 What is due'],
      ['recs', scope === 'parent' ? '📄 Records you have sent' : '📄 Records on file'],
    ];
    var pane = 'due';
    var recCount = null;          // null until the records have actually loaded

    function paintTabs() {
      tabsEl.innerHTML = PANES.map(function (t) {
        var on = t[0] === pane;
        var label = t[1] + (t[0] === 'recs' && recCount !== null ? ' (' + recCount + ')' : '');
        return '<button type="button" role="tab" data-pane="' + t[0] + '" aria-selected="' + on + '" '
          + 'style="background:none;border:none;border-bottom:3px solid '
          +   (on ? '#1F6080' : 'transparent') + ';color:' + (on ? '#1F6080' : '#64748B') + ';'
          +   'font-weight:700;font-size:13.5px;padding:9px 14px;cursor:pointer;margin-bottom:-1px;'
          +   'white-space:nowrap;">' + label + '</button>';
      }).join('');
      Array.prototype.forEach.call(tabsEl.querySelectorAll('[data-pane]'), function (b) {
        b.addEventListener('click', function () { showPane(b.getAttribute('data-pane')); });
      });
    }

    function showPane(which) {
      pane = which === 'recs' ? 'recs' : 'due';
      // display, not [hidden] — a global rule can out-rank [hidden] in this portal.
      dueEl.style.display = pane === 'due' ? '' : 'none';
      recEl.style.display = pane === 'recs' ? '' : 'none';
      recEl.style.marginTop = pane === 'recs' ? '0' : '16px';
      paintTabs();
    }

    /* Exposed so the filing dialog can land the reader on what they just filed —
       "show me the uploaded record" is the whole reason they were in the dialog. */
    host.__ktShowRecords = function () { showPane('recs'); };

    paintTabs();
    showPane('due');

    /* The dose checklist in the filing dialog is the SAME list the panel is already
       showing. Kept here rather than re-fetched: a second read could disagree with what
       is on screen, and ticking a dose that is not the one you are looking at is the
       worst possible failure for a health record. */
    var lastStatus = null;

    loadDue();
    loadRecords();

    /* ───────── what is needed ───────── */
    function loadDue() {
      KT.Api.get('/immunization/child/' + child.id + '/status').then(function (d) {
        lastStatus = d || {};
        paintDue(lastStatus);
      }).catch(function (e) {
        dueEl.innerHTML = '<div style="background:#FFF7ED;border:1px solid #FED7AA;border-radius:12px;padding:14px;'
          + 'font-size:13px;color:#9A3412;">The due list could not be worked out just now'
          + (e && e.message ? ' (' + esc(e.message) + ')' : '') + '. You can still send a record in below.</div>';
      });
    }

    function paintDue(d) {
      var items = (d.items || []);
      var over = items.filter(function (i) { return i.status === 'overdue'; });
      var soon = items.filter(function (i) { return i.status === 'due_soon'; });
      var done = items.filter(function (i) { return i.status === 'done' || i.status === 'exempt'; });

      if (!items.length) {
        dueEl.innerHTML = '<div style="background:#F8FAFC;border:1px solid #E2E8F0;border-radius:12px;padding:14px;'
          + 'font-size:13px;color:#475569;">No immunisation schedule has been set up for this agency yet, '
          + 'so nothing can be worked out as due.</div>';
        return;
      }

      /* WHAT TO ASK FOR DEPENDS ON WHETHER A RECORD HAS ARRIVED.

         The doses below are genuinely still unrecorded either way — a filed PDF is not a
         recorded dose, and the overdue count stays exactly as it was. But when the card
         IS on file, asking for it again is wrong twice over: it tells whoever uploaded it
         that nothing happened, and it sends a parent back to the clinic for a printout
         they already sent. The outstanding work at that point is transcribing what is on
         file, and that is a job for the centre, not the family. (Anthony, 2026-09-15) */
      /* A quiet one-liner for the states that are not asking for anything. Whether a
         record is on file should never be something you have to scroll to find out. */
      function filedNote(d) {
        var rec = d && d.record_on_file;
        if (!rec) { return ''; }
        return '<div style="margin-top:6px;opacity:0.85;">Record on file, sent in '
          + esc(fmtDay(rec.uploaded_at))
          + (rec.uploaded_by ? ' by ' + esc(rec.uploaded_by) : '') + '.</div>';
      }

      function overdueAsk(d) {
        var rec = d && d.record_on_file;
        if (rec) {
          var when = fmtDay(rec.uploaded_at);
          var who = rec.uploaded_by ? ' by ' + esc(rec.uploaded_by) : '';
          return scope === 'parent'
            ? 'You sent a record in on ' + esc(when) + '. The centre has not entered these '
              + 'doses from it yet — there is no need to send it again.'
            : 'A record was filed on ' + esc(when) + who + ', and these doses have not been '
              + 'entered from it yet. Open it under <strong>Records on file</strong> below '
              + 'and record the doses it shows.';
        }
        return scope === 'parent'
          ? 'If ' + esc(childName(child)) + ' has had these, send a photo of the immunisation '
            + 'card or a printout from your clinic and the centre will update the record.'
          : 'Nothing has been recorded against these, and no card or printout has been filed '
            + 'either. Upload one if the family has sent it in.';
      }

      /* THE HEADLINE FIRST. A parent opening this wants one sentence, not a table to
         count. The table is underneath for whoever needs the detail. */
      var head;
      if (over.length) {
        head = '<div style="background:#FEF2F2;border:1px solid #FECACA;border-left:4px solid #DC2626;border-radius:12px;'
          + 'padding:14px 16px;"><div style="font-weight:800;color:#991B1B;font-size:14.5px;">'
          + over.length + ' dose' + (over.length === 1 ? '' : 's') + ' overdue for ' + esc(childName(child)) + '</div>'
          + '<div style="color:#7F1D1D;font-size:13px;margin-top:4px;line-height:1.55;">'
          + overdueAsk(d)
          + '</div></div>';
      } else if (soon.length) {
        head = '<div style="background:#FFFBEB;border:1px solid #FDE68A;border-left:4px solid #F59E0B;border-radius:12px;'
          + 'padding:14px 16px;"><div style="font-weight:800;color:#92400E;font-size:14.5px;">'
          + soon.length + ' dose' + (soon.length === 1 ? '' : 's') + ' due in the next two months</div>'
          + '<div style="color:#78350F;font-size:13px;margin-top:4px;">Nothing is late — this is a heads-up.' + filedNote(d) + '</div></div>';
      } else {
        head = '<div style="background:#F0FDF4;border:1px solid #BBF7D0;border-left:4px solid #16A34A;border-radius:12px;'
          + 'padding:14px 16px;"><div style="font-weight:800;color:#166534;font-size:14.5px;">'
          + 'Nothing outstanding</div>'
          + '<div style="color:#14532D;font-size:13px;margin-top:4px;">'
          + done.length + ' of ' + items.length + ' doses on the schedule are recorded.'
          + filedNote(d) + '</div></div>';
      }

      /* Outstanding first, then the rest — and the rest collapsed, because a fourteen-row
         schedule read top to bottom buries the two lines that need doing. */
      var outstanding = over.concat(soon);
      var rest = items.filter(function (i) { return i.status !== 'overdue' && i.status !== 'due_soon'; });

      dueEl.innerHTML = head
        + (outstanding.length ? '<div style="margin-top:14px;">' + table(outstanding, true) + '</div>' : '')
        + (rest.length
            ? '<details style="margin-top:12px;"><summary style="cursor:pointer;font-size:13px;font-weight:700;color:#1F6080;">'
              + 'Show the rest of the schedule (' + rest.length + ')</summary>'
              + '<div style="margin-top:10px;">' + table(rest, false) + '</div></details>'
            : '');
    }

    function table(rows, urgent) {
      return '<div style="overflow-x:auto;border:1px solid #E5E7EB;border-radius:12px;background:#fff;">'
        + '<table style="width:100%;border-collapse:collapse;font-size:13px;">'
        + '<thead><tr style="background:#F9FAFB;text-align:left;">'
        +   '<th style="padding:9px 12px;font-size:11px;font-weight:800;color:#64748B;text-transform:uppercase;">Vaccine</th>'
        +   '<th style="padding:9px 12px;font-size:11px;font-weight:800;color:#64748B;text-transform:uppercase;">Dose</th>'
        +   '<th style="padding:9px 12px;font-size:11px;font-weight:800;color:#64748B;text-transform:uppercase;">'
        +     (urgent ? 'Was due' : 'Due') + '</th>'
        +   '<th style="padding:9px 12px;font-size:11px;font-weight:800;color:#64748B;text-transform:uppercase;">Status</th>'
        + '</tr></thead><tbody>'
        + rows.map(function (i) {
            return '<tr style="border-top:1px solid #F1F5F9;">'
              + '<td style="padding:9px 12px;font-weight:600;color:#111827;">' + esc(i.vaccine) + '</td>'
              + '<td style="padding:9px 12px;color:#475569;">' + esc(i.dose_label || '—') + '</td>'
              + '<td style="padding:9px 12px;color:#475569;white-space:nowrap;">'
              +   (i.status === 'done' && i.administered_on
                    ? 'given ' + esc(fmtDay(i.administered_on))
                    : esc(fmtDay(i.due_date)))
              + '</td>'
              + '<td style="padding:9px 12px;">' + chip(i.status) + '</td>'
              + '</tr>';
          }).join('')
        + '</tbody></table></div>';
    }

    /* The child's own tab already knows which child it is and already holds the dose
       list it is showing, so it hands both over rather than making the dialog fetch
       them again — a second read could disagree with what is on screen, and ticking a
       dose that is not the one you are looking at is the worst failure a health record
       has. Everything else lives in KT.immunFileRecord, because the Immunizations
       section opens the same dialog. */
    function fileRecordModal() {
      return KT.immunFileRecord({
        scope: scope,
        child: child,
        status: lastStatus,
        onFiled: function () {
          loadDue();
          loadRecords();
          // Land on the record that was just filed, not on the list of what is still due.
          showPane('recs');
        },
      });
    }

    /* ───────── what has been sent in ───────── */
    function loadRecords() {
      recEl.innerHTML = '<div style="padding:12px 0;color:#94A3B8;font-size:13px;">Loading records…</div>';
      KT.Api.get(base + '/immunization-records').then(function (d) {
        paintRecords((d && d.records) || []);
      }).catch(function (e) {
        recEl.innerHTML = '<div style="padding:12px 0;color:#B91C1C;font-size:13px;">'
          + 'Records could not be loaded: ' + esc((e && e.message) || '') + '</div>';
        if (canUpload) { recEl.appendChild(uploader()); }
      });
    }

    function paintRecords(records) {
      recCount = records.length;
      paintTabs();
      recEl.innerHTML = '<div style="font-weight:800;font-size:13px;color:#0F172A;margin-bottom:8px;">'
        + (scope === 'parent' ? 'Records you have sent in' : 'Records on file') + '</div>';

      if (!records.length) {
        var none = document.createElement('div');
        none.style.cssText = 'color:#64748B;padding:6px 0;font-size:13px;';
        none.textContent = 'Nothing on file yet — a clear photo of the immunisation card is enough.';
        recEl.appendChild(none);
        if (canUpload) { recEl.appendChild(uploader()); }
        return;
      }

      /* A TABLE, and data-kt-row-actions so it gets the standard kebab.
         This panel is drawn in two places: inside #appMain on the child's own tab, and
         inside a hand-rolled overlay appended to <body> when it is opened from the
         Immunizations screen. kt-row-actions only ever swept #appMain, so the second one
         kept its inline buttons; the attribute opts it in wherever it happens to be. */
      var wrap = document.createElement('div');
      wrap.style.cssText = 'overflow-x:auto;border:1px solid #E2E8F0;border-radius:10px;background:#fff;';
      var tbl = document.createElement('table');
      tbl.setAttribute('data-kt-row-actions', '1');
      tbl.style.cssText = 'width:100%;border-collapse:collapse;font-size:13px;';

      tbl.innerHTML =
        '<thead><tr style="background:#F8FAFC;text-align:left;">'
        + '<th style="' + TH + '">Record</th>'
        + '<th style="' + TH + '">What it shows</th>'
        + '<th style="' + TH + '">Added</th>'
        + '<th style="' + TH + '"></th>'
        + '</tr></thead>';

      var tb = document.createElement('tbody');
      records.forEach(function (r) {
        var tr = document.createElement('tr');
        tr.style.cssText = 'border-top:1px solid #F1F5F9;';

        var pending = !(r.doses && r.doses.length);
        var shows = pending
          ? '<span style="background:#FEF3C7;border:1px solid #FDE68A;border-radius:999px;'
            +   'padding:1px 8px;font-weight:700;color:#92400E;font-size:12px;">Details pending</span>'
            + ((r.covers_claimed && r.covers_claimed.length)
                ? '<div style="color:#64748B;font-size:12px;margin-top:4px;">uploader listed '
                    + r.covers_claimed.map(function (x) {
                        return esc(String(x.vaccine || '') + ' ' + String(x.dose_label || '')).trim();
                      }).join(', ') + ' — not confirmed</div>'
                : '<div style="color:#64748B;font-size:12px;margin-top:4px;">the record is on file, the doses are not</div>')
          : '<span style="color:#166534;font-size:12.5px;">✓ '
            + r.doses.map(function (x) {
                return esc(String(x.vaccine || '') + ' ' + String(x.dose_label || '')).trim()
                  + (x.administered_on ? ' (' + esc(fmtDay(x.administered_on)) + ')' : '');
              }).join(' · ') + '</span>';

        tr.innerHTML =
          '<td style="' + TD + '">'
          +   '<div style="font-weight:600;color:#0F172A;">' + esc(r.title || 'Immunization record') + '</div>'
          +   '<div style="color:#94A3B8;font-size:12px;margin-top:2px;">' + esc(fmtSize(r.file_size)) + '</div>'
          +   (r.notes
                ? '<div style="margin-top:4px;color:#475569;font-size:12px;white-space:pre-wrap;">'
                    + '<span style="color:#94A3B8;">Note:</span> ' + esc(r.notes) + '</div>'
                : '')
          + '</td>'
          + '<td style="' + TD + '">' + shows + '</td>'
          + '<td style="' + TD + 'white-space:nowrap;">'
          +   '<div style="color:#334155;">' + esc(String(r.uploaded_at || '').slice(0, 10)) + '</div>'
          +   '<div style="color:#94A3B8;font-size:12px;margin-top:2px;">'
          +     esc(r.uploaded_by || '') + (r.uploaded_by_parent ? '' : (r.uploaded_by ? ' · centre' : 'the centre'))
          +   '</div>'
          + '</td>';

        /* Plain buttons in the LAST cell; the platform collapses them. */
        var actions = document.createElement('td');
        actions.style.cssText = TD + 'text-align:right;white-space:nowrap;';

        var view = document.createElement('button');
        view.type = 'button';
        view.dataset.ktIconized = '1';
        view.textContent = 'View';
        view.style.cssText = BTN;
        view.addEventListener('click', function (e) { e.stopPropagation(); openRecord(r, view); });
        actions.appendChild(view);

        if (scope === 'director' && pending) {
          var fill = document.createElement('button');
          fill.type = 'button';
          fill.dataset.ktIconized = '1';
          // Its own glyph: kt-row-actions picks a fallback icon from the label and tests
          // view/open/DETAILS before add, so this would otherwise be a second eye.
          fill.textContent = '➕ Add details';
          fill.style.cssText = 'margin-left:6px;' + BTN_PRIMARY;
          fill.addEventListener('click', function (e) { e.stopPropagation(); openDetailsEditor(r); });
          actions.appendChild(fill);
        }

        tr.appendChild(actions);
        tb.appendChild(tr);
      });

      tbl.appendChild(tb);
      wrap.appendChild(tbl);
      recEl.appendChild(wrap);
      if (canUpload) { recEl.appendChild(uploader()); }

      /* Built after an await, so the sweep has already run for this render. */
      if (window.KT && KT.sweepRowActions) { setTimeout(KT.sweepRowActions, 0); }
    }

    /* ONE implementation, called from two places: this panel and the agency-wide
       records table on the Immunizations screen. A second copy would be two
       implementations of one clinical rule. */
    function openDetailsEditor(r) {
      return KT.immunAddDetails({
        child: child,
        record: r,
        onDone: function () { loadRecords(); loadDue(); try { if (opts.onChange) { opts.onChange(); } } catch (e) {} },
      });
    }

    /**
     * Streamed through the API rather than opened by its /storage path.
     *
     * A child's health record is not something to hand out on a guessable URL, and the
     * mobile wrapper cannot reliably follow a signed redirect — the same reasoning as
     * the parent Documents screen.
     */
    function openRecord(r, btn) {
      var was = btn.textContent;
      btn.disabled = true; btn.textContent = 'Opening…';
      /* No pre-opened tab any more. It was there because a popup blocker eats a window
         opened after an await — but the portal's own panel is not a popup, so the whole
         problem goes away, and with it the blank tab left behind whenever the fetch
         failed. */
      fetch(api() + base + '/immunization-records/' + r.id + '/download', {
        headers: { Authorization: 'Bearer ' + token() },
      }).then(function (res) {
        if (!res.ok) { throw new Error('HTTP ' + res.status); }
        return res.blob();
      }).then(function (b) {
        if (!(window.KT && KT.viewBlob && KT.viewBlob(b, {
          title: r.title || 'Immunization record',
          label: 'Immunization record',
          filename: 'immunization-record',
          // Printing from the APK means handing the file to a real browser, which has
          // no session — so it gets the signed URL, not the blob.
          externalPrint: r.print_url ? function () { return r.print_url; } : null,
        }))) {
          // The viewer is not loaded — better a new tab than nothing.
          var u = URL.createObjectURL(b);
          window.open(u, '_blank');
          setTimeout(function () { URL.revokeObjectURL(u); }, 60000);
        }
      }).catch(function () {
        if (KT.toast) { KT.toast('⚠️', 'Could not open', 'That record could not be opened.', '#B91C1C'); }
      }).finally(function () {
        btn.disabled = false; btn.textContent = was;
      });
    }

    /* A hidden file input behind a normal-looking button, so a phone offers the camera
       and the picker the way it does everywhere else. */
    function uploader() {
      var box = document.createElement('div');
      box.style.cssText = 'margin-top:12px;padding-top:12px;border-top:1px dashed #E5E7EB;'
        + 'display:flex;align-items:center;gap:10px;flex-wrap:wrap;';

      var input = document.createElement('input');
      input.type = 'file';
      input.accept = '.pdf,.jpg,.jpeg,.png,.webp,.heic';
      input.style.display = 'none';

      var btn = document.createElement('button');
      btn.type = 'button';
      btn.textContent = scope === 'parent' ? '📷 Send in a record' : '＋ Upload a record';
      btn.dataset.ktIconized = '1';   // see the note on the View button above
      btn.style.cssText = 'padding:10px 18px;border-radius:10px;border:0;background:#1F6080;color:#fff;'
        + 'font-size:13.5px;font-weight:800;cursor:pointer;';

      var msg = document.createElement('span');
      msg.style.cssText = 'font-size:12.5px;color:#64748B;';

      /* EVERYONE GETS THE DIALOG NOW — but it asks each of them a different question.

         Staff tick what they have READ off the card, and that writes doses. A parent
         ticks what they believe the card SHOWS, and that writes a claim the centre then
         confirms. Same list, same card, two different acts, and the server keeps them
         apart: `doses` is refused from a guardian, `covers` is not.

         The plain file picker remains the fallback for anyone whose shell Modal did not
         load, so handing the record over never depends on the dialog. */
      btn.addEventListener('click', function () {
        if (fileRecordModal()) { return; }
        input.click();
      });
      input.addEventListener('change', function () {
        var f = input.files && input.files[0];
        input.value = '';                       // so the same file can be picked twice
        if (!f) { return; }
        if (f.size > 10 * 1024 * 1024) {
          msg.style.color = '#B91C1C';
          msg.textContent = 'That file is over 10 MB — a photo is usually well under.';
          return;
        }
        btn.disabled = true;
        var was = btn.textContent;
        btn.textContent = 'Sending…';
        msg.style.color = '#64748B';
        msg.textContent = '';

        var fd = new FormData();
        fd.append('file', f, f.name);
        /* Multipart, so the bytes travel as bytes — this host rejects a body with no file
           part just over 1 MB and a phone photo is several. */
        KT.Api.postForm(base + '/immunization-records', fd).then(function () {
          btn.disabled = false; btn.textContent = was;
          if (KT.toast) {
            KT.toast('💉', 'Sent', scope === 'parent'
              ? 'Thank you — the centre has been told.'
              : 'Filed on the child’s record.', '#16A34A');
          }
          loadRecords();
          /* The due list is worked out from typed doses, not from the file, so it does not
             change here — but the caller may want to refresh a count around us. */
          if (typeof opts.onChange === 'function') { opts.onChange(); }
        }).catch(function (e) {
          btn.disabled = false; btn.textContent = was;
          msg.style.color = '#B91C1C';
          msg.textContent = (e && e.message) || 'That did not send. Please try again.';
        });
      });

      box.appendChild(btn);
      box.appendChild(input);
      box.appendChild(msg);
      box.appendChild(Object.assign(document.createElement('span'), {
        textContent: 'PDF or a photo, up to 10 MB.',
      })).style.cssText = 'font-size:12px;color:#94A3B8;';
      return box;
    }
  };
})(window);
