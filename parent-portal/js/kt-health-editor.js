/* ═══════════════════════════════════════════════════════════════════
   KiddieTrac — one editor for a child's allergies, dietary needs and health alerts.

   The DATA has been structured since v22p1 — children.allergies, .dietary_restrictions
   and .health_alerts are JSON arrays with severity, reaction, EpiPen location and an
   action plan, and PATCH /children/{id}/health has always accepted them. Nothing in the
   portal ever wrote to it. The Allergy alerts screen could only READ, the child record
   showed a comma-separated string typed at enrolment, and an EpiPen location existed as a
   field that no screen offered anybody a way to fill in.

   So: one editor, opened from the alerts screen and from the child record, writing the
   same structured rows. One implementation on purpose — an allergy is the last thing that
   should read differently depending on which screen somebody opened it from.

   Public: KT.healthEditor.open(child, opts)
     child : { id, first_name, last_name }
     opts  : { onSaved: fn, readOnly: bool }
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

  var SEV = [
    ['mild', 'Mild', '#166534', '#DCFCE7', '#BBF7D0'],
    ['moderate', 'Moderate', '#92400E', '#FEF3C7', '#FDE68A'],
    ['anaphylactic', 'Anaphylactic', '#991B1B', '#FEE2E2', '#FECACA'],
  ];

  var IN = 'width:100%;padding:8px 11px;border:1px solid #D1D5DB;border-radius:9px;'
    + 'font-size:13.5px;box-sizing:border-box;font-family:inherit;';
  var LBL = 'display:block;font-size:11px;font-weight:800;color:#64748B;'
    + 'text-transform:uppercase;letter-spacing:.4px;margin-bottom:4px;';

  function childName(c) {
    return ((c.preferred_name || c.first_name || '') + ' ' + (c.last_name || '')).trim() || 'this child';
  }

  /**
   * Open the editor.
   *
   * Loads the current record first. Editing health data from a blank form is how somebody
   * wipes an EpiPen location they never saw — the form always starts from what is on file.
   */
  KT.healthEditor = {
    open: function (child, opts) {
      opts = opts || {};

      var ov = document.createElement('div');
      ov.className = 'kt-scrim';
      ov.setAttribute('data-no-modal-guard', '1');
      ov.style.cssText = 'position:fixed;inset:0;z-index:2147479000;display:flex;align-items:flex-start;'
        + 'justify-content:center;padding:20px;overflow-y:auto;background:rgba(8,20,40,.55);';
      ov.innerHTML =
        '<div style="background:#fff;border-radius:16px;max-width:760px;width:100%;margin:auto;'
        + 'overflow:hidden;box-shadow:0 30px 80px -20px rgba(8,20,40,.6);">'
        + '<div style="padding:18px 22px;border-bottom:1px solid #EEF2F7;display:flex;align-items:center;gap:12px;">'
        +   '<div style="min-width:0;">'
        +     '<div style="font-size:17px;font-weight:800;color:#0F172A;">⚠️ ' + esc(childName(child)) + '</div>'
        +     '<div style="font-size:12.5px;color:#64748B;margin-top:2px;">Allergies, dietary needs and health alerts. '
        +       'Educators see these on the roster, the day brief and the weekly menu.</div>'
        +   '</div>'
        +   '<button class="he-x" type="button" aria-label="Close" data-kt-iconized="1" style="margin-left:auto;'
        +     'background:#F1F5F9;border:0;border-radius:9px;width:34px;height:34px;font-size:17px;cursor:pointer;color:#475569;">✕</button>'
        + '</div>'
        + '<div class="he-body" style="padding:18px 22px;max-height:min(72vh,760px);overflow-y:auto;" data-kt-scroll="1">'
        +   '<div style="padding:26px;text-align:center;color:#94A3B8;">Loading…</div>'
        + '</div>'
        + '<div class="he-foot" style="padding:14px 22px;border-top:1px solid #EEF2F7;display:flex;gap:10px;'
        +   'justify-content:flex-end;align-items:center;">'
        +   '<div class="he-msg" style="margin-right:auto;font-size:13px;color:#B91C1C;min-height:18px;"></div>'
        +   '<button class="he-x" type="button" style="padding:10px 18px;border:1px solid #D1D5DB;background:#fff;'
        +     'border-radius:10px;font-size:13.5px;font-weight:700;cursor:pointer;color:#475569;">Cancel</button>'
        +   '<button class="he-save" type="button" style="padding:10px 22px;border:0;background:#1F6080;color:#fff;'
        +     'border-radius:10px;font-size:13.5px;font-weight:800;cursor:pointer;">Save</button>'
        + '</div></div>';
      document.body.appendChild(ov);
      ov.querySelectorAll('.he-x').forEach(function (b) {
        b.addEventListener('click', function () { ov.remove(); });
      });

      var bodyEl = ov.querySelector('.he-body');
      var msgEl = ov.querySelector('.he-msg');
      var saveBtn = ov.querySelector('.he-save');
      if (opts.readOnly) { saveBtn.style.display = 'none'; }

      var state = { allergies: [], dietary_restrictions: [], health_alerts: [] };
      var notes = { medical_notes: '', dietary_notes: '' };

      /* /director/... — that is where the child-health pair lives, for directors and
         agency admins alike. The bare /children/{id}/health resolves to nothing. */
      KT.Api.get('/director/children/' + child.id + '/health').then(function (r) {
        // GET /children/{id}/health answers { child: {...}, active_medications, … }
        var h = (r && r.child) || {};
        state.allergies = norm(h.allergies, 'allergen');
        state.dietary_restrictions = norm(h.dietary_restrictions, 'restriction');
        state.health_alerts = norm(h.health_alerts, 'label');
        notes.medical_notes = h.medical_notes || '';
        notes.dietary_notes = h.dietary_notes || '';
        paint();
      }).catch(function (e) {
        bodyEl.innerHTML = '<div style="padding:20px;color:#B91C1C;font-size:13.5px;">'
          + 'Could not load this child’s health record: ' + esc((e && e.message) || '') + '</div>';
        saveBtn.style.display = 'none';
      });

      /* The stored value can be a JSON array of objects, a JSON array of strings, or a
         plain comma-separated string typed at enrolment. All three mean the same thing to
         a reader, so all three are read — otherwise opening this on an older record shows
         nothing and saving would erase what was there. */
      function norm(raw, key) {
        if (!raw) { return []; }
        var arr = raw;
        if (typeof raw === 'string') {
          var t = raw.trim();
          if (!t) { return []; }
          try {
            arr = JSON.parse(t);
            if (!Array.isArray(arr)) { throw new Error('not a list'); }
          } catch (e) {
            arr = t.split(',');
          }
        }
        if (!Array.isArray(arr)) { return []; }
        return arr.map(function (x) {
          if (typeof x === 'string') { var o = {}; o[key] = x.trim(); return o; }
          return x || {};
        }).filter(function (x) { return String(x[key] || '').trim(); });
      }

      function paint() {
        bodyEl.innerHTML = '';
        bodyEl.appendChild(section('allergies', '🥜 Allergies', 'allergen',
          'What they react to, how badly, and what to do about it.'));
        bodyEl.appendChild(section('dietary_restrictions', '🍽️ Dietary needs', 'restriction',
          'Halal, vegetarian, no dairy — anything the kitchen has to work around.'));
        bodyEl.appendChild(section('health_alerts', '🩺 Health alerts', 'label',
          'Asthma, seizures, anything an educator must know at a glance.'));

        var nn = document.createElement('div');
        nn.style.cssText = 'margin-top:18px;padding-top:16px;border-top:1px solid #EEF2F7;display:grid;gap:12px;';
        nn.innerHTML = '<div><label style="' + LBL + '">Medical notes</label>'
          + '<textarea class="he-mn" rows="2" style="' + IN + 'resize:vertical;">' + esc(notes.medical_notes) + '</textarea></div>'
          + '<div><label style="' + LBL + '">Dietary notes</label>'
          + '<textarea class="he-dn" rows="2" style="' + IN + 'resize:vertical;">' + esc(notes.dietary_notes) + '</textarea></div>';
        bodyEl.appendChild(nn);
        nn.querySelector('.he-mn').addEventListener('input', function () { notes.medical_notes = this.value; });
        nn.querySelector('.he-dn').addEventListener('input', function () { notes.dietary_notes = this.value; });

        if (opts.readOnly) {
          bodyEl.querySelectorAll('input, select, textarea, button').forEach(function (el) { el.disabled = true; });
        }
      }

      function section(kind, title, key, hint) {
        var wrap = document.createElement('div');
        wrap.style.cssText = 'margin-bottom:18px;';
        var head = document.createElement('div');
        head.style.cssText = 'display:flex;align-items:center;gap:10px;margin-bottom:4px;';
        head.innerHTML = '<div style="font-size:14px;font-weight:800;color:#0F172A;">' + title + '</div>';
        var add = document.createElement('button');
        add.type = 'button';
        add.textContent = '＋ Add';
        add.setAttribute('data-kt-no-icon', '1');
        add.style.cssText = 'margin-left:auto;background:#fff;border:1.5px solid #BFDBFE;color:#1F6FB2;'
          + 'border-radius:9px;padding:5px 12px;font-size:12.5px;font-weight:800;cursor:pointer;';
        add.addEventListener('click', function () {
          var o = {};
          o[key] = '';
          if (kind === 'allergies') { o.severity = 'mild'; }
          state[kind].push(o);
          paint();
        });
        if (!opts.readOnly) { head.appendChild(add); }
        wrap.appendChild(head);
        wrap.appendChild(Object.assign(document.createElement('div'), {
          textContent: hint,
        })).style.cssText = 'font-size:12px;color:#64748B;margin-bottom:8px;';

        if (!state[kind].length) {
          wrap.appendChild(Object.assign(document.createElement('div'), {
            textContent: 'None recorded.',
          })).style.cssText = 'font-size:13px;color:#94A3B8;padding:8px 0;';
          return wrap;
        }

        state[kind].forEach(function (row, i) {
          wrap.appendChild(rowEl(kind, key, row, i));
        });
        return wrap;
      }

      function rowEl(kind, key, row, i) {
        var box = document.createElement('div');
        var severe = kind === 'allergies' && row.severity === 'anaphylactic';
        box.style.cssText = 'border:1.5px solid ' + (severe ? '#FECACA' : '#E2E8F0') + ';'
          + 'background:' + (severe ? '#FEF2F2' : '#fff') + ';border-radius:11px;padding:12px 13px;margin-bottom:9px;';

        var top = document.createElement('div');
        top.style.cssText = 'display:grid;grid-template-columns:1fr '
          + (kind === 'allergies' ? '150px' : '') + ' 34px;gap:9px;align-items:end;';

        var nameWrap = document.createElement('div');
        nameWrap.innerHTML = '<label style="' + LBL + '">'
          + (kind === 'allergies' ? 'Allergen' : kind === 'dietary_restrictions' ? 'Restriction' : 'Alert')
          + '</label>';
        var nameIn = document.createElement('input');
        nameIn.style.cssText = IN;
        nameIn.value = row[key] || '';
        nameIn.placeholder = kind === 'allergies' ? 'e.g. Peanuts'
          : kind === 'dietary_restrictions' ? 'e.g. No dairy' : 'e.g. Asthma';
        nameIn.addEventListener('input', function () { row[key] = this.value; });
        nameWrap.appendChild(nameIn);
        top.appendChild(nameWrap);

        if (kind === 'allergies') {
          var sevWrap = document.createElement('div');
          sevWrap.innerHTML = '<label style="' + LBL + '">Severity</label>';
          var sevSel = document.createElement('select');
          sevSel.style.cssText = IN + 'background:#fff;';
          SEV.forEach(function (s) {
            var o = document.createElement('option');
            o.value = s[0]; o.textContent = s[1];
            if ((row.severity || 'mild') === s[0]) { o.selected = true; }
            sevSel.appendChild(o);
          });
          /* Repaint on change: anaphylactic turns the card red and reveals the EpiPen
             fields, which is the one thing on this form somebody must not miss. */
          sevSel.addEventListener('change', function () { row.severity = this.value; paint(); });
          sevWrap.appendChild(sevSel);
          top.appendChild(sevWrap);
        }

        var del = document.createElement('button');
        del.type = 'button';
        del.textContent = '✕';
        del.title = 'Remove';
        del.setAttribute('data-kt-no-icon', '1');
        del.style.cssText = 'height:36px;background:none;border:0;color:#DC2626;font-size:15px;cursor:pointer;';
        del.addEventListener('click', function () { state[kind].splice(i, 1); paint(); });
        top.appendChild(del);
        box.appendChild(top);

        if (kind === 'allergies') {
          var more = document.createElement('div');
          more.style.cssText = 'display:grid;grid-template-columns:1fr 1fr;gap:9px;margin-top:9px;';
          more.innerHTML = '<div><label style="' + LBL + '">Reaction</label>'
            + '<input class="he-r" style="' + IN + '" value="' + esc(row.reaction || '') + '" placeholder="Hives, swelling…"></div>'
            + '<div><label style="' + LBL + '">Action plan</label>'
            + '<input class="he-a" style="' + IN + '" value="' + esc(row.action_plan || '') + '" placeholder="What to do first"></div>';
          more.querySelector('.he-r').addEventListener('input', function () { row.reaction = this.value; });
          more.querySelector('.he-a').addEventListener('input', function () { row.action_plan = this.value; });
          box.appendChild(more);

          var epi = document.createElement('div');
          epi.style.cssText = 'display:flex;align-items:center;gap:10px;margin-top:9px;flex-wrap:wrap;';
          var cb = document.createElement('input');
          cb.type = 'checkbox';
          cb.checked = !!row.epipen_required;
          cb.style.cssText = 'width:17px;height:17px;accent-color:#DC2626;';
          var lab = document.createElement('label');
          lab.style.cssText = 'display:flex;align-items:center;gap:8px;font-size:13px;font-weight:700;color:#334155;cursor:pointer;';
          lab.appendChild(cb);
          lab.appendChild(document.createTextNode('EpiPen required'));
          epi.appendChild(lab);

          var loc = document.createElement('input');
          loc.style.cssText = IN + 'flex:1;min-width:180px;';
          loc.placeholder = 'Where is it kept? e.g. Room 2 cupboard';
          loc.value = row.epipen_location || '';
          loc.addEventListener('input', function () { row.epipen_location = this.value; });
          loc.style.display = cb.checked ? '' : 'none';
          /* The location only matters if there is a pen, and an empty box next to an
             unticked checkbox is just something else to read. */
          cb.addEventListener('change', function () {
            row.epipen_required = cb.checked;
            loc.style.display = cb.checked ? '' : 'none';
            if (!cb.checked) { row.epipen_location = ''; loc.value = ''; }
          });
          epi.appendChild(loc);
          box.appendChild(epi);
        }

        if (kind === 'dietary_restrictions') {
          var d = document.createElement('div');
          d.style.cssText = 'margin-top:9px;';
          d.innerHTML = '<label style="' + LBL + '">Details</label>'
            + '<input class="he-d" style="' + IN + '" value="' + esc(row.details || '') + '" placeholder="Anything the kitchen needs to know">';
          d.querySelector('.he-d').addEventListener('input', function () { row.details = this.value; });
          box.appendChild(d);
        }

        if (kind === 'health_alerts') {
          var hd = document.createElement('div');
          hd.style.cssText = 'margin-top:9px;';
          hd.innerHTML = '<label style="' + LBL + '">Detail</label>'
            + '<input class="he-hd" style="' + IN + '" value="' + esc(row.detail || '') + '" placeholder="What an educator should watch for">';
          hd.querySelector('.he-hd').addEventListener('input', function () { row.detail = this.value; });
          box.appendChild(hd);
        }

        return box;
      }

      saveBtn.addEventListener('click', function () {
        msgEl.textContent = '';

        /* A blank line is a half-finished thought, not a record. Dropped rather than sent,
           because the server requires a name on every entry and would refuse the lot —
           losing the rows that WERE filled in. */
        var payload = {
          allergies: state.allergies.filter(function (r) { return String(r.allergen || '').trim(); }),
          dietary_restrictions: state.dietary_restrictions.filter(function (r) { return String(r.restriction || '').trim(); }),
          health_alerts: state.health_alerts.filter(function (r) { return String(r.label || '').trim(); }),
          medical_notes: notes.medical_notes || null,
          dietary_notes: notes.dietary_notes || null,
        };

        /* Anaphylactic with no EpiPen location is worth a question, not a refusal — the
           pen may genuinely live with the parent. Asked once, then accepted. */
        var missing = payload.allergies.filter(function (r) {
          return r.severity === 'anaphylactic' && r.epipen_required && !String(r.epipen_location || '').trim();
        });
        var go = missing.length
          ? Promise.resolve(KT.confirm
              ? KT.confirm('An anaphylactic allergy is marked as needing an EpiPen but no location is recorded.\n\n'
                  + 'Educators are told to look for it. Save anyway?')
              : confirm('EpiPen location is blank. Save anyway?'))
          : Promise.resolve(true);

        go.then(function (ok) {
          if (!ok) { return; }
          saveBtn.disabled = true;
          saveBtn.textContent = 'Saving…';
          KT.Api.patch('/director/children/' + child.id + '/health', payload).then(function () {
            if (KT.toast) {
              KT.toast('⚠️', 'Saved', childName(child) + '’s record is updated — educators see it straight away.', '#16A34A');
            }
            ov.remove();
            if (typeof opts.onSaved === 'function') { opts.onSaved(); }
          }).catch(function (e) {
            saveBtn.disabled = false;
            saveBtn.textContent = 'Save';
            msgEl.textContent = (e && e.message) || 'That could not be saved.';
          });
        });
      });
    },
  };
})(window);
