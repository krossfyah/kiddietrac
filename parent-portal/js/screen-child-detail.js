/* ============================================================
   KIDDIETRAC v22p5 — Child detail screen
   Hash: #child-detail?id=N  (preserves ?back=centre|list etc.)
   Roles: centre_director, agency_admin
   - Shows full child record (family, room, enrollment, health flags)
   - Edit form (PATCH /api/v1/director/children/{id})
   - Archive (DELETE) — soft delete with confirmation
   ============================================================ */
(function (window) {
  'use strict';
  if (!window.KT) return;
  var KT = window.KT;
  var Api = KT.Api;
  var Dom = KT.Dom;
  var Shell = KT.Shell;

  function esc(s) {
    return s == null ? '' : String(s)
      .replace(/&/g, '&amp;').replace(/</g, '&lt;')
      .replace(/>/g, '&gt;').replace(/"/g, '&quot;');
  }

  /* A bare YYYY-MM-DD carries no time and no zone: a date of birth, an enrolment date.
     It must be read as the day it says. Anything longer is a MySQL datetime, which is
     UTC without a marker and has to be converted into the agency's clock. */
  var DATE_ONLY = /^\d{4}-\d{2}-\d{2}$/;

  /** "Mon, Tue, Wed, Thu" — or nothing at all when it is simply every day. */
  function daysLabel(schedule) {
    var arr = schedule;
    if (typeof arr === 'string') {
      var t = arr.trim();
      if (!t || t === '[]') { return ''; }
      try { arr = JSON.parse(t); } catch (e) { return ''; }
    }
    if (!Array.isArray(arr) || !arr.length || arr.length === 7) { return ''; }
    var LBL = { mon: 'Mon', tue: 'Tue', wed: 'Wed', thu: 'Thu', fri: 'Fri', sat: 'Sat', sun: 'Sun' };
    var ORDER = ['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'];
    return ORDER.filter(function (d) { return arr.indexOf(d) !== -1; })
                .map(function (d) { return LBL[d]; }).join(', ');
  }

  /* Shared today styling — see .kt-today-row in kt-consistency-polish.css. Screens
     used to pick their own tint and every one of them landed a few percent from white. */
  function todayRowAttr(isToday) { return isToday ? ' class="kt-today-row"' : ''; }
  function todayTag() { return '<span class="kt-today-tag">TODAY</span>'; }

  function fmtDate(d) {
    if (!d) return '—';
    var s = String(d).trim();

    if (DATE_ONLY.test(s)) {
      // Formatted from the string parts — nothing is parsed, so nothing can shift.
      return (window.KT && KT.dayLabel) ? KT.dayLabel(s) : s;
    }

    if (window.KT && KT.fmtDate) {
      try {
        var out = KT.fmtDate(s);
        if (out) { return out; }
      } catch (e) { /* fall through */ }
    }

    try {
      return new Date(s).toLocaleDateString('en-CA',
        { year: 'numeric', month: 'short', day: 'numeric' });
    } catch (e) { return d; }
  }

  function parseQuery(hash) {
    var q = (hash || '').split('?')[1] || '';
    var out = {};
    q.split('&').forEach(function (kv) {
      if (!kv) return;
      var parts = kv.split('=');
      out[decodeURIComponent(parts[0])] = decodeURIComponent(parts[1] || '');
    });
    return out;
  }

  function backHash(params) {
    if (params.back === 'centre' && params.centre_id) {
      return '#admin-centres';
    }
    if (params.centre_id) {
      return '#children?centre_id=' + encodeURIComponent(params.centre_id);
    }
    return '#children';
  }

  function row(label, value) {
    var r = Dom.el('div', {
      style: 'display:grid;grid-template-columns:160px 1fr;gap:12px;padding:8px 0;border-bottom:1px solid #F3F4F6;',
    });
    r.appendChild(Dom.el('div', {
      style: 'color:#6B7280;font-size:13px;font-weight:600;',
    }, label));
    var v = Dom.el('div', { style: 'font-size:14px;color:#111827;' });
    if (typeof value === 'string' || typeof value === 'number') {
      v.textContent = value === null || value === '' ? '—' : String(value);
    } else if (value instanceof Node) {
      v.appendChild(value);
    } else {
      v.textContent = '—';
    }
    r.appendChild(v);
    return r;
  }

  function btn(label, style, onClick) {
    var b = Dom.el('button', {
      style: 'border:none;border-radius:8px;padding:9px 14px;font-size:13px;font-weight:600;cursor:pointer;' + (style || ''),
    }, label);
    if (onClick) b.addEventListener('click', onClick);
    return b;
  }

  function btnPrimary() {
    return 'background:#1F6080;color:white;';
  }
  function btnSecondary() {
    return 'background:white;color:#374151;border:1px solid #D1D5DB!important;';
  }
  function btnDanger() {
    return 'background:#DC2626;color:white;';
  }

  function showEditModal(child, onSaved) {
    var overlay = Dom.el('div', {
      style: 'position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:9999;display:flex;align-items:flex-start;justify-content:center;padding:40px 20px;overflow:auto;',
    });
    var modal = Dom.el('div', {
      style: 'background:white;border-radius:14px;padding:24px;max-width:640px;width:100%;box-shadow:0 20px 50px rgba(0,0,0,.3);',
    });
    modal.appendChild(Dom.el('h3', {
      style: 'margin:0 0 16px;font-size:18px;',
    }, 'Edit ' + (child.display_name || child.full_name)));

    var form = Dom.el('form', {});
    var fields = [
      { name: 'first_name', label: 'First name', value: child.first_name, type: 'text' },
      { name: 'last_name', label: 'Last name', value: child.last_name, type: 'text' },
      { name: 'preferred_name', label: 'Preferred name', value: child.preferred_name, type: 'text' },
      { name: 'pronouns', label: 'Pronouns', value: child.pronouns, type: 'text' },
      { name: 'date_of_birth', label: 'Date of birth', value: child.date_of_birth, type: 'date' },
      /* The times this child is EXPECTED. Not the same thing as when they actually
         arrived — those are check_events. Held here so reports can compare the two, and
         so a roster can say who is still due rather than only who is present. */
      { name: 'expected_dropoff_time', label: 'Usual drop-off time', value: child.expected_dropoff_time, type: 'time' },
      { name: 'expected_pickup_time', label: 'Usual pick-up time', value: child.expected_pickup_time, type: 'time' },
      { name: 'gender', label: 'Gender', value: child.gender, type: 'select',
        options: ['female', 'male', 'non_binary', 'prefer_not_to_say', 'other'] },
      { name: 'health_card_last4', label: 'Health card (last 4)', value: child.health_card_last4, type: 'text' },
      { name: 'doctor_name', label: 'Doctor name', value: child.doctor_name, type: 'text' },
      { name: 'doctor_phone', label: 'Doctor phone', value: child.doctor_phone, type: 'text' },
      { name: 'medical_notes', label: 'Medical notes', value: child.medical_notes, type: 'textarea' },
      { name: 'dietary_notes', label: 'Dietary notes', value: child.dietary_notes, type: 'textarea' },
      { name: 'cultural_notes', label: 'Cultural notes', value: child.cultural_notes, type: 'textarea' },
    ];
    var inputs = {};
    fields.forEach(function (f) {
      var wrap = Dom.el('div', { style: 'margin-bottom:12px;' });
      wrap.appendChild(Dom.el('label', {
        style: 'display:block;margin-bottom:4px;font-size:12px;font-weight:600;color:#374151;',
      }, f.label));
      var input;
      if (f.type === 'textarea') {
        input = Dom.el('textarea', {
          name: f.name, rows: '2',
          style: 'width:100%;border:1px solid #D1D5DB;border-radius:6px;padding:7px 9px;font-size:13px;font-family:inherit;resize:vertical;',
        });
        input.value = f.value || '';
      } else if (f.type === 'select') {
        input = Dom.el('select', {
          name: f.name,
          style: 'width:100%;border:1px solid #D1D5DB;border-radius:6px;padding:7px 9px;font-size:13px;background:white;',
        });
        f.options.forEach(function (opt) {
          var o = Dom.el('option', { value: opt }, opt.replace(/_/g, ' '));
          if (opt === f.value) o.selected = true;
          input.appendChild(o);
        });
      } else {
        input = Dom.el('input', {
          type: f.type, name: f.name, value: f.value || '',
          style: 'width:100%;border:1px solid #D1D5DB;border-radius:6px;padding:7px 9px;font-size:13px;',
        });
      }
      inputs[f.name] = input;
      wrap.appendChild(input);
      form.appendChild(wrap);
    });

    var actions = Dom.el('div', {
      style: 'display:flex;justify-content:flex-end;gap:8px;margin-top:16px;',
    });
    var cancelB = btn('Cancel', btnSecondary(), function () { overlay.remove(); });
    actions.appendChild(cancelB);
    var saveB = btn('Save changes', btnPrimary());
    actions.appendChild(saveB);
    form.appendChild(actions);

    saveB.addEventListener('click', function (ev) {
      ev.preventDefault();
      var payload = {};
      Object.keys(inputs).forEach(function (k) {
        var v = inputs[k].value;
        if (v === '') v = null;
        payload[k] = v;
      });
      saveB.disabled = true;
      saveB.textContent = 'Saving...';
      Api.patch('/director/children/' + child.id, payload)
        .then(function () {
          overlay.remove();
          if (KT.Dom && KT.Dom.toast) KT.Dom.toast('Child updated', 'success');
          if (onSaved) onSaved();
        })
        .catch(function (e) {
          saveB.disabled = false;
          saveB.textContent = 'Save changes';
          alert('Save failed: ' + (e.message || 'error'));
        });
    });

    modal.appendChild(form);
    overlay.appendChild(modal);
    overlay.addEventListener('click', function (e) {
      if (e.target === overlay) overlay.remove();
    });
    document.body.appendChild(overlay);
  }

  function showArchiveConfirm(child, onArchived) {
    var overlay = Dom.el('div', {
      style: 'position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:9999;display:flex;align-items:center;justify-content:center;',
    });
    var modal = Dom.el('div', {
      style: 'background:white;border-radius:14px;padding:24px;max-width:440px;width:100%;box-shadow:0 20px 50px rgba(0,0,0,.3);',
    });
    modal.appendChild(Dom.el('h3', {
      style: 'margin:0 0 8px;font-size:18px;color:#DC2626;',
    }, 'Archive child?'));
    modal.appendChild(Dom.el('p', {
      style: 'margin:0 0 16px;font-size:14px;color:#374151;line-height:1.5;',
    }, 'Archiving ' + (child.display_name || child.full_name) + ' will end any active enrollment and remove the child from active rosters. Historical records (invoices, observations, photos) are preserved. This can be restored by an agency admin.'));
    var actions = Dom.el('div', {
      style: 'display:flex;justify-content:flex-end;gap:8px;',
    });
    actions.appendChild(btn('Cancel', btnSecondary(), function () { overlay.remove(); }));
    var confirmB = btn('Yes, archive', btnDanger());
    confirmB.addEventListener('click', function () {
      confirmB.disabled = true;
      confirmB.textContent = 'Archiving...';
      Api.delete('/director/children/' + child.id)
        .then(function () {
          overlay.remove();
          if (KT.Dom && KT.Dom.toast) KT.Dom.toast('Child archived', 'success');
          if (onArchived) onArchived();
        })
        .catch(function (e) {
          confirmB.disabled = false;
          confirmB.textContent = 'Yes, archive';
          alert('Archive failed: ' + (e.message || 'error'));
        });
    });
    actions.appendChild(confirmB);
    modal.appendChild(actions);
    overlay.appendChild(modal);
    document.body.appendChild(overlay);
  }

  /* KT.avatar returns an HTML STRING (it is used inside innerHTML elsewhere), and
     this screen builds nodes. Wrap it once rather than at each call site. Falls back
     to nothing if the global helper has not loaded, so a missing avatar can never
     take the record down with it. (2026-08-25) */
  function _avatarEl(name, opts) {
    var span = Dom.el('span', { style: 'flex:0 0 auto;display:inline-flex;' });
    try {
      if (window.KT && KT.avatar) span.innerHTML = KT.avatar(name || '', opts || {});
    } catch (e) {}
    return span;
  }

  /* Read-only until somebody asks to edit. Reset on every open, deliberately: edit mode
     is a thing you turn on for a task, not a preference that follows you into the next
     child's record. */
  var editMode = false;
  /* Which child the mode belongs to. renderChildDetail() is BOTH the open path and the
     re-render path — pressing Edit calls it to repaint — so a blanket reset at the top
     of it cancelled the very click that turned editing on. Keyed on the child instead:
     opening a different record starts read-only, repainting the same one does not throw
     away what the user just asked for. That also survives the shell's own re-renders,
     which would otherwise drop somebody out of edit mode mid-task. */
  var editModeFor = null;

  /**
   * Make a region non-interactive while the record is read-only.
   *
   * `inert` rather than disabling each control: these regions render their own contents
   * asynchronously (the attendance pattern is a separate module), so anything that walks
   * the DOM once would miss whatever arrived after it ran. inert covers the subtree
   * whenever it appears, and takes it out of the tab order too — a keyboard user should
   * not be able to reach a field the mouse cannot.
   */
  function gateEditable(host, what) {
    if (!host) { return host; }
    if (editMode) {
      host.removeAttribute('inert');
      host.style.opacity = '';
      return host;
    }
    host.setAttribute('inert', '');
    host.style.opacity = '.72';
    host.title = (what || 'This') + ' can be changed after you press Edit.';
    return host;
  }


  /* Incidents on this child's record — read-only, newest first.
     Fetched separately so a slow or failing incidents query can never stop the
     rest of the record rendering; on failure the section simply does not appear. */
  function appendIncidentsSection(wrap, childId) {
    var sec = Dom.el('div', {
      style: 'background:#fff;border:1px solid #E5E7EB;border-radius:14px;padding:20px;margin-top:18px;',
    });
    sec.appendChild(Dom.el('div', {
      style: 'font-size:11.5px;font-weight:800;letter-spacing:.6px;text-transform:uppercase;color:#64748B;margin-bottom:10px;',
    }, 'Incidents'));
    var body = Dom.el('div', { style: 'font-size:13.5px;color:#64748B;' }, 'Loading…');
    sec.appendChild(body);
    wrap.appendChild(sec);

    Api.get('/director/incidents?child_id=' + encodeURIComponent(childId) + '&per_page=50')
      .then(function (res) {
        var rows = (res && (res.data || res.incidents)) || [];
        Dom.clear(body);
        if (!rows.length) {
          body.appendChild(Dom.el('div', {}, 'No incidents recorded for this child.'));
          return;
        }

        var SEV = { low: '#15803D', medium: '#B45309', high: '#B3261E' };
        rows.forEach(function (inc) {
          var when = String(inc.occurred_at || '').replace('T', ' ').slice(0, 16);
          var who = [inc.recorded_by && inc.recorded_by.first_name, inc.recorded_by && inc.recorded_by.last_name]
            .filter(Boolean).join(' ');
          var row = Dom.el('div', {
            style: 'display:flex;gap:12px;align-items:baseline;padding:9px 0;border-top:1px solid #F1F5F9;cursor:pointer;',
          });
          row.appendChild(Dom.el('div', {
            style: 'flex:0 0 108px;font-size:12.5px;color:#64748B;white-space:nowrap;',
          }, when || '—'));
          var mid = Dom.el('div', { style: 'flex:1;min-width:0;' });
          mid.appendChild(Dom.el('div', { style: 'font-weight:700;color:#0F172A;' },
            String(inc.incident_type || '').replace(/_/g, ' ').replace(/\b\w/g, function (c) { return c.toUpperCase(); })
            + (inc.is_serious_occurrence ? ' · Serious occurrence' : '')));
          if (who || inc.location) {
            mid.appendChild(Dom.el('div', { style: 'font-size:12px;color:#64748B;margin-top:1px;' },
              [inc.location, who ? 'recorded by ' + who : ''].filter(Boolean).join(' · ')));
          }
          row.appendChild(mid);
          row.appendChild(Dom.el('div', {
            style: 'flex:0 0 auto;font-size:11px;font-weight:800;text-transform:uppercase;letter-spacing:.4px;color:'
              + (SEV[inc.severity] || '#64748B') + ';',
          }, String(inc.severity || '')));
          row.appendChild(Dom.el('div', {
            style: 'flex:0 0 auto;font-size:11px;color:#64748B;text-transform:uppercase;letter-spacing:.4px;',
          }, String(inc.status || '').replace(/_/g, ' ')));
          /* view-only — a record screen shows history; it does not act on it.
             Opens over the record rather than navigating away from it. */
          row.addEventListener('click', function () {
            if (window.KT && KT.openIncidentDialog) {
              KT.openIncidentDialog(inc.id, null, true, null);
              return;
            }
            window.location.hash = '#incident-detail?id=' + inc.id + '&view=1';
          });
          body.appendChild(row);
        });
      })
      .catch(function () {
        // Never let this take the record down with it.
        sec.remove();
      });
  }

  function renderChildDetail(container, opts) {
    Dom.clear(container);
    /* THE RECORD CAN NOW OPEN IN A POPUP (2026-09-21).

       Anthony: "view children record should be a popup which includes archived records".

       The hash was the only way in, which is why opening a child meant LEAVING the list
       you were working through - and coming back to the top of it. `opts.params` lets a
       caller hand the id (and archived flag) straight in; with no opts this behaves
       exactly as it always has, so the #child-detail route is untouched. */
    var params = (opts && opts.params) ? opts.params : parseQuery(window.location.hash);
    var childId = params.id;
    if (String(childId) !== String(editModeFor)) {
      editMode = false;
      editModeFor = childId;
    }
    if (!childId) {
      container.appendChild(Dom.el('div', {
        style: 'padding:24px;color:#DC2626;',
      }, 'Missing child id. Go back to the children list.'));
      return;
    }

    var wrap = Dom.el('div', { style: 'padding:24px;max-width:1800px;margin:0 auto;' });
    container.appendChild(wrap);

    var loading = Dom.el('div', {
      style: 'padding:40px;text-align:center;color:#6B7280;',
    }, 'Loading child record...');
    wrap.appendChild(loading);

    /* Opened from the Archived list, a removed child must still load — the record
       is retained for years and an admin has to be able to read it. (2026-08-25) */
    var _archivedParam = (params.archived === '1' || params.archived === 'true') ? '?archived=1' : '';
    Api.get('/director/children/' + childId + _archivedParam).then(function (data) {
      Dom.clear(wrap);
      var child = data;

      // The "← Back" link is deliberately gone: the shell's own navigation knows
      // where the user came from, and a link that guesses does not.

      // Header
      var header = Dom.el('div', {
        style: 'display:flex;align-items:flex-start;justify-content:space-between;margin-bottom:20px;flex-wrap:wrap;gap:12px;',
      });
      /* HAS THIS CHILD LEFT? is_archived ALONE IS NOT THE ANSWER (2026-09-21).

         A child leaves two different ways and only one of them sets is_archived:
           - REMOVED  -> the row is soft-deleted, is_archived true;
           - WITHDRAWN -> enrollment_status 'withdrawn' and a departed_at date, row intact.
         The Archived list counts both (its State column literally says Removed or
         Withdrawn), so a withdrawn child appeared there and then opened as a perfectly
         live record - no banner, and Edit and Archive on the header. Measured: child 85
         came back is_archived=false, departed_at='2026-09-22'; a live child has
         departed_at null, so the date is the honest discriminator. */
      var _departed = !!child.is_archived || !!child.departed_at;

      if (_departed) {
        container.appendChild(Dom.el('div', {
          style: 'background:#FFF7ED;border:1px solid #FED7AA;border-radius:10px;padding:11px 14px;margin-bottom:14px;font-size:13px;color:#9A3412;line-height:1.55;',
        }, '\ud83d\uddc4\ufe0f This child has left'
           + (child.departed_at ? ' (' + String(child.departed_at).slice(0, 10) + ')' : '')
           + '. The record is kept for your retention period and is read-only history.'));
      }
      var headerLeft = Dom.el('div');
      /* The face before the name: a record about a person should look like one.
         (Anthony, 2026-08-25) */
      var _idRow = Dom.el('div', { style: 'display:flex;align-items:center;gap:14px;' });
      _idRow.appendChild(_avatarEl(child.full_name, {
        size: 60,
        photoUrl: child.photo_url ? mediaUrl(child.photo_url) : '',
        sex: child.sex || child.gender || null,
        kind: 'child',
      }));
      var _idText = Dom.el('div', {});
      _idRow.appendChild(_idText);
      headerLeft.appendChild(_idRow);
      _idText.appendChild(Dom.el('h1', {
        style: 'font-size:26px;margin:0 0 4px;color:#111827;',
      }, child.full_name + (child.preferred_name ? '  (' + child.preferred_name + ')' : '')));
      _idText.appendChild(Dom.el('div', {
        style: 'color:#6B7280;font-size:14px;',
      }, (child.age && child.age.human ? child.age.human : '—') + '  ·  born ' + fmtDate(child.date_of_birth)));
      /* Usual drop-off / pick-up belongs in the glance, not three tabs down: it is
         what the room plans the day around.

         Shown ALWAYS, including when unset. Rendering nothing for an empty value
         made a brand-new field indistinguishable from a missing feature — it was
         reported as "I don't see this in the child record" twice, when in fact no
         child had a value yet. An empty state that names itself is the fix.
         (2026-08-25) */
      var _hasTimes = child.expected_dropoff_time || child.expected_pickup_time;
      _idText.appendChild(Dom.el('div', {
        style: 'font-size:13px;margin-top:5px;color:' + (_hasTimes ? '#374151' : '#9CA3AF') + ';',
      }, _hasTimes
          ? ('🕗 Usual drop-off ' + t12(child.expected_dropoff_time)
             + '  ·  pick-up ' + t12(child.expected_pickup_time))
          : '🕗 Usual drop-off / pick-up times not set — ' + (_departed ? 'not recorded' : 'add them with Edit') + ''));
      header.appendChild(headerLeft);


      var headerActions = Dom.el('div', {
        style: 'display:flex;gap:8px;',
      });
      /* Edit is now a MODE, not a single modal. The modal still owns the core fields
         and is reachable from inside the mode — but the tabs' own editors only come
         alive once the mode is on. */
      /* AN ARCHIVED RECORD IS READ-ONLY, AND MUST LOOK IT (2026-09-21).

         Anthony: "archived children and when I am viewing the archived record it has the
         button to archive again, remove that."

         is_archived was consulted in exactly one place - the banner a few lines above,
         which tells the reader this is "read-only history" - and nowhere else. So the
         screen said read-only while offering Edit and a red Archive button on a child who
         had already left. Pressing Archive would DELETE /director/children/{id} a second
         time on a row that is already soft-deleted.

         Read-only actions stay: the emergency card and the compliance report are exactly
         what an archived record is kept FOR. */
      var _readOnly = _departed;   // see the note on _departed above

      if (_readOnly) {
        /* Nothing to add: the banner has already explained why. An explanation beats a
           row of disabled buttons nobody can act on. */
      } else if (editMode) {
        headerActions.appendChild(btn('📝 Edit details…', btnSecondary(), function () {
          showEditModal(child, function () { renderChildDetail(container); });
        }));
        headerActions.appendChild(btn('✓ Done', btnPrimary(), function () {
          editMode = false;
          renderChildDetail(container);
        }));
      } else {
        headerActions.appendChild(btn('✏️ Edit', btnPrimary(), function () {
          editMode = true;
          renderChildDetail(container);
          /* Straight into the field they came for: opening the details modal on entry
             would be presumptuous when the thing they want may be on a tab. */
        }));
      }
      headerActions.appendChild(btn('🚨 Emergency card', btnSecondary(), function () {
        var token = sessionStorage.getItem('kt_token');
        var apiBase = (window.KT && window.KT.API_BASE) || 'https://api.kiddietrac.com/api/v1';
        var hdrs = { 'Authorization': 'Bearer ' + token, 'Accept': 'text/html' };
        var aa = sessionStorage.getItem('kt_active_agency_id'); if (aa) hdrs['X-Active-Agency-Id'] = aa;
        fetch(apiBase + '/director/children/' + child.id + '/emergency-card', {
          headers: hdrs,
        }).then(function (r) {
          if (!r.ok) throw new Error('HTTP ' + r.status);
          return r.text();
        }).then(function (html) {
          /* IN THE PORTAL, NOT INSTEAD OF IT (2026-09-15).

             This opened a blank window and wrote the card into it. document.write()
             replaces the whole document, which took the router with it (the bottom bar
             stopped navigating), the theme-color and kt-native-ui's StatusBar paint
             (the top bar stopped being navy), and any way back — a written-into window
             has no history and close() is refused inside a web view. Reported as three
             separate faults; it was one.

             KT.viewHtml keeps the portal's document alive and shows the card in the
             same panel the portal already uses for PDFs, with Print and ✕ in its
             header. `hide` suppresses the card's own toolbar, which was built for the
             standalone tab and whose Close would now act on the iframe. */
          if (window.KT && KT.viewHtml) {
            KT.viewHtml(html, {
              title: (child.full_name || 'Child') + ' — emergency card',
              label: 'Emergency card',
              hide: '.toolbar',
              /* Only asked for when the Print button is actually pressed inside the
                 app, so no link is minted for the ordinary case of reading the card on
                 screen. Five minutes, signed, and audited at both ends. */
              externalPrint: function () {
                return fetch(apiBase + '/director/children/' + child.id + '/emergency-card/print-link', {
                  headers: hdrs,
                }).then(function (r) {
                  if (!r.ok) throw new Error('HTTP ' + r.status);
                  return r.json();
                }).then(function (d) { return d && d.url; });
              },
            });
            return;
          }
          // Older cached build with no viewer: the previous behaviour, unchanged.
          var w = window.open('', '_blank');
          if (!w) { alert('Pop-up blocked. Allow pop-ups to view the emergency card.'); return; }
          w.document.open();
          w.document.write(html);
          w.document.close();
        }).catch(function (e) { alert('Could not load emergency card: ' + e.message); });
      }));
      if (! _readOnly) {
        headerActions.appendChild(btn('🗄️ Archive', btnDanger(), function () {
          showArchiveConfirm(child, function () {
            /* In the popup there is no list to navigate back TO - the list is still
             behind it. Close and let the caller refresh in place. */
          if (opts && typeof opts.onDone === 'function') { opts.onDone(); }
          else { window.location.hash = backHash(params); }
          });
        }));
      }
      headerActions.appendChild(btn('📋 Compliance report', btnSecondary(), function () {
        var _e = function (s) { return (s == null ? '' : String(s)).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;'); };
        var hf = (child.health_flags && child.health_flags.length) ? child.health_flags.map(function (f) { return '<li>' + _e(f.label || f.type || f.note || 'Health flag') + '</li>'; }).join('') : '<li>None on file</li>';
        /* Same trap as the emergency card above: a written-into window leaves the
           portal behind. KT.docWindow answers the same document.write API and renders
           into the portal's panel instead. Falls back to the old behaviour on a cached
           build that predates the viewer. */
        var w = (window.KT && KT.docWindow)
          ? KT.docWindow({ title: (child.full_name || 'Child') + ' — compliance report', label: 'Compliance report' })
          : window.open('', '_blank');
        if (!w) { alert('Please allow pop-ups to generate the report.'); return; }
        w.document.write('<!doctype html><html><head><meta charset="utf-8"><title>Compliance Report - ' + _e(child.full_name) + '</title>'
          + '<style>body{font-family:Arial,Helvetica,sans-serif;color:#0a1e2c;max-width:760px;margin:22px auto;padding:0 22px}h1{color:#1F6080;font-size:23px;margin:0 0 2px}.sub{color:#777;font-size:13px;margin-bottom:18px}table{width:100%;border-collapse:collapse;margin-bottom:6px}td{padding:7px 4px;border-bottom:1px solid #eee;font-size:14px;vertical-align:top}td.l{color:#666;width:210px}h2{font-size:14px;color:#1F6080;border-bottom:2px solid #1F6080;padding-bottom:4px;margin:22px 0 6px;text-transform:uppercase;letter-spacing:.5px}ul{margin:6px 0 6px 18px}.foot{margin-top:30px;font-size:11px;color:#999;border-top:1px solid #eee;padding-top:10px}@media print{.noprint{display:none}}</style></head><body>'
          + '<div style="display:flex;justify-content:space-between;align-items:flex-start"><div><h1>Child Compliance Report</h1><div class="sub">Generated ' + _e(new Date().toLocaleString()) + '</div></div>'
          + '<button class="noprint" onclick="window.print()" style="padding:9px 16px;background:#1F6080;color:#fff;border:none;border-radius:7px;cursor:pointer;font-size:13px">Print / Save as PDF</button></div>'
          + '<h2>Child</h2><table>'
          + '<tr><td class="l">Full name</td><td>' + _e(child.full_name) + '</td></tr>'
          + '<tr><td class="l">Preferred name</td><td>' + _e(child.preferred_name || '—') + '</td></tr>'
          + '<tr><td class="l">Date of birth</td><td>' + _e(fmtDate(child.date_of_birth)) + '</td></tr>'
          + '<tr><td class="l">Age</td><td>' + _e(child.age && child.age.human ? child.age.human : '—') + '</td></tr>'
          + '<tr><td class="l">Gender</td><td>' + _e(child.gender ? child.gender.replace(/_/g, ' ') : '—') + '</td></tr>'
          + '<tr><td class="l">Pronouns</td><td>' + _e(child.pronouns || '—') + '</td></tr></table>'
          + '<h2>Enrollment</h2><table>'
          + '<tr><td class="l">Family</td><td>' + _e(child.family ? child.family.family_name : '—') + '</td></tr>'
          + '<tr><td class="l">Room</td><td>' + _e(child.room ? child.room.name : '—') + '</td></tr>'
          + '<tr><td class="l">Status</td><td>' + ((child.enrollment && child.enrollment.end_date) ? 'Withdrawn' : (child.enrollment ? 'Enrolled' : '—')) + '</td></tr>'
          + '<tr><td class="l">Enrolled since</td><td>' + _e(child.enrollment ? fmtDate(child.enrollment.start_date) : '—') + '</td></tr></table>'
          + '<h2>Health &amp; Safety</h2><table>'
          + '<tr><td class="l">Doctor</td><td>' + _e(child.doctor_name ? (child.doctor_name + (child.doctor_phone ? ' · ' + child.doctor_phone : '')) : '—') + '</td></tr>'
          + '<tr><td class="l">Health card (last 4)</td><td>' + _e(child.health_card_last4 ? ('xxxx-xxxx-' + child.health_card_last4) : '—') + '</td></tr></table>'
          + '<h2>Active health flags</h2><ul>' + hf + '</ul>'
          + '<div class="foot">KiddieTrac &middot; Reflects records on file at generation time. Confirm current requirements with your licensing authority.</div>'
          + '</body></html>'); w.document.close();
      }));
      header.appendChild(headerActions);
      wrap.appendChild(header);

      /* Edit mode has to be visible, and visible WHERE THE MODE APPLIES — directly under
         the name, above the tabs. Appended to the container earlier, it rendered below
         everything: a notice about the mode you are in, underneath the content it
         governs. */
      if (editMode) {
        wrap.appendChild(Dom.el('div', {
          style: 'background:#FFFBEB;border:1px solid #FDE68A;border-radius:10px;'
               + 'padding:9px 14px;margin:0 0 14px;font-size:13px;color:#92400E;'
               + 'display:flex;align-items:center;gap:8px;',
        }, '✏️  Editing — changes on every tab are live. Press Done when you have finished.'));
      }

      // Detail card
      var card = Dom.el('div', {
        style: 'background:white;border-radius:14px;padding:20px 24px;box-shadow:0 1px 3px rgba(0,0,0,.06);margin-bottom:16px;',
      });
      card.appendChild(row('Status',
        '' + ((child.enrollment && !child.is_at_centre) ? (child.enrollment.end_date ? 'WITHDRAWN' : 'ENROLLED') : (child.is_at_centre ? 'AT CENTRE NOW' : '—'))));
      card.appendChild(row('Gender', child.gender ? child.gender.replace(/_/g, ' ') : '—'));
      card.appendChild(row('Pronouns', child.pronouns));
      card.appendChild(row('Family', child.family ? child.family.family_name : '—'));
      card.appendChild(row('Room', child.room ? child.room.name : '—'));
      if (child.enrollment) {
        card.appendChild(row('Enrolled since', fmtDate(child.enrollment.start_date)));
        card.appendChild(row('Monthly fee', child.enrollment.monthly_fee ? ('$' + child.enrollment.monthly_fee) : '—'));
      }
      card.appendChild(row('Doctor', child.doctor_name ? (child.doctor_name + (child.doctor_phone ? ' · ' + child.doctor_phone : '')) : '—'));
      card.appendChild(row('Health card', child.health_card_last4 ? ('xxxx-xxxx-' + child.health_card_last4) : '—'));
      card.appendChild(row('Medical notes', child.medical_notes));
      card.appendChild(row('Dietary notes', child.dietary_notes));
      card.appendChild(row('Cultural notes', child.cultural_notes));
      wrap.appendChild(card);

      // #10 — Allergies & health alerts: safety-critical, so shown prominently in
      // a red-bordered banner whenever there's an allergy or health alert on file.
      (function () {
        /* THESE ARE STRUCTURED RECORDS, NOT STRINGS.

           This printed `String(value)` straight onto the page. That was fine while the
           columns held the comma-separated text somebody typed at enrolment — and wrong
           the moment anything wrote the structured form the data model has carried since
           v22p1: an array of objects renders as "[object Object]", and a JSON array of
           strings renders with its brackets and quotes showing.

           So each entry is read properly and shown as what it is: the allergen, how
           severe, the reaction, and — the part that matters at the moment it matters —
           where the EpiPen is. Legacy plain text still renders, because plenty of records
           still hold it. (Anthony, 2026-09-10) */
        function readList(raw, key) {
          if (!raw) { return []; }
          var arr = raw;
          if (typeof raw === 'string') {
            var t = raw.trim();
            if (!t) { return []; }
            try {
              arr = JSON.parse(t);
              if (!Array.isArray(arr)) { throw new Error('not a list'); }
            } catch (e) { arr = t.split(','); }
          }
          if (!Array.isArray(arr)) { return []; }
          return arr.map(function (x) {
            if (typeof x === 'string') { var o = {}; o[key] = x.trim(); return o; }
            return x || {};
          }).filter(function (x) { return String(x[key] || '').trim(); });
        }

        var allergies = readList(child.allergies, 'allergen');
        var alerts = readList(child.health_alerts, 'label');
        var dietary = readList(child.dietary_restrictions, 'restriction');
        var anyThing = allergies.length || alerts.length || dietary.length;
        var critical = allergies.length || alerts.length;

        var sec = Dom.el('div', { style: 'border-radius:14px;padding:16px 20px;margin-bottom:16px;border:2px solid ' + (critical ? '#EF4444' : '#E5E7EB') + ';background:' + (critical ? '#FEF2F2' : '#F9FAFB') + ';' });

        var head = Dom.el('div', { style: 'display:flex;align-items:center;gap:12px;margin-bottom:' + (anyThing ? '12px' : '0') + ';' });
        head.appendChild(Dom.el('div', { style: 'font-size:12px;font-weight:800;letter-spacing:.5px;text-transform:uppercase;color:' + (critical ? '#B91C1C' : '#6B7280') + ';' }, (critical ? '⚠️ ' : '') + 'Allergies, dietary & health alerts'));
        /* Editable from the record itself. The same editor the Allergy alerts screen
           opens, so the two can never describe a child differently. */
        var edit = Dom.el('button', {
          type: 'button',
          style: 'margin-left:auto;background:#fff;border:1.5px solid ' + (critical ? '#FECACA' : '#D1D5DB')
            + ';color:' + (critical ? '#B91C1C' : '#475569') + ';border-radius:9px;padding:5px 13px;'
            + 'font-size:12.5px;font-weight:800;cursor:pointer;',
        }, anyThing ? '✏️ Edit' : '＋ Add');
        edit.setAttribute('data-kt-no-icon', '1');
        edit.addEventListener('click', function () {
          if (!(window.KT && KT.healthEditor)) { return; }
          KT.healthEditor.open(child, {
            // Same refresh the edit modal uses — renderChildDetail takes the container.
            onSaved: function () { renderChildDetail(container); },
          });
        });
        head.appendChild(edit);
        sec.appendChild(head);

        if (!anyThing) {
          sec.appendChild(Dom.el('div', { style: 'color:#64748B;font-size:13.5px;' }, 'None on file.'));
          wrap.appendChild(sec);
          return;
        }

        function block(title, rows, render, danger) {
          if (!rows.length) { return; }
          var b = Dom.el('div', { style: 'padding:9px 0;border-top:1px solid ' + (critical ? '#FECACA' : '#EEF2F5') + ';' });
          b.appendChild(Dom.el('div', {
            style: 'font-size:12px;font-weight:700;color:' + (danger ? '#B91C1C' : '#6B7280')
              + ';text-transform:uppercase;letter-spacing:.3px;margin-bottom:5px;',
          }, title));
          rows.forEach(function (r) { b.appendChild(render(r)); });
          sec.appendChild(b);
        }

        var SEVCLR = { anaphylactic: ['#991B1B', '#FEE2E2'], moderate: ['#92400E', '#FEF3C7'], mild: ['#166534', '#DCFCE7'] };

        block('Allergies', allergies, function (a) {
          var row = Dom.el('div', { style: 'display:flex;align-items:baseline;gap:9px;flex-wrap:wrap;padding:3px 0;' });
          row.appendChild(Dom.el('span', { style: 'font-size:14px;font-weight:700;color:#0F172A;' }, a.allergen));
          if (a.severity) {
            var c = SEVCLR[a.severity] || SEVCLR.mild;
            row.appendChild(Dom.el('span', {
              style: 'font-size:11px;font-weight:800;color:' + c[0] + ';background:' + c[1]
                + ';border-radius:999px;padding:1px 8px;text-transform:capitalize;',
            }, a.severity));
          }
          if (a.reaction) { row.appendChild(Dom.el('span', { style: 'font-size:13px;color:#475569;' }, '· ' + a.reaction)); }
          if (a.epipen_required) {
            row.appendChild(Dom.el('span', {
              style: 'font-size:11.5px;font-weight:800;color:#991B1B;background:#FEE2E2;border:1px solid #FECACA;'
                + 'border-radius:999px;padding:1px 9px;',
            }, '💉 EpiPen' + (a.epipen_location ? ' — ' + a.epipen_location : ' (location not recorded)')));
          }
          if (a.action_plan) {
            row.appendChild(Dom.el('div', { style: 'flex-basis:100%;font-size:12.5px;color:#7F1D1D;margin-top:2px;' }, 'Plan: ' + a.action_plan));
          }
          return row;
        }, true);

        block('Health alerts', alerts, function (a) {
          return Dom.el('div', { style: 'font-size:14px;font-weight:600;color:#0F172A;padding:3px 0;' },
            a.label + (a.detail ? ' — ' + a.detail : ''));
        }, true);

        block('Dietary', dietary, function (a) {
          return Dom.el('div', { style: 'font-size:14px;font-weight:600;color:#0F172A;padding:3px 0;' },
            a.restriction + (a.details ? ' — ' + a.details : ''));
        }, false);

        wrap.appendChild(sec);
      })();

      // #10 — Family record: full address + contacts + guardians, right on the
      // child's detail so staff can reach the family without hunting for it.
      (function () {
        var f = child.family;
        if (!f) return;
        var esc2 = function (s) { return String(s == null ? '' : s); };
        var addr = [f.address_line1, f.address_line2, f.city, f.province, f.postal_code].filter(Boolean).join(', ');
        var sec = Dom.el('div', { style: 'background:white;border-radius:14px;padding:16px 20px 18px;box-shadow:0 1px 3px rgba(0,0,0,.06);margin-bottom:16px;' });
        var head = Dom.el('div', { style: 'display:flex;justify-content:space-between;align-items:center;gap:10px;margin-bottom:10px;' });
        head.appendChild(Dom.el('div', { style: 'font-size:12px;font-weight:800;letter-spacing:.5px;text-transform:uppercase;color:#6B7280;' }, '👪 Family record'));
        var openLink = Dom.el('a', { href: '#admin-families', style: 'font-size:12.5px;color:#1F6080;text-decoration:none;font-weight:700;' }, 'Open in Families →');
        head.appendChild(openLink);
        sec.appendChild(head);
        var grid = Dom.el('table', { 'data-kt-filtered': '1', style: 'width:100%;border-collapse:collapse;font-size:13.5px;' });
        var addRow = function (label, value) {
          if (!value) return;
          var tr = Dom.el('tr', {});
          tr.appendChild(Dom.el('td', { style: 'padding:5px 14px 5px 0;color:#64748B;white-space:nowrap;vertical-align:top;width:150px;' }, label));
          tr.appendChild(Dom.el('td', { style: 'padding:5px 0;font-weight:600;color:#0F172A;' }, esc2(value)));
          grid.appendChild(tr);
        };
        addRow('Family', f.family_name);
        addRow('Address', addr);
        addRow('Phone', f.primary_phone);
        addRow('Email', f.primary_email);
        addRow('Language', f.preferred_lang);
        sec.appendChild(grid);
        // Guardians / contacts.
        if (child.guardians && child.guardians.length) {
          sec.appendChild(Dom.el('div', { style: 'font-size:11.5px;font-weight:800;color:#6B7280;text-transform:uppercase;letter-spacing:.3px;margin:12px 0 6px;' }, 'Guardians'));
          child.guardians.forEach(function (g) {
            var name = ((g.first_name || '') + ' ' + (g.last_name || '')).trim() || 'Guardian';
            var bits = [];
            if (g.relationship) bits.push(g.relationship);
            if (g.is_primary) bits.push('primary');
            if (g.can_pickup) bits.push('can pick up');
            var gr = Dom.el('div', { style: 'display:flex;justify-content:space-between;gap:10px;padding:8px 0;border-top:1px solid #EEF2F5;font-size:13px;align-items:center;' });
            var gWho = Dom.el('div', { style: 'display:flex;align-items:center;gap:10px;min-width:0;' });
            gWho.appendChild(_avatarEl(name, {
              size: 34,
              photoUrl: g.photo_url ? mediaUrl(g.photo_url) : '',
              sex: g.sex || null,
              userId: g.user_id || g.id || null,
              role: 'guardian',
            }));
            gr.appendChild(gWho);
            gWho.appendChild(Dom.el('div', {}, [
              Dom.el('div', { style: 'font-weight:700;color:#0F172A;' }, name + (bits.length ? ' · ' + bits.join(', ') : '')),
              g.email ? Dom.el('div', { style: 'color:#64748B;font-size:12px;' }, g.email) : Dom.el('span', {}),
            ]));
            sec.appendChild(gr);
          });
        }
        wrap.appendChild(sec);
      })();

      // v22p88: tabbed sections — Overview / Enrollment / Daily reports / Live feed / Attachments
      /* Immunisation sits between the health-shaped tabs and the paperwork, because that
         is what it is: a compliance question with a document behind it. It had no home
         here at all — the records were filed on the child (scope_type 'child') and only
         ever readable from the immunization SECTION or buried in Attachments among
         everything else, so the one screen about this child could not answer "is she up
         to date?". (Anthony, 2026-09-10) */
      var TABS = [['overview', '👤 Overview'], ['enrollment', '🏫 Enrollment'], ['daily', '📊 Daily reports'], ['incidents', '🩹 Incidents'], ['immunization', '💉 Immunization'], ['feed', '📸 Live feed'], ['attachments', '📎 Attachments'], ['history', '🗄️ Full history']];
      var activeTab = 'overview';
      var tabbar = Dom.el('div', { style: 'display:flex;gap:4px;border-bottom:1px solid #E5E7EB;margin:4px 0 18px;overflow-x:auto;overflow-y:hidden;' });
      var tabContent = Dom.el('div', {});
      function paintTabs() {
        Dom.clear(tabbar);
        TABS.forEach(function (t) {
          var on = activeTab === t[0];
          var b = Dom.el('button', { style: 'padding:10px 16px;border:none;background:transparent;cursor:pointer;font-size:14px;font-weight:600;white-space:nowrap;' + (on ? 'color:#1F6080;border-bottom:2px solid #1F6080;margin-bottom:-1px;' : 'color:#6B7280;') }, t[1]);
          b.addEventListener('click', function () { activeTab = t[0]; paintTabs(); paintTab(); });
          tabbar.appendChild(b);
        });
      }
      // Everything appended straight to the page - guardians, contacts and the
      // rest - lives outside the tab system. With the tabs at the top it renders
      // beneath whichever tab is open and reads as another tab leaking in.
      //
      // It is MOVED into one Overview-owned container rather than hidden in place.
      // Hiding only caught what existed at the first paint and only direct
      // children; sections appended later by other closures were missed, which is
      // why content still showed under Live feed. Once moved, a tab can only show
      // what it renders, because nothing else remains loose on the page.
      var looseBox = null;
      function gatherLoose() {
        try {
          if (!looseBox) { looseBox = Dom.el('div', {}); wrap.appendChild(looseBox); }
          var fixed = [header, tabbar, tabContent, looseBox];
          [].slice.call(wrap.children).forEach(function (el) {
            if (fixed.indexOf(el) === -1) looseBox.appendChild(el);   // appendChild MOVES
          });
        } catch (e) {}
      }

      function paintTab() {
        gatherLoose();
        if (looseBox) looseBox.style.display = (activeTab === 'overview') ? '' : 'none';
        Dom.clear(tabContent);
        if (activeTab === 'incidents') appendIncidentsSection(tabContent, child.id);
        else if (activeTab === 'enrollment') renderEnrollmentTab(tabContent, child);
        else if (activeTab === 'daily') renderDailyTab(tabContent, child);
        else if (activeTab === 'feed') renderFeedTab(tabContent, child);
        else if (activeTab === 'immunization') renderImmunizationTab(tabContent, child);
        else if (activeTab === 'attachments') renderAttachmentsTab(tabContent, child);
        else if (activeTab === 'history') renderHistoryTab(tabContent, child);
        else renderOverviewTab(tabContent, child);
      }
      // Tabs sit directly under the header — they decide what you are looking at,
      // so they cannot appear below the thing they control. insertBefore rather than
      // appendChild because a section of content is built before this point.
      if (header && header.nextSibling) wrap.insertBefore(tabbar, header.nextSibling);
      else wrap.appendChild(tabbar);
      if (tabbar && tabbar.nextSibling) wrap.insertBefore(tabContent, tabbar.nextSibling);
      else wrap.appendChild(tabContent);
      paintTabs();
      paintTab();
    }).catch(function (e) {
      Dom.clear(wrap);
      wrap.appendChild(Dom.el('div', {
        style: 'padding:24px;color:#DC2626;',
      }, 'Could not load child: ' + (e.message || 'error')));
    });
  }

  // Cards are tinted by KIND, not uniformly: a soft wash plus a coloured left edge
  // lets the eye find "the health one" or "the placement one" before reading a
  // heading, which is what makes a long record scannable. White-on-white relies
  // entirely on the shadow, and the shadow is the first thing to disappear on the
  // APK's brighter screen.
  var CARD_TONES = {
    neutral:   ['#FFFFFF', '#CBD5E1'],
    placement: ['#F5F9FF', '#1F6FB2'],
    health:    ['#FFF9F0', '#B45309'],
    care:      ['#F2FBFA', '#0E7C90'],
    people:    ['#F8F5FF', '#7C3AED'],
    files:     ['#F7FAFC', '#64748B'],
  };
  function tcard(tone) {
    var t = CARD_TONES[tone] || CARD_TONES.neutral;
    return 'background:' + t[0] + ';border-radius:14px;padding:20px 24px;margin-bottom:16px;'
      + 'border:1px solid #EDF2F7;border-left:4px solid ' + t[1] + ';'
      + 'box-shadow:0 1px 3px rgba(15,23,42,.05);';
  }
  function loadingBox(msg) { return Dom.el('div', { style: 'padding:30px;text-align:center;color:#64748B;' }, msg || 'Loading…'); }
  function emptyBox(msg) { return Dom.el('div', { style: 'padding:30px;text-align:center;color:#6B7280;' }, msg); }
  function fmtDateTime(s) { if (!s) return '—'; try { return new Date(s).toLocaleString(undefined, { month: 'short', day: 'numeric', hour: 'numeric', minute: '2-digit' }); } catch (e) { return s; } }
  function mediaUrl(u) {
    if (!u) return '';
    if (/^https?:\/\//i.test(u)) return u;
    var base = (window.KT && window.KT.API_BASE) || 'https://api.kiddietrac.com/api/v1';
    return base.replace(/\/api\/v1\/?$/, '') + u;
  }
  function detailRow(l, v) {
    var r = Dom.el('div', { style: 'display:grid;grid-template-columns:180px 1fr;gap:10px;padding:6px 0;font-size:14px;border-bottom:1px solid #F3F4F6;' });
    r.appendChild(Dom.el('div', { style: 'color:#6B7280;font-weight:600;' }, l));
    r.appendChild(Dom.el('div', {}, v || '—'));
    return r;
  }

  function renderOverviewTab(c, child) {
    if (child.guardians && child.guardians.length) {
      var g = Dom.el('div', { style: tcard('people') });
      g.appendChild(Dom.el('h3', { style: 'margin:0 0 12px;font-size:16px;' }, 'Guardians'));
      child.guardians.forEach(function (gu) {
        var line = (gu.first_name || '') + ' ' + (gu.last_name || '') + (gu.relationship ? ' · ' + gu.relationship : '') + (gu.is_primary ? ' (primary)' : '') + (gu.email ? '  ·  ' + gu.email : '') + (!gu.can_pickup ? '  ·  cannot pick up' : '');
        g.appendChild(Dom.el('div', { style: 'padding:6px 0;font-size:14px;border-bottom:1px solid #F3F4F6;' }, line));
      });
      c.appendChild(g);
    }
    if (child.health_flags && child.health_flags.length) {
      var h = Dom.el('div', { style: 'background:#FEF3C7;border-radius:14px;padding:20px 24px;margin-bottom:16px;' });
      h.appendChild(Dom.el('h3', { style: 'margin:0 0 12px;font-size:16px;color:#92400E;' }, '⚠️ Active health flags'));
      child.health_flags.forEach(function (hf) { h.appendChild(Dom.el('div', { style: 'padding:4px 0;font-size:14px;color:#92400E;' }, (hf.flag_type || 'flag').toUpperCase() + ': ' + (hf.detail || hf.name || ''))); });
      c.appendChild(h);
    }
    if (!c.children.length) c.appendChild(emptyBox('No guardians or health flags on file.'));
  }

  /**
   * The week in one grid: Day | Attending | Provider.
   *
   * Reads and writes two APIs because the two facts live in different places on purpose
   * (see the note at the call site). Keeps them coherent so the pair can never disagree:
   * choosing a provider implies the child attends that day, and marking a day
   * "not attending" clears the provider.
   */
  async function renderWeekTable(host, childId) {
    host.innerHTML = '<div style="font-size:13px;color:#94A3B8;padding:8px 0;">Loading…</div>';

    var sched, pattern;
    try {
      var both = await Promise.all([
        Api.get('/admin/children/' + childId + '/care-schedule'),
        Api.get('/attendance/pattern/' + childId).catch(function () { return { data: null }; }),
      ]);
      sched = both[0];
      pattern = (both[1] && both[1].data) || null;
    } catch (e) {
      host.innerHTML = '<div style="font-size:13px;color:#B45309;">Could not load the schedule: '
        + esc(e.message || 'error') + '</div>';
      return;
    }

    // mon → monday: the two APIs disagree on day keys, so translate in one place.
    var LONG = { mon: 'monday', tue: 'tuesday', wed: 'wednesday', thu: 'thursday',
                 fri: 'friday', sat: 'saturday', sun: 'sunday' };
    var ROT = [['', 'Not attending'], ['full', 'Full day'], ['am', 'Morning only'],
               ['pm', 'Afternoon only'], ['before', 'Before school'],
               ['after', 'After school'], ['bna', 'Before & after']];

    var days = sched.days || [];
    var labels = sched.labels || {};
    var week = sched.week || {};
    var providers = sched.providers || [];

    function provOptions(selected) {
      var byCentre = {};
      providers.forEach(function (p) { (byCentre[p.centre_name] = byCentre[p.centre_name] || []).push(p); });
      var html = '<option value="">— none —</option>';
      Object.keys(byCentre).forEach(function (cn) {
        var rooms = byCentre[cn], multi = rooms.length > 1;
        if (multi) { html += '<optgroup label="' + esc(cn) + '">'; }
        rooms.forEach(function (p) {
          html += '<option value="' + p.room_id + '"' + (String(selected) === String(p.room_id) ? ' selected' : '')
            + '>' + esc(multi ? p.room_name : cn) + '</option>';
        });
        if (multi) { html += '</optgroup>'; }
      });
      return html;
    }

    function rotOptions(selected) {
      return ROT.map(function (r) {
        return '<option value="' + r[0] + '"' + (String(selected || '') === r[0] ? ' selected' : '')
          + '>' + r[1] + '</option>';
      }).join('');
    }

    /* Read-only shows the answer; edit mode shows the controls. `inert` alone left a
       dropdown that looked live and did nothing, which reads as a broken screen. */
    var weekReadOnly = !editMode;

    var rows = days.map(function (day) {
      var cell = week[day];
      var rot = pattern ? (pattern[LONG[day]] || '') : (cell ? 'full' : '');
      var isToday = sched.today === day;

      if (weekReadOnly) {
        var rotTxt = (ROT.filter(function (r) { return r[0] === String(rot || ''); })[0] || ROT[0])[1];
        var provTxt = cell
          ? ((providers.filter(function (p) { return String(p.room_id) === String(cell.room_id); })[0] || {}).centre_name
             || cell.centre_name || cell.room_name || '—')
          : '—';
        return '<tr' + todayRowAttr(isToday) + '>'
          + '<td style="padding:7px 8px;font-size:13.5px;font-weight:' + (isToday ? '800' : '600')
            + ';color:#0F172A;white-space:nowrap;">' + esc(labels[day] || day)
            + (isToday ? todayTag() : '') + '</td>'
          + '<td style="padding:7px 8px;font-size:13.5px;color:'
            + (rot ? '#0F172A' : '#94A3B8') + ';">' + esc(rotTxt) + '</td>'
          + '<td style="padding:7px 8px;font-size:13.5px;color:'
            + (cell ? '#0F172A' : '#94A3B8') + ';">' + esc(provTxt) + '</td>'
          + '</tr>';
      }

      return '<tr' + todayRowAttr(isToday) + '>'
        + '<td style="padding:6px 8px;font-size:13.5px;font-weight:' + (isToday ? '800' : '600') + ';color:#0F172A;white-space:nowrap;">'
          + esc(labels[day] || day) + (isToday ? todayTag() : '')
        + '</td>'
        + '<td style="padding:6px 8px;"><select data-wk-rot="' + day + '" style="width:100%;padding:6px 9px;font-size:13px;">'
          + rotOptions(rot) + '</select></td>'
        + '<td style="padding:6px 8px;"><select data-wk-prov="' + day + '" style="width:100%;padding:6px 9px;font-size:13px;">'
          + provOptions(cell ? cell.room_id : '') + '</select></td>'
        + '</tr>';
    }).join('');

    host.innerHTML =
      '<div style="font-size:13px;color:#334155;margin-bottom:8px;"><strong>Now:</strong> '
        + esc(sched.summary || '—') + '</div>'
      + '<div style="overflow-x:auto;"><table style="width:100%;border-collapse:collapse;">'
        + '<thead><tr>'
          + '<th style="text-align:left;padding:4px 8px;font-size:11px;font-weight:800;letter-spacing:.04em;text-transform:uppercase;color:#64748B;">Day</th>'
          + '<th style="text-align:left;padding:4px 8px;font-size:11px;font-weight:800;letter-spacing:.04em;text-transform:uppercase;color:#64748B;">Attending</th>'
          + '<th style="text-align:left;padding:4px 8px;font-size:11px;font-weight:800;letter-spacing:.04em;text-transform:uppercase;color:#64748B;">Provider</th>'
        + '</tr></thead><tbody>' + rows + '</tbody></table></div>'
      + (weekReadOnly
          ? '<div style="margin-top:10px;font-size:12.5px;color:#64748B;">'
            + 'Press <strong>Edit</strong> above to change who has this child on each day.</div>'
          : '<div style="display:flex;align-items:center;gap:10px;margin-top:12px;">'
            + '<button id="wk-save" class="kt-btn kt-btn-primary kt-btn-sm">Save schedule</button>'
            + '<span id="wk-msg" style="font-size:12.5px;color:#64748B;"></span>'
          + '</div>');

    if (weekReadOnly) { return; }

    /* Keep the two columns honest about each other rather than letting somebody save a
       provider for a day the child does not attend. */
    host.querySelectorAll('select[data-wk-prov]').forEach(function (sel) {
      sel.addEventListener('change', function () {
        var day = sel.getAttribute('data-wk-prov');
        var rotSel = host.querySelector('select[data-wk-rot="' + day + '"]');
        if (sel.value && rotSel && !rotSel.value) { rotSel.value = 'full'; }
        if (!sel.value && rotSel) { rotSel.value = ''; }
      });
    });
    host.querySelectorAll('select[data-wk-rot]').forEach(function (sel) {
      sel.addEventListener('change', function () {
        var day = sel.getAttribute('data-wk-rot');
        var provSel = host.querySelector('select[data-wk-prov="' + day + '"]');
        if (!sel.value && provSel) { provSel.value = ''; }
      });
    });

    var msg = host.querySelector('#wk-msg');
    host.querySelector('#wk-save').addEventListener('click', async function () {
      var btn = this;
      var weekPayload = {}, patPayload = { effective_from: (new Date()).toISOString().slice(0, 10) };
      host.querySelectorAll('select[data-wk-prov]').forEach(function (sel) {
        weekPayload[sel.getAttribute('data-wk-prov')] = sel.value ? parseInt(sel.value, 10) : null;
      });
      host.querySelectorAll('select[data-wk-rot]').forEach(function (sel) {
        patPayload[LONG[sel.getAttribute('data-wk-rot')]] = sel.value || null;
      });

      btn.disabled = true;
      msg.style.color = '#64748B';
      msg.textContent = 'Saving…';
      try {
        /* Providers first: it is the one that can be refused (capacity), and it is the
           one everything else reads. If it fails, the attendance days are left alone
           rather than saved against a placement that did not happen. */
        var r = await Api.put('/admin/children/' + childId + '/care-schedule', { week: weekPayload });
        try { await Api.post('/attendance/pattern/' + childId, patPayload); }
        catch (e2) {
          msg.style.color = '#B45309';
          msg.textContent = 'Providers saved, but the attendance days did not: ' + (e2.message || 'error');
          renderWeekTable(host, childId);
          return;
        }
        msg.style.color = '#166534';
        msg.textContent = r.summary || 'Saved';
        if (Dom.toast) { Dom.toast(r.message || 'Weekly schedule saved', 'success'); }
        if (r.over_capacity && r.over_capacity.length && Dom.toast) {
          Dom.toast('Over capacity — ' + r.over_capacity.join('; '), 'warning');
        }
        renderWeekTable(host, childId);
      } catch (e) {
        btn.disabled = false;
        msg.style.color = '#B91C1C';
        msg.textContent = (e.message || 'Could not save')
          + (e.errors ? ' — ' + Object.values(e.errors).flat().join(', ') : '');
      }
    });
  }

  function renderEnrollmentTab(c, child) {
    var card = Dom.el('div', { style: tcard('placement') });
    card.appendChild(Dom.el('h3', { style: 'margin:0 0 12px;font-size:16px;' }, 'Current placement'));
    card.appendChild(detailRow('Agency', child.agency ? child.agency.name : '—'));
    card.appendChild(detailRow('Centre', child.centre ? (child.centre.name + (child.centre.city ? ' · ' + child.centre.city : '')) : '—'));
    card.appendChild(detailRow('Room', child.room ? child.room.name : '—'));
    card.appendChild(detailRow('Status', (child.enrollment && child.enrollment.end_date) ? 'Withdrawn' : (child.enrollment ? 'Enrolled' : 'Not enrolled')));
    card.appendChild(detailRow('Enrolled since', child.enrollment ? fmtDate(child.enrollment.start_date) : '—'));
    card.appendChild(detailRow('Monthly fee', (child.enrollment && child.enrollment.monthly_fee) ? ('$' + child.enrollment.monthly_fee) : '—'));
    c.appendChild(card);

    var sc = Dom.el('div', { style: tcard('placement') });
    sc.appendChild(Dom.el('h3', { style: 'margin:0 0 12px;font-size:16px;' }, '🏫 School details'));
    if (child.school_name) { sc.appendChild(detailRow('School', child.school_name)); sc.appendChild(detailRow('Grade', child.school_grade)); }
    else sc.appendChild(emptyBox('No school on file (for school-age children, add it via Edit).'));
    c.appendChild(sc);

    var hist = Dom.el('div', { style: tcard('placement') });
    hist.appendChild(Dom.el('h3', { style: 'margin:0 0 12px;font-size:16px;' }, 'Provider history'));
    hist.appendChild(Dom.el('div', { style: 'font-size:12.5px;color:#64748B;margin:-6px 0 10px;' },
      'Every provider this child has been with, and on which days.'));
    var hh = child.enrollment_history || [];
    if (!hh.length) hist.appendChild(emptyBox('No provider history.'));
    else hh.forEach(function (e) {
      var open = !e.end_date;
      var row = Dom.el('div', {
        style: 'padding:8px 0;border-bottom:1px solid #F3F4F6;'
             + (open ? 'border-left:3px solid #1F6080;padding-left:9px;margin-left:-9px;' : ''),
      });
      row.appendChild(Dom.el('div', { style: 'font-size:14px;font-weight:' + (open ? '700' : '600') + ';color:#0F172A;' },
        (e.room_name || 'Room —') + (open ? '  ·  current' : '')));
      var bits = [fmtDate(e.start_date) + ' → ' + (e.end_date ? fmtDate(e.end_date) : 'present')];
      /* The days this provider has them. Seven means every day they attend, and saying
         so beats listing the whole week back at the reader. */
      var d = daysLabel(e.schedule);
      if (d) { bits.push(d); }
      if (e.monthly_fee && Number(e.monthly_fee) > 0) { bits.push('$' + e.monthly_fee + '/mo'); }
      row.appendChild(Dom.el('div', { style: 'font-size:12.5px;color:#64748B;margin-top:2px;' },
        bits.join('  ·  ')));
      hist.appendChild(row);
    });
    c.appendChild(hist);

    /* The week, in one table: which days this child attends and who has them.
       These were two separate cards asking about the same week — the days in one, the
       provider in the other — and keeping them agreed was left to the person using it.
       (Anthony, 2026-08-27)

       They are still stored separately, and that is deliberate: the provider lives on
       `enrollments`, because that is what every roster, ratio, check-in, meal claim and
       invoice reads. Copying it into attendance_patterns would create a second answer to
       the same question, and two answers drift. One table on screen; one home per fact. */
    var wk = Dom.el('div', { style: tcard('care') });
    wk.appendChild(Dom.el('h3', { style: 'margin:0 0 4px;font-size:16px;' }, '\ud83d\uddd3 Weekly schedule'));
    wk.appendChild(Dom.el('div', { style: 'font-size:12.5px;color:#64748B;margin-bottom:10px;' },
      'Which days this child attends, and which provider has them. Drives the room roster, ratios, check-in and meal counts.'));
    var wkHost = Dom.el('div', {});
    wk.appendChild(wkHost);
    c.appendChild(wk);
    renderWeekTable(wkHost, child.id);
    gateEditable(wkHost, 'The weekly schedule');
  }

  function renderDailyTab(c, child) {
    // A date picker, because "what happened on the 3rd" is as common a question as
    // "what happened today" — and until now the tab could only answer the second,
    // and only as an undated 21-day scroll.
    var state = { mode: 'day', date: (new Date()).toISOString().slice(0, 10) };

    var bar = Dom.el('div', { style: 'display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin-bottom:12px;' });
    var prev = Dom.el('button', { style: 'padding:8px 12px;border:1px solid #E2E8F0;background:#fff;border-radius:9px;cursor:pointer;font-weight:700;' }, '‹');
    var dateIn = Dom.el('input', { type: 'date', value: state.date, style: 'padding:8px 10px;border:1px solid #E2E8F0;border-radius:9px;font-size:14px;' });
    var next = Dom.el('button', { style: 'padding:8px 12px;border:1px solid #E2E8F0;background:#fff;border-radius:9px;cursor:pointer;font-weight:700;' }, '›');
    var todayBtn = Dom.el('button', { style: 'padding:8px 12px;border:1px solid #E2E8F0;background:#fff;border-radius:9px;cursor:pointer;font-size:13px;font-weight:700;' }, 'Today');
    var recentBtn = Dom.el('button', { style: 'padding:8px 12px;border:1px solid #E2E8F0;background:#fff;border-radius:9px;cursor:pointer;font-size:13px;font-weight:700;' }, 'Last 21 days');
    bar.appendChild(prev); bar.appendChild(dateIn); bar.appendChild(next);
    bar.appendChild(todayBtn); bar.appendChild(recentBtn);
    c.appendChild(bar);
    var host = Dom.el('div', {});
    c.appendChild(host);

    function shift(days) {
      var d = new Date(state.date + 'T12:00:00');
      d.setDate(d.getDate() + days);
      state.date = d.toISOString().slice(0, 10);
      dateIn.value = state.date; state.mode = 'day'; load();
    }
    prev.addEventListener('click', function () { shift(-1); });
    next.addEventListener('click', function () { shift(1); });
    dateIn.addEventListener('change', function () { state.date = dateIn.value; state.mode = 'day'; load(); });
    todayBtn.addEventListener('click', function () { state.date = (new Date()).toISOString().slice(0, 10); dateIn.value = state.date; state.mode = 'day'; load(); });
    recentBtn.addEventListener('click', function () { state.mode = 'recent'; load(); });

    var ICON = { meal: '🍽️', snack: '🍎', nap: '😴', nap_start: '😴', nap_end: '🌅', diaper: '🧷',
      bathroom: '🚽', activity: '✨', mood: '🙂', note: '📝', bottle: '🍼', sunscreen: '☀️', outdoor: '🌳',
      check_in: '✅', check_out: '👋' };

    function load() {
      Dom.clear(host);
      host.appendChild(loadingBox());
      var qs = state.mode === 'day' ? ('date=' + encodeURIComponent(state.date)) : 'days=21';
      Api.get('/director/children/' + child.id + '/daily-events?' + qs).then(function (d) {
        Dom.clear(host);
        var rows = [];
        (d.checks || []).forEach(function (x) {
          rows.push({ t: x.occurred_at, kind: 'Attendance', label: (x.event_type || '').replace(/_/g, ' '),
            notes: x.notes, who: x.recorded_by_name, icon: ICON[x.event_type] || '•' });
        });
        (d.events || []).forEach(function (x) {
          var p = {};
          try { p = typeof x.payload === 'string' ? (JSON.parse(x.payload) || {}) : (x.payload || {}); } catch (e) {}
          // The payload carries what actually happened — the meal, the nap length,
          // the activity name. Showing only the event type threw that away.
          var detail = p.meal || p.name || p.type || p.score || '';
          rows.push({ t: x.occurred_at, kind: 'Care', label: (x.event_type || 'event').replace(/_/g, ' '),
            detail: detail, notes: p.note || x.notes, who: x.recorded_by_name, icon: ICON[x.event_type] || '•' });
        });
        // The care screen's own table, which this tab never read.
        (d.care_logs || []).forEach(function (x) {
          rows.push({ t: x.occurred_at, kind: 'Care', label: (x.log_type || 'care').replace(/_/g, ' '),
            detail: x.details, notes: x.notes, who: x.recorded_by_name, icon: ICON[x.log_type] || '•' });
        });
        rows.sort(function (a, b) { return (b.t || '').localeCompare(a.t || ''); });

        if (!rows.length) {
          host.appendChild(emptyBox(state.mode === 'day'
            ? 'Nothing logged on ' + state.date + '.'
            : 'No daily activity in the last 21 days.'));
          return;
        }
        host.appendChild(Dom.el('div', { style: 'font-size:12.5px;color:#64748B;margin-bottom:8px;' },
          rows.length + (rows.length === 1 ? ' entry' : ' entries')
          + (state.mode === 'day' ? ' on ' + state.date : ' in the last 21 days')));

        var card = Dom.el('div', { style: tcard('care') });
        rows.forEach(function (e) {
          var r = Dom.el('div', { style: 'display:flex;gap:12px;padding:10px 0;border-bottom:1px solid #EEF3F7;font-size:14px;align-items:flex-start;' });
          r.appendChild(Dom.el('div', { style: 'width:132px;flex-shrink:0;color:#6B7280;font-size:12px;' }, fmtDateTime(e.t)));
          r.appendChild(Dom.el('div', { style: 'flex-shrink:0;font-size:16px;width:22px;text-align:center;' }, e.icon));
          var txt = Dom.el('div', { style: 'flex:1;min-width:0;' });
          txt.appendChild(Dom.el('div', { style: 'font-weight:700;text-transform:capitalize;color:#0F172A;' },
            (e.label || '—') + (e.detail ? ' — ' + e.detail : '')));
          if (e.notes) txt.appendChild(Dom.el('div', { style: 'color:#475569;font-size:13px;margin-top:2px;' }, e.notes));
          if (e.who) txt.appendChild(Dom.el('div', { style: 'color:#94A3B8;font-size:11.5px;margin-top:3px;' }, 'logged by ' + e.who));
          r.appendChild(txt);
          card.appendChild(r);
        });
        host.appendChild(card);
      }).catch(function (err) {
        Dom.clear(host);
        host.appendChild(emptyBox('Could not load daily activity' + (err && err.message ? ' — ' + err.message : '') + '.'));
      });
    }
    load();
  }

  function renderFeedTab(c, child) {
    c.appendChild(loadingBox());
    Api.get('/director/children/' + child.id + '/feed').then(function (d) {
      Dom.clear(c);
      var media = d.media || [], obs = d.observations || [];
      if (!media.length && !obs.length) { c.appendChild(emptyBox('No photos or observations yet.')); return; }
      if (media.length) {
        var grid = Dom.el('div', { style: tcard('neutral') + 'display:grid;grid-template-columns:repeat(auto-fill,minmax(160px,1fr));gap:12px;' });
        media.forEach(function (m) {
          var url = m.url || m.file_url || m.media_url || m.thumbnail_url; if (!url) return;
          var fig = Dom.el('div', {});
          fig.appendChild(Dom.el('img', { src: mediaUrl(url), style: 'width:100%;height:130px;object-fit:cover;border-radius:10px;background:#F3F4F6;' }));
          if (m.caption) fig.appendChild(Dom.el('div', { style: 'font-size:12px;color:#6B7280;margin-top:4px;' }, m.caption));
          fig.appendChild(Dom.el('div', { style: 'font-size:11px;color:#64748B;' }, fmtDate(m.created_at)));
          grid.appendChild(fig);
        });
        c.appendChild(grid);
      }
      if (obs.length) {
        var oc = Dom.el('div', { style: tcard('care') });
        oc.appendChild(Dom.el('h3', { style: 'margin:0 0 12px;font-size:16px;' }, 'Observations'));
        obs.forEach(function (o) {
          var r = Dom.el('div', { style: 'padding:8px 0;border-bottom:1px solid #F3F4F6;font-size:14px;' });
          r.appendChild(Dom.el('div', { style: 'color:#64748B;font-size:12px;' }, fmtDate(o.created_at) + (o.domain ? '  ·  ' + String(o.domain).replace(/_/g, ' ') : '')));
          r.appendChild(Dom.el('div', {}, o.note || o.text || o.title || '—'));
          oc.appendChild(r);
        });
        c.appendChild(oc);
      }
    }).catch(function (e) { Dom.clear(c); c.appendChild(emptyBox('Could not load live feed: ' + (e.message || 'error'))); });
  }

  /* "09:00" -> "9:00 am". Entered in 24h, read by people who think in 12h.
     A wall-clock schedule value, so no timezone conversion applies. */
  function t12(hhmm) {
    var m = /^(\d{1,2}):(\d{2})/.exec(String(hhmm || ''));
    if (!m) return '\u2014';
    var h = +m[1], ap = h >= 12 ? 'pm' : 'am';
    h = h % 12; if (!h) h = 12;
    return h + ':' + m[2] + ' ' + ap;
  }

  /**
   * Attachments on a child record.
   *
   * Until 2026-08-25 this tab was READ-ONLY: it listed documents that no screen in
   * the portal had any way to create, so in practice it always said "none on file".
   * Upload / download / remove mirror the per-user document panel in screen-admin.js
   * deliberately, so the two behave identically wherever a file is filed.
   */
  /**
   * FULL HISTORY — everything held about this child, from every table that carries
   * their id.
   *
   * Anthony, 2026-08-25: "all things related to the family and child should be in the
   * archive — photos, observations, meal plans, lesson plans, chats, everything." A
   * schema audit found 57 tables carrying a child_id or family_id, so the server walks
   * a declared list and this screen simply renders whatever comes back: a section it
   * has never heard of still displays correctly, which is the point.
   *
   * Sections start COLLAPSED. One child had 427 records; opening the tab straight into
   * a 400-row wall is not "accessible", it is unreadable.
   */
  function renderHistoryTab(c, child) {
    c.appendChild(loadingBox());
    Api.get('/director/children/' + child.id + '/history').then(function (d) {
      Dom.clear(c);
      var sections = d.sections || [];
      var subject = d.subject || {};

      var head = Dom.el('div', { style: 'display:flex;align-items:baseline;justify-content:space-between;gap:12px;flex-wrap:wrap;margin-bottom:12px;' });
      head.appendChild(Dom.el('div', { style: 'font-size:14px;color:#374151;' },
        (d.total_records || 0) + ' record' + ((d.total_records === 1) ? '' : 's') + ' across '
        + sections.length + ' categor' + (sections.length === 1 ? 'y' : 'ies')
        + ' \u00b7 open one to page through it'));
      if (subject.is_archived) {
        head.appendChild(Dom.el('span', {
          style: 'font-size:12px;font-weight:700;color:#9A3412;background:#FFF7ED;border:1px solid #FED7AA;border-radius:6px;padding:2px 8px;',
        }, 'Archived \u2014 retained history'));
      }
      c.appendChild(head);

      if (!sections.length) {
        c.appendChild(emptyBox('Nothing else is on file for this child yet.'));
        return;
      }

      sections.forEach(function (sec) {
        var box = Dom.el('div', { style: 'background:#fff;border:1px solid #EEF0F3;border-radius:10px;margin-bottom:8px;overflow:hidden;' });

        var bar = Dom.el('button', {
          type: 'button',
          style: 'width:100%;display:flex;align-items:center;gap:10px;padding:11px 14px;background:none;border:0;cursor:pointer;font-family:inherit;text-align:left;',
        });
        var caret = Dom.el('span', { style: 'font-size:11px;color:#9CA3AF;width:12px;flex:0 0 auto;' }, '\u25b6');
        bar.appendChild(caret);
        bar.appendChild(Dom.el('span', { style: 'font-size:16px;flex:0 0 auto;' }, sec.icon || '\u2022'));
        bar.appendChild(Dom.el('span', { style: 'font-weight:700;font-size:13.5px;color:#111827;flex:1;' }, sec.label));
        bar.appendChild(Dom.el('span', {
          style: 'font-size:12px;font-weight:700;color:#4B5563;background:#F3F4F6;border-radius:20px;padding:2px 9px;flex:0 0 auto;',
        }, String(sec.count == null ? '?' : sec.count)));
        box.appendChild(bar);

        var body = Dom.el('div', { style: 'display:none;border-top:1px solid #F3F4F6;' });
        box.appendChild(body);

        /* One page at a time. The server pages in SQL and returns only the section
           asked for, so turning a page reads one table rather than all 57. */
        function renderSectionPage(data) {
          Dom.clear(body);

          if (data.error) {
            body.appendChild(Dom.el('div', { style: 'padding:12px 14px;font-size:13px;color:#B91C1C;' }, data.error));
            return;
          }

          (data.rows || []).forEach(function (r, i) {
            var row = Dom.el('div', {
              style: 'display:flex;gap:12px;padding:9px 14px;font-size:13px;align-items:center;' + (i ? 'border-top:1px solid #F8FAFC;' : ''),
            });
            row.appendChild(Dom.el('div', {
              style: 'flex:0 0 148px;color:#6B7280;font-size:12px;white-space:nowrap;',
            }, r.when ? fmtDate(r.when) : '\u2014'));
            var mid = Dom.el('div', { style: 'flex:1;min-width:0;' });
            mid.appendChild(Dom.el('div', { style: 'color:#111827;' }, r.title || '\u2014'));
            if (r.detail) mid.appendChild(Dom.el('div', { style: 'color:#6B7280;font-size:12px;margin-top:1px;' }, r.detail));
            row.appendChild(mid);
            if (r.thumb) {
              row.appendChild(Dom.el('img', {
                src: mediaUrl(r.thumb),
                style: 'width:44px;height:44px;object-fit:cover;border-radius:6px;flex:0 0 auto;background:#F3F4F6;',
              }));
            } else if (r.url) {
              row.appendChild(Dom.el('a', {
                href: mediaUrl(r.url), target: '_blank',
                style: 'flex:0 0 auto;color:#1F6080;font-size:12px;font-weight:700;text-decoration:none;',
              }, 'Open \u2197'));
            }
            body.appendChild(row);
          });

          var pages = data.pages || 1;
          var page = data.page || 1;
          if (pages <= 1) return;

          var pager = Dom.el('div', {
            style: 'display:flex;align-items:center;justify-content:space-between;gap:10px;padding:9px 14px;background:#FAFAFA;border-top:1px solid #F3F4F6;',
          });
          var from = ((page - 1) * (data.per_page || 25)) + 1;
          var to = from + (data.rows || []).length - 1;
          pager.appendChild(Dom.el('span', { style: 'font-size:12px;color:#6B7280;' },
            from + '\u2013' + to + ' of ' + data.count));

          var nav = Dom.el('div', { style: 'display:flex;align-items:center;gap:6px;' });
          function navBtn(label, target, enabled) {
            var b = Dom.el('button', {
              type: 'button',
              style: 'padding:4px 10px;font-size:12px;font-weight:700;font-family:inherit;border-radius:6px;'
                + (enabled
                    ? 'border:1px solid #D1D5DB;background:#fff;color:#374151;cursor:pointer;'
                    : 'border:1px solid #EEF0F3;background:#F9FAFB;color:#C4C9D0;cursor:default;'),
            }, label);
            if (enabled) b.addEventListener('click', function () { loadPage(target); });
            return b;
          }
          nav.appendChild(navBtn('\u2039 Prev', page - 1, page > 1));
          nav.appendChild(Dom.el('span', { style: 'font-size:12px;color:#374151;font-weight:600;padding:0 4px;' },
            'Page ' + page + ' of ' + pages));
          nav.appendChild(navBtn('Next \u203a', page + 1, page < pages));
          pager.appendChild(nav);
          body.appendChild(pager);
        }

        function loadPage(n) {
          Dom.clear(body);
          body.appendChild(Dom.el('div', { style: 'padding:12px 14px;font-size:13px;color:#6B7280;' }, 'Loading\u2026'));
          Api.get('/director/children/' + child.id + '/history?section='
                  + encodeURIComponent(sec.key) + '&page=' + n)
            .then(function (d) {
              var fresh = (d.sections || [])[0];
              renderSectionPage(fresh || { rows: [], error: 'That page could not be loaded.' });
            })
            .catch(function (e) {
              // A failed page must not read as an empty section.
              Dom.clear(body);
              body.appendChild(Dom.el('div', { style: 'padding:12px 14px;font-size:13px;color:#B91C1C;' },
                'Could not load page ' + n + ': ' + (e.message || 'error')));
            });
        }

        var built = false;
        bar.addEventListener('click', function () {
          var open = body.style.display !== 'none';
          body.style.display = open ? 'none' : '';
          caret.textContent = open ? '\u25b6' : '\u25bc';
          if (built || open) return;
          built = true;
          renderSectionPage(sec);   // page 1 already came with the first load
        });

        c.appendChild(box);
      });
    }).catch(function (e) {
      Dom.clear(c);
      c.appendChild(emptyBox('Could not load the full history: ' + (e.message || 'error')));
    });
  }

  /**
   * What this child still needs, what has been sent in, and a way to add to it.
   *
   * The whole tab is KT.immunPanel — the same component the family sees on their own
   * screen and the same one the immunization section opens from a roster row. One
   * implementation on purpose: a director telling a parent "you still owe us MMR" and the
   * parent looking at their phone have to be reading the same list, and three copies of
   * this markup is the shortest route to them not being.
   */
  function renderImmunizationTab(host, child) {
    Dom.clear(host);
    var wrap = Dom.el('div', { style: 'padding:4px 0 8px;' });
    host.appendChild(wrap);

    if (!(window.KT && KT.immunPanel)) {
      wrap.appendChild(Dom.el('div', { style: 'font-size:13px;color:#B91C1C;padding:12px 0;' },
        'This view could not load. Please reload the page.'));
      return;
    }
    /* 'director' picks the /director/... routes, which is what an admin or a centre
       director holds here. The route enforces access; this only chooses the pair. */
    KT.immunPanel(wrap, child, { scope: 'director', canUpload: true });
  }

  function renderAttachmentsTab(c, child) {
    var card = Dom.el('div', { style: tcard('neutral') });
    var list = Dom.el('div', {});
    card.appendChild(list);
    c.appendChild(card);

    function fmtSize(n) {
      n = Number(n || 0);
      if (!n) return '';
      if (n < 1024) return n + ' B';
      if (n < 1048576) return Math.round(n / 1024) + ' KB';
      return (n / 1048576).toFixed(1) + ' MB';
    }
    function docIcon(d) {
      if (d.category === 'agreement') return '\ud83d\udd0f';
      var t = (d.file_type || '') + ' ' + (d.file_url || '');
      if (/pdf/i.test(t)) return '\ud83d\udcc4';
      if (/(png|jpe?g|webp|gif|image)/i.test(t)) return '\ud83d\uddbc\ufe0f';
      if (/(doc|word)/i.test(t)) return '\ud83d\udcc3';
      if (/(xls|sheet|excel|csv)/i.test(t)) return '\ud83d\udcca';
      return '\ud83d\udcce';
    }
    /* View inside the portal's own document panel. Handing the URL to the browser
       sent APK users out to an external browser, session and all. */
    function openDoc(d) {
      var url = mediaUrl(d.file_url);
      if (!url) return;
      if (window.KT && KT.viewDocument) {
        KT.viewDocument(url, { title: d.title || 'Document', label: d.category || 'Document' });
        return;
      }
      if (window.KT && KT.openDocumentExternally) { KT.openDocumentExternally(url); return; }
      try { window.open(url, '_blank'); } catch (e) {}
    }
    /* Download streams through the API rather than the raw /storage URL, so the
       tenant check runs on the way out. If that fails we fall back to opening it —
       a failed download must never read to the user as "there is no file". */
    function downloadDoc(d, btnEl) {
      var token = sessionStorage.getItem('kt_token');
      var apiBase = (window.KT && window.KT.API_BASE) || 'https://api.kiddietrac.com/api/v1';
      var hdrs = { 'Authorization': 'Bearer ' + token };
      var aa = sessionStorage.getItem('kt_active_agency_id');
      if (aa) hdrs['X-Active-Agency-Id'] = aa;
      if (btnEl) btnEl.disabled = true;
      fetch(apiBase + '/director/children/' + child.id + '/documents/' + d.id + '/download', { headers: hdrs })
        .then(function (r) { if (!r.ok) throw new Error('HTTP ' + r.status); return r.blob(); })
        .then(function (blob) {
          var ext = (String(d.file_url || '').match(/\.([a-z0-9]+)$/i) || [])[1] || '';
          var base = String(d.title || 'document').replace(/[\\/:*?"<>|]/g, '_');
          var a = document.createElement('a');
          a.href = URL.createObjectURL(blob);
          a.download = /\.[a-z0-9]+$/i.test(base) ? base : (ext ? base + '.' + ext : base);
          document.body.appendChild(a); a.click(); document.body.removeChild(a);
          setTimeout(function () { URL.revokeObjectURL(a.href); }, 4000);
        })
        .catch(function () { openDoc(d); })
        .then(function () { if (btnEl) btnEl.disabled = false; });
    }

    function paint(docs) {
      Dom.clear(list);
      if (!docs.length) {
        list.appendChild(Dom.el('div', { style: 'font-size:13px;color:#6B7280;padding:6px 0 2px;' },
          'No attachments on file for this child.'));
        return;
      }
      docs.forEach(function (d) {
        var row = Dom.el('div', { style: 'display:flex;align-items:center;gap:10px;padding:9px 10px;background:#fff;border:1px solid #E5E7EB;border-radius:8px;margin-bottom:6px;' });
        row.appendChild(Dom.el('span', { style: 'font-size:20px;flex:0 0 auto;' }, docIcon(d)));
        var mid = Dom.el('div', { style: 'flex:1;min-width:0;' });
        mid.appendChild(Dom.el('div', { style: 'font-weight:700;font-size:13.5px;color:#111827;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;' }, d.title || 'Document'));
        var bits = [d.category || 'File'];
        if (d.file_size) bits.push(fmtSize(d.file_size));
        if (d.created_at) bits.push(fmtDate(d.created_at));
        if (d.expires_at) bits.push('expires ' + fmtDate(d.expires_at));
        if (d.uploaded_by) bits.push('by ' + d.uploaded_by);
        mid.appendChild(Dom.el('div', { style: 'font-size:11.5px;color:#6B7280;margin-top:1px;' }, bits.join('  \u00b7  ')));
        row.appendChild(mid);

        var openBtn = Dom.el('button', { type: 'button', style: 'flex:0 0 auto;font-size:12px;font-weight:700;color:#1F6080;background:#fff;cursor:pointer;padding:5px 10px;border:1px solid #1F6080;border-radius:6px;' }, 'Open');
        openBtn.addEventListener('click', function () { openDoc(d); });
        row.appendChild(openBtn);

        var dlBtn = Dom.el('button', { type: 'button', style: 'flex:0 0 auto;font-size:12px;font-weight:700;color:#374151;background:#fff;cursor:pointer;padding:5px 10px;border:1px solid #D1D5DB;border-radius:6px;' }, 'Download');
        dlBtn.addEventListener('click', function () { downloadDoc(d, dlBtn); });
        row.appendChild(dlBtn);

        if (d.category === 'agreement') {
          row.appendChild(Dom.el('span', { title: 'Legal record \u2014 cannot be deleted', style: 'flex:0 0 auto;font-size:13px;color:#6B7280;padding:4px 6px;' }, '\ud83d\udd12'));
        } else if (!editMode) {
          // Nothing: removing a file belongs to edit mode.
        } else {
          var del = Dom.el('button', { type: 'button', class: 'kt-act-icon kt-act-danger kt-icon-tip', title: 'Remove', 'data-kttip': 'Remove', 'aria-label': 'Remove', style: 'flex:0 0 auto;' }, '\ud83d\uddd1');
          del.addEventListener('click', async function () {
            var sure = (window.KT && KT.confirm)
              ? await KT.confirm('Remove "' + (d.title || 'this file') + '"?')
              : window.confirm('Remove "' + (d.title || 'this file') + '"?');
            if (!sure) return;
            del.disabled = true;
            try {
              await Api.delete('/director/children/' + child.id + '/documents/' + d.id);
              load();
            } catch (e) {
              del.disabled = false;
              if (Dom.toast) Dom.toast(e.message || 'Delete failed', 'error');
            }
          });
          row.appendChild(del);
        }
        list.appendChild(row);
      });
    }

    function load() {
      Dom.clear(list);
      list.appendChild(Dom.el('div', { style: 'font-size:13px;color:#6B7280;padding:8px 0;' }, 'Loading\u2026'));
      Api.get('/director/children/' + child.id + '/documents')
        .then(function (r) { paint((r && r.documents) || []); })
        .catch(function (e) {
          Dom.clear(list);
          list.appendChild(Dom.el('div', { style: 'font-size:13px;color:#B91C1C;' },
            'Could not load attachments: ' + (e.message || 'error')));
        });
    }

    /* Attaching a file is a change to the record like any other. Hidden rather than
       greyed out when read-only: an upload row you cannot use is just clutter, and the
       Open / Download buttons below stay live because reading is the point of the
       read-only view. */
    var up = Dom.el('div', { style: 'display:flex;flex-wrap:wrap;gap:8px;align-items:center;margin-top:12px;padding-top:12px;border-top:1px dashed #E5E7EB;'
      + (editMode ? '' : 'display:none;') });
    var titleIn = Dom.el('input', { type: 'text', placeholder: 'Title (optional)', style: 'flex:1;min-width:140px;padding:6px 10px;border:1px solid #D1D5DB;border-radius:6px;font-size:13px;' });
    var catSel = Dom.el('select', { style: 'padding:6px 10px;border:1px solid #D1D5DB;border-radius:6px;font-size:13px;background:#fff;' });
    [['file', 'File'], ['medical', 'Medical'], ['immunisation', 'Immunisation'],
     ['consent', 'Consent'], ['id', 'ID document'], ['court', 'Court order'],
     ['other', 'Other']].forEach(function (o) { catSel.appendChild(Dom.el('option', { value: o[0] }, o[1])); });
    var expIn = Dom.el('input', { type: 'date', title: 'Expires (optional)', style: 'padding:6px 10px;border:1px solid #D1D5DB;border-radius:6px;font-size:13px;' });
    var fileIn = Dom.el('input', { type: 'file', accept: '.pdf,.jpg,.jpeg,.png,.webp,.doc,.docx,.xls,.xlsx', style: 'display:none;' });
    var upBtn = Dom.el('button', { type: 'button', style: 'padding:7px 14px;background:#1F6080;color:#fff;border:0;border-radius:6px;font-size:12.5px;font-weight:700;cursor:pointer;' }, '\uff0b Attach file');
    var upMsg = Dom.el('span', { style: 'font-size:12px;color:#6B7280;' });
    upBtn.addEventListener('click', function () { fileIn.click(); });
    fileIn.addEventListener('change', async function () {
      var f = fileIn.files[0];
      if (!f) return;
      if (f.size > 10 * 1024 * 1024) { upMsg.style.color = '#B91C1C'; upMsg.textContent = 'Max 10 MB'; return; }
      upBtn.disabled = true; upMsg.style.color = '#6B7280'; upMsg.textContent = 'Uploading\u2026';
      try {
        var fd = new FormData();
        fd.append('file', f);
        if (titleIn.value.trim()) fd.append('title', titleIn.value.trim());
        fd.append('category', catSel.value);
        if (expIn.value) fd.append('expires_at', expIn.value);
        await Api.postForm('/director/children/' + child.id + '/documents', fd);
        titleIn.value = ''; expIn.value = '';
        upMsg.style.color = '#16A34A'; upMsg.textContent = '\u2713 Attached';
        load();
      } catch (e) {
        upMsg.style.color = '#B91C1C'; upMsg.textContent = e.message || 'Upload failed';
      } finally {
        upBtn.disabled = false; fileIn.value = '';
      }
    });
    up.appendChild(titleIn); up.appendChild(catSel); up.appendChild(expIn);
    up.appendChild(upBtn); up.appendChild(fileIn); up.appendChild(upMsg);
    card.appendChild(up);

    load();
  }

  // Register for both director and agency_admin roles via Shell.
  if (Shell && Shell.registerScreen) {
    Shell.registerScreen('centre_director:child-detail', renderChildDetail);
    Shell.registerScreen('agency_admin:child-detail', renderChildDetail);
  }

  /**
   * Open a child's record in a popup, live or archived.
   *
   * Same renderer as the full screen - one record, one implementation. Archived records
   * work because the only thing that made them different was the `archived=1` flag in the
   * hash, and that is now just a parameter.
   */
  function openChildModal(childId, opts) {
    opts = opts || {};
    if (!Shell || !Shell.Modal || !Shell.Modal.open) {
      /* No modal host (an old cached shell): fall back to the screen rather than doing
         nothing at all. */
      window.location.hash = '#child-detail?id=' + childId + (opts.archived ? '&archived=1' : '');
      return;
    }

    var host = Dom.el('div', {});
    Shell.Modal.open({
      title: opts.title || 'Child record',
      body: host,
      large: true,
    });

    renderChildDetail(host, {
      params: {
        id: String(childId),
        archived: opts.archived ? '1' : '',
        centre_id: opts.centreId || '',
      },
      onDone: function () {
        try { Shell.Modal.close(); } catch (e) {}
        if (typeof opts.onChanged === 'function') { opts.onChanged(); }
      },
    });
  }

  // Expose for tests / debug.
  KT.ChildDetail = { render: renderChildDetail, openModal: openChildModal };
})(window);
