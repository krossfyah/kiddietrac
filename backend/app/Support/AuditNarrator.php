<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Turns audit rows into sentences a person can read (2026-08-24).
 *
 * The portal-wide AuditActivity middleware records raw HTTP: action is
 * "post:api/v1/provider/chat-archive" and the only other identifier is entity_id. The
 * audit screen had nothing else to show, which is why it read as "id: 17". Earlier work
 * on this only improved the handful of hand-instrumented actions (chat.broadcast,
 * staff.contractor_flag and friends) -- the generic middleware rows are the large
 * majority and were never touched.
 *
 * Narration happens at READ time, deliberately. Doing it at write time would only fix
 * rows created from now on; doing it here makes the ~10,600 rows already in the table
 * readable too, and lets the wording be corrected later without a migration.
 *
 * Entity names are resolved in ONE batched query per type for the whole page, never per
 * row -- a hundred rows must not become four hundred queries.
 */
final class AuditNarrator
{
    /**
     * Exact action -> verb phrase. Anything not listed falls back to the path
     * humaniser below, so an unmapped route still reads as English.
     */
    private const VERBS = [
        'post:api/v1/provider/chat-archive'        => 'Archived a conversation',
        'post:api/v1/provider/team-threads/start'  => 'Started a conversation',
        'post:api/v1/auth/agreement/sign'          => 'Signed the user agreement',
        'email.sent'                               => 'Sent an email',
        'email.suppressed'                         => 'Suppressed an email',
        'email.failed'                             => 'An email failed to send',
        /* Zum Rails. Without these the narrator falls back to "Changed zum.settled",
           which is the one line in an audit log a bookkeeper actually needs to read. */
        'agency.updated'                           => 'Updated agency details',
        'schedule.autofill_enabled'                => 'Turned on nightly schedule filling',
        'schedule.autofill_disabled'               => 'Turned off nightly schedule filling',
        'zum.settled'                              => 'A payment settled',
        // Written by the webhook once refunds were audited (2026-09-02). The wording
        // makes the direction plain: a settled refund means money has left us.
        'zum.refund_settled'                       => 'A refund was paid back',
        'zum.refund_failed'                        => 'A refund failed',
        'zum.refund_cancelled'                     => 'A refund was cancelled',
        'zum.refund_submitted'                     => 'A refund was sent to the bank',
        'zum.refund_in_review'                     => 'A refund is being reviewed',
        'zum.failed'                               => 'A payment failed',
        'zum.cancelled'                            => 'A payment was cancelled',
        'zum.in_review'                            => 'A payment is being reviewed',
        'holidays.synced'                          => 'Added statutory holidays to the calendar',
        'shift.autofilled'                         => 'Filled the staff schedule',
        // Signed-out actions, audited since 2026-10-21 (AuditActivity::GUEST).
        'kiosk.check_event'                        => 'Signed a child in or out at the kiosk',
        'kiosk.signature'                          => 'Signed at the kiosk',
        'kiosk.temperature'                        => 'Recorded a temperature at the kiosk',
        'time_off.decided_by_link'                 => 'Answered a time-off request from the email link',
        'parent_feedback.submitted'                => 'Sent feedback on the day',
        'rsvp.submitted'                           => 'Replied to an event invitation',
        'donation.pledged'                         => 'Made a donation pledge',
        'tour.booked'                              => 'Booked a tour',
        'sales_quote.client_declined'              => 'Declined a proposal',
        'auth.registered'                          => 'Registered an account',
        // Passwords, in words (2026-10-21).
        'user.password_reset'                      => 'Reset a person’s password',
        'password_reset_requested'                 => 'Asked for a password reset',
        'password_reset_send_failed'               => 'A password reset could not be sent',
        'password_reset_code_verified'             => 'Entered the right reset code',
        'password_reset_code_failed'               => 'Entered a wrong reset code',
        'password_reset_completed'                 => 'Chose a new password',
        'password_reset_failed'                    => 'A password reset failed',
        'password_reset_unmatched'                 => 'Asked to reset a password for an account that does not exist',
        'push.sent'                                => 'Sent a push notification',
        'push.failed'                              => 'A push notification failed',
        'push.no_device'                           => 'No device to notify',
        'push.suppressed'                          => 'A push notification was suppressed',
        'zum.failed'                               => 'A payment failed',
        'zum.cancelled'                            => 'A payment was cancelled',
        'zum.cancelling'                           => 'A payment is being cancelled',
        'zum.submitted'                            => 'A payment reached the bank',
        'zum.in_review'                            => 'A payment was held for review',
        'zum.requested'                            => 'Requested a payment from a family',
        'zum.sent'                                 => 'Sent money out',
        'zum.bank_account_saved'                   => 'Saved a bank account for payments',
        /* Attendance corrections. Named plainly because these are the rows somebody
           reads back months later when a ratio or an invoice is questioned. */
        'attendance.backdated'                     => 'Corrected attendance for a child',
        'attendance.entry_removed'                 => 'Removed an attendance entry',
        'post:api/v1/parent/zum/pay'               => 'A parent paid an invoice (Zum)',
        'post:api/v1/parent/zum/bank-account'      => 'A parent saved a bank account (Zum)',
        'post:api/v1/director/zum/send'            => 'Sent money out (Zum)',
        'post:api/v1/director/zum/request'         => 'Requested money from a family (Zum)',
        'post:api/v1/admin/payment-providers/zumrails' => 'Changed Zum Rails settings',
        'post:api/v1/admin/payment-providers/stripe'   => 'Changed Stripe settings',
        'post:api/v1/parent/billing/setup-intent'  => 'Started adding a card (Stripe)',
        'post:api/v1/parent/billing/save-card'     => 'Saved a card (Stripe)',
        'post:api/v1/parent/billing/autopay'       => 'Changed autopay',
        'post:api/v1/parent/wallet/setup-intent'   => 'Started adding a payment method',
        'chat.broadcast'                           => 'Sent a broadcast',
        'staff.contractor_flag'                    => 'Changed a contractor flag',
        'login'                                    => 'Signed in',
        'login_failed'                             => 'Failed sign-in attempt',
        'post:api/v1/auth/logout'                  => 'Signed out',
        'password_set_via_invite'                  => 'Set a password from an invite',
        'user.welcome_resent'                      => 'Resent a welcome email',
        'digest.daily_sent'                        => 'Sent the daily digest',
        'post:api/v1/notifications/mark-read'      => 'Marked notifications as read',
        'post:api/v1/push/subscribe'               => 'Enabled push notifications',
        'post:api/v1/push/device'                  => 'Registered a device for push',
        'post:api/v1/care/logs'                    => 'Logged a care moment',
        'post:api/v1/provider/check-in'            => 'Checked a child in',
        'post:api/v1/provider/check-out'           => 'Checked a child out',
        'child.check_in_by_staff'                  => 'Checked a child in',
        'child.check_out_by_staff'                 => 'Checked a child out',
        'staff.clock_in'                           => 'Clocked in',
        'staff.clock_out'                          => 'Clocked out',
        /* Written by the nightly job with user_id = NULL, so the actor already
           reads as "System". The verb says what happened and why. */
        'staff.auto_clock_out'                     => 'Clocked out automatically (no clock-out recorded)',
        'child.auto_check_out'                     => 'Checked out automatically (nobody signed them out)',
        'post:api/v1/parent/messages'              => 'Sent a message',
        'logout'                                   => 'Signed out',
        /* Named events that used to read as their code (2026-10-21). Anthony: "platform
           level audit log shows onboarding failed but no email or name attached - we need
           more details to all of the audit logs". */
        'onboarding_started'                       => 'Opened their invite link',
        'onboarding_failed'                        => 'Could not start onboarding',
        'onboarding_completed'                     => 'Finished onboarding',
        'login_blocked_locked'                     => 'Was refused sign-in (locked after too many wrong passwords)',
        'login_needs_username'                     => 'Tried to sign in with an email several accounts share',
        'mfa_failed'                               => 'Entered a wrong two-step code',
        'session.expired'                          => 'Was signed out after being idle',
        'error.rate_limited'                       => 'Was slowed down after too many attempts',
        // Both kinds land here: a request that failed, and error text a screen showed
        // (kind raw_error_shown). The summary says which; the label must fit both.
        'error.client_request'                     => 'A portal page hit an error',
        'perf.slow_request'                        => 'A page loaded slowly',
        'security.password_expiry_swept'           => 'Checked for passwords about to expire',
        'security.tokens_revoked'                  => 'Signed out old sessions',
        'headcount.taken'                          => 'Took a headcount',
        'walk.auto_closed'                         => 'Closed a walk automatically',
        'announcement.sent'                        => 'Sent an announcement',
        'managed_form.signed_via_link'             => 'Signed a form from an email link',
        'sales.library_uploaded'                   => 'Uploaded a file to the sales library',
        'sales.library_downloaded'                 => 'Downloaded a file from the sales library',
        'user.created'                             => 'Created an account',
        'user.deleted'                             => 'Deleted an account',
        'chat.email_notified'                      => 'Emailed people about unread chat messages',
        'support_ticket.raised'                    => 'Raised a support ticket',
        'attendance.pickup_time_from_email'        => 'Entered the real pick-up time from the email',
        'staff.clock_out_from_email'               => 'Entered their real clock-out time from the email',
        'attendance.time_fix_refused'              => 'A time from an automatic sign-out email was refused',
        'support_ticket.resolved'                  => 'Resolved a support ticket',
        'reseller.created'                         => 'Created a reseller',
        'reseller.updated'                         => 'Changed a reseller',
        'reseller.agencies_set'                    => 'Changed which agencies a reseller has',
        'reseller.admin_added'                     => 'Gave someone access to manage a reseller',
        'reseller.admin_removed'                   => 'Removed someone’s access to manage a reseller',
        'reseller.brand_saved'                     => 'Changed a reseller’s brand',
        'reseller.logo_uploaded'                   => 'Uploaded a reseller logo',
        'reseller.app_saved'                       => 'Changed a reseller’s app build settings',
        'reseller.app_kit'                         => 'Downloaded a reseller’s app build kit',
        'reseller.agency_created'                  => 'Added an agency under a reseller',
        'support_ticket.escalated'                 => 'Escalated a support ticket to the platform team',
        'reseller.statement_exported'              => 'Downloaded a reseller statement',
        'reseller.agency_admin_invited'            => 'Invited an agency admin',
        'reseller.agency_admin_deactivate'         => 'Turned off an agency admin’s access',
        'reseller.agency_admin_reactivate'         => 'Turned an agency admin’s access back on',
        'reseller.agency_admin_reset'              => 'Sent an agency admin a new set-password link',
        'login_failed_social'                      => 'Failed sign-in with Google or Microsoft',
    ];

    /* EVERY NAMED ACTION GETS A SENTENCE (2026-10-31, Anthony: "audit log using raw ... we had a rule to never use
       raw logging"). 409 named actions had no entry above and read as their code turned into words
       ("Billing reminders switch"). kt:audit-raw-check fails when a recently written action is missing here. */
    private const MORE = [
        'named' => 'Recorded an event',
        // Ontario curriculum library + menu templates (2026-11-03).
        'curriculum.unit_copied' => 'Copied a curriculum unit',
        'curriculum.unit_updated' => 'Edited a curriculum unit',
        'curriculum.unit_deleted' => 'Deleted a curriculum unit',
        'curriculum.unit_used' => 'Made draft lesson plans from a curriculum unit',
        'menu_template.saved' => 'Saved a menu week as a template',
        'menu_template.applied' => 'Applied a menu template',
        'menu_template.deleted' => 'Deleted a menu template',
        // Ask KiddieTrac, the admin AI assistant (2026-11-03).
        'ai.ask' => 'Asked Ask KiddieTrac a question',
        // Sales -> Expenses (KiddieTrac's own spending) and its supplier list (2026-11-02).
        'sales_supplier.added' => 'Added a KiddieTrac expense supplier',
        'sales_supplier.changed' => 'Changed a KiddieTrac expense supplier',
        'sales_supplier.archived' => 'Archived a KiddieTrac expense supplier',
        'sales_supplier.restored' => 'Brought back a KiddieTrac expense supplier',
        'platform_expense.added' => 'Added a KiddieTrac expense',
        'platform_expense.changed' => 'Changed a KiddieTrac expense',
        'platform_expense.deleted' => 'Deleted a KiddieTrac expense',
        'platform_expense.approved' => 'Approved a KiddieTrac expense',
        'platform_expense.rejected' => 'Did not approve a KiddieTrac expense',
        'platform_expense.reimbursed' => 'Marked a KiddieTrac expense reimbursed',
        'platform_expense.recurring_added' => 'Added a recurring KiddieTrac expense',
        'platform_expense.recurring_changed' => 'Changed a recurring KiddieTrac expense',
        'platform_expense.recurring_removed' => 'Removed a recurring KiddieTrac expense',
        'thank_you_auto.settings' => 'Changed the automatic thank-you award settings',
        'educator_reward.thanked' => 'Sent an educator a thank-you award',
        'thank_you_auto.drafts_ready' => 'Prepared automatic thank-you awards for review',
        'thank_you_auto.sent' => 'Sent the automatic thank-you awards',
        'thank_you_auto.none' => 'Ran the automatic thank-you awards (nobody qualified)',
        'thank_you_auto.no_signer' => 'Could not prepare automatic thank-you awards',
        'thank_you_auto.draft_edited' => 'Edited an automatic thank-you award draft',
        'thank_you_auto.draft_skipped' => 'Skipped an automatic thank-you award',
        'thank_you_auto.expired' => 'An automatic thank-you award draft expired',
        'account.reactivated' => 'Reactivated an account',
        'account_statement.emailed' => 'Emailed an account statement',
        'agency.deleted' => 'Deleted an agency',
        'agency.email_template_test' => 'Sent a test of an email template',
        'agency.email_template_updated' => 'Changed an email template',
        'agency.email_wording_updated' => 'Changed the wording of agency emails',
        'agency.invite_resent' => 'Resent an agency invitation',
        'agency.jurisdiction_changed' => 'Changed the agency’s province, state or currency',
        'agency.provisioned' => 'Set up a new agency',
        'agency.ref_prefix' => 'Changed the agency’s reference-number prefix',
        'agency.restored_after_test' => 'Restored an agency after a test',
        'agency.settings_updated' => 'Changed agency settings',
        'announcement.reminder_sent' => 'Sent an announcement reminder',
        'anomaly_rules.updated' => 'Changed the unusual-activity alert rules',
        'attendance.restored' => 'Restored an attendance record',
        'audit.redacted' => 'Redacted part of the audit log',
        'autopay.batch_run' => 'Ran the autopay batch',
        'autopay.disabled' => 'Turned off autopay',
        'autopay.enabled' => 'Turned on autopay',
        'billing.payment_run' => 'Ran a payment run',
        'billing.reminders_switch' => 'Switched invoice reminders on or off for the platform',
        'billing.split_updated' => 'Changed how a family’s bill is split between guardians',
        'billing_reminders_updated' => 'Changed the invoice reminder settings',
        'booking.accepted' => 'Accepted a meeting request',
        'booking.cancelled' => 'Cancelled a meeting',
        'booking.email_decision' => 'Decided a meeting request from the email',
        'booking.modified' => 'Moved a meeting',
        'booking.settings' => 'Changed meeting booking settings',
        'branding.updated' => 'Changed the branding',
        'call.alerts_changed' => 'Changed call alert settings',
        'call.answered_in_app' => 'Answered a call in the app',
        'call.not_connected' => 'A call did not connect',
        'call.placed' => 'Placed a phone call',
        'call.recording_played' => 'Played a call recording',
        'campaign.email_sent' => 'Sent a campaign email',
        'care.attendance_reminder' => 'Sent an attendance reminder',
        'centre.address_corrected' => 'Corrected a centre’s address',
        'centre.archived' => 'Archived a centre',
        'centre.created' => 'Added a centre',
        'centre.deleted' => 'Deleted a centre',
        'centre.duplicate_removed' => 'Removed a duplicate centre',
        'centre.geocoded' => 'Found a centre on the map',
        'centre.merged' => 'Merged two centres',
        'centre.offboarded' => 'Offboarded a centre',
        'centre.updated' => 'Changed a centre',
        'centre_signup' => 'Signed up a centre',
        'chat.transcript_viewed' => 'Viewed a chat transcript',
        'child.absence_backfilled' => 'Filled in past absences for a child',
        'child.attendance_pattern_updated' => 'Changed a child’s attendance days',
        'child.care_schedule_updated' => 'Changed a child’s care schedule',
        'child.check_in' => 'Checked a child in',
        'child.check_in_refused' => 'Was refused when checking a child in',
        'child.check_out' => 'Checked a child out',
        'child.check_refused' => 'Was refused when checking a child in or out',
        'child.document_deleted' => 'Deleted a child’s document',
        'child.document_uploaded' => 'Uploaded a child’s document',
        'child.enrolment_backfilled' => 'Filled in a child’s past enrolment',
        'child.enrolment_closed' => 'Closed a child’s enrolment',
        'child.immunization_details_recorded' => 'Recorded immunization details for a child',
        'child.immunization_exemptions_recorded' => 'Recorded immunization exemptions for a child',
        'child.immunization_record_uploaded' => 'Uploaded an immunization record',
        'child.note_added' => 'Added a note to a child’s record',
        'child.parent_message_sent' => 'Sent a message to a child’s parents',
        'child.schedule_change_notice' => 'Told the family and staff about a schedule change',
        'child.sleep_direction' => 'Recorded a sleep position instruction for a child',
        'child.transferred' => 'Moved a child to another room or centre',
        'compliance.access_review' => 'Completed an access review',
        'compliance.evidence_exported' => 'Exported compliance evidence',
        'compliance_settings_updated' => 'Changed data-retention settings',
        'contact.created' => 'Added a contact',
        'contact.deleted' => 'Deleted a contact',
        'contact_prefs.sms' => 'Changed text-message preferences',
        'create_family' => 'Created a family',
        'cwelcc.ended' => 'Ended CWELCC funding for a child',
        'cwelcc.rate_changed' => 'Changed a CWELCC rate',
        'demo.companion_issued' => 'Opened the parent phone beside the educator phone (website demo)',
        'dialer.settings_saved' => 'Changed phone dialer settings',
        'digest.weekly_sent' => 'Sent the weekly digest',
        'donations.campaign_saved' => 'Saved a donation campaign',
        'donations.receipt_issued' => 'Issued a donation receipt',
        'donations.settings' => 'Changed donation settings',
        'donations.updated' => 'Changed a donation',
        'drill.cadence_changed' => 'Changed how often drills are due',
        'drill.recorded' => 'Recorded a drill',
        'drill.removed' => 'Removed a drill',
        'educator_rewards.provider' => 'Changed a provider’s reward points',
        'email.bounced' => 'An email bounced',
        'email.deferred' => 'An email was delayed by the receiving server',
        'email.staff_welcome' => 'Sent a staff welcome email',
        'email.staff_welcome_test' => 'Sent a test staff welcome email',
        'email.text_repaired' => 'Repaired the text of an email',
        'email_header_season.changed' => 'Changed the seasonal email header',
        'emergency_card.print_link_issued' => 'Created a print link for an emergency card',
        'emergency_card.printed' => 'Printed an emergency card',
        'emergency_card.viewed' => 'Opened a child’s emergency card',
        'enrolment.backfill_closed' => 'Closed a back-filled enrolment',
        'error.server' => 'The server hit an error',
        'family.dearchived_correction' => 'Un-archived a family (correction)',
        'family.deleted' => 'Closed a family’s account',
        'family.departure_notice_resent' => 'Resent a family’s departure notice',
        'family.departure_scheduled' => 'Scheduled a family’s last day',
        'family.guardian_removed' => 'Removed a guardian from a family',
        'family.merged' => 'Merged two families',
        'family.note_added' => 'Added a note to a family',
        'family.onboarding_notice_sent' => 'Sent a family their onboarding notice',
        'family.pickup_login_granted' => 'Gave a pick-up person a login',
        'family.pickup_login_removed' => 'Removed a pick-up person’s login',
        'family.provider_welcome_resent' => 'Resent the provider welcome to a family',
        'family.reactivated' => 'Reactivated a family',
        'family.restored' => 'Restored a family',
        'family.suspended' => 'Suspended a family',
        'family_file.downloaded' => 'Downloaded a family file',
        'family_file.sent' => 'Sent a family file',
        'family_file.settings' => 'Changed family file settings',
        'fee_plan.invoices_generated' => 'Created invoices from a fee plan',
        'file_request.created' => 'Requested a file',
        'file_request.deleted' => 'Deleted a file request',
        'file_request.link_viewed' => 'Opened a file request link',
        'file_request.uploaded' => 'Uploaded a requested file',
        'form.emailed' => 'Emailed a form',
        'form.submitted' => 'Submitted a form',
        'forms.countersign_reminder_settings' => 'Changed counter-signature reminder settings',
        'forms.countersign_reminder_test' => 'Sent a test counter-signature reminder',
        'forms.countersign_reminders_sent' => 'Sent counter-signature reminders',
        'grants.saved' => 'Saved a grant',
        'headcount.alerted' => 'Sent a missing-child alert from a headcount',
        'headcount.missing' => 'Recorded a headcount with children not accounted for',
        'headcount.reminders' => 'Changed headcount reminder settings',
        'headcount.resolved' => 'Resolved a headcount alert',
        'immunization.exemption_removed' => 'Removed an immunization exemption',
        'immunization.exemption_set' => 'Recorded an immunization exemption',
        'immunization.schedule_preset' => 'Chose an immunization schedule',
        'immunization_reminders_updated' => 'Changed immunization reminder settings',
        'impersonate.start' => 'Started viewing the portal as another person',
        'integration.child_created' => 'Added a child from the iLearn sync',
        'integration.child_placement_failed' => 'The iLearn sync could not place a child in a room',
        'integration.child_updated' => 'Updated a child from the iLearn sync',
        'integration.inbound_create_declined' => 'The iLearn sync was refused a new record',
        'integration.provider_change_held' => 'Held a provider change from the iLearn sync for review',
        'integration.skipped_archived_centre' => 'Skipped an archived provider offered by the iLearn sync',
        'integration.skipped_archived_child' => 'Skipped an archived child offered by the iLearn sync',
        'invoice.description_rewritten' => 'Rewrote an invoice’s line description',
        'invoice.description_updated' => 'Changed an invoice’s description',
        'invoice.emailed' => 'Emailed an invoice',
        'invoice.issued_from_schedule' => 'Issued an invoice from a payment schedule',
        'invoice.issued_manually' => 'Issued invoices by hand',
        'invoice.late_fee_applied' => 'Added a late fee to an invoice',
        'invoice.line_added' => 'Added a line to an invoice',
        'invoice.line_removed' => 'Removed a line from an invoice',
        'invoice.line_updated' => 'Changed an invoice line',
        'invoice.renumbered' => 'Renumbered an invoice',
        'invoice.voided' => 'Voided an invoice',
        'invoice.voided_external' => 'Voided an iLearn invoice',
        'invoice.withdrawn_upstream' => 'An invoice was withdrawn by iLearn',
        'lesson_plan.saved' => 'Saved a lesson plan',
        'lesson_template.created' => 'Created a lesson template',
        'lesson_template.removed' => 'Removed a lesson template',
        'licence.billing_monthly' => 'Moved a licence to monthly billing',
        'licence.granted' => 'Granted a licence',
        'licence.updated' => 'Changed a licence',
        'licence.white_label_off' => 'Turned off white-label branding on a licence',
        'line.text_sent' => 'Sent a text from the KiddieTrac line',
        'login_blocked' => 'Was refused sign-in',
        'login_brand_blocked' => 'Was refused sign-in in the wrong app',
        'managed_form.audience_restored' => 'Restored who a form goes to',
        'managed_form.bulk_assign' => 'Assigned forms to several people',
        'managed_form.countersign_setting' => 'Changed a form’s counter-signature setting',
        'managed_form.countersigned' => 'Counter-signed a form',
        'managed_form.documents_synced' => 'Synced a form’s documents',
        'managed_form.notice_skipped_no_address' => 'Could not send a form notice (no email address)',
        'managed_form.package_copies_resent' => 'Resent copies of a form package',
        'managed_form.package_resent' => 'Resent a form package',
        'managed_form.package_send_deleted' => 'Deleted a form package send',
        'managed_form.resent_single' => 'Resent a form',
        'managed_form.returned_to_signer' => 'Sent a form back to the signer for correction',
        'managed_form.signoff_deleted' => 'Deleted a form sign-off',
        'medication.requested' => 'Requested medication permission',
        'meeting.cancelled' => 'Cancelled a meeting',
        'meeting.created' => 'Scheduled a meeting',
        'meeting.updated' => 'Changed a meeting',
        'message.deleted' => 'Deleted a message',
        'message.edited' => 'Edited a message',
        'message.emergency' => 'Sent an EMERGENCY message',
        'message.nudge' => 'Nudged someone about a message',
        'message.sent' => 'Sent a message',
        'messaging.rules_changed' => 'Changed the messaging rules',
        'messaging_rules.updated' => 'Changed the messaging rules',
        'mfa.enabled' => 'Turned on two-step sign-in',
        'mfa.setup_started' => 'Started setting up two-step sign-in',
        'notification_sounds.reset' => 'Reset notification sounds',
        'notification_sounds.updated' => 'Changed notification sounds',
        'parent_signup_by_code' => 'Signed up as a parent with a code',
        'passkey.enrolled' => 'Added a passkey',
        'passkey.login_failed' => 'Failed to sign in with a passkey',
        'passkey.login_unknown_credential' => 'Tried to sign in with an unknown passkey',
        'password_changed' => 'Changed their password',
        'payment.receipt_recorded' => 'Recorded a payment receipt',
        'payment.recorded_manually' => 'Recorded a payment by hand',
        'payment_schedule.cancelled' => 'Cancelled a payment schedule',
        'payment_schedule.installment_removed' => 'Removed a payment schedule instalment',
        'payment_schedule.installment_updated' => 'Changed a payment schedule instalment',
        'payment_schedule.issue_dates_realigned' => 'Realigned payment schedule issue dates',
        'payment_schedule.updated' => 'Changed a payment schedule',
        'payroll.manual_run' => 'Ran payroll by hand',
        'payroll_document.emailed' => 'Emailed a payslip',
        'payroll_document.notice_emailed' => 'Emailed a staff member their new payslip',
        'payroll_document.reassigned' => 'Reassigned a payslip to another person',
        'payroll_document.voided' => 'Voided pay documents',
        'perf.ui_freeze' => 'A portal screen froze',
        'permissions.mode' => 'Changed the permissions mode',
        'photo.tagged' => 'Tagged children in a photo',
        'platform.ai_off' => 'Turned off AI for an agency',
        'platform.ai_settings' => 'Changed AI settings',
        'platform.ai_topup' => 'Added AI credit',
        'platform.ai_topup_removed' => 'Removed AI credit',
        'platform.app_versions' => 'Changed the app version settings',
        'platform.backup_settings_changed' => 'Changed backup settings',
        'platform.billing_emails_saved' => 'Changed the KiddieTrac billing emails',
        'platform.cloudways' => 'Changed server settings',
        'platform.invoice_emails_saved' => 'Changed where KiddieTrac invoices are sent',
        'platform.overage_charged' => 'Charged an overage',
        'platform.overage_settings' => 'Changed overage settings',
        'platform.overage_undone' => 'Undid an overage charge',
        'platform.overage_waived' => 'Waived an overage',
        'platform.recurring_invoices_saved' => 'Changed recurring invoice settings',
        'platform_invoice.complimentary_lines' => 'Added complimentary lines to a KiddieTrac invoice',
        'platform_invoice.created' => 'Created a KiddieTrac invoice',
        'platform_invoice.edited' => 'Edited a KiddieTrac invoice',
        'platform_invoice.extra_deleted' => 'Removed an extra charge from a KiddieTrac invoice',
        'platform_invoice.extra_saved' => 'Saved an extra charge on a KiddieTrac invoice',
        'platform_invoice.issued' => 'Issued a KiddieTrac invoice',
        'platform_invoice.late_fee' => 'Added a late fee to a KiddieTrac invoice',
        'platform_invoice.late_fee_waived' => 'Waived a late fee on a KiddieTrac invoice',
        'platform_invoice.line_added' => 'Added a line to a KiddieTrac invoice',
        'platform_invoice.line_removed' => 'Removed a line from a KiddieTrac invoice',
        'platform_invoice.payment_confirmed' => 'Confirmed a payment on a KiddieTrac invoice',
        'platform_invoice.payment_recorded' => 'Recorded a payment on a KiddieTrac invoice',
        'platform_invoice.payment_rejected' => 'Rejected a reported payment on a KiddieTrac invoice',
        'platform_invoice.payment_reported' => 'Reported a payment on a KiddieTrac invoice',
        'platform_invoice.payment_returned' => 'A payment on a KiddieTrac invoice was returned',
        'platform_invoice.prepay' => 'Recorded a prepayment on a KiddieTrac invoice',
        'platform_invoice.refund' => 'Refunded a KiddieTrac invoice',
        'platform_invoice.reissued' => 'Reissued a KiddieTrac invoice',
        'platform_invoice.zero_settled' => 'Settled a KiddieTrac invoice with nothing owing',
        'policy.acknowledged' => 'Acknowledged a policy',
        'policy.seeded' => 'Added the starter policies',
        'prospect.intake_submitted' => 'A prospect sent in their intake form',
        'prospect_nda.sent' => 'Sent a prospect an NDA',
        'prospect_nda.signed' => 'A prospect signed their NDA',
        'providers.bio_reminder_settings' => 'Changed provider bio reminder settings',
        'providers.bio_reminder_test' => 'Sent a test provider bio reminder',
        'providers.bio_reminders_sent' => 'Sent provider bio reminders',
        'push.tapped' => 'Opened the app from a notification',
        'qbo.connected' => 'Connected QuickBooks',
        'ratio.recalculated' => 'Recalculated ratios',
        'ratio_alerts.updated' => 'Changed ratio alert settings',
        'ratio_settings.updated' => 'Changed ratio settings',
        'refund.created' => 'Issued a refund',
        'role.deactivated' => 'Turned off a role',
        'role.granted' => 'Gave someone a role',
        'role.mis_filing_withdrawn' => 'Withdrew a mis-filed role',
        'roles.matrix_aligned' => 'Aligned the role permissions',
        'rsvp.event_deleted' => 'Deleted an event',
        'rsvp.event_saved' => 'Saved an event',
        'rsvp.image_uploaded' => 'Uploaded an event image',
        'rsvp.invitee_removed' => 'Removed someone from an event',
        'rsvp.invitees_added' => 'Invited people to an event',
        'rsvp.invites_queued' => 'Queued event invitations',
        'rsvp.opted_out' => 'Opted out of event emails',
        'rsvp.reminder_cancelled' => 'Cancelled an event reminder',
        'rsvp.reminder_saved' => 'Saved an event reminder',
        'rsvp.reminder_sent' => 'Sent an event reminder',
        'rsvp.report_exported' => 'Exported an event report',
        'rsvp.sender_checked' => 'Checked the event email sender',
        'rsvp.sender_connect_started' => 'Started connecting the event email sender',
        'rsvp.sender_connected' => 'Connected the event email sender',
        'rsvp.sender_saved' => 'Saved the event email sender',
        'rsvp.sender_test' => 'Tested the event email sender',
        'rsvp.template_saved' => 'Saved an event email template',
        'rsvp.test_sent' => 'Sent a test event invitation',
        'safe_arrival.parent_answered' => 'A parent answered a Safe Arrival call',
        'safe_arrival.resolved' => 'Resolved a Safe Arrival check',
        'safe_arrival.running_late' => 'Logged that a child is running late',
        'safe_arrival.running_late_cancelled' => 'Took back a child\'s running-late time',
        'safe_departure.running_late' => 'Logged a late pickup for a child',
        'safe_departure.running_late_cancelled' => 'Took back a child\'s late pickup time',
        'staff.break_overrun' => 'A lunch or break ran over its limit (directors/admins alerted)',
        'safe_arrival.settings' => 'Changed Safe Arrival settings',
        'safe_departure.resolved' => 'Resolved a Safe Departure check',
        'safe_departure.unconfirmed' => 'A Safe Departure was not confirmed',
        'sage.connected' => 'Connected Sage',
        'sales.activity_edited' => 'Edited a sales activity',
        'sales.activity_file_added' => 'Attached a file to a sales activity',
        'sales.activity_file_removed' => 'Removed a file from a sales activity',
        'sales.campaign_approved' => 'Approved a sales campaign',
        'sales.campaign_created' => 'Created a sales campaign',
        'sales.campaign_interested' => 'A recipient said they were interested in a campaign',
        'sales.campaign_removed' => 'Removed a sales campaign',
        'sales.campaign_scheduled' => 'Scheduled a sales campaign',
        'sales.campaign_sent' => 'Started sending a sales campaign',
        'sales.campaign_test' => 'Sent a test of a sales campaign',
        'sales.campaign_template_created' => 'Created a campaign email template',
        'sales.campaign_template_updated' => 'Edited a campaign email template',
        'sales.campaign_template_removed' => 'Removed a campaign email template',
        'sales.campaign_template_applied' => 'Used an email template for a sales campaign',
        'sales.campaign_unsubscribed' => 'A recipient unsubscribed from a campaign',
        'sales.contract_uploaded' => 'Uploaded a signed contract',
        'sales.email_sent' => 'Sent a sales email',
        'sales.lead_deleted' => 'Deleted a sales lead',
        'sales.lead_from_voicemail' => 'Created a sales lead from a voicemail',
        'sales.lead_merged' => 'Merged two sales leads',
        'sales.leads_not_duplicates' => 'Marked sales leads as not duplicates',
        'sales.library_folder_created' => 'Created a folder in the Files repository',
        'sales.library_moved' => 'Moved a file in the Files repository',
        'sales.library_removed' => 'Removed a file from the Files repository',
        'sales.proposal_approved' => 'Approved a proposal',
        'sales.proposal_rejected' => 'Rejected a proposal',
        'sales.prospect_unsubscribed' => 'A prospect unsubscribed',
        'sales_quote.client_signed' => 'A client signed a proposal',
        'sales_quote.countersigned' => 'Counter-signed a proposal',
        'security.alerts_acknowledged' => 'Acknowledged security alerts',
        'security.alerts_cleared' => 'Cleared security alerts',
        'security.login_unlocked' => 'Unlocked a sign-in',
        'security.offboard_swept' => 'Cleaned up after departed accounts',
        'security.password_reset_forced' => 'Forced a password reset',
        'settings.learning_framework' => 'Changed the learning framework',
        'settings.instant_moments_updated' => 'Changed instant moment notifications to families',
        'settings.staff_notifications_updated' => 'Changed required app notifications for staff',
        'staff_notifications.instructions_sent' => 'Sent notification setup instructions to staff',
        'settings.record_reminders' => 'Changed record reminder settings',
        'settings.sleep_checks' => 'Changed sleep check settings',
        'settings.walks_background' => 'Changed Walk Watch background settings',
        'shift.created' => 'Added a shift',
        'shift.removed' => 'Removed a shift',
        'shift.standing_saved' => 'Saved standing hours',
        'signin.new_device' => 'Signed in on a new device',
        'signin.new_device_and_location' => 'Signed in on a new device from a new place',
        'signin.new_location' => 'Signed in from a new place',
        'sleep_check.recorded' => 'Recorded a sleep check',
        'sms.broadcast_reached_nobody' => 'A text broadcast reached nobody',
        'sms.failed' => 'A text message failed',
        'sms.received' => 'Received a text message',
        'sms.reply_refused' => 'A text reply was not sent',
        'sms.reply_sent' => 'Replied to a text message',
        'sms.sent' => 'Sent a text message',
        'sms.settings.telnyx_profile_set' => 'Set the texting provider profile',
        'sms.settings.test' => 'Tested text-message settings',
        'sms.settings.test_send' => 'Sent a test text message',
        'sms.settings.updated' => 'Changed text-message settings',
        'sms.skipped' => 'Did not send a text message',
        'sms_consent.declined' => 'Declined text messages',
        'sms_consent.granted' => 'Agreed to text messages',
        'sms_consent.invites_sent' => 'Asked families to agree to text messages',
        'sms_consent.test_reverted' => 'Reverted a text-consent test',
        'staff.certification_added' => 'Added a staff certificate',
        'staff.certification_removed' => 'Removed a staff certificate',
        'staff.certification_updated' => 'Changed a staff certificate',
        'staff.certification_verified' => 'Verified a staff certificate',
        'staff.certification_viewed' => 'Opened a staff certificate',
        'staff.offboarded' => 'Offboarded a staff member',
        'staff.payout_method_cleared' => 'Cleared a staff member’s payout details',
        'staff.payout_method_revealed' => 'Revealed a staff member’s bank details',
        'staff.payout_method_updated' => 'Changed a staff member’s payout details',
        'subsidy.created' => 'Added a subsidy',
        'subsidy.document_added' => 'Added a subsidy document',
        'subsidy.document_removed' => 'Removed a subsidy document',
        'subsidy.ended' => 'Ended a subsidy',
        'subsidy.removed' => 'Removed a subsidy',
        'subsidy.updated' => 'Changed a subsidy',
        'summaries.timing_changed' => 'Changed when daily summaries are sent',
        'supplies.cancelled' => 'Cancelled a supplies request',
        'supplies.list_changed' => 'Changed the supplies list',
        'supplies.received' => 'Marked supplies received',
        'supplies.requested' => 'Asked a family to bring supplies',
        'support_ticket.closed' => 'Closed a support ticket',
        'ticket.resolved' => 'Resolved a support ticket',
        'timeoff.reminder_settings' => 'Changed time-off reminder settings',
        'timeoff.reminder_test' => 'Sent a test time-off reminder',
        'timeoff.reminders_sent' => 'Sent time-off reminders',
        'timepunch.corrected' => 'Corrected a time punch',
        'timepunch.created' => 'Added a time punch',
        'timepunch.deleted' => 'Deleted a time punch',
        'training.published_all' => 'Published all training',
        'update_family' => 'Changed a family',
        'user.avatar_updated' => 'Changed a profile photo',
        'user.created_for_forms' => 'Created an account so forms could be sent',
        'user.onboarding_reopened' => 'Reopened someone’s onboarding',
        'user.profile_backfilled' => 'Filled in missing profile details',
        'user.profile_restored' => 'Restored a profile',
        'user.profile_updated' => 'Changed a profile',
        'user.reactivated' => 'Reactivated an account',
        'user.restored' => 'Restored an account',
        'user.restored_after_test_decline' => 'Restored an account after a test decline',
        'user.role_set' => 'Changed someone’s role',
        'user.temp_linked' => 'Linked a temporary account',
        'user.temp_removed' => 'Removed a temporary account',
        'user.updated' => 'Changed an account',
        'voice.announcement.sent' => 'Sent a voice announcement',
        'voice.greeting_published' => 'Published a phone greeting',
        'voice.menu' => 'Changed the phone menu',
        'voice.menu_saved' => 'Saved the phone menu',
        'voice.settings.disabled' => 'Turned off phone calls',
        'voice.settings.enabled' => 'Turned on phone calls',
        'voice.settings.telnyx_setup' => 'Set up the phone provider',
        'voice.voicemail_deleted' => 'Deleted a voicemail',
        'voice.voicemail_reply' => 'Replied to a voicemail',
        'waitlist.added' => 'Added a child to the waitlist',
        'waitlist.declined' => 'Declined a waitlist place',
        'waitlist.promoted' => 'Offered a waitlist place',
        'waitlist.reminded' => 'Sent a waitlist reminder',
        'walk.gps' => 'Walk Watch GPS update',
        'webpush' => 'Sent a browser notification',
        'website_chat.deleted' => 'Deleted a website chat',
        'xero.connected' => 'Connected Xero',
        'announcement.personal' => 'Announced something to one person',
        'payroll_document.resent' => 'Resent a payslip',
        'etransfer.requested' => 'Asked for an e-Transfer request',
        'etransfer.request_sent' => 'Sent an e-Transfer request',
        'etransfer.paid' => 'Marked an e-Transfer request paid',
        'etransfer.cancelled' => 'Cancelled an e-Transfer request',
    ];

    /** The plain sentence for an action, or null when none is written for it. */
    public static function verbFor(string $action): ?string
    {
        $action = (string) preg_replace('/ \[fail\]$/i', '', $action);

        return self::VERBS[$action] ?? self::MORE[$action] ?? self::patternVerb($action);
    }

    /** Every named action that has a written sentence (for kt:audit-raw-check). */
    public static function known(): array
    {
        return array_merge(array_keys(self::VERBS), array_keys(self::MORE));
    }

    /** entity_type -> [table, columns to build a name from]. */
    private const ENTITIES = [
        'user'         => ['users', ['first_name', 'last_name']],
        'child'        => ['children', ['first_name', 'last_name']],
        'family'       => ['families', ['family_name']],
        'centre'       => ['centres', ['name']],
        'conversation' => ['conversations', []],
    ];

    /**
     * Narrate a page of rows in place. Each row gains:
     *   description  the sentence
     *   actor_name   who did it
     *   action_raw   the original action, kept for filtering and support
     *
     * @param  Collection<int,object>  $rows
     */
    public static function narrate(Collection $rows, ?array $visibleUserIds = null, bool $platformReader = false): Collection
    {
        $names = self::resolveNames($rows);
        $archives = self::resolveChatArchives($rows, $platformReader);
        $payloadNames = self::resolvePayloadNames($rows);
        $people = self::resolveUnsignedPeople($rows, $visibleUserIds);
        // Resellers acting inside an agency are labelled as such (2026-10-21).
        $resellerActors = [];
        try {
            $ids = $rows->pluck('user_id')->filter()->unique()->values()->all();
            if ($ids) {
                $resellerActors = array_flip(DB::table('role_assignments')->whereIn('user_id', $ids)
                    ->where('role', 'reseller_admin')->where('active', true)->pluck('user_id')->map(fn ($v) => (int) $v)->all());
            }
        } catch (Throwable $e) {
        }

        return $rows->map(function ($row) use ($names, $payloadNames, $people, $resellerActors, $archives) {
            try {
                $action = (string) ($row->action ?? '');
                /* A refused attempt is recorded as the action with " [fail]" appended,
                   which the exact-match lookup below could never hit — so every failed
                   action in the whole log fell through to the path humaniser and read
                   as "Created zum pay [fail]" instead of the sentence its successful
                   twin gets. Stripped for the lookup, said plainly afterwards. */
                $failed = (bool) preg_match('/ \[fail\]$/i', $action);
                $lookup = (string) preg_replace('/ \[fail\]$/i', '', $action);

                /* Rows written before 2026-09-02 carry a "generated::xxxx" placeholder
                   instead of an action, because a cached route has a name even when
                   nobody gave it one. The method and path were recorded correctly in the
                   payload all along, so rebuild the lookup from those rather than
                   rewriting history -- an audit row should be read differently, never
                   edited quietly. */
                if (str_starts_with($lookup, 'generated::')) {
                    $rebuilt = self::actionFromPayload($row);
                    if ($rebuilt !== '') {
                        $lookup = $rebuilt;
                    }
                }

                $verb = self::VERBS[$lookup] ?? self::MORE[$lookup] ?? self::patternVerb($lookup);
                if ($verb !== null) {
                    if ($failed) {
                        $verb .= ' — failed';
                    }
                } else {
                    /* AdminController already labelled the row from the route catalogue
                       ("Couldn't load household (not allowed)"); the path humaniser only
                       ever produced "changed household — failed". */
                    $label = trim((string) ($row->action_label ?? ''));
                    $verb = $label !== '' ? $label : self::humanisePath($lookup) . ($failed ? ' — failed' : '');
                }

                /* Some endpoints resolve the actor in SQL and hand us a ready
                   actor_name without the split name columns. Take theirs when it
                   exists -- rebuilding it from fields that were never selected is
                   how every row briefly became "Someone". */
                $actor = trim((string) ($row->actor_name ?? ''));
                /* A scheduled job is not an unknown person. Keep the distinction:
                   an auditor needs to tell a cron run apart from an unidentified
                   human action. */
                if ($actor === 'system') {
                    $actor = '';
                }
                if ($actor === '') {
                    $actor = trim(($row->first_name ?? '') . ' ' . ($row->last_name ?? ''));
                }
                if ($actor === '') {
                    $actor = (string) ($row->actor_email ?? $row->email ?? '');
                }
                if ($actor === '') {
                    /* No user on the row does not mean an unknown person. Most of these
                       are the platform acting on its own — a nightly job, a mailer, a
                       payment provider calling back — and "Someone sent an email" reads
                       as though a person did it. Worse for the auto sign-outs: the
                       compliance report exists to separate "the system closed this" from
                       "a person closed this", and this flattened the two.

                       Matched by action family rather than blanket, because login_failed
                       with no user genuinely IS an unknown person typing an address we do
                       not recognise, and calling that "System" would be a lie exactly
                       where it matters most. */
                    $actor = self::systemActorFor($lookup);
                    if ($actor === '') {
                        $actor = $people[(int) ($row->id ?? 0)] ?? 'Someone (not signed in)';
                    }
                }

                if (isset($resellerActors[(int) ($row->user_id ?? 0)]) && ! str_contains($actor, '(Reseller)')) {
                    $actor .= ' (Reseller)';
                }
                $subject = self::subjectFor($row, $names, $payloadNames);
                $arch = $archives[(int) ($row->id ?? 0)] ?? null;
                if ($arch) {
                    $verb = $arch['verb'] . ($failed ? ' — failed' : '');
                    $subject = $arch['subject'];
                    $row->summary = $failed ? 'Refused — nothing was changed.' : $arch['summary'];
                    $row->details = $arch['details'];
                }
                if ($subject === '' && trim((string) ($row->entity_name ?? '')) !== '') {
                    $subject = trim((string) $row->entity_name);
                }

                $row->action_raw = $action;
                $row->actor_name = $actor;
                $row->description = $actor . ' — ' . lcfirst($verb) . ($subject ? ' (' . $subject . ')' : '');
                // The screen renders `action`; overwriting it means the sentence shows
                // without the front end having to change. action_raw keeps the original.
                $row->action = $verb . ($subject ? ': ' . $subject : '');

                /* Blank the entity fields when they name nothing. AuditActivity derives
                   entity_type from a path segment, so hundreds of rows carry the literal
                   string "id" -- which is exactly what the screen was printing as
                   "id: 123". If we could not resolve a name, there is nothing here worth
                   showing. */
                if ($subject === '' || ! isset(self::ENTITIES[(string) ($row->entity_type ?? '')])) {
                    $row->entity_type = null;
                    $row->entity_id = null;
                }
                if (trim((string) ($row->entity_name ?? '')) === '' && $subject !== '') {
                    $row->entity_name = $subject;
                }
                /* The record the row is ABOUT wins over a title found in the payload (2026-10-31): a push row's
                   "What" read "💵 Your payslip is ready (User)" instead of the person it was for. */
                $__t = (string) ($row->entity_type ?? '');
                $__i = (int) ($row->entity_id ?? 0);
                if ($__t !== '' && $__i > 0 && isset($names[$__t][$__i])) {
                    $row->entity_name = (string) $names[$__t][$__i];
                }
                // The screen's main line is this sentence, so both read the same.
                $row->action_label = $verb;
            } catch (Throwable $e) {
                // A row that will not narrate must still be listed.
            }

            return $row;
        });
    }

    /**
     * WHO an unsigned row was, when it can be known (2026-10-21).
     *
     * A row with no user_id was printed as "System" whatever it was - which is how
     * Alysia Stephenson Sterling's eight refused invite attempts read as the platform
     * acting on its own. In order of certainty:
     *
     *   1. the payload names the account (name / account) - that person;
     *   2. it carries what was typed (login / email / identifier / username) and that
     *      matches an account the reader may see - that person;
     *   3. what was typed matches nobody the reader may see - say what was typed;
     *   4. nothing typed - the account signed in from the SAME device (address and
     *      browser) closest in time, within three hours, marked "probably".
     *
     * Names come only from $visibleUserIds (null on the platform view), so a row in one
     * agency's log can never name somebody from another. Two queries per page.
     *
     * @return array<int,string> row id => who
     */
    private static function resolveUnsignedPeople(Collection $rows, ?array $visibleUserIds): array
    {
        $out = [];
        $typed = [];
        $guess = [];
        foreach ($rows as $row) {
            if (! empty($row->user_id) || ! empty($row->actor_email)) {
                continue;
            }
            $p = json_decode((string) ($row->payload ?? ''), true);
            $p = is_array($p) ? $p : [];
            $in = is_array($p['input'] ?? null) ? $p['input'] : [];
            $named = $p['name'] ?? $p['account'] ?? null;
            if (is_string($named) && trim($named) !== '' && ! str_contains($named, '@')) {
                $out[(int) $row->id] = trim($named) . ' (not signed in)';
                continue;
            }
            $t = $p['login'] ?? $p['email'] ?? $p['identifier'] ?? $p['username']
                ?? $in['login'] ?? $in['email'] ?? $in['identifier'] ?? $in['username'] ?? null;
            if (is_string($t) && trim($t) !== '' && mb_strlen($t) <= 120) {
                $typed[(int) $row->id] = trim($t);
            } elseif (! empty($row->ip_address)) {
                $guess[(int) $row->id] = $row;
            }
        }

        try {
            if ($typed) {
                $keys = array_values(array_unique(array_map('mb_strtolower', $typed)));
                $q = DB::table('users')->whereNull('deleted_at')
                    ->where(function ($w) use ($keys) {
                        $w->whereIn(DB::raw('LOWER(email)'), $keys)->orWhereIn(DB::raw('LOWER(username)'), $keys);
                    });
                if ($visibleUserIds !== null) {
                    $q->whereIn('id', $visibleUserIds ?: [0]);
                }
                $found = [];
                foreach ($q->orderBy('id')->get(['id', 'first_name', 'last_name', 'email', 'username']) as $u) {
                    $n = trim(($u->first_name ?? '') . ' ' . ($u->last_name ?? '')) ?: (string) $u->email;
                    foreach ([$u->email, $u->username] as $k) {
                        $k = mb_strtolower((string) $k);
                        if ($k === '') {
                            continue;
                        }
                        // An email shared by several accounts names them all, not the first.
                        $found[$k] = isset($found[$k]) && ! str_contains($found[$k], $n) ? $found[$k] . ' / ' . $n : $n;
                    }
                }
                foreach ($typed as $rid => $t) {
                    $out[$rid] = isset($found[mb_strtolower($t)])
                        ? $found[mb_strtolower($t)] . ' (not signed in)'
                        : '“' . $t . '” (not signed in)';
                }
            }

            if ($guess) {
                $ips = array_values(array_unique(array_map(fn ($r) => (string) $r->ip_address, $guess)));
                $times = array_map(fn ($r) => strtotime((string) $r->created_at) ?: 0, $guess);
                $from = date('Y-m-d H:i:s', min($times) - 3 * 3600);
                $to = date('Y-m-d H:i:s', max($times) + 3 * 3600);
                $q = DB::table('audit_logs as a')->join('users as u', 'u.id', '=', 'a.user_id')
                    ->whereIn('a.ip_address', $ips)->whereNotNull('a.user_id')
                    ->whereBetween('a.created_at', [$from, $to]);
                if ($visibleUserIds !== null) {
                    $q->whereIn('a.user_id', $visibleUserIds ?: [0]);
                }
                $seen = $q->limit(5000)->get(['a.ip_address', 'a.user_agent', 'a.created_at', 'u.first_name', 'u.last_name', 'u.email']);
                foreach ($guess as $rid => $row) {
                    $t0 = strtotime((string) $row->created_at) ?: 0;
                    $best = null;
                    $bestGap = 3 * 3600 + 1;
                    foreach ($seen as $s) {
                        if ($s->ip_address !== $row->ip_address) {
                            continue;
                        }
                        // Same address AND the same browser - an office or a household
                        // shares an address, not usually a browser build.
                        if (! empty($row->user_agent) && ! empty($s->user_agent) && $s->user_agent !== $row->user_agent) {
                            continue;
                        }
                        $gap = abs((strtotime((string) $s->created_at) ?: 0) - $t0);
                        if ($gap < $bestGap) {
                            $bestGap = $gap;
                            $best = $s;
                        }
                    }
                    if ($best) {
                        $n = trim(($best->first_name ?? '') . ' ' . ($best->last_name ?? '')) ?: (string) $best->email;
                        $out[$rid] = 'Probably ' . $n . ' (same device, not signed in)';
                    }
                }
            }
        } catch (Throwable $e) {
            // Unnamed beats unavailable.
        }

        return $out;
    }

    /**
     * Names for ids that live in the PAYLOAD rather than the entity columns.
     *
     * Most rows are written by the generic activity middleware, which records the
     * request body but cannot know which field identifies the subject. So
     * "checked a child out" carried child_id=91 and displayed nothing.
     *
     * One query per type for the entire page.
     *
     * @return array{child: array<int,string>, family: array<int,string>, thread: array<int,string>}
     */
    /** The routes that archive or restore one conversation for the person doing it (ChatArchiveController::store). */
    private const ARCHIVE_ACTIONS = ['post:api/v1/chat-archive', 'post:api/v1/provider/chat-archive'];

    /**
     * Archiving a chat, in words (2026-11-02, Anthony: "archived a chat in audit log shows no
     * details, its raw logging"). The middleware stored only {kind, id, archived}, so the row read
     * "Archived a chat — kind: web, id: 9, archived: yes" with "Kind: Web browser" in its details,
     * and a RESTORE read "Archived" too. This names the conversation: the family, the colleagues,
     * or the website visitor, and says which way it went.
     *
     * A name is given only from inside the row's own agency (a family through its centre, a staff
     * thread through its agency_id), and a website visitor only to a KiddieTrac super admin reading
     * the log, because website chats are the platform's own sales inbox. Otherwise the kind is said
     * without a name. Three queries per page at most.
     *
     * @return array<int,array{verb:string,subject:string,summary:string,details:array}>
     */
    private static function resolveChatArchives(Collection $rows, bool $platformReader): array
    {
        $want = ['family' => [], 'staff' => [], 'web' => []];
        $hits = [];
        foreach ($rows as $row) {
            $a = (string) preg_replace('/ \[fail\]$/i', '', (string) ($row->action ?? ''));
            if (! in_array($a, self::ARCHIVE_ACTIONS, true)) {
                continue;
            }
            $p = json_decode((string) ($row->payload ?? ''), true);
            $in = is_array($p) && is_array($p['input'] ?? null) ? $p['input'] : [];
            $kind = (string) ($in['kind'] ?? '');
            $id = (int) ($in['id'] ?? 0);
            if (! isset($want[$kind]) || $id <= 0) {
                continue;
            }
            $want[$kind][$id] = true;
            $archived = filter_var($in['archived'] ?? true, FILTER_VALIDATE_BOOLEAN);
            $hits[(int) $row->id] = [$kind, $id, $archived, (int) ($row->agency_id ?? 0), (int) ($row->user_id ?? 0), (int) ($p['status'] ?? 200)];
        }
        if (! $hits) {
            return [];
        }

        $family = [];
        $staff = [];
        $web = [];
        try {
            if ($want['family']) {
                foreach (DB::table('conversations as c')
                    ->leftJoin('families as f', 'f.id', '=', 'c.family_id')
                    ->leftJoin('centres as ce', 'ce.id', '=', 'c.centre_id')
                    ->whereIn('c.id', array_keys($want['family']))
                    ->get(['c.id', 'f.family_name', 'ce.agency_id']) as $c) {
                    $family[(int) $c->id] = [trim((string) $c->family_name), (int) $c->agency_id];
                }
            }
            if ($want['staff']) {
                foreach (DB::table('staff_threads')->whereIn('id', array_keys($want['staff']))
                    ->get(['id', 'agency_id', 'title', 'is_group']) as $t) {
                    $staff[(int) $t->id] = ['agency' => (int) $t->agency_id, 'title' => $t->is_group ? trim((string) $t->title) : '', 'people' => []];
                }
                foreach (DB::table('staff_thread_participants as p')->join('users as u', 'u.id', '=', 'p.user_id')
                    ->whereIn('p.thread_id', array_keys($staff ?: [0 => 1]))
                    ->get(['p.thread_id', 'p.user_id', 'u.first_name', 'u.last_name']) as $pt) {
                    $staff[(int) $pt->thread_id]['people'][(int) $pt->user_id] = trim(($pt->first_name ?? '') . ' ' . ($pt->last_name ?? ''));
                }
            }
            if ($want['web'] && $platformReader) {
                foreach (DB::table('web_chats')->whereIn('id', array_keys($want['web']))->get(['id', 'name', 'email']) as $w) {
                    $web[(int) $w->id] = trim((string) $w->name) !== '' ? trim((string) $w->name) : trim((string) $w->email);
                }
            }
        } catch (Throwable $e) {
            // Names are a courtesy; the sentence below still says what happened.
        }

        $out = [];
        foreach ($hits as $rowId => [$kind, $id, $archived, $agency, $actorId, $status]) {
            $with = '';
            if ($kind === 'family') {
                [$fname, $fagency] = $family[$id] ?? ['', 0];
                if ($fname !== '' && ($platformReader || ($agency > 0 && $fagency === $agency))) {
                    $with = preg_match('/\bfamily$/i', $fname) ? 'the ' . $fname : 'the ' . $fname . ' family';
                }
                $what = 'family chat';
            } elseif ($kind === 'staff') {
                $t = $staff[$id] ?? null;
                if ($t && ($platformReader || ($agency > 0 && $t['agency'] === $agency))) {
                    $others = array_filter($t['people'], fn ($n, $uid) => $uid !== $actorId && $n !== '', ARRAY_FILTER_USE_BOTH);
                    $with = $t['title'] !== '' ? $t['title'] : implode(' and ', array_unique(array_values($others)));
                }
                $what = 'staff chat';
            } else {
                $with = $web[$id] ?? '';
                $what = 'website chat';
            }
            $subject = ucfirst($what) . ($with !== '' ? ' with ' . $with : '');
            $other = $kind === 'web' ? 'the visitor' : ($kind === 'family' ? 'the family' : 'the other people in it');
            $summary = $archived
                ? 'Moved the ' . $what . ($with !== '' ? ' with ' . $with : '') . ' off their own chat list. Nothing changes for ' . $other . ', and it comes back by itself if a new message arrives.'
                : 'Put the ' . $what . ($with !== '' ? ' with ' . $with : '') . ' back on their chat list.';
            $out[$rowId] = [
                'verb' => $archived ? 'Archived a chat' : 'Restored an archived chat',
                'subject' => $subject,
                'summary' => $summary,
                'details' => [
                    ['Conversation', $subject],
                    ['Change', $archived ? 'Archived (taken off their own list only)' : 'Restored to their list'],
                    ['Result', $status >= 200 && $status < 300 ? 'Done' : 'Refused - nothing changed'],
                ],
            ];
        }

        return $out;
    }

    private static function resolvePayloadNames(Collection $rows): array
    {
        $childIds = [];
        $familyIds = [];
        $threadIds = [];

        foreach ($rows as $row) {
            try {
                $p = json_decode((string) ($row->payload ?? ''), true) ?: [];
                $in = $p['input'] ?? [];
                if (! empty($in['child_id'])) {
                    $childIds[(int) $in['child_id']] = true;
                }
                if (! empty($in['family_id'])) {
                    $familyIds[(int) $in['family_id']] = true;
                }
                if ((string) ($row->entity_type ?? '') === 'thread' && ! empty($row->entity_id)) {
                    $threadIds[(int) $row->entity_id] = true;
                }
            } catch (Throwable $e) {
            }
        }

        $out = ['child' => [], 'family' => [], 'thread' => [], 'thread_ids' => []];

        try {
            if ($childIds) {
                foreach (DB::table('children')->whereIn('id', array_keys($childIds))
                    ->get(['id', 'first_name', 'last_name', 'preferred_name']) as $c) {
                    $name = trim(($c->preferred_name ?: $c->first_name) . ' ' . ($c->last_name ?? ''));
                    if ($name !== '') {
                        $out['child'][(int) $c->id] = $name;
                    }
                }
            }
            if ($familyIds) {
                foreach (DB::table('families')->whereIn('id', array_keys($familyIds))
                    ->get(['id', 'family_name']) as $f) {
                    if ($f->family_name) {
                        $out['family'][(int) $f->id] = (string) $f->family_name;
                    }
                }
            }
            /* A staff thread has no name of its own — it IS the people in it. Naming
               every participant lets a reader see who was actually messaged. */
            if ($threadIds) {
                $parts = DB::table('staff_thread_participants as p')
                    ->join('users as u', 'u.id', '=', 'p.user_id')
                    ->whereIn('p.thread_id', array_keys($threadIds))
                    ->get(['p.thread_id', 'p.user_id', 'u.first_name', 'u.last_name']);
                $byThread = [];
                foreach ($parts as $pt) {
                    $out['thread_ids'][(int) $pt->thread_id][(int) $pt->user_id] =
                        trim(($pt->first_name ?? '') . ' ' . ($pt->last_name ?? ''));
                    $n = trim(($pt->first_name ?? '') . ' ' . ($pt->last_name ?? ''));
                    if ($n !== '') {
                        $byThread[(int) $pt->thread_id][] = $n;
                    }
                }
                /* Keyed by user id, so the actor can be dropped when the row is
                   narrated -- a message you sent should name who received it, not
                   read your own name back to you. */
                foreach ($byThread as $tid => $names) {
                    $out['thread'][$tid] = implode(' and ', array_unique($names));
                }
            }
        } catch (Throwable $e) {
            // A failed lookup must not take the audit page down.
        }

        return $out;
    }

    /**
     * Verbs for routes that carry an id in the middle, which exact matching cannot reach.
     *
     * "post:api/v1/provider/team-threads/5/send" was falling through to the path
     * humaniser and reading as "Created team threads send".
     */
    private static function patternVerb(string $action): ?string
    {
        $patterns = [
            '#team-threads/\d+/send$#' => 'Sent a staff message',
            '#chats/\d+/send$#' => 'Sent a message',
            '#conversations/\d+/send$#' => 'Sent a message',
            '#children/\d+/check-?in$#' => 'Checked a child in',
            '#children/\d+/check-?out$#' => 'Checked a child out',
            '#managed-forms/\d+/draft$#' => 'Saved a form draft',
            '#managed-forms/\d+/submit$#' => 'Submitted a form',
            '#invoices/\d+/#' => 'Updated an invoice',
            '#users/\d+/resend-welcome$#' => 'Resent a welcome email',
            /* Now visible for the first time: these rows used to carry a generated::
               placeholder, so the path humaniser never saw them and they read as
               "Created walks start". */
            '#walks/start$#' => 'Started a walk',
            '#walks/\d+/end$#' => 'Ended a walk',
            '#walks/\d+/checkpoint$#' => 'Logged a walk checkpoint',
            '#provider/check-?in$#' => 'Checked a child in',
            '#provider/check-?out$#' => 'Checked a child out',
            '#care/logs$#' => 'Logged a care moment',
        ];
        foreach ($patterns as $re => $verb) {
            if (preg_match($re, $action)) {
                return $verb;
            }
        }

        return null;
    }

    /**
     * Everyone in a thread except the person the row is about.
     *
     * Listing every participant means a row about you messaging a colleague reads your
     * own name back at you. What an auditor wants is the other end of the conversation.
     */
    private static function otherParticipants($row, int $threadId, array $payloadNames): string
    {
        $all = $payloadNames['thread_ids'][$threadId] ?? [];
        if (! $all) {
            return '';
        }
        $actorId = (int) ($row->user_id ?? 0);
        $names = [];
        foreach ($all as $uid => $name) {
            if ($uid !== $actorId && trim((string) $name) !== '') {
                $names[] = trim((string) $name);
            }
        }

        return $names ? implode(' and ', array_unique($names)) : '';
    }

    /** Name of the thing the row is about, if we can resolve one. */
    private static function subjectFor($row, array $names, array $payloadNames = []): string
    {
        $type = (string) ($row->entity_type ?? '');
        $id = (int) ($row->entity_id ?? 0);
        if ($type !== '' && $id > 0 && isset($names[$type][$id])) {
            return (string) $names[$type][$id];
        }

        /* A staff thread names the people in it — "sent a staff message" is not much
           use without knowing who received it. */
        if ($type === 'thread' && $id > 0) {
            $others = self::otherParticipants($row, $id, $payloadNames);
            if ($others !== '') {
                return 'to ' . $others;
            }
            if (isset($payloadNames['thread'][$id])) {
                return 'with ' . $payloadNames['thread'][$id];
            }
        }

        // Fall back to something meaningful out of the payload rather than an id.
        try {
            $payload = json_decode((string) ($row->payload ?? ''), true) ?: [];
            $in = $payload['input'] ?? [];
            /* The ids the generic middleware captured. Checked before the generic
               string keys below so "Checked a child out" names the child. */
            if (! empty($in['child_id']) && isset($payloadNames['child'][(int) $in['child_id']])) {
                return $payloadNames['child'][(int) $in['child_id']];
            }
            if (! empty($in['family_id']) && isset($payloadNames['family'][(int) $in['family_id']])) {
                return $payloadNames['family'][(int) $in['family_id']];
            }
            foreach (['subject', 'family_name', 'name', 'title'] as $k) {
                if (! empty($payload[$k]) && is_string($payload[$k])) {
                    return $payload[$k];
                }
            }
            if (! empty($payload['to']) && is_array($payload['to'])) {
                return (string) reset($payload['to']);
            }
        } catch (Throwable $e) {
        }

        return '';
    }

    /**
     * One query per entity type for the whole page.
     *
     * @return array<string,array<int,string>>
     */
    private static function resolveNames(Collection $rows): array
    {
        $byType = [];
        foreach ($rows as $row) {
            $type = (string) ($row->entity_type ?? '');
            $id = (int) ($row->entity_id ?? 0);
            if ($type !== '' && $id > 0 && isset(self::ENTITIES[$type])) {
                $byType[$type][$id] = true;
            }
        }

        $out = [];
        foreach ($byType as $type => $ids) {
            [$table, $cols] = self::ENTITIES[$type];
            try {
                if (empty($cols)) {
                    // No natural name (a conversation); say what it is, not its id.
                    foreach (array_keys($ids) as $id) {
                        $out[$type][$id] = 'conversation';
                    }
                    continue;
                }
                foreach (DB::table($table)->whereIn('id', array_keys($ids))
                    ->get(array_merge(['id'], $cols)) as $rec) {
                    $parts = [];
                    foreach ($cols as $c) {
                        $parts[] = (string) ($rec->$c ?? '');
                    }
                    $name = trim(implode(' ', array_filter($parts)));
                    if ($name !== '') {
                        $out[$type][(int) $rec->id] = $name;
                    }
                }
            } catch (Throwable $e) {
                // A missing table must not break the whole audit page.
            }
        }

        return $out;
    }

    /**
     * "post:api/v1/provider/team-threads/start" -> "Started team threads".
     *
     * Not as good as an explicit entry in VERBS, but far better than showing the route.
     * Anything appearing here often enough to matter should get a real verb above.
     */
    /**
     * "post:care/logs", rebuilt from a row whose action is a framework placeholder.
     *
     * Returns '' when the payload cannot supply both halves, so the caller keeps whatever
     * it had rather than inventing a sentence out of nothing.
     */
    private static function actionFromPayload($row): string
    {
        $raw = $row->payload ?? null;
        if (is_string($raw)) {
            $raw = json_decode($raw, true);
        }
        if (! is_array($raw)) {
            return '';
        }

        $method = strtolower(trim((string) ($raw['method'] ?? '')));
        $path = trim((string) ($raw['path'] ?? ''));
        if ($method === '' || $path === '') {
            return '';
        }

        return $method . ':' . ltrim($path, '/');
    }

    /**
     * Who to name when an audit row has no user.
     *
     * Returns '' for anything not recognised, so an unattributed action stays "Someone"
     * rather than being confidently mislabelled.
     */
    private static function systemActorFor(string $action): string
    {
        // The payment provider calling us back is not "the system" in general — naming it
        // is the difference between "a payment settled" and "Zum Rails settled a payment".
        if (str_starts_with($action, 'zum.')) {
            return 'Zum Rails';
        }

        foreach ([
            'email.', 'push.', 'digest.', 'chat.email_notified',
            'child.auto_check_out', 'staff.auto_clock_out',
            'shift.autofilled', 'holidays.synced',
            // Geocoding and address tidying run unattended from the provider map.
            'centre.geocoded', 'centre.address_corrected',
            // A queued notice, not a person clicking send.
            'family.onboarding_notice_sent',
            'family.departed', 'family.auto', 'invoice.auto', 'retention.',
            'security.', 'perf.', 'walk.auto_closed', 'announcement.sent', 'announcement.personal', 'schedule.',
            // Scheduled jobs and syncs that write with no signed-in person (2026-10-31).
            'invoice.issued_from_schedule', 'invoice.late_fee_applied', 'forms.countersign_reminders_sent',
            'providers.bio_reminders_sent', 'timeoff.reminders_sent', 'sms.sent', 'sms.skipped', 'sms.failed', 'integration.', 'payroll_document.voided',
            'records.', 'immunization.', 'backup.', 'db.',
        ] as $prefix) {
            if (str_starts_with($action, $prefix)) {
                return 'System';
            }
        }

        return '';
    }

    private static function humanisePath(string $action): string
    {
        // Framework-generated names (generated::xxxx) say nothing to a reader.
        if ($action === '' || str_starts_with($action, 'generated::')) {
            return 'Activity';
        }

        $method = 'changed';
        if (str_contains($action, ':')) {
            [$verb, $action] = explode(':', $action, 2);
            $method = match (strtolower($verb)) {
                'post' => 'created',
                'put', 'patch' => 'updated',
                'delete' => 'deleted',
                default => 'changed',
            };
        }

        $path = preg_replace('#^api/v\d+/#', '', $action) ?? $action;
        // Drop ids and the role prefix; they add nothing to the sentence.
        $parts = array_values(array_filter(
            explode('/', (string) $path),
            fn ($p) => $p !== '' && ! ctype_digit($p)
                && ! in_array($p, ['provider', 'parent', 'admin', 'api'], true)
        ));
        if (empty($parts)) {
            return ucfirst($method) . ' a record';
        }

        $what = str_replace(['-', '_'], ' ', implode(' ', $parts));

        return ucfirst($method) . ' ' . $what;
    }
}
