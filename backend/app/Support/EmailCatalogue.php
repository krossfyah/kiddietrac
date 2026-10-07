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
                'source' => 'Support/TemplatedEmails.php:89, Services/PaymentNotifier.php:162',
                'preview' => 'render', 'registry' => 'payment-receipt',
            ],
            [
                'key' => 'payment-failed-parent', 'audience' => 'parents',
                'name' => 'Your payment did not go through',
                'fires' => 'When a payment is declined or fails. Nothing has been taken.',
                'to' => 'The payer',
                'subject' => 'Your payment did not go through',
                'source' => 'Support/TemplatedEmails.php:122, Services/PaymentNotifier.php:212',
                'preview' => 'render', 'registry' => 'payment-failed',
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
                'key' => 'safe-arrival', 'audience' => 'parents',
                'name' => 'Safe Arrival: has your child arrived?',
                'fires' => "Every 5 minutes (agencies with Safe Arrival on), once per child per day, when a scheduled child is past their expected drop-off + grace with no sign-in and no absence.",
                'to' => 'Guardians of that child, by their own channel choices',
                'subject' => '🛡️ Has {child} arrived?',
                'source' => 'Console/Commands/SafeArrivalCommand.php',
                'preview' => 'none',
            ],
            [
                'key' => 'whats-new-digest', 'audience' => 'staff',
                'name' => "What's new in KiddieTrac (weekly)",
                'fires' => "Mondays 9am, only when whats-new.json has entries newer than the agency's last digest.",
                'to' => 'Agency admins and centre directors (portal highlights); educators and home visitors (only entries tagged for them)',
                'subject' => "What's new in KiddieTrac — {n} new features",
                'source' => 'Console/Commands/WhatsNewDigestCommand.php',
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
                'source' => 'Support/TemplatedEmails.php:183, Http/Controllers/Api/OperationsV2Controller.php:167',
                'preview' => 'render', 'registry' => 'closure-notice',
            ],
            [
                'key' => 'invoice', 'audience' => 'parents',
                'name' => 'Your invoice',
                'fires' => 'When an invoice to a third-party payer (a subsidy body, an employer) is sent from Billing → Payee invoices, with the PDF attached.',
                'to' => 'The payee\'s billing address',
                'subject' => 'Invoice {reference} - {amount}',
                'source' => 'Http/Controllers/Api/PayeeInvoiceController.php:353',
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
                'key' => 'billing-reminder', 'audience' => 'platform',
                'name' => 'KiddieTrac invoice overdue (to the agency)',
                'fires' => 'When a KiddieTrac subscription invoice to an agency is overdue, on the platform reminder schedule.',
                'to' => 'The agency\'s billing contact',
                'subject' => 'Reminder: invoice {number} is overdue',
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
                'key' => 'staff-welcome', 'audience' => 'staff',
                'name' => 'Staff welcome (educators & providers)',
                'fires' => 'Once, when an educator, centre director or home visitor finishes onboarding.',
                'to' => 'The new staff member; CC the director(s) of their centre and the agency admins',
                'subject' => 'Welcome to {agency}, {first name}!',
                'source' => 'Services/StaffWelcome.php',
                'preview' => 'render', 'registry' => 'staff-welcome',
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
                'source' => 'Http/Controllers/Api/AdminController.php:4159, Support/ProviderWelcomeMailer.php:74',
                'preview' => 'sample', 'registry' => 'provider-welcome',
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
                'name' => 'Your time off was approved / declined',
                'fires' => 'When a director or admin decides a time-off request.',
                'to' => 'The person who asked',
                'subject' => 'Your time-off request was approved',
                'source' => 'Http/Controllers/Api/TimeOffController.php:848',
                'preview' => 'none',
            ],
            [
                'key' => 'rota-change', 'audience' => 'staff',
                'name' => 'Rota change — a colleague is away',
                'fires' => 'When a colleague\'s time off is approved.',
                'to' => 'The other educators and home visitors at the centre',
                'subject' => '{name} is away — {dates}',
                'source' => 'Http/Controllers/Api/TimeOffController.php:592',
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
                'name' => 'Support ticket raised',
                'fires' => 'When a ticket is raised. Bugs route to info@, everything else to the agency admins.',
                'to' => 'Agency admins, or KiddieTrac support for bugs',
                'subject' => 'New support ticket',
                'source' => 'Http/Controllers/Api/SupportTicketController.php:258',
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
                'key' => 'website-booking-confirmation', 'audience' => 'platform',
                'name' => 'Website booking confirmation',
                'fires' => 'When a visitor books a meeting in the website booking wizard.',
                'to' => 'The visitor who booked (with an .ics calendar file)',
                'subject' => 'Confirmed: your KiddieTrac {meeting type}',
                'source' => 'Http/Controllers/Api/MarketingBookingController.php:283',
                'preview' => 'none',
            ],
            [
                'key' => 'website-booking-sales', 'audience' => 'platform',
                'name' => 'New website booking (to sales)',
                'fires' => 'When a visitor books a meeting in the website booking wizard.',
                'to' => 'sales@kiddietrac.com',
                'subject' => 'Booked: {meeting type} with {name} — {date, time}',
                'source' => 'Http/Controllers/Api/MarketingBookingController.php:283',
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
                'source' => 'Http/Controllers/Api/PlatformInvoiceController.php:341, Console/Commands/SendPlatformInvoice.php:82, Console/Commands/BillingRun.php:131',
                'preview' => 'none',
            ],
            // ── Added 2026-10-06: senders the catalogue had missed (email:catalogue --check) ──
            [
                'key' => 'invoice-cancelled', 'audience' => 'parents',
                'name' => 'An invoice was cancelled',
                'fires' => 'When an invoice is voided. Tells the family nothing is owed and nothing needs doing.',
                'to' => "The family's guardians",
                'subject' => 'Invoice {number} has been cancelled - nothing to do',
                'source' => 'Support/TemplatedEmails.php:221, Http/Controllers/Api/InvoiceController.php:2387',
                'preview' => 'render', 'registry' => 'invoice-cancelled',
            ],
            [
                'key' => 'invoice-voided-staff', 'audience' => 'office',
                'name' => 'Invoice voided (staff copy)',
                'fires' => 'Alongside the family letter when an invoice is voided: who voided it and why.',
                'to' => 'Directors and agency admins',
                'subject' => 'Invoice voided: {number} - {family}',
                'source' => 'Http/Controllers/Api/InvoiceController.php:2413',
                'preview' => 'none',
            ],
            [
                'key' => 'closure-reminder', 'audience' => 'parents',
                'name' => 'Closure reminder (and holiday closures)',
                'fires' => 'Shortly before a closure or statutory holiday — a reminder to families and staff, with how fees are handled.',
                'to' => "The centre's families and staff",
                'subject' => 'Reminder: {centre} is closed {when} ({dates})',
                'source' => 'Console/Commands/ClosureReminderCommand.php:430',
                'preview' => 'none',
            ],
            [
                'key' => 'payment-receipt-pdf', 'audience' => 'parents',
                'name' => 'Receipt for a recorded payment (PDF attached)',
                'fires' => 'When a payment is recorded against an invoice and a receipt is issued, with the PDF receipt attached.',
                'to' => 'The payer',
                'subject' => 'Your receipt',
                'source' => 'Services/PaymentReceipt.php:135',
                'preview' => 'none',
            ],
            [
                'key' => 'files-requested', 'audience' => 'parents',
                'name' => 'Documents requested (and reminders)',
                'fires' => 'When the office asks a family for documents, and the reminder while they are still outstanding.',
                'to' => 'The family the request was sent to',
                'subject' => 'Please send us a few documents',
                'source' => 'Support/FileRequestNotice.php:95',
                'preview' => 'none',
            ],
            [
                'key' => 'files-received-family', 'audience' => 'parents',
                'name' => 'We have your documents',
                'fires' => 'When a family has uploaded everything that was requested.',
                'to' => 'The family',
                'subject' => 'We have your documents',
                'source' => 'Support/FileRequestNotice.php:202',
                'preview' => 'none',
            ],
            [
                'key' => 'files-received-office', 'audience' => 'office',
                'name' => 'All requested files are in',
                'fires' => 'When a family has completed a document request.',
                'to' => 'Whoever sent the request, and the office',
                'subject' => 'All requested files are in',
                'source' => 'Support/FileRequestNotice.php:160',
                'preview' => 'none',
            ],
            [
                'key' => 'form-received', 'audience' => 'parents',
                'name' => 'We have your form',
                'fires' => 'When a family submits a form — a confirmation with a copy of what they sent.',
                'to' => 'The person who submitted it',
                'subject' => 'We have your {form}',
                'source' => 'Support/FormSubmissionNotice.php:194',
                'preview' => 'none',
            ],
            [
                'key' => 'form-submitted-office', 'audience' => 'office',
                'name' => 'A form was submitted',
                'fires' => 'When a family submits a form that the office needs to see.',
                'to' => 'The form owner / office',
                'subject' => '{form}',
                'source' => 'Support/FormSubmissionNotice.php:245',
                'preview' => 'none',
            ],
            [
                'key' => 'late-pickup-alert', 'audience' => 'office',
                'name' => 'Late pickup logged / needs a decision',
                'fires' => 'When a late pickup or arrival is logged — charged on the spot, or waiting for a director or admin to decide.',
                'to' => "The centre's directors and agency admins",
                'subject' => 'Late pickup: {child} — {minutes} min, {fee}',
                'source' => 'Support/LatePickups.php:198',
                'preview' => 'none',
            ],
            [
                'key' => 'rsvp-emails', 'audience' => 'parents',
                'name' => 'Event RSVP invitation, confirmation, reminder and notices',
                'fires' => 'From Event RSVPs: the invitation, the confirmation after signing up, reminders, and sign-up / opt-out notices to the organiser. Edited per event on the Event RSVPs screen.',
                'to' => 'Invitees, registrants, and the event\'s sign-up address',
                'subject' => 'Set per event',
                'source' => 'Support/RsvpMail.php:73, Support/RsvpMail.php:108',
                'preview' => 'none',
            ],
            [
                'key' => 'rsvp-sender-test', 'audience' => 'office',
                'name' => 'RSVP sender account test',
                'fires' => 'When an admin presses Send test on an RSVP sender account.',
                'to' => 'The address the admin types',
                'subject' => 'Sender account test',
                'source' => 'Http/Controllers/Api/RsvpController.php:788',
                'preview' => 'none',
            ],
            [
                'key' => 'donation-receipt', 'audience' => 'parents',
                'name' => 'Donation receipt',
                'fires' => 'When a donation is recorded — a thank-you with the receipt number.',
                'to' => 'The donor',
                'subject' => 'Thank you for your gift',
                'source' => 'Http/Controllers/Api/DonationController.php:371',
                'preview' => 'none',
            ],
            [
                'key' => 'donation-pledge', 'audience' => 'parents',
                'name' => 'Pledge thank-you',
                'fires' => 'When someone pledges to a fundraising campaign.',
                'to' => 'The person pledging',
                'subject' => 'Thank you for your pledge',
                'source' => 'Http/Controllers/Api/DonationController.php:504',
                'preview' => 'none',
            ],
            [
                'key' => 'sms-consent-invite', 'audience' => 'parents',
                'name' => 'Would you like text alerts?',
                'fires' => 'When the office invites a guardian to opt in to text messages.',
                'to' => 'The guardian',
                'subject' => 'Would you like text alerts?',
                'source' => 'Http/Controllers/Api/SmsConsentInviteController.php:382',
                'preview' => 'none',
            ],
            [
                'key' => 'sms-consent-receipt', 'audience' => 'parents',
                'name' => 'Text alerts are on / off',
                'fires' => 'A record for the guardian whenever their text-message consent changes.',
                'to' => 'The guardian',
                'subject' => 'Text alerts are on / Text alerts are off',
                'source' => 'Services/SmsConsentReceipt.php:210',
                'preview' => 'none',
            ],
            [
                'key' => 'password-expiry', 'audience' => 'platform',
                'name' => 'Time to change your password',
                'fires' => 'Before a password expires, and once it has, under the agency\'s password policy.',
                'to' => 'The account holder',
                'subject' => 'Time to change your password / Your password has expired',
                'source' => 'Services/PasswordExpiryMail.php:116',
                'preview' => 'none',
            ],
            [
                'key' => 'sign-in-alert', 'audience' => 'platform',
                'name' => 'New sign-in to your account',
                'fires' => 'When an account signs in from a new place or device.',
                'to' => 'The account holder',
                'subject' => 'New sign-in to your account',
                'source' => 'Support/SignInAlert.php:126',
                'preview' => 'none',
            ],
            [
                'key' => 'training-assignment', 'audience' => 'staff',
                'name' => 'Training assigned to you',
                'fires' => 'When an admin or director assigns a Help & guides training module, with its due date.',
                'to' => 'The staff member or parent assigned',
                'subject' => 'Training assigned: {module}',
                'source' => 'Support/TrainingMail.php:52',
                'preview' => 'none',
            ],
            [
                'key' => 'security-alerts', 'audience' => 'platform',
                'name' => 'Security alert raised',
                'fires' => 'When the security monitor finds something worth a look (repeated failed sign-ins and similar).',
                'to' => 'Platform administrators',
                'subject' => 'A security alert was raised',
                'source' => 'Console/Commands/SecurityAlerts.php:173',
                'preview' => 'none',
            ],
            // ── Added 2026-10-06 (email templates, worker 2): one entry per distinct email, so
            //    each can be previewed and worded on its own. Several of these used to share
            //    an entry with a sibling sent from the same method.
            [
                'key' => 'portal-access', 'audience' => 'parents',
                'name' => 'Portal access paused / restored',
                'fires' => 'When an admin suspends or reactivates a family.',
                'to' => 'The family\'s guardians',
                'subject' => 'Your portal access has been paused',
                'source' => 'Http/Controllers/Api/AdminController.php:5242',
                'preview' => 'none',
            ],
            [
                'key' => 'immunization-reminder-parent', 'audience' => 'parents',
                'name' => 'Immunization record needed (to parents)',
                'fires' => 'On the immunization reminder schedule, when a child is overdue or coming up and the office does not already hold a record.',
                'to' => 'The child\'s guardians, with the centre\'s staff blind-copied',
                'subject' => 'Immunization record for {child}',
                'source' => 'Console/Commands/ImmunizationRemindersCommand.php:199',
                'preview' => 'none',
            ],
            [
                'key' => 'team-chat-unread', 'audience' => 'staff',
                'name' => 'Unread team chat',
                'fires' => 'When team-chat messages have sat unread for a while — one email per person per run.',
                'to' => 'Each staff member with unread messages',
                'subject' => 'New message from {sender}',
                'source' => 'Console/Commands/EmailMissedMessagesCommand.php:493',
                'preview' => 'none',
            ],
            [
                'key' => 'safe-arrival-staff', 'audience' => 'office',
                'name' => 'Safe Arrival: child not arrived (staff copy)',
                'fires' => 'When an expected child has not arrived and no absence was reported.',
                'to' => 'The centre\'s staff',
                'subject' => '{child} has not arrived',
                'source' => 'Console/Commands/SafeArrivalCommand.php:218',
                'preview' => 'none',
            ],
            [
                'key' => 'timeoff-reminders', 'audience' => 'office',
                'name' => 'Time off waiting for approval (reminder)',
                'fires' => 'On the agency\'s reminder schedule while requests are still undecided (Settings → Clock settings).',
                'to' => 'The people who can approve them',
                'subject' => 'Time off waiting for approval',
                'source' => 'Support/TimeOffReminders.php:225',
                'preview' => 'none',
            ],
            [
                'key' => 'forms-to-sign', 'audience' => 'parents',
                'name' => 'Forms to sign',
                'fires' => 'When forms are sent to someone to complete from Forms Manager.',
                'to' => 'The person asked to sign',
                'subject' => 'A form needs your signature',
                'source' => 'Http/Controllers/Api/ManagedFormController.php:1424',
                'preview' => 'none',
            ],
            [
                'key' => 'form-correction', 'audience' => 'parents',
                'name' => 'Please correct and resubmit a form',
                'fires' => 'When a reviewer sends a submitted form back for changes.',
                'to' => 'The person who submitted it',
                'subject' => 'Please correct and resubmit: {form}',
                'source' => 'Http/Controllers/Api/ManagedFormController.php:1845',
                'preview' => 'none',
            ],
            [
                'key' => 'form-countersigned', 'audience' => 'parents',
                'name' => 'Your form was counter-signed',
                'fires' => 'When the office counter-signs a form, with the signed copy.',
                'to' => 'The person who signed it first',
                'subject' => '{form} — counter-signed',
                'source' => 'Http/Controllers/Api/ManagedFormController.php:2678',
                'preview' => 'none',
            ],
            [
                'key' => 'absence-reported-office', 'audience' => 'office',
                'name' => 'Absence reported (to the centre)',
                'fires' => 'When a parent reports an absence in the app.',
                'to' => 'The child\'s educators and the centre\'s directors',
                'subject' => '{child} will be absent — {date}',
                'source' => 'Http/Controllers/Api/AbsenceController.php:359',
                'preview' => 'none',
            ],
            [
                'key' => 'incident-review', 'audience' => 'office',
                'name' => 'Incident awaiting review',
                'fires' => 'When an incident report is filed and needs a director\'s review.',
                'to' => 'The centre\'s directors',
                'subject' => 'Incident awaiting review — {child}',
                'source' => 'Http/Controllers/Api/IncidentController.php:994',
                'preview' => 'none',
            ],
            [
                'key' => 'timeoff-closure-parents', 'audience' => 'parents',
                'name' => 'We will be closed (a provider\'s time off)',
                'fires' => 'When approved time off leaves a home-care centre with nobody to open it.',
                'to' => 'The centre\'s families',
                'subject' => 'We will be closed on {dates}',
                'source' => 'Http/Controllers/Api/TimeOffController.php:592',
                'preview' => 'none',
            ],
            [
                'key' => 'timeoff-approved-office', 'audience' => 'office',
                'name' => 'Time off approved (record for the office)',
                'fires' => 'When a time-off request is approved.',
                'to' => 'Agency admins and directors',
                'subject' => 'Approved: {name} off {dates}',
                'source' => 'Http/Controllers/Api/TimeOffController.php:592',
                'preview' => 'none',
            ],
            [
                'key' => 'timeoff-withdrawn', 'audience' => 'staff',
                'name' => 'Time off withdrawn — back in after all',
                'fires' => 'When approved time off is withdrawn.',
                'to' => 'The colleagues and office who were told it was approved',
                'subject' => '{name} will be in after all — {dates}',
                'source' => 'Http/Controllers/Api/TimeOffController.php:753',
                'preview' => 'none',
            ],
            [
                'key' => 'support-ticket-reply', 'audience' => 'staff',
                'name' => 'New reply on your support ticket',
                'fires' => 'When someone replies on a support ticket.',
                'to' => 'The other side of the ticket',
                'subject' => 'New reply on your ticket #{id}',
                'source' => 'Http/Controllers/Api/SupportTicketController.php:435',
                'preview' => 'none',
            ],
            [
                'key' => 'support-ticket-resolved', 'audience' => 'staff',
                'name' => 'Your support request was resolved',
                'fires' => 'When a ticket is resolved or closed.',
                'to' => 'The person who raised it',
                'subject' => 'Your request has been resolved — ticket #{id}',
                'source' => 'Http/Controllers/Api/SupportTicketController.php:617',
                'preview' => 'none',
            ],
            [
                'key' => 'website-support-ack', 'audience' => 'platform',
                'name' => 'Website support request received (to the person asking)',
                'fires' => 'When someone asks for help on the public website.',
                'to' => 'The person asking',
                'subject' => 'KiddieTrac support request #{id} received',
                'source' => 'Http/Controllers/Api/WebsiteSupportController.php:113',
                'preview' => 'none',
            ],
            [
                'key' => 'website-voicemail', 'audience' => 'platform',
                'name' => 'New voicemail',
                'fires' => 'When a caller leaves a voicemail on the support line.',
                'to' => 'KiddieTrac support',
                'subject' => 'Voicemail from {caller}',
                'source' => 'Http/Controllers/Api/WebsiteSupportController.php:181',
                'preview' => 'none',
            ],
            [
                'key' => 'website-support', 'audience' => 'platform',
                'name' => 'Website support request / voicemail',
                'fires' => 'When someone asks for help on the public website (a copy to them and one to support), and when a voicemail is left.',
                'to' => 'KiddieTrac support, and the person asking',
                'subject' => 'Website support request #{id}',
                'source' => 'Http/Controllers/Api/WebsiteSupportController.php:96',
                'preview' => 'none',
            ],
        ];
    }

    /**
     * Emails worded once for every agency, by a platform admin: KiddieTrac's own mail
     * (password reset, its invoices, legal notices, the public website) rather than an
     * agency's. An agency admin can preview these but not change them.
     */
    public const PLATFORM_KEYS = [
        'password-reset', 'platform-welcome', 'platform-invoice', 'billing-reminder', 'legal-notice',
        'security-alerts', 'sign-in-alert', 'password-expiry', 'account-deletion',
        'website-booking-confirmation', 'website-booking-sales', 'website-support', 'website-support-ack',
        'website-voicemail',
    ];

    /** Shown with Preview only, and why. Everything else can be worded. */
    public const PREVIEW_ONLY = [
        'weekly-summary' => 'A QA harness run by hand on the server; families never receive it on a schedule.',
        'rsvp-sender-test' => 'A connection test for an event\'s sender account: it has no wording of its own.',
        'drip' => 'Each drip email IS the campaign\'s content — word it on the campaign in Marketing.',
        'website-booking-sales' => 'An internal note to the KiddieTrac sales inbox, not built on the email layout.',
    ];

    /** Listed per event, edited in Event RSVPs. */
    public const PER_EVENT = ['rsvp-emails' => '#event-rsvps'];

    /** The editor's groups, in order. */
    public const GROUPS = [
        'parents' => 'Parents',
        'billing' => 'Billing',
        'staff' => 'Staff',
        'accounts' => 'Accounts & invitations',
        'admin' => 'Admin alerts',
        'platform' => 'Platform (KiddieTrac-wide)',
    ];

    private const GROUP_OF = [
        'payment-receipt' => 'billing', 'payment-failed-parent' => 'billing', 'invoice' => 'billing',
        'account-statement' => 'billing', 'invoice-copy' => 'billing', 'payment-receipt-pdf' => 'billing',
        'invoice-cancelled' => 'billing', 'invoice-voided-staff' => 'billing', 'payment-failed-office' => 'billing',
        'payslip' => 'billing', 'donation-receipt' => 'billing', 'donation-pledge' => 'billing',
        'invite' => 'accounts', 'onboarding-welcome' => 'accounts', 'onboarding-reminder' => 'accounts',
        'portal-access' => 'accounts', 'sms-consent-invite' => 'accounts', 'sms-consent-receipt' => 'accounts',
    ];

    public static function groupOf(array $e): string
    {
        if (in_array($e['key'], self::PLATFORM_KEYS, true)) {
            return 'platform';
        }
        if (isset(self::GROUP_OF[$e['key']])) {
            return self::GROUP_OF[$e['key']];
        }

        return ['parents' => 'parents', 'staff' => 'staff', 'office' => 'admin', 'platform' => 'platform'][$e['audience']] ?? 'parents';
    }

    /**
     * How an email is edited:
     *   'sections'  — the full section editor of the registry (App\Support\EmailTemplates)
     *   'basic'     — subject, heading, opening and closing (+ any named sections)
     *                 through App\Support\EmailOverrides
     *   'per-event' — worded on each event
     *   'none'      — preview only (see PREVIEW_ONLY)
     */
    public static function editMode(string $key): string
    {
        static $byKey = null;
        if ($byKey === null) {
            $byKey = [];
            foreach (self::all() as $e) {
                $byKey[$e['key']] = $e;
            }
        }
        $e = $byKey[$key] ?? null;
        if ($e === null) {
            return 'none';
        }
        if (! empty($e['registry'])) {
            return 'sections';
        }
        if (isset(self::PER_EVENT[$key])) {
            return 'per-event';
        }
        if (isset(self::PREVIEW_ONLY[$key])) {
            return 'none';
        }

        return 'basic';
    }

    public static function find(string $key): ?array
    {
        foreach (self::all() as $e) {
            if ($e['key'] === $key) {
                return $e;
            }
        }

        return null;
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
