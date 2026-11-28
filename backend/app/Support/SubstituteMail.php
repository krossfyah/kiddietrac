<?php

namespace App\Support;

use App\Services\EmailTemplate;

/**
 * "Cover needed" email to a substitute (2026-11-28, App\Support\SubstituteCover::announce). Pure builder: no sends,
 * no writes. House frame, the substitute by first name, the shift in a card, a button straight to Open cover
 * requests where they can take it.
 */
final class SubstituteMail
{
    private static function e($s): string
    {
        return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
    }

    /** @return array{subject:string, html:string} */
    public static function request(int $agencyId, string $first, string $centre, string $when, string $reason, int $requestId): array
    {
        $p = fn (string $t, string $x = '') => '<p style="margin:0 0 14px;font-size:15px;line-height:1.65;color:#1E293B;' . $x . '">' . $t . '</p>';
        $c = self::e(EmailTemplate::brandColours($agencyId)['primary'] ?? '#1F6080');
        $href = 'https://app.kiddietrac.com/dashboard.html#cover-requests?open=' . $requestId;
        $rows = '';
        foreach ([['Centre', $centre], ['When', $when], ['Why', $reason]] as [$k, $v]) {
            if ($v === '') continue;
            $rows .= '<tr><td style="padding:4px 14px 4px 0;font-size:13px;color:#64748B;white-space:nowrap;">' . self::e($k) . '</td><td style="padding:4px 0;font-size:14px;color:#0F172A;font-weight:600;">' . self::e($v) . '</td></tr>';
        }
        $team = FamilyFacingName::agency($agencyId) ?: 'KiddieTrac';
        $body = $p('Hi ' . self::e($first ?: 'there') . ',')
            . $p('A centre needs a substitute. If you can work this shift, take it in the app. The first person to take it gets it.')
            . '<table role="presentation" class="kt-panel" cellpadding="0" cellspacing="0" border="0" width="100%" style="width:100%;background:#F8FAFC;border:1px solid #E2E8F0;border-radius:12px;margin:0 0 16px;"><tr><td style="padding:12px 16px;"><table role="presentation" cellpadding="0" cellspacing="0" border="0">' . $rows . '</table></td></tr></table>'
            . '<table role="presentation" cellpadding="0" cellspacing="0" border="0" style="margin:6px 0 16px;"><tr><td bgcolor="' . $c . '" style="background:' . $c . ';border-radius:10px;">'
            . '<a class="kt-btn" href="' . self::e($href) . '" style="display:inline-block;padding:13px 24px;font-size:15px;font-weight:800;color:#FFFFFF;text-decoration:none;border-radius:10px;">See the shift</a></td></tr></table>'
            . $p('You get these because you are on the substitute list. Ask your director to change how you are contacted.', 'font-size:13px;color:#64748B;')
            . $p('Thank you,<br><strong>The ' . self::e($team) . ' team</strong>', 'margin:4px 0 0;');

        return [
            'subject' => 'Cover needed: ' . $when . ($centre ? ' at ' . $centre : ''),
            'html' => EmailTemplate::wrap($agencyId, $body, [
                'eyebrow' => '🙋 COVER NEEDED', 'title' => 'Can you cover a shift?', 'subtitle' => $centre,
                'preheader' => 'Cover needed ' . $when . ($centre ? ' at ' . $centre : '') . '.',
            ]),
        ];
    }
}
