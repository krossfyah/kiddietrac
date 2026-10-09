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
    'sales.lead_deleted': '🗑 Sales lead deleted',
    'perf.slow_request': '🐌 Slow response',
    'login': 'Signed in',
    'login_failed': 'Failed sign-in',
    'logout': 'Signed out',
    'email.sent': 'Email sent',
    'email.failed': 'Email failed',
    /* Zum Rails. The fallback would render these as "Zum settled", which is not wrong
       but tells a reader nothing about what actually happened to the money. */
    'zum.settled': '💳 Payment settled',
    'zum.failed': '💳 Payment failed',
    'zum.cancelled': '💳 Payment cancelled',
    'zum.cancelling': '💳 Payment being cancelled',
    'zum.submitted': '💳 Payment submitted to the bank',
    'zum.in_review': '💳 Payment held for review',
    'zum.requested': '💳 Payment requested from a family',
    'zum.sent': '💳 Money sent out',
    'zum.bank_account_saved': '🏦 Bank account saved for payments',
    'attendance.backdated': '⏱ Attendance corrected',
    'attendance.entry_removed': '⏱ Attendance entry removed',
    /* Staff shifts, not children — the two read alike in a log and mean very different
       things, so the wording says whose hours moved. Unlabelled until now, which the
       fallback rendered as "Timepunch corrected": a word no director uses, on rows that
       change what somebody is paid. */
    'timepunch.corrected': '⏱ Staff hours corrected',
    'timepunch.created': '⏱ Staff shift entered by hand',
    'timepunch.deleted': '⏱ Staff shift removed',
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
    /* Payment providers. These are route audits, so without a rule here they render
       as "Created zum pay" — which is the least useful possible description of a
       parent trying to pay an invoice. */
    [/parent\/zum\/pay/, 'Parent paid an invoice (Zūm)'],
    [/parent\/zum\/bank-account/, 'Parent saved a bank account (Zūm)'],
    [/director\/zum\/send/, 'Sent money out (Zūm)'],
    [/director\/zum\/request/, 'Requested money from a family (Zūm)'],
    [/zumrails\/webhook/, 'Zūm settlement callback'],
    [/payment-providers\/zumrails/, 'Changed Zūm Rails settings'],
    [/payment-providers\/stripe/, 'Changed Stripe settings'],
    [/payment-providers/, 'Changed payment provider settings'],
    [/billing\/setup-intent/, 'Started adding a card (Stripe)'],
    [/billing\/save-card/, 'Saved a card (Stripe)'],
    [/billing\/ach-/, 'Set up bank debit (Stripe)'],
    [/billing\/autopay/, 'Changed autopay'],
    [/wallet\/setup-intent/, 'Started adding a payment method'],
    [/wallet\/\d+\/default/, 'Changed the default payment method'],
    [/wallet/, 'Changed saved payment methods'],
    [/invoices\/\d+\/charge/, 'Charged a saved payment method'],

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
      /* MILLISECONDS. One request writes several rows in the same second — a login
         and its device row, a send and its suppression note — and a sync writes 25 of
         them. At second precision those all read as one moment, which made a correctly
         ordered list look arbitrary and left no way to line a row up against a server
         log. The list tie-breaks on id; this is what makes the order visible.

         The components are spelled out rather than using timeStyle because Intl treats
         dateStyle/timeStyle and the individual fields as mutually exclusive families
         and THROWS when they are mixed — including on fractionalSecondDigits, which
         older WebViews (the Android and iOS builds load this page in one) do not
         support at all. Appending from the Date sidesteps both. Milliseconds are
         timezone-invariant, every offset being a whole number of minutes, so this
         stays exact in the agency's zone. */
      var base = new Intl.DateTimeFormat('en-CA', {
        timeZone: auditTz(),
        year: 'numeric', month: 'short', day: 'numeric',
        hour: '2-digit', minute: '2-digit', second: '2-digit', hour12: true,
      }).format(d);
      var ms = ('00' + d.getMilliseconds()).slice(-3);
      // Straight after the seconds; if the locale ever stops matching, the time is
      // still correct, just without the fraction.
      return base.replace(/(\d{1,2}:\d{2}:\d{2})/, '$1.' + ms);
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

  /* Session-scoped so it survives the periodic re-render and a reload, but does not
     follow somebody into tomorrow's session on a shared machine. */
  var KT_AUDIT_TAB_KEY = 'kt_audit_subtab';

  /* THE AUDIT LOG ON A PHONE (2026-10-09, Anthony: "audit log view on mobile apk is hard
     to read with words spilling over to the next line, subtabs not visible and easy to
     scroll etc and the view is not using the entire screen size like other pages").
     Measured at 390px:
       · every pane added its own 24px of padding inside #appMain's 10px, so cards started
         34px in — the gutters he saw. On a phone the panes now add none.
       · the subtab row could not scroll (overflow visible) and its labels wrapped to two
         lines (80px-tall tabs), so "Email log" was cut off at the edge. It is now one line
         that scrolls sideways, fades at the edge that has more, and brings the open tab
         into view.
       · the five filters stacked full-width took a whole screen before the first entry. On
         a phone they sit behind a "Filters" button (with a count when any are set), two to
         a row when open.
     The card text itself was the shared phone-table layout (kt-mobile-tables.js), fixed
     there for every table. */
  function ensureAuditCss() {
    if (document.getElementById('kt-audit-phone-css')) return;
    var st = document.createElement('style');
    st.id = 'kt-audit-phone-css';
    st.textContent = ''
      + '.kt-al-ftoggle{display:none;}'
      + '.kt-al-tabs{scrollbar-width:none;}'
      + '.kt-al-tabs::-webkit-scrollbar{display:none;}'
      + '.kt-al-tab{white-space:nowrap;flex:0 0 auto;}'
      + '@media (max-width:600px){'
      +   '.kt-al-wrap{padding:6px 0 14px !important;}'
      +   '.kt-al-tabs{padding:2px 0 0 !important;overflow-x:auto;overflow-y:hidden;-webkit-overflow-scrolling:touch;overscroll-behavior-x:contain;}'
      +   '.kt-al-tabs.kt-al-more-r{-webkit-mask-image:linear-gradient(to right,#000 82%,transparent);mask-image:linear-gradient(to right,#000 82%,transparent);}'
      +   '.kt-al-tabs.kt-al-more-l{-webkit-mask-image:linear-gradient(to left,#000 82%,transparent);mask-image:linear-gradient(to left,#000 82%,transparent);}'
      +   '.kt-al-tabs.kt-al-more-l.kt-al-more-r{-webkit-mask-image:linear-gradient(to right,transparent,#000 14%,#000 86%,transparent);mask-image:linear-gradient(to right,transparent,#000 14%,#000 86%,transparent);}'
      +   '.kt-al-tab{padding:10px 12px !important;font-size:13.5px !important;}'
      +   '.kt-al-ftoggle{display:flex;align-items:center;gap:8px;width:100%;box-sizing:border-box;margin:12px 0 0;padding:9px 12px;background:#fff;border:1px solid #E2E8F0;border-radius:10px;font-size:14px;font-weight:700;color:#0F172A;cursor:pointer;}'
      +   '.kt-al-ftoggle .kt-al-fcount{background:#1F6080;color:#fff;border-radius:999px;font-size:11.5px;padding:1px 8px;}'
      +   '.kt-al-ftoggle .kt-al-fchev{margin-left:auto;color:#64748B;transition:transform .15s;}'
      +   '.kt-al-ftoggle.open .kt-al-fchev{transform:rotate(180deg);}'
      +   '.kt-al-toolbar{display:none !important;}'
      +   '.kt-al-toolbar.open{display:grid !important;grid-template-columns:1fr 1fr !important;gap:8px !important;padding:10px !important;margin:8px 0 12px !important;}'
      +   '.kt-al-toolbar.open > .kt-al-full{grid-column:1 / -1;}'
      +   '.kt-al-toolbar.open > *{min-width:0;}'
      +   '.kt-al-toolbar input,.kt-al-toolbar select{width:100% !important;max-width:100%;box-sizing:border-box;}'
      +   '.kt-al-toolbar input,.kt-al-toolbar select{height:38px;padding:4px 9px !important;font-size:15px !important;}'
      +   '.kt-al-list{margin-top:12px;background:transparent !important;box-shadow:none !important;border:0 !important;overflow:visible !important;}'
      +   '.kt-al-pager{margin-top:6px !important;}'
      +   '.kt-al-email .kt-al-efilters{display:grid !important;grid-template-columns:1fr 1fr;gap:8px !important;}'
      +   '.kt-al-email .kt-al-efilters > label{min-width:0 !important;max-width:none !important;}'
      +   '.kt-al-email .kt-al-efilters > label:first-child{grid-column:1 / -1;}'
      +   '.kt-al-email .kt-al-efilters input,.kt-al-email .kt-al-efilters select{width:100% !important;height:38px;}'
      + '}';
    document.head.appendChild(st);
  }

  /* The phone's "Filters" button for a toolbar. countFn says how many filters are set;
     the toolbar opens by itself when any are, so a search is never hidden. */
  function filterToggle(toolbar, countFn) {
    var btn = Dom.el('button', { type: 'button', class: 'kt-al-ftoggle', 'aria-expanded': 'false' });
    function paint() {
      var n = countFn();
      btn.innerHTML = '<span>⚙️ Filters</span>' + (n ? '<span class="kt-al-fcount">' + n + '</span>' : '')
        + '<span class="kt-al-fchev">▾</span>';
    }
    btn.addEventListener('click', function () {
      var open = !toolbar.classList.contains('open');
      toolbar.classList.toggle('open', open);
      btn.classList.toggle('open', open);
      btn.setAttribute('aria-expanded', open ? 'true' : 'false');
    });
    if (countFn()) { toolbar.classList.add('open'); btn.classList.add('open'); btn.setAttribute('aria-expanded', 'true'); }
    paint();
    btn.ktPaint = paint;
    return btn;
  }

  /* One-line, sideways-scrolling tab row: fade the edge that has more, and keep the open
     tab in view (scrollLeft, not scrollIntoView, which would also move the page). */
  function tabScroller(row) {
    function edges() {
      var more = row.scrollWidth - row.clientWidth > 4;
      row.classList.toggle('kt-al-more-r', more && row.scrollLeft + row.clientWidth < row.scrollWidth - 4);
      row.classList.toggle('kt-al-more-l', more && row.scrollLeft > 4);
    }
    row.addEventListener('scroll', edges, { passive: true });
    return {
      edges: edges,
      show: function (tab) {
        if (!tab || row.scrollWidth <= row.clientWidth) { edges(); return; }
        var l = tab.offsetLeft - row.offsetLeft, r = l + tab.offsetWidth;
        if (l < row.scrollLeft + 8) row.scrollLeft = Math.max(0, l - 24);
        else if (r > row.scrollLeft + row.clientWidth - 8) row.scrollLeft = r - row.clientWidth + 24;
        edges();
      },
    };
  }

  // Top-level screen: for platform admins, a tab bar with the Audit log + the
  // Email log (hosted here as a subtab). Everyone else just gets the audit log.
  function render(container) {
    Dom.clear(container);
    var isPlatform = false, isAgencyAdmin = false;
    try {
      var u = JSON.parse(sessionStorage.getItem('kt_user') || localStorage.getItem('kt_user') || '{}');
      isPlatform = (u.roles && u.roles.indexOf('platform_admin') > -1) || sessionStorage.getItem('kt_is_platform_admin') === '1';
      isAgencyAdmin = !!(u.roles && u.roles.indexOf('agency_admin') > -1);
    } catch (e) {}
    // Chat conversations: platform and agency admins only (the server enforces the same).
    var hasChats = isPlatform || isAgencyAdmin;
    /* The bar used to appear only for platform admins, because the email log was the
       only other tab and only they can load it. Payment integrations is for whoever
       runs the agency's money, so the bar now appears whenever there is more than one
       tab to show — an agency admin gets Audit log + Payments, a platform admin gets
       all four. */
    var hasEmail = isPlatform && window.KT && KT.EmailLog && KT.EmailLog.render;

    ensureAuditCss();
    var tabsRow = Dom.el('div', { class: 'kt-al-tabs', style: 'display:flex;gap:4px;border-bottom:1px solid #E5E7EB;padding:16px 24px 0;max-width:1800px;margin:0 auto;' });
    var pane = Dom.el('div', {});
    var mkTab = function (label) { return Dom.el('button', { class: 'kt-al-tab', style: 'background:none;border:none;border-bottom:3px solid transparent;color:#64748B;font-weight:700;font-size:14px;padding:10px 16px;cursor:pointer;margin-bottom:-1px;' }, label); };
    var tabScroll = tabScroller(tabsRow);
    var tA = mkTab('📜 Audit log');
    var tP = mkTab('💳 Payment integrations');
    var tE = mkTab('📧 Email log');
    // A dedicated home for mail that did not arrive. It was findable before only by
    // knowing to set the Email log's status filter, so a bounce nobody went looking
    // for was indistinguishable from a message that was never sent.
    var tX = mkTab('⚠️ Email errors');
    var tC = mkTab('💬 Chat conversations');
    var activate = function (which) {
      tC.style.borderBottomColor = which === 'chats' ? '#7C3AED' : 'transparent'; tC.style.color = which === 'chats' ? '#7C3AED' : '#64748B';
      var openTab = { audit: tA, payments: tP, email: tE, errors: tX, chats: tC }[which];
      setTimeout(function () { tabScroll.show(openTab); }, 0);
      tA.style.borderBottomColor = which === 'audit' ? '#1F6080' : 'transparent'; tA.style.color = which === 'audit' ? '#1F6080' : '#64748B';
      tP.style.borderBottomColor = which === 'payments' ? '#12805F' : 'transparent'; tP.style.color = which === 'payments' ? '#12805F' : '#64748B';
      tE.style.borderBottomColor = which === 'email' ? '#1F6080' : 'transparent'; tE.style.color = which === 'email' ? '#1F6080' : '#64748B';
      tX.style.borderBottomColor = which === 'errors' ? '#B91C1C' : 'transparent'; tX.style.color = which === 'errors' ? '#B91C1C' : '#64748B';
      /* The banner normaliser moves this row to just under the hero — which lives INSIDE
         the pane. Bring it home before the pane is emptied, or it is emptied with it (the
         "subtabs not visible" on a phone); it is placed under the new hero again after. */
      if (tabsRow.parentNode && tabsRow.parentNode !== container && pane.parentNode === container) { container.insertBefore(tabsRow, pane); }
      Dom.clear(pane);
      if (which === 'errors') {
        var xInner = Dom.el('div', { class: 'kt-al-wrap kt-al-email', style: 'padding:20px 24px;max-width:1800px;margin:0 auto;' });
        var xHero = Dom.el('div', { class: 'kt-hero', style: 'background:linear-gradient(135deg,#3F0D0A 0%,#8A1D16 60%,#B91C1C 100%);' });
        xHero.innerHTML = '<div class="kt-hero-greet">⚠️ ADMIN</div><h1>Email errors</h1>'
          + '<div class="kt-hero-sub">Every message that did not reach the person it was addressed to — bounced, '
          + 'failed, deferred or suppressed — newest first, with the reason. Times are in your agency timezone ('
          + auditTz() + ').</div>';
        xInner.appendChild(xHero);
        var xBody = Dom.el('div', { style: 'margin-top:16px;' });
        xInner.appendChild(xBody);
        pane.appendChild(xInner);
        KT.EmailLog.render(xBody, { status: 'problems' });
      }
      else if (which === 'email') {
        var inner = Dom.el('div', { class: 'kt-al-wrap kt-al-email', style: 'padding:20px 24px;max-width:1800px;margin:0 auto;' });
        // The Email log had no banner at all, so it opened straight onto a table while
        // its sibling tab had the gradient hero. Same construction as renderAudit's
        // hero, so kt-banner-normalize treats them identically.
        var eHero = Dom.el('div', { class: 'kt-hero', style: 'background:linear-gradient(135deg,#0F172A 0%,#1F6080 60%,#16637A 100%);' });
        eHero.innerHTML = '<div class="kt-hero-greet">📧 ADMIN</div><h1>Email log</h1>'
          + '<div class="kt-hero-sub">Every message the platform tried to send — delivered, suppressed or failed — '
          + 'with the reason why. All times are shown in your agency timezone (' + auditTz() + ').</div>';
        inner.appendChild(eHero);
        var eBody = Dom.el('div', { style: 'margin-top:16px;' });
        inner.appendChild(eBody);
        pane.appendChild(inner);
        KT.EmailLog.render(eBody);
      }
      else if (which === 'payments') { renderAudit(pane, { channel: 'payments' }); }
      else if (which === 'chats') { renderChats(pane); }
      else { renderAudit(pane); }

      // Re-run the banner normaliser by hand. It observes #appMain with
      // { childList: true } and NO subtree flag, so a tab swap inside `pane` — a
      // descendant — never reaches it. Without this the new hero would sit flat until
      // the periodic sweep wandered past, which is also why the Audit tab can look
      // right on arrival and unshimmered when you switch back to it.
      try { if (window.KT && KT.normalizeBanner) KT.normalizeBanner(); } catch (e) {}
    };
    /* Which tab you were on has to outlive the re-render.
       This screen is in kt-auto-refresh's LIVE_SCREENS, so it is rebuilt from scratch on a
       timer; the old code ended with a flat activate('audit'), which is why reading the
       Email log dropped you back onto the Audit log at what felt like random moments.
       (2026-08-27) */
    var pick = function (which) {
      try { sessionStorage.setItem(KT_AUDIT_TAB_KEY, which); } catch (e) {}
      activate(which);
    };
    tA.addEventListener('click', function () { pick('audit'); });
    tP.addEventListener('click', function () { pick('payments'); });
    tE.addEventListener('click', function () { pick('email'); });
    tX.addEventListener('click', function () { pick('errors'); });
    tC.addEventListener('click', function () { pick('chats'); });
    tabsRow.appendChild(tA);
    tabsRow.appendChild(tP);
    if (hasChats) { tabsRow.appendChild(tC); }
    // The email tabs read /platform/* and would 403 for anybody else, so they are
    // only built for the people who can actually load them.
    if (hasEmail) { tabsRow.appendChild(tE); tabsRow.appendChild(tX); }
    container.appendChild(tabsRow);
    container.appendChild(pane);

    /* Restore the subtab across an auto-refresh, but NOT when somebody has just
       navigated here. Remembering it for the whole session meant that once you had
       read the Email log, clicking "Audit log" in the menu kept opening the Email
       log — the screen was faithfully restoring a choice you made ten minutes and
       four screens ago. Clicking the menu item is a fresh request for the thing it
       names; a 45-second timer tick is not. */
    var remembered = '';
    var byRefresh = false;
    try { byRefresh = !!(window.KT && KT.autoRefresh && KT.autoRefresh.isRefreshing && KT.autoRefresh.isRefreshing()); } catch (e) {}
    if (byRefresh) {
      try { remembered = sessionStorage.getItem(KT_AUDIT_TAB_KEY) || ''; } catch (e) {}
    } else {
      // Arriving fresh: forget the old choice so it cannot resurface later either.
      try { sessionStorage.removeItem(KT_AUDIT_TAB_KEY); } catch (e) {}
    }
    // Never trust it blindly — a stored value from an older build, or one whose tab
    // this user cannot see, must fall back rather than render nothing.
    if (! hasEmail && /^(email|errors)$/.test(remembered)) { remembered = ''; }
    if (! hasChats && remembered === 'chats') { remembered = ''; }
    activate(/^(audit|payments|email|errors|chats)$/.test(remembered) ? remembered : 'audit');
  }

  /* CHAT CONVERSATIONS (2026-10-09, Anthony: "add to audit log a subtab for chat
     conversations that tracks all conversations with users etc"). Every conversation in
     the active agency — family (parent <-> staff) and staff-to-staff — newest activity
     first, searchable by person, family, centre or anything said. Opening one shows the
     whole transcript, deleted and edited messages flagged; that read is itself written to
     the audit log (chat.transcript_viewed). Platform and agency admins only. */
  var chatState = { kind: '', q: '', since: '', until: '', offset: 0, limit: 50 };
  function renderChats(container) {
    Dom.clear(container);
    var wrap = Dom.el('div', { class: 'kt-al-wrap', style: 'padding:24px;max-width:1800px;margin:0 auto;' });
    container.appendChild(wrap);
    var hero = Dom.el('div', { class: 'kt-hero', style: 'background:linear-gradient(135deg,#1E1B4B 0%,#5B21B6 60%,#7C3AED 100%);' });
    hero.innerHTML = '<div class="kt-hero-greet">💬 ADMIN</div><h1>Chat conversations</h1>'
      + '<div class="kt-hero-sub">Every conversation in your agency — families with staff, and staff with each other — '
      + 'with the full transcript, including messages that were deleted or edited. Opening a transcript is recorded in the '
      + 'audit log. Times are in your agency timezone (' + auditTz() + ').</div>';
    wrap.appendChild(hero);

    var toolbar = Dom.el('div', { class: 'kt-al-toolbar', style: 'background:white;border-radius:12px;padding:14px;box-shadow:0 1px 3px rgba(0,0,0,.04);margin:16px 0;display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:10px;align-items:end;' });
    var fToggle = filterToggle(toolbar, function () {
      return ['kind', 'q', 'since', 'until'].filter(function (k) { return chatState[k]; }).length;
    });
    wrap.appendChild(fToggle);
    wrap.appendChild(toolbar);
    var listWrap = Dom.el('div', { class: 'kt-al-list', style: 'background:white;border-radius:12px;box-shadow:0 1px 3px rgba(0,0,0,.04);overflow:hidden;' });
    wrap.appendChild(listWrap);
    var pager = Dom.el('div', { class: 'kt-al-pager', style: 'display:flex;align-items:center;justify-content:space-between;gap:10px;flex-wrap:wrap;margin-top:14px;font-size:13px;color:#6B7280;' });
    wrap.appendChild(pager);

    var kindWrap = Dom.el('div');
    kindWrap.appendChild(Dom.el('label', { style: lblStyle() }, 'Type'));
    var kindSel = Dom.el('select', { style: inputStyle() });
    [['', 'All conversations'], ['family', 'Families ↔ staff'], ['staff', 'Staff ↔ staff']].forEach(function (o) {
      var op = Dom.el('option', { value: o[0] }, o[1]); if (o[0] === chatState.kind) op.selected = true; kindSel.appendChild(op);
    });
    kindSel.addEventListener('change', function () { chatState.kind = kindSel.value; chatState.offset = 0; load(); });
    kindWrap.appendChild(kindSel);
    toolbar.appendChild(kindWrap);
    var chatSearch = filterInput('Search', chatState.q, 'text', function (v) { chatState.q = v; chatState.offset = 0; load(); });
    chatSearch.classList.add('kt-al-full');
    toolbar.appendChild(chatSearch);
    toolbar.appendChild(filterInput('Active from', chatState.since, 'date', function (v) { chatState.since = v; chatState.offset = 0; load(); }));
    toolbar.appendChild(filterInput('Started by', chatState.until, 'date', function (v) { chatState.until = v; chatState.offset = 0; load(); }));

    function load() {
      if (fToggle.ktPaint) fToggle.ktPaint();
      var h = listWrap.offsetHeight; if (h) listWrap.style.minHeight = h + 'px';
      Dom.clear(listWrap);
      listWrap.appendChild(Dom.el('div', { style: 'padding:24px;color:#94A3B8;font-size:13px;' }, 'Loading…'));
      var qs = new URLSearchParams();
      ['kind', 'q', 'since', 'until'].forEach(function (k) { if (chatState[k]) qs.set(k, chatState[k]); });
      qs.set('limit', chatState.limit); qs.set('offset', chatState.offset);
      Api.get('/admin/chat-audit?' + qs.toString()).then(function (d) {
        listWrap.style.minHeight = '';
        drawList(d.conversations || [], d.total || 0);
      }).catch(function (e) {
        listWrap.style.minHeight = '';
        Dom.clear(listWrap);
        listWrap.appendChild(Dom.el('div', { style: 'padding:24px;color:#B91C1C;font-size:13px;' }, (e && e.message) || 'Could not load conversations.'));
      });
    }

    function drawList(rows, total) {
      Dom.clear(listWrap); Dom.clear(pager);
      if (!rows.length) {
        listWrap.appendChild(Dom.el('div', { style: 'padding:28px;text-align:center;color:#94A3B8;font-size:13px;' }, 'No conversations match.'));
        return;
      }
      var html = '<div style="overflow-x:auto;"><table data-kt-bulk="off" data-kt-filtered="1" style="width:100%;border-collapse:collapse;font-size:13px;">'
        + '<thead><tr style="background:#F8FAFC;text-align:left;color:#475569;font-size:11.5px;text-transform:uppercase;letter-spacing:.4px;">'
        + '<th style="padding:10px 12px;">Conversation</th><th style="padding:10px 12px;">Type</th><th style="padding:10px 12px;">People</th>'
        + '<th style="padding:10px 12px;text-align:right;">Messages</th><th style="padding:10px 12px;">Last message</th><th style="padding:10px 12px;"></th></tr></thead><tbody>';
      rows.forEach(function (r, i) {
        var type = r.kind === 'family' ? 'Family' : (r.channel === 'group' ? 'Staff group' : 'Staff direct');
        var people = (r.people || []);
        html += '<tr data-i="' + i + '" style="border-top:1px solid #EEF2F6;cursor:pointer;">'
          + '<td style="padding:9px 12px;"><div style="font-weight:700;color:#0F172A;">' + esc(r.title) + (r.closed ? ' <span style="color:#94A3B8;font-weight:600;">(closed)</span>' : '') + '</div>'
          +   '<div style="color:#64748B;font-size:12px;">' + esc([r.centre, r.subject, r.channel === 'sms' ? 'SMS' : ''].filter(Boolean).join(' · ')) + '</div></td>'
          + '<td style="padding:9px 12px;"><span class="kt-pill">' + esc(type) + '</span></td>'
          + '<td style="padding:9px 12px;color:#334155;max-width:360px;">' + esc(people.slice(0, 4).join(', ') + (people.length > 4 ? ' +' + (people.length - 4) + ' more' : '')) + '</td>'
          + '<td style="padding:9px 12px;text-align:right;white-space:nowrap;">' + r.messages + (r.deleted ? ' <span style="color:#B91C1C;font-size:11.5px;">(' + r.deleted + ' deleted)</span>' : '') + '</td>'
          + '<td style="padding:9px 12px;white-space:nowrap;color:#475569;">' + esc(r.last_message_at ? fmtTime(r.last_message_at).replace(/\.\d{3}/, '') : '—') + '</td>'
          + '<td style="padding:9px 12px;text-align:right;"><button type="button" class="kt-btn" data-open="' + i + '" style="padding:4px 10px;font-size:12.5px;">View transcript</button></td>'
          + '</tr>';
      });
      html += '</tbody></table></div>';
      listWrap.innerHTML = html;
      listWrap.querySelectorAll('tr[data-i]').forEach(function (tr) {
        tr.addEventListener('click', function () { openTranscript(rows[+tr.getAttribute('data-i')]); });
      });

      var from = chatState.offset + 1, to = chatState.offset + rows.length;
      pager.appendChild(Dom.el('div', {}, 'Showing ' + from + '–' + to + ' of ' + total));
      var nav = Dom.el('div', { style: 'display:flex;gap:6px;' });
      var prev = Dom.el('button', { type: 'button', class: 'kt-btn' }, '‹ Previous');
      var next = Dom.el('button', { type: 'button', class: 'kt-btn' }, 'Next ›');
      prev.disabled = chatState.offset === 0; next.disabled = to >= total;
      prev.addEventListener('click', function () { chatState.offset = Math.max(0, chatState.offset - chatState.limit); load(); });
      next.addEventListener('click', function () { chatState.offset += chatState.limit; load(); });
      nav.appendChild(prev); nav.appendChild(next); pager.appendChild(nav);
    }

    load();
  }

  function openTranscript(r) {
    var M = window.KT && KT.Shell && KT.Shell.Modal;
    var body = Dom.el('div', { style: 'height:min(62vh,640px);overflow:auto;background:#F8FAFC;border-radius:10px;padding:12px;' });
    body.appendChild(Dom.el('div', { style: 'color:#94A3B8;font-size:13px;padding:12px;' }, 'Loading transcript…'));
    var data = null;
    function asText() {
      if (!data) return '';
      return data.title + '\n' + (data.messages || []).map(function (m) {
        return '[' + fmtTime(m.created_at).replace(/\.\d{3}/, '') + '] ' + m.sender
          + (m.deleted_at ? ' (deleted)' : '') + (m.edited_at ? ' (edited)' : '') + ': ' + m.body
          + (m.attachments && m.attachments.length ? '  [attachments: ' + m.attachments.join(', ') + ']' : '');
      }).join('\n') + '\n';
    }
    if (M) {
      M.open({ title: '💬 ' + r.title, body: body, large: true, actions: [
        { label: '⬇️ Download transcript', handler: function () {
            if (!data) return false;
            var a = document.createElement('a');
            a.href = URL.createObjectURL(new Blob([asText()], { type: 'text/plain;charset=utf-8' }));
            a.download = ('chat-' + r.kind + '-' + r.id + '-' + String(r.title).replace(/[^A-Za-z0-9]+/g, '-')).slice(0, 80) + '.txt';
            document.body.appendChild(a); a.click(); setTimeout(function () { URL.revokeObjectURL(a.href); a.remove(); }, 500);
            return false;
          } },
        { label: 'Close', primary: true },
      ] });
    }
    Api.get('/admin/chat-audit/' + r.kind + '/' + r.id).then(function (d) {
      data = d;
      Dom.clear(body);
      var msgs = d.messages || [];
      if (!msgs.length) { body.appendChild(Dom.el('div', { style: 'color:#94A3B8;font-size:13px;padding:12px;' }, 'No messages in this conversation.')); return; }
      var lastDay = '';
      msgs.forEach(function (m) {
        var when = fmtTime(m.created_at).replace(/\.\d{3}/, '');
        var day = when.replace(/,?\s*\d{1,2}:\d{2}.*$/, '');
        if (day !== lastDay) {
          lastDay = day;
          body.appendChild(Dom.el('div', { style: 'text-align:center;color:#94A3B8;font-size:11.5px;font-weight:700;margin:10px 0 6px;' }, day));
        }
        var tone = m.side === 'family' ? ['#ECFEFF', '#A5F3FC', '#0E7490'] : m.side === 'system' ? ['#F1F5F9', '#E2E8F0', '#64748B'] : ['#F5F3FF', '#DDD6FE', '#6D28D9'];
        var card = Dom.el('div', { style: 'background:' + (m.deleted_at ? '#FEF2F2' : tone[0]) + ';border:1px solid ' + (m.deleted_at ? '#FECACA' : tone[1])
          + ';border-radius:10px;padding:8px 11px;margin:0 0 6px;' });
        card.innerHTML = '<div style="display:flex;gap:8px;align-items:baseline;flex-wrap:wrap;font-size:12px;">'
          + '<strong style="color:' + tone[2] + ';">' + esc(m.sender) + '</strong>'
          + '<span style="color:#94A3B8;">' + esc(m.side === 'family' ? 'Family' : m.side === 'system' ? 'System' : 'Staff') + '</span>'
          + (m.via && m.via !== 'app' && m.via !== 'chat' ? '<span style="color:#94A3B8;">via ' + esc(m.via) + '</span>' : '')
          + '<span style="margin-left:auto;color:#94A3B8;">' + esc(when) + '</span></div>'
          + '<div style="white-space:pre-wrap;overflow-wrap:anywhere;color:#0F172A;font-size:13.5px;margin-top:3px;' + (m.deleted_at ? 'text-decoration:line-through;color:#7F1D1D;' : '') + '">' + esc(m.body || (m.attachments && m.attachments.length ? '' : '(empty)')) + '</div>'
          + (m.attachments && m.attachments.length ? '<div style="font-size:12px;color:#475569;margin-top:4px;">📎 ' + esc(m.attachments.join(', ')) + '</div>' : '')
          + (m.deleted_at || m.edited_at ? '<div style="font-size:11.5px;color:#B91C1C;margin-top:4px;">'
              + (m.deleted_at ? 'Deleted ' + esc(fmtTime(m.deleted_at).replace(/\.\d{3}/, '')) : '')
              + (m.deleted_at && m.edited_at ? ' · ' : '')
              + (m.edited_at ? 'Edited ' + esc(fmtTime(m.edited_at).replace(/\.\d{3}/, '')) : '') + '</div>' : '');
        body.appendChild(card);
      });
    }).catch(function (e) {
      Dom.clear(body);
      body.appendChild(Dom.el('div', { style: 'color:#B91C1C;font-size:13px;padding:12px;' }, (e && e.message) || 'Could not load the transcript.'));
    });
  }

  /* One renderer, two views. The payments channel is the same table with a different
     server-side filter, so the filters, search, pager and CSV export all work there
     without a second implementation to keep in step. */
  function renderAudit(container, opts) {
    opts = opts || {};
    var isPayments = opts.channel === 'payments';
    Dom.clear(container);
    var wrap = Dom.el('div', { class: 'kt-al-wrap', style: 'padding:24px;max-width:1800px;margin:0 auto;' });
    container.appendChild(wrap);

    var hero = Dom.el('div', {
      class: 'kt-hero',
      style: isPayments
        ? 'background:linear-gradient(135deg,#0B2E1F 0%,#116149 60%,#12805F 100%);'
        : 'background:linear-gradient(135deg,#0F172A 0%,#1F6080 60%,#16637A 100%);',
    });
    hero.innerHTML = isPayments
      ? '<div class="kt-hero-greet">💳 ADMIN</div><h1>Payment integrations</h1>'
        + '<div class="kt-hero-sub">Everything that passed between this portal and your payment '
        + 'providers — Zūm Rails and Stripe. Money instructed, settled, refused or refunded, plus '
        + 'the cards and bank accounts behind it. All times in your agency timezone ('
        + auditTz() + ').</div>'
      : '<div class="kt-hero-greet">📜 ADMIN</div><h1>Audit log</h1>'
        + '<div class="kt-hero-sub">Every meaningful action on your agency — who did what, when, and from where. '
        + 'Payment-provider activity has its own tab. '
        + 'All times are shown in your agency timezone (' + auditTz() + ').</div>';
    wrap.appendChild(hero);

    var toolbar = Dom.el('div', { class: 'kt-al-toolbar', style: 'background:white;border-radius:12px;padding:14px;box-shadow:0 1px 3px rgba(0,0,0,.04);margin:16px 0;display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:10px;align-items:end;' });
    var fToggle = filterToggle(toolbar, function () {
      return ['action', 'entity_type', 'q', 'since', 'until'].filter(function (k) { return state.filters[k]; }).length;
    });
    wrap.appendChild(fToggle);
    wrap.appendChild(toolbar);

    var listWrap = Dom.el('div', { class: 'kt-al-list', style: 'background:white;border-radius:12px;box-shadow:0 1px 3px rgba(0,0,0,.04);overflow:hidden;' });
    wrap.appendChild(listWrap);

    var pager = Dom.el('div', { class: 'kt-al-pager', style: 'display:flex;align-items:center;justify-content:space-between;gap:10px;flex-wrap:wrap;margin-top:14px;font-size:13px;color:#6B7280;' });
    wrap.appendChild(pager);

    /* Paging used to throw the reader down the page.
       reload() replaced a 50-row table with a single "Loading…" line, so the document
       collapsed from a few thousand pixels to a few hundred; the browser clamped the
       scroll position to the bottom of that short page, and when the new rows arrived
       the reader was somewhere arbitrary. Holding the old height keeps the scrollbar
       still across the gap. (Anthony, 2026-08-26) */
    function holdHeight() {
      try {
        var h = listWrap.getBoundingClientRect().height;
        if (h > 0) { listWrap.style.minHeight = h + 'px'; }
      } catch (e) {}
    }
    function releaseHeight() {
      try { listWrap.style.minHeight = ''; } catch (e) {}
    }
    /* Set by the pager and the filters: after THOSE reloads the reader wants the first
       row of the new page, not wherever they happened to be standing when they clicked
       Next — the pager sits below the table, so without this you land mid-way down a
       page you have not read yet. A background refresh leaves the position alone. */
    var jumpToTop = false;
    function landOnTable() {
      if (!jumpToTop) { return; }
      jumpToTop = false;
      // KT.scroll: on a phone #appMain scrolls, and window.scrollTo did nothing there.
      try {
        if (window.KT && KT.scroll) { KT.scroll.toEl(listWrap, 90); return; }
        var top = listWrap.getBoundingClientRect().top + (window.scrollY || window.pageYOffset || 0);
        window.scrollTo({ top: Math.max(0, top - 90), behavior: 'smooth' });
      } catch (e) {}
    }

    function reload() {
      holdHeight();
      if (fToggle.ktPaint) fToggle.ktPaint();
      Dom.clear(listWrap);
      listWrap.appendChild(Dom.el('div', { style: 'padding:32px;text-align:center;color:#64748B;font-size:13px;' }, 'Loading audit log…'));

      var qs = new URLSearchParams();
      Object.keys(state.filters).forEach(function (k) { if (state.filters[k]) qs.set(k, state.filters[k]); });
      qs.set('limit', String(state.limit));
      qs.set('offset', String(state.offset));
      // The server owns the split; this only says which half is wanted.
      if (isPayments) { qs.set('channel', 'payments'); }

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
            refreshSelects();
          }
        }
        Dom.clear(listWrap);
        if (!data.logs || !data.logs.length) {
          listWrap.appendChild(Dom.el('div', { style: 'padding:48px;text-align:center;color:#6B7280;' }, 'No events match the current filters.'));
        } else {
          listWrap.appendChild(renderTable(data.logs));
        }
        // The rows are on screen by now; a pager fault must never take them back off
        // (the .catch below clears listWrap). A tab mid-update can hold this file with
        // an older kt-card-pager.js that has no KT.pagerBar.
        try { buildPager(); } catch (e) { try { console.warn('[audit-logs] pager', e); } catch (e2) {} }
        releaseHeight();
        landOnTable();
      }).catch(function (e) {
        Dom.clear(listWrap);
        listWrap.appendChild(Dom.el('div', { style: 'padding:24px;color:#DC2626;' }, 'Could not load: ' + (e.message || 'error')));
        releaseHeight();
      });
    }

    /* THE LISTS ARRIVE AFTER THE TOOLBAR IS ON SCREEN (2026-10-09). This used to call
       buildToolbar() again, which replaced EVERY control, the search box included. On a
       phone, tapping Search and typing while that first response was still on its way
       lost the box, the keyboard and what had been typed (Anthony: "I cannot seem to
       search in mobile apk view with audit logs"). Only the two dropdowns change, so only
       their options are rebuilt, and the search box is never replaced. */
    function refreshSelects() {
      [['action', 'Action', state.actions], ['entity', 'Entity', state.entities]].forEach(function (d) {
        var sel = toolbar.querySelector('select[data-kt-filter="' + d[0] + '"]');
        if (!sel) { buildToolbar(); return; }
        var cur = d[0] === 'action' ? state.filters.action : state.filters.entity_type;
        while (sel.firstChild) sel.removeChild(sel.firstChild);
        [''].concat(d[2]).forEach(function (o) {
          var opt = Dom.el('option', { value: o }, o ? (d[1] === 'Action' ? actionLabel(o) : o) : 'All');
          if (o === cur) opt.selected = true;
          sel.appendChild(opt);
        });
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
      var searchBox = filterInput('Search', state.filters.q, 'text', function (v) {
        state.filters.q = v; state.offset = 0; reload();
      });
      searchBox.classList.add('kt-al-full');
      toolbar.appendChild(searchBox);

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
      var resetWrap = Dom.el('div', { class: 'kt-al-full', style: 'display:flex;align-items:flex-end;gap:6px;' });
      var resetBtn = Dom.el('button', { style: 'background:white;border:1px solid #D1D5DB;color:#374151;padding:8px 12px;border-radius:8px;font-weight:600;cursor:pointer;font-size:13px;flex:1;' }, 'Reset');
      resetBtn.addEventListener('click', function () {
        state.filters = { entity_type: '', action: '', q: '', since: '', until: '' };
        state.offset = 0;
        buildToolbar(); reload();
      });
      resetWrap.appendChild(resetBtn);

      /* The periodic refresh now holds off while somebody is scrolled into the log —
         a rebuild every 45 seconds was taking their place away, and new entries
         arrive at the top where a reader deep in the page cannot see them anyway. So
         give them the button instead: it reloads the table in place, without the
         screen teardown, and keeps the scroll position exactly where it is. */
      var refreshBtn = Dom.el('button', {
        style: 'background:white;border:1px solid #D1D5DB;color:#374151;padding:8px 12px;'
          + 'border-radius:8px;font-weight:600;cursor:pointer;font-size:13px;',
        title: 'Reload the entries without losing your place',
      }, '↻');
      refreshBtn.addEventListener('click', function () {
        refreshBtn.disabled = true;
        var was = refreshBtn.textContent;
        refreshBtn.textContent = '…';
        state.offset = 0;
        reload();
        setTimeout(function () { refreshBtn.disabled = false; refreshBtn.textContent = was; }, 900);
      });
      resetWrap.appendChild(refreshBtn);

      // v22p46: CSV download — same filters apply to the export
      var csvBtn = Dom.el('button', { style: 'background:white;border:1px solid #16A34A;color:#16A34A;padding:8px 12px;border-radius:8px;font-weight:600;cursor:pointer;font-size:13px;', title: 'Export filtered audit log to CSV' }, '⤓ CSV');
      csvBtn.addEventListener('click', function () {
        var qs = new URLSearchParams();
        Object.keys(state.filters).forEach(function (k) { if (state.filters[k]) qs.set(k, state.filters[k]); });
        qs.set('format', 'csv');
        if (isPayments) { qs.set('channel', 'payments'); }
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
      // The portal's one numbered pager (KT.pagerBar), not a bespoke ◀ Prev / Next ▶ pair.
      var nav = Dom.el('div', {});
      pager.appendChild(nav);
      var lim = state.limit || 50;
      if (window.KT && KT.pagerBar) KT.pagerBar(nav, Math.floor(state.offset / lim) + 1, Math.ceil(state.total / lim), function (p) {
        state.offset = (p - 1) * lim; jumpToTop = true; reload();
      });
    }

    buildToolbar();
    reload();
  }

  function renderTable(logs) {
    // This screen has its OWN server-side search + pagination (the toolbar filters
    // and the numbered pager below, backed by /admin/audit-logs). Pre-set
    // kt-table-filter's dedup flag so its GLOBAL auto search+pager does NOT also
    // attach — otherwise it client-paginated the 50 rows we load per server page
    // into 25-per-page chunks, producing a SECOND Prev/Next and capping the view
    // at "2 pages" no matter how many pages the server actually has.
    var t = Dom.el('table', { 'data-kt-filtered': '1', 'data-kt-bulk': 'off', style: 'width:100%;border-collapse:collapse;font-size:13px;' });
    var thead = Dom.el('thead', { style: 'background:#FAFBFC;' });
    var hr = Dom.el('tr');
    ['When', 'Action', 'Entity', 'Actor', 'Location'].forEach(function (h) {
      hr.appendChild(Dom.el('th', { style: 'text-align:left;padding:10px 14px;font-size:11px;font-weight:700;color:#6B7280;letter-spacing:1px;text-transform:uppercase;border-bottom:1px solid #E5E7EB;' }, h));
    });
    thead.appendChild(hr);
    t.appendChild(thead);

    var tb = Dom.el('tbody');
    /* A CELL CAP ON A td DOES NOTHING (2026-09-18).

       Anthony: "the audit table where the entity column is now much wider and has
       changed as to how it was prior."

       The Action cell already carried max-width:360px and had since it was written. It
       never worked: this table is table-layout:auto, and the auto algorithm sizes each
       column from its CONTENT - a max-width on the td itself is advisory and the browser
       ignores it. Measured live: that cell was 468px wide inside a 1585px table.

       Nothing changed in this file. What changed is the DATA. The Action column prints
       the payload's ready-made `summary` verbatim, uncapped, and yesterday's billing work
       wrote the three longest summaries the log has ever held - 452, 414 and 215
       characters (invoice.renumbered lists every invoice it renumbered, by number). 49 of
       716 summary rows are over 90 characters, and the longest are the newest, so they
       all land on page one. One row stretched the column and squeezed every other.

       The constraint has to go on a BLOCK INSIDE the cell, where max-width is honoured,
       and the text is clamped to two lines. Nothing is lost: clicking the row opens the
       detail modal, which shows the full Details line and the raw payload, and the full
       text is on the title attribute for a hover. */
    var clamp = function (text, lines, width) {
      return Dom.el('div', {
        style: 'max-width:' + width + 'px;display:-webkit-box;-webkit-line-clamp:' + lines + ';'
             + '-webkit-box-orient:vertical;overflow:hidden;word-break:break-word;',
        title: text,
      }, text);
    };

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
      var actCell = Dom.el('td', { class: 'kt-mcard-block', style: 'padding:10px 14px;vertical-align:top;max-width:360px;' });
      var actLine = Dom.el('div', {});
      actLine.appendChild(Dom.el('span', { style: 'font-size:16px;margin-right:6px;' }, iconFor(l.action)));
      // Show the clean label, and keep the raw action in the tooltip for anyone
      // who needs the exact event name (support, an auditor, a bug report).
      var lbl = Dom.el('span', {
        style: 'font-weight:600;color:' + (/\[fail\]/.test(l.action || '') ? '#B91C1C' : '#1F6080') + ';',
        title: l.action || '',
      }, rowActionLabel(l));
      actLine.appendChild(lbl);
      actLine.style.cssText = 'max-width:360px;word-break:break-word;';
      actCell.appendChild(actLine);
      if (l.summary) {
        var sm = clamp(l.summary, 2, 360);
        sm.style.cssText += 'font-size:11px;color:#6B7280;margin-top:3px;line-height:1.45;';
        actCell.appendChild(sm);
      }
      row.appendChild(actCell);

      // Entity — the resolved NAME with a plain-English type label; no raw ids.
      var entCell = Dom.el('td', { style: 'padding:10px 14px;color:#374151;vertical-align:top;' });
      if (l.entity_name) {
        var en = clamp(l.entity_name, 2, 220);
        en.style.cssText += 'font-weight:600;color:#111827;';
        entCell.appendChild(en);
        if (l.entity_type) entCell.appendChild(Dom.el('div', { style: 'font-size:11px;color:#64748B;' }, prettyEntityType(l.entity_type)));
      } else {
        entCell.appendChild(Dom.el('div', { style: 'color:#374151;' }, l.entity_type ? prettyEntityType(l.entity_type) : '—'));
      }
      row.appendChild(entCell);

      // Actor
      var actorCell = Dom.el('td', { style: 'padding:10px 14px;vertical-align:top;' });
      actorCell.appendChild(Dom.el('div', { style: 'font-weight:600;color:#111827;' }, l.actor_name || 'system'));
      if (l.actor_email) {
        var ae = clamp(l.actor_email, 1, 220);
        ae.style.cssText += 'font-size:11px;color:#64748B;';
        actorCell.appendChild(ae);
      }
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
    var sel = Dom.el('select', { style: inputStyle(), 'data-kt-filter': label === 'Action' ? 'action' : (label === 'Entity' ? 'entity' : '') });
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
    var isText = type === 'text';
    /* A phone keyboard (2026-10-09): type=search + enterkeyhint gives Android's keyboard a
       Search key; that key, Enter, the end of an IME composition and a change on Done all
       search at once, as well as the 320ms typing debounce. Whichever a keyboard sends,
       the term reaches the server, once (the same term is never fetched twice in a row). */
    var input = Dom.el('input', isText
      ? { type: 'search', enterkeyhint: 'search', autocomplete: 'off', autocapitalize: 'off', spellcheck: 'false', value: value || '', style: inputStyle() }
      : { type: type, value: value && type === 'date' ? value.slice(0, 10) : value, style: inputStyle() });
    var deb, last = value || '';
    function fire() {
      clearTimeout(deb);
      if (isText && input.value === last) return;
      last = input.value;
      onChange(input.value);
    }
    if (isText) {
      input.addEventListener('input', function () { clearTimeout(deb); deb = setTimeout(fire, 320); });
      input.addEventListener('compositionend', function () { clearTimeout(deb); deb = setTimeout(fire, 320); });
      input.addEventListener('change', fire);
      input.addEventListener('search', fire);   // the clear (x) on a search box
      input.addEventListener('keydown', function (e) {
        if (e.key === 'Enter' || e.keyCode === 13) { e.preventDefault(); fire(); try { input.blur(); } catch (er) {} }
      });
    } else {
      input.addEventListener('change', function () { clearTimeout(deb); deb = setTimeout(fire, 0); });
    }
    wrap.appendChild(input);
    return wrap;
  }
  function lblStyle() { return 'display:block;font-size:11px;font-weight:700;color:#6B7280;letter-spacing:0.5px;text-transform:uppercase;margin-bottom:4px;'; }
  function inputStyle() { return 'width:100%;padding:7px 10px;border:1px solid #D1D5DB;border-radius:8px;font-size:13px;background:white;box-sizing:border-box;'; }

  // ─── Shell registration ────────────────────────────────────────────
  if (Shell && Shell.registerScreen) {
    Shell.registerScreen('agency_admin:audit-logs',   render);
    Shell.registerScreen('platform_admin:audit-logs', render);
    Shell.registerScreen('centre_director:audit-logs', render);
  }
  KT.AuditLogs = { render: render };
})(window);
