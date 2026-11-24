<?php

namespace App\Support;

/**
 * ALTERNATE SALES-EMAIL DESIGNS (2026-10-30). Anthony: "each email looks the same - make them different and unique -
 * change up the design, add new images etc", then "make sure your emails are dark mode friendly ... the last email
 * should lay out the ROI a bit in detail". A Sales campaign with sales_campaigns.design set is drawn here instead of
 * the standard SalesCampaigns::render layout. Same rules as every sales email: the recipient's first name, the
 * sender's FIRST name only, tracked links (/c/l), "send me more information" (/c/i), the CASL line + one-click
 * unsubscribe (/c/u), the open pixel (/c/o), no prices, images from www.kiddietrac.com.
 * DARK MODE: every design carries its own dark palette (prefers-color-scheme, mirrored for Outlook.com's [data-ogsc]),
 * and the logo swaps to the light-on-dark wordmark.
 *   morning     - warm cream "photo story": hero photo, One-Tap in four steps, the film, coral button
 *   safety      - navy "spotlight": shield, Safe Arrival + Walk Watch cards with photos, highlight band, the film
 *   letter      - a quiet personal letter: one photo, short paragraphs, a signature, a P.S.
 *   letter_roi  - the letter with a worked "time back" example (clearly an estimate with its assumptions shown)
 */
final class CampaignDesigns
{
    public const DESIGNS = ['morning' => 'Morning photo story', 'safety' => 'Safety spotlight', 'letter' => 'Personal letter', 'letter_roi' => 'Personal letter + ROI'];
    private const API = 'https://api.kiddietrac.com/api/v1';
    private const SITE = 'https://www.kiddietrac.com';

    public static function has(?string $d): bool
    {
        return $d !== null && isset(self::DESIGNS[$d]);
    }

    private static function e($v): string
    {
        return htmlspecialchars((string) $v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /** Dark palette per design: [page bg, card bg, text, heading, soft text, accent, inner panel, panel border]. */
    private const DARK = [
        'morning' => ['#17110C', '#221A13', '#E9DDD0', '#FFF4E8', '#BBA894', '#FF8A65', '#2C2219', '#3A2D21'],
        'safety' => ['#0B1220', '#121B2E', '#CBD5E1', '#F1F5F9', '#9FB0CC', '#5FD3DB', '#18233A', '#26344F'],
        'letter' => ['#141311', '#1F1D1A', '#E7E2DA', '#F5F1EA', '#B5AEA3', '#5FD3DB', '#2A2723', '#3A3630'],
    ];

    private static function darkCss(string $design): string
    {
        $d = self::DARK[$design === 'letter_roi' ? 'letter' : $design] ?? self::DARK['letter'];
        [$bg, $card, $tx, $hd, $soft, $acc, $panel, $bd] = $d;
        $rules = function (string $pre) use ($bg, $card, $tx, $hd, $soft, $acc, $panel, $bd) {
            return $pre . '.bg{background:' . $bg . ' !important}'
                . $pre . '.sheet{background:' . $card . ' !important;border-color:' . $bd . ' !important;box-shadow:none !important}'
                . $pre . '.sheet p,' . $pre . '.sheet td,' . $pre . '.sheet div,' . $pre . '.sheet span{color:' . $tx . ' !important}'
                . $pre . '.sheet h1,' . $pre . '.sheet b,' . $pre . '.sheet .hd{color:' . $hd . ' !important}'
                . $pre . '.sheet .soft,' . $pre . '.sheet .soft *{color:' . $soft . ' !important}'
                . $pre . '.sheet .acc,' . $pre . '.sheet a.acc{color:' . $acc . ' !important}'
                . $pre . '.sheet .panel{background:' . $panel . ' !important;border-color:' . $bd . ' !important}'
                . $pre . '.sheet .line{border-color:' . $bd . ' !important}'
                . $pre . '.sheet .keep,' . $pre . '.sheet .keep *{color:#FFFFFF !important}'
                . $pre . '.sheet a.btn{color:#FFFFFF !important}' . $pre . '.sheet a.btn.dk{color:#06231F !important}'
                . $pre . '.foot,' . $pre . '.foot a{color:#8A97AB !important}'
                . $pre . '.lm{display:none !important}' . $pre . '.dm{display:block !important;max-height:none !important;overflow:visible !important}';
        };
        return '<style>.dm{display:none;max-height:0;overflow:hidden}@media (prefers-color-scheme:dark){' . $rules('') . '}' . $rules('[data-ogsc] ') . '</style>';
    }

    /** Light and dark wordmarks: only one shows at a time. NOTE the file names read backwards: kiddietrac-wordmark-dark.png
        has DARK (navy) text for LIGHT backgrounds; kiddietrac-wordmark.png has WHITE text for DARK backgrounds. */
    private static function logo(string $light, string $dark, int $w = 150, string $align = 'left'): string
    {
        $m = $align === 'center' ? 'margin:0 auto;' : '';
        return '<img class="lm" src="' . self::SITE . '/email-icons/brand/' . $light . '" width="' . $w . '" alt="KiddieTrac" style="display:block;border:0;height:auto;' . $m . '">'
            . '<!--[if !mso]><!--><div class="dm" style="display:none;max-height:0;overflow:hidden;"><img src="' . self::SITE . '/email-icons/brand/' . $dark . '" width="' . $w . '" alt="KiddieTrac" style="display:block;border:0;height:auto;' . $m . '"></div><!--<![endif]-->';
    }

    public static function render(object $c, ?object $r, ?int $senderId = null): string
    {
        $tok = $r ? $r->token : null;
        $video = SalesCampaigns::VIDEOS[$c->video] ?? SalesCampaigns::VIDEOS['a-day-with-kiddietrac'];
        $targets = ['video' => self::SITE . $video[1], 'demo' => self::SITE . '/?book=1', 'plans' => self::SITE . '/pricing', 'site' => self::SITE . '/'];
        $L = [
            'link' => fn (string $k) => $tok ? self::API . '/c/l/' . $tok . '/' . $k : ($targets[$k] ?? self::SITE),
            'interested' => $tok ? self::API . '/c/i/' . $tok : self::SITE . '/contact',
            'unsub' => $tok ? self::API . '/c/u/' . $tok : self::SITE . '/contact',
            'pixel' => $tok ? '<img src="' . self::API . '/c/o/' . $tok . '.gif" width="1" height="1" alt="" style="display:block;width:1px;height:1px;border:0;">' : '',
        ];
        $first = trim((string) strtok(trim((string) ($r ? $r->name : 'Alex Sample')), ' ')) ?: 'there';
        $consent = SalesCampaigns::CONSENT[$r->consent_basis ?? $c->consent_basis] ?? SalesCampaigns::CONSENT['implied_published'];
        $sender = SalesCampaigns::senderName($senderId ?: ($c->sent_by ?: $c->created_by));
        $senderFirst = trim((string) strtok($sender, ' '));
        $intro = array_values(array_filter(array_map('trim', preg_split("/\n\s*\n/", (string) $c->intro))));
        // A rich intro (2026-11-04) is one block, drawn by paras() in each design's own paragraph style.
        self::$sign = CampaignRich::canvas($c->canvas ?? null)['signature'];   // the sign-off is optional (2026-11-05)
        self::$rich = CampaignRich::isRich((string) $c->intro) ? ['html' => (string) $c->intro, 'tok' => $tok, 'first' => $first] : null;
        if (self::$rich) $intro = ['__rich__'];
        $pre = trim((string) $c->preheader);
        $design = (string) $c->design;
        $body = match ($design) {
            'morning' => self::morning($c, $first, $intro, $sender, $L),
            'safety' => self::safety($c, $first, $intro, $sender, $L),
            'letter_roi' => self::letter($c, $first, $intro, $senderFirst, $sender, $L, true),
            default => self::letter($c, $first, $intro, $senderFirst, $sender, $L, false),
        };
        $footCol = $design === 'safety' ? '#9FB0CC' : '#7A8699';
        $foot = '<table role="presentation" class="w" width="600" cellpadding="0" cellspacing="0" style="width:600px;max-width:100%;"><tr><td class="foot" align="center" style="padding:18px 24px 26px;font:400 12px/1.6 Arial,Helvetica,sans-serif;color:' . $footCol . ';">'
            . self::e($consent[1]) . '<br>' . self::e(SalesCampaigns::ADDRESS) . '<br><a class="foot" href="' . self::e($L['unsub']) . '" style="color:' . $footCol . ';text-decoration:underline;">Unsubscribe</a> from KiddieTrac sales emails · '
            . '<a class="foot" href="' . self::e(($L['link'])('site')) . '" style="color:' . $footCol . ';text-decoration:underline;">kiddietrac.com</a>'
            . '<div style="margin:10px 0 0;">' . SocialLinks::block(SocialLinks::row($footCol, 'font-family:Arial,Helvetica,sans-serif;', 'Follow us', $footCol)) . '</div></td></tr></table>';
        $bg = ['morning' => '#FFF6EC', 'safety' => '#0B1F4D', 'letter' => '#F4F1EC', 'letter_roi' => '#F4F1EC'][$design] ?? '#F4F1EC';
        return '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
            . '<meta name="color-scheme" content="light dark"><meta name="supported-color-schemes" content="light dark">'
            . '<title>' . self::e($c->subject) . '</title><style>@media(max-width:620px){.w{width:100%!important}.px{padding-left:20px!important;padding-right:20px!important}.col{display:block!important;width:100%!important}.h1{font-size:28px!important;line-height:1.15!important}.stack img{width:100%!important;height:auto!important}}</style>'
            . self::darkCss($design) . '</head>'
            . '<body class="bg" style="margin:0;padding:0;background:' . $bg . ';-webkit-text-size-adjust:100%;">'
            . ($pre !== '' ? '<div style="display:none;max-height:0;overflow:hidden;opacity:0;color:transparent;">' . self::e($pre) . str_repeat('&#8199;&#847; ', 30) . '</div>' : '')
            . '<table role="presentation" class="bg" width="100%" cellpadding="0" cellspacing="0" bgcolor="' . $bg . '" style="background:' . $bg . ';"><tr><td class="bg" align="center" style="padding:24px 10px;">'
            . $body . $foot . $L['pixel']
            . '</td></tr></table></body></html>';
    }

    private static function btn(string $href, string $label, string $bg, string $fg = '#FFFFFF'): string
    {
        return '<a class="btn' . ($fg !== '#FFFFFF' ? ' dk' : '') . '" href="' . self::e($href) . '" style="display:inline-block;padding:15px 28px;border-radius:999px;background:' . $bg . ';color:' . $fg . ';font:800 16px/1 Arial,Helvetica,sans-serif;text-decoration:none;">' . $label . '</a>';
    }

    private static ?array $rich = null;
    /** Draw the "Talk soon / Warmly / All the best, <name>" sign-off (canvas.signature). */
    private static bool $sign = true;

    private static function paras(array $intro, string $style): string
    {
        if (self::$rich && $intro === ['__rich__']) {
            preg_match('/color:([^;]+);/', $style, $m);
            return CampaignRich::html(self::$rich['html'], ['p' => $style, 'li' => preg_replace('/margin:[^;]*;/', 'margin:0 0 6px;', $style),
                'h2' => 'margin:6px 0 10px;font-weight:800;font-size:20px;line-height:1.3;color:' . ($m[1] ?? '#1F3A5F') . ';',
                'h3' => 'margin:4px 0 8px;font-weight:800;font-size:17px;line-height:1.35;color:' . ($m[1] ?? '#1F3A5F') . ';',
                'a' => 'color:#0E7C90;font-weight:700;'], self::$rich['tok'], self::$rich['first']);
        }
        return implode('', array_map(fn ($p) => '<p style="' . $style . '">' . nl2br(self::e($p)) . '</p>', $intro));
    }

    /** Warm cream photo story: the morning, One-Tap in four steps. */
    private static function morning(object $c, string $first, array $intro, string $sender, array $L): string
    {
        $img = self::SITE . '/email-icons/onetap/';
        $coral = '#E8613C'; $ink = '#2B2118'; $soft = '#6B5B4D';
        $p = 'margin:0 0 14px;font:400 16px/1.65 Georgia,\'Times New Roman\',serif;color:' . $ink . ';';
        $steps = [['icon-step1-t.png', 'Parent arrives', 'Scans the code at your door'], ['icon-step2-t.png', 'One tap', 'Checked in, ratios update'],
            ['icon-step3-t.png', 'The day, logged', 'Meals, naps, moments'], ['icon-step4-t.png', 'Pick-up', 'Report already sent']];
        $cells = '';
        foreach ($steps as $i => $s) {
            $cells .= '<td class="col" width="25%" align="center" valign="top" style="padding:8px 6px;">'
                . '<img src="' . $img . $s[0] . '" width="54" height="54" alt="" style="display:block;margin:0 auto 8px;border:0;">'
                . '<div class="hd" style="font:800 13px/1.3 Arial,Helvetica,sans-serif;color:' . $ink . ';">' . ($i + 1) . '. ' . self::e($s[1]) . '</div>'
                . '<div class="soft" style="font:400 12px/1.4 Arial,Helvetica,sans-serif;color:' . $soft . ';margin-top:3px;">' . self::e($s[2]) . '</div></td>';
        }
        return '<table role="presentation" class="w sheet" width="600" cellpadding="0" cellspacing="0" style="width:600px;max-width:100%;background:#FFFFFF;border-radius:22px;overflow:hidden;box-shadow:0 10px 30px -18px rgba(43,33,24,.35);">'
            . '<tr><td style="padding:22px 32px 0;" class="px">' . self::logo('kiddietrac-wordmark-dark.png', 'kiddietrac-wordmark.png') . '</td></tr>'
            . '<tr><td class="stack" style="padding:18px 0 0;"><img src="' . $img . 'hero-v2.jpg" width="600" alt="A parent checking in at the door with One-Tap" style="display:block;width:100%;max-width:600px;height:auto;border:0;"></td></tr>'
            . '<tr><td class="px" style="padding:26px 36px 6px;"><div class="acc" style="font:800 12px/1 Arial,Helvetica,sans-serif;letter-spacing:2px;text-transform:uppercase;color:' . $coral . ';margin-bottom:10px;">A quick follow-up</div>'
            . '<h1 class="h1" style="margin:0 0 16px;font:700 32px/1.15 Georgia,\'Times New Roman\',serif;color:' . $ink . ';">' . self::e($c->headline) . '</h1>'
            . '<p style="' . $p . '">Hi ' . self::e($first) . ',</p>' . self::paras($intro, $p) . '</td></tr>'
            . '<tr><td class="px" style="padding:6px 26px 4px;"><table role="presentation" class="panel" width="100%" cellpadding="0" cellspacing="0" style="background:#FFF6EC;border-radius:16px;border:1px solid #FFF6EC;"><tr>' . $cells . '</tr></table></td></tr>'
            . '<tr><td class="px stack" style="padding:22px 36px 4px;"><a href="' . self::e(($L['link'])('video')) . '" style="text-decoration:none;"><img src="' . self::SITE . '/email-icons/run-up/film-one-tap.jpg" width="528" alt="Watch One-Tap in action" style="display:block;width:100%;max-width:528px;height:auto;border:0;border-radius:16px;"></a>'
            . '<div class="soft" style="font:400 13px/1.5 Arial,Helvetica,sans-serif;color:' . $soft . ';margin-top:8px;text-align:center;">▶ 1 minute: One-Tap at a real front door</div></td></tr>'
            . '<tr><td align="center" style="padding:22px 36px 6px;">' . self::btn(($L['link'])('demo'), 'Book a 20-minute look', $coral) . '</td></tr>'
            . '<tr><td align="center" style="padding:6px 36px 4px;"><a class="acc" href="' . self::e($L['interested']) . '" style="font:700 14px/1.5 Arial,Helvetica,sans-serif;color:' . $coral . ';">Not now? Send me more information</a></td></tr>'
            . (self::$sign ? '<tr><td class="px" style="padding:22px 36px 30px;"><p style="' . $p . 'margin:0;">Warmly,<br><b>' . self::e($sender) . '</b><br><span class="soft" style="font:400 13.5px/1.6 Arial,Helvetica,sans-serif;color:' . $soft . ';">' . self::e(SalesCampaigns::FROM) . ' · ' . self::e(SalesCampaigns::PHONE) . '</span></p></td></tr>' : '<tr><td style="padding:0 0 26px;"></td></tr>')
            . '</table>';
    }

    /** Navy spotlight: Safe Arrival + Walk Watch. */
    private static function safety(object $c, string $first, array $intro, string $sender, array $L): string
    {
        $navy = '#0B1F4D'; $teal = '#19B5A5'; $ink = '#14213D'; $soft = '#5B6B84';
        $p = 'margin:0 0 14px;font:400 16px/1.65 Arial,Helvetica,sans-serif;color:' . $ink . ';';
        $card = function (string $photo, string $mark, string $title, string $text) use ($ink, $soft) {
            return '<td class="col" width="50%" valign="top" style="padding:8px;"><table role="presentation" class="panel" width="100%" cellpadding="0" cellspacing="0" style="background:#FFFFFF;border:1px solid #E3E9F3;border-radius:16px;overflow:hidden;">'
                . '<tr><td class="stack"><img src="' . self::SITE . '/images/life/' . $photo . '" width="260" alt="" style="display:block;width:100%;height:auto;border:0;"></td></tr>'
                . '<tr><td style="padding:14px 16px 16px;"><img src="' . self::SITE . '/email-icons/run-up/' . $mark . '" width="34" height="34" alt="" style="display:block;border:0;margin-bottom:8px;">'
                . '<div class="hd" style="font:800 16px/1.3 Arial,Helvetica,sans-serif;color:' . $ink . ';">' . $title . '</div>'
                . '<div class="soft" style="font:400 13.5px/1.5 Arial,Helvetica,sans-serif;color:' . $soft . ';margin-top:4px;">' . $text . '</div></td></tr></table></td>';
        };
        // The navy band above the card is navy in both modes; its logo is always the light-on-dark one.
        return '<table role="presentation" class="w" width="600" cellpadding="0" cellspacing="0" style="width:600px;max-width:100%;">'
            . '<tr><td class="px" style="padding:6px 32px 26px;" align="center"><img src="' . self::SITE . '/email-icons/brand/kiddietrac-wordmark.png" width="150" alt="KiddieTrac" style="display:block;border:0;height:auto;margin:0 auto 22px;">'
            . '<img src="' . self::SITE . '/videos/safe-arrival/email-shield.png" width="88" alt="" style="display:block;border:0;margin:0 auto 14px;height:auto;border-radius:22px;">'
            . '<h1 class="h1" style="margin:0;font:800 34px/1.12 Arial,Helvetica,sans-serif;color:#FFFFFF;letter-spacing:-.5px;">' . self::e($c->headline) . '</h1>'
            . '<div style="font:400 16px/1.5 Arial,Helvetica,sans-serif;color:#B9C6DE;margin-top:10px;">Safe Arrival &amp; Walk Watch, built into KiddieTrac</div></td></tr>'
            . '<tr><td><table role="presentation" class="sheet" width="100%" cellpadding="0" cellspacing="0" style="background:#FFFFFF;border-radius:22px;overflow:hidden;">'
            . '<tr><td class="px" style="padding:30px 36px 8px;"><p style="' . $p . '">Hi ' . self::e($first) . ',</p>' . self::paras($intro, $p) . '</td></tr>'
            . '<tr><td class="px" style="padding:4px 28px 6px;"><table role="presentation" width="100%" cellpadding="0" cellspacing="0"><tr>'
            . $card('educator-child-800.jpg', 'mark-safe-arrival.png', 'Safe Arrival', 'An expected child isn’t here? Families are asked right away, and you see every answer in one place.')
            . $card('walking-together-800.jpg', 'mark-walk-watch.png', 'Walk Watch', 'Live GPS for walks and outings: the route, the headcount, and a record when you are back.')
            . '</tr></table></td></tr>'
            . '<tr><td class="px" style="padding:14px 36px 4px;"><table role="presentation" class="keep" width="100%" cellpadding="0" cellspacing="0" style="background:' . $navy . ';border-radius:14px;"><tr>'
            . '<td style="padding:16px 18px;font:800 15px/1.6 Arial,Helvetica,sans-serif;color:#FFFFFF;">✓ Every check-in timed<br>✓ Every walk mapped<br>✓ Inspection-ready records</td></tr></table></td></tr>'
            . '<tr><td class="px stack" style="padding:22px 36px 4px;"><a href="' . self::e(($L['link'])('video')) . '" style="text-decoration:none;"><img src="' . self::SITE . '/email-icons/run-up/film-safe-arrival.jpg" width="528" alt="Watch Safe Arrival" style="display:block;width:100%;max-width:528px;height:auto;border:0;border-radius:14px;"></a></td></tr>'
            . '<tr><td align="center" style="padding:22px 36px 6px;">' . self::btn(($L['link'])('demo'), 'See it with your centre in mind', $teal, '#06231F') . '</td></tr>'
            . '<tr><td align="center" style="padding:6px 36px 4px;"><a class="acc" href="' . self::e($L['interested']) . '" style="font:700 14px/1.5 Arial,Helvetica,sans-serif;color:#0E7C90;">Send me more information</a></td></tr>'
            . (self::$sign ? '<tr><td class="px" style="padding:22px 36px 30px;"><p style="' . $p . 'margin:0;">Talk soon,<br><b>' . self::e($sender) . '</b><br><span class="soft" style="font-size:13.5px;color:' . $soft . ';">' . self::e(SalesCampaigns::FROM) . ' · ' . self::e(SalesCampaigns::PHONE) . '</span></p></td></tr>' : '<tr><td style="padding:0 0 26px;"></td></tr>')
            . '</table></td></tr></table>';
    }

    /**
     * The "time back" example for letter_roi. Deliberately an illustration with its assumptions in plain sight (an
     * example 60-child centre, staff time valued at $25/hour), never a promise or a quoted customer result.
     */
    private static function roi(string $ink, string $soft, array $L): string
    {
        $rows = [
            ['Billing, subsidies & chasing payments', 6, 1.5],
            ['Daily reports & parent messages', 5, 1],
            ['Attendance, sign-in sheets & ratios', 3, 0.5],
            ['Forms, incident reports & filing', 2, 0.5],
            ['Staff scheduling & timesheets', 2, 0.5],
        ];
        $saved = 0; $tr = '';
        $f = fn ($n) => rtrim(rtrim(number_format($n, 1), '0'), '.');
        foreach ($rows as $i => [$what, $before, $after]) {
            $saved += $before - $after;
            $tr .= '<tr><td class="line" style="padding:9px 10px;border-top:1px solid #EFEAE2;font:400 14px/1.4 Arial,Helvetica,sans-serif;color:' . $ink . ';">' . self::e($what) . '</td>'
                . '<td class="line soft" align="center" style="padding:9px 6px;border-top:1px solid #EFEAE2;font:400 14px/1.4 Arial,Helvetica,sans-serif;color:' . $soft . ';white-space:nowrap;">' . $f($before) . ' h</td>'
                . '<td class="line" align="center" style="padding:9px 6px;border-top:1px solid #EFEAE2;font:700 14px/1.4 Arial,Helvetica,sans-serif;color:' . $ink . ';white-space:nowrap;">' . $f($after) . ' h</td></tr>';
        }
        $month = $saved * 52 / 12;
        $value = $month * 25;
        $hd = 'padding:8px 10px;font:800 11px/1.3 Arial,Helvetica,sans-serif;letter-spacing:.8px;text-transform:uppercase;';
        return '<div class="hd" style="font:700 19px/1.3 Georgia,\'Times New Roman\',serif;color:' . $ink . ';margin:8px 0 6px;">What the time back can look like</div>'
            . '<p style="margin:0 0 12px;font:400 15px/1.6 Georgia,\'Times New Roman\',serif;color:' . $ink . ';">Here is a worked example for a typical 60-child centre: the admin that usually lands on a director or a lead educator each week, before and after it runs in KiddieTrac.</p>'
            . '<table role="presentation" class="panel" width="100%" cellpadding="0" cellspacing="0" style="background:#FAF8F4;border:1px solid #EFEAE2;border-radius:12px;border-collapse:separate;">'
            . '<tr><td class="soft" style="' . $hd . 'color:' . $soft . ';">Each week</td><td class="soft" align="center" style="' . $hd . 'color:' . $soft . ';">Today</td><td class="acc" align="center" style="' . $hd . 'color:#0E7C90;">With KiddieTrac</td></tr>'
            . $tr . '</table>'
            . '<table role="presentation" class="keep" width="100%" cellpadding="0" cellspacing="0" style="margin:12px 0 8px;background:#0E5E68;border-radius:12px;"><tr>'
            . '<td class="col" width="33%" align="center" style="padding:14px 8px;"><div style="font:800 24px/1.1 Arial,Helvetica,sans-serif;color:#FFFFFF;">~' . $f($saved) . ' h</div><div style="font:400 12px/1.4 Arial,Helvetica,sans-serif;color:#CDEBEE;">back every week</div></td>'
            . '<td class="col" width="33%" align="center" style="padding:14px 8px;"><div style="font:800 24px/1.1 Arial,Helvetica,sans-serif;color:#FFFFFF;">~' . round($month) . ' h</div><div style="font:400 12px/1.4 Arial,Helvetica,sans-serif;color:#CDEBEE;">back every month</div></td>'
            . '<td class="col" width="33%" align="center" style="padding:14px 8px;"><div style="font:800 24px/1.1 Arial,Helvetica,sans-serif;color:#FFFFFF;">~$' . number_format(round($value / 50) * 50) . '</div><div style="font:400 12px/1.4 Arial,Helvetica,sans-serif;color:#CDEBEE;">of staff time a month</div></td></tr></table>'
            . '<p class="soft" style="margin:0 0 6px;font:400 12.5px/1.55 Arial,Helvetica,sans-serif;color:' . $soft . ';">An illustration, not a promise: it assumes 60 children, the weekly hours shown, and staff time valued at $25 an hour. Your numbers will be different, so I’m happy to work through them with you.</p>'
            . '<p style="margin:10px 0 18px;font:400 15px/1.6 Georgia,\'Times New Roman\',serif;color:' . $ink . ';">Beyond the hours: autopay and automatic reminders mean fewer late payments to chase, families get the day’s photos and updates without anyone typing them twice, and your records are ready when the inspector visits.</p>';
    }

    /** A quiet personal letter (optionally with the ROI example). */
    private static function letter(object $c, string $first, array $intro, string $senderFirst, string $sender, array $L, bool $withRoi): string
    {
        $ink = '#2A2A2A'; $soft = '#6F6A63';
        $p = 'margin:0 0 16px;font:400 16.5px/1.7 Georgia,\'Times New Roman\',serif;color:' . $ink . ';';
        // The intro's last paragraph (the thank-you) goes after the example, so the letter still ends warmly.
        $lead = $intro; $close = [];
        if ($withRoi && count($intro) > 1) { $close = [array_pop($lead)]; }
        return '<table role="presentation" class="w sheet" width="560" cellpadding="0" cellspacing="0" style="width:560px;max-width:100%;background:#FFFFFF;border-radius:6px;border:1px solid #E8E2D9;">'
            . '<tr><td class="px" style="padding:36px 44px 8px;">'
            . '<p style="' . $p . '">Hi ' . self::e($first) . ',</p>' . self::paras($lead, $p)
            . ($withRoi ? self::roi($ink, $soft, $L) : '')
            . self::paras($close, $p)
            . '<table role="presentation" cellpadding="0" cellspacing="0" style="margin:6px 0 20px;"><tr>'
            . '<td valign="top" style="padding-right:16px;"><img src="' . self::SITE . '/images/life/reading-together-800.jpg" width="150" alt="" style="display:block;width:150px;height:auto;border:0;border-radius:10px;"></td>'
            . '<td class="soft" valign="middle" style="font:italic 400 15px/1.6 Georgia,\'Times New Roman\',serif;color:' . $soft . ';">More time for moments like this,<br>and less time on paperwork.</td></tr></table>'
            . '<p style="' . $p . '">If a short conversation would help, <a class="acc" href="' . self::e(($L['link'])('demo')) . '" style="color:#0E7C90;font-weight:700;">pick any time that suits you</a>, or simply reply to this email.</p>'
            . (self::$sign ? '<p style="' . $p . 'margin-bottom:4px;">All the best,</p>'
            . '<p class="acc" style="margin:0 0 2px;font:italic 400 30px/1.2 \'Brush Script MT\',\'Segoe Script\',cursive;color:#1F3A5F;">' . self::e($senderFirst) . '</p>'
            . '<p class="soft" style="margin:0 0 20px;font:400 13.5px/1.6 Arial,Helvetica,sans-serif;color:' . $soft . ';">' . self::e($sender) . ' · ' . self::e(SalesCampaigns::FROM) . ' · ' . self::e(SalesCampaigns::PHONE) . '</p>' : '')
            . '<p class="soft line" style="margin:0 0 30px;padding-top:14px;border-top:1px solid #EFEAE2;font:400 14px/1.6 Georgia,\'Times New Roman\',serif;color:' . $soft . ';"><b style="color:' . $ink . ';">P.S.</b> If you ever want a one-minute look, here is <a class="acc" href="' . self::e(($L['link'])('video')) . '" style="color:#0E7C90;">a day with KiddieTrac</a>. And if it helps to have something in writing, <a class="acc" href="' . self::e($L['interested']) . '" style="color:#0E7C90;">I can send more information</a>.</p>'
            . '</td></tr></table>';
    }
}
