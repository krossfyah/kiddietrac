<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Facades\DB;

/**
 * EVERY EMAIL CAN BE WORDED BY THE AGENCY (2026-10-06).
 *
 * Anthony: "i don't see all email templates being able to edit and preview only some?"
 *
 * He was right. Nine emails lived in the editable registry (App\Support\EmailTemplates);
 * the other ~80 are composed inline by whatever sends them, so the editor could only
 * describe them. Moving 80 senders into the registry one by one is how the nine were
 * done, and it would take as many careful rewrites again.
 *
 * Every one of those emails already passes through one function on its way out:
 * App\Services\EmailTemplate::wrap. So the editing happens THERE. wrap() asks this
 * class which email is being built (from the caller, see SITES) and applies whatever
 * the agency changed:
 *
 *   subject — the subject line ({{original_subject}} is the one the system wrote)
 *   title   — the heading in the banner ({{original_heading}})
 *   intro   — a paragraph added before the automatic content
 *   outro   — a paragraph added after it
 *
 * plus, for the emails listed in SECTIONS, named sentences inside the body that the
 * sender asks for with say().
 *
 * THE RULE THAT MAKES THIS SAFE: nothing changes unless something was changed. With no
 * overrides stored, wrap() receives exactly what it received before and the email is
 * byte-for-byte what it was. A section left at its default text returns the sender's
 * own HTML, untouched — the editor shows the default as words, but the words are never
 * re-rendered into the email unless the agency actually edits them.
 *
 * Storage: agencies.settings.email_overrides.<key> for agency emails, and the
 * platform_settings row `email_overrides` for platform emails (password reset, platform
 * invoices…), which only a platform admin may change.
 *
 * The subject is the one part wrap() cannot reach — the sender sets it afterwards. So
 * when a subject is overridden wrap() leaves a marker comment in the HTML, and the
 * MessageSending listener App\Listeners\EmailSubjectOverride swaps the subject and
 * removes the marker before anything leaves.
 */
final class EmailOverrides
{
    /** The four wording fields every email has. */
    public const BASIC = ['subject', 'title', 'intro', 'outro'];

    /** Tags every email understands. Sections add their own. */
    public const BASIC_TAGS = ['agency_name', 'agency_email', 'agency_phone', 'today', 'original_heading', 'original_subject'];

    private const MARKER = '<!--kt-email-subject:';

    /**
     * Which email a wrap() call is building, by the method that called it.
     *
     * A string is the key. An array chooses by "EYEBROW|Title" as the caller passed them
     * (first matching regex wins; '*' is the fallback), for the few methods that send
     * more than one kind of email. Line numbers drift; method names do not, which is why this is
     * keyed on them. `php artisan email:catalogue --check` reports any wrap site whose
     * method is missing here.
     */
    public const SITES = [
        'PasswordResetEmail@content' => 'password-reset',
        'ClosureReminderCommand@send' => 'closure-reminder',
        'WeeklyDigestCommand@renderHtml' => 'weekly-digest',
        'DripDispatchCommand@handle' => 'drip',
        'ImmunizationRemindersCommand@parentPass' => 'immunization-reminder-parent',
        'ImmunizationRemindersCommand@sendOne' => 'immunization-reminders',
        'BillingRun@handle' => 'platform-invoice',
        'EmailMissedMessagesCommand@renderEmail' => 'chat-unread',
        'EmailMissedMessagesCommand@sweepTeamChat' => ['/^MESSAGES\|/' => 'chat-unread', '*' => 'team-chat-unread'],
        'ManualCheckinReminderCommand@send' => 'manual-checkin-tip',
        'DailyDigestCommand@renderHtml' => 'daily-digest',
        'WeeklySummaryTestCommand@handle' => 'weekly-summary',
        'SecurityAlerts@notify' => 'security-alerts',
        'AdminDigestCommand@handle' => 'admin-digest',
        'LegalNoticeCommand@body' => 'legal-notice',
        'YearEndPortfolioCommand@handle' => 'year-end-portfolio',
        'ClockReminderCommand@sendReminder' => 'clock-reminder',
        'BirthdayEmailCommand@deliver' => 'birthday',
        'BirthdayEmailCommand@sendSample' => 'birthday',
        'CheckinReminderCommand@email' => 'checkin-reminder',
        'WhatsNewDigestCommand@send' => 'whats-new-digest',
        'SafeArrivalCommand@staffEmail' => 'safe-arrival-staff',
        'SafeArrivalCommand@email' => 'safe-arrival',
        'OnboardingReminderCommand@send' => 'onboarding-reminder',
        'EducatorDailySummaryCommand@buildHtml' => 'educator-daily-summary',
        'SendPlatformInvoice@handle' => 'platform-invoice',
        'PlatformInvoiceReminders@handle' => 'billing-reminder',
        'IncidentRemindersCommand@handle' => 'incident-reminders',
        'SendCampaignEmailsCommand@handle' => 'announcement',
        'ParentDailySummaryCommand@buildHtml' => 'parent-daily-summary',
        'SendScheduledReports@sendOne' => 'scheduled-report',
        'CheckEventNotifier@email' => 'check-event',
        'PaymentNotifier@send' => 'payment-failed-office',
        'PaymentReceipt@build' => 'payment-receipt-pdf',
        'StaffWelcome@renderHtml' => 'staff-welcome',
        'PasswordExpiryMail@send' => 'password-expiry',
        'SmsConsentReceipt@send' => 'sms-consent-receipt',
        'NewFamilyNotice@forSyncedFamily' => 'new-family-notice',
        'AccountLedgerController@emailInvoice' => 'invoice-copy',
        'AccountLedgerController@emailStatement' => 'account-statement',
        'CentreOffboardController@notifyClosure' => 'provider-closed',
        'ManagedFormController@emailFormPackage' => 'forms-to-sign',
        'ManagedFormController@mailCorrectionRequest' => 'form-correction',
        'ManagedFormController@emailCompletedForm' => 'form-completed',
        'ManagedFormController@returnCountersigned' => 'form-countersigned',
        'AdminController@sendUserInvite' => 'invite',
        'AdminController@notifyProviderOfDeparture' => 'family-left-educator',
        'AdminController@notifyAdminsOfDeparture' => 'departure-oversight',
        'AdminController@notifyFamilyDeparture' => 'family-departure',
        'AdminController@notifyFamilyAccess' => 'portal-access',
        'ParentImmunizationRecordController@emailOffice' => 'immunization-record-filed',
        'AccountController@notifyAdmins' => 'account-deletion',
        'SmsConsentInviteController@email' => 'sms-consent-invite',
        'DonationController@emailReceipt' => 'donation-receipt',
        'DonationController@publicPledge' => 'donation-pledge',
        'WithdrawalController@mailUser' => 'withdrawal-decision',
        'WaitlistController@remind' => 'waitlist',
        'ExternalWaitlistController@reachOut' => 'waitlist',
        'MarketingBookingController@mailVisitor' => 'website-booking-confirmation',
        'InvoiceController@voidLetters' => 'invoice-voided-staff',
        'IncidentController@emailReport' => ['/^ACTION NEEDED\|/' => 'incident-review', '*' => 'incident-report-share'],
        'AbsenceController@tellTheFamily' => 'absence-reported',
        'AbsenceController@tellTheCentre' => 'absence-reported-office',
        'PayrollDocumentController@email' => 'payslip',
        'WebsiteSupportController@submitTicket' => ['/^\|We/u' => 'website-support-ack', '*' => 'website-support'],
        'WebsiteSupportController@voicemail' => 'website-voicemail',
        'SupportTicketController@create' => 'support-ticket',
        'SupportTicketController@sendReplyNotice' => 'support-ticket-reply',
        'SupportTicketController@notifyReporterResolved' => 'support-ticket-resolved',
        'PlatformController@sendAdminInvite' => 'platform-welcome',
        'BiometricController@alert' => 'biometric-alert',
        'ChildController@mailTransfer' => 'change-of-provider',
        'WalkController@mailWalk' => 'walk-notice',
        'AttendancePatternController@notifyPatternChange' => 'attendance-days-updated',
        'PlatformInvoiceController@email' => 'platform-invoice',
        'PayeeInvoiceController@send' => 'invoice',
        'RsvpController@testSender' => 'rsvp-sender-test',
        'HomeVisitReportController@notifyReviewers' => 'home-visit-report',
        'TimeOffController@mailApprover' => 'timeoff-request',
        'TimeOffController@announceApproval' => ['/^CENTRE CLOSURE\|/' => 'timeoff-closure-parents', '/^ROTA CHANGE\|/' => 'rota-change', '*' => 'timeoff-approved-office'],
        'TimeOffController@announceWithdrawal' => 'timeoff-withdrawn',
        'TimeOffController@mailDecision' => 'timeoff-decision',
        'HccFormController@notifyReviewers' => 'inspection-form',
        'StaffOffboardController@notifyOffice' => 'staff-offboarded',
        'StaffOffboardController@sendExitNotice' => 'staff-offboard',
        'AgreementController@emailSignedCopy' => 'agreement-signed',
        'AgreementController@emailDeclineAlert' => 'agreement-declined',
        'AttendanceCorrectionController@notifyOversight' => 'attendance-correction',
        'TimeOffReminders@digest' => 'timeoff-reminders',
        'TrainingMail@send' => 'training-assignment',
        'SignInAlert@renderEmail' => 'sign-in-alert',
        'ProviderWelcomeMailer@sendToFamily' => 'provider-welcome',
        'RsvpMail@frame' => 'rsvp-emails',
        'AttendanceReminder@maybeSend' => 'attendance-reminder',
        'FormSubmissionNotice@toSigner' => 'form-received',
        'FormSubmissionNotice@toOffice' => 'form-submitted-office',
        'FileRequestNotice@ask' => 'files-requested',
        'FileRequestNotice@completed' => 'files-received-office',
        'FileRequestNotice@receipt' => 'files-received-family',
        'LatePickups@send' => 'late-pickup-alert',
        // The registry's own builders: they have full section editors already.
        'TemplatedEmails@paymentReceipt' => 'payment-receipt',
        'TemplatedEmails@paymentFailed' => 'payment-failed-parent',
        'TemplatedEmails@closureNotice' => 'closure-notice',
        'TemplatedEmails@invoiceCancelled' => 'invoice-cancelled',
    ];

    /**
     * Named sentences inside a body, for the emails that needed more than an opening
     * and a closing. Each is asked for by the sender with say(); the default here is
     * the sender's own wording written out as text, shown in the editor. When the
     * stored value equals it (or is empty) say() returns the sender's HTML untouched.
     *
     * `when` is shown under the label: several of these emails have versions (a parent
     * and a staff copy, a holiday and an ordinary closure), and each version's sentence
     * is its own section so changing one cannot change the other.
     */
    public const SECTIONS = [
        'closure-reminder' => [
            'tags' => ['centre_name', 'when', 'reason', 'dates', 'provider'],
            'fields' => [
                ['k' => 'lead_parent', 'label' => 'Opening — parents', 'when' => 'Ordinary closure (repairs, training days…)',
                    'def' => 'This is a reminder that **{{centre_name}}** will be closed {{when}}.'],
                ['k' => 'lead_parent_holiday', 'label' => 'Opening — parents', 'when' => 'Statutory holiday',
                    'def' => 'A gentle reminder that **{{centre_name}}** will be closed {{when}} for **{{reason}}**.'],
                ['k' => 'lead_staff', 'label' => 'Opening — staff', 'when' => 'Ordinary closure',
                    'def' => 'A reminder that **{{centre_name}}** is closed {{when}}, so you are not expected in.'],
                ['k' => 'lead_staff_holiday', 'label' => 'Opening — staff', 'when' => 'Statutory holiday',
                    'def' => '**{{centre_name}}** is closed {{when}} for **{{reason}}**, so you are not expected in.'],
                ['k' => 'fees_adjusted', 'label' => 'Fees — parents', 'when' => 'Closure marked as affecting billing',
                    'def' => 'Fees for these days are adjusted where applicable, in line with the agreement between your family and us. Anything owing or credited will appear on your next invoice.'],
                ['k' => 'fees_continue', 'label' => 'Fees — parents', 'when' => 'Closure that does not affect billing',
                    'def' => 'Your usual fees continue to apply for these days. Where an adjustment is due under the agreement between your family and us, it will appear on your next invoice.'],
                ['k' => 'note_staff', 'label' => 'Timesheet note — staff', 'when' => 'Every staff copy',
                    'def' => 'Sign-in and clock-in are switched off for these days, so they will not appear as missing hours on your timesheet.'],
                ['k' => 'close_parent', 'label' => 'Closing line — parents', 'when' => 'Every parent copy',
                    'def' => 'There is nothing you need to do — sign-in is switched off and we will see you when we reopen.'],
                ['k' => 'close_staff', 'label' => 'Closing line — staff', 'when' => 'Every staff copy',
                    'def' => 'If you believe you are scheduled to work during the closure, speak to your director before the date.'],
            ],
        ],
        // The reminder line is also the push notification and the text message; these
        // sections change the EMAIL only (the editor says so).
        'checkin-reminder' => [
            'tags' => ['child_name', 'centre_name', 'signed_in_at'],
            'email_only' => 'These words change the email only. The push notification and the text message keep the standard wording.',
            'fields' => [
                ['k' => 'message_morning', 'label' => 'Reminder — morning', 'when' => 'Not signed in yet',
                    'def' => "{{child_name}} hasn't been signed in at {{centre_name}} today. Please sign in when you arrive — or let us know if they're not coming in."],
                ['k' => 'message_evening', 'label' => 'Reminder — evening', 'when' => 'Signed in, not signed out',
                    'def' => "{{child_name}} was signed in at {{signed_in_at}} and hasn't been signed out. Please sign out so the day's attendance record is complete."],
                ['k' => 'not_attending_tip', 'label' => 'Not attending tip', 'when' => 'Morning reminder',
                    'def' => 'If your child is not coming in today, you can tell us in the app — open KiddieTrac and tap **“Not attending today”**. That lets the whole team know, and stops these reminders.'],
            ],
        ],
        'absence-reported' => [
            'tags' => ['child_name', 'centre_name'],
            'email_only' => 'These words change the email only. The push notification keeps the standard wording.',
            'fields' => [
                ['k' => 'closing_reporter', 'label' => 'Closing line', 'when' => 'To the parent who reported it',
                    'def' => 'Nothing further is needed. If plans change, cancel the absence in the app and {{centre_name}} will be told.'],
                ['k' => 'closing_other', 'label' => 'Closing line', 'when' => 'To the other guardians',
                    'def' => 'If this is not right, contact {{centre_name}} so it can be corrected.'],
            ],
        ],
        'password-reset' => [
            'tags' => ['minutes'],
            'fields' => [
                ['k' => 'intro', 'label' => 'Why they are getting it', 'when' => null,
                    'def' => 'We received a request to reset your Kiddietrac password. Click the button below to choose a new one.'],
                ['k' => 'expiry', 'label' => 'Expiry note', 'when' => null,
                    'def' => "This link expires in {{minutes}} minutes. If you didn't request a reset, you can safely ignore this email — your password won't change."],
            ],
        ],
        'payment-receipt-pdf' => [
            'tags' => [],
            'fields' => [
                ['k' => 'thanks', 'label' => 'Thank-you line', 'when' => null,
                    'def' => 'Thank you — we have received your payment.'],
                ['k' => 'attached', 'label' => 'About the attachment', 'when' => null,
                    'def' => 'Your receipt is **attached as a PDF**. It shows the amount, how it was paid and what is left on the account. We keep those details out of the email itself so they are not sitting in an inbox or a message preview.'],
                ['k' => 'keep', 'label' => 'Keep it for your records', 'when' => null,
                    'def' => 'Please keep it for your records. It is a payment receipt, not a childcare tax receipt — your tax receipt is issued separately at the end of the year.'],
            ],
        ],
        'invoice-voided-staff' => [
            'tags' => ['family_name', 'invoice_number'],
            'fields' => [
                ['k' => 'intro', 'label' => 'What happened', 'when' => null,
                    'def' => 'An invoice for **{{family_name}}** has been voided. The family has been told it is cancelled and that no payment is due.'],
                ['k' => 'trail', 'label' => 'What happens to it', 'when' => null,
                    'def' => 'The invoice keeps its number and stays in Accounting marked Void, so the trail is intact. If it belonged to a payment schedule, that instalment has been withdrawn and the schedule total adjusted to match.'],
            ],
        ],
    ];

    /** Unsaved editor values for one key — Preview and Send test only, never a real send. */
    private static ?array $draft = null;

    /**
     * Set by the preview harness (App\Support\EmailPreviews). While it is an array,
     * every wrap() appends [key, html] to it, so a preview can show the email even when
     * its sender queues the actual send instead of making it.
     */
    public static ?array $seen = null;

    /** Run $fn with $values standing in for the stored wording of $key. */
    public static function withDraft(string $key, ?array $values, callable $fn)
    {
        $prev = self::$draft;
        self::$draft = $values === null ? null : ['key' => $key, 'values' => $values];
        try {
            return $fn();
        } finally {
            self::$draft = $prev;
        }
    }

    // ── what wrap() calls ───────────────────────────────────────────────────

    /**
     * Apply the stored wording for the email being built. Returns [$body, $opts].
     * Unchanged emails come back exactly as they went in.
     */
    public static function apply(?int $agencyId, string $bodyHtml, array $opts): array
    {
        $key = null;
        try {
            $key = isset($opts['email']) && is_string($opts['email']) ? $opts['email'] : self::keyFromTrace($opts);
        } catch (\Throwable $e) {
            $key = null;
        }
        unset($opts['email']);
        if ($key === null) {
            return [$bodyHtml, $opts, null];
        }
        try {
            $v = self::basicEditable($key) ? self::values($agencyId, $key) : [];
            if ($v) {
                $data = self::data($agencyId) + ['original_heading' => (string) ($opts['title'] ?? '')];
                if (($v['title'] ?? '') !== '') {
                    $opts['title'] = self::line($v['title'], $data);
                }
                if (($v['intro'] ?? '') !== '') {
                    $bodyHtml = '<p style="margin:0 0 14px;font-size:15px;line-height:1.6;color:#334155;">'
                        . self::para($v['intro'], $data) . '</p>' . $bodyHtml;
                }
                if (($v['outro'] ?? '') !== '') {
                    $bodyHtml .= '<p style="margin:16px 0 0;font-size:15px;line-height:1.6;color:#334155;">'
                        . self::para($v['outro'], $data) . '</p>';
                }
                if (($v['subject'] ?? '') !== '') {
                    $bodyHtml .= self::MARKER . $key . ':' . (int) $agencyId . '-->';
                }
            }
        } catch (\Throwable $e) {
            // Wording must never be the reason an email fails to send.
        }

        return [$bodyHtml, $opts, $key];
    }

    /**
     * wrap() hands back the finished HTML so a preview can see it — and what it was
     * given, so a check can rebuild the same email with the old wrap() and compare.
     */
    public static function built(?string $key, string $html, ?array $in = null): void
    {
        if (is_array(self::$seen)) {
            self::$seen[] = ['key' => $key, 'html' => $html, 'in' => $in];
        }
    }

    /**
     * One named sentence of a body, as HTML: the agency's wording if it changed it,
     * otherwise $original — the sender's own HTML, returned untouched.
     */
    public static function say(?int $agencyId, string $key, string $section, string $original, array $data = []): string
    {
        try {
            $def = null;
            foreach (self::SECTIONS[$key]['fields'] ?? [] as $f) {
                if ($f['k'] === $section) {
                    $def = (string) $f['def'];
                }
            }
            if ($def === null) {
                return $original;
            }
            $v = (string) (self::values($agencyId, $key)[$section] ?? '');
            if (trim($v) === '' || self::norm($v) === self::norm($def)) {
                return $original;
            }

            return self::para($v, $data + self::data($agencyId));
        } catch (\Throwable $e) {
            return $original;
        }
    }

    // ── what the MessageSending listener calls ──────────────────────────────

    /** The overridden subject for a message carrying a marker, and the HTML without it. */
    public static function resolveSubject(string $html, string $original): ?array
    {
        if (strpos($html, self::MARKER) === false) {
            return null;
        }
        if (! preg_match('/<!--kt-email-subject:([a-z0-9-]+):(\d+)-->/', $html, $m)) {
            return null;
        }
        $clean = str_replace($m[0], '', $html);
        $agencyId = (int) $m[2] ?: null;
        $v = self::values($agencyId, $m[1]);
        $subject = $original;
        if (($v['subject'] ?? '') !== '') {
            $s = self::line($v['subject'], self::data($agencyId) + ['original_subject' => $original]);
            if ($s !== '') {
                $subject = mb_substr($s, 0, 250);
            }
        }

        return ['subject' => $subject, 'html' => $clean];
    }

    // ── storage ─────────────────────────────────────────────────────────────

    /** The stored (or draft) wording for one email: only fields that hold something. */
    public static function values(?int $agencyId, string $key): array
    {
        if (self::$draft !== null && self::$draft['key'] === $key) {
            return self::clean($key, self::$draft['values']);
        }
        if (self::isPlatform($key)) {
            $all = self::platformAll();

            return self::clean($key, $all[$key] ?? []);
        }
        if (! $agencyId) {
            return [];
        }
        $raw = DB::table('agencies')->where('id', $agencyId)->value('settings');
        $s = $raw ? (json_decode((string) $raw, true) ?: []) : [];

        return self::clean($key, $s['email_overrides'][$key] ?? []);
    }

    /** Save (or, with an empty set, clear) one email's wording. */
    public static function store(?int $agencyId, string $key, array $values): void
    {
        $clean = self::clean($key, $values, true);
        if (self::isPlatform($key)) {
            $all = self::platformAll();
            if ($clean) {
                $all[$key] = $clean;
            } else {
                unset($all[$key]);
            }
            PlatformSettings::set('email_overrides', json_encode($all));

            return;
        }
        if (! $agencyId) {
            throw new \RuntimeException('No agency');
        }
        DB::transaction(function () use ($agencyId, $key, $clean) {
            $raw = DB::table('agencies')->where('id', $agencyId)->lockForUpdate()->value('settings');
            $s = $raw ? (json_decode((string) $raw, true) ?: []) : [];
            if (! isset($s['email_overrides']) || ! is_array($s['email_overrides'])) {
                $s['email_overrides'] = [];
            }
            if ($clean) {
                $s['email_overrides'][$key] = $clean;
            } else {
                unset($s['email_overrides'][$key]);
            }
            DB::table('agencies')->where('id', $agencyId)->update(['settings' => json_encode($s), 'updated_at' => now()]);
        });
    }

    /** True when this email's wording differs from what the system writes. */
    public static function isCustomised(?int $agencyId, string $key): bool
    {
        $v = self::values($agencyId, $key);
        foreach ($v as $k => $val) {
            if (in_array($k, self::BASIC, true)) {
                return true;
            }
            foreach (self::SECTIONS[$key]['fields'] ?? [] as $f) {
                if ($f['k'] === $k && self::norm($val) !== self::norm((string) $f['def'])) {
                    return true;
                }
            }
        }

        return false;
    }

    /** Field list for the editor: the four basics, then the email's own sections. */
    public static function fields(string $key): array
    {
        $out = [
            ['k' => 'subject', 'label' => 'Subject line', 'rich' => false, 'minH' => 0, 'basic' => true,
                'hint' => 'Leave empty to keep the automatic subject. {{original_subject}} is the automatic one.'],
            ['k' => 'title', 'label' => 'Heading', 'rich' => false, 'minH' => 0, 'basic' => true,
                'hint' => 'Leave empty to keep the automatic heading. {{original_heading}} is the automatic one.'],
            ['k' => 'intro', 'label' => 'Opening paragraph', 'rich' => false, 'minH' => 70, 'basic' => true,
                'hint' => 'Added above the automatic content. Empty adds nothing.'],
        ];
        foreach (self::SECTIONS[$key]['fields'] ?? [] as $f) {
            $out[] = ['k' => $f['k'], 'label' => $f['label'], 'when' => $f['when'] ?? null, 'rich' => false,
                'minH' => 60, 'basic' => false, 'default' => $f['def']];
        }
        $out[] = ['k' => 'outro', 'label' => 'Closing paragraph', 'rich' => false, 'minH' => 70, 'basic' => true,
            'hint' => 'Added below the automatic content. Empty adds nothing.'];

        return $out;
    }

    /** Defaults for the editor: empty basics, the sender's own sentences for sections. */
    public static function defaults(string $key): array
    {
        $d = array_fill_keys(self::BASIC, '');
        foreach (self::SECTIONS[$key]['fields'] ?? [] as $f) {
            $d[$f['k']] = (string) $f['def'];
        }

        return $d;
    }

    /** Stored wording merged over the defaults — what the editor opens with. */
    public static function blocks(?int $agencyId, string $key): array
    {
        return array_merge(self::defaults($key), self::values($agencyId, $key));
    }

    public static function tags(string $key): array
    {
        return array_values(array_unique(array_merge(self::BASIC_TAGS, self::SECTIONS[$key]['tags'] ?? [])));
    }

    // ── which emails ────────────────────────────────────────────────────────

    /** Platform emails are worded once for everybody, by a platform admin. */
    public static function isPlatform(string $key): bool
    {
        return in_array($key, EmailCatalogue::PLATFORM_KEYS, true);
    }

    /** Whether the generic subject/heading/opening/closing applies to this key. */
    public static function basicEditable(string $key): bool
    {
        return EmailCatalogue::editMode($key) === 'basic';
    }

    // ── helpers ─────────────────────────────────────────────────────────────

    /** Which catalogue key the code that called wrap() is building. */
    private static function keyFromTrace(array $opts): ?string
    {
        $trace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 24);
        // Skip this class and wrap() itself, closures and the framework: the first
        // method of ours above them is the sender.
        for ($i = 0, $n = count($trace); $i < $n; $i++) {
            $cls = (string) ($trace[$i]['class'] ?? '');
            $fn = (string) ($trace[$i]['function'] ?? '');
            if ($cls === '' || strpos($cls, 'App\\') !== 0 || strpos($fn, '{closure') !== false
                || $cls === self::class || $cls === \App\Services\EmailTemplate::class) {
                continue;
            }
            $site = substr($cls, (int) strrpos($cls, '\\') + 1) . '@' . $fn;
            $hit = self::SITES[$site] ?? null;
            if ($hit === null) {
                return null;
            }
            if (is_string($hit)) {
                return $hit;
            }
            $said = trim((string) ($opts['eyebrow'] ?? '')) . '|' . trim((string) ($opts['title'] ?? ''));
            foreach ($hit as $re => $key) {
                if ($re !== '*' && preg_match($re, $said)) {
                    return $key;
                }
            }

            return $hit['*'] ?? null;
        }

        return null;
    }

    private static function clean(string $key, $values, bool $forStore = false): array
    {
        if (! is_array($values)) {
            return [];
        }
        $allowed = self::BASIC;
        foreach (self::SECTIONS[$key]['fields'] ?? [] as $f) {
            $allowed[] = $f['k'];
        }
        $out = [];
        foreach ($allowed as $k) {
            if (! array_key_exists($k, $values)) {
                continue;
            }
            $v = trim(strip_tags(str_replace(["\r\n", "\r"], "\n", (string) $values[$k])));
            if ($v === '') {
                continue;
            }
            $v = mb_substr($v, 0, in_array($k, ['subject', 'title'], true) ? 250 : 4000);
            // A section equal to its default is not stored: there is nothing to override.
            if ($forStore && ! in_array($k, self::BASIC, true)) {
                foreach (self::SECTIONS[$key]['fields'] ?? [] as $f) {
                    if ($f['k'] === $k && self::norm($v) === self::norm((string) $f['def'])) {
                        continue 2;
                    }
                }
            }
            $out[$k] = $v;
        }

        return $out;
    }

    private static function platformAll(): array
    {
        $raw = PlatformSettings::get('email_overrides');
        if (is_array($raw)) {
            return $raw;
        }

        return $raw ? (json_decode((string) $raw, true) ?: []) : [];
    }

    /** Agency facts every email can use. */
    private static function data(?int $agencyId): array
    {
        $ag = $agencyId ? DB::table('agencies')->where('id', $agencyId)->first() : null;

        return [
            'agency_name' => (string) ($ag->name ?? 'KiddieTrac'),
            'agency_email' => (string) ($ag->contact_email ?? ''),
            'agency_phone' => (string) ($ag->contact_phone ?? ''),
            'today' => now(AgencyTime::tz($agencyId))->format('l, j F Y'),
        ];
    }

    private static function fill(string $text, array $data): string
    {
        return preg_replace_callback('/\{\{\s*([a-z_]+)\s*\}\}/', function ($m) use ($data) {
            return array_key_exists($m[1], $data) && (is_scalar($data[$m[1]]) || $data[$m[1]] === null)
                ? (string) $data[$m[1]] : $m[0];
        }, $text) ?? $text;
    }

    /** A paragraph: tags filled, escaped, **bold** kept, line breaks kept. */
    private static function para(string $text, array $data): string
    {
        $t = e(self::fill($text, $data));
        $t = preg_replace('/\*\*(.+?)\*\*/su', '<strong>$1</strong>', $t) ?? $t;

        return nl2br($t, false);
    }

    /** A one-line value (subject, heading): tags filled, no markup. */
    private static function line(string $text, array $data): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', str_replace('**', '', self::fill($text, $data))));
    }

    private static function norm(string $v): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($v), ENT_QUOTES | ENT_HTML5, 'UTF-8')));
    }
}
