/* ═══════════════════════════════════════════════════════════════════
   KIDDIETRAC v22p39 — Audit log viewer
   Hash: #audit-logs
   agency_admin sees their own agency. platform_admin sees every row.
   ═══════════════════════════════════════════════════════════════════ */
(function (window) {
  'use strict';

  // ── Human-readable action labels ─────────────────────────────────────
  // The log holds two kinds of action. Named events ("email.sent", "login") and
  // raw HTTP strings written by the write-auditing middleware
  // ("post:api/v1/notifications/mark-read", sometimes with a " [fail]" suffix).
  // Neither is something you should have to decode while auditing an incident.
  var ACTION_LABELS = {
    'login': 'Signed in',
    'login_failed': 'Failed sign-in',
    'logout': 'Signed out',
    'email.sent': 'Email sent',
    'email.failed': 'Email failed',
    'chat.email_notified': 'Missed-message email sent',
    'message.sent': 'Message sent',
    'message.edited': 'Message edited',
    'message.deleted': 'Message deleted',
    'message.nudge': 'Nudge sent',
    'announcement.sent': 'Announcement sent',
    'digest.daily_sent': 'Daily digest sent',
    'digest.weekly_sent': 'Weekly digest sent',
    'campaign.email_sent': 'Campaign email sent',
    'password_set_via_invite': 'Password set from invite',
    'password_reset_requested': 'Password reset requested',
    'agency.invite_resent': 'Invite re-sent',
    'lesson_plan.saved': 'Lesson plan saved',
    'centre.deleted': 'Centre deleted',
    'staff.clock_in': '🟢 Clocked IN',
    'staff.clock_out': '🔴 Clocked OUT',
  };

  // Known endpoints, said plainly. Anything not listed still gets a sensible
  // fallback below rather than being dumped raw on screen.
  var PATH_LABELS = [
    [/notifications\/mark-read/, 'Marked notifications read'],
    [/notifications\/delete/, 'Deleted notifications'],
    [/push\/subscribe/, 'Enabled push notifications'],
    [/push\/device/, 'Registered a device for push'],
    [/push\/test-fcm/, 'Sent a test push'],
    [/auth\/logout/, 'Signed out'],
    [/auth\/agreement\/sign/, 'Signed the Terms & NDA'],
    [/auth\/agreement\/decline/, 'DECLINED the Terms & NDA'],
    [/auth\/mfa\/setup/, 'Set up two-factor'],
    [/integration\/sync/, 'Integration sync'],
    [/provider\/chats\/\d+\/send/, 'Replied to a family'],
    [/provider\/chats\/start/, 'Started a conversation'],
    [/parent\/messages\/nudge/, 'Parent nudged the centre'],
    [/parent\/messages/, 'Parent sent a message'],
    [/provider\/announcements/, 'Sent an announcement'],
    [/provider\/observations\/structure/, 'Structured an observation with AI'],
    [/provider\/observations\/save/, 'Saved an observation'],
    [/provider\/observations/, 'Recorded an observation'],
    [/provider\/check-in-batch/, 'Signed children in (batch)'],
    [/provider\/check-in/, 'Signed a child in'],
    [/provider\/check-out/, 'Signed a child out'],
    [/parent\/checkin\/scan/, 'Parent scanned the check-in QR'],
    [/parent\/absences/, 'Parent reported an absence'],
    [/staff\/punch/, 'Clocked in / out'],
    [/attendance\/pattern/, 'Updated an attendance pattern'],
    [/billing\/setup-intent/, 'Started card setup'],
    [/admin\/email-settings/, 'Changed email settings'],
    [/care\/logs/, 'Logged a care moment'],
    [/photos\/upload/, 'Shared a photo or video'],
    [/invoices\/generate/, 'Generated invoices'],
  ];

  var VERBS = { post: 'Created', patch: 'Updated', put: 'Updated', delete: 'Deleted', get: 'Viewed' };

  /**
   * The label for a row. Prefer the SERVER's action_label: the API resolves the
   * record ids in the path to real names ("Signed a form" + the form's title),
   * where this file can only pattern-match the raw URL. Two independent label
   * tables drift apart — the browser showed "Created photos" for what the API
   * already described as "Created a photo". Fall back to the local mapping for
   * rows an older API build didn't enrich.
   */
  function rowActionLabel(l) {
    if (l && typeof l.action_label === 'string' && l.action_label.trim() !== '') return l.action_label;
    return actionLabel(l && l.action);
  }

  // The agency's word for a facility — "Centre", "Provider" or "Room" — applied to
  // the sentence this screen generates. Without it, an agency set to Providers still
  // read "Centre updated" in its own audit trail.
  function termed(t) {
    return (window.KT && KT.termText) ? KT.termText(t) : t;
  }

  function actionLabel(raw) {
    var a = String(raw || '').trim();
    if (!a) return '—';

    var failed = / \[fail\]$/i.test(a);
    a = a.replace(/ \[fail\]$/i, '');

    var label = ACTION_LABELS[a];

    if (!label) {
      var m = a.match(/^([a-z]+):(.*)$/i);
      if (m) {
        var verb = m[1].toLowerCase();
        var path = m[2].replace(/^api\/v1\//, '');

        for (var i = 0; i < PATH_LABELS.length; i++) {
          if (PATH_LABELS[i][0].test(path)) { label = PATH_LABELS[i][1]; break; }
        }
        if (!label) {
          // Fallback: "Updated admin / users / 12" reads far better than
          // "patch:api/v1/admin/users/12".
          var pretty = path.split('/')
            .filter(function (p) { return p && !/^\d+$/.test(p); })
            .join(' ')
            .replace(/[-_]/g, ' ');
          label = (VERBS[verb] || verb.toUpperCase()) + ' ' + pretty;
          label = label.charAt(0).toUpperCase() + label.slice(1);
        }
      } else {
        // A named event we don't have a label for: "care.log_added" → "Care log added"
        label = a.replace(/[._-]/g, ' ');
        label = label.charAt(0).toUpperCase() + label.slice(1);
      }
    }

    return termed(label) + (failed ? ' — failed' : '');
  }

  // Turn a raw entity_type ("room", "menu_week", "check_event") into a plain word
  // an auditor reads — no "id" jargon, no table names.
  function prettyEntityType(t) {
    if (!t) return '';
    // The middleware takes entity_type from the URL, so it is sometimes a bare
    // "id" (from ".../managed-forms/9/sign") or a number. Those are not a kind of
    // thing — printing them gave rows like "test 8  Id". Say nothing instead.
    var raw = String(t).trim().toLowerCase();
    if (raw === 'id' || raw === 'ids' || /^\d+$/.test(raw)) return '';
    var MAP = {
      user: 'User', users: 'User', child: 'Child', children: 'Child',
      family: 'Family', families: 'Family', centre: 'Centre', centres: 'Centre',
      room: 'Room', rooms: 'Room', agency: 'Agency', agencies: 'Agency',
      integration: 'Integration', conversation: 'Message thread', message: 'Message',
      photo: 'Photo', photos: 'Photo', video: 'Video',
      care: 'Care log', logs: 'Care log', 'care-logs': 'Care log',
      managed_form: 'Form', 'managed-forms': 'Form', managed_forms: 'Form',
      invoice: 'Invoice', invoices: 'Invoice', waitlist: 'Waitlist', announcement: 'Announcement',
      incident: 'Incident report', task: 'Task', document: 'Document', menu_week: 'Menu',
      lesson_plan: 'Lesson plan', check_event: 'Check-in / out', daily_event: 'Care log',
      time_punch: 'Time clock', guardian: 'Parent', role_assignment: 'Role',
    };
    var key = String(t).toLowerCase();
    if (MAP[key]) return termed(MAP[key]);
    return key.replace(/_/g, ' ').replace(/\b\w/g, function (c) { return c.toUpperCase(); });
  }

  if (!window.KT) return;
  var KT = window.KT;
  var Api = KT.Api;
  var Dom = KT.Dom;
  var Shell = KT.Shell;

  var state = {
    filters: { entity_type: '', action: '', q: '', since: '', until: '' },
    offset: 0,
    limit: 50,
    total: 0,
    actions: [],
    entities: [],
  };

  function esc(s) { return s == null ? '' : String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;'); }
  // Timestamps come from MySQL as "2026-07-14 17:47:00" — UTC, with NO timezone
  // marker — so new Date(t) read them as LOCAL and every entry in the audit log
  // was shown four hours late. Parse as UTC and render in the AGENCY's timezone
  // (kt-tz.js), which is the only clock an auditor cares about.
  function auditTz() {
    return (window.KT && KT.tz) ? KT.tz() : 'America/Toronto';
  }
  function parseTs(t) {
    if (window.KT && KT.parseTs) return KT.parseTs(t);
    var s = String(t || '').trim();
    return new Date(/(Z|[+-]\d{2}:?\d{2})$/.test(s) ? s.replace(' ', 'T') : s.replace(' ', 'T') + 'Z');
  }
  function fmtTime(t) {
    if (!t) return '—';
    try {
      var d = parseTs(t);
      if (isNaN(d)) return t;
      return new Intl.DateTimeFormat('en-CA', {
        timeZone: auditTz(), dateStyle: 'medium', timeStyle: 'short',
      }).format(d);
    } catch (e) { return t; }
  }
  function relTime(t) {
    if (!t) return '';
    var ms = Date.now() - parseTs(t).getTime();
    if (ms < 60000) return 'just now';
    if (ms < 3600000) return Math.floor(ms / 60000) + 'm ago';
    if (ms < 86400000) return Math.floor(ms / 3600000) + 'h ago';
    return Math.floor(ms / 86400000) + 'd ago';
  }

  /**
   * Rewrite every timestamp inside a payload into the AGENCY's timezone.
   *
   * Clients post times as UTC ISO strings ("2026-08-12T11:50:00.000Z"), so the raw
   * payload showed a care log entered at 7:50 a.m. as 11:50 — contradicting the
   * "When" line directly above it in the same dialog. The audit trail must read in
   * one clock, and that clock is the agency's.
   */
  function localiseTimes(node) {
    var ISO = /^\d{4}-\d{2}-\d{2}([ T]\d{2}:\d{2}(:\d{2})?(\.\d+)?(Z|[+-]\d{2}:?\d{2})?)?$/;
    if (Array.isArray(node)) return node.map(localiseTimes);
    if (node && typeof node === 'object') {
      var out = {};
      Object.keys(node).forEach(function (k) { out[k] = localiseTimes(node[k]); });
      return out;
    }
    if (typeof node === 'string' && ISO.test(node.trim())) {
      var s = node.trim();
      if (s.indexOf(':') === -1) return s;                 // a bare date has no zone to fix
      var d = parseTs(s);
      if (isNaN(d)) return node;
      try {
        return new Intl.DateTimeFormat('en-CA', {
          timeZone: auditTz(), dateStyle: 'medium', timeStyle: 'short',
        }).format(d);
      } catch (e) { return node; }
    }
    return node;
  }

  var ACTION_ICONS = {
    'user.created': '👤', 'user.revived': '♻️', 'user.deleted': '✖',
    'user.updated': '✏', 'user.role_changed': '🎚',
    'centre.created': '🏫', 'centre.updated': '🔧', 'centre.archived': '🗄',
    'agency.created': '🏛', 'agency.updated': '🔧',
    'agency.suspended': '⏸', 'agency.resumed': '▶',
    'family.created': '👪', 'family.updated': '✏',
    'invoice.created': '💸', 'invoice.paid': '✅', 'invoice.sent': '📧',
    'branding.updated': '🎨',
    'campaign.email_sent': '📣', 'digest.daily_sent': '📅', 'digest.weekly_sent': '📅',
    'kiosk.checkin': '🟢', 'kiosk.checkout': '🔴',
  };

  // Top-level screen: for platform admins, a tab bar with the Audit log + the
  // Email log (hosted here as a subtab). Everyone else just gets the audit log.
  function render(container) {
    Dom.clear(container);
    var isPlatform = false;
    try {
      var u = JSON.parse(sessionStorage.getItem('kt_user') || localStorage.getItem('kt_user') || '{}');
      isPlatform = (u.roles && u.roles.indexOf('platform_admin') > -1) || sessionStorage.getItem('kt_is_platform_admin') === '1';
    } catch (e) {}
    var hasEmail = isPlatform && window.KT && KT.EmailLog && KT.EmailLog.render;
    if (!hasEmail) { renderAudit(container); return; }

    var tabsRow = Dom.el('div', { style: 'display:flex;gap:4px;border-bottom:1px solid #E5E7EB;padding:16px 24px 0;max-width:1800px;margin:0 auto;' });
    var pane = Dom.el('div', {});
    var mkTab = function (label) { return Dom.el('button', { style: 'background:none;border:none;border-bottom:3px solid transparent;color:#64748B;font-weight:700;font-size:14px;padding:10px 16px;cursor:pointer;margin-bottom:-1px;' }, label); };
    var tA = mkTab('📜 Audit log');
    var tE = mkTab('📧 Email log');
    var activate = function (which) {
      tA.style.borderBottomColor = which === 'audit' ? '#1F6080' : 'transparent'; tA.style.color = which === 'audit' ? '#1F6080' : '#64748B';
      tE.style.borderBottomColor = which === 'email' ? '#1F6080' : 'transparent'; tE.style.color = which === 'email' ? '#1F6080' : '#64748B';
      Dom.clear(pane);
      if (which === 'email') { var inner = Dom.el('div', { style: 'padding:20px 24px;max-width:1800px;margin:0 auto;' }); pane.appendChild(inner); KT.EmailLog.render(inner); }
      else { renderAudit(pane); }
    };
    tA.addEventListener('click', function () { activate('audit'); });
    tE.addEventListener('click', function () { activate('email'); });
    tabsRow.appendChild(tA); tabsRow.appendChild(tE);
    container.appendChild(tabsRow);
    container.appendChild(pane);
    activate('audit');
  }

  function renderAudit(container) {
    Dom.clear(container);
    var wrap = Dom.el('div', { style: 'padding:24px;max-width:1800px;margin:0 auto;' });
    container.appendChild(wrap);

    var hero = Dom.el('div', { class: 'kt-hero', style: 'background:linear-gradient(135deg,#0F172A 0%,#1F6080 60%,#16637A 100%);' });
    hero.innerHTML = '<div class="kt-hero-greet">📜 ADMIN</div><h1>Audit log</h1>'
      + '<div class="kt-hero-sub">Every meaningful action on your agency — who did what, when, and from where. '
      + 'All times are shown in your agency timezone (' + auditTz() + ').</div>';
    wrap.appendChild(hero);

    var toolbar = Dom.el('div', { style: 'background:white;border-radius:12px;padding:14px;box-shadow:0 1px 3px rgba(0,0,0,.04);margin:16px 0;display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:10px;align-items:end;' });
    wrap.appendChild(toolbar);

    var listWrap = Dom.el('div', { style: 'background:white;border-radius:12px;box-shadow:0 1px 3px rgba(0,0,0,.04);overflow:hidden;' });
    wrap.appendChild(listWrap);

    var pager = Dom.el('div', { style: 'display:flex;align-items:center;justify-content:space-between;margin-top:14px;font-size:13px;color:#6B7280;' });
    wrap.appendChild(pager);

    function reload() {
      Dom.clear(listWrap);
      listWrap.appendChild(Dom.el('div', { style: 'padding:32px;text-align:center;color:#64748B;font-size:13px;' }, 'Loading audit log…'));

      var qs = new URLSearchParams();
      Object.keys(state.filters).forEach(function (k) { if (state.filters[k]) qs.set(k, state.filters[k]); });
      qs.set('limit', String(state.limit));
      qs.set('offset', String(state.offset));

      Api.get('/admin/audit-logs?' + qs.toString()).then(function (data) {
        state.total = data.total || 0;
        if (data.filters) {
          // Never blank the dropdown: keep what we already have if a response
          // omits the list.
          var before = state.actions.length + '|' + state.entities.length;
          if (data.filters.actions && data.filters.actions.length) state.actions = data.filters.actions;
          // The API sends `entities`; this read `entity_types`, which does not
          // exist — so the Entity filter was always empty.
          var ents = data.filters.entities || data.filters.entity_types;
          if (ents && ents.length) state.entities = ents;

          // THE BUG: the toolbar is built BEFORE the first request returns, when
          // state.actions is still empty — and nothing ever rebuilt it. So the
          // Action dropdown sat there with nothing in it forever, no matter what
          // the API sent. Rebuild it the moment the lists change.
          if (before !== state.actions.length + '|' + state.entities.length) {
            buildToolbar();
          }
        }
        Dom.clear(listWrap);
        if (!data.logs || !data.logs.length) {
          listWrap.appendChild(Dom.el('div', { style: 'padding:48px;text-align:center;color:#6B7280;' }, 'No events match the current filters.'));
        } else {
          listWrap.appendChild(renderTable(data.logs));
        }
        buildPager();
      }).catch(function (e) {
        Dom.clear(listWrap);
        listWrap.appendChild(Dom.el('div', { style: 'padding:24px;color:#DC2626;' }, 'Could not load: ' + (e.message || 'error')));
      });
    }

    function buildToolbar() {
      Dom.clear(toolbar);

      // Action dropdown
      // The filter lists the same clean labels — a dropdown of
      // "post:api/v1/notifications/mark-read" is not a filter anyone can use.
      toolbar.appendChild(filterSelect('Action', state.filters.action, [''].concat(state.actions.map(function (a) { return a; })), function (v) {
        state.filters.action = v; state.offset = 0; reload();
      }));

      // Entity type dropdown
      toolbar.appendChild(filterSelect('Entity', state.filters.entity_type, [''].concat(state.entities), function (v) {
        state.filters.entity_type = v; state.offset = 0; reload();
      }));

      // Free-text
      toolbar.appendChild(filterInput('Search', state.filters.q, 'text', function (v) {
        state.filters.q = v; state.offset = 0; reload();
      }));

      // Date range
      toolbar.appendChild(filterInput('From', state.filters.since, 'date', function (v) {
        state.filters.since = v ? (v + ' 00:00:00') : '';
        state.offset = 0; reload();
      }));
      toolbar.appendChild(filterInput('To', state.filters.until, 'date', function (v) {
        state.filters.until = v ? (v + ' 23:59:59') : '';
        state.offset = 0; reload();
      }));

      // Reset
      var resetWrap = Dom.el('div', { style: 'display:flex;align-items:flex-end;gap:6px;' });
      var resetBtn = Dom.el('button', { style: 'background:white;border:1px solid #D1D5DB;color:#374151;padding:8px 12px;border-radius:8px;font-weight:600;cursor:pointer;font-size:13px;flex:1;' }, 'Reset');
      resetBtn.addEventListener('click', function () {
        state.filters = { entity_type: '', action: '', q: '', since: '', until: '' };
        state.offset = 0;
        buildToolbar(); reload();
      });
      resetWrap.appendChild(resetBtn);

      // v22p46: CSV download — same filters apply to the export
      var csvBtn = Dom.el('button', { style: 'background:white;border:1px solid #16A34A;color:#16A34A;padding:8px 12px;border-radius:8px;font-weight:600;cursor:pointer;font-size:13px;', title: 'Export filtered audit log to CSV' }, '⤓ CSV');
      csvBtn.addEventListener('click', function () {
        var qs = new URLSearchParams();
        Object.keys(state.filters).forEach(function (k) { if (state.filters[k]) qs.set(k, state.filters[k]); });
        qs.set('format', 'csv');
        var apiBase = (window.KT && window.KT.API_BASE) || 'https://api.kiddietrac.com/api/v1';
        var token = sessionStorage.getItem('kt_token');
        var activeAgencyId = sessionStorage.getItem('kt_active_agency_id') || '';
        var headers = { 'Authorization': 'Bearer ' + token, 'Accept': 'text/csv' };
        if (activeAgencyId) headers['X-Active-Agency-Id'] = activeAgencyId;
        csvBtn.disabled = true; csvBtn.textContent = '…';
        fetch(apiBase + '/admin/audit-logs?' + qs.toString(), { headers: headers })
          .then(function (r) { if (!r.ok) throw new Error('HTTP ' + r.status); return r.blob(); })
          .then(function (blob) {
            var url = URL.createObjectURL(blob);
            var a = document.createElement('a'); a.href = url; a.download = 'audit-log.csv';
            document.body.appendChild(a); a.click();
            setTimeout(function () { URL.revokeObjectURL(url); a.remove(); }, 500);
          })
          .catch(function (e) { alert('CSV failed: ' + e.message); })
          .finally(function () { csvBtn.disabled = false; csvBtn.textContent = '⤓ CSV'; });
      });
      resetWrap.appendChild(csvBtn);
      toolbar.appendChild(resetWrap);
    }

    function buildPager() {
      Dom.clear(pager);
      var shown = Math.min(state.offset + state.limit, state.total);
      pager.appendChild(Dom.el('div', {}, state.total === 0 ? '0 events' : (state.offset + 1 + '–' + shown + ' of ' + state.total)));
      var btns = Dom.el('div', { style: 'display:flex;gap:8px;' });
      var prev = Dom.el('button', { style: pagerBtn(), disabled: state.offset === 0 }, '◀ Prev');
      prev.addEventListener('click', function () { state.offset = Math.max(0, state.offset - state.limit); reload(); });
      var next = Dom.el('button', { style: pagerBtn(), disabled: state.offset + state.limit >= state.total }, 'Next ▶');
      next.addEventListener('click', function () { if (state.offset + state.limit < state.total) { state.offset += state.limit; reload(); } });
      btns.appendChild(prev); btns.appendChild(next);
      pager.appendChild(btns);
    }

    buildToolbar();
    reload();
  }

  function renderTable(logs) {
    // This screen has its OWN server-side search + pagination (the toolbar filters
    // and the ◀ Prev / Next ▶ pager below, backed by /admin/audit-logs). Pre-set
    // kt-table-filter's dedup flag so its GLOBAL auto search+pager does NOT also
    // attach — otherwise it client-paginated the 50 rows we load per server page
    // into 25-per-page chunks, producing a SECOND Prev/Next and capping the view
    // at "2 pages" no matter how many pages the server actually has.
    var t = Dom.el('table', { 'data-kt-filtered': '1', style: 'width:100%;border-collapse:collapse;font-size:13px;' });
    var thead = Dom.el('thead', { style: 'background:#FAFBFC;' });
    var hr = Dom.el('tr');
    ['When', 'Action', 'Entity', 'Actor', 'Location'].forEach(function (h) {
      hr.appendChild(Dom.el('th', { style: 'text-align:left;padding:10px 14px;font-size:11px;font-weight:700;color:#6B7280;letter-spacing:1px;text-transform:uppercase;border-bottom:1px solid #E5E7EB;' }, h));
    });
    thead.appendChild(hr);
    t.appendChild(thead);

    var tb = Dom.el('tbody');
    var iconFor = function (a) {
      a = a || '';
      if (ACTION_ICONS[a]) return ACTION_ICONS[a];
      if (/email\.failed/.test(a)) return '⚠️';
      if (/email/.test(a)) return '📧';
      if (/delete|deleted|destroy/.test(a)) return '🗑️';
      if (/login/.test(a)) return '🔑';
      if (/^post:/.test(a)) return '➕';
      if (/^(patch|put):/.test(a)) return '✏️';
      return '•';
    };
    logs.forEach(function (l) {
      var row = Dom.el('tr', { style: 'border-bottom:1px solid #F3F4F6;cursor:pointer;', title: 'Click to view full payload' });
      row.addEventListener('mouseenter', function () { row.style.background = '#FAFBFC'; });
      row.addEventListener('mouseleave', function () { row.style.background = 'white'; });
      row.addEventListener('click', function () { openDetailModal(l); });

      // When
      var whenCell = Dom.el('td', { style: 'padding:10px 14px;vertical-align:top;' });
      whenCell.appendChild(Dom.el('div', { style: 'font-weight:600;color:#111827;' }, fmtTime(l.created_at)));
      whenCell.appendChild(Dom.el('div', { style: 'font-size:11px;color:#64748B;' }, relTime(l.created_at)));
      row.appendChild(whenCell);

      // Action
      var actCell = Dom.el('td', { style: 'padding:10px 14px;vertical-align:top;max-width:360px;' });
      var actLine = Dom.el('div', {});
      actLine.appendChild(Dom.el('span', { style: 'font-size:16px;margin-right:6px;' }, iconFor(l.action)));
      // Show the clean label, and keep the raw action in the tooltip for anyone
      // who needs the exact event name (support, an auditor, a bug report).
      var lbl = Dom.el('span', {
        style: 'font-weight:600;color:' + (/\[fail\]/.test(l.action || '') ? '#B91C1C' : '#1F6080') + ';',
        title: l.action || '',
      }, rowActionLabel(l));
      actLine.appendChild(lbl);
      actCell.appendChild(actLine);
      if (l.summary) actCell.appendChild(Dom.el('div', { style: 'font-size:11px;color:#6B7280;margin-top:3px;word-break:break-word;' }, l.summary));
      row.appendChild(actCell);

      // Entity — the resolved NAME with a plain-English type label; no raw ids.
      var entCell = Dom.el('td', { style: 'padding:10px 14px;color:#374151;vertical-align:top;' });
      if (l.entity_name) {
        entCell.appendChild(Dom.el('div', { style: 'font-weight:600;color:#111827;' }, l.entity_name));
        if (l.entity_type) entCell.appendChild(Dom.el('div', { style: 'font-size:11px;color:#64748B;' }, prettyEntityType(l.entity_type)));
      } else {
        entCell.appendChild(Dom.el('div', { style: 'color:#374151;' }, l.entity_type ? prettyEntityType(l.entity_type) : '—'));
      }
      row.appendChild(entCell);

      // Actor
      var actorCell = Dom.el('td', { style: 'padding:10px 14px;vertical-align:top;' });
      actorCell.appendChild(Dom.el('div', { style: 'font-weight:600;color:#111827;' }, l.actor_name || 'system'));
      if (l.actor_email) actorCell.appendChild(Dom.el('div', { style: 'font-size:11px;color:#64748B;' }, l.actor_email));
      row.appendChild(actorCell);

      // Where it came from. A raw payload blob was noise — nobody audits by
      // reading JSON in a table cell, and it's still one click away in the detail
      // view. "Was this done from Canada, or somewhere nobody here has worked
      // from?" is the question an audit actually asks.
      var locCell = Dom.el('td', { style: 'padding:10px 14px;vertical-align:top;font-size:12px;color:#374151;' });
      locCell.appendChild(Dom.el('div', {}, l.location || l.ip_address || '—'));
      if (l.ip_address) {
        locCell.appendChild(Dom.el('div', {
          style: 'color:#64748B;font-family:ui-monospace,monospace;font-size:10.5px;margin-top:2px;',
        }, l.ip_address));
      }
      row.appendChild(locCell);

      tb.appendChild(row);
    });
    t.appendChild(tb);
    return t;
  }

  function openDetailModal(l) {
    var overlay = Dom.el('div', { style: 'position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:1000;display:flex;align-items:center;justify-content:center;padding:24px;' });
    var modal = Dom.el('div', { style: 'background:white;border-radius:14px;max-width:680px;width:100%;max-height:calc(100vh - 48px);overflow-y:auto;padding:24px 28px;box-shadow:0 12px 36px rgba(0,0,0,.25);' });
    overlay.appendChild(modal);

    var head = Dom.el('div', { style: 'display:flex;align-items:center;justify-content:space-between;margin-bottom:14px;' });
    var titleEl = Dom.el('h3', { style: 'margin:0;font-size:18px;' });
    // The heading is the readable action, not the raw endpoint — the dialog said
    // "post:api/v1/care/logs" while the Action row right below it already read
    // "Created a daily-care log".
    titleEl.textContent = (ACTION_ICONS[l.action] || '•') + ' ' + rowActionLabel(l);
    head.appendChild(titleEl);
    var x = Dom.el('button', { style: 'background:transparent;border:none;font-size:20px;color:#6B7280;cursor:pointer;' }, '×');
    x.addEventListener('click', function () { overlay.remove(); });
    head.appendChild(x);
    modal.appendChild(head);

    function row(k, v) {
      var r = Dom.el('div', { style: 'display:grid;grid-template-columns:140px 1fr;gap:10px;padding:8px 0;border-bottom:1px solid #F3F4F6;font-size:13px;' });
      r.appendChild(Dom.el('div', { style: 'color:#6B7280;font-weight:600;' }, k));
      r.appendChild(Dom.el('div', { style: 'color:#111827;word-break:break-word;' }, v == null ? '—' : String(v)));
      return r;
    }
    modal.appendChild(row('When', fmtTime(l.created_at) + ' (' + relTime(l.created_at) + ')'));
    modal.appendChild(row('Action', rowActionLabel(l)));
    modal.appendChild(row('What', l.entity_name
      ? (l.entity_name + (l.entity_type ? ' (' + prettyEntityType(l.entity_type) + ')' : ''))
      : (l.entity_type ? prettyEntityType(l.entity_type) : '—')));
    if (l.summary) modal.appendChild(row('Details', l.summary));
    modal.appendChild(row('Actor', l.actor_name + (l.actor_email ? ' — ' + l.actor_email : '')));
    modal.appendChild(row('IP address', l.ip_address));

    var prettyPayload = l.payload;
    try {
      if (typeof l.payload === 'string') prettyPayload = JSON.stringify(localiseTimes(JSON.parse(l.payload)), null, 2);
    } catch (e) {}
    modal.appendChild(Dom.el('div', { style: 'margin-top:14px;font-size:12px;font-weight:700;color:#6B7280;letter-spacing:1px;text-transform:uppercase;margin-bottom:6px;' }, 'Payload · times in ' + auditTz()));
    modal.appendChild(Dom.el('pre', { style: 'background:#0F172A;color:#E2E8F0;padding:14px;border-radius:8px;overflow:auto;font-size:12px;line-height:1.4;max-height:240px;' }, prettyPayload || '(empty)'));

    document.body.appendChild(overlay);
    overlay.addEventListener('click', function (e) { if (e.target === overlay) overlay.remove(); });
  }

  // ─── helpers ───────────────────────────────────────────────────────
  function filterSelect(label, value, options, onChange) {
    var wrap = Dom.el('div');
    wrap.appendChild(Dom.el('label', { style: lblStyle() }, label));
    var sel = Dom.el('select', { style: inputStyle() });
    options.forEach(function (o) {
      // The value stays raw (that's what the API filters on); only the text is
      // humanised — a dropdown of "post:api/v1/notifications/mark-read" is not a
      // filter anyone can actually use.
      var text = o ? (label === 'Action' ? actionLabel(o) : o) : 'All';
      var opt = Dom.el('option', { value: o }, text);
      if (o === value) opt.selected = true;
      sel.appendChild(opt);
    });
    sel.addEventListener('change', function () { onChange(sel.value); });
    wrap.appendChild(sel);
    return wrap;
  }
  function filterInput(label, value, type, onChange) {
    var wrap = Dom.el('div');
    wrap.appendChild(Dom.el('label', { style: lblStyle() }, label));
    var input = Dom.el('input', { type: type, value: value && type === 'date' ? value.slice(0, 10) : value, style: inputStyle() });
    var deb;
    function fire() { onChange(input.value); }
    input.addEventListener(type === 'text' ? 'input' : 'change', function () {
      clearTimeout(deb); deb = setTimeout(fire, type === 'text' ? 320 : 0);
    });
    wrap.appendChild(input);
    return wrap;
  }
  function lblStyle() { return 'display:block;font-size:11px;font-weight:700;color:#6B7280;letter-spacing:0.5px;text-transform:uppercase;margin-bottom:4px;'; }
  function inputStyle() { return 'width:100%;padding:7px 10px;border:1px solid #D1D5DB;border-radius:8px;font-size:13px;background:white;box-sizing:border-box;'; }
  function pagerBtn() { return 'background:white;border:1px solid #D1D5DB;color:#374151;padding:6px 14px;border-radius:8px;font-size:13px;cursor:pointer;'; }

  // ─── Shell registration ────────────────────────────────────────────
  if (Shell && Shell.registerScreen) {
    Shell.registerScreen('agency_admin:audit-logs',   render);
    Shell.registerScreen('platform_admin:audit-logs', render);
    Shell.registerScreen('centre_director:audit-logs', render);
  }
  KT.AuditLogs = { render: render };
})(window);
