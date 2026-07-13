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
      if (_va && user.roles.indexOf('platform_admin') !== -1 && ['agency_admin','centre_director','educator','guardian','auditor'].indexOf(_va) !== -1) return _va;
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
      var overviewItems = [{ hash: 'dashboard', label: 'Agency overview', icon: '🏠' }, { hash: 'provider-map', label: 'Provider map', icon: '🗺️' }];
      if (!isPlatformAdmin_v22p34) overviewItems.push({ hash: 'agencies', label: 'Agencies', icon: '🏢' });
      return [
        { label: 'Overview', items: overviewItems },
        { label: 'Operations', items: [
          { hash: 'care-log',       label: 'Daily log',        icon: '📝' },

          { hash: 'chat',           label: 'Messages',         icon: '💬', badgeKey: 'chat_unread' },
          { hash: 'announcements',  label: 'Announcements',    icon: '📢', badgeKey: 'announcement_unread' },
          { hash: 'lesson-plans',   label: 'Lesson plans',     icon: '📚' },
          { hash: 'observations',   label: 'Observations',     icon: '\ud83d\udc40' },
          { hash: 'lesson-plans-ai', label: 'AI Lesson Plans',  icon: '\ud83d\udcd6' },
          { hash: 'schedule',       label: 'Schedule',         icon: '📅' },
          { hash: 'certifications', label: 'Certifications',   icon: '🎓' },
          { hash: 'timesheets',     label: 'Timesheets',       icon: '📊' },
          { hash: 'waitlist',       label: 'Waitlist',         icon: '⏳' },
          { hash: 'incidents',     label: 'Incidents',        icon: '⚠️' },
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
          { hash: 'staff-calendar',     label: 'Calendar',           icon: '📅' },
          { hash: 'time-off',           label: 'Time off requests',  icon: '🌴' },
          { hash: 'background-checks',  label: 'Background checks',  icon: '🛡' },
          { hash: 'payroll',            label: 'Payroll',            icon: '💼' },
          { hash: 'substitutes',        label: 'Substitutes',        icon: '🔄' },
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
          { hash: 'admin-features', label: 'Feature flags',    icon: '⚙️' },
          { hash: 'admin-branding', label: 'Branding',         icon: '🎨' },
          { hash: 'digest-status', label: 'AI digest status', icon: '🤖' },
        ]}] : []),
        ...(isPlatformAdmin_v22p34 ? [{ label: 'Website', items: [{ hash: 'marketing-site', label: 'Website', icon: '🌐' }] }] : []),
        { label: 'Enrollment', items: [
          { hash: 'invitation-codes', label: 'Invitation codes', icon: '✉️' },
          { hash: 'edocuments',       label: 'eDocuments',       icon: '📄' },
        ]},
        { label: 'Finance', items: [
          { hash: 'expenses',         label: 'Expenses',         icon: '🧾' },
        ]},
        { label: 'Administration', items: [
          { hash: 'admin-users',      label: 'User management',  icon: '👥' },
          { hash: 'admin-centres',    label: 'Centres / Rooms',  icon: '🏫' },
          { hash: 'admin-families',   label: 'Families',         icon: '👪' },
          { hash: 'admin-children',   label: 'Children',         icon: '🧒' },
          { hash: 'admin-billing',    label: 'Billing (Stripe)', icon: '💳' },
          { hash: 'bulk-invoices',    label: 'Bulk invoice run', icon: '💸' },
          { hash: 'admin-forms',      label: 'Custom forms',     icon: '📝' },
          { hash: 'compliance',       label: 'Compliance',       icon: '✅' },
          { hash: 'audit-logs',       label: 'Audit log',        icon: '📜' },
          { hash: 'closures',         label: 'Closures',         icon: '🗓' },
          { hash: 'late-pickups',     label: 'Late pickups',     icon: '⏰' },
          { hash: 'room-ratios',      label: 'Room ratios',      icon: '👥' },
          { hash: 'vacation-holds',   label: 'Vacation holds',   icon: '🏖' },
          { hash: 'tuition-increases',label: 'Tuition increases',icon: '📈' },
          { hash: 'bus-routes',       label: 'Bus routes',       icon: '🚐' },
          { hash: 'room-rotations',   label: 'Room rotations',   icon: '🔄' },
          { hash: 'reports',          label: 'Reports',          icon: '📋' },
          { hash: 'refunds',          label: 'Refunds',          icon: '↩' },
          { hash: 'immun-schedule',   label: 'Immunization due', icon: '💉' },
          { hash: 'cacfp',            label: 'CACFP meals',      icon: '🍽' },
          { hash: 'billing-schedule', label: 'Billing schedule', icon: '📅' },
          { hash: 'report-cards',     label: 'Report cards',    icon: '📑' },
          { hash: 'zones',            label: 'Activity zones',  icon: '🎨' },
          { hash: 'tickets',          label: 'Support tickets', icon: '🎫' },
          { hash: 'attendance-pattern',label: 'Attendance days', icon: '📅' },
          { hash: 'trip-gps',         label: 'Field trip GPS',  icon: '📍' },
          { hash: 'wellness-digest',  label: 'Wellness digest', icon: '🩺' },
          { hash: 'payment-plans',    label: 'Payment plans',   icon: '📅' },
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
        ]},
        { label: 'Settings', items: [
          { hash: 'admin-roles',        label: 'Roles & permissions', icon: '🛡' },
          { hash: 'sibling-discounts',  label: 'Sibling discounts',   icon: '👨‍👩‍👧' },
          { hash: 'tuition-plans',      label: 'Tuition plans',       icon: '💵' },
          { hash: 'billing-setup',      label: 'Billing',             icon: '💳' },
          { hash: 'email-settings',     label: 'Email settings',      icon: '✉️' },
          { hash: 'quickbooks',         label: 'QuickBooks (Intuit)', icon: '📒' },
          { hash: 'language',           label: 'Language',            icon: '🌐' },

          { hash: 'notifications', label: 'Notifications', icon: '🔔' },


          { hash: 'data-retention',     label: 'Data retention & compliance', icon: '🗄️' },
          { hash: 'mfa',                label: 'Two-factor (MFA)',    icon: '🔐' },
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
        { label: 'Operations', items: [
          { hash: 'today',          label: 'Today',            icon: '✨' },
          { hash: 'care-log',       label: 'Daily log',        icon: '📝' },

          { hash: 'chat',           label: 'Messages',         icon: '💬', badgeKey: 'chat_unread' },
          { hash: 'announcements',  label: 'Announcements',    icon: '📢', badgeKey: 'announcement_unread' },
          { hash: 'lesson-plans',   label: 'Lesson plans',     icon: '📚' },
          { hash: 'observations',   label: 'Observations',     icon: '\ud83d\udc40' },
          { hash: 'lesson-plans-ai', label: 'AI Lesson Plans',  icon: '\ud83d\udcd6' },
          { hash: 'schedule',       label: 'Schedule',         icon: '📅' },
          { hash: 'certifications', label: 'Certifications',   icon: '🎓' },
          { hash: 'timesheets',     label: 'Timesheets',       icon: '📊' },
          { hash: 'audit-logs',     label: 'Audit log',        icon: '📜' },
          { hash: 'waitlist',       label: 'Waitlist',         icon: '⏳' },
          { hash: 'incidents',     label: 'Incidents',        icon: '⚠️' },
          { hash: 'medications',   label: 'Medications',      icon: '💊' },
          { hash: 'immunizations', label: 'Immunizations',    icon: '🩹' },
          { hash: 'digest-status', label: 'AI digest status', icon: '🤖' },
        ]},
        { label: 'Growth', items: [
          { hash: 'marketing-campaigns', label: 'Marketing',  icon: '📣' },
          { hash: 'tours',              label: 'Tours',       icon: '🚪' },
          { hash: 'sms',                label: 'SMS broadcast', icon: '📱' },
          { hash: 'ai-churn',           label: 'Churn risk',  icon: '📉' },
          { hash: 'ai-docs',            label: 'AI doc extract', icon: '🪄' },
        ]},
        { label: 'Staff', items: [
          { hash: 'staff-calendar',    label: 'Calendar',           icon: '📅' },
          { hash: 'time-clock',        label: 'Time clock',         icon: '⏱' },
          { hash: 'time-off',          label: 'Time off requests',  icon: '🌴' },
          { hash: 'background-checks', label: 'Background checks',  icon: '🛡' },
          { hash: 'payroll',           label: 'Payroll',            icon: '💼' },
          { hash: 'substitutes',       label: 'Substitutes',        icon: '🔄' },
        ]},
        { label: 'Programs', items: [
          { hash: 'menu',              label: 'Weekly menu',     icon: '🍽' },
          { hash: 'field-trips',       label: 'Field trips',     icon: '🚐' },
          { hash: 'allergy-alerts',    label: 'Allergy alerts',  icon: '⚠' },
        ]},
        { label: 'Compliance', items: [
          { hash: 'inspection',        label: 'Inspection checklist', icon: '✅' },
          { hash: 'renewals',          label: 'Renewals calendar',    icon: '📅' },
          { hash: 'cwelcc',            label: 'CWELCC subsidies',  icon: '💵' },
          { hash: 'retention',         label: 'Retention',        icon: '📊' },
          { hash: 'forecast',          label: 'Enrolment forecast',icon: '📈' },
          { hash: 'anomalies',         label: 'Anomalies',        icon: '🔍' },
        ]},
        { label: 'Enrollment', items: [
          { hash: 'invitation-codes', label: 'Invitation codes', icon: '✉️' },
          { hash: 'edocuments',       label: 'eDocuments',       icon: '📄' },
        ]},
        { label: 'Settings', items: [
          { hash: 'billing-setup', label: 'Billing reminders', icon: '💳' },

          { hash: 'notifications', label: 'Notifications', icon: '🔔' },


          { hash: 'mfa',              label: 'Two-factor (MFA)', icon: '🔐' },
          { hash: 'language',         label: 'Language',         icon: '🌐' },
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
          // v22p98: educators clock in/out here — needed for ratio compliance,
          // payroll and reporting. The time-clock screen was already built and
          // registered for the educator role; it was just missing from this nav.
          { hash: 'time-clock',    label: 'Clock in/out',  icon: '⏱' },
          { hash: 'care-log',      label: 'Daily log',     icon: '✅' },
          { hash: 'observations',  label: 'Observations',  icon: '👀' },
          { hash: 'lesson-plans',  label: 'Lesson plans',  icon: '📚' },
          { hash: 'chat',          label: 'Messages',      icon: '💬', badgeKey: 'chat_unread' },
          { hash: 'incidents',     label: 'Incidents',     icon: '⚠️' },
          { hash: 'medications',   label: 'Medications',   icon: '💊' },
          { hash: 'announcements', label: 'News',          icon: '📢' },
          { hash: 'time-off',      label: 'Time off',      icon: '🌴' },
        ]},
        { label: 'Account', items: [
          { hash: 'notifications', label: 'Notifications', icon: '🔔' },
          { hash: 'mfa',           label: 'Two-factor',    icon: '🔐' },
          { hash: 'help',          label: 'Help',          icon: '📖' },
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
        { hash: 'photos',             label: 'Photos',     icon: '📸' },
        { hash: 'messages',           label: 'Messages',   icon: '💬', badgeKey: 'chat_unread' },
        { hash: 'checkin',            label: 'Check-in',   icon: '☀' },
        { hash: 'parent-forms',       label: 'Forms',      icon: '📝' },
        { hash: 'billing',            label: 'Billing',    icon: '💳' },
        { hash: 'attendance-pattern', label: 'Attendance', icon: '📅' },
        { hash: 'medications',        label: 'Health',     icon: '💊' },
        { hash: 'announcements',      label: 'News',       icon: '📢' },
      ]},
      { label: 'Account', items: [
        { hash: 'notifications', label: 'Notifications', icon: '🔔' },
        { hash: 'mfa',           label: 'Two-factor',    icon: '🔐' },
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
      class: 'nav-link',
      'data-hash': item.hash,
    });
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
    return { icon: '', label: base.replace(/-/g, ' ').replace(/\b\w/g, function (c) { return c.toUpperCase(); }), section: '' };
  }
  function buildAutoHero(info) {
    var b = document.createElement('div'); b.className = 'kt-hero kt-hero-auto';
    var lbl = String(info.label || '').replace(/[<>&]/g, '');
    var sect = String(info.section || '').replace(/[<>&]/g, '');
    var emoji = String(info.icon || '\u2728').replace(/[<>&]/g, '');
    // Only show the eyebrow when the section adds information. It used to fall
    // back to the label, so any screen with no nav section (Settings, Dashboard)
    // printed its own name twice — "Settings" above "Settings".
    // "Menu"/"Main" are catch-all nav section names, not information — and with
    // the Menu button gone from the mobile bar, an eyebrow reading "MENU" is
    // actively confusing.
    if (/^(menu|main|general|other)$/i.test(sect)) sect = '';
    var greet = (sect && sect.toLowerCase() !== lbl.toLowerCase())
      ? '<div class="kt-hero-greet">' + (info.icon ? info.icon + ' ' : '') + sect + '</div>'
      : '';
    b.innerHTML = greet + '<h1>' + lbl + '</h1>' + '<div class="kt-hero-emoji" aria-hidden="true">' + emoji + '</div>';
    return b;
  }

  var _hashStack = [];
  function _trackNav(h) {
    if (_hashStack.length > 1 && _hashStack[_hashStack.length - 2] === h) { _hashStack.pop(); }
    else if (_hashStack[_hashStack.length - 1] !== h) { _hashStack.push(h); }
  }
  window.ktBack = function () {
    if (_hashStack.length > 1) { var prev = _hashStack[_hashStack.length - 2]; window.location.hash = (prev.charAt(0) === '#' ? prev : '#' + prev); }
    else { try { window.location.hash = '#' + homeHashForRole(Roles.primaryRoleOf(Auth.user())); } catch (e) { window.location.hash = '#dashboard'; } }
  };
  document.addEventListener('click', function (e) {
    var el = e.target && e.target.closest ? e.target.closest('a,button,[data-back]') : null;
    if (!el) return;
    var t = (el.textContent || '').trim();
    // Only treat a leading arrow as "back" when it's essentially the whole label
    // (a ‹ / ← button) — not any button that happens to start with one.
    if (el.hasAttribute('data-back') || (/^[←‹⟵⬅⭠]/.test(t) && t.length <= 8)) {
      e.preventDefault(); e.stopPropagation();
      // Overlay-aware: closes an open thread/compose/invoice back to the list
      // it sits on, and only navigates the hash when nothing is stacked.
      if (window.KT && KT.goBack) KT.goBack(); else window.ktBack();
    }
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
    var roles = [['', 'Super admin (default)'], ['agency_admin', '\uD83C\uDFE2 Agency admin'], ['centre_director', '\uD83C\uDFEB Centre director'], ['educator', '\uD83C\uDF93 Educator'], ['guardian', '\uD83D\uDC6A Parent / guardian'], ['auditor', '\uD83D\uDD0D Auditor']];
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

  async function renderScreen() {
    const main = Dom.$('#appMain');
    // Reset scroll to the top BEFORE we clear + render. If we only reset after
    // render, the shell's hashchange listener (registered before kt-mobilenav's)
    // paints the new, tall screen at the OLD scroll position for a frame first —
    // that's the "shows the bottom, then jumps to the top" flash. Resetting here,
    // while the old content is still in place, means the new screen paints at 0.
    try {
      window.scrollTo(0, 0);
      if (document.scrollingElement) document.scrollingElement.scrollTop = 0;
      if (document.documentElement) document.documentElement.scrollTop = 0;
      if (document.body) document.body.scrollTop = 0;
      if (main) main.scrollTop = 0;
    } catch (e) {}
    Dom.clear(main);
    try { if (window.__ktBannerObs) window.__ktBannerObs.disconnect(); } catch (e) {}

    const user = Auth.user();
    const role = Roles.primaryRoleOf(user);
    const hash = (window.location.hash || ('#' + homeHashForRole(role))).replace('#', '').split('?')[0];
    _trackNav(hash);

    updateActiveNav();

    // Route based on role + hash
    const screenKey = `${role}:${hash}`;
    const fallbackKey = `${role}:dashboard`;

    const fn = screens[screenKey] || screens[fallbackKey] || screens['guardian:today'];

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
        window.scrollTo(0, 0);
        if (document.scrollingElement) document.scrollingElement.scrollTop = 0;
        if (document.documentElement) document.documentElement.scrollTop = 0;
        if (document.body) document.body.scrollTop = 0;
        main.scrollTop = 0;
        requestAnimationFrame(function () { try { window.scrollTo(0, 0); if (document.scrollingElement) document.scrollingElement.scrollTop = 0; } catch (e) {} });
      } catch (e) {}
      try {
        var __info = navItemFor(role, hash);
        // A section already has a banner if it uses a known hero class OR renders
        // its own custom gradient banner near the top (some screens, e.g. Tours,
        // build an inline-styled banner with no hero class). Detect both so we
        // never stack a second auto-hero on top → no double banners anywhere.
        var __hasHero = function () {
          if (main.querySelector('.kt-hero, .kt-page-hero, .page-header-v17')) return true;
          var f = main.firstElementChild;
          var cands = [f, f && f.firstElementChild];
          for (var ci = 0; ci < cands.length; ci++) {
            var el = cands[ci]; if (!el || el.classList.contains('kt-hero-auto')) continue;
            try { if ((getComputedStyle(el).backgroundImage || '').indexOf('gradient') !== -1 && el.getBoundingClientRect().height > 60) return true; } catch (e) {}
          }
          return false;
        };
        var __ensure = function () { if (!__hasHero()) { main.insertBefore(buildAutoHero(__info), main.firstChild); } };
        __ensure();
        if (window.MutationObserver) { var __obs = new MutationObserver(__ensure); window.__ktBannerObs = __obs; __obs.observe(main, { childList: true }); setTimeout(function () { __obs.disconnect(); }, 4000); }
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

      const close = () => {
        Dom.clear(root);
        if (onClose) onClose();
      };

      const backdrop = Dom.el('div', {
        class: 'modal-backdrop',
        onClick: (e) => { if (e.target === backdrop) close(); },
      });

      const modal = Dom.el('div', { class: 'modal' + (large ? ' modal-large' : '') });

      // Header
      const header = Dom.el('div', { class: 'modal-header' });
      header.appendChild(Dom.el('h2', {}, title));
      header.appendChild(Dom.el('button', {
        class: 'modal-close',
        onClick: close,
      }, '×'));
      modal.appendChild(header);

      // Body
      const bodyEl = Dom.el('div', { class: 'modal-body' });
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

      return { close };
    },

    // v22p3.1: facade-level close so callers can do Shell.Modal.close() without
    // holding the handle returned by open().
    close() {
      const root = Dom.$('#modalRoot');
      if (root) Dom.clear(root);
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
    if (isSidebarRole) {
      document.body.classList.add('layout-sidebar', 'role-' + role.replace('_', '-'));
    } else {
      if (shell)     shell.classList.remove('app-shell--sidebar');
      if (sidebarEl) sidebarEl.classList.remove('app-sidebar');
      document.body.classList.add('role-' + role.replace('_', '-'));
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
        img.onerror = () => { navAvatar.removeChild(img); navAvatar.textContent = Fmt.initials(user.name); };
        navAvatar.appendChild(img);
      } else {
        navAvatar.textContent = Fmt.initials(user.name);
      }
    }
    if (navName)   navName.textContent   = user.name?.split(' ').slice(0, 2).join(' ') || 'You';
    if (navRole && role) {
      const roleLabel = {
        agency_admin:    'Platform admin',
        centre_director: 'Director',
        educator:        'Educator',
        guardian:        'Parent',
        auditor:         'Auditor',
      }[role] || role;
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
  };
})(window);
