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

  // Email the incident report to parent / director / agency admin (+ optional email).
  function openEmailReportDialog(inc, base) {
    var m = document.createElement('div');
    m.style.cssText = 'position:fixed;inset:0;background:rgba(15,23,42,0.55);z-index:100000;display:flex;align-items:center;justify-content:center;padding:20px;';
    m.innerHTML = '<div style="background:#fff;padding:24px;border-radius:14px;max-width:420px;width:100%;box-shadow:0 20px 50px rgba(0,0,0,.3);">'
      + '<h3 style="margin:0 0 4px;font-size:18px;color:#0F172A;">📧 Email incident report</h3>'
      + '<div style="font-size:13px;color:#64748B;margin-bottom:16px;">Send this report to:</div>'
      + '<label style="display:flex;align-items:center;gap:9px;font-size:14px;margin-bottom:10px;cursor:pointer;"><input type="checkbox" value="parent" checked style="width:16px;height:16px;"> Parent / guardian</label>'
      + '<label style="display:flex;align-items:center;gap:9px;font-size:14px;margin-bottom:10px;cursor:pointer;"><input type="checkbox" value="director" style="width:16px;height:16px;"> Centre director</label>'
      + '<label style="display:flex;align-items:center;gap:9px;font-size:14px;margin-bottom:14px;cursor:pointer;"><input type="checkbox" value="admin" style="width:16px;height:16px;"> Agency admin</label>'
      + '<label style="display:block;font-size:12.5px;font-weight:600;color:#475569;margin-bottom:4px;">Also send to (optional email)</label>'
      + '<input id="ir-extra" type="email" placeholder="name@example.com" style="width:100%;padding:9px;border:1px solid #E2E8F0;border-radius:8px;box-sizing:border-box;">'
      + '<div id="ir-msg" style="min-height:18px;font-size:12.5px;margin-top:10px;"></div>'
      + '<div style="display:flex;justify-content:flex-end;gap:8px;margin-top:8px;">'
      + '<button id="ir-cancel" style="padding:9px 16px;border:1px solid #D1D5DB;background:#fff;border-radius:8px;font-weight:600;cursor:pointer;">Cancel</button>'
      + '<button id="ir-send" style="padding:9px 16px;border:none;background:#1F6080;color:#fff;border-radius:8px;font-weight:700;cursor:pointer;">Send</button>'
      + '</div></div>';
    document.body.appendChild(m);
    m.addEventListener('click', function (e) { if (e.target === m) m.remove(); });
    m.querySelector('#ir-cancel').onclick = function () { m.remove(); };
    m.querySelector('#ir-send').onclick = async function () {
      var to = Array.prototype.slice.call(m.querySelectorAll('input[type=checkbox]:checked')).map(function (c) { return c.value; });
      var extra = m.querySelector('#ir-extra').value.trim();
      var msg = m.querySelector('#ir-msg'); var btn = m.querySelector('#ir-send');
      if (!to.length && !extra) { msg.style.color = '#DC2626'; msg.textContent = 'Pick at least one recipient.'; return; }
      btn.disabled = true; msg.style.color = '#64748B'; msg.textContent = 'Sending…';
      try {
        var r = await Api.post(base + '/incidents/' + inc.id + '/email', { to: to, extra_email: extra || null });
        msg.style.color = '#16A34A'; msg.textContent = '✓ Sent to ' + r.recipients + ' recipient' + (r.recipients === 1 ? '' : 's') + '.';
        setTimeout(function () { m.remove(); }, 1200);
      } catch (e) {
        btn.disabled = false; msg.style.color = '#DC2626'; msg.textContent = (e && e.message) || 'Could not send.';
      }
    };
  }

  /* ===== LIST ===== */
  // The APK WebView can report a desktop-ish width, so gate mobile UI on the
  // kt-native class too (added on the native app) — not width alone.
  function isMobile() { return window.innerWidth <= 700 || document.documentElement.classList.contains('kt-native'); }
  function incAbsUrl(u) { if (!u) return u; if (/^https?:\/\//.test(u)) return u; var b = (window.KT && KT.API_BASE) || 'https://api.kiddietrac.com/api/v1'; return b.replace(/\/api\/v1\/?$/, '') + (u.charAt(0) === '/' ? u : '/' + u); }
  var INC_SEV_COLOR = { low: '#16A34A', medium: '#F59E0B', high: '#DC2626' };
  // Phone/APK: a stacked card is far easier to read than a 7-column table.
  function buildIncidentCard(inc) {
    var childName = inc.child ? ((inc.child.first_name || '') + ' ' + (inc.child.last_name || '')).trim() : '';
    if (!childName) childName = 'Incident';
    var recorded = (inc.recorded_by && inc.recorded_by.name) || '-';
    var sev = INC_SEV_COLOR[inc.severity] || '#64748B';
    var photo = (inc.child && inc.child.photo_url) ? incAbsUrl(inc.child.photo_url) : null;
    var avatarHtml = (window.KT && KT.avatar)
      ? KT.avatar(childName, { size: 42, photoUrl: photo })
      : '<span style="display:flex;width:42px;height:42px;border-radius:50%;background:' + sev + ';color:#fff;align-items:center;justify-content:center;font-weight:800;font-size:15px;">' + esc((childName[0] || '?').toUpperCase()) + '</span>';
    var card = document.createElement('button');
    card.type = 'button';
    card.className = 'kt-inc-card';
    card.style.cssText = 'width:100%;text-align:left;display:block;background:#fff;border:1px solid #EDF1F6;border-left:5px solid ' + sev + ';border-radius:15px;padding:13px 14px;margin-bottom:11px;cursor:pointer;box-shadow:0 2px 8px -3px rgba(15,23,42,.12);font:inherit;';
    card.innerHTML =
      '<div style="display:flex;align-items:center;gap:11px;">' +
        '<span style="flex:0 0 auto;">' + avatarHtml + '</span>' +
        '<div style="flex:1;min-width:0;">' +
          '<div style="font-weight:800;font-size:15px;color:#0F172A;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">' + esc(childName) + '</div>' +
          '<div style="font-size:12.5px;color:#475569;margin-top:1px;">' + esc(typeLabel(inc.incident_type)) + (inc.is_serious_occurrence ? ' <span class="tag tag-danger" style="font-size:9px;">SO</span>' : '') + '</div>' +
        '</div>' +
        '<span style="color:#CBD5E1;font-size:24px;line-height:1;flex-shrink:0;">›</span>' +
      '</div>' +
      '<div style="display:flex;flex-wrap:wrap;align-items:center;gap:6px;margin-top:11px;padding-top:11px;border-top:1px solid #F1F5F9;">' +
        severityBadge(inc.severity) + statusBadge(inc.status) +
        '<span style="margin-left:auto;font-size:11.5px;color:#94A3B8;white-space:nowrap;">' + esc(fmt(inc.occurred_at)) + '</span>' +
      '</div>' +
      '<div style="font-size:11.5px;color:#94A3B8;margin-top:6px;">Recorded by ' + esc(recorded) + '</div>';
    card.addEventListener('click', function () { window.location.hash = '#incident-detail?id=' + inc.id; });
    return card;
  }

  async function renderIncidentsList(main, ctx) {
    Dom.clear(main);
    const role = ctx && ctx.role;
    const params = (ctx && ctx.params) || {};
    const base = apiBase(role);
    const filterStatus = params.status || '';

    const wrap = document.createElement('div');
    main.appendChild(wrap);

    wrap.insertAdjacentHTML('beforeend',
      // No page-header-v17 here — that class makes app-v2-shell skip its standard
      // "Operations / Incidents" auto-banner, leaving this screen with the old,
      // broken right-aligned header. Render a plain content header instead so the
      // consistent shell banner shows above.
      '<div style="display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap;padding:18px 2px 10px;">' +
        '<div>' +
          '<h2 style="font-size:20px;margin:0;color:#0F172A;">⚠️ Incident Reports</h2>' +
          '<div style="color:var(--kt-text-muted);font-size:13px;margin-top:2px;">' + (isGuardian(role) ? 'Reports involving your child' : 'All reports') + '</div>' +
        '</div>' +
        ((isEducator(role) || isDirector(role)) ? '<button class="btn btn-primary" id="kt-new-incident">+ New report</button>' : '') +
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
      listEl.appendChild(emptyState('⚠️', 'Could not load', (e && e.message) || 'Server error'));
      return;
    }

    const rows = (data && (data.data || data)) || [];
    const listEl = wrap.querySelector('#kt-incidents-list');
    Dom.clear(listEl);

    if (!Array.isArray(rows) || rows.length === 0) {
      listEl.appendChild(emptyState('📋', 'No incident reports',
        (isEducator(role) || isDirector(role)) ? 'Click + New report to record one.' : 'Reports will appear here.'));
      return;
    }

    if (isMobile()) {
      const cards = document.createElement('div');
      cards.setAttribute('data-kt-list', '1');
      rows.forEach(function (inc) { cards.appendChild(buildIncidentCard(inc)); });
      listEl.appendChild(cards);
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
        // Real action control (not a bare arrow) so kt-row-actions.js collapses it
        // into the standard ⋮ kebab, consistent with every other data table.
        '<td style="text-align:right;"><button type="button" class="kt-inc-view kt-act-icon kt-act-info kt-icon-tip" data-id="' + inc.id + '" data-kttip="View" aria-label="View">👁️</button></td>';
      tr.addEventListener('click', function () {
        window.location.hash = '#incident-detail?id=' + inc.id;
      });
      var _vb = tr.querySelector('.kt-inc-view');
      if (_vb) _vb.addEventListener('click', function (e) { e.stopPropagation(); window.location.hash = '#incident-detail?id=' + inc.id; });
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
      main.appendChild(emptyState('⚠️', 'Missing incident ID', 'Add ?id=N to the URL.'));
      return;
    }

    let inc;
    try {
      const res = await Api.get(base + '/incidents/' + id);
      inc = (res && res.data) || res;
    } catch (e) {
      main.appendChild(emptyState('⚠️', 'Could not load', (e && e.message) || 'Server error'));
      return;
    }
    if (!inc) {
      main.appendChild(emptyState('🔍', 'Not found', 'This incident may have been deleted.'));
      return;
    }

    const childName = inc.child ? ((inc.child.first_name || '') + ' ' + (inc.child.last_name || '')).trim() : '-';

    const wrap = document.createElement('div');
    wrap.className = 'kt-inc-detail';
    main.appendChild(wrap);
    if (!document.getElementById('kt-inc-detail-css')) {
      var _ds = document.createElement('style'); _ds.id = 'kt-inc-detail-css';
      _ds.textContent = '@media(max-width:700px){#appMain .kt-inc-detail .page-header-v17{flex-direction:column;align-items:flex-start;gap:10px;}#appMain .kt-inc-detail .page-header-v17 .actions{display:flex;flex-wrap:wrap;gap:6px;margin-top:2px;}#appMain .kt-inc-detail .page-header-v17 h1{font-size:19px;line-height:1.25;}#appMain .kt-inc-detail > div{padding:16px !important;}#appMain .kt-inc-detail h2{font-size:16px !important;}}';
      document.head.appendChild(_ds);
    }

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
      '<div id="kt-notes"></div>' +
      '<div id="kt-actions"></div>'
    );

    renderNotes(wrap.querySelector('#kt-notes'), inc, role, base);
    renderActions(wrap.querySelector('#kt-actions'), inc, role, base);
  }

  /* ===== NOTES (staff-internal audit trail) ===== */
  // Educators + directors/admins can append notes/details; each is stamped with
  // who wrote it and when. Guardians never see this section (the API also strips
  // notes from their payload).
  function renderNotes(el, inc, role, base) {
    Dom.clear(el);
    if (isGuardian(role)) return;

    const notes = Array.isArray(inc.notes) ? inc.notes : [];

    function noteAuthor(n) {
      if (n.author_name) return n.author_name;
      if (n.user) return ((n.user.first_name || '') + ' ' + (n.user.last_name || '')).trim() || 'Staff';
      return 'Staff';
    }
    function noteHtml(n) {
      return '<div class="kt-note-item" style="padding:11px 0;border-bottom:1px solid var(--kt-border);">' +
        '<div style="font-size:12.5px;color:var(--kt-text-muted);">' +
          '<strong style="color:var(--kt-text);">' + esc(noteAuthor(n)) + '</strong>' +
          '<span style="color:var(--kt-text-faint);"> &middot; ' + fmt(n.created_at) + '</span>' +
        '</div>' +
        '<div style="white-space:pre-wrap;line-height:1.55;margin-top:3px;font-size:14px;">' + esc(n.note) + '</div>' +
      '</div>';
    }

    const card = document.createElement('div');
    card.style.cssText = 'background:var(--kt-surface); border:1px solid var(--kt-border); border-radius:14px; padding:20px; margin-bottom:16px;';
    card.innerHTML =
      '<h2 style="font-family:var(--kt-font-display); font-size:16px; margin-bottom:4px;">Notes &amp; details</h2>' +
      '<p style="font-size:12px; color:var(--kt-text-faint); margin:0 0 12px;">Staff-only. Every note records who added it and when.</p>' +
      '<div id="kt-note-list">' +
        (notes.length ? notes.map(noteHtml).join('') : '<div id="kt-note-empty" style="font-size:13px;color:var(--kt-text-muted);padding:4px 0 8px;">No notes yet.</div>') +
      '</div>' +
      '<div style="margin-top:14px;">' +
        '<textarea id="kt-note-input" rows="3" placeholder="Add a note or extra detail…" style="width:100%; padding:10px; border:1.5px solid var(--kt-border); border-radius:8px; font-family:inherit; box-sizing:border-box; font-size:14px;"></textarea>' +
        // Left-aligned directly under the textarea — was flex-end, which parked it at
        // the far-right edge of a full-width card, easy to miss.
        '<div style="display:flex; justify-content:flex-start; margin-top:8px;">' +
          '<button class="btn btn-primary" id="kt-note-add">Add note</button>' +
        '</div>' +
      '</div>';
    el.appendChild(card);

    const input = card.querySelector('#kt-note-input');
    const addBtnEl = card.querySelector('#kt-note-add');
    const list = card.querySelector('#kt-note-list');

    addBtnEl.addEventListener('click', async function () {
      const text = (input.value || '').trim();
      if (text.length < 1) { input.focus(); return; }
      addBtnEl.disabled = true; addBtnEl.textContent = 'Saving…';
      try {
        const res = await Api.post(base + '/incidents/' + inc.id + '/notes', { note: text });
        const n = (res && res.data) || res;
        const empty = list.querySelector('#kt-note-empty');
        if (empty) empty.remove();
        list.insertAdjacentHTML('beforeend', noteHtml(n));
        input.value = '';
        Dom.toast('Note added', 'success');
      } catch (e) {
        Dom.toast((e && e.message) || 'Could not add note', 'error');
      } finally {
        addBtnEl.disabled = false; addBtnEl.textContent = 'Add note';
      }
    });
  }

  function renderActions(el, inc, role, base) {
    Dom.clear(el);

    // Always offer a way back to the list — previously a director on an already
    // reviewed/notified incident saw only "Close", with no way to leave without
    // acting on it. Built via addBtn so it's byte-for-byte the same .btn.btn-secondary
    // as Close. data-kt-iconized stops kt-icon-buttons.js from converting it into a
    // 38px ⬅️ icon (it matches "back") — which is what made it a different size.
    const back = document.createElement('button');
    back.className = 'btn btn-secondary';
    back.style.marginRight = '8px';
    back.dataset.ktIconized = '1';
    back.textContent = '← Back to incidents';
    back.addEventListener('click', function () { window.location.hash = '#incidents'; });
    el.appendChild(back);

    // Staff: email the report to parent / director / agency admin.
    if ((isDirector(role) || isEducator(role)) && inc.status !== 'draft') {
      addBtn(el, '📧 Email report', 'btn-secondary', function () { openEmailReportDialog(inc, base); });
    }

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
          if (!await KT.confirm('Send notification to the child\'s parents now?')) return;
          await Api.post(base + '/incidents/' + inc.id + '/notify-parent');
          window.location.reload();
        });
      }
      if (inc.status !== 'closed') {
        addBtn(el, 'Close', 'btn-secondary', async () => {
          if (!await KT.confirm('Close this incident?')) return;
          await Api.post(base + '/incidents/' + inc.id + '/close');
          window.location.reload();
        });
      }
    }

    if (isEducator(role) && inc.status === 'draft') {
      addBtn(el, 'Submit for review', 'btn-primary', async () => {
        if (!await KT.confirm('Submit this report to your director?')) return;
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
          '<label>Sign here</label>' +
          '<div style="border:1.5px solid var(--kt-border);border-radius:8px;background:#fff;position:relative;">' +
            '<canvas id="kt-ack-sig" style="width:100%;height:140px;display:block;touch-action:none;border-radius:8px;cursor:crosshair;"></canvas>' +
            '<button type="button" id="kt-ack-sig-clear" style="position:absolute;top:6px;right:6px;font-size:11px;padding:3px 8px;border:1px solid var(--kt-border);background:#fff;border-radius:6px;cursor:pointer;">Clear</button>' +
          '</div>' +
          '<div style="font-size:11px;color:var(--kt-text-faint);margin-top:4px;">Draw your signature with your mouse or finger. Dated <span id="kt-ack-date"></span>.</div>' +
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

      // Signature pad (draw with mouse/finger). The drawn PNG is sent alongside
      // the typed name so the acknowledgment has a real signature + date.
      (function initSigPad() {
        var cv = card.querySelector('#kt-ack-sig');
        if (!cv) return;
        var dpr = window.devicePixelRatio || 1, cx;
        function resize() {
          var r = cv.getBoundingClientRect();
          if (!r.width) return;
          cv.width = Math.round(r.width * dpr); cv.height = Math.round(140 * dpr);
          cx = cv.getContext('2d'); cx.scale(dpr, dpr); cx.lineWidth = 2; cx.lineCap = 'round'; cx.lineJoin = 'round'; cx.strokeStyle = '#0F172A';
        }
        setTimeout(resize, 0);
        var drawing = false, last = null;
        function pt(e) { var r = cv.getBoundingClientRect(); var t = (e.touches && e.touches[0]) ? e.touches[0] : e; return { x: t.clientX - r.left, y: t.clientY - r.top }; }
        function down(e) { e.preventDefault(); if (!cx) resize(); drawing = true; last = pt(e); }
        function move(e) { if (!drawing || !cx) return; e.preventDefault(); var p = pt(e); cx.beginPath(); cx.moveTo(last.x, last.y); cx.lineTo(p.x, p.y); cx.stroke(); last = p; cv._drawn = true; }
        function up() { drawing = false; }
        cv.addEventListener('mousedown', down); cv.addEventListener('mousemove', move); window.addEventListener('mouseup', up);
        cv.addEventListener('touchstart', down, { passive: false }); cv.addEventListener('touchmove', move, { passive: false }); cv.addEventListener('touchend', up);
        card.querySelector('#kt-ack-sig-clear').addEventListener('click', function () { if (cx) cx.clearRect(0, 0, cv.width, cv.height); cv._drawn = false; });
        card._sig = cv;
      })();
      var _dt = card.querySelector('#kt-ack-date'); if (_dt) _dt.textContent = new Date().toLocaleDateString();

      card.querySelector('#kt-ack-submit').addEventListener('click', async function () {
        const name = card.querySelector('#kt-ack-name').value.trim();
        const comment = card.querySelector('#kt-ack-comment').value.trim();
        if (name.length < 2) {
          Dom.toast('Please type your full name', 'error');
          return;
        }
        var sig = null;
        try { var cv = card._sig; if (cv && cv._drawn) sig = cv.toDataURL('image/png'); } catch (e) {}
        if (!sig) { Dom.toast('Please draw your signature above', 'error'); return; }
        const btn = card.querySelector('#kt-ack-submit');
        btn.disabled = true; btn.textContent = 'Saving...';
        try {
          await Api.post(base + '/incidents/' + inc.id + '/acknowledge', {
            signed_name: name, comment: comment || null, signature_data: sig,
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
    wrap.className = 'kt-inc-form';
    main.appendChild(wrap);
    // Phone/APK: single-column form, tighter padding, 16px controls (no iOS zoom),
    // and a stacked header — the 2-column grid was unusable on a narrow screen.
    if (!document.getElementById('kt-inc-form-css')) {
      var _fs = document.createElement('style'); _fs.id = 'kt-inc-form-css';
      _fs.textContent = '@media(max-width:700px){#appMain .kt-inc-form .page-header-v17{flex-direction:column;align-items:flex-start;gap:8px;}#appMain .kt-inc-form .page-header-v17 h1{font-size:20px;line-height:1.25;}#appMain .kt-inc-form form{padding:16px !important;max-width:100% !important;}#appMain .kt-inc-form .form-grid{grid-template-columns:1fr !important;gap:12px !important;}#appMain .kt-inc-form label{display:block;font-size:13px;font-weight:700;color:#334155;margin-bottom:4px;}#appMain .kt-inc-form input,#appMain .kt-inc-form select,#appMain .kt-inc-form textarea{font-size:16px !important;}}';
      document.head.appendChild(_fs);
    }

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
        '<div class="kt-inc-actions" style="display:flex;gap:8px;margin-top:22px;flex-wrap:wrap;">' +
          '<button type="submit" class="btn btn-primary" data-submit="true" style="padding:10px 18px;font-size:14px;font-weight:700;border-radius:10px;flex:0 0 auto;">Save &amp; submit</button>' +
          '<button type="button" class="btn btn-secondary" data-submit="false" style="padding:10px 18px;font-size:14px;font-weight:700;border-radius:10px;flex:0 0 auto;">Save as draft</button>' +
          '<button type="button" class="btn btn-ghost" id="kt-cancel" style="padding:10px 16px;font-size:14px;font-weight:700;border-radius:10px;flex:0 0 auto;">Cancel</button>' +
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
