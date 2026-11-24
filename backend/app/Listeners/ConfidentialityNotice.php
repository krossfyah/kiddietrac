<?php

declare(strict_types=1);

namespace App\Listeners;

use Illuminate\Mail\Events\MessageSending;

/**
 * A CONFIDENTIALITY NOTICE ON EVERY EMAIL (2026-11-06).
 *
 * Anthony, after the 2026-09-23 immunization reminders reached providers who did not care for the children named:
 * "wire up a clause on all emails to say ... if this email is not intended for you please destroy delete right away
 * and notify your agency and point of contact".
 *
 * Added here, at send time, because about one email in five never passes through EmailTemplate's footer (campaigns,
 * announcements, RSVP invitations, crash alerts, white-label mail), and a notice that only some emails carry is not
 * a safeguard. Registered after WhiteLabelMail (the words name no brand, so there is nothing for it to change) and
 * before AddPlainTextPart, so a text part built from the HTML carries it too; SuppressAgencyMail translates it with
 * the rest of the message. It goes at the very foot of the message, below every footer. Marked so it is never added
 * twice. Never blocks or cancels a send.
 */
final class ConfidentialityNotice
{
    public const MARK = 'data-kt-confidential';

    public const TITLE = 'Confidentiality notice';

    public const TEXT = 'This email, and anything attached to it, is confidential and may contain personal information '
        . 'about children and families. It is intended only for the person or people it is addressed to. If it has reached '
        . 'you by mistake, please do not read, copy, forward or act on it. Delete it right away, including from your '
        . 'deleted items, destroy any copies, and let the childcare agency that sent it, or your usual point of contact '
        . 'there, know so they can put it right. Thank you.';

    public function handle(MessageSending $event): void
    {
        try {
            $m = $event->message;
            if (! method_exists($m, 'getHtmlBody')) return;
            $html = $m->getHtmlBody();
            if (is_string($html) && trim($html) !== '') {
                if (stripos($html, self::MARK) === false) {
                    $m->html(self::intoHtml($html));
                }
            }
            // A text part the sender wrote itself (AddPlainTextPart builds one from the HTML only when there is none).
            $text = $m->getTextBody();
            if (is_string($text) && trim($text) !== '' && strpos($text, self::TITLE) === false) {
                $m->text(rtrim($text) . "\n\n--\n" . self::TITLE . ': ' . self::TEXT . "\n", 'utf-8');
            }
        } catch (\Throwable $e) {
            // never in the way of a send
        }
    }

    /** The notice as a centred block at the foot of the message (Outlook-safe: width on the attribute). */
    public static function block(): string
    {
        // Muted slate reads on a white and on a dark-mode background alike, so it needs no dark-mode swap.
        return '<table role="presentation" ' . self::MARK . '="1" width="100%" cellpadding="0" cellspacing="0" border="0" style="border-collapse:collapse;">'
            . '<tr><td align="center" style="padding:0 12px;">'
            . '<table role="presentation" width="620" cellpadding="0" cellspacing="0" border="0" style="width:100%;max-width:620px;border-collapse:collapse;">'
            . '<tr><td style="padding:14px 20px 26px;border-top:1px solid #CBD5E1;font-family:Arial,Helvetica,sans-serif;font-size:11px;line-height:1.55;color:#64748B;text-align:left;">'
            . '<strong style="color:#64748B;">' . self::TITLE . ':</strong> ' . htmlspecialchars(self::TEXT, ENT_QUOTES, 'UTF-8')
            . '</td></tr></table></td></tr></table>';
    }

    public static function intoHtml(string $html): string
    {
        $pos = strripos($html, '</body>');
        return $pos === false ? $html . self::block() : substr($html, 0, $pos) . self::block() . substr($html, $pos);
    }
}
