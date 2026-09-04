/* ═══════════════════════════════════════════════════════════════════
   KiddieTrac — load a role's screens, and nobody else's.

   Every screen module used to be a <script> tag in dashboard.html, so a parent
   downloaded 1.78 MB of screens they can never open — the whole admin console, the
   platform tools, the sales pipeline — before their own Today page appeared.

   The 51 files below register NO guardian screen, so a parent never needs one. They
   are injected after sign-in for the roles that do use them, and the shell awaits
   ensure() before it resolves a screen, so a page is never "not available" merely
   because its file had not arrived.

   FAILS SLOW, NOT BROKEN. If the manifest is stale, a hash is unknown, or a file
   404s, ensure() still resolves and the shell falls through to exactly the behaviour
   it had before this existed. A wrong entry here costs a render, not a screen.

   The manifest is GENERATED from registerScreen() calls in the source — do not hand
   -edit it. Regenerate when a screen changes roles.
   ═══════════════════════════════════════════════════════════════════ */
(function (w) {
  'use strict';
  var KT = (w.KT = w.KT || {});
  if (KT.screenLoader) { return; }

  var MANIFEST = {"/js/fix-screen-v22p69.js":{"hashes":["system-status"],"roles":["agency_admin","centre_director","platform_admin"]},"/js/screen-admin-children.js":{"hashes":["admin-children"],"roles":["agency_admin"]},"/js/screen-admin.js":{"hashes":["admin","admin-"],"roles":["agency_admin"]},"/js/screen-agency-admin.js":{"hashes":["centres","dashboard"],"roles":["agency_admin"]},"/js/screen-audit-logs.js":{"hashes":["audit-logs"],"roles":["agency_admin","centre_director","platform_admin"]},"/js/screen-auto-signoff.js":{"hashes":["auto-signoff"],"roles":["agency_admin","centre_director","platform_admin"]},"/js/screen-billing-settings.js":{"hashes":["billing-settings"],"roles":["agency_admin","platform_admin"]},"/js/screen-billing-setup.js":{"hashes":["billing-setup"],"roles":["agency_admin","centre_director","platform_admin"]},"/js/screen-birthday-settings.js":{"hashes":["birthday-settings"],"roles":["agency_admin","platform_admin"]},"/js/screen-bulk-invoices.js":{"hashes":["bulk-invoices"],"roles":["agency_admin","platform_admin"]},"/js/screen-calendar-settings.js":{"hashes":["calendar-settings"],"roles":["agency_admin","centre_director","platform_admin"]},"/js/screen-child-detail.js":{"hashes":["child-detail"],"roles":["agency_admin","centre_director"]},"/js/screen-clock-settings.js":{"hashes":["clock-settings"],"roles":["agency_admin","centre_director","platform_admin"]},"/js/screen-compliance.js":{"hashes":["compliance"],"roles":["agency_admin","centre_director","platform_admin"]},"/js/screen-data-retention.js":{"hashes":["data-retention"],"roles":["agency_admin","platform_admin"]},"/js/screen-digest-status.js":{"hashes":["digest-status"],"roles":["agency_admin","centre_director"]},"/js/screen-document-templates.js":{"hashes":["document-templates"],"roles":["agency_admin","platform_admin"]},"/js/screen-edocuments.js":{"hashes":["edocuments"],"roles":["agency_admin","centre_director","guardian"]},"/js/screen-educator-me.js":{"hashes":["child-record","children","my-hours","my-schedule"],"roles":["educator","home_visitor"]},"/js/screen-educator-rooms.js":{"hashes":["educator-rooms"],"roles":["agency_admin","centre_director","platform_admin"]},"/js/screen-email-template.js":{"hashes":["email-templates"],"roles":["agency_admin","platform_admin"]},"/js/screen-email.js":{"hashes":["email"],"roles":["agency_admin","centre_director","educator","platform_admin"]},"/js/screen-account-ledgers.js":{"hashes":["account-ledgers"],"roles":["agency_admin","platform_admin"]},"/js/screen-expenses.js":{"hashes":["expenses"],"roles":["agency_admin","centre_director","platform_admin"]},"/js/screen-external-billing.js":{"hashes":["external-billing"],"roles":["agency_admin","centre_director","platform_admin"]},"/js/screen-external-waitlist.js":{"hashes":["synced-waitlist"],"roles":["agency_admin","centre_director","platform_admin"]},"/js/screen-fee-plans.js":{"hashes":["tuition-plans"],"roles":["agency_admin","centre_director","platform_admin"]},"/js/screen-forms.js":{"hashes":["admin-forms"],"roles":["agency_admin","centre_director","platform_admin"]},"/js/screen-hcc-forms.js":{"hashes":["hcc-form-view","hcc-forms","inspection-form","inspection-forms"],"roles":["agency_admin","centre_director","home_visitor","platform_admin"]},"/js/screen-home-visitor.js":{"hashes":["edit-home-visit","home","home-visit-reports","home-visit-view","home-visits","new-home-visit"],"roles":["agency_admin","centre_director","home_visitor","platform_admin"]},"/js/screen-immunizations.js":{"hashes":["immunizations"],"roles":["agency_admin","centre_director","educator","guardian"]},"/js/screen-integrations-v22p72.js":{"hashes":["email-settings","quickbooks"],"roles":["agency_admin","centre_director","platform_admin"]},"/js/screen-invitation-codes.js":{"hashes":["invitation-codes"],"roles":["agency_admin","centre_director"]},"/js/screen-knowledge-base.js":{"hashes":["knowledge-base"],"roles":["agency_admin","auditor","centre_director","educator","guardian","home_visitor","platform_admin","sales_rep"]},"/js/screen-late-events.js":{"hashes":["late-events"],"roles":["agency_admin","centre_director","platform_admin"]},"/js/screen-lesson-plans-ai.js":{"hashes":["lesson-plan-new","lesson-plans-ai"],"roles":["agency_admin","centre_director"]},"/js/screen-mail-settings.js":{"hashes":["mail-settings"],"roles":["platform_admin"]},"/js/screen-marketing-site.js":{"hashes":["marketing-site"],"roles":["agency_admin","platform_admin"]},"/js/screen-marketing.js":{"hashes":["marketing-campaigns"],"roles":["agency_admin","centre_director","platform_admin"]},"/js/screen-medications.js":{"hashes":["medications"],"roles":["agency_admin","centre_director","educator","guardian"]},"/js/screen-my-pay.js":{"hashes":["my-hours","my-pay"],"roles":["centre_director","educator","home_visitor"]},"/js/screen-observations.js":{"hashes":["observation-new","observations"],"roles":["Educator","agency_admin","centre_director","educator"]},"/js/screen-parent-feedback.js":{"hashes":["parent-feedback"],"roles":["educator","home_visitor"]},"/js/screen-payment-providers.js":{"hashes":["payment-providers"],"roles":["agency_admin","platform_admin"]},"/js/screen-payroll-settings.js":{"hashes":["payroll-settings"],"roles":["agency_admin","platform_admin"]},"/js/screen-platform-invoices.js":{"hashes":["sales-invoices"],"roles":["platform_admin"]},"/js/screen-platform.js":{"hashes":["maintenance","platform-agencies","platform-overview"],"roles":["agency_admin","centre_director","platform_admin"]},"/js/screen-provider-day.js":{"hashes":["provider-day"],"roles":["agency_admin","centre_director","platform_admin"]},"/js/screen-reports.js":{"hashes":["reports"],"roles":["agency_admin","auditor","centre_director","educator","platform_admin"]},"/js/screen-roles.js":{"hashes":["admin-roles"],"roles":["agency_admin"]},"/js/screen-sales.js":{"hashes":["home","sales","sales-chat","sales-demo","sales-followups","sales-lead","sales-leads","sales-new","sales-plans"],"roles":["agency_admin","platform_admin","sales_rep"]},"/js/screen-security-alerts.js":{"hashes":["security-alerts"],"roles":["agency_admin","platform_admin"]},"/js/screen-settings.js":{"hashes":["settings"],"roles":["agency_admin","centre_director","educator","guardian","home_visitor","platform_admin","sales_rep"]},"/js/screen-sibling-discounts.js":{"hashes":["sibling-discounts"],"roles":["agency_admin"]},"/js/screen-social-settings.js":{"hashes":["social-settings"],"roles":["agency_admin","platform_admin"]},"/js/screen-staff-calendar.js":{"hashes":["calendar","staff-calendar"],"roles":["agency_admin","centre_director"]},"/js/screen-support.js":{"hashes":["support"],"roles":["educator","guardian"]},"/js/screen-team-chat.js":{"hashes":["team-messages"],"roles":["agency_admin","auditor","centre_director","educator","home_visitor","platform_admin"]},"/js/screen-withdrawals.js":{"hashes":["withdraw","withdrawals"],"roles":["agency_admin","centre_director","guardian","platform_admin"]}};

  var loaded = {};       // src -> Promise
  var roleDone = {};     // role -> Promise

  function inject(src) {
    if (loaded[src]) { return loaded[src]; }
    loaded[src] = new Promise(function (resolve) {
      var s = document.createElement('script');
      s.src = src + '?v=' + (w.KT_VERSION || 'rl1');
      s.async = false;                 // keep execution order predictable
      /* Resolve on error too. A missing file must not wedge navigation — the shell
         then behaves exactly as it did when the tag was static and 404'd. */
      s.onload = function () { resolve(true); };
      s.onerror = function () { resolve(false); };
      (document.body || document.documentElement).appendChild(s);
    });
    return loaded[src];
  }

  function filesForRole(role) {
    var out = [];
    for (var src in MANIFEST) {
      if (MANIFEST[src].roles.indexOf(role) !== -1) { out.push(src); }
    }
    return out;
  }

  function filesForHash(hash) {
    var out = [];
    for (var src in MANIFEST) {
      if (MANIFEST[src].hashes.indexOf(hash) !== -1) { out.push(src); }
    }
    return out;
  }

  /**
   * Make sure this role's screens are present — and, if a specific hash is named,
   * whatever registers it even when the role list says otherwise.
   */
  function ensure(role, hash) {
    var jobs = [];
    if (role) {
      if (!roleDone[role]) {
        roleDone[role] = Promise.all(filesForRole(role).map(inject));
      }
      jobs.push(roleDone[role]);
    }
    if (hash) {
      filesForHash(hash).forEach(function (src) { jobs.push(inject(src)); });
    }
    return Promise.all(jobs).then(function () { return true; },
                                  function () { return true; });
  }

  KT.screenLoader = {
    ensure: ensure,
    /* For diagnostics: what this role skips. */
    stats: function (role) {
      var mine = filesForRole(role).length;
      var all = Object.keys(MANIFEST).length;
      return { total: all, forThisRole: mine, skipped: all - mine };
    },
  };
})(window);
