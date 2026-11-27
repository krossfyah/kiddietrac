<?php

namespace App\Support;

use App\Services\EmailTemplate;

/**
 * FAMILY EMAILS THAT WERE HAND-BUILT, NOW IN THE HOUSE FRAME (2026-11-27 sweep, after Anthony: "supplies needed
 * email ... isn't in the typical format - pls full sweep").
 *
 * Each of these used to draw its own <div> card (or the old KiddieTrac-only Blade layout) instead of the
 * EmailTemplate wrapper, so it had no agency branding, a different header and footer, and no dark palette:
 *  - formRequest: "please complete this form" (FormsController::emailToParents)
 *  - invoiceCopy: the copy of an invoice a parent emails to themselves (LedgerController::emailMyInvoice)
 * Pure builders: no mail, no writes. Plain text comes from AddPlainTextPart.
 */
final class FamilyNoticeMail
{
    private static function e($s): string
    {
        return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
    }

    private static function p(string $t, string $extra = ''): string
    {
        return '<p style="margin:0 0 14px;font-size:15px;line-height:1.65;color:#1E293B;' . $extra . '">' . $t . '</p>';
    }

    private static function button(?int $agencyId, string $label, string $href): string
    {
        $c = self::e(EmailTemplate::brandColours($agencyId)['primary'] ?? '#1F6080');

        return '<table role="presentation" cellpadding="0" cellspacing="0" border="0" style="margin:6px 0 16px;"><tr>'
            . '<td bgcolor="' . $c . '" style="background:' . $c . ';border-radius:10px;">'
            . '<a class="kt-btn" href="' . self::e($href) . '" style="display:inline-block;padding:13px 24px;font-size:15px;font-weight:800;color:#FFFFFF;text-decoration:none;border-radius:10px;">'
            . self::e($label) . '</a></td></tr></table>';
    }

    private static function signoff(string $team): string
    {
        return self::p('Thank you,<br><strong>The ' . self::e($team) . ' team</strong>', 'margin:4px 0 0;');
    }

    private static function team(?int $agencyId): string
    {
        return ($agencyId ? FamilyFacingName::agency($agencyId) : '') ?: 'KiddieTrac';
    }

    /** "Please complete this form". @return array{subject:string, html:string} */
    public static function formRequest(int $agencyId, string $title, ?string $description): array
    {
        $team = self::team($agencyId);
        $body = self::p('Hello,')
            . self::p('Please take a moment to complete <strong>' . self::e($title) . '</strong> for ' . self::e($team) . '. It only takes a few minutes in the app.')
            . ($description ? '<table role="presentation" class="kt-panel" cellpadding="0" cellspacing="0" border="0" width="100%" style="width:100%;background:#F8FAFC;border:1px solid #E2E8F0;border-radius:12px;margin:0 0 16px;"><tr>'
                . '<td style="padding:12px 16px;font-size:14px;line-height:1.55;color:#334155;">' . nl2br(self::e($description)) . '</td></tr></table>' : '')
            . self::button($agencyId, 'Open the form', 'https://app.kiddietrac.com/dashboard.html#forms')
            . self::p('Or sign in to the app and open <strong>Forms</strong>.', 'font-size:13px;color:#64748B;')
            . self::signoff($team);

        return [
            'subject' => 'Please complete: ' . $title . ' — ' . $team,
            'html' => EmailTemplate::wrap($agencyId, $body, [
                'eyebrow' => '📝 FORM TO COMPLETE', 'title' => $title,
                'subtitle' => 'From ' . $team,
                'preheader' => 'Please complete ' . $title . ' for ' . $team . '.',
            ]),
        ];
    }

    /** The copy of an invoice a parent sent to themselves. @return array{subject:string, html:string} */
    public static function invoiceCopy(?int $agencyId, string $number, string $first, float $balance): array
    {
        $team = self::team($agencyId);
        $box = $balance > 0.005
            ? EmailTemplate::calloutBox('Balance due: <strong>$' . number_format($balance, 2) . '</strong>', 'warning')
            : EmailTemplate::calloutBox('This invoice is paid in full. Thank you! 🎉', 'success');
        $body = self::p('Hi ' . self::e($first) . ',')
            . self::p('As you asked, a copy of your invoice <strong>' . self::e($number) . '</strong> from ' . self::e($team) . ' is attached to this email as a PDF.')
            . $box
            . self::button($agencyId, 'View your billing', 'https://app.kiddietrac.com/dashboard.html#billing')
            . self::p('You asked for this copy from your account. If you didn’t, you can ignore this email.', 'font-size:13px;color:#64748B;')
            . self::signoff($team);

        return [
            'subject' => 'Your invoice ' . $number,
            'html' => EmailTemplate::wrap($agencyId, $body, [
                'eyebrow' => '🧾 INVOICE COPY', 'title' => 'Your invoice ' . $number,
                'subtitle' => $balance > 0.005 ? 'Balance due $' . number_format($balance, 2) : 'Paid in full',
                'preheader' => 'A copy of your invoice ' . $number . ' is attached.',
            ]),
        ];
    }
}
