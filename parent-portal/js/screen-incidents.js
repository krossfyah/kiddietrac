/* ============================================================
   KIDDIETRAC v20 - Incident Reports (parent + educator + director)
   3 screens in 1 file, registered for all 4 roles:
     incidents              list  (role-filtered server-side)
     incident-detail        show + actions (role-gated UI)
     incident-new           educator/director: create form
   ============================================================ */
(function (window) {
  'use strict';
  const { Api, Dom, Shell, Fmt, Auth } = window.KT;
  const { emptyState } = Shell;

  // API path varies by role. /provider, /director, /parent.
  function apiBase(role) {
    if (role === 'guardian') return '/parent';
    if (role === 'centre_director' || role === 'agency_admin') return '/director';
    return '/provider';
  }

  function esc(s) {
    return s == null ? '' : String(s)
      .replace(/&/g, '&amp;').replace(/</g, '&lt;')
      .replace(/>/g, '&gt;').replace(/"/g, '&quot;');
  }

  function fmt(d) {
    if (!d) return '-';
    try {
      return new Date(d).toLocaleString('en-CA', {
        year: 'numeric', month: 'short', day: 'numeric',
        hour: 'numeric', minute: '2-digit',
      });
    } catch (e) { return d; }
  }

  function statusBadge(status) {
    const map = {
      draft:              { c: 'tag-info',    t: 'DRAFT' },
      submitted:          { c: 'tag-warn',    t: 'AWAITING REVIEW' },
      director_reviewed:  { c: 'tag-info',    t: 'REVIEWED' },
      parent_notified:    { c: 'tag-warn',    t: 'AWAITING PARENT' },
      acknowledged:       { c: 'tag-success', t: 'ACKNOWLEDGED' },
      closed:             { c: 'tag-success', t: 'CLOSED' },
    };
    const m = map[status] || { c: 'tag-info', t: String(status || '').toUpperCase() };
    return '<span class="tag ' + m.c + '">' + m.t + '</span>';
  }

  function severityBadge(s) {
    const map = { low: 'tag-success', medium: 'tag-warn', high: 'tag-danger' };
    return '<span class="tag ' + (map[s] || 'tag-info') + '">' + (s || 'low').toUpperCase() + '</span>';
  }

  function typeLabel(t) {
    return {
      general: 'General',
      injury: 'Injury',
      illness: 'Illness',
      serious_occurrence: 'Serious Occurrence',
      behavioural: 'Behavioural',
      medication_error: 'Medication Error',
      other: 'Other',
    }[t] || t;
  }

  function isDirector(role) { return role === 'agency_admin' || role === 'centre_director'; }
  function isEducator(role) { return role === 'educator'; }
  function isGuardian(role) { return role === 'guardian'; }

  /* ===== LIST ===== */
  async function renderIncidentsList(main, ctx) {
    Dom.clear(main);
    const role = ctx && ctx.role;
    const params = (ctx && ctx.params) || {};
    const base = apiBase(role);
    const filterStatus = params.status || '';

    const wrap = document.createElement('div');
    main.appendChild(wrap);

    wrap.insertAdjacentHTML('beforeend',
      '<div class="page-header-v17">' +
        '<div>' +
          '<div class="crumbs"><span>Home</span><span class="sep">&gt;</span><span style="color:var(--kt-text-muted);">Incidents</span></div>' +
          '<h1>Incident Reports</h1>' +
          '<div class="sub">' + (isGuardian(role) ? 'Reports involving your child' : 'All reports') + '</div>' +
        '</div>' +
        '<div class="actions">' +
          ((isEducator(role) || isDirector(role)) ? '<button class="btn btn-primary" id="kt-new-incident">+ New report</button>' : '') +
        '</div>' +
      '</div>' +
      (!isGuardian(role) ?
        '<div class="filter-bar" style="margin-bottom:16px;">' +
          '<select id="kt-filter-status" class="filter-select" style="padding:8px 12px; border:1.5px solid var(--kt-border); border-radius:8px;">' +
            '<option value="">All statuses</option>' +
            '<option value="draft"'              + (filterStatus==='draft'?' selected':'')              + '>Draft</option>' +
            '<option value="submitted"'          + (filterStatus==='submitted'?' selected':'')          + '>Awaiting review</option>' +
            '<option value="director_reviewed"'  + (filterStatus==='director_reviewed'?' selected':'')  + '>Reviewed</option>' +
            '<option value="parent_notified"'    + (filterStatus==='parent_notified'?' selected':'')    + '>Awaiting parent</option>' +
            '<option value="acknowledged"'       + (filterStatus==='acknowledged'?' selected':'')       + '>Acknowledged</option>' +
            '<option value="closed"'             + (filterStatus==='closed'?' selected':'')             + '>Closed</option>' +
          '</select>' +
        '</div>' : '') +
      '<div id="kt-incidents-list"><div class="loading-state"><div class="spinner"></div><p style="margin-top:12px;color:var(--kt-text-muted);">Loading...</p></div></div>'
    );

    const newBtn = wrap.querySelector('#kt-new-incident');
    if (newBtn) newBtn.addEventListener('click', () => { window.location.hash = '#incident-new'; });

    const filterSel = wrap.querySelector('#kt-filter-status');
    if (filterSel) filterSel.addEventListener('change', (e) => {
      const v = e.target.value;
      window.location.hash = v ? '#incidents?status=' + encodeURIComponent(v) : '#incidents';
    });

    let data;
    try {
      const qs = filterStatus ? '?status=' + encodeURIComponent(filterStatus) : '';
      data = await Api.get(base + '/incidents' + qs);
    } catch (e) {
      const listEl = wrap.querySelector('#kt-incidents-list');
      Dom.clear(listEl);
      listEl.appendChild(emptyState('!', 'Could not load', (e && e.message) || 'Server error'));
      return;
    }

    const rows = (data && (data.data || data)) || [];
    const listEl = wrap.querySelector('#kt-incidents-list');
    Dom.clear(listEl);

    if (!Array.isArray(rows) || rows.length === 0) {
      listEl.appendChild(emptyState('-', 'No incident reports',
        (isEducator(role) || isDirector(role)) ? 'Click + New report to record one.' : 'Reports will appear here.'));
      return;
    }

    const table = document.createElement('div');
    table.style.cssText = 'background:var(--kt-surface); border:1px solid var(--kt-border); border-radius:14px; overflow:hidden;';
    table.innerHTML =
      '<table class="data-table" style="margin:0;">' +
        '<thead><tr>' +
          '<th>When</th><th>Child</th><th>Type</th><th>Severity</th><th>Status</th><th>Recorded by</th><th></th>' +
        '</tr></thead>' +
        '<tbody></tbody>' +
      '</table>';
    const tbody = table.querySelector('tbody');

    rows.forEach(function (inc) {
      const childName = inc.child ? ((inc.child.first_name || '') + ' ' + (inc.child.last_name || '')).trim() : '-';
      const recorded  = (inc.recorded_by && inc.recorded_by.name) || '-';
      const tr = document.createElement('tr');
      tr.style.cursor = 'pointer';
      tr.innerHTML =
        '<td>' + fmt(inc.occurred_at) + '</td>' +
        '<td><strong>' + esc(childName) + '</strong></td>' +
        '<td>' + esc(typeLabel(inc.incident_type)) + (inc.is_serious_occurrence ? ' <span class="tag tag-danger" style="font-size:9px;">SO</span>' : '') + '</td>' +
        '<td>' + severityBadge(inc.severity) + '</td>' +
        '<td>' + statusBadge(inc.status) + '</td>' +
        '<td style="font-size:13px; color:var(--kt-text-muted);">' + esc(recorded) + '</td>' +
        '<td style="text-align:right; color:var(--kt-text-faint);">&rarr;</td>';
      tr.addEventListener('click', function () {
        window.location.hash = '#incident-detail?id=' + inc.id;
      });
      tbody.appendChild(tr);
    });
    listEl.appendChild(table);
  }

  /* ===== DETAIL ===== */
  async function renderIncidentDetail(main, ctx) {
    Dom.clear(main);
    const role = ctx && ctx.role;
    const id = ctx && ctx.params && ctx.params.id;
    const base = apiBase(role);

    if (!id) {
      main.appendChild(emptyState('!', 'Missing incident ID', 'Add ?id=N to the URL.'));
      return;
    }

    let inc;
    try {
      const res = await Api.get(base + '/incidents/' + id);
      inc = (res && res.data) || res;
    } catch (e) {
      main.appendChild(emptyState('!', 'Could not load', (e && e.message) || 'Server error'));
      return;
    }
    if (!inc) {
      main.appendChild(emptyState('!', 'Not found', 'This incident may have been deleted.'));
      return;
    }

    const childName = inc.child ? ((inc.child.first_name || '') + ' ' + (inc.child.last_name || '')).trim() : '-';

    const wrap = document.createElement('div');
    main.appendChild(wrap);

    let acksHtml = '';
    if (Array.isArray(inc.acknowledgments) && inc.acknowledgments.length > 0) {
      acksHtml =
        '<div style="background:var(--kt-surface); border:1px solid var(--kt-border); border-radius:14px; padding:20px; margin-bottom:16px;">' +
          '<h2 style="font-family:var(--kt-font-display); font-size:16px; margin-bottom:12px;">Acknowledgments</h2>' +
          inc.acknowledgments.map(function (a) {
            return '<div style="padding:10px 0; border-bottom:1px solid var(--kt-border); font-size:13px;">' +
              '<strong>' + esc(a.signed_name) + '</strong> &middot; ' + fmt(a.signed_at) + '<br>' +
              (a.comment ? '<em style="color:var(--kt-text-muted);">' + esc(a.comment) + '</em><br>' : '') +
              '<span style="color:var(--kt-text-faint); font-size:11px;">IP ' + esc(a.ip_address || '-') + '</span>' +
            '</div>';
          }).join('') +
        '</div>';
    }

    let witnessesHtml = '';
    if (Array.isArray(inc.witnesses) && inc.witnesses.length > 0) {
      witnessesHtml =
        '<h2 style="font-family:var(--kt-font-display); font-size:18px; margin:24px 0 12px;">Witnesses</h2>' +
        '<ul style="padding-left:20px; line-height:1.6;">' +
          inc.witnesses.map(function (w) {
            return '<li>' + esc(w.name || '') + (w.role ? ' (' + esc(w.role) + ')' : '') + '</li>';
          }).join('') +
        '</ul>';
    }

    wrap.insertAdjacentHTML('beforeend',
      '<div class="page-header-v17">' +
        '<div>' +
          '<div class="crumbs">' +
            '<a href="#incidents" style="color:var(--kt-text-muted);">Incidents</a>' +
            '<span class="sep">&gt;</span>' +
            '<span style="color:var(--kt-text-muted);">#' + inc.id + '</span>' +
          '</div>' +
          '<h1>' + esc(typeLabel(inc.incident_type)) + ' - ' + esc(childName) + '</h1>' +
          '<div class="sub">' + fmt(inc.occurred_at) + (inc.location ? ' &middot; ' + esc(inc.location) : '') + '</div>' +
        '</div>' +
        '<div class="actions">' +
          severityBadge(inc.severity) + ' ' +
          statusBadge(inc.status) + ' ' +
          (inc.is_serious_occurrence ? '<span class="tag tag-danger">SERIOUS OCCURRENCE</span>' : '') +
        '</div>' +
      '</div>' +

      '<div style="background:var(--kt-surface); border:1px solid var(--kt-border); border-radius:14px; padding:24px; margin-bottom:16px;">' +
        '<h2 style="font-family:var(--kt-font-display); font-size:18px; margin-bottom:12px;">What happened</h2>' +
        '<p style="white-space:pre-wrap; line-height:1.6;">' + esc(inc.description) + '</p>' +
        (inc.action_taken ?
          '<h2 style="font-family:var(--kt-font-display); font-size:18px; margin:24px 0 12px;">Action taken</h2>' +
          '<p style="white-space:pre-wrap; line-height:1.6;">' + esc(inc.action_taken) + '</p>' : '') +
        (inc.follow_up_required ?
          '<h2 style="font-family:var(--kt-font-display); font-size:18px; margin:24px 0 12px;">Follow-up required</h2>' +
          '<p style="white-space:pre-wrap; line-height:1.6;">' + esc(inc.follow_up_required) + '</p>' : '') +
        witnessesHtml +
        (inc.director_notes ?
          '<h2 style="font-family:var(--kt-font-display); font-size:18px; margin:24px 0 12px;">Director notes</h2>' +
          '<p style="white-space:pre-wrap; line-height:1.6; padding:12px; background:var(--kt-bg); border-radius:8px;">' + esc(inc.director_notes) + '</p>' : '') +
      '</div>' +

      '<div style="background:var(--kt-surface); border:1px solid var(--kt-border); border-radius:14px; padding:20px; margin-bottom:16px;">' +
        '<h2 style="font-family:var(--kt-font-display); font-size:16px; margin-bottom:12px;">Timeline</h2>' +
        '<div style="font-size:13px; line-height:1.8; color:var(--kt-text-muted);">' +
          '<div>Recorded by ' + esc((inc.recorded_by && inc.recorded_by.name) || '-') + '</div>' +
          (inc.submitted_at        ? '<div>Submitted: ' + fmt(inc.submitted_at) + '</div>' : '') +
          (inc.reviewed_at         ? '<div>Reviewed by ' + esc((inc.reviewed_by && inc.reviewed_by.name) || '-') + ': ' + fmt(inc.reviewed_at) + '</div>' : '') +
          (inc.parent_notified_at  ? '<div>Parent notified: ' + fmt(inc.parent_notified_at) + '</div>' : '') +
          (inc.acknowledged_at     ? '<div>Acknowledged: ' + fmt(inc.acknowledged_at) + '</div>' : '') +
          (inc.closed_at           ? '<div>Closed: ' + fmt(inc.closed_at) + '</div>' : '') +
        '</div>' +
      '</div>' +

      acksHtml +
      '<div id="kt-actions"></div>'
    );

    renderActions(wrap.querySelector('#kt-actions'), inc, role, base);
  }

  function renderActions(el, inc, role, base) {
    Dom.clear(el);

    if (isDirector(role)) {
      if (inc.status === 'submitted') {
        addBtn(el, 'Mark reviewed', 'btn-primary', async () => {
          const notes = prompt('Optional notes for the parent (leave blank to skip):');
          await Api.post(base + '/incidents/' + inc.id + '/review', { director_notes: notes || null });
          window.location.reload();
        });
      }
      if (inc.status === 'director_reviewed' || inc.status === 'submitted') {
        addBtn(el, 'Notify parent', 'btn-success', async () => {
          if (!confirm('Send notification to the child\'s parents now?')) return;
          await Api.post(base + '/incidents/' + inc.id + '/notify-parent');
          window.location.reload();
        });
      }
      if (inc.status !== 'closed') {
        addBtn(el, 'Close', 'btn-secondary', async () => {
          if (!confirm('Close this incident?')) return;
          await Api.post(base + '/incidents/' + inc.id + '/close');
          window.location.reload();
        });
      }
    }

    if (isEducator(role) && inc.status === 'draft') {
      addBtn(el, 'Submit for review', 'btn-primary', async () => {
        if (!confirm('Submit this report to your director?')) return;
        await Api.post(base + '/incidents/' + inc.id + '/submit');
        window.location.reload();
      });
    }

    if (isGuardian(role) && inc.status === 'parent_notified') {
      const card = document.createElement('div');
      card.style.cssText = 'background:var(--kt-surface); border:2px solid var(--kt-blue); border-radius:14px; padding:24px;';
      card.innerHTML =
        '<h2 style="font-family:var(--kt-font-display); font-size:18px; margin-bottom:8px;">Acknowledgment</h2>' +
        '<p style="margin-bottom:16px; color:var(--kt-text-muted); line-height:1.6;">' +
          'Please confirm you have read and understood this incident report. ' +
          'Typing your name below acts as your electronic signature.' +
        '</p>' +
        '<div class="form-row">' +
          '<label>Type your full name to acknowledge</label>' +
          '<input type="text" id="kt-ack-name" placeholder="e.g. Jane Doe" autocomplete="off" style="padding:10px; border:1.5px solid var(--kt-border); border-radius:8px; width:100%;">' +
        '</div>' +
        '<div class="form-row" style="margin-top:12px;">' +
          '<label>Optional comment for the centre</label>' +
          '<textarea id="kt-ack-comment" rows="3" placeholder="(optional)" style="font-family:inherit; padding:10px; border:1.5px solid var(--kt-border); border-radius:8px; width:100%;"></textarea>' +
        '</div>' +
        '<div style="margin-top:16px;">' +
          '<button class="btn btn-primary btn-block" id="kt-ack-submit">Acknowledge</button>' +
        '</div>' +
        '<p style="font-size:11px; color:var(--kt-text-faint); margin-top:12px;">' +
          'For our records, your IP address, browser, and timestamp will be saved with this acknowledgment.' +
        '</p>';
      el.appendChild(card);

      card.querySelector('#kt-ack-submit').addEventListener('click', async function () {
        const name = card.querySelector('#kt-ack-name').value.trim();
        const comment = card.querySelector('#kt-ack-comment').value.trim();
        if (name.length < 2) {
          Dom.toast('Please type your full name', 'error');
          return;
        }
        const btn = card.querySelector('#kt-ack-submit');
        btn.disabled = true; btn.textContent = 'Saving...';
        try {
          await Api.post(base + '/incidents/' + inc.id + '/acknowledge', {
            signed_name: name, comment: comment || null,
          });
          Dom.toast('Acknowledged. Thank you.', 'success');
          setTimeout(() => window.location.reload(), 800);
        } catch (e) {
          Dom.toast((e && e.message) || 'Could not save', 'error');
          btn.disabled = false; btn.textContent = 'Acknowledge';
        }
      });
    }
  }

  function addBtn(el, label, cls, onClick) {
    const b = document.createElement('button');
    b.className = 'btn ' + cls;
    b.style.marginRight = '8px';
    b.textContent = label;
    b.addEventListener('click', async function () {
      b.disabled = true;
      try { await onClick(); }
      catch (e) {
        Dom.toast((e && e.message) || 'Action failed', 'error');
        b.disabled = false;
      }
    });
    el.appendChild(b);
  }

  /* ===== NEW (educator) ===== */
  async function renderIncidentNew(main, ctx) {
    Dom.clear(main);
    const role = ctx && ctx.role;
    const base = apiBase(role);

    // Try to load roster for child dropdown
    let children = [];
    try {
      const r = await Api.get('/provider/bootstrap');
      if (r && r.rooms) {
        // bootstrap returns rooms with children -- flatten
        r.rooms.forEach(function (room) {
          if (Array.isArray(room.children)) {
            room.children.forEach(function (c) { children.push(c); });
          }
        });
      }
    } catch (e) {
      // Continue with empty children list
    }
    if (children.length === 0) {
      try {
        const r = await Api.get('/parent/children');
        children = r.children || r.data || r || [];
      } catch (e2) {}
    }

    const wrap = document.createElement('div');
    main.appendChild(wrap);

    wrap.insertAdjacentHTML('beforeend',
      '<div class="page-header-v17">' +
        '<div>' +
          '<div class="crumbs">' +
            '<a href="#incidents" style="color:var(--kt-text-muted);">Incidents</a>' +
            '<span class="sep">&gt;</span>' +
            '<span style="color:var(--kt-text-muted);">New report</span>' +
          '</div>' +
          '<h1>New incident report</h1>' +
          '<div class="sub">Record what happened. You can save as draft and submit later.</div>' +
        '</div>' +
      '</div>' +

      '<form id="kt-new-incident-form" style="background:var(--kt-surface); border:1px solid var(--kt-border); border-radius:14px; padding:24px; max-width:760px;">' +
        '<div class="form-grid" style="display:grid; grid-template-columns:1fr 1fr; gap:14px;">' +
          '<div class="form-row">' +
            '<label>Child *</label>' +
            '<select name="child_id" required style="padding:10px; border:1.5px solid var(--kt-border); border-radius:8px; width:100%;">' +
              '<option value="">- select -</option>' +
              children.map(function (c) {
                return '<option value="' + c.id + '">' + esc(((c.first_name || '') + ' ' + (c.last_name || '')).trim()) + '</option>';
              }).join('') +
            '</select>' +
          '</div>' +
          '<div class="form-row">' +
            '<label>When did it happen? *</label>' +
            '<input type="datetime-local" name="occurred_at" required style="padding:10px; border:1.5px solid var(--kt-border); border-radius:8px; width:100%;">' +
          '</div>' +
          '<div class="form-row">' +
            '<label>Type *</label>' +
            '<select name="incident_type" required style="padding:10px; border:1.5px solid var(--kt-border); border-radius:8px; width:100%;">' +
              '<option value="general">General</option>' +
              '<option value="injury">Injury</option>' +
              '<option value="illness">Illness</option>' +
              '<option value="behavioural">Behavioural</option>' +
              '<option value="medication_error">Medication error</option>' +
              '<option value="serious_occurrence">Serious occurrence (CCEYA)</option>' +
              '<option value="other">Other</option>' +
            '</select>' +
          '</div>' +
          '<div class="form-row">' +
            '<label>Severity</label>' +
            '<select name="severity" style="padding:10px; border:1.5px solid var(--kt-border); border-radius:8px; width:100%;">' +
              '<option value="low">Low</option>' +
              '<option value="medium">Medium</option>' +
              '<option value="high">High</option>' +
            '</select>' +
          '</div>' +
          '<div class="form-row" style="grid-column:1/-1;">' +
            '<label>Location</label>' +
            '<input type="text" name="location" placeholder="e.g. Acorn Room, outdoor playground" style="padding:10px; border:1.5px solid var(--kt-border); border-radius:8px; width:100%;">' +
          '</div>' +
          '<div class="form-row" style="grid-column:1/-1;">' +
            '<label>What happened? *</label>' +
            '<textarea name="description" required rows="5" placeholder="Describe the incident factually." style="padding:10px; border:1.5px solid var(--kt-border); border-radius:8px; font-family:inherit; width:100%;"></textarea>' +
          '</div>' +
          '<div class="form-row" style="grid-column:1/-1;">' +
            '<label>Action taken</label>' +
            '<textarea name="action_taken" rows="3" style="padding:10px; border:1.5px solid var(--kt-border); border-radius:8px; font-family:inherit; width:100%;"></textarea>' +
          '</div>' +
          '<div class="form-row" style="grid-column:1/-1;">' +
            '<label>Follow-up required</label>' +
            '<textarea name="follow_up_required" rows="2" style="padding:10px; border:1.5px solid var(--kt-border); border-radius:8px; font-family:inherit; width:100%;"></textarea>' +
          '</div>' +
        '</div>' +
        '<div style="display:flex; gap:10px; margin-top:24px;">' +
          '<button type="submit" class="btn btn-primary" data-submit="true">Save and submit</button>' +
          '<button type="button" class="btn btn-secondary" data-submit="false">Save as draft</button>' +
          '<button type="button" class="btn btn-ghost" id="kt-cancel">Cancel</button>' +
        '</div>' +
      '</form>'
    );

    wrap.querySelector('#kt-cancel').addEventListener('click', function () {
      window.location.hash = '#incidents';
    });

    const form = wrap.querySelector('#kt-new-incident-form');
    let pendingSubmit = false;

    wrap.querySelectorAll('[data-submit]').forEach(function (btn) {
      btn.addEventListener('click', function (e) {
        e.preventDefault();
        pendingSubmit = btn.getAttribute('data-submit') === 'true';
        form.requestSubmit();
      });
    });

    form.addEventListener('submit', async function (e) {
      e.preventDefault();
      const fd = new FormData(form);
      const data = {};
      fd.forEach(function (v, k) { if (v !== '') data[k] = v; });

      try {
        const res = await Api.post(base + '/incidents', data);
        const inc = (res && res.data) || res;
        if (pendingSubmit) {
          await Api.post(base + '/incidents/' + inc.id + '/submit');
          Dom.toast('Submitted for review', 'success');
        } else {
          Dom.toast('Saved as draft', 'success');
        }
        setTimeout(function () { window.location.hash = '#incident-detail?id=' + inc.id; }, 600);
      } catch (e2) {
        Dom.toast((e2 && e2.message) || 'Could not save', 'error');
      }
    });
  }

  /* ===== Register ===== */
  window.KT = window.KT || {};
  window.KT.renderIncidentsList   = renderIncidentsList;
  window.KT.renderIncidentDetail  = renderIncidentDetail;
  window.KT.renderIncidentNew     = renderIncidentNew;

  if (Shell && Shell.registerScreen) {
    ['agency_admin', 'centre_director', 'educator', 'guardian'].forEach(function (r) {
      Shell.registerScreen(r + ':incidents',       renderIncidentsList);
      Shell.registerScreen(r + ':incident-detail', renderIncidentDetail);
    });
    ['educator', 'centre_director', 'agency_admin'].forEach(function (r) {
      Shell.registerScreen(r + ':incident-new',    renderIncidentNew);
    });
  }
})(window);
