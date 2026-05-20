/* Kiddietrac v21.1 legacy-shim
   Bridges the v17 shell router to the older screen modules that expose
   window.KT.X.render(container) instead of calling Shell.registerScreen.
   Without this, the shell falls back to <role>:dashboard for unregistered
   hashes and a race with the v12-v15 hashchange dispatchers determines
   whether the user sees the intended screen or the dashboard. */
(function (window) {
  'use strict';
  if (!window.KT || !window.KT.Shell || !window.KT.Shell.registerScreen) {
    console.warn('legacy-shim: KT.Shell.registerScreen not available');
    return;
  }
  var Shell = window.KT.Shell;

  function bridge(globalPath, methodName) {
    var fn = function (main) {
      var parts = globalPath.split('.');
      var obj = window;
      for (var i = 0; i < parts.length; i++) {
        obj = obj && obj[parts[i]];
      }
      if (!obj || typeof obj[methodName] !== 'function') {
        main.innerHTML = '<div style="padding:24px;color:#6B7280;">Screen module ' + globalPath + ' not loaded yet.</div>';
        return;
      }
      return obj[methodName](main);
    };
    return fn;
  }

  function reg(roles, hash, globalPath, methodName) {
    var fn = bridge(globalPath, methodName || 'render');
    roles.forEach(function (r) { Shell.registerScreen(r + ':' + hash, fn); });
  }

  var allStaff   = ['agency_admin', 'centre_director', 'educator'];
  var directors  = ['agency_admin', 'centre_director'];
  var adminOnly  = ['agency_admin'];
  var allRoles   = ['agency_admin', 'centre_director', 'educator', 'guardian'];

  // Agency-admin only
  reg(adminOnly, 'agencies',       'KT.Agencies');
  reg(adminOnly, 'admin-mrr',      'KT.AdminMrr');
  reg(adminOnly, 'admin-features', 'KT.AdminFeatures');
  reg(adminOnly, 'admin-branding', 'KT.AdminBranding');

  // Director + admin
  reg(directors, 'schedule',       'KT.Schedule');
  reg(directors, 'certifications', 'KT.Certifications');
  reg(directors, 'timesheets',     'KT.Timesheets');
  reg(directors, 'waitlist',       'KT.Waitlist');

  // Staff (educator + director + admin)
  reg(allStaff,  'announcements',  'KT.Announcements');
  reg(allStaff,  'lesson-plans',   'KT.LessonPlans');

  // v22p34: marketing campaigns — directors + agency admins
  reg(directors, 'marketing-campaigns', 'KT.Marketing');

  // v22p37: staff calendar — directors + agency admins
  reg(directors, 'staff-calendar', 'KT.StaffCalendar');

  // v22p39: audit log viewer — agency_admin + platform_admin
  reg(adminOnly, 'audit-logs', 'KT.AuditLogs');

  // v22p42: bulk invoice run — agency_admin
  reg(adminOnly, 'bulk-invoices', 'KT.BulkInvoices');

  // v22p43: custom forms builder — directors + admins
  reg(directors, 'admin-forms', 'KT.Forms');

  // v22p46: notifications inbox — every authenticated role. The backend
  // route was promoted to /notifications (was /parent/notifications) so
  // staff can use the same UI.
  reg(allRoles, 'notifications', 'KT.Notifications');

  // v22p47: compliance dashboard — directors + admins
  reg(directors, 'compliance', 'KT.Compliance');

  // v22p49: care logs, time clock, milestones, portfolio, tours
  ['agency_admin','centre_director','educator'].forEach(function (r) {
    Shell.registerScreen(r + ':time-clock', bridge('KT.Care', 'renderTimeClock'));
    Shell.registerScreen(r + ':care-log',   bridge('KT.Care', 'renderCareLog'));
  });
  Shell.registerScreen('guardian:care-log', bridge('KT.Care', 'renderCareLog'));
  ['agency_admin','centre_director','educator','guardian'].forEach(function (r) {
    Shell.registerScreen(r + ':portfolio',  bridge('KT.Care', 'renderPortfolio'));
    Shell.registerScreen(r + ':milestones', bridge('KT.Care', 'renderMilestones'));
  });
  ['agency_admin','centre_director'].forEach(function (r) {
    Shell.registerScreen(r + ':tours', bridge('KT.Care', 'renderTours'));
  });

  // Chat exposes mount(container), not render
  ['agency_admin', 'centre_director', 'educator'].forEach(function (r) {
    Shell.registerScreen(r + ':chat', bridge('KT.Chat', 'mount'));
  });

  // Guardian extras
  Shell.registerScreen('guardian:autopay',       bridge('KT.Autopay', 'render'));
  Shell.registerScreen('guardian:announcements', bridge('KT.Announcements', 'render'));
  Shell.registerScreen('guardian:lesson-plans',  bridge('KT.LessonPlans', 'render'));

// v22p51 — paste BEFORE the closing })(window) in screen-legacy-shim.js

  // v22p51: time off (every staff role + parent submits / staff approve)
  ['agency_admin','centre_director','educator','guardian','platform_admin'].forEach(function (r) {
    Shell.registerScreen(r + ':time-off', bridge('KT.V22p51', 'renderTimeOff'));
  });
  // background checks, payroll, agency-billing config, SMS, AI tools — admin+director
  ['agency_admin','centre_director','platform_admin'].forEach(function (r) {
    Shell.registerScreen(r + ':background-checks', bridge('KT.V22p51', 'renderBackgroundChecks'));
    Shell.registerScreen(r + ':payroll',           bridge('KT.V22p51', 'renderPayroll'));
    Shell.registerScreen(r + ':sms',               bridge('KT.V22p51', 'renderSms'));
    Shell.registerScreen(r + ':ai-churn',          bridge('KT.V22p51', 'renderAiChurn'));
    Shell.registerScreen(r + ':ai-docs',           bridge('KT.V22p51', 'renderAiDocs'));
  });
  // agency-billing settings — agency_admin only
  Shell.registerScreen('platform_admin:agency-billing', bridge('KT.V22p51', 'renderAgencyBilling'));
  Shell.registerScreen('agency_admin:agency-billing', bridge('KT.V22p51', 'renderAgencyBilling'));
  // parent autopay
  Shell.registerScreen('guardian:autopay-card', bridge('KT.V22p51', 'renderAutopay'));
  // locale picker - everyone
  ['agency_admin','centre_director','educator','guardian','platform_admin'].forEach(function (r) {
    Shell.registerScreen(r + ':language', bridge('KT.V22p51', 'renderLocale'));
  });


  // v22p53: operations + compliance + AI v2
  ['agency_admin','centre_director','platform_admin'].forEach(function (r) {
    Shell.registerScreen(r + ':menu',            bridge('KT.V22p53', 'renderMenu'));
    Shell.registerScreen(r + ':allergy-alerts',  bridge('KT.V22p53', 'renderAllergyAlerts'));
    Shell.registerScreen(r + ':field-trips',     bridge('KT.V22p53', 'renderFieldTrips'));
    Shell.registerScreen(r + ':substitutes',     bridge('KT.V22p53', 'renderSubstitutes'));
    Shell.registerScreen(r + ':inspection',      bridge('KT.V22p53', 'renderInspection'));
    Shell.registerScreen(r + ':cwelcc',          bridge('KT.V22p53', 'renderCwelcc'));
    Shell.registerScreen(r + ':retention',       bridge('KT.V22p53', 'renderRetention'));
    Shell.registerScreen(r + ':forecast',        bridge('KT.V22p53', 'renderForecast'));
    Shell.registerScreen(r + ':anomalies',       bridge('KT.V22p53', 'renderAnomalies'));
    Shell.registerScreen(r + ':renewals',        bridge('KT.V22p53', 'renderExpiryCalendar'));
  });

  // v22p55 — rewrite + new screens
  ['agency_admin','centre_director','platform_admin'].forEach(function (r) {
    Shell.registerScreen(r + ':allergy-alerts', bridge('KT.V22p55', 'renderAllergyAlerts'));
    Shell.registerScreen(r + ':forecast',       bridge('KT.V22p55', 'renderForecast'));
    Shell.registerScreen(r + ':ai-churn',       bridge('KT.V22p55', 'renderAiChurn'));
    Shell.registerScreen(r + ':retention',      bridge('KT.V22p55', 'renderRetention'));
    Shell.registerScreen(r + ':doc-autolink',   bridge('KT.V22p55', 'renderDocAutolink'));
    Shell.registerScreen(r + ':feedback',       bridge('KT.V22p55', 'renderFeedback'));
  });
  ['agency_admin','centre_director','educator','guardian','platform_admin'].forEach(function (r) {
    Shell.registerScreen(r + ':photos', bridge('KT.V22p55', 'renderPhotoFeed'));
  });


  // v22p56 — Procare-parity screens
  ['agency_admin','centre_director','platform_admin'].forEach(function (r) {
    Shell.registerScreen(r + ':closures',          bridge('KT.V22p56', 'renderClosures'));
    Shell.registerScreen(r + ':late-pickups',      bridge('KT.V22p56', 'renderLatePickup'));
    Shell.registerScreen(r + ':room-ratios',       bridge('KT.V22p56', 'renderRoomRatios'));
    Shell.registerScreen(r + ':vacation-holds',    bridge('KT.V22p56', 'renderVacationHolds'));
    Shell.registerScreen(r + ':tuition-increases', bridge('KT.V22p56', 'renderTuitionIncreases'));
    Shell.registerScreen(r + ':reenrollment',      bridge('KT.V22p56', 'renderReenrollment'));
    Shell.registerScreen(r + ':engagement',        bridge('KT.V22p56', 'renderEngagement'));
    Shell.registerScreen(r + ':nps',               bridge('KT.V22p56', 'renderNps'));
    Shell.registerScreen(r + ':signed-docs',       bridge('KT.V22p56', 'renderSignedDocs'));
    Shell.registerScreen(r + ':bus-routes',        bridge('KT.V22p56', 'renderBusRoutes'));
    Shell.registerScreen(r + ':room-rotations',    bridge('KT.V22p56', 'renderRoomRotations'));
  });
  ['guardian'].forEach(function (r) {
    Shell.registerScreen(r + ':vacation-holds', bridge('KT.V22p56', 'renderVacationHolds'));
    Shell.registerScreen(r + ':signed-docs',    bridge('KT.V22p56', 'renderSignedDocs'));
  });


  // v22p57 — combined features
  ['guardian'].forEach(function (r) {
    Shell.registerScreen(r + ':pickup-auth', bridge('KT.V22p57', 'renderPickupAuth'));
    Shell.registerScreen(r + ':checkin',     bridge('KT.V22p57', 'renderCheckin'));
    Shell.registerScreen(r + ':trends',      bridge('KT.V22p57', 'renderTrends'));
    Shell.registerScreen(r + ':referrals',   bridge('KT.V22p57', 'renderReferrals'));
    Shell.registerScreen(r + ':ach-pay',     bridge('KT.V22p57', 'renderAch'));
  });
  ['agency_admin','centre_director','platform_admin','educator'].forEach(function (r) {
    Shell.registerScreen(r + ':drip-campaigns', bridge('KT.V22p57', 'renderDrip'));
    Shell.registerScreen(r + ':curriculum',     bridge('KT.V22p57', 'renderCurriculum'));
    Shell.registerScreen(r + ':hdlh-gaps',      bridge('KT.V22p57', 'renderHdlhGaps'));
    Shell.registerScreen(r + ':trends',         bridge('KT.V22p57', 'renderTrends'));
  });


  // v22p58 — Procare-gap features
  ['guardian'].forEach(function (r) {
    Shell.registerScreen(r + ':wallet', bridge('KT.V22p58', 'renderWallet'));
    Shell.registerScreen(r + ':ledger', bridge('KT.V22p58', 'renderLedger'));
    Shell.registerScreen(r + ':videos', bridge('KT.V22p58', 'renderVideoFeed'));
  });
  ['agency_admin','centre_director','platform_admin','educator'].forEach(function (r) {
    Shell.registerScreen(r + ':refunds',         bridge('KT.V22p58', 'renderRefunds'));
    Shell.registerScreen(r + ':reports',         bridge('KT.V22p58', 'renderReports'));
    Shell.registerScreen(r + ':videos',          bridge('KT.V22p58', 'renderVideoFeed'));
    Shell.registerScreen(r + ':immun-schedule',  bridge('KT.V22p58', 'renderImmunSchedule'));
    Shell.registerScreen(r + ':cacfp',           bridge('KT.V22p58', 'renderCacfp'));
    Shell.registerScreen(r + ':billing-schedule',bridge('KT.V22p58', 'renderBillingSchedule'));
  });


  // v22p59
  ['guardian'].forEach(function (r) {
    Shell.registerScreen(r + ':directory',         bridge('KT.V22p59', 'renderDirectory'));
    Shell.registerScreen(r + ':conferences',       bridge('KT.V22p59', 'renderConferences'));
    Shell.registerScreen(r + ':trip-gps',          bridge('KT.V22p59', 'renderTripGps'));
    Shell.registerScreen(r + ':attendance-pattern',bridge('KT.V22p59', 'renderAttendancePattern'));
    Shell.registerScreen(r + ':tickets',           bridge('KT.V22p59', 'renderTickets'));
  });
  ['agency_admin','centre_director','educator','platform_admin'].forEach(function (r) {
    Shell.registerScreen(r + ':conferences',       bridge('KT.V22p59', 'renderConferences'));
    Shell.registerScreen(r + ':trip-gps',          bridge('KT.V22p59', 'renderTripGps'));
    Shell.registerScreen(r + ':attendance-pattern',bridge('KT.V22p59', 'renderAttendancePattern'));
    Shell.registerScreen(r + ':report-cards',      bridge('KT.V22p59', 'renderReportCards'));
    Shell.registerScreen(r + ':zones',             bridge('KT.V22p59', 'renderZones'));
    Shell.registerScreen(r + ':tickets',           bridge('KT.V22p59', 'renderTickets'));
    Shell.registerScreen(r + ':photo-tagging',     bridge('KT.V22p59', 'renderPhotoTagging'));
  });

})(window);
