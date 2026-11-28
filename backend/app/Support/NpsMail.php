<?php

namespace App\Support;

use App\Services\AgencyMailer;
use App\Services\EmailTemplate;

/**
 * The NPS survey email (2026-11-28): "How likely are you to recommend {agency}?" with a 0-10 row of buttons, each
 * opening nps.html with that score picked (the page asks for a tap to send, so a mail scanner opening every link
 * records nothing). House frame, greeting by first name, sign-off "The {agency} team". build() is pure.
 */
final class NpsMail
{
    public static function build(int $agencyId, array $d): array
    {
        $e = fn ($s) => e((string) $s);
        $first = trim((string) ($d['first_name'] ?? ''));
        $staff = ($d['audience'] ?? 'family') === 'staff';
        $team = FamilyFacingName::agency($agencyId);
        $url = (string) ($d['url'] ?? '');
        $q = $staff ? 'How likely are you to recommend ' . $team . ' as a place to work?' : 'How likely are you to recommend ' . $team . ' to a friend or family member?';

        $cells = '';
        for ($i = 0; $i <= 10; $i++) {
            $bg = $i <= 6 ? '#FEE2E2' : ($i <= 8 ? '#FEF3C7' : '#DCFCE7');
            $fg = $i <= 6 ? '#991B1B' : ($i <= 8 ? '#92400E' : '#166534');
            $cells .= '<td style="padding:2px;"><a class="kt-nps-n" href="' . $e($url . '&s=' . $i) . '" style="display:block;width:34px;line-height:34px;text-align:center;border-radius:8px;'
                . 'background:' . $bg . ';color:' . $fg . ';font-weight:800;font-size:15px;text-decoration:none;">' . $i . '</a></td>';
        }
        $body = '<p style="margin:0 0 14px;font-size:15px;line-height:1.65;">' . ($first !== '' ? 'Hi ' . $e($first) . ',' : 'Hello,') . '</p>'
            . '<p style="margin:0 0 16px;font-size:15px;line-height:1.65;">We would love to know how we are doing. It is one question and takes a few seconds.</p>'
            . '<div class="kt-nps-box" style="margin:0 0 18px;padding:14px 12px;border:1px solid #E2E8F0;border-radius:12px;background:#F8FAFC;">'
            . '<div class="kt-nps-q" style="font-size:15px;font-weight:700;color:#0F172A;margin:0 0 10px;line-height:1.45;">' . $e($q) . '</div>'
            . '<table role="presentation" cellpadding="0" cellspacing="0" style="border-collapse:collapse;"><tr>' . $cells . '</tr></table>'
            . '<table role="presentation" cellpadding="0" cellspacing="0" style="width:100%;margin-top:6px;"><tr>'
            . '<td class="kt-nps-hint" style="font-size:12px;color:#64748B;">0 = not at all likely</td><td class="kt-nps-hint" style="font-size:12px;color:#64748B;text-align:right;">10 = extremely likely</td></tr></table></div>'
            . '<p style="margin:0 0 14px;font-size:15px;line-height:1.65;">Tap a number, add a comment if you like, and press <strong>Send</strong>. No sign-in is needed, and you can change your answer for 30 days.</p>'
            . '<p style="margin:0 0 14px;font-size:14px;line-height:1.65;color:#475569;">' . ($staff ? 'Your answer helps us make this a better place to work.' : 'Your answer goes to the team and helps us make care better for every family.') . '</p>'
            . '<p style="margin:18px 0 0;font-size:15px;line-height:1.65;">Thank you,<br><strong>The ' . $e($team) . ' team</strong></p>';

        $title = 'How are we doing?';
        $html = EmailTemplate::wrap($agencyId, $body, [
            'eyebrow_icon' => '💬', 'eyebrow' => 'QUICK SURVEY', 'title' => $title,
            'subtitle' => 'One question · a few seconds',
            'preheader' => 'How likely are you to recommend ' . $team . '? Tap a number from 0 to 10.',
        ]);
        $dark = '.kt-nps-box{background:#1E293B !important;border-color:#334155 !important}.kt-nps-q{color:#F1F5F9 !important}.kt-nps-hint{color:#94A3B8 !important}';
        $style = '<style>@media (prefers-color-scheme:dark){' . $dark . '}' . preg_replace('/(^|\})\s*\./', '$1[data-ogsc] .', $dark) . '</style>';
        $html = stripos($html, '</head>') !== false ? str_ireplace('</head>', $style . '</head>', $html) : $style . $html;

        return ['subject' => $title . ' A one-question survey from ' . $team, 'html' => $html];
    }

    /** One person, through the agency's mailer (X-KT-Agency-Id: the normal gates apply). The recipient is required. */
    public static function send(int $agencyId, string $to, array $d): void
    {
        if ($to === '') throw new \InvalidArgumentException('NpsMail::send needs a recipient.');
        $m = self::build($agencyId, $d);
        $name = trim((string) ($d['first_name'] ?? ''));
        AgencyMailer::forAgency($agencyId)->html($m['html'], function ($msg) use ($to, $name, $m) {
            $msg->to($to, $name !== '' ? $name : null);
            $msg->from('noreply@kiddietrac.com', 'KiddieTrac')->subject($m['subject']);
        });
    }
}
