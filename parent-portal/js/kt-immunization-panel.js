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
  KT.immunPanel = function (host, child, opts) {
    opts = opts || {};
    var scope = opts.scope === 'director' ? 'director' : 'parent';
    var base = '/' + scope + '/children/' + child.id;
    var canUpload = opts.canUpload !== false;

    host.innerHTML =
      '<div class="ip-due"><div style="padding:18px;color:#94A3B8;font-size:13px;">Working out what is due…</div></div>'
      + '<div class="ip-recs" style="margin-top:16px;"></div>';

    var dueEl = host.querySelector('.ip-due');
    var recEl = host.querySelector('.ip-recs');

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

    /* ───────── filing a record, and saying what is on it ─────────

       FILING THE CARD AND READING IT WERE TWO JOBS ON TWO SCREENS, and that gap is
       exactly where records sat for weeks while the child still showed overdue: somebody
       uploaded a PDF, the compliance list did not move, and nobody could tell whether it
       had been read or merely received.

       So for staff it is one dialog: the document, the doses it accounts for, and a note
       for whatever the card does not say. The server writes all of it in one request, so
       a filed record can never exist without the decision that went with it.

       Parents keep the plain uploader. Deciding that a smudged line means "DTaP-IPV-Hib,
       2nd dose" is a clinical judgement and it is the centre's to make — the server
       enforces that too, this is only the half you can see. (Anthony, 2026-09-15) */
    function fileRecordModal() {
      var M = window.KT && KT.Shell && KT.Shell.Modal;
      if (!M) { return null; }

      var items = (lastStatus && lastStatus.items) || [];
      var form = document.createElement('div');

      var doneCount = items.filter(function (i) {
        return i.status === 'done' || i.status === 'exempt';
      }).length;

      form.innerHTML =
        '<label style="display:block;font-weight:700;font-size:13px;color:#0F172A;margin-bottom:6px;">'
        + 'The record <span style="color:#DC2626;">*</span></label>'
        + '<input type="file" class="fr-file" accept=".pdf,.jpg,.jpeg,.png,.webp,.heic" '
        +   'style="display:block;width:100%;font-size:13px;margin-bottom:4px;">'
        + '<div style="color:#64748B;font-size:12px;margin-bottom:16px;">'
        +   'PDF or a photo of the card, up to 10 MB.</div>'

        + '<label style="display:block;font-weight:700;font-size:13px;color:#0F172A;margin-bottom:6px;">'
        + 'What does this record cover?</label>'
        + '<div style="color:#64748B;font-size:12px;margin-bottom:8px;">'
        +   'Tick each dose the record shows. A date is optional — leave it blank if the '
        +   'card is unclear, the dose still counts as recorded.</div>'
        + '<div class="fr-doses" style="border:1px solid #E2E8F0;border-radius:10px;max-height:260px;'
        +   'overflow:auto;" data-kt-scroll="1"></div>'
        + (doneCount
            ? '<div style="color:#64748B;font-size:12px;margin:6px 0 0;">'
              + doneCount + ' dose' + (doneCount === 1 ? ' is' : 's are') + ' already on file and '
              + 'cannot be ticked again here.</div>'
            : '')

        + '<label style="display:block;font-weight:700;font-size:13px;color:#0F172A;margin:16px 0 6px;">'
        + 'Notes</label>'
        + '<textarea class="fr-notes" rows="3" maxlength="2000" '
        +   'placeholder="Anything the record does not say — a faded line, a dose the clinic is confirming…" '
        +   'style="width:100%;box-sizing:border-box;padding:9px 11px;border:1px solid #CBD5E1;'
        +   'border-radius:9px;font-size:13px;font-family:inherit;resize:vertical;"></textarea>'
        + '<div class="fr-err" style="color:#B91C1C;font-size:12.5px;margin-top:10px;"></div>';

      var listEl = form.querySelector('.fr-doses');
      if (!items.length) {
        listEl.innerHTML = '<div style="padding:14px;color:#64748B;font-size:13px;">'
          + 'No immunisation schedule has been set up for this agency, so there is nothing '
          + 'to tick. The record can still be filed.</div>';
      } else {
        items.forEach(function (i, idx) {
          var already = (i.status === 'done' || i.status === 'exempt');
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
            + '<input type="date" class="fr-date"' + (already ? ' disabled' : ' disabled') + ' '
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
      }

      M.open({
        title: 'File an immunization record — ' + childName(child),
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

              var fd = new FormData();
              fd.append('file', f);
              fd.append('title', 'Immunization record');
              fd.append('notes', form.querySelector('.fr-notes').value || '');
              if (doses.length) { fd.append('doses', JSON.stringify(doses)); }

              return KT.Api.postForm(base + '/immunization-records', fd).then(function (res) {
                // Both halves move: the record appears, and the doses read off it are
                // no longer outstanding. Re-read rather than patch the DOM — the server
                // decides what was actually recorded (a dose already on file is skipped).
                loadDue();
                loadRecords();
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
      recEl.innerHTML = '<div style="font-weight:800;font-size:13px;color:#0F172A;margin-bottom:8px;">'
        + (scope === 'parent' ? 'Records you have sent in' : 'Records on file') + '</div>';

      var list = document.createElement('div');
      list.style.cssText = 'font-size:13px;';
      if (!records.length) {
        list.innerHTML = '<div style="color:#64748B;padding:6px 0;">Nothing on file yet — '
          + 'a clear photo of the immunisation card is enough.</div>';
      } else {
        records.forEach(function (r) {
          var row = document.createElement('div');
          row.style.cssText = 'display:flex;align-items:center;gap:10px;padding:9px 0;'
            + 'border-top:1px solid #F1F5F9;flex-wrap:wrap;';
          row.innerHTML = '<div style="flex:1;min-width:170px;">'
            + '<div style="font-weight:600;color:#0F172A;">' + esc(r.title || 'Immunization record') + '</div>'
            + '<div style="color:#64748B;font-size:12px;">'
            +   esc(String(r.uploaded_at || '').slice(0, 10))
            +   (r.uploaded_by ? '  ·  ' + esc(r.uploaded_by) : '')
            +   (r.uploaded_by_parent ? '' : '  ·  added by the centre')
            +   '  ·  ' + esc(fmtSize(r.file_size))
            + '</div>'
            /* WHAT WAS READ OFF IT, wherever the record is shown. This is the half that
               makes a parent's and an educator's view the same view as the director's:
               the document alone says a card arrived, the dose list says what the centre
               concluded from it, and only the second one is checkable. */
            + ((r.doses && r.doses.length)
                ? '<div style="margin-top:5px;color:#166534;font-size:12px;">✓ '
                    + r.doses.map(function (x) {
                        return esc(String(x.vaccine || '') + ' ' + String(x.dose_label || '')).trim()
                          + (x.administered_on ? ' (' + esc(fmtDay(x.administered_on)) + ')' : '');
                      }).join(' · ')
                  + '</div>'
                : '')
            + (r.notes
                ? '<div style="margin-top:5px;color:#475569;font-size:12px;white-space:pre-wrap;">'
                    + '<span style="color:#94A3B8;">Note:</span> ' + esc(r.notes) + '</div>'
                : '')
            + '</div>';

          var view = document.createElement('button');
          view.type = 'button';
          view.textContent = 'View';
          view.style.cssText = 'padding:6px 12px;border-radius:8px;border:1px solid #CBD5E1;background:#fff;'
            + 'font-size:12.5px;font-weight:700;cursor:pointer;color:#0F172A;';
          view.addEventListener('click', function () { openRecord(r, view); });
          row.appendChild(view);
          list.appendChild(row);
        });
      }
      recEl.appendChild(list);
      if (canUpload) { recEl.appendChild(uploader()); }
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
      var win = window.open('', '_blank');   // opened on the click, or a blocker eats it
      fetch(api() + base + '/immunization-records/' + r.id + '/download', {
        headers: { Authorization: 'Bearer ' + token() },
      }).then(function (res) {
        if (!res.ok) { throw new Error('HTTP ' + res.status); }
        return res.blob();
      }).then(function (b) {
        var u = URL.createObjectURL(b);
        if (win) { win.location = u; } else { window.open(u, '_blank'); }
        setTimeout(function () { URL.revokeObjectURL(u); }, 60000);
      }).catch(function () {
        if (win) { win.close(); }
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
      btn.setAttribute('data-kt-no-icon', '1');
      btn.style.cssText = 'padding:10px 18px;border-radius:10px;border:0;background:#1F6080;color:#fff;'
        + 'font-size:13.5px;font-weight:800;cursor:pointer;';

      var msg = document.createElement('span');
      msg.style.cssText = 'font-size:12.5px;color:#64748B;';

      /* Staff get the dialog — the document AND what it says, in one action. A parent
         has nothing to tick, so their button still goes straight to the file picker, and
         so does everyone's if the shell's Modal is somehow not loaded. */
      btn.addEventListener('click', function () {
        if (scope !== 'parent' && fileRecordModal()) { return; }
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
