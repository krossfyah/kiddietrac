<?php

declare(strict_types=1);

namespace App\Support;

/**
 * THE REPORT CARD EMAIL TO A FAMILY (2026-11-26, Anthony: "email to parent" on a report card's ⋮).
 *
 * A pure builder: the same subject and HTML for the popup's preview and for the real send, so the preview shows
 * exactly what goes out. Since 2026-11-26 (Anthony: "the report cards should be an attachment to the email as a pdf")
 * the card travels as a PDF ATTACHMENT - the same file as Download - and the body says so in one line. The secure,
 * expiring link (the signed rc.signed route, one per guardian) is only the fallback for a PDF too big to attach
 * (ReportCardMail::MAX_ATTACH). Staff are named by first name only. The wrapper is EmailTemplate's, which is
 * dark-mode ready and on the agency's own branding (white label included).
 *
 * Sending is the caller's job, and the caller asks TestAgency first: test agencies send nothing.
 */
final class ReportCardMail
{
    /* The largest PDF we attach. Mail leaves through the Gmail API (messages.send with the whole MIME message as
       `raw`) or Microsoft Graph (sendMail with base64 MIME, or JSON contentBytes on its fallback). Graph caps one
       request at about 4 MB and base64 adds a third, so 2.5 MB of PDF stays under every path; Gmail's limit is
       higher. A report card is ~20 KB, logo and signatures included - the link is for the unexpected. */
    public const MAX_ATTACH = 2_500_000;

    /** "Liam S - Spring 2026 Report Card.pdf": child first name, last initial, term; safe characters only. */
    public static function filename(string $first, string $last, string $term): string
    {
        $clean = fn (string $t) => trim(preg_replace('/\s+/', ' ', preg_replace('/[^A-Za-z0-9 ()\-]+/', '', \Illuminate\Support\Str::ascii($t))));
        $name = trim($clean($first) . ' ' . mb_substr($clean($last), 0, 1));
        $term = $clean($term);
        return trim(($name !== '' ? $name : 'Child') . ($term !== '' ? ' - ' . $term : '') . ' Report Card') . '.pdf';
    }

    /**
     * @param array{agency_id:?int, child_first:string, term:string, provider:string, guardian_first:string,
     *              sender_first:string, message:string, url:string, expires:string, child_left:bool,
     *              attachment?:?string} $o  attachment = the attached PDF's file name; null/absent = the link fallback
     * @return array{subject:string, html:string}
     */
    public static function build(array $o): array
    {
        $child = trim((string) $o['child_first']) ?: 'your child';
        $term = trim((string) $o['term']);
        $provider = trim((string) $o['provider']) ?: 'your childcare centre';
        $sender = trim((string) $o['sender_first']);
        $hello = trim((string) $o['guardian_first']);

        $p = fn (string $t) => '<p style="margin:0 0 14px;font-size:15px;line-height:1.6;color:#2A3D5F;">' . nl2br(e($t)) . '</p>';
        $subject = $child . "'s " . ($term !== '' ? $term . ' ' : '') . 'report card';

        $inner = $p($hello !== '' ? 'Hello ' . $hello . ',' : 'Hello,')
            . $p($child . "'s " . ($term !== '' ? $term . ' ' : '') . 'report card from ' . $provider . ' is ready for you.');

        if (! empty($o['child_left'])) {
            $inner .= $p('We know ' . $child . ' is no longer with us. We are sharing this report card for your records.');
        }

        $msg = trim((string) $o['message']);
        if ($msg !== '') {
            $inner .= '<div style="margin:6px 0 16px;padding:12px 16px;background:#F1F6FB;border-left:3px solid #1F6FB2;'
                . 'border-radius:0 8px 8px 0;font-size:15px;line-height:1.6;color:#2A3D5F;">'
                . ($sender !== '' ? '<div style="font-size:12px;font-weight:800;letter-spacing:.5px;text-transform:uppercase;color:#1F6FB2;margin:0 0 4px;">'
                    . e('A note from ' . $sender) . '</div>' : '')
                . nl2br(e($msg)) . '</div>';
        }

        $portal = empty($o['child_left']) ? ' Your report cards are also in your portal under Report cards.' : '';
        if (! empty($o['attachment'])) {
            $inner .= '<p style="margin:18px 0 14px;font-size:15px;line-height:1.6;color:#2A3D5F;">&#128206; The report card is attached to this email as a PDF ('
                . '<strong style="color:#0B1A33;">' . e((string) $o['attachment']) . '</strong>) you can save or print.' . e($portal) . '</p>'
                . '<p style="margin:22px 0 0;font-size:15px;line-height:1.6;color:#2A3D5F;">Kind regards,<br><strong style="color:#0B1A33;">'
                . e($sender !== '' ? $sender . ', ' . $provider : $provider . ' Team') . '</strong></p>';
        } else {
        $inner .= '<p style="margin:20px 0 6px;"><a href="' . e($o['url']) . '" style="display:inline-block;background:#1F6FB2;'
            . 'color:#ffffff;text-decoration:none;font-weight:700;font-size:15px;padding:13px 26px;border-radius:10px;">'
            . 'Open the report card</a></p>'
            . '<p style="margin:0 0 14px;font-size:13px;line-height:1.5;color:#64748B;">This secure link opens the report card '
            . 'as a PDF you can save or print. It works until ' . e($o['expires']) . '.' . $portal . '</p>'
            . '<p style="margin:22px 0 0;font-size:15px;line-height:1.6;color:#2A3D5F;">Kind regards,<br><strong style="color:#0B1A33;">'
            . e($sender !== '' ? $sender . ', ' . $provider : $provider . ' Team') . '</strong></p>';
        }

        $html = \App\Services\EmailTemplate::wrap($o['agency_id'] ?? null, $inner, [
            'eyebrow' => 'REPORT CARD',
            'title' => $child . "'s report card",
            'subtitle' => $term,
            'preheader' => $child . "'s " . ($term !== '' ? $term . ' ' : '') . 'report card is ready.',
        ]);

        return ['subject' => $subject, 'html' => $html];
    }
}
