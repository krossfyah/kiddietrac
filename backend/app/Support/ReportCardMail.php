<?php

declare(strict_types=1);

namespace App\Support;

/**
 * THE REPORT CARD EMAIL TO A FAMILY (2026-11-26, Anthony: "email to parent" on a report card's ⋮).
 *
 * A pure builder: the same subject and HTML for the popup's preview and for the real send, so the preview shows
 * exactly what goes out. It carries a secure, expiring link (the signed rc.signed route, one per guardian) rather
 * than an attachment, so a forwarded email stops opening the card after the link expires and nothing about the
 * child sits in a mailbox as a file. Staff are named by first name only. The wrapper is EmailTemplate's, which is
 * dark-mode ready and on the agency's own branding (white label included).
 *
 * Sending is the caller's job, and the caller asks TestAgency first: test agencies send nothing.
 */
final class ReportCardMail
{
    /**
     * @param array{agency_id:?int, child_first:string, term:string, provider:string, guardian_first:string,
     *              sender_first:string, message:string, url:string, expires:string, child_left:bool} $o
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

        $inner .= '<p style="margin:20px 0 6px;"><a href="' . e($o['url']) . '" style="display:inline-block;background:#1F6FB2;'
            . 'color:#ffffff;text-decoration:none;font-weight:700;font-size:15px;padding:13px 26px;border-radius:10px;">'
            . 'Open the report card</a></p>'
            . '<p style="margin:0 0 14px;font-size:13px;line-height:1.5;color:#64748B;">This secure link opens the report card '
            . 'as a PDF you can save or print. It works until ' . e($o['expires']) . '.'
            . (empty($o['child_left']) ? ' Your report cards are also in your portal under Report cards.' : '') . '</p>'
            . '<p style="margin:22px 0 0;font-size:15px;line-height:1.6;color:#2A3D5F;">Kind regards,<br><strong style="color:#0B1A33;">'
            . e($sender !== '' ? $sender . ', ' . $provider : $provider . ' Team') . '</strong></p>';

        $html = \App\Services\EmailTemplate::wrap($o['agency_id'] ?? null, $inner, [
            'eyebrow' => 'REPORT CARD',
            'title' => $child . "'s report card",
            'subtitle' => $term,
            'preheader' => $child . "'s " . ($term !== '' ? $term . ' ' : '') . 'report card is ready.',
        ]);

        return ['subject' => $subject, 'html' => $html];
    }
}
