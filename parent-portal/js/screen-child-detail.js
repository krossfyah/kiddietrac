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

  function fmtDate(d) {
    if (!d) return '—';
    try {
      return new Date(d).toLocaleDateString('en-CA',
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

  function renderChildDetail(container) {
    Dom.clear(container);
    var params = parseQuery(window.location.hash);
    var childId = params.id;
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

    Api.get('/director/children/' + childId).then(function (data) {
      Dom.clear(wrap);
      var child = data;

      // Back link
      var backWrap = Dom.el('div', { style: 'margin-bottom:16px;' });
      var backLink = Dom.el('a', {
        href: backHash(params),
        style: 'color:#1F6080;text-decoration:none;font-size:13px;',
      }, '← Back');
      backWrap.appendChild(backLink);
      wrap.appendChild(backWrap);

      // Header
      var header = Dom.el('div', {
        style: 'display:flex;align-items:flex-start;justify-content:space-between;margin-bottom:20px;flex-wrap:wrap;gap:12px;',
      });
      var headerLeft = Dom.el('div');
      headerLeft.appendChild(Dom.el('h1', {
        style: 'font-size:26px;margin:0 0 4px;color:#111827;',
      }, child.full_name + (child.preferred_name ? '  (' + child.preferred_name + ')' : '')));
      headerLeft.appendChild(Dom.el('div', {
        style: 'color:#6B7280;font-size:14px;',
      }, (child.age && child.age.human ? child.age.human : '—') + '  ·  born ' + fmtDate(child.date_of_birth)));
      header.appendChild(headerLeft);

      var headerActions = Dom.el('div', {
        style: 'display:flex;gap:8px;',
      });
      headerActions.appendChild(btn('✏️ Edit', btnPrimary(), function () {
        showEditModal(child, function () { renderChildDetail(container); });
      }));
      headerActions.appendChild(btn('🗄️ Archive', btnDanger(), function () {
        showArchiveConfirm(child, function () {
          window.location.hash = backHash(params);
        });
      }));
      header.appendChild(headerActions);
      wrap.appendChild(header);

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

      // Guardians
      if (child.guardians && child.guardians.length) {
        var gCard = Dom.el('div', {
          style: 'background:white;border-radius:14px;padding:20px 24px;box-shadow:0 1px 3px rgba(0,0,0,.06);margin-bottom:16px;',
        });
        gCard.appendChild(Dom.el('h3', {
          style: 'margin:0 0 12px;font-size:16px;',
        }, 'Guardians'));
        child.guardians.forEach(function (g) {
          var line = (g.first_name || '') + ' ' + (g.last_name || '') +
            (g.relationship ? ' · ' + g.relationship : '') +
            (g.is_primary ? ' (primary)' : '') +
            (g.email ? '  ·  ' + g.email : '') +
            (!g.can_pickup ? '  ·  cannot pick up' : '');
          gCard.appendChild(Dom.el('div', {
            style: 'padding:6px 0;font-size:14px;border-bottom:1px solid #F3F4F6;',
          }, line));
        });
        wrap.appendChild(gCard);
      }

      // Health flags
      if (child.health_flags && child.health_flags.length) {
        var hCard = Dom.el('div', {
          style: 'background:#FEF3C7;border-radius:14px;padding:20px 24px;margin-bottom:16px;',
        });
        hCard.appendChild(Dom.el('h3', {
          style: 'margin:0 0 12px;font-size:16px;color:#92400E;',
        }, '⚠️ Active health flags'));
        child.health_flags.forEach(function (h) {
          hCard.appendChild(Dom.el('div', {
            style: 'padding:4px 0;font-size:14px;color:#92400E;',
          }, (h.flag_type || 'flag').toUpperCase() + ': ' + (h.detail || h.name || '')));
        });
        wrap.appendChild(hCard);
      }
    }).catch(function (e) {
      Dom.clear(wrap);
      wrap.appendChild(Dom.el('div', {
        style: 'padding:24px;color:#DC2626;',
      }, 'Could not load child: ' + (e.message || 'error')));
    });
  }

  // Register for both director and agency_admin roles via Shell.
  if (Shell && Shell.registerScreen) {
    Shell.registerScreen('centre_director:child-detail', renderChildDetail);
    Shell.registerScreen('agency_admin:child-detail', renderChildDetail);
  }

  // Expose for tests / debug.
  KT.ChildDetail = { render: renderChildDetail };
})(window);
