/* ═══════════════════════════════════════════════════════════════════
   KIDDIETRAC v2 — App Shell
   Common router, modal system, role detection
   ═══════════════════════════════════════════════════════════════════ */

(function (window) {
  'use strict';

  // ─── v17.3: Suppress legacy nav-additions IMMEDIATELY ─────────
  // The v12/v13/v14/v15 nav-additions files run on DOMContentLoaded
  // and inject their own <div id="kt-v##-nav-extra"> elements as
  // siblings of #navLinks. If they have the guard we shipped in v17
  // (an early-return checking this flag), they bail. Setting the flag
  // at IIFE-load time guarantees the guard sees it set regardless of
  // script execution order.
  window.KT_V17_NAV_INSTALLED = true;

  const { Auth, Api, Fmt, Dom } = window.KT;

  // ──────────────── Role detection ─────────────────────────────
  const Roles = {
    primaryRoleOf(user) {
      if (!user || !user.roles) return null;
      var _va = null; try { _va = sessionStorage.getItem('kt_view_as'); } catch (e) {}
      if (_va && user.roles.indexOf('platform_admin') !== -1 && ['agency_admin','centre_director','educator','guardian','auditor','home_visitor','sales_rep'].indexOf(_va) !== -1) return _va;
      // v22p39: platform_admin gets routed through the agency_admin shell
      // so a user who holds ONLY the platform role still gets a nav and
      // dashboard rendered. Previously primary_role came back as null and
      // the shell hung on 'Loading your workspace…' indefinitely. The
      // platform-overview + all-agencies links are injected separately
      // by agency-switcher.js, so nothing is lost.
      if (user.roles.includes('agency_admin'))    return 'agency_admin';
      if (user.roles.includes('platform_admin'))  return 'agency_admin';
      if (user.roles.includes('centre_director')) return 'centre_director';
      if (user.roles.includes('educator'))        return 'educator';
      if (user.roles.includes('guardian'))        return 'guardian';
      if (user.roles.includes('home_visitor'))    return 'home_visitor';
      if (user.roles.includes('sales_rep'))       return 'sales_rep';
      if (user.roles.includes('auditor'))         return 'auditor';
      return null;
    },

    isStaff(user) {
      const r = this.primaryRoleOf(user);
      return r === 'agency_admin' || r === 'centre_director' || r === 'educator';
    },

    isDirector(user) {
      const r = this.primaryRoleOf(user);
      return r === 'agency_admin' || r === 'centre_director';
    },

    isEducator(user) {
      return this.primaryRoleOf(user) === 'educator';
    },

    isGuardian(user) {
      return this.primaryRoleOf(user) === 'guardian';
    },
  };

  // ──────────────── Nav builder (v17 — grouped sidebar) ────────
  // Each section: { label, items: [{ hash, label, icon, badgeKey? }] }
  function navItemsForRole(role) {
    if (role === 'agency_admin') {
      // v22p34: hide legacy 'Agencies' entry for callers who are ALSO platform_admin
      // — they already get a richer 'All agencies' link from agency-switcher.js,
      // and the legacy '/admin/agencies' route shows essentially the same data
      // with fewer SaaS-level controls (no suspend/resume, no white-label edit).
      var u_v22p34 = (function () { try { return JSON.parse(sessionStorage.getItem('kt_user') || '{}'); } catch (e) { return {}; } })();
      // v22p98: platform-only sections (Reseller, Website) must appear ONLY in the
      // real "Super admin (default)" view — NOT while previewing as agency admin.
      // The account holds both roles, so gate on the view-as selection too.
      var viewAs_v22p98 = (function () { try { return sessionStorage.getItem('kt_view_as') || ''; } catch (e) { return ''; } })();
      var isPlatformAdmin_v22p34 = Array.isArray(u_v22p34.roles) && u_v22p34.roles.indexOf('platform_admin') !== -1
        && (viewAs_v22p98 === '' || viewAs_v22p98 === 'platform_admin');
      // Home-visitor inspection forms are limited to iLearn (2) + Test Agency (6).
      // Fail-open when the active agency is unknown; the screen itself re-checks server-side.
      var _hccAg_v = parseInt(sessionStorage.getItem('kt_active_agency_id') || localStorage.getItem('kt_active_agency_id') || '0', 10);
      var hccEnabled_v = (!_hccAg_v) || _hccAg_v === 2 || _hccAg_v === 6 || isPlatformAdmin_v22p34;
      var overviewItems = [{ hash: 'dashboard', label: 'Agency overview', icon: '🏠' }, { hash: 'admin-centres', label: 'Centres', icon: '🏫' }, { hash: 'provider-day', label: 'Daily Overview', icon: '🗓️' }, { hash: 'provider-map', label: 'Provider map', icon: '🗺️' }];
      if (!isPlatformAdmin_v22p34) overviewItems.push({ hash: 'agencies', label: 'Agencies', icon: '🏢' });
      return [
        { label: 'Overview', items: overviewItems },
        { label: 'Operations', items: [
          { hash: 'notifications', label: 'Notifications', icon: '🔔' },
          { hash: 'staff-calendar', label: 'Calendar',        icon: '📅' },
          { hash: 'care-log',       label: 'Daily log',        icon: '📝' },

          { hash: 'chat',           label: 'Messenger',         icon: '💬', badgeKey: 'chat_unread' },
          { hash: 'email',          label: 'Email Client',     icon: '✉️' },
          { hash: 'announcements',  label: 'Announcements',    icon: '📢', badgeKey: 'announcement_unread' },
          { hash: 'lesson-plans',   label: 'Lesson plans',     icon: '📚' },
          { hash: 'observations',   label: 'Observations',     icon: '\ud83d\udc40' },
          { hash: 'awards',         label: 'Awards',           icon: '\ud83c\udfc6' },
          { hash: 'schedule',       label: 'Schedule',         icon: '📅' },
          { hash: 'certifications', label: 'Certifications',   icon: '🎓' },
          { hash: 'timesheets',     label: 'Timesheets',       icon: '📊' },
          { hash: 'incidents',     label: 'Incidents',        icon: '⚠️' },
          { hash: 'withdrawals',   label: 'Withdrawals',      icon: '🚸' },
          { hash: 'medications',   label: 'Medications',      icon: '💊' },
          { hash: 'immunizations', label: 'Immunizations',    icon: '🩹' },
        ]},
        { label: 'Growth', items: [
          { hash: 'marketing-campaigns', label: 'Marketing',   icon: '📣' },
          { hash: 'tours',               label: 'Tours',       icon: '🚪' },
          { hash: 'sms',                 label: 'SMS broadcast', icon: '📱' },
          { hash: 'ai-churn',            label: 'Churn risk',  icon: '📉' },
          { hash: 'ai-docs',             label: 'AI doc extract', icon: '🪄' },
          { hash: 'forecast',            label: 'Enrolment forecast', icon: '📈' },
        ]},
        { label: 'Programs', items: [
          { hash: 'menu',                label: 'Weekly menu',     icon: '🍽' },
          { hash: 'field-trips',         label: 'Field trips',     icon: '🚐' },
          { hash: 'allergy-alerts',      label: 'Allergy alerts',  icon: '⚠' },
        ]},
        { label: 'Staff', items: [
          { hash: 'tasks',              label: 'Tasks',              icon: '📋' },
          { hash: 'time-off',           label: 'Time off requests',  icon: '🌴' },
          { hash: 'background-checks',  label: 'Background checks',  icon: '🛡' },
          { hash: 'substitutes',        label: 'Substitutes',        icon: '🔄' },
        ]},
        // Home Visitors — own section (moved out of Compliance for clarity).
        { label: 'Home Visitors', items: [
          { hash: 'home-visit-reports', label: 'Home visit reports', icon: '📋' },
          ...(hccEnabled_v ? [{ hash: 'hcc-forms', label: 'Inspection forms', icon: '🏡' }] : []),
        ]},
        { label: 'Compliance', items: [
          { hash: 'inspection',         label: 'Inspection checklist', icon: '✅' },
          { hash: 'renewals',           label: 'Renewals calendar',    icon: '📅' },
          { hash: 'cwelcc',             label: 'CWELCC subsidies',    icon: '💵' },
          { hash: 'retention',          label: 'Retention',          icon: '📊' },
          { hash: 'anomalies',          label: 'Anomalies',          icon: '🔍' },
        ]},
        // v22p98: the Reseller section (MRR, feature flags, branding, platform
        // tooling) is super-admin only — hide it from tenant agency admins.
        ...(isPlatformAdmin_v22p34 ? [{ label: 'Reseller', items: [
          { hash: 'admin-mrr',      label: 'MRR dashboard',    icon: '💰' },
          /* Beside MRR: the same money from the other side — MRR is what is owed in
             aggregate, this is the individual invoices behind it. Reseller because that
             is a platform_admin section, and the API is guarded role:platform_admin. */
          { hash: 'sales-invoices', label: 'Invoices',         icon: '🧾' },
          { hash: 'admin-features', label: 'Feature flags',    icon: '⚙️' },
          { hash: 'admin-branding', label: 'Branding',         icon: '🎨' },
          { hash: 'digest-status', label: 'AI digest status', icon: '🤖' },
          { hash: 'maintenance',   label: 'Maintenance',      icon: '🛠️' },
          { hash: 'mail-settings', label: 'Email',            icon: '✉️' },
        ]}] : []),
        ...(isPlatformAdmin_v22p34 ? [{ label: 'Website', items: [{ hash: 'marketing-site', label: 'Website', icon: '🌐' }] }] : []),
        { label: 'Enrollment', items: [
          { hash: 'invitation-codes', label: 'Invitation codes', icon: '✉️' },
          { hash: 'edocuments',       label: 'eDocuments',       icon: '📄' },
          { hash: 'synced-waitlist',  label: 'Waitlist',          icon: '⏳' },
        ]},
        { label: 'Finance', items: [
          { hash: 'late-events',      label: 'Late pick-ups',    icon: '⏰' },
          { hash: 'bulk-invoices',    label: 'Bulk invoice run', icon: '💸' },
          { hash: 'billing-schedule', label: 'Billing schedule', icon: '📅' },
          { hash: 'payment-plans',    label: 'Payment plans',   icon: '📅' },
          { hash: 'refunds',          label: 'Refunds',          icon: '↩' },
          { hash: 'tuition-increases',label: 'Tuition increases',icon: '📈' },
          { hash: 'expenses',         label: 'Expenses',         icon: '🧾' },
          { hash: 'external-billing', label: 'Accounting',        icon: '🧾' },
          { hash: 'payroll',            label: 'Payroll',            icon: '💼' },
        ]},
        { label: 'Administration', items: [
          { hash: 'admin-users',      label: 'User management',  icon: '👥' },
          { hash: 'admin-families',   label: 'Families',         icon: '👪' },
          { hash: 'admin-children',   label: 'Children',         icon: '🧒' },
          { hash: 'admin-forms',      label: 'Custom forms',     icon: '📝' },
          { hash: 'compliance',       label: 'Compliance',       icon: '✅' },
          { hash: 'audit-logs',       label: 'Audit log',        icon: '📜' },
          { hash: 'closures',         label: 'Closures',         icon: '🗓' },
          { hash: 'late-pickups',     label: 'Late pickups',     icon: '⏰' },
          { hash: 'room-ratios',      label: 'Room ratios',      icon: '👥' },
          { hash: 'vacation-holds',   label: 'Vacation holds',   icon: '🏖' },
          { hash: 'bus-routes',       label: 'Bus routes',       icon: '🚐' },
          { hash: 'room-rotations',   label: 'Room rotations',   icon: '🔄' },
          { hash: 'reports',          label: 'Reports',          icon: '📋' },
          { hash: 'immun-schedule',   label: 'Immunization due', icon: '💉' },
          { hash: 'cacfp',            label: 'CACFP meals',      icon: '🍽' },
          { hash: 'report-cards',     label: 'Report cards',    icon: '📑' },
          { hash: 'zones',            label: 'Activity zones',  icon: '🎨' },
          { hash: 'tickets',          label: 'Support tickets', icon: '🎫' },
          { hash: 'attendance-pattern',label: 'Attendance days', icon: '📅' },
          { hash: 'trip-gps',         label: 'Field trip GPS',  icon: '📍' },
          { hash: 'wellness-digest',  label: 'Wellness digest', icon: '🩺' },
          { hash: 'doc-workflows',    label: 'Doc workflows',   icon: '📜' },
        ]},
        { label: 'Engagement', items: [
          { hash: 'reenrollment',     label: 'Re-enrollment',  icon: '🔁' },
          { hash: 'engagement',       label: 'Engagement score', icon: '💚' },
          { hash: 'nps',              label: 'NPS', icon: '📊' },
          { hash: 'signed-docs',      label: 'Signed documents', icon: '✍' },
          { hash: 'drip-campaigns',   label: 'Drip campaigns',  icon: '💧' },
          { hash: 'curriculum',       label: 'Curriculum',      icon: '📚' },
          { hash: 'hdlh-gaps',        label: 'HDLH gaps',       icon: '🎯' },
          { hash: 'videos',           label: 'Video feed',      icon: '🎬' },
          { hash: 'photo-tagging',    label: 'Photo AI tagging',icon: '🪄' },
          { hash: 'conferences',      label: 'Conferences',     icon: '🗣' },
          { hash: 'forms-manager',    label: 'Forms Manager',   icon: '🗂️' },
        ]},
        { label: 'Settings', items: [
          { hash: 'billing-settings',  label: 'Billing', icon: '🧾' },
          { hash: 'clock-settings' ,     label: 'Clock settings',          icon: '⏱️' },
          { hash: 'payment-providers',  label: 'Payment providers',       icon: '💳' },
          /* Two-factor lives in the personal profile for EVERY role now — this entry
             used to be the exception, kept because agency admins and centre directors
             had no profile screen. They do as of 2026-09-01 (screen-settings registers
             them), so the account settings and the agency settings are no longer mixed
             together in one menu.

             This item points at that profile, and says "security" out loud: the thing
             people come here looking for is two-factor, and a bare "My profile" gives
             them no reason to think it is inside. */
          { hash: 'settings',           label: 'My profile & security', icon: '👤' },
          { hash: 'educator-rooms', label: 'Room assignments', icon: '🚪' },
          { hash: 'calendar-settings',     label: 'Calendar settings',           icon: '📅' },
          { hash: 'admin-roles',        label: 'Roles & permissions', icon: '🛡' },
          { hash: 'email-settings',     label: 'Email settings',      icon: '✉️' },
          { hash: 'email-templates',    label: 'Email templates',     icon: '📧' },
          { hash: 'document-templates', label: 'Document templates', icon: '📄' },
          { hash: 'quickbooks',         label: 'QuickBooks (Intuit)', icon: '📒' },



          { hash: 'data-retention',     label: 'Data retention & compliance', icon: '🗄️' },
          { hash: 'help',               label: 'Help & guides',       icon: '📖' },
        ].concat(isPlatformAdmin_v22p34 ? [{ hash: 'social-settings', label: 'Sign-in methods', icon: '🔑' }, { hash: 'security-alerts', label: 'Security alerts', icon: '🛡️' }] : []) },
      ];
    }
    if (role === 'centre_director') {
      return [
        { label: 'Overview', items: [
          { hash: 'dashboard',      label: 'Dashboard',        icon: '🏠' },
          { hash: 'children',       label: 'Children',         icon: '🧒' },
          { hash: 'families',       label: 'Families',         icon: '👨‍👩‍👧' },
          { hash: 'staff',          label: 'Staff',            icon: '👥' },
        ]},
        // Home Visitors — directors review their agency's home-visit reports +
        // inspection forms (screens are registered for centre_director too).
        { label: 'Home Visitors', items: [
          { hash: 'home-visit-reports', label: 'Home visit reports', icon: '📋' },
          { hash: 'hcc-forms',          label: 'Inspection forms',   icon: '🏡' },
        ]},
        { label: 'Operations', items: [
          { hash: 'notifications', label: 'Notifications', icon: '🔔' },
          { hash: 'staff-calendar', label: 'Calendar',        icon: '📅' },
          { hash: 'closures',       label: 'Closures',         icon: '🗓' },
          { hash: 'today',          label: 'Today',            icon: '✨' },
          { hash: 'care-log',       label: 'Daily log',        icon: '📝' },

          { hash: 'chat',           label: 'Messenger',         icon: '💬', badgeKey: 'chat_unread' },
          { hash: 'email',          label: 'Email Client',     icon: '✉️' },
          { hash: 'announcements',  label: 'Announcements',    icon: '📢', badgeKey: 'announcement_unread' },
          { hash: 'lesson-plans',   label: 'Lesson plans',     icon: '📚' },
          { hash: 'observations',   label: 'Observations',     icon: '\ud83d\udc40' },
          { hash: 'awards',         label: 'Awards',           icon: '\ud83c\udfc6' },
          { hash: 'schedule',       label: 'Schedule',         icon: '📅' },
          { hash: 'certifications', label: 'Certifications',   icon: '🎓' },
          { hash: 'timesheets',     label: 'Timesheets',       icon: '📊' },
          { hash: 'audit-logs',     label: 'Audit log',        icon: '📜' },
          { hash: 'incidents',     label: 'Incidents',        icon: '⚠️' },
          { hash: 'withdrawals',   label: 'Withdrawals',      icon: '🚸' },
          { hash: 'medications',   label: 'Medications',      icon: '💊' },
          { hash: 'immunizations', label: 'Immunizations',    icon: '🩹' },
          { hash: 'digest-status', label: 'AI digest status', icon: '🤖' },
          { hash: 'maintenance',   label: 'Maintenance',      icon: '🛠️' },
          { hash: 'mail-settings', label: 'Email',            icon: '✉️' },
        ]},
        { label: 'Growth', items: [
          { hash: 'marketing-campaigns', label: 'Marketing',  icon: '📣' },
          { hash: 'tours',              label: 'Tours',       icon: '🚪' },
          { hash: 'sms',                label: 'SMS broadcast', icon: '📱' },
          { hash: 'ai-churn',           label: 'Churn risk',  icon: '📉' },
          { hash: 'ai-docs',            label: 'AI doc extract', icon: '🪄' },
        ]},
        { label: 'Staff', items: [
          { hash: 'tasks',             label: 'Tasks',              icon: '📋' },
          { hash: 'time-clock',        label: 'Time clock',         icon: '⏱' },
          { hash: 'time-off',          label: 'Time off requests',  icon: '🌴' },
          { hash: 'background-checks', label: 'Background checks',  icon: '🛡' },
          { hash: 'substitutes',       label: 'Substitutes',        icon: '🔄' },
        ]},
        { label: 'Programs', items: [
          { hash: 'menu',              label: 'Weekly menu',     icon: '🍽' },
          { hash: 'field-trips',       label: 'Field trips',     icon: '🚐' },
          { hash: 'conferences',       label: 'Conferences',     icon: '🗣' },
          { hash: 'allergy-alerts',    label: 'Allergy alerts',  icon: '⚠' },
        ]},
        { label: 'Compliance', items: [
          { hash: 'compliance',        label: 'Compliance',           icon: '✅' },
          { hash: 'inspection',        label: 'Inspection checklist', icon: '✅' },
          { hash: 'renewals',          label: 'Renewals calendar',    icon: '📅' },
          { hash: 'cwelcc',            label: 'CWELCC subsidies',  icon: '💵' },
          { hash: 'retention',         label: 'Retention',        icon: '📊' },
          { hash: 'forecast',          label: 'Enrolment forecast',icon: '📈' },
          { hash: 'anomalies',         label: 'Anomalies',        icon: '🔍' },
        ]},
        { label: 'Enrollment', items: [
          { hash: 'invitation-codes', label: 'Invitation codes', icon: '✉️' },
          { hash: 'vacation-holds',   label: 'Vacation holds',   icon: '🏖' },
          { hash: 'edocuments',       label: 'eDocuments',       icon: '📄' },
          { hash: 'synced-waitlist',  label: 'Waitlist',          icon: '⏳' },
        ]},
        { label: 'Finance', items: [
          { hash: 'late-events',      label: 'Late pick-ups',    icon: '⏰' },
          { hash: 'bulk-invoices',    label: 'Bulk invoice run', icon: '💸' },
          { hash: 'billing-schedule', label: 'Billing schedule', icon: '📅' },
          { hash: 'payment-plans',    label: 'Payment plans',   icon: '📅' },
          { hash: 'refunds',          label: 'Refunds',          icon: '↩' },
          { hash: 'tuition-increases',label: 'Tuition increases',icon: '📈' },
          { hash: 'external-billing', label: 'Accounting',        icon: '🧾' },
          { hash: 'payroll',           label: 'Payroll',            icon: '💼' },
        ]},
        { label: 'Settings', items: [
          { hash: 'billing-settings',  label: 'Billing', icon: '🧾' },
          { hash: 'clock-settings' ,     label: 'Clock settings',          icon: '⏱️' },
          { hash: 'educator-rooms', label: 'Room assignments', icon: '🚪' },
          /* Two-factor lives in the personal profile for EVERY role now — this entry
             used to be the exception, kept because agency admins and centre directors
             had no profile screen. They do as of 2026-09-01 (screen-settings registers
             them), so the account settings and the agency settings are no longer mixed
             together in one menu.

             This item points at that profile, and says "security" out loud: the thing
             people come here looking for is two-factor, and a bare "My profile" gives
             them no reason to think it is inside. */
          { hash: 'settings',           label: 'My profile & security', icon: '👤' },
          { hash: 'calendar-settings',     label: 'Calendar settings',           icon: '📅' },



          { hash: 'help',             label: 'Help & guides',    icon: '📖' },
        ]},
      ];
    }
    if (role === 'educator') {
      // Curated, classroom-relevant set (mirrors the icon-tile home). The long
      // tail was removed to keep the educator view dead simple.
      return [
        { label: 'Menu', items: [
          { hash: 'home',          label: 'Home',          icon: '🏠' },
          { hash: 'today',         label: 'Today',         icon: '✨' },
          { hash: 'my-tasks',      label: 'My tasks',      icon: '📋' },
          { hash: 'parent-feedback', label: 'Parent Feedback', icon: '💬' },
          // v22p98: educators clock in/out here — needed for ratio compliance,
          // payroll and reporting. The time-clock screen was already built and
          // registered for the educator role; it was just missing from this nav.
          { hash: 'time-clock',    label: 'Clock in/out',  icon: '⏱' },
          { hash: 'care-log',      label: 'Daily log',     icon: '✅' },
          { hash: 'observations',  label: 'Observations',  icon: '👀' },
          { hash: 'lesson-plans',  label: 'Lesson plans',  icon: '📚' },
          { hash: 'chat',          label: 'Messenger',      icon: '💬', badgeKey: 'chat_unread' },
          { hash: 'incidents',     label: 'Incidents',     icon: '⚠️' },
          { hash: 'medications',   label: 'Medications',   icon: '💊' },
          { hash: 'announcements', label: 'News',          icon: '📢' },
          { hash: 'time-off',      label: 'Time off',      icon: '🌴' },
          // 2026-08-30: the canned Reports screen, scoped server-side to this
          // educator's own rooms and their own clock-ins.
          { hash: 'reports',       label: 'Reports',       icon: '📊' },
        ]},
        { label: 'Account', items: [
          { hash: 'notifications', label: 'Notifications', icon: '🔔' },
          { hash: 'help',          label: 'Help',          icon: '📖' },
        ]},
      ];
    }
    if (role === 'home_visitor') {
      // Home visitor: agency-attached practitioner who files home-visit reports
      // against any centre in their agency.
      return [
        { label: 'Menu', items: [
          { hash: 'home',           label: 'Home',             icon: '🏠' },
          { hash: 'new-home-visit', label: 'New visit report', icon: '📝' },
          { hash: 'home-visits',    label: 'My reports',       icon: '📋' },
          { hash: 'my-tasks',       label: 'My tasks',         icon: '✅' },
        ]},
        { label: 'Account', items: [
          { hash: 'notifications', label: 'Notifications', icon: '🔔' },
          { hash: 'settings',      label: 'Settings',      icon: '⚙️' },
          { hash: 'help',          label: 'Help',          icon: '📖' },
        ]},
      ];
    }
    if (role === 'sales_rep') {
      // Platform sales CRM: pipeline, leads, quotes, follow-ups + demo launcher.
      return [
        { label: 'Sales', items: [
          { hash: 'home',            label: 'Home',            icon: '🏠' },
          { hash: 'sales',           label: 'Pipeline',        icon: '📊' },
          { hash: 'sales-leads',     label: 'Leads',           icon: '🎯' },
          { hash: 'sales-new',       label: 'New lead',        icon: '➕', sub: true },
          { hash: 'sales-followups', label: 'Follow-ups',      icon: '⏰' },
          { hash: 'sales-demo',      label: 'Launch demo',     icon: '🚀' },
        ]},
        { label: 'Account', items: [
          { hash: 'notifications', label: 'Notifications', icon: '🔔' },
          { hash: 'help',          label: 'Help',          icon: '❓' },
          { hash: 'settings',      label: 'Settings',      icon: '⚙️' },
        ]},
      ];
    }
    if (role === 'auditor') {
      // Read-only audit/compliance set (mirrors the icon-tile home).
      return [
        { label: 'Menu', items: [
          { hash: 'home',       label: 'Home',       icon: '🏠' },
          { hash: 'compliance', label: 'Compliance', icon: '✅' },
          { hash: 'audit-logs', label: 'Audit logs', icon: '📋' },
          { hash: 'children',   label: 'Children',   icon: '🧒' },
          { hash: 'forms',      label: 'Forms',      icon: '📝' },
          { hash: 'forms-manager', label: 'Forms Manager', icon: '🗂️' },
          { hash: 'help',       label: 'Help',       icon: '📖' },
        ]},
      ];
    }
    // guardian (default) — curated, family-relevant set (mirrors the icon-tile
    // home). The long tail lives behind the home screen's "More" tile.
    return [
      { label: 'Menu', items: [
        { hash: 'home',               label: 'Home',       icon: '🏠' },
        { hash: 'today',              label: 'Today',      icon: '✨' },
        { hash: 'photos',             label: 'Photos & video', icon: '📸' },
        { hash: 'messages',           label: 'Messenger',   icon: '💬', badgeKey: 'chat_unread' },
        { hash: 'my-tasks',           label: 'My tasks',   icon: '📋' },
        { hash: 'checkin',            label: 'Check-in',   icon: '☀' },
        { hash: 'billing',            label: 'Billing',    icon: '💳' },
        { hash: 'attendance-pattern', label: 'Attendance', icon: '📅' },
        { hash: 'medications',        label: 'Health',     icon: '💊' },
        /* The screen was registered for guardians but reachable by nobody — it was
           in the staff nav only, so a parent had no route to it from desktop or
           mobile. That is where the upload lives. */
        { hash: 'immunizations',      label: 'Immunization', icon: '🩹' },
        { hash: 'announcements',      label: 'News',       icon: '📢' },
        /* Reports the centre has shared — incident reports today. Filed copies a
           family can come back to, rather than an email they have to keep. */
        { hash: 'my-documents',       label: 'Documents',  icon: '📄' },
      ]},
      { label: 'Account', items: [
        { hash: 'notifications', label: 'Notifications', icon: '🔔' },
        { hash: 'help',          label: 'Help',          icon: '📖' },
      ]},
    ];
  }

  function buildNav(user) {
    const links = Dom.$('#navLinks');
    if (!links) return;
    Dom.clear(links);

    const role = Roles.primaryRoleOf(user);
    const sections = navItemsForRole(role);
    const isSidebar = (role === 'agency_admin' || role === 'centre_director');

    if (isSidebar) {
      // Grouped sidebar layout (sections with labels)
      sections.forEach(section => {
        const sectionEl = Dom.el('div', { class: 'sidebar-section' });
        if (section.label) {
          sectionEl.appendChild(Dom.el('div', { class: 'sidebar-section-label' }, section.label));
        }
        section.items.forEach(item => sectionEl.appendChild(buildNavLink(item)));
        links.appendChild(sectionEl);
      });
    } else {
      // Flat horizontal list for top-nav layout (educator + guardian)
      sections.forEach(section => {
        section.items.forEach(item => links.appendChild(buildNavLink(item)));
      });
    }

    // Mark that v17 buildNav has run — nav-additions-v12/13/14/15 see this
    // flag and skip their old injectNav() side-effects.
    window.KT_V17_NAV_INSTALLED = true;

    updateActiveNav();
  }

  function buildNavLink(item) {
    const a = Dom.el('a', {
      href: '#' + item.hash,
      class: 'nav-link' + (item.sub ? ' nav-link--sub' : ''),
      'data-hash': item.hash,
    });
    // Sub-items sit indented under their parent (e.g. "New lead" under "Leads").
    // The collapsed rail zeroes side padding, so the indent only shows when expanded.
    if (item.sub) a.style.paddingLeft = '30px';
    if (item.icon) {
      a.appendChild(Dom.el('span', { class: 'nav-icon' }, item.icon));
    }
    a.appendChild(Dom.el('span', { class: 'nav-label' }, item.label));
    if (item.badgeKey) {
      a.appendChild(Dom.el('span', {
        class: 'nav-badge',
        'data-badge-key': item.badgeKey,
        style: 'display:none;',
      }, ''));
    }
    return a;
  }

  // ──────────────── Badge updater ──────────────────────────────
  // External code (push-client, chat polling, announcement polling)
  // can call KT.Shell.setBadge('chat_unread', 4) to update a count.
  function setBadge(key, count) {
    document.querySelectorAll('[data-badge-key="' + key + '"]').forEach(el => {
      if (count > 0) {
        el.textContent = count > 99 ? '99+' : String(count);
        el.style.display = '';
      } else {
        el.style.display = 'none';
      }
    });
  }

  function updateActiveNav() {
    const hash = (window.location.hash || '#dashboard').replace('#', '');
    Dom.$$('.nav-link').forEach(link => {
      const isActive = link.dataset.hash === hash || (hash === '' && link.dataset.hash === 'dashboard');
      link.classList.toggle('active', isActive);
    });
  }

  // ──────────────── Screen router ──────────────────────────────
  const screens = {}; // populated by individual screen modules

  function registerScreen(name, screenFn) {
    screens[name] = screenFn;
  }

  // The "home" screen for a role = the first item in its nav. Admin/director
  // home is 'dashboard'; guardian/educator home is 'today'. Used so a role
  // always lands on a screen it actually has (not a foreign #dashboard).
  function homeHashForRole(role) {
    /* Phones land on the launcher, which is also where the Home button goes. Without
       this a platform_admin signed in to an agency dashboard -- primaryRoleOf() resolves
       them to 'agency_admin', so the first nav item is one tenant's numbers -- and on a
       phone there is no sidebar to get from there to the platform sections. The launcher
       is built from navItemsForRole(), so a superadmin's carries Reseller and a tenant
       admin's does not; each role lands on its own view of the portal.
       Desktop is deliberately unchanged: the sidebar is present there. */
    try {
      if (!window.matchMedia('(min-width: 601px)').matches
          && ['agency_admin', 'centre_director', 'platform_admin'].indexOf(role) !== -1) {
        return 'home';
      }
    } catch (e) { /* no matchMedia — fall through to the nav-order answer below */ }

    var secs = navItemsForRole(role) || [];
    if (secs[0] && secs[0].items && secs[0].items[0] && secs[0].items[0].hash) {
      return secs[0].items[0].hash;
    }
    return 'dashboard';
  }

  function navItemFor(role, hash) {
    var base = String(hash || '').split('/')[0];
    var secs = navItemsForRole(role) || [];
    for (var i = 0; i < secs.length; i++) {
      var items = secs[i].items || [];
      for (var j = 0; j < items.length; j++) {
        if (items[j].hash === base) return { icon: items[j].icon || '', label: items[j].label || base, section: secs[i].label || '' };
      }
    }
    // Icons for screens reached outside the sidebar nav (home tiles, deep links)
    // so their auto-banner gets a unique glyph instead of the generic ✨ default.
    var EXTRA_ICONS = { 'awards': '🏆', 'walk': '🚶' };
    return { icon: EXTRA_ICONS[base] || '', label: base.replace(/-/g, ' ').replace(/\b\w/g, function (c) { return c.toUpperCase(); }), section: '' };
  }
  // One-line description under each banner. Keyed by screen hash, so the wording lives
  // in one place instead of being re-invented per screen (which is why some banners had
  // a description and some had none).
  var SCREEN_DESC = {
    'dashboard': 'Today at a glance: enrolment, attendance, staffing and receivables.',
    'platform-overview': 'Every agency on the platform, with usage and revenue.',
    'platform-agencies': 'Create, configure and suspend agencies.',
    'agencies': 'Create, configure and suspend agencies.',
    'provider-map': 'Where your centres are, and who is on the floor.',

    'care-log': 'Meals, naps, nappies and notes for each child, as the day happens.',
    'chat': 'Conversations with families and staff.',
    'messages': 'Conversations with families and staff.',
    'announcements': 'Broadcast news to a centre, a room, or the whole agency.',
    'awards': 'Celebrate a child’s effort — daily, weekly or monthly. Parents see it too, and you can print a certificate.',
    'lesson-plans': 'Weekly plans for each room.',
    'observations': 'Learning stories and developmental observations for each child.',
    'curriculum': 'Curriculum framework and coverage.',
    'hdlh-gaps': 'Where your programme is light against How Does Learning Happen.',
    'schedule': 'Who is working, and where.',
    'staff-calendar': 'Closures, trips, conferences, time off, birthdays and shifts across the agency.',
    'calendar': 'Closures, trips, conferences, time off, birthdays and shifts across the agency.',
    'auto-signoff': 'Close shifts and days somebody forgot to close.',
    'clock-settings': 'Reminders about the time clock, and closing what somebody forgot.',
    'payment-providers': 'Your own Zum Rails and Stripe accounts, per agency.',
    'late-events': 'Late arrivals and pick-ups awaiting a decision.',
    'billing-settings': 'Tax rate and other invoicing defaults.',
    'calendar-settings': 'Choose which layers the calendar draws.',
    'tasks': 'Assign tasks to educators and track them to completion.',
    'my-tasks': 'Tasks assigned to you — mark them in progress or done.',
    'certifications': 'Staff qualifications and their expiry dates.',
    'timesheets': 'Hours worked, ready for payroll.',
    'time-clock': 'Clock in and out.',
    'time-off': 'Leave requests awaiting a decision.',
    'substitutes': 'Cover for absent staff.',
    'payroll': 'Pay runs and hours.',
    'background-checks': 'Police checks and vulnerable-sector screening.',

    'waitlist': 'Families waiting for a space.',
    'tours': 'Prospective families who booked a centre tour.',
    'incidents': 'Injuries, behaviour and safeguarding reports.',
    'medications': 'Medication authorised, given and outstanding.',
    'immunizations': 'Immunisation records and what is missing.',
    'immun-schedule': 'Children with an immunisation due.',
    'allergy-alerts': 'Allergies and dietary requirements, by room.',
    'room-ratios': 'Live child-to-educator ratios against licensing.',
    'room-rotations': 'Which educator is in which room.',
    'closures': 'Holidays and unplanned closures.',
    'late-pickups': 'Late collections and the fees they attract.',
    'field-trips': 'Outings, consent and headcounts.',
    'trip-gps': 'Live location of a bus or a trip in progress.',
    'bus-routes': 'Routes, stops and riders.',
    'zones': 'Activity zones within each room.',
    'menu': 'This week on the menu.',
    'cacfp': 'CACFP meal counts and claims.',
    'attendance-pattern': 'Attendance patterns and days booked.',
    'photos': 'Photos and clips shared with families.',
    'videos': 'Video clips shared with families.',
    'photo-tagging': 'Faces suggested by AI, for you to confirm.',
    'conferences': 'Parent-teacher conferences.',
    'report-cards': 'Progress reports for each child.',
    'wellness-digest': 'A weekly wellbeing summary for each child.',

    'admin-users': 'Staff accounts, roles and access.',
    'admin-centres': 'Centres and the rooms inside them.',
    'admin-children': 'Every child enrolled, and their records.',
    'admin-families': 'Families, guardians and contact details.',
    'parent-feedback': 'What families said about their child’s day.',
    'admin-roles': 'What each role is allowed to do.',
    'admin-forms': 'Forms families and staff are asked to complete.',
    'forms-manager': 'Upload a form, assign it to parents, educators or home visitors, and track who has signed it.',
    'admin-branding': 'Your logo, colours and the name families see.',
    'admin-features': 'Turn features on and off per agency.',
    'admin-mrr': 'Recurring revenue across the platform.',
    'invitation-codes': 'Codes that let a family or educator join.',
    'synced-waitlist': 'Waitlist leads and enquiries.',
    'edocuments': 'Documents on file, and what is still outstanding.',
    'my-documents': 'Reports your centre has shared with you, kept for you to open any time.',
    'signed-docs': 'Agreements that have been signed.',
    'doc-workflows': 'Who has to sign what, and in which order.',
    'compliance': 'Licensing obligations and the evidence for them.',
    'hcc-forms': 'Monitoring & quarterly inspection forms submitted by home visitors.',
    'home-visit-reports': 'Home-visit reports filed by your home visitors, with edit history.',
    'inspection': 'Walk the licensing checklist before an inspector does.',
    'renewals': 'Licences and certificates coming up for renewal.',
    'cwelcc': 'CWELCC subsidy funding and claims.',
    'audit-logs': 'Every change made in the portal, and by whom.',
    'security-alerts': 'Sign-in anomalies and security events.',
    'birthday-settings': 'Warm notes when a child or colleague has a birthday coming up.',
    'payroll-settings': 'When payroll is next due, so the overview can count down to it.',
    'data-retention': 'How long records are kept before deletion.',
    'anomalies': 'Unusual activity worth a second look.',


    'external-billing': 'Invoices and balances for the agency.',
    'billing': 'Invoices, payments and balances.',
    'billing-setup': 'Fees, schedules and automatic reminders.',
    'billing-schedule': 'When invoices go out, and when payment is due.',
    'bulk-invoices': 'Raise invoices for everyone in one run.',
    'refunds': 'Money returned to families.',
    'payment-plans': 'Split a large balance into instalments.',
    'tuition-plans': 'The fee plans a family can be placed on.',
    'tuition-increases': 'Plan and communicate a fee increase.',
    'sibling-discounts': 'Discounts for second and subsequent children.',
    'vacation-holds': 'Places held while a family is away.',
    'expenses': 'Suppliers, purchase orders and bills.',
    'quickbooks': 'Sync invoices and payments with QuickBooks.',
    'forecast': 'Projected enrolment and revenue.',
    'reports': 'Ready-made reports you can print or export.',
    'retention': 'Which families stay, and which leave.',
    'reenrollment': 'Families due to re-enrol for next term.',
    'engagement': 'How engaged each family is with the app.',
    'nps': 'What families think of you.',
    'ai-churn': 'Families at risk of leaving.',
    'ai-docs': 'Pull the details out of an uploaded document.',
    'digest-status': 'Whether the daily AI summaries went out.',
    'maintenance': 'Schedule downtime and notify users by email.',
    'email-log': 'Every email the system sent — preview the exact message + open tracking.',

    'marketing-campaigns': 'Campaigns to fill your empty spaces.',
    'drip-campaigns': 'Automated follow-up with prospective families.',
    'marketing-site': 'Your public website.',
    'sms': 'Send a one-off text to staff or families.',
    'email-settings': 'The mailbox your agency sends from.',
    'notifications': 'What the app tells people about, and how.',
    'tickets': 'Operational issues, tracked to resolution.',
    'social-settings': 'Google, Microsoft and Facebook sign-in.',
    'mfa': 'Two-factor authentication for your team.',
    'help': 'Guides for every part of KiddieTrac.',
    'reports-canned': 'Ready-made reports you can print or export.'
  };

  function buildAutoHero(info, hash) {
    var b = document.createElement('div');
    b.className = 'kt-hero kt-hero-auto kt-banner-fx';
    var h1 = document.createElement('h1');
    h1.textContent = String(info.label || '');   // textContent keeps the "&" in "Help & guides"
    b.appendChild(h1);

    var key = String(hash || '').split('/')[0].split('?')[0];
    var desc = SCREEN_DESC[key];
    if (desc) {
      var sub = document.createElement('div');
      sub.className = 'kt-hero-sub';
      sub.textContent = desc;
      b.appendChild(sub);
    }

    var art = document.createElement('div');
    art.className = 'kt-hero-emoji';
    art.setAttribute('aria-hidden', 'true');
    art.textContent = String(info.icon || '\u2728');
    b.appendChild(art);
    return b;
  }

  // The banner belongs directly BELOW the top bar, never above it. Both are inserted at
  // the top of #appMain, so without this they race and the order flips per screen.
  // The toolbar that sits directly beneath the banner and holds its action buttons.
  function pageActions(main, hero) {
    var bar = main.querySelector('.kt-page-actions');
    if (!bar) {
      bar = document.createElement('div');
      bar.className = 'kt-page-actions';
      if (hero && hero.parentNode) hero.parentNode.insertBefore(bar, hero.nextSibling);
      else main.appendChild(bar);
    }
    return bar;
  }

  function heroAnchor(main) {
    var bar = main.querySelector('#kt-topbar');
    return bar ? bar.nextSibling : main.firstChild;
  }
  // Remount the top bar, but never more than a few times per screen visit.
  //
  // THE CAP IS LOAD-BEARING. Some screens (Support tickets) re-render themselves in
  // response to any #appMain mutation, so re-adding the bar makes them wipe it again —
  // an uncapped remount is an infinite render loop that freezes the tab. After the cap
  // we leave it to kt-topbar's own 1.2s poll, which is slow enough not to spiral.
  var _tbHash = null, _tbMounts = 0;
  function ensureTopbar(hash) {
    var h = String(hash || '').split('/')[0];
    if (h !== _tbHash) { _tbHash = h; _tbMounts = 0; }
    if (_tbMounts >= 3) return;
    if (document.getElementById('kt-topbar')) return;
    try {
      if (window.KT && KT.topbar && KT.topbar.ensure) {
        KT.topbar.ensure();
        if (document.getElementById('kt-topbar')) _tbMounts++;
      }
    } catch (e) {}
  }

  // Shared, per-screen-visit budget for banner normalisation. Deliberately survives
  // re-renders of the same screen — that is the whole point (see __ensure).
  var _bannerHash = null, _bannerRuns = 0;
  function bannerSync(hash) {
    var h = String(hash || '').split('/')[0];
    if (h !== _bannerHash) { _bannerHash = h; _bannerRuns = 0; }
  }
  // Is there budget left? (Cheap look — costs nothing.)
  function bannerBudgetLeft(hash) { bannerSync(hash); return _bannerRuns < 15; }
  // Charge the budget — called ONLY after we actually changed the DOM, so a screen
  // that merely re-renders a lot does not starve itself, while a screen that fights us
  // over its banner still runs out and is left alone.
  function bannerSpend(hash) { bannerSync(hash); _bannerRuns++; }

  // Strip emoji/punctuation so "\ud83d\udcda Lesson Plans" and "Lesson plans" compare equal.
  function heroKey(t) {
    return String(t || '').replace(/[^\p{L}\p{N} ]/gu, ' ').replace(/\s+/g, ' ').trim().toLowerCase();
  }

  // Collapse whatever a screen rendered at the top into the single standard hero:
  //   1. legacy header (.page-header-v17 / .kt-page-hero) -> its subtitle and action
  //      buttons are MOVED into the hero (moved, not rebuilt, so ids and already-bound
  //      listeners survive), then the header is removed;
  //   2. a screen's own inline-gradient banner (Tours) -> removed;
  //   3. a heading that merely repeats the hero title ("Medications" under the
  //      Medications banner) -> removed, leaving any buttons beside it in place.
  //
  // Cheap and idempotent by construction: it only ever looks at main's first few
  // levels, reads the inline style ATTRIBUTE rather than computed style (no forced
  // layout), and marks what it has handled.
  // Lift action buttons out of ANY banner into the toolbar beneath it. Buttons inside a
  // banner sit on top of the artwork and read as decoration rather than controls.
  // Returns true if it moved anything.
  function liftBannerButtons(main) {
    var banner = main.querySelector('.kt-hero, .kt-page-hero');
    if (!banner) return false;
    var btns = main.querySelectorAll('.kt-hero button, .kt-hero a.btn, .kt-page-hero button, .kt-page-hero a.btn');
    if (!btns.length) return false;
    var host = pageActions(main, banner);
    for (var q = 0; q < btns.length; q++) host.appendChild(btns[q]);   // move: keeps ids + listeners
    return true;
  }

  /**
   * A screen that draws its OWN banner very often ALSO draws a plain <h1> + subtitle
   * above it, so the page opens with the title twice — once as grey text, once in the
   * branded banner further down — and the banner no longer leads the page.
   * (Anthony, 2026-08-30, on #admin-families: "the banner is not at the top and there is
   * other text at the top which is redundant to the banner".)
   *
   * De-duplication already existed for the shell's own `.kt-hero-auto` and for the
   * legacy `.page-header-v17`, which is why this looked handled — a bare <h1>+<div> pair
   * matched neither. Fixed HERE rather than in screen-admin.js so it covers every screen
   * with the pattern, including ones written later.
   *
   * Deliberately narrow, because this deletes markup a screen rendered:
   *   • the heading must say the SAME thing as the banner (heroKey-normalised, so
   *     "👪 Families" and "Families" match while "Families" and "Children" never do);
   *   • it must come BEFORE the banner in document order;
   *   • its container is only removed when it holds nothing interactive — otherwise just
   *     the heading goes and whatever else was in there stays.
   */
  function tidyOwnBanner(main) {
    var banner = main.querySelector('.kt-hero:not(.kt-hero-auto), .kt-page-hero');
    if (!banner) return false;
    var changed = false;
    var title = heroKey((banner.querySelector('h1, h2') || {}).textContent);

    if (title) {
      var heads = main.querySelectorAll('h1, h2');
      for (var i = 0; i < heads.length; i++) {
        var h = heads[i];
        if (banner.contains(h)) continue;
        if (heroKey(h.textContent) !== title) continue;
        // Only a heading ABOVE the banner is the redundant one. A matching heading
        // further down is a section of the page that happens to share the name.
        if (!(h.compareDocumentPosition(banner) & Node.DOCUMENT_POSITION_FOLLOWING)) continue;

        var block = h.parentElement;
        var disposable = block && block !== main && block.children.length <= 3 &&
          [].every.call(block.children, function (c) {
            return c === h || !c.querySelector('button, input, select, textarea, table, a');
          });
        if (disposable) block.parentNode.removeChild(block);
        else h.parentNode.removeChild(h);
        changed = true;
        break;
      }
    }

    /* And the banner leads its own section. Bounded to the first few positions: a banner
       sitting third behind a title and a tab strip is one that was meant to be at the
       top, whereas one halfway down the page belongs where the screen put it. */
    var par = banner.parentElement;
    if (par && par.firstElementChild !== banner) {
      var idx = [].indexOf.call(par.children, banner);
      if (idx > 0 && idx <= 2) { par.insertBefore(banner, par.firstElementChild); changed = true; }
    }
    return changed;
  }

  function normaliseBanners(main, hash) {
    var hero = main.querySelector('.kt-hero-auto');

    // If the screen rendered its OWN banner after we had already added the automatic one
    // (the Admin tabs do this: each tab draws its own .kt-hero), drop OURS — theirs is
    // the more specific one, and otherwise two banners stack. We only ever remove the
    // element we created, never one the screen owns.
    if (hero) {
      var own = main.querySelector('.kt-hero:not(.kt-hero-auto), .kt-page-hero');
      if (own) { hero.parentNode.removeChild(hero); tidyOwnBanner(main); return true; }
    }
    // No auto-banner: the screen brought its own (role-home's greeting, or a
    // .kt-page-hero). Its buttons still come out — and so does any heading repeating it.
    if (!hero) { var t = tidyOwnBanner(main); return liftBannerButtons(main) || t; }
    var changed = liftBannerButtons(main);
    var h1 = hero.querySelector('h1');
    var title = heroKey(h1 && h1.textContent);

    // 1. legacy headers
    var legacy = main.querySelectorAll('.page-header-v17');
    for (var i = 0; i < legacy.length; i++) {
      var L = legacy[i];
      if (hero.contains(L)) continue;

      // The legacy header's own <h1> is the page's real title ("AI-generated Lesson
      // Plans"), and it beats the nav label — let alone the hash-derived fallback
      // ("Lesson Plans Ai"). Carry it into the banner.
      var lh1 = L.querySelector('h1');
      if (lh1 && h1) {
        var lt = (lh1.textContent || '').trim();
        if (lt) { h1.textContent = lt; title = heroKey(lt); }
      }

      var sub = L.querySelector('.sub, .subtitle');
      if (sub && !hero.querySelector('.kt-hero-sub')) {
        var sd = document.createElement('div');
        sd.className = 'kt-hero-sub';
        sd.textContent = (sub.textContent || '').trim();
        var em = hero.querySelector('.kt-hero-emoji');
        hero.insertBefore(sd, em || null);
      }

      var acts = L.querySelector('.actions');
      var btns = acts ? acts.children : L.querySelectorAll('button, a.btn');
      if (btns && btns.length) {
        var bar = pageActions(main, hero);
        while (btns.length) bar.appendChild(btns[0]);   // move: keeps listeners + ids
      }
      L.parentNode.removeChild(L);
      changed = true;
    }

    // 3. The heading a screen prints as its own page title.
    //
    // Two cases, both ending with ONE banner and no repeated title underneath:
    //  (a) it repeats the banner ("Medications" under a Medications banner) -> drop it;
    //  (b) it IS the page's name but the banner is showing the nav SECTION instead
    //      ("Medications" under a banner reading "Health", because the nav item for
    //      #medications is labelled Health) -> promote it into the banner, then drop it.
    //      Promotion is deliberately limited to a heading that matches the page's own
    //      hash, so an ordinary card title can never rename the banner.
    var pageKey = heroKey(String(hash || '').split('/')[0].replace(/-/g, ' '));
    var heads = main.querySelectorAll('h1, h2, h3');
    for (var k = 0; k < heads.length && k < 6; k++) {
      var H = heads[k];
      if (hero.contains(H)) continue;
      var hk = heroKey(H.textContent);
      if (!hk) continue;
      if (title && hk === title) {                 // (a) duplicate
        H.parentNode.removeChild(H);
        changed = true;
        break;
      }
      /* (c) the heading EXTENDS the banner's title — "White-Label Branding" under a
         banner reading "Branding", which is the same page named twice, just not
         character-for-character, so (a) never caught it. The longer one is the real
         name: promote it, drop the heading. Whole-word containment only, so "Room
         ratios" is never folded into a banner titled "Rooms". */
      /* endsWith/startsWith, NOT indexOf-arithmetic: `hk.indexOf(' '+title) === hk.length
         - title.length - 1` is true whenever BOTH sides are -1, i.e. whenever the two
         strings merely happen to be the same LENGTH. That promoted "Live invoice preview"
         (20 chars) into a banner titled "White-Label Branding" (20 chars) the moment this
         shipped. Compared as strings there is no sentinel to collide with. */
      if (title && hk !== title &&
          (hk.indexOf(title + ' ') === 0 || hk.slice(-(title.length + 1)) === ' ' + title)) {
        if (h1) h1.textContent = (H.textContent || '').replace(/[^\p{L}\p{N} &'\-]/gu, '').trim();
        H.parentNode.removeChild(H);
        changed = true;
        break;
      }
      if (pageKey && hk === pageKey) {             // (b) the real page title
        if (h1) h1.textContent = (H.textContent || '').replace(/[^\p{L}\p{N} &'\-]/gu, '').trim();
        H.parentNode.removeChild(H);
        changed = true;
        break;
      }
    }
    return changed;
  }

  var _hashStack = [];
  function _trackNav(h) {
    if (_hashStack.length > 1 && _hashStack[_hashStack.length - 2] === h) { _hashStack.pop(); }
    else if (_hashStack[_hashStack.length - 1] !== h) { _hashStack.push(h); }
  }
  /* Is the user in the middle of something a screen teardown would destroy?
     ONE answer, shared by kt-auto-refresh and kt-live, which each had their own partial
     selector list. Returns true to DEFER a refresh, never to cancel it. */
  window.KT = window.KT || {};
  window.KT.uiBusy = function () {
    try {
      // 1. Anything focused that holds text or a choice.
      var a = document.activeElement;
      if (a) {
        var t = (a.tagName || '').toUpperCase();
        if (t === 'INPUT' || t === 'TEXTAREA' || t === 'SELECT' || a.isContentEditable) return true;
        /* Focus inside a dialog counts even when it is on a button: the classic way to
           lose your work is to type, then tick a box, and have the tick move focus off
           the input just as the timer fires. */
        if (a.closest && a.closest('[role="dialog"], .kt-modal, .kt-modal-overlay, .modal-backdrop, .kt-scrim')) return true;
      }

      // 2. The dialog shapes this portal actually uses, by name.
      if (document.querySelector(
        '.kt-modal, .kt-modal-overlay, .modal-backdrop, .kt-scrim, .kt-lightbox,'
        + ' .kt-doc-viewer, .kt-av-zoom, [role="dialog"], [data-kt-open-menu], .kt-sheet-open'
      )) return true;
      var mr = document.getElementById('modalRoot');
      if (mr && mr.firstElementChild) return true;

      /* 3. And by SHAPE, for the dozen dialogs built with inline position:fixed and no
            class at all. Only body's own children are measured — dialogs are appended
            there — so this stays a handful of getComputedStyle calls. */
      var kids = document.body ? document.body.children : [];
      for (var i = 0; i < kids.length; i++) {
        var el = kids[i];
        if (el.id === 'appMain' || el.tagName === 'SCRIPT' || el.tagName === 'STYLE' || el.tagName === 'LINK') continue;
        if (el.hidden) continue;
        var cs = window.getComputedStyle(el);
        if (cs.position !== 'fixed' || cs.display === 'none' || cs.visibility === 'hidden') continue;
        if (parseFloat(cs.opacity || '1') < 0.05) continue;
        var z = parseInt(cs.zIndex, 10);
        if (isNaN(z) || z < 900) continue;              // toasts and bars sit lower
        var r = el.getBoundingClientRect();
        // Big enough to be a dialog rather than a badge or a floating button.
        if (r.width < 200 || r.height < 120) continue;
        return true;
      }

      // 4. A screen that says it updates itself, or asks not to be refreshed.
      if (document.querySelector('[data-kt-no-autorefresh], [data-kt-self-live]')) return true;
    } catch (e) { /* never let this throw and block a refresh forever */ }
    return false;
  };

  window.ktBack = function () {
    if (_hashStack.length > 1) { var prev = _hashStack[_hashStack.length - 2]; window.location.hash = (prev.charAt(0) === '#' ? prev : '#' + prev); }
    else { try { window.location.hash = '#' + homeHashForRole(Roles.primaryRoleOf(Auth.user())); } catch (e) { window.location.hash = '#dashboard'; } }
  };
  /* Is this control "go back", or something else that merely starts with an arrow?
     Getting this wrong is expensive: the handler below captures, so a false positive
     both cancels the control's own click AND navigates away from the screen.

     The old test was "starts with an arrow and is 8 characters or shorter", which caught
     every '‹ Prev' pager in the portal — the table pager, chat, forms, parent, care's
     previous-day, notifications' '‹ Newer' — and '← OUT', which checks a child out.
     kt-icon-buttons.js had already solved the same problem; this matches its reasoning. */
  function isBackControl(el, t) {
    if (el.hasAttribute && el.hasAttribute('data-back')) return true;
    if (el.classList && el.classList.contains('kt-back')) return true;

    var s = (t || '').toLowerCase();
    // Paging and check-in/out are actions on the page, never navigation history.
    if (/\b(prev|previous|next|newer|older|forward)\b/.test(s)) return false;
    if (/\bback\b/.test(s)) return true;

    /* A bare long arrow, alone — with an optional U+FE0F, because kt-icon-buttons.js
       rewrites a back label to the emoji '⬅️' on mobile (desktop keeps the text), and
       that is the glyph plus a variation selector.

       '‹' is deliberately absent: in this codebase it is the pagination glyph — a dozen
       pagers use it and nothing uses it to mean back. Real back controls read '← Back'
       or '‹ Back' and are caught by the word above. */
    return /^[←⟵⬅⭠]️?$/.test((t || '').trim());
  }

  document.addEventListener('click', function (e) {
    var el = e.target && e.target.closest ? e.target.closest('a,button,[data-back]') : null;
    if (!el) return;
    if (!isBackControl(el, (el.textContent || '').trim())) return;
    e.preventDefault(); e.stopPropagation();
    // Overlay-aware: closes an open thread/compose/invoice back to the list
    // it sits on, and only navigates the hash when nothing is stacked.
    if (window.KT && KT.goBack) KT.goBack(); else window.ktBack();
  }, true);

  window.ktViewAs = function (role) {
    try { if (role) sessionStorage.setItem('kt_view_as', role); else sessionStorage.removeItem('kt_view_as'); } catch (e) {}
    // Drop the current hash so the new role lands on ITS home screen (e.g. a
    // parent's "today") instead of the previous role's page or a bogus #dashboard.
    try { history.replaceState(null, '', location.pathname + location.search); } catch (e) {}
    location.reload();
  };
  function _injectViewAs(user) {
    if (!user || !Array.isArray(user.roles) || user.roles.indexOf('platform_admin') === -1) return;
    var cur = ''; try { cur = sessionStorage.getItem('kt_view_as') || ''; } catch (e) {}
    var old = document.getElementById('kt-view-as'); if (old) old.remove();
    var roles = [['', 'Super admin (default)'], ['agency_admin', '\uD83C\uDFE2 Agency admin'], ['centre_director', '\uD83C\uDFEB Centre director'], ['educator', '\uD83C\uDF93 Educator'], ['guardian', '\uD83D\uDC6A Parent / guardian'], ['home_visitor', '\uD83C\uDFE1 Home visitor'], ['sales_rep', '\uD83D\uDCBC Sales rep'], ['auditor', '\uD83D\uDD0D Auditor']];
    var wrap = document.createElement('div'); wrap.id = 'kt-view-as';
    // Embedded in the sidebar (not a floating overlay) so it never blocks content.
    wrap.style.cssText = 'margin:10px 8px 8px;background:' + (cur ? '#7C3AED' : '#0f2233') + ';color:#fff;border-radius:10px;padding:8px 10px;font-size:12px;display:flex;align-items:center;gap:8px;font-family:inherit;flex-wrap:wrap;';
    var lab = document.createElement('span'); lab.textContent = cur ? '\uD83D\uDC41 Viewing as' : '\uD83D\uDC41 View as'; lab.style.cssText = 'font-weight:700;white-space:nowrap';
    var sel = document.createElement('select'); sel.style.cssText = 'border-radius:7px;padding:4px 8px;font-size:12px;font-family:inherit;cursor:pointer;flex:1;min-width:118px;max-width:100%;';
    roles.forEach(function (r) { var o = document.createElement('option'); o.value = r[0]; o.textContent = r[1]; if (r[0] === cur) o.selected = true; sel.appendChild(o); });
    sel.addEventListener('change', function () { window.ktViewAs(sel.value); });
    wrap.appendChild(lab); wrap.appendChild(sel);
    var _sb = document.getElementById('appSidebar');
    if (_sb) { var _nu = document.getElementById('navUser'); if (_nu && _nu.parentNode === _sb) _sb.insertBefore(wrap, _nu); else _sb.appendChild(wrap); }
    else { wrap.style.position = 'fixed'; wrap.style.left = '14px'; wrap.style.bottom = '14px'; wrap.style.zIndex = '9000'; document.body.appendChild(wrap); }
  }

  /* ── Holding the reader's place across a same-screen re-render ─────────────
     A re-render of the screen you are already on (a live update after a write, the
     45s poll) is not navigation, and it must not cost you your position.

     Restoring on the next animation frame is not enough, and that was the bug behind
     "the view keeps jumping". Most screens return from their render function BEFORE
     their data arrives — the audit log paints a "Loading…" line and fetches — so one
     frame later the document is a few hundred pixels tall, the browser CLAMPS
     scrollTo(0, 4000) to the bottom of that short page, and when the real table lands
     the reader is somewhere they never asked to be.

     So keep re-applying the target while the page is still growing, and stop the
     moment the reader touches the scroll themselves — putting somebody back where they
     were is help, moving them while they are scrolling is a fight they always lose.
     (Anthony, 2026-08-26) */
  function __ktSettleScroll(y, releaseEl) {
    var tries = 0, aborted = false;
    var lastSet = -1;                      // the position WE last asked for
    function abort() { aborted = true; }
    var opts = { passive: true, capture: true };
    var EVENTS = ['wheel', 'touchstart', 'touchmove', 'keydown', 'mousedown'];
    EVENTS.forEach(function (ev) { window.addEventListener(ev, abort, opts); });

    /* The input events above only catch a scroll that STARTS after the re-render.
       A wheel or a swipe already in flight when the screen was torn down has
       already fired, so nothing aborted and the loop below spent the next second
       and a half dragging the reader back — which is what "it won't let me
       scroll" is. So also watch the result: if the page reports a position that
       is not the one we set, somebody else moved it, and they win. */
    function onScroll() {
      if (lastSet < 0) { return; }
      var at = window.scrollY || (document.scrollingElement || {}).scrollTop || 0;
      if (Math.abs(at - lastSet) > 4) { aborted = true; }
    }
    window.addEventListener('scroll', onScroll, opts);

    function release() {
      EVENTS.forEach(function (ev) {
        try { window.removeEventListener(ev, abort, opts); } catch (e) {}
      });
      try { window.removeEventListener('scroll', onScroll, opts); } catch (e) {}
      // Let the screen size itself again — the pin exists only for the blank moment.
      if (releaseEl) { try { releaseEl.style.minHeight = ''; } catch (e) {} }
    }
    function step() {
      if (aborted) { release(); return; }
      var doc = document.documentElement;
      var max = Math.max(0, (doc ? doc.scrollHeight : 0) - window.innerHeight);
      var target = Math.min(y, max);
      lastSet = target;
      try { window.scrollTo(0, target); } catch (e) {}
      // Tall enough to honour the request, or out of patience (~1.6s of async render).
      if (max >= y || ++tries > 32) { release(); return; }
      setTimeout(step, 50);
    }
    requestAnimationFrame(step);
  }

  async function renderScreen() {
    /* A re-render of the screen you are already on (a save, a tab switch) is NOT
       navigation. The resets below are correct for navigation and stay as they are;
       this records where you were so the same-screen case can be put back. */
    var _ktSameScreen = (location.hash === window.__ktLastHash);
    var _ktPrevScroll = _ktSameScreen
      ? (window.scrollY || (document.scrollingElement || {}).scrollTop || 0) : 0;
    window.__ktLastHash = location.hash;
    const main = Dom.$('#appMain');
    // Reset scroll to the top BEFORE we clear + render. If we only reset after
    // render, the shell's hashchange listener (registered before kt-mobilenav's)
    // paints the new, tall screen at the OLD scroll position for a frame first —
    // that's the "shows the bottom, then jumps to the top" flash. Resetting here,
    // while the old content is still in place, means the new screen paints at 0.
    /* Landing at the top is right for NAVIGATION and wrong for a re-render of the
       screen you are already on — that is somebody's place on a page, not a new page. */
    if (!_ktSameScreen) {
      try {
        window.scrollTo(0, 0);
        if (document.scrollingElement) document.scrollingElement.scrollTop = 0;
        if (document.documentElement) document.documentElement.scrollTop = 0;
        if (document.body) document.body.scrollTop = 0;
        if (main) main.scrollTop = 0;
      } catch (e) {}
    } else if (main) {
      /* Hold the page's height across the blank moment. Dom.clear() empties #appMain,
         the document collapses to nothing, and the browser clamps the scroll position
         to the bottom of an empty page — the reader is already lost before the new
         content has even been asked for. Pinning the old height keeps the scrollbar
         still; __ktSettleScroll releases it once the screen has painted. */
      try {
        var _h = main.getBoundingClientRect().height;
        if (_h > 0) { main.style.minHeight = _h + 'px'; }
      } catch (e) {}
    }
    Dom.clear(main);
    try { if (window.__ktBannerObs) window.__ktBannerObs.disconnect(); } catch (e) {}



    const user = Auth.user();
    const role = Roles.primaryRoleOf(user);
    const hash = (window.location.hash || ('#' + homeHashForRole(role))).replace('#', '').split('?')[0];
    _trackNav(hash);

    // Clearing #appMain took the top bar with it. Put it back before the screen renders
    // a single node, so the bar is already there when the banner draws — otherwise it
    // was missing until kt-topbar's 1.2s poll, and popped in on top of the banner.
    ensureTopbar(hash);

    updateActiveNav();

    /* The role's screen modules are no longer <script> tags — they are fetched for
       the role that actually signs in. Awaited HERE, immediately before the lookup,
       so a screen is never reported missing just because its file is still in
       flight. Resolves immediately once loaded, and resolves anyway if the loader
       is absent or a file 404s, which leaves the original behaviour intact. */
    if (window.KT && KT.screenLoader) {
      try { await KT.screenLoader.ensure(role, hash); } catch (e) {}
    }

    // Route based on role + hash
    const screenKey = `${role}:${hash}`;
    const fallbackKey = `${role}:dashboard`;

    // Super-admins operate under the resolved `agency_admin` role, but several
    // screens (Maintenance, Email/mail-settings, and other super-admin-only
    // Reseller tools) are registered ONLY under `platform_admin:`. Try that key
    // before falling back to the dashboard — otherwise those nav items silently
    // landed on the home screen ("Maintenance / Email don't open").
    const isPlatform = !!(user && Array.isArray(user.roles) && user.roles.indexOf('platform_admin') !== -1)
      || sessionStorage.getItem('kt_is_platform_admin') === '1';

    const fn = screens[screenKey]
      || (isPlatform ? screens['platform_admin:' + hash] : null)
      || screens[fallbackKey] || screens['guardian:today'];

    if (!fn) {
      main.appendChild(emptyState('🤔', 'Screen not available', 'No screen registered for this role + page.'));
      return;
    }

    try {
      await fn(main, { user, role, params: parseParams() });
      // Land every freshly-rendered screen at the very top. Doing it AFTER render
      // (not just on hashchange, before the async content exists) is what stops the
      // "shows the bottom, then scrolls to the top" flash on tall screens like Home.
      try {
        if (_ktSameScreen) {
          /* Same screen: never scroll to 0 first. The old code reset to the top and then
             raced a single restore frame against it — which is why a live refresh flung
             you to the top and sometimes left you there. */
          __ktSettleScroll(_ktPrevScroll, main);
        } else {
          window.scrollTo(0, 0);
          if (document.scrollingElement) document.scrollingElement.scrollTop = 0;
          if (document.documentElement) document.documentElement.scrollTop = 0;
          if (document.body) document.body.scrollTop = 0;
          main.scrollTop = 0;
          requestAnimationFrame(function () { try { window.scrollTo(0, 0); if (document.scrollingElement) document.scrollingElement.scrollTop = 0; } catch (e) {} });
        }
      } catch (e) { try { if (main) main.style.minHeight = ''; } catch (e2) {} }
      try {
        var __info = navItemFor(role, hash);
        // ONE banner per screen, and it always looks the same.
        //
        // Only the standard .kt-hero component counts as "this screen brought its own
        // banner" (role-home's greeting tile is the one legitimate case). The legacy
        // headers — .page-header-v17 / .kt-page-hero, and the hand-rolled inline-gradient
        // banner Tours builds — no longer suppress the auto-hero; normaliseBanners()
        // folds them INTO it. That is why AI Lesson Plans looked nothing like the rest
        // of the site and Tours stacked two banners.
        var __hasHero = function () {
          // A screen can opt out of the auto-hero explicitly (educator Today renders
          // its own "Today at a glance" banner in that slot) via data-kt-no-autohero,
          // without needing a .kt-hero element that global banner-fx would decorate.
          //
          // .kt-page-hero is NOT in this list, though it was until 2026-08-31 -- which
          // contradicted the paragraph directly above and cost the parent Settings screen
          // its banner. The class is used for SECTION headers as well as page ones, and
          // Settings has one on its Payments tab ("Wallet"). Buried in a tab panel that
          // is not even on screen, it still answered "this screen brought its own
          // banner", so the shell stood down and Settings became the only screen in the
          // portal without one.
          //
          // A selector that means "the screen has a banner" cannot be one that also
          // matches things which are not banners. .kt-hero is the component; a legacy
          // .kt-page-hero gets folded into the auto-hero by normaliseBanners(), which is
          // what the comment above always said would happen.
          return !!main.querySelector('.kt-hero, [data-kt-no-autohero]');
        };
        var __ensure = function () {
          // The budget is GLOBAL per screen visit, not per render pass. A screen that
          // re-renders itself (support tickets does) calls renderScreen again, which
          // would reset a per-pass counter to zero — so a screen that re-adds the banner
          // we just folded in ping-pongs with us forever. That froze the tab. With a
          // shared budget the worst case is that we stop touching the screen and it
          // simply keeps its own banner.
          ensureTopbar(hash);   // capped — see below
          if (!bannerBudgetLeft(hash)) return;
          if (!__hasHero()) { main.insertBefore(buildAutoHero(__info, hash), heroAnchor(main)); bannerSpend(hash); }
          try { if (normaliseBanners(main, hash)) bannerSpend(hash); } catch (e) {}
        };
        __ensure();
        // Some screens (Vacation holds) clear main and render their header seconds
        // later — after the old 4s window had closed, which left them with no banner
        // at all. The 15-run budget is what keeps this cheap.
        [120, 400, 900, 1800, 3200, 5200, 7500].forEach(function (ms) { setTimeout(__ensure, ms); });
        if (window.MutationObserver) { var __obs = new MutationObserver(__ensure); window.__ktBannerObs = __obs; __obs.observe(main, { childList: true }); setTimeout(function () { __obs.disconnect(); }, 9000); }
      } catch (e) {}
    } catch (e) {
      console.error('Screen render error:', e);
      Dom.clear(main);
      main.appendChild(emptyState('⚠️', 'Something went wrong', e.message || 'Please refresh the page.'));
    }
  }

  function parseParams() {
    const hashParts = (window.location.hash || '').split('?');
    if (hashParts.length < 2) return {};
    const params = {};
    new URLSearchParams(hashParts[1]).forEach((v, k) => { params[k] = v; });
    return params;
  }

  function navigate(hash) {
    window.location.hash = '#' + hash;
  }

  // ──────────────── Modal system ───────────────────────────────
  const Modal = {
    open({ title, body, actions = [], onClose = null, large = false }) {
      const root = Dom.$('#modalRoot');
      Dom.clear(root);
      // a11y: remember what had focus so we can restore it when the modal closes.
      const prevFocus = document.activeElement;

      // Lock the page behind the modal so a mouse-wheel scroll inside the dialog
      // doesn't scroll the whole window — it stays focused on the popup.
      const _htmlEl = document.documentElement;
      const _prevOverflow = _htmlEl.style.overflow;
      _htmlEl.style.overflow = 'hidden';

      const close = () => {
        document.removeEventListener('keydown', onKeyDown, true);
        _htmlEl.style.overflow = _prevOverflow;
        Dom.clear(root);
        try { if (prevFocus && prevFocus.focus) prevFocus.focus(); } catch (e) {}
        if (onClose) onClose();
      };

      // Esc closes; Tab is trapped inside the dialog so keyboard focus can't wander
      // onto the page behind the backdrop — standard accessible-dialog behaviour.
      function onKeyDown(e) {
        if (e.key === 'Escape') { e.preventDefault(); close(); return; }
        if (e.key === 'Tab') {
          const f = modal.querySelectorAll('a[href],button:not([disabled]),textarea:not([disabled]),input:not([disabled]):not([type=hidden]),select:not([disabled]),[tabindex]:not([tabindex="-1"])');
          if (!f.length) return;
          const first = f[0], last = f[f.length - 1];
          if (e.shiftKey && document.activeElement === first) { e.preventDefault(); last.focus(); }
          else if (!e.shiftKey && document.activeElement === last) { e.preventDefault(); first.focus(); }
        }
      }

      const backdrop = Dom.el('div', { class: 'modal-backdrop' });
      // Close only when a click BOTH starts and ends on the backdrop. Tracking the
      // mousedown origin stops a text-selection drag that begins inside a field and
      // releases on the backdrop from closing the dialog mid-edit (annoying bug).
      let _downOnBackdrop = false;
      backdrop.addEventListener('mousedown', (e) => { _downOnBackdrop = (e.target === backdrop); });
      backdrop.addEventListener('click', (e) => { if (e.target === backdrop && _downOnBackdrop) close(); });

      const modal = Dom.el('div', { class: 'modal' + (large ? ' modal-large' : '') });
      modal.setAttribute('role', 'dialog');
      modal.setAttribute('aria-modal', 'true');
      modal.setAttribute('aria-labelledby', 'kt-modal-title');
      modal.setAttribute('tabindex', '-1');

      // Header
      const header = Dom.el('div', { class: 'modal-header' });
      const titleEl = Dom.el('h2', {}, title);
      titleEl.id = 'kt-modal-title';
      header.appendChild(titleEl);
      const xBtn = Dom.el('button', {
        class: 'modal-close',
        onClick: close,
      }, '×');
      xBtn.setAttribute('aria-label', 'Close');
      header.appendChild(xBtn);
      modal.appendChild(header);

      // Body — scrolls inside the dialog (overscroll-behavior:contain stops the
      // scroll from chaining to the page once the body hits its top/bottom).
      const bodyEl = Dom.el('div', { class: 'modal-body' });
      // Scrolling lives in the stylesheet now: the shell is a flex column that does
      // not scroll and the body is the only scroll region, sized by flex. The inline
      // "90vh minus 128px" had to guess the height of the header and footer, and
      // paired with the shell's own overflow it produced two scrollbars.
      if (typeof body === 'string') {
        bodyEl.innerHTML = body;
      } else if (body instanceof Node) {
        bodyEl.appendChild(body);
      }
      modal.appendChild(bodyEl);

      // Footer
      if (actions.length) {
        const footer = Dom.el('div', { class: 'modal-footer' });
        actions.forEach(a => {
          // v22p3.1: accept either `handler` (preferred) OR `onClick` (legacy from
          // screen-admin.js). Without this back-compat, every screen-admin action
          // button no-ops and the modal just closes — that's why "Create centre"
          // and "Save user" appeared to do nothing.
          const cb = a.handler || a.onClick;
          const isPrimary = a.style === 'btn-primary' || a.primary;
          const btn = Dom.el('button', {
            class: 'btn ' + (a.style || (isPrimary ? 'btn-primary' : 'btn-secondary')),
            onClick: async () => {
              if (cb) {
                btn.disabled = true;
                btn.textContent = a.busyLabel || 'Saving…';
                try {
                  const result = await cb();
                  // If the callback returned `false` (legacy onClick callers do
                  // this on validation error), leave the modal open and restore
                  // the button. Otherwise close.
                  if (result === false) {
                    btn.disabled = false;
                    btn.textContent = a.label;
                  } else {
                    close();
                  }
                } catch (err) {
                  btn.disabled = false;
                  btn.textContent = a.label;
                  if (Dom.toast) Dom.toast(err.message || 'Something went wrong', 'error');
                  else console.error(err);
                }
              } else {
                close();
              }
            },
          }, a.label);
          footer.appendChild(btn);
        });
        modal.appendChild(footer);
      }

      backdrop.appendChild(modal);
      root.appendChild(backdrop);

      // Trap focus + wire Esc, then move focus into the dialog (prefer the first
      // field for data entry, else the first non-close button, else the dialog).
      document.addEventListener('keydown', onKeyDown, true);
      const focusables = modal.querySelectorAll('a[href],button:not([disabled]),textarea:not([disabled]),input:not([disabled]):not([type=hidden]),select:not([disabled]),[tabindex]:not([tabindex="-1"])');
      let toFocus = null;
      for (let i = 0; i < focusables.length; i++) { if (/^(INPUT|TEXTAREA|SELECT)$/.test(focusables[i].tagName)) { toFocus = focusables[i]; break; } }
      if (!toFocus) { for (let j = 0; j < focusables.length; j++) { if (!focusables[j].classList.contains('modal-close')) { toFocus = focusables[j]; break; } } }
      try { (toFocus || modal).focus(); } catch (e) {}

      return { close };
    },

    // v22p3.1: facade-level close so callers can do Shell.Modal.close() without
    // holding the handle returned by open().
    close() {
      const root = Dom.$('#modalRoot');
      if (root) Dom.clear(root);
      // Release the background scroll lock set in open().
      try { document.documentElement.style.overflow = ''; } catch (e) {}
    },

    confirm({ title, message, confirmLabel = 'Confirm', destructive = false, onConfirm }) {
      return this.open({
        title,
        body: Dom.el('p', { style: 'font-size: 15px; line-height: 1.6;' }, message),
        actions: [
          { label: 'Cancel', style: 'btn-secondary' },
          {
            label: confirmLabel,
            style: destructive ? 'btn-danger' : 'btn-primary',
            handler: onConfirm,
            busyLabel: 'Working…',
          },
        ],
      });
    },
  };

  // ──────────────── Generic helpers ────────────────────────────
  function emptyState(emoji, title, sub) {
    return Dom.el('div', { class: 'empty-large' },
      Dom.el('div', { class: 'empty-emoji' }, emoji),
      Dom.el('h3', {}, title),
      Dom.el('p', {}, sub),
    );
  }

  function statusTagFor(ratioStatus) {
    return ratioStatus === 'ok' ? { class: 'tag-success', text: 'COMPLIANT' }
      : ratioStatus === 'tight' ? { class: 'tag-warn', text: 'TIGHT' }
      : { class: 'tag-danger', text: 'BREACH' };
  }

  // ──────────────── Bootstrap ──────────────────────────────────
  async function startApp() {
    if (!Auth.requireLogin()) return;

    const user = Auth.user();
    const role = Roles.primaryRoleOf(user);

    // v17.3: Defensive cleanup — even with the early flag set, if any
    // v12/v13/v14/v15 nav-additions file is missing the guard (maybe
    // the guard patcher never ran), this observer catches injected
    // <div id="kt-v##-nav-extra"> elements and removes them as soon as
    // they're added to the sidebar. Belt-and-suspenders.
    const sidebarEl0 = Dom.$('#appSidebar');
    if (sidebarEl0 && typeof MutationObserver !== 'undefined') {
      const navObserver = new MutationObserver((mutations) => {
        for (const m of mutations) {
          for (const node of m.addedNodes) {
            if (node.nodeType === 1 && node.id && /^kt-v\d+-nav-extra$/.test(node.id)) {
              node.remove();
            }
          }
        }
      });
      navObserver.observe(sidebarEl0, { childList: true });
      // Also clean up any that snuck in before we got here
      sidebarEl0.querySelectorAll('[id^="kt-v"][id$="-nav-extra"]').forEach(n => n.remove());
    }

    // v17.2: layout class is applied in HTML by default. For non-sidebar
    // roles, we must remove BOTH the outer .app-shell--sidebar AND the
    // .app-sidebar class on the aside element — otherwise the aside's
    // vertical-column styles fight the .app-nav horizontal-row styles
    // and the top nav renders as a 100vh-tall column.
    const isSidebarRole = (role === 'agency_admin' || role === 'centre_director');
    const shell = Dom.$('#appShell');
    const sidebarEl = Dom.$('#appSidebar');

    // No resolvable role is a real state — a deactivated role_assignment, or an
    // /auth/me that answered before its roles were attached. Calling .replace() on it
    // threw during boot and left a blank shell with nothing to act on. Boot without the
    // role class instead, and say what is wrong.
    const roleClass = role ? ('role-' + role.replace('_', '-')) : null;
    if (isSidebarRole && roleClass) {
      document.body.classList.add('layout-sidebar', roleClass);
    } else {
      if (shell)     shell.classList.remove('app-shell--sidebar');
      if (sidebarEl) sidebarEl.classList.remove('app-sidebar');
      if (roleClass) { document.body.classList.add(roleClass); }
    }
    if (!role) {
      console.warn('[shell] no role resolved for this account — booting without a role scope');
      try {
        var _rw = document.createElement('div');
        _rw.style.cssText = 'background:#FEF3C7;border-bottom:1px solid #FDE68A;color:#92400E;'
          + 'padding:10px 14px;font-size:13.5px;text-align:center;';
        _rw.textContent = 'Your account does not have a role assigned yet, so parts of the app are unavailable. '
          + 'Please ask your administrator to assign one.';
        document.body.insertBefore(_rw, document.body.firstChild);
      } catch (e) {}
    }

    // Set up nav user pill
    const navAvatar = Dom.$('#navAvatar');
    const navName   = Dom.$('#navName');
    const navRole   = Dom.$('#navRole');
    if (navAvatar) {
      // v22p3.2: render photo if user.photo_url is set, fallback to initials.
      if (user && user.photo_url) {
        navAvatar.textContent = '';
        const apiBase = (window.KT && window.KT.API_BASE) || 'https://api.kiddietrac.com/api/v1';
        const apiHost = apiBase.replace(/\/api\/v1\/?$/, '');
        const src = /^https?:\/\//i.test(user.photo_url) ? user.photo_url : (apiHost + user.photo_url);
        const img = document.createElement('img');
        img.src = src;
        img.alt = user.name || '';
        img.style.cssText = 'width:100%;height:100%;object-fit:cover;border-radius:50%;display:block;';
        /* Remove via img's OWN parent, never navAvatar's.

         navAvatar.removeChild(img) throws NotFoundError whenever img is not a child
         of it right then -- which happens if the pill was re-rendered since, and on
         WebKit if error fires for an already-failed cached URL BEFORE the appendChild
         below has run. That threw during boot on iPhone and left a dead shell. */
      img.onerror = () => {
        if (img.parentNode) { img.parentNode.removeChild(img); }
        navAvatar.textContent = Fmt.initials(user && user.name);
      };
        navAvatar.appendChild(img);
      } else {
        // Not user.name: the branch above tests `user && user.photo_url`, so reaching
        // here with a null user is expected — a valid token whose cached user object is
        // missing. Calling .name on it killed boot behind a blank shell.
        navAvatar.textContent = Fmt.initials(user && user.name);
      }
    }
    // `user.name?.` guards a missing NAME, not a null USER — which is the case that
    // actually occurs here.
    if (navName)   navName.textContent   = ((user && user.name) || '').split(' ').slice(0, 2).join(' ') || 'You';
    if (navRole && role) {
      // Distinguish a platform_admin (primaryRoleOf resolves them to agency_admin)
      // from a genuine agency admin — a real agency admin was mislabelled "Platform
      // admin". home_visitor was missing → the pill showed the raw enum. Aligns with
      // kt-topbar.js's ROLE map.
      const _roles = (user.roles || []);
      const roleLabel = _roles.indexOf('platform_admin') !== -1
        ? 'Platform admin'
        : ({
            agency_admin:    'Agency admin',
            centre_director: 'Director',
            educator:        'Educator',
            guardian:        'Parent',
            home_visitor:    'Home visitor',
            sales_rep:       'Sales rep',
            auditor:         'Auditor',
          }[role] || role);
      navRole.textContent = roleLabel;
    }

    Dom.$('#navUser')?.addEventListener('click', () => {
      if (confirm('Sign out?')) Auth.logout();
    });

    buildNav(user);
    try { _injectViewAs(user); } catch (e) {}

    window.addEventListener('hashchange', renderScreen);
    renderScreen();
  }

  // Export
  window.KT.Shell = {
    registerScreen,
    renderScreen,
    navigate,
    Modal,
    Roles,
    emptyState,
    statusTagFor,
    buildNav,
    setBadge,
    startApp,
    homeHashForRole,
    /* Exposed for the admin/director launcher (screen-admin-home.js), which builds its
       tiles from the role's REAL nav rather than a second hand-written copy of the
       menu. A copy would go stale the first time a screen was added, and could offer a
       role a screen it cannot open. */
    navItemsForRole,
    // Lift any action buttons out of the current screen's banner into the toolbar
    // beneath it. Screens that RE-RENDER themselves (e.g. a Refresh button that
    // rebuilds its own hero) must call this afterwards — the shell's automatic
    // normalisation is budget-capped per visit and its observer disconnects after a
    // few seconds, so a late self-re-render would otherwise leave the buttons sitting
    // inside the banner artwork. Idempotent: a no-op once the buttons are already out.
    liftHeroButtons: function (m) {
      try { return liftBannerButtons(m || document.getElementById('appMain')); }
      catch (e) { return false; }
    },
  };
})(window);
