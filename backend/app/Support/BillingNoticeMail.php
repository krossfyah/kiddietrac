<?php

namespace App\Support;

use App\Services\EmailTemplate;

/**
 * FAMILY EMAILS FROM THE AUTOMATED BILLING FEATURES (2026-11-28), in the house frame.
 *
 *  - tuitionIncrease: the notice before a tuition increase takes effect (TuitionIncreases::notify)
 *
 * Pure builders: no mail, no writes. Plain text comes from AddPlainTextPart. Greeting by the guardian's first name,
 * signed by the agency the way families know it (FamilyFacingName).
 */
final class BillingNoticeMail
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

    private static function team(?int $agencyId): string
    {
        return ($agencyId ? FamilyFacingName::agency($agencyId) : '') ?: 'KiddieTrac';
    }

    private static function signoff(string $team): string
    {
        return self::p('Thank you,<br><strong>The ' . self::e($team) . ' team</strong>', 'margin:4px 0 0;');
    }

    /** A card of rows: [[left, right], ...]. */
    private static function card(array $rows, ?array $head = null): string
    {
        $h = '<table role="presentation" class="kt-panel" cellpadding="0" cellspacing="0" border="0" width="100%" style="width:100%;background:#F8FAFC;border:1px solid #E2E8F0;border-radius:12px;margin:0 0 16px;border-collapse:separate;">';
        if ($head) {
            $h .= '<tr>' . implode('', array_map(fn ($c, $i) => '<td style="padding:10px 16px 6px;font-size:12px;font-weight:700;letter-spacing:.04em;text-transform:uppercase;color:#64748B;' . ($i ? 'text-align:right;' : '') . '">' . self::e($c) . '</td>', $head, array_keys($head))) . '</tr>';
        }
        foreach ($rows as $r) {
            $h .= '<tr>' . implode('', array_map(fn ($c, $i) => '<td style="padding:8px 16px;font-size:14.5px;line-height:1.5;color:#1E293B;border-top:1px solid #E2E8F0;' . ($i ? 'text-align:right;white-space:nowrap;' : '') . '">' . $c . '</td>', $r, array_keys($r))) . '</tr>';
        }

        return $h . '</table>';
    }

    /**
     * @param  array<int, array{name:string, old:float, new:float}> $lines  the family's own children
     * @return array{subject:string, html:string}
     */
    public static function tuitionIncrease(int $agencyId, string $first, array $lines, string $effective, string $notes = ''): array
    {
        $team = self::team($agencyId);
        $when = BillingRules::day($effective);
        $rows = array_map(fn ($l) => [
            '<strong>' . self::e($l['name']) . '</strong>',
            self::e(BillingRules::money((float) $l['old'])),
            '<strong>' . self::e(BillingRules::money((float) $l['new'])) . '</strong>',
        ], $lines);
        $body = self::p('Hi ' . self::e($first ?: 'there') . ',')
            . self::p('We are writing to let you know that tuition at ' . self::e($team) . ' changes from <strong>' . self::e($when) . '</strong>. Here is what it means for your family:')
            . self::card($rows, ['Child', 'Now (monthly)', 'From ' . $when])
            . ($notes !== '' ? self::p(nl2br(self::e($notes)), 'font-size:14px;color:#334155;') : '')
            . self::p('Your invoices from that date will show the new amount. Nothing else changes, and there is nothing you need to do.')
            . self::button($agencyId, 'View your billing', 'https://app.kiddietrac.com/dashboard.html#billing')
            . self::p('If you have any questions, just reply to this email or message us in the app.', 'font-size:13px;color:#64748B;')
            . self::signoff($team);

        return [
            'subject' => 'Tuition change from ' . $when . ' — ' . $team,
            'html' => EmailTemplate::wrap($agencyId, $body, [
                'eyebrow' => '📈 TUITION CHANGE', 'title' => 'Tuition change from ' . $when,
                'subtitle' => 'From ' . $team,
                'preheader' => 'Your tuition changes from ' . $when . '. Here are the new amounts for your family.',
            ]),
        ];
    }
}
