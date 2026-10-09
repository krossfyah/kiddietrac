<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Support\EmailOverrides;
use Illuminate\Mail\Events\MessageSending;

/**
 * The subject line an agency wrote for an email (2026-10-06).
 *
 * EmailTemplate::wrap() applies an agency's heading and paragraphs as it builds the body,
 * but the subject is set by the sender afterwards, so wrap() cannot reach it. When a
 * subject has been overridden, wrap() leaves a marker comment in the HTML naming the
 * email and agency; this swaps the subject and takes the marker out again.
 *
 * Messages without the marker — every email whose subject nobody changed — are not
 * touched at all.
 *
 * It is registered BEFORE SuppressAgencyMail and returns nothing: MessageSending is
 * dispatched with `until`, so a listener that returned a value would stop the rest. A
 * suppressed message is therefore logged with the subject it would have gone out with.
 */
final class EmailSubjectOverride
{
    public function handle(MessageSending $event): void
    {
        // Raw escape codes / double-encoded entities never reach a reader (2026-10-09).
        // First listener, so the log and the suppression gate see the repaired text.
        try { \App\Support\MailText::repair($event->message); } catch (\Throwable $e) {}

        try {
            $msg = $event->message;
            $html = $msg->getHtmlBody();
            if (! is_string($html) || strpos($html, '<!--kt-email-subject:') === false) {
                return;
            }
            $r = EmailOverrides::resolveSubject($html, (string) $msg->getSubject());
            if ($r === null) {
                return;
            }
            $msg->html($r['html']);
            $msg->subject($r['subject']);
        } catch (\Throwable $e) {
            // A subject line must never be the reason an email does not go.
        }
    }
}
