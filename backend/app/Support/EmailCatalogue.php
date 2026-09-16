<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Every email KiddieTrac can send, in one reviewable list.
 *
 * Anthony, 2026-08-27: "create a repository of all email templates that are sent out based
 * on how the system is designed, accessible in the email templates section under settings,
 * that can be reviewed from time to time with a preview."
 *
 * The Settings editor covered four templates. The system actually composes 66 branded
 * emails across 30 files, so "what does this system send?" had no answer short of reading
 * the codebase — which meant nobody reviewed the wording of anything except those four.
 *
 * Two rules this file lives by:
 *
 *  1. EVERY entry names a real `source` (file:line of its EmailTemplate::wrap call). It was
 *     built from a scan of the code, not from memory, and `email:catalogue --check` walks
 *     the code again and fails if a wrap site exists in a file no entry points at. A
 *     catalogue that quietly goes stale is worse than none, because people trust it.
 *
 *  2. `preview` is honest about what can be SHOWN:
 *       'render' — the body lives in the editable registry; it renders exactly.
 *       'sample' — the sender takes a preview address, so a REAL one can be emailed.
 *       'none'   — the body is composed inline at send time. Documented, not invented:
 *                  a made-up preview of an email you rely on is worse than no preview.
 */
final class EmailCatalogue
{
    public const AUDIENCES = [
        'parents'   => 'Parents & guardians',
        'staff'     => 'Educators & staff',
        'office'    => 'Admins & directors',
        'platform'  => 'Platform & account',
    ];

    /**
     * @return array<int,array<string,mixed>>
     */
    public static function all(): array
    {
        return [
            // ── Parents & guardians ──────────────────────────────────────────
            [
                'key' => 'attendance-days-updated', 'audience' => 'parents',
                'name' => 'Attendance days updated by a parent',
                'fires' => 'When a PARENT changes the days their child is expected. Staff edits are not mailed — the office is already where that is decided, and a director applying a bulk pattern across a roster would send dozens.',
                'to' => "The centre's directors and agency admins, plus a copy to the parent who made the change",
                'subject' => 'Attendance days updated — {child}',
                'source' => 'Http/Controllers/Api/AttendancePatternController.php:138',
                'preview' => 'none',
            ],
            [
                'key' => 'payment-receipt', 'audience' => 'parents',
                'name' => 'Payment received',
                'fires' => 'When a payment settles — live, or when payments:reconcile catches one the webhook missed.',
                'to' => 'The payer',
                'subject' => 'Payment received — {amount}',
                'source' => 'Services/PaymentNotifier.php:309',
                'preview' => 'none',
            ],
            [
                'key' => 'payment-failed-parent', 'audience' => 'parents',
                'name' => 'Your payment did not go through',
                'fires' => 'When a payment is declined or fails. Nothing has been taken.',
                'to' => 'The payer',
                'subject' => 'Your payment did not go through',
                'source' => 'Services/PaymentNotifier.php:309',
                'preview' => 'none',
            ],

            [
                'key' => 'incident-report-share', 'audience' => 'parents',
                'name' => 'An incident report about your child',
                'fires' => 'When a director shares an incident report, and to staff as a copy.',
                'to' => 'The child\'s guardians (staff CC\'d), or staff alone',
                'subject' => 'About {child} today  ·  ACTION NEEDED: incident report — {child}',
                'source' => 'Http/Controllers/Api/IncidentController.php:982',
                'preview' => 'none',
            ],

            [
                'key' => 'parent-daily-summary', 'audience' => 'parents',
                'name' => 'Your child\'s day',
                'fires' => 'Every evening at 18:30, agency time, for each child who attended.',
                'to' => 'Every guardian of the child',
                'subject' => 'Your child\'s day at {centre}',
                'source' => 'Console/Commands/ParentDailySummaryCommand.php:932',
                'preview' => 'render', 'registry' => 'parent-daily-summary',
            ],
            [
                'key' => 'checkin-reminder', 'audience' => 'parents',
                'name' => 'Has your child arrived?',
                'fires' => 'Mid-morning, when a child expected today has not been signed in.',
                'to' => 'Guardians of the missing child',
                'subject' => '🕘 Has {child} arrived?',
                'source' => 'Console/Commands/CheckinReminderCommand.php:182',
                'preview' => 'none',
            ],
            [
                'key' => 'check-event', 'audience' => 'parents',
                'name' => 'Signed in / signed out',
                'fires' => 'The moment a child is checked in or out, if the agency has it switched on.',
                'to' => 'Guardians authorised for pickup',
                'subject' => '✅ {child} arrived — {time}',
                'source' => 'Services/CheckEventNotifier.php:250',
                'preview' => 'none',
            ],
            [
                'key' => 'absence-reported', 'audience' => 'parents',
                'name' => 'Absence recorded',
                'fires' => 'When an absence is reported, and again to confirm it was received.',
                'to' => 'Guardians, and the child\'s educator',
                'subject' => 'Absence recorded for {child}',
                'source' => 'Http/Controllers/Api/AbsenceController.php:196, :280',
                'preview' => 'none',
            ],
            [
                'key' => 'birthday', 'audience' => 'parents',
                'name' => 'Birthday greeting',
                'fires' => 'On the child\'s birthday, if the agency has birthday emails on.',
                'to' => 'Guardians',
                'subject' => 'Happy birthday, {child}!',
                'source' => 'Console/Commands/BirthdayEmailCommand.php:199',
                'preview' => 'sample',
            ],
            [
                'key' => 'closure-notice', 'audience' => 'parents',
                'name' => 'We will be closed',
                'fires' => 'When a closure is recorded, and again as a reminder the day before.',
                'to' => 'Guardians at the affected provider',
                'subject' => 'We will be closed on {date} — {centre}',
                'source' => 'Http/Controllers/Api/OperationsV2Controller.php:207, Console/Commands/ClosureReminderCommand.php:237',
                'preview' => 'none',
            ],
            [
                'key' => 'invoice', 'audience' => 'parents',
                'name' => 'Your invoice',
                'fires' => 'When an invoice is issued, manually or by the billing run.',
                'to' => 'The payer on the account',
                'subject' => 'Invoice {number}',
                'source' => 'Console/Commands/BillingRun.php:131, Http/Controllers/Api/PayeeInvoiceController.php:351',
                'preview' => 'none',
            ],
            [
                'key' => 'account-statement', 'audience' => 'parents',
                'name' => 'Your account statement',
                'fires' => 'When an admin sends a statement from the account ledger.',
                'to' => 'An address typed into the send dialog — never defaulted',
                'subject' => 'Your account statement — {agency} ({date})',
                'source' => 'Http/Controllers/Api/AccountLedgerController.php:1076',
                'preview' => 'none',
            ],
            [
                'key' => 'invoice-copy', 'audience' => 'parents',
                'name' => 'A copy of one invoice',
                'fires' => 'From the kebab on a scheduled payment in the account ledger.',
                'to' => 'An address typed into the send dialog — never defaulted',
                'subject' => 'Invoice {number} — {agency}',
                'source' => 'Http/Controllers/Api/AccountLedgerController.php:907',
                'preview' => 'none',
            ],
            [
                'key' => 'payslip', 'audience' => 'staff',
                'name' => 'Your payslip',
                'fires' => 'From the kebab on a payroll document, on the payroll screen.',
                'to' => 'An address typed into the send dialog — never defaulted',
                'subject' => '{Payslip|Payroll invoice} — {period} — {agency}',
                'source' => 'Http/Controllers/Api/PayrollDocumentController.php:160',
                'preview' => 'none',
            ],
            [
                'key' => 'billing-reminder', 'audience' => 'parents',
                'name' => 'Payment reminder',
                'fires' => 'On the reminder schedule set under Billing → Reminders. Master switch is OFF by default.',
                'to' => 'Payers with an unpaid invoice',
                'subject' => 'Invoice {number} — payment reminder',
                'source' => 'Console/Commands/PlatformInvoiceReminders.php:127',
                'preview' => 'none',
            ],
            [
                'key' => 'onboarding-welcome', 'audience' => 'parents',
                'name' => 'Welcome — set up your account',
                'fires' => 'When a guardian is added, and from the family record\'s Send welcome button.',
                'to' => 'The new guardian',
                'subject' => 'Welcome to KiddieTrac — set up your family account',
                'source' => 'Support/EmailTemplates.php:193',
                'preview' => 'render', 'registry' => 'onboarding-welcome',
            ],
            [
                'key' => 'onboarding-reminder', 'audience' => 'parents',
                'name' => 'Finish setting up your account',
                'fires' => 'Daily, to guardians invited but not yet onboarded.',
                'to' => 'Guardians with an unfinished profile',
                'subject' => 'Reminder: finish setting up your {agency} account',
                'source' => 'Console/Commands/OnboardingReminderCommand.php:164',
                'preview' => 'none',
            ],
            [
                'key' => 'provider-welcome', 'audience' => 'parents',
                'name' => 'Meet your provider',
                'fires' => 'From the family record — the warm introduction with the educator\'s bio, photo and escalation path.',
                'to' => 'Guardians, CC admin, director and educator',
                'subject' => 'Welcome to {centre}',
                'source' => 'Http/Controllers/Api/AdminController.php:4159',
                'preview' => 'sample',
            ],
            [
                'key' => 'family-departure', 'audience' => 'parents',
                'name' => 'Leaving — records, access and balance',
                'fires' => 'When a family is de-enrolled. Itemises anything still owed, because their portal closes moments later.',
                'to' => 'Every guardian, BCC the office',
                'subject' => 'Leaving {agency} — your records and access',
                'source' => 'Http/Controllers/Api/AdminController.php:4014',
                'preview' => 'none',
            ],
            [
                'key' => 'change-of-provider', 'audience' => 'parents',
                'name' => 'A change of provider',
                'fires' => 'When a child or family is moved to another provider.',
                'to' => 'Guardians; educators at both ends are told separately',
                'subject' => 'A change of provider for {child}',
                'source' => 'Http/Controllers/Api/ChildController.php:794',
                'preview' => 'none',
            ],
            [
                'key' => 'waitlist', 'audience' => 'parents',
                'name' => 'Waitlist update',
                'fires' => 'On a waitlist enquiry, and periodically to say they are still on it.',
                'to' => 'The enquiring family',
                'subject' => 'You are still on our waitlist',
                'source' => 'Http/Controllers/Api/WaitlistController.php:244, Http/Controllers/Api/ExternalWaitlistController.php:128',
                'preview' => 'none',
            ],
            [
                'key' => 'chat-unread', 'audience' => 'parents',
                'name' => 'You have unread messages',
                'fires' => 'When a message goes unread — so a note to a parent is not missed.',
                'to' => 'The recipient of the message',
                'subject' => '[{agency}] New message from {sender}',
                'source' => 'Console/Commands/EmailMissedMessagesCommand.php:279, :438',
                'preview' => 'none',
            ],
            [
                'key' => 'announcement', 'audience' => 'parents',
                'name' => 'Announcement / campaign',
                'fires' => 'When an admin sends a broadcast from Messenger.',
                'to' => 'The chosen audience',
                'subject' => 'A message from {agency}',
                'source' => 'Console/Commands/SendCampaignEmailsCommand.php:79',
                'preview' => 'render', 'registry' => 'announcement',
            ],
            [
                'key' => 'drip', 'audience' => 'parents',
                'name' => 'A note from us',
                'fires' => 'Scheduled drip sequence, where one is configured.',
                'to' => 'Enrolled contacts on the sequence',
                'subject' => 'A note from {agency}',
                'source' => 'Console/Commands/DripDispatchCommand.php:61',
                'preview' => 'none',
            ],
            [
                'key' => 'legal-notice', 'audience' => 'parents',
                'name' => 'Our legal terms have changed',
                'fires' => 'When terms, privacy policy or SMS terms are updated.',
                'to' => 'Everyone who has accepted the previous terms',
                'subject' => 'Our legal terms have changed',
                'source' => 'Console/Commands/LegalNoticeCommand.php:239',
                'preview' => 'none',
            ],
            [
                'key' => 'agreement-signed', 'audience' => 'parents',
                'name' => 'Your signed copy',
                'fires' => 'Immediately after someone signs the terms, privacy policy and NDA.',
                'to' => 'The signer',
                'subject' => 'Your signed copy — KiddieTrac Terms, Privacy & NDA',
                'source' => 'Http/Controllers/Api/AgreementController.php:246',
                'preview' => 'none',
            ],
            [
                'key' => 'walk-notice', 'audience' => 'parents',
                'name' => 'Walk / field trip',
                'fires' => 'When a walk or field trip starts or ends with the child attached.',
                'to' => 'Guardians of attached children',
                'subject' => 'Walk update — {child}',
                'source' => 'Http/Controllers/Api/WalkController.php:710',
                'preview' => 'none',
            ],
            [
                'key' => 'withdrawal-decision', 'audience' => 'parents',
                'name' => 'Withdrawal request decision',
                'fires' => 'When a director approves or declines a withdrawal request.',
                'to' => 'The requesting family',
                'subject' => 'Your withdrawal request',
                'source' => 'Http/Controllers/Api/WithdrawalController.php:380',
                'preview' => 'none',
            ],
            [
                'key' => 'year-end-portfolio', 'audience' => 'parents',
                'name' => 'Year-end portfolio',
                'fires' => 'At year end, with the child\'s collected work and moments.',
                'to' => 'Guardians',
                'subject' => '{child}\'s year',
                'source' => 'Console/Commands/YearEndPortfolioCommand.php:76',
                'preview' => 'none',
            ],
            [
                'key' => 'manual-checkin-tip', 'audience' => 'parents',
                'name' => 'A quick tip on checking in',
                'fires' => 'Weekly, Wednesday 10:00, to families not using check-in.',
                'to' => 'Guardians',
                'subject' => 'A quick tip on checking {child} in',
                'source' => 'Console/Commands/ManualCheckinReminderCommand.php:144',
                'preview' => 'none',
            ],

            // ── Educators & staff ────────────────────────────────────────────
            [
                'key' => 'educator-daily-summary', 'audience' => 'staff',
                'name' => 'Your day at a glance',
                'fires' => 'Every evening at 19:00, agency time.',
                'to' => 'Each educator, for their own room',
                'subject' => 'Your day at a glance — {centre} — {date}',
                'source' => 'Console/Commands/EducatorDailySummaryCommand.php:1137',
                'preview' => 'sample',
            ],
            [
                'key' => 'attendance-reminder', 'audience' => 'staff',
                'name' => 'Daily moments not logged',
                'fires' => 'When children were signed in but nothing was logged for them.',
                'to' => 'The room\'s educator',
                'subject' => 'Daily moments logged for children who were signed in',
                'source' => 'Support/AttendanceReminder.php:90',
                'preview' => 'none',
            ],
            [
                'key' => 'clock-reminder', 'audience' => 'staff',
                'name' => 'Clock in / still clocked in',
                'fires' => 'On the clock-reminder cron — a nudge to clock in, and a warning if still clocked in late.',
                'to' => 'The staff member',
                'subject' => 'Reminder: you\'re still clocked in',
                'source' => 'Console/Commands/ClockReminderCommand.php',
                'preview' => 'none',
            ],
            [
                'key' => 'timeoff-decision', 'audience' => 'staff',
                'name' => 'Time off approved / declined / withdrawn',
                'fires' => 'When a time-off request is decided or withdrawn.',
                'to' => 'The requester, and the rota is re-sent to affected staff',
                'subject' => 'Approved: {name} off {date}',
                'source' => 'Http/Controllers/Api/TimeOffController.php:585, :746, :841',
                'preview' => 'none',
            ],
            [
                'key' => 'rota-change', 'audience' => 'staff',
                'name' => 'Rota change',
                'fires' => 'When approved leave changes who is on the floor.',
                'to' => 'Staff whose shifts moved',
                'subject' => 'Your rota has changed',
                'source' => 'Http/Controllers/Api/TimeOffController.php:670',
                'preview' => 'none',
            ],
            [
                'key' => 'staff-offboard', 'audience' => 'staff',
                'name' => 'Thank you, and all the best',
                'fires' => 'When a staff member is off-boarded.',
                'to' => 'The departing staff member',
                'subject' => 'Thank you, and all the best',
                'source' => 'Http/Controllers/Api/StaffOffboardController.php:504',
                'preview' => 'none',
            ],
            [
                'key' => 'family-left-educator', 'audience' => 'staff',
                'name' => 'Goodbye to {child}',
                'fires' => 'When a family is de-enrolled — the gentle note to the educator who had them. Carries no reason and no balance.',
                'to' => 'Educators in the children\'s rooms (both, on a split week)',
                'subject' => 'Goodbye to {child}',
                'source' => 'Http/Controllers/Api/AdminController.php:3632',
                'preview' => 'sample',
            ],
            [
                'key' => 'biometric-alert', 'audience' => 'staff',
                'name' => 'Biometric unlock enabled',
                'fires' => 'When someone turns on biometric sign-in for their account.',
                'to' => 'The account holder',
                'subject' => 'Biometric unlock enabled',
                'source' => 'Http/Controllers/Api/BiometricController.php:187',
                'preview' => 'none',
            ],

            // ── Admins & directors ───────────────────────────────────────────
            [
                'key' => 'payment-failed-office', 'audience' => 'office',
                'name' => 'A payment did not go through',
                'fires' => 'When a payment from a family fails, so the fee is not quietly left unpaid.',
                'to' => 'Agency admins, and the directors of that centre',
                'subject' => 'Payment failed — {family} · {amount}',
                'source' => 'Services/PaymentNotifier.php:309',
                'preview' => 'none',
            ],

            [
                'key' => 'incident-reminders', 'audience' => 'office',
                'name' => 'Incident reports still waiting',
                'fires' => 'Every morning at 08:00 agency time, while any incident is still open. Silent when the list is empty.',
                'to' => 'Directors of the centre, and the agency\'s admins',
                'subject' => '{n} incident reports waiting — {centre}',
                'source' => 'Console/Commands/IncidentRemindersCommand.php:144',
                'preview' => 'none',
            ],
            [
                'key' => 'immunization-reminders', 'audience' => 'office',
                'name' => 'Immunization records need updating',
                'fires' => 'On the immunization reminder schedule, when records are missing or expiring.',
                'to' => 'The agency\'s admins and directors',
                'subject' => '{n} immunization records need updating',
                'source' => 'Console/Commands/ImmunizationRemindersCommand.php:181',
                'preview' => 'none',
            ],
            [
                'key' => 'staff-offboarded', 'audience' => 'office',
                'name' => 'Staff off-boarded — rooms and ratio impact',
                'fires' => 'When a staff member is off-boarded, after the work is done. Leads with any room they were the only educator on and how many children are enrolled there.',
                'to' => 'The agency\'s admins and the directors of their centres, minus whoever did it',
                'subject' => 'Staff off-boarded — {name} ({n} rooms uncovered)',
                'source' => 'Http/Controllers/Api/StaffOffboardController.php:notifyOffice',
                'preview' => 'none',
            ],
            [
                'key' => 'provider-closed', 'audience' => 'office',
                'name' => 'Provider closed',
                'fires' => 'When a provider is closed through the off-boarding flow, after the work is done — it reports what actually happened, including anything that failed.',
                'to' => 'The agency\'s admins and the closing centre\'s directors, minus whoever closed it',
                'subject' => 'Provider closed — {centre}',
                'source' => 'Http/Controllers/Api/CentreOffboardController.php:113',
                'preview' => 'none',
            ],
            [
                'key' => 'immunization-record-filed', 'audience' => 'office',
                'name' => 'Immunization record filed',
                'fires' => 'When anyone files an immunization record for a child — a parent sending the card in, or the centre filing it and recording the doses off it.',
                'to' => 'The agency\'s admins and the directors of the child\'s centre, minus whoever filed it',
                'subject' => 'Immunization record filed — {child}',
                'source' => 'Http/Controllers/Api/ParentImmunizationRecordController.php:433',
                'preview' => 'none',
            ],
            [
                'key' => 'attendance-correction', 'audience' => 'office',
                'name' => 'Attendance corrected or removed',
                'fires' => 'When a staff member corrects or deletes an attendance entry.',
                'to' => 'The agency\'s admins and directors',
                'subject' => 'Attendance corrected — {child}',
                'source' => 'Http/Controllers/Api/AttendanceCorrectionController.php:349',
                'preview' => 'none',
            ],

            [
                'key' => 'admin-digest', 'audience' => 'office',
                'name' => 'Admin digest',
                'fires' => 'Daily and weekly — what needs a person: open days, approvals, incidents, invoicing, and families who joined.',
                'to' => 'Agency admins and directors',
                'subject' => '[{agency}] Daily summary · {date}',
                'source' => 'Console/Commands/AdminDigestCommand.php:66',
                'preview' => 'sample',
            ],
            [
                'key' => 'daily-digest', 'audience' => 'office',
                'name' => 'Daily digest',
                'fires' => 'Nightly roll-up of the day across the agency.',
                'to' => 'Agency admins and directors',
                'subject' => '[{agency}] Daily summary · {date}',
                'source' => 'Console/Commands/DailyDigestCommand.php:366',
                'preview' => 'none',
            ],
            [
                'key' => 'weekly-digest', 'audience' => 'office',
                'name' => 'Weekly digest',
                'fires' => 'Weekly — enrolments, departures, occupancy and the week ahead.',
                'to' => 'Agency admins and directors',
                'subject' => '[{agency}] Weekly summary · week of {date}',
                'source' => 'Console/Commands/WeeklyDigestCommand.php:270',
                'preview' => 'none',
            ],
            [
                'key' => 'weekly-summary', 'audience' => 'office',
                'name' => 'Weekly summary (test harness)',
                'fires' => 'On demand, from the weekly-summary test command — the same body the weekly digest sends, used to check wording before a real run.',
                'to' => 'Whoever the command is pointed at',
                'subject' => '[{agency}] Weekly summary · week of {date}',
                'source' => 'Console/Commands/WeeklySummaryTestCommand.php:92',
                'preview' => 'sample',
            ],
            [
                'key' => 'scheduled-report', 'audience' => 'office',
                'name' => 'Scheduled report',
                'fires' => 'On the cadence set for each saved report.',
                'to' => 'The report\'s recipients',
                'subject' => '{report} — {period}',
                'source' => 'Console/Commands/SendScheduledReports.php:228, :242',
                'preview' => 'none',
            ],
            [
                'key' => 'new-family-notice', 'audience' => 'office',
                'name' => 'New family to set up',
                'fires' => 'When a family arrives through the iLearn sync — the onboarding checklist, once per family.',
                'to' => 'Agency admins and directors',
                'subject' => 'New family to set up: {family}',
                'source' => 'Services/NewFamilyNotice.php:66',
                'preview' => 'sample',
            ],
            [
                'key' => 'departure-oversight', 'audience' => 'office',
                'name' => 'De-enrolment recorded',
                'fires' => 'When a family is de-enrolled — who did it, why, and the checklist: final invoice, balance, subsidy and CACFP claims, standing payments, the child\'s file.',
                'to' => 'Agency admins and directors',
                'subject' => 'De-enrolment recorded: {family}',
                'source' => 'Http/Controllers/Api/AdminController.php:3801',
                'preview' => 'sample',
            ],
            [
                'key' => 'support-ticket', 'audience' => 'office',
                'name' => 'Support ticket raised / answered',
                'fires' => 'When a ticket is raised, and when it is answered. Bugs route to info@, everything else to the agency admins.',
                'to' => 'Agency admins, or KiddieTrac support for bugs',
                'subject' => 'New support ticket',
                'source' => 'Http/Controllers/Api/SupportTicketController.php:227, :229, :490',
                'preview' => 'none',
            ],
            [
                'key' => 'timeoff-request', 'audience' => 'office',
                'name' => 'Time-off request waiting',
                'fires' => 'When a staff member requests time off.',
                'to' => 'Whoever approves for that centre',
                'subject' => 'Time-off request from {name}',
                'source' => 'Http/Controllers/Api/TimeOffController.php:356',
                'preview' => 'none',
            ],
            [
                'key' => 'home-visit-report', 'audience' => 'office',
                'name' => 'Home-visit report submitted',
                'fires' => 'When a home visitor files a report.',
                'to' => 'Agency admins and directors',
                'subject' => 'New home-visit report submitted',
                'source' => 'Http/Controllers/Api/HomeVisitReportController.php:152',
                'preview' => 'none',
            ],
            [
                'key' => 'inspection-form', 'audience' => 'office',
                'name' => 'Home-visit inspection form',
                'fires' => 'When one of the Ontario inspection forms is submitted.',
                'to' => 'Agency admins and directors',
                'subject' => 'New home-visit form submitted',
                'source' => 'Http/Controllers/Api/HccFormController.php:411',
                'preview' => 'none',
            ],
            [
                'key' => 'form-completed', 'audience' => 'office',
                'name' => 'Form completed',
                'fires' => 'When an assigned form is submitted.',
                'to' => 'Whoever assigned it',
                'subject' => 'Completed form: {form}',
                'source' => 'Http/Controllers/Api/ManagedFormController.php:683',
                'preview' => 'none',
            ],
            [
                'key' => 'agreement-declined', 'audience' => 'office',
                'name' => 'Agreement declined',
                'fires' => 'When somebody declines the terms — they lose access, so somebody has to know.',
                'to' => 'Agency admins',
                'subject' => 'Agreement declined',
                'source' => 'Http/Controllers/Api/AgreementController.php:308',
                'preview' => 'none',
            ],

            // ── Platform & account ───────────────────────────────────────────
            [
                'key' => 'invite', 'audience' => 'platform',
                'name' => 'You are invited',
                'fires' => 'When a user is invited to the portal.',
                'to' => 'The invited person',
                'subject' => 'You\'re invited to KiddieTrac — set your password',
                'source' => 'Support/EmailTemplates.php:204',
                'preview' => 'render', 'registry' => 'invite',
            ],
            [
                'key' => 'platform-welcome', 'audience' => 'platform',
                'name' => 'Your account is ready',
                'fires' => 'When a new agency or platform account is created.',
                'to' => 'The account holder',
                'subject' => 'Welcome to KiddieTrac — set your password',
                'source' => 'Http/Controllers/Api/PlatformController.php:649',
                'preview' => 'none',
            ],
            [
                'key' => 'password-reset', 'audience' => 'platform',
                'name' => 'Reset your password',
                'fires' => 'On a password reset request.',
                'to' => 'The account holder',
                'subject' => 'Reset your password',
                'source' => 'Mail/PasswordResetEmail.php:30, :46',
                'preview' => 'none',
            ],
            [
                'key' => 'account-deletion', 'audience' => 'platform',
                'name' => 'Account deletion request',
                'fires' => 'When someone asks for their account to be deleted.',
                'to' => 'The requester, and KiddieTrac',
                'subject' => 'Account deletion request',
                'source' => 'Http/Controllers/Api/AccountController.php:86',
                'preview' => 'none',
            ],
            [
                'key' => 'platform-invoice', 'audience' => 'platform',
                'name' => 'KiddieTrac invoice to the agency',
                'fires' => 'When KiddieTrac bills an agency for its subscription.',
                'to' => 'The agency\'s billing contact',
                'subject' => 'KiddieTrac — invoice {number}',
                'source' => 'Http/Controllers/Api/PlatformInvoiceController.php:291, Console/Commands/SendPlatformInvoice.php:82',
                'preview' => 'none',
            ],
        ];
    }

    /** Grouped for the screen, in audience order. */
    public static function grouped(): array
    {
        $out = [];
        foreach (array_keys(self::AUDIENCES) as $a) {
            $out[$a] = [];
        }
        foreach (self::all() as $e) {
            $out[$e['audience']][] = $e;
        }

        return $out;
    }

    /** Which files the catalogue claims to cover — used by the drift check. */
    public static function coveredFiles(): array
    {
        $files = [];
        foreach (self::all() as $e) {
            foreach (explode(',', (string) $e['source']) as $bit) {
                $bit = trim($bit);
                if ($bit === '') {
                    continue;
                }
                $files[] = preg_replace('/:\d+.*$/', '', $bit);
            }
        }

        return array_values(array_unique(array_filter($files)));
    }
}
